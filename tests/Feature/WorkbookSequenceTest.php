<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\CompanyUser;
use App\Models\Customer;
use App\Models\InventoryLedger;
use App\Models\JournalEntry;
use App\Models\Payment;
use App\Models\Product;
use App\Models\ProductUnit;
use App\Models\ProductUom;
use App\Models\SalesInvoice;
use App\Models\UnitOfMeasure;
use App\Models\Vendor;
use App\Models\Warehouse;
use App\Services\ChartAccountService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class WorkbookSequenceTest extends TestCase
{
    use DatabaseTransactions;

    private Company $company;

    private CompanyUser $actor;

    private UnitOfMeasure $piece;

    private Warehouse $warehouse;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame('account_api_testing', DB::connection()->getDatabaseName());
        $this->travelTo(\Carbon\Carbon::parse('2026-01-01 12:00:00'));
        $this->company = Company::query()->create(['name' => 'Audit Test Company', 'status' => 'active']);
        $this->actor = CompanyUser::query()->create(['company_id' => $this->company->id, 'name' => 'Audit User', 'email' => 'workbook-'.uniqid().'@example.test', 'phone_number' => '01700000000', 'password' => Hash::make('password'), 'role' => 'owner', 'status' => 'active', 'is_primary' => true]);
        Sanctum::actingAs($this->actor);
        Auth::shouldUse('sanctum');
        app(ChartAccountService::class)->seedDefaultForCompany($this->company->id);
        $this->piece = UnitOfMeasure::query()->create(['name' => 'Piece', 'symbol' => 'pcs']);
        $this->warehouse = Warehouse::query()->create(['company_id' => $this->company->id, 'name' => 'Main Warehouse', 'is_default' => true]);
        DB::table('company_settings')->insert(['company_id' => $this->company->id, 'key' => 'is_vat_registered', 'value' => 'true', 'created_at' => now(), 'updated_at' => now()]);
    }

    public function test_receipts_and_payments_replace_and_remove_their_journals(): void
    {
        $cases = [['customers', 'customer', 'receipts', 'receipt', 'amount_received'], ['vendors', 'vendor', 'payments', 'payment', 'amount_paid']];
        foreach ($cases as [$parties, $party, $documents, $document, $amountField]) {
            $this->postJson('/api/'.$parties, ['name' => 'Lifecycle '.$party, $party.'_number' => 'LIFE-'.$party])->assertCreated();
            $model = $party === 'customer' ? Customer::class : Vendor::class;
            $record = $model::query()->where('name', 'Lifecycle '.$party)->firstOrFail();
            $payload = [$party.'_id' => $record->id, $document.'_number' => 'LIFE-'.$document, $document.'_date' => '2026-01-20', $amountField => 100, 'payment_mode' => 'cash', 'status' => 'completed'];
            $created = $this->postJson('/api/'.$documents, $payload)->assertCreated();
            $this->postJson('/api/'.$documents, $payload)->assertUnprocessable();
            $id = (int) $created->json('id');
            $reference = $document === 'receipt' ? \App\Models\Receipt::class : Payment::class;
            $journal = fn () => JournalEntry::query()->with('lines')->where('company_id', $this->company->id)->where('reference_type', $reference)->where('reference_id', $id);
            $this->assertEquals(100, $journal()->firstOrFail()->lines->sum('debit'));
            $payload[$amountField] = 150;
            $payload['payment_mode'] = 'bank';
            $this->putJson('/api/'.$documents.'/'.$id, $payload)->assertOk();
            $this->assertSame(1, $journal()->count());
            $entry = $journal()->firstOrFail();
            $this->assertEquals(150, $entry->lines->sum('debit'));
            $this->assertEquals(150, $entry->lines->sum('credit'));
            $partyLine = $entry->lines->firstWhere('account_id', $record->refresh()->chart_account_id);
            $this->assertNotNull($partyLine);
            $this->assertEquals(150, $document === 'receipt' ? $partyLine->credit : $partyLine->debit);
            $payload['status'] = 'draft';
            $this->putJson('/api/'.$documents.'/'.$id, $payload)->assertOk();
            $this->assertSame(0, $journal()->count());
            $payload['status'] = 'completed';
            $this->putJson('/api/'.$documents.'/'.$id, $payload)->assertOk();
            $this->assertSame(1, $journal()->count());
            $this->deleteJson('/api/'.$documents.'/'.$id)->assertOk();
            $this->assertSame(0, $journal()->count());
        }
    }

    public function test_legacy_units_work_and_failed_product_creation_rolls_back(): void
    {
        $payload = ['product_type' => 'Stock', 'name' => 'Unit Compatibility', 'sku' => 'UNIT-COMPAT', 'costing_price' => 500, 'sales_price' => 750, 'opening_quantity' => 10, 'opening_unit_cost' => 500, 'opening_date' => '2026-01-01', 'warehouse_id' => $this->warehouse->id, 'units' => [['name' => 'Piece', 'factor' => 1, 'is_base' => true]]];
        $response = $this->postJson('/api/products', $payload)->assertCreated();
        $this->assertCount(1, $response->json('units'));
        $product = Product::query()->where('sku', 'UNIT-COMPAT')->firstOrFail();
        $this->assertEquals(10, $product->current_stock_in_base_uom);
        $payload['sku'] = 'UNIT-ROLLBACK';
        $payload['units'][] = ['name' => 'Invalid', 'factor' => null, 'is_base' => false];
        try {
            app(\App\Services\ProductService::class)->create($payload);
            $this->fail('Expected a unit constraint failure.');
        } catch (\Illuminate\Database\QueryException $exception) {
            $this->assertFalse(Product::query()->where('sku', 'UNIT-ROLLBACK')->exists());
        }
    }

    public function test_settlements_reject_another_companys_party(): void
    {
        $other = Company::query()->create(['name' => 'Other Company', 'status' => 'active']);
        $vendor = Vendor::query()->create(['company_id' => $other->id, 'name' => 'Other Vendor']);
        $customer = Customer::query()->create(['company_id' => $other->id, 'name' => 'Other Customer']);
        $this->postJson('/api/payments', ['vendor_id' => $vendor->id, 'payment_date' => '2026-01-20', 'amount_paid' => 10, 'payment_mode' => 'cash'])->assertUnprocessable();
        $this->postJson('/api/receipts', ['customer_id' => $customer->id, 'receipt_date' => '2026-01-20', 'amount_received' => 10, 'payment_mode' => 'cash'])->assertUnprocessable();
    }

    public function test_workbook_sequence(): void
    {
        $this->assertSame('account_api_testing', DB::connection()->getDatabaseName());
        $results = [];
        $products = [];
        $vendors = [];
        $customers = [];
        $invoices = [];
        $expected = [1 => [0, 0, 5000, 0, 0, 0, 5000, 5000], 2 => [0, 0, 5000, 0, 20000, 0, -15000, 5000], 3 => [0, 0, 5000, 15000, 20000, 0, 0, 20000], 4 => [10, 50000, 505000, 15000, 520000, 0, 0, 520000], 5 => [10, 50000, 505000, 15000, 520000, 0, 0, 520000], 6 => [15, 52000, 785000, 15000, 800000, 0, 0, 800000], 7 => [15, 52000, 785000, 15000, 800000, 0, 0, 800000], 8 => [7, 52000, 369000, 535000, 800000, 104000, 104000, 904000], 9 => [7, 52000, 369000, 535000, 800000, 104000, 104000, 904000], 10 => [12, 51166.666667, 619000, 535000, 1050000, 104000, 104000, 1154000], 11 => [12, 51166.666667, 619000, 535000, 1050000, 104000, 104000, 1154000], 12 => [10, 51400, 519000, 535000, 950000, 104000, 104000, 1054000], 13 => [10, 51400, 519000, 535000, 950000, 104000, 104000, 1054000], 14 => [6, 51400, 313400, 775000, 950000, 138400, 138400, 1088400], 15 => [6, 51400, 313400, 775000, 950000, 138400, 138400, 1088400], 16 => [10, 54040, 545400, 775000, 1182000, 138400, 138400, 1320400], 17 => [10, 54040, 545400, 775000, 1182000, 138400, 138400, 1320400], 18 => [11, 53800, 596800, 715000, 1182000, 129800, 129800, 1311800], 19 => [11, 53800, 596800, 515000, 1032000, 129800, 129800, 1161800], 20 => [11, 53800, 596800, 505000, 1017000, 129800, 129800, 1146800]];
        try {
            for ($step = 1;
                $step <= 20;
                $step++) {
                $row = ['step' => $step, 'responses' => [], 'checks' => []];
                $post = function (string $url, array $payload) use (&$row) {
                    $response = $this->postJson($url, $payload);
                    $row['responses'][] = ['url' => $url, 'input' => $payload, 'status' => $response->status(), 'body' => $response->json()];
                    $row['checks'][] = ['name' => 'HTTP '.$url, 'expected' => 201, 'actual' => $response->status(), 'pass' => $response->status() === 201];

                    return $response;
                };
                try {
                    if ($step === 1) {
                        foreach ([['Laptop', 0, 0], ['Keyboard', 10, 500]] as [$name, $qty, $cost]) {
                            $post('/api/products', ['product_type' => 'Stock', 'name' => $name, 'sku' => 'WB-'.$name, 'costing_price' => $cost, 'sales_price' => 65000, 'opening_quantity' => $qty, 'opening_unit_cost' => $qty > 0 ? $cost : null, 'opening_date' => $qty > 0 ? '2026-01-01' : null, 'warehouse_id' => $this->warehouse->id, 'product_uoms' => [['uom_id' => $this->piece->id, 'name' => 'Piece', 'symbol' => 'pcs', 'conversion_factor' => 1, 'sale_price' => 65000, 'is_base_uom' => true, 'is_default_sale_uom' => true]]]);
                            $products[$name] = Product::query()->where('sku', 'WB-'.$name)->firstOrFail();
                        }
                    }
                    if ($step === 2 || $step === 3) {
                        $supplier = $step === 2;
                        $parties = $supplier ? [['Tech Supplier Ltd', 0], ['Global Enterprise', 20000]] : [['Rahman Trading', 0], ['Standard Store', 15000]];
                        foreach ($parties as [$name, $balance]) {
                            $number = 'WB-'.str_replace(' ', '-', $name);
                            $post($supplier ? '/api/vendors' : '/api/customers', ['name' => $name, ($supplier ? 'vendor_number' : 'customer_number') => $number, 'opening_balance' => $balance, 'opening_balance_type' => $supplier ? 'credit' : 'debit', 'opening_balance_date' => '2026-01-01']);
                            if ($supplier) {
                                $vendors[$name] = Vendor::query()->where('vendor_number', $number)->firstOrFail();
                            } else {
                                $customers[$name] = Customer::query()->where('customer_number', $number)->firstOrFail();
                            }
                        }
                    }
                    if (in_array($step, [4, 6, 10, 16, 8, 14], true)) {
                        $purchase = in_array($step, [4, 6, 10, 16], true);
                        [$qty, $rate] = [4 => [10, 50000], 6 => [5, 56000], 10 => [5, 50000], 16 => [4, 58000], 8 => [8, 65000], 14 => [4, 60000]][$step];
                        $product = $products['Laptop'];
                        $uom = ProductUom::query()->where('product_id', $product->id)->firstOrFail();
                        $payload = [($purchase ? 'vendor_id' : 'customer_id') => $purchase ? $vendors['Tech Supplier Ltd']->id : $customers['Rahman Trading']->id, ($purchase ? 'bill_no' : 'invoice_no') => 'WB-'.$step, ($purchase ? 'bill_date' : 'invoice_date') => sprintf('2026-01-%02d', $step), 'due_date' => '2026-02-28', 'warehouse_id' => $this->warehouse->id, 'vat_mode' => 'exclusive', 'items' => [['product_id' => $product->id, ($purchase ? 'purchase_uom_id' : 'sale_uom_id') => $uom->id, 'price_uom_id' => $uom->id, 'quantity' => $qty, 'unit_price' => $rate, 'trade_discount_pct' => 0, 'line_discount_pct' => 0, 'line_discount_amt' => 0, 'vat_rate' => 0, 'ait_rate' => 0]]];
                        $post($purchase ? '/api/purchase-bills' : '/api/sales-invoices', $payload);
                        if (! $purchase) {
                            $invoices[$step] = SalesInvoice::query()->with('items')->where('invoice_no', 'WB-'.$step)->firstOrFail();
                        }
                    }
                    if ($step === 12) {
                        $product = $products['Laptop'];
                        $unit = ProductUnit::query()->where('product_id', $product->id)->where('is_base', true)->firstOrFail();
                        $post('/api/purchase-returns', ['vendor_id' => $vendors['Tech Supplier Ltd']->id, 'return_no' => 'WB-PR', 'return_date' => '2026-01-12', 'warehouse_id' => $this->warehouse->id, 'items' => [['product_id' => $product->id, 'qty_unit_id' => $unit->id, 'qty' => 2, 'rate_unit_id' => $unit->id, 'rate_per_unit' => 50000, 'discount_percent' => 0, 'discount_amount' => 0, 'warehouse_id' => $this->warehouse->id]]]);
                    }
                    if ($step === 18) {
                        $invoice = $invoices[14];
                        $post('/api/sales-invoices/'.$invoice->id.'/create-return', ['return_no' => 'WB-SR', 'return_date' => '2026-01-18', 'reason' => 'Workbook return', 'items' => [['sales_invoice_item_id' => $invoice->items->first()->id, 'product_id' => $products['Laptop']->id, 'quantity' => 1, 'unit_price' => 60000, 'discount_amount' => 0, 'tax_amount' => 0]]]);
                    }
                    if ($step === 19 || $step === 20) {
                        $cash = $step === 19;
                        $post('/api/receipts', ['customer_id' => $customers[$cash ? 'Rahman Trading' : 'Standard Store']->id, 'receipt_number' => 'WB-R-'.$step, 'receipt_date' => '2026-01-'.$step, 'amount_received' => $cash ? 200000 : 10000, 'payment_mode' => $cash ? 'cash' : 'bank', 'status' => 'completed']);
                        $post('/api/payments', ['vendor_id' => $vendors[$cash ? 'Tech Supplier Ltd' : 'Global Enterprise']->id, 'payment_number' => 'WB-P-'.$step, 'payment_date' => '2026-01-'.$step, 'amount_paid' => $cash ? 150000 : 15000, 'payment_mode' => $cash ? 'cash' : 'bank', 'status' => 'completed']);
                    }
                } catch (\Throwable $exception) {
                    $row['error'] = get_class($exception).': '.$exception->getMessage();
                }
                $lines = \App\Models\JournalLine::query()->with('account')->where('company_id', $this->company->id)->get();
                $balance = function (string $prefix) use ($lines): float {
                    return (float) $lines->filter(fn ($line) => $line->account && str_starts_with($line->account->code, $prefix))->sum(fn ($line) => (float) $line->debit - (float) $line->credit);
                };
                $laptop = isset($products['Laptop']) ? $products['Laptop']->refresh() : null;
                $actual = ['quantity' => (float) $laptop?->current_stock_in_base_uom, 'average_cost' => (float) $laptop?->weighted_avg_cost, 'inventory' => $balance('1.1.5'), 'receivables' => $balance('1.1.4'), 'payables' => -$balance('2.1.1'),  'profit' => -$balance('4') - $balance('5'),  'equity' => -$balance('3') - $balance('4') - $balance('5'), 'assets' => $balance('1')];
                foreach (array_keys($actual) as $index => $key) {
                    $row['checks'][] = ['name' => $key, 'expected' => $expected[$step][$index], 'actual' => $actual[$key], 'pass' => abs($expected[$step][$index] - $actual[$key]) <= 0.011];
                }
                $debitTotal = (float) $lines->sum('debit');
                $creditTotal = (float) $lines->sum('credit');
                $row['checks'][] = ['name' => 'trial balance debit equals credit', 'expected' => 0, 'actual' => $debitTotal - $creditTotal, 'pass' => abs($debitTotal - $creditTotal) <= 0.01];
                $row['journal_lines'] = $lines->map(fn ($line) => ['entry' => $line->journal_entry_id, 'account' => $line->account?->code, 'name' => $line->account?->name, 'debit' => $line->debit, 'credit' => $line->credit])->all();
                $row['products'] = Product::query()->where('company_id', $this->company->id)->get()->toArray();
                $productIds = array_map(fn ($product) => $product->id, $products);
                $row['inventory_ledger'] = empty($productIds) ? [] : InventoryLedger::query()->whereIn('product_id', $productIds)->get()->toArray();
                foreach (['income-statement', 'balance-sheet', 'stock-report', 'trial-balance'] as $report) {
                    $response = $this->getJson('/api/reports/'.$report.'?start_date=2026-01-01'.'&end_date=2026-01-31'.'&as_of_date=2026-01-31');
                    $row['reports'][$report] = ['status' => $response->status(), 'body' => $response->json()];
                    $row['checks'][] = ['name' => $report.' HTTP', 'expected' => 200, 'actual' => $response->status(), 'pass' => $response->status() === 200];
                }
                $check = function (string $name, float $expectedValue, mixed $actualValue) use (&$row): void {
                    $tolerance = preg_match('/quantity|WAC|average_cost/i', $name) ? 0.0001 : 0.01;
                    $row['checks'][] = ['name' => $name, 'expected' => $expectedValue, 'actual' => $actualValue, 'pass' => is_numeric($actualValue) && abs($expectedValue - (float) $actualValue) <= $tolerance];
                };
                $sum = function (mixed $values) use (&$sum): float {
                    if (! is_array($values)) {
                        return (float) $values;
                    }

                    return array_sum(array_map($sum, $values));
                };
                $balanceSheet = $row['reports']['balance-sheet']['body'] ?? [];
                $incomeStatement = $row['reports']['income-statement']['body'] ?? [];
                $stockReport = $row['reports']['stock-report']['body'] ?? [];
                $check('balance sheet inventory', $expected[$step][2], $balanceSheet['assets']['current']['inventory'] ?? null);
                $check('balance sheet receivables', $expected[$step][3], $balanceSheet['assets']['current']['accountsReceivable'] ?? null);
                $check('balance sheet payables', $expected[$step][4], $balanceSheet['liabilities']['current']['accountsPayable'] ?? null);
                $balanceSheetAssets = $sum($balanceSheet['assets'] ?? []);
                $balanceSheetLiabilities = $sum($balanceSheet['liabilities'] ?? []);
                $balanceSheetEquity = $sum($balanceSheet['equity'] ?? []);
                $check('balance sheet assets', $expected[$step][7], $balanceSheetAssets);
                $check('balance sheet equity', $expected[$step][6], $balanceSheetEquity);
                $check('balance sheet equation', $balanceSheetAssets, $balanceSheetLiabilities + $balanceSheetEquity);
                $incomeStatementProfit = $sum($incomeStatement['revenue'] ?? []) - $sum($incomeStatement['costOfGoods'] ?? []) - $sum($incomeStatement['expenses'] ?? []);
                $check('income statement profit', $expected[$step][5], $incomeStatementProfit);
                $grossSales = ($step >= 8 ? 520000 : 0) + ($step >= 14 ? 240000 : 0);
                $salesReturns = $step >= 18 ? 60000 : 0;
                $netSales = $grossSales - $salesReturns;
                $grossCogs = ($step >= 8 ? 416000 : 0) + ($step >= 14 ? 205600 : 0);
                $salesReturnCogsReversal = $step >= 18 ? 51400 : 0;
                $netCogs = $grossCogs - $salesReturnCogsReversal;
                $check('sales revenue ledger', $netSales, -$balance('4.1.1'));
                $check('COGS ledger', $netCogs, $balance('5.1.1'));
                $check('income statement net sales', $netSales, $incomeStatement['revenue']['salesRevenue'] ?? null);
                $check('income statement net COGS', $netCogs, $incomeStatement['costOfGoods']['costOfSales'] ?? null);
                $salesBreakdown = $incomeStatement['salesBreakdown'] ?? [];
                $check('income statement gross sales', $grossSales, $salesBreakdown['grossSales'] ?? null);
                $check('income statement sales returns', $salesReturns, $salesBreakdown['salesReturns'] ?? null);
                $check('income statement breakdown net sales', $netSales, $salesBreakdown['netSales'] ?? null);
                $check('income statement gross COGS', $grossCogs, $salesBreakdown['grossCostOfSales'] ?? null);
                $check('income statement sales return COGS reversal', $salesReturnCogsReversal, $salesBreakdown['salesReturnCostReversal'] ?? null);
                $check('income statement breakdown net COGS', $netCogs, $salesBreakdown['netCostOfSales'] ?? null);
                $check('stock report value', $expected[$step][2], $stockReport['totalValue'] ?? null);
                $keyboard = $products['Keyboard'] ?? null;
                $check('keyboard quantity', 10, (float) $keyboard?->refresh()?->current_stock_in_base_uom);
                $check('cash ledger', $step >= 19 ? 50000 : 0, $balance('1.1.1.1'));
                $check('bank ledger', $step === 20 ? -5000 : 0, $balance('1.1.1.2'));
                foreach ($vendors as $name => $party) {
                    $amount = $name === 'Global Enterprise' ? ($step === 20 ? 5000 : 20000) : (($step >= 4 ? 500000 : 0) + ($step >= 6 ? 280000 : 0) + ($step >= 10 ? 250000 : 0) - ($step >= 12 ? 100000 : 0) + ($step >= 16 ? 232000 : 0) - ($step >= 19 ? 150000 : 0));
                    $partyLedger = (float) $lines->where('account_id', $party->chart_account_id)->sum(fn ($line) => (float) $line->debit - (float) $line->credit);
                    $check('party payable: '.$name, $amount, -$partyLedger);
                }
                foreach ($customers as $name => $party) {
                    $amount = $name === 'Standard Store' ? ($step === 20 ? 5000 : 15000) : (($step >= 8 ? 520000 : 0) + ($step >= 14 ? 240000 : 0) - ($step >= 18 ? 60000 : 0) - ($step >= 19 ? 200000 : 0));
                    $partyLedger = (float) $lines->where('account_id', $party->chart_account_id)->sum(fn ($line) => (float) $line->debit - (float) $line->credit);
                    $check('party receivable: '.$name, $amount, $partyLedger);
                }
                if ($step === 20) {
                    $check('FINAL gross sales', 760000, $salesBreakdown['grossSales'] ?? null);
                    $check('FINAL sales returns', 60000, $salesBreakdown['salesReturns'] ?? null);
                    $check('FINAL net sales', 700000, $salesBreakdown['netSales'] ?? null);
                    $check('FINAL gross COGS', 621600, $salesBreakdown['grossCostOfSales'] ?? null);
                    $check('FINAL sales return COGS reversal', 51400, $salesBreakdown['salesReturnCostReversal'] ?? null);
                    $check('FINAL net COGS', 570200, $salesBreakdown['netCostOfSales'] ?? null);
                    $check('FINAL current profit/loss', 129800, $balanceSheet['equity']['currentProfitLoss'] ?? null);
                    $check('FINAL total assets', 1146800, $balanceSheetAssets);
                    $check('FINAL total liabilities', 1017000, $balanceSheetLiabilities);
                    $check('FINAL total equity', 129800, $balanceSheetEquity);
                    $check('FINAL liabilities plus equity', 1146800, $balanceSheetLiabilities + $balanceSheetEquity);
                    $rahman = $customers['Rahman Trading'];
                    $standard = $customers['Standard Store'];
                    $tech = $vendors['Tech Supplier Ltd'];
                    $global = $vendors['Global Enterprise'];
                    $rahmanBalance = (float) $lines->where('account_id', $rahman->chart_account_id)->sum(fn ($line) => (float) $line->debit - (float) $line->credit);
                    $standardBalance = (float) $lines->where('account_id', $standard->chart_account_id)->sum(fn ($line) => (float) $line->debit - (float) $line->credit);
                    $techBalance = (float) $lines->where('account_id', $tech->chart_account_id)->sum(fn ($line) => (float) $line->debit - (float) $line->credit);
                    $globalBalance = (float) $lines->where('account_id', $global->chart_account_id)->sum(fn ($line) => (float) $line->debit - (float) $line->credit);
                    $check('FINAL Rahman Trading AR', 500000, $rahmanBalance);
                    $check('FINAL Standard Store AR', 5000, $standardBalance);
                    $check('FINAL total AR', 505000, $rahmanBalance + $standardBalance);
                    $check('FINAL Tech Supplier AP', 1012000, -$techBalance);
                    $check('FINAL Global Enterprise AP', 5000, -$globalBalance);
                    $check('FINAL total AP', 1017000, -$techBalance - $globalBalance);
                    $check('FINAL cash', 50000, $balance('1.1.1.1'));
                    $check('FINAL bank', -5000, $balance('1.1.1.2'));
                    $finalLaptop = collect($stockReport['products'] ?? [])->first(fn ($product) => ($product['name'] ?? null) === 'Laptop');
                    $finalKeyboard = collect($stockReport['products'] ?? [])->first(fn ($product) => ($product['name'] ?? null) === 'Keyboard');
                    $check('FINAL Laptop quantity', 11, $finalLaptop['quantity'] ?? null);
                    $check('FINAL Laptop WAC', 53800, $finalLaptop['unitCost'] ?? null);
                    $check('FINAL Laptop stock value', 591800, $finalLaptop['totalValue'] ?? null);
                    $check('FINAL Keyboard quantity', 10, $finalKeyboard['quantity'] ?? null);
                    $check('FINAL Keyboard WAC', 500, $finalKeyboard['unitCost'] ?? null);
                    $check('FINAL Keyboard stock value', 5000, $finalKeyboard['totalValue'] ?? null);
                    $check('FINAL total inventory', 596800, $stockReport['totalValue'] ?? null);
                }
                $failedChecks = array_filter($row['checks'], fn ($check) => ! ($check['pass'] ?? false));
                $row['status'] = isset($row['error']) ? 'ERROR' : (count($failedChecks) > 0 ? 'FAIL' : 'PASS');
                $results[] = $row;
            }
        } finally {
            $qaDirectory = base_path('../qa');
            if (! is_dir($qaDirectory)) {
                if (!mkdir($qaDirectory, 0700, true) && !is_dir($qaDirectory)) {
                    throw new \RuntimeException('Could not create QA evidence directory.');
                }
            }
            $passed = count(array_filter($results, fn ($result) => ($result['status'] ?? null) === 'PASS'));
            $failed = count(array_filter($results, fn ($result) => ($result['status'] ?? null) !== 'PASS'));
            $written = file_put_contents($qaDirectory.'/workbook-sequence-results.json', json_encode(['date' => date(DATE_ATOM), 'scope' => 'API and database; authenticated isolated company; rolled-back transaction; no browser UI execution', 'total_expected_steps' => 20, 'passed_steps' => $passed, 'failed_steps' => $failed, 'completed' => count($results) === 20 && $failed === 0, 'results' => $results], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
            if ($written === false) throw new \RuntimeException('Could not write workbook evidence.');
        }
        $this->assertCount(20, $results);
        $failedSteps = array_values(array_map(fn ($result) => $result['step'], array_filter($results, fn ($result) => ($result['status'] ?? null) !== 'PASS')));
        $this->assertSame([], $failedSteps, 'Workbook steps with failed checks; see qa/workbook-sequence-results.json');
    }
}
