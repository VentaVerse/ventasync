<?php

namespace App\Integrations\Contracts;

use App\Models\ChannelWebhookEvent;
use Illuminate\Http\Request;

interface WebhookReceiver
{
    public function webhookChannel(): string;

    public function verifyWebhook(Request $request): bool;

    public function webhookEventType(array $payload): ?string;

    public function handleWebhookEvent(ChannelWebhookEvent $event): string;
}
