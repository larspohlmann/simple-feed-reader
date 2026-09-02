# #788 Merge Media Sources Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** A higher-priority media source establishes precedence over a URL without hiding the unique candidates later sources find, so a page with one declared and four embedded videos renders all four under their sections.

**Architecture:** `PageMediaScanner` stops letting the first source own a whole `MediaKind`. It merges every source's candidates by normalised URL, in priority order: the first source to name a URL sets the candidate and its position, a later source fills the gaps it left (`MediaCandidate::completedBy()`), and new URLs are appended. Downstream placement (`PageMediaInserter`) is untouched: a candidate that gains its prose anchor from the page scan lands after its section.

**Tech Stack:** PHP 8.4, PHPUnit; one Angular constant.

**Spec:** `docs/superpowers/specs/2026-09-02-788-merge-media-sources-design.md`

## Global Constraints

- Branch `fix/788-merge-media-sources`; commit messages `type(#788): summary`; no attribution lines, no `Co-Authored-By`.
- PHP: `declare(strict_types=1)`, `final readonly class`, PSR-12, PHPStan level max, **every touched `src` file PHPMD-clean** (`composer md`). No boolean flag parameters. Comments only for a why, one line, three at most.
- Run from `backend/`: `composer cs` (autofix `composer cs:fix`), `composer stan` (after `bin/console cache:warmup --env=dev >/dev/null`), `composer md`, `php bin/phpunit <path>`.
- Do not change `PageMediaInserter`, `ReaderBodyCleaner`, `InBodyEmbedRewriter` or any source class; the fix is the merge in the scanner plus the candidate value object.
- YouTube ids in fixtures are exactly 11 characters of `[A-Za-z0-9_-]`.
- Frontend: run Jest inside the Docker frontend container (`docker compose exec -T frontend npx jest <path>` from the repo root).
- `grep` on this machine is ugrep; use `rg` or `grep -F`.

---

### Task 1: Merge by URL in the scanner

**Files:**
- Modify: `backend/src/Service/Reader/Media/MediaCandidate.php`
- Modify: `backend/src/Service/Reader/Media/PageMediaScanner.php`
- Modify: `backend/src/Service/Reader/Media/MediaCandidateSourceInterface.php` (docblock only)
- Create: `backend/tests/Service/Reader/Media/MediaCandidateTest.php`
- Modify: `backend/tests/Service/Reader/Media/PageMediaScannerTest.php`

**Interfaces:**
- Produces: `MediaCandidate::completedBy(MediaCandidate $later): self`.
- Produces: `PageMediaScanner::scan(string $pageHtml, string $pageUrl): ArticleMedia` (unchanged signature) returning every unique URL across sources, first-named first, capped at `ArticleMedia::MAX_ITEMS`.

- [ ] **Step 1: Write the failing tests**

`backend/tests/Service/Reader/Media/MediaCandidateTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Service\Reader\Media;

use App\Service\Reader\Media\MediaCandidate;
use App\Service\Reader\Media\MediaKind;
use PHPUnit\Framework\TestCase;

final class MediaCandidateTest extends TestCase
{
    public function testFillsOnlyTheGapsFromTheLaterCandidate(): void
    {
        $declared = new MediaCandidate(MediaKind::Embed, 'https://www.youtube-nocookie.com/embed/aaaaaaaaaaa', null, 'Watch on YouTube');
        $scanned = new MediaCandidate(
            MediaKind::Embed,
            'https://www.youtube-nocookie.com/embed/aaaaaaaaaaa',
            'https://i.ytimg.com/vi/aaaaaaaaaaa/hqdefault.jpg',
            'Scanned label',
            'The prose the player followed.',
        );

        $completed = $declared->completedBy($scanned);

        self::assertSame('https://i.ytimg.com/vi/aaaaaaaaaaa/hqdefault.jpg', $completed->posterUrl);
        self::assertSame('Watch on YouTube', $completed->label);
        self::assertSame('The prose the player followed.', $completed->precedingText);
        self::assertSame(MediaKind::Embed, $completed->kind);
    }

    public function testKeepsEverythingItAlreadyHas(): void
    {
        $full = new MediaCandidate(MediaKind::Video, 'https://x.test/a.mp4', 'https://x.test/a.jpg', 'A', 'Prose A.');
        $other = new MediaCandidate(MediaKind::Video, 'https://x.test/a.mp4', 'https://x.test/b.jpg', 'B', 'Prose B.');

        $completed = $full->completedBy($other);

        self::assertSame('https://x.test/a.jpg', $completed->posterUrl);
        self::assertSame('A', $completed->label);
        self::assertSame('Prose A.', $completed->precedingText);
    }
}
```

