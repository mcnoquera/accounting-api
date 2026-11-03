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

The demo is a fictional VAT-registered trading company on the Philippine starter chart: customers and suppliers, VAT and withholding on sales and purchases (including a zero-rated export sale and a non-VAT lawyer), open receivables and payables, January and February closed, two drafts awaiting review, and entries that trip every audit exception test.

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

**Sales invoices**
- Drafts are editable: customer, dates (due date defaults to the customer's terms), reference, notes, and lines with description, quantity (up to 3 decimals), unit price, revenue account and VAT code. Amounts use integer maths, rounded half up.
- **Issue** numbers the invoice (`SI-000001`, gap-free) and posts an ordinary journal entry through the normal rules (period lock, tax check, customer on the control account): Dr receivable for the total, Cr revenue per account and VAT code, Cr Output VAT. The entry is linked to the invoice and can only be undone by **voiding** the invoice (unpaid only), which reverses it on the invoice date or the first open date.
- **PDF** for customers: seller's registered name, TIN (VAT/Non-VAT), address; invoice number and dates; buyer's name, address and TIN; lines; VATable / zero-rated / exempt sales, VAT and total, or the non-VAT notice. Printing is audited. *System-generated invoices generally require the system to be registered with the BIR; confirm with your accountant.*

**Customers and suppliers (subsidiary ledgers)**
- Contacts: name, type (customer / supplier / both), TIN (normalised like the company TIN), address, email, phone, payment terms in days (default 30), active flag. Audited; a contact used on entries can't be deleted, only deactivated.
- Accounts can be a `control_type` `receivable` (asset) or `payable` (liability) control account; the Philippine starter chart tags 1210 and 2110. Every line on a control account must name an active customer (receivable) or supplier (payable). Other lines may name a contact too, e.g. the payee of a cash expense.
- Per-contact ledger with opening and running balance, and receivables/payables **aging** as of a date: current, 1–30, 31–60, 61–90 and over 90 days past due (entry date + payment terms), with payments and credits settling the oldest charges first (no invoice matching). The control accounts' balance is shown next to the contact total, so lines without a contact show up as a difference. Aging exports to CSV and PDF.

**Month-end close**
- Periods are calendar months, closed strictly in order by an admin or accountant (from the month of the earliest entry, and only once the month has ended). Closing locks every date up to the month's last day.
- In a closed period you can't create, edit into, post or void entries; correct them with a **reversal** dated in an open period (the default reversal date skips past closed months). Drafts left in a closed month can be deleted or moved to an open date.
- An admin or accountant can reopen, only the most recent closed period, and only with a reason. Closing reports how many drafts are still dated in the month. Close and reopen are in the audit trail.

**Year-end close**
- Fiscal years follow the company's `fiscal_year_start_month` and are named by the calendar year they start in. Once every month of a year is closed (and earlier years with entries are closed), an admin or accountant closes it into a chosen equity account: a posted, system-marked closing entry dated the year's last day zeroes each revenue and expense account into retained earnings.
- The income statement leaves closing entries out, so a closed year still shows its profit and the next year starts at zero; balance sheet totals don't change. Closing entries can't be voided or reversed by hand: reopening the year's last month reverses the closing entry automatically and reopens the year. Closes are audited.

**Tax codes (VAT and withholding)**
- Seeded codes: `VAT12` (vatable 12%), `VAT0` (zero-rated), `VATEX` (exempt), and the BIR expanded-withholding ATC schedule from Form 1601-EQ (January 2019; RR 2-98 as amended by RR 11-2018): 84 codes, all except the electric-utility meter-deposit codes (WI/WC650–663). The 28 most businesses use start active (professional fees, consultants, bookkeeping agents, rent, contractors, brokers, sales agents, top withholding agent goods/services); specialised ones start inactive. **Confirm with your accountant, and check later BIR issuances for new codes or rate changes.** Admins add, rename and deactivate codes; a code's kind, rate and category are locked once it's used on an entry.
- Accounts can carry a `tax_role`: `input_vat`, `output_vat`, `ewt_payable` (tax we withheld) or `cwt_receivable` (tax customers withheld, Form 2307). The Philippine starter chart tags 1420, 2210, 2230 and 1430.
- A journal line can carry a `vat_code_id` and an `ewt_code_id`. They go on the **base line** (the sale or purchase), whose amount is the tax base. Saving and posting check that each tax role on the entry holds base × rate (±₱1): VAT on the same side as the base line, withholding on the other side. Revenue lines are sales, everything else purchases, so returns and reversals check out too. Zero-rated and exempt codes need no tax line. A company that isn't VAT-registered can't charge output VAT.

**VAT summary** (supports BIR Form 2550Q): for a calendar quarter, vatable / zero-rated / exempt sales and purchases from the VAT codes on base lines (sales returns and reversals reduce them), output and input VAT from the VAT-account lines of those coded entries, and net VAT payable (negative = excess input VAT), by month and for the quarter. It also shows VAT computed from the codes next to VAT booked, and lists VAT-account activity in entries without a VAT code (quarter-end settlements, or sales/purchases missing their code) separately.

**Percentage tax summary** (supports BIR Form 2551Q, non-VAT businesses): for a calendar quarter, gross sales/receipts from revenue lines (returns and reversals reduce them; year-end closing entries are excluded), less VAT-exempt (VATEX) sales, times the company's `percentage_tax_rate_bp` (default 3%, set on the company profile as `percentage_tax_rate`), by month and for the quarter, rounded half up. Exports to CSV/PDF.

**Withholding tax summary** (supports BIR Forms 0619-E, 1601-EQ and the QAP): for a calendar quarter, tax withheld from suppliers by ATC and month (base = the amount of the line carrying the ATC; returns and reversals reduce it), the payee list by supplier and ATC with TIN, and tax customers withheld from us (Forms 2307) by customer. The payee is the contact on the coded line, or the only contact elsewhere on the entry (e.g. the payable line). Withholding accounts' activity on coded entries is shown for reconciliation; uncoded activity (remittances, 2307 applications) is listed separately. Exports: summary (CSV/PDF) and payee list (CSV).

**Exports**: CSV for every report, the general ledger and the audit trail; PDF (via dompdf) for the trial balance, balance sheet, income statement and VAT summary, with the company's registered name, TIN, RDO, address and VAT status in the header and "Prepared by … on …" in the footer. CSV and PDF are built from the same table, amounts are pesos with 2 decimals, CSV text that looks like a spreadsheet formula is prefixed with `'`, and every export is written to the audit trail (`exported`, with the report, format and filters).

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
| GET/POST | `/sales-invoices` | `?status=draft\|open\|overdue\|paid\|voided&contact_id&from&to&q`; POST creates a draft |
| GET/PUT/DELETE | `/sales-invoices/{id}` | PUT/DELETE drafts only |
| POST | `/sales-invoices/{id}/issue` · `/void` | void takes `{reason}` |
| GET | `/sales-invoices/{id}/pdf` | customer-facing PDF (audited) |
| GET/POST | `/contacts` | `?type=customer\|supplier&active&q`; POST admin/accountant |
| GET/PUT/DELETE | `/contacts/{id}` | DELETE only if unused |
| GET | `/contacts/{id}/ledger` | `?type=receivable\|payable&from&to` |
| GET | `/reports/percentage-tax` | `?year&quarter` (calendar quarter; default current) |
| GET | `/reports/withholding` | `?year&quarter` (calendar quarter; default current) |
| GET | `/reports/aging` | `?type=receivable\|payable&as_of` |
| GET/POST | `/tax-codes` | `?kind=vat\|ewt&active=1`; POST admin: `{code, name, kind, rate, vat_category?}` |
| PUT | `/tax-codes/{id}` | admin; rate/kind/category locked once used |
| GET/POST | `/journal-entries` | `?status&from&to&q&account_id&per_page&page`; lines take `vat_code_id`, `ewt_code_id` |
| GET/PUT/DELETE | `/journal-entries/{id}` | PUT/DELETE drafts only |
| POST | `/journal-entries/{id}/post` | |
| POST | `/journal-entries/{id}/void` | `{reason}` |
| POST | `/journal-entries/{id}/reverse` | `{reason, entry_date?}` → the new reversing entry (201) |
| GET | `/fiscal-years` | each year with status, net income, closing entry, `can_close` |
| POST | `/fiscal-years/{year}/close` | admin/accountant; `{retained_earnings_account_id}` |
| GET | `/periods` | months with status and counts; `meta.locked_through`, `first_open_date`, `next_to_close` |
| POST | `/periods/{YYYY-MM}/close` | admin/accountant; `meta.draft_count` |
| POST | `/periods/{YYYY-MM}/reopen` | admin; `{reason}`, latest closed period only |
| GET | `/reports/trial-balance` · `/reports/balance-sheet` | `?as_of` |
| GET | `/reports/income-statement` | `?from&to` |
| GET | `/reports/vat-summary` | `?year&quarter` (calendar quarter 1–4; default current) |
| GET | `/reports/{trial-balance\|balance-sheet\|income-statement\|vat-summary\|aging\|withholding\|withholding-payees\|percentage-tax}/export` | `?format=csv\|pdf` + the report's filters; file download |
| GET | `/accounts/{id}/ledger/export` | CSV; `?from&to` |
| GET | `/audit/logs/export` | CSV, admin/auditor; same filters as `/audit/logs`, latest 50,000 rows |
| GET | `/audit/logs` | `?action&type&user_id&from&to` |
| GET | `/audit/exceptions` | `?from&to` |
| GET/POST | `/audit/findings` | `?status&severity` |
| POST | `/audit/findings/{id}/resolve` | `{resolution}` |

## Layout

Controllers are thin; business rules live in `app/Services`, data access behind interfaces in
`app/Repositories` (bound in `RepositoryServiceProvider`). Domain rule violations throw
`DomainRuleException` and render as 422.
