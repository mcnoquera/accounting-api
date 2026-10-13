# Ledgerly API

Laravel 13 REST API for double-entry accounting with a built-in audit layer. Pairs with
[`accounting-web`](https://github.com/mcnoquera/accounting-web).

## Quick start

```bash
composer install
cp .env.example .env && php artisan key:generate
touch database/database.sqlite
php artisan migrate --seed      # demo company, users and transactions
php artisan serve               # http://localhost:8000
```

Demo users (password `password`): `admin@example.com`, `accountant@example.com`,
`controller@example.com`, `auditor@example.com`.

```bash
php artisan test        # PHPUnit feature + unit tests
./vendor/bin/pint       # code style
```

## Deploy (Render)

[![Deploy to Render](https://render.com/images/deploy-to-render-button.svg)](https://render.com/deploy?repo=https://github.com/mcnoquera/accounting-api)

`render.yaml` creates the API (Docker, `Dockerfile`) and a Postgres database, and redeploys on every
push to `main`. Render asks for two values when you create it:

- `ADMIN_EMAIL` / `ADMIN_PASSWORD` (12+ characters): the first admin, created on boot if missing.

On every boot the container caches config, runs migrations and ensures the admin exists, so restarts
and redeploys are safe. `SEED_DEMO_DATA=true` loads the demo company into an **empty** database only;
its users all have the password `password`, so use it only for a throwaway demo. The app key is
derived from Render's generated `APP_KEY_SECRET`; set `APP_KEY` yourself to override.

If the service name `ledgerly-api` is taken, Render picks another URL: update `APP_URL` to match.
Free-tier services sleep after 15 minutes idle (the first request then takes up to a minute), and
free Postgres databases expire after 30 days unless upgraded.

## What it does

**Bookkeeping**
- Chart of accounts (asset / liability / equity / revenue / expense). Accounts with activity can't be deleted or change type, only deactivated.
- Journal entries with a `draft → posted → voided` lifecycle. Every entry must balance (debits = credits), have at least two lines, and use active accounts to post. Posted entries are immutable; corrections happen by voiding with a reason.
- Money is stored as integer cents and parsed from strings, so there is no floating-point drift.

**Reports** (posted entries only): trial balance, balance sheet (with current-period earnings rolled into equity), income statement, and per-account general ledger with running balance.

**Audit**
- **Audit trail**: every create/update/delete on accounts, entries and findings, plus sign-ins and failed sign-ins, with old/new values, user and IP. The `AuditLog` model refuses updates and deletes.
- **Exception tests** over posted entries: above materiality, prepared and posted by the same user (segregation of duties), posted long after entry date, possible duplicates, large round numbers, weekend dates. Rules live in `app/Services/AuditRules`; add one by implementing `AuditRule` and registering it in `config/audit.php`.
- **Findings**: auditors raise issues (optionally tied to an entry); the accounting team resolves them with a written response.

**Roles**

| | Read books & reports | Record / post / void | Audit trail & exceptions | Raise findings | Resolve findings |
|---|---|---|---|---|---|
| admin | ✓ | ✓ | ✓ | ✓ | ✓ |
| accountant | ✓ | ✓ | | | ✓ |
| auditor | ✓ | | ✓ | ✓ | ✓ |

## API

All routes are under `/api/v1` and, apart from login, need `Authorization: Bearer <token>` (Sanctum).
Responses use `{ "success": true, "data": …, "meta"?: … }`; errors use
`{ "success": false, "message": "…", "errors": { field: [msg] } }`. Amounts in responses are integer cents;
amounts in requests are decimal strings like `"1250.50"`.

| Method | Path | Notes |
|---|---|---|
| POST | `/auth/login` | `{email, password}` → `{token, user}` |
| POST | `/auth/logout` · GET `/auth/me` | |
| GET | `/dashboard` | KPIs + recent entries |
| GET/POST | `/accounts` | `?type=asset&active=1` |
| GET/PUT/DELETE | `/accounts/{id}` | |
| GET | `/accounts/{id}/ledger` | `?from&to` |
| GET/POST | `/journal-entries` | `?status&from&to&q&account_id&per_page&page` |
| GET/PUT/DELETE | `/journal-entries/{id}` | PUT/DELETE drafts only |
| POST | `/journal-entries/{id}/post` | |
| POST | `/journal-entries/{id}/void` | `{reason}` |
| GET | `/reports/trial-balance` · `/reports/balance-sheet` | `?as_of` |
| GET | `/reports/income-statement` | `?from&to` |
| GET | `/audit/logs` | `?action&type&user_id&from&to` |
| GET | `/audit/exceptions` | `?from&to` |
| GET/POST | `/audit/findings` | `?status&severity` |
| POST | `/audit/findings/{id}/resolve` | `{resolution}` |

## Layout

Controllers are thin; business rules live in `app/Services`, data access behind interfaces in
`app/Repositories` (bound in `RepositoryServiceProvider`). Domain rule violations throw
`DomainRuleException` and render as 422.
