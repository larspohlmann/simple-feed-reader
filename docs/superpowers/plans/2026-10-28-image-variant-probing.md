# Image Variant Probing Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Find and save the image variants the magazine planner needs: read undeclared `media:` originals, and, when a lead image is verified, probe on-demand resizers and CMS size variants so its rendition ladder holds a rung for every list-view width.

**Architecture:** The parser binds undeclared standard namespace prefixes before streaming. `ImageVerifier`, after measuring a lead, asks a new `RenditionProbe` for rungs: a resizer check (`?w=264`, ranged GET) short-circuits to computed rungs; otherwise a size-guessing walk HEADs `-WxH` siblings band by band. All HTTP runs through a new `GuardedImageProbe` on `RedirectFollower`'s SSRF-guarded chain. No migration, no frontend change.

**Tech Stack:** PHP 8.4, Symfony 7.4 HttpClient (`MockHttpClient` in tests), Doctrine embeddable `EntryImage`, PHPUnit 12.

**Spec:** `docs/superpowers/specs/2026-10-28-image-variant-probing-design.md` — read it first; its "Refined while planning" section lists four decisions made after the grilling.

**Issue / branch:** #1855, `feature/1855-image-variant-probing` (off `develop`). Commits: `type(#1855): …`.

## Global Constraints

- Target widths, exactly: `264, 520, 680, 1100, 1360`. Nothing wider than 1360 is ever stored as a probed rung.
- Guessed CMS widths, exactly: `300, 384, 512, 640, 768, 1024, 1280`.
- Resizer check: `w=264`, accepted when the measured width is within ±1 of 264.
- Ranged GET reads the first `65536` bytes (`Range: bytes=0-65535`); probe timeout `4` s; at most 3 redirects.
- HEAD fallback to a ranged GET on `403`, `405`, `501` only.
- Probing never changes an `ImageVerifyOutcome`, and never throws out of `RenditionProbe`.
- HTTP statuses are always `Response::HTTP_*` constants, in `src` and `tests`.
- CLAUDE.md applies in full: `final readonly`, role folders (`Model/`, `Pass/`, `Support/`, interface folders), no abbreviations, no boolean flag parameters, default to no comment, PHPMD-clean files, phptramp clean.
- Every touched `src` file passes `composer cs`, `composer stan`, `composer md`, `composer tramp`.

## File Map

| File | Role |
|---|---|
| `backend/src/Service/Parser/Model/FeedNamespace.php` | modify: `conventionalPrefix()` |
| `backend/src/Service/Parser/Support/UndeclaredPrefixes.php` | create: binds undeclared prefixes on the root |
| `backend/src/Service/Parser/Pass/StreamedFeedDocument.php` | modify: spool `UndeclaredPrefixes::declaredIn($xml)` |
| `backend/tests/Fixtures/feeds/smarticular-undeclared-media.xml` | create: trimmed real feed |
| `backend/src/Service/Fetch/RedirectFollower.php` | modify: `followHead()` |
| `backend/src/Service/Image/GuardedImageProbe/GuardedImageProbeInterface.php` | create |
| `backend/src/Service/Image/GuardedImageProbe/GuardedImageProbe.php` | create: HEAD + ranged GET |
| `backend/src/Service/Image/Support/RenditionTargets.php` | create: targets, bands, coverage |
| `backend/src/Service/Image/Support/SizedImageUrl.php` | create: `-WxH` suffix helpers |
| `backend/src/Service/Image/Support/WidthQuery.php` | create: set `w=` on a URL |
| `backend/src/Service/Image/Model/LeadImageModel.php` | create: the measured lead + known widths |
| `backend/src/Service/Image/ResizerRungs.php` | create |
| `backend/src/Service/Image/Pass/SizeWalk.php` | create: per-call walk state |
| `backend/src/Service/Image/GuessedSizeRungs.php` | create |
| `backend/src/Service/Image/RenditionProbe.php` | create: resizer first, else guessing |
| `backend/src/Service/Image/ImageVerifier.php` | modify: store probed rungs after a measurement |
| `backend/tests/Support/StubGuardedImageProbe.php` | create: test double |
| `backend/tests/Support/RenditionProbes.php` | create: test factory for `RenditionProbe` |
| tests mirroring each `src` file | create/modify |

---

### Task 1: Undeclared namespace prefixes

**Files:**
- Modify: `backend/src/Service/Parser/Model/FeedNamespace.php`
- Create: `backend/src/Service/Parser/Support/UndeclaredPrefixes.php`
- Modify: `backend/src/Service/Parser/Pass/StreamedFeedDocument.php` (`parse()`, the `self::spooled($xml)` call)
- Create: `backend/tests/Fixtures/feeds/smarticular-undeclared-media.xml`
- Test: `backend/tests/Service/Parser/Support/UndeclaredPrefixesTest.php`, `backend/tests/Service/Parser/UndeclaredMediaPrefixTest.php`

**Interfaces:**
- Produces: `FeedNamespace::conventionalPrefix(): ?string`; `UndeclaredPrefixes::declaredIn(string $xml): string`.

- [ ] **Step 1: Create the fixture** — the real smarticular feed's head (namespaces exactly as shipped: `content`, `wfw`, `dc`, `atom`, `sy`, `slash`, **no `media`**) and one item:

```xml
<?xml version="1.0" encoding="UTF-8"?><rss version="2.0"
	xmlns:content="http://purl.org/rss/1.0/modules/content/"
	xmlns:wfw="http://wellformedweb.org/CommentAPI/"
	xmlns:dc="http://purl.org/dc/elements/1.1/"
	xmlns:atom="http://www.w3.org/2005/Atom"
	xmlns:sy="http://purl.org/rss/1.0/modules/syndication/"
	xmlns:slash="http://purl.org/rss/1.0/modules/slash/"
	>
<channel>
	<title>smarticular Verlag</title>
	<link>https://www.smarticular.net/</link>
	<description>einfach nachhaltiger leben</description>
	<item>
		<title>Bröselknödel selber machen</title>
		<link>https://www.smarticular.net/broeselknoedel-rezept/</link>
		<guid isPermaLink="false">https://www.smarticular.net/?p=201250</guid>
		<pubDate>Tue, 27 Oct 2026 14:14:33 +0000</pubDate>
		<description><![CDATA[<p>smarticular.net -</p>
<div><img src="https://www.smarticular.net/wp-content/uploads/2026/10/broeselknoedel-rezept-202x202.jpg"></div>
<p>Mit diesem einfachen Rezept wird aus Paniermehl eine Suppeneinlage.</p>]]></description>
		<media:content url="https://www.smarticular.net/wp-content/uploads/2026/10/broeselknoedel-rezept.jpg" fileSize="111598" type="image/jpeg"
medium="image" isDefault="true">
			<media:credit role="photographer" scheme="urn:ebu">smarticular Verlag</media:credit>
		</media:content>
	</item>
</channel>
</rss>
```

- [ ] **Step 2: Write the failing tests**

`backend/tests/Service/Parser/UndeclaredMediaPrefixTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Service\Parser;

use App\Tests\Support\FeedFormatParsers;
use PHPUnit\Framework\TestCase;

final class UndeclaredMediaPrefixTest extends TestCase
{
    public function testAnUndeclaredMediaContentOriginalWinsOverTheBodyThumbnail(): void
    {
        $feed = FeedFormatParsers::feed((string) file_get_contents(__DIR__ . '/../../Fixtures/feeds/smarticular-undeclared-media.xml'));

        self::assertSame(
            'https://www.smarticular.net/wp-content/uploads/2026/10/broeselknoedel-rezept.jpg',
            $feed->entries[0]->media->image?->url,
        );
    }
}
```

`backend/tests/Service/Parser/Support/UndeclaredPrefixesTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Service\Parser\Support;

use App\Service\Parser\Support\UndeclaredPrefixes;
use PHPUnit\Framework\TestCase;

final class UndeclaredPrefixesTest extends TestCase
{
    public function testDeclaresAnUndeclaredPrefixOnTheRootElement(): void
    {
        $xml = '<?xml version="1.0"?><!-- c --><rss version="2.0"><item><media:content url="x"/></item></rss>';

        self::assertSame(
            '<?xml version="1.0"?><!-- c --><rss xmlns:media="http://search.yahoo.com/mrss/" version="2.0">'
            . '<item><media:content url="x"/></item></rss>',
            UndeclaredPrefixes::declaredIn($xml),
        );
    }

    public function testDeclaresEachUndeclaredPrefixInEnumOrder(): void
    {
        $xml = '<rss><item><itunes:image href="x"/><dc:creator>A</dc:creator></item></rss>';

        self::assertSame(
            '<rss xmlns:dc="http://purl.org/dc/elements/1.1/" xmlns:itunes="http://www.itunes.com/dtds/podcast-1.0.dtd">'
            . '<item><itunes:image href="x"/><dc:creator>A</dc:creator></item></rss>',
            UndeclaredPrefixes::declaredIn($xml),
        );
    }

    public function testLeavesADeclaredPrefixAlone(): void
    {
        $xml = '<rss xmlns:media="http://search.yahoo.com/mrss/"><media:content url="x"/></rss>';

        self::assertSame($xml, UndeclaredPrefixes::declaredIn($xml));
    }

    public function testLeavesAFeedWithoutPrefixesAlone(): void
    {
        $xml = '<rss><channel><title>T</title></channel></rss>';

        self::assertSame($xml, UndeclaredPrefixes::declaredIn($xml));
    }

    public function testIgnoresAPrefixedWordInsideText(): void
    {
        $xml = '<rss><title>media:content is a tag</title></rss>';

        self::assertSame($xml, UndeclaredPrefixes::declaredIn($xml));
    }
}
```

