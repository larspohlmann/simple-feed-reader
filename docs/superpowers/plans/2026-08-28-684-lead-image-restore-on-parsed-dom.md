# Reader Lead-Image Restore on the Parsed Document — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make `ReaderLeadImage` restore the lead photo by mutating the shared parsed body document in place, fed by a page-image inventory resolved once from the normalised page, so the extraction pipeline never re-parses HTML strings for the lead restore.

**Architecture:** A new `PageImageInventory` value object holds the images the normalised page draws, as `ImageIdentity` fingerprints, built once by `ArticleExtractor` before Readability consumes the document. A new `LeadImageCandidate` DTO groups the lead URL with that inventory. `ReaderLeadImage::restore` becomes an in-place `\Dom\HTMLDocument` mutation and runs as the third step inside `ReaderBodyCleaner`'s single parse-once/serialise-once window, alongside `LeadingTitleRemover` and `EdgeBoilerplateTrimmer`. Behaviour is unchanged.

**Tech Stack:** PHP 8.4, Symfony 7.4, `\Dom\HTMLDocument` (lexbor), PHPUnit, fivefilters/readability.

**Spec:** [docs/superpowers/specs/2026-08-28-684-lead-image-restore-on-parsed-dom-design.md](../specs/2026-08-28-684-lead-image-restore-on-parsed-dom-design.md)

## Global Constraints

- `declare(strict_types=1);` in every PHP file.
- Clean Code is mandatory: names reveal intent, functions do one thing, no boolean flag parameters, guard clauses over nesting, immutability by default (`final readonly class` with constructor promotion), depend on interfaces, errors are typed exceptions never `null`-as-signal.
- Every `src` file touched must be PHPMD-clean (`composer md`) and PHPStan-level-max clean (`composer stan`, needs a warm cache: `bin/console cache:warmup`).
- Controllers are out of scope; no controller changes.
- Tests are production code: same naming, structure, and standards.
- TDD: write the failing test first, watch it fail, implement, watch it pass, commit.
- Commit message format: `type(#684): summary`.
- Run PHPUnit natively (SQLite) with `php bin/phpunit`. Parallel runs need `TEST_TOKEN` — not needed for the single-process runs in this plan.
- All backend commands run from `backend/`.
- Behaviour must stay identical. The three `ArticleExtractor` lead-image integration tests are the primary behaviour guard and must pass with only their construction wiring changed.

---

## File Structure

| File | Change | Responsibility |
|---|---|---|
| `backend/src/Service/Reader/PageImageInventory.php` | Create | The set of images the normalised page draws, as `ImageIdentity`; answers `draws()`. |
| `backend/src/Service/Reader/LeadImageCandidate.php` | Create | Groups the lead URL and the page inventory into one value object. |
| `backend/src/Service/Reader/ReaderLeadImage.php` | Rewrite | In-place body-DOM mutation; no re-parse, no lazy-source digging. |
| `backend/src/Service/Reader/ReaderBodyCleaner.php` | Modify | Runs the restore as the third in-place step; takes a `LeadImageCandidate`. |
| `backend/src/Service/Reader/ArticleExtractor.php` | Modify | Builds the inventory before Readability; passes the candidate to the cleaner; drops its `ReaderLeadImage` dependency. |
| `backend/tests/Service/Reader/PageImageInventoryTest.php` | Create | Unit tests for the inventory. |
| `backend/tests/Service/Reader/ReaderLeadImageTest.php` | Rewrite | The #681 scenarios on the new API. |
| `backend/tests/Service/Reader/ReaderBodyCleanerTest.php` | Modify | Constructor wiring, `clean()` third argument, one restore-in-window case. |
| `backend/tests/Service/Reader/ArticleExtractorTest.php` | Modify | Construction wiring only. |

---

## Task 1: `PageImageInventory` value object

**Files:**
- Create: `backend/src/Service/Reader/PageImageInventory.php`
- Test: `backend/tests/Service/Reader/PageImageInventoryTest.php`

**Interfaces:**
- Consumes: `App\Service\Reader\ImageIdentity` (`::fromUrl(string): self`, `->matches(self): bool`), `App\Service\Html\Srcset` (`::firstUrl(?string): ?string`), `App\Service\Html\HtmlDocumentParser` (`::parseOrNull(string): ?\Dom\HTMLDocument`).
- Produces: `PageImageInventory::fromDocument(?\Dom\HTMLDocument $page): self` and `->draws(ImageIdentity $lead): bool`. Later tasks rely on both.

- [ ] **Step 1: Write the failing test**

