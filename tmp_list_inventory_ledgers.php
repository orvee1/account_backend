<?php
require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();
$companyId = 1;
$ledgers = App\Models\ChartAccount::where('company_id', $companyId)
    ->where('type', 'ledger')
    ->where(function($q) {
        $q->where('slug', 'inventory')
          ->orWhere('name', 'Inventory');
    })->get();

echo json_encode($ledgers->toArray(), JSON_PRETTY_PRINT);
