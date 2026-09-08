# Reader landing-page validation (#892) — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Before reader extraction, follow a zero-delay `<meta http-equiv="refresh">` interstitial to the real article, and reject a `200` bot/consent challenge body as a fetch failure — both under one shared redirect budget.

**Architecture:** The reader already reads and size-caps the body in `HtmlPageFetcher`, so the new "validate the landing page" step lives there, not in the header-only `RedirectFollower` (which `MediaLanding` shares and never reads a body from). `RedirectFollower` gains one job: report how many redirects it consumed, so `HtmlPageFetcher` keeps a single hop budget across HTTP-redirect and meta-refresh hops. Two new pure classes — `MetaRefreshTarget` (parse a zero-delay refresh target) and `LandingChallenge` (recognise a challenge body) — are injected into `HtmlPageFetcher` and unit-tested in isolation.

**Tech Stack:** PHP 8.4, Symfony 7.4, `\Dom\HTMLDocument` (lexbor) via `App\Service\Html\HtmlDocumentParser`, PHPUnit with `Symfony\Component\HttpClient\MockHttpClient`.

**Spec:** [docs/superpowers/specs/2026-09-08-892-reader-landing-page-validation-design.md](../specs/2026-09-08-892-reader-landing-page-validation-design.md)

## Global Constraints

- `declare(strict_types=1);` in every PHP file; PSR-12 (`composer cs`).
- PHPStan level max over `src` and `tests`, no new baseline / no bare `@phpstan-ignore` (`composer stan`, needs `bin/console cache:warmup` first).
- **PHPMD codesize clean on every touched `src` file** (`composer md`) — not merely free of new findings.
- Clean Code: names reveal intent; functions do one thing and stay short; guard clauses over nesting; `final readonly` with constructor promotion; depend on injected interfaces; typed namespaced exceptions; default to no comment.
- phptramp: no parameter threaded unread across 2+ classes (`composer tramp`).
- Mutation testing gates changed lines (`composer infection:diff`, `minMsi` in `infection.json5`) — assertions must be exact, especially the hop arithmetic and the delay `=== 0` / budget `< 1` boundaries.
- Native-iOS constraint holds: this is server-internal; the endpoint stays JSON-in / JSON-out with no new client coupling. No frontend change.
- Scan today's dev log after backend work: `ls -t backend/var/log/dev-*.log | head -1`.
- Shared checkout: another session is live here. Do **not** `checkout`/`reset`/`stash`; branch is `feature/892-reader-landing-page-validation` (already created).
- All paths below are relative to `backend/`. Run commands from `backend/`.

---

### Task 1: `RedirectFollower` reports the hops it consumed

**Files:**
- Modify: `src/Service/Fetch/LandedResponse.php` (add a `hops` field)
- Modify: `src/Service/Fetch/RedirectFollower.php:42` (pass the hop count into `LandedResponse`)
- Test: `tests/Service/Fetch/RedirectFollowerTest.php` (add two cases)

**Interfaces:**
- Consumes: nothing new.
- Produces: `LandedResponse::$hops` (public `int`, default `0`) — the number of HTTP redirects `RedirectFollower::follow()` followed before landing. `follow(string $url, array $options, int $maxRedirects): LandedResponse` signature is unchanged; Task 4 reads `->hops`.

- [ ] **Step 1: Write the failing tests**

Add to `tests/Service/Fetch/RedirectFollowerTest.php` (the `follower()` helper and `redirect()` factory already exist in this file):

```php
public function testReportsZeroHopsWhenTheFirstResponseLands(): void
{
    $landed = $this->follower([new MockResponse('ok', ['http_code' => 200])])
        ->follow('https://example.com/start', [], 5);

    self::assertSame(0, $landed->hops);
}

public function testReportsTheNumberOfRedirectsFollowedBeforeLanding(): void
{
    $follower = $this->follower([
        self::redirect('/a'),
        self::redirect('/b'),
        new MockResponse('ok', ['http_code' => 200]),
    ]);

    $landed = $follower->follow('https://example.com/start', [], 5);

    self::assertSame(2, $landed->hops);
}
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `php bin/phpunit --filter 'testReportsZeroHopsWhenTheFirstResponseLands|testReportsTheNumberOfRedirectsFollowedBeforeLanding'`
Expected: FAIL — `LandedResponse::$hops` does not exist (error / unknown property).

- [ ] **Step 3: Add the `hops` field to `LandedResponse`**

In `src/Service/Fetch/LandedResponse.php`, extend the constructor (keep the existing docblock and `isSuccess()`):

```php
    public function __construct(
        public string $url,
        public int $status,
        public ResponseInterface $response,
        public int $hops = 0,
    ) {
    }
