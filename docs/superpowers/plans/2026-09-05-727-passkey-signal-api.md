# #727 Prune Stale Passkeys with the WebAuthn Signal API — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** A passkey the server refused or deleted disappears from the browser's password manager, without touching it by hand, and a browser without the Signal API behaves exactly as today (#727).

**Architecture:** The backend names the one login failure the browser may act on (`unknown_passkey_credential`) and widens the passkey listing with `rpId`, `userHandle` and a flat authoritative `acceptedCredentialIds`. Two small services (`PasskeyListing`, `PasskeyRemoval`) take listing and removal out of `PasskeyController`, which keeps the controller under PHPMD's parameter limit. The frontend gains two best-effort helpers in `core/webauthn.ts`; `PasskeyService` calls `signalUnknownCredential` after a server-refused enrolment and after the new login problem type, and `signalAllAcceptedCredentials` on every listing, remembering the last non-null user handle so the sweep after deleting the last passkey still has its key.

**Tech Stack:** Symfony 7.4 / PHP 8.4, PHPUnit (SQLite natively, MySQL in Docker), Angular 20 standalone + signals, Jest/jsdom (no `PublicKeyCredential` in jsdom), TypeScript 5.9.

**Spec:** `docs/superpowers/specs/2026-09-05-727-passkey-signal-api-design.md` — read it first. The issue comment "Design decided (2026-08-31)" on #727 holds the same decisions with the spec research behind them.

## Global Constraints

- **Branch:** `feature/727-passkey-signal-api` already exists with the spec and this plan committed. Run `git status --short` before any checkout: a concurrent session may share this checkout. Never `reset` or `stash` another session's work.
- **Commits:** `type(#727): summary`, no attribution lines. Commit after every task.
- **PHP style (CLAUDE.md):** `declare(strict_types=1)`, `final readonly class` with constructor promotion, guard clauses, no boolean flags, typed exceptions under `Service/Passkey/Exception/`. Comments: one line, three at the absolute most; only the *why*. Delete a comment the change makes stale.
- **Gates every touched PHP file must pass:** `composer cs`, `composer stan` (warm the cache first with `bin/console cache:warmup`), `composer md` (every touched `src` file PHPMD-clean, not merely free of new findings), `composer tramp`. Run from `backend/`.
- **PHPMD parameter limit:** `ExcessiveParameterList` fires at 10 constructor parameters. `PasskeyController` has 9 today. Task 3 must land before Task 4; the count goes 9 → 7 → 8.
- **Backend tests:** `php bin/phpunit tests/Path/ToTest.php` from `backend/` for one file. Both legs before the PR: native `php bin/phpunit` and `docker compose exec php vendor/bin/phpunit` from the repo root. Never run the native and Docker suites at the same time.
- **Frontend tests run inside Docker:** `docker compose exec -T frontend npx jest src/app/core/webauthn.spec.ts` for one spec, `docker compose exec -T frontend npm run check` for the gate. Native `npx jest` skips the type check and passes code the gate rejects.
- **TypeScript:** `strict`, `noPropertyAccessFromIndexSignature`. Prettier at 100 columns. `lib.dom` (TS 5.9) does not declare the two Signal API statics; Task 5 declares a local structural type.
- **Wire encoding:** every credential id and user handle is already a base64url string on both sides. No conversion anywhere in this feature.
- **Irreversibility rule:** `acceptedCredentialIds` is passed to the browser exactly as received. Never filter, sort, slice or rebuild it. An empty list with a valid handle deletes every credential for that account in the browser — intended only after the user removed their last passkey.
- **Scope:** no new endpoint, no `User` column for the handle, no signal on every sign-in, no i18n copy (signalling is silent), no change to `PasskeysGroupComponent` or its spec.

---

### Task 1: `UnknownPasskeyCredentialException` from `AssertionVerifier`

**Files:**
- Create: `backend/src/Service/Passkey/Exception/UnknownPasskeyCredentialException.php`
- Create: `backend/tests/Service/Passkey/Exception/UnknownPasskeyCredentialExceptionTest.php`
- Modify: `backend/src/Service/Passkey/AssertionVerifier.php` (`resolveCredential()`, ~line 160)
- Modify: `backend/src/Service/Passkey/Exception/AssertionRejectedException.php` (docblock)
- Modify: `backend/tests/Service/Passkey/AssertionVerifierTest.php` (`testAnUnenrolledCredentialIdIsRejected`, ~line 112)

**Interfaces:**
- Consumes: nothing new.
- Produces: `App\Service\Passkey\Exception\UnknownPasskeyCredentialException` (`final`, extends `ApiException`), `type = 'unknown_passkey_credential'`, `status = 401`, `title = 'Unknown passkey'`, `detail = 'This passkey is not registered here.'`. Task 2 matches on this class; Task 8 matches on the `type` string.

- [ ] **Step 1: Check out the branch**

```bash
git status --short
git checkout feature/727-passkey-signal-api
```

- [ ] **Step 2: Write the failing exception test** at `backend/tests/Service/Passkey/Exception/UnknownPasskeyCredentialExceptionTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Service\Passkey\Exception;

use App\Service\Passkey\Exception\UnknownPasskeyCredentialException;
use PHPUnit\Framework\TestCase;

/**
 * The one passkey login failure whose type reaches the client (#727) — the
 * string the frontend branches on before it signals the browser.
 */
final class UnknownPasskeyCredentialExceptionTest extends TestCase
{
    public function testItIsA401WithTheTypeTheFrontendBranchesOn(): void
    {
        $exception = new UnknownPasskeyCredentialException();

        self::assertSame('unknown_passkey_credential', $exception->type);
        self::assertSame(401, $exception->status);
        self::assertSame('Unknown passkey', $exception->title);
        self::assertSame('This passkey is not registered here.', $exception->detail);
    }
}
```

- [ ] **Step 3: Change the verifier test to expect the new class.** In `backend/tests/Service/Passkey/AssertionVerifierTest.php`, inside `testAnUnenrolledCredentialIdIsRejected()`, replace

```php
        $this->expectException(AssertionRejectedException::class);
```

with

```php
        $this->expectException(UnknownPasskeyCredentialException::class);
```

and add `use App\Service\Passkey\Exception\UnknownPasskeyCredentialException;` to the imports. Keep the `AssertionRejectedException` import: the other tests still use it.

- [ ] **Step 4: Run both tests to verify they fail**

Run from `backend/`:
```bash
php bin/phpunit tests/Service/Passkey/Exception/UnknownPasskeyCredentialExceptionTest.php tests/Service/Passkey/AssertionVerifierTest.php
```
Expected: the exception test errors with class not found; `testAnUnenrolledCredentialIdIsRejected` fails because `AssertionRejectedException` was thrown instead.

- [ ] **Step 5: Create the exception** at `backend/src/Service/Passkey/Exception/UnknownPasskeyCredentialException.php`:

```php
<?php

declare(strict_types=1);

namespace App\Service\Passkey\Exception;

use App\Exception\ApiException;

/**
 * The assertion named a credential id no account holds (#727). Kept apart
 * from AssertionRejectedException because the browser can only prune the
 * dead entry (Signal API) if the client learns this exact case; a caller who
 * already holds the id learns only whether this instance knows it, the same
 * oracle DuplicatePasskeyException accepts.
 */
final class UnknownPasskeyCredentialException extends ApiException
{
    public function __construct()
    {
        parent::__construct(
            'unknown_passkey_credential',
            401,
            'Unknown passkey',
            'This passkey is not registered here.',
        );
    }
}
```

- [ ] **Step 6: Throw it from `resolveCredential()`.** In `backend/src/Service/Passkey/AssertionVerifier.php` add the import `use App\Service\Passkey\Exception\UnknownPasskeyCredentialException;` and replace the method with:

```php
    /**
     * `credential_id` is unique across every account (UserPasskey's own
     * unique constraint), so this lookup carries no user. Its own exception
     * type, not AssertionRejectedException: the client prunes the dead
     * browser entry on this case alone (#727).
     */
    private function resolveCredential(string $rawCredentialId): UserPasskey
    {
        $credentialId = Base64UrlSafe::encodeUnpadded($rawCredentialId);

        return $this->passkeys->findOneByCredentialId($credentialId)
            ?? throw new UnknownPasskeyCredentialException();
    }
```

