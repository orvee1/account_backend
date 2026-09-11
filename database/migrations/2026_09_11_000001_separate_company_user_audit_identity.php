<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    private array $columns = [
        'chart_accounts' => ['created_by', 'updated_by'], 'products' => ['created_by', 'updated_by'],
        'sales_invoices' => ['created_by'], 'sales_orders' => ['created_by'],
        'sales_returns' => ['created_by'], 'sales_payments' => ['created_by'],
        'employees' => ['created_by', 'updated_by'], 'salary_setups' => ['created_by', 'updated_by'],
        'payroll_runs' => ['created_by', 'processed_by', 'undo_by'], 'payslips' => ['created_by'],
        'company_users' => ['created_by', 'updated_by', 'deleted_by'], 'account_reconciliations' => ['user_id'],
    ];

    public function up(): void
    {
        foreach ($this->columns as $name => $columns) {
            Schema::table($name, function (Blueprint $table) use ($columns) {
                foreach ($columns as $column) {
                    $table->foreignId($column.'_company_user_id')->nullable()->constrained('company_users')->restrictOnDelete();
                }
            });
        }
        Schema::table('account_reconciliations', fn (Blueprint $table) => $table->unsignedBigInteger('user_id')->nullable()->change());
    }

    public function down(): void
    {
        foreach ($this->columns as $name => $columns) {
            foreach ($columns as $column) {
                if (DB::table($name)->whereNotNull($column.'_company_user_id')->exists()) {
                    throw new RuntimeException('Cannot remove populated company audit identities. Restore a pre-migration backup or retain this migration.');
                }
            }
        }
        foreach ($this->columns as $name => $columns) {
            Schema::table($name, function (Blueprint $table) use ($columns) {
                foreach ($columns as $column) $table->dropConstrainedForeignId($column.'_company_user_id');
            });
        }
        Schema::table('account_reconciliations', fn (Blueprint $table) => $table->unsignedBigInteger('user_id')->nullable(false)->change());
    }
};
