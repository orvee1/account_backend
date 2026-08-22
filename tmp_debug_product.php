<?php

require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$product = App\Models\Product::with(['units','productUoms.uom','baseUom','stocks'])->find(17);
echo json_encode($product ? $product->toArray() : null, JSON_PRETTY_PRINT);
