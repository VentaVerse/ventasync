<?php

namespace Tests\Feature;

use App\Models\Admin\Permission;
use App\Models\Admin\UserGroup;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * What a product actually needs in order to exist is a name.
 *
 * Model, SKU, price and quantity were all required, and none of them earns
 * it. Decided by the owner 2026-08-21:
 *
 *   model     he does not want the field at all. It exists because Lazada and
 *             Shopee have one, and the channel mapping fills that in, so a
 *             blank model is a normal state rather than a half-built record.
 *   sku       plenty of catalogue lines never get one - and UniqueSku was
 *             already written to pass on an empty value, so the rule and the
 *             requirement had disagreed for as long as both existed.
 *   price     setting a product up before it is priced is ordinary.
 *   quantity  same, and on a product with variations it is not even editable:
 *             it is the sum of the rows below it.
 *
 * Null lands as zero for the two numbers, which is what those columns hold
 * anyway - not as null, which would be a third state meaning nothing.
 */
class ProductRequiredFieldsTest extends TestCase
{
    use RefreshDatabase;

    private function operator(): User
    {
        $group = UserGroup::create(['name' => 'Catalogue RF']);
        $group->permissions()->attach(
            Permission::whereIn('key', ['manage_products', 'view_products'])->pluck('id')->all()
        );

        return User::factory()->create(['user_group_id' => $group->id]);
    }

    public function test_a_name_is_the_only_field_a_new_product_must_have(): void
    {
        $response = $this->actingAs($this->operator())
            ->post('/catalog/products', ['name' => 'Bare Minimum', 'status' => 1]);

        $response->assertRedirect();
        $response->assertSessionHasNoErrors();

        $p = config('catalog.prefix');
        $id = DB::table($p.'product_description')->where('name', 'Bare Minimum')->value('product_id');

        $this->assertNotNull($id, 'the product must exist with nothing but a name');

        $row = DB::table($p.'product')->where('product_id', $id)->first();

        // Null becomes zero, not null: these columns carry a number, and a
        // third "unknown" state would have to be handled everywhere downstream.
        $this->assertSame(0.0, (float) $row->price);
        $this->assertSame(0, (int) $row->quantity);
        $this->assertSame('', trim((string) $row->sku));
    }

    public function test_a_name_is_still_required(): void
    {
        $this->actingAs($this->operator())
            ->post('/catalog/products', ['status' => 1, 'sku' => 'X-1'])
            ->assertSessionHasErrors('name');
    }

    /**
     * A blank model stays blank. It must not quietly become the SKU, which
     * is what the fallback does when the field is absent entirely - a form
     * that submits an empty box is saying "no model", not "no opinion".
     */
    public function test_a_blank_model_stays_blank(): void
    {
        $this->actingAs($this->operator())
            ->post('/catalog/products', [
                'name' => 'No Model', 'status' => 1, 'model' => '', 'sku' => 'HAS-SKU-1',
            ])->assertRedirect();

        $p = config('catalog.prefix');
        $id = DB::table($p.'product_description')->where('name', 'No Model')->value('product_id');

        $this->assertSame('', trim((string) DB::table($p.'product')->where('product_id', $id)->value('model')));
    }

    /**
     * Duplicate SKUs are still refused. Relaxing "you must have one" is not
     * the same as relaxing "it must be unique", and the marketplace matching
     * depends on the second.
     */
    public function test_a_duplicate_sku_is_still_refused(): void
    {
        $operator = $this->operator();

        $this->actingAs($operator)
            ->post('/catalog/products', ['name' => 'First', 'status' => 1, 'sku' => 'DUP-1'])
            ->assertRedirect();

        $this->actingAs($operator)
            ->post('/catalog/products', ['name' => 'Second', 'status' => 1, 'sku' => 'DUP-1'])
            ->assertSessionHasErrors('sku');
    }

    /**
     * And the form stops claiming otherwise. A pill that says Required over a
     * field the server accepts empty teaches people to distrust the markers
     * that are real.
     */
    public function test_only_the_name_is_marked_required_on_both_forms(): void
    {
        foreach (['edit', 'create'] as $form) {
            $markup = File::get(base_path("resources/views/catalog/products/{$form}.blade.php"));

            preg_match_all('/<x-ui\.field[^>]*name="([a-z_]+)"[^>]*:required="true"/', $markup, $m);

            $this->assertSame(['name'], $m[1],
                "only the name may carry a Required marker on the {$form} form");
        }
    }
}
