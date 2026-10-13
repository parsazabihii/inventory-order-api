<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Product;
use App\Models\Stock;
use App\Models\StockMovement;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ApiTest extends TestCase
{
    use RefreshDatabase;

    private User $manager;

    private Warehouse $warehouse;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();
        $this->manager = User::create(['name' => 'Manager', 'email' => 'manager@example.test', 'password' => 'StrongPassword123', 'role' => 'manager']);
        $category = Category::create(['name' => 'Electronics']);
        $this->warehouse = Warehouse::create(['name' => 'Main', 'code' => 'MAIN']);
        $this->product = Product::create(['name' => 'Keyboard', 'sku' => 'KB-01', 'category_id' => $category->id, 'price_cents' => 2599, 'low_stock_threshold' => 3]);
    }

    private function acting(string $role = 'manager'): User
    {
        $u = $role === 'manager' ? $this->manager : User::create(['name' => $role, 'email' => $role.'@example.test', 'password' => 'StrongPassword123', 'role' => $role]);
        Sanctum::actingAs($u);

        return $u;
    }

    private function stock(int $quantity = 10): Stock
    {
        return Stock::create(['warehouse_id' => $this->warehouse->id, 'product_id' => $this->product->id, 'on_hand' => $quantity]);
    }

    private function order(array $override = []): array
    {
        return array_replace(['warehouse_id' => $this->warehouse->id, 'customer_name' => 'Test Customer', 'items' => [['product_id' => $this->product->id, 'quantity' => 2]]], $override);
    }

    private function place(string $key = 'order-key-0001', array $data = [])
    {
        return $this->postJson('/api/v1/orders', $this->order($data), ['Idempotency-Key' => $key]);
    }

    public function test_postgresql_is_the_test_database(): void
    {
        $this->assertSame('pgsql', DB::getDriverName());
        $this->assertSame('inventory_order_test', DB::connection()->getDatabaseName());
    }

    public function test_unauthenticated_requests_are_rejected(): void
    {
        $this->getJson('/api/v1/products')->assertUnauthorized();
    }

    public function test_login_token_me_and_logout(): void
    {
        $res = $this->postJson('/api/v1/auth/login', ['email' => 'MANAGER@example.test', 'password' => 'StrongPassword123'])->assertOk()->assertJsonPath('expires_in', 86400);
        $token = $res->json('token');
        $this->assertDatabaseCount('personal_access_tokens', 1);
        $this->getJson('/api/v1/auth/me', ['Authorization' => 'Bearer '.$token])->assertOk()->assertJsonMissingPath('data.password');
        $this->postJson('/api/v1/auth/logout', [], ['Authorization' => 'Bearer '.$token])->assertNoContent();
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_wrong_password_and_disabled_login_are_rejected(): void
    {
        $this->postJson('/api/v1/auth/login', ['email' => $this->manager->email, 'password' => 'wrong'])->assertUnprocessable();
        $this->manager->update(['is_active' => false]);
        $this->postJson('/api/v1/auth/login', ['email' => $this->manager->email, 'password' => 'StrongPassword123'])->assertUnprocessable();
    }

    public function test_expired_token_is_rejected(): void
    {
        $token = $this->manager->createToken('expired', ['*'], now()->subMinute())->plainTextToken;
        $this->getJson('/api/v1/products', ['Authorization' => 'Bearer '.$token])->assertUnauthorized();
    }

    public function test_login_is_rate_limited(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/v1/auth/login', ['email' => 'unknown@example.test', 'password' => 'wrong'])->assertUnprocessable();
        }
        $this->postJson('/api/v1/auth/login', ['email' => 'unknown@example.test', 'password' => 'wrong'])->assertStatus(429);
    }

    public function test_catalog_crud_search_and_pagination(): void
    {
        $this->acting();
        $id = $this->postJson('/api/v1/categories', ['name' => 'Tools'])->assertCreated()->json('data.id');
        $this->getJson('/api/v1/categories/'.$id)->assertOk()->assertJsonPath('data.name', 'Tools');
        $this->patchJson('/api/v1/categories/'.$id, ['name' => 'Hand Tools'])->assertOk();
        $this->getJson('/api/v1/categories?q=hand&per_page=1')->assertOk()->assertJsonPath('total', 1);
        $this->deleteJson('/api/v1/categories/'.$id)->assertNoContent();
        $this->getJson('/api/v1/products?per_page=101')->assertUnprocessable();
    }

    public function test_product_validation_and_price_snapshot(): void
    {
        $this->acting();
        $this->stock();
        $this->postJson('/api/v1/products', ['name' => 'bad'])->assertUnprocessable()->assertJsonValidationErrors(['sku', 'category_id', 'price_cents']);
        $response = $this->place()->assertCreated()->assertJsonPath('data.total_cents', 5198)->assertJsonPath('data.items.0.unit_price_cents', 2599);
        $this->patchJson('/api/v1/products/'.$this->product->id, ['price_cents' => 3000])->assertOk();
        $this->getJson('/api/v1/orders/'.$response->json('data.id'))->assertJsonPath('data.total_cents', 5198);
    }

    public function test_referenced_catalog_deletion_returns_conflict(): void
    {
        $this->acting();
        $this->stock();
        $this->deleteJson('/api/v1/products/'.$this->product->id)->assertConflict();
        $this->deleteJson('/api/v1/warehouses/'.$this->warehouse->id)->assertConflict();
        $this->deleteJson('/api/v1/categories/'.$this->product->category_id)->assertConflict();
    }

    public function test_sales_cannot_manage_catalog_stock_or_users(): void
    {
        $this->acting('sales');
        $this->postJson('/api/v1/categories', ['name' => 'Denied'])->assertForbidden();
        $this->postJson('/api/v1/stock-movements', [])->assertForbidden();
        $this->getJson('/api/v1/users')->assertForbidden();
        $this->getJson('/api/v1/reports/low-stock')->assertForbidden();
    }

    public function test_manager_can_provision_and_disable_users(): void
    {
        $this->acting();
        $id = $this->postJson('/api/v1/users', ['name' => 'Sales', 'email' => 'SALES@example.test', 'password' => 'StrongPassword123', 'role' => 'sales'])->assertCreated()->assertJsonMissingPath('data.password')->json('data.id');
        $token = User::find($id)->createToken('test')->plainTextToken;
        $this->patchJson('/api/v1/users/'.$id, ['is_active' => false])->assertOk();
        $this->assertDatabaseMissing('personal_access_tokens', ['tokenable_id' => $id]);
        $this->assertFalse(User::find($id)->is_active);
    }

    public function test_last_active_manager_cannot_be_demoted(): void
    {
        $this->acting();
        $this->patchJson('/api/v1/users/'.$this->manager->id, ['role' => 'sales'])->assertConflict();
        $this->patchJson('/api/v1/users/'.$this->manager->id, ['is_active' => false])->assertConflict();
    }

    public function test_receipt_issue_replay_and_audit(): void
    {
        $actor = $this->acting('warehouse');
        $payload = ['warehouse_id' => $this->warehouse->id, 'product_id' => $this->product->id, 'type' => 'receipt', 'quantity' => 10, 'reason' => 'Delivery'];
        $headers = ['Idempotency-Key' => 'receipt-key-0001'];
        $this->postJson('/api/v1/stock-movements', $payload, $headers)->assertCreated()->assertJsonPath('data.user_id', $actor->id);
        $this->postJson('/api/v1/stock-movements', $payload, $headers)->assertOk()->assertJsonPath('replayed', true);
        $this->postJson('/api/v1/stock-movements', array_replace($payload, ['quantity' => 11]), $headers)->assertConflict();
        $this->assertDatabaseCount('stock_movements', 1);
        $this->postJson('/api/v1/stock-movements', array_replace($payload, ['type' => 'issue', 'quantity' => 3]), ['Idempotency-Key' => 'issue-key-0001'])->assertCreated();
        $this->assertSame(7, Stock::first()->on_hand);
        $this->getJson('/api/v1/stock-movements')->assertOk()->assertJsonPath('total', 2);
    }

    public function test_manual_issue_cannot_consume_reserved_stock(): void
    {
        $this->acting();
        $this->stock(3);
        $this->place()->assertCreated();
        $this->postJson('/api/v1/stock-movements', ['warehouse_id' => $this->warehouse->id, 'product_id' => $this->product->id, 'type' => 'issue', 'quantity' => 2, 'reason' => 'Manual withdrawal'], ['Idempotency-Key' => 'issue-key-0001'])->assertConflict();
        $this->assertSame(3, Stock::first()->on_hand);
    }

    public function test_order_idempotency_replays_without_duplicate_reservation(): void
    {
        $this->acting();
        $this->stock();
        $id = $this->place()->assertCreated()->json('data.id');
        $this->place()->assertOk()->assertJsonPath('data.id', $id)->assertJsonPath('replayed', true);
        $this->place('order-key-0001', ['customer_name' => 'Different'])->assertConflict();
        $this->assertDatabaseCount('orders', 1);
        $this->assertSame(2, Stock::first()->reserved);
    }

    public function test_idempotency_key_is_required_and_duplicate_products_invalid(): void
    {
        $this->acting();
        $this->stock();
        $this->postJson('/api/v1/orders', $this->order())->assertUnprocessable();
        $this->place('order-key-0001', ['items' => [['product_id' => $this->product->id, 'quantity' => 1], ['product_id' => $this->product->id, 'quantity' => 1]]])->assertUnprocessable();
        $this->assertDatabaseCount('orders', 0);
    }

    public function test_insufficient_stock_rolls_back_all_items(): void
    {
        $this->acting();
        $this->stock();
        $other = Product::create(['name' => 'Mouse', 'sku' => 'MOUSE', 'category_id' => $this->product->category_id, 'price_cents' => 500]);
        $this->place('order-key-0001', ['items' => [['product_id' => $this->product->id, 'quantity' => 2], ['product_id' => $other->id, 'quantity' => 1]]])->assertConflict();
        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseCount('stock_movements', 0);
        $this->assertSame(0, Stock::first()->reserved);
    }

    public function test_cancel_releases_stock_once_and_cannot_then_fulfil(): void
    {
        $this->acting();
        $this->stock();
        $id = $this->place()->json('data.id');
        $this->postJson('/api/v1/orders/'.$id.'/cancel')->assertOk()->assertJsonPath('data.status', 'cancelled');
        $this->postJson('/api/v1/orders/'.$id.'/cancel')->assertOk();
        $this->postJson('/api/v1/orders/'.$id.'/fulfil')->assertConflict();
        $this->assertSame(0, Stock::first()->reserved);
        $this->assertSame(10, Stock::first()->on_hand);
        $this->assertSame(1, StockMovement::where('type', 'release')->count());
    }

    public function test_fulfil_deducts_stock_once(): void
    {
        $this->acting();
        $this->stock();
        $id = $this->place()->json('data.id');
        $this->acting('warehouse');
        $this->postJson('/api/v1/orders/'.$id.'/fulfil')->assertOk();
        $this->postJson('/api/v1/orders/'.$id.'/fulfil')->assertOk();
        $this->assertSame(8, Stock::first()->on_hand);
        $this->assertSame(0, Stock::first()->reserved);
        $this->assertSame(1, StockMovement::where('type', 'fulfil')->count());
    }

    public function test_sales_only_sees_and_cancels_own_orders(): void
    {
        $this->acting();
        $this->stock();
        $id = $this->place()->json('data.id');
        $this->acting('sales');
        $this->getJson('/api/v1/orders/'.$id)->assertNotFound();
        $this->postJson('/api/v1/orders/'.$id.'/cancel')->assertNotFound();
        $this->getJson('/api/v1/orders')->assertJsonPath('total', 0);
        $own = $this->place('sales-order-0001')->assertCreated()->json('data.id');
        $this->postJson('/api/v1/orders/'.$own.'/fulfil')->assertForbidden();
        $this->postJson('/api/v1/orders/'.$own.'/cancel')->assertOk();
    }

    public function test_warehouse_role_cannot_create_orders(): void
    {
        $this->acting('warehouse');
        $this->place()->assertForbidden();
    }

    public function test_low_stock_includes_missing_rows_and_reserved_units(): void
    {
        $this->acting();
        $this->getJson('/api/v1/reports/low-stock')->assertOk()->assertJsonPath('data.0.available', 0);
        $this->stock(5);
        $this->place()->assertCreated();
        $this->getJson('/api/v1/reports/low-stock')->assertJsonPath('data.0.available', 3);
        $this->getJson('/api/v1/stocks')->assertJsonPath('data.0.available', 3);
    }

    public function test_database_rejects_invalid_stock_balances(): void
    {
        $this->expectException(QueryException::class);
        Stock::create(['warehouse_id' => $this->warehouse->id, 'product_id' => $this->product->id, 'on_hand' => 1, 'reserved' => 2]);
    }

    public function test_product_and_warehouse_crud_routes(): void
    {
        $this->acting();
        $warehouse = $this->postJson('/api/v1/warehouses', ['name' => 'Secondary', 'code' => 'SECOND'])->assertCreated()->json('data.id');
        $this->getJson('/api/v1/warehouses/'.$warehouse)->assertOk();
        $this->patchJson('/api/v1/warehouses/'.$warehouse, ['name' => 'Updated'])->assertOk()->assertJsonPath('data.name', 'Updated');
        $this->deleteJson('/api/v1/warehouses/'.$warehouse)->assertNoContent();
        $product = $this->postJson('/api/v1/products', ['name' => 'Tool', 'sku' => 'TOOL', 'category_id' => $this->product->category_id, 'price_cents' => 100])->assertCreated()->json('data.id');
        $this->getJson('/api/v1/products/'.$product)->assertOk();
        $this->patchJson('/api/v1/products/'.$product, ['name' => 'Updated tool'])->assertOk();
        $this->getJson('/api/v1/products?q=updated&category_id='.$this->product->category_id)->assertJsonPath('total', 1);
        $this->deleteJson('/api/v1/products/'.$product)->assertNoContent();
    }

    public function test_stock_is_isolated_by_warehouse(): void
    {
        $this->acting();
        $this->stock(10);
        $other = Warehouse::create(['name' => 'Other', 'code' => 'OTHER']);
        $this->place('other-warehouse', ['warehouse_id' => $other->id])->assertConflict();
        $this->assertSame(10, Stock::first()->available);
        $this->assertDatabaseCount('stocks', 1);
    }

    public function test_numeric_strings_and_item_property_order_replay_consistently(): void
    {
        $this->acting();
        $this->stock();
        $id = $this->place()->assertCreated()->json('data.id');
        $this->place('order-key-0001', ['warehouse_id' => (string) $this->warehouse->id, 'items' => [['quantity' => '2', 'product_id' => (string) $this->product->id]]])->assertOk()->assertJsonPath('data.id', $id);
    }

    public function test_role_and_password_validation_prevents_privilege_input_errors(): void
    {
        $this->acting();
        $this->postJson('/api/v1/users', ['name' => 'Bad', 'email' => 'bad@example.test', 'password' => 'short', 'role' => 'root'])->assertUnprocessable()->assertJsonValidationErrors(['password', 'role']);
        $this->assertDatabaseCount('users', 1);
    }

    public function test_negative_and_fractional_quantities_are_rejected(): void
    {
        $this->acting();
        $this->stock();
        foreach ([-1, 0, 1.5, 1000001] as $qty) {
            $this->place('invalid-quantity', ['items' => [['product_id' => $this->product->id, 'quantity' => $qty]]])->assertUnprocessable();
        }
        $this->assertDatabaseCount('orders', 0);
    }

    public function test_movement_idempotency_normalizes_numeric_strings(): void
    {
        $this->acting();
        $data = ['warehouse_id' => $this->warehouse->id, 'product_id' => $this->product->id, 'type' => 'receipt', 'quantity' => 4, 'reason' => 'Test receipt'];
        $this->postJson('/api/v1/stock-movements', $data, ['Idempotency-Key' => 'normalized-stock'])->assertCreated();
        $data['quantity'] = '4';
        $data['product_id'] = (string) $this->product->id;
        $this->postJson('/api/v1/stock-movements', $data, ['Idempotency-Key' => 'normalized-stock'])->assertOk();
        $this->assertSame(4, Stock::first()->on_hand);
    }
}
