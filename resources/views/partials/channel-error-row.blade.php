{{-- A product's error as a full-width line directly above its row, on every
     channel's Listings page and group Products page. Nothing is drawn when
     the product carries no error.

     $error  ?string  the message, or null when the row is healthy
     $cols   int      the number of columns the table renders --}}
@php
    $error = trim((string) ($error ?? ''));
@endphp
@if($error !== '')
    <tr class="cc-err-row">
        <td class="cc-err-row__cell" colspan="{{ max(1, (int) ($cols ?? 1)) }}"><span class="x-sr">Error: </span>{{ $error }}</td>
    </tr>
@endif
