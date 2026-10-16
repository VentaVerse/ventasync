<?php

namespace App\Services\Orders;

use App\Models\Catalog\Order;
use Illuminate\Support\Facades\DB;

final class OrderProfit
{
    private function __construct(
        public readonly float $revenue,
        public readonly float $cogs,
        public readonly float $ownShippingCost,
        public readonly float $reportedShippingCost,
        public readonly array $fees,
        public readonly bool $hasLines,
        public readonly float $customerShippingCharged = 0.0,
        public readonly bool $shippingPaidToSeller = false,
    ) {}

    public static function of(Order $order): self
    {
        $orderId = (int) $order->order_id;

        $lines = DB::table('order_products')
            ->where('order_id', $orderId)
            ->selectRaw('COUNT(*) AS n, COALESCE(SUM(total), 0) AS revenue, COALESCE(SUM(cost * quantity), 0) AS cogs')
            ->first();

        $shippingCode = ChannelFeeNormalizer::CODES['shipping'][0];
        $voucherCode = ChannelFeeNormalizer::CODES['voucher'][0];
        $reported = DB::table('order_totals')
            ->where('order_id', $orderId)
            ->whereIn('code', array_column(ChannelFeeNormalizer::CODES, 0))
            ->orderBy('sort_order')
            ->get(['code', 'title', 'value']);

        $reportedShipping = 0.0;
        $customerShipping = (float) DB::table('order_totals')
            ->where('order_id', $orderId)
            ->where('code', CustomerShipping::TOTAL_CODE)
            ->sum('value');
        $fees = [];
        $vouchers = [];
        foreach ($reported as $row) {
            $amount = abs((float) $row->value);
            if ($row->code === $shippingCode) {
                $reportedShipping += $amount;
            } elseif ($row->code === $voucherCode) {
                if ($amount > 0) {
                    $vouchers[] = ['label' => (string) $row->title, 'amount' => $amount, 'fee_id' => null];
                }
            } elseif ($amount > 0) {
                $fees[] = ['label' => (string) $row->title, 'amount' => $amount, 'fee_id' => null];
            }
        }

        if ($fees === []) {
            foreach (['Payment cost' => $order->payment_cost, 'Extra cost' => $order->extra_cost] as $label => $value) {
                $amount = abs((float) ($value ?? 0));
                if ($amount > 0) {
                    $fees[] = ['label' => $label, 'amount' => $amount, 'fee_id' => null];
                }
            }
        }

        $fees = array_merge($fees, $vouchers);

        foreach (DB::table('order_fees')->where('order_id', $orderId)->orderBy('id')->get(['id', 'label', 'amount']) as $fee) {
            $amount = abs((float) $fee->amount);
            if ($amount > 0) {
                $fees[] = ['label' => (string) $fee->label, 'amount' => $amount, 'fee_id' => (int) $fee->id];
            }
        }

        return new self(
            revenue: (float) $lines->revenue,
            cogs: (float) $lines->cogs,
            ownShippingCost: abs((float) ($order->shipping_cost ?? 0)),
            reportedShippingCost: $reportedShipping,
            fees: $fees,
            hasLines: (int) $lines->n > 0,
            customerShippingCharged: max(0.0, $customerShipping),
            shippingPaidToSeller: app(CustomerShipping::class)->paidToSeller($order->channel),
        );
    }

    public function shippingCost(): float
    {
        return $this->hasLines ? $this->ownShippingCost + $this->reportedShippingCost : 0.0;
    }

    /** The customer's shipping counted as income: on the seller's own channels; a marketplace keeps it. */
    public function shippingIncome(): float
    {
        return $this->hasLines && $this->shippingPaidToSeller ? $this->customerShippingCharged : 0.0;
    }

    public function feesTotal(): float
    {
        return $this->hasLines ? array_sum(array_column($this->fees, 'amount')) : 0.0;
    }

    public function netProfit(): float
    {
        return $this->revenue + $this->shippingIncome() - $this->cogs - $this->shippingCost() - $this->feesTotal();
    }

    public function margin(): float
    {
        return $this->revenue > 0 ? $this->netProfit() / $this->revenue * 100 : 0.0;
    }

    public function markup(): float
    {
        $costs = $this->cogs + $this->shippingCost() + $this->feesTotal();

        return $costs > 0 ? $this->netProfit() / $costs * 100 : 0.0;
    }
}
