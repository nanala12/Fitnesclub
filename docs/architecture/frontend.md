# ActiveFit — Frontend Architecture

- **Version:** 1.0 (2026-11-05)
- **Status:** DRAFT FOR REVIEW
- **Source of truth:** `docs/product/requirements.md`, `docs/architecture/overview.md`, `cms.md`, `security.md`, `decisions/ADR-001-activation.md`, HTML references in `design/reference-html/`.
- **Stack:** Laravel + Inertia.js + Vue 3 (Composition API) + TypeScript + Tailwind CSS.

## 1. Purpose and scope

Preserve the supplied ActiveFit HTML designs while replacing static markup, hand-written DOM manipulation and mock data with maintainable Inertia pages and Vue components. One Laravel monolith; no separate REST API for standard browser flows. Backend determines authorization and all business state; client renders server-provided data and uses Inertia form submissions.

**UX copy is always written for the gym member or reception employee, not for developers.** Existing HTML is a visual reference, not a security or data contract.

## 2. Supplied HTML — page mapping

Source ZIP: `activefit-.zip` (12 files). Save extracted originals as unchanged design references, not within the Vite production bundle.

| Reference HTML | Target Inertia page or component | Notes |
| --- | --- | --- |
| `activefit-start.html` | `pages/public/Home.vue` | Hero, tiles, selected plans, news teasers |
| `activefit-cennik.html` | `pages/public/Pricing.vue` | Public plan catalog; max 3 featured on home |
| `activefit-aktualnosci.html` | `pages/public/News/Index.vue` (+ `Show.vue` when content needs detail) | Published CMS posts |
| `activefit-galeria.html` | `pages/public/Gallery.vue` | Single-category image ownership |
| `activefit-kontakt-.html` | `pages/public/Contact.vue` | Club address, hours, map link |
| `activefit-regulamin.html` | `pages/public/Regulations.vue` | Current published version |
| `activefit-zakup-karnetu.html` | `pages/checkout/Create.vue`, `Status.vue` | Guest/account + gift; server-confirmed payment state |
| `activefit-admin-bez-edycji-tresci-strony.html` | `layouts/AdminLayout.vue`, `pages/admin/Dashboard.vue` | Baseline admin shell/navigation |
| `activefit-admin-aktualnosci.html` | `pages/admin/Cms/News/*` | Extract and merge incremental admin features |
| `activefit-admin-cennik-responsywny.html` | `pages/admin/Cms/Pricing/*` | Responsive table / mobile cards |
| `activefit-admin-galeria-uproszczona.html` | `pages/admin/Cms/Gallery/*` | Category names/descriptions; images no captions |
| `activefit-admin-regulamin-wersje.html` | `pages/admin/Cms/Regulations/*` | Single document, version history |

**Important:** the five administrator HTML files represent evolving variants of one dashboard with different features. Merge into one shared admin shell; do not duplicate the complete panel five times.

## 3. Additional screens needed (not in supplied HTML)

| User role | Page | UX obligation |
| --- | --- | --- |
| Guest / customer | `pages/checkout/Status.vue` | Payment pending, paid/waiting for first visit, failed and retry paths |
| Customer | `pages/customer/Dashboard.vue`, `Memberships/Index.vue`, `Orders/Index.vue` | Pending activation, active pass, future renewals, history and PDF access |
| All logged-in roles | `pages/account/Profile.vue`, `Security.vue` | Edit own name, surname, email, phone, password; email reverification |
| Employee | `pages/staff/Memberships/Search.vue`, `Show.vue` | Search by phone, disambiguate holders with shared phone, first-visit activation and entry redemption |
| Employee | `pages/staff/Sales/Create.vue` | Reception cash/card sale, with/without registered account |
| Admin | `pages/admin/Employees/*` | Create/edit/suspend staff, safe invitation via email |
| Admin | `pages/admin/Orders/*`, `Audit/Index.vue` | Orders, channels/methods, critical action history |
| Admin | `pages/admin/Cms/Tiles/*`, `Slides/*`, `Settings/*`, `Schedule/*` | CMS features missing from HTML as dedicated forms |

