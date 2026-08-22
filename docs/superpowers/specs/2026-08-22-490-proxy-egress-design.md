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
- Master secret env var: **reuse `AI_KEY_SECRET`** (owner-approved), the same
  env the AI cipher reads (`#[Autowire('%env(AI_KEY_SECRET)%')]`), so no new
  deploy secret is introduced. The distinct binding string
  (`proxy-password|v%d|instance`) keeps the two secrets cryptographically
  separate even under a shared master key.

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
service, return a response. `src/Service/Proxy/ProxySettings.php` owns the
load-or-create + sealing (mirroring `InstanceSettings`) and exposes:
`view(): array` (the JSON view, no secret), `update(ProxySettingsRequest)`,
`configuredProxy(): ?ProxyConfig` (config when a host is stored, regardless of
`enabled` — used by the tester), and `egressProxy(): ?ProxyConfig`
(`enabled ? configuredProxy() : null` — used by `ProxyEgressResolver`). The
password is opened only inside the two `*Proxy()` methods.

### 4. Test connection probe

`src/Service/Proxy/ProxyConnectionTester.php`:

- Reads the **saved** config via `ProxySettings::configuredProxy(): ?ProxyConfig`
  (opens the password) and builds the DSN through the shared `ProxyConfig::dsn()`
  — one DSN builder for the tester and both fetch paths.
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

### 5. Fetch integration — two egress paths

The owner extended the scope: the proxy must cover **both** the feed pull **and
the reader's full-page article fetch**. Those are two independent HTTP builders
in the codebase, so the proxy branch lands in both. Egress is global, so one
`ProxyConfig` is resolved once at the top of each flow and passed down; the
low-level fetchers stay database-free.

Shared pieces:

- `src/Enum/ProxyType.php` — `enum ProxyType: string { case Socks5 = 'SOCKS5';
  case Http = 'HTTP'; }` (matches the `src/Enum` convention).
- `src/Service/Fetch/ProxyConfig.php` — `final readonly` value object holding the
  resolved egress: `ProxyType $type`, `host`, `port`, `?username`, `?password`.
  `dsn(): string` builds the curl proxy URL (`socks5h://…` for SOCKS5 — remote
  DNS — or `http://…`), URL-encoding any credentials. This is the only place the
  opened password lives at fetch time.
- `src/Service/Fetch/ProxyEgressResolver.php` — `resolve(): ?ProxyConfig`. Returns
  the instance `ProxyConfig` when the singleton is `enabled` **and** configured;
  else `null`. Global — takes no `Feed` argument, so it resolves once per flow.
  It wraps `ProxySettings::egressProxy()` (which opens the sealed password). It is
  injected only into the two higher-level flow owners below, never into the
  low-level `ConcurrentFeedFetcher` / `FailoverRequestSender`.

**Path A — feed pull (`ConcurrentFeedFetcher`):**

- `FetchTicket` gains an optional immutable `?ProxyConfig $proxy`. `RefreshRunner`
  calls `ProxyEgressResolver::resolve()` once and passes the result into
  `BudgetedFeedQueue`, which attaches it to every ticket it yields. Every other
  `new FetchTicket(...)` site (discovery, preview, favicon, single-fetch) keeps
  the default `null` — those flows stay direct (see "Out of scope" below). The
  value is read in `send()`, not threaded through a long chain — not tramp data.
- `FetchAttempt` gains `effectiveProxy(): ?ProxyConfig` (the ticket's proxy unless
  stripped), `isProxied(): bool`, and `withoutProxy(): self` (sets a
  `proxyStripped` flag; the direct-fallback attempt).
- `ConcurrentFeedFetcher::send()` splits on `$attempt->effectiveProxy()`:
  - **direct path** — unchanged: `UrlGuard::assertSafe` + IP pin (`resolve`) +
    cross-family extra.
  - **proxied path** — still calls `UrlGuard::assertSafe` (the host guard is kept
    on both paths — see Security notes) but issues the request with
    `proxy => $config->dsn()` and **without** the `resolve` pin or the
    cross-family extra (the IP pin is impossible through `socks5h`; only the pin
    is dropped, not the guard). The size cap (`on_progress`), timeout, and
    `max_redirects: 0` stay identical.

