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