In `backend/tests/Service/Reader/Media/PageMediaScannerTest.php`, replace `testTheFirstSourceToYieldAKindWins` with these four tests (keep `testADifferentKindStillComesThroughALaterSource`, `testOneSourceMayYieldManyOfAKind` and `testTheCapStillApplies` as they are), and add the cross-source cap test:

```php
    /** A declared file and a scanned one at the same URL are one candidate, and the declaration's data stands. */
    public function testTheSameUrlFromTwoSourcesIsOneCandidateWithTheDeclaredData(): void
    {
        $scanner = new PageMediaScanner([
            $this->source([new MediaCandidate(MediaKind::Video, 'https://x.test/a.mp4', 'https://x.test/declared.jpg')]),
            $this->source([new MediaCandidate(MediaKind::Video, 'https://x.test/a.mp4', 'https://x.test/scanned.jpg')]),
        ]);

        $media = $scanner->scan('<html></html>', 'https://x.test/a');

        self::assertCount(1, $media->candidates);
        self::assertSame('https://x.test/declared.jpg', $media->candidates[0]->posterUrl);
    }

    /** vice 495401: JSON-LD declares the first video from the <head>, with no prose anchor; the page scan knows where it stands. */
    public function testALaterSourceFillsTheAnchorTheDeclarationLacks(): void
    {
        $url = 'https://www.youtube-nocookie.com/embed/aaaaaaaaaaa';
        $scanner = new PageMediaScanner([
            $this->source([new MediaCandidate(MediaKind::Embed, $url, 'https://i.ytimg.com/vi/aaaaaaaaaaa/hqdefault.jpg')]),
            $this->source([new MediaCandidate(MediaKind::Embed, $url, null, null, 'The section the player follows.')]),
        ]);

        $media = $scanner->scan('<html></html>', 'https://x.test/a');

        self::assertCount(1, $media->candidates);
        self::assertSame('The section the player follows.', $media->candidates[0]->precedingText);
        self::assertSame('https://i.ytimg.com/vi/aaaaaaaaaaa/hqdefault.jpg', $media->candidates[0]->posterUrl);
    }

    /** vice 495401: JSON-LD declares one of four videos; the other three exist only as page embeds. */
    public function testALaterSourceAddsTheUrlsTheEarlierOneNeverNamed(): void
    {
        $embed = static fn (string $id): MediaCandidate => new MediaCandidate(
            MediaKind::Embed,
            'https://www.youtube-nocookie.com/embed/' . $id,
        );
        $scanner = new PageMediaScanner([
            $this->source([$embed('aaaaaaaaaaa')]),
            $this->source([$embed('aaaaaaaaaaa'), $embed('bbbbbbbbbbb'), $embed('ccccccccccc'), $embed('ddddddddddd')]),
        ]);

        $urls = array_map(
            static fn (MediaCandidate $c): string => $c->url,
            $scanner->scan('<html></html>', 'https://x.test/a')->candidates,
        );

        self::assertSame([
            'https://www.youtube-nocookie.com/embed/aaaaaaaaaaa',
            'https://www.youtube-nocookie.com/embed/bbbbbbbbbbb',
            'https://www.youtube-nocookie.com/embed/ccccccccccc',
            'https://www.youtube-nocookie.com/embed/ddddddddddd',
        ], $urls);
    }

    /** A declared file and a differently named scanned one are both unique; the declaration comes first. */
    public function testAUniqueUrlOfAnAlreadySeenKindStillJoinsAfterTheDeclaredOne(): void
    {
        $scanner = new PageMediaScanner([
            $this->source([new MediaCandidate(MediaKind::Audio, 'https://x.test/declared.mp3')]),
            $this->source([new MediaCandidate(MediaKind::Audio, 'https://x.test/scanned.mp3')]),
        ]);

        $media = $scanner->scan('<html></html>', 'https://x.test/a');

        self::assertCount(2, $media->candidates);
        self::assertStringContainsString('declared', $media->candidates[0]->url);
        self::assertStringContainsString('scanned', $media->candidates[1]->url);
    }

    public function testTheCapAppliesToTheMergedListAcrossSources(): void
    {
        $first = [];
        $second = [];
        for ($i = 0; $i < 15; $i++) {
            $first[] = new MediaCandidate(MediaKind::Embed, 'https://x.test/e' . $i);
            $second[] = new MediaCandidate(MediaKind::Embed, 'https://x.test/f' . $i);
        }

        $scanner = new PageMediaScanner([$this->source($first), $this->source($second)]);

        self::assertCount(ArticleMedia::MAX_ITEMS, $scanner->scan('<html></html>', 'https://x.test/a')->candidates);
    }
```

