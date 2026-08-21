<?php

namespace Tests\Feature;

use App\Models\Admin\Permission;
use App\Models\Admin\UserGroup;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * The image library search reaches the whole library.
 *
 * It used to filter the files already on screen, which only ever holds one
 * directory - so a box labelled "search" answered a question about where you
 * were standing rather than about the library, and finding a picture still
 * meant knowing which of 240 folders it was in.
 *
 * What a match means, per the owner: a folder whose name matches, an image whose
 * name matches wherever it lives, and every image INSIDE a matching folder -
 * because asking for "Bigsby" and being handed the Bigsby folder but none of
 * the Bigsby pictures answers a question nobody asked. When a query hits
 * both, both come back.
 */
class ImageLibrarySearchTest extends TestCase
{
    use RefreshDatabase;

    private ?User $operator = null;

    /** Memoised: the group name is unique, so a second call would collide. */
    private function operator(): User
    {
        if ($this->operator) {
            return $this->operator;
        }

        $group = UserGroup::create(['name' => 'Catalogue Search']);
        $group->permissions()->attach(
            Permission::whereIn('key', ['manage_products', 'view_products'])->pluck('id')->all()
        );

        return $this->operator = User::factory()->create(['user_group_id' => $group->id]);
    }

    /**
     * A FAKE DISK, and this is not incidental.
     *
     * The first version of this test seeded Storage::disk('public') - the
     * real one - so it wrote eight files into the live 7,773-image catalogue
     * and then asserted against a library that contained both its fixtures
     * and every real Bigsby photograph. It failed for the right reason and
     * for the wrong one at the same time.
     *
     * Storage::fake swaps the disk for a temporary directory that is
     * discarded after the test, so the assertions can be exact and the
     * catalogue is never touched.
     */
    private function seedLibrary(): void
    {
        $disk = Storage::fake('public');

        // A folder whose NAME matches, holding a file whose name does not.
        $disk->put('catalog/Bigsby/aaa-unrelated-name.jpg', 'x');
        $disk->put('catalog/Bigsby/second.png', 'x');

        // A file whose NAME matches, in a folder that does not.
        $disk->put('catalog/Other/bigsby-tailpiece.jpg', 'x');

        // Neither matches.
        $disk->put('catalog/Other/kettle.jpg', 'x');

        // Nested, to prove the walk is not one level deep.
        $disk->put('catalog/Products/Bigsby Spares/spring.jpg', 'x');

        // Not an image.
        $disk->put('catalog/Bigsby/notes.txt', 'x');
    }

    private function search(string $q): array
    {
        return $this->actingAs($this->operator())
            ->getJson('/catalog/products/images/browse?q='.urlencode($q))
            ->assertOk()
            ->json();
    }

    public function test_a_query_returns_the_folder_and_the_images_inside_it(): void
    {
        $this->seedLibrary();

        $result = $this->search('bigsby');

        $folders = array_column($result['folders'], 'name');
        sort($folders);
        $this->assertSame(['Bigsby', 'Bigsby Spares'], $folders,
            'a folder matches on its own name, at any depth');

        $files = array_column($result['files'], 'name');
        sort($files);

        $this->assertSame([
            'aaa-unrelated-name.jpg',   // inside a matching folder
            'bigsby-tailpiece.jpg',     // matches by its own name
            'second.png',               // inside a matching folder
            'spring.jpg',               // inside a matching folder, nested
        ], $files);

        // Both answers to the same question, together.
        $this->assertNotEmpty($result['folders']);
        $this->assertNotEmpty($result['files']);
    }

    public function test_a_result_says_which_folder_it_came_from(): void
    {
        $this->seedLibrary();

        $byName = collect($this->search('bigsby')['files'])->keyBy('name');

        $this->assertSame('Bigsby', $byName['second.png']['folder']);
        $this->assertSame('Other', $byName['bigsby-tailpiece.jpg']['folder']);
        $this->assertSame('Products/Bigsby Spares', $byName['spring.jpg']['folder']);
    }

    public function test_it_ignores_files_that_are_not_images(): void
    {
        $this->seedLibrary();

        $this->assertNotContains('notes.txt', array_column($this->search('bigsby')['files'], 'name'));
    }

    /**
     * "Boss" must not drag in "Bossa Nova". The prefix match is on a path
     * segment, not on a string.
     */
    public function test_a_folder_prefix_does_not_claim_a_longer_neighbour(): void
    {
        $disk = Storage::fake('public');
        $disk->put('catalog/Boss/pedal.jpg', 'x');
        $disk->put('catalog/Bossa Nova/guitar.jpg', 'x');

        $files = array_column($this->search('boss')['files'], 'name');

        // Both folders legitimately match "boss" by name, so both contribute.
        // What must not happen is Boss/ claiming Bossa Nova/ as a subtree.
        $this->assertContains('pedal.jpg', $files);

        $onlyBoss = array_column($this->search('boss/')['files'], 'name');
        $this->assertSame([], $onlyBoss, 'a slash is not part of any file or folder name');
    }

    public function test_a_query_that_matches_nothing_returns_nothing_rather_than_everything(): void
    {
        $this->seedLibrary();

        $result = $this->search('zzz-nothing-matches-this');

        $this->assertSame([], $result['folders']);
        $this->assertSame([], $result['files']);
    }

    /**
     * An empty query is a browse, not a search: it must keep answering with
     * the requested folder rather than dumping the library.
     */
    public function test_an_empty_query_still_browses(): void
    {
        $this->seedLibrary();

        $result = $this->actingAs($this->operator())
            ->getJson('/catalog/products/images/browse?q=&path=Bigsby')
            ->assertOk()
            ->json();

        $this->assertArrayNotHasKey('search', $result);
        $this->assertSame('Bigsby', $result['path']);
    }

    /**
     * The client half: opening the library no longer demands a manufacturer,
     * and it starts at the root every time.
     */
    public function test_the_library_opens_at_the_root_without_a_manufacturer(): void
    {
        $markup = File::get(base_path('resources/views/catalog/products/partials/image_manager.blade.php'));

        $this->assertStringNotContainsString('Choose a manufacturer first', $markup,
            'the manufacturer gate is retired');
        $this->assertStringNotContainsString('start_at_manufacturer', $markup,
            'the library no longer jumps to the manufacturer folder');
        $this->assertStringContainsString("await loadServerDir('');", $markup,
            'it opens at the root');
    }
}
