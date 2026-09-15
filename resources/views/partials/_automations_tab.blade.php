{{--
    Universal Automations tab. Embed into any integration settings page:

        @include('partials._automations_tab', ['integration' => 'shopee', 'storeId' => null])

    Renders one compact row per scheduled job (with the matching
    integration / store_id). Each row exposes: enabled toggle,
    cadence (every N + unit), per-job options (days / days_returns),
    last-run status, and Run Now / Save buttons -- all on a single
    horizontal line so the tab reads as a settings list rather than
    a stack of mini-cards.
--}}
@php
    $jobs = \App\Models\ScheduledJob::query()
        ->where('integration', $integration)
        ->when($storeId ?? null, fn($q, $sid) => $q->where('store_id', $sid))
        ->orderBy('id')
        ->get();

    // Resolve the integration's settings record so the top "Sync windows"
    // block can read + write `sync_last_days` / `sync_last_days_returns`.
    // The sync commands fall back to these columns when no CLI override
    // is passed, so a single global pair drives every Sync Orders /
    // Sync Returns job for the integration.
    $settingModel = match ($integration) {
        'shopee' => \Extensions\shopee\Models\ShopeeSetting::class,
        'lazada' => \Extensions\lazada\Models\LazadaSetting::class,
        'tiktok' => \Extensions\tiktok\Models\TikTokSetting::class,
        default  => null,
    };
    $setting = ($settingModel && class_exists($settingModel)) ? $settingModel::query()->first() : null;

    // Feature-detect sync-window support by the column itself, not by
    // legacy per-window routes. The columns are persisted by
    // AutomationsController::batchUpdate, so column presence ===
    // capability. Works even after the old ext.X.sync_days* routes
    // are removed.
    $hasOrdersWindow  = $setting !== null
        && \Illuminate\Support\Facades\Schema::hasColumn($setting->getTable(), 'sync_last_days');
    $hasReturnsWindow = $setting !== null
        && \Illuminate\Support\Facades\Schema::hasColumn($setting->getTable(), 'sync_last_days_returns');

    // Reviews lookback lives on the matching review job's options.days
    // (no DB column for it on the integration settings table).
    $reviewsJob = $jobs->firstWhere('command', "{$integration}:sync-reviews");
    $hasReviewsWindow = $reviewsJob !== null;
@endphp

