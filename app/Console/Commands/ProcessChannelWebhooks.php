<?php

namespace App\Console\Commands;

use App\Integrations\IntegrationRegistry;
use App\Models\ChannelWebhookEvent;
use Illuminate\Console\Command;

class ProcessChannelWebhooks extends Command
{
    protected $signature = 'webhooks:process {--limit=200}';

    protected $description = 'Apply verified channel webhook deliveries to the ERP';

    public function handle(IntegrationRegistry $registry): int
    {
        $events = ChannelWebhookEvent::query()
            ->where('signature_ok', true)
            ->whereNull('processed_at')
            ->orderBy('id')
            ->limit((int) $this->option('limit'))
            ->get();

        $done = 0;
        foreach ($events as $event) {
            $receiver = $registry->webhookReceiverFor($event->channel);
            try {
                $outcome = $receiver
                    ? $receiver->handleWebhookEvent($event)
                    : 'no receiver for channel ' . $event->channel . ' (disabled?)';
            } catch (\Throwable $e) {
                $outcome = 'failed: ' . mb_substr($e->getMessage(), 0, 200);
            }
            $event->forceFill([
                'processed_at' => now(),
                'outcome' => mb_substr($outcome, 0, 255),
            ])->save();
            $done++;
        }

        $this->info("Processed {$done} webhook event(s).");

        return 0;
    }
}
