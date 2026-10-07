<?php

namespace App\Http\Controllers\Catalog;

use App\Http\Controllers\Controller;
use App\Models\ProductVideo;
use App\Services\Media\ProductVideoFile;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ProductVideoController extends Controller
{
    public function upload(Request $request): JsonResponse
    {
        $data = $request->validate([
            'video' => ['required', 'file', 'mimetypes:' . implode(',', ProductVideoFile::MIME), 'max:102400'],
            'product_id' => ['nullable', 'integer', 'min:0'],
            'token' => ['nullable', 'string', 'max:64'],
        ]);

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

    // Find the file through the row that claims it, so one product cannot delete another's video.
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

        return response()->json(['ok' => true, 'kept' => true]);
    }
}
