# Reader Extraction Fixes and Typographic Variety — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Ship issue #235 — reader-mode extraction keeps headings/images on block-component sites, and the article view gains typographic rhythm (dividers, lead paragraph, tables, blockquotes, reading time).

**Architecture:** Backend: two new never-throwing services bracket the readability parse inside `ArticleExtractor` (`FetchedPageNormalizer` pre-parse, `LeadingTitleRemover` post-parse); `ArticleExtractorInterface::extract()` gains an optional `$entryTitle`. Frontend: two new pure-function modules (`reading-time.ts`, `lead-paragraph.ts`) consumed by `ReaderViewComponent`, plus SCSS in the `.content ::ng-deep` block.

**Tech Stack:** Symfony 7.4 / PHP 8.4, fivefilters/readability.php v4, PHPUnit; Angular 20 signals, Jest.

**Spec:** `docs/superpowers/specs/2026-08-02-reader-visual-variety-design.md` · **Issue:** #235 · **Branch:** `feature/235-reader-visual-variety` (already checked out)

## IMPORTANT: the prototype already exists

The working tree already contains the approved, browser-verified prototype
(uncommitted). The tasks below do NOT write that code from scratch — they
**verify the file matches the plan, add the tests, run them, and commit code
plus tests together**. A failing test means the prototype has a bug: fix the
implementation, not the test, unless the test contradicts the spec.

Prototype files present in the working tree:

- `backend/src/Service/Reader/FetchedPageNormalizer.php` (new)
- `backend/src/Service/Reader/LeadingTitleRemover.php` (new)
- `backend/src/Service/Reader/ArticleExtractor.php` (modified: constructor + pipeline + docblock)
- `backend/src/Service/Reader/ArticleExtractorInterface.php` (modified: `$entryTitle` param)
- `backend/src/Controller/Api/EntryController.php` (modified: passes `$entry->getTitle()`)
- `frontend/src/app/reader/reader-view/reader-view.component.ts` (modified: `readingMinutes`, `markLeadParagraph` — Tasks 5/6 move these into modules)
- `frontend/src/app/reader/reader-view/reader-view.component.html` (modified: reading-time in `.meta`)
- `frontend/src/app/reader/reader-view/reader-view.component.scss` (modified: dividers, lead, blockquote, tables, mark/sub/sup/dl)
- `frontend/public/i18n/en.json`, `frontend/public/i18n/de.json` (modified: `reader.readingTime`)

Also present but **never to be committed** (delete in Task 8):
`backend/var/extract-probe.php`, `backend/var/flatten-compare.php`,
`backend/var/readability-log.txt`, `backend/var/extract-probe-raw.html`,
`backend/var/extract-probe-clean.html`.

## Global Constraints

- PHP: `declare(strict_types=1)` in every file; PSR-12 (`composer cs:fix`); PHPStan level max (warm cache first: `bin/console cache:warmup`); **every touched `src` file PHPMD-clean** (`composer md`), not merely free of new findings.
- PHP 8.4 `new Foo()->bar()` chains must be written `(new Foo())->bar()` — pdepend 2.16.2 (CI `composer md`) cannot parse the bare form.
- House style: `final readonly class`, constructor promotion, guard clauses, no boolean flag params, exceptions over null returns for errors (the never-throws normalizers return input unchanged by design — documented in their docblocks).
- Controllers: no private methods that carry responsibility (`ThinControllerRule`).
- Frontend: standalone components + signals; styles in sibling `.scss` only; **no hex colours, no ad-hoc `px`, no media-query literals outside `src/app/theme/`** (Stylelint gate); Prettier 100-col.
- `npm run check` must run on **Node 22** (`node --version` first — it fails on newer majors).
- Run backend suite on both legs before PR: `php bin/phpunit` (SQLite, from `backend/`) and `docker compose exec php vendor/bin/phpunit` (MySQL, from repo root). Known flake: order-dependent rate-limiter failures in the full MySQL run that pass in isolation are pre-existing.
- Commits: `type(#235): subject` (e.g. `feat(#235): …`, `test(#235): …`).
- Concurrent Claude sessions may share this checkout — never `checkout`/`reset`/`stash` without checking `git status` first.

---

### Task 1: FetchedPageNormalizer unit tests

**Files:**
- Already in tree (verify, do not rewrite): `backend/src/Service/Reader/FetchedPageNormalizer.php`
- Test: `backend/tests/Service/Reader/FetchedPageNormalizerTest.php` (create)

