# Mailless-capable instance + configurable registration gates

**Issues:** #230 (mailless install), #224 (admin toggles for registration gates)
**Date:** 2026-08-01
**Branch:** `feature/230-mailless-instance`

## Problem

Two committed defaults make outgoing mail effectively mandatory:

- `docker-compose.prod.yml` hard-requires `MAILER_DSN` via `${MAILER_DSN:?}`, so
  the prod stack refuses to start without it.
- `InsecureProductionConfigGuard` answers 500 to every request while
  `MAILER_DSN=null://null` is set.

Both exist to catch a *forgotten* mail config. A *deliberately* mailless
instance — a private single-user or family deployment with no relay and no host
MTA — has no way to express itself and cannot run.

Separately, registration always forces two mail-dependent gates before a new
account can log in: email confirmation (double opt-in) and admin approval. An
admin cannot relax either without a code change.

## Goals

- Let an operator run the instance with **no outgoing mail**, as an explicit
  opt-in — never a default. A merely *unconfigured* mailer still fails loud.
- Give the admin two runtime switches: **require email confirmation** and
  **require admin approval**, both default **on** (no behaviour change on
  upgrade).
- Adapt the app coherently when mail is off: registration gates degrade,
  password reset moves to an operator-driven path, notification mail is skipped
  with a log line, and the SPA hides the dead entries and shows *why*.
- Preserve the native-iOS constraint: every endpoint stays bearer-token,
  stateless, JSON in / `application/problem+json` out. No browser-only inputs.

## Non-goals

- No closed-registration / admin-creates-accounts model. Public self-registration
  stays open on a mailless instance, gated by the toggles (decided in
  brainstorming).
- No general-purpose key/value config subsystem. The settings store is a small,
  typed, single-row entity sized to this feature (and reusable later).

## Design decisions (settled in brainstorming)

1. **Registration gates live in an admin-editable DB settings store** (not env
   flags). This establishes reusable instance-settings infrastructure that
   #66/#64 will also want.