```

- [ ] **Step 4: Set the hop count in `RedirectFollower`**

In `src/Service/Fetch/RedirectFollower.php`, the landing return inside `follow()` currently reads:

```php
            if (!\in_array($status, self::REDIRECT_STATUSES, true)) {
                return new LandedResponse($currentUrl, $status, $response);
            }
```

Change the return to pass the loop counter (which equals the number of redirects already followed):

```php
            if (!\in_array($status, self::REDIRECT_STATUSES, true)) {
                return new LandedResponse($currentUrl, $status, $response, $hop);
            }
```

- [ ] **Step 5: Run the tests to verify they pass**

Run: `php bin/phpunit tests/Service/Fetch/RedirectFollowerTest.php`
Expected: PASS (all cases, old and new).

- [ ] **Step 6: Commit**

```bash
git add src/Service/Fetch/LandedResponse.php src/Service/Fetch/RedirectFollower.php tests/Service/Fetch/RedirectFollowerTest.php
git commit -m "feat(#892): report the redirect-hop count from RedirectFollower"
```

---

### Task 2: `MetaRefreshTarget` — parse a zero-delay meta-refresh target

**Files:**
- Create: `src/Service/Reader/MetaRefreshTarget.php`
- Test: `tests/Service/Reader/MetaRefreshTargetTest.php`

**Interfaces:**
- Consumes: `App\Service\Html\HtmlDocumentParser::parseOrNull(string): ?\Dom\HTMLDocument`; `App\Service\Fetch\UrlResolver::resolve(string $baseUrl, string $location): string` (throws `App\Service\Fetch\Exception\FeedUnreachableException`, a `FetchException`, when the base names no host).
- Produces: `MetaRefreshTarget::within(string $html, string $baseUrl): ?string` — the absolute `http(s)` target of the first zero-delay `<meta http-equiv="refresh">`, or `null` (no such meta, non-zero delay, missing/non-`http(s)` target). No constructor, so Symfony autowires it with no config change.

- [ ] **Step 1: Write the failing test**

Create `tests/Service/Reader/MetaRefreshTargetTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Service\Reader;

use App\Service\Reader\MetaRefreshTarget;
use PHPUnit\Framework\TestCase;

final class MetaRefreshTargetTest extends TestCase
{
    private function meta(string $content): string
    {
        return '<html><head><meta http-equiv="refresh" content="' . $content . '"></head><body>x</body></html>';
    }

    public function testFollowsAZeroDelayAbsoluteTarget(): void
    {
        $target = (new MetaRefreshTarget())
            ->within($this->meta('0; url=https://example.com/real'), 'https://example.com/wall');

        self::assertSame('https://example.com/real', $target);
    }

    public function testResolvesAZeroDelayRelativeTargetAgainstTheLandingUrl(): void
    {
        $target = (new MetaRefreshTarget())
            ->within($this->meta('0; url=/real'), 'https://example.com/wall/here');

        self::assertSame('https://example.com/real', $target);
    }

    public function testLeavesANonZeroDelayReloadAlone(): void
    {
        self::assertNull((new MetaRefreshTarget())
            ->within($this->meta('5; url=https://example.com/real'), 'https://example.com/wall'));
    }

    public function testReturnsNullWhenThereIsNoRefreshMeta(): void
    {
        self::assertNull((new MetaRefreshTarget())
            ->within('<html><body>just an article</body></html>', 'https://example.com/wall'));
    }

    public function testReturnsNullWhenTheRefreshCarriesNoUrl(): void
    {
        self::assertNull((new MetaRefreshTarget())->within($this->meta('0'), 'https://example.com/wall'));
    }

    public function testRejectsANonHttpTarget(): void
    {
        self::assertNull((new MetaRefreshTarget())
            ->within($this->meta('0; url=mailto:editor@example.com'), 'https://example.com/wall'));
        self::assertNull((new MetaRefreshTarget())
            ->within($this->meta("0; url=javascript:alert('x')"), 'https://example.com/wall'));
    }

