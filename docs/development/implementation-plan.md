# ActiveFit — Implementation Plan

> **Status:** DRAFT for execution review · **Version:** 1.0 · **Date:** 2026-11-05  
> **Project:** Laravel + Inertia.js + Vue 3 + TypeScript + Tailwind CSS + MySQL + Pest  
> **Repository:** GitHub · **Architecture:** modular monolith · **Method:** AI SDLC

## 1. Purpose and source of truth

This plan translates approved ActiveFit product requirements into small, reviewable implementation tasks. It is **not** permission to invent business rules. Before starting a task, an AI agent reads:

1. `AGENTS.md`;
2. `docs/product/requirements.md`;
3. applicable `docs/architecture/{overview,database,cms,security,frontend,business-flows}.md`;
4. `docs/architecture/decisions/ADR-001-activation.md`;
5. `docs/development/testing-strategy.md`;
6. source HTML under `design/reference-html/` when implementing UI.

In a conflict, explicitly approved/latest business decisions win; document and resolve contradictions before writing affected business logic. The reference HTML establishes visual direction, **not** backend contracts or security behavior.

### Confirmed invariants

- **Payment ≠ activation.** Verified online payment or confirmed reception payment creates one `pending_activation` membership. At first visit, staff/admin activates it, records `activated_at`, `activated_by_user_id`, `starts_on` and inclusive `expires_on` in `Europe/Warsaw`. Activation and entry redemption are separate actions.
- PDF is issued **after confirmed payment**, including future renewals and pending activation. Pending PDF shows **no fictitious dates**. Email delivery failure does not change payment or create another membership.
- Purchase supports guest checkout, purchase for someone else, **exactly one membership per order**, independent purchaser and holder identities, no merge of guest purchases into registered accounts.
- Registered `users.email` and normalized `users.phone` are unique; guest orders can repeat contact details. A shared phone search returns distinguishable holders.
- Memberships preserve immutable purchase-time plan terms and integer grosz prices. Month arithmetic: e.g. 28 Sep → 27 Oct; 30 Apr → 29 May; 31 Jan 2027 → 28 Feb 2027 (inclusive).
- Existing active period renewals start after current expiry; unused limited entries roll over where approved. **Do not** assume how to combine different plan types or renew an unactivated membership.
- Every redeemed entry records timestamp and employee; critical mutations have auditable actor, time and change details, preserved after expiry.
- CMS uses fixed templates; no page builder; one image per placement and file deleted when removed; regulation is versioned and accepted version is recorded with online purchase; price plans are shared with checkout, max 3 featured on homepage.
- First admin is created idempotently by a safe seeder; employee provisioning is admin-only; all logged-in roles can edit their own profile.

## 2. Delivery principles

- **One repository and one deployable Laravel application**; Inertia serves Vue pages, no separate API unless justified by a concrete integration.
- Each task should normally fit one small PR. A task is complete only when its migrations, authorization, validations, tests and UI states (where relevant) are included.
- Never mark a task complete using only visual screenshots, happy-path manual checks or mocked integrations without contract tests.
- Never use real customer data or real payment credentials in fixtures, screenshots, CI or AI prompts.
- Backend is authoritative for statuses, prices, permissions and date calculations. Frontend only renders received state and performs presentation-level checks.
- Use database transactions / locks / constraints for concurrency-sensitive operations. Dispatch outbound email/queue jobs **after commit**.
- Monetary values are **integers in grosz**. Store timestamps consistently and render local Warsaw dates; distinguish date-only columns from timestamps.
- Proposed new tools: **Vitest + Vue Test Utils** for component tests and **Playwright** for E2E. They require approval if not already part of the repository tooling; backend testing with Pest is confirmed.

## 3. Work packages and milestone gates

| Milestone | Outcome a reviewer can verify | Included tasks |
| --- | --- | --- |
| M0 — Repository foundation | Installs, runs, checks in GitHub CI with documented configuration | TASK-001–005 |
| M1 — Access and identity | Accounts, admin seeder, roles, profile, staff lifecycle protected by backend | TASK-006–010 |
| M2 — Public site and CMS | Public pages from reference HTML; admin edits CMS and publishes content safely | TASK-011–018 |
| M3 — Core membership domain | Plans/orders/holders, dates, first visit, entries and audited history tested independently of real gateway | TASK-019–026 |
| M4 — Checkout and delivery | Reception and online checkout, P24 verification, PDF/email and recovery | TASK-027–034 |
| M5 — Operations and release | Customer/staff/admin panels integrated, E2E/observability/hardening/release checklist | TASK-035–040 |

