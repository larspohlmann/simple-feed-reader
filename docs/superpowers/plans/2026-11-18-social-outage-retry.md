# Social outage retry and plain-language messages (#2341) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** A social lookup that hits a short outage on the instance is retried after a pause, and the dialog says in plain words, with no status code, what happened and whether the reply went out.

**Architecture:** One shared `PatientApiClient` in `Service/Social/Social` wraps `GuardedApiClient` and re-sends a GET that got 502/503/504, with a short pause from the Symfony clock; the Lemmy, Mastodon and Bluesky clients send through it. In `send()`, each client turns any 5xx into one of two new `SocialNetworkUnreachableException` subclasses that carry the instance host: `SocialNetworkUnavailableException` (a read failed, so nothing was written) or `SocialWriteOutcomeUnknownException` (a write got a 5xx, so it may have landed). Each gets its own problem type with an `instance` member, and the composer translates the two types in en/de/fr/es.

**Tech Stack:** Symfony 8.1 / PHP 8.5 (`symfony/clock`, `MockClock` in tests), Angular 20 + Transloco, Jest.

**Spec:** GitHub issue #2341 (the 503 on the Lemmy reply lookup, 2026-11-18 Strato log).

## Global Constraints

- No HTTP status number in any user-facing text these changes produce. The status goes to the log only.
- Never re-send a non-GET request: a write that got a 5xx may already be stored.
- Retry only 502, 503 and 504. Pauses are 1 s and then 2 s (at most 2 retries, 3 attempts). A `Retry-After` of 3 s or less replaces the next pause; a larger one ends the retries at once.
- Copy (en): unavailable: `{instance} isn't responding right now — it's probably overloaded. Try again in a minute.`; write outcome unknown: `{instance} had trouble with this request, so it may or may not have gone through. Check before trying again.`; composer, unavailable on send: `{{instance}} isn't responding right now — it's probably overloaded. Your reply was not sent; try again in a minute.`; composer, outcome unknown on send: `{{instance}} had trouble with your post, so it may or may not have been published. Check before sending it again.`
- The instance is the request URL's host (`lemmy.world`, `mastodon.social`, `bsky.social`).
- Translations: en, de, fr, es (de uses "du").
- The background timeline rounds (`acceptedInRound`, `answerOf` over the concurrent engine) keep their behaviour; `accepted()`'s default arm drops the number: `The Lemmy instance ran into a problem of its own.` (Mastodon: `The Mastodon instance …`, Bluesky: `Bluesky …`).

## Review Focus

1. A POST that gets a 503 is sent exactly once (pin in Task 1).
2. A 503 with `Retry-After: 120` gives up at once instead of hanging the dialog for two minutes (pin in Task 1).
3. A 429 keeps throwing `SocialNetworkThrottledException`, not the new unavailable exception (pin in Task 2).
4. Catch sites written against `SocialNetworkUnreachableException` still catch both new exceptions; they are subclasses (pin with `assertInstanceOf` in Task 2).
5. A send failure of any other type still shows the backend detail as before (pin in Task 4).

---

### Task 1: `PatientApiClient` re-sends a GET after a short pause

**Files:**
- Create: `backend/src/Service/Social/Social/PatientApiClient.php`
- Test: `backend/tests/Service/Social/Social/PatientApiClientTest.php`

**Interfaces:**
- Produces: `final readonly class PatientApiClient { __construct(GuardedApiClient $api, ClockInterface $clock); send(ApiRequestModel $request): ApiResponseModel }`. It throws whatever `GuardedApiClient::send` throws (`FetchException`) and returns the last answer whatever its status.

- [ ] **Step 1: Write the failing tests** (wire `FetchWiring::guardedApiClient` over a `MockHttpClient` with a response list, plus `new MockClock('2026-11-18 14:52:21')`):
  - `testAGetThatGets503IsSentAgainAfterOneSecond`: responses 503, 200 → status 200, 2 requests, clock advanced exactly 1 s.
  - `testAGetGivesUpAfterThreeAttempts`: 503 ×3 → status 503, 3 requests, clock advanced 3 s.
  - `testAPostThatGets503IsSentOnce`: `ApiRequestModel::postJson` → status 503, 1 request, clock not advanced.
  - `testRetryAfterWithinThreeSecondsSetsThePause`: 503 with `Retry-After: 3`, then 200 → clock advanced 3 s.
  - `testALongRetryAfterEndsTheRetries`: 503 with `Retry-After: 120` → status 503, 1 request, clock not advanced.
  - `testA500IsNotRetried`: 500 → 1 request.
