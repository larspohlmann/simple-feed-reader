# Per-user trial period and configurable max feeds (#66)

## Problem

All limits are global and hardcoded. `SubscriptionService::MAX_SUBSCRIPTIONS_PER_USER = 500`
applies to every account. The `User` entity carries a lifecycle status but no
per-user limit and no expiry. There is no notion of a time-limited account.

This design adds two per-user attributes and enforces them:

1. A trial period. When it ends, the account becomes suspended. An admin can see
   that the suspension came from an expired trial, and can start a new trial.
2. A configurable maximum number of subscribed feeds, per user, that overrides the
   global default.

The user, during an active trial, sees a countdown in the sidebar.

## Constraints and existing behaviour

- **No scheduler.** This app has no cron and that is a deliberate decision. A
  trial-to-suspended transition must happen lazily, on the trial-expired user's
  own next request, not from a background job.
- **Per-request user reload.** The Doctrine user provider reloads the user from
  the database on every authenticated request. `App\Security\UserChecker`
  (API firewall, `checkPreAuth`) blocks any account whose status is not `Active`.
  A status change therefore takes effect on the very next request. A live 7-day
  JWT does not outlive a status change.
- **No enumeration oracle.** The login firewall uses
  `App\Security\LoginUserChecker` (`checkPostAuth`), which runs only after the
  password is verified. The status check must stay post-auth on the login path.
- **Naive UTC datetimes.** Doctrine persists wall-clock values. Compute
  `trialEndsAt` from the injected `ClockInterface` (UTC) and store UTC.
- **Native iOS readiness.** All new responses are JSON, bearer-token, stateless.
  The trial block returns `application/problem+json` with an `accountStatus`
  field, identical to the existing suspended response. No browser coupling.

## Data model

Two nullable columns on `User` (`app_user`):

- `trialEndsAt` (`datetime_immutable`, nullable). `null` means the account has no
  trial and no expiry — today's behaviour for every existing account.
- `maxSubscriptions` (`int`, nullable). `null` means "fall back to the global
  default" (`500`).

One additive migration adds both columns. Both are nullable, so rows that predate
the columns backfill to `null`, which preserves current behaviour. The migration
is verified on SQLite and MySQL by the migration CI leg (schema build from ORM
metadata never runs a migration, so the migration needs its own verification).

New entity accessors:

- `getTrialEndsAt(): ?\DateTimeImmutable`, `setTrialEndsAt(?\DateTimeImmutable)`.
- `getMaxSubscriptions(): ?int`, `setMaxSubscriptions(?int)`.

## Trial expiry: lazy transition to suspended

A new collaborator, `App\Security\TrialExpiryGuard`, holds the one rule:

```
enforce(User $user): void
  if trialEndsAt is null            -> return (no trial)
  if trialEndsAt > now              -> return (trial still active)
  if status is Active               -> set status Suspended; flush   (one-time flip)
  throw AccountStatusException('suspended')
```

- It injects `ClockInterface` and `EntityManagerInterface`.
- The flush happens at most once per user: after the flip the status is
  `Suspended`, so the flip branch is not taken again. Non-trial users and active
  trials never flush.
- It is the named home of a deliberate side effect, so the checkers stay thin and
  the mutation is not hidden inside a `check...` method by accident.

Both checkers delegate to it:

- `UserChecker::checkPreAuth` (API): call `TrialExpiryGuard::enforce($user)`
  before the existing `status !== Active` throw. This flips and blocks a
  trial-expired user on their next API request.
- `LoginUserChecker::checkPostAuth` (login): call `TrialExpiryGuard::enforce($user)`
  before the existing `status !== Active` throw. This blocks a fresh login by a
  trial-expired user, with the status check staying post-auth (no oracle). Login
  throws before a token is issued, so the user never gets in.

The block reuses the existing `AccountStatusException` -> `AccountNotActiveException`
path in `LoginFailureHandler`, so the client receives the same `problem+json` with
`accountStatus: "suspended"` it already handles.

## Telling "trial ended" apart from "admin suspended"

Derived, with no extra column:

- `Suspended` **and** `trialEndsAt` set **and** `trialEndsAt <= now` -> suspended
  by trial expiry.
- `Suspended` with `trialEndsAt` null or in the future -> a manual admin suspend.

This is unambiguous for every case that matters. The admin UI derives the badge
from `trialEndsAt` versus now, independent of the stored status, so a user whose
trial expired but who has not returned yet (still `Active` in the database) also
shows as "trial expired". The badge is the truth; the stored status converges to
`Suspended` on the user's next access.

## Configurable max feeds

A new `App\Service\Subscription\SubscriptionLimitResolver`:

```
resolve(User $user): int
  return user.maxSubscriptions ?? DEFAULT_MAX_SUBSCRIPTIONS_PER_USER
```

The global default constant stays the single definition of the fallback. The three
sites that hardcode it today all route through the resolver:

- `SubscriptionService::createSubscription` — the per-feed subscribe cap.
- `BulkSubscriber` — the OPML/catalog batch cap.
- `UserStatistics::forUser` — the `feedsLimit` figure on the admin detail screen.

