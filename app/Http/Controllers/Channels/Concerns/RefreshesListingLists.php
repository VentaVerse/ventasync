<?php

namespace App\Http\Controllers\Channels\Concerns;

use App\Channels\Contracts\ListingLists;

trait RefreshesListingLists
{
    abstract protected function listingLists(): ListingLists;

    public function refreshList(string $list)
    {
        $lists = $this->listingLists();
        abort_unless(in_array($list, $lists->names(), true), 404);
        $out = $lists->refresh($list);

        return response()->json($out, ! empty($out['ok']) ? 200 : 422);
    }
}
