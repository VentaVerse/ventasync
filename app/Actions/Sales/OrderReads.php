<?php

namespace App\Actions\Sales;

use App\Support\Api\Input;
use App\Support\Api\Refused;
use App\Support\Money;
use Illuminate\Support\Facades\DB;

final class OrderReads
{
    public function index(Input $request): array
    {
        $request->validate([
            'date_from' => ['nullable', 'date'],
            'date_to'   => ['nullable', 'date'],
            'status'    => ['nullable', 'integer'],
            'source'    => ['nullable', 'string', 'max:32'],
            'search'    => ['nullable', 'string', 'max:255'],
            'per_page'  => ['nullable', 'integer', 'min:1', 'max:200'],
        ]);

        $pfx = (string) config('catalog.prefix');
        $langId = (int) config('catalog.default_language_id');
        $perPage = max(1, min((int) $request->integer('per_page', 50), 200));

        $query = DB::table($pfx . 'order as o')
            ->leftJoin($pfx . 'order_status as os', function ($j) use ($langId) {
                $j->on('os.order_status_id', '=', 'o.order_status_id')
                    ->where('os.language_id', '=', $langId);
            })
            ->leftJoin(DB::raw("(SELECT order_id, COUNT(*) as items, SUM(quantity) as units FROM `{$pfx}order_product` GROUP BY order_id) as op"),
                'op.order_id', '=', 'o.order_id')
            ->select(
                'o.order_id',
                'o.marketplace_source',
                'o.marketplace_order_id',
                'o.store_name',
                DB::raw("TRIM(CONCAT(o.firstname, ' ', o.lastname)) as customer"),
                'o.order_status_id',
                'os.name as status',
                'o.total',
                'o.foreign_total',
                'o.currency_code',
                DB::raw('COALESCE(op.items, 0) as line_items'),
                DB::raw('COALESCE(op.units, 0) as units'),
                'o.date_added'
            );

        if ($from = $request->query('date_from')) {
            $query->where('o.date_added', '>=', $from . ' 00:00:00');
        }
        if ($to = $request->query('date_to')) {
            $query->where('o.date_added', '<=', $to . ' 23:59:59');
        }
        if ($request->filled('status')) {
            $query->where('o.order_status_id', (int) $request->integer('status'));
        }
        if ($source = $request->query('source')) {
            $query->where('o.marketplace_source', $source);
        }
        if ($search = trim((string) $request->query('search', ''))) {
            $query->where(function ($q) use ($search) {
                $q->where('o.order_id', '=', ctype_digit($search) ? (int) $search : 0)
                    ->orWhere('o.marketplace_order_id', 'like', "%{$search}%")
                    ->orWhere('o.firstname', 'like', "%{$search}%")
                    ->orWhere('o.lastname', 'like', "%{$search}%");
            });
        }

        $defaultCode = Money::defaultCode();
        $paginated = $query->orderByDesc('o.date_added')->paginate($perPage)->withPath(\App\Support\AppDoor::pagePath('orders'));
        $paginated->through(fn ($row) => self::applyCurrencyShape($row, $defaultCode));

        return $this->json($paginated);
    }

    public function show(Input $request, int $id): array
    {
        $pfx = (string) config('catalog.prefix');
        $langId = (int) config('catalog.default_language_id');

        $order = DB::table($pfx . 'order as o')
            ->leftJoin($pfx . 'order_status as os', function ($j) use ($langId) {
                $j->on('os.order_status_id', '=', 'o.order_status_id')
                    ->where('os.language_id', '=', $langId);
            })
            ->where('o.order_id', $id)
            ->select(
                'o.order_id', 'o.marketplace_source', 'o.marketplace_order_id', 'o.store_name',
                DB::raw("TRIM(CONCAT(o.firstname, ' ', o.lastname)) as customer"),
                'o.email', 'o.telephone', 'o.payment_method',
                'o.order_status_id', 'os.name as status',
                'o.total', 'o.foreign_total', 'o.currency_code', 'o.date_added'
            )
            ->first();

        if (! $order) {
            return $this->json(['message' => 'Order not found.'], 404);
        }

        $order = self::applyCurrencyShape($order, Money::defaultCode());

        $items = DB::table($pfx . 'order_product')
            ->where('order_id', $id)
            ->select('product_id', 'name', 'model', 'quantity', 'price', 'total', 'cost')
            ->get();

        return $this->json([
            'order' => $order,
            'items' => $items,
            'receivable' => app(\App\Services\Sales\Receivables::class)->ofOrder($id),
        ]);
    }

    private static function applyCurrencyShape(object $row, string $defaultCode): object
    {
        $foreignTotal = $row->foreign_total;

        $row->transaction_currency = $foreignTotal !== null ? $row->currency_code : null;
        $row->transaction_total = $foreignTotal !== null ? (float) $foreignTotal : null;
        $row->currency_code = $defaultCode;
        unset($row->foreign_total);

        return $row;
    }

    private function json(mixed $data, int $status = 200): array
    {
        if ($status >= 400) {
            $data = (array) json_decode((string) json_encode($data), true);

            throw new Refused((string) ($data['message'] ?? 'The request was refused.'), $status);
        }

        return is_array($data) ? $data : (array) json_decode((string) json_encode($data), true);
    }
}