- [ ] **Step 3: Run them to verify they fail**

Run (from `backend/`): `php bin/phpunit tests/Service/Parser/UndeclaredMediaPrefixTest.php tests/Service/Parser/Support/UndeclaredPrefixesTest.php`
Expected: the parser test FAILS with the `-202x202.jpg` URL; the support test errors on the missing class.

- [ ] **Step 4: Implement**

`FeedNamespace` — add:

```php
    public function conventionalPrefix(): ?string
    {
        return match ($this) {
            self::Atom10 => 'atom',
            self::Rdf => 'rdf',
            self::DublinCore => 'dc',
            self::Content => 'content',
            self::WellFormedWeb => 'wfw',
            self::MediaRss => 'media',
            self::Itunes => 'itunes',
            self::Podcast => 'podcast',
            self::PodloveSimpleChapters => 'psc',
            self::GooglePlay => 'googleplay',
            self::Atom03, self::Rss1 => null,
        };
    }
```

`backend/src/Service/Parser/Support/UndeclaredPrefixes.php`:

```php
<?php

declare(strict_types=1);

namespace App\Service\Parser\Support;

use App\Service\Parser\Model\FeedNamespace;

/** WordPress plugins emit `<media:content>` without `xmlns:media`; libxml then files the element under no namespace. */
final class UndeclaredPrefixes
{
    private const string ROOT_START_TAG = '/<(?![?!])[A-Za-z_][\w.:-]*/';

    public static function declaredIn(string $xml): string
    {
        $declarations = self::missingDeclarations($xml);
        if ($declarations === '') {
            return $xml;
        }

        return (string) preg_replace_callback(
            self::ROOT_START_TAG,
            static fn (array $match): string => $match[0] . $declarations,
            $xml,
            1,
        );
    }

    private static function missingDeclarations(string $xml): string
    {
        $declarations = '';
        foreach (FeedNamespace::cases() as $namespace) {
            $prefix = $namespace->conventionalPrefix();
            if ($prefix !== null && self::usesUndeclared($xml, $prefix)) {
                $declarations .= \sprintf(' xmlns:%s="%s"', $prefix, $namespace->value);
            }
        }

        return $declarations;
    }

    private static function usesUndeclared(string $xml, string $prefix): bool
    {
        return str_contains($xml, '<' . $prefix . ':') && !str_contains($xml, 'xmlns:' . $prefix . '=');
    }

    private function __construct()
    {
    }
}
```

`StreamedFeedDocument::parse()` — change the reader construction to:

```php
            $document = new self(\XMLReader::fromStream(
                self::spooled(UndeclaredPrefixes::declaredIn($xml)),
                null,
                LIBXML_NONET,
            ));
```

(add `use App\Service\Parser\Support\UndeclaredPrefixes;`).

The enum-order test expects `dc` before `itunes` because `DublinCore` precedes `Itunes` in `FeedNamespace`; keep the cases' order.

- [ ] **Step 5: Run the tests, then the whole parser suite**

Run: `php bin/phpunit tests/Service/Parser`
Expected: all PASS.

- [ ] **Step 6: Commit**

```bash
git add backend/src/Service/Parser backend/tests/Service/Parser backend/tests/Fixtures/feeds/smarticular-undeclared-media.xml
git commit -m "fix(#1855): bind standard prefixes a feed uses without declaring them"
```

---

### Task 2: `RedirectFollower::followHead()`

**Files:**
- Modify: `backend/src/Service/Fetch/RedirectFollower.php`
- Test: `backend/tests/Service/Fetch/RedirectFollowerTest.php`

**Interfaces:**
- Produces: `RedirectFollower::followHead(string $url, array $options, RedirectBudget $budget): LandedResponse` — same guard, budget and exceptions as `follow()`, every hop sent as `HEAD`.

- [ ] **Step 1: Write the failing test** (append to `RedirectFollowerTest`):

```php
    public function testFollowHeadSendsEveryHopAsHead(): void
    {
        $methods = [];
        $follower = $this->follower(static function (string $method) use (&$methods): MockResponse {
            $methods[] = $method;

            return \count($methods) === 1
                ? self::redirect('https://cdn.example.com/a.jpg')
                : new MockResponse('', ['http_code' => Response::HTTP_OK]);
        });

        $landed = $follower->followHead('https://example.com/a.jpg', [], new RedirectBudget(5));

        self::assertSame(['HEAD', 'HEAD'], $methods);
        self::assertSame('https://cdn.example.com/a.jpg', $landed->url);
    }

    public function testFollowStillSendsGet(): void
    {
        $methods = [];
        $follower = $this->follower(static function (string $method) use (&$methods): MockResponse {
            $methods[] = $method;

            return new MockResponse('', ['http_code' => Response::HTTP_OK]);
        });

        $follower->follow('https://example.com/a.jpg', [], new RedirectBudget(5));

        self::assertSame(['GET'], $methods);
    }
```

(add `use Symfony\Component\HttpFoundation\Response;`).

- [ ] **Step 2: Run to verify it fails**

Run: `php bin/phpunit --filter 'FollowHead|FollowStillSendsGet' tests/Service/Fetch/RedirectFollowerTest.php`
Expected: FAIL, undefined method `followHead`.

- [ ] **Step 3: Implement** — rename the loop body into a private `land()` that takes the method, and keep `follow()`'s docblock on it:

```php
    public function follow(string $url, array $options, RedirectBudget $budget): LandedResponse
    {
        return $this->land('GET', $url, $options, $budget);
    }

    /**
     * @param array<string, mixed> $options
     *
     * @throws RedirectChainException as follow()
     */
    public function followHead(string $url, array $options, RedirectBudget $budget): LandedResponse
    {
        return $this->land('HEAD', $url, $options, $budget);
    }

    /** @param array<string, mixed> $options */
    private function land(string $method, string $url, array $options, RedirectBudget $budget): LandedResponse
    {
        $currentUrl = $url;
        while (true) {
            self::assertTimeLeft($url, $budget);
            $response = $this->send($method, $currentUrl, self::withinBudget($options, $budget));
            // … the rest of today's loop, unchanged
        }
    }
```

and `send(string $method, string $url, array $options)` passes `$method` to `$this->requestSender->send(...)` instead of `'GET'`. Keep `follow()`'s existing docblock (the `@param`/`@throws` lines) on `follow()`.

- [ ] **Step 4: Run the Fetch suite**

