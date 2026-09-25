# One Domain-Failure → problem+json Mapping (#1160) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Domain failures reach HTTP by exactly one route. Plain typed exceptions go to per-module problem mappers in `src/Http/Problem/`, which feed one response factory. The three competing routes (HTTP-carrying `ApiException`s, Symfony HTTP exceptions thrown from services, and controller catch-and-rethrow twins) are removed.

**Architecture:** `App\Exception\ApiException` is deleted. Every exception that extended it becomes a plain `\RuntimeException` subclass with no HTTP knowledge. The tagged interface `ExceptionProblems` has one implementation per module. Each implementation is a `match (true)` of `instanceof` arms that returns a `ResolvedProblem`. `ProblemCatalog` does the following, in order:

1. It answers every `AuthenticationException` with the opaque 401.
2. It asks the mappers.
3. It falls back to the Symfony-HTTP and `AccessDeniedException` handling that moves out of `ApiExceptionListener`.
4. It answers anything else with a logged, opaque 500.

`ProblemResponseFactory` turns a `ResolvedProblem` into the `JsonResponse`. `ApiExceptionListener`, `LoginFailureHandler` and `JwtFailureResponseListener` all go through the catalog and the factory.

**Tech Stack:** Symfony 7.4, PHP 8.4, PHPUnit 12 (`#[DataProvider]` attributes, static providers), PHPStan level max with the custom rules in `backend/tests/PhpStan/`, and Infection (`minMsi: 80` on changed files).

**Spec:** GitHub issue #1160 (`gh issue view 1160`). The preflight evidence is in `.superpowers/sdd/2026-09-25-1160-one-problem-mapping/preflight.md`. That file is git-ignored and lives in the checkout. Related issues:
- #1165 builds on this mapping.
- #1157 moves `TagNameTakenException` into a tag service later.
- #1158 removes the `App\Http` / `Response` presentation imports from services.

Do none of those here.

## Status

| Task | State |
|---|---|
| Task 1: Pin the wire contract | ⬜ not started |
| Task 2: Catalog, factory, mapper interface; three entry points delegate | ⬜ not started |
| Task 3: AI and recommendation twins | ⬜ not started |
| Task 4: Remaining catch-and-rethrow sites | ⬜ not started |
| Task 5: Plain module exceptions (6 commits) | ⬜ not started |
| Task 6: No Symfony HTTP exceptions in services and repositories | ⬜ not started |
| Task 7: Delete `ApiException`; guard the boundary | ⬜ not started |

`git log develop..refactor/1160-one-problem-mapping` holds only the plan commits: `59c59380` (the plan) and `0f583a0a` (the preflight amendments). No task has been implemented yet.

## Amendments vs previous plan

Every item below was checked against the tree at `develop` (`cbf7e656`). This list covers the preflight rulings (D1–D14 in `progress.md`) and the corrections found while writing this version.

1. **D3, implemented without a non-debug kernel.**
   - The ruling said: boot `ProblemContractTest` with `debug => false`. That trades one trap for a worse one. `Kernel::initializeContainer()` (vendor/symfony/http-kernel/Kernel.php:423-424) reuses a compiled non-debug container **without a freshness check**. Tasks 2–6 change services, so every one of them would run against a stale container, and so would every developer after merge.
   - What this plan does instead:
     - The contract test boots the normal test kernel. `debug` has no effect on any deliberate row.
     - Its `unexpectedFailures` rows assert `type`, `title` and `status` only. That proves no mapper claims them.
     - The "no detail outside debug" invariant is pinned by `ProblemCatalogTest` with `debug: false`. Until Task 2, the existing `ApiExceptionListenerTest::testUnexpectedExceptionsBecomeOpaque500` pins it.
   - See open question 1.
2. **`ProblemCatalog` answers `AuthenticationException` before it asks any mapper.** The previous order was mappers first, then fallbacks. With the new order, "a stolen JWT for a suspended user stays the opaque 401" holds by construction, not only by test. It also makes the Lexik `InvalidTokenException` same-name trap harmless on the JWT path. Today no `ApiException` is an `AuthenticationException`, so the order change alters no response.
3. **D4 `SecretUnreadableException` is wrapped in one place: `AiProviderConfigurator::credentials()`.** The preflight suggested three services. The configurator's own docblock calls `credentials()` "the one place that opens the sealed key". `ApiKeyCipher` has no other `src` user, and `ProviderConnectionFactory` goes through `credentials()`. The following follow the new type, which the preflight did not list: `RecommendationRunAdvancer:220,266-268`, `WorkerRunSweep:118`, `AiProviderConfiguratorTest:169`, `RecommendationRunAdvancerTest:2791-2792` and `AdvanceRecommendationRunsHandlerTest:283-292`.
4. **D5 `FeedPreviewException` moves in Task 4, not Task 5.** Task 4 already rewrites its only catch and creates `PreviewProblems`. Moving it there avoids editing that mapper twice.
5. **Constructor simplifications (verified against every throw site):**
   - `AlreadySubscribedException` and `TagNameTakenException` lose their unused `?string $detail` parameter. Every caller (`SubscriptionCreator:86`, `TagController:53,133`) passes none.
   - `InvalidOpmlException` takes the ordinary required message. Every caller (`OpmlController:45`, `OpmlBodyReader:32,37`) passes one.
   - So no mapper needs a `'' === getMessage()` check.
6. **One detail rule, applied uniformly.**
   - Where the old `ApiException` passed a constructor argument through, or computed its `detail` from one, that text becomes the plain exception's message, and the mapper uses `getMessage()`. This covers `AccountNotActive`, `RelyingPartyChange…`, `SubscriptionLimitReached`, `Backup*`, `IncompleteMail…`, `InvalidOpml`, the AI provider group, `ScrapingDisabled`, `FeedPreview`, `RecordNotFound` and `InvalidSelection`.
   - Where the old `detail` was fixed text, the text lives in the mapper and the exception has no constructor.
   - The previous plan moved `AccountNotActive`'s status→message `match` into the mapper. Under this rule it stays in the exception. Its `detail` is computed from its constructor argument.
7. **Tests the preflight missed.** `tests/Exception/ReaderExceptionsTest.php`, `tests/Service/OAuth/AbstractOidcProviderTest.php:465`, `OwnedSubscriptionsTest.php:98,131`, `FeedTagMoveTest.php:135` and `ExactSetGuardTest.php:41-42` all read HTTP properties or expect HTTP exceptions.
8. **The temporary `ApiException` arm in `ProblemCatalog` has to shrink as modules go plain.** PHPStan reports `instanceof` between `ApiException` and a now-unrelated final class as always false, and it fails on imports that moved. So:
   - The Settings commit removes the `RelyingPartyChange…` branch.
   - The Auth commit removes the `RateLimited` and `AccountNotActive` branches.
9. **`DomainKnowsNoHttpRule` is a `FileNode` rule**, like `ChainedNullCoalescingRule`. It walks the `Name` nodes of each `Namespace_`.
   - PHPStan does not call rules for bare `Name` nodes in every position, such as type hints and class constants.
   - It also covers `App\Exception`, the namespace where the three cross-cutting exceptions and `InvalidSelectionException` live.
   - D2/D12 narrowing holds: `HttpKernel\Exception\*` is forbidden in every scanned namespace, and `HttpFoundation\Response` and `App\Http\*` only in namespaces with an `Exception` segment.
   - It was checked in the scratchpad against the repo's `vendor/`. Its `RuleTestCase` passes on the Task 7 fixture. Run over `develop`'s `src`, it reports exactly the files Tasks 3–6 delete, move or rewrite.
   - `PhpParser\NodeFinder` is injected and registered in `phpstan.dist.neon`. PHPStan's Nette container will not fall back to a class-typed default.
10. **More fallback rows in the contract test**, so the code moved into `ProblemCatalog` stays mutation-covered:
    - `HttpException(500)`, which pins `>= 500`.
    - `ServiceUnavailableHttpException`.
    - `BadRequestHttpException` and `AccessDeniedHttpException`.
    - `MethodNotAllowedHttpException`, with its `Allow` header.
    - A `\Stringable` violation message, which pins the `(string)` cast.
11. **D1 needs no new login tests.** `tests/Controller/Api/PasskeyLoginTest.php` already covers each of the four `verify()` failures:
    - disabled: `testADisabledInstanceRejectsLoginWith401NotA500`, line 548.
    - unknown challenge: `testAReplayedHandleIsRejected` (143) and `testAnExpiredHandleIsRejected` (166).
    - rejected: `testATamperedClientDataJsonIsRejected` (305) and `testATamperedSignatureIsRejected` (341).
    - unknown credential: `testACredentialIdThatWasNeverEnrolledIsRejected` (192).

    The Passkey commit runs that file red first, before the catch changes, and green after.
12. **D6, confirmed in vendor code.** `LoginThrottlingListener.php:52,57` throws `new TooManyLoginAttemptsAuthenticationException(ceil(($limit->getRetryAfter()->getTimestamp() - time()) / 60))`, and `getMessageData()['%minutes%']` returns that threshold. A threshold of `0` is possible as well as `null`, so both fall back to 60 seconds. `LoginFailureHandlerTest` has a row for each.
13. **D9: the passkey split stays for cohesion.** `UnknownChallengeException` goes into `PasskeyRegistrationProblems`, because the login path never lets it reach the catalog: `PasskeyAuthenticator` wraps it.
14. **`ApiExceptionListenerTest` shrinks to the path filter and the dispatcher-chain tests.** Its other cases become `ProblemContractTest` rows, `ProblemCatalogTest` and `ProblemResponseFactoryTest`.
15. **Mutation safety.**
    - A plain exception calls `parent::__construct()` only where the message or `previous` is observable.
    - `AttestationRejectedException` and `AssertionRejectedException` get chaining tests. Without them, removing the parent call would be an escaped mutant.
16. **Dry run.** Before the owner stopped the trial runs, Tasks 1–4 and commit 5a ran in a scratch copy of `develop`. That covered every red and green expectation, `composer stan`, `md`, `cs` and `tramp`, and every before-block matching the real source exactly. Task 1 gave `OK (65 tests, 394 assertions)` against untouched code. From 5b on, the plan is checked by reading only; the executor verifies it during implementation.
17. **Scope of comment edits.**
    - Exception files that are rewritten get new docblocks of three lines at most.
    - Other comments are edited only where they name `ApiException`, `ApiExceptionListener`, a twin, a moved class, or an HTTP status inside domain code.
    - Long rationale docblocks that are otherwise accurate, such as `InsecureProductionConfigGuard`'s, are fixed by a minimal in-sentence edit, not rewritten.

**Planner rulings (were open questions)**

1. Amendment 1 is accepted: the contract test runs on the default kernel, and the no-detail-on-500 rule is pinned in `ProblemCatalogTest` with `debug: false`.
2. Amendment 3 is accepted. The executor must check that the advancer and the worker sweep still treat an unreadable key exactly as before: the same run state and the same log.
3. Accepted. A mapped domain exception gets its 4xx on any `/api` path.
4. The executor posts nothing on #1158. The planner carries the widening into the #1158 plan.

## Global Constraints

- **Paths and commands are relative to `backend/`** unless they start with `docs/`, `frontend/` or `CLAUDE.md`.
- **The wire contract does not change.** Every `type`, `status`, `title` and `detail` stays byte-identical, and so do the `errors`, `accountStatus` and `invalidatedPasskeyCount` members, the `Retry-After` header and the key order of the body. There are two exceptions:
  - The deliberate Task 6 change: `RecordNotFoundException` and `InvalidSelectionException` now carry `detail`.
  - Open question 3.
  - The Angular client and a future iOS client switch on `type`.
- **The mappers in `src/Http/Problem/` are the only code that maps a domain failure to HTTP.**
  - No class under `src/Service`, `src/Repository`, `src/Entity`, `src/Enum` or `src/Exception` may reference `Symfony\Component\HttpKernel\Exception\*`.
  - No class in a namespace with an `Exception` segment may reference `Symfony\Component\HttpFoundation\Response` or `App\Http\*`.
  - Non-exception service code that imports `Response` or `App\Http\*` today (`HtmlPageFetcher`, `FlowCookie`, `MaintenanceTokenGuard` and 21 presentation imports) is #1158's scope and stays.
- **Where exceptions live:** next to their service in `Service/<Module>/Exception/` (CLAUDE.md), with two exceptions:
  - `Repository/Exception/RecordNotFoundException` lives next to the repositories.
  - Four stay in `src/Exception/`: `ValidationException`, `InvalidCredentialsException`, `InvalidSelectionException` (new) and `TagNameTakenException`, which waits for #1157.
- **Same-name traps.**
  - `Lexik\Bundle\JWTAuthenticationBundle\Exception\InvalidTokenException` is an `AuthenticationException` that a revoked JWT throws. `App\…\InvalidTokenException` is ours.
  - `Doctrine\ORM\EntityNotFoundException` is a proxy miss, which is a bug. Ours is `RecordNotFoundException`.
  - `ProblemContractTest` pins both of the foreign ones: the revoked JWT as the opaque 401, the proxy miss as the opaque 500. In test files that import both `InvalidTokenException`s, the Lexik one is aliased `RevokedJwtException`.
- **The safety invariant of the old `ApiException` docblock holds.** A mapper never reads `getPrevious()`. It uses `getMessage()` only for exceptions whose message is authored and safe to show a client (Amendment 6).
- **CLAUDE.md Clean Code applies to every file you touch:**
  - `final readonly` classes, and `final` exceptions.
  - No boolean flag parameters.
  - Comments of one line, three at most, and only where a reader would otherwise get the code wrong.
  - Every touched `src` file must be PHPMD-clean.
- **Use-statement order is not linted** (PSR-12 only). A `perl` rename that leaves imports out of alphabetical order is fine.
- **Gates before the PR:**
  - `composer check`, `composer md` and `php bin/phpunit` (SQLite).
  - `docker compose exec php composer test` (MySQL, from the repo root).
  - `composer infection:diff`.
  - PhpStorm `lint_files` on the changed PHP.
- **Before running `composer stan`** after a task that adds or changes a service, run `bin/console cache:warmup --env=dev`. PHPStan reads the dev container XML.
- **Commits:** `test(#1160): …` and `refactor(#1160): …` on `refactor/1160-one-problem-mapping`. Another session may share this checkout: run `git status` before every commit, and never `checkout`, `stash` or `reset`.

---

## File Structure

| Path | Responsibility |
|---|---|
| `src/Http/Problem/ApiProblem.php` | Moved from `src/Http/ApiProblem.php`. Namespace `App\Http\Problem`. |
| `src/Http/Problem/ResolvedProblem.php` | Moved from `src/Http/ResolvedProblem.php`. |
| `src/Http/Problem/ExceptionProblems.php` | Mapper interface with `#[AutoconfigureTag('app.exception_problems')]`. |
| `src/Http/Problem/ProblemCatalog.php` | `\Throwable` → `ResolvedProblem`: authentication first, then the mappers, the framework fallbacks, and the opaque 500. |
| `src/Http/Problem/ProblemResponseFactory.php` | `ResolvedProblem` → `JsonResponse`, with the problem+json header winning. |
| `src/Http/Problem/AiProblems.php`, `RecommendationRunProblems.php` | Task 3 |
| `src/Http/Problem/DiscoveryProblems.php`, `PreviewProblems.php`, `CatalogProblems.php`, `CommentsProblems.php` | Task 4 |
| `src/Http/Problem/PasskeyRegistrationProblems.php`, `PasskeySignInProblems.php`, `BackupProblems.php`, `MailProblems.php`, `SettingsProblems.php`, `AccountProblems.php`, `SubscriptionProblems.php`, `AuthProblems.php`, `RateLimitProblems.php`, `OpmlProblems.php`, `OAuthProblems.php`, `RequestProblems.php`, `TagProblems.php` | Task 5 |
| `src/Service/Passkey/Exception/PasskeySignInFailure.php` | Marker for the four `AssertionVerifier::verify()` refusals that `PasskeyAuthenticator` catches. |
| `src/Service/Ai/Exception/AiKeyUnreadableException.php` | AI-owned wrapper for `SecretUnreadableException`. |
| `src/Repository/Exception/RecordNotFoundException.php`, `src/Exception/InvalidSelectionException.php` | Task 6 |
| `src/EventListener/ApiExceptionListener.php` | Path guard, then catalog, then factory. |
| `src/Security/LoginFailureHandler.php`, `src/EventListener/JwtFailureResponseListener.php` | Use the catalog and the factory. |
| `tests/Http/Problem/ProblemContractTest.php` | The characterization table that pins the wire contract from Task 1 on. |
| `tests/Http/Problem/ProblemCatalogTest.php`, `ProblemResponseFactoryTest.php`, `ApiProblemTest.php` | Unit tests. `ApiProblemTest` is moved. |
| `tests/Security/LoginFailureHandlerTest.php` | The lockout's `Retry-After`. |
| `tests/PhpStan/DomainKnowsNoHttpRule.php`, `DomainKnowsNoHttpRuleTest.php`, `data/domain-knows-no-http-fixtures.php` | Task 7 guard |

---
### Task 1: Pin the wire contract before touching anything

**Files:**
- Create: `tests/Http/Problem/ProblemContractTest.php`

**Interfaces:**
- Consumes: the container's `App\EventListener\ApiExceptionListener` service, which has the public method `onKernelException(ExceptionEvent $event): void`.
- Produces: `App\Tests\Http\Problem\ProblemContractTest` with two static providers:
  - `deliberateFailures(): iterable<string, array{0: \Throwable, 1: array<string, mixed>, 2?: array<string, string>}>`.
  - `unexpectedFailures(): iterable<string, array{\Throwable}>`.
- Later tasks change only its `use` lines and the first element of a row. Task 3 adds one `unexpectedFailures` row, and Task 6 adds two `deliberateFailures` rows.
- The row keys named here are referenced verbatim by later tasks.

