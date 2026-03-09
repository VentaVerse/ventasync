@extends('layouts.modern.app')

@section('breadcrumb', 'Dashboard')

@section('content')
@php
    $pfx = (string) config('catalog.prefix');
    $totalProducts = \DB::table($pfx . 'product')->count();
    $lowStockItems = \DB::table($pfx . 'product')->where('quantity', '<', 5)->count();

    $recentActivity = collect();
    try {
        if (\Schema::hasTable('activity_logs')) {
            $recentActivity = \DB::table('activity_logs')->orderByDesc('created_at')->limit(10)->get();
        }
    } catch (\Throwable $e) {
        // table may not exist yet
    }
@endphp

{{-- Welcome Header --}}
<div class="mb-8">
    <h1 class="text-2xl font-bold text-gray-900">Welcome back, {{ auth()->user()->name }}</h1>
    <p class="mt-1 text-sm text-gray-500">{{ now()->format('l, F j, Y') }}</p>
</div>

{{-- Quick Stat Cards --}}
<div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6 mb-8">

    {{-- Total Products --}}
    <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-6 flex items-center gap-4">
        <div class="flex-shrink-0 w-12 h-12 rounded-full bg-blue-100 flex items-center justify-center">
            <svg class="w-6 h-6 text-blue-600" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"/>
            </svg>
        </div>
        <div>
            <p class="text-3xl font-bold text-gray-900">{{ number_format($totalProducts) }}</p>
            <p class="text-sm text-gray-500">Total Products</p>
        </div>
    </div>

    {{-- Low Stock Items --}}
    <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-6 flex items-center gap-4">
        <div class="flex-shrink-0 w-12 h-12 rounded-full bg-amber-100 flex items-center justify-center">
            <svg class="w-6 h-6 text-amber-600" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v2m0 4h.01M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z"/>
            </svg>
        </div>
        <div>
            <p class="text-3xl font-bold text-gray-900">{{ number_format($lowStockItems) }}</p>
            <p class="text-sm text-gray-500">Low Stock Items</p>
        </div>
    </div>

    {{-- Pending Lazada Orders --}}
    <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-6 flex items-center gap-4">
        <div class="flex-shrink-0 w-12 h-12 rounded-full bg-purple-100 flex items-center justify-center">
            <svg class="w-6 h-6 text-purple-600" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" d="M16 11V7a4 4 0 00-8 0v4M5 9h14l1 12H4L5 9z"/>
            </svg>
        </div>
        <div>
            <p class="text-3xl font-bold text-gray-900">{{ number_format($lazadaPendingCount ?? 0) }}</p>
            <p class="text-sm text-gray-500">Pending Lazada Orders</p>
        </div>
    </div>

    {{-- Pending Shopee Orders --}}
    <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-6 flex items-center gap-4">
        <div class="flex-shrink-0 w-12 h-12 rounded-full bg-orange-100 flex items-center justify-center">
            <svg class="w-6 h-6 text-orange-600" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" d="M3 3h2l.4 2M7 13h10l4-8H5.4M7 13L5.4 5M7 13l-2.293 2.293c-.63.63-.184 1.707.707 1.707H17m0 0a2 2 0 100 4 2 2 0 000-4zm-8 2a2 2 0 100 4 2 2 0 000-4z"/>
            </svg>
        </div>
        <div>
            <p class="text-3xl font-bold text-gray-900">{{ number_format($shopeePendingCount ?? 0) }}</p>
            <p class="text-sm text-gray-500">Pending Shopee Orders</p>
        </div>
    </div>

</div>

{{-- Quick Actions --}}
<div class="mb-8">
    <h2 class="text-lg font-semibold text-gray-900 mb-4">Quick Actions</h2>
    <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">

        <a href="{{ route('products.create') }}" class="bg-white rounded-xl shadow-sm border border-gray-100 p-4 hover:shadow-md transition flex items-center gap-3 group">
            <div class="flex-shrink-0 w-10 h-10 rounded-lg bg-blue-50 flex items-center justify-center group-hover:bg-blue-100 transition-colors">
                <svg class="w-5 h-5 text-blue-600" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4"/>
                </svg>
            </div>
            <div>
                <p class="text-sm font-medium text-gray-900">Add Product</p>
                <p class="text-xs text-gray-500">Create a new catalog product</p>
            </div>
        </a>

        <a href="{{ route('lazada.orders.index', ['tab' => 'TO_SHIP']) }}" class="bg-white rounded-xl shadow-sm border border-gray-100 p-4 hover:shadow-md transition flex items-center gap-3 group">
            <div class="flex-shrink-0 w-10 h-10 rounded-lg bg-purple-50 flex items-center justify-center group-hover:bg-purple-100 transition-colors">
                <svg class="w-5 h-5 text-purple-600" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"/>
                </svg>
            </div>
            <div>
                <p class="text-sm font-medium text-gray-900">Lazada Orders</p>
                <p class="text-xs text-gray-500">View orders ready to ship</p>
            </div>
        </a>

        <a href="{{ route('shopee.orders.index', ['tab' => 'PENDING']) }}" class="bg-white rounded-xl shadow-sm border border-gray-100 p-4 hover:shadow-md transition flex items-center gap-3 group">
            <div class="flex-shrink-0 w-10 h-10 rounded-lg bg-orange-50 flex items-center justify-center group-hover:bg-orange-100 transition-colors">
                <svg class="w-5 h-5 text-orange-600" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"/>
                </svg>
            </div>
            <div>
                <p class="text-sm font-medium text-gray-900">Shopee Orders</p>
                <p class="text-xs text-gray-500">View pending orders</p>
            </div>
        </a>

    </div>
