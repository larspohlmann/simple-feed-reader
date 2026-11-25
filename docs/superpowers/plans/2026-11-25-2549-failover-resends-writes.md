# Failover Never Resends a Write Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Cross-family and proxy-to-direct failover sends a request again only when repeating it cannot apply it twice.

**Architecture:** `CrossFamilyFailover` (the one retry policy) learns which requests may be repeated: a repeatable method (GET, HEAD, OPTIONS) keeps today's rules; any other method gets another attempt only after a transport failure that happened before the request left (curl's `pretransfer_time` is `0.0`), and never after an answer. `FailoverRequestSender` asks the policy with the method and the failed response in both its pinned-family loop and its proxied attempt.

**Tech Stack:** PHP 8.5, Symfony 8.1 HttpClient (`MockHttpClient`, `MockResponse`), PHPUnit 12.

**Spec:** GitHub issue #2549 (found while fixing #2548: a 5xx or post-connect reset on IPv6 makes `sendPinnedFamilies()` send the same POST again over IPv4, which can post twice).

## Global Constraints

- CLAUDE.md applies in full: naming verb table, `final readonly`, no comments unless a reader would get it wrong, `Response::HTTP_*` never a literal, PHPMD-clean touched files, no boolean flag parameters.
- Repeatable methods: exactly `GET`, `HEAD`, `OPTIONS` (case-insensitive). Every other method is a write for this policy.
- A write is never sent again after any answer (any status line), on the pinned path or proxied→direct.
- A write is sent again after a transport failure only when the failed response's `pretransfer_time` info is exactly `0.0` (never reached the transfer: refused connect, TLS reset — #356's heise/telepolis shape). Missing or non-numeric `pretransfer_time` counts as possibly sent: no resend.
- Timeouts stay non-retryable for everyone (unchanged).
- GET behaviour is byte-for-byte unchanged: every existing test in `FailoverRequestSenderTest`, `FailoverRequestSenderProxyTest`, `CrossFamilyFailoverTest` passes unmodified.
- Gate cadence (#2525): per task, the touched tests plus `composer cs`/`composer stan` on changed files only.

## Review Focus

- A POST whose first family resets mid-TLS (pretransfer 0.0) must still reach the server over the other family — otherwise Strato loses every write to a dual-stack host with a broken IPv6 path. Task 1 pins it.
- A POST answered 5xx/403 on the first family returns that answer (no second request), so `ServerFailure` reports "may or may not have gone through". Task 1 pins it.
- A proxied POST answered with a retryable status, fallback on, is not resent directly; a proxied POST whose proxy connect failed before transfer is. Task 2 pins both.
- Lowercase `post`/`get` method strings follow the same rule. Task 1 pins it.
- `sendDirectAfterProxyRefusal` stays caller-driven and unchanged: the caller has read the answer and decides (its only callers send GETs).

---

### Task 1: The policy knows writes, and the pinned-family loop asks it

**Files:**
- Modify: `backend/src/Service/Outbound/Fetch/CrossFamilyFailover.php`
- Modify: `backend/src/Service/Outbound/Fetch/FailoverRequestSender.php` (`sendPinnedFamilies` only)
- Test: `backend/tests/Service/Outbound/Fetch/CrossFamilyFailoverTest.php`, `backend/tests/Service/Outbound/Fetch/FailoverRequestSenderTest.php`

**Interfaces:**
- Produces: `CrossFamilyFailover::allowsRetryAfterFailure(string $method, ResponseInterface $failed, TransportExceptionInterface $transportError): bool` — `isWarranted($transportError)` and (repeatable method, or `$failed->getInfo('pretransfer_time') === 0.0`).
- Produces: `CrossFamilyFailover::allowsRetryAfterStatus(string $method, int $statusCode): bool` — repeatable method and `isRetryableStatus($statusCode)`.
- `isWarranted()` and `isRetryableStatus()` stay public with unchanged behaviour (`ConcurrentFeedFetcher` and others may call them; it sends only GETs).
- `FailoverRequestSender::sendPinnedFamilies()` uses the two new methods instead of `isWarranted`/`isRetryableStatus`.

- [ ] **Step 1: Write the failing tests**

`CrossFamilyFailoverTest`: data-provided `testARepeatableMethodMayBeAskedAgainAfterAnErrorStatus` over `GET`, `head`, `OPTIONS` with `Response::HTTP_BAD_GATEWAY` → true; `testAWriteIsNeverAskedAgainAfterAnAnswer` over `POST`, `put`, `DELETE`, `PATCH` with `HTTP_BAD_GATEWAY` and `HTTP_FORBIDDEN` → false; `testAWriteThatNeverLeftMayBeSentAgain` (POST, `MockResponse` info `pretransfer_time` 0.0, `TransportException('Connection reset by peer')`) → true; `testAWriteThatMayHaveLeftIsNotSentAgain` (pretransfer 0.042) → false and (info missing) → false; `testATimedOutWriteIsNotSentAgain` (pretransfer 0.0, `TimeoutException`) → false. Get a `MockResponse` with real info by sending it through a `MockHttpClient` and catching the transport error (see `FailoverRequestSenderTest` for the shape).

`FailoverRequestSenderTest` (dual-stack fixture as in `testFailsOverToTheNextFamilyWhenTheFirstConnectsButDiesBeforeHeaders`):
- `testAWriteThatResetBeforeItLeftIsSentOverTheNextFamily`: IPv6 pin → `new MockResponse('', ['error' => 'Connection reset by peer', 'pretransfer_time' => 0.0])`, IPv4 → 200; `send('POST', …)` → 200, 2 requests.
- `testAWriteThatResetAfterItLeftIsNotSentAgain`: same with `'pretransfer_time' => 0.05` → `TransportExceptionInterface` thrown, exactly 1 request.
- `testAWriteAnsweredWithAnErrorStatusKeepsThatAnswer`: IPv6 → `HTTP_BAD_GATEWAY`, IPv4 → 200; `send('POST', …)` → status 502, exactly 1 request.
- `testALowercaseWriteIsAWriteToo`: as the previous with method `post`.

- [ ] **Step 2: Run to verify they fail** — `php bin/phpunit tests/Service/Outbound/Fetch/CrossFamilyFailoverTest.php tests/Service/Outbound/Fetch/FailoverRequestSenderTest.php` → FAIL (undefined methods; POST resent).
- [ ] **Step 3: Implement** the Interfaces above. `REPEATABLE_METHODS = ['GET', 'HEAD', 'OPTIONS']`, compared after `strtoupper`.
- [ ] **Step 4: Run** `php bin/phpunit tests/Service/Outbound/Fetch` → PASS (existing tests unmodified); cs/stan/PHPMD on changed `src` files.
- [ ] **Step 5: Commit** — `fix(#2549): failover never sends a write twice across families`

---

### Task 2: The proxied attempt never resends a write directly

**Files:**
- Modify: `backend/src/Service/Outbound/Fetch/FailoverRequestSender.php` (`attemptProxied` only)
- Test: `backend/tests/Service/Outbound/Fetch/FailoverRequestSenderProxyTest.php`

**Interfaces:**
- Consumes: `allowsRetryAfterFailure`, `allowsRetryAfterStatus` (Task 1).
- `attemptProxied()` throws `ProxiedAttemptFailedException` (which falls through to the direct route) only when `$proxy->directFallback` and the matching `allowsRetryAfter…` call say yes; otherwise it rethrows the transport error or returns the proxied answer, as it does today with fallback off.

- [ ] **Step 1: Write the failing tests** (fixtures as in `testProxyErrorStatusFallsThroughToPinnedDirectWhenFallbackOn` and `testProxyTransportFailureFallsThroughToPinnedDirect`, fallback on):
- `testAProxiedWriteAnsweredWithAnErrorStatusIsNotSentDirectly`: proxy answers `HTTP_FORBIDDEN` to `POST` → that 403 returned, exactly 1 request.
- `testAProxiedWriteThatNeverLeftFallsThroughToDirect`: proxy attempt fails with `pretransfer_time` 0.0 → direct answer returned, 2 requests.
- `testAProxiedWriteThatMayHaveLeftIsNotSentDirectly`: proxy attempt fails with `pretransfer_time` 0.05 → transport error thrown, 1 request.
- [ ] **Step 2: Run to verify they fail** — `php bin/phpunit tests/Service/Outbound/Fetch/FailoverRequestSenderProxyTest.php` → FAIL.
- [ ] **Step 3: Implement.**
- [ ] **Step 4: Run** `php bin/phpunit tests/Service/Outbound/Fetch tests/Service/Social` → PASS; cs/stan/PHPMD on changed `src` files.
- [ ] **Step 5: Commit** — `fix(#2549): a proxied write never falls through to a second send`
