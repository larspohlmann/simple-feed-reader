# Reader Chrome Cleaners Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Strip the page chrome that survives the reader's extract-and-clean pipeline — hand-rolled share bars, bare menu lists, repeated headlines, ad labels, and a paywalled Substack player — with generalized, host-agnostic rules (plus one interface-backed Substack one-off).

**Architecture:** Four small cleaners in the existing `Service/Reader` pipeline (three extend a cleaner, one is new and runs in `FetchedPageNormalizer` before readability). A fifth unit is a tagged `GatedMediaPlaceholder` strategy set that `ReaderBodyCleaner` iterates, with one Substack implementation. No API or frontend change: a gated post shows its cleaned teaser inline.

**Tech Stack:** PHP 8.4, Symfony 7.4, `\Dom\HTMLDocument` (lexbor), PHPUnit, Infection. `final readonly class`, constructor promotion, guard clauses.

**Spec:** `docs/superpowers/specs/2026-08-31-627-reader-chrome-cleaners-design.md`

## Global Constraints

- `declare(strict_types=1);` in every PHP file. PSR-12 (`composer cs`).
- PHPStan level max over `src` and `tests` — no new baselines, no unexplained `@phpstan-ignore`. Generics on every collection.
- PHPMD codesize clean on every touched file (`composer md`) — fix the design, not the threshold.
- phptramp clean (`composer tramp`) — a value with no home becomes a field/DTO, never a longer signature.
- Clean Code: names reveal intent, one thing per method, guard clauses over nesting, no boolean flag parameters, comments only for the non-obvious *why* (≤3 lines).
- Comments cap at 3 lines; delete comments that restate the code.
- `\Dom` gotchas: `getAttribute` returns `?string`; `localName` is lower-case, `nodeName` UPPER-case; element-named XPath (`//h1`) does NOT match (XHTML namespace) — use `querySelector`/`getElementsByTagName`.
- Tests are production code: same naming and standards. Each threshold gets a boundary case on each side plus one past it; every `mb_strlen` path gets an umlaut case (Infection minMsi 80).
- Verify the pipeline via `docker compose exec -T php` (MySQL/real subscriptions); the native run is SQLite/test.

---

## File Structure

- `src/Service/Reader/ShareIntentLinkRemover.php` — **new**. Removes hand-rolled share buttons by href signal, before readability.
- `src/Service/Reader/FetchedPageNormalizer.php` — **modify**. Inject and call `ShareIntentLinkRemover` in `repair()`.
- `src/Service/Reader/NavigationChromeTrimmer.php` — **modify**. Add the leading menu-list anchor.
- `src/Service/Reader/LeadingTitleRemover.php` — **modify**. Inspect the first text-bearing block, not just `h1–h3`.
- `src/Service/Reader/EdgeBoilerplateTrimmer.php` — **modify**. Remove the `EDGE_FRACTION` cap; add the standalone ad-label signal.
- `src/Service/Reader/GatedMediaPlaceholderInterface.php` — **new**. The strategy contract.
- `src/Service/Reader/GatedMediaContext.php` — **new**. Readonly DTO: `sourceUrl`, `posterUrl`.
- `src/Service/Reader/SubstackGatedVideoPlaceholder.php` — **new**. The Substack strategy.
- `src/Service/Reader/ReaderBodyCleaner.php` — **modify**. Take a `GatedMediaContext`, iterate the placeholder set.
- `src/Service/Reader/ArticleExtractor.php` — **modify**. Build `GatedMediaContext` and pass it to `clean()`.
- `config/services.yaml` — **modify**. `_instanceof` tag for the placeholder interface.
- Tests mirror each file under `tests/Service/Reader/`.

---

## Task 1: `ShareIntentLinkRemover`

Removes a hand-rolled share button — an `<a>` pointing at a known share endpoint whose query carries this page's own URL. Runs in `FetchedPageNormalizer` before readability, beside `ShareWidgetRemover`. A share link with no page URL (POLITICO's `api.whatsapp.com/send?phone=…&text=Hey Zoya and crew!`) is editorial and stays.

**Files:**
- Create: `backend/src/Service/Reader/ShareIntentLinkRemover.php`
- Test: `backend/tests/Service/Reader/ShareIntentLinkRemoverTest.php`
- Modify: `backend/src/Service/Reader/FetchedPageNormalizer.php`
- Modify: `backend/tests/Service/Reader/FetchedPageNormalizerTest.php`

**Interfaces:**
- Consumes: `App\Service\Html\HtmlDocumentParser::parseOrNull(string): ?\Dom\HTMLDocument`.
- Produces: `ShareIntentLinkRemover::removeFrom(\Dom\HTMLDocument $document): void`.

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace App\Tests\Service\Reader;

use App\Service\Html\HtmlDocumentParser;
use App\Service\Reader\ShareIntentLinkRemover;
use PHPUnit\Framework\TestCase;

final class ShareIntentLinkRemoverTest extends TestCase
{
    private ShareIntentLinkRemover $remover;

