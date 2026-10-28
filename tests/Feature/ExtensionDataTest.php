<?php

namespace Tests\Feature;

use App\Extensions\ExtensionData;
use App\Extensions\ExtensionManager;
use App\Models\Admin\Permission;
use App\Models\Admin\UserGroup;
use App\Models\Extension;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class ExtensionDataTest extends TestCase
{
    use RefreshDatabase;

    private const ID = 'testfixture';

    private const MIGRATION = '2026_10_28_000001_create_testfixture_notes';

    protected function tearDown(): void
    {
        Schema::dropIfExists('testfixture_notes');
        DB::table('migrations')->where('migration', self::MIGRATION)->delete();
        DB::table('extensions')->where('id', self::ID)->delete();
        File::deleteDirectory(base_path('extensions/' . self::ID));
        parent::tearDown();
    }

    private function fixture(): ExtensionManager
    {
        $dir = base_path('extensions/' . self::ID);
        File::ensureDirectoryExists($dir . '/migrations');
        File::put($dir . '/extension.json', json_encode(['id' => self::ID, 'name' => 'Test Fixture', 'version' => '1.0.0', 'tier' => 'community']));
        File::put($dir . '/migrations/' . self::MIGRATION . '.php', <<<'PHP'
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('testfixture_notes', function (Blueprint $table) {
            $table->id();
            $table->string('body');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('testfixture_notes');
    }
};
PHP);

        return new ExtensionManager();
    }

    private function ran(): bool
    {
        return DB::table('migrations')->where('migration', self::MIGRATION)->exists();
    }

    public function test_installing_runs_the_extensions_own_migrations(): void
    {
        $manager = $this->fixture();
        $this->assertFalse(Schema::hasTable('testfixture_notes'));

        $this->assertTrue($manager->install(self::ID));

        $this->assertTrue(Schema::hasTable('testfixture_notes'));
        $this->assertTrue($this->ran());
    }

    public function test_uninstalling_keeps_the_data_and_installing_again_finds_it(): void
    {
        $manager = $this->fixture();
        $manager->install(self::ID);
        DB::table('testfixture_notes')->insert([['body' => 'one'], ['body' => 'two']]);

        $manager->uninstall(self::ID);

        $this->assertNull(Extension::find(self::ID));
        $this->assertSame(['Testfixture notes' => 2], ExtensionData::counts(self::ID));

        $manager->install(self::ID);
        $this->assertSame(2, DB::table('testfixture_notes')->count());
    }

    public function test_deleting_the_data_rolls_the_migrations_back_and_a_new_install_starts_empty(): void
    {
        $manager = $this->fixture();
        $manager->install(self::ID);
        DB::table('testfixture_notes')->insert(['body' => 'one']);

        $this->assertFalse($manager->deleteData(self::ID), 'an installed extension keeps its data');
        $this->assertSame(1, DB::table('testfixture_notes')->count());

        $manager->uninstall(self::ID);
        $this->assertTrue($manager->deleteData(self::ID));

        $this->assertFalse(Schema::hasTable('testfixture_notes'));
        $this->assertFalse($this->ran());
        $this->assertSame([], ExtensionData::counts(self::ID));

        $manager->install(self::ID);
        $this->assertSame(0, DB::table('testfixture_notes')->count());
    }

    private function admin(): User
    {
        $group = UserGroup::create(['name' => 'Extension data']);
        $group->permissions()->attach(Permission::whereIn('key', ['view_settings/extension', 'manage_settings/extension'])->pluck('id')->all());

        return User::factory()->create(['user_group_id' => $group->id]);
    }

    public function test_the_page_refuses_without_the_typed_name_or_while_installed(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->post(route('extensions.delete_data', 'opencart'), ['confirm' => 'opencart'])
            ->assertSessionHas('error', 'opencart data was not deleted.');

        Extension::create(['id' => 'opencart', 'name' => 'OpenCart', 'version' => '1.0.0', 'enabled' => false]);
        $this->actingAs($admin)->post(route('extensions.delete_data', 'opencart'), ['confirm' => 'OpenCart'])
            ->assertSessionHas('error', 'opencart data was not deleted.');

        $this->assertTrue(Schema::hasTable('ch_opencart_stores'));
    }
}
