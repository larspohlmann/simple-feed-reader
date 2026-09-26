# Architecture: client contract and native-client readiness

This document records the **cross-cutting constraints that govern how any client
talks to the backend** — the Angular SPA today, and a **native Swift iOS app**
that is planned but not yet built. It is not a component-by-component tour of the
system; for the OAuth flow see [oauth-sign-in.md](oauth-sign-in.md), for the
local stack see [local-docker.md](local-docker.md), and for the whole design see
the [design spec](superpowers/specs/2026-07-21-simple-feed-reader-design.md).

Its job is to make one decision durable: **keep a native iOS client viable**, and
say precisely what that costs and what it forbids.

**The SPA today, in brief.** The client that exists now lives in `frontend/` (see
[../frontend/README.md](../frontend/README.md)): Angular 20 with standalone
components and signals, bespoke SCSS over Angular CDK, and CSS-custom-property
theming (Graphite, in light/dark/system). It honours the contract below — its
transport is the bearer JWT, attached by a functional HTTP interceptor and
cleared on `401` — and its **sole credentialed call** is the OAuth code→token
exchange (§4.1). Every other request is a plain bearer call, which is exactly what
keeps the native-client door open.

---

## 1. The standing constraint

> **Keep the option of a native Swift iOS app open in all future development.**

Concretely: when a new feature or endpoint is designed, prefer patterns a client
with **no browser** can use — JSON in and out, bearer-token auth, stateless
requests. Anything that only works in a browser is flagged at design time and
given a native-friendly alternative before it lands.

This is a guardrail, not a mandate to build the app now. The point is that the
door stays open **by default**, so that adding the iOS client later is additive
work — new endpoints — and never a retrofit of decisions baked in early.

## 2. Why this is an architecture concern and not a later detail

The decisions that decide native viability are the **auth and transport**
decisions, and those are the expensive ones to reverse. If the access token had
been delivered as an httpOnly cookie the SPA never sees, a native app could not
hold it without reimplementing a cookie jar the server controls — and by the time
that is discovered, every endpoint, every test, and the SPA all assume it. The
same is true of server-side sessions, CSRF tokens, and HTML responses.

So the check has to happen when an endpoint is designed, not when the iOS project
starts. Section 6 is that check as a short list.

## 3. What is already native-ready — the invariants to preserve

An audit of the current backend (2026-07-22) found the foundation is native-ready.
These are **load-bearing invariants: do not regress them.**

| Invariant | Where | Why it matters for native |
|---|---|---|
| Access token is returned in the **JSON body** and read back from the **`Authorization: Bearer`** header — never an httpOnly auth cookie | `json_login` in `config/packages/security.yaml` uses Lexik's default success handler; no `set_cookies` / `token_extractors` override in `config/packages/lexik_jwt_authentication.yaml` | A native app posts credentials and attaches the bearer header. This is *the* decision that would otherwise close the door. |
| Firewalls are **`stateless: true`**; `framework.session` is enabled but nothing reads or writes it, so no `SESSION` cookie is issued in practice | `security.yaml` firewalls; `OAuthStateStore` stores flow state in a filesystem cache pool, not the session | No hidden server-side session state a client must carry. |
| **No CSRF token** is required on the JSON API | `framework.csrf_protection` is not set; `json_login` leaves `enable_csrf` off | Nothing expects a browser-supplied CSRF token. |
| **ALTCHA is algorithmic** sha256 proof-of-work over JSON — no browser widget is required server-side | `App\Service\Auth\AltchaService`; required only on `POST /api/auth/register` and `POST /api/auth/password-reset-request` | A native client computes the proof with CryptoKit; the widget is a web convenience, not a protocol requirement. |
| Errors are **`application/problem+json` regardless of `Accept`**; no `text/html` fallback | `App\Http\Problem\ProblemCatalog` (one mapping for every error path), used by `App\EventListener\ApiExceptionListener`, `JwtFailureResponseListener` and `App\Security\LoginFailureHandler` | A native client parses one content type for every outcome. |
| **No `Origin` / `Referer` / `Sec-Fetch-*` gating** on the API | none present (only doc-comments) | Requests are not rejected for lacking browser-set headers. |

**The one-line rule for reviewers:** if a change moves the access token into a
cookie, adds a session dependency, adds a CSRF-token requirement, or makes an
endpoint answer `text/html`, it has closed the door — stop and reconsider.

## 4. What is web-coupled today — additive work before an iOS client

Two areas assume a browser. Neither is lock-in: the fix in each case is **new
endpoints or config alongside the existing ones**, not a rewrite. Email/password
bearer login already works for a native client with nothing added, so neither of
these is on the critical path for a first native release.

### 4.1 The OAuth handoff is browser/SPA-only

The Google/Apple flow (custom OIDC in `src/Service/OAuth/`, see
[oauth-sign-in.md](oauth-sign-in.md)) is bound to a browser in three ways:

- a **fixed web `redirect_uri`** built from `APP_BACKEND_URL`, one per provider;
- the flow `state` is **bound to a `__Host-oauth_flow` browser cookie**; and
- it finishes by **redirecting to `APP_FRONTEND_URL/auth/callback`** with a
  one-time code that the SPA exchanges as a **credentialed** cross-origin request.

A native `ASWebAuthenticationSession` returns to a custom scheme or universal
link, not to `APP_FRONTEND_URL`, and has no cookie jar the backend controls. So
the native OAuth path will need **its own endpoints** — an app-supplied redirect
plus PKCE-only binding, without the flow cookie.

