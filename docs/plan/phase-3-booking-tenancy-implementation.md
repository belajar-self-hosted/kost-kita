# Phase 3 — Booking, Tenancy, and Complete Availability Implementation Plan

> **For agentic workers:** Implement task by task against the approved SSOT. Use the test-first workflow for behavior changes and keep each task reviewable. This workspace is not a Git repository; preserve existing documentation and local environment.

**Goal:** Deliver tenant-scoped resident, booking, tenancy, maintenance-blocking, and date-range availability workflows, including the first rent invoice and one deposit-liability account needed for complete check-in behavior.

**Architecture:** Laravel owns tenant scope, booking/tenancy state transitions, date-range availability, locks, audit, invoice creation, and scheduling. Inertia/Svelte renders owner workflows. A shared availability service checks room status, confirmed bookings, scheduled/active tenancies, and blocking maintenance under the agreed property-first lock order.

**Tech Stack:** Laravel 13, PHP 8.3+, Eloquent, Inertia 3, Svelte 5, TypeScript, SQLite for local tests, PostgreSQL for production concurrency behavior.

**Spec:** [Roadmap](roadmap.md), [Booking dan Masa Sewa](../business/booking-tenancy.md), [Ketersediaan Kamar](../business/availability.md), [Perawatan](../business/maintenance.md), [Keuangan](../business/finance.md), [relational schema](../data/relational-schema.md), [route/action contract](../architecture/route-actions.md), [access control](../security/access-control.md), [UI requirements](../ux/requirements.md), and [acceptance criteria](../quality/testing.md).

## Global Constraints

- Only active, verified `owner` accounts may use `/owner/*`; tenant IDs are derived from `TenantContext`.
- Rent and deposit amounts use integer Rupiah; never use floating-point for money.
- Business dates use `Asia/Jakarta`; stored event timestamps use UTC.
- Room periods use `[start_date, end_date_exclusive)` and reject empty or reversed ranges.
- Every availability-changing write locks the tenant-owned property row, then room rows by ID, then relevant booking/tenancy/maintenance rows by ID; re-read and check conflicts after locks.
- `pending`, `cancelled`, and `no_show` bookings do not block; `confirmed` bookings block until check-in, cancellation, or expiry.
- A booking confirmed on planned check-in date gets a grace period through the next calendar day and expires at 00:00 Asia/Jakarta the following day.
- A tenancy remains blocking after its planned end until the owner records actual check-out; the entered checkout date is the last occupied day and its exclusive boundary is the following day.
- Room price override wins over room-type price; manual agreed rent remains valid when no catalog price applies.
- New extension rent starts at the next monthly billing boundary; if recorded exactly on a cycle boundary, it starts in that cycle. Do not prorate a rate change within a cycle.
- Check-in from booking keeps the same deposit account/liability, creates no second receipt, and creates exactly one first rent invoice.
- Do not collect tenant KTP or official identity numbers; do not add tenant or staff login.

---

### Task 1: Add behavior-first coverage for the shared booking and tenancy rules

**Files:**
- Create: `tests/Feature/Owner/BookingAvailabilityTest.php`
- Create: `tests/Feature/Owner/BookingNoShowTest.php`
- Create: `tests/Feature/Owner/TenancyLifecycleTest.php`
- Create: `tests/Feature/Owner/MaintenanceAvailabilityTest.php`
- Create: `tests/Feature/Owner/AvailabilityConcurrencyTest.php`
- Create: `tests/Support/OwnerTestData.php` only if a fixture helper is needed by more than one test.

**Interfaces:** Tests exercise owner routes and the shared availability/transition services through real SQLite transactions. The concurrency acceptance test uses two PostgreSQL connections and a committed fixture; it runs only against the dedicated PostgreSQL test database.

- [x] Write a failing overlap test proving adjacent `[start,end)` periods succeed and overlapping periods are rejected.
- [x] Write failing no-show tests for the deadline boundary, repeat scheduler execution, manual extension success/conflict, expired check-in rejection, and deposit-account preservation.
- [x] Write failing availability test proving a late no-show booking is reconciled before availability is returned and no longer blocks.
- [x] Write failing tenancy tests for direct immediate/scheduled check-in, booking conversion, checkout date normalization, extension rate boundary, and first-invoice idempotence.
- [x] Write PostgreSQL concurrency tests for two overlapping confirmed-booking requests and for no-show expiry racing with a new booking; assert the final state never has conflicting blockers.
- [x] Run the targeted test files and confirm failures are caused by the missing workflows, not test setup errors.

