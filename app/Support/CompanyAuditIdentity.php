<?php

namespace App\Support;

use App\Models\CompanyUser;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

class CompanyAuditIdentity
{
    public const COLUMNS = [
        'chart_accounts' => ['created_by', 'updated_by'],
        'products' => ['created_by', 'updated_by'],
        'sales_invoices' => ['created_by'],
        'sales_orders' => ['created_by'],
        'sales_returns' => ['created_by'],
        'sales_payments' => ['created_by'],
        'employees' => ['created_by', 'updated_by'],
        'salary_setups' => ['created_by', 'updated_by'],
        'payroll_runs' => ['created_by', 'processed_by', 'undo_by'],
        'payslips' => ['created_by'],
        'company_users' => ['created_by', 'updated_by', 'deleted_by'],
        'account_reconciliations' => ['user_id'],
    ];

    public static function stamp(Model $model, bool $creating): void
    {
        // Sanctum saves token usage while resolving the actor. Do not resolve
        // authentication for unrelated models, which would recurse on that save.
        if (!isset(self::COLUMNS[$model->getTable()])) return;
        $actor = Auth::guard('sanctum')->user() ?? Auth::user();
        if (!$actor instanceof CompanyUser) return;

        foreach (self::COLUMNS[$model->getTable()] ?? [] as $column) {
            if (($creating && $column === 'created_by') ||
                (!$creating && $column === 'updated_by') || $model->isDirty($column)) {
                // Never reinterpret historical users.id values as company_users.id.
                $model->setAttribute($column.'_company_user_id', $actor->id);
                $model->setAttribute($column, null);
            }
        }
    }
}
