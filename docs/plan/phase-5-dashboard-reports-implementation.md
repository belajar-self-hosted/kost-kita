# Phase 5 — Dashboard, Reminders, Reports, and Exports Implementation Plan

> **For agentic workers:** Implement against the linked SSOT and track each step with checkboxes. Keep report calculations in tenant-scoped Laravel query/services; the UI and export render the same returned dataset.

**Goal:** Deliver the owner dashboard, internal operational reminders, five filtered reports, and matching XLSX/PDF exports.

**Architecture:** Laravel builds one validated filter object and tenant-scoped report datasets. Shared calculation services supply both Inertia pages and export writers, so totals cannot drift between formats. The dashboard reads the same finance ledgers and adds occupancy, activity, and follow-up reminders.

**Tech Stack:** Laravel 13, PHP 8.3+, Inertia 3, Svelte 5, TypeScript, PostgreSQL production, SQLite local/test. Use PhpSpreadsheet for numeric XLSX files and Dompdf for server-rendered PDF files; both are pure PHP and do not require another runtime service.

**Spec:** [Roadmap](roadmap.md), [Finance](../business/finance.md), [ADR-002](../decisions/ADR-002-financial-reporting-basis.md), [Route Actions](../architecture/route-actions.md), [UX Requirements](../ux/requirements.md), [Relational Schema](../data/relational-schema.md), [Testing Strategy](../quality/testing.md), [Access Control](../security/access-control.md).

## Global Constraints

- The owner dashboard and reports query only the tenant returned by `TenantContext`; request filters narrow that scope and never establish it.
- Net cash flow is actual cash received minus actual cash paid; call it “Arus Kas Bersih (Net Cash Flow)”, not profit.
- A cash transaction parent counts once in total cash; receipt subledgers may split its display categories without duplicating the parent amount.
- Deposit receipts/refunds remain liability movements; non-cash settlement and unpaid invoices are not cash events.
- Money is integer Rupiah; report snapshots use event business dates and never floating-point arithmetic.
- The invoice report reconstructs outstanding balance at the selected end date; later receipts/refunds or adjustments do not alter that historical snapshot.
- Dashboard reminders are read-only, private to the owner, use `Asia/Jakarta`, and do not message tenants or mutate business state.
- XLSX values for money are numeric with Rupiah formats; PDF layout uses simple tables because Dompdf does not render modern flex/grid layout reliably.
- Exports use the same validated filters, rows, subtotals, and totals as their report page.
- Report pages read ordered result chunks of 100 rows and retain only the requested 50-row page while calculating exact full-period summaries and row counts; this bounds memory, while query work grows with the selected history. For long histories, owners should narrow date/property filters; Phase 6 measures response time on the target LXC and adds SQL aggregates if measured latency warrants it.
- XLSX/PDF exports are limited to 5,000 rows per file; preflight stops at the 5,001st matching report row and rejects larger files before processing the rest or rendering a workbook/PDF, because the self-hosted LXC must keep export resource use predictable.

---

### Task 1: Define and validate report filters and datasets

**Files:**

- Create: `app/Support/Reports/ReportFilters.php`
- Create: `app/Support/Reports/ReportQueryService.php`
- Create: `tests/Feature/Owner/OwnerReportsTest.php`
- Modify: `docs/business/finance.md`, `docs/architecture/route-actions.md`, `docs/quality/testing.md` only if implementation exposes an uncovered rule.

**Interfaces:** `ReportFilters::fromRequest(Request $request, Tenant $tenant): self` validates optional property, room, date range, transaction type, and status filters. Missing dates default to the first of the current Jakarta month through today. `ReportQueryService` receives `Tenant`, `ReportFilters`, and an optional `ReportPage`; it returns a dataset with `title`, `period`, `columns`, current page `rows`, full-result `total_rows`, `summary`, and `generated_at`; every row and total is tenant-scoped.

- [x] Add feature tests showing the default month-to-date range, reversed dates, foreign property/room rejection, and room/property mismatch rejection.
- [x] Add cash-flow fixtures with one mixed receipt, rent refund, deposit refund, non-cash deposit settlement, paid expense, and unpaid expense; assert the receipt parent counts once and non-cash/unpaid events do not enter cash totals.
- [x] Implement one filter parser and tenant-scoped dataset interface; report queries do not accept client-supplied tenant IDs.
- [x] Run `php artisan test --filter=OwnerReportsTest`; all report cases pass.

