@extends('layouts.channel')
@section('title', 'Apply listing template, ' . $channelName)
@section('breadcrumb', 'Apply listing template')

@section('content')
@php
    $count = count($productIds);
    $fills = array_sum(array_map(fn ($r) => count($r['fills']), $rows));
@endphp
<div class="fm-page lt-apply">
    <div class="fm-head">
        <div class="fm-head__main">
            <a class="fm-back" href="{{ $productsUrl }}">
                <x-ui.icon name="chevron-left" size="14" /> Products
            </a>
            <h1 class="fm-title">Apply a listing template to {{ number_format($count) }} {{ $count === 1 ? 'product' : 'products' }}</h1>
        </div>
    </div>

    <form method="POST" action="{{ $previewUrl }}" class="lt-apply__pick">
        @csrf
        @foreach($productIds as $pid)
            <input type="hidden" name="product_ids[]" value="{{ $pid }}">
        @endforeach
        <x-ui.field label="Listing template" for="lt-pick" name="template_id">
            <select id="lt-pick" name="template_id" class="x-input">
                @foreach($templates as $t)
                    <option value="{{ $t->id }}" @selected((int) $t->id === (int) $template->id)>{{ $t->name }}</option>
                @endforeach
            </select>
        </x-ui.field>
        <x-ui.button type="submit" size="sm">Show changes</x-ui.button>
    </form>

    <form method="POST" action="{{ $applyUrl }}">
        @csrf
        <input type="hidden" name="template_id" value="{{ $template->id }}">
        @foreach($productIds as $pid)
            <input type="hidden" name="product_ids[]" value="{{ $pid }}">
        @endforeach

        @if($rows === [])
            <x-ui.empty title="{{ $template->name }} has no values to apply" />
        @else
            <x-ui.table caption="What {{ $template->name }} changes">
                <thead>
                    <tr>
                        <th scope="col">Field</th>
                        <th scope="col">Template</th>
                        <th scope="col">What changes</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($rows as $key => $r)
                        <tr>
                            <th scope="row" class="lt-apply__field">{{ $r['label'] }}</th>
                            <td>{{ $r['value'] }}</td>
                            <td class="lt-apply__what">
                                @if($r['fills'] !== [])
                                    <span class="lt-apply__fill">Fills {{ count($r['fills']) }}</span>
                                @endif
                                @if($r['matches'] !== [])
                                    <span class="lt-apply__same">{{ count($r['matches']) }} already {{ count($r['matches']) === 1 ? 'matches' : 'match' }}</span>
                                @endif
                                @if($r['own'] !== [])
                                    <span class="lt-apply__own">{{ count($r['own']) }} {{ count($r['own']) === 1 ? 'has its own' : 'have their own' }}</span>
                                    <ul class="lt-apply__list">
                                        @foreach($r['own'] as $o)
                                            <li class="lt-apply__item">
                                                <span><span class="lt-apply__name">{{ $o['name'] }}</span> <span class="lt-apply__ownval">{{ $o['own'] }}</span></span>
                                                <span class="lt-apply__choice" role="radiogroup" aria-label="{{ $r['label'] }} for {{ $o['name'] }}">
                                                    <label><input type="radio" name="replace[{{ $key }}][{{ $o['id'] }}]" value="keep" checked> Keep</label>
                                                    <label><input type="radio" name="replace[{{ $key }}][{{ $o['id'] }}]" value="replace"> Replace</label>
                                                </span>
                                            </li>
                                        @endforeach
                                    </ul>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </x-ui.table>
        @endif

        <x-ui.form-bar :cancel="$productsUrl">
            <x-slot:note>{{ number_format($count) }} {{ $count === 1 ? 'product' : 'products' }} · {{ number_format($fills) }} {{ $fills === 1 ? 'blank to fill' : 'blanks to fill' }}</x-slot:note>
            <x-slot:primary>
                <x-ui.button type="submit" variant="primary" :disabled="$rows === []">Apply to {{ number_format($count) }} {{ $count === 1 ? 'product' : 'products' }}</x-ui.button>
            </x-slot:primary>
        </x-ui.form-bar>
    </form>
</div>
@endsection
