<?php

namespace App\Actions\Stock;

use App\Services\Catalog\ProductQuantityWriter;
use App\Support\Actor;
use App\Support\Api\Refused;
use Illuminate\Support\Facades\Validator;

final class AdjustStock
{
    public function handle(array $input): array
    {
        $data = Validator::make($input, [
            'product_id' => 'required|integer|min:1',
            'variation_sku' => 'nullable|string|max:64',
            'new_quantity' => 'required_without:change_by|prohibits:change_by|nullable|integer|min:0|max:99999999',
            'change_by' => 'required_without:new_quantity|nullable|integer|min:-99999999|max:99999999',
            'reason' => 'required|string|min:3|max:500',
        ])->validate();

        try {
            $result = app(ProductQuantityWriter::class)->set(
                (int) $data['product_id'],
                $data['variation_sku'] ?? null,
                isset($data['new_quantity']) ? (int) $data['new_quantity'] : null,
                isset($data['change_by']) ? (int) $data['change_by'] : null,
                trim((string) $data['reason']),
                (string) (Actor::current()->name ?? 'An API application')
            );
        } catch (\RuntimeException $e) {
            throw new Refused($e->getMessage());
        }

        return $result + [
            'note' => 'Saved on the catalog product. Marketplace stock changes on the next stock push or sync, not now. '
                . 'Stock that arrived on a purchase order should be received in Purchasing instead, so its unit cost is recorded.',
        ];
    }
}