Build missing views using the same brand elements, spacing and interaction patterns as the references, while ensuring WCAG 2.2 AA basics and mobile readability.

## 4. Suggested folder layout

### Implemented baseline — TASK-004

The starter's existing page names, layouts and route names are preserved. `routes/web.php` maps `/` to `HomeController` → `Welcome.vue`, and authenticated/verified `/dashboard` to `DashboardController` → `Dashboard.vue`. The dashboard is a read-only account overview from the current authenticated user, not a sales dashboard or membership listing. Shared props are defined in `resources/js/types/shared.ts`, page-specific contracts in `types/pages.ts`, and registered through the Inertia module augmentation in `types/global.d.ts`.

- Shared fields: `name`, `businessTimezone`, `sidebarOpen`, and `auth.user` (null for guests, otherwise only `id`, `name`, `email`, `email_verified_at`). The middleware explicitly selects these fields instead of serializing an entire model; timestamps and internal/security attributes are not automatically shared.
- `AuthenticatedPageProps` narrows `auth.user` only inside components rendered behind Laravel's `auth` middleware. The narrower TypeScript type does not authorize access.
- Home page props: `canRegister`, derived from Fortify configuration. Dashboard page props: nullable ISO UTC `accountCreatedAt`; the page formats it in the server-provided business timezone and handles a missing date.
- Controllers remain thin. Add a Form Request, Policy, Action or Job when a real feature requires validation, resource authorization, business logic or asynchronous work; do not add empty layers for this read-only baseline.

### Shared UI — TASK-005

`resources/css/app.css` maps semantic tokens to the reference HTML's warm palette: background `#f3f0ed`, surface `#fffdfb`, text `#393633`, muted text `#706963`, border `#e1dad4`, primary `#5b514b`, and sidebar `#403934`. The existing light/dark preference remains supported, with warm dark tokens and reduced-motion handling. Original HTML stays unchanged, including its initial-space directory name (` design/reference-html/`). Do not copy its fake sales, prices, contacts or remote image dependencies into production pages.

- `PublicLayout` supplies the wordmark, responsive navigation and footer for `Welcome` and `public/*`. The welcome view is an account entry point until TASK-011 supplies real public content.
- `PanelLayout` supplies the shared 246px desktop sidebar, mobile sheet, account identity, logout and skip link. `CustomerLayout` supplies existing account/profile/security links and is the target of the compatibility `AppLayout` wrapper. Settings retain their nested layout.
- `AdminLayout` and `StaffLayout` reuse `PanelLayout` with distinct headings and caller-supplied navigation; `app.ts` selects them for `admin/*` and `staff/*`. They add no routes or authorization. Backend role guards remain TASK-006, and later modules provide actual, authorized navigation. Never infer permissions from a selected layout.
- `AuthLayout` retains the existing authentication forms with the shared wordmark/tokens. Positive tab indices were removed from login/registration; the password-visibility button participates in natural keyboard order. Full authentication copy is part of TASK-006.

Reusable components live in `resources/js/components/shared/` and build on the installed Button/Input/Label/Dialog/Sheet primitives:

| Component | Contract |
| --- | --- |
| `ActionButton` | Native button; defaults to `type="button"`. Set `type="submit"` in forms. `loading` disables repeats, announces `loadingText` and sets `aria-busy`; `disabled` handles unavailable actions. Link buttons continue using the base `Button as-child`. |
| `FormField` | Required `id` and `label`, optional `name` (defaults to `id`), `hint`, `error`, `defaultValue` or `v-model`. Input attributes pass to the actual input; label, hint/error IDs and `aria-invalid`/`aria-describedby` are associated automatically. `InputError` announces errors. |
| `FeedbackMessage` | Required title, optional body slot; info/success/warning/error appearance and status/alert announcements. |
| `EmptyState`, `LoadingState` | Empty-result explanation with optional action slot; loading status with visible text. Caller supplies meaningful copy and real loading state. |
| `ConfirmDialog` | Required trigger slot, title, description and explicit `confirmLabel`; `v-model:open`, `processing`, `error` and optional `destructive`. Emits `confirm` without closing. Caller starts the request, resets processing on failure, and closes after success. Reka traps/restores focus; closing is blocked while processing. Server authorization/idempotency remain mandatory. |
| `Pagination` | Laravel-style `PaginationLink[]` (`url`, `label`, `active`) and optional `loading`. Uses supplied URLs, Polish previous/next labels, disabled boundaries and current-page semantics. Labels render as text; no `v-html`. |
| `ContentImage` | Public image `src` (nullable), required `alt` (empty for decoration), optional dimensions/aspect ratio/loading mode. Reserves layout space, loads lazily by default, and shows accessible missing/error fallbacks. Never use it to expose private PDFs. |
| `BrandLogo`, `NavigationLinks` | Shared wordmark and keyboard-operable, current-page navigation. Layouts only link implemented destinations. |

The profile form demonstrates `FormField` and `ActionButton` with real Inertia validation/submission states. Polish profile validation and save feedback live in `lang/pl/validation.php` and `lang/pl.json`; the existing account-removal UI is localized without changing its backend operation. No new package, database field or business rule is introduced.

Vitest/Playwright are not configured. TASK-005 uses the existing PHP/TS/build checks plus Chrome smoke in a temporary checkout: responsive shells, menu/dialog keyboard trapping and focus return, loading/error/empty/image states, safe pagination labels, real profile save/validation and logout. Component harness routes and synthetic fixtures exist only in that temporary checkout and must not ship with production routes.

The directory tree below is the target organization for later product modules, not a list of empty files to generate in advance.

```text
resources/js/
├── app.ts
├── types/
│   ├── shared.ts
│   ├── membership.ts
│   ├── order.ts
│   └── cms.ts
├── layouts/
│   ├── PublicLayout.vue
│   ├── CustomerLayout.vue
│   ├── StaffLayout.vue
│   └── AdminLayout.vue
├── components/
│   ├── ui/                    # Button, FieldError, Modal, EmptyState, Badge
│   ├── navigation/            # PublicHeader, AdminSidebar
│   ├── memberships/           # MembershipCard, PassStatusBadge, EntryHistory
│   ├── checkout/              # PlanChoice, HolderForm, PaymentStatus
│   └── cms/                   # MediaUpload, RichTextEditor, SortableItems
├── composables/              # Small cross-page UI behavior, not business rules
├── pages/
│   ├── public/
│   ├── checkout/
│   ├── customer/
│   ├── staff/
│   ├── admin/
│   └── account/
└── utils/                    # Pure formatting and display helpers
```

Use PascalCase for Vue components, typed props and emits, Composition API `<script setup lang="ts">`. Resist introducing global state for server-owned membership records. Use Inertia shared props for auth/flash where appropriate, and per-page props for scoped business records.

## 5. Pages, routes and authorization

| Route (illustrative) | Page | Auth/authorization |
| --- | --- | --- |
| `GET /` | `public/Home` | Public |
| `GET /cennik` | `public/Pricing` | Public |
| `GET /karnety/kup/{plan}` | `checkout/Create` | Public or signed-in customer |
| `GET /zamowienie/status/{secure-reference}` | `checkout/Status` | Scoped secure reference / token; no simple order-id lookup |
| `GET /moje-konto` | `customer/Dashboard` | Customer |
| `GET /panel-pracownika/karnety` | `staff/Memberships/Search` | Employee/admin |
| `POST /panel-pracownika/karnety/{membership}/aktywuj` | Action, redirect | Employee/admin + MembershipPolicy + paid status |
| `POST /panel-pracownika/karnety/{membership}/wejscia` | Action, redirect | Employee/admin + policy + active/remaining balance |
| `GET /admin` | `admin/Dashboard` | Admin |
| `GET /admin/aktualnosci` | `admin/Cms/News/Index` | Admin |
| `GET /konto/profil` | `account/Profile` | Authenticated current user |

