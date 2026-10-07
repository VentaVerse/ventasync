@extends('layouts.standalone')
@section('title', 'Lazada Authorisation')

{{-- The route name lazada.callback is registered with Lazada and has no auth middleware; keep both. --}}
@section('content')
@php
    $stateOk = isset($state_ok) && $state_ok;
    $wasSaved = isset($saved) && $saved;
    $tokenResult = is_array($token_result ?? null) ? $token_result : null;

    [$verdict, $verdictTone, $verdictNote] = match (true) {
        $wasSaved => [
            'Connected',
            'od-note--ok',
            'Lazada authorised this shop and the tokens are saved. There is nothing else to do here.',
        ],
        !$stateOk => [
            'Refused, the request did not match',
            'od-note--fail',
            'The state value Lazada sent back is not the one this application issued. Nothing was saved. Start the authorisation again from the Lazada settings screen rather than re-using an old link.',
        ],
        $tokenResult !== null => [
            'Lazada answered, but nothing was saved',
            'od-note--fail',
            'The token exchange ran and came back with something this application could not store. The exchange is below.',
        ],
        default => [
            'Nothing was exchanged',
            'od-note--warn',
            'No code came back, or the Lazada settings are incomplete, so no token exchange was attempted.',
        ],
    };
@endphp

<div class="od-page">

    <header class="od-head">
        <div class="od-head__id">
            <h1 class="x-page-title">Lazada authorisation</h1>
        </div>
    </header>

    <div class="od-note {{ $verdictTone }}">
        <div class="od-note__body">
            <strong>{{ $verdict }}.</strong> {{ $verdictNote }}
        </div>
    </div>

    @if(!empty($message))
        <div class="od-note">
            <div class="od-note__body">{{ $message }}</div>
        </div>
    @endif

    @if(!empty($save_error))
        <div class="od-note od-note--fail">
            <div class="od-note__body">
                Lazada's answer could not be saved: {{ $save_error }}
                <br>If that mentions a column that does not exist, the database is behind the code. Run <code>php artisan migrate --force</code> and authorise again.
            </div>
        </div>
    @endif

    <div class="od-body">
        <div class="od-col od-col--main">
            <section class="od-section">
                <div class="od-section__head">
                    <h2 class="od-section__title">What came back</h2>
                </div>

                <div class="od-card">
                    <div class="od-card__body">
                        <div class="od-fact">
                            <span class="od-fact__k">Authorisation code</span>
                            <span class="od-fact__v od-fact__v--mono">{{ $code ?? '' ?: 'None sent' }}</span>
                        </div>
                        <div class="od-fact">
                            <span class="od-fact__k">State</span>
                            <span class="od-fact__v od-fact__v--mono">{{ $state ?? '' ?: 'None sent' }}</span>
                        </div>
                        <div class="od-fact">
                            <span class="od-fact__k">State matched what we issued</span>
                            <span class="od-fact__v">{{ $stateOk ? 'Yes' : 'No' }}</span>
                        </div>
                        <div class="od-fact">
                            <span class="od-fact__k">Tokens saved</span>
                            <span class="od-fact__v">{{ $wasSaved ? 'Yes' : 'No' }}</span>
                        </div>
                    </div>
                </div>
            </section>

            <section class="od-section">
                <div class="od-section__head">
                    <h2 class="od-section__title">If something went wrong</h2>
                </div>

                <details class="od-disclose" @if(!$wasSaved) open @endif>
                    <summary>The token exchange</summary>
                    @if($tokenResult !== null)
                        <pre class="cl-json">{{ json_encode($tokenResult, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</pre>
                    @else
                        <p class="fm-section__note">No exchange was attempted, so there is nothing to show. That happens when no code came back, when the state did not match, or when the Lazada settings are incomplete.</p>
                    @endif
                </details>

                <details class="od-disclose">
                    <summary>Everything Lazada put in the address</summary>
                    <pre class="cl-json">{{ json_encode($query ?? [], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</pre>
                </details>
            </section>
        </div>

        <div class="od-col">
            <div class="od-card">
                <div class="od-card__head"><h2 class="od-card__title">Next</h2></div>
                <div class="od-card__body">
                    <p class="fm-section__note">
                        @if($wasSaved)
                            Lazada's access token lasts about two days and is refreshed automatically. You only need to come back here if the settings screen says the connection has lapsed.
                        @else
                            Start again from the Lazada settings screen. Authorisation links are single use, so re-opening this address will not help.
                        @endif
                    </p>
                    <div class="od-form__actions">
                        <x-ui.button variant="primary" :href="!empty($store) ? route('ext.lazada.index', ['store' => $store->id]) : route('ext.lazada.dashboard')">Go to Lazada settings</x-ui.button>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
@endsection
