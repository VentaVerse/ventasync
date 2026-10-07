<?php

namespace Tests\Feature;

use Tests\TestCase;

class VentaCartDisabledStoreTest extends TestCase
{
    private const CONTROLLER = 'extensions/ventacart/Controllers/VentaCartProductGroupController.php';

    private const WRITE_ACTIONS = [
        'syncId', 'checkAgainstVenta', 'pushProducts', 'pushStock', 'pushPrices', 'deleteFromVenta',
    ];

    private function source(string $file): string
    {
        return file_get_contents(base_path($file));
    }

    private function methodBody(string $source, string $method): string
    {
        if (! preg_match('/\n    (?:public|private) function '.$method.'\(/', $source, $m, PREG_OFFSET_CAPTURE)) {
            return '';
        }

        $start = $m[0][1];
        $next = preg_match('/\n    (?:public|private) function /', $source, $n, PREG_OFFSET_CAPTURE, $start + 10)
            ? $n[0][1]
            : strlen($source);

        return substr($source, $start, $next - $start);
    }

    public function test_every_write_action_resolves_its_store_through_the_guard(): void
    {
        $source = $this->source(self::CONTROLLER);

        foreach (self::WRITE_ACTIONS as $action) {
            $body = $this->methodBody($source, $action);

            $this->assertNotSame('', $body, "{$action} was not found; the guard list is stale.");

            $this->assertStringContainsString('$this->storeForWrite_($store)', $body,
                "VentaCart::{$action} resolves its store without the enabled guard, so it can write to a "
                .'live storefront that the operator has switched off.');

            $this->assertStringNotContainsString('$setting = $this->store_($store);', $body,
                "VentaCart::{$action} still uses the unguarded lookup.");
        }
    }

    public function test_the_guard_refuses_a_disabled_store(): void
    {
        $source = $this->source(self::CONTROLLER);

        $this->assertStringContainsString('return $setting->enabled ? $setting : null;', $source,
            'storeForWrite_ does not test the enabled flag.');
    }

    public function test_a_refusal_tells_the_operator_why(): void
    {
        $source = $this->source(self::CONTROLLER);

        foreach (self::WRITE_ACTIONS as $action) {
            $body = $this->methodBody($source, $action);

            $this->assertNotSame('', $body, "{$action} was not found; the guard list is stale.");

            $this->assertStringContainsString('VentaCart store is turned off', $body,
                "VentaCart::{$action} refuses a disabled store without telling the operator why.");
        }
    }

    private const READ_ONLY = [
        'VentaCartFeeKeys.php',
    ];

    public function test_no_console_command_can_reach_a_disabled_store(): void
    {
        foreach (glob(base_path('extensions/ventacart/Commands/*.php')) as $path) {
            $source = (string) file_get_contents($path);

            if (in_array(basename($path), self::READ_ONLY, true)) {
                foreach (['->update(', '->delete(', '->insert(', '->save()', 'client->', 'Client('] as $write) {
                    $this->assertStringNotContainsString($write, $source,
                        basename($path) . ' is listed as read-only and writes or calls out.');
                }
                continue;
            }

            $this->assertStringContainsString("where('enabled', true)", $source,
                basename($path).' can run against a disabled VentaCart store.');
        }
    }
}
