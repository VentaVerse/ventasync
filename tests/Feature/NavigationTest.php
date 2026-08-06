<?php

namespace Tests\Feature;

use App\Support\Navigation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class NavigationTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Extension routes only register when their `extensions` row is
     * enabled, which nothing in a freshly migrated test schema ever sets
     * (see PurchaseOrderReceiveCurrencyTest for the same workaround). The
     * nav references routes from opencart, purchasing, warehousing,
     * reports and pettycash, so load each extension's own route file
     * directly, bypassing the DB gate, then rebuild the route name lookup
     * table so Route::has()/route() can see the newly registered names.
     */
    protected function setUp(): void
    {
        parent::setUp();

        foreach (['opencart', 'purchasing', 'warehousing', 'reports', 'pettycash'] as $extension) {
            app('router')->middleware('web')->group(base_path("extensions/{$extension}/routes/web.php"));
        }

        app('router')->getRoutes()->refreshNameLookups();
    }

    /**
     * The defect this catches: a previous plan listed `couriers.index`, which
     * does not exist. A Route::has() guard hid the card silently, so a missing
     * destination looked like a deliberate omission.
     */
    public function test_every_route_named_in_the_nav_exists(): void
    {
        $missing = [];

        foreach (Navigation::definition() as $entry) {
            foreach ($entry['items'] ?? [$entry] as $item) {
                if (isset($item['route']) && !Route::has($item['route'])) {
                    $missing[] = $item['route'];
                }
            }
        }

        $this->assertSame([], $missing, 'Nav references routes that do not exist: '.implode(', ', $missing));
    }

    /**
     * Permissions are derived from each route's own perm: middleware rather
     * than hand-maintained, so the nav cannot drift from routes/web.php.
     */
    public function test_permission_is_derived_from_route_middleware(): void
    {
        $this->assertSame('view_orders|manage_orders', Navigation::permissionFor('orders.index'));
        $this->assertSame('view_products|manage_products', Navigation::permissionFor('products.index'));
        $this->assertNull(Navigation::permissionFor('dashboard'));
    }

    public function test_unknown_route_yields_no_permission_and_is_not_allowed(): void
    {
        $this->assertNull(Navigation::permissionFor('route.that.does.not.exist'));
        $this->assertFalse(Navigation::allows('route.that.does.not.exist'));
    }
}
