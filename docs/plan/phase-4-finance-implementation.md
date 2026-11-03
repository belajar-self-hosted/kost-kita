# Phase 4 — Finance, Payments, Deposits, and Expenses Implementation Plan

> **For agentic workers:** Implement task by task against the approved SSOT. Write and run behavior tests before production changes. This workspace is not a Git repository; preserve existing documentation and local environment.

**Goal:** Deliver recurring monthly invoices, auditable cash receipts and allocations, deposit settlement/refunds, and partially payable operating/maintenance expenses for owner workflows.

**Architecture:** Laravel owns all billing calculations, cash events, tenant-scoped ledgers, idempotency, and atomic financial transitions. Inertia/Svelte provides owner forms and ledgers. Immutable `cash_transactions` is the single source for actual cash; invoice allocations, deposit movements, and expense payments are tenant-scoped subledgers. Reports and exports consume these records in Phase 5.

**Tech Stack:** Laravel 13, PHP 8.3+, Eloquent, Inertia 3, Svelte 5, TypeScript, SQLite for local tests, PostgreSQL in production.

**Spec:** [Roadmap](roadmap.md), [Finance](../business/finance.md), [ADR-002](../decisions/ADR-002-financial-reporting-basis.md), [ADR-003](../decisions/ADR-003-security-deposit-settlement.md), [Maintenance](../business/maintenance.md), [Relational Schema](../data/relational-schema.md), [Route Actions](../architecture/route-actions.md), [Access Control](../security/access-control.md), [Testing Strategy](../quality/testing.md).

## Global Constraints

- Only authenticated, verified, active owner accounts may use `/owner/*`; tenant IDs are derived from `TenantContext`.
- Money is integer Rupiah; only prorata uses decimal arithmetic with one final half-up rounding.
- Business dates use `Asia/Jakarta`; transfer event timestamps remain UTC and event dates are calendar dates.
- Invoice cycle arithmetic is calendar-based and preserves the original billing anchor day; 29–31 clamp to month-end without anchor drift.
- Invoice issue/due date and actual cash receipt/payment are separate records; overdue does not terminate tenancy.
- A `cash_transactions` parent row represents one actual transfer and is counted exactly once, even when a receipt has multiple allocations.
- Financial mutations, allocation balance checks, liability changes, evidence ownership, and idempotency must be tenant-scoped and transactional.
- Historical financial events are immutable; correction uses a reversal, adjustment, or separately audited movement.
- Security deposit and booking DP are one liability; deposit receipts are not rental revenue, deposit refunds are not operating expenses, and non-cash deposit settlement does not change cash flow.
- Reject in-app overpayment. Record externally received surplus in full only as a receipt plus `temporary_liability`; never silently turn it into rent or tenant credit.
- Deposit claims require itemized basis and evidence; only the owner may approve, and the system never transfers a refund automatically.
- Expenses are obligations until payment is actually recorded; support partial payment and prevent duplicate cash events on retries.
- Private evidence storage must not expose public file URLs and must be authorized through the owning tenant and business record.
- Phase 4 does not implement dashboard/report exports; those read the stable ledgers in Phase 5.

---

### Task 1: Generate recurring monthly invoices safely

**Files:**
- Create: `app/Console/Commands/GenerateMonthlyInvoices.php`
- Modify: `app/Support/MonthlyBillingCycle.php`, `app/Support/InitialInvoiceService.php`, `routes/console.php`
- Create: `tests/Feature/Owner/RecurringInvoiceGenerationTest.php`

**Interfaces:** The command `billing:generate-monthly-invoices` finds actual check-ins whose current cycle has begun, locks each tenancy, skips scheduled or fully checked-out tenancies, and calls an idempotent invoice generator keyed by tenancy and cycle. `MonthlyBillingCycle` computes each cycle from the original check-in anchor, not the prior cycle's clamped date.

