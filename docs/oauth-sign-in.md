# OAuth Sign-In (Google and Apple)

Two audiences: an **operator** setting up provider credentials, and a
**frontend developer** implementing the SPA side of the flow. Read the section
you need; they do not depend on each other.

Assumed knowledge: none of this code. Assumed access, for the operator part: a
Google Cloud project and an Apple Developer Program membership.

---

## 1. What the flow actually does

A visitor clicks "Sign in with Google". Five things then happen:

1. The SPA sends the browser to `GET /api/auth/oauth/google`. The backend
   stores a short-lived secret server-side and answers `302` to Google.
2. The visitor approves at Google.
3. Google sends the browser back to
   `<APP_BACKEND_URL>/api/auth/oauth/google/callback` with an authorization
   code. The backend trades that code for a verified identity, finds or creates
   the local account, and redirects the browser to
   `<APP_FRONTEND_URL>/auth/callback?code=…`.
4. The SPA reads that `code` and `POST`s it to `/api/auth/oauth/exchange`,
   **with credentials** (section 7.3 — this trips people up).
5. It gets back a normal JWT — the same token `POST /api/auth/login` issues.

**Why the extra hop in steps 3–4.** The `code` in the redirect is *not* the JWT
and is not the provider's authorization code. It is a one-time value that lives
30 seconds and dies on first use. A JWT in that redirect's query string would be
written into browser history, forwarded in `Referer` headers, and logged
verbatim by every proxy and access log on the way. The JWT never appears in a
URL anywhere in this system.

**Steps 1, 3 and 4 are tied to one browser.** A cookie set at step 1 is required
again at step 3 and at step 4. Neither the `state` nor the login code is a
bearer value that works from anywhere — see section 4.2 for the login CSRF that
buys, and section 7.3 for what the SPA must do about it.

---

## 2. Operator: setting up Google

