@if(($canManage ?? false) && ! empty($fill['templates']))
    <section class="fm-section" aria-label="Fill from template">
        <div class="lt-fill">
            <x-ui.field label="Fill from template" for="lt-fill-pick" name="fill">
                <x-ui.select id="lt-fill-pick" name="fill" form="lt-fill-form">
                    @foreach($fill['templates'] as $option)
                        <option value="{{ $option['id'] }}" @selected($option['id'] === ($fill['chosen'] ?? null))>{{ $option['name'] }}</option>
                    @endforeach
                </x-ui.select>
            </x-ui.field>
            <x-ui.button type="submit" form="lt-fill-form" data-guard-leave>Fill</x-ui.button>
        </div>
        @if(($fill['name'] ?? null) !== null)
            <div class="fm-note fm-note--warn" role="status" @if($fill['filled'] !== []) data-guard-filled @endif>
                <div class="fm-note__body">
                    @if($fill['filled'] === [])
                        "{{ $fill['name'] }}" has nothing to fill.
                    @else
                        {{ \App\Channels\Listings\Templates\TemplateFill::sentence($fill['filled']) }} filled from "{{ $fill['name'] }}". Not saved yet.
                    @endif
                </div>
            </div>
        @endif
    </section>

    @push('forms')
        <form id="lt-fill-form" method="GET" action="{{ url()->current() }}">
            @if(\App\Support\BackTo::safe(request('back'), '') !== '')
                <input type="hidden" name="back" value="{{ \App\Support\BackTo::safe(request('back'), '') }}">
            @endif
        </form>
    @endpush
@endif