### Task 2: Persist resident, booking, tenancy, maintenance blocker, and initial billing records

**Files:**
- Create: `database/migrations/2026_11_03_000008_create_residents_table.php`
- Create: `database/migrations/2026_11_03_000009_create_bookings_table.php`
- Create: `database/migrations/2026_11_03_000010_create_tenancies_table.php`
- Create: `database/migrations/2026_11_03_000011_create_maintenance_jobs_table.php`
- Create: `database/migrations/2026_11_03_000012_create_initial_invoice_tables.php`
- Create: `database/migrations/2026_11_03_000013_create_deposit_accounts_table.php`
- Create: `database/migrations/2026_11_03_000014_create_invoice_adjustments_table.php`
- Create: `app/Models/Resident.php`, `Booking.php`, `BookingGraceExtension.php`, `Tenancy.php`, `TenancyExtension.php`, `MaintenanceJob.php`, `Invoice.php`, `InvoiceLine.php`, `InvoiceAdjustment.php`, `DepositAccount.php`
- Modify: `app/Models/Property.php`, `app/Models/Room.php`, and any source model relations needed by the schema.

**Interfaces:** Every business model carries `tenant_id`; booking and tenancy link to one property, room, resident, and optional booking with composite tenant/property foreign keys. Booking status is one of `pending`, `confirmed`, `checked_in`, `cancelled`, `no_show`; tenancy status is one of `scheduled`, `active`, `completed`, `terminated`; maintenance status is one of `reported`, `scheduled`, `completed`, `cancelled`. An invoice belongs to exactly one booking or tenancy, and an initial rent line snapshots amount, monthly rate, and service interval. A deposit account belongs to a booking and may later link to one tenancy without replacing its booking link.

- [x] Define resident name, WhatsApp/contact fields, optional notes, archive timestamp, and no identity-number columns.
- [x] Add booking planned interval, agreed monthly rent snapshot, check-in deadline, state timestamps, optional notes and cancellation reason (max 500 characters), creator, required deposit amount/account, and indexes for status/date/deadline availability lookups.
- [x] Add immutable booking grace-extension events with old/new deadlines, reason, actor, and tenant-scoped idempotency key.
- [x] Add tenancy planned and actual dates, actual check-in instant, checkout last-occupied date, rent snapshot, billing anchor day, optional source booking, and indexes for scheduled/active lookup.
- [x] Add immutable tenancy extension events with old/new end dates, old/new rent snapshots, effective cycle boundary, reason, and actor.
- [x] Add maintenance schedule fields required by SSOT: property, optional room, `blocks_room`, active status, and valid start/end-exclusive dates. A blocking schedule requires a room and a valid interval.
- [x] Add invoice and rent-line tables with tenant-safe foreign keys, unique invoice number/idempotency key, one-source constraint, monthly cycle dates, due date, amount, rent snapshot, and service interval.
- [x] Add immutable invoice adjustment records with tenant-scoped idempotency, reason, actor, and the actual used/cycle day operands required to explain early-checkout prorata.
- [x] Add one deposit account per booking/tenancy link, required amount, unique source references, and constraints that permit linking the same account during booking conversion.
- [x] Add indexes and SQLite/PostgreSQL-compatible checks; keep arbitrary overlap and cross-row balance invariants in transactional services.
- [x] Run migration `--pretend` and targeted model/schema tests before building UI.

### Task 3: Build the single availability and lock service

**Files:**
- Create: `app/Support/RoomAvailabilityService.php`
- Create: `app/Support/PropertyAvailabilityLock.php` only if it prevents duplicated lock-order code across services.
- Modify: `app/Http/Controllers/Owner/RoomController.php`, `app/Http/Controllers/Owner/PropertyController.php`, `app/Support/RoomPriceService.php` only where needed for availability integration.

**Interfaces:** `RoomAvailabilityService::conflicts(Room $room, CarbonImmutable $start, CarbonImmutable $endExclusive, ?int $ignoreBookingId = null, ?int $ignoreTenancyId = null, ?int $ignoreMaintenanceJobId = null): Collection` returns scoped conflict summaries. `assertAvailable(...)` throws a field validation error listing the latest conflicts. The service uses `[start,end)` overlap and checks operational status, blocking confirmed bookings, scheduled/active tenancies, and active blocking maintenance.

