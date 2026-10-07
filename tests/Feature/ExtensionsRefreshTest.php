<?php

namespace Tests\Feature;

use App\Models\Admin\Permission;
use App\Models\Admin\UserGroup;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class ExtensionsRefreshTest extends TestCase
{
    use RefreshDatabase;

    private string $routes;

    private string $config;

    protected function setUp(): void
    {
        parent::setUp();
        $this->routes = $this->app->getCachedRoutesPath();
        $this->config = $this->app->getCachedConfigPath();
        $this->assertFileDoesNotExist($this->routes, 'a real route cache would be lost by this test');
        $this->assertFileDoesNotExist($this->config, 'a real config cache would be lost by this test');
    }

    protected function tearDown(): void
    {
        File::delete([$this->routes, $this->config]);
        parent::tearDown();
    }

    private function userWith(array $keys): User
    {
        $group = UserGroup::create(['name' => 'Extensions ' . implode(',', $keys)]);
        $group->permissions()->attach(Permission::whereIn('key', $keys)->pluck('id')->all());

        return User::factory()->create(['user_group_id' => $group->id]);
    }

    public function test_refresh_drops_a_stale_route_and_config_cache(): void
    {
        File::put($this->routes, '<?php return [];');
        File::put($this->config, '<?php return [];');

        $this->actingAs($this->userWith(['view_settings/extension', 'manage_settings/extension']))
            ->from(route('extensions.index'))
            ->post(route('extensions.refresh'))
            ->assertRedirect(route('extensions.index'))
            ->assertSessionHas('success', 'Refreshed.');

        $this->assertFileDoesNotExist($this->routes);
        $this->assertFileDoesNotExist($this->config);
    }

    public function test_only_someone_who_manages_extensions_can_refresh(): void
    {
        File::put($this->routes, '<?php return [];');

        $this->actingAs($this->userWith(['view_settings/extension']))
            ->post(route('extensions.refresh'))
            ->assertSessionMissing('success');

        $this->assertFileExists($this->routes);
    }

    public function test_the_page_shows_refresh_to_a_manager_only(): void
    {
        $this->actingAs($this->userWith(['view_settings/extension', 'manage_settings/extension']))
            ->get(route('extensions.index'))->assertOk()->assertSee(route('extensions.refresh'), false);

        $this->actingAs($this->userWith(['view_settings/extension']))
            ->get(route('extensions.index'))->assertOk()->assertDontSee(route('extensions.refresh'), false);
    }
}
