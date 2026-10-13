<?php

namespace App\Http\Controllers;

use App\Models\Stock;
use App\Models\StockMovement;
use App\Services\InventoryService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class InventoryController extends Controller
{
    private function filters(Request $r): array
    {
        return $r->validate(['warehouse_id' => 'sometimes|integer|exists:warehouses,id', 'product_id' => 'sometimes|integer|exists:products,id', 'per_page' => 'sometimes|integer|min:1|max:100', 'page' => 'sometimes|integer|min:1']);
    }

    public function stocks(Request $r)
    {
        $d = $this->filters($r);
        $q = Stock::with('product', 'warehouse');
        foreach (['warehouse_id', 'product_id'] as $f) {
            if (isset($d[$f])) {
                $q->where($f, $d[$f]);
            }
        }

        return $q->orderBy('id')->paginate($d['per_page'] ?? 20);
    }

    public function movements(Request $r)
    {
        $d = $this->filters($r);
        $q = StockMovement::with('product', 'warehouse');
        foreach (['warehouse_id', 'product_id'] as $f) {
            if (isset($d[$f])) {
                $q->where($f, $d[$f]);
            }
        }

        return $q->orderByDesc('id')->paginate($d['per_page'] ?? 20);
    }

    public function storeMovement(Request $r, InventoryService $service)
    {
        $d = $r->validate(['warehouse_id' => 'required|integer|exists:warehouses,id', 'product_id' => 'required|integer|exists:products,id', 'type' => 'required|in:receipt,issue', 'quantity' => 'required|integer|min:1|max:1000000', 'reason' => 'required|string|max:255']);
        $d = ['warehouse_id' => (int) $d['warehouse_id'], 'product_id' => (int) $d['product_id'], 'type' => $d['type'], 'quantity' => (int) $d['quantity'], 'reason' => $d['reason']];
        $key = $this->idempotency($r);
        [$movement,$created] = $service->movement($r->user(), $d, $key);

        return response()->json(['data' => $movement, 'replayed' => ! $created], $created ? 201 : 200);
    }

    public function lowStock(Request $r)
    {
        $d = $this->filters($r);
        // Include zero-stock products even when a stock row has never been created.
        $q = DB::table('warehouses')->crossJoin('products')->leftJoin('stocks', function ($j) {
            $j->on('stocks.warehouse_id', '=', 'warehouses.id')->on('stocks.product_id', '=', 'products.id');
        })
            ->selectRaw('warehouses.id AS warehouse_id, warehouses.name AS warehouse_name, products.id AS product_id, products.sku, products.name, products.low_stock_threshold, COALESCE(stocks.on_hand,0) AS on_hand, COALESCE(stocks.reserved,0) AS reserved, COALESCE(stocks.on_hand,0)-COALESCE(stocks.reserved,0) AS available')
            ->whereRaw('COALESCE(stocks.on_hand,0)-COALESCE(stocks.reserved,0) <= products.low_stock_threshold');
        if (isset($d['warehouse_id'])) {
            $q->where('warehouses.id', $d['warehouse_id']);
        }
        if (isset($d['product_id'])) {
            $q->where('products.id', $d['product_id']);
        }

        return $q->orderBy('warehouses.id')->orderBy('products.id')->paginate($d['per_page'] ?? 20);
    }

    private function idempotency(Request $r): string
    {
        $key = $r->header('Idempotency-Key');
        abort_unless(is_string($key) && preg_match('/^[A-Za-z0-9_.:-]{8,100}$/D', $key), 422, 'Provide an Idempotency-Key header of 8–100 ASCII letters, digits or _ . : -');

        return $key;
    }
}
