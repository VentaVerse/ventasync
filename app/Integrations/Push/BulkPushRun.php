<?php

namespace App\Integrations\Push;

use Illuminate\Support\Facades\DB;

/**
 * MULTI-TICK PUSH, IN ONE SET OF WORDS.
 *
 * Ticking ten products and pressing Push is the same act on every channel,
 * so it answers in the same sentence on every channel: how many went up,
 * which ones did not and why, in the marketplace's own words, and what a
 * second press would still do.
 *
 * the owner, 2026-09-09, on why this came back after being taken off the
 * Listings page on 2026-09-06: both reasons for its removal had expired. A
 * batch used to need a combined form for couriers, category and markup,
 * and every listing now carries its own price rule (else its group's), so
 * there is nothing shared left to ask for; and a bar reporting one outcome
 * could not describe ten creates refused for ten different reasons, which
 * is exactly what naming each product by name fixes.
 *
 * THE CEILING IS SAID OUT LOUD. Each push uploads images and creates an
 * item at a marketplace, so a selection of hundreds would outlive the
 * request. The overflow is NAMED and left for a second press, never
 * dropped in silence - a bar that quietly ignores half a selection is the
 * defect this whole rebuild exists to remove.
 */
final class BulkPushRun
{
    /** How many go up on one press. */
    public const LIMIT = 25;

    /**
     * Walk a selection, push each one on its own settings, and say what
     * happened.
     *
     * @param  list<int>  $productIds
     * @param  string  $channel  the store's name as the operator reads it
     * @param  callable(int): array{ok: bool, message?: string}  $pushOne
     * @return array{key: string, message: string}  the flash to hand back
     */
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

    /** A product by the name an operator would recognise, for a ledger line. */
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