- [ ] **Step 1: Write the test.** Create `tests/Http/Problem/ProblemContractTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Http\Problem;

use App\EventListener\ApiExceptionListener;
use App\Exception\AccountNotActiveException;
use App\Exception\AiConfigurationNotFoundApiException;
use App\Exception\AiKeyUnreadableApiException;
use App\Exception\AiNotConfiguredApiException;
use App\Exception\AiProviderApiException;
use App\Exception\AlreadySubscribedException;
use App\Exception\FeedPreviewApiException;
use App\Exception\InvalidCredentialsException;
use App\Exception\InvalidOpmlException;
use App\Exception\InvalidSetupSecretException;
use App\Exception\InvalidTokenException;
use App\Exception\LastAdminException;
use App\Exception\NoActiveRecommendationRunApiException;
use App\Exception\NoResumableRecommendationRunApiException;
use App\Exception\OAuth\OAuthFailedException;
use App\Exception\OAuth\UnknownProviderException;
use App\Exception\RateLimitedException;
use App\Exception\RecommendationRunActiveApiException;
use App\Exception\ScrapingDisabledApiException;
use App\Exception\SetupUnavailableException;
use App\Exception\SubscriptionLimitReachedException;
use App\Exception\TagNameTakenException;
use App\Exception\TooManyAiConfigurationsApiException;
use App\Exception\ValidationException;
use App\Security\AccountStatusException;
use App\Service\Backup\Exception\BackupDoesNotFitException;
use App\Service\Backup\Exception\BackupLoadFailedException;
use App\Service\Backup\Exception\InvalidBackupException;
use App\Service\Mail\Settings\Exception\IncompleteMailConfigurationException;
use App\Service\Passkey\Exception\AssertionRejectedException;
use App\Service\Passkey\Exception\AttestationRejectedException;
use App\Service\Passkey\Exception\DuplicatePasskeyException;
use App\Service\Passkey\Exception\LastSignInMethodException;
use App\Service\Passkey\Exception\PasskeyChallengeOwnershipException;
use App\Service\Passkey\Exception\PasskeyNotFoundException;
use App\Service\Passkey\Exception\PasskeySignInDisabledException;
use App\Service\Passkey\Exception\UnknownChallengeException;
use App\Service\Passkey\Exception\UnknownPasskeyCredentialException;
use App\Service\Settings\Exception\RelyingPartyChangeRequiresConfirmationException;
use Doctrine\ORM\EntityNotFoundException;
use Lexik\Bundle\JWTAuthenticationBundle\Exception\InvalidTokenException as RevokedJwtException;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Core\Exception\BadCredentialsException;
use Symfony\Component\Validator\ConstraintViolation;
use Symfony\Component\Validator\ConstraintViolationList;
use Symfony\Component\Validator\Exception\ValidationFailedException;

final class ProblemContractTest extends KernelTestCase
{
    /**
     * @param array<string, mixed>  $expectedBody
     * @param array<string, string> $expectedHeaders
     */
    #[DataProvider('deliberateFailures')]
    public function testADeliberateFailureRendersItsContract(
        \Throwable $exception,
        array $expectedBody,
        array $expectedHeaders = [],
    ): void {
        $response = $this->render($exception);

        self::assertSame($expectedBody['status'], $response->getStatusCode());
        self::assertSame('application/problem+json', $response->headers->get('Content-Type'));
        self::assertSame($expectedBody, $this->bodyOf($response));
        foreach ($expectedHeaders as $name => $value) {
            self::assertSame($value, $response->headers->get($name));
        }
    }

    #[DataProvider('unexpectedFailures')]
    public function testAnUnexpectedFailureStaysAnOpaque500(\Throwable $exception): void
    {
        $response = $this->render($exception);
        $body = $this->bodyOf($response);

        self::assertSame(500, $response->getStatusCode());
        self::assertSame('internal_error', $body['type']);
        self::assertSame('Internal server error', $body['title']);
    }

    /** @return iterable<string, array{0: \Throwable, 1: array<string, mixed>, 2?: array<string, string>}> */
    public static function deliberateFailures(): iterable
    {
        yield 'unknown passkey credential' => [
            new UnknownPasskeyCredentialException(),
            [
                'type' => 'unknown_passkey_credential',
                'title' => 'Unknown passkey',
                'status' => 401,
                'detail' => 'This passkey is not registered here.',
            ],
        ];
        yield 'unknown passkey challenge' => [
            new UnknownChallengeException(),
            ['type' => 'unknown_passkey_challenge', 'title' => 'Unknown or expired passkey challenge', 'status' => 400],
        ];
        yield 'passkey sign-in disabled' => [
            new PasskeySignInDisabledException(),
            [
                'type' => 'passkey_sign_in_disabled',
                'title' => 'Passkey sign-in is disabled',
                'status' => 403,
                'detail' => 'This instance has turned off passkey sign-in.',
            ],
        ];
        yield 'passkey not found' => [
            new PasskeyNotFoundException(),
            ['type' => 'passkey_not_found', 'title' => 'No such passkey', 'status' => 404],
        ];
        yield 'last sign-in method' => [
            new LastSignInMethodException(),
            [
                'type' => 'passkey_last_sign_in_method',
                'title' => 'Cannot remove your last sign-in method',
                'status' => 409,
                'detail' => 'This is your only way to sign in. Set a password or link a sign-in provider first.',
            ],
        ];
        yield 'duplicate passkey' => [
            new DuplicatePasskeyException(),
            [
                'type' => 'passkey_already_registered',
                'title' => 'Passkey already registered',
                'status' => 409,
                'detail' => 'This passkey is already registered.',
            ],
        ];
        yield 'passkey challenge owner mismatch' => [
            new PasskeyChallengeOwnershipException(),
            [
                'type' => 'passkey_challenge_owner_mismatch',
                'title' => 'Forbidden',
                'status' => 403,
                'detail' => 'This registration challenge was not issued to you.',
            ],
        ];
        yield 'attestation rejected, cause withheld' => [
            new AttestationRejectedException(new \RuntimeException('CBOR offset 17 in secret-attestation-bytes')),
            [
                'type' => 'passkey_attestation_rejected',
                'title' => 'Passkey registration rejected',
                'status' => 400,
                'detail' => 'The passkey could not be verified.',
            ],
        ];
        yield 'assertion rejected, cause withheld' => [
            new AssertionRejectedException(new \RuntimeException('secret-assertion-bytes')),
            [
                'type' => 'passkey_assertion_rejected',
                'title' => 'Passkey login rejected',
                'status' => 401,
                'detail' => 'The passkey could not be verified.',
            ],
        ];
        yield 'relying party change needs confirmation' => [
            new RelyingPartyChangeRequiresConfirmationException(3),
            [
                'type' => 'relying_party_change_requires_confirmation',
                'title' => 'Relying party change requires confirmation',
                'status' => 409,
                'detail' => 'Changing the passkey relying party id invalidates 3 enrolled passkey(s). '
                    . 'Resend the request with invalidateExistingPasskeys set to confirm.',
                'invalidatedPasskeyCount' => 3,
            ],
        ];
        yield 'incomplete mail configuration' => [
            IncompleteMailConfigurationException::passwordMissing(),
            [
                'type' => 'incomplete_mail_configuration',
                'title' => 'Incomplete mail configuration',
                'status' => 422,
                'detail' => 'An enabled SMTP transport with a username needs a password, stored or provided.',
            ],
        ];
        yield 'backup does not fit' => [
            new BackupDoesNotFitException('The backup holds 600 subscriptions; this account allows 500.'),
            [
                'type' => 'backup_does_not_fit',
                'title' => 'The backup does not fit this account',
                'status' => 409,
                'detail' => 'The backup holds 600 subscriptions; this account allows 500.',
            ],
        ];
        yield 'invalid backup' => [
            new InvalidBackupException('The file is not gzip-compressed.'),
            [
                'type' => 'invalid_backup',
                'title' => 'Invalid backup file',
                'status' => 422,
                'detail' => 'The file is not gzip-compressed.',
            ],
        ];
        yield 'backup load failed, driver message withheld' => [
            BackupLoadFailedException::from(new \RuntimeException('SQLSTATE[22001]: secret-column-value')),
            [
                'type' => 'backup_load_failed',
                'title' => 'The backup could not be loaded',
                'status' => 422,
                'detail' => 'The restore emptied the account and then could not load the file: '
                    . 'the database rejected one of its values. The account is now empty. '
                    . 'Correct or re-export the backup, then run the restore again.',
            ],
        ];
        yield 'backup entries load failed' => [
            BackupLoadFailedException::duringEntries(new \RuntimeException('secret-entry-value')),
            [
                'type' => 'backup_load_failed',
                'title' => 'The backup could not be loaded',
                'status' => 422,
                'detail' => 'A backup part could not be loaded: the database rejected one of its values. '
                    . 'The account was not emptied; correct or re-export the backup, then continue the restore.',
            ],
        ];
        yield 'rate limited' => [
            new RateLimitedException(120),
            [
                'type' => 'rate_limited',
                'title' => 'Too many requests',
                'status' => 429,
                'detail' => 'Too many attempts. Try again later.',
            ],
            ['Retry-After' => '120'],
        ];
        yield 'invalid credentials' => [
            new InvalidCredentialsException(),
            [
                'type' => 'invalid_credentials',
                'title' => 'Invalid credentials',
                'status' => 401,
                'detail' => 'Email address or password is incorrect.',
            ],
        ];
        yield 'invalid token' => [
            new InvalidTokenException(),
            [
                'type' => 'invalid_token',
                'title' => 'Invalid token',
                'status' => 400,
                'detail' => 'This link is invalid, already used, or expired.',
            ],
        ];
        yield 'invalid setup secret' => [
            new InvalidSetupSecretException(),
            [
                'type' => 'invalid_setup_secret',
                'title' => 'Forbidden',
                'status' => 403,
                'detail' => 'The setup secret is incorrect.',
            ],
        ];
        yield 'setup unavailable' => [
            new SetupUnavailableException(),
            [
                'type' => 'setup_unavailable',
                'title' => 'Not found',
                'status' => 404,
                'detail' => 'Setup is not available.',
            ],
        ];
        yield 'account pending verification' => [
            new AccountNotActiveException('pending_verification'),
            self::accountNotActive('pending_verification', 'Confirm your email address first.'),
        ];
        yield 'account pending approval' => [
            new AccountNotActiveException('pending_approval'),
            self::accountNotActive('pending_approval', 'An administrator has not approved this account yet.'),
        ];
        yield 'account suspended' => [
            new AccountNotActiveException('suspended'),
            self::accountNotActive('suspended', 'This account has been suspended.'),
        ];
        yield 'account rejected' => [
            new AccountNotActiveException('rejected'),
            self::accountNotActive('rejected', 'This account was rejected.'),
        ];
        yield 'account in any other status' => [
            new AccountNotActiveException('active'),
            self::accountNotActive('active', 'This account cannot sign in.'),
        ];
        yield 'invalid opml' => [
            new InvalidOpmlException('OPML has no <body>.'),
            [
                'type' => 'invalid_opml',
                'title' => 'The OPML document could not be parsed',
                'status' => 422,
                'detail' => 'OPML has no <body>.',
            ],
        ];
        yield 'last admin' => [
            new LastAdminException(),
            [
                'type' => 'last_admin',
                'title' => 'Last administrator',
                'status' => 409,
                'detail' => 'This is the only administrator account. Promote another account first.',
            ],
        ];
        yield 'subscription limit reached' => [
            new SubscriptionLimitReachedException(7),
            [
                'type' => 'subscription_limit_reached',
                'title' => 'Subscription limit reached',
                'status' => 409,
                'detail' => 'You can subscribe to at most 7 feeds.',
            ],
        ];
        yield 'already subscribed' => [
            new AlreadySubscribedException(),
            ['type' => 'already_subscribed', 'title' => 'Already subscribed to that feed', 'status' => 409],
        ];
        yield 'tag name taken' => [
            new TagNameTakenException(),
            ['type' => 'tag_name_taken', 'title' => 'Tag name already in use', 'status' => 409],
        ];
        yield 'validation' => [
            new ValidationException(['email' => ['Not a valid email address.']]),
            [
                'type' => 'validation_error',
                'title' => 'Validation failed',
                'status' => 422,
                'detail' => 'One or more fields are invalid.',
                'errors' => ['email' => ['Not a valid email address.']],
            ],
        ];
        yield 'unknown sign-in provider' => [
            new UnknownProviderException(),
            [
                'type' => 'unknown_provider',
                'title' => 'Unknown sign-in provider',
                'status' => 404,
                'detail' => 'That sign-in provider is not available.',
            ],
        ];
        yield 'oauth failed, log detail and cause withheld' => [
            new OAuthFailedException(
                'audience mismatch: token aud=attacker-client-id',
                new \RuntimeException('private key /etc/secrets/apple.p8 unreadable'),
            ),
            [
                'type' => 'oauth_failed',
                'title' => 'Sign-in failed',
                'status' => 502,
                'detail' => 'Signing in with that provider did not work. Please try again.',
            ],
        ];
        yield 'ai not configured' => [
            new AiNotConfiguredApiException(),
            [
                'type' => 'ai_not_configured',
                'title' => 'No AI provider is configured',
                'status' => 404,
                'detail' => 'Save an endpoint and an API key first.',
            ],
        ];
        yield 'ai configuration not found' => [
            new AiConfigurationNotFoundApiException(),
            [
                'type' => 'ai_configuration_not_found',
                'title' => 'AI configuration not found',
                'status' => 404,
                'detail' => 'No such AI configuration for this account.',
            ],
        ];
        yield 'too many ai configurations' => [
            new TooManyAiConfigurationsApiException(),
            [
                'type' => 'ai_configuration_limit',
                'title' => 'Too many AI configurations',
                'status' => 409,
                'detail' => 'This account already holds the maximum number of AI configurations.',
            ],
        ];
        yield 'ai key unreadable' => [
            new AiKeyUnreadableApiException(new \RuntimeException('The stored secret failed its integrity check.')),
            [
                'type' => 'ai_key_unreadable',
                'title' => 'The stored API key could not be read',
                'status' => 422,
                'detail' => 'The stored API key can no longer be read. Enter it again.',
            ],
        ];
        yield 'ai provider unreachable' => [
            new AiProviderApiException('That address did not answer.'),
            self::providerRejected('That address did not answer.'),
        ];
        yield 'ai credentials rejected' => [
            new AiProviderApiException('That provider refused the API key.'),
            self::providerRejected('That provider refused the API key.'),
        ];
        yield 'ai model not offered' => [
            new AiProviderApiException('That provider does not offer "gpt-9".'),
            self::providerRejected('That provider does not offer "gpt-9".'),
        ];
        yield 'ai model required for activation' => [
            new AiProviderApiException('Choose a model before activating this configuration.'),
            self::providerRejected('Choose a model before activating this configuration.'),
        ];
        yield 'no active recommendation run' => [
            new NoActiveRecommendationRunApiException(),
            [
                'type' => 'no_active_recommendation_run',
                'title' => 'No recommendation run is active',
                'status' => 409,
                'detail' => 'There is nothing to stop: the run already finished.',
            ],
        ];
        yield 'no resumable recommendation run' => [
            new NoResumableRecommendationRunApiException(),
            [
                'type' => 'no_resumable_recommendation_run',
                'title' => 'No recommendation run to resume',
                'status' => 409,
                'detail' => 'There is no failed run to resume; start a new one instead.',
            ],
        ];
        yield 'recommendation run active' => [
            new RecommendationRunActiveApiException(),
            [
                'type' => 'recommendation_run_active',
                'title' => 'A recommendation run is still active',
                'status' => 409,
                'detail' => 'Wait for the current run to finish, then try again.',
            ],
        ];
        yield 'scraping disabled' => [
            new ScrapingDisabledApiException('Website scraping is turned off for this account.'),
            [
                'type' => 'scraping_disabled',
                'title' => 'Website scraping is disabled',
                'status' => 403,
                'detail' => 'Website scraping is turned off for this account.',
            ],
        ];
        yield 'feed preview failed' => [
            new FeedPreviewApiException('The feed returned an empty document.'),
            [
                'type' => 'feed_preview_failed',
                'title' => 'Feed preview failed',
                'status' => 422,
                'detail' => 'The feed returned an empty document.',
            ],
        ];
        yield 'invalid catalog document, message withheld' => [
            new UnprocessableEntityHttpException('Duplicate feed URL "https://a.example/feed".'),
            ['type' => 'request_error', 'title' => 'Unprocessable Content', 'status' => 422],
        ];
        yield 'no comments feed, message withheld' => [
            new NotFoundHttpException('The entry has no comments feed.'),
            ['type' => 'not_found', 'title' => 'Not Found', 'status' => 404],
        ];
        yield 'http not found' => [
            new NotFoundHttpException(),
            ['type' => 'not_found', 'title' => 'Not Found', 'status' => 404],
        ];
        yield 'payload validation failure' => [
            new UnprocessableEntityHttpException('Validation failed', new ValidationFailedException(
                null,
                new ConstraintViolationList([
                    new ConstraintViolation('Not a valid email address.', null, [], null, 'email', 'nope'),
                    new ConstraintViolation(self::stringable('Too short.'), null, [], null, 'password', 'x'),
                ]),
            )),
            [
                'type' => 'validation_error',
                'title' => 'Validation failed',
                'status' => 422,
                'detail' => 'One or more fields are invalid.',
                'errors' => ['email' => ['Not a valid email address.'], 'password' => ['Too short.']],
            ],
        ];
        yield 'http 422, message withheld' => [
            new UnprocessableEntityHttpException('secret m'),
            ['type' => 'request_error', 'title' => 'Unprocessable Content', 'status' => 422],
        ];
        yield 'http 429 keeps retry-after' => [
            new TooManyRequestsHttpException(60),
            ['type' => 'rate_limited', 'title' => 'Too Many Requests', 'status' => 429],
            ['Retry-After' => '60'],
        ];
        yield 'http 405 keeps allow' => [
            new MethodNotAllowedHttpException(['GET', 'POST']),
            ['type' => 'method_not_allowed', 'title' => 'Method Not Allowed', 'status' => 405],
            ['Allow' => 'GET, POST'],
        ];
        yield 'http 401 keeps www-authenticate' => [
            new HttpException(401, 'Nope', null, ['Content-Type' => 'text/html', 'WWW-Authenticate' => 'Bearer']),
            ['type' => 'unauthorized', 'title' => 'Unauthorized', 'status' => 401],
            ['WWW-Authenticate' => 'Bearer'],
        ];
        yield 'http 500' => [
            new HttpException(500),
            ['type' => 'internal_error', 'title' => 'Internal Server Error', 'status' => 500],
        ];
        yield 'http 503' => [
            new ServiceUnavailableHttpException(),
            ['type' => 'internal_error', 'title' => 'Service Unavailable', 'status' => 503],
        ];
        yield 'http 400, message withheld' => [
            new BadRequestHttpException('secret request detail'),
            ['type' => 'request_error', 'title' => 'Bad Request', 'status' => 400],
        ];
        yield 'http 403' => [
            new AccessDeniedHttpException(),
            ['type' => 'forbidden', 'title' => 'Forbidden', 'status' => 403],
        ];
        yield 'authentication required' => [new AuthenticationException('Not authenticated.'), self::unauthorized()];
        yield 'bad credentials' => [new BadCredentialsException(), self::unauthorized()];
        yield 'account status stays opaque' => [new AccountStatusException('suspended'), self::unauthorized()];
        yield 'revoked jwt stays opaque' => [
            new RevokedJwtException('JWT predates the account\'s last password change.'),
            self::unauthorized(),
        ];
        yield 'security access denied' => [
            new AccessDeniedException(),
            [
                'type' => 'forbidden',
                'title' => 'Forbidden',
                'status' => 403,
                'detail' => 'You do not have permission to access this resource.',
            ],
        ];
    }

    /** @return iterable<string, array{\Throwable}> */
    public static function unexpectedFailures(): iterable
    {
        yield 'logic error' => [new \LogicException('DB password is hunter2')];
        yield 'doctrine proxy miss' => [
            EntityNotFoundException::fromClassNameAndIdentifier('App\Entity\Tag', ['id' => '1']),
        ];
    }

    /** @return array<string, mixed> */
    private static function accountNotActive(string $status, string $detail): array
    {
        return [
            'type' => 'account_not_active',
            'title' => 'Account not active',
            'status' => 403,
            'detail' => $detail,
            'accountStatus' => $status,
        ];
    }

    /** @return array<string, mixed> */
    private static function providerRejected(string $detail): array
    {
        return [
            'type' => 'ai_provider_rejected',
            'title' => 'The AI provider could not be used',
            'status' => 422,
            'detail' => $detail,
        ];
    }

    /** @return array<string, mixed> */
    private static function unauthorized(): array
    {
        return [
            'type' => 'unauthorized',
            'title' => 'Unauthorized',
            'status' => 401,
            'detail' => 'Authentication is required to access this resource.',
        ];
    }

    private static function stringable(string $text): \Stringable
    {
        return new class ($text) implements \Stringable {
            public function __construct(private readonly string $text)
            {
            }

            public function __toString(): string
            {
                return $this->text;
            }
        };
    }

    private function render(\Throwable $exception): Response
    {
        $event = new ExceptionEvent(
            self::bootKernel(),
            Request::create('/api/contract'),
            HttpKernelInterface::MAIN_REQUEST,
            $exception,
        );
        $listener = self::getContainer()->get(ApiExceptionListener::class);
        self::assertInstanceOf(ApiExceptionListener::class, $listener);
        $listener->onKernelException($event);

        $response = $event->getResponse();
        self::assertNotNull($response);

        return $response;
    }

    /** @return array<mixed> */
    private function bodyOf(Response $response): array
    {
        $decoded = json_decode((string) $response->getContent(), true);
        self::assertIsArray($decoded);

        return $decoded;
    }
}
```

Notes that the file does not carry:
- The container listener runs at `kernel.debug = true`. That changes nothing for a deliberate row. It also explains why the opaque rows assert `type`, `title` and `status` only (Amendment 1).
- `assertSame` on two arrays compares key order too. The expected arrays follow `ApiProblem::toArray()` order (`type`, `title`, `status`, `detail`, `errors`), then the extension members.
- The `'invalid catalog document, message withheld'` and `'no comments feed, message withheld'` rows pin what `AdminCatalogImportController:66,81` and `EntryCommentsController:40` send today. Task 4 swaps their first element to the domain exception.

- [ ] **Step 2: Run it.**

Run: `php bin/phpunit tests/Http/Problem/ProblemContractTest.php`
Expected: `OK (65 tests, 394 assertions)`.

This is untouched code, so any failure means a row is wrong. Re-read the exception's source and fix the row, never the code. If the test count is not 65, a row was dropped or duplicated.

- [ ] **Step 3: Break it on purpose.**

In `src/Exception/LastAdminException.php`, change `'Last administrator',` to `'Last admin',`.

Run: `php bin/phpunit tests/Http/Problem/ProblemContractTest.php --filter 'last admin'`
Expected: FAIL. The message reads `Failed asserting that two arrays are identical`, and the diff shows `'title' => 'Last admin'`.

Then edit `'Last admin',` back to `'Last administrator',` by hand. Do not use `git checkout --`.

Run the Step 2 command again. Expected: `OK (65 tests, 394 assertions)`.

- [ ] **Step 4: Commit.**

```bash
git status
git add tests/Http/Problem/ProblemContractTest.php
git commit -m "test(#1160): pin the problem+json wire contract"
```

---
### Task 2: Catalog, factory and mapper interface; the listener, login handler and JWT listener delegate

**Files:**
- Move (`git mv`):
  - `src/Http/ApiProblem.php` → `src/Http/Problem/ApiProblem.php`
  - `src/Http/ResolvedProblem.php` → `src/Http/Problem/ResolvedProblem.php`
  - `tests/Http/ApiProblemTest.php` → `tests/Http/Problem/ApiProblemTest.php`
- Create: `src/Http/Problem/ExceptionProblems.php`, `src/Http/Problem/ProblemCatalog.php` and `src/Http/Problem/ProblemResponseFactory.php`.
- Modify:
  - `src/EventListener/ApiExceptionListener.php`, `src/EventListener/JwtFailureResponseListener.php` and `src/Security/LoginFailureHandler.php` (all three rewritten in full).
  - `src/EventListener/InsecureProductionConfigGuard.php:34` and `src/Service/Search/EntrySearchRequestFactory.php:54-66`.
  - `tests/Exception/OAuth/OAuthExceptionTest.php:11`.
- Test:
  - Create `tests/Http/Problem/ProblemCatalogTest.php`, `tests/Http/Problem/ProblemResponseFactoryTest.php` and `tests/Security/LoginFailureHandlerTest.php`.
  - Rewrite `tests/EventListener/ApiExceptionListenerTest.php`.

**Interfaces:**
- Consumes: the `ProblemContractTest` from Task 1. It must stay green, unchanged.
- Produces:

```php
namespace App\Http\Problem;

final readonly class ApiProblem          // moved; constructor unchanged
{
    /** @param array<string, list<string>> $errors */
    public function __construct(string $type, string $title, int $status, ?string $detail = null, array $errors = []);
    /** @return array<string, mixed> */
    public function toArray(): array;
}

final readonly class ResolvedProblem     // moved; constructor unchanged
{
    /** @param array<array-key, mixed> $headers @param array<string, mixed> $extensions */
    public function __construct(ApiProblem $problem, array $headers = [], array $extensions = []);
}

#[AutoconfigureTag('app.exception_problems')]
interface ExceptionProblems
{
    public function resolve(\Throwable $exception): ?ResolvedProblem;
}

final readonly class ProblemCatalog
{
    /** @param iterable<ExceptionProblems> $mappers */
    public function __construct(iterable $mappers, LoggerInterface $logger, bool $debug);
    public function resolve(\Throwable $exception, string $path): ResolvedProblem;
}

final readonly class ProblemResponseFactory
{
    public function create(ResolvedProblem $resolved): JsonResponse;
}
```

- The temporary `ProblemCatalog::legacy(ApiException)` arm lives until Task 7. Task 5 removes its three `instanceof` branches.
- A mapper returns `new ResolvedProblem(new ApiProblem(...), $headers, $extensions)`. It returns `null` for an exception that belongs to another module.

- [ ] **Step 1: Write the failing tests.**

Create `tests/Http/Problem/ProblemCatalogTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Http\Problem;

use App\Http\Problem\ApiProblem;
use App\Http\Problem\ExceptionProblems;
use App\Http\Problem\ProblemCatalog;
use App\Http\Problem\ResolvedProblem;
use App\Security\AccountStatusException;
use Lexik\Bundle\JWTAuthenticationBundle\Exception\InvalidTokenException as RevokedJwtException;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

final class ProblemCatalogTest extends TestCase
{
    public function testTheMapperThatOwnsAnExceptionAnswersForItInAnyOrder(): void
    {
        $domain = self::problem('domain_failure');
        $overflow = self::problem('overflow_failure');
        $mappers = [
            self::claiming(\DomainException::class, $domain),
            self::claiming(\OverflowException::class, $overflow),
        ];

        foreach ([$mappers, array_reverse($mappers)] as $ordering) {
            $catalog = new ProblemCatalog($ordering, new NullLogger(), debug: false);

            self::assertSame($domain, $catalog->resolve(new \DomainException('x'), '/api/x'));
            self::assertSame($overflow, $catalog->resolve(new \OverflowException('x'), '/api/x'));
        }
    }

    public function testAnExceptionNoMapperClaimsIsALoggedOpaque500(): void
    {
        $exception = new \LogicException('DB password is hunter2');
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('error')->with(
            'Unhandled API exception',
            ['exception' => $exception, 'path' => '/api/entries'],
        );
        $catalog = new ProblemCatalog(
            [self::claiming(\DomainException::class, self::problem('domain_failure'))],
            $logger,
            debug: false,
        );

        $resolved = $catalog->resolve($exception, '/api/entries');

        self::assertSame(
            ['type' => 'internal_error', 'title' => 'Internal server error', 'status' => 500],
            $resolved->problem->toArray(),
        );
        self::assertSame([], $resolved->headers);
        self::assertSame([], $resolved->extensions);
    }

    public function testDebugShowsTheMessageOfAnUnexpectedException(): void
    {
        $catalog = new ProblemCatalog([], new NullLogger(), debug: true);

        self::assertSame('boom', $catalog->resolve(new \LogicException('boom'), '/api/x')->problem->detail);
    }

    public function testNoMapperIsOfferedAnAuthenticationException(): void
    {
        $catalog = new ProblemCatalog(
            [self::claiming(\Throwable::class, self::problem('claimed_by_a_mapper'))],
            new NullLogger(),
            debug: false,
        );

        foreach ([new AccountStatusException('suspended'), new RevokedJwtException('revoked')] as $exception) {
            $resolved = $catalog->resolve($exception, '/api/me');

            self::assertSame(
                [
                    'type' => 'unauthorized',
                    'title' => 'Unauthorized',
                    'status' => 401,
                    'detail' => 'Authentication is required to access this resource.',
                ],
                $resolved->problem->toArray(),
            );
            self::assertSame([], $resolved->extensions);
        }
    }

    private static function problem(string $type): ResolvedProblem
    {
        return new ResolvedProblem(new ApiProblem($type, 'Title', 409));
    }

    /** @param class-string<\Throwable> $class */
    private static function claiming(string $class, ResolvedProblem $problem): ExceptionProblems
    {
        return new class ($class, $problem) implements ExceptionProblems {
            /** @param class-string<\Throwable> $class */
            public function __construct(private readonly string $class, private readonly ResolvedProblem $problem)
            {
            }

            public function resolve(\Throwable $exception): ?ResolvedProblem
            {
                return $exception instanceof $this->class ? $this->problem : null;
            }
        };
    }
}
```

Create `tests/Http/Problem/ProblemResponseFactoryTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Http\Problem;

use App\Http\Problem\ApiProblem;
use App\Http\Problem\ProblemResponseFactory;
use App\Http\Problem\ResolvedProblem;
use PHPUnit\Framework\TestCase;

final class ProblemResponseFactoryTest extends TestCase
{
    public function testTheProblemContentTypeBeatsAPassThroughOneAndOtherHeadersSurvive(): void
    {
        $response = (new ProblemResponseFactory())->create(new ResolvedProblem(
            new ApiProblem('unauthorized', 'Unauthorized', 401),
            ['Content-Type' => 'text/html', 'WWW-Authenticate' => 'Bearer'],
        ));

        self::assertSame('application/problem+json', $response->headers->get('Content-Type'));
        self::assertSame('Bearer', $response->headers->get('WWW-Authenticate'));
    }

    public function testExtensionMembersFollowTheProblemMembers(): void
    {
        $response = (new ProblemResponseFactory())->create(new ResolvedProblem(
            new ApiProblem('account_not_active', 'Account not active', 403, 'This account has been suspended.'),
            extensions: ['accountStatus' => 'suspended'],
        ));

        self::assertSame(403, $response->getStatusCode());
        self::assertSame(
            '{"type":"account_not_active","title":"Account not active","status":403,'
            . '"detail":"This account has been suspended.","accountStatus":"suspended"}',
            $response->getContent(),
        );
    }
}
```

Create `tests/Security/LoginFailureHandlerTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Security\LoginFailureHandler;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Core\Exception\TooManyLoginAttemptsAuthenticationException;

final class LoginFailureHandlerTest extends KernelTestCase
{
    /** @return iterable<string, array{?int, string}> */
    public static function lockouts(): iterable
    {
        yield 'three minutes left' => [3, '180'];
        yield 'no threshold' => [null, '60'];
        yield 'zero minutes left' => [0, '60'];
    }

    #[DataProvider('lockouts')]
    public function testALockoutReportsSymfonysMinutesAsSeconds(?int $minutes, string $retryAfter): void
    {
        $handler = self::getContainer()->get(LoginFailureHandler::class);
        self::assertInstanceOf(LoginFailureHandler::class, $handler);

        $response = $handler->onAuthenticationFailure(
            Request::create('/api/auth/login', 'POST', content: '{"email":"someone@example.test"}'),
            new TooManyLoginAttemptsAuthenticationException($minutes),
        );

        self::assertSame(429, $response->getStatusCode());
        self::assertSame($retryAfter, $response->headers->get('Retry-After'));
    }
}
```

- [ ] **Step 2: Run them to verify they fail.**

Run: `php bin/phpunit tests/Http/Problem/ProblemCatalogTest.php tests/Http/Problem/ProblemResponseFactoryTest.php tests/Security/LoginFailureHandlerTest.php`
Expected: FAIL (`Tests: 9, Errors: 6, Failures: 3`), with these reasons:
- The catalog and factory tests error with `Class "App\Http\Problem\ProblemCatalog" not found`, `…ProblemResponseFactory" not found` or `…ResolvedProblem" not found`.
- `LoginFailureHandlerTest` fails all three rows with `Failed asserting that two strings are identical`: `'900'` against `'180'` or `'60'`. The handler still hard-codes 900.

- [ ] **Step 3: Move the two value objects and their test.**

```bash
mkdir -p src/Http/Problem tests/Http/Problem
git mv src/Http/ApiProblem.php src/Http/Problem/ApiProblem.php
git mv src/Http/ResolvedProblem.php src/Http/Problem/ResolvedProblem.php
git mv tests/Http/ApiProblemTest.php tests/Http/Problem/ApiProblemTest.php
```

In `src/Http/Problem/ApiProblem.php`, change only the namespace line:

```php
namespace App\Http;
```
→
```php
namespace App\Http\Problem;
```

Replace the whole of `src/Http/Problem/ResolvedProblem.php` with:

```php
<?php

declare(strict_types=1);

namespace App\Http\Problem;

/** An exception's problem document plus the headers and extension members that travel with it. */
final readonly class ResolvedProblem
{
    /**
     * @param array<array-key, mixed> $headers    HttpExceptionInterface::getHeaders() is an untyped array
     * @param array<string, mixed>    $extensions RFC 7807 extension members
     */
    public function __construct(
        public ApiProblem $problem,
        public array $headers = [],
        public array $extensions = [],
    ) {
    }
}
```

In `tests/Http/Problem/ApiProblemTest.php`:

```php
namespace App\Tests\Http;

use App\Http\ApiProblem;
```
→
```php
namespace App\Tests\Http\Problem;

use App\Http\Problem\ApiProblem;
```

In `tests/Exception/OAuth/OAuthExceptionTest.php` (Task 5 moves and rewrites it):

```php
use App\Http\ApiProblem;
```
→
```php
use App\Http\Problem\ApiProblem;
```

- [ ] **Step 4: Create the interface, the catalog and the factory.**

`src/Http/Problem/ExceptionProblems.php`:

```php
<?php

declare(strict_types=1);

namespace App\Http\Problem;

use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/** One module's exceptions mapped to problem documents; null means the exception belongs to another module. */
#[AutoconfigureTag('app.exception_problems')]
interface ExceptionProblems
{
    public function resolve(\Throwable $exception): ?ResolvedProblem;
}
```

`src/Http/Problem/ProblemCatalog.php`:

