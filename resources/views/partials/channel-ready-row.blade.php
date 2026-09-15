{{-- A product's readiness as a full-width amber line directly above its row,
     under the red error line when both apply, on every channel's Listings
     page and group Products page. A ready product draws nothing, so the line
     marks exactly the rows the status menu's Needs details lists. The words
     come from ListingState, the same answer every push path checks.

     $state  ?App\Integrations\Listings\ListingState  the row's state, or null when unchecked
     $cols   int  the number of columns the table renders --}}
@php
    $rs = $state ?? null;
    $summary = $rs !== null && ! $rs->ready ? trim($rs->missingSummary()) : '';
@endphp
@if($rs !== null && ! $rs->ready)
    <tr class="cc-err-row cc-err-row--warn">
        <td class="cc-err-row__cell" colspan="{{ max(1, (int) ($cols ?? 1)) }}"><span class="cc-warn-lead">Needs details{{ $summary !== '' ? ':' : '' }}</span>@if($summary !== '') {{ $summary }}@endif</td>
    </tr>
@endif