- [ ] **Step 7: Amend `AssertionRejectedException`'s docblock** so it no longer claims the unknown-id case. Replace the first paragraph of the class docblock in `backend/src/Service/Passkey/Exception/AssertionRejectedException.php` with:

```php
/**
 * A WebAuthn assertion ("login") failed verification: wrong challenge, wrong
 * origin, wrong relying-party id, a stalled signature counter, or a
 * corrupt/unparseable response. AssertionVerifier catches all of these at the
 * WebAuthn/CBOR boundary and rewrites them to this one type. The one case
 * with its own type is a credential id no account holds —
 * UnknownPasskeyCredentialException (#727).
 *
```

Leave the remaining two paragraphs as they are.

- [ ] **Step 8: Run the tests to verify they pass**

```bash
php bin/phpunit tests/Service/Passkey/Exception/UnknownPasskeyCredentialExceptionTest.php tests/Service/Passkey/AssertionVerifierTest.php
```
Expected: PASS.

- [ ] **Step 9: Lint**

```bash
composer cs && bin/console cache:warmup -q && composer stan && composer md
```
Expected: clean.

- [ ] **Step 10: Commit**

```bash
git add backend/src/Service/Passkey backend/tests/Service/Passkey
git commit -m "feat(#727): name an unknown passkey credential id with its own exception"
```

---

### Task 2: `LoginFailureHandler` lets the unknown-credential type through

**Files:**
- Modify: `backend/src/Security/LoginFailureHandler.php` (class docblock and the `match`, ~lines 20-45)
- Modify: `backend/tests/Controller/Api/PasskeyLoginTest.php` (`testAnExpiredHandleIsRejected` ~line 161, `testACredentialIdThatWasNeverEnrolledIsRejected` ~line 184, `testATamperedSignatureIsRejected` ~line 332, and the docblock above `testADisabledInstanceRejectsLoginWith401NotA500` ~line 531)

**Interfaces:**
- Consumes: `UnknownPasskeyCredentialException` from Task 1. `PasskeyAuthenticator::verifiedUser()` already attaches the `ApiException` as `previous` on the `AuthenticationException` it throws — do not change that class.
- Produces: `POST /api/auth/passkey/login` answers `401 application/problem+json` with `type: unknown_passkey_credential` for an unknown credential id and `type: invalid_credentials` for every other rejection. Task 8 relies on that pair.

- [ ] **Step 1: Add the type assertions to the functional tests.** In `backend/tests/Controller/Api/PasskeyLoginTest.php`:

In `testACredentialIdThatWasNeverEnrolledIsRejected()`, after `$this->assertRejected($client, 401);` add:

```php
        self::assertSame('unknown_passkey_credential', $this->payload($client)['type']);
```

In `testAnExpiredHandleIsRejected()`, after `$this->assertRejected($client, 401);` add:

```php
        // The safety argument for #727: an expired challenge must NOT look
        // like an unknown credential, or the browser would prune a working key.
        self::assertSame('invalid_credentials', $this->payload($client)['type']);
```

In `testATamperedSignatureIsRejected()`, after its `$this->assertRejected($client, 401);` add:

```php
        self::assertSame('invalid_credentials', $this->payload($client)['type']);
```

- [ ] **Step 2: Run the test file to verify the first assertion fails**

```bash
php bin/phpunit tests/Controller/Api/PasskeyLoginTest.php
```
Expected: `testACredentialIdThatWasNeverEnrolledIsRejected` fails (`invalid_credentials` !== `unknown_passkey_credential`); the other two pass.

- [ ] **Step 3: Add the match arm.** In `backend/src/Security/LoginFailureHandler.php` add the import `use App\Service\Passkey\Exception\UnknownPasskeyCredentialException;` and replace the `match` with:

```php
        $previous = $exception->getPrevious();
        $apiException = match (true) {
            $previous instanceof UnknownPasskeyCredentialException => $previous,
            $exception instanceof AccountStatusException
                => new AccountNotActiveException($exception->accountStatus),
            $exception instanceof TooManyLoginAttemptsAuthenticationException
                => new RateLimitedException(900),
            default => new InvalidCredentialsException(),
        };
```

Then extend the class docblock by one paragraph:

```php
 * One deliberate exception since #727: a passkey assertion naming a
 * credential id no account holds keeps its own type, so the browser can
 * prune the dead entry. The oracle that accepts is stated on
 * UnknownPasskeyCredentialException; the timing equalizer still runs first.
```

- [ ] **Step 4: Update the stale docblock in the login test.** In the docblock above `testADisabledInstanceRejectsLoginWith401NotA500()` replace

```
     * `LoginFailureHandler` collapses every passkey login failure to the
     * same generic `invalid_credentials` body by design (no-enumeration), so
```

with

```
     * `LoginFailureHandler` collapses every passkey login failure except an
     * unknown credential id (#727) to the same `invalid_credentials` body, so
```

- [ ] **Step 5: Run the test file to verify it passes**

```bash
php bin/phpunit tests/Controller/Api/PasskeyLoginTest.php tests/Controller/Api/LoginTest.php
```
Expected: PASS. If `testACredentialIdThatWasNeverEnrolledIsRejected` still sees `invalid_credentials`, the security layer wrapped the exception once more before the handler: print `get_class($exception->getPrevious())` in the handler once to see the chain, then walk `getPrevious()` until an `UnknownPasskeyCredentialException` is found (the way `LoginTimingEqualizer::isUserNotFound()` walks it) — and record which it was in the commit message.

- [ ] **Step 6: Lint and commit**

```bash
composer cs && composer stan && composer md
git add backend/src/Security/LoginFailureHandler.php backend/tests/Controller/Api/PasskeyLoginTest.php
git commit -m "feat(#727): answer an unknown passkey credential with its own problem type"
```

---

### Task 3: `PasskeyRemoval` service takes the delete out of the controller

**Files:**
- Create: `backend/src/Service/Passkey/PasskeyRemoval.php`
- Create: `backend/src/Service/Passkey/Exception/PasskeyNotFoundException.php`
- Create: `backend/tests/Service/Passkey/Exception/PasskeyNotFoundExceptionTest.php`
- Modify: `backend/src/Controller/Api/PasskeyController.php` (constructor, `delete()`, imports)
- Modify: `backend/tests/Controller/Api/PasskeyListTest.php` (`testDeletingAnotherUsersCredentialReturns404NotFound`, ~line 95)

**Interfaces:**
- Consumes: `UserPasskeyRepository::findOneForUser(User, int): ?UserPasskey`, `PasskeyRemovalPolicy::guardRemoval(User, UserPasskey): void` (throws `LastSignInMethodException`, 409).
- Produces: `App\Service\Passkey\PasskeyRemoval::remove(User $user, int $id): void`; `App\Service\Passkey\Exception\PasskeyNotFoundException` (404, `type = 'passkey_not_found'`, `title = 'No such passkey'`). After this task `PasskeyController` has 7 constructor parameters: `RegistrationOptionsFactory`, `AssertionOptionsFactory`, `AttestationVerifier`, `UserPasskeyRepository`, `RateLimitGuard`, `RateLimiterFactoryInterface`, `PasskeySignInAvailability`, plus `PasskeyRemoval` = 8. (Task 4 swaps the repository for `PasskeyListing`, staying at 8.)

- [ ] **Step 1: Write the failing exception test** at `backend/tests/Service/Passkey/Exception/PasskeyNotFoundExceptionTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Service\Passkey\Exception;

use App\Service\Passkey\Exception\PasskeyNotFoundException;
use PHPUnit\Framework\TestCase;

final class PasskeyNotFoundExceptionTest extends TestCase
{
    public function testItIsA404WithAFixedTypeAndTitle(): void
    {
        $exception = new PasskeyNotFoundException();

        self::assertSame('passkey_not_found', $exception->type);
        self::assertSame(404, $exception->status);
        self::assertSame('No such passkey', $exception->title);
        self::assertNull($exception->detail);
    }
}
```

- [ ] **Step 2: Tighten the foreign-delete functional test.** In `backend/tests/Controller/Api/PasskeyListTest.php`, `testDeletingAnotherUsersCredentialReturns404NotFound()`, after `self::assertResponseStatusCodeSame(404);` add:

```php
        self::assertSame('application/problem+json', $client->getResponse()->headers->get('Content-Type'));
        self::assertSame('passkey_not_found', $this->payload($client)['type']);
```