- [ ] **Step 2: Run the tests to verify they fail**

Run from `backend/`: `php bin/phpunit tests/Service/Reader/Media/MediaCandidateTest.php tests/Service/Reader/Media/PageMediaScannerTest.php`
Expected: `completedBy` undefined; the anchor test and the "adds the URLs" test fail with 1 candidate; the unique-URL test fails with count 1.

- [ ] **Step 3: Add `completedBy` to `MediaCandidate`**

Append to the class, after the constructor:

```php
    /** The same media with the gaps a later, weaker source can fill: poster, label, and the prose anchor. */
    public function completedBy(self $later): self
    {
        return new self(
            $this->kind,
            $this->url,
            $this->posterUrl ?? $later->posterUrl,
            $this->label ?? $later->label,
            $this->precedingText ?? $later->precedingText,
        );
    }
```

- [ ] **Step 4: Replace the scanner's merge**

Replace the whole class body of `PageMediaScanner` (keep the namespace, imports, and the constructor) so it reads:

```php
/**
 * Runs every candidate source over the raw page, highest priority first, and
 * merges what they find by URL: the first source to name a URL sets the
 * candidate and its place, a later source fills the gaps it left (poster,
 * label, prose anchor), and a URL no earlier source named joins the list. A
 * declaration establishes precedence without hiding the embeds only a page
 * scan can see (#788).
 *
 * It reads the raw HTML rather than FetchedPageNormalizer's document on purpose:
 * that pass is tuned for readability scoring and removes elements, so discovery
 * must not depend on it. Working from the source costs one extra parse, the
 * same trade collapseWrapperChains() already makes.
 */
final readonly class PageMediaScanner
{
    /** @param iterable<MediaCandidateSourceInterface> $sources */
    public function __construct(
        #[AutowireIterator('app.media_candidate_source')]
        private iterable $sources,
    ) {
    }

    public function scan(string $pageHtml, string $pageUrl): ArticleMedia
    {
        $byUrl = [];
        foreach ($this->sources as $source) {
            foreach ($source->find($pageHtml, $pageUrl) as $candidate) {
                $byUrl[$candidate->url] = isset($byUrl[$candidate->url])
                    ? $byUrl[$candidate->url]->completedBy($candidate)
                    : $candidate;
            }
        }

        return new ArticleMedia(\array_slice(array_values($byUrl), 0, ArticleMedia::MAX_ITEMS));
    }
}
```

`claimUnownedKinds()` is deleted.

- [ ] **Step 5: Correct the interface docblock**

In `MediaCandidateSourceInterface.php`, replace the sentence
` * collected in AsTaggedItem priority order, highest first, and the first one to
 * yield a given MediaKind owns it — so a publisher's own declaration outranks
 * anything discovered by scanning.`
with
` * collected in AsTaggedItem priority order, highest first. The first to name a
 * URL sets that candidate; later ones fill its gaps and add the URLs it never
 * named — a declaration leads, but hides nothing a scan finds (#788).`

- [ ] **Step 6: Run the tests to verify they pass**

Run: `php bin/phpunit tests/Service/Reader/Media`
Expected: green, including `HostAgnosticDiscoveryTest` (every corpus fixture yields the same URLs as before — measured: cross-source duplicates are URL-identical) and `PageMediaScannerWiringTest`.

- [ ] **Step 7: Lint**

Run: `composer cs && composer md && bin/console cache:warmup --env=dev >/dev/null && composer stan`
Expected: clean.

- [ ] **Step 8: Commit**

```bash
git add src/Service/Reader/Media/MediaCandidate.php src/Service/Reader/Media/PageMediaScanner.php src/Service/Reader/Media/MediaCandidateSourceInterface.php tests/Service/Reader/Media/MediaCandidateTest.php tests/Service/Reader/Media/PageMediaScannerTest.php
git commit -m "fix(#788): merge media candidates by URL so a declared embed hides no page embed"
```

---

### Task 2: The vice-shaped fixture, through the corpus test and the whole pipeline

**Files:**
- Create: `backend/tests/Fixtures/reader/media/multi-embed-page.html`
- Modify: `backend/tests/Service/Reader/Media/HostAgnosticDiscoveryTest.php`
- Modify: `backend/tests/Service/Reader/ArticleExtractorTest.php:110-118` (the `mediaScanner()` factory) and append one test

**Interfaces:**
- Consumes: the merged scanner from Task 1.
- Consumes: `ArticleExtractorTest::extractFixture(string $fixture): ExtractionResult` (exists; resolves `tests/Fixtures/reader/<fixture>`).

