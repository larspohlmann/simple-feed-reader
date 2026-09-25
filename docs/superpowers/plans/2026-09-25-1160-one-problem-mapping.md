# One Domain-Failure → problem+json Mapping (#1160) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Domain failures reach HTTP by exactly one route: plain typed exceptions → per-module problem mappers in `src/Http/Problem/` → one response factory. The three competing routes (HTTP-carrying `ApiException`s, Symfony HTTP exceptions thrown from services, controller catch-and-rethrow twins) are gone.

**Architecture:** `App\Exception\ApiException` is deleted. Every exception that used to extend it becomes a plain `\RuntimeException` (or `\DomainException`) subclass with no HTTP knowledge. A tagged interface `ExceptionProblems` has one implementation per module (`PasskeyProblems`, `AiProblems`, …), each a `match (true)` of `instanceof` arms that builds a `ResolvedProblem`. `ProblemCatalog` asks the mappers, then falls back to the existing Symfony-HTTP / security / opaque-500 handling moved out of `ApiExceptionListener`. `ProblemResponseFactory` turns a `ResolvedProblem` into the `JsonResponse`, and `ApiExceptionListener`, `LoginFailureHandler` and `JwtFailureResponseListener` all use it.

**Tech Stack:** Symfony 7.4, PHP 8.4, PHPUnit 12, PHPStan max with the custom rules in `backend/tests/PhpStan/`.

**Spec:** GitHub issue #1160 (`gh issue view 1160`). Related issues: #1165 builds on this mapping, #1157 moves `TagNameTakenException` into a tag service later. Don't do either here.

## Global Constraints

- **The wire contract does not change.** Every `type`, `status` and `title` stays byte-identical, and so do `detail`, the `errors`/`accountStatus`/`invalidatedPasskeyCount` members and the `Retry-After` header. The only exceptions are the deliberate changes listed in Task 6. The Angular client and a future iOS client switch on `type`.
- **The mappers are the only code that knows HTTP.** Exceptions under `src/Service`, `src/Repository`, `src/Entity` and `src/Enum` must not import `Symfony\Component\HttpFoundation\Response`, any `Symfony\Component\HttpKernel\Exception\*`, or anything under `App\Http\*`.
- **Where exceptions live:** follow CLAUDE.md, next to their service in `Service/<Module>/Exception/`. Only three stay in `src/Exception/`, because they are cross-cutting or HTTP-layer: `ValidationException`, `InvalidSelectionException` (new) and `InvalidCredentialsException`. `TagNameTakenException` also stays in `src/Exception/` for now, because #1157 creates its service.
- **Keep the safety invariant of the old `ApiException` docblock.** A mapper never copies `getPrevious()` or an unexpected message into the problem document. It uses `getMessage()` as `detail` only for exceptions whose message is authored text that is safe to show a client (the table in Task 1 says which).
- The CLAUDE.md Clean Code rules apply to every file you touch:
  - `final readonly` where possible.
  - No boolean flag parameters.
  - Comments are a single line and only where genuinely needed. Delete any comment that narrates the old twin mechanism.
  - Each touched `src` file must pass PHPMD.
- Gates before the PR:
  - `composer check`, `composer md` and `php bin/phpunit` (SQLite).
  - `docker compose exec php composer test` (MySQL).
  - `composer infection:diff`.
  - PhpStorm `lint_files` on changed PHP.
- Commit format: `refactor(#1160): …`, `test(#1160): …`. Branch: `refactor/1160-one-problem-mapping` off `develop`. Before creating it, check `git status`, because other sessions share this checkout.

---

## File Structure

