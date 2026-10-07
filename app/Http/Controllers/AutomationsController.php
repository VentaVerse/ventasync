<?php

namespace App\Http\Controllers;

use App\Integrations\Support\AutomationSettings;
use App\Models\ScheduledJob;
use Illuminate\Http\Request;

class AutomationsController extends Controller
{
    public function update(Request $request, int $id)
    {
        $data = $request->validate([
            'cadence_value' => ['required', 'integer', 'min:1', 'max:9999'],
            'cadence_unit'  => ['required', 'in:minute,hour,day'],
            'enabled'       => ['nullable'],
            'days'          => ['nullable', 'integer', 'min:1', 'max:365'],
            'days_returns'  => ['nullable', 'integer', 'min:1', 'max:365'],
        ]);

        $job = ScheduledJob::findOrFail($id);

        if (!self::mayManage((string) $job->integration)) {
            return back()->with('error', "You don't have permission to do this action.");
        }
        $job->cadence_value = (int) $data['cadence_value'];
        $job->cadence_unit  = (string) $data['cadence_unit'];
        $job->enabled       = $request->boolean('enabled');

        $options = is_array($job->options) ? $job->options : [];
        if ($request->filled('days')) {
            $options['days'] = (int) $data['days'];
        }
        if ($request->filled('days_returns')) {
            $options['days_returns'] = (int) $data['days_returns'];
        }
        $job->options = $options;

        $job->syncCronExpression();
        $job->save();

        return back()->with('status', "Automation saved: {$job->display_name}");
    }

    public function run(Request $request, int $id)
    {
        $job = ScheduledJob::findOrFail($id);
        if (!self::mayManage((string) $job->integration)) {
            return back()->with('error', "You don't have permission to do this action.");
        }

        if (function_exists('set_time_limit')) {
            @set_time_limit(900);
        }

        $commandName = (string) strtok(trim((string) $job->command), ' ');
        if (! array_key_exists($commandName, \Illuminate\Support\Facades\Artisan::all())) {
            $job->forceFill(['last_run_at' => now(), 'last_run_ok' => false])->save();

            return back()->with('error', 'Run failed: ' . $job->display_name . '. The command ' . $job->command . ' is not installed. Enable the extension that provides it, or remove this row.');
        }

        $command = \Illuminate\Support\Facades\Artisan::all()[$commandName];
        if ($request->expectsJson() && $command instanceof \App\Integrations\Contracts\SteppableAutomation) {
            $runner = app(\App\Services\AutomationRunner::class);
            $run = $runner->begin($job, $command);
            $run = $runner->step($run, $command);

            return $this->runJson($run);
        }

        try {
            $code = \Illuminate\Support\Facades\Artisan::call(self::commandLine($job));
            $output = trim((string) \Illuminate\Support\Facades\Artisan::output());
            $ok = $code === 0;
        } catch (\Throwable $e) {
            $output = $e->getMessage();
            $ok = false;
        }

        $job->forceFill(['last_run_at' => now(), 'last_run_ok' => $ok])->save();

        $said = \Illuminate\Support\Str::limit(preg_replace('/\s+/', ' ', $output) ?: '', 300);
        $line = ($ok ? 'Ran ' : 'Run failed: ') . $job->display_name . ($said !== '' ? '. ' . $said : '.');

        if ($request->expectsJson()) {
            $plain = array_values(array_filter(array_map('trim', explode("\n", (string) $output)), fn ($l) => $l !== '' && ! preg_match('/^[=\-\s]+/', $l) && ! str_starts_with($l, '===')));
            $reason = $ok ? '' : ' ' . \Illuminate\Support\Str::limit((string) (end($plain) ?: ''), 160);

            return response()->json(['ok' => $ok, 'done' => true, 'message' => $ok ? '' : trim($reason)]);
        }

        return back()->with($ok ? 'status' : 'error', $line);
    }

