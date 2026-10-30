# Podcast Description Chapters Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** An episode whose feed declares no chapters shows the chapters its description lists, as a YouTube video already does.

**Architecture:** `DescriptionLines` walks the whole description body in reading order, so `<br>` lines that sit directly in the body (or in any block) count. `DescriptionChapters` gains `inEpisode()`, whose run may start after `0:00` (a `ChapterRunStart` enum carries the one rule that differs). `ChaptersOnOpen` serves `inEpisode()` for an entry with an enclosure and no stored chapters.

**Tech Stack:** PHP 8.4, `Dom\HTMLDocument`, PHPUnit 12.

**Spec:** GitHub issues #1924 (“Podcast chapters listed only in the episode description are not read”) and #1925 (Podigee's `href` chapter links). Their "Decided" sections are the spec.

## Global Constraints

- An entry with at least one attachment (enclosure) and no stored chapters, once the chapters file has been tried, takes its chapters from its description.
- For an episode, the first chapter may start after `0:00`. No implied chapter before it. A YouTube video still needs `0:00`.
- The stamp list leaves the shown body once it becomes chapters, as it does for YouTube.
- `<br>`-separated lines directly in the body (outside any `<p>`/`<li>`) count as description lines.
- Backend only: the frontend already treats the time before the first chapter as "no chapter" (`currentChapterIndex` returns -1).
- Description chapters are computed on open and never stored.
- CLAUDE.md Clean Code rules apply: no boolean flag parameters, no comments beyond the bar, every touched `src` file PHPMD-clean.
- Commit format `type(#1924): summary`. No attribution lines.

---

### Task 1: Description lines are read from the whole body, in reading order

Today `DescriptionLines::of()` reads only leaf `<p>`/`<li>` blocks. A Deutschlandfunk description writes its list as loose `<br>` lines in the body, so nothing is read.

New rule: walk the body. Any child that is a block element (`p`, `li`, `ul`, `div`, …) ends the current line and contributes its own lines, recursively, in document order. Blank lines are dropped. Without that, the whitespace text between `<li>`s or `<p>`s becomes a line of the `<ul>` or the body and breaks a run.

The body itself can now be a run's block. So `DescriptionChapters::dropIfEmpty()` must never remove `body`. Otherwise `$document->body->innerHTML ?? $html` would fall back to the original HTML, chapters and all.

**Files:**
- Modify: `backend/src/Service/Chapter/Support/DescriptionLines.php` (whole file)
- Modify: `backend/src/Service/Chapter/Support/DescriptionChapters.php` (`dropIfEmpty`)
- Test: `backend/tests/Service/Chapter/Support/DescriptionChaptersTest.php`

**Interfaces:**
- Consumes: `DescriptionLine(Element $block, list<Node> $nodes, ?Element $breakAfter)` (unchanged)
- Produces: `DescriptionLines::of(Document $document): list<DescriptionLine>`, same signature but it now covers the whole body; no blank lines

- [ ] **Step 1: Write the failing tests**

Add to `DescriptionChaptersTest` (after `testReadsChaptersFromANestedListWithoutTheOuterItemText`):

```php
    public function testReadsLinesThatSitDirectlyInTheBody(): void
    {
        $described = DescriptionChapters::in('Intro<br>0:00 A<br>1:00 B<br>2:00 C<br>Outro');

        self::assertCount(3, $described->chapters);
        self::assertSame('Intro<br>Outro', $described->contentHtml);
    }

    public function testKeepsTheBodyThatHeldOnlyTheBlock(): void
    {
        $described = DescriptionChapters::in('0:00 A<br>1:00 B<br>2:00 C');

        self::assertCount(3, $described->chapters);
        self::assertSame('', $described->contentHtml);
    }

    public function testReadsTheLinesAroundAListInReadingOrder(): void
    {
        $described = DescriptionChapters::in('0:00 A<ul><li>1:00 B</li></ul>2:00 C');

        self::assertEquals(
            [new EntryChapter(0, 'A'), new EntryChapter(60, 'B'), new EntryChapter(120, 'C')],
            $described->chapters,
        );
    }

    public function testReadsLinesInsideADivision(): void
    {
        $described = DescriptionChapters::in('<div>0:00 A<br>1:00 B<br>2:00 C</div><div>Outro</div>');

        self::assertCount(3, $described->chapters);
    }

    public function testWhitespaceBetweenListItemsKeepsTheRun(): void
    {
        $described = DescriptionChapters::in("<ul>\n<li>0:00 A</li>\n<li>1:00 B</li>\n<li>2:00 C</li>\n</ul>");

        self::assertCount(3, $described->chapters);
    }
```

