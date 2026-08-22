<?php
require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$product = App\Models\Product::find(17);
if (! $product) {
    echo "Product 17 not found\n";
    exit(1);
}

$service = app(App\Services\ProductOpeningStockService::class);
$je = $service->createOpeningStockJournal($product, 5, 250, date('Y-m-d'));

if (! $je) {
    echo "No journal created (maybe already exists or invalid input)\n";
    exit(0);
}

$je = App\Models\JournalEntry::with('lines')->find($je->id);
echo json_encode($je->toArray(), JSON_PRETTY_PRINT);
