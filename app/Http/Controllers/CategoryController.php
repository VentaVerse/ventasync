<?php

namespace App\Http\Controllers;

use App\Models\Catalog\Category;
use App\Models\Catalog\CategoryDescription;
use App\Services\ActivityLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CategoryController extends Controller
{
    public function index(Request $request)
    {
        $p = config('catalog.prefix');
        $languageId = (int) config('catalog.default_language_id');
        $q = trim((string) $request->get('q', ''));

        // Subquery that builds "Category 1 > Category 2 > Category 3" using category_path
        $pathsSub = $this->pathsSubquery($p, $languageId);

        $categories = DB::table($p.'category as c')
            ->leftJoinSub($pathsSub, 'cp', function($j) {
                $j->on('c.category_id','=','cp.category_id');
            })
            ->leftJoin($p.'category_description as pcd', function($j) use ($languageId) {
                $j->on('c.parent_id','=','pcd.category_id')->where('pcd.language_id','=',$languageId);
            })
            ->when($q !== '', function($query) use ($q) {
                $query->where('cp.path_name', 'like', '%'.$q.'%');
            })
            ->select(
                'c.category_id',
                'c.parent_id',
                DB::raw('COALESCE(cp.path_name, CONCAT("Category #", c.category_id)) as name'),
                'pcd.name as parent_name',
                'c.status',
                'c.sort_order'
            )
            ->orderBy('c.parent_id')
            ->orderBy('name')
            ->paginate(50)
            ->withQueryString();

        foreach ($categories as $row) {
    if (isset($row->name)) $row->name = html_entity_decode($row->name, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    if (isset($row->parent_name)) $row->parent_name = html_entity_decode($row->parent_name, ENT_QUOTES | ENT_HTML5, 'UTF-8');
}

return view('categories.index', ['categories' => $categories, 'q' => $q]);
    }

    public function create()
    {
        $parents = $this->parentOptions();
        return view('categories.create', compact('parents'));
    }

    public function store(Request $request)
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'parent_id' => 'nullable|integer',
            'status' => 'required|in:0,1',
            'sort_order' => 'nullable|integer',
        ]);

        $languageId = (int) config('catalog.default_language_id');

        $categoryId = null;
        DB::transaction(function () use ($request, $languageId, &$categoryId) {
            $c = new Category();
            $c->image = null;
            $c->parent_id = (int) ($request->parent_id ?? 0);
            $c->top = 0;
            $c->column = 0;
            $c->sort_order = (int) ($request->sort_order ?? 0);
            $c->status = (int) $request->status;
            $c->date_added = now();
            $c->date_modified = now();
            $c->save();

            $categoryId = $c->category_id;

            $cd = new CategoryDescription();
            $cd->category_id = $c->category_id;
            $cd->language_id = $languageId;
            $cd->name = $request->name;
            $cd->description = $request->description ?? '';
            $cd->meta_title = $request->name;
            $cd->meta_description = '';
            $cd->meta_keyword = '';
            $cd->seo_keyword = '';
            $cd->seo_h1 = '';
            $cd->seo_h2 = '';
            $cd->seo_h3 = '';
            $cd->save();

            $this->rebuildCategoryPath($c->category_id, $c->parent_id);
            $this->rebuildChildrenPaths($c->category_id);
        });

        ActivityLogger::log('created', 'Category', $categoryId, $request->name);

        return redirect()->route('categories.index')->with('status','Saved');
    }

    public function edit($id)
    {
        $p = config('catalog.prefix');
        $languageId = (int) config('catalog.default_language_id');

        $category = DB::table($p.'category as c')
            ->leftJoin($p.'category_description as cd', function($j) use ($languageId) {
                $j->on('c.category_id','=','cd.category_id')->where('cd.language_id','=',$languageId);
            })
            ->select('c.*','cd.name','cd.description')
            ->where('c.category_id', (int) $id)
            ->first();

        abort_if(!$category, 404);

        $parents = $this->parentOptions((int)$id);

        return view('categories.edit', compact('category','parents'));
    }

    public function update(Request $request, $id)
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'parent_id' => 'nullable|integer',
            'status' => 'required|in:0,1',
            'sort_order' => 'nullable|integer',
        ]);

        $languageId = (int) config('catalog.default_language_id');

        $changes = null;
        DB::transaction(function () use ($request, $id, $languageId, &$changes) {
            $c = Category::where('category_id', (int)$id)->firstOrFail();
            $origStatus = (string) $c->status;
            $origSortOrder = (string) $c->sort_order;
            $c->parent_id = (int) ($request->parent_id ?? 0);
            $c->sort_order = (int) ($request->sort_order ?? 0);
            $c->status = (int) $request->status;
            $c->date_modified = now();
            $c->save();

            $cd = CategoryDescription::where('category_id', (int)$id)->where('language_id', $languageId)->first();
            $origName = $cd->name ?? '';
            if (!$cd) {
                $cd = new CategoryDescription();
                $cd->category_id = (int)$id;
                $cd->language_id = $languageId;
                $cd->meta_description = '';
                $cd->meta_keyword = '';
                $cd->seo_keyword = '';
                $cd->seo_h1 = '';
                $cd->seo_h2 = '';
                $cd->seo_h3 = '';
            }
            $cd->name = $request->name;
            $cd->description = $request->description ?? '';
            $cd->meta_title = $request->name;
            $cd->save();

            $this->rebuildCategoryPath((int)$id, $c->parent_id);
            $this->rebuildChildrenPaths((int)$id);

            $diff = [];
            if ($origName !== $request->name) $diff['name'] = [$origName, $request->name];
            if ($origStatus !== (string) $request->status) $diff['status'] = [$origStatus, (string) $request->status];
            if ($origSortOrder !== (string) ($request->sort_order ?? 0)) $diff['sort_order'] = [$origSortOrder, (string) ($request->sort_order ?? 0)];
            $changes = !empty($diff) ? $diff : null;
        });

        ActivityLogger::log('updated', 'Category', (int) $id, $request->name, $changes);

        return redirect()->route('categories.index')->with('status','Saved');
    }

    public function destroy($id)
    {
        $p = config('catalog.prefix');
        $languageId = (int) config('catalog.default_language_id');
        $name = DB::table($p.'category_description')
            ->where('category_id', (int)$id)->where('language_id', $languageId)->value('name') ?? '#' . $id;

        DB::transaction(function () use ($id, $p) {
            DB::table($p.'category_path')->where('category_id', (int)$id)->delete();
            DB::table($p.'category_description')->where('category_id', (int)$id)->delete();
            DB::table($p.'product_to_category')->where('category_id', (int)$id)->delete();
            DB::table($p.'category')->where('category_id', (int)$id)->delete();
        });

        ActivityLogger::log('deleted', 'Category', (int) $id, $name);

        return redirect()->route('categories.index')->with('status','Saved');
    }

    public function bulkAction(Request $request)
    {
        $action = (string) $request->input('action', '');
        $ids = $request->input('ids', []);

        if (!is_array($ids)) {
            $ids = [];
        }

        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), fn ($v) => $v > 0)));

        if (empty($ids)) {
            return redirect()->route('categories.index')->with('status', 'No items selected.');
        }

        if (!in_array($action, ['enable', 'disable'], true)) {
            return redirect()->route('categories.index')->with('status', 'Invalid action.');
        }

        $pfx = config('catalog.prefix');
        $newStatus = $action === 'enable' ? 1 : 0;
        DB::table($pfx.'category')->whereIn('category_id', $ids)->update(['status' => $newStatus]);

        ActivityLogger::log($action === 'enable' ? 'updated' : 'updated', 'Category', null, count($ids) . ' categories ' . $action . 'd');

        return redirect()->route('categories.index')->with('status', $newStatus === 1 ? 'Enabled selected categories.' : 'Disabled selected categories.');
    }

    // AJAX lookup used by Product form typeahead
    public function lookup(Request $request)
    {
        $p = config('catalog.prefix');
        $languageId = (int) config('catalog.default_language_id');
        $q = trim((string) $request->get('q', ''));

        $pathsSub = $this->pathsSubquery($p, $languageId);

        $rows = DB::table($p.'category as c')
            ->leftJoinSub($pathsSub, 'cp', function($j) {
                $j->on('c.category_id','=','cp.category_id');
            })
            ->when($q !== '', function($query) use ($q) {
                $query->where('cp.path_name', 'like', '%'.$q.'%');
            })
            ->select(
                'c.category_id',
                'c.parent_id',
                DB::raw('COALESCE(cp.path_name, CONCAT("Category #", c.category_id)) as name')
            )
            ->orderBy('name')
            ->limit(20)
            ->get();

        return response()->json($rows);
    }

    private function parentOptions(?int $excludeId = null)
    {
        $p = config('catalog.prefix');
        $languageId = (int) config('catalog.default_language_id');

        $pathsSub = $this->pathsSubquery($p, $languageId);

        $q = DB::table($p.'category as c')
            ->leftJoinSub($pathsSub, 'cp', function($j) {
                $j->on('c.category_id','=','cp.category_id');
            })
            ->select(
                'c.category_id',
                'c.parent_id',
                DB::raw('COALESCE(cp.path_name, CONCAT("Category #", c.category_id)) as name')
            )
            ->orderBy('name');

        if ($excludeId) {
            $q->where('c.category_id','!=',$excludeId);
        }

        return $q->get();
    }

    private function pathsSubquery(string $p, int $languageId)
    {
        return DB::table($p.'category_path as cp')
            ->join($p.'category_description as cd', function($j) use ($languageId, $p) {
                $j->on('cp.path_id','=','cd.category_id')
                  ->where('cd.language_id','=',$languageId);
            })
            ->select(
                'cp.category_id',
                DB::raw("GROUP_CONCAT(cd.name ORDER BY cp.level SEPARATOR ' > ') as path_name")
            )
            ->groupBy('cp.category_id');
    }

    private function ensurePathExists(int $categoryId): void
    {
        $p = config('catalog.prefix');
        $exists = DB::table($p.'category_path')->where('category_id', $categoryId)->exists();
        if ($exists) return;

        DB::table($p.'category_path')->insert([
            'category_id' => $categoryId,
            'path_id'     => $categoryId,
            'level'       => 0,
        ]);
    }

    private function rebuildCategoryPath(int $categoryId, int $parentId): void
    {
        $p = config('catalog.prefix');

        DB::table($p.'category_path')->where('category_id', $categoryId)->delete();

        $level = 0;

        if ($parentId > 0) {
            // If parent doesn't have a path yet (common after manual imports), create it.
            $this->ensurePathExists($parentId);

            $parentPaths = DB::table($p.'category_path')
                ->where('category_id', $parentId)
                ->orderBy('level')
                ->get();

            foreach ($parentPaths as $pp) {
                DB::table($p.'category_path')->insert([
                    'category_id' => $categoryId,
                    'path_id' => (int) $pp->path_id,
                    'level' => $level,
                ]);
                $level++;
            }
        }

        DB::table($p.'category_path')->insert([
            'category_id' => $categoryId,
            'path_id' => $categoryId,
            'level' => $level,
        ]);
    }

    private function rebuildChildrenPaths(int $categoryId): void
    {
        $p = config('catalog.prefix');

        $children = DB::table($p.'category')
            ->where('parent_id', $categoryId)
            ->select('category_id','parent_id')
            ->get();

        foreach ($children as $child) {
            $this->rebuildCategoryPath((int)$child->category_id, (int)$child->parent_id);
            $this->rebuildChildrenPaths((int)$child->category_id);
        }
    }
}
