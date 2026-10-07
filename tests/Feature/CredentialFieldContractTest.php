<?php

namespace Tests\Feature;

use App\Integrations\Contracts\CredentialRevealer;
use App\Support\Credentials;
use Illuminate\Support\Facades\Crypt;
use Tests\TestCase;

class CredentialFieldContractTest extends TestCase
{
    private const CHANNELS = ['shopee', 'lazada', 'tiktok', 'ventacart', 'opencart', 'shopify', 'pedallion'];

    private function extensionSource(string $channel): string
    {
        $matches = glob(base_path("extensions/{$channel}/*Extension.php"));

        return $matches ? file_get_contents($matches[0]) : '';
    }

    public function test_every_channel_can_hand_its_credentials_back(): void
    {
        foreach (self::CHANNELS as $channel) {
            if (! is_dir(base_path("extensions/{$channel}"))) {
                continue;
            }
            $source = $this->extensionSource($channel);

            $this->assertNotSame('', $source, "No extension class found for {$channel}.");
            $this->assertStringContainsString('CredentialRevealer', $source,
                "{$channel} does not implement CredentialRevealer, so its credential fields cannot be "
                .'edited without retyping the whole value.');
            $this->assertStringContainsString('use RevealsCredentials;', $source,
                "{$channel} hand-rolls its reveal logic instead of using the shared one.");
        }
    }

    public function test_no_public_identifier_is_listed_as_revealable(): void
    {
        $checked = 0;

        foreach (self::CHANNELS as $channel) {
            if (! is_dir(base_path("extensions/{$channel}"))) {
                continue;
            }
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

    public function test_nothing_clears_the_field_when_editing_begins(): void
    {
        $js = file_get_contents(base_path('resources/js/credential-field.js'));

        $this->assertStringNotContainsString('beforeinput', $js,
            'The field clears itself on the first keystroke again.');

        $this->assertStringContainsString('this.edited = true', $js,
            'Edits are not tracked, so the field cannot tell a revealed value from an edited one.');

        $this->assertStringContainsString('if (! this.edited)', $js,
            'The field can be blanked on submit without checking whether it was edited.');

        $this->assertSame(1, substr_count($js, "value = ''"),
            'There is more than one place that empties this field.');
    }

    public function test_the_blur_handler_cannot_race_the_control(): void
    {
        $js = file_get_contents(base_path('resources/js/credential-field.js'));

        $this->assertStringContainsString('event.relatedTarget === this.$refs.toggle', $js,
            'Blur hides the field even when focus is moving to its own control, so Hide bounces back to revealed.');

        $this->assertStringNotContainsString('}, { once: true });', $js,
            'The blur listener is registered per reveal again, so the first blur consumes it whether or not it acted.');

        $this->assertStringContainsString('x-ref="toggle"',
            file_get_contents(base_path('resources/views/components/ui/credential.blade.php')),
            'The control is not addressable, so the blur guard cannot recognise it.');
    }

    public function test_an_edited_field_cannot_be_clobbered_by_revealing_again(): void
    {
        $js = file_get_contents(base_path('resources/js/credential-field.js'));
        $component = file_get_contents(base_path('resources/views/components/ui/credential.blade.php'));

        $this->assertMatchesRegularExpression('/if \(this->edited\) \{\s*\n\s*return;/',
            str_replace('this.edited', 'this->edited', $js),
            'toggle() will re-fetch over an edited field.');

        $this->assertStringContainsString('x-show="! edited"', $component,
            'The control stays visible after editing, inviting a press that would discard the edit.');
    }

    public function test_the_caption_shares_the_components_scope(): void
    {
        $component = file_get_contents(base_path('resources/views/components/ui/credential.blade.php'));

        $this->assertSame(1, substr_count($component, 'x-data='),
            'More than one scope in this component: a nested x-data shadows the outer one.');

        $dataAt = strpos($component, 'x-data=');
        $metaAt = strpos($component, 'x-cred__meta');

        $this->assertNotFalse($metaAt);
        $this->assertLessThan($metaAt, $dataAt,
            'The caption is declared before the scope that owns it, so `edited` reads as undefined there.');
    }

    public function test_only_one_handler_owns_a_managed_field(): void
    {
        $this->assertStringContainsString('credentialManaged', file_get_contents(base_path('resources/js/credential-field.js')));
        $this->assertStringContainsString("credentialManaged === '1'", file_get_contents(base_path('resources/js/credential-mask.js')),
            'credential-mask.js still blanks fields that credentialField owns.');
    }

    public function test_an_encrypted_value_comes_back_as_plaintext(): void
    {
        $this->assertSame('secret-value', Credentials::plaintext(Crypt::encryptString('secret-value')));
        $this->assertSame('secret-value', Credentials::plaintext(encrypt('secret-value')));
    }

    public function test_a_never_encrypted_value_comes_back_as_itself(): void
    {
        $this->assertSame('plain-token', Credentials::plaintext('plain-token'));
    }

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

    public function test_length_measures_the_plaintext_not_the_ciphertext(): void
    {
        $token = 'abcdefghij';
        $stored = Crypt::encryptString($token);

        $this->assertGreaterThan(100, strlen($stored));
        $this->assertSame(10, Credentials::length($stored));
    }

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
