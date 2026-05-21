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
    $hasOrdersWindow  = $setting !== null && \Illuminate\Support\Facades\Route::has("ext.{$integration}.sync_days");
    $hasReturnsWindow = $setting !== null && \Illuminate\Support\Facades\Route::has("ext.{$integration}.sync_days_returns");

    // Reviews lookback lives on the matching review job's options.days
    // (no DB column for it on the integration settings table).
    $reviewsJob = $jobs->firstWhere('command', "{$integration}:sync-reviews");
    $hasReviewsWindow = $reviewsJob !== null;
@endphp

<div class="automations-tab">
    @if($hasOrdersWindow || $hasReturnsWindow || $hasReviewsWindow)
        <div class="automations-windows">
            <div class="automations-windows-head">
                <h4>Sync windows</h4>
                <p>How far back to look when fetching orders, returns, and reviews. Applied globally to the matching jobs below.</p>
            </div>
            <div class="automations-windows-body">
                @if($hasOrdersWindow)
                    <form method="POST" action="{{ route('ext.'.$integration.'.sync_days') }}" class="automations-window-form">
                        @csrf
                        <label class="automation-window">
                            <span class="automation-window-prefix">Orders &mdash; look back</span>
                            <input type="number" name="sync_last_days"
                                   value="{{ $setting->sync_last_days ?? '' }}"
                                   placeholder="14" min="1" max="365"
                                   class="input automation-window-value">
                            <span class="automation-window-suffix">days</span>
                        </label>
                        <button type="submit" class="btn small automation-btn-save">Save</button>
                    </form>
                @endif
                @if($hasReturnsWindow)
                    <form method="POST" action="{{ route('ext.'.$integration.'.sync_days_returns') }}" class="automations-window-form">
                        @csrf
                        <label class="automation-window">
                            <span class="automation-window-prefix">Returns &mdash; look back</span>
                            <input type="number" name="sync_last_days_returns"
                                   value="{{ $setting->sync_last_days_returns ?? '' }}"
                                   placeholder="14" min="1" max="365"
                                   class="input automation-window-value">
                            <span class="automation-window-suffix">days</span>
                        </label>
                        <button type="submit" class="btn small automation-btn-save">Save</button>
                    </form>
                @endif
                @if($hasReviewsWindow)
                    {{-- Reviews window writes to the review job's options.days
                         via the AutomationsController. We send the current
                         cadence inputs back unchanged so validation passes. --}}
                    <form method="POST" action="{{ route('automations.update', $reviewsJob->id) }}" class="automations-window-form">
                        @csrf
                        <input type="hidden" name="cadence_value" value="{{ $reviewsJob->cadence_value }}">
                        <input type="hidden" name="cadence_unit" value="{{ $reviewsJob->cadence_unit }}">
                        @if($reviewsJob->enabled) <input type="hidden" name="enabled" value="1"> @endif
                        <label class="automation-window">
                            <span class="automation-window-prefix">Reviews &mdash; look back</span>
                            <input type="number" name="days"
                                   value="{{ $reviewsJob->getOption('days', 7) }}"
                                   placeholder="7" min="1" max="365"
                                   class="input automation-window-value">
                            <span class="automation-window-suffix">days</span>
                        </label>
                        <button type="submit" class="btn small automation-btn-save">Save</button>
                    </form>
                @endif
            </div>
        </div>
    @endif

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
        {{-- One form covers every row. The operator can flip toggles and
             retune cadence values freely, then commit the lot with the
             single Save All button at the bottom. Inputs are namespaced
             by job id (`jobs[ID][field]`) so the controller's
             `jobs.*.X` validation rules pick them up. --}}
        <form method="POST"
              action="{{ route('automations.batch_update') }}"
              class="automations-batch-form">
            @csrf
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

                        {{-- Right cluster: last-run only. The per-row Save
                             button was folded into the single Save All
                             button at the bottom of the list. --}}
                        <div class="automation-actions">
                            <span class="automation-lastrun" aria-label="Last run">
                                @if($job->last_run_at)
                                    <span class="automation-lastrun-prefix">Last run</span>
                                    <time datetime="{{ $job->last_run_at->toIso8601String() }}">{{ $job->last_run_at->format('M d, H:i') }}</time>
                                    @if($job->last_run_ok === true)
                                        <span class="badge badge-green">OK</span>
                                    @elseif($job->last_run_ok === false)
                                        <span class="badge badge-red">Failed</span>
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

            <div class="automations-batch-actions">
                <button type="submit" class="btn automation-btn-save-all">Save All Automations</button>
            </div>
        </form>
    @endif
</div>
