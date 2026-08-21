<?php

namespace Tests\Feature;

use App\Models\Admin\Permission;
use App\Models\Admin\UserGroup;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Making a folder in the image library.
 *
 * The library has always HAD folders and never had a way to make one - a
 * directory only came into being as a side effect of an upload landing in
 * it. So "organise your images yourself" described a capability that did not
 * exist, and retiring the manufacturer gate without this would have left
 * people with no way to organise anything at all.
 *
 * This endpoint creates directories from user input, so most of what is
 * asserted here is what it REFUSES.
 */
class ImageLibraryFolderTest extends TestCase
{
    use RefreshDatabase;

    private ?User $user = null;

    private function operator(array $permissions = ['manage_products', 'view_products']): User
    {
        if ($this->user) {
            return $this->user;
        }

        $group = UserGroup::create(['name' => 'Catalogue Folders']);
        $group->permissions()->attach(
            Permission::whereIn('key', $permissions)->pluck('id')->all()
        );

        return $this->user = User::factory()->create(['user_group_id' => $group->id]);
    }

    private function create(array $payload): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($this->operator())
            ->postJson('/catalog/products/images/folder', $payload);
    }

    public function test_it_creates_a_folder_in_the_current_directory(): void
    {
        $disk = Storage::fake('public');
        $disk->put('catalog/Bigsby/keep.jpg', 'x');

        $this->create(['path' => 'Bigsby', 'name' => 'Spares'])
            ->assertOk()
            ->assertJson(['ok' => true, 'path' => 'Bigsby/Spares']);

        $this->assertTrue($disk->exists('catalog/Bigsby/Spares'));
        // And it did not disturb what was already there.
        $this->assertTrue($disk->exists('catalog/Bigsby/keep.jpg'));
    }

    public function test_it_creates_at_the_root_when_no_path_is_given(): void
    {
        $disk = Storage::fake('public');

        $this->create(['name' => 'Fender'])->assertOk()->assertJson(['path' => 'Fender']);

        $this->assertTrue($disk->exists('catalog/Fender'));
    }

    /**
     * THE NAME IS ONE SEGMENT. A slash would let a folder name become a
     * path, which is how a create-folder endpoint turns into a
     * write-anywhere endpoint.
     */
    public function test_a_name_may_not_contain_a_path(): void
    {
        $disk = Storage::fake('public');

        foreach (['a/b', 'a\\b', '../escape', '..'] as $bad) {
            $this->create(['name' => $bad])->assertStatus(422);
        }

        // Nothing was created anywhere, under any interpretation.
        $this->assertSame([], $disk->allDirectories('catalog'));
    }

    public function test_it_refuses_to_climb_out_through_the_path(): void
    {
        Storage::fake('public');

        $this->create(['path' => '../../etc', 'name' => 'evil'])->assertStatus(422);
    }

    public function test_a_hidden_folder_is_refused(): void
    {
        $disk = Storage::fake('public');

        $this->create(['name' => '.git'])->assertStatus(422);

        $this->assertSame([], $disk->allDirectories('catalog'));
    }

    /**
     * The character set matches what browse and search can address, so a
     * folder made here is never one the library cannot reach afterwards.
     */
    public function test_a_name_the_library_could_not_address_is_refused(): void
    {
        Storage::fake('public');

        foreach (['we<b>ird', 'quote"mark', 'pipe|char', "new\nline"] as $bad) {
            $this->create(['name' => $bad])->assertStatus(422);
        }
    }

    public function test_a_duplicate_is_refused_and_says_so(): void
    {
        $disk = Storage::fake('public');
        $disk->makeDirectory('catalog/Boss');

        $this->create(['name' => 'Boss'])
            ->assertStatus(422)
            ->assertJsonFragment(['ok' => false]);
    }

    public function test_an_empty_name_is_refused(): void
    {
        Storage::fake('public');

        $this->create(['name' => '   '])->assertStatus(422);
        $this->create([])->assertStatus(422);
    }

    /**
     * Creating a folder is a write, so it sits behind manage_products - not
     * behind the view tier that browse and search use.
     */
    public function test_a_read_only_operator_cannot_create_one(): void
    {
        Storage::fake('public');

        $group = UserGroup::create(['name' => 'Catalogue Readers']);
        $group->permissions()->attach(Permission::where('key', 'view_products')->pluck('id')->all());
        $viewer = User::factory()->create(['user_group_id' => $group->id]);

        $this->actingAs($viewer)
            ->postJson('/catalog/products/images/folder', ['name' => 'Nope'])
            ->assertStatus(403);
    }
}