These are proposed readable routes, not a promise of a public API. Controllers are thin, authorize on backend, produce typed page props, and redirect with flash messages after writes.

## 6. Membership state — source of truth is Laravel

| State | Customer-facing text | Staff action | Dates displayed |
| --- | --- | --- | --- |
| `awaiting_payment` | „Oczekujemy na potwierdzenie płatności.” | No activation | None |
| `payment_failed` | „Nie udało się dokończyć płatności. Spróbuj ponownie.” | No activation | None |
| `pending_activation` | „Karnet opłacony. Aktywujemy go podczas pierwszej wizyty w klubie.” | **Aktywuj karnet** | „Początek ważności: przy pierwszej wizycie” |
| `scheduled` | „Karnet opłacony. Nowy okres rozpocznie się [data].” | No reactivation | Actual known dates |
| `active` | „Karnet aktywny — ważny do [data].” | Redeem if eligible | Actual known dates |
| `expired` | „Okres ważności karnetu zakończył się.” | History only | Historical dates |
| `exhausted` | „Wszystkie wejścia zostały wykorzystane.” | Disable entry button | Entry balance and history |

**Rules:** purchase confirmation never tells the user that validity has started. The first-visit button is available only to staff/admin and only for paid, not-yet-activated passes. Clicking activation does not automatically record a limited-entry visit. When a guest receives the immediately emailed PDF before first visit, it must not contain invented validity dates. Failure of email does not affect paid status.

### Minimal TypeScript boundary (illustrative)

```ts
export type MembershipView = {
    id: number;
    planName: string;
    holderName: string;
    status: 'pending_activation' | 'scheduled' | 'active' | 'expired' | 'exhausted';
    startsOn: string | null;
    expiresOn: string | null;
    entriesRemaining: number | null;
    canActivate: boolean;
    canRedeemEntry: boolean;
};
```

`canActivate` and `canRedeemEntry` are **derived, permission-scoped hints** from the backend; server policies and atomic validation remain authoritative. Do not use phone/email as automatic identity merge keys.

## 7. Inertia forms and errors

- Prefer `useForm()` (or typed standard Inertia visit) for non-trivial edits. Server Form Requests validate and return field errors; client-side checks only improve UX.
- Disable primary submit while processing; show inline errors, global error/flash feedback, and accessible focus after validation. Avoid duplicate `POST`s on double-click; business layer must also be idempotent.
- Purchaser and holder have separately labeled fields. Default „Dla siebie”; selecting „Dla innej osoby” reveals holder details and delivery email.
- Purchases remain **one pass per order**. Unregistered customers provide name, surname, phone and email. Regulation checkbox must link to the published version; record accepted version and timestamp server-side.
- Payment result page may show `processing` until verified P24 status; returning from the gateway does not mean success.
- First-visit activation UI should use an explicit confirmation dialog, show plan/holder and the resulting validity range before committing where possible. If server returns 409 after concurrent activation, refresh data and show actual saved dates.
- Use status/empty-state messages rather than raw backend exception text.

## 8. CMS component design

- **Public site:** show only published/visible records; no CMS edit controls on public pages.
- **News:** sanitized HTML, safe rich text, status and publication scheduling if approved; image belongs only to that content item.
- **Gallery:** category `name` + `short_description`; images have no editorial titles/descriptions. One image owned by one placement; removing it deletes the media record and physical file after commit (with failure recovery).
- **Plans/pricing:** one `membership_plans` source for both checkout and CMS; show up to **3 featured** plans on homepage; changing prices never mutates sold snapshots.
- **Regulations:** one published content entry with immutable version history; display current version and require online checkout acceptance of its version.
- **Tiles/slides:** explicit forms, title/description/image/order/visibility where approved. Carousel images and caption text are accessible.
- **Opening hours:** at most one interval per weekday; no holiday exceptions in MVP.
- **Rich text:** use a constrained WYSIWYG; sanitize on server and render only already-cleaned HTML; never trust editor output by default.
- **Uploads:** show local preview, progress/error, confirmation for delete, and never expose protected PDF files in public media.

