<?php

namespace App\Services;

use App\Models\OrderFetchRun;
use Illuminate\Support\Str;

class OrderFetchRunner
{
    public const SYNC_PAGE_CAP = 3;

    public function begin(string $integration, ?int $storeId, string $dateFrom, string $dateTo, array $options = []): OrderFetchRun
    {
        OrderFetchRun::query()
            ->where('integration', $integration)
            ->where(fn ($q) => $storeId === null ? $q->whereNull('store_id') : $q->where('store_id', $storeId))
            ->where('status', OrderFetchRun::RUNNING)
            ->update(['status' => OrderFetchRun::STOPPED]);

        return OrderFetchRun::create([
            'integration' => $integration,
            'store_id' => $storeId,
            'date_from' => $dateFrom,
            'date_to' => $dateTo,
            'options' => $options,
            'cursor' => null,
            'status' => OrderFetchRun::RUNNING,
            'ledger' => [],
            'user_id' => auth()->id(),
        ]);
    }

    public function resume(OrderFetchRun $run): OrderFetchRun
    {
        if ($run->status !== OrderFetchRun::DONE) {
            $run->forceFill(['status' => OrderFetchRun::RUNNING, 'last_error' => null])->save();
        }

        return $run;
    }

    public function step(OrderFetchRun $run, callable $stepper): OrderFetchRun
    {
        if ($run->status !== OrderFetchRun::RUNNING) {
            return $run;
        }
        $pageNo = (int) $run->page + 1;
        try {
            $answer = $stepper($run);
        } catch (\Throwable $e) {
            $answer = ['error' => \App\Support\TransportError::plain($e, Str::title($run->integration))];
        }
        $answer = is_array($answer) ? $answer : [];

        if (! empty($answer['error'])) {
            $run->forceFill([
                'status' => OrderFetchRun::FAILED,
                'last_error' => Str::limit((string) $answer['error'], 500, ''),
                'ledger' => $this->ledgerWith($run, "Page {$pageNo}: no answer"),
            ])->save();

            return $run;
        }

        $read = (int) ($answer['read'] ?? 0);
        $created = (int) ($answer['created'] ?? 0);
        $updated = (int) ($answer['updated'] ?? 0);
        $failed = (int) ($answer['failed'] ?? 0);
        $done = (bool) ($answer['done'] ?? false) || ($answer['cursor'] ?? null) === null;

        $run->forceFill([
            'page' => $pageNo,
            'pages' => isset($answer['pages']) ? (int) $answer['pages'] : $run->pages,
            'total' => isset($answer['total']) ? (int) $answer['total'] : $run->total,
            'read' => (int) $run->read + $read,
            'created' => (int) $run->created + $created,
            'updated' => (int) $run->updated + $updated,
            'failed' => (int) $run->failed + $failed,
            'cursor' => $done ? null : $answer['cursor'],
            'status' => $done ? OrderFetchRun::DONE : OrderFetchRun::RUNNING,
            'last_error' => null,
            'ledger' => $this->ledgerWith($run, "Page {$pageNo}: " . ($answer['note'] ?? self::pageSentence($read, $created, $updated, $failed))),
        ])->save();

        return $run;
    }

    public function walk(OrderFetchRun $run, callable $stepper, int $pages = self::SYNC_PAGE_CAP): OrderFetchRun
    {
        for ($i = 0; $i < $pages && $run->status === OrderFetchRun::RUNNING; $i++) {
            $run = $this->step($run, $stepper);
        }

        return $run;
    }

    public function stop(OrderFetchRun $run): OrderFetchRun
    {
        if ($run->status === OrderFetchRun::RUNNING) {
            $run->forceFill(['status' => OrderFetchRun::STOPPED])->save();
        }

        return $run;
    }

    public static function pageSentence(int $read, int $created, int $updated, int $failed): string
    {
        $parts = [$read . ' ' . Str::plural('order', $read)];
        if ($created > 0) {
            $parts[] = $created . ' new';
        }
        if ($updated > 0) {
            $parts[] = $updated . ' updated';
        }
        if ($failed > 0) {
            $parts[] = $failed . ' failed';
        }
        if ($created === 0 && $updated === 0 && $failed === 0 && $read > 0) {
            $parts[] = 'unchanged';
        }

        return implode(', ', $parts);
    }

    public static function outcome(OrderFetchRun $run): string
    {
        $n = (int) $run->read;
        $s = $n . ' ' . Str::plural('order', $n) . ' read';
        $parts = [];
        if ($run->created > 0) {
            $parts[] = $run->created . ' new';
        }
        if ($run->updated > 0) {
            $parts[] = $run->updated . ' updated';
        }
        if ($run->failed > 0) {
            $parts[] = $run->failed . ' failed';
        }
        $unchanged = $n - (int) $run->created - (int) $run->updated - (int) $run->failed;
        if ($unchanged > 0) {
            $parts[] = $unchanged . ' unchanged';
        }

        return $s . ($parts ? ': ' . implode(', ', $parts) : '') . '.';
    }

    private function ledgerWith(OrderFetchRun $run, string $line): array
    {
        $ledger = array_values((array) ($run->ledger ?? []));
        array_unshift($ledger, $line);

        return array_slice($ledger, 0, 20);
    }
}
