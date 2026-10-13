<?php

use App\Models\User;
use App\Services\InventoryService;
use Illuminate\Contracts\Console\Kernel;
use Symfony\Component\HttpKernel\Exception\HttpException;

require __DIR__.'/../../vendor/autoload.php';
putenv('APP_ENV=testing');
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (config('database.connections.pgsql.database') !== 'inventory_order_test') {
    exit(3);
}
$d = json_decode(file_get_contents($argv[1]), true, 512, JSON_THROW_ON_ERROR);
file_put_contents($argv[2], 'ready');
$deadline = microtime(true) + 10;
while (! file_exists($argv[3])) {
    if (microtime(true) > $deadline) {
        exit(2);
    } usleep(10000);
    clearstatcache();
}
try {
    $actor = User::findOrFail($d['user_id']);
    $s = app(InventoryService::class);
    if ($d['op'] === 'create') {
        [$order,$created] = $s->createOrder($actor, $d['data'], $d['key']);
    } else {
        $order = $s->transition($actor, $d['order_id'], $d['op']);
    }
    echo json_encode(['status' => 200, 'id' => $order->id]);
} catch (HttpException $e) {
    echo json_encode(['status' => $e->getStatusCode()]);
} catch (Throwable $e) {
    fwrite(STDERR, $e::class.': '.$e->getMessage());
    exit(1);
}