    public function testAcceptsCapitalisedRefreshAndQuotedUrl(): void
    {
        $html = '<html><head><meta http-equiv="Refresh" content="0; URL=\'https://example.com/real\'">'
            . '</head><body>x</body></html>';

        self::assertSame(
            'https://example.com/real',
            (new MetaRefreshTarget())->within($html, 'https://example.com/wall'),
        );
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `php bin/phpunit tests/Service/Reader/MetaRefreshTargetTest.php`
Expected: FAIL — `App\Service\Reader\MetaRefreshTarget` does not exist.

- [ ] **Step 3: Write the implementation**

Create `src/Service/Reader/MetaRefreshTarget.php`:

```php
<?php

declare(strict_types=1);

namespace App\Service\Reader;

use App\Service\Fetch\Exception\FetchException;
use App\Service\Fetch\UrlResolver;
use App\Service\Html\HtmlDocumentParser;

/**
 * The target of a client-side redirect a landed page performs with a zero-delay
 * <meta http-equiv="refresh">. A timed reload (a non-zero delay) is a real reload,
 * not a redirect, so it yields null — as does a missing or non-http(s) target.
 */
final readonly class MetaRefreshTarget
{
    public function within(string $html, string $baseUrl): ?string
    {
        $document = HtmlDocumentParser::parseOrNull($html);
        if ($document === null) {
            return null;
        }

        foreach ($document->querySelectorAll('meta[http-equiv]') as $meta) {
            if (strtolower((string) $meta->getAttribute('http-equiv')) !== 'refresh') {
                continue;
            }
            $target = $this->httpTarget((string) $meta->getAttribute('content'), $baseUrl);
            if ($target !== null) {
                return $target;
            }
        }

        return null;
    }

    private function httpTarget(string $content, string $baseUrl): ?string
    {
        $rawTarget = $this->zeroDelayTarget($content);
        if ($rawTarget === null) {
            return null;
        }

        $scheme = parse_url($rawTarget, \PHP_URL_SCHEME);
        if (\is_string($scheme)) {
            return \in_array(strtolower($scheme), ['http', 'https'], true) ? $rawTarget : null;
        }
        if ($scheme === false) {
            return null;
        }

        try {
            return UrlResolver::resolve($baseUrl, $rawTarget);
        } catch (FetchException) {
            return null;
        }
    }

    private function zeroDelayTarget(string $content): ?string
    {
        $parts = explode(';', $content, 2);
        if (\count($parts) !== 2 || !is_numeric(trim($parts[0])) || (float) trim($parts[0]) !== 0.0) {
            return null;
        }

        $target = trim((string) preg_replace('/^\s*url\s*=\s*/i', '', trim($parts[1])), "\"'");

        return $target === '' ? null : $target;
    }
}
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `php bin/phpunit tests/Service/Reader/MetaRefreshTargetTest.php`
Expected: PASS (all cases).

- [ ] **Step 5: Commit**

```bash
git add src/Service/Reader/MetaRefreshTarget.php tests/Service/Reader/MetaRefreshTargetTest.php
git commit -m "feat(#892): parse a zero-delay meta-refresh target"
```

---

### Task 3: `LandingChallenge` — recognise a bot/consent challenge body

**Files:**
- Create: `src/Service/Reader/LandingChallenge.php`
- Test: `tests/Service/Reader/LandingChallengeTest.php`

**Interfaces:**
- Consumes: nothing.
- Produces: `LandingChallenge::matches(string $html): bool` — `true` when the body carries a known challenge/consent-gate marker. No constructor, so Symfony autowires it with no config change.

- [ ] **Step 1: Write the failing test**

Create `tests/Service/Reader/LandingChallengeTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Service\Reader;

use App\Service\Reader\LandingChallenge;
use PHPUnit\Framework\TestCase;

final class LandingChallengeTest extends TestCase
{
    /**
     * @return iterable<string, array{string}>
     */
    public static function challengeBodies(): iterable
    {
        yield 'cloudflare verification' => ['<html><body class="cf-browser-verification">Just a moment…</body></html>'];
        yield 'cloudflare challenge platform' => ['<script src="/cdn-cgi/challenge-platform/h/b/orchestrate"></script>'];
        yield 'cloudflare challenge form' => ['<form id="challenge-form" action="/cdn-cgi/l/chk_jschl">'];
        yield 'anubis' => ['<script id="anubis_challenge" type="application/json">{}</script>'];
        yield 'siteground captcha' => ['<meta http-equiv="refresh" content="0;url=/.well-known/sgcaptcha/">'];
    }

    /**
     * @dataProvider challengeBodies
     */
    public function testRecognisesAChallengeBody(string $body): void
    {
        self::assertTrue((new LandingChallenge())->matches($body));
    }

    public function testDoesNotRejectAnArticleThatMerelyMentionsAChallengeInProse(): void
    {
        $body = '<html><body><h1>Are you a robot?</h1><p>Solving a captcha proves you are human.</p></body></html>';

        self::assertFalse((new LandingChallenge())->matches($body));
    }

    public function testDoesNotMatchAnEmptyBody(): void
    {
        self::assertFalse((new LandingChallenge())->matches(''));
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `php bin/phpunit tests/Service/Reader/LandingChallengeTest.php`
Expected: FAIL — `App\Service\Reader\LandingChallenge` does not exist.

- [ ] **Step 3: Write the implementation**

Create `src/Service/Reader/LandingChallenge.php`:

```php
<?php

declare(strict_types=1);

namespace App\Service\Reader;

/**
 * Recognises a bot- or consent-gate interstitial served with a 2xx status in
 * place of the article. Every marker is a vendor/machine string, never prose, so
 * the pre-extraction check cannot reject an article that merely names a captcha.
 * A new vendor earns a row once its markup is observed (cf. BotChallengePage, #424).
 */
final readonly class LandingChallenge
{
    private const array MARKERS = [
        'cf-browser-verification',
        '/cdn-cgi/challenge-platform/',
        'id="challenge-form"',
        'anubis_challenge',
        '/.well-known/sgcaptcha/',
    ];

    public function matches(string $html): bool
    {
        foreach (self::MARKERS as $marker) {
            if (str_contains($html, $marker)) {
                return true;
            }
        }

        return false;
    }
}
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `php bin/phpunit tests/Service/Reader/LandingChallengeTest.php`
Expected: PASS (all cases).

- [ ] **Step 5: Commit**

```bash
git add src/Service/Reader/LandingChallenge.php tests/Service/Reader/LandingChallengeTest.php
git commit -m "feat(#892): recognise a 200 bot or consent challenge body"
```

---

### Task 4: Wire the landing loop into `HtmlPageFetcher`

**Files:**
- Modify: `src/Service/Reader/HtmlPageFetcher.php` (constructor, `fetch()`, `land()`; add `readableBody()`, `assertNotChallenge()`, `LANDING_SCAN_LENGTH`)
- Test: `tests/Service/Reader/HtmlPageFetcherTest.php` (update the `fetcher()` helper; add cases)

**Interfaces:**
- Consumes: `RedirectFollower::follow(...): LandedResponse` with `LandedResponse::$hops` (Task 1); `MetaRefreshTarget::within(string $html, string $baseUrl): ?string` (Task 2); `LandingChallenge::matches(string $html): bool` (Task 3).
- Produces: `HtmlPageFetcher::fetch(string $url): PageResponse` — unchanged signature; now follows zero-delay meta-refresh interstitials and rejects challenge bodies, all under one budget of `MAX_REDIRECTS` (5).

- [ ] **Step 1: Update the test helper and write the failing tests**

In `tests/Service/Reader/HtmlPageFetcherTest.php`, the `fetcher()` helper constructs `new HtmlPageFetcher(new RedirectFollower(...), 'TestAgent/1.0')`. Add the two new collaborators and the imports:

```php
use App\Service\Reader\LandingChallenge;
use App\Service\Reader\MetaRefreshTarget;
```

```php
        return new HtmlPageFetcher(
            new RedirectFollower(
                new FailoverRequestSender(new MockHttpClient($responses), $this->noProxyResolver()),
                new UrlGuard($resolver, new IpValidator()),
            ),
            new MetaRefreshTarget(),
            new LandingChallenge(),
            'TestAgent/1.0',
        );
```

Add a small helper next to the existing ones for building a meta-refresh page:

```php
    private static function metaRefresh(string $target): string
    {
        return '<html><head><meta http-equiv="refresh" content="0; url=' . $target . '">'
            . '</head><body>interstitial</body></html>';
    }
```

Then add the cases:

```php
public function testFollowsAZeroDelayMetaRefreshToTheArticle(): void
{
    $fetcher = $this->fetcher([
        new MockResponse(self::metaRefresh('https://example.com/article'), ['http_code' => 200]),
        new MockResponse('<html><body>the real article</body></html>', ['http_code' => 200]),
    ]);

    $result = $fetcher->fetch('https://example.com/wall');

    self::assertStringContainsString('the real article', $result->html);
    self::assertSame('https://example.com/article', $result->finalUrl);
}

public function testLeavesANonZeroDelayMetaRefreshAlone(): void
{
    $body = '<html><head><meta http-equiv="refresh" content="5; url=https://example.com/later">'
        . '</head><body>timed reload page</body></html>';
    $fetcher = $this->fetcher([new MockResponse($body, ['http_code' => 200])]);

    $result = $fetcher->fetch('https://example.com/wall');

    self::assertStringContainsString('timed reload page', $result->html);
    self::assertSame('https://example.com/wall', $result->finalUrl);
}

public function testStopsAMetaRefreshSelfLoopAtTheBudget(): void
{
    $fetcher = $this->fetcher(
        static fn (): MockResponse => new MockResponse(
            self::metaRefresh('https://example.com/wall'),
            ['http_code' => 200],
        ),
    );

    $this->expectException(PageFetchException::class);
    $this->expectExceptionMessage('more than 5 redirects');
    $fetcher->fetch('https://example.com/wall');
}

public function testSharesOneBudgetAcrossHttpRedirectAndMetaHops(): void
{
    $fetcher = $this->fetcher([
        new MockResponse('', ['http_code' => 301, 'response_headers' => ['location' => '/a']]),
        new MockResponse(self::metaRefresh('https://example.com/b'), ['http_code' => 200]),
        new MockResponse('', ['http_code' => 301, 'response_headers' => ['location' => '/c']]),
        new MockResponse(self::metaRefresh('https://example.com/d'), ['http_code' => 200]),
        new MockResponse('', ['http_code' => 301, 'response_headers' => ['location' => '/e']]),
        new MockResponse(self::metaRefresh('https://example.com/f'), ['http_code' => 200]),
    ]);

    $this->expectException(PageFetchException::class);
    $this->expectExceptionMessage('more than 5 redirects');
    $fetcher->fetch('https://example.com/start');
}

public function testRejectsA200ChallengeBody(): void
{
    $body = '<html><body class="cf-browser-verification">Just a moment…</body></html>';
    $fetcher = $this->fetcher([new MockResponse($body, ['http_code' => 200])]);

    $this->expectException(PageFetchException::class);
    $fetcher->fetch('https://example.com/wall');
}

public function testSsrfGuardsAMetaRefreshTarget(): void
{
    $fetcher = $this->fetcher(
        [new MockResponse(self::metaRefresh('http://internal.test/secret'), ['http_code' => 200])],
        ['example.com' => ['93.184.216.34'], 'internal.test' => ['10.0.0.1']],
    );

    $this->expectException(PageFetchException::class);
    $fetcher->fetch('https://example.com/wall');
}
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `php bin/phpunit tests/Service/Reader/HtmlPageFetcherTest.php`
Expected: FAIL — `HtmlPageFetcher::__construct()` now gets the wrong argument count / the loop behavior does not exist yet.

- [ ] **Step 3: Rewrite `HtmlPageFetcher` to loop**

In `src/Service/Reader/HtmlPageFetcher.php`, add the constant next to the others:

```php
    private const int LANDING_SCAN_LENGTH = 20_000;
```

Add the two collaborators to the constructor:

```php
    public function __construct(
        private RedirectFollower $redirects,
        private MetaRefreshTarget $metaRefresh,
        private LandingChallenge $challenge,
        private string $userAgent,
    ) {
    }
```

Replace `fetch()` with the bounded loop:

```php
    public function fetch(string $url): PageResponse
    {
        $remainingHops = self::MAX_REDIRECTS;
        $target = $url;
        while (true) {
            $landed = $this->land($target, $remainingHops);
            $remainingHops -= $landed->hops;
            $body = $this->readableBody($landed);

            $head = mb_substr($body, 0, self::LANDING_SCAN_LENGTH);
            $this->assertNotChallenge($head, $landed);
            $next = $this->metaRefresh->within($head, $landed->url);
            if ($next === null) {
                return new PageResponse($landed->url, $body);
            }

            $landed->response->cancel();
            if ($remainingHops < 1) {
                throw new PageFetchException(sprintf('%s: more than %d redirects', $url, self::MAX_REDIRECTS));
            }
            $remainingHops--;
            $target = $next;
        }
    }
```

Change `land()` to take the remaining budget:

```php
    private function land(string $url, int $maxRedirects): LandedResponse
    {
        try {
            return $this->redirects->follow($url, $this->options(), $maxRedirects);
        } catch (RedirectChainException $e) {
            throw new PageFetchException($e->getMessage(), previous: $e);
        }
    }
```

Add the two helpers (they fold in the non-2xx snippet + size cap that used to live inline in `fetch()`):

```php
    private function readableBody(LandedResponse $landed): string
    {
        if (!$landed->isSuccess()) {
            $snippet = $this->errorBodySnippet($landed->response);
            $landed->response->cancel();
            $status = self::describeStatus($landed->status);

            throw new PageFetchException($snippet === null ? $status : $status . ' — ' . $snippet);
        }

        $body = $this->content($landed);
        if (\strlen($body) > self::MAX_BYTES) {
            throw new PageFetchException(sprintf('response exceeds %d bytes', self::MAX_BYTES));
        }

        return $body;
    }

    private function assertNotChallenge(string $head, LandedResponse $landed): void
    {
        if ($this->challenge->matches($head)) {
            $landed->response->cancel();

            throw new PageFetchException(sprintf('%s: bot or consent challenge interstitial', $landed->url));
        }
    }
```

Leave `options()`, `describeStatus()`, `errorBodySnippet()`, `visibleText()`, and `content()` unchanged.

- [ ] **Step 4: Run the tests to verify they pass**

Run: `php bin/phpunit tests/Service/Reader/HtmlPageFetcherTest.php`
Expected: PASS (all cases, old and new).

- [ ] **Step 5: Commit**

```bash
git add src/Service/Reader/HtmlPageFetcher.php tests/Service/Reader/HtmlPageFetcherTest.php
git commit -m "feat(#892): follow meta-refresh interstitials and reject challenge bodies"
```

---

### Task 5: Full gate + dev-log scan

**Files:** none (verification only).

- [ ] **Step 1: Warm the cache and run the static gates**

```bash
php bin/console cache:warmup
composer check
composer md
```

Expected: `cs`, `stan`, `tramp`, and `md` all clean. If `md` flags `HtmlPageFetcher` (the file grew), extract further rather than tuning the threshold.

- [ ] **Step 2: Run the full suite**

Run: `php bin/phpunit`
Expected: PASS.

- [ ] **Step 3: Run the mutation gate over the branch**

Run: `composer infection:diff`
Expected: at or above `minMsi`. Escaped mutants point at a weak assertion — strengthen the test (likely the hop arithmetic or a delay/budget boundary), do not lower the gate.

- [ ] **Step 4: Scan today's dev log**

```bash
cat "$(ls -t var/log/dev-*.log | head -1)"
```

Expected: no new deprecation or swallowed error from the reader fetch path.

- [ ] **Step 5: PhpStorm inspections on the changed PHP**

Run `mcp__phpstorm__lint_files` over the five touched/created files; block on ERROR and WARNING, weak warnings advisory.

---

## Self-Review

**Spec coverage:**
- §3.1 report hops → Task 1. ✅
- §3.2 `MetaRefreshTarget` (zero-delay, case-insensitive, de-quote, absolutize, http(s)-only) → Task 2. ✅
- §3.3 `LandingChallenge` (structural markers, prose false-positive guard) → Task 3. ✅
- §3.4 + §4 loop + shared budget + challenge-before-meta + SSRF-via-re-entry → Task 4 (`testSharesOneBudgetAcrossHttpRedirectAndMetaHops`, `testRejectsA200ChallengeBody`, `testSsrfGuardsAMetaRefreshTarget`). ✅
- §5 fallback wiring: every rejection throws `PageFetchException` → existing `ArticleExtractor` `reason 'fetch'`; unchanged, no task needed. ✅
- §6 head-window scan (`LANDING_SCAN_LENGTH`), zero-delay only → Task 4 / Task 2. ✅
- §7 test plan → Tasks 1–4 test steps. ✅
- §8 gates → Task 5. ✅
- Deferrals (`MediaLanding`, `BotChallengePage`) → untouched by design; no task, correct. ✅

**Placeholder scan:** none — every code and test step carries full content.

**Type consistency:** `hops` (int, Task 1) read in Task 4; `within(string,string): ?string` (Task 2) and `matches(string): bool` (Task 3) called with those exact signatures in Task 4; `PageResponse(finalUrl, html)` and `PageFetchException` used as they exist today.
