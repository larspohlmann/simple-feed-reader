# #476 Dual-Extract, Keep Richer — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Stop the reader extracting publisher chrome instead of the article body (#476) by extracting each page both with and without the wrapper-chain collapse and keeping the richer result.

**Architecture:** `FetchedPageNormalizer` splits into two operations: `normalize()` (score-neutral repairs, always applied) and `collapseWrapperChains()` (the #235 wrapper collapse, applied only to build a second candidate). `ArticleExtractor` runs readability on both candidates and keeps the one with the longer `textContent`. This dominates today's always-collapse behaviour on every observed fixture: it differs only on pages where the collapse shortens the result — the #476 failure mode — and leaves every page the current pipeline handles well unchanged. The residual risk is the symmetric case (a page the collapse *correctly* shortens); "longer = better" is a heuristic, unobserved to fail, and the metric is where to revisit if it ever does.

**Tech Stack:** PHP 8.4, Symfony 7.4, `fivefilters/readability.php` v4.0.0, PHPUnit.

## Global Constraints

- `declare(strict_types=1);` in every PHP file; PSR-12 (`composer cs`).
- PHPStan level max over `src` and `tests`, no new baselines, no unexplained `@phpstan-ignore` (`composer stan`, warm cache first: `bin/console cache:warmup`).
- PHPMD codesize clean on **every touched `src` file** (`composer md`) — fix the design, not the threshold.
- phptramp clean (`composer tramp`).
- Clean Code: names reveal intent, functions do one thing, guard clauses over nesting, **no boolean flag parameters**, depend on injected interfaces, immutable `final readonly` where possible, comments say *why*.
- Tests are production code: same naming and standards.
- Mutation gate: `composer infection:diff` must hold `minMsi` in `infection.json5` (a ratchet — never lower it).
- PhpStorm inspections on changed PHP (`mcp__phpstorm__lint_files`): block on ERROR and WARNING.
- Run both suite legs before the PR: `php bin/phpunit` (SQLite) and `docker compose exec php vendor/bin/phpunit` (MySQL).
- Backend-only change; keep a native Swift iOS client viable (no client-facing surface changes here).

---

### Task 1: Split the wrapper-chain collapse out of `normalize()`

`normalize()` currently runs the collapse as its last step. Move the collapse into a new public method `collapseWrapperChains()` so the extractor can choose whether to apply it. `normalize()` keeps returning score-neutral HTML; `collapseWrapperChains()` returns its input **unchanged** when there is no chain to collapse, so an unchanged page needs no second extraction downstream.

**Files:**
- Modify: `backend/src/Service/Reader/FetchedPageNormalizer.php`
- Test: `backend/tests/Service/Reader/FetchedPageNormalizerTest.php`

**Interfaces:**
- Produces:
  - `FetchedPageNormalizer::normalize(string $html): string` — script/style strip, lazy-image restore, screen-reader-only removal, orphan-glyph strip. **No wrapper collapse.**
  - `FetchedPageNormalizer::collapseWrapperChains(string $html): string` — collapse single-child `<div>` chains; returns `$html` unchanged when nothing collapses.

- [ ] **Step 1: Move the four wrapper-collapse tests onto the new method and add a no-op test**

In `FetchedPageNormalizerTest.php`, change the call under test from `$this->normalizer->normalize($html)` to `$this->normalizer->collapseWrapperChains($html)` in exactly these four methods: `testCollapsesSingleChildDivChains`, `testKeepsDivWithMultipleElementChildren`, `testKeepsDivWithOwnText`, `testHeadingSurvivesWrapperCollapse`. Leave every other test calling `normalize()` unchanged. Then add:

```php
    public function testCollapseReturnsInputUnchangedWhenNoWrapperChains(): void
    {
        // A page with no single-child <div> chain must come back byte-for-byte,
        // so ArticleExtractor can skip the second extraction.
        /** @noinspection HtmlRequiredLangAttribute */
        $html = '<html><body><div class="keep"><p>One</p><p>Two</p></div></body></html>';

        self::assertSame($html, $this->normalizer->collapseWrapperChains($html));
    }
```

- [ ] **Step 2: Run the normalizer tests to see the four moved tests and the new one fail**

Run: `cd backend && php bin/phpunit tests/Service/Reader/FetchedPageNormalizerTest.php`
Expected: FAIL — `Error: Call to undefined method …::collapseWrapperChains()`.

- [ ] **Step 3: Implement the split in `FetchedPageNormalizer`**

Remove the `$this->unwrapSingleChildDivs($document);` line from `normalize()`. Add the new public method after `normalize()`, and change `unwrapSingleChildDivs` to return the number of collapses:

```php
    /**
     * Collapse chains of single-child <div> wrappers so readability's score
     * propagation reaches the real article container (#235). Kept separate from
     * normalize() because the same collapse can flip a well-structured page to
     * the wrong block (#476): ArticleExtractor extracts with and without it and
     * keeps the richer result. The input is returned unchanged when there is no
     * chain to collapse, so an unaffected page costs no second extraction.
     */
    public function collapseWrapperChains(string $html): string
    {
        $document = $this->parse($html);
        if ($document === null) {
            return $html;
        }

        if ($this->unwrapSingleChildDivs($document) === 0) {
            return $html;
        }

        $collapsed = $document->saveHTML();

        return $collapsed === false ? $html : $collapsed;
    }
```

Change the collapse counter method signature and body:

```php
    private function unwrapSingleChildDivs(\DOMDocument $document): int
    {
        $divs = iterator_to_array($document->getElementsByTagName('div'));
        // Reverse document order visits descendants before their ancestors, so
        // one pass collapses a whole wrapper chain from the inside out.
        $collapsed = 0;
        foreach (array_reverse($divs) as $div) {
            $child = $this->soleDivChild($div);
            if ($child !== null && $div->parentNode !== null) {
                $div->parentNode->replaceChild($child, $div);
                ++$collapsed;
            }
        }

        return $collapsed;
    }
```

Update the class-level docblock: the fourth bullet (the wrapper-collapse paragraph) now describes `collapseWrapperChains()`, no longer part of `normalize()`. Add one sentence noting the two-method split and that `normalize()` is the score-neutral pass.

- [ ] **Step 4: Run the normalizer tests to green**

Run: `cd backend && php bin/phpunit tests/Service/Reader/FetchedPageNormalizerTest.php`
Expected: PASS (all cases).

- [ ] **Step 5: Quality gates on the touched files**

Run: `cd backend && composer cs && composer stan && composer md && composer tramp`
Then PhpStorm inspections on `src/Service/Reader/FetchedPageNormalizer.php` and the test file via `mcp__phpstorm__lint_files`; resolve every ERROR and WARNING.
Expected: all clean.

- [ ] **Step 6: Commit**

```bash
git add backend/src/Service/Reader/FetchedPageNormalizer.php backend/tests/Service/Reader/FetchedPageNormalizerTest.php
git commit -m "refactor(#476): split wrapper-chain collapse out of normalize()"
```

---

### Task 2: Extract both candidates and keep the richer in `ArticleExtractor`

Replace the single readability parse with a dual extraction: parse the conservative (neutral) candidate and the collapsed candidate, and keep the `Article` with the longer `textContent`. The regression fixture `article-shopify-promo.html` (a real capture of entry 466491, already in the repo) extracts the article correctly on the conservative candidate but the promo block on the collapsed candidate, so it fails on today's always-collapse code and passes after the fix. The existing `article-block-components.html` (#235) is the opposite case — the collapsed candidate is richer — and must still pass.

**Files:**
- Modify: `backend/src/Service/Reader/ArticleExtractor.php`
- Test: `backend/tests/Service/Reader/ArticleExtractorTest.php`
- Fixture (already present): `backend/tests/Fixtures/reader/article-shopify-promo.html`

**Interfaces:**
- Consumes: `FetchedPageNormalizer::normalize()` and `FetchedPageNormalizer::collapseWrapperChains()` from Task 1; `PageResponse { public string $finalUrl; public string $html; }`; `fivefilters\Readability\Article { public ?string $textContent; public ?string $content; public ?string $title; … }`.

- [ ] **Step 1: Write the failing #476 regression test**

The tests build the extractor with the private helper `extractor(iterable $responses, …)` and drive it with a `MockResponse`; requests resolve against the default DNS map for `site.test` (see `testKeepsHeadingsAndImagesOnBlockComponentPages` at line 154). Follow that exactly — serve the fixture from a `site.test` URL (the host does not matter to the content assertions). Add:

```php
    public function testKeepsTheArticleWhenCollapsingWouldElevatePublisherChrome(): void
    {
        // A real Shopify blog capture (#476, entry 466491). Readability extracts
        // the article on the neutral candidate, but the wrapper-chain collapse
        // flips the winner to the promo banner. Dual extraction keeps the richer
        // (article) result.
        $html = (string) file_get_contents(__DIR__ . '/../../Fixtures/reader/article-shopify-promo.html');
        $extractor = $this->extractor([new MockResponse($html, ['http_code' => 200])]);

        $result = $extractor->extract('https://site.test/post');

        self::assertTrue($result->ok);
        self::assertStringContainsString('Hand aufs Herz', (string) $result->contentHtml);
        self::assertStringNotContainsString('DU MAGST DEN ANKERHERZ BLOG', (string) $result->contentHtml);
    }
```

- [ ] **Step 2: Run the new test to verify it fails on current code**

Run: `cd backend && php bin/phpunit tests/Service/Reader/ArticleExtractorTest.php --filter testKeepsTheArticleWhenCollapsingWouldElevatePublisherChrome`
Expected: FAIL — `contentHtml` contains "DU MAGST DEN ANKERHERZ BLOG" (current code always collapses and picks the promo).

- [ ] **Step 3: Implement dual-extract in `ArticleExtractor`**

Add the import `use fivefilters\Readability\Article;` next to the other `fivefilters` imports. Replace the body of `extract()` from the `$readability = …` block through the `parse()` call with a single call to a new private `richestArticle()`, and add the three private helpers. The final `extract()`:

```php
    public function extract(string $url, ?string $entryTitle = null): ExtractionResult
    {
        try {
            $page = $this->fetcher->fetch($url);
        } catch (PageFetchException) {
            return ExtractionResult::failed($url, 'fetch');
        }

        $article = $this->richestArticle($page);
        if ($article === null) {
            return ExtractionResult::failed($url, 'unextractable');
        }

        if ($article->content === null || !$article->hasContent()) {
            return ExtractionResult::failed($url, 'empty');
        }
        if (mb_strlen(trim((string) $article->textContent)) < self::MIN_CONTENT_LENGTH) {
            return ExtractionResult::failed($url, 'empty');
        }

        $withoutTitle = $this->titleRemover->remove($article->content, [$article->title, $entryTitle]);
        $clean = $this->sanitizer->sanitize($withoutTitle);
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
            image: $this->leadImage($article->image, $clean),
        );
    }
```

Add the helpers (place them above `leadImage()`):

```php
    /**
     * Extract the page twice — with the score-neutral repairs only, and with the
     * wrapper-chain collapse (#235) as well — and keep the richer result. The
     * collapse rescues block-component pages (#235) and breaks some
     * well-structured ones (#476); the longer body is the better one in both
     * directions. The second parse is skipped when the collapse changed nothing.
     */
    private function richestArticle(PageResponse $page): ?Article
    {
        $conservative = $this->normalizer->normalize($page->html);
        $collapsed = $this->normalizer->collapseWrapperChains($conservative);

        $fromConservative = $this->parse($conservative, $page->finalUrl);
        $fromCollapsed = $collapsed === $conservative
            ? null
            : $this->parse($collapsed, $page->finalUrl);

        return $this->richer($fromConservative, $fromCollapsed);
    }

    private function parse(string $html, string $finalUrl): ?Article
    {
        $readability = new Readability(new Configuration(
            fixRelativeURLs: true,
            originalURL: $finalUrl,
        ));

        try {
            return $readability->parse($html);
        } catch (ParseException) {
            return null;
        }
    }

    /** Keep the extraction with more readable text; a tie keeps the conservative one. */
    private function richer(?Article $conservative, ?Article $collapsed): ?Article
    {
        if ($conservative === null) {
            return $collapsed;
        }
        if ($collapsed === null) {
            return $conservative;
        }

        return $this->textLength($collapsed) > $this->textLength($conservative)
            ? $collapsed
            : $conservative;
    }

    private function textLength(Article $article): int
    {
        return mb_strlen(trim((string) $article->textContent));
    }
```

- [ ] **Step 4: Run the new test to green and the existing #235 test to confirm no regression**

Run: `cd backend && php bin/phpunit tests/Service/Reader/ArticleExtractorTest.php`
Expected: PASS — the new #476 test **and** the existing block-component (#235) test both green.

- [ ] **Step 5: Add a no-collapse-path test so the identical-candidate branch is exercised**

Add a test that a page with no wrapper chains still extracts (this drives the `$collapsed === $conservative` skip branch). Reuse an existing simple fixture:

```php
    public function testExtractsAPageThatNeedsNoWrapperCollapse(): void
    {
        // article.html has no single-child <div> chain, so the collapsed
        // candidate equals the conservative one and only one parse runs.
        $html = (string) file_get_contents(__DIR__ . '/../../Fixtures/reader/article.html');
        $extractor = $this->extractor([new MockResponse($html, ['http_code' => 200])]);

        $result = $extractor->extract('https://site.test/post');

        self::assertTrue($result->ok);
        self::assertStringContainsString('First su', (string) $result->contentHtml);
    }
```

Adjust the asserted substring to text actually present in `article.html` (open the fixture and pick a stable phrase from its body).

- [ ] **Step 6: Run the full reader test group**

Run: `cd backend && php bin/phpunit tests/Service/Reader/`
Expected: PASS.

- [ ] **Step 7: Quality gates on the touched files**

Run: `cd backend && composer cs && composer stan && composer md && composer tramp`
Then PhpStorm inspections on `src/Service/Reader/ArticleExtractor.php` and the test file; resolve every ERROR and WARNING.
Expected: all clean.

- [ ] **Step 8: Commit**

```bash
git add backend/src/Service/Reader/ArticleExtractor.php backend/tests/Service/Reader/ArticleExtractorTest.php backend/tests/Fixtures/reader/article-shopify-promo.html
git commit -m "fix(#476): extract with and without wrapper collapse, keep the richer result"
```

---

### Task 3: Full verification

- [ ] **Step 1: Full SQLite suite**

Run: `cd backend && php bin/phpunit`
Expected: PASS (watch for unrelated known flakes noted in project memory — the MySQL rate-limiter flake does not apply to SQLite).

- [ ] **Step 2: MySQL leg**

Run: `docker compose up -d && docker compose exec php vendor/bin/phpunit tests/Service/Reader/`
Expected: PASS. (If the stack is not current, restart per the project's "verify containers are current" note.)

- [ ] **Step 3: Mutation gate on the diff**

Run: `cd backend && composer infection:diff`
Expected: `minMsi` holds. Escaped mutants arrive as annotations. Likely-surviving spot: the boundary in `richer()` (`>` vs `>=`). The block-component fixture (collapsed longer) and the shopify-promo fixture (conservative longer) cover both directions of the comparison. If the boundary mutant survives and breaches `minMsi`, add a targeted test with two candidates of equal `textContent` length asserting the conservative one is kept — do **not** lower `minMsi`.

- [ ] **Step 4: Scan the backend dev log**

Run: `cd backend && tail -n 40 var/log/dev.log`
Expected: no new deprecations or swallowed errors from the reader path.

- [ ] **Step 5: Check dev.log after — confirm no regressions, then hand back**

Stop here for the `/simplify` pass and PR (driven by the session, not a subagent).

---

## Self-Review

- **Spec coverage:** normalizer split (Task 1) ✓; dual-extract keep-richer (Task 2) ✓; #476 regression fixture + #235 guard (Task 2) ✓; selector coverage both directions + identical-candidate path (Task 2 steps 1/5, Task 3 step 3) ✓; error handling (both-fail → `unextractable`, below-min → `empty`) preserved in `extract()` ✓; both suite legs + mutation gate (Task 3) ✓. The `\Dom\HTMLDocument` migration is out of scope (tracked in #480) ✓; client cache intentionally ignored per the user ✓.
- **Placeholder scan:** none — all steps carry real code or exact commands. The two "match the existing test helper" notes are deliberate (the plan must follow whatever stubbing shape `ArticleExtractorTest` already uses) and name the concrete anchor to copy.
- **Type consistency:** `normalize`/`collapseWrapperChains` return `string`; `richestArticle(PageResponse): ?Article`; `parse(string,string): ?Article`; `richer(?Article,?Article): ?Article`; `textLength(Article): int`. `Article::textContent`/`content`/`title` used as in the current file.
