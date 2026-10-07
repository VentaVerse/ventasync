<?php

namespace App\Http\Controllers\Concerns;

use App\Models\OrderFetchRun;
use App\Services\OrderFetchRunner;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

trait DrivesOrderFetchRuns
{
    abstract protected function orderFetchIntegration(): string;

    abstract protected function orderFetchStoreId(Request $request): ?int;

    abstract public function orderFetchStep(OrderFetchRun $run): array;

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
