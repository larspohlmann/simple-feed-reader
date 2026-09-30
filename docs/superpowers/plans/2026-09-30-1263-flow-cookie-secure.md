# The OAuth Flow Cookie Always Carries `Secure` (#1263) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Close #1263 in one PR. `FlowCookie::issue()` sets `Secure` itself, as it did before ef25473d, so the `__Host-oauth_flow` cookie is `Secure` on every scheme. `FlowCookieTest` stops supplying the flag through `setSecureDefault(true)`. `OAuthFlowTest` checks the flag through the real wiring, on a start request made over plain http.

**Architecture:** The fix is one line in `src/Http/OAuth/FlowCookie.php`. Symfony resolves a cookie whose secure flag is `null` from the request: `ResponseListener` calls `Response::prepare()` (`vendor/symfony/http-kernel/EventListener/ResponseListener.php:56`), and that method calls `setSecureDefault(true)` only when `$request->isSecure()` (`vendor/symfony/http-foundation/Response.php:300-303`). That default is also why both test layers stayed green. The unit test set the default by hand. The functional test starts every flow over `https://localhost`, so `prepare()` set it. Each layer is changed so the default is `false` when it asserts `isSecure()`. Then only an explicit `->withSecure(true)` passes.

**Tech Stack:** PHP 8.4, Symfony 7.4 HttpFoundation `Cookie`, `WebTestCase`/`KernelBrowser`, PHPUnit 12.

**Spec:**
- GitHub issue #1263 (`gh issue view 1263 --repo larspohlmann/simple-feed-reader`).
- `docs/oauth-sign-in.md` §4.1 ("It must be `https`…": "There is no setting to turn it off") and "The flow cookie" (line 223: "`Secure` and the `__Host-` prefix hold on every deployment, localhost included").
- The regression: `git show ef25473d -- backend/src/Controller/Api/OAuthController.php` removes `->withSecure(true)` and `->withHttpOnly(true)` from the old flow-cookie builder. `FlowCookie` was extracted from that builder later.

## Decisions

- **D-1 (the "Consider…" in the issue): yes, a functional assertion.** It is not added as a new test. Instead, the start request of `OAuthFlowTest::testTheFlowCookieCarriesTheAttributesTheCrossSitePostNeeds` moves from `https://localhost` to `http://localhost`. That test already asserts `self::assertTrue($cookie->isSecure(), …)` (`tests/Controller/Api/OAuthFlowTest.php:324`), but over https `Response::prepare()` sets the flag, so the assertion cannot fail today. Over http it proves the flag through the real wiring. That is the check that would have caught ef25473d (CLAUDE.md: direct-invocation tests mislead). No second test is needed. The test's other attribute assertions do not depend on the scheme, and it reads the response's `Set-Cookie` (`responseCookie()`), not the jar. The jar drops `Secure` from a cookie received over http.
- **D-2: `->withHttpOnly(true)` is not added back.** ef25473d removed it too, but `Cookie::create()` defaults `bool $httpOnly = true` (`vendor/symfony/http-foundation/Cookie.php:72`), and both tests already assert `isHttpOnly()`. `Cookie::create()` has no `bool` default for `secure`: its `?bool $secure = null` is exactly what the request resolves. An explicit `withHttpOnly(true)` would also produce an equivalent mutant (MethodCallRemoval) on a touched line, and `composer infection:diff` would report it as escaped.
- **D-3: The comment the issue names goes** (`FlowCookieTest.php:24-25`, "The __Host- prefix mandates Secure; over HTTPS the deployment resolves…"). It justified the default the fix now makes wrong. The comment in `testTheClearMatchesTheSetOnEveryAttribute` (lines 32-34) explains why that test exists, and it stays. The issue does not name it, and the edit does not change what it says.
- **D-4: The docs do not change.** `docs/oauth-sign-in.md:223` already states the invariant this PR restores.
- **D-5: `OAuthFlowTest`'s class docblock is reworded.** It says "every request is `https`", which is no longer true after D-1. The new wording keeps the reason: the requests that rely on the jar are `https`. It stays within three lines.

