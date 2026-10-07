@php
    $pid = (int) $listing->product_id;
    $cols = $listing::copyColumns();
    $catalog = \App\Integrations\Listings\CatalogCopy::catalog([$pid])[$pid] ?? null;
    $own = $catalog !== null ? \App\Integrations\Listings\CatalogCopy::listing($listing, $cols, $catalog) : null;
    $canCatalog = (bool) (auth()->user()?->hasPermission('manage_catalog/product') ?? false);
    $hasVariations = \Illuminate\Support\Facades\DB::table((string) config('catalog.prefix') . 'product_option_value')->where('product_id', $pid)->exists();
    $panelErrors = collect($errors->keys())->contains(fn ($k) => str_starts_with($k, 'listing.') || str_starts_with($k, 'catalog.'));
    $openNow = request()->boolean('compare') || $panelErrors;
    $photosOf = function (string $side, array $stored) {
        $decoded = json_decode((string) old($side . '.photos', json_encode($stored)), true);

        return is_array($decoded) ? \App\Support\Catalog\ProductImages::acceptable($decoded) : $stored;
    };
    $sides = $catalog === null ? [] : [
        'catalog' => ['label' => 'Catalog product', 'values' => $catalog, 'photos' => $photosOf('catalog', $catalog['photos']), 'can' => $canCatalog],
        'listing' => ['label' => 'This listing', 'values' => $own, 'photos' => $photosOf('listing', $own['photos']), 'can' => (bool) $canManage],
    ];
    $num = fn ($v, int $places) => rtrim(rtrim(number_format((float) $v, $places, '.', ''), '0'), '.');
    $ownNum = fn ($v, int $places) => ($v === null || $v === '') ? '' : $num($v, $places);
