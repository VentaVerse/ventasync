@extends('layouts.channel')
@section('title', $category->name . ' Attributes, Lazada')
@section('breadcrumb', $category->name . ' Attributes')

@section('content')
@php
    $canManageLazada = auth()->user()?->hasPermission('manage_lazada/category_attribute') ?? false;

    $attributes = $attributes ?? [];
    $requiredCount = collect($attributes)->filter(fn ($a) => !empty($a['required']))->count();
@endphp

<div class="cc-page">

<div class="x-list-head">
    <div>
        <h1 class="x-page-title">{{ $category->name }}</h1>
        <p class="x-page-sub">
            What Lazada asks for on every product in this category
        </p>
    </div>
    @if($canManageLazada)
        <form method="POST" action="{{ route('ext.lazada.categories.attributes.fetch', $category->category_id) }}" data-slow-action>
            @csrf
            <x-ui.button type="submit" variant="primary">Read them from Lazada</x-ui.button>
        </form>
    @endif
</div>

<div class="fm-stamps">
    <span class="fm-stamp">
        <span class="fm-stamp__k">Category</span>
        <span class="fm-stamp__v x-num">{{ $category->category_id }}</span>
    </span>
    <span class="fm-stamp">
        <span class="fm-stamp__k">Region</span>
        <span class="fm-stamp__v">{{ $region !== '' ? $region : 'Not set' }}</span>
    </span>
    <span class="fm-stamp">
        <span class="fm-stamp__k">Last read</span>
        <span class="fm-stamp__v">{{ $template && $template->fetched_at ? $template->fetched_at->format('Y-m-d H:i') : 'Never' }}</span>
    </span>
    <span class="fm-stamp">
        <span class="fm-stamp__k">Attributes</span>
        <span class="fm-stamp__v x-num">{{ count($attributes) }}</span>
    </span>
    @if($requiredCount > 0)
        <span class="fm-stamp">
            <span class="fm-stamp__k">Of those, required</span>
            <span class="fm-stamp__v x-num">{{ $requiredCount }}</span>
        </span>
    @endif
</div>

@if(empty($attributes))
    <x-ui.empty title="Nothing cached for this category yet"
                :description="$canManageLazada
                    ? 'Read them from Lazada and they will be listed here, ready to map on a product group.'
                    : 'Someone with the Lazada manage permission needs to read them from Lazada first.'" />
@else
    <x-ui.table>
        <x-slot:head>
            <tr>
                <th scope="col">Attribute</th>
                <th scope="col" class="cc-col-flag">Required</th>
                <th scope="col" class="cc-col-stat">Type</th>
                <th scope="col" class="cc-col-count x-td-num">Choices</th>
            </tr>
        </x-slot:head>
        @foreach($attributes as $attribute)
            <tr>
                <td data-label="Attribute">
                    <span class="co-item__name">{{ $attribute['name'] }}</span>
                    <span class="cc-sub cc-sub--mono">{{ $attribute['key'] }}</span>
                </td>
                <td class="cc-col-flag" data-label="Required">
                    @if($attribute['required'])
                        <span class="x-cell-strong">Required</span>
                    @else
                        <span class="x-cell-muted">Optional</span>
                    @endif
                </td>
                <td class="cc-col-stat" data-label="Type">{{ $attribute['input_type'] }}</td>
                <td class="cc-col-count x-td-num" data-label="Choices">
                    @if(!empty($attribute['options']))
                        <span class="x-num">{{ count($attribute['options']) }}</span>
                    @else
                        <span class="x-cell-muted">Free text</span>
                    @endif
                </td>
            </tr>
        @endforeach
    </x-ui.table>
@endif

<section class="fm-section">
    <div class="fm-section__head">
        <h2 class="fm-section__title">If the list looks wrong</h2>
    </div>

    <details class="od-disclose">
        <summary>Exactly what Lazada sent back</summary>
        @if($template && $template->template_body)
            <pre class="cl-json">{{ json_encode($template->template_body, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) }}</pre>
        @else
            <p class="fm-section__note">Nothing cached yet.</p>
        @endif
    </details>

    <details class="od-disclose">
        <summary>The last few times we asked ({{ $logs->count() }})</summary>
        @if($logs->isEmpty())
            <p class="fm-section__note">No calls recorded yet.</p>
        @else
            <x-ui.table>
                <x-slot:head>
                    <tr>
                        <th scope="col" class="cc-col-when">When</th>
                        <th scope="col" class="cc-col-flag--sm">Result</th>
                        <th scope="col" class="cc-col-stat">Status</th>
                        <th scope="col">What we asked for</th>
                    </tr>
                </x-slot:head>
                @foreach($logs as $log)
                    <tr>
                        <td class="cc-col-when" data-label="When"><span class="x-num">{{ $log->created_at }}</span></td>
                        <td class="cc-col-flag--sm" data-label="Result">
                            @if($log->ok)
                                <x-ui.badge tone="success">OK</x-ui.badge>
                            @else
                                <x-ui.badge tone="danger">Failed</x-ui.badge>
                            @endif
                        </td>
                        <td class="cc-col-stat" data-label="Status"><span class="x-num">{{ $log->response_status ?? 'None' }}</span></td>
                        <td data-label="What we asked for">
                            <pre class="cl-json">{{ json_encode($log->request_params, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) }}</pre>
                        </td>
                    </tr>
                @endforeach
            </x-ui.table>
        @endif
    </details>
</section>

<div id="lazada-group-progress" class="modal-backdrop">
    <div class="modal co-modal co-modal--sm">
        <div class="cc-progress">
            <span class="co-spinner co-spinner--lg" aria-hidden="true"></span>
            <div>
                <div class="cc-progress__title" data-progress-title>Talking to Lazada</div>
                <div class="cc-progress__note">Please keep this tab open.</div>
            </div>
        </div>
    </div>
</div>

</div>
@endsection