**Interfaces:**
- Produces: `FetchedPageNormalizer::normalize(string $html): string` — no constructor args. Task 3 injects it into `ArticleExtractor`.

- [ ] **Step 1: Read the prototype service**

Read `backend/src/Service/Reader/FetchedPageNormalizer.php`. It must expose
exactly `public function normalize(string $html): string`, remove elements
whose `class` matches `/(?:visually-?hidden|sr-only|screen-reader)/i`, and
collapse `<div>` wrappers that have no own text and exactly one element child
that is also a `<div>` (bottom-up, one pass via reverse document order).

- [ ] **Step 2: Write the test file**

```php
<?php

declare(strict_types=1);

namespace App\Tests\Service\Reader;

use App\Service\Reader\FetchedPageNormalizer;
use PHPUnit\Framework\TestCase;

final class FetchedPageNormalizerTest extends TestCase
{
    private FetchedPageNormalizer $normalizer;

    protected function setUp(): void
    {
        $this->normalizer = new FetchedPageNormalizer();
    }

    public function testCollapsesSingleChildDivChains(): void
    {
        $html = '<html><body><div class="a"><div class="b"><div class="c"><p>Text</p></div></div></div></body></html>';

        $normalized = $this->normalizer->normalize($html);

        // The two outer wrappers are gone; the innermost div (whose child is a
        // <p>, not a <div>) survives as the direct parent of the paragraph.
        self::assertStringNotContainsString('class="a"', $normalized);
        self::assertStringNotContainsString('class="b"', $normalized);
        self::assertStringContainsString('<div class="c"><p>Text</p></div>', $normalized);
    }

    public function testKeepsDivWithMultipleElementChildren(): void
    {
        $html = '<html><body><div class="keep"><div>one</div><div>two</div></div></body></html>';

        $normalized = $this->normalizer->normalize($html);

        self::assertStringContainsString('class="keep"', $normalized);
    }

    public function testKeepsDivWithOwnText(): void
    {
        $html = '<html><body><div class="keep">intro <div>nested</div></div></body></html>';

        $normalized = $this->normalizer->normalize($html);

        self::assertStringContainsString('class="keep"', $normalized);
    }

    public function testHeadingSurvivesWrapperCollapse(): void
    {
        $html = '<html><body><div><div><h2 id="s1">Section</h2></div></div></body></html>';

        $normalized = $this->normalizer->normalize($html);

        self::assertStringContainsString('<h2 id="s1">Section</h2>', $normalized);
    }

    public function testRemovesScreenReaderOnlyElements(): void
    {
        $html = '<html><body>'
            . '<span class="visually-hidden">Image source,</span>'
            . '<span class="ssrcss-1f39n02-VisuallyHidden e16en2lz0">Image caption,</span>'
            . '<span class="sr-only">skip</span>'
            . '<p class="visible">Body</p>'
            . '</body></html>';

        $normalized = $this->normalizer->normalize($html);

        self::assertStringNotContainsString('Image source,', $normalized);
        self::assertStringNotContainsString('Image caption,', $normalized);
        self::assertStringNotContainsString('skip', $normalized);
        self::assertStringContainsString('Body', $normalized);
    }

    public function testEmptyInputIsReturnedUnchanged(): void
    {
        self::assertSame('', $this->normalizer->normalize(''));
        self::assertSame('   ', $this->normalizer->normalize('   '));
    }

    public function testUmlautsSurviveNormalization(): void
    {
        $html = '<html><body><div><div><p>Grüße from Köln</p></div></div></body></html>';

        $normalized = $this->normalizer->normalize($html);

        // The DOM round-trip encodes non-ASCII as entities; the decoded text
        // must be intact. html_entity_decode covers both representations.
        self::assertStringContainsString('Grüße from Köln', html_entity_decode($normalized));
    }
}
```

- [ ] **Step 3: Run the tests**

Run from `backend/`: `php bin/phpunit tests/Service/Reader/FetchedPageNormalizerTest.php`
Expected: PASS (the prototype exists). A failure is a prototype bug — fix
`FetchedPageNormalizer.php`, not the test.

- [ ] **Step 4: Commit**

```bash
git add backend/src/Service/Reader/FetchedPageNormalizer.php backend/tests/Service/Reader/FetchedPageNormalizerTest.php
git commit -m "feat(#235): normalize fetched pages before readability parse"
```

---

### Task 2: LeadingTitleRemover unit tests

**Files:**
- Already in tree (verify, do not rewrite): `backend/src/Service/Reader/LeadingTitleRemover.php`
- Test: `backend/tests/Service/Reader/LeadingTitleRemoverTest.php` (create)

