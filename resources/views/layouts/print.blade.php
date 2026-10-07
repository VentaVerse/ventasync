<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    @php
        $__companyName = $appSetting->company_name ?? 'VentaSync';
    @endphp
    <title>@yield('title', 'Print') - {{ $__companyName }}</title>
    @vite(['resources/css/blotter.css'])
</head>
<body class="pl-doc">
@yield('content')

<div class="pl-actions">
    <button type="button" class="pl-btn pl-btn--primary" onclick="window.print()">Print</button>
    <a class="pl-btn" href="{{ url()->previous() }}">Back to the list</a>
</div>
</body>
</html>