| Path | Responsibility |
|---|---|
| `src/Http/Problem/ApiProblem.php` | Moved from `src/Http/ApiProblem.php`. Same class, new namespace `App\Http\Problem`. |
| `src/Http/Problem/ResolvedProblem.php` | Moved from `src/Http/ResolvedProblem.php`. |
| `src/Http/Problem/ExceptionProblems.php` | The interface, carrying `#[AutoconfigureTag('app.exception_problems')]`. |
| `src/Http/Problem/ProblemCatalog.php` | Resolves any `\Throwable` to a `ResolvedProblem` (the mappers, then the fallbacks). |
| `src/Http/Problem/ProblemResponseFactory.php` | `ResolvedProblem` → `JsonResponse` (merges the problem+json header). |
| `src/Http/Problem/*Problems.php` | One mapper per module (listed in Tasks 3–6). |
| `src/EventListener/ApiExceptionListener.php` | Reduced to a path check, then catalog, then factory. |
| `src/Security/LoginFailureHandler.php`, `src/EventListener/JwtFailureResponseListener.php` | Use the catalog and the factory. |
| `tests/Http/Problem/ProblemContractTest.php` | The characterization table (Task 1) that pins the wire contract through every step. |
| `tests/PhpStan/DomainKnowsNoHttpRule.php` (+ test) | The guard added in Task 7. |

---

### Task 1: Pin the wire contract before touching anything

**Files:**
- Create: `backend/tests/Http/Problem/ProblemContractTest.php`

**Interfaces:**
- Produces: a data-provider test that later tasks keep green. When a task turns an exception into a plain class, it swaps only that row's instance.

The test builds an `ExceptionEvent` for `/api/x` exactly as `tests/EventListener/ApiExceptionListenerTest.php::event()` does. It resolves the event through the container's `ApiExceptionListener` (a `KernelTestCase` with `static::getContainer()->get(ApiExceptionListener::class)`), not through `new`, because later tasks give the listener dependencies. It then asserts the status code, the `Content-Type`, the full decoded body (`assertSame` on the whole array) and `Retry-After` where the row expects it.

- [ ] **Step 1: Write one data-provider row per current contract.** The table has one row for each deliberate failure below, and the expected body is the literal array:

| Exception today (constructor) | type | status | title | detail |
|---|---|---|---|---|
| `RelyingPartyChangeRequiresConfirmationException(3)` | relying_party_change_requires_confirmation | 409 | Relying party change requires confirmation | `Changing the passkey relying party id invalidates 3 enrolled passkey(s). Resend the request with invalidateExistingPasskeys set to confirm.` + `invalidatedPasskeyCount: 3` |
| `UnknownPasskeyCredentialException()` | unknown_passkey_credential | 401 | Unknown passkey | This passkey is not registered here. |
| `UnknownChallengeException()` | unknown_passkey_challenge | 400 | Unknown or expired passkey challenge | — |
| `PasskeySignInDisabledException()` | passkey_sign_in_disabled | 403 | Passkey sign-in is disabled | This instance has turned off passkey sign-in. |
| `PasskeyNotFoundException()` | passkey_not_found | 404 | No such passkey | — |
| `LastSignInMethodException()` | passkey_last_sign_in_method | 409 | Cannot remove your last sign-in method | This is your only way to sign in. Set a password or link a sign-in provider first. |
| `DuplicatePasskeyException()` | passkey_already_registered | 409 | Passkey already registered | This passkey is already registered. |
| `PasskeyChallengeOwnershipException()` | passkey_challenge_owner_mismatch | 403 | Forbidden | This registration challenge was not issued to you. |
| `AttestationRejectedException(new \RuntimeException('secret'))` | passkey_attestation_rejected | 400 | Passkey registration rejected | The passkey could not be verified. |
| `AssertionRejectedException()` | passkey_assertion_rejected | 401 | Passkey login rejected | The passkey could not be verified. |
| `IncompleteMailConfigurationException::<its named ctor>` | incomplete_mail_configuration | 422 | Incomplete mail configuration | (its message) |
| `BackupDoesNotFitException('d')` | backup_does_not_fit | 409 | The backup does not fit this account | d |
| `InvalidBackupException('d')` | invalid_backup | 422 | Invalid backup file | d |
| `BackupLoadFailedException::<named ctor>` | backup_load_failed | 422 | The backup could not be loaded | (its detail) |
| `RateLimitedException(120)` | rate_limited | 429 | Too many requests | Too many attempts. Try again later. + header `Retry-After: 120` |
| `InvalidCredentialsException()` | invalid_credentials | 401 | Invalid credentials | Email address or password is incorrect. |
| `InvalidTokenException()` | invalid_token | 400 | Invalid token | This link is invalid, already used, or expired. |
| `InvalidSetupSecretException()` | invalid_setup_secret | 403 | Forbidden | The setup secret is incorrect. |
| `SetupUnavailableException()` | setup_unavailable | 404 | Not found | Setup is not available. |
| `AccountNotActiveException('suspended')` | account_not_active | 403 | Account not active | This account has been suspended. + `accountStatus: suspended` (add one row per status, plus the default arm) |
| `InvalidOpmlException('d')` / `InvalidOpmlException()` | invalid_opml | 422 | The OPML document could not be parsed | d / — |
| `LastAdminException()` | last_admin | 409 | Last administrator | This is the only administrator account. Promote another account first. |
| `SubscriptionLimitReachedException(7)` | subscription_limit_reached | 409 | Subscription limit reached | You can subscribe to at most 7 feeds. |
| `AlreadySubscribedException('d')` / `()` | already_subscribed | 409 | Already subscribed to that feed | d / — |
| `TagNameTakenException('d')` / `()` | tag_name_taken | 409 | Tag name already in use | d / — |
| `ValidationException(['f' => ['m']])` | validation_error | 422 | Validation failed | One or more fields are invalid. + `errors` |
| `UnknownProviderException()` | unknown_provider | 404 | Unknown sign-in provider | That sign-in provider is not available. |
| `OAuthFailedException(<its ctor>)` | oauth_failed | 502 | Sign-in failed | Signing in with that provider did not work. Please try again. |
| `AiNotConfiguredException` (domain) | ai_not_configured | 404 | No AI provider is configured | Save an endpoint and an API key first. |
| `ConfigurationNotFoundException` (domain) | ai_configuration_not_found | 404 | AI configuration not found | No such AI configuration for this account. |
| `TooManyConfigurationsException` (domain) | ai_configuration_limit | 409 | Too many AI configurations | This account already holds the maximum number of AI configurations. |
| `SecretUnreadableException` (domain) | ai_key_unreadable | 422 | The stored API key could not be read | The stored API key can no longer be read. Enter it again. |
| `ProviderUnreachableException('m')`, `CredentialsRejectedException('m')`, `ModelNotOfferedException('m')`, `ModelRequiredForActivationException('m')` (domain) | ai_provider_rejected | 422 | The AI provider could not be used | m |
| `NoActiveRecommendationRunException` (domain) | no_active_recommendation_run | 409 | No recommendation run is active | There is nothing to stop: the run already finished. |
| `NoResumableRecommendationRunException` (domain) | no_resumable_recommendation_run | 409 | No recommendation run to resume | There is no failed run to resume; start a new one instead. |
| `RecommendationRunActiveException` (domain) | recommendation_run_active | 409 | A recommendation run is still active | Wait for the current run to finish, then try again. |
| `ScrapingDisabledException('m')` (domain) | scraping_disabled | 403 | Website scraping is disabled | m |
| `FeedPreviewException('m')` (domain) | feed_preview_failed | 422 | Feed preview failed | m |
| `InvalidCatalogDocumentException('m')` (domain) | request_error | 422 | Unprocessable Content | — |
| `NoCommentsFeedException('m')` (domain) | not_found | 404 | Not Found | — |

- The rows whose first column says "(domain)" are exceptions that today only reach HTTP through a controller catch-and-rethrow.
- For those rows, mark the test `#[Group('pending-1160')]` and skip it, with `self::markTestSkipped('mapped in Task 3/4')`, until the task that maps them.
- Read each domain exception's real constructor before writing its row. Where a constructor differs from the table, the constructor wins.
- Also check the two `ModelNotOffered…` messages that `AiSettingsController:287-293` catches. If that catch maps anything the table misses, add a row for it.

