# #800 Sibling-id media Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** A page that declares one media URL embedding a page-local id, and names sibling ids in the same keyed context, gets those siblings as players too — derived from the page's own template, verified by the network.

**Architecture:** `MediaLanding` (extracted from `StreamLocationResolver`) is the one place a media URL is followed to a 2xx landing. `SiblingIdRule` is pure text: seed id → keyed occurrences → context-matched siblings (list-capped, URL-excluded, poster-required) → unverified candidates. `SiblingMediaExtender` verifies each through `MediaLanding` + `MediaUrlKind` and appends the survivors. `ArticleExtractor` applies it after the stream location step.

**Tech Stack:** PHP 8.4 / Symfony 7.4, `MockHttpClient`, PHPUnit.

**Spec:** `docs/superpowers/specs/2026-09-02-800-sibling-id-media-design.md`

## Global Constraints

- Host-agnostic: no host, key name, or URL template in `src/`. The seed's URL is the template; the seed's keyed context selects siblings.
- Filters, exactly: seed stem `^[A-Za-z0-9_-]{6,}$`; sibling same character class plus the seed's `-\d+` suffix when present; sibling context = same previous key, key, next key; a context with **more than 5** siblings is skipped whole; a sibling the page names inside any URL is skipped; a sibling without a poster within **2000** characters after its occurrence is skipped; poster = largest `WxH` https image-like URL.
- A derived candidate is emitted only when its URL lands 2xx on a URL of the seed's kind, at the landing.
- `StreamLocationResolver` keeps its behaviour and tests; only its constructor changes to take `MediaLanding`.
- `ArticleMedia::MAX_ITEMS` still caps the total.
- Measured baseline: over 75 files the rule fires on one page (the ZDF shape) with three siblings; every existing discovery/extractor expectation stays green unchanged.
- Reader cache `VERSION` 17 → 18.
- Clean Code per CLAUDE.md: `final readonly`, guard clauses, every touched `src` file PHPMD-clean, comments ≤ 3 lines. Commit messages `type(#800): summary`. Backend tests from `backend/` with `php bin/phpunit <path>`; never run the native suite and the Docker MySQL leg concurrently.

---

### Task 1: `MediaLanding` — one landing check for streams and derived URLs

**Files:**
- Create: `backend/src/Service/Reader/Media/MediaLanding.php`
- Create: `backend/tests/Service/Reader/Media/MediaLandingTest.php`
- Modify: `backend/src/Service/Reader/Media/StreamLocationResolver.php` (whole file)
- Modify: `backend/tests/Service/Reader/Media/StreamLocationResolverTest.php` (constructor in `resolver()`)
- Modify: `backend/tests/Service/Reader/ArticleExtractorTest.php` (constructor in `extractor()`)

**Interfaces:**
- Produces: `MediaLanding::urlOf(string $url): ?string` — the 2xx landing URL, null on a failed chain or a non-2xx landing; sends the same request options the resolver sent.
- Consumes: `RedirectFollower::follow(url, options, maxRedirects): LandedResponse{url,status,response,isSuccess()}`.

- [ ] **Step 1: Write the failing tests**

`backend/tests/Service/Reader/Media/MediaLandingTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Service\Reader\Media;

use App\Service\Fetch\DnsResolverInterface;
use App\Service\Fetch\FailoverRequestSender;
use App\Service\Fetch\IpValidator;
use App\Service\Fetch\ProxyEgressResolver;
use App\Service\Fetch\RedirectFollower;
use App\Service\Fetch\UrlGuard;
use App\Service\Reader\Media\MediaLanding;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class MediaLandingTest extends TestCase
{
    /** @var array<array-key, mixed> */
    private array $seenOptions = [];

    /** @param list<MockResponse> $responses */
    private function landing(array $responses): MediaLanding
    {
        $queue = $responses;
        $client = new MockHttpClient(function (string $method, string $url, array $options) use (&$queue): MockResponse {
            $this->seenOptions = $options;

            return array_shift($queue) ?? new MockResponse('', ['http_code' => 500]);
        });
        $dns = new class implements DnsResolverInterface {
            public function resolve(string $hostname): array
            {
                return ['93.184.216.34'];
            }
        };
        $proxy = $this->createStub(ProxyEgressResolver::class);
        $proxy->method('resolve')->willReturn(null);

        return new MediaLanding(
            new RedirectFollower(new FailoverRequestSender($client, $proxy), new UrlGuard($dns, new IpValidator())),
            'TestAgent/1.0',
        );
    }

    private static function redirect(string $location): MockResponse
    {
        return new MockResponse('', ['http_code' => 301, 'response_headers' => ['location' => $location]]);
    }

    public function testNamesWhereAChainLandsWithSuccess(): void
    {
        $landing = $this->landing([self::redirect('https://cdn.test/master.m3u8'), new MockResponse('#EXTM3U', ['http_code' => 200])]);

        self::assertSame('https://cdn.test/master.m3u8', $landing->urlOf('https://a.test/x.m3u8'));
    }

    public function testNullWhenTheChainLandsOnAnError(): void
    {
        $landing = $this->landing([new MockResponse('', ['http_code' => 404])]);

        self::assertNull($landing->urlOf('https://a.test/x.m3u8'));
    }

    public function testNullWhenTheChainFails(): void
    {
        $landing = $this->landing([new MockResponse('', ['error' => 'Connection reset by peer'])]);

        self::assertNull($landing->urlOf('https://a.test/x.m3u8'));
    }

    public function testSendsTheMediaAcceptHeaderAndTheTimeBudget(): void
    {
        $this->landing([new MockResponse('', ['http_code' => 200])])->urlOf('https://a.test/x.m3u8');

        /** @var list<string> $headers */
        $headers = $this->seenOptions['headers'];
        self::assertContains('Accept: application/vnd.apple.mpegurl,application/x-mpegURL,*/*;q=0.8', $headers);
        self::assertContains('User-Agent: TestAgent/1.0', $headers);
        self::assertSame(0, $this->seenOptions['max_redirects']);
        self::assertSame(10.0, $this->seenOptions['max_duration']);
    }
}
```

(`StreamLocationResolverTest` already asserts these options through `seenOptions`; copy its exact assertion shape — the MockHttpClient normalises headers to `Name: value` strings.)

- [ ] **Step 2: Run them to verify they fail**

Run: `php bin/phpunit tests/Service/Reader/Media/MediaLandingTest.php`
Expected: error — class not found.

- [ ] **Step 3: Write `MediaLanding` and slim `StreamLocationResolver`**

`backend/src/Service/Reader/Media/MediaLanding.php`:

