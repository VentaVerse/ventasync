<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * Shopee gets the same reconcile Venta got, and for the same reason.
 *
 * Every action on a row decided what to do from a link row - the ERP's own
 * memory - which is wrong in both directions often enough to have produced a
 * closed trap. Delete an item in Seller Centre and the ERP went on believing
 * it was live: Update on Shopee could only answer item_not_found, Push was
 * skipped because push()'s first act was "skip anything already linked", and
 * Unlink led to "Link to Shopee again", which succeeded against a listing that
 * was not there.
 *
 * Three properties are pinned here.
 *
 * FIRST, silence is not an answer. Shopee replies HTTP 200 with an `error`
 * field, and its client retries twice and then THROWS, so there are three ways
 * to learn nothing and only one of them looks like a normal failure. Only an
 * error that names the item as missing may conclude the item is gone, because
 * concluding it deletes a link.
 *
 * SECOND, the search must not mistake a hidden item for a deleted one. An
 * UNLIST or BANNED item still occupies its SKU; treating it as absent would
 * drop a good link and create a duplicate listing beside the original.
 *
 * THIRD, reconcile runs BEFORE the create-versus-update decision, so the local
 * gates in createOnShopee and updateOnShopee are checked facts rather than
 * guesses.
 */
class ShopeeReconcileTest extends TestCase
{
    private const CONTROLLER = 'extensions/shopee/Controllers/ShopeeProductGroupController.php';

    /* The reconcile, the SKU index and the check sweep moved here whole
     * (Phase F, #184) so the workbench shares them; methodBody() follows. */
    private const CHECK = 'extensions/shopee/Services/Shopee/ShopeeLinkCheck.php';
    private const VIEW = 'extensions/shopee/views/product-groups/products.blade.php';

    private function source(string $file): string
    {
        return file_get_contents(base_path($file));
    }

    private function methodBody(string $method, ?string $file = null): string
    {
        $source = $this->source($file ?? self::CONTROLLER);

        if ($file === null && ! preg_match('/\n    (?:public|private) function '.$method.'\(/', $source, $m, PREG_OFFSET_CAPTURE)) {
            $source = $this->source(self::CHECK);
        }

        if (! preg_match('/\n    (?:public|private) function '.$method.'\(/', $source, $m, PREG_OFFSET_CAPTURE)) {
            return '';
        }

        $start = $m[0][1];
        $next = preg_match('/\n    (?:public|private) function /', $source, $n, PREG_OFFSET_CAPTURE, $start + 10)
            ? $n[0][1]
            : strlen($source);

        return substr($source, $start, $next - $start);
    }

    // ── Silence is not an answer ─────────────────────────────────────

    public function test_a_thrown_request_concludes_nothing(): void
    {
        $body = $this->methodBody('shopeeItemStillExists');

        $this->assertNotSame('', $body, 'shopeeItemStillExists is gone; nothing is verifying the link.');
        $this->assertStringContainsString('catch', $body,
            'A transport failure propagates instead of being reported as unknown, so a network blip '
            .'either 500s the push or gets read as the item being gone.');
        $this->assertStringContainsString("return 'unknown'", $body,
            'There is no unknown answer, so every failure to reach Shopee reads as absence.');
    }

    /**
     * Shopee answers 200 with an error field, so the HTTP status proves
     * nothing. An auth failure or a rate limit is silence, not a no.
     */
    public function test_only_an_error_naming_the_item_means_it_is_gone(): void
    {
        $body = $this->methodBody('shopeeItemStillExists');

        $this->assertStringContainsString('not_found', $body,
            'The missing-item error is no longer recognised, so a deleted item cannot be detected.');
        $this->assertMatchesRegularExpression(
            "/not_found.*?:\s*'unknown'/s",
            $body,
            'Any API error is treated as proof the item is gone, so a rate limit or an expired token '
            .'would delete good links.'
        );
    }

