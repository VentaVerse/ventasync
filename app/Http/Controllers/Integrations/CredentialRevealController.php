<?php

namespace App\Http\Controllers\Integrations;

use App\Http\Controllers\Controller;
use App\Integrations\IntegrationRegistry;
use App\Services\ActivityLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Hands one stored credential back to an operator who asked for it.
 *
 * The settings forms mask their credentials and never render the real value
 * into the page. This is the other door: an explicit, named, single-field
 * request, gated on the channel's own manage permission and written to the
 * activity log.
 *
 * Every refusal below answers with the same 403 and the same words. A caller
 * probing for which channels exist, which fields they hold, or which
 * permissions they lack learns nothing from the difference between "no such
 * channel", "that channel reveals nothing" and "you may not". Only the log
 * distinguishes them.
 */
class CredentialRevealController extends Controller
{
    public function __invoke(Request $request, string $channel, IntegrationRegistry $registry): JsonResponse
    {
        $data = $request->validate([
            'field' => 'required|string|max:64',
            // Present only on a multi-store channel. Scoping is the
            // implementation's job, but the value has to survive validation to
            // reach it.
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

        // The allowlist, not the request, decides what a field name may be.
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

        // Logged BEFORE the value is returned, so a read is recorded even if
        // the response never arrives. The field name is recorded; the value
        // never is - an audit trail that copies the secret into a second table
        // has doubled the problem it exists to police.
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
