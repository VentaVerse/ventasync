<?php

namespace App\Http\Controllers;

use App\Services\ActivityLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class OptionController extends Controller
{
    public function index(Request $request)
    {
        $p = config('catalog.prefix');
        $langId = (int) config('catalog.default_language_id');
        $q = trim((string) $request->query('q',''));

        $rows = DB::table($p.'option as o')
            ->join($p.'option_description as od', function ($j) use ($langId) {
                $j->on('o.option_id', '=', 'od.option_id')
                  ->where('od.language_id', '=', $langId);
            })
            ->select('o.option_id', 'o.type', 'o.sort_order', 'od.name')
            ->when($q !== '', function ($query) use ($q) {
                $query->where('od.name', 'like', '%'.$q.'%');
            })
            ->orderBy('od.name')
            ->paginate(20)
            ->withQueryString();

        foreach ($rows as $r) {
            $r->name = html_entity_decode((string)$r->name, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }

        return view('options.index', [
            'options' => $rows,
            'q' => $q,
        ]);
    }

    public function create()
    {
        return view('options.create');
    }

    public function store(Request $request)
    {
        $p = config('catalog.prefix');
        $langId = (int) config('catalog.default_language_id');

        $data = $request->validate([
            'name' => ['required','string','max:128'],
            'type' => ['required','string','max:32'],
            'sort_order' => ['required','integer'],
            'new_values' => ['array'],
            'new_values.*.name' => ['nullable','string','max:128'],
            'new_values.*.sort_order' => ['nullable','integer'],
        ]);

        $name = trim($data['name']);

        $optionId = null;
        DB::transaction(function () use ($p, $langId, $data, $name, &$optionId) {
            $id = DB::table($p.'option')->insertGetId([
                'type' => $data['type'],
                'sort_order' => (int) $data['sort_order'],
            ]);

            $optionId = $id;

            DB::table($p.'option_description')->insert([
                'option_id' => $id,
                'language_id' => $langId,
                'name' => $name,
            ]);

            // Insert new values (OpenCart-like) for types that support values
            if (in_array($data['type'], ['select','radio','checkbox'], true)) {
                $new = $data['new_values'] ?? [];
                foreach ($new as $row) {
                    $vName = trim((string)($row['name'] ?? ''));
                    if ($vName === '') continue;

                    $newId = DB::table($p.'option_value')->insertGetId([
                        'option_id' => $id,
                        'image' => '',
                        'sort_order' => (int)($row['sort_order'] ?? 0),
                    ]);

                    DB::table($p.'option_value_description')->insert([
                        'option_value_id' => $newId,
                        'language_id' => $langId,
                        'option_id' => $id,
                        'name' => $vName,
                    ]);
                }
            }
        });

        ActivityLogger::log('created', 'Option', $optionId, $name);

        return redirect()->route('options.index')->with('success', 'Option created.');
    }

    public function edit(int $id)
    {
        $p = config('catalog.prefix');
        $langId = (int) config('catalog.default_language_id');

        $option = DB::table($p.'option as o')
            ->join($p.'option_description as od', function ($j) use ($langId) {
                $j->on('o.option_id', '=', 'od.option_id')
                  ->where('od.language_id', '=', $langId);
            })
            ->where('o.option_id', $id)
            ->select('o.option_id','o.type','o.sort_order','od.name')
            ->first();

        abort_if(!$option, 404);

        $option->name = html_entity_decode((string)$option->name, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        $values = DB::table($p.'option_value as ov')
            ->join($p.'option_value_description as ovd', function ($j) use ($langId) {
                $j->on('ov.option_value_id', '=', 'ovd.option_value_id')
                  ->where('ovd.language_id', '=', $langId);
            })
            ->where('ov.option_id', $id)
            ->orderBy('ov.sort_order')
            ->orderBy('ovd.name')
            ->get([
                'ov.option_value_id',
                'ov.sort_order',
                'ov.image',
                'ovd.name',
            ]);

        foreach ($values as $v) {
            $v->name = html_entity_decode((string)$v->name, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }

        return view('options.edit', [
            'option' => $option,
            'values' => $values,
        ]);
    }

    public function update(Request $request, int $id)
    {
        $p = config('catalog.prefix');
        $langId = (int) config('catalog.default_language_id');

        $data = $request->validate([
            'name' => ['required','string','max:128'],
            'type' => ['required','string','max:32'],
            'sort_order' => ['required','integer'],
            'values' => ['array'],
            'values.*.option_value_id' => ['nullable','integer'],
            'values.*.name' => ['nullable','string','max:128'],
            'values.*.sort_order' => ['nullable','integer'],
            'new_values' => ['array'],
            'new_values.*.name' => ['nullable','string','max:128'],
            'new_values.*.sort_order' => ['nullable','integer'],
        ]);

        $name = trim($data['name']);

        DB::transaction(function () use ($p, $langId, $id, $data, $name) {
            DB::table($p.'option')->where('option_id', $id)->update([
                'type' => $data['type'],
                'sort_order' => (int) $data['sort_order'],
            ]);

            DB::table($p.'option_description')
                ->where('option_id', $id)
                ->where('language_id', $langId)
                ->update(['name' => $name]);

            // Update existing values / insert new ones only for types that support values
            if (in_array($data['type'], ['select','radio','checkbox'], true)) {
                $values = $data['values'] ?? [];
            foreach ($values as $row) {
                $ovId = (int) ($row['option_value_id'] ?? 0);
                if ($ovId <= 0) continue;

                $vName = trim((string)($row['name'] ?? ''));
                if ($vName === '') continue;

                DB::table($p.'option_value')->where('option_value_id', $ovId)->where('option_id', $id)->update([
                    'sort_order' => (int)($row['sort_order'] ?? 0),
                ]);

                DB::table($p.'option_value_description')
                    ->where('option_value_id', $ovId)
                    ->where('language_id', $langId)
                    ->update([
                        'name' => $vName,
                        'option_id' => $id,
                    ]);
            }

            // Insert new values
            $new = $data['new_values'] ?? [];
            foreach ($new as $row) {
                $vName = trim((string)($row['name'] ?? ''));
                if ($vName === '') continue;

                $newId = DB::table($p.'option_value')->insertGetId([
                    'option_id' => $id,
                    'image' => '',
                    'sort_order' => (int)($row['sort_order'] ?? 0),
                ]);

                DB::table($p.'option_value_description')->insert([
                    'option_value_id' => $newId,
                    'language_id' => $langId,
                    'option_id' => $id,
                    'name' => $vName,
                ]);
            }
            }
        });

        ActivityLogger::log('updated', 'Option', $id, $name);

        return redirect()->route('options.edit', $id)->with('success', 'Option updated.');
    }

    public function destroy(int $id)
    {
        $p = config('catalog.prefix');
        $langId = (int) config('catalog.default_language_id');

        // Production-safe: block deletion if option is used by any product option
        $inUse = DB::table($p.'product_option')->where('option_id', $id)->exists();
        if ($inUse) {
            return redirect()->route('options.index')->with('error', 'Cannot delete: option is used by products.');
        }

        $name = DB::table($p.'option_description')->where('option_id', $id)->where('language_id', $langId)->value('name') ?? '#' . $id;

        DB::transaction(function () use ($p, $id) {
            DB::table($p.'option_value_description')->where('option_id', $id)->delete();
            DB::table($p.'option_value')->where('option_id', $id)->delete();
            DB::table($p.'option_description')->where('option_id', $id)->delete();
            DB::table($p.'option')->where('option_id', $id)->delete();
        });

        ActivityLogger::log('deleted', 'Option', $id, $name);

        return redirect()->route('options.index')->with('success', 'Option deleted.');
    }

    public function bulkDestroy(Request $request)
    {
        $p = config('catalog.prefix');
        $ids = $request->input('ids', []);

        if (!is_array($ids)) {
            $ids = [];
        }
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), fn ($v) => $v > 0)));

        if (empty($ids)) {
            return redirect()->route('options.index')->with('error', 'No items selected.');
        }

        $blocked = [];
        $deleted = 0;

        DB::transaction(function () use ($p, $ids, &$blocked, &$deleted) {
            foreach ($ids as $id) {
                $inUse = DB::table($p.'product_option')->where('option_id', $id)->exists();
                if ($inUse) {
                    $blocked[] = (int) $id;
                    continue;
                }

                DB::table($p.'option_value_description')->where('option_id', $id)->delete();
                DB::table($p.'option_value')->where('option_id', $id)->delete();
                DB::table($p.'option_description')->where('option_id', $id)->delete();
                DB::table($p.'option')->where('option_id', $id)->delete();
                $deleted++;
            }
        });

        if ($deleted > 0) {
            ActivityLogger::log('deleted', 'Option', null, $deleted . ' options');
        }

        if ($deleted > 0 && !empty($blocked)) {
            return redirect()->route('options.index')->with('success', 'Deleted selected options (except those in use).')
                ->with('error', 'Cannot delete options in use (IDs: '.implode(', ', $blocked).').');
        }

        if (!empty($blocked) && $deleted === 0) {
            return redirect()->route('options.index')->with('error', 'Cannot delete: selected options are used by products (IDs: '.implode(', ', $blocked).').');
        }

        return redirect()->route('options.index')->with('success', 'Deleted selected options.');
    }

    public function destroyValue(int $optionId, int $valueId)
    {
        $p = config('catalog.prefix');

        // Block deletion if used by product_option_value
        $inUse = DB::table($p.'product_option_value')->where('option_value_id', $valueId)->exists();
        if ($inUse) {
            return redirect()->route('options.edit', $optionId)->with('error', 'Cannot delete: value is used by products.');
        }

        DB::transaction(function () use ($p, $optionId, $valueId) {
            DB::table($p.'option_value_description')->where('option_value_id', $valueId)->delete();
            DB::table($p.'option_value')->where('option_value_id', $valueId)->where('option_id', $optionId)->delete();
        });

        return redirect()->route('options.edit', $optionId)->with('success', 'Value deleted.');
    }
}
