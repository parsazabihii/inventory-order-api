<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StockMovement extends Model
{
    protected $fillable = ['user_id', 'product_id', 'warehouse_id', 'order_id', 'type', 'quantity', 'on_hand_delta', 'reserved_delta', 'reason', 'idempotency_key', 'request_hash'];

    protected $hidden = ['request_hash', 'idempotency_key'];

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    public function warehouse()
    {
        return $this->belongsTo(Warehouse::class);
    }
}