Run: `php bin/phpunit tests/Service/Fetch`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add backend/src/Service/Fetch/RedirectFollower.php backend/tests/Service/Fetch/RedirectFollowerTest.php
git commit -m "feat(#1855): RedirectFollower follows a HEAD through the same guard"
```

---

### Task 3: `GuardedImageProbe`

**Files:**
- Create: `backend/src/Service/Image/GuardedImageProbe/GuardedImageProbeInterface.php`
- Create: `backend/src/Service/Image/GuardedImageProbe/GuardedImageProbe.php`
- Create: `backend/tests/Support/StubGuardedImageProbe.php`
- Test: `backend/tests/Service/Image/GuardedImageProbe/GuardedImageProbeTest.php`

**Interfaces:**
- Consumes: `RedirectFollower::follow()`, `followHead()` (Task 2); `ImageDimensionsModel::fromBytes()`; `MediaType::of()`.
- Produces:
  - `GuardedImageProbeInterface::exists(string $imageUrl): bool` — HEAD; true on `200` with an `image/*` type; on `403/405/501` falls back to `measure()` succeeding; false on anything else, including transport and guard failures.
  - `GuardedImageProbeInterface::measure(string $imageUrl): ImageDimensionsModel` — ranged GET of the first 64 KiB; accepts `200` or `206`; throws `GuardedImageUnavailableException` on any failure or undecodable header.
  - `StubGuardedImageProbe`: `willExist(string $url)`, `willMeasure(string $url, int $width, int $height)`, `requestedUrls(): list<string>`; unknown URLs don't exist and fail to measure.

- [ ] **Step 1: Write the stub** (`backend/tests/Support/StubGuardedImageProbe.php`):

```php
<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Service\Image\Exception\GuardedImageUnavailableException;
use App\Service\Image\GuardedImageProbe\GuardedImageProbeInterface;
use App\Service\Image\Model\ImageDimensionsModel;

final class StubGuardedImageProbe implements GuardedImageProbeInterface
{
    /** @var array<string, true> */
    private array $existing = [];

    /** @var array<string, ImageDimensionsModel> */
    private array $measured = [];

    /** @var list<string> */
    private array $requested = [];

    public function willExist(string $url): void
    {
        $this->existing[$url] = true;
    }

    public function willMeasure(string $url, int $width, int $height): void
    {
        $this->measured[$url] = new ImageDimensionsModel($width, $height);
    }

    /** @return list<string> */
    public function requestedUrls(): array
    {
        return $this->requested;
    }

    public function exists(string $imageUrl): bool
    {
        $this->requested[] = $imageUrl;

        return isset($this->existing[$imageUrl]);
    }

    public function measure(string $imageUrl): ImageDimensionsModel
    {
        $this->requested[] = $imageUrl;

        return $this->measured[$imageUrl] ?? throw new GuardedImageUnavailableException('not stubbed: ' . $imageUrl);
    }
}
```

- [ ] **Step 2: Write the failing tests** (`GuardedImageProbeTest`), wired like `GuardedImageDownloaderTest` (real `UrlGuard` over a fake resolver mapping `img.example.com` to `93.184.216.34`, `FetchWiring::redirectFollower`, `NoEgressProxy`):

```php
<?php

declare(strict_types=1);

namespace App\Tests\Service\Image\GuardedImageProbe;

use App\Service\Fetch\DnsResolver\DnsResolverInterface;
use App\Service\Fetch\IpValidator;
use App\Service\Fetch\UrlGuard;
use App\Service\Image\Exception\GuardedImageUnavailableException;
use App\Service\Image\GuardedImageProbe\GuardedImageProbe;
use App\Tests\Support\FetchWiring;
use App\Tests\Support\NoEgressProxy;
use App\Tests\Support\PngImageFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\HttpFoundation\Response;

final class GuardedImageProbeTest extends TestCase
{
    use NoEgressProxy;

    private const string IMAGE_URL = 'https://img.example.com/a-768x432.jpg';

    /** @var list<array{method: string, headers: list<string>}> */
    private array $requests = [];

    /** @param list<MockResponse> $responses */
    private function probe(array $responses, string $address = '93.184.216.34'): GuardedImageProbe
    {
        $client = new MockHttpClient(function (string $method, string $url, array $options) use (&$responses): MockResponse {
            $this->requests[] = ['method' => $method, 'headers' => $options['headers']];

            return array_shift($responses) ?? new MockResponse('', ['http_code' => Response::HTTP_NOT_FOUND]);
        });
        $resolver = new class ($address) implements DnsResolverInterface {
            public function __construct(private readonly string $address)
            {
            }

            public function resolve(string $hostname): array
            {
                return [$this->address];
            }
        };

        return new GuardedImageProbe(
            FetchWiring::redirectFollower($client, $this->noEgressProxy(), new UrlGuard($resolver, new IpValidator())),
            'TestAgent/1.0',
        );
    }

    private static function image(int $status = Response::HTTP_OK, string $type = 'image/jpeg', string $body = ''): MockResponse
    {
        return new MockResponse($body, ['http_code' => $status, 'response_headers' => ['content-type' => [$type]]]);
    }

    public function testAnImageAnsweringHeadExists(): void
    {
        self::assertTrue($this->probe([self::image()])->exists(self::IMAGE_URL));
        self::assertSame('HEAD', $this->requests[0]['method']);
    }

    public function testAMissingImageDoesNotExist(): void
    {
        self::assertFalse($this->probe([self::image(Response::HTTP_NOT_FOUND)])->exists(self::IMAGE_URL));
    }

    public function testAnHtmlPageAnsweringOkIsNoImage(): void
    {
        self::assertFalse($this->probe([self::image(type: 'text/html')])->exists(self::IMAGE_URL));
    }

    /** @return iterable<string, array{int}> */
    public static function refusedHeadStatuses(): iterable
    {
        yield 'forbidden' => [Response::HTTP_FORBIDDEN];
        yield 'method not allowed' => [Response::HTTP_METHOD_NOT_ALLOWED];
        yield 'not implemented' => [Response::HTTP_NOT_IMPLEMENTED];
    }

    #[DataProvider('refusedHeadStatuses')]
    public function testARefusedHeadFallsBackToARangedGet(int $status): void
    {
        $probe = $this->probe([
            self::image($status),
            self::image(Response::HTTP_PARTIAL_CONTENT, 'image/png', PngImageFactory::bytes(768, 432)),
        ]);

        self::assertTrue($probe->exists(self::IMAGE_URL));
        self::assertSame('GET', $this->requests[1]['method']);
    }

    public function testAnotherErrorStatusDoesNotFallBack(): void
    {
        self::assertFalse($this->probe([self::image(Response::HTTP_GONE)])->exists(self::IMAGE_URL));
        self::assertCount(1, $this->requests);
    }

    public function testABlockedAddressDoesNotExist(): void
    {
        self::assertFalse($this->probe([self::image()], '10.0.0.1')->exists(self::IMAGE_URL));
        self::assertSame([], $this->requests);
    }

    public function testMeasureAsksForTheFirst64KibOnly(): void
    {
        $this->probe([self::image(Response::HTTP_PARTIAL_CONTENT, 'image/png', PngImageFactory::bytes(264, 149))])
            ->measure(self::IMAGE_URL);

        self::assertContains('Range: bytes=0-65535', $this->requests[0]['headers']);
    }

    public function testMeasureReadsThePartialHeader(): void
    {
        $dimensions = $this->probe([self::image(Response::HTTP_PARTIAL_CONTENT, 'image/png', PngImageFactory::bytes(264, 149))])
            ->measure(self::IMAGE_URL);

        self::assertSame([264, 149], [$dimensions->width, $dimensions->height]);
    }

    public function testMeasureAcceptsAServerIgnoringTheRange(): void
    {
        $bytes = PngImageFactory::bytes(1200, 630) . str_repeat("\0", 200_000);

        $dimensions = $this->probe([self::image(Response::HTTP_OK, 'image/png', $bytes)])->measure(self::IMAGE_URL);

        self::assertSame(1200, $dimensions->width);
    }

    public function testMeasureRejectsAMissingImage(): void
    {
        $this->expectException(GuardedImageUnavailableException::class);

        $this->probe([self::image(Response::HTTP_NOT_FOUND)])->measure(self::IMAGE_URL);
    }

    public function testMeasureRejectsBytesThatAreNoImage(): void
    {
        $this->expectException(GuardedImageUnavailableException::class);

        $this->probe([self::image(Response::HTTP_OK, 'image/jpeg', 'not an image')])->measure(self::IMAGE_URL);
    }

    public function testSendsTheOutboundUserAgentWithoutCompression(): void
    {
        $this->probe([self::image()])->exists(self::IMAGE_URL);

        self::assertContains('User-Agent: TestAgent/1.0', $this->requests[0]['headers']);
        self::assertContains('Accept-Encoding: identity', $this->requests[0]['headers']);
    }
}
```

Check `GuardedImageDownloaderTest::testSendsTheOutboundUserAgent` for how `$options['headers']` is shaped by `MockHttpClient` (normalized `Name: value` strings) and match it.

- [ ] **Step 3: Run to verify failure**

Run: `php bin/phpunit tests/Service/Image/GuardedImageProbe`
Expected: errors, class not found.

- [ ] **Step 4: Implement**

`GuardedImageProbeInterface.php`:

```php
<?php

declare(strict_types=1);

namespace App\Service\Image\GuardedImageProbe;

use App\Service\Image\Exception\GuardedImageUnavailableException;
use App\Service\Image\Model\ImageDimensionsModel;

interface GuardedImageProbeInterface
{
    public function exists(string $imageUrl): bool;

    /** @throws GuardedImageUnavailableException */
    public function measure(string $imageUrl): ImageDimensionsModel;
}
```

`GuardedImageProbe.php`:

```php
<?php

declare(strict_types=1);

namespace App\Service\Image\GuardedImageProbe;

use App\Service\Fetch\Exception\FetchException;
use App\Service\Fetch\Pass\LandedResponse;
use App\Service\Fetch\Pass\RedirectBudget;
use App\Service\Fetch\RedirectFollower;
use App\Service\Image\Exception\GuardedImageUnavailableException;
use App\Service\Image\Model\ImageDimensionsModel;
use App\Service\Image\Support\MediaType;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\StreamableInterface;

