<?php

namespace App\Services;

use App\Models\InventoryLedger;
use App\Models\Product;
use App\Models\ProductStock;
use App\Models\PurchaseReturn;
use App\Models\SalesReturn;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class DocumentCorrectionService
{
    public function assertLatest(string $type, int $id, array $productIds): void
    {
        sort($productIds);
        foreach (array_unique($productIds) as $productId) {
            Product::whereKey($productId)->lockForUpdate()->firstOrFail();
            $last = InventoryLedger::where('product_id', $productId)->latest('id')->first();
            if ($last && ($last->reference_type !== $type || (int) $last->reference_id !== $id)) {
                throw ValidationException::withMessages(['document' => ['Later inventory movements exist. Use a correcting return or journal instead of rewriting this document.']]);
            }
        }
    }

    public function deleteReturn(PurchaseReturn|SalesReturn $document): void
    {
        DB::transaction(function () use ($document) {
            $invoice = $document instanceof SalesReturn ? \App\Models\SalesInvoice::whereKey($document->sales_invoice_id)->lockForUpdate()->firstOrFail() : null;
            $document = $document->newQuery()->lockForUpdate()->findOrFail($document->id);
            $purchase = $document instanceof PurchaseReturn;
            $type = $purchase ? 'purchase_return' : 'sales_return';
            $movements = InventoryLedger::where('reference_type', $type)->where('reference_id', $document->id)->get();
            $this->assertLatest($type, $document->id, $movements->pluck('product_id')->all());
            foreach ($movements as $movement) {
                $product = Product::where('company_id', $document->company_id)->findOrFail($movement->product_id);
                $delta = $purchase ? (float) $movement->qty_out : -(float) $movement->qty_in;
                $valueDelta = ($purchase ? 1 : -1) * (float) $movement->total_cost;
                $quantity = round((float) $product->current_stock_in_base_uom + $delta, 6);
                $value = round((float) $product->current_stock_in_base_uom * (float) $product->weighted_avg_cost + $valueDelta, 4);
                if ($quantity < 0 || $value < -0.01) throw ValidationException::withMessages(['document'=>['The reversal would create negative stock or value.']]);
                $average = $quantity > 0 ? round(max(0, $value) / $quantity, 4) : 0;
                $product->update(['current_stock_in_base_uom'=>$quantity, 'weighted_avg_cost'=>$average]);
                $warehouseId = $purchase ? ($document->warehouse_id ?? $document->items->firstWhere('product_id', $product->id)?->warehouse_id ?? $product->warehouse_id) : ($document->salesInvoice?->warehouse_id ?? $product->warehouse_id);
                $stock = ProductStock::where('product_id', $product->id)->where('warehouse_id', $warehouseId)->lockForUpdate()->first();
                if ($stock) $stock->update(['quantity_on_hand'=>(float) $stock->quantity_on_hand+$delta, 'avg_cost'=>$average]);
                InventoryLedger::create(['product_id'=>$product->id,'reference_type'=>$type.'_reversal','reference_id'=>$document->id,'qty_in'=>max(0,$delta),'qty_out'=>max(0,-$delta),'qty_balance'=>$quantity,'unit_cost'=>$movement->unit_cost,'total_cost'=>abs($valueDelta),'new_weighted_avg_cost'=>$average]);
            }
            app(AccountingPostingService::class)->deleteForReference($document->company_id, $document::class, $document->id);
            $document->delete();
            if ($invoice) app(SalesInvoiceService::class)->refreshInvoicePaymentStatus($invoice);
        });
    }
}