- [ ] **Step 2: Run them and see the first four fail**

Run (from `backend/`): `php bin/phpunit --filter 'testReadsLinesThatSitDirectlyInTheBody|testKeepsTheBodyThatHeldOnlyTheBlock|testReadsTheLinesAroundAListInReadingOrder|testReadsLinesInsideADivision|testWhitespaceBetweenListItemsKeepsTheRun' tests/Service/Chapter/Support/DescriptionChaptersTest.php`

Expected: the first four FAIL (no chapters read, `0 !== 3` or an empty chapters list). `testWhitespaceBetweenListItemsKeepsTheRun` PASSES today: it guards the blank-line filter added in Step 3. Step 5 proves it does.

- [ ] **Step 3: Rewrite `DescriptionLines`**

Replace the whole file `backend/src/Service/Chapter/Support/DescriptionLines.php`:

```php
<?php

declare(strict_types=1);

namespace App\Service\Chapter\Support;

use App\Service\Chapter\Pass\DescriptionLine;
use Dom\Document;
use Dom\Element;
use Dom\Node;

/** A description's non-blank `<br>`-separated lines in reading order; a block element ends a line and holds lines of its own. */
final class DescriptionLines
{
    private const array BLOCKS = [
        'address', 'article', 'aside', 'blockquote', 'dd', 'div', 'dl', 'dt', 'figcaption', 'figure', 'footer',
        'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'header', 'hr', 'li', 'ol', 'p', 'pre', 'section', 'table', 'tbody',
        'td', 'tfoot', 'th', 'thead', 'tr', 'ul',
    ];

    /** @return list<DescriptionLine> */
    public static function of(Document $document): array
    {
        if ($document->body === null) {
            return [];
        }

        return array_values(array_filter(
            self::inBlock($document->body),
            static fn (DescriptionLine $line): bool => trim($line->text()) !== '',
        ));
    }

    /** @return list<DescriptionLine> */
    private static function inBlock(Element $block): array
    {
        $lines = [];
        $nodes = [];
        foreach ($block->childNodes as $child) {
            if (self::isBlock($child)) {
                $lines[] = new DescriptionLine($block, $nodes, null);
                array_push($lines, ...self::inBlock($child));
                $nodes = [];
                continue;
            }
            if (self::isBreak($child)) {
                $lines[] = new DescriptionLine($block, $nodes, $child);
                $nodes = [];
                continue;
            }
            $nodes[] = $child;
        }
        $lines[] = new DescriptionLine($block, $nodes, null);

        return $lines;
    }

    /** @phpstan-assert-if-true Element $node */
    private static function isBlock(Node $node): bool
    {
        return $node instanceof Element && \in_array($node->localName, self::BLOCKS, true);
    }

    /** @phpstan-assert-if-true Element $node */
    private static function isBreak(Node $node): bool
    {
        return $node instanceof Element && $node->localName === 'br';
    }

    private function __construct()
    {
    }
}
```

In `DescriptionChapters::dropIfEmpty()`, add the body guard as the first condition:

```php
    private static function dropIfEmpty(Element $block): void
    {
        if ($block->localName === 'body' || trim((string) $block->textContent) !== '' || $block->firstElementChild !== null) {
            return;
        }
```

(Keep the rest of the method unchanged. If the line passes 120 columns, phpcs wraps it: put each `||` operand on its own line.)

