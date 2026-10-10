# Enclosure and Category Reads in the Dialect Namespace — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** RSS 2.0/RSS 1.0/Atom item-level `enclosure`, `link rel="enclosure"` and `category` reads match only elements in the dialect's own namespace, through the item's `CoreElement` (#1484), so an extension element (`media:category`, `x:enclosure`) never counts as the core one.

**Architecture:** `CoreElement` gains `isCore(\DOMNode $node, string $localName): bool`, which tests one node against the held namespace. It is needed where a read walks the item's children in document order and mixes core and foreign matches (categories interleaved with `dc:subject`; enclosures interleaved with `media:content`), so `children()` alone cannot keep the order. `ItemCategoryExtractor::extract`, `ItemMediaExtractor::extract`, `ItemImageExtractor::fromRssEnclosure` and `FeedItemImageSelector::fromRss2`/`fromRss1` take the item's `CoreElement`. Inside a `<media:group>`, media nodes are Media RSS slots only (`MediaRssSlot::isContentOrThumbnail`).

**Measured before planning** (#1487 asks for it): 235 real feeds from the dev database's subscriptions, 221 parsable, 8,276 items. Zero items carry a prefixed `category` or `enclosure`, or a prefixed `link rel="enclosure"`, and a synthetic probe confirmed the audit detects them. 124 of those feeds do carry core categories. Across 96 `<media:group>`s, the children are only `content`, `thumbnail`, `title`, `description` and `community`. No `enclosure` or `link` appears. So the observable change for subscribed feeds is nil.

**Tech Stack:** PHP 8.4, PHPUnit 12, PHPStan max, phptramp, Infection.

**Spec:** GitHub issue #1487.

## Global Constraints

- Foreign-namespace reads (`dc:subject`, `media:*`, `itunes:*`) stay explicit.
- CLAUDE.md Clean Code rules apply. Add no comments beyond what a reader would otherwise get wrong. Use `declare(strict_types=1)`.
- In tests, an item's core namespace is the item's own `namespaceURI`. That holds for all three dialects: an RSS 2.0 `<item>` has none, an RSS 1.0 `<item>` is in `RSS1_NS`, an Atom `<entry>` is in the Atom namespace.
- Commit format: `fix(#1487): <lower-case summary>`. No attribution lines. Run from `backend/`.

---

### Task 1: `CoreElement::isCore`

**Files:**
- Modify: `backend/src/Service/Parser/Pass/CoreElement.php`
- Test: `backend/tests/Service/Parser/Pass/CoreElementTest.php`

**Interfaces:**
- Produces: `CoreElement::isCore(\DOMNode $node, string $localName): bool`, with `@phpstan-assert-if-true =\DOMElement $node`.

- [ ] **Step 1: Failing test.** Append to `CoreElementTest`:

```php
    public function testIsCoreMatchesOnlyACoreElementOfThatName(): void
    {
        $item = $this->core(
            '<category>A</category><x:category' . self::OTHER_NAMESPACE_DECLARATION . '>B</x:category>text',
        );
        [$core, $foreign, $text] = iterator_to_array($item->element->childNodes, false);

        self::assertTrue($item->isCore($core, 'category'));
        self::assertFalse($item->isCore($core, 'link'));
        self::assertFalse($item->isCore($foreign, 'category'));
        self::assertFalse($item->isCore($text, 'category'));
    }
```

Run: `php bin/phpunit tests/Service/Parser/Pass/CoreElementTest.php`. Expected: FAIL (`Call to undefined method …isCore()`).

- [ ] **Step 2: Implement.** In `CoreElement`, after `at()`:

```php
    /** @phpstan-assert-if-true =\DOMElement $node */
    public function isCore(\DOMNode $node, string $localName): bool
    {
        return XmlHelper::isElement($node, $localName, $this->namespaceUri);
    }
```

Run the test again. Expected: PASS.

- [ ] **Step 3: Commit.** `git commit -m "fix(#1487): a core element tells whether a node is one of its core children"`

---

### Task 2: categories in the dialect namespace

**Files:**
- Modify: `backend/src/Service/Parser/Support/ItemCategoryExtractor.php`
- Modify: `backend/src/Service/Parser/FeedFormatParser/Rss2Parser.php`, `Rss1Parser.php` and `AbstractAtomParser.php` (the `categories:` arguments)
- Test: `backend/tests/Service/Parser/Support/ItemCategoryExtractorTest.php`

**Interfaces:**
- Consumes: `CoreElement::isCore` (Task 1).
- Produces: `ItemCategoryExtractor::extract(CoreElement $item): list<ParsedCategoryModel>`.

- [ ] **Step 1: Tests.** In `ItemCategoryExtractorTest`, add `use App\Service\Parser\Pass\CoreElement;`. Change `firstItem()` to return `CoreElement`, replacing its final comment and `return $item;` with `return new CoreElement($item, $item->namespaceURI);`. Then append:

```php
    public function testAnExtensionCategoryIsNotAnItemCategory(): void
    {
        $item = $this->firstItem(
            '<item xmlns:media="http://search.yahoo.com/mrss/">'
            . '<media:category>Music</media:category><category>World</category>'
            . '</item>',
        );

        $labels = array_map(static fn ($category) => $category->label, ItemCategoryExtractor::extract($item));

        self::assertSame(['World'], $labels);
    }

    public function testAnAtomEntryReadsOnlyAtomCategories(): void
    {
        $item = $this->firstItem(
            '<entry xmlns="http://www.w3.org/2005/Atom" xmlns:x="urn:example:other">'
            . '<x:category term="Wrong"/><category term="Right"/>'
            . '</entry>',
        );

        $labels = array_map(static fn ($category) => $category->label, ItemCategoryExtractor::extract($item));

        self::assertSame(['Right'], $labels);
    }
```

Run: `php bin/phpunit tests/Service/Parser/Support/ItemCategoryExtractorTest.php`. Expected: FAIL (`TypeError`: `CoreElement` given where `DOMElement` is declared).

- [ ] **Step 2: Implement.** In `ItemCategoryExtractor`, add `use App\Service\Parser\Pass\CoreElement;` and replace `extract` and `fromChild` with:

```php
    /** @return list<ParsedCategoryModel> */
    public static function extract(CoreElement $item): array
    {
        $categories = [];
        foreach ($item->element->childNodes as $child) {
            $category = self::fromChild($item, $child);
            if ($category !== null) {
                $categories[] = $category;
            }
        }

        return $categories;
    }

    private static function fromChild(CoreElement $item, \DOMNode $child): ?ParsedCategoryModel
    {
        if ($item->isCore($child, 'category')) {
            return self::fromCategoryElement($child);
        }
        if (XmlHelper::isElement($child, 'subject', XmlHelper::DUBLIN_CORE_NAMESPACE)) {
            $label = trim($child->textContent);

            return $label === '' ? null : new ParsedCategoryModel($label);
        }

        return null;
    }
```

The `instanceof \DOMElement` guard goes: `isCore` and `isElement` both reject non-elements, and both assert `\DOMElement` to PHPStan. In the three parsers, `categories: ItemCategoryExtractor::extract($entry)` becomes `ItemCategoryExtractor::extract($item)` in `Rss2Parser` and `Rss1Parser`, and `ItemCategoryExtractor::extract($atomEntry)` in `AbstractAtomParser`.

- [ ] **Step 3: Run** `php bin/phpunit tests/Service/Parser`. Expected: PASS.

- [ ] **Step 4: Commit.** `git commit -m "fix(#1487): item categories are read in the dialect namespace only"`

---

### Task 3: enclosures in the dialect namespace

**Files:**
- Modify: `backend/src/Service/Parser/ItemImageExtractor.php` (`fromRssEnclosure`)
- Modify: `backend/src/Service/Parser/FeedItemImageSelector.php` (`fromRss2`, `fromRss1`)
- Modify: `backend/src/Service/Parser/ItemMediaExtractor.php`
- Modify: `backend/src/Service/Parser/FeedFormatParser/Rss2Parser.php`, `Rss1Parser.php` and `AbstractAtomParser.php` (the `fromRss2`/`fromRss1`/`extract` calls)
- Test: `backend/tests/Service/Parser/ItemImageExtractorTest.php`, `ItemMediaExtractorTest.php`, `FeedItemImageSelectorTest.php` and `backend/tests/Service/Ingest/EntryImageWriterTest.php`

**Interfaces:**
- Consumes: `CoreElement::isCore` and `CoreElement::children` (Task 1, #1484).
- Produces:
  - `ItemImageExtractor::fromRssEnclosure(CoreElement $item): ?DeclaredImageModel`
  - `FeedItemImageSelector::fromRss2(CoreElement $item, ?string $bodyHtml)`
  - `FeedItemImageSelector::fromRss1(CoreElement $item, ?string $bodyHtml)`
  - `ItemMediaExtractor::extract(CoreElement $item): ParsedMediaBundleModel`

- [ ] **Step 1: Tests.**

`ItemImageExtractorTest`: add `use App\Service\Parser\Pass\CoreElement;` (already imported since #1485; check) and the helper

```php
    private static function core(\DOMElement $item): CoreElement
    {
        return new CoreElement($item, $item->namespaceURI);
    }
```

Wrap the item argument of the three `fromRssEnclosure(...)` calls in `self::core(...)`, and append:

```php
    public function testAPrefixedEnclosureIsNotTheRssEnclosure(): void
    {
        self::assertNull($this->extractor->fromRssEnclosure(self::core($this->item(
            '<x:enclosure xmlns:x="urn:example:other" url="https://i/x.jpg" type="image/jpeg"/>',
        ))));
    }
```

`ItemMediaExtractorTest`: add `use App\Service\Parser\Pass\CoreElement;`. Change `rssItem()` and `atomEntry()` to return `CoreElement`, each ending `return new CoreElement($item, $item->namespaceURI);` (`$entry` in `atomEntry`). Append:

```php
    public function testAPrefixedEnclosureIsNoAttachment(): void
    {
        $bundle = $this->extractor->extract($this->rssItem(
            '<x:enclosure xmlns:x="urn:example:other" url="https://cdn.test/a.mp3" type="audio/mpeg"/>',
        ));

        self::assertSame([], $bundle->attachments);
    }

    public function testAnEnclosureLinkOutsideTheAtomNamespaceIsNoAttachment(): void
    {
        $bundle = $this->extractor->extract($this->atomEntry(
            '<x:link xmlns:x="urn:example:other" rel="enclosure" href="https://cdn.test/a.mp3" type="audio/mpeg"/>',
        ));

        self::assertSame([], $bundle->attachments);
    }
```

`FeedItemImageSelectorTest`: change `rss2Item()` and `rss1Item()` to return `CoreElement`, each ending `return new CoreElement($item, $item->namespaceURI);`.

`EntryImageWriterTest` (around line 235): add `use App\Service\Parser\Pass\CoreElement;` and pass `new CoreElement($item, $item->namespaceURI)` to `fromRss2`.

Run: `php bin/phpunit tests/Service/Parser tests/Service/Ingest/EntryImageWriterTest.php`. Expected: FAIL with `TypeError`s.

- [ ] **Step 2: `ItemImageExtractor::fromRssEnclosure`**

```php
    /** RSS 2.0 <enclosure type="image/*" url="…">. */
    public function fromRssEnclosure(CoreElement $item): ?DeclaredImageModel
    {
        foreach ($item->children('enclosure') as $enclosure) {
            if (!str_starts_with(strtolower($enclosure->getAttribute('type')), 'image/')) {
                continue;
            }
            $url = trim($enclosure->getAttribute('url'));
            if ($url !== '') {
                return DeclaredImages::fromElement($enclosure, $url);
            }
        }

        return null;
    }
```

- [ ] **Step 3: `FeedItemImageSelector`**

```php
    public function fromRss2(CoreElement $item, ?string $bodyHtml): ?DeclaredImageModel
    {
        $declared = $this->extractor->fromMedia($item->element)
            ?? $this->extractor->fromRssEnclosure($item)
            ?? $this->extractor->fromCustomImageElement($item->element);

        return self::withBodyImage($declared, $this->extractor->fromHtml($bodyHtml)) ?? PodcastArtwork::of($item->element);
    }

    public function fromRss1(CoreElement $item, ?string $bodyHtml): ?DeclaredImageModel
    {
        $declared = $this->extractor->fromMedia($item->element) ?? $this->extractor->fromCustomImageElement($item->element);

        return self::withBodyImage($declared, $this->extractor->fromHtml($bodyHtml));
    }
```

Wrap lines over 120 columns the way `fromAtom` does.

- [ ] **Step 4: `ItemMediaExtractor`.** Add `use App\Service\Parser\Pass\CoreElement;`. Replace `extract`, `fromChild`, `mediaNodesIn`, `mediaNode` and `isMediaNode` with:

```php
    public function extract(CoreElement $item): ParsedMediaBundleModel
    {
        $fallbackDuration = self::itunesDuration($item->element);
        $media = [];
        $attachments = [];
        foreach ($item->element->childNodes as $child) {
            $bundle = self::fromChild($item, $child, $fallbackDuration);
            if ($bundle === null) {
                continue;
            }
            array_push($media, ...$bundle->media);
            array_push($attachments, ...$bundle->attachments);
        }

        return new ParsedMediaBundleModel($media, $attachments);
    }

    private static function fromChild(CoreElement $item, \DOMNode $child, ?int $fallbackDuration): ?ParsedMediaBundleModel
    {
        if (XmlHelper::isElement($child, 'group', XmlHelper::MEDIA_RSS_NAMESPACE)) {
            return self::fromGroup($child, $fallbackDuration);
        }

        return self::isItemMediaNode($item, $child)
            ? self::fromNode(new FeedMediaNode($child), $fallbackDuration)
            : null;
    }
```

```php
    /** @return list<FeedMediaNode> */
    private static function mediaNodesIn(\DOMElement $group): array
    {
        $nodes = [];
        foreach ($group->childNodes as $child) {
            if (!$child instanceof \DOMElement || !MediaRssSlot::isContentOrThumbnail($child)) {
                continue;
            }
            $node = new FeedMediaNode($child);
            if ($node->url() !== '') {
                $nodes[] = $node;
            }
        }

        return $nodes;
    }
```

```php
    /** @phpstan-assert-if-true =\DOMElement $node */
    private static function isItemMediaNode(CoreElement $item, \DOMNode $node): bool
    {
        if ($item->isCore($node, 'enclosure')) {
            return true;
        }
        if ($item->isCore($node, 'link') && $node->getAttribute('rel') === 'enclosure') {
            return true;
        }

        return $node instanceof \DOMElement && MediaRssSlot::isContentOrThumbnail($node);
    }
```

Check `MediaRssSlot::isContentOrThumbnail`'s parameter type. If it already takes `\DOMNode`, drop the `instanceof` in the last line. Rename `fromGroup`'s use of `mediaNodesIn($group)` only if the parameter name changed; it did not.

- [ ] **Step 5: Parsers.**
  - `Rss2Parser::parseEntry`: `$this->imageSelector->fromRss2($item, …)` and `$this->mediaExtractor->extract($item)`.
  - `Rss1Parser::parseEntry`: `$this->imageSelector->fromRss1($item, …)` and `$this->mediaExtractor->extract($item)`.
  - `AbstractAtomParser::parseEntry`: `$this->mediaExtractor->extract($atomEntry)`.

- [ ] **Step 6: Run** `php bin/phpunit tests/Service/Parser tests/Service/Ingest`. Expected: PASS.

- [ ] **Step 7: Commit.** `git commit -m "fix(#1487): enclosures are read in the dialect namespace only"`

---

### Task 4: Gates

- [ ] Run `composer cs`, `composer stan`, `composer md` and `composer tramp` (expect "No tramp data found"), plus PhpStorm `lint_files` on the changed PHP. Then `composer test:parallel`, `docker compose exec php composer test` (from the repo root) and `composer infection:diff`. Kill any escaped mutant on a touched line with a test in the matching file.
- [ ] Commit any gate fixes: `fix(#1487): gate fixes`.