## Questions for the planner

None.

## Global Constraints

- **Paths and commands are relative to `backend/`**, except steps marked "from the repository root" and `docs/…`.
- **Read before you write.** Every edit below names the exact text it replaces, as it stands at `bf742411`. If that text is not there, stop and report the file and the text you found.
- **Clean Code (CLAUDE.md) is mandatory.** Comments: default none, three lines at most. This plan adds one comment line (the test docblock in Task 2 Step 2). It is there because a future reader would otherwise "tidy" the http request back to `self::ORIGIN`.
- **PSR-12, 120 columns.** PHPUnit 12. `assertSame`, never `assertEquals`, on values.
- **Deletion checks:** the implementer runs every one, restores the code with the Edit tool (never `git checkout --`), and quotes each FAIL in the task report. A check without a quoted FAIL is not done.
- **Every grep gets a positive control** (named in its step).
- **Commits:** `type(#1263): <lower-case summary>`, no attribution or co-author lines.
- **The checkout is shared.** Run `git status --short && git branch --show-current` before any `switch`, `reset` or `stash`. Work in place, no worktrees.

---

### Task 1: Preflight, branch, plan copy

**Files:**
- Create: `docs/superpowers/plans/2026-09-30-1263-flow-cookie-secure.md` (this plan)

- [ ] **Step 1: The checkout is free and the defect is as described (from the repository root)**

```bash
git status --short && git branch --show-current
git fetch origin
gh issue view 1263 --repo larspohlmann/simple-feed-reader --json state --jq .state
git grep -n -E 'withSecure\(|secure: true' origin/develop -- backend/src/Http/OAuth/FlowCookie.php
git grep -n -E 'setSecureDefault' origin/develop -- backend/src backend/tests
```
Expected:
- A clean tree, or only another session's files, which you leave alone.
- `OPEN`.
- Exactly one line: `origin/develop:backend/src/Http/OAuth/FlowCookie.php:63:            secure: true,`. This is the positive control for the pattern: the clear sets the flag and the issue sets none.
- Exactly two lines: `backend/tests/Http/OAuth/FlowCookieTest.php:26:` and `:36:`, each `$issued->setSecureDefault(true);`.

A different result means `origin/develop` has moved past `bf742411` in these files. Stop and report the output.

- [ ] **Step 2: Branch and commit the plan copy (from the repository root)**

```bash
git switch -c fix/1263-flow-cookie-secure origin/develop
cp /private/tmp/claude-501/-Users-lars-Documents-work-eigenes-simple-feed-reader/d391b4d3-0e6b-4c11-9d20-34b69ad80840/scratchpad/plans/1263-draft.md docs/superpowers/plans/2026-09-30-1263-flow-cookie-secure.md
git add docs/superpowers/plans/2026-09-30-1263-flow-cookie-secure.md
git commit -m "docs(#1263): add plan"
```

---

### Task 2: The flow cookie sets `Secure` itself, and both test layers check it

**Files:**
- Modify: `backend/tests/Http/OAuth/FlowCookieTest.php`
- Modify: `backend/tests/Controller/Api/OAuthFlowTest.php`
- Modify: `backend/src/Http/OAuth/FlowCookie.php`

- [ ] **Step 1: `FlowCookieTest` stops supplying the flag (red)**

In `tests/Http/OAuth/FlowCookieTest.php`, `testTheIssuedCookieCarriesEveryLoadBearingAttribute()`:

Before:
```php
        $this->assertSame(Cookie::SAMESITE_NONE, $issued->getSameSite());
        // The __Host- prefix mandates Secure; over HTTPS the deployment resolves
        // the create() default to true. Model that here.
        $issued->setSecureDefault(true);
        $this->assertTrue($issued->isSecure());
    }
```
After:
```php
        $this->assertSame(Cookie::SAMESITE_NONE, $issued->getSameSite());
        $this->assertTrue($issued->isSecure());
    }
```

