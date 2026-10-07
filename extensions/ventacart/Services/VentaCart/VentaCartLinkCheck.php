<?php

namespace Extensions\ventacart\Services\VentaCart;

use Extensions\ventacart\Models\VentaCartProductGroup;
use Extensions\ventacart\Models\VentaCartSetting;
use Illuminate\Support\Facades\DB;

class VentaCartLinkCheck
{
    public function runNext(VentaCartSetting $setting): ?array
    {
        $linked = \Extensions\ventacart\Models\VentaCartProductLink::query()->where('ventacart_setting_id', $setting->id)
            ->orderBy('product_id')->pluck('product_id')->map(fn ($v) => (int) $v)->unique()->values()->all();
        $linked = \App\Integrations\Support\LinkCheckCursor::rotate('ventacart:' . (int) $setting->id, $linked);

        return $linked !== [] ? $this->run($setting, $linked) : null;
    }

    public function run(VentaCartSetting $setting, array $ids): array
    {
        $settingId = (int) $setting->id;
        $storeName = $setting->store_name ?: 'Unnamed store';
        $pfx = (string) config('catalog.prefix');
        $push = VentaCartProductPush::for($setting);

        $verdicts = [];
        $clashes = [];

        $limit = 200;
        $skippedForSize = max(0, count($ids) - $limit);
        $ids = array_slice($ids, 0, $limit);

        $storeName = $setting->store_name ?: 'Unnamed store';
        $pfx = (string) config('catalog.prefix');
        $client = new VentaCartClient($setting);

        $verdicts = [];
        $clashes = [];
        $states = VentaCartListingStates::for($settingId);

        foreach ($ids as $pid) {
            $verdict = $push->reconcileLink($pid);
            $state = $verdict['state'];
            $verdicts[$state] = ($verdicts[$state] ?? 0) + 1;

            if ($state === 'unreachable') {
                continue;
            }

            if ($state === 'blocked') {
                $clashes[] = "#{$pid}: {$verdict['message']}";
                $this->writeProductStatus($settingId, $pid, [
                    'sync_status' => 'error',
                    'push_error' => $verdict['message'],
                    'last_confirmed_at' => now(),
                ]);
                $states->recordOutcome($pid, (string) $verdict['message']);
                continue;
            }

            if ($state === 'lost') {
                $this->writeProductStatus($settingId, $pid, [
                    'sync_status' => 'pending',
                    'push_error' => null,
                    'ventacart_sku' => null,
                    'last_pushed_at' => null,
                    'last_confirmed_at' => now(),
                ]);
                $states->recordOutcome($pid, 'No longer on ' . $storeName . ', so its link was removed.');
                continue;
            }

            if ($state === 'new') {
                $this->writeProductStatus($settingId, $pid, [
                    'sync_status' => 'pending',
                    'push_error' => null,
                    'last_confirmed_at' => now(),
                ]);
                continue;
            }

            $this->writeProductStatus($settingId, $pid, [
                'sync_status' => 'pushed',
                'ventacart_sku' => $verdict['sku'],
                'push_error' => null,
                'last_confirmed_at' => now(),
            ]);
        }

        $checked = count($ids) - ($verdicts['unreachable'] ?? 0);

        $parts = [];
        if (! empty($verdicts['confirmed'])) {
            $parts[] = $verdicts['confirmed'] . ' still on ' . $storeName;
        }
        if (! empty($verdicts['adopted'])) {
            $parts[] = $verdicts['adopted'] . ' found and linked';
        }
        if (! empty($verdicts['repointed'])) {
            $parts[] = $verdicts['repointed'] . ' pointed at the wrong product and corrected';
        }
        if (! empty($verdicts['lost'])) {
            $parts[] = $verdicts['lost'] . ' no longer on ' . $storeName . ', now marked not pushed';
        }
        if (! empty($verdicts['new'])) {
            $parts[] = $verdicts['new'] . ' not pushed yet';
        }
        if ($clashes) {
            $parts[] = count($clashes) . ' blocked by another product holding the same VentaCart id';
        }
        if (! empty($verdicts['unreachable'])) {
            $parts[] = $verdicts['unreachable'] . ' could not be checked because ' . $storeName . ' did not answer';
        }

        $msg = "Checked {$checked} against {$storeName}. " . ($parts ? implode('. ', $parts) . '.' : 'Nothing to report.');

        if ($skippedForSize > 0) {
            $msg .= " {$skippedForSize} further " . ($skippedForSize === 1 ? 'product was' : 'products were')
                . ' not checked this time. Run it again to continue.';
        }

        if ($clashes) {
            $msg .= ' ' . implode('; ', array_slice($clashes, 0, 2));
            if (count($clashes) > 2) {
                $msg .= ' (and ' . (count($clashes) - 2) . ' more)';
            }
        }

        $problems = count($clashes) + ($verdicts['unreachable'] ?? 0);
        $tone = $problems === 0 ? 'status' : ($checked > 0 ? 'warning' : 'error');

        return ['tone' => $tone, 'summary' => $msg];
    }

    private function writeProductStatus(int $settingId, int $productId, array $attributes): void
    {
        \App\Support\ChannelProductStatus::write(
            'ventacart_product_group_products',
            'ventacart_product_group_id',
            VentaCartProductGroup::where('ventacart_setting_id', $settingId)->pluck('id')->all(),
            $productId,
            $attributes
        );
    }
}