```php
<?php

declare(strict_types=1);

namespace App\Http\Problem;

use App\Exception\AccountNotActiveException;
use App\Exception\ApiException;
use App\Exception\RateLimitedException;
use App\Service\Settings\Exception\RelyingPartyChangeRequiresConfirmationException;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Validator\Exception\ValidationFailedException;

final readonly class ProblemCatalog
{
    /** @param iterable<ExceptionProblems> $mappers */
    public function __construct(
        #[AutowireIterator('app.exception_problems')]
        private iterable $mappers,
        private LoggerInterface $logger,
        #[Autowire('%kernel.debug%')]
        private bool $debug,
    ) {
    }

    public function resolve(\Throwable $exception, string $path): ResolvedProblem
    {
        // Before the mappers, so none can claim one: a revoked or suspended token must stay the opaque 401.
        if ($exception instanceof AuthenticationException) {
            return self::unauthorized();
        }

        return $this->mapped($exception) ?? self::fromFramework($exception) ?? $this->unexpected($exception, $path);
    }

    private function mapped(\Throwable $exception): ?ResolvedProblem
    {
        foreach ($this->mappers as $mapper) {
            $resolved = $mapper->resolve($exception);
            if (null !== $resolved) {
                return $resolved;
            }
        }

        return $exception instanceof ApiException ? self::legacy($exception) : null;
    }

    private static function fromFramework(\Throwable $exception): ?ResolvedProblem
    {
        return match (true) {
            $exception instanceof HttpExceptionInterface
                => new ResolvedProblem(self::fromHttpException($exception), $exception->getHeaders()),
            $exception instanceof AccessDeniedException => new ResolvedProblem(new ApiProblem(
                'forbidden',
                'Forbidden',
                Response::HTTP_FORBIDDEN,
                'You do not have permission to access this resource.',
            )),
            default => null,
        };
    }

    private function unexpected(\Throwable $exception, string $path): ResolvedProblem
    {
        // The message may hold connection strings, tokens or row data: it goes to the log, never to the client.
        $this->logger->error('Unhandled API exception', ['exception' => $exception, 'path' => $path]);

        return new ResolvedProblem(new ApiProblem(
            'internal_error',
            'Internal server error',
            Response::HTTP_INTERNAL_SERVER_ERROR,
            $this->debug ? $exception->getMessage() : null,
        ));
    }

    private static function unauthorized(): ResolvedProblem
    {
        return new ResolvedProblem(new ApiProblem(
            'unauthorized',
            'Unauthorized',
            Response::HTTP_UNAUTHORIZED,
            'Authentication is required to access this resource.',
        ));
    }

    private static function fromHttpException(HttpExceptionInterface $exception): ApiProblem
    {
        $previous = $exception->getPrevious();
        // #[MapRequestPayload] reports constraint failures as a ValidationFailedException inside a 422.
        if ($previous instanceof ValidationFailedException) {
            return new ApiProblem(
                'validation_error',
                'Validation failed',
                Response::HTTP_UNPROCESSABLE_ENTITY,
                'One or more fields are invalid.',
                self::fieldErrors($previous),
            );
        }

        $status = $exception->getStatusCode();

        return new ApiProblem(
            match ($status) {
                Response::HTTP_UNAUTHORIZED => 'unauthorized',
                Response::HTTP_FORBIDDEN => 'forbidden',
                Response::HTTP_NOT_FOUND => 'not_found',
                Response::HTTP_METHOD_NOT_ALLOWED => 'method_not_allowed',
                Response::HTTP_TOO_MANY_REQUESTS => 'rate_limited',
                default => $status >= 500 ? 'internal_error' : 'request_error',
            },
            Response::$statusTexts[$status] ?? 'Error',
            $status,
        );
    }

    /** @return array<string, list<string>> */
    private static function fieldErrors(ValidationFailedException $failure): array
    {
        $errors = [];
        foreach ($failure->getViolations() as $violation) {
            $errors[$violation->getPropertyPath()][] = (string) $violation->getMessage();
        }

        return $errors;
    }

    private static function legacy(ApiException $exception): ResolvedProblem
    {
        $problem = new ApiProblem(
            $exception->type,
            $exception->title,
            $exception->status,
            $exception->detail,
            $exception->errors,
        );

        $headers = [];
        if ($exception instanceof RateLimitedException) {
            $headers['Retry-After'] = (string) $exception->retryAfterSeconds;
        }

        $extensions = [];
        if ($exception instanceof AccountNotActiveException) {
            $extensions['accountStatus'] = $exception->accountStatus;
        }

        if ($exception instanceof RelyingPartyChangeRequiresConfirmationException) {
            $extensions['invalidatedPasskeyCount'] = $exception->invalidatedPasskeyCount;
        }

        return new ResolvedProblem($problem, $headers, $extensions);
    }
}
```

`src/Http/Problem/ProblemResponseFactory.php`:

```php
<?php

declare(strict_types=1);

namespace App\Http\Problem;

use Symfony\Component\HttpFoundation\JsonResponse;

final readonly class ProblemResponseFactory
{
    public function create(ResolvedProblem $resolved): JsonResponse
    {
        // array_merge, not +: the union keeps the LEFT value, so a pass-through Content-Type would win.
        return new JsonResponse(
            array_merge($resolved->problem->toArray(), $resolved->extensions),
            $resolved->problem->status,
            array_merge($resolved->headers, ['Content-Type' => 'application/problem+json']),
        );
    }
}
```

- [ ] **Step 5: Rewrite `ApiExceptionListener` and shrink its test.**

Replace the whole of `src/EventListener/ApiExceptionListener.php` with:

```php
<?php

declare(strict_types=1);

namespace App\EventListener;

use App\Http\Problem\ProblemCatalog;
use App\Http\Problem\ProblemResponseFactory;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;

/** Renders every exception under /api or /maintenance as problem+json; controllers never build an error by hand. */
#[AsEventListener(event: ExceptionEvent::class)]
final readonly class ApiExceptionListener
{
    public function __construct(
        private ProblemCatalog $problems,
        private ProblemResponseFactory $responses,
    ) {
    }

    public function onKernelException(ExceptionEvent $event): void
    {
        $path = $event->getRequest()->getPathInfo();
        if (!str_starts_with($path, '/api') && !str_starts_with($path, '/maintenance')) {
            return;
        }

        $event->setResponse($this->responses->create($this->problems->resolve($event->getThrowable(), $path)));
    }
}
```

Replace the whole of `tests/EventListener/ApiExceptionListenerTest.php` with the file below. Its other old cases now live elsewhere:
- As `ProblemContractTest` rows: `'validation'`, `'backup load failed, driver message withheld'`, `'rate limited'`, `'payload validation failure'`, `'http not found'`, `'http 401 keeps www-authenticate'`, `'authentication required'`, `'bad credentials'` and `'security access denied'`.
- In `ProblemCatalogTest::testAnExceptionNoMapperClaimsIsALoggedOpaque500`.
- In `ProblemResponseFactoryTest`.

```php
<?php

declare(strict_types=1);

namespace App\Tests\EventListener;

use App\EventListener\ApiExceptionListener;
use App\Http\Problem\ProblemCatalog;
use App\Http\Problem\ProblemResponseFactory;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\KernelInterface;

final class ApiExceptionListenerTest extends TestCase
{
    private function event(string $path, \Throwable $exception): ExceptionEvent
    {
        return new ExceptionEvent(
            $this->createStub(KernelInterface::class),
            Request::create($path),
            HttpKernelInterface::MAIN_REQUEST,
            $exception,
        );
    }

    private function listener(): ApiExceptionListener
    {
        return new ApiExceptionListener(
            new ProblemCatalog([], new NullLogger(), debug: false),
            new ProblemResponseFactory(),
        );
    }

    /** @return array<mixed> */
    private function payloadOf(Response $response): array
    {
        $decoded = json_decode((string) $response->getContent(), true);
        self::assertIsArray($decoded);

        return $decoded;
    }

    public function testIgnoresNonApiPaths(): void
    {
        $event = $this->event('/some/page', new NotFoundHttpException());
        $this->listener()->onKernelException($event);

        self::assertNull($event->getResponse());
    }

    /** /maintenance is outside the firewall, but its errors must still be problem+json, not Symfony's HTML page. */
    public function testMaintenancePathsAreAlsoHandled(): void
    {
        $event = $this->event('/maintenance/refresh', new \LogicException('boom'));
        $this->listener()->onKernelException($event);

        self::assertNotNull($event->getResponse());
    }

    /**
     * setResponse() stops propagation, so a listener that answers first ends the chain. That is why Lexik's 401
     * is normalised by JwtFailureResponseListener on Lexik's own events, not here.
     */
    public function testAnEarlierListenersResponseEndsTheChain(): void
    {
        $dispatcher = new EventDispatcher();

        $firstResponse = new Response('first', 418);
        $dispatcher->addListener(
            ExceptionEvent::class,
            static fn (ExceptionEvent $event) => $event->setResponse($firstResponse),
            priority: 1,
        );
        $dispatcher->addListener(
            ExceptionEvent::class,
            $this->listener()->onKernelException(...),
            priority: 0,
        );

        $event = $this->event('/api/thing', new NotFoundHttpException());
        $dispatcher->dispatch($event, ExceptionEvent::class);

        self::assertTrue($event->isPropagationStopped());
        self::assertSame(
            $firstResponse,
            $event->getResponse(),
            'ApiExceptionListener must never have run: setResponse() stopped propagation before it.',
        );
    }

    public function testTheListenerRunsWhenNoEarlierListenerAnswered(): void
    {
        $dispatcher = new EventDispatcher();
        $dispatcher->addListener(ExceptionEvent::class, $this->listener()->onKernelException(...));

        $event = $this->event('/api/thing', new NotFoundHttpException());
        $dispatcher->dispatch($event, ExceptionEvent::class);

        $response = $event->getResponse();
        self::assertNotNull($response);
        self::assertSame(404, $response->getStatusCode());
        self::assertSame('not_found', $this->payloadOf($response)['type']);
    }
}
```

- [ ] **Step 6: Rewrite `JwtFailureResponseListener`.**

Replace the whole of `src/EventListener/JwtFailureResponseListener.php` with the code below. Every Lexik failure is an `AuthenticationException`, and `ProblemCatalog::resolve()` answers those before any mapper. So a stolen token for a suspended account keeps getting the opaque 401. The `'/api'` path is only ever read by the opaque-500 branch, which an `AuthenticationException` never reaches.

```php
<?php

declare(strict_types=1);

namespace App\EventListener;

use App\Http\Problem\ProblemCatalog;
use App\Http\Problem\ProblemResponseFactory;
use Lexik\Bundle\JWTAuthenticationBundle\Event\AuthenticationFailureEvent;
use Lexik\Bundle\JWTAuthenticationBundle\Events;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/**
 * Lexik answers JWT failures itself and stops kernel.exception, so its own events are hooked here. Every branch
 * is the opaque 401: whoever presents a suspended account's token may have stolen it, and learns nothing.
 */
#[AsEventListener(event: Events::JWT_NOT_FOUND, method: 'onJwtFailure')]
#[AsEventListener(event: Events::JWT_INVALID, method: 'onJwtFailure')]
#[AsEventListener(event: Events::JWT_EXPIRED, method: 'onJwtFailure')]
#[AsEventListener(event: Events::AUTHENTICATION_FAILURE, method: 'onJwtFailure')]
final readonly class JwtFailureResponseListener
{
    public function __construct(
        private ProblemCatalog $problems,
        private ProblemResponseFactory $responses,
    ) {
    }

    public function onJwtFailure(AuthenticationFailureEvent $event): void
    {
        $event->setResponse($this->responses->create($this->problems->resolve($event->getException(), '/api')));
    }
}
```

- [ ] **Step 7: Rewrite `LoginFailureHandler`.**

Replace the whole of `src/Security/LoginFailureHandler.php` with the code below. The `submittedIdentifier()` body is unchanged. Its docblock and the class docblock are trimmed to the rules they carry.

```php
<?php

declare(strict_types=1);

namespace App\Security;

use App\Exception\AccountNotActiveException;
use App\Exception\InvalidCredentialsException;
use App\Exception\RateLimitedException;
use App\Http\Problem\ProblemCatalog;
use App\Http\Problem\ProblemResponseFactory;
use App\Service\Passkey\Exception\UnknownPasskeyCredentialException;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Core\Exception\TooManyLoginAttemptsAuthenticationException;
use Symfony\Component\Security\Http\Authentication\AuthenticationFailureHandlerInterface;

/**
 * The firewall answers before kernel.exception, so login failures resolve here. A bad password and an unknown
 * email give one response (no enumeration oracle); only an unknown passkey keeps its own type (#727).
 */
final readonly class LoginFailureHandler implements AuthenticationFailureHandlerInterface
{
    private const int SECONDS_PER_MINUTE = 60;

    public function __construct(
        private LoginTimingEqualizer $timingEqualizer,
        private ProblemCatalog $problems,
        private ProblemResponseFactory $responses,
    ) {
    }

    public function onAuthenticationFailure(Request $request, AuthenticationException $exception): Response
    {
        // Before building the response: the delay must land inside the window the client measures.
        $this->timingEqualizer->equalize($exception, $this->submittedIdentifier($request));

        return $this->responses->create(
            $this->problems->resolve(self::domainFailure($exception), $request->getPathInfo()),
        );
    }

    private static function domainFailure(AuthenticationException $exception): \Throwable
    {
        $previous = $exception->getPrevious();

        return match (true) {
            $previous instanceof UnknownPasskeyCredentialException => $previous,
            $exception instanceof AccountStatusException => new AccountNotActiveException($exception->accountStatus),
            $exception instanceof TooManyLoginAttemptsAuthenticationException
                => new RateLimitedException(self::lockoutSeconds($exception)),
            default => new InvalidCredentialsException(),
        };
    }

    /** LoginThrottlingListener reports the remaining lockout in whole minutes; a manual throw may carry none. */
    private static function lockoutSeconds(TooManyLoginAttemptsAuthenticationException $exception): int
    {
        $minutes = $exception->getMessageData()['%minutes%'];

        return \is_int($minutes) && $minutes > 0 ? $minutes * self::SECONDS_PER_MINUTE : self::SECONDS_PER_MINUTE;
    }

    /**
     * Read from the body, not the exception: the token is not populated for every failure mode. json_decode,
     * not Request::toArray(), which throws on a non-array body and would turn this 401 into a 500.
     */
    private function submittedIdentifier(Request $request): ?string
    {
        $payload = json_decode($request->getContent(), true);

        if (!\is_array($payload)) {
            return null;
        }

        $email = $payload['email'] ?? null;

        return \is_string($email) && '' !== $email ? $email : null;
    }
}
```

`tests/Controller/Api/LoginTest.php:195-212` keeps asserting `Retry-After: 900`. A fresh lockout is `ceil(14.9x) = 15` minutes, and 15 × 60 = 900.

- [ ] **Step 8: Fix the two comments that name the listener's old internals.**

`src/EventListener/InsecureProductionConfigGuard.php:34`:

```php
 * log (ApiExceptionListener suppresses exception messages outside debug), so
```
→
```php
 * log (ProblemCatalog suppresses exception messages outside debug), so
```

`src/Service/Search/EntrySearchRequestFactory.php:54-66`:

```php
    /**
     * Reads one query parameter as a plain string rather than through
     * `getString()`, so `q[]=x` reports the same `validation_error` — with a
     * message naming the field — as every other invalid input to this endpoint.
     *
     * `getString()` would not break: it throws `BadRequestException`, converted
     * by `HttpKernel::handle()` to `BadRequestHttpException` before
     * `kernel.exception` fires, so `ApiExceptionListener` already answers a clean
     * 400 `request_error` (an earlier version of this comment claimed a 500;
     * that was measured false, see #410). The real choice is smaller: a 400 with
     * no field detail, or a 422 naming WHICH parameter was malformed, matching
     * the 422 this endpoint already answers for a too-short or over-long `q`.
     */
```
→
```php
    /**
     * A plain string read, not getString(): `q[]=x` then gets the validation_error naming the field that every
     * other invalid input here gets, instead of a bare request_error without field detail (#410).
     */
```

- [ ] **Step 9: Run the tests.**

Run: `php bin/phpunit tests/Http/Problem tests/EventListener/ApiExceptionListenerTest.php tests/Security tests/Controller/Api/LoginTest.php tests/Controller/Api/JwtAccessTest.php tests/Controller/Api/PasskeyLoginTest.php`
Expected: PASS (`OK`). In particular:
- `ProblemContractTest` still passes all 65 tests with no edit. The legacy arm renders every `ApiException`.
- `LoginFailureHandlerTest` passes 3 of 3.
- `JwtAccessTest::testSuspendedTokenDoesNotLeakAccountStatus` passes.

- [ ] **Step 10: Run the static gates.**

Run: `bin/console cache:warmup --env=dev && composer stan && composer md && composer cs`
Expected:
- `composer stan` reports `[OK] No errors`.
- `composer md` prints no finding.
- `composer cs` prints no error.

Then run PhpStorm `lint_files` on every PHP file this task touched. Expected: no ERROR and no WARNING.

- [ ] **Step 11: Commit.**

```bash
git status
git add src/Http src/EventListener src/Security/LoginFailureHandler.php src/Service/Search/EntrySearchRequestFactory.php \
  tests/Http tests/EventListener/ApiExceptionListenerTest.php tests/Security/LoginFailureHandlerTest.php \
  tests/Exception/OAuth/OAuthExceptionTest.php
git commit -m "refactor(#1160): one problem catalog and response factory for every error path"
```

---
### Task 3: Delete the AI and recommendation twins; map the domain exceptions

**Files:**
- Create:
  - `src/Service/Ai/Exception/AiKeyUnreadableException.php`
  - `src/Http/Problem/AiProblems.php`
  - `src/Http/Problem/RecommendationRunProblems.php`
- Delete: the following files in `src/Exception/`:
  - `AiNotConfiguredApiException.php`
  - `AiConfigurationNotFoundApiException.php`
  - `TooManyAiConfigurationsApiException.php`
  - `AiKeyUnreadableApiException.php`
  - `AiProviderApiException.php`
  - `NoActiveRecommendationRunApiException.php`
  - `NoResumableRecommendationRunApiException.php`
  - `RecommendationRunActiveApiException.php`
- Modify:
  - `src/Controller/Api/AiSettingsController.php` and `src/Controller/Api/RecommendationRunController.php`, both rewritten in full.
  - `src/Service/Ai/AiProviderConfigurator.php`, `src/Service/Ai/ProviderConnectionFactory.php`, `src/Service/Recommendation/RecommendationRunAdvancer.php` and `src/Service/Worker/WorkerRunSweep.php`.
- Test:
  - `tests/Http/Problem/ProblemContractTest.php`
  - `tests/Service/Ai/AiProviderConfiguratorTest.php`
  - `tests/Service/Recommendation/RecommendationRunAdvancerTest.php`
  - `tests/Service/Worker/AdvanceRecommendationRunsHandlerTest.php` (docblock only)

**Interfaces:**
- Consumes: `ExceptionProblems`, `ResolvedProblem` and `ApiProblem` from Task 2.
- Produces:
  - `App\Service\Ai\Exception\AiKeyUnreadableException extends \RuntimeException`. It has no constructor of its own and is thrown with `previous`.
  - `AiProviderConfigurator::credentials(AiProviderSettings): ProviderCredentials` now throws `AiKeyUnreadableException` where it used to throw `SecretUnreadableException`.
  - `App\Http\Problem\AiProblems` and `App\Http\Problem\RecommendationRunProblems`, both `final readonly` and implementing `ExceptionProblems`.

`SecretUnreadableException` is not AI-owned. The Mail, Proxy and Grafana ciphers, `ConcurrentFeedFetcher` and `FailoverRequestSender` throw it too. So `AiProblems` never maps it. The AI module wraps it at its single decrypt point, and a bare `SecretUnreadableException` stays an opaque 500.

- [ ] **Step 1: Create the wrapper type.** It has to exist before the red tests can compile.

`src/Service/Ai/Exception/AiKeyUnreadableException.php`:

```php
<?php

declare(strict_types=1);

namespace App\Service\Ai\Exception;

/**
 * The stored API key no longer opens (a rotated instance secret, an edited or a moved row). Its own type,
 * apart from the provider refusals: only re-entering the key helps, and the client tells them apart by type.
 */
final class AiKeyUnreadableException extends \RuntimeException
{
}
```

- [ ] **Step 2: Swap the contract rows to the domain exceptions and add the cross-module guard row (red-first).**

In `tests/Http/Problem/ProblemContractTest.php`, delete these eight `use` lines:

```php
use App\Exception\AiConfigurationNotFoundApiException;
use App\Exception\AiKeyUnreadableApiException;
use App\Exception\AiNotConfiguredApiException;
use App\Exception\AiProviderApiException;
use App\Exception\NoActiveRecommendationRunApiException;
use App\Exception\NoResumableRecommendationRunApiException;
use App\Exception\RecommendationRunActiveApiException;
use App\Exception\TooManyAiConfigurationsApiException;
```

and add these twelve after `use App\Service\Settings\Exception\RelyingPartyChangeRequiresConfirmationException;`:

```php
use App\Service\Ai\Exception\AiKeyUnreadableException;
use App\Service\Ai\Exception\AiNotConfiguredException;
use App\Service\Ai\Exception\ConfigurationNotFoundException;
use App\Service\Ai\Exception\CredentialsRejectedException;
use App\Service\Ai\Exception\ModelNotOfferedException;
use App\Service\Ai\Exception\ModelRequiredForActivationException;
use App\Service\Ai\Exception\ProviderUnreachableException;
use App\Service\Ai\Exception\TooManyConfigurationsException;
use App\Service\Crypto\Exception\SecretUnreadableException;
use App\Service\Recommendation\Exception\NoActiveRecommendationRunException;
use App\Service\Recommendation\Exception\NoResumableRecommendationRunException;
use App\Service\Recommendation\Exception\RecommendationRunActiveException;
```

Change the first element of these eleven rows. The expected bodies stay exactly as they are. Each row carries a domain message that differs from the fixed detail, which proves the mapper does not leak it.

| Row key | Before | After |
|---|---|---|
| `'ai not configured'` | `new AiNotConfiguredApiException(),` | `new AiNotConfiguredException('This account has no active AI configuration.'),` |
| `'ai configuration not found'` | `new AiConfigurationNotFoundApiException(),` | `new ConfigurationNotFoundException('No AI configuration 7 for this account.'),` |
| `'too many ai configurations'` | `new TooManyAiConfigurationsApiException(),` | `new TooManyConfigurationsException('This account already holds the maximum number of AI configurations.'),` |
| `'ai key unreadable'` | `new AiKeyUnreadableApiException(new \RuntimeException('The stored secret failed its integrity check.')),` | `new AiKeyUnreadableException('The stored API key cannot be opened.'),` |
| `'ai provider unreachable'` | `new AiProviderApiException('That address did not answer.'),` | `new ProviderUnreachableException('That address did not answer.'),` |
| `'ai credentials rejected'` | `new AiProviderApiException('That provider refused the API key.'),` | `new CredentialsRejectedException('That provider refused the API key.'),` |
| `'ai model not offered'` | `new AiProviderApiException('That provider does not offer "gpt-9".'),` | `new ModelNotOfferedException('That provider does not offer "gpt-9".'),` |
| `'ai model required for activation'` | `new AiProviderApiException('Choose a model before activating this configuration.'),` | `new ModelRequiredForActivationException('Choose a model before activating this configuration.'),` |
| `'no active recommendation run'` | `new NoActiveRecommendationRunApiException(),` | `new NoActiveRecommendationRunException(),` |
| `'no resumable recommendation run'` | `new NoResumableRecommendationRunApiException(),` | `new NoResumableRecommendationRunException('There is no failed run to resume.'),` |
| `'recommendation run active'` | `new RecommendationRunActiveApiException(),` | `new RecommendationRunActiveException(),` |

In `unexpectedFailures()`:

```php
        yield 'doctrine proxy miss' => [
            EntityNotFoundException::fromClassNameAndIdentifier('App\Entity\Tag', ['id' => '1']),
        ];
    }
```
→
```php
        yield 'doctrine proxy miss' => [
            EntityNotFoundException::fromClassNameAndIdentifier('App\Entity\Tag', ['id' => '1']),
        ];
        yield 'unreadable secret outside ai' => [
            new SecretUnreadableException('The stored secret failed its integrity check.'),
        ];
    }
```

Rename the exception in the two tests that expect it:

```bash
perl -pi -e 's/^use App\\Service\\Crypto\\Exception\\SecretUnreadableException;$/use App\\Service\\Ai\\Exception\\AiKeyUnreadableException;/; s/\bSecretUnreadableException\b/AiKeyUnreadableException/g' \
  tests/Service/Ai/AiProviderConfiguratorTest.php tests/Service/Recommendation/RecommendationRunAdvancerTest.php
```

That turns `AiProviderConfiguratorTest.php:169` into `$this->expectException(AiKeyUnreadableException::class);`. In `RecommendationRunAdvancerTest.php:2791-2792` it turns into:

```php
            self::fail('Expected an AiKeyUnreadableException.');
        } catch (AiKeyUnreadableException) {
```

- [ ] **Step 3: Run the red tests.**

Run: `php bin/phpunit tests/Http/Problem/ProblemContractTest.php tests/Service/Ai/AiProviderConfiguratorTest.php --filter 'ai |recommendation run|StoredKeyCannotBeOpened|unreadable secret'`
Expected: FAIL, with these reasons:
- The eleven swapped contract rows fail with `Failed asserting that 500 is identical to 404` (or 409, or 422). Nothing maps the domain exceptions yet.
- `testAStoredKeyCannotBeOpenedUnderAnotherAccount` fails with `Failed asserting that exception of type "App\Service\Crypto\Exception\SecretUnreadableException" matches expected exception "App\Service\Ai\Exception\AiKeyUnreadableException"`.
- The `'unreadable secret outside ai'` row already PASSES. It is a guard, not a red test.

- [ ] **Step 4: Write the two mappers.**

`src/Http/Problem/AiProblems.php`:

```php
<?php

declare(strict_types=1);

namespace App\Http\Problem;

use App\Service\Ai\Exception\AiKeyUnreadableException;
use App\Service\Ai\Exception\AiNotConfiguredException;
use App\Service\Ai\Exception\ConfigurationNotFoundException;
use App\Service\Ai\Exception\CredentialsRejectedException;
use App\Service\Ai\Exception\ModelNotOfferedException;
use App\Service\Ai\Exception\ModelRequiredForActivationException;
use App\Service\Ai\Exception\ProviderUnreachableException;
use App\Service\Ai\Exception\TooManyConfigurationsException;
use Symfony\Component\HttpFoundation\Response;

final readonly class AiProblems implements ExceptionProblems
{
    public function resolve(\Throwable $exception): ?ResolvedProblem
    {
        return match (true) {
            $exception instanceof AiNotConfiguredException => new ResolvedProblem(new ApiProblem(
                'ai_not_configured',
                'No AI provider is configured',
                Response::HTTP_NOT_FOUND,
                'Save an endpoint and an API key first.',
            )),
            $exception instanceof ConfigurationNotFoundException => new ResolvedProblem(new ApiProblem(
                'ai_configuration_not_found',
                'AI configuration not found',
                Response::HTTP_NOT_FOUND,
                'No such AI configuration for this account.',
            )),
            $exception instanceof TooManyConfigurationsException => new ResolvedProblem(new ApiProblem(
                'ai_configuration_limit',
                'Too many AI configurations',
                Response::HTTP_CONFLICT,
                'This account already holds the maximum number of AI configurations.',
            )),
            $exception instanceof AiKeyUnreadableException => new ResolvedProblem(new ApiProblem(
                'ai_key_unreadable',
                'The stored API key could not be read',
                Response::HTTP_UNPROCESSABLE_ENTITY,
                'The stored API key can no longer be read. Enter it again.',
            )),
            self::isProviderRefusal($exception) => new ResolvedProblem(new ApiProblem(
                'ai_provider_rejected',
                'The AI provider could not be used',
                Response::HTTP_UNPROCESSABLE_ENTITY,
                $exception->getMessage(),
            )),
            default => null,
        };
    }

    private static function isProviderRefusal(\Throwable $exception): bool
    {
        return $exception instanceof ProviderUnreachableException
            || $exception instanceof CredentialsRejectedException
            || $exception instanceof ModelNotOfferedException
            || $exception instanceof ModelRequiredForActivationException;
    }
}
```

