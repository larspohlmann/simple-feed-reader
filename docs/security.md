# Security invariants

One section per invariant the backend relies on. A code comment that points here names the section's anchor.

## Insecure production config

`InsecureProductionConfigGuardListener` refuses every request in `prod` while
`ALTCHA_HMAC_KEY` still holds the placeholder committed to `.env`.

That key is the only thing that makes an ALTCHA challenge unforgeable, and the
placeholder is public: anyone holding it forges a solved proof-of-work in one
hash instead of about 150,000, so `/register` and `/password-reset-request` keep
answering `200` behind a void gate. A committed default turns a forgotten
override into a failure that looks healthy indefinitely, so the guard makes it
fail closed — the stance `MaintenanceTokenGuard` takes on an empty
`MAINTENANCE_TOKEN`.

- **It matches the exact placeholder**, not a notion of a weak key. "Not
  overridden" is a fact; a strength heuristic would miss real weak keys and
  reject fine unusual ones. `InsecureProductionConfigGuardListenerTest` reads
  `.env`, so an edited placeholder cannot silently stop matching.
- **It runs on `kernel.request`, not in a compiler pass or at kernel boot.** A
  deployment runs `cache:warmup` in `prod` before the `current` symlink flips, so
  a build-time check would abort the warmup of a misconfigured environment and
  take the whole deploy down. A host that injects the key per request (Apache
  `SetEnv`, a php-fpm pool directive) sees only the `.env` default at warmup and
  the real value at request time. Warmup, migrations and console commands are
  never refused.
- **The refusal is a `500` on every route**, not only on the two ALTCHA-gated
  ones. The exception names the variable to set and goes to the log; outside
  debug the problem document suppresses exception messages, so a client learns
  nothing. An instance that half-serves with a void CAPTCHA is quietly failing
  at what it is for.
- **`dev` and `test` are exempt**: the test suite solves real ALTCHA challenges
  with the committed key.

## OAuth account linking

`OAuthAccountLinker` decides which local account a provider-verified identity
signs in to. A wrong answer here is an account takeover, not a bug. The rules,
in the order they apply:

1. **A known identity wins.** A `user_identity` row matching (provider,
   subject) is the account, whatever address the provider reports today. A
   returning user whose provider address changed — perhaps to a victim's — stays
   on their own account.
2. **Only a linkable address links.** An address links to the account holding
   it only when the provider verified it and it is not an Apple private relay. A
   relay address is real and deliverable, but it names one (app, Apple user)
   pair rather than a person, so no login may hang on it.
3. **Otherwise a new account**, with no password, in `pending_approval` — or
   active at once when admin approval is off. The provider proved the address,
   so the account skips `pending_verification` whatever the email-confirmation
   toggle says.

Around those three:

- **An unlinkable address never becomes the login identifier.** Refusing to
  link is only half the rule: an attacker whose provider allows arbitrary
  unverified addresses could otherwise park `admin@company.example` in the
  approval queue, be approved on how the address reads, and end up sharing the
  account with the real owner once that owner recovers a password. The claimed
  address is kept on the `UserIdentity` row, visibly provider-reported.
- **Placeholder addresses.** An identity with no usable address — Apple sends
  the address only on the first authorisation — gets
  `<provider>-<first 32 hex of sha256(subject)>@oauth.invalid`. `.invalid` never
  resolves (RFC 2606), the prefix tells the admin where the account came from,
  the digest keeps the subject out of a column the admin UI shows, and the same
  identity always rebuilds the same address instead of piling up accounts.
  Approving such an account sends the welcome mail to it; that send is deferred
  to `kernel.terminate` and its failure is logged, so the bounce costs a log
  line. Every other account mail starts from an address a person typed.
- **An unverified registration is claimed.** When the address a provider just
  verified belongs to an account still in `pending_verification`, whoever set
  that account's password never proved the address. The account moves to
  `pending_approval` (or active, with approval off), its address is marked
  verified, and its password is wiped with `passwordChangedAt` stamped, which
  revokes any JWT the planter holds. The wipe happens whatever the approval
  toggle says: it is a control over an unproven credential, not a step of the
  approval workflow. Refusing to link and creating a second account instead
  would hand an attacker a cheap denial of service against the real owner and
  strand the common case, where the abandoned registration is the user's own.
  The owner gets back in by signing in with the provider again; a password
  reset works once an admin has approved the account.
