<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Payment;
use App\Models\Vendor;
use App\Services\AccountingPostingService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class PaymentController extends Controller
{
    public function __construct(private AccountingPostingService $postingService) {}

    public function index(Request $request)
    {
        $companyId = auth()->user()->company_id;

        $query = Payment::query()
            ->where('company_id', $companyId)
            ->with('vendor')
            ->when($request->filled('q'), function ($q) use ($request) {
                $keyword = "%{$request->q}%";
                $q->where('payment_number', 'like', $keyword)
                    ->orWhere('invoice_reference', 'like', $keyword);
            })
            ->when($request->filled('vendor_id'), fn($q) => $q->where('vendor_id', $request->integer('vendor_id')))
            ->when($request->filled('status'), fn($q) => $q->where('status', $request->status))
            ->when($request->filled('date_from'), fn($q) => $q->whereDate('payment_date', '>=', $request->date('date_from')))
            ->when($request->filled('date_to'), fn($q) => $q->whereDate('payment_date', '<=', $request->date('date_to')))
            ->orderByDesc('id');

        return response()->json($query->paginate($request->input('per_page', 15))->withQueryString());
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'vendor_id' => ['nullable', Rule::exists('vendors', 'id')->where('company_id', auth()->user()->company_id)->whereNull('deleted_at')],
            'vendor_name' => 'nullable|string',
            'payment_date' => 'required|date',
            'amount_paid' => 'required|numeric|gt:0',
            'payment_mode' => 'required|string',
            'invoice_reference' => 'nullable|string',
            'cheque_number' => 'nullable|string',
            'description' => 'nullable|string',
            'status' => 'nullable|in:draft,completed',
            'payment_number' => ['nullable', 'string', 'max:255', Rule::unique('payments', 'payment_number')],
        ]);

        if (empty($validated['vendor_id']) && !empty($validated['vendor_name'])) {
            $validated['vendor_id'] = $this->resolveVendorId($validated['vendor_name']);
        }

        if (empty($validated['vendor_id'])) {
            return response()->json(['message' => 'Vendor not found'], 422);
        }

        $user = $request->user();
        $validated['company_id'] = $user->company_id;
        $validated['recorded_by'] = $user->id;
        $validated['status'] = $validated['status'] ?? 'completed';
        $validated['payment_number'] = $validated['payment_number'] ?? ('PAY-' . \Illuminate\Support\Str::uuid());

        $payment = DB::transaction(function () use ($validated) {
            $payment = Payment::create($validated);

            $this->postJournal($payment);

            return $payment;
        });

        return response()->json($payment->load('vendor'), 201);
    }

    public function show(Payment $payment)
    {
        $this->ensureCompanyAccess($payment->company_id);
        return response()->json($payment->load('vendor'));
    }

    public function update(Request $request, Payment $payment)
    {
        $this->ensureCompanyAccess($payment->company_id);

        $validated = $request->validate([
            'vendor_id' => ['nullable', Rule::exists('vendors', 'id')->where('company_id', auth()->user()->company_id)->whereNull('deleted_at')],
            'vendor_name' => 'nullable|string',
            'payment_date' => 'required|date',
            'amount_paid' => 'required|numeric|gt:0',
            'payment_mode' => 'required|string',
            'invoice_reference' => 'nullable|string',
            'cheque_number' => 'nullable|string',
            'description' => 'nullable|string',
            'status' => 'nullable|in:draft,completed',
            'payment_number' => ['nullable', 'string', 'max:255', Rule::unique('payments', 'payment_number')->ignore($payment->id)],
        ]);

        if (empty($validated['vendor_id']) && !empty($validated['vendor_name'])) {
            $validated['vendor_id'] = $this->resolveVendorId($validated['vendor_name']);
        }

        if (empty($validated['vendor_id'])) {
            return response()->json(['message' => 'Vendor not found'], 422);
        }

        DB::transaction(function () use ($payment, $validated) {
            $payment->update($validated);
            $this->postJournal($payment);
        });

        return response()->json($payment->load('vendor'));
    }

    public function destroy(Payment $payment)
    {
        $this->ensureCompanyAccess($payment->company_id);
        DB::transaction(function () use ($payment) {
            $this->postingService->deleteForReference($payment->company_id, Payment::class, $payment->id);
            $payment->delete();
        });

        return response()->json(['message' => 'Payment deleted']);
    }

    private function resolveVendorId(string $name): ?int
    {
        return Vendor::query()
            ->where('company_id', auth()->user()->company_id)
            ->where('name', $name)
            ->value('id');
    }

    private function ensureCompanyAccess(?int $companyId): void
    {
        if ($companyId !== auth()->user()->company_id) {
            abort(404, 'Not found');
        }
    }

    private function postJournal(Payment $payment): void
    {
        if ($payment->status !== 'completed') {
            $this->postingService->deleteForReference($payment->company_id, Payment::class, $payment->id);
            return;
        }

        $this->postingService->post([
                'company_id' => $payment->company_id,
                'reference_type' => Payment::class,
                'reference_id' => $payment->id,
                'entry_date' => $payment->payment_date?->toDateString() ?? now()->toDateString(),
                'description' => "Supplier Payment #{$payment->payment_number}",
                'created_by' => $payment->recorded_by,
                'lines' => [
                    [
                        'vendor_id' => $payment->vendor_id,
                        'debit' => (float) $payment->amount_paid,
                        'credit' => 0,
                        'narration' => $payment->description ?: "Supplier payment {$payment->payment_number}",
                    ],
                    [
                        'key' => $this->paymentMethodKey($payment->payment_mode),
                        'debit' => 0,
                        'credit' => (float) $payment->amount_paid,
                        'narration' => $payment->description ?: "Cash/bank paid {$payment->payment_number}",
                    ],
                ],
            ]);
    }

    private function paymentMethodKey(?string $paymentMode): string
    {
        $mode = strtolower((string) $paymentMode);

        return str_contains($mode, 'bank')
            || str_contains($mode, 'cheque')
            || str_contains($mode, 'check')
            || str_contains($mode, 'transfer')
            || str_contains($mode, 'online')
            || str_contains($mode, 'card')
                ? 'bank'
                : 'cash';
    }
}
