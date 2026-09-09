<?php

namespace App\Integrations\Listings;

/**
 * WHAT IS MISSING, AND WHERE TO GO AND FIX IT.
 *
 * A listing that cannot be pushed says what it still needs. It used to say
 * it like this:
 *
 *   "Before it can go to Shopee, this listing still needs a SKU on the
 *    catalog product before it can run. Set what is missing and save."
 *
 * the owner, 2026-09-09: "This message is a bit lost. Its not a warning. Its
 * not an error. Its a reminder that looks like it doesnt belong and it
 * doesnt really teach the user."
 *
 * He is right, and the reason is structural rather than a matter of
 * wording. Half the things it names are not on the page it is written on.
 * A category or a courier is set here, so naming it is useful; a SKU, a
 * name, a price, a parcel size all live on the CATALOG PRODUCT, and being
 * told to "save" a field that is not on screen teaches nothing. Those had
 * no link at all, so they read as an apology.
 *
 * So every gap is a link now. A gap this page can fix links to the field;
 * a gap the catalog owns links to the catalog product. And the sentence
 * says the one thing it is for - what is missing - and stops.
 */
final class GapLinks
{
    /**
     * Gaps the CATALOG product owns. A channel may claim one of these for
     * itself by giving it an anchor - Shopee's images card answers the
     * image gap on its own page, because a listing that arranges its own
     * pictures satisfies it there.
     */
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

    /**
     * The gaps as one readable, linked phrase.
     *
     * @param  list<array{code: string, label: string}>  $gaps
     * @param  array<string, string>  $anchors  gap code => an anchor on THIS page
     * @return string  HTML, already escaped
     */
    public static function words(array $gaps, int $productId, array $anchors = []): string
    {
        $catalogUrl = route('products.edit', $productId);

        $links = [];
        foreach ($gaps as $gap) {
            $code = (string) ($gap['code'] ?? '');
            $label = (string) ($gap['label'] ?? '');
            if ($label === '') {
                continue;
            }

            $href = $anchors[$code] ?? (in_array($code, self::CATALOG_OWNED, true) ? $catalogUrl : null);

            $links[] = $href !== null
                ? '<a href="' . e($href) . '">' . e($label) . '</a>'
                : e($label);
        }

        if ($links === []) {
            return '';
        }

        return count($links) > 1
            ? implode(', ', array_slice($links, 0, -1)) . ' and ' . end($links)
            : $links[0];
    }

    /**
     * The whole sentence. Two states, because they are two different
     * situations: a listing that has never gone up, and a live one whose
     * update would be refused.
     */
    public static function sentence(array $gaps, int $productId, string $channel, bool $isLive, array $anchors = []): string
    {
        $words = self::words($gaps, $productId, $anchors);
        if ($words === '') {
            return '';
        }

        return $isLive
            ? 'Push update needs ' . $words . '.'
            : 'Not ready for ' . e($channel) . ': it needs ' . $words . '.';
    }
}
