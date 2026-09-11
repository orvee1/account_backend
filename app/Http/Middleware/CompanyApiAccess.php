<?php

namespace App\Http\Middleware;

use App\Models\Company;
use App\Models\CompanyUser;
use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CompanyApiAccess
{
    public function handle(Request $request, Closure $next)
    {
        $user = $request->user();
        abort_unless($user instanceof CompanyUser && $user->status === 'active', 403, 'An active company user is required.');
        abort_unless(Company::whereKey($user->company_id)->where('status', 'active')->exists(), 403, 'Company access is inactive.');

        foreach ($request->route()->parameters() as $key => $value) {
            if ($value instanceof Company) abort_unless((int) $value->id === (int) $user->company_id, 404);
            elseif ($value instanceof Model && $value->getAttribute('company_id') !== null) {
                abort_unless((int) $value->company_id === (int) $user->company_id, 404);
            } elseif ($key === 'company') abort_unless((int) $value === (int) $user->company_id, 404);
        }
        if ($request->has('company_id')) abort_unless((int) $request->input('company_id') === (int) $user->company_id, 403);

        $resource = $request->segment(2);
        $selfService = in_array($resource, ['user', 'logout'], true);
        $read = in_array($request->method(), ['GET', 'HEAD', 'OPTIONS'], true);
        abort_unless(in_array($user->role, ['owner', 'admin', 'accountant', 'viewer'], true), 403);
        if (!$selfService) {
            if ($resource === 'company-users' || $resource === 'settings' && !$read) {
                abort_unless(in_array($user->role, ['owner', 'admin'], true), 403, 'Administrator access is required.');
            }
            if (!$read) abort_if($user->role === 'viewer', 403, 'Viewers cannot change accounting data.');
            if (!$read && $resource === 'company-users') {
                $target = $request->route('company_user') ?? $request->route('companyUser');
                if ($request->input('role') === 'owner' || $target instanceof CompanyUser && $target->role === 'owner') {
                    abort_unless($user->role === 'owner', 403, 'Only the owner can manage owner access.');
                }
            }
            if ($user->role !== 'owner' && is_array($user->permissions)) {
                $action = $read ? 'view' : (['POST' => 'create', 'PUT' => 'update', 'PATCH' => 'update', 'DELETE' => 'delete'][$request->method()] ?? 'update');
                $scope = str_replace('-', '_', $resource);
                abort_unless(in_array('*', $user->permissions, true) || in_array($action.'_'.$scope, $user->permissions, true), 403, 'This action is not permitted.');
            }
        }
        if (!$read) $this->checkReferences($request->all(), (int) $user->company_id);
        return $next($request);
    }

    private function checkReferences(array $data, int $companyId, string $prefix = ''): void
    {
        $tables = ['customer_id'=>'customers', 'vendor_id'=>'vendors', 'product_id'=>'products', 'warehouse_id'=>'warehouses', 'sales_invoice_id'=>'sales_invoices', 'account_id'=>'chart_accounts', 'bill_discount_account_id'=>'chart_accounts', 'invoice_discount_account_id'=>'chart_accounts'];
        foreach ($data as $key => $value) {
            if (is_array($value)) $this->checkReferences($value, $companyId, $prefix.$key.'.');
            elseif (isset($tables[$key]) && $value !== null && $value !== '') {
                if (!DB::table($tables[$key])->where('id', $value)->where('company_id', $companyId)->exists()) {
                    throw ValidationException::withMessages([$prefix.$key => ['The selected record does not belong to your company.']]);
                }
            }
        }
    }
}
