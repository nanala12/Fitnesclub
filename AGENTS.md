# ActiveFit — AI Agent Instructions

> Version: 1.1 · Scope: entire repository · Language of communication: Polish
>
> This file defines how AI coding agents contribute to ActiveFit. Keep it short enough to follow; the product requirements remain the source of business truth.

## 1. Project context

ActiveFit is a **single Laravel application** using **Inertia.js + Vue 3**, with a public website, custom CMS, administrator and employee panels, customer account area, and gym membership sales (including guest checkout and purchases for other people).

- **Confirmed:** Laravel, Inertia.js, Vue 3, Przelewy24 for online payments, cash/card at reception.
- **Confirmed tooling:** GitHub, TypeScript, Tailwind CSS, MySQL, Pest. **Recommended:** Pint, ESLint/Prettier, Playwright. Verify installed versions against lockfiles.
- The UI language is **Polish**. Code, class names, variable names and code comments are **English**.
- Preserve the existing UX/UI decisions and components. Write UX messages for the end user: clear actions, informative errors, empty states, mobile-first controls.
- Build a **simple modular monolith**. Do not introduce microservices, DDD ceremony, CQRS, repositories around Eloquent, a separate SPA/API, or new packages without a concrete need.

## 2. Source of truth and priority

Before implementing, read the relevant documents:

1. `docs/product/requirements.md` — **business scope**, requirement IDs, acceptance criteria and unresolved decisions.
2. `docs/architecture/repository-setup.md` — setup, architectural recommendations and tooling conventions.
3. `AGENTS.md` — implementation and review rules for AI agents.
4. Existing code, tests and lockfiles — authoritative for **actual installed APIs and versions**.

If documents and code disagree: identify the conflict, do not silently alter business behavior, and document the decision. A technical recommendation in `repository-setup.md` is **not** a confirmed business rule. Never invent an answer to a requirement marked `OPEN` or `OD-*`. Implement independent work only; flag blockers explicitly.

## 3. Required workflow (AI SDLC)

For each task:

1. **Understand:** locate relevant `WEB-*`, `CMS-*`, `ACC-*`, `PLN-*`, `ORD-*`, `PAY-*`, `MEM-*`, `STAFF-*`, `ADM-*`, `NFR-*` requirements and acceptance criteria.
2. **Inspect:** check existing architecture, naming, patterns and tests before proposing files or migrations.
3. **Plan minimally:** list touched areas, expected behavior, edge cases and any unresolved decisions.
4. **Implement:** keep changes focused, typed, secure and consistent with existing conventions.
5. **Test:** add/update automated tests for behavior and regressions; run targeted tests and quality checks.
6. **Review:** check correctness, authorization, payment safety, concurrency, privacy, performance, responsiveness and accessibility.
7. **Report:** summarize changed files, key decisions, tests executed (or not executable and why), outstanding risks and linked requirement IDs.

Do not report tests as passed unless actually run. Do not modify unrelated files, make sweeping refactors, or create speculative features.

## 4. Backend guidelines — Laravel

- Prefer framework conventions: routes, controllers, Form Requests, Policies, Eloquent, Notifications, Jobs, Events and database transactions.
- Controllers should orchestrate; place nontrivial purchase, membership activation, renewal and entry-use logic into focused **Actions**.
- Validate all user input server-side. Authorize **every** private page, resource query and mutation server-side; hiding buttons is insufficient.
- Use explicit method return types and type declarations where practical; avoid excessive abstraction or premature interfaces.
- Prevent N+1 queries, use pagination for potentially large lists, and create relevant DB indexes.
- Use migrations for schema changes, factories for test data and safe seeders (no production personal data).
- Represent all domain money amounts as **integer grosze** (PLN minor units) in the database and application logic. Never use floats or DECIMAL for monetary domain amounts. Validate provider/API units at integration boundaries and format amounts only for display.
- Treat external API calls, emails and PDF delivery as failure-prone. Handle retries and errors; dispatch background jobs only after database commit when appropriate.

## 5. Frontend guidelines — Inertia + Vue

- Prefer Vue 3 Composition API with `<script setup lang="ts">` and typed props/emits.
- Use server-provided Inertia page props; do not create an unnecessary second client-side API.
- Keep components focused. Separate reusable UI components from page orchestration; avoid global state without a demonstrated need.
- Use Inertia forms with validation errors, disabled/submitting states, success messages and deliberate retry behavior.
- Preserve accessibility: labels, keyboard support, focus indication, validation announcements and adequate contrast.
- Design mobile-first for purchases, the customer membership card, and staff lookup. Use natural Polish customer-facing copy rather than technical status names.
- Never trust client-supplied prices, user roles, plan constraints, membership status or payment confirmation.

## 6. Domain invariants — do not break