- [ ] **Step 3: Run to verify failure**

```bash
php bin/phpunit tests/Service/Passkey/Exception/PasskeyNotFoundExceptionTest.php tests/Controller/Api/PasskeyListTest.php
```
Expected: exception test errors (class missing); the 404 test fails on the `type` assertion.

- [ ] **Step 4: Create the exception** at `backend/src/Service/Passkey/Exception/PasskeyNotFoundException.php`:

```php
<?php

declare(strict_types=1);

namespace App\Service\Passkey\Exception;

use App\Exception\ApiException;

/**
 * No passkey with this id belongs to the caller. Also the answer for another
 * account's id: a 403 there would confirm the id exists (see PasskeyRemoval).
 */
final class PasskeyNotFoundException extends ApiException
{
    public function __construct()
    {
        parent::__construct('passkey_not_found', 404, 'No such passkey');
    }
}
```

- [ ] **Step 5: Create the service** at `backend/src/Service/Passkey/PasskeyRemoval.php`:

```php
<?php

declare(strict_types=1);

namespace App\Service\Passkey;

use App\Entity\User;
use App\Repository\UserPasskeyRepository;
use App\Service\Passkey\Exception\LastSignInMethodException;
use App\Service\Passkey\Exception\PasskeyNotFoundException;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Removes one of $user's own passkeys (#624; extracted from PasskeyController
 * in #727). The lookup is `(id, user)` in one query, never fetch-by-id then
 * compare owner: a foreign id answers 404, indistinguishable from an id that
 * was never registered, so no 403 can confirm another account's credential.
 */
final readonly class PasskeyRemoval
{
    public function __construct(
        private UserPasskeyRepository $passkeys,
        private PasskeyRemovalPolicy $policy,
        private EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * @throws PasskeyNotFoundException
     * @throws LastSignInMethodException
     */
    public function remove(User $user, int $id): void
    {
        $passkey = $this->passkeys->findOneForUser($user, $id) ?? throw new PasskeyNotFoundException();

        $this->policy->guardRemoval($user, $passkey);

        $this->entityManager->remove($passkey);
        $this->entityManager->flush();
    }
}
```

- [ ] **Step 6: Delegate from the controller.** In `backend/src/Controller/Api/PasskeyController.php`:

Remove these imports: `App\Service\Passkey\PasskeyRemovalPolicy`, `Doctrine\ORM\EntityManagerInterface`, `Symfony\Component\HttpKernel\Exception\NotFoundHttpException`. Add `use App\Service\Passkey\PasskeyRemoval;`.

Replace the constructor with:

```php
    public function __construct(
        private RegistrationOptionsFactory $registrationOptionsFactory,
        private AssertionOptionsFactory $assertionOptionsFactory,
        private AttestationVerifier $attestationVerifier,
        private UserPasskeyRepository $passkeys,
        private PasskeyRemoval $removal,
        private RateLimitGuard $rateLimitGuard,
        private RateLimiterFactoryInterface $passkeyChallengeLimiter,
        private PasskeySignInAvailability $availability,
    ) {
    }
```

Replace the `delete()` action and its docblock with:

```php
    /** Own credential 204; a foreign or unknown id 404 — see PasskeyRemoval. */
    #[Route(
        '/api/auth/passkeys/{id}',
        name: 'api_auth_passkeys_delete',
        methods: ['DELETE'],
        requirements: ['id' => '\d+'],
    )]
    public function delete(int $id, #[CurrentUser] User $user): JsonResponse
    {
        $this->removal->remove($user, $id);

        return new JsonResponse(null, Response::HTTP_NO_CONTENT);
    }
```

The class docblock's sentences about `delete()` staying unguarded remain true and stay.

- [ ] **Step 7: Run the tests**

```bash
php bin/phpunit tests/Service/Passkey/Exception/PasskeyNotFoundExceptionTest.php tests/Controller/Api/PasskeyListTest.php tests/Service/Passkey/PasskeyRemovalPolicyTest.php
```
Expected: PASS, including the 204 / 404 / 409 delete cases and the "delete still works while sign-in is disabled" case.

- [ ] **Step 8: Lint and commit**

```bash
composer cs && bin/console cache:warmup -q && composer stan && composer md && composer tramp
git add backend/src/Controller/Api/PasskeyController.php backend/src/Service/Passkey backend/tests/Service/Passkey backend/tests/Controller/Api/PasskeyListTest.php
git commit -m "refactor(#727): move passkey removal out of the controller into PasskeyRemoval"
```

---

### Task 4: Widen the listing (`PasskeyJson::listing()` + `PasskeyListing`)

**Files:**
- Modify: `backend/src/Http/PasskeyJson.php` (whole file)
- Create: `backend/src/Service/Passkey/PasskeyListing.php`
- Modify: `backend/src/Controller/Api/PasskeyController.php` (constructor, `register()`, `list()`, imports)
- Modify: `backend/tests/Controller/Api/PasskeyListTest.php` (`testListingExposesOnlyIdLabelCreatedAtAndLastUsedAt` ~line 31, plus two new tests)
- Modify: `backend/tests/Controller/Api/PasskeyRegistrationTest.php` (`testAValidAttestationStoresACredentialAndListsIt` ~line 176)

**Interfaces:**
- Consumes: `PasskeyRelyingParty::id(): string` (interface in `App\Service\Settings`, autowired), `UserPasskeyRepository::findForUser(User): list<UserPasskey>` (ordered by `createdAt ASC`), `UserPasskey::getUserHandle(): string`, `UserPasskey::getCredentialId(): string`.
- Produces: `PasskeyJson::listing(string $relyingPartyId, array $passkeys): array` and `PasskeyListing::forUser(User $user): array`, both returning `{rpId: string, userHandle: ?string, acceptedCredentialIds: list<string>, passkeys: list<row>}`. `GET /api/auth/passkeys` and the 201 of `POST /api/auth/passkey/register` return that body. Task 6 consumes it as `PasskeyListingJson`.

- [ ] **Step 1: Rewrite the key-pinning test and add two.** In `backend/tests/Controller/Api/PasskeyListTest.php` add the imports `use App\Tests\Support\PinsPasskeyRelyingParty;` and use the trait next to `TogglesPasskeySignIn` (`use TogglesPasskeySignIn; use PinsPasskeyRelyingParty;`). Then replace `testListingExposesOnlyIdLabelCreatedAtAndLastUsedAt()` and its docblock with:

```php
    /**
     * Pinned so a future addition cannot silently widen the payload. Since
     * #727 the body carries exactly the three values the WebAuthn Signal API
     * needs beside the rows: the relying party id, the account's user handle,
     * and every accepted credential id as one flat list. The public key stays
     * out; the rows themselves are unchanged.
     */
    public function testListingCarriesTheSignalValuesBesideTheUnchangedRows(): void
    {
        $client = static::createClient();
        $user = $this->factory()->create('lister@example.test');
        $this->givenAPasskeyFor($user, credentialId: 'Y3JlZC1hYmM', userHandle: 'aGFuZGxl', label: 'My phone');
        $this->authenticate($client, 'lister@example.test');
        $this->pinRelyingParty('example.test', 'Example Reader', 'https://example.test');

        $client->request('GET', '/api/auth/passkeys');

        self::assertResponseIsSuccessful();
        $body = $this->payload($client);
        $topLevelKeys = array_keys($body);
        sort($topLevelKeys);
        self::assertSame(['acceptedCredentialIds', 'passkeys', 'rpId', 'userHandle'], $topLevelKeys);
        self::assertSame('example.test', $body['rpId']);
        self::assertSame('aGFuZGxl', $body['userHandle']);
        self::assertSame(['Y3JlZC1hYmM'], $body['acceptedCredentialIds']);
        $passkeys = $this->passkeysFromResponse($client);
        self::assertCount(1, $passkeys);
        $rowKeys = array_keys($passkeys[0]);
        sort($rowKeys);
        self::assertSame(['createdAt', 'id', 'label', 'lastUsedAt'], $rowKeys);
        self::assertSame('My phone', $passkeys[0]['label']);
    }

    /**
     * The handle is read off the rows, never minted: a minted handle matches
     * nothing in the browser and turns the signal into a silent no-op. With
     * no rows there is no handle, and the client falls back to the one it
     * remembered from before the delete.
     */
    public function testListingReportsNoUserHandleAndNoAcceptedIdsForAnAccountWithoutPasskeys(): void
    {
        $client = static::createClient();
        $this->factory()->create('empty@example.test');
        $this->authenticate($client, 'empty@example.test');
        $this->enablePasskeySignIn();

        $client->request('GET', '/api/auth/passkeys');

        self::assertResponseIsSuccessful();
        $body = $this->payload($client);
        self::assertNull($body['userHandle']);
        self::assertSame([], $body['acceptedCredentialIds']);
        self::assertSame([], $body['passkeys']);
    }

    public function testAcceptedCredentialIdsListEveryStoredCredentialInCreationOrder(): void
    {
        $client = static::createClient();
        $user = $this->factory()->create('two@example.test');
        $this->givenAPasskeyFor($user, credentialId: 'Zmlyc3Q', label: 'First');
        $this->givenAPasskeyFor($user, credentialId: 'c2Vjb25k', label: 'Second');
        $this->authenticate($client, 'two@example.test');
        $this->enablePasskeySignIn();

        $client->request('GET', '/api/auth/passkeys');

        self::assertResponseIsSuccessful();
        self::assertSame(['Zmlyc3Q', 'c2Vjb25k'], $this->payload($client)['acceptedCredentialIds']);
    }
```

