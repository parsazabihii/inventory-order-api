<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Product extends Model
{
    protected $attributes = ['low_stock_threshold' => 5];

    protected $fillable = ['name', 'sku', 'category_id', 'price_cents', 'low_stock_threshold'];

    protected function casts(): array
    {
        return ['price_cents' => 'integer', 'low_stock_threshold' => 'integer'];
    }

    public function category()
    {
        return $this->belongsTo(Category::class);
    }
}
