<?php

namespace App\Http\Controllers\Concerns;

use App\Models\OrderFetchRun;
use App\Services\OrderFetchRunner;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The four JSON verbs the orders page uses to drive a fetch of orders one
 * page at a time (the owner, 2026-09-07): begin, step, stop, state. Every
 * channel's OrderController uses this trait and supplies two things: its
 * integration id and the stepper that reads one page.
 *
 * The routes live beside the channel's own orders.fetch route, so they
 * carry the same permission (manage_{channel}/order).
 */
trait DrivesOrderFetchRuns
{
    /** The channel's id in scheduled_jobs and order_fetch_runs. */
    abstract protected function orderFetchIntegration(): string;

    /** The store the page stands in; null on a channel with one store. */
    abstract protected function orderFetchStoreId(Request $request): ?int;

    /**
     * One page of orders for the run, from its cursor. See OrderFetchRunner
     * for the answer's shape.
     */
    abstract public function orderFetchStep(OrderFetchRun $run): array;

    /** Begin a run and read its first page inside the request, so a small window is done at once. */
    public function fetchRunBegin(Request $request): JsonResponse
    {
        $data = $request->validate([
            'date_from' => 'required|date',
            'date_to' => 'required|date|after_or_equal:date_from',
            'status' => 'nullable|string|max:64',
            'no_stock' => 'nullable',
        ]);
        $runner = app(OrderFetchRunner::class);
        $run = $runner->begin($this->orderFetchIntegration(), $this->orderFetchStoreId($request), $data['date_from'], $data['date_to'], [
            'status' => $data['status'] ?? null,
            'no_stock' => ! empty($data['no_stock']),
        ]);
        $run = $runner->step($run, fn (OrderFetchRun $r) => $this->orderFetchStep($r));

        return $this->fetchRunJson($run);
    }

    public function fetchRunStep(Request $request): JsonResponse
    {
        $run = $this->fetchRunFor($request);
        $runner = app(OrderFetchRunner::class);
        if ($request->boolean('resume')) {
            $runner->resume($run);
        }
        $run = $runner->step($run, fn (OrderFetchRun $r) => $this->orderFetchStep($r));

        return $this->fetchRunJson($run);
    }

    public function fetchRunStop(Request $request): JsonResponse
    {
        $run = app(OrderFetchRunner::class)->stop($this->fetchRunFor($request));

        return $this->fetchRunJson($run);
    }

    public function fetchRunState(Request $request): JsonResponse
    {
        return $this->fetchRunJson($this->fetchRunFor($request));
    }

    /**
     * A run belongs to this channel and this store, or it is not found. The
     * id is read from the route by name: a scalar method parameter would
     * receive the FIRST route value, the store, by position.
     */
    private function fetchRunFor(Request $request): OrderFetchRun
    {
        $storeId = $this->orderFetchStoreId($request);
        $id = (int) $request->route('run');

        return OrderFetchRun::query()
            ->whereKey($id)
            ->where('integration', $this->orderFetchIntegration())
            ->where(fn ($q) => $storeId === null ? $q->whereNull('store_id') : $q->where('store_id', $storeId))
            ->firstOrFail();
    }

    private function fetchRunJson(OrderFetchRun $run): JsonResponse
    {
        return response()->json(['ok' => true, 'run' => $run->toState(), 'outcome' => OrderFetchRunner::outcome($run)]);
    }
}
