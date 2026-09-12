<?php
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\Receipt;
use App\Services\AccountingPostingService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class ReceiptController extends Controller
{
    public function __construct(
        private AccountingPostingService $postingService
    ) {}

    /*
    |--------------------------------------------------------------------------
    | Receipt List
    |--------------------------------------------------------------------------
    */

    public function index(Request $request)
    {
        $companyId =
        (int) auth()
            ->user()
            ->company_id;

        $query =
        Receipt::query()
            ->where(
                'company_id',
                $companyId
            )
            ->with(
                'customer'
            );

        /*
        |--------------------------------------------------------------------------
        | Search
        |--------------------------------------------------------------------------
        */

        if (
            $request->filled(
                'q'
            )
        ) {
            $keyword =
            '%' .
            trim(
                (string)
                $request->q
            ) .
                '%';

            $query->where(
                function (
                    $query
                ) use (
                    $keyword
                ) {
                    $query
                        ->where(
                            'receipt_number',
                            'like',
                            $keyword
                        )
                        ->orWhere(
                            'reference_number',
                            'like',
                            $keyword
                        )
                        ->orWhere(
                            'description',
                            'like',
                            $keyword
                        );
                }
            );
        }

        /*
        |--------------------------------------------------------------------------
        | Customer
        |--------------------------------------------------------------------------
        */

        if (
            $request->filled(
                'customer_id'
            )
        ) {
            $query->where(
                'customer_id',
                $request->integer(
                    'customer_id'
                )
            );
        }

        /*
        |--------------------------------------------------------------------------
        | Payment Mode
        |--------------------------------------------------------------------------
        */

        if (
            $request->filled(
                'payment_mode'
            )
        ) {
            $query->where(
                'payment_mode',
                $request->payment_mode
            );
        }

        /*
        |--------------------------------------------------------------------------
        | Status
        |--------------------------------------------------------------------------
        */

        if (
            $request->filled(
                'status'
            )
        ) {
            $query->where(
                'status',
                $request->status
            );
        }

        /*
        |--------------------------------------------------------------------------
        | Date Range
        |--------------------------------------------------------------------------
        */

        if (
            $request->filled(
                'date_from'
            )
        ) {
            $query->whereDate(
                'receipt_date',
                '>=',
                $request->date(
                    'date_from'
                )
            );
        }

        if (
            $request->filled(
                'date_to'
            )
        ) {
            $query->whereDate(
                'receipt_date',
                '<=',
                $request->date(
                    'date_to'
                )
            );
        }

        return response()->json(
            $query
                ->orderByDesc(
                    'id'
                )
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
    | Create Receipt
    |--------------------------------------------------------------------------
    */

    public function store(
        Request $request
    ) {
        $companyId =
        (int) auth()
            ->user()
            ->company_id;

        $validated =
        $request->validate([
            /*
                |--------------------------------------------------------------------------
                | Customer
                |--------------------------------------------------------------------------
                */

            'customer_id'      => [
                'nullable',

                Rule::exists(
                    'customers',
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

            'customer_name'    => [
                'nullable',
                'string',
                'max:255',
            ],

            /*
                |--------------------------------------------------------------------------
                | Receipt
                |--------------------------------------------------------------------------
                */

            'receipt_date'     => [
                'required',
                'date',
            ],

            'amount_received'  => [
                'required',
                'numeric',
                'gt:0',
            ],

            /*
                |--------------------------------------------------------------------------
                | Canonical Payment Modes
                |--------------------------------------------------------------------------
                |
                | Frontend converts:
                |
                | bank_transfer -> bank
                | card          -> online
                |
                | Backend accepts only:
                |
                | cash
                | cheque
                | bank
                | online
                |
                */

            'payment_mode'     => [
                'required',

                Rule::in([
                    'cash',
                    'cheque',
                    'bank',
                    'online',
                ]),
            ],

            'reference_number' => [
                'nullable',
                'string',
                'max:255',
            ],

            'description'      => [
                'nullable',
                'string',
            ],

            /*
                |--------------------------------------------------------------------------
                | Status
                |--------------------------------------------------------------------------
                */

            'status'           => [
                'nullable',

                Rule::in([
                    'draft',
                    'completed',
                ]),
            ],

            /*
                |--------------------------------------------------------------------------
                | Receipt Number
                |--------------------------------------------------------------------------
                */

            'receipt_number'   => [
                'nullable',
                'string',
                'max:255',

                Rule::unique(
                    'receipts',
                    'receipt_number'
                ),
            ],
        ]);

        /*
        |--------------------------------------------------------------------------
        | Resolve Customer
        |--------------------------------------------------------------------------
        */

        if (
            empty(
                $validated[
                    'customer_id'
                ]
            )
            &&
            ! empty(
                $validated[
                    'customer_name'
                ]
            )
        ) {
            $validated[
                'customer_id'
            ] =
            $this->resolveCustomerId(
                $validated[
                    'customer_name'
                ]
            );
        }

        if (
            empty(
                $validated[
                    'customer_id'
                ]
            )
        ) {
            return response()->json(
                [
                    'message' =>
                    'Customer not found.',
                ],
                422
            );
        }

        /*
        |--------------------------------------------------------------------------
        | System Fields
        |--------------------------------------------------------------------------
        */

        $user =
        $request->user();

        $validated[
            'company_id'
        ] =
        (int)
        $user->company_id;

        $validated[
            'recorded_by'
        ] =
        $user->id;

        /*
         * Normal receipt is posted immediately.
         *
         * Draft must be explicitly selected.
         */
        $validated[
            'status'
        ] =
        $validated[
            'status'
        ] ?? 'completed';

        $validated[
            'receipt_number'
        ] =
        $validated[
            'receipt_number'
        ] ??
            (
            'RCP-' .
            Str::uuid()
        );

        /*
        |--------------------------------------------------------------------------
        | Create + Accounting Posting
        |--------------------------------------------------------------------------
        */

        $receipt =
        DB::transaction(
            function () use (
                $validated
            ) {
                $receipt =
                Receipt::create(
                    $validated
                );

                $this->postJournal(
                    $receipt
                );

                return $receipt;
            }
        );

        return response()->json(
            $receipt->load(
                'customer'
            ),
            201
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Show Receipt
    |--------------------------------------------------------------------------
    */

    public function show(
        Receipt $receipt
    ) {
        /*
         * Deliberately use a receipt-specific method name.
         *
         * The project's base Controller may already contain
         * ensureCompanyAccess(), therefore redeclaring that exact method
         * here could create an inheritance signature conflict.
         */
        $this->ensureReceiptCompanyAccess(
            $receipt->company_id
        );

        return response()->json(
            $receipt->load(
                'customer'
            )
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Update Receipt
    |--------------------------------------------------------------------------
    */

    public function update(
        Request $request,
        Receipt $receipt
    ) {
        $this->ensureReceiptCompanyAccess(
            $receipt->company_id
        );

        $companyId =
        (int) auth()
            ->user()
            ->company_id;

        $validated =
        $request->validate([
            /*
                |--------------------------------------------------------------------------
                | Customer
                |--------------------------------------------------------------------------
                */

            'customer_id'      => [
                'nullable',

                Rule::exists(
                    'customers',
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

            'customer_name'    => [
                'nullable',
                'string',
                'max:255',
            ],

            /*
                |--------------------------------------------------------------------------
                | Receipt
                |--------------------------------------------------------------------------
                */

            'receipt_date'     => [
                'required',
                'date',
            ],

            'amount_received'  => [
                'required',
                'numeric',
                'gt:0',
            ],

            'payment_mode'     => [
                'required',

                Rule::in([
                    'cash',
                    'cheque',
                    'bank',
                    'online',
                ]),
            ],

            'reference_number' => [
                'nullable',
                'string',
                'max:255',
            ],

            'description'      => [
                'nullable',
                'string',
            ],

            'status'           => [
                'nullable',

                Rule::in([
                    'draft',
                    'completed',
                ]),
            ],

            'receipt_number'   => [
                'nullable',
                'string',
                'max:255',

                Rule::unique(
                    'receipts',
                    'receipt_number'
                )->ignore(
                    $receipt->id
                ),
            ],
        ]);

        /*
        |--------------------------------------------------------------------------
        | Resolve Customer
        |--------------------------------------------------------------------------
        */

        if (
            empty(
                $validated[
                    'customer_id'
                ]
            )
            &&
            ! empty(
                $validated[
                    'customer_name'
                ]
            )
        ) {
            $validated[
                'customer_id'
            ] =
            $this->resolveCustomerId(
                $validated[
                    'customer_name'
                ]
            );
        }

        if (
            empty(
                $validated[
                    'customer_id'
                ]
            )
        ) {
            return response()->json(
                [
                    'message' =>
                    'Customer not found.',
                ],
                422
            );
        }

        /*
        |--------------------------------------------------------------------------
        | Update + Rebuild Journal
        |--------------------------------------------------------------------------
        */

        DB::transaction(
            function () use (
                $receipt,
                $validated
            ) {
                $receipt->update(
                    $validated
                );

                $receipt->refresh();

                $this->postJournal(
                    $receipt
                );
            }
        );

        return response()->json(
            $receipt->load(
                'customer'
            )
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Delete Receipt
    |--------------------------------------------------------------------------
    */

    public function destroy(
        Receipt $receipt
    ) {
        $this->ensureReceiptCompanyAccess(
            $receipt->company_id
        );

        DB::transaction(
            function () use (
                $receipt
            ) {
                /*
                 * Remove accounting journal first.
                 */
                $this
                    ->postingService
                    ->deleteForReference(
                        $receipt->company_id,
                        Receipt::class,
                        $receipt->id
                    );

                $receipt->delete();
            }
        );

        return response()->json([
            'message' =>
            'Receipt deleted',
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | Resolve Customer
    |--------------------------------------------------------------------------
    */

    private function resolveCustomerId(
        string $name
    ): ?int {
        $customerId =
        Customer::query()
            ->where(
                'company_id',
                (int)
                auth()
                    ->user()
                    ->company_id
            )
            ->where(
                'name',
                trim(
                    $name
                )
            )
            ->value(
                'id'
            );

        return $customerId
            ? (int)
        $customerId
            : null;
    }

    /*
    |--------------------------------------------------------------------------
    | Receipt Company Guard
    |--------------------------------------------------------------------------
    |
    | IMPORTANT:
    |
    | Do NOT rename this back to ensureCompanyAccess().
    |
    | Your current base Controller already has a method using that name.
    | Keeping a unique method name prevents the same inheritance error that
    | occurred in PaymentController.
    |
    */

    private function ensureReceiptCompanyAccess(
        mixed $companyId
    ): void {
        $authenticatedCompanyId =
        (int)
        auth()
            ->user()
            ->company_id;

        if (
            (int)
            $companyId !==
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
    | Completed Customer Receipt:
    |
    | Dr Cash / Bank
    |     Cr Customer / Accounts Receivable
    |
    |
    | Draft Receipt:
    |
    | No journal.
    |
    */

    private function postJournal(
        Receipt $receipt
    ): void {
        /*
        |--------------------------------------------------------------------------
        | Draft = No Accounting Effect
        |--------------------------------------------------------------------------
        */

        if (
            $receipt->status !==
            'completed'
        ) {
            $this
                ->postingService
                ->deleteForReference(
                    $receipt->company_id,
                    Receipt::class,
                    $receipt->id
                );

            return;
        }

        /*
        |--------------------------------------------------------------------------
        | Entry Date
        |--------------------------------------------------------------------------
        */

        $entryDate =
        $receipt->receipt_date;

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
            (string)
                $entryDate;
        }

        if (
            ! $entryDate
        ) {
            $entryDate =
            now()
                ->toDateString();
        }

        /*
        |--------------------------------------------------------------------------
        | Journal
        |--------------------------------------------------------------------------
        */

        $this
            ->postingService
            ->post([
                'company_id'     =>
                $receipt->company_id,

                'reference_type' =>
                Receipt::class,

                'reference_id'   =>
                $receipt->id,

                'entry_date'     =>
                $entryDate,

                'description'    =>
                "Customer Receipt #{$receipt->receipt_number}",

                'created_by' =>
                $receipt->recorded_by,

                'lines'      => [
                    /*
                    |--------------------------------------------------------------------------
                    | Debit Cash / Bank
                    |--------------------------------------------------------------------------
                    */

                    [
                        'key'       =>
                        $this->receiptMethodKey(
                            $receipt
                                ->payment_mode
                        ),

                        'debit'     =>
                        (float)
                        $receipt
                            ->amount_received,

                        'credit'    =>
                        0,

                        'narration' =>
                        $receipt
                            ->description
                            ?:
                        "Customer receipt {$receipt->receipt_number}",
                    ],

                    /*
                    |--------------------------------------------------------------------------
                    | Credit Customer / Accounts Receivable
                    |--------------------------------------------------------------------------
                    */

                    [
                        'customer_id' =>
                        $receipt
                            ->customer_id,

                        'debit'       =>
                        0,

                        'credit'      =>
                        (float)
                        $receipt
                            ->amount_received,

                        'narration'   =>
                        $receipt
                            ->description
                            ?:
                        "Customer receipt {$receipt->receipt_number}",
                    ],
                ],
            ]);
    }

    /*
    |--------------------------------------------------------------------------
    | Resolve Cash / Bank Accounting Key
    |--------------------------------------------------------------------------
    |
    | Canonical values:
    |
    | cash
    | cheque
    | bank
    | online
    |
    | Accounting treatment:
    |
    | cash    -> Cash
    | cheque  -> Bank
    | bank    -> Bank
    | online  -> Bank
    |
    */

    private function receiptMethodKey(
        ?string $paymentMode
    ): string {
        $mode =
            strtolower(
            trim(
                (string)
                $paymentMode
            )
        );

        return match (
            $mode
        ) {
            'bank',
            'cheque',
            'online' =>
            'bank',

            default  =>
            'cash',
        };
    }
}