The groundwork is already there: **PKCE (S256) is implemented and mandatory**
(`AbstractOidcProvider`), which is the hard cryptographic part of a native OAuth
flow. What is missing is only the browser-free binding and redirect, which is
additive.

### 4.2 Email links point at the web frontend

Verify-email, password-reset, and approval links are all `APP_FRONTEND_URL`-based
web URLs (`App\Service\Mail\AccountMailer`). The **tokens themselves are generic**;
only the URL base is web. Native reuse means universal links / associated domains,
or a configurable deep-link base — again additive, and it does not change how the
tokens are minted or verified.

## 5. Watch item at scale (not native-specific)

`register`, `password-reset-request`, and `oauth-start` rate limiters are keyed
**purely on client IP** (`config/packages/rate_limiter.yaml`), and
`framework.trusted_proxies` is unset, so `getClientIp()` returns `REMOTE_ADDR`.
Behind carrier-grade NAT, many mobile users collapse into one bucket and share a
single 5-per-15-minute (or 20-per-15-minute) allowance. This hurts web users
behind a corporate NAT too; native mobile populations just make it more visible.
The fix — account-keying where feasible, plus setting `trusted_proxies` behind a
known CDN/proxy — is independent of client type and can happen any time. Worth
revisiting before a mobile launch or a CDN-fronted deployment.

## 6. Design-time checklist for new endpoints

Run this against any new client-facing endpoint before it lands. A "no" is not a
veto — it is a prompt to add the native-friendly alternative now, while it is
cheap, or to consciously accept the coupling.

- [ ] **Auth by bearer token?** The endpoint authenticates via
      `Authorization: Bearer`, not a cookie or session.
- [ ] **Stateless?** It reads no server-side session and sets no cookie the client
      must carry.
- [ ] **JSON in, JSON out?** Request and success and error bodies are JSON
      (`application/problem+json` for errors); no `text/html` response and no HTML
      form assumption.
- [ ] **No browser-only inputs?** It does not depend on `Origin` / `Referer` /
      `Sec-Fetch-*`, a CSRF token, or a browser widget.
- [ ] **No redirect-to-web handoff?** It does not hand results back by redirecting
      to a fixed web URL that only the SPA can receive. If a redirect handoff is
      unavoidable (as with OAuth), keep the web version and note that a native
      variant is future additive work.
- [ ] **Any user-facing link is client-agnostic**, or its base URL is
      configurable, so it can resolve to a native deep link later.

If every box is checked, the endpoint serves a native client unchanged.

## 7. Where database access lives

Every query lives in `backend/src/Repository/`: DQL, the ORM and DBAL query builders, native SQL and DBAL statements
alike. Services orchestrate. They call repository methods and own the unit of work: `persist`, `remove`, `flush`,
`clear`, `getReference`, `wrapInTransaction`. Decided in #1170.

- **An entity's own repository** (`FeedRepository`, `EntryStateRepository`) holds the queries central to that entity.
- **A concern repository** holds a pipeline that serves one concern or spans entities. It is a flat `final readonly`
  class in `src/Repository/`, named for its concern (`RetentionRepository`, `ReadingHistoryRepository`,
  `RecommendationCallRepository`). It injects `EntityManagerInterface`, or the DBAL `Connection` for raw SQL. It
  returns entities, scalars or small row objects (`TitledEntry`); mapping those into domain values stays in the service.
- **`*Query` names are taken.** `EntryQuery`, `ForYouFeedQuery` and `SavedSearchListQuery` are request value objects.
  A class that runs queries is a `*Repository`, and there is no `Repository/Query/` subdirectory.
- **Composition, not inheritance.** Shared query construction is an injected collaborator (`EntryScopePredicates`,
  `DuplicateCollapseDql`), never an abstract base repository. `AbstractEntryProjectionRepository` is the last one
  left; #1169 replaces it with a collaborator.
- **No hidden side effects.** A write method does what its name says. `add()` persists: it neither prunes nor runs a
  whole-EntityManager `flush()` that would commit someone else's pending changes. The service that owns the unit of
  work flushes, as `MailDeliveryHealth::recordFailure()` does for the mail-failure log.
- **The owner comes first.** A user-scoped repository method takes the user, or its id, first; a lookup of owned
  rows by their ids is named `…ForUser` (`getOneForUser(int $userId, int $tagId)`, `findAllByIdsForUser(int $userId,
  array $tagIds)`). Where the reorder swaps two adjacent ints, the method is renamed too. The subject a method
  works on otherwise stays in front (`EntryListRowEnricher::enrich($rows, $userId)`). Decided in #1167.
- **`src/Doctrine/`** (DQL functions, SQL walkers, schema listeners, driver middleware) extends the ORM itself and may
  touch the connection.
- **Controllers** follow the same rule and go further: they hold no unit of work either. `ThinControllerRule` and
  `ControllerMutatesNoEntityRule` keep `persist`, `flush`, `remove` and entity mutation in services (#1157).

Enforced by `QueriesLiveInRepositoriesRule` (`backend/tests/PhpStan/`, run by `composer stan`). Outside `src/Repository`
and `src/Doctrine`, no class may call `createQuery`, `createQueryBuilder`, `createNativeQuery` or `getConnection`. It
also may not reference the DBAL `Connection`, an ORM or DBAL `QueryBuilder`, `Query` or `NativeQuery`.
