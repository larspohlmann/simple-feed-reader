# Optional SOCKS5/HTTP proxy egress for feed fetching — design (#490)

Status: approved for spec review
Branch: `feature/490-proxy-egress`
Milestone: v0.7.0

## Summary

Add an optional outbound SOCKS5/HTTP proxy for feed fetching so feeds can be
fetched through a VPN egress (for example a Private Internet Access SOCKS5
proxy). The proxy is configured once, instance-wide, in the admin area. A single
global **enable/disable** switch decides whether feeds are fetched through it. A
**Test connection** button probes the saved config through the proxy and reports
the observed egress IP.

## Scope decision (supersedes the issue text)

The issue proposed a **per-subscription** "Fetch via proxy" toggle. During
brainstorming the owner chose a **hard global master switch with no
per-subscription toggle**. This is the governing decision:

- **In scope:** the admin config surface, the global enable/disable switch, the
  Test-connection button, and the fetch integration with a direct fallback.
- **Dropped:** the per-subscription toggle, the `subscription.use_proxy` column,
  and any subscription-UI change.

**Resolution rule (final):** a feed is fetched through the proxy when the proxy is
**enabled** and **configured**; otherwise it is fetched directly, exactly as
today. There is no per-feed decision — when the switch is on, every feed fetch
goes through the proxy.

### Why a single instance-wide setting

Feeds are fetched once per shared `Feed`, not per subscription
(`Service/Refresh/BudgetedFeedQueue.php` yields one ticket per feed id; the ETag /
Last-Modified live on the shared `Feed` row). The egress is therefore inherently
shared, so one global switch is the correct model and removes any "whose proxy
wins" ambiguity.

## Backend design

### 1. `ProxyServerSettings` singleton entity

New entity `src/Entity/ProxyServerSettings.php`, table `proxy_server_settings`,
following the `InstanceSetting` singleton shape (load-or-create, absence of the
row means "not configured, disabled").

Columns:

| Column | Type | Notes |
|---|---|---|
| `id` | int, auto-increment | one row only; repository reads first by id asc |
| `enabled` | bool, default `0` | the master switch |
| `type` | string enum `SOCKS5` \| `HTTP` | egress protocol |
| `host` | string | proxy host |
| `port` | int | proxy port |
| `username` | string, nullable | proxy auth user (PIA needs a generated proxy user) |
| `password_ciphertext` | VARCHAR(1024) | sealed, base64 |
| `password_nonce` | VARCHAR(64) | sealed, base64 |
| `password_salt` | VARCHAR(64) | sealed, base64 |
| `password_hint` | VARCHAR(8) | last 4 chars, cleartext, on purpose |
| `key_version` | int, default `1` | seal key version |

The sealed password reuses the `AiProviderSettings` shape exactly: four flat
columns plus a version, flattened in a single `replaceConnection()`-style
re-seal path and reconstituted into a `SealedApiKey` value object on read.

The entity holds a single `apply(...)` mutator (no setters) and stays
`final`. A `ProxyType` PHP enum (`SOCKS5`, `HTTP`) models `type`.

### 2. Sealing the password without a user id

The existing `ApiKeyCipher::binding()` embeds the owning user id
(`"ai-api-key|v%d|user:%d"`) so a sealed row cannot be moved between accounts.
`ProxyServerSettings` is a singleton with no user.

Add a dedicated cipher for this secret rather than overloading the AI one, so the
two secrets never share a binding namespace:

- `src/Service/Proxy/Crypto/ProxyPasswordCipher.php` — same XChaCha20-Poly1305
  AEAD via libsodium, master secret from an env var, a fixed binding string
  (for example `"proxy-password|v%d|instance"`), `seal(string): SealedProxyPassword`
  / `open(SealedProxyPassword): string`.
- Reuse the `SealedApiKey` value object shape under a proxy-local name
  (`SealedProxyPassword`) to keep the boundary types separate from the AI area.
- Master secret env var: reuse `AI_KEY_SECRET` **or** introduce `APP_SECRET_KEY`.
  Decision for the plan: **reuse the existing key-material env** the AI cipher
  reads, to avoid a new deploy secret; the distinct binding string already keeps
  the two secrets cryptographically separate. The plan confirms the exact env var
  name against `config/services.yaml` before wiring.

### 3. Admin endpoints — `AdminProxyController`

New `src/Controller/Admin/AdminProxyController.php`, `final readonly`, thin.
Route prefix `/api/admin/proxy`. Admin-guarding is automatic: `security.yaml`
already gates `^/api/admin/` to `ROLE_ADMIN` under the stateless JWT firewall. No
per-action attribute.

- `GET /api/admin/proxy` → JSON view built by `src/Http/Admin/ProxySettingsJson.php`
  (hand-built static factory, never auto-serialized). Returns
  `{ enabled, type, host, port, username, hasPassword, passwordHint }`. The secret
  is **absent by construction**; only the 4-char hint and a `hasPassword` boolean
  are exposed.
