{{-- The Add-store modal on the Channels board (the owner, 2026-09-04; the page was renamed from Integrations on 2026-09-06).
     Pick a channel, answer the fewest questions - a name always, plus
     whatever that channel's row cannot exist without - and a store is
     created; credentials are filled on its settings page afterwards. The
     channel list and each channel's fields come from the cards'
     AddStoreAction, so a channel appears here the moment it declares one.

     Logos live in public/images/channels/{id}.png (tracked). Until one is
     uploaded the tile shows the channel's initial; channel-add.js hides a
     broken image so no broken-image glyph ever shows. --}}
<div class="modal-backdrop" id="add-store-modal" data-add-store-modal>
    <div class="modal cs-addmodal" role="dialog" aria-modal="true" aria-labelledby="add-store-title">
        <div class="cs-addmodal__head">
            <h2 class="cs-addmodal__title" id="add-store-title">Add a store</h2>
            <button type="button" class="cc-iconbtn" data-add-store-close aria-label="Close">
                <x-ui.icon name="x" size="16" />
            </button>
        </div>

        {{-- Step 1: which channel. --}}
        <div class="cs-addmodal__body" data-add-step="channel">
            <p class="cs-addmodal__lead">Which channel is this store on?</p>
            <div class="cs-chgrid">
                @foreach($addable as $c)
                    <button type="button" class="cs-chtile" data-add-pick="{{ $c['id'] }}">
                        <span class="ch-logo">
                            <span class="ch-logo__ltr">{{ mb_substr($c['name'], 0, 1) }}</span>
                            <img class="ch-logo__img" src="{{ asset('images/channels/' . $c['id'] . '.png') }}" alt="" data-ch-logo>
                        </span>
                        <span class="cs-chtile__name">{{ $c['name'] }}</span>
                    </button>
                @endforeach
            </div>
        </div>

        {{-- Step 2: the fewest questions, one form per channel, revealed by
             the pick above. --}}
        @foreach($addable as $c)
            <form method="POST" action="{{ $c['route'] }}" class="cs-addmodal__body" data-add-form="{{ $c['id'] }}" data-slow-action hidden>
                @csrf
                <button type="button" class="cs-addmodal__back" data-add-back>
                    <x-ui.icon name="chevron-left" size="14" /> Choose a different channel
                </button>

                <div class="cs-addform__head">
                    <span class="ch-logo ch-logo--sm">
                        <span class="ch-logo__ltr">{{ mb_substr($c['name'], 0, 1) }}</span>
                        <img class="ch-logo__img" src="{{ asset('images/channels/' . $c['id'] . '.png') }}" alt="" data-ch-logo>
                    </span>
                    <span class="cs-addform__name">New {{ $c['name'] }} store</span>
                </div>

                <x-ui.field label="Store name" name="store_name" :required="true"
                            hint="Your own name for this connection - how you and the reports will recognise it.">
                    <x-ui.input name="store_name" value="{{ old('store_name') }}" placeholder="e.g. Gearshipper" required />
                </x-ui.field>

                @foreach($c['fields'] as $field)
                    <x-ui.field label="{{ $field['label'] }}" name="{{ $field['name'] }}" :required="true"
                                :hint="$field['hint'] ?? null">
                        <x-ui.input type="{{ $field['type'] ?? 'text' }}" name="{{ $field['name'] }}"
                                    value="{{ old($field['name']) }}"
                                    placeholder="{{ $field['placeholder'] ?? '' }}" required />
                    </x-ui.field>
                @endforeach

                <div class="cs-addmodal__foot">
                    <button type="button" class="fm-cancel" data-add-store-close>Cancel</button>
                    <x-ui.button type="submit" variant="primary">Create store</x-ui.button>
                </div>
            </form>
        @endforeach
    </div>
</div>
