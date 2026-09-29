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
