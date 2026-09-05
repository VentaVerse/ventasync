{{-- Connecting this store (the owner chose draft C, 2026-09-05): the three
     steps a store goes through, in a strip above the keys and the by-hand
     tools, each with its state and, where one exists, its button. The keys
     and the button that uses them were one flow in two cards; this puts the
     flow where it is read first.

     $envWord      'production' or 'sandbox'
     $keysOk       the environment's keys are on file
     $hasToken     a token is stored for the environment
     $tokenExpired that token has lapsed
     $expiresAt    when it lapses, or null
     $authLabel    the open-authorisation button's words
     $canManage    manage tier --}}
@php
    $categories = \Extensions\shopee\Models\ShopeeCategory::query()->count();
    $couriers = \Extensions\shopee\Models\ShopeeLogistic::query()->count();
    $setUp = $categories > 0 && $couriers > 0;
@endphp
<section class="fm-section">
    <div class="fm-section__head">
        <h2 class="fm-section__title">Connecting this store</h2>
    </div>
    <ol class="cs-flow">
        <li class="cs-flow__step" data-state="{{ $keysOk ? 'done' : 'todo' }}">
            <span class="cs-step__n">1</span>
            <div class="cs-flow__body">
                <span class="cs-step__title">Save the keys</span>
                <span class="cs-flow__state">{{ $keysOk ? ucfirst($envWord) . ' keys saved' : 'Fill in the ' . $envWord . ' keys below and save' }}</span>
            </div>
        </li>
        <li class="cs-flow__step" data-state="{{ $hasToken && ! $tokenExpired ? 'done' : ($hasToken ? 'failed' : 'todo') }}">
            <span class="cs-step__n">2</span>
            <div class="cs-flow__body">
                <span class="cs-step__title">Authorise with Shopee <x-ui.hint label="About this step">Opens Shopee sign-in in a new tab, with the {{ $envWord }} keys saved here. After you grant access Shopee sends you back with an auth code, and the code is exchanged for a token on its own.</x-ui.hint></span>
                @if($canManage)
                    <div>
                        <x-ui.button size="sm" :variant="$hasToken && ! $tokenExpired ? 'secondary' : 'primary'" :href="route('ext.shopee.authorize', ['store' => $setting->id ?? null])" target="_blank" rel="noopener">{{ $authLabel }}</x-ui.button>
                    </div>
                @endif
                <span class="cs-flow__state">
                    @if($hasToken && ! $tokenExpired)
                        Token active{{ $expiresAt ? ', expires ' . \Carbon\Carbon::parse($expiresAt)->diffForHumans() : '' }}
                    @elseif($hasToken)
                        Token expired: authorise again, or refresh it by hand
                    @else
                        No token yet
                    @endif
                </span>
            </div>
        </li>
        <li class="cs-flow__step" data-state="{{ $setUp ? 'done' : 'todo' }}">
            <span class="cs-step__n">3</span>
            <div class="cs-flow__body">
                <span class="cs-step__title">Set up the store <x-ui.hint class="bl-hint--end" label="About this step">Reads the categories and couriers every push needs. It runs on its own after an authorisation; press it again to refresh them.</x-ui.hint></span>
                @if($canManage)
                    <div>
                        <x-ui.button type="button" size="sm" :variant="$setUp ? 'secondary' : 'primary'" data-setup-open>Set up store</x-ui.button>
                    </div>
                @endif
                <span class="cs-flow__state">
                    @if($setUp)
                        {{ number_format($categories) }} categories and {{ number_format($couriers) }} couriers on file
                    @else
                        Nothing fetched yet
                    @endif
                </span>
            </div>
        </li>
    </ol>
</section>