/** Asks whether an image exists, or what size it is, without downloading it; over the same guarded chain. */
final readonly class GuardedImageProbe implements GuardedImageProbeInterface
{
    private const int TIMEOUT_SECONDS = 4;
    private const int MAX_REDIRECTS = 3;
    private const int HEADER_BYTES = 65_536;

    private const array HEAD_REFUSALS = [
        Response::HTTP_FORBIDDEN,
        Response::HTTP_METHOD_NOT_ALLOWED,
        Response::HTTP_NOT_IMPLEMENTED,
    ];

    private const array MEASURABLE_STATUSES = [Response::HTTP_OK, Response::HTTP_PARTIAL_CONTENT];

    public function __construct(
        private RedirectFollower $redirects,
        private string $userAgent,
    ) {
    }

    public function exists(string $imageUrl): bool
    {
        try {
            $landed = $this->redirects->followHead($imageUrl, $this->options([]), $this->budget());
        } catch (FetchException | ExceptionInterface) {
            return false;
        }
        if (\in_array($landed->status, self::HEAD_REFUSALS, true)) {
            return $this->measures($imageUrl);
        }

        return $landed->status === Response::HTTP_OK && self::declaresAnImage($landed);
    }

    public function measure(string $imageUrl): ImageDimensionsModel
    {
        try {
            $landed = $this->redirects->follow($imageUrl, $this->options(self::rangeHeader()), $this->budget());
            $bytes = $this->headerBytes($landed);
        } catch (FetchException | ExceptionInterface $exception) {
            throw new GuardedImageUnavailableException($exception->getMessage(), 0, $exception);
        }

        return ImageDimensionsModel::fromBytes($bytes)
            ?? throw new GuardedImageUnavailableException('No image header in the first bytes of ' . $imageUrl);
    }

    private function measures(string $imageUrl): bool
    {
        try {
            $this->measure($imageUrl);

            return true;
        } catch (GuardedImageUnavailableException) {
            return false;
        }
    }

    /** @throws ExceptionInterface */
    private function headerBytes(LandedResponse $landed): string
    {
        if (!\in_array($landed->status, self::MEASURABLE_STATUSES, true)) {
            throw new GuardedImageUnavailableException('Image responded ' . $landed->status . '.');
        }
        if (!$landed->response instanceof StreamableInterface) {
            throw new GuardedImageUnavailableException('Response cannot be read partially.');
        }
        $stream = $landed->response->toStream(false);
        $bytes = (string) stream_get_contents($stream, self::HEADER_BYTES);
        fclose($stream);
        $landed->response->cancel();

        return $bytes;
    }

    private static function declaresAnImage(LandedResponse $landed): bool
    {
        return str_starts_with(MediaType::of($landed->header('content-type')), 'image/');
    }

    /** @return array<string, string> */
    private static function rangeHeader(): array
    {
        return ['Range' => 'bytes=0-' . (self::HEADER_BYTES - 1)];
    }

    /**
     * @param array<string, string> $extraHeaders
     *
     * @return array<string, mixed>
     */
    private function options(array $extraHeaders): array
    {
        return [
            'timeout' => self::TIMEOUT_SECONDS,
            'max_duration' => self::TIMEOUT_SECONDS,
            'headers' => ['Accept-Encoding' => 'identity', 'User-Agent' => $this->userAgent] + $extraHeaders,
        ];
    }

    private function budget(): RedirectBudget
    {
        return new RedirectBudget(self::MAX_REDIRECTS);
    }
}
```

Checked while planning: `RedirectChainException` extends `FetchException` (what `follow()` throws for a blocked address); `MediaType::of()` takes `?string`; `MockResponse`, `CurlResponse` and `TraceableResponse` all implement `StreamableInterface`; `GuardedImageRejectedException` extends `GuardedImageUnavailableException`, so one catch covers both.

- [ ] **Step 5: Run the tests**

Run: `php bin/phpunit tests/Service/Image/GuardedImageProbe`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add backend/src/Service/Image/GuardedImageProbe backend/tests/Service/Image/GuardedImageProbe backend/tests/Support/StubGuardedImageProbe.php
git commit -m "feat(#1855): GuardedImageProbe answers exists and size without a download"
```

---

### Task 4: URL and target helpers

**Files:**
- Create: `backend/src/Service/Image/Support/RenditionTargets.php`, `SizedImageUrl.php`, `WidthQuery.php`
- Test: `backend/tests/Service/Image/Support/RenditionTargetsTest.php`, `SizedImageUrlTest.php`, `WidthQueryTest.php`

**Interfaces:**
- Produces:
  - `RenditionTargets::WIDTHS` (`list<int>`: 264, 520, 680, 1100, 1360); `RenditionTargets::LARGEST` (1360); `RenditionTargets::bandOf(int $width): ?int` (the target whose band holds the width; null below 264 or at/above 2040); `RenditionTargets::uncovered(list<int> $knownWidths): list<int>`.
  - `SizedImageUrl::hasSizeSuffix(string $url): bool`; `SizedImageUrl::withoutSizeSuffix(string $url): string`; `SizedImageUrl::atSize(string $url, int $width, int $height): string` (replaces a suffix, or adds one before the extension; query and fragment kept).
  - `WidthQuery::withWidth(string $url, int $width): string` (replaces an existing `w=`, else appends `w=`).

- [ ] **Step 1: Write the failing tests**

```php
// RenditionTargetsTest
public function testEachTargetOwnsTheBandUpToTheNextOne(): void
{
    self::assertNull(RenditionTargets::bandOf(263));
    self::assertSame(264, RenditionTargets::bandOf(264));
    self::assertSame(264, RenditionTargets::bandOf(519));
    self::assertSame(520, RenditionTargets::bandOf(520));
    self::assertSame(680, RenditionTargets::bandOf(1099));
    self::assertSame(1100, RenditionTargets::bandOf(1359));
    self::assertSame(1360, RenditionTargets::bandOf(2039));
    self::assertNull(RenditionTargets::bandOf(2040));
}

public function testATargetWithAKnownWidthInItsBandIsCovered(): void
{
    self::assertSame([264, 520, 1100, 1360], RenditionTargets::uncovered([768]));
    self::assertSame([520, 680, 1100], RenditionTargets::uncovered([300, 1500, 4000]));
    self::assertSame(RenditionTargets::WIDTHS, RenditionTargets::uncovered([]));
}

// SizedImageUrlTest
public function testRecognisesASizeSuffixBeforeTheExtension(): void
{
    self::assertTrue(SizedImageUrl::hasSizeSuffix('https://h/u/a-202x202.jpg'));
    self::assertTrue(SizedImageUrl::hasSizeSuffix('https://h/u/a-768x432.webp?ssl=1'));
    self::assertFalse(SizedImageUrl::hasSizeSuffix('https://h/u/a.jpg'));
    self::assertFalse(SizedImageUrl::hasSizeSuffix('https://h/202x202/a.jpg'));
}

public function testStripsTheSuffixKeepingTheQuery(): void
{
    self::assertSame('https://h/u/a.jpg?x=1', SizedImageUrl::withoutSizeSuffix('https://h/u/a-202x202.jpg?x=1'));
}

public function testSetsASize(): void
{
    self::assertSame('https://h/u/a-768x403.jpg', SizedImageUrl::atSize('https://h/u/a-202x202.jpg', 768, 403));
    self::assertSame('https://h/u/a-768x403.jpg', SizedImageUrl::atSize('https://h/u/a.jpg', 768, 403));
    self::assertSame('https://h/u/a-300x158.png?v=2', SizedImageUrl::atSize('https://h/u/a.png?v=2', 300, 158));
}

// WidthQueryTest
public function testAppendsAWidth(): void
{
    self::assertSame('https://h/a.jpg?w=264', WidthQuery::withWidth('https://h/a.jpg', 264));
    self::assertSame('https://h/a.jpg?quality=90&w=264', WidthQuery::withWidth('https://h/a.jpg?quality=90', 264));
}

public function testReplacesAnExistingWidthKeepingTheRestVerbatim(): void
{
    self::assertSame(
        'https://h/a.jpg?quality=82&strip=all&w=520',
        WidthQuery::withWidth('https://h/a.jpg?quality=82&strip=all&w=1600', 520),
    );
    self::assertSame(
        'https://h/a.jpg?w=264&crop=0,0,100,100',
        WidthQuery::withWidth('https://h/a.jpg?w=1600&crop=0,0,100,100', 264),
    );
}

public function testLeavesAWidthLookalikeAlone(): void
{
    self::assertSame('https://h/a.jpg?vw=3&w=264', WidthQuery::withWidth('https://h/a.jpg?vw=3', 264));
}
```

(each in its own `final class …Test extends TestCase` under `App\Tests\Service\Image\Support`).

