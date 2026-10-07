<?php

namespace App\Support\Fulfilment;

use Illuminate\Http\JsonResponse;

final class AppFulfilment
{
    public const STEPS = ['to_pack', 'to_arrange', 'to_handover', 'shipped', 'done', 'cancelled', 'other'];

    private const COUNTED = ['pack', 'ship', 'book', 'book_manual'];

    public static function block(string $channel, int $orderId, string $step, array $writes, array $reads = [], ?string $blocked = null): array
    {
        $user = auth()->user();
        $manages = $user !== null && $user->hasPermission('manage_' . $channel . '/order');
        $writes = $manages && $blocked === null ? $writes : [];

        $counted = array_intersect($writes, self::COUNTED) !== []
            && PackingCheck::enabled()
            && PackingCheck::supports($channel)
            && ! PackingCheck::passed($user, $channel, $orderId);

        return [
            'step' => in_array($step, self::STEPS, true) ? $step : 'other',
            'actions' => array_values(array_unique(array_merge($writes, $reads))),
            'count_needed' => $counted,
            'blocked_reason' => $blocked,
        ];
    }

    public static function ok(string $message, ?array $order = null, array $extra = []): JsonResponse
    {
        if (($user = auth()->user()) !== null) {
            \App\Support\FulfilmentBadge::forget($user);
        }

        return response()->json(['ok' => true, 'message' => $message] + $extra + ['order' => $order]);
    }

    public static function alreadyDone(string $message, ?array $order = null): JsonResponse
    {
        return response()->json(['ok' => false, 'error' => 'already_done', 'message' => $message, 'order' => $order], 409);
    }

    public static function refused(string $message, int $status = 422): JsonResponse
    {
        return response()->json(['ok' => false, 'error' => 'refused', 'message' => $message], $status);
    }

    public static function bindStore(string $channel, \Illuminate\Database\Eloquent\Model $store): void
    {
        app()->instance($channel . '.route-store', $store);
        \Illuminate\Support\Facades\URL::defaults(['store' => (int) $store->getKey()]);
    }

    public static function webOutcome(\Symfony\Component\HttpFoundation\Response $response, string $flashKey): array
    {
        if ($response instanceof JsonResponse) {
            $data = (array) $response->getData(true);
            $ok = (bool) ($data['ok'] ?? $response->isSuccessful());

            return ['ok' => $ok, 'error' => $data['error'] ?? ($ok ? null : 'refused'), 'message' => (string) ($data['message'] ?? '')];
        }

        $flash = (array) app('session.store')->pull($flashKey, []);
        $ok = (bool) ($flash['ok'] ?? false);
        $message = (string) ($flash['message'] ?? '');
        if ($message === '') {
            $message = (string) (app('session.store')->pull('error') ?? '');
        }

        return ['ok' => $ok, 'error' => $flash['error'] ?? ($ok ? null : 'refused'), 'message' => $message];
    }

    public static function answer(array $outcome, ?array $order, array $extra = []): JsonResponse
    {
        if ($outcome['ok']) {
            return self::ok($outcome['message'], $order, $extra);
        }
        if ($outcome['error'] === 'already_done') {
            return self::alreadyDone($outcome['message'], $order);
        }
        if ($outcome['error'] === 'busy') {
            return response()->json(['ok' => false, 'error' => 'busy', 'message' => $outcome['message']], 409);
        }

        return self::refused($outcome['message'] !== '' ? $outcome['message'] : 'The marketplace did not accept this.');
    }

    public static function waybill(?string $pdf, string $name, bool $building = false, string $message = ''): \Symfony\Component\HttpFoundation\Response
    {
        if ($pdf !== null && str_starts_with($pdf, '%PDF')) {
            return response($pdf, 200, [
                'Content-Type' => 'application/pdf',
                'Content-Disposition' => 'inline; filename="' . $name . '"',
            ]);
        }
        if ($building) {
            return response()->json(['ok' => true, 'ready' => false, 'message' => $message]);
        }

        return response()->json(['ok' => false, 'message' => $message !== '' ? $message : 'There is no waybill for this order.'], 422);
    }
}
