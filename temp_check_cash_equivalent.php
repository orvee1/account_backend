<?php
require __DIR__ . '/vendor/autoload.php';
$app = require __DIR__ . '/bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

use App\Models\ChartAccount;

$cashEquivalent = ChartAccount::where('name', 'Cash & Cash Equivalent')->first();
if (! $cashEquivalent) {
    echo "Cash & Cash Equivalent not found\n";
    exit(1);
}

echo "Cash & Cash Equivalent: {$cashEquivalent->id} | {$cashEquivalent->name} | {$cashEquivalent->code} | parent=" . ($cashEquivalent->parent_id ?? 'null') . " | path={$cashEquivalent->path} | depth={$cashEquivalent->depth}\n";
$children = $cashEquivalent->children;
echo "Children count: " . $children->count() . "\n";
foreach ($children as $child) {
    echo "- {$child->id} | {$child->name} | {$child->code} | parent={$child->parent_id} | type={$child->type} | path={$child->path}\n";
}