Create `backend/tests/Service/Reader/PageImageInventoryTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Service\Reader;

use App\Service\Html\HtmlDocumentParser;
use App\Service\Reader\ImageIdentity;
use App\Service\Reader\PageImageInventory;
use PHPUnit\Framework\TestCase;

final class PageImageInventoryTest extends TestCase
{
    private function inventoryOf(string $html): PageImageInventory
    {
        return PageImageInventory::fromDocument(HtmlDocumentParser::parseOrNull($html));
    }

    public function testDrawsAPlainImageSource(): void
    {
        $inventory = $this->inventoryOf('<body><img src="https://cdn.test/hero-photo.jpg"></body>');

        self::assertTrue($inventory->draws(ImageIdentity::fromUrl('https://cdn.test/hero-photo.jpg')));
    }

    public function testDrawsASizeVariantOfTheSamePhoto(): void
    {
        // ImageIdentity matches renditions of one photo by filename stem.
        $inventory = $this->inventoryOf('<body><img src="https://cdn.test/hero-photo-1280x720.jpg"></body>');

        self::assertTrue($inventory->draws(ImageIdentity::fromUrl('https://cdn.test/hero-photo.jpg')));
    }

    public function testDoesNotDrawAnUnrelatedPhoto(): void
    {
        $inventory = $this->inventoryOf('<body><img src="https://cdn.test/gallery-shot.jpg"></body>');

        self::assertFalse($inventory->draws(ImageIdentity::fromUrl('https://cdn.test/hero-photo.jpg')));
    }

    public function testDrawsTheFirstSrcsetCandidateOfASource(): void
    {
        $html = '<body><picture><source srcset="https://cdn.test/hero-photo.jpg 1x, https://cdn.test/hero-2x.jpg 2x">'
            . '<img></picture></body>';
        $inventory = $this->inventoryOf($html);

        self::assertTrue($inventory->draws(ImageIdentity::fromUrl('https://cdn.test/hero-photo.jpg')));
    }

    public function testIgnoresAnImageWithAnEmptySource(): void
    {
        $inventory = $this->inventoryOf('<body><img src=""><img src="https://cdn.test/hero-photo.jpg"></body>');

        self::assertFalse($inventory->draws(ImageIdentity::fromUrl('https://cdn.test/other.jpg')));
        self::assertTrue($inventory->draws(ImageIdentity::fromUrl('https://cdn.test/hero-photo.jpg')));
    }

    public function testANullDocumentDrawsNothing(): void
    {
        $inventory = PageImageInventory::fromDocument(null);

        self::assertFalse($inventory->draws(ImageIdentity::fromUrl('https://cdn.test/hero-photo.jpg')));
    }

    public function testADocumentWithNoImagesDrawsNothing(): void
    {
        $inventory = $this->inventoryOf('<body><p>Just words.</p></body>');

        self::assertFalse($inventory->draws(ImageIdentity::fromUrl('https://cdn.test/hero-photo.jpg')));
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `php bin/phpunit tests/Service/Reader/PageImageInventoryTest.php`
Expected: FAIL — `Class "App\Service\Reader\PageImageInventory" not found`.

- [ ] **Step 3: Write the implementation**

Create `backend/src/Service/Reader/PageImageInventory.php`:

```php
<?php

declare(strict_types=1);

namespace App\Service\Reader;

use App\Service\Html\Srcset;
use Dom\HTMLDocument;

/**
 * The set of images a normalised page draws, as light ImageIdentity
 * fingerprints, built once from the FetchedPageNormalizer document. There
 * LazyImageSources has already promoted every lazy source to a plain `src` and
 * flattened each <picture> to its <img>, so the scan is a plain `img@src` +
 * `source@srcset` read — no `data-*` digging, which LazyImageSources owns (#684).
 *
 * It answers one question for ReaderLeadImage: does the page actually draw the
 * lead photo, or is the og:image a meta-only share-render? A miss only skips the
 * restore, so a fingerprint the scan does not carry is safe by design.
 */
final readonly class PageImageInventory
{
    /** @param list<ImageIdentity> $drawn */
    private function __construct(private array $drawn)
    {
    }

    public static function fromDocument(?HTMLDocument $page): self
    {
        if ($page === null) {
            return new self([]);
        }

        $drawn = [];
        foreach (self::renderedUrls($page) as $url) {
            $drawn[] = ImageIdentity::fromUrl($url);
        }

        return new self($drawn);
    }

    public function draws(ImageIdentity $lead): bool
    {
        foreach ($this->drawn as $identity) {
            if ($lead->matches($identity)) {
                return true;
            }
        }

        return false;
    }

    /** @return \Generator<string> every URL the page draws, in document order */
    private static function renderedUrls(HTMLDocument $page): \Generator
    {
        foreach ($page->getElementsByTagName('img') as $image) {
            $source = trim($image->getAttribute('src') ?? '');
            if ($source !== '') {
                yield $source;
            }
        }
        foreach ($page->getElementsByTagName('source') as $source) {
            $first = Srcset::firstUrl($source->getAttribute('srcset'));
            if ($first !== null) {
                yield $first;
            }
        }
    }
}
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `php bin/phpunit tests/Service/Reader/PageImageInventoryTest.php`
Expected: PASS (7 tests).

