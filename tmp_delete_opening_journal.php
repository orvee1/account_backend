<?php
require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();
$je = App\Models\JournalEntry::where('reference_type', App\Models\Product::class)->where('reference_id', 17)->first();
if (! $je) { echo "No journal to delete\n"; exit; }
$je->lines()->delete();
$je->delete();
echo "Deleted journal\n";