- [ ] **Step 2: Run to verify failure**

Run: `php bin/phpunit tests/Service/Image/Support`
Expected: errors on the missing classes.

- [ ] **Step 3: Implement**

```php
<?php

declare(strict_types=1);

namespace App\Service\Image\Support;

/** The image widths the list views ask for: thumb 132 px at 2×, split 259 px, full column 680 px, each at 1× to 3×. */
final class RenditionTargets
{
    /** @var list<int> */
    public const array WIDTHS = [264, 520, 680, 1100, 1360];

    public const int LARGEST = 1360;

    private const float TOP_BAND_STRETCH = 1.5;

    public static function bandOf(int $width): ?int
    {
        $band = null;
        foreach (self::WIDTHS as $target) {
            if ($width >= $target) {
                $band = $target;
            }
        }

        return $width < self::LARGEST * self::TOP_BAND_STRETCH ? $band : null;
    }

    /**
     * @param list<int> $knownWidths
     *
     * @return list<int>
     */
    public static function uncovered(array $knownWidths): array
    {
        $covered = array_map(self::bandOf(...), $knownWidths);

        return array_values(array_filter(
            self::WIDTHS,
            static fn (int $target): bool => !\in_array($target, $covered, true),
        ));
    }

    private function __construct()
    {
    }
}
```

```php
<?php

declare(strict_types=1);

namespace App\Service\Image\Support;

/** The `-640x360` filename suffix CMSes such as WordPress name their stored sizes with. */
final class SizedImageUrl
{
    private const string SUFFIX = '/-\d+x\d+(?=\.[a-z0-9]{2,5}(?:[?#]|$))/i';
    private const string EXTENSION = '/(?=\.[a-z0-9]{2,5}(?:[?#]|$))/i';

    public static function hasSizeSuffix(string $url): bool
    {
        return preg_match(self::SUFFIX, $url) === 1;
    }

    public static function withoutSizeSuffix(string $url): string
    {
        return (string) preg_replace(self::SUFFIX, '', $url, 1);
    }

    public static function atSize(string $url, int $width, int $height): string
    {
        return (string) preg_replace(self::EXTENSION, '-' . $width . 'x' . $height, self::withoutSizeSuffix($url), 1);
    }

    private function __construct()
    {
    }
}
```

`EXTENSION` must match the *last* extension-like dot in the path only: write a test case with a dotted directory (`https://h/v1.2/a.jpg` → `https://h/v1.2/a-300x200.jpg`) and, if the lookahead matches `.2/`, anchor it to the final path segment (`(?=\.[a-z0-9]{2,5}(?:[?#]|$))` already requires the extension to end the path or precede `?`/`#`, so `.2/` does not match — keep the test as the proof).

```php
<?php

declare(strict_types=1);

namespace App\Service\Image\Support;

/** On-demand resizers (WordPress VIP, Jetpack Photon, Sanity, imgix) read the target width from `w=`. */
final class WidthQuery
{
    private const string WIDTH_PARAMETER = '/([?&])w=\d*/';

    public static function withWidth(string $url, int $width): string
    {
        if (preg_match(self::WIDTH_PARAMETER, $url) === 1) {
            return (string) preg_replace(self::WIDTH_PARAMETER, '${1}w=' . $width, $url, 1);
        }

        return $url . (str_contains($url, '?') ? '&' : '?') . 'w=' . $width;
    }

    private function __construct()
    {
    }
}
```

A URL with a `#fragment` is not expected from a feed image; don't handle it.

- [ ] **Step 4: Run the tests**

Run: `php bin/phpunit tests/Service/Image/Support`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add backend/src/Service/Image/Support backend/tests/Service/Image/Support
git commit -m "feat(#1855): rendition targets and the URL helpers that name sized variants"
```

---

### Task 5: `LeadImageModel` and `ResizerRungs`

**Files:**
- Create: `backend/src/Service/Image/Model/LeadImageModel.php`, `backend/src/Service/Image/ResizerRungs.php`
- Test: `backend/tests/Service/Image/ResizerRungsTest.php`

**Interfaces:**
- Consumes: `GuardedImageProbeInterface::measure()`; `RenditionTargets`; `WidthQuery`.
- Produces:
  - `LeadImageModel(string $url, ImageDimensionsModel $dimensions, list<ImageRendition> $known)` with `knownWidths(): list<int>` (the lead's width plus every known rendition's).
  - `ResizerRungs::resizes(LeadImageModel $lead): bool`; `ResizerRungs::rungsFor(LeadImageModel $lead): list<ImageRendition>` (no requests).

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace App\Tests\Service\Image;

use App\Entity\ImageRendition;
use App\Service\Image\Model\ImageDimensionsModel;
use App\Service\Image\Model\LeadImageModel;
use App\Service\Image\ResizerRungs;
use App\Tests\Support\StubGuardedImageProbe;
use PHPUnit\Framework\TestCase;

final class ResizerRungsTest extends TestCase
{
    private const string LEAD = 'https://9to5mac.com/wp-content/uploads/a.jpg?quality=82&w=1600';

    /** @param list<ImageRendition> $known */
    private static function lead(int $width, array $known = []): LeadImageModel
    {
        return new LeadImageModel(self::LEAD, new ImageDimensionsModel($width, (int) round($width * 0.5)), $known);
    }

    public function testAHostReturningTheAskedWidthResizes(): void
    {
        $probe = new StubGuardedImageProbe();
        $probe->willMeasure('https://9to5mac.com/wp-content/uploads/a.jpg?quality=82&w=264', 264, 132);

        self::assertTrue(new ResizerRungs($probe)->resizes(self::lead(1600)));
    }

    public function testOnePixelOfRoundingStillCounts(): void
    {
        $probe = new StubGuardedImageProbe();
        $probe->willMeasure('https://9to5mac.com/wp-content/uploads/a.jpg?quality=82&w=264', 263, 132);

        self::assertTrue(new ResizerRungs($probe)->resizes(self::lead(1600)));
    }

    public function testAHostReturningTheFullImageDoesNotResize(): void
    {
        $probe = new StubGuardedImageProbe();
        $probe->willMeasure('https://9to5mac.com/wp-content/uploads/a.jpg?quality=82&w=264', 1600, 800);

        self::assertFalse(new ResizerRungs($probe)->resizes(self::lead(1600)));
    }

    public function testAFailedCheckDoesNotResize(): void
    {
        self::assertFalse(new ResizerRungs(new StubGuardedImageProbe())->resizes(self::lead(1600)));
    }

    public function testALeadNarrowerThanTheSmallestTargetIsNotChecked(): void
    {
        $probe = new StubGuardedImageProbe();

        self::assertFalse(new ResizerRungs($probe)->resizes(self::lead(263)));
        self::assertSame([], $probe->requestedUrls());
    }

    public function testRungsCoverEachUncoveredTargetUpToTheLeadWidth(): void
    {
        $rungs = new ResizerRungs(new StubGuardedImageProbe())->rungsFor(self::lead(800));

        self::assertEquals([
            new ImageRendition('https://9to5mac.com/wp-content/uploads/a.jpg?quality=82&w=264', 264),
            new ImageRendition('https://9to5mac.com/wp-content/uploads/a.jpg?quality=82&w=520', 520),
        ], $rungs);
    }

    public function testAKnownRenditionCoversItsTarget(): void
    {
        $known = [new ImageRendition('https://9to5mac.com/a-300.jpg', 300)];

        $rungs = new ResizerRungs(new StubGuardedImageProbe())->rungsFor(self::lead(2000, $known));

        self::assertSame([520, 680, 1100], array_map(static fn (ImageRendition $rung): int => $rung->width, $rungs));
    }
}
```

Lead 800: its own width sits in the 680 band, so 680 is covered; 1100 and 1360 exceed the lead. Lead 2000 covers 1360 itself (band `[1360,2040)`) and the known 300 covers 264.

- [ ] **Step 2: Run to verify failure**

Run: `php bin/phpunit tests/Service/Image/ResizerRungsTest.php`
Expected: errors, classes missing.

- [ ] **Step 3: Implement**

```php
<?php

declare(strict_types=1);

namespace App\Service\Image\Model;

use App\Entity\ImageRendition;

/** A verified lead image and the renditions already known for it. */
final readonly class LeadImageModel
{
    /** @param list<ImageRendition> $known */
    public function __construct(
        public string $url,
        public ImageDimensionsModel $dimensions,
        public array $known,
    ) {
    }

    /** @return list<int> */
    public function knownWidths(): array
    {
        return [
            $this->dimensions->width,
            ...array_map(static fn (ImageRendition $rendition): int => $rendition->width, $this->known),
        ];
    }
}
```

