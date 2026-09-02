<?php

namespace App\Integrations\Contracts;

use App\Models\ChannelWebhookEvent;
use Illuminate\Http\Request;

/**
 * A channel that accepts push deliveries from its marketplace (Phase E,
 * #184). Core owns one public endpoint - POST /webhooks/{channel} - and
 * one inbox; the channel owns its own signature scheme, its own event
 * names, and what applying an event means. A channel that implements
 * this appears on the endpoint with no core change, like every other
 * contract in the registry.
 */
interface WebhookReceiver
{
    /** The path segment this channel answers on: /webhooks/{segment}. */
    public function webhookChannel(): string;

    /**
     * Does this delivery carry the marketplace's own signature? The raw
     * body and headers are the evidence; the channel knows its scheme.
     */
    public function verifyWebhook(Request $request): bool;

    /** Name the event so the inbox can be read and routed; null = unknown shape. */
    public function webhookEventType(array $payload): ?string;

    /**
     * Apply one verified event, returning a short outcome sentence for
     * the inbox row ("Order 2209ABC status -> SHIPPED"). Unknown event
     * types return a "stored, no handler" outcome rather than throwing.
     */
    public function handleWebhookEvent(ChannelWebhookEvent $event): string;
}
