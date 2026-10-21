@php
    $rows = $variationRows ?? [];
    $extras = $variationExtras ?? [];
    $stateKnown = collect($rows)->contains(fn ($r) => $r['onStore'] !== null) || $extras !== [];
@endphp
@if($rows !== [] || $extras !== [])
    <section class="fm-section" id="lv-variations">
        <div class="fm-section__head">
            <h2 class="fm-section__title">{{ $title }}</h2>
        </div>
        <input type="hidden" name="variations_listed" value="1">
        <x-ui.table caption="{{ $title }}">
            <thead>
                <tr>
                    <th>Variation</th>
                    <th>SKU</th>
                    @if($stateKnown)
                        <th>On {{ $storeName }}</th>
                    @endif
                    <th class="x-num">Master Catalog price</th>
                    <th class="x-num">Price on {{ $storeName }}</th>
                    <th>Sell here</th>
                </tr>
            </thead>
            <tbody>
                @foreach($rows as $i => $r)
                    <tr>
                        <td>{{ $r['name'] }}</td>
                        <td>{{ $r['sku'] !== '' ? $r['sku'] : 'none' }}</td>
                        @if($stateKnown)
                            <td>
                                @if($r['onStore'] === true)
                                    <x-ui.badge tone="success">Listed</x-ui.badge>
                                @elseif($r['onStore'] === false)
                                    <x-ui.badge tone="neutral">Not listed</x-ui.badge>
                                @endif
                            </td>
                        @endif
                        <td class="x-num">{{ $r['catalogPrice'] !== null ? \App\Support\Money::base($r['catalogPrice']) : '' }}</td>
                        <td>
                            @if($r['sku'] !== '')
                                <input type="hidden" name="variation_prices[{{ $i }}][sku]" value="{{ $r['sku'] }}">
                                <input class="x-input fm-num lv-price" type="number" step="0.01" min="0"
                                       name="variation_prices[{{ $i }}][price]"
                                       value="{{ old('variation_prices.' . $i . '.price', $r['price'] !== null ? number_format($r['price'], 2, '.', '') : '') }}"
                                       placeholder="{{ $r['catalogPrice'] !== null ? number_format($r['catalogPrice'], 2, '.', '') : '' }}"
                                       aria-label="Price of {{ $r['name'] }} on {{ $storeName }}"
                                       @disabled(! ($canManage ?? false))>
                            @endif
                        </td>
                        <td>
                            @if($r['sku'] !== '')
                                <label class="fm-switch fm-switch--sm">
                                    <input type="checkbox" class="fm-switch__input" id="lv-sell-{{ $i }}"
                                           name="variations_on[]" value="{{ $r['sku'] }}"
                                           aria-label="Sell {{ $r['name'] }} on {{ $storeName }}"
                                           @checked($r['on']) @disabled(! ($canManage ?? false))>
                                    <span class="fm-switch__track"></span>
                                </label>
                            @endif
                        </td>
                    </tr>
                @endforeach
                @foreach($extras as $sku)
                    <tr>
                        <td>Not in your Master Catalog</td>
                        <td>{{ $sku }}</td>
                        <td><x-ui.badge tone="warning">Listed</x-ui.badge></td>
                        <td></td>
                        <td></td>
                        <td></td>
                    </tr>
                @endforeach
            </tbody>
        </x-ui.table>
    </section>
@endif
