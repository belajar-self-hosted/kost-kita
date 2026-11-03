# Phase 1 Foundation Implementation Plan

> **For agentic workers:** Implement this plan task by task against the approved SSOT. Keep changes in the current project directory; it contains product documentation and is not currently a Git repository.

**Goal:** Establish a runnable KostKita Laravel application with the approved Svelte frontend, owner/Super Admin authentication, tenant isolation, audit logging, and property management.

**Architecture:** Use the official Laravel Svelte starter kit for a single Laravel/Inertia application. Laravel owns authentication, role/account checks, tenant scoping, audit events, and property persistence; Svelte renders owner and platform pages through Inertia.

**Tech Stack:** Laravel 13, PHP 8.3+, Inertia 3, Svelte 5, TypeScript, Tailwind CSS, SQLite for local development, PostgreSQL for production.

**Spec:** [KostKita SSOT index](../README.md), [access control](../security/access-control.md), [route/action contract](../architecture/route-actions.md), [relational schema](../data/relational-schema.md), and [Phase roadmap](roadmap.md).

## Global Constraints

- Only `owner` and `super_admin` may authenticate; tenants and staff have no login.
- Owner registration creates one tenant server-side in the same transaction; clients never choose `tenant_id` or role.
- Owner routes require authentication, verified email, and active account; platform routes require the Super Admin role and active account.
- Every property read/write is scoped through the authenticated owner's tenant and guarded by policy.
- Super Admin accounts are provisioned through a restricted CLI flow; there is no public Super Admin registration.
- Audit records are append-only and exclude passwords, tokens, official identity numbers, and secrets.
- Property archival preserves history; do not add cascade deletes for tenant business records.
- Keep Laravel route/session/Inertia form behavior; do not introduce an API server or JSON UI contract.
- Do not add or run application tests in this phase unless the user asks for test/verification. Perform static, migration, and production asset-build checks only.

---

### Task 1: Scaffold the approved application

**Files:** Create the Laravel application at project root while preserving the existing `docs/` tree; generated Laravel, Svelte, Vite, Tailwind, and Inertia files remain under their official starter-kit paths.

**Interfaces:** Provide Laravel web routes, Inertia page resolution, auth starter-kit routes, SQLite local setup, and an npm production build command for later tasks.

- [x] Install the official Laravel installer in an isolated temporary directory or use an already installed local tool; do not add global packages.
- [x] Create a Laravel 13 application with the official Svelte starter kit and built-in Laravel authentication.
- [x] Preserve all existing documentation and `.env` secrets; do not overwrite documentation or copy a real `.env` from another project.
- [x] Install the generated PHP and Node dependencies from the new manifests.
- [x] Configure `.env.example` for SQLite local use, without placing secrets in source.
- [x] Run `php artisan about`, `php artisan route:list`, and `npm run build`; resolve scaffold errors before continuing.

### Task 2: Add account roles and owner tenant provisioning

**Files:** Modify `app/Models/User.php`, the generated registration controller/request, `routes/web.php`, and user migrations; create `app/Models/Tenant.php` and the tenants migration.

**Interfaces:** `User` exposes `owner`/`super_admin` role and `active`/`suspended` status; `Tenant` belongs to exactly one owner; successful owner registration creates both records atomically.

- [x] Confirm the generated registration flow and route names from Task 1 before editing it.
- [x] Make the registration request accept profile/auth fields only; assign role and account status server-side.
- [x] In one database transaction, create the owner user and corresponding tenant; use a normalized unique email and enforce one tenant per owner.
- [x] Make `User` implement email verification and keep the starter-kit password reset, login rate limit, CSRF, and session invalidation behavior.
- [x] Ensure non-owner roles cannot use public registration and no client field can set role/status/tenant ownership.
- [x] Run `php artisan migrate --pretend` and `php artisan route:list` against the local SQLite configuration; inspect results for the required constraints and routes.

### Task 3: Enforce roles and provision Super Admin safely

**Files:** Create `app/Http/Middleware/EnsureRole.php`, `app/Http/Middleware/EnsureActiveAccount.php`, `app/Console/Commands/CreateSuperAdmin.php`; modify `bootstrap/app.php`, auth redirects, and `routes/web.php`.

