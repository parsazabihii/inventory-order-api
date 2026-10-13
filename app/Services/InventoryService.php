<?php

namespace App\Services;

use App\Models\Order;
use App\Models\Product;
use App\Models\Stock;
use App\Models\StockMovement;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class InventoryService
{
    private function lockKey(string $key): void
    {
        // Transaction-scoped lock serializes the same idempotency key, even before a row exists.
        DB::select('SELECT pg_advisory_xact_lock(hashtextextended(?, 0))', [$key]);
    }

    private function stock(int $warehouse, int $product): Stock
    {
        DB::table('stocks')->insertOrIgnore(['warehouse_id' => $warehouse, 'product_id' => $product, 'on_hand' => 0, 'reserved' => 0, 'created_at' => now(), 'updated_at' => now()]);

        return Stock::where('warehouse_id', $warehouse)->where('product_id', $product)->lockForUpdate()->firstOrFail();
    }

    private function hash(array $data): string
    {
        return hash('sha256', json_encode($data, JSON_THROW_ON_ERROR));
    }

    public function movement(User $actor, array $data, string $key): array
    {
        return DB::transaction(function () use ($actor, $data, $key) {
            $this->lockKey('movement:'.$actor->id.':'.$key);
            $hash = $this->hash($data);
            $existing = StockMovement::where('user_id', $actor->id)->where('idempotency_key', $key)->first();
            if ($existing) {
                abort_if($existing->request_hash !== $hash, 409, 'Idempotency key was already used with different data.');

                return [$existing, false];
            }
            $stock = $this->stock($data['warehouse_id'], $data['product_id']);
            $delta = $data['type'] === 'receipt' ? $data['quantity'] : -$data['quantity'];
            abort_if($stock->on_hand + $delta < $stock->reserved, 409, 'Insufficient available stock.');
            $stock->on_hand += $delta;
            $stock->save();
            $movement = StockMovement::create([...$data, 'user_id' => $actor->id, 'on_hand_delta' => $delta, 'reserved_delta' => 0, 'idempotency_key' => $key, 'request_hash' => $hash]);

            return [$movement, true];
        }, 3);
    }

    public function createOrder(User $actor, array $data, string $key): array
    {
        // Canonical item order makes retries stable and avoids cross-product lock inversions.
        usort($data['items'], fn ($a, $b) => $a['product_id'] <=> $b['product_id']);

        return DB::transaction(function () use ($actor, $data, $key) {
            $this->lockKey('order:'.$actor->id.':'.$key);
            $hash = $this->hash($data);
            $existing = Order::where('user_id', $actor->id)->where('idempotency_key', $key)->first();
            if ($existing) {
                abort_if($existing->request_hash !== $hash, 409, 'Idempotency key was already used with different data.');

                return [$existing->load('items'), false];
            }
            // Lock product prices in sorted order before stock rows; snapshot prices server-side.
            $products = Product::whereIn('id', array_column($data['items'], 'product_id'))->orderBy('id')->sharedLock()->get()->keyBy('id');
            abort_if($products->count() !== count($data['items']), 409, 'A product is no longer available.');
            $stocks = [];
            $total = 0;
            foreach ($data['items'] as $item) {
                $stock = $this->stock($data['warehouse_id'], $item['product_id']);
                abort_if($stock->available < $item['quantity'], 409, 'Insufficient available stock for product '.$item['product_id'].'.');
                $stocks[$item['product_id']] = $stock;
                $total += $products[$item['product_id']]->price_cents * $item['quantity'];
            }
            $order = Order::create([...collect($data)->except('items')->all(), 'user_id' => $actor->id, 'total_cents' => $total, 'status' => 'reserved', 'idempotency_key' => $key, 'request_hash' => $hash]);
            foreach ($data['items'] as $item) {
                $stock = $stocks[$item['product_id']];
                $stock->reserved += $item['quantity'];
                $stock->save();
                $order->items()->create([...$item, 'unit_price_cents' => $products[$item['product_id']]->price_cents]);
                StockMovement::create(['user_id' => $actor->id, 'warehouse_id' => $order->warehouse_id, 'product_id' => $item['product_id'], 'order_id' => $order->id, 'type' => 'reserve', 'quantity' => $item['quantity'], 'on_hand_delta' => 0, 'reserved_delta' => $item['quantity']]);
            }

            return [$order->load('items'), true];
        }, 3);
    }

    public function transition(User $actor, int $id, string $target): Order
    {
        return DB::transaction(function () use ($actor, $id, $target) {
            $order = Order::lockForUpdate()->findOrFail($id);
            abort_if($actor->role === 'sales' && $order->user_id !== $actor->id, 404);
            if ($order->status === $target) {
                return $order->load('items');
            }
            abort_if($order->status !== 'reserved', 409, 'Only a reserved order can change status.');
            foreach ($order->items()->orderBy('product_id')->get() as $item) {
                $stock = $this->stock($order->warehouse_id, $item->product_id);
                $stock->reserved -= $item->quantity;
                $delta = $target === 'fulfilled' ? -$item->quantity : 0;
                $stock->on_hand += $delta;
                $stock->save();
                StockMovement::create(['user_id' => $actor->id, 'warehouse_id' => $order->warehouse_id, 'product_id' => $item->product_id, 'order_id' => $order->id, 'type' => $target === 'fulfilled' ? 'fulfil' : 'release', 'quantity' => $item->quantity, 'on_hand_delta' => $delta, 'reserved_delta' => -$item->quantity]);
            }
            $order->status = $target;
            $order->save();

            return $order->load('items');
        }, 3);
    }
}
