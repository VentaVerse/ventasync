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
        </div>
    </div>

    {{-- Install / Enable / Uninstall actions --}}
    <div class="ext-controls">
        @if($ext['installed'])
            <form method="POST" action="{{ route('extensions.toggle', $ext['id']) }}" style="display:inline;">
                @csrf
                @if($ext['enabled'])
                    <button class="btn small secondary" type="submit">Disable</button>
                @else
                    <button class="btn small" type="submit">Enable</button>
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
