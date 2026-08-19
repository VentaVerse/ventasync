@props(['caption' => null])

{{-- A table says what it is a table OF.
     Every list in this product shipped without a caption or any accessible
     name, so a screen reader announced "table, 7 columns, 21 rows" and left
     the user to infer the rest from the column headers. On a page carrying
     one table that is recoverable; the point of the caption is that it costs
     nothing and removes the inference.

     Visually hidden rather than drawn, because the page already says what the
     list is - in the h1, the breadcrumb and the active tab - and drawing it
     again would be repeating a heading. --}}
<div class="x-table-wrap">
    <table {{ $attributes->merge(['class' => 'x-table']) }}>
        @if($caption)<caption class="x-sr">{{ $caption }}</caption>@endif
        @isset($head)<thead>{{ $head }}</thead>@endisset
        <tbody>{{ $slot }}</tbody>
    </table>
</div>
