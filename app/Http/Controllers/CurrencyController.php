<?php

namespace App\Http\Controllers;

use App\Models\Currency;
use App\Services\ActivityLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class CurrencyController extends Controller
{
    public function index(Request $request)
    {
        $q = trim((string) $request->get('q', ''));

        $currencies = Currency::query()
            ->when($q !== '', function ($query) use ($q) {
                $query->where('code', 'like', '%' . $q . '%')
                      ->orWhere('name', 'like', '%' . $q . '%');
            })
            ->orderBy('code')
            ->paginate(50)
            ->withQueryString();

        return view('currencies.index', compact('currencies', 'q'));
    }

    public function create()
    {
        return view('currencies.create');
    }

    public function store(Request $request)
    {
        $request->validate([
            'code' => 'required|string|max:3|unique:currencies,code',
            'name' => 'required|string|max:64',
            'symbol' => 'required|string|max:8',
            'exchange_rate' => 'required|numeric|min:0',
            'is_default' => 'nullable|boolean',
            'status' => 'required|in:0,1',
        ]);

        $isDefault = (bool) $request->input('is_default', false);

        if ($isDefault) {
            Currency::where('is_default', true)->update(['is_default' => false]);
        }

        $currency = Currency::create([
            'code' => strtoupper($request->code),
            'name' => $request->name,
            'symbol' => $request->symbol,
            'exchange_rate' => $request->exchange_rate,
            'is_default' => $isDefault,
            'status' => (int) $request->status,
        ]);

        ActivityLogger::log('created', 'Currency', $currency->id, $currency->code);

        return redirect()->route('currencies.index')->with('status', 'Currency created.');
    }

    public function edit($id)
    {
        $currency = Currency::findOrFail($id);
        return view('currencies.edit', compact('currency'));
    }

    public function update(Request $request, $id)
    {
        $currency = Currency::findOrFail($id);

        $request->validate([
            'code' => 'required|string|max:3|unique:currencies,code,' . $id,
            'name' => 'required|string|max:64',
            'symbol' => 'required|string|max:8',
            'exchange_rate' => 'required|numeric|min:0',
            'is_default' => 'nullable|boolean',
            'status' => 'required|in:0,1',
        ]);

        $isDefault = (bool) $request->input('is_default', false);

        if ($isDefault && !$currency->is_default) {
            Currency::where('is_default', true)->update(['is_default' => false]);
        }

        $original = $currency->getAttributes();

        $currency->update([
            'code' => strtoupper($request->code),
            'name' => $request->name,
            'symbol' => $request->symbol,
            'exchange_rate' => $request->exchange_rate,
            'is_default' => $isDefault,
            'status' => (int) $request->status,
        ]);

        $changes = ActivityLogger::diff($original, $currency->getAttributes(), ['code', 'name', 'symbol', 'exchange_rate', 'is_default', 'status']);
        ActivityLogger::log('updated', 'Currency', $id, $currency->code, $changes);

        return redirect()->route('currencies.index')->with('status', 'Currency updated.');
    }

    public function destroy($id)
    {
        $currency = Currency::findOrFail($id);

        if ($currency->is_default) {
            return redirect()->route('currencies.index')->with('error', 'Cannot delete the default currency.');
        }

        $code = $currency->code;
        $currency->delete();
        ActivityLogger::log('deleted', 'Currency', $id, $code);

        return redirect()->route('currencies.index')->with('status', 'Currency deleted.');
    }

    /**
     * Fetch rates from frankfurter.app. Shared by button + cron.
     * Returns [success, message].
     */
    private function fetchAndUpdateRates(): array
    {
        $base = Currency::where('is_default', 1)->first();

        if (!$base) {
            return [false, 'No default currency configured.'];
        }

        $currencies = Currency::where('is_default', 0)
            ->where('status', 1)
            ->pluck('code')
            ->toArray();

        if (empty($currencies)) {
            return [true, 'No active non-default currencies to update.'];
        }

        $url = 'https://api.frankfurter.app/latest?from=' . $base->code . '&to=' . implode(',', $currencies);

        $response = Http::timeout(15)->get($url);

        if (!$response->successful()) {
            return [false, "Failed to fetch rates: HTTP {$response->status()}"];
        }

        $data = $response->json();
        $rates = $data['rates'] ?? [];
        $date = $data['date'] ?? 'unknown';
        $updated = 0;
        $skipped = [];

        foreach ($currencies as $code) {
            if (!isset($rates[$code]) || $rates[$code] <= 0) {
                $skipped[] = $code;
                continue;
            }

            // API returns: 1 base = X foreign → invert to: 1 foreign = X base
            $erpRate = round(1 / $rates[$code], 8);
            Currency::where('code', $code)->update(['exchange_rate' => $erpRate]);
            $updated++;
        }

        $msg = "Updated {$updated} exchange rates (source: ECB, date: {$date}).";
        if (!empty($skipped)) {
            $msg .= ' Skipped: ' . implode(', ', $skipped) . ' (not available).';
        }

        Log::info("currencies:update-rates: {$msg}");

        return [true, $msg];
    }

    public function updateRates()
    {
        try {
            [$ok, $msg] = $this->fetchAndUpdateRates();
            return redirect()->route('currencies.index')->with($ok ? 'status' : 'error', $msg);
        } catch (\Exception $e) {
            Log::error("currencies:update-rates: {$e->getMessage()}");
            return redirect()->route('currencies.index')
                ->with('error', 'Failed to fetch rates: ' . $e->getMessage());
        }
    }

    public function lookup(Request $request)
    {
        $q = trim((string) $request->get('q', ''));

        $rows = Currency::query()
            ->where('status', 1)
            ->when($q !== '', fn($query) => $query->where('code', 'like', '%' . $q . '%')->orWhere('name', 'like', '%' . $q . '%'))
            ->orderBy('code')
            ->limit(20)
            ->get(['id', 'code', 'name', 'symbol', 'exchange_rate']);

        return response()->json($rows);
    }
}