- [ ] **Step 2: Add the fallback rows**, so moving that logic cannot drift:
  - `new NotFoundHttpException()` → `{type:not_found,title:Not Found,status:404}`.
  - `new HttpException(422, previous: new ValidationFailedException(...))` → `validation_error` with per-field `errors`.
  - `new BadCredentialsException()` → `unauthorized` 401 with its detail.
  - `new AccessDeniedException()` (Security) → `forbidden` 403.
  - `new \LogicException('db password')` → `internal_error` 500, with **no** `detail` and without the string anywhere in the body.
  - `new TooManyRequestsHttpException(60)` → header `Retry-After: 60` is kept.
- [ ] **Step 3: Run it.** `php bin/phpunit tests/Http/Problem/ProblemContractTest.php`. Expected: every non-skipped row PASSES on untouched code. Any failure means the row is wrong, not the code. Fix the row.
- [ ] **Step 4: Break it.** Change one `title` in `LastAdminException` and watch that row go red, then revert by hand. Don't use `git checkout --`.
- [ ] **Step 5: Commit** `test(#1160): pin the problem+json wire contract`.

---

### Task 2: Catalog, factory and mapper interface; the listener, login handler and JWT listener delegate

**Files:**
- Move: `src/Http/ApiProblem.php` → `src/Http/Problem/ApiProblem.php`, and `src/Http/ResolvedProblem.php` → `src/Http/Problem/ResolvedProblem.php` (update every `use`).
- Create: `src/Http/Problem/ExceptionProblems.php`, `ProblemCatalog.php`, `ProblemResponseFactory.php`.
- Modify: `src/EventListener/ApiExceptionListener.php`, `src/Security/LoginFailureHandler.php`, `src/EventListener/JwtFailureResponseListener.php`.
- Test: `tests/Http/Problem/ProblemCatalogTest.php`, `tests/Http/Problem/ProblemResponseFactoryTest.php`. Move the relevant cases out of `tests/EventListener/ApiExceptionListenerTest.php` and keep that file for the path filter only.

**Interfaces:**
- Produces:

```php
namespace App\Http\Problem;

#[AutoconfigureTag('app.exception_problems')]
interface ExceptionProblems
{
    /** Null when the exception belongs to another module's mapper. */
    public function resolve(\Throwable $exception): ?ResolvedProblem;
}

final readonly class ProblemCatalog
{
    /** @param iterable<ExceptionProblems> $mappers */
    public function __construct(
        #[AutowireIterator('app.exception_problems')] private iterable $mappers,
        private LoggerInterface $logger,
        #[Autowire('%kernel.debug%')] private bool $debug,
    ) {}

    public function resolve(\Throwable $exception, string $path): ResolvedProblem;
}

final readonly class ProblemResponseFactory
{
    public function create(ResolvedProblem $resolved): JsonResponse;
}
```

- [ ] **Step 1: Write the failing tests.**
  - `ProblemCatalogTest`: a stub mapper that returns a problem for `\DomainException` wins over the fallbacks. Two stubs that both return null fall through to the 500. The ordering among mappers is irrelevant, because each exception class is owned by exactly one mapper; assert that with a stub that returns non-null only for its own class.
  - `ProblemResponseFactoryTest`: port the array_merge header case, so that an `HttpException` carrying `Content-Type: text/html` still yields `application/problem+json`, and pass-through `WWW-Authenticate` survives.
- [ ] **Step 2: Run the tests.** Expected: they FAIL, class not found.
- [ ] **Step 3: Implement.**
  - `ProblemCatalog::resolve()` first iterates the mappers and returns the first non-null result.
  - It then runs, in the same order and with the same bodies, the `HttpExceptionInterface`, `AuthenticationException`, `AccessDeniedException` and opaque-500 branches from today's listener (`resolve()` plus `fromHttpException()`), with the 500 logging included.
  - While migration is in flight, keep a temporary `ApiException` arm directly after the mappers. Copy it verbatim from `resolveApiException()`, together with its RateLimited, AccountNotActive and RelyingParty extension handling. Task 7 deletes it.
  - `ProblemResponseFactory::create()` holds the `array_merge` response construction now in `ApiExceptionListener::onKernelException`.
  - The listener becomes: path guard, then `$event->setResponse($this->responses->create($this->problems->resolve($event->getThrowable(), $path)))`.
