<?php

namespace Tests\Feature;

use Extensions\ventacart\Controllers\VentaCartProductGroupController;
use ReflectionMethod;
use Tests\TestCase;

class VentaCartImageVerdictTest extends TestCase
{
    private function verdict(array $response): array
    {
        $method = new ReflectionMethod(VentaCartProductGroupController::class, 'collectImageVerdict');

        $sent = 0;
        $stored = 0;
        $reasons = [];

        $method->invokeArgs(
            app(VentaCartProductGroupController::class),
            [$response, 1, &$sent, &$stored, &$reasons]
        );

        return ['sent' => $sent, 'stored' => $stored, 'reasons' => $reasons];
    }

    public function test_images_ventacart_kept_are_counted(): void
    {
        $v = $this->verdict(['body' => ['images' => ['a', 'b', 'c']]]);

        $this->assertSame(3, $v['sent']);
        $this->assertSame(3, $v['stored']);
        $this->assertSame([], $v['reasons']);
    }

    public function test_every_image_rejected_is_reported_with_its_reason(): void
    {
        $v = $this->verdict(['body' => ['images' => [], 'images_failed' => [
            ['url' => 'http://192.168.0.224:8000/a.jpg', 'reason' => "Host '192.168.0.224' resolves to a disallowed address."],
            ['url' => 'http://192.168.0.224:8000/b.jpg', 'reason' => "Host '192.168.0.224' resolves to a disallowed address."],
        ]]]);

        $this->assertSame(2, $v['sent']);
        $this->assertSame(0, $v['stored']);
        $this->assertSame(["Host '192.168.0.224' resolves to a disallowed address." => 2], $v['reasons'],
            'Rejections are not grouped by reason, so one cause reads as many separate problems.');
    }

    public function test_a_mixed_result_counts_both_sides(): void
    {
        $v = $this->verdict(['body' => ['images' => ['a'], 'images_failed' => [
            ['url' => 'u1', 'reason' => 'Exceeds 5MB limit'],
            ['url' => 'u2', 'reason' => 'Invalid type: text/html'],
        ]]]);

        $this->assertSame(3, $v['sent']);
        $this->assertSame(1, $v['stored']);
        $this->assertCount(2, $v['reasons']);
    }

    public function test_a_nested_response_is_read(): void
    {
        $this->assertSame(2, $this->verdict(['body' => ['data' => ['images' => ['a', 'b']]]])['stored']);
    }

    public function test_silence_claims_nothing(): void
    {
        foreach ([['body' => ['id' => 7]], [], ['body' => 'not an array']] as $response) {
            $v = $this->verdict($response);

            $this->assertSame(0, $v['sent']);
            $this->assertSame(0, $v['stored']);
            $this->assertSame([], $v['reasons']);
        }
    }

    public function test_a_rejection_without_a_reason_is_still_reported(): void
    {
        $v = $this->verdict(['body' => ['images_failed' => [['url' => 'u1'], ['url' => 'u2', 'reason' => '']]]]);

        $this->assertSame(2, $v['sent']);
        $this->assertSame(['no reason given' => 2], $v['reasons']);
    }

    public function test_a_taken_sku_tells_the_operator_how_to_get_out(): void
    {
        $method = new ReflectionMethod(VentaCartProductGroupController::class, 'pushFailureText');

        $message = $method->invoke(
            app(VentaCartProductGroupController::class),
            ['body' => ['message' => 'The sku has already been taken.', 'errors' => ['sku' => ['The sku has already been taken.']]]],
            'm-vave-tank-pro'
        );

        $this->assertStringContainsString('m-vave-tank-pro', $message,
            'The message does not say which SKU VentaCart already has.');
        $this->assertStringContainsString('Send', $message,
            'The message does not name the action that resolves this, so the row is a dead end.');

        $view = file_get_contents(base_path('extensions/ventacart/views/product-groups/products.blade.php'));
        $this->assertStringContainsString("'label' => 'Send to ' . \$storeName", $view,
            'The message tells the operator to press Send, but the row menu has no Send action.');
        $this->assertStringNotContainsString('{"', $message,
            'The raw response envelope is still being shown to the operator.');
    }

    public function test_an_unrelated_failure_is_not_rewritten(): void
    {
        $method = new ReflectionMethod(VentaCartProductGroupController::class, 'pushFailureText');

        $message = $method->invoke(
            app(VentaCartProductGroupController::class),
            ['body' => ['error' => 'Connection refused']],
            'some-sku'
        );

        $this->assertStringContainsString('Connection refused', $message);
        $this->assertStringNotContainsString('Match VentaCart ID', $message,
            'An unrelated failure is being given advice that does not apply to it.');
    }

    public function test_lost_images_are_not_reported_as_a_clean_success(): void
    {
        $source = file_get_contents(base_path('extensions/ventacart/Controllers/VentaCartProductGroupController.php'));

        $this->assertStringContainsString('$lostImages = $imagesSent > 0 && $imagesStored < $imagesSent;', $source,
            'Nothing tests whether images were lost, so a push that dropped them all still reports green.');

        $this->assertStringContainsString('$failed > 0 || $lostImages => \'warning\'', $source,
            'Lost images do not affect the tone of the result.');

        $this->assertStringContainsString('images were rejected by VentaCart', $source,
            'The summary does not mention images at all.');
    }
}
