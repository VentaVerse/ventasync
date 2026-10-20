<?php

namespace App\Services\Sales;

use App\Channels\ChannelRegistry;
use App\Models\Catalog\Order;
use App\Models\Setting;
use App\Support\LineVariation;
use App\Support\StoreLabel;
use App\Models\Invoice;
use App\Models\InvoiceSettings;
use App\Models\TaxRate;
use Illuminate\Support\Facades\Storage;

class InvoiceDocument
{
    public function for(Order $order): array
    {
        $company = Setting::singleton();
        $settings = InvoiceSettings::current();
        $invoice = Invoice::where('order_id', $order->order_id)->first();

        return [
            'order' => $order,
            'invoice' => $invoice,
            'seller' => [
                'name' => (string) $company->company_name,
                'business_style' => trim((string) $settings->business_style),
                'address' => $this->join([
                    $company->address_line1, $company->address_line2,
                    $this->join([$company->city, $company->state, $company->postal_code], ' '),
                    $company->country,
                ]),
                'contact' => $this->join([$company->telephone, $company->company_email], ' · '),
                'tin' => $settings->fullTin(),
                'is_vat' => $settings->isVat(),
                'logo' => $settings->print_logo ? $this->logo($company->logo_path) : null,
            ],
            'customer' => [
                'name' => trim($order->firstname . ' ' . $order->lastname),
                'company' => trim((string) ($order->payment_company ?: $order->shipping_company)),
                'address' => $this->customerAddress($order),
                'tin' => trim((string) $order->customer_tin),
            ],
            'store' => $this->storeLabel($order),
            'lines' => $order->products()->get()->map(fn ($line) => [
                'name' => html_entity_decode((string) $line->name, ENT_QUOTES | ENT_HTML5, 'UTF-8'),
                'variation' => LineVariation::label($line->sku, null, (int) $order->order_id, $line->name),
                'sku' => trim((string) $line->sku),
                'quantity' => (int) $line->quantity,
                'price' => (float) $line->price,
                'total' => (float) $line->total,
            ])->all(),
            'totals' => $order->totals()->orderBy('sort_order')->get()
                ->map(fn ($row) => ['code' => (string) $row->code, 'title' => (string) $row->title, 'value' => (float) $row->value])
                ->all(),
            'tax' => $settings->isVat() ? $this->vat((float) $order->total) : null,
        ];
    }

    public function filename(Order $order): string
    {
        $invoice = Invoice::where('order_id', $order->order_id)->first();

        return ($invoice ? $invoice->label() : 'order-' . $order->order_id) . '.pdf';
    }

    private function vat(float $total): ?array
    {
        $rate = TaxRate::default();
        if (! $rate) {
            return null;
        }

        $taxable = round($total / (1 + (float) $rate->rate / 100), 2);

        return [
            'name' => $rate->name,
            'percent' => $rate->percent(),
            'taxable' => $taxable,
            'tax' => round($total - $taxable, 2),
        ];
    }

    private function customerAddress(Order $order): string
    {
        foreach (['payment', 'shipping'] as $side) {
            $address = $this->join([
                $order->{$side . '_address_1'}, $order->{$side . '_address_2'},
                $this->join([$order->{$side . '_city'}, $order->{$side . '_zone'}, $order->{$side . '_postcode'}], ' '),
                $order->{$side . '_country'},
            ]);
            if ($address !== '') {
                return $address;
            }
        }

        return '';
    }

    private function storeLabel(Order $order): string
    {
        $channel = trim((string) $order->channel);
        if ($channel === '') {
            return trim((string) $order->store_name);
        }

        $label = app(ChannelRegistry::class)->resolveChannelLabel($channel) ?: ucfirst(strtok($channel, ':'));

        return StoreLabel::of($label, $order->store_name);
    }

    private function logo(?string $path): ?string
    {
        if (! $path || ! Storage::disk('public')->exists($path)) {
            return null;
        }

        $mime = Storage::disk('public')->mimeType($path) ?: 'image/png';

        return 'data:' . $mime . ';base64,' . base64_encode(Storage::disk('public')->get($path));
    }

    private function join(array $parts, string $glue = ', '): string
    {
        return implode($glue, array_filter(array_map(fn ($p) => trim((string) $p), $parts), fn ($p) => $p !== ''));
    }
}
