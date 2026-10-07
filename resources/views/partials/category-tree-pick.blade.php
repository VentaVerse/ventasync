@php
    $ctpValue = (int) ($value ?? 0);
    $ctpRequired = (bool) ($required ?? false);

    $ctpPath = $ctpValue > 0 && ! empty($channel)
        ? \App\Integrations\Listings\CategoryTree::path($channel, (int) ($storeId ?? 0), $ctpValue)
        : [];
    $ctpName = (string) ($valueLabel ?? '') !== ''
        ? (string) $valueLabel
        : implode(' / ', array_column($ctpPath, 'name'));

    $ctpRoots = ! empty($channel)
        ? \App\Integrations\Listings\CategoryTree::children($channel, (int) ($storeId ?? 0))
        : [];
    $ctpChosenRoot = (int) ($ctpPath[0]['id'] ?? 0);
@endphp
<x-ui.field :label="$label" :for="$id" :name="$name" :required="$ctpRequired" wide>
    <div class="ctp" data-category-pick
         @if($ctpRequired) data-combo-required="{{ $requiredWord ?? 'Pick a category from the list.' }}" @endif
         data-children-url="{{ $childrenUrl }}"
         data-path-url="{{ $pathUrl }}"
         @if(! empty($searchUrl)) data-search-url="{{ $searchUrl }}" @endif
         @if($ctpValue > 0) data-chosen="{{ $ctpValue }}" @endif>

        <input type="hidden" id="{{ $id }}-value" name="{{ $name }}" data-ctp-value data-combo-value
               value="{{ $ctpValue > 0 ? $ctpValue : '' }}">

        <button type="button" class="ctp__field" id="{{ $id }}" data-ctp-open @disabled(!$canManage)>
            <span class="ctp__field-text{{ $ctpName === '' ? ' ctp__field-text--none' : '' }}" data-ctp-field-text>{{ $ctpName !== '' ? $ctpName : 'No category picked' }}</span>
            <x-ui.icon name="pencil" size="14" class="ctp__pencil" />
        </button>

        <p class="ctp__say" role="status" aria-live="polite" data-ctp-say hidden></p>

        <div class="modal-backdrop ctp-modal" data-ctp-modal>
            <div class="fm-pick" role="dialog" aria-modal="true" aria-labelledby="{{ $id }}-pick-title">
                <div class="fm-pick__head">
                    <h3 class="fm-pick__title" id="{{ $id }}-pick-title">{{ $label }}</h3>
                    <span class="fm-pick__msg" role="status" aria-live="polite" data-ctp-msg></span>
                    <div class="fm-pick__actions">
                        @unless($ctpRequired)
                            <x-ui.button type="button" size="sm" data-ctp-clear>Clear</x-ui.button>
                        @endunless
                        @if(! empty($refreshUrl))
                            <x-ui.button type="button" size="sm" data-ctp-refresh
                                         data-refresh-url="{{ $refreshUrl }}">Refresh</x-ui.button>
                        @endif
                        <x-ui.button type="button" size="sm" data-ctp-cancel>Cancel</x-ui.button>
                        <x-ui.button type="button" size="sm" variant="primary" data-ctp-confirm disabled>Confirm</x-ui.button>
                    </div>
                </div>
                <div class="fm-pick__body">
                    <div class="fm-pick__tools">
                        <input type="search" class="x-input ctp__search" data-ctp-search
                               placeholder="Search categories" aria-label="Search categories">
                        <span class="fm-pick__gap"></span>
                        <span class="ctp__picked" data-ctp-picked></span>
                    </div>

                    <div class="ctp__cols" data-ctp-cols>
                        <div class="ctp__col">
                            <div class="ctp__col-head">All categories</div>
                            <div class="ctp__list">
                                @forelse($ctpRoots as $ctpRoot)
                                    <button type="button" class="ctp__row{{ $ctpChosenRoot === $ctpRoot['id'] ? ' is-on' : '' }}"
                                            @if($ctpChosenRoot === $ctpRoot['id']) aria-current="true" @endif>
                                        <span class="ctp__row-name">{{ $ctpRoot['name'] }}</span>
                                        <span class="ctp__row-mark" aria-hidden="true">{{ $ctpRoot['leaf'] ? '' : '›' }}</span>
                                    </button>
                                @empty
                                    <p class="ctp__none">No categories yet.</p>
                                @endforelse
                            </div>
                        </div>
                    </div>

                    <div class="ctp__results" data-ctp-results role="listbox" aria-label="Matching categories" hidden></div>
                </div>
            </div>
        </div>
    </div>
</x-ui.field>