- [ ] **Step 1: Write the fixture**

`backend/tests/Fixtures/reader/media/multi-embed-page.html` — the players sit inside `<noscript>` exactly as vice serves them, so readability drops them from the body and the scanner is the only route:

```html
<!DOCTYPE html>
<html lang="en"><head><title>4 remixes that were better than the originals — Site</title>
<meta property="og:image" content="https://site.test/uploads/lead.jpg">
<meta property="og:video" content="https://www.youtube.com/embed/aaaaaaaaaa1">
<script type="application/ld+json">{"@context":"https://schema.org","@graph":[{"@type":"NewsArticle","headline":"4 remixes that were better than the originals","image":{"@type":"ImageObject","contentUrl":"https://site.test/uploads/lead.jpg"},"video":{"@type":"VideoObject","name":"Remix one","embedUrl":"https://www.youtube.com/embed/aaaaaaaaaa1","thumbnailUrl":"https://site.test/uploads/aaaaaaaaaa1.jpg"}}]}</script>
</head>
<body>
  <nav><a href="/">Home</a><a href="/music">Music</a></nav>
  <article>
    <h1>4 remixes that were better than the originals</h1>
    <p>Sometimes a remix can totally eclipse the original it was built from, and the 2000s produced a whole run of them that still sound better than the songs they started from, which is the only reason this list exists.</p>
    <h2>1. Apologize</h2>
    <p>The first remix took a piano ballad and gave it a beat the original never had, which is why the remix version is the one every radio station played that winter and the one people still hum.</p>
    <figure class="wp-block-embed is-provider-youtube"><div class="wp-block-embed__wrapper"><noscript><iframe loading="lazy" title="Remix one" src="https://www.youtube.com/embed/aaaaaaaaaa1?feature=oembed"></iframe></noscript></div></figure>
    <h2>2. A Little Less Conversation</h2>
    <p>The second remix dragged a sixties film song into a big-beat present and turned a forgotten album track into a chart hit twenty years after it was recorded, a trick nobody has managed since.</p>
    <figure class="wp-block-embed is-provider-youtube"><div class="wp-block-embed__wrapper"><noscript><iframe loading="lazy" title="Remix two" src="https://www.youtube.com/embed/aaaaaaaaaa2?feature=oembed"></iframe></noscript></div></figure>
    <h2>3. Listen to Your Heart</h2>
    <p>The third remix stretched a power ballad into an extended dance mix that clubs kept playing long after the original had left the charts for good, and it still fills a floor at two in the morning.</p>
    <figure class="wp-block-embed is-provider-youtube"><div class="wp-block-embed__wrapper"><noscript><iframe loading="lazy" title="Remix three" src="https://www.youtube.com/embed/aaaaaaaaaa3?feature=oembed"></iframe></noscript></div></figure>
    <h2>4. Touch It</h2>
    <p>The fourth remix stacked guest verses onto a single until the remix outgrew the song it came from and became the version everyone remembers, while the original quietly disappeared from the playlists.</p>
    <figure class="wp-block-embed is-provider-youtube"><div class="wp-block-embed__wrapper"><noscript><iframe loading="lazy" title="Remix four" src="https://www.youtube.com/embed/aaaaaaaaaa4?feature=oembed"></iframe></noscript></div></figure>
  </article>
  <aside><h3>More music</h3><iframe src="https://www.youtube.com/embed/sidebarvid1"></iframe></aside>
  <footer>© 2026</footer>
</body></html>
```

- [ ] **Step 2: Write the corpus test**

Append to `HostAgnosticDiscoveryTest`:

```php
    /** vice 495401: JSON-LD declares one of four videos; the other three exist only as page embeds inside <noscript>. */
    public function testAPageThatDeclaresOneOfFourVideosYieldsAllFourInPageOrder(): void
    {
        $media = $this->scanner()->scan(
            $this->fixture('multi-embed-page.html'),
            'https://www.vice.com/en/article/4-remixes-from-the-2000s/',
        );

        $urls = array_map(static fn ($c): string => $c->url, $media->candidates);
        self::assertSame([
            'https://www.youtube-nocookie.com/embed/aaaaaaaaaa1',
            'https://www.youtube-nocookie.com/embed/aaaaaaaaaa2',
            'https://www.youtube-nocookie.com/embed/aaaaaaaaaa3',
            'https://www.youtube-nocookie.com/embed/aaaaaaaaaa4',
        ], $urls, 'four unique players in page order, the sidebar teaser excluded');
        foreach ($media->candidates as $candidate) {
            self::assertNotNull($candidate->precedingText, 'every player knows the section it follows');
        }
    }
```

