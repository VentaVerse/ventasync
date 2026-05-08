@extends('ext-installer::layout')

@section('title', 'Server Requirements')

@section('content')
    <div class="content-header">
        <h1 class="content-title">Server Requirements</h1>
        <p class="content-subtitle">We're checking your server can run VentaSync smoothly. Items in green are ready; red items need to be addressed before you can continue.</p>
    </div>

    <div class="content-body" id="reqContent" data-checks='@json($checks)'>

        <div class="check-summary" id="reqSummary">
            <div class="summary-item">
                <div class="summary-label">PHP Version</div>
                <div class="summary-value" id="sumPhp">{{ $checks['php']['value'] }}</div>
            </div>
            <div class="summary-item">
                <div class="summary-label">Extensions</div>
                <div class="summary-value" id="sumExt">{{ collect($checks['extensions'])->where('ok', true)->count() }} / {{ count($checks['extensions']) }}</div>
            </div>
            <div class="summary-item">
                <div class="summary-label">Permissions</div>
                <div class="summary-value" id="sumPerm">{{ collect($checks['permissions'])->where('ok', true)->count() }} / {{ count($checks['permissions']) }}</div>
            </div>
            <button type="button" class="recheck-btn" onclick="recheckRequirements()">
                <svg class="recheck-icon" viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <polyline points="1 4 1 10 7 10"/>
                    <path d="M3.51 15a9 9 0 1 0 2.13-9.36L1 10"/>
                </svg>
                Re-check
            </button>
        </div>

        <div class="check-section">
            <h3 class="section-heading">PHP &amp; database</h3>
            <ul class="check-list" id="checkPhp">
                <li class="check-item {{ $checks['php']['ok'] ? 'ok' : 'fail' }}">
                    <span class="check-status">{!! $checks['php']['ok'] ? '✓' : '✗' !!}</span>
                    <span class="check-name">PHP</span>
                    <span class="check-value">{{ $checks['php']['value'] }} <span class="check-required">(required: {{ $checks['php']['required'] }})</span></span>
                </li>
                <li class="check-item {{ $checks['mysql']['ok'] ? 'ok' : 'fail' }}">
                    <span class="check-status">{!! $checks['mysql']['ok'] ? '✓' : '✗' !!}</span>
                    <span class="check-name">MySQL driver</span>
                    <span class="check-value">{{ $checks['mysql']['message'] }}</span>
                </li>
            </ul>
        </div>

        <div class="check-section">
            <h3 class="section-heading">PHP extensions <span class="section-heading-subtle">— required</span></h3>
            <ul class="check-list check-list-grid" id="checkExt">
                @foreach($checks['extensions'] as $ext)
                    @if($ext['severity'] === 'required')
                        <li class="check-item {{ $ext['ok'] ? 'ok' : 'fail' }}" title="{{ $ext['reason'] ?? '' }}">
                            <span class="check-status">{!! $ext['ok'] ? '✓' : '✗' !!}</span>
                            <span class="check-name">{{ $ext['name'] }}</span>
                        </li>
                    @endif
                @endforeach
            </ul>
        </div>

        @php $recommended = collect($checks['extensions'])->where('severity', 'recommended'); @endphp
        @if($recommended->count() > 0)
            <div class="check-section">
                <h3 class="section-heading">PHP extensions <span class="section-heading-subtle">— recommended</span></h3>
                <ul class="check-list" id="checkExtRecommended">
                    @foreach($recommended as $ext)
                        <li class="check-item {{ $ext['ok'] ? 'ok' : 'warn' }}">
                            <span class="check-status">{!! $ext['ok'] ? '✓' : '!' !!}</span>
                            <span class="check-name">{{ $ext['name'] }}</span>
                            <span class="check-value">
                                @if(!$ext['ok'])
                                    Optional — {{ $ext['reason'] ?? '' }}
                                @endif
                            </span>
                        </li>
                    @endforeach
                </ul>
            </div>
        @endif

        <div class="check-section">
            <h3 class="section-heading">File permissions</h3>
            <ul class="check-list" id="checkPerm">
                @foreach($checks['permissions'] as $perm)
                    <li class="check-item {{ $perm['ok'] ? 'ok' : 'fail' }}">
                        <span class="check-status">{!! $perm['ok'] ? '✓' : '✗' !!}</span>
                        <span class="check-name">{{ $perm['path'] }}</span>
                        <span class="check-value">{{ $perm['ok'] ? 'writable' : 'NOT writable' }}</span>
                    </li>
                @endforeach
            </ul>
        </div>

        @unless($checks['all_ok'])
            <div class="alert alert-danger" style="margin-top: 16px;">
                <strong>Some checks failed.</strong> Fix the items marked in red, then click <em>Re-check</em>. If you need help, see the <a href="https://example.com/install-guides" target="_blank" rel="noopener">install guides</a> for your hosting provider.
            </div>
        @endunless

        <form method="GET" action="{{ route('install.database') }}" class="step-form">
            <div class="step-actions step-actions-split">
                <a href="{{ route('install.welcome') }}" class="btn btn-secondary">
                    <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="margin-right: 6px;">
                        <line x1="19" y1="12" x2="5" y2="12"/>
                        <polyline points="12 19 5 12 12 5"/>
                    </svg>
                    Back
                </a>
                <button type="submit" class="btn btn-primary" id="reqContinueBtn" {{ $checks['all_ok'] ? '' : 'disabled' }}>
                    Continue
                    <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="margin-left: 6px;">
                        <line x1="5" y1="12" x2="19" y2="12"/>
                        <polyline points="12 5 19 12 12 19"/>
                    </svg>
                </button>
            </div>
        </form>

    </div>