### Task 2: Build cash-flow and historical invoice reports

**Files:**

- Create: `app/Support/Reports/CashFlowReportQuery.php`
- Create: `app/Support/Reports/InvoiceReportQuery.php`
- Modify: `app/Models/CashTransaction.php`, `Invoice.php`, or `InvoiceBalanceService.php` only if relations are needed for the read-only report query.
- Test: `tests/Feature/Owner/OwnerReportsTest.php`

**Interfaces:** `CashFlowReportQuery::run(Tenant $tenant, ReportFilters $filters, ?ReportPage $page): ReportDataset` filters on `cash_transactions.occurred_on`. `InvoiceReportQuery` reconstructs each invoice balance at the filter end date from invoice issue date, adjustments posted by end-of-day Jakarta, cash received by business date, and non-cash deposit settlement movements; page queries process ordered chunks and retain only rows for the requested page.

- [x] Test an invoice with payment and credit adjustment after the selected end date; neither event changes the earlier snapshot and overdue uses the snapshot date.
- [x] Test mixed receipt category splits while retaining one parent transfer; test deposit refund liability and non-cash settlement exclusion from cash flow.
- [x] Implement date-scoped ledger reconstruction with stable Rupiah integer operations; cash uses `occurred_on`, invoices use issue date, and adjustments/movements use posting instants converted to `Asia/Jakarta`.
- [x] Run `php artisan test --filter=OwnerReportsTest`.

### Task 3: Build booking, tenancy, and maintenance reports

**Files:**

- Create: `app/Support/Reports/BookingReportQuery.php`
- Create: `app/Support/Reports/TenancyReportQuery.php`
- Create: `app/Support/Reports/MaintenanceReportQuery.php`
- Test: `tests/Feature/Owner/OwnerReportsTest.php`

**Interfaces:** Each report query returns the same dataset structure and accepts optional `ReportPage`. Booking rows use inclusive planned check-in date filters. A stored actual checkout date means the resident's last occupied day, so tenancy overlap treats checkout+1 as the internal exclusive end; scheduled tenancies use planned period overlap. Maintenance jobs use report creation instant and linked expense cash uses actual payment calendar date. Booking summaries count statuses within the selected period; maintenance summaries group actual paid cash by work category.

- [x] Test booking start/end inclusion, tenancy last-occupied-day overlap, maintenance creation/payment date boundaries, and valid/foreign/mismatched room/property filters.
- [x] Implement read-only, tenant-scoped queries and aggregate maintenance costs only from actual expense payment cash events in the selected range.
- [x] Run `php artisan test --filter=OwnerReportsTest` and the tenant-isolation tests.

### Task 4: Add matching XLSX and PDF export writers

**Files:**

- Modify: `composer.json`, `composer.lock` (add `phpoffice/phpspreadsheet` and `dompdf/dompdf` after checking PHP/extension compatibility).
- Create: `app/Support/Reports/ReportExportService.php`
- Create: `resources/views/exports/report-pdf.blade.php`
- Modify: `routes/web.php`, `docs/deployment/HOMELAB-SSOT.md` only if the verified PHP container image needs additional extensions.
- Test: `tests/Feature/Owner/OwnerReportsTest.php`

**Interfaces:** `ReportExportService::download(ReportDataset $dataset, string $tenantName, string $format, array $filterLabels): StreamedResponse` writes numeric XLSX cells or Blade-escaped HTML rendered to PDF. `ReportsController` resolves property/room names within the tenant. Export preflight stops after the 5,001st matching row; a result over 5,000 returns HTTP 422 with guidance to narrow the filter.

- [x] Test XLSX MIME type, numeric Rupiah cells, readable property/room/date metadata, totals, and report filename by inspecting the workbook with PhpSpreadsheet.
- [x] Test PDF MIME type and valid signature; inspect options to confirm remote/PHP/JavaScript execution is disabled and Blade output escapes report data.
- [x] Implement shared report data export, frozen/filterable spreadsheet headers, printable table pagination, active filter metadata, and identical summary totals.
- [x] Run `php artisan test --filter=OwnerReportsTest` and `composer validate --no-check-publish`.

### Task 5: Implement dashboard metrics and internal reminders