- **Purchaser != holder:** the person paying may be different from the person using the membership.
- A holder may have **no user account**. Guest checkout and reception sales must work without account registration.
- **One order buys one membership** in the agreed MVP; the same purchaser can make multiple purchases.
- A person may hold more than one membership (including purchasing memberships for relatives). Do not force a one-membership-per-account schema.
- Staff locate holders primarily by phone number. **Phone and email must be unique within registered `users`**, after phone normalization, but may repeat across guest orders and across the account/guest boundary. Never use a globally unique phone constraint for guest purchaser/holder data.
- Guest purchases remain separate from user accounts forever under the current scope: **no automatic or manual merging, claiming, or reassignment** of past guest purchases to a subsequently registered account. A new account may use contact details previously used for guest checkout if those details are not already registered in `users`.
- Purchased terms and price need a historical snapshot; edits to the catalog must not rewrite past purchases.
- Online payment may issue a pass only after **verified Przelewy24 confirmation**, never a browser redirect. The validity clock starts only at staff/admin first-visit activation (ACT-01). Duplicate/replayed callbacks must never create multiple memberships.
- Reception payment methods: **cash** and **card**. Record who performed the sale and subsequent corrections.
- Membership use does not require QR scanning, entry gates or an external access-control integration.
- Limited-entry passes require safe decrementing; **never allow negative remaining entries**, including concurrent requests.
- A guest / recipient receives the membership as a PDF by email at the supplied address, subject to the currently approved notification rules.
- Employee accounts support creation, editing, suspension, and secure email invitation to set a password. Suspension must block existing sessions on subsequent authorized requests.
- CMS includes regulations, news, gallery, price list, contact, editable offering tiles, and an image carousel with descriptions; do not add a general-purpose page builder.

### Open business decisions: STOP before hardcoding assumptions

Check `docs/product/requirements.md`, section **7**, before implementation. In particular:

- `OD-01` (partly confirmed): when an anchor date is the final day of a month, the corresponding date in the next month must also be its final day (Jan 31 → Feb 28/29 → Mar 31). Inclusive/exclusive expiry semantics remain open.
- `OD-02` (partly confirmed): timed membership renewals start after the existing period instead of on early purchase; unused entries carry over and add to the newly purchased entry allowance. Mixing unrelated plan types remains open.
- `OD-03`: effective start date for online purchases with delayed confirmation.
- `OD-04`: cancellations, corrections and refunds.
- `OD-05` **RESOLVED**: registered accounts require unique email and normalized phone; guest contact data may duplicate and is not merged into accounts.
- `OD-06`–`OD-11`: PDF rules, third-party recipients/minors, dashboard metrics, exports, regulation versioning and data-retention details.

Confirmed renewal behavior must be tested; do not invent rules for mixed or unrelated plans. Keep date calculation isolated and tested once decisions are approved. Store transaction instants in UTC; interpret business dates using `Europe/Warsaw`, covering DST and end-of-month cases.

## 7. Security and personal data

- Apply least privilege: separate **admin**, **employee** and **customer** permissions through Policies/middleware; protect guest purchase status and PDF access with secure, scoped tokens or equivalent mechanisms.
- Keep `.env`, secrets, P24 keys, passwords and personal data out of Git, logs and error messages. Update `.env.example` only with placeholders.
- Use Laravel session/CSRF protections and rate limits for login, checkout entry points, customer lookup and sensitive endpoints.
- Verify P24 authenticity and transaction/order/amount/currency against server-side records. Enforce **idempotency and atomicity** inside database transactions, with uniqueness constraints or locks where needed.
- Generate sensitive PDFs into **private** storage; avoid publicly guessable file URLs and accidental retention of unnecessary customer data.
- Record audit events for manual membership grants, entry uses/corrections, staff changes, and sensitive admin operations. Avoid logging unnecessary personal information.
- Do not invent legal consent or privacy flows. Flag outstanding regulatory/legal decisions before production release.

## 8. Tests — mandatory for business logic

Prefer Pest for PHP (if installed); complement with appropriate frontend and E2E tests. Cover positive, negative and boundary behavior.

Critical examples:

- Guest checkout and checkout for **another person**: correct purchaser, holder and email recipient.
- One order → one membership; repeated P24 notifications → **one** activation; invalid notifications → **none**.
- Failed/cancelled/pending payment cannot issue a pass or allow first-visit activation. Paid new passes remain `pending_activation` until a staff/admin visit action.
- Cash/card sale and auditable staff identity.
- Concurrent redemption of the final entry permits **only one** success.
- Membership dates around month ends and DST **after** date rules are approved.
- Suspended staff cannot access protected routes even with an existing session.
- Customer/staff/admin segregation and prevention of IDOR/data leakage.
- CMS edit permissions; safely rendered descriptions and regulation content.

Before submitting work, use the project's existing scripts. Common commands (run only when available):

```bash
php artisan test
vendor/bin/pint --test
npm run typecheck
npm run lint
npm run build
```

