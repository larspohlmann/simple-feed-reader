# Admin mail configuration — design (#834)

**Status:** agreed (grilling session 2026-09-04). This is the source of truth;
the on-branch plan argues from it.

## Goal

An admin configures outgoing mail from the admin UI. No `.env` edit. No
redeploy. This covers two pains: activating mail on a fresh install, and
changing mail provider later.

## Scope

One global, instance-wide configuration owned by an admin:

- SMTP transport: host, port, username, password, encryption.
- Identity: from-address, from-name.

Out of scope: mail templates, per-mail toggles, per-user mail, and sendmail as a
UI-selectable transport (the form is SMTP-only).

## Template

The feature mirrors the existing instance egress-proxy settings
(`ProxyServerSettings` and its service/JSON/DTO/controller/tester/frontend
section). Anywhere this spec says "mirror the proxy", copy that pattern and
change only the mail-specific parts.

## Storage and override model

- A new singleton entity `MailServerSettings` holds the configuration (one row,
  `findSingleton()`, absence = "not configured", defaults from a fresh entity).
- Resolution rule, everywhere: **DB-when-present, else env fallback.** A saved DB
  transport overrides the env even when an env transport exists, so provider
  swaps are live.
- The env transport DSN is renamed `MAILER_DSN` → `MAILER_FALLBACK_DSN`.
  `MAILER_DSN` becomes the fixed value `dynamic://default`, handled by a new
  transport factory (below).

## Enablement

- `enabled` is a boolean column on the entity.
- **No row** = "not configured": enablement then **derives** from the env
  fallback — enabled iff the fallback is a real transport (scheme present and not
  `null://`). This keeps an existing install (real env DSN, no row) sending after
  upgrade, and keeps a fresh/no-mail install (empty or `null://` fallback) off.
- **Row present**: the DB `enabled` value governs and overrides the env. So an
  admin can enable mail on an install that shipped with no mail, with no `.env`
  edit — the explicit requirement behind this feature.
- The JSON mapper reports the **derived** `enabled` when the row is absent, so the
  toggle shows the true current state and the first Save adopts it faithfully
  instead of silently switching mail off.
- `MAIL_DISABLED` is **retired**. Its job — "run without mail" — is now the
  natural state of "no configured transport and no real fallback", plus the
  admin toggle. `MAIL_FROM` / `MAIL_FROM_NAME` stay as the identity fallback.

## The `dynamic://` transport

Symfony's mailer transport is built at boot from the DSN, but the config now
lives in the DB and must be read per-send. A custom transport factory bridges
this:

- `MailServerSettings` config → `MAILER_DSN=dynamic://default`.
- `DynamicMailTransportFactory implements TransportFactoryInterface`
  (autoconfigured by FrameworkBundle via the `mailer.transport_factory` tag)
  supports scheme `dynamic` and returns a `DynamicMailTransport`.
- `DynamicMailTransport implements TransportInterface` resolves, **at send time
  not at construction** (the DB is not reachable during `cache:warmup`), the
  active transport:
  - DB config present (host set) → an `EsmtpTransport` built from the stored
    host/port/username/password/encryption.
  - else → the env fallback, built from `MAILER_FALLBACK_DSN`.
  - The built transport is memoised per resolved signature so a digest batch does
    not reconnect per message.
- Both the SMTP build and the fallback build receive the app's
  `EventDispatcherInterface` / `HttpClientInterface` / `LoggerInterface`, so the
  message-logger listener still collects sent messages (Symfony's mail test
  assertions and any listeners keep working). The fallback is built via
  `new Transport(Transport::getDefaultFactories($dispatcher, $httpClient,
  $logger))->fromString($dsn)`, whose default factory set does **not** include
  `dynamic`, so there is no recursion.

Downstream is untouched: `DeferredMailer` (account mail), `DigestMailer`, and the
`MailGated*` decorators all send through `mailer.mailer`, which is now the
dynamic transport. Because account mail drains on `kernel.terminate`, the DB read
happens after the response and does not re-introduce the timing oracle
`DeferredMailer` exists to remove.

## Secret at rest

- A new `MailPasswordCipher` copies `ProxyPasswordCipher` exactly, changing only
  the binding string to `mail-password|v%d|instance` (cryptographic domain
  separation from the proxy and AI secrets). `SealedMailPassword` and
  `MailPasswordUnreadableException` mirror their proxy equivalents.
- Master secret: the **same** env the proxy and AI ciphers already read — no new
  secret. That env is renamed (below).
- The password is sealed (XChaCha20-Poly1305), never returned. The API exposes
  only `hasPassword` and a 4-char `passwordHint` (last 4 chars, `mb_substr`). On
  update, a null `password` keeps the stored secret; a string replaces it.

## Master-secret rename

`AI_KEY_SECRET` → `INSTANCE_SECRET_KEY`, app-wide, in one PR. It is no longer
AI-specific: it seals AI keys, the proxy password, and now the mail password.

- Hard rename (no legacy fallback). Every reference moves in the same PR:
  `ApiKeyCipher`, `ProxyPasswordCipher` (autowire + message + docblock),
  `SealedProxyPassword` / `AiKeyUnreadableApiException` docblocks, `backend/.env`,
  `docker-compose.prod.yml`, `.env.prod.example` if present, the Strato deploy
  (`deploy/strato/.env.local.example`, `deploy/strato/README.md`,
  `deploy/strato/activate-release.sh`), and the installer
  (`scripts/install.sh`, `scripts/lib.sh`). The historical plan/spec docs under
  `docs/superpowers/` are records and are left as written.