- [x] Implement the overlap expression `existing.start < requested.endExclusive && existing.endExclusive > requested.start` for every blocker source.
- [x] Exclude expired confirmed bookings from the result and atomically reconcile them to `no_show` before completing an availability decision.
- [x] Implement the shared property → room IDs → business-record IDs lock order and use it from all blocker-changing services.
- [x] Update room/property archive checks to use the actual models and all open booking, tenancy, and maintenance states instead of table-existence guards.
- [x] Add a backend availability page query for property, optional room, start date, and end-exclusive date; include effective room price and conflict type, without exposing data outside the owner tenant.
- [x] Run the targeted overlap tests and verify range boundaries and blocker status changes.

### Task 4: Implement resident and booking owner workflows

**Files:**
- Create: `app/Policies/ResidentPolicy.php`, `BookingPolicy.php`
- Create: `app/Http/Controllers/Owner/ResidentController.php`, `BookingController.php`
- Create: `app/Support/BookingService.php`
- Create: `app/Console/Commands/ExpireNoShowBookings.php` and a queued job only if processing volume requires it.
- Create: `resources/js/pages/Owner/Residents/{Index,Create,Show,Edit}.svelte`
- Create: `resources/js/pages/Owner/Bookings/{Index,Create,Show}.svelte`
- Modify: `routes/web.php`, `routes/console.php`, `app/Providers/AppServiceProvider.php`, and owner navigation.

**Interfaces:** Routes match `route-actions.md`: resident list/create/show/update/archive; booking list/create/show/confirm/cancel/extend-grace-period/check-in. Forms use normal Inertia validation redirects. Booking create accepts either a tenant-owned resident ID or new resident name/WhatsApp details, creates related records atomically, resolves current catalog rent as a prefill, allows an owner-agreed amount, and creates one deposit account. Pending bookings do not block. Confirm locks and rechecks availability, stores the default expiry, and audits the transition.

- [x] Implement tenant-scoped resident create/list/show/update/archive; archive only when no active business flow needs the resident.
- [x] Implement booking create with existing/new resident choice, planned date validation, manual or catalog-prefilled rent snapshot, deposit obligation, and no client-controlled tenant/actor fields.
- [x] Implement pending → confirmed and pending/confirmed → cancelled transitions with property/room/business locks, persisted cancellation reason and audit values, and no automatic deposit settlement.
- [x] Implement owner grace extension before expiry; preserve planned check-in date, record immutable old/new deadline and reason, reject conflicts, and reconcile expired bookings to no-show before returning a validation error.
- [x] Implement an idempotent scheduler command that locks and changes only confirmed bookings whose deadline has passed; it must not make financial movements.
- [x] Run the scheduler twice against fixtures and verify the second run changes no state or audit/financial record.

### Task 5: Implement direct check-in, booking conversion, tenancy lifecycle, and initial invoices

**Files:**
- Create: `app/Policies/TenancyPolicy.php`, `InvoicePolicy.php`
- Create: `app/Http/Controllers/Owner/TenancyController.php`, `AvailabilityController.php`
- Create: `app/Support/TenancyService.php`, `MonthlyBillingCycle.php`, `InitialInvoiceService.php`
- Create: `resources/js/pages/Owner/Tenancies/{Index,Show}.svelte`
- Create: `resources/js/pages/Owner/DirectCheckIns/Create.svelte`
- Create: `resources/js/pages/Owner/Availability/Index.svelte`
- Modify: booking page, `routes/web.php`, owner navigation, and room detail page.

**Interfaces:** Routes match `route-actions.md`: tenancy list/detail; direct-check-in create/store; scheduled-tenancy check-in/cancel; booking check-in; extension; and checkout. Direct check-in today creates `active` tenancy, actual check-in time/date, billing anchor, one initial invoice, and one deposit account. A future start creates `scheduled` tenancy and no invoice. Booking conversion links the existing resident, booking, tenancy, invoice, and deposit account atomically. Invoice cycle boundaries use original anchor day 1–31 and calendar arithmetic.