**Interfaces:**
- Produces: `LeadingTitleRemover::remove(string $contentHtml, array $titleCandidates): string` with `@param list<string|null> $titleCandidates`. Task 3 injects it into `ArticleExtractor`.

- [ ] **Step 1: Read the prototype service**

Read `backend/src/Service/Reader/LeadingTitleRemover.php`. It must drop the
first `h1|h2|h3` (document order) whose normalized text (whitespace-collapsed,
case-folded) equals any non-empty candidate, and return the input unchanged in
every other case.

- [ ] **Step 2: Write the test file**

```php
<?php

declare(strict_types=1);

namespace App\Tests\Service\Reader;

use App\Service\Reader\LeadingTitleRemover;
use PHPUnit\Framework\TestCase;

final class LeadingTitleRemoverTest extends TestCase
{
    private LeadingTitleRemover $remover;

    protected function setUp(): void
    {
        $this->remover = new LeadingTitleRemover();
    }

    public function testRemovesFirstHeadingMatchingPageTitle(): void
    {
        $content = '<div><h2>My Article</h2><p>Body text.</p></div>';

        $result = $this->remover->remove($content, ['My Article', null]);

        self::assertStringNotContainsString('<h2>', $result);
        self::assertStringContainsString('Body text.', $result);
    }

    public function testRemovesFirstHeadingMatchingEntryTitle(): void
    {
        // The page <title> is an SEO variant; the feed entry title matches.
        $content = '<div><h2>My Article</h2><p>Body text.</p></div>';

        $result = $this->remover->remove($content, ['SEO Variant Title', 'My Article']);

        self::assertStringNotContainsString('<h2>', $result);
    }

    public function testNormalizesWhitespaceAndCase(): void
    {
        $content = "<div><h1>  my   ARTICLE\n</h1><p>Body.</p></div>";

        $result = $this->remover->remove($content, ['My Article']);

        self::assertStringNotContainsString('<h1>', $result);
    }

    public function testKeepsHeadingThatDoesNotMatch(): void
    {
        $content = '<div><h2>A Real Section</h2><p>Body.</p></div>';

        $result = $this->remover->remove($content, ['My Article']);

        self::assertStringContainsString('A Real Section', $result);
    }

    public function testOnlyTheFirstHeadingIsConsidered(): void
    {
        // A later heading that happens to equal the title is content, not a
        // duplicated headline — it stays.
        $content = '<div><h2>Intro</h2><p>Body.</p><h2>My Article</h2></div>';

        $result = $this->remover->remove($content, ['My Article']);

        self::assertStringContainsString('Intro', $result);
        self::assertStringContainsString('My Article', $result);
    }

    public function testNoCandidatesReturnsInputUnchanged(): void
    {
        $content = '<div><h2>My Article</h2></div>';

        self::assertSame($content, $this->remover->remove($content, [null, '', '  ']));
    }

    public function testContentWithoutHeadingsIsUnchanged(): void
    {
        $content = '<div><p>Only paragraphs here.</p></div>';

        self::assertSame($content, $this->remover->remove($content, ['My Article']));
    }
}
```

- [ ] **Step 3: Run the tests**

Run from `backend/`: `php bin/phpunit tests/Service/Reader/LeadingTitleRemoverTest.php`
Expected: PASS. On failure fix the service (see Task 1 Step 3 rule).

Note: `testNoCandidatesReturnsInputUnchanged` and
`testContentWithoutHeadingsIsUnchanged` assert `assertSame` on the raw input —
the service must return the ORIGINAL string (not a DOM round-trip) whenever it
removes nothing. If the prototype round-trips unconditionally, restructure it
to return `$contentHtml` early in the no-match path.

- [ ] **Step 4: Commit**

```bash
git add backend/src/Service/Reader/LeadingTitleRemover.php backend/tests/Service/Reader/LeadingTitleRemoverTest.php
git commit -m "feat(#235): drop extracted headline that repeats the entry title"
```

---

### Task 3: ArticleExtractor wiring, interface change, fixtures

**Files:**
- Already in tree (verify): `backend/src/Service/Reader/ArticleExtractor.php`, `backend/src/Service/Reader/ArticleExtractorInterface.php`, `backend/src/Controller/Api/EntryController.php`
- Modify: `backend/tests/Support/FakeArticleExtractor.php`, `backend/tests/Service/Reader/ArticleExtractorTest.php`
- Create: `backend/tests/Fixtures/reader/article-block-components.html`

