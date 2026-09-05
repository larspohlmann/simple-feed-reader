# Prune stale passkeys with the WebAuthn Signal API — design (#727)

**Status:** agreed. The decisions were taken on the issue on 2026-08-31
(comment "Design decided"); this document restates them against the code as
of `develop` at 2aa8c6ac and adds the structural choices the recorded design
left open. The on-branch plan argues from this file.

## Problem

A passkey the server no longer knows about stays in the user's password
manager. Two ways it happens:

1. `navigator.credentials.create()` writes the credential before the server
   validates it. A refused enrolment leaves a live entry and no row.
2. `DELETE /api/auth/passkeys/{id}` removes the row. The authenticator is
   never told.

The sign-in sheet then keeps offering credentials that cannot work. The
WebAuthn Signal API (`PublicKeyCredential.signalUnknownCredential`,
`PublicKeyCredential.signalAllAcceptedCredentials`) closes the gap. Chrome
132+, Edge 132+ and Safari 26+ implement it. Firefox does not. Everything here
is therefore best-effort and must never gate a sign-in or a deletion.

## Spec facts that drive the design

Source: WebAuthn L3 §signal methods, Chrome's Signal API doc.

- `credentialId`, `userId` and `allAcceptedCredentialIds` are **base64url
  strings**. The values cross the wire in the encoding `user_passkey` already
  stores. No conversion in `core/webauthn.ts`.
- An **empty** `allAcceptedCredentialIds` is not a no-op: the browser removes
  every credential for that `(rpId, userId)`. A **short** list destroys valid
  credentials irreversibly.
- A `userId` that matches nothing is a silent no-op.
- The returned promise resolves once the options are well formed. It proves
  nothing about what the authenticator did.
- The spec prefers `signalUnknownCredential` for an unauthenticated caller and
  `signalAllAcceptedCredentials` after sign-in. That matches the two call
  sites below.

Consequence: only `signalAllAcceptedCredentials` can remove an orphan the
server never knew about. `signalUnknownCredential` can only name an id the
caller holds in hand.

## Decision 1 — narrow the login 401 for the unknown-credential case only

`LoginFailureHandler` collapses every passkey login failure into one
`invalid_credentials` 401: expired challenge, malformed body, bad signature,
stalled counter, unknown credential id. Signalling on any 401 would delete a
**working** passkey whenever a challenge expired, which is likely under
conditional mediation. So the unknown-id case gets its own problem type and
nothing else changes.

`AssertionVerifier::verify()` already consumes the challenge before it
resolves the credential id, so the unknown-id branch is isolated by
construction.

### Backend changes

- New `App\Service\Passkey\Exception\UnknownPasskeyCredentialException`
  (`final`, extends `ApiException`): type `unknown_passkey_credential`, status
  401, title `Unknown passkey`, detail `This passkey is not registered here.`
  Naming follows `unknown_passkey_challenge`.
- `AssertionVerifier::resolveCredential()` throws it instead of
  `AssertionRejectedException`. Every other rejection keeps the generic type.
- `PasskeyAuthenticator::verifiedUser()` is unchanged: it already attaches the
  `ApiException` as `previous` on the `AuthenticationException` it throws.
- `LoginFailureHandler::onAuthenticationFailure()` gains one `match` arm: when
  `$exception->getPrevious()` is an `UnknownPasskeyCredentialException`, that
  exception is the `$apiException`. `LoginTimingEqualizer::equalize()` still
  runs first, so timing is unchanged and only the machine-readable `type`
  differs.
- Docblocks that state the collapse must be amended, not contradicted:
  `AssertionRejectedException` (drop "unenrolled credential id" from the list
  and name the exception that took it over), `LoginFailureHandler` (the
  single deliberate exception to "every failure looks the same"), and the
  `PasskeyLoginTest::testADisabledInstance…` docblock that says the type
  cannot discriminate.

### The leak this accepts

A caller who **already holds** a credential id can learn whether this instance
knows it. No account identity is exposed, and a discoverable-credential login
has no address to enumerate. `DuplicatePasskeyException` already accepts the
same oracle on the registration side, for the same reason.

## Decision 2 — widen the listing response

`GET /api/auth/passkeys` (and the 201 body of `POST /api/auth/passkey/register`,
which uses the same mapper) returns:

```json
{
  "rpId": "reader.example",
  "userHandle": "…base64url… or null",
  "acceptedCredentialIds": ["…base64url…"],
  "passkeys": [ { "id": 1, "label": "…", "createdAt": "…", "lastUsedAt": null } ]
}
```