- [ ] Cover Jan 31 → Feb month-end → Mar 31, leap-year February, repeated command invocation, scheduled tenancy exclusion, and no invoice for cycles wholly after actual checkout.
- [ ] Run the focused tests and confirm missing recurring generation/date cases fail.
- [ ] Implement idempotent generation, rent snapshot effective at cycle start, issue-ahead support, and due date equal to cycle start.
- [ ] Schedule the command in `routes/console.php` with `Asia/Jakarta` and overlap protection.
- [ ] Run focused tests and existing tenancy/prorata tests.

### Task 2: Add immutable cash receipt, allocation, refund, and invoice ledger

**Files:**
- Create migrations: `2026_11_03_000016_create_cash_transaction_tables.php` and `2026_11_03_000017_create_deposit_and_temporary_liability_ledgers.php`
- Create models: `CashTransaction`, `InvoiceCashAllocation`, `DepositMovement`, `TemporaryLiability`, `TemporaryLiabilityMovement`
- Create services: `CashTransactionService`, `InvoicePaymentService`, `DepositLedgerService`
- Create owner controllers/policies for invoices, payments, deposits, and tenant-scoped detail/list actions.
- Modify: `Invoice.php`, `DepositAccount.php`, `routes/web.php`, owner navigation.
- Create: `tests/Feature/Owner/FinancialLedgerTest.php`

**Interfaces:** `InvoicePaymentService::recordReceipt(Tenant $tenant, User $actor, array $input): CashTransaction` creates one idempotent receipt, allocates the exact submitted split across invoice/deposit obligations, locks affected obligations in stable ID order, rejects any allocation above a current balance, and persists the cash event plus allocations/movements atomically. Invoice balances/status are derived from lines, adjustments, and active allocations. The deposit account remains the same account linked at booking and tenancy.

- [ ] Test partial/multiple rent payments, rejected rent/deposit overpayment, exact mixed receipt allocation counted once, duplicate idempotency key, reversal/refund without deleting original events, concurrent stale-balance rejection, booking-to-tenancy account continuity, required-deposit change audit before first receipt, and externally received surplus as temporary liability.
- [ ] Run focused tests and confirm failures target missing ledger behavior.
- [ ] Add tenant composite foreign keys, direction/category checks, unique idempotency keys, immutable events, and audit actor references to migrations.
- [ ] Implement transactional services and owner routes/forms for rent receipt, deposit receipt, full external surplus reconciliation, and temporary-liability refund.
- [ ] Run focused tests plus booking conversion and tenancy invoice tests.

### Task 3: Implement owner deposit settlement and private evidence

**Files:**
- Create migration: `2026_11_03_000018_create_deposit_settlement_tables.php`
- Create models: `DepositSettlement`, `DepositSettlementLine`, `DepositClaimResolution`, `DepositEvidence`
- Create services: `DepositSettlementService`, private evidence storage service
- Create owner controller/policy and pages for deposit account list/detail, settlement, approval, claim resolution, and actual refund.
- Modify: `DepositAccount.php`, `routes/web.php`, owner navigation.
- Create: `tests/Feature/Owner/DepositSettlementTest.php`

**Interfaces:** `DepositSettlementService` opens exactly one auditable settlement per account after check-out, cancellation, or no-show; requires itemized line and evidence rules from ADR-003; records statement and manually entered WhatsApp notification time; supports owner approval, non-cash invoice deduction, hold, actual claim resolution, and actual transfer refund. The service records 7/30-day deadlines in Asia/Jakarta, marks overdue holds for owner action, and never performs an automatic refund or forfeiture.

- [ ] Test settlement eligibility after checkout/cancel/no-show, allowed/forbidden deduction categories, evidence and written agreement requirements, manual WhatsApp notification audit, day-7 undisputed refund tracking, day-30 late flag/no automatic transfer, held claim finalization with actual costs, zero-cost claim refund, non-cash invoice deduction, owner-only approval, over-refund rejection, and cross-tenant evidence denial.
- [ ] Run focused tests and confirm missing settlement behaviors fail.
- [ ] Store evidence on a private disk with validated MIME/size and tenant-authorized download; persist checksum and original filename without exposing storage paths.
- [ ] Implement settlement transitions and immutable deposit/liability movements under a single database transaction and idempotency boundary.
- [ ] Run focused tests plus all financial ledger tests.