- [ ] **Step 2:** Run `php bin/phpunit tests/Service/Social/Social/PatientApiClientTest.php`. Expected: FAIL, class not found.
- [ ] **Step 3: Implement.** Constants `PAUSES_SECONDS = [1, 2]`, `LONGEST_HONOURED_RETRY_AFTER_SECONDS = 3`, statuses 502/503/504 as `Response::HTTP_*`. Read the header with the existing `RetryAfterHeader::seconds()`. Pause with `$this->clock->sleep()`. Keep each method one thing (the "is this answer worth another try" check, and the "how long to pause" choice that returns null to stop).
- [ ] **Step 4:** Run the test. Expected: PASS.
- [ ] **Step 5:** Commit `feat(#2341): re-send a social GET after a short pause on 502–504`.

### Task 2: Clients name the instance and say whether a write may have landed

**Files:**
- Create: `backend/src/Service/Social/Social/Exception/SocialNetworkUnavailableException.php`, `…/Exception/SocialWriteOutcomeUnknownException.php`
- Modify: `backend/src/Service/Social/Social/LemmyApiClient.php`, `MastodonApiClient.php`, `BlueskyApiClient.php` (constructor takes `PatientApiClient`; `send()` maps 5xx; `accepted()` default arm drops the number)
- Test: `backend/tests/Service/Social/Social/LemmyApiClientTest.php`, `MastodonApiClientTest.php`, a new `BlueskyApiClientTest.php` only if the Bluesky change is not already covered through `BlueskyPostWriterTest`; update every test that pins `answered NNN` (the grep in the issue session found `LemmyApiClientTest`, `PostWriter/BlueskyPostWriterTest`, `CandidateSource/BlueskyCandidateSourceTest`).

**Interfaces:**
- Consumes: `PatientApiClient::send` (Task 1).
- Produces:
  - `final class SocialNetworkUnavailableException extends SocialNetworkUnreachableException { public readonly string $instance; static fromHost(string $instance): self }`, with the Global Constraints wording.
  - `final class SocialWriteOutcomeUnknownException extends SocialNetworkUnreachableException { public readonly string $instance; static fromHost(string $instance): self }`, with the Global Constraints wording.
  - The rule in each client's `send(ApiRequestModel $request)`: an answer with status ≥ 500 throws `SocialWriteOutcomeUnknownException` when `$request->method !== 'GET'`, else `SocialNetworkUnavailableException`; host from `parse_url($request->url, PHP_URL_HOST)`. Everything else goes through `accepted()` as today. Put the shared mapping in one place (a `Support/` static helper, e.g. `ServerFailure::throwIfServerError(ApiRequestModel, ApiResponseModel): void`) so the three clients do not repeat it.

- [ ] **Step 1: Write the failing tests** in `LemmyApiClientTest` (the router needs a POST route too):
  - `testAGetThatKeepsGetting503NamesTheInstance`: expect `SocialNetworkUnavailableException`, message `lemmy.example isn't responding right now — it's probably overloaded. Try again in a minute.`, `assertInstanceOf(SocialNetworkUnreachableException::class, …)`, and `assertStringNotContainsString('503', $message)`.
  - `testAPostThatGets502MayHaveGoneThrough`: expect `SocialWriteOutcomeUnknownException`, message `lemmy.example had trouble with this request, so it may or may not have gone through. Check before trying again.`, sent once.
  - `testA429StillThrottles`: 429 → `SocialNetworkThrottledException`.
  - Rewrite the existing `answered 500` test to `accepted()`'s new wording `The Lemmy instance ran into a problem of its own.`
  - Mirror the first two in `MastodonApiClientTest` with its host.
