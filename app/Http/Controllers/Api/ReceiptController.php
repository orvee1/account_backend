<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\Receipt;
use Illuminate\Http\Request;
use App\Services\AccountingPostingService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class ReceiptController extends Controller
{
    public function __construct(private AccountingPostingService $postingService) {}

    public function index(Request $request)
    {
        $filters = $request->only(['q', 'customer_id', 'payment_mode', 'status', 'per_page']);

        $companyId = auth()->user()->company_id;
        $query = Receipt::query()
            ->where('company_id', $companyId)
            ->with('customer');

        if ($request->filled('q')) {
            $query->where('receipt_number', 'like', "%{$request->q}%");
        }

        if ($request->filled('customer_id')) {
            $query->where('customer_id', $request->customer_id);
        }

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        return response()->json($query->paginate($request->input('per_page', 15))->withQueryString());
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'customer_id' => ['nullable', Rule::exists('customers', 'id')->where('company_id', auth()->user()->company_id)->whereNull('deleted_at')],
            'customer_name' => 'nullable|string',
            'receipt_date' => 'required|date',
            'amount_received' => 'required|numeric|gt:0',
            'payment_mode' => 'required|in:cash,cheque,bank,online',
            'reference_number' => 'nullable|string',
            'description' => 'nullable|string',
            'status' => 'nullable|in:draft,completed',
            'receipt_number' => ['nullable', 'string', 'max:255', Rule::unique('receipts', 'receipt_number')],
        ]);

        if (empty($validated['customer_id']) && !empty($validated['customer_name'])) {
            $validated['customer_id'] = $this->resolveCustomerId($validated['customer_name']);
        }

        if (empty($validated['customer_id'])) {
            return response()->json(['message' => 'Customer not found'], 422);
        }

        $validated['company_id'] = auth()->user()->company_id;
        $validated['recorded_by'] = auth()->id();
        $validated['status'] = $validated['status'] ?? 'completed';
        $validated['receipt_number'] = $validated['receipt_number'] ?? ('RCP-' . \Illuminate\Support\Str::uuid());

        $receipt = DB::transaction(function () use ($validated) {
            $receipt = Receipt::create($validated);
            $this->postJournal($receipt);
            return $receipt;
        });

        return response()->json($receipt->load('customer'), 201);
    }

    public function show(Receipt $receipt)
    {
        $this->ensureCompanyAccess($receipt->company_id);
        return response()->json($receipt->load('customer'));
    }

    public function update(Request $request, Receipt $receipt)
    {
        $this->ensureCompanyAccess($receipt->company_id);

        $validated = $request->validate([
            'customer_id' => ['nullable', Rule::exists('customers', 'id')->where('company_id', auth()->user()->company_id)->whereNull('deleted_at')],
            'customer_name' => 'nullable|string',
            'receipt_date' => 'required|date',
            'amount_received' => 'required|numeric|gt:0',
            'payment_mode' => 'required|in:cash,cheque,bank,online',
            'reference_number' => 'nullable|string',
            'description' => 'nullable|string',
            'status' => 'nullable|in:draft,completed',
            'receipt_number' => ['nullable', 'string', 'max:255', Rule::unique('receipts', 'receipt_number')->ignore($receipt->id)],
        ]);

        if (empty($validated['customer_id']) && !empty($validated['customer_name'])) {
            $validated['customer_id'] = $this->resolveCustomerId($validated['customer_name']);
        }

        if (empty($validated['customer_id'])) {
            return response()->json(['message' => 'Customer not found'], 422);
        }

        DB::transaction(function () use ($receipt, $validated) {
            $receipt->update($validated);
            $this->postJournal($receipt);
        });

        return response()->json($receipt->load('customer'));
    }

    public function destroy(Receipt $receipt)
    {
        $this->ensureCompanyAccess($receipt->company_id);
        DB::transaction(function () use ($receipt) {
            $this->postingService->deleteForReference($receipt->company_id, Receipt::class, $receipt->id);
            $receipt->delete();
        });

        return response()->json(['message' => 'Receipt deleted']);
    }

    private function resolveCustomerId(string $name): ?int
    {
        return Customer::query()
            ->where('company_id', auth()->user()->company_id)
            ->where('name', $name)
            ->value('id');
    }

    private function postJournal(Receipt $receipt): void
    {
        if ($receipt->status !== 'completed') {
            $this->postingService->deleteForReference($receipt->company_id, Receipt::class, $receipt->id);
            return;
        }

        $this->postingService->post([
            'company_id' => $receipt->company_id,
            'reference_type' => Receipt::class,
            'reference_id' => $receipt->id,
            'entry_date' => $receipt->receipt_date->toDateString(),
            'description' => "Customer Receipt #{$receipt->receipt_number}",
            'created_by' => $receipt->recorded_by,
            'lines' => [
                ['key' => $receipt->payment_mode === 'cash' ? 'cash' : 'bank', 'debit' => (float) $receipt->amount_received, 'credit' => 0],
                ['customer_id' => $receipt->customer_id, 'debit' => 0, 'credit' => (float) $receipt->amount_received],
            ],
        ]);
    }

    private function ensureCompanyAccess(?int $companyId): void
    {
        if ($companyId !== auth()->user()->company_id) {
            abort(404, 'Not found');
        }
    }
}
