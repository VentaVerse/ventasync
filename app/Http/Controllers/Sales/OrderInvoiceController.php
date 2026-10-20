<?php

namespace App\Http\Controllers\Sales;

use App\Http\Controllers\Controller;
use App\Models\Catalog\Order;
use App\Services\ActivityLogger;
use Barryvdh\DomPDF\Facade\Pdf;
use App\Services\Sales\InvoiceDocument;
use App\Services\Sales\InvoiceNumbers;
use Illuminate\Http\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;

class OrderInvoiceController extends Controller
{
    public function pdf(int $order, InvoiceDocument $document): Response
    {
        $model = Order::where('order_id', $order)->firstOrFail();

        return Pdf::loadView('sales.orders.pdf', $document->for($model))
            ->setPaper('a4')
            ->stream($document->filename($model));
    }

    public function generate(int $order, InvoiceNumbers $numbers): RedirectResponse
    {
        $model = Order::where('order_id', $order)->firstOrFail();
        $invoice = $numbers->issue((int) $model->order_id, auth()->id());

        ActivityLogger::log('created', 'invoice', $invoice->id, $invoice->label() . ' for order #' . $model->order_id, null);

        return redirect()->route('orders.show', $model->order_id)->with('status', 'Invoice ' . $invoice->label() . ' given.');
    }
}
