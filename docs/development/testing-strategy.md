# ActiveFit — Testing Strategy

- **Version:** 1.0
- **Status:** DRAFT — ready for review
- **Date:** 2026-11-05
- **Applies to:** Laravel + Inertia.js + Vue 3 + TypeScript + MySQL + Przelewy24
- **Process:** AI SDLC; test-first for domain rules, incremental verification per feature

## 1. Purpose and sources of truth

This document defines **what to test, where, with which tools, and what must pass** before an ActiveFit change is merged or released. This is a testing plan, **not** a claim that tests or CI already exist.

Authoritative project documents:

1. `docs/product/requirements.md` — functional requirements and acceptance scenarios.
2. `docs/architecture/decisions/ADR-001-activation.md` — **ACCEPTED** first-visit activation.
3. `docs/architecture/business-flows.md` — transaction and process boundaries.
4. `docs/architecture/database.md` and `docs/architecture/cms.md` — persistence and CMS.
5. `docs/architecture/security.md` — authorization, privacy and payment boundaries.
6. `docs/architecture/frontend.md` — Inertia page and component structure.
7. `AGENTS.md` — agent workflow and repository conventions.

When implementation and documentation differ, **do not silently change the business rule**. Record the discrepancy and resolve the source document first. `OPEN` decisions are not acceptance criteria for a chosen business behavior.

## 2. Quality strategy and test layers

| Layer | Main purpose | Recommended tooling | Run cadence |
| --- | --- | --- | --- |
| PHP unit/domain | Dates, state transitions, calculation rules, pure policies | Pest + PHPUnit assertions | Each PR |
| Laravel feature/integration | HTTP, authorization, models, database transactions, PDF/e-mail jobs | Pest + Laravel HTTP/DB/Mail/Queue/Storage fakes | Each PR |
| External gateway contract | Request/response mapping, signature/verification, retry, mismatch | Pest + HTTP fake / gateway sandbox | Each PR for mock; sandbox pre-release |
| Vue component | Rendering, form states, validation feedback, responsive interaction semantics | Vitest + Vue Test Utils (recommended, not yet installed) | Each frontend PR |
| E2E browser | End-to-end user journeys incl. redirects, role navigation, checkout UI | Playwright (recommended, not yet installed) | Critical smoke per PR / full suite before release |
| Nonfunctional | Accessibility, security, race conditions, performance, backups | axe tooling (recommended), dependency audits, targeted load tests | PR/release depending on risk |

**Testing database:** Use MySQL for integration tests whose behavior depends on MySQL-specific constraints, locking, transactions, unique indexes or collation. Lightweight SQLite tests are acceptable only for isolated tests that do **not** assert MySQL-equivalent behavior. Never treat SQLite's locking semantics as evidence that concurrent payment/entry tests pass on MySQL.

**Environment:** Always use an isolated testing database, fake mail/storage/queue, and a fake or sandbox Przelewy24 gateway. Never call production Przelewy24 or send real customer emails from automated tests. Never use production personal data in fixtures.

**Implemented baseline (TASK-002):** `composer test` runs Pint, PHPStan and Pest with in-memory SQLite. `make test-mysql` starts the isolated Compose `mysql-test` service and runs `composer test:mysql` using `phpunit.mysql.xml`; native setup is documented in `README.md`. The MySQL test database/user are `activefit_testing`, independent of development. Do not run concurrent suites against that same database. Both PHPUnit configurations force in-memory mail/cache/session drivers and synchronous queues; `EnvironmentTest` explicitly exercises the real database queue with the array mail transport, including commit/rollback and worker processing. Test bootstrap refuses non-testing configuration or a database outside the allowed test targets. Default seeders contain no demo accounts; tests create their own fixtures through factories.

## 3. Non-negotiable domain invariants

These invariants must be exercised at the API/DB boundary, not only in Vue:

