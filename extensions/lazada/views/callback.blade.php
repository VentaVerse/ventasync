@extends('layouts.standalone')
@section('title', 'Lazada Authorisation')

{{-- Round F Task 1 finding: this is NOT a bare redirect either, and it is a
     different kind of page from Shopee's.

     Lazada's callback does the token exchange server side, so by the time the
     operator's browser lands here it has either worked or it has not. The page
     is the record of that: what came back, whether the state matched, whether
     it was saved, and the raw exchange for when it went wrong. It has no form,
     no script and no CSRF, and it never had any. That function is untouched:
     the same seven controller variables read the same way.

     Its chrome had to change regardless, because it extended
     layouts/app.blade.php, which Round F Task 4 deleted; left alone this page
     would have 500'd the moment the layout went.

     It does NOT go onto layouts.channel with the rest of the Lazada
     workspace, and that is deliberate. The route is `lazada.callback`, not
     `ext.lazada.callback`, because that exact string is registered in the
     Lazada Open Platform console and compared byte for byte on every consent
     round trip; and it carries no `auth` middleware so the answer from Lazada
     is not lost behind a login redirect. layouts.channel resolves its
     workspace from an `ext.<channel>.*` route name and abort(404)s when it
     cannot, so putting this page there would 404 the one page that has to
     work when everything else has gone wrong. It uses layouts.standalone,
     which exists for exactly this: an external landing surface with no
     sidebar and no assumption of a session.

     Shopee's callback view is the opposite case and does go on
     layouts.channel: it is rendered from ext.shopee.index, an authenticated
     route inside the workspace.

     What changed beyond the layout: the outcome was four unlabelled read-only
     text inputs saying OK / MISMATCH / YES / NO, which reads as a form you
     could edit and does not say what any of it means. It is a verdict at the
     top and a set of facts under it now, and the two raw payloads are behind
     disclosures because they are what you open when the verdict is bad. --}}
@section('content')
@php
    $stateOk = isset($state_ok) && $state_ok;
    $wasSaved = isset($saved) && $saved;
    $tokenResult = is_array($token_result ?? null) ? $token_result : null;

    // The one thing the operator needs to know, decided once here rather than
    // left for them to assemble from four separate yes-or-no rows.
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
            {{-- Not .od-title: that class is mono with tabular figures
                 because every other od- page titles itself with an order
                 or return number. This title is prose. --}}
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
                        <x-ui.button variant="primary" :href="route('ext.lazada.index')">Go to Lazada settings</x-ui.button>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
@endsection