- **Linking never overrules an admin.** Every status other than
  `pending_verification` is left as it is: a rejected account is not revived, a
  suspended one is not unsuspended, and an active account's password is not
  wiped, which would revoke the live sessions of a user who only signed in a
  second way. The exchange's status gate then refuses suspended and rejected
  accounts.
- **`User::$email` is never rewritten from a callback.** Only the identity
  row's address follows the provider. The login address is also where
  password-reset mail goes, and a compromised provider account must not be able
  to redirect it.
- **Two concurrent first sign-ins** with one linkable address can both find no
  account; the second hits `uniq_user_email` and gets a retryable `500`, as the
  registration form does in the same race.

## Password change

A JWT lives seven days, with no refresh flow and no token store, so the only per-request revocation channel is the
user reload the Doctrine provider performs. That reload sees a status change (`UserChecker`), but nothing in the token
derives from the password hash: without a further check, a password reset — the one action a compromised user takes
to evict an attacker — would leave a stolen token valid for the rest of its week.

`InvalidatePasswordChangeTokensListener` compares the token's `iat` with `User::getPasswordChangedAt()` on
`JWT_AUTHENTICATED`, where the user is already loaded (`JWT_DECODED` carries only the payload and would need a second
lookup). It needs no server-side token storage, which the Strato target (no Redis, no daemon) could not carry.

- **Strictly before: `<`, never `<=`.** `iat` has whole-second resolution, and a reset followed by an immediate login
  often lands in the same second as `passwordChangedAt`. `<=` would reject that fresh token and make reset look broken
  to the person who just used it. The cost of `<` is that a token minted in that same second survives, which needs an
  attacker logging in within that second while already holding the old password.
- **Fails closed without `iat`.** When a change is recorded and the token cannot prove it is newer, it is refused.
  Lexik always stamps `iat`, so the branch is unreachable today; it keeps a future encoder change from silently
  disabling the check.
- **No recorded change, nothing revoked.** Rows from before the column carry `NULL`, which is why the migration could
  be additive.
- **The 401 is opaque.** The refusal takes the path of every JWT failure (`JWTAuthenticator::onAuthenticationFailure`,
  `JWT_INVALID`, `JwtFailureResponseListener`) and answers the same `unauthorized` problem+json. The holder of a dead
  token may be the thief, so it is never told the password changed.

Pinned by `JwtAccessTest` (the `iat` boundary on both sides; no recorded change) and `PasswordResetTest` (a stolen
token dies on reset, a same-second token survives, another account is untouched).

## Login timing

A password login can fail on credentials in three ways: an unknown address, a wrong password, and an account with no
password hash (created by OAuth sign-in). Symfony performs no dummy hash: `CheckCredentialsListener` reaches the hasher
only for a loaded user with a hash, so an unknown address fails on a bare `SELECT` miss and a password-less account
returns without hashing, while a wrong password pays for a full verify. Measured locally, `algorithm: auto` resolves
to argon2id at about 174 ms per hash (bcrypt, the fallback without libsodium, about 58 ms). That gap survives
byte-identical responses and is measurable over the internet: it sorts addresses into registered and not, and
registered ones into "has a password" and "OAuth only", a list of accounts worth a provider-named phishing mail.

- `PasswordWorkEqualizer::spendOneHash()` runs one real `hash()` of a placeholder through the configured hasher, so it
  follows any change of algorithm or cost; a hard-coded dummy hash would drift out of calibration. It is not constant
  time, which PHP cannot deliver and which is not the bar: the bar is removing the argon2-sized cliff.
- `LoginTimingEqualizer` decides when to spend it: on an unknown address (the `UserNotFoundException` that
  `AuthenticatorManager` masks behind a `BadCredentialsException`, found on the `previous` chain), on a request body
  that named no user, and on an account without a password hash. It spends nothing on a wrong password (already
  hashed), on a status rejection (post-verify, so already hashed; a second hash would make it the slowest outcome and
  flip the oracle) or on a throttled request (an attacker would buy an argon2 of our CPU with one cheap request). Its
  own lookup runs on hit and miss alike, so it is no side channel.
- It is called from `LoginFailureHandler`, not a `LoginFailureEvent` subscriber: SecurityBundle copies global
  listeners onto every firewall, so a subscriber would also hash on every unauthenticated JWT request. The handler
  serves only the `login` and `passkey_login` firewalls. The passkey firewall passes no identifier, which leaks
  nothing: a discoverable login has no address to enumerate.
- Registration spends the same hash: a taken address returns without hashing the password a fresh signup hashes, so
  `RegistrationService::register()` calls `spendOneHash()` before it returns.
