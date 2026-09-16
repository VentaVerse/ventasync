{{-- A product's readiness as a full-width amber line directly above its row,
     under the red error line when both apply, on every channel's Listings
     page and group Products page. A ready product draws nothing, so the line
     marks exactly the rows the status menu's Needs details lists. The words
     come from ListingState, the same answer every push path checks.

     The sentence names what is missing; where it is missing FROM decides
     whether the line carries a way out. A gap the catalog owns (a weight, an
     SKU, a name) is fixed on the catalog product, which is a page this row
     cannot reach, so the line ends in a link to it. A gap the listing owns (a
     category, its couriers) is fixed on the listing page the product's own
     name already opens, so the line stays words: a second link to a place
     the row already leads teaches nothing.

     $state      ?App\Integrations\Listings\ListingState  the row's state, or null when unchecked
     $cols       int   the number of columns the table renders
     $productId  ?int  the catalog product, when the page can name it --}}
@php
    $rs = $state ?? null;
    $summary = $rs !== null && ! $rs->ready ? trim($rs->missingSummary()) : '';
    /* The way out, decided here so the row below stays one plain line: a gap
       the catalog owns answers with the catalog product, a gap this page owns
       answers with an anchor we do not pass, so it stays words. */
    $fix = ($rs !== null && ! $rs->ready && (int) ($productId ?? 0) > 0)
        ? \App\Integrations\Listings\GapLinks::action($rs->missing, (int) $productId)
        : null;
    $fixHref = ($fix !== null && ! $fix['onPage']) ? $fix['href'] : null;
@endphp
@if($rs !== null && ! $rs->ready)
    <tr class="cc-err-row cc-err-row--warn">
        <td class="cc-err-row__cell" colspan="{{ max(1, (int) ($cols ?? 1)) }}">
            <span class="cc-warn-lead">Needs details{{ $summary !== '' ? ':' : '' }}</span>
            {{ $summary }}
            @if($fixHref !== null)
                <a class="cc-warn-fix" href="{{ $fixHref }}">Fix in the catalog</a>
            @endif
        </td>
    </tr>
@endif
