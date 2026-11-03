# Phase 2 — Room Catalog, Pricing, and Availability Plan

> Implement the catalog and pricing against the approved SSOT. This project is not currently a Git repository; work in place and preserve the existing docs and local environment.

## Goal

Add room types, rooms, structured facilities, and effective-dated monthly prices to each owner's property. Resolve price defaults consistently, preserving room-specific price overrides and historical agreements.

## Scope and references

- [Roadmap](roadmap.md), [relational schema](../data/relational-schema.md), [route/action contract](../architecture/route-actions.md), [availability rules](../business/availability.md), and [booking/tenancy rules](../business/booking-tenancy.md).
- All owner queries derive tenant ownership from `TenantContext`; property, room type, room, facility, and price relationships are validated server-side.
- Do not add or run application tests unless the user asks for test/verification. Use static, migration, route, and frontend build checks only.
- Availability must never be represented by a manually editable occupied/available status. Under the current default assumption, full booking, tenancy, and maintenance blocker integration is deferred until those source tables/modules exist; no availability endpoint is shipped meanwhile. The user may steer this assumption.

## Tasks

### 1. Catalog schema and models

- [x] Add tenant-scoped `room_types`, `rooms`, and `room_facilities` migrations with normalized uniqueness, archive fields, correct tenant/property composite references, and restrictive historical retention.
- [x] Add tenant-scoped `room_prices` with exactly one target (room or room type), positive integer Rupiah, effective date interval, creator, and no overlapping periods for the same target.
- [x] Add `RoomType`, `Room`, `RoomFacility`, and `RoomPrice` models and relationships. Keep current parent-property locks in the agreed order for archive-sensitive writes.

### 2. Owner room type and room workflows

- [x] Add owner-scoped routes/controllers/policies and Inertia pages for room types and rooms, including list, create, update, and archive. Use list/edit as the room-type detail surface specified by the route contract; room detail has its own show page.
- [x] Validate room type and parent property belong to the authenticated tenant; validate optional room type belongs to that same property.
- [x] Store facilities as structured rows, normalized and unique per room; never accept tenant or actor IDs from clients.
- [x] Prevent destructive archival when future blocking business records exist; preserve history and audit every mutation in the same transaction.

### 3. Effective-dated monthly prices

- [x] Add owner pages/actions for room-type defaults and per-room overrides, including create/list/edit/end-period flows from the route contract.
- [x] Serialize edits using the agreed property lock, reject overlapping effective intervals on the same price target, and retain used historical periods.
- [x] Add one server-side price resolver: an effective room override wins over the room type price; otherwise use the effective room-type price; if none exists, allow the owner to enter a manual agreement price in the future booking/check-in flow.
- [x] Return the selected source and amount to catalog/detail UIs without treating the catalog as a contract or changing past agreement snapshots.

### 4. Availability dependency decision

- [x] Use the current default assumption: defer full blocker integration until booking/tenancy/maintenance modules exist; no incomplete availability endpoint was added.
- [x] Implement full date-range availability after its booking/tenancy/maintenance sources exist, using half-open ranges `[start_date, end_date_exclusive)`, `Asia/Jakarta`, and the shared lock order: property mutex → room rows → business records.

### 5. Handoff

- [x] Run static PHP checks, `migrate --pretend`, route inspection, `npm run types:check`, and `npm run build`; the integrated SQLite availability acceptance suite was added and run during Phase 3.
- [x] Review tenant scoping, price override resolution, no-overlap period enforcement, archive locking, and audit sanitization in a separate review pass.
- [x] Compare delivered scope to the Phase 2 exit criteria. The full booking/tenancy/maintenance blocker integration is implemented and acceptance-tested; keep Phase 2 open only until PostgreSQL concurrency acceptance runs against a dedicated test database.

### Handoff status

Catalog, room management, effective-dated pricing, and full booking/tenancy/maintenance availability are implemented and reviewed. SQLite acceptance passes. Phase 2 remains open only until the PostgreSQL concurrency acceptance suite runs against a dedicated database.
