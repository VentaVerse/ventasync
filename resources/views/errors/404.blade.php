<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Page not found - VentaSync</title>
    <style>
        body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; background: #f8fafc; color: #0f172a; margin: 0; min-height: 100vh; display: flex; align-items: center; justify-content: center; padding: 24px; }
        .card { background: #fff; max-width: 560px; width: 100%; border-radius: 16px; box-shadow: 0 10px 40px rgba(15, 23, 42, 0.08); padding: 40px; border: 1px solid #e2e8f0; }
        .icon { width: 56px; height: 56px; border-radius: 16px; background: #dbeafe; color: #1d4ed8; display: flex; align-items: center; justify-content: center; font-size: 18px; font-weight: 700; letter-spacing: -0.5px; margin-bottom: 20px; }
        h1 { font-size: 24px; margin: 0 0 12px; font-weight: 700; }
        p { color: #475569; line-height: 1.6; margin: 0 0 16px; }
        code { background: #f1f5f9; padding: 2px 8px; border-radius: 6px; font-size: 13px; font-family: ui-monospace, "SF Mono", Menlo, monospace; word-break: break-all; }
        .actions { display: flex; gap: 12px; margin-top: 28px; flex-wrap: wrap; }
        .btn { display: inline-block; padding: 12px 20px; border-radius: 10px; text-decoration: none; font-weight: 600; font-size: 14px; transition: all 0.15s; border: none; cursor: pointer; font-family: inherit; }
        .btn-primary { background: #3b82f6; color: #fff; }
        .btn-primary:hover { background: #2563eb; }
        .btn-secondary { background: #f1f5f9; color: #475569; }
        .btn-secondary:hover { background: #e2e8f0; }
    </style>
</head>
<body>
    <div class="card">
        <div class="icon">404</div>
        <h1>Page not found</h1>
        <p>We couldn't find <code>{{ request()->getPathInfo() }}</code>.</p>
        <p>The link may be outdated, or the page may have moved.</p>
        <div class="actions">
            <button type="button" id="backBtn" class="btn btn-primary">&larr; Back</button>
            <a href="{{ url('/') }}" class="btn btn-secondary">Home</a>
        </div>
    </div>
    <script>
        document.getElementById('backBtn').addEventListener('click', function () {
            if (window.history.length > 1) {
                window.history.back();
            } else {
                window.location.href = '{{ url('/') }}';
            }
        });
    </script>
</body>
</html>
