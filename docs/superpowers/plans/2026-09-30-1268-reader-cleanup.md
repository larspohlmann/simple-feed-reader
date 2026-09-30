# Reader Cleanup: One Substantial-Prose Check, One Emptied-Wrapper Walk, `remove()` (#1268) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Close #1268 in one PR on `chore/1268-reader-cleanup`. The substantial-prose check that `EdgeBoilerplateTrimmer` and `AuthorBioSeparator` each write out becomes one injected service with one threshold. The three "remove emptied wrappers" walks (`DuplicateBlockCollapser`, `PlayerChromeCleaner`, `OrphanIconGlyphRemover`) become one injected service with one definition of "empty". `ShareWidgetRemover` and `ScreenReaderOnlyElementRemover` call `$element->remove()` instead of reaching through a parent check that guards nothing. A before/after harness runs the whole fetch-normalise-extract-clean pipeline over every HTML fixture and a frozen corpus of real pages, so every output change is either one of the four intended changes listed below or a stop.

**Architecture:**
- **`App\Service\Reader\SubstantialProseDetector`** (new root service, next to `LinkListDetector`): `isSubstantial(Element $block): bool`, 200 collapsed characters and not link-dominated. The threshold is tuning and the class calls a service, so under `docs/architecture.md` §10 ("`Support/` computes; a service decides") it is an injected root service, not a `Support/` helper.
- **`App\Service\Reader\EmptiedWrapperRemover`** (new root service): `removeWithEmptiedWrappers(Element $node)` for the collapser and the player cleaner, `removeIfEmptied(Element $element)` for the glyph remover. It removes the DOM and holds a curated tag list, so it is a service too: no `Support/` class in `src` mutates a document, and §10 makes a curated list policy.
- **Behaviour.** Item 1 changes nothing: the two checks are the same code with the same threshold. Item 3 changes nothing. Item 2 changes output only in the four ways listed under "Intended behaviour changes", each pinned by a test. `ReaderCacheService.VERSION` is bumped (Task 7), as the reader rules require for any reader-output change.
- **The harness** (Appendix H, in `backend/var/reader-1268/`, git-ignored) wires the pipeline as `ArticleExtractorTest` does, offline, over all 61 `tests/Fixtures/**/*.html` pages and up to 300 pages frozen from the dev database. It writes one file per page. Task 0 captures the base; every code task diffs against the snapshot before it.

**Tech Stack:** PHP 8.4 (`Dom\HTMLDocument`, `Dom\Element::closest()`/`matches()`/`querySelector()`/`remove()`), Symfony 7.4 autowiring (`config/services.yaml` lists the repair and cleaning steps by id; their constructors autowire), PHPUnit 12, PHPStan level max, PHPMD, phptramp, Infection (`composer infection:diff`, `minMsi` 80), Angular 20 for the one-line cache-version bump.

**Spec:**
- GitHub issue #1268 (`gh issue view 1268 --repo larspohlmann/simple-feed-reader`).
- CLAUDE.md, "PHP code style — Clean Code is mandatory"; `docs/architecture.md` §10.
- The reader pipeline's settled decisions (host-agnostic rule; any reader-output change bumps `ReaderCacheService.VERSION`).

Written at `bf742411` (origin/develop). Line numbers below are anchors at that commit, not contracts: every edit names the text it replaces.

---

## What the code does at `bf742411`

### The two substantial-prose checks (item 1)

`backend/src/Service/Reader/BodyCleaning/BodyCleaningStep/EdgeBoilerplateTrimmer.php:21-22` and `:160-164`:

```php
    /** Characters of text that mark a block as a real, substantial paragraph. */
    private const int SUBSTANTIAL_PROSE_LENGTH = 200;
…
    private function isSubstantialProse(Element $block): bool
    {
        return mb_strlen(BlockText::collapsed($block)) >= self::SUBSTANTIAL_PROSE_LENGTH
            && !$this->linkLists->isLinkDominated($block);
    }
```

`backend/src/Service/Reader/BodyCleaning/BodyCleaningStep/AuthorBioSeparator.php:20` and `:101-105`:

```php
    private const int SUBSTANTIAL_PROSE_LENGTH = 200;
…
    private function isSubstantialProse(Element $paragraph): bool
    {
        return mb_strlen(BlockText::collapsed($paragraph)) >= self::SUBSTANTIAL_PROSE_LENGTH
            && !$this->linkLists->isLinkDominated($paragraph);
    }
```

They do not differ: same threshold (200), same measure (`BlockText::collapsed()`), same link test (`LinkListDetector::isLinkDominated()`). Only the parameter name differs. Merging them changes no output.

### The three emptied-wrapper walks (item 2)

**A.** `DuplicateBlockCollapser.php:72-93`:

```php
    /** Remove the node, then the wrappers it leaves empty, so no blank paragraph or box survives. */
    private function removeBlock(Element $node): void
    {
        $parent = $node->parentElement;
        $node->remove();
        while ($parent instanceof Element && $this->isEmptyWrapper($parent)) {
            $grandparent = $parent->parentElement;
            $parent->remove();
            $parent = $grandparent;
        }
    }

    /** A structural root is never dissolved; a wrapper with no text and no media is. */
    private function isEmptyWrapper(Element $element): bool
    {
        if (in_array(strtoupper($element->nodeName), ['BODY', 'ARTICLE', 'MAIN', 'SECTION'], true)) {
            return false;
        }

        return trim((string) $element->textContent) === ''
            && $element->querySelector('img, picture, video, iframe, audio, svg') === null;
    }
```

**B.** `PlayerChromeCleaner.php:25` and `:134-152`:

```php
    private const array MEDIA_TAGS = ['img', 'audio', 'video', 'iframe', 'svg'];
…
    private function removeWithEmptiedWrappers(Element $readout, Element $body): void
    {
        $wrapper = $readout->parentElement;
        $readout->remove();
        while ($wrapper !== null && $wrapper !== $body && $this->isEmptied($wrapper)) {
            $next = $wrapper->parentElement;
            $wrapper->remove();
            $wrapper = $next;
        }
    }

    private function isEmptied(Element $wrapper): bool
    {
        return Whitespace::collapse($wrapper->textContent) === ''
            && !array_any(
                self::MEDIA_TAGS,
                static fn (string $tag): bool => $wrapper->getElementsByTagName($tag)->length > 0,
            );
    }
```

**C.** `PageRepair/OrphanIconGlyphRemover.php:24-27` and `:64-94` (runs before readability, on the raw page):

```php
    /** Elements that carry content without text, so an empty one still counts. */
    private const array EMBEDDED_TAGS = [
        'img', 'picture', 'source', 'svg', 'video', 'audio', 'iframe', 'br', 'hr', 'input',
    ];
…
    private function pruneWhileEmpty(Element $element): void
    {
        while (
            $element->parentNode !== null
            && trim((string) $element->textContent) === ''
            && !$this->holdsEmbeddedContent($element)
        ) {
            $parent = $element->parentNode;
            $parent->removeChild($element);
            if (!$parent instanceof Element) {
                return;
            }
            $element = $parent;
        }
    }

    private function holdsEmbeddedContent(Element $element): bool
    {
        foreach ($element->getElementsByTagName('*') as $descendant) {
            if (in_array($descendant->localName, self::EMBEDDED_TAGS, true)) {
                return true;
            }
        }

        return false;
    }
```

| | A (collapser) | B (player chrome) | C (glyphs, pre-readability) |
|---|---|---|---|
| Starts at | the removed node's parent | the removed node's parent | the glyph holder itself |
| Never removes | `body`, `article`, `main`, `section` | `$body` | nothing (can reach `body`, `head`, `html`) |
| Blank text | `trim()` | `Whitespace::collapse()` (Unicode, so `&nbsp;` is blank) | `trim()` |
| Content without text | img, picture, video, iframe, audio, svg | img, audio, video, iframe, svg | img, picture, source, svg, video, audio, iframe, br, hr, input |
| The element itself counts | no (descendants only) | no | no |

### `ShareWidgetRemover` (item 3)

`PageRepair/ShareWidgetRemover.php:34-38`:

```php
        foreach ($this->elementsWithClass($document) as $element) {
            if ($element->parentNode !== null && $this->isShareWidget($element)) {
                $element->parentNode->removeChild($element);
            }
        }
```