```php
<?php

declare(strict_types=1);

namespace App\Service\Image;

use App\Entity\ImageRendition;
use App\Service\Image\Exception\GuardedImageUnavailableException;
use App\Service\Image\GuardedImageProbe\GuardedImageProbeInterface;
use App\Service\Image\Model\LeadImageModel;
use App\Service\Image\Support\RenditionTargets;
use App\Service\Image\Support\WidthQuery;

/** A host that answers `w=` with that width renders every target on demand; one check proves it. */
final readonly class ResizerRungs
{
    private const int CHECK_WIDTH = RenditionTargets::WIDTHS[0];
    private const int ROUNDING_TOLERANCE = 1;

    public function __construct(private GuardedImageProbeInterface $probe)
    {
    }

    public function resizes(LeadImageModel $lead): bool
    {
        if ($lead->dimensions->width < self::CHECK_WIDTH) {
            return false;
        }
        try {
            $measured = $this->probe->measure(WidthQuery::withWidth($lead->url, self::CHECK_WIDTH));
        } catch (GuardedImageUnavailableException) {
            return false;
        }

        return abs($measured->width - self::CHECK_WIDTH) <= self::ROUNDING_TOLERANCE;
    }

    /** @return list<ImageRendition> */
    public function rungsFor(LeadImageModel $lead): array
    {
        $rungs = [];
        foreach (RenditionTargets::uncovered($lead->knownWidths()) as $target) {
            if ($target <= $lead->dimensions->width) {
                $rungs[] = new ImageRendition(WidthQuery::withWidth($lead->url, $target), $target);
            }
        }

        return $rungs;
    }
}
```

- [ ] **Step 4: Run the test**

Run: `php bin/phpunit tests/Service/Image/ResizerRungsTest.php`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add backend/src/Service/Image/Model/LeadImageModel.php backend/src/Service/Image/ResizerRungs.php backend/tests/Service/Image/ResizerRungsTest.php
git commit -m "feat(#1855): rungs from on-demand resizers, proven by one w=264 check"
```

---

### Task 6: `GuessedSizeRungs`

**Files:**
- Create: `backend/src/Service/Image/Pass/SizeWalk.php`, `backend/src/Service/Image/GuessedSizeRungs.php`
- Test: `backend/tests/Service/Image/GuessedSizeRungsTest.php`

**Interfaces:**
- Consumes: `GuardedImageProbeInterface`; `LeadImageModel`; `RenditionTargets`; `SizedImageUrl`.
- Produces: `GuessedSizeRungs::rungsFor(LeadImageModel $lead): list<ImageRendition>` — never throws.

Rules (spec 7.2): a lead is guessable when `SizedImageUrl::hasSizeSuffix($url)` or its URL contains `/wp-content/uploads/`. A suffixed lead measures `withoutSizeSuffix($url)` first; when it exists, its dimensions are the basis and the bound, and it is itself a rung when its width ≤ `RenditionTargets::LARGEST` and differs from the lead. Otherwise the lead is the basis. Walk `GUESSED_WIDTHS` ascending; skip a width ≥ the basis width, or one whose band is covered by a known width or an earlier find; HEAD `atSize($basisUrl, $width, round($basisHeight * $width / $basisWidth))`.

- [ ] **Step 1: Write the failing tests**

```php
<?php

declare(strict_types=1);

namespace App\Tests\Service\Image;

use App\Entity\ImageRendition;
use App\Service\Image\GuessedSizeRungs;
use App\Service\Image\Model\ImageDimensionsModel;
use App\Service\Image\Model\LeadImageModel;
use App\Tests\Support\StubGuardedImageProbe;
use PHPUnit\Framework\TestCase;

final class GuessedSizeRungsTest extends TestCase
{
    private static function lead(string $url, int $width, int $height): LeadImageModel
    {
        return new LeadImageModel($url, new ImageDimensionsModel($width, $height), []);
    }

    /**
     * @param list<ImageRendition> $rungs
     *
     * @return list<int>
     */
    private static function widths(array $rungs): array
    {
        return array_map(static fn (ImageRendition $rung): int => $rung->width, $rungs);
    }

    public function testAnUnsuffixedWordPressOriginalWalksItsOwnAspect(): void
    {
        $base = 'https://www.smarticular.net/wp-content/uploads/2026/10/broeselknoedel-rezept';
        $probe = new StubGuardedImageProbe();
        $probe->willExist($base . '-768x403.jpg');

        $rungs = new GuessedSizeRungs($probe)->rungsFor(self::lead($base . '.jpg', 1200, 630));

        self::assertEquals([new ImageRendition($base . '-768x403.jpg', 768)], $rungs);
        self::assertSame([
            $base . '-300x158.jpg',
            $base . '-384x202.jpg',
            $base . '-512x269.jpg',
            $base . '-640x336.jpg',
            $base . '-768x403.jpg',
        ], $probe->requestedUrls());
    }

    public function testASuffixedCropUsesItsMeasuredOriginal(): void
    {
        $base = 'https://www.smarticular.net/wp-content/uploads/2026/10/broeselknoedel-rezept';
        $probe = new StubGuardedImageProbe();
        $probe->willMeasure($base . '.jpg', 1200, 630);
        $probe->willExist($base . '-768x403.jpg');

        $rungs = new GuessedSizeRungs($probe)->rungsFor(self::lead($base . '-202x202.jpg', 202, 202));

        self::assertSame([768, 1200], self::widths(ImageRendition::ladder($rungs)));
    }

    public function testAnOriginalWiderThanTheLargestTargetIsNoRung(): void
    {
        $base = 'https://cdn.arstechnica.net/wp-content/uploads/2026/10/anthropic-crystal-ball';
        $probe = new StubGuardedImageProbe();
        $probe->willMeasure($base . '.jpg', 2560, 1440);
        $probe->willExist($base . '-640x360.jpg');
        $probe->willExist($base . '-768x432.jpg');
        $probe->willExist($base . '-1280x720.jpg');

        $rungs = new GuessedSizeRungs($probe)->rungsFor(self::lead($base . '-1152x648.jpg', 1152, 648));

        self::assertSame([640, 1280], self::widths(ImageRendition::ladder($rungs)));
    }

    public function testWithoutAnOriginalTheSuffixedLeadIsTheBasis(): void
    {
        $base = 'https://bilder.deutschlandfunk.de/15/1b/gadi-eisenkot-104';
        $probe = new StubGuardedImageProbe();
        $probe->willExist($base . '-512x512.jpg');
        $probe->willExist($base . '-768x768.jpg');
        $probe->willExist($base . '-1280x1280.jpg');

        $rungs = new GuessedSizeRungs($probe)->rungsFor(self::lead($base . '-1920x1920.jpg', 1920, 1920));

        self::assertSame([512, 768, 1280], self::widths(ImageRendition::ladder($rungs)));
        self::assertNotContains($base . '-1024x1024.jpg', $probe->requestedUrls());
    }

    public function testACoveredBandIsNotProbed(): void
    {
        $base = 'https://h.example/wp-content/uploads/a';
        $probe = new StubGuardedImageProbe();
        $lead = new LeadImageModel(
            $base . '.jpg',
            new ImageDimensionsModel(1000, 500),
            [new ImageRendition($base . '-300x150.jpg', 300)],
        );

        new GuessedSizeRungs($probe)->rungsFor($lead);

        self::assertSame([$base . '-640x320.jpg'], $probe->requestedUrls());
    }

    public function testAnUnsuffixedLeadOutsideWordPressIsLeftAlone(): void
    {
        $probe = new StubGuardedImageProbe();

        self::assertSame([], new GuessedSizeRungs($probe)->rungsFor(self::lead('https://i.guim.co.uk/a.jpg', 1200, 800)));
        self::assertSame([], $probe->requestedUrls());
    }
}
```

Expected walks, worked out by hand (heights use PHP `round()`, half away from zero: 630·300/1200 = 157.5 → 158):
- smarticular unsuffixed: lead 1200 covers band 1100; 300/384/512/640 miss, 768 hits (band 680), 1024 skipped (band 680 covered), 1280 ≥ 1200 skipped — five HEADs.
- smarticular crop: lead 202 is below every band; the measured original 1200 is kept (band 1100) before the walk, which then runs as above.
- Ars: the lead 1152 covers band 680, so 768 and 1024 are never probed; original 2560 > 1360 is no rung; 640 finds band 520, 1280 band 1100; band 264 stays empty (300/384/512 miss).
- DLF: the original's `measure` fails (not stubbed); basis 1920×1920, which covers 1360; 512 (264), 768 (680), 1280 (1100) hit; 1024 is skipped.
- Covered band: lead 1000 covers 680, the known 300 covers 264; only 640 (band 520) is probed; 1024 ≥ 1000 is skipped.

- [ ] **Step 2: Run to verify failure**

Run: `php bin/phpunit tests/Service/Image/GuessedSizeRungsTest.php`
Expected: errors, classes missing.

- [ ] **Step 3: Implement**

`backend/src/Service/Image/Pass/SizeWalk.php`:

```php
<?php