1. **Payment ≠ activation.** Successful verified payment issues **exactly one** `pending_activation` membership; `starts_on`/`expires_on` remain `NULL` until first visit, except confirmed advance renewals with known future dates.
2. **First-visit activation** is performed only by an authorized employee/admin. It records actor, activation time, Warsaw start date, inclusive end date, audit event; is idempotent and concurrency-safe. Activating a limited-entry pass **does not use an entry**.
3. **Monthly validity:** same day of next month minus one day if that calendar date exists; if not, **last day of next month**, inclusive. Examples: 2026-09-28 → 2026-10-27; 2026-04-30 → 2026-05-29; 2027-01-31 → 2027-02-28; 2028-01-31 → 2028-02-29; 2027-02-28 → 2027-03-27. Do not apply previous month-end anchoring rule.
4. **Renewals:** approved renewal of an active time pass starts the day **after** the inclusive expiry; never from early purchase date. Same-category limited-entry renewals carry unused entries once; unsupported cross-type / unactivated-renewal behavior is `OPEN`.
5. **Purchaser ≠ holder:** every order has distinct purchaser/holder snapshots as applicable. One order buys **one** pass. Guest purchases never create temporary users or later merge with registered users.
6. **Account uniqueness:** normalized e-mail and phone are unique in `users`, not globally unique across guest order snapshots; phone search can return multiple distinct holders.
7. **Immutable purchase snapshot:** changing plan price/terms in CMS never rewrites past orders/memberships. Monetary values are integer grosz, no float math.
8. **Entry usage:** `used_at` and recording actor are stored. Remaining entries never go below zero; two concurrent recordings must not both consume the last remaining entry; activation alone does not consume an entry.
9. **PDF delivery:** send PDF after successful payment for all purchase modes, including advance renewals, **regardless of activation date**. Pending PDF contains no invented dates and no phone/e-mail (name, surname, club, plan, conditions are allowed). Mail failure never retries payment or creates a new membership.
10. **Audit:** critical membership and sale-configuration mutations create immutable staff/system audit entries; denied actions do not mutate data.
11. **CMS:** single-place image ownership, deletion removes the owned file (with safe post-commit cleanup/retry), category has title/short description and photo has no descriptive metadata; regulations are versioned and the accepted version is tied to the order.
12. **Access control:** `employee` cannot manage other employees, roles or global configuration; all authenticated roles can edit their own profile without gaining extra roles or changing purchase snapshots.

## 4. Test suites and suggested filenames

Suggested structure (adapt to the actual starter; paths are guidance):

```text
 tests/
 ├── Unit/
 │   ├── Memberships/MonthlyExpiryCalculatorTest.php
 │   ├── Memberships/RenewalPolicyTest.php
 │   └── Money/PriceSnapshotTest.php
 ├── Feature/
 │   ├── Auth/ProfileUpdateTest.php
 │   ├── Auth/AdminSeederTest.php
 │   ├── Checkout/GuestCheckoutTest.php
 │   ├── Checkout/PurchaseForAnotherPersonTest.php
 │   ├── Payments/Przelewy24NotificationTest.php
 │   ├── Memberships/FirstVisitActivationTest.php
 │   ├── Memberships/EntryRedemptionTest.php
 │   ├── Memberships/RenewalTest.php
 │   ├── Memberships/MembershipHistoryTest.php
 │   ├── Notifications/MembershipPdfDeliveryTest.php
 │   ├── Staff/HolderSearchTest.php
 │   ├── Admin/EmployeeManagementTest.php
 │   ├── Admin/AuditLogTest.php
 │   └── Cms/
 │       ├── RegulationsTest.php
 │       ├── MediaDeletionTest.php
 │       ├── PostsTest.php
 │       ├── GalleryTest.php
 │       ├── PricingTest.php
 │       └── ClubSettingsTest.php
 └── Support/
     └── ...
 resources/js/
 └── ... (component tests colocated as *.spec.ts, or in a tests/frontend/ directory)
 e2e/
 ├── purchase-guest.spec.ts
 ├── purchase-for-other.spec.ts
 ├── staff-activation.spec.ts
 ├── limited-entry.spec.ts
 └── cms-admin.spec.ts
```

Avoid real gateway credentials or real mail addresses in fixtures. Test fixture factories should clearly distinguish purchaser, holder, staff, plan, paid order and membership states.

## 5. Critical acceptance test matrix