```php
<?php

declare(strict_types=1);

namespace App\Service\Reader\Media;

use App\Service\Fetch\Exception\RedirectChainException;
use App\Service\Fetch\RedirectFollower;

/** Where a media URL comes to rest: the 2xx landing of its redirect chain, or null when the chain fails or lands on an error. */
final readonly class MediaLanding
{
    private const int MAX_REDIRECTS = 5;
    private const float TIMEOUT_SECONDS = 5.0;

    public function __construct(
        private RedirectFollower $redirects,
        private string $userAgent,
    ) {
    }

    public function urlOf(string $url): ?string
    {
        try {
            $landed = $this->redirects->follow($url, $this->options(), self::MAX_REDIRECTS);
        } catch (RedirectChainException) {
            return null;
        }
        $landed->response->cancel();

        return $landed->isSuccess() ? $landed->url : null;
    }

    /** @return array<string, mixed> */
    private function options(): array
    {
        return [
            'headers' => [
                'Accept' => 'application/vnd.apple.mpegurl,application/x-mpegURL,*/*;q=0.8',
                'User-Agent' => $this->userAgent,
            ],
            'timeout' => self::TIMEOUT_SECONDS,
            'max_duration' => self::TIMEOUT_SECONDS * 2,
        ];
    }
}
```

`backend/src/Service/Reader/Media/StreamLocationResolver.php` (whole file):

```php
<?php

declare(strict_types=1);

namespace App\Service\Reader\Media;

/**
 * A stream is fetched by script, not by the media element, so it plays only from
 * the URL that finally serves it: a cross-origin fetch dies on a redirect hop
 * without a CORS header. A chain that fails, or lands anywhere but on a durable
 * playlist, keeps the declared URL — the native client follows redirects itself.
 */
final readonly class StreamLocationResolver
{
    public function __construct(
        private MediaLanding $landings,
        private MediaUrlKind $mediaUrlKind,
    ) {
    }

    public function resolve(ArticleMedia $media): ArticleMedia
    {
        return new ArticleMedia(array_map($this->located(...), $media->candidates));
    }

    private function located(MediaCandidate $candidate): MediaCandidate
    {
        if ($candidate->kind !== MediaKind::Stream) {
            return $candidate;
        }
        $landing = $this->mediaUrlKind->resolve($this->landings->urlOf($candidate->url) ?? $candidate->url);

        return $landing?->kind === MediaKind::Stream ? $candidate->at($landing->url) : $candidate;
    }
}
```

- [ ] **Step 4: Update the two constructions**

`StreamLocationResolverTest::resolver()` returns:

```php
        return new StreamLocationResolver(
            new MediaLanding(
                new RedirectFollower(new FailoverRequestSender($client, $proxy), new UrlGuard($dns, new IpValidator())),
                'TestAgent/1.0',
            ),
            new MediaUrlKind(new DurableMediaUrl(), $providers),
        );
```

`ArticleExtractorTest::extractor()`: replace `new StreamLocationResolver($redirects, $this->urlKind(), 'TestAgent/1.0')` with `new StreamLocationResolver(new MediaLanding($redirects, 'TestAgent/1.0'), $this->urlKind())` and add the `use`. (Task 4 restructures this line again — minimal edit here.)

- [ ] **Step 5: Run the suites**

Run: `php bin/phpunit tests/Service/Reader`
Expected: PASS, every `StreamLocationResolverTest` case unchanged.

- [ ] **Step 6: Gates and commit**

Run from `backend/`: `composer cs:fix && composer check && composer md`
Expected: clean.

```bash
git add backend/src/Service/Reader/Media/MediaLanding.php backend/src/Service/Reader/Media/StreamLocationResolver.php backend/tests/Service/Reader/Media/MediaLandingTest.php backend/tests/Service/Reader/Media/StreamLocationResolverTest.php backend/tests/Service/Reader/ArticleExtractorTest.php
git commit -m "refactor(#800): move the media landing check out of StreamLocationResolver into MediaLanding"
```

---

### Task 2: `KeyedOccurrences` and `NearbyPoster` — reading the page's text

**Files:**
- Create: `backend/src/Service/Reader/Media/Sibling/KeyedOccurrence.php`
- Create: `backend/src/Service/Reader/Media/Sibling/KeyedOccurrences.php`
- Create: `backend/src/Service/Reader/Media/Sibling/NearbyPoster.php`
- Create: `backend/tests/Service/Reader/Media/Sibling/KeyedOccurrencesTest.php`
- Create: `backend/tests/Service/Reader/Media/Sibling/NearbyPosterTest.php`

**Interfaces:**
- Produces: `KeyedOccurrence{key, previousKey, nextKey, position}` with `sharesContextWith(self): bool`; `KeyedOccurrences::of(string $html, string $id): list<KeyedOccurrence>` and `::at(string $html, string $id, int $position): ?KeyedOccurrence`; `NearbyPoster::after(string $html, int $position): ?string`.

- [ ] **Step 1: Write the failing tests**

`KeyedOccurrencesTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Service\Reader\Media\Sibling;

use App\Service\Reader\Media\Sibling\KeyedOccurrences;
use PHPUnit\Framework\TestCase;

final class KeyedOccurrencesTest extends TestCase
{
    /** The Next.js flight payload escapes its quotes; the key on either side is still readable. */
    public function testReadsAnEscapedJsonValueWithItsNeighbouringKeys(): void
    {
        $html = '<script>self.__next_f.push([1,"{\\"config\\":{\\"isPriority\\":\\"$undefined\\",'
            . '\\"content\\":\\"taktik-analyse-video-100\\",\\"startImage\\":{\\"title\\":\\"x\\"}}}"])</script>';

        $found = KeyedOccurrences::of($html, 'taktik-analyse-video-100');

        self::assertCount(1, $found);
        self::assertSame('content', $found[0]->key);
        self::assertSame('isPriority', $found[0]->previousKey);
        self::assertSame('startImage', $found[0]->nextKey);
        self::assertSame(strpos($html, 'taktik-analyse-video-100'), $found[0]->position);
    }

    public function testReadsAPlainJsonValue(): void
    {
        $html = '<script type="application/json">{"kind":"clip","id":"clip-77","poster":"p.jpg"}</script>';

        $found = KeyedOccurrences::of($html, 'clip-77');

        self::assertSame(['kind', 'id', 'poster'], [$found[0]->previousKey, $found[0]->key, $found[0]->nextKey]);
    }

    public function testReadsAnAttributeValue(): void
    {
        $html = '<div class="player" data-content="clip-77" data-poster="p.jpg"></div>';

        $found = KeyedOccurrences::of($html, 'clip-77');

        self::assertSame(['class', 'data-content', 'data-poster'], [$found[0]->previousKey, $found[0]->key, $found[0]->nextKey]);
    }

    public function testAnOccurrenceInsideAUrlPathHasNoKey(): void
    {
        $html = '<a href="/video/xpress/clip-77.html">x</a> {"contentUrl":"https://a.test/api/video/clip-77.m3u8"}';

        self::assertSame([], KeyedOccurrences::of($html, 'clip-77'));
    }

    public function testAtReadsOneOccurrenceByPosition(): void
    {
        $html = '{"a":"clip-77","b":1} {"x":"clip-77","y":2}';
        $second = strrpos($html, 'clip-77');
        self::assertIsInt($second);

        $occurrence = KeyedOccurrences::at($html, 'clip-77', $second);

        self::assertNotNull($occurrence);
        self::assertSame('x', $occurrence->key);
        self::assertSame('y', $occurrence->nextKey);
    }

    public function testContextIsTheKeyAndBothNeighbours(): void
    {
        $html = '{"p":1,"k":"one-1","n":2} {"p":1,"k":"two-2","n":2} {"q":1,"k":"three-3","n":2}';
        $one = KeyedOccurrences::of($html, 'one-1')[0];

        self::assertTrue($one->sharesContextWith(KeyedOccurrences::of($html, 'two-2')[0]));
        self::assertFalse($one->sharesContextWith(KeyedOccurrences::of($html, 'three-3')[0]));
    }
}
```

`NearbyPosterTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Service\Reader\Media\Sibling;

use App\Service\Reader\Media\Sibling\NearbyPoster;
use PHPUnit\Framework\TestCase;

final class NearbyPosterTest extends TestCase
{
    public function testTakesTheLargestRenditionDeclaredAfterThePosition(): void
    {
        $html = 'id "layouts":{"1140x120":"https://a.test/assets/still-100~1140x120?cb=1",'
            . '"1920x1080":"https://a.test/assets/still-100~1920x1080?cb=1","384x216":"https://a.test/assets/still-100~384x216"}';

        self::assertSame('https://a.test/assets/still-100~1920x1080?cb=1', NearbyPoster::after($html, 0));
    }

    public function testAnImageExtensionCountsWithoutDimensions(): void
    {
        $html = 'id … "src":"https://a.test/img/still.jpg?w=1"';

        self::assertSame('https://a.test/img/still.jpg?w=1', NearbyPoster::after($html, 0));
    }

    public function testNeverTakesAPlaylistAFileAScriptOrAStylesheet(): void
    {
        $html = 'id "a":"https://a.test/v/master.m3u8","b":"https://a.test/v/clip.mp4","c":"https://a.test/app.js?v=2x2","d":"https://a.test/s.css"';

        self::assertNull(NearbyPoster::after($html, 0));
    }

    public function testLooksOnlyWithinTheWindow(): void
    {
        $html = 'id' . str_repeat(' ', 2100) . '"src":"https://a.test/still.jpg"';

        self::assertNull(NearbyPoster::after($html, 0));
    }

    public function testNullWhenNothingImageLikeFollows(): void
    {
        self::assertNull(NearbyPoster::after('id "href":"https://a.test/page"', 0));
    }
}
```

- [ ] **Step 2: Run them to verify they fail**

Run: `php bin/phpunit tests/Service/Reader/Media/Sibling`
Expected: errors — classes not found.

- [ ] **Step 3: Write the classes**

`KeyedOccurrence.php`:

```php
<?php

declare(strict_types=1);

namespace App\Service\Reader\Media\Sibling;

/** An id standing as a keyed value on the page, with the keys on either side — the context that tells one list from another. */
final readonly class KeyedOccurrence
{
    public function __construct(
        public string $key,
        public string $previousKey,
        public string $nextKey,
        public int $position,
    ) {
    }

    public function sharesContextWith(self $other): bool
    {
        return $this->key === $other->key
            && $this->previousKey === $other->previousKey
            && $this->nextKey === $other->nextKey;
    }
}
```

`KeyedOccurrences.php`:

```php
<?php

declare(strict_types=1);

namespace App\Service\Reader\Media\Sibling;

/**
 * Where a page names an id as a keyed value — `"key":"id"` in a script payload
 * (escaped quotes allowed) or `key="id"` on an element. An id inside a URL path
 * has no key and is not an occurrence.
 */
final readonly class KeyedOccurrences
{
    private const string KEY_BEFORE_VALUE = '/([A-Za-z_][A-Za-z0-9_-]*)\\\\?"?\s*[:=]\s*\\\\?"$/';
    private const string ANY_KEY = '/\\\\?"?([A-Za-z_][A-Za-z0-9_-]*)\\\\?"?\s*[:=]\s*/';
    private const int WINDOW = 200;

    /** @return list<KeyedOccurrence> */
    public static function of(string $html, string $id): array
    {
        $found = [];
        $offset = 0;
        while (($position = strpos($html, $id, $offset)) !== false) {
            $offset = $position + 1;
            $occurrence = self::at($html, $id, $position);
            if ($occurrence !== null) {
                $found[] = $occurrence;
            }
        }

        return $found;
    }

    public static function at(string $html, string $id, int $position): ?KeyedOccurrence
    {
        $before = substr($html, max(0, $position - self::WINDOW), min(self::WINDOW, $position));
        if (preg_match(self::KEY_BEFORE_VALUE, $before, $key) !== 1) {
            return null;
        }
        $after = substr($html, $position + \strlen($id), self::WINDOW);

        return new KeyedOccurrence(
            $key[1],
            self::lastKeyIn(substr($before, 0, -\strlen($key[0]))),
            self::firstKeyIn($after),
            $position,
        );
    }

    private static function lastKeyIn(string $text): string
    {
        preg_match_all(self::ANY_KEY, $text, $matches);
        $last = end($matches[1]);

        return $last === false ? '' : $last;
    }

    private static function firstKeyIn(string $text): string
    {
        return preg_match(self::ANY_KEY, $text, $matches) === 1 ? $matches[1] : '';
    }
}
```

`NearbyPoster.php`:

```php
<?php

declare(strict_types=1);

namespace App\Service\Reader\Media\Sibling;

/** The largest still declared within reach of a position — a player config lists its poster's renditions beside its id. */
final readonly class NearbyPoster
{
    private const int WINDOW = 2000;
    private const string HTTPS_URL = '#https://[^\s"\'<>\\\\]+#';
    private const string IMAGE_LIKE = '#\.(jpe?g|png|webp|gif|avif)(\?|$)|\d+x\d+#i';
    private const string NEVER_AN_IMAGE = '#\.(m3u8|mp4|mp3|js|css|json)(\?|$)#i';
    private const string DIMENSIONS = '/(\d+)x(\d+)/';

    public static function after(string $html, int $position): ?string
    {
        preg_match_all(self::HTTPS_URL, substr($html, $position, self::WINDOW), $matches);
        $best = null;
        $bestArea = -1;
        foreach ($matches[0] as $url) {
            $area = self::area($url);
            if ($area > $bestArea) {
                $bestArea = $area;
                $best = $url;
            }
        }

        return $best;
    }

    /** Pixels the URL declares, 0 for an image without dimensions, -1 for anything that is not an image. */
    private static function area(string $url): int
    {
        if (preg_match(self::NEVER_AN_IMAGE, $url) === 1 || preg_match(self::IMAGE_LIKE, $url) !== 1) {
            return -1;
        }

        return preg_match(self::DIMENSIONS, $url, $dimensions) === 1
            ? (int) $dimensions[1] * (int) $dimensions[2]
            : 0;
    }
}
```

- [ ] **Step 4: Run the tests**

Run: `php bin/phpunit tests/Service/Reader/Media/Sibling`
Expected: PASS, 11 tests. If `testReadsAnAttributeValue` reports `previousKey` as `class` only after the quote, check `ANY_KEY`'s optional quote handles `class="player"` (`"player" ` precedes `data-content=`): the last key match before the value is `class`.

- [ ] **Step 5: Gates and commit**

Run from `backend/`: `composer cs:fix && composer check && composer md`
Expected: clean.

