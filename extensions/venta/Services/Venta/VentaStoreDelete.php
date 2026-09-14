<?php

namespace Extensions\venta\Services\Venta;

use Extensions\venta\Models\VentaListing;
use Extensions\venta\Models\VentaProductGroup;
use Extensions\venta\Models\VentaProductGroupProduct;
use Extensions\venta\Models\VentaProductLink;
use Extensions\venta\Models\VentaSetting;

/**
 * Delete from {Store}: the channel's own act that removes the product AT
 * the store (a catalog delete never does, 2026-09-07). One sender for the
 * Listings page's row and bulk verbs and the group page's. On success the
 * link goes, the mirror is voided and every group place on this store
 * goes back to pending; a store that answers "not found" counts as gone.
 */
final class VentaStoreDelete
{
    public function __construct(private readonly VentaSetting $setting, private readonly VentaClient $client) {}

    public static function for(VentaSetting $setting): self
    {
        return new self($setting, new VentaClient($setting));
    }

    /**
     * @param list<int> $productIds
     * @return array{deleted:int, skipped:int, errors:array<int,string>}
     */
    public function delete(array $productIds): array
    {
        $ids = array_values(array_unique(array_map('intval', $productIds)));
        $out = ['deleted' => 0, 'skipped' => 0, 'errors' => []];
        $links = VentaProductLink::query()->where('venta_setting_id', $this->setting->id)->whereIn('product_id', $ids ?: [0])->get()->keyBy('product_id');
        $groupIds = VentaProductGroup::query()->where('venta_setting_id', $this->setting->id)->pluck('id')->all();
        $states = VentaListingStates::for((int) $this->setting->id);
        foreach ($ids as $pid) {
            $link = $links->get($pid);
            if (! $link || trim((string) $link->sku) === '') {
                $out['skipped']++;
                continue;
            }
            try {
                $r = $this->client->delete('products/' . rawurlencode((string) $link->sku));
            } catch (\Throwable $e) {
                $r = ['ok' => false, 'status' => 0, 'body' => ['error' => \App\Support\TransportError::plain($e, 'Venta')]];
            }
            $gone = ($r['ok'] ?? false) || (int) ($r['status'] ?? 0) === 404;
            if (! $gone) {
                $out['errors'][$pid] = (string) ($r['body']['error'] ?? $r['body']['message'] ?? 'no answer');
                $states->recordOutcome($pid, 'Delete from ' . $this->setting->store_name . ' failed: ' . $out['errors'][$pid]);
                continue;
            }
            $states->clearErrors([$pid]);
            $link->delete();
            VentaListing::query()->where('venta_setting_id', $this->setting->id)->where('product_id', $pid)
                ->update(['live_status' => null, 'live_checked_at' => null]);
            if ($groupIds !== []) {
                VentaProductGroupProduct::query()->where('product_id', $pid)->whereIn('venta_product_group_id', $groupIds)
                    ->update(['sync_status' => 'pending', 'push_error' => null, 'last_pushed_at' => null]);
            }
            $out['deleted']++;
        }

        return $out;
    }

    public static function sentence(array $r, string $storeName): string
    {
        $msg = "Delete from {$storeName}: {$r['deleted']} deleted";
        if ($r['skipped'] > 0) {
            $msg .= ", {$r['skipped']} skipped (not on the store)";
        }
        if ($r['errors']) {
            $msg .= ', ' . count($r['errors']) . ' failed. ' . implode('; ', array_slice(array_map(fn ($pid, $t) => "#{$pid}: {$t}", array_keys($r['errors']), $r['errors']), 0, 3));
        }

        return $msg . '.';
    }
}
