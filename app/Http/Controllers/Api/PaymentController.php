<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Payment;
use App\Models\Vendor;
use App\Services\AccountingPostingService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class PaymentController extends Controller
{
    public function __construct(
        private AccountingPostingService $postingService
    ) {}

    /*
    |--------------------------------------------------------------------------
    | List Payments
    |--------------------------------------------------------------------------
    */

    public function index(Request $request)
    {
        $companyId = (int) auth()->user()->company_id;

        $query = Payment::query()
            ->where('company_id', $companyId)
            ->with('vendor')

            ->when(
                $request->filled('q'),
                function ($query) use ($request) {
                    $keyword = '%' . trim((string) $request->q) . '%';

                    $query->where(function ($subQuery) use ($keyword) {
                        $subQuery
                            ->where(
                                'payment_number',
                                'like',
                                $keyword
                            )
                            ->orWhere(
                                'invoice_reference',
                                'like',
                                $keyword
                            )
                            ->orWhere(
                                'description',
                                'like',
                                $keyword
                            );
                    });
                }
            )

            ->when(
                $request->filled('vendor_id'),
                fn ($query) => $query->where(
                    'vendor_id',
                    $request->integer('vendor_id')
                )
            )

            ->when(
                $request->filled('status'),
                fn ($query) => $query->where(
                    'status',
                    $request->status
                )
            )

            ->when(
                $request->filled('date_from'),
                fn ($query) => $query->whereDate(
                    'payment_date',
                    '>=',
                    $request->date('date_from')
                )
            )

            ->when(
                $request->filled('date_to'),
                fn ($query) => $query->whereDate(
                    'payment_date',
                    '<=',
                    $request->date('date_to')
                )
            )

            ->orderByDesc('id');

        return response()->json(
            $query
                ->paginate(
                    $request->input(
                        'per_page',
                        15
                    )
                )
                ->withQueryString()
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Create Payment
    |--------------------------------------------------------------------------
    */

    public function store(Request $request)
    {
        $companyId = (int) auth()->user()->company_id;

        $validated = $request->validate([
            'vendor_id' => [
                'nullable',

                Rule::exists(
                    'vendors',
                    'id'
                )
                    ->where(
                        'company_id',
                        $companyId
                    )
                    ->whereNull(
                        'deleted_at'
                    ),
            ],

            'vendor_name' => [
                'nullable',
                'string',
                'max:255',
            ],

            'payment_date' => [
                'required',
                'date',
            ],

            'amount_paid' => [
                'required',
                'numeric',
                'gt:0',
            ],

            /*
            |--------------------------------------------------------------------------
            | Canonical payment modes
            |--------------------------------------------------------------------------
            |
            | Frontend:
            |
            | bank_transfer -> bank
            | card          -> online
            |
            | Backend accepts:
            |
            | cash
            | cheque
            | bank
            | online
            |
            */

            'payment_mode' => [
                'required',

                Rule::in([
                    'cash',
                    'cheque',
                    'bank',
                    'online',
                ]),
            ],

            'invoice_reference' => [
                'nullable',
                'string',
                'max:255',
            ],

            'cheque_number' => [
                'nullable',
                'string',
                'max:255',
            ],

            'description' => [
                'nullable',
                'string',
            ],

            'status' => [
                'nullable',

                Rule::in([
                    'draft',
                    'completed',
                ]),
            ],

            'payment_number' => [
                'nullable',
                'string',
                'max:255',

                Rule::unique(
                    'payments',
                    'payment_number'
                ),
            ],
        ]);

        /*
        |--------------------------------------------------------------------------
        | Resolve Vendor
        |--------------------------------------------------------------------------
        */

        if (
            empty($validated['vendor_id']) &&
            ! empty($validated['vendor_name'])
        ) {
            $validated['vendor_id'] =
                $this->resolveVendorId(
                    $validated['vendor_name']
                );
        }

        if (empty($validated['vendor_id'])) {
            return response()->json(
                [
                    'message' => 'Vendor not found.',
                ],
                422
            );
        }

        /*
        |--------------------------------------------------------------------------
        | System Fields
        |--------------------------------------------------------------------------
        */

        $user = $request->user();

        $validated['company_id'] =
            (int) $user->company_id;

        $validated['recorded_by'] =
            $user->id;

        /*
         * A normal supplier payment is posted immediately.
         * Draft must be explicitly selected.
         */
        $validated['status'] =
            $validated['status']
            ?? 'completed';

        $validated['payment_number'] =
            $validated['payment_number']
            ?? (
                'PAY-' .
                Str::uuid()
            );

        /*
        |--------------------------------------------------------------------------
        | Save Payment + Accounting Posting
        |--------------------------------------------------------------------------
        */

        $payment = DB::transaction(
            function () use ($validated) {
                $payment = Payment::create(
                    $validated
                );

                $this->postJournal(
                    $payment
                );

                return $payment;
            }
        );

        return response()->json(
            $payment->load('vendor'),
            201
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Show Payment
    |--------------------------------------------------------------------------
    */

    public function show(Payment $payment)
    {
        $this->ensurePaymentCompanyAccess(
            $payment->company_id
        );

        return response()->json(
            $payment->load('vendor')
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Update Payment
    |--------------------------------------------------------------------------
    */

    public function update(
        Request $request,
        Payment $payment
    ) {
        $this->ensurePaymentCompanyAccess(
            $payment->company_id
        );

        $companyId = (int) auth()->user()->company_id;

        $validated = $request->validate([
            'vendor_id' => [
                'nullable',

                Rule::exists(
                    'vendors',
                    'id'
                )
                    ->where(
                        'company_id',
                        $companyId
                    )
                    ->whereNull(
                        'deleted_at'
                    ),
            ],

            'vendor_name' => [
                'nullable',
                'string',
                'max:255',
            ],

            'payment_date' => [
                'required',
                'date',
            ],

            'amount_paid' => [
                'required',
                'numeric',
                'gt:0',
            ],

            'payment_mode' => [
                'required',

                Rule::in([
                    'cash',
                    'cheque',
                    'bank',
                    'online',
                ]),
            ],

            'invoice_reference' => [
                'nullable',
                'string',
                'max:255',
            ],

            'cheque_number' => [
                'nullable',
                'string',
                'max:255',
            ],

            'description' => [
                'nullable',
                'string',
            ],

            'status' => [
                'nullable',

                Rule::in([
                    'draft',
                    'completed',
                ]),
            ],

            'payment_number' => [
                'nullable',
                'string',
                'max:255',

                Rule::unique(
                    'payments',
                    'payment_number'
                )
                    ->ignore(
                        $payment->id
                    ),
            ],
        ]);

        /*
        |--------------------------------------------------------------------------
        | Resolve Vendor
        |--------------------------------------------------------------------------
        */

        if (
            empty($validated['vendor_id']) &&
            ! empty($validated['vendor_name'])
        ) {
            $validated['vendor_id'] =
                $this->resolveVendorId(
                    $validated['vendor_name']
                );
        }

        if (empty($validated['vendor_id'])) {
            return response()->json(
                [
                    'message' => 'Vendor not found.',
                ],
                422
            );
        }

        /*
        |--------------------------------------------------------------------------
        | Update Payment + Rebuild Journal
        |--------------------------------------------------------------------------
        */

        DB::transaction(
            function () use (
                $payment,
                $validated
            ) {
                $payment->update(
                    $validated
                );

                $payment->refresh();

                $this->postJournal(
                    $payment
                );
            }
        );

        return response()->json(
            $payment->load('vendor')
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Delete Payment
    |--------------------------------------------------------------------------
    */

    public function destroy(Payment $payment)
    {
        $this->ensurePaymentCompanyAccess(
            $payment->company_id
        );

        DB::transaction(
            function () use ($payment) {
                /*
                 * Remove related accounting journal first.
                 */
                $this
                    ->postingService
                    ->deleteForReference(
                        $payment->company_id,
                        Payment::class,
                        $payment->id
                    );

                $payment->delete();
            }
        );

        return response()->json([
            'message' => 'Payment deleted',
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | Resolve Vendor
    |--------------------------------------------------------------------------
    */

    private function resolveVendorId(
        string $name
    ): ?int {
        $vendorId = Vendor::query()
            ->where(
                'company_id',
                (int) auth()->user()->company_id
            )
            ->where(
                'name',
                trim($name)
            )
            ->value('id');

        return $vendorId
            ? (int) $vendorId
            : null;
    }

    /*
    |--------------------------------------------------------------------------
    | Payment-specific Company Access Guard
    |--------------------------------------------------------------------------
    |
    | IMPORTANT:
    |
    | This deliberately does NOT use the name ensureCompanyAccess().
    |
    | The base Controller in your current project already contains a method
    | with that name, so declaring another incompatible implementation here
    | causes:
    |
    | Method PaymentController::ensureCompanyAccess() is not compatible
    | with Controller::ensureCompanyAccess().
    |
    */

    private function ensurePaymentCompanyAccess(
        mixed $companyId
    ): void {
        $authenticatedCompanyId =
            (int) auth()->user()->company_id;

        if (
            (int) $companyId !==
            $authenticatedCompanyId
        ) {
            abort(
                404,
                'Not found'
            );
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Accounting Posting
    |--------------------------------------------------------------------------
    |
    | Completed supplier payment:
    |
    | Dr Vendor / Accounts Payable
    |      Cr Cash / Bank
    |
    |
    | Draft:
    |
    | No journal.
    |
    */

    private function postJournal(
        Payment $payment
    ): void {
        /*
        |--------------------------------------------------------------------------
        | Draft = No Accounting Effect
        |--------------------------------------------------------------------------
        */

        if (
            $payment->status !==
            'completed'
        ) {
            $this
                ->postingService
                ->deleteForReference(
                    $payment->company_id,
                    Payment::class,
                    $payment->id
                );

            return;
        }

        /*
        |--------------------------------------------------------------------------
        | Completed Payment
        |--------------------------------------------------------------------------
        */

        $entryDate =
            $payment->payment_date;

        if (
            $entryDate instanceof
            \DateTimeInterface
        ) {
            $entryDate =
                $entryDate->format(
                    'Y-m-d'
                );
        } else {
            $entryDate =
                (string) $entryDate;
        }

        if (! $entryDate) {
            $entryDate =
                now()->toDateString();
        }

        $this
            ->postingService
            ->post([
                'company_id' =>
                    $payment->company_id,

                'reference_type' =>
                    Payment::class,

                'reference_id' =>
                    $payment->id,

                'entry_date' =>
                    $entryDate,

                'description' =>
                    "Supplier Payment #{$payment->payment_number}",

                'created_by' =>
                    $payment->recorded_by,

                'lines' => [
                    /*
                    |--------------------------------------------------------------------------
                    | Debit Vendor / Accounts Payable
                    |--------------------------------------------------------------------------
                    */

                    [
                        'vendor_id' =>
                            $payment->vendor_id,

                        'debit' =>
                            (float)
                            $payment->amount_paid,

                        'credit' =>
                            0,

                        'narration' =>
                            $payment->description
                            ?: "Supplier payment {$payment->payment_number}",
                    ],

                    /*
                    |--------------------------------------------------------------------------
                    | Credit Cash / Bank
                    |--------------------------------------------------------------------------
                    */

                    [
                        'key' =>
                            $this->paymentMethodKey(
                                $payment->payment_mode
                            ),

                        'debit' =>
                            0,

                        'credit' =>
                            (float)
                            $payment->amount_paid,

                        'narration' =>
                            $payment->description
                            ?: "Cash/bank paid {$payment->payment_number}",
                    ],
                ],
            ]);
    }

    /*
    |--------------------------------------------------------------------------
    | Resolve Cash / Bank Accounting Key
    |--------------------------------------------------------------------------
    |
    | cash:
    |   Cash ledger
    |
    | cheque:
    |   Bank ledger
    |
    | bank:
    |   Bank ledger
    |
    | online:
    |   Bank ledger
    |
    */

    private function paymentMethodKey(
        ?string $paymentMode
    ): string {
        $mode = strtolower(
            trim(
                (string) $paymentMode
            )
        );

        return match ($mode) {
            'bank',
            'cheque',
            'online' => 'bank',

            default => 'cash',
        };
    }
}
