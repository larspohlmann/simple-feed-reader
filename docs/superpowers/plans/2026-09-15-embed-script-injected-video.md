# Embed Script-Injected Video (Vimeo via `videoData`) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make the reader embed a video that the source page injects only with client-side JavaScript, where the provider URL lives in an inline `<script>` variable and no embeddable element exists in the fetched HTML.

**Architecture:** Two additions to the reader media pipeline, both through the existing Symfony-tag extension points. (1) A `VimeoEmbedProvider` teaches `EmbedProviders` to recognise Vimeo — a gap today — which also repairs real Vimeo iframes everywhere. (2) A low-priority `ScriptEmbedSource` scans inline-script text for provider URLs and emits `Embed` candidates; the numeric-id strictness of the providers and a `PageFurniture` skip filter keep noise out, so no empty-container detection is needed.

**Tech Stack:** PHP 8.4, Symfony 7.4, PHPUnit 12, `\Dom\HTMLDocument`.

**Spec:** GitHub issue #1048 (<https://github.com/larspohlmann/simple-feed-reader/issues/1048>).

## Global Constraints

- `declare(strict_types=1);` in every PHP file; PSR-12; PHPStan level max over `src` and `tests`.
- House style: `final readonly class`, constructor promotion, guard clauses, names reveal intent, no boolean flag parameters. Comment only a genuinely non-obvious invariant; one line, three at most.
- Every `src` file touched must be PHPMD-clean (codesize) and phptramp-clean before commit.
- Media sources read the RAW page, not the normalized document (`MediaCandidateSourceInterface` contract).
- SSRF: emit only a normalized player URL; add no new outbound fetch.
- Native iOS: standard JSON out; no browser-only coupling.
- Commit format: `type(#1048): subject`. No attribution lines.
- Branch: `feature/1048-embed-script-injected-video` (already created off `develop`).

---

## Task 1: `VimeoEmbedProvider`

Teaches the embed allow-list to recognise Vimeo. A public video is `vimeo.com/<numericId>`; an unlisted one adds a privacy hash as a second path segment (`vimeo.com/<id>/<hash>`), which the player needs as `?h=<hash>`. The numeric-id requirement rejects channel/profile pages, so the `youtube.com/lionsroaronline`-shaped decoy for Vimeo (`vimeo.com/<name>`) never matches. Registers automatically via the `app.embed_provider` `_instanceof` tag; no `services.yaml` edit.

**Files:**
- Create: `backend/src/Service/Reader/Media/Provider/VimeoEmbedProvider.php`
- Test: `backend/tests/Service/Reader/Media/Provider/VimeoEmbedProviderTest.php`
- Modify: `backend/tests/Service/Reader/Media/EmbedProvidersWiringTest.php` (add a Vimeo-through-the-iterator assertion)

**Interfaces:**
- Consumes: `App\Service\Reader\Media\EmbedProviderInterface` (`matches(string): bool`, `normalize(string): ?string`, `poster(string): ?string`, `label(): string`).
- Produces: class `App\Service\Reader\Media\Provider\VimeoEmbedProvider`. `normalize('https://vimeo.com/1226652197/')` returns `'https://player.vimeo.com/video/1226652197'`; `normalize('https://vimeo.com/76979871/8272103f6e')` returns `'https://player.vimeo.com/video/76979871?h=8272103f6e'`; `label()` returns `'Watch on Vimeo'`; `poster()` returns `null`.

- [ ] **Step 1: Write the failing test**

