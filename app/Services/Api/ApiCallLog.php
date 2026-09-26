<?php

namespace App\Services\Api;

use App\Models\ApiClient;
use App\Models\ApiRequestLog;
use Throwable;

/**
 * One line per call an application makes, through any door: the partner
 * API records its request, an assistant records each tool it runs. It also
 * stamps when the application was last used. A failure to record is
 * reported, never allowed to fail the call itself.
 */
final class ApiCallLog
{
    public static function record(?ApiClient $client, string $method, string $path, ?string $scope, int $status, ?string $ip): void
    {
        try {
            ApiRequestLog::create([
                'api_client_id' => $client?->id,
                'method'        => $method,
                'path'          => $path,
                'scope'         => $scope,
                'status'        => $status,
                'ip'            => $ip,
                'created_at'    => now(),
            ]);
            if ($client) {
                ApiClient::whereKey($client->id)->update(['last_used_at' => now()]);
            }
        } catch (Throwable $e) {
            report($e);
        }
    }
}
