# Inventory & Order Management API

A runnable Laravel 13 + PostgreSQL backend for one business to manage products, multiple warehouses, physical stock, reservations and order fulfilment. API prefix: `/api/v1`. Uses Laravel Sanctum bearer tokens, role checks, transactional row locks and idempotency keys.

## Requirements

- PHP 8.4+ with PDO PostgreSQL, mbstring, XML/DOM, tokenizer, fileinfo, openssl and Laravel-required extensions.
- Composer 2 and PostgreSQL (tested on PostgreSQL 18).
- No Node/npm, Redis, SMTP or queue worker is required for this version.

## Local setup

1. Run `composer install`.
2. Copy `.env.example` to `.env`. Create a PostgreSQL database named `inventory_order` and configure DB_HOST, DB_PORT, DB_USERNAME and DB_PASSWORD. Credentials are not supplied in this archive.
3. Run `php artisan key:generate`, then `php artisan migrate`.
4. Create the first manager: `php artisan inventory:create-manager your-email@example.com --name="Your Name"`. Enter a strong password interactively. There is no default account and no public signup.
5. Run `php artisan serve` and open `http://127.0.0.1:8000`.

API documentation: `http://127.0.0.1:8000/docs/openapi.json`. Import `docs/openapi.json` into Swagger Editor, Postman or another OpenAPI client. Import the `bruno/` directory into Bruno and select the Local environment. Put your manager email and password into private local environment values; run the numbered workflow in order.

## Quick request flow

```http
POST /api/v1/auth/login
Content-Type: application/json
Accept: application/json

{"email":"your-email@example.com","password":"your-password"}
```

Use the returned token as `Authorization: Bearer <token>`. Create a category, warehouse and product; record a receipt; then create an order. Both stock movements and order creation require an `Idempotency-Key` header (8–100 ASCII letters, digits or `_ . : -`). Use a NEW key for a new operation and the SAME key for retries. A changed payload with an existing key returns 409.

```http
POST /api/v1/orders
Authorization: Bearer <token>
Idempotency-Key: order-2026-0001
Content-Type: application/json

{"warehouse_id":1,"customer_name":"Example Customer","items":[{"product_id":1,"quantity":2}]}
```

Order prices come from the catalog, not from the client. All monetary values are integer cents. An order reserves inventory; fulfilment consumes it and cancellation releases it. Repeating cancellation/fulfilment is safe. Sales users can only access/cancel their own orders.

## Tests — PostgreSQL only

Create a SEPARATE disposable database named `inventory_order_test`; grant the test user privileges to create/drop its tables. Copy `.env.testing.example` to `.env.testing` and fill its PostgreSQL credentials. Set a valid test APP_KEY (`php artisan key:generate --env=testing`). Then run:

```sh
php artisan test
```

The test configuration forces the database name `inventory_order_test`; never point it at production. Tests migrate/reset the test schema. Three tests start two independent PHP processes against real PostgreSQL to verify overselling, concurrent duplicate keys and competing order transitions. See `docs/TEST-RESULTS.md` for the verified run.

## Docker development option

Docker Desktop/Engine must be running. Copy `.env.example` to `.env`, set DB_HOST=db, DB_USERNAME=inventory, a nonempty DB_PASSWORD and APP_KEY. Build/start with `docker compose up --build -d`. Then run `docker compose exec app php artisan migrate` and `docker compose exec app php artisan inventory:create-manager your-email@example.com`. API: http://127.0.0.1:8001.

The Docker setup binds the API and database to localhost and uses Laravel's development server. It is a development convenience, not a production server. Container build was not executed in the verification environment because Docker Engine was not running; the application was verified directly with PHP/PostgreSQL.

## Deployment

Serve only `public/` with your PHP web server. Set APP_ENV=production, APP_DEBUG=false and APP_URL to your domain; configure PostgreSQL TLS via DB_SSLMODE as required by your host. Install with `composer install --no-dev --optimize-autoloader`, run `php artisan migrate --force`, then `php artisan config:cache` and `php artisan route:cache`. Protect `.env`, keep storage/ and bootstrap/cache/ writable, and maintain database backups. Prune expired tokens periodically with `php artisan sanctum:prune-expired --hours=24`. `/up` checks app startup, not database availability. No live hosting/deployment has been performed.

## Project structure

- `app/Services/InventoryService.php`: transaction and stock invariants.
- `app/Http/Controllers/`: API input validation and response boundaries.
- `app/Http/Middleware/`: active account and role authorization.
- `database/migrations/`: relations, uniqueness and PostgreSQL checks.
- `tests/Feature/`: API behavior and concurrent PostgreSQL integration tests.
- `docs/ARCHITECTURE.md`: workflow, locking decisions, role matrix and scope.
- `docs/openapi.json`: request/response contracts for every API operation.
- `bruno/`: executable walkthrough with dynamic IDs and token capture.

## Scope

This release implements the agreed inventory/order API. It does not include a frontend dashboard, payments, tax, returns, suppliers, multi-tenancy or automatic reservation expiry. There are no live credentials or client data. Passwords in automated tests and CI are disposable test fixtures only. Review the code, run it locally and publish your repository before marking the portfolio case study completed.
