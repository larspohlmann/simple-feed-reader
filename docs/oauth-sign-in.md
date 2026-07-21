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
4. The SPA reads that `code` and `POST`s it to `/api/auth/oauth/exchange`.
5. It gets back a normal JWT — the same token `POST /api/auth/login` issues.

**Why the extra hop in steps 3–4.** The `code` in the redirect is *not* the JWT
and is not the provider's authorization code. It is a one-time value that lives
30 seconds and dies on first use. A JWT in that redirect's query string would be
written into browser history, forwarded in `Referer` headers, and logged
verbatim by every proxy and access log on the way. The JWT never appears in a
URL anywhere in this system.

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
value that no request can influence.

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
sends; Firefox has behaved the same since version 75.

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

It is `SameSite=None`, which looks like a weakening and is the opposite. Apple
returns its callback as a **cross-site POST** (`response_mode=form_post`), and a
`Lax` cookie is not sent on a cross-site POST — so `Lax` would leave Google
working perfectly while every Apple sign-in failed. The cookie needs no help
from `SameSite`: it grants nothing by itself and is useless without the matching
unspent `state`.

**One sign-in at a time per browser.** There is one cookie, so starting a second
sign-in replaces the first flow's binding and the abandoned tab will fail with
`invalid_state`. Starting again works.

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

POST /api/auth/oauth/exchange  { "code": "…" }
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

Do this **immediately** on route activation. The code expires 30 seconds after
it was issued and is destroyed on first use, which also means: do not retry a
failed exchange with the same code, and expect a page reload of
`/auth/callback?code=…` to fail. Strip the query string from the URL once
exchanged.

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

**`400`**, `"type": "invalid_token"` — the code was unknown, already spent, or
expired. Indistinguishable from each other on purpose. Send the user back to the
login page to start over.

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