- [ ] **Step 5: Commit**

```bash
git add backend/src/Service/Reader/PageImageInventory.php backend/tests/Service/Reader/PageImageInventoryTest.php
git commit -m "feat(#684): add PageImageInventory for the reader lead-image restore"
```

---

## Task 2: Restore on the parsed document (coordinated refactor)

This is one atomic change: `ReaderLeadImage::restore` changes signature, so its only production caller (`ArticleExtractor`) and its new invoker (`ReaderBodyCleaner`) must change in the same commit. There is no green intermediate state that changes the signature without updating both. The suite (including `composer stan`) must be green at the end of this task.

**Files:**
- Create: `backend/src/Service/Reader/LeadImageCandidate.php`
- Rewrite: `backend/src/Service/Reader/ReaderLeadImage.php`
- Modify: `backend/src/Service/Reader/ReaderBodyCleaner.php`
- Modify: `backend/src/Service/Reader/ArticleExtractor.php`
- Rewrite: `backend/tests/Service/Reader/ReaderLeadImageTest.php`
- Modify: `backend/tests/Service/Reader/ReaderBodyCleanerTest.php`
- Modify: `backend/tests/Service/Reader/ArticleExtractorTest.php`

**Interfaces:**
- Consumes: `PageImageInventory` (Task 1), `ImageIdentity`, `App\Service\Html\HtmlDocumentParser`, `Dom\HTMLDocument`, `Dom\Element`.
- Produces:
  - `LeadImageCandidate::__construct(?string $url, PageImageInventory $pageImages)` with public readonly `$url` and `$pageImages`.
  - `ReaderLeadImage::restore(\Dom\HTMLDocument $document, LeadImageCandidate $lead): void`.
  - `ReaderBodyCleaner::__construct(LeadingTitleRemover, EdgeBoilerplateTrimmer, ReaderLeadImage)` and `->clean(string $contentHtml, list<string|null> $titleCandidates, LeadImageCandidate $leadImage): string`.
  - `ArticleExtractor::__construct(HtmlPageFetcher, FetchedPageNormalizer, ReaderBodyCleaner, EntrySanitizer)` — no `ReaderLeadImage`.

- [ ] **Step 1: Create the `LeadImageCandidate` DTO**

Create `backend/src/Service/Reader/LeadImageCandidate.php`:

```php
<?php

declare(strict_types=1);

namespace App\Service\Reader;

/**
 * The lead image ReaderLeadImage may restore, with the evidence to decide it:
 * the og:image URL readability reported (null or non-http when there is none to
 * restore), and the inventory of images the page actually draws. Grouped so
 * ReaderBodyCleaner::clean carries one lead parameter, not two.
 */
final readonly class LeadImageCandidate
{
    public function __construct(
        public ?string $url,
        public PageImageInventory $pageImages,
    ) {
    }
}
```

- [ ] **Step 2: Rewrite `ReaderLeadImageTest` to the new API (the failing test)**

