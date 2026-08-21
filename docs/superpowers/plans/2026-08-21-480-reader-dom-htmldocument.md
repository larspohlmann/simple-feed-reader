# Reader `\Dom\HTMLDocument` Migration Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Migrate the reader normalization pipeline from the legacy libxml `\DOMDocument` API to PHP 8.4's `\Dom\HTMLDocument`, and hand the parsed document straight to readability instead of round-tripping through a serialized string.

**Architecture:** `FetchedPageNormalizer` and `LazyImageSources` parse with libxml `\DOMDocument` and serialize to a string; `ArticleExtractor` then hands that string to `fivefilters/readability.php` v4.0.0, which re-parses it with lexbor (`\Dom\HTMLDocument`). So each page is parsed twice with two different parsers. This plan parses once with the HTML5 parser readability already uses and returns the `\Dom\HTMLDocument` object, which `Readability::parse()` accepts directly — deleting the serialize→re-parse round-trip.

**Tech Stack:** PHP 8.4, `\Dom\HTMLDocument` / `\Dom\Element` / `\Dom\Text` / `\Dom\XPath` (lexbor, HTML5-spec), PHPUnit, `fivefilters/readability.php` v4.0.0.

## Global Constraints

- `declare(strict_types=1)` in every PHP file.
- PSR-12 (`composer cs`), PHPStan level max over src and tests (`composer stan`, warm dev cache first), PHPMD codesize (`composer md`), phptramp (`composer tramp`). All must be clean on touched files — no new baselines, no threshold tuning.
- Clean Code house style: `final readonly class`, constructor promotion, guard clauses, names reveal intent, comments explain *why*, no dead code, tests are production code.
- Mutation gate: `composer infection:diff` over touched lines; `minMsi` in `infection.json5` is a ratchet — never lower it.
- Pure backend change — keep a native Swift iOS client viable (no client surface touched).
- **Behavioral invariant:** the reader fixture corpus must extract *identically* after the swap. This is cleanup + correctness hygiene, not a behavior change.

## Verified API facts (probed on PHP 8.4.23, lexbor)

These were confirmed with runnable probes before planning — the port depends on them:

1. `\Dom\HTMLDocument::createFromString($html, LIBXML_NOERROR)` parses; it can throw `\Throwable` on unparseable input (wrap in try/catch, house style in `HtmlItemExtractor`/`FeedLinkScanner`).
2. `\Dom\Element::getAttribute()` returns **`null`** (not `''`) for an absent attribute.
3. `nodeName` is **upper-case** (`DIV`); `localName` is lower-case (`div`) — compare tag names with `localName`.
4. `getElementsByTagName('div')` matches ASCII-case-insensitively and works on `\Dom\HTMLDocument`.
5. `\Dom\XPath` with `//*[@class]` and `//text()` works with no namespace registration; `query()` returns a `\Dom\NodeList` (never `false`) and throws on a bad expression.
6. Text nodes are `\Dom\Text`; `\Dom\Node::nodeValue` and `::textContent` are typed `?string`.
7. Serializer method is `saveHtml()` (returns `string`, never `false`); it keeps non-ASCII as UTF-8 (no numeric-entity encoding — the `mb_encode_numericentity` dance is gone).
8. The HTML5 parser treats `<source>` as a **void element**, so a `<picture>`'s `<img>` is always its **direct child** — the libxml walk-up-through-`<source>` loop in `LazyImageSources::enclosingPicture()` is dead code and must be removed.

## File Structure

