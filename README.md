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

### Using MySQL or PostgreSQL

SQLite is the zero-setup default. The API also runs on **MySQL 8.4+** and **PostgreSQL 16+** (production
uses Postgres). Create an empty database, then set these in `.env` instead of `DB_CONNECTION=sqlite`:

```dotenv
DB_CONNECTION=mysql        # or pgsql
DB_HOST=127.0.0.1
DB_PORT=3306               # 5432 for pgsql
DB_DATABASE=ledgerly
DB_USERNAME=ledgerly
DB_PASSWORD=ledgerly
```

```sql
-- MySQL
CREATE DATABASE ledgerly CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'ledgerly'@'127.0.0.1' IDENTIFIED BY 'ledgerly';
GRANT ALL ON ledgerly.* TO 'ledgerly'@'127.0.0.1';
```

Then `php artisan migrate --seed`. Tests use in-memory SQLite by default; to run them against another
database, point the `DB_*` variables at a **separate, disposable** database (tests wipe it):

```bash
DB_CONNECTION=mysql DB_HOST=127.0.0.1 DB_PORT=3306 DB_DATABASE=ledgerly_test \
DB_USERNAME=ledgerly DB_PASSWORD=ledgerly php artisan test
```

CI runs the suite on SQLite, MySQL 8.4 and PostgreSQL 16.

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

**Setup**
- **Users** (admin only): create, change role, deactivate/reactivate, unlock. Users are never deleted, so the audit trail always points at a real person. Deactivating signs the user out everywhere. The last active admin can't be demoted or deactivated, and admins can't deactivate themselves.
- **Sign-in lockout**: 5 failed sign-ins lock the account for 15 minutes (an admin can unlock it sooner). Lockouts and unlocks are audited.
- **Company profile**: one company per installation with BIR TIN (9 digits + 3- or 5-digit branch code, stored as `123-456-789-000`), RDO code, address, VAT registration, fiscal-year start month. Currency is PHP. `GET /company` returns `null` until first-run setup.

**Bookkeeping**
- Chart of accounts (asset / liability / equity / revenue / expense) with optional **group accounts** (`parent_id`, same type, no cycles). Entries post to detail accounts only. Accounts with activity can't be deleted, change type or become a group; group accounts can't be deleted or change type while they have sub-accounts. Each account reports its `normal_balance`.
- **Philippine starter chart** (`POST /accounts/load-defaults`, empty chart only): 78 accounts including Input/Output VAT, Creditable Withholding Tax, EWT and compensation withholding payable, SSS / PhilHealth / Pag-IBIG payables. Have your external accountant review it before go-live.
- Journal entries with a `draft → posted → voided` lifecycle. Every entry must balance (debits = credits), have at least two lines, and use active accounts to post. Posted entries are immutable.
- Two ways to correct a posted entry, both requiring a reason:
  - **Void** removes it from the books entirely (use for entries that should never have existed).
  - **Reverse** posts a new entry on a date you choose (on or after the original) that swaps every debit and credit. Both stay in the ledger and net to zero, which keeps a closed period's figures intact. The pair links both ways (`reversal_of` / `reversed_by`); an entry can be reversed once, a reversal can't be reversed, and neither side of a pair can be voided.
- Money is stored as integer cents and parsed from strings, so there is no floating-point drift.

**Month-end close**
- Periods are calendar months, closed strictly in order by an admin or accountant (from the month of the earliest entry, and only once the month has ended). Closing locks every date up to the month's last day.
- In a closed period you can't create, edit into, post or void entries; correct them with a **reversal** dated in an open period (the default reversal date skips past closed months). Drafts left in a closed month can be deleted or moved to an open date.
- Only an admin can reopen, only the most recent closed period, and only with a reason. Closing reports how many drafts are still dated in the month. Close and reopen are in the audit trail.

**Reports** (posted entries only): trial balance, balance sheet (with current-period earnings rolled into equity), income statement, and per-account general ledger with running balance.

**Audit**
- **Audit trail**: every create/update/delete on accounts, entries and findings, plus sign-ins and failed sign-ins, with old/new values, user and IP. The `AuditLog` model refuses updates and deletes.
- **Exception tests** over posted entries: above materiality, prepared and posted by the same user (segregation of duties), posted long after entry date, possible duplicates, large round numbers, weekend dates. Rules live in `app/Services/AuditRules`; add one by implementing `AuditRule` and registering it in `config/audit.php`.
- **Findings**: auditors raise issues (optionally tied to an entry); the accounting team resolves them with a written response.

**Roles**

| | Read books & reports | Record / post / void / reverse | Audit trail & exceptions | Raise findings | Resolve findings |
|---|---|---|---|---|---|
| admin (also users & company) | ✓ | ✓ | ✓ | ✓ | ✓ |
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
| GET/POST | `/users` | admin only; `{name, email, role, password}` (12+ chars) |
| GET/PUT | `/users/{id}` | `{name?, email?, role?, is_active?, password?}` |
| POST | `/users/{id}/unlock` | clears a sign-in lockout |
| GET/PUT | `/company` | PUT is admin only; 201 on first setup |
| GET | `/dashboard` | KPIs + recent entries |
| GET/POST | `/accounts` | `?type=asset&active=1`; `parent_id` optional |
| POST | `/accounts/load-defaults` | Philippine starter chart, empty chart only |
| GET/PUT/DELETE | `/accounts/{id}` | |
| GET | `/accounts/{id}/ledger` | `?from&to` |
| GET/POST | `/journal-entries` | `?status&from&to&q&account_id&per_page&page` |
| GET/PUT/DELETE | `/journal-entries/{id}` | PUT/DELETE drafts only |
| POST | `/journal-entries/{id}/post` | |
| POST | `/journal-entries/{id}/void` | `{reason}` |
| POST | `/journal-entries/{id}/reverse` | `{reason, entry_date?}` → the new reversing entry (201) |
| GET | `/periods` | months with status and counts; `meta.locked_through`, `first_open_date`, `next_to_close` |
| POST | `/periods/{YYYY-MM}/close` | admin/accountant; `meta.draft_count` |
| POST | `/periods/{YYYY-MM}/reopen` | admin; `{reason}`, latest closed period only |
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
