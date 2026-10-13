<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Order;
use App\Models\Product;
use App\Models\Stock;
use App\Models\StockMovement;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\InventoryService;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class ConcurrencyTest extends TestCase
{
    use DatabaseMigrations;

    private function fixture(): array
    {
        $u = User::create(['name' => 'Manager', 'email' => 'concurrent@example.test', 'password' => 'StrongPassword123', 'role' => 'manager']);
        $c = Category::create(['name' => 'Test']);
        $w = Warehouse::create(['name' => 'Test', 'code' => 'TEST']);
        $p = Product::create(['name' => 'Last stock', 'sku' => 'LAST', 'category_id' => $c->id, 'price_cents' => 100]);
        Stock::create(['warehouse_id' => $w->id, 'product_id' => $p->id, 'on_hand' => 5]);

        return [$u, ['warehouse_id' => $w->id, 'customer_name' => 'Concurrent Customer', 'customer_email' => null, 'items' => [['product_id' => $p->id, 'quantity' => 4]]]];
    }

    private function race(array $requests): array
    {
        $dir = sys_get_temp_dir().'/inventory-race-'.bin2hex(random_bytes(8));
        mkdir($dir);
        $processes = [];
        try {
            foreach ($requests as $i => $request) {
                file_put_contents("$dir/$i.json", json_encode($request));
                $process = new Process([PHP_BINARY, base_path('tests/Support/concurrent_worker.php'), "$dir/$i.json", "$dir/$i.ready", "$dir/go"], base_path(), ['APP_ENV' => 'testing', 'DB_CONNECTION' => 'pgsql', 'DB_DATABASE' => 'inventory_order_test']);
                $process->setTimeout(20);
                $process->start();
                $processes[] = $process;
            }
            $deadline = microtime(true) + 10;
            while (count(glob("$dir/*.ready")) !== count($requests)) {
                foreach ($processes as $p) {
                    if (! $p->isRunning()) {
                        $this->fail('Worker failed: '.$p->getErrorOutput());
                    }
                }
                if (microtime(true) > $deadline) {
                    $this->fail('Worker startup timed out.');
                }
                usleep(10000);
                clearstatcache();
            }
            file_put_contents("$dir/go", 'go');
            $results = [];
            foreach ($processes as $p) {
                $p->wait();
                $this->assertTrue($p->isSuccessful(), $p->getErrorOutput());
                $results[] = json_decode($p->getOutput(), true, 512, JSON_THROW_ON_ERROR);
            }

            return $results;
        } finally {
            foreach ($processes as $p) {
                if ($p->isRunning()) {
                    $p->stop();
                }
            } foreach (glob("$dir/*") as $file) {
                unlink($file);
            } rmdir($dir);
        }
    }

    public function test_competing_orders_cannot_oversell(): void
    {
        [$u,$data] = $this->fixture();
        $r = $this->race([['op' => 'create', 'user_id' => $u->id, 'data' => $data, 'key' => 'race-order-0001'], ['op' => 'create', 'user_id' => $u->id, 'data' => $data, 'key' => 'race-order-0002']]);
        $statuses = array_column($r, 'status');
        sort($statuses);
        $this->assertSame([200, 409], $statuses);
        $this->assertSame(1, Order::count());
        $this->assertSame(4, Stock::first()->reserved);
        $this->assertSame(5, Stock::first()->on_hand);
    }

    public function test_concurrent_identical_keys_only_create_one_order(): void
    {
        [$u,$data] = $this->fixture();
        $request = ['op' => 'create', 'user_id' => $u->id, 'data' => $data, 'key' => 'same-race-0001'];
        $r = $this->race([$request, $request]);
        $this->assertSame([200, 200], array_column($r, 'status'));
        $this->assertSame($r[0]['id'], $r[1]['id']);
        $this->assertSame(1, Order::count());
        $this->assertSame(4, Stock::first()->reserved);
    }

    public function test_cancellation_and_fulfilment_cannot_both_apply(): void
    {
        [$u,$data] = $this->fixture();
        [$order] = app(InventoryService::class)->createOrder($u, $data, 'transition-race');
        $r = $this->race([['op' => 'cancelled', 'user_id' => $u->id, 'order_id' => $order->id], ['op' => 'fulfilled', 'user_id' => $u->id, 'order_id' => $order->id]]);
        $statuses = array_column($r, 'status');
        sort($statuses);
        $this->assertSame([200, 409], $statuses);
        $order->refresh();
        $this->assertSame(0, Stock::first()->reserved);
        $this->assertSame($order->status === 'fulfilled' ? 1 : 5, Stock::first()->on_hand);
        $this->assertSame(1, StockMovement::whereIn('type', ['release', 'fulfil'])->count());
    }
}