For the provider refusals, `detail` is `getMessage()`, exactly what the twin received through `new AiProviderApiException($e->getMessage(), $e)`. These messages are authored text: `OpenAiCompatibleChatClient`, `OpenAiCompatibleCatalog`, `ProviderCredentials`, and `AiProviderConfigurator::activate()` and `::offeredDescriptor()` all write client sentences.

`src/Http/Problem/RecommendationRunProblems.php`:

```php
<?php

declare(strict_types=1);

namespace App\Http\Problem;

use App\Service\Recommendation\Exception\NoActiveRecommendationRunException;
use App\Service\Recommendation\Exception\NoResumableRecommendationRunException;
use App\Service\Recommendation\Exception\RecommendationRunActiveException;
use Symfony\Component\HttpFoundation\Response;

final readonly class RecommendationRunProblems implements ExceptionProblems
{
    public function resolve(\Throwable $exception): ?ResolvedProblem
    {
        return match (true) {
            $exception instanceof NoActiveRecommendationRunException => new ResolvedProblem(new ApiProblem(
                'no_active_recommendation_run',
                'No recommendation run is active',
                Response::HTTP_CONFLICT,
                'There is nothing to stop: the run already finished.',
            )),
            $exception instanceof NoResumableRecommendationRunException => new ResolvedProblem(new ApiProblem(
                'no_resumable_recommendation_run',
                'No recommendation run to resume',
                Response::HTTP_CONFLICT,
                'There is no failed run to resume; start a new one instead.',
            )),
            $exception instanceof RecommendationRunActiveException => new ResolvedProblem(new ApiProblem(
                'recommendation_run_active',
                'A recommendation run is still active',
                Response::HTTP_CONFLICT,
                'Wait for the current run to finish, then try again.',
            )),
            default => null,
        };
    }
}
```

- [ ] **Step 5: Wrap `SecretUnreadableException` at the AI module's one decrypt point.**

In `src/Service/Ai/AiProviderConfigurator.php`, replace `credentials()` and its docblock:

```php
    /**
     * Public so a service that must call the provider directly — a prompt
     * runner, say — can reuse the one place that opens the sealed key, rather
     * than duplicating the cipher call.
     *
     * @throws SecretUnreadableException
     */
    public function credentials(AiProviderSettings $settings): ProviderCredentials
    {
        return ProviderCredentials::fromStoredConfiguration(
            $settings->getBaseUrl(),
            $this->cipher->open($this->identify($settings->getUser()), $settings->getSealedSecret()),
        );
    }
```
→
```php
    /**
     * The one place that opens the sealed key; public so every caller of the provider reuses it. An unreadable
     * key becomes the AI module's own exception, so it can never read as another module's secret.
     *
     * @throws AiKeyUnreadableException
     */
    public function credentials(AiProviderSettings $settings): ProviderCredentials
    {
        try {
            $apiKey = $this->cipher->open($this->identify($settings->getUser()), $settings->getSealedSecret());
        } catch (SecretUnreadableException $e) {
            throw new AiKeyUnreadableException('The stored API key cannot be opened.', 0, $e);
        }

        return ProviderCredentials::fromStoredConfiguration($settings->getBaseUrl(), $apiKey);
    }
```

In the same file, add the import:

```php
use App\Service\Ai\Crypto\ApiKeyCipher;
use App\Service\Crypto\Exception\SecretUnreadableException;
```
→
```php
use App\Service\Ai\Crypto\ApiKeyCipher;
use App\Service\Ai\Exception\AiKeyUnreadableException;
use App\Service\Crypto\Exception\SecretUnreadableException;
```

Then rename the remaining three `@throws` tags (lines 120, 175 and 235 of the original):

```bash
perl -pi -e 's/^(\s+\* \@throws )SecretUnreadableException      the source key/${1}AiKeyUnreadableException       the source key/; s/^(\s+\* \@throws )SecretUnreadableException$/${1}AiKeyUnreadableException/' \
  src/Service/Ai/AiProviderConfigurator.php
grep -n "SecretUnreadableException" src/Service/Ai/AiProviderConfigurator.php
```

Expected grep output is exactly two lines: the `use App\Service\Crypto\Exception\SecretUnreadableException;` import and `} catch (SecretUnreadableException $e) {`.

In `src/Service/Ai/ProviderConnectionFactory.php`:

```php
use App\Entity\AiProviderSettings;
use App\Service\Crypto\Exception\SecretUnreadableException;
```
→
```php
use App\Entity\AiProviderSettings;
use App\Service\Ai\Exception\AiKeyUnreadableException;
```

and

```php
     * @throws SecretUnreadableException
```
→
```php
     * @throws AiKeyUnreadableException
```

- [ ] **Step 6: The advancer and the worker sweep follow the new type.**

```bash
perl -pi -e 's/^use App\\Service\\Crypto\\Exception\\SecretUnreadableException;$/use App\\Service\\Ai\\Exception\\AiKeyUnreadableException;/; s/\bSecretUnreadableException\b/AiKeyUnreadableException/g' \
  src/Service/Recommendation/RecommendationRunAdvancer.php src/Service/Worker/WorkerRunSweep.php
```

This produces the following in `RecommendationRunAdvancer.php`:

```php
        } catch (AiNotConfiguredException | AiKeyUnreadableException $e) {
```
```php
    private static function failureMessageFor(AiNotConfiguredException | AiKeyUnreadableException $e): string
    {
        return $e instanceof AiKeyUnreadableException
```

In `WorkerRunSweep.php`, replace the docblock above `advanceOne()`. After the perl run it reads:

```php
    /**
     * The typed AI-provider cases are handled by exception type alone — each
     * already knows what to do, so neither needs the run passed back out.
     * AiNotConfiguredException and AiKeyUnreadableException are no longer
     * classified here: the shared tick both drivers call
     * (RecommendationRunAdvancer::tick(), #311 fix) already failed and
     * flushed the run before rethrowing. That failure recording used to live
     * here too, split into "which failure" (classifyFailure) and "record it";
     * duplicating the classification in only one driver is exactly what left
     * a poll-only install's run stuck forever, so it now lives in the one
     * place both drivers go through.
     */
```
→
```php
    /**
     * AiNotConfiguredException and AiKeyUnreadableException were already recorded on the run by
     * RecommendationRunAdvancer::tick(), the one place both drivers go through (#311).
     */
```

In `tests/Service/Worker/AdvanceRecommendationRunsHandlerTest.php`:

```php
    /**
     * Distinguishes the SecretUnreadableException catch from the
     * AiNotConfiguredException one above it: both fail the run, but each
     * must carry its own message, not the sibling case's.
     *
     * The run's FAILED status alone no longer proves which catch clause
     * handled it (#311 fix): RecommendationRunAdvancer::tick() now fails and
     * flushes the run itself before rethrowing, so even the handler's
     * generic \Throwable floor would see a FAILED run. Asserting no error was
     * logged is what actually pins that SecretUnreadableException landed in
     * the typed, silent catch rather than falling through to that floor.
     */
```
→
```php
    /**
     * An unreadable key fails the run with its own message, not AiNotConfiguredException's. No error may be
     * logged: that pins the typed, silent catch rather than the handler's \Throwable floor (#311).
     */
```

- [ ] **Step 7: Remove the twin catches from both controllers.**

Replace the whole of `src/Controller/Api/AiSettingsController.php` with:

```php
<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Dto\Ai\AddConfigurationRequest;
use App\Dto\Ai\RenameConfigurationRequest;
use App\Dto\Ai\SaveModelRequest;
use App\Dto\Ai\SetBatchConcurrencyRequest;
use App\Dto\Ai\SetMaxBatchSizeRequest;
use App\Dto\Ai\SetReasoningRequest;
use App\Dto\Ai\SetSlowModelRequest;
use App\Entity\User;
use App\Http\AiSettingsJson;
use App\Service\Ai\AiConfigurationEditor;
use App\Service\Ai\AiConfigurationForUser;
use App\Service\Ai\AiProviderConfigurator;
use App\Service\RateLimit\RateLimitGuard;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Serializer\Normalizer\AbstractNormalizer;

/**
 * The account's AI provider configurations. A write that talks to the provider verifies against it first (see
 * AiProviderConfigurator). Every `{id}` route resolves through AiConfigurationForUser, so another account's id
 * answers 404, not 403, and a caller cannot learn that it exists.
 */
#[Route('/api/me/ai')]
final readonly class AiSettingsController
{
    public function __construct(
        private AiProviderConfigurator $configurator,
        private AiConfigurationEditor $editor,
        private AiConfigurationForUser $configuration,
        private RateLimitGuard $rateLimitGuard,
        private RateLimiterFactoryInterface $aiProviderLimiter,
    ) {
    }

    #[Route('', name: 'api_me_ai_list', methods: ['GET'])]
    public function list(#[CurrentUser] User $user): JsonResponse
    {
        return new JsonResponse(AiSettingsJson::list(
            $this->configurator->listConfigurations($user),
            $this->configurator->settingsFor($user)?->getId(),
        ));
    }

    #[Route('/configs', name: 'api_me_ai_add', methods: ['POST'])]
    public function add(
        #[CurrentUser] User $user,
        #[MapRequestPayload] AddConfigurationRequest $request,
    ): JsonResponse {
        $this->rateLimitGuard->enforceForUser($this->aiProviderLimiter, $user);
        $added = $this->configurator->addConfiguration($user, $request->name, $request->baseUrl, $request->apiKey);

        return new JsonResponse(
            AiSettingsJson::added($added->configuration, $added->modelIds),
            Response::HTTP_CREATED,
        );
    }

    #[Route('/configs/{id}/duplicate', name: 'api_me_ai_duplicate', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function duplicate(#[CurrentUser] User $user, int $id): JsonResponse
    {
        $source = $this->configuration->require($user, $id);
        $this->rateLimitGuard->enforceForUser($this->aiProviderLimiter, $user);
        $copy = $this->configurator->duplicateConfiguration($source);

        return new JsonResponse(
            AiSettingsJson::configuration($copy, $this->configurator->settingsFor($user)?->getId()),
            Response::HTTP_CREATED,
        );
    }

    #[Route('/configs/{id}/models', name: 'api_me_ai_models', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function models(#[CurrentUser] User $user, int $id): JsonResponse
    {
        $configuration = $this->configuration->require($user, $id);
        $this->rateLimitGuard->enforceForUser($this->aiProviderLimiter, $user);

        return new JsonResponse(AiSettingsJson::models($this->configurator->listModels($configuration)));
    }

    #[Route('/configs/{id}/model', name: 'api_me_ai_save_model', requirements: ['id' => '\d+'], methods: ['PUT'])]
    public function saveModel(
        #[CurrentUser] User $user,
        int $id,
        #[MapRequestPayload] SaveModelRequest $request,
    ): JsonResponse {
        $configuration = $this->configuration->require($user, $id);
        $this->rateLimitGuard->enforceForUser($this->aiProviderLimiter, $user);
        $this->configurator->chooseModel($configuration, $request->model);

        return new JsonResponse(
            AiSettingsJson::configuration($configuration, $this->configurator->settingsFor($user)?->getId()),
        );
    }

    #[Route('/configs/{id}/name', name: 'api_me_ai_rename', requirements: ['id' => '\d+'], methods: ['PUT'])]
    public function rename(
        #[CurrentUser] User $user,
        int $id,
        #[MapRequestPayload] RenameConfigurationRequest $request,
    ): JsonResponse {
        $configuration = $this->configuration->require($user, $id);
        $this->editor->rename($configuration, $request->name);

        return new JsonResponse(
            AiSettingsJson::configuration($configuration, $this->configurator->settingsFor($user)?->getId()),
        );
    }

    #[Route(
        '/configs/{id}/reasoning',
        name: 'api_me_ai_set_reasoning',
        requirements: ['id' => '\d+'],
        methods: ['PUT'],
    )]
    public function setReasoning(
        #[CurrentUser] User $user,
        int $id,
        #[MapRequestPayload] SetReasoningRequest $request,
    ): JsonResponse {
        $configuration = $this->configuration->require($user, $id);
        $this->editor->setSuppressReasoning($configuration, $request->suppressReasoning);

        return new JsonResponse(
            AiSettingsJson::configuration($configuration, $this->configurator->settingsFor($user)?->getId()),
        );
    }

    #[Route(
        '/configs/{id}/slow-model',
        name: 'api_me_ai_set_slow_model',
        requirements: ['id' => '\d+'],
        methods: ['PUT'],
    )]
    public function setSlowModel(
        #[CurrentUser] User $user,
        int $id,
        #[MapRequestPayload] SetSlowModelRequest $request,
    ): JsonResponse {
        $configuration = $this->configuration->require($user, $id);
        $this->editor->setSlowModel($configuration, $request->slowModel);

        return new JsonResponse(
            AiSettingsJson::configuration($configuration, $this->configurator->settingsFor($user)?->getId()),
        );
    }

    #[Route(
        '/configs/{id}/batch-concurrency',
        name: 'api_me_ai_set_batch_concurrency',
        requirements: ['id' => '\d+'],
        methods: ['PUT'],
    )]
    public function setBatchConcurrency(
        #[CurrentUser] User $user,
        int $id,
        #[MapRequestPayload] SetBatchConcurrencyRequest $request,
    ): JsonResponse {
        $configuration = $this->configuration->require($user, $id);
        $this->editor->setBatchConcurrency($configuration, $request->batchConcurrency);

        return new JsonResponse(
            AiSettingsJson::configuration($configuration, $this->configurator->settingsFor($user)?->getId()),
        );
    }

    #[Route(
        '/configs/{id}/max-batch-size',
        name: 'api_me_ai_set_max_batch_size',
        requirements: ['id' => '\d+'],
        methods: ['PUT'],
    )]
    /**
     * REQUIRE_ALL_PROPERTIES: the one nullable payload here, so a body that never mentions `maxBatchSize` must
     * not clear the account's cap (#445). Clearing takes an explicit `{"maxBatchSize": null}`.
     */
    public function setMaxBatchSize(
        #[CurrentUser] User $user,
        int $id,
        #[MapRequestPayload(serializationContext: [AbstractNormalizer::REQUIRE_ALL_PROPERTIES => true])]
        SetMaxBatchSizeRequest $request,
    ): JsonResponse {
        $configuration = $this->configuration->require($user, $id);
        $this->editor->setMaxBatchSize($configuration, $request->maxBatchSize);

        return new JsonResponse(
            AiSettingsJson::configuration($configuration, $this->configurator->settingsFor($user)?->getId()),
        );
    }

    #[Route('/configs/{id}/active', name: 'api_me_ai_activate', requirements: ['id' => '\d+'], methods: ['PUT'])]
    public function activate(#[CurrentUser] User $user, int $id): JsonResponse
    {
        $configuration = $this->configuration->require($user, $id);
        $this->rateLimitGuard->enforceForUser($this->aiProviderLimiter, $user);
        $this->configurator->activate($configuration);

        return new JsonResponse(
            AiSettingsJson::configuration($configuration, $this->configurator->settingsFor($user)?->getId()),
        );
    }

    #[Route('/configs/{id}', name: 'api_me_ai_delete', requirements: ['id' => '\d+'], methods: ['DELETE'])]
    public function delete(#[CurrentUser] User $user, int $id): JsonResponse
    {
        $this->configurator->deleteConfiguration($this->configuration->require($user, $id));

        return new JsonResponse(null, Response::HTTP_NO_CONTENT);
    }
}
```

Replace the whole of `src/Controller/Api/RecommendationRunController.php` with:

```php
<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Entity\User;
use App\Service\RateLimit\RateLimitGuard;
use App\Service\Recommendation\RecommendationPollDriver;
use App\Service\Recommendation\RecommendationRunCanceller;
use App\Service\Recommendation\RecommendationRunPurger;
use App\Service\Recommendation\RecommendationRunReport;
use App\Service\Recommendation\RecommendationRunStarter;
use App\Service\Recommendation\RecommendationRunStatusPayload;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * The poll loop the client drives. `current` is a plain read with no limiter. Starting a run commits outbound
 * spend; ticking is the progress loop and must stay generous enough never to throttle a long run (#308).
 */
#[Route('/api/recommendations/runs')]
final readonly class RecommendationRunController
{
    public function __construct(
        private RecommendationRunStarter $starter,
        private RecommendationPollDriver $pollDriver,
        private RecommendationRunPurger $purger,
        private RecommendationRunCanceller $canceller,
        private RecommendationRunStatusPayload $statusPayload,
        private RateLimitGuard $rateLimitGuard,
        private RateLimiterFactoryInterface $aiRecommendationsLimiter,
        private RateLimiterFactoryInterface $aiRecommendationStartsLimiter,
    ) {
    }

    #[Route('', name: 'api_recommendations_start', methods: ['POST'])]
    public function start(#[CurrentUser] User $user): JsonResponse
    {
        $this->rateLimitGuard->enforceForUser($this->aiRecommendationStartsLimiter, $user);

        return new JsonResponse($this->statusPayload->forReport($this->starter->start($user), $user));
    }

    /** Resumes the latest failed run; it shares the start limiter because it commits the same outbound spend. */
    #[Route('/resume', name: 'api_recommendations_resume', methods: ['POST'])]
    public function resume(#[CurrentUser] User $user): JsonResponse
    {
        $this->rateLimitGuard->enforceForUser($this->aiRecommendationStartsLimiter, $user);

        return new JsonResponse($this->statusPayload->forReport($this->starter->resume($user), $user));
    }

    #[Route('/tick', name: 'api_recommendations_tick', methods: ['POST'])]
    public function tick(#[CurrentUser] User $user): JsonResponse
    {
        $this->rateLimitGuard->enforceForUser($this->aiRecommendationsLimiter, $user);

        return new JsonResponse($this->statusPayload->forReport($this->pollDriver->poll($user), $user));
    }

    #[Route('/current', name: 'api_recommendations_current', methods: ['GET'])]
    public function current(#[CurrentUser] User $user): JsonResponse
    {
        return new JsonResponse($this->statusPayload->forReport($this->pollDriver->current($user), $user));
    }

    /** No limiter: stopping only reduces work, and throttling the way out of a spending run is backwards. */
    #[Route('/stop', name: 'api_recommendations_stop', methods: ['POST'])]
    public function stop(#[CurrentUser] User $user): JsonResponse
    {
        $this->canceller->cancel($user);

        return new JsonResponse($this->statusPayload->forReport($this->pollDriver->current($user), $user));
    }

    #[Route('', name: 'api_recommendations_purge', methods: ['DELETE'])]
    public function purge(#[CurrentUser] User $user): JsonResponse
    {
        $this->purger->purge($user);

        return new JsonResponse($this->statusPayload->forReport(RecommendationRunReport::none(), $user));
    }
}
```

Delete the twins:

```bash
git rm src/Exception/AiNotConfiguredApiException.php src/Exception/AiConfigurationNotFoundApiException.php \
  src/Exception/TooManyAiConfigurationsApiException.php src/Exception/AiKeyUnreadableApiException.php \
  src/Exception/AiProviderApiException.php src/Exception/NoActiveRecommendationRunApiException.php \
  src/Exception/NoResumableRecommendationRunApiException.php src/Exception/RecommendationRunActiveApiException.php
grep -rn "AiNotConfiguredApiException\|AiConfigurationNotFoundApiException\|TooManyAiConfigurationsApiException\|AiKeyUnreadableApiException\|AiProviderApiException\|RecommendationRunApiException\|RecommendationRunActiveApiException" src tests
```

Expected grep output: nothing.

- [ ] **Step 8: Run the tests.**

Run: `php bin/phpunit tests/Http/Problem tests/Controller/Api/AiSettingsControllerTest.php tests/Controller/Api/RecommendationRunControllerTest.php tests/Service/Ai tests/Service/Recommendation tests/Service/Worker tests/Command/RecommendationDrainCommandTest.php`
Expected: PASS (`OK`). `ProblemContractTest` now runs 66 tests.

- [ ] **Step 9: Run the static gates.**

Run: `bin/console cache:warmup --env=dev && composer stan && composer md && composer cs`
Expected: `[OK] No errors` from stan, and no findings from md or cs. Then run PhpStorm `lint_files` on the touched PHP files. Expected: no ERROR or WARNING.

- [ ] **Step 10: Commit.**

```bash
git status
git add -A src/Exception src/Http/Problem src/Service/Ai src/Service/Recommendation/RecommendationRunAdvancer.php \
  src/Service/Worker/WorkerRunSweep.php src/Controller/Api/AiSettingsController.php \
  src/Controller/Api/RecommendationRunController.php tests/Http/Problem/ProblemContractTest.php \
  tests/Service/Ai/AiProviderConfiguratorTest.php tests/Service/Recommendation/RecommendationRunAdvancerTest.php \
  tests/Service/Worker/AdvanceRecommendationRunsHandlerTest.php
git commit -m "refactor(#1160): map AI and recommendation-run failures without twin exceptions"
```

---
### Task 4: The remaining catch-and-rethrow sites

**Files:**
- Create: `src/Http/Problem/DiscoveryProblems.php`, `PreviewProblems.php`, `CatalogProblems.php` and `CommentsProblems.php`.
- Move (`git mv`): `src/Exception/FeedPreviewException.php` → `src/Service/Preview/Exception/FeedPreviewException.php`.
- Delete: `src/Exception/ScrapingDisabledApiException.php` and `src/Exception/FeedPreviewApiException.php`.
- Modify:
  - `src/Controller/Api/SubscriptionController.php` (the `use` lines and `create()`).
  - `src/Controller/Api/FeedPreviewController.php` and `src/Controller/Api/EntryCommentsController.php`, rewritten in full.
  - `src/Controller/Admin/AdminCatalogImportController.php` (`importBundled()` and `import()`).
  - `src/Service/Preview/FeedPreviewService.php` (the `use` line).
- Leave alone:
  - `AdminCatalogImportController:43`. Its catch returns `available: false`, a real alternative answer.
  - `OAuthController:187`. It logs and redirects a browser, which is the flow's contract.
- Test: `tests/Http/Problem/ProblemContractTest.php` and `tests/Service/Preview/FeedPreviewServiceTest.php` (the `use` line).

**Interfaces:**
- Consumes: `ExceptionProblems`, `ResolvedProblem` and `ApiProblem` from Task 2.
- Produces:
  - `App\Service\Preview\Exception\FeedPreviewException extends \RuntimeException`. Only the namespace is new.
  - Four `final readonly` mappers implementing `ExceptionProblems`.

- [ ] **Step 1: Swap the four contract rows (red-first).**

In `tests/Http/Problem/ProblemContractTest.php`, delete:

```php
use App\Exception\FeedPreviewApiException;
```
```php
use App\Exception\ScrapingDisabledApiException;
```

and add, after `use App\Service\Backup\Exception\InvalidBackupException;`:

```php
use App\Service\Catalog\Exception\InvalidCatalogDocumentException;
use App\Service\Comments\Exception\NoCommentsFeedException;
use App\Service\Discovery\Exception\ScrapingDisabledException;
use App\Service\Preview\Exception\FeedPreviewException;
```

Change the first element of these rows. Every expected body stays as it is.

| Row key | Before | After |
|---|---|---|
| `'scraping disabled'` | `new ScrapingDisabledApiException('Website scraping is turned off for this account.'),` | `new ScrapingDisabledException(),` |
| `'feed preview failed'` | `new FeedPreviewApiException('The feed returned an empty document.'),` | `new FeedPreviewException('The feed returned an empty document.'),` |
| `'invalid catalog document, message withheld'` | `new UnprocessableEntityHttpException('Duplicate feed URL "https://a.example/feed".'),` | `new InvalidCatalogDocumentException('Duplicate feed URL "https://a.example/feed".'),` |
| `'no comments feed, message withheld'` | `new NotFoundHttpException('The entry has no comments feed.'),` | `new NoCommentsFeedException('The entry has no comments feed.'),` |

Move the preview exception, so that the new `use` line resolves:

```bash
mkdir -p src/Service/Preview/Exception
git mv src/Exception/FeedPreviewException.php src/Service/Preview/Exception/FeedPreviewException.php
```

Replace the whole of `src/Service/Preview/Exception/FeedPreviewException.php` with:

```php
<?php

declare(strict_types=1);

namespace App\Service\Preview\Exception;

final class FeedPreviewException extends \RuntimeException
{
}
```

```bash
perl -pi -e 's/^use App\\Exception\\FeedPreviewException;$/use App\\Service\\Preview\\Exception\\FeedPreviewException;/' \
  src/Service/Preview/FeedPreviewService.php tests/Service/Preview/FeedPreviewServiceTest.php
```

`FeedPreviewController` still imports the old name until Step 4.

- [ ] **Step 2: Run the red rows.**

Run: `php bin/phpunit tests/Http/Problem/ProblemContractTest.php --filter 'scraping disabled|feed preview failed|invalid catalog document|no comments feed'`
Expected: FAIL, 4 of 4, with `Failed asserting that 500 is identical to 403` (or 422, 422, 404). Nothing maps these exceptions yet.

- [ ] **Step 3: Write the four mappers.**

`src/Http/Problem/DiscoveryProblems.php`:

```php
<?php

declare(strict_types=1);

namespace App\Http\Problem;

use App\Service\Discovery\Exception\ScrapingDisabledException;
use Symfony\Component\HttpFoundation\Response;

final readonly class DiscoveryProblems implements ExceptionProblems
{
    public function resolve(\Throwable $exception): ?ResolvedProblem
    {
        return match (true) {
            $exception instanceof ScrapingDisabledException => new ResolvedProblem(new ApiProblem(
                'scraping_disabled',
                'Website scraping is disabled',
                Response::HTTP_FORBIDDEN,
                $exception->getMessage(),
            )),
            default => null,
        };
    }
}
```

`src/Http/Problem/PreviewProblems.php`:

```php
<?php

declare(strict_types=1);

namespace App\Http\Problem;

use App\Service\Preview\Exception\FeedPreviewException;
use Symfony\Component\HttpFoundation\Response;

final readonly class PreviewProblems implements ExceptionProblems
{
    public function resolve(\Throwable $exception): ?ResolvedProblem
    {
        return match (true) {
            $exception instanceof FeedPreviewException => new ResolvedProblem(new ApiProblem(
                'feed_preview_failed',
                'Feed preview failed',
                Response::HTTP_UNPROCESSABLE_ENTITY,
                $exception->getMessage(),
            )),
            default => null,
        };
    }
}
```

`src/Http/Problem/CatalogProblems.php`. Today this answer comes from a bare `UnprocessableEntityHttpException`, which has never carried a detail:

