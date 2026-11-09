# ADR-001 — First-visit membership activation

- **Status:** ACCEPTED / CONFIRMED
- **Date:** 2026-11-05
- **Owners:** ActiveFit product / architecture
- **Supersedes:** initial validity starting at payment confirmation and previous PENDING ADR

## Context

Payment confirms purchase; entry to the gym starts the purchased period. The system supports registered and guest orders, family recipients, reception and Przelewy24 checkout, advance renewals, limited-entry passes, and mandatory PDF after payment.

## Decision

1. Verified payment (online via Przelewy24 or confirmed reception cash/card) records `paid_at`, creates exactly one purchased pass and sends its PDF. The new pass has status `pending_activation`, with `starts_on` and `expires_on` unknown (`NULL`). **Payment is not activation.**
2. At the **holder's first visit**, an authorized `employee` or `admin` explicitly activates that pass. The server records `activated_at` (UTC), actor ID, local `starts_on` (date in `Europe/Warsaw`) and inclusive `expires_on` calculated from the purchased plan terms. The operation is auditable, transactional and idempotent.
3. Only verified paid passes can be activated. Two simultaneous clicks must not produce two activations or change saved dates. Activating an entry pass **does not automatically consume an entry**; redemption is separately recorded with `used_at` and the staff actor.
4. The PDF **is sent after payment even if activation has not occurred**. For pending activation it lists holder first/last name, club, plan and terms and states that the period starts at the first visit. It must not invent start/end dates. An advance renewal with already calculated future dates lists those dates. Automatic second email on activation is not required; optional later authenticated PDF retrieval can reflect current dates.
5. For a confirmed renewal of an **already activated, still valid** pass in a supported same-plan category, the subsequent period starts the day after the previous inclusive expiry (`previous.expires_on + 1 day`), without a second first-visit action. Do not infer unknown dates for renewals of an as-yet-unactivated pass.
6. Member/holder identity is selected explicitly at reception; the same phone can refer to multiple people. No guest-to-user account merging.

## Domain states

| Payment / period | Presentation | Next allowed action |
| --- | --- | --- |
| Payment awaiting | `awaiting_payment` | Confirm or retry payment |
| Verified paid; new period not yet activated | `pending_activation` | Staff/admin first-visit activate |
| Verified paid; future renewal period known | `scheduled` | Begins automatically when date arrives |
| Today is within the known validity interval | `active` | Check / redeem entry if allowed |
| After inclusive `expires_on` | `expired` | View history / approved renewal |
| No entries remain | `exhausted` | Cannot redeem again |

These are presentation/business states, not necessarily a single persisted enum. Order/payment state is separate from membership validity.

## Consequences

- `memberships.starts_on` and `expires_on` are nullable until first activation for newly purchased passes; consider `activated_at` and `activated_by_user_id` and add audit record.
- Commands: `IssuePaidMembership` (verified payment, unique per order) and `ActivateMembershipAtFirstVisit` (authorized first visit) are **separate** idempotent actions.
- The customer checkout success screen must say **paid / awaiting first activation**, not `active`.
- Staff search and customer panel must clearly present a pending pass with unknown dates.
- PDF generation cannot assume `starts_on`/`expires_on` are non-null.

## Follow-up questions (not blockers for basic first-pass activation)

- Can an unactivated pass be kept indefinitely before first visit? Any business activation deadline is **OPEN**.
- How should customers renew an unactivated pass or combine different pass types? **OPEN**.
- Full behavior for unused limited entries after expiry, refunds and minors remains **OPEN**.

## Required acceptance tests

- Paid guest and registered purchases produce one `pending_activation` pass and PDF immediately after payment.
- No dates appear in initial pending PDF; no auto-resend after first activation.
- Customer (403), unpaid pass (409/422), duplicate click, concurrent requests, staff actor/audit, Warsaw dates including month ends/leap years.
- Existing active period extension begins after expiry without a second visit activation.