    public function test_a_hidden_item_is_not_a_deleted_one(): void
    {
        $body = $this->methodBody('skuIndex');

        foreach (['NORMAL', 'UNLIST', 'BANNED'] as $status) {
            $this->assertStringContainsString($status, $body,
                "The shop scan ignores {$status} items, so one of those would be reported as missing "
                .'and a duplicate listing created beside it.');
        }
    }

    public function test_only_deleted_counts_as_gone(): void
    {
        $this->assertStringContainsString("'DELETED'", $this->methodBody('shopeeItemStillExists'),
            'Item status is no longer inspected, so a live-but-hidden item may be treated as absent.');
    }

    public function test_an_incomplete_scan_never_concludes_absence(): void
    {
        $body = $this->methodBody('reconcile');

        $this->assertStringContainsString("\$index['complete']", $body,
            'A shop that could only be read halfway still yields a "not on Shopee" verdict, which '
            .'deletes links for products the scan simply never reached.');
    }

    /* ── The duplicate guard (#244, the owner 2026-09-09) ─────────────────
     *
     * His test shop held three copies of one product and four of another,
     * and this ERP held not one link row. Four separate teeth had to be
     * missing at once for that, and each one is pinned here. */

    /**
     * Every create asks the marketplace, not only our own table.
     *
     * The guard lives in pushOne, which is the ONE create path: the
     * one-click push and the multi-tick bulk push both go through it
     * (2026-09-09), so neither can grow its own way in.
     */
    public function test_every_push_reconciles_before_it_creates(): void
    {
        $controller = 'extensions/shopee/Controllers/ShopeeProductController.php';
        $src = $this->source($controller);

        foreach (['pushDirect', 'bulkPush'] as $entry) {
            $this->assertStringContainsString('$this->pushOne(', $this->methodBody($entry, $controller),
                "{$entry} creates without going through the one guarded create path.");
        }

        $body = $this->methodBody('pushOne', $controller);

        $this->assertNotSame('', $body, 'pushOne is gone.');
        $this->assertStringContainsString('reconcile(', $body,
            'The one-click push decides create-or-not from the local link row alone. A store deleted '
            .'here, or a database restored, takes every link while the shop keeps every item - and the '
            .'next press builds a second listing beside the first.');
        $this->assertStringContainsString("'taken'", $body,
            'A Shopee item already held by another catalog product would be pushed over.');
        $this->assertStringContainsString("'unreachable'", $body,
            'Creating while the shop could not be read is how a duplicate appears next to the original; '
            .'the group push has always refused it and this must too.');
    }

    /** A refusal from the shop is not a shop with nothing in it. */
    public function test_a_refused_scan_is_never_a_complete_one(): void
    {
        $body = $this->methodBody('skuIndex');

        $this->assertMatchesRegularExpression(
            '/\$res\[.ok.\].*?\$complete = false;/s',
            $body,
            'An item-list call that failed yields no items and reads as an empty shop, so an expired '
            .'token or a rate limit becomes "not on Shopee" and a duplicate is created. The owner\'s QA log '
            .'was full of invalid_acceess_token on the days the copies appeared.'
        );
    }

    /**
     * A MODEL'S SKU IS ITS OWN CALL.
     *
     * The shop index read $item['model_list'] off get_item_base_info, a
     * field Shopee does not put there - models come from get_model_list,
     * one item at a time, which is how every other model read in this
     * application already does it. So the index never held a single model
     * SKU, and an item with no parent SKU contributed NOTHING to it: the
     * one product that cannot be found any other way. The owner pushed such a
     * product, unlinked it, pushed again and got a duplicate, with the
     * guard in place and working exactly as written (2026-09-09).
     */
    public function test_the_shop_index_asks_for_the_models_it_needs(): void
    {
        $body = $this->methodBody('skuIndex');

        $this->assertStringContainsString('get_model_list', $body,
            'The index reads model SKUs off the base info, where Shopee never puts them, so it holds none '
            .'and a product with no parent SKU can never be matched back to its listing.');
        $this->assertStringContainsString("has_model", $body,
            'Every item is asked for its models, or none is: the base info says which items have them.');
        $this->assertStringNotContainsString("\$item['model_list']", $body,
            'The field that was never there is being read again.');
    }