**Path B — reader full-page fetch (`FailoverRequestSender` / `HtmlPageFetcher`):**

- `FailoverRequestSender::send()` gains a trailing `?ProxyConfig $proxy = null`.
  When non-null it first issues one **proxied** request (`proxy => dsn`, no
  `resolve`) and forces the status line (`getStatusCode()`); on a warranted
  `TransportExceptionInterface` it cancels and **falls through to the existing
  pinned-address family loop** — which is the direct fallback, guard+pin intact.
  A proxied response that returns a status (even 4xx/5xx) stands, exactly as the
  final-family answer stands today. When `$proxy` is `null`, behaviour is
  byte-for-byte the current loop, so `CatalogFaviconFetcher` (the other caller,
  out of scope) is untouched.
- `HtmlPageFetcher` gains a `ProxyEgressResolver` dependency, resolves once at the
  top of `fetch()`, and threads the `?ProxyConfig` into each `request()` → `send()`
  call across the redirect loop.

### 6. Direct fallback when the proxy fetch fails

- **Path A:** on **any** failure of a proxied attempt — a `send()` throw caught in
  `fill()`, or a stream/status failure caught in `awaitNext()` — the fetcher
  requeues `$attempt->withoutProxy()` **once** instead of failing or trying the
  cross-family retry. That direct-fallback attempt then runs the full guard + IP
  pin and its own cross-family failover underneath. A private `fallbackFor(attempt)`
  helper (proxied-and-not-stripped → `withoutProxy()`, else `null`) centralises the
  "fire once" decision at both catch sites; cross-family applies only to attempts
  that are not proxied.
- **Path B:** the fallback is the fall-through inside `FailoverRequestSender::send()`
  described above — the proxied attempt is "attempt 0", and a warranted transport
  failure drops to the pinned direct families.
- Either way the fallback fires **once** and only for proxied attempts. A direct
  attempt that fails is terminal, exactly as today. No proxy → direct → proxy loop.

### 6a. Out of scope (outbound sites that stay direct)

The proxy covers the two flows the owner named: the refresh feed pull and the
reader article fetch. These other outbound sites keep the default `null` and are
**not** proxied in this change (noted so it is a conscious boundary, not an
oversight): feed **discovery** scrape-fallback, feed **preview**, **favicon**
resolution and catalog favicon bytes, and the catalog **rot-check** command. They
can be folded in later behind the same resolver if wanted.

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

- **Unit (backend):** `ProxyConfig::dsn()` build (SOCKS5 → `socks5h://`, HTTP →
  `http://`, with/without credentials, credential URL-encoding); `ProxyType` enum;
  `ProxyEgressResolver` (disabled → null, enabled-but-unconfigured → null,
  enabled+configured → config); `ProxyConnectionTester` result mapping
  (ok+egressIp, connect fail, auth reject, timeout, not-configured);
  `FetchAttempt` (`effectiveProxy`/`isProxied`/`withoutProxy` + `proxyStripped`);
  `ConcurrentFeedFetcher::send()` branch (Path A) — proxied omits `resolve` +
  guard, direct keeps both; proxied failure requeues exactly one direct attempt
  and that fallback re-runs guard + pin; direct failure stays terminal; fallback
  fires at most once (assert via a `MockHttpClient` that a proxied failure yields
  one direct request with `resolve` set and no `proxy`);
  `FailoverRequestSender::send()` branch (Path B) — with a proxy it issues one
  `proxy`/no-`resolve` request first, a warranted transport failure falls through
  to the pinned direct loop, and a null proxy is byte-for-byte the current
  behaviour; `HtmlPageFetcher` threads the resolved config into `send()`.
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

- The SSRF guard + IP pin are bypassed only on the **proxied** path, on both
  fetch flows (accepted trade-off: a `socks5h` egress cannot be IP-pinned). The
  `UrlGuard::assertSafe` host check still runs before every request on both paths
  (feed and reader) — only the address **pin** is dropped when proxied. The direct
  path and the direct fallback keep the full guard + pin.
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