**Sequence is dependency-based, not strictly numeric.** Public frontend and CMS can be implemented independently of payments after M0/M1. A milestone must not be presented as production-ready while its critical acceptance tests fail.

## 4. Implementation backlog

### M0 — Repository foundation

#### TASK-001 — Initialize application and GitHub repository
- **Priority:** P0 · **Depends on:** none · **Source:** `repository-setup.md`, `overview.md`.
- **Scope:** Set up Laravel + official Vue/Inertia TypeScript starter, Tailwind, MySQL; commit `.env.example`, README and repository documentation. Confirm supported versions against current official documentation at implementation time. No secrets in git.
- **Acceptance:** Fresh checkout starts locally using documented commands; default Inertia page renders; production secrets absent from tracked files.
- **Tests/checks:** dependency install/build, framework health smoke, `git status` clean, secret scan.

**Execution — 2026-11-09:** Local implementation and verification completed using the existing official starter; no regeneration or dependency upgrades were needed. README now documents Docker/native setup and actual locked versions. `ApplicationSmokeTest` checks the guest Inertia `Welcome` component and `/up`; formatter exclusions preserve `AGENTS.md` and original reference HTML. A fresh temporary checkout (tracked source plus task changes, without `.env`, `vendor` or `node_modules`) passed `composer setup`, including MySQL 8.4 migrations, Composer install, `npm ci` and production build. `composer validate --strict`, `composer test` (Pint, PHPStan, 41 tests / 148 assertions), `npm run check`, `npm run types:check`, and live HTTP smoke (`/` and `/up`: 200) passed. Limited tracked-file secret-pattern scan and `git diff --check` passed. Existing GitHub origin was preserved; repository visibility/branch protection were not verified. Changes await review/commit, so the clean-worktree check remains pending. Related requirement: NFR-008; business rules are unchanged.

#### TASK-002 — Developer environment and conventions
- **Priority:** P0 · **Depends on:** TASK-001 · **Source:** `AGENTS.md`, `repository-setup.md`.
- **Scope:** Dev/test DB configuration, application timezone strategy, queued mail local transport, standard formatters/linting/type checks, test seed data separation.
- **Acceptance:** New developer can initialize and reset local database without manual undocumented steps; test DB is isolated from dev DB.
- **Tests/checks:** run formatting, TypeScript check, lint and Pest baseline on clean checkout.

**Execution — 2026-11-09:** Completed locally. Runtime and MySQL session timestamps use UTC; business calendar dates use `app.business_timezone = Europe/Warsaw`, with DST and day-boundary tests. Database queues dispatch after commit; integration tests cover worker delivery and rollback. Default seeding creates no demo data; explicit, local-only `LocalDatabaseSeeder` creates an account with a random password and preserves existing credentials. README documents setup/reset, mail transport and quality commands. An opt-in Compose `mysql-test` service uses a separate server, restricted test user and ephemeral storage; `phpunit.mysql.xml`, `composer test:mysql` and `make test-mysql` run the suite there. Test bootstrap rejects non-testing environments and unsafe database targets, including cached developer configuration. Fresh temporary source snapshot passed `composer setup` (MySQL migrations, dependency installs, production build), `composer validate --strict`, `composer test` (Pint, PHPStan, 53 tests / 191 assertions on SQLite), `composer test:mysql` (53 tests / 196 assertions on MySQL 8.4), `npm run check`, `npm run types:check` and `git diff --check`. Additional local smoke verified the documented reset, actual database queue → log delivery and refusal of cached developer configuration without changing demo data. Existing developer `.env` and database were not modified; temporary test containers/volumes were removed. Related: NFR-008 and the UTC/Warsaw convention required by MEM-010 / ACT-01. CI integration remains TASK-003; no open business decisions were resolved in code.

#### TASK-003 — GitHub Actions baseline
- **Priority:** P0 · **Depends on:** TASK-001, TASK-002 · **Source:** `testing-strategy.md`.
- **Scope:** Workflow for PHP dependencies, Node build, format/lint/static checks and Pest with MySQL; cache dependencies; PR checks required by team policy.
- **Acceptance:** CI fails on broken test/build and succeeds on main baseline; does not use production P24 credentials.
- **Tests/checks:** intentionally failing test verified to fail pipeline; green run documented.

