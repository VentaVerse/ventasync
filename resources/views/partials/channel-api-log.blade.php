@php
    $modeLabels = \App\Support\ApiLogMode::labels();
    $keepDays = \App\Support\ApiLogPanel::keepDays();
    $showErrors = request('log_show') === 'errors';
    $logQ = trim((string) request('log_q', ''));
    $filtered = $showErrors || $logQ !== '';
@endphp

<section class="fm-section">
    <div class="fm-section__head">
        <h2 class="fm-section__title">Recording</h2>
        <x-ui.hint label="What each recording mode keeps">Everything keeps a copy of each request sent to {{ $logChannel }} and the answer that came back. Errors only keeps just the failed calls, so the every-minute order fetches stay out of the log while the rows that explain a problem stay in. Either way, rows older than {{ $keepDays }} days are removed nightly.</x-ui.hint>
    </div>
    @if($canManage)
        <form method="POST" action="{{ $modeUrl }}" class="x-segment cs-modeseg" role="group" aria-label="What the API log records">
            @csrf
            @foreach($modeLabels as $mode => $label)
                <button type="submit" name="mode" value="{{ $mode }}"
                        class="x-segment__item {{ $logMode === $mode ? 'is-active' : '' }}"
                        aria-pressed="{{ $logMode === $mode ? 'true' : 'false' }}"><span>{{ $label }}</span></button>
            @endforeach
        </form>
    @else
        <x-ui.badge :tone="$logMode === \App\Support\ApiLogMode::OFF ? 'neutral' : 'success'">{{ $modeLabels[$logMode] ?? 'Everything' }}</x-ui.badge>
    @endif
</section>

<section class="fm-section">
    <div class="fm-section__head">
        <h2 class="fm-section__title">Recent calls</h2>
        <div class="fm-section__aside cs-logtools">
            <nav class="x-segment" aria-label="Which calls to show">
                <a class="x-segment__item {{ $showErrors ? '' : 'is-active' }}"
                   href="{{ request()->fullUrlWithQuery(['log_show' => null, 'logPage' => null]) }}"
                   @if(!$showErrors) aria-current="true" @endif><span>All</span></a>
                <a class="x-segment__item {{ $showErrors ? 'is-active' : '' }}"
                   href="{{ request()->fullUrlWithQuery(['log_show' => 'errors', 'logPage' => null]) }}"
                   @if($showErrors) aria-current="true" @endif><span>Errors</span></a>
            </nav>
            <form method="GET" action="{{ request()->url() }}" class="cs-logsearch">
                <input type="hidden" name="tab" value="logs">
                @if($showErrors)<input type="hidden" name="log_show" value="errors">@endif
                <x-ui.input name="log_q" value="{{ $logQ }}" placeholder="Filter by path"
                            class="cs-logsearch__q" aria-label="Show only calls whose path contains this" />
                <x-ui.button type="submit" size="sm" variant="secondary">Filter</x-ui.button>
            </form>
            <a class="cc-iconbtn" href="{{ request()->fullUrl() }}"
               title="Refresh this list (keeps the filters and this tab)" aria-label="Refresh the call list">
                <x-ui.icon name="refresh-cw" size="13" />
            </a>
            @if($canManage && $clearUrl && $logs->total() > 0)
                <form method="POST" action="{{ $clearUrl }}"
                      data-confirm="Delete every recorded {{ $logChannel }} API call? This cannot be undone.">
                    @csrf
                    @method('DELETE')
                    <x-ui.button type="submit" variant="danger" size="sm">Delete all</x-ui.button>
                </form>
            @endif
        </div>
    </div>

    @if($logs->total() === 0)
        @if($filtered)
            <x-ui.empty title="Nothing matches"
                        description="No recorded call fits the current filter. Clear it to see everything that was kept." />
        @else
            <x-ui.empty title="Nothing recorded yet"
                        description="Calls appear here as soon as a sync runs, provided recording is on." />
        @endif
    @else
        <x-ui.table>
            <x-slot:head>
                <tr>
                    <th scope="col" class="cs-col-when">When</th>
                    <th scope="col" class="cs-col-method">Method</th>
                    <th scope="col">Path</th>
                    @if($map['signed'] ?? null)<th scope="col" class="cs-col-auth">Signed</th>@endif
                    <th scope="col" class="cs-col-status x-td-num">Status</th>
                    @if($map['ms'] ?? null)<th scope="col" class="cs-col-ms x-td-num">Time</th>@endif
                </tr>
            </x-slot:head>

            @foreach($logs as $log)
                @php $rowOk = (bool) ($map['okFn'])($log); @endphp
                <tr>
                    <td class="cs-col-when" data-label="When">{{ $log->created_at?->format('M d, H:i:s') }}</td>
                    <td class="cs-col-method" data-label="Method">
                        <x-ui.badge :tone="$rowOk ? 'success' : 'danger'">{{ $log->method }}</x-ui.badge>
                    </td>
                    <td data-label="Path">
                        @if(($map['pack'] ?? null) && !empty($log->{$map['pack']}))
                            <span class="cs-map-label">{{ $log->{$map['pack']} }}</span>
                        @endif
                        <div class="cs-code">{{ $log->{$map['path']} }}</div>
                        @if(!$rowOk && $log->{$map['response']})
                            <span class="cc-err">{{ \Illuminate\Support\Str::limit(json_encode($log->{$map['response']}), 120) }}</span>
                        @endif

                        <details class="cs-disclosure">
                            <summary>Request and response</summary>
                            <div class="cs-disclosure__body">
                                <span class="cs-disclosure__label">{{ $map['requestLabel'] }}</span>
                                <pre class="cs-pre">{{ $log->{$map['request']} ? json_encode($log->{$map['request']}, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) : 'Nothing sent (a GET request)' }}</pre>
                                <span class="cs-disclosure__label">Response</span>
                                <pre class="cs-pre">{{ $log->{$map['response']} ? json_encode($log->{$map['response']}, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) : 'Empty response' }}</pre>
                            </div>
                        </details>
                    </td>
                    @if($map['signed'] ?? null)
                        <td class="cs-col-auth" data-label="Signed">{{ $log->{$map['signed']} ? 'Yes' : 'No' }}</td>
                    @endif
                    <td class="cs-col-status x-td-num" data-label="Status">{{ $log->{$map['status']} ?? '-' }}</td>
                    @if($map['ms'] ?? null)
                        <td class="cs-col-ms x-td-num" data-label="Time">{{ $log->{$map['ms']} !== null ? $log->{$map['ms']} . ' ms' : '-' }}</td>
                    @endif
                </tr>
            @endforeach
        </x-ui.table>

        <x-ui.pager :paginator="$logs" />
    @endif
</section>
