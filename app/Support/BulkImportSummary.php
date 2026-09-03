<?php

namespace App\Support;

/**
 * One flash line for "Import selected" on the channel import pages
 * (the owner, 2026-09-04). Every channel runs the same loop - each row
 * through its own importer, each in its own transaction - and lands
 * here so the wording cannot drift between channels.
 */
class BulkImportSummary
{
    /**
     * @param  list<string>  $failed  "name: reason" lines, in import order
     * @return array{tone: string, message: string}
     */
    public static function line(int $done, int $total, array $failed): array
    {
        if ($failed === []) {
            return ['tone' => 'status', 'message' => $done === 1
                ? 'Imported 1 item into your main catalog.'
                : "Imported {$done} items into your main catalog."];
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
