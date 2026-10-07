<?php

namespace App\Http\Controllers\Concerns;

use App\Integrations\Listings\CategoryTree;
use App\Integrations\Listings\ListingStore;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

trait ServesCategoryTree
{
    abstract protected function categoryChannel(): string;

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

    public function categoryPath(Request $request): JsonResponse
    {
        $data = $request->validate([
            'id' => 'required|integer|min:1|max:99999999999',
        ]);

        $channel = $this->categoryChannel();
        $path = CategoryTree::path($channel, ListingStore::id($channel), (int) $data['id']);

        return response()->json([
            'path' => $path,
            'levels' => $this->levelsFor($channel, $path),
        ]);
    }

    public function categorySearch(Request $request): JsonResponse
    {
        $data = $request->validate([
            'q' => 'required|string|min:2|max:120',
        ]);

        $channel = $this->categoryChannel();

        return response()->json([
            'items' => CategoryTree::search($channel, ListingStore::id($channel), (string) $data['q']),
        ]);
    }

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
