<?php

namespace App\Http\Controllers\Catalog;

use App\Http\Controllers\Controller;
use App\Models\ProductVideo;
use App\Services\Media\ProductVideoFile;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The product's one video: put it there, take it off.
 *
 * The wire matches the picture manager's. The file is uploaded on its own,
 * before the product is saved, and the form carries the path; a product that
 * does not exist yet stages its video under a token, and the save settles it
 * into the product's own folder.
 */
class ProductVideoController extends Controller
{
    /** Accept the file, say what the stores will make of it. */
    public function upload(Request $request): JsonResponse
    {
        $data = $request->validate([
            'video' => ['required', 'file', 'mimetypes:' . implode(',', ProductVideoFile::MIME), 'max:102400'],
            'product_id' => ['nullable', 'integer', 'min:0'],
            'token' => ['nullable', 'string', 'max:64'],
        ]);

        // A folder is only made for a product that exists.
        $productId = (int) ($data['product_id'] ?? 0);
        if ($productId > 0) {
            abort_unless(\Illuminate\Support\Facades\DB::table((string) config('catalog.prefix') . 'product')->where('product_id', $productId)->exists(), 404);
        }

        $stored = ProductVideoFile::put($request->file('video'), $productId, ProductVideoFile::stagingKey((string) ($data['token'] ?? '')));

        return response()->json([
            'ok' => true,
            'video' => $stored + [
                'url' => \App\Services\Media\ImageCache::publicUrl($stored['path']),
                'size' => ProductVideoFile::megabytes($stored['bytes']),
                'length' => $stored['duration_ms'] !== null ? ProductVideoFile::seconds($stored['duration_ms'] / 1000) : null,
            ],
        ]);
    }

    /**
     * Take the video off the product, file and all.
     *
     * The file is found through the row that claims it, never through the
     * path alone: a path is what the page sends, and one product's remove
     * must not be able to delete another product's video and leave its row
     * pointing at a file that is gone. The one path without a row is a
     * video staged in this session, which its own uploader may drop.
     */
    public function destroy(Request $request): JsonResponse
    {
        $data = $request->validate([
            'path' => ['required', 'string', 'max:512'],
            'product_id' => ['nullable', 'integer', 'min:0'],
        ]);

        $path = (string) $data['path'];
        $row = ProductVideo::query()->where('path', $path)->first();

        if ($row) {
            $row->delete();
            ProductVideoFile::forget($path);

            return response()->json(['ok' => true]);
        }

        if (str_starts_with($path, ProductVideoFile::stagingPrefix())) {
            ProductVideoFile::forget($path);

            return response()->json(['ok' => true]);
        }

        // Nothing here claims that file, so nothing here removes it.
        return response()->json(['ok' => true, 'kept' => true]);
    }
}
