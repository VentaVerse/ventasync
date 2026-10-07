<?php

namespace App\Support;

class BulkImportSummary
{
    public static function line(int $done, int $total, array $failed): array
    {
        if ($failed === []) {
            return ['tone' => 'status', 'message' => $done === 1
                ? 'Imported 1 item into your Master Catalog.'
                : "Imported {$done} items into your Master Catalog."];
        }

        $shown = array_slice($failed, 0, 3);
        $more = count($failed) - count($shown);
        $detail = implode(' | ', $shown) . ($more > 0 ? " (and {$more} more)" : '');

        if ($done === 0) {
            return ['tone' => 'error', 'message' => "None of the {$total} selected items could be imported. " . $detail];
        }

        return ['tone' => 'status', 'message' => "Imported {$done} of {$total} selected items. Not imported: " . $detail];
    }
}