2. **On a mailless instance, public registration stays open**, gated by the
   toggles. Email confirmation is forced off there; a new account goes straight
   to `PendingApproval` (approval on) or `Active` (approval off). The
   unverified-address risk is accepted and documented (#224).
3. **Password recovery on a mailless instance** uses a CLI command **and** an
   admin UI reset button.
4. **Capability discovery** extends the existing `GET /api/setup/status`
   response with `mailEnabled` (no new endpoint).

## Architecture

### Two layers, one source of truth

- **Mail capability — deploy-time env.** A new `MAIL_DISABLED` flag. It must be
  env, not DB, because `docker-compose.prod.yml` and
  `InsecureProductionConfigGuard` act before the app can read the database.
- **Registration gates — DB store.** A single-row typed `InstanceSetting`
  entity: `requireEmailConfirmation` (bool, default true), `requireApproval`
  (bool, default true). Read/written through a repository and an admin endpoint.
- **`RegistrationPolicy`** service — the single point that combines both. Nothing
  downstream re-derives these facts:
  - `mailEnabled(): bool` — `MAIL_DISABLED` is not truthy.
  - `emailConfirmationRequired(): bool` — `requireEmailConfirmation` **AND**
    `mailEnabled()`. Mail off forces confirmation off.
  - `approvalRequired(): bool` — `requireApproval`. Independent of mail.
  - `prospectiveStatusForEmailSignup(): UserStatus` — the status a new
    email/password signup would receive under the current policy
    (`PendingVerification` / `PendingApproval` / `Active`). Used by the API
    response (see anti-enumeration note below).

The `InstanceSetting` row is loaded once per request (cheap; one row) and
carries sane defaults so a fresh database with no row behaves as
`on/on` — no data migration needed to seed behaviour.

### Backend gating

**`RegistrationService::register()`** branches on the policy:

| emailConfirmationRequired | approvalRequired | first status | side effects |
|---|---|---|---|
| on | on | `PendingVerification` | issue VerifyEmail token, send verification mail |
| on | off | `PendingVerification` | issue token, send verification mail |
| off | on | `PendingApproval` | dispatch `UserAwaitingApproval` (+ notify mail if mail on) |
| off | off | `Active` | stamp `approvedAt`, no event |

(The email-confirmation column is the *effective* value from `RegistrationPolicy`,
so `MAIL_DISABLED=1` collapses the first two rows into the lower two.)

**Anti-enumeration is preserved.** Today `register()` returns `void` and the
controller always answers a fixed `{status:'pending_verification'}`, identical
whether the address was new or already registered — that identical response is
what stops the endpoint from being an existence oracle
(`RegistrationService.php:41-58`). The new response reports the
**policy-derived prospective status**, which is *instance-wide public policy*,
not a per-address fact: it is identical for a genuine new signup and for the
silent duplicate path. The duplicate path still spends one argon2id hash via
`PasswordWorkEqualizer` and returns the same status. No new oracle is created.
This is a security-relevant invariant; the plan must add a test that both paths
return byte-identical responses.

**`RegistrationService::verifyEmail()`**: when `approvalRequired()` is off,
`PendingVerification → Active` (stamp `approvedAt`), and **do not** dispatch
`UserAwaitingApproval`. When on, current behaviour
(`PendingVerification → PendingApproval` + dispatch).

**`OAuthAccountLinker`**: when `approvalRequired()` is off, `createUser()` and
`claimIfUnverified()` converge to `Active` + `approvedAt` and skip the
`UserAwaitingApproval` dispatch. OAuth still skips email confirmation regardless
(the provider verified the address) — unchanged. The email-confirmation toggle
does not affect the OAuth path.

**Mail send boundary.** A guard at the `AccountMailer` boundary (a decorator, or
an explicit `mailEnabled()` check at each send site — decorator preferred for
DRY) that, when `!mailEnabled()`, **skips the send and writes one log line**
instead. This uniformly covers verification, password-reset, and the
approval-notification mail (#164) — satisfying #230's "log line instead". With
mail off, `register()` never reaches `sendVerification()` anyway (confirmation
forced off); the boundary is defense-in-depth for the reset endpoint and the
approval-notification listener, which can still fire.

### Guard, compose, installer (#230 infra)

- **`docker-compose.prod.yml`**: `MAILER_DSN` stays `${MAILER_DSN:?}`-required,
  so a *forgotten* mailer still fails loud at start. Add
  `MAIL_DISABLED: ${MAIL_DISABLED:-}` to the `php` service environment. The
  installer's "No mail" choice writes **both** `MAIL_DISABLED=1` and
  `MAILER_DSN=null://null`, which satisfies the `${VAR:?}` requirement.
- **`InsecureProductionConfigGuard`**: inject `MAIL_DISABLED`. `null://null` is
  accepted **iff** `MAIL_DISABLED` is truthy; a bare `null://null` without the
  flag still 500s. The ALTCHA placeholder check is unchanged. Update the class
  docblock to record the mailless exception.
- **`scripts/lib.sh` `configure_mail`**: add choice **"4) No mail (disable
  registration/reset mail on this instance)"**, distinct from "3) Later". It sets
  `MAIL_DISABLED=1` and `MAILER_DSN=null://null`, and skips the From/mail-check
  steps. The `ENV_PROD_REQUIRED` validation must treat `MAILER_DSN` as satisfied
  when the "No mail" path set it to `null://null` (it is set, so the existing
  presence check already passes).
- **`.env.prod.example`**: document `MAIL_DISABLED` next to `MAILER_DSN`,
  including the unverified-address consequence.

### Password recovery (mailless)

- **CLI**: `app:user:reset-password <email>` command, mirroring
  `CreateAdminCommand`. Sets a new password (prompted or generated) and stamps
  `passwordChangedAt` so old JWTs die.
- **Admin UI**: a "reset password" action on the admin user list. The endpoint
  **generates a random password server-side and returns it once** in the
  response for the admin to relay out-of-band — no admin-typed-password form, one
  action. Stamps `passwordChangedAt`. Bearer JWT, JSON, `problem+json`. Reuses
  the same underlying service as the CLI command.
- Available regardless of mail state (an operator escape hatch), but it is the
  *only* recovery path when mail is off.

### Frontend

- **`GET /api/setup/status`** extended: `{needsSetup, mailEnabled}`.
  `SetupController::status()` reads `RegistrationPolicy::mailEnabled()`.
  `SetupService` exposes a `mailEnabled` signal. The endpoint stays public and
  native-safe.
- **Login** (`login.component.html`): hide the forgot-password link when
  `!mailEnabled`. The register link stays (registration remains open).
- **Register**: react to the real returned status; no "check your email" message
  when confirmation is off — show the message that matches the resulting status.
- **Admin settings section**: a new section in `settings-sections.ts` +
  `settings.routes.ts` + component, with the two toggles wired to the admin
  settings endpoint. When mailless, the email-confirmation toggle is shown
  **disabled with an explanation**, and the mailless state is surfaced so the
  operator sees why the mail-dependent flows are off.
