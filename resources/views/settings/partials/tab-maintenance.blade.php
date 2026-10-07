@php
    $cronLine = '* * * * * /usr/bin/php ' . base_path('artisan') . ' schedule:run >> /dev/null 2>&1';
@endphp

<div class="fm-body st-body--one">
    <div class="fm-col fm-col--main">

        <section class="fm-section">
            <div class="fm-section__head">
                <h2 class="fm-section__title">Scheduled tasks</h2>
            </div>
            <p class="fm-section__note">Add this to your hosting panel's Cron Jobs, set to every minute.</p>

            <div class="st-cron__row">
                <code class="st-cron__cmd" id="st-cron-line">{{ $cronLine }}</code>
                <x-ui.button type="button" size="sm" data-copy-target="st-cron-line">Copy</x-ui.button>
            </div>
        </section>

    </div>
</div>
