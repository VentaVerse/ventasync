@php
    $pillClass = 'unactivated';
    $pillLabel = 'Unlicensed';
    $license = $ext['_license'] ?? null;

    if (empty($ext['license_required'])) {
        $pillClass = 'licensed';
        $pillLabel = 'Free';
    } elseif ($license) {
        if ($license->is_revoked) {
            $pillClass = 'revoked';
            $pillLabel = 'Revoked';
        } elseif ($license->isExpired()) {
            $pillClass = 'expired';
            $pillLabel = 'Expired';
        } elseif ($license->isTrial()) {
            $pillClass = 'trial';
            $pillLabel = 'Trial';
        } elseif ($ext['_is_licensed'] ?? false) {
            $pillClass = 'licensed';
            $pillLabel = 'Licensed';
        }
    }

    $canEnable = !$ext['license_required'] || ($ext['_is_licensed'] ?? false);
@endphp

<div class="ext-row" id="{{ $ext['id'] }}">
    <div class="ext-row-head">
        <div class="ext-row-title-block">
            <div class="ext-row-titleline">
                <h3 class="ext-row-title">{{ $ext['name'] }}</h3>
                <span class="ext-version">v{{ $ext['version'] }}</span>
                @if($ext['author'])
                    <span class="ext-author">by {{ $ext['author'] }}</span>
                @endif
            </div>
            @if($ext['description'])
                <p class="ext-desc">{{ $ext['description'] }}</p>
            @endif
        </div>
        <div class="ext-row-pills">
            @if($ext['installed'])
                @if($ext['enabled'])
                    <span class="lic-pill licensed"><span class="lic-pill-dot"></span>Enabled</span>
                @else
                    <span class="lic-pill unactivated"><span class="lic-pill-dot"></span>Disabled</span>
                @endif
            @else
                <span class="lic-pill unactivated"><span class="lic-pill-dot"></span>Not Installed</span>
            @endif
            <span class="lic-pill {{ $pillClass }}"><span class="lic-pill-dot"></span>{{ $pillLabel }}</span>
        </div>
    </div>

    @if($ext['license_required'] && $ext['installed'] && $license)
        <div class="ext-meta">
            <span class="ext-meta-item"><span class="ext-meta-label">Key</span> <code>{{ $license->maskedKey() }}</code></span>
            <span class="ext-meta-item"><span class="ext-meta-label">Domain</span> <code>{{ $license->domain }}</code></span>
            <span class="ext-meta-item">{{ ucfirst($license->license_type) }}</span>
            @if($license->isTrial() || ($license->expires_at && $license->expires_at->year < 2100))
                <span class="ext-meta-item">Expires {{ $license->expires_at->format('M j, Y') }}</span>
            @endif
            @if($license->maintenance_expires_at)
                @if($license->maintenanceExpired())
                    <span class="ext-meta-item maint-expired">Support ended {{ $license->maintenance_expires_at->format('M j, Y') }}</span>
                @else
                    <span class="ext-meta-item">Support until {{ $license->maintenance_expires_at->format('M j, Y') }}</span>
                @endif
            @endif
            <span class="ext-meta-item soft">Activated {{ $license->activated_at?->format('M j, Y') ?? '—' }}</span>
        </div>

        @if(strtolower($license->domain) !== strtolower($domain))
            <div class="ext-domain-warn">
                License bound to <strong>{{ $license->domain }}</strong> but you're on <strong>{{ $domain }}</strong>. Contact support to move it.
            </div>
        @endif
    @endif

    <div class="ext-row-actions">
        @if($ext['license_required'] && $ext['installed'])
            <form method="POST" action="{{ route('licenses.activate', $ext['id']) }}" class="ext-activate-form">
                @csrf
                <input type="text" name="license_key" placeholder="{{ $license ? 'Replace with new key' : 'Enter license key' }}" autocomplete="off" spellcheck="false" required>
                <button type="submit" class="btn small">{{ $license ? 'Replace' : 'Activate' }}</button>
            </form>
            @if($license)
                <form method="POST" action="{{ route('licenses.revalidate', $ext['id']) }}">
                    @csrf
                    <button type="submit" class="btn small secondary">Re-validate</button>
                </form>
            @endif
            <span class="ext-actions-spacer"></span>
        @endif

        @if($ext['installed'])
            <form method="POST" action="{{ route('extensions.toggle', $ext['id']) }}" style="display:inline;">
                @csrf
                @if($ext['enabled'])
                    <button class="btn small secondary" type="submit">Disable</button>
                @elseif($canEnable)
                    <button class="btn small" type="submit">Enable</button>
                @else
                    <button class="btn small" type="button" disabled title="Activate the license before enabling this extension.">Enable</button>
                @endif
            </form>
            <form method="POST" action="{{ route('extensions.uninstall', $ext['id']) }}" data-confirm="Uninstall {{ $ext['name'] }}? All extension data and database tables will be deleted. Files will be kept so you can reinstall later." style="display:inline;">
                @csrf
                @method('DELETE')
                <button class="btn danger small" type="submit">Uninstall</button>
            </form>
        @else
            <form method="POST" action="{{ route('extensions.reinstall', $ext['id']) }}" style="display:inline;">
                @csrf
                <button class="btn small" type="submit">Install</button>
            </form>
        @endif
    </div>
</div>