```bash
git add backend/src/Service/Reader/Media/Sibling backend/tests/Service/Reader/Media/Sibling
git commit -m "feat(#800): read where a page names an id as a keyed value, and the still declared beside it"
```

---

### Task 3: `SiblingIdRule` — derive the siblings

**Files:**
- Create: `backend/src/Service/Reader/Media/Sibling/SiblingIdRule.php`
- Create: `backend/tests/Service/Reader/Media/Sibling/SiblingIdRuleTest.php`

**Interfaces:**
- Consumes: `KeyedOccurrences`, `KeyedOccurrence::sharesContextWith()`, `NearbyPoster::after()`, `ArticleMedia::candidates`, `MediaCandidate` (constructor `kind, url, posterUrl, label, precedingText`).
- Produces: `SiblingIdRule::derive(ArticleMedia $found, string $pageHtml): list<MediaCandidate>` (unverified).

- [ ] **Step 1: Write the failing tests**

```php
<?php

declare(strict_types=1);

namespace App\Tests\Service\Reader\Media\Sibling;

use App\Service\Reader\Media\ArticleMedia;
use App\Service\Reader\Media\MediaCandidate;
use App\Service\Reader\Media\MediaKind;
use App\Service\Reader\Media\Sibling\SiblingIdRule;
use PHPUnit\Framework\TestCase;

final class SiblingIdRuleTest extends TestCase
{
    private const string SEED_URL = 'https://a.test/api/video/taktik-analyse-video-100.m3u8';

    private function seed(MediaKind $kind = MediaKind::Stream, string $url = self::SEED_URL): ArticleMedia
    {
        return new ArticleMedia([new MediaCandidate($kind, $url, 'https://a.test/assets/taktik~1920x1080', null, 'prose')]);
    }

    /** One player config in the shape the ZDF payload uses, escaped as the flight data escapes it. */
    private static function config(string $id, ?string $still = null): string
    {
        $layouts = $still === null
            ? ''
            : ',\\"startImage\\":{\\"layouts\\":{\\"384x216\\":\\"https://a.test/assets/' . $still . '~384x216\\",'
                . '\\"1920x1080\\":\\"https://a.test/assets/' . $still . '~1920x1080?cb=1\\"}}';

        return '{\\"config\\":{\\"isPriority\\":\\"$undefined\\",\\"content\\":\\"' . $id . '\\"' . $layouts . '}}';
    }

    private static function page(string ...$payload): string
    {
        return '<html><body><script>self.__next_f.push([1,"' . implode(',', $payload) . '"])</script></body></html>';
    }

    public function testDerivesTheSiblingsNamedInTheSeedsContext(): void
    {
        $html = self::page(
            self::config('taktik-analyse-video-100', 'taktik'),
            self::config('reaktion-anschlag-video-100', 'reaktion-clean-100'),
            self::config('sgs-lange-wiesel-100', '260826-clip-2-hju-100'),
        );

        $derived = (new SiblingIdRule())->derive($this->seed(), $html);

        self::assertCount(2, $derived);
        self::assertSame(MediaKind::Stream, $derived[0]->kind);
        self::assertSame('https://a.test/api/video/reaktion-anschlag-video-100.m3u8', $derived[0]->url);
        self::assertSame('https://a.test/assets/reaktion-clean-100~1920x1080?cb=1', $derived[0]->posterUrl);
        self::assertNull($derived[0]->precedingText);
        self::assertSame('https://a.test/api/video/sgs-lange-wiesel-100.m3u8', $derived[1]->url);
    }

    public function testAContextWithMoreThanFiveSiblingsIsAListNotTheArticle(): void
    {
        $configs = [self::config('taktik-analyse-video-100', 'taktik')];
        foreach (range(1, 6) as $n) {
            $configs[] = self::config('nav-entry-' . $n . '-100', 'nav-' . $n);
        }

        self::assertSame([], (new SiblingIdRule())->derive($this->seed(), self::page(...$configs)));
    }

    public function testASiblingThePageNamesInsideAUrlIsLeftToTheUrlSources(): void
    {
        $html = self::page(self::config('taktik-analyse-video-100', 'taktik'), self::config('gestern-clip-100', 'gestern'))
            . '<a href="https://a.test/api/video/gestern-clip-100.m3u8">yesterday</a>';

        self::assertSame([], (new SiblingIdRule())->derive($this->seed(), $html));
    }

    public function testASiblingWithoutAStillNearbyIsSkipped(): void
    {
        $html = self::page(self::config('taktik-analyse-video-100', 'taktik'), self::config('reaktion-anschlag-video-100'));

        self::assertSame([], (new SiblingIdRule())->derive($this->seed(), $html));
    }

    public function testASiblingInAnotherContextIsSkipped(): void
    {
        $html = self::page(
            self::config('taktik-analyse-video-100', 'taktik'),
            '{\\"teaser\\":{\\"type\\":\\"link\\",\\"content\\":\\"other-teaser-100\\",\\"startImage\\":{\\"layouts\\":{\\"1x1\\":\\"https://a.test/assets/o~1x1\\"}}}}',
        );

        self::assertSame([], (new SiblingIdRule())->derive($this->seed(), $html));
    }

    public function testTheSeedsSuffixShapeIsRequired(): void
    {
        $html = self::page(self::config('taktik-analyse-video-100', 'taktik'), self::config('reaktion-anschlag-video', 'reaktion'));

        self::assertSame([], (new SiblingIdRule())->derive($this->seed(), $html));
    }

    public function testAnEmbedSeedDerivesNothing(): void
    {
        $html = self::page(self::config('M1j_uRqKMKI', 'a'), self::config('Zx1_6F-nCaw', 'b'));
        $seed = $this->seed(MediaKind::Embed, 'https://www.youtube-nocookie.com/embed/M1j_uRqKMKI');

        self::assertSame([], (new SiblingIdRule())->derive($seed, $html));
    }

    public function testASeedWhoseStemIsNotAnIdDerivesNothing(): void
    {
        $html = self::page(self::config('main', 'a'), self::config('other', 'b'));

        self::assertSame([], (new SiblingIdRule())->derive($this->seed(MediaKind::Video, 'https://a.test/v/main.mp4'), $html));
    }

    public function testASeedNamedOnlyInsideUrlsDerivesNothing(): void
    {
        $html = '<html><body><a href="/video/taktik-analyse-video-100.html">x</a>'
            . '<script>{"contentUrl":"https://a.test/api/video/taktik-analyse-video-100.m3u8"}</script></body></html>';

        self::assertSame([], (new SiblingIdRule())->derive($this->seed(), $html));
    }
}
```

- [ ] **Step 2: Run them to verify they fail**

Run: `php bin/phpunit tests/Service/Reader/Media/Sibling/SiblingIdRuleTest.php`
Expected: error — class not found.

- [ ] **Step 3: Write the rule**