`givenAPasskeyFor()` stamps `new \DateTimeImmutable()` on each row; two rows created in one test can share a second. If the order test flakes, give the helper an optional `?\DateTimeImmutable $createdAt = null` parameter and pass `new \DateTimeImmutable('2026-01-01')` / `('2026-01-02')` from this test only.

- [ ] **Step 2: Assert the 201 body in the registration test.** In `backend/tests/Controller/Api/PasskeyRegistrationTest.php`, `testAValidAttestationStoresACredentialAndListsIt()`, after `self::assertSame('My phone', $passkeys[0]['label']);` add:

```php
        $body = $this->payload($client);
        self::assertSame('example.test', $body['rpId']);
        self::assertSame($this->onlyStoredPasskeyFor($user)->getUserHandle(), $body['userHandle']);
        self::assertSame([$this->onlyStoredPasskeyFor($user)->getCredentialId()], $body['acceptedCredentialIds']);
```

Check the test pins `example.test` as its relying party a few lines above (every ceremony test in that file does); if it pins a different id, assert that one.

- [ ] **Step 3: Run to verify failure**

```bash
php bin/phpunit tests/Controller/Api/PasskeyListTest.php tests/Controller/Api/PasskeyRegistrationTest.php
```
Expected: the three listing tests and the registration assertion fail on the missing keys.

- [ ] **Step 4: Rewrite `PasskeyJson`** at `backend/src/Http/PasskeyJson.php`:

```php
<?php

declare(strict_types=1);

namespace App\Http;

use App\Entity\UserPasskey;

/**
 * The passkey listing body (#624), widened in #727 with the three values the
 * WebAuthn Signal API needs: the relying party id, the account's user handle,
 * and every accepted credential id as ONE flat authoritative list the client
 * hands to the browser unchanged — a rebuilt or shortened list makes the
 * browser delete valid credentials. `register/options` already discloses all
 * three to the same authenticated user, so this exposes nothing new.
 *
 * The two options factories already return their body in its final wire
 * shape — the identical `{options, handle}` for both ceremonies — so they
 * need no mapper here and their controller actions hand the array straight
 * to the response.
 *
 * @phpstan-type PasskeyRow array{id: ?int, label: string, createdAt: string, lastUsedAt: ?string}
 * @phpstan-type PasskeyListingBody array{rpId: string, userHandle: ?string, acceptedCredentialIds: list<string>, passkeys: list<PasskeyRow>}
 */
final readonly class PasskeyJson
{
    /**
     * `userHandle` is read off the rows, never minted: the browser ignores a
     * handle that matches nothing, so a fresh one would make the signal a
     * silent no-op. Null with no rows.
     *
     * @param list<UserPasskey> $passkeys
     *
     * @return PasskeyListingBody
     */
    public static function listing(string $relyingPartyId, array $passkeys): array
    {
        return [
            'rpId' => $relyingPartyId,
            'userHandle' => ($passkeys[0] ?? null)?->getUserHandle(),
            'acceptedCredentialIds' => array_map(
                static fn (UserPasskey $passkey): string => $passkey->getCredentialId(),
                $passkeys,
            ),
            'passkeys' => array_map(self::passkey(...), $passkeys),
        ];
    }

    /**
     * @return PasskeyRow
     */
    private static function passkey(UserPasskey $passkey): array
    {
        return [
            'id' => $passkey->getId(),
            'label' => $passkey->getLabel(),
            'createdAt' => $passkey->getCreatedAt()->format(\DateTimeInterface::ATOM),
            'lastUsedAt' => $passkey->getLastUsedAt()?->format(\DateTimeInterface::ATOM),
        ];
    }
}
```

The old `passkeys()` method is gone; its two callers change in Step 6.

- [ ] **Step 5: Create the service** at `backend/src/Service/Passkey/PasskeyListing.php`:

```php
<?php

declare(strict_types=1);

namespace App\Service\Passkey;

use App\Entity\User;
use App\Http\PasskeyJson;
use App\Repository\UserPasskeyRepository;
use App\Service\Settings\PasskeyRelyingParty;

/**
 * Builds the passkey listing body for one account (#727) — the same body the
 * listing and the enrolment 201 return, so the two cannot drift.
 *
 * @phpstan-import-type PasskeyListingBody from PasskeyJson
 */
final readonly class PasskeyListing
{
    public function __construct(
        private UserPasskeyRepository $passkeys,
        private PasskeyRelyingParty $relyingParty,
    ) {
    }

    /**
     * @return PasskeyListingBody
     */
    public function forUser(User $user): array
    {
        return PasskeyJson::listing($this->relyingParty->id(), $this->passkeys->findForUser($user));
    }
}
```

- [ ] **Step 6: Point the controller at it.** In `backend/src/Controller/Api/PasskeyController.php`:

Remove the imports `App\Http\PasskeyJson` and `App\Repository\UserPasskeyRepository`; add `use App\Service\Passkey\PasskeyListing;`. In the constructor replace `private UserPasskeyRepository $passkeys,` with `private PasskeyListing $listing,`. Then:

```php
    /**
     * @throws InvalidArgumentException
     */
    #[Route('/api/auth/passkey/register', name: 'api_auth_passkey_register', methods: ['POST'])]
    public function register(
        #[CurrentUser] User $user,
        #[MapRequestPayload] RegisterPasskeyRequest $request,
    ): JsonResponse {
        $this->availability->guard();
        $this->attestationVerifier->verifyAndStore($user, $request);

        return new JsonResponse($this->listing->forUser($user), Response::HTTP_CREATED);
    }

    #[Route('/api/auth/passkeys', name: 'api_auth_passkeys_list', methods: ['GET'])]
    public function list(#[CurrentUser] User $user): JsonResponse
    {
        $this->availability->guard();

        return new JsonResponse($this->listing->forUser($user));
    }
```

- [ ] **Step 7: Run the tests**

```bash
php bin/phpunit tests/Controller/Api/PasskeyListTest.php tests/Controller/Api/PasskeyRegistrationTest.php tests/Controller/Api/PasskeyLoginTest.php
```
Expected: PASS.

- [ ] **Step 8: Lint and commit**

```bash
composer cs && bin/console cache:warmup -q && composer stan && composer md && composer tramp
git add backend/src/Http/PasskeyJson.php backend/src/Service/Passkey/PasskeyListing.php backend/src/Controller/Api/PasskeyController.php backend/tests/Controller/Api/PasskeyListTest.php backend/tests/Controller/Api/PasskeyRegistrationTest.php
git commit -m "feat(#727): carry rpId, userHandle and every accepted credential id in the passkey listing"
```

- [ ] **Step 9: Run the whole backend suite natively, then the MySQL leg**

From `backend/`:
```bash
php bin/phpunit
```
From the repo root (only after the native run has finished):
```bash
docker compose exec php vendor/bin/phpunit
```
Expected: both green. Scan the dev log for new deprecations: `ls -t backend/var/log/dev-*.log | head -1 | xargs grep -c -i "deprecat"` and compare with the count before your changes.

---

### Task 5: Signal API helpers in `core/webauthn.ts`

