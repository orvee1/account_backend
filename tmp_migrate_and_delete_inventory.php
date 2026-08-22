<?php
require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$companyId = 1;
$svc = app(App\Services\ProductOpeningStockService::class);
$stock = $svc->getStockInHandAccount($companyId);

if (! $stock) {
    echo json_encode(['error' => 'Stock in Hand account not found'], JSON_PRETTY_PRINT);
    exit(1);
}

$toAccountId = $stock->id;

$ledgers = App\Models\ChartAccount::where('company_id', $companyId)
    ->where('type', 'ledger')
    ->where(function($q) {
        $q->where('slug', 'inventory')
          ->orWhere('name', 'Inventory');
    })->get();

$result = [];
foreach ($ledgers as $led) {
    $fromId = $led->id;
    // Reassign journal lines
    $updated = App\Models\JournalLine::where('account_id', $fromId)->update(['account_id' => $toAccountId]);

    // Soft-delete or delete the account — use delete()
    try {
        $led->delete();
        $deleted = true;
    } catch (Exception $e) {
        $deleted = false;
    }

    $result[] = [
        'from' => $fromId,
        'to' => $toAccountId,
        'journal_lines_migrated' => $updated,
        'deleted' => $deleted,
    ];
}

echo json_encode($result, JSON_PRETTY_PRINT);
