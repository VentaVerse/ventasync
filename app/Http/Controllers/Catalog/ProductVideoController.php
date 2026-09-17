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

        $stored = ProductVideoFile::put($request->file('video'), (int) ($data['product_id'] ?? 0), (string) ($data['token'] ?? ''));

        return response()->json([
            'ok' => true,
            'video' => $stored + [
                'url' => \App\Services\Media\ImageCache::publicUrl($stored['path']),
                'size' => ProductVideoFile::megabytes($stored['bytes']),
                'length' => $stored['duration_ms'] !== null ? ProductVideoFile::seconds($stored['duration_ms'] / 1000) : null,
                'refusals' => ProductVideoFile::refusals($stored['bytes'], $stored['duration_ms']),
            ],
        ]);
    }

    /** Take the video off the product, file and all. */
    public function destroy(Request $request): JsonResponse
    {
        $data = $request->validate([
            'path' => ['required', 'string', 'max:512'],
            'product_id' => ['nullable', 'integer', 'min:0'],
        ]);

        $productId = (int) ($data['product_id'] ?? 0);
        $row = $productId > 0 ? ProductVideo::query()->where('product_id', $productId)->first() : null;
        if ($row && $row->path === $data['path']) {
            $row->delete();
        }
        ProductVideoFile::forget($data['path']);

        return response()->json(['ok' => true]);
    }
}
