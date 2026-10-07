@php
    $error = trim((string) ($error ?? ''));
@endphp
@if($error !== '')
    <tr class="cc-err-row">
        <td class="cc-err-row__cell" colspan="{{ max(1, (int) ($cols ?? 1)) }}"><span class="x-sr">Error: </span>{{ $error }}</td>
    </tr>
@endif