- [ ] **Step 3: Write the end-to-end test**

In `ArticleExtractorTest`, change `mediaScanner()` so the JSON-LD source can resolve YouTube and the page-embed source is present:

```php
    private function mediaScanner(): PageMediaScanner
    {
        $youTube = new EmbedProviders([new YouTubeEmbedProvider()]);
        $urlKind = new MediaUrlKind(new DurableMediaUrl(), $youTube);

        return new PageMediaScanner([
            new JsonLdMediaSource($urlKind, $youTube),
            new PageEmbedSource($youTube),
            new AttributeMediaSource($urlKind, new MediaRelevance()),
        ]);
    }
```

Add `use App\Service\Reader\Media\Source\PageEmbedSource;` to the imports. Append the test:

```php
    public function testRecoversEveryEmbedThePageCarriesEachUnderItsOwnSection(): void
    {
        $result = $this->extractFixture('media/multi-embed-page.html');

        self::assertTrue($result->ok);
        $html = (string) $result->contentHtml;
        preg_match_all('#youtube-nocookie\.com/embed/(aaaaaaaaaa\d)#', $html, $players);
        self::assertSame(['aaaaaaaaaa1', 'aaaaaaaaaa2', 'aaaaaaaaaa3', 'aaaaaaaaaa4'], $players[1]);
        // Under its own section, not above the lead: the first player follows the first section's prose.
        self::assertGreaterThan((int) strpos($html, 'The first remix took'), (int) strpos($html, 'aaaaaaaaaa1'));
        self::assertLessThan((int) strpos($html, 'The second remix dragged'), (int) strpos($html, 'aaaaaaaaaa1'));
    }
```

- [ ] **Step 4: Run the tests**

Run: `php bin/phpunit tests/Service/Reader/Media/HostAgnosticDiscoveryTest.php tests/Service/Reader/ArticleExtractorTest.php`
Expected: green. If the end-to-end test finds fewer than four players, print `$result->contentHtml` in the failure and report as DONE_WITH_CONCERNS with the body — do not loosen the assertions.

To see the fixture fail on the old rule (the RED for this task), run the same two tests with Task 1's scanner change stashed: `git stash push src/Service/Reader/Media/PageMediaScanner.php && php bin/phpunit tests/Service/Reader/Media/HostAgnosticDiscoveryTest.php --filter DeclaresOneOfFour; git stash pop`. Expected: the corpus test fails with one URL. Record the output in the report.

Then the whole suite: `php bin/phpunit`
Expected: green.

- [ ] **Step 5: Lint**

Run: `composer cs && composer stan`
Expected: clean (tests are PHPStan-checked too).

- [ ] **Step 6: Commit**

```bash
git add tests/Fixtures/reader/media/multi-embed-page.html tests/Service/Reader/Media/HostAgnosticDiscoveryTest.php tests/Service/Reader/ArticleExtractorTest.php
git commit -m "test(#788): a page with one declared and four embedded videos yields all four under their sections"
```

---

### Task 3: Reader cache version

**Files:**
- Modify: `frontend/src/app/reader/reader-cache.service.ts:35-36`

- [ ] **Step 1: Bump the version**

After the `// v11:` comment lines, add:

```ts
  // v12: v11 records were extracted while a declared embed hid the page's
  // other embeds (#788); an already-read article would keep one player where
  // the page has several.
  private static readonly VERSION = 12;
```

(replacing `private static readonly VERSION = 11;`).

- [ ] **Step 2: Run the cache tests and the check**

Run from the repo root: `docker compose exec -T frontend npx jest src/app/reader/reader-cache`
Expected: green.
Run: `docker compose exec -T frontend npm run check`
Expected: green.

- [ ] **Step 3: Commit**

```bash
git add frontend/src/app/reader/reader-cache.service.ts
git commit -m "fix(#788): bump the reader cache version so single-player bodies are refetched"
```

---

### Task 4: Verification (controller-run)

- [ ] **Step 1: Backend gates**

From `backend/`: `composer check && composer md && php bin/phpunit && composer infection:diff`
Expected: green; MSI on the changed files at or above `minMsi`.

- [ ] **Step 2: MySQL leg**

From the repo root: `docker compose exec -T php vendor/bin/phpunit tests/Service/Reader`
Expected: green.

- [ ] **Step 3: Live check of entry 495401**

Open `http://localhost:4200/?subscription=1071&entry=495401-4-remixes-from-the-2000s-that-were-somehow-better-than-the-originals` in the browser, reload the article (the reader's "Reload article" button, so the cached body is replaced), and count the players: four `.reader-embed` links, one under each remix section, none above the lead. Screenshot as evidence.
