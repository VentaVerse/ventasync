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

<div class="lic-row" id="{{ $ext['id'] }}">
    <div class="lic-row-head">
        <div>
            <h3 class="lic-row-title">
                {{ $ext['name'] }}
                <span class="ext-version">v{{ $ext['version'] }}</span>
            </h3>
            @if($ext['description'])
                <p class="lic-row-sub">{{ $ext['description'] }}</p>
            @endif
            @if($ext['author'])
                <p class="lic-row-sub" style="margin-top:2px;">by {{ $ext['author'] }}</p>
            @endif
        </div>
        <div style="display:flex; gap:6px; flex-wrap:wrap; align-items:center;">
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

    {{-- License details + activation form, only for license_required extensions --}}
    @if($ext['license_required'] && $ext['installed'])
        @if($license)
            <div class="lic-kv">
                <div>
                    <div class="lic-kv-key">License Key</div>
                    <div class="lic-kv-val">{{ $license->maskedKey() }}</div>
                </div>
                <div>
                    <div class="lic-kv-key">Bound Domain</div>
                    <div class="lic-kv-val">{{ $license->domain }}</div>
                </div>
                <div>
                    <div class="lic-kv-key">Type</div>
                    <div class="lic-kv-val soft">{{ ucfirst($license->license_type) }}</div>
                </div>
                @if($license->maintenance_expires_at)
                    <div>
                        <div class="lic-kv-key">Support &amp; Updates</div>
                        <div class="lic-kv-val soft">
                            @if($license->maintenanceExpired())
                                <span style="color:#b45309; font-weight:600;">Expired {{ $license->maintenance_expires_at->format('M j, Y') }}</span>
                            @else
                                Until {{ $license->maintenance_expires_at->format('M j, Y') }}
                            @endif
                        </div>
                    </div>
                @endif
                <div>
                    <div class="lic-kv-key">Last Validated</div>
                    <div class="lic-kv-val soft">{{ $license->last_validated_at?->diffForHumans() ?? '—' }}</div>
                </div>
            </div>

            @if(strtolower($license->domain) !== strtolower($domain))
                <div class="alert danger" style="margin-bottom:12px;">
                    This license was issued for <strong>{{ $license->domain }}</strong> but you are on <strong>{{ $domain }}</strong>.
                    Contact support to move the license to this domain.
                </div>
            @endif
        @endif

        <div class="lic-actions">
            <form method="POST" action="{{ route('licenses.activate', $ext['id']) }}" class="lic-form">
                @csrf
                <input type="text" name="license_key" placeholder="Enter or replace license key" autocomplete="off" spellcheck="false" required>
                <button type="submit" class="btn">{{ $license ? 'Replace Key' : 'Activate' }}</button>
            </form>

            @if($license)
                <form method="POST" action="{{ route('licenses.revalidate', $ext['id']) }}">
                    @csrf
                    <button type="submit" class="btn secondary">Re-validate</button>
                </form>
            @endif
        </div>
    @endif

    {{-- Install / Enable / Uninstall actions --}}
    <div class="ext-controls">
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
