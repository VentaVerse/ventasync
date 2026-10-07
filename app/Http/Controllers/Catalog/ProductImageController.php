<?php

namespace App\Http\Controllers\Catalog;

use App\Http\Controllers\Controller;
use App\Models\Catalog\Manufacturer;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class ProductImageController extends Controller
{
    private const SEARCH_LIMIT = 400;

    private function isSafeRemoteUrl(string $url): bool
    {
        return app(\App\Services\Media\CatalogImageImporter::class)->isSafeRemoteUrl($url);
    }

    private function fetchRemoteImageContents(string $url): string|false
    {
        return app(\App\Services\Media\CatalogImageImporter::class)->fetch($url);
    }

    private function manufacturerSlugFromId(int $manufacturerId): string
    {
        $manufacturerId = (int) $manufacturerId;
        if ($manufacturerId <= 0) {
            return '_no_manufacturer_';
        }

        $m = Manufacturer::query()
            ->where('manufacturer_id', $manufacturerId)
            ->first(['name']);

        if (!$m) {
            return '_no_manufacturer_';
        }

        $slug = Str::slug((string) $m->name);
        return $slug !== '' ? $slug : '_no_manufacturer_';
    }

    private function ensureAllowedImage(string $absPath): void
    {
        try {
            app(\App\Services\Media\CatalogImageImporter::class)->ensureAllowedImage($absPath);
        } catch (\RuntimeException $e) {
            abort(422, $e->getMessage());
        }
    }

    private function convertWebpToJpg(string $relPath): string
    {
        return app(\App\Services\Media\CatalogImageImporter::class)->convertWebpToJpg($relPath);
    }

    private function tmpCleanup(): void
    {
        $disk = Storage::disk('public');
        $base = 'tmp/product-images';
        if (!$disk->exists($base)) return;

        $now = time();
        foreach ($disk->directories($base) as $dir) {
            $files = $disk->allFiles($dir);
            $latest = 0;
            foreach ($files as $f) {
                $t = $disk->lastModified($f);
                if ($t > $latest) $latest = $t;
            }
            if ($latest === 0) {
                $disk->deleteDirectory($dir);
                continue;
            }
            if (($now - $latest) > (6 * 3600)) {
                $disk->deleteDirectory($dir);
            }
        }
    }
    private function manufacturerDir(int $manufacturerId): string
    {
        $folder = $this->resolveManufacturerFolder($manufacturerId);
        if ($folder !== '') {
            return 'catalog/' . $folder;
        }

        $slug = $this->manufacturerSlugFromId($manufacturerId);
        return 'catalog/' . $slug;
    }

    private function tempDir(string $token): string
    {
        $token = preg_replace('/[^A-Za-z0-9_-]/', '', $token);
        return 'tmp/product-images/' . ($token ?: 'default');
    }

    private function incomingDir(): string
    {
        return 'catalog/_incoming/' . date('Y') . '/' . date('m');
    }

    public function thumb(Request $request)
    {
        $path = (string) $request->query('path', '');
        abort_unless(str_starts_with($path, 'catalog/') && ! str_contains($path, '..'), 404);

        $copy = \App\Services\Media\ImageCache::path($path, \App\Services\Media\ImageCache::THUMB);
        abort_if($copy === null, 404);

        return redirect(\App\Services\Media\ImageCache::publicUrl($copy));
    }

    private function publicUrl(string $relativePublicDiskPath): string
    {
        $relativePublicDiskPath = str_replace('\\', '/', ltrim($relativePublicDiskPath, '/'));
        $segments = array_map('rawurlencode', explode('/', $relativePublicDiskPath));
        return asset('storage/' . implode('/', $segments));
    }

    private function normalizeCatalogSubPath(string $path): string
    {
        $path = str_replace('\\', '/', $path);
        $path = trim($path, '/');
        if ($path === '') return '';
        if (str_contains($path, '..')) {
            abort(422, 'Invalid path.');
        }
        if (!preg_match('#^[A-Za-z0-9/_\- .&\']+$#', $path)) {
            abort(422, 'Invalid path.');
        }
        return $path;
    }

    private function uniqueTargetPath(string $dir, string $filename): string
    {
        $dir = trim($dir, '/');
        $filename = trim($filename);
        $disk = Storage::disk('public');

        $base = pathinfo($filename, PATHINFO_FILENAME);
        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));

        $candidate = $dir . '/' . $base . '.' . $ext;
        if (!$disk->exists($candidate)) {
            return $candidate;
        }

        $i = 1;
        while (true) {
            $candidate = $dir . '/' . $base . '_' . $i . '.' . $ext;
            if (!$disk->exists($candidate)) {
                return $candidate;
            }
            $i++;
            if ($i > 9999) {
                abort(500, 'Unable to generate unique image filename.');
            }
        }
    }

    public function upload(Request $request)
    {
        $this->tmpCleanup();

        $request->validate([
            'manufacturer_id' => 'nullable|integer|min:0',
            'file' => 'required|file|max:5120',
            'product_id' => 'nullable|integer|min:1',
            'token' => 'nullable|string|max:80',
        ]);

        $productId = (int) ($request->product_id ?? 0);
        $token = (string) ($request->token ?? '');

        $file = $request->file('file');
        $original = $file->getClientOriginalName() ?: 'image.jpg';
        $original = basename($original);

        $ext = strtolower($file->getClientOriginalExtension() ?: '');
        if (!in_array($ext, ['jpg', 'jpeg', 'png', 'webp'], true)) {
            return response()->json(['ok' => false, 'message' => 'Only JPG, PNG, and WebP images are allowed.'], 422);
        }

        $disk = Storage::disk('public');

        $mid = (int) ($request->manufacturer_id ?? 0);

        if ($productId > 0) {
            $dir = $this->manufacturerDir($mid);

            $tmpPath = $file->storeAs('tmp/product-images', Str::uuid()->toString() . '.' . $ext, 'public');
            $absTmp = $disk->path($tmpPath);
            $this->ensureAllowedImage($absTmp);
            $tmpPath = $this->convertWebpToJpg($tmpPath);

            $original = preg_replace('/\.webp$/i', '.jpg', $original);
            $disk->makeDirectory($dir);
            $target = $this->uniqueTargetPath($dir, $original);
            $disk->move($tmpPath, $target);

            return response()->json([
                'ok' => true,
                'kind' => 'final',
                'path' => $target,
                'url' => $this->publicUrl($target),
                'name' => basename($target),
            ]);
        }

        if (trim($token) === '') {
            return response()->json(['ok' => false, 'message' => 'Missing upload token. Please refresh and try again.'], 422);
        }

        $tmpDir = $this->tempDir($token);

        $tmpPath = $file->storeAs('tmp/product-images', Str::uuid()->toString() . '.' . $ext, 'public');
        $absTmp = $disk->path($tmpPath);
        $this->ensureAllowedImage($absTmp);
        $tmpPath = $this->convertWebpToJpg($tmpPath);

        $original = preg_replace('/\.webp$/i', '.jpg', $original);
        $tmpTarget = $this->uniqueTargetPath($tmpDir, $original);
        $disk->makeDirectory($tmpDir);
        $disk->move($tmpPath, $tmpTarget);

        return response()->json([
            'ok' => true,
            'kind' => 'temp',
            'path' => $tmpTarget,
            'url' => $this->publicUrl($tmpTarget),
            'name' => basename($tmpTarget),
        ]);
    }


    public function importUrl(Request $request)
    {
        $this->tmpCleanup();

        $request->validate([
            'manufacturer_id' => 'nullable|integer|min:0',
            'url' => 'required|string|max:2048',
            'product_id' => 'nullable|integer|min:1',
            'token' => 'nullable|string|max:80',
        ]);

        $productId = (int) ($request->product_id ?? 0);
        $token = (string) ($request->token ?? '');

        $url = trim((string) $request->url);
        if (!preg_match('#^https?://#i', $url) || !$this->isSafeRemoteUrl($url)) {
            return response()->json(['ok' => false, 'message' => 'Invalid URL.'], 422);
        }

        $contents = $this->fetchRemoteImageContents($url);
        if ($contents === false) {
            return response()->json(['ok' => false, 'message' => 'Unable to download image from URL.'], 422);
        }

        $pathPart = parse_url($url, PHP_URL_PATH) ?: '';
        $name = basename($pathPart) ?: ('image_' . date('Ymd_His') . '.jpg');
        $name = preg_replace('/[^A-Za-z0-9._-]/', '_', $name);

        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        if (!in_array($ext, ['jpg', 'jpeg', 'png', 'webp'], true)) {
            $tmp = tempnam(sys_get_temp_dir(), 'img');
            file_put_contents($tmp, $contents);
            $info = @getimagesize($tmp);
            @unlink($tmp);
            if ($info && isset($info[2])) {
                $ext = match ((int) $info[2]) {
                    IMAGETYPE_PNG => 'png',
                    IMAGETYPE_WEBP => 'webp',
                    default => 'jpg',
                };
            } else {
                $ext = 'jpg';
            }
            $name = pathinfo($name, PATHINFO_FILENAME) . '.' . $ext;
        }

        $disk = Storage::disk('public');
        $tmpRel = 'tmp/product-images/' . Str::uuid()->toString() . '.' . $ext;
        $disk->put($tmpRel, $contents);
        $absTmp = $disk->path($tmpRel);
        $this->ensureAllowedImage($absTmp);
        $tmpRel = $this->convertWebpToJpg($tmpRel);
        $name = preg_replace('/\.webp$/i', '.jpg', $name);

        $mid = (int) ($request->manufacturer_id ?? 0);

        if ($productId > 0) {
            $dir = $this->manufacturerDir($mid);
            $disk->makeDirectory($dir);
            $target = $this->uniqueTargetPath($dir, $name);
            $disk->move($tmpRel, $target);

            return response()->json([
                'ok' => true,
                'kind' => 'final',
                'path' => $target,
                'url' => $this->publicUrl($target),
                'name' => basename($target),
            ]);
        }

        if (trim($token) === '') {
            $disk->delete($tmpRel);
            return response()->json(['ok' => false, 'message' => 'Missing upload token. Please refresh and try again.'], 422);
        }

        $tmpDir = $this->tempDir($token);
        $disk->makeDirectory($tmpDir);
        $target = $this->uniqueTargetPath($tmpDir, $name);
        $disk->move($tmpRel, $target);

        return response()->json([
            'ok' => true,
            'kind' => 'temp',
            'path' => $target,
            'url' => $this->publicUrl($target),
            'name' => basename($target),
        ]);
    }




    public function uploadToCatalog(Request $request)
    {
        $request->validate([
            'file' => 'required|file|max:5120',
            'path' => 'nullable|string|max:255',
        ]);

        $this->tmpCleanup();

        $sub = $this->normalizeCatalogSubPath((string) ($request->path ?? ''));
        $dir = 'catalog' . ($sub !== '' ? '/' . $sub : '');

        $file = $request->file('file');
        $original = basename($file->getClientOriginalName() ?: 'image.jpg');

        $ext = strtolower($file->getClientOriginalExtension() ?: '');
        if (!in_array($ext, ['jpg', 'jpeg', 'png', 'webp'], true)) {
            return response()->json(['ok' => false, 'message' => 'Only JPG, PNG, and WebP images are allowed.'], 422);
        }

        $disk = Storage::disk('public');

        $tmpPath = $file->storeAs('tmp/product-images', Str::uuid()->toString() . '.' . $ext, 'public');
        $absTmp = $disk->path($tmpPath);
        $this->ensureAllowedImage($absTmp);
        $tmpPath = $this->convertWebpToJpg($tmpPath);

        $original = preg_replace('/\.webp$/i', '.jpg', $original);
        $disk->makeDirectory($dir);
        $target = $this->uniqueTargetPath($dir, $original);
        $disk->move($tmpPath, $target);

        return response()->json([
            'ok' => true,
            'path' => $target,
            'url' => $this->publicUrl($target),
            'name' => basename($target),
        ]);
    }

    public function importUrlToCatalog(Request $request)
    {
        $request->validate([
            'url' => 'required|string|max:2048',
            'path' => 'nullable|string|max:255',
        ]);

        $this->tmpCleanup();

        $sub = $this->normalizeCatalogSubPath((string) ($request->path ?? ''));
        $dir = 'catalog' . ($sub !== '' ? '/' . $sub : '');

        try {
            $target = app(\App\Services\Media\CatalogImageImporter::class)->fromUrl((string) $request->url, $dir);
        } catch (\RuntimeException $e) {
            return response()->json(['ok' => false, 'message' => $e->getMessage()], 422);
        }

        return response()->json([
            'ok' => true,
            'path' => $target,
            'url' => $this->publicUrl($target),
            'name' => basename($target),
        ]);
    }

    public function deleteFromCatalog(Request $request)
    {
        $request->validate([
            'path' => 'required|string|max:500',
        ]);

        $path = trim($request->path);

        if (!str_starts_with($path, 'catalog/')) {
            return response()->json(['ok' => false, 'message' => 'Can only delete images from catalog folder.'], 422);
        }

        $disk = Storage::disk('public');

        if (!$disk->exists($path)) {
            return response()->json(['ok' => false, 'message' => 'File not found.'], 404);
        }

        $disk->delete($path);

        return response()->json(['ok' => true]);
    }

    private function resolveManufacturerFolder(int $manufacturerId): string
    {
        if ($manufacturerId <= 0) {
            return '';
        }

        $m = Manufacturer::query()
            ->where('manufacturer_id', $manufacturerId)
            ->first(['name']);

        if (!$m || !$m->name) {
            return '';
        }

        $disk = Storage::disk('public');
        $slug = Str::slug((string) $m->name);
        $name = (string) $m->name;

        if ($disk->exists('catalog/' . $name)) {
            return $name;
        }
        if ($slug !== '' && $disk->exists('catalog/' . $slug)) {
            return $slug;
        }

        $nameLower = mb_strtolower($name);
        $slugLower = mb_strtolower($slug);
        foreach ($disk->directories('catalog') as $dir) {
            $folder = basename($dir);
            $folderLower = mb_strtolower($folder);
            if ($folderLower === $nameLower || $folderLower === $slugLower) {
                return $folder;
            }
        }

        return '';
    }


    private function searchLibrary($disk, string $base, string $query): array
    {
        $needle = mb_strtolower($query);

        $matchedFolders = [];
        $matchedPrefixes = [];

        foreach ($disk->allDirectories($base) as $dir) {
            $dir = str_replace('\\', '/', $dir);
            $rel = trim((string) preg_replace('#^'.preg_quote($base, '#').'/?#', '', $dir), '/');

            if ($rel === '') {
                continue;
            }

            if (str_contains(mb_strtolower(basename($rel)), $needle)) {
                $matchedFolders[] = ['path' => $rel, 'name' => basename($rel)];
                $matchedPrefixes[] = $base.'/'.$rel.'/';
            }
        }

        $files = [];
        $truncated = false;

        foreach ($disk->allFiles($base) as $file) {
            $file = str_replace('\\', '/', $file);

            if (! in_array(strtolower(pathinfo($file, PATHINFO_EXTENSION)), ['jpg', 'jpeg', 'png', 'webp'], true)) {
                continue;
            }

            $name = basename($file);
            $hit = str_contains(mb_strtolower($name), $needle);

            if (! $hit) {
                foreach ($matchedPrefixes as $prefix) {
                    if (str_starts_with($file, $prefix)) {
                        $hit = true;
                        break;
                    }
                }
            }

            if (! $hit) {
                continue;
            }

            if (count($files) >= self::SEARCH_LIMIT) {
                $truncated = true;
                break;
            }

            $folder = trim((string) preg_replace('#^'.preg_quote($base, '#').'/?#', '', dirname($file)), '/');

            $files[] = [
                'path' => $file,
                'url' => $this->publicUrl($file),
                'name' => $name,
                'folder' => $folder,
                'modified' => $this->safeLastModified($disk, $file),
            ];
        }

        usort($files, fn ($a, $b) => strnatcasecmp($a['name'], $b['name']));

        usort($matchedFolders, fn ($a, $b) => strnatcasecmp($a['name'], $b['name']));

        return [
            'ok' => true,
            'search' => true,
            'query' => $query,
            'path' => '',
            'parent' => null,
            'folders' => $matchedFolders,
            'files' => $files,
            'truncated' => $truncated,
        ];
    }

    private function safeLastModified($disk, string $file): int
    {
        try {
            return (int) $disk->lastModified($file);
        } catch (\Throwable $e) {
            return 0;
        }
    }


    public function createFolder(Request $request)
    {
        $request->validate([
            'path' => 'nullable|string|max:255',
            'name' => 'required|string|max:64',
        ]);

        $parent = $this->normalizeCatalogSubPath((string) $request->input('path', ''));
        $name = trim((string) $request->input('name'));

        if ($name === '' || str_contains($name, '/') || str_contains($name, '\\')) {
            return response()->json(['ok' => false, 'message' => 'A folder name cannot contain a slash.'], 422);
        }

        if (str_starts_with($name, '.')) {
            return response()->json(['ok' => false, 'message' => 'A folder name cannot start with a dot.'], 422);
        }

        if (! preg_match("#^[A-Za-z0-9_\- .&']+$#", $name)) {
            return response()->json([
                'ok' => false,
                'message' => 'Use letters, numbers, spaces, and - _ . & only.',
            ], 422);
        }

        $rel = ($parent !== '' ? $parent.'/' : '').$name;
        $dir = 'catalog/'.$rel;

        $disk = Storage::disk('public');

        if ($disk->exists($dir)) {
            return response()->json(['ok' => false, 'message' => 'A folder called "'.$name.'" is already here.'], 422);
        }

        $disk->makeDirectory($dir);

        if (! $disk->exists($dir)) {
            return response()->json(['ok' => false, 'message' => 'The folder could not be created.'], 500);
        }

        return response()->json(['ok' => true, 'path' => $rel, 'name' => $name]);
    }

    public function browse(Request $request)
    {
        $request->validate([
            'manufacturer_id' => 'nullable|integer|min:0',
            'product_id' => 'nullable|integer|min:0',
            'path' => 'nullable|string|max:255',
            'start_at_manufacturer' => 'nullable|boolean',
            'q' => 'nullable|string|max:120',
        ]);

        $base = 'catalog';
        $disk = Storage::disk('public');

        $query = trim((string) $request->input('q', ''));

        if ($query !== '') {
            return response()->json($this->searchLibrary($disk, $base, $query));
        }

        $path = (string) ($request->path ?? '');
        $serverResolved = false;

        if ($request->boolean('start_at_manufacturer') && $path === '') {
            $mid = (int) ($request->manufacturer_id ?? 0);
            $folder = $this->resolveManufacturerFolder($mid);
            if ($folder === '' && $mid > 0) {
                $m = Manufacturer::query()->where('manufacturer_id', $mid)->first(['name']);
                if ($m && trim((string) $m->name) !== '') {
                    $folder = trim((string) $m->name);
                    $disk->makeDirectory('catalog/' . $folder);
                }
            }
            if ($folder !== '') {
                $path = $folder;
                $serverResolved = true;
            }
        }

        $path = str_replace('\\', '/', $path);
        $path = trim($path, '/');

        if ($path !== '' && !$serverResolved) {
            if (str_contains($path, '..')) {
                abort(422, 'Invalid path.');
            }
            if (!preg_match('#^[A-Za-z0-9/_\- .&\']+$#', $path)) {
                abort(422, 'Invalid path.');
            }
        }

        $dir = $base . ($path !== '' ? '/' . $path : '');
        if (!$disk->exists($dir)) {
            return response()->json([
                'ok' => true,
                'path' => $path,
                'parent' => $path !== '' ? (dirname($path) === '.' ? '' : str_replace('\\', '/', dirname($path))) : null,
                'folders' => [],
                'files' => [],
            ]);
        }

        $folders = collect($disk->directories($dir))
            ->map(function ($d) use ($base) {
                $d = str_replace('\\', '/', $d);
                $rel = preg_replace('#^' . preg_quote($base, '#') . '/?#', '', $d);
                $rel = trim((string) $rel, '/');

                return [
                    'path' => $rel,
                    'name' => basename($d),
                ];
            })
            ->sortBy(function ($x) {
                return (string) ($x['name'] ?? '');
            }, SORT_NATURAL | SORT_FLAG_CASE)
            ->values();

        $files = collect($disk->files($dir))
            ->filter(function ($f) {
                $ext = strtolower(pathinfo($f, PATHINFO_EXTENSION));
                return in_array($ext, ['jpg', 'jpeg', 'png', 'webp'], true);
            })
            ->map(function ($f) use ($disk) {
                $modified = 0;
                try {
                    $modified = (int) $disk->lastModified($f);
                } catch (\Throwable $e) {
                    $modified = 0;
                }

                return [
                    'path' => $f,
                    'url' => $this->publicUrl($f),
                    'thumb' => \App\Services\Media\ImageCache::existing($f) ?? route('products.images.thumb', ['path' => $f]),
                    'name' => basename($f),
                    'modified' => $modified,
                ];
            })
            ->sort(function ($a, $b) {
                $ma = (int) ($a['modified'] ?? 0);
                $mb = (int) ($b['modified'] ?? 0);
                if ($ma !== $mb) return $mb <=> $ma;

                return strcmp((string) ($a['name'] ?? ''), (string) ($b['name'] ?? ''));
            })
            ->values();

        $parent = null;
        if ($path !== '') {
            $p = dirname($path);
            $parent = ($p === '.' ? '' : str_replace('\\', '/', $p));
        }

        return response()->json([
            'ok' => true,
            'path' => $path,
            'parent' => $parent,
            'folders' => $folders,
            'files' => $files,
        ]);
    }

}