declare(strict_types=1);

namespace App\Service\Image\Pass;

use App\Entity\ImageRendition;
use App\Service\Image\Model\ImageDimensionsModel;
use App\Service\Image\Support\RenditionTargets;
use App\Service\Image\Support\SizedImageUrl;

/** One lead's walk through the guessed widths: the picture sizes are named after, and the bands found so far. */
final class SizeWalk
{
    /** @var list<ImageRendition> */
    private array $found = [];

    /** @param list<int> $knownWidths */
    public function __construct(
        private readonly string $basisUrl,
        private readonly ImageDimensionsModel $basis,
        private array $knownWidths,
    ) {
    }

    public function wants(int $width): bool
    {
        return $width < $this->basis->width
            && \in_array(RenditionTargets::bandOf($width), RenditionTargets::uncovered($this->knownWidths), true);
    }

    public function candidateAt(int $width): string
    {
        return SizedImageUrl::atSize($this->basisUrl, $width, (int) round($this->basis->height * $width / $this->basis->width));
    }

    public function keep(ImageRendition $rung): void
    {
        $this->found[] = $rung;
        $this->knownWidths[] = $rung->width;
    }

    /** @return list<ImageRendition> */
    public function found(): array
    {
        return $this->found;
    }
}
```

`backend/src/Service/Image/GuessedSizeRungs.php`:

```php
<?php

declare(strict_types=1);

namespace App\Service\Image;

use App\Entity\ImageRendition;
use App\Service\Image\Exception\GuardedImageUnavailableException;
use App\Service\Image\GuardedImageProbe\GuardedImageProbeInterface;
use App\Service\Image\Model\LeadImageModel;
use App\Service\Image\Pass\SizeWalk;
use App\Service\Image\Support\RenditionTargets;
use App\Service\Image\Support\SizedImageUrl;

/** Size variants a CMS stores beside an upload but no feed declares, found by naming them and asking. */
final readonly class GuessedSizeRungs
{
    /** @var list<int> */
    private const array GUESSED_WIDTHS = [300, 384, 512, 640, 768, 1024, 1280];

    private const string WORDPRESS_UPLOADS = '/wp-content/uploads/';

    public function __construct(private GuardedImageProbeInterface $probe)
    {
    }

    /** @return list<ImageRendition> */
    public function rungsFor(LeadImageModel $lead): array
    {
        if (SizedImageUrl::hasSizeSuffix($lead->url)) {
            return $this->walk($this->fromOriginalOrLead($lead));
        }
        if (str_contains($lead->url, self::WORDPRESS_UPLOADS)) {
            return $this->walk(new SizeWalk($lead->url, $lead->dimensions, $lead->knownWidths()));
        }

        return [];
    }

    private function fromOriginalOrLead(LeadImageModel $lead): SizeWalk
    {
        $originalUrl = SizedImageUrl::withoutSizeSuffix($lead->url);
        try {
            $original = $this->probe->measure($originalUrl);
        } catch (GuardedImageUnavailableException) {
            return new SizeWalk($lead->url, $lead->dimensions, $lead->knownWidths());
        }
        $walk = new SizeWalk($originalUrl, $original, $lead->knownWidths());
        if ($original->width <= RenditionTargets::LARGEST && $original->width !== $lead->dimensions->width) {
            $walk->keep(new ImageRendition($originalUrl, $original->width));
        }

        return $walk;
    }

    /** @return list<ImageRendition> */
    private function walk(SizeWalk $walk): array
    {
        foreach (self::GUESSED_WIDTHS as $width) {
            if ($walk->wants($width) && $this->probe->exists($walk->candidateAt($width))) {
                $walk->keep(new ImageRendition($walk->candidateAt($width), $width));
            }
        }

        return $walk->found();
    }
}
```

- [ ] **Step 4: Run the test**

Run: `php bin/phpunit tests/Service/Image/GuessedSizeRungsTest.php`
Expected: PASS. A failing expectation is a bug in the implementation, not in the worked-out walks above; recheck the arithmetic against the spec's rules before touching a test value.

- [ ] **Step 5: Commit**

```bash
git add backend/src/Service/Image/Pass/SizeWalk.php backend/src/Service/Image/GuessedSizeRungs.php backend/tests/Service/Image/GuessedSizeRungsTest.php
git commit -m "feat(#1855): guess CMS size variants band by band"
```

---

### Task 7: `RenditionProbe` in `ImageVerifier`

**Files:**
- Create: `backend/src/Service/Image/RenditionProbe.php`, `backend/tests/Support/RenditionProbes.php`
- Modify: `backend/src/Service/Image/ImageVerifier.php`
- Modify (constructor call only): `backend/tests/Service/Image/ImageVerifierTest.php:46`, `backend/tests/Service/Image/ImageVerificationSweepTest.php:26`, `backend/tests/Service/Maintenance/MaintenanceTickTest.php:324`, `backend/tests/Service/Worker/VerifyPendingImagesHandlerTest.php:62`
- Test: `backend/tests/Service/Image/RenditionProbeTest.php`, `ImageVerifierTest.php`

**Interfaces:**
- Consumes: `ResizerRungs`, `GuessedSizeRungs`, `LeadImageModel`.
- Produces: `RenditionProbe::rungsFor(LeadImageModel $lead): list<ImageRendition>`; `ImageVerifier::__construct(GuardedImageDownloaderInterface $downloader, NaiveUtcClock $clock, RenditionProbe $renditionProbe)`; test factory `RenditionProbes::over(StubGuardedImageProbe $probe): RenditionProbe` and `RenditionProbes::none(): RenditionProbe`.

- [ ] **Step 1: Write the failing tests**

`backend/tests/Support/RenditionProbes.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Service\Image\GuessedSizeRungs;
use App\Service\Image\RenditionProbe;
use App\Service\Image\ResizerRungs;

final class RenditionProbes
{
    public static function over(StubGuardedImageProbe $probe): RenditionProbe
    {
        return new RenditionProbe(new ResizerRungs($probe), new GuessedSizeRungs($probe));
    }

    public static function none(): RenditionProbe
    {
        return self::over(new StubGuardedImageProbe());
    }

    private function __construct()
    {
    }
}
```

`RenditionProbeTest`:

```php
public function testAResizerSkipsGuessing(): void
{
    $lead = 'https://h.example/wp-content/uploads/a-1152x648.jpg';
    $probe = new StubGuardedImageProbe();
    $probe->willMeasure($lead . '?w=264', 264, 149);

    $rungs = RenditionProbes::over($probe)->rungsFor(
        new LeadImageModel($lead, new ImageDimensionsModel(1152, 648), []),
    );

    self::assertSame([264, 520, 1100], array_map(static fn (ImageRendition $rung): int => $rung->width, $rungs));
    self::assertSame([$lead . '?w=264'], $probe->requestedUrls());
}

public function testWithoutAResizerItGuesses(): void
{
    $base = 'https://h.example/wp-content/uploads/a';
    $probe = new StubGuardedImageProbe();
    $probe->willExist($base . '-768x432.jpg');

    $rungs = RenditionProbes::over($probe)->rungsFor(
        new LeadImageModel($base . '.jpg', new ImageDimensionsModel(1600, 900), []),
    );

    self::assertEquals([new ImageRendition($base . '-768x432.jpg', 768)], $rungs);
}
```

(Lead 1152 sits in band 680, so the resizer writes 264, 520 and 1100 — 1100 ≤ 1152 — but not 680 or 1360.)

`ImageVerifierTest` — change `verifier()` to take an optional probe and add:

```php
    private function verifier(
        StubGuardedImageDownloader $downloader,
        ?StubGuardedImageProbe $probe = null,
    ): ImageVerifier {
        return new ImageVerifier(
            $downloader,
            new NaiveUtcClock(new MockClock('2026-09-21 12:00:00')),
            RenditionProbes::over($probe ?? new StubGuardedImageProbe()),
        );
    }

    public function testAMeasuredLeadGainsItsProbedRungsBesideTheDeclaredOnes(): void
    {
        $lead = 'https://i/wp-content/uploads/a.png';
        $downloader = new StubGuardedImageDownloader();
        $downloader->willReturnBytes($lead, PngImageFactory::bytes(1600, 900));
        $probe = new StubGuardedImageProbe();
        $probe->willExist('https://i/wp-content/uploads/a-768x432.png');
        $entry = $this->pendingImage($lead);
        $entry->getImage()->storeRenditions([new ImageRendition('https://i/wp-content/uploads/a-300x169.png', 300)]);

        $this->verifier($downloader, $probe)->verify($entry);

        self::assertEquals([
            new ImageRendition('https://i/wp-content/uploads/a-300x169.png', 300),
            new ImageRendition('https://i/wp-content/uploads/a-768x432.png', 768),
        ], $entry->getImage()->getRenditions());
    }

    public function testADroppedImageIsNeverProbed(): void
    {
        $downloader = new StubGuardedImageDownloader();
        $downloader->willReturnBytes('https://i/pixel.png', PngImageFactory::bytes(1, 1));
        $probe = new StubGuardedImageProbe();

        $this->verifier($downloader, $probe)->verify($this->pendingImage('https://i/pixel.png'));

        self::assertSame([], $probe->requestedUrls());
    }

    public function testAFailedProbeLeavesTheOutcomeMeasured(): void
    {
        $downloader = new StubGuardedImageDownloader();
        $downloader->willReturnBytes('https://i/wp-content/uploads/a.png', PngImageFactory::bytes(1600, 900));

        $outcome = $this->verifier($downloader)->verify($this->pendingImage('https://i/wp-content/uploads/a.png'));

        self::assertSame(ImageVerifyOutcome::Measured, $outcome);
    }
