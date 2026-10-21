@props(['order' => null, 'colspan' => 7])
@php $buyerNote = \App\Support\Fulfillment\BuyerNotes::of($order); @endphp
@if($buyerNote !== '')
    <tr class="co-note">
        <td colspan="{{ (int) $colspan }}"><span class="co-note__label">Buyer note</span> <span class="co-note__text">{{ $buyerNote }}</span></td>
    </tr>
@endif
