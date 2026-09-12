<?php
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ChartAccount;
use App\Models\JournalLine;
use App\Models\Payment;
use App\Models\ProductStock;
use App\Models\PurchaseBill;
use App\Models\SalesInvoice;
use App\Models\SalesReturn;
use App\Models\Vendor;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ReportController extends Controller
{
    public function incomeStatement(Request $request)
    {
        $companyId = $this->companyId();
        $startDate = $request->query(
            'start_date',
            Carbon::now()->startOfYear()->toDateString()
        );
        $endDate = $request->query(
            'end_date',
            Carbon::now()->toDateString()
        );

        $accountTotals = $this->accountTotalsForPeriod(
            $companyId,
            $startDate,
            $endDate
        );

        $revenue = [
            'salesRevenue'   => 0,
            'serviceRevenue' => 0,
            'otherIncome'    => 0,
        ];

        $costOfGoods = [
            'costOfSales' => 0,
            'directCosts' => 0,
        ];

        $expenses = [
            'salaries'      => 0,
            'rent'          => 0,
            'utilities'     => 0,
            'marketing'     => 0,
            'depreciation'  => 0,
            'otherExpenses' => 0,
        ];

        foreach ($accountTotals as $row) {
            $code   = $row['code'] ?? '';
            $name   = strtolower($row['name'] ?? '');
            $debit  = $row['debit'];
            $credit = $row['credit'];

            if ($this->isIncomeAccount($code)) {
                $amount = $credit - $debit;

                if ($amount === 0) {
                    continue;
                }

                if (str_contains($name, 'sales')) {
                    $revenue['salesRevenue'] += $amount;
                } elseif (str_contains($name, 'service')) {
                    $revenue['serviceRevenue'] += $amount;
                } else {
                    $revenue['otherIncome'] += $amount;
                }

                continue;
            }

            if ($this->isExpenseAccount($code)) {
                $amount = $debit - $credit;

                if ($amount === 0) {
                    continue;
                }

                if (
                    str_starts_with($code, '5.1')
                    || str_contains($name, 'cost of sales')
                    || str_contains($name, 'cost of goods')
                ) {
                    $costOfGoods['costOfSales'] += $amount;
                } elseif (str_contains($name, 'direct')) {
                    $costOfGoods['directCosts'] += $amount;
                } elseif (
                    str_contains($name, 'salary')
                    || str_contains($name, 'wage')
                ) {
                    $expenses['salaries'] += $amount;
                } elseif (str_contains($name, 'rent')) {
                    $expenses['rent'] += $amount;
                } elseif (str_contains($name, 'utility')) {
                    $expenses['utilities'] += $amount;
                } elseif (
                    str_contains($name, 'marketing')
                    || str_contains($name, 'advertisement')
                    || str_contains($name, 'promotion')
                ) {
                    $expenses['marketing'] += $amount;
                } elseif (
                    str_contains($name, 'depreciation')
                    || str_contains($name, 'amortization')
                ) {
                    $expenses['depreciation'] += $amount;
                } else {
                    $expenses['otherExpenses'] += $amount;
                }
            }
        }

        /*
        |--------------------------------------------------------------------------
        | Sales / COGS Breakdown
        |--------------------------------------------------------------------------
        |
        | Existing income-statement values remain NET ledger values.
        |
        | The accounting test workbook additionally requires:
        |
        | Gross Sales
        | Less Sales Return
        | Net Sales
        |
        | Gross COGS
        | Less Sales-return COGS reversal
        | Net COGS
        |
        */

        $salesBreakdown = $this->salesBreakdown(
            $companyId,
            $startDate,
            $endDate
        );

        return response()->json([
            'period'         => [
                'start_date' => $startDate,
                'end_date'   => $endDate,
            ],

            'revenue'        => $this->roundObject(
                $revenue
            ),

            'costOfGoods'    => $this->roundObject(
                $costOfGoods
            ),

            'expenses'       => $this->roundObject(
                $expenses
            ),

            'salesBreakdown' => $this->roundObject(
                $salesBreakdown
            ),
        ]);
    }

    public function balanceSheet(Request $request)
    {
        $companyId = $this->companyId();

        $asOfDate = $request->query(
            'as_of_date',
            Carbon::now()->toDateString()
        );

        $balances = $this->accountBalancesAtDate(
            $companyId,
            $asOfDate
        );

        $assets = [
            'current' => [
                'cash'               => 0,
                'accountsReceivable' => 0,
                'inventory'          => 0,
                'prepaidExpenses'    => 0,
            ],

            'fixed'   => [
                'propertyPlantEquipment'  => 0,
                'accumulatedDepreciation' => 0,
                'intangibleAssets'        => 0,
            ],

            'other'   => [
                'longTermInvestments' => 0,
                'deferredTaxAssets'   => 0,
            ],
        ];

        $liabilities = [
            'current'  => [
                'accountsPayable'      => 0,
                'shortTermDebt'        => 0,
                'accruedExpenses'      => 0,
                'currentPortionLTDebt' => 0,
            ],

            'longTerm' => [
                'longTermDebt'           => 0,
                'deferredTaxLiabilities' => 0,
            ],
        ];

        $equity = [
            'commonStock'              => 0,
            'retainedEarnings'         => 0,
            'currentProfitLoss'        => 0,
            'otherComprehensiveIncome' => 0,
        ];

        foreach ($balances as $row) {
            $code    = $row['code'] ?? '';
            $name    = strtolower($row['name'] ?? '');
            $balance = $row['balance'];

            if ($this->isAssetAccount($code)) {
                if (
                    str_starts_with($code, '1.1.1')
                    || str_contains($name, 'cash')
                    || str_contains($name, 'bank')
                ) {
                    $assets['current']['cash'] += $balance;
                } elseif (
                    str_starts_with($code, '1.1.4')
                    || str_contains($name, 'receivable')
                ) {
                    $assets['current']['accountsReceivable'] += $balance;
                } elseif (
                    str_starts_with($code, '1.1.5')
                    || str_contains($name, 'inventory')
                ) {
                    $assets['current']['inventory'] += $balance;
                } elseif (
                    str_starts_with($code, '1.1.7')
                    || str_contains($name, 'prepaid')
                ) {
                    $assets['current']['prepaidExpenses'] += $balance;
                } elseif (
                    str_starts_with($code, '1.2.1')
                    && str_contains($name, 'depreciation')
                ) {
                    $assets['fixed']['accumulatedDepreciation'] += $balance;
                } elseif (
                    str_starts_with($code, '1.2.1')
                ) {
                    $assets['fixed']['propertyPlantEquipment'] += $balance;
                } elseif (
                    str_starts_with($code, '1.2.2')
                    || str_contains($name, 'intangible')
                ) {
                    $assets['fixed']['intangibleAssets'] += $balance;
                } elseif (
                    str_starts_with($code, '1.2.3')
                    || str_contains($name, 'investment')
                ) {
                    $assets['other']['longTermInvestments'] += $balance;
                } elseif (
                    str_contains($name, 'deferred tax')
                ) {
                    $assets['other']['deferredTaxAssets'] += $balance;
                } else {
                    $assets['other']['longTermInvestments'] += $balance;
                }

                continue;
            }

            if ($this->isLiabilityAccount($code)) {
                if (
                    str_starts_with($code, '2.1.1')
                    || str_contains($name, 'payable')
                ) {
                    $liabilities['current']['accountsPayable'] += $balance;
                } elseif (
                    str_starts_with($code, '2.1.2')
                    || str_contains($name, 'short-term')
                ) {
                    $liabilities['current']['shortTermDebt'] += $balance;
                } elseif (
                    str_starts_with($code, '2.1.3')
                    || str_contains($name, 'accrued')
                ) {
                    $liabilities['current']['accruedExpenses'] += $balance;
                } elseif (
                    str_contains($name, 'current portion')
                ) {
                    $liabilities['current']['currentPortionLTDebt'] += $balance;
                } elseif (
                    str_starts_with($code, '2.2.1')
                    || str_contains($name, 'long-term loan')
                    || str_contains($name, 'long term')
                ) {
                    $liabilities['longTerm']['longTermDebt'] += $balance;
                } elseif (
                    str_contains($name, 'deferred tax')
                ) {
                    $liabilities['longTerm']['deferredTaxLiabilities'] += $balance;
                } else {
                    $liabilities['longTerm']['longTermDebt'] += $balance;
                }

                continue;
            }

            if ($this->isEquityAccount($code)) {
                if (
                    str_contains($name, 'capital')
                    || str_contains($name, 'share')
                ) {
                    $equity['commonStock'] += $balance;
                } elseif (
                    str_contains($name, 'retained')
                ) {
                    $equity['retainedEarnings'] += $balance;
                } else {
                    $equity['otherComprehensiveIncome'] += $balance;
                }
            }
        }

        /*
        |--------------------------------------------------------------------------
        | Current Year Profit / Loss
        |--------------------------------------------------------------------------
        */

        $equity['currentProfitLoss'] =
        $this->computeNetIncome(
            $companyId,
            Carbon::parse($asOfDate)
                ->startOfYear()
                ->toDateString(),
            $asOfDate
        );

        return response()->json([
            'as_of_date'  => $asOfDate,

            'assets'      =>
            $this->roundNestedObject(
                $assets
            ),

            'liabilities' =>
            $this->roundNestedObject(
                $liabilities
            ),

            'equity'      =>
            $this->roundObject(
                $equity
            ),
        ]);
    }

    public function trialBalance(Request $request)
    {
        $companyId =
        $this->companyId();

        $asOfDate =
        $request->query(
            'as_of_date',
            Carbon::now()->toDateString()
        );

        $accountTotals =
        $this->accountTotalsForPeriod(
            $companyId,
            null,
            $asOfDate
        );

        $accounts    = [];
        $totalDebit  = 0;
        $totalCredit = 0;

        foreach (
            $accountTotals as $row
        ) {
            $debit =
                $row['debit'];

            $credit =
                $row['credit'];

            $totalDebit +=
                $debit;

            $totalCredit +=
                $credit;

            $accounts[] = [
                'name'   =>
                $row['name'] ?? '',

                'code'   =>
                $row['code'] ?? '',

                'debit'  =>
                $this->roundNumber(
                    $debit
                ),

                'credit' =>
                $this->roundNumber(
                    $credit
                ),
            ];
        }

        return response()->json([
            'as_of_date'  =>
            $asOfDate,

            'accounts'    =>
            $accounts,

            'totalDebit'  =>
            $this->roundNumber(
                $totalDebit
            ),

            'totalCredit' =>
            $this->roundNumber(
                $totalCredit
            ),
        ]);
    }

    public function ownersEquity(Request $request)
    {
        $companyId =
        $this->companyId();

        $asOfDate =
        $request->query(
            'as_of_date',
            Carbon::now()->toDateString()
        );

        $startDate =
        $request->query(
            'start_date',
            Carbon::now()
                ->startOfYear()
                ->toDateString()
        );

        $balances =
        $this->accountBalancesAtDate(
            $companyId,
            $asOfDate
        );

        $equityItems = [];
        $totalEquity = 0;

        foreach (
            $balances as $row
        ) {
            if (
                ! $this->isEquityAccount(
                    $row['code'] ?? ''
                )
            ) {
                continue;
            }

            $totalEquity +=
                $row['balance'];

            $equityItems[] = [
                'name'   =>
                $row['name'] ?? '',

                'amount' =>
                $this->roundNumber(
                    $row['balance']
                ),
            ];
        }

        $netIncome =
        $this->computeNetIncome(
            $companyId,
            $startDate,
            $asOfDate
        );

        return response()->json([
            'period'    => [
                'start_date' =>
                $startDate,

                'end_date'   =>
                $asOfDate,
            ],

            'equity'    => [
                'items' =>
                $equityItems,

                'total' =>
                $this->roundNumber(
                    $totalEquity
                ),
            ],

            'netIncome' =>
            $this->roundNumber(
                $netIncome
            ),
        ]);
    }

    public function stockReport(Request $request)
    {
        $companyId =
        $this->companyId();

        $stocks =
        ProductStock::query()
            ->select([
                'product_stocks.id',
                'product_stocks.product_id',
                'product_stocks.warehouse_id',
                'product_stocks.quantity_on_hand',
                'product_stocks.avg_cost',
                'products.name as product_name',
                'products.unit as product_unit',
                'products.costing_price',
                'categories.name as category_name',
                'warehouses.name as warehouse_name',
            ])
            ->join(
                'products',
                'product_stocks.product_id',
                '=',
                'products.id'
            )
            ->leftJoin(
                'categories',
                'products.category_id',
                '=',
                'categories.id'
            )
            ->leftJoin(
                'warehouses',
                'product_stocks.warehouse_id',
                '=',
                'warehouses.id'
            )
            ->where(
                'product_stocks.company_id',
                $companyId
            )
            ->when(
                $request->filled(
                    'warehouse_id'
                ),
                fn($q) =>
                $q->where(
                    'product_stocks.warehouse_id',
                    $request->integer(
                        'warehouse_id'
                    )
                )
            )
            ->when(
                $request->filled(
                    'category_id'
                ),
                fn($q) =>
                $q->where(
                    'products.category_id',
                    $request->integer(
                        'category_id'
                    )
                )
            )
            ->orderBy(
                'products.name'
            )
            ->get();

        $products = [];

        $totalQuantity = 0;
        $totalValue    = 0;

        $allWarehouses = [];
        $allCategories = [];

        foreach ($stocks as $row) {
            $quantity =
            (float)
            $row->quantity_on_hand;

            $unitCost =
            (float) (
                $row->avg_cost ?? $row->costing_price ?? 0
            );

            $total =
                $quantity *
                $unitCost;

            $totalQuantity +=
                $quantity;

            $totalValue +=
                $total;

            $warehouseName =
            $row->warehouse_name ?? 'Unknown';

            $categoryName =
            $row->category_name ?? 'Uncategorized';

            $allWarehouses[
                $warehouseName
            ] = true;

            $allCategories[
                $categoryName
            ] = true;

            $products[] = [
                'id'         =>
                $row->product_id,

                'name'       =>
                $row->product_name,

                'category'   =>
                $categoryName,

                'warehouse'  =>
                $warehouseName,

                'quantity'   =>
                $this->roundNumber(
                    $quantity
                ),

                'unit'       =>
                $row->product_unit
                    ?: 'Unit',

                'unitCost'   =>
                $this->roundNumber(
                    $unitCost
                ),

                'totalValue' =>
                $this->roundNumber(
                    $total
                ),

                'minStock'   =>
                0,

                'status'     =>
                'Good',
            ];
        }

        return response()->json([
            'products'      =>
            $products,

            'totalQuantity' =>
            $this->roundNumber(
                $totalQuantity
            ),

            'totalValue'    =>
            $this->roundNumber(
                $totalValue
            ),

            'allWarehouses' =>
            array_keys(
                $allWarehouses
            ),

            'allCategories' =>
            array_keys(
                $allCategories
            ),
        ]);
    }

    public function cashFlow(Request $request)
    {
        $companyId =
        $this->companyId();

        $fromDate =
        $request->query(
            'from_date',
            Carbon::now()
                ->startOfYear()
                ->toDateString()
        );

        $toDate =
        $request->query(
            'to_date',
            Carbon::now()
                ->toDateString()
        );

        $netIncome =
        $this->computeNetIncome(
            $companyId,
            $fromDate,
            $toDate
        );

        $depreciation =
        $this->sumExpenseByName(
            $companyId,
            $fromDate,
            $toDate,
            [
                'depreciation',
            ]
        );

        $amortization =
        $this->sumExpenseByName(
            $companyId,
            $fromDate,
            $toDate,
            [
                'amortization',
            ]
        );

        $changeAccountsReceivable =
        $this->balanceChangeImpact(
            $companyId,
            $fromDate,
            $toDate,
            [
                '1.1.4',
            ],
            [
                'receivable',
            ],
            -1
        );

        $changeInventory =
        $this->balanceChangeImpact(
            $companyId,
            $fromDate,
            $toDate,
            [
                '1.1.5',
            ],
            [
                'inventory',
            ],
            -1
        );

        $changeAccountsPayable =
        $this->balanceChangeImpact(
            $companyId,
            $fromDate,
            $toDate,
            [
                '2.1.1',
            ],
            [
                'payable',
            ],
            1
        );

        $changeAccruedExpenses =
        $this->balanceChangeImpact(
            $companyId,
            $fromDate,
            $toDate,
            [
                '2.1.3',
            ],
            [
                'accrued',
            ],
            1
        );

        $changeWorkingCapital =
            $changeAccountsReceivable
             + $changeInventory
             + $changeAccountsPayable
             + $changeAccruedExpenses;

        $capitalExpenditures =
        $this->balanceChangeImpact(
            $companyId,
            $fromDate,
            $toDate,
            [
                '1.2.1',
            ],
            [
                'fixed',
            ],
            -1
        );

        $investmentDelta =
        $this->balanceChangeImpact(
            $companyId,
            $fromDate,
            $toDate,
            [
                '1.2.3',
            ],
            [
                'investment',
            ],
            -1
        );

        $purchaseInvestments =
        $investmentDelta < 0
            ? $investmentDelta
            : 0;

        $saleInvestments =
        $investmentDelta > 0
            ? $investmentDelta
            : 0;

        $acquisitionOfAssets =
            0;

        $debtDelta =
        $this->balanceChangeImpact(
            $companyId,
            $fromDate,
            $toDate,
            [
                '2.2.1',
            ],
            [
                'loan',
            ],
            1
        );

        $proceedsFromDebt =
        $debtDelta > 0
            ? $debtDelta
            : 0;

        $paymentOfDebt =
        $debtDelta < 0
            ? $debtDelta
            : 0;

        $issuedCommonStock =
        $this->balanceChangeImpact(
            $companyId,
            $fromDate,
            $toDate,
            [
                '3.4',
            ],
            [
                'share',
            ],
            1
        );

        $dividendsPaid =
        $this->balanceChangeImpact(
            $companyId,
            $fromDate,
            $toDate,
            [
                '3.2',
            ],
            [
                'drawings',
            ],
            -1
        );

        $interestPaid =
        $this->sumExpenseByName(
            $companyId,
            $fromDate,
            $toDate,
            [
                'interest',
            ]
        ) * -1;

        $beginningCash =
        $this->cashBalanceAtDate(
            $companyId,
            Carbon::parse(
                $fromDate
            )
                ->subDay()
                ->toDateString()
        );

        return response()->json([
            'period'                   => [
                'from_date' =>
                $fromDate,

                'to_date'   =>
                $toDate,
            ],

            'netIncome'                =>
            $this->roundNumber(
                $netIncome
            ),

            'depreciation'             =>
            $this->roundNumber(
                $depreciation
            ),

            'amortization'             =>
            $this->roundNumber(
                $amortization
            ),

            'changeAccountsReceivable' =>
            $this->roundNumber(
                $changeAccountsReceivable
            ),

            'changeInventory'          =>
            $this->roundNumber(
                $changeInventory
            ),

            'changeAccountsPayable'    =>
            $this->roundNumber(
                $changeAccountsPayable
            ),

            'changeAccruedExpenses'    =>
            $this->roundNumber(
                $changeAccruedExpenses
            ),

            'changeWorkingCapital'     =>
            $this->roundNumber(
                $changeWorkingCapital
            ),

            'capitalExpenditures'      =>
            $this->roundNumber(
                $capitalExpenditures
            ),

            'purchaseInvestments'      =>
            $this->roundNumber(
                $purchaseInvestments
            ),

            'saleInvestments'          =>
            $this->roundNumber(
                $saleInvestments
            ),

            'acquisitionOfAssets'      =>
            $this->roundNumber(
                $acquisitionOfAssets
            ),

            'proceedsFromDebt'         =>
            $this->roundNumber(
                $proceedsFromDebt
            ),

            'paymentOfDebt'            =>
            $this->roundNumber(
                $paymentOfDebt
            ),

            'issuedCommonStock'        =>
            $this->roundNumber(
                $issuedCommonStock
            ),

            'dividendsPaid'            =>
            $this->roundNumber(
                $dividendsPaid
            ),

            'interestPaid'             =>
            $this->roundNumber(
                $interestPaid
            ),

            'beginningCash'            =>
            $this->roundNumber(
                $beginningCash
            ),
        ]);
    }

    public function vendorLedger(Request $request)
    {
        $companyId =
        $this->companyId();

        $fromDate =
        $request->query(
            'from_date',
            Carbon::now()
                ->startOfYear()
                ->toDateString()
        );

        $toDate =
        $request->query(
            'to_date',
            Carbon::now()
                ->toDateString()
        );

        $vendorId =
        $request->query(
            'vendor_id'
        );

        $vendors =
        Vendor::query()
            ->where(
                'company_id',
                $companyId
            )
            ->when(
                $vendorId
                && $vendorId !==
                'all',
                fn($q) =>
                $q->where(
                    'id',
                    $vendorId
                )
            )
            ->orderBy(
                'name'
            )
            ->get();

        $vendorTransactions = [];
        $vendorSummaries    = [];
        $totalOutstanding   = 0;

        foreach (
            $vendors as $vendor
        ) {
            $transactions =
                collect();

            /*
            |--------------------------------------------------------------------------
            | Purchase Bills
            |--------------------------------------------------------------------------
            */

            $bills =
            PurchaseBill::query()
                ->where(
                    'company_id',
                    $companyId
                )
                ->where(
                    'vendor_id',
                    $vendor->id
                )
                ->when(
                    $fromDate,
                    fn($q) =>
                    $q->whereDate(
                        'bill_date',
                        '>=',
                        $fromDate
                    )
                )
                ->when(
                    $toDate,
                    fn($q) =>
                    $q->whereDate(
                        'bill_date',
                        '<=',
                        $toDate
                    )
                )
                ->get();

            foreach (
                $bills as $bill
            ) {
                $transactions->push([
                    'date'        =>
                    $bill->bill_date
                        ? $bill
                        ->bill_date
                        ->toDateString()
                        : null,

                    'ref'         =>
                    $bill->bill_no ?? 'BILL',

                    'description' =>
                    $bill->notes
                        ?: 'Purchase Bill',

                    'debit'       =>
                    0,

                    'credit'      =>
                    (float)
                    $bill->total_amount,

                    'type'        =>
                    'bill',
                ]);
            }

            /*
            |--------------------------------------------------------------------------
            | Vendor Payments
            |--------------------------------------------------------------------------
            */

            $payments =
            Payment::query()
                ->where(
                    'company_id',
                    $companyId
                )
                ->where(
                    'vendor_id',
                    $vendor->id
                )
                ->when(
                    $fromDate,
                    fn($q) =>
                    $q->whereDate(
                        'payment_date',
                        '>=',
                        $fromDate
                    )
                )
                ->when(
                    $toDate,
                    fn($q) =>
                    $q->whereDate(
                        'payment_date',
                        '<=',
                        $toDate
                    )
                )
                ->get();

            foreach (
                $payments as $payment
            ) {
                $transactions->push([
                    'date'        =>
                    $payment->payment_date
                        ? $payment
                        ->payment_date
                        ->toDateString()
                        : null,

                    'ref'         =>
                    $payment->payment_number ?? 'PAY',

                    'description' =>
                    $payment->description
                        ?: 'Payment',

                    'debit'       =>
                    (float)
                    $payment->amount_paid,

                    'credit'      =>
                    0,

                    'type'        =>
                    'payment',
                ]);
            }

            $transactions =
            $transactions
                ->sortBy(
                    'date'
                )
                ->values();

            $runningBalance =
                0;

            $transactions =
            $transactions
                ->map(
                    function (
                        $txn
                    ) use (
                        &$runningBalance
                    ) {
                        $runningBalance +=
                            (
                            $txn['debit']
                             -
                            $txn['credit']
                        );

                        $txn['balance'] =
                        $this->roundNumber(
                            $runningBalance
                        );

                        $txn['debit'] =
                        $this->roundNumber(
                            $txn['debit']
                        );

                        $txn['credit'] =
                        $this->roundNumber(
                            $txn['credit']
                        );

                        return $txn;
                    }
                );

            $totalPurchases =
            $transactions
                ->sum(
                    'credit'
                );

            $totalPayments =
            $transactions
                ->sum(
                    'debit'
                );

            $balance =
            $transactions
                ->last()['balance'] ?? 0;

            $outstanding =
                max(
                0,
                $balance * -1
            );

            $vendorSummaries[] = [
                'vendor'         =>
                $vendor->name,

                'balance'        =>
                $this->roundNumber(
                    abs(
                        $balance
                    )
                ),

                'totalPurchases' =>
                $this->roundNumber(
                    $totalPurchases
                ),

                'totalPayments'  =>
                $this->roundNumber(
                    $totalPayments
                ),

                'outstanding'    =>
                $this->roundNumber(
                    $outstanding
                ),
            ];

            $totalOutstanding +=
                $outstanding;

            $vendorTransactions[] = [
                'vendor'       =>
                $vendor->name,

                'transactions' =>
                $transactions
                    ->values()
                    ->all(),
            ];
        }

        return response()->json([
            'vendorTransactions' =>
            $vendorTransactions,

            'vendorSummaries'    =>
            $vendorSummaries,

            'totalOutstanding'   =>
            $this->roundNumber(
                $totalOutstanding
            ),

            'allVendors'         =>
            $vendors
                ->pluck(
                    'name'
                )
                ->values()
                ->all(),
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | Sales / COGS Breakdown
    |--------------------------------------------------------------------------
    |
    | This method is deliberately based on JOURNAL ENTRIES instead of invoice
    | document totals.
    |
    | That means the financial report remains tied to the actual accounting
    | ledger and catches posting problems rather than hiding them.
    |
    | Example from the accounting test plan:
    |
    | Sales Invoice 1:
    |     8 × 65,000 = 520,000
    |
    | Sales Invoice 2:
    |     4 × 60,000 = 240,000
    |
    | Gross Sales:
    |     760,000
    |
    | Sales Return:
    |     1 × 60,000 = 60,000
    |
    | Net Sales:
    |     700,000
    |
    |
    | Gross COGS:
    |     416,000 + 205,600
    |     = 621,600
    |
    | Sales-return COGS reversal:
    |     51,400
    |
    | Net COGS:
    |     570,200
    |
    */

    private function salesBreakdown(
        int $companyId,
        string $startDate,
        string $endDate
    ): array {
        $lines =
        JournalLine::query()
            ->with([
                'account:id,name,code,base_type,normal_balance',
                'journalEntry:id,entry_date,reference_type',
            ])
            ->where(
                'company_id',
                $companyId
            )
            ->whereHas(
                'journalEntry',
                function (
                    $query
                ) use (
                    $startDate,
                    $endDate
                ) {
                    $query
                        ->whereDate(
                            'entry_date',
                            '>=',
                            $startDate
                        )
                        ->whereDate(
                            'entry_date',
                            '<=',
                            $endDate
                        )
                        ->whereIn(
                            'reference_type',
                            [
                                SalesInvoice::class,
                                SalesReturn::class,
                            ]
                        );
                }
            )
            ->get();

        $grossSales =
            0.0;

        $salesReturns =
            0.0;

        $grossCostOfSales =
            0.0;

        $salesReturnCostReversal =
            0.0;

        foreach ($lines as $line) {
            $account =
            $line->account;

            $journalEntry =
            $line->journalEntry;

            if (
                ! $account
                || ! $journalEntry
            ) {
                continue;
            }

            $code =
            (string) (
                $account->code ?? ''
            );

            $name =
                strtolower(
                (string) (
                    $account->name ?? ''
                )
            );

            $referenceType =
            $journalEntry
                ->reference_type;

            $debit =
            (float)
            $line->debit;

            $credit =
            (float)
            $line->credit;

            /*
            |--------------------------------------------------------------------------
            | Sales Revenue Ledger
            |--------------------------------------------------------------------------
            |
            | Prefer accounting classification, but additionally guard using
            | either sales naming or the project's 4.1 revenue branch.
            |
            */

            $isSalesRevenue =
            $this->isIncomeAccount(
                $code,
                $account
            )
                && (
                str_contains(
                    $name,
                    'sales'
                )
                || str_starts_with(
                    $code,
                    '4.1'
                )
            );

            /*
            |--------------------------------------------------------------------------
            | Cost of Sales / COGS Ledger
            |--------------------------------------------------------------------------
            */

            $isCostOfSales =
            $this->isExpenseAccount(
                $code,
                $account
            )
                && (
                str_starts_with(
                    $code,
                    '5.1'
                )
                || str_contains(
                    $name,
                    'cost of sales'
                )
                || str_contains(
                    $name,
                    'cost of goods'
                )
                || str_contains(
                    $name,
                    'cogs'
                )
            );

            /*
            |--------------------------------------------------------------------------
            | Normal Sales Invoice
            |--------------------------------------------------------------------------
            */

            if (
                $referenceType ===
                SalesInvoice::class
            ) {
                if (
                    $isSalesRevenue
                ) {
                    $grossSales +=
                        $credit
                         - $debit;
                }

                if (
                    $isCostOfSales
                ) {
                    $grossCostOfSales +=
                        $debit
                         - $credit;
                }

                continue;
            }

            /*
            |--------------------------------------------------------------------------
            | Sales Return
            |--------------------------------------------------------------------------
            |
            | Return journal:
            |
            | Dr Sales Revenue
            |     Cr Accounts Receivable
            |
            | Dr Inventory
            |     Cr COGS
            |
            */

            if (
                $referenceType ===
                SalesReturn::class
            ) {
                if (
                    $isSalesRevenue
                ) {
                    $salesReturns +=
                        $debit
                         - $credit;
                }

                if (
                    $isCostOfSales
                ) {
                    $salesReturnCostReversal +=
                        $credit
                         - $debit;
                }
            }
        }

        return [
            'grossSales'              =>
            $grossSales,

            'salesReturns'            =>
            $salesReturns,

            'netSales'                =>
            $grossSales
             - $salesReturns,

            'grossCostOfSales'        =>
            $grossCostOfSales,

            'salesReturnCostReversal' =>
            $salesReturnCostReversal,

            'netCostOfSales'          =>
            $grossCostOfSales
             - $salesReturnCostReversal,
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | Current Company
    |--------------------------------------------------------------------------
    */

    private function companyId(): int
    {
        return (int) (
            auth()
                ->user()
                ->company_id ?? 0
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Account Totals for Period
    |--------------------------------------------------------------------------
    */

    private function accountTotalsForPeriod(
        int $companyId,
        ?string $startDate,
        string $endDate
    ): array {
        $query =
        JournalLine::query()
            ->select([
                'account_id',

                DB::raw(
                    'SUM(debit) as debit'
                ),

                DB::raw(
                    'SUM(credit) as credit'
                ),
            ])
            ->where(
                'company_id',
                $companyId
            )
            ->whereHas(
                'journalEntry',
                function (
                    $q
                ) use (
                    $startDate,
                    $endDate
                ) {
                    if (
                        $startDate
                    ) {
                        $q->whereDate(
                            'entry_date',
                            '>=',
                            $startDate
                        );
                    }

                    $q->whereDate(
                        'entry_date',
                        '<=',
                        $endDate
                    );
                }
            )
            ->groupBy(
                'account_id'
            );

        $totals =
        $query->get();

        $accounts =
        ChartAccount::query()
            ->whereIn(
                'id',
                $totals->pluck(
                    'account_id'
                )
            )
            ->get([
                'id',
                'name',
                'code',
                'base_type',
                'normal_balance',
            ]);

        $accountMap =
        $accounts->keyBy(
            'id'
        );

        return $totals
            ->map(
                function (
                    $row
                ) use (
                    $accountMap
                ) {
                    $account =
                    $accountMap->get(
                        $row->account_id
                    );

                    return [
                        'account_id'     =>
                        $row->account_id,

                        'name'           =>
                        $account?->name,

                        'code'           =>
                        $account?->code,

                        'base_type'      =>
                        $account?->base_type,

                        'normal_balance' =>
                        $account?->normal_balance,

                        'debit'          =>
                        (float)
                        $row->debit,

                        'credit'         =>
                        (float)
                        $row->credit,

                        'account'        =>
                        $account,
                    ];
                }
            )
            ->all();
    }

    /*
    |--------------------------------------------------------------------------
    | Account Balances at Date
    |--------------------------------------------------------------------------
    */

    private function accountBalancesAtDate(
        int $companyId,
        string $asOfDate
    ): array {
        $totals =
        JournalLine::query()
            ->select([
                'account_id',

                DB::raw(
                    'SUM(debit) as debit'
                ),

                DB::raw(
                    'SUM(credit) as credit'
                ),
            ])
            ->where(
                'company_id',
                $companyId
            )
            ->whereHas(
                'journalEntry',
                fn($q) =>
                $q->whereDate(
                    'entry_date',
                    '<=',
                    $asOfDate
                )
            )
            ->groupBy(
                'account_id'
            )
            ->get();

        $accounts =
        ChartAccount::query()
            ->whereIn(
                'id',
                $totals->pluck(
                    'account_id'
                )
            )
            ->get([
                'id',
                'name',
                'code',
                'base_type',
                'normal_balance',
            ]);

        $accountMap =
        $accounts->keyBy(
            'id'
        );

        return $totals
            ->map(
                function (
                    $row
                ) use (
                    $accountMap
                ) {
                    $account =
                    $accountMap->get(
                        $row->account_id
                    );

                    $code =
                    $account?->code ?? '';

                    $isDebitNormal =
                        false;

                    if (
                        $account
                        && $account->normal_balance
                    ) {
                        $isDebitNormal =
                            (
                            $account
                                ->normal_balance ===
                            'debit'
                        );
                    } else {
                        $isDebitNormal =
                        str_starts_with(
                            $code,
                            '1'
                        )
                        || str_starts_with(
                            $code,
                            '5'
                        );
                    }

                    $balance =
                    $isDebitNormal
                        ? (
                        (float)
                        $row->debit
                         -
                        (float)
                        $row->credit
                    )
                        : (
                        (float)
                        $row->credit
                         -
                        (float)
                        $row->debit
                    );

                    return [
                        'account_id'     =>
                        $row->account_id,

                        'name'           =>
                        $account?->name,

                        'code'           =>
                        $code,

                        'base_type'      =>
                        $account?->base_type,

                        'normal_balance' =>
                        $account?->normal_balance,

                        'balance'        =>
                        $balance,

                        'account'        =>
                        $account,
                    ];
                }
            )
            ->all();
    }

    /*
    |--------------------------------------------------------------------------
    | Net Income
    |--------------------------------------------------------------------------
    */

    private function computeNetIncome(
        int $companyId,
        string $startDate,
        string $endDate
    ): float {
        $accountTotals =
        $this->accountTotalsForPeriod(
            $companyId,
            $startDate,
            $endDate
        );

        $income =
            0;

        $expense =
            0;

        foreach (
            $accountTotals as $row
        ) {
            if (
                $this->isIncomeAccount(
                    $row['code'] ?? '',
                    $row['account'] ?? null
                )
            ) {
                $income +=
                    (
                    $row['credit']
                     -
                    $row['debit']
                );
            } elseif (
                $this->isExpenseAccount(
                    $row['code'] ?? '',
                    $row['account'] ?? null
                )
            ) {
                $expense +=
                    (
                    $row['debit']
                     -
                    $row['credit']
                );
            }
        }

        return $income -
            $expense;
    }

    /*
    |--------------------------------------------------------------------------
    | Expense Sum by Name
    |--------------------------------------------------------------------------
    */

    private function sumExpenseByName(
        int $companyId,
        string $startDate,
        string $endDate,
        array $keywords
    ): float {
        $accountTotals =
        $this->accountTotalsForPeriod(
            $companyId,
            $startDate,
            $endDate
        );

        $sum = 0;

        foreach (
            $accountTotals as $row
        ) {
            if (
                ! $this->isExpenseAccount(
                    $row['code'] ?? '',
                    $row['account'] ?? null
                )
            ) {
                continue;
            }

            $name =
                strtolower(
                $row['name'] ?? ''
            );

            foreach (
                $keywords as $keyword
            ) {
                if (
                    str_contains(
                        $name,
                        $keyword
                    )
                ) {
                    $sum +=
                        (
                        $row['debit']
                         -
                        $row['credit']
                    );

                    break;
                }
            }
        }

        return $sum;
    }

    /*
    |--------------------------------------------------------------------------
    | Balance Change Impact
    |--------------------------------------------------------------------------
    */

    private function balanceChangeImpact(
        int $companyId,
        string $startDate,
        string $endDate,
        array $codePrefixes,
        array $keywords,
        int $multiplier = 1
    ): float {
        $startBalance =
        $this->balanceForAccounts(
            $companyId,
            Carbon::parse(
                $startDate
            )
                ->subDay()
                ->toDateString(),
            $codePrefixes,
            $keywords
        );

        $endBalance =
        $this->balanceForAccounts(
            $companyId,
            $endDate,
            $codePrefixes,
            $keywords
        );

        $change =
            $endBalance
             - $startBalance;

        return $change *
            $multiplier;
    }

    /*
    |--------------------------------------------------------------------------
    | Balance for Accounts
    |--------------------------------------------------------------------------
    */

    private function balanceForAccounts(
        int $companyId,
        string $asOfDate,
        array $codePrefixes,
        array $keywords
    ): float {
        $balances =
        $this->accountBalancesAtDate(
            $companyId,
            $asOfDate
        );

        $sum =
            0;

        foreach (
            $balances as $row
        ) {
            $code =
            $row['code'] ?? '';

            $name =
                strtolower(
                $row['name'] ?? ''
            );

            $account =
            $row['account'] ?? null;

            $match =
                false;

            /*
            |--------------------------------------------------------------------------
            | Match Code Prefix
            |--------------------------------------------------------------------------
            */

            foreach (
                $codePrefixes as $prefix
            ) {
                if (
                    str_starts_with(
                        $code,
                        $prefix
                    )
                ) {
                    $match =
                        true;

                    break;
                }
            }

            /*
            |--------------------------------------------------------------------------
            | Match Base Type
            |--------------------------------------------------------------------------
            */

            if (! $match) {
                if (
                    $account
                    && $account->base_type
                ) {
                    foreach (
                        $codePrefixes as $prefix
                    ) {
                        if (
                            $prefix == '1'
                            && $account->base_type == 'asset'
                        ) {
                            $match = true;
                        }

                        if (
                            $prefix == '2'
                            && $account->base_type == 'liability'
                        ) {
                            $match = true;
                        }

                        if (
                            $prefix == '3'
                            && $account->base_type == 'equity'
                        ) {
                            $match = true;
                        }

                        if (
                            $prefix == '4'
                            && $account->base_type == 'income'
                        ) {
                            $match = true;
                        }

                        if (
                            $prefix == '5'
                            && $account->base_type == 'expense'
                        ) {
                            $match = true;
                        }
                    }
                }
            }

            /*
            |--------------------------------------------------------------------------
            | Match Name Keywords
            |--------------------------------------------------------------------------
            */

            if (! $match) {
                foreach (
                    $keywords as $keyword
                ) {
                    if (
                        str_contains(
                            $name,
                            $keyword
                        )
                    ) {
                        $match =
                            true;

                        break;
                    }
                }
            }

            if ($match) {
                $sum +=
                    $row['balance'];
            }
        }

        return $sum;
    }

    /*
    |--------------------------------------------------------------------------
    | Cash Balance
    |--------------------------------------------------------------------------
    */

    private function cashBalanceAtDate(
        int $companyId,
        string $asOfDate
    ): float {
        return $this->balanceForAccounts(
            $companyId,
            $asOfDate,

            [
                '1.1.1',
                '1.1.2',
                '1.1.3',
            ],

            [
                'cash',
                'bank',
            ]
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Account Type Helpers
    |--------------------------------------------------------------------------
    */

    private function isAssetAccount(
        string $code,
        ?ChartAccount $account = null
    ): bool {
        if (
            $account
            && $account->base_type
        ) {
            return $account->base_type ===
                'asset';
        }

        return str_starts_with(
            $code,
            '1'
        );
    }

    private function isLiabilityAccount(
        string $code,
        ?ChartAccount $account = null
    ): bool {
        if (
            $account
            && $account->base_type
        ) {
            return $account->base_type ===
                'liability';
        }

        return str_starts_with(
            $code,
            '2'
        );
    }

    private function isEquityAccount(
        string $code,
        ?ChartAccount $account = null
    ): bool {
        if (
            $account
            && $account->base_type
        ) {
            return $account->base_type ===
                'equity';
        }

        return str_starts_with(
            $code,
            '3'
        );
    }

    private function isIncomeAccount(
        string $code,
        ?ChartAccount $account = null
    ): bool {
        if (
            $account
            && $account->base_type
        ) {
            return $account->base_type ===
                'income';
        }

        return str_starts_with(
            $code,
            '4'
        );
    }

    private function isExpenseAccount(
        string $code,
        ?ChartAccount $account = null
    ): bool {
        if (
            $account
            && $account->base_type
        ) {
            return $account->base_type ===
                'expense';
        }

        return str_starts_with(
            $code,
            '5'
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Rounding Helpers
    |--------------------------------------------------------------------------
    */

    private function roundNumber(
        float $value
    ): float {
        return round(
            $value,
            2
        );
    }

    private function roundObject(
        array $data
    ): array {
        foreach (
            $data as $key => $value
        ) {
            $data[$key] =
            $this->roundNumber(
                (float) $value
            );
        }

        return $data;
    }

    private function roundNestedObject(
        array $data
    ): array {
        foreach (
            $data as $key => $value
        ) {
            if (
                is_array(
                    $value
                )
            ) {
                $data[$key] =
                $this->roundNestedObject(
                    $value
                );
            } else {
                $data[$key] =
                $this->roundNumber(
                    (float) $value
                );
            }
        }

        return $data;
    }
}
