<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Services\InventoryService;
use Illuminate\Http\Request;

class OrderController extends Controller
{
    public function index(Request $r)
    {
        $d = $r->validate(['status' => 'sometimes|in:reserved,fulfilled,cancelled', 'warehouse_id' => 'sometimes|integer|exists:warehouses,id', 'per_page' => 'sometimes|integer|min:1|max:100', 'page' => 'sometimes|integer|min:1']);
        $q = Order::with('items', 'warehouse');
        if ($r->user()->role === 'sales') {
            $q->where('user_id', $r->user()->id);
        }
        foreach (['status', 'warehouse_id'] as $f) {
            if (isset($d[$f])) {
                $q->where($f, $d[$f]);
            }
        }

        return $q->orderByDesc('id')->paginate($d['per_page'] ?? 20);
    }

    public function show(Request $r, Order $order)
    {
        abort_if($r->user()->role === 'sales' && $order->user_id !== $r->user()->id, 404);

        return response()->json(['data' => $order->load('items', 'warehouse')]);
    }

    public function store(Request $r, InventoryService $service)
    {
        $d = $r->validate(['warehouse_id' => 'required|integer|exists:warehouses,id', 'customer_name' => 'required|string|max:100', 'customer_email' => 'nullable|email|max:255', 'items' => 'required|array|min:1|max:100', 'items.*' => 'required|array:product_id,quantity', 'items.*.product_id' => 'required|integer|distinct|exists:products,id', 'items.*.quantity' => 'required|integer|min:1|max:1000000']);
        // Normalize numeric types, nullable values and key order before fingerprinting.
        $d = ['warehouse_id' => (int) $d['warehouse_id'], 'customer_name' => $d['customer_name'], 'customer_email' => $d['customer_email'] ?? null, 'items' => array_map(fn ($i) => ['product_id' => (int) $i['product_id'], 'quantity' => (int) $i['quantity']], $d['items'])];
        $key = $r->header('Idempotency-Key');
        abort_unless(is_string($key) && preg_match('/^[A-Za-z0-9_.:-]{8,100}$/D', $key), 422, 'Provide an Idempotency-Key header of 8–100 ASCII letters, digits or _ . : -');
        [$order,$created] = $service->createOrder($r->user(), $d, $key);

        return response()->json(['data' => $order, 'replayed' => ! $created], $created ? 201 : 200);
    }

    public function cancel(Request $r, Order $order, InventoryService $s)
    {
        return response()->json(['data' => $s->transition($r->user(), $order->id, 'cancelled')]);
    }

    public function fulfil(Request $r, Order $order, InventoryService $s)
    {
        return response()->json(['data' => $s->transition($r->user(), $order->id, 'fulfilled')]);
    }
}
