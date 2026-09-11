<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\SalesInvoice;
use App\Http\Requests\StoreSalesInvoiceRequest;
use App\Http\Resources\SalesInvoiceResource;
use App\Services\SalesInvoiceService;
use Illuminate\Http\Request;

class SalesInvoiceController extends Controller
{
    public function __construct(private SalesInvoiceService $service) {}

    // GET /api/sales-invoices?q=&customer_id=&date_from=&date_to=&status=&per_page=20
    public function index(Request $request)
    {
        $query = SalesInvoice::query()
            ->with(['customer'])
            ->where('company_id', auth('sanctum')->user()->company_id)
            ->when($request->filled('q'), function ($q) use ($request) {
                $keyword = "%{$request->q}%";
                $q->where(function ($query) use ($keyword) {
                    $query->where('invoice_no', 'like', $keyword)
                        ->orWhereHas('customer', fn($c) => $c->where('name', 'like', $keyword));
                });
            })
            ->when($request->filled('customer_id'), fn($q) => $q->where('customer_id', $request->integer('customer_id')))
            ->when($request->filled('date_from'), fn($q) => $q->whereDate('invoice_date', '>=', $request->date('date_from')))
            ->when($request->filled('date_to'), fn($q) => $q->whereDate('invoice_date', '<=', $request->date('date_to')))
            ->when($request->filled('status'), fn($q) => $q->where('status', $request->string('status')))
            ->orderByDesc('id');

        return SalesInvoiceResource::collection($query->paginate($request->integer('per_page', 20)));
    }

    // GET /api/sales-invoices/{id}
    public function show(SalesInvoice $salesInvoice)
    {
        abort_if((int) $salesInvoice->company_id !== (int) auth('sanctum')->user()->company_id, 404);
        $salesInvoice->load(['customer', 'items.product', 'payments']);
        return SalesInvoiceResource::make($salesInvoice);
    }

    // POST /api/sales-invoices
    public function store(StoreSalesInvoiceRequest $request)
    {
        $invoice = $this->service->createInvoice($request->validated(), auth('sanctum')->user()->id);
        return response()->json(SalesInvoiceResource::make($invoice), 201);
    }

    // PUT /api/sales-invoices/{id}
    public function update(StoreSalesInvoiceRequest $request, SalesInvoice $salesInvoice)
    {
        $invoice = $this->service->updateInvoice($salesInvoice, $request->validated(), auth('sanctum')->user()->id);
        return SalesInvoiceResource::make($invoice);
    }

    // DELETE /api/sales-invoices/{id}
    public function destroy(SalesInvoice $salesInvoice)
    {
        $this->service->deleteInvoice($salesInvoice);
        return response()->noContent();
    }

    // POST /api/sales-invoices/{id}/create-return
    public function createReturn(Request $request, SalesInvoice $salesInvoice)
    {
        abort_if((int) $salesInvoice->company_id !== (int) auth('sanctum')->user()->company_id, 404);
        $payload = $request->validate([
            'return_no'=>'nullable|string|max:100', 'return_date'=>'required|date', 'reason'=>'nullable|string',
            'items'=>'required|array|min:1', 'items.*.sales_invoice_item_id'=>'required|integer',
            'items.*.product_id'=>'required|integer', 'items.*.quantity'=>'required|numeric|gt:0',
            'items.*.unit_price'=>'required|numeric|min:0', 'items.*.discount_amount'=>'nullable|numeric|min:0', 'items.*.tax_amount'=>'nullable|numeric|min:0',
        ]);
        $return = $this->service->createReturn($salesInvoice, $payload);
        return response()->json([
            'message' => 'Sales Return created successfully',
            'return' => $return,
        ], 201);
    }

    // POST /api/sales-invoices/{id}/record-payment
    public function recordPayment(Request $request, SalesInvoice $salesInvoice)
    {
        abort_if((int) $salesInvoice->company_id !== (int) auth('sanctum')->user()->company_id, 404);
        $payload = $request->validate(['amount'=>'required|numeric|gt:0','payment_no'=>'nullable|string|max:100','payment_date'=>'required|date','payment_method'=>'nullable|string','reference_no'=>'nullable|string','notes'=>'nullable|string']);
        $payment = $this->service->recordPayment($salesInvoice, $payload);
        return response()->json([
            'message' => 'Payment recorded successfully',
            'payment' => $payment,
        ], 201);
    }
}