- `backend/src/Service/Reader/LazyImageSources.php` — `resolveIn(\Dom\HTMLDocument)`; port all node handling; simplify `enclosingPicture()` to a direct-parent check.
- `backend/src/Service/Reader/FetchedPageNormalizer.php` — `normalize()` and `collapseWrapperChains()` return `?\Dom\HTMLDocument`; extract a shared private `repair()`; parse via `createFromString`.
- `backend/src/Service/Reader/ArticleExtractor.php` — `richestArticle()` passes documents (not strings) to a `parse(?\Dom\HTMLDocument, string)` that early-returns `null`.
- `backend/tests/Service/Reader/LazyImageSourcesTest.php` — build the document with `createFromString`; serialize with `saveHtml()`.
- `backend/tests/Service/Reader/FetchedPageNormalizerTest.php` — serialize the returned document; adapt the no-collapse / empty-input cases to the `null` contract; add a whitespace-indented-chain test.
- `backend/src/Service/Reader/LeadingTitleRemover.php` — port `loadDocument()` to `createFromString`; find the first heading with `querySelector('h1, h2, h3')` (an element-named XPath does **not** match under lexbor's XHTML namespace); drop the now-dead `saveHTML()===false` fallback and the always-attached `parentNode` guard.
- `backend/tests/Service/Reader/ArticleExtractorTest.php` — **no change**; this is the fixture-corpus regression harness and must stay green untouched.
- `backend/tests/Service/Reader/LeadingTitleRemoverTest.php` — **no change**; the existing tests already cover the ported code (Infection confirms 0 new escaped mutants).

## Interface contract (locked across tasks)

- `FetchedPageNormalizer::normalize(string $html): ?\Dom\HTMLDocument` — score-neutral document, or `null` when empty/unparseable.
- `FetchedPageNormalizer::collapseWrapperChains(string $html): ?\Dom\HTMLDocument` — the document with single-child `<div>` chains collapsed, or **`null` when nothing collapsed** (this is the signal `ArticleExtractor` uses to skip the second extraction — it replaces the old "returns the input string unchanged" signal).
- `LazyImageSources::resolveIn(\Dom\HTMLDocument $document): void`.
- `ArticleExtractor::parse(?\Dom\HTMLDocument $document, string $finalUrl): ?Article` — `null` in ⇒ `null` out.

---

### Task 1: Port `LazyImageSources` to `\Dom\*`

**Files:**
- Modify: `backend/src/Service/Reader/LazyImageSources.php`
- Test: `backend/tests/Service/Reader/LazyImageSourcesTest.php`

**Interfaces:**
- Produces: `resolveIn(\Dom\HTMLDocument $document): void`.
- Consumes: nothing from other tasks (leaf collaborator).

- [ ] **Step 1: Update the test harness to the new parser (still failing against old prod code)**

In `LazyImageSourcesTest.php`, replace the three helpers:

```php
/** The `src` the resolver leaves on the first image, or null if it removed it. */
private function resolvedSource(string $bodyHtml): ?string
{
    $image = $this->resolvedDocument($bodyHtml)->getElementsByTagName('img')->item(0);

    return $image instanceof \Dom\Element ? $image->getAttribute('src') : null;
}

private function resolvedHtml(string $bodyHtml): string
{
    return $this->resolvedDocument($bodyHtml)->saveHtml();
}

private function resolvedDocument(string $bodyHtml): \Dom\HTMLDocument
{
    $document = \Dom\HTMLDocument::createFromString(
        '<html lang="en"><body>' . $bodyHtml . '</body></html>',
        LIBXML_NOERROR,
    );

    $this->lazyImages->resolveIn($document);

    return $document;
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `php bin/phpunit tests/Service/Reader/LazyImageSourcesTest.php`
Expected: FAIL — `resolveIn()` still type-hints `\DOMDocument`, so `TypeError` on the new document.

- [ ] **Step 3: Port the production class**

In `LazyImageSources.php`: add `use Dom\Element;` and `use Dom\HTMLDocument;`. Change every `\DOMDocument`→`HTMLDocument` and `\DOMElement`→`Element`. Coerce nullable attribute reads:
- `resolveIn(HTMLDocument $document)`, iterate `iterator_to_array($document->getElementsByTagName('img'))`.
- `isUsable(?string $url): bool` → `return $url !== null && $url !== '' && preg_match(self::FOREIGN_SCHEME, $url) !== 1;`
- In `candidateFor()`: `trim($image->getAttribute($attribute) ?? '')` and `$this->usableSrcsetHead($image->getAttribute($attribute) ?? '')`.
- In `candidateFromEnclosingPicture()`: `$source->getAttribute('srcset') ?? ''`.
- Replace `enclosingPicture()` with the direct-parent check (delete the `while` walk and its stale libxml comment):

```php
/**
 * The <picture> an image belongs to. The HTML5 parser treats <source> as a
 * void element, so the candidates and the <img> stay siblings under the
 * <picture> however the page spells its source tags — the image is the
 * picture's direct child.
 */
private function enclosingPicture(Element $image): ?Element
{
    $parent = $image->parentNode;

    return $parent instanceof Element && $parent->localName === 'picture' ? $parent : null;
}
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `php bin/phpunit tests/Service/Reader/LazyImageSourcesTest.php`
Expected: PASS (16 tests). The self-closed / unclosed / multi-source picture cases all still resolve because the `<img>` is a direct child of `<picture>` under lexbor.

- [ ] **Step 5: Commit**

```bash
git add backend/src/Service/Reader/LazyImageSources.php backend/tests/Service/Reader/LazyImageSourcesTest.php
git commit -m "refactor(reader): port LazyImageSources to \Dom\HTMLDocument"
```

---

### Task 2: Port `FetchedPageNormalizer`, return the document

**Files:**
- Modify: `backend/src/Service/Reader/FetchedPageNormalizer.php`
- Test: `backend/tests/Service/Reader/FetchedPageNormalizerTest.php`

**Interfaces:**
- Consumes: `LazyImageSources::resolveIn(\Dom\HTMLDocument)` (Task 1).
- Produces: `normalize(string): ?\Dom\HTMLDocument`, `collapseWrapperChains(string): ?\Dom\HTMLDocument`.

- [ ] **Step 1: Adapt the tests to serialize the returned document + the `null` contract**

Add two private helpers and rewrite the assertions to run through them:

```php
/** normalize() then serialize; the fixtures under test always parse. */
private function normalized(string $html): string
{
    $document = $this->normalizer->normalize($html);
    self::assertNotNull($document);

    return $document->saveHtml();
}

/** collapseWrapperChains() then serialize; used only where a chain collapses. */
private function collapsed(string $html): string
{
    $document = $this->normalizer->collapseWrapperChains($html);
    self::assertNotNull($document);

    return $document->saveHtml();
}
```

Convert the string-return tests: the collapse tests and the `removeScreenReaderOnlyElements` / glyph / script / style / lazy-image tests call `normalized()` / `collapsed()` instead of asserting on the method's old string return. Convert the three "does not collapse" tests to `self::assertNull($this->normalizer->collapseWrapperChains($html));` and rename `testCollapseReturnsInputUnchangedWhenNoWrapperChains` → `testCollapseReturnsNullWhenNoWrapperChains`. Convert `testEmptyInputIsReturnedUnchanged` → `testEmptyInputYieldsNull` asserting `assertNull($this->normalizer->normalize(''))` and the whitespace-only variant.

- [ ] **Step 2: Run to verify it fails**

Run: `php bin/phpunit tests/Service/Reader/FetchedPageNormalizerTest.php`
Expected: FAIL — `normalize()` still returns a string, so `->saveHtml()` errors / `assertNull` fails.

- [ ] **Step 3: Port the production class**

In `FetchedPageNormalizer.php`: add `use Dom\Element; use Dom\HTMLDocument; use Dom\Text; use Dom\XPath;`. Then:
- `normalize(string $html): ?HTMLDocument { return $this->repair($html); }`
- `collapseWrapperChains(string $html): ?HTMLDocument` — `repair()`, then `if ($document === null || $this->unwrapSingleChildDivs($document) === 0) { return null; }` else return the document.
- New private `repair()`: `parse(removeScriptAndStyleBlocks($html))`, guard null, then `resolveIn` / `removeScreenReaderOnlyElements` / `removeOrphanIconGlyphs`, return the document.
- `parse(string $html): ?HTMLDocument` — trim-empty guard returns null; `try { return HTMLDocument::createFromString($html, \LIBXML_NOERROR); } catch (\Throwable) { return null; }`. Delete the `mb_encode_numericentity` line and the `libxml_*` juggling.
- `removeScreenReaderOnlyElements(HTMLDocument)` / `removeOrphanIconGlyphs(HTMLDocument)` — iterate a private `query()` helper; `$element->getAttribute('class') ?? ''`; keep the `$text = $node->nodeValue; if ($text === null) continue;` guard (PHPStan sees `?string`).
- `pruneWhileEmpty`/`holdsEmbeddedContent`/`unwrapSingleChildDivs`/`soleDivChild` on `Element`/`Text`; tag comparisons use `localName === 'div'` and `in_array($descendant->localName, self::EMBEDDED_TAGS, true)`; wrap textContent reads as `trim((string) $node->textContent)`.
- Add: `private function query(HTMLDocument $document, string $expression): array { return iterator_to_array((new XPath($document))->query($expression), false); }` with `@return list<\Dom\Node>` (the `, false` makes it a list for PHPStan).
- Update the class docblock: the pipeline now parses once and hands on the object; the `<script>`/`<style>` strip stays to keep their text (and any JSON-LD block) out of the extraction, which preserves identical behavior.

- [ ] **Step 4: Add the whitespace-indented-chain test (kills the trim mutants)**

```php
public function testCollapsesWrapperChainsIndentedWithWhitespace(): void
{
    // Real block-component markup indents its wrappers, so whitespace text
    // sits between each <div> and its single child. That whitespace is not
    // the wrapper's own content; without trimming it, the chain would never
    // collapse.
    /** @noinspection HtmlRequiredLangAttribute */
    $html = "<html><body><div class=\"a\">\n<div class=\"b\">\n"
        . "<div class=\"c\">\n<p>Text</p>\n</div>\n</div>\n</div></body></html>";

    $collapsed = $this->collapsed($html);

    self::assertStringNotContainsString('class="a"', $collapsed);
    self::assertStringNotContainsString('class="b"', $collapsed);
    self::assertStringContainsString('<p>Text</p>', $collapsed);
}
```

- [ ] **Step 5: Run to verify it passes**

Run: `php bin/phpunit tests/Service/Reader/FetchedPageNormalizerTest.php`
Expected: PASS (15 tests).

- [ ] **Step 6: Commit**

```bash
git add backend/src/Service/Reader/FetchedPageNormalizer.php backend/tests/Service/Reader/FetchedPageNormalizerTest.php
git commit -m "refactor(reader): FetchedPageNormalizer parses once, returns \Dom\HTMLDocument"
```

---

### Task 3: Hand the document straight to readability in `ArticleExtractor`

**Files:**
- Modify: `backend/src/Service/Reader/ArticleExtractor.php`
- Test (regression harness, unchanged): `backend/tests/Service/Reader/ArticleExtractorTest.php`

**Interfaces:**
- Consumes: `normalize()` / `collapseWrapperChains()` returning `?\Dom\HTMLDocument` (Task 2).
- Produces: `richestArticle()` unchanged externally; `parse(?\Dom\HTMLDocument, string): ?Article`.

- [ ] **Step 1: Confirm the corpus is green before the change**

Run: `php bin/phpunit tests/Service/Reader/ArticleExtractorTest.php`
Expected: PASS — this is the identical-extraction baseline.

- [ ] **Step 2: Rewire `richestArticle()` and `parse()`**

Add `use Dom\HTMLDocument;`. Replace both methods:

```php
private function richestArticle(PageResponse $page): ?Article
{
    $conservative = $this->parse($this->normalizer->normalize($page->html), $page->finalUrl);
    $collapsed = $this->parse($this->normalizer->collapseWrapperChains($page->html), $page->finalUrl);

    return $this->richer($conservative, $collapsed);
}

private function parse(?HTMLDocument $document, string $finalUrl): ?Article
{
    if ($document === null) {
        return null;
    }

    $readability = new Readability(new Configuration(
        fixRelativeURLs: true,
        originalURL: $finalUrl,
    ));

    try {
        return $readability->parse($document);
    } catch (ParseException) {
        return null;
    }
}
```

Update the `richestArticle()` docblock: `collapseWrapperChains()` returns `null` when there is no chain to collapse, so the second extraction is skipped.

- [ ] **Step 3: Run the corpus to verify identical extraction**

Run: `php bin/phpunit tests/Service/Reader/ArticleExtractorTest.php`
Expected: PASS — every fixture (article, lazy-images, lead-image, distinct-hero, block-components, shopify-promo) extracts as before.

- [ ] **Step 4: Commit**

```bash
git add backend/src/Service/Reader/ArticleExtractor.php
git commit -m "refactor(reader): pass the normalized \Dom\HTMLDocument straight to readability"
```

---

### Task 4: Port `LeadingTitleRemover` (last libxml collaborator in `Service/Reader`)

**Files:**
- Modify: `backend/src/Service/Reader/LeadingTitleRemover.php`
- Test (unchanged, already covers the port): `backend/tests/Service/Reader/LeadingTitleRemoverTest.php`

**Interfaces:**
- Public `remove(string $contentHtml, array $titleCandidates): string` is unchanged — this collaborator works on readability's *output* fragment (string in, string out) and shares no document with the rest of the pipeline.

- [ ] **Step 1: Confirm the tests are green before the change**

Run: `php bin/phpunit tests/Service/Reader/LeadingTitleRemoverTest.php`
Expected: PASS — the behavioral baseline.

- [ ] **Step 2: Port the class**

Add `use Dom\Element; use Dom\HTMLDocument;`. Then:
- `loadDocument(string $contentHtml): ?HTMLDocument` — `try { return HTMLDocument::createFromString($contentHtml, \LIBXML_NOERROR); } catch (\Throwable) { return null; }`. Drop the `mb_encode_numericentity`/`libxml_*` juggling.
- `findFirstHeading(HTMLDocument $document): ?Element` — `return $document->querySelector('h1, h2, h3');` with a comment: an element-named XPath (`//h1`) would not match under the HTML5 parser's XHTML namespace, so read the tree with a CSS selector. **This is the trap** — a naive `(//h1|//h2|//h3)[1]` XPath port silently finds nothing and no title is ever removed.
- `repeatsTitle(Element $heading, array $normalizedTitles): bool` — drop the always-false `$heading->parentNode === null` guard (a `querySelector` hit is always attached); cast `(string) $heading->textContent`.
- Inline the heading removal into `remove()`: `$firstHeading->remove(); return $document->saveHtml();`. `saveHtml()` never returns `false`, so the old `removeHeading()` helper and its `$fallback` param are dead — delete them.

- [ ] **Step 3: Run the tests to verify they still pass**

Run: `php bin/phpunit tests/Service/Reader/LeadingTitleRemoverTest.php tests/Service/Reader/ArticleExtractorTest.php`
Expected: PASS — the public behavior is identical; the corpus still drops duplicated headlines.

- [ ] **Step 4: Commit**

```bash
git add backend/src/Service/Reader/LeadingTitleRemover.php
git commit -m "refactor(reader): port LeadingTitleRemover to \Dom\HTMLDocument"
```

---

### Task 5: Full verification and quality gates

**Files:** none (verification only).

- [ ] **Step 1: Full backend suite**

Run: `php bin/phpunit`
Expected: PASS (all tests).

- [ ] **Step 2: Warm cache + combined gate**

Run: `bin/console cache:warmup --env=dev && composer check`
Expected: `cs` clean, `stan` "No errors", `tramp` 0 errors (pre-existing warnings in Scraper/Backup are out of scope).

- [ ] **Step 3: PHPMD on touched files**

Run: `composer md`
Expected: exit 0, no findings.

- [ ] **Step 4: Mutation gate on the diff**

Run: `TEST_TOKEN=inf composer infection:diff`
Expected: MSI over the ratchet. Remaining escaped mutants must be only the two equivalent ones — the defensive `$element instanceof Element` guard (XPath `//*[@class]` returns only elements) and the `iterator_to_array(..., false)` preserve-keys argument (kept for PHPStan's `list<>`). Any *other* escaped mutant is a real gap — add a test.

- [ ] **Step 5: Scan the dev log**

Run: `tail -n 40 backend/var/log/dev.log`
Expected: no new deprecations or swallowed errors from the reader path.

- [ ] **Step 6: PhpStorm inspections (if the MCP is connected)**

Run `mcp__phpstorm__lint_files` on the three changed `src/` files; block on ERROR/WARNING. If the PhpStorm MCP is not connected in this session, record that the gate could not be run and leave it for CI / the reviewer.

---

## Self-Review

**Spec coverage** (issue #480):
- "Rewrite `parse()` to `createFromString(..., LIBXML_NOERROR)`" → Task 2, Step 3.
- "Port mutation steps and `LazyImageSources` to `\Dom\*`, note API differences (`getAttribute` null, upper-case `nodeName`)" → Task 1 + Task 2 (uses `localName`, `?? ''`).
- "`normalize()` returns the `\Dom\HTMLDocument`, pass it straight to `readability->parse()`, remove the round-trip" → Task 2 (return type) + Task 3 (`parse(?HTMLDocument)`).
- "Re-verify the whole reader fixture corpus extracts identically" → Task 3 Steps 1/3 + Task 4 Step 1.
- "Pure backend change, keep native iOS viable" → no client surface touched; Global Constraints.

**Placeholder scan:** none — every code step carries the actual code.

**Type consistency:** `?\Dom\HTMLDocument` is the single return/parameter shape across Tasks 2 and 3; `null` is the uniform "nothing to do" signal (`normalize` empty, `collapseWrapperChains` no-op, `parse` null-in). `enclosingPicture`, `soleDivChild`, `holdsEmbeddedContent` consistently use `localName`.

**Extra design note captured during implementation:** `collapseWrapperChains()` is deliberately called on the *raw* page HTML (not on `normalize()`'s output), re-running `repair()`, because readability mutates each document it parses — the conservative and collapsed variants must be two independent documents. This still halves the HTML parses versus the old pipeline (2 parses vs. 4 across two parsers), removing the round-trip the issue targets.
