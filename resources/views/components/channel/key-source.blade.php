@props(['keys' => null, 'portal' => null, 'portalName' => null])
<p class="fm-section__note">@if($keys && $portal)Get the {{ $keys }} from <a href="{{ $portal }}" target="_blank" rel="noopener">{{ $portalName }}</a>. @endif Documentation: <a href="{{ config('app.docs_url') }}" target="_blank" rel="noopener">VentaSync docs</a>.</p>
