# Notify admins by email when a new user needs approval

**Issue:** [#164](https://github.com/larspohlmann/simple-feed-reader/issues/164)
**Branch:** `feature/164-notify-admins-pending-approval`
**Date:** 2026-07-28

## Problem

When a new user registers, they land in the approval queue
(`UserStatus::PendingApproval`) and wait. Today an admin only discovers this by
visiting the admin users page — there is no proactive signal. Admins want an
email the moment someone needs approving, so they can review and approve
promptly.

## Behavior

When any user *enters* `UserStatus::PendingApproval`, every **active admin**
(`ROLE_ADMIN` **and** `status = Active`) receives a plain-text email, rendered in
that admin's own locale, containing:

- the applicant's email address,
- how they signed up — `email & password` vs. the OAuth provider name,
- the current number of users awaiting approval,
- a deep link to the admin users page.

Delivery rides the existing `DeferredMailer`: the mail is queued during the
request and flushed on `kernel.terminate`, so it adds no latency to the
registration response and works from both the HTTP and CLI paths.

### The three entry points into the queue

A user reaches `PendingApproval` from exactly three code sites, and **all three**
must trigger the notification:

| Path | Site | Registration method |
|---|---|---|
| Password signup, after email verification | `RegistrationService::verifyEmail` | `EmailPassword` |
| New OAuth signup | `OAuthAccountLinker::createUser` | `OAuth` (+ provider) |
| OAuth claiming a never-verified local account | `OAuthAccountLinker::claimUnverifiedAccount` | `OAuth` (+ provider) |

No admin action ever sets `PendingApproval` (approve → `Active`, reject →
`Rejected`, suspend → `Suspended`), so these three are the complete set of
triggers and there are no spurious notifications from moderation.

## Architecture

An **explicit domain event** dispatched from the three transition sites and
handled by a single listener. Chosen over a Doctrine flush listener because each
site already knows the registration method (so it is passed in, not re-derived
from a fragile null-password-hash heuristic), it matches the codebase's existing
`#[AsEventListener]` style, and it is straightforward to back with functional
tests. The only paths into `PendingApproval` are these three registration flows,
so the "a Doctrine listener can't be bypassed" advantage is thin here.

```
verifyEmail / createUser / claimUnverifiedAccount
        │  (after flush)
        ▼
  dispatch UserAwaitingApproval(user, method, provider?)
        │
        ▼
  #[AsEventListener] NotifyAdminsOfPendingApproval
        │   ├─ UserRepository::findActiveAdmins()
        │   ├─ UserRepository::countByStatus(PendingApproval)
        │   └─ build PendingApprovalNotice (reviewUrl from APP_FRONTEND_URL)
        ▼
  for each admin: AccountMailer::sendPendingApprovalNotice(admin, notice)
        │   (renders subject/body in admin's locale, plain text)
        ▼
  DeferredMailer.queue → flushed on kernel.terminate
```

### Why dispatch *after* flush

Dispatch happens after `EntityManager::flush()` at each site so that:

- the DB reflects the newly-pending user, making `countByStatus` accurate
  (the just-registered user is included in "N awaiting approval");
- a failed flush produces no notification (correct — nothing was queued).

The listener runs synchronously at dispatch time (cheap: two small queries, then
`DeferredMailer::send` only *enqueues*); the SMTP send itself is deferred.

## Components

### 1. Domain event + method enum

- **`src/Event/UserAwaitingApproval.php`** — `final readonly class`:
  ```php
  public function __construct(
      public User $user,
      public RegistrationMethod $method,
      public ?string $oauthProvider = null, // present iff method is OAuth
  ) {}
  ```
- **`src/Enum/RegistrationMethod.php`** — `enum RegistrationMethod: string`
  with `EmailPassword` and `OAuth`.

### 2. Dispatch sites

- `RegistrationService` and `OAuthAccountLinker` each gain an injected
  `Symfony\Component\EventDispatcher\EventDispatcherInterface` (constructor DI —
  both are `final readonly`).
- After the existing flush, dispatch `new UserAwaitingApproval($user, …)` with
  the method (and provider, for the two OAuth sites — the linker already knows
  the provider id).

### 3. Listener

- **`src/EventListener/NotifyAdminsOfPendingApproval.php`** —
  `#[AsEventListener(UserAwaitingApproval::class)]`. Injects `UserRepository`,
  `AccountMailer`, and `%env(APP_FRONTEND_URL)%`. Steps:
  1. `admins = userRepository.findActiveAdmins()`; if empty, log at debug and
     return (guard clause).
  2. `count = userRepository.countByStatus(PendingApproval)`.
  3. Build one `PendingApprovalNotice` (same for all admins; only locale differs).
  4. For each admin: `accountMailer.sendPendingApprovalNotice(admin, notice)`.

### 4. Repository queries (`src/Repository/UserRepository.php`)

- `findActiveAdmins(): list<User>` — `WHERE u.status = :active AND u.roles LIKE
  :roleAdmin` (`%ROLE_ADMIN%`), then an in-PHP
  `in_array('ROLE_ADMIN', $user->getRoles(), true)` recheck to eliminate
  substring false positives. `roles` is a JSON column stored as text on both
  SQLite (tests) and MySQL (prod), so `LIKE` is portable.
  - **Plan must verify:** DQL `LIKE` against a `json`-typed field is accepted by
    both dialects. If either balks, fall back to fetching candidate rows and
    filtering in PHP — user counts are tiny, so a full scan is acceptable.
- `countByStatus(UserStatus $status): int` — `SELECT COUNT(u.id) … WHERE
  u.status = :status`, `getSingleScalarResult()`.

### 5. Mail composition

- **`src/Dto/Mail/PendingApprovalNotice.php`** — `final readonly` DTO:
  `applicantEmail`, `method` (`RegistrationMethod`), `oauthProvider` (`?string`),
  `reviewUrl`, `pendingApprovalCount`. The DTO keeps
  `sendPendingApprovalNotice` to two parameters, per the house "few parameters"
  rule.
- **`AccountMailer::sendPendingApprovalNotice(User $admin, PendingApprovalNotice
  $notice): void`** — renders subject/body in `$admin->getLocale()`, plain text,
  from the existing `noreply@` (`MAIL_FROM` / `MAIL_FROM_NAME`) identity, via the
  same deferred `MailerInterface` the class already uses.
- **Translations** — new `admin_pending_approval.{subject,body}` in
  **`translations/emails.en.yaml`** and **`emails.de.yaml`**, matching the
  existing single-line-with-`\n` body style. The signup-method label is itself
  translated: an `email & password` string for `EmailPassword`, and the raw
  provider name (e.g. `Google`) for `OAuth`.

### 6. Config — no new env var

The deep link reuses the existing **`APP_FRONTEND_URL`**:
`rtrim(APP_FRONTEND_URL, '/') . '/admin/users'`. In production `APP_FRONTEND_URL`
is `https://lars-pohlmann.de/reader`, so the link is
`https://lars-pohlmann.de/reader/admin/users` (the `/reader` base href comes from
`APP_FRONTEND_URL`; the Angular route is `admin/users`).

## Error handling & edge cases

- **No active admins** → listener returns quietly after a debug log; no mail, no
  error.
- **Flush fails** → event never dispatched → no notification.
- **Suspended / rejected admins** are excluded by the `status = Active` filter.
- **The applicant** is never a recipient (new registrations are never admins, and
  the recipient set is active admins only).
- **One notification per entry** — `verifyEmail` consumes a single-use token,
  each OAuth site runs once; there is no double-fire on a single registration.
- **Delivery only where `MAILER_DSN` is real** (dev is `null://null`); this is
  inherited from the existing transport, not this feature's concern (see #65).

## Testing

Functional tests exercise the real wiring (per the repo's "direct-invocation
tests mislead" rule — the listener is validated through the actual
register/verify and OAuth flows, not by calling it directly):

- Password path: register → verify email → the admin mail is sent, addressed to
  the active admin, subject + body contain the applicant email, the review URL,
  and the pending count.
- OAuth path: an OAuth signup landing in `PendingApproval` sends the mail with
  the provider name as the method.
- Locale: two active admins with `en` and `de` each receive their locale's
  subject/body.
- Exclusion: a suspended admin and a non-admin user receive nothing.
- Zero active admins: no mail is sent and no error is raised.

Unit/integration test for `UserRepository::findActiveAdmins()` (active
`ROLE_ADMIN` only; excludes suspended admins, non-admins, and `ROLE_ADMIN`
substring false positives) and `countByStatus()`.

## Constraints check

- **Native iOS:** server-internal mail only; no new client-facing endpoint, no
  browser-coupled input. The deep link is plain text in the body — an iOS admin
  is still notified. Native-iOS-safe.
- **Quality gate:** every touched `src` file must pass `composer check` and be
  PHPMD-clean (guard clauses, ≤3 params via the DTO, typed exceptions if any,
  `declare(strict_types=1)`), plus PhpStorm inspections on changed PHP.
- **Both test legs:** run `php bin/phpunit` (SQLite) and the Docker MySQL leg
  before the PR, since `findActiveAdmins` depends on cross-dialect `LIKE`
  behaviour.

## Out of scope

- In-app / push notifications (email only).
- A digest or rate-limiting of notifications (one mail per registration is
  acceptable at this app's scale).
- Making the notification recipient configurable beyond "active admins" (e.g. a
  dedicated `ADMIN_NOTIFY_EMAIL` env var) — decided against; recipients follow
  `ROLE_ADMIN`.
