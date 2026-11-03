@vite(['resources/css/blotter.css', 'resources/js/app.js'])
@foreach(\App\Extensions\ExtensionAssets::styles() as $url)
    <link rel="stylesheet" href="{{ $url }}">
@endforeach
@foreach(\App\Extensions\ExtensionAssets::scripts() as $url)
    <script type="module" src="{{ $url }}"></script>
@endforeach
@vite(['resources/js/alpine-start.js'])