In the [Google Cloud console](https://console.cloud.google.com/), under
**APIs & Services → Credentials**, create an **OAuth 2.0 Client ID** of type
**Web application**.

Register exactly one authorized redirect URI:

```
<APP_BACKEND_URL>/api/auth/oauth/google/callback
```

for example `https://api.example.com/api/auth/oauth/google/callback`.

Then fill in:

| Variable | Where it comes from |
|---|---|
| `GOOGLE_OAUTH_CLIENT_ID` | "Client ID" on the credential page, ends in `.apps.googleusercontent.com` |
| `GOOGLE_OAUTH_CLIENT_SECRET` | "Client secret" on the same page |

The requested scope is `openid email`, and nothing else. This application shows
an address and stores an address; a wider scope would only enlarge the consent
screen and the blast radius of a leaked token.

---

## 3. Operator: setting up Apple

Apple needs four values from three different places, and the most common
mistake is using the wrong identifier in the first one.

### 3.1 `APPLE_OAUTH_CLIENT_ID` — the **Services ID**, not the App ID

In the Apple Developer portal, under **Certificates, Identifiers & Profiles →
Identifiers**, create an identifier of type **Services IDs** (not **App IDs**).
Its identifier string — conventionally reverse-DNS, e.g.
`com.example.feedreader.web` — is what goes in `APPLE_OAUTH_CLIENT_ID`.

An App ID looks similar and will be accepted by nothing: Apple rejects the
authorization request with an opaque error. If sign-in fails immediately at
Apple's own screen, check this first.

Enable **Sign In with Apple** on the Services ID, click **Configure**, and
register:

- **Domains and Subdomains**: the host of `APP_BACKEND_URL`, e.g. `api.example.com`
- **Return URLs**: `<APP_BACKEND_URL>/api/auth/oauth/apple/callback`

Apple requires HTTPS here and rejects `localhost`. Local development against
the real Apple therefore needs a public tunnel; local development against the
*fake* provider the test suite uses needs nothing.

### 3.2 `APPLE_OAUTH_TEAM_ID`

The 10-character team identifier, shown top-right in the developer portal and
under **Membership details**. E.g. `A1B2C3D4E5`.

### 3.3 `APPLE_OAUTH_KEY_ID` and `APPLE_OAUTH_PRIVATE_KEY`

Under **Keys**, create a key, enable **Sign In with Apple** on it, and associate
it with the Services ID above.

- The 10-character **Key ID** goes in `APPLE_OAUTH_KEY_ID`.
- Downloading the key gives you a `.p8` file, **once** — Apple will not let you
  download it again. `APPLE_OAUTH_PRIVATE_KEY` is the **contents** of that file,
  not a path to it: the whole PEM block including the
  `-----BEGIN PRIVATE KEY-----` and `-----END PRIVATE KEY-----` lines and the
  newlines between them.

Multi-line values in a dotenv file are a portability trap. Prefer setting this
one in the real process environment (the hosting panel's environment editor, a
systemd unit, or the deploy's secret store) rather than in `.env.local`. If it
must go in a dotenv file, quote it and use `\n` escapes.

There is no "Apple client secret" to paste anywhere. Apple's client secret is an
ES256 JWT this application signs for itself from the four values above, valid
for a short window and regenerated as needed.

The requested scope is `email` only.

---

## 4. Operator: `APP_BACKEND_URL` must match byte for byte

```dotenv
APP_BACKEND_URL=https://api.example.com
```

This is the absolute base URL the backend is reachable at. The redirect URI sent
to the providers is built from it, and both Google and Apple compare that
redirect URI against the registered one as an exact string. A trailing slash, an
`http` where the registration says `https`, or `www.` on one side and not the
other is a failed sign-in with a provider-side error, not a helpful message from
this application.

It is deliberately **not** derived from the incoming request. Deriving it from
the `Host` header would let anyone who can set that header point the redirect —
and therefore the authorization code — somewhere else.

`APP_FRONTEND_URL` (already used by the mailer) is the other half: it is where
the callback sends the browser at step 3, and it is likewise a deployment-time
value that no request can influence. It is **also the CORS origin** — see
section 4.3, which matters more than it used to.

### 4.1 It must be `https`, and that is not merely advice

Starting a sign-in sets a cookie named `__Host-oauth_flow`. It binds the flow to
the browser that started it (section 4.2), and it is `Secure` — so on a
deployment served over plain HTTP the browser never sends it back and **every
sign-in fails with `invalid_state`**.

That is the intended failure. There is no setting to turn it off, because a
switch that downgrades the cookie is a switch an attacker benefits from.

Local development on `http://localhost:8000` is unaffected: browsers treat
`localhost` as a trustworthy origin and accept `Secure` — and `__Host-` —
cookies there. Verified in Chromium against the exact attributes the backend
sends; Firefox has behaved the same since version 75. Safari, however, does
not extend that leniency to `Secure` cookies on `http://localhost` — which is
exactly why the [local Docker stack](local-docker.md) terminates TLS.

### 4.2 Why there is a cookie at all: login CSRF

`state` proves a callback belongs to a flow *this server* started. On its own it
does **not** prove the callback belongs to the browser that started it, and
those are different properties.

Without the second one, this attack works. An attacker with a real account
scripts `GET /api/auth/oauth/google`, keeps the `state`, approves at the
provider, and captures the `code` from the provider's final redirect *without
following it* — so the state is never spent. They then get a victim to open the
callback URL. Since the SPA exchanges the code automatically on landing
(section 7.3), no click is required, and the victim's browser ends up signed in
**as the attacker**: every feed they add and every article they read lands in
the attacker's account.

The `__Host-oauth_flow` cookie closes it. The backend stores only a digest of
its value beside the flow and requires the matching cookie back at the callback;
a callback that cannot produce it is refused as `invalid_state`, indistinguish-
able from an unknown or expired state. The cookie carries nothing identifying,
is different for every flow, and is cleared when the flow ends.

**The same cookie is required again at the exchange, and that is the other
half.** Binding only the callback forces the attacker to complete the flow in
their own browser — it does not stop them walking away with the login code that
falls out of it. If the code were a pure bearer value, they would run a genuine
sign-in, *not* redeem the code, and inside its 30 seconds point a victim at
`<APP_FRONTEND_URL>/auth/callback?code=…`. The SPA exchanges it and the victim
holds the attacker's session: the same outcome as above, narrowed to a
30-second window and entirely scriptable.

So the login code is bound to the same browser as the flow that earned it. The
backend stores a digest of the flow cookie alongside the user id and requires
the matching cookie back at `POST /api/auth/oauth/exchange`. A missing or
mismatched cookie is a `400 invalid_token` — deliberately the *same* answer as
an unknown, spent or expired code, because a caller who could tell those apart
could confirm that a captured code was still live.

**This is why the SPA must send the exchange with `credentials: 'include'`**
(section 7.3). It is a hard requirement, not a hardening step, and forgetting it
produces the most confusing failure this design has.

The cookie therefore lives slightly longer than the flow's ten minutes — ten
minutes and thirty seconds, the state's life plus the code's — so that a
callback arriving in the final second of a state's life still hands the browser
a code it can actually exchange. It is cleared on every failed callback, and on
a successful exchange.

It is `SameSite=None`, which looks like a weakening and is the opposite. Apple
returns its callback as a **cross-site POST** (`response_mode=form_post`), and a
`Lax` cookie is not sent on a cross-site POST — so `Lax` would leave Google
working perfectly while every Apple sign-in failed. The cookie needs no help
from `SameSite`: it grants nothing by itself and is useless without the matching
unspent `state`.

**One sign-in at a time per browser.** There is one cookie, so starting a second
sign-in replaces the first flow's binding and the abandoned tab will fail with
`invalid_state`. Starting again works. The same applies for the ~30 seconds
between a callback and its exchange: starting a fresh sign-in in that gap
replaces the binding the pending code needs, and the pending exchange fails with
`invalid_token`. Starting again works.

### 4.3 `APP_FRONTEND_URL` is the CORS origin

The SPA's exchange call is a **credentialed cross-origin request** whenever the
frontend and the backend are served from different origins. The backend answers
it with:

```http
Access-Control-Allow-Origin: <APP_FRONTEND_URL's origin>
Access-Control-Allow-Credentials: true
```

The allowed origin is that one exact value — scheme, host and port, with any
path or trailing slash stripped. It is never `*`, and cannot be configured to
be: browsers reject `*` on a credentialed request outright, so a wildcard would
break every OAuth sign-in as well as being wrong. There is no allow-list and no
pattern syntax; if you need a second origin, that is a code change, on purpose.

Two consequences worth knowing:

- **Get `APP_FRONTEND_URL` wrong and the SPA's exchange fails as a browser CORS
  error**, not as an API error — so it will not show up in your problem+json
  handling. Check the browser console before checking the server log.
- **A same-origin deployment needs none of this and is unaffected.** If the SPA
  and the API share an origin the browser applies no CORS at all; the headers
  are emitted and ignored.

**Different port is a different origin but the same site.** Local development
runs the SPA on `http://localhost:4200` and the API on `http://localhost:8000`.
Those are different *origins*, so CORS applies and the configuration above is
required. They are the same *site*, because ports play no part in a site — so
the flow cookie is not a third-party cookie locally, and `SameSite` is not what
makes local development work. See section 4.4.

### 4.4 Third-party cookie restrictions: the honest position

The flow cookie is `SameSite=None`, which is the class of cookie browsers
restrict most aggressively. Whether that affects a given deployment depends
entirely on one question: **are the frontend and the backend on the same
site** — that is, the same registrable domain?

| Deployment | Exchange cookie is | Affected? |
|---|---|---|
| One origin (`example.com` serves both) | first-party | No. No CORS, no cross-site request. |
| `example.com` + `api.example.com` | first-party (same site) | No. |
| `localhost:4200` + `localhost:8000` | first-party (same site) | No. |
| `example.com` + `api.some-other-host.net` | **third-party** | **Yes.** |

Only the last row is at risk, and there it is a real risk, not a theoretical
one. Safari's ITP blocks third-party cookies by default today, and the exchange
XHR would silently carry no cookie — producing a `400` that looks exactly like a
bad code, for every Safari user, permanently. Chrome's third-party cookie
phase-out would do the same for Chrome.

Note what is *not* affected even there: the provider callback itself. That is a
top-level navigation to the backend's own origin — Apple's `form_post` is a
cross-site POST, but the destination is first-party to the cookie — so ITP does
not touch it. It is specifically the exchange XHR that would break.

**Recommendation: deploy the frontend and backend on the same site.** That is
the shape this application expects, it is the shape the default configuration
describes, and it makes the entire question moot. A cross-site split is
supportable only for as long as third-party cookies are, which is not a
foundation worth building on.

---

## 5. Operator: disabling a provider

**Leave the credentials blank.** That is the supported way to run with only one
provider, or with none.

```dotenv
GOOGLE_OAUTH_CLIENT_ID=''
GOOGLE_OAUTH_CLIENT_SECRET=''
```

An unconfigured provider is *invisible*, not broken:

- It is absent from `GET /api/auth/oauth/providers`, so the SPA does not render
  its button.
- `GET /api/auth/oauth/google` answers `404` problem+json — the same answer as a
  provider that does not exist at all. The distinction is real to you and
  meaningless to a visitor, and reporting it would tell an unauthenticated
  stranger which integrations this deployment holds credentials for.

Google needs **both** its values; Apple needs **all four** of its. Three out of
four is treated as unconfigured rather than half-working, because the
alternative is a button that sends people to Apple's consent screen and fails on
the way back.

---

## 6. Operator: what an OAuth account looks like in the admin queue

Two things in the approval queue look like anomalies and are not.

**No verification mail.** OAuth accounts are created in `pending_approval`, not
`pending_verification`. Double opt-in exists to prove the address belongs to the
person signing up, and the provider has already proved exactly that. There is no
verification mail to chase because none was ever sent.

**A `…@oauth.invalid` address.** Apple returns a user's address only on the
*first* authorization. Someone who revokes access and signs in again arrives
with a subject identifier and nothing else — so the account gets a synthetic
`<provider>-<hash>@oauth.invalid` identifier. `.invalid` is reserved by RFC 2606
and can never resolve, which is the point: it is visibly not a real address. The
`identities` column in `GET /api/admin/users` tells you which provider such an
account came from.

That address space cannot be registered through the signup form — the
registration DTO refuses the whole `.invalid` TLD — so a placeholder address can
only ever have been minted by this application.

---

## 7. Frontend: the SPA contract

```
GET  /api/auth/oauth/providers          → 200 { "providers": ["google", "apple"] }

GET  /api/auth/oauth/{provider}         → 302 to the provider's consent screen
                                        → 404 problem+json if unknown/unconfigured
                                        → 429 problem+json (20 starts / 15 min / IP)

     …the provider redirects the browser back to the BACKEND, which redirects to…

     <APP_FRONTEND_URL>/auth/callback?code=<one-time code>
     or
     <APP_FRONTEND_URL>/auth/callback?error=<code>

POST /api/auth/oauth/exchange  { "code": "…" }   ← MUST send credentials
                                        → 200 { "token": "<jwt>" }
                                        → 400 problem+json  invalid_token
                                        → 403 problem+json  account_not_active
                                        → 422 problem+json  validation_error
```

### 7.1 Rendering the buttons

Call `GET /api/auth/oauth/providers` and render a button per returned name.
Never hard-code the list: an instance with no Apple credentials must not show an
Apple button. The order returned is stable for a given build and is deliberately
not sorted, so the buttons do not move between deployments.

### 7.2 Starting a sign-in

Navigate the **browser** to `/api/auth/oauth/{provider}` — a full navigation,
not `fetch()`. The response is a `302` to a third-party consent screen; an XHR
cannot follow it usefully and CORS will not allow it.

### 7.3 Handling the callback route

Implement a route at `/auth/callback`. It receives exactly one of two query
parameters.

**`?code=…`** — the happy path. `POST` it to `/api/auth/oauth/exchange` as
`{"code": "…"}` and store the returned `token` exactly as you store the one from
`POST /api/auth/login`. From that point on there is nothing OAuth-specific about
the session.

#### The exchange must send credentials. Read this before you debug anything.

```js
const res = await fetch(`${API}/api/auth/oauth/exchange`, {
  method: 'POST',
  credentials: 'include',            // ← REQUIRED. Not optional. Not a nicety.
  headers: { 'Content-Type': 'application/json' },
  body: JSON.stringify({ code }),
});
```

`credentials: 'include'` is not a default in `fetch` for cross-origin requests,
and omitting it is the single most likely mistake to make on this endpoint.

The code alone is **not** enough to sign in. It is bound to the browser that
completed the sign-in, and the proof of that is the `__Host-oauth_flow` cookie
the backend set at step 1 (section 4.2). Without the cookie the backend cannot
tell your legitimate exchange from an attacker replaying a code they captured,
so it refuses.

**Here is the failure mode, and it is a nasty one.** If you forget
`credentials: 'include'`, you get:

```
400  { "type": "invalid_token", "title": "…" }
```

That is *byte for byte* the response an unknown, expired or already-used code
gets. It is identical on purpose — telling the two apart would let an attacker
confirm a captured code was live — but it means the error text will send you
looking for an expired code, a double-submit, a clock problem or a caching bug,
none of which are the problem. **A `400 invalid_token` on an exchange you are
certain just happened is almost always a missing `credentials: 'include'`.**
Check that first. Confirm it by looking for a `Cookie:` header on the exchange
request in the network tab — if it is absent, that is your bug.

Two related things that will produce the same 400:

- `APP_FRONTEND_URL` not matching the origin the SPA is actually served from,
  so the browser strips the credentials (section 4.3). This one usually also
  logs a CORS error to the console.
- A cross-site frontend/backend split with third-party cookies blocked, which
  affects Safari today (section 4.4). This one produces *no* console error,
  which makes it the worst of the three to diagnose.

#### When to exchange

The code expires 30 seconds after it was issued and is destroyed on first use.
So: do not retry a failed exchange with the same code, and expect a page reload
of `/auth/callback?code=…` to fail. Strip the query string from the URL once
exchanged.

**On whether to exchange automatically on landing — a change of advice.** This
document previously said to do it *immediately* on route activation. That is
still workable and is no longer a vulnerability, but the recommendation is now:

> Render a brief **"Continue as …"** confirmation and exchange on the click.

The reasoning, stated plainly so you can overrule it: the gesture-free
auto-POST is what made *both* halves of the login-CSRF attack in section 4.2
work end to end. An attacker only had to get a victim's browser to *load* a URL,
which is a link, an image tag or a redirect — no interaction at all. Both halves
are now closed by the browser binding, and the binding is what the security
rests on; the gesture is defence in depth, not the control.

But it is cheap defence in depth. It costs one click on a page the user reached
by deliberately signing in, and it converts any future hole in the binding from
"attacker sends a link" into "attacker must convince the victim to click a
button that says the wrong name" — which is exactly the moment a user notices
something is wrong, because the name on the button is not theirs.

The cost is real and you may weigh it differently: a click on a page that
appears mid-redirect feels like an interruption, and the 30-second code window
means a user who wanders off returns to a dead page and must start over. If you
auto-POST anyway, nothing here breaks — the binding still holds. Just know that
you are spending the defence in depth, not the defence.

**`?error=…`** — one of exactly four values:

| `error` | What happened | What to show |
|---|---|---|
| `access_denied` | The visitor declined at the provider's consent screen. | Nothing alarming. Return them to the login page. This is the most common non-success outcome and is not a fault. |
| `invalid_request` | The callback arrived without the parameters it needs. | "Sign-in could not be completed. Please try again." |
| `invalid_state` | The flow was not started by this server, was already completed, is older than 10 minutes, or was started by a **different browser** (section 4.2). | Same message. In practice: a bookmarked callback URL, a back-button replay, a consent screen left open too long, a second sign-in started in another tab, or a deployment serving plain HTTP (section 4.1). Offer to start again. |
| `exchange_failed` | The conversation with the provider did not produce a usable identity. | Same message. The specific cause is in the server log and deliberately not in the response. |

Treat any other value as `exchange_failed`. The list is closed today, but a
frontend that renders an unknown code verbatim is a frontend that renders
whatever ends up there tomorrow.

### 7.4 Handling the exchange response

**`200`** — `{ "token": "<jwt>" }`. Done.

**`400`**, `"type": "invalid_token"` — the code was unknown, already spent,
expired, **or arrived without the flow cookie that binds it to this browser**.
Indistinguishable from each other on purpose (section 4.2). Send the user back
to the login page to start over — but if you are seeing this during development
on a code you know is fresh, read the credentials warning in section 7.3 first.

**`403`**, `"type": "account_not_active"` — the sign-in worked and the account
may not log in yet. The problem payload carries an `accountStatus` extension
member alongside a human-readable `detail`, exactly as the password login's 403
does. This is the one error the user can act on:

- `pending_approval` — a brand new OAuth account, or one still in the queue.
  "Your account is waiting for an administrator to approve it." Expect this on
  **every first-time OAuth signup**; it is the normal path, not an error.
- `suspended` / `rejected` — an administrator's decision. Signing in with a
  second provider does not overrule it.

**`422`**, `"type": "validation_error"` — the request body was malformed (no
`code`, or an implausibly long one). A bug in the SPA, not something a user did.

### 7.5 Two behaviours worth knowing

**Linking is by provider-verified address.** Signing in with Google using an
address that already has a local account signs you into *that* account. An
address the provider has not verified never links — it is treated as a brand
new signup — because otherwise anyone who could set an arbitrary unverified
address at any provider could claim any account here.

**A user can hold several identities.** Google and Apple on one account is
supported and expected; the admin list shows all of them.

## 8. Developer: adding a third provider

The design spec claims a third provider is "one class and one env block". This
section is that claim walked rather than asserted — the steps below were
performed against this branch with a scratch provider, and the failure modes
described are ones that actually occurred.

It works for any provider that speaks standard OpenID Connect authorization
code + PKCE and returns an ID token from its token endpoint. A provider that
does not (a plain OAuth 2 provider with a userinfo endpoint and no ID token,
say) does not fit `AbstractOidcProvider` and needs its own implementation of
`OAuthProviderInterface`.

### 8.1 The class

One file in `src/Service/OAuth/`, extending `AbstractOidcProvider`. Six methods,
none of which contain a decision worth agonising over:

| Method | What goes in it |
| --- | --- |
| `getName()` | The URL segment and the `user_identity.provider` value. Lowercase, stable forever — it is stored in the database. |
| `isConfigured()` | `false` when the deployment has no credentials, so the provider 404s instead of redirecting to a broken consent screen. |
| `getAuthorizationEndpoint()` | A **constant**, never configuration. |
| `getScope()` | The narrowest scope that yields a verified email. |
| `getTokenEndpoint()` | A **constant**. See below — this one is load-bearing. |
| `getIssuers()` | Accepted `iss` values. A list, because Google mints two spellings. |
| `getClientId()` / `getClientSecret()` | Usually constructor-injected env vars. Apple overrides the secret to mint a fresh ES256 JWT per exchange. |

If the provider needs a parameter OIDC does not define, override
`extraAuthorizationParams()` — Apple's `response_mode=form_post` is the only
current instance. Everything else about the authorization request, PKCE
included, is assembled by the `final` `getAuthorizationUrl()` on the parent and
is not yours to change.

**`getTokenEndpoint()` and `getAuthorizationEndpoint()` must be constants, and
that is a security requirement, not a style preference.** This codebase does not
verify the ID token's signature; it relies on the OIDC §3.1.3.7 carve-out that
lets validated TLS to a *pinned* endpoint stand in for one. A token endpoint
that a deployment — or worse, a request — could move would withdraw the premise
the whole exchange rests on. `AbstractOidcProvider`'s class docblock spells out
all three conditions. Read it before you touch that method.

### 8.2 The registration you do *not* write

There is none. `config/services.yaml` carries an `_instanceof` block that tags
every `OAuthProviderInterface` with `app.oauth_provider`, and
`OAuthProviderRegistry` collects that tag via `#[AutowireIterator]`. Dropping
the file in is the whole registration: the new name appears in
`GET /api/auth/oauth/providers`, the SPA renders a button for it, and
`/api/auth/oauth/<name>` and `/api/auth/oauth/<name>/callback` both route.
Confirmed by booting the container with a scratch provider and reading the
registry back — `['google', 'scratch']`, with nothing else edited.

Do not "helpfully" replace that `_instanceof` block with
`#[AutowireIterator(OAuthProviderInterface::class)]`. `autoconfigure: true` does
not register a tag named after a plain application interface, so the iterator
comes back empty — and it fails *silently*: the registry builds, every lookup
throws `UnknownProviderException`, and the deployment is indistinguishable from
a correctly wired one that simply has no credentials.
`OAuthProviderWiringTest` exists to catch exactly this.

### 8.3 The env block

Add the variables to `backend/.env` with empty defaults, next to the Google and
Apple blocks, and document them the same way.

Empty defaults are what make the provider *optional*: `isConfigured()` returns
false, and a deployment that does not want it does nothing at all.

**Declaring them is not optional, even though nothing complains.** A
`#[Autowire('%env(NEW_OAUTH_CLIENT_ID)%')]` naming a variable that appears in no
`.env` file passes `cache:clear` **and** passes `lint:container` — both stay
green — and then throws `EnvNotFoundException: Environment variable not found`
the first time anything touches the registry. Observed on this branch, not
theorised. The blank line in `.env` is the fix.

### 8.4 The redirect URI

Register this at the provider's console, byte for byte:

```
<APP_BACKEND_URL>/api/auth/oauth/<name>/callback
```

`APP_BACKEND_URL` must be `https` and must match what is registered exactly —
section 4 covers why, and covers the failure modes when it does not.

### 8.5 The tests

Mirror `GoogleOAuthProviderTest`: parse the authorization URL and pin every
query parameter individually, including `code_challenge_method=S256`. That
suite is what made the refactor extracting `getAuthorizationUrl()` safe, and it
is what will catch an `extraAuthorizationParams()` override that stomps a
standard parameter.

You do **not** need a new flow test. `OAuthFlowTest` drives the whole
redirect → callback → exchange path through `FakeOAuthProvider`, and everything
it proves — the browser binding, the one-time code, the status gate — lives in
the controller and the stores, which are provider-agnostic. Adding a
near-duplicate flow test per provider would mean one cause failing in N files.

### 8.6 What you do not have to touch

No migration: `user_identity` stores the provider as a string and is already
unique on `(provider, provider_user_id)`. No admin change: the queue reads
whatever names are in that column. No account-linking change: linking is by
provider-verified email address, and a provider that returns no address gets the
same `<provider>-<hash>@oauth.invalid` placeholder Apple already gets.
