<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Services\ActivityLogger;
use App\Models\InvoiceSettings;
use App\Models\TaxRate;
use App\Http\Requests\Settings\SaveTaxRate;
use App\Http\Requests\Settings\UpdateTaxRegistration;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;

class TaxController extends Controller
{
    public function index(): View
    {
        return view('settings.taxes.index', [
            'settings' => InvoiceSettings::current(),
            'rates' => TaxRate::orderByDesc('is_default')->orderBy('name')->get(),
        ]);
    }

    public function updateRegistration(UpdateTaxRegistration $request): RedirectResponse
    {
        $settings = InvoiceSettings::current();
        $settings->update([
            'tax_status' => $request->validated('tax_status'),
            'tin' => (string) $request->validated('tin'),
            'branch_code' => $request->validated('branch_code') ?: '00000',
            'business_style' => trim((string) $request->validated('business_style')),
        ]);

        ActivityLogger::log('updated', 'tax_registration', $settings->id, 'Tax registration', null);

        return redirect()->route('taxes.index')->with('success', 'Tax registration saved.');
    }

    public function create(): View
    {
        return view('settings.taxes.form', ['rate' => null, 'isFirst' => TaxRate::count() === 0]);
    }

    public function store(SaveTaxRate $request): RedirectResponse
    {
        $rate = DB::transaction(function () use ($request) {
            $makeDefault = $request->boolean('is_default') || TaxRate::count() === 0;
            if ($makeDefault) {
                TaxRate::where('is_default', true)->update(['is_default' => false]);
            }

            return TaxRate::create([
                'name' => trim($request->validated('name')),
                'rate' => $request->validated('rate'),
                'is_default' => $makeDefault,
            ]);
        });

        ActivityLogger::log('created', 'tax_rate', $rate->id, $rate->name, null);

        return redirect()->route('taxes.index')->with('success', 'Tax rate added.');
    }

    public function edit(int $rate): View
    {
        return view('settings.taxes.form', ['rate' => TaxRate::findOrFail($rate), 'isFirst' => false]);
    }

    public function update(SaveTaxRate $request, int $rate): RedirectResponse
    {
        $taxRate = TaxRate::findOrFail($rate);

        DB::transaction(function () use ($request, $taxRate) {
            $makeDefault = $taxRate->is_default || $request->boolean('is_default');
            if ($makeDefault && ! $taxRate->is_default) {
                TaxRate::where('is_default', true)->update(['is_default' => false]);
            }

            $taxRate->update([
                'name' => trim($request->validated('name')),
                'rate' => $request->validated('rate'),
                'is_default' => $makeDefault,
            ]);
        });

        ActivityLogger::log('updated', 'tax_rate', $taxRate->id, $taxRate->name, null);

        return redirect()->route('taxes.index')->with('success', 'Tax rate saved.');
    }

    public function destroy(int $rate): RedirectResponse
    {
        $taxRate = TaxRate::findOrFail($rate);

        if ($taxRate->is_default) {
            return redirect()->route('taxes.index')->with('error', 'The default tax rate cannot be deleted. Make another rate the default first.');
        }

        $taxRate->delete();
        ActivityLogger::log('deleted', 'tax_rate', $rate, $taxRate->name, null);

        return redirect()->route('taxes.index')->with('success', 'Tax rate deleted.');
    }
}
