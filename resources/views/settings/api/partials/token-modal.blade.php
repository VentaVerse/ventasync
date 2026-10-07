@php
    $flash = $flash ?? null;
    $flashName = $flashName ?? null;
@endphp

<div class="modal-backdrop" id="st-token-modal" data-token-modal @if($flash) data-token-open @endif>
    <div class="modal st-reveal" role="dialog" aria-modal="true" aria-labelledby="st-token-modal-title">
        <div class="modal-header">
            <h2 id="st-token-modal-title" data-token-title>{{ $flashName ? 'Token for ' . $flashName : 'Token' }}</h2>
            <button class="modal-close" type="button" data-token-close aria-label="Close">&times;</button>
        </div>

        <p class="st-reveal__status" data-token-status role="status" @if($flash) hidden @endif></p>

        <div class="st-reveal__row" data-token-row @unless($flash) hidden @endunless>
            <input class="x-input st-reveal__value" id="st-token-value" type="text" readonly
                   autocomplete="off" spellcheck="false" data-select-on-focus
                   aria-label="API token" value="{{ $flash }}" @if($flash) data-token-flash @endif>
            <x-ui.button type="button" data-copy-target="st-token-value">Copy</x-ui.button>
        </div>

        <div class="st-reveal__foot">
            <x-ui.button type="button" variant="secondary" data-token-close>Done</x-ui.button>
        </div>
    </div>
</div>