- [x] Implement direct check-in create/store with room price prefill, manual agreement price, resident create/select, valid date range, and full availability recheck under locks.
- [x] Implement conversion of a valid confirmed booking into one active tenancy, including expiry recheck; retain the same deposit account and never create a second receipt.
- [x] Implement future scheduled tenancy activation; on actual check-in set actual date/instant and original billing anchor, and create the first invoice exactly once.
- [x] Implement idempotent initial invoice creation for one monthly cycle with due date equal to actual check-in date; do not issue invoices for scheduled tenancies.
- [x] Implement extension with conflict summary, immutable old/new snapshots, next-cycle rent effective date, and no mid-cycle rate adjustment. Resolve any catalog price prefill using that effective cycle boundary, not the form-submission date.
- [x] Implement scheduled cancellation and active checkout. Store the entered last-occupied date, normalize the availability/proration boundary to the following day, retain history, and create a prorata adjustment only for early checkout; do not issue an automatic refund.
- [x] Add tenant-scoped page states for scheduled, active, completed, and terminated tenancies and display the linked invoice/deposit account summaries.
- [x] Run booking-conversion, direct-check-in, extension-boundary, checkout, and invoice-idempotency tests.

### Task 6: Implement maintenance blockers and the owner availability view

**Files:**
- Create: `app/Policies/MaintenanceJobPolicy.php`, `app/Http/Controllers/Owner/MaintenanceController.php`
- Create: `app/Support/MaintenanceScheduleService.php`
- Create: `resources/js/pages/Owner/Maintenance/{Index,Create,Show}.svelte`
- Modify: `routes/web.php`, owner navigation, `app/Http/Controllers/Owner/RoomController.php`, and `app/Http/Controllers/Owner/PropertyController.php`.

**Interfaces:** Implement the maintenance behavior needed for a complete availability source: report/list/show, schedule blocking work for a room and half-open date interval, complete, and cancel schedule. A completed or cancelled job no longer blocks. Financial expenses and maintenance payments remain in Phase 4.

- [x] Implement tenant-scoped report/list/show for maintenance jobs with optional room, `blocks_room`, and category/description.
- [x] Implement schedule/cancel/complete transitions with availability recheck, the shared lock order, and audit.
- [x] Reject blocking maintenance schedules that overlap confirmed bookings or scheduled/active tenancies; keep the work reported and do not persist a conflicting blocker. A non-blocking job or a new date may be saved.
- [x] Verify the availability page includes room operational status, confirmed booking, scheduled/active tenancy, and maintenance conflicts for the queried range.
- [x] Verify archiving a property or room refuses unresolved blockers and preserves historical records.
- [x] Run the targeted maintenance/availability feature tests.

### Task 7: Review Phase 3 and verify delivery

**Files:** Review the implementation files and update `docs/plan/phase-3-booking-tenancy-implementation.md`, `docs/plan/phase-2-room-catalog-implementation.md`, and `docs/plan/roadmap.md` after verification.

- [x] Run all automated tests for the implemented flows; run PostgreSQL concurrency acceptance tests against a dedicated PostgreSQL test database when available.
- [x] Run PHP lint/Pint, Composer validation, route inspection, `npm run types:check`, `npm run build`, and migration `--pretend`; do not run a migration against the developer's configured database.
- [x] Use a separate code-review pass for tenant isolation, state transitions, lock ordering, scheduler idempotence, money snapshots, and history retention; fix every actionable finding.
- [x] Inspect changed docs/code for placeholder TODO branches, skipped/empty acceptance tests, mass assignment, client-controlled ownership, and direct deletes; resolve each finding.
- [x] Keep Phase 2 open only for the unexecuted PostgreSQL concurrency acceptance; full booking, tenancy, and maintenance availability behavior is implemented and SQLite acceptance-tested.
- [x] Compare Phase 3 behavior with the roadmap exit criteria and record any environment-dependent PostgreSQL verification as pending with the exact database required.

### Handoff status

The implementation includes the dependency work needed for a complete Phase 3 check-in and availability flow: blocking maintenance schedules, the first rent invoice, and a deposit account that survives booking conversion. The full SQLite suite (45 tests), static/build checks, route inspection, and migration `--pretend` pass. Independent code review completed with no remaining findings. PostgreSQL concurrency acceptance remains pending until a dedicated test database is configured. Phase 4 remains responsible for cash receipt/allocation, recurring billing generation, deposit settlement/refunds, maintenance expenses, reports, and exports.