This removes the triplicated constant. Lowering a user's cap below their current
count blocks new subscribes only; existing subscriptions stay. `feedsLimit` then
reports the effective per-user cap, so the admin sees the real number.

## Admin API

New thin actions on `AdminUserController`, each reading the request, delegating to
a `Service/Admin` mutation service, and returning JSON. Request bodies are
validated with `MapRequestPayload` DTOs (`Assert` constraints).

- `POST /api/admin/users/{id}/trial` body `{ "days": int }`
  - `days` in `1..3650`.
  - Sets `trialEndsAt = now + days`. If the account is not `Active` (a
    trial-suspended user, or any non-active state), reactivate it to `Active` and
    stamp `approvedAt` — a silent restoration, no mail (mirrors the suspended
    reinstatement rule in `approve()`).
- `DELETE /api/admin/users/{id}/trial`
  - Clears `trialEndsAt` (makes the account permanent). If the account was
    trial-suspended, reactivate it to `Active` as well, so "make permanent" does
    not leave a still-blocked account.
- `PUT /api/admin/users/{id}/subscription-limit` body `{ "maxSubscriptions": int|null }`
  - `maxSubscriptions` null (clear the override) or a positive int.
  - Sets the per-user cap.

The mutation service (for example `App\Service\Admin\UserLimits`) injects
`ClockInterface` and `EntityManagerInterface` and exposes `startTrial`,
`clearTrial`, and `setSubscriptionLimit`. No boolean flag parameters.

The admin JSON mappers (`AdminUserJson::listRows`, `::account`) gain `trialEndsAt`
and `maxSubscriptions`. The password hash and token columns stay absent, as before.

## Admin UI

Admin detail screen (`admin-user-detail.component`) gains a "Limits"
`app-settings-card`:

- Trial state, derived from `trialEndsAt` and status:
  - no trial;
  - active trial, ends `<date>` (`N` days left);
  - expired on `<date>` (shown when `trialEndsAt <= now`); if the account is
    suspended, the status line reads that the suspension came from the trial.
- A **Start trial** control: a days input prefilled with `14`, and a button that
  calls `POST .../trial`.
- A **Make permanent** action that calls `DELETE .../trial`, shown only when a
  trial is set.
- A max-feeds override: an input (blank = the default `500`) that calls
  `PUT .../subscription-limit`. The effective cap comes from
  `footprint.feedsLimit`.

Admin users list (`admin-users.component`) shows a small "trial expired" badge on
rows whose `trialEndsAt <= now`, so the admin spots them while scanning.

Frontend model and API additions:

- `AdminUserAccountDto` and `AdminUserDto` gain `trialEndsAt: string | null` and
  `maxSubscriptions: number | null`.
- `AdminApi` gains `startTrial(id, days)`, `clearTrial(id)`,
  `setSubscriptionLimit(id, max | null)`.
- Dates render through the existing `formatLongDate` / `formatDateOr` helpers
  (DatePipe is unusable here — runtime Transloco language switching).

## User-facing sidebar countdown

- `MeJson::profile` gains `trialEndsAt`. The frontend `CurrentUser` interface gains
  `trialEndsAt: string | null`.
- The sidebar (`sidebar.component`) shows a small "Trial — N days left" indicator
  when `trialEndsAt` is set and in the future. It reads the account from
  `AuthService.user()`, computes the days left client-side (ceiling of the
  difference), and emphasises the indicator in the last few days. Labels are en/de
  via Transloco.
- Expired-trial users are suspended and never reach the reader, so the existing
  "account not active" handling covers them; the indicator is for active trials
  only.

## Testing

Backend:

- `SubscriptionLimitResolver`: returns the per-user cap when set, the default when
  null.
- `createSubscription`: at the effective cap it blocks, one below it allows; an
  override changes the boundary (boundary-tested, mutation-aware).
- `BulkSubscriber`: honours the per-user cap.
- Trial expiry through the firewall (functional, not direct invocation): a
  trial-expired user's API request is blocked and the stored status is flipped to
  `Suspended`; an active-trial user's request passes.
- Trial-expired login (functional): blocked, `accountStatus` reported, no token
  issued.
- Admin: `startTrial` sets `trialEndsAt` and reactivates a trial-suspended user;
  `clearTrial` clears it and reactivates; `setSubscriptionLimit` sets and clears
  the override. Assert JSON and persisted state.

Frontend:

- Sidebar: countdown shows with the correct days for an active trial; hidden when
  `trialEndsAt` is null or past.
- Admin detail: renders each trial state; the start/clear/limit controls call the
  API; the "suspended by trial" line shows for a trial-suspended account.
- Admin list: the expired badge shows only for expired trials.
- Mutation evidence for all new tests.

## Quality gates

- Feature branch `feature/66-per-user-trial-and-feed-cap` off `develop`; PR into
  `develop`. Close #66 manually on merge.
- Backend: `composer check` (cs + PHPStan max) and `composer md` clean on every
  touched file; PhpStorm inspections clean (block on ERROR/WARNING). Run the suite
  on SQLite natively and MySQL in Docker.
- Frontend: `npm run check` clean.
- Scan `backend/var/log/dev.log` after backend work.
