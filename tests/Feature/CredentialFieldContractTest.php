<?php

namespace Tests\Feature;

use App\Integrations\Contracts\CredentialRevealer;
use App\Support\Credentials;
use Illuminate\Support\Facades\Crypt;
use Tests\TestCase;

/**
 * What a stored credential field is, on every channel.
 *
 * The old field was editable-but-masked and destroyed its own contents the
 * moment editing began, on the reasoning that a half-edited secret is a
 * corrupt secret. Sound reasoning, wrong interaction: nobody types a
 * 64-character token by hand, they paste, so the clearing never once saved
 * anyone from a corrupt secret and every single time it read as "I have just
 * destroyed the saved value".
 *
 * The field is now read-only until the operator asks for it. Asking reveals
 * the real value AND makes the field editable in place, so one wrong character
 * can be corrected instead of the whole token being re-pasted. Nothing is
 * cleared, ever.
 *
 * This only works where a channel can hand its stored value back, so all seven
 * implement CredentialRevealer now rather than Lazada alone.
 */
class CredentialFieldContractTest extends TestCase
{
    private const CHANNELS = ['shopee', 'lazada', 'tiktok', 'venta', 'opencart', 'shopify', 'pedallion'];

    private function extensionSource(string $channel): string
    {
        $matches = glob(base_path("extensions/{$channel}/*Extension.php"));

        return $matches ? file_get_contents($matches[0]) : '';
    }

    public function test_every_channel_can_hand_its_credentials_back(): void
    {
        foreach (self::CHANNELS as $channel) {
            $source = $this->extensionSource($channel);

            $this->assertNotSame('', $source, "No extension class found for {$channel}.");
            $this->assertStringContainsString('CredentialRevealer', $source,
                "{$channel} does not implement CredentialRevealer, so its credential fields cannot be "
                .'edited without retyping the whole value.');
            $this->assertStringContainsString('use RevealsCredentials;', $source,
                "{$channel} hand-rolls its reveal logic instead of using the shared one.");
        }
    }

    /**
     * The allowlist is the whole defence against a caller naming a column and
     * reading it back, so a public identifier must never appear in one. It
     * also must not: those are printed in the page already.
     */
    public function test_no_public_identifier_is_listed_as_revealable(): void
    {
        // Read from SOURCE, not from the registry. A bare test has no
        // extensions registered, so every credentialRevealer() lookup returned
        // null, the loop body never ran, and this test passed while checking
        // nothing at all - PHPUnit called it risky and PHPUnit was right.
        $checked = 0;

        foreach (self::CHANNELS as $channel) {
            $source = $this->extensionSource($channel);

            if (! preg_match('/function revealableCredentials\(\): array\s*\{\s*return \[(.*?)\];/s', $source, $m)) {
                continue;
            }

            preg_match_all("/'([a-z_]+)'\s*=>/", $m[1], $fields);

            foreach ($fields[1] as $field) {
                $checked++;

                $this->assertDoesNotMatchRegularExpression(
                    '/^(app_key|sandbox_app_key|partner_id|sandbox_partner_id|shop_id|sandbox_shop_id|region)$/',
                    $field,
                    "{$channel} lists '{$field}' as revealable, but it is a public identifier, not a secret."
                );
            }
        }

        $this->assertGreaterThan(10, $checked,
            'Almost no allowlisted fields were found, so this test is not reading what it thinks it is.');
    }

    /** A stored credential renders read-only, and nothing empties it. */
    public function test_a_stored_credential_renders_read_only(): void
    {
        $component = file_get_contents(base_path('resources/views/components/ui/credential.blade.php'));

        $this->assertStringContainsString(':readonly="$stored"', $component,
            'A stored credential is typeable without the operator asking, so it can be destroyed by accident.');
    }

    public function test_the_control_says_it_also_edits(): void
    {
        $component = file_get_contents(base_path('resources/views/components/ui/credential.blade.php'));

        $this->assertStringContainsString('View and edit', $component,
            'The control still says only "View", so nothing tells the operator how to change the value.');
    }

