# Leading Article-Head Furniture Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Strip the article masthead (kickers, breadcrumbs, separators, duplicate title, date/reading-time, action toolbars, zero counters, promo badges) that readability keeps at the head of extracted articles, using general shape-based rules so the reader view starts at the real headline or first paragraph.

**Architecture:** Extend the existing leading-cleaner trio only — no new services or interfaces. Removal stays shape-gated: a block is deleted only when it matches a furniture shape, so widening the scanned region and the collected tags can never take real prose, captions or media. Emptied wrappers are cleared by the existing `removeRemaindersBefore` sweep. `LeadingEngagementRules` holds pure text predicates; `LeadingEngagementBlocks` holds block collection and element predicates; `LeadingEngagementCleaner` composes the shapes and owns the head region and the sweep; `ReaderBodyCleaner` owns step ordering; `LeadingTitleRemover` gets the duplicate-title fix.

**Tech Stack:** PHP 8.4, Symfony 7.4, `\Dom\HTMLDocument`, PHPUnit 12.

**Spec:** GitHub issue #901 (https://github.com/larspohlmann/simple-feed-reader/issues/901) — the shape catalogue, the seven observed articles and the two-phase reasoning live there.

## Global Constraints

- `declare(strict_types=1)` in every PHP file; PSR-12; PHPStan level max over `src` and `tests`.
- Clean Code house rules: names reveal intent, functions do one thing, no boolean flag parameters, guard clauses over nesting, `final readonly` where the class already is, default to no comment.
- PHPMD codesize clean on every touched `src` file; phptramp: no forwarded-parameter chain of 4+ across 2+ classes.
- The class stays named `LeadingEngagementCleaner` (rename would touch DI and tests for no functional gain); its docblock notes the broadened scope.
- Datetimes irrelevant here; no persistence touched.
- Keep `testKeepsALeadingPosterLinkWhenTheSweepRuns` green — the Substack poster (`<a><img></a>`, #627) must survive; badge removal is scoped to a leading `<header>`, where a poster never sits.

---

### Task 1: Separator, navigation-label and kicker predicates  — DONE

**Files:**
- Modify: `backend/src/Service/Reader/LeadingEngagementRules.php`
- Test: `backend/tests/Service/Reader/LeadingEngagementRulesTest.php`

**Interfaces:**
- Produces: `LeadingEngagementRules::isSeparatorOnly(string $text): bool`, `isNavigationLabel(string $text, int $linkTextLength): bool`, `isKicker(string $text, int $linkTextLength): bool`. `PROSE_CHARS = 120` and `LINK_DOMINATED = 0.8` already exist and are reused.

- [x] **Step 1: Write failing tests** for the three predicates (separator glyph runs; short link-dominated label; ≤3 link-less label words that is not a byline or a sentence).
- [x] **Step 2: Run — expected FAIL** (undefined methods).
- [x] **Step 3: Implement** in `LeadingEngagementRules`:

```php
/** A kicker is a label, not a sentence: at most this many words, this short. */
private const int KICKER_MAX_WORDS = 3;
private const int KICKER_MAX_CHARS = 30;

/** A masthead separator with no words of its own: "|", "›", "•". */
public static function isSeparatorOnly(string $text): bool
{
    return $text !== '' && preg_match('/^[\s|\/~•·‣›‹»«—–\-…]+$/u', $text) === 1;
}

/**
 * A breadcrumb or section label: short enough to be no article prose, and
 * carried almost entirely by outbound links.
 */
public static function isNavigationLabel(string $text, int $linkTextLength): bool
{
    $length = mb_strlen($text);

    return $length > 0 && $length < self::PROSE_CHARS && $linkTextLength / $length >= self::LINK_DOMINATED;
}

/** A kicker or category eyebrow above the title: a few link-less label words. */
public static function isKicker(string $text, int $linkTextLength): bool
{
    if ($linkTextLength > 0 || mb_strlen($text) > self::KICKER_MAX_CHARS || self::isByline($text)) {
        return false;
    }
    if (preg_match('/[.!?:]/u', $text) === 1 || preg_match('/\pL/u', $text) !== 1) {
        return false;
    }

    return count(preg_split('/\s+/u', $text) ?: []) <= self::KICKER_MAX_WORDS;
}
```

- [x] **Step 4: Run — expected PASS.**
- [x] **Step 5: Commit.**

---

### Task 2: Compose the navigational shapes into the cleaner  — DONE

**Files:**
- Modify: `backend/src/Service/Reader/LeadingEngagementCleaner.php`
- Test: `backend/tests/Service/Reader/LeadingEngagementCleanerTest.php`

**Interfaces:**
- Consumes: Task 1 predicates and the existing private `linkTextLength(Element): int`.
- Produces: private `isLeadingFurniture(LeadingBlock, ?string): bool` replacing the old `isEngagement`; the leading loop flag is renamed `$removedFurniture`.

- [x] **Step 1: Write failing cleaner tests** — leading section link + bare pipe removed; `<p>` breadcrumb trail removed; breadcrumb list nested inside `<article>` removed; kicker labels removed while title and dek stay; a leading paragraph that merely links one word is kept.
- [x] **Step 2: Run — expected FAIL.**
- [x] **Step 3: Rename `isEngagement` → `isLeadingFurniture`, split into two grouped predicates, and rename the loop flag** in `LeadingEngagementCleaner`:

```php
private function isLeadingFurniture(LeadingBlock $block, ?string $entryAuthor): bool
{
    return $this->isNavigationalChrome($block)
        || $this->isEngagementMeta($block, $entryAuthor);
}

/** Breadcrumbs, section labels, kickers and bare separators. */
private function isNavigationalChrome(LeadingBlock $block): bool
{
    $linkTextLength = $this->linkTextLength($block->element);

    return LeadingEngagementRules::isSeparatorOnly($block->text)
        || LeadingEngagementRules::isNavigationLabel($block->text, $linkTextLength)
        || LeadingEngagementRules::isKicker($block->text, $linkTextLength);
}
```

Rename the three `$removedEngagement` references in `removeFrom` to `$removedFurniture`, and call `$this->isLeadingFurniture(...)`. `isLeadingFurniture` guards captions first — a `<figcaption>` is content and is never furniture (a short caption like "A caption line" otherwise looks like a kicker):

```php
private function isLeadingFurniture(LeadingBlock $block, ?string $entryAuthor): bool
{
    if ($block->element->localName === 'figcaption') {
        return false;
    }

    return $this->isNavigationalChrome($block)
        || $this->isEngagementMeta($block, $entryAuthor);
}
```

- [x] **Step 4: Run — expected PASS** (existing 17 + new 5).
- [x] **Step 5: Commit.**

---

### Task 3: Close the collection gap (`<address>`, `<time>`)  — DONE

**Files:**
- Modify: `backend/src/Service/Reader/LeadingEngagementBlocks.php`
- Test: `backend/tests/Service/Reader/LeadingEngagementCleanerTest.php`

**Interfaces:**
- Produces: `LeadingEngagementBlocks::isTimeOnly` now also returns true when the block element *is* a `<time>`; `BLOCK_TAGS` now includes `address` and `time`.

- [x] **Step 1: Write failing test** `testRemovesCategoryBylineAndTimeFromASemanticHeaderMasthead` — a `<header>` with a category link, a dek, an `<address>` byline and a `<time>` date; assert category, byline and date gone, dek and caption and body kept (author "Clark Strand").
- [x] **Step 2: Run — expected FAIL** (`<address>`/`<time>` not collected).
- [x] **Step 3: Implement** in `LeadingEngagementBlocks`:

```php
private const array BLOCK_TAGS = [
    'p', 'li', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'blockquote', 'figcaption', 'div', 'address', 'time',
];
```

```php
public static function isTimeOnly(Element $element): bool
{
    if ($element->localName === 'time') {
        return true;
    }

    $times = $element->getElementsByTagName('time');

    return $times->length === 1
        && LeadingEngagementRules::collapse($element->textContent)
            === LeadingEngagementRules::collapse($times->item(0)?->textContent);
}
```

- [x] **Step 4: Run — expected PASS.**
- [x] **Step 5: Commit.**

---

### Task 4: Date, reading-time and bare-number meta predicates  — DONE

**Files:**
- Modify: `backend/src/Service/Reader/LeadingEngagementRules.php`, `backend/src/Service/Reader/LeadingEngagementCleaner.php`
- Test: `backend/tests/Service/Reader/LeadingEngagementRulesTest.php`, `backend/tests/Service/Reader/LeadingEngagementCleanerTest.php`

**Interfaces:**
- Produces: `LeadingEngagementRules::isReadingTime(string): bool`, `isDateLine(string): bool`, `isBareNumber(string): bool`, all wired into the cleaner's `isEngagementMeta`.

- [x] **Step 1: Write failing rule tests** — reading-time forms (`9 min.`, `11 min read`, `9 minutes`) and prose rejection; German/English/numeric dates and sentence rejection; digits-only vs `0 reactions`.
- [x] **Step 2: Run — expected FAIL.**
- [x] **Step 3: Implement** the three predicates in `LeadingEngagementRules`:

```php
/** A reading-time stamp: "9 min.", "11 min read", "9 minutes". */
public static function isReadingTime(string $text): bool
{
    return preg_match('/^\d+\s*min(?:\.|ute[ns]?|\s+read)?$/iu', $text) === 1;
}

// Constants near the top of the class:
private const int DATE_LINE_MAX_CHARS = 48;
private const array DATE_LINE_LOCALES = ['de', 'en_US', 'en_GB'];
private const array DATE_LINE_STYLES = [
    \IntlDateFormatter::FULL,
    \IntlDateFormatter::LONG,
    \IntlDateFormatter::MEDIUM,
];

/**
 * A stand-alone publication date. ICU parses the common German and English
 * forms from its own locale data — day, month and year in any order, spelled
 * or numeric, with an optional weekday — so no format is hand-listed. Strict
 * parsing that must consume the whole string, a length cap and a required
 * digit keep a bare month, a lone year or a sentence from matching.
 */
public static function isDateLine(string $text): bool
{
    if (mb_strlen($text) > self::DATE_LINE_MAX_CHARS || preg_match('/\d/', $text) !== 1) {
        return false;
    }
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $text) === 1) {
        return true;
    }

    return array_any(
        self::DATE_LINE_LOCALES,
        static fn (string $locale): bool => array_any(
            self::DATE_LINE_STYLES,
            static fn (int $style): bool => self::consumesWholeStringAsDate($text, $locale, $style),
        ),
    );
}

private static function consumesWholeStringAsDate(string $text, string $locale, int $style): bool
{
    $formatter = new \IntlDateFormatter($locale, $style, \IntlDateFormatter::NONE, 'UTC', \IntlDateFormatter::GREGORIAN);
    $formatter->setLenient(false);

    $position = 0;
    $timestamp = $formatter->parse($text, $position);

    return $timestamp !== false && $position === strlen($text);
}

/** A stray engagement count rendered as a bare number, e.g. "0". */
public static function isBareNumber(string $text): bool
{
    return preg_match('/^\d+$/', $text) === 1;
}
```

- [x] **Step 4: Wire into the cleaner's `isEngagementMeta`:**

```php
/** Emoji rows, engagement counters, date and reading-time stamps and a duplicate byline. */
private function isEngagementMeta(LeadingBlock $block, ?string $entryAuthor): bool
{
    return LeadingEngagementRules::isEmojiOnly($block->text)
        || LeadingEngagementRules::isCounter($block->text)
        || LeadingEngagementRules::isBareNumber($block->text)
        || LeadingEngagementRules::isReadingTime($block->text)
        || LeadingEngagementRules::isDateLine($block->text)
        || LeadingEngagementBlocks::isTimeOnly($block->element)
        || (LeadingEngagementRules::hasAuthor($entryAuthor) && LeadingEngagementRules::isByline($block->text));
}
```

- [x] **Step 5: Add cleaner test** `testRemovesLeadingDateReadingTimeAndBareNumberRows`; run — expected PASS; commit.

---

### Task 5: Icon-label action buttons (print, correction, more-articles) — RESOLVED WITHOUT A NEW RULE

**Ruling:** an icon+label action row (`<p><img>Drucken</p>`) is already removed by the existing shapes — the label text ("Drucken", "Korrektur", "Mehr Artikel") is a kicker, and the icon rides inside the same `<p>`, so it goes with it. A dedicated `isIconLabel` rule would be redundant. The only residual is a *bare* decorative icon (`<p><img></p>`, no text), which is indistinguishable from a hero without a fragile size/path heuristic (untergrund's icons even lack dimensions) — deferred to Task 9's real fixture, where the downstream hero/edge/media steps may already drop it. A guard test (`testRemovesLeadingActionButtonRowsThatPairAnIconWithALabel`) locks in the icon+label behaviour. Original (superseded) design below.



**Files:**
- Modify: `backend/src/Service/Reader/LeadingEngagementBlocks.php`, `backend/src/Service/Reader/LeadingEngagementCleaner.php`
- Test: `backend/tests/Service/Reader/LeadingEngagementCleanerTest.php`

**Interfaces:**
- Produces: `LeadingEngagementBlocks::isIconLabel(Element): bool` — exactly one `<img>` and a short label. Wired into `isNavigationalChrome`.

- [ ] **Step 1: Write the failing test** in `LeadingEngagementCleanerTest`:

```php
public function testRemovesLeadingIconLabelActionButtons(): void
{
    $html = '<div><p><img src="https://x.test/icons/print.png" alt="Print">Drucken</p>'
        . '<p><img src="https://x.test/icons/edit.png" alt="Korrektur">Korrektur</p>'
        . '<div><p><img src="https://x.test/icons/more.png" alt="Mehr Artikel"></p><p>Mehr Artikel</p></div>'
        . '<p>' . self::PROSE . '</p></div>';

    $clean = $this->clean($html, null);

    self::assertStringNotContainsString('Drucken', $clean);
    self::assertStringNotContainsString('Korrektur', $clean);
    self::assertStringContainsString('Hamburg/Norderstedt', $clean);
}
```

- [ ] **Step 2: Run — expected FAIL.**

Run: `php bin/phpunit --filter testRemovesLeadingIconLabelActionButtons`

- [ ] **Step 3: Implement `isIconLabel`** in `LeadingEngagementBlocks` (add the two caps as constants):

```php
/** An icon-label button carries one small image and a label of a few words. */
private const int LABEL_MAX_WORDS = 3;
private const int LABEL_MAX_CHARS = 30;

public static function isIconLabel(Element $element): bool
{
    if ($element->getElementsByTagName('img')->length !== 1) {
        return false;
    }

    $text = LeadingEngagementRules::collapse($element->textContent);

    return $text !== ''
        && mb_strlen($text) <= self::LABEL_MAX_CHARS
        && count(preg_split('/\s+/u', $text) ?: []) <= self::LABEL_MAX_WORDS;
}
```

- [ ] **Step 4: Wire into `isNavigationalChrome`** — note: after the Task 1-4 refactor, furniture classification lives in `LeadingEngagementBlocks` (`isFurniture`/`isNavigationalChrome`/`isEngagementMeta` are static there; the cleaner does DOM work only). Add the icon-label to `LeadingEngagementBlocks::isNavigationalChrome`:

```php
return LeadingEngagementRules::isSeparatorOnly($block->text)
    || LeadingEngagementRules::isNavigationLabel($block->text, $linkTextLength)
    || LeadingEngagementRules::isKicker($block->text, $linkTextLength)
    || self::isIconLabel($block->element);
```

- [ ] **Step 5: Run — expected PASS; run the whole `LeadingEngagementCleanerTest` to confirm no regression; commit.**

---

### Task 6: Extend the head region past one leading standfirst

**Files:**
- Modify: `backend/src/Service/Reader/LeadingEngagementCleaner.php`
- Test: `backend/tests/Service/Reader/LeadingEngagementCleanerTest.php`

**Interfaces:**
- Consumes: existing `firstProseAnchor`, `isProse`, `isLeadingFurniture`.
- Produces: private `bodyStart(list<LeadingBlock>, ?string): ?int` used in place of `firstProseAnchor` inside `removeFrom`; helpers `nextProseAfter(list<LeadingBlock>, int): ?int` and `furnitureBetween(list<LeadingBlock>, int, int, ?string): bool`.

- [ ] **Step 1: Write the failing test** — a standfirst paragraph FIRST, then a meta/action toolbar, then the body; the toolbar must go and both the standfirst and the body stay:

```php
public function testExtendsPastOneStandfirstToReachAToolbar(): void
{
    $standfirst = 'Während der Streit eskalierte, bekräftigte die Parteiführung ihren Kurs und lud '
        . 'die gesamte Szene in die Festung Mark, wo sich der Abend dann endgültig entlud.';
    $html = '<div><p>' . $standfirst . '</p>'
        . '<p><img src="https://x.test/icons/cal.png" alt="Datum"> 7. September 2026</p>'
        . '<p>0</p><p><img src="https://x.test/icons/clock.png" alt="Lesezeit">9 min.</p>'
        . '<p><img src="https://x.test/icons/print.png" alt="Drucken">Drucken</p>'
        . '<p>' . self::PROSE . '</p></div>';

    $clean = $this->clean($html, null);

    self::assertStringContainsString('Festung Mark', $clean);
    self::assertStringNotContainsString('7. September 2026', $clean);
    self::assertStringNotContainsString('9 min.', $clean);
    self::assertStringNotContainsString('Drucken', $clean);
    self::assertStringContainsString('Hamburg/Norderstedt', $clean);
}
```

- [ ] **Step 2: Run — expected FAIL** (anchor stops at the standfirst, toolbar survives).

- [ ] **Step 3: Implement the region extension.** In `removeFrom`, replace the anchor lookup:

```php
$anchor = $this->bodyStart($blocks, $entryAuthor);
if ($anchor === null) {
    return;
}
```

Add the helpers (the one-skip cap is the safeguard against reaching into the body):

```php
/**
 * The block the article body starts at. A single leading standfirst is
 * allowed to sit above masthead furniture, so when furniture still appears
 * between the first prose block and the next one, the first is treated as a
 * standfirst and the body starts at the next. At most one such skip.
 */
private function bodyStart(array $blocks, ?string $entryAuthor): ?int
{
    $firstProse = $this->firstProseAnchor($blocks);
    if ($firstProse === null) {
        return null;
    }

    $nextProse = $this->nextProseAfter($blocks, $firstProse);
    if ($nextProse !== null && $this->furnitureBetween($blocks, $firstProse, $nextProse, $entryAuthor)) {
        return $nextProse;
    }

    return $firstProse;
}

/** @param list<LeadingBlock> $blocks */
private function nextProseAfter(array $blocks, int $from): ?int
{
    foreach ($blocks as $index => $block) {
        if ($index > $from && $this->isProse($block)) {
            return $index;
        }
    }

    return null;
}

/** @param list<LeadingBlock> $blocks */
private function furnitureBetween(array $blocks, int $from, int $to, ?string $entryAuthor): bool
{
    for ($index = $from + 1; $index < $to; $index++) {
        if ($this->isLeadingFurniture($blocks[$index], $entryAuthor)) {
            return true;
        }
    }

    return false;
}
```

- [ ] **Step 4: Run the new test and the whole `LeadingEngagementCleanerTest` — expected PASS** (the standfirst is prose so it is never furniture; a caption between is neither prose nor furniture so it is kept).
- [ ] **Step 5: Commit.**

---

### Task 7: Remove image-only promo badges inside a leading `<header>`

**Files:**
- Modify: `backend/src/Service/Reader/LeadingEngagementCleaner.php`
- Test: `backend/tests/Service/Reader/LeadingEngagementCleanerTest.php`

**Interfaces:**
- Consumes: existing `precedes(Element, Element): bool`, and the resolved `bodyStart` anchor element.
- Produces: private `removeMastheadBadges(Element $root, Element $anchor): void`, called from `removeFrom` right before the remainder sweep; private `isBadgeLink(Element $link): bool`.

- [ ] **Step 1: Write two failing tests** — the badge inside a leading `<header>` goes; the Substack poster (already covered by `testKeepsALeadingPosterLinkWhenTheSweepRuns`) must stay, so add an explicit guard test for a poster that is NOT in a header:

```php
public function testRemovesAnImageOnlyBadgeLinkInsideALeadingHeader(): void
{
    $html = '<div><article><header><div><p>'
        . '<a href="https://google.com/preferences/source?q=x.test">'
        . '<img src="https://x.test/img/badge.png" alt="Add as a preferred source on Google"></a>'
        . '</p></div></header><section><p>' . self::PROSE . '</p></section></article></div>';

    $clean = $this->clean($html, null);

    self::assertStringNotContainsString('badge.png', $clean);
    self::assertStringContainsString('Hamburg/Norderstedt', $clean);
}

public function testKeepsAnImageOnlyLinkThatIsNotInsideAHeader(): void
{
    $html = '<div><p><a href="https://x.test/post"><img src="https://x.test/poster.jpg" alt="Video"></a></p>'
        . '<p>' . self::PROSE . '</p></div>';

    self::assertStringContainsString('poster.jpg', $this->clean($html, null));
}
```

- [ ] **Step 2: Run — expected the first FAIL, the second PASS.**

- [ ] **Step 3: Implement.** Call the pass from `removeFrom` (guarded by `$removedFurniture` is wrong here — badges are their own signal, so run it unconditionally when there is an anchor, then let the sweep clear the emptied header):

```php
$anchorElement = $blocks[$anchor]->element;
$this->removeMastheadBadges($root, $anchorElement);

$followingByline = $blocks[$anchor + 1] ?? null;
// ... existing byline block unchanged ...

if ($removedFurniture || $this->removedAnyBadge) {
    $this->removeRemaindersBefore($root, $anchorElement);
}
```

Rather than track a flag, make `removeMastheadBadges` report whether it removed anything and fold it into the sweep condition:

```php
$removedFurniture = $this->removeMatchedFurniture($leading, $entryAuthor);
$removedFurniture = $this->removeMastheadBadges($root, $anchorElement) || $removedFurniture;
```

Extract the existing leading loop into `removeMatchedFurniture(array $leading, ?string $entryAuthor): bool` returning whether it removed a block (keeps `removeFrom` short). Then:

```php
/**
 * A leading <header> is the masthead; an image-only link inside it is a promo
 * badge ("preferred source on Google"), not a poster — a poster never sits in
 * a <header>, so this leaves #627 alone.
 */
private function removeMastheadBadges(Element $root, Element $anchor): bool
{
    $removed = false;
    foreach ($root->getElementsByTagName('header') as $header) {
        if (!$this->precedes($header, $anchor)) {
            continue;
        }
        foreach (iterator_to_array($header->getElementsByTagName('a')) as $link) {
            if ($this->isBadgeLink($link)) {
                $link->remove();
                $removed = true;
            }
        }
    }

    return $removed;
}

private function isBadgeLink(Element $link): bool
{
    return $link->getElementsByTagName('img')->length >= 1
        && LeadingEngagementRules::collapse($link->textContent) === '';
}
```

(`iterator_to_array` snapshots the live list so removal during iteration is safe.)

- [ ] **Step 4: Run both new tests and the whole `LeadingEngagementCleanerTest` — expected PASS.**
- [ ] **Step 5: Commit.**

---

### Task 8: Drop the duplicate in-body title (reorder `LeadingTitleRemover`)

**Files:**
- Modify: `backend/src/Service/Reader/ReaderBodyCleaner.php:65-68`
- Test: `backend/tests/Service/Reader/ReaderBodyCleanerTest.php`

**Interfaces:**
- No signature change. Only the call order changes: `LeadingTitleRemover::removeFrom` runs *after* `LeadingEngagementCleaner::removeFrom`.

- [ ] **Step 1: Write the failing test** in `ReaderBodyCleanerTest` — a kicker above the duplicate title; after cleaning the title appears zero times in the body:

```php
public function testDropsADuplicateTitleThatSatBehindAKicker(): void
{
    $title = 'Schwedens Wohlfahrtsstaat nach 30 Jahren';
    $html = '<div><p>Demokratie</p><h2>' . $title . '</h2>'
        . '<p>Schweden wählt am Sonntag ein neues Parlament und das Land ist längst nicht mehr das '
        . 'soziale Vorbild, das es über Jahrzehnte für halb Europa einmal gewesen ist.</p></div>';

    $clean = $this->clean($html, [$title], null);

    self::assertStringNotContainsString($title, $clean);
    self::assertStringContainsString('Schweden wählt', $clean);
}
```

(Match the existing `ReaderBodyCleanerTest` helper signature for `clean(...)`; read the file first and mirror how it builds candidates/lead image/media.)

- [ ] **Step 2: Run — expected FAIL** (kicker is the first text block, so the title is never inspected).

- [ ] **Step 3: Reorder in `ReaderBodyCleaner::clean`** so title removal follows furniture removal:

```php
$this->navigationTrimmer->trimIn($document);
$this->engagementCleaner->removeFrom($document, $entryAuthor);
$this->titleRemover->removeFrom($document, $titleCandidates);
$this->boilerplateTrimmer->trimIn($document);
```

- [ ] **Step 4: Run the new test and the whole `ReaderBodyCleanerTest` — expected PASS.** If any existing ordering test breaks, read it: the correct fix is to update the expectation to the new order, not to revert.
- [ ] **Step 5: Commit.**

---

### Task 9: End-to-end fixtures through `ArticleExtractor`

**Files:**
- Create: `backend/tests/Fixtures/reader/article-masthead-breadcrumb.html`, `article-masthead-header.html`, `article-masthead-toolbar.html`, `article-masthead-metabar.html`
- Test: `backend/tests/Service/Reader/ArticleExtractorTest.php`

**Interfaces:**
- Consumes: the existing `extractor(...)` helper and `extract(string $url, ?string $title = null, ?string $author = null)`.

Author each fixture as a full readability-scoring page (two substantial body paragraphs, like `article.html`) with the masthead shape at the head. Assert on `contentHtml`. The four archetypes cover every catalogue shape.

- [ ] **Step 1: Write the four fixtures.** Example — `article-masthead-header.html` (semantic header masthead + badge + duplicate title):

```html
<!DOCTYPE html>
<html lang="en"><head><title>The Haiku Challenge — Site</title></head>
<body>
<article>
  <header>
    <div><p><a href="https://google.com/preferences/source?q=site.test"><img src="/img/badge.png" alt="Add as a preferred source on Google"></a></p></div>
    <p><a href="https://site.test/topic/culture">Culture</a></p>
    <h2>The Haiku Challenge</h2>
    <p>Announcing the winning poems from the magazine's monthly challenge</p>
    <address>By <a href="https://site.test/author/x">Clark Strand</a></address>
    <time>Sep 01, 2026</time>
  </header>
  <section>
    <figure><img src="/img/hero.jpg" alt="Clouds"><figcaption>Illustration by Jing Li</figcaption></figure>
    <p>Because cumulus clouds are beautiful to behold and generally indicate fair weather, their
       white billowing shapes relax the mind and stimulate long unhurried daydreams, inviting
       comparisons to animals and people and the other shapes that live in the imagination.</p>
    <p>A playful philosophical humour informs the meaning of the best haiku, and this paragraph is
       deliberately long and prose heavy so readability scores the section as the article body and
       keeps it in the extracted output rather than discarding it as boilerplate.</p>
  </section>
</article>
</body></html>
```

Author the other three the same way: `article-masthead-breadcrumb.html` (a `<ul>` section link + a `<ul>|</ul>` separator + a `<p>` breadcrumb trail + two kicker `<p>`s above an `<h2>` duplicate title, then two body paragraphs); `article-masthead-toolbar.html` (one standfirst paragraph, then a `<div id="content_article">` toolbar with date / `0 0` counters / `9 min.` / `Drucken` icon-labels, then two body paragraphs); `article-masthead-metabar.html` (a `<ul>` with `11 min read` and a `Wallpapers` category link, then an intro `<section>` and two body paragraphs).

- [ ] **Step 2: Write the failing tests** in `ArticleExtractorTest`, one per fixture, e.g.:

```php
public function testStripsASemanticHeaderMasthead(): void
{
    $html = (string) file_get_contents(__DIR__ . '/../../Fixtures/reader/article-masthead-header.html');
    $extractor = $this->extractor([new MockResponse($html, ['http_code' => 200])]);

    $result = $extractor->extract('https://site.test/post', 'The Haiku Challenge', 'Clark Strand');
    $content = (string) $result->contentHtml;

    self::assertStringNotContainsString('badge.png', $content);
    self::assertStringNotContainsString('Culture', $content);
    self::assertStringNotContainsString('Clark Strand', $content);
    self::assertStringNotContainsString('Sep 01, 2026', $content);
    self::assertStringNotContainsString('<h2>The Haiku Challenge</h2>', $content);
    self::assertStringContainsString('Announcing the winning poems', $content);
    self::assertStringContainsString('Illustration by Jing Li', $content);
    self::assertStringContainsString('Because cumulus clouds', $content);
}
```

Write the analogous three tests, each asserting: every masthead shape gone, the hero/caption/dek/intro kept, and the body present.

- [ ] **Step 3: Run — expected FAIL / partial.** Iterate on each fixture until readability extracts the body (adjust body length/structure only, never the assertions). If readability drops a fixture entirely, lengthen the two body paragraphs to clear the coverage threshold, as in `article.html`.
- [ ] **Step 4: Run the whole `ArticleExtractorTest` — expected PASS.**
- [ ] **Step 5: Commit.**

---

### Task 10: Quality gates and live re-verification

**Files:** none (verification only).

- [ ] **Step 1: Full backend suite (SQLite).** Run: `php bin/phpunit` — expected all green.
- [ ] **Step 2: MySQL leg.** Run: `docker compose exec -T php vendor/bin/phpunit` — expected all green.
- [ ] **Step 3: Static + style + tramp.** Run: `composer check` then `composer md` — expected clean; fix any PHPMD finding on a touched `src` file by improving the design, never by tuning a threshold.
- [ ] **Step 4: PhpStorm inspections** on the changed PHP via `mcp__phpstorm__lint_files` — block on ERROR and WARNING.
- [ ] **Step 5: Mutation gate on the diff.** Run: `composer infection:diff` — raise `minMsi` only if the tree already clears it; never lower it. Add a killing test for any escaped mutant on the new predicates.
- [ ] **Step 6: Live re-verification.** With the Docker stack up, run the reader pipeline over the seven issue-#901 entries (502081, 503504, 503785, 503704, 493536, 491942, 503934, 503917) via a throwaway `tmp:` console command that prints the leading `contentHtml`, and confirm each starts at its real headline / first paragraph with hero, caption and dek intact. Delete the throwaway command before commit.
- [ ] **Step 7: Scan today's dev log** (`ls -t backend/var/log/dev-*.log | head -1`) for deprecations or swallowed errors from the run.
- [ ] **Step 8: Open the PR** into `develop` with `Closes #901`; verify the issue auto-closes on merge.