- [ ] **Step 4: Run the whole chapter suite**

Run: `php bin/phpunit tests/Service/Chapter`
Expected: PASS, every test, the existing nested-list, ordered-list, Veritasium and fixture tests included. A failing existing test means the reading order or the blank-line filter changed a block YouTube relies on: fix `DescriptionLines`, not the test.

- [ ] **Step 5: Prove the two guards**

These are temporary edits. Restore them by re-applying the code above, never with `git checkout --`.
1. In `of()`, return `self::inBlock($document->body)` unfiltered, then run `php bin/phpunit --filter testWhitespaceBetweenListItemsKeepsTheRun tests/Service/Chapter/Support/DescriptionChaptersTest.php`. Expected: FAIL. Restore the filter.
2. Remove `$block->localName === 'body' ||` from `dropIfEmpty`, then run `php bin/phpunit --filter testKeepsTheBodyThatHeldOnlyTheBlock tests/Service/Chapter/Support/DescriptionChaptersTest.php`. Expected: FAIL (the contentHtml is the original HTML). Restore the guard.

Quote the FAIL line of each run in the task report.

- [ ] **Step 6: Commit**

```bash
git add backend/src/Service/Chapter/Support/DescriptionLines.php backend/src/Service/Chapter/Support/DescriptionChapters.php backend/tests/Service/Chapter/Support/DescriptionChaptersTest.php
git commit -m "fix(#1924): description lines are read from the whole body in reading order"
```

---

### Task 2: An episode's chapter run may start after 0:00

Most of Deutschlandfunk's lists start at `(00:00)`, but 44 of 99 start later (`(02:23)` in the reported episode). YouTube's rule stays as it is for videos and embeds.

**Files:**
- Create: `backend/src/Service/Chapter/Model/ChapterRunStart.php`
- Create: `backend/tests/Fixtures/Chapter/deutschlandfunk-description-ki-verstehen.html`
- Modify: `backend/src/Service/Chapter/Support/DescriptionChapters.php`
- Test: `backend/tests/Service/Chapter/Support/DescriptionChaptersTest.php`

**Interfaces:**
- Consumes: `DescriptionLines::of()` from Task 1
- Produces:
  - `DescriptionChapters::inEpisode(string $html): ServedChaptersModel`
  - `DescriptionChapters::in(string $html): ServedChaptersModel` (unchanged signature and rule)

- [ ] **Step 1: Save the fixture**

Write the reported entry's `content_html` (dev DB, entry 679418, read with `--default-character-set=utf8mb4`) verbatim to `backend/tests/Fixtures/Chapter/deutschlandfunk-description-ki-verstehen.html`. A UTF-8 copy is at `/private/tmp/claude-501/-Users-lars-Documents-work-eigenes-simple-feed-reader/d95e9b44-8ce6-44b2-aaff-3b6e86a76bcf/scratchpad/dlf.html`: copy it and strip the trailing newline. Check it holds `Gesprächspartner` as UTF-8, not mojibake.

- [ ] **Step 2: Write the failing tests**

Add to `DescriptionChaptersTest`:

