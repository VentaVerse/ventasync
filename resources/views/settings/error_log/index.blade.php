@extends('layouts.blotter')
@section('title', 'Error log')
@section('breadcrumb', 'Error log')

@section('content')
@php
    $canManage = auth()->user()?->hasPermission('manage_settings/error_log') ?? false;

    $entries = collect($lines)->reverse()->values()->map(function ($line) {
        $ok = preg_match(
            '/^\[(?<at>[^\]]+)\]\s+(?<level>E_[A-Z0-9_]+):\s+(?<message>.*?)(?:\s+in\s+(?<file>[^ ]+):(?<line>\d+))?(?:\s+\|\s+(?<where>[^|]*?))?(?:\s+\|\s+(?<who>[^|]*?))?$/',
            $line,
            $m
        );

        if (! $ok) {
            return ['raw' => $line, 'level' => null, 'at' => null, 'message' => $line,
                    'file' => null, 'line' => null, 'where' => null, 'who' => null];
        }

        return [
            'raw'     => $line,
            'level'   => $m['level'],
            'at'      => $m['at'],
            'message' => trim($m['message']),
            'file'    => $m['file'] ?? null,
            'line'    => $m['line'] ?? null,
            'where'   => trim($m['where'] ?? ''),
            'who'     => trim($m['who'] ?? ''),
        ];
    });

    $severity = function (?string $level): array {
        if ($level === null) {
            return ['key' => 'other', 'label' => 'Unparsed', 'tone' => 'neutral'];
        }
        if (str_contains($level, 'DEPRECATED') || str_contains($level, 'STRICT')) {
            return ['key' => 'deprecated', 'label' => 'Deprecated', 'tone' => 'neutral'];
        }
        if (str_contains($level, 'ERROR')) {
            return ['key' => 'error', 'label' => 'Error', 'tone' => 'danger'];
        }
        if (str_contains($level, 'WARNING')) {
            return ['key' => 'warning', 'label' => 'Warning', 'tone' => 'warning'];
        }
        return ['key' => 'notice', 'label' => 'Notice', 'tone' => 'info'];
    };

    $entries = $entries->map(fn ($e) => $e + ['sev' => $severity($e['level'])]);
    $counts = $entries->groupBy(fn ($e) => $e['sev']['key'])->map->count();

    $filters = [
        ''           => 'Everything',
        'error'      => 'Errors',
        'warning'    => 'Warnings',
        'notice'     => 'Notices',
        'deprecated' => 'Deprecations',
    ];
@endphp

<div class="st-log-page" x-data="{ sev: '', term: '' }">

    @include('partials.flash')

    <div class="x-list-head">
        <div>
            @include('partials.back-to-settings')
            <h1 class="x-page-title">Error log</h1>
            <p class="x-page-sub">
                Non-fatal PHP errors this application caught: warnings, notices and deprecations.
                Fatal errors and uncaught exceptions go to the Laravel log instead.
            </p>
        </div>
        @if($canManage)
        <div class="st-head-actions">
            <form method="POST" action="{{ route('error_log.test') }}">
                @csrf
                <x-ui.button type="submit">Write a test line</x-ui.button>
            </form>
            <x-ui.menu label="Error log actions">
                <button type="button" class="x-menu__item x-menu__item--danger"
                        data-confirm="Empty error.log? Every line below is deleted from the file. This cannot be undone."
                        data-confirm-submit="st-log-clear">Empty the file</button>
            </x-ui.menu>
        </div>
        @endif
    </div>

    @if($canManage)
        <form id="st-log-clear" method="POST" action="{{ route('error_log.clear') }}" class="x-sr">@csrf</form>
    @endif

    <div class="st-facts">
        <div class="st-fact">
            <span class="st-fact__k">File</span>
            <span class="st-fact__v st-fact__v--path">{{ $logPath }}</span>
        </div>
        <div class="st-fact">
            <span class="st-fact__k">Size</span>
            <span class="st-fact__v">{{ $sizeHuman }}</span>
            <span class="st-fact__note">{{ number_format($sizeBytes) }} bytes</span>
        </div>
        <div class="st-fact">
            <span class="st-fact__k">Writable</span>
            <span class="st-fact__v">
                <x-ui.badge :tone="$writable ? 'success' : 'danger'">{{ $writable ? 'Yes' : 'No' }}</x-ui.badge>
            </span>
            @unless($writable)
                <span class="st-fact__note">Nothing new can be recorded until the file permissions are fixed.</span>
            @endunless
        </div>
    </div>

    @unless($logExists)
        <div class="fm-note fm-note--warn">
            <div class="fm-note__body">
                No error.log yet. It is created the first time this application catches a PHP error.
            </div>
        </div>
    @endunless

    @if($entries->isEmpty())
        <x-ui.empty title="Nothing recorded"
                    description="The file is empty. Write a test line to prove the handler and the file permissions work." />
    @else

    <div class="x-segment-bar">
        <div class="x-segment" role="group" aria-label="Filter by severity">
            @foreach($filters as $key => $label)
                <button type="button" class="x-segment__item" :class="sev === '{{ $key }}' && 'is-active'"
                        @click="sev = '{{ $key }}'">
                    {{ $label }}
                    @if($key === '')
                        <span class="x-segment__count">{{ number_format($entries->count()) }}</span>
                    @elseif(($counts[$key] ?? 0) > 0)
                        <span class="x-segment__count">{{ number_format($counts[$key]) }}</span>
                    @endif
                </button>
            @endforeach
        </div>
        <label class="x-sr" for="st-log-q">Search the lines below</label>
        <x-ui.input type="search" id="st-log-q" x-model="term" class="x-filters__search"
                    placeholder="Search these lines" autocomplete="off" />
    </div>

    <p class="st-log-count">
        Showing the last {{ number_format($entries->count()) }}
        {{ Str::plural('line', $entries->count()) }} in the file, newest first.
    </p>

    <ol class="st-log">
        @foreach($entries as $entry)
            <li class="st-log__row"
                data-sev="{{ $entry['sev']['key'] }}"
                x-show="(sev === '' || sev === '{{ $entry['sev']['key'] }}')
                        && (term === '' || $el.dataset.hay.includes(term.toLowerCase()))"
                data-hay="{{ Str::lower($entry['raw']) }}">
                <div class="st-log__top">
                    <x-ui.badge :tone="$entry['sev']['tone']">{{ $entry['sev']['label'] }}</x-ui.badge>
                    @if($entry['at'])
                        <span class="st-log__at">{{ $entry['at'] }}</span>
                    @endif
                    @if($entry['level'])
                        <span class="st-log__lvl">{{ $entry['level'] }}</span>
                    @endif
                </div>
                <p class="st-log__msg">{{ $entry['message'] }}</p>
                @if($entry['file'] || $entry['where'] || $entry['who'])
                    <div class="st-log__meta">
                        @if($entry['file'])
                            <span class="st-log__where">{{ $entry['file'] }}:{{ $entry['line'] }}</span>
                        @endif
                        @if($entry['where'])
                            <span class="st-log__where">{{ $entry['where'] }}</span>
                        @endif
                        @if($entry['who'])
                            <span class="st-log__where">{{ $entry['who'] }}</span>
                        @endif
                    </div>
                @endif
            </li>
        @endforeach
    </ol>

    <p class="st-log-count" x-show="sev !== '' || term !== ''" x-cloak>
        Filtered from {{ number_format($entries->count()) }} {{ Str::plural('line', $entries->count()) }}.
    </p>

    @endif
</div>
@endsection
