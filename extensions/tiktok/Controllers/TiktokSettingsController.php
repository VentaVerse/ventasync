<?php

namespace Extensions\tiktok\Controllers;

/**
 * The Tiktok connection: credentials, tokens, status maps, logging.
 *
 * A thin subclass so these routes derive their own key (tiktok/settings)
 * instead of the retired bundle key (tiktok/overview). All logic stays in
 * TikTokController; the catalog keys on the routed class, so the split is a
 * routing fact, not a code move. See LazadaSettingsController for the
 * full reasoning.
 */
use Illuminate\Http\Request;

class TiktokSettingsController extends TikTokController
{

    /** The return status map (#156): blank rows are unmapped and deleted. */
    public function saveReturnStatusMap(Request $request)
    {
        $map = $request->input('map', []);
        if (!is_array($map)) {
            return back()->with('status', 'Invalid mapping data.');
        }
        foreach ($map as $tiktokStatus => $orderStatusId) {
            $tiktokStatus = strtoupper(trim((string) $tiktokStatus));
            $orderStatusId = (int) $orderStatusId;
            if ($tiktokStatus === '') {
                continue;
            }
            if ($orderStatusId <= 0) {
                \Extensions\tiktok\Models\TikTokOrderStatusMap::where('tiktok_status', $tiktokStatus)->where('context', 'return')->delete();
                continue;
            }
            \Extensions\tiktok\Models\TikTokOrderStatusMap::updateOrCreate(
                ['tiktok_status' => $tiktokStatus, 'context' => 'return'],
                ['order_status_id' => $orderStatusId]
            );
        }

        return back()->with('status', 'Return status mapping saved.');
    }
}
