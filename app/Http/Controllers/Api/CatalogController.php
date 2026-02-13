<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Catalog\Manufacturer;
use Illuminate\Support\Facades\DB;

class CatalogController extends Controller
{
    private function isWordStartMatch(string $haystack, string $term): bool
    {
        $h = mb_strtolower($haystack, 'UTF-8');
        $t = mb_strtolower(trim($term), 'UTF-8');
        if ($t === '') return false;

        // Match at start or after any non-alphanumeric (space, dash, etc.)
        return (bool) preg_match('/(^|[^a-z0-9])' . preg_quote($t, '/') . '/i', $h);
    }

    public function manufacturers(Request $request)
    {
        $term = trim((string) $request->query('term',''));
        if ($term === '') {
            return response()->json([]);
        }

        // DB prefilter (fast), then PHP word-start filter (accurate & consistent)
        $rows = Manufacturer::query()
            ->where('name', 'like', '%'.$term.'%')
            ->orderBy('name')
            ->limit(80)
            ->get(['manufacturer_id','name']);

        $out = [];
        foreach ($rows as $r) {
            if ($this->isWordStartMatch((string)$r->name, $term)) {
                $out[] = [
                    'manufacturer_id' => (int) $r->manufacturer_id,
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

        $p = config('catalog.prefix');
        $lang = (int) config('catalog.default_language_id');

        $tableCat = $p.'category_description';
        $tablePath = $p.'category_path';

        // Build paths using category_path (like OpenCart)
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

        // Fallback if category_path not populated
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

    public function options(Request $request)
    {
        $term = trim((string) $request->query('term',''));
        if ($term === '') {
            return response()->json([]);
        }

        $p = config('catalog.prefix');
        $lang = (int) config('catalog.default_language_id');

        $rows = DB::table($p.'option as o')
            ->join($p.'option_description as od', function($j) use ($lang){
                $j->on('o.option_id','=','od.option_id')->where('od.language_id','=',$lang);
            })
            ->where('od.name','like','%'.$term.'%')
            ->orderBy('od.name')
            ->limit(120)
            ->get(['o.option_id','o.type','od.name']);

        $out = [];
        foreach ($rows as $r) {
            if ($this->isWordStartMatch((string)$r->name, $term)) {
                $out[] = [
                    'option_id' => (int) $r->option_id,
                    'type' => (string) $r->type,
                    'name' => html_entity_decode((string)$r->name, ENT_QUOTES | ENT_HTML5, 'UTF-8'),
                ];
            }
            if (count($out) >= 20) break;
        }

        return response()->json($out);
    }

    public function optionValues(Request $request, int $optionId)
    {
        $term = trim((string) $request->query('term',''));
        $p = config('catalog.prefix');
        $lang = (int) config('catalog.default_language_id');

        $q = DB::table($p.'option_value_description as ovd')
            ->where('ovd.option_id', $optionId)
            ->where('ovd.language_id', $lang);

        if ($term !== '') {
            $q->where('ovd.name','like','%'.$term.'%');
        }

        $rows = $q->orderBy('ovd.name')->limit(200)->get(['ovd.option_value_id','ovd.name']);

        $out = [];
        foreach ($rows as $r) {
            $name = (string)$r->name;
            if ($term === '' || $this->isWordStartMatch($name, $term)) {
                $out[] = [
                    'option_value_id' => (int) $r->option_value_id,
                    'name' => html_entity_decode($name, ENT_QUOTES | ENT_HTML5, 'UTF-8'),
                ];
            }
            if (count($out) >= 50) break;
        }

        return response()->json($out);
    }
}
