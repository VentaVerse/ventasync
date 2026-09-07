<?php

namespace App\Services;

use App\Models\OrderFetchRun;
use Illuminate\Support\Str;

/**
 * Drives a fetch of orders one page at a time (the owner, 2026-09-07). The
 * channel supplies the stepper: a callable that reads ONE page of the
 * store for the run's window from the run's cursor, saves what it read,
 * and answers with the counts, the next cursor and whether it is done.
 * The runner keeps the run honest: counts, the ledger of page sentences,
 * the place, and the verdict when a page fails.
 *
 * A step answer:
 *   read, created, updated, failed  (this page)
 *   cursor  the place for the next page (any array), null when done
 *   done    bool
 *   pages   ?int  how many pages the store has, when it says
 *   total   ?int  how many orders the store has in the window, when it says
 *   note    string  the page's own sentence, optional
 *   error   ?string  the store's refusal; the run stops on it
 */
class OrderFetchRunner
{
    /** The largest number of pages one synchronous walk may take before it hands the rest to the page. */
    public const SYNC_PAGE_CAP = 3;

    public function begin(string $integration, ?int $storeId, string $dateFrom, string $dateTo, array $options = []): OrderFetchRun
    {
        // One run per store at a time: whatever was still open is stopped, not lost.
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

    /** Continue a stopped or failed run from its place. */
    public function resume(OrderFetchRun $run): OrderFetchRun
    {
        if ($run->status !== OrderFetchRun::DONE) {
            $run->forceFill(['status' => OrderFetchRun::RUNNING, 'last_error' => null])->save();
        }

        return $run;
    }

    /**
     * One page. The stepper's exception or error verdict stops the run
     * with the sentence; nothing already saved is undone.
     *
     * @param callable(OrderFetchRun): array $stepper
     */
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

    /**
     * The whole run inside one request, for the form post that predates
     * the page-driven walk and for tests: up to SYNC_PAGE_CAP pages, then
     * the run stays running for the page to continue.
     */
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

    /** "50 orders, 12 new, 2 updated" */
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

    /** The outcome in one sentence, the words the orders page uses. */
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