**Execution — 2026-11-09:** Workflow and local verification implemented; hosted acceptance remains pending publication. `.github/workflows/tests.yml` runs check **CI** on pull requests, pushes to `main` and manual dispatch, with PHP 8.4, Node from `.nvmrc`, an isolated MySQL 8.4 service, Composer/npm download caches, commit-pinned actions, read-only token permissions, concurrency cancellation and a 15-minute timeout. `composer ci:check` now performs strict manifest validation, production build before route typechecks, frontend formatting/lint/typecheck, Pint, PHPStan and Pest against `activefit_testing`; no production secrets are referenced. A fresh source snapshot installed locked PHP/Node dependencies and passed the entire CI command (53 tests / 196 assertions). In that temporary snapshot, an intentionally failing Pest test caused exit 1, a TypeScript syntax error caused the build/CI command to exit 1, and after restoring both files the entire command passed again. `actionlint` 1.7.12 and `git diff --check` passed. Removed an unused import from the locally edited demo seeder while preserving its local password choice; README reflects current local setup. Existing developer containers, data and `.env` were preserved. Related: NFR-008. No commit/push or hosted Actions run was performed; hosted green/red runs and required **CI** / PR review settings on `main` still need verification in GitHub. Local results must not be reported as a hosted green run.

#### TASK-004 — Application skeleton and shared contracts
- **Priority:** P0 · **Depends on:** TASK-001 · **Source:** `overview.md`, `frontend.md`.
- **Scope:** Organize Laravel Controllers, Form Requests, Policies, Actions, Jobs, Inertia pages/layouts, typed shared props. Do not pre-create empty service layers.
- **Acceptance:** One real end-to-end read-only page demonstrates route → controller → Inertia → typed Vue page.
- **Tests/checks:** HTTP page test and frontend typecheck.

#### TASK-005 — Shared UX primitives and reference HTML integration
- **Priority:** P1 · **Depends on:** TASK-004 · **Source:** `frontend.md`, `design/reference-html/`.
- **Scope:** Buttons, fields, feedback, confirmation dialogs, pagination, image component, public/admin/staff/customer layouts. Preserve established responsive appearance; no business data mocked in production views.
- **Acceptance:** Common components have loading, empty, error and accessible focus states; work at mobile widths.
- **Tests/checks:** component tests if Vitest approved; responsive and keyboard smoke.

### M1 — Identity, authorization and employee operations

#### TASK-006 — Authentication, sessions and role boundaries
- **Priority:** P0 · **Depends on:** TASK-002, TASK-004 · **Source:** `security.md`, ACC-002, STAFF, ADM.
- **Scope:** Registration, login, logout, password reset, `customer`/`employee`/`admin` roles, backend middleware/policies; blocked/suspended staff cannot use existing sessions.
- **Acceptance:** Customer cannot read another person's order/pass; employee cannot call employee-admin/CMS settings routes; roles cannot be escalated by payload.
- **Tests/checks:** Pest authorization matrix incl. direct HTTP tampering, suspended sessions.

#### TASK-007 — Idempotent initial administrator seeder
- **Priority:** P0 · **Depends on:** TASK-006 · **Source:** ACC-013, `security.md`.
- **Scope:** `AdminUserSeeder` with secret-based initialization; no embedded plaintext password; collision guard; reruns never overwrite password.
- **Acceptance:** Two runs create one admin; existing unrelated user with same email is not promoted.
- **Tests/checks:** seeder re-run and collision tests.

#### TASK-008 — Self-service profile for all authenticated roles
- **Priority:** P0 · **Depends on:** TASK-006, TASK-005 · **Source:** ACC-011–012.
- **Scope:** View/update first name, surname, email, normalized phone; verify changed email; password change requiring current password; immutable purchase snapshots.
- **Acceptance:** Each role can update only own data; DB uniqueness is enforced under race; role/status injection rejected.
- **Tests/checks:** Pest validation, access and history immutability; frontend form states.

