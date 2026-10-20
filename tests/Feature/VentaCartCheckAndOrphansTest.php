<?php

namespace Tests\Feature;

use Tests\TestCase;

class VentaCartCheckAndOrphansTest extends TestCase
{
    private const CONTROLLER = 'extensions/ventacart/Controllers/VentaCartProductGroupController.php';
    private const SERVICE = 'extensions/ventacart/Services/VentaCart/VentaCartProductPush.php';
    private const CHECK = 'extensions/ventacart/Services/VentaCart/VentaCartLinkCheck.php';

    private function source(string $file): string
    {
        return file_get_contents(base_path($file));
    }

    private function methodBody(string $method, ?string $file = null): string
    {
        $source = $this->source($file ?? self::CONTROLLER);

        if (! preg_match('/\n    (?:public|private) function '.$method.'\(/', $source, $m, PREG_OFFSET_CAPTURE)) {
            return '';
        }

        $start = $m[0][1];
        $next = preg_match('/\n    (?:public|private) function /', $source, $n, PREG_OFFSET_CAPTURE, $start + 10)
            ? $n[0][1]
            : strlen($source);

        return substr($source, $start, $next - $start);
    }

    public function test_only_a_404_may_mean_the_product_is_gone(): void
    {
        $body = $this->methodBody('reconcileLink', self::SERVICE);

        $this->assertStringContainsString('$status !== 404', $body,
            'reconcileLink no longer separates "VentaCart said no" from "VentaCart said nothing", '
            .'so an unreachable store reads as an empty one and its links get deleted.');
    }

    public function test_an_unanswered_check_is_its_own_verdict(): void
    {
        $body = $this->methodBody('reconcileLink', self::SERVICE);

        $this->assertStringContainsString("'state' => 'unreachable'", $body,
            'The unreachable verdict is gone, so a failed lookup falls through to lost or new '
            .'and something gets concluded from no evidence.');

        $unreachableAt = strpos($body, "'state' => 'unreachable'");
        $lostAt = strpos($body, "'state' => 'lost'");

        $this->assertNotFalse($unreachableAt);
        $this->assertNotFalse($lostAt);
        $this->assertLessThan($lostAt, $unreachableAt,
            'The lost branch is reachable before the unreachable guard, so a store that never '
            .'answered can still have its links deleted.');
    }

    public function test_an_unreachable_product_is_never_pushed(): void
    {
        $body = $this->methodBody('push', self::SERVICE);

        $this->assertStringContainsString("\$verdict['state'] === 'unreachable'", $body,
            'pushProducts pushes after a check that failed, so a create can land on top of a '
            .'product that already exists.');
    }

    public function test_an_unreachable_product_keeps_its_previous_row(): void
    {
        $body = $this->methodBody('run', self::CHECK);

        $this->assertMatchesRegularExpression(
            "/'unreachable'\s*\)\s*\{(?:(?!writeProductStatus).)*?continue;/s",
            $body,
            'The check writes a status for a product VentaCart never answered about, so a guess is '
            .'being recorded as a finding.'
        );
    }

    public function test_the_check_records_when_it_asked(): void
    {
        $this->assertStringContainsString("'last_confirmed_at' => now()", $this->methodBody('run', self::CHECK),
            'Checking leaves no trace, so the operator cannot tell a fresh answer from an old belief.');
    }

    public function test_checking_never_writes_to_ventacart(): void
    {
        $body = $this->methodBody('run', self::CHECK);

        foreach (['createProduct(', 'updateProduct(', 'updateVariant(', '$client->post(', '$client->put(', '$client->delete('] as $write) {
            $this->assertStringNotContainsString($write, $body,
                "The check calls {$write} - it is supposed to only ask questions, and the "
                .'confirm dialog promises it changes nothing on the store.');
        }
    }

    public function test_the_check_does_not_silently_cap_its_own_work(): void
    {
        $body = $this->methodBody('run', self::CHECK);

        $this->assertStringContainsString('$skippedForSize', $body,
            'The check has no ceiling, so a large group runs until the request times out.');

        $this->assertStringContainsString('not checked this time', $body,
            'The check stops early without saying so, so unchecked products look confirmed.');
    }
}
