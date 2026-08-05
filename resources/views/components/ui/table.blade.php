@props([])

<div class="x-table-wrap">
    <table {{ $attributes->merge(['class' => 'x-table']) }}>
        @isset($head)<thead>{{ $head }}</thead>@endisset
        <tbody>{{ $slot }}</tbody>
    </table>
</div>
