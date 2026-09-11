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

        $this->company = Company::query()->create([
            'name' => 'Audit Test Company',
            'status' => 'active',
        ]);

        $this->actor = CompanyUser::query()->create([
            'company_id' => $this->company->id,
            'name' => 'Audit User',
            'email' => 'workbook-' . uniqid() . '@example.test',
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

    public function test_receipts_and_payments_replace_and_remove_their_journals(): void
    {
        foreach ([['customers', 'customer', 'receipts', 'receipt', 'amount_received'], ['vendors', 'vendor', 'payments', 'payment', 'amount_paid']] as [$parties, $party, $documents, $document, $amount]) {
            $this->postJson('/api/'.$parties, ['name' => 'Lifecycle '.$party, $party.'_number' => 'LIFE-'.$party])->assertCreated();
            $model = $party === 'customer' ? Customer::class : Vendor::class;
            $record = $model::where('name', 'Lifecycle '.$party)->firstOrFail();
            $payload = [$party.'_id' => $record->id, $document.'_number' => 'LIFE-'.$document, $document.'_date' => '2026-01-20', $amount => 100, 'payment_mode' => 'cash'];
            $created = $this->postJson('/api/'.$documents, $payload)->assertCreated();
            $this->postJson('/api/'.$documents, $payload)->assertUnprocessable();
            $id = $created->json('id');
            $reference = $document === 'receipt' ? \App\Models\Receipt::class : Payment::class;
            $journal = fn () => JournalEntry::with('lines')->where('company_id', $this->company->id)->where('reference_type', $reference)->where('reference_id', $id);
            $this->assertEquals(100, $journal()->firstOrFail()->lines->sum('debit'));
            $payload[$amount] = 150;
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
        $payload = ['product_type' => 'Stock', 'name' => 'Unit Compatibility', 'sku' => 'UNIT-COMPAT', 'costing_price' => 500, 'sales_price' => 750, 'opening_quantity' => 10, 'warehouse_id' => $this->warehouse->id, 'units' => [['name' => 'Piece', 'factor' => 1, 'is_base' => true]]];
        $response = $this->postJson('/api/products', $payload)->assertCreated();
        $this->assertCount(1, $response->json('units'));
        $this->assertEquals(10, Product::where('sku', 'UNIT-COMPAT')->firstOrFail()->current_stock_in_base_uom);
        // Force an actual database failure after the product and first unit have
        // been written; the surrounding transaction must remove both.
        $payload['sku'] = 'UNIT-ROLLBACK';
        $payload['units'][] = ['name' => 'Invalid', 'factor' => null, 'is_base' => false];
        try {
            app(\App\Services\ProductService::class)->create($payload);
            $this->fail('Expected a unit constraint failure.');
        } catch (\Illuminate\Database\QueryException $exception) {
            $this->assertFalse(Product::where('sku', 'UNIT-ROLLBACK')->exists());
        }
    }

    public function test_settlements_reject_another_companys_party(): void
    {
        $other = Company::create(['name' => 'Other Company', 'status' => 'active']);
        $vendor = Vendor::create(['company_id' => $other->id, 'name' => 'Other Vendor']);
        $customer = Customer::create(['company_id' => $other->id, 'name' => 'Other Customer']);
        $this->postJson('/api/payments', ['vendor_id' => $vendor->id, 'payment_date' => '2026-01-20', 'amount_paid' => 10, 'payment_mode' => 'cash'])->assertUnprocessable();
        $this->postJson('/api/receipts', ['customer_id' => $customer->id, 'receipt_date' => '2026-01-20', 'amount_received' => 10, 'payment_mode' => 'cash'])->assertUnprocessable();
    }

    public function test_workbook_sequence(): void
    {
        $this->assertSame('account_api_testing', DB::connection()->getDatabaseName());
        $results = [];
        $products = []; $vendors = []; $customers = []; $invoices = [];
        $expected = [
            1=>[0,0,5000,0,0,0,5000,5000],2=>[0,0,5000,0,20000,0,-15000,5000],
            3=>[0,0,5000,15000,20000,0,0,20000],4=>[10,50000,505000,15000,520000,0,0,520000],
            5=>[10,50000,505000,15000,520000,0,0,520000],6=>[15,52000,785000,15000,800000,0,0,800000],
            7=>[15,52000,785000,15000,800000,0,0,800000],8=>[7,52000,369000,535000,800000,104000,104000,904000],
            9=>[7,52000,369000,535000,800000,104000,104000,904000],10=>[12,51166.666667,619000,535000,1050000,104000,104000,1154000],
            11=>[12,51166.666667,619000,535000,1050000,104000,104000,1154000],12=>[10,51400,519000,535000,950000,104000,104000,1054000],
            13=>[10,51400,519000,535000,950000,104000,104000,1054000],14=>[6,51400,313400,775000,950000,138400,138400,1088400],
            15=>[6,51400,313400,775000,950000,138400,138400,1088400],16=>[10,54040,545400,775000,1182000,138400,138400,1320400],
            17=>[10,54040,545400,775000,1182000,138400,138400,1320400],18=>[11,53800,596800,715000,1182000,129800,129800,1311800],
            19=>[11,53800,596800,515000,1032000,129800,129800,1161800],20=>[11,53800,596800,505000,1017000,129800,129800,1146800],
        ];
        try {
            for ($step=1; $step<=20; $step++) {
                $row=['step'=>$step,'responses'=>[],'checks'=>[]];
                $post=function($url,$payload) use (&$row) {
                    $r=$this->postJson($url,$payload);
                    $row['responses'][]=['url'=>$url,'input'=>$payload,'status'=>$r->status(),'body'=>$r->json()];
                    $row['checks'][]=['name'=>'HTTP '.$url,'expected'=>201,'actual'=>$r->status(),'pass'=>$r->status()===201];
                    return $r;
                };
                try {
                    if ($step===1) {
                        foreach ([['Laptop',0,0],['Keyboard',10,500]] as [$name,$qty,$cost]) {
                            $post('/api/products',['product_type'=>'Stock','name'=>$name,'sku'=>'WB-'.$name,'costing_price'=>$cost,'sales_price'=>65000,'opening_quantity'=>$qty,'opening_date'=>'2026-01-01','warehouse_id'=>$this->warehouse->id,'product_uoms'=>[['uom_id'=>$this->piece->id,'name'=>'Piece','symbol'=>'pcs','conversion_factor'=>1,'sale_price'=>65000,'is_base_uom'=>true,'is_default_sale_uom'=>true]]]);
                            $products[$name]=Product::where('sku','WB-'.$name)->firstOrFail();
                        }
                    }
                    if ($step===2 || $step===3) {
                        $supplier=$step===2;
                        foreach (($supplier ? [['Tech Supplier Ltd',0],['Global Enterprise',20000]] : [['Rahman Trading',0],['Standard Store',15000]]) as [$name,$balance]) {
                            $number='WB-'.str_replace(' ','-',$name);
                            $post($supplier?'/api/vendors':'/api/customers',['name'=>$name,($supplier?'vendor_number':'customer_number')=>$number,'opening_balance'=>$balance,'opening_balance_type'=>$supplier?'credit':'debit','opening_balance_date'=>'2026-01-01']);
                            if($supplier) $vendors[$name]=Vendor::where('vendor_number',$number)->firstOrFail();
                            else $customers[$name]=Customer::where('customer_number',$number)->firstOrFail();
                        }
                    }
                    if (in_array($step,[4,6,10,16,8,14])) {
                        $purchase=in_array($step,[4,6,10,16]);
                        [$qty,$rate]=[4=>[10,50000],6=>[5,56000],10=>[5,50000],16=>[4,58000],8=>[8,65000],14=>[4,60000]][$step];
                        $p=$products['Laptop']; $u=ProductUom::where('product_id',$p->id)->firstOrFail();
                        $payload=[($purchase?'vendor_id':'customer_id')=>($purchase?$vendors['Tech Supplier Ltd']->id:$customers['Rahman Trading']->id),($purchase?'bill_no':'invoice_no')=>'WB-'.$step,($purchase?'bill_date':'invoice_date')=>sprintf('2026-01-%02d',$step),'due_date'=>'2026-02-28','warehouse_id'=>$this->warehouse->id,'vat_mode'=>'exclusive','items'=>[['product_id'=>$p->id,($purchase?'purchase_uom_id':'sale_uom_id')=>$u->id,'price_uom_id'=>$u->id,'quantity'=>$qty,'unit_price'=>$rate,'trade_discount_pct'=>0,'line_discount_pct'=>0,'line_discount_amt'=>0,'vat_rate'=>0,'ait_rate'=>0]]];
                        $post($purchase?'/api/purchase-bills':'/api/sales-invoices',$payload);
                        if (!$purchase) $invoices[$step]=SalesInvoice::with('items')->where('invoice_no','WB-'.$step)->firstOrFail();
                    }
                    if ($step===12) {
                        $p=$products['Laptop']; $u=ProductUnit::where('product_id',$p->id)->where('is_base',true)->firstOrFail();
                        $post('/api/purchase-returns',['vendor_id'=>$vendors['Tech Supplier Ltd']->id,'return_no'=>'WB-PR','return_date'=>'2026-01-12','warehouse_id'=>$this->warehouse->id,'items'=>[['product_id'=>$p->id,'qty_unit_id'=>$u->id,'qty'=>2,'rate_unit_id'=>$u->id,'rate_per_unit'=>50000,'discount_percent'=>0,'discount_amount'=>0,'warehouse_id'=>$this->warehouse->id]]]);
                    }
                    if ($step===18) {
                        $invoice=$invoices[14];
                        $post('/api/sales-invoices/'.$invoice->id.'/create-return',['return_no'=>'WB-SR','return_date'=>'2026-01-18','reason'=>'Workbook return','items'=>[['sales_invoice_item_id'=>$invoice->items->first()->id,'product_id'=>$products['Laptop']->id,'quantity'=>1,'unit_price'=>60000,'discount_amount'=>0,'tax_amount'=>0]]]);
                    }
                    if ($step===19 || $step===20) {
                        $cash=$step===19;
                        $post('/api/receipts',['customer_id'=>$customers[$cash?'Rahman Trading':'Standard Store']->id,'receipt_number'=>'WB-R-'.$step,'receipt_date'=>'2026-01-'.$step,'amount_received'=>$cash?200000:10000,'payment_mode'=>$cash?'cash':'bank']);
                        $post('/api/payments',['vendor_id'=>$vendors[$cash?'Tech Supplier Ltd':'Global Enterprise']->id,'payment_number'=>'WB-P-'.$step,'payment_date'=>'2026-01-'.$step,'amount_paid'=>$cash?150000:15000,'payment_mode'=>$cash?'cash':'bank']);
                    }
                } catch (\Throwable $e) { $row['error']=get_class($e).': '.$e->getMessage(); }
                $lines=\App\Models\JournalLine::with('account')->where('company_id',$this->company->id)->get();
                $balance=function($prefix) use($lines) { return (float)$lines->filter(fn($l)=>str_starts_with($l->account->code,$prefix))->sum(fn($l)=>(float)$l->debit-(float)$l->credit); };
                $lap=isset($products['Laptop'])?$products['Laptop']->refresh():null;
                $actual=['quantity'=>(float)$lap?->current_stock_in_base_uom,'average_cost'=>(float)$lap?->weighted_avg_cost,'inventory'=>$balance('1.1.5'),'receivables'=>$balance('1.1.4'),'payables'=>-$balance('2.1.1'),'profit'=>-$balance('4')-$balance('5'),'equity'=>-$balance('3')-$balance('4')-$balance('5'),'assets'=>$balance('1')];
                foreach (array_keys($actual) as $i=>$key) $row['checks'][]=['name'=>$key,'expected'=>$expected[$step][$i],'actual'=>$actual[$key],'pass'=>abs($expected[$step][$i]-$actual[$key])<=0.011];
                $row['checks'][]=['name'=>'trial balance debit equals credit','expected'=>0,'actual'=>(float)$lines->sum('debit')-(float)$lines->sum('credit'),'pass'=>abs((float)$lines->sum('debit')-(float)$lines->sum('credit'))<=0.01];
                $row['journal_lines']=$lines->map(fn($l)=>['entry'=>$l->journal_entry_id,'account'=>$l->account->code,'name'=>$l->account->name,'debit'=>$l->debit,'credit'=>$l->credit])->all();
                $row['products']=Product::where('company_id',$this->company->id)->get()->toArray();
                $row['inventory_ledger']=InventoryLedger::whereIn('product_id',array_map(fn($p)=>$p->id,$products))->get()->toArray();
                foreach (['income-statement','balance-sheet','stock-report','trial-balance'] as $report) {
                    $r=$this->getJson('/api/reports/'.$report.'?start_date=2026-01-01&end_date=2026-01-31&as_of_date=2026-01-31');
                    $row['reports'][$report]=['status'=>$r->status(),'body'=>$r->json()];
                    $row['checks'][]=['name'=>$report.' HTTP','expected'=>200,'actual'=>$r->status(),'pass'=>$r->status()===200];
                }
                $check=function($name,$expectedValue,$actualValue) use (&$row) {
                    $row['checks'][]=['name'=>$name,'expected'=>$expectedValue,'actual'=>$actualValue,'pass'=>is_numeric($actualValue)&&abs($expectedValue-$actualValue)<=0.011];
                };
                $sum=function($values) use (&$sum) { return is_array($values)?array_sum(array_map($sum,$values)):(float)$values; };
                $bs=$row['reports']['balance-sheet']['body'];
                $is=$row['reports']['income-statement']['body'];
                $check('balance sheet inventory',$expected[$step][2],$bs['assets']['current']['inventory']??null);
                $check('balance sheet receivables',$expected[$step][3],$bs['assets']['current']['accountsReceivable']??null);
                $check('balance sheet payables',$expected[$step][4],$bs['liabilities']['current']['accountsPayable']??null);
                $check('balance sheet assets',$expected[$step][7],$sum($bs['assets']??[]));
                $check('balance sheet equity',$expected[$step][6],$sum($bs['equity']??[]));
                $check('income statement profit',$expected[$step][5],$sum($is['revenue']??[])-$sum($is['costOfGoods']??[])-$sum($is['expenses']??[]));
                $revenue = ($step >= 8 ? 520000 : 0) + ($step >= 14 ? 240000 : 0) - ($step >= 18 ? 60000 : 0);
                $cogs = ($step >= 8 ? 416000 : 0) + ($step >= 14 ? 205600 : 0) - ($step >= 18 ? 51400 : 0);
                $check('sales revenue ledger', $revenue, -$balance('4.1.1'));
                $check('COGS ledger', $cogs, $balance('5.1.1'));
                $check('income statement net sales', $revenue, $is['revenue']['salesRevenue'] ?? null);
                $check('income statement net COGS', $cogs, $is['costOfGoods']['costOfSales'] ?? null);
                $check('stock report value',$expected[$step][2],$row['reports']['stock-report']['body']['totalValue']??null);
                $check('keyboard quantity',10,(float)($products['Keyboard']??null)?->refresh()?->current_stock_in_base_uom);
                $check('cash ledger',$step>=19?50000:0,$balance('1.1.1.1'));
                $check('bank ledger',$step===20?-5000:0,$balance('1.1.1.2'));
                foreach ($vendors as $name=>$party) {
                    $amount=$name==='Global Enterprise'?($step===20?5000:20000):($step>=4?500000:0)+($step>=6?280000:0)+($step>=10?250000:0)-($step>=12?100000:0)+($step>=16?232000:0)-($step>=19?150000:0);
                    $check('party payable: '.$name,$amount,-(float)$lines->where('account_id',$party->chart_account_id)->sum(fn($l)=>(float)$l->debit-(float)$l->credit));
                }
                foreach ($customers as $name=>$party) {
                    $amount=$name==='Standard Store'?($step===20?5000:15000):($step>=8?520000:0)+($step>=14?240000:0)-($step>=18?60000:0)-($step>=19?200000:0);
                    $check('party receivable: '.$name,$amount,(float)$lines->where('account_id',$party->chart_account_id)->sum(fn($l)=>(float)$l->debit-(float)$l->credit));
                }
                $row['status']=isset($row['error'])?'ERROR':(count(array_filter($row['checks'],fn($c)=>!$c['pass']))?'FAIL':'PASS');
                $results[]=$row;
            }
        } finally {
            file_put_contents(base_path('../qa/workbook-sequence-results.json'),json_encode(['date'=>date(DATE_ATOM),'scope'=>'API and database; authenticated fixture; rolled-back transaction; no UI execution','results'=>$results],JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_PARTIAL_OUTPUT_ON_ERROR));
        }
        $this->assertCount(20,$results);
        $this->assertSame([],array_values(array_map(fn($r)=>$r['step'],array_filter($results,fn($r)=>$r['status']!=='PASS'))),'Workbook steps with failed checks; see qa/workbook-sequence-results.json');
    }
}
