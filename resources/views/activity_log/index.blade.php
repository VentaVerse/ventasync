@extends('layouts.app')
@section('breadcrumb', 'Settings / Activity Log')

@section('content')
<div class="card">
    <div class="page-header">
        <h2>Activity Log</h2>
        <div class="page-header-actions">
            <form method="POST" action="{{ route('activity_log.clear') }}" onsubmit="return confirm('Clear logs older than {{ $retentionDays }} days?')">
                @csrf
                <button class="btn secondary" type="submit">Clear Old Logs ({{ $retentionDays }}+ days)</button>
            </form>
        </div>
    </div>

    {{-- Category Tabs --}}
    <div class="tabs mb-16">
        @foreach($categories as $key => $cat)
            <a class="tab {{ $category === $key ? 'active' : '' }}"
               href="{{ route('activity_log.index', array_merge(request()->except('category', 'page'), ['category' => $key])) }}">
                {{ $cat['label'] }}
                @if(isset($categoryCounts[$key]) && $categoryCounts[$key] > 0)
                    <span style="font-size:11px; color:var(--text-muted); margin-left:4px;">({{ number_format($categoryCounts[$key]) }})</span>
                @endif
            </a>
        @endforeach
    </div>

    {{-- Filters --}}
    <form method="GET" action="{{ route('activity_log.index') }}" class="d-flex gap-8 items-end mb-16" style="flex-wrap:wrap;">
        <input type="hidden" name="category" value="{{ $category }}">
        <div>
            <label class="text-xs text-secondary">Search</label>
            <input class="input" name="q" value="{{ $q }}" placeholder="Subject..." style="width:200px;">
        </div>
        <div>
            <label class="text-xs text-secondary">Action</label>
            <select class="input" name="action" style="width:140px;">
                <option value="">All</option>
                @foreach($actions as $a)
                    <option value="{{ $a }}" {{ $action === $a ? 'selected' : '' }}>{{ ucfirst($a) }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label class="text-xs text-secondary">User</label>
            <select class="input" name="user_id" style="width:160px;">
                <option value="">All</option>
                @foreach($users as $u)
                    <option value="{{ $u->id }}" {{ $userId === $u->id ? 'selected' : '' }}>{{ $u->name }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label class="text-xs text-secondary">Source</label>
            <select class="input" name="source" style="width:140px;">
                <option value="">All</option>
                <option value="user" {{ $source === 'user' ? 'selected' : '' }}>User Actions</option>
                <option value="system" {{ $source === 'system' ? 'selected' : '' }}>System</option>
            </select>
        </div>
        <div>
            <label class="text-xs text-secondary">From</label>
            <input class="input" type="date" name="date_from" value="{{ $dateFrom }}" style="width:150px;">
        </div>
        <div>
            <label class="text-xs text-secondary">To</label>
            <input class="input" type="date" name="date_to" value="{{ $dateTo }}" style="width:150px;">
        </div>
        <button class="btn" type="submit">Filter</button>
        @if($q || $action || $userId || $source || $dateFrom || $dateTo)
            <a class="btn secondary" href="{{ route('activity_log.index', ['category' => $category]) }}">Reset</a>
        @endif
    </form>

    @if($logs->isEmpty())
        <div class="text-secondary text-center py-32">No activity recorded yet.</div>
    @else
        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th style="width:150px;">When</th>
                        <th style="width:130px;">User</th>
                        <th style="width:90px;">Action</th>
                        <th>Subject</th>
                        <th style="width:120px;">IP</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($logs as $log)
                        <tr>
                            <td class="text-xs text-secondary">{{ $log->created_at->format('M d, Y H:i') }}</td>
                            <td>
                                {{ $log->user_name ?? 'System' }}
                                @if($log->source === 'system')
                                    <span style="display:inline-block; padding:1px 6px; border-radius:99px; font-size:10px; font-weight:600; background:#f59e0b15; color:#d97706; margin-left:4px;">System</span>
                                @endif
                            </td>
                            <td>
                                @php
                                    $actionColors = [
                                        'created' => '#16a34a',
                                        'updated' => '#2563eb',
                                        'deleted' => '#dc2626',
                                        'login'   => '#7c3aed',
                                        'logout'  => '#64748b',
                                        'purged'  => '#d97706',
                                    ];
                                    $color = $actionColors[$log->action] ?? '#64748b';
                                @endphp
                                <span style="display:inline-block; padding:2px 8px; border-radius:99px; font-size:11px; font-weight:600; background:{{ $color }}15; color:{{ $color }};">
                                    {{ ucfirst($log->action) }}
                                </span>
                            </td>
                            <td>
                                <div class="font-bold" style="font-size:13px;">
                                    @if($log->subject_type)
                                        <span class="text-secondary" style="font-weight:400;">{{ $log->subject_type }}</span>
                                    @endif
                                    @if($log->subject_label)
                                        {{ $log->subject_label }}
                                    @elseif($log->subject_id)
                                        #{{ $log->subject_id }}
                                    @endif
                                </div>
                                @if($log->changes)
                                    <details style="margin-top:4px;">
                                        <summary class="text-xs text-secondary" style="cursor:pointer;">
                                            {{ count($log->changes) }} field(s) changed
                                        </summary>
                                        <div style="margin-top:4px; font-size:12px; line-height:1.5;">
                                            @foreach($log->changes as $field => $vals)
                                                <div>
                                                    <span class="text-secondary">{{ $field }}:</span>
                                                    @if(is_array($vals) && count($vals) === 2)
                                                        <span style="color:#dc2626; text-decoration:line-through;">{{ \Illuminate\Support\Str::limit((string)$vals[0], 50) }}</span>
                                                        &rarr;
                                                        <span style="color:#16a34a;">{{ \Illuminate\Support\Str::limit((string)$vals[1], 50) }}</span>
                                                    @else
                                                        {{ \Illuminate\Support\Str::limit(json_encode($vals), 80) }}
                                                    @endif
                                                </div>
                                            @endforeach
                                        </div>
                                    </details>
                                @endif
                            </td>
                            <td class="text-xs text-secondary">{{ $log->ip_address }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        <div class="mt-16">
            {{ $logs->links() }}
        </div>
    @endif
</div>
@endsection