- **Deploy consequence:** the Strato server's hand-maintained
  `shared/.env.local` must be edited to rename the key in lockstep with this
  deploy, or the AI-keys, proxy, and mail features all fail closed. This is
  called out in the PR description as a required manual step.

## Guard change

`InsecureProductionConfigGuard` relaxes: an empty or `null` mail transport is now
a **valid running state**, so a fresh, unconfigured install boots and the admin
can reach the UI. The guard drops its `MAILER_DSN`/`MailCapability` mail branch
entirely — silent mail loss is no longer possible, because a transport that is
not really configured resolves to "mail disabled" (gated no-op that logs), not to
`null://null` reporting success. The guard keeps rejecting the committed ALTCHA
placeholder.

## Test-send

`POST /api/admin/mail/test` tests the **saved** DB config (independent of the
`enabled` toggle, mirroring the proxy tester's `configured*()` accessor). It:

- builds the `EsmtpTransport` from the saved row and sends **synchronously**,
  bypassing `DeferredMailer`, so the admin gets the real result in the response;
- sends a fixed test message from the resolved identity to the **acting admin's
  own account email** (zero input, so the instance cannot be driven to send test
  mail to arbitrary addresses);
- returns `{ok, reason}` with the transport error humanised, catching
  `MailPasswordUnreadableException` explicitly (like the proxy tester) and
  `not_configured` when no DB row / no host is saved.

## Frontend

A new `frontend/src/app/settings/admin/mail/` section, structural twin of
`proxy/`, behind `adminGuard`, route `admin/mail`, its own `MailSettingsService`.
SMTP-only. The Test button gates on `configured && !dirty` (save-before-test).
Env-prefill: when no row exists, the GET payload carries the env values as form
defaults for the non-secret fields (host, port, username, encryption,
from-address, from-name, derived enabled). The password is **never** prefilled
from the env — the admin re-enters it once when adopting the env config — so no
env secret crosses the wire and a blank-password Save never creates a
half-configured secret. A `sendmail://` / `null://` fallback (Strato, Docker)
leaves the SMTP fields blank; an `smtp(s)://` fallback (dev Mailpit, an SMTP
relay) pre-fills them.

## Reset to environment (addendum, 2026-09-04)

An admin who has saved a DB config can revert the instance to the `.env`
configuration from the admin panel, without an `.env` edit.

- **Semantics:** reset **deletes the saved singleton row**. Resolution is
  "DB-when-present, else env fallback", so with the row gone the instance sends
  through `MAILER_FALLBACK_DSN` + `MAIL_FROM(_NAME)` again — the env DSN carries
  its own credentials, so this is a working transport, not a half-configured one.
  Reset is deliberately not "load the env values into the form": the password
  never crosses the wire, so re-saving env values would persist a passwordless
  SMTP row — the exact half-configured-secret state the form already guards
  against.
- **API:** `POST /api/admin/mail/reset` (ROLE_ADMIN via the `^/api/admin/`
  rule). It calls `MailSettings::resetToEnvironment()` and returns the same
  payload as `GET`, now env-seeded.
- **Payload additions:** the mail JSON gains `hasSavedConfig` (a DB row exists)
  and `envFallbackConfigured` (the env fallback is a real transport). These are
  additive to the existing shape.
- **Frontend:** a "Reset to environment" button in the mail section, shown only
  when `hasSavedConfig && envFallbackConfigured` — so it never appears as a
  disguised "disable mail" control on an install whose env has no real transport
  (a fresh Strato/Docker `null://` fallback). It confirms before firing (it
  discards the saved override), then reloads and toasts.

## Files

New (backend):
- `Entity/MailServerSettings`, `Repository/MailServerSettingsRepository`
- `Enum/MailEncryption`
- `Service/Mail/Settings/MailSettings`, `MailConnection`, `ResolvedMailTransport`,
  `MailIdentity`, `MailFallback` (+ `MailFallbackContext`)
- `Service/Mail/Settings/Crypto/MailPasswordCipher`, `SealedMailPassword`,
  `Exception/MailPasswordUnreadableException`
- `Service/Mail/Transport/DynamicMailTransportFactory`, `DynamicMailTransport`
- `Service/Mail/Settings/MailConnectionTester`, `MailTestResult`
- `Http/Admin/MailSettingsJson`
- `Dto/Admin/MailSettingsRequest`
- `Controller/Admin/AdminMailController`
- `migrations/VersionYYYYMMDDHHMMSS.php` (create `mail_server_settings`)

Modified (backend):
- `config/packages/mailer.yaml` (`dynamic://default`)
- `Service/Mail/MailCapability` (DB-aware via `MailSettings`)
- `Service/Mail/AccountMailer` (identity from `MailSettings`, not env autowire)
- `EventListener/InsecureProductionConfigGuard` (drop mail branch)
- `Service/Ai/Crypto/ApiKeyCipher`, `Service/Proxy/Crypto/ProxyPasswordCipher`
  and the two docblock files (env rename)
- env / compose / deploy / installer files (env renames)

New (frontend):
- `settings/admin/mail/mail-settings.service.ts` (+ spec)
- `settings/admin/mail/mail-section.component.ts` / `.html` / `.scss` (+ spec)
- Transloco keys under `settings.mail.*`

Modified (frontend):
- `settings/settings.routes.ts` (add `admin/mail`)
- the admin settings navigation/section list (add the Mail entry)