**Interfaces:** `/owner/*` is owner-only; `/platform/*` is Super Admin-only; inactive accounts are denied; `app:create-super-admin` provisions a verified active Super Admin without a public route.

- [x] Register middleware aliases in the Laravel 13 application bootstrap using the generated project convention.
- [x] Apply `auth`, `verified`, `active`, and exact role middleware to their route groups.
- [x] Redirect authenticated users by server-side role; do not trust a `next`, `role`, or account-status form value to grant access.
- [x] Implement `app:create-super-admin` with an interactive hidden password prompt, unique email validation, secure hashing, and role/status assignment; never accept a plaintext password command-line argument.
- [x] Add a real minimal platform landing page for the provisioned Super Admin; no placeholder routes or unfinished branches.
- [x] Run `php artisan route:list` and inspect middleware assignments and CLI command registration.

### Task 4: Add tenant context and append-only audit logging

**Files:** Create `app/Support/TenantContext.php`, `app/Models/AuditLog.php`, `app/Support/AuditLogger.php`, and their migration; modify relevant service providers and account/property actions.

**Interfaces:** `TenantContext` derives the tenant from the authenticated owner; `AuditLogger` writes sanitized actor/action/subject/old-new/reason/request metadata; platform events may have a null tenant.

- [x] Resolve tenant ownership only from the authenticated `User` relationship; fail closed for accounts without a tenant.
- [x] Create an append-only audit table with tenant and actor foreign keys, sanitized JSON old/new values, reason, UTC time, and request ID.
- [x] Reject or redact password, token, identity-number, and secret keys before an audit row is written.
- [x] Record owner registration and important property create/update/archive actions; do not log read-only requests by default.
- [x] Run `php artisan migrate --pretend`; review the migration and route code to ensure untrusted `tenant_id` never determines scope.

### Task 5: Implement property CRUD for owners

**Files:** Create `app/Models/Property.php`, its migration, `app/Policies/PropertyPolicy.php`, `app/Http/Controllers/Owner/PropertyController.php`, `resources/js/pages/Owner/Properties/{Index,Create,Edit,Show}.svelte`; modify `routes/web.php` and the owner navigation/dashboard page.

**Interfaces:** Owner routes follow the SSOT paths `/owner/properties`, `/owner/properties/create`, `/owner/properties/{property}`, and `/owner/properties/{property}/edit`. Property queries use `TenantContext`; forms use normal Inertia validation/redirect behavior.

- [x] Define property fields and status from `relational-schema.md`: tenant ownership, name, address/location, status, archive timestamp, and timestamps.
- [x] Add policy checks and scoped query binding so a foreign tenant property is returned as a safe not-found response.
- [x] Implement list, create, show, update, and archive actions; archive instead of deleting and refuse archive while open business records require resolution.
- [x] Validate all fields on the server and set `tenant_id` from the authenticated context.
- [x] Log create/update/archive through `AuditLogger` in the same database transaction as the property mutation.
- [x] Implement Svelte pages with empty, validation-error, success, and confirmation states; do not put authorization or ownership decisions in client code.
- [x] Run `php artisan migrate --pretend`, `php artisan route:list`, and `npm run build`; inspect representative owner route and UI wiring.

### Task 6: Review Phase 1 scope and handoff

**Files:** Review the implementation files above and the SSOT references; update this plan's checkboxes only after the associated work is complete.

- [x] Confirm no public Super Admin registration, tenant/staff login, client-controlled tenant ID, public property mutation, or cascade deletion was introduced.
- [x] Run the static, migration, route, Composer, type-check, and asset-build verification commands after the final implementation changes; report actual exit status. Application tests were not added or run.
- [x] Compare the result with Phase 1 exit criteria in `docs/plan/roadmap.md`; mark behavioral acceptance verification as pending because no tests were run.

### Handoff status

The Phase 1 implementation is in place and static/build checks pass. Do not mark the roadmap phase behaviorally complete until owner/Super Admin login, property tenant scoping, and cross-tenant not-found behavior have been acceptance-verified. The current session did not add or run application tests.
