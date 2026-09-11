<?php
namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AccountingIdempotency
{
    public function handle(Request $request, Closure $next)
    {
        $key = $request->header('Idempotency-Key');
        $resources = ['purchase-bills','purchase-returns','sales-invoices','sales-returns','sales-payments','receipts','payments'];
        if (!$key || !$request->isMethod('POST') || !in_array($request->segment(2), $resources, true)) return $next($request);
        abort_if(strlen($key) > 128, 422, 'Idempotency-Key must be at most 128 characters.');
        $company = $request->user()->company_id;
        $fingerprint = hash('sha256', $request->user()->id.'|'.$request->path().'|'.$request->getContent());
        return DB::transaction(function () use ($request, $next, $key, $company, $fingerprint) {
            DB::table('accounting_request_keys')->insertOrIgnore(['company_id'=>$company,'request_key'=>$key,'fingerprint'=>$fingerprint]);
            $query = DB::table('accounting_request_keys')->where('company_id',$company)->where('request_key',$key);
            $saved = (clone $query)->lockForUpdate()->first();
            abort_unless(hash_equals($saved->fingerprint,$fingerprint),409,'Idempotency key was already used for a different request.');
            if ($saved->status) return response($saved->response,$saved->status,['Content-Type'=>'application/json','Idempotency-Replayed'=>'true']);
            $response = $next($request);
            if ($response->getStatusCode() >= 400) $query->delete();
            else $query->update(['status'=>$response->getStatusCode(),'response'=>$response->getContent()]);
            return $response;
        }, 3);
    }
}
