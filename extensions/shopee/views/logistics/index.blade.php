@extends('layouts.app')
@section('breadcrumb', 'Marketplace / Shopee / Logistics')

@section('title', 'Shopee Logistics Channels')

@section('content')
    <div class="page-header">
        <div>
            <h2>Shopee Logistics Channels</h2>
            <div class="text-muted text-sm">Fetch and view available logistics channels from your Shopee shop.</div>
        </div>
        <div class="page-header-actions">
            <a class="btn secondary" href="{{ route('ext.shopee.index') }}">Shopee Settings</a>
            <form method="POST" action="{{ route('ext.shopee.logistics.fetch') }}" style="display:inline;">
                @csrf
                <button class="btn" type="submit">Fetch from Shopee</button>
            </form>
        </div>
    </div>

    <div class="card">
        <div class="table-wrap">
            <table class="table">
                <thead>
                <tr>
                    <th style="width:80px;">Channel ID</th>
                    <th>Name</th>
                    <th style="width:80px;">Status</th>
                    <th style="width:60px;">COD</th>
                    <th>Weight Limit</th>
                    <th>Max Dimensions</th>
                    <th style="width:60px;">Pre-order</th>
                </tr>
                </thead>
                <tbody>
                @forelse($channels as $ch)
                    <tr>
                        <td class="font-mono text-sm">{{ $ch->logistics_channel_id }}</td>
                        <td>
                            <div class="font-bold">{{ $ch->logistics_channel_name }}</div>
                            @if($ch->mask_channel_id > 0)
                                <div class="text-muted text-xs">Masks channel: {{ $ch->mask_channel_id }}</div>
                            @endif
                        </td>
                        <td>
                            @if($ch->force_enable)
                                <span class="badge badge-blue">Force On</span>
                            @elseif($ch->enabled)
                                <span class="badge badge-green">Enabled</span>
                            @else
                                <span class="badge badge-gray">Disabled</span>
                            @endif
                        </td>
                        <td>
                            @if($ch->cod_enabled)
                                <span class="badge badge-green">Yes</span>
                            @else
                                <span class="text-muted">No</span>
                            @endif
                        </td>
                        <td>
                            @php $wl = $ch->weight_limit; @endphp
                            @if(!empty($wl) && ($wl['item_max_weight'] ?? 0) > 0)
                                {{ $wl['item_min_weight'] ?? 0 }} &ndash; {{ $wl['item_max_weight'] }} kg
                            @else
                                <span class="text-muted">-</span>
                            @endif
                        </td>
                        <td>
                            @php $dim = $ch->item_max_dimension; @endphp
                            @if(!empty($dim) && ($dim['length'] ?? 0) > 0)
                                {{ $dim['length'] }} &times; {{ $dim['width'] }} &times; {{ $dim['height'] }} {{ $dim['unit'] ?? 'cm' }}
                                @if(($dim['dimension_sum'] ?? 0) > 0)
                                    <span class="text-muted text-xs">(sum: {{ $dim['dimension_sum'] }})</span>
                                @endif
                            @else
                                <span class="text-muted">-</span>
                            @endif
                        </td>
                        <td>
                            @if($ch->support_pre_order)
                                <span class="text-muted">Yes</span>
                            @else
                                <span class="text-muted">No</span>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="7" class="text-muted">No logistics channels cached yet. Click <strong>Fetch from Shopee</strong> to load them.</td>
                    </tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </div>
@endsection
