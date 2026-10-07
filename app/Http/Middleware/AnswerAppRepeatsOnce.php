<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

class AnswerAppRepeatsOnce
{
    public const HEADER = 'Idempotency-Key';

    private const TABLE = 'app_idempotency_keys';

    private const KEEP_HOURS = 24;

    public function handle(Request $request, Closure $next): Response
    {
        $key = $request->headers->get(self::HEADER);
        $user = $request->user();
        if ($key === null || $user === null || in_array($request->getMethod(), ['GET', 'HEAD', 'OPTIONS'], true)) {
            return $next($request);
        }

        if (! preg_match('/^[A-Za-z0-9_.:-]{8,100}$/', $key)) {
            return response()->json(['ok' => false, 'error' => 'bad_key', 'message' => 'The Idempotency-Key header must be 8 to 100 letters, digits or - _ . :'], 422);
        }

        $fingerprint = hash('sha256', $request->getMethod() . ' ' . $request->path() . "\n" . $request->getContent());

        if (random_int(1, 100) === 1) {
            DB::table(self::TABLE)->where('created_at', '<', now()->subHours(self::KEEP_HOURS))->delete();
        }

        try {
            $id = DB::table(self::TABLE)->insertGetId([
                'user_id' => $user->getKey(),
                'idempotency_key' => $key,
                'fingerprint' => $fingerprint,
                'created_at' => now(),
            ]);
        } catch (UniqueConstraintViolationException $e) {
            return $this->repeat($user->getKey(), $key, $fingerprint);
        }

        try {
            $response = $next($request);
        } catch (\Throwable $e) {
            DB::table(self::TABLE)->where('id', $id)->delete();
            throw $e;
        }

        if ($response->isSuccessful()) {
            DB::table(self::TABLE)->where('id', $id)->update([
                'status_code' => $response->getStatusCode(),
                'response' => (string) $response->getContent(),
            ]);
        } else {
            DB::table(self::TABLE)->where('id', $id)->delete();
        }

        return $response;
    }

    private function repeat(int $userId, string $key, string $fingerprint): Response
    {
        $first = DB::table(self::TABLE)->where('user_id', $userId)->where('idempotency_key', $key)->first();

        if ($first === null) {
            return response()->json(['ok' => false, 'error' => 'busy', 'message' => 'The first try is still being handled. Send it again in a moment.'], 409);
        }
        if (! hash_equals((string) $first->fingerprint, $fingerprint)) {
            return response()->json(['ok' => false, 'error' => 'key_reused', 'message' => 'This Idempotency-Key was already used for a different request.'], 422);
        }
        if ($first->status_code === null) {
            return response()->json(['ok' => false, 'error' => 'busy', 'message' => 'The first try is still being handled. Send it again in a moment.'], 409);
        }

        return response((string) $first->response, (int) $first->status_code, [
            'Content-Type' => 'application/json',
            'Idempotent-Replayed' => 'true',
        ]);
    }
}
