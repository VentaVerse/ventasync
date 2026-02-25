<?php

namespace App\Http\Controllers;

use App\Models\Catalog\OrderStatus;
use App\Services\ActivityLogger;
use Illuminate\Http\Request;

class OrderStatusController extends Controller
{
    public function index(Request $request)
    {
        $q = trim((string) $request->get('q', ''));

        $statuses = OrderStatus::query()
            ->when($q !== '', function ($query) use ($q) {
                $query->where('name', 'like', '%' . $q . '%');
            })
            ->orderBy('order_status_id')
            ->paginate(50)
            ->withQueryString();

        return view('order_statuses.index', compact('statuses', 'q'));
    }

    public function create()
    {
        return view('order_statuses.create');
    }

    public function store(Request $request)
    {
        $request->validate([
            'name' => 'required|string|max:32',
        ]);

        $os = OrderStatus::create([
            'language_id' => 1,
            'name' => $request->name,
            'subtract_stock' => $request->has('subtract_stock') ? 1 : 0,
            'add_revenue' => $request->has('add_revenue') ? 1 : 0,
        ]);

        ActivityLogger::log('created', 'Order Status', (int) $os->order_status_id, $request->name);

        return redirect()->route('order_statuses.index')->with('status', 'Saved');
    }

    public function edit($id)
    {
        $orderStatus = OrderStatus::where('order_status_id', (int) $id)->firstOrFail();

        return view('order_statuses.edit', compact('orderStatus'));
    }

    public function update(Request $request, $id)
    {
        $request->validate([
            'name' => 'required|string|max:32',
        ]);

        $orderStatus = OrderStatus::where('order_status_id', (int) $id)->firstOrFail();
        $original = $orderStatus->getAttributes();
        $orderStatus->name = $request->name;
        $orderStatus->subtract_stock = $request->has('subtract_stock') ? 1 : 0;
        $orderStatus->add_revenue = $request->has('add_revenue') ? 1 : 0;
        $orderStatus->save();

        $changes = ActivityLogger::diff($original, $orderStatus->getAttributes(), ['name', 'subtract_stock', 'add_revenue']);
        ActivityLogger::log('updated', 'Order Status', (int) $id, $request->name, $changes);

        return redirect()->route('order_statuses.index')->with('status', 'Saved');
    }

    public function destroy($id)
    {
        $name = OrderStatus::where('order_status_id', (int) $id)->value('name') ?? '#' . $id;
        OrderStatus::where('order_status_id', (int) $id)->delete();

        ActivityLogger::log('deleted', 'Order Status', (int) $id, $name);

        return redirect()->route('order_statuses.index')->with('status', 'Saved');
    }

    public function bulkAction(Request $request)
    {
        $ids = $request->input('ids', []);
        if (!is_array($ids)) {
            $ids = [];
        }
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), fn ($v) => $v > 0)));

        if (empty($ids)) {
            return redirect()->route('order_statuses.index')->with('status', 'No items selected.');
        }

        OrderStatus::whereIn('order_status_id', $ids)->delete();

        ActivityLogger::log('deleted', 'Order Status', null, count($ids) . ' order statuses');

        return redirect()->route('order_statuses.index')->with('status', 'Deleted selected order statuses.');
    }
}