- **Reset routes** (`reset-request`, `reset-password`): fail gracefully / show an
  "unavailable" state when `!mailEnabled` — defense-in-depth, since the link is
  already hidden.

### Admin settings endpoint

- `GET /api/admin/settings` → the current toggle values.
- `PUT` (or `PATCH`) `/api/admin/settings` → update the toggles. Validated DTO,
  `problem+json` on error. Thin controller: reads request, delegates to a
  service that persists the single row, returns the response. No business logic
  in the controller (ThinControllerRule).
- Toggles affect **future** registrations only; users already in a queue are
  untouched.

## Data flow

1. Operator chooses "No mail" at install → `.env.prod` gets `MAIL_DISABLED=1` +
   `MAILER_DSN=null://null`.
2. Compose starts (both vars set). Guard sees `MAIL_DISABLED=1`, accepts
   `null://null`.
3. SPA loads → `GET /api/setup/status` → `mailEnabled:false` → forgot-password
   hidden, register page adjusted, admin settings section shows mailless state.
4. A user registers → `register()` reads `RegistrationPolicy` → email
   confirmation forced off → lands in `PendingApproval` (or `Active`) → API
   returns the real status.
5. `AccountMailer` boundary skips any send and logs a line.
6. A user forgets their password → admin uses the CLI command or the admin UI
   reset button → relays the new password out-of-band.

## Error handling

- Guard throws `RuntimeException` → 500 with the operator message in the log only
  (existing `ApiExceptionListener` behaviour). Unchanged mechanism; extended
  rule.
- Admin settings update: invalid payload → `application/problem+json` 400/422.
- Reset endpoint / CLI: unknown email → clear error to the operator (this path is
  admin-only, so no enumeration concern — unlike the public reset endpoint).

## Testing

- **Anti-enumeration**: functional test that `register()` returns byte-identical
  responses for a new address and an already-registered address, under every
  toggle combination.
- **Policy matrix**: `RegistrationService::register()` and `verifyEmail()` cover
  all four toggle combinations; assert resulting `UserStatus`, token/mail issued
  or not, and `UserAwaitingApproval` dispatched or not.
- **OAuth**: `OAuthAccountLinker` with approval off → `Active` + `approvedAt`, no
  event; approval on → current behaviour. Back listener assertions with a
  functional test (per the direct-invocation-tests-mislead rule).
- **Guard**: `problems()` asserted in both directions for `MAIL_DISABLED`
  truthy/absent × `null://null`/real DSN.
- **Mail boundary**: `!mailEnabled()` skips send and logs; `mailEnabled()` sends.
- **Admin settings endpoint**: get/update round-trip; validation; auth (admin
  only).
- **Password reset command + endpoint**: sets password, stamps
  `passwordChangedAt`, invalidates old JWTs.
- **Migration**: the new `InstanceSetting` table migrates from empty on SQLite
  and MySQL, then `doctrine:schema:validate` (the dedicated CI leg — migrations
  are never executed by the test bootstrap).
- **Frontend (Jest)**: `SetupService` exposes `mailEnabled`; login hides
  forgot-password when mailless; admin settings component toggles; register
  message reacts to status.

## Security notes

- Turning email confirmation off lets an account go active with an unverified
  address (typo / squatting / no self-service reset). Accepted for the mailless
  use case; documented; default stays on.
- The register response must not become an existence oracle (see
  anti-enumeration note). Tested explicitly.
- `MAIL_DISABLED` must not weaken the ALTCHA guard — that check is independent and
  stays enforced.
- The admin reset endpoint returns a fresh secret exactly once; it is
  admin-authenticated and stamps `passwordChangedAt`.

## Rollout / compatibility

- Defaults are on/on; no behaviour change on upgrade for existing mail-capable
  instances.
- The `InstanceSetting` row is optional: absence means defaults, so no data
  backfill is required.
- Docs: `docs/docker-production.md` and `docs/first-run-setup.md` gain a mailless
  section; `.env.prod.example` documents `MAIL_DISABLED`.
- One combined branch off `develop` (`feature/230-mailless-instance`). PR
  references and closes both #230 and #224.

## Open items for the plan

- Confirm the admin reset button UX: generated-once secret (recommended) vs
  admin-typed. Spec assumes generated-once.
- Confirm `InstanceSetting` shape: typed single-row (recommended) vs key/value.
  Spec assumes typed single-row.
