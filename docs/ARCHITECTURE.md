# Architecture and invariants

This is a backend REST API for one business with multiple warehouses. Laravel 13, PHP 8.4, PostgreSQL 18 for verification, and Sanctum 4.3. No UI or third-party SaaS dependency is required.

## Domain

- Categories → products (SKU unique, integer price_cents).
- Warehouses × products → stocks (one balance per pair).
- Orders belong to a creator and warehouse; items snapshot product prices.
- Stock movements are append-only through the API and identify the actor, product, warehouse, order and signed changes to physical/reserved stock.
- Users have manager, warehouse or sales roles. Disabled accounts are rejected and their tokens are revoked when disabled through the API.

## Balance invariants

`available = on_hand - reserved`, `on_hand >= 0`, `reserved >= 0`, `reserved <= on_hand`.
PostgreSQL CHECK constraints and transactional services enforce these properties. Clients cannot edit balances directly. Record initial balances as receipt movements; never seed balances directly in production if audit history is required.

| Operation | on_hand | reserved |
| --- | --- | --- |
| Receipt | +quantity | unchanged |
| Manual issue | -quantity (available units only) | unchanged |
| Create order | unchanged | +quantity |
| Cancel reserved order | unchanged | -quantity |
| Fulfil reserved order | -quantity | -quantity |

Orders progress from `reserved` to `cancelled` OR `fulfilled`. Final states cannot change. Repeating the same final action has no effect; attempting the other final action returns 409. Cancelled orders release stock; fulfilled orders physically consume it.

## Concurrency and retries

- Every stock update uses a PostgreSQL row lock inside a DB transaction.
- Order creation shares locks on product price rows and then locks stock rows in product ID order. Price changes cannot alter the snapshot during the transaction.
- Order transitions lock the order first and stock rows in product ID order, so concurrent cancellation and fulfilment cannot both succeed.
- Transaction-scoped advisory locks serialize order/movement idempotency keys before record creation. Each user has a separate key namespace for orders and movements. A hash of canonical validated input detects conflicting retries. Order items are sorted before hashing; JSON property order and numeric strings are normalized.
- UNIQUE constraints provide a second guard. Transactions retry concurrency exceptions up to three attempts. Exhausted database contention returns 503; clients should retry writes with the SAME Idempotency-Key.
- Idempotency records are retained with their orders/movements. No expiration or deletion endpoint exists.
- Referential integrity rejects deletion of catalog records already used by stock or history. No historical records are rewritten or deleted through the API.

## Security and roles

There is no public registration. Create the first manager with the CLI; managers provision team accounts. Tokens expire after 24 hours. Passwords use Laravel hashing; only token hashes are stored. The user's current role is checked on each request. Password changes revoke all tokens for that account. The last active manager cannot be demoted or disabled through the API.

| Capability | Manager | Warehouse | Sales |
| --- | --- | --- | --- |
| Read catalog and stock | Yes | Yes | Yes |
| Write catalog and users | Yes | No | No |
| Record stock movements / read audit / low-stock report | Yes | Yes | No |
| Create order | Yes | No | Yes |
| Read orders | All | All | Own only |
| Cancel reserved order | All | No | Own only |
| Fulfil reserved order | All | All | No |

Login: 5 attempts/minute per normalized email/IP and 30/minute per IP. Authenticated API: 120/minute per user. Configure a shared cache and trusted proxy settings when deploying multiple application instances. CORS is not globally opened; browser clients need an explicit allowed-origin configuration.

## Deliberate boundaries

One business, one configured currency, whole-unit quantities. `price_cents` is a minor-unit amount; currencies with different minor-unit conventions require adaptation. No tax, discounts, real payment provider, shipping integration, product returns, supplier purchasing, tenant isolation or frontend dashboard is claimed. Reservations remain until explicit fulfilment/cancellation; automatic expiry is a future feature. Audit records do not track catalog/user edits. These are extensions, not implemented features.
