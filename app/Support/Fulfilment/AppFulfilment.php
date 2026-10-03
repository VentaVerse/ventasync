<?php

namespace App\Support\Fulfilment;

use Illuminate\Http\JsonResponse;

/**
 * What the mobile app is told about an order's fulfilment, and how a
 * fulfilment action answers it.
 *
 * Each channel places its own statuses in a step and names the actions
 * that step allows; this decides what the person asking may do with them
 * and whether the packing check stands in front of a booking. The app keeps
 * no status maps of its own, so the web and the phone cannot drift.
 */
final class AppFulfilment
{
    public const STEPS = ['to_pack', 'to_arrange', 'to_handover', 'shipped', 'done', 'cancelled', 'other'];

    /** The actions the packing check stands in front of. */
    private const COUNTED = ['pack', 'ship', 'book', 'book_manual'];

    /**
     * @param  list<string>  $writes  what the step allows a person who manages this channel's orders
     * @param  list<string>  $reads  what anyone who may see the order can do at this step
     * @return array{step: string, actions: list<string>, count_needed: bool, blocked_reason: ?string}
     */
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

    /** @param  array<string, mixed>|null  $order  the order as its show endpoint draws it, after the action */
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

    /**
     * Stand in the order's own store, as its web page does, so every
     * marketplace call is made with that store's credentials and every
     * read is fenced to it.
     */
    public static function bindStore(string $channel, \Illuminate\Database\Eloquent\Model $store): void
    {
        app()->instance($channel . '.route-store', $store);
        \Illuminate\Support\Facades\URL::defaults(['store' => (int) $store->getKey()]);
    }

    /**
     * What a channel's web button answered, read the way its page reads it:
     * the JSON it returned, or the result it flashed before redirecting.
     * The phone runs the web's own action, so the two cannot drift apart.
     *
     * @return array{ok: bool, error: ?string, message: string}
     */
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

    /**
     * The app's answer to a web button's outcome, with the order as it
     * stands afterwards.
     *
     * @param  array{ok: bool, error: ?string, message: string}  $outcome
     */
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

    /**
     * A waybill as the app reads it: the PDF itself, or a note that it is
     * still being made, or why there is none. Never a link elsewhere: the
     * app sends its sign-in to this server only.
     */
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
