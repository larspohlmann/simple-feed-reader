# Typed Failures Instead of Null and Magic Values (#1165) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Every site that #1165 lists stops reporting a failure or an outcome through `null`, a `bool` or a string literal. Each one uses an enum, a typed exception, or `requireId()` instead. The wire contract stays byte-identical.

**Architecture:** The work ships as two PRs.
- **PR A (`refactor/1165-typed-failures`)** handles the behavioural sites: outcome enums, the token `consume()` family, the cursors, the swallowed errors, the untyped throws, and the one #1160 follow-up (a required OPML problem detail). Most of these stay inside a single module.
- **PR B (`refactor/1165-require-id`)** is the mechanical sweep. A `PersistedId` trait gives persisted entities `requireId(): int`, 130 id-coercion sites move to it, and a PHPStan rule keeps new ones out.

There are two reasons for the split:
- PR B touches about 60 files, including nearly every controller. It conflicts with every open branch, so it should land as one quick, low-risk merge instead of waiting behind a long behavioural review.
- The two PRs need different kinds of review. PR A needs someone who reasons about semantics. PR B needs someone who checks that nothing relied on a silent `0`.

**Tech Stack:** Symfony 7.4, PHP 8.4, Doctrine ORM 3.6 with `enable_native_lazy_objects: true`, PHPUnit 12, PHPStan max with the custom rules in `backend/tests/PhpStan/`, and Infection.

**Spec:** GitHub issue #1165 (`gh issue view 1165`). **The plan builds on #1160**, merged into `develop` as `c43e21b5` (PR #1176). The "Dependencies on #1160" section below records the merged shape this plan relies on.

## Global Constraints

- **The wire contract does not change.** The following all stay byte-identical:
  - Every problem `type`, `status`, `title` and `detail`.
  - The `scrapeFailureReason` values.
  - The mail-test `{ok, reason}` and proxy-test `{ok, egressIp, reason}` bodies.
  - The OAuth `invalid_state` redirect code.
  - The 422 cursor message.
  - The `invalid_opml` body for every message the code throws today.
- **Every exception that can reach HTTP needs an arm in its module's `src/Http/Problem/*Problems.php` mapper.** In this plan no new exception needs one. Each is either caught before the edge or is a programmer error that belongs in the opaque 500 (see **Exceptions introduced**). No mapper file changes. The executor confirms this per class in the steps that say so.
- **Exceptions live next to their service** in `Service/<Module>/Exception/`. They are plain `\RuntimeException`, `\LogicException` or `\InvalidArgumentException` subclasses with no HTTP knowledge.
- **Clean Code (CLAUDE.md):**
  - Use `final readonly` where the class allows it.
  - No boolean flag parameters.
  - Use guard clauses.
  - Comments are **one line at most**, and appear only where a future reader would otherwise get the code wrong. The after-code in each step already shows the reduced comments. Don't reintroduce the removed ones.
- **Every touched `src` file must be PHPMD-clean.** `composer md` was clean on `develop` @ `cbf7e656` (verified 2026-09-25). Run it once on a fresh branch off `c43e21b5` before Task 1; any finding after that is new.
- **Gates**, before each PR:
  - `composer check` (cs, stan, tramp) and `composer md`.
  - `php bin/phpunit` (SQLite).
  - `docker compose exec php composer test` (MySQL).
  - `composer infection:diff`.
  - PhpStorm `lint_files` on the changed PHP.
  - Today's dev log: `ls -t var/log/dev-*.log | head -1 | xargs tail -n 200 | jq -c 'select(.level_name=="ERROR" or .level_name=="CRITICAL")'`.
- **Commits:** `refactor(#1165): …` and `test(#1165): …`.
- **Branches:**
  - PR A: `refactor/1165-typed-failures` off `develop` at or after `c43e21b5`. Run `git fetch origin develop` first and branch from `origin/develop` if the local `develop` is behind.
  - PR B: `refactor/1165-require-id` off `develop`, created after PR A merges.
  - Run `git status` before any branch or checkout. Other sessions share this checkout.
- **After a break-test, restore the code by hand.** Never use `git checkout --`.
- All paths and commands below are relative to `backend/`.

## Dependencies on #1160 (checked against the merge, `c43e21b5`)

| # | What this plan relies on | Where it lives on `develop` | Status after the merge | Used by |
|---|---|---|---|---|
| D1 | `InvalidTokenException` is plain, lives at `App\Service\Auth\Exception\InvalidTokenException`, and has no constructor of its own. `AuthProblems` maps it to `invalid_token` / 400 / `Invalid token` / "This link is invalid, already used, or expired." | `src/Service/Auth/Exception/InvalidTokenException.php`, `src/Http/Problem/AuthProblems.php` | Holds | Task 2 |
| D2 | `App\Service\OAuth\Exception\` exists. `OAuthException` is its abstract base, and `OAuthProblems` matches the **concrete classes** `UnknownProviderException` and `OAuthFailedException`, never `instanceof OAuthException`. | `src/Service/OAuth/Exception/`, `src/Http/Problem/OAuthProblems.php` | Holds | Task 3 |
| D3 | `ValidationException` stays at `App\Exception\ValidationException` with the constructor `__construct(array $errors)`. `RequestProblems` maps it to 422 `validation_error` with the fixed detail "One or more fields are invalid." and `errors` from the exception. | `src/Exception/ValidationException.php`, `src/Http/Problem/RequestProblems.php` | **Changed, no plan impact.** The constructor now also sets the parent message "One or more fields are invalid." Task 11 builds it the same way, and the wire detail still comes from the mapper. | Task 11 |
| D4 | `DomainKnowsNoHttpRule` lets `App\Service\Recommendation\RecommendationFeedPager` import `App\Http\Exception\MalformedCursorException`, and lets the new exceptions live where the plan puts them. | `tests/PhpStan/DomainKnowsNoHttpRule.php` | **Changed shape, still holds.** The rule covers the namespaces `App\Service`, `App\Repository`, `App\Entity`, `App\Enum` and `App\Exception`. In all of them it forbids `Symfony\Component\HttpKernel\Exception\*`. `Symfony\Component\HttpFoundation\Response` and `App\Http\*` are forbidden only in a namespace with an `Exception` segment. `RecommendationFeedPager` is not in one. None of the new `…\Exception\` classes references `App\Http` or `Response`. | Task 11 |
| D5 | An exception that no mapper claims reaches `ProblemCatalog::unexpected()`. That logs `Unhandled API exception` at error level and answers 500 `internal_error` / `Internal server error`, with the message as detail only when `kernel.debug` is on. Every mapper matches concrete classes. None matches `\RuntimeException`, `\LogicException` or `\InvalidArgumentException`. | `src/Http/Problem/ProblemCatalog.php`, `src/Http/Problem/*Problems.php` | Holds | Tasks 12, 15 |
| D6 | `ProblemContractTest` pins every problem body, including `invalid opml` with `new InvalidOpmlException('OPML has no <body>.')`. This plan adds no rows to it. | `tests/Http/Problem/ProblemContractTest.php` | Holds | Task 14, finishing review |
| D7 | #1160 only touched the files this plan edits in ways that change imports or shift lines. | See the next column | **Changed.** `AuthController`, `OAuthController` and `OAuthSignIn` got 1:1 import swaps, so their lines did not move. Four files moved: `SubscriptionController` lost two imports and the `ScrapingDisabledException` rethrow in `create` (−2 above `create`, −10 below it; Task 6, Appendix A), `EntryCommentsController` lost an import and a `try`/`catch` (its site moved −1; Appendix A), `MarkReadService` (−1) and `OwnedSubscriptions` (−9; Appendix B). `AiProviderConfigurator::credentials()` was rewritten to catch `SecretUnreadableException`, so its `identify()` call site now reads differently and the helper moved +3 (Task 15, Appendix C). The steps and appendices below quote `c43e21b5`. The draft's wrong ranges are also fixed: `AuthController` 91–103/130–137, `OAuthController` 165–183, `OAuthSignIn` 94–100 and `RecommendationRunAdvancer` 539–543. | Tasks 2, 3, 6, 15, 16, 17 |

## Items verified against `develop` @ `c43e21b5` (file:line)

| Issue item | Verified at | Decision |
|---|---|---|
| `SendDueDigests::attemptSend(): ?bool`, `sendAndAdvance(): ?bool` | `Service/Mail/Digest/SendDueDigests.php:75,103` | Enum `DigestAttempt` (Task 1) |
| `ActionTokenService::consume` returns null twice | `Service/Auth/ActionTokenService.php:66-83` | Throws `InvalidTokenException` (Task 2) |
| `RegistrationService::verifyEmail` forwards the null; `resetPassword(): bool` | `Service/Auth/RegistrationService.php:115-119, 203-208` | Becomes `UserStatus` / `void` (Task 2) |
| `AuthController` turns null into an exception | `Controller/Api/AuthController.php:95-97, 132-134` | The checks are deleted (Task 2) |
| `LoginCodeStore::consume(): ?int` | `Service/OAuth/LoginCodeStore.php:121` (null at 126, 143, 154, 162) | Throws `InvalidTokenException` (Task 2) |
| `OAuthStateStore::consume(): ?OAuthStartState`, plus `decodeStored(): ?array` | `Service/OAuth/OAuthStateStore.php:134` (null at 139, 148, 158, 165), `:193` | Throws `InvalidOAuthStateException`; the controller catches it (Task 3) |
| `FailoverRequestSender`: `?ResponseInterface` | `Service/Fetch/FailoverRequestSender.php:94-127` (null at 112, 123) | Internal `ProxiedAttemptFailedException` (Task 4) |
| `EntryPruner::deletableIdsPastBoundary(…, ?\DateTimeImmutable $cutoff)` | `Service/Retention/EntryPruner.php:167`, called at 91 and 121 | Split into two methods (Task 5) |
| `ScrapeFailureReason` string alias; `?string` in `SubscribeOutcome` | `Service/Discovery/FeedDiscoveryResult.php:8,22,50`; `Service/Subscription/SubscribeOutcome.php:28,48`; producers in `FeedDiscovery.php:64,69,94,144,149,180`; wire at `SubscriptionController.php:102-103` | Backed enum (Task 6) |
| `SchemaOrgAccess::paywalledIn(): ?bool` | `Service/Reader/Paywall/SchemaOrgAccess.php:18`; consumer `PaywallSignals.php:21` | Enum `AccessDeclaration` (Task 7) |
| `LokiSinkFactory::selects()` returns strings | `Service/Logging/Loki/LokiSinkFactory.php:21,26-33` | Enum `LokiDelivery` (Task 8) |
| `LokiSpoolShipper`: `catch (\Throwable)` returns false | `Service/Logging/Loki/LokiSpoolShipper.php:51-64` | Catches only `CorruptSpoolFileException` (Task 8) |
| `MailTestResult::failed()` mixes codes and messages | `Service/Mail/Settings/MailTestResult.php:20`; producers `MailConnectionTester.php:44,51,60,83` | Enum `MailTestFailure` plus `detail` (Task 9); `ProxyTestResult` has the same smell, fixed in the same task (Lars's ruling) |
| `CheckCatalogUrlsCommand::check()` returns `?string` and swallows `\Throwable` | `Command/CheckCatalogUrlsCommand.php:107-134` | `BrokenCatalogUrlException`; the catch narrows to HttpClient `ExceptionInterface` (Task 10) |
| `EntryCursor::decode()` / `RecommendationCursor::decode()` return null | `Http/EntryCursor.php:66-88`, `Http/RecommendationCursor.php:28-45`; lenient caller `Service/Recommendation/RecommendationFeedPager.php:20-29` | `MalformedCursorException`; the pager catches it explicitly (Task 11) |
| `GzipLineReader:48`, `BackupPart:31` throw `\RuntimeException` | same lines | `BackupCompressionException` (Task 12) |
| `RefreshRunner:332` throws `FeedParseException` for a fetch-contract breach | `Service/Refresh/RefreshRunner.php:328-333` | `FetchResponse::modifiedBody()` (Task 13) |
| A bare `new InvalidOpmlException()` would send `"detail": ""` (a #1160 follow-up, not in the issue text) | `Service/Opml/Exception/InvalidOpmlException.php` inherits `\RuntimeException`'s optional `$message = ""`; `Http/Problem/OpmlProblems.php:19` passes `getMessage()` through unchanged. No thrower sends an empty message today (`OpmlController.php:45`, `OpmlBodyReader.php:32,37`). | Required `non-empty-string` message (Task 14) |
| Nullable ids, "24 sites" | **117** `(int) $x->getId()`, **5** `getId() ?? 0`, **7** `getId() ?? throw new \LogicException`, **1** `\assert(null !== $userId)` | `PersistedId::requireId()` (Tasks 15–18) |
| "`RecommendationPromptBuilder:133,491`" | `$candidate->entryId ?? 0` and `$line->entryId ?? 0` on `PromptLine::$entryId`, which is **not** `getId()` | Non-null `PromptLine::$entryId` (Task 19) |

**Dropped or corrected:**
- **Nothing was dropped as a legitimate "nothing found" lookup.** Every listed site encodes a failure or a closed set of outcomes. The nulls that remain are genuine nothing-found values, kept on purpose:
  - The cache and repository lookups before the throw.
  - `FailoverRequestSender::resolveProxy(): ?ProxyConfig` (no proxy is configured).
  - `EntryPruner::rankBoundaryBeyond(): ?EntryRankBoundary` (fewer entries than `keep`).
  - `SubscribeOutcome::$scrapeFailureReason` (null when candidates were found).
  - `EntryCursor::fromRequestValue(): ?self` (null when no cursor was sent).
  - `SchemaOrgAccess::asBoolean(): ?bool` (a private extraction heuristic).
- **The count is wrong.** There are 117 `(int)` casts, not 24, plus 13 other forms.
- **The `?? throw` count is wrong.** It is 7 sites, not 5. `OAuthSignIn:71` adds an `\assert`.
- **Two cited sites are not id coercions.** `RecommendationPromptBuilder:133,491` come from `PromptLine::$entryId` being nullable. Task 19 fixes that type.

## Exceptions introduced

| Class | Namespace | Extends | Reaches HTTP? | Mapper arm |
|---|---|---|---|---|
| `InvalidOAuthStateException` | `App\Service\OAuth\Exception` | `\RuntimeException` | No. `OAuthController::callback` catches it and redirects with `invalid_state`. | None. It must not extend `OAuthException` (D2). |
| `ProxiedAttemptFailedException` | `App\Service\Fetch\Exception` | `\RuntimeException`, **not** `FetchException` | No. `FailoverRequestSender::send` catches it. | None |
| `CorruptSpoolFileException` | `App\Service\Logging\Loki\Exception` | `\RuntimeException` | No. `LokiSpoolShipper::ship` catches it. | None |
| `BrokenCatalogUrlException` | `App\Service\Catalog\Exception` | `\RuntimeException` | No. Console only. | None |
| `MalformedCursorException` | `App\Http\Exception` | `\InvalidArgumentException` | No. Translated to `ValidationException` (D3) or caught by the pager. | None |
| `BackupCompressionException` | `App\Service\Backup\Exception` | `\RuntimeException` | Yes, as the opaque 500. It is a server fault, as today (D5). | None, deliberately |
| `UnpersistedEntityException` | `App\Entity\Exception` | `\LogicException` | Yes, as the opaque 500. It is a programmer error (D5). | None, deliberately |

The token `consume()` family reuses the existing `InvalidTokenException` (D1):
- After #1160 it is already the plain "one answer for every failure mode" exception.
- `OAuthSignIn` already throws it for login codes.
- `AuthProblems` already maps it to `invalid_token`.

A new `InvalidActionTokenException` would need an identical mapper arm and would mean the same thing. Lars ruled: reuse it.

`InvalidOpmlException` is not new. Task 14 gives it a required message, and its `OpmlProblems` arm stays as it is.

---

## File Structure

| Path | Responsibility |
|---|---|
| `src/Service/Mail/Digest/DigestAttempt.php` | One case per digest-sweep outcome for one account |
| `src/Service/OAuth/Exception/InvalidOAuthStateException.php` | Any refused OAuth state, as one class |
| `src/Service/Fetch/Exception/ProxiedAttemptFailedException.php` | A proxied attempt that failed in a way a direct route may still serve |
| `src/Service/Discovery/ScrapeFailureReason.php` | Backed enum whose values are the wire strings |
| `src/Service/Reader/Paywall/AccessDeclaration.php` | `Paywalled` / `Free` / `Undeclared` |
| `src/Service/Logging/Loki/LokiDelivery.php` | `Direct` / `Spool` |
| `src/Service/Logging/Loki/Exception/CorruptSpoolFileException.php` | A spool file that cannot be read or decoded |
| `src/Service/Mail/Settings/MailTestFailure.php` | Backed enum whose values are the existing wire codes (internal only) |
| `src/Service/Proxy/ProxyTestFailure.php` | Same, for the proxy connection test (internal only) |
| `src/Service/Catalog/Exception/BrokenCatalogUrlException.php` | A catalog URL that no longer serves a feed |
| `src/Http/Exception/MalformedCursorException.php` | A cursor that cannot be decoded |
| `src/Service/Backup/Exception/BackupCompressionException.php` | The server could not gzip or inflate backup bytes |
| `src/Entity/PersistedId.php` | Trait providing `requireId(): int` |
| `src/Entity/Exception/UnpersistedEntityException.php` | `requireId()` on an entity that was never flushed |
| `tests/PhpStan/EntityIdCoercionRule.php` (+ test, + fixture) | Forbids `(int) $entity->getId()` and `$entity->getId() ?? …` |
| `tests/Service/Opml/Exception/InvalidOpmlExceptionTest.php` | Pins that `InvalidOpmlException` cannot be built without a message |

---

# PR A — `refactor/1165-typed-failures`

All tasks in PR A are independent of each other, with one exception: Tasks 6 and 13 both edit `Service/Discovery/FeedDiscovery.php`, so run Task 6 first. Each task leaves the full suite green.

### Task 1: The digest sweep reports its outcome as an enum

**Files:**
- Create: `src/Service/Mail/Digest/DigestAttempt.php`
- Modify: `src/Service/Mail/Digest/SendDueDigests.php:54-126`
- Test: `tests/Service/Mail/Digest/SendDueDigestsTest.php`. It already pins every branch through `run()`, at lines 67, 89, 107, 125, 143, 156 and 179, and stays unchanged.

- [ ] **Step 1: Confirm the characterization is green.**
  Run: `php bin/phpunit tests/Service/Mail/Digest/`
  Expected: PASS.
- [ ] **Step 2: Create the enum.**

```php
<?php

declare(strict_types=1);

namespace App\Service\Mail\Digest;

enum DigestAttempt
{
    case NotDue;
    case Ineligible;
    case NothingToReport;
    case SendFailed;
    case Sent;
}
```

- [ ] **Step 3: Rewrite `SendDueDigests` lines 54–126.**

Before (the `run()` loop):
```php
            $outcome = $this->attemptSend($prefs, $now);
            if (true === $outcome) {
                ++$sent;
            } elseif (false === $outcome) {
                ++$skippedEmpty;
            }
```
After:
```php
            $attempt = $this->attemptSend($prefs, $now);
            if (DigestAttempt::Sent === $attempt) {
                ++$sent;
            } elseif (DigestAttempt::NothingToReport === $attempt) {
                ++$skippedEmpty;
            }
```

After (replacing both private methods and their docblocks, lines 68–126):
```php
    private function attemptSend(Preferences $prefs, \DateTimeImmutable $now): DigestAttempt
    {
        $occurrence = $this->dueOccurrence($prefs, $now);
        if (null === $occurrence) {
            return DigestAttempt::NotDue;
        }

        $user = $prefs->getUser();
        if (!$user->isEmailVerified()) {
            return DigestAttempt::Ineligible;
        }

        $model = $this->composer->compose($user, $prefs->getDigestLastSentAt() ?? $occurrence);
        if (null === $model) {
            return DigestAttempt::NothingToReport;
        }

        return $this->sendAndAdvance($user, $model, $prefs, $occurrence);
    }

    /** One recipient's transport failure must not stop the sweep; the untouched watermark retries it next tick (#636). */
    private function sendAndAdvance(
        User $user,
        DigestModel $model,
        Preferences $prefs,
        \DateTimeImmutable $occurrence,
    ): DigestAttempt {
        try {
            $this->mailer->send($user, $model);
        } catch (TransportExceptionInterface $e) {
            $this->logger->error(
                'Digest send failed: {userId} <{email}>',
                ['userId' => $user->getId(), 'email' => $user->getEmail(), 'exception' => $e],
            );
            $this->health->recordFailure(MailKind::Digest, $user->getEmail(), $e->getMessage());

            return DigestAttempt::SendFailed;
        }

        $this->health->recordSuccess();
        $prefs->setDigestLastSentAt($occurrence);
        $this->em->flush();

        return DigestAttempt::Sent;
    }