```php
<?php

declare(strict_types=1);

namespace App\Service\Reader\Media\Sibling;

use App\Service\Reader\Media\ArticleMedia;
use App\Service\Reader\Media\MediaCandidate;
use App\Service\Reader\Media\MediaKind;

/**
 * The page's other media, named beside a found one by a bare id: the found URL
 * is the template, the id's keyed context tells sibling ids from a navigation
 * list. Derived candidates are guesses until SiblingMediaExtender verifies them.
 */
final readonly class SiblingIdRule
{
    private const string ID_STEM = '/^[A-Za-z0-9_-]{6,}$/';
    private const string NUMBERED_SUFFIX = '/-\d+$/';
    private const int MAX_SIBLINGS = 5;

    /** @return list<MediaCandidate> */
    public function derive(ArticleMedia $found, string $pageHtml): array
    {
        $derived = [];
        foreach ($found->candidates as $seed) {
            foreach ($this->siblingsOf($seed, $pageHtml) as $candidate) {
                $derived[$candidate->url] ??= $candidate;
            }
        }

        return array_values($derived);
    }

    /** @return list<MediaCandidate> */
    private function siblingsOf(MediaCandidate $seed, string $pageHtml): array
    {
        $id = $seed->kind === MediaKind::Embed ? null : self::idOf($seed->url);
        if ($id === null) {
            return [];
        }

        $candidates = [];
        foreach (KeyedOccurrences::of($pageHtml, $id) as $occurrence) {
            foreach ($this->siblingIdsIn($pageHtml, $occurrence, $id) as $siblingId => $position) {
                $candidate = $this->candidateFor($seed, $id, $siblingId, $pageHtml, $position);
                if ($candidate !== null) {
                    $candidates[] = $candidate;
                }
            }
        }

        return $candidates;
    }

    /**
     * Every other value under the seed's key whose own occurrence shares the seed's context.
     *
     * @return array<string, int> sibling id => position of its first occurrence
     */
    private function siblingIdsIn(string $pageHtml, KeyedOccurrence $seedOccurrence, string $seedId): array
    {
        $suffix = preg_match(self::NUMBERED_SUFFIX, $seedId) === 1 ? '-\d+' : '';
        $pattern = '/' . preg_quote($seedOccurrence->key, '/') . '\\\\?"?\s*[:=]\s*\\\\?"([A-Za-z0-9_-]{6,}' . $suffix . ')\\\\?"/';
        preg_match_all($pattern, $pageHtml, $matches, \PREG_OFFSET_CAPTURE);

        $ids = [];
        foreach ($matches[1] as [$id, $position]) {
            $occurrence = $id === $seedId ? null : KeyedOccurrences::at($pageHtml, $id, $position);
            if ($occurrence !== null && $occurrence->sharesContextWith($seedOccurrence)) {
                $ids[$id] ??= $position;
            }
        }

        // More than a handful of siblings is a navigation or teaser list, not the article's media.
        return \count($ids) > self::MAX_SIBLINGS ? [] : $ids;
    }

    private function candidateFor(MediaCandidate $seed, string $seedId, string $siblingId, string $pageHtml, int $position): ?MediaCandidate
    {
        if (self::namedInsideAUrl($pageHtml, $siblingId)) {
            return null;
        }
        $poster = NearbyPoster::after($pageHtml, $position);

        return $poster === null
            ? null
            : new MediaCandidate($seed->kind, str_replace($seedId, $siblingId, $seed->url), $poster);
    }

    private static function idOf(string $url): ?string
    {
        $path = parse_url($url, \PHP_URL_PATH);
        $stem = pathinfo(\is_string($path) ? $path : '', \PATHINFO_FILENAME);

        return preg_match(self::ID_STEM, $stem) === 1 ? $stem : null;
    }

    /** An id the page already names inside a URL belongs to the URL-based sources, which saw it and chose. */
    private static function namedInsideAUrl(string $pageHtml, string $id): bool
    {
        $quoted = preg_quote($id, '#');

        return preg_match('#https?://[^\s"\'<>\\\\]*' . $quoted . '#', $pageHtml) === 1
            || preg_match('#/[^\s"\'<>\\\\]*' . $quoted . '\.[a-z0-9]{2,5}#', $pageHtml) === 1;
    }
}
```

`candidateFor()` has five parameters: PHPMD's default parameter threshold is ten, but CLAUDE.md calls three a lot. If the reviewer objects, introduce a small `Sibling{id, position}` value from `siblingIdsIn()` and pass `(MediaCandidate $seed, string $seedId, Sibling $sibling, string $pageHtml)` — do not thread more scalars.

- [ ] **Step 4: Run the tests**

Run: `php bin/phpunit tests/Service/Reader/Media/Sibling`
Expected: PASS.

- [ ] **Step 5: Gates and commit**

Run from `backend/`: `composer cs:fix && composer check && composer md && composer tramp`
Expected: clean (watch phptramp: `$pageHtml` travels `derive → siblingsOf → siblingIdsIn/candidateFor` inside one class — decomposition, not a tunnel; it must not cross into a second class unread).

```bash
git add backend/src/Service/Reader/Media/Sibling/SiblingIdRule.php backend/tests/Service/Reader/Media/Sibling/SiblingIdRuleTest.php
git commit -m "feat(#800): derive a page's sibling media from a found URL and the id's keyed context"
```

---

### Task 4: `SiblingMediaExtender` — verify and append; wire into the extractor

**Files:**
- Create: `backend/src/Service/Reader/Media/Sibling/SiblingMediaExtender.php`
- Create: `backend/tests/Service/Reader/Media/Sibling/SiblingMediaExtenderTest.php`
- Modify: `backend/src/Service/Reader/Media/ArticleMedia.php` (add `with()`)
- Modify: `backend/tests/Service/Reader/Media/ArticleMediaTest.php` (one test)
- Modify: `backend/src/Service/Reader/ArticleExtractor.php:44-84`
- Modify: `backend/tests/Service/Reader/ArticleExtractorTest.php` (`extractor()`)

**Interfaces:**
- Consumes: `SiblingIdRule::derive()`, `MediaLanding::urlOf()`, `MediaUrlKind::resolve(): ?ResolvedMediaUrl{kind,url}`, `MediaCandidate::at()`.
- Produces: `SiblingMediaExtender::extend(ArticleMedia $media, string $pageHtml): ArticleMedia`; `ArticleMedia::with(list<MediaCandidate>): self` (appends, keeps `MAX_ITEMS`).

- [ ] **Step 1: Write the failing tests**

Append to `ArticleMediaTest`:

```php
    public function testWithAppendsAndKeepsTheCap(): void
    {
        $one = static fn (int $n): MediaCandidate => new MediaCandidate(MediaKind::Video, 'https://a.test/' . $n . '.mp4', 'p.jpg');
        $media = new ArticleMedia(array_map($one, range(1, ArticleMedia::MAX_ITEMS - 1)));

        $extended = $media->with([$one(98), $one(99)]);

        self::assertCount(ArticleMedia::MAX_ITEMS, $extended->candidates);
        self::assertSame('https://a.test/98.mp4', $extended->candidates[ArticleMedia::MAX_ITEMS - 1]->url);
    }
```

`SiblingMediaExtenderTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Service\Reader\Media\Sibling;

use App\Service\Fetch\DnsResolverInterface;
use App\Service\Fetch\FailoverRequestSender;
use App\Service\Fetch\IpValidator;
use App\Service\Fetch\ProxyEgressResolver;
use App\Service\Fetch\RedirectFollower;
use App\Service\Fetch\UrlGuard;
use App\Service\Reader\Media\ArticleMedia;
use App\Service\Reader\Media\DurableMediaUrl;
use App\Service\Reader\Media\EmbedProviders;
use App\Service\Reader\Media\MediaCandidate;
use App\Service\Reader\Media\MediaKind;
use App\Service\Reader\Media\MediaLanding;
use App\Service\Reader\Media\MediaUrlKind;
use App\Service\Reader\Media\Provider\YouTubeEmbedProvider;
use App\Service\Reader\Media\Sibling\SiblingIdRule;
use App\Service\Reader\Media\Sibling\SiblingMediaExtender;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class SiblingMediaExtenderTest extends TestCase
{
    private const string PAGE = '<html><body><script>self.__next_f.push([1,"'
        . '{\\"config\\":{\\"isPriority\\":\\"$undefined\\",\\"content\\":\\"taktik-analyse-video-100\\",\\"startImage\\":{\\"layouts\\":{\\"1920x1080\\":\\"https://a.test/assets/taktik~1920x1080\\"}}}},'
        . '{\\"config\\":{\\"isPriority\\":\\"$undefined\\",\\"content\\":\\"reaktion-anschlag-video-100\\",\\"startImage\\":{\\"layouts\\":{\\"1920x1080\\":\\"https://a.test/assets/reaktion~1920x1080\\"}}}}'
        . '"])</script></body></html>';

    /** @var list<string> */
    private array $requested = [];

    /** @param list<MockResponse> $responses */
    private function extender(array $responses): SiblingMediaExtender
    {
        $queue = $responses;
        $client = new MockHttpClient(function (string $method, string $url) use (&$queue): MockResponse {
            $this->requested[] = $url;

            return array_shift($queue) ?? new MockResponse('', ['http_code' => 500]);
        });
        $dns = new class implements DnsResolverInterface {
            public function resolve(string $hostname): array
            {
                return ['93.184.216.34'];
            }
        };
        $proxy = $this->createStub(ProxyEgressResolver::class);
        $proxy->method('resolve')->willReturn(null);
        $landing = new MediaLanding(
            new RedirectFollower(new FailoverRequestSender($client, $proxy), new UrlGuard($dns, new IpValidator())),
            'TestAgent/1.0',
        );

        return new SiblingMediaExtender(
            new SiblingIdRule(),
            $landing,
            new MediaUrlKind(new DurableMediaUrl(), new EmbedProviders([new YouTubeEmbedProvider()])),
        );
    }

    private static function redirect(string $location): MockResponse
    {
        return new MockResponse('', ['http_code' => 301, 'response_headers' => ['location' => $location]]);
    }

    private static function found(): ArticleMedia
    {
        return new ArticleMedia([new MediaCandidate(
            MediaKind::Stream,
            'https://cdn.test/live/taktik-analyse-video-100.m3u8',
            'https://a.test/assets/taktik~1920x1080',
        )]);
    }

    public function testKeepsADerivedStreamAtItsLanding(): void
    {
        $landing = 'https://cdn.test/v2/reaktion-anschlag-video-100/master.m3u8';
        $extended = $this->extender([self::redirect($landing), new MockResponse('#EXTM3U', ['http_code' => 200])])
            ->extend(self::found(), self::PAGE);

        self::assertCount(2, $extended->candidates);
        self::assertSame($landing, $extended->candidates[1]->url);
        self::assertSame(MediaKind::Stream, $extended->candidates[1]->kind);
        self::assertSame('https://a.test/assets/reaktion~1920x1080', $extended->candidates[1]->posterUrl);
        self::assertSame(['https://cdn.test/live/reaktion-anschlag-video-100.m3u8', $landing], $this->requested);
    }

    public function testDropsADerivedUrlTheNetworkRefuses(): void
    {
        $extended = $this->extender([new MockResponse('', ['http_code' => 404])])->extend(self::found(), self::PAGE);

        self::assertCount(1, $extended->candidates);
    }

    public function testDropsADerivedUrlThatLandsOnAnotherKind(): void
    {
        $extended = $this->extender([self::redirect('https://cdn.test/live/reaktion.mp4'), new MockResponse('', ['http_code' => 200])])
            ->extend(self::found(), self::PAGE);

        self::assertCount(1, $extended->candidates);
    }

    public function testMakesNoRequestWhenNothingIsDerived(): void
    {
        $extended = $this->extender([])->extend(self::found(), '<html><body><p>no payload</p></body></html>');

        self::assertSame([], $this->requested);
        self::assertCount(1, $extended->candidates);
    }
}
```

- [ ] **Step 2: Run them to verify they fail**

Run: `php bin/phpunit tests/Service/Reader/Media/Sibling/SiblingMediaExtenderTest.php tests/Service/Reader/Media/ArticleMediaTest.php`
Expected: errors — `with()` undefined, class not found.

- [ ] **Step 3: Write the code**

Add to `ArticleMedia`:

```php
    /** @param list<MediaCandidate> $more */
    public function with(array $more): self
    {
        return new self(\array_slice([...$this->candidates, ...$more], 0, self::MAX_ITEMS));
    }
```

`SiblingMediaExtender.php`:

```php
<?php

declare(strict_types=1);

namespace App\Service\Reader\Media\Sibling;

use App\Service\Reader\Media\ArticleMedia;
use App\Service\Reader\Media\MediaCandidate;
use App\Service\Reader\Media\MediaLanding;
use App\Service\Reader\Media\MediaUrlKind;

/** Adds the media SiblingIdRule derives, once the network has confirmed each URL: it must land 2xx on a URL of the seed's kind. */
final readonly class SiblingMediaExtender
{
    public function __construct(
        private SiblingIdRule $rule,
        private MediaLanding $landings,
        private MediaUrlKind $mediaUrlKind,
    ) {
    }

    public function extend(ArticleMedia $media, string $pageHtml): ArticleMedia
    {
        $verified = [];
        foreach ($this->rule->derive($media, $pageHtml) as $candidate) {
            $landed = $this->landed($candidate);
            if ($landed !== null) {
                $verified[] = $landed;
            }
        }

        return $media->with($verified);
    }

    private function landed(MediaCandidate $candidate): ?MediaCandidate
    {
        $landing = $this->landings->urlOf($candidate->url);
        $resolved = $landing === null ? null : $this->mediaUrlKind->resolve($landing);

        return $resolved?->kind === $candidate->kind ? $candidate->at($resolved->url) : null;
    }
}
```

`ArticleExtractor`: add `private readonly SiblingMediaExtender $siblings,` as the seventh constructor argument (with the `use`), and change the media argument of `clean()`:

```php
            $this->siblings->extend($this->streamLocations->resolve($media), $page->html),
```

`ArticleExtractorTest::extractor()`: build once and share:

```php
        $landing = new MediaLanding($redirects, 'TestAgent/1.0');

        return new ArticleExtractor(
            …,
            $this->mediaScanner(),
            new StreamLocationResolver($landing, $this->urlKind()),
            new SiblingMediaExtender(new SiblingIdRule(), $landing, $this->urlKind()),
        );
```

- [ ] **Step 4: Run the suites**

Run: `php bin/phpunit tests/Service/Reader`
Expected: PASS — no existing extractor fixture derives anything, so no test's mocked response order changes.

- [ ] **Step 5: Gates and commit**

Run from `backend/`: `composer cs:fix && composer check && composer md && composer tramp`
Expected: clean.

```bash
git add backend/src/Service/Reader/Media/Sibling/SiblingMediaExtender.php backend/src/Service/Reader/Media/ArticleMedia.php backend/src/Service/Reader/ArticleExtractor.php backend/tests/Service/Reader/Media/Sibling/SiblingMediaExtenderTest.php backend/tests/Service/Reader/Media/ArticleMediaTest.php backend/tests/Service/Reader/ArticleExtractorTest.php
git commit -m "feat(#800): verify derived sibling media on the network and append it to the article's media"
```

---

### Task 5: The ZDF shape end to end

**Files:**
- Create: `backend/tests/Fixtures/reader/media/zdf-sibling-video-configs.html`
- Modify: `backend/tests/Service/Reader/Media/HostAgnosticDiscoveryTest.php` (append one test)
- Modify: `backend/tests/Service/Reader/ArticleExtractorTest.php` (append one test)

- [ ] **Step 1: Write the fixture**

The page shape reduced: a JSON-LD seed, four figures in the body (one per video, the ZDF `~384x216` rendition), the flight payload with four player configs and a seven-entry navigation list under another key, and a link that names the seed inside a path.

```html
<!DOCTYPE html>
<html lang="de"><head><title>Drohnen in Leipzig: Sabotage oder hybride Kriegsführung? — Site</title>
<meta property="og:image" content="https://www.zdfheute.de/assets/russland-taktik-experten-analyse-100~1280x720?cb=1">
<script type="application/ld+json">{"@context":"https://schema.org","@type":"NewsArticle","headline":"Drohnen in Leipzig","video":{"@type":"VideoObject","name":"Experten-Analyse","contentUrl":"https://www.zdfheute.de/api/video/taktik-russland-drohnen-vorfall-experten-analyse-video-100.m3u8","embedUrl":"https://ngp.zdf.de/miniplayer/embed/?mediaID=/zdf/nachrichten/zdfheute-xpress/taktik-russland-drohnen-vorfall-experten-analyse-video-100","thumbnailUrl":["https://www.zdfheute.de/assets/russland-taktik-experten-analyse-100~1920x1080?cb=1"]}}</script>
</head>
<body>
  <nav><a href="/">Start</a><a href="/politik">Politik</a></nav>
  <main>
    <article>
      <h1>Drohnen in Leipzig: Sabotage oder hybride Kriegsführung?</h1>
      <p>Nach dem Drohnenvorfall am Flughafen Leipzig/Halle streiten Fachleute darüber, ob es sich um Sabotage oder um einen Akt hybrider Kriegsführung handelt. Die Bundesregierung prüft Strafmaßnahmen, während die Ermittler weitere Spuren sichern.</p>
      <figure><img src="https://www.zdfheute.de/assets/russland-taktik-experten-analyse-100~384x216?cb=1" alt="Experten-Analyse"><figcaption>Experten analysieren die russische Taktik.</figcaption></figure>
      <p>Zwei Verdächtige sollen inzwischen identifiziert sein. Die Behörden gehen davon aus, dass die Drohne aus dem Umland gestartet wurde und gezielt auf das Frachtzentrum zusteuerte.</p>
      <figure><img src="https://www.zdfheute.de/assets/reaktion-deutschland-russland-drohnen-anschlag-clean-100~384x216?cb=1" alt="Reaktion"><figcaption>Investigativ-Journalist Bojan Pancevski zur Reaktion der Bundesregierung.</figcaption></figure>
      <p>Der Vorfall reiht sich in eine Serie von Störungen an deutschen Flughäfen ein, die seit dem Frühjahr registriert werden und deren Herkunft in den meisten Fällen ungeklärt bleibt.</p>
      <figure><img src="https://www.zdfheute.de/assets/drohnenvorfall-flughafen-russland-klauser-102~384x216?cb=1" alt="Drohnenfund"><figcaption>Spannungen mit Russland nach Drohnenfund.</figcaption></figure>
      <p>Sicherheitsexperten fordern, die Luftraumüberwachung an Verkehrsflughäfen auszubauen und die Zuständigkeiten zwischen Bund und Ländern klarer zu regeln.</p>
      <figure><img src="https://www.zdfheute.de/assets/260826-0000-clip-2-hju-100~384x216?cb=1" alt="Sgs-Lange-hju"><figcaption>Der Sicherheitsexperte im Gespräch.</figcaption></figure>
      <a href="/video/zdfheute-xpress/taktik-russland-drohnen-vorfall-experten-analyse-video-100.html">Zum Video</a>
    </article>
  </main>
  <script>self.__next_f.push([1,"{\"nav\":[{\"type\":\"link\",\"sophoraId\":\"zdfheute-startseite-108\",\"externalSophoraId\":\"$undefined\"},{\"type\":\"link\",\"sophoraId\":\"zdfheute-politik-100\",\"externalSophoraId\":\"$undefined\"},{\"type\":\"link\",\"sophoraId\":\"zdfheute-wirtschaft-100\",\"externalSophoraId\":\"$undefined\"},{\"type\":\"link\",\"sophoraId\":\"zdfheute-panorama-100\",\"externalSophoraId\":\"$undefined\"},{\"type\":\"link\",\"sophoraId\":\"zdfheute-sport-100\",\"externalSophoraId\":\"$undefined\"},{\"type\":\"link\",\"sophoraId\":\"zdfheute-wissen-100\",\"externalSophoraId\":\"$undefined\"},{\"type\":\"link\",\"sophoraId\":\"taktik-russland-drohnen-vorfall-experten-analyse-video-100\",\"externalSophoraId\":\"$undefined\"}],\"modules\":[{\"config\":{\"isPriority\":\"$undefined\",\"content\":\"taktik-russland-drohnen-vorfall-experten-analyse-video-100\",\"startImage\":{\"title\":\"Experten-Analyse\",\"layouts\":{\"384x216\":\"https://www.zdfheute.de/assets/russland-taktik-experten-analyse-100~384x216?cb=1\",\"1920x1080\":\"https://www.zdfheute.de/assets/russland-taktik-experten-analyse-100~1920x1080?cb=1\"}}}},{\"config\":{\"isPriority\":\"$undefined\",\"content\":\"reaktion-deutschland-russland-drohnen-anschlag-video-100\",\"startImage\":{\"title\":\"Reaktion\",\"layouts\":{\"384x216\":\"https://www.zdfheute.de/assets/reaktion-deutschland-russland-drohnen-anschlag-clean-100~384x216?cb=1\",\"1920x1080\":\"https://www.zdfheute.de/assets/reaktion-deutschland-russland-drohnen-anschlag-clean-100~1920x1080?cb=1\"}}}},{\"config\":{\"isPriority\":\"$undefined\",\"content\":\"drohnenvorfall-flughafen-russland-klauser-100\",\"startImage\":{\"title\":\"Drohnenfund\",\"layouts\":{\"384x216\":\"https://www.zdfheute.de/assets/drohnenvorfall-flughafen-russland-klauser-102~384x216?cb=1\",\"1920x1080\":\"https://www.zdfheute.de/assets/drohnenvorfall-flughafen-russland-klauser-102~1920x1080?cb=1\"}}}},{\"config\":{\"isPriority\":\"$undefined\",\"content\":\"sgs-lange-wiesel-100\",\"startImage\":{\"title\":\"Sgs-Lange-hju\",\"layouts\":{\"384x216\":\"https://www.zdfheute.de/assets/260826-0000-clip-2-hju-100~384x216?cb=1\",\"1920x1080\":\"https://www.zdfheute.de/assets/260826-0000-clip-2-hju-100~1920x1080?cb=1\"}}}}]}"])</script>
  <footer>© ZDF</footer>
</body></html>
```