In the same file, `testTheClearMatchesTheSetOnEveryAttribute()`:

Before:
```php
        $issued = $this->issue();
        $issued->setSecureDefault(true);

        $response = new Response();
```
After:
```php
        $issued = $this->issue();

        $response = new Response();
```

Run:
```bash
php bin/phpunit tests/Http/OAuth/FlowCookieTest.php
```
Expected: `FAILURES!` with `Tests: 3` and `Failures: 2`:
- `FlowCookieTest::testTheIssuedCookieCarriesEveryLoadBearingAttribute`: `Failed asserting that false is true.`
- `FlowCookieTest::testTheClearMatchesTheSetOnEveryAttribute`: `Failed asserting that true is identical to false.` (the issued cookie is `false` and the clear is `true`: the mismatch the issue describes).

`testTheClearExpiresTheCookieInThePast` passes.

- [ ] **Step 2: `OAuthFlowTest` starts the attribute test over plain http (red)**

In `tests/Controller/Api/OAuthFlowTest.php`, the class docblock:

Before:
```php
/**
 * The OAuth flow over HTTP. The client never reboots, or the fake registry would last one request; the registry, not
 * a provider, is replaced; and every request is `https`, because the jar withholds the `Secure` flow cookie otherwise.
 */
final class OAuthFlowTest extends WebTestCase
```
After:
```php
/**
 * The OAuth flow over HTTP. The client never reboots, or the fake registry would last one request; the registry, not
 * a provider, is replaced; and every request that relies on the jar is `https`, because the jar withholds the
 * `Secure` flow cookie otherwise.
 */
final class OAuthFlowTest extends WebTestCase
```

In the same file, `testTheFlowCookieCarriesTheAttributesTheCrossSitePostNeeds()`:

Before:
```php
    /**
     * `SameSite=None` because Apple's callback is a cross-site POST, which a `Lax` cookie misses; `__Host-` so no
     * sibling host can write the binding.
     */
    public function testTheFlowCookieCarriesTheAttributesTheCrossSitePostNeeds(): void
    {
        $this->fakeProvider(new OAuthIdentityModel('google', 'sub-1', 'bob@example.com', true));

        $this->startFlow();

        $cookie = $this->responseCookie(OAuthController::FLOW_COOKIE);
```
After:
```php
    /**
     * `SameSite=None` because Apple's callback is a cross-site POST, which a `Lax` cookie misses; `__Host-` so no
     * sibling host can write the binding. Plain http, because over https Response::prepare() defaults `Secure` on.
     */
    public function testTheFlowCookieCarriesTheAttributesTheCrossSitePostNeeds(): void
    {
        $this->fakeProvider(new OAuthIdentityModel('google', 'sub-1', 'bob@example.com', true));

        $this->client->request('GET', 'http://localhost/api/auth/oauth/google');

        $cookie = $this->responseCookie(OAuthController::FLOW_COOKIE);
```

Run:
```bash
php bin/phpunit --filter testTheFlowCookieCarriesTheAttributesTheCrossSitePostNeeds tests/Controller/Api/OAuthFlowTest.php
```
Expected: `FAILURES!`, `Tests: 1`, `Failures: 1`, with:
```
SameSite=None is only honoured on a Secure cookie
Failed asserting that false is true.
```
If the run instead reports a 404, a 429 or a redirect to https, the http request did not reach `start()`. That falsifies D-1: stop and report the output.

- [ ] **Step 3: The fix (green)**

In `src/Http/OAuth/FlowCookie.php`, `issue()`:

Before:
```php
        return Cookie::create(self::NAME)
            ->withValue($browserToken)
            ->withExpires($this->clock->now()->getTimestamp() + self::LIFETIME_SECONDS)
            ->withPath('/')
            ->withDomain(null)
            ->withSameSite(Cookie::SAMESITE_NONE);
```
After:
```php
        return Cookie::create(self::NAME)
            ->withValue($browserToken)
            ->withExpires($this->clock->now()->getTimestamp() + self::LIFETIME_SECONDS)
            ->withPath('/')
            ->withDomain(null)
            ->withSecure(true)
            ->withSameSite(Cookie::SAMESITE_NONE);
```