```php
<?php

declare(strict_types=1);

namespace App\Http\Problem;

use App\Service\Catalog\Exception\InvalidCatalogDocumentException;
use Symfony\Component\HttpFoundation\Response;

final readonly class CatalogProblems implements ExceptionProblems
{
    public function resolve(\Throwable $exception): ?ResolvedProblem
    {
        return match (true) {
            $exception instanceof InvalidCatalogDocumentException => new ResolvedProblem(new ApiProblem(
                'request_error',
                Response::$statusTexts[Response::HTTP_UNPROCESSABLE_ENTITY],
                Response::HTTP_UNPROCESSABLE_ENTITY,
            )),
            default => null,
        };
    }
}
```

`src/Http/Problem/CommentsProblems.php`:

```php
<?php

declare(strict_types=1);

namespace App\Http\Problem;

use App\Service\Comments\Exception\NoCommentsFeedException;
use Symfony\Component\HttpFoundation\Response;

final readonly class CommentsProblems implements ExceptionProblems
{
    public function resolve(\Throwable $exception): ?ResolvedProblem
    {
        return match (true) {
            $exception instanceof NoCommentsFeedException => new ResolvedProblem(new ApiProblem(
                'not_found',
                Response::$statusTexts[Response::HTTP_NOT_FOUND],
                Response::HTTP_NOT_FOUND,
            )),
            default => null,
        };
    }
}
```

Run the Step 2 command again. Expected: `OK (4 tests, 24 assertions)`.

- [ ] **Step 4: Remove the catches.**

In `src/Controller/Api/SubscriptionController.php`, delete these two imports:

```php
use App\Exception\ScrapingDisabledApiException;
```
```php
use App\Service\Discovery\Exception\ScrapingDisabledException;
```

and in `create()`:

```php
        $tags = $this->tags->findAllByIdsForUser($request->tagIds, (int) $user->getId());

        try {
            $outcome = $this->subscriptions->subscribe($user, $request->url, $request->format, $tags, $request->title);
        } catch (ScrapingDisabledException $e) {
            // Rethrow as an ApiException so the listener renders a problem+json
            // document — a bare RuntimeException would otherwise reach
            // ApiExceptionListener's unhandled branch and 500.
            throw new ScrapingDisabledApiException($e->getMessage(), $e);
        }

        if (null === $outcome->subscription) {
```
→
```php
        $tags = $this->tags->findAllByIdsForUser($request->tagIds, (int) $user->getId());
        $outcome = $this->subscriptions->subscribe($user, $request->url, $request->format, $tags, $request->title);

        if (null === $outcome->subscription) {
```

Replace the whole of `src/Controller/Api/FeedPreviewController.php` with:

```php
<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Dto\Feed\PreviewFeedRequest;
use App\Entity\User;
use App\Http\FeedPreviewJson;
use App\Service\Preview\FeedPreviewService;
use App\Service\RateLimit\RateLimitGuard;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

#[Route('/api/feeds')]
final readonly class FeedPreviewController
{
    public function __construct(
        private FeedPreviewService $previews,
        private RateLimitGuard $rateLimitGuard,
        private RateLimiterFactoryInterface $feedPreviewLimiter,
    ) {
    }

    #[Route('/preview', name: 'api_feeds_preview', methods: ['POST'])]
    public function preview(
        #[CurrentUser] User $user,
        #[MapRequestPayload] PreviewFeedRequest $request,
    ): JsonResponse {
        $this->rateLimitGuard->enforceForUser($this->feedPreviewLimiter, $user);
        $preview = $this->previews->preview($user, $request->url, $request->format);

        return new JsonResponse(FeedPreviewJson::one($preview));
    }
}
```

Replace the whole of `src/Controller/Api/EntryCommentsController.php` with:

```php
<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Entity\User;
use App\Http\CommentsJson;
use App\Repository\EntryListRepository;
use App\Service\Comments\CommentsLoader;
use App\Service\RateLimit\RateLimitGuard;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

#[Route('/api/entries')]
final readonly class EntryCommentsController
{
    public function __construct(
        private EntryListRepository $entryList,
        private CommentsLoader $comments,
        private RateLimitGuard $rateLimitGuard,
        private RateLimiterFactoryInterface $commentsLimiter,
    ) {
    }

    #[Route('/{id}/comments', name: 'api_entries_comments', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function comments(int $id, #[CurrentUser] User $user): JsonResponse
    {
        $entry = $this->entryList->findOneSubscribedByUser($id, (int) $user->getId())
            ?? throw new NotFoundHttpException('No such entry.');

        $this->rateLimitGuard->enforceForUser($this->commentsLimiter, $user);

        return new JsonResponse(CommentsJson::one($this->comments->load($entry)));
    }
}
```

In `src/Controller/Admin/AdminCatalogImportController.php`, `importBundled()`:

```php
    public function importBundled(#[MapRequestPayload] CatalogImportModeRequest $request): JsonResponse
    {
        try {
            $document = $this->bundled->document();
        } catch (InvalidCatalogDocumentException $e) {
            throw new UnprocessableEntityHttpException($e->getMessage(), $e);
        }

        return new JsonResponse(AdminCatalogJson::importResult($this->importer->import(
            $document,
            $request->mode ?? throw new UnprocessableEntityHttpException('A mode is required.'),
        )));
    }
```
→
```php
    public function importBundled(#[MapRequestPayload] CatalogImportModeRequest $request): JsonResponse
    {
        return new JsonResponse(AdminCatalogJson::importResult($this->importer->import(
            $this->bundled->document(),
            $request->mode ?? throw new UnprocessableEntityHttpException('A mode is required.'),
        )));
    }
```

and `import()`:

```php
    public function import(#[MapRequestPayload] CatalogImportRequest $request): JsonResponse
    {
        try {
            $document = $this->parser->parse($request->document);
        } catch (InvalidCatalogDocumentException $e) {
            // 422, not 500: the upload is the user's input, and nothing was
            // written — validation happens entirely before the importer runs.
            throw new UnprocessableEntityHttpException($e->getMessage(), $e);
        }

        return new JsonResponse(AdminCatalogJson::importResult($this->importer->import(
            $document,
            $request->mode ?? throw new UnprocessableEntityHttpException('A mode is required.'),
        )));
    }
```
→
```php
    public function import(#[MapRequestPayload] CatalogImportRequest $request): JsonResponse
    {
        return new JsonResponse(AdminCatalogJson::importResult($this->importer->import(
            $this->parser->parse($request->document),
            $request->mode ?? throw new UnprocessableEntityHttpException('A mode is required.'),
        )));
    }
```

PHP evaluates arguments left to right, so the document is still resolved before the mode check. That was the old order too. The `InvalidCatalogDocumentException` and `UnprocessableEntityHttpException` imports stay: `describeBundled()` and the mode checks still use them.

Delete the twins:

```bash
git rm src/Exception/ScrapingDisabledApiException.php src/Exception/FeedPreviewApiException.php
grep -rn "ScrapingDisabledApiException\|FeedPreviewApiException\|App\\\\Exception\\\\FeedPreviewException" src tests
```

Expected grep output: nothing.

- [ ] **Step 5: Run the tests.**

Run: `php bin/phpunit tests/Http/Problem tests/Controller/Api/SubscriptionControllerTest.php tests/Controller/Api/FeedPreviewControllerTest.php tests/Controller/Api/EntryCommentsControllerTest.php tests/Controller/Admin/AdminCatalogImportControllerTest.php tests/Service/Preview`
Expected: PASS (`OK`).

- [ ] **Step 6: Run the static gates.**

Run: `bin/console cache:warmup --env=dev && composer stan && composer md && composer cs`
Expected: `[OK] No errors`, and no md or cs findings. Then run PhpStorm `lint_files` on the touched PHP files. Expected: no ERROR or WARNING.

- [ ] **Step 7: Commit.**

```bash
git status
git add -A src/Exception src/Http/Problem src/Service/Preview src/Controller/Api/SubscriptionController.php \
  src/Controller/Api/FeedPreviewController.php src/Controller/Api/EntryCommentsController.php \
  src/Controller/Admin/AdminCatalogImportController.php tests/Http/Problem/ProblemContractTest.php \
  tests/Service/Preview/FeedPreviewServiceTest.php
git commit -m "refactor(#1160): drop the remaining catch-and-rethrow sites"
```

---
### Task 5: Make the module exceptions plain and move them home

This task is six commits, 5a–5f, run in order. Each commit:
- Rewrites its module's exceptions as plain `\RuntimeException` subclasses (Amendment 6 decides each constructor).
- Adds that module's mapper.
- Moves files with `git mv`, so history follows.
- Fixes every `use` line with the `perl` command given.
- Keeps `ProblemContractTest` green.

The contract rows' first elements never change in this task. Only `ProblemContractTest`'s `use` lines move with the classes.

**Interfaces (the whole task):**
- Consumes: `ExceptionProblems`, `ResolvedProblem`, `ApiProblem` and `ProblemCatalog::legacy()` from Task 2.
- Produces. These are the final class names and constructors that Tasks 6 and 7 rely on:

| Class | Constructor | Carries |
|---|---|---|
| `App\Service\Passkey\Exception\PasskeySignInFailure` | interface `extends \Throwable` | — |
| `App\Service\Passkey\Exception\AssertionRejectedException` | `(?\Throwable $previous = null)` | implements `PasskeySignInFailure` |
| `App\Service\Passkey\Exception\AttestationRejectedException` | `(\Throwable $previous)` | — |
| `App\Service\Passkey\Exception\{DuplicatePasskey,LastSignInMethod,PasskeyChallengeOwnership,PasskeyNotFound}Exception` | none | — |
| `App\Service\Passkey\Exception\{PasskeySignInDisabled,UnknownChallenge,UnknownPasskeyCredential}Exception` | none | implements `PasskeySignInFailure` |
| `App\Service\Backup\Exception\{BackupDoesNotFit,InvalidBackup}Exception` | `\RuntimeException`'s (message) | — |
| `App\Service\Backup\Exception\BackupLoadFailedException` | named constructors `from()`, `duringEntries()`, `danglingReference()` | — |
| `App\Service\Mail\Settings\Exception\IncompleteMailConfigurationException` | named constructors `passwordMissing()`, `transportMissing()`, `proxyMissing()` | — |
| `App\Service\Settings\Exception\RelyingPartyChangeRequiresConfirmationException` | `(int $invalidatedPasskeyCount)` | `public readonly int $invalidatedPasskeyCount` |
| `App\Service\Account\Exception\LastAdminException` | none | — |
| `App\Service\Subscription\Exception\AlreadySubscribedException` | none | — |
| `App\Service\Subscription\Exception\SubscriptionLimitReachedException` | `(int $limit)` | — |
| `App\Service\Auth\Exception\{InvalidSetupSecret,SetupUnavailable,InvalidToken}Exception` | none | — |
| `App\Service\Auth\Exception\AccountNotActiveException` | `(string $accountStatus)` | `public readonly string $accountStatus` |
| `App\Exception\InvalidCredentialsException` | none | — |
| `App\Service\RateLimit\Exception\RateLimitedException` | `(int $retryAfterSeconds)` | `public readonly int $retryAfterSeconds` |
| `App\Service\Opml\Exception\InvalidOpmlException` | `\RuntimeException`'s (message) | — |
| `App\Service\OAuth\Exception\OAuthException` | abstract | — |
| `App\Service\OAuth\Exception\OAuthFailedException` | `(string $logDetail, ?\Throwable $previous = null)` | `public readonly string $logDetail` |
| `App\Service\OAuth\Exception\UnknownProviderException` | none | — |
| `App\Exception\ValidationException` | `(array $errors)` | `public readonly array $errors` (`array<string, list<string>>`) |
| `App\Exception\TagNameTakenException` | none | — |

- The mappers are `App\Http\Problem\{PasskeyRegistration,PasskeySignIn,Backup,Mail,Settings,Account,Subscription,Auth,RateLimit,Opml,OAuth,Request,Tag}Problems`.
- `RequestProblems` is extended in Task 6.

#### 5a — Passkey

**Files:**
- Create: `src/Service/Passkey/Exception/PasskeySignInFailure.php`, `src/Http/Problem/PasskeyRegistrationProblems.php` and `src/Http/Problem/PasskeySignInProblems.php`.
- Rewrite: the 9 files in `src/Service/Passkey/Exception/`.
- Modify:
  - `src/Security/PasskeyAuthenticator.php`: the `use` line, `verifiedUser()`'s docblock and its catch.
  - `src/Service/Passkey/AssertionVerifier.php`: `verify()`'s docblock.
  - `tests/Controller/Api/PasskeyLoginTest.php`: one docblock.
- Test:
  - Delete `tests/Service/Passkey/Exception/PasskeyNotFoundExceptionTest.php`, `PasskeySignInDisabledExceptionTest.php` and `UnknownPasskeyCredentialExceptionTest.php`. They assert only HTTP properties, which contract rows 1–9 now pin.
  - Rewrite `tests/Service/Passkey/Exception/AssertionRejectedExceptionTest.php`.
  - Create `tests/Service/Passkey/Exception/AttestationRejectedExceptionTest.php`.

- [ ] **Step 1: Rewrite the passkey exceptions as plain classes.** Replace each file whole.

`src/Service/Passkey/Exception/PasskeySignInFailure.php` (new):

```php
<?php

declare(strict_types=1);

namespace App\Service\Passkey\Exception;

/**
 * A reason AssertionVerifier::verify() refuses a login. PasskeyAuthenticator catches this marker and turns each
 * into a plain AuthenticationException, so every passkey login failure goes through LoginFailureHandler.
 */
interface PasskeySignInFailure extends \Throwable
{
}
```

`src/Service/Passkey/Exception/AssertionRejectedException.php`:

```php
<?php

declare(strict_types=1);

namespace App\Service\Passkey\Exception;

/**
 * Any failed check on a login assertion. One type on purpose: naming the failed check would help an attacker
 * probing the endpoint more than a legitimate caller, who can only retry the ceremony.
 */
final class AssertionRejectedException extends \RuntimeException implements PasskeySignInFailure
{
    public function __construct(?\Throwable $previous = null)
    {
        parent::__construct('The passkey assertion failed verification.', previous: $previous);
    }
}
```

`src/Service/Passkey/Exception/AttestationRejectedException.php`:

```php
<?php

declare(strict_types=1);

namespace App\Service\Passkey\Exception;

/** Any failed check on a registration attestation, collapsed into one type for the same reason as an assertion. */
final class AttestationRejectedException extends \RuntimeException
{
    public function __construct(\Throwable $previous)
    {
        parent::__construct('The passkey attestation failed verification.', previous: $previous);
    }
}
```

`src/Service/Passkey/Exception/DuplicatePasskeyException.php`:

```php
<?php

declare(strict_types=1);

namespace App\Service\Passkey\Exception;

/**
 * The attested credential id already exists; the unique constraint is global. Reporting it is an accepted, narrow
 * existence oracle: ~32 bytes of authenticator entropy mean it only confirms an id the caller already holds.
 */
final class DuplicatePasskeyException extends \RuntimeException
{
}
```

`src/Service/Passkey/Exception/LastSignInMethodException.php`:

```php
<?php

declare(strict_types=1);

namespace App\Service\Passkey\Exception;

/** Refused by PasskeyRemovalPolicy: removing this passkey would leave the account no way to sign in. */
final class LastSignInMethodException extends \RuntimeException
{
}
```

`src/Service/Passkey/Exception/PasskeyChallengeOwnershipException.php`:

```php
<?php

declare(strict_types=1);

namespace App\Service\Passkey\Exception;

/** The redeemed challenge names another account: this is what binds a registration challenge to its owner. */
final class PasskeyChallengeOwnershipException extends \RuntimeException
{
}
```

`src/Service/Passkey/Exception/PasskeyNotFoundException.php`:

```php
<?php

declare(strict_types=1);

namespace App\Service\Passkey\Exception;

/** No passkey with this id belongs to the caller — foreign ids included, see PasskeyRemoval. */
final class PasskeyNotFoundException extends \RuntimeException
{
}
```

`src/Service/Passkey/Exception/PasskeySignInDisabledException.php`:

```php
<?php

declare(strict_types=1);

namespace App\Service\Passkey\Exception;

/** Refused by PasskeySignInAvailability::guard(): sign-in is off, or the relying-party id does not fit the host. */
final class PasskeySignInDisabledException extends \RuntimeException implements PasskeySignInFailure
{
}
```

`src/Service/Passkey/Exception/UnknownChallengeException.php`:

```php
<?php

declare(strict_types=1);

namespace App\Service\Passkey\Exception;

/**
 * The challenge handle is not redeemable: never issued, already redeemed, or expired. One case on purpose, so a
 * caller cannot probe for live handles.
 */
final class UnknownChallengeException extends \RuntimeException implements PasskeySignInFailure
{
}
```

`src/Service/Passkey/Exception/UnknownPasskeyCredentialException.php`:

```php
<?php

declare(strict_types=1);

namespace App\Service\Passkey\Exception;

/**
 * The assertion named a credential id no account holds. Its own type so the client can prune the dead browser
 * entry (#727); it accepts the same oracle DuplicatePasskeyException does.
 */
final class UnknownPasskeyCredentialException extends \RuntimeException implements PasskeySignInFailure
{
}
```

- [ ] **Step 2: Run the contract to see the passkey rows go red.**

Run: `php bin/phpunit tests/Http/Problem/ProblemContractTest.php --filter 'passkey|attestation|assertion|last sign-in method'`
Expected: FAIL, 9 of 9, with `Failed asserting that 500 is identical to …`. The exceptions are no longer `ApiException`s, and no mapper claims them yet.

- [ ] **Step 3: Write the two passkey mappers.**

`src/Http/Problem/PasskeyRegistrationProblems.php`:

```php
<?php

declare(strict_types=1);

namespace App\Http\Problem;

use App\Service\Passkey\Exception\AttestationRejectedException;
use App\Service\Passkey\Exception\DuplicatePasskeyException;
use App\Service\Passkey\Exception\LastSignInMethodException;
use App\Service\Passkey\Exception\PasskeyChallengeOwnershipException;
use App\Service\Passkey\Exception\PasskeyNotFoundException;
use App\Service\Passkey\Exception\UnknownChallengeException;
use Symfony\Component\HttpFoundation\Response;

final readonly class PasskeyRegistrationProblems implements ExceptionProblems
{
    public function resolve(\Throwable $exception): ?ResolvedProblem
    {
        return match (true) {
            $exception instanceof AttestationRejectedException => new ResolvedProblem(new ApiProblem(
                'passkey_attestation_rejected',
                'Passkey registration rejected',
                Response::HTTP_BAD_REQUEST,
                'The passkey could not be verified.',
            )),
            $exception instanceof DuplicatePasskeyException => new ResolvedProblem(new ApiProblem(
                'passkey_already_registered',
                'Passkey already registered',
                Response::HTTP_CONFLICT,
                'This passkey is already registered.',
            )),
            $exception instanceof PasskeyChallengeOwnershipException => new ResolvedProblem(new ApiProblem(
                'passkey_challenge_owner_mismatch',
                'Forbidden',
                Response::HTTP_FORBIDDEN,
                'This registration challenge was not issued to you.',
            )),
            $exception instanceof UnknownChallengeException => new ResolvedProblem(new ApiProblem(
                'unknown_passkey_challenge',
                'Unknown or expired passkey challenge',
                Response::HTTP_BAD_REQUEST,
            )),
            $exception instanceof PasskeyNotFoundException => new ResolvedProblem(new ApiProblem(
                'passkey_not_found',
                'No such passkey',
                Response::HTTP_NOT_FOUND,
            )),
            $exception instanceof LastSignInMethodException => new ResolvedProblem(new ApiProblem(
                'passkey_last_sign_in_method',
                'Cannot remove your last sign-in method',
                Response::HTTP_CONFLICT,
                'This is your only way to sign in. Set a password or link a sign-in provider first.',
            )),
            default => null,
        };
    }
}
```

`src/Http/Problem/PasskeySignInProblems.php`:

```php
<?php

declare(strict_types=1);

namespace App\Http\Problem;

use App\Service\Passkey\Exception\AssertionRejectedException;
use App\Service\Passkey\Exception\PasskeySignInDisabledException;
use App\Service\Passkey\Exception\UnknownPasskeyCredentialException;
use Symfony\Component\HttpFoundation\Response;

final readonly class PasskeySignInProblems implements ExceptionProblems
{
    public function resolve(\Throwable $exception): ?ResolvedProblem
    {
        return match (true) {
            $exception instanceof AssertionRejectedException => new ResolvedProblem(new ApiProblem(
                'passkey_assertion_rejected',
                'Passkey login rejected',
                Response::HTTP_UNAUTHORIZED,
                'The passkey could not be verified.',
            )),
            $exception instanceof UnknownPasskeyCredentialException => new ResolvedProblem(new ApiProblem(
                'unknown_passkey_credential',
                'Unknown passkey',
                Response::HTTP_UNAUTHORIZED,
                'This passkey is not registered here.',
            )),
            $exception instanceof PasskeySignInDisabledException => new ResolvedProblem(new ApiProblem(
                'passkey_sign_in_disabled',
                'Passkey sign-in is disabled',
                Response::HTTP_FORBIDDEN,
                'This instance has turned off passkey sign-in.',
            )),
            default => null,
        };
    }
}
```

Run the Step 2 command again. Expected: `OK (9 tests, 54 assertions)`.

- [ ] **Step 4: Watch passkey login break, because `PasskeyAuthenticator` still catches `ApiException`.**

Run: `php bin/phpunit tests/Controller/Api/PasskeyLoginTest.php`
Expected: FAIL (`Tests: 17, Failures: 5`):
- `testAReplayedHandleIsRejected` and `testAnExpiredHandleIsRejected`: the response is the 400 `unknown_passkey_challenge`, not a 401.
- `testATamperedSignatureIsRejected`: the type is `passkey_assertion_rejected`, not `invalid_credentials`.
- `testTheSixthFailedAttemptFromOneIpIsThrottled`: the failures bypass the login throttle.
- `testADisabledInstanceRejectsLoginWith401NotA500`: the response is a 403.

The plain exceptions now escape the authenticator and bypass `LoginFailureHandler`.

- [ ] **Step 5: Catch the marker instead.**

In `src/Security/PasskeyAuthenticator.php`:

```php
use App\Exception\ApiException;
```
→
```php
use App\Service\Passkey\Exception\PasskeySignInFailure;
```

and `verifiedUser()`'s docblock and catch:

```php
    /**
     * Runs lazily from the UserBadge loader — see the class docblock.
     * AssertionVerifier's typed rejections never reach the kernel from here:
     * they're always translated into a plain AuthenticationException, so
     * LoginFailureHandler (and login_throttling upstream of it) handle every
     * passkey failure like a password one — save the unknown-credential type
     * LoginFailureHandler reads off `previous` (#727).
     *
     * @param array<string, mixed> $payload
     */
```
→
```php
    /**
     * Runs lazily from the UserBadge loader (see the class docblock). Every PasskeySignInFailure becomes a plain
     * AuthenticationException, so LoginFailureHandler treats it like a password failure, #727's `previous` aside.
     *
     * @param array<string, mixed> $payload
     */
```

```php
        } catch (ApiException $exception) {
            throw new AuthenticationException('Passkey assertion rejected.', previous: $exception);
        }
```
→
```php
        } catch (PasskeySignInFailure $exception) {
            throw new AuthenticationException('Passkey assertion rejected.', previous: $exception);
        }
```

In `src/Service/Passkey/AssertionVerifier.php`, `verify()`'s docblock:

```php
    /**
     * $availability->guard() runs first, before the challenge is consumed: the
     * login path has no controller action to gate — PasskeyAuthenticator calls
     * this method from inside a lazily-invoked UserBadge loader, never through
     * PasskeyController — so this is the one place that can refuse a disabled
     * instance's login. PasskeySignInDisabledException extends ApiException,
     * already caught and rewritten to AuthenticationException by
     * PasskeyAuthenticator::verifiedUser(), so a disabled instance fails
     * exactly like a rejected assertion: a clean 401, never a 500.
     *
     * @param array<string, mixed> $credential
     *
     * @throws InvalidArgumentException
     */
```
→
```php
    /**
     * The availability guard runs before the challenge is consumed: the login path has no controller action to
     * gate, so this is the one place that can refuse a disabled instance's login.
     *
     * @param array<string, mixed> $credential
     *
     * @throws InvalidArgumentException
     */
```

In `tests/Controller/Api/PasskeyLoginTest.php`, replace the 23-line docblock (both delimiters included) above `testADisabledInstanceRejectsLoginWith401NotA500()`. It starts `The trap the design brief calls out by name` and ends `(see fix round 2 report for the exact failure).` Replace it with:

```php
    /**
     * The login path runs through PasskeyAuthenticator, so the availability guard lives in AssertionVerifier.
     * The relying party stays pinned, making the toggle the only variable: every other passkey failure reads as
     * invalid_credentials, so only a request that would otherwise succeed proves the guard rejected it.
     */
```

Run: `php bin/phpunit tests/Controller/Api/PasskeyLoginTest.php`
Expected: PASS (`OK`).

- [ ] **Step 6: Break the marker once.**

Delete ` implements PasskeySignInFailure` from `UnknownChallengeException`.

Run: `php bin/phpunit tests/Controller/Api/PasskeyLoginTest.php --filter 'Replayed|Expired'`
Expected: FAIL, with the 400 again.

Restore the text by hand. Do not use `git checkout --`. Run the same command again. Expected: `OK (2 tests, …)`.

- [ ] **Step 7: Replace the property tests.**

```bash
git rm tests/Service/Passkey/Exception/PasskeyNotFoundExceptionTest.php \
  tests/Service/Passkey/Exception/PasskeySignInDisabledExceptionTest.php \
  tests/Service/Passkey/Exception/UnknownPasskeyCredentialExceptionTest.php
```

Replace the whole of `tests/Service/Passkey/Exception/AssertionRejectedExceptionTest.php` with:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Service\Passkey\Exception;

use App\Service\Passkey\Exception\AssertionRejectedException;
use PHPUnit\Framework\TestCase;

final class AssertionRejectedExceptionTest extends TestCase
{
    public function testTheCauseIsChainedForTheLog(): void
    {
        $cause = new \RuntimeException('CBOR decode failed');

        self::assertSame($cause, (new AssertionRejectedException($cause))->getPrevious());
    }
}
```

Create `tests/Service/Passkey/Exception/AttestationRejectedExceptionTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Service\Passkey\Exception;

use App\Service\Passkey\Exception\AttestationRejectedException;
use PHPUnit\Framework\TestCase;

final class AttestationRejectedExceptionTest extends TestCase
{
    public function testTheCauseIsChainedForTheLog(): void
    {
        $cause = new \LengthException('Credential id is too long to store.');

        self::assertSame($cause, (new AttestationRejectedException($cause))->getPrevious());
    }
}
```

- [ ] **Step 8: Run the module's tests and gates.**

Run: `php bin/phpunit tests/Http/Problem tests/Controller/Api tests/Service/Passkey tests/Security`
Expected: PASS (`OK`).

Run: `bin/console cache:warmup --env=dev && composer stan && composer md && composer cs`
Expected: `[OK] No errors`, and no md or cs findings.

Run: `grep -rn "ApiException" src/Service/Passkey src/Security`
Expected: nothing.

- [ ] **Step 9: Commit.**

```bash
git status
git add -A src/Service/Passkey src/Http/Problem src/Security/PasskeyAuthenticator.php tests/Service/Passkey \
  tests/Controller/Api/PasskeyLoginTest.php
