<?php

namespace App\Http\Controllers\Integrations;

use App\Http\Controllers\Controller;
use App\Integrations\IntegrationRegistry;
use App\Services\ActivityLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CredentialRevealController extends Controller
{
    public function __invoke(Request $request, string $channel, IntegrationRegistry $registry): JsonResponse
    {
        $data = $request->validate([
            'field' => 'required|string|max:64',
            'store' => 'nullable|integer|min:1',
        ]);

        $refused = response()->json([
            'ok' => false,
            'message' => 'That credential is not available.',
        ], 403);

        $revealer = $registry->credentialRevealer($channel);

        if (! $revealer) {
            return $refused;
        }

        if (! array_key_exists($data['field'], $revealer->revealableCredentials())) {
            return $refused;
        }

        $user = $request->user();

        if (! $user || ! $user->hasPermission($revealer->credentialManagePermission())) {
            return $refused;
        }

        $value = $revealer->revealCredential($data['field'], $data['store'] ?? null);

        if ($value === null || $value === '') {
            return response()->json([
                'ok' => false,
                'message' => 'Nothing is stored in that field.',
            ], 404);
        }

        ActivityLogger::log(
            'credential.revealed',
            'integration',
            null,
            $channel . ' · ' . ($revealer->revealableCredentials()[$data['field']] ?? $data['field']),
            ['field' => $data['field'], 'store' => $data['store'] ?? null],
        );

        return response()->json([
            'ok' => true,
            'value' => $value,
        ]);
    }
}