#### TASK-009 — Employee creation, editing and suspension
- **Priority:** P0 · **Depends on:** TASK-006–008 · **Source:** ADM-005–008, NTF-001.
- **Scope:** Admin-only CRUD (excluding destructive account removal), welcome email with safe setup link, suspension; audit all critical changes.
- **Acceptance:** Employee receives setup link without password disclosure; suspension blocks access; employee cannot self-grant role.
- **Tests/checks:** Pest permissions, notification fake, setup-link expiration and suspension.

#### TASK-010 — Auditable critical actions infrastructure
- **Priority:** P0 · **Depends on:** TASK-006 · **Source:** `database.md`, `security.md`, MEM-006.
- **Scope:** Append-only `audit_logs`, actor/system attribution, action/subject, safe old/new summaries, timestamp; write in business transaction; mask sensitive fields.
- **Acceptance:** Admin can inspect actual logged changes; user cannot edit audit records; failed transaction leaves no phantom audit.
- **Tests/checks:** transaction rollback, actor context, sensitive-data exclusion.

### M2 — Public website and CMS

#### TASK-011 — Public content pages and navigation
- **Priority:** P1 · **Depends on:** TASK-004–005 · **Source:** WEB-001–004, WEB-006, `frontend.md`.
- **Scope:** Vue/Inertia public homepage, offer, pricing, news, gallery, contact, regulations and schedule from existing HTML; responsive, public SEO metadata.
- **Acceptance:** Pages use real backend props and existing layouts, provide 404s and empty states, no administrative data exposed.
- **Tests/checks:** Pest public read routes; visual/mobile and keyboard checks.

#### TASK-012 — CMS media upload, ownership and delete
- **Priority:** P0 · **Depends on:** TASK-006, TASK-010 · **Source:** `cms.md`, CMS-002, CMS-OD-05.
- **Scope:** One asset in one placement only; upload size/type validation, safe paths, delete reference and physical file after DB commit with recoverable cleanup failures.
- **Acceptance:** Unsafe upload rejected; remove image deletes its asset/file; no shared-media assumptions; no stale public references after removal.
- **Tests/checks:** Pest MIME/spoofed MIME/oversize, orphan cleanup retry, unauthorized delete.

#### TASK-013 — Homepage tiles and carousel management
- **Priority:** P1 · **Depends on:** TASK-011–012 · **Source:** CMS-006–007.
- **Scope:** Admin add/edit/hide/reorder tiles and hero slides, upload owned photos, per-slide descriptions; reflect published state on homepage.
- **Acceptance:** Only visible content reaches public props; order is stable; form mirrors supplied HTML patterns.
- **Tests/checks:** CRUD/ordering/visibility and image removal.

#### TASK-014 — News CMS
- **Priority:** P1 · **Depends on:** TASK-011–012 · **Source:** CMS-003, CMS-OD-04.
- **Scope:** Draft/publish/hide news, title, HTML body sanitized against XSS, cover image, publication date; admin UI and public list/detail.
- **Acceptance:** Draft not publicly accessible; scripts/event attributes/unsafe links removed.
- **Tests/checks:** Pest policy, publication, sanitizer and content tests.

#### TASK-015 — Category-based gallery CMS
- **Priority:** P1 · **Depends on:** TASK-011–012 · **Source:** CMS-002, CMS-OD-01.
- **Scope:** Categories with title/short description; images with sort order and no editorial per-image descriptions; one gallery category per image.
- **Acceptance:** Admin creates/edits/removes categories and images; public gallery displays ordered images correctly.
- **Tests/checks:** category ownership, ordering, deletion and access.

#### TASK-016 — Regulations with version history and consent capture
- **Priority:** P0 · **Depends on:** TASK-010, TASK-011 · **Source:** CMS-001, CMS-011 (latest confirmed), CMS-OD-03.
- **Scope:** One current published regulation, immutable prior versions; optional image; safe HTML; checkout captures version ID and consent timestamp, separately from marketing consent.
- **Acceptance:** Old order points to original version even after publish; checkout cannot proceed without required acceptance; drafts are inaccessible publicly.
- **Tests/checks:** version history, concurrent publish policy, consent binding, sanitizer, auth.

#### TASK-017 — Contact, opening hours and class schedule
- **Priority:** P1 · **Depends on:** TASK-011 · **Source:** CMS-005, CMS-008, CMS-OD-02.
- **Scope:** Club contact/location settings, one opening interval or closed per weekday, types/schedule of classes, admin editing and public rendering.
- **Acceptance:** Public contact/schedule reflect saved settings; closed days do not show fictitious hours.
- **Tests/checks:** validation, read/write authorization, empty days.