```

`dueOccurrence(): ?\DateTimeImmutable` and `DigestComposer::compose(): ?DigestModel` stay as they are, because their null means "nothing due" or "nothing to report".
- [ ] **Step 4: Run** `php bin/phpunit tests/Service/Mail/Digest/ tests/Service/Worker/SendDueDigestsHandlerTest.php`, then `vendor/bin/phpstan analyse src/Service/Mail/Digest`. Expected: PASS.
- [ ] **Step 5: Commit.**
```bash
git add src/Service/Mail/Digest/DigestAttempt.php src/Service/Mail/Digest/SendDueDigests.php
git commit -m "refactor(#1165): digest sweep reports a DigestAttempt, not a ?bool"
```

---

### Task 2: Action tokens and login codes throw `InvalidTokenException`

**Files:**
- Modify:
  - `src/Service/Auth/ActionTokenService.php:61-83`
  - `src/Service/Auth/RegistrationService.php:105-120, 203-217`
  - `src/Controller/Api/AuthController.php:11, 91-103, 130-137`
  - `src/Service/OAuth/LoginCodeStore.php:109-165`
  - `src/Service/OAuth/OAuthSignIn.php:76-110`
- Test: `tests/Service/Auth/ActionTokenServiceTest.php`, `tests/Service/Auth/RegistrationServiceTest.php` and `tests/Service/OAuth/LoginCodeStoreTest.php`.
- These must stay green unchanged:
  - `tests/Service/OAuth/OAuthSignInTest.php`
  - `tests/Controller/Api/PasswordResetTest.php:261,277,292` and `tests/Controller/Api/RegistrationTest.php:450,459` (the `invalid_token` wire)
  - `tests/Controller/Api/AuthJourneyTest.php`
  - `tests/Controller/Api/OAuthFlowTest.php`

**Interfaces:**
- Consumes: `App\Service\Auth\Exception\InvalidTokenException` (D1).
- Produces:
  - `ActionTokenService::consume(string $plainToken, TokenPurpose $purpose): User`
  - `RegistrationService::verifyEmail(string $plainToken): UserStatus`
  - `RegistrationService::resetPassword(string $plainToken, string $plainPassword): void`
  - `LoginCodeStore::consume(string $code, ?string $browserToken): int`

  All four throw `InvalidTokenException`.

- [ ] **Step 1: Rewrite `ActionTokenServiceTest`.** Add `use App\Service\Auth\Exception\InvalidTokenException;` and this helper at the end of the class:

```php
    private function assertRefused(string $plainToken, TokenPurpose $purpose): void
    {
        try {
            $this->service->consume($plainToken, $purpose);
            self::fail('The token was redeemed.');
        } catch (InvalidTokenException) {
            $this->addToAssertionCount(1);
        }
    }
```

Replace the following lines:

| Line | Before | After |
|---|---|---|
| 59 | `self::assertSame($this->user->getId(), $consumed?->getId());` | `self::assertSame($this->user->getId(), $consumed->getId());` |
| 67 | `self::assertNull($this->service->consume($plain, TokenPurpose::VerifyEmail));` | `$this->assertRefused($plain, TokenPurpose::VerifyEmail);` |
| 74 | `self::assertNull($this->service->consume($plain, TokenPurpose::ResetPassword));` | `$this->assertRefused($plain, TokenPurpose::ResetPassword);` |
| 82 | `self::assertNull($this->service->consume($plain, TokenPurpose::VerifyEmail));` | `$this->assertRefused($plain, TokenPurpose::VerifyEmail);` |
| 87 | `self::assertNull($this->service->consume('not-a-real-token', TokenPurpose::VerifyEmail));` | `$this->assertRefused('not-a-real-token', TokenPurpose::VerifyEmail);` |
| 97 | `self::assertNull($this->service->consume($first, TokenPurpose::ResetPassword));` | `$this->assertRefused($first, TokenPurpose::ResetPassword);` |
| 98 | `self::assertNotNull($this->service->consume($second, TokenPurpose::ResetPassword));` | `self::assertSame($this->user->getId(), $this->service->consume($second, TokenPurpose::ResetPassword)->getId());` |
| 115 | `self::assertNull($this->service->consume($plain, TokenPurpose::VerifyEmail));` | `$this->assertRefused($plain, TokenPurpose::VerifyEmail);` |

- [ ] **Step 2: Rewrite `LoginCodeStoreTest`.** Add `use App\Service\Auth\Exception\InvalidTokenException;` and:

```php
    private function assertRefused(string $code, ?string $browserToken, string $whyItMatters = 'The code was redeemed.'): void
    {
        try {
            $this->store->consume($code, $browserToken);
            self::fail($whyItMatters);
        } catch (InvalidTokenException) {
            $this->addToAssertionCount(1);
        }
    }
```

| Line | Before | After |
|---|---|---|
| 42 | `self::assertNull($this->store->consume($code, self::TOKEN));` | `$this->assertRefused($code, self::TOKEN);` |
| 45 | `public function testAnUnknownCodeReturnsNull(): void` | `public function testAnUnknownCodeIsRefused(): void` |
| 47 | `self::assertNull($this->store->consume('not-a-code', self::TOKEN));` | `$this->assertRefused('not-a-code', self::TOKEN);` |
| 55 | `self::assertNull($this->store->consume($code, self::TOKEN));` | `$this->assertRefused($code, self::TOKEN);` |
| 91 | `self::assertNull($this->store->consume('some-other-code', self::TOKEN));` | `$this->assertRefused('some-other-code', self::TOKEN);` |
| 94–97 | `self::assertNull(`<br>`    $this->store->consume($code, self::TOKEN),`<br>`    'the code outlived T+30, so its deadline moved with the store rather than with its issue',`<br>`);` | `$this->assertRefused(`<br>`    $code,`<br>`    self::TOKEN,`<br>`    'the code outlived T+30, so its deadline moved with the store rather than with its issue',`<br>`);` |
| 117 | `self::assertNull($this->store->consume($code, 'a-different-browsers-token'));` | `$this->assertRefused($code, 'a-different-browsers-token');` |
| 129 | `self::assertNull($this->store->consume($code, null));` | `$this->assertRefused($code, null);` |
| 141 | `self::assertNull($this->store->consume($code, ''));` | `$this->assertRefused($code, '');` |
| 144 | `self::assertNull($this->store->consume($emptyBound, self::TOKEN));` | `$this->assertRefused($emptyBound, self::TOKEN);` |
| 156 | `self::assertNull($this->store->consume($code, 'wrong'));` | `$this->assertRefused($code, 'wrong');` |
| 157 | `self::assertNull($this->store->consume($code, self::TOKEN), 'the code survived a failed binding check');` | `$this->assertRefused($code, self::TOKEN, 'the code survived a failed binding check');` |

- [ ] **Step 3: Add two tests to `RegistrationServiceTest`.** Add `use App\Service\Auth\Exception\InvalidTokenException;`. Put the tests after `testVerifyEmailWithApprovalOffActivatesDirectlyWithoutEvent`:

```php
    public function testVerifyingWithAnUnknownTokenIsRefused(): void
    {
        $service = $this->serviceUnderPolicy($this->policy(confirm: true, approve: false));

        $this->expectException(InvalidTokenException::class);

        $service->verifyEmail('never-issued');
    }

    public function testResettingWithAnUnknownTokenIsRefused(): void
    {
        $service = $this->serviceUnderPolicy($this->policy(confirm: true, approve: false));

        $this->expectException(InvalidTokenException::class);

        $service->resetPassword('never-issued', 'correct-horse-battery');
    }
```

- [ ] **Step 4: Run the tests.**
  Run: `php bin/phpunit tests/Service/Auth tests/Service/OAuth/LoginCodeStoreTest.php`
  Expected: FAIL. `consume` returns null, so every `assertRefused` hits `self::fail`.
- [ ] **Step 5: Implement `ActionTokenService::consume`.** Replace lines 61–83 with:

```php
    /** Every failure mode is the same exception, so a guesser cannot tell which one it hit. */
    public function consume(string $plainToken, TokenPurpose $purpose): User
    {
        $token = $this->repository()->findOneByHashAndPurpose(hash('sha256', $plainToken), $purpose);
        $now = $this->clock->now();

        if (null === $token || null !== $token->getConsumedAt() || $token->isExpiredAt($now)) {
            throw new InvalidTokenException();
        }

        $token->setConsumedAt($now);
        $this->em->flush();

        return $token->getUser();
    }
