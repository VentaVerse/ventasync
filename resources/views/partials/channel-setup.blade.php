{{-- Setting up a store (the owner, 2026-09-05): the reads a store needs before
     anything can be pushed (categories, couriers), run one after another
     behind a progress popup the moment the store is authorised, and again
     from the Set up store button whenever they need refreshing. The page
     passes the steps as data; resources/js/pages/channel-setup.js runs them
     and draws the bar. Each step answers a plain sentence, never a payload.

     Rides .modal-backdrop's fixed scrim and .active show/hide, the mechanism
     every other dialog uses. $run is whether to start on load (set right
     after a successful authorisation). --}}
@php $run = $run ?? false; $storeName = $storeName ?? ''; @endphp
<div class="modal-backdrop cs-setup" data-channel-setup
     data-setup-steps="{{ json_encode(array_values($steps), JSON_UNESCAPED_SLASHES) }}"
     data-setup-run="{{ $run ? '1' : '0' }}">
    @csrf
    <div class="modal co-modal co-modal--sm cs-setup__sheet" role="dialog" aria-modal="true" aria-labelledby="cs-setup-title" aria-describedby="cs-setup-status">
        <h2 class="cs-setup__title" id="cs-setup-title">Setting up {{ $storeName ?: 'this store' }}</h2>
        <p class="cs-setup__sub">Reading from {{ $channelLabel }} what every push needs. Keep this tab open.</p>
        <div class="cs-setup__bar" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="0" data-setup-bar>
            <div class="cs-setup__fill" data-setup-fill></div>
        </div>
        <ol class="cs-setup__steps" data-setup-list>
            @foreach($steps as $step)
                <li class="cs-setup__step" data-state="waiting">
                    <span class="cs-setup__name">{{ $step['label'] }}</span>
                    <span class="cs-setup__state" data-step-state>Waiting</span>
                    <span class="cs-setup__note" data-step-note></span>
                </li>
            @endforeach
        </ol>
        <p class="cs-setup__status" id="cs-setup-status" role="status" aria-live="polite" data-setup-status></p>
        <div class="cs-setup__foot">
            <x-ui.button type="button" size="sm" variant="primary" data-setup-done hidden>Continue</x-ui.button>
        </div>
    </div>
</div>