- `PUT /api/admin/proxy` → full-replace via `#[MapRequestPayload]`
  `src/Dto/Admin/ProxySettingsRequest.php` (`final readonly`, `#[Assert\...]`,
  constructor defaults). The plaintext `password` is inbound-only, sealed
  immediately, never re-serialized. **Blank password keeps the stored one**;
  a present password replaces it. Re-returns the GET JSON view.
- `POST /api/admin/proxy/test` → the Test-connection probe (below).

Controllers stay thin (`ThinControllerRule`): read request, delegate to a
service, return a response. Load-or-create, sealing, and the JSON view live in
`src/Service/Proxy/ProxySettings.php` and the `Http` mapper.

### 4. Test connection probe

`src/Service/Proxy/ProxyConnectionTester.php`:

- Reads the **saved** sealed config (opens the password), builds the DSN
  (`socks5h://user:pass@host:port` for SOCKS5 — remote DNS — or
  `http://user:pass@host:port` for HTTP).
- Fetches a fixed neutral IP-echo endpoint through the proxy with a short timeout
  (10s), a small response cap, `max_redirects: 0`, `Accept-Encoding: identity`.
- The echo endpoint is a **class constant**, not a config field:
  `public const EGRESS_ECHO_URL = 'https://api.ipify.org';` (owner decision). One
  place to change it; no user-facing field.
- Returns a small result value object: `{ ok: true, egressIp }` or
  `{ ok: false, reason }`. Maps a connect failure, an auth rejection, and a
  timeout to distinct, translatable reasons.
- Requires a saved config; if none is stored it returns a "not configured"
  reason. It does **not** require `enabled` to be on — the admin tests before
  flipping the switch on.

`POST /api/admin/proxy/test` returns this result as JSON (`{ ok, egressIp }` or
`{ ok:false, reason }`). Admin-only, fixed target URL, so it stays within the
SSRF boundary spirit even though it deliberately egresses through the proxy.

### 5. Fetch integration

- `src/Service/Fetch/ProxyConfig.php` — `final readonly` value object holding the
  resolved egress: `type`, `host`, `port`, `username`, `password`. One method
  builds the curl DSN (`socks5h://…` or `http://…`). This is the only place the
  opened password lives at fetch time.
- `src/Service/Fetch/FeedProxyResolver.php` — `resolve(): ?ProxyConfig`. Returns
  the instance `ProxyConfig` when the singleton is `enabled` and configured; else
  `null`. **No per-feed / per-subscription query** — the switch is global, so the
  resolver takes no `Feed` argument and can resolve once per refresh pass.
- `FetchTicket` gains an optional immutable `?ProxyConfig`. `BudgetedFeedQueue`
  (or the ticket builder it feeds) attaches the resolved config when building
  tickets. The value is read in `send()`, not threaded through a long chain — not
  tramp data.
- `ConcurrentFeedFetcher::send()` splits into two paths:
  - **direct path** — unchanged: `UrlGuard::assertSafe` + IP pin (`resolve`).
  - **proxied path** — adds `proxy => <dsn>`; **omits the IP pin** (pinning is
    impossible through `socks5h`; DNS resolves at the proxy). The size cap
    (`on_progress`) and the redirect cap / manual redirect loop stay on **both**
    paths.

### 6. Direct fallback when the proxy fetch fails

- A failed **proxied** attempt is retried **once directly** (proxy stripped)
  before the outcome is reported as failed, reusing the existing
  requeue-on-failure mechanism (`ConcurrentFeedFetcher::overNextFamily` already
  requeues a failed attempt). The fallback requeues the attempt with its
  `ProxyConfig` removed.
- The direct fallback runs the **full guard + IP pin** (it is a direct ticket
  now), so it re-secures the request; its own cross-family failover still applies
  underneath.
- The fallback fires **once** and only for proxied attempts. A direct attempt that
  fails is terminal, exactly as today. No proxy → direct → proxy loop.

### 7. Migration

Platform-aware, hand-written DDL branching on `AbstractMySQLPlatform` vs
`SQLitePlatform`, throwing on anything else; additive-only; `skipIf(hasTable)`.
Create `proxy_server_settings` with the columns above. No `subscription` change.
`isTransactional(): false` following the sealed-secret table precedent
(`Version20260806120000.php`). A dedicated CI leg migrates from empty on both
SQLite and MySQL, then runs `doctrine:schema:validate`.

## Frontend design (new #541 grouped design language)

The `settings/ai` page is the only surface on the #541 grouped language; it and
`recommendation-settings-card` are the templates. Feature sections **compose**
the shared primitives and never restyle them.

New files:

- `frontend/src/app/settings/proxy-section.component.{ts,html,scss,spec.ts}`
- `frontend/src/app/settings/proxy-settings.service.ts` (mirrors
  `RecommendationSettingsService`: instant-save + dirty-tracked-draft split, a
  one-shot `saved` signal the component toasts off real HTTP success).