Run:
```bash
php bin/phpunit tests/Http/OAuth/FlowCookieTest.php
php bin/phpunit tests/Controller/Api/OAuthFlowTest.php
```
Expected: both `OK`. `FlowCookieTest` runs 3 tests. `OAuthFlowTest` runs its whole class, which proves the https tests that rely on the jar are unaffected.

- [ ] **Step 4: No `setSecureDefault` is left in the app or its tests**

```bash
grep -rn -E 'setSecureDefault' src tests vendor/symfony/http-foundation/Response.php
```
Expected: exactly one line, `vendor/symfony/http-foundation/Response.php:302:                $cookie->setSecureDefault(true);`. That line is the positive control. Any hit under `src` or `tests` fails this step.

- [ ] **Step 5: Deletion check: remove `->withSecure(true)` (both test layers)**

In `src/Http/OAuth/FlowCookie.php`, delete the line `            ->withSecure(true)` (the After of Step 3 goes back to its Before). Run:
```bash
php bin/phpunit tests/Http/OAuth/FlowCookieTest.php
php bin/phpunit --filter testTheFlowCookieCarriesTheAttributesTheCrossSitePostNeeds tests/Controller/Api/OAuthFlowTest.php
```
Expected, and quote both in the task report:
- `FlowCookieTest`: `Failures: 2`. `testTheIssuedCookieCarriesEveryLoadBearingAttribute` fails with `Failed asserting that false is true.` and `testTheClearMatchesTheSetOnEveryAttribute` fails with `Failed asserting that true is identical to false.`
- `OAuthFlowTest`: `Failures: 1`. `testTheFlowCookieCarriesTheAttributesTheCrossSitePostNeeds` fails with `SameSite=None is only honoured on a Secure cookie` / `Failed asserting that false is true.`

Leave `->withSecure(true)` removed for one more run, which shows the scheme is what makes the functional test work. In `tests/Controller/Api/OAuthFlowTest.php`, temporarily replace `        $this->client->request('GET', 'http://localhost/api/auth/oauth/google');` with `        $this->startFlow();` and run the same `--filter` command. Expected: `OK (1 test, …)`. Over https the broken code passes, which is exactly how ef25473d went unnoticed. Quote the `OK` line.

Restore both files with the Edit tool (never `git checkout --`):
- `FlowCookie.php`: re-add `            ->withSecure(true)` between `->withDomain(null)` and `->withSameSite(Cookie::SAMESITE_NONE);`, as in Step 3's After.
- `OAuthFlowTest.php`: put `        $this->client->request('GET', 'http://localhost/api/auth/oauth/google');` back in place of `        $this->startFlow();` in `testTheFlowCookieCarriesTheAttributesTheCrossSitePostNeeds()`, as in Step 2's After.

Then confirm the restore:
```bash
git diff --stat
php bin/phpunit tests/Http/OAuth/FlowCookieTest.php tests/Controller/Api/OAuthFlowTest.php
```
Expected: `git diff --stat` lists exactly the three files of this task. PHPUnit prints `OK`.

- [ ] **Step 6: Task gates**

```bash
php -l src/Http/OAuth/FlowCookie.php
php -l tests/Http/OAuth/FlowCookieTest.php
php -l tests/Controller/Api/OAuthFlowTest.php
vendor/bin/phpcs src/Http/OAuth/FlowCookie.php tests/Http/OAuth/FlowCookieTest.php tests/Controller/Api/OAuthFlowTest.php
php bin/phpunit tests/Http/OAuth/FlowCookieTest.php tests/Controller/Api/OAuthFlowTest.php
```
Expected: `No syntax errors detected` three times, phpcs prints nothing, and PHPUnit prints `OK`.