    /** The behaviour the rewrite exists to remove must not come back. */
    public function test_nothing_clears_the_field_when_editing_begins(): void
    {
        $js = file_get_contents(base_path('resources/js/credential-field.js'));

        // beforeinput was the hook that cleared on the first keystroke. Its
        // absence is the fix.
        $this->assertStringNotContainsString('beforeinput', $js,
            'The field clears itself on the first keystroke again.');

        $this->assertStringContainsString('this.edited = true', $js,
            'Edits are not tracked, so the field cannot tell a revealed value from an edited one.');

        // The one clear that IS correct: an unedited field posts blank, so
        // looking at a secret never rewrites it. Exactly one clear, and it
        // sits behind the edited guard.
        $this->assertStringContainsString('if (! this.edited)', $js,
            'The field can be blanked on submit without checking whether it was edited.');

        $this->assertSame(1, substr_count($js, "value = ''"),
            'There is more than one place that empties this field.');
    }

    /** Two submit handlers on one field is how a saved credential gets wiped. */
    public function test_only_one_handler_owns_a_managed_field(): void
    {
        $this->assertStringContainsString('credentialManaged', file_get_contents(base_path('resources/js/credential-field.js')));
        $this->assertStringContainsString("credentialManaged === '1'", file_get_contents(base_path('resources/js/credential-mask.js')),
            'credential-mask.js still blanks fields that credentialField owns.');
    }

    // ── Storage, which differs per channel and must not leak that fact ──

    public function test_an_encrypted_value_comes_back_as_plaintext(): void
    {
        $this->assertSame('secret-value', Credentials::plaintext(Crypt::encryptString('secret-value')));
        $this->assertSame('secret-value', Credentials::plaintext(encrypt('secret-value')));
    }

    public function test_a_never_encrypted_value_comes_back_as_itself(): void
    {
        $this->assertSame('plain-token', Credentials::plaintext('plain-token'));
    }

    /**
     * The case a copied database produces. Both Lazada's live app_secret and
     * OpenCart's api_key on this installation are in exactly this state: real
     * Laravel envelopes written under a different APP_KEY.
     *
     * Ciphertext must never be returned. It looks like a credential, so it
     * would be pasted into a marketplace dashboard and fail there instead of
     * here, hours later and somewhere else.
     */
    public function test_a_value_encrypted_under_another_key_is_refused(): void
    {
        $foreign = 'eyJpdiI6IndCZm9yZWlnbiIsInZhbHVlIjoibm9wZSIsIm1hYyI6ImJhZCJ9';

        $this->assertNull(Credentials::plaintext($foreign));
        $this->assertSame(0, Credentials::length($foreign),
            'An unreadable credential reports a length, so the field masks it and offers a reveal that cannot work.');
    }

    public function test_nothing_stored_is_nothing(): void
    {
        foreach ([null, ''] as $empty) {
            $this->assertNull(Credentials::plaintext($empty));
            $this->assertSame(0, Credentials::length($empty));
        }
    }

    /** The mask is sized to the value the operator would see, not the envelope. */
    public function test_length_measures_the_plaintext_not_the_ciphertext(): void
    {
        $token = 'abcdefghij';
        $stored = Crypt::encryptString($token);

        $this->assertGreaterThan(100, strlen($stored));
        $this->assertSame(10, Credentials::length($stored));
    }

    /** No settings form may go back to hand-rolling the mask. */
    public function test_no_settings_view_still_hand_rolls_a_mask(): void
    {
        foreach (glob(base_path('extensions/*/views/**/*.blade.php')) + glob(base_path('extensions/*/views/*.blade.php')) as $path) {
            $source = file_get_contents($path);

            if (! str_contains($source, 'data-credential-mask')) {
                continue;
            }

            $this->fail(
                str_replace(base_path().'/', '', $path)
                .' still hand-rolls a credential mask instead of using <x-ui.credential>.'
            );
        }

        $this->assertTrue(true);
    }
}
