<?php

namespace App\Http\Controllers\Concerns;

use App\Models\AutomationRun;
use App\Services\AutomationRunner;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

trait DrivesGroupSendRuns
{
    abstract protected function groupSendIntegration(): string;

    abstract protected function groupSendStoreId(Request $request): int;

    abstract protected function groupSendMembers(int $groupId): array;

    abstract protected function groupSendChunk(int $groupId, array $productIds): array;

    public function groupSendSize(int $groupId): int
    {
        try {
            return count($this->groupSendMembers($groupId));
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException) {
            return 0;
        }
    }

    public function sendRunBegin(Request $request): JsonResponse
    {
        $groupId = $this->groupSendGroupId($request);
        $runner = app(AutomationRunner::class);
        $run = $runner->beginWork(
            $this->groupSendIntegration(),
            $this->groupSendStoreId($request),
            'group:' . $groupId,
            $this->groupSendMembers($groupId),
        );

        return $this->sendRunJson($this->walkGroupSend($run, $groupId));
    }

    public function sendRunStep(Request $request): JsonResponse
    {
        $run = $this->sendRunFor($request);

        return $this->sendRunJson($this->walkGroupSend($run, $this->groupSendGroupId($request)));
    }

    public function sendRunStop(Request $request): JsonResponse
    {
        return $this->sendRunJson(app(AutomationRunner::class)->stop($this->sendRunFor($request)));
    }

    private function walkGroupSend(AutomationRun $run, int $groupId): AutomationRun
    {
        return app(AutomationRunner::class)->stepWith($run, function (array $units) use ($groupId) {
            $members = array_flip($this->groupSendMembers($groupId));
            $ids = array_values(array_filter(array_map('intval', $units), fn (int $id) => isset($members[$id])));
            if ($ids === []) {
                return ['ok' => count($units), 'failed' => 0];
            }
            $answer = $this->groupSendChunk($groupId, $ids);
            if (! empty($answer['stop'])) {
                return ['error' => (string) ($answer['summary'] ?? 'Nothing was sent.')];
            }
            $failed = min(count($ids), max(0, (int) ($answer['failed'] ?? 0)));

            return ['ok' => count($units) - $failed, 'failed' => $failed];
        });
    }

    private function groupSendGroupId(Request $request): int
    {
        return (int) ($request->route('group') ?? $request->route('id'));
    }

    private function sendRunFor(Request $request): AutomationRun
    {
        return AutomationRun::query()
            ->whereKey((int) $request->route('run'))
            ->whereNull('scheduled_job_id')
            ->where('integration', $this->groupSendIntegration())
            ->where('store_id', $this->groupSendStoreId($request))
            ->where('subject', 'group:' . $this->groupSendGroupId($request))
            ->firstOrFail();
    }

    private function sendRunJson(AutomationRun $run): JsonResponse
    {
        return response()->json(['ok' => true, 'run' => $run->toState(), 'outcome' => AutomationRunner::outcome($run)]);
    }
}
