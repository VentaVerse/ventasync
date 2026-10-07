@props(['caption' => null])

<div class="x-table-wrap">
    <table {{ $attributes->merge(['class' => 'x-table']) }}>
        @if($caption)<caption class="x-sr">{{ $caption }}</caption>@endif
        @isset($head)<thead>{{ $head }}</thead>@endisset
        <tbody>{{ $slot }}</tbody>
    </table>
</div>
