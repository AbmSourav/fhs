# FHS — Fast Home Service

Business management application for a home-delivery goods business. Staff
record what was sold, to whom, and what stock is left. The business delivers
heavy household goods to customers' doors — LPG/LNG gas cylinders, large rice
sacks, and similar bulk items.

## Scope

This is a **single-tenant, internal application**. One business uses it; there
is no organisation/tenant concept and nothing should be scoped by tenant. Do
not add multi-tenancy scaffolding.

Staff are the only users. There is no customer-facing portal and no online
ordering — customers are records in the system, not accounts that log in.

Three things are tracked:

- **Inventory** — what's in stock
- **Sales** — what was sold, to whom
- **Customers** — who buys, and what they still owe

## Domain rules

These shape the data model. Get them right before adding features.

### Sales are recorded after the fact

Staff log **completed sales**, not orders in progress. A sale is a finished
transaction by the time it is entered, so orders are created with
`status = complete`.

`orders.status` does carry fulfilment states — `pending`, `processing`,
`complete`, `failed` — but **no workflow drives them yet**. The column exists so
a lifecycle can be introduced later without a migration. Until that is built,
do not add delivery assignment, status transitions, or notifications.

Note that `status` is fulfilment only. **Payment state is derived**, never
stored: `SUM(orders.total_amount) − SUM(payments.amount)`, excluding failed
orders. There is deliberately no `paid_amount` column.

### Cylinders: swap is the common case, not the only one

Gas cylinders are returnable containers, and this is the main modelling
subtlety in the app. Three distinct transaction types:

| Transaction | What moves | Customer gives back |
| --- | --- | --- |
| **Swap / refill** (most common) | Gas only | An empty cylinder |
| **Buy cylinder with gas** | Cylinder + gas | Nothing |
| **Buy empty cylinder** | Cylinder only | Nothing |

Consequences:

- A cylinder's **gas** and its **physical shell** are separately tracked
  things. Stock is not a single number — full and empty cylinders are distinct
  counts, and a swap converts one into the other rather than reducing a total.
- The customer keeps a cylinder between purchases. The business needs to know
  how many shells are out with customers versus on the premises.
- Non-cylinder goods (rice sacks etc.) are ordinary products with none of this
  behaviour. Don't force them through cylinder logic.

### Payment is offline, mostly immediate

All payment happens in person — cash or mobile money. **The application never
processes payments.** Do not integrate a payment gateway.

Most sales are paid in full at delivery. Some customers pay later, so a sale
must be able to carry an outstanding balance, and payments are recorded
against it over time. Partial payment is possible.

A customer therefore has a running due amount. This is a normal part of the
business, not an exception case to bolt on later.

## Stack

Detailed setup, ports, and commands live in [fhs-app/README.md](fhs-app/README.md).
The application itself is in `fhs-app/`.

| Layer | Choice |
| --- | --- |
| Backend | Laravel 13, PHP 8.5 |
| Frontend | React 19 + Inertia 3, TypeScript, Tailwind 4 |
| Build | Vite 8 |
| Database | PostgreSQL 18 |
| Cache / queue | Redis 7 |
| Auth | Laravel Fortify |
| Queues | Laravel Horizon |

Everything runs in Docker. There is no host-side PHP or Node dependency.

## Authorisation

Administrators are identified by email address, listed in `ADMIN_EMAILS`
(comma-separated, case-insensitive) and read via `config('app.admin_emails')`.
There is no `role` column and no admin management UI — the admin set is fixed
by deployment.

Authorise through the **`admin` gate**, never by calling `isAdmin()` or reading
the config directly:

```php
Gate::authorize('admin');                    // in a controller
Route::get(...)->middleware('can:admin');    // on a route
```

`auth.isAdmin` is shared to the frontend for rendering admin-only UI. It is a
convenience only — every privileged route must still authorise server-side.

When staff roles beyond a single admin are needed, replace the config lookup in
`User::isAdmin()` with a real role column. The gate keeps call sites unchanged.

### User types

Three types are planned: **admin**, **founder**, **investor**. Admins create
founder and investor accounts from the dashboard and pass the credentials to
them out of band — there is no self-registration for those types.

`users.permission` is a nullable JSON column holding per-user capability
overrides. Null means "no explicit grants", so the user falls back to whatever
their type allows. The role/type column itself is not built yet.

**`permission` is not mass-assignable.** It is a privilege field and is
deliberately excluded from `$fillable`; assign it explicitly. Never add it to a
form request's validated data.

## Known issues

- `ProfileUpdateRequest` validates only `name`, so `email` is stripped from the
  validated data and profile email updates silently do nothing. This fails
  `ProfileUpdateTest::test_profile_information_can_be_updated`. Pre-existing,
  introduced by the Laravel 13 upgrade of the starter kit.

## Working in this repo

**Run commands inside the containers**, never on the host:

```bash
docker compose exec php php artisan migrate
docker compose exec php php artisan test
docker compose exec vite npm run build
docker compose exec vite npx eslint .
```

Composer and npm installs must also run in-container — the host has a
different PHP version, and `node_modules` contains platform-specific binaries.

Notes:

- The app is served at **http://fhs.test:7080** (`localhost:7080` also works).
- Host ports are deliberately non-default (Postgres `5433`, Redis `6380`) so
  the stack coexists with other local projects. Inside the compose network the
  standard ports apply.
- Changing `APP_URL` is sufficient to move the app to a different hostname —
  Vite derives its asset host, HMR host, and CORS allow-list from it.
- SSR is intentionally disabled.