Replace the entire contents of `backend/tests/Service/Reader/ReaderLeadImageTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Service\Reader;

use App\Service\Html\HtmlDocumentParser;
use App\Service\Reader\LazyImageSources;
use App\Service\Reader\LeadImageCandidate;
use App\Service\Reader\PageImageInventory;
use App\Service\Reader\ReaderLeadImage;
use PHPUnit\Framework\TestCase;

final class ReaderLeadImageTest extends TestCase
{
    private ReaderLeadImage $leadImage;

    protected function setUp(): void
    {
        $this->leadImage = new ReaderLeadImage();
    }

    /** An inventory of a page that draws exactly these plain image URLs. */
    private function pageDrawing(string ...$urls): PageImageInventory
    {
        $images = '';
        foreach ($urls as $url) {
            $images .= '<img src="' . $url . '">';
        }

        return PageImageInventory::fromDocument(HtmlDocumentParser::parseOrNull('<body>' . $images . '</body>'));
    }

    private function pageDrawingNothing(): PageImageInventory
    {
        return PageImageInventory::fromDocument(null);
    }

    /** The inventory of a raw page after LazyImageSources has resolved it. */
    private function inventoryOfResolvedPage(string $pageHtml): PageImageInventory
    {
        $document = HtmlDocumentParser::parseOrNull($pageHtml);
        self::assertNotNull($document);
        (new LazyImageSources())->resolveIn($document);

        return PageImageInventory::fromDocument($document);
    }

    /** Run restore in place and return the serialised body markup. */
    private function restoredBody(string $bodyHtml, PageImageInventory $pageImages, ?string $leadUrl): string
    {
        $document = HtmlDocumentParser::parseOrNull($bodyHtml);
        self::assertNotNull($document);
        $this->leadImage->restore($document, new LeadImageCandidate($leadUrl, $pageImages));

        return (string) $document->body?->innerHTML;
    }

    /** The body markup as the parser round-trips it, with no restore applied. */
    private function unchangedBody(string $bodyHtml): string
    {
        return (string) HtmlDocumentParser::parseOrNull($bodyHtml)?->body?->innerHTML;
    }

    public function testPrependsTheLeadWhenTheBodyBuriesADifferentImage(): void
    {
        // mopo: readability dropped the header photo and kept a different photo
        // deep in the body. The lead belongs back at the top.
        $lead = 'https://cdn.test/hero-photo.jpg';
        $body = '<p>Anzeige</p><p>Story.</p><figure><img src="https://cdn.test/gallery-shot.jpg" alt=""></figure>';

        $result = $this->restoredBody($body, $this->pageDrawing($lead), $lead);

        self::assertStringContainsString('hero-photo.jpg', $result);
        self::assertStringContainsString('gallery-shot.jpg', $result);
        self::assertLessThan(
            strpos($result, 'gallery-shot.jpg'),
            strpos($result, 'hero-photo.jpg'),
            'the lead must lead the body',
        );
    }

    public function testPrependsTheLeadWhenTheBodyHasNoImage(): void
    {
        $lead = 'https://cdn.test/hero-photo.jpg';

        $result = $this->restoredBody('<p>Just words.</p>', $this->pageDrawing($lead), $lead);

        self::assertStringContainsString('hero-photo.jpg', $result);
    }

    public function testRestoresAMetaOnlyLeadIntoAnImagelessBody(): void
    {
        // A text-only article whose lead lives only in the og:meta: the body has
        // no picture to duplicate, so the lead still leads (the old hero behaviour).
        $lead = 'https://cdn.test/hero-photo.jpg';

        $result = $this->restoredBody('<p>Just words.</p>', $this->pageDrawingNothing(), $lead);

        self::assertStringContainsString('hero-photo.jpg', $result);
    }

    public function testLeavesTheBodyWhenItAlreadyShowsTheLead(): void
    {
        // readability kept the lead in the body; re-adding it would stack the photo.
        $lead = 'https://cdn.test/hero-photo.jpg';
        $body = '<p>Intro.</p><figure><img src="https://cdn.test/hero-photo.jpg" alt=""></figure>';

        self::assertSame(
            $this->unchangedBody($body),
            $this->restoredBody($body, $this->pageDrawing($lead), $lead),
        );
    }

    public function testLeavesTheBodyWhenItOpensWithAnImage(): void
    {
        // The body already leads with a picture; the safe choice is to add nothing
        // even when identity cannot confirm it is the same photo.
        $lead = 'https://cdn.test/hero-photo.jpg';
        $body = '<figure><img src="https://cdn.test/some-other.jpg" alt=""></figure><p>Intro.</p>';

        self::assertSame(
            $this->unchangedBody($body),
            $this->restoredBody($body, $this->pageDrawing($lead), $lead),
        );
    }

    public function testLeavesTheBodyWhenTheLeadIsNotDrawnOnThePage(): void
    {
        // beat.de: the og:image is a meta-only share-render, never drawn in the
        // article. It must not be injected — the body already shows the real photo.
        $lead = 'https://cdn.test/share-render.jpg';
        $body = '<p>Intro.</p><figure><img src="https://cdn.test/real-upload.jpg" alt=""></figure>';

        self::assertSame(
            $this->unchangedBody($body),
            $this->restoredBody($body, $this->pageDrawing('https://cdn.test/real-upload.jpg'), $lead),
        );
    }

    public function testIgnoresANonHttpLead(): void
    {
        $body = '<p>Just words.</p>';

        self::assertSame(
            $this->unchangedBody($body),
            $this->restoredBody($body, $this->pageDrawingNothing(), 'javascript:alert(1)'),
        );
        self::assertSame(
            $this->unchangedBody($body),
            $this->restoredBody($body, $this->pageDrawingNothing(), null),
        );
    }

    public function testDrawsALazyLoadedLeadOnceLazyImageSourcesResolvedIt(): void
    {
        // The page ships the real URL on data-src behind a data: placeholder.
        // Digging it out is LazyImageSources' job now; the inventory then reads
        // the resolved src, so the drawn-on-page gate opens for the lead against
        // a body that carries a different picture.
        $lead = 'https://cdn.test/hero-photo.jpg';
        $page = '<html><body><img src="data:image/gif;base64,AAAA" data-src="' . $lead . '">'
            . '<p>Body.</p></body></html>';
        $body = '<p>Text.</p><figure><img src="https://cdn.test/other.jpg" alt=""></figure>';

        $result = $this->restoredBody($body, $this->inventoryOfResolvedPage($page), $lead);

        self::assertStringContainsString('hero-photo.jpg', $result);
    }
}
```