| ID | Requirement / flow | Preconditions and action | Required result | Layer | Priority |
| --- | --- | --- | --- | --- | --- |
| T-001 | `PAY-003`, `MEM-001`, ACT-01 | Verified online payment for a new pass | Exactly one `pending_activation` membership; dates NULL, `paid_at` stored | Integration | P0 |
| T-002 | `PAY-004` | Same verified payment notification delivered 2+ times | Exactly one membership, one issuance and no repeated accounting effects | Integration/concurrency | P0 |
| T-003 | `PAY-007` | Failed/cancelled/ambiguous payment; user refresh/retry | No pass issued until verified paid; meaningful state; no double charge triggered by app retry | Integration/E2E | P0 |
| T-004 | `ORD-003`, `ORD-005`, `ACC-005` | Purchase for partner | Separate purchaser and holder snapshots; one order/one pass | Integration/E2E | P0 |
| T-005 | `ACC-001`, `ACC-008` | Guest purchase, later account registration | No temporary account and no guest history imported into account | Integration | P0 |
| T-006 | `MEM-001`, `MEM-010`, ACT-01 | Employee activates paid pass at first visit | Actor/time/audit saved; Warsaw day; correct expiry; `active` status | Integration/E2E | P0 |
| T-007 | ACT-01 | Double activation or concurrent employees | Dates unchanged; only one effective activation and audit effect | MySQL concurrency | P0 |
| T-008 | `MEM-004`, `MEM-005`, E2E-04 | Redeem last entry | Record `used_at`, actor, remaining=0; next redemption rejected | Integration/E2E | P0 |
| T-009 | `MEM-005` | Concurrent redemption of final entry | Only one successful transaction, no negative remainder | MySQL concurrency | P0 |
| T-010 | `PLN-004` | Month boundary data set | Exact dates from section 3 including leap years | Unit | P0 |
| T-011 | `MEM-002` | Buy renewal before expiry | Starts following day after previous `expires_on`, no overlap | Unit/integration | P0 |
| T-012 | `MEM-008` | Renew limited pass with unused entries | Carry-forward occurs exactly once; entry ledger auditable | Unit/integration | P0 |
| T-013 | `PDF-003`, `NTF-002` | Paid new pass pending activation | PDF sent after payment; shows pending notice; no dates/phone/e-mail | Integration/PDF inspection | P0 |
| T-014 | `NTF-002`, ACT-01 | Buy renewal with known future dates | PDF sent after payment, **not** deferred until start | Integration | P0 |
| T-015 | `NTF-003` | Mail transport fails then retries | Payment + membership unchanged; notification attempts tracked; retry sends same pass | Integration | P0 |
| T-016 | `ADM-010` | Staff edits a pass or changes sales config | Audit identifies actor, time, target, old/new safe fields | Integration | P0 |
| T-017 | `ACC-007`, `ACC-010` | Duplicate normalized registered e-mail/phone | Duplicate rejected; guest contact snapshots remain permitted | Integration/MySQL | P0 |
| T-018 | `STAFF-*` and security | Employee calls admin employee/settings endpoints | HTTP 403 and no mutation | Feature | P0 |
| T-019 | `ACC-011`, `ACC-012` | Update own profile/password/e-mail | Allowed for all roles; uniqueness/reverification; no role privilege escalation | Feature | P0 |
| T-020 | `CMS-001`, `CMS-011` | Publish new regulation; complete checkout | Order links to accepted immutable version; old version retrievable | Feature | P0 |
| T-021 | CMS-OD-04 | Submit rich HTML with script/event handler | Dangerous markup is removed/rejected; safe markup renders | Feature/component | P0 |
| T-022 | CMS-OD-05 | Delete single-use gallery image | Reference removed; file deleted after commit; failed deletion recoverable | Integration | P1 |
| T-023 | `ADM-001` | View dashboard with mixed status fixtures | Aggregate values use paid orders / derived validity, not raw order count | Feature | P1 |
| T-024 | `CMS-004`, `PLN-005` | Change current plan price | New purchase sees new price; existing snapshot unchanged | Feature | P0 |
| T-025 | Staff holder search | Shared phone among family members | Separate, distinguishable holder results; no false deduplication | Feature/UI | P0 |
| T-026 | `ADM-005..007` | Create staff then suspend session | Set-password link sent; suspended staff cannot continue authenticated work | Feature | P0 |
| T-027 | Admin seeder | Run twice and with e-mail collision | No duplicate; do not reset password or hijack non-admin account | Integration | P0 |
| T-028 | History after expiry | Advance clock beyond expiry | Orders/entries/audit/history still accessible to authorized users | Feature | P1 |
| T-029 | CMS primary pages | Mobile list, gallery, pricing, carousel | Proper empty/error/loading and keyboard/mobile behavior | Component/E2E | P1 |
| T-030 | P24 amount and order binding | Fake webhook with valid-looking ID but wrong amount/order | Reject; no membership issued | Integration/security | P0 |

`STAFF-*` in T-018 refers to the staff authorization requirements in `requirements.md`; use the concrete ID when final route definitions are approved. Tests for `OPEN` behaviors (refund, cross-type renewals, minors, dormant pending pass expiry) should initially assert that unsupported actions are blocked **without inventing a business policy**.

## 6. Mandatory date test data

Use explicit day-only cases, never `now()` without freezing time. Business dates are interpreted in `Europe/Warsaw`; persisted timestamps are UTC.

| Start | Expected inclusive expiry | Reason |
| --- | --- | --- |
| 2026-09-28 | 2026-10-27 | Ordinary month |
| 2026-04-30 | 2026-05-29 | No automatic month-end anchoring |
| 2027-01-31 | 2027-02-28 | Corresponding day missing |
| 2028-01-31 | 2028-02-29 | Leap February, missing day |
| 2027-02-28 | 2027-03-27 | Corresponding day exists |
| 2028-02-29 | 2028-03-28 | Leap day start |
| 2026-11-01 | 2026-11-30 | First-of-month start |

Also test a first visit across UTC/local midnight and daylight-saving transitions: the **calendar day in Warsaw** controls `starts_on`, not raw UTC date. `expires_on` is inclusive in UI, API and entry-eligibility checks.

## 7. Payments: idempotency, atomicity and contract tests

- Fake gateway initiation and callback verification separately. Cover success, rejected, timeout, stale notification, duplicate notification, signature/verification failure, order mismatch, amount mismatch, and conflicting states.
- Assert one committed order → at most one issued membership (database uniqueness and transactional handling; UI double-submit protection is secondary).
- Assert failed payment callbacks do not send PDFs or issue passes.
- For reception transactions, verify staff-reported cash/card confirmation; do not automatically trust an unverified public request.
- Use deterministic synchronization (barriers / separate DB connections) for MySQL race tests. Two sequential calls are **not** a concurrency test.
- Test job dispatch **after transaction commit** so no PDF/e-mail is produced for rolled-back issuance.
- Przelewy24 sandbox smoke before release requires explicit test credentials in secrets, never in Git or CI logs.

