<?php

namespace App\Integrations\Concerns;

/**
 * The reveal half of CredentialRevealer, written once.
 *
 * Seven channels need identical logic around one risky operation: check the
 * allowlist, find the right settings row, hand back one column. Copying that
 * seven times is how the sixth copy ends up missing the allowlist check, and
 * this codebase has already been bitten twice this week by one concept living
 * in two places that drifted apart.
 *
 * A channel supplies three facts and nothing else:
 *
 *   revealableCredentials()      which fields may be read back, and their
 *                                labels. THIS IS THE ALLOWLIST - a column
 *                                absent from it cannot be requested at all,
 *                                which is what stops a caller naming an
 *                                arbitrary column. Public identifiers (app
 *                                keys, partner ids, shop ids) are deliberately
 *                                excluded: they are not secrets, so they are
 *                                shown in the page and never need revealing.
 *
 *   credentialSettingsModel()    where the row lives.
 *
 *   credentialManagePermission() who may ask.
 *
 * The allowlist is re-checked here even though CredentialRevealController
 * already checks it. A provider that trusts its caller is one refactor away
 * from being the hole.
 */
trait RevealsCredentials
{
    /** @return array<string, string> */
    abstract public function revealableCredentials(): array;

    abstract public function credentialManagePermission(): string;

    /** @return class-string */
    abstract protected function credentialSettingsModel(): string;

    /**
     * A multi-store channel keeps one settings row per store, so a store id
     * names something real and is required. A single-store channel has one
     * row, so a store id names nothing and is REFUSED rather than ignored:
     * quietly accepting it would make a multi-store-shaped request look as
     * though it had been honoured.
     */
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

        // Shopee, Lazada and TikTok encrypt these columns and expose a
        // decrypted() copy of the row. Venta, OpenCart, Shopify and Pedallion
        // encrypt too, but at write time in their controllers rather than
        // through the model, so they have no such method and the column comes
        // back as ciphertext. Reading the model's own accessor first and
        // decrypting after covers both without this method needing to know
        // which channel it is serving.
        if (method_exists($setting, 'decrypted')) {
            $setting = $setting->decrypted();
        }

        $value = $setting?->{$field} ?? null;

        if (! is_string($value) || $value === '') {
            return null;
        }

        // One place knows how these columns are stored.
        return \App\Support\Credentials::plaintext($value);
    }

}
