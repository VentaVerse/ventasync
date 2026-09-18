<?php

namespace App\Http\Controllers\Concerns;

use App\Integrations\Listings\CategoryTree;
use App\Integrations\Listings\ListingStore;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * One level of this channel's category tree, and the path down to a chosen
 * category, for the picker on a listing page or a product group.
 *
 * It rides the controller that already draws those pages, so browsing the
 * tree needs no permission of its own: whoever may see the listings may see
 * the categories they are picked from.
 *
 * Nothing here calls a marketplace. The tree is already stored, and
 * App\Integrations\Listings\CategoryTree knows how each channel spells it.
 */
trait ServesCategoryTree
{
    /** The channel this controller serves: 'shopee', 'lazada', ... */
    abstract protected function categoryChannel(): string;

    /**
     * The children of one category, or the roots when none is named, each
     * saying whether a column opens under it.
     *
     * A depth is never assumed: the page asks for the next level until a row
     * answers that it is a leaf, which is how Lazada's six levels and
     * Shopee's five are drawn by the same code.
     */
    public function categoryChildren(Request $request): JsonResponse
    {
        $data = $request->validate([
            'parent' => 'nullable|integer|min:0|max:99999999999',
        ]);

        $channel = $this->categoryChannel();

        return response()->json([
            'parent' => ((int) ($data['parent'] ?? 0)) ?: null,
            'items' => CategoryTree::children($channel, ListingStore::id($channel), (int) ($data['parent'] ?? 0)),
        ]);
    }

    /**
     * The chain from the root down to one category, so a search result opens
     * every column along its path already chosen, rather than landing the
     * seller somewhere with no way back up.
     *
     * A category this store no longer holds answers with an empty path,
     * which the page reads as "nothing chosen".
     */
    public function categoryPath(Request $request): JsonResponse
    {
        $data = $request->validate([
            'id' => 'required|integer|min:1|max:99999999999',
        ]);

        $channel = $this->categoryChannel();
        $path = CategoryTree::path($channel, ListingStore::id($channel), (int) $data['id']);

        return response()->json([
            'path' => $path,
            /* Each step's siblings, so the page can draw the whole way down
               in one request instead of one per level. */
            'levels' => $this->levelsFor($channel, $path),
        ]);
    }

    /**
     * Every column a path needs: the roots, then the children of each step
     * above the last.
     *
     * @param  list<array{id: int, name: string, leaf: bool}>  $path
     * @return list<array{parent: ?int, items: list<array{id: int, name: string, leaf: bool}>}>
     */
    private function levelsFor(string $channel, array $path): array
    {
        $storeId = ListingStore::id($channel);
        $levels = [['parent' => null, 'items' => CategoryTree::children($channel, $storeId)]];

        foreach ($path as $step) {
            if ($step['leaf']) {
                break;
            }
            $levels[] = ['parent' => $step['id'], 'items' => CategoryTree::children($channel, $storeId, $step['id'])];
        }

        return $levels;
    }
}
