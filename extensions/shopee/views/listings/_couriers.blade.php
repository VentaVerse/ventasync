<div class="fm-sub" id="{{ $id }}" data-couriers>
    <div class="sc-couriers__head">
        <h3 class="fm-sub__title">Couriers @if($required)<span class="fm-req" aria-hidden="true">*</span><span class="x-sr">(required)</span> <x-ui.hint label="About the couriers">Which of Shopee's couriers this listing is offered on. Pick at least one.</x-ui.hint>@endif</h3>
        @if($refreshUrl)
            <x-ui.button type="button" size="sm" data-list-refresh="{{ $refreshUrl }}">Refresh</x-ui.button>
            <span class="list-refresh__say" role="status" aria-live="polite" data-list-refresh-say></span>
        @endif
    </div>
    @if($errors->has('logistic_ids'))
        <div class="fm-error">{{ $errors->first('logistic_ids') }}</div>
    @endif
    <div class="gf-checks" data-courier-list @if($required) data-must-pick="Pick at least one courier." @endif @if(empty($logistics)) hidden @endif>
        @foreach($logistics as $l)
            <label class="gf-check">
                <input type="checkbox" class="cc-check" name="logistic_ids[]" value="{{ $l['logistic_id'] }}"
                       @checked(in_array((int) $l['logistic_id'], $selected, true)) @disabled(! $canManage)>
                <span class="gf-check__name">{{ $l['logistic_name'] }}</span>
                <span class="gf-check__id">{{ $l['logistic_id'] }}</span>
                @unless($l['enabled'])
                    <span class="gf-check__off">Off in Seller Centre</span>
                @endunless
            </label>
        @endforeach
    </div>
    <p class="x-empty__title" data-courier-empty @unless(empty($logistics)) hidden @endunless>No couriers yet</p>
    <template data-courier-row>
        <label class="gf-check">
            <input type="checkbox" class="cc-check" name="logistic_ids[]" @disabled(! $canManage)>
            <span class="gf-check__name"></span>
            <span class="gf-check__id"></span>
            <span class="gf-check__off">Off in Seller Centre</span>
        </label>
    </template>
</div>
