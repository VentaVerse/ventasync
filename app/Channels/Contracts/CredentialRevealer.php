<?php

namespace App\Channels\Contracts;

interface CredentialRevealer
{
    public function revealableCredentials(): array;

    public function revealCredential(string $field, ?int $storeId = null): ?string;

    public function credentialManagePermission(): string;
}
