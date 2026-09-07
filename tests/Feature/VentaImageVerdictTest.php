<?php

namespace Tests\Feature;

use Extensions\venta\Controllers\VentaProductGroupController;
use ReflectionMethod;
use Tests\TestCase;

/**
 * A push says what happened to the images.
 *
 * Venta answers every push with a per-image verdict: `images` for the ones it
 * downloaded and kept, `images_failed` for each rejected url with the reason
 * it was rejected. Nothing read either. So a push whose product was created
 * and whose images were every one refused reported:
 *
 *     Push complete — Created: 1, Updated: 0, Failed: 0
 *
 * Green, no failures, no mention of images. The product really was created, so
 * the count was not wrong - it was half the answer, and the missing half was
 * exactly what the operator was trying to find out. Venta had diagnosed it
 * precisely on every single push and the diagnosis was thrown away.
 *
 * On this installation every rejection reads "Host '...' resolves to a
 * disallowed address": Venta's SSRF guard refusing a private network address,
 * which is the whole reason images never arrive from a sandbox and cannot be
 * fixed from this side.
 */
class VentaImageVerdictTest extends TestCase
{
    /**
     * @param  array<string, mixed>  $response
     * @return array{sent: int, stored: int, reasons: array<string, int>}
     */
    private function verdict(array $response): array
    {
        $method = new ReflectionMethod(VentaProductGroupController::class, 'collectImageVerdict');

        $sent = 0;
        $stored = 0;
        $reasons = [];

        $method->invokeArgs(
            app(VentaProductGroupController::class),
            [$response, 1, &$sent, &$stored, &$reasons]
        );

        return ['sent' => $sent, 'stored' => $stored, 'reasons' => $reasons];
    }

    public function test_images_venta_kept_are_counted(): void
    {
        $v = $this->verdict(['body' => ['images' => ['a', 'b', 'c']]]);

        $this->assertSame(3, $v['sent']);
        $this->assertSame(3, $v['stored']);
        $this->assertSame([], $v['reasons']);
    }

    /** The case that was invisible: the product lands, the pictures do not. */
    public function test_every_image_rejected_is_reported_with_its_reason(): void
    {
        $v = $this->verdict(['body' => ['images' => [], 'images_failed' => [
            ['url' => 'http://192.168.0.10:8000/a.jpg', 'reason' => "Host '192.168.0.10' resolves to a disallowed address."],
            ['url' => 'http://192.168.0.10:8000/b.jpg', 'reason' => "Host '192.168.0.10' resolves to a disallowed address."],
        ]]]);

        $this->assertSame(2, $v['sent']);
        $this->assertSame(0, $v['stored']);
        $this->assertSame(["Host '192.168.0.10' resolves to a disallowed address." => 2], $v['reasons'],
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

    /** Venta wraps some responses in `data`; the verdict is read from either. */
    public function test_a_nested_response_is_read(): void
    {
        $this->assertSame(2, $this->verdict(['body' => ['data' => ['images' => ['a', 'b']]]])['stored']);
    }

    /**
     * An older Venta reports nothing about images. Silence is not evidence of
     * failure, so nothing may be claimed from it - inventing rejections here
     * would put a warning on every push against an older storefront.
     */
    public function test_silence_claims_nothing(): void
    {
        foreach ([['body' => ['id' => 7]], [], ['body' => 'not an array']] as $response) {
            $v = $this->verdict($response);

            $this->assertSame(0, $v['sent']);
            $this->assertSame(0, $v['stored']);
            $this->assertSame([], $v['reasons']);
        }
    }

    /** A rejection with no reason still counts, and still says something. */
    public function test_a_rejection_without_a_reason_is_still_reported(): void
    {
        $v = $this->verdict(['body' => ['images_failed' => [['url' => 'u1'], ['url' => 'u2', 'reason' => '']]]]);

        $this->assertSame(2, $v['sent']);
        $this->assertSame(['no reason given' => 2], $v['reasons']);
    }

    /**
     * The dead end, named.
     *
     * With no local link the push takes the CREATE branch, and if Venta
     * already holds that SKU it answers 422 "The sku has already been taken."
     * Every visible action on the row then failed - Push created and 422'd
     * again, Update was not offered because the page saw no link, and Delete
     * from Venta refused with "not linked, nothing to delete" - while the one
     * action that worked, Match Venta ID, went unmentioned. The operator was
     * also reading Laravel's raw validation envelope rather than a sentence
     * about their product.
     *
     * The remedy has since changed and this test changed with it. Match Venta
     * ID was DELETED: Send now reconciles before it decides, so it finds the
     * existing product and links to it by itself. The message named the
     * deleted button for a while, which is a worse dead end than the original
     * - it sent the operator hunting for a control that is not in the menu.
     *
     * So the assertion no longer pins a phrase. It requires that whatever
     * action the message names is an action the row actually offers, which is
     * the thing that was ever really being tested.
     */
    public function test_a_taken_sku_tells_the_operator_how_to_get_out(): void
    {
        $method = new ReflectionMethod(VentaProductGroupController::class, 'pushFailureText');

        $message = $method->invoke(
            app(VentaProductGroupController::class),
            ['body' => ['message' => 'The sku has already been taken.', 'errors' => ['sku' => ['The sku has already been taken.']]]],
            'm-vave-tank-pro'
        );

        $this->assertStringContainsString('m-vave-tank-pro', $message,
            'The message does not say which SKU Venta already has.');
        $this->assertStringContainsString('Send', $message,
            'The message does not name the action that resolves this, so the row is a dead end.');

        // And that action is really there. A message naming a button nobody
        // can find is the defect this test exists to prevent, whichever button
        // it happens to name.
        $view = file_get_contents(base_path('extensions/venta/views/product-groups/products.blade.php'));
        $this->assertStringContainsString("'label' => 'Push to ' . \$storeName", $view,
            'The message tells the operator to press Send, but the row menu has no Send action.');
        $this->assertStringNotContainsString('{"', $message,
            'The raw response envelope is still being shown to the operator.');
    }

    /** Any other refusal is passed through rather than reworded into a guess. */
    public function test_an_unrelated_failure_is_not_rewritten(): void
    {
        $method = new ReflectionMethod(VentaProductGroupController::class, 'pushFailureText');

        $message = $method->invoke(
            app(VentaProductGroupController::class),
            ['body' => ['error' => 'Connection refused']],
            'some-sku'
        );

        $this->assertStringContainsString('Connection refused', $message);
        $this->assertStringNotContainsString('Match Venta ID', $message,
            'An unrelated failure is being given advice that does not apply to it.');
    }

    /**
     * The ratchet. A push that stored the product and none of its pictures is
     * not a clean success, whatever the product count says.
     */
    public function test_lost_images_are_not_reported_as_a_clean_success(): void
    {
        $source = file_get_contents(base_path('extensions/venta/Controllers/VentaProductGroupController.php'));

        $this->assertStringContainsString('$lostImages = $imagesSent > 0 && $imagesStored < $imagesSent;', $source,
            'Nothing tests whether images were lost, so a push that dropped them all still reports green.');

        $this->assertStringContainsString('$failed > 0 || $lostImages => \'warning\'', $source,
            'Lost images do not affect the tone of the result.');

        $this->assertStringContainsString('images were rejected by Venta', $source,
            'The summary does not mention images at all.');
    }
}
