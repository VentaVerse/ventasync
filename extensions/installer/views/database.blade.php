@extends('ext-installer::layout')

@section('title', 'Database')

@section('content')
    <div class="content-header">
        <h1 class="content-title">Database connection</h1>
        <p class="content-subtitle">Provide MySQL credentials. We'll test the connection, write your <code>.env</code>, and run the schema migrations.</p>
    </div>

    <div class="content-body">

        @if($errors->any())
            <div class="alert alert-danger">
                @foreach($errors->all() as $error)
                    <div>{{ $error }}</div>
                @endforeach
            </div>
        @endif

        <form method="POST" action="{{ route('install.database') }}" id="dbForm" class="step-form">
            @csrf

            <div class="form-grid form-grid-2">
                <div class="form-field">
                    <label for="db_host">Host</label>
                    <input id="db_host" type="text" name="host" value="{{ old('host', '127.0.0.1') }}" required>
                    <div class="hint">Usually <code>localhost</code> or <code>127.0.0.1</code> on shared hosting.</div>
                </div>
                <div class="form-field">
                    <label for="db_port">Port</label>
                    <input id="db_port" type="number" name="port" value="{{ old('port', 3306) }}" min="1" max="65535" required>
                </div>
            </div>

            <div class="form-field">
                <label for="db_database">Database name</label>
                <input id="db_database" type="text" name="database" value="{{ old('database') }}" required placeholder="agila_suites">
                <div class="hint">Create this database first in your hosting panel (cPanel: MySQL Databases · HestiaCP: Databases · Plesk: Databases).</div>
            </div>

            <div class="form-grid form-grid-2">
                <div class="form-field">
                    <label for="db_username">Username</label>
                    <input id="db_username" type="text" name="username" value="{{ old('username') }}" required>
                </div>
                <div class="form-field">
                    <label for="db_password">Password</label>
                    <input id="db_password" type="password" name="password" value="{{ old('password') }}" autocomplete="new-password">
                    <div class="hint">Leave blank if your DB user has no password.</div>
                </div>
            </div>

            <div class="db-actions">
                <button type="button" class="btn btn-secondary" id="btnTestDb">
                    <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="margin-right: 6px;">
                        <polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/>
                    </svg>
                    Test connection
                </button>
                <div id="dbTestResult" class="db-test-result"></div>
            </div>

            <div class="alert alert-info" style="margin-top: 18px;">
                <strong>Heads up:</strong> Clicking <em>Continue</em> below writes your credentials to <code>.env</code> and runs ~80 migrations. This can take 30-90 seconds. Don't close the browser tab.
            </div>

            <div class="step-actions step-actions-split">
                <a href="{{ route('install.requirements') }}" class="btn btn-secondary">
                    <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="margin-right: 6px;">
                        <line x1="19" y1="12" x2="5" y2="12"/>
                        <polyline points="12 19 5 12 12 5"/>
                    </svg>
                    Back
                </a>
                <button type="submit" class="btn btn-primary" id="dbSubmitBtn">
                    Continue &amp; run migrations
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
(function() {
    const testBtn = document.getElementById('btnTestDb');
    const resultEl = document.getElementById('dbTestResult');
    const form = document.getElementById('dbForm');
    const submitBtn = document.getElementById('dbSubmitBtn');

    function getFormPayload() {
        return {
            host: form.querySelector('[name=host]').value,
            port: parseInt(form.querySelector('[name=port]').value, 10),
            database: form.querySelector('[name=database]').value,
            username: form.querySelector('[name=username]').value,
            password: form.querySelector('[name=password]').value,
        };
    }

    testBtn.addEventListener('click', async () => {
        resultEl.textContent = 'Testing...';
        resultEl.className = 'db-test-result db-test-pending';
        testBtn.disabled = true;

        try {
            const r = await fetch(@json(route('install.database.test')), {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content,
                    'X-Requested-With': 'XMLHttpRequest',
                },
                body: JSON.stringify(getFormPayload()),
            });
            const data = await r.json();
            if (r.ok && data.ok) {
                resultEl.textContent = '✓ ' + data.message;
                resultEl.className = 'db-test-result db-test-ok';
            } else {
                resultEl.textContent = '✗ ' + (data.message || 'Connection failed');
                resultEl.className = 'db-test-result db-test-fail';
            }
        } catch (err) {
            resultEl.textContent = '✗ Network error: ' + err.message;
            resultEl.className = 'db-test-result db-test-fail';
        } finally {
            testBtn.disabled = false;
        }
    });

    // Show a loading state on submit — migrations take 30-90s
    form.addEventListener('submit', () => {
        submitBtn.disabled = true;
        const original = submitBtn.textContent;
        submitBtn.textContent = 'Running migrations… please wait';
        // Don't return false — let the form submit normally
    });
})();
</script>
@endpush
@endsection