### Task 4: Add expense obligations and partial cash payments

**Files:**
- Create migration: `2026_11_03_000019_create_expense_tables.php`
- Create models: `Expense`, `ExpensePayment`
- Create services: `ExpensePaymentService`
- Create owner controller/policy and pages for expense list/create/show/update and payment recording.
- Modify: `MaintenanceJob.php`, maintenance controller/detail as needed, `routes/web.php`, owner navigation.
- Create: `tests/Feature/Owner/ExpensePaymentTest.php`

**Interfaces:** An expense belongs to a tenant and property, may reference a room or maintenance job, and records obligation amount separately from actual cash payments. `ExpensePaymentService::recordPayment(...)` creates one cash outflow plus one expense allocation per actual payment, limited to current outstanding expense balance, atomically and idempotently.

- [ ] Test unpaid expense not counted as cash, partial and multiple payments, payment overbalance rejection, duplicate retry, maintenance-linked expense scope, and tenant isolation.
- [ ] Run focused tests and confirm failures target missing expense/payment behavior.
- [ ] Add expense schemas, models, policy/controller, owner UI, and actual payment allocation on the shared cash ledger.
- [ ] Run focused tests and existing maintenance/tenant-isolation tests.

### Task 5: Expose invoice and ledger workflows in owner UI

**Files:**
- Create pages in `resources/js/pages/Owner/Invoices/`, `Payments/`, `Deposits/`, and `Expenses/`.
- Modify the owner layout/navigation component, tenancy detail, maintenance detail, route definitions, and relevant TypeScript shared types.
- Create/extend Inertia feature tests in `tests/Feature/Owner/FinancialLedgerTest.php`, `DepositSettlementTest.php`, and `ExpensePaymentTest.php`.

**Interfaces:** All forms submit through Laravel session/Inertia validation. Views show derived invoice outstanding/status, actual receipt allocation split, deposit liability/settlement status and deadlines, and expense obligation/payment balance. UI never submits tenant ownership, computed balance, approval actor, or a free-form financial status.

- [ ] Verify every finance route has owner middleware, policy authorization, tenant-scoped query, and safe not-found behavior.
- [ ] Verify create/payment/settlement forms surface backend validation and the latest obligation balance after a stale submission.
- [ ] Run frontend type checks and build after page implementation.

### Task 6: Verify the phase and record handoff

**Files:** Review all Phase 4 files; update `docs/plan/phase-4-finance-implementation.md` and `docs/plan/roadmap.md` after verification.

- [ ] Run all SQLite acceptance tests, Pint, PHP static analysis, route inspection, TypeScript checking, frontend build, Composer validation, and migration `--pretend`; do not run migrations on the developer's configured database.
- [ ] Use a separate code-review pass for tenant isolation, balance locking, idempotency, ledger invariants, deposit restrictions, evidence privacy, and scheduler safety; fix actionable findings.
- [ ] Search changed files for placeholders, skipped tests, direct deletion of financial history, floating-point money calculations, or public evidence URLs.
- [ ] Record any PostgreSQL-only concurrency or deployment-dependent verification as pending with the exact database/environment required.

## Exit Criteria

Phase 4 is complete when recurring invoices, cash/deposit/expense ledgers and owner workflows meet their linked acceptance cases; all SQLite tests and static/build checks pass; financial history remains tenant-scoped and immutable; and an independent review has no unresolved actionable findings. Phase 2's PostgreSQL booking-concurrency gate remains separately tracked.

### Handoff status — 3 November 2026

Recurring billing, cash receipts/refunds, deposit settlement, expense payments, and owner workflows are present in the application. The default SQLite suite and local static/build checks pass. The unchecked acceptance items above still need item-by-item reconciliation before this phase is declared complete; PostgreSQL financial concurrency and deployment checks require the dedicated deployment/test environment and remain pending.
