<?php

namespace App\Integrations\Listings;

use App\Models\DescriptionTemplate;
use App\Support\Catalog\DescriptionHtml;
use App\Support\Catalog\DescriptionText;

class ListingContent
{
    public static function of(?object $listing, string $coreTitle, string $coreDescription, ?string $integration = null, ?int $storeId = null): array
    {
        if ($listing === null || ! method_exists($listing, 'contentOverrides') || (property_exists($listing, 'exists') && ! $listing->exists)) {
            $decoded = DescriptionHtml::decoded($coreDescription);
            $title = CatalogCopy::title($coreTitle);
            $description = DescriptionText::looksLikeHtml($decoded) ? DescriptionHtml::forEditor($decoded) : $decoded;
        } else {
            $own = $listing->contentOverrides();
            $title = trim((string) ($own['title'] ?? ''));
            $description = (string) ($own['description'] ?? '');
        }

        if ($integration !== null && $storeId !== null && $listing !== null) {
            $description = self::wrap(
                $description,
                DescriptionTemplate::bodyOf(self::intOrNull($listing->description_prefix_id ?? null), $integration, $storeId),
                DescriptionTemplate::bodyOf(self::intOrNull($listing->description_suffix_id ?? null), $integration, $storeId)
            );
        }

        return ['title' => $title, 'description' => $description];
    }

    public static function rowTitles(iterable $rows, iterable $listings): array
    {
        $own = [];
        foreach ($listings as $listing) {
            if (! method_exists($listing, 'contentOverrides')) {
                continue;
            }
            $title = trim((string) ($listing->contentOverrides()['title'] ?? ''));
            if ($title !== '') {
                $own[(int) $listing->product_id] = $title;
            }
        }

        $out = [];
        foreach ($rows as $row) {
            $pid = (int) $row->product_id;
            $out[$pid] = $own[$pid] ?? CatalogCopy::title((string) ($row->name ?? ''));
        }

        return $out;
    }

    public static function wrapDescription(string $description, string $integration, int $storeId, ?object $listing): string
    {
        if ($listing === null || $storeId <= 0) {
            return $description;
        }

        return self::wrap(
            $description,
            DescriptionTemplate::bodyOf(self::intOrNull($listing->description_prefix_id ?? null), $integration, $storeId),
            DescriptionTemplate::bodyOf(self::intOrNull($listing->description_suffix_id ?? null), $integration, $storeId)
        );
    }

    private static function wrap(string $description, string $prefix, string $suffix): string
    {
        return implode("\n\n", array_filter([
            $prefix,
            trim($description),
            $suffix,
        ], fn ($part) => trim((string) $part) !== ''));
    }

    private static function intOrNull($value): ?int
    {
        return ($value === null || (int) $value <= 0) ? null : (int) $value;
    }

    public static function own(?string $submitted): ?string
    {
        $submitted = trim((string) $submitted);

        return $submitted === '' ? null : $submitted;
    }

    public static function descriptionToSave(?string $submitted, ?string $current, ?string $edited, bool $plainText = false): ?string
    {
        if ($edited === '0') {
            return $current;
        }

        if ($plainText) {
            $text = DescriptionText::of($submitted);
            if ($current !== null && $text === DescriptionText::of($current)) {
                return $current;
            }

            return $text === '' ? null : $text;
        }

        if (DescriptionText::isBlank($submitted)) {
            return null;
        }
        $clean = trim(DescriptionHtml::store($submitted));

        return $clean === '' ? null : $clean;
    }

    public static function editable(?string $own, string $core): string
    {
        return ($own !== null && trim($own) !== '') ? $own : $core;
    }
}
