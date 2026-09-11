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

class AccountingHardeningTest extends TestCase
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
            'id' => 10000 + (int) CompanyUser::max('id'),
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

    private function fixture(int $quantity = 10, float $cost = 100): array
    {
        $this->postJson('/api/products', ['product_type'=>'Stock','name'=>'Hardening Item','sku'=>'HARDEN','opening_quantity'=>$quantity,'costing_price'=>$cost,'sales_price'=>200,'warehouse_id'=>$this->warehouse->id,'product_uoms'=>[['uom_id'=>$this->piece->id,'name'=>'Piece','symbol'=>'pcs','conversion_factor'=>1,'sale_price'=>200,'is_base_uom'=>true,'is_default_sale_uom'=>true]]])->assertCreated();
        $this->postJson('/api/customers',['name'=>'Hardening Customer','customer_number'=>'H-C'])->assertCreated();
        $this->postJson('/api/vendors',['name'=>'Hardening Vendor','vendor_number'=>'H-V'])->assertCreated();
        return [Product::where('sku','HARDEN')->firstOrFail(),Customer::where('customer_number','H-C')->firstOrFail(),Vendor::where('vendor_number','H-V')->firstOrFail()];
    }
    public function test_duplicate_invoice_and_insufficient_stock_leave_no_partial_postings(): void
    {
        [$product, $customer] = $this->fixture();
        $this->sale($product, $customer)->assertCreated();
        $this->sale($product, $customer)->assertUnprocessable();
        $this->sale($product, $customer, ['invoice_no'=>'H-EXCESS','items'=>[['quantity'=>100]]])->assertUnprocessable();
        $this->assertEquals(8, $product->fresh()->current_stock_in_base_uom);
        $this->assertSame(1, SalesInvoice::where('company_id',$this->company->id)->count());
        $this->assertSame(1, JournalEntry::where('company_id',$this->company->id)->where('reference_type',SalesInvoice::class)->count());
    }

    public function test_owner_cannot_disable_their_only_owner_account(): void
    {
        $this->postJson('/api/company-users/'.$this->actor->id.'/toggle-status')->assertConflict();
        $this->assertSame('active',$this->actor->fresh()->status);
    }

    public function test_purchase_returns_reject_invalid_stock_value_and_duplicate_numbers(): void
    {
        [$product, $customer, $vendor] = $this->fixture();
        $unit = $product->units()->firstOrFail();
        $payload = ['vendor_id'=>$vendor->id,'return_no'=>'H-PR','return_date'=>'2026-01-10','warehouse_id'=>$this->warehouse->id,'items'=>[['product_id'=>$product->id,'qty_unit_id'=>$unit->id,'rate_unit_id'=>$unit->id,'qty'=>2,'rate_per_unit'=>100]]];
        $invalid = $payload;
        $invalid['items'][0]['qty'] = 11;
        $this->postJson('/api/purchase-returns',$invalid)->assertUnprocessable();
        $invalid['items'][0]['qty'] = 10;
        $invalid['items'][0]['rate_per_unit'] = 50;
        $this->postJson('/api/purchase-returns',$invalid)->assertUnprocessable();
        $this->assertEquals(10,$product->fresh()->current_stock_in_base_uom);
        $this->postJson('/api/purchase-returns',$payload)->assertCreated();
        $this->postJson('/api/purchase-returns',$payload)->assertUnprocessable();
        $this->assertEquals(8,$product->fresh()->current_stock_in_base_uom);
        $this->assertSame(1,PurchaseReturn::where('company_id',$this->company->id)->count());
    }

    public function test_invoice_collections_respect_return_credits_on_both_routes(): void
    {
        [$product,$customer] = $this->fixture();
        $id = $this->sale($product,$customer)->assertCreated()->json('id');
        $invoice = SalesInvoice::with('items')->findOrFail($id);
        $return = $this->postJson('/api/sales-invoices/'.$id.'/create-return',['return_date'=>'2026-01-10','items'=>[['sales_invoice_item_id'=>$invoice->items->first()->id,'product_id'=>$product->id,'quantity'=>1,'unit_price'=>200]]])->assertCreated()->json('return.id');
        $payment = ['sales_invoice_id'=>$id,'payment_date'=>'2026-01-10','amount'=>201,'payment_method'=>'cash'];
        $this->postJson('/api/sales-payments',$payment)->assertUnprocessable();
        $this->postJson('/api/sales-invoices/'.$id.'/record-payment',$payment)->assertUnprocessable();
        $payment['amount'] = 200;
        $this->postJson('/api/sales-payments',$payment)->assertCreated();
        $this->assertSame('paid',$invoice->fresh()->status);
        $this->deleteJson('/api/sales-returns/'.$return)->assertNoContent();
        $this->assertSame('partially_paid',$invoice->fresh()->status);
        $this->postJson('/api/sales-invoices/'.$id.'/record-payment',$payment)->assertCreated();
        $this->assertSame('paid',$invoice->fresh()->status);
    }
    private function sale(Product $p, Customer $c, array $extra = [])
    {
        $u=ProductUom::where('product_id',$p->id)->firstOrFail();
        return $this->postJson('/api/sales-invoices',array_replace_recursive(['customer_id'=>$c->id,'invoice_no'=>'H-S','invoice_date'=>'2026-01-10','warehouse_id'=>$this->warehouse->id,'vat_mode'=>'exclusive','items'=>[['product_id'=>$p->id,'sale_uom_id'=>$u->id,'price_uom_id'=>$u->id,'quantity'=>2,'unit_price'=>200,'vat_rate'=>0,'ait_rate'=>0]]],$extra));
    }
    public function test_company_user_identity_does_not_need_matching_administrator(): void
    {
        [$p,$c]=$this->fixture();
        $this->assertFalse(User::whereKey($this->actor->id)->exists());
        $this->assertNull($p->created_by);
        $this->assertSame($this->actor->id,(int)$p->created_by_company_user_id);
        $this->sale($p,$c)->assertCreated();
        $invoice=SalesInvoice::where('invoice_no','H-S')->firstOrFail();
        $this->assertNull($invoice->created_by);
        $this->assertSame($this->actor->id,(int)$invoice->created_by_company_user_id);
    }
    public function test_roles_inactive_accounts_and_company_boundaries(): void
    {
        [$p,$c]=$this->fixture();
        $other=Company::create(['name'=>'Hardening Other Company','status'=>'active']);
        $outsider=CompanyUser::create(['company_id'=>$other->id,'name'=>'Outsider','phone_number'=>'01900000000','email'=>'outsider@example.test','password'=>'password','role'=>'owner','status'=>'active']);
        $this->getJson('/api/company-users')->assertOk()->assertJsonCount(1,'data');
        $this->getJson('/api/company-users/'.$outsider->id)->assertNotFound();
        $this->getJson('/api/companies/'.$other->id.'/chart-accounts')->assertNotFound();
        $this->actor->update(['role'=>'viewer']);
        $this->getJson('/api/products')->assertOk();
        $this->postJson('/api/receipts',['customer_id'=>$c->id,'amount_received'=>10,'receipt_date'=>'2026-01-10','payment_mode'=>'cash'])->assertForbidden();
        $this->actor->update(['role'=>'accountant']);
        $this->getJson('/api/company-users')->assertForbidden();
        $this->actor->update(['status'=>'inactive']);
        $this->getJson('/api/user')->assertForbidden();
    }
    public function test_idempotency_replays_once_and_rejects_different_payload(): void
    {
        [$p,$c]=$this->fixture();
        $payload=['customer_id'=>$c->id,'amount_received'=>10,'receipt_date'=>'2026-01-10','payment_mode'=>'cash'];
        $a=$this->postJson('/api/receipts',$payload,['Idempotency-Key'=>'hardening-key'])->assertCreated();
        $b=$this->postJson('/api/receipts',$payload,['Idempotency-Key'=>'hardening-key'])->assertCreated()->assertHeader('Idempotency-Replayed','true');
        $this->assertSame($a->json('id'),$b->json('id'));
        $this->assertSame(1,\App\Models\Receipt::where('company_id',$this->company->id)->count());
        $payload['amount_received']=20;
        $this->postJson('/api/receipts',$payload,['Idempotency-Key'=>'hardening-key'])->assertStatus(409);
    }
    public function test_invoice_payments_returns_and_deletions_preserve_accounting(): void
    {
        [$p,$c]=$this->fixture(); $this->sale($p,$c)->assertCreated();
        $invoice=SalesInvoice::with('items')->where('invoice_no','H-S')->firstOrFail();
        $payment=$this->postJson('/api/sales-invoices/'.$invoice->id.'/record-payment',['payment_date'=>'2026-01-11','amount'=>100,'payment_method'=>'cash'])->assertCreated();
        $this->deleteJson('/api/sales-invoices/'.$invoice->id)->assertUnprocessable();
        $id=$payment->json('payment.id');
        $this->deleteJson('/api/sales-payments/'.$id)->assertNoContent();
        $this->assertEquals(0,$invoice->refresh()->paid_amount);
        $this->assertFalse(JournalEntry::where('reference_type',SalesPayment::class)->where('reference_id',$id)->exists());
        $payload=['return_date'=>'2026-01-12','items'=>[['sales_invoice_item_id'=>$invoice->items->first()->id,'product_id'=>$p->id,'quantity'=>1,'unit_price'=>200]]];
        $return=$this->postJson('/api/sales-invoices/'.$invoice->id.'/create-return',$payload)->assertCreated();
        $payload['items'][0]['quantity']=2;
        $this->postJson('/api/sales-invoices/'.$invoice->id.'/create-return',$payload)->assertUnprocessable();
        $this->assertEquals(9,$p->refresh()->current_stock_in_base_uom);
        $this->deleteJson('/api/sales-returns/'.$return->json('return.id'))->assertNoContent();
        $this->assertEquals(8,$p->refresh()->current_stock_in_base_uom);
        $this->assertEquals(100,$p->weighted_avg_cost);
    }
    public function test_vat_inclusive_and_exclusive_purchase_costs_match_journals(): void
    {
        [$p,$c,$v]=$this->fixture(0,0); $u=ProductUom::where('product_id',$p->id)->firstOrFail();
        foreach (['exclusive','inclusive'] as $i=>$mode) {
            $r=$this->postJson('/api/purchase-bills',['vendor_id'=>$v->id,'bill_no'=>'VAT-'.$i,'bill_date'=>'2026-01-05','warehouse_id'=>$this->warehouse->id,'vat_mode'=>$mode,'items'=>[['product_id'=>$p->id,'purchase_uom_id'=>$u->id,'price_uom_id'=>$u->id,'quantity'=>2,'unit_price'=>$mode==='inclusive'?575:500,'trade_discount_pct'=>10,'vat_rate'=>15,'ait_rate'=>0]]])->assertCreated();
            $this->assertEqualsWithDelta(1035,$r->json('total_amount'),0.01);
            $this->assertEqualsWithDelta(450,$p->refresh()->weighted_avg_cost,0.01);
        }
        $this->sale($p,$c,['vat_mode'=>'inclusive','items'=>[['quantity'=>1,'unit_price'=>1150,'vat_rate'=>15]]])->assertCreated();
        $report=$this->getJson('/api/reports/income-statement?start_date=2026-01-01&end_date=2026-01-31')->assertOk();
        $this->assertEquals(1000,$report->json('revenue.salesRevenue'));
        $this->assertEquals(450,$report->json('costOfGoods.costOfSales'));
        $first=PurchaseBill::where('bill_no','VAT-0')->firstOrFail();
        $this->deleteJson('/api/purchase-bills/'.$first->id)->assertUnprocessable();
    }
    public function test_reconciliation_preview_is_read_only_and_apply_is_repeatable(): void
    {
        [$p,$c]=$this->fixture();
        $receipt=\App\Models\Receipt::create(['company_id'=>$this->company->id,'customer_id'=>$c->id,'receipt_number'=>'H-OLD','receipt_date'=>'2026-01-01','amount_received'=>50,'payment_mode'=>'cash','status'=>'completed']);
        $before=JournalEntry::count();
        $file=base_path('../qa/hardening-reconciliation.json');
        $this->artisan('accounting:reconcile',['company'=>$this->company->id,'--output'=>$file])->assertSuccessful();
        $this->assertSame($before,JournalEntry::count());
        $plan=json_decode(file_get_contents($file),true);
        $this->assertSame('missing_receipt',$plan['actions'][0]['kind']);
        $this->artisan('accounting:reconcile',['company'=>$this->company->id,'--apply'=>true,'--approve-hash'=>$plan['plan_hash'],'--output'=>$file])->assertSuccessful();
        $this->assertSame($before+1,JournalEntry::count());
        $this->artisan('accounting:reconcile',['company'=>$this->company->id,'--output'=>$file])->assertSuccessful();
        $this->assertSame([],json_decode(file_get_contents($file),true)['actions']);
    }
}
