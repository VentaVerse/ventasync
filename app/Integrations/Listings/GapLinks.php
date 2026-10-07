<?php

namespace App\Integrations\Listings;

final class GapLinks
{
    private const CATALOG_OWNED = [
        ListingState::GAP_SKU,
        ListingState::GAP_NAME,
        ListingState::GAP_DESCRIPTION,
        ListingState::GAP_PRICE,
        ListingState::GAP_PARCEL,
        ListingState::GAP_IMAGE,
        ListingState::GAP_DISABLED,
        ListingState::GAP_VARIATIONS,
    ];

    public static function words(array $gaps): string
    {
        $labels = [];
        foreach ($gaps as $gap) {
            $label = trim((string) ($gap['label'] ?? ''));
            if ($label !== '') {
                $labels[] = $label;
            }
        }

        if ($labels === []) {
            return '';
        }

        return count($labels) > 1
            ? implode(', ', array_slice($labels, 0, -1)) . ' and ' . end($labels)
            : $labels[0];
    }

    public static function sentence(array $gaps, string $channel, bool $isLive): string
    {
        $disabled = false;
        $rest = [];
        foreach ($gaps as $gap) {
            if (($gap['code'] ?? '') === ListingState::GAP_DISABLED) {
                $disabled = true;
            } else {
                $rest[] = $gap;
            }
        }

        $words = self::words($rest);
        if ($disabled) {
            return ($isLive ? 'Push update' : 'Not eligible for ' . $channel) . ': the ' . CatalogGaps::DISABLED_LABEL
                . ($words !== '' ? ', and it needs ' . $words : '') . '.';
        }
        if ($words === '') {
            return '';
        }

        return $isLive
            ? 'Push update needs ' . $words . '.'
            : 'Not eligible for ' . $channel . ': it needs ' . $words . '.';
    }

    public static function action(array $gaps, int $productId, array $anchors = []): ?array
    {
        foreach ($gaps as $gap) {
            $code = (string) ($gap['code'] ?? '');
            if (in_array($code, self::CATALOG_OWNED, true) && ! isset($anchors[$code])) {
                return [
                    'href' => route('products.edit', $productId),
                    'label' => 'Open the catalog product',
                    'onPage' => false,
                ];
            }
            if (isset($anchors[$code])) {
                return [
                    'href' => $anchors[$code],
                    'label' => self::setLabel((string) ($gap['label'] ?? '')),
                    'onPage' => true,
                ];
            }
        }

        return null;
    }

    private static function setLabel(string $label): string
    {
        $label = trim($label);

        return $label === '' ? 'Go to what is missing' : 'Set ' . $label;
    }
}
