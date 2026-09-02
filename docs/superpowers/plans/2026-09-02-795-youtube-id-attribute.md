# #795 YouTube id in `data-video-id` Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** A page that declares its YouTube video only as an 11-character id in a `data-video-id` attribute on an element that names YouTube gets the same player a JSON-LD or iframe declaration would give it.

**Architecture:** One new tagged `MediaCandidateSourceInterface` implementation, `YouTubeIdAttributeSource` (priority 55), builds a watch URL from the id and hands it to `EmbedProviders::resolve()`; the scanner, inserter and frontend are untouched. A Guardian-shaped fixture proves it through the real container and the full extractor.

**Tech Stack:** PHP 8.4 / Symfony 7.4, `\Dom\HTMLDocument`, PHPUnit.

**Spec:** `docs/superpowers/specs/2026-09-02-795-youtube-id-attribute-design.md`

## Global Constraints

- Host-agnostic: no host name in `src/`. The rule is: element outside `PageFurniture`, `data-video-id` matching `^[A-Za-z0-9_-]{11}$`, and the element's own tag name or any attribute name/value matching `youtube` or a `yt-`/`yt_` token (case-insensitive).
- The candidate is built by `EmbedProviders::resolve('https://www.youtube.com/watch?v=<id>')` — URL, poster and label come from the provider, never assembled by hand.
- `#[AsTaggedItem(priority: 55)]`; the interface's `#[AutoconfigureTag]` does the wiring, no `services.yaml` edit.
- Reader cache `VERSION` +1 from the value on develop at branch time (comment line in the file's style).
- Measured baseline: over 70 files (every `backend/tests/Fixtures/**/*.html` plus the #782 survey and live pages) the rule fires on exactly the two Guardian pages. Every existing test in `tests/Service/Reader/Media` must stay green without edits to its expectations.
- Clean Code per CLAUDE.md: `final readonly`, guard clauses, every touched `src` file PHPMD-clean, comments ≤ 3 lines. Commit messages `type(#795): summary`. Run backend tests from `backend/` with `php bin/phpunit <path>`.

---

### Task 1: `YouTubeIdAttributeSource` with its unit test

**Files:**
- Create: `backend/src/Service/Reader/Media/Source/YouTubeIdAttributeSource.php`
- Create: `backend/tests/Service/Reader/Media/Source/YouTubeIdAttributeSourceTest.php`

**Interfaces:**
- Consumes: `EmbedProviders::resolve(string): ?EmbedTarget` (`->url`, `->posterUrl`, `->label`), `PageFurniture::holds(Element): bool`, `PageTextBlocks::fromDocument()` / `->before(Element): ?string`, `HtmlDocumentParser::parseOrNull()`.
- Produces: `YouTubeIdAttributeSource::find(string $pageHtml, string $pageUrl): list<MediaCandidate>`.

- [ ] **Step 1: Write the failing tests**

```php
<?php

declare(strict_types=1);

namespace App\Tests\Service\Reader\Media\Source;

use App\Service\Reader\Media\EmbedProviders;
use App\Service\Reader\Media\MediaKind;
use App\Service\Reader\Media\Provider\YouTubeEmbedProvider;
use App\Service\Reader\Media\Source\YouTubeIdAttributeSource;
use PHPUnit\Framework\TestCase;

final class YouTubeIdAttributeSourceTest extends TestCase
{
    private const string PROSE =
        'The paragraph the player followed on the source page, long enough to be prose.';

    private YouTubeIdAttributeSource $source;

    protected function setUp(): void
    {
        $this->source = new YouTubeIdAttributeSource(new EmbedProviders([new YouTubeEmbedProvider()]));
    }

    /** The Guardian's youtube-atom, reached without naming the Guardian. */
    public function testAnElementNamingYouTubeWithAnIdYieldsTheEmbed(): void
    {
        $html = '<body><div data-component="youtube-atom" data-atom-id="8052ac31" data-video-id="pz8VRrI0p0U"></div></body>';

        $found = $this->source->find($html, 'https://x.test/whales-video');

        self::assertCount(1, $found);
        self::assertSame(MediaKind::Embed, $found[0]->kind);
        self::assertSame('https://www.youtube-nocookie.com/embed/pz8VRrI0p0U', $found[0]->url);
        self::assertSame('https://i.ytimg.com/vi/pz8VRrI0p0U/hqdefault.jpg', $found[0]->posterUrl);
        self::assertSame('Watch on YouTube', $found[0]->label);
    }

    /** zeit.de spells the marker as an id prefix. */
    public function testAYtPrefixedIdIsTheMarkerToo(): void
    {
        $html = '<body><div id="yt-JSrAQkrp1JI0" data-video-id="JSrAQkrp1JI"></div></body>';

        $found = $this->source->find($html, 'https://x.test/a');

        self::assertCount(1, $found);
        self::assertSame('https://www.youtube-nocookie.com/embed/JSrAQkrp1JI', $found[0]->url);
    }

    public function testTheTagNameCanCarryTheMarker(): void
    {
        $html = '<body><youtube-player data-video-id="M1j_uRqKMKI"></youtube-player></body>';

        self::assertCount(1, $this->source->find($html, 'https://x.test/a'));
    }

    /** Brightcove's in-page embed uses the same attribute with a numeric id. */
    public function testANumericIdOnAnotherProvidersPlayerYieldsNothing(): void
    {
        $html = '<body><video-js data-account="665003303001" data-player="6tKQRAx7lu" data-video-id="6404487520112"></video-js></body>';

        self::assertSame([], $this->source->find($html, 'https://x.test/a'));
    }

    public function testAnIdOnAnElementThatDoesNotNameYouTubeYieldsNothing(): void
    {
        $html = '<body><div class="player" data-video-id="pz8VRrI0p0U"></div></body>';

        self::assertSame([], $this->source->find($html, 'https://x.test/a'));
    }

    public function testATenCharacterIdYieldsNothing(): void
    {
        $html = '<body><div data-component="youtube-atom" data-video-id="pz8VRrI0p0"></div></body>';

        self::assertSame([], $this->source->find($html, 'https://x.test/a'));
    }

    public function testAnOccurrenceInsideFurnitureYieldsNothing(): void
    {
        $html = '<body><aside><div data-component="youtube-atom" data-video-id="pz8VRrI0p0U"></div></aside></body>';

        self::assertSame([], $this->source->find($html, 'https://x.test/a'));
    }

    public function testARepeatedIdYieldsOneCandidateAnchoredWhereItFirstAppears(): void
    {
        $html = '<body><p>' . self::PROSE . '</p>'
            . '<div data-component="youtube-atom" data-video-id="pz8VRrI0p0U"></div>'
            . '<p>Later prose, also long enough to count as a block of the article.</p>'
            . '<div class="embed--youtube" data-video-id="pz8VRrI0p0U"></div></body>';

        $found = $this->source->find($html, 'https://x.test/a');

        self::assertCount(1, $found);
        self::assertSame(self::PROSE, $found[0]->precedingText);
    }

    public function testIgnoresUnparseableHtml(): void
    {
        self::assertSame([], $this->source->find('', 'https://x.test/a'));
    }
}
```

Check `HtmlDocumentParser::parseOrNull('')` really returns null (other source tests have an `IgnoresMalformed…` case to copy); if it parses an empty document, assert on `[]` all the same — the test still holds.

- [ ] **Step 2: Run them to verify they fail**

Run: `php bin/phpunit tests/Service/Reader/Media/Source/YouTubeIdAttributeSourceTest.php`
Expected: error — class not found.

- [ ] **Step 3: Write the source**

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
use App\Service\Reader\Media\PageTextBlocks;
use Dom\Element;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;

/**
 * Host-agnostic: a publisher's own player (the Guardian's youtube-atom, zeit.de's
 * `yt-` embed) declares the video as a bare id in `data-video-id`, with no URL
 * anywhere on the page. The id alone is ambiguous — Brightcove and Vimeo use the
 * same attribute — so the element has to name YouTube itself, and the id has to
 * have YouTube's shape. The provider then builds the candidate as for any URL.
 */
#[AsTaggedItem(priority: 55)]
final readonly class YouTubeIdAttributeSource implements MediaCandidateSourceInterface
{
    private const string VIDEO_ID_ATTRIBUTE = 'data-video-id';
    private const string ID_PATTERN = '#^[A-Za-z0-9_-]{11}$#';
    private const string YOUTUBE_MARKER = '#youtube|(?:^|[^a-z])yt[-_]#i';

    public function __construct(private EmbedProviders $providers)
    {
    }

    public function find(string $pageHtml, string $pageUrl): array
    {
        $document = HtmlDocumentParser::parseOrNull($pageHtml);
        if ($document === null) {
            return [];
        }

        $blocks = PageTextBlocks::fromDocument($document);
        $found = [];
        foreach ($document->querySelectorAll('[' . self::VIDEO_ID_ATTRIBUTE . ']') as $element) {
            $target = $this->targetOf($element);
            if ($target !== null) {
                $found[$target->url] ??= new MediaCandidate(
                    MediaKind::Embed,
                    $target->url,
                    $target->posterUrl,
                    $target->label,
                    $blocks->before($element),
                );
            }
        }

        return array_values($found);
    }

    private function targetOf(Element $element): ?EmbedTarget
    {
        $id = $element->getAttribute(self::VIDEO_ID_ATTRIBUTE) ?? '';
        if (PageFurniture::holds($element) || preg_match(self::ID_PATTERN, $id) !== 1 || !$this->namesYouTube($element)) {
            return null;
        }

        return $this->providers->resolve('https://www.youtube.com/watch?v=' . $id);
    }

    private function namesYouTube(Element $element): bool
    {
        $ownMarkup = $element->localName;
        foreach ($element->attributes as $attribute) {
            $ownMarkup .= ' ' . $attribute->name . '=' . $attribute->value;
        }

        return preg_match(self::YOUTUBE_MARKER, $ownMarkup) === 1;
    }
}
```

The class docblock is five lines; trim it to three at most before committing (CLAUDE.md): keep the *why* — the id alone is ambiguous, so the element must name YouTube — and drop the examples into the tests.

- [ ] **Step 4: Run the tests**

Run: `php bin/phpunit tests/Service/Reader/Media/Source/YouTubeIdAttributeSourceTest.php`
Expected: PASS, 9 tests.

- [ ] **Step 5: Gates and commit**

Run from `backend/`: `composer cs:fix && composer check && composer md`
Expected: clean.

```bash
git add backend/src/Service/Reader/Media/Source/YouTubeIdAttributeSource.php backend/tests/Service/Reader/Media/Source/YouTubeIdAttributeSourceTest.php
git commit -m "feat(#795): find a YouTube video declared only as an id in data-video-id"
```

---

### Task 2: The Guardian shape through the container and the full extractor

**Files:**
- Create: `backend/tests/Fixtures/reader/media/guardian-youtube-atom.html`
- Modify: `backend/tests/Service/Reader/Media/HostAgnosticDiscoveryTest.php` (append one test)
- Modify: `backend/tests/Service/Reader/ArticleExtractorTest.php` (`mediaScanner()` list; append one test)
- Modify: `backend/tests/Service/Reader/Media/PageMediaScannerWiringTest.php` (append one test)

**Interfaces:**
- Consumes: `YouTubeIdAttributeSource` (Task 1) through the container tag and by hand in the extractor test's scanner.

- [ ] **Step 1: Write the fixture**

`backend/tests/Fixtures/reader/media/guardian-youtube-atom.html` — the Guardian shape reduced to what matters: an `og:image`, a custom-element island around the atom `<div>`, then the standfirst paragraphs. No JSON-LD `VideoObject`, no iframe, no `<video>`:

```html
<!DOCTYPE html>
<html lang="en"><head><title>Could humans ever communicate with whales? – video — Site</title>
<meta property="og:image" content="https://i.guim.co.uk/img/media/b9482880fccfdc811b513f091f736f7036f7cd01/256_0_4192_3353/master/4192.jpg?width=1200&amp;height=630&amp;quality=85&amp;auto=format&amp;fit=crop">
<meta property="og:video:url" content="https://www.theguardian.com/science/video/2026/sep/01/could-humans-ever-communicate-with-whales-video">
<script type="application/ld+json">{"@context":"https://schema.org","@type":"NewsArticle","headline":"Could humans ever communicate with whales? – video","datePublished":"2026-09-01T05:00:00Z"}</script>
</head>
<body>
  <nav><a href="/">Home</a><a href="/science">Science</a></nav>
  <main>
    <article>
      <h1>Could humans ever communicate with whales? – video</h1>
      <gu-island name="YoutubeBlockComponent" priority="critical" props="{&quot;id&quot;:&quot;8052ac31-0f8f-41d9-a604-865c61095b8d&quot;,&quot;assetId&quot;:&quot;pz8VRrI0p0U&quot;,&quot;isMainMedia&quot;:true,&quot;posterImage&quot;:&quot;https://media.guim.co.uk/f4ef21fc097ccc98181eeba46960e8f4650e07c2/0_0_1920_1080/1920.jpg&quot;,&quot;duration&quot;:908}">
        <div data-chromatic="ignore"><div data-component="youtube-atom" data-atom-id="8052ac31-0f8f-41d9-a604-865c61095b8d" data-video-id="pz8VRrI0p0U" data-video-unique-id="pz8VRrI0p0U-0"><div data-testid="youtube-sticky-placeholder"></div></div></div>
      </gu-island>
      <div data-gu-name="standfirst">
        <p><strong>Ever since the earliest known recording of whale song was made in 1949, researchers have been captivated by the wails, rumbles, clicks, grunts and squeals of their communication. As technology improves, scientists are decoding the intricate patterns that make different species’ vocalisations distinctive. Madeleine Finlay hears from Emma Bryce, a journalist who has spent months investigating human efforts to understand whale song.</strong></p>
        <p><strong>She explains how this knowledge could help us better protect whales from human-made harms, and why the research community is now cautious about what a decoded phrase would actually mean.</strong></p>
      </div>
    </article>
  </main>
  <aside><h3>More video</h3><div data-component="youtube-atom" data-video-id="U8duwJ2mKWs"></div></aside>
  <footer>© 2026</footer>
</body></html>
```

The `<aside>` carries a second atom on purpose: furniture must not become the article's media.

- [ ] **Step 2: Write the failing tests**

Append to `HostAgnosticDiscoveryTest`:

```php
    public function testTheGuardianYieldsItsYouTubeAtomAndNotTheSidebarOne(): void
    {
        $media = $this->scanner()->scan(
            $this->fixture('guardian-youtube-atom.html'),
            'https://www.theguardian.com/science/video/2026/sep/01/could-humans-ever-communicate-with-whales-video',
        );

        self::assertCount(1, $media->candidates);
        self::assertSame(MediaKind::Embed, $media->candidates[0]->kind);
        self::assertSame('https://www.youtube-nocookie.com/embed/pz8VRrI0p0U', $media->candidates[0]->url);
    }
```

Append to `PageMediaScannerWiringTest`:

```php
    public function testAnIdDeclaredInDataVideoIdIsCollectedThroughTheTag(): void
    {
        self::bootKernel();
        $scanner = self::getContainer()->get(PageMediaScanner::class);
        self::assertInstanceOf(PageMediaScanner::class, $scanner);

        $html = '<html><body><div data-component="youtube-atom" data-video-id="M1j_uRqKMKI"></div></body></html>';
        $media = $scanner->scan($html, 'https://example.test/article');

        self::assertSame('https://www.youtube-nocookie.com/embed/M1j_uRqKMKI', $media->candidates[0]->url);
    }
```

In `ArticleExtractorTest::mediaScanner()`, add `new YouTubeIdAttributeSource($providers),` after `new AttributeMediaSource(...)` (with the `use`), then append beside the other media tests:

```php
    /** Guardian 493958: the body opens with the player where the page had no URL at all, and no stacked lead. */
    public function testAYouTubeIdInADataAttributeBecomesTheLeadPlayer(): void
    {
        $html = (string) file_get_contents(__DIR__ . '/../../Fixtures/reader/media/guardian-youtube-atom.html');
        $result = $this->extractor(
            [new MockResponse($html, ['http_code' => 200])],
            ['www.theguardian.com' => ['93.184.216.34']],
        )->extract('https://www.theguardian.com/science/video/2026/sep/01/could-humans-ever-communicate-with-whales-video');

        self::assertTrue($result->ok);
        $body = (string) $result->contentHtml;
        self::assertStringStartsWith('<a href="https://www.youtube-nocookie.com/embed/pz8VRrI0p0U"', $body);
        self::assertStringContainsString('src="https://i.ytimg.com/vi/pz8VRrI0p0U/hqdefault.jpg"', $body);
        self::assertSame(1, substr_count($body, '<img'), 'the top-placed player is the lead visual; no hero above it');
        self::assertStringNotContainsString('U8duwJ2mKWs', $body);
        self::assertStringContainsString('earliest known recording of whale song', $body);
    }
```

If the sanitizer adds `rel`/`target` before `href` or reorders attributes, assert the prefix with a regex (`#^<a [^>]*href="https://www\.youtube-nocookie\.com/embed/pz8VRrI0p0U"#`) — the #793 note on entity-encoding applies to `=` in query strings only, which this URL has none of.

- [ ] **Step 3: Run them to verify they fail**

Run: `php bin/phpunit tests/Service/Reader/Media/HostAgnosticDiscoveryTest.php tests/Service/Reader/Media/PageMediaScannerWiringTest.php tests/Service/Reader/ArticleExtractorTest.php --filter 'Guardian|DataVideoId|DataAttribute'`
Expected: the discovery and wiring tests FAIL only if Task 1's tag is not picked up (they should already PASS, proving the wiring); the extractor test FAILS on `assertStringStartsWith` until `mediaScanner()` includes the source.

- [ ] **Step 4: Run the whole media and reader suites**

Run: `php bin/phpunit tests/Service/Reader`
Expected: PASS — every pre-existing discovery expectation unchanged (the rule fires on no other fixture).

- [ ] **Step 5: Gates and commit**

Run from `backend/`: `composer cs:fix && composer check`
Expected: clean.

```bash
git add backend/tests/Fixtures/reader/media/guardian-youtube-atom.html backend/tests/Service/Reader/Media/HostAgnosticDiscoveryTest.php backend/tests/Service/Reader/Media/PageMediaScannerWiringTest.php backend/tests/Service/Reader/ArticleExtractorTest.php
git commit -m "test(#795): the Guardian shape yields its player through the container and the extractor"
```

---

### Task 3: Reader cache version

**Files:**
- Modify: `frontend/src/app/reader/reader-cache.service.ts` (the `VERSION` constant and its comment block)

- [ ] **Step 1: Bump**

Read the current value on the branch (15 once the #782 follow-up is merged) and add, in the existing comment style, directly above the constant:

```ts
  // v16: v15 records hold no player for a page that declares its YouTube video
  // only as an id in a data attribute (#795).
  private static readonly VERSION = 16;
```

(Use current + 1 whatever the current value is.)

- [ ] **Step 2: Test and gate**

Run: `docker compose exec -T frontend npx jest src/app/reader/reader-cache.service.spec.ts`
From `frontend/`: `npm run check`
Expected: PASS / clean.

- [ ] **Step 3: Commit**

```bash
git add frontend/src/app/reader/reader-cache.service.ts
git commit -m "fix(#795): bump the reader cache version so cached Guardian video articles refetch"
```

---

### Task 4: Verification

- [ ] **Step 1: Backend gates and both legs**

From `backend/`: `composer check && composer md && php bin/phpunit && composer infection:diff`
From the repo root: `docker compose exec php vendor/bin/phpunit`
Expected: all green; MSI at or above `minMsi`.

- [ ] **Step 2: Corpus statement**

Run the prototype measurement again against the committed class: over every `backend/tests/Fixtures/**/*.html` the source yields a candidate only for `guardian-youtube-atom.html`. State the file count in the PR.

- [ ] **Step 3: Refresh the stack and check live**

`docker compose restart php worker`, then reload entry 493958 in Chrome through the reader's refresh control: the body opens with the YouTube player, the standfirst follows, no duplicate lead image. An unrelated entry with a YouTube iframe (495401, four players) is unchanged.

- [ ] **Step 4: PR**

Branch `feature/795-youtube-id-attribute` → `develop`, body `Closes #795` with the corpus count and the live-check result.
