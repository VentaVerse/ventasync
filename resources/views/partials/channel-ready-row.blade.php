@php
    $rs = $state ?? null;
    $summary = $rs !== null && ! $rs->ready ? trim($rs->missingSummary()) : '';
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
