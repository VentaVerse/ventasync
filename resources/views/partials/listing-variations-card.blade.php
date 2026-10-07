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
                    </tr>
                @endforeach
            </tbody>
        </x-ui.table>
    </section>
@endif
