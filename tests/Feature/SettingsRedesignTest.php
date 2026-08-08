<?php

namespace Tests\Feature;

use App\Extensions\ExtensionManager;
use App\Models\Admin\Permission;
use App\Models\Admin\UserGroup;
use App\Models\ApiClient;
use App\Models\Catalog\OrderStatus;
use App\Models\Currency;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Settings redesign, Round E, Task 1.
 *
 * The fifteen settings CRUD views move from the legacy layout onto
 * `layouts.shell`: settings/{users,user_groups,order_statuses,currencies,api}
 * x {index,create,edit}.
 *
 * The views were rebuilt from scratch, so the only thing that proves the wire
 * survived is posting what the rendered form actually emits and reading the
 * rows back. In rough order of how much it would hurt to lose:
 *
 *   1. THE PERMISSION MATRIX. settings/user_groups/{create,edit} IS the
 *      security model. Its posted shape is a flat `permissions[]` array of DB
 *      permission ids, carried by one hidden input per tier per resource, with
 *      exactly the active tier's input enabled. Both the emitted shape and the
 *      grant/revoke round trip are asserted here, including the two cascades
 *      UserGroupController documents (child grant elevates its parent; a
 *      parent switched off takes its children with it).
 *
 *   2. THE CURRENCY RATE SEMANTIC. OrderCurrencyService's contract is that a
 *      rate is "units of the default currency per 1 unit of this currency".
 *      The redesigned pages say that out loud in the copy, so the copy is
 *      wrong the moment the stored value stops meaning it. Asserted by saving
 *      a rate and converting through the service.
 *
 *   3. NO SECRET ECHOES. `personal_access_tokens.token` is a SHA-256 hash and
 *      the plaintext exists for exactly one flashed request. Asserted that
 *      neither the list nor the edit page ever carries either.
 *
 *   4. THE ORDER-STATUS FLAG TRAP. OrderStatusController reads both flags with
 *      $request->has(), so the usual "hidden 0 in front of the checkbox" trick
 *      would pin both flags ON forever. Asserted from both ends: the views
 *      carry no such hidden input, and a post that omits them lands zeroes.
 *
 * Wire truth throughout: app/Http/Controllers/Settings/*, gated by
 * routes/settings.php (read tier `view_x|manage_x`, every write `manage_x`).
 */
class SettingsRedesignTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Core permissions reach the `permissions` table via
     * config/permissions.php + syncCorePermissions(), which nothing runs
     * automatically on a fresh migrate. Every key this test set touches
     * (view_users, manage_users, view_user_groups, ...) is a core key, so
     * without this call the fixtures would attach nothing and every gate
     * assertion would pass for the wrong reason.
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->app->make(ExtensionManager::class)->syncCorePermissions();
    }

    // ------------------------------------------------------------------
    // Fixtures
    // ------------------------------------------------------------------

    private function userWithPermissions(array $keys, string $label = null): User
    {
        $group = UserGroup::create(['name' => 'Test group '.($label ?? implode('-', $keys))]);

        $group->permissions()->attach(
            Permission::whereIn('key', $keys)->pluck('id')->all()
        );

        return User::factory()->create(['user_group_id' => $group->id]);
    }

    /**
     * Holds a real permission, just not one any settings route asks for, so a
     * "the request was simply unauthenticated" explanation cannot account for
     * a passing denial assertion.
     */
    private function unrelatedUser(): User
    {
        return $this->userWithPermissions(['view_shopee'], 'unrelated');
    }

    private function seedCurrencies(): void
    {
        Currency::updateOrCreate(['code' => 'PHP'], [
            'name' => 'Philippine Peso', 'symbol' => '₱',
            'exchange_rate' => 1, 'is_default' => 1, 'status' => 1,
        ]);
        Currency::updateOrCreate(['code' => 'USD'], [
            'name' => 'US Dollar', 'symbol' => '$',
            'exchange_rate' => 61.61429452, 'is_default' => 0, 'status' => 1,
        ]);
    }

    private function seedOrderStatus(string $name = 'Pending'): OrderStatus
    {
        return OrderStatus::create([
            'language_id' => 1, 'name' => $name,
            'subtract_stock' => 0, 'add_revenue' => 0,
        ]);
    }

    private function seedApiClient(string $name, array $abilities, $expiresAt = null): ApiClient
    {
        $client = ApiClient::create(['name' => $name, 'active' => true, 'created_by' => 1]);
        $client->createToken($client->name, $abilities, $expiresAt);

        return $client->fresh();
    }

    /** The manage-tier permission each family's writes require. */
    private const MANAGE_KEYS = [
        'users'          => 'manage_users',
        'user_groups'    => 'manage_user_groups',
        'order_statuses' => 'manage_order_statuses',
        'currencies'     => 'manage_website_settings',
        'api'            => 'manage_api_clients',
    ];

    /** The read-tier permission each family's index accepts on its own. */
    private const VIEW_KEYS = [
        'users'          => 'view_users',
        'user_groups'    => 'view_user_groups',
        'order_statuses' => 'view_order_statuses',
        'currencies'     => 'view_website_settings',
        'api'            => 'view_api_clients',
    ];

    /** Every view file this task owns. */
    private function ownedViewFiles(): array
    {
        $files = [];
        foreach (['users', 'user_groups', 'order_statuses', 'currencies', 'api'] as $family) {
            foreach (['index', 'create', 'edit'] as $page) {
                $files[] = resource_path("views/settings/{$family}/{$page}.blade.php");
            }
        }
        // The two partials those pages include. The permission picker is the
        // security model's markup and the scope matrix is the API wire, so
        // both are held to the same rules as the pages themselves.
        $files[] = resource_path('views/settings/user_groups/partials/permissions.blade.php');
        $files[] = resource_path('views/settings/api/partials/scopes.blade.php');

        return $files;
    }

    // ==================================================================
    // 1. RENDERS
    // ==================================================================

    public function test_every_settings_view_renders_on_the_shell_for_a_manager(): void
    {
        $this->seedCurrencies();
        $status = $this->seedOrderStatus();
        $client = $this->seedApiClient('Pricing assistant', ['products:read']);

        $manager = $this->userWithPermissions(array_values(self::MANAGE_KEYS), 'all-manage');
        $subject = $this->userWithPermissions(['view_users'], 'subject');
        $group = UserGroup::where('name', 'Test group subject')->firstOrFail();
        $currency = Currency::where('code', 'USD')->firstOrFail();

        $urls = [
            route('users.index'),
            route('users.create'),
            route('users.edit', $subject->id),
            route('user_groups.index'),
            route('user_groups.create'),
            route('user_groups.edit', $group->id),
            route('order_statuses.index'),
            route('order_statuses.create'),
            route('order_statuses.edit', $status->order_status_id),
            route('currencies.index'),
            route('currencies.create'),
            route('currencies.edit', $currency->id),
            route('api_clients.index'),
            route('api_clients.create'),
            route('api_clients.edit', $client->id),
        ];

        foreach ($urls as $url) {
            $response = $this->actingAs($manager)->get($url);

            $response->assertOk();
            // On the shell, not the legacy layout, and out of the legacy
            // quarantine wrapper: `x-app` is the body class layouts/shell puts
            // on, and `x-legacy` is the content boundary a migrated page drops.
            $response->assertSee('class="x-app"', false);
            $response->assertDontSee('x-legacy', false);
        }
    }

    // ==================================================================
    // 2. GATING, BOTH DIRECTIONS, PER FAMILY
    // ==================================================================

    public function test_the_read_tier_reaches_every_index_and_no_write_page(): void
    {
        $this->seedCurrencies();
        $status = $this->seedOrderStatus();
        $client = $this->seedApiClient('Pricing assistant', ['products:read']);
        $currency = Currency::where('code', 'USD')->firstOrFail();

        $writeUrls = [
            'users'          => [route('users.create')],
            'user_groups'    => [route('user_groups.create')],
            'order_statuses' => [route('order_statuses.create'), route('order_statuses.edit', $status->order_status_id)],
            'currencies'     => [route('currencies.create'), route('currencies.edit', $currency->id)],
            'api'            => [route('api_clients.create'), route('api_clients.edit', $client->id)],
        ];
        $indexUrls = [
            'users'          => route('users.index'),
            'user_groups'    => route('user_groups.index'),
            'order_statuses' => route('order_statuses.index'),
            'currencies'     => route('currencies.index'),
            'api'            => route('api_clients.index'),
        ];

        foreach (self::VIEW_KEYS as $family => $viewKey) {
            $viewer = $this->userWithPermissions([$viewKey], 'viewer-'.$family);

            // Reaches its own index...
            $this->actingAs($viewer)->get($indexUrls[$family])->assertOk();

            // ...and is bounced off every write page in the same family.
            foreach ($writeUrls[$family] as $url) {
                $denied = $this->actingAs($viewer)->get($url);
                $denied->assertRedirect();
                $denied->assertSessionHas('error');
            }
        }
    }

    public function test_a_user_holding_no_settings_permission_reaches_nothing(): void
    {
        $this->seedCurrencies();
        $stranger = $this->unrelatedUser();

        foreach ([
            route('users.index'),
            route('user_groups.index'),
            route('order_statuses.index'),
            route('currencies.index'),
            route('api_clients.index'),
        ] as $url) {
            $denied = $this->actingAs($stranger)->get($url);
            $denied->assertRedirect();
            $denied->assertSessionHas('error');
        }
    }

    public function test_a_read_tier_index_offers_no_write_control(): void
    {
        $this->seedCurrencies();
        $this->seedOrderStatus();
        $this->seedApiClient('Pricing assistant', ['products:read']);

        // The pre-redesign views hardcoded `$canManageX = true`, so a
        // read-tier operator saw New / Edit / Delete on every row and only
        // discovered the truth from a bounced redirect. Each redesigned index
        // derives the flag from the same permission its write routes require.
        $checks = [
            ['view_users', route('users.index'), route('users.create')],
            ['view_user_groups', route('user_groups.index'), route('user_groups.create')],
            ['view_order_statuses', route('order_statuses.index'), route('order_statuses.create')],
            ['view_website_settings', route('currencies.index'), route('currencies.create')],
            ['view_api_clients', route('api_clients.index'), route('api_clients.create')],
        ];

        foreach ($checks as [$viewKey, $indexUrl, $createUrl]) {
            $viewer = $this->userWithPermissions([$viewKey], 'nowrite-'.$viewKey);
            $response = $this->actingAs($viewer)->get($indexUrl);

            $response->assertOk();
            $response->assertDontSee($createUrl, false);
            // No destroy affordance either: `data-confirm` is this project's
            // only destructive trigger, so its absence is the whole test.
            $response->assertDontSee('data-confirm', false);
        }
    }

    // ==================================================================
    // 3. THE PERMISSION MATRIX. The headline of this task.
    // ==================================================================

    /**
     * The emitted shape, before any round trip: for every resource the editor
     * offers, one hidden `permissions[]` input per tier that exists, carrying
     * that tier's real DB id, with exactly the currently-granted tier enabled
     * and every other one `disabled` (a disabled input does not submit, which
     * is how "Off" posts nothing at all).
     */
    public function test_the_editor_emits_one_hidden_input_per_tier_with_only_the_granted_one_enabled(): void
    {
        $manager = $this->userWithPermissions(['manage_user_groups'], 'matrix-shape');

        $target = UserGroup::create(['name' => 'Shape group']);
        $manageProducts = Permission::where('key', 'manage_products')->firstOrFail();
        $viewOrders = Permission::where('key', 'view_orders')->firstOrFail();
        $target->permissions()->sync([$manageProducts->id, $viewOrders->id]);

        $html = $this->actingAs($manager)
            ->get(route('user_groups.edit', $target->id))
            ->assertOk()
            ->getContent();

        // Both tiers of `products` are present as inputs...
        $viewProducts = Permission::where('key', 'view_products')->firstOrFail();
        $this->assertStringContainsString('data-perm-key="manage_products"', $html);
        $this->assertStringContainsString('data-perm-key="view_products"', $html);

        // ...the granted one carries its real id and is NOT disabled...
        $this->assertMatchesRegularExpression(
            '/<input[^>]*name="permissions\[\]"[^>]*value="'.$manageProducts->id.'"[^>]*data-perm-key="manage_products"[^>]*>/',
            $html
        );
        $this->assertDoesNotMatchRegularExpression(
            '/<input[^>]*value="'.$manageProducts->id.'"[^>]*data-perm-key="manage_products"[^>]*\sdisabled/',
            $html
        );

        // ...and the ungranted sibling tier of the same resource is disabled,
        // so it cannot submit alongside it.
        $this->assertMatchesRegularExpression(
            '/<input[^>]*value="'.$viewProducts->id.'"[^>]*data-perm-key="view_products"[^>]*\sdisabled/',
            $html
        );

        // The view tier of `orders` is the granted one there, and its manage
        // sibling is the disabled one. Same rule, other tier.
        $manageOrders = Permission::where('key', 'manage_orders')->firstOrFail();
        $this->assertDoesNotMatchRegularExpression(
            '/<input[^>]*value="'.$viewOrders->id.'"[^>]*data-perm-key="view_orders"[^>]*\sdisabled/',
            $html
        );
        $this->assertMatchesRegularExpression(
            '/<input[^>]*value="'.$manageOrders->id.'"[^>]*data-perm-key="manage_orders"[^>]*\sdisabled/',
            $html
        );
    }

    /**
     * THE ROUND TRIP. Grant across all three tiers in one save and assert the
     * `user_group_permissions` rows land exactly, id for id.
     */
    public function test_granting_across_tiers_lands_exactly_those_permission_rows(): void
    {
        $manager = $this->userWithPermissions(['manage_user_groups'], 'grant');
        $target = UserGroup::create(['name' => 'Grant group']);

        $this->assertSame([], $target->permissions()->pluck('permissions.id')->all());

        $ids = fn (array $keys) => Permission::whereIn('key', $keys)->pluck('id', 'key')->all();
        $p = $ids(['manage_products', 'view_orders', 'manage_reports', 'view_catalog', 'view_sales']);

        // Exactly what the browser posts after clicking Manage on products,
        // View on orders and Manage on reports: the three chosen tiers plus
        // the two parents the client-side cascade elevates. Verified against
        // the real form in a browser before this test was written.
        $posted = [
            $p['view_catalog'],     // parent, elevated by the products grant
            $p['manage_products'],
            $p['view_sales'],       // parent, elevated by the orders/reports grants
            $p['view_orders'],
            $p['manage_reports'],
        ];

        $response = $this->actingAs($manager)->put(route('user_groups.update', $target->id), [
            'name' => 'Grant group',
            'permissions' => $posted,
        ]);

        $response->assertRedirect(route('user_groups.index'));

        $landed = DB::table('user_group_permissions')
            ->where('user_group_id', $target->id)
            ->pluck('permission_id')
            ->sort()->values()->all();

        sort($posted);
        $this->assertSame($posted, $landed, 'the permission rows must land exactly as posted');

        // And the tiers really are distinct: a "manage" grant must not have
        // silently also written the "view" row for the same resource.
        $keys = Permission::whereIn('id', $landed)->pluck('key')->sort()->values()->all();
        $this->assertContains('manage_products', $keys);
        $this->assertNotContains('view_products', $keys);
        $this->assertContains('view_orders', $keys);
        $this->assertNotContains('manage_orders', $keys);
    }

    /**
     * The other direction, which is the half that actually removes access.
     */
    public function test_revoking_removes_exactly_those_permission_rows(): void
    {
        $manager = $this->userWithPermissions(['manage_user_groups'], 'revoke');
        $target = UserGroup::create(['name' => 'Revoke group']);

        $ids = Permission::whereIn('key', [
            'view_catalog', 'manage_products', 'view_sales', 'view_orders', 'manage_reports',
        ])->pluck('id', 'key')->all();

        $target->permissions()->sync(array_values($ids));
        $this->assertCount(5, $target->permissions()->pluck('permissions.id'));

        // Switching the Catalog parent off cascades its children off with it,
        // and orders is raised view -> manage in the same save. This is what
        // the form posts afterwards.
        $posted = [$ids['view_sales'], Permission::where('key', 'manage_orders')->value('id'), $ids['manage_reports']];

        $this->actingAs($manager)
            ->put(route('user_groups.update', $target->id), [
                'name' => 'Revoke group',
                'permissions' => $posted,
            ])
            ->assertRedirect(route('user_groups.index'));

        $keys = Permission::whereIn(
            'id',
            DB::table('user_group_permissions')->where('user_group_id', $target->id)->pluck('permission_id')
        )->pluck('key')->sort()->values()->all();

        $this->assertSame(['manage_orders', 'manage_reports', 'view_sales'], $keys);
        $this->assertNotContains('view_catalog', $keys, 'the parent must be gone');
        $this->assertNotContains('manage_products', $keys, 'the cascaded child must be gone');
        $this->assertNotContains('view_orders', $keys, 'the superseded tier must be gone');
    }

    /**
     * Posting nothing at all detaches everything. The "Off all" cascade is the
     * only way to reach a genuinely empty group, so it has to work.
     */
    public function test_posting_no_permissions_detaches_every_row(): void
    {
        $manager = $this->userWithPermissions(['manage_user_groups'], 'offall');
        $target = UserGroup::create(['name' => 'Off all group']);
        $target->permissions()->sync(Permission::whereIn('key', ['manage_products', 'view_orders'])->pluck('id')->all());

        $this->actingAs($manager)
            ->put(route('user_groups.update', $target->id), ['name' => 'Off all group'])
            ->assertRedirect(route('user_groups.index'));

        $this->assertSame(
            0,
            DB::table('user_group_permissions')->where('user_group_id', $target->id)->count()
        );
    }

    /**
     * The server-side backstop UserGroupController documents: a child granted
     * without its parent (DOM tamper, or a direct API post) elevates the
     * parent rather than dropping the child, so the grant never lands dead.
     */
    public function test_a_child_granted_without_its_parent_elevates_the_parent(): void
    {
        $manager = $this->userWithPermissions(['manage_user_groups'], 'elevate');
        $target = UserGroup::create(['name' => 'Elevate group']);

        $manageProducts = Permission::where('key', 'manage_products')->value('id');

        $this->actingAs($manager)->put(route('user_groups.update', $target->id), [
            'name' => 'Elevate group',
            'permissions' => [$manageProducts],
        ])->assertRedirect();

        $keys = $target->permissions()->pluck('permissions.key')->sort()->values()->all();

        $this->assertContains('manage_products', $keys);
        $this->assertContains('view_catalog', $keys, 'the parent must have been elevated server-side');
    }

    public function test_the_administrator_group_keeps_its_core_permissions_and_is_never_deletable(): void
    {
        $manager = $this->userWithPermissions(['manage_user_groups'], 'admin-guard');
        // A migration already seeds the Administrator group on a fresh
        // schema, so this reuses it rather than colliding on the unique name.
        $admin = UserGroup::firstOrCreate(['name' => 'Administrator']);

        // Post an empty matrix at the Administrator group.
        $this->actingAs($manager)
            ->put(route('user_groups.update', $admin->id), ['name' => 'Administrator'])
            ->assertRedirect();

        $keys = $admin->permissions()->pluck('permissions.key')->all();
        foreach (['manage_settings', 'manage_user_groups', 'manage_users'] as $core) {
            $this->assertContains($core, $keys, "Administrator must keep {$core}");
        }

        // And the edit page never offers the delete the controller refuses.
        $html = $this->actingAs($manager)->get(route('user_groups.edit', $admin->id))->getContent();
        $this->assertStringNotContainsString('st-group-delete-form', $html);

        $this->actingAs($manager)->delete(route('user_groups.destroy', $admin->id));
        $this->assertDatabaseHas('user_groups', ['id' => $admin->id]);
    }

    // ==================================================================
    // 4. CURRENCIES
    // ==================================================================

    /**
     * A currency save has to store the rate meaning OrderCurrencyService's
     * documented semantic: "units of the default currency per 1 unit of this
     * currency". The redesigned pages print that direction on screen
     * ("1 USD = 58.25 PHP"), so a change to the stored meaning would make the
     * copy a lie rather than just a rounding difference.
     */
    public function test_a_currency_save_stores_the_rate_as_units_of_the_default_per_one_unit(): void
    {
        $this->seedCurrencies();
        $manager = $this->userWithPermissions(['manage_website_settings'], 'cur');
        $usd = Currency::where('code', 'USD')->firstOrFail();

        $this->actingAs($manager)->put(route('currencies.update', $usd->id), [
            'code' => 'usd',
            'name' => 'US Dollar',
            'symbol' => '$',
            'exchange_rate' => '58.25000000',
            'status' => '1',
        ])->assertRedirect(route('currencies.index'));

        $usd->refresh();

        $this->assertSame('USD', $usd->code, 'the code is upper-cased on save');
        $this->assertEqualsWithDelta(58.25, (float) $usd->exchange_rate, 0.00000001);
        $this->assertFalse((bool) $usd->is_default, 'omitting is_default reads as false, not as unchanged');

        // The semantic itself, through the service that owns it.
        $resolved = app(\App\Services\OrderCurrencyService::class)->resolve('USD');
        $this->assertEqualsWithDelta(58.25, $resolved['rate'], 0.00000001);
        $this->assertEqualsWithDelta(
            5825.0,
            \App\Services\OrderCurrencyService::toBase(100.0, $resolved['rate']),
            0.0001,
            '100 USD must be worth 5825 of the default currency at a rate of 58.25'
        );

        // And the list page prints that direction rather than a bare number.
        $this->actingAs($manager)->get(route('currencies.index'))
            ->assertOk()
            ->assertSee('1 USD = 58.25 PHP');
    }

    public function test_moving_the_default_currency_clears_the_previous_default(): void
    {
        $this->seedCurrencies();
        $manager = $this->userWithPermissions(['manage_website_settings'], 'cur-default');
        $usd = Currency::where('code', 'USD')->firstOrFail();

        $this->actingAs($manager)->put(route('currencies.update', $usd->id), [
            'code' => 'USD', 'name' => 'US Dollar', 'symbol' => '$',
            'exchange_rate' => '1.00000000', 'is_default' => '1', 'status' => '1',
        ])->assertRedirect();

        $this->assertTrue((bool) Currency::where('code', 'USD')->value('is_default'));
        $this->assertFalse((bool) Currency::where('code', 'PHP')->value('is_default'));
        $this->assertSame(1, Currency::where('is_default', 1)->count());
    }

    /**
     * The currency pages resolve the default currency from the `currencies`
     * table through App\Support\Money. Hardcoding it (the pre-redesign hint
     * read "Rate relative to base currency (PHP)") makes every label wrong the
     * day someone re-bases.
     */
    public function test_the_currency_pages_never_hardcode_a_currency(): void
    {
        foreach ([
            resource_path('views/settings/currencies/index.blade.php'),
            resource_path('views/settings/currencies/create.blade.php'),
            resource_path('views/settings/currencies/edit.blade.php'),
        ] as $file) {
            $source = file_get_contents($file);
            $name = basename($file);

            $this->assertStringContainsString('Money::defaultCode()', $source, "{$name} must resolve the default currency");
            // No literal code or symbol anywhere in the markup.
            $this->assertDoesNotMatchRegularExpression('/\(PHP\)|\bPHP\b(?!.*defaultCode)/', $source, "{$name} hardcodes PHP");
            $this->assertStringNotContainsString('₱', $source, "{$name} hardcodes a currency symbol");
        }

        // Proven live, not just by grep: re-base to USD and the page follows.
        $this->seedCurrencies();
        Currency::where('code', 'PHP')->update(['is_default' => 0]);
        Currency::where('code', 'USD')->update(['is_default' => 1, 'exchange_rate' => 1]);
        \App\Support\Money::flushCache();

        $manager = $this->userWithPermissions(['manage_website_settings'], 'cur-rebase');
        $this->actingAs($manager)->get(route('currencies.index'))
            ->assertOk()
            ->assertSee('how much of USD one unit');
    }

    // ==================================================================
    // 5. API APPLICATIONS
    // ==================================================================

    /**
     * `personal_access_tokens.token` is a SHA-256 hash, and the plaintext
     * exists only as a one-request flash after create or rotate. Neither may
     * reach the list or the edit page.
     *
     * NOTE for the record: the Round C credential fix shape (a masked field
     * plus a blank-keeps-stored branch) is deliberately NOT applied here, and
     * no controller change was needed. That fix exists for pages that render a
     * stored plaintext secret back into an editable input. This page has no
     * such value to render: there is no readable secret in the schema at all.
     * This test is what holds that claim honest.
     */
    public function test_an_api_client_save_never_echoes_a_secret(): void
    {
        $manager = $this->userWithPermissions(['manage_api_clients'], 'api');
        $client = $this->seedApiClient('Pricing assistant', ['products:read', 'orders:read'], now()->addDays(30));

        $hash = DB::table('personal_access_tokens')->where('tokenable_id', $client->id)->value('token');
        $this->assertNotEmpty($hash);

        $save = $this->actingAs($manager)->put(route('api_clients.update', $client->id), [
            'name' => 'Pricing assistant',
            'scopes' => ['products' => ['read' => '1'], 'orders' => ['read' => '1', 'write' => '1']],
            'no_expiry' => '1',
        ]);
        $save->assertRedirect(route('api_clients.index'));

        // The scopes really changed in place, and the token value did not.
        $token = DB::table('personal_access_tokens')->where('tokenable_id', $client->id)->first();
        $this->assertSame(
            ['products:read', 'orders:read', 'orders:write'],
            json_decode($token->abilities, true)
        );
        $this->assertSame($hash, $token->token, 'editing scopes must not rotate the token');
        $this->assertNull($token->expires_at, 'no_expiry must clear the expiry');

        // Neither page carries the hash, or anything shaped like a plaintext
        // Sanctum token ("<id>|<40+ chars>").
        foreach ([route('api_clients.index'), route('api_clients.edit', $client->id)] as $url) {
            $html = $this->actingAs($manager)->get($url)->assertOk()->getContent();

            $this->assertStringNotContainsString($hash, $html, "{$url} echoes the stored token hash");
            $this->assertDoesNotMatchRegularExpression(
                '/\d+\|[A-Za-z0-9]{40,}/',
                $html,
                "{$url} echoes something shaped like a plaintext token"
            );
            $this->assertStringNotContainsString('st-token', $html, "{$url} renders the one-time reveal outside its flash");
        }
    }

    public function test_the_plaintext_token_is_revealed_once_and_never_again(): void
    {
        $manager = $this->userWithPermissions(['manage_api_clients'], 'api-reveal');

        $create = $this->actingAs($manager)->post(route('api_clients.store'), [
            'name' => 'Warehouse scanner',
            'scopes' => ['inventory' => ['read' => '1', 'write' => '1']],
            'no_expiry' => '1',
        ]);
        $create->assertRedirect(route('api_clients.index'));
        $create->assertSessionHas('new_token');

        $plain = session('new_token');
        $this->assertMatchesRegularExpression('/^\d+\|[A-Za-z0-9]{40,}$/', $plain);

        // Following the redirect shows it exactly once...
        $revealed = $this->actingAs($manager)->get(route('api_clients.index'));
        $revealed->assertOk();
        $revealed->assertSee($plain, false);
        $revealed->assertSee('st-token', false);

        // ...and the very next request does not.
        $again = $this->actingAs($manager)->get(route('api_clients.index'));
        $again->assertOk();
        $again->assertDontSee($plain, false);
        $again->assertDontSee('st-token', false);
    }

    public function test_the_scope_matrix_posts_only_actions_the_catalog_offers(): void
    {
        $manager = $this->userWithPermissions(['manage_api_clients'], 'api-scope');

        $this->actingAs($manager)->post(route('api_clients.store'), [
            'name' => 'Tampered client',
            // `delete` is not in any resource's action list, and `unknown` is
            // not a resource. Both must be dropped rather than stored.
            'scopes' => [
                'products' => ['read' => '1', 'delete' => '1'],
                'unknown'  => ['read' => '1'],
            ],
            'no_expiry' => '1',
        ])->assertRedirect();

        $client = ApiClient::where('name', 'Tampered client')->firstOrFail();
        $abilities = json_decode(
            DB::table('personal_access_tokens')->where('tokenable_id', $client->id)->value('abilities'),
            true
        );

        $this->assertSame(['products:read'], $abilities);
    }

    // ==================================================================
    // 6. USERS
    // ==================================================================

    public function test_a_user_save_round_trips_and_a_password_box_is_never_prefilled(): void
    {
        $manager = $this->userWithPermissions(['manage_users'], 'users');
        $group = UserGroup::create(['name' => 'Floor staff']);

        $this->actingAs($manager)->post(route('users.store'), [
            'name' => 'Ana Cruz',
            'username' => 'ana',
            'email' => 'ana@example.test',
            'password' => 'supersecret',
            'password_confirmation' => 'supersecret',
            'user_group_id' => $group->id,
        ])->assertRedirect(route('users.index'));

        $ana = User::where('username', 'ana')->firstOrFail();
        $this->assertSame('Ana Cruz', $ana->name);
        $this->assertSame($group->id, $ana->user_group_id);
        $this->assertTrue(\Illuminate\Support\Facades\Hash::check('supersecret', $ana->password));

        // The hash never reaches the edit page, and neither password box
        // carries a value attribute of any kind.
        $html = $this->actingAs($manager)->get(route('users.edit', $ana->id))->assertOk()->getContent();
        $this->assertStringNotContainsString($ana->password, $html);

        foreach (['password', 'password_confirmation'] as $field) {
            $this->assertMatchesRegularExpression(
                '/<input[^>]*name="'.$field.'"[^>]*>/',
                $html,
                "the {$field} box must exist"
            );
            $this->assertDoesNotMatchRegularExpression(
                '/<input[^>]*name="'.$field.'"[^>]*\svalue=/',
                $html,
                "the {$field} box must never be prefilled"
            );
        }

        // Saving with both boxes empty keeps the existing password.
        $before = $ana->password;
        $this->actingAs($manager)->put(route('users.update', $ana->id), [
            'name' => 'Ana Cruz Reyes',
            'username' => 'ana',
            'email' => 'ana@example.test',
            'user_group_id' => $group->id,
        ])->assertRedirect();

        $ana->refresh();
        $this->assertSame('Ana Cruz Reyes', $ana->name);
        $this->assertSame($before, $ana->password, 'an empty password box means unchanged');
    }

    public function test_the_users_list_offers_no_delete_for_an_administrator_or_for_yourself(): void
    {
        $adminGroup = UserGroup::firstOrCreate(['name' => 'Administrator']);
        // syncWithoutDetaching, not attach: the seeded Administrator group may
        // already hold manage_users, and the pivot's primary key is the pair.
        $adminGroup->permissions()->syncWithoutDetaching([Permission::where('key', 'manage_users')->value('id')]);
        $me = User::factory()->create(['user_group_id' => $adminGroup->id, 'name' => 'the owner']);

        $other = $this->userWithPermissions(['view_users'], 'deletable');

        $html = $this->actingAs($me)->get(route('users.index'))->assertOk()->getContent();

        // Neither an Administrator account nor my own row gets a delete form.
        $this->assertStringNotContainsString('st-user-del-'.$me->id.'"', $html);
        // A plain account does.
        $this->assertStringContainsString('st-user-del-'.$other->id.'"', $html);

        // And the controller agrees, from both ends.
        $this->actingAs($me)->delete(route('users.destroy', $me->id));
        $this->assertDatabaseHas('users', ['id' => $me->id]);
    }

    // ==================================================================
    // 7. ORDER STATUSES
    // ==================================================================

    /**
     * OrderStatusController reads both flags with $request->has(), so a hidden
     * `value="0"` companion in front of each checkbox (the usual way to make a
     * switch submit when off, and what the currency page correctly does for
     * its `required|in:0,1` status field) would make has() true on EVERY save
     * and pin both flags permanently on. Asserted from both ends.
     */
    public function test_order_status_flags_round_trip_and_carry_no_hidden_companion(): void
    {
        $manager = $this->userWithPermissions(['manage_order_statuses'], 'os');

        foreach (['create', 'edit'] as $page) {
            $source = file_get_contents(resource_path("views/settings/order_statuses/{$page}.blade.php"));
            foreach (['subtract_stock', 'add_revenue'] as $flag) {
                $this->assertDoesNotMatchRegularExpression(
                    '/<input[^>]*type="hidden"[^>]*name="'.$flag.'"/',
                    $source,
                    "{$page} must not give {$flag} a hidden companion: the controller reads it with has()"
                );
            }
        }

        // Both on.
        $this->actingAs($manager)->post(route('order_statuses.store'), [
            'name' => 'Ready to ship',
            'subtract_stock' => '1',
            'add_revenue' => '1',
        ])->assertRedirect(route('order_statuses.index'));

        $status = OrderStatus::where('name', 'Ready to ship')->firstOrFail();
        $this->assertSame(1, (int) $status->subtract_stock);
        $this->assertSame(1, (int) $status->add_revenue);

        // Both omitted, which is what an unchecked checkbox posts. They must
        // come back off, not stay on.
        $this->actingAs($manager)->put(route('order_statuses.update', $status->order_status_id), [
            'name' => 'Ready to ship',
        ])->assertRedirect();

        $status->refresh();
        $this->assertSame(0, (int) $status->subtract_stock, 'an unchecked flag must land as 0');
        $this->assertSame(0, (int) $status->add_revenue, 'an unchecked flag must land as 0');
    }

    public function test_the_bulk_delete_removes_only_the_selected_statuses(): void
    {
        $manager = $this->userWithPermissions(['manage_order_statuses'], 'os-bulk');

        $keep = $this->seedOrderStatus('Pending');
        $goneA = $this->seedOrderStatus('Failed');
        $goneB = $this->seedOrderStatus('Denied');

        $this->actingAs($manager)->post(route('order_statuses.bulk'), [
            'ids' => [$goneA->order_status_id, $goneB->order_status_id],
        ])->assertRedirect(route('order_statuses.index'));

        $this->assertDatabaseHas('order_status', ['order_status_id' => $keep->order_status_id]);
        $this->assertDatabaseMissing('order_status', ['order_status_id' => $goneA->order_status_id]);
        $this->assertDatabaseMissing('order_status', ['order_status_id' => $goneB->order_status_id]);
    }

    // ==================================================================
    // 8. ESCAPING
    // ==================================================================

    public function test_stored_markup_in_a_name_is_escaped_on_every_list_and_form(): void
    {
        $payload = '<script>alert(1)</script>';
        $escaped = '&lt;script&gt;alert(1)&lt;/script&gt;';

        $manager = $this->userWithPermissions(array_values(self::MANAGE_KEYS), 'xss');

        $group = UserGroup::create(['name' => $payload]);
        $status = OrderStatus::create([
            'language_id' => 1, 'name' => $payload,
            'subtract_stock' => 0, 'add_revenue' => 0,
        ]);
        $client = $this->seedApiClient($payload, ['products:read']);
        $victim = User::factory()->create(['name' => $payload, 'user_group_id' => $group->id]);
        Currency::create([
            'code' => 'XSS', 'name' => $payload, 'symbol' => '<b>',
            'exchange_rate' => 2, 'is_default' => 0, 'status' => 1,
        ]);
        $currency = Currency::where('code', 'XSS')->firstOrFail();

        foreach ([
            route('users.index'),
            route('users.edit', $victim->id),
            route('user_groups.index'),
            route('user_groups.edit', $group->id),
            route('order_statuses.index'),
            route('order_statuses.edit', $status->order_status_id),
            route('currencies.index'),
            route('currencies.edit', $currency->id),
            route('api_clients.index'),
            route('api_clients.edit', $client->id),
        ] as $url) {
            $response = $this->actingAs($manager)->get($url);
            $response->assertOk();
            $response->assertDontSee($payload, false);
            $response->assertSee($escaped, false);
        }
    }

    // ==================================================================
    // 9. HOUSE RULES
    // ==================================================================

    public function test_the_redesigned_views_carry_no_css_no_native_dialogs_and_no_em_dash(): void
    {
        foreach ($this->ownedViewFiles() as $file) {
            $source = file_get_contents($file);
            $name = str_replace(resource_path('views/settings/'), '', $file);

            $this->assertStringNotContainsString('<style', $source, "{$name} has a <style> block");
            $this->assertDoesNotMatchRegularExpression('/\sstyle="/', $source, "{$name} has an inline style attribute");
            $this->assertDoesNotMatchRegularExpression('/(?<![\w$>])confirm\s*\(/', $source, "{$name} calls confirm()");
            $this->assertDoesNotMatchRegularExpression('/(?<![\w$>])alert\s*\(/', $source, "{$name} calls alert()");
            $this->assertStringNotContainsString('layouts.app', $source, "{$name} is still on the legacy layout");
            $this->assertStringNotContainsString('<script', $source, "{$name} carries a script block; page JS belongs in resources/js");
            // Every spelling of the same character, per the fix-round finding
            // that the raw glyph alone let an entity through.
            $this->assertDoesNotMatchRegularExpression(
                '/\x{2014}|&mdash;|&#8212;|&#x2014;/iu',
                $source,
                "{$name} contains an em dash"
            );
        }
    }

    /**
     * Blade will not compile a component tag whose attribute is a directive:
     * `<x-ui.input @disabled($flag) />` emits the LITERAL `<x-ui.input ...>`
     * tag as text, which a browser parses as an unknown element. The page
     * still returns 200 and still "contains" the field name, so a render test
     * cannot see it. This one can, and it cost a real defect on the API expiry
     * field during this task.
     */
    public function test_no_owned_view_puts_a_blade_directive_inside_a_component_tag(): void
    {
        foreach ($this->ownedViewFiles() as $file) {
            $source = file_get_contents($file);
            $name = str_replace(resource_path('views/settings/'), '', $file);

            $this->assertDoesNotMatchRegularExpression(
                '/<x-[a-z0-9.\-]+[^>]*@(disabled|checked|selected|readonly|required|class|style)\(/s',
                $source,
                "{$name} puts a Blade directive inside a component tag; use :attribute=\"\$expr\" instead"
            );
        }

        // And the control that was broken is genuinely an input now.
        $manager = $this->userWithPermissions(['manage_api_clients'], 'api-date');
        $html = $this->actingAs($manager)->get(route('api_clients.create'))->assertOk()->getContent();

        $this->assertStringNotContainsString('<x-ui.input', $html, 'a component tag leaked into the rendered page');
        $this->assertMatchesRegularExpression(
            '/<input[^>]*type="date"[^>]*id="st-api-expires"[^>]*>/',
            $html,
            'the expiry field must render as a real date input'
        );
    }

    public function test_the_settings_pages_declare_their_breadcrumb_leaf(): void
    {
        $this->seedCurrencies();
        $manager = $this->userWithPermissions(array_values(self::MANAGE_KEYS), 'crumbs');

        // The Settings nav entry is a single destination, so `Breadcrumbs`
        // derives only "Settings" from the URL and each page supplies its own
        // leaf. Without one, every settings page's trail reads just "Settings".
        foreach ([
            [route('users.index'), 'Users'],
            [route('user_groups.index'), 'User groups'],
            [route('order_statuses.index'), 'Order statuses'],
            [route('currencies.index'), 'Currencies'],
            [route('api_clients.index'), 'API applications'],
        ] as [$url, $leaf]) {
            $this->actingAs($manager)->get($url)->assertOk()->assertSee($leaf);
        }
    }
}
