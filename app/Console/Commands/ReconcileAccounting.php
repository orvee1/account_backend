<?php
namespace App\Console\Commands;

use App\Models\{Company, Customer, Vendor, Receipt, Payment, PurchaseBill, PurchaseReturn, SalesInvoice, SalesReturn, SalesPayment, JournalEntry};
use App\Services\AccountingPostingService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class ReconcileAccounting extends Command
{
    protected $signature = 'accounting:reconcile {company} {--apply} {--approve-hash=} {--output=}';
    protected $description = 'Preview deterministic missing-receipt and party-account corrections; apply only an unchanged approved plan.';

    public function handle(): int
    {
        $company = Company::findOrFail($this->argument('company'));
        return DB::transaction(function () use ($company) {
            $actions = []; $review = [];
            $entries = JournalEntry::with('lines.account')->where('company_id',$company->id)->lockForUpdate()->get();
            foreach ($entries as $entry) {
                $class = $entry->reference_type;
                if (!in_array($class,[Receipt::class,Payment::class,PurchaseBill::class,PurchaseReturn::class,SalesInvoice::class,SalesReturn::class,SalesPayment::class,Customer::class],true)) continue;
                $source = $class::where('company_id',$company->id)->lockForUpdate()->find($entry->reference_id);
                if (!$source) { $review[]=['journal'=>$entry->id,'reason'=>'Source document missing or deleted']; continue; }
                $vendor = in_array($class,[Payment::class,PurchaseBill::class,PurchaseReturn::class],true);
                $party = $vendor ? Vendor::where('company_id',$company->id)->find($source->vendor_id) : ($source instanceof Customer ? $source : Customer::where('company_id',$company->id)->find($source instanceof SalesPayment ? $source->salesInvoice?->customer_id : $source->customer_id));
                if (!$party?->chart_account_id) { $review[]=['journal'=>$entry->id,'reason'=>'Missing linked party account']; continue; }
                $target = \App\Models\ChartAccount::where('company_id',$company->id)->find($party->chart_account_id);
                if (!$target?->is_postable) { $review[]=['journal'=>$entry->id,'reason'=>'Linked party account is not an active company ledger']; continue; }
                $partyLines = $entry->lines->filter(fn($line)=>str_starts_with($line->account?->code ?? '',$vendor?'2.1.1':'1.1.4'));
                foreach ($partyLines as $line) {
                    if ((int)$line->account_id === (int)$party->chart_account_id) continue;
                    if ($partyLines->count() !== 1 || preg_match('/^(Customer|Vendor) - /',$line->account->name)) {
                        $review[]=['journal'=>$entry->id,'reason'=>'Ambiguous or different-party posting']; continue;
                    }
                    $actions[]=['kind'=>'party_account','journal'=>$entry->id,'line'=>$line->id,'old_account'=>$line->account_id,'new_account'=>$party->chart_account_id,'debit'=>$line->debit,'credit'=>$line->credit];
                }
            }
            foreach (Receipt::where('company_id',$company->id)->where('status','completed')->lockForUpdate()->get() as $receipt) {
                if ($entries->where('reference_type',Receipt::class)->where('reference_id',$receipt->id)->isNotEmpty()) continue;
                $customer = Customer::where('company_id',$company->id)->find($receipt->customer_id);
                if (!$customer?->chart_account_id || $receipt->amount_received <= 0) { $review[]=['receipt'=>$receipt->id,'reason'=>'Missing customer account or invalid amount']; continue; }
                $target = \App\Models\ChartAccount::where('company_id',$company->id)->find($customer->chart_account_id);
                if (!$target?->is_postable || !in_array($receipt->payment_mode,['cash','bank_transfer'],true)) { $review[]=['receipt'=>$receipt->id,'reason'=>'Inactive account or payment mode requires review']; continue; }
                $actions[]=['kind'=>'missing_receipt','receipt'=>$receipt->id,'customer'=>$customer->id,'account'=>$customer->chart_account_id,'amount'=>$receipt->amount_received,'date'=>$receipt->receipt_date->toDateString(),'mode'=>$receipt->payment_mode];
            }
            $plan=['company_id'=>$company->id,'actions'=>$actions,'manual_review'=>$review];
            $hash=hash('sha256',json_encode($plan));
            if ($this->option('apply')) {
                if (!hash_equals($hash,(string)$this->option('approve-hash'))) { $this->error('Plan hash missing or stale. Preview again and pass --approve-hash.'); return self::FAILURE; }
                foreach ($actions as $action) {
                    if ($action['kind']==='party_account') {
                        DB::table('journal_lines')->where('id',$action['line'])->where('company_id',$company->id)->update(['account_id'=>$action['new_account']]);
                    } else {
                        app(AccountingPostingService::class)->post(['company_id'=>$company->id,'reference_type'=>Receipt::class,'reference_id'=>$action['receipt'],'entry_date'=>$action['date'],'description'=>'Reconciled historical receipt #'.$action['receipt'],'lines'=>[
                            ['key'=>$action['mode']==='cash'?'cash':'bank','debit'=>(float)$action['amount'],'credit'=>0],
                            ['account_id'=>$action['account'],'debit'=>0,'credit'=>(float)$action['amount']],
                        ]]);
                    }
                }
            }
            $report=$plan+['plan_hash'=>$hash,'applied'=>(bool)$this->option('apply'),'generated_at'=>now()->toIso8601String()];
            if ($this->option('output') && file_put_contents($this->option('output'),json_encode($report,JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR)) === false) throw new \RuntimeException('Could not write reconciliation evidence.');
            $this->line(json_encode($report,JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR));
            return self::SUCCESS;
        });
    }
}