## 8. PDF, e-mail and asynchronous work

Use `Mail::fake()`, `Queue::fake()`, `Storage::fake()` where suitable, plus **at least one** integration test that runs the actual PDF generator and checks content/bytes. Faking every layer would conceal broken PDF output.

Scenarios:

- Purchase with/without account, for another holder, reception: PDF issued to the designated email after confirmed payment.
- PDF pending activation: contains name/surname, plan, club, explicit first-visit status; excludes unknown dates, e-mail and phone.
- Already scheduled renewal: PDF includes known dates, delivered after payment, not on the later start date.
- Mail failure, retries, manual resend, concurrent retry: no new order/payment/membership and auditable attempts. Avoid making delivery status a payment status.
- File access: private storage only, authorized customer (when account-linked), staff/admin permissions, no guessable public URLs.
- After activation, the prior sent pending PDF is not silently assumed to contain newly known dates; no unsolicited second email unless separately approved.

## 9. Permissions, privacy and CMS

### 9.1 Authorization matrix tests

For **every protected write route**, test customer, employee, administrator, unauthenticated guest, and (where relevant) suspended employee. Test own-vs-other resource access (IDOR). HTTP 403/404 differences may follow route policy; **no unauthorized mutation or data disclosure** is the requirement.

- All roles: profile edit allowed only for self; cannot inject `role`, `is_active`, audit fields or price into request to escalate privileges.
- Employee: holder lookup, reception sales, entries and first-visit activation only within approved policy; cannot manage employees, CMS global settings or role configuration.
- Administrator: management actions plus audit access; even admin actions validate inputs and log critical changes.
- Guest checkout: receipt / PDF links must not permit enumerating another customer's orders.

### 9.2 CMS tests

- Posts: draft/visible transitions, date publishing, sorting, title/HTML sanitation, disabled public visibility.
- Gallery: one image belongs to one place; category name/short description; no per-photo content fields; sort order; deletion removes DB relation and underlying file.
- Carousel and offer tiles: visibility/order, image ownership, no duplicate attachment to other CMS entities.
- Pricing: max 3 featured items on homepage including concurrent admin submissions; new prices only affect new purchases; invalid negative prices are rejected.
- Regulation: **single current content**, immutable previous versions, version ID on purchased order, accepted checkbox required; checkout rejects missing acceptance / stale version according to validated UX flow.
- Club contact: one opening interval per weekday; no holiday exceptions as currently agreed.
- Upload: content-type and file-signature checks, allowlisted image formats, size limits, path traversal and SVG script/XSS defense. No executable uploads.
- Rich HTML: sanitize on server; Vue renders only trusted sanitized output; test event handlers, `<script>`, `javascript:` URLs, hostile attributes and image URLs.

## 10. Frontend and E2E testing

### 10.1 Component testing

- `MembershipStatusCard`: awaiting payment vs pending activation vs scheduled vs active vs expired vs exhausted; pending does **not** display fabricated validity dates.
- Checkout forms: purchaser vs holder, account vs guest, required regulation acceptance, invalid email/phone, loading/submission disabled, server-side validation feedback.
- Staff search: multiple holders on same phone appear separately with useful labels.
- Activation: explicit confirmation; successful date update; duplicate / conflict feedback without incorrect local state.
- CMS: table responsiveness, forms, reorder/visibility toggles, deletion confirmation and error/retry states.
- Profile: editable self details for every role, password update and email verification messaging.

### 10.2 Browser E2E smoke journeys

1. Guest buys using fake gateway → payment confirmed → sees pending pass → PDF notification queued → employee activates at first visit.
2. Registered buyer buys for partner → separate holder shown in staff search → corresponding PDF delivered.
3. Active holder renews early → new period scheduled after expiry → PDF delivered immediately after payment.
4. Employee records final limited entry → `used_at` persisted → further entry rejected.
5. Admin publishes versioned regulation + new pricing → buyer acknowledges current regulation, old order snapshot remains unchanged.
6. Admin creates/suspends employee → suspended user loses protected access; employee denied admin management page.

Run E2E against isolated seeded fixtures and deterministic fake payment service. Do not drive E2E through production payment pages.

### 10.3 UX and accessibility acceptance

Check keyboard-only operation, visible focus, form labels and errors, modal focus management, sufficient contrast, meaningful status text besides color, no horizontal page overflow at 320px, and responsive admin tables. Prefer automated axe checks supplemented with manual verification for critical journeys. Public route navigation and metadata must remain functional without relying on browser-only state; measure SSR/SEO configuration once chosen.

## 11. CI/CD gates and quality thresholds