```php
    public function testAnEpisodeRunMayStartAfterZero(): void
    {
        $described = DescriptionChapters::inEpisode('<p>0:10 A<br>1:00 B<br>2:00 C</p>');

        self::assertEquals(
            [new EntryChapter(10, 'A'), new EntryChapter(60, 'B'), new EntryChapter(120, 'C')],
            $described->chapters,
        );
        self::assertSame('', $described->contentHtml);
    }

    public function testAnEpisodeRunStillNeedsThreeLines(): void
    {
        self::assertSame([], DescriptionChapters::inEpisode('<p>0:10 A<br>1:00 B</p>')->chapters);
    }

    public function testAVideoSkipsARunThatStartsAfterZeroAndAnEpisodeTakesIt(): void
    {
        $html = '<p>0:10 A<br>1:00 B<br>2:00 C</p><p>0:00 X<br>1:00 Y<br>2:00 Z</p>';

        self::assertSame('X', DescriptionChapters::in($html)->chapters[0]->title);
        self::assertSame('A', DescriptionChapters::inEpisode($html)->chapters[0]->title);
    }

    public function testReadsADeutschlandfunkEpisodeDescription(): void
    {
        $html = (string) file_get_contents(self::FIXTURES . 'deutschlandfunk-description-ki-verstehen.html');

        $described = DescriptionChapters::inEpisode($html);

        self::assertEquals(
            [
                new EntryChapter(143, 'Wie weit deutsche Unternehmen beim KI-Einsatz sind'),
                new EntryChapter(398, 'Erst in der Integrationsphase steigt die Produktivität'),
                new EntryChapter(677, 'KI ist viel mehr als Chatbots und kann sogar Stahlrohre prüfen'),
                new EntryChapter(912, 'Die Kehrseite des KI-Booms und die Angst vor dem Jobverlust'),
                new EntryChapter(1140, 'Nvidia-Boss Jensen Huang sagt: KI schafft massenhaft neue Arbeitsplätze'),
                new EntryChapter(1520, 'Das Produktivitätsparadoxon und die J-Kurve'),
            ],
            $described->chapters,
        );
        self::assertNotNull($described->contentHtml);
        self::assertStringNotContainsString('(02:23)', $described->contentHtml);
        self::assertStringNotContainsString('(25:20)', $described->contentHtml);
        self::assertStringContainsString('<strong>Das erwartet Euch in dieser Folge:</strong>', $described->contentHtml);
        self::assertStringContainsString('Barbara Engels', $described->contentHtml);
        self::assertStringContainsString('<strong>Mehr zum Thema in der Deutschlandfunk-App:</strong>', $described->contentHtml);
    }
```

- [ ] **Step 3: Run them and see them fail**

Run: `php bin/phpunit --filter 'testAnEpisode|testAVideoSkips|testReadsADeutschlandfunk' tests/Service/Chapter/Support/DescriptionChaptersTest.php`
Expected: FAIL / error "Call to undefined method …DescriptionChapters::inEpisode()".

- [ ] **Step 4: Add the enum**

Create `backend/src/Service/Chapter/Model/ChapterRunStart.php`:

```php
<?php

declare(strict_types=1);

namespace App\Service\Chapter\Model;

enum ChapterRunStart
{
    case AtZero;
    case Anywhere;

    public function admits(int $startSeconds): bool
    {
        return $this === self::Anywhere || $startSeconds === 0;
    }
}
```

- [ ] **Step 5: Thread the rule through `DescriptionChapters`**

In `backend/src/Service/Chapter/Support/DescriptionChapters.php`:

1. Class docblock becomes:
   `/** A description's first run of three or more ascending chapter lines, stamped alike, taken out of its body. */`
2. Replace the public `in()` with the two entry points and a private `read()`. The early skip becomes "no stamp at all", shared by both rules. `ChapterLine::holdsNoStamp()` already exists.

```php
    /** YouTube's chapter rule: the run starts at `0:00`. */
    public static function in(string $html): ServedChaptersModel
    {
        return self::read($html, ChapterRunStart::AtZero);
    }

    /** A show often opens on an intro its notes leave out, so an episode's run may start later. */
    public static function inEpisode(string $html): ServedChaptersModel
    {
        return self::read($html, ChapterRunStart::Anywhere);
    }

    private static function read(string $html, ChapterRunStart $start): ServedChaptersModel
    {
        if (ChapterLine::holdsNoStamp($html)) {
            return new ServedChaptersModel([], $html);
        }

        $document = HtmlDocumentParser::parseFragment($html);
        $run = self::firstRun(DescriptionLines::of($document), $start);
        if ($run === []) {
            return new ServedChaptersModel([], $html);
        }

        $chapters = array_map(static fn (array $pair): EntryChapter => $pair[1], $run);
        foreach ($run as [$line]) {
            $line->remove();
        }
        foreach (self::blocksOf($run) as $block) {
            self::tidy($block);
        }

        return new ServedChaptersModel($chapters, $document->body->innerHTML ?? $html);
    }
```

