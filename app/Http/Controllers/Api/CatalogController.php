<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Catalog\Brand;
use Illuminate\Support\Facades\DB;

class CatalogController extends Controller
{
    private function isWordStartMatch(string $haystack, string $term): bool
    {
        $h = mb_strtolower($haystack, 'UTF-8');
        $t = mb_strtolower(trim($term), 'UTF-8');
        if ($t === '') return false;

        return (bool) preg_match('/(^|[^a-z0-9])' . preg_quote($t, '/') . '/i', $h);
    }

    public function brands(Request $request)
    {
        $term = trim((string) $request->query('term',''));
        if ($term === '') {
            return response()->json([]);
        }

        $rows = Brand::query()
            ->where('name', 'like', '%'.$term.'%')
            ->orderBy('name')
            ->limit(80)
            ->get(['brand_id','name']);

        $out = [];
        foreach ($rows as $r) {
            if ($this->isWordStartMatch((string)$r->name, $term)) {
                $out[] = [
                    'brand_id' => (int) $r->brand_id,
                    'name' => html_entity_decode((string)$r->name, ENT_QUOTES | ENT_HTML5, 'UTF-8'),
                ];
            }
            if (count($out) >= 15) break;
        }

        return response()->json($out);
    }

    public function categories(Request $request)
    {
        $term = trim((string) $request->query('term',''));
        if ($term === '') {
            return response()->json([]);
        }
        $lang = (int) config('catalog.default_language_id');

        $tableCat = 'category_descriptions';
        $tablePath = 'category_paths';

        $rows = DB::table($tablePath.' as cp')
            ->join($tableCat.' as cd1', function($j) use ($lang){
                $j->on('cp.category_id','=','cd1.category_id')->where('cd1.language_id','=',$lang);
            })
            ->join($tableCat.' as cd2', function($j) use ($lang){
                $j->on('cp.path_id','=','cd2.category_id')->where('cd2.language_id','=',$lang);
            })
            ->select('cp.category_id', DB::raw("GROUP_CONCAT(cd2.name ORDER BY cp.level SEPARATOR ' > ') as path"))
            ->groupBy('cp.category_id')
            ->having('path','like','%'.$term.'%')
            ->orderBy('path')
            ->limit(120)
            ->get();

        $out = [];
        foreach ($rows as $r) {
            $path = (string)($r->path ?? '');
            if ($this->isWordStartMatch($path, $term)) {
                $out[] = [
                    'category_id' => (int) $r->category_id,
                    'path' => html_entity_decode($path, ENT_QUOTES | ENT_HTML5, 'UTF-8'),
                ];
            }
            if (count($out) >= 15) break;
        }

        if (count($out) === 0) {
            $rows2 = DB::table($tableCat)
                ->where('language_id', $lang)
                ->where('name','like','%'.$term.'%')
                ->orderBy('name')
                ->limit(120)
                ->get(['category_id','name']);

            foreach ($rows2 as $r) {
                $name = (string)($r->name ?? '');
                if ($this->isWordStartMatch($name, $term)) {
                    $out[] = [
                        'category_id' => (int) $r->category_id,
                        'path' => html_entity_decode($name, ENT_QUOTES | ENT_HTML5, 'UTF-8'),
                    ];
                }
                if (count($out) >= 15) break;
            }
        }

        return response()->json($out);
    }

    public function variationTypes(Request $request)
    {
        $term = trim((string) $request->query('term',''));
        if ($term === '') {
            return response()->json([]);
        }
        $lang = (int) config('catalog.default_language_id');

        $rows = DB::table('variation_types as o')
            ->join('variation_type_descriptions as od', function($j) use ($lang){
                $j->on('o.variation_type_id','=','od.variation_type_id')->where('od.language_id','=',$lang);
            })
            ->where('od.name','like','%'.$term.'%')
            ->orderBy('od.name')
            ->limit(120)
            ->get(['o.variation_type_id','o.type','od.name']);

        $out = [];
        foreach ($rows as $r) {
            if ($this->isWordStartMatch((string)$r->name, $term)) {
                $out[] = [
                    'variation_type_id' => (int) $r->variation_type_id,
                    'type' => (string) $r->type,
                    'name' => html_entity_decode((string)$r->name, ENT_QUOTES | ENT_HTML5, 'UTF-8'),
                ];
            }
            if (count($out) >= 20) break;
        }

        return response()->json($out);
    }

    public function variationValues(Request $request, int $variationTypeId)
    {
        $term = trim((string) $request->query('term',''));
        $lang = (int) config('catalog.default_language_id');

        $q = DB::table('variation_value_descriptions as ovd')
            ->where('ovd.variation_type_id', $variationTypeId)
            ->where('ovd.language_id', $lang);

        if ($term !== '') {
            $q->where('ovd.name','like','%'.$term.'%');
        }

        $rows = $q->orderBy('ovd.name')->limit(200)->get(['ovd.variation_value_id','ovd.name']);

        $out = [];
        foreach ($rows as $r) {
            $name = (string)$r->name;
            if ($term === '' || $this->isWordStartMatch($name, $term)) {
                $out[] = [
                    'variation_value_id' => (int) $r->variation_value_id,
                    'name' => html_entity_decode($name, ENT_QUOTES | ENT_HTML5, 'UTF-8'),
                ];
            }
            if (count($out) >= 50) break;
        }

        return response()->json($out);
    }
}