- [ ] **Step 4: Switch `JwtFailureResponseListener`** to `$event->setResponse($this->responses->create($this->problems->resolve($event->getException(), '/api')))`.
  - This is only safe if no mapper ever claims an `AuthenticationException` subclass. A suspended user's stolen token must keep getting the opaque 401.
  - `AccountStatusException` is an `AuthenticationException`. Assert this invariant in `ProblemCatalogTest`: resolving `new App\Security\AccountStatusException(...)` must return `unauthorized` with no `accountStatus`.
  - Then find the existing JWT test for suspended tokens (`tests/Controller/Api/JwtAccessTest.php`) and confirm it still passes.
- [ ] **Step 5: Switch `LoginFailureHandler`.**
  - Keep the `match` that picks the domain exception.
  - Replace `new RateLimitedException(900)` with the lockout that Symfony reports. `LoginThrottlingListener` throws `TooManyLoginAttemptsAuthenticationException(ceil(minutes))`, so read `$exception->getMessageData()['%minutes%']`. A null threshold, which only a manual throw can produce, falls back to 60 seconds.
  - Build the response through `$this->responses->create($this->problems->resolve($domainException, $request->getPathInfo()))`.
  - Delete its hand-built payload, its `accountStatus` handling and its `Retry-After` handling. The catalog now supplies all three.
  - Add or adjust a test that throttles a login past `max_attempts: 5`. Assert that `Retry-After` is between 1 and 900, and that it is no longer the constant 900: freeze no clock, just assert `<= 900` and `> 0`.
  - Search `tests/` for an existing throttling test first.
- [ ] **Step 6: Run the tests.** Run `ProblemContractTest`, the listener tests, `tests/Security`, `tests/Controller/Api/JwtAccessTest.php`, the passkey login tests and `composer stan`. Expected: all PASS.
- [ ] **Step 7: Commit** `refactor(#1160): one problem catalog and response factory for every error path`.

---

### Task 3: Delete the AI and recommendation twins; map the domain exceptions

**Files:**
- Create:
  - `src/Http/Problem/AiProblems.php`, with arms for AiNotConfigured, ConfigurationNotFound, TooManyConfigurations, SecretUnreadable, and the provider group (ProviderUnreachable, CredentialsRejected, ModelNotOffered, ModelRequiredForActivation).
  - `src/Http/Problem/RecommendationRunProblems.php`, with arms for NoActive, NoResumable and RunActive.
- Delete: `src/Exception/AiNotConfiguredApiException.php`, `AiConfigurationNotFoundApiException.php`, `TooManyAiConfigurationsApiException.php`, `AiKeyUnreadableApiException.php`, `AiProviderApiException.php`, `NoActiveRecommendationRunApiException.php`, `NoResumableRecommendationRunApiException.php`, `RecommendationRunActiveApiException.php`.
- Modify: `src/Controller/Api/AiSettingsController.php` and `src/Controller/Api/RecommendationRunController.php`. Remove every catch that only rethrows a twin; the `try` goes with it where nothing else is caught.

- [ ] **Step 1: Un-skip the AI and recommendation rows in `ProblemContractTest`.** Run them. Expected: FAIL with a 500, because nothing maps them yet.
- [ ] **Step 2: Write the mappers.** The shape:

```php
final readonly class RecommendationRunProblems implements ExceptionProblems
{
    public function resolve(\Throwable $exception): ?ResolvedProblem
    {
        $problem = match (true) {
            $exception instanceof NoActiveRecommendationRunException => new ApiProblem(
                'no_active_recommendation_run',
                'No recommendation run is active',
                Response::HTTP_CONFLICT,
                'There is nothing to stop: the run already finished.',
            ),
            // … one arm per exception, values from the Task 1 table
            default => null,
        };

        return null === $problem ? null : new ResolvedProblem($problem);
    }
}
```

   For the provider group, `detail` is `$exception->getMessage()`, which is exactly what the twin received. Check each of those four domain exceptions to confirm its message is the authored client text; the twin already exposed it, so it is.