```

  Add `use App\Service\Auth\Exception\InvalidTokenException;`.
- [ ] **Step 6: Implement `RegistrationService`.**

Before (lines 105–119):
```php
    /**
     * Returns the account's status *after* verification, so the caller reports
     * what is actually true, not what is usually true: an admin may have
     * approved the account between the mail being sent and the link being
     * clicked, in which case the user is already Active. A blanket
     * "pending_approval" would tell someone who can sign in right now to sit
     * and wait for an approval that already happened.
     *
     * @return UserStatus|null null when the token is unknown, used, or expired
     */
    public function verifyEmail(string $plainToken): ?UserStatus
    {
        $user = $this->tokens->consume($plainToken, TokenPurpose::VerifyEmail);
        if (null === $user) {
            return null;
        }
```
After:
```php
    /** The status after verification: an admin may have approved the account while the mail was in flight. */
    public function verifyEmail(string $plainToken): UserStatus
    {
        $user = $this->tokens->consume($plainToken, TokenPurpose::VerifyEmail);
```

Before (lines 203–217):
```php
    public function resetPassword(string $plainToken, string $plainPassword): bool
    {
        $user = $this->tokens->consume($plainToken, TokenPurpose::ResetPassword);
        if (null === $user) {
            return false;
        }

        // Stamping the change is what evicts tokens minted before it — see
        // App\Security\PasswordChangeTokenInvalidator. Without it this method
        // changes the password and leaves whoever stole a token still signed
        // in, which is the opposite of what a reset is for.
        $user->setPasswordHash($this->hasher->hashPassword($user, $plainPassword), $this->clock->now());
        $this->em->flush();

        return true;
    }
```
After:
```php
    public function resetPassword(string $plainToken, string $plainPassword): void
    {
        $user = $this->tokens->consume($plainToken, TokenPurpose::ResetPassword);

        // The timestamp evicts JWTs minted before the reset (PasswordChangeTokenInvalidator).
        $user->setPasswordHash($this->hasher->hashPassword($user, $plainPassword), $this->clock->now());
        $this->em->flush();
    }
```

- [ ] **Step 7: Implement `AuthController`.** Delete the import at line 11, `use App\Service\Auth\Exception\InvalidTokenException;`. After this step nothing in the controller names the class; `ValidationException` (line 12) stays, because `register()` and `passwordResetRequest()` still throw it.

Before (lines 91–103):
```php
    public function verifyEmail(#[MapRequestPayload] VerifyEmailRequest $request): JsonResponse
    {
        $status = $this->registration->verifyEmail($request->token);

        if (null === $status) {
            throw new InvalidTokenException();
        }

        // The real status, not a hardcoded one: an account approved between the
        // mail going out and the link being clicked is already active, and
        // telling that user to wait would be simply false.
        return new JsonResponse(['status' => $status->value]);
    }
```
After:
```php
    public function verifyEmail(#[MapRequestPayload] VerifyEmailRequest $request): JsonResponse
    {
        return new JsonResponse(['status' => $this->registration->verifyEmail($request->token)->value]);
    }
```

Before (lines 130–137):
```php
    public function passwordReset(#[MapRequestPayload] PasswordResetConfirmRequest $request): JsonResponse
    {
        if (!$this->registration->resetPassword($request->token, $request->password)) {
            throw new InvalidTokenException();
        }

        return new JsonResponse(['status' => 'reset']);
    }
```
After:
```php
    public function passwordReset(#[MapRequestPayload] PasswordResetConfirmRequest $request): JsonResponse
    {
        $this->registration->resetPassword($request->token, $request->password);

        return new JsonResponse(['status' => 'reset']);
    }
```

- [ ] **Step 8: Implement `LoginCodeStore::consume`.** Add `use App\Service\Auth\Exception\InvalidTokenException;`, then replace lines 109–165 (the docblock and the method):

```php
    /**
     * @param string|null $browserToken the flow cookie, or null when the exchange arrived without one, which fails
     *
     * @throws InvalidTokenException when the code is unknown, spent, expired, or presented by another browser
     * @throws InvalidArgumentException
     */
    public function consume(string $code, ?string $browserToken): int
    {
        $key = self::keyFor($code);
        $item = $this->loginCodeCache->getItem($key);

        if (!$item->isHit()) {
            throw new InvalidTokenException();
        }

        // Deleted before the checks below, so a failed check burns the code instead of leaving it to retry.
        $this->loginCodeCache->deleteItem($key);

        $stored = $item->get();
        if (
            !\is_array($stored)
            || !\is_int($stored['user_id'] ?? null)
            || !\is_string($stored['browser_digest'] ?? null)
            || !\is_int($stored['expires_at'] ?? null)
        ) {
            throw new InvalidTokenException();
        }

        // hash_equals: the stored digest is secret-derived, and a byte-wise compare leaks its prefix.
        if (null === $browserToken || !hash_equals($stored['browser_digest'], self::digest($browserToken))) {
            throw new InvalidTokenException();
        }

        // The pool's TTL runs on the cache backend's clock; this runs on the injected one.
        if ($stored['expires_at'] < $this->clock->now()->getTimestamp()) {
            throw new InvalidTokenException();
        }

        return $stored['user_id'];
    }
```

- [ ] **Step 9: Implement `OAuthSignIn::redeemLoginCode`.**

Before (lines 94–100):
```php
        $userId = $this->loginCodes->consume($code, $browserToken);

        if (null === $userId) {
            throw new InvalidTokenException();
        }

        $user = $this->users->find($userId);
```
After:
```php
        $user = $this->users->find($this->loginCodes->consume($code, $browserToken));
```

  The deleted-account `throw new InvalidTokenException()` that follows stays.

  Docblock before (lines 76–91):
```php
    /**
     * Leg two: spend the code and mint the JWT. The failures below are ONE
     * answer on purpose. An unknown code, an already-spent one, an expired
     * one, one presented by a browser that didn't complete the flow, and one
     * naming an account deleted since the callback are all
     * InvalidTokenException — telling them apart could confirm a captured
     * code was still live, or probe which accounts exist.
     *
     * @param string|null $browserToken null when the browser sent no binding
     *                                  cookie, which the store treats as a
     *                                  failure rather than as a reason to skip
     *                                  the check
     *
     * @return string the JWT for the signed-in user
     * @throws InvalidArgumentException
     */
```
  After:
```php
    /**
     * Leg two: spend the code and mint the JWT; every refusal, a since-deleted account included, is one answer.
     *
     * @return string the JWT for the signed-in user
     *
     * @throws InvalidTokenException
     * @throws InvalidArgumentException
     */
```
- [ ] **Step 10: Run the tests.**
  Run: `php bin/phpunit tests/Service/Auth tests/Service/OAuth tests/Controller/Api/PasswordResetTest.php tests/Controller/Api/RegistrationTest.php tests/Controller/Api/AuthJourneyTest.php tests/Controller/Api/OAuthFlowTest.php`
  Expected: PASS. The `invalid_token` bodies are unchanged.
- [ ] **Step 11: Break it.** In `ActionTokenService::consume`, change the first `||` to `&&`. Run `php bin/phpunit tests/Service/Auth/ActionTokenServiceTest.php` and watch `testConsumeRejectsGarbage` fail. Restore the line by hand.
- [ ] **Step 12: Commit.**
```bash
git add src/Service/Auth src/Controller/Api/AuthController.php src/Service/OAuth/LoginCodeStore.php src/Service/OAuth/OAuthSignIn.php tests/Service/Auth tests/Service/OAuth/LoginCodeStoreTest.php
git commit -m "refactor(#1165): token consume() throws InvalidTokenException instead of returning null"
```

---

### Task 3: A refused OAuth state is an exception, and the callback catches it

**Files:**
- Create: `src/Service/OAuth/Exception/InvalidOAuthStateException.php`
- Modify: `src/Service/OAuth/OAuthStateStore.php:122-205` and `src/Controller/Api/OAuthController.php:8, 165-183`
- Test: `tests/Service/OAuth/OAuthStateStoreTest.php`. `tests/Controller/Api/OAuthFlowTest.php` stays green unchanged: it pins the `invalid_state` redirect.

```php
<?php

declare(strict_types=1);

namespace App\Service\OAuth\Exception;

/** One class for every refusal (unknown, spent, expired, other browser), so the callback cannot leak which. */
final class InvalidOAuthStateException extends \RuntimeException
{
}
```

- [ ] **Step 1: Rewrite the tests.** Add `use App\Service\OAuth\Exception\InvalidOAuthStateException;` and:

```php
    private function assertRefused(string $state, ?string $browserToken): void
    {
        try {
            $this->store->consume($state, $browserToken);
            self::fail('The state was redeemed.');
        } catch (InvalidOAuthStateException) {
            $this->addToAssertionCount(1);
        }
    }
```

| Line | Before | After |
|---|---|---|
| 36 | `self::assertNotNull($consumed);` | delete the line |
| 43 | `self::assertNull($this->store->consume($started->state, $started->browserToken));` | `$this->assertRefused($started->state, $started->browserToken);` |
| 48 | `self::assertNull($this->store->consume('never-issued', 'irrelevant'));` | `$this->assertRefused('never-issued', 'irrelevant');` |
| 56 | `self::assertNull($this->store->consume($started->state, $started->browserToken));` | `$this->assertRefused($started->state, $started->browserToken);` |
| 73 | `self::assertNull($this->store->consume($started->state, null));` | `$this->assertRefused($started->state, null);` |
| 80 | `self::assertNull($this->store->consume($started->state, str_repeat('a', 64)));` | `$this->assertRefused($started->state, str_repeat('a', 64));` |
| 94 | `self::assertNull($this->store->consume($a->state, $b->browserToken));` | `$this->assertRefused($a->state, $b->browserToken);` |
| 109 | `self::assertNull($this->store->consume($started->state, 'wrong'));` | `$this->assertRefused($started->state, 'wrong');` |
| 112 | `self::assertNull($this->store->consume($started->state, $started->browserToken));` | `$this->assertRefused($started->state, $started->browserToken);` |
| 156 | `self::assertNull($this->store->consume('some-other-state', 'irrelevant'));` | `$this->assertRefused('some-other-state', 'irrelevant');` |
| 159 | `self::assertNull($this->store->consume($started->state, $started->browserToken));` | `$this->assertRefused($started->state, $started->browserToken);` |

- [ ] **Step 2: Run the tests.**
  Run: `php bin/phpunit tests/Service/OAuth/OAuthStateStoreTest.php`
  Expected: FAIL, because the class does not exist.
- [ ] **Step 3: Implement `OAuthStateStore`.** Add `use App\Service\OAuth\Exception\InvalidOAuthStateException;` and replace lines 122–205 (the `consume` docblock through the end of `decodeStored`):

```php
    /**
     * @param string|null $browserToken the flow cookie, or null when the callback arrived without one, which fails
     *
     * @throws InvalidOAuthStateException when the state is unknown, spent, expired, or presented by another browser
     * @throws InvalidArgumentException
     */
    public function consume(string $state, ?string $browserToken): OAuthStartState
    {
        $key = self::keyFor($state);
        $item = $this->oauthStateCache->getItem($key);

        if (!$item->isHit()) {
            throw new InvalidOAuthStateException();
        }

        // Deleted before validation, so a failed check burns the state instead of leaving it to retry.
        $this->oauthStateCache->deleteItem($key);

        $stored = self::decodeStored($item->get());

        // hash_equals: the stored digest is secret-derived, and a byte-wise compare leaks its prefix.
        if (null === $browserToken || !hash_equals($stored['browser_digest'], self::digest($browserToken))) {
            throw new InvalidOAuthStateException();
        }

        // The pool's TTL runs on the cache backend's clock; this runs on the injected one.
        if ($stored['expires_at'] < $this->clock->now()->getTimestamp()) {
            throw new InvalidOAuthStateException();
        }

        $codeVerifier = $stored['code_verifier'];

        return new OAuthStartState(
            $stored['provider'],
            $state,
            $stored['nonce'],
            $codeVerifier,
            self::challengeFor($codeVerifier),
        );
    }

    /**
     * @return array{provider: string, nonce: string, code_verifier: string, browser_digest: string, expires_at: int}
     *
     * @throws InvalidOAuthStateException when the entry is corrupt or tampered with
     */
    private static function decodeStored(mixed $stored): array
    {
        if (
            !\is_array($stored)
            || !\is_string($stored['provider'] ?? null)
            || !\is_string($stored['nonce'] ?? null)
            || !\is_string($stored['code_verifier'] ?? null)
            || !\is_string($stored['browser_digest'] ?? null)
            || !\is_int($stored['expires_at'] ?? null)
        ) {
            throw new InvalidOAuthStateException();
        }

        return [
            'provider' => $stored['provider'],
            'nonce' => $stored['nonce'],
            'code_verifier' => $stored['code_verifier'],
            'browser_digest' => $stored['browser_digest'],
            'expires_at' => $stored['expires_at'],
        ];
    }
```

- [ ] **Step 4: Implement `OAuthController::callback`.** Insert `use App\Service\OAuth\Exception\InvalidOAuthStateException;` directly above line 8, `use App\Service\OAuth\Exception\OAuthFailedException;`.

Before (lines 165–183):
```php
        $started = $this->stateStore->consume($state, $browserToken);

        // No valid state: not started by this server, already used, older than
        // ten minutes, or — the case state alone cannot catch — started by a
        // DIFFERENT BROWSER. All four are discarded without touching the provider.
        //
        // The fourth case is login CSRF, the reason the binding exists: an
        // attacker who obtains a state and code from their own account and gets a
        // victim to open this URL would otherwise sign the victim's browser in as
        // themselves, silently (the SPA exchanges with no user gesture). One code
        // for all four so a caller cannot probe for live states.
        //
        // The provider comparison stops a Google state replayed at Apple's
        // callback from spending a Google code against Apple's token endpoint, and
        // from letting the URL's chooser decide which provider is trusted.
        if (null === $started || $started->provider !== $provider) {
            return $this->oauthRedirect->failure('invalid_state');
        }
```
After:
```php
        try {
            $started = $this->stateStore->consume($state, $browserToken);
        } catch (InvalidOAuthStateException) {
            return $this->oauthRedirect->failure('invalid_state');
        }

        // A state replayed at another provider's callback is refused like a forged one.
        if ($started->provider !== $provider) {
            return $this->oauthRedirect->failure('invalid_state');
        }
```

  The login-CSRF reasoning stays in `OAuthStateStore`'s class docblock. The `\assert(null !== $browserToken)` further down stays too: it narrows a type for PHPStan and does not signal failure.
- [ ] **Step 5: Run the tests.**
  Run: `php bin/phpunit tests/Service/OAuth tests/Controller/Api/OAuthFlowTest.php`, then `vendor/bin/phpstan analyse src/Controller/Api/OAuthController.php src/Service/OAuth`
  Expected: PASS.
- [ ] **Step 6: Confirm the new exception is unmapped.**
  Run: `grep -n "instanceof OAuthException\b" src/Http/Problem/*.php`
  Expected: no output (D2). If there is output, stop and report it.
- [ ] **Step 7: Commit.**
```bash
git add src/Service/OAuth src/Controller/Api/OAuthController.php tests/Service/OAuth/OAuthStateStoreTest.php
git commit -m "refactor(#1165): OAuth state refusal is an exception the callback turns into invalid_state"
```

---

### Task 4: The proxied attempt falls back to direct without a null response

**Files:**
- Create: `src/Service/Fetch/Exception/ProxiedAttemptFailedException.php`
- Modify: `src/Service/Fetch/FailoverRequestSender.php:49-62, 86-127`
- Test: `tests/Service/Fetch/FailoverRequestSenderProxyTest.php` and `FailoverRequestSenderTest.php`. These are the characterization and stay unchanged:
  - fall-through: lines 43 and 70
  - terminal: lines 95 and 199
  - cancel: lines 139 and 164
  - unreadable password: line 226

```php
<?php

declare(strict_types=1);

namespace App\Service\Fetch\Exception;

/** The proxied attempt failed in a way a pinned direct route may still serve; never escapes FailoverRequestSender. */
final class ProxiedAttemptFailedException extends \RuntimeException
{
}
```

Do **not** make it extend `FetchException`. `FeedDiscovery` and `RefreshRunner` catch `FetchException`, so a leak would turn into a silent "unreachable".

- [ ] **Step 1: Run the characterization.**
  Run: `php bin/phpunit tests/Service/Fetch/`
  Expected: PASS.
- [ ] **Step 2: Implement.** Add `use App\Service\Fetch\Exception\ProxiedAttemptFailedException;`.

`send()`, replacing lines 49–62:
```php
    public function send(string $method, string $url, GuardedUrl $guarded, array $options): ResponseInterface
    {
        $proxy = $this->resolveProxy();
        if (null === $proxy) {
            return $this->sendPinnedFamilies($method, $url, $guarded, $options);
        }

        try {
            return $this->attemptProxied($method, $url, $options, $proxy);
        } catch (ProxiedAttemptFailedException) {
            return $this->sendPinnedFamilies($method, $url, $guarded, $options);
        }
    }
```

`attemptProxied()`, replacing lines 86–127:
```php
    /**
     * @param array<string, mixed> $options
     *
     * @throws ProxiedAttemptFailedException when direct fallback is on and warranted
     * @throws TransportExceptionInterface   when direct fallback is unavailable
     */
    private function attemptProxied(
        string $method,
        string $url,
        array $options,
        ProxyConfig $proxy,
    ): ResponseInterface {
        $response = $this->httpClient->request($method, $url, [...$options, ...EgressOptions::proxied($proxy)]);

        try {
            $status = $response->getStatusCode();
        } catch (TransportExceptionInterface $transportError) {
            $response->cancel();
            // With fallback off, going direct would leak the real server IP the proxy hides.
            if (!$proxy->directFallback || !CrossFamilyFailover::isWarranted($transportError)) {
                throw $transportError;
            }

            throw new ProxiedAttemptFailedException(previous: $transportError);
        }

        // A CDN/WAF refusal of the proxy's egress IP may still be served directly, but only with fallback on.
        if ($proxy->directFallback && CrossFamilyFailover::isRetryableStatus($status)) {
            $response->cancel();

            throw new ProxiedAttemptFailedException();
        }

        return $response;
    }
```

- [ ] **Step 3: Run the checks.**
  Run: `php bin/phpunit tests/Service/Fetch/`, then `composer tramp`
  Expected: PASS, with no new tramp chain.
- [ ] **Step 4: Commit.**
```bash
git add src/Service/Fetch
git commit -m "refactor(#1165): proxied-attempt fallback is an exception, not a null response"
```

---

### Task 5: `EntryPruner` gets one query per pass instead of a null-cutoff switch

**Files:**
- Modify: `src/Service/Retention/EntryPruner.php:84-126, 155-198`
- Test: `tests/Service/Retention/EntryPrunerTest.php`. This is the characterization and stays unchanged:
  - age pass: lines 114, 133, 202, 215 and 269
  - cap pass: lines 235, 253, 380, 413, 444, 475, 564 and 574

- [ ] **Step 1: Run the characterization on both databases.**
  Run: `php bin/phpunit tests/Service/Retention/EntryPrunerTest.php`, then `docker compose exec php composer test -- --filter=EntryPrunerTest`
  Expected: PASS on both.
- [ ] **Step 2: Implement.** Add `use Doctrine\ORM\QueryBuilder;`.

Before (line 91, in `pruneByAge`):
```php
                $this->deletableIdsPastBoundary((int) $feedId, self::MIN_ENTRIES_PER_FEED, $cutoff),
```
After:
```php
                $this->staleIdsPastBoundary((int) $feedId, $cutoff),
```

Before (line 121, in `pruneByFeedCap`):
```php
                $this->deletableIdsPastBoundary((int) $feedId, $cap, null),
```
After:
```php
                $this->idsPastBoundary((int) $feedId, $cap),
```

Replace lines 155–198 (the docblock and `deletableIdsPastBoundary`) with:
```php
    /** @return list<int> */
    private function idsPastBoundary(int $feedId, int $keep): array
    {
        $query = $this->deletablePastBoundary($feedId, $keep);

        return null === $query ? [] : self::idsOf($query);
    }

    /** @return list<int> */
    private function staleIdsPastBoundary(int $feedId, \DateTimeImmutable $cutoff): array
    {
        $query = $this->deletablePastBoundary($feedId, self::MIN_ENTRIES_PER_FEED);
        if (null === $query) {
            return [];
        }

        return self::idsOf($query->andWhere('e.createdAt < :cutoff')->setParameter('cutoff', $cutoff));
    }

    /** Null when the feed holds no more than `keep` entries. */
    private function deletablePastBoundary(int $feedId, int $keep): ?QueryBuilder
    {
        $boundary = $this->rankBoundaryBeyond($feedId, $keep);
        if (null === $boundary) {
            return null;
        }

        return $this->em->createQueryBuilder()
            ->select('e.id')
            ->from(Entry::class, 'e')
            ->where('e.feed = :feed')
            ->andWhere($this->pastBoundaryDql())
            ->andWhere($this->notProtectedDql())
            ->setParameter('feed', $feedId)
            ->setParameter('boundaryCreatedAt', $boundary->createdAt)
            ->setParameter('boundaryId', $boundary->id)
            ->setParameter('true', true, Types::BOOLEAN);
    }

    /** @return list<int> */
    private static function idsOf(QueryBuilder $query): array
    {
        /** @var list<int> $ids */
        $ids = $query->getQuery()->getSingleColumnResult();

        return $ids;
    }
```

- [ ] **Step 3: Run both database legs again.** Expected: PASS. If MySQL and SQLite disagree, compare `->getQuery()->getSQL()` from before and after before you change anything else.
- [ ] **Step 4: Break it.** Delete `->andWhere('e.createdAt < :cutoff')` from `staleIdsPastBoundary`, and watch `testKeepsAnOldArticleThatWasFetchedRecently` fail. Restore it by hand.
- [ ] **Step 5: Run `composer md`.** Expected: clean.
- [ ] **Step 6: Commit.**
```bash
git add src/Service/Retention/EntryPruner.php
git commit -m "refactor(#1165): entry pruner has an age query and a cap query, not a null-cutoff switch"
```

---

### Task 6: `ScrapeFailureReason` becomes a backed enum

**Files:**
- Create: `src/Service/Discovery/ScrapeFailureReason.php` and `tests/Service/Discovery/ScrapeFailureReasonTest.php`
- Modify:
  - `src/Service/Discovery/FeedDiscoveryResult.php` (whole file)
  - `src/Service/Subscription/SubscribeOutcome.php` (whole file)
  - `src/Service/Discovery/FeedDiscovery.php:64,69,94,144,148-150,180`
  - `src/Service/Discovery/FeedDiscoveryInterface.php:11-20`
  - `src/Controller/Api/SubscriptionController.php:100-103`
- Test: `tests/Service/Discovery/FeedDiscoveryTest.php`. `tests/Controller/Api/SubscriptionControllerTest.php:511` (the wire test) stays unchanged.

```php
<?php

declare(strict_types=1);

namespace App\Service\Discovery;

enum ScrapeFailureReason: string
{
    case Blocked = 'blocked';
    case Throttled = 'throttled';
    case Unreachable = 'unreachable';
    case NotScrapable = 'not_scrapable';
}
```

- [ ] **Step 1: Write the failing tests.** Create the test file:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Service\Discovery;

use App\Service\Discovery\ScrapeFailureReason;
use PHPUnit\Framework\TestCase;

final class ScrapeFailureReasonTest extends TestCase
{
    public function testTheWireValuesAreTheOnesTheSubscribeDialogRenders(): void
    {
        self::assertSame(
            ['blocked', 'throttled', 'unreachable', 'not_scrapable'],
            array_map(static fn (ScrapeFailureReason $reason): string => $reason->value, ScrapeFailureReason::cases()),
        );
    }
}
```

  Then, in `FeedDiscoveryTest`, add `use App\Service\Discovery\ScrapeFailureReason;` and replace:

| Lines | Before | After |
|---|---|---|
| 195, 226 | `self::assertSame('blocked', $result->scrapeFailureReason);` | `self::assertSame(ScrapeFailureReason::Blocked, $result->scrapeFailureReason);` |
| 285, 316, 331, 343, 406 | `self::assertSame('unreachable', $result->scrapeFailureReason);` | `self::assertSame(ScrapeFailureReason::Unreachable, $result->scrapeFailureReason);` |
| 301 | `self::assertSame('throttled', $result->scrapeFailureReason);` | `self::assertSame(ScrapeFailureReason::Throttled, $result->scrapeFailureReason);` |
| 358, 368 | `self::assertSame('not_scrapable', $result->scrapeFailureReason);` | `self::assertSame(ScrapeFailureReason::NotScrapable, $result->scrapeFailureReason);` |

- [ ] **Step 2: Run the tests.**
  Run: `php bin/phpunit tests/Service/Discovery`
  Expected: FAIL, because the class does not exist.
- [ ] **Step 3: Implement.**

`FeedDiscoveryResult.php` (whole file):
```php
<?php

declare(strict_types=1);

namespace App\Service\Discovery;

final readonly class FeedDiscoveryResult
{
    /**
     * @param DiscoveredFeed|null $feed the feed with its document, which the subscribe stores instead of refetching
     * @param list<FeedCandidate> $candidates
     */
    private function __construct(
        public ?DiscoveredFeed $feed,
        public array $candidates,
        public ?ScrapeFailureReason $scrapeFailureReason = null,
    ) {
    }

    public static function directFeed(DiscoveredFeed $feed): self
    {
        return new self($feed, []);
    }

    /** @param list<FeedCandidate> $candidates */
    public static function candidates(array $candidates): self
    {
        return new self(null, $candidates);
    }

    /** Nothing to offer, not even a scraped fallback: an outcome the subscribe UI renders, not an error. */
    public static function scrapeFailed(ScrapeFailureReason $reason): self
    {
        return new self(null, [], $reason);
    }
}
```

`SubscribeOutcome.php` (whole file):
```php
<?php

declare(strict_types=1);

namespace App\Service\Subscription;

use App\Entity\Subscription;
use App\Service\Discovery\FeedCandidate;
use App\Service\Discovery\ScrapeFailureReason;

final readonly class SubscribeOutcome
{
    /**
     * @param list<FeedCandidate> $candidates
     * @param int                 $unreadCount the entries the subscribe stored; nobody has read a feed just added
     */
    private function __construct(
        public ?Subscription $subscription,
        public array $candidates,
        public ?ScrapeFailureReason $scrapeFailureReason = null,
        public int $unreadCount = 0,
    ) {
    }

    public static function subscribed(Subscription $subscription, int $unreadCount = 0): self
    {
        return new self($subscription, [], null, $unreadCount);
    }

    /** @param list<FeedCandidate> $candidates an empty list is a legitimate outcome; the reason says why */
    public static function candidates(array $candidates, ?ScrapeFailureReason $scrapeFailureReason = null): self
    {
        return new self(null, $candidates, $scrapeFailureReason);
    }
}
```

`FeedDiscovery.php`:

| Line | Before | After |
|---|---|---|
| 64 | `return FeedDiscoveryResult::scrapeFailed('throttled');` | `return FeedDiscoveryResult::scrapeFailed(ScrapeFailureReason::Throttled);` |
| 69 | `return FeedDiscoveryResult::scrapeFailed('unreachable');` | `return FeedDiscoveryResult::scrapeFailed(ScrapeFailureReason::Unreachable);` |
| 94 | `return FeedDiscoveryResult::scrapeFailed('blocked');` | `return FeedDiscoveryResult::scrapeFailed(ScrapeFailureReason::Blocked);` |
| 144 | `return FeedDiscoveryResult::scrapeFailed('unreachable');` | `return FeedDiscoveryResult::scrapeFailed(ScrapeFailureReason::Unreachable);` |
| 149 | `\in_array($status, self::BLOCKED_STATUSES, true) ? 'blocked' : 'unreachable',` | `\in_array($status, self::BLOCKED_STATUSES, true) ? ScrapeFailureReason::Blocked : ScrapeFailureReason::Unreachable,` |
| 180 | `return FeedDiscoveryResult::scrapeFailed('not_scrapable');` | `return FeedDiscoveryResult::scrapeFailed(ScrapeFailureReason::NotScrapable);` |

`FeedDiscoveryInterface.php`, docblock before (lines 11–20):
```php
    /**
     * Never throws for an unreachable or feedless address: those are expected
     * outcomes the subscribe UI must render, so they surface as
     * FeedDiscoveryResult::$scrapeFailureReason
     * ('blocked'|'unreachable'|'not_scrapable') instead of an exception.
     * Callers can rely on always getting a result back to translate.
     *
     * With $fallback disabled, a page that advertises no feed yields an empty
     * candidate list and NO reason: 'not_scrapable' would tell the user about
     * a feature they have not turned on.
     */
```
After:
```php
    /** Never throws for an unreachable or feedless address; with $fallback off, a feedless page yields no reason. */
```

`SubscriptionController.php`:

Before (lines 102–103):
```php
            if (null !== $outcome->scrapeFailureReason) {
                $payload['scrapeFailureReason'] = $outcome->scrapeFailureReason;
```
After:
```php
            if (null !== $outcome->scrapeFailureReason) {
                $payload['scrapeFailureReason'] = $outcome->scrapeFailureReason->value;
```

  Also delete the two-line comment at lines 100–101. The `if` already says it:
```php
            // Key present only on failure: successful candidate lists stay
            // byte-compatible with what pre-scraper clients already parse.
```
- [ ] **Step 4: Run the tests.**
  Run: `php bin/phpunit tests/Service/Discovery tests/Service/Subscription tests/Controller/Api/SubscriptionControllerTest.php`
  Expected: PASS. Line 511 still sees `'blocked'`.
- [ ] **Step 5: Commit.**
```bash
git add src/Service/Discovery src/Service/Subscription/SubscribeOutcome.php src/Controller/Api/SubscriptionController.php tests/Service/Discovery
git commit -m "refactor(#1165): scrape failure reason is an enum"
```

---

### Task 7: The schema.org access declaration becomes an enum

**Files:**
- Create: `src/Service/Reader/Paywall/AccessDeclaration.php`
- Modify: `src/Service/Reader/Paywall/SchemaOrgAccess.php:17-36` and `src/Service/Reader/Paywall/PaywallSignals.php:19-22`
- Test: `tests/Service/Reader/Paywall/SchemaOrgAccessTest.php`. `PaywallSignalsTest.php` stays unchanged.

```php
<?php

declare(strict_types=1);

namespace App\Service\Reader\Paywall;

enum AccessDeclaration
{
    case Paywalled;
    case Free;
    case Undeclared;
}
```

- [ ] **Step 1: Rewrite the tests.** The fixtures stay; only the assertions change.

| Line | Before | After |
|---|---|---|
| 16, 27, 34, 49, 63, 71 | `self::assertTrue(SchemaOrgAccess::paywalledIn($html));` | `self::assertSame(AccessDeclaration::Paywalled, SchemaOrgAccess::declaredIn($html));` |
| 41 | `self::assertFalse(SchemaOrgAccess::paywalledIn($html));` | `self::assertSame(AccessDeclaration::Free, SchemaOrgAccess::declaredIn($html));` |
| 56, 78, 85 | `self::assertNull(SchemaOrgAccess::paywalledIn($html));` | `self::assertSame(AccessDeclaration::Undeclared, SchemaOrgAccess::declaredIn($html));` |

  Add `use App\Service\Reader\Paywall\AccessDeclaration;`.
- [ ] **Step 2: Run the tests.**
  Run: `php bin/phpunit tests/Service/Reader/Paywall/SchemaOrgAccessTest.php`
  Expected: FAIL.
- [ ] **Step 3: Implement.** In `SchemaOrgAccess`, replace lines 17–36:

```php
    public static function declaredIn(string $html): AccessDeclaration
    {
        $declaration = AccessDeclaration::Undeclared;
        preg_match_all(self::JSON_LD_PATTERN, $html, $blocks);
        foreach ($blocks[1] as $json) {
            $decoded = json_decode(trim($json), true);
            if (!\is_array($decoded)) {
                continue;
            }
            foreach (self::declarationsIn($decoded) as $accessibleForFree) {
                if (!$accessibleForFree) {
                    return AccessDeclaration::Paywalled;
                }
                $declaration = AccessDeclaration::Free;
            }
        }

        return $declaration;
    }
```

  In `PaywallSignals`, replace lines 19–22:
```php
    public static function isPreview(string $html, ?HTMLDocument $normalized): bool
    {
        return match (SchemaOrgAccess::declaredIn($html)) {
            AccessDeclaration::Paywalled => true,
            AccessDeclaration::Free => false,
            AccessDeclaration::Undeclared => $normalized !== null && self::gatedInBody($normalized),
        };
    }
```

- [ ] **Step 4: Run the tests.**
  Run: `php bin/phpunit tests/Service/Reader/Paywall`
  Expected: PASS.
- [ ] **Step 5: Commit.**
```bash
git add src/Service/Reader/Paywall tests/Service/Reader/Paywall/SchemaOrgAccessTest.php
git commit -m "refactor(#1165): schema.org access declaration is an enum, not a ?bool"
```

---

### Task 8: Loki picks its sink with an enum, and the spool shipper catches only a corrupt file

**Files:**
- Create: `src/Service/Logging/Loki/LokiDelivery.php` and `src/Service/Logging/Loki/Exception/CorruptSpoolFileException.php`
- Modify: `src/Service/Logging/Loki/LokiSinkFactory.php:19-33` and `src/Service/Logging/Loki/LokiSpoolShipper.php:22-65`
- Test: `tests/Service/Logging/Loki/LokiSinkFactoryTest.php` and `tests/Service/Logging/Loki/LokiSpoolShipperTest.php`

```php
<?php

declare(strict_types=1);

namespace App\Service\Logging\Loki;

enum LokiDelivery
{
    case Direct;
    case Spool;
}
```

```php
<?php

declare(strict_types=1);

namespace App\Service\Logging\Loki\Exception;

final class CorruptSpoolFileException extends \RuntimeException
{
    public function __construct(string $file, ?\Throwable $previous = null)
    {
        parent::__construct(sprintf('The Loki spool file %s is unreadable or not a JSON batch.', $file), 0, $previous);
    }
}
```

- [ ] **Step 1: Write the tests.** In `LokiSinkFactoryTest`, add `use App\Service\Logging\Loki\LokiDelivery;`:

| Line | Before | After |
|---|---|---|
| 19 | `self::assertSame('direct', LokiSinkFactory::selects('cli', false));` | `self::assertSame(LokiDelivery::Direct, LokiSinkFactory::selects('cli', false));` |
| 20 | `self::assertSame('direct', LokiSinkFactory::selects('cli', true));` | `self::assertSame(LokiDelivery::Direct, LokiSinkFactory::selects('cli', true));` |
| 25 | `self::assertSame('direct', LokiSinkFactory::selects('fpm-fcgi', true));` | `self::assertSame(LokiDelivery::Direct, LokiSinkFactory::selects('fpm-fcgi', true));` |
| 30 | `self::assertSame('spool', LokiSinkFactory::selects('cgi-fcgi', false));` | `self::assertSame(LokiDelivery::Spool, LokiSinkFactory::selects('cgi-fcgi', false));` |

  Add to `LokiSpoolShipperTest`:
```php
    public function testAFileHoldingAJsonScalarIsCountedFailedAndDeleted(): void
    {
        file_put_contents($this->spoolDirectory . '/1-cafe.json', '42');
        $shipper = new LokiSpoolShipper(
            new LokiClient(new MockHttpClient(), new StubLokiEndpoint()),
            $this->spoolDirectory,
        );

        $report = $shipper->ship();

        self::assertSame(0, $report->shipped);
        self::assertSame(1, $report->failed);
        self::assertSame([], glob($this->spoolDirectory . '/*.json') ?: []);
    }
```

- [ ] **Step 2: Run the tests.**
  Run: `php bin/phpunit tests/Service/Logging/Loki`
  Expected: the four factory assertions FAIL. The new shipper test PASSES; it pins the non-array branch before Step 3 moves it.
- [ ] **Step 3: Implement.** `LokiSinkFactory`, lines 19–33:

```php
    public function create(): LokiSink
    {
        return match (self::selects(\PHP_SAPI, \function_exists('fastcgi_finish_request'))) {
            LokiDelivery::Spool => $this->spool,
            LokiDelivery::Direct => $this->direct,
        };
    }

    public static function selects(string $sapi, bool $canFinishRequest): LokiDelivery
    {
        if ('cli' === $sapi || $canFinishRequest) {
            return LokiDelivery::Direct;
        }

        return LokiDelivery::Spool;
    }
```

  `LokiSpoolShipper`: add `use App\Service\Logging\Loki\Exception\CorruptSpoolFileException;`, replace `ship()` (lines 22–36), and replace `shipFile()` (lines 51–65) with `linesIn()`:

```php
    public function ship(): LokiSpoolReport
    {
        $shipped = 0;
        $failed = 0;
        foreach ($this->files() as $file) {
            try {
                $this->client->push(self::linesIn($file));
                ++$shipped;
            } catch (CorruptSpoolFileException) {
                ++$failed;
            } finally {
                @unlink($file);
            }
        }

        return new LokiSpoolReport($shipped, $failed);
    }
```

```php
    /**
     * @return list<array{ts: string, line: string, labels: array<string, string>}>
     *
     * @throws CorruptSpoolFileException
     */
    private static function linesIn(string $file): array
    {
        // Silenced: a concurrent tick may have shipped the file since glob(), and the error handler would throw.
        $contents = @file_get_contents($file);
        if (false === $contents) {
            throw new CorruptSpoolFileException($file);
        }

        try {
            $lines = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new CorruptSpoolFileException($file, $e);
        }
        if (!\is_array($lines)) {
            throw new CorruptSpoolFileException($file);
        }

        /** @var list<array{ts: string, line: string, labels: array<string, string>}> $lines */
        return $lines;
    }
```

  `LokiClient::push` is fail-open and never throws, so nothing else needs catching. A `TypeError` from a bug now surfaces instead of being counted as `failed`.
- [ ] **Step 4: Run the tests.**
  Run: `php bin/phpunit tests/Service/Logging tests/Service/Maintenance`
  Expected: PASS.
- [ ] **Step 5: Commit.**
```bash
git add src/Service/Logging/Loki tests/Service/Logging/Loki
git commit -m "refactor(#1165): Loki sink choice is an enum; the spool shipper catches only a corrupt file"
```

---

### Task 9: Mail and proxy connection tests keep the failure code apart from the detail

Both results have the same smell: machine codes (`'not_configured'`) and raw messages share one `reason`. Both get a failure enum and a separate `detail`, used internally only. **The wire does not change:** `toArray()` still emits `reason = detail ?? code`, with no new member. The two classes stay separate because their failure sets differ.

**Files:**
- Create: `src/Service/Mail/Settings/MailTestFailure.php`, `src/Service/Proxy/ProxyTestFailure.php` and `tests/Service/Mail/Settings/MailTestResultTest.php`
- Modify:
  - `src/Service/Mail/Settings/MailTestResult.php` (whole file)
  - `src/Service/Mail/Settings/MailConnectionTester.php:44,51,60,83`
  - `src/Service/Proxy/ProxyTestResult.php` (whole file)
  - `src/Service/Proxy/ProxyConnectionTester.php:39,43,56,60`
- Test:
  - `tests/Service/Mail/Settings/MailConnectionTesterTest.php`
  - `tests/Service/Proxy/ProxyTestResultTest.php`
  - `tests/Service/Proxy/ProxyConnectionTesterTest.php`
  - The wire tests `tests/Controller/Admin/AdminMailControllerTest.php` and `AdminProxyControllerTest.php` stay unchanged.

```php
<?php

declare(strict_types=1);

namespace App\Service\Mail\Settings;

enum MailTestFailure: string
{
    case NotConfigured = 'not_configured';
    case NoFromAddress = 'no_from_address';
    case SecretUnreadable = 'secret_unreadable';
    case SendRejected = 'send_rejected';
}
```

```php
<?php

declare(strict_types=1);

namespace App\Service\Proxy;

enum ProxyTestFailure: string
{
    case NotConfigured = 'not_configured';
    case SecretUnreadable = 'secret_unreadable';
    case Unreachable = 'unreachable';
    case UnexpectedStatus = 'unexpected_status';
}
```

- [ ] **Step 1: Write the mail tests.** Create `MailTestResultTest`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Service\Mail\Settings;

use App\Service\Mail\Settings\MailTestFailure;
use App\Service\Mail\Settings\MailTestResult;
use PHPUnit\Framework\TestCase;

final class MailTestResultTest extends TestCase
{
    public function testAGuardFailureSendsItsCode(): void
    {
        self::assertSame(
            ['ok' => false, 'reason' => 'not_configured'],
            MailTestResult::failed(MailTestFailure::NotConfigured)->toArray(),
        );
    }

    public function testARejectedSendSendsTheTransportMessage(): void
    {
        self::assertSame(
            ['ok' => false, 'reason' => '535 5.7.8 bad credentials'],
            MailTestResult::failed(MailTestFailure::SendRejected, '535 5.7.8 bad credentials')->toArray(),
        );
    }

    public function testSuccessSendsNoReason(): void
    {
        self::assertSame(['ok' => true, 'reason' => null], MailTestResult::ok()->toArray());
    }
}
```

  In `MailConnectionTesterTest`, add `use App\Service\Mail\Settings\MailTestFailure;`. The `assertFalse($result->ok)` and `assertTrue($result->ok)` lines (70, 82, 102, 119, 135, 162, 180, 205, 227) stay, because `ok` stays a property.

| Line | Before | After |
|---|---|---|
| 71 | `self::assertSame('not_configured', $result->reason);` | `self::assertSame(MailTestFailure::NotConfigured, $result->failure);` |
| 83 | `self::assertNotNull($result->reason);` | `self::assertSame(MailTestFailure::SendRejected, $result->failure);`<br>`self::assertNotNull($result->detail);` |
| 103 | `self::assertSame('no_from_address', $result->reason);` | `self::assertSame(MailTestFailure::NoFromAddress, $result->failure);` |
| 120 | `self::assertStringContainsString('not-an-address', (string) $result->reason);` | `self::assertStringContainsString('not-an-address', (string) $result->detail);` |
| 136, 163 | `self::assertNotSame('not_configured', $result->reason);` | `self::assertNotSame(MailTestFailure::NotConfigured, $result->failure);` |
| 182 | `[['kind' => MailKind::Test, 'recipient' => 'boss@example.com', 'error' => $result->reason]],` | `[['kind' => MailKind::Test, 'recipient' => 'boss@example.com', 'error' => $result->detail]],` |
| 206 | `self::assertSame('no_from_address', $result->reason);` | `self::assertSame(MailTestFailure::NoFromAddress, $result->failure);` |

- [ ] **Step 2: Write the proxy tests.** In `ProxyTestResultTest`, add `use App\Service\Proxy\ProxyTestFailure;`, then replace the whole class body:

```php
final class ProxyTestResultTest extends TestCase
{
    public function testOkResultCarriesTheEgressIpAndNoReason(): void
    {
        $result = ProxyTestResult::ok('203.0.113.7');

        self::assertTrue($result->ok);
        self::assertSame('203.0.113.7', $result->egressIp);
        self::assertNull($result->failure);
        self::assertSame(
            ['ok' => true, 'egressIp' => '203.0.113.7', 'reason' => null],
            $result->toArray(),
        );
    }

    public function testAGuardFailureSendsItsCodeAsTheReason(): void
    {
        $result = ProxyTestResult::failed(ProxyTestFailure::NotConfigured);

        self::assertFalse($result->ok);
        self::assertNull($result->egressIp);
        self::assertSame(ProxyTestFailure::NotConfigured, $result->failure);
        self::assertSame(
            ['ok' => false, 'egressIp' => null, 'reason' => 'not_configured'],
            $result->toArray(),
        );
    }

    public function testAFailureWithADetailSendsTheDetailAsTheReason(): void
    {
        $result = ProxyTestResult::failed(ProxyTestFailure::UnexpectedStatus, 'HTTP 404');

        self::assertSame(
            ['ok' => false, 'egressIp' => null, 'reason' => 'HTTP 404'],
            $result->toArray(),
        );
    }
}
```

  In `ProxyConnectionTesterTest`, add `use App\Service\Proxy\ProxyTestFailure;`. The `->ok` lines stay.

| Line | Before | After |
|---|---|---|
| 56 | `self::assertNotNull($result->reason);` | `self::assertSame(ProxyTestFailure::NotConfigured, $result->failure);` |
| 69 | `self::assertNotNull($result->reason);` | `self::assertSame(ProxyTestFailure::Unreachable, $result->failure);`<br>`self::assertNotNull($result->detail);` |
| 103 | `self::assertSame('HTTP 404', $result->reason);` | `self::assertSame(ProxyTestFailure::UnexpectedStatus, $result->failure);`<br>`self::assertSame('HTTP 404', $result->detail);` |
| 116 | `self::assertSame('HTTP 300', $result->reason);` | `self::assertSame(ProxyTestFailure::UnexpectedStatus, $result->failure);`<br>`self::assertSame('HTTP 300', $result->detail);` |
| 183 | `self::assertNotNull($result->reason);` | `self::assertSame(ProxyTestFailure::SecretUnreadable, $result->failure);`<br>`self::assertNotNull($result->detail);` |
| 202 | `self::assertIsString($result->reason);` | `self::assertSame(ProxyTestFailure::Unreachable, $result->failure);` |
| 203 | `self::assertStringContainsString('does not resolve host names', $result->reason);` | `self::assertStringContainsString('does not resolve host names', (string) $result->detail);` |

- [ ] **Step 3: Run the tests.**
  Run: `php bin/phpunit tests/Service/Mail/Settings tests/Service/Proxy`
  Expected: FAIL.
- [ ] **Step 4: Implement the mail side.** `MailTestResult.php` (whole file):

```php
<?php

declare(strict_types=1);

namespace App\Service\Mail\Settings;

final readonly class MailTestResult
{
    private function __construct(
        public bool $ok,
        public ?MailTestFailure $failure,
        public ?string $detail,
    ) {
    }

    public static function ok(): self
    {
        return new self(true, null, null);
    }

    public static function failed(MailTestFailure $failure, ?string $detail = null): self
    {
        return new self(false, $failure, $detail);
    }

    /** @return array{ok: bool, reason: string|null} */
    public function toArray(): array
    {
        return ['ok' => $this->ok, 'reason' => $this->detail ?? $this->failure?->value];
    }
}
```

  `MailConnectionTester`:

| Line | Before | After |
|---|---|---|
| 44 | `return MailTestResult::failed($e->getMessage());` | `return MailTestResult::failed(MailTestFailure::SecretUnreadable, $e->getMessage());` |
| 51 | `return MailTestResult::failed('not_configured');` | `return MailTestResult::failed(MailTestFailure::NotConfigured);` |
| 60 | `return MailTestResult::failed('no_from_address');` | `return MailTestResult::failed(MailTestFailure::NoFromAddress);` |
| 83 | `return MailTestResult::failed($e->getMessage());` | `return MailTestResult::failed(MailTestFailure::SendRejected, $e->getMessage());` |

- [ ] **Step 5: Implement the proxy side.** `ProxyTestResult.php` (whole file):

```php
<?php

declare(strict_types=1);

namespace App\Service\Proxy;

final readonly class ProxyTestResult
{
    private function __construct(
        public bool $ok,
        public ?string $egressIp,
        public ?ProxyTestFailure $failure,
        public ?string $detail,
    ) {
    }

    public static function ok(string $egressIp): self
    {
        return new self(true, $egressIp, null, null);
    }

    public static function failed(ProxyTestFailure $failure, ?string $detail = null): self
    {
        return new self(false, null, $failure, $detail);
    }

    /** @return array{ok: bool, egressIp: string|null, reason: string|null} */
    public function toArray(): array
    {
        return ['ok' => $this->ok, 'egressIp' => $this->egressIp, 'reason' => $this->detail ?? $this->failure?->value];
    }
}
```

  `ProxyConnectionTester`:

| Line | Before | After |
|---|---|---|
| 39 | `return ProxyTestResult::failed($e->getMessage());` | `return ProxyTestResult::failed(ProxyTestFailure::SecretUnreadable, $e->getMessage());` |
| 43 | `return ProxyTestResult::failed('not_configured');` | `return ProxyTestResult::failed(ProxyTestFailure::NotConfigured);` |
| 56 | `return ProxyTestResult::failed(ProxyHandshakeFailure::explain($e->getMessage()));` | `return ProxyTestResult::failed(ProxyTestFailure::Unreachable, ProxyHandshakeFailure::explain($e->getMessage()));` |
| 60 | `return ProxyTestResult::failed(sprintf('HTTP %d', $status));` | `return ProxyTestResult::failed(ProxyTestFailure::UnexpectedStatus, sprintf('HTTP %d', $status));` |

  Every wire `reason` stays byte-identical. Guard failures still send their code, and every other failure still sends the same message as before. `mail-section.component.ts:179` greps that message for the Gmail hint.
- [ ] **Step 6: Run the tests.**
  Run: `php bin/phpunit tests/Service/Mail tests/Service/Proxy tests/Controller/Admin/AdminMailControllerTest.php tests/Controller/Admin/AdminProxyControllerTest.php`
  Expected: PASS.
- [ ] **Step 7: Commit.**
```bash
git add src/Service/Mail/Settings src/Service/Proxy tests/Service/Mail/Settings tests/Service/Proxy
git commit -m "refactor(#1165): mail and proxy test results carry a failure enum and a separate detail"
```

---

### Task 10: `app:catalog:check-urls` throws a typed failure and catches only HTTP-client errors

**Files:**
- Create: `src/Service/Catalog/Exception/BrokenCatalogUrlException.php`
- Modify: `src/Command/CheckCatalogUrlsCommand.php:74-80, 104-134`
- Test: `tests/Command/CheckCatalogUrlsCommandTest.php`. `CheckCatalogUrlsCommandProxyTest.php` stays unchanged.

```php
<?php

declare(strict_types=1);

namespace App\Service\Catalog\Exception;

final class BrokenCatalogUrlException extends \RuntimeException
{
}
```

- [ ] **Step 1: Add a test** that pins transport-error reporting before the catch narrows:

```php
    public function testReportsATransportFailureAsBroken(): void
    {
        $client = new MockHttpClient(static fn (): MockResponse => new MockResponse(
            '',
            ['error' => 'Could not resolve host: rotten.example'],
        ));

        $tester = $this->tester($client);
        $tester->execute(['--limit' => '1']);

        self::assertSame(1, $tester->getStatusCode());
        self::assertStringContainsString('Could not resolve host', $tester->getDisplay());
    }
```

  Run: `php bin/phpunit tests/Command/CheckCatalogUrlsCommandTest.php`
  Expected: PASS against today's code. It is the characterization for Step 2.
- [ ] **Step 2: Implement.** Add `use App\Service\Catalog\Exception\BrokenCatalogUrlException;` and `use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;`.

Before (lines 74–80):
```php
        $broken = [];
        foreach ($feeds as $feed) {
            $failure = $this->check($feed->url, $proxy);
            if (null !== $failure) {
                $broken[] = \sprintf('%s (%s): %s', $feed->title, $feed->url, $failure);
            }
        }
```
After:
```php
        $broken = [];
        foreach ($feeds as $feed) {
            try {
                $this->assertServesFeed($feed->url, $proxy);
            } catch (BrokenCatalogUrlException $e) {
                $broken[] = \sprintf('%s (%s): %s', $feed->title, $feed->url, $e->getMessage());
            }
        }
```

  Replace lines 104–134 (the docblock and `check()`) with:
```php
    /** @throws BrokenCatalogUrlException */
    private function assertServesFeed(string $url, ?ProxyConfig $proxy): void
    {
        try {
            $response = $this->httpClient->request('GET', $url, [
                'timeout' => self::TIMEOUT_SECONDS,
                'max_duration' => self::TIMEOUT_SECONDS,
                // The fetcher's agent: a publisher tolerating an unknown checker must not pass for healthy.
                'headers' => ['User-Agent' => $this->userAgent],
                ...(null !== $proxy ? EgressOptions::proxied($proxy) : []),
            ]);
            $status = $response->getStatusCode();
            $head = 200 === $status ? mb_substr($response->getContent(), 0, 2048) : '';
        } catch (ExceptionInterface $e) {
            throw new BrokenCatalogUrlException($e->getMessage(), 0, $e);
        }

        if (200 !== $status) {
            throw new BrokenCatalogUrlException('HTTP ' . $status);
        }
        if (!str_contains($head, '<rss') && !str_contains($head, '<feed') && !str_contains($head, '<rdf:RDF')) {
            throw new BrokenCatalogUrlException('not a feed document');
        }
    }
```

- [ ] **Step 3: Run the tests.**
  Run: `php bin/phpunit tests/Command/`
  Expected: PASS.
- [ ] **Step 4: Commit.**
```bash
git add src/Service/Catalog/Exception/BrokenCatalogUrlException.php src/Command/CheckCatalogUrlsCommand.php tests/Command/CheckCatalogUrlsCommandTest.php
git commit -m "refactor(#1165): catalog URL check throws a typed failure and stops swallowing every Throwable"
```

---

### Task 11: Cursors throw `MalformedCursorException`, and the For You pager catches it on purpose

**Files:**
- Create: `src/Http/Exception/MalformedCursorException.php`
- Modify:
  - `src/Http/EntryCursor.php:27-46, 66-88`
  - `src/Http/RecommendationCursor.php:28-45`
  - `src/Service/Recommendation/RecommendationFeedPager.php:20-34`
- Test: `tests/Http/EntryCursorTest.php` and `tests/Http/RecommendationCursorTest.php`. These stay unchanged:
  - `tests/Service/Recommendation/RecommendationFeedPagerTest.php:46` (a malformed cursor yields the first page)
  - `tests/Service/Search/EntrySearchRequestFactoryTest.php` (a malformed cursor raises `ValidationException`)

```php
<?php

declare(strict_types=1);

namespace App\Http\Exception;

final class MalformedCursorException extends \InvalidArgumentException
{
}
```

- [ ] **Step 1: Rewrite `EntryCursorTest`.** Add `use App\Http\Exception\MalformedCursorException;` and `use PHPUnit\Framework\Attributes\DataProvider;`.

Before (lines 23–28):
```php
    public function testRejectsAThreePartCursorFromTheOldFormat(): void
    {
        $stale = rtrim(strtr(base64_encode('2026-08-14T12:00:00+00:00||42'), '+/', '-_'), '=');

        self::assertNull(EntryCursor::decode($stale));
    }
```
After:
```php
    public function testRejectsAThreePartCursorFromTheOldFormat(): void
    {
        $stale = rtrim(strtr(base64_encode('2026-08-14T12:00:00+00:00||42'), '+/', '-_'), '=');

        $this->expectException(MalformedCursorException::class);

        EntryCursor::decode($stale);
    }
```

Replace `testDecodeRejectsGarbage` (lines 37–44) with:
```php
    /** @return iterable<string, array{string}> */
    public static function malformedCursors(): iterable
    {
        yield 'not base64' => ['not-a-cursor'];
        yield 'one part' => [base64_encode('only-one-part')];
        yield 'bad date' => [base64_encode('bad-date|1')];
        yield 'non-numeric id' => [base64_encode('2026-01-01T00:00:00+00:00|notint')];
        yield 'empty' => [''];
    }

    #[DataProvider('malformedCursors')]
    public function testDecodeRejectsGarbage(string $cursor): void
    {
        $this->expectException(MalformedCursorException::class);

        EntryCursor::decode($cursor);
    }
```

- [ ] **Step 2: Rewrite `RecommendationCursorTest`.** Add `use App\Http\Exception\MalformedCursorException;`. Replace everything from line 39 to the end of the class with the code below. Line 39 is the two-line comment that starts `// Each case below is named for, and exercises, exactly one of decode()'s`.

```php
    public function testDecodeRejectsTheEmptyString(): void
    {
        $this->expectException(MalformedCursorException::class);

        RecommendationCursor::decode('');
    }

    public function testDecodeRejectsInputThatFailsStrictBase64Decoding(): void
    {
        // Spaces and '!' are outside the alphabet, so strict base64_decode() returns false.
        $this->expectException(MalformedCursorException::class);

        RecommendationCursor::decode('not a valid base64!!');
    }

    public function testDecodeRejectsValidBase64WithNoDelimiter(): void
    {
        $this->expectException(MalformedCursorException::class);

        RecommendationCursor::decode(base64_encode('only-one-part'));
    }

    public function testDecodeRejectsValidBase64WithThreeParts(): void
    {
        $this->expectException(MalformedCursorException::class);

        RecommendationCursor::decode(base64_encode('1|2|3'));
    }

    public function testDecodeRejectsANonNumericRunId(): void
    {
        $this->expectException(MalformedCursorException::class);

        RecommendationCursor::decode(base64_encode('abc|1'));
    }

    public function testDecodeRejectsANonNumericPosition(): void
    {
        $this->expectException(MalformedCursorException::class);

        RecommendationCursor::decode(base64_encode('1|abc'));
    }
}
```

  In `testRoundTrips` (line 17), delete `self::assertNotNull($decoded);`.
- [ ] **Step 3: Run the tests.**
  Run: `php bin/phpunit tests/Http/EntryCursorTest.php tests/Http/RecommendationCursorTest.php`
  Expected: FAIL.
- [ ] **Step 4: Implement `EntryCursor`.** Add `use App\Http\Exception\MalformedCursorException;`. Replace lines 27–46 (`fromRequestValue` and its docblock):

```php
    /** @throws ValidationException when the cursor is present but unreadable */
    public static function fromRequestValue(?string $raw): ?self
    {
        if ($raw === null || $raw === '') {
            return null;
        }

        try {
            return self::decode($raw);
        } catch (MalformedCursorException) {
            throw new ValidationException(['cursor' => ['The cursor is malformed.']]);
        }
    }
```

  Replace lines 66–88 (`decode`):
```php
    /** @throws MalformedCursorException */
    public static function decode(string $cursor): self
    {
        $raw = base64_decode(strtr($cursor, '-_', '+/'), true);
        $parts = false === $raw ? [] : explode('|', $raw);
        if (\count($parts) !== 2 || !ctype_digit($parts[1])) {
            throw new MalformedCursorException();
        }

        $sortInstant = \DateTimeImmutable::createFromFormat(\DateTimeInterface::ATOM, $parts[0]);
        if (false === $sortInstant) {
            throw new MalformedCursorException();
        }

        return new self($sortInstant, (int) $parts[1]);
    }
```

  The empty string needs no guard of its own: `base64_decode('', true)` is `''`, which explodes into one part, and that throws.
- [ ] **Step 5: Implement `RecommendationCursor::decode`** (lines 28–45):

```php
    /** @throws MalformedCursorException */
    public static function decode(string $cursor): self
    {
        $raw = base64_decode(strtr($cursor, '-_', '+/'), true);
        $parts = false === $raw ? [] : explode('|', $raw);
        if (\count($parts) !== 2 || !ctype_digit($parts[0]) || !ctype_digit($parts[1])) {
            throw new MalformedCursorException();
        }

        return new self((int) $parts[0], (int) $parts[1]);
    }
```

  Add `use App\Http\Exception\MalformedCursorException;`.
- [ ] **Step 6: Implement `RecommendationFeedPager`.** Add `use App\Http\Exception\MalformedCursorException;`.

Before (lines 20–34):
```php
    /**
     * A malformed cursor decodes to null, which yields the first page rather
     * than an error — the same leniency EntryCursor deliberately does NOT
     * have, because here a stale/garbled cursor should never break the feed.
     */
    #[WithSpan]
    public function page(ForYouFeedQuery $query): RecommendationFeedPage
    {
        $cursor = $query->cursor;
        $decodedCursor = $cursor === null || $cursor === '' ? null : RecommendationCursor::decode($cursor);

        $rows = $this->items->listForYou($query, $decodedCursor);

        return new RecommendationFeedPage($rows, $this->nextCursorFor($rows, $query->limit));
    }
```
After:
```php
    #[WithSpan]
    public function page(ForYouFeedQuery $query): RecommendationFeedPage
    {
        $rows = $this->items->listForYou($query, self::cursorOf($query));

        return new RecommendationFeedPage($rows, $this->nextCursorFor($rows, $query->limit));
    }

    /** A garbled For You cursor restarts the feed instead of breaking it, unlike EntryCursor. */
    private static function cursorOf(ForYouFeedQuery $query): ?RecommendationCursor
    {
        if (null === $query->cursor || '' === $query->cursor) {
            return null;
        }

        try {
            return RecommendationCursor::decode($query->cursor);
        } catch (MalformedCursorException) {
            return null;
        }
    }
```

- [ ] **Step 7: Run the tests.**
  Run: `php bin/phpunit tests/Http tests/Service/Recommendation/RecommendationFeedPagerTest.php tests/Service/Search tests/Controller/Api/EntryControllerTest.php tests/Controller/Api/SavedSearchEntriesControllerTest.php`, then `composer stan`
  Expected: PASS. A malformed entry cursor is still a 422 `validation_error` carrying `errors.cursor`. `composer stan` confirms D4.
- [ ] **Step 8: Commit.**
```bash
git add src/Http src/Service/Recommendation/RecommendationFeedPager.php tests/Http
git commit -m "refactor(#1165): a malformed cursor is an exception; the For You pager forgives it explicitly"
```

---

### Task 12: Backup compression failures are typed

**Files:**
- Create: `src/Service/Backup/Exception/BackupCompressionException.php`
- Modify: `src/Service/Backup/GzipLineReader.php:38,48` and `src/Service/Backup/BackupPart.php:31`

```php
<?php

declare(strict_types=1);

namespace App\Service\Backup\Exception;

/** The server could not compress or inflate backup bytes: a server fault, not a bad upload. */
final class BackupCompressionException extends \RuntimeException
{
}
```

This class deliberately does not extend `InvalidBackupException`. That exception maps to a 422 "Invalid backup file", which would blame the user for a server fault. This one stays unmapped and reaches the opaque 500, as the `\RuntimeException` does today (D5).

- [ ] **Step 1: Implement.**

| Site | Before | After |
|---|---|---|
| `GzipLineReader.php:38` (docblock) | `     * @throws InvalidBackupException` | `     * @throws InvalidBackupException`<br>`     * @throws BackupCompressionException` |
| `GzipLineReader.php:48` | `throw new \RuntimeException('Cannot open an in-memory stream.');` | `throw new BackupCompressionException('Cannot open an in-memory stream.');` |
| `BackupPart.php:31` | `return false !== $bytes ? $bytes : throw new \RuntimeException('Could not gzip-encode a backup part.');` | `return false !== $bytes ? $bytes : throw new BackupCompressionException('Could not gzip-encode a backup part.');` |

  Add `use App\Service\Backup\Exception\BackupCompressionException;` to both files.
- [ ] **Step 2: Run the tests.**
  Run: `php bin/phpunit tests/Service/Backup`
  Expected: PASS.
- [ ] **Step 3: Check Infection on these two lines.** No test can reach either branch, because `fopen('php://memory')` and `gzencode()` cannot be made to fail. If `composer infection:diff` reports their `Throw_` mutants as uncovered, and that drops the diff below `minMsi`, add this block to the `mutators` object in `infection.json5`:
```json5
        // Unreachable from a test: php://memory and gzencode() cannot be made to fail on demand.
        Throw_: {
            ignore: [
                'App\\Service\\Backup\\GzipLineReader::lines',
                'App\\Service\\Backup\\BackupPart::gzip',
            ],
        },
```
  Never lower `minMsi`.
- [ ] **Step 4: Commit.**
```bash
git add src/Service/Backup infection.json5
git commit -m "refactor(#1165): backup compression failures throw BackupCompressionException"
```

---

### Task 13: `FetchResponse::modifiedBody()` replaces the nullable body and its `''` fallbacks

**Files:**
- Modify:
  - `src/Service/Fetch/FetchResponse.php` (whole file)
  - `src/Service/Refresh/RefreshRunner.php:328-335`
  - `src/Service/Discovery/FeedDiscovery.php:72`, `WellKnownFeedProbe.php:107`, `SubstackProfileFeed.php:95` and `WordPressRestProbe.php:122`
  - `src/Service/Fetch/FaviconResolver.php:104`, `src/Service/Preview/FeedPreviewService.php:62` and `src/Service/Comments/CommentsLoader.php:39`
- Test:
  - `tests/Service/Fetch/FetchResponseTest.php`
  - `tests/Service/Fetch/ResponseClassifierTest.php:187`
  - `tests/Service/Fetch/ConcurrentFeedFetcherTest.php:137,198,231,255,370,422`
  - `tests/Service/Fetch/HttpFeedFetcherTest.php:73,152`

- [ ] **Step 1: Write the tests.** In `FetchResponseTest`, add:

```php
    public function testANotModifiedResponseHasNoBodyToHandBack(): void
    {
        $response = FetchResponse::notModified('https://example.com/feed', false, '"abc"', null);

        $this->expectException(\LogicException::class);

        $response->modifiedBody();
    }
```

| Site | Before | After |
|---|---|---|
| `FetchResponseTest.php:25` | `self::assertSame('<rss/>', $response->body);` | `self::assertSame('<rss/>', $response->modifiedBody());` |
| `FetchResponseTest.php:36` | `self::assertNull($response->body);` | delete the line |
| `ResponseClassifierTest.php:187` | `self::assertSame('<rss/>', $fetched->body);` | `self::assertSame('<rss/>', $fetched->modifiedBody());` |
| `ConcurrentFeedFetcherTest.php:137` | `self::assertSame('<rss/>', $outcomes[7]->responseOrThrow()->body);` | `self::assertSame('<rss/>', $outcomes[7]->responseOrThrow()->modifiedBody());` |
| `ConcurrentFeedFetcherTest.php:198, 231, 255` | `self::assertSame('<rss/>', $outcomes[1]->responseOrThrow()->body);` | `self::assertSame('<rss/>', $outcomes[1]->responseOrThrow()->modifiedBody());` |
| `ConcurrentFeedFetcherTest.php:370` | `self::assertStringContainsString('five.example.com', (string) $outcomes[5]->responseOrThrow()->body);` | `self::assertStringContainsString('five.example.com', $outcomes[5]->responseOrThrow()->modifiedBody());` |
| `ConcurrentFeedFetcherTest.php:422` | `self::assertSame('<rss/>', $response->body);` | `self::assertSame('<rss/>', $response->modifiedBody());` |
| `HttpFeedFetcherTest.php:73, 152` | `self::assertSame('<rss/>', $response->body);` | `self::assertSame('<rss/>', $response->modifiedBody());` |

- [ ] **Step 2: Run the tests.**
  Run: `php bin/phpunit tests/Service/Fetch`
  Expected: FAIL, because `modifiedBody` is undefined.
- [ ] **Step 3: Implement.** `FetchResponse.php` (whole file):

```php
<?php

declare(strict_types=1);

namespace App\Service\Fetch;

final readonly class FetchResponse
{
    private function __construct(
        public bool $notModified,
        public string $finalUrl,
        public bool $permanentRedirect,
        private ?string $body,
        public ?string $etag,
        public ?string $lastModified,
    ) {
    }

    public static function fetched(
        string $finalUrl,
        bool $permanentRedirect,
        string $body,
        ?string $etag,
        ?string $lastModified,
    ): self {
        return new self(false, $finalUrl, $permanentRedirect, $body, $etag, $lastModified);
    }

    public static function notModified(
        string $finalUrl,
        bool $permanentRedirect,
        ?string $etag,
        ?string $lastModified,
    ): self {
        return new self(true, $finalUrl, $permanentRedirect, null, $etag, $lastModified);
    }

    public function modifiedBody(): string
    {
        return $this->body ?? throw new \LogicException('A not-modified response carries no body.');
    }
}
```

  `RefreshRunner.php`:

Before (lines 328–335):
```php
            $body = $response->body;
            if (null === $body) {
                // Not reachable via the FetchResponse factories, but parsing an
                // empty string would silently record a bogus "successful" fetch.
                throw new FeedParseException('Fetcher returned a modified response without a body.');
            }

            $parsed = $this->bodyParser->parse($feed, $body);
```
After:
```php
            $parsed = $this->bodyParser->parse($feed, $response->modifiedBody());
```

  The remaining sites:

| Site | Before | After |
|---|---|---|
| `FeedDiscovery.php:72` | `$body = $response->body ?? '';` | `$body = $response->modifiedBody();` |
| `WellKnownFeedProbe.php:107` | `$this->parser->parse($response->body ?? ''),` | `$this->parser->parse($response->modifiedBody()),` |
| `SubstackProfileFeed.php:95` | `return $this->subdomainOf($response->body ?? '');` | `return $this->subdomainOf($response->modifiedBody());` |
| `WordPressRestProbe.php:122` | `$posts = json_decode($response->body ?? '', true);` | `$posts = json_decode($response->modifiedBody(), true);` |
| `FaviconResolver.php:104` | `$body = $response->body ?? '';` | `$body = $response->modifiedBody();` |
| `FeedPreviewService.php:62` | `$body = $response->body ?? '';` | `$body = $response->modifiedBody();` |
| `CommentsLoader.php:39` | `$body = (string) $this->fetcher->fetch($feedUrl)->body;` | `$body = $this->fetcher->fetch($feedUrl)->modifiedBody();` |

  None of these callers sends an ETag or a Last-Modified header: `FaviconResolver` builds `new FetchTicket($origin)`, and the others call `fetch($url)`. So none of them can receive a not-modified response, and the `''` fallback only hid that impossible case. The `\LogicException` marks a programmer error, as in the house `?? throw new \LogicException(…)` idiom, and nothing catches it. `FeedParseException` stays imported in `RefreshRunner`, because its catch at line 366 still uses it.
- [ ] **Step 4: Run the tests.**
  Run: `php bin/phpunit tests/Service/Fetch tests/Service/Refresh tests/Service/Discovery tests/Service/Preview tests/Service/Comments`
  Expected: PASS.
- [ ] **Step 5: Commit.**
```bash
git add src/Service/Fetch src/Service/Refresh/RefreshRunner.php src/Service/Discovery src/Service/Preview/FeedPreviewService.php src/Service/Comments/CommentsLoader.php tests/Service/Fetch
git commit -m "refactor(#1165): FetchResponse hands out a modified body or refuses; no FeedParseException for a contract breach"
```

---

### Task 14: `InvalidOpmlException` requires a non-empty message

The exception has no constructor of its own, so it inherits `\RuntimeException`'s optional `$message = ""`. `OpmlProblems` passes `getMessage()` through as the problem detail. `ApiProblem::toArray()` drops only a `null` detail, so a bare `new InvalidOpmlException()` would answer 422 with `"detail": ""`. Before #1160 the old `ApiException` subclass took `?string $detail = null`, which dropped the member instead. All three throwers pass a message today (`OpmlController.php:45`, `OpmlBodyReader.php:32` and `:37`). This task makes the type keep it that way.

`OpmlProblems` has no empty-string branch on `c43e21b5`, so there is nothing to remove there, and the mapper file does not change. Its arm stays as it is:
```php
            $exception instanceof InvalidOpmlException => new ResolvedProblem(new ApiProblem(
                'invalid_opml',
                'The OPML document could not be parsed',
                Response::HTTP_UNPROCESSABLE_ENTITY,
                $exception->getMessage(),
            )),
```

**Files:**
- Modify: `src/Service/Opml/Exception/InvalidOpmlException.php` (whole file)
- Create: `tests/Service/Opml/Exception/InvalidOpmlExceptionTest.php`
- These stay green unchanged:
  - `tests/Http/Problem/ProblemContractTest.php`, row `invalid opml` (D6)
  - `tests/Service/Opml/OpmlBodyReaderTest.php` and `OpmlImporterTest.php`
  - `tests/Controller/Api/OpmlControllerTest.php`
  - `tests/Service/Catalog/CatalogDocumentTest.php`. `CatalogDocument::parse()` catches the exception and rewraps its message.

**Interfaces:**
- Produces: `InvalidOpmlException::__construct(string $message)`, with `$message` typed `non-empty-string` for PHPStan.

- [ ] **Step 1: Write the failing test.** Create `tests/Service/Opml/Exception/InvalidOpmlExceptionTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Service\Opml\Exception;

use App\Service\Opml\Exception\InvalidOpmlException;
use PHPUnit\Framework\TestCase;

final class InvalidOpmlExceptionTest extends TestCase
{
    public function testItCannotBeBuiltWithoutAMessage(): void
    {
        $constructor = new \ReflectionMethod(InvalidOpmlException::class, '__construct');

        self::assertSame(1, $constructor->getNumberOfRequiredParameters());
    }

    public function testItCarriesTheReasonTheUserReads(): void
    {
        $exception = new InvalidOpmlException('OPML has no <body>.');

        self::assertSame('OPML has no <body>.', $exception->getMessage());
    }
}
```

- [ ] **Step 2: Run the test.**
  Run: `php bin/phpunit tests/Service/Opml/Exception/InvalidOpmlExceptionTest.php`
  Expected: `testItCannotBeBuiltWithoutAMessage` FAILS with "Failed asserting that 0 is identical to 1", because the inherited `Exception::__construct` requires nothing. `testItCarriesTheReasonTheUserReads` PASSES. It guards the `parent::__construct()` call against Infection's `MethodCallRemoval`.
- [ ] **Step 3: Implement.** `src/Service/Opml/Exception/InvalidOpmlException.php` (whole file).

  Before:
```php
<?php

declare(strict_types=1);

namespace App\Service\Opml\Exception;

final class InvalidOpmlException extends \RuntimeException
{
}
```
  After:
```php
<?php

declare(strict_types=1);

namespace App\Service\Opml\Exception;

final class InvalidOpmlException extends \RuntimeException
{
    /** @param non-empty-string $message OpmlProblems sends it verbatim as the problem detail */
    public function __construct(string $message)
    {
        parent::__construct($message);
    }
}
```

- [ ] **Step 4: Run the tests.**
  Run: `php bin/phpunit tests/Service/Opml tests/Service/Catalog/CatalogDocumentTest.php tests/Controller/Api/OpmlControllerTest.php tests/Http/Problem/ProblemContractTest.php`, then `vendor/bin/phpstan analyse src/Service/Opml src/Controller/Api/OpmlController.php tests/Service/Opml`
  Expected: PASS. PHPStan is clean, because every thrower passes a non-empty literal.
- [ ] **Step 5: Break it.** In `src/Controller/Api/OpmlController.php:45`, change `throw new InvalidOpmlException('The OPML body is empty or larger than 1 MB.');` to `throw new InvalidOpmlException('');`. Run `vendor/bin/phpstan analyse src/Controller/Api/OpmlController.php`. It must report that parameter `#1 $message` of the `InvalidOpmlException` constructor expects `non-empty-string` and got `''`. Then change it to `throw new InvalidOpmlException();` and run the same command. It must report the missing parameter. Restore the original line by hand.
- [ ] **Step 6: Commit.**
```bash
git add src/Service/Opml/Exception/InvalidOpmlException.php tests/Service/Opml/Exception/InvalidOpmlExceptionTest.php
git commit -m "refactor(#1165): InvalidOpmlException requires the message it sends as the problem detail"
```

---

### Finishing PR A

1. Run every gate listed under Global Constraints.
   Run: `grep -rn 'catch (\\Throwable' src/Service/Logging src/Command/CheckCatalogUrlsCommand.php`
   Expected: no output.
2. Run the **SDD final whole-branch review**. It is not optional. Ask the reviewer to attack four things:
   - Can a caller now distinguish a refused token, state or code by timing, exception class or message? Every refusal path must throw the same class, with the default message.
   - Diff the `invalid_token`, `invalid_state`, `invalid_opml`, cursor-422, `scrapeFailureReason`, mail-test `reason` and proxy-test `reason` bodies against `develop`.
   - Can `ProxiedAttemptFailedException`, `CorruptSpoolFileException` or `MalformedCursorException` escape their catch?
   - Did any new exception pick up an HTTP mapping? Run `git diff origin/develop -- src/Http/Problem`, which must be empty.
3. Run `/simplify` over the branch diff.
4. Open the PR against `develop`. The body starts with `Refs #1165 (PR A of 2: typed outcomes and failures).`, followed by the table from **Exceptions introduced**. Don't write `Closes`: PR B closes the issue.
5. Merge only when CI is green. Arm a Monitor that polls `gh pr checks <n>` until every check has concluded, then run `gh pr merge <n> --merge`. Don't use `--auto`: this repository merges immediately with it.

---

# PR B — `refactor/1165-require-id`

Create this branch from `develop` after PR A merges. Task 15 comes first. Tasks 16, 17 and 19 are independent of each other and each depends only on Task 15. Task 18 comes last, because its rule fails until every site is converted.

### Task 15: The `PersistedId` trait, `UnpersistedEntityException`, and the explicit guard sites

**Files:**
- Create: `src/Entity/PersistedId.php`, `src/Entity/Exception/UnpersistedEntityException.php` and `tests/Entity/PersistedIdTest.php`
- Modify: eight entities, and the 13 sites in Appendix C.

```php
<?php

declare(strict_types=1);

namespace App\Entity;

use App\Entity\Exception\UnpersistedEntityException;

trait PersistedId
{
    abstract public function getId(): ?int;

    public function requireId(): int
    {
        return $this->getId() ?? throw new UnpersistedEntityException(static::class);
    }
}
```

```php
<?php

declare(strict_types=1);

namespace App\Entity\Exception;

final class UnpersistedEntityException extends \LogicException
{
    public function __construct(string $entityClass)
    {
        parent::__construct(sprintf('%s has no id yet: it was never flushed.', $entityClass));
    }
}
```

**Why a trait:** every entity already declares `getId(): ?int`, and the trait's abstract method binds to it. **Why it is safe with native lazy objects:** ORM 3.6 lazy ghosts are instances of the real class, with the identifier set without initializing the object. So `requireId()` → `getId()` reads a property that is already set and never loads the entity. The second test below proves this rather than assuming it.

- [ ] **Step 1: Write the failing test.**

```php
<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\Exception\UnpersistedEntityException;
use App\Entity\User;
use App\Tests\DbTestCase;

final class PersistedIdTest extends DbTestCase
{
    public function testAFlushedEntityHandsBackItsId(): void
    {
        $user = $this->flushedUser();

        self::assertSame($user->getId(), $user->requireId());
    }

    public function testAnUninitializedReferenceAnswersWithoutLoading(): void
    {
        $id = $this->flushedUser()->requireId();
        $this->em->clear();

        $reference = $this->em->getReference(User::class, $id);
        self::assertNotNull($reference);

        self::assertSame($id, $reference->requireId());
        self::assertTrue($this->em->getUnitOfWork()->isUninitializedObject($reference));
    }

    public function testAnUnsavedEntityIsRefused(): void
    {
        $this->expectException(UnpersistedEntityException::class);
        $this->expectExceptionMessage(User::class);

        (new User('unsaved@example.com', new \DateTimeImmutable('2026-09-25T00:00:00Z')))->requireId();
    }

    private function flushedUser(): User
    {
        $user = new User('persisted@example.com', new \DateTimeImmutable('2026-09-25T00:00:00Z'));
        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }
}
```

- [ ] **Step 2: Run the test.**
  Run: `php bin/phpunit tests/Entity/PersistedIdTest.php`
  Expected: FAIL, because `requireId` is undefined.
- [ ] **Step 3: Add the trait, the exception, and the trait to the entities.** In each of the eight entities below, insert `    use PersistedId;` followed by a blank line as the first line of the class body:

| File | Class line (the `{` follows on the next line) |
|---|---|
| `src/Entity/User.php` | 17: `class User implements UserInterface, PasswordAuthenticatedUserInterface` |
| `src/Entity/Feed.php` | 15: `class Feed` |
| `src/Entity/Entry.php` | 20: `class Entry` |
| `src/Entity/Subscription.php` | 16: `class Subscription` |
| `src/Entity/Tag.php` | 13: `class Tag` |
| `src/Entity/SavedSearch.php` | 20: `class SavedSearch` |
| `src/Entity/RecommendationRun.php` | 37: `class RecommendationRun` |
| `src/Entity/CatalogCategory.php` | 20: `class CatalogCategory` |

  `src/Entity/User.php`, lines 17–19. Before:
```php
class User implements UserInterface, PasswordAuthenticatedUserInterface
{
    #[ORM\Id]
```
  After:
```php
class User implements UserInterface, PasswordAuthenticatedUserInterface
{
    use PersistedId;

    #[ORM\Id]
```

  `src/Entity/Feed.php`, lines 15–17. Before:
```php
class Feed
{
    #[ORM\Id]
```
  After:
```php
class Feed
{
    use PersistedId;

    #[ORM\Id]
```

  `src/Entity/Entry.php`, lines 20–22. Before:
```php
class Entry
{
    #[ORM\Id]
```
  After:
```php
class Entry
{
    use PersistedId;

    #[ORM\Id]
```

  `src/Entity/Subscription.php`, lines 16–18. Before:
```php
class Subscription
{
    #[ORM\Id]
```
  After:
```php
class Subscription
{
    use PersistedId;

    #[ORM\Id]
```

  `src/Entity/Tag.php`, lines 13–15. Before:
```php
class Tag
{
    #[ORM\Id]
```
  After:
```php
class Tag
{
    use PersistedId;

    #[ORM\Id]
```

  `src/Entity/SavedSearch.php`, lines 20–22. Before:
```php
class SavedSearch
{
    #[ORM\Id]
```
  After:
```php
class SavedSearch
{
    use PersistedId;

    #[ORM\Id]
```

  `src/Entity/RecommendationRun.php`, lines 37–39. Before:
```php
class RecommendationRun
{
    public const string STATUS_PENDING = 'pending';
```
  After:
```php
class RecommendationRun
{
    use PersistedId;

    public const string STATUS_PENDING = 'pending';
```

  `src/Entity/CatalogCategory.php`, lines 20–22. Before:
```php
class CatalogCategory
{
    #[ORM\Id]
```
  After:
```php
class CatalogCategory
{
    use PersistedId;

    #[ORM\Id]
```

  Run: `php bin/phpunit tests/Entity/PersistedIdTest.php`
  Expected: PASS. If the lazy-reference assertion fails, stop and report it: PR B rests on that premise.
- [ ] **Step 4: Replace the 13 explicit sites** exactly as Appendix C shows. Three private helpers become redundant and are deleted along with their docblocks:
  - `RecommendationRunAdvancer::requireUserId()` (lines 539–543, the blank line before it included; its callers are at 275, 313, 432 and 484)
  - `AiProviderConfigurator::identify()` (lines 286–294, the blank line before it and its docblock included; its callers are at 96, 134 and 214)
  - `BackupPartWalk::entryId()` (lines 136–140, the blank line before it included; its callers are at 89, 92 and 134)
- [ ] **Step 5: Run the gates.**
  Run: `php bin/phpunit` (the full suite: an unsaved entity that used to yield `0` now throws, and that is the point), then `composer md`
  Expected: PASS, and PHPMD clean. `RecommendationRun` already carries `@SuppressWarnings("PHPMD.TooManyPublicMethods")`.
- [ ] **Step 6: Commit.**
```bash
git add src/Entity src/Http/EntryPage.php src/Service src/Repository/RecommendationItemRepository.php src/EventListener/AddUserIdClaimOnTokenIssue.php tests/Entity/PersistedIdTest.php
git commit -m "refactor(#1165): requireId() on persisted entities replaces ad-hoc id guards"
```

### Task 16: Sweep the HTTP edge: controllers, `AdminUserJson` and the e2e seed command (55 sites)

**Files:** every site listed in Appendix A.

- [ ] **Step 1: Apply each before/after in Appendix A.** Each line changes exactly as shown and nothing else changes. `ThinControllerRule` is unaffected, because no private method is added.
- [ ] **Step 2: Run the tests.**
  Run: `php bin/phpunit tests/Controller tests/Http tests/Command`, then `composer stan`
  Expected: PASS.
- [ ] **Step 3: Commit.**
```bash
git add src/Controller src/Http/AdminUserJson.php src/Command/E2eSeedAdminSubscriptionCommand.php
git commit -m "refactor(#1165): controllers read ids through requireId()"
```

### Task 17: Sweep services and repositories (62 sites)

**Files:** every site listed in Appendix B.

- [ ] **Step 1: Apply each before/after in Appendix B.** Then verify one assumption: `SavedSearchSlug::assignTo()` (Appendix B, `SavedSearchSlug.php:29`) must run after a flush.
  Run: `grep -n "assignTo" -B6 src/Controller/Api/SavedSearchController.php src/Service/Backup/RestoreLoader.php`
  Expected: a `flush()` precedes each call. If one does not, the old code produced a `0-…` slug. Report that as a bug, and don't mask it.
- [ ] **Step 2: Run both database legs.**
  Run: `php bin/phpunit`, then `docker compose exec php composer test`
  Expected: PASS on both.
- [ ] **Step 3: Commit.**
```bash
git add src/Service src/Repository
git commit -m "refactor(#1165): services and repositories read ids through requireId()"
```

### Task 18: A PHPStan guard, `EntityIdCoercionRule`

**Files:**
- Create: `tests/PhpStan/EntityIdCoercionRule.php`, `tests/PhpStan/EntityIdCoercionRuleTest.php` and `tests/PhpStan/data/entity-id-coercion-fixtures.php`
- Modify: `phpstan.dist.neon` and `../CLAUDE.md`

- [ ] **Step 1: Write the fixture.** Line numbers matter: the test expects errors on lines 20 and 21.

```php
<?php

declare(strict_types=1);

namespace App\Tests\PhpStan\Data\EntityIdCoercion;

use App\Entity\User;

final class NotAnEntity
{
    public function getId(): ?int
    {
        return null;
    }
}

/** @param array{id: string} $row */
function coercions(User $user, NotAnEntity $dto, array $row): void
{
    $cast = (int) $user->getId();
    $defaulted = $user->getId() ?? 0;
    $fromRow = (int) $row['id'];
    $foreign = $dto->getId() ?? 0;
    $read = $user->requireId();
}
```

- [ ] **Step 2: Write the rule test.**

```php
<?php

declare(strict_types=1);

namespace App\Tests\PhpStan;

use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;

/** @extends RuleTestCase<EntityIdCoercionRule> */
final class EntityIdCoercionRuleTest extends RuleTestCase
{
    protected function getRule(): Rule
    {
        return new EntityIdCoercionRule();
    }

    public function testItReportsACastAndADefaultOnAnEntityIdOnly(): void
    {
        $this->analyse(
            [__DIR__ . '/data/entity-id-coercion-fixtures.php'],
            [
                [EntityIdCoercionRule::MESSAGE, 20],
                [EntityIdCoercionRule::MESSAGE, 21],
            ],
        );
    }
}
```

  Run: `php bin/phpunit tests/PhpStan/EntityIdCoercionRuleTest.php`
  Expected: FAIL, because the class does not exist.
- [ ] **Step 3: Implement the rule.**

```php
<?php

declare(strict_types=1);

namespace App\Tests\PhpStan;

use App\Entity\PersistedId;
use PhpParser\Node;
use PhpParser\Node\Expr\BinaryOp\Coalesce;
use PhpParser\Node\Expr\Cast\Int_;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Identifier;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/** @implements Rule<Node\Expr> */
final readonly class EntityIdCoercionRule implements Rule
{
    public const string MESSAGE = "Read a persisted entity's id with requireId(); "
        . 'casting or defaulting getId() hides an unsaved entity (#1165).';

    public function getNodeType(): string
    {
        return Node\Expr::class;
    }

    public function processNode(Node $node, Scope $scope): array
    {
        $coerced = match (true) {
            $node instanceof Int_ => $node->expr,
            $node instanceof Coalesce => $node->left,
            default => null,
        };

        if (!$coerced instanceof MethodCall || !$this->readsAnEntityId($coerced, $scope)) {
            return [];
        }

        return [
            RuleErrorBuilder::message(self::MESSAGE)
                ->identifier('simpleFeedReader.entityIdCoercion')
                ->build(),
        ];
    }

    private function readsAnEntityId(MethodCall $call, Scope $scope): bool
    {
        if (!$call->name instanceof Identifier || 'getId' !== $call->name->toString()) {
            return false;
        }
        if ($scope->isInTrait() && PersistedId::class === $scope->getTraitReflection()?->getName()) {
            return false;
        }

        foreach ($scope->getType($call->var)->getObjectClassNames() as $className) {
            if (str_starts_with($className, 'App\\Entity\\')) {
                return true;
            }
        }

        return false;
    }
}
```

  Register it in `phpstan.dist.neon`. On `c43e21b5` the `services:` list ends with the `DomainKnowsNoHttpRule` entry (lines 32–34). Append the new entry after it.

  Before (lines 29–34):
```neon
    -
        class: PhpParser\NodeFinder
    -
        class: App\Tests\PhpStan\DomainKnowsNoHttpRule
        tags:
            - phpstan.rules.rule
```
  After:
```neon
    -
        class: PhpParser\NodeFinder
    -
        class: App\Tests\PhpStan\DomainKnowsNoHttpRule
        tags:
            - phpstan.rules.rule
    -
        class: App\Tests\PhpStan\EntityIdCoercionRule
        tags:
            - phpstan.rules.rule
```

- [ ] **Step 4: Run the checks.**
  Run: `php bin/phpunit tests/PhpStan`, then `composer stan`
  Expected: PASS, and `composer stan` clean across `src` and `tests`. Any hit is a site the grep missed, such as a line-wrapped cast. Convert it with `->requireId()`, and add its before/after to the commit message body.
- [ ] **Step 5: Break it.** Change `SubscriptionController.php:52` back to `$rows = $this->subscriptionRepo->findForUserWithTags((int) $user->getId());` and watch `composer stan` report it. Restore the line by hand.
- [ ] **Step 6: Add this bullet to CLAUDE.md** under "Enforced mechanically by `composer check` and `composer md`", after the `ThinControllerRule` bullet:
```markdown
- **`EntityIdCoercionRule`** (`tests/PhpStan/EntityIdCoercionRule.php`) — read a persisted entity's id with `requireId()`, never `(int) $entity->getId()` or `$entity->getId() ?? …`.
```
- [ ] **Step 7: Commit.**
```bash
git add tests/PhpStan phpstan.dist.neon ../CLAUDE.md
git commit -m "test(#1165): PHPStan forbids coercing an entity id"
```

### Task 19: `PromptLine::$entryId` becomes non-null

History lines render through `historyLine()`, which never prints an id (`RecommendationPromptBuilder.php:478-485`). The "model has nothing to pick" property therefore comes from the renderer, not from a null. `testBatchMessagesReturnsTheExactRoleContentStructure` pins that rendering.

**Files:**
- Modify:
  - `src/Service/Recommendation/PromptLine.php`
  - `src/Service/Recommendation/RecommendationHistoryLoader.php:129`
  - `src/Service/Recommendation/RecommendationCandidateLoader.php:92-95, 204`
  - `src/Service/Recommendation/RecommendationPromptBuilder.php:133, 491-495`
- Test: `tests/Service/Recommendation/RecommendationPromptBuilderTest.php:629-636, 641-647, 663`

- [ ] **Step 1: Change the test.**
  - Delete `testPackBatchesFallsBackToZeroForACandidateWithoutAnEntryId` (lines 629–636): the state it tested can no longer be represented.
  - In `testBatchMessagesReturnsTheExactRoleContentStructure`:

| Line | Before | After |
|---|---|---|
| 641 | `favorites: [new PromptLine(null, 'Fav Title', 'Feed A', '2026-01-01', 'fav desc')],` | `favorites: [new PromptLine(101, 'Fav Title', 'Feed A', '2026-01-01', 'fav desc')],` |
| 642 | `kept: [new PromptLine(null, 'Kept Title', 'Feed B', '2026-01-02', null)],` | `kept: [new PromptLine(102, 'Kept Title', 'Feed B', '2026-01-02', null)],` |
| 643 | `viewed: [new PromptLine(null, 'View Title', 'Feed C', '2026-01-02', null)],` | `viewed: [new PromptLine(103, 'View Title', 'Feed C', '2026-01-02', null)],` |
| 647 | `new PromptLine(null, 'No Id', 'Feed D', '2026-01-04', null),` | `new PromptLine(6, 'Second', 'Feed D', '2026-01-04', null),` |
| 663 | `. '- [0] No Id — Feed D — 2026-01-04',` | `. '- [6] Second — Feed D — 2026-01-04',` |

  Line 660 (`"FAVORITES (newest first):\n- Fav Title — Feed A — 2026-01-01 — fav desc"`) stays unchanged. It now proves that a history line with a real id still renders without one.
- [ ] **Step 2: Run the tests.**
  Run: `php bin/phpunit tests/Service/Recommendation/RecommendationPromptBuilderTest.php`
  Expected: PASS already, because the constructor still accepts `?int`. Now change the type in Step 3; PHPStan and the suite then prove the rest.
- [ ] **Step 3: Implement.** `PromptLine.php` (whole file):

```php
<?php

declare(strict_types=1);

namespace App\Service\Recommendation;

/** One entry in a prompt; only candidate lines print their id, so a history line gives the model nothing to pick. */
final readonly class PromptLine
{
    public function __construct(
        public int $entryId,
        public string $title,
        public string $feedName,
        public string $date,
        public ?string $description,
    ) {
    }
}
```

| Site | Before | After |
|---|---|---|
| `RecommendationHistoryLoader.php:129` | `entryId: null,` | `entryId: $entry->requireId(),` |
| `RecommendationCandidateLoader.php:204` | `entryId: $entry->getId(),` | `entryId: $entry->requireId(),` |
| `RecommendationCandidateLoader.php:92-95` | `foreach ($this->linesFor($qb) as $line) {`<br>`    /** @var int $entryId non-null: every line here came from an Entry row */`<br>`    $entryId = $line->entryId;`<br>`    $linesById[$entryId] = $line;`<br>`}` | `foreach ($this->linesFor($qb) as $line) {`<br>`    $linesById[$line->entryId] = $line;`<br>`}` |
| `RecommendationPromptBuilder.php:133` | `$current[] = $candidate->entryId ?? 0;` | `$current[] = $candidate->entryId;` |
| `RecommendationPromptBuilder.php:491-495` | `$entryId = $line->entryId ?? 0;`<br><br>`return null === $description`<br>`    ? \sprintf('- [%d] %s — %s — %s', $entryId, $line->title, $line->feedName, $line->date)`<br>`    : \sprintf('- [%d] %s — %s — %s — %s', $entryId, $line->title, $line->feedName, $line->date, $description);` | `return null === $description`<br>`    ? \sprintf('- [%d] %s — %s — %s', $line->entryId, $line->title, $line->feedName, $line->date)`<br>`    : \sprintf('- [%d] %s — %s — %s — %s', $line->entryId, $line->title, $line->feedName, $line->date, $description);` |

- [ ] **Step 4: Run the tests.**
  Run: `php bin/phpunit tests/Service/Recommendation`, then `composer stan`
  Expected: PASS. The rendered prompts are byte-identical for every real candidate.
- [ ] **Step 5: Commit.**
```bash
git add src/Service/Recommendation tests/Service/Recommendation/RecommendationPromptBuilderTest.php
git commit -m "refactor(#1165): prompt lines always carry their entry id; only candidates print it"
```

### Finishing PR B

1. Run the gates, including both database legs.
   Run: `grep -rnE '\(int\) ?\$[A-Za-z_]+(->[A-Za-z_]+(\(\))?)*->getId\(\)|getId\(\) ?\?\?' src`
   Expected: no output.
2. Run the SDD final review. Ask the reviewer to hunt for any path where an entity reaches a changed site **before** it is flushed: builders, factories, and `prePersist` listeners. The old code quietly produced `0` there; the new code throws.
3. Run `/simplify`.
4. Open the PR against `develop` with the body `Closes #1165 (PR B of 2; PR A was #<n>).`
5. Merge only when CI is green. Arm a Monitor that polls `gh pr checks <n>` until every check has concluded, then run `gh pr merge <n> --merge`. Don't use `--auto`: this repository merges immediately with it. Afterwards, confirm that #1165 closed.

---

## Decisions (ruled by Lars, 2026-09-25)

1. **Two PRs.** PR A's body says `Refs #1165`, and PR B's says `Closes #1165`. Each PR has its own Finishing section.
2. **Reuse `InvalidTokenException`** for the token `consume()` family. The contract is the same, so there is no new class and no new mapper arm.
3. **Keep `InvalidOAuthStateException`** as a separate, unmapped class that the OAuth callback catches.
4. **`LokiSpoolShipper` does not log a corrupt file.** The maintenance report already counts it. The catch stays narrow.
5. **The mail-test wire stays unchanged.** `MailTestFailure` is internal only, with no new `failure` member.
6. **`ProxyTestResult` is fixed in Task 9**, alongside `MailTestResult`. It is the same pattern, the third occurrence, so DRY applies.
7. **Keep the `EntityIdCoercionRule`** PHPStan guard (Task 18).
8. **`PromptLine::$entryId` becomes non-null** on the single class (Task 19). There is no `CandidateLine` split.

---

## Appendix A: HTTP-edge `(int) $x->getId()` sites (Task 16), 55 sites on `develop` @ `c43e21b5`

Found with `grep -rnE '\(int\) ?\$[A-Za-z_]+(->[A-Za-z_]+(\(\))?)*->getId\(\)' src`. Each line changes exactly as shown. Indentation is trimmed here; keep the file's indentation.

```diff
src/Command/E2eSeedAdminSubscriptionCommand.php:165
- $subscription->setPosition($this->subscriptions->nextPositionForUser((int) $admin->getId()));
+ $subscription->setPosition($this->subscriptions->nextPositionForUser($admin->requireId()));

src/Command/E2eSeedAdminSubscriptionCommand.php:190
- $state = $this->entryStates->findOneForUserEntry((int) $admin->getId(), (int) $entry->getId());
+ $state = $this->entryStates->findOneForUserEntry($admin->requireId(), $entry->requireId());

src/Controller/Admin/AdminCatalogFeedController.php:50
- $feed->setPosition($this->feeds->nextPositionInCategory((int) $category->getId()));
+ $feed->setPosition($this->feeds->nextPositionInCategory($category->requireId()));

src/Controller/Admin/AdminUserController.php:90
- $userId = (int) $user->getId();
+ $userId = $user->requireId();

src/Controller/Api/CatalogController.php:35
- $this->feeds->subscribedUrlSetForUser((int) $user->getId()),
+ $this->feeds->subscribedUrlSetForUser($user->requireId()),

src/Controller/Api/EntryCommentsController.php:32
- $entry = $this->entryList->findOneSubscribedByUser($id, (int) $user->getId())
+ $entry = $this->entryList->findOneSubscribedByUser($id, $user->requireId())

src/Controller/Api/EntryController.php:91
- userId: (int) $user->getId(),
+ userId: $user->requireId(),

src/Controller/Api/EntryController.php:102
- (int) $user->getId(),
+ $user->requireId(),

src/Controller/Api/EntryController.php:113
- $row = $this->entryList->oneRowForUser($id, (int) $user->getId())
+ $row = $this->entryList->oneRowForUser($id, $user->requireId())

src/Controller/Api/EntryController.php:117
- (int) $user->getId(),
+ $user->requireId(),

src/Controller/Api/EntryController.php:165
- $row = $this->entryList->oneRowForUser($id, (int) $user->getId())
+ $row = $this->entryList->oneRowForUser($id, $user->requireId())

src/Controller/Api/EntryReaderController.php:50
- $entry = $this->entryList->findOneSubscribedByUser($id, (int) $user->getId())
+ $entry = $this->entryList->findOneSubscribedByUser($id, $user->requireId())

src/Controller/Api/EntrySearchController.php:41
- (int) $user->getId(),
+ $user->requireId(),

src/Controller/Api/RefreshController.php:54
- $userId = (int) $user->getId();
+ $userId = $user->requireId();

src/Controller/Api/SavedSearchController.php:42
- $userId = (int) $user->getId();
+ $userId = $user->requireId();

src/Controller/Api/SavedSearchController.php:48
- static fn (SavedSearch $s) => SavedSearchJson::one($s, $tallies[(int) $s->getId()]),
+ static fn (SavedSearch $s) => SavedSearchJson::one($s, $tallies[$s->requireId()]),

src/Controller/Api/SavedSearchController.php:59
- $userId = (int) $user->getId();
+ $userId = $user->requireId();

src/Controller/Api/SavedSearchController.php:91
- $userId = (int) $user->getId();
+ $userId = $user->requireId();

src/Controller/Api/SavedSearchController.php:106
- $savedSearch = $this->savedSearches->findOneOwnedBy($id, (int) $user->getId())
+ $savedSearch = $this->savedSearches->findOneOwnedBy($id, $user->requireId())

src/Controller/Api/SavedSearchEntriesController.php:52
- $userId = (int) $user->getId();
+ $userId = $user->requireId();

src/Controller/Api/SavedSearchEntriesController.php:79
- $userId = (int) $user->getId();
+ $userId = $user->requireId();

src/Controller/Api/SavedSearchEntriesController.php:121
- $userId = (int) $user->getId();
+ $userId = $user->requireId();

src/Controller/Api/SubscriptionController.php:52
- $rows = $this->subscriptionRepo->findForUserWithTags((int) $user->getId());
+ $rows = $this->subscriptionRepo->findForUserWithTags($user->requireId());

src/Controller/Api/SubscriptionController.php:53
- $counts = $this->entryStates->unreadCountsForUser((int) $user->getId());
+ $counts = $this->entryStates->unreadCountsForUser($user->requireId());

src/Controller/Api/SubscriptionController.php:54
- $entryCounts = $this->subscriptionRepo->entryCountsForUser((int) $user->getId());
+ $entryCounts = $this->subscriptionRepo->entryCountsForUser($user->requireId());

src/Controller/Api/SubscriptionController.php:55
- $flags = $this->entryStates->stateCountsForUser((int) $user->getId());
+ $flags = $this->entryStates->stateCountsForUser($user->requireId());

src/Controller/Api/SubscriptionController.php:61
- $counts[(int) $s->getId()] ?? 0,
+ $counts[$s->requireId()] ?? 0,

src/Controller/Api/SubscriptionController.php:62
- $entryCounts[(int) $s->getId()] ?? 0,
+ $entryCounts[$s->requireId()] ?? 0,

src/Controller/Api/SubscriptionController.php:81
- $this->entryStates->unreadCountsForUser((int) $user->getId()),
+ $this->entryStates->unreadCountsForUser($user->requireId()),

src/Controller/Api/SubscriptionController.php:82
- $this->subscriptionRepo->entryCountsForUser((int) $user->getId()),
+ $this->subscriptionRepo->entryCountsForUser($user->requireId()),

src/Controller/Api/SubscriptionController.php:83
- $this->entryStates->stateCountsForUser((int) $user->getId()),
+ $this->entryStates->stateCountsForUser($user->requireId()),

src/Controller/Api/SubscriptionController.php:90
- $tags = $this->tags->findAllByIdsForUser($request->tagIds, (int) $user->getId());
+ $tags = $this->tags->findAllByIdsForUser($request->tagIds, $user->requireId());

src/Controller/Api/SubscriptionController.php:123
- $sub = $this->subscriptionRepo->findOneOwnedBy($id, (int) $user->getId())
+ $sub = $this->subscriptionRepo->findOneOwnedBy($id, $user->requireId())

src/Controller/Api/SubscriptionController.php:128
- $this->tagSync->sync($sub, $request->tagIds, (int) $user->getId());
+ $this->tagSync->sync($sub, $request->tagIds, $user->requireId());

src/Controller/Api/SubscriptionController.php:155
- $userId = (int) $user->getId();
+ $userId = $user->requireId();

src/Controller/Api/SubscriptionController.php:175
- $byId = $this->ownedSubscriptions->resolve($request->subscriptionIds, (int) $user->getId());
+ $byId = $this->ownedSubscriptions->resolve($request->subscriptionIds, $user->requireId());

src/Controller/Api/SubscriptionController.php:194
- $changed = $this->bulkUpdater->apply($request, (int) $user->getId());
+ $changed = $this->bulkUpdater->apply($request, $user->requireId());

src/Controller/Api/SubscriptionController.php:213
- $byId = $this->ownedSubscriptions->resolve($request->subscriptionIds, (int) $user->getId());
+ $byId = $this->ownedSubscriptions->resolve($request->subscriptionIds, $user->requireId());

src/Controller/Api/SubscriptionController.php:221
- $subscription = $this->subscriptionRepo->findOneOwnedBy($id, (int) $user->getId())
+ $subscription = $this->subscriptionRepo->findOneOwnedBy($id, $user->requireId())

src/Controller/Api/TagController.php:42
- $rows = $this->tags->findForUser((int) $user->getId());
+ $rows = $this->tags->findForUser($user->requireId());

src/Controller/Api/TagController.php:52
- if ($this->tags->existsForUserAndName((int) $user->getId(), $request->name)) {
+ if ($this->tags->existsForUserAndName($user->requireId(), $request->name)) {

src/Controller/Api/TagController.php:59
- $tag->setPosition($this->tags->nextPositionForUser((int) $user->getId()));
+ $tag->setPosition($this->tags->nextPositionForUser($user->requireId()));

src/Controller/Api/TagController.php:75
- $owned = $this->tags->findForUser((int) $user->getId());
+ $owned = $this->tags->findForUser($user->requireId());

src/Controller/Api/TagController.php:79
- $byId[(int) $tag->getId()] = $tag;
+ $byId[$tag->requireId()] = $tag;

src/Controller/Api/TagController.php:105
- $tag = $this->tags->findOneOwnedBy($id, (int) $user->getId())
+ $tag = $this->tags->findOneOwnedBy($id, $user->requireId())

src/Controller/Api/TagController.php:129
- $tag = $this->tags->findOneOwnedBy($id, (int) $user->getId())
+ $tag = $this->tags->findOneOwnedBy($id, $user->requireId())

src/Controller/Api/TagController.php:132
- if ($this->tags->existsForUserAndName((int) $user->getId(), $request->name, $id)) {
+ if ($this->tags->existsForUserAndName($user->requireId(), $request->name, $id)) {

src/Controller/Api/TagController.php:147
- $tag = $this->tags->findOneOwnedBy($id, (int) $user->getId())
+ $tag = $this->tags->findOneOwnedBy($id, $user->requireId())

src/Controller/Api/TagController.php:153
- foreach ($this->subscriptions->findForUserByTagId((int) $user->getId(), $id) as $sub) {
+ foreach ($this->subscriptions->findForUserByTagId($user->requireId(), $id) as $sub) {

src/Http/AdminUserJson.php:100
- id: (int) $user->getId(),
+ id: $user->requireId(),

src/Http/AdminUserJson.php:146
- $tagId = (int) $tag->getId();
+ $tagId = $tag->requireId();

src/Http/AdminUserJson.php:153
- id: (int) $tag->getId(),
+ id: $tag->requireId(),

src/Http/AdminUserJson.php:158
- feedsCount: $feedsPerTag[(int) $tag->getId()] ?? 0,
+ feedsCount: $feedsPerTag[$tag->requireId()] ?? 0,

src/Http/AdminUserJson.php:176
- id: (int) $subscription->getId(),
+ id: $subscription->requireId(),

src/Http/AdminUserJson.php:185
- id: (int) $tag->getId(),
+ id: $tag->requireId(),

```

## Appendix B: domain `(int) $x->getId()` sites (Task 17), 62 sites

```diff
src/Repository/EntryListRepository.php:253
- $survivorIds[] = (int) $row->entry->getId();
+ $survivorIds[] = $row->entry->requireId();

src/Repository/ForYouFeedQuery.php:37
- return (int) $this->user->getId();
+ return $this->user->requireId();

src/Repository/SubscriptionTagRepository.php:53
- $byId[(int) $row->getSubscription()->getId()] = $row;
+ $byId[$row->getSubscription()->requireId()] = $row;

src/Service/Account/AccountDeleter.php:57
- $feedIds = $this->feeds->idsSubscribedByUser((int) $user->getId());
+ $feedIds = $this->feeds->idsSubscribedByUser($user->requireId());

src/Service/Backup/AccountRestorer.php:43
- $userId = (int) $user->getId();
+ $userId = $user->requireId();

src/Service/Backup/EntryPartInspector.php:84
- $feedIdsByUrl = $this->feeds->idsByUrlsForUser((int) $user->getId(), array_keys($feedUrls));
+ $feedIdsByUrl = $this->feeds->idsByUrlsForUser($user->requireId(), array_keys($feedUrls));

src/Service/Backup/EntryPartInspector.php:119
- $current = $this->entries->countInFeedsSubscribedBy((int) $user->getId());
+ $current = $this->entries->countInFeedsSubscribedBy($user->requireId());

src/Service/Backup/RestoreEntryLoader.php:233
- $userId = (int) $this->userReference()->getId();
+ $userId = $this->userReference()->requireId();

src/Service/Backup/RestoreEntryLoader.php:255
- $userId = (int) $this->userReference()->getId();
+ $userId = $this->userReference()->requireId();

src/Service/Backup/RestoreEntryLoader.php:276
- $lastId = (int) $entry->getId();
+ $lastId = $entry->requireId();

src/Service/Backup/RestoreEntryLoaderFactory.php:46
- new RestoreFeedTargets((int) $user->getId(), $feedIdsByUrl, $this->feeds, $this->entries),
+ new RestoreFeedTargets($user->requireId(), $feedIdsByUrl, $this->feeds, $this->entries),

src/Service/Ingest/EntryIngestor.php:75
- (int) $feed->getId(),
+ $feed->requireId(),

src/Service/Mail/Digest/DigestComposer.php:33
- $userId = (int) $user->getId();
+ $userId = $user->requireId();

src/Service/Mail/Digest/DigestComposer.php:71
- $this->links->entryUrl((int) $entry->getId()),
+ $this->links->entryUrl($entry->requireId()),

src/Service/Mail/Digest/DigestEntryFinder.php:29
- $ids = $this->members->unreadMemberIdsSince((int) $search->getId(), $userId, $since);
+ $ids = $this->members->unreadMemberIdsSince($search->requireId(), $userId, $since);

src/Service/Opml/OpmlExporter.php:28
- $subs = $this->subscriptions->findForUserWithTags((int) $user->getId());
+ $subs = $this->subscriptions->findForUserWithTags($user->requireId());

src/Service/Reader/EntryStateResolver.php:41
- $userId = (int) $user->getId();
+ $userId = $user->requireId();

src/Service/Reader/EntryStateResolver.php:42
- $entryId = (int) $row->entry->getId();
+ $entryId = $row->entry->requireId();

src/Service/Reader/EntryStateUpdater.php:70
- $siblings = $this->rows->siblingRowsForUser($hash, (int) $row->entry->getId(), (int) $user->getId());
+ $siblings = $this->rows->siblingRowsForUser($hash, $row->entry->requireId(), $user->requireId());

src/Service/Reader/MarkEntriesReadService.php:21
- $this->readMarker->markRead((int) $user->getId(), $this->entries->findExistingIds($entryIds));
+ $this->readMarker->markRead($user->requireId(), $this->entries->findExistingIds($entryIds));

src/Service/Reader/MarkReadService.php:45
- $feedIds[] = (int) $sub->getFeed()->getId();
+ $feedIds[] = $sub->getFeed()->requireId();

src/Service/Reader/MarkReadService.php:82
- $userId = (int) $user->getId();
+ $userId = $user->requireId();

src/Service/Reader/MarkReadService.php:128
- return (int) $tag->getId();
+ return $tag->requireId();

src/Service/Reader/SavedSearchMarkReadService.php:28
- $userId = (int) $user->getId();
+ $userId = $user->requireId();

src/Service/Reader/SavedSearchMarkReadService.php:34
- $this->markSearches((int) $user->getId(), [$savedSearchId], $until);
+ $this->markSearches($user->requireId(), [$savedSearchId], $until);

src/Service/Reader/SearchMarkReadService.php:28
- $userId = (int) $user->getId();
+ $userId = $user->requireId();

src/Service/Reading/ReadingActivityView.php:42
- $userId = (int) $user->getId();
+ $userId = $user->requireId();

src/Service/Recommendation/ForYouMarkReadService.php:30
- $userId = (int) $user->getId();
+ $userId = $user->requireId();

src/Service/Recommendation/RecommendationForYouSummaryProvider.php:30
- $this->items->countForYou((int) $user->getId()),
+ $this->items->countForYou($user->requireId()),

src/Service/Recommendation/RecommendationForYouSummaryProvider.php:31
- $this->items->countForYouIncludingRead((int) $user->getId()),
+ $this->items->countForYouIncludingRead($user->requireId()),

src/Service/Refresh/BudgetedFeedQueue.php:42
- $this->startedFeedIds[] = (int) $feed->getId();
+ $this->startedFeedIds[] = $feed->requireId();

src/Service/Refresh/BudgetedFeedQueue.php:44
- yield (int) $feed->getId() => new FetchTicket(
+ yield $feed->requireId() => new FetchTicket(

src/Service/Refresh/RefreshRunner.php:224
- $byId[(int) $feed->getId()] = $feed;
+ $byId[$feed->requireId()] = $feed;

src/Service/Refresh/RefreshRunner.php:392
- $baseUrls[(int) $feed->getId()] = $feed->getSiteUrl() ?? $feed->getUrl();
+ $baseUrls[$feed->requireId()] = $feed->getSiteUrl() ?? $feed->getUrl();

src/Service/Refresh/RefreshRunner.php:401
- $icon = $icons[(int) $feed->getId()] ?? null;
+ $icon = $icons[$feed->requireId()] ?? null;

src/Service/Search/EntryIndexer.php:127
- id: (int) $entry->getId(),
+ id: $entry->requireId(),

src/Service/Search/EntryIndexer.php:128
- feedId: (int) $entry->getFeed()->getId(),
+ feedId: $entry->getFeed()->requireId(),

src/Service/Search/EntrySearchRequestFactory.php:30
- userId: (int) $user->getId(),
+ userId: $user->requireId(),

src/Service/Search/Membership/SavedSearchMembershipSweep.php:191
- return array_map(static fn (SavedSearch $search): int => (int) $search->getId(), $group);
+ return array_map(static fn (SavedSearch $search): int => $search->requireId(), $group);

src/Service/Search/SavedSearchSlug.php:29
- $savedSearch->setSlug($this->build((int) $savedSearch->getId(), $savedSearch->getTerm()));
+ $savedSearch->setSlug($this->build($savedSearch->requireId(), $savedSearch->getTerm()));

src/Service/Search/SavedSearchTallies.php:30
- $ids = array_map(static fn (SavedSearch $s): int => (int) $s->getId(), $savedSearches);
+ $ids = array_map(static fn (SavedSearch $s): int => $s->requireId(), $savedSearches);

src/Service/Search/SavedSearchTallies.php:42
- return $this->forAll([$savedSearch], $userId)[(int) $savedSearch->getId()];
+ return $this->forAll([$savedSearch], $userId)[$savedSearch->requireId()];

src/Service/Search/SavedSearchTerms.php:26
- return new SavedSearchTerm((int) $savedSearch->getId(), self::of($savedSearch));
+ return new SavedSearchTerm($savedSearch->requireId(), self::of($savedSearch));

src/Service/Subscription/BulkSubscriber.php:59
- $userId = (int) $user->getId();
+ $userId = $user->requireId();

src/Service/Subscription/BulkSubscriber.php:94
- if (null !== $feed && $this->subscriptions->existsForUserAndFeed((int) $user->getId(), (int) $feed->getId())) {
+ if (null !== $feed && $this->subscriptions->existsForUserAndFeed($user->requireId(), $feed->requireId())) {

src/Service/Subscription/BulkSubscriber.php:141
- $tag = $state->tagCache[$key] ?? $this->tags->findOneByNameForUser((int) $user->getId(), $name);
+ $tag = $state->tagCache[$key] ?? $this->tags->findOneByNameForUser($user->requireId(), $name);

src/Service/Subscription/BulkSubscriptionUpdater.php:95
- return array_map(static fn (Tag $tag): int => (int) $tag->getId(), $owned);
+ return array_map(static fn (Tag $tag): int => $tag->requireId(), $owned);

src/Service/Subscription/BulkSubscriptionUpdater.php:111
- static fn (Tag $tag): int => (int) $tag->getId(),
+ static fn (Tag $tag): int => $tag->requireId(),

src/Service/Subscription/FeedTagMove.php:75
- $others = $this->tagFeedsExcept($tag, (int) $subscription->getId());
+ $others = $this->tagFeedsExcept($tag, $subscription->requireId());

src/Service/Subscription/FeedTagMove.php:86
- $others = $this->untaggedFeedsExcept($userId, (int) $subscription->getId());
+ $others = $this->untaggedFeedsExcept($userId, $subscription->requireId());

src/Service/Subscription/FeedTagMove.php:125
- && (int) $feed->getId() !== $subscriptionId,
+ && $feed->requireId() !== $subscriptionId,

src/Service/Subscription/OwnedSubscriptions.php:64
- $byId[(int) $subscription->getId()] = $subscription;
+ $byId[$subscription->requireId()] = $subscription;

src/Service/Subscription/OwnedTagsCache.php:76
- $this->resolvedByUser[$userId][(int) $tag->getId()] = $tag;
+ $this->resolvedByUser[$userId][$tag->requireId()] = $tag;

src/Service/Subscription/SubscriptionCreator.php:55
- $userId = (int) $user->getId();
+ $userId = $user->requireId();

src/Service/Subscription/SubscriptionCreator.php:85
- if ($this->subscriptions->existsForUserAndFeed($userId, (int) $feed->getId())) {
+ if ($this->subscriptions->existsForUserAndFeed($userId, $feed->requireId())) {

src/Service/Subscription/SubscriptionService.php:49
- $feedId = (int) $subscription->getFeed()->getId();
+ $feedId = $subscription->getFeed()->requireId();

src/Service/Subscription/SubscriptionService.php:79
- $feedIds[(int) $subscription->getFeed()->getId()] = true;
+ $feedIds[$subscription->getFeed()->requireId()] = true;

src/Service/Subscription/SubscriptionTagPositions.php:46
- $tagId = (int) $tag->getId();
+ $tagId = $tag->requireId();

src/Service/Subscription/SubscriptionTagSync.php:33
- $resolvedIds = array_map(static fn (Tag $tag): int => (int) $tag->getId(), $resolved);
+ $resolvedIds = array_map(static fn (Tag $tag): int => $tag->requireId(), $resolved);

src/Service/Subscription/SubscriptionTagSync.php:36
- if (!\in_array((int) $existing->getId(), $resolvedIds, true)) {
+ if (!\in_array($existing->requireId(), $resolvedIds, true)) {

src/Service/Subscription/SubscriptionTagSync.php:40
- $currentIds = array_map(static fn (Tag $tag): int => (int) $tag->getId(), $subscription->getTags()->toArray());
+ $currentIds = array_map(static fn (Tag $tag): int => $tag->requireId(), $subscription->getTags()->toArray());

src/Service/Subscription/SubscriptionTagSync.php:42
- if (!\in_array((int) $tag->getId(), $currentIds, true)) {
+ if (!\in_array($tag->requireId(), $currentIds, true)) {

```

## Appendix C: explicit id guards (Task 15), 13 sites

```diff
src/Http/EntryPage.php:89-93
-        $entryId = $row->entry->getId() ?? throw new \LogicException(
-            'An entry loaded from the database must have an id.',
-        );
-
-        return EntryCursor::encode($sort->instantOf($row), $entryId);
+        return EntryCursor::encode($sort->instantOf($row), $row->entry->requireId());

src/Service/Recommendation/RecommendationCallRecorder.php:44
-            $run->getId() ?? throw new \LogicException('Cannot record a call for an unsaved run.'),
+            $run->requireId(),

src/Service/Recommendation/RecommendationRunAdvancer.php:114
-        return self::LOCK_NAME_PREFIX . ($user->getId() ?? 0);
+        return self::LOCK_NAME_PREFIX . $user->requireId();

src/Service/Recommendation/RecommendationRunAdvancer.php:275, 313, 432, 484 (four identical lines)
-        $userId = $this->requireUserId($user);
+        $userId = $user->requireId();

src/Service/Recommendation/RecommendationRunAdvancer.php:539-543 (delete the helper)
-
-    private function requireUserId(User $user): int
-    {
-        return $user->getId() ?? throw new \LogicException('Cannot advance a run for an unsaved account.');
-    }

src/Service/Recommendation/RecommendationTickCheckpoint.php:60
-        return RecommendationRun::STATUS_CANCELLED === $this->runs->statusOf($run->getId() ?? 0);
+        return RecommendationRun::STATUS_CANCELLED === $this->runs->statusOf($run->requireId());

src/Service/Recommendation/RecommendationDebugLogView.php:51
-        $selectedId = $selected->getId() ?? 0;
+        $selectedId = $selected->requireId();

src/Repository/RecommendationItemRepository.php:246
-            runId: $item->getRun()->getId() ?? 0,
+            runId: $item->getRun()->requireId(),

src/Service/Ai/AiProviderConfigurator.php:96
-        $sealed = $this->cipher->seal($this->identify($user), $credentials->apiKey);
+        $sealed = $this->cipher->seal($user->requireId(), $credentials->apiKey);

src/Service/Ai/AiProviderConfigurator.php:134
-        $sealed = $this->cipher->seal($this->identify($user), $this->credentials($source)->apiKey);
+        $sealed = $this->cipher->seal($user->requireId(), $this->credentials($source)->apiKey);

src/Service/Ai/AiProviderConfigurator.php:214 (inside the try that #1160 added to credentials())
-            $apiKey = $this->cipher->open($this->identify($settings->getUser()), $settings->getSealedSecret());
+            $apiKey = $this->cipher->open($settings->getUser()->requireId(), $settings->getSealedSecret());

src/Service/Ai/AiProviderConfigurator.php:286-294 (delete the helper and its docblock)
-
-    /**
-     * The account id is bound into the sealed key, so an unsaved User cannot be
-     * sealed for: the id it would get on flush is not the one used here.
-     */
-    private function identify(User $user): int
-    {
-        return $user->getId() ?? throw new \LogicException('Cannot seal a key for an unsaved account.');
-    }

src/Service/Backup/BackupPartWalk.php:89
-        $statesByEntryId = $this->entryStates->forUserByEntryIds($this->userId, array_map(self::entryId(...), $batch));
+        $statesByEntryId = $this->entryStates->forUserByEntryIds(
+            $this->userId,
+            array_map(static fn (Entry $entry): int => $entry->requireId(), $batch),
+        );

src/Service/Backup/BackupPartWalk.php:92
-            yield from $this->bufferEntry($entry, $feedUrl, $statesByEntryId[self::entryId($entry)] ?? null);
+            yield from $this->bufferEntry($entry, $feedUrl, $statesByEntryId[$entry->requireId()] ?? null);

src/Service/Backup/BackupPartWalk.php:134
-        return null === $lastKey ? $fallback : self::entryId($batch[$lastKey]);
+        return null === $lastKey ? $fallback : $batch[$lastKey]->requireId();

src/Service/Backup/BackupPartWalk.php:136-140 (delete the helper)
-
-    private static function entryId(Entry $entry): int
-    {
-        return $entry->getId() ?? throw new \LogicException('A persisted entry has no id.');
-    }

src/Service/Backup/AccountBackupExporter.php:46
-        $userId = $user->getId() ?? throw new \LogicException('Cannot export an unsaved account.');
+        $userId = $user->requireId();

src/Service/Backup/RestorePreviewer.php:37
-        $userId = $user->getId() ?? 0;
+        $userId = $user->requireId();

src/EventListener/AddUserIdClaimOnTokenIssue.php:27
-            self::CLAIM => $user->getId() ?? throw new \LogicException('A signed-in user must have an id.'),
+            self::CLAIM => $user->requireId(),

src/Service/OAuth/OAuthSignIn.php:70-73
-        $userId = $user->getId();
-        \assert(null !== $userId);
-
-        return $this->loginCodes->issue($userId, $browserToken);
+        return $this->loginCodes->issue($user->requireId(), $browserToken);
```

Both `RecommendationRunAdvancer` and `AiProviderConfigurator` keep `use App\Entity\User;` after their helpers go: other method signatures in each file still name `User`. `BackupPartWalk` keeps `use App\Entity\Entry;` for the new closure.