@push('scripts')
<script>
    function recheckRequirements() {
        const btn = document.querySelector('.recheck-btn');
        const icon = btn.querySelector('.recheck-icon');
        btn.disabled = true;
        icon.style.transform = 'rotate(360deg)';

        fetch(@json(route('install.requirements.check')), {
            headers: {
                'Accept': 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
            },
        })
        .then(r => r.json())
        .then(checks => {
            renderRequirements(checks);
        })
        .catch(err => {
            console.error('Re-check failed', err);
        })
        .finally(() => {
            btn.disabled = false;
            setTimeout(() => { icon.style.transform = ''; }, 500);
        });
    }

    function renderRequirements(checks) {
        // Update summary
        document.getElementById('sumPhp').textContent = checks.php.value;
        const extOk = checks.extensions.filter(e => e.ok).length;
        document.getElementById('sumExt').textContent = `${extOk} / ${checks.extensions.length}`;
        const permOk = checks.permissions.filter(p => p.ok).length;
        document.getElementById('sumPerm').textContent = `${permOk} / ${checks.permissions.length}`;

        // Update PHP/MySQL list (rebuild via DOM, no innerHTML)
        const phpList = document.getElementById('checkPhp');
        phpList.replaceChildren(
            buildCheckItem(checks.php.ok, 'PHP', `${checks.php.value} (required: ${checks.php.required})`),
            buildCheckItem(checks.mysql.ok, 'MySQL driver', checks.mysql.message),
        );

        const extList = document.getElementById('checkExt');
        extList.replaceChildren(...checks.extensions.map(e => buildCheckItem(e.ok, e.name, '')));

        const permList = document.getElementById('checkPerm');
        permList.replaceChildren(...checks.permissions.map(p => buildCheckItem(p.ok, p.path, p.ok ? 'writable' : 'NOT writable')));

        // Enable/disable continue button
        document.getElementById('reqContinueBtn').disabled = !checks.all_ok;
    }

    function buildCheckItem(ok, name, value) {
        const li = document.createElement('li');
        li.className = 'check-item ' + (ok ? 'ok' : 'fail');

        const status = document.createElement('span');
        status.className = 'check-status';
        status.textContent = ok ? '✓' : '✗';
        li.appendChild(status);

        const nameEl = document.createElement('span');
        nameEl.className = 'check-name';
        nameEl.textContent = name;
        li.appendChild(nameEl);

        if (value) {
            const valueEl = document.createElement('span');
            valueEl.className = 'check-value';
            valueEl.textContent = value;
            li.appendChild(valueEl);
        }

        return li;
    }
</script>
@endpush
@endsection