#### TASK-018 — CMS publication integration and administrator review
- **Priority:** P1 · **Depends on:** TASK-013–017 · **Source:** `cms.md`, `security.md`.
- **Scope:** Consistent publish/hide transitions, admin navigation, media cleanup and audit where changes affect business/legal content; final content QA.
- **Acceptance:** Admin can manage public content end-to-end without developer; employee cannot edit CMS; no duplicate source for pricing.
- **Tests/checks:** cross-module CMS smoke and permissions.

### M3 — Membership core (gateway-independent)

#### TASK-019 — Database migrations and domain models
- **Priority:** P0 · **Depends on:** TASK-002, TASK-006, TASK-010 · **Source:** `database.md`, ADR-001, ORD-005.
- **Scope:** `users`, plan/order/payment/holder snapshot/membership/entry/adjustment/delivery/audit schema as designed; nullable dates for pending passes; constraints, indexes, foreign keys; avoid speculative polymorphism.
- **Acceptance:** Migrations work forward/rollback on MySQL; one order cannot issue multiple memberships; snapshots preserved.
- **Tests/checks:** migration schema assertions, unique constraint and relationship tests.

#### TASK-020 — Membership plans and unified admin pricing
- **Priority:** P0 · **Depends on:** TASK-019, TASK-011 · **Source:** PLN-001–005, CMS-004, CMS-010.
- **Scope:** CRUD plan types, duration/entry rules, integer grosz pricing, visibility, homepage featured max 3; admin managed and storefront read from same records.
- **Acceptance:** Hidden/inactive plan cannot be purchased; current price changes do not affect previous orders; fourth featured plan rejected atomically.
- **Tests/checks:** Pest plan changes, snapshot, race on featured count.

#### TASK-021 — Calendar-period calculation as pure domain logic
- **Priority:** P0 · **Depends on:** TASK-019 · **Source:** PLN-004, OD-01.
- **Scope:** Tested service/value object accepting date and plan duration; month boundary and leap year behavior; inclusive expiry, no frontend calendar arithmetic.
- **Acceptance:** 28 Sep→27 Oct; 30 Apr→29 May; 31 Jan 2027→28 Feb; 31 Jan 2028→29 Feb; 28 Feb 2027→27 Mar.
- **Tests/checks:** data-driven Pest tests + no timezone shift.

#### TASK-022 — Checkout order and holder identity persistence
- **Priority:** P0 · **Depends on:** TASK-016, TASK-019–020 · **Source:** ACC-001, ACC-004–010, ORD-001–005.
- **Scope:** Explicit purchaser and holder snapshots, guest/registered linking rules, one-plan/one-holder order, required consent version; normalized phone searching without merging guest histories.
- **Acceptance:** Guest buys without account, holder can differ, two distinct family orders share contact safely, buyer cannot tamper with unit price.
- **Tests/checks:** Pest guest vs account, ownership, field tampering and consent.

#### TASK-023 — Paid membership issue action (fake provider first)
- **Priority:** P0 · **Depends on:** TASK-019–022 · **Source:** PAY-003–004, MEM-001, ADR-001.
- **Scope:** `IssuePaidMembership` only after verified payment state; exactly once per order; `pending_activation` and unknown dates; transactional audit/event-outbox boundary if needed.
- **Acceptance:** Paid order issues exactly one pending pass; order creation or browser return alone never activates/creates paid pass.
- **Tests/checks:** Pest duplicate/concurrent issue, rollback and authorization.

#### TASK-024 — First-visit activation
- **Priority:** P0 · **Depends on:** TASK-021, TASK-023, TASK-010 · **Source:** MEM-001, MEM-010, ADR-001.
- **Scope:** Authorized staff/admin activation with lock, one-time write of `activated_at/by`, local date, inclusive expiry and audit; entry consumption separate.
- **Acceptance:** Unpaid/customer requests denied; duplicate activation doesn't move dates; 2 employees racing cannot double activate.
- **Tests/checks:** Pest idempotency/concurrency/date boundaries and audit.

