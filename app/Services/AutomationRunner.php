<?php

namespace App\Services;

use App\Integrations\Contracts\SteppableAutomation;
use App\Models\AutomationRun;
use App\Models\ScheduledJob;
use Illuminate\Support\Str;

class AutomationRunner
{
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

    public function beginWork(string $integration, ?int $storeId, string $subject, array $units, string $label = 'products'): AutomationRun
    {
        AutomationRun::query()->where('integration', $integration)
            ->where(fn ($q) => $storeId === null ? $q->whereNull('store_id') : $q->where('store_id', $storeId))
            ->where('subject', $subject)->where('status', AutomationRun::RUNNING)
            ->update(['status' => AutomationRun::STOPPED]);

        $units = array_values(array_map('strval', $units));
        $run = AutomationRun::create([
            'scheduled_job_id' => null,
            'integration' => $integration,
            'store_id' => $storeId,
            'subject' => $subject,
            'label' => $label,
            'units' => $units,
            'total' => count($units),
            'status' => AutomationRun::RUNNING,
            'ledger' => [],
            'user_id' => auth()->id(),
        ]);
        if ($units === []) {
            $this->finish($run, null, AutomationRun::DONE, null);
        }

        return $run;
    }

    public function step(AutomationRun $run, SteppableAutomation $command, ?int $chunk = null): AutomationRun
    {
        if ($run->status !== AutomationRun::RUNNING) {
            return $run;
        }
        $job = $run->job;
        if (! $job) {
            $this->finish($run, null, AutomationRun::DONE, null);

            return $run;
        }
        $chunk = $chunk ?? (method_exists($command, 'automationChunk') ? max(1, (int) $command->automationChunk()) : self::CHUNK);

        return $this->stepWith($run, fn (array $slice) => $command->automationStep($job, $slice), $chunk);
    }

    public function stepWith(AutomationRun $run, callable $work, int $chunk = self::CHUNK): AutomationRun
    {
        if ($run->status !== AutomationRun::RUNNING) {
            return $run;
        }
        $job = $run->job;
        $units = array_values((array) $run->units);
        $slice = array_slice($units, (int) $run->done, max(1, $chunk));
        if ($slice === []) {
            $this->finish($run, $job, AutomationRun::DONE, null);

            return $run;
        }
        try {
            $answer = $work($slice);
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