- `rpId` is `PasskeyRelyingParty::id()`.
- `userHandle` is the handle on the account's rows, or `null` when the account
  has no row. **Never minted.** `PasskeyCredentials::userHandleFor()` mints a
  random handle for an empty account, and a minted handle makes the signal a
  silent no-op, so the listing reads the handle off the rows themselves.
- `acceptedCredentialIds` is a **flat, authoritative array**, deliberately not
  a `credentialId` per row: the frontend passes it straight through and never
  rebuilds it, so no future filter, sort limit or paging can produce the short
  list that irreversibly deletes credentials.
- The row shape is unchanged.

This discloses nothing new: `POST /api/auth/passkey/register/options` already
hands the same authenticated user `rp.id`, `user.id` and every credential id in
`excludeCredentials`. The "never the credential id" note on `PasskeyJson` was a
statement about that one response; its reason ("a client has no use for it")
stops being true, so the docblock changes. `PasskeyListTest` pins the row keys
today and must keep pinning them, plus the three new top-level keys.

Rejected: a seventh endpoint `GET /api/auth/passkeys/signal` (the exact
addition `PasskeyController`'s reviewer note warns about); reading the values
off `register/options` (mints a challenge and writes a cache entry on every
Settings visit).

### Structure: two services, not a tenth constructor parameter

`PasskeyController` has nine constructor parameters. PHPMD's
`ExcessiveParameterList` fires at ten, and the standing rule is to fix the
design the metric points at. The controller therefore delegates two of its
responsibilities to services and ends with eight parameters:

- `App\Service\Passkey\PasskeyListing` (`final readonly`), depends on
  `UserPasskeyRepository` and `PasskeyRelyingParty`. `for(User $user): array`
  loads the rows and returns the full body via `PasskeyJson::listing(string
  $relyingPartyId, array $passkeys)`. `PasskeyJson` stays a static mapper in
  `src/Http/`; it derives `userHandle` from the first row (or `null`) and
  `acceptedCredentialIds` from every row. `list()` and `register()` call the
  service; the controller no longer injects the repository.
- `App\Service\Passkey\PasskeyRemoval` (`final readonly`), depends on
  `UserPasskeyRepository`, `PasskeyRemovalPolicy` and `EntityManagerInterface`.
  `remove(User $user, int $id): void` performs the `(id, user)` lookup, the
  policy guard, `remove()` and `flush()`. A miss throws a new
  `App\Service\Passkey\Exception\PasskeyNotFoundException` (`ApiException`,
  404, type `passkey_not_found`, title `No such passkey`), which the standard
  exception listener renders as `application/problem+json`. The controller's
  `delete()` becomes a single delegating call. The 404-not-403 reasoning moves
  with the lookup into the service docblock.

The controller's `$availability->guard()` calls stay exactly where they are.
The reviewer note's count ("FIVE times") is unchanged.

## Where each call goes

| Orphan source | Call | Data |
|---|---|---|
| Enrolment the server refused | `signalUnknownCredential` | `options.rp.id` + the credential just created |
| Login with an id the server does not know | `signalUnknownCredential` | `options.rpId` + the credential just sent, **only** on `unknown_passkey_credential` |
| Settings opened, or a passkey deleted | `signalAllAcceptedCredentials` | the listing response |

### Frontend changes

`core/webauthn.ts` gains two best-effort helpers beside
`isConditionalMediationSupported()`, same feature-detect-and-return shape:

- `signalUnknownCredential(rpId: string, credentialId: string): Promise<void>`
- `signalAllAcceptedCredentials(rpId: string, userId: string,
  allAcceptedCredentialIds: string[]): Promise<void>`

Both resolve without effect when `PublicKeyCredential` or the static method is
absent, and swallow anything the browser throws. TypeScript 5.9's `lib.dom`
does not declare either method, so the file declares a local structural type
for the two statics and reads them off `window.PublicKeyCredential` through it.
`PasskeyService` stays the only caller, so that file's docblock stays true.

`PasskeyService`:

- `enrol()`: the `register` POST is caught on its own. When it fails with an
  `HttpErrorResponse` whose status is 4xx, the service calls
  `signalUnknownCredential(options.rp.id, credential.id)` before rethrowing
  the `Problem`. Not on status 0 and not on 5xx: the server may have stored
  the row before the response was lost, and the signal is irreversible. A
  rejected ceremony creates no credential, so it signals nothing.
- `login()`: the `login` POST is caught on its own. When the parsed `Problem`
  has type `unknown_passkey_credential` and `options.rpId` is present, the
  service calls `signalUnknownCredential(options.rpId, credential.id)` before
  rethrowing. Any other failure signals nothing. Both `signIn()` and
  `signInConditionally()` go through this path.
- `list()`: keeps its `Observable<PasskeySummary[]>` signature, so
  `PasskeysGroupComponent` and its spec are untouched. Internally it reads the
  widened body, remembers the last non-null `{rpId, userHandle}` on the
  service instance, and fires `signalAllAcceptedCredentials(rpId, handle,
  acceptedCredentialIds)` as a fire-and-forget side effect whenever a handle
  is in hand (from this response or remembered). The signal never affects the
  stream: it cannot delay, error or change what the component receives.

### The hard case: deleting the last passkey

After that delete the account has no rows, so `userHandle` is `null` — and the
sweep needs the handle exactly then. `PasskeysGroupComponent.refresh()`
already runs when the group opens **and** after every successful delete, so
the service has the handle in hand before the delete happens and reuses it.
The remembered handle is the right key: it is the one those credentials were
created under. An empty `acceptedCredentialIds` with that handle is the
intended outcome (the user removed every passkey).

This cannot heal an account whose rows were **all** already gone before this
feature shipped: the handle went with them. The login path is the only cure
there, which is the second reason Decision 1 matters.

Not chosen: persisting the handle on `User`. It is the better model and is
parked as a follow-up; it costs an entity field, a migration and a backfill
for a best-effort cleanup.

## Native client

Widening the listing helps a native iOS client: the same three values drive
`ASCredentialIdentityStore`. No endpoint is added; the §6 checklist in
`docs/architecture.md` holds unchanged (bearer, stateless, JSON, no
browser-only input).

## Testing

Backend:

- `UnknownPasskeyCredentialExceptionTest` and `PasskeyNotFoundExceptionTest`:
  type, status, title, detail (sibling of `AssertionRejectedExceptionTest`).
- `AssertionVerifierTest::testAnUnenrolledCredentialIdIsRejected` expects the
  new exception.
- `PasskeyLoginTest`, **through the firewall**: the never-enrolled credential
  returns type `unknown_passkey_credential`; the expired handle and a tampered
  signature return `invalid_credentials`. That pair is the whole safety
  argument; a direct-invocation test cannot prove it. The risk this decides:
  whether the security layer wraps the exception before `LoginFailureHandler`
  sees `previous`.
- `PasskeyListTest`: top-level keys are exactly `acceptedCredentialIds`,
  `passkeys`, `rpId`, `userHandle`; row keys unchanged; `userHandle` is the
  stored handle with rows and `null` with none; `acceptedCredentialIds` lists
  every stored id in `createdAt` order; the 201 from `register` carries the
  same shape (in `PasskeyRegistrationTest`); delete still answers 204 for own,
  404 problem+json for foreign, 409 for the last sign-in method.
- Unit tests for `PasskeyListing` and `PasskeyRemoval` are not required
  beyond the functional coverage above; the functional tests exercise both.

Frontend (Jest, jsdom has no `PublicKeyCredential`, so "absent API" is the
default rather than a special case):

- `webauthn.spec.ts`: each helper resolves when `PublicKeyCredential` is
  absent, when the static is missing, and when the browser call rejects; each
  forwards the exact `{rpId, credentialId}` / `{rpId, userId,
  allAcceptedCredentialIds}` object when present.
- `passkey.service.spec.ts`: a 4xx `register` signals the unknown credential
  with `rp.id` and the created id; a status-0 `register` does not; a rejected
  ceremony does not; a login 401 of type `unknown_passkey_credential` signals
  with `rpId` and the sent id; an `invalid_credentials` 401 does not; `list()`
  still yields the rows; `list()` signals with the response handle and ids;
  a later `list()` with `userHandle: null` and no ids signals with the
  remembered handle and an empty array; `list()` with no handle ever seen
  signals nothing; a signal that rejects leaves `list()`, `enrol()` and
  `signIn()` outcomes unchanged.

Gates: `composer check`, `composer md` (every touched `src` file clean),
`php bin/phpunit` natively and the MySQL leg in Docker,
`composer infection:diff` at `minMsi: 80`, `npm run check` in the frontend
container. PhpStorm inspections on changed PHP block on ERROR and WARNING.

## Out of scope, recorded

- Changing the RP id deletes every `user_passkey` row (624 design §3.5). That
  orphans credentials for every user under the old rp id; no single browser
  can clean that up.
- Running `signalAllAcceptedCredentials` on every sign-in. A Settings visit
  already heals the same case here.
- Persisting the user handle on `User` (see above).
