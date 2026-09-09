<?php

namespace Extensions\shopee\Commands;

use Extensions\shopee\Models\ShopeeProductLink;
use Extensions\shopee\Models\ShopeeSetting;
use Extensions\shopee\Services\Shopee\ShopeeLiveListing;
use Illuminate\Console\Command;

/**
 * TEMPORARY. Why does the Listings page read unsynced after a deploy?
 *
 * the owner, 2026-09-09: after pulling a new commit the products read as not
 * synced, and pressing Refresh or waiting for the cron puts them right.
 *
 * A pull changes files, not data, and no migration landed - so this walks
 * the page's OWN sequence and prints each answer, read only, so the state
 * at that moment is visible before a page load repairs it.
 *
 * Run it BEFORE opening the Listings page. Delete this command once the
 * question is answered.
 */
class ShopeeWhyUnsynced extends Command
{
    protected $signature = 'shopee:why-unsynced';

    protected $description = 'TEMPORARY: print what the Listings page sees, without changing anything';

    public function handle(ShopeeLiveListing $live): int
    {
        $stores = ShopeeSetting::query()->orderBy('id')->get();
        if ($stores->isEmpty()) {
            $this->error('No Shopee store at all.');

            return self::SUCCESS;
        }

        foreach ($stores as $store) {
            $label = $store->store_name ?: ('#' . $store->id);
            $this->line('');
            $this->info("=== Store {$label} (id {$store->id}) ===");
            $this->line('enabled: ' . ($store->enabled ? 'yes' : 'NO'));

            $auth = ShopeeSetting::activeAuth($store->decrypted());

            /* THE GATE. The page draws its tabs and fills blank statuses
             * only when this is true; when it is false every row reads
             * unchecked and nothing repairs it until the cron. */
            $this->line('mode: ' . $auth['mode']);
            $this->line('auth complete: ' . ($auth['complete'] ? 'YES' : 'NO  <- the page draws no tabs and fills no blanks'));
            foreach (['partner_id', 'partner_key', 'shop_id', 'access_token', 'refresh_token'] as $key) {
                $this->line('  ' . str_pad($key, 14) . (empty($auth[$key]) ? 'MISSING' : 'present'));
            }

            $expires = $auth['expires_at'] ? \Illuminate\Support\Carbon::parse($auth['expires_at']) : null;
            $this->line('  token expires : ' . ($expires
                ? $expires->toDateTimeString() . ' (' . ($expires->isPast() ? 'EXPIRED ' : 'in ') . $expires->diffForHumans(null, true) . ')'
                : 'not recorded'));

            // The mirror, exactly as the page reads it.
            $links = ShopeeProductLink::query()->withoutGlobalScope('shopeeStore')
                ->where('shopee_setting_id', $store->id)->get(['product_id', 'shopee_item_id', 'live_status', 'live_checked_at']);

            $this->line('');
            $this->line('link rows: ' . $links->count()
                . '  |  with a status: ' . $links->whereNotNull('live_status')->count()
                . '  |  BLANK: ' . $links->whereNull('live_status')->count());
            $this->line('last checked: ' . ($links->max('live_checked_at') ?: 'never'));

            foreach ($links->take(10) as $l) {
                $this->line(sprintf('  product #%-6d item %-12s %-14s %s',
                    $l->product_id, $l->shopee_item_id, $l->live_status ?: 'BLANK', $l->live_checked_at ?: '-'));
            }

            if (! $auth['complete']) {
                $this->warn('Stopping here: with the credentials incomplete the page asks Shopee nothing.');
                continue;
            }

            /* What the blank-fill would get if it ran now. This is the one
             * call the page makes, and a throw here is swallowed silently
             * on the page - which is the behaviour under suspicion. */
            $blank = $links->filter(fn ($l) => $l->live_status === null && (int) $l->shopee_item_id > 0);
            $this->line('');
            if ($blank->isEmpty()) {
                $this->line('Nothing blank to fill.');
            } else {
                $this->line('Asking Shopee for the ' . $blank->count() . ' blank one(s), the way the page would:');
                try {
                    $statuses = $live->statusesFor($auth, $blank->pluck('shopee_item_id')->all());
                    $this->info('  answered: ' . json_encode($statuses));
                } catch (\Throwable $e) {
                    $this->error('  REFUSED: ' . $e->getMessage());
                    $this->warn('  On the page this throw is caught and ignored, so every row stays blank.');
                }
            }
        }

        /* WHO DROPPED A LINK, AND ON WHAT GROUNDS. The scheduled check
         * writes one of these every time it lets a link go, so a link that
         * vanished overnight can be read rather than guessed at. */
        $this->line('');
        $this->info('=== Links dropped by the scheduled check ===');
        $dropped = \Illuminate\Support\Facades\DB::table('activity_logs')
            ->where('subject_type', 'Shopee Product')
            ->where('subject_label', 'like', 'Link to Shopee item%dropped%')
            ->orderByDesc('id')->limit(15)
            ->get(['created_at', 'subject_id', 'subject_label']);

        if ($dropped->isEmpty()) {
            $this->line('None recorded. (Only drops made after 2026-09-09 are written down.)');
        } else {
            foreach ($dropped as $row) {
                $this->line('  ' . $row->created_at . '  product #' . $row->subject_id . '  ' . $row->subject_label);
            }
        }

        $this->line('');
        $this->info('Nothing was changed.');

        return self::SUCCESS;
    }
}