Add Playwright/Vitest steps only if configured. Prefer targeted tests during implementation, then broader checks before merge.

## 9. Git, PRs and deployment

- Never commit secrets, `.env`, payment credentials, production DB dumps or real user exports.
- Make small, logically grouped changes. Preserve `composer.lock` and `package-lock.json` when dependencies change.
- Use descriptive commits (e.g. `feat(memberships): add guest checkout`) and PRs referencing requirement IDs.
- No automatic production migrations, destructive schema changes, real payment attempts or outbound production emails without explicit approval.
- Keep separate `local`, `testing/CI`, `staging` and `production` configurations, with sandbox P24 outside production.
- Confirm queues, worker, scheduler, backups and error monitoring in the deployment checklist.

## Potwierdzona reguła aktywacji — ACT-01 (2026-11-05)

**CONFIRMED:** opłacenie zakupu wydaje karnet o statusie `pending_activation`, ale **nie uruchamia** jego okresu ważności. Przy **pierwszej wizycie posiadacza** pracownik lub administrator weryfikuje właściwy karnet i uruchamia okres ważności; zapisujemy `activated_at` (UTC), `activated_by_user_id`, `starts_on` (lokalny dzień wizyty według `Europe/Warsaw`) i wyliczone `expires_on` (włącznie) według zakupionego snapshotu planu. Nie można aktywować nieopłaconego karnetu. Powtórzenie aktywacji niczego nie przelicza. Zapis aktywacji i audytu jest atomowy.

**Przedłużenia:** opłacone przedłużenie aktywnego karnetu o tym samym obsługiwanym typie ma przyszły okres od `previous.expires_on + 1 day` — **bez ponownej wizyty aktywacyjnej**. Dla karnetu, który sam jeszcze nie został aktywowany, sposób kolejkowania następnych zakupów pozostaje `OPEN`; nie przypisujemy automatycznie dat bez znanej daty startu źródła.

**PDF po opłacie:** generujemy i wysyłamy także dla `pending_activation`. Zawiera imię i nazwisko posiadacza, dane planu i komunikat „Ważność rozpocznie się przy pierwszej wizycie w klubie”; **nie zawiera fikcyjnych dat**. Dla opłaconego przedłużenia ze znanym przyszłym okresem PDF pokazuje rzeczywiste daty. Nie wymagamy automatycznej drugiej wysyłki PDF po aktywacji; ewentualne późniejsze pobranie aktualnego PDF może uwzględniać ustalone daty. Błąd e-maila nie cofa zakupu ani nie tworzy nowego karnetu.

**Granica stanów:** `orders/payments: paid` ≠ `memberships: active`. Statusy UI obejmują `awaiting_payment`, `pending_activation`, `scheduled` (przedłużenie) i `active`, a także `expired`/`exhausted` w odpowiednim kontekście. Faktyczną aktywację i wejście do klubu zapisujemy jako **różne zdarzenia**; odliczenie wejścia z karnetu limitowanego wymaga osobnej świadomej akcji, nawet jeśli odbywa się podczas tej samej wizyty.


## 10. Definition of done

A task is done only when:

- behavior matches its confirmed requirements and acceptance criteria;
- backend validation, authorization and relevant security controls exist;
- UI handles success, loading, errors and empty states and works on mobile;
- necessary tests are written and executed, or clearly documented as blocked;
- formatting, typechecks and builds are run where applicable;
- documentation reflects material architectural or business changes;
- no unresolved `OPEN` decision was silently decided in code.

**When in doubt:** choose the smallest correct solution, respect confirmed product decisions, surface ambiguity, and avoid speculative features.

## 11. Confirmed updates — 2026-11-04 (override earlier conflicting guidance)

- Treat `docs/product/requirements.md` and confirmed decisions as binding. First-visit activation `ACT-01` is **CONFIRMED**; never derive initial membership dates from purchase timestamp. Scheduled renewal of an already active pass is a separate path.
- Date calculation OD-01: if corresponding day exists in next month, expiry is day before it; otherwise expiry is the **last day of target month**. Example 31 Jan 2027 → 28 Feb 2027; 30 Apr → 29 May. Test leap years.
- Generate and queue PDF **on verified payment** for every sales channel, also renewal with future start. Retry delivery without modifying payments or memberships.
- Account ownership: every role can edit own profile/password, never own role or suspension status. Admin bootstrap uses an idempotent secure seeder.
- CMS: one media item per placement, deletion removes file **after** DB commit; rich-text HTML must be sanitized on server; online checkout accepts specific published regulation version.
- For entry passes, record `used_at` UTC, staff actor, request id; protect remaining balance against concurrent updates.
- Preserve old pass, payment, order and audit history after expiry; redact sensitive data in audit diffs.
- Keep snapshot values and amount `integer` in grosz; never infer holder identity solely by phone.
