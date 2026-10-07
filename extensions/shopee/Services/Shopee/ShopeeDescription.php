<?php

namespace Extensions\shopee\Services\Shopee;

use App\Services\Media\CatalogImageImporter;
use App\Support\Catalog\DescriptionText;
use Extensions\shopee\Models\ShopeeApiLog;
use Illuminate\Support\Str;

final class ShopeeDescription
{
    public const MAX_TEXT = 5000;

    private const MAX_REMOTE_BYTES = 10 * 1024 * 1024;

    public function __construct(
        private readonly ShopeeClient $client,
        private readonly ?ShopeeItemCreate $create = null,
    ) {}

    public function shapeOf(array $auth, int $itemId): ?array
    {
        $params = ['item_id_list' => (string) $itemId, 'need_complex_description' => 'true'];
        try {
            $r = $this->client->shopGet(
                $auth['mode'],
                (int) $auth['partner_id'], (string) $auth['partner_key'],
                (string) $auth['access_token'], (int) $auth['shop_id'],
                '/api/v2/product/get_item_base_info',
                $params
            );
        } catch (\Throwable) {
            return null;
        }

        ShopeeApiLog::safeCreate([
            'pack' => 'shopee.listings.description_shape', 'method' => 'GET',
            'api_path' => '/api/v2/product/get_item_base_info', 'auth_required' => true,
            'request_params' => $params,
            'response_status' => $r['status'] ?? null,
            'ok' => (bool) ($r['ok'] ?? false),
            'response_body' => $r['body'] ?? null, 'user_id' => auth()->id(),
        ]);

        $item = data_get($r, 'body.response.item_list.0');
        if (! ($r['ok'] ?? false) || ! is_array($item)) {
            return null;
        }

        return [
            'type' => (string) ($item['description_type'] ?? '') === 'extended' ? 'extended' : 'normal',
            'fields' => array_values(array_filter((array) data_get($item, 'description_info.extended_description.field_list', []), 'is_array')),
        ];
    }

    public function write(array $payload, array $auth, int $itemId, string $html): array
    {
        $shape = $this->shapeOf($auth, $itemId);
        if (($shape['type'] ?? 'normal') !== 'extended') {
            return ['payload' => $payload, 'note' => ''];
        }

        unset($payload['description']);
        $ours = $this->fieldsOf($auth, $html);
        if ($ours['fields'] === []) {
            return ['payload' => $payload, 'note' => 'The description was not sent: it has no words and none of its pictures could be uploaded.'];
        }

        $payload['description_type'] = 'extended';
        $payload['description_info'] = ['extended_description' => ['field_list' => $ours['fields']]];

        return ['payload' => $payload, 'note' => self::leftOutNote($ours['left_out'])];
    }

    public function fieldsOf(array $auth, string $html): array
    {
        $parts = preg_split('#(<img\b[^>]*>)#i', $html, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY) ?: [];
        $fields = [];
        $leftOut = 0;

        foreach ($parts as $part) {
            if (preg_match('#^<img\b#i', $part) === 1) {
                $imageId = $this->upload($auth, self::srcOf($part));
                if ($imageId === null) {
                    $leftOut++;
                } else {
                    $fields[] = ['field_type' => 'image', 'image_info' => ['image_id' => $imageId]];
                }

                continue;
            }

            $text = DescriptionText::of($part);
            if ($text === '') {
                continue;
            }
            $last = array_key_last($fields);
            if ($last !== null && $fields[$last]['field_type'] === 'text') {
                $fields[$last]['text'] .= "\n\n" . $text;
            } else {
                $fields[] = ['field_type' => 'text', 'text' => $text];
            }
        }

        return ['fields' => self::withinTextLimit($fields), 'left_out' => $leftOut];
    }

    public static function leftOutNote(int $count): string
    {
        return match (true) {
            $count <= 0 => '',
            $count === 1 => '1 description picture could not be uploaded and was left out.',
            default => $count . ' description pictures could not be uploaded and were left out.',
        };
    }

    public static function textOf(array $item): string
    {
        $plain = trim((string) ($item['description'] ?? ''));
        if ($plain !== '') {
            return $plain;
        }
        $parts = [];
        foreach ((array) data_get($item, 'description_info.extended_description.field_list', []) as $field) {
            if (is_array($field) && ($field['field_type'] ?? '') === 'text' && trim((string) ($field['text'] ?? '')) !== '') {
                $parts[] = trim((string) $field['text']);
            }
        }

        return implode("\n\n", $parts);
    }