3. `firstRun(array $lines, ChapterRunStart $start)`: pass `$start` to both `self::isChapterBlock($run, $start)` calls. Update its `@param` block to the new signature (keep the `list<DescriptionLine>` and return shapes).
4. `isChapterBlock`:

```php
    /** @param list<array{DescriptionLine, EntryChapter}> $run */
    private static function isChapterBlock(array $run, ChapterRunStart $start): bool
    {
        return \count($run) >= self::MINIMUM && $start->admits($run[0][1]->startSeconds);
    }
```

5. Add `use App\Service\Chapter\Model\ChapterRunStart;`.

- [ ] **Step 6: Run the chapter suite**

Run: `php bin/phpunit tests/Service/Chapter`
Expected: PASS, including the `no 0:00` case of `testLeavesABodyWithoutTheBlockAlone` (YouTube unchanged) and `EmbedDetailsTest`.

- [ ] **Step 7: Commit**

```bash
git add backend/src/Service/Chapter/Model/ChapterRunStart.php backend/src/Service/Chapter/Support/DescriptionChapters.php backend/tests/Service/Chapter/Support/DescriptionChaptersTest.php backend/tests/Fixtures/Chapter/deutschlandfunk-description-ki-verstehen.html
git commit -m "fix(#1924): an episode's description chapters may start after 0:00"
```

---

### Task 3: An opened episode without declared chapters serves its description's

**Files:**
- Modify: `backend/src/Service/Chapter/ChaptersOnOpen.php`
- Test: `backend/tests/Service/Chapter/ChaptersOnOpenTest.php`

**Interfaces:**
- Consumes: `DescriptionChapters::inEpisode(string): ServedChaptersModel` from Task 2; `Entry::getAttachments(): list<EntryAttachment>`, `Entry::getChapters(): list<EntryChapter>`, `Entry::setMedia(list<EntryMedium>, list<EntryAttachment>)`
- Produces: `ChaptersOnOpen::serve(Entry): ServedChaptersModel` (same signature)

- [ ] **Step 1: Write the failing tests**

In `ChaptersOnOpenTest`:

1. Add `use App\Entity\EntryAttachment;` and a second constant:

```php
    private const string SHOW_NOTES = '<p>Intro</p><p>(02:00) A<br>(05:00) B<br>(09:00) C</p>';
```

2. Add tests:

```php
    public function testAnEpisodeWithoutDeclaredChaptersServesItsDescriptionChaptersAndTheTrimmedBody(): void
    {
        $served = $this->serving()->serve(self::episode(self::SHOW_NOTES));

        self::assertEquals(
            [new EntryChapter(120, 'A'), new EntryChapter(300, 'B'), new EntryChapter(540, 'C')],
            $served->chapters,
        );
        self::assertSame('<p>Intro</p>', $served->contentHtml);
    }

    public function testAnEntryWithoutAnEnclosureKeepsItsStampsInTheBody(): void
    {
        $served = $this->serving()->serve(self::entry('https://blog.test/post', self::SHOW_NOTES));

        self::assertSame([], $served->chapters);
        self::assertSame(self::SHOW_NOTES, $served->contentHtml);
    }
```

3. Rename `testAnyOtherEntryServesItsStoredChaptersAndItsBodyUnchanged` to `testAnEpisodeServesItsDeclaredChaptersOverItsDescription`. Build its entry with `self::episode(self::DESCRIPTION)` instead of `self::entry('https://pod.test/ep1', self::DESCRIPTION)`, so it pins that declared chapters win over the description.

4. Add the helper next to `entry()`:

```php
    private static function episode(string $body): Entry
    {
        $entry = self::entry('https://pod.test/ep1', $body);
        $entry->setMedia([], [new EntryAttachment('https://pod.test/ep1.mp3', 'audio/mpeg')]);

        return $entry;
    }
```

