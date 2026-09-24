<?php

namespace App\Support;

use App\Models\ApiClient;
use Illuminate\Support\Facades\Auth;

/**
 * Who is making the current change, for the columns every ledger keeps.
 *
 * A person signs in as a User; an assistant or other application arrives
 * as an ApiClient. The two tables number their rows independently, so an
 * application's id must never be written where a user id is expected: it
 * would name whichever person shares the number. An application is
 * recorded by its own name and its own id, with no user.
 */
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
