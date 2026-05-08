<?php

namespace App\Http\Controllers;

use App\Http\Middleware\InstallationMiddleware;
use App\Support\EnvFileWriter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\View\View;

/**
 * Web installer wizard. Handles the 6-step setup flow:
 *   1. Welcome   — license acceptance
 *   2. Requirements — live AJAX dependency check
 *   3. Database  — DB credentials + connection test + migration runner
 *   4. Site Config — APP_NAME, APP_URL, timezone, currency, mail
 *   5. Admin   — first admin user creation
 *   6. Complete — done screen with cron snippets, marks installed.lock
 *
 * Step ordering is enforced by the controller (each step redirects
 * forward only if the previous step is complete) but the wizard is
 * mostly stateless — each step writes its result directly to .env or
 * the database, no session-spanning state.
 */
class InstallController extends Controller
{
    /** Redirect / → first step or completion. */
    public function index(): RedirectResponse
    {
        return redirect()->route('install.welcome');
    }

    /** Step 1 — Welcome + License acceptance. */
    public function welcome(): View
    {
        return view('install.welcome', $this->stepContext(1));
    }

    public function welcomeSubmit(Request $request): RedirectResponse
    {
        $request->validate([
            'accept_license' => 'required|accepted',
        ], [
            'accept_license.accepted' => 'You must accept the AGPL-3.0 license to continue.',
        ]);

        return redirect()->route('install.requirements');
    }

    /** Step 2 — Server requirements check. */
    public function requirements(): View
    {
        $checks = $this->runRequirementChecks();
        return view('install.requirements', array_merge($this->stepContext(2), [
            'checks' => $checks,
        ]));
    }

    /** AJAX endpoint for live re-check (button on the requirements page). */
    public function requirementsCheck(): JsonResponse
    {
        return response()->json($this->runRequirementChecks());
    }

    /** Step 3 — Database configuration. */
    public function database(): View
    {
        return view('install.database', $this->stepContext(3));
    }