#### TASK-025 — Limited-entry redemption and operation history
- **Priority:** P0 · **Depends on:** TASK-024, TASK-010 · **Source:** MEM-004–006, E2E-04.
- **Scope:** Explicit redeem command, `used_at` UTC, `recorded_by_user_id`, never negative remaining balance; full history past expiry; separate activation/redeem operations.
- **Acceptance:** Last available entry used once; second attempt rejected; staff can inspect dated history.
- **Tests/checks:** Pest simultaneous last-entry lock, expired/cancelled pass, audit.

#### TASK-026 — Renewals, rollover and scheduled periods (approved cases only)
- **Priority:** P0 for approved cases · **Depends on:** TASK-021, TASK-023–025 · **Source:** MEM-002, MEM-008–009, OD-02.
- **Scope:** Future monthly renewal of already activated eligible pass starts after inclusive expiration without second activation; preserve unused entries when approved for same supported entry-plan type, with transactional ledger preventing duplicate carryover.
- **Acceptance:** Renewed monthly period never overlaps; e.g. 3 remaining + 8 purchased = 11; reprocessing cannot carry over twice. Nonapproved mixed-type/unactivated-pass combinations are **blocked with explanatory validation**, not guessed.
- **Tests/checks:** Pest renewals, multi-purchase race, idempotent ledger, explicit unsupported combinations.

### M4 — Sales, payments, PDFs and customer delivery

#### TASK-027 — Reception order and confirmed payment
- **Priority:** P0 · **Depends on:** TASK-022–024, TASK-009 · **Source:** PAY-005–006, FLOW-08.
- **Scope:** Staff creates order for holder with cash/card channel and records confirmation; issues pending pass; audit actor and payment method.
- **Acceptance:** Receipt payment method and sale channel are distinguishable; unpaid reception order never issues pass.
- **Tests/checks:** Pest staff permissions, cash/card, duplicate submits.

#### TASK-028 — Przelewy24 adapter and order initiation
- **Priority:** P0 · **Depends on:** TASK-022–023 · **Source:** PAY-001–002, `security.md`.
- **Scope:** Isolate P24 client; credentials only from secure config; initialize transaction using order snapshot, persist external IDs and retries; handle unavailable provider.
- **Acceptance:** Cannot alter price via browser; transient provider failure leaves order in traceable state without pass.
- **Tests/checks:** HTTP fake + provider contract (per official integration specification) and timeout.

#### TASK-029 — Verified P24 notification, idempotency and reconciliation
- **Priority:** P0 · **Depends on:** TASK-028, TASK-023 · **Source:** PAY-003–004, PAY-007.
- **Scope:** Verify trusted callback/order/amount/currency/provider state; locked exactly-once paid transition and membership issue; handle retry/replay; record diagnostics without leaking secrets.
- **Acceptance:** Forged or mismatched notification rejected; 2 simultaneous valid notifications yield one pass; return URL cannot finalize payment.
- **Tests/checks:** Pest success/failure/replay/concurrency; integration sandbox checks before release.

#### TASK-030 — Checkout UI (guest, registered, family)
- **Priority:** P0 · **Depends on:** TASK-005, TASK-016, TASK-022, TASK-028 · **Source:** ORD-001–006, PAY-007, reference checkout HTML.
- **Scope:** Choose plan and holder, dynamic own/other fields, terms consent, payment redirect, explicit statuses and error/retry UX; no account required.
- **Acceptance:** Purchase screen clearly explains activation at first visit; success says **paid, pending activation** (not active). One checkout contains one holder and plan.
- **Tests/checks:** frontend form tests + E2E mocked gateway.

#### TASK-031 — PDF template and private document storage
- **Priority:** P0 · **Depends on:** TASK-023, TASK-026 · **Source:** PDF-001–003, ADR-001, `security.md`.
- **Scope:** Generate PDF after payment; name/surname of holder only for personal data, plan and relevant terms; pending pass has no invented dates; future dated renewal shows dates; private storage.
- **Acceptance:** PDF generated for both guest/account/family purchases; unauthorized direct file access blocked.
- **Tests/checks:** Pest content/date-safety/privacy tests, private download authorization.