- [ ] **Step 2: Run them and see the new episode test fail**

Run: `php bin/phpunit tests/Service/Chapter/ChaptersOnOpenTest.php`
Expected: `testAnEpisodeWithoutDeclaredChaptersServesItsDescriptionChaptersAndTheTrimmedBody` FAILS (no chapters served). The others pass.

- [ ] **Step 3: Implement**

`backend/src/Service/Chapter/ChaptersOnOpen.php`, `serve()` and a new helper:

```php
    public function serve(Entry $entry): ServedChaptersModel
    {
        $body = $entry->getContentHtml();
        if ($body !== null && self::isYouTubeVideo($entry->getUrl())) {
            return DescriptionChapters::in($body);
        }

        $this->filler->fillOnOpen($entry);
        if ($body !== null && self::isEpisodeWithoutChapters($entry)) {
            return DescriptionChapters::inEpisode($body);
        }

        return new ServedChaptersModel($entry->getChapters(), $body);
    }

    private static function isEpisodeWithoutChapters(Entry $entry): bool
    {
        return $entry->getAttachments() !== [] && $entry->getChapters() === [];
    }
```

Class docblock becomes:
`/** The chapters an opened entry shows, and the body to show beside them; a video's or an undeclared episode's come from its description. Opening may store a fetched chapters file. */`

- [ ] **Step 4: Run the test file and the controller's functional tests**

Run: `php bin/phpunit tests/Service/Chapter/ChaptersOnOpenTest.php` then `php bin/phpunit --filter EntryDetail`
Expected: PASS.

- [ ] **Step 5: Prove the order guard**

Temporary edit: drop `&& $entry->getChapters() === []`. Run `php bin/phpunit --filter testAnEpisodeServesItsDeclaredChaptersOverItsDescription tests/Service/Chapter/ChaptersOnOpenTest.php`. Expected: FAIL. Restore the condition by re-typing it, not with `git checkout --`. Quote the FAIL line in the report.

- [ ] **Step 6: Commit**

```bash
git add backend/src/Service/Chapter/ChaptersOnOpen.php backend/tests/Service/Chapter/ChaptersOnOpenTest.php
git commit -m "fix(#1924): an opened episode without declared chapters serves its description's"
```

---

### Task 4: A Podigee `podcast:chapters` link written as `href` is read (#1925)

The Podcasting 2.0 spec puts the chapters-file link in `url`. Podigee writes `href` (`<podcast:chapters href="https://geschichten-aus-der-geschichte.podigee.io/b33-fgag20/chapters.json" type="application/json+chapters"/>`), so `DeclaredChapters` drops it. Read `url`, fall back to `href`; `url` wins when both are set.

**Files:**
- Modify: `backend/src/Service/Parser/Support/DeclaredChapters.php` (`of()`)
- Test: `backend/tests/Service/Parser/Support/DeclaredChaptersTest.php`

**Interfaces:**
- Produces: `DeclaredChapters::of(CoreElement $item): ParsedChaptersModel` (unchanged signature)

- [ ] **Step 1: Write the failing tests**

Add after `testKeepsTheLinkedFileWhenNothingIsInline`, reusing the file's existing `$this->rssItem(...)` helper:

```php
    public function testReadsALinkWrittenAsHref(): void
    {
        $chapters = DeclaredChapters::of($this->rssItem(
            '<podcast:chapters href="https://cdn.test/ch.json" type="application/json+chapters"/>',
        ));

        self::assertSame('https://cdn.test/ch.json', $chapters->url);
    }

    public function testTheSpecUrlWinsOverHref(): void
    {
        $chapters = DeclaredChapters::of($this->rssItem(
            '<podcast:chapters url="https://cdn.test/url.json" href="https://cdn.test/href.json"'
            . ' type="application/json+chapters"/>',
        ));

        self::assertSame('https://cdn.test/url.json', $chapters->url);
    }
```