- [ ] **Step 7: Commit (from the repository root)**

```bash
git add backend/src/Http/OAuth/FlowCookie.php backend/tests/Http/OAuth/FlowCookieTest.php backend/tests/Controller/Api/OAuthFlowTest.php
git commit -m "fix(#1263): the OAuth flow cookie always carries Secure"
```

---

### Task 3: Gates, review, PR

- [ ] **Step 1: PR gates (from `backend/`)**

Check that the Docker containers are current before the MySQL leg (the standing rule). Run the native leg first, then the MySQL leg, one after the other:
```bash
composer cs
bin/console cache:warmup
composer stan
composer md
composer tramp
php bin/phpunit
docker compose exec php composer test
composer infection:diff
ls -t var/log/dev-*.log | head -1 | xargs tail -n 200 | jq -c 'select(.level >= 300)'
```
Expected: all green. `composer infection:diff` mutates the touched line `->withSecure(true)`. Both its MethodCallRemoval and its TrueValue mutant are killed by `FlowCookieTest`, and no mutant escapes. The dev log shows nothing new at level 300 or above.

If only `composer tramp` fails, run `composer show larspohlmann/phptramp` first. This change forwards no parameter.

- [ ] **Step 2: PhpStorm inspections**

Run `mcp__phpstorm__lint_files` on `backend/src/Http/OAuth/FlowCookie.php`, `backend/tests/Http/OAuth/FlowCookieTest.php` and `backend/tests/Controller/Api/OAuthFlowTest.php`. ERROR and WARNING block. Weak warnings are advisory.

- [ ] **Step 3: Final review**

Dispatch one fresh reviewer subagent with this plan, the issue and `git diff origin/develop...HEAD`. It reports findings and does not fix them. Attack points:
1. The set and the clear agree on all six attributes (`FlowCookie::issue()` against `clearFrom()`).
2. No test anywhere still supplies `Secure` through `setSecureDefault` or through an https-only request on an assertion of `isSecure()`.
3. The functional test really goes through `start()` over http: the request is `http://localhost/…`, and the assertion reads the response's cookie, not the jar.
4. Re-run Task 2 Step 5's first deletion check and quote the FAIL.

Fix each finding rated Important or above in its own commit (`fix(#1263): review — <finding>`). Re-run the Step 1 gates, and record the rest in the PR body.

- [ ] **Step 4: Open the PR (from the repository root)**

```bash
git push -u origin fix/1263-flow-cookie-secure
gh pr create --repo larspohlmann/simple-feed-reader --base develop --head fix/1263-flow-cookie-secure \
  --title "fix(#1263): the OAuth flow cookie always carries Secure" \
  --body "$(cat <<'EOF'
`FlowCookie::issue()` sets `Secure` again. ef25473d dropped `->withSecure(true)`, which left the flag `null`, so Symfony resolved it from the request. On plain http, or behind a TLS proxy outside `SYMFONY_TRUSTED_PROXIES`, the `__Host-oauth_flow` cookie went out without `Secure`, browsers refused it, and every OAuth sign-in failed with `invalid_state`. The set also stopped matching `clearFrom()`, which always passes `secure: true`.

- `FlowCookieTest` no longer calls `setSecureDefault(true)` before it asserts `isSecure()`.
- `OAuthFlowTest::testTheFlowCookieCarriesTheAttributesTheCrossSitePostNeeds` starts its flow over plain http. Over https, `Response::prepare()` defaults `Secure` on, so its `isSecure()` assertion could not fail.
- Deletion check: without `->withSecure(true)` both layers fail. With the functional test back on https, the broken code passes, which is how the regression went unnoticed.

Closes #1263
EOF
)"
```

- [ ] **Step 5: Merge when CI is green, then report**

Watch CI with a Monitor, merge with `gh pr merge --merge` (never `--auto`) once every check is green, verify #1263 is CLOSED (COMPLETED), and report the PR URL, the merge SHA, the quoted deletion-check FAILs and the gate results to the planner.