**Implemented baseline (TASK-003):** `.github/workflows/tests.yml` defines check **CI** for pull requests, pushes to `main`, and manual dispatch. It installs locked dependencies on PHP 8.4 / Node 24 and runs `composer ci:check`: strict Composer validation, production frontend build (before checking generated route types), Vite+ formatting/lint, Vue/TypeScript typecheck, Pint, PHPStan, then Pest with `phpunit.mysql.xml` and disposable MySQL 8.4. Composer/npm download caches depend on lockfiles; `.env`, application keys and installed dependency directories are not cached. Actions are pinned to commit SHAs, token permissions are `contents: read`, duplicate runs are cancelled, and the job times out after 15 minutes. No production secret is referenced. Repository settings must separately require **CI** and PR approval on `main` after the first hosted run; publishing and hosted-run verification are not implied by local validation.

**Minimum PR checks** (enable as tools enter repository):

1. `composer validate` and PHP static analysis/lint as configured.
2. `vendor/bin/pint --test` (if Laravel Pint installed).
3. `php artisan test` / `vendor/bin/pest` on the configured test environment.
4. `npm run typecheck` (create script if absent; do not assume starter provides it).
5. `npm run lint` and `npm run build` (once scripts are configured).
6. Vitest on frontend changes once installed.
7. MySQL-backed integration suite for DB-sensitive business changes.
8. Critical Playwright smoke suite after environment is available (required before first production release).
9. Dependency/security audit, secret scan and migration review for release.

**Blocking gate:** No merge with failing P0 test, failing static check, or known high-severity security issue. P0 workflows must have automated acceptance coverage before release. Do **not** demand an arbitrary global coverage percentage as a substitute for business-critical scenarios; report line/branch coverage when configured and track gaps by requirement ID.

**CI safety:** Use GitHub Actions with disposable MySQL test service and temporary app key; no production secrets, no real customer data. Artifact retention for CI logs/screenshots should be limited; sanitize customer fields in failure snapshots.

## 12. Test data and deterministic execution

- Prefer model factories/seeders **only for test fixtures** (admin production seeder is a separate concern).
- Freeze clock for date calculations and fake system time for jobs; use Warsaw timezone test vectors.
- Build fixtures for: guest purchaser, registered holder, family shared phone, pending paid pass, scheduled pass, expired pass, exhausted pass, limited entry with one remaining, failed payment, suspended staff, published/old regulations, reusable and deleted images (ownership rules).
- Run integration tests in transactions where safe; concurrency tests may need separate DB connections and cleanup outside `RefreshDatabase` transaction wrapping.
- Random test data must be reproducible; fixed time and seeded randomness preferred for failed runs.
- Use only test-domain emails (e.g., `example.test`) and dummy normalized phone numbers.

## 13. AI SDLC — expected deliverables per feature

Each implementation task must include:

1. Requirement IDs and business-flow reference.
2. Acceptance cases: happy path, invalid input, unauthorized access and relevant race/idempotency cases.
3. Tests added or changed **in the same PR** as implementation.
4. Evidence: command(s) executed, pass/fail summary, environment/DB and known untested cases.
5. Review checklist: correctness, security, performance, accessibility, observability and regressions.
6. Updated architecture/ADR when behavior changes (never silently rewrite confirmed rules).

**Code review must reject** mocked tests that trivially assert their own stubs without exercising production code, UI-only authorization, payment side effects outside safe transaction boundaries, and tests that infer an `OPEN` business rule as confirmed.

## 14. Out-of-scope decisions and risks

Do not encode assumptions for these as final behavior until separately approved:

- Refund/cancellation rules (`PAY-008`) and refund-related membership transitions.
- Cross-type renewals and independent simultaneous pass types (`MEM-009`).
- Renewing a pass that has not yet been activated.
- Time limit between payment and the first visit (pending validity lifetime).
- Carry-forward after prior limited pass expiry when renewal timing is disputed.
- Purchases for minors and legal/identity rules (`ORD-007`).
- Retention and deletion periods for personal data, audit logs, PDF and backups; legal review before production.

Tests may assert **unsupported actions are rejected** until a decision is approved; they must not invent default business outcomes.

## 15. Release test checklist

- [ ] All P0 cases T-001–T-030 that are marked P0 have automated passing coverage.
- [ ] MySQL concurrency tests pass for payment notification, activation and last-entry redemption.
- [ ] P24 fake contract tests pass, and sandbox transaction verified before production.
- [ ] PDF content and mail failure/retry flows verified (including pending activation).
- [ ] Full role authorization and own-resource access tests pass.
- [ ] CMS HTML/XSS, uploads, media deletion and regulation versioning tests pass.
- [ ] Vue responsive, keyboard and critical browser journeys reviewed.
- [ ] No real credentials/customer data in fixtures, logs or test artifacts.
- [ ] Database migrations, rollback and backup/restore procedure reviewed.
- [ ] `OPEN` business decisions remain explicit and do not silently ship as invented functionality.

## 16. Next step

Prepare `docs/development/implementation-plan.md`, breaking requirements into small, ordered tasks with dependencies and references to test IDs T-001–T-030. Start with repository foundation, CI, authentication/authorization, core membership dates/states and payment boundary. Existing HTML in `design/reference-html/` remains the UX reference, not production Vue code.

## 17. TASK-034 — Procedura odzyskiwania i odbiór sandbox P24