The seed's `sophoraId` entry makes the navigation a context of the seed with six siblings — the list cap must drop it; the four `config` entries are the article's modules.

- [ ] **Step 2: Write the tests**

Append to `HostAgnosticDiscoveryTest` (derivation is not a source — the scan still yields the declared one):

```php
    public function testTheZdfShapeScansToItsOneDeclaredStream(): void
    {
        $media = $this->scanner()->scan(
            $this->fixture('zdf-sibling-video-configs.html'),
            'https://www.zdfheute.de/politik/deutschland/leipzig-drohne-sabotage-100.html',
        );

        self::assertCount(1, $media->candidates);
        self::assertSame(MediaKind::Stream, $media->candidates[0]->kind);
    }
```

Append to `ArticleExtractorTest`:

```php
    /** zdfheute 1374175: three of four videos exist only as ids in the payload; the page's own template recovers them. */
    public function testRecoversTheVideosThePageNamesOnlyByASiblingId(): void
    {
        $html = (string) file_get_contents(__DIR__ . '/../../Fixtures/reader/media/zdf-sibling-video-configs.html');
        $landing = static fn (string $name): string => 'https://zdfvod.akamaized.net/i/mp4/none/zdf/26/09/' . $name . '/1/' . $name . ',_508k_p9,_6628k_p61,v17.mp4.csmil/master.m3u8';
        $pair = static fn (string $name): array => [
            new MockResponse('', ['http_code' => 301, 'response_headers' => ['location' => $landing($name)]]),
            new MockResponse('#EXTM3U', ['http_code' => 200]),
        ];
        $result = $this->extractor(
            [
                new MockResponse($html, ['http_code' => 200]),
                ...$pair('260902_russland_taktik_viu'),
                ...$pair('260902_leipzig_verdaechtige_interview_hli'),
                ...$pair('260902_clip_12_mom'),
                ...$pair('260825_hju_sgs_lange'),
            ],
            ['www.zdfheute.de' => ['93.184.216.34'], 'zdfvod.akamaized.net' => ['93.184.216.35']],
        )->extract('https://www.zdfheute.de/politik/deutschland/leipzig-drohne-sabotage-100.html');

        self::assertTrue($result->ok);
        $body = (string) $result->contentHtml;
        self::assertSame(4, substr_count($body, '<video'), 'the declared stream and its three siblings');
        foreach (['260902_russland_taktik_viu', '260902_leipzig_verdaechtige_interview_hli', '260902_clip_12_mom', '260825_hju_sgs_lange'] as $name) {
            self::assertStringContainsString('src="' . $landing($name) . '"', $body);
        }
        self::assertSame(0, substr_count($body, '<img'), 'each player replaced its figure image; no picture was added');
        self::assertStringNotContainsString('zdfheute-politik-100', $body);
        self::assertLessThan(
            (int) strpos($body, 'Zwei Verdächtige sollen'),
            (int) strpos($body, '260902_russland_taktik_viu'),
            'the first player sits where its figure stood, before the second paragraph',
        );
    }
```

Request order matters: the seed is followed first by `StreamLocationResolver`, then the three siblings in payload order. If the sanitizer entity-encodes the commas or `=` in `src`, decode the body's `src` values before asserting rather than loosening the URLs. `PageMediaInserter::apply()` replaces each figure's `<img>` with the `<video>` (a `poster` attribute, no `<img>`), and `ReaderLeadImage` adds no hero above a body that already showed the lead — hence zero `<img>`. If the count differs, the inserter did not reconcile a poster: fix the fixture's still names (they must share `ImageIdentity` tokens with the payload's), not the expectation.

- [ ] **Step 3: Run them**

Run: `php bin/phpunit tests/Service/Reader/Media/HostAgnosticDiscoveryTest.php tests/Service/Reader/ArticleExtractorTest.php --filter 'ZdfShape|SiblingId'`
Expected: PASS. A failure here is a rule or fixture defect; fix the code, not the expectation.

- [ ] **Step 4: Run the reader suites**

Run: `php bin/phpunit tests/Service/Reader`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add backend/tests/Fixtures/reader/media/zdf-sibling-video-configs.html backend/tests/Service/Reader/Media/HostAgnosticDiscoveryTest.php backend/tests/Service/Reader/ArticleExtractorTest.php
git commit -m "test(#800): the ZDF payload shape yields all four players through the extractor"
```

---

### Task 6: Cache version and verification

**Files:**
- Modify: `frontend/src/app/reader/reader-cache.service.ts` (`VERSION` 17 → 18, comment in the file's style: `// v18: v17 records hold one player for a page whose other videos are named only by a sibling id in a script payload (#800).`)

- [ ] **Step 1: Bump, test, gate**

Run: `docker compose exec -T frontend npx jest src/app/reader/reader-cache.service.spec.ts`; from `frontend/`: `npm run check`.
Expected: PASS / clean.

```bash
git add frontend/src/app/reader/reader-cache.service.ts
git commit -m "fix(#800): bump the reader cache version so cached ZDF articles refetch their sibling players"
```

- [ ] **Step 2: Backend gates and both legs, one after the other**

From `backend/`: `composer check && composer md && composer tramp && php bin/phpunit && composer infection:diff`, then from the repo root `docker compose exec php vendor/bin/phpunit`.
Expected: all green; MSI at or above `minMsi`.

- [ ] **Step 3: Corpus statement**

Run the scanner + extender (with a `MediaLanding` whose client is a `MockHttpClient` answering 200 to everything) over every `backend/tests/Fixtures/**/*.html`: only `zdf-sibling-video-configs.html` derives anything, exactly three candidates. State the file count and the three derived URLs in the PR.

- [ ] **Step 4: Refresh the stack and check live**

`docker compose restart php worker`, then in Chrome open the local copy of the zdfheute article (add the feed if needed, or reload production entry 1374175 after the next deploy): four players, each where its still stood; the navigation ids never appear.

- [ ] **Step 5: PR**

Branch `feature/800-sibling-id-media` → `develop`, body `Closes #800` with the corpus statement.