```

Also add a test that a refused (`GuardedImageRejectedException`) and a retried download make no probe request. `storePending()` clears renditions, so the test above stores the declared rendition *after* `pendingImage()`.

- [ ] **Step 2: Run to verify failure**

Run: `php bin/phpunit tests/Service/Image`
Expected: errors (`RenditionProbe` missing, constructor arity).

- [ ] **Step 3: Implement**

```php
<?php

declare(strict_types=1);

namespace App\Service\Image;

use App\Entity\ImageRendition;
use App\Service\Image\Model\LeadImageModel;

/** The rungs a verified lead is missing: computed for an on-demand resizer, guessed otherwise. */
final readonly class RenditionProbe
{
    public function __construct(
        private ResizerRungs $resizerRungs,
        private GuessedSizeRungs $guessedSizeRungs,
    ) {
    }

    /** @return list<ImageRendition> */
    public function rungsFor(LeadImageModel $lead): array
    {
        if ($this->resizerRungs->resizes($lead)) {
            return $this->resizerRungs->rungsFor($lead);
        }

        return $this->guessedSizeRungs->rungsFor($lead);
    }
}
```

`ImageVerifier` — add the constructor parameter `private RenditionProbe $renditionProbe`, and in `judge()` after `recordMeasurement(...)`:

```php
        $entry->getImage()->recordMeasurement($dimensions->width, $dimensions->height, $this->clock->now());
        $this->addProbedRungs($entry->getImage(), $dimensions);

        return ImageVerifyOutcome::Measured;
```

```php
    private function addProbedRungs(EntryImage $image, ImageDimensionsModel $dimensions): void
    {
        $known = $image->getRenditions();
        $lead = new LeadImageModel((string) $image->getUrl(), $dimensions, $known);
        $probed = $this->renditionProbe->rungsFor($lead);
        if ($probed !== []) {
            $image->storeRenditions(ImageRendition::ladder([...$known, ...$probed]));
        }
    }
```

`judge()` only runs after `verify()` returned early for a null URL, so `getUrl()` is non-null here; if PHPStan cannot see that, pass the `$url` from `verify()` down through `judge()` instead of casting (and drop the cast). Add `use App\Entity\EntryImage; use App\Entity\ImageRendition; use App\Service\Image\Model\LeadImageModel;`.

Update the other three constructor calls to pass `RenditionProbes::none()` as the third argument.

- [ ] **Step 4: Run the image, maintenance and worker suites**

Run: `php bin/phpunit tests/Service/Image tests/Service/Maintenance tests/Service/Worker`
Expected: PASS.

- [ ] **Step 5: Check the container wiring**

Run: `php bin/console debug:container 'App\Service\Image\GuardedImageProbe\GuardedImageProbeInterface'` and `php bin/console lint:container`
Expected: the interface aliases `GuardedImageProbe`, and `$userAgent` binds from `config/services.yaml`'s name-matched bind. If the alias is missing, add it to `config/services.yaml` beside the downloader's.

- [ ] **Step 6: Commit**

```bash
git add backend/src/Service/Image backend/tests/Service/Image backend/tests/Support/RenditionProbes.php backend/tests/Service/Maintenance/MaintenanceTickTest.php backend/tests/Service/Worker/VerifyPendingImagesHandlerTest.php
git commit -m "feat(#1855): a verified lead gains the rungs its list views need"
```

---

### Task 8: Gates and a live check

- [ ] **Step 1: Static gates** (from `backend/`, warm cache first)

```bash
bin/console cache:warmup
composer check
composer md
```

Expected: clean. Fix the design PHPMD/phptramp point at; never raise a threshold.

- [ ] **Step 2: Both test legs in parallel**

```bash
composer test:parallel
docker compose exec php composer test
```

Expected: PASS on SQLite and MySQL.

- [ ] **Step 3: Mutation gate** — commit first (`infection:diff` ignores untracked files), then:

```bash
composer infection:diff
```

Expected: at or above `minMsi`. Kill escaped mutants with tests, never by lowering the threshold.

- [ ] **Step 4: PhpStorm inspections** on every changed PHP file (`mcp__phpstorm__lint_files`); block on ERROR/WARNING.

- [ ] **Step 5: Live check on the Docker stack** — restart the worker so it runs the new code (`docker compose restart worker php`), clear the smarticular entries' verification so the sweep re-runs them, by refreshing the feed through the app (not raw SQL behind the running app): use the feed's "refresh" in the UI or `docker compose exec php bin/console app:feeds:refresh`. New smarticular entries must store the `media:content` original and, after the next sweep minute, a `-768x403` rung:

```bash
docker compose exec -T mysql sh -c 'mysql -u"$MYSQL_USER" -p"$MYSQL_PASSWORD" "$MYSQL_DATABASE" -e "select image_url, image_width, image_renditions from entry where feed_id=292 order by id desc limit 3"'
```

Then scan today's dev log: `ls -t backend/var/log/dev-*.log | head -1 | xargs tail -n 200 | jq -c 'select(.level_name != "DEBUG" and .level_name != "INFO")'`.

- [ ] **Step 6: Commit any gate fixes**

```bash
git commit -am "chore(#1855): gate fixes"
```

---

### Task 9: Backfill rehearsal (throwaway, not committed)

Follow the memory recipe `strato-one-off-script-via-kernel-subclass`.

- [ ] **Step 1: Write the script** to `backend/var/backfill-renditions.php` (`var/` is gitignored; check with `git check-ignore backend/var/backfill-renditions.php`). It:
  - requires `<release>/vendor/autoload.php`, `bootEnv`s `<release>/.env`, boots an `App\Kernel` subclass with its own cache dir and a `TYPE_BEFORE_OPTIMIZATION` pass making `App\Service\Image\RenditionProbe` and `doctrine.orm.entity_manager` public;
  - pages entries with a non-null `image_url`, `image_width` and `image_height`, ordered `published_at DESC, id DESC`, 25 per batch, starting after `--after=<published_at>,<id>` when given;
  - for each: builds `LeadImageModel(url, new ImageDimensionsModel(width, height), getRenditions())`, calls `rungsFor`, and, with `--apply`, stores `ImageRendition::ladder([...known, ...probed])`; flushes and `clear()`s per batch;
  - prints one line per batch (`last=<published_at>,<id> probed=<n> rungs=<n>`) and, at the end, rungs found and request count per host (wrap the probe in a counting decorator inside the script);
  - dry-run by default.

- [ ] **Step 2: Rehearse on the dev DB** — dry run first, then `--apply`, inside the php container:

```bash
docker compose exec php php /app/var/backfill-renditions.php /app
docker compose exec php php /app/var/backfill-renditions.php /app --apply
```

Report rungs and requests per host to Lars. Spot-check the magazine at `http://localhost:4200` in a phone and a desktop viewport: thumbs load the small rungs (Network panel), smarticular and Ars entries reach `hero`/`wide`.

- [ ] **Step 3: Strato** — only after Lars says the deploy is done. Hand Lars the upload, dry-run, `--apply` and cleanup commands one per block (the classifier blocks my remote writes); run with `/opt/RZphp84/bin/php-cli ~/backfill-renditions.php "$(readlink -f ~/simplefeedreader/current)"`. Delete the script locally and on Strato afterwards.

---

### Task 10: PR

- [ ] **Step 1:** `git push -u origin feature/1855-image-variant-probing`; open a PR into `develop` titled `feat(#1855): probe image variants for the magazine planner`, body: what changed, the survey numbers from the spec, the four planning refinements, test evidence (both legs, infection, live check), and `Closes #1855`. No Claude attribution.
- [ ] **Step 2:** bind it (`ccd_pr`), read CI, offer Auto-fix. Never merge unasked.
