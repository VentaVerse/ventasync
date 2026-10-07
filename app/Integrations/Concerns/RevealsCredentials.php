<?php

namespace App\Integrations\Concerns;

trait RevealsCredentials
{
    abstract public function revealableCredentials(): array;

    abstract public function credentialManagePermission(): string;

    abstract protected function credentialSettingsModel(): string;

    protected function credentialIsMultiStore(): bool
    {
        return false;
    }

    public function revealCredential(string $field, ?int $storeId = null): ?string
    {
        if (! array_key_exists($field, $this->revealableCredentials())) {
            return null;
        }

        $model = $this->credentialSettingsModel();

        if ($this->credentialIsMultiStore()) {
            if ($storeId === null) {
                return null;
            }

            $setting = $model::query()->whereKey($storeId)->first();
        } else {
            if ($storeId !== null) {
                return null;
            }

            $setting = $model::query()->first();
        }

        if (! $setting) {
            return null;
        }

        if (method_exists($setting, 'decrypted')) {
            $setting = $setting->decrypted();
        }

        $value = $setting?->{$field} ?? null;

        if (! is_string($value) || $value === '') {
            return null;
        }

        return \App\Support\Credentials::plaintext($value);
    }

}