The list is collected before any removal. Removing an element detaches it but leaves its descendants attached to it, so no collected element ever has a null `parentNode` (the `<html>` element's parent is the document). The check guards nothing and only satisfies PHPStan's nullable `parentNode`. `PageRepair/ScreenReaderOnlyElementRemover.php:21-28` has the same shape over `querySelectorAll('[class]')` (a static list):

```php
        foreach ($document->querySelectorAll('[class]') as $element) {
            if (
                $element->parentNode !== null
                && preg_match(self::HIDDEN_CLASS_PATTERN, $element->getAttribute('class') ?? '') === 1
            ) {
                $element->parentNode->removeChild($element);
            }
        }
```

---

## Decisions

- **D-1 (item 1: one check, one threshold, no behaviour change).** The two checks are identical (quoted above). The merged `SubstantialProseDetector` keeps 200 and the link test. It is an injected root service in `Service/Reader/`: the 200 is a measured threshold and the class calls `LinkListDetector`, and §10 makes both a service, never `Support/`.
- **D-2 (item 1: scope).** `RelatedTeaserGridRemover::containsSubstantialProse()` (`RelatedTeaserGridRemover.php:129-138`, 200, **length only, no link test**) and `NavigationChromeTrimmer::SUBSTANTIAL_PROSE_LENGTH = 120` (`:35`, "the article has started") ask different questions. Routing either through the detector would change output (a link-dominated 200-character card paragraph, a 120–199-character first paragraph), so both stay as they are.
- **D-3 (item 2: one walk, no parameters).** None of the five differences in the table is intended. Each walk grew on its own (#786, #963, #472), and none of the commits or tests says why its choices differ from the others. So the walk takes no parameters, and each difference is settled once by the rule the rest of the pipeline already uses:
  - **Roots: dissolve `section`/`article`/`main`, never touch `body` or anything outside it.** `TrailingBlankRemover` (`src/Service/Sanitize/TrailingBlankRemover.php:8-18`) strips empty `section`/`article` blocks because they "still draw their margin". The frontend cards every heading-less `<section>` (`frontend/src/app/reader/reader-cards.ts`, `isSemanticInsert()`; `dominatesArticle()` returns false for an empty one) with padding and a border (`reader-view.component.content.scss:102-108`). So the `section` that A keeps renders as an **empty card**, the very "box" its own docblock says the walk exists to remove. A's `testKeepsAStructuralSectionLeftEmptyByACollapse` pins that defect, and Task 4 reverses it. The body boundary also stops C from reaching `<head>` or `<html>`.
  - **Blank text: `Whitespace::collapse()`** (Unicode whitespace, so `&nbsp;` counts as blank). This is what B and `LeadingEngagementCleaner::isRemainder()` use, and `TrailingBlankRemover` treats `&nbsp;` as blank too.
  - **Content without text: `img, picture, svg, video, audio, iframe`.** These are the HTML embedded-content tags every walk except B already counts (B lacks only `picture`). C's extra `source`, `br`, `hr` and `input` draw nothing, or only a blank line, or a separator, once the text around them is gone. `TrailingBlankRemover` treats a `<br>` as blank, and `LeadingEngagementCleaner::isRemainder()` treats an `<hr>` as a remainder. An orphan `<source>` renders nothing. Readability and the sanitizer drop `<input>`.
  - **The element itself counts.** An emptied `<video>`, `<audio>`, `<svg>`, `<iframe>` or `<picture>` is content and stays. `LeadingEngagementCleaner::hasMedia()` already checks "the element itself or any descendant". All three walks checked only descendants, so a readout paragraph in a player's fallback content took the player with it.
- **D-4 (item 2: the walk's API).** There are two entry points because there are two starts: the collapser and the player cleaner remove a node and then its wrappers, while the glyph remover removes a holder only when the holder is empty. `removeIfEmptied()` checks and then hands over to `removeWithEmptiedWrappers()`, which calls `removeIfEmptied()` on the parent, so one `isEmptied()` decides every step. The recursion is only as deep as the chain of emptied wrappers.
- **D-5 (item 3: both removers).** `ShareWidgetRemover` and `ScreenReaderOnlyElementRemover` both call `$element->remove()` ("always the general solution": same pattern, same fix). The unwrappers that check `parentNode` (`CustomElementUnwrapper`, `ImageButtonUnwrapper`, `FetchedPageNormalizer::unwrapSingleChildDivs()`) need the parent to insert the children, and `LeadingEngagementCleaner:169` is a different walk. They stay.
- **D-6 (the "reader audit").** `app:reader:audit` / `bin/reader-audit.sh` sample *live* pages from the Docker database, so two runs hours apart differ by page drift and cannot serve as a before/after gate. No golden or snapshot test over the fixtures exists (the fixtures are read ad hoc by `ArticleExtractorTest` and the media and slideshow tests). Appendix H therefore adds the deterministic version of that audit: the 61 fixtures plus up to 300 real pages frozen once from the same database, run offline through the same wiring before and after.
- **D-7 (cache version).** The intended changes alter the reader output of real pages, so `frontend/src/app/reader/reader-cache.service.ts` goes from `VERSION = 26` to `27` (Task 7). `26` shipped in `v1.0.17-dev.3`, per `git merge-base --is-ancestor 2cabccf4 v1.0.17-dev.3`. See Q-2.

## Intended behaviour changes

These are the only output changes Tasks 4–6 may produce. The harness diff after each task is read against this list.

| Id | Change | Walks it changes | Pinned by |
|---|---|---|---|
| I-1 | An emptied `section`/`article`/`main` is removed. `body` and everything outside it (`head`, `title`, `html`) never is. | A (sections now go), C (bounded) | `EmptiedWrapperRemoverTest::testDissolvesEmptiedSectioningElements`, `…NeverRemovesTheBody`, `…LeavesAnEmptiedElementOutsideTheBodyAlone`, `…NeverRemovesTheDocumentElement`; `DuplicateBlockCollapserTest::testDissolvesASectionLeftEmptyByACollapse` |
| I-2 | Text that is only Unicode whitespace (`&nbsp;`) is blank. | A, C | `…TreatsANonBreakingSpaceAsEmpty` |
| I-3 | Content without text is `img, picture, svg, video, audio, iframe`: a wrapper left with only a `br`, `hr`, `input` or orphan `source` goes. | B (gains `picture`), C (loses `source`, `br`, `hr`, `input`) | `…KeepsAWrapperThatStillHoldsContentWithoutText` (6 cases), `…RemovesAWrapperLeftWithOnlyABlankElement` (4 cases); `OrphanIconGlyphRemoverTest::testPrunesAHolderLeftWithOnlyALineBreak` |
| I-4 | An emptied element that is itself one of those tags stays. | A, B, C | `…KeepsAnEmptiedElementThatIsItselfAPlayer` |

## Questions for the planner

- **Q-1 (sections):** "dissolve an emptied `section`/`article`/`main` in every walk" (recommended; planned: an empty section renders as an empty card, D-3), or "keep them in the collapser only, as it does today" (the walk would then take an explicit protected-roots parameter and `testKeepsAStructuralSectionLeftEmptyByACollapse` stays).
- **Q-2 (cache version):** "bump `ReaderCacheService.VERSION` to 27 in this PR" (recommended; planned as Task 7 per the settled reader rule), or "leave the frontend untouched, as the series brief defaults to" (drop Task 7; already-opened articles keep the old copy until the next bump).
- **Q-3 (`<hr>`):** "a lone `<hr>` leaves a wrapper empty" (recommended; planned, as `LeadingEngagementCleaner::isRemainder()` treats it), or "count `<hr>` as content without text, as `OrphanIconGlyphRemover` does today" (add `hr` to the selector, move the `rule` case from `blankElements()` to `contentWithoutText()`).

## Status

| Task | State |
|---|---|
| 0: Preflight, branch, plan copy, anchors, harness baseline | ⬜ |
| 1: `ShareWidgetRemover` and `ScreenReaderOnlyElementRemover` call `remove()` | ⬜ |
| 2: `SubstantialProseDetector`; both trimmers use it | ⬜ |
| 3: `EmptiedWrapperRemover` and its test | ⬜ |
| 4: `DuplicateBlockCollapser` uses the walk | ⬜ |
| 5: `PlayerChromeCleaner` uses the walk | ⬜ |
| 6: `OrphanIconGlyphRemover` uses the walk | ⬜ |
| 7: Reader cache version 27 | ⬜ |
| 8: PR gates, independent review, PR | ⬜ |

## Global Constraints

- **Paths and commands are relative to `backend/`**, except steps marked "from the repository root" and `frontend/…`.
- **Read before you write.** Every edit names the exact text it replaces. If that text is not there, stop and report the file and the text you found.
- **Clean Code (CLAUDE.md) is mandatory.** New services are `final readonly` in the module root and take only collaborators. Default to no comment, three lines at most.
- **PHPStan level max:** no baseline entry, no `@phpstan-ignore`.
- **Every touched `src` file is PHPMD-clean** under `composer md`.
- **TDD where behaviour changes** (Tasks 3, 4, 6): the failing test first, with its quoted FAIL, then the code, then the PASS.
- **Deletion checks:** every new or strengthened test gets a step that breaks the code it guards, runs the test, and quotes the FAIL. Restore by re-applying the After with the Edit tool, **never `git checkout --`**. A check without a quoted FAIL is not done. The reviewer re-runs at least one per task.
- **Every grep has a positive control.** A grep expected to print nothing is paired with the same pattern printing a known hit. Use `git grep -E`, `(^|[^A-Za-z0-9_])` for word boundaries, and never `-F` with backslashes.
- **The harness is a gate.** After Tasks 1, 2, 4, 5 and 6, run the snapshot and diff it against the previous snapshot. Tasks 1 and 2 expect no output. Tasks 4–6 expect no output, or only hunks each explained by one of I-1…I-4. An unexplained hunk means stop: report the page, the hunk and your reading of it to the planner, and do not commit.
- **Commits:** `type(#1268): <lower-case summary>`, one per task, no attribution or co-author lines.
- **The checkout is shared.** Run `git status --short && git branch --show-current` before any `switch`. Work in place, no worktrees, no stash.

---

## Appendix H: the before/after harness

Two one-off scripts in `backend/var/reader-1268/`. `backend/.gitignore` line 7 (`/var/`) keeps them and their output out of git. Task 0 writes them, and Task 8 deletes the directory.

`freeze.php` fetches each URL in `corpus/urls.txt` once, concurrently, with the app's User-Agent, and stores the bytes as `corpus/NNNN.html` (NNNN = line number).

`snapshot.php` runs every `tests/Fixtures/**/*.html` page and every frozen corpus page through the pipeline, wired as `ArticleExtractorTest::extractor()` wires it. It reuses `FetchedPageNormalizerTest::repairs()` and `ReaderBodyCleanerTest::steps()`, which each task keeps in step with the constructors, and adds both slideshow recognizers. It runs offline: every host resolves to one public IP, the first request serves the page, and every later request (media verification) gets a 404. For each page it writes the normalised page (the output of the `PageRepair` pipeline, where `OrphanIconGlyphRemover` and both removers run) and the extraction result (`ok`, reason, title, byline, excerpt, paywalled, the sanitised body). HTML is broken before each `<` so that a diff shows the changed tag. Stdout gets one `sha1 status name` line per page.

### `backend/var/reader-1268/freeze.php`

```php
<?php

declare(strict_types=1);

use Symfony\Component\HttpClient\HttpClient;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

$corpusDirectory = __DIR__ . '/corpus';
$urls = file($corpusDirectory . '/urls.txt', FILE_IGNORE_NEW_LINES);
if ($urls === false) {
    fwrite(STDERR, "Write the URL list to var/reader-1268/corpus/urls.txt first.\n");
    exit(1);
}

$client = HttpClient::create([
    'timeout' => 20,
    'max_duration' => 40,
    'headers' => ['User-Agent' => 'SimpleFeedReader/1.0'],
]);

$responses = [];
foreach ($urls as $index => $url) {
    if ($url !== '') {
        $responses[$index] = $client->request('GET', $url);
    }
}

$frozen = 0;
foreach ($responses as $index => $response) {
    try {
        $body = $response->getContent(false);
    } catch (Throwable $exception) {
        fwrite(STDERR, sprintf("%04d %s: %s\n", $index + 1, $urls[$index], $exception->getMessage()));
        continue;
    }
    file_put_contents(sprintf('%s/%04d.html', $corpusDirectory, $index + 1), $body);
    ++$frozen;
}

printf("froze %d of %d pages\n", $frozen, count($responses));
```

### `backend/var/reader-1268/snapshot.php`

```php
<?php

declare(strict_types=1);

use App\Http\SymfonyStatusReasonPhrases;
use App\Service\Fetch\DnsResolver\DnsResolverInterface;
use App\Service\Fetch\EgressProxySource\EgressProxySourceInterface;
use App\Service\Fetch\IpValidator;
use App\Service\Fetch\Model\ProxyConfigModel;
use App\Service\Fetch\UrlGuard;
use App\Service\Reader\ArticleContentGate;
use App\Service\Reader\ArticleExtractor\ArticleExtractor;
use App\Service\Reader\ArticlePageReader;
use App\Service\Reader\ArticleReadability;
use App\Service\Reader\FetchedPageNormalizer;
use App\Service\Reader\HtmlPageFetcher;
use App\Service\Reader\LandingChallenge;
use App\Service\Reader\Media\BodyMediaResolver;
use App\Service\Reader\Media\DurableMediaUrl;
use App\Service\Reader\Media\EmbedProvider\BrightcoveEmbedProvider;
use App\Service\Reader\Media\EmbedProvider\DailymotionEmbedProvider;
use App\Service\Reader\Media\EmbedProvider\SpotifyEmbedProvider;
use App\Service\Reader\Media\EmbedProvider\VimeoEmbedProvider;
use App\Service\Reader\Media\EmbedProvider\YouTubeEmbedProvider;
use App\Service\Reader\Media\EmbedProviders;
use App\Service\Reader\Media\MediaCandidateSource\AttributeMediaSource;
use App\Service\Reader\Media\MediaCandidateSource\JsonLdMediaSource;
use App\Service\Reader\Media\MediaCandidateSource\PageEmbedSource;
use App\Service\Reader\Media\MediaCandidateSource\ScriptEmbedSource;
use App\Service\Reader\Media\MediaCandidateSource\SemanticMediaSource;
use App\Service\Reader\Media\MediaCandidateSource\YouTubeIdAttributeSource;
use App\Service\Reader\Media\MediaLanding;
use App\Service\Reader\Media\MediaRelevance;
use App\Service\Reader\Media\MediaUrlKind;
use App\Service\Reader\Media\NarrationSignals;
use App\Service\Reader\Media\PageFurniture;
use App\Service\Reader\Media\PageMediaScanner;
use App\Service\Reader\Media\PlayerPoster;
use App\Service\Reader\Media\Sibling\NearbyPoster;
use App\Service\Reader\Media\Sibling\SiblingIdRule;
use App\Service\Reader\Media\Sibling\SiblingMediaExtender;
use App\Service\Reader\Media\StreamLocationResolver;
use App\Service\Reader\Media\Teaser\TeaserPlayerScanner;
use App\Service\Reader\MetaRefreshTarget;
use App\Service\Reader\Paywall\MembershipCheckout;
use App\Service\Reader\Paywall\OutsideFurniture;
use App\Service\Reader\Paywall\PaywallBlocks;
use App\Service\Reader\Paywall\PaywallSignals;
use App\Service\Reader\ReaderBodyCleaner;
use App\Service\Reader\RelatedTeaserGridRemover;
use App\Service\Reader\Slideshow\SlideCaptionResolver;
use App\Service\Reader\Slideshow\SlideImageResolver;
use App\Service\Reader\Slideshow\SlideshowRecognizer\MarkupCarouselRecognizer;
use App\Service\Reader\Slideshow\SlideshowRecognizer\TagesschauCarouselRecognizer;
use App\Service\Reader\Slideshow\SlideshowScanner;
use App\Service\Sanitize\EntrySanitizer;
use App\Service\Sanitize\TrailingBlankRemover;
use App\Tests\Service\Reader\FetchedPageNormalizerTest;
use App\Tests\Service\Reader\ReaderBodyCleanerTest;
use App\Tests\Support\FetchWiring;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

$backendDirectory = dirname(__DIR__, 2);
require $backendDirectory . '/vendor/autoload.php';

$outputDirectory = $argv[1] ?? '';
if ($outputDirectory === '') {
    fwrite(STDERR, "Usage: php var/reader-1268/snapshot.php <output-directory>\n");
    exit(1);
}
if (!is_dir($outputDirectory) && !mkdir($outputDirectory, 0o777, true)) {
    fwrite(STDERR, "Cannot create $outputDirectory\n");
    exit(1);
}

$pages = [];
$fixtureRoot = $backendDirectory . '/tests/Fixtures';
$fixtureFiles = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($fixtureRoot, FilesystemIterator::SKIP_DOTS),
);
foreach ($fixtureFiles as $file) {
    if ($file instanceof SplFileInfo && $file->getExtension() === 'html') {
        $relative = substr($file->getPathname(), strlen($fixtureRoot) + 1);
        $pages['fixture/' . $relative] = [
            'path' => $file->getPathname(),
            'url' => 'https://site.test/' . str_replace(['/', '.html'], ['-', ''], $relative),
        ];
    }
}

$corpusDirectory = __DIR__ . '/corpus';
$corpusUrls = is_file($corpusDirectory . '/urls.txt')
    ? (file($corpusDirectory . '/urls.txt', FILE_IGNORE_NEW_LINES) ?: [])
    : [];
foreach ($corpusUrls as $index => $url) {
    $path = sprintf('%s/%04d.html', $corpusDirectory, $index + 1);
    if ($url !== '' && is_file($path)) {
        $pages[sprintf('corpus/%04d', $index + 1)] = ['path' => $path, 'url' => $url];
    }
}
ksort($pages);

$providers = new EmbedProviders([
    new YouTubeEmbedProvider(),
    new BrightcoveEmbedProvider(),
    new VimeoEmbedProvider(),
    new SpotifyEmbedProvider(),
    new DailymotionEmbedProvider(),
]);
$urlKind = new MediaUrlKind(new DurableMediaUrl(), $providers);
$normalizer = new FetchedPageNormalizer(FetchedPageNormalizerTest::repairs());
$outsideFurniture = new OutsideFurniture(new PageFurniture());
$anyHostResolver = new class () implements DnsResolverInterface {
    public function resolve(string $hostname): array
    {
        return ['93.184.216.34'];
    }
};
$noEgressProxy = new class () implements EgressProxySourceInterface {
    public function egressProxy(): ?ProxyConfigModel
    {
        return null;
    }
};

$extractorServing = static function (string $html) use (
    $providers,
    $urlKind,
    $normalizer,
    $outsideFurniture,
    $anyHostResolver,
    $noEgressProxy,
): ArticleExtractor {
    $served = false;
    $client = new MockHttpClient(static function () use ($html, &$served): MockResponse {
        if ($served) {
            return new MockResponse('', ['http_code' => 404]);
        }
        $served = true;

        return new MockResponse($html, ['http_code' => 200]);
    });
    $redirects = FetchWiring::redirectFollower($client, $noEgressProxy, new UrlGuard($anyHostResolver, new IpValidator()));
    $landing = new MediaLanding($redirects, 'TestAgent/1.0');

    return new ArticleExtractor(
        new HtmlPageFetcher(
            $redirects,
            new MetaRefreshTarget(),
            new LandingChallenge(),
            'TestAgent/1.0',
            new SymfonyStatusReasonPhrases(),
        ),
        new ArticlePageReader(
            $normalizer,
            new PageMediaScanner([
                new JsonLdMediaSource($urlKind, $providers, new PageFurniture()),
                new PageEmbedSource($providers, new PageFurniture()),
                new AttributeMediaSource(
                    $urlKind,
                    new MediaRelevance(),
                    new PageFurniture(),
                    new NarrationSignals(),
                    new PlayerPoster(),
                ),
                new YouTubeIdAttributeSource($providers, new PageFurniture()),
                new SemanticMediaSource($urlKind, new PageFurniture(), new NarrationSignals()),
                new ScriptEmbedSource($providers, new PageFurniture()),
            ]),
            new SlideshowScanner([
                new MarkupCarouselRecognizer(new SlideImageResolver(), new SlideCaptionResolver()),
                new TagesschauCarouselRecognizer(),
            ]),
            new TeaserPlayerScanner($urlKind, new PageFurniture(), new PlayerPoster()),
            new PaywallSignals(new PaywallBlocks($outsideFurniture), new MembershipCheckout($outsideFurniture)),
        ),
        new ReaderBodyCleaner(ReaderBodyCleanerTest::steps($providers)),
        new EntrySanitizer(new TrailingBlankRemover()),
        new BodyMediaResolver(
            new StreamLocationResolver($landing, $urlKind),
            new SiblingMediaExtender(new SiblingIdRule(new NearbyPoster()), $landing, $urlKind),
        ),
        new ArticleReadability($normalizer, new RelatedTeaserGridRemover(), $providers, new ArticleContentGate()),
        new ArticleContentGate(),
    );
};

$tagPerLine = static fn (?string $html): string => (string) preg_replace('/(?=<)/', "\n", (string) $html);

foreach ($pages as $name => $page) {
    $html = (string) file_get_contents($page['path']);
    $snapshot = 'url: ' . $page['url'] . "\n";

    try {
        $snapshot .= "--- normalized\n" . $tagPerLine($normalizer->normalize($html)->saveHtml()) . "\n";
    } catch (Throwable $exception) {
        $snapshot .= '--- normalize threw ' . $exception::class . ': ' . $exception->getMessage() . "\n";
    }

    try {
        $result = $extractorServing($html)->extract($page['url']);
        $status = $result->ok ? 'ok' : ($result->reason->name ?? 'failed');
        $snapshot .= "--- extraction\n"
            . 'ok: ' . ($result->ok ? 'yes' : 'no') . "\n"
            . 'reason: ' . ($result->reason->name ?? '') . "\n"
            . 'title: ' . $result->title . "\n"
            . 'byline: ' . $result->byline . "\n"
            . 'excerpt: ' . $result->excerpt . "\n"
            . 'paywalled: ' . ($result->paywalled ? 'yes' : 'no') . "\n"
            . "--- content\n" . $tagPerLine($result->contentHtml) . "\n";
    } catch (Throwable $exception) {
        $status = 'threw';
        $snapshot .= '--- extract threw ' . $exception::class . ': ' . $exception->getMessage() . "\n";
    }

    file_put_contents($outputDirectory . '/' . str_replace('/', '__', $name) . '.txt', $snapshot);
    printf("%s %s %s\n", sha1($snapshot), $status, $name);
}
```

### How a task runs the harness (from `backend/`)

```bash
OTEL_PHP_DISABLED_INSTRUMENTATIONS=all php -d display_errors=stderr var/reader-1268/snapshot.php var/reader-1268/<label> > var/reader-1268/<label>.txt 2> var/reader-1268/<label>.err
diff -rq var/reader-1268/<previous-label> var/reader-1268/<label>
```

To read a changed page: `diff -u var/reader-1268/<previous-label>/<file> var/reader-1268/<label>/<file>`.

---

### Task 0: Preflight, branch, plan copy, anchors, harness baseline

**Files:**
- Create: `docs/superpowers/plans/2026-09-30-1268-reader-cleanup.md` (this plan)
- Create (git-ignored): `backend/var/reader-1268/freeze.php`, `backend/var/reader-1268/snapshot.php`

- [ ] **Step 1: The checkout is free and the issue is open (from the repository root)**

```bash
git status --short && git branch --show-current
git fetch origin
gh issue view 1268 --repo larspohlmann/simple-feed-reader --json state --jq .state
```
Expected: a clean tree (or only another session's files, which you leave alone), then `OPEN`.

- [ ] **Step 2: Branch and commit the plan copy (from the repository root)**

```bash
git switch -c chore/1268-reader-cleanup origin/develop
cp <the plan file the planner handed you> docs/superpowers/plans/2026-09-30-1268-reader-cleanup.md
git add docs/superpowers/plans/2026-09-30-1268-reader-cleanup.md
git commit -m "docs(#1268): add plan"
```

- [ ] **Step 3: The anchors hold (from `backend/`)**

```bash
git grep -n -E 'SUBSTANTIAL_PROSE_LENGTH = (200|120);' -- src/Service/Reader
git grep -n -E 'private function (removeWithEmptiedWrappers|isEmptied|removeBlock|isEmptyWrapper|pruneWhileEmpty|holdsEmbeddedContent)\(' -- src/Service/Reader
git grep -n -E 'parentNode->removeChild\(\$element\)' -- src/Service/Reader/PageRepair
git grep -n -E 'VERSION = 26;' -- ':(top)frontend/src/app/reader/reader-cache.service.ts'
git ls-files -- src/Service/Reader/SubstantialProseDetector.php src/Service/Reader/EmptiedWrapperRemover.php src/Service/Reader/LinkListDetector.php
```
Expected:
- Four lines: `AuthorBioSeparator.php:20` and `EdgeBoilerplateTrimmer.php:22` (200), `NavigationChromeTrimmer.php:35` (120), `RelatedTeaserGridRemover.php:23` (200).
- Six lines: `DuplicateBlockCollapser.php:73` (`removeBlock`), `:85` (`isEmptyWrapper`), `PlayerChromeCleaner.php:134` (`removeWithEmptiedWrappers`), `:145` (`isEmptied`), `PageRepair/OrphanIconGlyphRemover.php:69` (`pruneWhileEmpty`), `:85` (`holdsEmbeddedContent`).
- Two lines: `ScreenReaderOnlyElementRemover.php:26`, `ShareWidgetRemover.php:36`.
- One line: `reader-cache.service.ts:20`.
- Only `src/Service/Reader/LinkListDetector.php` (the positive control): neither new class exists yet.

A different result is a reconcile gap. Stop and report it with the output.

- [ ] **Step 4: Write the harness and prove git ignores it (from `backend/`)**

Create `var/reader-1268/freeze.php` and `var/reader-1268/snapshot.php` with the exact content of Appendix H.

```bash
git check-ignore -v var/reader-1268/freeze.php var/reader-1268/snapshot.php
git status --short
```
Expected: two lines, each `.gitignore:7:/var/` followed by the path; `git status` prints nothing.

- [ ] **Step 5: Freeze the corpus (from `backend/`)**

The Docker stack must be up (`docker compose up -d` from the repository root if `docker compose ps --status running --services` does not list `php`). The query only reads.

```bash
mkdir -p var/reader-1268/corpus
docker compose exec -T php bin/console dbal:run-sql "SELECT url FROM (SELECT url, ROW_NUMBER() OVER (PARTITION BY feed_id ORDER BY id DESC) AS position FROM entry WHERE url LIKE 'http%') ranked WHERE position <= 2 ORDER BY url LIMIT 300" | grep -oE 'https?://[^ ]+' > var/reader-1268/corpus/urls.txt
wc -l < var/reader-1268/corpus/urls.txt
OTEL_PHP_DISABLED_INSTRUMENTATIONS=all php -d display_errors=stderr var/reader-1268/freeze.php
```
Expected: a count above 0 (at most 300), then `froze N of M pages` with N close to M. A failed fetch prints its URL on stderr and is left out. If the stack cannot be started, skip this step, write `Corpus: none (stack down)` into the task report and the PR body, and continue with the 61 fixtures alone.

- [ ] **Step 6: Capture the base twice; the harness is deterministic (from `backend/`)**

```bash
OTEL_PHP_DISABLED_INSTRUMENTATIONS=all php -d display_errors=stderr var/reader-1268/snapshot.php var/reader-1268/base > var/reader-1268/base.txt 2> var/reader-1268/base.err
OTEL_PHP_DISABLED_INSTRUMENTATIONS=all php -d display_errors=stderr var/reader-1268/snapshot.php var/reader-1268/base-again > var/reader-1268/base-again.txt 2> var/reader-1268/base-again.err
wc -l < var/reader-1268/base.txt
grep -c ' fixture/' var/reader-1268/base.txt
grep -c ' ok fixture/' var/reader-1268/base.txt
diff -rq var/reader-1268/base var/reader-1268/base-again
```
Expected: `61 + N` lines (N = frozen pages); `61`; a count of `ok` fixtures above 0. Most `fixture/reader/` pages are whole articles and report `ok`, while `scraped/` pages and some media fragments legitimately fail. Record the count in the task report. If it is 0, the wiring is wrong: stop and report `base.err`. The last command prints nothing. If a corpus page differs between the two runs, delete its `corpus/NNNN.html`, rerun both captures, and list the page in the task report.

- [ ] **Step 7: Positive control: the harness sees a repair change (from `backend/`)**

In `src/Service/Reader/PageRepair/ShareWidgetRemover.php`, edit:

Before:
```php
        'shariff',                          // Shariff (heise)
```
After:
```php
        'shariff-control',                  // Shariff (heise)
```

```bash
OTEL_PHP_DISABLED_INSTRUMENTATIONS=all php -d display_errors=stderr var/reader-1268/snapshot.php var/reader-1268/control > /dev/null 2>&1
diff -rq var/reader-1268/base var/reader-1268/control
```
Expected: at least the line `Files var/reader-1268/base/fixture__reader__hanfjournal-shariff.html.txt and var/reader-1268/control/fixture__reader__hanfjournal-shariff.html.txt differ`. Quote it in the task report.

Restore by editing the line back to `        'shariff',                          // Shariff (heise)`, then:

```bash
rm -rf var/reader-1268/control
OTEL_PHP_DISABLED_INSTRUMENTATIONS=all php -d display_errors=stderr var/reader-1268/snapshot.php var/reader-1268/control > /dev/null 2>&1
diff -rq var/reader-1268/base var/reader-1268/control
git status --short
```
Expected: no diff output, and a clean `git status`.

---

### Task 1: `ShareWidgetRemover` and `ScreenReaderOnlyElementRemover` call `remove()`

**Files:**
- Modify: `backend/src/Service/Reader/PageRepair/ShareWidgetRemover.php`
- Modify: `backend/src/Service/Reader/PageRepair/ScreenReaderOnlyElementRemover.php`

A refactor: no behaviour changes (item 3, D-5). The existing tests guard both removers.

- [ ] **Step 1: `ShareWidgetRemover`**

Before:
```php
        foreach ($this->elementsWithClass($document) as $element) {
            if ($element->parentNode !== null && $this->isShareWidget($element)) {
                $element->parentNode->removeChild($element);
            }
        }
```
After:
```php
        foreach ($this->elementsWithClass($document) as $element) {
            if ($this->isShareWidget($element)) {
                $element->remove();
            }
        }
```

- [ ] **Step 2: `ScreenReaderOnlyElementRemover`**

Before:
```php
        foreach ($document->querySelectorAll('[class]') as $element) {
            if (
                $element->parentNode !== null
                && preg_match(self::HIDDEN_CLASS_PATTERN, $element->getAttribute('class') ?? '') === 1
            ) {
                $element->parentNode->removeChild($element);
            }
        }
```
After:
```php
        foreach ($document->querySelectorAll('[class]') as $element) {
            if (preg_match(self::HIDDEN_CLASS_PATTERN, $element->getAttribute('class') ?? '') === 1) {
                $element->remove();
            }
        }
```

- [ ] **Step 3: Tests pass**

```bash
php bin/phpunit --filter '(ShareWidgetRemoverTest|ScreenReaderOnlyElementRemoverTest|FetchedPageNormalizerTest|ArticleExtractorTest)'
```
Expected: `OK`.

- [ ] **Step 4: Guard checks: the tests see each removal**

(a) In `ShareWidgetRemover.php`, replace `                $element->remove();` with `                $element->getAttribute('class');`, then run:
```bash
php bin/phpunit --filter 'ShareWidgetRemoverTest::testRemovesTheShariffBarButKeepsTheProse'
```
Expected FAIL: `Failed asserting that '` … `' does not contain "shariff".` Restore by editing the line back to `                $element->remove();`.

(b) In `ScreenReaderOnlyElementRemover.php`, make the same replacement, then run:
```bash
php bin/phpunit --filter 'ScreenReaderOnlyElementRemoverTest::testRemovesScreenReaderOnlyElements'
```
Expected FAIL: `Failed asserting that '` … `' does not contain "Image source,".` Restore by editing the line back to `                $element->remove();`.

- [ ] **Step 5: No remover reaches through its parent any more (from `backend/`)**

```bash
git grep -n -E 'parentNode->removeChild\(\$element\)' -- src/Service/Reader
git grep -n -E 'removeChild\(\$element\)' -- src/Service/Reader
```
Expected: the first prints nothing. The second prints `src/Service/Reader/PageRepair/OrphanIconGlyphRemover.php:77:            $parent->removeChild($element);`, the positive control (Task 6 removes it).

- [ ] **Step 6: Harness: no output change (from `backend/`)**

```bash
OTEL_PHP_DISABLED_INSTRUMENTATIONS=all php -d display_errors=stderr var/reader-1268/snapshot.php var/reader-1268/after-task-1 > var/reader-1268/after-task-1.txt 2> var/reader-1268/after-task-1.err
diff -rq var/reader-1268/base var/reader-1268/after-task-1
```
Expected: no output.

- [ ] **Step 7: Lint and commit**

```bash
php -l src/Service/Reader/PageRepair/ShareWidgetRemover.php
php -l src/Service/Reader/PageRepair/ScreenReaderOnlyElementRemover.php
vendor/bin/phpcs src/Service/Reader/PageRepair/ShareWidgetRemover.php src/Service/Reader/PageRepair/ScreenReaderOnlyElementRemover.php
git add src/Service/Reader/PageRepair/ShareWidgetRemover.php src/Service/Reader/PageRepair/ScreenReaderOnlyElementRemover.php
git commit -m "refactor(#1268): share-widget and screen-reader removers call remove()"
```

---

### Task 2: `SubstantialProseDetector`; both trimmers use it

**Files:**
- Create: `backend/src/Service/Reader/SubstantialProseDetector.php`
- Create: `backend/tests/Service/Reader/SubstantialProseDetectorTest.php`
- Modify: `backend/src/Service/Reader/BodyCleaning/BodyCleaningStep/EdgeBoilerplateTrimmer.php`
- Modify: `backend/src/Service/Reader/BodyCleaning/BodyCleaningStep/AuthorBioSeparator.php`
- Modify: `backend/tests/Service/Reader/BodyCleaning/BodyCleaningStep/EdgeBoilerplateTrimmerTest.php`
- Modify: `backend/tests/Service/Reader/BodyCleaning/BodyCleaningStep/AuthorBioSeparatorTest.php`
- Modify: `backend/tests/Service/Reader/ReaderBodyCleanerTest.php`

- [ ] **Step 1: The failing test**

Create `backend/tests/Service/Reader/SubstantialProseDetectorTest.php`. The `ä` makes the byte length differ from the character length, so a `strlen()` mutant fails the 199-character case.

```php
<?php

declare(strict_types=1);

namespace App\Tests\Service\Reader;

use App\Service\Reader\LinkListDetector;
use App\Service\Reader\SubstantialProseDetector;
use App\Tests\Support\ParsesHtml;
use Dom\Element;
use PHPUnit\Framework\TestCase;

final class SubstantialProseDetectorTest extends TestCase
{
    use ParsesHtml;

    private SubstantialProseDetector $prose;

    protected function setUp(): void
    {
        $this->prose = new SubstantialProseDetector(new LinkListDetector());
    }

    public function testTwoHundredCharactersOfProseAreSubstantial(): void
    {
        self::assertTrue($this->prose->isSubstantial($this->paragraph(str_repeat('ä', 200))));
    }

    public function testOneHundredNinetyNineCharactersAreNot(): void
    {
        self::assertFalse($this->prose->isSubstantial($this->paragraph(str_repeat('ä', 199))));
    }

    public function testProseWithAMinorityOfLinkTextIsSubstantial(): void
    {
        $paragraph = $this->paragraph(str_repeat('ä', 200) . ' <a href="https://pub.test/more">more</a>');

        self::assertTrue($this->prose->isSubstantial($paragraph));
    }

    public function testALinkDominatedBlockIsNeverSubstantial(): void
    {
        $paragraph = $this->paragraph('<a href="https://pub.test/next">' . str_repeat('ä', 300) . '</a>');

        self::assertFalse($this->prose->isSubstantial($paragraph));
    }

    private function paragraph(string $inner): Element
    {
        $paragraph = $this->document('<body><p>' . $inner . '</p></body>')->querySelector('p');
        self::assertInstanceOf(Element::class, $paragraph);

        return $paragraph;
    }
}
```

```bash
php bin/phpunit tests/Service/Reader/SubstantialProseDetectorTest.php
```
Expected FAIL: `Error: Class "App\Service\Reader\SubstantialProseDetector" not found`.

- [ ] **Step 2: The detector**

Create `backend/src/Service/Reader/SubstantialProseDetector.php`:

```php
<?php

declare(strict_types=1);

namespace App\Service\Reader;

use App\Service\Reader\Support\BlockText;
use Dom\Element;

/**
 * Prose long enough to anchor an article's edge or mark its body. A link-dominated block of any length is a list,
 * not prose, so a teaser carousel cannot shield itself (#779).
 */
final readonly class SubstantialProseDetector
{
    private const int MIN_LENGTH = 200;

    public function __construct(private LinkListDetector $linkLists)
    {
    }

    public function isSubstantial(Element $block): bool
    {
        return mb_strlen(BlockText::collapsed($block)) >= self::MIN_LENGTH
            && !$this->linkLists->isLinkDominated($block);
    }
}
```

```bash
php bin/phpunit tests/Service/Reader/SubstantialProseDetectorTest.php
```
Expected: `OK (4 tests, 8 assertions)` (each test also counts the helper's `assertInstanceOf`).

- [ ] **Step 3: `EdgeBoilerplateTrimmer` uses it**

Edit 1. Before:
```php
use App\Service\Reader\BodyCleaning\Pass\BodyCleaningPass;
use App\Service\Reader\BoilerplateVerdict;
use App\Service\Reader\LinkListDetector;
use App\Service\Reader\Support\BlockText;
use Dom\Element;
```
After:
```php
use App\Service\Reader\BodyCleaning\Pass\BodyCleaningPass;
use App\Service\Reader\BoilerplateVerdict;
use App\Service\Reader\SubstantialProseDetector;
use Dom\Element;
```

Edit 2. Before:
```php
{
    /** Characters of text that mark a block as a real, substantial paragraph. */
    private const int SUBSTANTIAL_PROSE_LENGTH = 200;

    public function __construct(
        private BoilerplateVerdict $verdict,
        private LinkListDetector $linkLists,
    ) {
    }
```
After:
```php
{
    public function __construct(
        private BoilerplateVerdict $verdict,
        private SubstantialProseDetector $prose,
    ) {
    }
```

Edit 3. Before:
```php
            if ($this->isSubstantialProse($block)) {
                $indexes[] = $index;
            }
        }

        return $indexes;
    }

    /**
     * Prose long enough to anchor an edge. A link-dominated block of any length
     * is a list, not prose, so a teaser carousel cannot shield itself (#779).
     */
    private function isSubstantialProse(Element $block): bool
    {
        return mb_strlen(BlockText::collapsed($block)) >= self::SUBSTANTIAL_PROSE_LENGTH
            && !$this->linkLists->isLinkDominated($block);
    }
}
```
After:
```php
            if ($this->prose->isSubstantial($block)) {
                $indexes[] = $index;
            }
        }

        return $indexes;
    }
}
```

- [ ] **Step 4: `AuthorBioSeparator` uses it**

Edit 1. Before:
```php
use App\Service\Reader\AuthorBio\AuthorProfileLink;
use App\Service\Reader\BodyCleaning\Pass\BodyCleaningPass;
use App\Service\Reader\LinkListDetector;
use App\Service\Reader\Support\BlockText;
use Dom\Element;
```
After:
```php
use App\Service\Reader\AuthorBio\AuthorProfileLink;
use App\Service\Reader\BodyCleaning\Pass\BodyCleaningPass;
use App\Service\Reader\SubstantialProseDetector;
use Dom\Element;
```

Edit 2. Before:
```php
{
    private const int SUBSTANTIAL_PROSE_LENGTH = 200;

    public function __construct(
        private AuthorProfileLink $authorProfileLink,
        private LinkListDetector $linkLists,
    ) {
    }
```
After:
```php
{
    public function __construct(
        private AuthorProfileLink $authorProfileLink,
        private SubstantialProseDetector $prose,
    ) {
    }
```

Edit 3. Before:
```php
            if ($this->isSubstantialProse($paragraph)) {
                ++$count;
            }
        }

        return $count;
    }

    private function isSubstantialProse(Element $paragraph): bool
    {
        return mb_strlen(BlockText::collapsed($paragraph)) >= self::SUBSTANTIAL_PROSE_LENGTH
            && !$this->linkLists->isLinkDominated($paragraph);
    }
```
After:
```php
            if ($this->prose->isSubstantial($paragraph)) {
                ++$count;
            }
        }

        return $count;
    }
```

- [ ] **Step 5: The tests construct the new collaborator**

`tests/Service/Reader/BodyCleaning/BodyCleaningStep/EdgeBoilerplateTrimmerTest.php`, edit 1. Before:
```php
use App\Service\Reader\BoilerplateVerdict;
use App\Service\Reader\LinkListDetector;
use App\Tests\Support\BodyCleaningPasses;
```
After:
```php
use App\Service\Reader\BoilerplateVerdict;
use App\Service\Reader\LinkListDetector;
use App\Service\Reader\SubstantialProseDetector;
use App\Tests\Support\BodyCleaningPasses;
```
Edit 2. Before:
```php
        $this->trimmer = new EdgeBoilerplateTrimmer(
            new BoilerplateVerdict(new LinkListDetector()),
            new LinkListDetector(),
        );
```
After:
```php
        $this->trimmer = new EdgeBoilerplateTrimmer(
            new BoilerplateVerdict(new LinkListDetector()),
            new SubstantialProseDetector(new LinkListDetector()),
        );
```

`tests/Service/Reader/BodyCleaning/BodyCleaningStep/AuthorBioSeparatorTest.php`, edit 1. Before:
```php
use App\Service\Reader\BodyCleaning\BodyCleaningStep\AuthorBioSeparator;
use App\Service\Reader\LinkListDetector;
use App\Tests\Support\BodyCleaningPasses;
```
After:
```php
use App\Service\Reader\BodyCleaning\BodyCleaningStep\AuthorBioSeparator;
use App\Service\Reader\LinkListDetector;
use App\Service\Reader\SubstantialProseDetector;
use App\Tests\Support\BodyCleaningPasses;
```
Edit 2. Before:
```php
        $this->separator = new AuthorBioSeparator(new AuthorProfileLink(), new LinkListDetector());
```
After:
```php
        $this->separator = new AuthorBioSeparator(
            new AuthorProfileLink(),
            new SubstantialProseDetector(new LinkListDetector()),
        );
```

`tests/Service/Reader/ReaderBodyCleanerTest.php`, edit 1. Before:
```php
use App\Service\Reader\Slideshow\SlideshowMarkup;
use App\Tests\Support\BodyCleaningInputs;
```
After:
```php
use App\Service\Reader\Slideshow\SlideshowMarkup;
use App\Service\Reader\SubstantialProseDetector;
use App\Tests\Support\BodyCleaningInputs;
```
Edit 2. Before:
```php
            new EdgeBoilerplateTrimmer(new BoilerplateVerdict(new LinkListDetector()), new LinkListDetector()),
```
After:
```php
            new EdgeBoilerplateTrimmer(
                new BoilerplateVerdict(new LinkListDetector()),
                new SubstantialProseDetector(new LinkListDetector()),
            ),
```
Edit 3. Before:
```php
            new AuthorBioSeparator(new AuthorProfileLink(), new LinkListDetector()),
```
After:
```php
            new AuthorBioSeparator(new AuthorProfileLink(), new SubstantialProseDetector(new LinkListDetector())),
```

- [ ] **Step 6: Tests pass**

```bash
php bin/phpunit --filter '(SubstantialProseDetectorTest|EdgeBoilerplateTrimmerTest|AuthorBioSeparatorTest|ReaderBodyCleanerTest|ReaderBodyCleanerWiringTest|ArticleExtractorTest|SlideshowModelExtractionTest|EveryApplicationServiceBuildsTest)'
bin/console lint:container
```
Expected: `OK`, then `[OK] The container was linted successfully`.

- [ ] **Step 7: Deletion checks**

(a) In `SubstantialProseDetector.php`, change `>= self::MIN_LENGTH` to `> self::MIN_LENGTH`.
```bash
php bin/phpunit --filter 'SubstantialProseDetectorTest::testTwoHundredCharactersOfProseAreSubstantial'
```
Expected FAIL: `Failed asserting that false is true.` Restore by editing it back to `>= self::MIN_LENGTH`.

(b) In `SubstantialProseDetector.php`, replace
```php
        return mb_strlen(BlockText::collapsed($block)) >= self::MIN_LENGTH
            && !$this->linkLists->isLinkDominated($block);
```
with
```php
        return mb_strlen(BlockText::collapsed($block)) >= self::MIN_LENGTH;
```
```bash
php bin/phpunit --filter 'SubstantialProseDetectorTest::testALinkDominatedBlockIsNeverSubstantial'
```
Expected FAIL: `Failed asserting that true is false.` Restore by re-applying Step 2's two-line `return`.

(c) The trimmers delegate. In `EdgeBoilerplateTrimmer.php`, change `if ($this->prose->isSubstantial($block)) {` to `if (!$this->prose->isSubstantial($block)) {`.
```bash
php bin/phpunit tests/Service/Reader/BodyCleaning/BodyCleaningStep/EdgeBoilerplateTrimmerTest.php
```
Expected: at least one FAIL (`Failed asserting that …`). Quote the first. Restore by removing the `!`.

- [ ] **Step 8: One check left in the two trimmers (from `backend/`)**

```bash
git grep -n -E 'SUBSTANTIAL_PROSE_LENGTH|isSubstantialProse' -- src/Service/Reader
```
Expected: exactly four lines, all in `NavigationChromeTrimmer.php` (`:35`, `:149`) and `RelatedTeaserGridRemover.php` (`:23`, `:132`). They are the positive control, and D-2 keeps them. No line names `EdgeBoilerplateTrimmer.php` or `AuthorBioSeparator.php`.

- [ ] **Step 9: Harness: no output change (from `backend/`)**

```bash
OTEL_PHP_DISABLED_INSTRUMENTATIONS=all php -d display_errors=stderr var/reader-1268/snapshot.php var/reader-1268/after-task-2 > var/reader-1268/after-task-2.txt 2> var/reader-1268/after-task-2.err
diff -rq var/reader-1268/after-task-1 var/reader-1268/after-task-2
```
Expected: no output.

- [ ] **Step 10: Lint and commit**

```bash
for file in src/Service/Reader/SubstantialProseDetector.php src/Service/Reader/BodyCleaning/BodyCleaningStep/EdgeBoilerplateTrimmer.php src/Service/Reader/BodyCleaning/BodyCleaningStep/AuthorBioSeparator.php tests/Service/Reader/SubstantialProseDetectorTest.php tests/Service/Reader/BodyCleaning/BodyCleaningStep/EdgeBoilerplateTrimmerTest.php tests/Service/Reader/BodyCleaning/BodyCleaningStep/AuthorBioSeparatorTest.php tests/Service/Reader/ReaderBodyCleanerTest.php; do php -l "$file"; done
vendor/bin/phpcs src/Service/Reader/SubstantialProseDetector.php src/Service/Reader/BodyCleaning/BodyCleaningStep/EdgeBoilerplateTrimmer.php src/Service/Reader/BodyCleaning/BodyCleaningStep/AuthorBioSeparator.php tests/Service/Reader/SubstantialProseDetectorTest.php tests/Service/Reader/BodyCleaning/BodyCleaningStep/EdgeBoilerplateTrimmerTest.php tests/Service/Reader/BodyCleaning/BodyCleaningStep/AuthorBioSeparatorTest.php tests/Service/Reader/ReaderBodyCleanerTest.php
git add src/Service/Reader/SubstantialProseDetector.php src/Service/Reader/BodyCleaning/BodyCleaningStep/EdgeBoilerplateTrimmer.php src/Service/Reader/BodyCleaning/BodyCleaningStep/AuthorBioSeparator.php tests/Service/Reader/SubstantialProseDetectorTest.php tests/Service/Reader/BodyCleaning/BodyCleaningStep/EdgeBoilerplateTrimmerTest.php tests/Service/Reader/BodyCleaning/BodyCleaningStep/AuthorBioSeparatorTest.php tests/Service/Reader/ReaderBodyCleanerTest.php
git commit -m "refactor(#1268): one substantial-prose check for the edge trimmer and the author-bio separator"
```

---

### Task 3: `EmptiedWrapperRemover` and its test

**Files:**
- Create: `backend/src/Service/Reader/EmptiedWrapperRemover.php`
- Create: `backend/tests/Service/Reader/EmptiedWrapperRemoverTest.php`

Nothing calls the service yet, so reader output cannot change in this task.

- [ ] **Step 1: The failing test**

Create `backend/tests/Service/Reader/EmptiedWrapperRemoverTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Service\Reader;

use App\Service\Reader\EmptiedWrapperRemover;
use App\Tests\Support\ParsesHtml;
use Dom\Element;
use Dom\HTMLDocument;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class EmptiedWrapperRemoverTest extends TestCase
{
    use ParsesHtml;

    private EmptiedWrapperRemover $remover;

    protected function setUp(): void
    {
        $this->remover = new EmptiedWrapperRemover();
    }

    public function testRemovesTheNodeAndEveryWrapperItLeavesEmpty(): void
    {
        $document = $this->page('<div class="outer"><div class="inner"><p id="gone">Dek.</p></div></div><p>Story.</p>');

        $this->remover->removeWithEmptiedWrappers($this->element($document, '#gone'));

        $html = $document->saveHtml();
        self::assertStringContainsString('Story.', $html);
        self::assertStringNotContainsString('Dek.', $html);
        self::assertStringNotContainsString('inner', $html);
        self::assertStringNotContainsString('outer', $html);
    }

    public function testStopsAtTheFirstWrapperThatStillHoldsText(): void
    {
        $document = $this->page('<div class="kept">Caption <div class="inner"><p id="gone">Dek.</p></div></div>');

        $this->remover->removeWithEmptiedWrappers($this->element($document, '#gone'));

        $html = $document->saveHtml();
        self::assertStringContainsString('class="kept"', $html);
        self::assertStringNotContainsString('inner', $html);
    }

    /** An emptied section would render as an empty inset card. */
    public function testDissolvesEmptiedSectioningElements(): void
    {
        $document = $this->page('<main><article><section><p id="gone">Dek.</p></section></article></main><p>Story.</p>');

        $this->remover->removeWithEmptiedWrappers($this->element($document, '#gone'));

        $html = $document->saveHtml();
        self::assertStringContainsString('Story.', $html);
        self::assertStringNotContainsString('<section', $html);
        self::assertStringNotContainsString('<article', $html);
        self::assertStringNotContainsString('<main', $html);
    }

    public function testNeverRemovesTheBody(): void
    {
        $document = $this->page('<p id="gone">Dek.</p>');

        $this->remover->removeWithEmptiedWrappers($this->element($document, '#gone'));

        self::assertStringContainsString('<body></body>', $document->saveHtml());
    }

    public function testLeavesAnEmptiedElementOutsideTheBodyAlone(): void
    {
        $document = $this->document(
            '<html lang="en"><head><title id="empty"></title></head><body><p>Story.</p></body></html>'
        );

        $this->remover->removeIfEmptied($this->element($document, '#empty'));

        self::assertStringContainsString('<title id="empty"></title>', $document->saveHtml());
    }

    public function testNeverRemovesTheDocumentElement(): void
    {
        $document = $this->document('<html lang="en"><head></head><body></body></html>');

        $this->remover->removeIfEmptied($this->element($document, 'html'));

        self::assertStringContainsString('<html lang="en">', $document->saveHtml());
    }

    public function testTreatsANonBreakingSpaceAsEmpty(): void
    {
        $document = $this->page('<div class="wrapper"><p id="gone">Dek.</p>&nbsp;</div><p>Story.</p>');

        $this->remover->removeWithEmptiedWrappers($this->element($document, '#gone'));

        $html = $document->saveHtml();
        self::assertStringContainsString('Story.', $html);
        self::assertStringNotContainsString('wrapper', $html);
    }

    #[DataProvider('contentWithoutText')]
    public function testKeepsAWrapperThatStillHoldsContentWithoutText(string $content): void
    {
        $document = $this->page('<div class="wrapper"><p id="gone">Dek.</p>' . $content . '</div><p>Story.</p>');

        $this->remover->removeWithEmptiedWrappers($this->element($document, '#gone'));

        self::assertStringContainsString('class="wrapper"', $document->saveHtml());
    }

    /** @return iterable<string, array{string}> */
    public static function contentWithoutText(): iterable
    {
        yield 'image' => ['<img src="https://pub.test/photo.jpg" alt="">'];
        yield 'picture' => ['<picture><source srcset="https://pub.test/photo.webp"></picture>'];
        yield 'svg' => ['<svg viewBox="0 0 10 10"><path d="M0 0h10v10z"></path></svg>'];
        yield 'video' => ['<video src="https://pub.test/clip.mp4"></video>'];
        yield 'audio' => ['<audio src="https://pub.test/talk.mp3"></audio>'];
        yield 'iframe' => ['<iframe src="https://pub.test/embed"></iframe>'];
    }

    #[DataProvider('blankElements')]
    public function testRemovesAWrapperLeftWithOnlyABlankElement(string $blank): void
    {
        $document = $this->page('<div class="wrapper"><p id="gone">Dek.</p>' . $blank . '</div><p>Story.</p>');

        $this->remover->removeWithEmptiedWrappers($this->element($document, '#gone'));

        $html = $document->saveHtml();
        self::assertStringContainsString('Story.', $html);
        self::assertStringNotContainsString('wrapper', $html);
    }

    /** @return iterable<string, array{string}> */
    public static function blankElements(): iterable
    {
        yield 'line break' => ['<br>'];
        yield 'rule' => ['<hr>'];
        yield 'form control' => ['<input type="checkbox">'];
        yield 'orphan source' => ['<source src="https://pub.test/clip.mp4">'];
    }

    public function testKeepsAnEmptiedElementThatIsItselfAPlayer(): void
    {
        $document = $this->page('<video src="https://pub.test/clip.mp4"><p id="gone">0:00</p></video><p>Story.</p>');

        $this->remover->removeWithEmptiedWrappers($this->element($document, '#gone'));

        $html = $document->saveHtml();
        self::assertStringContainsString('<video', $html);
        self::assertStringNotContainsString('0:00', $html);
    }

    public function testRemovesAnElementThatHoldsNothingAndTheWrappersItEmpties(): void
    {
        $document = $this->page('<div class="outer"><p id="holder"> </p></div><p>Story.</p>');

        $this->remover->removeIfEmptied($this->element($document, '#holder'));

        $html = $document->saveHtml();
        self::assertStringContainsString('Story.', $html);
        self::assertStringNotContainsString('holder', $html);
        self::assertStringNotContainsString('outer', $html);
    }

    public function testKeepsAnElementThatStillHoldsText(): void
    {
        $document = $this->page('<p id="holder">Kept words.</p>');

        $this->remover->removeIfEmptied($this->element($document, '#holder'));

        self::assertStringContainsString('Kept words.', $document->saveHtml());
    }

    private function page(string $bodyHtml): HTMLDocument
    {
        return $this->document('<html lang="en"><head><title>Page</title></head><body>' . $bodyHtml . '</body></html>');
    }

    private function element(HTMLDocument $document, string $selector): Element
    {
        $element = $document->querySelector($selector);
        self::assertInstanceOf(Element::class, $element);

        return $element;
    }
}
```

```bash
php bin/phpunit tests/Service/Reader/EmptiedWrapperRemoverTest.php
```
Expected FAIL: `Error: Class "App\Service\Reader\EmptiedWrapperRemover" not found`.

- [ ] **Step 2: The walk**

Create `backend/src/Service/Reader/EmptiedWrapperRemover.php`:

```php
<?php

declare(strict_types=1);

namespace App\Service\Reader;

use App\Service\Text\Support\Whitespace;
use Dom\Element;

/** Removes what a removal leaves empty, wrapper by wrapper, up to but never including <body>. */
final readonly class EmptiedWrapperRemover
{
    /** Content without text; a <br>, an <hr> or a form control alone leaves a wrapper empty. */
    private const string CONTENT_WITHOUT_TEXT = 'img, picture, svg, video, audio, iframe';

    public function removeWithEmptiedWrappers(Element $node): void
    {
        $wrapper = $node->parentElement;
        $node->remove();
        if ($wrapper !== null) {
            $this->removeIfEmptied($wrapper);
        }
    }

    /** Removes the element when it holds nothing, then each ancestor that leaves empty. */
    public function removeIfEmptied(Element $element): void
    {
        if ($this->isEmptied($element)) {
            $this->removeWithEmptiedWrappers($element);
        }
    }

    private function isEmptied(Element $element): bool
    {
        return $this->isInsideBody($element)
            && Whitespace::collapse($element->textContent) === ''
            && !$this->isOrHoldsContent($element);
    }

    private function isInsideBody(Element $element): bool
    {
        return $element->parentElement?->closest('body') !== null;
    }

    private function isOrHoldsContent(Element $element): bool
    {
        return $element->matches(self::CONTENT_WITHOUT_TEXT)
            || $element->querySelector(self::CONTENT_WITHOUT_TEXT) !== null;
    }
}
```

```bash
php bin/phpunit tests/Service/Reader/EmptiedWrapperRemoverTest.php
bin/console lint:container
```
Expected: `OK (20 tests, …)` (10 plain tests plus 6 + 4 data sets), then the container lint OK.

- [ ] **Step 3: Deletion checks (each restored by re-applying Step 2's text with the Edit tool)**

(a) Body boundary. Replace
```php
        return $this->isInsideBody($element)
            && Whitespace::collapse($element->textContent) === ''
```
with
```php
        return Whitespace::collapse($element->textContent) === ''
```
```bash
php bin/phpunit --filter 'EmptiedWrapperRemoverTest::(testNeverRemovesTheBody|testLeavesAnEmptiedElementOutsideTheBodyAlone)'
```
Expected FAIL (both): `Failed asserting that '` … `' contains "<body></body>".` and `… contains "<title id="empty"></title>".` Restore.

(b) Null-safe climb. Replace `$element->parentElement?->closest('body')` with `$element->parentElement->closest('body')`.
```bash
php bin/phpunit --filter 'EmptiedWrapperRemoverTest::testNeverRemovesTheDocumentElement'
```
Expected: `Error: Call to a member function closest() on null`. Restore the `?->`.

(c) Unicode blanks. Replace `Whitespace::collapse($element->textContent) === ''` with `trim((string) $element->textContent) === ''`.
```bash
php bin/phpunit --filter 'EmptiedWrapperRemoverTest::testTreatsANonBreakingSpaceAsEmpty'
```
Expected FAIL: `Failed asserting that '` … `' does not contain "wrapper".` Restore.

(d) The tag list. Change the constant to `'img, svg, video, audio, iframe'` (no `picture`).
```bash
php bin/phpunit --filter 'EmptiedWrapperRemoverTest::testKeepsAWrapperThatStillHoldsContentWithoutText'
```
Expected FAIL: `… with data set "picture"` and `Failed asserting that '` … `' contains "class="wrapper"".` Restore.

(e) A `<br>` is blank. Change the constant to `'img, picture, svg, video, audio, iframe, br'`.
```bash
php bin/phpunit --filter 'EmptiedWrapperRemoverTest::testRemovesAWrapperLeftWithOnlyABlankElement'
```
Expected FAIL: `… with data set "line break"` and `… does not contain "wrapper".` Restore.

(f) The element itself. Replace
```php
        return $element->matches(self::CONTENT_WITHOUT_TEXT)
            || $element->querySelector(self::CONTENT_WITHOUT_TEXT) !== null;
```
with
```php
        return $element->querySelector(self::CONTENT_WITHOUT_TEXT) !== null;
```
```bash
php bin/phpunit --filter 'EmptiedWrapperRemoverTest::testKeepsAnEmptiedElementThatIsItselfAPlayer'
```
Expected FAIL: `Failed asserting that '` … `' contains "<video".` Restore.

(g) Sections dissolve (I-1). Replace `        return $this->isInsideBody($element)` with `        return !in_array($element->localName, ['article', 'main', 'section'], true) && $this->isInsideBody($element)`.
```bash
php bin/phpunit --filter 'EmptiedWrapperRemoverTest::testDissolvesEmptiedSectioningElements'
```
Expected FAIL: `Failed asserting that '` … `' does not contain "<section".` Restore.

After restoring all seven, rerun `php bin/phpunit tests/Service/Reader/EmptiedWrapperRemoverTest.php` and expect `OK`.

- [ ] **Step 4: Lint and commit**

```bash
php -l src/Service/Reader/EmptiedWrapperRemover.php
php -l tests/Service/Reader/EmptiedWrapperRemoverTest.php
vendor/bin/phpcs src/Service/Reader/EmptiedWrapperRemover.php tests/Service/Reader/EmptiedWrapperRemoverTest.php
git add src/Service/Reader/EmptiedWrapperRemover.php tests/Service/Reader/EmptiedWrapperRemoverTest.php
git commit -m "refactor(#1268): one walk that removes the wrappers a removal leaves empty"
```

---

### Task 4: `DuplicateBlockCollapser` uses the walk

**Files:**
- Modify: `backend/src/Service/Reader/BodyCleaning/BodyCleaningStep/DuplicateBlockCollapser.php`
- Modify: `backend/tests/Service/Reader/BodyCleaning/BodyCleaningStep/DuplicateBlockCollapserTest.php`
- Modify: `backend/tests/Service/Reader/ReaderBodyCleanerTest.php`

Intended changes for walk A: I-1 (an emptied section goes), I-2, I-4.

- [ ] **Step 1: The failing test: the reversed section pin**

`tests/Service/Reader/BodyCleaning/BodyCleaningStep/DuplicateBlockCollapserTest.php`. Before:
```php
    /** A structural section is never dissolved, even when a collapse leaves it empty. */
    public function testKeepsAStructuralSectionLeftEmptyByACollapse(): void
    {
        $html = $this->collapsed(
            '<section><p>Same dek line.</p></section><section><p>Same dek line.</p></section>'
        );

        self::assertSame(1, substr_count($html, 'Same dek line.'));
        self::assertSame(2, substr_count($html, '<section'));
    }
```
After:
```php
    /** A section a collapse leaves empty goes too, or the reader would draw it as an empty inset card. */
    public function testDissolvesASectionLeftEmptyByACollapse(): void
    {
        $html = $this->collapsed(
            '<section><p>Same dek line.</p></section><section><p>Same dek line.</p></section>'
        );

        self::assertSame(1, substr_count($html, 'Same dek line.'));
        self::assertSame(1, substr_count($html, '<section'));
    }
```

```bash
php bin/phpunit --filter 'DuplicateBlockCollapserTest::testDissolvesASectionLeftEmptyByACollapse'
```
Expected FAIL: `Failed asserting that 2 is identical to 1.`

- [ ] **Step 2: The collapser delegates**

`src/Service/Reader/BodyCleaning/BodyCleaningStep/DuplicateBlockCollapser.php`, edit 1. Before:
```php
use App\Service\Reader\BodyCleaning\Pass\BodyCleaningPass;
use App\Service\Reader\Media\EmbedProviders;
```
After:
```php
use App\Service\Reader\BodyCleaning\Pass\BodyCleaningPass;
use App\Service\Reader\EmptiedWrapperRemover;
use App\Service\Reader\Media\EmbedProviders;
```

Edit 2. Before:
```php
    public function __construct(private EmbedProviders $embedProviders)
    {
    }
```
After:
```php
    public function __construct(
        private EmbedProviders $embedProviders,
        private EmptiedWrapperRemover $wrapperRemover,
    ) {
    }
```

Edit 3. Before:
```php
                $this->removeBlock($paragraph);
```
After:
```php
                $this->wrapperRemover->removeWithEmptiedWrappers($paragraph);
```

Edit 4. Before:
```php
    /** Remove the node, then the wrappers it leaves empty, so no blank paragraph or box survives. */
    private function removeBlock(Element $node): void
    {
        $parent = $node->parentElement;
        $node->remove();
        while ($parent instanceof Element && $this->isEmptyWrapper($parent)) {
            $grandparent = $parent->parentElement;
            $parent->remove();
            $parent = $grandparent;
        }
    }

    /** A structural root is never dissolved; a wrapper with no text and no media is. */
    private function isEmptyWrapper(Element $element): bool
    {
        if (in_array(strtoupper($element->nodeName), ['BODY', 'ARTICLE', 'MAIN', 'SECTION'], true)) {
            return false;
        }

        return trim((string) $element->textContent) === ''
            && $element->querySelector('img, picture, video, iframe, audio, svg') === null;
    }

    private function normalize(string $text): string
```
After:
```php
    private function normalize(string $text): string
```

- [ ] **Step 3: The tests construct the walk**

`DuplicateBlockCollapserTest.php`, edit 1. Before:
```php
use App\Service\Reader\BodyCleaning\BodyCleaningStep\DuplicateBlockCollapser;
use App\Service\Reader\Media\EmbedProvider\VimeoEmbedProvider;
```
After:
```php
use App\Service\Reader\BodyCleaning\BodyCleaningStep\DuplicateBlockCollapser;
use App\Service\Reader\EmptiedWrapperRemover;
use App\Service\Reader\Media\EmbedProvider\VimeoEmbedProvider;
```
Edit 2. Before:
```php
        $this->collapser = new DuplicateBlockCollapser(
            new EmbedProviders([new YouTubeEmbedProvider(), new VimeoEmbedProvider()]),
        );
```
After:
```php
        $this->collapser = new DuplicateBlockCollapser(
            new EmbedProviders([new YouTubeEmbedProvider(), new VimeoEmbedProvider()]),
            new EmptiedWrapperRemover(),
        );
```

`tests/Service/Reader/ReaderBodyCleanerTest.php`, edit 1. Before:
```php
use App\Service\Reader\BoilerplateVerdict;
use App\Service\Reader\LinkListDetector;
```
After:
```php
use App\Service\Reader\BoilerplateVerdict;
use App\Service\Reader\EmptiedWrapperRemover;
use App\Service\Reader\LinkListDetector;
```
Edit 2. Before:
```php
            new DuplicateBlockCollapser($embedProviders),
```
After:
```php
            new DuplicateBlockCollapser($embedProviders, new EmptiedWrapperRemover()),
```

- [ ] **Step 4: Tests pass**

```bash
php bin/phpunit --filter '(DuplicateBlockCollapserTest|ReaderBodyCleanerTest|ReaderBodyCleanerWiringTest|ArticleExtractorTest|SlideshowModelExtractionTest|EveryApplicationServiceBuildsTest)'
bin/console lint:container
```
Expected: `OK`, then the container lint OK.

- [ ] **Step 5: Deletion check: the collapser delegates**

In `DuplicateBlockCollapser.php`, replace `                $this->wrapperRemover->removeWithEmptiedWrappers($paragraph);` with `                $paragraph->remove();`.
```bash
php bin/phpunit --filter 'DuplicateBlockCollapserTest::testDissolvesASectionLeftEmptyByACollapse'
```
Expected FAIL: `Failed asserting that 2 is identical to 1.` Restore by editing the line back.

- [ ] **Step 6: Harness: only intended changes (from `backend/`)**

```bash
OTEL_PHP_DISABLED_INSTRUMENTATIONS=all php -d display_errors=stderr var/reader-1268/snapshot.php var/reader-1268/after-task-4 > var/reader-1268/after-task-4.txt 2> var/reader-1268/after-task-4.err
diff -rq var/reader-1268/after-task-2 var/reader-1268/after-task-4
```
Expected: no output, or files whose every hunk (`diff -u var/reader-1268/after-task-2/<file> var/reader-1268/after-task-4/<file>`) is explained by I-1 (an emptied `<section>`, `<article>` or `<main>` is gone from `--- content`), I-2 (a wrapper that held only `&nbsp;` is gone) or I-4. The `--- normalized` part cannot change in this task. In the task report, list each changed page with its I-id. An unexplained hunk means stop (Global Constraints).

- [ ] **Step 7: Lint and commit**

```bash
php -l src/Service/Reader/BodyCleaning/BodyCleaningStep/DuplicateBlockCollapser.php
php -l tests/Service/Reader/BodyCleaning/BodyCleaningStep/DuplicateBlockCollapserTest.php
php -l tests/Service/Reader/ReaderBodyCleanerTest.php
vendor/bin/phpcs src/Service/Reader/BodyCleaning/BodyCleaningStep/DuplicateBlockCollapser.php tests/Service/Reader/BodyCleaning/BodyCleaningStep/DuplicateBlockCollapserTest.php tests/Service/Reader/ReaderBodyCleanerTest.php
git add src/Service/Reader/BodyCleaning/BodyCleaningStep/DuplicateBlockCollapser.php tests/Service/Reader/BodyCleaning/BodyCleaningStep/DuplicateBlockCollapserTest.php tests/Service/Reader/ReaderBodyCleanerTest.php
git commit -m "refactor(#1268): the duplicate collapser removes emptied wrappers through the shared walk"
```

---

### Task 5: `PlayerChromeCleaner` uses the walk

**Files:**
- Modify: `backend/src/Service/Reader/BodyCleaning/BodyCleaningStep/PlayerChromeCleaner.php`
- Modify: `backend/tests/Service/Reader/BodyCleaning/BodyCleaningStep/PlayerChromeCleanerTest.php`
- Modify: `backend/tests/Service/Reader/ReaderBodyCleanerTest.php`

Intended changes for walk B: I-3 (a wrapper holding a `<picture>` stays) and I-4. `EmptiedWrapperRemoverTest` pins both. The cleaner's own region, embed-widget and narration tests pin the delegation.

- [ ] **Step 1: The cleaner delegates**

`src/Service/Reader/BodyCleaning/BodyCleaningStep/PlayerChromeCleaner.php`, edit 1. Before:
```php
use App\Service\Reader\BodyCleaning\Pass\BodyCleaningPass;
use App\Service\Reader\Media\NarrationSignals;
use App\Service\Reader\Support\LeadingEngagementBlocks;
use App\Service\Text\Support\Whitespace;
use Dom\Element;
```
After:
```php
use App\Service\Reader\BodyCleaning\Pass\BodyCleaningPass;
use App\Service\Reader\EmptiedWrapperRemover;
use App\Service\Reader\Media\NarrationSignals;
use App\Service\Reader\Support\LeadingEngagementBlocks;
use Dom\Element;
```

Edit 2. Before:
```php
    private const array MEDIA_TAGS = ['img', 'audio', 'video', 'iframe', 'svg'];

    /** A code block whose literal text is an <iframe> embed snippet (src and
     *  all) is a "copy this embed" widget, not a code sample a reader wrote. */
    private const string EMBED_SNIPPET_PATTERN = '/<iframe\b[^>]*\bsrc=/i';

    public function __construct(private NarrationSignals $narration)
    {
    }
```
After:
```php
    /** A code block whose literal text is an <iframe> embed snippet (src and
     *  all) is a "copy this embed" widget, not a code sample a reader wrote. */
    private const string EMBED_SNIPPET_PATTERN = '/<iframe\b[^>]*\bsrc=/i';

    public function __construct(
        private NarrationSignals $narration,
        private EmptiedWrapperRemover $wrapperRemover,
    ) {
    }
```

Edit 3. Before:
```php
        foreach (LeadingEngagementBlocks::in($body) as $block) {
            if (preg_match(self::READOUT_PATTERN, $block->text) === 1) {
                $this->removeWithEmptiedWrappers($block->element, $body);
            }
        }
        foreach ($this->embedCodeBlocks($document) as $code) {
            $this->removeWithEmptiedWrappers($this->embedRow($code, $body) ?? $code, $body);
        }
        foreach ($this->silentNarrationWidgets($document) as $widget) {
            $this->removeWithEmptiedWrappers($widget, $body);
        }
```
After:
```php
        foreach (LeadingEngagementBlocks::in($body) as $block) {
            if (preg_match(self::READOUT_PATTERN, $block->text) === 1) {
                $this->wrapperRemover->removeWithEmptiedWrappers($block->element);
            }
        }
        foreach ($this->embedCodeBlocks($document) as $code) {
            $this->wrapperRemover->removeWithEmptiedWrappers($this->embedRow($code, $body) ?? $code);
        }
        foreach ($this->silentNarrationWidgets($document) as $widget) {
            $this->wrapperRemover->removeWithEmptiedWrappers($widget);
        }
```

Edit 4. Before:
```php
            || $player->getElementsByTagName('source')->length > 0;
    }

    private function removeWithEmptiedWrappers(Element $readout, Element $body): void
    {
        $wrapper = $readout->parentElement;
        $readout->remove();
        while ($wrapper !== null && $wrapper !== $body && $this->isEmptied($wrapper)) {
            $next = $wrapper->parentElement;
            $wrapper->remove();
            $wrapper = $next;
        }
    }

    private function isEmptied(Element $wrapper): bool
    {
        return Whitespace::collapse($wrapper->textContent) === ''
            && !array_any(
                self::MEDIA_TAGS,
                static fn (string $tag): bool => $wrapper->getElementsByTagName($tag)->length > 0,
            );
    }
}
```
After:
```php
            || $player->getElementsByTagName('source')->length > 0;
    }
}
```

- [ ] **Step 2: The tests construct the walk**

`tests/Service/Reader/BodyCleaning/BodyCleaningStep/PlayerChromeCleanerTest.php`, edit 1. Before:
```php
use App\Service\Reader\BodyCleaning\BodyCleaningStep\PlayerChromeCleaner;
use App\Service\Reader\Media\NarrationSignals;
```
After:
```php
use App\Service\Reader\BodyCleaning\BodyCleaningStep\PlayerChromeCleaner;
use App\Service\Reader\EmptiedWrapperRemover;
use App\Service\Reader\Media\NarrationSignals;
```
Edit 2. Before:
```php
        $this->cleaner = new PlayerChromeCleaner(new NarrationSignals());
```
After:
```php
        $this->cleaner = new PlayerChromeCleaner(new NarrationSignals(), new EmptiedWrapperRemover());
```

`tests/Service/Reader/ReaderBodyCleanerTest.php`. Before:
```php
            new PlayerChromeCleaner(new NarrationSignals()),
```
After:
```php
            new PlayerChromeCleaner(new NarrationSignals(), new EmptiedWrapperRemover()),
```

- [ ] **Step 3: Tests pass**

```bash
php bin/phpunit --filter '(PlayerChromeCleanerTest|ReaderBodyCleanerTest|ReaderBodyCleanerWiringTest|ArticleExtractorTest|SlideshowModelExtractionTest|EveryApplicationServiceBuildsTest)'
bin/console lint:container
```
Expected: `OK`, then the container lint OK.

- [ ] **Step 4: Deletion checks: each of the three removals delegates**

(a) Replace `                $this->wrapperRemover->removeWithEmptiedWrappers($block->element);` with `                $block->element->remove();`.
```bash
php bin/phpunit --filter 'PlayerChromeCleanerTest::testRemovesTheClockReadoutsBesideAPlayerWithTheirEmptiedRegion'
```
Expected FAIL: `Failed asserting that '` … `' does not contain "role="region"".` Restore.

(b) Replace `            $this->wrapperRemover->removeWithEmptiedWrappers($this->embedRow($code, $body) ?? $code);` with `            ($this->embedRow($code, $body) ?? $code)->remove();`.
```bash
php bin/phpunit --filter 'PlayerChromeCleanerTest::testRemovesTheNprEmbedCodeWidgetAndItsEmptiedWrappers'
```
Expected FAIL: `Failed asserting that '` … `' does not contain "<ul>".` Restore.

(c) Replace `            $this->wrapperRemover->removeWithEmptiedWrappers($widget);` with `            $widget->getAttribute('class');`.
```bash
php bin/phpunit --filter 'PlayerChromeCleanerTest::testRemovesASilentTextToSpeechWidgetWholeIconAndLabels'
```
Expected FAIL: `Failed asserting that '` … `' does not contain "texttospeech.svg".` Restore.

- [ ] **Step 5: Harness: only intended changes (from `backend/`)**

```bash
OTEL_PHP_DISABLED_INSTRUMENTATIONS=all php -d display_errors=stderr var/reader-1268/snapshot.php var/reader-1268/after-task-5 > var/reader-1268/after-task-5.txt 2> var/reader-1268/after-task-5.err
diff -rq var/reader-1268/after-task-4 var/reader-1268/after-task-5
```
Expected: no output, or files whose every hunk is explained by I-3 (a wrapper that holds a `<picture>` now stays in `--- content`) or I-4 (a player or frame that a readout emptied now stays). List them with their I-ids. An unexplained hunk means stop.

- [ ] **Step 6: Lint and commit**

```bash
php -l src/Service/Reader/BodyCleaning/BodyCleaningStep/PlayerChromeCleaner.php
php -l tests/Service/Reader/BodyCleaning/BodyCleaningStep/PlayerChromeCleanerTest.php
php -l tests/Service/Reader/ReaderBodyCleanerTest.php
vendor/bin/phpcs src/Service/Reader/BodyCleaning/BodyCleaningStep/PlayerChromeCleaner.php tests/Service/Reader/BodyCleaning/BodyCleaningStep/PlayerChromeCleanerTest.php tests/Service/Reader/ReaderBodyCleanerTest.php
git add src/Service/Reader/BodyCleaning/BodyCleaningStep/PlayerChromeCleaner.php tests/Service/Reader/BodyCleaning/BodyCleaningStep/PlayerChromeCleanerTest.php tests/Service/Reader/ReaderBodyCleanerTest.php
git commit -m "refactor(#1268): the player chrome cleaner removes emptied wrappers through the shared walk"
```

---

### Task 6: `OrphanIconGlyphRemover` uses the walk

**Files:**
- Modify: `backend/src/Service/Reader/PageRepair/OrphanIconGlyphRemover.php`
- Modify: `backend/tests/Service/Reader/PageRepair/OrphanIconGlyphRemoverTest.php`
- Modify: `backend/tests/Service/Reader/FetchedPageNormalizerTest.php`

Intended changes for walk C: I-1 (bounded by `body`), I-2, I-3 (`br`, `hr`, `input` and `source` no longer keep a holder), I-4.

- [ ] **Step 1: The failing test**

`tests/Service/Reader/PageRepair/OrphanIconGlyphRemoverTest.php`. Before:
```php
    public function testStripsAGlyphButKeepsTheTextAroundIt(): void
    {
        $html = $this->repaired("<p>Before\u{E80F}After</p>");

        self::assertStringNotContainsString("\u{E80F}", $html);
        self::assertStringContainsString('BeforeAfter', html_entity_decode($html));
    }
```
After:
```php
    public function testStripsAGlyphButKeepsTheTextAroundIt(): void
    {
        $html = $this->repaired("<p>Before\u{E80F}After</p>");

        self::assertStringNotContainsString("\u{E80F}", $html);
        self::assertStringContainsString('BeforeAfter', html_entity_decode($html));
    }

    public function testPrunesAHolderLeftWithOnlyALineBreak(): void
    {
        $html = $this->repaired("<p>Intro paragraph.</p><p><span>\u{E80F}</span><br></p>");

        self::assertStringContainsString('Intro paragraph.', $html);
        self::assertStringNotContainsString('<br>', $html);
    }
```

```bash
php bin/phpunit --filter 'OrphanIconGlyphRemoverTest::testPrunesAHolderLeftWithOnlyALineBreak'
```
Expected FAIL: `Failed asserting that '` … `' does not contain "<br>".`

- [ ] **Step 2: The remover delegates**

`src/Service/Reader/PageRepair/OrphanIconGlyphRemover.php`, edit 1. Before:
```php
namespace App\Service\Reader\PageRepair;

use Dom\Element;
```
After:
```php
namespace App\Service\Reader\PageRepair;

use App\Service\Reader\EmptiedWrapperRemover;
use Dom\Element;
```

Edit 2. Before:
```php
    private const string PRIVATE_USE_PATTERN = '/[\x{E000}-\x{F8FF}\x{F0000}-\x{FFFFD}\x{100000}-\x{10FFFD}]/u';

    /** Elements that carry content without text, so an empty one still counts. */
    private const array EMBEDDED_TAGS = [
        'img', 'picture', 'source', 'svg', 'video', 'audio', 'iframe', 'br', 'hr', 'input',
    ];

    public function repairIn(HTMLDocument $document): void
```
After:
```php
    private const string PRIVATE_USE_PATTERN = '/[\x{E000}-\x{F8FF}\x{F0000}-\x{FFFFD}\x{100000}-\x{10FFFD}]/u';

    public function __construct(private EmptiedWrapperRemover $wrapperRemover)
    {
    }

    public function repairIn(HTMLDocument $document): void
```

Edit 3. Before:
```php
        foreach ($emptiedHolders as $holder) {
            $this->pruneWhileEmpty($holder);
        }
```
After:
```php
        foreach ($emptiedHolders as $holder) {
            $this->wrapperRemover->removeIfEmptied($holder);
        }
```

Edit 4. Before:
```php
        return $nodes;
    }

    /**
     * Drop an element the glyph strip left empty, then walk up dropping each
     * ancestor the removal in turn empties — a pull-quote's icon <span> and the
     * <p> that held nothing else both go.
     */
    private function pruneWhileEmpty(Element $element): void
    {
        while (
            $element->parentNode !== null
            && trim((string) $element->textContent) === ''
            && !$this->holdsEmbeddedContent($element)
        ) {
            $parent = $element->parentNode;
            $parent->removeChild($element);
            if (!$parent instanceof Element) {
                return;
            }
            $element = $parent;
        }
    }

    private function holdsEmbeddedContent(Element $element): bool
    {
        foreach ($element->getElementsByTagName('*') as $descendant) {
            if (in_array($descendant->localName, self::EMBEDDED_TAGS, true)) {
                return true;
            }
        }

        return false;
    }
}
```
After:
```php
        return $nodes;
    }
}
```

- [ ] **Step 3: The tests construct the walk**

`OrphanIconGlyphRemoverTest.php`, edit 1. Before:
```php
use App\Service\Reader\PageRepair\OrphanIconGlyphRemover;
use App\Tests\Support\ParsesHtml;
```
After:
```php
use App\Service\Reader\EmptiedWrapperRemover;
use App\Service\Reader\PageRepair\OrphanIconGlyphRemover;
use App\Tests\Support\ParsesHtml;
```
Edit 2. Before:
```php
        $this->remover = new OrphanIconGlyphRemover();
```
After:
```php
        $this->remover = new OrphanIconGlyphRemover(new EmptiedWrapperRemover());
```

`tests/Service/Reader/FetchedPageNormalizerTest.php`, edit 1. Before:
```php
use App\Service\Html\PictureSources;
use App\Service\Reader\FetchedPageNormalizer;
```
After:
```php
use App\Service\Html\PictureSources;
use App\Service\Reader\EmptiedWrapperRemover;
use App\Service\Reader\FetchedPageNormalizer;
```
Edit 2. Before:
```php
            new OrphanIconGlyphRemover(),
```
After:
```php
            new OrphanIconGlyphRemover(new EmptiedWrapperRemover()),
```

- [ ] **Step 4: Tests pass**

```bash
php bin/phpunit --filter '(OrphanIconGlyphRemoverTest|FetchedPageNormalizerTest|ArticleExtractorTest|ArticleReadabilityTest|EveryApplicationServiceBuildsTest)'
bin/console lint:container
```
Expected: `OK` (the new test passes now), then the container lint OK.

- [ ] **Step 5: Deletion check: the remover delegates**

In `OrphanIconGlyphRemover.php`, replace `            $this->wrapperRemover->removeIfEmptied($holder);` with `            $holder->getAttribute('class');`.
```bash
php bin/phpunit --filter 'OrphanIconGlyphRemoverTest::testRemovesAnOrphanIconGlyphAndPrunesTheHoldersItEmpties'
```
Expected FAIL: `Failed asserting that '` … `' does not contain "<span>".` Restore by editing the line back.

- [ ] **Step 6: One walk left (from `backend/`)**

```bash
git grep -n -E 'function (removeBlock|isEmptyWrapper|removeWithEmptiedWrappers|isEmptied|pruneWhileEmpty|holdsEmbeddedContent)\(' -- src
git grep -n -E 'removeChild\(\$element\)' -- src/Service/Reader
```
Expected: exactly two lines, `src/Service/Reader/EmptiedWrapperRemover.php` with `public function removeWithEmptiedWrappers(` and `private function isEmptied(` (the positive control). The second command prints nothing. Its positive control is Task 1 Step 5's hit, which this task removed from the working tree but which the last commit still holds: `git grep -n -E 'removeChild\(\$element\)' HEAD -- src/Service/Reader` prints `HEAD:src/Service/Reader/PageRepair/OrphanIconGlyphRemover.php:77:            $parent->removeChild($element);`.

- [ ] **Step 7: Harness: only intended changes (from `backend/`)**

```bash
OTEL_PHP_DISABLED_INSTRUMENTATIONS=all php -d display_errors=stderr var/reader-1268/snapshot.php var/reader-1268/after-task-6 > var/reader-1268/after-task-6.txt 2> var/reader-1268/after-task-6.err
diff -rq var/reader-1268/after-task-5 var/reader-1268/after-task-6
```
Expected: no output, or files whose every hunk is explained by I-1 (a glyph-only `<title>` or `<head>` element now stays in `--- normalized`), I-2, I-3 (a glyph holder left with only a `<br>`, `<hr>`, `<input>` or `<source>` is gone) or I-4. Only pages that contain Private Use Area characters can change. List them with their I-ids. An unexplained hunk means stop.

- [ ] **Step 8: Lint and commit**

```bash
php -l src/Service/Reader/PageRepair/OrphanIconGlyphRemover.php
php -l tests/Service/Reader/PageRepair/OrphanIconGlyphRemoverTest.php
php -l tests/Service/Reader/FetchedPageNormalizerTest.php
vendor/bin/phpcs src/Service/Reader/PageRepair/OrphanIconGlyphRemover.php tests/Service/Reader/PageRepair/OrphanIconGlyphRemoverTest.php tests/Service/Reader/FetchedPageNormalizerTest.php
git add src/Service/Reader/PageRepair/OrphanIconGlyphRemover.php tests/Service/Reader/PageRepair/OrphanIconGlyphRemoverTest.php tests/Service/Reader/FetchedPageNormalizerTest.php
git commit -m "refactor(#1268): the glyph remover prunes emptied holders through the shared walk"
```

---

### Task 7: Reader cache version 27

**Files:**
- Modify: `frontend/src/app/reader/reader-cache.service.ts`

Skip this task if the planner rules "leave the frontend untouched" on Q-2.

- [ ] **Step 1: Bump**

Before:
```ts
  private static readonly VERSION = 26;
```
After:
```ts
  private static readonly VERSION = 27;
```

- [ ] **Step 2: Frontend gate (from the repository root)**

```bash
docker compose exec -T frontend npm run check
```
Expected: ESLint, Prettier, Stylelint and Jest all pass.

- [ ] **Step 3: Commit (from the repository root)**

```bash
git add frontend/src/app/reader/reader-cache.service.ts
git commit -m "chore(#1268): bump the reader cache version for the emptied-wrapper changes"
```

---

### Task 8: PR gates, independent review, PR

- [ ] **Step 1: The cumulative harness diff (from `backend/`)**

```bash
diff -rq var/reader-1268/base var/reader-1268/after-task-6 | tee var/reader-1268/cumulative.txt
wc -l < var/reader-1268/cumulative.txt
```
Expected: the union of the pages Tasks 4–6 reported, each already classified. The PR body quotes the count and the classification.

- [ ] **Step 2: Backend gates (from `backend/`)**

```bash
composer cs
bin/console cache:warmup && composer stan
composer md
composer tramp
php bin/phpunit
docker compose exec php printenv APP_CACHE_DIR
docker compose exec php composer test
composer infection:diff
```
Expected: each passes. `printenv` prints `/app/var/cache-docker` (the MySQL leg may then run beside the native one; if it prints nothing, run `docker compose up -d php worker` and `docker compose restart nginx` first). If only `composer tramp` fails, run `composer show larspohlmann/phptramp` before looking at the code. An escaped mutant on a touched line gets a killing test in the task that owns the line, never an `ignore`.

- [ ] **Step 3: PhpStorm inspections**

Run `mcp__phpstorm__lint_files` on every changed PHP file (`git diff --name-only origin/develop -- '*.php'` from the repository root). ERROR and WARNING block; weak warnings are advisory.

- [ ] **Step 4: The dev log (from `backend/`)**

```bash
ls -t var/log/dev-*.log | head -1 | xargs tail -n 200 | jq -c 'select(.level >= 300)'
```
Expected: nothing from the reader pipeline.

- [ ] **Step 5: Independent review (opus)**

Dispatch a fresh reviewer with the Agent tool (`subagent_type: general-purpose`, `model: opus`). Its prompt:

> Review branch `chore/1268-reader-cleanup` against `origin/develop` in `/Users/lars/Documents/work/eigenes/simple-feed-reader` (read-only: no commits, no checkout, no stash). The plan is `docs/superpowers/plans/2026-09-30-1268-reader-cleanup.md`. Check, adversarially: (1) every Decision (D-1…D-7) holds in the code, and no output change outside I-1…I-4 is possible. Walk `EmptiedWrapperRemover` against each of the three old walks quoted in the plan, case by case: roots, blank text, tag list, self-check, start node, detached subtrees. (2) `SubstantialProseDetector` is exactly the two old checks. (3) CLAUDE.md: names, `final readonly`, §10 roles, comments (default none, three lines at most), no dead imports, PHPMD, phptramp. (4) The tests: re-run at least one deletion check from each of Tasks 1–6, restoring with the Edit tool (never `git checkout --`), and quote each FAIL. (5) From `backend/`, run `diff -rq var/reader-1268/base var/reader-1268/after-task-6` and read at least three changed pages' hunks against I-1…I-4. Report findings as blocking or non-blocking, each with `path:line` and the fix.

Fix every blocking finding in the task that owns it (a new commit `fix(#1268): …`, with its own test and deletion check), then rerun Steps 1–4. Put a non-blocking finding you do not fix into the PR body with the reason.

- [ ] **Step 6: Delete the harness (from `backend/`)**

```bash
rm -rf var/reader-1268
git status --short
```
Expected: a clean tree. The scripts stay in this plan's Appendix H.

- [ ] **Step 7: Push and open the PR (from the repository root)**

Write the body to the scratchpad as `pr-1268.md`:

```markdown
## What

- **One substantial-prose check.** `SubstantialProseDetector` (root service, 200 collapsed characters, not link-dominated) replaces the identical private copies in `EdgeBoilerplateTrimmer` and `AuthorBioSeparator`. No output change. `RelatedTeaserGridRemover` (length only) and `NavigationChromeTrimmer` (120) ask different questions and stay.
- **One emptied-wrapper walk.** `EmptiedWrapperRemover` replaces the walks in `DuplicateBlockCollapser`, `PlayerChromeCleaner` and `OrphanIconGlyphRemover`. One definition of empty: whitespace-only text (`&nbsp;` included), no `img`/`picture`/`svg`/`video`/`audio`/`iframe` in or at the element, and never `<body>` or anything outside it.
- **`remove()`.** `ShareWidgetRemover` and `ScreenReaderOnlyElementRemover` call `$element->remove()`. The `parentNode !== null` guard could never be false for an element in the pre-collected list.
- **Reader cache `VERSION` 27**, because the walk changes output on some pages.

## Intended output changes

- I-1: an emptied `section`/`article`/`main` is removed (the collapser kept it, and the reader drew it as an empty inset card). `body` and everything outside it never is.
- I-2: `&nbsp;`-only text is blank.
- I-3: a lone `br`, `hr`, `input` or orphan `source` no longer keeps a wrapper. `picture` counts as content everywhere.
- I-4: an emptied player, frame, `svg` or `picture` that is itself the wrapper stays.

## Before/after

A one-off harness (in the plan, Appendix H) ran the full fetch-normalise-extract-clean pipeline offline over all 61 `tests/Fixtures/**/*.html` pages and <N> real pages frozen from the dev database, at the branch base and after each task. Tasks 1–2: no change. Tasks 4–6: <count> pages changed, each hunk explained by one of I-1…I-4: <list>.

## Gates

`composer cs`, `composer stan`, `composer md`, `composer tramp`, `php bin/phpunit` (SQLite), `composer test` (MySQL), `composer infection:diff`, PhpStorm inspections, `npm run check` in the frontend container: all green. Independent review: <summary>.

Closes #1268
```

Fill in the `<…>` placeholders from Steps 1 and 5 before creating the PR. The body must end with `Closes #1268`.

```bash
git push -u origin chore/1268-reader-cleanup && gh pr create --repo larspohlmann/simple-feed-reader --base develop --head chore/1268-reader-cleanup --title "refactor(#1268): one substantial-prose check, one emptied-wrapper walk, remove()" --body-file <scratchpad>/pr-1268.md
```

- [ ] **Step 8: Report**

Report the PR URL, the cumulative harness count and classification, the review's findings and how each was handled. Then merge when CI is green: before merging, check that `gh pr view <PR> --json closingIssuesReferences` lists #1268 (if empty, re-save the body with `gh pr edit <PR> --body-file <file>` and check again); watch CI with a Monitor (`gh pr checks <PR> --watch --fail-fast`); merge with `gh pr merge <PR> --merge` (never `--auto`); verify #1268 is CLOSED (COMPLETED), closing it by hand with `--reason completed` and a comment naming the PR and merge SHA only if GitHub did not. Report the merge SHA as well.
