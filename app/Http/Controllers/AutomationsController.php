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

    // Run-now was removed intentionally: the same actions exist on the
    // marketplace's Orders / Product Group pages with proper progress +
    // result detail. The Automations tab is scoped to cadence + window
    // config only; the scheduler is the only thing that fires jobs.
}