<div class="automations-tab">
    @if($jobs->isEmpty())
        <div class="automations-empty">
            <div class="automations-empty-icon" aria-hidden="true">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round">
                    <rect x="3" y="4" width="18" height="17" rx="2"/>
                    <line x1="3" y1="9" x2="21" y2="9"/>
                    <line x1="8" y1="2" x2="8" y2="6"/>
                    <line x1="16" y1="2" x2="16" y2="6"/>
                </svg>
            </div>
            <h4>No automations yet</h4>
            <p>No scheduled jobs are configured for this integration. They&rsquo;ll show up here as soon as the extension registers them.</p>
        </div>
    @else
        {{-- One form covers every row. The operator can flip toggles,
             retune cadence values, AND adjust the Sync Windows at the
             top — then commit the lot with the single Save All button
             at the bottom. Job inputs are namespaced by job id
             (`jobs[ID][field]`); sync-window columns ride under
             `windows[X]` with a hidden `integration` so the controller
             knows which Setting model to write. --}}
        <form method="POST"
              action="{{ route('automations.batch_update') }}"
              class="automations-batch-form">
            @csrf
            <input type="hidden" name="integration" value="{{ $integration }}">

            @if($hasOrdersWindow || $hasReturnsWindow || $hasReviewsWindow)
                <div class="automations-windows">
                    <div class="automations-windows-head">
                        <h4>Sync windows</h4>
                        <p>How far back to look when fetching orders, returns, and reviews. Applied globally to the matching jobs below.</p>
                    </div>
                    <div class="automations-windows-body">
                        @if($hasOrdersWindow)
                            <label class="automation-window">
                                <span class="automation-window-prefix">Orders &mdash; look back</span>
                                <input type="number" name="windows[sync_last_days]"
                                       value="{{ $setting->sync_last_days ?? '' }}"
                                       placeholder="14" min="1" max="365"
                                       class="input automation-window-value">
                                <span class="automation-window-suffix">days</span>
                            </label>
                        @endif
                        @if($hasReturnsWindow)
                            <label class="automation-window">
                                <span class="automation-window-prefix">Returns &mdash; look back</span>
                                <input type="number" name="windows[sync_last_days_returns]"
                                       value="{{ $setting->sync_last_days_returns ?? '' }}"
                                       placeholder="14" min="1" max="365"
                                       class="input automation-window-value">
                                <span class="automation-window-suffix">days</span>
                            </label>
                        @endif
                        @if($hasReviewsWindow)
                            {{-- Reviews look-back is actually a job option, not a
                                 settings column. Route it through jobs[ID][days]
                                 so the controller writes to the matching review
                                 job's options.days field. --}}
                            <label class="automation-window">
                                <span class="automation-window-prefix">Reviews &mdash; look back</span>
                                <input type="number" name="jobs[{{ $reviewsJob->id }}][days]"
                                       value="{{ $reviewsJob->getOption('days', 7) }}"
                                       placeholder="7" min="1" max="365"
                                       class="input automation-window-value">
                                <span class="automation-window-suffix">days</span>
                            </label>
                        @endif
                    </div>
                </div>
            @endif

            <div class="automations-list">
                @foreach($jobs as $job)
                    @php
                        $statusState = $job->last_run_at
                            ? ($job->last_run_ok === false ? 'fail' : ($job->last_run_ok === true ? 'ok' : 'pending'))
                            : 'never';
                    @endphp
                    <div class="automation-row {{ $job->enabled ? 'is-enabled' : 'is-disabled' }}">

                        {{-- Toggle acts as the row's status indicator at the far left --}}
                        <label class="automation-toggle" title="Enable or disable this job">
                            <input type="checkbox" name="jobs[{{ $job->id }}][enabled]" value="1" {{ $job->enabled ? 'checked' : '' }}>
                            <span class="automation-toggle-track" aria-hidden="true">
                                <span class="automation-toggle-thumb"></span>
                            </span>
                        </label>

                        {{-- Identity: status dot + display name. The underlying
                             artisan command is intentionally hidden from the UI;
                             operators reason about jobs by display name only. --}}
                        <div class="automation-ident">
                            <div class="automation-ident-line">
                                <span class="automation-dot" data-state="{{ $statusState }}" aria-hidden="true"></span>
                                <span class="automation-name">{{ $job->display_name }}</span>
                            </div>
                        </div>

                        {{-- Cadence pill — only "every N (unit)" lives on the
                             main line. The look-back window pills are kept
                             in the Sync Windows block above the list. --}}
                        <div class="automation-controls">
                            <div class="automation-cadence" title="How often this job runs">
                                <span class="automation-cadence-prefix">every</span>
                                <input id="automation-{{ $job->id }}-cadence"
                                       type="number"
                                       name="jobs[{{ $job->id }}][cadence_value]"
                                       value="{{ $job->cadence_value }}"
                                       min="1"
                                       max="9999"
                                       class="input automation-cadence-value"
                                       required>
                                <select name="jobs[{{ $job->id }}][cadence_unit]" class="input automation-cadence-unit" aria-label="Cadence unit">
                                    <option value="minute" {{ $job->cadence_unit === 'minute' ? 'selected' : '' }}>min</option>
                                    <option value="hour"   {{ $job->cadence_unit === 'hour'   ? 'selected' : '' }}>hr</option>
                                    <option value="day"    {{ $job->cadence_unit === 'day'    ? 'selected' : '' }}>day</option>
                                </select>
                            </div>
                        </div>

                        {{-- Right cluster: the last run, and Run now (the owner,
                             2026-09-06). The button belongs to its own form,
                             rendered after the batch form below, through the
                             HTML5 form attribute: a form cannot nest. --}}
                        <div class="automation-actions">
                            <x-ui.button type="submit" size="sm" form="automation-run-{{ $job->id }}"
                                         title="Run {{ $job->display_name }} now, without waiting for the schedule">Run now</x-ui.button>
                            <span class="automation-lastrun" aria-label="Last run">
                                @if($job->last_run_at)
                                    <span class="automation-lastrun-prefix">Last run</span>
                                    <time datetime="{{ $job->last_run_at->toIso8601String() }}">{{ $job->last_run_at->format('M d, H:i') }}</time>
                                    @if($job->last_run_ok === true)
                                        <span class="badge badge-green">OK</span>
                                    @elseif($job->last_run_ok === false)
                                        <span class="badge badge-red">Failed</span>
                                        @php($failedUrl = \App\Integrations\Support\PushFailureLink::for($job, $storeId ?? null))
                                        @if($failedUrl)
                                            <x-ui.button size="sm" :href="$failedUrl" class="automation-failed-link">See which products failed</x-ui.button>
                                        @endif
                                    @endif
                                @else
                                    <span class="automation-lastrun-prefix">Last run</span>
                                    <span class="automation-lastrun-never">never</span>
                                @endif
                            </span>
                        </div>

                    </div>
                @endforeach
            </div>

            <div class="cs-actions">
                <x-ui.button type="submit" variant="primary">Save All Automations</x-ui.button>
            </div>
        </form>

        {{-- One run form per job, outside the batch form. data-busy raises
             the overlay below while the command runs inside the request
             (resources/js/busy-overlay.js). --}}
        @foreach($jobs as $job)
            <form id="automation-run-{{ $job->id }}" method="POST" action="{{ route('automations.run', $job->id) }}"
                  data-run-name="{{ $job->display_name }}">
                @csrf
            </form>
        @endforeach
        {{-- Run now (the owner, 2026-09-07): one shape for every automation. A
             modal with a loading state, then one sentence, success or failure,
             and OK. resources/js/automation-run.js drives it; the stepping
             underneath is invisible on purpose. --}}
        <div class="modal-backdrop" data-automation-run data-step-url="{{ route('automations.run_step', ['run' => 0]) }}" data-stop-url="{{ route('automations.run_stop', ['run' => 0]) }}">
            <div class="modal co-modal co-modal--sm ar-modal" role="dialog" aria-modal="true" aria-live="polite" aria-labelledby="ar-modal-title" data-ar-modal data-state="running">
                <div class="ar-modal__mark" aria-hidden="true">
                    <span class="ar-modal__ring" data-ar-spinner></span>
                    <svg class="ar-modal__glyph ar-modal__glyph--done" data-ar-mark-done hidden viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.25" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12.5l4.5 4.5L19 7.5"/></svg>
                    <svg class="ar-modal__glyph ar-modal__glyph--failed" data-ar-mark-failed hidden viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.25" stroke-linecap="round"><path d="M7 7l10 10M17 7L7 17"/></svg>
                </div>
                <div class="ar-modal__title" id="ar-modal-title" data-ar-title>Running</div>
                <div class="ar-modal__text" data-ar-text></div>
                <div class="ar-modal__foot">
                    <button type="button" class="x-btn x-btn--secondary" data-ar-stop>Stop</button>
                    <button type="button" class="x-btn x-btn--primary" data-ar-ok hidden>OK</button>
                </div>
            </div>
        </div>
    @endif

    {{-- The one thing every job on this page depends on, and the only thing
         here that lives outside the ERP.

         Laravel's scheduler decides WHICH job is due; something still has to
         wake it up every minute. Nothing on this tab said so, so a row could
         read "enabled, every 5 minutes" and never run once, with no way to
         tell from this screen. Venta's old tab explained the cron setup and
         the other three never did - this is that explanation, kept, and now
         shown on all of them.

         Stated as fact rather than as an instruction with a Copy button: on
         most installs it is already in place, and the operator needs to
         recognise it, not re-add it. --}}
    <p class="automations-foot">
        These run from the ERP's own scheduler. It needs one entry in your
        hosting panel's Cron Jobs, set to every minute:
        <code class="automations-foot__cmd">* * * * * php {{ base_path('artisan') }} schedule:run</code>
        Without it nothing on this page runs, whatever it says here.
    </p>
</div>
