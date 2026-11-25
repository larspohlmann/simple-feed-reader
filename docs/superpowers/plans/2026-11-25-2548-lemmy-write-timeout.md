# Write Timeouts Are an Unknown Outcome Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** A social write that times out says "may or may not have gone through" instead of "could not be reached", Lemmy post creation waits long enough for lemmy.world's link-metadata fetch, and a timed-out Lemmy post that did land is found and reported as posted.

**Architecture:** `NetworkAnswer` gains a request-aware entry point that turns a timed-out *write* into `SocialWriteOutcomeUnknownException`; the three API clients' `answer()` use it. `LemmyAccountApi` gets a post-creation call with a 30 s budget. `LemmyPostWriter` catches the unknown outcome on a top-level post and asks a new `LemmyOwnPosts` service for the account's own just-published post.

**Tech Stack:** PHP 8.5, Symfony 8.1 HttpClient (`MockResponse`), PHPUnit 12.

**Spec:** GitHub issue #2548 (root cause and the three fixes; prod evidence: `POST https://lemmy.world/api/v3/post` → `TimeoutException: Idle timeout reached` after 10 s, post created ~130 ms in, three duplicates).

## Global Constraints

- CLAUDE.md applies in full: naming verb table, `final readonly`, no comments unless a reader would get it wrong, guard clauses, `Response::HTTP_*` never a literal, PHPMD-clean touched files.
- Only a **timeout** on a **write** (`!ApiRequestModel::isRead()`) becomes an unknown outcome. A read timeout and any non-timeout transport failure stay `SocialNetworkUnreachableException` with today's message.
- Lemmy post creation budget: `30.0` seconds. Comments keep the standard 10 s.
- Recovery matches the account's own post by exact title **and** community id **and** `published` no earlier than 120 seconds before the request was sent. URL is not compared (Lemmy rewrites URLs, e.g. `#:~:text=` fragments are re-encoded).
- Recovery never throws its own failure: if the lookup fails or finds nothing, the original `SocialWriteOutcomeUnknownException` propagates.
- Gate cadence (#2525): per task, the touched tests plus `composer cs`/`composer stan` on changed files only.

## Review Focus

- A Lemmy timeout on a **comment** (reply): unknown outcome, no recovery lookup (no title/community) — Task 3 pins it.
- An identical older post by the same account in the same community (a repost of the same headline days ago) must not be taken as the new one — Task 3 pins the 120 s window.
- A 5xx on post creation (already `SocialWriteOutcomeUnknownException` via `ServerFailure`) also gets the recovery lookup — Task 3 pins it.
- A lookup that itself times out or 401s must surface the original "may or may not" error, not the lookup's — Task 3 pins it.
- Deleted posts in the user listing (`post.deleted: true`) are not a match — Task 3 pins it.

---

### Task 1: A timed-out write is an unknown outcome

**Files:**
- Modify: `backend/src/Service/Outbound/Fetch/Exception/FetchException.php`
- Modify: `backend/src/Service/Social/Social/Exception/SocialServiceFailureException.php`
- Modify: `backend/src/Service/Social/Social/Exception/SocialWriteOutcomeUnknownException.php`
- Modify: `backend/src/Service/Social/Social/Exception/SocialNetworkUnavailableException.php` (constructor call only)
- Modify: `backend/src/Service/Social/Social/Support/NetworkAnswer.php`
- Modify: `backend/src/Service/Social/Lemmy/LemmyApiClient.php`, `Mastodon/MastodonApiClient.php`, `Bluesky/BlueskyApiClient.php` (`answer()` only)
- Test: `backend/tests/Service/Social/Social/Support/NetworkAnswerTest.php`, `backend/tests/Service/Outbound/Fetch/Exception/FetchExceptionTest.php` (create if absent), `backend/tests/Service/Social/Lemmy/LemmyApiClientTest.php`

**Interfaces:**
- Produces: `FetchException::isTimeout(): bool` — true when any exception in its `getPrevious()` chain is a `Symfony\Contracts\HttpClient\Exception\TimeoutExceptionInterface`.
- Produces: `SocialWriteOutcomeUnknownException::unanswered(string $serviceName, FetchException $previous): self` — same user message as `naming()`, `getPrevious()` is `$previous`.
- Produces: `NetworkAnswer::fetchAnswer(ApiRequestModel $request, \Closure $send, string $network): ApiResponseModel` with `@param \Closure(ApiRequestModel): ApiResponseModel $send`; throws `SocialWriteOutcomeUnknownException::unanswered($network, …)` for a write whose `FetchException::isTimeout()`, otherwise behaves like `askNetwork`.
- `SocialServiceFailureException::__construct` becomes `final protected function __construct(public readonly string $serviceName, string $message, \Throwable $previous)`; `naming()` in both subclasses passes `new \RuntimeException(sprintf('%s answered %d.', $serviceName, $status))` as before.

- [ ] **Step 1: Write the failing tests**

`NetworkAnswerTest`:
- `testAWriteThatTimedOutMayHaveGoneThrough`: request `ApiRequestModel::postJson('https://lemmy.example/api/v3/post', [])`, `$send` throws `FetchException::from($url, new \Symfony\Component\HttpClient\Exception\TimeoutException('Idle timeout reached'))`; expect `SocialWriteOutcomeUnknownException`, `serviceName === 'The Lemmy instance'`, message `'The Lemmy instance had trouble with this request, so it may or may not have gone through. Check before trying again.'`, `getPrevious()` is the thrown `FetchException`.
- `testAReadThatTimedOutIsUnreachable`: same with `ApiRequestModel::get(...)`; expect exactly `SocialNetworkUnreachableException` (assert `get_class`), message `'The Lemmy instance could not be reached.'`.
- `testAWriteThatNeverConnectedIsUnreachable`: write, cause `new \Symfony\Component\HttpClient\Exception\TransportException('Could not resolve host')`; expect exactly `SocialNetworkUnreachableException`.
- `testAWriteThatIsMarkedChangingNothingIsARead`: `postJson(...)->changingNothing()` + timeout → exactly `SocialNetworkUnreachableException`.

`FetchExceptionTest`: `testATimeoutDeepInTheChainIsATimeout` (TimeoutException wrapped in a `\RuntimeException`, then `FetchException::from`) → true; `testATransportFailureIsNoTimeout` → false.

`LemmyApiClientTest`: `testAPostThatTimesOutMayHaveGoneThrough` — route `POST https://lemmy.example/api/v3/post` to `new MockResponse([''])` (an empty chunk is a timeout in `MockResponse`), `send(ApiRequestModel::postJson(...))` → `SocialWriteOutcomeUnknownException`.

- [ ] **Step 2: Run them to verify they fail**

Run: `php bin/phpunit tests/Service/Social/Social/Support/NetworkAnswerTest.php tests/Service/Outbound/Fetch/Exception/FetchExceptionTest.php tests/Service/Social/Lemmy/LemmyApiClientTest.php`
Expected: FAIL (undefined methods / wrong exception class).

- [ ] **Step 3: Implement** the Interfaces above; each client's `answer()` becomes `NetworkAnswer::fetchAnswer($request, $this->api->send(...), self::NETWORK)` followed by the existing `ServerFailure::throwIfServerError(...)`. `askNetwork` stays for the outcome-based reads.

- [ ] **Step 4: Run the tests plus the touched clients' and problem-mapper tests**

Run: `php bin/phpunit tests/Service/Social tests/Service/Outbound/Fetch tests/Http/Problem` — Expected: PASS. Then `composer cs` and `composer stan` on the changed files.

- [ ] **Step 5: Commit** — `fix(#2548): a timed-out social write may have gone through`

---

### Task 2: Lemmy post creation waits 30 seconds

**Files:**
- Modify: `backend/src/Service/Social/Lemmy/LemmyAccountApi.php`
- Modify: `backend/src/Service/Social/Lemmy/LemmyPostWriter.php` (top-level post path only)
- Test: `backend/tests/Service/Social/Lemmy/LemmyPostWriterTest.php`

**Interfaces:**
- Produces: `LemmyAccountApi::publishPost(SocialAccount $account, array $json): ApiResponseModel` — `POST /api/v3/post` with `->waitingUpTo(self::POST_CREATION_TIMEOUT_SECONDS)`, `POST_CREATION_TIMEOUT_SECONDS = 30.0`; same `@throws` as `post()`.

- [ ] **Step 1: Write the failing tests**
- `testAPostWaitsThirtySecondsForTheInstance`: link post as in `testALinkIsALinkPostInTheChosenCommunity`, keep the `MockResponse`, assert `30.0 === $response->getRequestOptions()['timeout']`.
- `testACommentKeepsTheStandardWait`: reply as in `testAReplyToAPostIsACommentOnIt`, assert `ApiBudgetModel::STANDARD_TIMEOUT_SECONDS === $response->getRequestOptions()['timeout']`.

- [ ] **Step 2: Run to verify the first fails** — `php bin/phpunit tests/Service/Social/Lemmy/LemmyPostWriterTest.php` → FAIL (10.0 ≠ 30.0).
- [ ] **Step 3: Implement** `publishPost`; `LemmyPostWriter::publish()` calls it for the top-level post.
- [ ] **Step 4: Run** the writer test file → PASS; cs/stan on changed files.
- [ ] **Step 5: Commit** — `fix(#2548): lemmy post creation waits for the link preview`

---

### Task 3: A timed-out Lemmy post that landed counts as posted

**Files:**
- Create: `backend/src/Service/Social/Lemmy/Model/LemmyPostAttemptModel.php`
- Create: `backend/src/Service/Social/Lemmy/LemmyOwnPosts.php`
- Modify: `backend/src/Service/Social/Lemmy/LemmyPostWriter.php`
- Test: `backend/tests/Service/Social/Lemmy/LemmyOwnPostsTest.php` (create), `backend/tests/Service/Social/Lemmy/LemmyPostWriterTest.php` (wiring + cases)

**Interfaces:**
- Consumes: `SocialWriteOutcomeUnknownException` (Task 1), `LemmyAccountApi::publishPost` (Task 2), `LemmyAccountApi::get(SocialAccount, string $pathAndQuery)`.
- Produces: `final readonly class LemmyPostAttemptModel(public string $title, public int $communityId, public \DateTimeImmutable $sentAt)`.
- Produces: `LemmyOwnPosts::findJustPublished(SocialAccount $account, LemmyPostAttemptModel $attempt): ?PublishedPostModel` — `GET /api/v3/user?username=<rawurlencode(identity)>&sort=New&limit=10` through `LemmyAccountApi::get`; first `posts[]` entry whose `post.name === title`, `post.community_id === communityId`, `post.deleted !== true`, and `post.published >= sentAt - 120 s` → `new PublishedPostModel($apId, $apId)` (https `ap_id` only, via `AbsoluteHttpUrl::keepIfHttps`); otherwise null. Lookup failures propagate (the writer handles them). `RECENT_WINDOW_SECONDS = 120`, `LOOKUP_LIMIT = 10`.
- `LemmyPostWriter` constructor becomes `(LemmyAccountApi $lemmy, LemmyOwnPosts $ownPosts, ClockInterface $clock)`.

- [ ] **Step 1: Write the failing tests**

`LemmyOwnPostsTest` (route `GET https://lemmy.example/api/v3/user?username=lars%40lemmy.example&sort=New&limit=10`, `MockClock('2026-11-25 11:36:20')`, attempt sent at `2026-11-25 11:36:09`):
- `testFindsTheAccountsPostWithTheSameTitleInTheSameCommunity` → `https://lemmy.example/post/501`.
- `testAnOlderPostWithTheSameTitleIsNotTheOneJustSent` (published `2026-11-25 11:30:00Z`) → null.
- `testAPostPublishedUpToTwoMinutesBeforeTheSendCounts` (published `11:34:09Z`) → found; `11:34:08Z` → null.
- `testAPostInAnotherCommunityIsNoMatch` → null.
- `testADeletedPostIsNoMatch` → null.

`LemmyPostWriterTest`:
- `testATimedOutPostThatLandedIsPosted`: `POST …/api/v3/post` → `new MockResponse([''])`, user listing returns the matching post → `PublishedPostModel` with its `ap_id`.
- `testATimedOutPostThatDidNotLandMayHaveGoneThrough`: listing has no match → `SocialWriteOutcomeUnknownException`.
- `testAFailedLookupKeepsTheUnknownOutcome`: listing → `MockResponse('', ['http_code' => Response::HTTP_UNAUTHORIZED])` → `SocialWriteOutcomeUnknownException` (not `SocialCredentialsRejectedException`).
- `testAPostAnsweredWithAServerErrorIsLookedUpToo`: post → `HTTP_BAD_GATEWAY`, listing matches → posted.
- `testATimedOutCommentIsNotLookedUp`: `POST …/api/v3/comment` → timeout, no user route registered → `SocialWriteOutcomeUnknownException`.

- [ ] **Step 2: Run to verify they fail** — `php bin/phpunit tests/Service/Social/Lemmy/LemmyOwnPostsTest.php tests/Service/Social/Lemmy/LemmyPostWriterTest.php` → FAIL.
- [ ] **Step 3: Implement.** In `publish()`, build the payload, take `$this->clock->now()` as `sentAt`, then:

```php
try {
    $answer = $this->lemmy->publishPost($account, $payload);
} catch (SocialWriteOutcomeUnknownException $unknown) {
    return $this->findLanded($account, $attempt) ?? throw $unknown;
}
```

`findLanded()` wraps `findJustPublished` and returns null on any exception `LemmyAccountApi::get()` declares (`SocialCredentialsRejectedException`, `SocialPostRefusedException`, `SocialPostNotFoundException`, `SocialNetworkThrottledException`, `SocialNetworkUnreachableException`).

- [ ] **Step 4: Run** both test files plus `tests/Service/Social` → PASS; cs/stan/`composer md` on changed `src` files.
- [ ] **Step 5: Commit** — `fix(#2548): a timed-out lemmy post that landed counts as posted`