**Files:**
- Modify: `frontend/src/app/core/webauthn.ts` (append after `isConditionalMediationSupported()`)
- Modify: `frontend/src/app/core/webauthn.spec.ts` (append two `describe` blocks)

**Interfaces:**
- Consumes: `isPasskeySupported()` from the same file.
- Produces: `signalUnknownCredential(rpId: string, credentialId: string): Promise<void>` and `signalAllAcceptedCredentials(rpId: string, userId: string, allAcceptedCredentialIds: string[]): Promise<void>`. Both always resolve. Tasks 6-8 call them.

- [ ] **Step 1: Write the failing specs.** Append to `frontend/src/app/core/webauthn.spec.ts` (add `signalAllAcceptedCredentials, signalUnknownCredential` to the existing import from `./webauthn`):

```ts
type WindowWithCredential = { PublicKeyCredential?: unknown };

describe('signalUnknownCredential', () => {
  afterEach(() => {
    delete (window as unknown as WindowWithCredential).PublicKeyCredential;
  });

  it('resolves when PublicKeyCredential is absent', async () => {
    await expect(signalUnknownCredential('test', 'Y3JlZA')).resolves.toBeUndefined();
  });

  it('resolves when the browser has no signalUnknownCredential method', async () => {
    (window as unknown as WindowWithCredential).PublicKeyCredential = {};
    await expect(signalUnknownCredential('test', 'Y3JlZA')).resolves.toBeUndefined();
  });

  it('resolves when the browser call rejects', async () => {
    (window as unknown as WindowWithCredential).PublicKeyCredential = {
      signalUnknownCredential: jest.fn().mockRejectedValue(new Error('boom')),
    };
    await expect(signalUnknownCredential('test', 'Y3JlZA')).resolves.toBeUndefined();
  });

  it('hands the browser the rp id and the credential id exactly as given', async () => {
    const signal = jest.fn().mockResolvedValue(undefined);
    (window as unknown as WindowWithCredential).PublicKeyCredential = {
      signalUnknownCredential: signal,
    };

    await signalUnknownCredential('test', 'Y3JlZA');

    expect(signal).toHaveBeenCalledWith({ rpId: 'test', credentialId: 'Y3JlZA' });
  });
});

describe('signalAllAcceptedCredentials', () => {
  afterEach(() => {
    delete (window as unknown as WindowWithCredential).PublicKeyCredential;
  });

  it('resolves when PublicKeyCredential is absent', async () => {
    await expect(signalAllAcceptedCredentials('test', 'aGFuZGxl', ['a'])).resolves.toBeUndefined();
  });

  it('resolves when the browser has no signalAllAcceptedCredentials method', async () => {
    (window as unknown as WindowWithCredential).PublicKeyCredential = {};
    await expect(signalAllAcceptedCredentials('test', 'aGFuZGxl', ['a'])).resolves.toBeUndefined();
  });

  it('resolves when the browser call rejects', async () => {
    (window as unknown as WindowWithCredential).PublicKeyCredential = {
      signalAllAcceptedCredentials: jest.fn().mockRejectedValue(new Error('boom')),
    };
    await expect(signalAllAcceptedCredentials('test', 'aGFuZGxl', ['a'])).resolves.toBeUndefined();
  });

  it('hands the browser the handle and the list exactly as given', async () => {
    const signal = jest.fn().mockResolvedValue(undefined);
    (window as unknown as WindowWithCredential).PublicKeyCredential = {
      signalAllAcceptedCredentials: signal,
    };

    await signalAllAcceptedCredentials('test', 'aGFuZGxl', ['Zmlyc3Q', 'c2Vjb25k']);

    expect(signal).toHaveBeenCalledWith({
      rpId: 'test',
      userId: 'aGFuZGxl',
      allAcceptedCredentialIds: ['Zmlyc3Q', 'c2Vjb25k'],
    });
  });

  // An empty list is how the last passkey's deletion reaches the browser; it
  // must go through unchanged, never be treated as "nothing to send".
  it('passes an empty list through unchanged', async () => {
    const signal = jest.fn().mockResolvedValue(undefined);
    (window as unknown as WindowWithCredential).PublicKeyCredential = {
      signalAllAcceptedCredentials: signal,
    };

    await signalAllAcceptedCredentials('test', 'aGFuZGxl', []);

    expect(signal).toHaveBeenCalledWith({
      rpId: 'test',
      userId: 'aGFuZGxl',
      allAcceptedCredentialIds: [],
    });
  });
});
```

- [ ] **Step 2: Run to verify failure**

From the repo root:
```bash
docker compose exec -T frontend npx jest src/app/core/webauthn.spec.ts
```
Expected: FAIL — the two functions are not exported.

- [ ] **Step 3: Implement.** Append to `frontend/src/app/core/webauthn.ts`:

```ts
/** The two WebAuthn L3 Signal API statics (#727). TypeScript 5.9's lib.dom
 *  does not declare them. Every id here is already a base64url string --
 *  the encoding the backend stores -- so nothing is converted. */
interface SignalMethods {
  signalUnknownCredential?: (options: { rpId: string; credentialId: string }) => Promise<void>;
  signalAllAcceptedCredentials?: (options: {
    rpId: string;
    userId: string;
    allAcceptedCredentialIds: string[];
  }) => Promise<void>;
}

function signalMethods(): SignalMethods {
  return isPasskeySupported() ? (window.PublicKeyCredential as unknown as SignalMethods) : {};
}

/** Tells the browser a credential id the server does not know, so the
 *  password manager can drop it. Best-effort by design: resolves without
 *  effect when the API is absent or the browser throws, so it can never gate
 *  a sign-in or an enrolment. */
export async function signalUnknownCredential(rpId: string, credentialId: string): Promise<void> {
  const methods = signalMethods();
  if (typeof methods.signalUnknownCredential !== 'function') return;
  try {
    await methods.signalUnknownCredential({ rpId, credentialId });
  } catch {
    // Best-effort by design -- see the docblock.
  }
}

/** Hands the browser one account's authoritative credential set so anything
 *  stale disappears. The list must be complete and unfiltered: a short or
 *  empty list makes the browser delete valid credentials for that user. */
export async function signalAllAcceptedCredentials(
  rpId: string,
  userId: string,
  allAcceptedCredentialIds: string[],
): Promise<void> {
  const methods = signalMethods();
  if (typeof methods.signalAllAcceptedCredentials !== 'function') return;
  try {
    await methods.signalAllAcceptedCredentials({ rpId, userId, allAcceptedCredentialIds });
  } catch {
    // Best-effort by design -- see the docblock.
  }
}
```

Calling the static through `methods.` keeps `this` bound to `PublicKeyCredential`, which is why the method is not first copied into a local.

- [ ] **Step 4: Run to verify pass**

```bash
docker compose exec -T frontend npx jest src/app/core/webauthn.spec.ts
```
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add frontend/src/app/core/webauthn.ts frontend/src/app/core/webauthn.spec.ts
git commit -m "feat(#727): best-effort WebAuthn Signal API helpers"
```

---

### Task 6: `PasskeyService.list()` sweeps stale credentials

**Files:**
- Modify: `frontend/src/app/core/passkey.service.ts` (`list()`, new private state and method, imports)
- Modify: `frontend/src/app/core/passkey.service.spec.ts` (`list unwraps the {passkeys} envelope` ~line 203, plus new tests)

**Interfaces:**
- Consumes: the widened body from Task 4; `signalAllAcceptedCredentials` from Task 5.
- Produces: `list(): Observable<PasskeySummary[]>` — unchanged signature, so `PasskeysGroupComponent` and its spec stay untouched. Internally `PasskeyListingJson` (`rpId`, `userHandle: string | null`, `acceptedCredentialIds: string[]`, `passkeys`).

- [ ] **Step 1: Write the failing specs.** In `frontend/src/app/core/passkey.service.spec.ts`:

Add near the other fixtures:

```ts
interface ListingBody {
  rpId: string;
  userHandle: string | null;
  acceptedCredentialIds: string[];
  passkeys: PasskeySummary[];
}

function listingBody(overrides: Partial<ListingBody> = {}): ListingBody {
  return {
    rpId: 'test',
    userHandle: 'aGFuZGxl',
    acceptedCredentialIds: ['Y3JlZC1hYmM'],
    passkeys: [],
    ...overrides,
  };
}

