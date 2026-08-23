{{-- The four page regions the views hosted on layouts.channel depend on: the
     session flash region, the validation error region, the integration banner
     region, and the #js-flash-container that window.showFlashError /
     showFlashSuccess write into (defined in resources/js/legacy-flash.js).

     THIS IS NOW THE SINGLE DEFINITION. It began as a faithful copy of the
     same markup inside layouts/app.blade.php: that file was protected and had
     to stay byte-identical, so a layout hosting a view written against it
     could only honour the contract by duplicating it. Round F Task 4 deleted
     layouts/app.blade.php, which removed the original and with it the reason
     for the copy. There is one definition of these regions again, and it is
     this file.

     Include this inside the element that wraps page content, before
     @yield('content'), so the alerts sit where the views expect them.

     A page that presents its own validation errors declares
     @section('own-errors') and this partial then skips its error list. That
     exists because the fm- form anatomy shows a short summary note ("Nothing
     was saved. Check the fields marked below.") and marks the offending
     fields inline, which is a different and better thing than a bullet dump
     of every message. Rendering both stacked two red boxes on top of each
     other on every failed save - the defect registered in Task 1 as "stacked
     double error banners", released here. Pages that do NOT opt out keep the
     full list, which is the only error display they have. --}}

{{-- role/aria-live on every alert. The outcome of a marketplace write was
     landing in the DOM with nothing announcing it, so an operator using a
     screen reader pressed Push and was told nothing at all. Success and the
     mixed result are polite; a failure interrupts. --}}
@if(session('status'))
    <div class="alert success" role="status" aria-live="polite">
        <svg class="alert__icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
        <span>{{ session('status') }}</span>
        <button class="alert-close" onclick="this.parentElement.remove()" aria-label="Close">&times;</button>
    </div>
@endif

{{-- Partly failed. Without this key a batch where most rows failed had only
     two things it could say, and "success" was the one it picked. --}}
@if(session('warning'))
    <div class="alert warning" role="status" aria-live="polite">
        <svg class="alert__icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M10.29 3.86 1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0Z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
        <span>{{ session('warning') }}</span>
        <button class="alert-close" onclick="this.parentElement.remove()" aria-label="Close">&times;</button>
    </div>
@endif

@if(session('error'))
    <div class="alert danger" role="alert">
        <svg class="alert__icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg>
        <span>{{ session('error') }}</span>
        <button class="alert-close" onclick="this.parentElement.remove()" aria-label="Close">&times;</button>
    </div>
@endif

@if($errors->any() && !$__env->hasSection('own-errors'))
    <div class="alert danger">
        <svg class="alert__icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg>
        <div>
            <ul class="alert__list">
                @foreach($errors->all() as $e)
                    <li>{{ $e }}</li>
                @endforeach
            </ul>
        </div>
        <button class="alert-close" onclick="this.parentElement.remove()" aria-label="Close">&times;</button>
    </div>
@endif

@php
    // Layout banners contributed by every enabled+licensed integration.
    // Token expiry warnings, sync pauses, etc. each marketplace knows
    // its own state and contributes via LayoutBannerContributor.
    //
    // SCOPED: inside a channel you see that channel's banners and nothing
    // else. Another marketplace's problem is noise while you are working in
    // this one, and it was arriving as an alarm - "TikTok Shop: Access token
    // expired" sat at the top of Venta and OpenCart pages.
    //
    // WHICH channel comes from $channelCard, which the layouts.channel view
    // composer has already resolved for this request through
    // ChannelWorkspace, including the /channels/venta/2 -> "venta:2"
    // translation and the 404 when it resolves to nothing. The card's id is
    // the bare integration ("venta"), which is what a contributor is keyed by.
    //
    // It used to be re-derived here from the route name and three candidate
    // route parameters, matched against the CONTRIBUTOR map. That is the bug
    // the owner reported, and the reason it is worth naming: a channel matched
    // only if it contributed banners, so a channel that contributes none was
    // indistinguishable from "not in a channel at all" and fell through to
    // showing everybody's. LayoutBannerContributor is opt-in and most channels
    // do not implement it, so that fallback was not an edge case - it was the
    // default for Venta, OpenCart, Shopify, Pedallion and for every
    // integration added afterwards. Asking the page which channel it is in,
    // rather than asking the banner map whether it recognises the channel,
    // is what stops the next integration inheriting it.
    //
    // $channelCard is null only if this partial is ever included outside
    // layouts.channel, which nothing does today. Then no channel owns the
    // page and every banner is shown, which is the right reading for a
    // cross-channel surface like the dashboard.
@endphp

@include('partials.integration-banners', ['bannerOnly' => ($channelCard ?? null)?->id])

<div id="js-flash-container"></div>