    protected function setUp(): void
    {
        $this->remover = new ShareIntentLinkRemover();
    }

    public function testRemovesABlueskyShareIntentCarryingThePageUrl(): void
    {
        $html = '<article><p>Body.</p>'
            . '<a href="https://bsky.app/intent/compose?text=https://canarymedia.com/x">Share</a>'
            . '</article>';

        $result = $this->cleaned($html);

        self::assertStringNotContainsString('bsky.app', $result);
        self::assertStringContainsString('Body.', $result);
    }

    public function testRemovesAMailtoShareCarryingThePageUrlInTheBody(): void
    {
        $html = '<div><a href="mailto:?subject=Poo&amp;body=https://www.nature.com/articles/x">Email</a>'
            . '<p>Body.</p></div>';

        self::assertStringNotContainsString('mailto:', $this->cleaned($html));
    }

    public function testKeepsAWhatsAppContactLinkThatCarriesNoPageUrl(): void
    {
        // POLITICO's write-to-the-hosts link: a share host, but no page URL.
        $html = '<div><p>Body.</p>'
            . '<a href="https://api.whatsapp.com/send/?phone=32491050629&amp;text=Hey+Zoya+and+crew!">Message us</a>'
            . '</div>';

        self::assertStringContainsString('api.whatsapp.com', $this->cleaned($html));
    }

    public function testKeepsAnOrdinaryOutboundLinkToAShareHostDomain(): void
    {
        // A plain link to facebook.com (not a /sharer endpoint) is not a control.
        $html = '<div><p>Body.</p><a href="https://facebook.com/politico">Our page</a></div>';

        self::assertStringContainsString('facebook.com/politico', $this->cleaned($html));
    }

    public function testRemovesTheWholeShareClusterIncludingItsLabel(): void
    {
        $html = '<div><p>Body.</p>'
            . '<div class="bar"><span>Share this article</span>'
            . '<a href="https://www.facebook.com/sharer/sharer.php?u=https://x.test/a">FB</a>'
            . '<a href="https://x.com/intent/tweet?url=https://x.test/a">X</a>'
            . '</div></div>';

        $result = $this->cleaned($html);

        self::assertStringNotContainsString('Share this article', $result);
        self::assertStringNotContainsString('sharer', $result);
        self::assertStringContainsString('Body.', $result);
    }

    public function testKeepsAClusterThatMixesShareButtonsWithRealLinks(): void
    {
        // Not a share-only cluster: it also holds a content link, so it stays.
        $html = '<div><a href="https://x.com/intent/tweet?url=https://x.test/a">X</a>'
            . '<a href="https://x.test/related">Related story</a></div>';

        self::assertStringContainsString('Related story', $this->cleaned($html));
    }

    private function cleaned(string $html): string
    {
        $document = HtmlDocumentParser::parseOrNull($html);
        self::assertNotNull($document);
        $this->remover->removeFrom($document);

        return $document->saveHtml();
    }
}
```

- [ ] **Step 2: Run the test, verify it fails**

Run: `docker compose exec -T php vendor/bin/phpunit --filter ShareIntentLinkRemoverTest`
Expected: FAIL — class `ShareIntentLinkRemover` not found.

- [ ] **Step 3: Write the implementation**

Create `ShareIntentLinkRemover.php`. Rules: `isShareIntent` = `pointsAtAShareEndpoint(href) && carriesTheSharedAddress(href)`. `pointsAtAShareEndpoint` = `mailto:` prefix, or `parse_url` host (minus `www.`) + path starts-with one of `SHARE_ENDPOINTS`. `carriesTheSharedAddress` = query part matches `#https?(://|%3a%2f%2f)#i`. Cluster = climb to the outermost ancestor holding share controls only and ≤ 60 chars of non-link text, stopping at `main`/`article`/`body`. Resolve all clusters before removing (`spl_object_id` dedupe), then remove.

```php
private const array SHARE_ENDPOINTS = [
    'facebook.com/sharer', 'facebook.com/share.php', 'facebook.com/dialog/',
    'x.com/intent', 'twitter.com/intent', 'bsky.app/intent', 'threads.net/intent',
    'linkedin.com/sharearticle', 'linkedin.com/sharing/share-offsite',
    'reddit.com/submit', 'pinterest.com/pin/create', 'tumblr.com/share',
    'tumblr.com/widgets/share', 'vk.com/share.php', 'xing.com/spi/shares/new',
    'getpocket.com/edit', 'getpocket.com/save', 'flipboard.com/bookmarklet/popup',
    'api.whatsapp.com/send', 'wa.me/', 't.me/share', 'telegram.me/share',
];
private const string SHARED_ADDRESS_PATTERN = '#https?(://|%3a%2f%2f)#i';
private const int CLUSTER_LABEL_LENGTH = 60;
private const array CONTENT_BOUNDARIES = ['main', 'article', 'body'];
```

