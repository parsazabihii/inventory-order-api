# Verified test results

Verified on 2026-10-13 with PHP 8.4.21, Laravel 13.35.0, Sanctum 4.3.3 and an isolated PostgreSQL 18 database on localhost.

- PHPUnit: **34 tests passed, 156 assertions**, no skipped tests. No SQLite fallback.
- Three multiprocess tests use separate PHP connections to the same PostgreSQL test database: competing orders, concurrent identical idempotency keys and cancellation versus fulfilment.
- **16 real HTTP checks passed** against a running local Laravel server, including CLI account creation, token login, CRUD, receipt, reservation, replay, fulfilment, conflicting cancellation, stock balance, OpenAPI retrieval and revoked-token rejection.
- OpenAPI 3.0.3 document validated with @apidevtools/swagger-parser; it describes 30 operations.
- All 14 Bruno request files and the Local environment parsed successfully with the official @usebruno/lang parser. The Bruno GUI/CLI runner was not used; API behavior was independently verified by HTTP and PHPUnit.
- Laravel Pint passed. Composer manifest validation and route caching passed.
- Migrations ran successfully on PostgreSQL. Database constraints were exercised, including rejection of reserved stock exceeding on-hand stock.

Docker build, GitHub-hosted CI, production deployment, large-scale performance and an external security audit were not performed. CI and Docker files are included for repeatable setup, not claimed as verified deployments.

## Passing PHPUnit cases

- ExampleTest: test_that_true_is_true
- ApiTest: test_postgresql_is_the_test_database
- ApiTest: test_unauthenticated_requests_are_rejected
- ApiTest: test_login_token_me_and_logout
- ApiTest: test_wrong_password_and_disabled_login_are_rejected
- ApiTest: test_expired_token_is_rejected
- ApiTest: test_login_is_rate_limited
- ApiTest: test_catalog_crud_search_and_pagination
- ApiTest: test_product_validation_and_price_snapshot
- ApiTest: test_referenced_catalog_deletion_returns_conflict
- ApiTest: test_sales_cannot_manage_catalog_stock_or_users
- ApiTest: test_manager_can_provision_and_disable_users
- ApiTest: test_last_active_manager_cannot_be_demoted
- ApiTest: test_receipt_issue_replay_and_audit
- ApiTest: test_manual_issue_cannot_consume_reserved_stock
- ApiTest: test_order_idempotency_replays_without_duplicate_reservation
- ApiTest: test_idempotency_key_is_required_and_duplicate_products_invalid
- ApiTest: test_insufficient_stock_rolls_back_all_items
- ApiTest: test_cancel_releases_stock_once_and_cannot_then_fulfil
- ApiTest: test_fulfil_deducts_stock_once
- ApiTest: test_sales_only_sees_and_cancels_own_orders
- ApiTest: test_warehouse_role_cannot_create_orders
- ApiTest: test_low_stock_includes_missing_rows_and_reserved_units
- ApiTest: test_database_rejects_invalid_stock_balances
- ApiTest: test_product_and_warehouse_crud_routes
- ApiTest: test_stock_is_isolated_by_warehouse
- ApiTest: test_numeric_strings_and_item_property_order_replay_consistently
- ApiTest: test_role_and_password_validation_prevents_privilege_input_errors
- ApiTest: test_negative_and_fractional_quantities_are_rejected
- ApiTest: test_movement_idempotency_normalizes_numeric_strings
- ConcurrencyTest: test_competing_orders_cannot_oversell
- ConcurrencyTest: test_concurrent_identical_keys_only_create_one_order
- ConcurrencyTest: test_cancellation_and_fulfilment_cannot_both_apply
- ExampleTest: test_the_application_returns_a_successful_response

## HTTP checks

| Method | Path | Status |
| --- | --- | --- |
| POST | /api/v1/auth/login | 200 |
| GET | /api/v1/auth/me | 200 |
| POST | /api/v1/categories | 201 |
| POST | /api/v1/warehouses | 201 |
| POST | /api/v1/products | 201 |
| POST | /api/v1/stock-movements | 201 |
| POST | /api/v1/orders | 201 |
| POST | /api/v1/orders | 200 |
| POST | /api/v1/orders/1/fulfil | 200 |
| POST | /api/v1/orders/1/fulfil | 200 |
| POST | /api/v1/orders/1/cancel | 409 |
| GET | /api/v1/stocks | 200 |
| GET | /api/v1/reports/low-stock | 200 |
| GET | /docs/openapi.json | 200 |
| POST | /api/v1/auth/logout | 204 |
| GET | /api/v1/auth/me | 401 |