Wiring (two files, shell untouched — the documented #180 extensibility criterion):

- `frontend/src/app/settings/settings.routes.ts` — add a lazy `proxy` route
  behind `adminGuard`.
- `frontend/src/app/settings/settings-sections.ts` — add
  `{ path: 'proxy', icon: 'vpn_lock', labelKey: 'settings.proxy.title', group: 'admin' }`.

Composition, one `app-settings-group`:

- **Enable** row → `app-settings-row` + `app-toggle`. **Instant save + toast**
  (design-language rule: a toggle saves on change). The toggle is **disabled until
  a config has been saved** — enabling an unconfigured proxy is meaningless.
- **Type** → `app-settings-row` + a select. Instant save (design-language rule: a
  select saves on change).
- **Host / Port / Username / Password** → `app-settings-row` typed fields,
  **dirty-tracked behind `app-settings-save-bar`** (typed fields never save
  instantly). Password is write-only: a blank `type="password"` field with a
  "•••• stored" hint from `passwordHint`; typing a value replaces the stored
  secret; leaving it blank keeps it. Never round-trip the secret.
- **Test connection** → `app-button [loading]`, driving an add-feed-style
  discriminated-union `probeState = signal<'idle' | 'loading' | { ok; egressIp } |
  { error }>`. On ok, show the egress IP; on failure, an `app-error-banner` with
  the mapped reason. **Disabled while there are unsaved changes** (it tests the
  saved config) and while no config is stored.
- **Info-tips** (`app-info-tip`, `rowTitleTip`) note: PIA needs a separately
  generated proxy username/password (not the account login); SOCKS5 uses remote
  DNS so geo-content resolves at the proxy.

States follow the area conventions: `app-spinner` for load, `app-error-banner`
for errors (`problem.detail || problem.title`), `ToastService` for success fired
on real HTTP success, `[disabled]` gating during writes.

New i18n keys under `settings.proxy.*` in the transloco catalogs.

## Native-iOS readiness

Config is plain JSON settings; secret is write-only; `application/problem+json`
on error; no browser-only coupling; no CSRF token. The Test endpoint is a plain
`POST` returning JSON. Passes the §6 checklist.

## Testing

- **Unit (backend):** `ProxyConfig` DSN build (SOCKS5 → `socks5h://`, HTTP →
  `http://`, with/without credentials, credential URL-encoding);
  `FeedProxyResolver` (disabled → null, enabled-but-unconfigured → null,
  enabled+configured → config); `ProxyConnectionTester` result mapping
  (ok+egressIp, connect fail, auth reject, timeout, not-configured);
  `ConcurrentFeedFetcher::send()` branch — proxied omits `resolve` + guard, direct
  keeps both; proxied failure requeues exactly one direct attempt and that
  fallback re-runs guard + pin; direct failure stays terminal; fallback fires at
  most once.
- **Functional:** admin proxy settings round-trip — `PUT` then `GET`, password
  write-only, `hasPassword`/`passwordHint` reflected, blank password keeps the
  stored one, admin-guarded (401/403 without `ROLE_ADMIN`); `POST /test` shape.
- **Migration leg:** new `proxy_server_settings` table on both SQLite and MySQL,
  then `doctrine:schema:validate`.
- **Mutation:** cover the `send()` branch and the resolver decision
  (`composer infection:diff`).
- **Frontend unit (Jest):** `proxy-settings.service` instant-save vs dirty-draft
  split; component renders the group, toggle disabled until configured, Test
  button disabled while dirty, probe success shows egress IP, probe failure shows
  the banner.
- **Quality gates on every touched file:** `composer check` (cs + stan + tramp),
  `composer md` PHPMD-clean, PhpStorm inspections ERROR/WARNING-free on changed
  PHP, `npm run check` (ESLint + Prettier + Stylelint — no hex/raw px outside
  `theme/` — + Jest). Component styles in a sibling `.scss` (`styleUrl`).

## Security notes

- The SSRF guard + IP pin are bypassed only on the **proxied** path (accepted
  trade-off: a `socks5h` egress cannot be IP-pinned). The direct path and the
  direct fallback keep the full guard + pin.
- The proxy password is encrypted at rest and never returned by the API.
- The Test endpoint egresses through the proxy to a **fixed** class-constant URL,
  admin-only.

## Validation / risks

- curl-on-Strato SOCKS5 support is already confirmed (issue #490 comment: ext-curl
  8.19.0, `CURLPROXY_SOCKS5_HOSTNAME` defined, `socks5h` routes). Build risk
  retired.
- Conditional-GET validators (ETag / Last-Modified) live on the shared `Feed`;
  switching the whole instance between direct and proxied egress can change the
  body/validator. Low risk; the next fetch reconciles.
