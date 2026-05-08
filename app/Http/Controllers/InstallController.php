<?php

namespace App\Http\Controllers;

use App\Http\Middleware\InstallationMiddleware;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
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

    public function databaseSubmit(Request $request): RedirectResponse
    {
        // Implementation in Day 3-4: write .env, run migrations
        // For Day 1 skeleton we just redirect to next step.
        return redirect()->route('install.config');
    }

    /** Step 4 — Site configuration. */
    public function config(): View
    {
        return view('install.config', $this->stepContext(4));
    }

    public function configSubmit(Request $request): RedirectResponse
    {
        // Implementation in Day 4-5
        return redirect()->route('install.admin');
    }

    /** Step 5 — Admin user creation. */
    public function admin(): View
    {
        return view('install.admin', $this->stepContext(5));
    }

    public function adminSubmit(Request $request): RedirectResponse
    {
        // Implementation in Day 5
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