- A password-reset request deliberately does not. Nothing on that path hashes a password, so a dummy hash on the short
  paths would make "unknown address" slower than "account exists", a louder oracle pointing the other way. What
  closed that gap was sending the mail after the response (`DeferredMailer`); what remains is one `INSERT` and one
  `UPDATE`, far below network jitter.

Tests count hashes instead of timing them (`HashCountingWork`): `LoginTimingEqualizerTest` drives the decision, and
`LoginTest::testEveryCredentialFailureCostsTheSameOneHash` proves the wired request path recovers the submitted address.

## Login throttle key

Symfony's `DefaultLoginRateLimiter` keys its per-identifier bucket on the `_security.last_username` request attribute:
the raw submitted identifier, lower-cased and nothing else. The user provider resolves accounts through
`User::normalizeEmail()`, which also trims. So `" bob@example.com"` and `"bob@example.com"` sign in to one account but
land in different buckets, and since `trim()` strips six bytes in any combination, an attacker has an unbounded supply
of spellings for one address, each with a fresh `max_attempts` budget. The per-identifier throttle stops existing.

`NormalizedLoginRateLimiter` decorates the limiter and rewrites that attribute with `User::normalizeEmail()` before it
delegates. Hashing, secret and the two-limiter structure stay Symfony's, and "normalised" keeps one definition.
Mutating the request is deliberate: every other layer uses the normalised value, and the only other reader,
`AuthenticationUtils::getLastUsername()`, re-displays it on form logins, which this stateless JSON firewall does not
have.

It must stay peekable. `LoginThrottlingListener` peeks on `CheckPassportEvent` and consumes only on failure; a
non-peekable decorator around a peekable limiter would shift the limit by one attempt. The constructor demands a
peekable inner limiter, so a wrong wiring fails at container build, not as an off-by-one in a brute-force defence.

Pinned by `LoginTest`: padded spellings share one bucket in both directions, and a padded address with the correct
password still signs in. `NormalizedLoginRateLimiterTest` pins that `peek()`, `consume()` and `reset()` each hand the
inner limiter the normalised name.

## Account status checks

Two checkers, one per kind of firewall:

- **`LoginUserChecker`** (`login` and `passkey_login`) checks the status in `checkPostAuth`, which runs from
  `AuthenticationSuccessEvent` once the credential is verified. A `checkPreAuth` check would run from
  `UserCheckerListener::preCheckCredentials` (priority 256), before `CheckCredentialsListener` (priority 0) verifies the
  password, and would answer "suspended" to anyone who merely guesses an address. Post-auth, a wrong password against a
  suspended account is the ordinary 401, byte for byte.
- **`UserChecker`** (`api`) checks in `checkPreAuth`. A JWT request has no password to verify, and the provider reloads
  the user on every request anyway, so a suspension takes effect on the next request rather than when the seven-day
  token expires. There are no refresh tokens and no blocklist: this reload is the revocation.
- **`TrialExpiryGuard`**, called by both, enforces the trial lazily because the app has no scheduler: the first request
  after `trialEndsAt` flips an Active account to Suspended and is refused. The flip happens at most once per account,
  so a live trial costs a null check and a date comparison. `trialEndsAt` stays set, which is how admin screens tell a
  trial expiry from a manual suspend.

`security.yaml`'s `expose_security_errors: AccountStatus` lets the status 403 through while `UserNotFoundException`
stays masked as bad credentials.

## Passkey login firewall

`PasskeyAuthenticator` authenticates a WebAuthn assertion as its own firewall (`passkey_login` in `security.yaml`,
which must stay between `login` and `api`). A firewall rather than a controller makes "a passkey login returns the JWT a
password login returns" structural: its success handler is the `lexik_jwt_authentication.handler.authentication_success`
service `json_login` uses, so both flows run `JWTTokenManager::create()` and every token-issue listener, and its failure
handler is `LoginFailureHandler`.

- **Verification is lazy.** `AssertionVerifier::verify()` runs inside the `UserBadge` user loader, not in
  `authenticate()`. `LoginThrottlingListener::checkPassport` runs on the same `CheckPassportEvent` at a higher priority
  than the listener that resolves the badge, so an over-budget request gets its 429 before any assertion is parsed.
  Calling `verify()` eagerly would skip the throttle.
- **The throttle identifier is a fixed sentinel.** A discoverable login carries no identifier, and `UserBadge`
  deprecates an empty one. `DefaultLoginRateLimiter` keys on `identifier-IP`, so the fixed value gives one bucket per
  client IP: five attempts per quarter hour.