    private static function withinTextLimit(array $fields): array
    {
        $room = self::MAX_TEXT;
        $out = [];
        foreach ($fields as $field) {
            if ($field['field_type'] === 'text') {
                if ($room <= 0) {
                    continue;
                }
                $field['text'] = mb_substr((string) $field['text'], 0, $room);
                $room -= mb_strlen($field['text']);
            }
            $out[] = $field;
        }

        return $out;
    }

    private static function srcOf(string $tag): string
    {
        if (preg_match('#(?<![\w-])src\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s>]+))#i', $tag, $m) !== 1) {
            return '';
        }

        return trim(html_entity_decode(($m[1] ?? '') . ($m[2] ?? '') . ($m[3] ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }

    private function upload(array $auth, string $src): ?string
    {
        if ($src === '') {
            return null;
        }

        $temp = null;
        $file = $this->libraryFile($src);
        if ($file === null) {
            $file = $temp = $this->fetchedFile($src);
        }
        if ($file === null) {
            return null;
        }

        try {
            $r = ($this->create ?? app(ShopeeItemCreate::class))->uploadImage($this->client, $auth, $file, 'desc');
        } catch (\Throwable $e) {
            $r = ['status' => 0, 'ok' => false, 'body' => ['error' => 'upload_failed', 'message' => $e->getMessage()]];
        } finally {
            if ($temp !== null && is_file($temp)) {
                unlink($temp);
            }
        }

        ShopeeApiLog::safeCreate([
            'pack' => 'shopee.listings.description_image', 'method' => 'POST',
            'api_path' => '/api/v2/media_space/upload_image', 'auth_required' => true,
            'request_params' => ['image' => Str::limit($src, 500), 'scene' => 'desc'],
            'response_status' => $r['status'] ?? null,
            'ok' => (bool) ($r['ok'] ?? false),
            'response_body' => $r['body'] ?? null, 'user_id' => auth()->id(),
        ]);

        $imageId = (string) data_get($r, 'body.response.image_info.image_id', '');

        return ($r['ok'] ?? false) && $imageId !== '' ? $imageId : null;
    }

    private function libraryFile(string $src): ?string
    {
        if (preg_match('#^[a-z][a-z0-9+.-]*:#i', $src) === 1 && preg_match('#^https?://#i', $src) !== 1) {
            return null;
        }

        $parts = parse_url($src);
        if ($parts === false) {
            return null;
        }
        $host = strtolower((string) ($parts['host'] ?? ''));
        if ($host !== '' && ! in_array($host, $this->ownHosts(), true)) {
            return null;
        }

        $path = ltrim(rawurldecode((string) ($parts['path'] ?? '')), '/');
        $base = trim((string) parse_url((string) config('app.url'), PHP_URL_PATH), '/');
        if ($base !== '' && str_starts_with($path, $base . '/')) {
            $path = substr($path, strlen($base) + 1);
        }
        if ($path === '' || str_contains($path, "\0") || in_array('..', explode('/', $path), true)) {
            return null;
        }

        foreach (['storage/' => storage_path('app/public'), 'image/' => public_path('image')] as $prefix => $root) {
            if (! str_starts_with($path, $prefix)) {
                continue;
            }
            $rootReal = realpath($root);
            $file = realpath($root . '/' . substr($path, strlen($prefix)));
            if ($rootReal !== false && $file !== false && str_starts_with($file, $rootReal . DIRECTORY_SEPARATOR) && is_file($file)) {
                return $file;
            }
        }

        return null;
    }

    private function ownHosts(): array
    {
        $hosts = [strtolower((string) parse_url((string) config('app.url'), PHP_URL_HOST))];
        if (app()->bound('request')) {
            $hosts[] = strtolower(request()->getHost());
        }

        return array_values(array_unique(array_filter($hosts)));
    }

    private function fetchedFile(string $src): ?string
    {
        if (preg_match('#^https?://#i', $src) !== 1) {
            return null;
        }

        try {
            $bytes = app(CatalogImageImporter::class)->fetch($src);
        } catch (\Throwable) {
            return null;
        }
        if (! is_string($bytes) || $bytes === '' || strlen($bytes) > self::MAX_REMOTE_BYTES) {
            return null;
        }

        $info = @getimagesizefromstring($bytes);
        $ext = match ($info === false ? 0 : (int) ($info[2] ?? 0)) {
            IMAGETYPE_JPEG => 'jpg',
            IMAGETYPE_PNG => 'png',
            IMAGETYPE_WEBP => 'webp',
            IMAGETYPE_GIF => 'gif',
            default => null,
        };
        if ($ext === null) {
            return null;
        }

        $path = sys_get_temp_dir() . '/shopee-desc-' . Str::random(24) . '.' . $ext;

        return file_put_contents($path, $bytes) === false ? null : $path;
    }
}