Use the full implementation drafted during design (host-and-path via `parse_url`, `www.` strip, `array_any` over endpoints, `holdsShareControlsOnly` requiring ≥1 link and every link a share intent, `textLengthOutsideLinks` ≤ 60). Keep every method one job; collapse whitespace with `preg_replace('/\s+/u', ' ', ...)`.

- [ ] **Step 4: Run the test, verify it passes**

Run: `docker compose exec -T php vendor/bin/phpunit --filter ShareIntentLinkRemoverTest`
Expected: PASS (7 tests).

- [ ] **Step 5: Wire into `FetchedPageNormalizer`**

Add the constructor dependency and call it in `repair()`, beside `ShareWidgetRemover`:

```php
public function __construct(
    private LazyImageSources $lazyImages,
    private ShareWidgetRemover $shareWidgets,
    private ShareIntentLinkRemover $shareIntentLinks,
) {
}
```

In `repair()`, after `$this->shareWidgets->removeFrom($document);` add
`$this->shareIntentLinks->removeFrom($document);`.

Add one test to `FetchedPageNormalizerTest` proving a `bsky.app/intent` link carrying the page URL is gone after `normalize()`.

- [ ] **Step 6: Run normalizer tests and the gates**

Run: `docker compose exec -T php vendor/bin/phpunit --filter FetchedPageNormalizerTest`
Run: `composer cs && composer stan && composer md`
Expected: PASS / no findings.

- [ ] **Step 7: Commit**

```bash
git add backend/src/Service/Reader/ShareIntentLinkRemover.php backend/src/Service/Reader/FetchedPageNormalizer.php backend/tests/Service/Reader/ShareIntentLinkRemoverTest.php backend/tests/Service/Reader/FetchedPageNormalizerTest.php
git commit -m "feat(#627): remove hand-rolled share buttons by href signal"
```

---

## Task 2: `NavigationChromeTrimmer` — leading menu-list anchor