- [ ] **Step 3: Run the test to verify it fails**

Run: `php bin/phpunit tests/Service/Reader/ReaderLeadImageTest.php`
Expected: FATAL/FAIL — `restore()` still has the old `(string, string, ?string)` signature; the `LazyImageSources`/`PageImageInventory`/`LeadImageCandidate` API mismatch stops the run.

- [ ] **Step 4: Rewrite `ReaderLeadImage`**

Replace the entire contents of `backend/src/Service/Reader/ReaderLeadImage.php`:

```php
<?php

declare(strict_types=1);

namespace App\Service\Reader;

use Dom\Element;
use Dom\HTMLDocument;

/**
 * Puts the article's lead photo back at the top of the extracted body when
 * readability dropped it.
 *
 * Readability strips a page-header image as chrome (mopo's `<figure
 * class="headerImage">`), then reports it separately as the og:image. The reader
 * used to re-add it as a floating "hero", suppressed whenever the body held any
 * image (#657) — which lost the lead on every article that also carries a second
 * picture. This restores the lead into the body itself instead.
 *
 * It mutates the shared \Dom\HTMLDocument in place, like LeadingTitleRemover and
 * EdgeBoilerplateTrimmer, so ReaderBodyCleaner parses and serialises once around
 * it — the lead restore never re-parses the body or the page (#684).
 *
 * The lead is left out only to avoid stacking a picture the body already shows.
 * So it is added whenever the body has no image at all, and otherwise only when:
 *
 *   - the body does not OPEN with an image (it already leads with a picture);
 *   - the body does not already SHOW that photo (readability kept it); and
 *   - the lead is actually DRAWN on the fetched page — not a meta-only
 *     share-render (beat.de's opengraph file lives in the <meta> alone), which
 *     against a body that has its own image would only duplicate it.
 *
 * "Drawn on the page" is answered by the PageImageInventory the caller built once
 * from the normalised page document, where LazyImageSources has already resolved
 * every lazy source — so this class no longer digs `data-*` attributes itself.
 *
 * Same-photo identity is the light ImageIdentity fingerprint, not the per-CDN
 * URL normalisation #657 deleted: a missed match simply skips the restore, so
 * the worst case is today's behaviour and never a duplicated photo. Measured
 * over 120 articles from 20 feeds (#681): fixes 54, duplicates none. A purely
 * positional rule (no identity) was measured to duplicate 45 of the 120.
 */
final readonly class ReaderLeadImage
{
    public function restore(HTMLDocument $document, LeadImageCandidate $lead): void
    {
        $leadUrl = $lead->url;
        if ($leadUrl === null || preg_match('#^https?://#i', $leadUrl) !== 1) {
            return;
        }

        $body = $document->body;
        if ($body === null) {
            return;
        }

        $leadIdentity = ImageIdentity::fromUrl($leadUrl);
        if ($this->opensWithImage($body) || $this->bodyShowsLead($leadIdentity, $body)) {
            return;
        }

        // A body that already carries some picture only takes the lead when the
        // page truly draws it; otherwise a meta-only share-render would double up.
        // A body with no picture has nothing to duplicate, so the lead goes in.
        if ($this->bodyHasImage($body) && !$lead->pageImages->draws($leadIdentity)) {
            return;
        }

        $body->insertBefore($this->figure($document, $leadUrl), $body->firstChild);
    }

    private function bodyHasImage(Element $body): bool
    {
        return $body->getElementsByTagName('img')->length > 0;
    }

    private function bodyShowsLead(ImageIdentity $lead, Element $body): bool
    {
        foreach ($body->getElementsByTagName('img') as $image) {
            $source = $image->getAttribute('src') ?? '';
            if ($source !== '' && $lead->matches(ImageIdentity::fromUrl($source))) {
                return true;
            }
        }

        return false;
    }

    /** True when the first content in document order is an image, not text. */
    private function opensWithImage(Element $body): bool
    {
        $pending = iterator_to_array($body->childNodes);
        while ($pending !== []) {
            $node = array_shift($pending);
            if ($node instanceof Element && $node->localName === 'img') {
                return true;
            }
            if ($node->nodeType === \XML_TEXT_NODE && trim((string) $node->textContent) !== '') {
                return false;
            }
            if ($node instanceof Element) {
                $pending = array_merge(iterator_to_array($node->childNodes), $pending);
            }
        }

        return false;
    }

    private function figure(HTMLDocument $document, string $leadUrl): Element
    {
        $image = $document->createElement('img');
        $image->setAttribute('src', $leadUrl);
        $image->setAttribute('alt', '');
        $figure = $document->createElement('figure');
        $figure->appendChild($image);

        return $figure;
    }
}
```