    /** A parent with no SKU is not a product with no SKU. */
    public function test_the_search_knows_every_place_a_sku_lives(): void
    {
        $body = $this->methodBody('shopeeCandidateSkus');

        $this->assertStringContainsString('product_option_value', $body,
            'Variation SKUs are no longer candidates.');
        $this->assertStringContainsString('product_option_combinations', $body,
            'Combination SKUs are not candidates, so a product whose variations live there and whose '
            .'parent carries no SKU can never be matched back to its Shopee item - which is exactly the '
            .'product the owner pushed four times. Lazada and TikTok have always read both tables.');
    }

    /** A link is dropped only when the item is really gone. */
    public function test_an_undo_confirms_the_item_left(): void
    {
        $body = $this->methodBody('undoCreate', 'extensions/shopee/Services/Shopee/ShopeeVariationPush.php');

        $this->assertNotSame('', $body, 'undoCreate is gone.');
        $this->assertStringContainsString('itemIsGone(', $body,
            'delete_item answering ok is taken as proof the item left. An item that survives the call '
            .'leaves a live listing with nothing here pointing at it, which is the state that makes the '
            .'next push create a second one.');
    }

    /** The sweep can find a link, not only confirm one. */
    public function test_the_sweep_walks_the_store_not_only_its_links(): void
    {
        $body = $this->methodBody('storeProductIds');

        $this->assertNotSame('', $body, 'storeProductIds is gone.');
        $this->assertStringContainsString('ShopeeListing::query()', $body,
            'The sweep walks linked rows only, so an item whose link was lost is invisible to every '
            .'automatic path and the untargeted Check answers "nothing to check" over a shop full of items.');
    }

    // ── Every divergence has a verdict ───────────────────────────────

    public function test_every_divergence_has_a_verdict(): void
    {
        $body = $this->methodBody('reconcile');

        foreach (['confirmed', 'adopted', 'repointed', 'lost', 'new', 'taken', 'unreachable'] as $state) {
            $this->assertStringContainsString("'{$state}'", $body,
                "reconcileShopeeLink no longer reports '{$state}', so that divergence is handled "
                .'silently or not at all.');
        }
    }

    /**
     * A found item already held by another catalog product is reported, not
     * stolen. That link may be perfectly good, and taking it would leave the
     * other product silently pointing at nothing.
     */
    public function test_a_contested_item_is_not_taken_over(): void
    {
        $body = $this->methodBody('reconcile');

        $this->assertStringContainsString("'state' => 'taken'", $body);

        $takenAt = strpos($body, "'state' => 'taken'");
        $writeAt = strpos($body, 'ShopeeProductLink::updateOrCreate');

        $this->assertNotFalse($takenAt);
        $this->assertNotFalse($writeAt);
        $this->assertLessThan($writeAt, $takenAt,
            'The link is written before the contested check, so one Shopee item can end up claimed '
            .'by two catalog products.');
    }

    /**
     * Losing an item must not leave the row saying "unlinked". That word means
     * the operator did it deliberately, and it hides the product from Send.
     */
    public function test_a_lost_item_reads_as_not_pushed_not_as_unlinked(): void
    {
        $body = $this->methodBody('reconcile');

        $this->assertMatchesRegularExpression(
            "/unlinkProduct\(\\\$productId\);(?:(?!return).)*'sync_status' => 'pending'/s",
            $body,
            'A product Shopee lost is left marked unlinked, which reads as a deliberate act and '
            .'stops Send offering to recreate it.'
        );
    }

    // ── Reconcile decides; the local gate no longer guesses ──────────