Create `backend/tests/Service/Reader/Media/Provider/VimeoEmbedProviderTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Service\Reader\Media\Provider;

use App\Service\Reader\Media\Provider\VimeoEmbedProvider;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class VimeoEmbedProviderTest extends TestCase
{
    private VimeoEmbedProvider $provider;

    protected function setUp(): void
    {
        $this->provider = new VimeoEmbedProvider();
    }

    /** @return iterable<string, array{0: string, 1: string}> */
    public static function embeddableUrls(): iterable
    {
        yield 'public id' => ['https://vimeo.com/1226652197', 'https://player.vimeo.com/video/1226652197'];
        yield 'trailing slash' => ['https://vimeo.com/1226652197/', 'https://player.vimeo.com/video/1226652197'];
        yield 'www host' => ['https://www.vimeo.com/1226652197', 'https://player.vimeo.com/video/1226652197'];
        yield 'unlisted hash' => [
            'https://vimeo.com/76979871/8272103f6e',
            'https://player.vimeo.com/video/76979871?h=8272103f6e',
        ];
        yield 'player url' => ['https://player.vimeo.com/video/76979871', 'https://player.vimeo.com/video/76979871'];
        yield 'player url with hash' => [
            'https://player.vimeo.com/video/76979871?h=8272103f6e',
            'https://player.vimeo.com/video/76979871?h=8272103f6e',
        ];
    }

    #[DataProvider('embeddableUrls')]
    public function testNormalisesEverySpellingToOnePlayerEmbed(string $url, string $expected): void
    {
        self::assertTrue($this->provider->matches($url));
        self::assertSame($expected, $this->provider->normalize($url));
    }

    /** @return iterable<string, array{0: string}> */
    public static function unembeddableUrls(): iterable
    {
        yield 'profile name' => ['https://vimeo.com/staffpicks'];
        yield 'channel path' => ['https://vimeo.com/channels/staffpicks/12345'];
        yield 'player asset' => ['https://player.vimeo.com/api/player.js'];
        yield 'other host' => ['https://example.test/1226652197'];
        yield 'not https' => ['http://vimeo.com/1226652197'];
    }

    #[DataProvider('unembeddableUrls')]
    public function testRefusesEverythingThatIsNotAVideoReference(string $url): void
    {
        self::assertFalse($this->provider->matches($url));
        self::assertNull($this->provider->normalize($url));
    }

    public function testOffersNoPosterAndNamesTheHost(): void
    {
        self::assertNull($this->provider->poster('https://vimeo.com/1226652197'));
        self::assertSame('Watch on Vimeo', $this->provider->label());
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `cd backend && php bin/phpunit tests/Service/Reader/Media/Provider/VimeoEmbedProviderTest.php`
Expected: FAIL — class `VimeoEmbedProvider` not found.

- [ ] **Step 3: Write minimal implementation**

Create `backend/src/Service/Reader/Media/Provider/VimeoEmbedProvider.php`:

```php
<?php

declare(strict_types=1);

namespace App\Service\Reader\Media\Provider;

use App\Service\Reader\Media\EmbedProviderInterface;

/**
 * Vimeo, reduced to one player embed. A public video is `vimeo.com/<id>`; an
 * unlisted one carries a privacy hash as a second path segment
 * (`vimeo.com/<id>/<hash>`), which the player needs as `?h=<hash>`. The id is
 * numeric, so a channel or profile page (`vimeo.com/staffpicks`) never matches.
 */