    public function databaseTest(Request $request): JsonResponse
    {
        $request->validate([
            'host' => 'required|string|max:255',
            'port' => 'required|integer|between:1,65535',
            'database' => 'required|string|max:64',
            'username' => 'required|string|max:128',
            'password' => 'nullable|string|max:255',
        ]);

        try {
            $pdo = new \PDO(
                sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $request->host, $request->port, $request->database),
                $request->username,
                (string) $request->password,
                [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]
            );
            $version = $pdo->query('SELECT VERSION()')->fetchColumn();
            return response()->json(['ok' => true, 'message' => 'Connected to MySQL ' . $version]);
        } catch (\Throwable $e) {
            return response()->json(['ok' => false, 'message' => $e->getMessage()], 422);
        }
    }

    /**
     * Persist DB credentials to .env, generate APP_KEY if missing,
     * apply runtime config so this request's migration uses the new
     * connection, then run `php artisan migrate --force`. On success,
     * redirect to the site config step.
     */
    public function databaseSubmit(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'host' => 'required|string|max:255',
            'port' => 'required|integer|between:1,65535',
            'database' => 'required|string|max:64',
            'username' => 'required|string|max:128',
            'password' => 'nullable|string|max:255',
        ]);

        // Verify connection one more time before persisting
        try {
            $pdo = new \PDO(
                sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $data['host'], $data['port'], $data['database']),
                $data['username'],
                (string) ($data['password'] ?? ''),
                [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]
            );
        } catch (\Throwable $e) {
            return back()
                ->withErrors(['db' => 'Connection failed: ' . $e->getMessage()])
                ->withInput($request->except('password'));
        }

        // Generate APP_KEY if missing (or if it's the Laravel default)
        $appKey = config('app.key');
        if (!$appKey || $appKey === 'SomeRandomString') {
            $appKey = 'base64:' . base64_encode(random_bytes(32));
        }

        // Persist to .env. We escape the password — it may contain special chars.
        EnvFileWriter::set([
            'APP_KEY'       => $appKey,
            'DB_CONNECTION' => 'mysql',
            'DB_HOST'       => $data['host'],
            'DB_PORT'       => (string) $data['port'],
            'DB_DATABASE'   => $data['database'],
            'DB_USERNAME'   => $data['username'],
            'DB_PASSWORD'   => $data['password'] ?? '',
        ]);

        // Apply runtime config so this request's Artisan::call('migrate')
        // uses the new credentials. Without this, Laravel would still be
        // pointed at whatever the .env said when the request started.
        config([
            'app.key' => $appKey,
            'database.connections.mysql.host' => $data['host'],
            'database.connections.mysql.port' => $data['port'],
            'database.connections.mysql.database' => $data['database'],
            'database.connections.mysql.username' => $data['username'],
            'database.connections.mysql.password' => $data['password'] ?? '',
        ]);
        DB::purge('mysql');

        // Run migrations. --force is required outside production env;
        // --no-interaction prevents Artisan from prompting on a TTY-less request.
        try {
            // Increase time budget for the request — fresh installs run ~80
            // migrations and can take 30-90 seconds on slow shared hosting.
            @set_time_limit(300);

            Artisan::call('migrate', [
                '--force' => true,
                '--no-interaction' => true,
            ]);
        } catch (\Throwable $e) {
            return back()
                ->withErrors(['migration' => 'Migration failed: ' . $e->getMessage() . ' — check storage/logs/laravel.log for details'])
                ->withInput($request->except('password'));
        }

        return redirect()->route('install.config')
            ->with('status', 'Database configured and schema migrated.');
    }

    /** Step 4 — Site configuration. */
    public function config(): View
    {
        return view('install.config', $this->stepContext(4));
    }

    /**
     * Persist site config to .env (APP_NAME, APP_URL, timezone) AND to
     * the settings table (company_name, timezone, optional mail). The
     * settings row is the source of truth for runtime config; .env is
     * the seed value for fresh boots.
     */
    public function configSubmit(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'company_name' => 'required|string|max:128',
            'company_email' => 'nullable|email|max:255',
            'app_url' => 'required|url|max:255',
            'timezone' => 'required|string|max:64',
            'mail_host' => 'nullable|string|max:255',
            'mail_port' => 'nullable|integer|between:1,65535',
            'mail_username' => 'nullable|string|max:255',
            'mail_password' => 'nullable|string|max:255',
            'mail_encryption' => 'nullable|in:tls,ssl,',
            'mail_from_address' => 'nullable|email|max:255',
            'mail_from_name' => 'nullable|string|max:128',
        ]);

        // Update .env — APP_NAME and APP_URL are the customer-facing brand
        EnvFileWriter::set([
            'APP_NAME' => $data['company_name'],
            'APP_URL' => rtrim($data['app_url'], '/'),
            'APP_TIMEZONE' => $data['timezone'],
            'MAIL_MAILER' => $data['mail_host'] ? 'smtp' : 'log',
            'MAIL_HOST' => $data['mail_host'] ?? '',
            'MAIL_PORT' => $data['mail_port'] ?? '',
            'MAIL_USERNAME' => $data['mail_username'] ?? '',
            'MAIL_PASSWORD' => $data['mail_password'] ?? '',
            'MAIL_ENCRYPTION' => $data['mail_encryption'] ?? '',
            'MAIL_FROM_ADDRESS' => $data['mail_from_address'] ?? '',
            'MAIL_FROM_NAME' => $data['mail_from_name'] ?? $data['company_name'],
        ]);

        // Apply to runtime so subsequent steps see new values
        config([
            'app.name' => $data['company_name'],
            'app.url' => rtrim($data['app_url'], '/'),
            'app.timezone' => $data['timezone'],
        ]);

        // Persist to settings table — the runtime source of truth for the
        // company-info bits the UI displays in the header, login, etc.
        DB::table('settings')->upsert([
            'id' => 1,
            'company_name' => $data['company_name'],
            'company_email' => $data['company_email'] ?? '',
            'timezone' => $data['timezone'],
            'mail_host' => $data['mail_host'] ?? '',
            'mail_port' => $data['mail_port'] ?? null,
            'mail_username' => $data['mail_username'] ?? '',
            'mail_password' => $data['mail_password'] ?? '',
            'mail_encryption' => $data['mail_encryption'] ?? '',
            'mail_from_address' => $data['mail_from_address'] ?? '',
            'mail_from_name' => $data['mail_from_name'] ?? $data['company_name'],
            'mail_mailer' => $data['mail_host'] ? 'smtp' : 'log',
            'updated_at' => now(),
            'created_at' => now(),
        ], ['id'], [
            'company_name', 'company_email', 'timezone',
            'mail_host', 'mail_port', 'mail_username', 'mail_password',
            'mail_encryption', 'mail_from_address', 'mail_from_name', 'mail_mailer',
            'updated_at',
        ]);

        return redirect()->route('install.admin');
    }

    /** Step 5 — Admin user creation. */
    public function admin(): View
    {
        return view('install.admin', $this->stepContext(5));
    }

    /**
     * Create the first admin user with full administrator group access.
     * Replaces the hardcoded `admin / admin@xenon.local` row from
     * DatabaseSeeder.php — the installer is now the source of truth for
     * admin user creation.
     */
    public function adminSubmit(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => 'required|string|max:128',
            'username' => ['required', 'string', 'max:64', 'regex:/^[a-zA-Z0-9_.-]+$/'],
            'email' => 'required|email|max:255',
            'password' => 'required|string|min:8|max:255|confirmed',
        ], [
            'username.regex' => 'Username may contain only letters, numbers, dots, hyphens, and underscores.',
            'password.confirmed' => 'Password and confirmation must match.',
        ]);

        // Ensure an Administrator group exists (the seeder creates it on
        // a fresh migrate, but defend against missing data).
        $adminGroupId = DB::table('user_groups')->where('name', 'Administrator')->value('id');
        if (!$adminGroupId) {
            $adminGroupId = DB::table('user_groups')->insertGetId([
                'name' => 'Administrator',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        // Replace any seeded default user (e.g. admin@xenon.local from
        // DatabaseSeeder) with this real account.
        DB::table('users')->where('username', 'admin')->where('email', 'admin@xenon.local')->delete();

        DB::table('users')->updateOrInsert(
            ['username' => $data['username']],
            [
                'name' => $data['name'],
                'username' => $data['username'],
                'email' => $data['email'],
                'password' => Hash::make($data['password']),
                'user_group_id' => $adminGroupId,
                'email_verified_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );

        return redirect()->route('install.complete');
    }

    /** Step 6 — Done. */
    public function complete(): View
    {
        return view('install.complete', $this->stepContext(6));
    }

    public function completeSubmit(): RedirectResponse
    {
        InstallationMiddleware::markInstalled();
        return redirect('/login');
    }

    /**
     * Provide step rail metadata for any step view.
     */
    protected function stepContext(int $current): array
    {
        $steps = [
            ['name' => 'Welcome',       'desc' => 'License accepted'],
            ['name' => 'Requirements',  'desc' => 'Server scan'],
            ['name' => 'Database',      'desc' => 'Connection setup'],
            ['name' => 'Site Config',   'desc' => 'Company details'],
            ['name' => 'Admin Account', 'desc' => 'First user'],
            ['name' => 'Finish',        'desc' => 'All set'],
        ];

        return [
            'currentStep' => $current,
            'totalSteps' => count($steps),
            'steps' => $steps,
            'progressPercent' => (int) round(($current / count($steps)) * 100),
        ];
    }

    /**
     * Run all server requirement checks in one shot. Used by the
     * Requirements page initial load and the AJAX re-check endpoint.
     *
     * Returns:
     *   [
     *     'php' => ['ok' => bool, 'value' => string, 'required' => string],
     *     'extensions' => [['name' => '...', 'ok' => bool], ...],
     *     'permissions' => [['path' => '...', 'ok' => bool], ...],
     *     'mysql' => ['ok' => bool, 'message' => string],
     *     'all_ok' => bool,
     *   ]
     */
    protected function runRequirementChecks(): array
    {
        $php = [
            'ok' => version_compare(PHP_VERSION, '8.2.0', '>='),
            'value' => PHP_VERSION,
            'required' => '8.2+',
        ];

        // Required: install will block if any of these are missing.
        $requiredExtensions = [
            'pdo_mysql' => 'database driver',
            'mbstring'  => 'Laravel core',
            'openssl'   => 'HTTPS, JWT verification, encryption',
            'json'      => 'JSON columns + API responses (PHP 8 built-in)',
            'curl'      => 'marketplace APIs + license server',
            'zip'       => 'DomPDF + future module installs',
            'dom'       => 'DomPDF',
            'xml'       => 'Laravel core',
            'fileinfo'  => 'file uploads',
            'bcmath'    => 'financial precision',
        ];

        // Recommended: install proceeds without these but specific features are
        // limited. We surface them as warnings, not blockers.
        $recommendedExtensions = [
            'gd' => 'image processing — WebP product image conversion + TikTok thumbnail cropping. Without it, those specific features silently skip.',
        ];

        $extensions = [];
        foreach ($requiredExtensions as $ext => $reason) {
            $extensions[] = [
                'name' => $ext,
                'ok' => extension_loaded($ext),
                'severity' => 'required',
                'reason' => $reason,
            ];
        }
        foreach ($recommendedExtensions as $ext => $reason) {
            $extensions[] = [
                'name' => $ext,
                'ok' => extension_loaded($ext),
                'severity' => 'recommended',
                'reason' => $reason,
            ];
        }

        $writablePaths = [
            base_path('.env'),
            storage_path(),
            base_path('bootstrap/cache'),
        ];
        $permissions = [];
        foreach ($writablePaths as $path) {
            $exists = file_exists($path);
            // .env may not exist yet on a fresh install — check the parent dir then
            $checkPath = $exists ? $path : dirname($path);
            $permissions[] = [
                'path' => str_replace(base_path() . '/', '', $path),
                'ok' => is_writable($checkPath),
            ];
        }

        // PDO MySQL availability (we don't test connection here — that's the
        // database step's job. Just make sure the driver loaded.)
        $mysql = [
            'ok' => in_array('mysql', \PDO::getAvailableDrivers(), true),
            'message' => in_array('mysql', \PDO::getAvailableDrivers(), true)
                ? 'MySQL driver available (will test connection on the next step)'
                : 'MySQL PDO driver not available',
        ];

        // Block install only on REQUIRED failures. Recommended-tier failures
        // get a warning chip but don't prevent Continue.
        $requiredOk = !collect($extensions)
            ->where('severity', 'required')
            ->contains(fn ($e) => !$e['ok']);

        $allOk = $php['ok']
            && $mysql['ok']
            && $requiredOk
            && !collect($permissions)->contains(fn ($p) => !$p['ok']);

        $hasWarnings = collect($extensions)
            ->where('severity', 'recommended')
            ->contains(fn ($e) => !$e['ok']);

        return [
            'php' => $php,
            'extensions' => $extensions,
            'permissions' => $permissions,
            'mysql' => $mysql,
            'all_ok' => $allOk,
            'has_warnings' => $hasWarnings,
        ];
    }
}