    public function runStep(Request $request)
    {
        $run = $this->runFor($request);
        $commandName = (string) strtok(trim((string) $run->job?->command), ' ');
        $command = \Illuminate\Support\Facades\Artisan::all()[$commandName] ?? null;
        if (! $command instanceof \App\Integrations\Contracts\SteppableAutomation) {
            return response()->json(['ok' => false, 'message' => 'This job cannot be walked in steps.'], 422);
        }
        $run = app(\App\Services\AutomationRunner::class)->step($run, $command);

        return $this->runJson($run);
    }

    public function runStop(Request $request)
    {
        return $this->runJson(app(\App\Services\AutomationRunner::class)->stop($this->runFor($request)));
    }

    public function runState(Request $request)
    {
        return $this->runJson($this->runFor($request));
    }

    private function runFor(Request $request): \App\Models\AutomationRun
    {
        $run = \App\Models\AutomationRun::query()->whereNotNull('scheduled_job_id')->findOrFail((int) $request->route('run'));
        abort_unless(self::mayManage((string) $run->integration), 403);

        return $run;
    }

    private function runJson(\App\Models\AutomationRun $run)
    {
        return response()->json(['ok' => true, 'run' => $run->toState(), 'outcome' => \App\Services\AutomationRunner::outcome($run)]);
    }

    public static function commandLine(ScheduledJob $job): string
    {
        $line = trim((string) $job->command);
        foreach ($job->commandArguments() as $flag => $value) {
            $line .= ' ' . $flag . ($value === true ? '' : '="' . addcslashes((string) $value, '"\\') . '"');
        }

        return $line;
    }

    public function batchUpdate(Request $request)
    {
        $data = $request->validate([
            'integration'                  => ['nullable', 'string', 'max:32'],
            'windows'                      => ['nullable', 'array'],
            'windows.sync_last_days'         => ['nullable', 'integer', 'min:1', 'max:365'],
            'windows.sync_last_days_returns' => ['nullable', 'integer', 'min:1', 'max:365'],
        ] + AutomationSettings::rules());

        $ids  = array_map('intval', array_keys($data['jobs'] ?? []));
        $jobs = $ids ? ScheduledJob::whereIn('id', $ids)->get()->keyBy('id') : collect();

        foreach ($jobs as $job) {
            if (!self::mayManage((string) $job->integration)) {
                return back()->with('error', "You don't have permission to do this action.");
            }
        }

        $integration = $data['integration'] ?? null;
        $hasWindows  = !empty($data['windows']) && is_array($data['windows']);
        if ($hasWindows) {
            if (!$integration || !self::mayManage((string) $integration)) {
                return back()->with('error', "You don't have permission to do this action.");
            }
        }

        $saved = AutomationSettings::apply($jobs, $data['jobs'] ?? []);

        if ($hasWindows) {
            $settingClass = $this->settingClassFor((string) $integration);
            if ($settingClass) {
                $setting = $settingClass::query()->first();
                if ($setting) {
                    $patch = [];
                    if (array_key_exists('sync_last_days', $data['windows'])) {
                        $patch['sync_last_days'] = $data['windows']['sync_last_days'] ?: null;
                    }
                    if (array_key_exists('sync_last_days_returns', $data['windows'])) {
                        $patch['sync_last_days_returns'] = $data['windows']['sync_last_days_returns'] ?: null;
                    }
                    if ($patch) {
                        $setting->update($patch);
                    }
                }
            }
        }

        return back()->with('status', "Saved {$saved} automation" . ($saved === 1 ? '' : 's') . '.');
    }

    private function settingClassFor(string $integration): ?string
    {
        $map = [
            'shopee' => \Extensions\shopee\Models\ShopeeSetting::class,
            'lazada' => \Extensions\lazada\Models\LazadaSetting::class,
            'tiktok' => \Extensions\tiktok\Models\TikTokSetting::class,
        ];
        $class = $map[$integration] ?? null;
        return ($class && class_exists($class)) ? $class : null;
    }

    private static function mayManage(string $integration): bool
    {
        $user = auth()->user();
        if (!$user) {
            return false;
        }
        foreach (["manage_{$integration}/settings", "manage_{$integration}"] as $key) {
            if ($user->hasPermission($key)) {
                return true;
            }
        }

        return false;
    }
}
