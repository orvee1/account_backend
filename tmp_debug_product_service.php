<?php

require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$user = App\Models\User::find(1);
if (! $user) {
    echo json_encode(['error' => 'user not found']);
    exit(1);
}

app('auth')->guard('sanctum')->setUser($user);
$data = app(App\Services\ProductService::class)->paginate([])->toArray();
echo json_encode($data, JSON_PRETTY_PRINT);
