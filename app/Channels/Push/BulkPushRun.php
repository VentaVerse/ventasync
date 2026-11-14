<?php

namespace App\Channels\Push;

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
        $notes = [];
        $warned = false;

        foreach (array_slice($productIds, 0, $limit) as $productId) {
            $answer = $pushOne($productId);
            $name = self::label($productId);
            if ($answer['ok'] ?? false) {
                $pushed[] = $name;
                $warned = $warned || ! empty($answer['warning']);
                $note = trim((string) ($answer['note'] ?? (! empty($answer['warning']) ? ($answer['message'] ?? '') : '')));
                if ($note !== '') {
                    $notes[$note][] = $name;
                }
            } else {
                $refused[trim((string) ($answer['message'] ?? '')) ?: 'It was refused and gave no reason.'][] = $name;
            }
        }

        $parts = [];
        if ($pushed !== []) {
            $parts[] = (count($pushed) === 1 ? $pushed[0] : count($pushed)) . ' pushed to ' . $channel . '.';
        }
        if ($notes !== []) {
            $parts[] = self::grouped($notes, null);
        }
        if ($refused !== []) {
            $parts[] = self::grouped($refused, 'not pushed');
        }
        if ($overflow !== []) {
            $parts[] = count($overflow) . ' more selected: press Push again for the next ' . min(count($overflow), $limit) . '.';
        }

        return ['key' => $pushed === [] ? 'error' : ($warned ? 'warning' : 'status'), 'message' => implode(' ', $parts)];
    }

    private static function grouped(array $byReason, ?string $verb): string
    {
        uasort($byReason, fn ($a, $b) => count($b) <=> count($a));
        $total = array_sum(array_map('count', $byReason));
        $shown = array_slice($byReason, 0, 3, true);
        $plain = fn (string $reason) => rtrim($reason, '. ');

        if (count($byReason) === 1) {
            $reason = array_key_first($byReason);
            $names = $byReason[$reason];
            $who = count($names) === 1 ? $names[0] : (string) count($names);

            return $verb ? "{$who} {$verb}: {$plain($reason)}." : "{$who}: {$plain($reason)}.";
        }

        $lines = [];
        foreach ($shown as $reason => $names) {
            $lines[] = $plain($reason) . ' (' . count($names) . ')';
        }
        $rest = count($byReason) > 3 ? " The rest are on each product's error line." : '';

        return ($verb ? "{$total} {$verb}: " : '') . implode('; ', $lines) . '.' . $rest;
    }

    public static function label(int $productId): string
    {
        $name = DB::table('product_descriptions')
            ->where('product_id', $productId)
            ->where('language_id', (int) config('catalog.default_language_id'))
            ->value('name');
        $name = trim(html_entity_decode((string) $name, ENT_QUOTES | ENT_HTML5, 'UTF-8'));

        return $name !== '' ? $name : 'Product #' . $productId;
    }
}