</div>

{{-- Recent Activity --}}
<div>
    <h2 class="text-lg font-semibold text-gray-900 mb-4">Recent Activity</h2>
    <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-6">
        @if($recentActivity->isEmpty())
            <p class="text-sm text-gray-500">No recent activity.</p>
        @else
            <div class="flow-root">
                <ul role="list" class="-mb-8">
                    @foreach($recentActivity as $i => $entry)
                        <li>
                            <div class="relative pb-8">
                                @if(!$loop->last)
                                    <span class="absolute left-4 top-4 -ml-px h-full w-0.5 bg-gray-200" aria-hidden="true"></span>
                                @endif
                                <div class="relative flex items-start space-x-3">
                                    {{-- Icon --}}
                                    <div class="relative">
                                        @switch($entry->action)
                                            @case('created')
                                                <div class="flex h-8 w-8 items-center justify-center rounded-full bg-green-100 ring-8 ring-white">
                                                    <svg class="w-4 h-4 text-green-600" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                                                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4"/>
                                                    </svg>
                                                </div>
                                                @break
                                            @case('updated')
                                                <div class="flex h-8 w-8 items-center justify-center rounded-full bg-blue-100 ring-8 ring-white">
                                                    <svg class="w-4 h-4 text-blue-600" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                                                        <path stroke-linecap="round" stroke-linejoin="round" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/>
                                                    </svg>
                                                </div>
                                                @break
                                            @case('deleted')
                                                <div class="flex h-8 w-8 items-center justify-center rounded-full bg-red-100 ring-8 ring-white">
                                                    <svg class="w-4 h-4 text-red-600" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                                                        <path stroke-linecap="round" stroke-linejoin="round" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/>
                                                    </svg>
                                                </div>
                                                @break
                                            @case('login')
                                            @case('logout')
                                                <div class="flex h-8 w-8 items-center justify-center rounded-full bg-gray-100 ring-8 ring-white">
                                                    <svg class="w-4 h-4 text-gray-600" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                                                        <path stroke-linecap="round" stroke-linejoin="round" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/>
                                                    </svg>
                                                </div>
                                                @break
                                            @default
                                                <div class="flex h-8 w-8 items-center justify-center rounded-full bg-gray-100 ring-8 ring-white">
                                                    <svg class="w-4 h-4 text-gray-500" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                                                        <path stroke-linecap="round" stroke-linejoin="round" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
                                                    </svg>
                                                </div>
                                        @endswitch
                                    </div>
                                    {{-- Content --}}
                                    <div class="min-w-0 flex-1">
                                        <div class="flex items-center justify-between">
                                            <p class="text-sm text-gray-900">
                                                <span class="font-medium">{{ ucfirst($entry->action) }}</span>
                                                @if($entry->subject_type)
                                                    <span class="text-gray-500">{{ class_basename($entry->subject_type) }}</span>
                                                @endif
                                                @if($entry->subject_label)
                                                    <span class="text-gray-700">&mdash; {{ Str::limit($entry->subject_label, 60) }}</span>
                                                @endif
                                            </p>
                                            <time class="flex-shrink-0 whitespace-nowrap text-xs text-gray-400" datetime="{{ $entry->created_at }}">
                                                {{ \Carbon\Carbon::parse($entry->created_at)->diffForHumans() }}
                                            </time>
                                        </div>
                                        @if(!empty($entry->user_name))
                                            <p class="mt-0.5 text-xs text-gray-500">by {{ $entry->user_name }}</p>
                                        @endif
                                    </div>
                                </div>
                            </div>
                        </li>
                    @endforeach
                </ul>
            </div>
        @endif
    </div>
</div>
@endsection
