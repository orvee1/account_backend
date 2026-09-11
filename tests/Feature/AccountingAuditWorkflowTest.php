<?php

namespace Tests\Feature;

use App\Models\ChartAccount;
use App\Models\Company;
use App\Models\CompanyUser;
use App\Models\Customer;
use App\Models\InventoryLedger;
use App\Models\InventoryMovement;
use App\Models\JournalEntry;
use App\Models\Payment;
use App\Models\Product;
use App\Models\ProductStock;
use App\Models\ProductUnit;
use App\Models\ProductUom;
use App\Models\PurchaseBill;
use App\Models\PurchaseReturn;
use App\Models\SalesInvoice;
use App\Models\SalesPayment;
use App\Models\SalesReturn;
use App\Models\StockMovement;
use App\Models\UnitOfMeasure;
use App\Models\User;
use App\Models\Vendor;
use App\Models\Warehouse;
use App\Services\ChartAccountService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AccountingAuditWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;
    private CompanyUser $actor;
    private UnitOfMeasure $piece;
    private Warehouse $warehouse;

    protected function setUp(): void
    {
        parent::setUp();

        User::unguarded(function (): void {
            User::query()->create([
                'id' => 1,
                'name' => 'System User',
                'email' => 'system@example.test',
                'phone_number' => '00000000000',
                'password' => Hash::make('password'),
                'email_verified_at' => now(),
            ]);
        });

        $this->company = Company::query()->create([
            'name' => 'Audit Test Company',
            'status' => 'active',
        ]);

        $this->actor = CompanyUser::query()->create([
            'id' => 1,
            'company_id' => $this->company->id,
            'name' => 'Audit User',
            'email' => 'audit@example.test',
            'phone_number' => '01700000000',
            'password' => Hash::make('password'),
            'role' => 'owner',
            'status' => 'active',
            'is_primary' => true,
        ]);

        Sanctum::actingAs($this->actor);
        Auth::shouldUse('sanctum');

        app(ChartAccountService::class)->seedDefaultForCompany($this->company->id);

        $this->piece = UnitOfMeasure::query()->create([
            'name' => 'Piece',
            'symbol' => 'pcs',
        ]);

        $this->warehouse = Warehouse::query()->create([
            'company_id' => $this->company->id,
            'name' => 'Main Warehouse',
            'is_default' => true,
        ]);

        DB::table('company_settings')->insert([
            'company_id' => $this->company->id,
            'key' => 'is_vat_registered',
            'value' => 'true',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_product_opening_stock_posts_inventory_and_opening_equity(): void
    {
        $response = $this->postJson('/api/products', [
            'product_type' => 'Stock',
            'name' => 'Keyboard',
            'sku' => 'QA-KB-001',
            'costing_price' => 500,
            'sales_price' => 750,
            'opening_quantity' => 10,
            'warehouse_id' => $this->warehouse->id,
            'product_uoms' => [
                [
                    'uom_id' => $this->piece->id,
                    'name' => 'Piece',
                    'symbol' => 'pcs',
                    'conversion_factor' => 1,
                    'sale_price' => 750,
                    'is_base_uom' => true,
                    'is_default_sale_uom' => true,
                ],
            ],
        ]);

        $response->assertCreated();

        $product = Product::query()->where('sku', 'QA-KB-001')->firstOrFail();
        $this->assertMoneyEquals(10, $product->current_stock_in_base_uom);
        $this->assertMoneyEquals(500, $product->weighted_avg_cost);

        $stock = ProductStock::query()
            ->where('product_id', $product->id)
            ->where('warehouse_id', $this->warehouse->id)
            ->firstOrFail();
        $this->assertMoneyEquals(10, $stock->quantity_on_hand);
        $this->assertMoneyEquals(500, $stock->avg_cost);

        $movement = StockMovement::query()->where('product_id', $product->id)->firstOrFail();
        $this->assertSame('opening', $movement->movement_type);
        $this->assertMoneyEquals(10, $movement->qty_in);
        $this->assertMoneyEquals(5000, $movement->total_cost);

        $journal = $this->journalFor(Product::class, $product->id);
        $this->assertBalanced($journal);
        $this->assertJournalLine($journal, '1.1.5.1.1', 5000, 0);
        $this->assertJournalLineBySlug($journal, 'opening-balances', 0, 5000);
    }

    public function test_customer_and_supplier_opening_balances_post_expected_ar_and_ap_impacts(): void
    {
        $customerResponse = $this->postJson('/api/customers', [
            'name' => 'Audit Customer',
            'customer_number' => 'C-AUD-001',
            'opening_balance' => 12000,
            'opening_balance_type' => 'debit',
            'opening_balance_date' => '2026-01-01',
        ]);
        $customerResponse->assertCreated();

        $customer = Customer::query()->where('customer_number', 'C-AUD-001')->firstOrFail();
        $customerJournal = $this->journalFor(Customer::class, $customer->id);
        $this->assertBalanced($customerJournal);
        $this->assertJournalLine($customerJournal, ChartAccount::findOrFail($customer->refresh()->chart_account_id)->code, 12000, 0);
        $this->assertJournalLineBySlug($customerJournal, 'opening-balances', 0, 12000);

        $vendorResponse = $this->postJson('/api/vendors', [
            'name' => 'Audit Supplier',
            'vendor_number' => 'V-AUD-001',
            'opening_balance' => 8000,
            'opening_balance_type' => 'credit',
            'opening_balance_date' => '2026-01-01',
        ]);
        $vendorResponse->assertCreated();

        $vendor = Vendor::query()->where('vendor_number', 'V-AUD-001')->firstOrFail();
        $vendorAccount = ChartAccount::query()->findOrFail($vendor->chart_account_id);
        $vendorJournal = $this->journalFor(Vendor::class, $vendor->id);
        $this->assertBalanced($vendorJournal);
        $this->assertJournalLineBySlug($vendorJournal, 'opening-balances', 8000, 0);
        $this->assertJournalLine($vendorJournal, $vendorAccount->code, 0, 8000);
    }

    public function test_credit_purchase_posts_inventory_ap_and_weighted_average_cost(): void
    {
        $vendor = $this->createVendor('WAC Supplier');
        [$product, $uom] = $this->createStockProduct('Laptop', 'QA-LAP-001');

        $firstBill = $this->postPurchaseBill('PB-WAC-001', $vendor, $product, $uom, 10, 50000);
        $firstBill->assertCreated();

        $product->refresh();
        $this->assertMoneyEquals(10, $product->current_stock_in_base_uom);
        $this->assertMoneyEquals(50000, $product->weighted_avg_cost);

        $firstJournal = $this->journalFor(PurchaseBill::class, (int) $firstBill->json('id'));
        $this->assertBalanced($firstJournal);
        $this->assertJournalLine($firstJournal, '1.1.5.1.1', 500000, 0);
        $this->assertJournalLine($firstJournal, ChartAccount::findOrFail($vendor->refresh()->chart_account_id)->code, 0, 500000);

        $secondBill = $this->postPurchaseBill('PB-WAC-002', $vendor, $product, $uom, 5, 56000);
        $secondBill->assertCreated();

        $product->refresh();
        $this->assertMoneyEquals(15, $product->current_stock_in_base_uom);
        $this->assertMoneyEquals(52000, $product->weighted_avg_cost);

        $ledgerRows = InventoryLedger::query()
            ->where('product_id', $product->id)
            ->where('reference_type', 'purchase')
            ->orderBy('id')
            ->get();

        $this->assertCount(2, $ledgerRows);
        $this->assertMoneyEquals(10, $ledgerRows[0]->qty_in);
        $this->assertMoneyEquals(50000, $ledgerRows[0]->unit_cost);
        $this->assertMoneyEquals(50000, $ledgerRows[0]->new_weighted_avg_cost);
        $this->assertMoneyEquals(5, $ledgerRows[1]->qty_in);
        $this->assertMoneyEquals(56000, $ledgerRows[1]->unit_cost);
        $this->assertMoneyEquals(52000, $ledgerRows[1]->new_weighted_avg_cost);
    }

    public function test_draft_purchase_bill_does_not_move_inventory_or_post_journal(): void
    {
        $vendor = $this->createVendor('Draft Supplier');
        [$product, $uom] = $this->createStockProduct('Draft Laptop', 'QA-DRAFT-001');

        $response = $this->postJson('/api/purchase-bills', [
            'vendor_id' => $vendor->id,
            'bill_no' => 'PB-DRAFT-001',
            'bill_date' => '2026-01-05',
            'due_date' => '2026-02-04',
            'vat_mode' => 'exclusive',
            'status' => 'draft',
            'items' => [
                [
                    'product_id' => $product->id,
                    'purchase_uom_id' => $uom->id,
                    'price_uom_id' => $uom->id,
                    'quantity' => 4,
                    'unit_price' => 50000,
                    'trade_discount_pct' => 0,
                    'line_discount_pct' => 0,
                    'line_discount_amt' => 0,
                    'vat_rate' => 0,
                    'ait_rate' => 0,
                ],
            ],
        ]);

        $response->assertCreated();
        $this->assertSame('draft', $response->json('status'));

        $product->refresh();
        $this->assertMoneyEquals(0, $product->current_stock_in_base_uom);
        $this->assertMoneyEquals(0, $product->weighted_avg_cost);

        $bill = PurchaseBill::query()->where('bill_no', 'PB-DRAFT-001')->firstOrFail();
        $this->assertFalse(
            JournalEntry::query()
                ->where('reference_type', PurchaseBill::class)
                ->where('reference_id', $bill->id)
                ->exists(),
            'Draft purchase bills must not post journal entries.'
        );
        $this->assertFalse(
            InventoryLedger::query()
                ->where('reference_type', 'purchase')
                ->where('reference_id', $bill->id)
                ->exists(),
            'Draft purchase bills must not create inventory ledger movements.'
        );
    }

    public function test_purchase_return_reduces_inventory_and_posts_reversal_journal(): void
    {
        $vendor = $this->createVendor('Return Supplier');
        [$product, $uom] = $this->createStockProduct('Return Laptop', 'QA-PRET-001');
        $this->postPurchaseBill('PB-PRET-001', $vendor, $product, $uom, 10, 50000)->assertCreated();
        $legacyUnit = $this->createLegacyProductUnit($product);

        $response = $this->postJson('/api/purchase-returns', [
            'vendor_id' => $vendor->id,
            'return_no' => 'PR-AUD-001',
            'return_date' => '2026-01-09',
            'warehouse_id' => $this->warehouse->id,
            'items' => [
                [
                    'product_id' => $product->id,
                    'qty_unit_id' => $legacyUnit->id,
                    'qty' => 3,
                    'rate_unit_id' => $legacyUnit->id,
                    'rate_per_unit' => 50000,
                    'discount_percent' => 0,
                    'discount_amount' => 0,
                    'warehouse_id' => $this->warehouse->id,
                ],
            ],
        ]);

        $response->assertCreated();

        $product->refresh();
        $this->assertMoneyEquals(7, $product->current_stock_in_base_uom);

        $return = PurchaseReturn::query()->where('return_no', 'PR-AUD-001')->firstOrFail();
        $inventoryMovement = InventoryMovement::query()
            ->where('document_type', 'purchase_return')
            ->where('document_id', $return->id)
            ->firstOrFail();
        $this->assertMoneyEquals(-3, $inventoryMovement->quantity_base);

        $journal = $this->journalFor(PurchaseReturn::class, $return->id);
        $this->assertBalanced($journal);
        $this->assertJournalLine($journal, ChartAccount::findOrFail($vendor->refresh()->chart_account_id)->code, 150000, 0);
        $this->assertJournalLine($journal, '1.1.5.1.1', 0, 150000);
    }

    public function test_credit_sale_posts_revenue_cogs_inventory_and_receivable(): void
    {
        $customer = $this->createCustomer('Sales Customer');
        [$product, $uom] = $this->createStockProduct('Laptop', 'QA-SALE-001', 15, 52000);

        $response = $this->postSalesInvoice('SI-AUD-001', $customer, $product, $uom, 2, 70000);
        $response->assertCreated();

        $invoice = SalesInvoice::query()->where('invoice_no', 'SI-AUD-001')->firstOrFail();
        $this->assertMoneyEquals(140000, $invoice->total_amount);
        $this->assertMoneyEquals(0, $invoice->paid_amount);
        $this->assertSame('sent', $invoice->status);

        $product->refresh();
        $this->assertMoneyEquals(13, $product->current_stock_in_base_uom);

        $ledger = InventoryLedger::query()
            ->where('product_id', $product->id)
            ->where('reference_type', 'sale')
            ->firstOrFail();
        $this->assertMoneyEquals(2, $ledger->qty_out);
        $this->assertMoneyEquals(13, $ledger->qty_balance);
        $this->assertMoneyEquals(52000, $ledger->unit_cost);
        $this->assertMoneyEquals(104000, $ledger->total_cost);

        $journal = $this->journalFor(SalesInvoice::class, $invoice->id);
        $this->assertBalanced($journal);
        $this->assertJournalLine($journal, ChartAccount::findOrFail($customer->refresh()->chart_account_id)->code, 140000, 0);
        $this->assertJournalLine($journal, '4.1.1.1', 0, 140000);
        $this->assertJournalLine($journal, '5.1.1.1', 104000, 0);
        $this->assertJournalLine($journal, '1.1.5.1.1', 0, 104000);
    }

    public function test_sales_return_restores_inventory_and_posts_reversal_journal(): void
    {
        $customer = $this->createCustomer('Return Customer');
        [$product, $uom] = $this->createStockProduct('Return Sale Laptop', 'QA-SRET-001', 15, 52000);
        $this->postSalesInvoice('SI-SRET-001', $customer, $product, $uom, 2, 70000)->assertCreated();

        $invoice = SalesInvoice::query()
            ->with('items')
            ->where('invoice_no', 'SI-SRET-001')
            ->firstOrFail();
        $item = $invoice->items->first();

        $response = $this->postJson("/api/sales-invoices/{$invoice->id}/create-return", [
            'return_no' => 'SR-AUD-001',
            'return_date' => '2026-01-10',
            'reason' => 'Customer return',
            'items' => [
                [
                    'sales_invoice_item_id' => $item->id,
                    'product_id' => $product->id,
                    'quantity' => 1,
                    'unit_price' => 70000,
                    'discount_amount' => 0,
                    'tax_amount' => 0,
                ],
            ],
        ]);

        $response->assertCreated();

        $product->refresh();
        $this->assertMoneyEquals(14, $product->current_stock_in_base_uom);

        $return = SalesReturn::query()->where('return_no', 'SR-AUD-001')->firstOrFail();
        $journal = $this->journalFor(SalesReturn::class, $return->id);
        $this->assertBalanced($journal);
        $this->assertJournalLine($journal, '4.1.1.1', 70000, 0);
        $this->assertJournalLine($journal, ChartAccount::findOrFail($customer->refresh()->chart_account_id)->code, 0, 70000);
        $this->assertJournalLine($journal, '1.1.5.1.1', 52000, 0);
        $this->assertJournalLine($journal, '5.1.1.1', 0, 52000);
    }

    public function test_invoice_payment_route_posts_cash_collection_and_updates_invoice_status(): void
    {
        $customer = $this->createCustomer('Payment Customer');
        [$product, $uom] = $this->createStockProduct('Payment Laptop', 'QA-PAY-001', 15, 52000);
        $invoiceResponse = $this->postSalesInvoice('SI-PAY-001', $customer, $product, $uom, 2, 70000);
        $invoiceResponse->assertCreated();

        $invoice = SalesInvoice::query()->where('invoice_no', 'SI-PAY-001')->firstOrFail();

        $paymentResponse = $this->postJson("/api/sales-invoices/{$invoice->id}/record-payment", [
            'payment_no' => 'SP-AUD-001',
            'payment_date' => '2026-01-10',
            'amount' => 50000,
            'payment_method' => 'cash',
        ]);
        $paymentResponse->assertCreated();

        $invoice->refresh();
        $this->assertMoneyEquals(50000, $invoice->paid_amount);
        $this->assertSame('partially_paid', $invoice->status);

        $payment = SalesPayment::query()->where('payment_no', 'SP-AUD-001')->firstOrFail();
        $journal = $this->journalFor(SalesPayment::class, $payment->id);
        $this->assertBalanced($journal);
        $this->assertJournalLine($journal, '1.1.1.1', 50000, 0);
        $this->assertJournalLine($journal, ChartAccount::findOrFail($customer->refresh()->chart_account_id)->code, 0, 50000);
    }

    public function test_bank_collection_uses_a_postable_bank_account_from_default_coa(): void
    {
        $customer = $this->createCustomer('Bank Payment Customer');
        [$product, $uom] = $this->createStockProduct('Bank Payment Laptop', 'QA-BPAY-001', 15, 52000);
        $this->postSalesInvoice('SI-BPAY-001', $customer, $product, $uom, 2, 70000)->assertCreated();

        $invoice = SalesInvoice::query()->where('invoice_no', 'SI-BPAY-001')->firstOrFail();

        $paymentResponse = $this->postJson("/api/sales-invoices/{$invoice->id}/record-payment", [
            'payment_no' => 'SP-BANK-001',
            'payment_date' => '2026-01-10',
            'amount' => 50000,
            'payment_method' => 'bank transfer',
        ]);
        $paymentResponse->assertCreated();

        $payment = SalesPayment::query()->where('payment_no', 'SP-BANK-001')->firstOrFail();
        $journal = $this->journalFor(SalesPayment::class, $payment->id);
        $this->assertBalanced($journal);
        $this->assertJournalLineByName($journal, 'Cash at Bank', 50000, 0);
        $this->assertJournalLine($journal, ChartAccount::findOrFail($customer->refresh()->chart_account_id)->code, 0, 50000);
    }

    public function test_supplier_payment_posts_payable_debit_and_cash_credit(): void
    {
        $vendor = $this->createVendor('Payment Supplier');

        $response = $this->postJson('/api/payments', [
            'vendor_id' => $vendor->id,
            'payment_number' => 'VP-AUD-001',
            'payment_date' => '2026-01-11',
            'amount_paid' => 10000,
            'payment_mode' => 'cash',
            'description' => 'Supplier settlement',
        ]);
        $response->assertCreated();

        $payment = Payment::query()->where('payment_number', 'VP-AUD-001')->firstOrFail();
        $journal = $this->journalFor(Payment::class, $payment->id);
        $this->assertBalanced($journal);
        $this->assertJournalLine($journal, ChartAccount::findOrFail($vendor->refresh()->chart_account_id)->code, 10000, 0);
        $this->assertJournalLine($journal, '1.1.1.1', 0, 10000);
    }

    public function test_financial_statements_include_current_profit_in_the_balance_sheet_equation(): void
    {
        $vendor = $this->createVendor('Report Supplier');
        $customer = $this->createCustomer('Report Customer');
        [$product, $uom] = $this->createStockProduct('Report Laptop', 'QA-REP-001');

        $this->postPurchaseBill('PB-REP-001', $vendor, $product, $uom, 10, 50000)->assertCreated();
        $this->postSalesInvoice('SI-REP-001', $customer, $product, $uom, 2, 70000)->assertCreated();

        $incomeResponse = $this->getJson('/api/reports/income-statement?start_date=2026-01-01&end_date=2026-01-31');
        $incomeResponse->assertOk();
        $income = $incomeResponse->json();
        $this->assertMoneyEquals(140000, $income['revenue']['salesRevenue']);
        $this->assertMoneyEquals(100000, $income['costOfGoods']['costOfSales']);

        $balanceResponse = $this->getJson('/api/reports/balance-sheet?as_of_date=2026-01-31');
        $balanceResponse->assertOk();
        $balanceSheet = $balanceResponse->json();

        $assets = $this->sumNested($balanceSheet['assets']);
        $liabilities = $this->sumNested($balanceSheet['liabilities']);
        $equity = $this->sumNested($balanceSheet['equity']);

        $this->assertMoneyEquals($assets, $liabilities + $equity);
    }

    public function test_direct_sales_payment_endpoint_posts_collection_journal(): void
    {
        $customer = $this->createCustomer('Direct Payment Customer');
        [$product, $uom] = $this->createStockProduct('Direct Payment Laptop', 'QA-DPAY-001', 15, 52000);
        $this->postSalesInvoice('SI-DPAY-001', $customer, $product, $uom, 2, 70000)->assertCreated();

        $invoice = SalesInvoice::query()->where('invoice_no', 'SI-DPAY-001')->firstOrFail();

        $paymentResponse = $this->postJson('/api/sales-payments', [
            'sales_invoice_id' => $invoice->id,
            'payment_no' => 'SP-DIRECT-001',
            'payment_date' => '2026-01-12',
            'amount' => 50000,
            'payment_method' => 'cash',
        ]);
        $paymentResponse->assertCreated();

        $payment = SalesPayment::query()->where('payment_no', 'SP-DIRECT-001')->firstOrFail();
        $journal = $this->journalFor(SalesPayment::class, $payment->id);
        $this->assertBalanced($journal);
        $this->assertJournalLine($journal, '1.1.1.1', 50000, 0);
        $this->assertJournalLine($journal, ChartAccount::findOrFail($customer->refresh()->chart_account_id)->code, 0, 50000);
    }

    private function postPurchaseBill(
        string $billNo,
        Vendor $vendor,
        Product $product,
        ProductUom $uom,
        float $quantity,
        float $unitPrice
    ) {
        return $this->postJson('/api/purchase-bills', [
            'vendor_id' => $vendor->id,
            'bill_no' => $billNo,
            'bill_date' => '2026-01-05',
            'due_date' => '2026-02-04',
            'vat_mode' => 'exclusive',
            'items' => [
                [
                    'product_id' => $product->id,
                    'purchase_uom_id' => $uom->id,
                    'price_uom_id' => $uom->id,
                    'quantity' => $quantity,
                    'unit_price' => $unitPrice,
                    'trade_discount_pct' => 0,
                    'line_discount_pct' => 0,
                    'line_discount_amt' => 0,
                    'vat_rate' => 0,
                    'ait_rate' => 0,
                ],
            ],
        ]);
    }

    private function postSalesInvoice(
        string $invoiceNo,
        Customer $customer,
        Product $product,
        ProductUom $uom,
        float $quantity,
        float $unitPrice
    ) {
        return $this->postJson('/api/sales-invoices', [
            'customer_id' => $customer->id,
            'invoice_no' => $invoiceNo,
            'invoice_date' => '2026-01-07',
            'due_date' => '2026-02-06',
            'warehouse_id' => $this->warehouse->id,
            'vat_mode' => 'exclusive',
            'items' => [
                [
                    'product_id' => $product->id,
                    'sale_uom_id' => $uom->id,
                    'price_uom_id' => $uom->id,
                    'quantity' => $quantity,
                    'unit_price' => $unitPrice,
                    'trade_discount_pct' => 0,
                    'line_discount_pct' => 0,
                    'line_discount_amt' => 0,
                    'vat_rate' => 0,
                    'ait_rate' => 0,
                ],
            ],
        ]);
    }

    private function createStockProduct(
        string $name,
        string $sku,
        float $stock = 0,
        float $weightedAvgCost = 0
    ): array {
        $product = Product::query()->create([
            'company_id' => $this->company->id,
            'product_type' => 'Stock',
            'name' => $name,
            'sku' => $sku,
            'warehouse_id' => $this->warehouse->id,
            'unit' => 'pcs',
            'costing_price' => $weightedAvgCost,
            'sales_price' => 70000,
            'current_stock_in_base_uom' => $stock,
            'weighted_avg_cost' => $weightedAvgCost,
            'status' => 'active',
            'created_by' => $this->actor->id,
        ]);

        $uom = ProductUom::query()->create([
            'product_id' => $product->id,
            'uom_id' => $this->piece->id,
            'conversion_factor' => 1,
            'sale_price' => 70000,
            'is_base_uom' => true,
            'is_default_sale_uom' => true,
        ]);

        return [$product, $uom];
    }

    private function createLegacyProductUnit(Product $product): ProductUnit
    {
        return ProductUnit::query()->create([
            'product_id' => $product->id,
            'name' => 'Piece',
            'factor' => 1,
            'is_base' => true,
        ]);
    }

    private function createVendor(string $name): Vendor
    {
        return Vendor::query()->create([
            'company_id' => $this->company->id,
            'name' => $name,
            'display_name' => $name,
            'vendor_number' => 'V-' . substr(md5($name), 0, 8),
            'created_by' => $this->actor->id,
            'updated_by' => $this->actor->id,
        ]);
    }

    private function createCustomer(string $name): Customer
    {
        return Customer::query()->create([
            'company_id' => $this->company->id,
            'name' => $name,
            'display_name' => $name,
            'customer_number' => 'C-' . substr(md5($name), 0, 8),
            'created_by' => $this->actor->id,
            'updated_by' => $this->actor->id,
        ]);
    }

    private function journalFor(string $referenceType, int $referenceId): JournalEntry
    {
        return JournalEntry::query()
            ->with('lines.account')
            ->where('company_id', $this->company->id)
            ->where('reference_type', $referenceType)
            ->where('reference_id', $referenceId)
            ->firstOrFail();
    }

    private function assertJournalLine(JournalEntry $journal, string $accountCode, float $debit, float $credit): void
    {
        $line = $journal->lines->first(
            fn ($item) => $item->account && $item->account->code === $accountCode
        );

        $this->assertNotNull($line, "Missing journal line for account code {$accountCode}.");
        $this->assertMoneyEquals($debit, $line->debit, "Debit mismatch for account {$accountCode}.");
        $this->assertMoneyEquals($credit, $line->credit, "Credit mismatch for account {$accountCode}.");
    }

    private function assertJournalLineBySlug(JournalEntry $journal, string $slug, float $debit, float $credit): void
    {
        $line = $journal->lines->first(
            fn ($item) => $item->account && $item->account->slug === $slug
        );

        $this->assertNotNull($line, "Missing journal line for account slug {$slug}.");
        $this->assertMoneyEquals($debit, $line->debit, "Debit mismatch for account {$slug}.");
        $this->assertMoneyEquals($credit, $line->credit, "Credit mismatch for account {$slug}.");
    }

    private function assertJournalLineByName(JournalEntry $journal, string $name, float $debit, float $credit): void
    {
        $line = $journal->lines->first(
            fn ($item) => $item->account && $item->account->name === $name
        );

        $this->assertNotNull($line, "Missing journal line for account name {$name}.");
        $this->assertMoneyEquals($debit, $line->debit, "Debit mismatch for account {$name}.");
        $this->assertMoneyEquals($credit, $line->credit, "Credit mismatch for account {$name}.");
    }

    private function assertBalanced(JournalEntry $journal): void
    {
        $this->assertMoneyEquals(
            (float) $journal->lines->sum('debit'),
            (float) $journal->lines->sum('credit'),
            "Journal entry {$journal->id} is not balanced."
        );
    }

    private function assertMoneyEquals(float $expected, mixed $actual, string $message = ''): void
    {
        $this->assertEqualsWithDelta($expected, (float) $actual, 0.01, $message);
    }

    private function sumNested(array $values): float
    {
        $sum = 0.0;

        foreach ($values as $value) {
            $sum += is_array($value) ? $this->sumNested($value) : (float) $value;
        }

        return $sum;
    }
}
