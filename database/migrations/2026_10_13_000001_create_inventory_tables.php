<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $t) {
            $t->string('role', 20)->default('sales');
            $t->boolean('is_active')->default(true);
        });
        Schema::create('personal_access_tokens', function (Blueprint $t) {
            $t->id();
            $t->morphs('tokenable');
            $t->text('name');
            $t->string('token', 64)->unique();
            $t->text('abilities')->nullable();
            $t->timestamp('last_used_at')->nullable();
            $t->timestamp('expires_at')->nullable()->index();
            $t->timestamps();
        });
        Schema::create('categories', function (Blueprint $t) {
            $t->id();
            $t->string('name', 100)->unique();
            $t->timestamps();
        });
        Schema::create('warehouses', function (Blueprint $t) {
            $t->id();
            $t->string('name', 100);
            $t->string('code', 40)->unique();
            $t->timestamps();
        });
        Schema::create('products', function (Blueprint $t) {
            $t->id();
            $t->foreignId('category_id')->constrained()->restrictOnDelete();
            $t->string('name', 150);
            $t->string('sku', 64)->unique();
            $t->bigInteger('price_cents');
            $t->integer('low_stock_threshold')->default(5);
            $t->timestamps();
        });
        Schema::create('stocks', function (Blueprint $t) {
            $t->id();
            $t->foreignId('warehouse_id')->constrained()->restrictOnDelete();
            $t->foreignId('product_id')->constrained()->restrictOnDelete();
            $t->bigInteger('on_hand')->default(0);
            $t->bigInteger('reserved')->default(0);
            $t->unique(['warehouse_id', 'product_id']);
            $t->timestamps();
        });
        Schema::create('orders', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->constrained()->restrictOnDelete();
            $t->foreignId('warehouse_id')->constrained()->restrictOnDelete();
            $t->string('customer_name', 100);
            $t->string('customer_email')->nullable();
            $t->string('status', 20)->default('reserved');
            $t->bigInteger('total_cents');
            $t->string('idempotency_key', 100);
            $t->string('request_hash', 64);
            $t->unique(['user_id', 'idempotency_key']);
            $t->index(['user_id', 'status', 'created_at']);
            $t->timestamps();
        });
        Schema::create('order_items', function (Blueprint $t) {
            $t->id();
            $t->foreignId('order_id')->constrained()->restrictOnDelete();
            $t->foreignId('product_id')->constrained()->restrictOnDelete();
            $t->integer('quantity');
            $t->bigInteger('unit_price_cents');
            $t->unique(['order_id', 'product_id']);
            $t->timestamps();
        });
        Schema::create('stock_movements', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->constrained()->restrictOnDelete();
            $t->foreignId('warehouse_id')->constrained()->restrictOnDelete();
            $t->foreignId('product_id')->constrained()->restrictOnDelete();
            $t->foreignId('order_id')->nullable()->constrained()->restrictOnDelete();
            $t->string('type', 20);
            $t->bigInteger('quantity');
            $t->bigInteger('on_hand_delta');
            $t->bigInteger('reserved_delta');
            $t->string('reason', 255)->nullable();
            $t->string('idempotency_key', 100)->nullable();
            $t->string('request_hash', 64)->nullable();
            $t->unique(['user_id', 'idempotency_key']);
            $t->index(['warehouse_id', 'product_id', 'created_at']);
            $t->timestamps();
        });
        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE users ADD CONSTRAINT users_role_check CHECK (role IN ('manager','warehouse','sales'))");
            DB::statement('ALTER TABLE stocks ADD CONSTRAINT stock_balance_check CHECK (on_hand >= 0 AND reserved >= 0 AND reserved <= on_hand)');
            DB::statement('ALTER TABLE products ADD CONSTRAINT product_values_check CHECK (price_cents >= 0 AND low_stock_threshold >= 0)');
            DB::statement("ALTER TABLE orders ADD CONSTRAINT order_values_check CHECK (total_cents >= 0 AND status IN ('reserved','fulfilled','cancelled'))");
            DB::statement('ALTER TABLE order_items ADD CONSTRAINT item_values_check CHECK (quantity > 0 AND unit_price_cents >= 0)');
            DB::statement("ALTER TABLE stock_movements ADD CONSTRAINT movement_type_check CHECK (type IN ('receipt','issue','reserve','release','fulfil'))");
        }
    }

    public function down(): void
    {
        foreach (['stock_movements', 'order_items', 'orders', 'stocks', 'products', 'warehouses', 'categories', 'personal_access_tokens'] as $table) {
            Schema::dropIfExists($table);
        }
        Schema::table('users', fn (Blueprint $t) => $t->dropColumn(['role', 'is_active']));
    }
};