Anchor removal on a leading menu-shaped link list (Dissent `ul.side-nav`, Democracy Now's topic `ul`), in addition to the existing `<nav>`/role landmark.

**Files:**
- Modify: `backend/src/Service/Reader/NavigationChromeTrimmer.php`
- Modify: `backend/tests/Service/Reader/NavigationChromeTrimmerTest.php`

**Interfaces:**
- Produces: unchanged public API `trimIn(\Dom\HTMLDocument): void`.

- [ ] **Step 1: Write the failing tests**

Add to `NavigationChromeTrimmerTest`:

```php
public function testRemovesALeadingMenuShapedListWithoutALandmark(): void
{
    // Dissent's masthead menu is a bare <ul>, no <nav>/role. Four+ outbound
    // link-only items before the first paragraph = a site menu.
    $menu = '<ul class="side-nav">'
        . '<li><a href="https://d.test/subscribe">Subscribe</a></li>'
        . '<li><a href="https://d.test/magazine">Magazine</a></li>'
        . '<li><a href="https://d.test/online">Online</a></li>'
        . '<li><a href="https://d.test/store">Store</a></li></ul>';
    $html = '<div>' . $menu . '<div><p>' . self::PROSE . '</p></div></div>';

    $result = $this->trimmed($html);

    self::assertStringNotContainsString('side-nav', $result);
    self::assertStringContainsString(self::PROSE, $result);
}

public function testKeepsALeadingListWithFewerThanFourLinks(): void
{
    $menu = '<ul><li><a href="https://d.test/a">A</a></li>'
        . '<li><a href="https://d.test/b">B</a></li>'
        . '<li><a href="https://d.test/c">C</a></li></ul>';
    $html = '<div>' . $menu . '<p>' . self::PROSE . '</p></div>';

    self::assertStringContainsString('href="https://d.test/a"', $this->trimmed($html));
}

public function testKeepsAnInPageTableOfContentsList(): void
{
    // Every item is an in-page (#) link — the article's own affordance.
    $toc = '<ul><li><a href="#one">One</a></li><li><a href="#two">Two</a></li>'
        . '<li><a href="#three">Three</a></li><li><a href="#four">Four</a></li></ul>';
    $html = '<div>' . $toc . '<p>' . self::PROSE . '</p></div>';

    self::assertStringContainsString('#one', $this->trimmed($html));
}

public function testKeepsAMenuShapedListThatFollowsTheFirstParagraph(): void
{
    // After the article started, a link list is "further reading", not chrome.
    $menu = '<ul><li><a href="https://d.test/a">A</a></li>'
        . '<li><a href="https://d.test/b">B</a></li><li><a href="https://d.test/c">C</a></li>'
        . '<li><a href="https://d.test/d">D</a></li></ul>';
    $html = '<div><p>' . self::PROSE . '</p>' . $menu . '</div>';

    self::assertStringContainsString('href="https://d.test/a"', $this->trimmed($html));
}
```

- [ ] **Step 2: Run, verify failure**

Run: `docker compose exec -T php vendor/bin/phpunit --filter NavigationChromeTrimmerTest`
Expected: the four new tests FAIL; the existing ones still PASS.

- [ ] **Step 3: Implement the menu-list anchor**

In `chromeRegions()`, after collecting landmark regions, also collect leading menu lists. Add constants and helpers:

```php
private const int MENU_MIN_LINKS = 4;
private const int SUBSTANTIAL_PROSE_LENGTH = 120;

/** @return list<Element> */
private function leadingMenuLists(HTMLDocument $document): array
{
    $firstProse = $this->firstSubstantialParagraph($document);
    $lists = [];
    foreach ($document->getElementsByTagName('*') as $element) {
        if (!in_array($element->localName, ['ul', 'ol'], true)) {
            continue;
        }
        if ($this->isMenuShaped($element) && $this->precedesInDocument($element, $firstProse)) {
            $lists[] = $element;
        }
    }

    return $lists;
}
```

`isMenuShaped`: not inside `main`/`article` (reuse `sitsInsideArticleBody`); `linkTextRatio($list) >= LINK_TEXT_RATIO` (existing 0.6); every `<a>` leaves the page (`href` non-empty and not starting `#`); ≥ `MENU_MIN_LINKS` such links. `firstSubstantialParagraph`: first element whose collapsed `textContent` ≥ 120 chars and is not link-dominated — walk `getElementsByTagName('p')` and headings, return the first that qualifies, or `null`. `precedesInDocument($list, $prose)`: `null` prose means the whole body precedes nothing → treat list as leading (true); otherwise compare document position via `$prose->compareDocumentPosition($list)` and `Node::DOCUMENT_POSITION_PRECEDING`. Merge landmark regions and menu lists into the `spl_object_id`-keyed set; the existing `outermostLinkDominatedAncestor` climb applies to a menu list too, so its single-purpose wrapper goes with it.

Keep methods short; if `chromeRegions` grows past PHPMD limits, extract `landmarkRegions()` and `menuRegions()` and merge.

- [ ] **Step 4: Run, verify pass**

Run: `docker compose exec -T php vendor/bin/phpunit --filter NavigationChromeTrimmerTest`
Expected: PASS (all, old + new).

- [ ] **Step 5: Gates**

Run: `composer cs && composer stan && composer md && composer tramp`
Expected: no findings.

- [ ] **Step 6: Commit**

```bash
git add backend/src/Service/Reader/NavigationChromeTrimmer.php backend/tests/Service/Reader/NavigationChromeTrimmerTest.php
git commit -m "feat(#627): trim a leading menu-shaped list with no nav landmark"
```

---

## Task 3: `LeadingTitleRemover` — first text-bearing block

Drop the first block — heading **or** paragraph — that repeats the article title (Trancentral `<p><span>title</span></p>`, Nature).

**Files:**
- Modify: `backend/src/Service/Reader/LeadingTitleRemover.php`
- Modify: `backend/tests/Service/Reader/LeadingTitleRemoverTest.php`

**Interfaces:**
- Produces: unchanged `removeFrom(\Dom\HTMLDocument, array $titleCandidates): void`.

- [ ] **Step 1: Write the failing tests**

```php
public function testRemovesALeadingParagraphThatRepeatsTheTitle(): void
{
    $html = '<div><p><span>Chill Space Top Tracks July 2026</span></p>'
        . '<p>Welcome to our July tribute to ambient music.</p></div>';

    $result = $this->removed($html, ['Chill Space Top Tracks July 2026']);

    self::assertStringNotContainsString('Chill Space Top Tracks', $result);
    self::assertStringContainsString('Welcome to our July tribute', $result);
}

public function testKeepsALeadingParagraphThatOnlyMentionsTheTitle(): void
{
    $html = '<div><p>In Chill Space Top Tracks July 2026 we cover ambient music.</p></div>';

    self::assertStringContainsString('we cover ambient music', $this->removed($html, ['Chill Space Top Tracks July 2026']));
}

public function testKeepsAMatchingParagraphThatIsNotTheFirstBlock(): void
{
    $html = '<div><p>Intro sentence that stands first.</p>'
        . '<p>Chill Space Top Tracks July 2026</p></div>';

    self::assertStringContainsString('Chill Space Top Tracks July 2026', $this->removed($html, ['Chill Space Top Tracks July 2026']));
}
```

(Reuse the file's existing `removed()` helper; if absent, add one mirroring `trimmed()` from Task 2.)

- [ ] **Step 2: Run, verify failure**

Run: `docker compose exec -T php vendor/bin/phpunit --filter LeadingTitleRemoverTest`
Expected: the three new tests FAIL; existing heading tests PASS.

- [ ] **Step 3: Implement**

Replace `findFirstHeading()` use with `findFirstTextBlock()`: the first element in document order among `h1,h2,h3,p` whose collapsed `textContent` is non-empty — `$document->querySelector('h1, h2, h3, p')` returns the first in document order, but a `<p>` before an `<h2>` must win, so query `'h1, h2, h3, p'` (CSS `querySelector` returns the first match in document order across the group). Keep `repeatsTitle`/`normalize` unchanged. Only that first block is inspected; remove it when it repeats a candidate.

Update the class docblock: "the first heading" → "the first heading or paragraph". Keep it ≤ 3 lines.

- [ ] **Step 4: Run, verify pass**

Run: `docker compose exec -T php vendor/bin/phpunit --filter LeadingTitleRemoverTest`
Expected: PASS (all).

- [ ] **Step 5: Gates**

Run: `composer cs && composer stan && composer md`
Expected: no findings.

- [ ] **Step 6: Commit**

```bash
git add backend/src/Service/Reader/LeadingTitleRemover.php backend/tests/Service/Reader/LeadingTitleRemoverTest.php
git commit -m "feat(#627): drop a leading paragraph that repeats the title"
```

---

## Task 4: `EdgeBoilerplateTrimmer` — drop the cap, add the ad-label signal

Remove the `EDGE_FRACTION` cap (a proven no-op on structured pages, a dead switch on wrapper fallbacks) and remove a standalone leading ad label (Groove `- Advertisement -`).

**Files:**
- Modify: `backend/src/Service/Reader/EdgeBoilerplateTrimmer.php`
- Modify: `backend/tests/Service/Reader/EdgeBoilerplateTrimmerTest.php`

**Interfaces:**
- Produces: unchanged `trimIn(\Dom\HTMLDocument): void`.

- [ ] **Step 1: Write the failing tests**

```php
public function testRemovesAStandaloneLeadingAdvertisementLabel(): void
{
    $body = '<div><p><span>- Advertisement -</span></p>'
        . '<p>' . self::LONG_PROSE . '</p></div>';

    $result = $this->trimmed($body);

    self::assertStringNotContainsString('Advertisement', $result);
    self::assertStringContainsString(self::LONG_PROSE, $result);
}

public function testRemovesAGermanAnzeigeLabel(): void
{
    $body = '<div><p>Anzeige</p><p>' . self::LONG_PROSE . '</p></div>';

    self::assertStringNotContainsString('Anzeige', $this->trimmed($body));
}

public function testKeepsAParagraphThatMerelyContainsTheWordAdvertisement(): void
{
    $body = '<div><p>The advertisement industry changed in 2026 for many reasons here.</p>'
        . '<p>' . self::LONG_PROSE . '</p></div>';

    self::assertStringContainsString('advertisement industry', $this->trimmed($body));
}

public function testRemovesLeadingBoilerplateOnATwoBlockWrapper(): void
{
    // With the cap gone, a 2-block wrapper's leading link-list + phrase is
    // reachable (floor(0.25 * 2) was 0 before).
    $related = '<div class="related"><h3>Related posts</h3>'
        . '<a href="https://x.test/a">A</a><a href="https://x.test/b">B</a>'
        . '<a href="https://x.test/c">C</a></div>';
    $body = '<div>' . $related . '<p>' . self::LONG_PROSE . '</p></div>';

    self::assertStringNotContainsString('class="related"', $this->trimmed($body));
}
```

Add `private const string LONG_PROSE` (≥ 200 chars of real text) to the test if it lacks one.

- [ ] **Step 2: Run, verify failure**

Run: `docker compose exec -T php vendor/bin/phpunit --filter EdgeBoilerplateTrimmerTest`
Expected: the ad-label and 2-block tests FAIL; existing tests PASS.

- [ ] **Step 3: Remove the cap**

In `edgeBounds()`:

```php
private function edgeBounds(int $count, array $substantial): array
{
    $leadingEnd = $substantial[0];
    $trailingStart = $substantial[array_key_last($substantial)] + 1;

    return [$leadingEnd, $trailingStart];
}
```

`$count` is now unused by `edgeBounds` — drop the parameter and its caller argument, and remove `EDGE_FRACTION`. Update the `edgeIndexes`/`edgeBounds` docblocks (delete the cap sentences).

- [ ] **Step 4: Add the ad-label signal**

Add a standalone removal in `shouldRemove()` — an ad label removes on its own, no corroboration:

```php
private const array AD_LABELS = ['advertisement', 'anzeige', 'werbung', 'sponsored'];

private function isAdLabel(Element $block): bool
{
    $text = mb_strtolower(trim((string) preg_replace('/[\s\-–—|:]+/u', ' ', (string) $block->textContent)));

    return in_array(trim($text), self::AD_LABELS, true);
}
```

In `shouldRemove()`, guard first: `if ($this->isAdLabel($block)) { return true; }` then the existing structural logic.

- [ ] **Step 5: Run, verify pass**

Run: `docker compose exec -T php vendor/bin/phpunit --filter EdgeBoilerplateTrimmerTest`
Expected: PASS (all).

- [ ] **Step 6: Gates**

Run: `composer cs && composer stan && composer md`
Expected: no findings.

- [ ] **Step 7: Commit**

```bash
git add backend/src/Service/Reader/EdgeBoilerplateTrimmer.php backend/tests/Service/Reader/EdgeBoilerplateTrimmerTest.php
git commit -m "feat(#627): trim leading ad labels; drop the dead edge-fraction cap"
```

---

## Task 5: Substack gated-video placeholder

Replace a paywalled Substack post's dead player chrome with a poster-image placeholder linking to the source, keeping the teaser. Interface-backed so a second host generalizes; `ReaderBodyCleaner` iterates the tagged set.

**Files:**
- Create: `backend/src/Service/Reader/GatedMediaPlaceholderInterface.php`
- Create: `backend/src/Service/Reader/GatedMediaContext.php`
- Create: `backend/src/Service/Reader/SubstackGatedVideoPlaceholder.php`
- Create: `backend/tests/Service/Reader/SubstackGatedVideoPlaceholderTest.php`
- Modify: `backend/src/Service/Reader/ReaderBodyCleaner.php`
- Modify: `backend/tests/Service/Reader/ReaderBodyCleanerTest.php`
- Modify: `backend/src/Service/Reader/ArticleExtractor.php`
- Modify: `backend/config/services.yaml`

**Interfaces:**
- Produces:
  - `final readonly class GatedMediaContext { public function __construct(public string $sourceUrl, public ?string $posterUrl) {} }`
  - `interface GatedMediaPlaceholderInterface { public function replaceIn(\Dom\HTMLDocument $body, GatedMediaContext $context): bool; }` — returns true if it acted.
  - `ReaderBodyCleaner::clean(string $contentHtml, array $titleCandidates, LeadImageCandidate $leadImage, GatedMediaContext $gatedMedia): string` — **one new final parameter**.
- Consumes: `ArticleExtractor` passes `new GatedMediaContext($page->finalUrl, $article->image)`.

- [ ] **Step 1: Write the interface and DTO (no test needed — pure contracts)**

```php
// GatedMediaContext.php
final readonly class GatedMediaContext
{
    public function __construct(
        public string $sourceUrl,
        public ?string $posterUrl,
    ) {
    }
}
```

```php
// GatedMediaPlaceholderInterface.php
interface GatedMediaPlaceholderInterface
{
    /** Replace a gated media region with a poster placeholder; true if it acted. */
    public function replaceIn(\Dom\HTMLDocument $body, GatedMediaContext $context): bool;
}
```

- [ ] **Step 2: Write the failing test for the Substack strategy**

```php
<?php

declare(strict_types=1);

namespace App\Tests\Service\Reader;

use App\Service\Html\HtmlDocumentParser;
use App\Service\Reader\GatedMediaContext;
use App\Service\Reader\SubstackGatedVideoPlaceholder;
use PHPUnit\Framework\TestCase;

final class SubstackGatedVideoPlaceholderTest extends TestCase
{
    private SubstackGatedVideoPlaceholder $placeholder;

    protected function setUp(): void
    {
        $this->placeholder = new SubstackGatedVideoPlaceholder();
    }

    public function testReplacesTheGatedPlayerWithAPosterLinkingToTheSource(): void
    {
        $body = '<div class="single-post-container"><article class="podcast-post post shows-post">'
            . '<div class="player"><p>Playback speed</p><p>Share post</p><p>0:00</p><p>Preview</p></div>'
            . '<p>An ancient intuition is that plants have souls and participate in life.</p>'
            . '<div role="region" aria-label="Paywall"><h2>Continue reading this post for free.</h2></div>'
            . '</article></div>';
        $context = new GatedMediaContext(
            'https://rupertsheldrake.substack.com/p/the-souls-of-plants',
            'https://substackcdn.com/image/og.jpg',
        );

        $acted = $this->apply($body, $context, $result);

        self::assertTrue($acted);
        self::assertStringNotContainsString('Playback speed', $result);
        self::assertStringContainsString('substackcdn.com/image/og.jpg', $result);
        self::assertStringContainsString('rupertsheldrake.substack.com/p/the-souls-of-plants', $result);
        self::assertStringContainsString('An ancient intuition', $result);
    }

    public function testDoesNothingWhenThereIsNoPaywallLandmark(): void
    {
        $body = '<div class="single-post-container"><article class="post">'
            . '<p>A full free article with real prose that is not gated at all here.</p>'
            . '</article></div>';
        $context = new GatedMediaContext('https://x.substack.com/p/free', 'https://x/og.jpg');

        self::assertFalse($this->apply($body, $context, $result));
        self::assertStringContainsString('A full free article', $result);
    }

    public function testDoesNothingWhenThePosterUrlIsMissing(): void
    {
        $body = '<div><div role="region" aria-label="Paywall"><p>Gated.</p></div>'
            . '<article class="podcast-post"><div class="player"><p>Preview</p></div></article></div>';
        $context = new GatedMediaContext('https://x.substack.com/p/a', null);

        self::assertFalse($this->apply($body, $context, $result));
    }

    private function apply(string $bodyHtml, GatedMediaContext $context, ?string &$result): bool
    {
        $document = HtmlDocumentParser::parseOrNull($bodyHtml);
        self::assertNotNull($document);
        $acted = $this->placeholder->replaceIn($document, $context);
        $result = $document->saveHtml();

        return $acted;
    }
}
```

- [ ] **Step 3: Run, verify failure**

Run: `docker compose exec -T php vendor/bin/phpunit --filter SubstackGatedVideoPlaceholderTest`
Expected: FAIL — class not found.

- [ ] **Step 4: Implement `SubstackGatedVideoPlaceholder`**

Detection (guard clauses, all required): `posterUrl` is a non-empty `http(s)` URL; body has a paywall landmark (`querySelector('[aria-label="Paywall"], [data-testid="paywall"]')`); body has a podcast/video article (`querySelector('article.podcast-post, article.shows-post, .shows-video-player-container')`). If any missing, return `false`.

Action: remove the paywall landmark element and the player region (`.shows-video-player-container`, or the nearest ancestor `div` of the player controls — for the test markup, remove `.player`; in production the player is `.shows-video-player-container`). Insert, at the article's top, a poster figure built with `$document->createElement`:

```php
$link = $document->createElement('a');
$link->setAttribute('href', $context->sourceUrl);
$image = $document->createElement('img');
$image->setAttribute('src', $context->posterUrl);
$image->setAttribute('alt', 'Video — open the original article to watch');
$image->setAttribute('width', '1280');
$image->setAttribute('height', '720');
$link->appendChild($image);
$article->insertBefore($link, $article->firstChild);
```

Return `true`. Keep detection and mutation in separate private methods.

- [ ] **Step 5: Run, verify pass**

Run: `docker compose exec -T php vendor/bin/phpunit --filter SubstackGatedVideoPlaceholderTest`
Expected: PASS (3 tests).

- [ ] **Step 6: Thread through `ReaderBodyCleaner`**

Inject the tagged iterator and add the parameter:

```php
public function __construct(
    private NavigationChromeTrimmer $navigationTrimmer,
    private LeadingTitleRemover $titleRemover,
    private EdgeBoilerplateTrimmer $boilerplateTrimmer,
    private ReaderLeadImage $leadImage,
    #[\Symfony\Component\DependencyInjection\Attribute\AutowireIterator('app.gated_media_placeholder')]
    private iterable $gatedMediaPlaceholders,
) {
}

public function clean(
    string $contentHtml,
    array $titleCandidates,
    LeadImageCandidate $leadImage,
    GatedMediaContext $gatedMedia,
): string {
    $document = HtmlDocumentParser::parseOrNull($contentHtml);
    if ($document === null) {
        return $contentHtml;
    }

    $this->navigationTrimmer->trimIn($document);
    $this->titleRemover->removeFrom($document, $titleCandidates);
    $this->boilerplateTrimmer->trimIn($document);
    $this->leadImage->restore($document, $leadImage);
    foreach ($this->gatedMediaPlaceholders as $placeholder) {
        if ($placeholder->replaceIn($document, $gatedMedia)) {
            break;
        }
    }

    return $document->saveHtml();
}
```

Add `@param iterable<GatedMediaPlaceholderInterface> $gatedMediaPlaceholders` on the constructor (PHPStan generics).

- [ ] **Step 7: Register the tag in `services.yaml`**

Under `_instanceof:` add:

```yaml
        App\Service\Reader\GatedMediaPlaceholderInterface:
            tags: ['app.gated_media_placeholder']
```

- [ ] **Step 8: Update `ArticleExtractor` and its callers**

In `extract()`:

```php
$leadImage = new LeadImageCandidate($article->image, $pageImages);
$gatedMedia = new GatedMediaContext($page->finalUrl, $article->image);
$body = $this->bodyCleaner->clean($article->content, [$article->title, $entryTitle], $leadImage, $gatedMedia);
```

Update every `ReaderBodyCleaner::clean(...)` call and every test constructing `ReaderBodyCleaner` (add the iterator arg, e.g. `[new SubstackGatedVideoPlaceholder()]` or `[]`) and each `clean()` call (add a `new GatedMediaContext('https://x.test/a', null)`). Grep: `rg 'bodyCleaner->clean|new ReaderBodyCleaner|->clean\(' backend/tests backend/src`.

- [ ] **Step 9: Run reader + extractor tests and gates**

Run: `docker compose exec -T php vendor/bin/phpunit --filter 'ReaderBodyCleaner|ArticleExtractor|SubstackGatedVideoPlaceholder'`
Run: `composer cs && composer stan && composer md && composer tramp`
Expected: PASS / no findings. (`clean()` has 4 params — within limits; if tramp flags the context passing, make it a field on a per-pass collaborator, not a longer chain.)

- [ ] **Step 10: Commit**

```bash
git add backend/src/Service/Reader/GatedMediaPlaceholderInterface.php backend/src/Service/Reader/GatedMediaContext.php backend/src/Service/Reader/SubstackGatedVideoPlaceholder.php backend/src/Service/Reader/ReaderBodyCleaner.php backend/src/Service/Reader/ArticleExtractor.php backend/config/services.yaml backend/tests/Service/Reader/
git commit -m "feat(#627): Substack gated-video poster placeholder"
```

---

## Task 6: Verify against the audit corpus and lock the gates

Prove all 17 findings clear and all 25 confirmed-good articles stay clean; extend the audit-side regression test; run the full gate set.

**Files:**
- Modify (if a new confirmed shape needs pinning): `backend/tests/Service/ReaderAudit/ConfirmedGoodArticlesTest.php`

- [ ] **Step 1: Restart the worker so the pipeline runs the new code**

The worker daemon loads code once at boot.

Run: `docker compose restart php`

- [ ] **Step 2: Re-audit the 17 findings + 25 confirmed-good as a fixed set**

Run:
```bash
docker compose exec -T php bin/console app:reader:audit \
  --entries 27491,1466,466427,466428,465683,483552,1527,465505,1589,1687,1617,479991,490390,200280,479596,467295,466488,466102,478174,482435,1232,24898,465337,483075,466385,465729,484654,476717,467220,465402,465429,472312,465143,465249,488383,490832,465705,474351,2757,485778,23718 \
  --out var/reader-audit/verify.jsonl
```
Expected: the 7 share, 5 nav, 4 title, 1 ad-label findings now carry **no** markers; every confirmed-good entry stays marker-free. Inspect with the JSONL dump used during design (rank by score). POLITICO 483552 must be marker-free (contact link kept, no share finding).

- [ ] **Step 3: If any finding persists, fix its cleaner and re-run**

Read the offending entry's cleaned body via `docker compose exec -T php php var/dump-body.php '<sourceUrl>' '<title>' sanitized` and adjust the responsible rule (not the threshold blindly). Re-run Step 2.

- [ ] **Step 4: Confirm #627 Sheldrake renders the placeholder + teaser**

Run: `docker compose exec -T php php var/dump-body.php 'https://rupertsheldrake.substack.com/p/the-souls-of-plants' 'The Souls of Plants' sanitized`
Expected: no "Playback speed"/"Share post"; an `<a><img>` poster to the source; the teaser prose present.

- [ ] **Step 5: Fresh stratified sweep for second-order effects**

Run: `LIMIT=500 SHARDS=8 backend/bin/reader-audit.sh` (or the design-time spike driver).
Expected: no new marker classes; flagged count at or below the pre-change baseline. Spot-check any new flag.

- [ ] **Step 6: Extend `ConfirmedGoodArticlesTest` if a new shape was confirmed**

Add reduced fixtures for any newly confirmed-good shape (e.g. POLITICO's kept contact link) mirroring the file's existing style. Run: `docker compose exec -T php vendor/bin/phpunit --filter ConfirmedGoodArticlesTest`.

- [ ] **Step 7: Full backend gate set**

Run:
```bash
docker compose exec -T php vendor/bin/phpunit
cd backend && composer check && composer infection:diff
```
Expected: green suite; `composer check` (cs + stan + tramp) clean; `infection:diff` ≥ minMsi 80. Add boundary/weight/umlaut tests for any escaped mutant on a changed line.

- [ ] **Step 8: PhpStorm inspections on changed PHP**

Run `mcp__phpstorm__lint_files` on every changed/created `.php`. Block on ERROR and WARNING; fix before proceeding.

- [ ] **Step 9: Remove throwaway spike files**

Run: `docker compose exec -T php sh -c 'rm -f var/dump-body.php var/process-raw.php var/taz-raw.html && rm -rf var/reader-audit/base var/reader-audit/capped'`
(These live under gitignored `backend/var`, but delete them so the next audit run is clean.)

- [ ] **Step 10: Commit any test additions**

```bash
git add backend/tests/Service/ReaderAudit/ConfirmedGoodArticlesTest.php
git commit -m "test(#627): pin confirmed-good shapes after chrome-cleaner changes"
```

---

## Self-Review

**Spec coverage:**
- A1 ShareIntentLinkRemover → Task 1. ✓
- A2 NavigationChromeTrimmer menu list → Task 2. ✓
- A3 LeadingTitleRemover first block → Task 3. ✓
- A4 EdgeBoilerplateTrimmer cap + ad label → Task 4. ✓
- B Substack placeholder (interface, DTO, strategy, wiring) → Task 5. ✓
- No API/frontend change → honored (no `ExtractionResult`/Angular edits). ✓
- Verification (re-sweep, ConfirmedGood, gates, infection) → Task 6. ✓

**Placeholder scan:** every code step carries real code; no TBD/TODO. ✓

**Type consistency:** `clean(string, array, LeadImageCandidate, GatedMediaContext): string`, `GatedMediaContext(string $sourceUrl, ?string $posterUrl)`, `GatedMediaPlaceholderInterface::replaceIn(\Dom\HTMLDocument, GatedMediaContext): bool`, tag `app.gated_media_placeholder` — used identically in Tasks 5 and 6. ✓
