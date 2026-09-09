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
 * So the sentence names what is missing and nothing else, and the way out
 * is a BUTTON, not a link inside the prose. The owner again, on the first
 * attempt: "It is clickable. But it doesnt look like a button... Create a
 * dedicated button that screams click me." A control has weight and shape;
 * an underlined phrase in a sentence has neither, and half of what the
 * sentence names is not clickable anyway, so nothing tells you which words
 * are the door.
 *
 * One button, because there is one place to go: the catalog product when
 * the catalog owns any of the gaps, or the field on this page when they
 * are all here.
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
     * What is missing, as plain words. No links: the button below is the
     * way out, and an underlined phrase mid-sentence reads as prose.
     *
     * @param  list<array{code: string, label: string}>  $gaps
     */
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

    /**
     * The whole sentence. Two states, because they are two different
     * situations: a listing that has never gone up, and a live one whose
     * update would be refused.
     */
    public static function sentence(array $gaps, string $channel, bool $isLive): string
    {
        $words = self::words($gaps);
        if ($words === '') {
            return '';
        }

        return $isLive
            ? 'Push update needs ' . $words . '.'
            : 'Not ready for ' . $channel . ': it needs ' . $words . '.';
    }

    /**
     * The one way out, as a button.
     *
     * The catalog product wins when it owns ANY of the gaps, because that
     * is the page this one cannot reach. When every gap is on this page
     * the button jumps to the first of them.
     *
     * @param  list<array{code: string, label: string}>  $gaps
     * @param  array<string, string>  $anchors  gap code => an anchor on THIS page
     * @return array{href: string, label: string}|null
     */
    public static function action(array $gaps, int $productId, array $anchors = []): ?array
    {
        $onPage = null;
        foreach ($gaps as $gap) {
            $code = (string) ($gap['code'] ?? '');
            if (in_array($code, self::CATALOG_OWNED, true) && ! isset($anchors[$code])) {
                return ['href' => route('products.edit', $productId), 'label' => 'Open the catalog product'];
            }
            $onPage = $onPage ?? ($anchors[$code] ?? null);
        }

        return $onPage !== null ? ['href' => $onPage, 'label' => 'Go to what is missing'] : null;
    }
}