- [ ] **Step 3: Delete the twins and the controller catches.** Then run `grep -rn "ApiException" src/Controller`. Expected: no twin names remain.
- [ ] **Step 4: Run** `ProblemContractTest`, `tests/Controller/Api/AiSettingsControllerTest.php`, `RecommendationRunControllerTest.php` and `composer stan`. Expected: all PASS.
- [ ] **Step 5: Commit** `refactor(#1160): map AI and recommendation-run failures without twin exceptions`.

---

### Task 4: The remaining catch-and-rethrow sites

**Files:**
- Create: `src/Http/Problem/DiscoveryProblems.php` (ScrapingDisabled, FeedPreview), `CatalogProblems.php` (InvalidCatalogDocument), `CommentsProblems.php` (NoCommentsFeed).
- Delete: `src/Exception/ScrapingDisabledApiException.php`, `src/Exception/FeedPreviewApiException.php`. Also delete `src/Exception/FeedPreviewException.php` if it turns out to be a stray duplicate of the service one; check its users first.
- Modify:
  - `Controller/Api/SubscriptionController.php:96` and `FeedPreviewController.php:41-50`.
  - `Controller/Admin/AdminCatalogImportController.php:66,81`. Keep the `:43` catch: it returns `available:false`, which is a real alternative answer, not a rethrow.
  - `Controller/Api/EntryCommentsController.php:40`.
- Leave `Controller/Api/OAuthController.php:187` alone. It logs and redirects a browser, which is the flow's contract.

- [ ] **Step 1: Un-skip the rows.** Expected: FAIL.
- [ ] **Step 2: Write the mappers.**
  - The detail rules follow the table. `InvalidCatalogDocumentException` and `NoCommentsFeedException` currently reach the client through a bare Symfony HTTP exception, which carries **no** detail. Keep it that way: `new ApiProblem('request_error', 'Unprocessable Content', 422)` and `new ApiProblem('not_found', 'Not Found', 404)`.
  - Use `Response::$statusTexts[...]` for those two titles, so they match `fromHttpException()` exactly.
- [ ] **Step 3: Remove the catches.** Run the controller tests for those four controllers. Expected: PASS.
- [ ] **Step 4: Commit** `refactor(#1160): drop the remaining catch-and-rethrow sites`.

---

### Task 5: Make the module exceptions plain and move them home

For every exception listed below, do the same four things:
- Make it `extends \RuntimeException`, or `\DomainException` where that already reads better.
- Give it a constructor that sets only a message and keeps any payload field it has (`accountStatus`, `invalidatedPasskeyCount`, `retryAfterSeconds`, `limit`).
- Delete its `Response` import.
- Add its arm to a module mapper.

Move each file with `git mv` so its history follows, and update every `use`. `composer stan` finds the stragglers.

| Exceptions | Destination namespace | Mapper |
|---|---|---|
| The 10 `Service/Passkey/Exception/*` | stay | `PasskeyRegistrationProblems` (Attestation, Duplicate, ChallengeOwnership, UnknownChallenge, NotFound, LastSignInMethod) + `PasskeySignInProblems` (Assertion, UnknownCredential, SignInDisabled). The split keeps each `match` under PHPMD's complexity limit. |
| `Service/Backup/Exception/*` (3) | stay | `BackupProblems` |
| `Service/Mail/Settings/Exception/IncompleteMailConfigurationException` | stay | `MailProblems` |
| `Service/Settings/Exception/RelyingPartyChangeRequiresConfirmationException` | stay | `SettingsProblems`, with extension `invalidatedPasskeyCount` |
| `Exception/LastAdminException` | `Service/Account/Exception/` | `AccountProblems` |
| `Exception/AlreadySubscribedException`, `SubscriptionLimitReachedException` | `Service/Subscription/Exception/` | `SubscriptionProblems` |
| `Exception/InvalidSetupSecretException`, `SetupUnavailableException`, `InvalidTokenException`, `AccountNotActiveException` | `Service/Auth/Exception/` | `AuthProblems`, with extension `accountStatus`. The `match` over the status that builds the detail moves into the mapper. |
| `Exception/RateLimitedException` | `Service/RateLimit/Exception/` | `RateLimitProblems`, with header `Retry-After` |
| `Exception/InvalidOpmlException` | `Service/Opml/Exception/` | `OpmlProblems` |
| `Exception/OAuth/*` (OAuthException, OAuthFailedException, UnknownProviderException) | `Service/OAuth/Exception/` | `OAuthProblems` |
| `Exception/InvalidCredentialsException`, `ValidationException`, `TagNameTakenException` | stay in `src/Exception/` | `RequestProblems` (Validation, with extension `errors` via `ApiProblem::$errors`), `AuthProblems` (InvalidCredentials), `TagProblems` (TagNameTaken) |

