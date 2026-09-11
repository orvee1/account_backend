<?php

namespace App\Services;

use App\Models\SalesInvoice;
use App\Models\SalesPayment;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Arr;
use Exception;

class SalesPaymentService
{
    public function __construct(private AccountingPostingService $postingService) {}

    public function recordPayment(array $payload, int $userId): SalesPayment
    {
        $companyId = Auth::guard('sanctum')->user()?->company_id ?? Auth::user()?->company_id;

        if (! $companyId) {
            throw new Exception('Authenticated company context is required.');
        }

        return DB::transaction(function () use ($payload, $userId, $companyId) {
            $invoice = SalesInvoice::query()
                ->where('company_id', $companyId)
                ->lockForUpdate()
                ->findOrFail($payload['sales_invoice_id']);

            $amount = round((float) $payload['amount'], 2);
            $remaining = round((float) $invoice->total_amount - (float) $invoice->returns()->sum('total_amount') - (float) $invoice->paid_amount, 2);
            if ($amount > $remaining) {
                throw \Illuminate\Validation\ValidationException::withMessages(['amount'=>'Payment amount cannot exceed the invoice due amount after return credits.']);
            }

            $payment = SalesPayment::create([
                'company_id'       => $companyId,
                'sales_invoice_id' => $invoice->id,
                'payment_no'       => $payload['payment_no'] ?? 'PAY-' . \Illuminate\Support\Str::uuid()->toString(),
                'payment_date'     => $payload['payment_date'],
                'amount'           => $amount,
                'payment_method'   => Arr::get($payload, 'payment_method'),
                'reference_no'     => Arr::get($payload, 'reference_no'),
                'notes'            => Arr::get($payload, 'notes'),
                'status'           => 'completed',
                'created_by'       => $userId,
            ]);

            $this->postPaymentJournal($payment, $invoice);

            app(SalesInvoiceService::class)->refreshInvoicePaymentStatus($invoice);

            return $payment;
        });
    }

    private function postPaymentJournal(SalesPayment $payment, SalesInvoice $invoice): void
    {
        $this->postingService->post([
            'company_id' => $payment->company_id,
            'reference_type' => SalesPayment::class,
            'reference_id' => $payment->id,
            'entry_date' => $payment->payment_date?->toDateString() ?? now()->toDateString(),
            'description' => "Sales Payment #{$payment->payment_no}",
            'created_by' => $payment->created_by,
            'lines' => [
                [
                    'key' => $this->paymentMethodKey($payment->payment_method),
                    'debit' => (float) $payment->amount,
                    'credit' => 0,
                    'narration' => "Payment received for {$invoice->invoice_no}",
                ],
                [
                    'customer_id' => $invoice->customer_id,
                    'debit' => 0,
                    'credit' => (float) $payment->amount,
                    'narration' => "Receivable cleared for {$invoice->invoice_no}",
                ],
            ],
        ]);
    }

    public function deletePayment(SalesPayment $payment): void
    {
        DB::transaction(function () use ($payment) {
            $invoice = SalesInvoice::whereKey($payment->sales_invoice_id)->lockForUpdate()->firstOrFail();
            $this->postingService->deleteForReference($payment->company_id, SalesPayment::class, $payment->id);
            $payment->delete();
            app(SalesInvoiceService::class)->refreshInvoicePaymentStatus($invoice);
        });
    }

    private function paymentMethodKey(?string $paymentMethod): string
    {
        $method = strtolower((string) $paymentMethod);

        return str_contains($method, 'bank')
            || str_contains($method, 'cheque')
            || str_contains($method, 'check')
            || str_contains($method, 'transfer')
            || str_contains($method, 'online')
                ? 'bank'
                : 'cash';
    }
}
