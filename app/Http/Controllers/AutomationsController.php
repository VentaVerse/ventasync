<?php

namespace App\Http\Controllers;

use App\Models\ScheduledJob;
use Illuminate\Http\Request;

/**
 * Edits scheduled_jobs rows from the per-integration "Automations" tab.
 *
 * The Automations tab is a Blade partial (`partials._automations_tab`)
 * embedded in each marketplace integration's settings page. Per-row
 * forms POST here to update cadence / enabled / options, or to fire a
 * one-off run via the "Run now" button.
 *
 * No new permissions: the tab inherits the parent integration's perm
 * gate (e.g. `manage_shopee`). Permission gating happens at the route
 * level via the perm: middleware alias.
 */
class AutomationsController extends Controller
{
    /**
     * Persist cadence + enabled + options for one job.
     */
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

        // Permission gate — the route can't declare a single `perm:` key
        // because each scheduled job belongs to a different integration
        // (shopee/lazada/tiktok/…). Derive the required permission from
        // the job's `integration` column and reject mismatched callers
        // here. Matches EnsurePermission's redirect-with-flash shape so
        // the UX is identical to other denied actions.
        $required = "manage_{$job->integration}";
        if (!auth()->user()?->hasPermission($required)) {
            return back()->with('error', "You don't have permission to do this action.");
        }
        $job->cadence_value = (int) $data['cadence_value'];
        $job->cadence_unit  = (string) $data['cadence_unit'];
        $job->enabled       = $request->boolean('enabled');

        // Merge known option keys back into the JSON. Anything the form
        // didn't send is left intact.
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

    /**
     * Batch update — the jobs list submits all rows in a single form.
     *
     * Per-row buttons made the Automations tab look like 7+ tiny forms
     * stacked vertically. Folding them into one form lets the operator
     * tweak everything (toggles, cadence, options) and commit once.
     *
     * Permission policy is the same as the single-row update: the
     * required key is derived from each job's `integration` column. We
     * deny the WHOLE batch if any row belongs to an integration the
     * caller can't manage — partial commits would let a low-permission
     * user smuggle authorized rows past the gate.
     */
    public function batchUpdate(Request $request)
    {
        $data = $request->validate([
            'integration'                  => ['nullable', 'string', 'max:32'],
            'jobs'                         => ['nullable', 'array'],
            'jobs.*.cadence_value'         => ['required', 'integer', 'min:1', 'max:9999'],
            'jobs.*.cadence_unit'          => ['required', 'in:minute,hour,day'],
            'jobs.*.enabled'               => ['nullable'],
            'jobs.*.days'                  => ['nullable', 'integer', 'min:1', 'max:365'],
            'jobs.*.days_returns'          => ['nullable', 'integer', 'min:1', 'max:365'],
            'windows'                      => ['nullable', 'array'],
            'windows.sync_last_days'         => ['nullable', 'integer', 'min:1', 'max:365'],
            'windows.sync_last_days_returns' => ['nullable', 'integer', 'min:1', 'max:365'],
        ]);

        $ids  = array_map('intval', array_keys($data['jobs'] ?? []));
        $jobs = $ids ? ScheduledJob::whereIn('id', $ids)->get()->keyBy('id') : collect();

        foreach ($jobs as $job) {
            if (!auth()->user()?->hasPermission("manage_{$job->integration}")) {
                return back()->with('error', "You don't have permission to do this action.");
            }
        }

        $integration = $data['integration'] ?? null;
        $hasWindows  = !empty($data['windows']) && is_array($data['windows']);
        if ($hasWindows) {
            if (!$integration || !auth()->user()?->hasPermission("manage_{$integration}")) {
                return back()->with('error', "You don't have permission to do this action.");
            }
        }

        $saved = 0;
        foreach (($data['jobs'] ?? []) as $id => $payload) {
            $job = $jobs[(int) $id] ?? null;
            if (!$job) {
                continue;
            }

            $job->cadence_value = (int) $payload['cadence_value'];
            $job->cadence_unit  = (string) $payload['cadence_unit'];
            $job->enabled       = !empty($payload['enabled']);

            $options = is_array($job->options) ? $job->options : [];
            if (isset($payload['days']) && $payload['days'] !== '' && $payload['days'] !== null) {
                $options['days'] = (int) $payload['days'];
            }
            if (isset($payload['days_returns']) && $payload['days_returns'] !== '' && $payload['days_returns'] !== null) {
                $options['days_returns'] = (int) $payload['days_returns'];
            }
            $job->options = $options;

            $job->syncCronExpression();
            $job->save();
            $saved++;
        }

        // Sync-window columns live on each integration's Setting model.
        // Empty string means "clear this window" (revert to the sync
        // command's internal default); a positive integer means override.
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

    /**
     * Map an integration id to its Setting model class. Returns null
     * for integrations that don't carry global sync-window columns.
     */
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

    // Run-now was removed intentionally: the same actions exist on the
    // marketplace's Orders / Product Group pages with proper progress +
    // result detail. The Automations tab is scoped to cadence + window
    // config only; the scheduler is the only thing that fires jobs.
}