- **Failures look like password failures.** Every passkey sign-in failure becomes a plain `AuthenticationException`
  (the original kept as `previous`, which is how an unknown credential keeps its own problem type), and
  `LoginTimingEqualizer` runs with no identifier, so every rejection costs the same whichever `AssertionVerifier` check
  refused it.
- **Status is checked post-auth** by `LoginUserChecker`, as on the password firewall.
- The `/api/auth/passkey/login` route exists only so the request is routed: `RouterListener` (priority 32) runs before
  the firewall (priority 8), so without a route the POST would 404 before the authenticator saw it. The same holds for
  `AuthController::login()`.

## Passkey ceremonies

WebAuthn registration (attestation) and login (assertion) each span two requests: the options, then the browser's
response. What must hold between and inside them:

- **The challenge handle is a bearer credential.** `PasskeyChallengeStore` keeps the challenge server-side for five
  minutes, since the API keeps no session. The handle it returns authorises the ceremony, so neither the cache key (an
  unsalted SHA-256 of 32 random bytes, which has no guessable preimage) nor the cached payload contains it: a readable
  cache directory on shared hosting must not be a list of usable handles. `consume()` deletes the entry before it
  validates it, so a handle that fails the expiry check is burned. The stored `expires_at` is checked against the
  injected clock because the pool's TTL runs on the backend's clock; the pool TTL is still set, so entries do not pile
  up on disk.
- **Single use is best-effort under concurrency.** PSR-6 has no compare-and-swap, so two simultaneous redemptions can
  both see the entry. Both racers present the same challenge and only one can pass the signature check: the argument
  `OAuthStateStore` makes for the OAuth flow (`docs/oauth-sign-in.md`).
- **The user handle is minted once.** `PasskeyCredentials::userHandleFor()` returns the account's existing handle, or a
  fresh random one while it has no credential. The handle shown at options time is the one the authenticator stores and
  returns at every login, so `RegistrationOptionsFactory::create()` stores it with the challenge and
  `AttestationVerifier` reads it from the consumed challenge. Calling `userHandleFor()` again would mint a different
  value and break discoverable login for every first passkey. A handle is 32 random bytes: never the e-mail
  (authenticators sync it to password managers) and never the account id (it would leak the account count and order).
- **Order inside verification.** Both verifiers consume the challenge (the attestation also checks its owner) before
  they parse any client bytes, so a caller who does not own a challenge never learns whether a forged credential would
  parse. Parsing runs inside one deliberately broad catch, because the WebAuthn deserializer and the CBOR decoder throw
  a scatter of library, serializer and SPL exceptions on attacker bytes; everything that can fail for real reasons
  (building the options, the database) stays outside it.
- **The login trusts only stored identity.** An assertion resolves the account from the credential id alone
  (`credential_id` is unique across all accounts), and the user handle passed to the library is the stored one, never
  the client's. The signature-counter check is the library's (`CheckCounter` with `ThrowExceptionIfInvalid`, wired in
  `PasskeyCeremony::request()`); `AssertionVerifier` logs a counter that did not advance, a sign of a cloned
  authenticator or a replay.
- **One options builder per ceremony.** Each factory's `optionsFor()` is the only place its resident-key and
  user-verification requirements live, shared by the options endpoint and the verifier, so the two cannot drift. User
  verification is required: the passkey is the account's only factor, and with `attestation: none` nothing else stops a
  caller from clearing the UV bit.
- **Login options enumerate nothing.** They take no e-mail, carry an empty `allowCredentials`, and cost the same for
  every caller; the login challenge stores a null user id and handle.
- **Stored identifiers are base64url text**, decoded to raw bytes before they reach the library. `credential_id` is
  `VARCHAR(255)`, which holds 191 raw bytes once encoded; the library accepts up to 1023, so `UserPasskeyFactory` refuses
  a longer id before the write (MySQL would answer a 500, SQLite would store it). `response.transports` is unvalidated
  client data echoed back in every later exclude list, so only the spec's values are stored.
- **Sign-in availability.** `PasskeySignInAvailability` answers from instance configuration only (the admin toggle, and
  a relying-party id that could work at all), so it cannot vary with accounts or credentials. Every passkey endpoint
  except `DELETE /api/auth/passkeys/{id}` enforces it server-side, the login inside `AssertionVerifier::verify()`, as
  the login route has no controller action. `DELETE` stays open so a user can remove a credential they can no longer
  use. That only helps an account with a second sign-in method: a passkey-only account cannot obtain a token while
  sign-in is disabled, and stays locked out until an admin re-enables it.
