<?php

namespace Extensions\venta\Services\Venta;

use Extensions\venta\Models\VentaProductGroup;
use Extensions\venta\Models\VentaSetting;
use Illuminate\Support\Facades\DB;

/**
 * Ask a Venta store about these products and make the ERP agree - the
 * group page's check, extracted whole so the Listings workbench runs
 * the same sweep (the one-workbench rule the Big 3 already follow,
 * #184/#194). The reconcile itself has always lived on
 * VentaProductPush; this owns the loop, the verdict filing and the
 * honest summary.
 *
 * Reads only, toward Venta: it confirms, adopts, repoints or drops
 * LINKS and refreshes the pivot mirrors; it writes nothing to the
 * storefront.
 */
class VentaLinkCheck
{
    /**
     * The next page of this store's links, checked (the owner, 2026-09-07).
     * Both the Refresh button and the scheduled refresh call this, which is
     * what stops them from drifting apart: the cron used to re-read statuses
     * and never re-run the check, so a failed check could not clear itself
     * and a row kept a six-month-old refusal until somebody pressed the
     * button. The cursor caps a run at two hundred and continues where the
     * last one stopped.
     *
     * @return ?array the check's own result, or null when nothing is linked
     */
    public function runNext(VentaSetting $setting): ?array
    {
        $linked = \Extensions\venta\Models\VentaProductLink::query()->where('venta_setting_id', $setting->id)
            ->orderBy('product_id')->pluck('product_id')->map(fn ($v) => (int) $v)->unique()->values()->all();
        $linked = \App\Integrations\Support\LinkCheckCursor::rotate('venta:' . (int) $setting->id, $linked);

        return $linked !== [] ? $this->run($setting, $linked) : null;
    }

    /** @return array{tone: 'status'|'warning'|'error', summary: string} */
    public function run(VentaSetting $setting, array $ids): array
    {
        $settingId = (int) $setting->id;
        $storeName = $setting->store_name ?: 'Unnamed store';
        $pfx = (string) config('catalog.prefix');
        $push = VentaProductPush::for($setting);

        $verdicts = [];
        $clashes = [];

        // A ceiling, because this runs in a web request and each product costs
        // a round trip. It is reported rather than applied quietly: a check
        // that silently examined the first 200 of 500 would leave the operator
        // believing the other 300 had been confirmed.
        $limit = 200;
        $skippedForSize = max(0, count($ids) - $limit);
        $ids = array_slice($ids, 0, $limit);

        $storeName = $setting->store_name ?: 'Unnamed store';
        $pfx = (string) config('catalog.prefix');
        $client = new VentaClient($setting);

        $verdicts = [];
        $clashes = [];
        $states = VentaListingStates::for($settingId);

        foreach ($ids as $pid) {
            $verdict = $push->reconcileLink($pid);
            $state = $verdict['state'];
            $verdicts[$state] = ($verdicts[$state] ?? 0) + 1;

            if ($state === 'unreachable') {
                // Venta never answered, so nothing is known and nothing is
                // written. The row keeps whatever it said before, which is
                // honest: it was always a belief and it still is.
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
                // reconcileLink has already dropped the dead link. The row goes
                // back to not-pushed, which is what it truthfully is.
                $this->writeProductStatus($settingId, $pid, [
                    'sync_status' => 'pending',
                    'push_error' => null,
                    'venta_sku' => null,
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

            // confirmed, adopted, repointed: Venta has it and the link is right.
            // A read never clears the listing's error; only a write to the store does.
            $this->writeProductStatus($settingId, $pid, [
                'sync_status' => 'pushed',
                'venta_sku' => $verdict['sku'],
                'push_error' => null,
                'last_confirmed_at' => now(),
            ]);
        }

        $checked = count($ids) - ($verdicts['unreachable'] ?? 0);

        // The message reports FINDINGS. Nothing here pushed anything, so it
        // must not imply that anything was fixed on Venta's side.
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
            $parts[] = count($clashes) . ' blocked by another product holding the same Venta id';
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

    /** The store-wide pivot write the group page has always done. */
    private function writeProductStatus(int $settingId, int $productId, array $attributes): void
    {
        \App\Support\ChannelProductStatus::write(
            'venta_product_group_products',
            'venta_product_group_id',
            VentaProductGroup::where('venta_setting_id', $settingId)->pluck('id')->all(),
            $productId,
            $attributes
        );
    }
}