#### TASK-032 — Email delivery, retry and manual resend
- **Priority:** P0 · **Depends on:** TASK-031, TASK-010 · **Source:** NTF-002–003, FLOW-09.
- **Scope:** Queue after commit, delivery table/attempts, bounded retries, idempotency and admin-only resend; no re-payment/re-issue on failure; email destination from purchase.
- **Acceptance:** Failed transport changes only delivery status; retries and manual resend deliver existing pass.
- **Tests/checks:** Mail/Queue fakes, retries, double-dispatch safety and audit.

#### TASK-033 — Payment result pages and order history
- **Priority:** P0 · **Depends on:** TASK-029–032 · **Source:** PAY-006–007, MEM-006.
- **Scope:** Pending/failed/cancelled/successful states, no false payment success, sales channel and method shown to authorized users, retry flow with no duplicate pass.
- **Acceptance:** User sees precise nontechnical message; order remains available in history; unresolved payment never issues pass.
- **Tests/checks:** feature tests of transitions and E2E error paths.

#### TASK-034 — Operational recovery and P24 sandbox acceptance
- **Priority:** P0 · **Depends on:** TASK-029–033 · **Source:** `security.md`, `testing-strategy.md`.
- **Scope:** Verify callbacks in sandbox, stuck payment and delivery diagnostics, safe manual recovery steps, retry caps, no duplicate issue.
- **Acceptance:** Written runbook demonstrates failed callback, failed e-mail, delayed confirmation recovery.
- **Tests/checks:** sandbox acceptance + recovery test report.

### M5 — Staff/customer/admin UI and release quality

#### TASK-035 — Staff holder search, details and activation screen
- **Priority:** P0 · **Depends on:** TASK-024–025, TASK-005 · **Source:** STAFF, ACC-010, ADR-001.
- **Scope:** Search by normalized phone, list holders separately even for shared contact, show payment/pass status, activation action with confirmation, entry redemption separate, dated entry history.
- **Acceptance:** Staff does not confuse family holders; pending pass shows no fake expiry; activation button disabled after successful activation; concurrent backend protection remains authoritative.
- **Tests/checks:** Pest access/search and E2E first-visit flow.

#### TASK-036 — Customer dashboard and membership history
- **Priority:** P1 · **Depends on:** TASK-006, TASK-023–026, TASK-031 · **Source:** ACC-006, MEM-006, ADR-001.
- **Scope:** Logged-in user sees own passes with pending/scheduled/active/expired states, dates only if known, history and authorized PDF access; guest purchases never auto-appear.
- **Acceptance:** Account customer cannot view others' records; newly registered user doesn't see earlier guest purchases.
- **Tests/checks:** Pest policy and Vue/E2E empty/pending/history states.

#### TASK-037 — Admin sales, membership and audit views
- **Priority:** P0 · **Depends on:** TASK-010, TASK-020, TASK-025, TASK-027–033 · **Source:** ADM-002–010.
- **Scope:** Orders/payments list, sales-channel/method filter, holder history, audit log and delivery resend; role-restricted actions only.
- **Acceptance:** Admin can trace who changed a pass and why; old passes remain visible; safe filters and pagination.
- **Tests/checks:** authorization, filtering, audit completeness and data-leak checks.

#### TASK-038 — Admin dashboard
- **Priority:** P1 · **Depends on:** TASK-037 · **Source:** ADM-001 (`SHOULD`, confirmed).
- **Scope:** Active/expiring passes, customers, sales and recent orders; date window and status semantics consistent with first-visit activation.
- **Acceptance:** Pending passes are not counted as active; financial totals use confirmed payments and integer grosz.
- **Tests/checks:** dashboard aggregation and edge dates; performance query check.

#### TASK-039 — Critical E2E and release security regression
- **Priority:** P0 · **Depends on:** TASK-018, TASK-034–038 · **Source:** `testing-strategy.md`, `security.md`.
- **Scope:** Browser smoke paths for guest/family purchase, first visit activation, entry final use, CMS publication, profile, employee suspension, P24 and PDF failure; accessibility/mobile and auth/CSRF/XSS coverage.
- **Acceptance:** Required P0 paths pass CI; no open high-severity security issue; active state never displayed before actual first visit.
- **Tests/checks:** Playwright if approved; Pest mandatory, manual release checklist for external provider.

