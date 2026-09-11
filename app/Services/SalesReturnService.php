<?php

namespace App\Services;

use App\Models\SalesReturn;
use App\Models\SalesInvoice;
use App\Models\SalesInvoiceItem;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Arr;
use Exception;

class SalesReturnService
{
    public function __construct(private SalesInvoiceService $salesInvoiceService) {}

    public function createReturn(array $payload, int $userId): SalesReturn
    {
        $companyId = Auth::user()->company_id;

        $invoiceId = Arr::get($payload, 'sales_invoice_id');
        if (! $invoiceId && ! empty($payload['items'][0]['sales_invoice_item_id'])) {
            $invoiceId = SalesInvoiceItem::query()
                ->whereKey($payload['items'][0]['sales_invoice_item_id'])
                ->value('sales_invoice_id');
        }

        if ($invoiceId) {
            $invoice = SalesInvoice::query()
                ->where('company_id', $companyId)
                ->where('customer_id', $payload['customer_id'])
                ->findOrFail($invoiceId);

            return $this->salesInvoiceService->createReturn($invoice, $payload);
        }

        throw new Exception('A source sales invoice is required to create an accounted sales return.');
    }
}
