<?php

namespace App\Http\Controllers;

use App\Models\Currency;
use App\Models\Vendor;
use App\Services\ActivityLogger;
use Illuminate\Http\Request;

class VendorController extends Controller
{
    public function index(Request $request)
    {
        $q = trim((string) $request->get('q', ''));

        $vendors = Vendor::query()
            ->when($q !== '', function ($query) use ($q) {
                $query->where('name', 'like', '%' . $q . '%')
                      ->orWhere('contact_person', 'like', '%' . $q . '%')
                      ->orWhere('email', 'like', '%' . $q . '%');
            })
            ->orderBy('name')
            ->paginate(50)
            ->withQueryString();

        return view('vendors.index', compact('vendors', 'q'));
    }

    public function create()
    {
        $currencies = Currency::where('status', 1)->orderBy('code')->get();
        return view('vendors.create', compact('currencies'));
    }

    public function store(Request $request)
    {
        $request->validate([
            'name' => 'required|string|max:128',
            'contact_person' => 'nullable|string|max:128',
            'email' => 'nullable|email|max:128',
            'phone' => 'nullable|string|max:32',
            'address_line1' => 'nullable|string|max:255',
            'address_line2' => 'nullable|string|max:255',
            'city' => 'nullable|string|max:128',
            'state' => 'nullable|string|max:128',
            'country' => 'nullable|string|max:128',
            'postal_code' => 'nullable|string|max:16',
            'currency_code' => 'required|string|max:3',
            'payment_terms' => 'nullable|string|max:64',
            'notes' => 'nullable|string',
            'status' => 'required|in:0,1',
        ]);

        $data = $request->only([
            'name', 'contact_person', 'email', 'phone',
            'address_line1', 'address_line2', 'city', 'state', 'country', 'postal_code',
            'currency_code', 'payment_terms', 'notes', 'status',
        ]);
        $data['email_po_enabled'] = $request->boolean('email_po_enabled');

        $vendor = Vendor::create($data);

        ActivityLogger::log('created', 'Vendor', $vendor->id, $vendor->name);

        return redirect()->route('vendors.index')->with('status', 'Vendor created.');
    }

    public function edit($id)
    {
        $vendor = Vendor::findOrFail($id);
        $currencies = Currency::where('status', 1)->orderBy('code')->get();
        return view('vendors.edit', compact('vendor', 'currencies'));
    }

    public function update(Request $request, $id)
    {
        $vendor = Vendor::findOrFail($id);

        $request->validate([
            'name' => 'required|string|max:128',
            'contact_person' => 'nullable|string|max:128',
            'email' => 'nullable|email|max:128',
            'phone' => 'nullable|string|max:32',
            'address_line1' => 'nullable|string|max:255',
            'address_line2' => 'nullable|string|max:255',
            'city' => 'nullable|string|max:128',
            'state' => 'nullable|string|max:128',
            'country' => 'nullable|string|max:128',
            'postal_code' => 'nullable|string|max:16',
            'currency_code' => 'required|string|max:3',
            'payment_terms' => 'nullable|string|max:64',
            'notes' => 'nullable|string',
            'status' => 'required|in:0,1',
        ]);

        $original = $vendor->getAttributes();

        $data = $request->only([
            'name', 'contact_person', 'email', 'phone',
            'address_line1', 'address_line2', 'city', 'state', 'country', 'postal_code',
            'currency_code', 'payment_terms', 'notes', 'status',
        ]);
        $data['email_po_enabled'] = $request->boolean('email_po_enabled');

        $vendor->update($data);

        $changes = ActivityLogger::diff($original, $vendor->getAttributes(), [
            'name', 'contact_person', 'email', 'phone', 'currency_code', 'payment_terms', 'email_po_enabled', 'status',
        ]);
        ActivityLogger::log('updated', 'Vendor', $id, $vendor->name, $changes);

        return redirect()->route('vendors.index')->with('status', 'Vendor updated.');
    }

    public function destroy($id)
    {
        $vendor = Vendor::findOrFail($id);
        $name = $vendor->name;
        $vendor->delete();
        ActivityLogger::log('deleted', 'Vendor', $id, $name);
        return redirect()->route('vendors.index')->with('status', 'Vendor deleted.');
    }

    public function bulkDestroy(Request $request)
    {
        $ids = $request->input('ids', []);
        if (!is_array($ids)) {
            $ids = [];
        }
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), fn ($v) => $v > 0)));

        if (empty($ids)) {
            return redirect()->route('vendors.index')->with('status', 'No items selected.');
        }

        Vendor::whereIn('id', $ids)->delete();
        ActivityLogger::log('deleted', 'Vendor', null, count($ids) . ' vendors');

        return redirect()->route('vendors.index')->with('status', 'Deleted selected vendors.');
    }

    public function lookup(Request $request)
    {
        $q = trim((string) $request->get('q', ''));

        $rows = Vendor::query()
            ->where('status', 1)
            ->when($q !== '', fn($query) => $query->where('name', 'like', '%' . $q . '%'))
            ->orderBy('name')
            ->limit(50)
            ->get(['id', 'name', 'currency_code']);

        return response()->json($rows);
    }
}