- [ ] **Step 2: Run them and see the first fail**

Run: `php bin/phpunit tests/Service/Parser/Support/DeclaredChaptersTest.php`
Expected: `testReadsALinkWrittenAsHref` FAILS (`null` is not the URL). `testTheSpecUrlWinsOverHref` passes today and guards the order.

- [ ] **Step 3: Implement**

In `DeclaredChapters::of()`, replace the two link lines and the return with:

```php
        return new ParsedChaptersModel([], self::linkedFile($item->in(FeedNamespace::Podcast)->child('chapters')));
```

and add:

```php
    /** The spec names the attribute `url`; Podigee writes `href`. */
    private static function linkedFile(?\DOMElement $link): ?string
    {
        foreach (['url', 'href'] as $attribute) {
            $url = trim($link?->getAttribute($attribute) ?? '');
            if ($url !== '') {
                return $url;
            }
        }

        return null;
    }
```

- [ ] **Step 4: Run the parser suite**

Run: `php bin/phpunit tests/Service/Parser`
Expected: PASS.

- [ ] **Step 5: Prove the order guard**

Temporary edit: swap the array to `['href', 'url']`. Run `php bin/phpunit --filter testTheSpecUrlWinsOverHref tests/Service/Parser/Support/DeclaredChaptersTest.php`. Expected: FAIL. Restore by re-typing, not with `git checkout --`. Quote the FAIL line.

- [ ] **Step 6: Commit**

```bash
git add backend/src/Service/Parser/Support/DeclaredChapters.php backend/tests/Service/Parser/Support/DeclaredChaptersTest.php
git commit -m "fix(#1925): read a podcast:chapters link written as href"
```

---

### Task 5: Gates, live check, simplify, PR, merge, deploy, re-ingest

- [ ] **Step 1: Backend gates** (from `backend/`, in parallel where possible)
  - `composer check` (cs + stan + tramp; warm the cache first with `bin/console cache:warmup` if stan complains)
  - `composer md`: every touched `src` file clean
  - `php bin/phpunit` (SQLite) and `docker compose exec php composer test:parallel` (MySQL)
  - `composer infection:diff`: at or above `minMsi`. A surviving mutant on the `holdsNoStamp` early skip is equivalent: without the skip, the parse finds no run and returns the same model. Report it; don't contort a test for it.
  - PhpStorm inspections (`mcp__phpstorm__lint_files`) on the changed PHP files: no ERROR/WARNING.
- [ ] **Step 2: Live check**
  - Check the Docker php container serves this branch, per the memory on stale containers (restart php-fpm / clear opcache if needed).
  - Open `http://localhost:4200/?subscription=2009&entry=679418-zukunft-der-arbeit-1-3-was-bringt-ki-unternehmen-konkret`.
  - Verify: six chapters appear in the episode panel; the stamp lines are gone from the body; the rest of the show notes remain.
  - Spot-check one episode of the feed whose list starts at `(00:00)`.
  - Scan today's dev log (`ls -t backend/var/log/dev-*.log | head -1`) for new errors.
- [ ] **Step 3:** Whole-branch review (requesting-code-review), fix wave, then `/simplify`, re-run the gates.
- [ ] **Step 4:** Push `fix/1924-podcast-description-chapters`. Open a PR into `develop` whose body says `Closes #1924` and `Closes #1925`; no attribution.
- [ ] **Step 5:** Merge when every check is green. Watch with one `gh pr checks <n> --watch --fail-fast --interval 30`; `--auto` merges at once on this repo.
- [ ] **Step 6:** Deploy to the Strato test server: tag the merge commit on `develop` with the next dev tag. Release tags decide the series (`git tag --sort=-v:refname | grep -v -- -dev`), and CI must be green on that SHA.
- [ ] **Step 7:** Re-ingest the podcast feeds on Strato (Lars's go-ahead, 2026-10-30), so stored episodes pick up the `href` chapter links. Description chapters (#1924) are computed on open and need no re-ingest.
