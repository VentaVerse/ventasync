@props(['keys' => null, 'portal' => null, 'portalName' => null])
@php($docs = config('app.docs_url'))
<dl class="cs-source" aria-label="Where to get the keys">
    @if($keys && $portal)
        <div class="cs-source__row">
            <dt class="cs-source__label">Get your keys</dt>
            <dd class="cs-source__value">
                <a class="cs-source__link" href="{{ $portal }}" target="_blank" rel="noopener">
                    <span class="cs-source__name">{{ $portalName }}</span>
                    <span class="cs-source__tail"><span class="cs-source__url">{{ preg_replace('#^https?://#', '', $portal) }}</span><x-ui.icon name="external-link" size="12" class="cs-source__icon" /></span>
                </a>
                <span class="cs-source__what">issues the {{ $keys }}</span>
            </dd>
        </div>
    @endif
    <div class="cs-source__row">
        <dt class="cs-source__label">Documentation</dt>
        <dd class="cs-source__value">
            <a class="cs-source__link" href="{{ $docs }}" target="_blank" rel="noopener">
                <span class="cs-source__name">VentaSync docs</span>
                <span class="cs-source__tail"><span class="cs-source__url">{{ preg_replace('#^https?://#', '', $docs) }}</span><x-ui.icon name="external-link" size="12" class="cs-source__icon" /></span>
            </a>
            <span class="cs-source__what">setup guides for every channel</span>
        </dd>
    </div>
</dl>