@endphp
@if($catalog !== null)
<div class="modal-backdrop cc-addpanel lcx-panel{{ $openNow ? ' active' : '' }}" id="lcx-panel" data-lcx-panel>
    <div class="cc-addpanel__sheet" role="dialog" aria-modal="true" aria-labelledby="lcx-title">
        <div class="cc-addpanel__head">
            <h2 class="cc-addpanel__title" id="lcx-title">Catalog change</h2>
            <button type="button" class="cc-addpanel__close" data-lcx-close aria-label="Close"><x-ui.icon name="x" size="16" /></button>
        </div>

        <form method="POST" action="{{ $saveUrl }}" class="lcx-form" data-lcx-form>
            @csrf
            <input type="hidden" name="back" value="{{ $back }}">

            <div class="lcx-cols" aria-hidden="true">
                <span class="lcx-col"><b>Catalog product</b>{{ $pid }}</span>
                <span class="lcx-col"><b>This listing</b>{{ $storeLabel }}</span>
            </div>

            <div class="lcx-list">
                <div class="lcx-f">
                    <span class="fm-label" id="lcx-k-title">Title</span>
                    <div class="lcx-pair">
                        @foreach($sides as $side => $s)
                            <div class="lcx-cell">
                                <span class="lcx-side">{{ $s['label'] }}</span>
                                <input type="text" class="x-input" name="{{ $side }}[title]" id="lcx-{{ $side }}-title" maxlength="255"
                                       value="{{ old($side . '.title', $s['values']['title']) }}"
                                       aria-labelledby="lcx-k-title" aria-describedby="lcx-{{ $side }}-title-side"
                                       @if($side === 'catalog') data-lcx-catalog @else required @endif @disabled(!$s['can'])>
                                <span class="x-sr" id="lcx-{{ $side }}-title-side">{{ $s['label'] }}</span>
                                @error($side . '.title')<div class="fm-error">{{ $message }}</div>@enderror
                            </div>
                        @endforeach
                    </div>
                </div>

                <div class="lcx-f">
                    <span class="fm-label">Description</span>
                    <div class="lcx-pair">
                        @foreach($sides as $side => $s)
                            <div class="lcx-cell">
                                <label class="lcx-side lcx-side--label" for="lcx-{{ $side }}-description">{{ $s['label'] }} description</label>
                                <textarea class="x-input wysiwyg" name="{{ $side }}[description]" id="lcx-{{ $side }}-description" rows="8"
                                          @if($side === 'catalog') data-lcx-catalog @endif @disabled(!$s['can'])>{{ old($side . '.description', $s['values']['description']) }}</textarea>
                                <input type="hidden" name="{{ $side }}[description_edited]" value="{{ old($side . '.description_edited', '0') }}" data-rte-edited="lcx-{{ $side }}-description" @disabled(!$s['can'])>
                                @error($side . '.description')<div class="fm-error">{{ $message }}</div>@enderror
                            </div>
                        @endforeach
                    </div>
                </div>

                <div class="lcx-f">
                    <span class="fm-label">Photos</span>
                    <div class="lcx-pair">
                        @foreach($sides as $side => $s)
                            <div class="lcx-cell">
                                <span class="lcx-side">{{ $s['label'] }}</span>
                                <div class="lcx-tiles" data-lcx-tiles data-lcx-input="lcx-{{ $side }}-photos" role="list" aria-label="{{ $s['label'] }} photos">
                                    @foreach($s['photos'] as $i => $path)
                                        <span class="lcx-tile" data-path="{{ $path }}" role="listitem">
                                            <img src="{{ \App\Support\Catalog\ProductImages::url($path) }}" alt="">
                                            <span class="lcx-tile__n">{{ $i + 1 }}</span>
                                            @if($s['can'])
                                                <button type="button" class="lcx-tile__x" data-lcx-drop aria-label="Remove photo {{ $i + 1 }}"><x-ui.icon name="x" size="12" /></button>
                                            @endif
                                        </span>
                                    @endforeach
                                    @if($s['can'])
                                        <button type="button" class="lcx-add" data-lcx-add aria-label="Add a photo from the media library"><x-ui.icon name="plus" size="18" /></button>
                                    @endif
                                </div>
                                <input type="hidden" name="{{ $side }}[photos]" id="lcx-{{ $side }}-photos" value="{{ json_encode($s['photos']) }}"
                                       @if($side === 'catalog') data-lcx-catalog @endif @disabled(!$s['can'])>
                            </div>
                        @endforeach
                    </div>
                </div>

                @unless($hasVariations)
                    <div class="lcx-f">
                        <span class="fm-label" id="lcx-k-price">Price</span>
                        <div class="lcx-pair">
                            @foreach($sides as $side => $s)
                                <div class="lcx-cell">
                                    <span class="lcx-side">{{ $s['label'] }}</span>
                                    <input type="number" class="x-input fm-input--num" name="{{ $side }}[price]" id="lcx-{{ $side }}-price" step="0.01" min="0"
                                           value="{{ old($side . '.price', $side === 'listing' ? $ownNum($listing->price, 2) : $num($s['values']['price'], 2)) }}" aria-labelledby="lcx-k-price"
                                           @if($side === 'catalog') data-lcx-catalog @else placeholder="{{ $num($catalog['price'], 2) }}" @endif @disabled(!$s['can'])>
                                    @error($side . '.price')<div class="fm-error">{{ $message }}</div>@enderror
                                </div>
                            @endforeach
                        </div>
                    </div>
                @endunless

                <div class="lcx-f">
                    <span class="fm-label">Weight and size</span>
                    <div class="lcx-pair">
                        @foreach($sides as $side => $s)
                            <div class="lcx-cell">
                                <span class="lcx-side">{{ $s['label'] }}</span>
                                <div class="lcx-parcel">
                                    @foreach(['weight' => 'Weight kg', 'length' => 'Length cm', 'width' => 'Width cm', 'height' => 'Height cm'] as $key => $label)
                                        @php
                                            $places = $key === 'weight' ? 3 : 2;
                                            $mine = $side === 'listing';
                                        @endphp
                                        <div class="lcx-parcel__f">
                                            <label class="lcx-mini" for="lcx-{{ $side }}-{{ $key }}">{{ $label }}</label>
                                            <input type="number" class="x-input fm-input--num" name="{{ $side }}[{{ $key }}]" id="lcx-{{ $side }}-{{ $key }}"
                                                   step="{{ $key === 'weight' ? '0.001' : '0.01' }}"
                                                   min="{{ $mine ? ($key === 'weight' ? '0.001' : '0.01') : '0' }}"
                                                   value="{{ old($side . '.' . $key, $mine ? $ownNum(((float) $listing->{\App\Integrations\Listings\CatalogCopy::PARCEL[$key]}) > 0 ? $listing->{\App\Integrations\Listings\CatalogCopy::PARCEL[$key]} : null, $places) : $num($s['values']['parcel'][$key], $places)) }}"
                                                   @if($mine) placeholder="{{ $num($catalog['parcel'][$key], $places) }}" @else data-lcx-catalog @endif @disabled(!$s['can'])>
                                        </div>
                                    @endforeach
                                </div>
                                @foreach(['weight', 'length', 'width', 'height'] as $key)
                                    @error($side . '.' . $key)<div class="fm-error">{{ $message }}</div>@enderror
                                @endforeach
                            </div>
                        @endforeach
                    </div>
                </div>
            </div>

            @if($canManage)
                <div class="cc-addpanel__foot lcx-foot">
                    <button type="submit" class="x-btn x-btn--secondary x-btn--sm" formaction="{{ $ignoreUrl }}" formnovalidate>Ignore</button>
                    <button type="submit" class="x-btn x-btn--primary x-btn--sm" data-lcx-save>Save</button>
                </div>
            @endif
        </form>

        <template data-lcx-tile>
            <span class="lcx-tile" data-path="" role="listitem">
                <img src="" alt="">
                <span class="lcx-tile__n"></span>
                <button type="button" class="lcx-tile__x" data-lcx-drop aria-label="Remove photo"><x-ui.icon name="x" size="12" /></button>
            </span>
        </template>
    </div>
</div>
@endif
