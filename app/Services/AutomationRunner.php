<?php

namespace App\Services;

use App\Integrations\Contracts\SteppableAutomation;
use App\Models\AutomationRun;
use App\Models\ScheduledJob;
use Illuminate\Support\Str;

/**
 * Drives a Run now in short steps (the owner, 2026-09-07). The command names
 * its units once; each step hands it the next few, and the run keeps the
 * count, the ledger and the verdict. When the run ends, however it ends,
 * the job row is stamped the way a scheduled run stamps it.
 */
class AutomationRunner
{
    /** Units per step: a product is one or two store calls, so ten keeps a step to a few seconds. */
    public const CHUNK = 10;

    public function begin(ScheduledJob $job, SteppableAutomation $command): AutomationRun
    {
        AutomationRun::query()->where('scheduled_job_id', $job->id)->where('status', AutomationRun::RUNNING)
            ->update(['status' => AutomationRun::STOPPED]);

        $named = $command->automationUnits($job);
        $units = array_values(array_map('strval', (array) ($named['units'] ?? [])));

        $run = AutomationRun::create([
            'scheduled_job_id' => $job->id,
            'integration' => (string) $job->integration,
            'store_id' => $job->store_id !== null ? (int) $job->store_id : null,
            'label' => (string) ($named['label'] ?? 'products'),
            'units' => $units,
            'total' => count($units),
            'status' => AutomationRun::RUNNING,
            'ledger' => [],
            'user_id' => auth()->id(),
        ]);
        if ($units === []) {
            $this->finish($run, $job, AutomationRun::DONE, 'Nothing to do: no ' . $run->label . ' to push.');
        }

        return $run;
    }

    public function step(AutomationRun $run, SteppableAutomation $command, ?int $chunk = null): AutomationRun
    {
        if ($run->status !== AutomationRun::RUNNING) {
            return $run;
        }
        // A command whose units are phases, not products, walks one per request.
        $chunk = $chunk ?? (method_exists($command, 'automationChunk') ? max(1, (int) $command->automationChunk()) : self::CHUNK);
        $job = $run->job;
        $units = array_values((array) $run->units);
        $slice = array_slice($units, (int) $run->done, $chunk);
        if ($slice === [] || ! $job) {
            $this->finish($run, $job, AutomationRun::DONE, null);

            return $run;
        }
        try {
            $answer = $command->automationStep($job, $slice);
        } catch (\Throwable $e) {
            $answer = ['error' => \App\Support\TransportError::plain($e, Str::title((string) $run->integration))];
        }
        $answer = is_array($answer) ? $answer : [];
        $from = (int) $run->done + 1;
        $to = (int) $run->done + count($slice);
        if (! empty($answer['error'])) {
            $run->forceFill(['ledger' => $this->ledgerWith($run, "{$from}-{$to}: " . $answer['error'])]);
            $this->finish($run, $job, AutomationRun::FAILED, (string) $answer['error']);

            return $run;
        }
        $ok = (int) ($answer['ok'] ?? 0);
        $failed = (int) ($answer['failed'] ?? 0);
        $line = Str::ucfirst(Str::singular($run->label)) . " {$from}-{$to}: {$ok} ok" . ($failed > 0 ? ", {$failed} failed" : '') . (! empty($answer['note']) ? '. ' . $answer['note'] : '');
        $run->forceFill([
            'done' => (int) $run->done + count($slice),
            'ok' => (int) $run->ok + $ok,
            'failed' => (int) $run->failed + $failed,
            'ledger' => $this->ledgerWith($run, $line),
        ])->save();
        if ((int) $run->done >= (int) $run->total) {
            $this->finish($run, $job, AutomationRun::DONE, null);
        }

        return $run;
    }

    public function stop(AutomationRun $run): AutomationRun
    {
        if ($run->status === AutomationRun::RUNNING) {
            $this->finish($run, $run->job, AutomationRun::STOPPED, null);
        }

        return $run;
    }

    /**
     * "200 products: 197 ok, 3 failed." A run whose units are phases or
     * stores says nothing about them (the owner, 2026-09-07: "users won't know
     * what the steps are"): finished is the whole sentence, and a failure
     * says how much did not finish.
     */
    public static function outcome(AutomationRun $run): string
    {
        if ($run->status === AutomationRun::FAILED) {
            return (string) $run->last_error;
        }
        if ((string) $run->label !== 'products') {
            if ((int) $run->failed > 0) {
                return (int) $run->failed . ' of ' . (int) $run->total . ' did not finish.';
            }
            if ($run->status === AutomationRun::STOPPED) {
                return 'Stopped before it finished.';
            }

            return '';
        }
        $n = (int) $run->done;
        $s = ($run->status === AutomationRun::STOPPED ? 'Stopped after ' : '') . $n . ' ' . ($n === 1 ? Str::singular((string) $run->label) : (string) $run->label);
        if ((int) $run->total > $n && $run->status === AutomationRun::STOPPED) {
            $s .= ' of ' . (int) $run->total;
        }
        if ($n === 0 && (int) $run->total === 0) {
            return 'Nothing to do: no ' . $run->label . ' to push.';
        }

        return $s . ': ' . (int) $run->ok . ' ok' . ((int) $run->failed > 0 ? ', ' . (int) $run->failed . ' failed' : '') . '.';
    }

    private function finish(AutomationRun $run, ?ScheduledJob $job, string $status, ?string $error): void
    {
        $run->forceFill(['status' => $status, 'last_error' => $error])->save();
        if ($job) {
            $job->forceFill(['last_run_at' => now(), 'last_run_ok' => $status === AutomationRun::DONE && (int) $run->failed === 0])->save();
        }
    }

    private function ledgerWith(AutomationRun $run, string $line): array
    {
        $ledger = array_values((array) ($run->ledger ?? []));
        array_unshift($ledger, $line);

        return array_slice($ledger, 0, 20);
    }
}