- [ ] **Step 5: Run the `ReaderLeadImage` test to verify it passes**

Run: `php bin/phpunit tests/Service/Reader/ReaderLeadImageTest.php`
Expected: PASS (8 tests). `ArticleExtractor` and `ReaderBodyCleaner` do not compile against the new signature yet — that is fixed in the next steps before any commit.

- [ ] **Step 6: Wire the restore into `ReaderBodyCleaner`**

Replace the entire contents of `backend/src/Service/Reader/ReaderBodyCleaner.php`:

```php
<?php

declare(strict_types=1);

namespace App\Service\Reader;

use App\Service\Html\HtmlDocumentParser;

/**
 * Cleans readability's article HTML for the reader view through one shared
 * \Dom\HTMLDocument: parse once, drop the duplicate leading title, trim edge
 * boilerplate and restore the lead image in place, serialise once. This mirrors
 * FetchedPageNormalizer's discipline of never serialising and re-parsing between
 * steps — the two removers and the lead-image restore all mutate the same
 * document, so the body is parsed once instead of four times (#586, #684).
 *
 * The result is handed on to EntrySanitizer, the XSS boundary, which stays
 * string-in/string-out because Symfony's HtmlSanitizer operates on strings, not
 * a shared DOM. So the shared-document window ends here, with one serialise.
 *
 * A body too broken to parse is returned unchanged: readability output is
 * always parseable in practice, but a degenerate one falls through rather than
 * crashing the pass.
 */
final readonly class ReaderBodyCleaner
{
    public function __construct(
        private LeadingTitleRemover $titleRemover,
        private EdgeBoilerplateTrimmer $boilerplateTrimmer,
        private ReaderLeadImage $leadImage,
    ) {
    }

    /** @param list<string|null> $titleCandidates */
    public function clean(string $contentHtml, array $titleCandidates, LeadImageCandidate $leadImage): string
    {
        $document = HtmlDocumentParser::parseOrNull($contentHtml);
        if ($document === null) {
            return $contentHtml;
        }

        $this->titleRemover->removeFrom($document, $titleCandidates);
        $this->boilerplateTrimmer->trimIn($document);
        $this->leadImage->restore($document, $leadImage);

        return $document->saveHtml();
    }
}
```

- [ ] **Step 7: Rewire `ArticleExtractor`**

In `backend/src/Service/Reader/ArticleExtractor.php`:

Update the class docblock's pipeline and lead-image sentences to:

```php
/**
 * Turns an article URL into clean, sanitized, distraction-free HTML:
 * fetch (SSRF-guarded) → page normalization → readability extraction → body
 * cleaning (duplicate-title removal, edge-boilerplate trimming, lead-image
 * restore) → EntrySanitizer (the same XSS barrier feed HTML crosses). Never
 * throws for an ordinary failure — returns a `failed` ExtractionResult with a
 * machine reason so the endpoint stays 200 and the client can fall back to feed
 * content.
 *
 * Readability strips a page-header image as chrome and reports it apart as the
 * og:image. ReaderBodyCleaner restores that picture into the extracted body via
 * ReaderLeadImage, when the page draws it and the body does not already show it
 * (#681). The "does the page draw it?" answer is a PageImageInventory this class
 * builds once from the normalised page document, before readability consumes it
 * (#684).
 */
```

Change the constructor to drop `ReaderLeadImage`:

```php
    public function __construct(
        private readonly HtmlPageFetcher $fetcher,
        private readonly FetchedPageNormalizer $normalizer,
        private readonly ReaderBodyCleaner $bodyCleaner,
        private readonly EntrySanitizer $sanitizer,
    ) {
    }
```

Replace the body of `extract()` from the fetch line through the `$body`/`$clean` block:

