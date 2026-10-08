<?php

namespace Extensions\TikTok\Controllers;

use Illuminate\Http\Request;

class TikTokSettingsController extends TikTokController
{

    public function saveReturnStatusMap(Request $request)
    {
        $map = $request->input('map', []);
        if (!is_array($map)) {
            return $this->toSettingsTab('status')->with('status', 'Invalid mapping data.');
        }
        foreach ($map as $tiktokStatus => $orderStatusId) {
            $tiktokStatus = strtoupper(trim((string) $tiktokStatus));
            $orderStatusId = (int) $orderStatusId;
            if ($tiktokStatus === '') {
                continue;
            }
            if ($orderStatusId <= 0) {
                \Extensions\TikTok\Models\TikTokOrderStatusMap::where('tiktok_status', $tiktokStatus)->where('context', 'return')->delete();
                continue;
            }
            \Extensions\TikTok\Models\TikTokOrderStatusMap::updateOrCreate(
                ['tiktok_status' => $tiktokStatus, 'context' => 'return'],
                ['order_status_id' => $orderStatusId]
            );
        }

        return $this->toSettingsTab('status')->with('status', 'Return status mapping saved.');
    }
}