type WindowWithCredential = { PublicKeyCredential?: unknown };

/** Installs a fake Signal API and returns its spies. jsdom has none, so the
 *  absent-API case is what every test gets without this. */
function installSignalApi(): { unknown: jest.Mock; allAccepted: jest.Mock } {
  const unknown = jest.fn().mockResolvedValue(undefined);
  const allAccepted = jest.fn().mockResolvedValue(undefined);
  (window as unknown as WindowWithCredential).PublicKeyCredential = {
    signalUnknownCredential: unknown,
    signalAllAcceptedCredentials: allAccepted,
  };
  return { unknown, allAccepted };
}
```

In the top-level `afterEach`, add `delete (window as unknown as WindowWithCredential).PublicKeyCredential;`.

Replace the existing `list unwraps the {passkeys} envelope` test body's flush so it sends the full body:

```ts
  it('list unwraps the {passkeys} envelope', () => {
    const passkeys: PasskeySummary[] = [
      { id: 1, label: 'Phone', createdAt: '2026-08-01T00:00:00Z', lastUsedAt: null },
    ];
    let received: PasskeySummary[] | undefined;

    svc.list().subscribe((list) => (received = list));
    ctrl.expectOne('https://api.test/api/auth/passkeys').flush(listingBody({ passkeys }));

    expect(received).toEqual(passkeys);
  });
```

(Keep whatever the existing test already asserts if it differs only in fixture values; the point is that it now flushes `listingBody(...)`.)

Add a `describe` block:

```ts
  describe('pruning stale passkeys from the browser on every listing', () => {
    it('hands the browser the authoritative set exactly as the server sent it', async () => {
      const { allAccepted } = installSignalApi();

      svc.list().subscribe();
      ctrl.expectOne('https://api.test/api/auth/passkeys').flush(
        listingBody({ acceptedCredentialIds: ['Zmlyc3Q', 'c2Vjb25k'] }),
      );
      await flushMicrotasks();

      expect(allAccepted).toHaveBeenCalledWith({
        rpId: 'test',
        userId: 'aGFuZGxl',
        allAcceptedCredentialIds: ['Zmlyc3Q', 'c2Vjb25k'],
      });
    });

    // After the LAST passkey is deleted the server has no handle to send, and
    // the sweep needs one exactly then. The handle from the listing before
    // the delete is the key those credentials were created under.
    it('uses the remembered handle when the account has no passkeys left', async () => {
      const { allAccepted } = installSignalApi();

      svc.list().subscribe();
      ctrl.expectOne('https://api.test/api/auth/passkeys').flush(listingBody());
      svc.list().subscribe();
      ctrl
        .expectOne('https://api.test/api/auth/passkeys')
        .flush(listingBody({ userHandle: null, acceptedCredentialIds: [] }));
      await flushMicrotasks();

      expect(allAccepted).toHaveBeenLastCalledWith({
        rpId: 'test',
        userId: 'aGFuZGxl',
        allAcceptedCredentialIds: [],
      });
    });

    it('signals nothing when no handle was ever seen', async () => {
      const { allAccepted } = installSignalApi();

      svc.list().subscribe();
      ctrl
        .expectOne('https://api.test/api/auth/passkeys')
        .flush(listingBody({ userHandle: null, acceptedCredentialIds: [] }));
      await flushMicrotasks();

      expect(allAccepted).not.toHaveBeenCalled();
    });

    it('still delivers the rows when the browser rejects the signal', async () => {
      const { allAccepted } = installSignalApi();
      allAccepted.mockRejectedValue(new Error('boom'));
      const passkeys: PasskeySummary[] = [
        { id: 1, label: 'Phone', createdAt: '2026-08-01T00:00:00Z', lastUsedAt: null },
      ];
      let received: PasskeySummary[] | undefined;

      svc.list().subscribe((list) => (received = list));
      ctrl.expectOne('https://api.test/api/auth/passkeys').flush(listingBody({ passkeys }));
      await flushMicrotasks();

      expect(received).toEqual(passkeys);
    });
  });
```

- [ ] **Step 2: Run to verify failure**

```bash
docker compose exec -T frontend npx jest src/app/core/passkey.service.spec.ts
```
Expected: the three signalling tests fail (`allAccepted` never called); the rows tests pass.

- [ ] **Step 3: Implement.** In `frontend/src/app/core/passkey.service.ts`:

Change the rxjs import to `import { Observable, firstValueFrom, map, tap } from 'rxjs';` and the webauthn import to `import { base64UrlToBytes, bytesToBase64Url, signalAllAcceptedCredentials } from './webauthn';`.

Add after the `PasskeySummary` interface:

```ts
/** The listing body since #727 -- see `PasskeyJson::listing()`. The id list
 *  is authoritative and goes to the browser unchanged. */
interface PasskeyListingJson {
  rpId: string;
  userHandle: string | null;
  acceptedCredentialIds: string[];
  passkeys: PasskeySummary[];
}

interface SignalSubject {
  rpId: string;
  userHandle: string;
}
```

Add a field below `private readonly tokens = inject(TokenStore);`:

```ts
  /** The last handle seen, so the sweep after deleting the LAST passkey still
   *  has the key those credentials were created under: that response carries
   *  `userHandle: null` and an empty list. */
  private signalSubject: SignalSubject | null = null;
```

Replace `list()`:

```ts
  list(): Observable<PasskeySummary[]> {
    return this.http.get<PasskeyListingJson>(`${this.base}/api/auth/passkeys`).pipe(
      tap((listing) => this.pruneStaleCredentials(listing)),
      map((listing) => listing.passkeys),
    );
  }
```

Add a private method after `getCredential()`:

```ts
  /** Fire-and-forget (#727): the browser drops every entry outside the
   *  server's list. The list is passed through exactly as received -- a
   *  rebuilt or shortened one would delete valid credentials. */
  private pruneStaleCredentials(listing: PasskeyListingJson): void {
    if (listing.userHandle !== null) {
      this.signalSubject = { rpId: listing.rpId, userHandle: listing.userHandle };
    }
    if (!this.signalSubject) return;
    void signalAllAcceptedCredentials(
      this.signalSubject.rpId,
      this.signalSubject.userHandle,
      listing.acceptedCredentialIds,
    );
  }
