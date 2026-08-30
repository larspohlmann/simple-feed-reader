# Passkey login (WebAuthn) — design

Issue: [#624](https://github.com/larspohlmann/simple-feed-reader/issues/624)
Date: 2026-08-29
Status: approved for planning

## 1. Goal

A signed-in user enrols a passkey from Settings → Account. Afterwards that user
signs in with the passkey alone. No password. No OAuth redirect.

Password login, OAuth login and registration do not change. The passkey is an
additional way into an account that already exists.

On the first reader boot after this ships, the user gets one offer to enrol. If
the user declines, the app names the place to do it later. The app never offers
again.

## 2. Out of scope

Each item below is a separate ticket if we want it.

- Passkey-only registration for a new visitor.
- A passkey as a second factor on top of a password.
- Enterprise attestation policies. We accept `none`.
- A native iOS implementation. The API must not block one. Building one is not
  part of this work.
- An `apple-app-site-association` file with a `webcredentials` entry.

## 3. Decisions

### 3.1 Library: `web-auth/webauthn-lib` 5.3, without the Symfony bundle

The bundle supplies a session-backed ceremony, its own firewall authenticator
and a config-driven credential repository. This API is stateless, the challenge
must live in the cache under an opaque handle, and login must return the Lexik
JWT. The bundle opposes all three. The issue asks for the bundle only "if it
earns its weight". It does not.

Version 5.3 needs PHP ≥ 8.2 and Symfony 6.4–8.0 components. Both fit. It pulls
`spomky-labs/cbor-php`, `spomky-labs/pki-framework`, `web-auth/cose-lib` and the
Symfony serializer, property-info and property-access components.

Do not hand-roll CBOR or COSE parsing.

### 3.2 Login is a firewall authenticator, not a controller

A new stateless firewall `passkey_login` matches `^/api/auth/passkey/login$`.

**It must be declared before the `api` firewall.** Firewalls match in order and
`api` matches `^/api`, so a `passkey_login` block placed after it would never be
reached. The order becomes `dev`, `maintenance`, `login`, `passkey_login`, `api`.

It uses:

- `custom_authenticator: App\Security\PasskeyAuthenticator`
- `user_checker: App\Security\LoginUserChecker` — the same checker `json_login`
  uses, which tests status only after the credential is verified
- `success_handler: lexik_jwt_authentication.handler.authentication_success` —
  the same handler `json_login` uses
- `failure_handler: App\Security\LoginFailureHandler`
- `login_throttling: max_attempts: 5, interval: '15 minutes'`

`PasskeyAuthenticator::authenticate()` reads the JSON body, verifies the
assertion through `AssertionVerifier`, resolves the user from the stored
credential, and returns a `SelfValidatingPassport` holding a `UserBadge`.

Why an authenticator and not a controller: "the same JWT as password login"
becomes structural instead of copied. The status checks, the failure shape and
the throttling all come from the same code that serves password login. A
controller would re-implement three things that can then drift apart.

Two consequences to record.

**Throttling keys on the IP.** The request carries no e-mail, so Symfony's
`DefaultLoginRateLimiter` finds an empty identifier and the effective budget is
five attempts per fifteen minutes per client IP. That is the correct budget for
a discoverable-credential flow, which has no identifier to key on.

**The timing equaliser degenerates, harmlessly.** `LoginFailureHandler` calls
`LoginTimingEqualizer` with the submitted identifier, which is empty here. The
result is a constant delay on a failed passkey login. It leaks nothing, because
this flow has no address to enumerate, and it costs the success path nothing,
because the handler runs on failure only. Reuse the handler; do not write a
second one.

### 3.3 `/passkey/login/options` needs its own rate limit

This is a finding, not part of the issue text.

Conditional mediation calls this endpoint on every login-page view, from every
anonymous visitor, and each call writes a cache entry. That is an unbounded
write surface a stranger controls — the risk `OAuthStateStore` records in its
docblock.

Add a `passkey_challenge` limiter to `rate_limiter.yaml`: sliding window, 30 per
15 minutes, keyed by IP, on the `cache.rate_limiter` pool, applied through the
existing `RateLimitGuard`.

It must be a separate budget from the login limiter. If the two shared a budget,
loading the login page would consume the sign-in allowance.

### 3.4 The relying party is admin-configured, not an environment variable

The issue proposes `PASSKEY_RP_ID` and `PASSKEY_RP_NAME` in `.env`. We do not
add them. `InstanceSetting.publicBaseUrl` (#636) already solved this exact
problem, and we follow it.

Two nullable columns join the `instance_setting` singleton row:
`passkey_rp_id` and `passkey_rp_name`. A new interface
`App\Service\Settings\PasskeyRelyingParty`, with `id()` and `name()`, reads them
and applies the fallback in exactly one place, the way `PublicBaseUrl` does.

**The fallback beats an environment variable.** The default RP id is the host of
`PublicBaseUrl::get()`. That yields `localhost` in development and the real
domain in production with no configuration at all, and it is self-correcting:
the admin must already set the public base URL, because every e-mail link
depends on it. The default RP name is `Simple Feed Reader`.

**Validation is the reason this belongs in the admin UI.** An RP id must equal
the origin's host or be a registrable suffix of it. A wrong value fails every
ceremony in the browser with an opaque `SecurityError`, on the client, leaving
nothing in the server log. The admin form validates the value against the public
base URL it already holds, and refuses a bad one with a 422 that says why. An
environment variable cannot do this.

**The value stays configurable for the reason the issue gives.** An RP id is
baked into every credential. Moving the app to a subdomain later invalidates
every enrolled passkey. The field exists to make that recoverable, not because
we expect to change it.

#### 3.4.1 The admin page documents both fields

A relying-party id is the kind of value an admin gets wrong once and then cannot
debug, because the failure happens in the browser and writes nothing to the
server log. The form therefore explains itself.

**The strongest documentation is the live value, not prose.** `InstanceSettingsJson`
gains `passkeyRpIdEffective`, the value the server would use right now. The RP id
field shows it as its placeholder, and the row's description says "Leave empty to
use `lars-pohlmann.de`" with the real host in it. An admin who reads nothing still
sees the right answer.

Below the two fields sits an `app-disclosure`, closed by default, labelled *How do
I find these values?*. It holds:

- A three-row table mapping a serving URL to the id to use. The example domains
  are literals, not translated strings.
- The four rules: the domain alone with no scheme, port or path; it must be the
  serving host or a parent of it; a public suffix such as `com` is refused; an IP
  address is refused, and `localhost` is the one exception.
- The warning that changing the id signs every passkey out for good, and that the
  reader refuses the change until it is confirmed.
- What the name field is: the text a device shows in its passkey prompt, and the
  label a password manager saves. Changing it is safe, and devices that already
  saved a passkey keep showing the old name.

The disclosure carries the detail so the form itself stays short. The one-line
descriptions on the rows carry what an admin needs without opening it.

### 3.5 Changing the RP id is guarded

An environment variable needed a deploy. A text field does not. So the PUT
refuses the change with a 409 while any `user_passkey` row exists. The
`problem+json` body names how many credentials the change would invalidate.

To proceed, the payload carries `invalidateExistingPasskeys: true`. The frontend
renders that as a confirm dialog quoting the count.

When the change proceeds, the server deletes every `user_passkey` row in the
same transaction. Orphaned credentials are worse than none: each user would see
them in Settings as working sign-in methods that always fail.

### 3.6 The origin check is separate from the RP id

The RP id is a registrable domain. The `/reader` subpath production runs under
is irrelevant to it. The origin check is a different thing and must accept the
real production origin, which comes from `PublicBaseUrl::get()`.

## 4. Backend

### 4.1 Entity

`App\Entity\UserPasskey`, table `user_passkey`. Not a reuse of `user_identity`:
that table is keyed on `(provider, provider_user_id)` and carries OIDC subject
semantics, while a credential needs a public key, a signature counter and
transports.

| Column | Type | Notes |
|---|---|---|
| `id` | int, generated | Surrogate key |
| `user_id` | FK → `app_user`, not null, `onDelete: CASCADE` | |
| `credential_id` | string(255), `utf8mb4_bin` | Base64url on the wire |
| `user_handle` | string(64), `utf8mb4_bin` | See 4.1.1 |
| `public_key` | text | The COSE key, base64url |
| `signature_counter` | int | |
| `aaguid` | string(36), nullable | |
| `transports` | JSON | |
| `label` | string(100) | Named on create, editable never (out of scope) |
| `created_at` | datetime_immutable | Naive UTC |
| `last_used_at` | datetime_immutable, nullable | Naive UTC |

`credential_id` is pinned to `utf8mb4_bin` explicitly, for the reason
`user_identity.providerUserId` is pinned and documented: MySQL would otherwise
inherit `utf8mb4_0900_ai_ci` and compare case-insensitively while SQLite
compares case-sensitively. A credential id is an opaque token where `a` and `A`
are different identifiers.

A unique constraint on `credential_id`. A credential belongs to exactly one
user.

#### 4.1.1 The user handle

`Webauthn\CredentialRecord` requires a non-nullable `userHandle`, and
`CheckUserHandle` verifies it on every assertion. So the account needs a stable
opaque handle, and it must not be the e-mail address: the handle is stored by
the authenticator and syncs to the user's password manager, so it must carry no
personal data. The numeric account id is also wrong, because it leaks how many
accounts the instance has and in what order they were made.

The handle is 32 random bytes, base64url-encoded, and it lives on the
`user_passkey` row rather than on `app_user`. `PasskeyCredentials::userHandleFor(User)`
returns the handle already used by that user's credentials, or mints one when
the user has none. Every credential a user owns therefore carries the same
handle.

Putting it here rather than on `app_user` keeps `app_user` untouched, and so
keeps the backup drift guard at the two hits recorded in 8.2 instead of three.
`user_passkey` is dropped from the backup wholesale, so the column needs no
declaration of its own.

### 4.2 Endpoints

All under `/api/auth/passkey`, JSON in, `application/problem+json` out.

| Method | Path | Auth | Purpose |
|---|---|---|---|
| POST | `/api/auth/passkey/register/options` | bearer | Creation options plus a challenge handle |
| POST | `/api/auth/passkey/register` | bearer | Verify the attestation, store the credential |
| GET | `/api/auth/passkeys` | bearer | List the caller's passkeys |
| DELETE | `/api/auth/passkeys/{id}` | bearer | Remove one |
| POST | `/api/auth/passkey/login/options` | public | Request options plus a challenge handle |
| POST | `/api/auth/passkey/login` | public | Verify the assertion, return the JWT |

Plus one more, for the offer flag only, described in 5.1:

| Method | Path | Auth | Purpose |
|---|---|---|---|
| POST | `/api/me/passkey-offer/answer` | bearer | Mark the enrolment offer answered |

Every binary field crosses the wire base64url-encoded inside the JSON body. A
native client must be able to drive these endpoints through
`ASAuthorizationPlatformPublicKeyCredentialProvider` without a browser API.

Two contract details worth pinning down.

**`DELETE /api/auth/passkeys/{id}` scopes the lookup to the caller.** The lookup
is by `(id, user)`, and a credential owned by somebody else returns `404`, not
`403`. A `403` would confirm that the id exists.

**`label` is client-supplied at register time**, not blank, at most 100
characters, validated by the request DTO. It is the string the user sees in the
list, so an empty one would produce a nameless row.

### 4.3 A trap in `security.yaml`

`access_control` already makes `^/api/auth/` `PUBLIC_ACCESS`, and the first
match wins. Four of the six paths above sit under that prefix and must be
authenticated. Two prefix rules cover all four:

```yaml
- { path: ^/api/auth/passkey/register, roles: IS_AUTHENTICATED_FULLY }
- { path: ^/api/auth/passkeys, roles: IS_AUTHENTICATED_FULLY }
```

The first also covers `/register/options`. The second also covers
`/passkeys/{id}`. Neither matches `/api/auth/passkey/login`, which stays public.

Both rules must sit **above** the `^/api/auth/` line. Without them the enrolment
endpoints are public. A functional test must prove each of the four paths
rejects an anonymous caller — the rules are prefix matches, and a prefix match
is exactly the kind of thing that is right in review and wrong in production.

### 4.4 Services

`App\Service\Passkey\`:

- `PasskeyChallengeStore` — the shape of `OAuthStateStore`. It mints an opaque
  handle, stores the record under the handle's digest, TTL 5 minutes, single
  use. Only the digest is stored, and it is compared with `hash_equals`, because
  for those five minutes the handle is a bearer credential and a readable cache
  directory must not be a list of usable ones. A registration record also
  carries the authenticated user id, and the verifier requires that it matches
  the caller.
- `RegistrationOptionsFactory` — builds the creation options. User verification
  is `required`. Attestation is `none`. Resident key is `required`, because the
  login flow is discoverable-credential only. The exclude list holds the
  caller's existing credential ids, so the same authenticator cannot enrol
  twice.
- `AssertionOptionsFactory` — builds the request options. User verification is
  `required`. The allow list is empty, because the flow takes no e-mail.
- `PasskeyCeremony` — builds and caches the two `CeremonyStepManager` instances
  and the Webauthn serializer. See 4.4.1.
- `AttestationVerifier` — verifies the attestation, then persists the
  credential.
- `AssertionVerifier` — verifies the assertion, then resolves the user.
- `PasskeyCredentials` — the credential repository wrapper. It resolves a
  credential id to a credential and stamps `last_used_at`.
- `PasskeyRemovalPolicy` — see 4.6.

Exceptions live in `App\Service\Passkey\Exception\`, typed and namespaced next
to the service, never signalled with `null`.

#### 4.4.1 The ceremony managers must be built lazily

`CeremonyStepManagerFactory::setAllowedOrigins()` bakes the allowed origins into
the `CeremonyStepManager` it then produces. Our origin comes from
`PublicBaseUrl`, which reads the database, so the managers cannot be built when
the container is compiled.

`PasskeyCeremony` therefore builds them on first use and holds them for the rest
of the request. It exposes `creation(): CeremonyStepManager`,
`request(): CeremonyStepManager`, `serializer(): SerializerInterface` and
`host(): string`. Every other service depends on this one, never on the library
factory.

Two library behaviours we get for free, and must not re-implement:
`CheckCounter`, wired to `ThrowExceptionIfInvalid` by default, enforces the
signature-counter rule from 4.5, and `CheckUserVerification` enforces
`userVerification: required`. Our code adds the logging the issue asks for by
catching the library's exception, not by checking the counter a second time.

Controllers stay thin. `ThinControllerRule` enforces it.

### 4.5 Security requirements

- **No enumeration.** `/passkey/login/options` returns the same shape and the
  same timing whether or not any account exists. It takes no e-mail.
- **User verification is `required`** on both ceremonies. The passkey is the
  sole factor, so a presence test is not enough.
- **The signature counter must increase** when the authenticator reports one. A
  non-increasing counter signals a cloned credential: reject the assertion and
  log it at `warning` with the credential id and the user id. An authenticator
  that always reports `0` is exempt, per spec.
- **Challenges are single use.** A replayed or expired handle is rejected.
- **A credential belongs to exactly one user.** The assertion resolves the user
  from the stored credential. It never trusts a client-supplied user handle.
- **The RP id and the origin are both checked**, and they are checked against
  different values (3.6).

### 4.6 Never lock the account out

Deleting a passkey is refused when it is the last one **and** the account has
neither a password hash nor a linked OAuth identity.

`User.passwordHash` is nullable, so "has a password" is not the same question as
"has an account". `PasskeyRemovalPolicy` asks both questions.

The refusal is a 409 with a `problem+json` body that says which other sign-in
method the user must add first.

## 5. The first-login offer

### 5.1 Storage

`Preferences` gets one nullable column, `passkey_offer_answered_at`
(datetime_immutable, naive UTC). `/api/me` exposes it inside `preferences` as
the boolean `passkeyOfferAnswered`.

Server-side, not `localStorage`, so the answer survives a cleared cache and a
new device, and so a native client can read it.

**It does not reuse the preferences PATCH.** `UpdatePreferencesRequest` gives
its field no default on purpose: its docblock records that a preference which
degrades quietly to a default is indistinguishable from one the user set. Adding
a defaulted field would contradict that, and adding a required one would force
every offer answer to resend `scrapeFallbackEnabled`. `MeController` already
split the locale PATCH from the preferences PATCH for this same reason.

So the flag gets its own action: `POST /api/me/passkey-offer/answer`, empty
body, idempotent, `204`. It lives on a small dedicated
`App\Controller\Api\PasskeyOfferController` rather than on `MeController`, whose
constructor already carries eight dependencies. It delegates to a
`PasskeyOffer::markAnswered(User)` service. The path is under `^/api/`, so the
catch-all `IS_AUTHENTICATED_FULLY` rule already covers it and `security.yaml`
needs no change for it.

The recorded cost: a user who declines on a phone is never offered on a laptop.
That user adds a passkey from Settings. This is the accepted trade for "never
ask again" holding.

### 5.2 When the clock is stamped

Two places:

1. The user answers the dialog.
2. A passkey is enrolled successfully, from anywhere.

So a user who enrols from Settings before ever seeing the dialog is never
offered. The frontend therefore needs only the one boolean; it does not need a
credential count.

### 5.3 The rule

The reader shell shows the dialog when all four hold:

1. `passkeyOfferAnswered` is false.
2. `window.PublicKeyCredential` exists.
3. The shell has settled and the subscription onboarding is not running.
4. The user is on the reader, not on an auth route.

Condition 3 matters: a new account is redirected to `/discover` for the
subscription onboarding, and a modal on top of that is wrong.

The offer fires on the first reader boot where the flag is unset, not strictly
on the login POST. For anyone who was signed out when this ships, the two are
the same moment.

### 5.4 The dialog

A CDK dialog with two states.

**State one** offers *Set up a passkey* and *Not now*.

*Set up a passkey* runs the same ceremony Settings runs. On success the dialog
shows a short confirmation and closes; the enrol endpoint has already stamped
the flag. On failure, or when the user cancels the authenticator sheet, the
dialog stays open, shows the error, and keeps *Not now* available. **A cancelled
sheet does not count as an answer.**

**State two**, reached by *Not now*, names the path: Settings → Account →
Passkeys. One OK button. The flag is stamped when this state opens, not when OK
is pressed, so an Escape here still counts.

**Any close marks the offer answered** — the button, Escape, or the backdrop.

If the flag write fails, for example offline, the offer returns on the next
boot. That is acceptable.

## 6. Frontend

- `core/webauthn.ts` — base64url encode and decode, plus the availability test.
  Pure, so it is unit-testable without a DOM.
- `core/passkey.service.ts` — the four HTTP calls and the two ceremonies. Errors
  go through `core/problem.ts` like every other API failure.
- `settings/passkeys-group.component.*` — an `<app-settings-group>` used inside
  `account-section`, built from the Grouped primitives in
  `shared/settings/`. It holds the list, a "last used" line per row, an *Add a
  passkey* action and a per-row remove. Keeping it a sibling component stops
  `account-section` from growing.
- `auth/login` — a *Sign in with a passkey* button beside the OAuth buttons,
  plus conditional mediation.
- `reader/passkey-offer-dialog.component.*` — the dialog from section 5.
- `settings/admin/admin-settings` — the two relying-party fields and the
  invalidation confirm dialog.

Both passkey entry points are hidden when `window.PublicKeyCredential` is
absent. Styles live in sibling `.scss` files. No hex colours, no raw `px`.
`TokenStore` and the existing post-login navigation are reused. No new session
concept, no cookie.

### 6.1 Three frontend traps

- **Conditional mediation needs the right autocomplete token.** The e-mail input
  must carry `autocomplete="username webauthn"`. Today it carries `email`.
- **The conditional request must be aborted when the password form submits.**
  Otherwise the two ceremonies compete and the browser rejects one. Hold the
  `AbortController` and abort it in the submit path.
- **jsdom has neither `PublicKeyCredential` nor `navigator.credentials`.** Every
  spec that touches this code must stub both, and at least one spec must cover
  the absent case, because that is the branch that hides the buttons.

## 7. Refactor carried by this change

`AdminSettingsController::update` would take four scalars once `passkeyRpId` and
`passkeyRpName` join it, and `InstanceSettings::update` would take five. CLAUDE.md
puts the line at three.

Introduce `App\Service\Settings\InstanceSettingsUpdate`, a value object holding
the fields, which `InstanceSettingsRequest` maps to and `InstanceSettings::update`
takes as its single parameter.

Note that `InstanceSettingsRequest` is a full-replace payload, documented as
such: `#[MapRequestPayload]` fills a missing field with the constructor default,
so a PUT that omits a field resets it. The two new fields keep that contract, and
the client always sends every field. `invalidateExistingPasskeys` is not a
setting but a command modifier, and it defaults to `false`.

## 8. Testing

- Unit tests per service.
- Functional tests per endpoint, through the real firewall. A direct-invocation
  test of the assertion verifier can assert something the real wiring makes
  impossible.
- Named negative cases: a replayed challenge, an expired challenge, a credential
  belonging to a different user, a counter that goes backwards, a tampered
  client-data hash, an origin mismatch, an RP-id mismatch.
- Each of the three authenticated paths in 4.3 rejects an anonymous caller.
- Deleting the last credential on an account with no password and no OAuth
  identity is refused.
- Changing the RP id is refused with a 409 while credentials exist, and deletes
  them when confirmed.
- An RP id that is not a suffix of the public base URL host is refused with 422.
- The offer flag is stamped by an enrolment as well as by an answer.
- `composer infection:diff` gates the changed files.

### 8.1 The migration

Three migrations, one per schema-touching task: `user_passkey`, the
`preferences` column, and the two `instance_setting` columns. One migration per
task keeps each task independently testable and independently revertable, which
is worth more than a single tidy file.

`tests/bootstrap.php` builds the schema from ORM metadata, so no test ever
executes a migration. The dedicated CI leg must migrate this from empty on both
SQLite and MySQL and then run `doctrine:schema:validate`.

### 8.2 The backup drift guard fires twice

Confirmed against `BackupSchemaCoverageTest`:

1. `UserPasskey` is a new account-scoped entity. Declare it dropped, with the
   reason: a passkey is bound to a device and to an RP id, so a credential
   restored into another account or another device could never authenticate, and
   exporting credential ids and public keys widens the blast radius of a leaked
   backup file for no gain.
2. `Preferences.passkeyOfferAnsweredAt` is a new field on a backed-up table.
   Declare it not backed up, with the reason: it is interface state, not account
   configuration.

The two `instance_setting` columns need **no** declaration. `InstanceSetting` is
already listed in `INSTANCE_SCOPED`, which excuses the whole entity.

### 8.3 Playwright

A CDP virtual authenticator can drive the whole flow. Optional, and outside the
CI gate like the other smokes. A spec must own the data it asserts on.

## 9. Native iOS

The flow satisfies the §6 design-time checklist in `docs/architecture.md`:
bearer auth, stateless requests, JSON both ways, `application/problem+json` out,
no browser-only inputs, no redirect-to-web handoff, no CSRF token.

Every binary field is base64url inside the JSON payload, so a native client
drives the same endpoints without a browser API.

A native client additionally needs an `apple-app-site-association` file with a
`webcredentials` entry on the RP domain. That is future additive work and is out
of scope here.

## 10. Acceptance criteria

- [ ] A signed-in user enrols a passkey from Settings → Account and sees it
      listed with its creation date.
- [ ] That user signs out, chooses *Sign in with a passkey*, and reaches the
      reader with a valid JWT. No e-mail and no password are typed.
- [ ] An enrolled passkey can be removed, and removal is refused when it would
      leave the account with no way in.
- [ ] Password login, OAuth login and registration behave exactly as before.
- [ ] `/passkey/login/options` reveals nothing about which accounts exist.
- [ ] A replayed assertion and a regressed signature counter are both rejected.
- [ ] The relying party is admin-configured. Development on `localhost` and
      production on the real domain both work with no code change and no
      environment variable.
- [ ] Changing the RP id while credentials exist is refused until confirmed, and
      the confirmed change removes them.
- [ ] On the first reader boot after this ships, the user is offered a passkey
      once. A decline names Settings → Account → Passkeys. The offer never
      returns.
- [ ] `composer check`, `composer md`, `php bin/phpunit` on both legs, and
      `npm run check` all pass.
