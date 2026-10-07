<?php

namespace App\Integrations\Push;

use Illuminate\Support\Facades\DB;

final class BulkPushRun
{
    public const LIMIT = 25;

    public static function over(array $productIds, string $channel, callable $pushOne, int $limit = self::LIMIT): array
    {
        $productIds = array_values(array_unique(array_filter(array_map('intval', $productIds))));
        if ($productIds === []) {
            return ['key' => 'error', 'message' => 'No products selected.'];
        }

        $overflow = array_slice($productIds, $limit);
        $pushed = [];
        $refused = [];

        foreach (array_slice($productIds, 0, $limit) as $productId) {
            $answer = $pushOne($productId);
            $name = self::label($productId);
            if ($answer['ok'] ?? false) {
                $pushed[] = $name;
            } else {
                $refused[] = $name . ': ' . trim((string) ($answer['message'] ?? 'it was refused and gave no reason.'));
            }
        }

        $parts = [];
        if ($pushed !== []) {
            $parts[] = count($pushed) . ' pushed to ' . $channel . '.';
        }
        if ($refused !== []) {
            $parts[] = count($refused) . ' not pushed. ' . implode(' ', $refused);
        }
        if ($overflow !== []) {
            $parts[] = count($overflow) . ' left for a second press: ' . $limit . ' go up at a time.';
        }

        return ['key' => $pushed === [] ? 'error' : 'status', 'message' => implode(' ', $parts)];
    }

    public static function label(int $productId): string
    {
        $pfx = (string) config('catalog.prefix');
        $name = DB::table($pfx . 'product_description')
            ->where('product_id', $productId)
            ->where('language_id', (int) config('catalog.default_language_id'))
            ->value('name');
        $name = trim(html_entity_decode((string) $name, ENT_QUOTES | ENT_HTML5, 'UTF-8'));

        return $name !== '' ? $name : 'Product #' . $productId;
    }
}