    public function test_send_reconciles_before_it_decides(): void
    {
        $body = $this->methodBody('send');

        $reconcileAt = strpos($body, 'linkCheck->reconcile(');
        $createAt = strpos($body, 'createOnShopee(');
        $updateAt = strpos($body, 'updateOnShopee(');

        $this->assertNotFalse($reconcileAt, 'send no longer reconciles, so it is guessing again.');
        $this->assertNotFalse($createAt);
        $this->assertNotFalse($updateAt);

        $this->assertLessThan($createAt, $reconcileAt,
            'send creates before it has checked Shopee, so it can duplicate a listing that exists.');
        $this->assertLessThan($updateAt, $reconcileAt,
            'send updates before it has checked Shopee, so it can send an update for a deleted item.');
    }

    public function test_send_refuses_to_write_on_a_verdict_it_cannot_trust(): void
    {
        $body = $this->methodBody('send');

        $this->assertMatchesRegularExpression(
            "/'taken'.*?'unreachable'.*?continue;/s",
            $body,
            'send proceeds after a verdict of taken or unreachable, so it can create a duplicate or '
            .'tie two products to one listing.'
        );
    }

    // ── The row always offers one action that works ──────────────────

    public function test_the_row_always_offers_one_action_that_works(): void
    {
        $view = $this->source(self::VIEW);

        // One row menu on every channel (2026-09-07): the labels sit in the spec, and both the
        // unlisted row's Push and the listed row's Update post to Send, which asks Shopee first.
        $this->assertStringContainsString("'label' => 'Push to Shopee'", $view,
            'The row no longer offers Send, which is the only action that works in every state.');
        $this->assertGreaterThanOrEqual(2, substr_count($view, "route('ext.shopee.product-groups.send', \$group->id), 'label' =>"), 'Push and Update both ride Send');

        // The verbs it replaced. Any of them returning means the row can
        // once again present a set of mutually exclusive states and hide the
        // useful one behind the wrong guess. ('Push to Shopee' left this
        // list 2026-09-03: the one action RENAMED to it in the verb
        // unification - same single ungated action, the marketplace verb.)
        foreach (['Match Shopee ID', 'Link to Shopee again'] as $gone) {
            $this->assertDoesNotMatchRegularExpression(
                '/>\s*'.preg_quote($gone, '/').'\s*<\/button>/',
                $view,
                "The row menu offers '{$gone}' again, which is one of the mutually exclusive states "
                .'that made this a trap.'
            );
        }
    }

    /**
     * And Send is not gated on the link. Gating it is exactly how the trap
     * closed: the useful action disappeared precisely when it was needed.
     */
    public function test_send_is_not_hidden_by_any_status(): void
    {
        $view = $this->source(self::VIEW);
        $at = strpos($view, 'Push to Shopee');
        $this->assertNotFalse($at);

        $before = substr($view, max(0, $at - 700), 700);

        foreach (['$hasShopeeId', '$isUnlinked'] as $gate) {
            $this->assertStringNotContainsString('@if('.$gate, $before,
                "Send is wrapped in {$gate}, so the row can once again reach a state that offers "
                .'nothing which works.');
        }
    }

    // ── Checked versus believed ──────────────────────────────────────

    public function test_the_check_records_when_it_asked(): void
    {
        /* The stamp moved product-level (stage 2 of the one-truth
         * repointing, 2026-08-25): it lands on the listing record, where
         * every page reads it, instead of the pivot copy only the page the
         * check ran from could see. */
        $this->assertStringContainsString("'last_checked_at' => now()", $this->methodBody('run'),
            'Checking leaves no trace, so a fresh answer cannot be told from an old belief.');
        $this->assertStringNotContainsString("'last_confirmed_at'", $this->methodBody('run'),
            'The check is stamping the per-group copy again.');
    }

    public function test_a_send_also_counts_as_having_asked(): void
    {
        $this->assertStringContainsString("'last_checked_at' => now()", $this->methodBody('send'),
            'Send reconciles but does not record that it did, so a row checked seconds ago still '
            .'reads as never checked.');
    }