- [ ] **Step 2:** Run those test files. Expected: FAIL.
- [ ] **Step 3: Implement** the exceptions, the helper and the three client changes. The service container autowires `PatientApiClient` and `ClockInterface`; tests construct the clients with `new PatientApiClient(FetchWiring::guardedApiClient(…), new MockClock())`. Fix every construction site of the three clients in `tests/` (grep `new LemmyApiClient(`, `new MastodonApiClient(`, `new BlueskyApiClient(`).
- [ ] **Step 4:** Run `php bin/phpunit tests/Service/Social`. Expected: PASS. Then `composer stan` and `composer md` on the touched files.
- [ ] **Step 5:** Commit `feat(#2341): name the instance and the write's outcome when a social network fails`.

### Task 3: Problem types with the instance

**Files:**
- Modify: `backend/src/Http/Problem/ApiProblem.php` (an `array<string, string> $extensions = []` merged into `toArray()`), `backend/src/Http/Problem/ExceptionProblems/SocialInteractionProblems.php` (two arms before the `SocialNetworkUnreachableException` arm)
- Test: the existing tests of those two classes (find them under `tests/Http/Problem/`), and a functional test of the reply route if one already exercises a failing network (extend it rather than adding a new one).

**Interfaces:**
- Consumes: the two exceptions (Task 2).
- Produces the wire shape: `{"type":"social_network_unavailable","title":"The network is not responding","status":502,"detail":"<message>","instance":"lemmy.world"}` and `{"type":"social_write_outcome_unknown","title":"The network may not have finished the request","status":502,"detail":"<message>","instance":"lemmy.world"}`.

- [ ] **Step 1: Write the failing tests:** each arm maps to the type, status 502, detail = exception message, `toArray()['instance']` = host; `ApiProblem` without extensions has no extra keys.
- [ ] **Step 2:** Run. Expected: FAIL.
- [ ] **Step 3:** Implement.
- [ ] **Step 4:** Run `php bin/phpunit tests/Http`. Expected: PASS.
- [ ] **Step 5:** Commit `feat(#2341): problem types for an unavailable network and an unknown write outcome`.

### Task 4: Composer tells you whether your reply was sent

**Files:**
- Modify: `frontend/src/app/core/problem.ts` (`Problem.instance?: string`, read in `problemFromBody` with `stringField`), `frontend/src/app/reader/article/composer/composer-lane.ts` (send failures use a new `sendFailureMessage`), `frontend/public/i18n/{en,de,fr,es}.json` (`reader.composer.notSent`, `reader.composer.mayHaveSent`)
- Test: `frontend/src/app/core/problem.spec.ts`, `frontend/src/app/reader/article/composer/composer-lanes.spec.ts` (or the existing spec that covers `send` failures)

**Interfaces:**
- Consumes: the wire shape from Task 3.

- [ ] **Step 1: Write the failing tests:**
  - `parseProblem` reads `instance: 'lemmy.world'`.
  - A send that fails with `social_network_unavailable` + `instance` sets `sendError` to `lemmy.world isn't responding right now — it's probably overloaded. Your reply was not sent; try again in a minute.`
  - `social_write_outcome_unknown` gives `lemmy.world had trouble with your post, so it may or may not have been published. Check before sending it again.`
  - Any other problem type still gives its detail (unchanged).
- [ ] **Step 2:** `docker compose exec -T frontend npx jest <spec paths>` cannot see the worktree (the container mounts the main checkout). Run `./node_modules/.bin/jest --testPathIgnorePatterns "/node_modules/" "/e2e/" <spec paths>` in `frontend/` of the worktree instead. Expected: FAIL.
- [ ] **Step 3:** Implement. Only the two types with an `instance` take the new keys; a load failure keeps `problemText`. de/fr/es: write natural translations of the two en strings (du-form in German), keep `{{instance}}`.
- [ ] **Step 4:** Run the specs. Expected: PASS. Then `npm run lint`, `npx prettier --check` on the touched files, and `npx tsc -p tsconfig.app.json --noEmit` and `-p tsconfig.spec.json`.
- [ ] **Step 5:** Commit `feat(#2341): the composer says whether your reply was sent`.

### Task 5: Full gates

- [ ] `composer check`, `composer md` (touched files clean), `php bin/phpunit` (SQLite). The MySQL leg runs in the Docker php container, which mounts the main checkout, so it cannot test this worktree. Say so in the PR; CI runs it.
- [ ] The whole Jest suite via the local binary, as in Task 4.
- [ ] `composer infection:diff` if pcov is available natively; otherwise leave it to CI and say so.