**Files:**

- Create: `app/Support/Reports/OwnerDashboardSummary.php`
- Modify: `app/Http/Controllers/Owner/OwnerDashboardController.php`, `resources/js/pages/Owner/Dashboard.svelte`
- Test: `tests/Feature/Owner/OwnerDashboardTest.php`

**Interfaces:** `OwnerDashboardSummary::for(Tenant $tenant, CarbonImmutable $today): array` returns occupancy/capacity as of the requested Jakarta date, six calendar months of actual cash totals, month-to-date finance summaries, outstanding invoices, recent activity, due-soon/overdue invoices, tenancies ending within 30 calendar days, unpaid approved refund lines after day 7, and unresolved held claims after day 30. Invoice balances use one aggregate query; invoice reminder details load only due/overdue rows.

- [x] Test mixed receipts, partial/overdue invoices, tenancy end, day-7 refund, day-30 held claim, pre-deadline boundaries, and tenant scope.
- [x] Implement dashboard calculations from actual cash/subledger sources; reminders link to owner workflows and never mutate business state.
- [x] Add the six-month cash trend and Indonesian labels, plus empty states for reminders and recent data.
- [x] Run `php artisan test --filter=OwnerDashboardTest` and `npm run types:check`.

### Task 6: Add report pages, filters, navigation, and route coverage

**Files:**

- Create: `app/Http/Controllers/Owner/ReportsController.php`
- Create: `resources/js/pages/Owner/Reports/Index.svelte` and shared `Show.svelte` for the five report types
- Modify: `routes/web.php`, `resources/js/components/AppSidebar.svelte`
- Test: `tests/Feature/Owner/OwnerReportsTest.php`

**Interfaces:** `GET /owner/reports` shows report links; `GET /owner/reports/cash-flow`, `/invoices`, `/tenancies`, `/bookings`, and `/maintenance` return report datasets and active filters; `GET /owner/reports/{report}/exports/{format}` downloads the corresponding dataset using the same validated filter parser. Filters survive export links and page navigation.

- [x] Test all report pages/exports require owner auth; validate tenant property/room and dates; paginate 51 rows as 50+1; reuse the report dataset for page and export totals.
- [x] Build shared Indonesian tables, filters, summary totals, empty states, bounded pagination, and XLSX/PDF controls for all five reports.
- [x] Add Reports navigation and dashboard links to report/reminder workflows.
- [x] Run report feature tests, tenant isolation tests, `npm run types:check`, and `npm run build`.

### Task 7: Review, verify, and update phase status

**Files:** Review all Phase 5 files; update `docs/plan/phase-5-dashboard-reports-implementation.md` and `docs/plan/roadmap.md` after verification.

- [x] Run final focused and complete SQLite acceptance tests, Pint, PHPStan, route inspection, frontend types/build, Composer validation, and in-memory SQLite migration `--pretend`; never migrate the configured database in this phase.
- [x] Obtain a fresh code review of the final query-level pagination, SQL aggregates, export row limit, snapshot accuracy, tenant scope, and reminder date boundaries; confirm the documented full-period scan is acceptable for MVP and measured in Phase 6.
- [x] Search changed files for placeholders, skipped tests, public export storage, cross-tenant data, duplicate financial calculation paths, or floating-point money.
- [x] Record PostgreSQL-only report/concurrency and deployment checks as pending for the dedicated deployment environment.

## Exit Criteria

Phase 5 is complete when dashboard metrics/reminders, five reports, and XLSX/PDF exports pass linked acceptance checks; page/export totals use the same dataset; tenant isolation and historical snapshots pass tests; all required static/build checks pass; and independent review has no unresolved correctness/security findings. The bounded-memory, full-period scan tradeoff is documented and has a Phase 6 target-LXC performance check.

### Handoff status — 3 November 2026

Phase 5 is implemented and locally verified. `php artisan test` passed 91 tests / 798 assertions; PHPStan reported zero errors; Pint, Svelte type check, frontend build, Composer validation, report route inspection, and SQLite in-memory migration `--pretend` passed. A separate review approved the final report queries and confirmed the page-2 summaries, 5,001-row export stop, tenant scope, historical invoice snapshot, and Jakarta date boundaries. PostgreSQL report/concurrency and deployment checks remain pending for the dedicated environment.