**Interfaces:**
- Consumes: `FetchedPageNormalizer::normalize(string): string` (Task 1), `LeadingTitleRemover::remove(string, array): string` (Task 2).
- Produces: `ArticleExtractorInterface::extract(string $url, ?string $entryTitle = null): ExtractionResult`. `ArticleExtractor` constructor order: `(HtmlPageFetcher $fetcher, FetchedPageNormalizer $normalizer, LeadingTitleRemover $titleRemover, EntrySanitizer $sanitizer)`.

- [ ] **Step 1: Update FakeArticleExtractor to the new signature**

In `backend/tests/Support/FakeArticleExtractor.php` replace the `extract` method:

```php
    public function extract(string $url, ?string $entryTitle = null): ExtractionResult
    {
        $this->calls[] = $url;

        return $this->result
            ?? throw new \LogicException('FakeArticleExtractor::extract called without a configured result.');
    }
```

- [ ] **Step 2: Fix the existing ArticleExtractorTest constructor calls**

`ArticleExtractorTest` builds the extractor in two places
(`private function extractor(...)` around line 41 and
`testFetchFailureMapsToFetchReason` around line 97). Both say
`new ArticleExtractor($fetcher, new EntrySanitizer())`. Replace both with:

```php
        return new ArticleExtractor(
            $fetcher,
            new FetchedPageNormalizer(),
            new LeadingTitleRemover(),
            new EntrySanitizer(),
        );
```

(in the second place `$extractor = new ArticleExtractor(...)` accordingly), and add:

```php
use App\Service\Reader\FetchedPageNormalizer;
use App\Service\Reader\LeadingTitleRemover;
```

- [ ] **Step 3: Run the existing reader tests — regression gate**

Run from `backend/`: `php bin/phpunit tests/Service/Reader/ tests/Controller/Api/EntryReaderControllerTest.php`
Expected: PASS. These fixtures have conventional markup, so the normalizer
must not change their extraction results. A failure here means the normalizer
is too aggressive — STOP and fix before continuing.

- [ ] **Step 4: Create the block-component fixture**

Create `backend/tests/Fixtures/reader/article-block-components.html`. The page
title is deliberately an SEO variant; the body headline equals the entry
title; every block sits in single-child div wrapper chains; hidden labels use
a hashed camel-case class (the hardest matching case):

```html
<!DOCTYPE html>
<html lang="en">
<head><meta charset="utf-8"><title>SEO Variant Of The Headline — Site</title></head>
<body>
<div id="root"><div class="page"><div class="stack">
<article>
  <div data-block="headline"><div class="wrap"><div class="inner">
    <h1>Block Component Headline</h1>
  </div></div></div>
  <div data-block="image"><div class="wrap"><div class="inner">
    <figure>
      <img src="https://site.test/img/hero.jpg" alt="Hero">
      <span class="css-1abc2de-VisuallyHidden x9">Image source, Agency Name</span>
      <figcaption><span class="css-1abc2de-VisuallyHidden x9">Image caption,</span>A caption line</figcaption>
    </figure>
  </div></div></div>
  <div data-block="text"><div class="wrap"><div class="inner"><div class="rich">
    <p>The opening paragraph carries a substantial amount of readable prose so that the readability scorer treats this fixture exactly like a genuine article body rather than boilerplate navigation chrome.</p>
    <p>A second paragraph continues the argument at length, adding enough commas, characters and sentence variety that the paragraph-level scoring accumulates comfortably past every internal threshold.</p>
    <p>A third paragraph keeps the density up, because the extractor requires at least two hundred characters of text content overall and rewards containers with many long paragraphs.</p>
  </div></div></div></div>
  <div data-block="subheadline"><div class="wrap"><div class="inner">
    <h2>First Section</h2>
  </div></div></div>
  <div data-block="text"><div class="wrap"><div class="inner"><div class="rich">
    <p>The first section opens with more meaningful prose, again long enough that the wrapper collapse lets its score propagate upward into the shared article container.</p>
    <p>Another sentence-rich paragraph follows, giving the sibling-join phase no excuse to drop this block once the wrappers have been flattened away.</p>
  </div></div></div></div>
  <div data-block="subheadline"><div class="wrap"><div class="inner">
    <h2>Second Section</h2>
  </div></div></div>
  <div data-block="text"><div class="wrap"><div class="inner"><div class="rich">
    <p>The closing section adds a final stretch of body copy, ensuring the fixture has multiple scored text blocks separated by subheadings and an image block, which is precisely the shape that used to lose its structure.</p>
    <p>The last paragraph rounds the article off with yet more prose so the total comfortably exceeds the minimum content length check inside the extractor.</p>
  </div></div></div></div>
</article>
</div></div></div>
</body>
</html>
```