- **Removal cannot lock an account out.** `PasskeyRemovalPolicy` refuses to delete the last passkey of an account with
  neither a password hash nor a linked OAuth identity. `PasskeyRemoval` looks a credential up by `(id, user)`, so a
  foreign id answers 404 like an unknown one.

## ALTCHA difficulty

`AltchaService` issues sha256 proof-of-work challenges whose `number` lies in `[MIN_NUMBER, MAX_NUMBER]`, 100 000 to
200 000. Both bounds are measured, not guessed (PHP 8.3 and Node 22 on Apple silicon):

| Solver | Per hash | Solve time |
|---|---|---|
| attacker, native sha256 | 0.41 µs | 40 ms minimum, 63 ms average |
| widget, awaited `subtle.digest` | 16.5 µs | 2.5 s average, 3.3 s worst |

That is a 25–40× asymmetry against the honest user. The widget awaits one promise per candidate and cannot close it,
so the window is sized by what a browser can afford. A wider window is defensible only if the widget moves to
`auto="onload"`, where the solve overlaps form filling instead of blocking submit. That is a frontend decision: do not
widen the window without confirming the widget mode. Re-derive the figures by hashing 200 000 candidates and dividing.

The floor is the load-bearing half. Challenges are free and unlimited, and nothing binds a client to the challenge it
was issued, so with a floor of zero an attacker batch-requests, discards the expensive challenges and solves only the
cheapest: the effective cost becomes the batch minimum rather than its mean. Pinning the minimum makes the cost floor a
protocol property. For the same reason `verify()` refuses a `number` outside the window before hashing it.

The proof-of-work prices bulk e-mail abuse, not account creation: a registration lands in `pending_verification`,
needs a clicked link and then an admin, and `app:users:purge-unverified` removes it after 48 hours. The rate limiters on
`POST /api/auth/register` and `POST /api/auth/password-reset-request` cap the abuse; ALTCHA only prices it.

The replay entry outlives the challenge by ten minutes (`REPLAY_TTL_SECONDS`): with one shared expiry, a solution first
spent just before the challenge expired could find its replay entry already evicted on a second use.

## Stored secrets

The secrets the server must use while their owner is away (a user's AI provider key, the mail password, the proxy
password, the Grafana API token) are sealed by `InstanceSecretCipher` (XChaCha20-Poly1305).

- The master key is `INSTANCE_SECRET_KEY`, at least 32 characters, and lives only in the environment. The cipher
  refuses to construct with a shorter one: a short key would still derive a key and encrypt, and nothing downstream
  could notice.
- Every row carries its own random salt. The row key is derived with HKDF-SHA256 from the master key, the salt and the
  binding.
- The binding (`SecretBindingModel::render()`: purpose, scheme version and owner) is both the HKDF info and the AEAD's
  additional data, so a ciphertext cannot be opened as another kind of secret or under another owner. The rendered
  string is part of the stored format: changing it makes every existing row unreadable, and
  `StoredSecretCompatibilityTest` fails.
- A row that does not open throws `SecretUnreadableException`, whatever the cause: a wrong or rotated master key, a row
  edited in the database, or a row bound to another owner. Telling them apart would only help someone probing the
  store; to a caller the secret is gone and must be entered again.

What this does not protect against: someone who holds both a database dump and the environment file. The server has
to read the secret while its owner is away, so the server can always reach it.

## AI provider endpoints

An account's AI base URL does not pass through `UrlGuard`: private, loopback and link-local targets are accepted,
so an account can point at a local provider (Ollama, LM Studio). This is a recorded exception to the SSRF boundary,
decided by the repository owner on 2026-08-06 (#305).

The accepted risk: any account that reaches the AI settings can make the server send requests to hosts on its own
network and observe whether they answer. The mitigation is that registration is approval-gated, so every account is
one the operator admitted. If the instance ever opens registration, revisit this decision.

`ProviderCredentialsModel::fromAccountInput()` is the only validation the URL gets: `http` or `https`, a host, no
user or password, no query or fragment. `OpenAiCompatibleCatalog` and `OpenAiCompatibleChatClient` add a timeout, a
response size cap, no redirects (a followed redirect would hand the API key to another host) and no transparent
compression, and `RateLimitGuard` covers the AI settings endpoints that call the provider. None of these is an SSRF
guard. Do not copy this pattern for any other outbound call.