## 9. Responsive UX, accessibility, SEO

- Build mobile-first. For dense staff/admin tables, switch to stacked cards or a consciously designed horizontal scroller. Never hide critical buttons off-screen.
- Touch targets around 44×44 px. Visible focus, semantic form labels, keyboard-operable carousel/dialogs, accessible validation, status text in addition to color.
- Screen readers receive state changes via `aria-live` where appropriate; dialogs trap focus and restore it on close.
- Public pages get meaningful `<title>`, metadata, canonical URL policy, semantic headings, optimized images and accessible alternatives. Configure Inertia SSR for SEO where needed, and test generated HTML.
- Do not ship reference HTML inline JS or remote stock-photo hotlinks as production dependencies. Replace with approved CMS assets.

## 10. Testing and review gates

**Pest / Laravel Feature:** server returns scoped props and denies employee access to staff-management, denies customer activation, protects guest status links; CMS publish permissions; max 3 featured plans; regulation version acceptance.

**Frontend unit/component (recommended Vitest + Vue Test Utils):** present pending activation without dates; when scheduled show dates; shared-phone holder selection; checkout guest/gift switching; responsive form validation; loading/error states.

**Browser E2E (recommended Playwright):** public browsing → checkout → paid pending screen; staff search → select holder → activate once → active dates; customer account history; CMS editing/news/regulation; accessibility keyboard flows. Use test doubles for P24/email and never real charges.

**Mandatory cases:** payment verified but no first visit; PDF before activation; no second PDF or new pass on email retry; concurrent activation; no entry deduction on activation; concurrent last entry; leap-year/month-end calculation; suspended employee forbidden; invalid image upload and rich-text XSS filtering.

## 11. Implementation order

1. Starter: Laravel + Inertia + Vue 3 + TS, shared design tokens, route naming and layouts.
2. Public pages read-only from static fixtures; align visual regression against HTML.
3. Auth/profile and staff/admin access guards.
4. Staff holder search, membership status cards and pending activation first-visit form.
5. Checkout guest/account, payments integration and PDF dispatch using backend contracts.
6. Customer dashboard, orders, pass history, responsive staff panel.
7. CMS editor modules (news, gallery, regulations, tiles/slides, plan pricing, contact/schedule).
8. Validation, E2E, security review and accessibility checks before production.

**Implemented account settings — TASK-008 (2026-11-13):** the own-profile page uses typed dedicated Inertia props and a controlled `useForm` for separate first/last name, email and international phone. Successful saves refresh defaults from the server, including normalized phone. Legacy names are shown as a hint without guessing their split. Shared field components associate labels, hints and validation errors; submitting and success states are explicit. Email changes show verification guidance and a loading-aware resend action. The password form provides Polish copy, current-password validation, confirmation, accessible errors and save feedback. Customer/employee/admin use the same own-account settings routes and backend policy. Existing shells and color tokens are preserved. Chrome smoke checked actual saves, duplicate-email validation, password failure/success, re-verification, all three roles, separate-name registration and responsive profile widths (320/768/1280px), with no JS exceptions.

## 12. Decisions still open

- Unsupported different-plan renewals, purchases of multiple passes for one holder and carryover after expired entry passes.
- Policy for how long paid but unactivated passes may remain pending.
- Refund/cancellation/retention rules, purchases for minors and recipient email verification for gifts.
- Exact SSR hosting/setup and selected editor/upload library are implementation choices to confirm with infrastructure.

These do not permit the agent to silently invent business rules. **ADR-001 is accepted, not open.**
