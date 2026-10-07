<?php

namespace App\Services;

use App\Models\ActivityLog;
use App\Support\Actor;

class ActivityLogger
{
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
            $actor = Actor::current();

            if ($actor->isApplication() && $source === 'user') {
                $source = 'api';
            }

            $source = \App\Support\AppDoor::source($source);

            ActivityLog::create([
                'user_id'       => $actor->userId,
                'user_name'     => $actor->name,
                'api_client_id' => $actor->apiClientId,
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
        }
    }
}