### Zakres i warunki uruchomienia

Procedura dotyczy PAY-003/004/007, NTF-002/003 i ACT-01. Diagnostyka i automatyczne testy są oddzielne od odbioru rzeczywistej integracji. Pozytywny test HTTP fake nie potwierdza połączenia z operatorem, publicznego callbacku ani dostarczenia SMTP. Źródło kontraktu sprawdzone 2026-11-25: [oficjalna dokumentacja P24 REST](https://developers.przelewy24.pl/index.php?en) i [specyfikacja 1.0.18](https://developers.przelewy24.pl/yaml/en_documentation_1.0.yaml). Powiadomienie dotyczy skutecznej opłaty; sam brak callbacku nie dowodzi niepowodzenia lub anulowania.

Rzeczywisty odbiór wykonujemy na osobnym stagingu z własną bazą, prywatnym dyskiem PDF i testowymi danymi. Nie używamy bazy klubu, klientów, produkcyjnego P24 ani rzeczywistych obciążeń. Klucze wpisuje operator do sekretów środowiska; nie przesyła ich przez czat, Git, argumenty poleceń lub raport. SMTP powinien kierować do przechwytującej skrzynki testowej. Faktyczna wysyłka na zewnętrzny adres wymaga osobnej zgody zgodnie z AGENTS.md.

Przygotowanie stagingu:

1. Ustaw publiczny `APP_URL` z HTTPS, `APP_ENV=staging`, `APP_DEBUG=false`, osobną bazę i klucz aplikacji. Wdróż kod/zależności, wykonaj migracje wyłącznie na tej bazie i sprawdź uprawnienia prywatnego dysku PDF. Nie kopiuj danych osobowych z produkcji.
2. Ustaw `P24_ENVIRONMENT=sandbox`, `P24_LIVE_ENABLED=false`, `P24_ENABLED=true` oraz identyfikator sprzedawcy, POS, klucz API/report i osobny CRC z konta sandbox. `P24_RETURN_URL` ma wskazywać na publiczne `/platnosc/powrot`, a `P24_STATUS_URL` na `/payments/przelewy24/notification` tej samej instalacji. Zastosuj zmianę cache konfiguracji i zrestartuj workery zgodnie ze sposobem wdrożenia. Nie drukuj konfiguracji ani pełnego `.env`.
3. Sprawdź poprawny HTTPS/certyfikat, reverse proxy, bezpieczne cookies i zachowanie pierwotnej sesji po powrocie. Callback musi być osiągalny dla P24 bez logowania, sesji, blokady Basic Auth i CSRF. Pozostałe formularze zachowują CSRF. Nie wyłączaj podpisu ani weryfikacji transakcji, aby „przepuścić” test.
4. Przygotuj testowy plan oraz opublikowany regulamin w izolowanym CMS, konta testowego administratora/pracownika i kontrolowane dane kupującego/posiadacza. Dane konta P24 muszą być zgodne z sandboxem; nie wolno zmieniać przestrzeni sprzedawcy istniejącej płatności.
5. Uruchom worker database/default i scheduler. Przy działającym Compose aplikacji, w osobnych procesach:

```bash
docker compose exec app php artisan queue:work database --queue=default --tries=1 --timeout=60
docker compose exec app php artisan schedule:work
docker compose exec app php artisan sales:diagnose --limit=25
```

Na serwerze bez Compose użyj odpowiadających poleceń `php artisan` i nadzorowanych procesów; na wdrożeniu scheduler to `schedule:run` co minutę. Diagnostyka nie uruchamia procesów i nie sprawdza ich żywotności.

### Diagnostyka przed działaniem

`sales:diagnose` wykonuje wyłącznie odczyty bazy i walidację konfiguracji, bez żądań do P24, e-maila, generowania PDF lub zlecania pracy. Zwraca JSON z ograniczoną liczbą wierszy w każdej sekcji (domyślnie 25, maksymalnie 100). Kod wyjścia 0 oznacza wykonanie raportu, nie sprawność całej integracji. Błędny argument daje 2, nieznane zamówienie 1.

```bash
docker compose exec app php artisan sales:diagnose --limit=25
docker compose exec app php artisan sales:diagnose --order=NUMER_ZAMOWIENIA_ULID --limit=10
```

Parametr `--order` przyjmuje publiczny numer widoczny na zakupie. Raport podaje wewnętrzny `payments.id` potrzebny do uzgodnienia i ID wysyłki, bez nazwisk, kontaktów, cen, tokenów, kluczy, ścieżek plików lub treści wyjątków. Przy filtrowaniu pokazuje również zakończone próby; globalnie pomija potwierdzone płatności i wysłane dokumenty. `paid_orders_without_membership` wymaga sprawdzenia przez utrzymanie — polecenie nie naprawia danych. `pdf_recorded` oznacza zapis ścieżki w bazie, nie sprawdzenie fizycznej obecności pliku.

`configuration_valid` sprawdza lokalne ustawienia; `callback_path_matches` i `return_path_matches` porównują tylko ścieżki tras. Żaden z tych znaczników nie potwierdza DNS, certyfikatu, dostępu z P24 ani działających danych uwierzytelniających. `external_connectivity`, SMTP, worker i scheduler pozostają `not_checked`. `can_reconcile` oznacza wyłącznie istniejącą sesję o obsługiwanym stanie i zgodnej przestrzeni środowiska/sprzedawcy/POS, a nie dowód opłaty.

Nie zapisuj w raporcie odbioru callback body, Basic Auth, kluczy API/CRC, adresów klientów, zawartości `jobs.payload`, `failed_jobs.exception` ani dokumentów PDF. Wystarczą ID, stan, liczba prób, znaczniki UTC i wynik kontroli. Pełne logi/proxy HAR mogą zawierać dane płatności — nie dołączaj ich do repozytorium.

### Scenariusz A — brak lub opóźnione potwierdzenie płatności

1. Odszukaj zamówienie po numerze i odczytaj raport. Powrót przeglądarki z P24 ani potwierdzenie klienta o pobraniu środków nie nadają lokalnie statusu paid.
2. Sprawdź dostępność callbacku, działanie aplikacji, zgodność konfiguracji oraz bezpieczne kody błędów z logów. Nie twórz drugiego zamówienia i nie wykonuj kolejnej rejestracji dla stanu unknown/initiated.
3. Jeśli raport wskazuje istniejącą sesję do uzgodnienia, operator z dostępem do serwera uruchamia pojedynczy odczyt stanu operatora:

```bash
docker compose exec app php artisan payments:reconcile-p24 --payment=123 --limit=1
```

`123` zastąp wewnętrznym ID płatności z raportu, nie ID zamówienia lub transakcji P24. Polecenie może potwierdzić opłatę i wydać PDF/karnet, ale nie rejestruje nowego obciążenia. Wykonuj je wyłącznie w odpowiednim środowisku. Brak sesji lub stan nieopłacony nie dowodzi, że nowa rejestracja jest bezpieczna. Konflikt kwoty/waluty/sesji, zwrot lub zmiana sprzedawcy wymagają analizy, bez ręcznego ustawiania paid.
4. Po skutecznym uzgodnieniu sprawdź: jedno zamówienie, jedna potwierdzona płatność, jeden karnet i jedno początkowe zlecenie PDF. Nowy karnet ma puste daty do pierwszej wizyty; przyszłe przedłużenie zachowuje ustalony okres. Kolejne uzgodnienie/duplikat callbacku nie dodają drugiego karnetu ani początkowej wysyłki.
5. Rejestracja płatności ma limit 3 prób na zamówienie i wznawia zapisany token zamiast rejestrować go ponownie. `payments:reconcile-p24` ma limit 1–100 sesji na wywołanie (domyślnie 25), bez automatycznej pętli/schedulera i bez stałego globalnego limitu weryfikacji; operator ocenia kolejne próby po usunięciu przyczyny. Nie uruchamiaj go w ciasnej pętli. Nie używaj `queue:retry all` jako naprawy płatności.

Odbiór sandbox: na dedykowanym stagingu przygotuj testowy zakup, a dla jednej kontrolowanej próby spowoduj czasową niedostępność wyłącznie callbacku na warstwie proxy (np. 503). Przywróć trasę, uzgodnij istniejącą sesję i sprawdź wynik powyżej. Nie dodawaj produkcyjnego „potwierdź testowo” i nie osłabiaj sprawdzania podpisów. Dla osobnego testowego zamówienia można po przywróceniu trasy odczekać na naturalne ponowienie operatora zamiast od razu uzgadniać sesję. Oficjalny kontrakt przewiduje ponowienia przy braku poprawnej weryfikacji; udana weryfikacja je kończy, więc nie wymagamy następnego callbacku po skutecznym uzgodnieniu. Nie traktuj czasu oczekiwania jako dowodu nieopłacenia. Powtórzenia samego odbioru można dodatkowo sprawdzić w izolowanych testach HTTP/MySQL, bez kopiowania podpisanych payloadów do raportu.

### Scenariusz B — opłacony karnet, nieudany PDF/e-mail

1. Potwierdź w historii opłatę i obecność właściwego karnetu. Odszukaj ID wysyłki w panelu administratora **Wysyłki PDF** lub diagnostyce. Nie ponawiaj zakupu/płatności z powodu braku e-maila.
2. Sprawdź prywatny dysk, uprawnienia, wolne miejsce, SMTP, worker database/default i scheduler. Brakujący PDF generator może odtworzyć; po opłacie komunikat oczekiwania na pierwszą wizytę jest prawidłowy. `MAIL_MAILER=log` nie przechwytuje wiadomości membership — używają dedykowanego SMTP. Na stagingu ustaw jego host na przechwytującą skrzynkę.
3. Dla znanego błędu wysyłka ma maksymalnie 5 prób, z odstępami 60/300/900/3600 sekund. Po usunięciu przyczyny odczekaj do `scheduled_for_utc`. Jeśli job nie został zapisany, scheduler odtworzy go; można też wywołać:

```bash
docker compose exec app php artisan documents:retry-deliveries --limit=100
```

Polecenie zleca pracę; bez workera e-mail nie zostanie wysłany. Nie zeruj licznika prób, nie zmieniaj `scheduled_for`, `sent_at`, `processing_token` ani immutable historii w SQL/tinker. Zduplikowany/stary job nie powinien zużyć nowej próby.
4. Po wyczerpaniu limitu administrator może wybrać **Wyślij ponownie** i potwierdzić dotychczasowego odbiorcę. Powstaje osobne audytowane zlecenie, bez zmiany oryginalnego sent_at, płatności i karnetu. Nie ma zmiany e-maila z tego ekranu. Przy trwających automatycznych próbach przycisk pozostaje niedostępny.
5. Wynik niepewny (proces przerwany na ponad 180 sekund lub przyjęcie SMTP z nieutrwalonym potwierdzeniem) wymaga sprawdzenia skrzynki/logów transportu. Scheduler oznacza go do sprawdzenia i nie wysyła automatycznie ponownie. Ewentualny ponowny egzemplarz musi być świadomą decyzją administratora. SMTP nie gwarantuje dokładnie jednej dostawy, a „przekazano do serwera” nie oznacza dostarczenia do skrzynki.

Odbiór staging: zatrzymaj przechwytujący serwer SMTP dla kontrolowanego testu, opłać testowy zakup w sandboxie i potwierdź, że awaria zmienia tylko wysyłkę. Uruchom SMTP przed kolejną zaplanowaną próbą, sprawdź wiadomość z jednym właściwym PDF i niezmieniony karnet. Następnie sprawdź anulowanie/potwierdzenie ręcznego ponowienia, zachowanie historii i brak nowego zakupu. Nie odłączaj produkcyjnego SMTP ani dysku klubu na potrzeby testu.

### Scenariusz C — awaria po weryfikacji u operatora

Nie cofaj lokalnie płatności ani nie rejestruj drugiej. Po przywróceniu bazy/aplikacji uruchom uzgodnienie istniejącej sesji ze scenariusza A. Tylko zgodny uwierzytelniony stan P24 pozwala dokończyć zapis. Przerwana transakcja lokalna powinna pozostawić brak nowego karnetu i wysyłki; ponowienie po naprawie tworzy je raz. Nie wymuszaj awarii transakcji na produkcji. Ten przypadek jest deterministycznie testowany przez symulację utraconej odpowiedzi/błędu lokalnego, a rzeczywista łączność jest osobnym krokiem odbioru.

### Raport wykonania — 2026-11-25

| Kontrola | Wynik / dowód |
| --- | --- |
| Lokalny odczyt `sales:diagnose --limit=5` | Wykonany; brak gotowej konfiguracji P24, brak rekordów płatności/zleceń wysyłki, bez zmian bazy i bez połączeń z operatorem. |
| Deterministyczne odzyskanie callbacku i e-maila | Test `OperationalRecoveryTest.php`: rejestracja, odrzucenie zmienionego callbacku, utracona weryfikacja, późniejsze uzgodnienie, duplikat callbacku, utracony job, awaria SMTP, prawdziwy worker database, ponowienie i ręczne wysłanie tego samego karnetu. HTTP fake, prywatny testowy dysk i transport array. |
| Diagnostyka i ochrona danych | `SalesDiagnosticsTest.php`: odczyt bez mutacji/HTTP/Queue/Mail, limit i scoping zamówienia, zgodność przestrzeni sprzedawcy, brak ujawnienia sekretów/kontaktów/ścieżek/wyjątków. |
| Automatyczne kontrole wykonane | 9 nowych testów / 100 asercji; regresja 751 testów / 4781 asercji (30 testów tylko MySQL pominiętych w SQLite); osobny MySQL 100 testów / 759 asercji, w tym współbieżność. Pint (335 plików) i PHPStan przeszły. |
| Rzeczywista rejestracja i opłata sandbox P24 | **NIEWYKONANE:** potrzebne gotowe osobne środowisko testowe, publiczny HTTPS oraz jego konfiguracja sandbox. |
| Rzeczywisty callback P24 i jego ponowienie | **NIEWYKONANE:** ta sama zależność; lokalne/fake testy nie zastępują odbioru. |
| Rzeczywisty odbiór przez testowy SMTP | **NIEWYKONANE:** potrzebny przechwytujący serwer testowy. Nie wysyłano wiadomości do prawdziwych klientów. |

Przy rzeczywistym odbiorze dopisz datę UTC, osobę wykonującą, identyfikator wersji kodu, adres stagingu (bez sekretów), numery testowych zamówień/wewnętrzne ID płatności/wysyłki oraz wynik każdego scenariusza A–C. Zapisuj liczbę karnetów i zmianę stanów, nie dane osób. TASK-034 pozostaje otwarty do potwierdzenia zewnętrznego sandboxu i testowego SMTP; nie włączamy sprzedaży produkcyjnej na podstawie samych atrap.