- [ ] **Step 5: Add the block-component test to ArticleExtractorTest**

```php
    public function testKeepsHeadingsAndImagesOnBlockComponentPages(): void
    {
        $html = (string) file_get_contents(__DIR__ . '/../../Fixtures/reader/article-block-components.html');
        $extractor = $this->extractor([new MockResponse($html, ['http_code' => 200])]);

        $result = $extractor->extract('https://site.test/post', 'Block Component Headline');

        self::assertTrue($result->ok);
        // Subheadings and the figure survive the wrapper-chain layout.
        self::assertStringContainsString('First Section', (string) $result->contentHtml);
        self::assertStringContainsString('Second Section', (string) $result->contentHtml);
        self::assertStringContainsString('<img', (string) $result->contentHtml);
        // The body headline duplicates the entry title, so it is dropped …
        self::assertStringNotContainsString('Block Component Headline', (string) $result->contentHtml);
        // … and screen-reader-only labels never reach the client.
        self::assertStringNotContainsString('Image source,', (string) $result->contentHtml);
        self::assertStringContainsString('A caption line', (string) $result->contentHtml);
    }
```

- [ ] **Step 6: Run the reader test directory**

Run from `backend/`: `php bin/phpunit tests/Service/Reader/`
Expected: PASS, including the new test. If the new test fails because
readability drops fixture content, first check the failure mode: assert what
`$result->contentHtml` actually contains (var_dump in a scratch run). Lengthen
the fixture paragraphs rather than weakening assertions.

- [ ] **Step 7: Commit**

```bash
git add backend/src/Service/Reader/ArticleExtractor.php backend/src/Service/Reader/ArticleExtractorInterface.php backend/src/Controller/Api/EntryController.php backend/tests/Support/FakeArticleExtractor.php backend/tests/Service/Reader/ArticleExtractorTest.php backend/tests/Fixtures/reader/article-block-components.html
git commit -m "feat(#235): wire page normalization and title removal into reader extraction"
```

---

### Task 4: Backend quality gates

**Files:**
- Possibly modify (lint fixes only): the files committed in Tasks 1–3.

- [ ] **Step 1: Full backend suite, SQLite leg**

Run from `backend/`: `php bin/phpunit`
Expected: PASS.

- [ ] **Step 2: Static gates**

Run from `backend/`:

```bash
composer cs:fix
bin/console cache:warmup
composer check
composer md
```

Expected: all clean. PHPMD findings in ANY touched src file must be fixed by
design changes (extract methods, reduce params), never by tuning thresholds.

- [ ] **Step 3: PhpStorm inspections**

Run `mcp__phpstorm__lint_files` on:
`backend/src/Service/Reader/FetchedPageNormalizer.php`,
`backend/src/Service/Reader/LeadingTitleRemover.php`,
`backend/src/Service/Reader/ArticleExtractor.php`,
`backend/src/Service/Reader/ArticleExtractorInterface.php`,
`backend/src/Controller/Api/EntryController.php`.
Block on ERROR and WARNING; weak warnings are advisory.

- [ ] **Step 4: MySQL leg**

Run from repo root: `docker compose exec php vendor/bin/phpunit`
Expected: PASS (order-dependent rate-limiter failures that pass in isolation
are a known pre-existing flake — re-run those tests in isolation to confirm).

- [ ] **Step 5: Commit any gate fixes**

```bash
git add -u backend
git commit -m "chore(#235): satisfy backend quality gates"
```

(Skip the commit if the gates required no changes.)

---

### Task 5: Frontend reading-time module

**Files:**
- Create: `frontend/src/app/reader/reading-time.ts`
- Create: `frontend/src/app/reader/reading-time.spec.ts`
- Modify: `frontend/src/app/reader/reader-view/reader-view.component.ts` (remove the inline `plainWordCount` + `READING_WORDS_PER_MINUTE`, import the module)

**Interfaces:**
- Produces: `estimateReadingMinutes(html: string): number | null` from `frontend/src/app/reader/reading-time.ts`. Task 7's component spec relies on the component exposing `readingMinutes` (a `computed`) that delegates to it.

- [ ] **Step 1: Create the module**

`frontend/src/app/reader/reading-time.ts`:

```ts
/** An ordinary adult reading pace; the estimate is coarse by nature. */
const READING_WORDS_PER_MINUTE = 220;

/**
 * Estimated minutes to read an HTML fragment, or null when it rounds below a
 * minute (a bare link, an empty summary) so the meta line can skip it. Tags
 * are dropped textually — parsing the fragment into a DOM (even detached)
 * would start image fetches, and an estimate does not need DOM fidelity.
 */
export function estimateReadingMinutes(html: string): number | null {
  const text = html
    .replace(/<[^>]*>/g, ' ')
    .replace(/&[a-z#0-9]+;/gi, ' ')
    .trim();
  if (text === '') return null;
  const words = text.split(/\s+/).length;
  const minutes = Math.round(words / READING_WORDS_PER_MINUTE);
  return minutes < 1 ? null : minutes;
}
```

- [ ] **Step 2: Write the spec**

`frontend/src/app/reader/reading-time.spec.ts`:

```ts
import { estimateReadingMinutes } from './reading-time';

const words = (n: number): string => Array.from({ length: n }, (_, i) => `word${i}`).join(' ');

describe('estimateReadingMinutes', () => {
  it('returns null for empty input', () => {
    expect(estimateReadingMinutes('')).toBeNull();
    expect(estimateReadingMinutes('   ')).toBeNull();
  });

  it('returns null for markup-only input', () => {
    expect(estimateReadingMinutes('<p></p><img src="x.jpg">')).toBeNull();
  });

  it('returns null below half a minute of text', () => {
    // 100 words / 220 wpm rounds to 0 minutes.
    expect(estimateReadingMinutes(`<p>${words(100)}</p>`)).toBeNull();
  });

  it('rounds to the nearest minute', () => {
    expect(estimateReadingMinutes(`<p>${words(220)}</p>`)).toBe(1);
    expect(estimateReadingMinutes(`<p>${words(550)}</p>`)).toBe(3);
  });

  it('does not count tags or entities as words', () => {
    const html = `<div class="wrapper"><p>${words(220)}</p>&nbsp;&amp;</div>`;
    expect(estimateReadingMinutes(html)).toBe(1);
  });
});
```

- [ ] **Step 3: Point the component at the module**

In `reader-view.component.ts`: delete the file-local
`READING_WORDS_PER_MINUTE` constant and `plainWordCount` function; add
`import { estimateReadingMinutes } from '../reading-time';` and reduce the
computed to:

```ts
  /** Estimated minutes to read the displayed text; null hides the meta chip. */
  readonly readingMinutes = computed(() => estimateReadingMinutes(this.displayHtml()));
```

- [ ] **Step 4: Run the frontend unit tests**

Run from `frontend/` (Node 22): `npm test -- --runTestsByPath src/app/reader/reading-time.spec.ts src/app/reader/reader-view/reader-view.component.spec.ts`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add frontend/src/app/reader/reading-time.ts frontend/src/app/reader/reading-time.spec.ts frontend/src/app/reader/reader-view/reader-view.component.ts
git commit -m "feat(#235): estimate reading time for the article meta line"
```

---

### Task 6: Frontend lead-paragraph module

**Files:**
- Create: `frontend/src/app/reader/lead-paragraph.ts`
- Create: `frontend/src/app/reader/lead-paragraph.spec.ts`
- Modify: `frontend/src/app/reader/reader-view/reader-view.component.ts` (remove the private `markLeadParagraph`, import the module)

**Interfaces:**
- Consumes: nothing.
- Produces: `markLeadParagraph(host: HTMLElement): void` from `frontend/src/app/reader/lead-paragraph.ts`.

- [ ] **Step 1: Create the module**

`frontend/src/app/reader/lead-paragraph.ts`:

```ts
/**
 * Tags the article's first real paragraph with class `lead`, so the
 * stylesheet can give it a little more weight. Runs in the component's
 * post-render pass because wrapper elements from feeds and readability sit
 * between the container and the paragraphs, which puts the lead out of reach
 * of a plain CSS sibling selector. Idempotent across re-renders: stale tags
 * are cleared first.
 */
export function markLeadParagraph(host: HTMLElement): void {
  for (const p of Array.from(host.querySelectorAll('p'))) {
    p.classList.remove('lead');
  }
  for (const p of Array.from(host.querySelectorAll('p'))) {
    if ((p.textContent ?? '').trim() !== '') {
      p.classList.add('lead');
      return;
    }
  }
}
```

- [ ] **Step 2: Write the spec**

`frontend/src/app/reader/lead-paragraph.spec.ts`:

```ts
import { markLeadParagraph } from './lead-paragraph';

const host = (html: string): HTMLElement => {
  const el = document.createElement('div');
  el.innerHTML = html;
  return el;
};

