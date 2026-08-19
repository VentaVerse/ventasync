<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    @php
        // The same fallback chain every other layout uses: the shared
        // $appSetting row, then the product name. NOT config('app.name'),
        // which is still "Laravel" in this install and would put it in the
        // browser tab of a document the warehouse prints.
        $__companyName = $appSetting->company_name ?? 'VentaSync';
    @endphp
    <title>@yield('title', 'Print') - {{ $__companyName }}</title>
    {{-- The app's bundle, not a copy of it inside the template. These pages
         are served to a signed-in browser like any other, so they can load
         the same stylesheet everything else does; the print vocabulary lives
         in pages/print-lists.css. The two single-order lists that predate
         this carry their CSS inline, which is what this replaces. --}}
    @vite(['resources/css/blotter.css'])
</head>
<body class="pl-doc">
@yield('content')

{{-- Offered once, above the sheets, and hidden by the print stylesheet so it
     never lands on paper. --}}
<div class="pl-actions">
    <button type="button" class="pl-btn pl-btn--primary" onclick="window.print()">Print</button>
    <a class="pl-btn" href="{{ url()->previous() }}">Back to the list</a>
</div>
</body>
</html>
