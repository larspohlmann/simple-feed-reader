# Media Scanner Shared Parse Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Parse the raw article page once per `PageMediaScanner::scan()` and share that one parse (and the prose blocks derived from it) with every media source, instead of each source re-parsing the page.

**Architecture:** Introduce a `RawPage` value object that holds the raw markup, one `?HTMLDocument` parse of it, the page URL, and the `PageTextBlocks` derived from that parse. `MediaCandidateSourceInterface::find()` takes a `RawPage` instead of `(string $pageHtml, string $pageUrl)`. The scanner builds one `RawPage` and hands it to all sources. This collapses two redundancies at once: 8 document parses → 1, and 5 `PageTextBlocks::fromDocument()` rebuilds → 1. All sources are read-only on the document, so one shared instance is safe (verified in the issue).

**Tech Stack:** PHP 8.4, Symfony 7.4, `\Dom\HTMLDocument` (lexbor via `HtmlDocumentParser`), PHPUnit 12.

**Spec:** GitHub issue #1090 (`Reader: PageMediaScanner parses the raw page 7 times; share one parse across sources`). Note: the issue lists 7 sources; there are now **8** — `ZdfPlayerConfigSource` was added by #1055 and works from the raw string only (regex), never parsing.

## Global Constraints

- PHP: `declare(strict_types=1)` in every file; PSR-12; PHPStan level max over `src` and `tests`; PHPMD codesize clean on every touched `src` file.
- Clean Code house style: `final readonly class` with constructor promotion; names reveal intent; no boolean flag parameters; guard clauses over nesting; depend on interfaces; comments only for a genuinely non-obvious invariant (one line, three at most).
- Media discovery reads the **raw** page on purpose (media must be seen before `normalize` strips it, #748) — do not switch it to the normalised document.
- Sources must stay read-only on the shared document (no `remove`/`appendChild`/`setAttribute`/`textContent=` etc.).
- Gates: `composer check` (cs + stan + tramp), `composer md`, `php bin/phpunit`, `composer infection:diff`, PhpStorm inspections on changed PHP; scan today's dev log after backend work.

---

### Task 1: `RawPage` value object and `PageTextBlocks::none()`

**Files:**
- Create: `backend/src/Service/Reader/Media/RawPage.php`
- Modify: `backend/src/Service/Reader/Media/PageTextBlocks.php` (add `none()` factory)
- Test: `backend/tests/Service/Reader/Media/RawPageTest.php`

**Interfaces:**
- Consumes: `App\Service\Html\HtmlDocumentParser::parseOrNull(string): ?HTMLDocument`; `App\Service\Reader\Media\PageTextBlocks::fromDocument(HTMLDocument): self` and `before(Element): ?string`.
- Produces:
  - `RawPage::parse(string $html, string $url): self`
  - public readonly props: `?HTMLDocument $document`, `string $html`, `string $url`, `PageTextBlocks $blocks`
  - `PageTextBlocks::none(): self` (an empty block set)

- [ ] **Step 1: Write the failing test**

Create `backend/tests/Service/Reader/Media/RawPageTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Service\Reader\Media;

use App\Service\Reader\Media\PageTextBlocks;
use App\Service\Reader\Media\RawPage;
use Dom\HTMLDocument;
use PHPUnit\Framework\TestCase;

final class RawPageTest extends TestCase
{
    public function testKeepsTheRawMarkupAndUrl(): void
    {
        $html = '<html><body><p>Prose.</p></body></html>';

        $page = RawPage::parse($html, 'https://x.test/a');

        self::assertSame($html, $page->html);
        self::assertSame('https://x.test/a', $page->url);
    }

    public function testParsesTheMarkupIntoOneSharedDocument(): void
    {
        $page = RawPage::parse('<html><body><p>Prose.</p></body></html>', 'https://x.test/a');

        self::assertInstanceOf(HTMLDocument::class, $page->document);
    }

    public function testBlocksAnchorToTheProseThatPrecedesAMediaElement(): void
    {
        $html = '<html><body><p>A paragraph long enough to survive the cleaners.</p>'
            . '<video src="https://x.test/v.mp4"></video></body></html>';

        $page = RawPage::parse($html, 'https://x.test/a');

        $video = $page->document?->querySelector('video');
        self::assertNotNull($video);
        self::assertSame('A paragraph long enough to survive the cleaners.', $page->blocks->before($video));
    }

    public function testUnparseablePageHasNoDocumentButStillOffersEmptyBlocks(): void
    {
        $page = RawPage::parse('   ', 'https://x.test/a');

        self::assertNull($page->document);
        self::assertInstanceOf(PageTextBlocks::class, $page->blocks);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `cd backend && php bin/phpunit tests/Service/Reader/Media/RawPageTest.php`
Expected: FAIL — `Class "App\Service\Reader\Media\RawPage" not found`.

- [ ] **Step 3: Write minimal implementation**

Add to `backend/src/Service/Reader/Media/PageTextBlocks.php`, next to `fromDocument()`:

```php
    public static function none(): self
    {
        return new self([]);
    }
```

Create `backend/src/Service/Reader/Media/RawPage.php`:

```php
<?php

declare(strict_types=1);

namespace App\Service\Reader\Media;

use App\Service\Html\HtmlDocumentParser;
use Dom\HTMLDocument;

/**
 * One read of the raw article page, shared by every MediaCandidateSource: the
 * markup, a single parse of it, and the prose blocks derived from that parse.
 * Discovery reads the raw page on purpose — normalize strips media before it
 * runs (#748) — but the sources no longer each re-parse it (#1090).
 */
final readonly class RawPage
{
    private function __construct(
        public ?HTMLDocument $document,
        public string $html,
        public string $url,
        public PageTextBlocks $blocks,
    ) {
    }

    public static function parse(string $html, string $url): self
    {
        $document = HtmlDocumentParser::parseOrNull($html);
        $blocks = $document !== null ? PageTextBlocks::fromDocument($document) : PageTextBlocks::none();

        return new self($document, $html, $url, $blocks);
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `cd backend && php bin/phpunit tests/Service/Reader/Media/RawPageTest.php`
Expected: PASS (4 tests).

- [ ] **Step 5: Commit**

```bash
git add backend/src/Service/Reader/Media/RawPage.php backend/src/Service/Reader/Media/PageTextBlocks.php backend/tests/Service/Reader/Media/RawPageTest.php
git commit -m "feat(#1090): add RawPage carrying one shared page parse"
```

---

### Task 2: Migrate the interface, scanner, and all 8 sources to `RawPage`

This is one atomic change: the moment `MediaCandidateSourceInterface::find()` changes, every implementer and every caller must change with it or the suite will not load. Do it as a single task ending on a green suite.

**Files:**
- Modify: `backend/src/Service/Reader/Media/MediaCandidateSourceInterface.php`
- Modify: `backend/src/Service/Reader/Media/PageMediaScanner.php`
- Modify: `backend/src/Service/Reader/Media/Source/ScannedPage.php`
- Modify (sources): `MetaMediaSource.php`, `AttributeMediaSource.php`, `YouTubeIdAttributeSource.php`, `PageEmbedSource.php`, `ScriptEmbedSource.php`, `JsonLdMediaSource.php`, `SemanticMediaSource.php`, `ZdfPlayerConfigSource.php` — all in `backend/src/Service/Reader/Media/Source/`
- Test (drive): `backend/tests/Service/Reader/Media/PageMediaScannerTest.php`
- Test (migrate call sites): the 8 files in `backend/tests/Service/Reader/Media/Source/`

**Interfaces:**
- Consumes: `RawPage::parse()` and its props from Task 1.
- Produces: `MediaCandidateSourceInterface::find(RawPage $page): array` (`@return list<MediaCandidate>`); `ScannedPage::from(RawPage $page): self`.

- [ ] **Step 1: Write the failing test** (drives the shared-parse behaviour)

In `backend/tests/Service/Reader/Media/PageMediaScannerTest.php`, add `use App\Service\Reader\Media\RawPage;` and `use Dom\HTMLDocument;` to the imports, then add a recording double helper and the test:

```php
    private function recordingSource(): MediaCandidateSourceInterface
    {
        return new class implements MediaCandidateSourceInterface {
            public bool $found = false;
            public ?HTMLDocument $document = null;

            public function find(RawPage $page): array
            {
                $this->found = true;
                $this->document = $page->document;

                return [];
            }
        };
    }

    public function testParsesThePageOnceAndSharesOneDocumentWithEverySource(): void
    {
        $first = $this->recordingSource();
        $second = $this->recordingSource();

        $scanner = new PageMediaScanner([$first, $second]);
        $scanner->scan('<html><body><p>Prose.</p></body></html>', 'https://x.test/a');

        self::assertTrue($first->found);
        self::assertTrue($second->found);
        self::assertInstanceOf(HTMLDocument::class, $first->document);
        self::assertSame($first->document, $second->document);
    }
```

- [ ] **Step 2: Run test to verify it fails**

Run: `cd backend && php bin/phpunit tests/Service/Reader/Media/PageMediaScannerTest.php --filter testParsesThePageOnceAndSharesOneDocumentWithEverySource`
Expected: FAIL — the anonymous class's `find(RawPage $page)` does not match the current interface `find(string, string)`, so PHP raises a fatal/compile error. (This is the RED that proves the interface still needs changing.)

- [ ] **Step 3: Change the interface**

`backend/src/Service/Reader/Media/MediaCandidateSourceInterface.php` — replace the method signature:

```php
    /** @return list<MediaCandidate> */
    public function find(RawPage $page): array;
```

`RawPage` is in the same namespace (`App\Service\Reader\Media`), so no `use` is needed.

- [ ] **Step 4: Change the scanner**

`backend/src/Service/Reader/Media/PageMediaScanner.php` — in `scan()` replace the source loop:

```php
        $rawPage = RawPage::parse($pageHtml, $pageUrl);
        foreach ($this->sources as $source) {
            $this->mergeSource($source->find($rawPage), $byUrl);
        }
```

(`$pageHtml` / `$pageUrl` are still the method parameters; only the fan-out changes. `RawPage` is same-namespace.)

- [ ] **Step 5: Change `ScannedPage` to reuse the shared blocks**

`backend/src/Service/Reader/Media/Source/ScannedPage.php` — replace `from()` and `ogImage()`:

```php
    public static function from(RawPage $page): self
    {
        return new self($page->blocks, $page->url, self::ogImage($page->document));
    }

    private static function ogImage(?HTMLDocument $document): ?string
    {
        $content = $document?->querySelector(self::OG_IMAGE)?->getAttribute('content');

        return $content !== null && preg_match('#^https://#i', $content) === 1 ? $content : null;
    }
```

Add `use App\Service\Reader\Media\RawPage;` to the imports. Keep `use Dom\HTMLDocument;`.

- [ ] **Step 6: Migrate the 5 document+blocks sources**

For each of `JsonLdMediaSource`, `SemanticMediaSource`, `PageEmbedSource`, `YouTubeIdAttributeSource`, change the head of `find()` from:

```php
    public function find(string $pageHtml, string $pageUrl): array
    {
        $document = HtmlDocumentParser::parseOrNull($pageHtml);
        if ($document === null) {
            return [];
        }

        $blocks = PageTextBlocks::fromDocument($document);
```

to:

```php
    public function find(RawPage $page): array
    {
        $document = $page->document;
        if ($document === null) {
            return [];
        }

        $blocks = $page->blocks;
```

The rest of each body is unchanged (`$document->querySelectorAll(...)`, `$blocks->before(...)`). In each file: add `use App\Service\Reader\Media\RawPage;`, and delete the now-unused `use App\Service\Html\HtmlDocumentParser;`. `PageEmbedSource` and `YouTubeIdAttributeSource` still use `PageTextBlocks` only via `$page->blocks` — remove any now-unused `use ...PageTextBlocks;` **only if** the type name no longer appears in the file (it will not, since the local `$blocks` needs no import).

`AttributeMediaSource` — its `find()` builds `ScannedPage`, not `$blocks` directly:

```php
    public function find(RawPage $page): array
    {
        $document = $page->document;
        if ($document === null) {
            return [];
        }

        return $this->candidates($this->originsByKind($document), ScannedPage::from($page));
    }
```

Add `use App\Service\Reader\Media\RawPage;`; delete `use App\Service\Html\HtmlDocumentParser;`.

- [ ] **Step 7: Migrate the 2 document-only sources**

`MetaMediaSource` and `ScriptEmbedSource` need the document but not the blocks:

```php
    public function find(RawPage $page): array
    {
        $document = $page->document;
        if ($document === null) {
            return [];
        }
```

The remaining body is unchanged. Add `use App\Service\Reader\Media\RawPage;`; delete `use App\Service\Html\HtmlDocumentParser;`.

- [ ] **Step 8: Migrate the raw-string source**

`ZdfPlayerConfigSource` never parses — it reads the URL host and runs regex over the raw markup:

```php
    public function find(RawPage $page): array
    {
        $host = parse_url($page->url, \PHP_URL_HOST);
        if (!\is_string($host) || preg_match(self::ZDF_HOST, $host) !== 1) {
            return [];
        }

        preg_match_all(self::PLAYER_CONFIG, $page->html, $matches, \PREG_OFFSET_CAPTURE);
        $found = [];
        foreach ($matches[1] as [$id, $position]) {
            $candidate = $this->candidateFor($host, $id, $position, $page->html);
            if ($candidate !== null) {
                $found[$candidate->url] ??= $candidate;
            }
        }

        return array_values($found);
    }
```

Add `use App\Service\Reader\Media\RawPage;`. `candidateFor(...)` is unchanged.

- [ ] **Step 9: Migrate the scanner test's fake source**

In `PageMediaScannerTest.php`, the `source()` helper builds an inline `MediaCandidateSourceInterface`. Change its `find()` signature:

```php
            public function find(RawPage $page): array
            {
                return $this->candidates;
            }
```

- [ ] **Step 10: Migrate the 8 source-test call sites**

Each of the 8 files in `backend/tests/Service/Reader/Media/Source/` calls `$this->source->find($html, $url)` (78 sites total). In each file:

1. Add `use App\Service\Reader\Media\RawPage;`.
2. Add one private helper (place it near the other helpers):

```php
    /** @return list<\App\Service\Reader\Media\MediaCandidate> */
    private function find(string $html, string $url): array
    {
        return $this->source->find(RawPage::parse($html, $url));
    }
```

3. Replace every `$this->source->find(` with `$this->find(`.

`ScriptEmbedSourceTest` already has a `private function find(...)` wrapper with a multi-line body — update **its** body to `return $this->source->find(RawPage::parse($html, $url));` (keep its existing signature/params), and it already calls `$this->find(...)`, so no call-site rewrite is needed there beyond the helper body.

- [ ] **Step 11: Run the full media suite to verify green**

Run: `cd backend && php bin/phpunit tests/Service/Reader/Media`
Expected: PASS, including `testParsesThePageOnceAndSharesOneDocumentWithEverySource`. Confirm no deprecation notices in the output.

- [ ] **Step 12: Commit**

```bash
git add backend/src/Service/Reader/Media backend/tests/Service/Reader/Media
git commit -m "refactor(#1090): share one raw-page parse across media sources"
```

---

### Task 3: Full verification gate

**Files:** none (verification only).

- [ ] **Step 1: Static analysis and style**

Run: `cd backend && composer check && composer md`
Expected: cs, stan, tramp, and PHPMD all clean. If PHPMD flags any touched file, fix the design (do not tune thresholds).

- [ ] **Step 2: Full unit/integration suite (SQLite)**

Run: `cd backend && php bin/phpunit`
Expected: PASS.

- [ ] **Step 3: Mutation gate on the diff**

Run: `cd backend && composer infection:diff`
Expected: MSI at or above the `infection.json5` floor; no escaped mutants on the changed lines. Kill any escapee with a real assertion.

- [ ] **Step 4: PhpStorm inspections on changed PHP**

Run `mcp__phpstorm__lint_files` over the created/modified `.php` files. Block on ERROR and WARNING; weak warnings are advisory.

- [ ] **Step 5: Scan the dev log**

Run: `ls -t backend/var/log/dev-*.log | head -1 | xargs tail -n 50 | jq .`
Expected: no new deprecations or swallowed errors from the media path.

- [ ] **Step 6: Open the PR**

```bash
git push -u origin feature/1090-media-scanner-shared-parse
```
Open a PR into `develop` with body `Closes #1090`. After merge, verify the issue closed.

---

## Notes for the executor

- **Do not** switch discovery to the normalised document; the raw parse is deliberate (#748).
- **Do not** add per-source micro-optimisations beyond the shared parse/blocks — the `ScannedPage` og:image derivation stays per-`AttributeMediaSource` (it is the only user).
- The shared `HTMLDocument` is handed to every source read-only; if you add mutation to a source later, it must clone first.
- Expected runtime win (issue evidence): ~13 ms per reader load on a large page — a CPU/throughput win, not a wall-clock latency win.