describe('markLeadParagraph', () => {
  it('tags the first non-empty paragraph', () => {
    const el = host('<div><p>First</p><p>Second</p></div>');

    markLeadParagraph(el);

    const tagged = el.querySelectorAll('p.lead');
    expect(tagged).toHaveLength(1);
    expect(tagged[0].textContent).toBe('First');
  });

  it('skips empty leading paragraphs', () => {
    const el = host('<p>   </p><p></p><p>Real lead</p>');

    markLeadParagraph(el);

    expect(el.querySelector('p.lead')?.textContent).toBe('Real lead');
  });

  it('reaches through nested wrappers', () => {
    const el = host('<div id="readability-page-1"><div><p>Nested lead</p></div></div>');

    markLeadParagraph(el);

    expect(el.querySelector('p.lead')?.textContent).toBe('Nested lead');
  });

  it('clears a stale tag before assigning the new one', () => {
    // A previous render tagged a paragraph that is no longer first.
    const el = host('<p>New first</p><p class="lead">Old lead</p>');

    markLeadParagraph(el);

    const tagged = el.querySelectorAll('p.lead');
    expect(tagged).toHaveLength(1);
    expect(tagged[0].textContent).toBe('New first');
  });

  it('does nothing when there is no paragraph', () => {
    const el = host('<h2>Heading only</h2>');

    markLeadParagraph(el);

    expect(el.querySelector('.lead')).toBeNull();
  });
});
```

- [ ] **Step 3: Point the component at the module**

In `reader-view.component.ts`: delete the private `markLeadParagraph` method,
add `import { markLeadParagraph } from '../lead-paragraph';`, and change the
call in the post-render effect from `this.markLeadParagraph(host)` to
`markLeadParagraph(host)`.

- [ ] **Step 4: Run the frontend unit tests**

Run from `frontend/` (Node 22): `npm test -- --runTestsByPath src/app/reader/lead-paragraph.spec.ts src/app/reader/reader-view/reader-view.component.spec.ts`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add frontend/src/app/reader/lead-paragraph.ts frontend/src/app/reader/lead-paragraph.spec.ts frontend/src/app/reader/reader-view/reader-view.component.ts
git commit -m "feat(#235): tag the article lead paragraph for styling"
```

---

### Task 7: Template, i18n, SCSS, component spec

**Files:**
- Already in tree (verify): `frontend/src/app/reader/reader-view/reader-view.component.html`, `frontend/src/app/reader/reader-view/reader-view.component.scss`, `frontend/public/i18n/en.json`, `frontend/public/i18n/de.json`
- Modify: `frontend/src/app/reader/reader-view/reader-view.component.spec.ts` (add reading-time cases)

**Interfaces:**
- Consumes: `readingMinutes` computed (Task 5); Transloco key `reader.readingTime` (en `"≈ {{minutes}} min"`, de `"≈ {{minutes}} Min."`).

- [ ] **Step 1: Verify the prototype template and dictionaries**

The `.meta` block in `reader-view.component.html` must contain:

```html
        @if (readingMinutes(); as minutes) {
          · {{ 'reader.readingTime' | transloco: { minutes } }}
        }
```

and both dictionaries the `readingTime` key next to `openOriginal`.

- [ ] **Step 2: Verify the prototype SCSS**

`reader-view.component.scss` must contain (inside the article-typography
section, all under `.content ::ng-deep`): the `h2` divider
(`margin-top: var(--space-7)`; `::before` full-width `1px solid var(--border)`
rule with `margin-bottom: var(--space-4)`), `p.lead { font-size: 1.0625em; }`,
the blockquote card (`background: var(--surface-1)`, radius on the right
corners, `--space-3/--space-4` padding, `blockquote p:last-child` margin
reset), the table block (`display: block; overflow-x: auto`,
`border-collapse: collapse`, `--fs-sm`, th/td padding `--space-2 --space-3`,
`border-bottom: 1px solid var(--border)`, header `var(--border-strong)`),
`mark` (`color-mix(in srgb, var(--accent) 22%, transparent)`), `sub/sup`
(`line-height: 0`), and `dl/dt/dd` margins with bold `dt`.

- [ ] **Step 3: Add reading-time cases to the component spec**

In `reader-view.component.spec.ts` (uses the real `en` dictionary, so the
literal string is assertable). Reuse the existing `entry(...)` factory and
`mount(...)` helper:

