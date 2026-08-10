<?php

namespace App\Integrations\Contracts;

/**
 * A channel that can hand back one of its stored credentials in plaintext,
 * on an explicit request, for an operator who holds its manage permission.
 *
 * WHY THIS EXISTS AT ALL
 *
 * Settings forms mask stored credentials and never render the real value into
 * the page (commit 19086505: "the stored value never reaches the page source
 * for either tier"). That is still true and this contract does not weaken it.
 * What it adds is a second, deliberate route to the value: the operator asks
 * for one field, by name, and the answer travels in a response to that
 * request rather than sitting in every page load.
 *
 * The difference matters. A credential in page source is read by anything
 * that can read the page - an XSS, a cached document, a screenshot of view
 * source. A credential behind this contract is read only when someone with
 * the manage permission asks for it, and the asking is recorded.
 *
 * THE ALLOWLIST IS THE POINT
 *
 * revealableCredentials() is not documentation. It is the whitelist the
 * controller checks a requested field name against, so a caller cannot name
 * an arbitrary column and read it back. A channel that returns an empty array
 * reveals nothing, whatever is asked for.
 */
interface CredentialRevealer
{
    /**
     * The fields this channel will reveal, keyed by the exact field name a
     * request may name, valued by what that field is called on screen.
     *
     * Anything absent from this map is refused. Add a field here only when a
     * person genuinely needs to read it back - a shop id or a region is not a
     * credential and does not belong.
     *
     * @return array<string, string>
     */
    public function revealableCredentials(): array;

    /**
     * The plaintext of one stored credential, or null when the field holds
     * nothing.
     *
     * $storeId is the store on a multi-store channel and null elsewhere. An
     * implementation must scope to it: without that, an operator could read
     * one store's credential through another store's page.
     */
    public function revealCredential(string $field, ?int $storeId = null): ?string;

    /**
     * The permission an operator must hold to read this channel's
     * credentials, e.g. 'manage_lazada'.
     *
     * Declared rather than derived from the channel id: the naming holds for
     * some channels and not others, and a permission gate that guesses is a
     * permission gate that eventually guesses wrong.
     */
    public function credentialManagePermission(): string;
}
