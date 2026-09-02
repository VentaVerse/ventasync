<?php

namespace App\Http\Controllers\Integrations;

use App\Http\Controllers\Controller;
use App\Integrations\IntegrationRegistry;
use App\Models\ChannelWebhookEvent;
use Illuminate\Http\Request;

/**
 * The one public webhook endpoint (Phase E, #184): verify, store, answer
 * fast. Applying events is webhooks:process's job a minute later - the
 * marketplaces expect a quick 2xx, and this server runs no queue worker.
 *
 * Security shape: the route is public by necessity (marketplaces hold no
 * session). The channel's own signature scheme is the authentication; a
 * delivery that fails it is STORED with signature_ok = false - evidence
 * for diagnosing a scheme mismatch, never processed - and answered 401.
 * An unknown channel segment is a plain 404, the same face any wrong
 * URL gets.
 */
class WebhookController extends Controller
{
    public function receive(Request $request, string $channel, IntegrationRegistry $registry)
    {
        $receiver = $registry->webhookReceiverFor($channel);
        if (!$receiver) {
            abort(404);
        }

        $payload = json_decode($request->getContent(), true);
        if (!is_array($payload)) {
            // Kept, named, never parsed twice: a body that is not JSON is
            // itself a fact worth being able to look at.
            $payload = ['_raw' => mb_substr((string) $request->getContent(), 0, 10000)];
        }

        $ok = $receiver->verifyWebhook($request);

        ChannelWebhookEvent::create([
            'channel' => $channel,
            'event_type' => $ok ? $receiver->webhookEventType($payload) : null,
            'payload' => $payload,
            'signature' => mb_substr((string) $request->header('Authorization', ''), 0, 512),
            'signature_ok' => $ok,
            'received_at' => now(),
        ]);

        return response()->json(['ok' => $ok], $ok ? 200 : 401);
    }
}
