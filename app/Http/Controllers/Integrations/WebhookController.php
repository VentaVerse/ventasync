<?php

namespace App\Http\Controllers\Integrations;

use App\Http\Controllers\Controller;
use App\Integrations\IntegrationRegistry;
use App\Models\ChannelWebhookEvent;
use Illuminate\Http\Request;

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
