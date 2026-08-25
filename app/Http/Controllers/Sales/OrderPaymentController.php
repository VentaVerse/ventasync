<?php

namespace App\Http\Controllers\Sales;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

/**
 * Order payments as a permission area of their own (live fix 2026-08-26).
 *
 * The payments report and the per-order payment writes lived on
 * OrderController, so they shared its keys: anyone who could view orders
 * could open the payments report, and there was no row for payments in
 * the permission editor at all. A controller of its own derives
 * view_sales/order_payment and manage_sales/order_payment. The work
 * itself is unchanged and still done by OrderController's methods.
 */
class OrderPaymentController extends Controller
{
    public function paymentsReport(Request $request)
    {
        return app(OrderController::class)->paymentsReport($request);
    }

    public function storePayment(Request $request, $id)
    {
        return app(OrderController::class)->storePayment($request, $id);
    }

    public function destroyPayment($id, $paymentId)
    {
        return app(OrderController::class)->destroyPayment($id, $paymentId);
    }

    public function togglePayments($id)
    {
        return app(OrderController::class)->togglePayments($id);
    }
}
