<?php

namespace Tests\Unit;

use App\Models\Product;
use App\Services\ProductOpeningStockService;
use App\Services\ProductService;
use App\Services\StockService;
use Mockery;
use Tests\TestCase;

class ProductDeletionTest extends TestCase
{
    public function test_it_blocks_deleting_products_with_quantity_or_transaction_history(): void
    {
        $service = new ProductService(
            $this->createMock(StockService::class),
            $this->createMock(ProductOpeningStockService::class)
        );

        $product = Mockery::mock(Product::class)->makePartial();
        $product->current_stock_in_base_uom = 3;
        $product->weighted_avg_cost = 100;
        $product->shouldReceive('inventoryLedger')->andReturn(new class {
            public function exists(): bool { return false; }
        });
        $product->shouldReceive('salesOrderItems')->andReturn(new class {
            public function exists(): bool { return false; }
        });
        $product->shouldReceive('salesInvoiceItems')->andReturn(new class {
            public function exists(): bool { return false; }
        });
        $product->shouldReceive('salesReturnItems')->andReturn(new class {
            public function exists(): bool { return false; }
        });
        $product->shouldReceive('purchaseOrderItems')->andReturn(new class {
            public function exists(): bool { return false; }
        });
        $product->shouldReceive('purchaseBillItems')->andReturn(new class {
            public function exists(): bool { return false; }
        });

        $this->assertFalse($service->canBeDeleted($product));
        $this->assertFalse($service->canEditCostingPrice($product));
    }

    public function test_it_allows_deleting_products_without_quantity_or_history(): void
    {
        $service = new ProductService(
            $this->createMock(StockService::class),
            $this->createMock(ProductOpeningStockService::class)
        );

        $product = Mockery::mock(Product::class)->makePartial();
        $product->current_stock_in_base_uom = 0;
        $product->weighted_avg_cost = 0;
        $product->shouldReceive('inventoryLedger')->andReturn(new class {
            public function exists(): bool { return false; }
        });
        $product->shouldReceive('salesOrderItems')->andReturn(new class {
            public function exists(): bool { return false; }
        });
        $product->shouldReceive('salesInvoiceItems')->andReturn(new class {
            public function exists(): bool { return false; }
        });
        $product->shouldReceive('salesReturnItems')->andReturn(new class {
            public function exists(): bool { return false; }
        });
        $product->shouldReceive('purchaseOrderItems')->andReturn(new class {
            public function exists(): bool { return false; }
        });
        $product->shouldReceive('purchaseBillItems')->andReturn(new class {
            public function exists(): bool { return false; }
        });

        $this->assertTrue($service->canBeDeleted($product));
        $this->assertTrue($service->canEditCostingPrice($product));
    }
}