```

- [ ] **Step 4: Run to verify pass**

```bash
docker compose exec -T frontend npx jest src/app/core/passkey.service.spec.ts src/app/settings/passkeys-group.component.spec.ts
```
Expected: PASS, including the untouched group spec.

- [ ] **Step 5: Commit**

```bash
git add frontend/src/app/core/passkey.service.ts frontend/src/app/core/passkey.service.spec.ts
git commit -m "feat(#727): sweep stale passkeys from the browser on every listing"
```

---

### Task 7: A server-refused enrolment signals the unknown credential

**Files:**
- Modify: `frontend/src/app/core/passkey.service.ts` (`enrol()`, new private `register()`, a module-level predicate)
- Modify: `frontend/src/app/core/passkey.service.spec.ts` (new tests)

**Interfaces:**
- Consumes: `signalUnknownCredential` from Task 5; `installSignalApi()` and `flushMicrotasks()` from the spec.
- Produces: `enrol()` behaviour: on an `HttpErrorResponse` with status 400-499 from `POST /api/auth/passkey/register`, and only then, `signalUnknownCredential(options.rp.id, credential.id)` runs before the `Problem` is rethrown.

- [ ] **Step 1: Write the failing specs.** Add to `frontend/src/app/core/passkey.service.spec.ts` a `describe` block:

```ts
  describe('a credential the server refused', () => {
    /** Runs the options + ceremony half of `enrol()` and returns the pending
     *  register request, so each test decides only how the server answers. */
    async function enrolUpToRegister(): Promise<{ enrolment: Promise<void>; register: TestRequest }> {
      create.mockResolvedValue(fixtureAttestationCredential());
      const enrolment = svc.enrol('MacBook Touch ID');
      ctrl
        .expectOne('https://api.test/api/auth/passkey/register/options')
        .flush({ options: creationOptions, handle: 'register-handle' });
      await flushMicrotasks();
      return { enrolment, register: ctrl.expectOne('https://api.test/api/auth/passkey/register') };
    }

    // The authenticator already holds the credential the server just refused
    // (#727); without the signal the sign-in sheet offers it forever.
    it('tells the browser to drop it on a 4xx from register', async () => {
      const { unknown } = installSignalApi();
      const { enrolment, register } = await enrolUpToRegister();

      register.flush(
        { type: 'passkey_attestation_rejected', title: 'Rejected', status: 400 },
        { status: 400, statusText: 'Bad Request' },
      );

      await expect(enrolment).rejects.toMatchObject({ status: 400 });
      expect(unknown).toHaveBeenCalledWith({ rpId: 'test', credentialId: 'credential-id' });
    });

    // A lost response is not a refusal: the row may exist, and the signal is
    // irreversible.
    it('leaves the browser alone on a network failure during register', async () => {
      const { unknown } = installSignalApi();
      const { enrolment, register } = await enrolUpToRegister();

      register.error(new ProgressEvent('error'), { status: 0, statusText: 'Unknown Error' });

      await expect(enrolment).rejects.toMatchObject({ status: 0 });
      expect(unknown).not.toHaveBeenCalled();
    });

    it('leaves the browser alone on a 5xx from register', async () => {
      const { unknown } = installSignalApi();
      const { enrolment, register } = await enrolUpToRegister();

      register.flush(
        { type: 'about:blank', title: 'Server error', status: 500 },
        { status: 500, statusText: 'Internal Server Error' },
      );

      await expect(enrolment).rejects.toMatchObject({ status: 500 });
      expect(unknown).not.toHaveBeenCalled();
    });

    // A rejected ceremony created no credential, so there is nothing to prune.
    it('signals nothing when the ceremony itself was rejected', async () => {
      const { unknown } = installSignalApi();
      create.mockRejectedValue(new DOMException('User cancelled.', 'NotAllowedError'));

      const enrolment = svc.enrol('MacBook Touch ID');
      ctrl
        .expectOne('https://api.test/api/auth/passkey/register/options')
        .flush({ options: creationOptions, handle: 'register-handle' });

      await expect(enrolment).rejects.toMatchObject({ type: 'NotAllowedError' });
      expect(unknown).not.toHaveBeenCalled();
    });

    it('still surfaces the Problem when the browser rejects the signal', async () => {
      const { unknown } = installSignalApi();
      unknown.mockRejectedValue(new Error('boom'));
      const { enrolment, register } = await enrolUpToRegister();

      register.flush(
        { type: 'passkey_attestation_rejected', title: 'Rejected', status: 400 },
        { status: 400, statusText: 'Bad Request' },
      );

      await expect(enrolment).rejects.toMatchObject({ status: 400, title: 'Rejected' });
    });
  });
```

Add `TestRequest` to the import from `@angular/common/http/testing`.

- [ ] **Step 2: Run to verify failure**

```bash
docker compose exec -T frontend npx jest src/app/core/passkey.service.spec.ts
```
Expected: the 4xx test fails (`unknown` not called); the rest pass.

- [ ] **Step 3: Implement.** In `frontend/src/app/core/passkey.service.ts`:

Extend the webauthn import: `import { base64UrlToBytes, bytesToBase64Url, signalAllAcceptedCredentials, signalUnknownCredential } from './webauthn';` (Prettier will wrap it).

Add after `AssertionCredentialJson`:

```ts
/** The `register` request body -- see `RegisterPasskeyRequest`. */
interface RegistrationBody {
  handle: string;
  credential: RegistrationCredentialJson;
  label: string;
}
```

Replace `enrol()`:

```ts
  async enrol(label: string): Promise<void> {
    try {
      const { options, handle } = await firstValueFrom(
        this.http.post<CeremonyOptions<PublicKeyCredentialCreationOptionsJSON>>(
          `${this.base}/api/auth/passkey/register/options`,
          {},
        ),
      );
      const credential = await this.createCredential(options);
      await this.register(options.rp.id, { handle, credential, label });
    } catch (error) {
      throw this.toProblem(error);
    }
  }
```

Add a private method after `createCredential()`:

```ts
  /** The authenticator already holds the credential when the server refuses
   *  it (#727), so a 4xx tells the browser to drop it. Not on status 0 or a
   *  5xx: the row may exist and only the response was lost. */
  private async register(rpId: string | undefined, body: RegistrationBody): Promise<void> {
    try {
      await firstValueFrom(this.http.post<void>(`${this.base}/api/auth/passkey/register`, body));
    } catch (error) {
      if (rpId && isClientRejection(error)) {
        await signalUnknownCredential(rpId, body.credential.id);
      }
      throw error;
    }
  }
```

Add a module-level function below the class (next to `decodeCreationOptions`):

```ts
function isClientRejection(error: unknown): boolean {
  return error instanceof HttpErrorResponse && error.status >= 400 && error.status < 500;
}
```

- [ ] **Step 4: Run to verify pass**

```bash
docker compose exec -T frontend npx jest src/app/core/passkey.service.spec.ts
```
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add frontend/src/app/core/passkey.service.ts frontend/src/app/core/passkey.service.spec.ts
git commit -m "feat(#727): prune a credential the server refused at enrolment"
```

---

### Task 8: A login with an unknown credential id signals it

**Files:**
- Modify: `frontend/src/app/core/passkey.service.ts` (`login()`, new private `exchangeAssertion()`, a module-level predicate and constant)
- Modify: `frontend/src/app/core/passkey.service.spec.ts` (new tests)

**Interfaces:**
- Consumes: the problem type `unknown_passkey_credential` from Task 2; `signalUnknownCredential` from Task 5; `parseProblem` from `./problem`.
- Produces: both `signIn()` and `signInConditionally()` call `signalUnknownCredential(options.rpId, credential.id)` when, and only when, `POST /api/auth/passkey/login` fails with a problem of type `unknown_passkey_credential` and the options carried an `rpId`.

- [ ] **Step 1: Write the failing specs.** Add to `frontend/src/app/core/passkey.service.spec.ts`:

```ts
  describe('a login with a credential id the server does not know', () => {
    async function signInUpToLogin(): Promise<{ signIn: Promise<string>; login: TestRequest }> {
      get.mockResolvedValue(fixtureAssertionCredential());
      const signIn = svc.signIn();
      ctrl
        .expectOne('https://api.test/api/auth/passkey/login/options')
        .flush({ options: requestOptions, handle: 'login-handle' });
      await flushMicrotasks();
      return { signIn, login: ctrl.expectOne('https://api.test/api/auth/passkey/login') };
    }

    it('tells the browser to drop it on the unknown_passkey_credential type', async () => {
      const { unknown } = installSignalApi();
      const { signIn, login } = await signInUpToLogin();

      login.flush(
        { type: 'unknown_passkey_credential', title: 'Unknown passkey', status: 401 },
        { status: 401, statusText: 'Unauthorized' },
      );

      await expect(signIn).rejects.toMatchObject({ type: 'unknown_passkey_credential' });
      expect(unknown).toHaveBeenCalledWith({ rpId: 'test', credentialId: 'credential-id' });
    });

    // Every other 401 -- an expired challenge above all, likely under
    // conditional mediation -- names a WORKING passkey. Pruning it would be
    // worse than the orphan #727 exists for.
    it('leaves the browser alone on any other 401', async () => {
      const { unknown } = installSignalApi();
      const { signIn, login } = await signInUpToLogin();

      login.flush(
        { type: 'invalid_credentials', title: 'Invalid credentials', status: 401 },
        { status: 401, statusText: 'Unauthorized' },
      );

      await expect(signIn).rejects.toMatchObject({ type: 'invalid_credentials' });
      expect(unknown).not.toHaveBeenCalled();
    });

    // Conditional mediation is the path that keeps re-offering a dead entry,
    // so it matters most that it signals too.
    it('signals from the conditional ceremony as well', async () => {
      const { unknown } = installSignalApi();
      get.mockResolvedValue(fixtureAssertionCredential());

      const signIn = svc.signInConditionally(new AbortController().signal);
      ctrl
        .expectOne('https://api.test/api/auth/passkey/login/options')
        .flush({ options: requestOptions, handle: 'login-handle' });
      await flushMicrotasks();
      ctrl.expectOne('https://api.test/api/auth/passkey/login').flush(
        { type: 'unknown_passkey_credential', title: 'Unknown passkey', status: 401 },
        { status: 401, statusText: 'Unauthorized' },
      );

      await expect(signIn).rejects.toMatchObject({ type: 'unknown_passkey_credential' });
      expect(unknown).toHaveBeenCalledWith({ rpId: 'test', credentialId: 'credential-id' });
    });

    it('still surfaces the Problem when the browser rejects the signal', async () => {
      const { unknown } = installSignalApi();
      unknown.mockRejectedValue(new Error('boom'));
      const { signIn, login } = await signInUpToLogin();

      login.flush(
        { type: 'unknown_passkey_credential', title: 'Unknown passkey', status: 401 },
        { status: 401, statusText: 'Unauthorized' },
      );

      await expect(signIn).rejects.toMatchObject({ status: 401, title: 'Unknown passkey' });
      expect(tokens.token()).toBeNull();
    });
  });
```