git commit -m "refactor(#1160): plain passkey exceptions, mapped at the edge"
```

#### 5b — Backup, Mail, Settings

**Files:**
- Create: `src/Http/Problem/BackupProblems.php`, `MailProblems.php` and `SettingsProblems.php`.
- Rewrite:
  - `src/Service/Backup/Exception/BackupDoesNotFitException.php`, `InvalidBackupException.php` and `BackupLoadFailedException.php`.
  - `src/Service/Mail/Settings/Exception/IncompleteMailConfigurationException.php`.
  - `src/Service/Settings/Exception/RelyingPartyChangeRequiresConfirmationException.php`.
- Modify: `src/Http/Problem/ProblemCatalog.php` (`legacy()`) and `src/Service/Backup/GzipLineReader.php` (one docblock).
- Test: rewrite `tests/Service/Settings/Exception/RelyingPartyChangeRequiresConfirmationExceptionTest.php`.

- [ ] **Step 1: Rewrite the five exceptions.** Replace each file whole.

`src/Service/Backup/Exception/BackupDoesNotFitException.php`:

```php
<?php

declare(strict_types=1);

namespace App\Service\Backup\Exception;

/** A well-formed backup that does not fit this account; always raised before any deletion. */
final class BackupDoesNotFitException extends \RuntimeException
{
}
```

`src/Service/Backup/Exception/InvalidBackupException.php`:

```php
<?php

declare(strict_types=1);

namespace App\Service\Backup\Exception;

/**
 * The uploaded bytes are not an acceptable backup. BackupInspector raises it in pass 1, before any deletion;
 * the load's own checks for the same conditions raise BackupLoadFailedException instead.
 */
final class InvalidBackupException extends \RuntimeException
{
}
```

`src/Service/Backup/Exception/BackupLoadFailedException.php`:

```php
<?php

declare(strict_types=1);

namespace App\Service\Backup\Exception;

/**
 * A restore load failed after grammar validation: the storage layer refused a value the grammar accepts, or a
 * reference dangled. The cause is chained for the log only; the message is authored and safe to show.
 */
final class BackupLoadFailedException extends \RuntimeException
{
    private const string REMEDY = 'The account is now empty. '
        . 'Correct or re-export the backup, then run the restore again.';

    private const string REJECTED = 'The restore emptied the account and then could not load the file: '
        . 'the database rejected one of its values. ';

    private const string DANGLING = 'The restore emptied the account and then could not load the file: '
        . 'it refers to a row it never declares. ';

    private const string ADDITIVE = 'A backup part could not be loaded: '
        . 'the database rejected one of its values. The account was not emptied; '
        . 'correct or re-export the backup, then continue the restore.';

    public static function from(\Throwable $cause): self
    {
        return new self(self::REJECTED . self::REMEDY, $cause);
    }

    public static function duringEntries(\Throwable $cause): self
    {
        return new self(self::ADDITIVE, $cause);
    }

    /** BackupInspector accepted a reference the load cannot resolve: the two passes disagree about the same bytes. */
    public static function danglingReference(string $reason): self
    {
        return new self(self::DANGLING . self::REMEDY, new \LogicException($reason));
    }

    private function __construct(string $message, \Throwable $cause)
    {
        parent::__construct($message, previous: $cause);
    }
}
```

`src/Service/Mail/Settings/Exception/IncompleteMailConfigurationException.php`:

```php
<?php

declare(strict_types=1);

namespace App\Service\Mail\Settings\Exception;

/** Refuses to persist an enabled row that could not send: it would accept every message and deliver none. */
final class IncompleteMailConfigurationException extends \RuntimeException
{
    private function __construct(string $message)
    {
        parent::__construct($message);
    }

    public static function passwordMissing(): self
    {
        return new self('An enabled SMTP transport with a username needs a password, stored or provided.');
    }

    public static function transportMissing(): self
    {
        return new self('Enabling mail needs an SMTP host, because the environment has no fallback transport.');
    }

    public static function proxyMissing(): self
    {
        return new self('Mail is set to use the egress proxy, but no proxy is configured.');
    }
}
```

`src/Service/Settings/Exception/RelyingPartyChangeRequiresConfirmationException.php`:

```php
<?php

declare(strict_types=1);

namespace App\Service\Settings\Exception;

/** A relying-party id change while passkeys exist; resending with invalidateExistingPasskeys confirms it. */
final class RelyingPartyChangeRequiresConfirmationException extends \RuntimeException
{
    public function __construct(public readonly int $invalidatedPasskeyCount)
    {
        parent::__construct(\sprintf(
            'Changing the passkey relying party id invalidates %d enrolled passkey(s). '
            . 'Resend the request with invalidateExistingPasskeys set to confirm.',
            $invalidatedPasskeyCount,
        ));
    }
}
```

- [ ] **Step 2: Drop the legacy branch that PHPStan now rejects.**

In `src/Http/Problem/ProblemCatalog.php`, delete this import:

```php
use App\Service\Settings\Exception\RelyingPartyChangeRequiresConfirmationException;
```

and, in `legacy()`, delete:

```php

        if ($exception instanceof RelyingPartyChangeRequiresConfirmationException) {
            $extensions['invalidatedPasskeyCount'] = $exception->invalidatedPasskeyCount;
        }
```

- [ ] **Step 3: Run the red rows.**

Run: `php bin/phpunit tests/Http/Problem/ProblemContractTest.php --filter 'backup|mail|relying party'`
Expected: FAIL, 6 of 6, with `Failed asserting that 500 is identical to …`.

- [ ] **Step 4: Write the three mappers.**

`src/Http/Problem/BackupProblems.php`:

```php
<?php

declare(strict_types=1);

namespace App\Http\Problem;

use App\Service\Backup\Exception\BackupDoesNotFitException;
use App\Service\Backup\Exception\BackupLoadFailedException;
use App\Service\Backup\Exception\InvalidBackupException;
use Symfony\Component\HttpFoundation\Response;

final readonly class BackupProblems implements ExceptionProblems
{
    public function resolve(\Throwable $exception): ?ResolvedProblem
    {
        return match (true) {
            $exception instanceof BackupDoesNotFitException => new ResolvedProblem(new ApiProblem(
                'backup_does_not_fit',
                'The backup does not fit this account',
                Response::HTTP_CONFLICT,
                $exception->getMessage(),
            )),
            $exception instanceof InvalidBackupException => new ResolvedProblem(new ApiProblem(
                'invalid_backup',
                'Invalid backup file',
                Response::HTTP_UNPROCESSABLE_ENTITY,
                $exception->getMessage(),
            )),
            $exception instanceof BackupLoadFailedException => new ResolvedProblem(new ApiProblem(
                'backup_load_failed',
                'The backup could not be loaded',
                Response::HTTP_UNPROCESSABLE_ENTITY,
                $exception->getMessage(),
            )),
            default => null,
        };
    }
}
```

`src/Http/Problem/MailProblems.php`:

```php
<?php

declare(strict_types=1);

namespace App\Http\Problem;

use App\Service\Mail\Settings\Exception\IncompleteMailConfigurationException;
use Symfony\Component\HttpFoundation\Response;

final readonly class MailProblems implements ExceptionProblems
{
    public function resolve(\Throwable $exception): ?ResolvedProblem
    {
        return match (true) {
            $exception instanceof IncompleteMailConfigurationException => new ResolvedProblem(new ApiProblem(
                'incomplete_mail_configuration',
                'Incomplete mail configuration',
                Response::HTTP_UNPROCESSABLE_ENTITY,
                $exception->getMessage(),
            )),
            default => null,
        };
    }
}
```

`src/Http/Problem/SettingsProblems.php`:

```php
<?php

declare(strict_types=1);

namespace App\Http\Problem;

use App\Service\Settings\Exception\RelyingPartyChangeRequiresConfirmationException;
use Symfony\Component\HttpFoundation\Response;

final readonly class SettingsProblems implements ExceptionProblems
{
    public function resolve(\Throwable $exception): ?ResolvedProblem
    {
        return match (true) {
            $exception instanceof RelyingPartyChangeRequiresConfirmationException => new ResolvedProblem(
                new ApiProblem(
                    'relying_party_change_requires_confirmation',
                    'Relying party change requires confirmation',
                    Response::HTTP_CONFLICT,
                    $exception->getMessage(),
                ),
                extensions: ['invalidatedPasskeyCount' => $exception->invalidatedPasskeyCount],
            ),
            default => null,
        };
    }
}
```

Run the Step 3 command again. Expected: `OK (6 tests, 36 assertions)`.

- [ ] **Step 5: Update the exception test and the stale docblock.**

Replace the whole of `tests/Service/Settings/Exception/RelyingPartyChangeRequiresConfirmationExceptionTest.php` with:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Service\Settings\Exception;

use App\Service\Settings\Exception\RelyingPartyChangeRequiresConfirmationException;
use PHPUnit\Framework\TestCase;

final class RelyingPartyChangeRequiresConfirmationExceptionTest extends TestCase
{
    /** Pins both sentences and their order: the admin acts on this text to know what to resend. */
    public function testTheMessageNamesTheCountAndTheConfirmationField(): void
    {
        $exception = new RelyingPartyChangeRequiresConfirmationException(3);

        self::assertSame(3, $exception->invalidatedPasskeyCount);
        self::assertSame(
            'Changing the passkey relying party id invalidates 3 enrolled passkey(s). '
            . 'Resend the request with invalidateExistingPasskeys set to confirm.',
            $exception->getMessage(),
        );
    }
}
```

In `src/Service/Backup/GzipLineReader.php`:

```php
    /**
     * Valid magic bytes are no promise that the rest of the body inflates: a
     * partially downloaded or bit-flipped file raises "zlib: data error" as a
     * PHP diagnostic, which Symfony's ErrorHandler turns into an
     * ErrorException — not an ApiException, so the listener would answer 500
     * with a stack trace instead of the 422 this refusal is. The handler is
     * installed around the fgets call alone, never across the yield, so it
     * cannot leak into the code consuming the generator.
     *
```
→
```php
    /**
     * Valid magic bytes promise nothing about the rest: a truncated or bit-flipped body raises a zlib diagnostic,
     * an ErrorException that would surface as an opaque 500 instead of this refusal. The handler wraps the fgets
     * call alone, never the yield, so it cannot leak into the generator's consumer.
     *
```

- [ ] **Step 6: Run the module's tests and gates.**

Run: `php bin/phpunit tests/Http/Problem tests/Service/Backup tests/Service/Mail tests/Service/Settings tests/Controller`
Expected: PASS (`OK`).

Run: `bin/console cache:warmup --env=dev && composer stan && composer md && composer cs`
Expected: `[OK] No errors`, and no md or cs findings.

Run: `grep -rn "ApiException" src/Service/Backup src/Service/Mail src/Service/Settings`
Expected: nothing.

- [ ] **Step 7: Commit.**

```bash
git status
git add -A src/Service/Backup src/Service/Mail/Settings/Exception src/Service/Settings/Exception src/Http/Problem \
  tests/Service/Settings/Exception
git commit -m "refactor(#1160): plain backup, mail and settings exceptions, mapped at the edge"
```

#### 5c — Account, Subscription

**Files:**
- Move and rewrite:
  - `src/Exception/LastAdminException.php` → `src/Service/Account/Exception/LastAdminException.php`
  - `src/Exception/AlreadySubscribedException.php` → `src/Service/Subscription/Exception/AlreadySubscribedException.php`
  - `src/Exception/SubscriptionLimitReachedException.php` → `src/Service/Subscription/Exception/SubscriptionLimitReachedException.php`
- Create: `src/Http/Problem/AccountProblems.php` and `src/Http/Problem/SubscriptionProblems.php`.
- Modify (`use` lines only): `src/Service/Account/AccountDeleter.php`, `src/Service/Subscription/SubscriptionCreator.php`, `tests/Service/Account/AccountDeleterTest.php`, `tests/Service/Subscription/SubscriptionServiceTest.php` and `tests/Http/Problem/ProblemContractTest.php`.
- Delete: `tests/Exception/ReaderExceptionsTest.php`. It asserts only `->type` and `->status`, which contract rows `'subscription limit reached'`, `'already subscribed'` and `'tag name taken'` now pin.

- [ ] **Step 1: Move the three files and fix every import.**

```bash
mkdir -p src/Service/Account/Exception src/Service/Subscription/Exception
git mv src/Exception/LastAdminException.php src/Service/Account/Exception/LastAdminException.php
git mv src/Exception/AlreadySubscribedException.php src/Service/Subscription/Exception/AlreadySubscribedException.php
git mv src/Exception/SubscriptionLimitReachedException.php \
  src/Service/Subscription/Exception/SubscriptionLimitReachedException.php
perl -pi -e 's/^use App\\Exception\\LastAdminException;$/use App\\Service\\Account\\Exception\\LastAdminException;/; s/^use App\\Exception\\(AlreadySubscribedException|SubscriptionLimitReachedException);$/use App\\Service\\Subscription\\Exception\\$1;/' \
  src/Service/Account/AccountDeleter.php src/Service/Subscription/SubscriptionCreator.php \
  tests/Service/Account/AccountDeleterTest.php tests/Service/Subscription/SubscriptionServiceTest.php \
  tests/Http/Problem/ProblemContractTest.php
git rm tests/Exception/ReaderExceptionsTest.php
grep -rnE 'App\\Exception\\(LastAdmin|AlreadySubscribed|SubscriptionLimitReached)Exception' src tests
```

Expected grep output: nothing.

- [ ] **Step 2: Rewrite the three exceptions.** Replace each file whole.

`src/Service/Account/Exception/LastAdminException.php`:

```php
<?php

declare(strict_types=1);

namespace App\Service\Account\Exception;

/**
 * The change would leave no Active administrator; a suspended admin cannot approve or reinstate anyone. It does
 * not guard hasAnyAdmin()'s first-run-setup invariant, which stays status-blind on purpose.
 */
final class LastAdminException extends \RuntimeException
{
}
```

`src/Service/Subscription/Exception/AlreadySubscribedException.php`:

```php
<?php

declare(strict_types=1);

namespace App\Service\Subscription\Exception;

final class AlreadySubscribedException extends \RuntimeException
{
}
```

`src/Service/Subscription/Exception/SubscriptionLimitReachedException.php`:

```php
<?php

declare(strict_types=1);

namespace App\Service\Subscription\Exception;

final class SubscriptionLimitReachedException extends \RuntimeException
{
    public function __construct(int $limit)
    {
        parent::__construct(sprintf('You can subscribe to at most %d feeds.', $limit));
    }
}
```

- [ ] **Step 3: Run the red rows.**

Run: `php bin/phpunit tests/Http/Problem/ProblemContractTest.php --filter 'last admin|subscription limit|already subscribed'`
Expected: FAIL, 3 of 3, with `Failed asserting that 500 is identical to 409`.

- [ ] **Step 4: Write the two mappers.**

`src/Http/Problem/AccountProblems.php`:

```php
<?php

declare(strict_types=1);

namespace App\Http\Problem;

use App\Service\Account\Exception\LastAdminException;
use Symfony\Component\HttpFoundation\Response;

final readonly class AccountProblems implements ExceptionProblems
{
    public function resolve(\Throwable $exception): ?ResolvedProblem
    {
        return match (true) {
            $exception instanceof LastAdminException => new ResolvedProblem(new ApiProblem(
                'last_admin',
                'Last administrator',
                Response::HTTP_CONFLICT,
                'This is the only administrator account. Promote another account first.',
            )),
            default => null,
        };
    }
}
```

`src/Http/Problem/SubscriptionProblems.php`:

```php
<?php

declare(strict_types=1);

namespace App\Http\Problem;

use App\Service\Subscription\Exception\AlreadySubscribedException;
use App\Service\Subscription\Exception\SubscriptionLimitReachedException;
use Symfony\Component\HttpFoundation\Response;

final readonly class SubscriptionProblems implements ExceptionProblems
{
    public function resolve(\Throwable $exception): ?ResolvedProblem
    {
        return match (true) {
            $exception instanceof SubscriptionLimitReachedException => new ResolvedProblem(new ApiProblem(
                'subscription_limit_reached',
                'Subscription limit reached',
                Response::HTTP_CONFLICT,
                $exception->getMessage(),
            )),
            $exception instanceof AlreadySubscribedException => new ResolvedProblem(new ApiProblem(
                'already_subscribed',
                'Already subscribed to that feed',
                Response::HTTP_CONFLICT,
            )),
            default => null,
        };
    }
}
```

Run the Step 3 command again. Expected: `OK (3 tests, 18 assertions)`.

- [ ] **Step 5: Run the module's tests and gates.**

Run: `php bin/phpunit tests/Http/Problem tests/Service/Account tests/Service/Subscription tests/Controller/Admin tests/Controller/Api/SubscriptionControllerTest.php`
Expected: PASS (`OK`).

Run: `bin/console cache:warmup --env=dev && composer stan && composer md && composer cs`
Expected: `[OK] No errors`, and no md or cs findings.

- [ ] **Step 6: Commit.**

```bash
git status
git add -A src/Exception src/Service/Account src/Service/Subscription src/Http/Problem tests/Exception \
  tests/Service/Account/AccountDeleterTest.php tests/Service/Subscription/SubscriptionServiceTest.php \
  tests/Http/Problem/ProblemContractTest.php
git commit -m "refactor(#1160): plain account and subscription exceptions, mapped at the edge"
```

#### 5d — Auth, RateLimit

**Files:**
- Move and rewrite:
  - `src/Exception/{InvalidSetupSecret,SetupUnavailable,InvalidToken,AccountNotActive}Exception.php` → `src/Service/Auth/Exception/`
  - `src/Exception/RateLimitedException.php` → `src/Service/RateLimit/Exception/RateLimitedException.php`
- Rewrite in place: `src/Exception/InvalidCredentialsException.php`.
- Create: `src/Http/Problem/AuthProblems.php` and `src/Http/Problem/RateLimitProblems.php`.
- Modify:
  - `src/Http/Problem/ProblemCatalog.php`: `legacy()` and two imports.
  - `src/Service/RateLimit/RateLimitGuard.php`: one comment.
  - `src/Service/Recommendation/Exception/RecommendationRunRateLimitedException.php`: docblock.
  - `use` lines only: `src/Service/Auth/WebAdminSetup.php`, `src/Controller/Api/AuthController.php`, `src/Service/OAuth/OAuthSignIn.php`, `src/Security/LoginFailureHandler.php`, `src/Service/RateLimit/RateLimitGuard.php`, `tests/Service/Auth/WebAdminSetupTest.php`, `tests/Service/OAuth/OAuthSignInTest.php`, `tests/Service/RateLimit/RateLimitGuardTest.php` and `tests/Http/Problem/ProblemContractTest.php`.
- Test: `tests/Exception/ApiExceptionTest.php`. Keep only the validation test.

- [ ] **Step 1: Move the five files and fix every import.** The anchor `^use App\\Exception\\` leaves Lexik's `InvalidTokenException` import in `PasswordChangeTokenInvalidator.php` alone.

```bash
mkdir -p src/Service/Auth/Exception src/Service/RateLimit/Exception
git mv src/Exception/InvalidSetupSecretException.php src/Service/Auth/Exception/InvalidSetupSecretException.php
git mv src/Exception/SetupUnavailableException.php src/Service/Auth/Exception/SetupUnavailableException.php
git mv src/Exception/InvalidTokenException.php src/Service/Auth/Exception/InvalidTokenException.php
git mv src/Exception/AccountNotActiveException.php src/Service/Auth/Exception/AccountNotActiveException.php
git mv src/Exception/RateLimitedException.php src/Service/RateLimit/Exception/RateLimitedException.php
perl -pi -e 's/^use App\\Exception\\(InvalidSetupSecretException|SetupUnavailableException|InvalidTokenException|AccountNotActiveException);$/use App\\Service\\Auth\\Exception\\$1;/; s/^use App\\Exception\\RateLimitedException;$/use App\\Service\\RateLimit\\Exception\\RateLimitedException;/' \
  src/Service/Auth/WebAdminSetup.php src/Controller/Api/AuthController.php src/Service/OAuth/OAuthSignIn.php \
  src/Security/LoginFailureHandler.php src/Service/RateLimit/RateLimitGuard.php \
  tests/Service/Auth/WebAdminSetupTest.php tests/Service/OAuth/OAuthSignInTest.php \
  tests/Service/RateLimit/RateLimitGuardTest.php tests/Http/Problem/ProblemContractTest.php
```

In `src/Http/Problem/ProblemCatalog.php`, delete these two imports:

```php
use App\Exception\AccountNotActiveException;
```
```php
use App\Exception\RateLimitedException;
```

and replace `legacy()`:

```php
    private static function legacy(ApiException $exception): ResolvedProblem
    {
        $problem = new ApiProblem(
            $exception->type,
            $exception->title,
            $exception->status,
            $exception->detail,
            $exception->errors,
        );

        $headers = [];
        if ($exception instanceof RateLimitedException) {
            $headers['Retry-After'] = (string) $exception->retryAfterSeconds;
        }

        $extensions = [];
        if ($exception instanceof AccountNotActiveException) {
            $extensions['accountStatus'] = $exception->accountStatus;
        }

        return new ResolvedProblem($problem, $headers, $extensions);
    }
```
→
```php
    private static function legacy(ApiException $exception): ResolvedProblem
    {
        return new ResolvedProblem(new ApiProblem(
            $exception->type,
            $exception->title,
            $exception->status,
            $exception->detail,
            $exception->errors,
        ));
    }
```

In `tests/Exception/ApiExceptionTest.php`, delete these imports:

```php
use App\Exception\AccountNotActiveException;
```
```php
use App\Exception\InvalidCredentialsException;
use App\Exception\InvalidTokenException;
use App\Exception\RateLimitedException;
```

and delete the methods `testInvalidCredentialsIs401()`, `testAccountNotActiveIs403AndNamesTheStatus()`, `testInvalidTokenIs400()` and `testRateLimitedIs429AndCarriesRetryAfter()` whole. Contract rows `'invalid credentials'`, `'account suspended'`, `'invalid token'` and `'rate limited'` pin what they asserted. Only `testValidationExceptionCarriesFieldErrors()` remains.

Check the imports:

```bash
grep -rnE 'App\\Exception\\(InvalidSetupSecret|SetupUnavailable|InvalidToken|AccountNotActive|RateLimited)Exception' src tests
```

Expected: nothing.

- [ ] **Step 2: Rewrite the six exceptions.** Replace each file whole.

`src/Service/Auth/Exception/InvalidSetupSecretException.php`:

```php
<?php

declare(strict_types=1);

namespace App\Service\Auth\Exception;

final class InvalidSetupSecretException extends \RuntimeException
{
}
```

`src/Service/Auth/Exception/SetupUnavailableException.php`:

```php
<?php

declare(strict_types=1);

namespace App\Service\Auth\Exception;

/** No setup secret is configured, or an administrator exists; both look alike, so neither is revealed. */
final class SetupUnavailableException extends \RuntimeException
{
}
```

`src/Service/Auth/Exception/InvalidTokenException.php`:

```php
<?php

declare(strict_types=1);

namespace App\Service\Auth\Exception;

/** A one-time link token that is invalid, used or expired. Not Lexik's JWT InvalidTokenException. */
final class InvalidTokenException extends \RuntimeException
{
}
```

`src/Service/Auth/Exception/AccountNotActiveException.php`:

```php
<?php

declare(strict_types=1);

namespace App\Service\Auth\Exception;

/** The credentials were correct but the account may not sign in yet; the client words its message by status. */
final class AccountNotActiveException extends \RuntimeException
{
    public function __construct(public readonly string $accountStatus)
    {
        parent::__construct(match ($accountStatus) {
            'pending_verification' => 'Confirm your email address first.',
            'pending_approval' => 'An administrator has not approved this account yet.',
            'suspended' => 'This account has been suspended.',
            'rejected' => 'This account was rejected.',
            default => 'This account cannot sign in.',
        });
    }
}
```

`src/Exception/InvalidCredentialsException.php`:

```php
<?php

declare(strict_types=1);

namespace App\Exception;

final class InvalidCredentialsException extends \RuntimeException
{
}
```

`src/Service/RateLimit/Exception/RateLimitedException.php`:

```php
<?php

declare(strict_types=1);

namespace App\Service\RateLimit\Exception;

final class RateLimitedException extends \RuntimeException
{
    public function __construct(public readonly int $retryAfterSeconds)
    {
    }
}
```

- [ ] **Step 3: Run the red rows.**

Run: `php bin/phpunit tests/Http/Problem/ProblemContractTest.php --filter 'rate limited|invalid credentials|invalid token|setup|account'`
Expected: FAIL, 10 of 11, with `Failed asserting that 500 is identical to …`. The eleventh, `'account status stays opaque'`, still passes: it is the catalog's own 401.

- [ ] **Step 4: Write the two mappers.**

`src/Http/Problem/AuthProblems.php`:

```php
<?php

declare(strict_types=1);

namespace App\Http\Problem;

use App\Exception\InvalidCredentialsException;
use App\Service\Auth\Exception\AccountNotActiveException;
use App\Service\Auth\Exception\InvalidSetupSecretException;
use App\Service\Auth\Exception\InvalidTokenException;
use App\Service\Auth\Exception\SetupUnavailableException;
use Symfony\Component\HttpFoundation\Response;

final readonly class AuthProblems implements ExceptionProblems
{
    public function resolve(\Throwable $exception): ?ResolvedProblem
    {
        return match (true) {
            $exception instanceof InvalidCredentialsException => new ResolvedProblem(new ApiProblem(
                'invalid_credentials',
                'Invalid credentials',
                Response::HTTP_UNAUTHORIZED,
                'Email address or password is incorrect.',
            )),
            $exception instanceof InvalidTokenException => new ResolvedProblem(new ApiProblem(
                'invalid_token',
                'Invalid token',
                Response::HTTP_BAD_REQUEST,
                'This link is invalid, already used, or expired.',
            )),
            $exception instanceof InvalidSetupSecretException => new ResolvedProblem(new ApiProblem(
                'invalid_setup_secret',
                'Forbidden',
                Response::HTTP_FORBIDDEN,
                'The setup secret is incorrect.',
            )),
            $exception instanceof SetupUnavailableException => new ResolvedProblem(new ApiProblem(
                'setup_unavailable',
                'Not found',
                Response::HTTP_NOT_FOUND,
                'Setup is not available.',
            )),
            $exception instanceof AccountNotActiveException => new ResolvedProblem(
                new ApiProblem(
                    'account_not_active',
                    'Account not active',
                    Response::HTTP_FORBIDDEN,
                    $exception->getMessage(),
                ),
                extensions: ['accountStatus' => $exception->accountStatus],
            ),
            default => null,
        };
    }
}
```

`src/Http/Problem/RateLimitProblems.php`:

```php
<?php

declare(strict_types=1);

namespace App\Http\Problem;

use App\Service\RateLimit\Exception\RateLimitedException;
use Symfony\Component\HttpFoundation\Response;

final readonly class RateLimitProblems implements ExceptionProblems
{
    public function resolve(\Throwable $exception): ?ResolvedProblem
    {
        return match (true) {
            $exception instanceof RateLimitedException => new ResolvedProblem(
                new ApiProblem(
                    'rate_limited',
                    'Too many requests',
                    Response::HTTP_TOO_MANY_REQUESTS,
                    'Too many attempts. Try again later.',
                ),
                ['Retry-After' => (string) $exception->retryAfterSeconds],
            ),
            default => null,
        };
    }
}
```

Run the Step 3 command again. Expected: `OK (11 tests, 67 assertions)`.

*(Executor amendment, preflight2 #1)* The `(string)` cast is an escaped mutant, because `HeaderBag::get()` stringifies an int. Add `tests/Http/Problem/RateLimitProblemsTest.php`, which asserts `(new RateLimitProblems())->resolve(new RateLimitedException(120))?->headers === ['Retry-After' => '120']` with `assertSame`, and asserts that an unrelated exception resolves to `null`.

- [ ] **Step 5: Fix the two comments that named the old class or the listener.**

`src/Service/RateLimit/RateLimitGuard.php`:

```php
        // The ApiExceptionListener turns this into 429 problem+json with a
        // Retry-After header. max(1, ...) because a retryAfter that has just
        // elapsed would otherwise render as "Retry-After: 0", which clients read
        // as "now".
```
→
```php
        // max(1, …): a retryAfter that has just elapsed would render as "Retry-After: 0",
        // which clients read as "now".
```

`src/Service/Recommendation/Exception/RecommendationRunRateLimitedException.php`:

```php
/**
 * A rate-limited call the current tick will not wait out: the advancer catches
 * it, records "retry not before now + waitSeconds" on the run, and returns at
 * once. Named apart from App\Exception\RateLimitedException, which is the HTTP
 * limiter's 429 to the client (#947).
 */
```
→
```php
/**
 * A rate-limited provider call the tick will not wait out: the advancer records "retry not before now +
 * waitSeconds" and returns. Named apart from Service\RateLimit\Exception\RateLimitedException (#947).
 */
```

- [ ] **Step 6: Run the module's tests and gates.**

Run: `php bin/phpunit tests/Http/Problem tests/Exception tests/Security tests/Service/Auth tests/Service/OAuth tests/Service/RateLimit tests/Controller/Api/LoginTest.php tests/Controller/Api/JwtAccessTest.php tests/Controller/Api/AuthJourneyTest.php tests/Controller/Api/OAuthFlowTest.php tests/Controller/Api/PasskeyLoginTest.php`
Expected: PASS (`OK`). This includes `LoginTest::testSixthFailedAttemptIsThrottled`, which still gets `Retry-After: 900`.

Run: `bin/console cache:warmup --env=dev && composer stan && composer md && composer cs`
Expected: `[OK] No errors`, and no md or cs findings.

- [ ] **Step 7: Commit.**

```bash
git status
git add -A src/Exception src/Service/Auth src/Service/RateLimit src/Service/OAuth/OAuthSignIn.php \
  src/Service/Recommendation/Exception/RecommendationRunRateLimitedException.php src/Controller/Api/AuthController.php \
  src/Security/LoginFailureHandler.php src/Http/Problem tests/Exception tests/Service/Auth tests/Service/OAuth \
  tests/Service/RateLimit tests/Http/Problem/ProblemContractTest.php
git commit -m "refactor(#1160): plain auth and rate-limit exceptions, mapped at the edge"
```

#### 5e — Opml, OAuth

**Files:**
- Move and rewrite:
  - `src/Exception/InvalidOpmlException.php` → `src/Service/Opml/Exception/InvalidOpmlException.php`
  - `src/Exception/OAuth/{OAuthException,OAuthFailedException,UnknownProviderException}.php` → `src/Service/OAuth/Exception/`
- Create: `src/Http/Problem/OpmlProblems.php` and `src/Http/Problem/OAuthProblems.php`.
- Modify (`use` lines only):
  - `src/Controller/Api/OpmlController.php`, `src/Service/Catalog/CatalogDocument.php` and `src/Service/Opml/OpmlBodyReader.php`.
  - `src/Controller/Api/OAuthController.php`.
  - `src/Service/OAuth/{AbstractOidcProvider,AppleClientSecretFactory,OAuthProviderInterface,OAuthProviderRegistry}.php` and `src/Service/OAuth/Oidc/{IdTokenClaims,IdTokenVerifier,TokenEndpoint}.php`.
- Test:
  - Move `tests/Exception/OAuth/OAuthExceptionTest.php` → `tests/Service/OAuth/Exception/OAuthExceptionTest.php`, rewritten.
  - Modify `tests/Service/OAuth/AbstractOidcProviderTest.php`, `AppleClientSecretFactoryTest.php` and `OAuthProviderRegistryTest.php`.
  - `use` lines only: `tests/Service/Opml/{OpmlBodyReaderTest,OpmlImporterTest}.php`, `tests/Service/OAuth/Oidc/{IdTokenClaimsTest,IdTokenVerifierTest,TokenEndpointTest}.php`, `tests/Support/FakeOAuthProvider.php` and `tests/Http/Problem/ProblemContractTest.php`.

- [ ] **Step 1: Move the files and fix every import.**

```bash
mkdir -p src/Service/Opml/Exception src/Service/OAuth/Exception tests/Service/OAuth/Exception
git mv src/Exception/InvalidOpmlException.php src/Service/Opml/Exception/InvalidOpmlException.php
git mv src/Exception/OAuth/OAuthException.php src/Service/OAuth/Exception/OAuthException.php
git mv src/Exception/OAuth/OAuthFailedException.php src/Service/OAuth/Exception/OAuthFailedException.php
git mv src/Exception/OAuth/UnknownProviderException.php src/Service/OAuth/Exception/UnknownProviderException.php
git mv tests/Exception/OAuth/OAuthExceptionTest.php tests/Service/OAuth/Exception/OAuthExceptionTest.php
perl -pi -e 's/^use App\\Exception\\InvalidOpmlException;$/use App\\Service\\Opml\\Exception\\InvalidOpmlException;/; s/^use App\\Exception\\OAuth\\(\w+);$/use App\\Service\\OAuth\\Exception\\$1;/' \
  src/Controller/Api/OpmlController.php src/Service/Catalog/CatalogDocument.php src/Service/Opml/OpmlBodyReader.php \
  src/Controller/Api/OAuthController.php src/Service/OAuth/AbstractOidcProvider.php \
  src/Service/OAuth/AppleClientSecretFactory.php src/Service/OAuth/OAuthProviderInterface.php \
  src/Service/OAuth/OAuthProviderRegistry.php src/Service/OAuth/Oidc/IdTokenClaims.php \
  src/Service/OAuth/Oidc/IdTokenVerifier.php src/Service/OAuth/Oidc/TokenEndpoint.php \
  tests/Service/Opml/OpmlBodyReaderTest.php tests/Service/Opml/OpmlImporterTest.php \
  tests/Service/OAuth/AbstractOidcProviderTest.php tests/Service/OAuth/AppleClientSecretFactoryTest.php \
  tests/Service/OAuth/OAuthProviderRegistryTest.php tests/Service/OAuth/Oidc/IdTokenClaimsTest.php \
  tests/Service/OAuth/Oidc/IdTokenVerifierTest.php tests/Service/OAuth/Oidc/TokenEndpointTest.php \
  tests/Support/FakeOAuthProvider.php tests/Http/Problem/ProblemContractTest.php
grep -rnE 'App\\Exception\\(InvalidOpmlException|OAuth\\)' src tests
```

Expected grep output: nothing.

- [ ] **Step 2: Rewrite the four exceptions and the moved test.** Replace each file whole.

`src/Service/Opml/Exception/InvalidOpmlException.php`:

```php
<?php

declare(strict_types=1);

namespace App\Service\Opml\Exception;

final class InvalidOpmlException extends \RuntimeException
{
}
```

`src/Service/OAuth/Exception/OAuthException.php`:

```php
<?php

declare(strict_types=1);

namespace App\Service\OAuth\Exception;

/** The OAuth flow's deliberate failures. */
abstract class OAuthException extends \RuntimeException
{
}
```

`src/Service/OAuth/Exception/OAuthFailedException.php`:

```php
<?php

declare(strict_types=1);

namespace App\Service\OAuth\Exception;

/**
 * The provider conversation produced no usable identity. One type for every cause on purpose: telling them apart
 * would hand a caller a probe into our configuration. The cause lives in $logDetail and $previous only.
 */
final class OAuthFailedException extends OAuthException
{
    public function __construct(public readonly string $logDetail, ?\Throwable $previous = null)
    {
        parent::__construct('The OAuth exchange failed.', previous: $previous);
    }
}
```

`src/Service/OAuth/Exception/UnknownProviderException.php`:

```php
<?php

declare(strict_types=1);

namespace App\Service\OAuth\Exception;

/**
 * The URL named a provider this deployment does not offer: a typo, a probe, or one without credentials. All three
 * must look alike, or the difference would reveal which providers this deployment holds keys for.
 */
final class UnknownProviderException extends OAuthException
{
}
```

`tests/Service/OAuth/Exception/OAuthExceptionTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Service\OAuth\Exception;

use App\Service\OAuth\Exception\OAuthException;
use App\Service\OAuth\Exception\OAuthFailedException;
use App\Service\OAuth\Exception\UnknownProviderException;
use PHPUnit\Framework\TestCase;

final class OAuthExceptionTest extends TestCase
{
    public function testBothFailuresBelongToTheOAuthFamily(): void
    {
        self::assertInstanceOf(OAuthException::class, new UnknownProviderException());
        self::assertInstanceOf(OAuthException::class, new OAuthFailedException('network'));
    }

    public function testTheLogDetailStaysOutOfTheMessage(): void
    {
        $exception = new OAuthFailedException('token endpoint returned 400 invalid_grant');

        self::assertSame('token endpoint returned 400 invalid_grant', $exception->logDetail);
        self::assertStringNotContainsString('invalid_grant', $exception->getMessage());
    }

    public function testTheCauseIsChainedForTheLog(): void
    {
        $cause = new \RuntimeException('Connection refused to oauth2.googleapis.com');

        self::assertSame($cause, (new OAuthFailedException('network', $cause))->getPrevious());
    }
}
```

The old `testNeitherTheLogDetailNorTheCauseReachesTheProblemDocument` is now contract row `'oauth failed, log detail and cause withheld'`. That row asserts the whole body.

- [ ] **Step 3: Run the red rows.**

Run: `php bin/phpunit tests/Http/Problem/ProblemContractTest.php --filter 'opml|sign-in provider|oauth'`
Expected: FAIL, 3 of 3, with `Failed asserting that 500 is identical to …`.

- [ ] **Step 4: Write the two mappers.**

`src/Http/Problem/OpmlProblems.php`:

```php
<?php

declare(strict_types=1);

namespace App\Http\Problem;

use App\Service\Opml\Exception\InvalidOpmlException;
use Symfony\Component\HttpFoundation\Response;

final readonly class OpmlProblems implements ExceptionProblems
{
    public function resolve(\Throwable $exception): ?ResolvedProblem
    {
        return match (true) {
            $exception instanceof InvalidOpmlException => new ResolvedProblem(new ApiProblem(
                'invalid_opml',
                'The OPML document could not be parsed',
                Response::HTTP_UNPROCESSABLE_ENTITY,
                $exception->getMessage(),
            )),
            default => null,
        };
    }
}
```

`src/Http/Problem/OAuthProblems.php`:

```php
<?php

declare(strict_types=1);

namespace App\Http\Problem;

use App\Service\OAuth\Exception\OAuthFailedException;
use App\Service\OAuth\Exception\UnknownProviderException;
use Symfony\Component\HttpFoundation\Response;

final readonly class OAuthProblems implements ExceptionProblems
{
    public function resolve(\Throwable $exception): ?ResolvedProblem
    {
        return match (true) {
            $exception instanceof UnknownProviderException => new ResolvedProblem(new ApiProblem(
                'unknown_provider',
                'Unknown sign-in provider',
                Response::HTTP_NOT_FOUND,
                'That sign-in provider is not available.',
            )),
            $exception instanceof OAuthFailedException => new ResolvedProblem(new ApiProblem(
                'oauth_failed',
                'Sign-in failed',
                Response::HTTP_BAD_GATEWAY,
                'Signing in with that provider did not work. Please try again.',
            )),
            default => null,
        };
    }
}
```

Run the Step 3 command again. Expected: `OK (3 tests, 18 assertions)`.

- [ ] **Step 5: Rewrite the three OAuth tests that read HTTP properties.**

`tests/Service/OAuth/AbstractOidcProviderTest.php`. Add the import below `use App\Service\OAuth\Exception\OAuthFailedException;`:

```php
use App\Http\Problem\OAuthProblems;
```

and in `testEveryFailureLooksIdenticalToTheCaller()`:

```php
                $shapes[] = [$e->type, $e->status, $e->title, $e->detail, $e->errors];
```
→
```php
                $shapes[] = [$e::class, $e->getMessage(), (new OAuthProblems())->resolve($e)];
```

The `array_unique(array_map(json_encode…))` that follows is unchanged. `json_encode` serialises the `ResolvedProblem`'s public properties.

`tests/Service/OAuth/AppleClientSecretFactoryTest.php`, in `testAnUnusableKeyFailsAsAGenericSignInFailure()`:

```php
        } catch (OAuthFailedException $e) {
            // Byte-identical to what a token-endpoint timeout produces. The
            // cause survives only in $logDetail and $previous, neither of which
            // ApiExceptionListener can reach.
            self::assertSame('Sign-in failed', $e->title);
            self::assertSame('Signing in with that provider did not work. Please try again.', $e->detail);
            self::assertSame(502, $e->status);
        }
```
→
```php
        } catch (OAuthFailedException $e) {
            self::assertSame('apple client secret could not be signed', $e->logDetail);
            self::assertNotNull($e->getPrevious());
        }
```

`tests/Service/OAuth/OAuthProviderRegistryTest.php`. Add the import below `use App\Dto\OAuth\OAuthIdentity;`:

```php
use App\Http\Problem\OAuthProblems;
```

and replace the docblock and the assertions of `testAnUnconfiguredProviderIsIndistinguishableFromAnAbsentOne()`:

```php
    /**
     * The invisibility property, asserted rather than assumed.
     *
     * "Provider is not registered at all" and "provider is registered but this
     * deployment has no credentials for it" must be indistinguishable from
     * outside. If they were not, an unauthenticated stranger could enumerate
     * which integrations this deployment holds keys for by diffing the two
     * responses — which is exactly the sort of thing that tells an attacker
     * where to spend their time.
     *
     * Compared field by field rather than by class, because the problem
     * document ApiExceptionListener renders is built from these five public
     * properties and nothing else. Two exceptions equal across all of them
     * serialise to byte-identical responses.
     */
```
→
```php
    /**
     * An unconfigured provider must be indistinguishable from an absent one, or a stranger could diff the two
     * responses to learn which integrations this deployment holds keys for.
     */
```

```php
        self::assertSame($absent::class, $unconfigured::class);
        self::assertSame($absent->type, $unconfigured->type);
        self::assertSame($absent->status, $unconfigured->status);
        self::assertSame($absent->title, $unconfigured->title);
        self::assertSame($absent->detail, $unconfigured->detail);
        self::assertSame($absent->errors, $unconfigured->errors);
        // The message is what a naive log line or a debug handler would print.
        self::assertSame($absent->getMessage(), $unconfigured->getMessage());
```
→
```php
        self::assertSame($absent::class, $unconfigured::class);
        self::assertEquals((new OAuthProblems())->resolve($absent), (new OAuthProblems())->resolve($unconfigured));
        self::assertSame($absent->getMessage(), $unconfigured->getMessage());
```

- [ ] **Step 6: Run the module's tests and gates.**

Run: `php bin/phpunit tests/Http/Problem tests/Service/OAuth tests/Service/Opml tests/Service/Catalog tests/Controller/Api/OAuthFlowTest.php tests/Controller/Api/OpmlControllerTest.php`
Expected: PASS (`OK`).

Run: `bin/console cache:warmup --env=dev && composer stan && composer md && composer cs`
Expected: `[OK] No errors`, and no md or cs findings.

- [ ] **Step 7: Commit.**

```bash
git status
git add -A src/Exception src/Service/Opml src/Service/OAuth src/Service/Catalog/CatalogDocument.php \
  src/Controller/Api/OpmlController.php src/Controller/Api/OAuthController.php src/Http/Problem tests/Exception \
  tests/Service/OAuth tests/Service/Opml tests/Support/FakeOAuthProvider.php tests/Http/Problem/ProblemContractTest.php
git commit -m "refactor(#1160): plain OPML and OAuth exceptions, mapped at the edge"
```

#### 5f — Request, Tag

**Files:**
- Rewrite in place: `src/Exception/ValidationException.php` and `src/Exception/TagNameTakenException.php`.
- Create: `src/Http/Problem/RequestProblems.php` and `src/Http/Problem/TagProblems.php`.
- Delete: `tests/Exception/ApiExceptionTest.php`. Its last method is contract row `'validation'`, and `SelfActionGuardTest` asserts `errors`.
- Modify: `tests/Service/Admin/SelfActionGuardTest.php`.

- [ ] **Step 1: Rewrite the two exceptions.** Replace each file whole.

`src/Exception/ValidationException.php`:

```php
<?php

declare(strict_types=1);

namespace App\Exception;

final class ValidationException extends \RuntimeException
{
    /** @param array<string, list<string>> $errors field name => messages */
    public function __construct(public readonly array $errors)
    {
    }
}
```

`src/Exception/TagNameTakenException.php`:

```php
<?php

declare(strict_types=1);

namespace App\Exception;

final class TagNameTakenException extends \RuntimeException
{
}
```

In `tests/Service/Admin/SelfActionGuardTest.php`:

```php
        } catch (ValidationException $exception) {
            $this->assertSame(422, $exception->status);
            $this->assertSame(
```
→
```php
        } catch (ValidationException $exception) {
            $this->assertSame(
```

```bash
git rm tests/Exception/ApiExceptionTest.php
```

- [ ] **Step 2: Run the red rows.**

Run: `php bin/phpunit tests/Http/Problem/ProblemContractTest.php --filter '"validation"|tag name taken'`
Expected: FAIL, 2 of 2, with `Failed asserting that 500 is identical to 422` (or 409).

- [ ] **Step 3: Write the two mappers.**

`src/Http/Problem/RequestProblems.php`:

```php
<?php

declare(strict_types=1);

namespace App\Http\Problem;

use App\Exception\ValidationException;
use Symfony\Component\HttpFoundation\Response;

final readonly class RequestProblems implements ExceptionProblems
{
    public function resolve(\Throwable $exception): ?ResolvedProblem
    {
        return match (true) {
            $exception instanceof ValidationException => new ResolvedProblem(new ApiProblem(
                'validation_error',
                'Validation failed',
                Response::HTTP_UNPROCESSABLE_ENTITY,
                'One or more fields are invalid.',
                $exception->errors,
            )),
            default => null,
        };
    }
}
```

`src/Http/Problem/TagProblems.php`:

```php
<?php

declare(strict_types=1);

namespace App\Http\Problem;

use App\Exception\TagNameTakenException;
use Symfony\Component\HttpFoundation\Response;

final readonly class TagProblems implements ExceptionProblems
{
    public function resolve(\Throwable $exception): ?ResolvedProblem
    {
        return match (true) {
            $exception instanceof TagNameTakenException => new ResolvedProblem(new ApiProblem(
                'tag_name_taken',
                'Tag name already in use',
                Response::HTTP_CONFLICT,
            )),
            default => null,
        };
    }
}
```

Run the Step 2 command again. Expected: `OK (2 tests, 12 assertions)`.

- [ ] **Step 4: Confirm nothing extends the old base.**

```bash
grep -rn "extends ApiException" src tests
grep -rnw "ApiException" src tests
```

Expected:
- The first grep prints nothing.
- The second prints only these lines:
  - `src/Exception/ApiException.php`: the class declaration and its docblock.
  - `src/Http/Problem/ProblemCatalog.php`: `use App\Exception\ApiException;`, the `instanceof ApiException` in `mapped()`, and the `legacy(ApiException $exception)` signature.

- [ ] **Step 5: Run the full suite and the gates.**

Run: `php bin/phpunit`
Expected: `OK`. Every test passes, with no skips beyond the suite's existing ones.

Run: `bin/console cache:warmup --env=dev && composer stan && composer md && composer cs`
Expected: `[OK] No errors`, and no md or cs findings.

- [ ] **Step 6: Commit.**

```bash
git status
git add -A src/Exception src/Http/Problem tests/Exception tests/Service/Admin/SelfActionGuardTest.php
git commit -m "refactor(#1160): plain validation and tag exceptions, mapped at the edge"
```

---
### Task 6: No Symfony HTTP exceptions under `src/Service` or `src/Repository`

**Files:**
- Create: `src/Repository/Exception/RecordNotFoundException.php` and `src/Exception/InvalidSelectionException.php`.
- Modify:
  - `src/Repository/UserRepository.php`, `src/Repository/CatalogFeedRepository.php` and `src/Repository/CatalogCategoryRepository.php`.
  - `src/Service/Reader/MarkReadService.php` and `src/Service/Reader/ExactSetGuard.php`.
  - `src/Service/Subscription/BulkSubscriptionUpdater.php`, `src/Service/Subscription/FeedTagMove.php` and `src/Service/Subscription/OwnedSubscriptions.php`.
  - `src/Http/Problem/RequestProblems.php`.
- Test:
  - `tests/Http/Problem/ProblemContractTest.php` (two rows) and `tests/Controller/Admin/AdminUserControllerTest.php` (one assertion).
  - `tests/Service/Reader/ExactSetGuardTest.php` and `tests/Service/Reader/MarkReadServiceTest.php`.
  - `tests/Service/Subscription/BulkSubscriptionUpdaterTest.php`, `FeedTagMoveTest.php` and `OwnedSubscriptionsTest.php`.

**Interfaces:**
- Consumes: `RequestProblems` from 5f, and `ValidationException(array $errors)`.
- Produces:
  - `App\Repository\Exception\RecordNotFoundException extends \RuntimeException`, with `\RuntimeException`'s constructor. Its message is authored ("User not found.") and becomes the 404's `detail`.
  - `App\Exception\InvalidSelectionException extends \RuntimeException`, with `\RuntimeException`'s constructor. Its message becomes the 422's `detail`.

**The deliberate contract change.**
- These 404s and 422s used to come from bare Symfony HTTP exceptions, which render no `detail`. From this task on they carry their authored message as `detail`, for example "No such tag." or "subscriptionIds must all be your feeds, without duplicates.".
- `type`, `title` and `status` are unchanged: `not_found`/`Not Found`/404 and `request_error`/`Unprocessable Content`/422.
- The unknown-scope arm in `MarkReadService` changes from a 400 to a `ValidationException`. No client can see that: `Dto/Entry/MarkReadRequest.php:12` carries `#[Assert\Choice(['all','feed','tag'])]`, so HTTP never reaches the arm.

- [ ] **Step 1: Create the two exceptions.**

`src/Repository/Exception/RecordNotFoundException.php`:

```php
<?php

declare(strict_types=1);

namespace App\Repository\Exception;

/** No row with the requested id. Not Doctrine's EntityNotFoundException, which is a proxy miss and a bug. */
final class RecordNotFoundException extends \RuntimeException
{
}
```

`src/Exception/InvalidSelectionException.php`:

```php
<?php

declare(strict_types=1);

namespace App\Exception;

/** The ids a request selected are not all the caller's own, or contradict each other; the message says which. */
final class InvalidSelectionException extends \RuntimeException
{
}
```

- [ ] **Step 2: Write the red tests.**

In `tests/Http/Problem/ProblemContractTest.php`, add these two imports after `use App\Exception\InvalidCredentialsException;`:

```php
use App\Exception\InvalidSelectionException;
use App\Repository\Exception\RecordNotFoundException;
```

and these two rows, directly after the `'validation'` row:

```php
        yield 'record not found' => [
            new RecordNotFoundException('No such tag.'),
            ['type' => 'not_found', 'title' => 'Not Found', 'status' => 404, 'detail' => 'No such tag.'],
        ];
        yield 'invalid selection' => [
            new InvalidSelectionException('subscriptionIds must all be your feeds, without duplicates.'),
            [
                'type' => 'request_error',
                'title' => 'Unprocessable Content',
                'status' => 422,
                'detail' => 'subscriptionIds must all be your feeds, without duplicates.',
            ],
        ];
```

In `tests/Controller/Admin/AdminUserControllerTest.php`:

```php
    public function testUnknownUserIsNotFound(): void
    {
        $admin = $this->admin();

        $this->call('POST', self::LIST . '/999999/approve', $this->tokenFor($admin));

        self::assertResponseStatusCodeSame(404);
        self::assertResponseHeaderSame('content-type', 'application/problem+json');
        self::assertSame('not_found', $this->payload()['type']);
    }
```
→
```php
    public function testUnknownUserIsNotFound(): void
    {
        $admin = $this->admin();

        $this->call('POST', self::LIST . '/999999/approve', $this->tokenFor($admin));

        self::assertResponseStatusCodeSame(404);
        self::assertResponseHeaderSame('content-type', 'application/problem+json');
        self::assertSame('not_found', $this->payload()['type']);
        self::assertSame('User not found.', $this->payload()['detail']);
    }
```

Switch the service tests to the new types:

```bash
perl -pi -e 's/^use Symfony\\Component\\HttpKernel\\Exception\\UnprocessableEntityHttpException;$/use App\\Exception\\InvalidSelectionException;/; s/\bUnprocessableEntityHttpException\b/InvalidSelectionException/g' \
  tests/Service/Reader/ExactSetGuardTest.php tests/Service/Subscription/BulkSubscriptionUpdaterTest.php \
  tests/Service/Subscription/FeedTagMoveTest.php tests/Service/Subscription/OwnedSubscriptionsTest.php
perl -ni -e 'print unless /^use Symfony\\Component\\HttpKernel\\Exception\\BadRequestHttpException;$/' \
  tests/Service/Reader/MarkReadServiceTest.php
perl -pi -e 's/^use Symfony\\Component\\HttpKernel\\Exception\\NotFoundHttpException;$/use App\\Repository\\Exception\\RecordNotFoundException;/; s/\bNotFoundHttpException::class/RecordNotFoundException::class/g; s/\bBadRequestHttpException::class/ValidationException::class/g' \
  tests/Service/Reader/MarkReadServiceTest.php
grep -rn "HttpException" tests/Service tests/Repository
```

Expected grep output: nothing. The result:
- `ExactSetGuardTest` reads `$this->fail('Expected an InvalidSelectionException.');` and `} catch (InvalidSelectionException $exception) {` at lines 41-42, and `$this->expectException(InvalidSelectionException::class);` at line 53.
- `BulkSubscriptionUpdaterTest` lines 225, 243, 261 and 278, `FeedTagMoveTest` line 135, and `OwnedSubscriptionsTest` lines 74, 86, 98 and 131 each read `$this->expectException(InvalidSelectionException::class);`.
- `MarkReadServiceTest` lines 98 and 176 read `$this->expectException(RecordNotFoundException::class);`. Line 195 (`testUnknownScopeIsRejected`) reads `$this->expectException(ValidationException::class);`. `ValidationException` is already imported at line 13. *(Executor amendment, preflight2 #4: once perl removes the import on line 16, these lines shift to 97, 175 and 194.)*
- *(Executor amendment, preflight2 #3)* Asserting only the class lets mutants survive. Rewrite `testUnknownScopeIsRejected` to catch the `ValidationException` and `assertSame(['scope' => ['Unknown scope "bogus".']], $exception->errors)`, using the scope value that test already sends.

- [ ] **Step 3: Run the red tests.**

Run: `php bin/phpunit tests/Http/Problem/ProblemContractTest.php tests/Controller/Admin/AdminUserControllerTest.php tests/Service/Reader/ExactSetGuardTest.php tests/Service/Reader/MarkReadServiceTest.php tests/Service/Subscription`
Expected: FAIL, with these reasons:
- `'record not found'` and `'invalid selection'`: `Failed asserting that 500 is identical to 404` (and 422).
- `testUnknownUserIsNotFound`: a warning `Undefined array key "detail"`, then `Failed asserting that null is identical to 'User not found.'`.
- The service tests: `Failed asserting that exception of type "Symfony\Component\HttpKernel\Exception\…HttpException" matches expected exception "App\…"`.
- `testTheMessageTravelsWithTheException` errors with the uncaught `UnprocessableEntityHttpException`.

- [ ] **Step 4: Map the two exceptions.**

Replace the whole of `src/Http/Problem/RequestProblems.php` with:

```php
<?php

declare(strict_types=1);

namespace App\Http\Problem;

use App\Exception\InvalidSelectionException;
use App\Exception\ValidationException;
use App\Repository\Exception\RecordNotFoundException;
use Symfony\Component\HttpFoundation\Response;

final readonly class RequestProblems implements ExceptionProblems
{
    public function resolve(\Throwable $exception): ?ResolvedProblem
    {
        return match (true) {
            $exception instanceof ValidationException => new ResolvedProblem(new ApiProblem(
                'validation_error',
                'Validation failed',
                Response::HTTP_UNPROCESSABLE_ENTITY,
                'One or more fields are invalid.',
                $exception->errors,
            )),
            $exception instanceof RecordNotFoundException => new ResolvedProblem(new ApiProblem(
                'not_found',
                Response::$statusTexts[Response::HTTP_NOT_FOUND],
                Response::HTTP_NOT_FOUND,
                $exception->getMessage(),
            )),
            $exception instanceof InvalidSelectionException => new ResolvedProblem(new ApiProblem(
                'request_error',
                Response::$statusTexts[Response::HTTP_UNPROCESSABLE_ENTITY],
                Response::HTTP_UNPROCESSABLE_ENTITY,
                $exception->getMessage(),
            )),
            default => null,
        };
    }
}
```

- [ ] **Step 5: The repositories throw `RecordNotFoundException`.**

`src/Repository/UserRepository.php`:

```php
use App\Entity\User;
use App\Enum\UserStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Bridge\Doctrine\Security\User\UserLoaderInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Security\Core\User\UserInterface;
```
→
```php
use App\Entity\User;
use App\Enum\UserStatus;
use App\Repository\Exception\RecordNotFoundException;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Bridge\Doctrine\Security\User\UserLoaderInterface;
use Symfony\Component\Security\Core\User\UserInterface;
```

```php
    /**
     * Fetch by id or fail with a 404. Throwing the HTTP exception here keeps the
     * lookup-or-404 guard out of the admin controller.
     */
    public function getById(int $id): User
    {
        return $this->find($id) ?? throw new NotFoundHttpException('User not found.');
    }
```
→
```php
    public function getById(int $id): User
    {
        return $this->find($id) ?? throw new RecordNotFoundException('User not found.');
    }
```

`src/Repository/CatalogFeedRepository.php`:

```php
use App\Entity\CatalogFeed;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
```
→
```php
use App\Entity\CatalogFeed;
use App\Repository\Exception\RecordNotFoundException;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
```

```php
    /**
     * Fetch by id or fail with a 404. Throwing the HTTP exception here keeps the
     * lookup-or-404 guard out of every admin controller that needs it, including
     * the reorder path that looks ids up from the request body, not the route.
     */
    public function getById(int $id): CatalogFeed
    {
        return $this->find($id) ?? throw new NotFoundHttpException('No such feed.');
    }
```
→
```php
    public function getById(int $id): CatalogFeed
    {
        return $this->find($id) ?? throw new RecordNotFoundException('No such feed.');
    }
```

`src/Repository/CatalogCategoryRepository.php`:

```php
use App\Entity\CatalogCategory;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
```
→
```php
use App\Entity\CatalogCategory;
use App\Repository\Exception\RecordNotFoundException;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
```

```php
    /**
     * Fetch by id or fail with a 404. Throwing the HTTP exception here keeps the
     * lookup-or-404 guard out of every admin controller that needs it, including
     * the reorder paths that look ids up from the request body, not the route.
     */
    public function getById(int $id): CatalogCategory
    {
        return $this->find($id) ?? throw new NotFoundHttpException('No such category.');
    }
```
→
```php
    public function getById(int $id): CatalogCategory
    {
        return $this->find($id) ?? throw new RecordNotFoundException('No such category.');
    }
```

- [ ] **Step 6: The services throw domain exceptions.**

`src/Service/Reader/MarkReadService.php`:

```php
use App\Exception\ValidationException;
use App\Repository\SubscriptionRepository;
use App\Repository\TagRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
```
→
```php
use App\Exception\ValidationException;
use App\Repository\Exception\RecordNotFoundException;
use App\Repository\SubscriptionRepository;
use App\Repository\TagRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
```

```php
            default => throw new BadRequestHttpException(sprintf('Unknown scope "%s".', $scope)),
```
→
```php
            default => throw new ValidationException(['scope' => [sprintf('Unknown scope "%s".', $scope)]]),
```

```php
        return $this->subscriptions->findOneOwnedBy($id, $userId)
            ?? throw new NotFoundHttpException('No such subscription.');
```
→
```php
        return $this->subscriptions->findOneOwnedBy($id, $userId)
            ?? throw new RecordNotFoundException('No such subscription.');
```

```php
        $tag = $this->tags->findOneOwnedBy($id, $userId)
            ?? throw new NotFoundHttpException('No such tag.');
```
→
```php
        $tag = $this->tags->findOneOwnedBy($id, $userId)
            ?? throw new RecordNotFoundException('No such tag.');
```

For the four selection guards, one command makes every change shown below:

```bash
perl -pi -e 's/^use Symfony\\Component\\HttpKernel\\Exception\\UnprocessableEntityHttpException;$/use App\\Exception\\InvalidSelectionException;/; s/\bUnprocessableEntityHttpException\b/InvalidSelectionException/g' \
  src/Service/Reader/ExactSetGuard.php src/Service/Subscription/BulkSubscriptionUpdater.php \
  src/Service/Subscription/FeedTagMove.php src/Service/Subscription/OwnedSubscriptions.php
```

It changes these lines:

| File | Before | After |
|---|---|---|
| `ExactSetGuard.php` (import) | `use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;` | `use App\Exception\InvalidSelectionException;` |
| `ExactSetGuard.php` (throw) | `throw new UnprocessableEntityHttpException($message);` | `throw new InvalidSelectionException($message);` |
| `BulkSubscriptionUpdater.php` (import) | `use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;` | `use App\Exception\InvalidSelectionException;` |
| `BulkSubscriptionUpdater.php` (throws) | `throw new UnprocessableEntityHttpException(` (twice) | `throw new InvalidSelectionException(` (twice). The messages `'A tag cannot be added and removed in the same request.'` and `'addTagIds and removeTagIds must all be your tags, without duplicates.'` are unchanged. |
| `FeedTagMove.php` (import) | `use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;` | `use App\Exception\InvalidSelectionException;` |
| `FeedTagMove.php` (throw) | `?? throw new UnprocessableEntityHttpException('The tag must be one of yours.');` | `?? throw new InvalidSelectionException('The tag must be one of yours.');` |
| `OwnedSubscriptions.php` (import) | `use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;` | `use App\Exception\InvalidSelectionException;` |
| `OwnedSubscriptions.php` (throw) | `throw new UnprocessableEntityHttpException(` | `throw new InvalidSelectionException(` (message unchanged) |

Then fix `OwnedSubscriptions`' class docblock, which states an HTTP status in domain code:

```php
/**
 * Resolves a request's subscription ids to the caller's own subscriptions.
 *
 * Every endpoint taking a list of subscription ids needs the same refusal: an
 * id the caller does not own, an id that does not exist, and a duplicate all
 * get 422, nothing written. Three endpoints needed it (reorder, bulk update,
 * bulk unsubscribe), so the rule lives here instead of three times over.
 *
 * The count comparison catches all three at once: the repository only returns
 * rows the user owns, so a short result means an id was foreign or absent —
 * and a repeated id is short too, since `IN (...)` answers a duplicate once.
 * Comparing against the *unique* ids instead would let `[5, 5]` through,
 * which is the bug this replaces.
 */
```
→
```php
/**
 * Resolves a request's subscription ids to the caller's own subscriptions, refusing foreign, absent and repeated
 * ids alike. A short result catches all three: `IN (...)` answers a duplicate once, so never compare against the
 * unique ids, which would let `[5, 5]` through.
 */
```

Check that nothing in the domain throws a Symfony HTTP exception:

```bash
grep -rn "HttpKernel\\\\Exception" src/Service src/Repository src/Entity src/Enum src/Exception
```

Expected: nothing.

- [ ] **Step 7: Run the tests.**

Run: `php bin/phpunit tests/Http/Problem tests/Controller tests/Service/Reader tests/Service/Subscription tests/Repository`
Expected: PASS (`OK`). `ProblemContractTest` runs 68 tests.

- [ ] **Step 8: Run the static gates.**

Run: `bin/console cache:warmup --env=dev && composer stan && composer md && composer cs`
Expected: `[OK] No errors`, and no md or cs findings. Then run PhpStorm `lint_files` on the touched PHP files. Expected: no ERROR or WARNING.

- [ ] **Step 9: Commit.**

```bash
git status
git add -A src/Repository src/Exception/InvalidSelectionException.php src/Service/Reader src/Service/Subscription \
  src/Http/Problem/RequestProblems.php tests/Http/Problem/ProblemContractTest.php \
  tests/Controller/Admin/AdminUserControllerTest.php tests/Service/Reader tests/Service/Subscription
git commit -m "refactor(#1160): services and repositories throw domain exceptions, not HTTP ones"
```

---
### Task 7: Delete `ApiException`; guard the boundary

**Files:**
- Delete: `src/Exception/ApiException.php`.
- Modify:
  - `src/Http/Problem/ProblemCatalog.php`: remove the legacy arm.
  - `phpstan.dist.neon`: register the rule and `PhpParser\NodeFinder`.
  - `docs/architecture.md:60` and `CLAUDE.md`: the "Errors are exceptions" bullet.
- Create: `tests/PhpStan/DomainKnowsNoHttpRule.php`, `tests/PhpStan/DomainKnowsNoHttpRuleTest.php` and `tests/PhpStan/data/domain-knows-no-http-fixtures.php`.

**Interfaces:**
- Consumes: the tree after Task 6. Nothing extends `ApiException` any more (5f Step 4), and no domain class references a Symfony HTTP exception (Task 6 Step 6).
- Produces:
  - `App\Tests\PhpStan\DomainKnowsNoHttpRule implements Rule<FileNode>`, with constructor `(NodeFinder $finder)` and identifier `simpleFeedReader.domainKnowsNoHttp`.
  - `ProblemCatalog::resolve()` with no legacy arm.

What the rule forbids:
- It scans namespaces under `App\Service`, `App\Repository`, `App\Entity`, `App\Enum` and `App\Exception`.
- In all of them it forbids any reference to `Symfony\Component\HttpKernel\Exception\*`.
- In a namespace with an `Exception` segment it also forbids `Symfony\Component\HttpFoundation\Response` and `App\Http\*`.
- Service code outside an exception namespace may still import `Response` and `App\Http\*`. That is #1158's scope.
- Every `Name` node reports: a violation shows once on its `use` line and once per reference.
- This version of the rule was run against `develop`. Every file it reported there (53 lines) is one that Tasks 3–6 delete, move or rewrite. So after Task 6 it reports nothing.
- `NodeFinder` is injected, not defaulted. PHPStan's Nette container refuses a class-typed parameter it cannot autowire, even when the parameter has a default (checked: `Service of type PhpParser\NodeFinder … not found`). So the neon file registers it.

- [ ] **Step 1: Remove the legacy arm and delete the base class.**

In `src/Http/Problem/ProblemCatalog.php`, delete this import:

```php
use App\Exception\ApiException;
```

In `mapped()`:

```php
        return $exception instanceof ApiException ? self::legacy($exception) : null;
```
→
```php
        return null;
```

and delete the whole method (its state since 5d):

```php
    private static function legacy(ApiException $exception): ResolvedProblem
    {
        return new ResolvedProblem(new ApiProblem(
            $exception->type,
            $exception->title,
            $exception->status,
            $exception->detail,
            $exception->errors,
        ));
    }
```

```bash
git rm src/Exception/ApiException.php
grep -rnw "ApiException" src tests
```

Expected grep output: nothing. `ApiExceptionListener` is a different word.

Run: `php bin/phpunit tests/Http/Problem`
Expected: PASS (`OK`). `ProblemContractTest` runs 68 tests.

- [ ] **Step 2: Write the rule's test and fixture (red).**

Create `tests/PhpStan/data/domain-knows-no-http-fixtures.php`. The line numbers matter: the test expects errors on lines 10, 16, 27, 28, 32, 34 and 46.

```php
<?php

declare(strict_types=1);

// Fixtures for DomainKnowsNoHttpRuleTest, excluded from `composer stan` like everything in this directory.
/** @noinspection PhpIllegalPsrClassPathInspection */

namespace App\Service\Fixtures {
    use Symfony\Component\HttpFoundation\Response;
    use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

    final class ThrowsHttp
    {
        public function fail(): never
        {
            throw new NotFoundHttpException();
        }

        public function statusText(): string
        {
            return Response::$statusTexts[404];
        }
    }
}

namespace App\Service\Fixtures\Exception {
    use App\Http\Problem\ApiProblem;
    use Symfony\Component\HttpFoundation\Response;

    final class KnowsItsStatus extends \RuntimeException
    {
        public const int STATUS = Response::HTTP_CONFLICT;

        public function problem(): ?ApiProblem
        {
            return null;
        }
    }
}

namespace App\Repository\Fixtures {
    final class ThrowsInlineHttp
    {
        public function fail(): never
        {
            throw new \Symfony\Component\HttpKernel\Exception\BadRequestHttpException();
        }
    }
}

namespace App\Http\Fixtures {
    use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

    final class HttpLayer
    {
        public function fail(): never
        {
            throw new NotFoundHttpException();
        }
    }
}
```

Create `tests/PhpStan/DomainKnowsNoHttpRuleTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\PhpStan;

use PhpParser\NodeFinder;
use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;

/** @extends RuleTestCase<DomainKnowsNoHttpRule> */
final class DomainKnowsNoHttpRuleTest extends RuleTestCase
{
    private const string SERVICE = 'App\Service\Fixtures';
    private const string SERVICE_EXCEPTION = 'App\Service\Fixtures\Exception';
    private const string REPOSITORY = 'App\Repository\Fixtures';
    private const string HTTP_EXCEPTION = 'Symfony\Component\HttpKernel\Exception\\';
    private const string RESPONSE = 'Symfony\Component\HttpFoundation\Response';
    private const string API_PROBLEM = 'App\Http\Problem\ApiProblem';

    protected function getRule(): Rule
    {
        return new DomainKnowsNoHttpRule(new NodeFinder());
    }

    public function testItReportsHttpInDomainCodeButNotInTheHttpLayer(): void
    {
        $this->analyse(
            [__DIR__ . '/data/domain-knows-no-http-fixtures.php'],
            [
                [self::message(self::SERVICE, self::HTTP_EXCEPTION . 'NotFoundHttpException'), 10],
                [self::message(self::SERVICE, self::HTTP_EXCEPTION . 'NotFoundHttpException'), 16],
                [self::message(self::SERVICE_EXCEPTION, self::API_PROBLEM), 27],
                [self::message(self::SERVICE_EXCEPTION, self::RESPONSE), 28],
                [self::message(self::SERVICE_EXCEPTION, self::RESPONSE), 32],
                [self::message(self::SERVICE_EXCEPTION, self::API_PROBLEM), 34],
                [self::message(self::REPOSITORY, self::HTTP_EXCEPTION . 'BadRequestHttpException'), 46],
            ],
        );
    }

    private static function message(string $namespaceName, string $reference): string
    {
        return sprintf(
            'Domain code must not know HTTP: %s references %s. '
            . 'Throw a typed exception and map it in src/Http/Problem (#1160).',
            $namespaceName,
            $reference,
        );
    }
}
```

Run: `php bin/phpunit tests/PhpStan/DomainKnowsNoHttpRuleTest.php`
Expected: FAIL. The test errors with `Class "App\Tests\PhpStan\DomainKnowsNoHttpRule" not found`.

- [ ] **Step 3: Write the rule and register it.**

Create `tests/PhpStan/DomainKnowsNoHttpRule.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\PhpStan;

use PhpParser\Node;
use PhpParser\Node\Name;
use PhpParser\Node\Stmt\Namespace_;
use PhpParser\NodeFinder;
use PHPStan\Analyser\Scope;
use PHPStan\Node\FileNode;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * Domain code throws typed exceptions and src/Http/Problem maps them (#1160). Response and App\Http are forbidden
 * only in exception namespaces until #1158 removes the presentation imports from services.
 *
 * @implements Rule<FileNode>
 */
final readonly class DomainKnowsNoHttpRule implements Rule
{
    private const array DOMAIN_NAMESPACES = [
        'App\\Service\\',
        'App\\Repository\\',
        'App\\Entity\\',
        'App\\Enum\\',
        'App\\Exception\\',
    ];

    private const string HTTP_EXCEPTIONS = 'Symfony\\Component\\HttpKernel\\Exception\\';
    private const string RESPONSE = 'Symfony\\Component\\HttpFoundation\\Response';
    private const string HTTP_LAYER = 'App\\Http\\';

    public function __construct(private NodeFinder $finder)
    {
    }

    public function getNodeType(): string
    {
        return FileNode::class;
    }

    public function processNode(Node $node, Scope $scope): array
    {
        $errors = [];
        foreach ($this->finder->findInstanceOf($node->getNodes(), Namespace_::class) as $namespace) {
            $errors = [...$errors, ...$this->errorsIn($namespace)];
        }

        return $errors;
    }

    /** @return list<IdentifierRuleError> */
    private function errorsIn(Namespace_ $namespace): array
    {
        $namespaceName = $namespace->name?->toString() ?? '';
        if (!self::isDomain($namespaceName)) {
            return [];
        }

        $errors = [];
        foreach ($this->finder->findInstanceOf($namespace->stmts, Name::class) as $reference) {
            if (self::isForbidden($reference->toString(), $namespaceName)) {
                $errors[] = self::error($namespaceName, $reference);
            }
        }

        return $errors;
    }

    private static function isDomain(string $namespaceName): bool
    {
        foreach (self::DOMAIN_NAMESPACES as $root) {
            if (str_starts_with($namespaceName . '\\', $root)) {
                return true;
            }
        }

        return false;
    }

    private static function isForbidden(string $reference, string $namespaceName): bool
    {
        if (str_starts_with($reference, self::HTTP_EXCEPTIONS)) {
            return true;
        }

        return self::isExceptionNamespace($namespaceName)
            && (self::RESPONSE === $reference || str_starts_with($reference, self::HTTP_LAYER));
    }

    private static function isExceptionNamespace(string $namespaceName): bool
    {
        return \in_array('Exception', explode('\\', $namespaceName), true);
    }

    private static function error(string $namespaceName, Name $reference): IdentifierRuleError
    {
        return RuleErrorBuilder::message(sprintf(
            'Domain code must not know HTTP: %s references %s. '
            . 'Throw a typed exception and map it in src/Http/Problem (#1160).',
            $namespaceName,
            $reference->toString(),
        ))
            ->identifier('simpleFeedReader.domainKnowsNoHttp')
            ->line($reference->getStartLine())
            ->build();
    }
}
```

In `phpstan.dist.neon`:

```neon
    -
        class: App\Tests\PhpStan\ThinControllerRule
        tags:
            - phpstan.rules.rule
```
→
```neon
    -
        class: App\Tests\PhpStan\ThinControllerRule
        tags:
            - phpstan.rules.rule
    -
        class: PhpParser\NodeFinder
    -
        class: App\Tests\PhpStan\DomainKnowsNoHttpRule
        tags:
            - phpstan.rules.rule
```

Run: `php bin/phpunit tests/PhpStan/DomainKnowsNoHttpRuleTest.php`
Expected: `OK (1 test, 1 assertion)`.

Run: `bin/console cache:warmup --env=dev && composer stan`
Expected: `[OK] No errors` across `src` and `tests`.

- [ ] **Step 4: Break it once.**

Add this as the first line of the body of `ExactSetGuard::assertPermutation()` in `src/Service/Reader/ExactSetGuard.php`:

```php
        throw new \Symfony\Component\HttpKernel\Exception\NotFoundHttpException();
```

Run: `composer stan`
Expected: FAIL, with the message `Domain code must not know HTTP: App\Service\Reader references Symfony\Component\HttpKernel\Exception\NotFoundHttpException. Throw a typed exception and map it in src/Http/Problem (#1160).` on that line. PHPStan may also report unreachable code after it.

Delete the line by hand. Do not use `git checkout --`. Run `composer stan` again. Expected: `[OK] No errors`.

- [ ] **Step 5: Update the docs.**

`docs/architecture.md:60`:

```markdown
| Errors are **`application/problem+json` regardless of `Accept`**; no `text/html` fallback | `App\EventListener\ApiExceptionListener`, `JwtFailureResponseListener` | A native client parses one content type for every outcome. |
```
→
```markdown
| Errors are **`application/problem+json` regardless of `Accept`**; no `text/html` fallback | `App\Http\Problem\ProblemCatalog` (one mapping for every error path), used by `App\EventListener\ApiExceptionListener`, `JwtFailureResponseListener` and `App\Security\LoginFailureHandler` | A native client parses one content type for every outcome. |
```

`CLAUDE.md` (repo root):

```markdown
- **Errors are exceptions**, typed and namespaced next to their service
  (`Service/*/Exception/`). Never signal failure with `null` or a magic value.
```
→
```markdown
- **Errors are exceptions**, typed and namespaced next to their service
  (`Service/*/Exception/`). Never signal failure with `null` or a magic value.
  Map a new one to HTTP by adding an arm to its module's `src/Http/Problem/*Problems`
  mapper; domain code never imports HTTP classes (`DomainKnowsNoHttpRule`).
```

- [ ] **Step 6: Run every gate.**

From `backend/`:

```bash
bin/console cache:warmup --env=dev
composer check
composer md
php bin/phpunit
composer infection:diff
```

Expected:
- `composer check` runs cs, stan and tramp, and all are clean. For a tramp failure with no cause in this diff, check `composer show larspohlmann/phptramp` first (CLAUDE.md).
- `composer md` prints no finding.
- `php bin/phpunit` reports `OK`.
- `composer infection:diff` reports an MSI of at least `minMsi` (80), and the run passes.

From the repo root, and only from the checkout that ran `docker compose up`:

```bash
docker compose exec php composer test
```

Expected: `OK` on MySQL.

Then:
- Run PhpStorm `lint_files` on every PHP file the branch changed (`git diff --name-only develop -- '*.php'`). Expected: no ERROR and no WARNING.
- Scan today's dev log for errors: `ls -t var/log/dev-*.log | head -1 | xargs tail -n 200 | jq -c 'select(.level_name=="ERROR" or .level_name=="CRITICAL")'`. Expected: nothing new from this branch.

- [ ] **Step 7: Commit.**

```bash
git status
git add -A src/Exception src/Http/Problem/ProblemCatalog.php tests/PhpStan phpstan.dist.neon
git add ../docs/architecture.md ../CLAUDE.md
git commit -m "refactor(#1160): delete ApiException; PHPStan keeps HTTP out of domain code"
```

- [ ] **Step 8: No #1158 comment.** The planner carries the rule-widening into the #1158 plan; nothing to do here.

---

## Finishing

1. **Run the SDD final whole-branch review.** It is not optional. Ask the reviewer to attack three things:
   - **Did any response that `ProblemContractTest` does not cover change shape?** Diff every `'type' =>` expectation in `tests/` against `develop`: `git diff develop -- tests | grep -n "'type'"`.
   - **Can a stolen JWT for a suspended user leak `accountStatus`?** `ProblemCatalog::resolve()` must still answer `AuthenticationException` before the mappers, and `JwtAccessTest::testSuspendedTokenDoesNotLeakAccountStatus` must pass.
   - **Did any mapper start echoing a message that the old code hid?** Check the OAuth `previous` and `logDetail`, the backup driver errors, `SecretUnreadableException`'s cipher messages, and the catalog and comments messages. Only `RecordNotFoundException` and `InvalidSelectionException` may newly show theirs.
2. **Run `/simplify`** over the branch diff.
3. **Open the PR against `develop`.** Its body is:

   ```markdown
   Closes #1160

   Domain failures now reach HTTP by one route: plain exceptions in `Service/*/Exception`, one
   `src/Http/Problem/*Problems` mapper per module, `ProblemCatalog` and `ProblemResponseFactory`, shared
   by `ApiExceptionListener`, `LoginFailureHandler` and `JwtFailureResponseListener`. `ApiException`,
   the eight `*ApiException` twins and every controller catch-and-rethrow are gone.
   `DomainKnowsNoHttpRule` keeps Symfony HTTP exceptions out of domain code.

   Deliberate contract change: 404s from `RecordNotFoundException` ("User not found.", "No such tag.", …)
   and 422s from `InvalidSelectionException` ("subscriptionIds must all be your feeds, without duplicates.",
   …) now carry `detail`. `type`, `title` and `status` are unchanged.

   A login lockout's `Retry-After` now follows the remaining lockout that Symfony reports; a fresh lockout
   is still 900.
   ```

4. **Merge only when the owner says so.** Do not merge because CI is green. When merging, arm a Monitor on `gh pr checks` instead of `gh pr merge --auto`, because `--auto` merges immediately on this repository. After the merge, confirm that #1160 closed; do not close it by hand.