```php
    public function extract(string $url, ?string $entryTitle = null): ExtractionResult
    {
        try {
            $page = $this->fetcher->fetch($url);
        } catch (PageFetchException) {
            return ExtractionResult::failed($url, 'fetch');
        }

        $normalized = $this->normalizer->normalize($page->html);
        $pageImages = PageImageInventory::fromDocument($normalized);

        $article = $this->richestArticle($normalized, $page);
        if ($article === null) {
            return ExtractionResult::failed($url, 'unextractable');
        }

        if ($article->content === null || !$article->hasContent()) {
            return ExtractionResult::failed($url, 'empty');
        }
        if (mb_strlen(trim((string) $article->textContent)) < self::MIN_CONTENT_LENGTH) {
            return ExtractionResult::failed($url, 'empty');
        }

        $leadImage = new LeadImageCandidate($article->image, $pageImages);
        $body = $this->bodyCleaner->clean($article->content, [$article->title, $entryTitle], $leadImage);
        $clean = $this->sanitizer->sanitize($body);
        if ($clean === null) {
            return ExtractionResult::failed($url, 'empty');
        }

        return ExtractionResult::ok(
            url: $page->finalUrl,
            title: $article->title,
            byline: $article->byline,
            siteName: $article->siteName,
            contentHtml: $clean,
            excerpt: $article->excerpt,
        );
    }
```

Replace `richestArticle()`'s docblock and signature so it takes the pre-built normalised document:

```php
    /**
     * Keep the richer of two extractions of the page: the passed score-neutral
     * document (repairs only) and the wrapper-chain-collapsed variant (#235). The
     * collapse rescues block-component pages (#235) and breaks some
     * well-structured ones (#476); the longer body is the better one in both
     * directions. collapseWrapperChains() returns null when there is no chain to
     * collapse, so the second extraction is skipped.
     *
     * The conservative document is passed in already normalised because the
     * caller reads its image inventory before readability consumes (mutates) it
     * (#684).
     */
    private function richestArticle(?HTMLDocument $normalized, PageResponse $page): ?Article
    {
        $conservative = $this->parse($normalized, $page->finalUrl);
        $collapsed = $this->parse($this->normalizer->collapseWrapperChains($page->html), $page->finalUrl);

        return $this->richer($conservative, $collapsed);
    }
```

Leave `parse()`, `richer()`, `textLength()` and `MIN_CONTENT_LENGTH` unchanged. `PageImageInventory` and `LeadImageCandidate` share the namespace, so no `use` line is needed.

- [ ] **Step 8: Update the `ReaderBodyCleaner` test**

In `backend/tests/Service/Reader/ReaderBodyCleanerTest.php`:

Add imports under the existing `use` lines:

```php
use App\Service\Reader\LeadImageCandidate;
use App\Service\Reader\PageImageInventory;
use App\Service\Reader\ReaderLeadImage;
```

Change the `setUp()` construction to inject the restore:

```php
    protected function setUp(): void
    {
        $this->cleaner = new ReaderBodyCleaner(
            new LeadingTitleRemover(),
            new EdgeBoilerplateTrimmer(),
            new ReaderLeadImage(),
        );
    }
```

Add a no-lead helper below `setUp()`:

```php
    private function noLead(): LeadImageCandidate
    {
        return new LeadImageCandidate(null, PageImageInventory::fromDocument(null));
    }
```

Pass `$this->noLead()` as the third argument to every existing `clean()` call in the file (the duplicate-heading case, the trailing-boilerplate case, the both-together case, and the blank-input `assertSame('   ', ...)` case). For example:

```php
        $result = $this->cleaner->clean($content, ['My Article'], $this->noLead());
```

and

```php
        self::assertSame('   ', $this->cleaner->clean('   ', ['My Article'], $this->noLead()));
```

Add one new case proving the restore runs inside the shared window:

```php
    public function testRestoresTheLeadIntoATextOnlyBodyInTheSharedWindow(): void
    {
        $content = '<div><p>' . self::PROSE . '</p></div>';
        $candidate = new LeadImageCandidate(
            'https://cdn.test/hero.jpg',
            PageImageInventory::fromDocument(null),
        );

        $result = $this->cleaner->clean($content, [null], $candidate);

        self::assertStringContainsString('<img src="https://cdn.test/hero.jpg"', $result);
        self::assertStringContainsString('Fliesstext', $result);
    }
```

- [ ] **Step 9: Update the `ArticleExtractor` test construction**

In `backend/tests/Service/Reader/ArticleExtractorTest.php`, both `ArticleExtractor` construction sites currently read:

```php
            new ReaderBodyCleaner(new LeadingTitleRemover(), new EdgeBoilerplateTrimmer()),
            new ReaderLeadImage(),
            new EntrySanitizer(),
```

Replace each with the restore moved into the cleaner:

```php
            new ReaderBodyCleaner(new LeadingTitleRemover(), new EdgeBoilerplateTrimmer(), new ReaderLeadImage()),
            new EntrySanitizer(),
```

Keep the `ReaderLeadImage` and `ReaderBodyCleaner` imports — both are still constructed. The three lead-image assertions and every other test are unchanged.

- [ ] **Step 10: Run the full reader suite to verify green**

Run: `php bin/phpunit tests/Service/Reader/`
Expected: PASS — including the three integration guards `testRestoresTheLeadIntoATextOnlyBody`, `testRestoresADistinctPageHeroAboveTheBodyPhoto`, `testRestoresLazyLoadedImagesInsteadOfLeavingEmptyFrames`.

- [ ] **Step 11: Warm the cache and run PHPStan**

Run: `bin/console cache:warmup && composer stan`
Expected: no errors. If PHPStan reports the removed `ReaderLeadImage` dependency anywhere unexpected, fix the call site named in the message.

- [ ] **Step 12: Commit**

```bash
git add backend/src/Service/Reader/LeadImageCandidate.php \
        backend/src/Service/Reader/ReaderLeadImage.php \
        backend/src/Service/Reader/ReaderBodyCleaner.php \
        backend/src/Service/Reader/ArticleExtractor.php \
        backend/tests/Service/Reader/ReaderLeadImageTest.php \
        backend/tests/Service/Reader/ReaderBodyCleanerTest.php \
        backend/tests/Service/Reader/ArticleExtractorTest.php
git commit -m "refactor(#684): restore the reader lead image on the parsed document"
```

---

## Task 3: Full gates

**Files:** none (verification only).

- [ ] **Step 1: Run the full backend suite natively (SQLite)**

Run: `php bin/phpunit`
Expected: PASS.

- [ ] **Step 2: Run the full backend suite in Docker (MySQL)**

Run: `docker compose exec -T php vendor/bin/phpunit`
Expected: PASS. (Start the stack with `docker compose up -d` first if it is not running.)

- [ ] **Step 3: Run the style, static-analysis, PHPMD and tramp gates**

Run: `composer check && composer md`
Expected: no findings. `composer check` runs `cs`, `stan` and `tramp`. If `tramp` is red, check `composer show larspohlmann/phptramp` (CI runs the tip of its develop branch) before assuming the cause is this change.

- [ ] **Step 4: Run mutation testing over the changed files**

Run: `composer infection:diff`
Expected: meets `minMsi`. Escaped mutants arrive on the offending line. Kill any escaped mutant on the new `PageImageInventory` code (for example the `$source !== ''` guard or the `draws()` early return) with a targeted assertion, then re-run.

- [ ] **Step 5: Run PhpStorm inspections on the changed PHP**

Use `mcp__phpstorm__lint_files` on:
- `backend/src/Service/Reader/PageImageInventory.php`
- `backend/src/Service/Reader/LeadImageCandidate.php`
- `backend/src/Service/Reader/ReaderLeadImage.php`
- `backend/src/Service/Reader/ReaderBodyCleaner.php`
- `backend/src/Service/Reader/ArticleExtractor.php`

Expected: no ERROR or WARNING. Weak warnings are advisory.

- [ ] **Step 6: Scan today's dev log**

Run: `ls -t backend/var/log/dev-*.log | head -1` then read that file.
Expected: no new deprecations or swallowed errors from the reader path.

---

## Self-Review Notes

- **Spec coverage:** Task 1 delivers `PageImageInventory` (spec §Architecture). Task 2 delivers `LeadImageCandidate`, the in-place `ReaderLeadImage::restore`, the `ReaderBodyCleaner` third step, and the `ArticleExtractor` reorder that captures the inventory before Readability (spec §Architecture, §The care point). The behaviour-equivalence claims (spec §Behaviour equivalence) are guarded by the rewritten `ReaderLeadImageTest`, the new lazy-source test, and the three unchanged `ArticleExtractor` integration tests. Task 3 runs every gate the spec's §Testing lists.
- **No behaviour change:** the serialise moves from `body->innerHTML` to `saveHtml()`; `sanitize()` strips the wrapper, so the sanitiser input is byte-identical (verified). `restore` runs last in the window, so it sees the same post-clean body it sees today.
- **Type consistency:** `restore(HTMLDocument, LeadImageCandidate): void`, `LeadImageCandidate(?string $url, PageImageInventory $pageImages)`, `PageImageInventory::fromDocument(?HTMLDocument): self`, `->draws(ImageIdentity): bool`, and `clean(string, list<string|null>, LeadImageCandidate): string` are used identically across tasks and tests.
