<?php

namespace Tests\Feature;

use App\Models\Admin\Permission;
use App\Models\Admin\UserGroup;
use App\Models\User;
use App\Services\PermissionCatalogue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class ControllerPermissionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $manager = $this->app->make(\App\Extensions\ExtensionManager::class);
        $manager->install('lazada');
        $manager->enable('lazada');
        $this->app->register(\Extensions\lazada\LazadaExtension::class);
        $this->app['router']->getRoutes()->refreshNameLookups();

        $this->artisan('permissions:sync-catalogue');
    }

    private int $groupSeq = 0;

    private function groupWith(array $keys, string $name = 'Test group'): UserGroup
    {
        $group = UserGroup::create(['name' => $name . ' ' . (++$this->groupSeq)]);
        $group->permissions()->attach(Permission::whereIn('key', $keys)->pluck('id')->all());

        return $group;
    }

    private function userWith(array $keys): User
    {
        return User::factory()->create(['user_group_id' => $this->groupWith($keys)->id]);
    }

    public function test_every_gated_controller_has_a_key_derived_from_its_routes(): void
    {
        $catalog = $this->app->make(PermissionCatalogue::class);
        $missing = [];

        foreach (Route::getRoutes() as $route) {
            $action = $route->getActionName();

            if (! str_contains($action, '@')) {
                continue;
            }

            [$class] = explode('@', $action);

            if ($catalog->neverGated($class)) {
                continue;
            }

            if ($catalog->keyForController($class) === null) {
                $missing[] = $class;
            }
        }

        $this->assertSame([], array_unique($missing),
            'a controller with no key is a screen nobody can be granted or denied');
    }

    public function test_every_derived_key_exists_as_a_permission_row(): void
    {
        $catalog = $this->app->make(PermissionCatalogue::class);
        $rows = Permission::pluck('key')->flip();

        $missing = array_values(array_filter(
            $catalog->keys(),
            fn ($key) => ! isset($rows[$key])
        ));

        $this->assertSame([], $missing,
            'the migration derives these from the routes; a gap means it did not run or the routes moved');
    }

    public function test_an_area_with_no_write_route_offers_no_manage_tier(): void
    {
        $catalog = $this->app->make(PermissionCatalogue::class);
        $readOnly = array_filter($catalog->all(), fn ($e) => $e['writes'] === 0);

        $this->assertNotEmpty($readOnly, 'this test is only meaningful while a read-only area exists');

        foreach ($readOnly as $entry) {
            $this->assertSame(['view'], $entry['tiers'],
                $entry['key'] . ' has no write route and must not advertise a manage tier');
        }
    }

    public function test_a_view_grant_opens_a_read_and_not_a_write(): void
    {
        $user = $this->userWith(['view_catalog/product']);

        $this->actingAs($user)->get(route('products.index'))->assertOk();

        $this->actingAs($user)
            ->delete(route('products.destroy', 1))
            ->assertRedirect()
            ->assertSessionHas('error');
    }

    public function test_a_manage_grant_opens_both(): void
    {
        $user = $this->userWith(['manage_catalog/product']);

        $this->actingAs($user)->get(route('products.index'))->assertOk();
    }

    public function test_a_grant_for_one_area_does_not_open_another(): void
    {
        $user = $this->userWith(['manage_catalog/product']);

        $this->actingAs($user)
            ->get(route('user_groups.index'))
            ->assertStatus(403)
            ->assertSee('You are not allowed to access this page');
    }

    public function test_a_route_that_declares_no_permission_is_still_gated(): void
    {
        Route::middleware('web')->get('/__test_ungated', [
            \App\Http\Controllers\Catalog\ProductController::class, 'index',
        ]);

        $route = Route::getRoutes()->getRoutes();
        $mine = collect($route)->first(fn ($r) => $r->uri() === '__test_ungated');

        $declared = collect($mine->gatherMiddleware())
            ->filter(fn ($m) => is_string($m) && str_starts_with($m, 'perm:'));

        $this->assertTrue($declared->isEmpty(), 'the premise of this test is a route with no perm: argument');

        $stranger = $this->userWith(['view_settings/user_group']);

        $this->actingAs($stranger)
            ->get('/__test_ungated')
            ->assertStatus(403)
            ->assertSee('You are not allowed to access this page');
    }

    public function test_a_route_may_declare_a_read_tier_despite_its_verb(): void
    {
        $route = Route::getRoutes()->getByName('ext.lazada.orders.bulk_awb');

        $this->assertNotNull($route);
        $this->assertSame('view', $route->defaults['permission_tier'] ?? null,
            'bulk AWB is a read; without this declaration the verb puts it behind manage');
    }

    public function test_the_retired_scheme_has_left_the_database(): void
    {
        $this->assertSame(
            0,
            \Illuminate\Support\Facades\DB::table('permissions')->where('key', 'not like', '%/%')->count(),
            'A slash-less permission row survived the Phase D prune - or something recreated one.'
        );
    }

    public function test_a_view_grant_reaches_the_read_and_is_refused_the_write(): void
    {
        $user = $this->userWith(['view_catalog/product']);

        $this->actingAs($user)->get(route('products.index'))->assertOk();

        $this->actingAs($user)
            ->delete(route('products.destroy', 1))
            ->assertRedirect()
            ->assertSessionHas('error');
    }

    public function test_a_route_with_no_auth_is_left_to_its_own_devices(): void
    {
        $this->get(route('lazada.callback'))->assertOk();
    }

    public function test_a_surface_that_gates_per_item_is_not_gated_at_the_door(): void
    {
        $catalog = $this->app->make(PermissionCatalogue::class);

        foreach ([
            \App\Http\Controllers\Integrations\IntegrationController::class,
            \App\Http\Controllers\AutomationsController::class,
            \App\Http\Controllers\Fulfilment\OrderPrintController::class,
        ] as $class) {
            $this->assertTrue($catalog->neverGated($class),
                $class . ' decides per item and must not also be gated at the door');
        }
    }

    public function test_each_channel_dashboard_derives_its_own_key(): void
    {
        $catalog = $this->app->make(PermissionCatalogue::class);

        $this->assertArrayHasKey(
            'lazada/dashboard',
            $catalog->all(),
            "lazada's dashboard has fallen back off the permission editor"
        );
    }

    public function test_every_exempt_controller_actually_checks_for_itself(): void
    {
        foreach ([
            'app/Http/Controllers/Integrations/IntegrationController.php',
            'app/Http/Controllers/AutomationsController.php',
            'app/Http/Controllers/Fulfilment/OrderPrintController.php',
        ] as $file) {
            $source = file_get_contents(base_path($file));

            $this->assertMatchesRegularExpression(
                '/hasPermission|allows\(|abort_unless|Gate::/',
                $source,
                $file . ' is exempt from the door gate and performs no check of its own'
            );
        }
    }
    public function test_a_denied_page_load_renders_the_not_allowed_page(): void
    {
        $user = $this->userWith(['view_catalog/product']);

        $response = $this->actingAs($user)->get(route('user_groups.index'));

        $response->assertStatus(403);
        $response->assertSee('You are not allowed to access this page');
        $response->assertSee('Contact your administrator');

        $this->actingAs($user)
            ->delete(route('user_groups.destroy', 999))
            ->assertRedirect();
    }
}