If `tokens.token()` returns `undefined` or `''` for "no token" in this codebase, assert that value instead; the point is that no token was stored.

- [ ] **Step 2: Run to verify failure**

```bash
docker compose exec -T frontend npx jest src/app/core/passkey.service.spec.ts
```
Expected: the two "tells the browser" tests fail; the others pass.

- [ ] **Step 3: Implement.** In `frontend/src/app/core/passkey.service.ts`:

Add after `RegistrationBody`:

```ts
/** The `login` request body PasskeyAuthenticator reads. */
interface AssertionBody {
  handle: string;
  credential: AssertionCredentialJson;
}
```

Replace `login()`:

```ts
  private async login(ceremonyOptions: LoginCeremonyOptions): Promise<string> {
    try {
      const { options, handle } = await firstValueFrom(
        this.http.post<CeremonyOptions<PublicKeyCredentialRequestOptionsJSON>>(
          `${this.base}/api/auth/passkey/login/options`,
          {},
        ),
      );
      const credential = await this.getCredential(options, ceremonyOptions);
      const token = await this.exchangeAssertion(options.rpId, { handle, credential });
      this.tokens.set(token);
      return token;
    } catch (error) {
      throw this.toProblem(error);
    }
  }
```

Add a private method after `register()`:

```ts
  /** Signals on the ONE type that means "no account holds this id" (#727).
   *  Any other 401 -- an expired challenge above all -- names a working
   *  passkey, and the signal is irreversible. */
  private async exchangeAssertion(rpId: string | undefined, body: AssertionBody): Promise<string> {
    try {
      const { token } = await firstValueFrom(
        this.http.post<{ token: string }>(`${this.base}/api/auth/passkey/login`, body),
      );
      return token;
    } catch (error) {
      if (rpId && isUnknownCredential(error)) {
        await signalUnknownCredential(rpId, body.credential.id);
      }
      throw error;
    }
  }
```

Add below `isClientRejection()`:

```ts
/** `UnknownPasskeyCredentialException::$type` on the backend. */
const UNKNOWN_PASSKEY_CREDENTIAL = 'unknown_passkey_credential';

function isUnknownCredential(error: unknown): boolean {
  return (
    error instanceof HttpErrorResponse && parseProblem(error).type === UNKNOWN_PASSKEY_CREDENTIAL
  );
}
```

`parseProblem` is already imported. Update the class docblock's first paragraph to mention the new duty, e.g. append one sentence: `Since #727 it is also the only caller of the Signal API helpers in core/webauthn.ts, which prune stale entries from the browser's password manager.`

- [ ] **Step 4: Run the frontend gate**

```bash
docker compose exec -T frontend npx jest src/app/core
docker compose exec -T frontend npm run check
```
Expected: PASS and a clean gate (ESLint, Prettier, Stylelint, Jest with type check). Fix Prettier wraps with `npx prettier --write` on the two files if it complains.

- [ ] **Step 5: Commit**

```bash
git add frontend/src/app/core/passkey.service.ts frontend/src/app/core/passkey.service.spec.ts
git commit -m "feat(#727): prune a passkey the server no longer knows after a failed login"
```

---

### Task 9: Gates, mutation score, real-browser check, pull request

**Files:** none new.

- [ ] **Step 1: Backend gates on the whole branch**

From `backend/`:
```bash
composer check && composer md && composer show larspohlmann/phptramp | head -3
php bin/phpunit
composer infection:diff
```
Expected: clean; `infection:diff` at or above `minMsi: 80`. Escaped mutants in `PasskeyJson::listing()` (e.g. `?? null` removed) or `isClientRejection`-equivalent PHP are killed by the tests above; if one escapes, add the missing assertion to the test that owns that line rather than lowering the ratchet.

- [ ] **Step 2: MySQL leg** (after the native run has finished)

From the repo root:
```bash
docker compose exec php vendor/bin/phpunit
```

- [ ] **Step 3: PhpStorm inspections on the changed PHP** via `mcp__phpstorm__lint_files` for every PHP file in `git diff --name-only develop -- backend`. Block on ERROR and WARNING.

- [ ] **Step 4: Real-browser check** (Chrome 132+ or Safari 26+; Firefox has no Signal API and is the no-op case). In the app at `https://localhost:8443`:
1. Settings → Account → add a passkey, then remove it. Open the browser's passkey manager: the entry is gone without manual deletion.
2. With a passkey enrolled, delete its row directly (`DELETE /api/auth/passkeys/{id}` via the UI on another device, or the Docker MySQL), then sign out and use the sign-in sheet once: the 401 carries `unknown_passkey_credential` in the network tab and the entry disappears from the sheet on the next visit.
3. In Firefox, both flows behave exactly as before and the console shows no error.
Record what was observed in the PR body; if the manager needs a moment to reflect the change, say so.

- [ ] **Step 5: Scan the dev log**

```bash
ls -t backend/var/log/dev-*.log | head -1 | xargs grep -i -E "deprecat|critical|error" | tail -20
```
Expected: nothing new from this branch.

- [ ] **Step 6: Open the pull request** against `develop`:

```bash
git push -u origin feature/727-passkey-signal-api
gh pr create --base develop --title "feat(#727): prune stale passkeys from the browser with the WebAuthn Signal API" --body-file - <<'PR'
Closes #727

Spec: docs/superpowers/specs/2026-09-05-727-passkey-signal-api-design.md

**Backend**
- `unknown_passkey_credential` is the one passkey login failure with its own problem type; every other rejection stays `invalid_credentials`. Proven through the firewall in `PasskeyLoginTest` (unknown id vs. expired handle vs. tampered signature).
- `GET /api/auth/passkeys` (and the enrolment 201) carry `rpId`, `userHandle` (null with no rows, never minted) and a flat authoritative `acceptedCredentialIds`. Discloses nothing `register/options` did not already hand the same user.
- `PasskeyListing` and `PasskeyRemoval` take listing and removal out of `PasskeyController` (PHPMD parameter limit; entity mutation belongs in a service).

**Frontend**
- Two best-effort helpers in `core/webauthn.ts`; `PasskeyService` signals an unknown credential after a 4xx enrolment refusal and after the new login type, and sweeps with the authoritative list on every listing, remembering the handle for the last-passkey delete.
- No-op on browsers without the API (Firefox); a rejected signal never changes a sign-in, an enrolment or a delete.

**Verified**
- <fill in from Task 9 Step 4>
PR
```

Replace the `<fill in>` line with the real observations before submitting.

---

## Self-review against the spec

- Decision 1 (narrowed 401): Tasks 1-2. The docblock amendments the spec names are Steps 1.7, 2.3 and 2.4.
- Decision 2 (widened listing, flat list, handle from rows): Task 4. The enrolment 201 carries the same body (Task 4 Step 6 and the registration test).
- Structure (two services, eight constructor parameters): Tasks 3-4, with the PHPMD ordering constraint stated globally.
- Where each call goes: enrolment refusal → Task 7; unknown login id → Task 8; listing/delete sweep → Task 6.
- Hard case (last passkey): Task 6 remembers the handle; the second listing test in Task 4 proves the server sends `null` then.
- Best-effort and absent API: Task 5 tests all three degradations per helper; Tasks 6-8 each test a rejecting signal.
- Testing section: backend items map to Tasks 1-4; frontend items map to Tasks 5-8; gates are Task 9.
- Types: `PasskeyListingBody` (PHP), `PasskeyListingJson` (TS), `SignalSubject`, `RegistrationBody`, `AssertionBody`, `isClientRejection`, `isUnknownCredential`, `PasskeyListing::forUser`, `PasskeyRemoval::remove` are each defined before use and named identically throughout.
