@php
    $isDbError = false;
    if (isset($exception)) {
        $message = method_exists($exception, 'getMessage') ? (string) $exception->getMessage() : '';
        $isDbError = str_contains($message, 'SQLSTATE') ||
                     str_contains($message, 'PDOException') ||
                     str_contains($message, 'Access denied for user') ||
                     str_contains($message, 'could not find driver') ||
                     str_contains($message, "Database connection") ||
                     str_contains(get_class($exception), 'QueryException') ||
                     str_contains(get_class($exception), 'PDOException');
    }
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Something went wrong - VentaSync</title>
    <style>
        body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; background: #f8fafc; color: #0f172a; margin: 0; min-height: 100vh; display: flex; align-items: center; justify-content: center; padding: 24px; }
        .card { background: #fff; max-width: 560px; width: 100%; border-radius: 16px; box-shadow: 0 10px 40px rgba(15, 23, 42, 0.08); padding: 40px; border: 1px solid #e2e8f0; }
        .icon { width: 56px; height: 56px; border-radius: 16px; background: #fef3c7; color: #b45309; display: flex; align-items: center; justify-content: center; font-size: 28px; margin-bottom: 20px; }
        h1 { font-size: 24px; margin: 0 0 12px; font-weight: 700; }
        p { color: #475569; line-height: 1.6; margin: 0 0 16px; }
        code { background: #f1f5f9; padding: 2px 8px; border-radius: 6px; font-size: 13px; font-family: ui-monospace, "SF Mono", Menlo, monospace; }
        .actions { display: flex; gap: 12px; margin-top: 28px; flex-wrap: wrap; }
        .btn { display: inline-block; padding: 12px 20px; border-radius: 10px; text-decoration: none; font-weight: 600; font-size: 14px; transition: all 0.15s; }
        .btn-primary { background: #3b82f6; color: #fff; }
        .btn-primary:hover { background: #2563eb; }
        .btn-secondary { background: #f1f5f9; color: #475569; }
        .btn-secondary:hover { background: #e2e8f0; }
        .details { margin-top: 24px; padding-top: 20px; border-top: 1px solid #e2e8f0; font-size: 13px; color: #64748b; }
        .details summary { cursor: pointer; font-weight: 600; color: #475569; }
        .details pre { background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px; padding: 12px; font-size: 12px; overflow-x: auto; margin-top: 12px; }
    </style>
</head>
<body>
    <div class="card">
        @if ($isDbError)
            <div class="icon">🗄</div>
            <h1>Database connection problem</h1>
            <p>VentaSync couldn't reach the database. This usually means one of:</p>
            <ul style="color: #475569; line-height: 1.7; padding-left: 20px;">
                <li>Database credentials in <code>.env</code> are wrong or missing</li>
                <li>The MySQL service on this server isn't running</li>
                <li>The database hasn't been migrated yet (<code>php artisan migrate --seed</code>)</li>
            </ul>
        @else
            <div class="icon">⚠</div>
            <h1>Something went wrong</h1>
            <p>VentaSync encountered an internal error.</p>
            <p>If this is the first time you've seen this, try refreshing the page. If it persists, check <code>storage/logs/laravel.log</code> for the full stack trace.</p>
            <div class="actions">
                <a href="/" class="btn btn-primary">Try home page</a>
            </div>
        @endif

        @if (config('app.debug') && isset($exception))
            <details class="details">
                <summary>Technical details (debug mode)</summary>
                <pre>{{ get_class($exception) }}: {{ $exception->getMessage() }}

at {{ $exception->getFile() }}:{{ $exception->getLine() }}</pre>
            </details>
        @endif
    </div>
</body>
</html>
