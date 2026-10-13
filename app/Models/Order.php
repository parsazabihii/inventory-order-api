<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Order extends Model
{
    protected $fillable = ['user_id', 'warehouse_id', 'customer_name', 'customer_email', 'status', 'total_cents', 'idempotency_key', 'request_hash'];

    protected $hidden = ['request_hash', 'idempotency_key'];

    protected function casts(): array
    {
        return ['total_cents' => 'integer'];
    }

    public function items()
    {
        return $this->hasMany(OrderItem::class);
    }

    public function warehouse()
    {
        return $this->belongsTo(Warehouse::class);
    }
}
