<?php

namespace App\Http\Controllers\Sales;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

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
