<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Services\ActivityLogger;
use App\Models\InvoiceSettings;
use App\Http\Requests\Settings\UpdateInvoiceSettings;
use App\Services\Sales\InvoiceNumbers;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;

class InvoiceController extends Controller
{
    public function edit(InvoiceNumbers $numbers): View
    {
        return view('settings.invoices.edit', [
            'settings' => InvoiceSettings::current(),
            'last' => $numbers->lastIssued(),
            'next' => $numbers->preview(),
        ]);
    }

    public function update(UpdateInvoiceSettings $request): RedirectResponse
    {
        $settings = InvoiceSettings::current();
        $settings->update([
            'prefix' => $request->validated('prefix'),
            'print_logo' => $request->boolean('print_logo'),
        ]);

        ActivityLogger::log('updated', 'invoice_settings', $settings->id, 'Invoice settings', null);

        return redirect()->route('settings.invoices')->with('success', 'Invoice settings saved.');
    }
}
