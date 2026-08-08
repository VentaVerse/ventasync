@php
    $cronLine = '* * * * * /usr/bin/php ' . base_path('artisan') . ' schedule:run >> /dev/null 2>&1';
@endphp

{{-- Nothing on this tab is saved, so it carries no form and no save bar.
     It is one command an operator copies into their hosting panel once. --}}
<div class="fm-body st-body--one">
    <div class="fm-col fm-col--main">

        <section class="fm-section">
            <div class="fm-section__head">
                <h2 class="fm-section__title">Scheduled tasks</h2>
            </div>
            <p class="fm-section__note">
                One entry in the server's crontab drives every scheduled task in this application.
                Add it once. After that, how often each individual job runs is set on that
                integration's Automations tab, with no further changes to the crontab.
            </p>

            <div class="st-cron__row">
                <code class="st-cron__cmd" id="st-cron-line">{{ $cronLine }}</code>
                <x-ui.button type="button" size="sm" data-copy-target="st-cron-line">Copy</x-ui.button>
            </div>

            <ol class="st-steps">
                <li>Open your hosting panel and find Cron Jobs or Scheduled Tasks.</li>
                <li>Paste the command above and set the interval to every minute.</li>
                <li>Save. Each job then runs at the cadence set on its own Automations tab.</li>
            </ol>
        </section>

    </div>
</div>
