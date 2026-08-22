<?php
require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();
$svc = app(App\Services\ProductOpeningStockService::class);
$a = $svc->getStockInHandAccount(1);
echo json_encode($a? $a->toArray() : null, JSON_PRETTY_PRINT);
