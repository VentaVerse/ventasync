<?php

namespace Tests\Feature;

use App\Models\Admin\Permission;
use App\Models\Admin\UserGroup;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PettyCashIsPartOfFinanceTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_install_with_petty_cash_keeps_it_switched_on_as_finance_with_its_grants(): void
    {
        DB::table('extensions')->insert([
            'id' => 'pettycash', 'name' => 'Petty Cash', 'version' => '0.30.0', 'enabled' => true,
            'manifest' => json_encode(['id' => 'pettycash']), 'created_at' => now(), 'updated_at' => now(),
        ]);
        $ledger = Permission::firstOrCreate(['key' => 'manage_pettycash/ledger'], ['label' => 'Transactions']);
        $settings = Permission::firstOrCreate(['key' => 'view_pettycash/settings'], ['label' => 'Settings']);
        $group = UserGroup::create(['name' => 'Bookkeepers']);
        $group->permissions()->attach([$ledger->id, $settings->id]);

        (require database_path('migrations/2026_10_08_120000_petty_cash_becomes_part_of_finance.php'))->up();

        $this->assertFalse(DB::table('extensions')->where('id', 'pettycash')->exists());
        $this->assertTrue((bool) DB::table('extensions')->where('id', 'finance')->value('enabled'));
        $this->assertSame('Finance', DB::table('extensions')->where('id', 'finance')->value('name'));
        $this->assertTrue($group->permissions()->where('key', 'manage_finance/petty_cash_ledger')->exists());
        $this->assertTrue($group->permissions()->where('key', 'view_finance/petty_cash_settings')->exists());
        $this->assertSame(0, Permission::where('key', 'like', '%pettycash/%')->count());
    }

    public function test_the_old_petty_cash_addresses_redirect_to_finance(): void
    {
        $this->actingAs(User::factory()->create());

        $this->get('/petty-cash')->assertRedirect('/finance/petty-cash')->assertStatus(301);
        $this->get('/petty-cash/settings?tab=roles')->assertRedirect('/finance/petty-cash/settings?tab=roles');
    }
}