- **`AccountNotActiveException`:** the status-to-message `match` becomes mapper logic. The exception keeps only `public readonly string $accountStatus`.
- **`RateLimitedException`:** keeps `retryAfterSeconds`.
- **`Passkey/AssertionVerifier.php`** matched the grep for `extends ApiException`. Find out why: it may type-hint or construct one. Adjust it.
- **The detail rules for each moved exception:** where the old `ApiException` passed a constructor argument through as `detail`, make that argument the exception's message, and have the mapper use `getMessage()`. Where the old detail was fixed text, put the text in the mapper. Where the old detail was absent or null, the mapper passes `null`. For example, `new AlreadySubscribedException()` today has no detail. Keep it that way: map `'' === $exception->getMessage() ? null : $exception->getMessage()`, and do the same for `TagNameTaken` and `InvalidOpml`.

- [ ] **Step 1: Do it one module per commit**, in this order: Passkey, Backup+Mail+Settings, Account+Subscription, Auth+RateLimit, Opml+OAuth, Request+Tag.
  - Each commit keeps `ProblemContractTest` and that module's controller tests green. Swap the contract row to the new namespace as you go.
  - Run `composer stan` and `composer md` on the touched files before each commit.
- [ ] **Step 2: Confirm nothing extends the old base.** After the last module, `grep -rn "extends ApiException\|extends OAuthException" src` should report only whatever `OAuthFailedException` and `UnknownProviderException` extend now, which is the plain `OAuthException`. `grep -rn "ApiException" src` should print only the temporary arm in `ProblemCatalog`.
- [ ] **Step 3: Commit** each module as `refactor(#1160): plain <module> exceptions, mapped at the edge`.

---

### Task 6: No Symfony HTTP exceptions under `src/Service` or `src/Repository`

**Files:**
- Create: `src/Repository/Exception/EntityNotFoundException.php` (plain, message is authored). Create `src/Exception/InvalidSelectionException.php` (plain; "the ids you sent are not all yours / contradict each other").
- Modify:
  - `Repository/UserRepository.php:36`, `CatalogFeedRepository.php:29` and `CatalogCategoryRepository.php:29` throw `EntityNotFoundException`.
  - `Service/Reader/MarkReadService.php:117,127` throws `EntityNotFoundException`.
  - `Service/Reader/ExactSetGuard.php:31`, `Service/Subscription/BulkSubscriptionUpdater.php:72,90`, `OwnedSubscriptions.php:66` and `FeedTagMove.php:70` throw `InvalidSelectionException($message)`.
  - `Service/Reader/MarkReadService.php:89` throws `new ValidationException(['scope' => [sprintf('Unknown scope "%s".', $scope)]])`. This is a **deliberate contract change** from 400 `request_error` to 422 `validation_error`, which makes it match the adjacent id-required checks in the same method. The frontend never sends an unknown scope; `grep -rn "request_error" ../frontend/src` came back empty.
  - Mappers: `RequestProblems` gains `EntityNotFoundException` → `not_found`, `Not Found`, 404, detail `getMessage()`. The old `NotFoundHttpException` detail was dropped, and now becomes visible. It is authored text such as "No such tag.", which is an improvement allowed here. It also gains `InvalidSelectionException` → `request_error`, `Unprocessable Content`, 422, detail `getMessage()`, which is also newly visible.
  - Drop the now-stale docblock on `UserRepository::getById`.
- Test: update the existing tests that assert these responses; `grep -rn "No such\|must all be your\|Unknown scope" tests`. Add one contract row for each of the new exceptions.