final readonly class VimeoEmbedProvider implements EmbedProviderInterface
{
    private const string PLAYER_HOST = 'player.vimeo.com';
    private const array PAGE_HOSTS = ['vimeo.com', 'www.vimeo.com'];
    private const string HASH = '[A-Za-z0-9]+';

    public function matches(string $url): bool
    {
        return $this->normalize($url) !== null;
    }

    public function normalize(string $url): ?string
    {
        $reference = $this->reference($url);
        if ($reference === null) {
            return null;
        }
        [$id, $hash] = $reference;

        return 'https://player.vimeo.com/video/' . $id . ($hash === null ? '' : '?h=' . $hash);
    }

    public function poster(string $url): ?string
    {
        return null;
    }

    public function label(): string
    {
        return 'Watch on Vimeo';
    }

    /** @return array{0: string, 1: ?string}|null the video id and its optional privacy hash */
    private function reference(string $url): ?array
    {
        $parts = parse_url($url);
        if (($parts['scheme'] ?? '') !== 'https' || !isset($parts['host'], $parts['path'])) {
            return null;
        }
        $host = strtolower($parts['host']);
        if ($host === self::PLAYER_HOST) {
            return $this->fromPlayer($parts['path'], $parts['query'] ?? '');
        }

        return \in_array($host, self::PAGE_HOSTS, true) ? $this->fromPage($parts['path']) : null;
    }

    /** @return array{0: string, 1: ?string}|null */
    private function fromPlayer(string $path, string $query): ?array
    {
        if (preg_match('#^/video/(\d+)$#', $path, $matches) !== 1) {
            return null;
        }
        parse_str($query, $params);
        $hash = $params['h'] ?? null;

        return [$matches[1], \is_string($hash) && preg_match('#^' . self::HASH . '$#', $hash) === 1 ? $hash : null];
    }

    /** @return array{0: string, 1: ?string}|null */
    private function fromPage(string $path): ?array
    {
        return preg_match('#^/(\d+)(?:/(' . self::HASH . '))?/?$#', $path, $matches) === 1
            ? [$matches[1], $matches[2] ?? null]
            : null;
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `cd backend && php bin/phpunit tests/Service/Reader/Media/Provider/VimeoEmbedProviderTest.php`
Expected: PASS.

- [ ] **Step 5: Add the wiring assertion**

In `backend/tests/Service/Reader/Media/EmbedProvidersWiringTest.php`, add this method after `testBrightcoveResolvesThroughTheTaggedIterator()` (before the closing brace):

```php
    public function testVimeoResolvesThroughTheTaggedIterator(): void
    {
        $target = $this->providers()->resolve('https://vimeo.com/1226652197/');

        self::assertNotNull($target);
        self::assertSame('https://player.vimeo.com/video/1226652197', $target->url);
    }
```

- [ ] **Step 6: Run the wiring test**

Run: `cd backend && php bin/phpunit tests/Service/Reader/Media/EmbedProvidersWiringTest.php`
Expected: PASS — the provider auto-registers through the `app.embed_provider` tag with no config change.

- [ ] **Step 7: Commit**

```bash
cd backend && git add src/Service/Reader/Media/Provider/VimeoEmbedProvider.php \
  tests/Service/Reader/Media/Provider/VimeoEmbedProviderTest.php \
  tests/Service/Reader/Media/EmbedProvidersWiringTest.php
git commit -m "feat(#1048): recognise Vimeo in the embed allow-list"
```

---

## Task 2: `ScriptEmbedSource`

A player some sites build client-side leaves no embeddable node in the fetched page — only a URL in an inline script. This source scans inline-script text for every `https://` URL, runs each through `EmbedProviders`, and emits an `Embed` candidate per distinct normalized target. Priority 50 puts it below every DOM-based source, so a real iframe, `og:video`, or JSON-LD keeps the place; this source only introduces a URL nothing else found. Scripts under page chrome (`aside, nav, footer`) are skipped, so a related-video widget never becomes the article's hero. The candidate carries no prose anchor, so `PageMediaInserter` top-places it as the hero — correct for a video post whose script sits at the page's end, far from the empty mount the player fills.

**Files:**
- Create: `backend/src/Service/Reader/Media/Source/ScriptEmbedSource.php`
- Test: `backend/tests/Service/Reader/Media/Source/ScriptEmbedSourceTest.php`
- Modify: `backend/tests/Service/Reader/Media/PageMediaScannerWiringTest.php` (append to the pinned order list)

**Interfaces:**
- Consumes: `App\Service\Reader\Media\MediaCandidateSourceInterface` (`find(string $pageHtml, string $pageUrl): list<MediaCandidate>`); `App\Service\Reader\Media\EmbedProviders::resolve(string): ?EmbedTarget`; `App\Service\Reader\Media\PageFurniture::holds(Element): bool`; `App\Service\Html\HtmlDocumentParser::parseOrNull(string): ?HTMLDocument`.
- Produces: class `App\Service\Reader\Media\Source\ScriptEmbedSource`, tagged `#[AsTaggedItem(priority: 50)]`. Emits `MediaCandidate(MediaKind::Embed, <normalized url>, <target poster>, <target label>)` with `precedingText === null`.

- [ ] **Step 1: Write the failing test**

Create `backend/tests/Service/Reader/Media/Source/ScriptEmbedSourceTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Service\Reader\Media\Source;

use App\Service\Reader\Media\EmbedProviders;
use App\Service\Reader\Media\MediaKind;
use App\Service\Reader\Media\Provider\VimeoEmbedProvider;
use App\Service\Reader\Media\Provider\YouTubeEmbedProvider;
use App\Service\Reader\Media\Source\ScriptEmbedSource;
use PHPUnit\Framework\TestCase;

final class ScriptEmbedSourceTest extends TestCase
{
    private function source(): ScriptEmbedSource
    {
        return new ScriptEmbedSource(new EmbedProviders([new YouTubeEmbedProvider(), new VimeoEmbedProvider()]));
    }

    private function find(string $body): array
    {
        return $this->source()->find('<html lang="en"><body>' . $body . '</body></html>', 'https://site.test/a');
    }

    public function testEmbedsAVimeoUrlAScriptVariableCarries(): void
    {
        $found = $this->find('<p>Text.</p><script>var videoData = {"url":"https://vimeo.com/1226652197/"};</script>');

        self::assertCount(1, $found);
        self::assertSame(MediaKind::Embed, $found[0]->kind);
        self::assertSame('https://player.vimeo.com/video/1226652197', $found[0]->url);
        self::assertNull($found[0]->precedingText);
        self::assertSame('Watch on Vimeo', $found[0]->label);
    }

    public function testIgnoresProviderAssetAndPageUrlsThatAreNotVideos(): void
    {
        $found = $this->find(
            '<script>var api = "https://player.vimeo.com/api/player.js";'
            . 'var channel = "https://www.youtube.com/lionsroaronline";</script>'
        );

        self::assertSame([], $found);
    }

    public function testSkipsScriptsInsidePageChrome(): void
    {
        $found = $this->find(
            '<article><p>Body.</p></article>'
            . '<footer><script>var videoData = {"url":"https://vimeo.com/1226652197/"};</script></footer>'
        );

        self::assertSame([], $found);
    }

    public function testCollapsesTheSameVideoNamedByTwoScripts(): void
    {
        $found = $this->find(
            '<script>var videoData = {"url":"https://vimeo.com/1226652197/"};</script>'
            . '<script>var alt = "https://vimeo.com/1226652197";</script>'
        );

        self::assertCount(1, $found);
        self::assertSame('https://player.vimeo.com/video/1226652197', $found[0]->url);
    }

    public function testResolvesAYouTubeEmbedUrlInsideAJsonLdScript(): void
    {
        $found = $this->find(
            '<script type="application/ld+json">{"@type":"VideoObject",'
            . '"embedUrl":"https://www.youtube.com/embed/aaaaaaaaaa1"}</script>'
        );

        self::assertCount(1, $found);
        self::assertSame('https://www.youtube-nocookie.com/embed/aaaaaaaaaa1', $found[0]->url);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `cd backend && php bin/phpunit tests/Service/Reader/Media/Source/ScriptEmbedSourceTest.php`
Expected: FAIL — class `ScriptEmbedSource` not found.

- [ ] **Step 3: Write minimal implementation**

Create `backend/src/Service/Reader/Media/Source/ScriptEmbedSource.php`:

```php
<?php

declare(strict_types=1);

namespace App\Service\Reader\Media\Source;

use App\Service\Html\HtmlDocumentParser;
use App\Service\Reader\Media\EmbedProviders;
use App\Service\Reader\Media\EmbedTarget;
use App\Service\Reader\Media\MediaCandidate;
use App\Service\Reader\Media\MediaCandidateSourceInterface;
use App\Service\Reader\Media\MediaKind;
use App\Service\Reader\Media\PageFurniture;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;

/**
 * A player some sites build client-side leaves no embeddable node in the
 * fetched page — only a URL in an inline script (Lion's Roar's
 * `var videoData = {"url":"https://vimeo.com/<id>/"}`). Every https URL an
 * inline script names is run through the embed allow-list; only a provider
 * match becomes a candidate, so a `player.js` asset or an analytics ping is
 * ignored. Scripts under page chrome are skipped, so a related-video widget in
 * a sidebar or footer never becomes the article's hero.
 *
 * No prose anchor: the script sits at the page's end, far from the empty mount
 * the player fills, so the candidate is top-placed as the hero rather than
 * dragged behind the last paragraph.
 */
#[AsTaggedItem(priority: 50)]
final readonly class ScriptEmbedSource implements MediaCandidateSourceInterface
{
    private const string URL_PATTERN = '#https://[^"\'\s\\\\<>]+#i';

    public function __construct(private EmbedProviders $providers)
    {
    }

    public function find(string $pageHtml, string $pageUrl): array
    {
        $document = HtmlDocumentParser::parseOrNull($pageHtml);
        if ($document === null) {
            return [];
        }

        $found = [];
        foreach ($document->querySelectorAll('script') as $script) {
            if (PageFurniture::holds($script)) {
                continue;
            }
            foreach ($this->embedTargets($script->textContent ?? '') as $target) {
                $found[$target->url] ??= new MediaCandidate(
                    MediaKind::Embed,
                    $target->url,
                    $target->posterUrl,
                    $target->label,
                );
            }
        }

        return array_values($found);
    }

    /** @return list<EmbedTarget> */
    private function embedTargets(string $scriptText): array
    {
        // A URL can sit inside a JSON string nested in the script; one decode
        // turns a stray "&quot;" back into a quote so the pattern stops at it.
        $decoded = html_entity_decode($scriptText, \ENT_QUOTES | \ENT_HTML5);
        preg_match_all(self::URL_PATTERN, $decoded, $matches);
        $targets = [];
        foreach ($matches[0] as $url) {
            $target = $this->providers->resolve($url);
            if ($target !== null) {
                $targets[] = $target;
            }
        }

        return $targets;
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `cd backend && php bin/phpunit tests/Service/Reader/Media/Source/ScriptEmbedSourceTest.php`
Expected: PASS.

Note on `testCollapsesTheSameVideoNamedByTwoScripts`: two scripts name the same video with different spellings (`vimeo.com/<id>/` and `vimeo.com/<id>`); both normalize to one player URL, so the `$found[$target->url] ??=` keyed merge yields a single candidate. On the real Lion's Roar page the GTM dataLayer names the video with JSON-escaped slashes (`https:\/\/vimeo.com\/...`), which the `https://` pattern does not match — harmless, because the unescaped `videoData` occurrence is matched and produces the one embed.

- [ ] **Step 5: Pin the source order**

In `backend/tests/Service/Reader/Media/PageMediaScannerWiringTest.php`:

Add the import after the existing `Source\SemanticMediaSource` import (line 12):

```php
use App\Service\Reader\Media\Source\ScriptEmbedSource;
```

Append `ScriptEmbedSource::class` as the last element of the `assertSame([...], $ordered)` list in `testTheSourcesRunInTheirDeclaredOrder()`:

```php
        self::assertSame([
            JsonLdMediaSource::class,
            MetaMediaSource::class,
            PageEmbedSource::class,
            SemanticMediaSource::class,
            AttributeMediaSource::class,
            YouTubeIdAttributeSource::class,
            ScriptEmbedSource::class,
        ], $ordered);
```

- [ ] **Step 6: Run the wiring test**

Run: `cd backend && php bin/phpunit tests/Service/Reader/Media/PageMediaScannerWiringTest.php`
Expected: PASS — the source auto-registers at priority 50, last in the order.

- [ ] **Step 7: Commit**

```bash
cd backend && git add src/Service/Reader/Media/Source/ScriptEmbedSource.php \
  tests/Service/Reader/Media/Source/ScriptEmbedSourceTest.php \
  tests/Service/Reader/Media/PageMediaScannerWiringTest.php
git commit -m "feat(#1048): embed a provider video an inline script names"
```

---

## Task 3: End-to-end through `ArticleExtractor`

Proves the whole pipeline on a realistic Lion's Roar-shaped page: an empty player mount in the body, the Vimeo URL only in a footer-region `<script>`, an `og:image`, and a YouTube channel-link decoy in the footer. The e2e test wires the two new collaborators into the manual extractor helper (this test is a plain `TestCase`, not a kernel test).

**Files:**
- Create: `backend/tests/Fixtures/reader/media/lionsroar-vimeo-script.html`
- Modify: `backend/tests/Service/Reader/ArticleExtractorTest.php` (helper wiring + one test)

**Interfaces:**
- Consumes: `ArticleExtractorTest::extractFixture(string): ExtractionResult` (result has `->ok: bool` and `->contentHtml: ?string`); the `mediaScanner()` and `providers()` helpers.

- [ ] **Step 1: Create the fixture**

Create `backend/tests/Fixtures/reader/media/lionsroar-vimeo-script.html`:

```html
<!DOCTYPE html>
<html lang="en"><head><title>Ronny Chieng Is Learning Every Day — Lion's Roar</title>
<meta property="og:image" content="https://site.test/uploads/ronny-chieng.jpg">
</head>
<body>
  <nav><a href="/">Home</a><a href="/video">Video</a></nav>
  <article>
    <h1>Ronny Chieng Is Learning Every Day</h1>
    <div class="videoPlayer-wrapper"><div id="videoPlayer"></div></div>
    <p>The comedian sat down for a long conversation about attention, discipline, and the practice of staying curious when the work stops being new, which is where most people quietly give up on it.</p>
    <p>He talked about the difference between the version of himself the audience meets on stage and the version that does the unglamorous preparation nobody claps for, and why the second one is the only one that lasts.</p>
    <p>The interview kept returning to the same idea from different angles: that learning is not a phase you finish but a habit you keep, and that the moment you decide you already know enough is the moment you start to shrink.</p>
    <p>By the end he had turned a conversation about comedy into something closer to a conversation about how to keep paying attention to a life while you are busy living it.</p>
  </article>
  <footer>
    <a href="https://www.youtube.com/lionsroaronline">Subscribe on YouTube</a>
    <script id="video-player-js-js-extra">var videoData = {"url":"https://vimeo.com/1226652197/"};</script>
  </footer>
</body></html>
```

- [ ] **Step 2: Wire the new collaborators into the extractor helper**

In `backend/tests/Service/Reader/ArticleExtractorTest.php`:

Add the import after the existing `Provider\YouTubeEmbedProvider` import (line 42):

```php
use App\Service\Reader\Media\Provider\VimeoEmbedProvider;
```

Add the import after the existing `Source\SemanticMediaSource` import (line 48):

```php
use App\Service\Reader\Media\Source\ScriptEmbedSource;
```

In `mediaScanner()`, append the source as the last element (mirrors its production priority — lowest, last):

```php
        return new PageMediaScanner([
            new JsonLdMediaSource($urlKind, $providers),
            new PageEmbedSource($providers),
            new AttributeMediaSource($urlKind, new MediaRelevance()),
            new YouTubeIdAttributeSource($providers),
            new SemanticMediaSource($urlKind),
            new ScriptEmbedSource($providers),
        ]);
```

In `providers()`, add the Vimeo provider:

```php
    private function providers(): EmbedProviders
    {
        return new EmbedProviders([
            new YouTubeEmbedProvider(),
            new BrightcoveEmbedProvider(),
            new VimeoEmbedProvider(),
        ]);
    }
```

- [ ] **Step 3: Write the failing test**

Add to `backend/tests/Service/Reader/ArticleExtractorTest.php`, after `testRecoversEveryEmbedThePageCarriesEachUnderItsOwnSection()` (after line 776):

```php
    public function testEmbedsAVideoTheSourcePageInjectsWithScriptOnly(): void
    {
        $result = $this->extractFixture('media/lionsroar-vimeo-script.html');

        self::assertTrue($result->ok);
        $html = (string) $result->contentHtml;
        self::assertStringContainsString('https://player.vimeo.com/video/1226652197', $html);
        // The footer's channel link is not a video id, so nothing YouTube is embedded.
        self::assertStringNotContainsString('youtube', $html);
    }
```

- [ ] **Step 4: Run test to verify it fails, then passes**

Run: `cd backend && php bin/phpunit tests/Service/Reader/ArticleExtractorTest.php --filter testEmbedsAVideoTheSourcePageInjectsWithScriptOnly`

Expected first (before Tasks 1–2 are on the branch): FAIL. After Tasks 1–2 are committed and the helper is wired: PASS. If it still fails after wiring, read the assertion diff — a missing `player.vimeo.com` means the source or provider is not reached; a present `youtube` means the decoy leaked (investigate rather than loosen the assertion).

- [ ] **Step 5: Guard against a corpus regression**

Run the sources' and extractor's existing suites to confirm the new source changes no existing output (the Brightcove and `multi-embed` provider URLs in scripts resolve to URLs higher-priority sources already emit, so they merge and add nothing):

Run: `cd backend && php bin/phpunit tests/Service/Reader/`
Expected: PASS — no existing expectation changes.

- [ ] **Step 6: Commit**

```bash
cd backend && git add tests/Fixtures/reader/media/lionsroar-vimeo-script.html \
  tests/Service/Reader/ArticleExtractorTest.php
git commit -m "test(#1048): embed a script-injected Vimeo video end to end"
```

---

## Task 4: Verify the gates and open the PR

**Files:** none created; runs the quality gates the CI enforces and opens the pull request.

- [ ] **Step 1: Static analysis and style**

Run:
```bash
cd backend && bin/console cache:warmup && composer check && composer md
```
Expected: PSR-12 clean, PHPStan level max clean, phptramp clean, PHPMD codesize clean on the two new `src` files. Fix any finding by improving the design, not the threshold. Note: CI runs the tip of phptramp's `develop`; if `composer tramp` reports a chain with no matching change in this branch, check `composer show larspohlmann/phptramp` before hunting in application code.

- [ ] **Step 2: PhpStorm inspections on the changed PHP**

Run `mcp__phpstorm__lint_files` on the four changed/created PHP files. Block on ERROR and WARNING; weak warnings are advisory.

- [ ] **Step 3: Full backend suite (SQLite leg)**

Run: `cd backend && php bin/phpunit`
Expected: green.

- [ ] **Step 4: Mutation testing on the diff**

Run: `cd backend && composer infection:diff`
Expected: meets `minMsi` in `infection.json5`. An escaped mutant on a new line means a missing assertion — add the test that kills it (do not lower the gate). Likely targets: the `?h=` hash branch in `VimeoEmbedProvider::normalize`, the numeric-id guard, and the `PageFurniture::holds` skip in `ScriptEmbedSource` (the furniture test kills it).

- [ ] **Step 5: Scan the dev log**

Run: `cd backend && ls -t var/log/dev-*.log | head -1 | xargs tail -n 50 | jq .`
Expected: no new deprecation or swallowed error from the extraction path.

- [ ] **Step 6: MySQL leg via Docker**

Confirm the container is current, then:
```bash
docker compose exec php composer test -- --filter 'VimeoEmbedProvider|ScriptEmbedSource|ArticleExtractor'
```
Expected: green on MySQL.

- [ ] **Step 7: Push and open the PR**

```bash
cd backend && git push -u origin feature/1048-embed-script-injected-video
gh pr create --repo larspohlmann/simple-feed-reader --base develop \
  --title "Embed a video the source page injects only with client-side script (#1048)" \
  --body "Closes #1048"
```
Fill the PR body from the repo PR template if one exists, keeping `Closes #1048`. After creating, verify CI on the exact SHA before asking for review.

---

## Self-Review

- **Spec coverage:** #1048 asks for (a) `VimeoEmbedProvider` — Task 1; (b) `ScriptEmbedSource` — Task 2; (c) the false-positive measurement — done during planning (corpus is clean; the Brightcove and multi-embed script URLs merge to existing URLs), guarded by Task 3 Step 5, so no empty-container filter is built (YAGNI, per the issue's "add the filter only if the count justifies it"); (d) the `lionsroar-vimeo-script.html` fixture and e2e — Task 3; (e) wiring-test updates — Tasks 1 and 2; (f) PHPMD/tramp/Infection — Task 4. All covered.
- **Placeholder scan:** none; every code and test block is complete.
- **Type consistency:** `EmbedProviderInterface`, `EmbedProviders::resolve(): ?EmbedTarget`, `EmbedTarget(url, posterUrl, label)`, `MediaCandidate(kind, url, posterUrl, label, precedingText=null)`, `MediaCandidateSourceInterface::find(): list<MediaCandidate>`, `PageFurniture::holds(Element)`, `HtmlDocumentParser::parseOrNull()` all match the classes as read on 2026-09-15.
- **Poster:** `VimeoEmbedProvider::poster()` returns null by design; an `Embed` candidate is not subject to `MediaCandidate::resolvePoster` (that guards only `isVideo()` kinds), so a null-poster embed is never dropped. The Vimeo player self-posters.