```ts
  describe('reading time', () => {
    const longBody = `<p>${Array.from({ length: 660 }, (_, i) => `w${i}`).join(' ')}</p>`;

    it('shows the estimate for a long article', () => {
      const f = mount(entry({ contentHtml: longBody }));

      expect(f.nativeElement.querySelector('.meta')?.textContent).toContain('≈ 3 min');
    });

    it('hides the estimate for a short article', () => {
      const f = mount(entry({ contentHtml: '<p>Tiny.</p>' }));

      expect(f.nativeElement.querySelector('.meta')?.textContent).not.toContain('≈');
    });
  });
```

- [ ] **Step 4: Run the component spec**

Run from `frontend/` (Node 22): `npm test -- --runTestsByPath src/app/reader/reader-view/reader-view.component.spec.ts`
Expected: PASS.

- [ ] **Step 5: Full frontend gate**

Run from `frontend/` (Node 22 — check `node --version`): `npm run check`
Expected: clean. Stylelint findings in the new SCSS must be fixed by using
tokens, not by disabling rules (the existing file shows the two sanctioned
`stylelint-disable` precedents for viewport-share values only).

- [ ] **Step 6: Commit**

```bash
git add frontend/src/app/reader/reader-view/reader-view.component.html frontend/src/app/reader/reader-view/reader-view.component.scss frontend/src/app/reader/reader-view/reader-view.component.spec.ts frontend/public/i18n/en.json frontend/public/i18n/de.json
git commit -m "feat(#235): typographic variety for the article view"
```

---

### Task 8: Cleanup, e2e, PR

**Files:**
- Delete (untracked probes): `backend/var/extract-probe.php`, `backend/var/flatten-compare.php`, `backend/var/readability-log.txt`, `backend/var/extract-probe-raw.html`, `backend/var/extract-probe-clean.html`

- [ ] **Step 1: Remove the probe files**

```bash
rm -f backend/var/extract-probe.php backend/var/flatten-compare.php backend/var/readability-log.txt backend/var/extract-probe-raw.html backend/var/extract-probe-clean.html
```

- [ ] **Step 2: Confirm a clean tree**

Run `git status` — no uncommitted or unexpected files may remain (probe files
are untracked, so nothing to commit; everything else was committed in Tasks
1–7).

- [ ] **Step 3: e2e smoke against the Docker stack**

With the stack up (`docker compose up -d` from repo root), run from
`backend/`: `composer e2e` (never raw phpunit — TLS trust needs the script's
CA bundle). Expected: PASS.

- [ ] **Step 4: Scan the dev log**

Run from repo root:
`docker compose exec php sh -c "grep -iE 'error|critical|deprecat' var/log/dev.log | tail -20"`
Expected: nothing new attributable to the reader pipeline.

- [ ] **Step 5: Manual verification note (for the PR description)**

Reader-mode content is cached per entry in IndexedDB (`sfr-reader` /
`articles`). To re-check a previously opened entry after deploy, clear the
store from the console — do NOT `indexedDB.deleteDatabase(...)`: any other
open app tab holds a connection, the delete blocks, and every later cache
read hangs until timeout:

```js
const open = indexedDB.open('sfr-reader', 2);
open.onsuccess = () => open.result.transaction('articles', 'readwrite').objectStore('articles').clear();
```

- [ ] **Step 6: Push and open the PR**

```bash
git push -u origin feature/235-reader-visual-variety
gh pr create --base develop --title "Reader extraction fixes and typographic variety" --body "Closes #235

## What
- Pre-parse page normalization (collapse single-child div wrapper chains, strip screen-reader-only labels) so readability keeps headings, figures and paragraphs on block-component sites (BBC and similar).
- Post-parse removal of a leading heading that duplicates the entry title.
- Article-view typography: h2 section dividers, lead paragraph, blockquote cards, table styles, reading-time estimate, mark/sub/sup/dl styles.

## Verified
- BBC article: 52 bare <p> before -> 3 h2, 5 figures, 71 p after; NDR and heise extractions byte-identical.
- Previewed live in the app (entries 11636/11637).
- Both phpunit legs, composer check + md, npm run check, composer e2e.

## Notes
- Already-read entries keep the old extraction in the client cache until evicted; new reads get the fix immediately.
- The body byline stays (real content; no reliable way to distinguish it)."
```

- [ ] **Step 7: Watch CI on the PR head**

`gh pr checks --watch` — then **re-read the run conclusions explicitly**
(`gh run list --branch feature/235-reader-visual-variety --limit 5`);
`gh run watch --exit-status` has returned 0 on failed runs before. Fix any CI
failure before handing the PR to the user. Do not merge — merging and closing
#235 is the user's call.
