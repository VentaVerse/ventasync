<?php

namespace Extensions\ventacart\Services\VentaCart;

use Extensions\ventacart\Models\VentaCartListing;
use Extensions\ventacart\Models\VentaCartProductGroup;
use Extensions\ventacart\Models\VentaCartProductGroupProduct;
use Extensions\ventacart\Models\VentaCartProductLink;
use Extensions\ventacart\Models\VentaCartSetting;

final class VentaCartStoreDelete
{
    public function __construct(private readonly VentaCartSetting $setting, private readonly VentaCartClient $client) {}

    public static function for(VentaCartSetting $setting): self
    {
        return new self($setting, new VentaCartClient($setting));
    }

    public function delete(array $productIds): array
    {
        $ids = array_values(array_unique(array_map('intval', $productIds)));
        $out = ['deleted' => 0, 'skipped' => 0, 'errors' => []];
        $links = VentaCartProductLink::query()->where('ventacart_setting_id', $this->setting->id)->whereIn('product_id', $ids ?: [0])->get()->keyBy('product_id');
        $groupIds = VentaCartProductGroup::query()->where('ventacart_setting_id', $this->setting->id)->pluck('id')->all();
        $states = VentaCartListingStates::for((int) $this->setting->id);
        foreach ($ids as $pid) {
            $link = $links->get($pid);
            if (! $link || trim((string) $link->sku) === '') {
                $out['skipped']++;
                continue;
            }
            try {
                $r = $this->client->delete('products/' . rawurlencode((string) $link->sku));
            } catch (\Throwable $e) {
                $r = ['ok' => false, 'status' => 0, 'body' => ['error' => \App\Support\TransportError::plain($e, 'VentaCart')]];
            }
            $gone = ($r['ok'] ?? false) || (int) ($r['status'] ?? 0) === 404;
            if (! $gone) {
                $out['errors'][$pid] = (string) ($r['body']['error'] ?? $r['body']['message'] ?? 'no answer');
                $states->recordOutcome($pid, 'Delete from ' . $this->setting->store_name . ' failed: ' . $out['errors'][$pid]);
                continue;
            }
            $states->clearErrors([$pid]);
            $link->delete();
            VentaCartListing::query()->where('ventacart_setting_id', $this->setting->id)->where('product_id', $pid)
                ->update(['live_status' => null, 'live_checked_at' => null]);
            if ($groupIds !== []) {
                VentaCartProductGroupProduct::query()->where('product_id', $pid)->whereIn('ventacart_product_group_id', $groupIds)
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
