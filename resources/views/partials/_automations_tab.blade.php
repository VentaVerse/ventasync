@php
    $hostForm = $form ?? null;
    $jobs = \App\Models\ScheduledJob::query()
        ->where('integration', $integration)
        ->when($storeId ?? null, fn($q, $sid) => $q->where('store_id', $sid))
        ->orderBy('id')
        ->get();

    $settingModel = match ($integration) {
        'shopee' => \Extensions\shopee\Models\ShopeeSetting::class,
        'lazada' => \Extensions\lazada\Models\LazadaSetting::class,
        'tiktok' => \Extensions\tiktok\Models\TikTokSetting::class,
        default  => null,
    };
    $setting = ($settingModel && class_exists($settingModel)) ? $settingModel::query()->first() : null;

    $hasOrdersWindow  = $setting !== null
        && \Illuminate\Support\Facades\Schema::hasColumn($setting->getTable(), 'sync_last_days');
    $hasReturnsWindow = $setting !== null
        && \Illuminate\Support\Facades\Schema::hasColumn($setting->getTable(), 'sync_last_days_returns');

    $reviewsJob = $jobs->firstWhere('command', "{$integration}:sync-reviews");
    $hasReviewsWindow = $reviewsJob !== null;

    $payoutsJob = $jobs->firstWhere('command', "{$integration}:sync-payouts");
    $hasPayoutsWindow = $payoutsJob !== null;
@endphp

<div class="automations-tab">
        @if(! $hostForm)
        <form method="POST"
              action="{{ route('automations.batch_update') }}"
              class="automations-batch-form">
            @csrf
            <input type="hidden" name="integration" value="{{ $integration }}">
        @endif

            @if(! $hostForm && ($hasOrdersWindow || $hasReturnsWindow || $hasReviewsWindow || $hasPayoutsWindow))
                <div class="automations-windows">
                    <div class="automations-windows-head">
                        <h4>Sync windows</h4>
                        <p>How far back to look when fetching orders, returns, reviews, and payouts. Applied globally to the matching jobs below.</p>
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
                            <label class="automation-window">
                                <span class="automation-window-prefix">Reviews &mdash; look back</span>
                                <input type="number" name="jobs[{{ $reviewsJob->id }}][days]"
                                       value="{{ $reviewsJob->getOption('days', 7) }}"
                                       placeholder="7" min="1" max="365"
                                       class="input automation-window-value">
                                <span class="automation-window-suffix">days</span>
                            </label>
                        @endif
                        @if($hasPayoutsWindow)
                            <label class="automation-window" title="An order still not paid out after this many days is marked No payout found and no longer checked. Raise it to check older orders again.">
                                <span class="automation-window-prefix">Payouts &mdash; look back</span>
                                <input type="number" name="jobs[{{ $payoutsJob->id }}][days]"
                                       value="{{ $payoutsJob->getOption('days', \App\Support\PayoutStatus::DEFAULT_LOOKBACK_DAYS) }}"
                                       placeholder="{{ \App\Support\PayoutStatus::DEFAULT_LOOKBACK_DAYS }}" min="1" max="365"
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

                        <label class="automation-toggle" title="Enable or disable this job">
                            <input type="checkbox" name="jobs[{{ $job->id }}][enabled]" value="1" {{ $job->enabled ? 'checked' : '' }} @if($hostForm) form="{{ $hostForm }}" @endif>
                            <span class="automation-toggle-track" aria-hidden="true">
                                <span class="automation-toggle-thumb"></span>
                            </span>
                        </label>

                        <div class="automation-ident">
                            <div class="automation-ident-line">
                                <span class="automation-dot" data-state="{{ $statusState }}" aria-hidden="true"></span>
                                <span class="automation-name">{{ $job->display_name }}</span>
                            </div>
                        </div>

                        <div class="automation-controls">
                            <div class="automation-cadence" title="How often this job runs">
                                <span class="automation-cadence-prefix">every</span>
                                <input id="automation-{{ $job->id }}-cadence"
                                       type="number"
                                       name="jobs[{{ $job->id }}][cadence_value]"
                                       @if($hostForm) form="{{ $hostForm }}" @endif
                                       value="{{ $job->cadence_value }}"
                                       min="1"
                                       max="9999"
                                       class="input automation-cadence-value"
                                       required>
                                <select name="jobs[{{ $job->id }}][cadence_unit]" class="input automation-cadence-unit" aria-label="Cadence unit" @if($hostForm) form="{{ $hostForm }}" @endif>
                                    <option value="minute" {{ $job->cadence_unit === 'minute' ? 'selected' : '' }}>min</option>
                                    <option value="hour"   {{ $job->cadence_unit === 'hour'   ? 'selected' : '' }}>hr</option>
                                    <option value="day"    {{ $job->cadence_unit === 'day'    ? 'selected' : '' }}>day</option>
                                </select>
                            </div>
                        </div>

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

        @if(! $hostForm)
            <div class="cs-actions">
                <x-ui.button type="submit" variant="primary">Save All Automations</x-ui.button>
            </div>
        </form>
        @endif

        @foreach($jobs as $job)
            <form id="automation-run-{{ $job->id }}" method="POST" action="{{ route('automations.run', $job->id) }}"
                  data-run-name="{{ $job->display_name }}">
                @csrf
            </form>
        @endforeach
        @include('partials.automation-run-modal', ['stepUrl' => route('automations.run_step', ['run' => 0]), 'stopUrl' => route('automations.run_stop', ['run' => 0])])

</div>
