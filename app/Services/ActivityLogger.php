<?php

namespace App\Services;

use App\Models\ActivityLog;
use Illuminate\Support\Facades\Auth;

class ActivityLogger
{
    /**
     * Log an activity.
     *
     * @param string      $action       e.g. 'created', 'updated', 'deleted', 'login', 'logout'
     * @param string|null $subjectType  e.g. 'Product', 'Order', 'User'
     * @param int|null    $subjectId    e.g. the product_id
     * @param string|null $subjectLabel e.g. "iPhone 15 Pro (SKU: IP15P)"
     * @param array|null  $changes      e.g. ['name' => ['Old Name', 'New Name']]
     */
    /**
     * Compute a field-level diff between a model's original and current attributes.
     *
     * @param  array  $original  Snapshot taken BEFORE save via $model->getAttributes()
     * @param  array  $current   Snapshot taken AFTER save via $model->getAttributes()
     * @param  array  $only      Only diff these fields (whitelist). Empty = all fields.
     * @param  array  $except    Exclude these fields from diff.
     * @return array|null        e.g. ['name' => ['Old', 'New']] or null if nothing changed
     */
    public static function diff(
        array $original,
        array $current,
        array $only = [],
        array $except = ['date_modified', 'updated_at', 'date_added', 'created_at'],
    ): ?array {
        $changes = [];
        $fields = !empty($only) ? $only : array_keys(array_merge($original, $current));

        foreach ($fields as $field) {
            if (in_array($field, $except, true)) {
                continue;
            }

            $old = $original[$field] ?? null;
            $new = $current[$field] ?? null;

            if ((string) $old !== (string) $new) {
                $changes[$field] = [(string) ($old ?? ''), (string) ($new ?? '')];
            }
        }

        return empty($changes) ? null : $changes;
    }

    public static function log(
        string  $action,
        ?string $subjectType = null,
        ?int    $subjectId = null,
        ?string $subjectLabel = null,
        ?array  $changes = null,
        string  $source = 'user',
    ): void {
        try {
            $user = Auth::user();

            ActivityLog::create([
                'user_id'       => $user?->id,
                'user_name'     => $user ? ($user->name ?? $user->username ?? 'User #' . $user->id) : null,
                'action'        => $action,
                'subject_type'  => $subjectType,
                'subject_id'    => $subjectId,
                'subject_label' => $subjectLabel ? mb_substr($subjectLabel, 0, 255) : null,
                'changes'       => $changes,
                'ip_address'    => request()->ip(),
                'source'        => $source,
                'created_at'    => now(),
            ]);
        } catch (\Throwable $e) {
            // Never let activity logging break the main flow
        }
    }
}