    public function test_the_row_says_which_of_the_two_it_is(): void
    {
        $view = $this->source(self::VIEW);

        $this->assertStringContainsString('$confirmedAt', $view);
        $this->assertStringContainsString('Not checked since the push', $view,
            'A row with no confirmation says nothing, so a badge written months ago looks identical '
            .'to one confirmed a minute ago.');
    }

    public function test_the_column_is_actually_selected(): void
    {
        $this->assertStringContainsString("'gp.last_confirmed_at'", $this->source(self::CONTROLLER),
            'The view reads last_confirmed_at but the query does not select it, so every row renders '
            .'as never checked.');
    }

    // ── The check and the orphan report only read ────────────────────

    public function test_checking_never_writes_to_shopee(): void
    {
        $body = $this->methodBody('run');

        foreach (['createOnShopee(', 'updateOnShopee(', '->postJson(', 'add_item', 'update_item'] as $write) {
            $this->assertStringNotContainsString($write, $body,
                "The check calls {$write} - it is supposed to only ask questions, and the confirm "
                .'dialog promises it changes nothing on Shopee.');
        }
    }

    public function test_the_check_does_not_silently_cap_its_own_work(): void
    {
        $body = $this->methodBody('run');

        $this->assertStringContainsString('$skippedForSize', $body,
            'The check has no ceiling, so a large group runs until the request times out.');
        $this->assertStringContainsString('not checked this time', $body,
            'The check stops early without saying so, so unchecked products look confirmed.');
    }

    public function test_the_orphan_report_only_reads(): void
    {
        $body = $this->methodBody('orphans');

        foreach (['updateOrCreate(', '->delete()', 'writeProductStatus(', 'unlinkProduct('] as $write) {
            $this->assertStringNotContainsString($write, $body,
                "The orphan report calls {$write}. Adopting a Shopee item by SKU without asking is "
                .'how a catalog product ends up pointed at a stranger.');
        }
    }

    public function test_the_orphan_report_admits_an_incomplete_read(): void
    {
        $this->assertStringContainsString("'complete' => \$index['complete']", $this->methodBody('orphans'),
            'The report does not pass on whether the scan finished, so a cut-off list reads as the '
            .'whole truth.');

        $this->assertStringContainsString(
            'may be incomplete',
            $this->source('extensions/shopee/views/product-groups/orphans.blade.php'),
            'The view never surfaces an incomplete scan.'
        );
    }

    /**
     * One row per item, not one per SKU. A product with four variations carries
     * four SKUs pointing at the same item.
     */
    public function test_orphans_are_grouped_by_item(): void
    {
        $this->assertStringContainsString('$byItem', $this->methodBody('orphans'),
            'Orphans are listed per SKU, so one item with four variations appears four times.');
    }

    // ── The shop is scanned once, not once per product ───────────────

    /**
     * Shopee has no get-item-by-SKU endpoint, so a lookup means paging the
     * whole shop: roughly forty calls. Per product that is ruinous on a group
     * of any size; memoised it is a fixed cost paid once and only when
     * something actually needs looking up.
     */
    public function test_the_shop_is_scanned_once_per_request(): void
    {
        $body = $this->methodBody('skuIndex');

        $this->assertStringContainsString('if ($this->shopeeSkuIndex !== null)', $body,
            'The shop-wide scan is no longer memoised, so a group of 137 products would page the '
            .'entire shop 137 times.');
    }

    /**
     * And the cheap question is asked first. Confirming an id we already hold
     * is one call; searching by SKU is forty. A reconcile that searched first
     * would pay the high price on the common case.
     */
    public function test_the_cheap_question_is_asked_first(): void
    {
        $body = $this->methodBody('reconcile');

        $existsAt = strpos($body, 'shopeeItemStillExists(');
        $indexAt = strpos($body, 'skuIndex(');

        $this->assertNotFalse($existsAt);
        $this->assertNotFalse($indexAt);
        $this->assertLessThan($indexAt, $existsAt,
            'The expensive shop scan runs before the single-item check, so confirming an unchanged '
            .'product costs forty calls instead of one.');
    }
}
