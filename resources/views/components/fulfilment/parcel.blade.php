{{--
    The parcel: what is in the box, how many, who carries it, where it goes.

    Shown in every channel's ship dialog since 2026-08-29. It began as the
    Lazada Packed screen's own block, added so a packer could check the
    parcel against the box in their hand (clicking the row above yours had
    produced the same screen as clicking yours). The owner's call: the other
    three channels get the same check, one partial, so a packer reads the
    box the same way on Shopee, TikTok and Venta as on Lazada.

    The list is a check, not a selector: no channel packs part of an order
    from here, so nothing in it is a control.

    Props:
      parcel  array|null  App\Support\Fulfilment\PackedParcel shape: items
                          (name, sku, variation, quantity, image), unit_count,
                          line_count, courier, destination. Null renders
                          nothing, so a dialog opened without a parcel on
                          hand (an order detail page) falls back to its
                          order number alone rather than to a false parcel.
--}}
@props(['parcel' => null])

@if(!empty($parcel) && !empty($parcel['items']))
    <div class="co-parcel">
        <ul class="co-parcel__items">
            @foreach($parcel['items'] as $item)
                <li class="co-parcel__item">
                    <div class="order-img-wrap">
                        @if(!empty($item['image']))
                            <img class="order-img" src="{{ $item['image'] }}" alt="">
                        @else
                            <span class="co-item__none">No image</span>
                        @endif
                    </div>
                    <div class="co-parcel__body">
                        <span class="co-parcel__name">{{ ($item['name'] ?? '') !== '' ? $item['name'] : 'Unnamed product' }}</span>
                        <div class="co-item__meta">
                            @if(trim((string) ($item['sku'] ?? '')) !== '')
                                <span class="co-item__sku">{{ $item['sku'] }}</span>
                            @endif
                            <x-fulfilment.variation :sku="$item['sku'] ?? null" :fallback="$item['variation'] ?? null" />
                        </div>
                    </div>
                    {{-- The count is the error a packer makes, so it is the
                         loudest figure here, not the quietest. --}}
                    <span class="co-parcel__qty">&times;{{ (int) ($item['quantity'] ?? 1) }}</span>
                </li>
            @endforeach
        </ul>

        <dl class="co-parcel__facts">
            <div class="co-parcel__fact">
                <dt>Units</dt>
                <dd>{{ $parcel['unit_count'] }} in {{ $parcel['line_count'] }} {{ $parcel['line_count'] === 1 ? 'line' : 'lines' }}</dd>
            </div>
            <div class="co-parcel__fact">
                <dt>Courier</dt>
                <dd>
                    @if(!empty($parcel['courier']))
                        {{ $parcel['courier'] }}
                    @else
                        <span class="co-empty-mark">Not assigned yet</span>
                    @endif
                </dd>
            </div>
            @if(!empty($parcel['destination']))
                <div class="co-parcel__fact">
                    <dt>To</dt>
                    <dd>{{ $parcel['destination'] }}</dd>
                </div>
            @endif
        </dl>
    </div>
@endif
