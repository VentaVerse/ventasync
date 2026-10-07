<?php

namespace App\Support;

use App\Models\ApiClient;
use Illuminate\Support\Facades\Auth;

final class Actor
{
    private function __construct(
        public readonly ?int $userId,
        public readonly ?string $name,
        public readonly ?int $apiClientId,
    ) {
    }

    public static function current(): self
    {
        $who = Auth::user();

        if ($who instanceof ApiClient) {
            return new self(null, (string) $who->name, (int) $who->id);
        }

        if ($who !== null) {
            $id = (int) $who->getAuthIdentifier();

            return new self($id, (string) ($who->name ?? $who->username ?? 'User #' . $id), null);
        }

        return new self(null, null, null);
    }

    public function isApplication(): bool
    {
        return $this->apiClientId !== null;
    }
}
