<?php

namespace Extensions\shopee\Services\Shopee;

use Extensions\shopee\Models\ShopeeApiLog;

/**
 * A Shopee item's description has one of two shapes. "normal" is a single
 * text field. "extended" is an ordered list of text and image fields, and it
 * is what Seller Center's rich editor saves, even when the list holds only
 * text. update_item refuses a plain description onto an extended item, so
 * an update reads the item's shape first and answers in it: the listing's
 * words take the place of the text, and the item's own images stay where
 * they are.
 */
final class ShopeeDescription
{
    public function __construct(private readonly ShopeeClient $client) {}

    /**
     * The live item's description shape, or null when Shopee did not
     * answer, in which case the update sends a plain description as it
     * always has.
     *
     * @return array{type: string, fields: list<array<string, mixed>>}|null
     */
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

    /**
     * The description written into an update_item payload in the item's own
     * shape. An extended item keeps every image field in its place; the
     * first text field carries the listing's words and the other text
     * fields go, so the words are not said twice. An extended item with no
     * text field gets the words first.
     *
     * @param  array{type: string, fields: list<array<string, mixed>>}|null  $shape
     */
    public static function into(array $payload, string $text, ?array $shape): array
    {
        if (($shape['type'] ?? 'normal') !== 'extended') {
            $payload['description'] = $text;

            return $payload;
        }

        unset($payload['description']);
        $fields = [];
        $placed = false;
        foreach ($shape['fields'] as $field) {
            $type = (string) ($field['field_type'] ?? '');
            if ($type === 'image') {
                $imageId = (string) data_get($field, 'image_info.image_id', '');
                if ($imageId !== '') {
                    $fields[] = ['field_type' => 'image', 'image_info' => ['image_id' => $imageId]];
                }
            } elseif ($type === 'text' && ! $placed) {
                $fields[] = ['field_type' => 'text', 'text' => $text];
                $placed = true;
            }
        }
        if (! $placed) {
            array_unshift($fields, ['field_type' => 'text', 'text' => $text]);
        }

        $payload['description_type'] = 'extended';
        $payload['description_info'] = ['extended_description' => ['field_list' => $fields]];

        return $payload;
    }

    /**
     * The words of an item read from Shopee: the plain description, or the
     * text fields of an extended one, a blank line between them.
     */
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
}