#### TASK-040 — Deployment, monitoring and handover
- **Priority:** P0 · **Depends on:** TASK-039 · **Source:** `repository-setup.md`, `security.md`.
- **Scope:** Staging deploy, backups/restore test, queues/scheduler, mail provider, HTTPS/secure cookies, P24 secrets, alerting/logging, image storage and production runbook.
- **Acceptance:** Staging purchase-to-activation-to-PDF works; restore verified; rollback plan documented; no test transactions pollute live data.
- **Tests/checks:** deployment smoke, backup restore evidence and secret/config audit.

## 5. Explicit unresolved decisions — do not invent business rules

| Key / theme | Current status | Impact / safe handling |
| --- | --- | --- |
| Refunds, cancellation and manual corrections (PAY-008, MEM-007) | OPEN | Defer refund/cancel workflow; preserve schema flexibility but no speculative behavior or authorization. |
| Renewing a **not-yet-activated** pass | OPEN (ADR-001) | Block/avoid creating undefined future date. Decide before enabling that checkout combination. |
| Mixed plan renewals, multiple concurrently applicable plans | OPEN (MEM-009) | Implement only approved supported same-kind renewal; explain unsupported combination. |
| Carryover of entries **after** expiry | OPEN | Do not silently transfer past-expired balances. Resolve before enabling affected path. |
| Maximum unactivated pass lifetime | OPEN | No automatic expiry until business decision; monitor operationally. |
| Minors / holder identity verification | OPEN (ORD-007) | Do not infer legal process; obtain policy before expanding minor-specific flows. |
| Data retention and statutory fiscal obligations | OPEN / legal review | Implement minimum personal data, defined restricted access, and review prior to production. |
| Vue component/E2E tool approval | PROPOSED | Pest is confirmed; approve Vitest and Playwright or document replacements. |

**Release blocker policy:** Issues affecting legal compliance, payment correctness, access control or data safety block production. Optional features may be deferred with explicit recorded scope and no hidden broken paths.

## 6. Standard AI-agent task contract

For each `TASK-NNN`, the implementing agent must produce a PR description containing:

```markdown
## TASK-NNN — <title>
### Requirements
- Requirement IDs and architecture references
### Scope
- Included work; explicitly excluded work
### Implementation
- Routes, schema changes, actions, views, policies and jobs touched
### Tests
- New/updated tests with exact commands and results
### Security & edge cases
- Authorization, validation, concurrency, privacy, error states
### Review evidence
- Screenshots for UI, before/after behavior, migration notes
### Open questions
- Any behavior not defined in approved docs; do not guess
```

**PR size rule:** If a task needs a large cross-module change, split it into sub-PRs while retaining the same traceability ID. Do not combine unrelated refactors with feature implementation.

## 7. Definition of Done for each task

- Acceptance criteria implemented against approved source; no invented business rules.
- Validation and authorization enforced on backend; sensitive values protected.
- Migrations reversible where feasible, constraints and indexes verified on MySQL.
- Automated Pest tests cover both success and failure for business logic; concurrency tests for payment, first activation and last entry.
- Vue pages typed, accessible, responsive and consistent with provided HTML; functional loading/error/empty states.
- Formatting, lint, typecheck, build and relevant tests pass in GitHub Actions.
- No plaintext secrets or customer/payment data committed; logs safe.
- Critical events auditable; docs/ADR updated if behavior or schema changed.
- Reviewer validates correctness, readability, performance, security and test coverage.

## 8. Recommended first execution batch

Run **TASK-001 → TASK-002 → TASK-003 → TASK-004** in sequence. TASK-005 can follow. In parallel after TASK-006, begin TASK-007/008/010; do **not** start production Przelewy24 payments before the order/membership model and idempotent issue action are tested. 

A practical first demo after M1/M2 is **public website + restricted CMS working on real MySQL**, with purchase buttons still behind a clearly disabled state until the payment domain is production-ready. A separate milestone after M3 demonstrates **fake-paid order → pending activation → staff first-visit activation → dated entry redemption**, before connecting Przelewy24.

## 9. Document status and maintenance

- This file is a **plan**, not a progress tracker: record actual delivery in GitHub Issues/Projects and reference `TASK-NNN` in branch, PR and commit descriptions.
- On each approved requirement change, update the corresponding requirement, ADR (if architectural), this plan's scope/dependencies and its tests in the same PR.
- Mark items as **DONE** only after merged code and green checks, not when the agent generates files.
- Next step: create GitHub Issues for `TASK-001` through `TASK-005` and start foundation work.