- [ ] **Step 1: Write the rows and the updated assertions first.** Expected: FAIL.
- [ ] **Step 2: Implement.** Expected: PASS.
- [ ] **Step 3: Confirm the HTTP exceptions are gone.** `grep -rn "HttpKernel\\\\Exception" src/Service src/Repository src/Entity src/Enum` should come back empty.
- [ ] **Step 4: Commit** `refactor(#1160): services and repositories throw domain exceptions, not HTTP ones`.

---

### Task 7: Delete `ApiException`; guard the boundary

**Files:**
- Delete: `src/Exception/ApiException.php`. Also delete the temporary arm in `ProblemCatalog`.
- Create: `tests/PhpStan/DomainKnowsNoHttpRule.php`, `tests/PhpStan/DomainKnowsNoHttpRuleTest.php` and fixtures under `tests/PhpStan/data/`. Model all three on `ThinControllerRule`/`ThinControllerRuleTest`.
- Modify: `phpstan.dist.neon`. Register the rule the way `ThinControllerRule` is registered, around lines 24–28.

The rule inspects `Node\Stmt\Use_` and `Node\Name` usages in files whose namespace starts with `App\Service`, `App\Repository`, `App\Entity` or `App\Enum`. It reports any reference to:
- `Symfony\Component\HttpKernel\Exception\*`
- `Symfony\Component\HttpFoundation\Response`
- `App\Http\*`

Message: `Domain code must not know HTTP; throw a typed exception and map it in src/Http/Problem (#1160).`

Before writing it, check what `src/Service` legitimately imports from `App\Http` today, with `grep -rn "use App\\\\Http" src/Service src/Repository`. #1158 exists to remove those presentation leaks. If there are hits, do not fix them here and do not add an allow-list. Instead, narrow the rule to the two Symfony namespaces, and leave a one-line note in #1158 (`gh issue comment 1158`) saying the rule should widen to `App\Http\*` once that issue lands.

- [ ] **Step 1: Write the rule test** with one fixture that violates each forbidden namespace and one clean fixture. Expected: FAIL.
- [ ] **Step 2: Implement the rule and register it.** Run `composer stan`. Expected: clean across `src` and `tests`.
- [ ] **Step 3: Break it.** Add `throw new NotFoundHttpException()` to one service, watch `composer stan` go red, then remove it by hand.
- [ ] **Step 4: Update the docs.**
  - `docs/architecture.md:60` names the components. Add `App\Http\Problem\ProblemCatalog`.
  - Add one sentence to the CLAUDE.md "Errors are exceptions" bullet: *"Map a new one to HTTP by adding an arm to its module's `src/Http/Problem/*Problems` mapper; domain code never imports HTTP classes (`DomainKnowsNoHttpRule`)."*
- [ ] **Step 5: Run the full gates** listed in Global Constraints, both database legs. Then scan today's dev log for errors (`ls -t var/log/dev-*.log | head -1 | xargs tail -n 200 | jq -c 'select(.level_name=="ERROR" or .level_name=="CRITICAL")'`).
- [ ] **Step 6: Commit** `refactor(#1160): delete ApiException; PHPStan keeps HTTP out of domain code`.

---

## Finishing

1. Run the SDD final whole-branch review. It is not optional. Ask the reviewer to attack three things:
   - Does any response that `ProblemContractTest` does not cover change shape? Diff `git grep -n "'type'" tests/` expectations against develop.
   - Can a stolen JWT for a suspended user now leak `accountStatus`?
   - Did any mapper start echoing an exception message that the old code hid? This covers the OAuth `previous` messages and backup driver errors.
2. Run `/simplify` over the branch diff.
3. Open the PR against `develop`, with body `Closes #1160`. The body lists the two deliberate contract changes from Task 6: `EntityNotFound`/`InvalidSelection` now carry `detail`, and an unknown mark-read scope now returns 422 `validation_error`.
4. Merge when CI is green. Arm a Monitor polling `gh pr checks`, because `--auto` merges immediately on this repository. After the merge, confirm #1160 closed.
