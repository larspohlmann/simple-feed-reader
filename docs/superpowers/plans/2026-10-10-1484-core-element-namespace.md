# Core-Element Namespace Object Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Every feed dialect reads its core elements through one per-call object that holds the dialect's namespace, so no core-element call site passes a namespace.

**Architecture:** A `final readonly` `CoreElement` in `Service/Parser/Pass/` wraps a `\DOMElement` together with its dialect's core namespace and answers `text`, `httpUrl`, `child`, `children` and `at` (re-wrap another element in the same dialect). Each parser builds it in one private `core()` method: RSS 2.0 from the element's own `namespaceURI`, RSS 1.0 from `RSS1_NS`, Atom from `namespaceUri()`. `XmlHelper` gains public reducers (`firstText`, `firstHttpUrl`, `firstElement`) so `CoreElement` composes them over `XmlHelper::childElements()` directly — each `CoreElement` method is then one hop, which keeps phptramp chains at 2 hops. `XmlHelper::childTextInOwnNamespace()` is deleted.

**Tech Stack:** PHP 8.4, Symfony 7.4, PHPUnit 12, PHPStan max, phptramp.

**Spec:** GitHub issue #1484 (`gh issue view 1484`).

## Global Constraints

- Pure refactor: no parser behaviour changes. Existing parser tests pass unchanged except for the call-signature updates this plan lists; the #1467 shadowing cases in `Rss2ParserTest` must stay green untouched.
- Foreign-namespace reads (`dc:`, `content:`, `wfw:`, `media:`, `itunes:`) stay explicit `XmlHelper` calls.
- `isElement()` checks on the element itself (`supports()`, `isEntry()`) stay `XmlHelper::isElement` — they test the element, not a core child.
- CLAUDE.md Clean Code rules apply: no abbreviations, no new comments unless a reader would get the code wrong without them, `declare(strict_types=1)`.
- Commit format: `refactor(#1484): <lower-case summary>`. No attribution lines.
- Run everything from `backend/`.

---

### Task 1: `CoreElement` and the `XmlHelper` reducers

**Files:**
- Create: `backend/src/Service/Parser/Pass/CoreElement.php`
- Modify: `backend/src/Service/Parser/Support/XmlHelper.php`
- Test: `backend/tests/Service/Parser/Pass/CoreElementTest.php`

**Interfaces:**
- Produces:
  - `new CoreElement(\DOMElement $element, ?string $namespaceUri)`; public readonly `\DOMElement $element`
  - `CoreElement::at(\DOMElement $element): CoreElement` — same namespace, another element
  - `CoreElement::text(string $localName): ?string` — trimmed text of the first core child that has text
  - `CoreElement::httpUrl(string $localName): ?string` — first core child whose trimmed text is an absolute http(s) URL
  - `CoreElement::child(string $localName): ?\DOMElement`
  - `CoreElement::children(string $localName): iterable<\DOMElement>`
  - `XmlHelper::firstText(iterable<\DOMElement>): ?string`, `XmlHelper::firstHttpUrl(iterable<\DOMElement>): ?string`, `XmlHelper::firstElement(iterable<\DOMElement>): ?\DOMElement` (all public static)
  - `XmlHelper::childTextInOwnNamespace()` still exists after this task; Task 2 deletes it.

- [ ] **Step 1: Write the failing test**

`backend/tests/Service/Parser/Pass/CoreElementTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Service\Parser\Pass;

use App\Service\Parser\Pass\CoreElement;
use PHPUnit\Framework\TestCase;

final class CoreElementTest extends TestCase
{
    private const string CORE_NAMESPACE = 'urn:example:core';

    public function testReadsTheTrimmedTextOfACoreChild(): void
    {
        $item = $this->core('<item xmlns="urn:example:core"><title> Hello </title></item>');

        self::assertSame('Hello', $item->text('title'));
    }

    public function testSkipsAnEmptyCoreChildForOneWithText(): void
    {
        $item = $this->core('<item xmlns="urn:example:core"><title>  </title><title>Second</title></item>');

        self::assertSame('Second', $item->text('title'));
    }

    public function testAPrefixedChildDoesNotShadowTheCoreOne(): void
    {
        $item = $this->core(
            '<item xmlns="urn:example:core" xmlns:x="urn:example:other"><x:title>Wrong</x:title><title>Right</title></item>',
        );

        self::assertSame('Right', $item->text('title'));
    }

    public function testANullNamespaceReadsOnlyUnnamespacedChildren(): void
    {
        $item = new CoreElement(
            self::element('<item xmlns:x="urn:example:other"><x:link>https://wrong.example/</x:link><link>https://right.example/</link></item>'),
            null,
        );

        self::assertSame('https://right.example/', $item->text('link'));
    }

    public function testReadsTheFirstCoreChildThatIsAnHttpUrl(): void
    {
        $item = $this->core(
            '<item xmlns="urn:example:core"><comments>not a url</comments><comments> https://example.com/c </comments></item>',
        );

        self::assertSame('https://example.com/c', $item->httpUrl('comments'));
    }

    public function testAnHttpUrlOutsideTheCoreNamespaceIsNotRead(): void
    {
        $item = $this->core(
            '<item xmlns="urn:example:core" xmlns:x="urn:example:other"><x:comments>https://example.com/c</x:comments></item>',
        );

        self::assertNull($item->httpUrl('comments'));
    }

    public function testFindsTheFirstCoreChildElement(): void
    {
        $feed = $this->core(
            '<feed xmlns="urn:example:core" xmlns:x="urn:example:other"><x:author/><author><name>A</name></author></feed>',
        );

        self::assertSame(self::CORE_NAMESPACE, $feed->child('author')?->namespaceURI);
        self::assertNull($feed->child('missing'));
    }

    public function testListsEveryCoreChildElementInOrder(): void
    {
        $feed = $this->core(
            '<feed xmlns="urn:example:core" xmlns:x="urn:example:other"><link href="a"/><x:link href="x"/><link href="b"/></feed>',
        );

        $hrefs = array_map(
            static fn (\DOMElement $link): string => $link->getAttribute('href'),
            iterator_to_array($feed->children('link'), false),
        );

        self::assertSame(['a', 'b'], $hrefs);
    }

    public function testAtReadsAnotherElementInTheSameNamespace(): void
    {
        $feed = $this->core(
            '<feed xmlns="urn:example:core" xmlns:x="urn:example:other"><author><x:name>Wrong</x:name><name>Right</name></author></feed>',
        );
        $author = $feed->child('author');
        self::assertNotNull($author);

        self::assertSame('Right', $feed->at($author)->text('name'));
    }

    private function core(string $xml): CoreElement
    {
        return new CoreElement(self::element($xml), self::CORE_NAMESPACE);
    }

    private static function element(string $xml): \DOMElement
    {
        $document = new \DOMDocument();
        $document->loadXML($xml);
        $root = $document->documentElement;
        self::assertInstanceOf(\DOMElement::class, $root);

        return $root;
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php bin/phpunit tests/Service/Parser/Pass/CoreElementTest.php`
Expected: FAIL — `Class "App\Service\Parser\Pass\CoreElement" not found`.

- [ ] **Step 3: Make the `XmlHelper` reducers public and route the `child*` helpers through them**

In `backend/src/Service/Parser/Support/XmlHelper.php`, replace `childText`, `childElement` and `childHttpUrl` with:

```php
    public static function childText(\DOMElement $parent, string $localName, ?string $namespaceUri): ?string
    {
        return self::firstText(self::childElements($parent, $localName, $namespaceUri));
    }
```

```php
    public static function childElement(
        \DOMElement $parent,
        string $localName,
        ?string $namespaceUri,
    ): ?\DOMElement {
        return self::firstElement(self::childElements($parent, $localName, $namespaceUri));
    }

    public static function childHttpUrl(\DOMElement $parent, string $localName, ?string $namespaceUri): ?string
    {
        return self::firstHttpUrl(self::childElements($parent, $localName, $namespaceUri));
    }
```

and replace the private `firstText` at the bottom with the three public reducers:

```php
    /**
     * Trimmed text of the first element that HAS text.
     *
     * @param iterable<\DOMElement> $elements
     */
    public static function firstText(iterable $elements): ?string
    {
        foreach ($elements as $element) {
            $text = trim($element->textContent);
            if ($text !== '') {
                return $text;
            }
        }

        return null;
    }

    /** @param iterable<\DOMElement> $elements */
    public static function firstHttpUrl(iterable $elements): ?string
    {
        foreach ($elements as $element) {
            $text = trim($element->textContent);
            if (AbsoluteHttpUrl::matches($text)) {
                return $text;
            }
        }

        return null;
    }

    /** @param iterable<\DOMElement> $elements */
    public static function firstElement(iterable $elements): ?\DOMElement
    {
        foreach ($elements as $element) {
            return $element;
        }

        return null;
    }
```

Delete the docblock `/** Trimmed text of the first matching direct child that HAS text. */` above `childText` (it moved to `firstText`). Leave `childTextInOwnNamespace` alone in this task.

- [ ] **Step 4: Create `CoreElement`**

`backend/src/Service/Parser/Pass/CoreElement.php`:

```php
<?php

declare(strict_types=1);

namespace App\Service\Parser\Pass;

use App\Service\Parser\Support\XmlHelper;

/** A feed element whose core children are read only in its dialect's namespace, so an extension never shadows them. */
final readonly class CoreElement
{
    public function __construct(public \DOMElement $element, private ?string $namespaceUri)
    {
    }

    public function at(\DOMElement $element): self
    {
        return new self($element, $this->namespaceUri);
    }

    public function text(string $localName): ?string
    {
        return XmlHelper::firstText(XmlHelper::childElements($this->element, $localName, $this->namespaceUri));
    }

    public function httpUrl(string $localName): ?string
    {
        return XmlHelper::firstHttpUrl(XmlHelper::childElements($this->element, $localName, $this->namespaceUri));
    }

    public function child(string $localName): ?\DOMElement
    {
        return XmlHelper::firstElement(XmlHelper::childElements($this->element, $localName, $this->namespaceUri));
    }

    /** @return iterable<\DOMElement> */
    public function children(string $localName): iterable
    {
        return XmlHelper::childElements($this->element, $localName, $this->namespaceUri);
    }
}
```

`text`/`httpUrl`/`child` call `XmlHelper::childElements` directly rather than `$this->children()`: routing through `children()` adds a pass-through hop to every phptramp chain that ends in a core read.

- [ ] **Step 5: Run the tests to verify they pass**

Run: `php bin/phpunit tests/Service/Parser`
Expected: PASS (the new test and every existing parser test).

- [ ] **Step 6: Commit**

```bash
git add src/Service/Parser/Pass/CoreElement.php src/Service/Parser/Support/XmlHelper.php tests/Service/Parser/Pass/CoreElementTest.php
git commit -m "refactor(#1484): a core element reads children in its dialect's namespace"
```

---

### Task 2: RSS 2.0 reads through `CoreElement`

**Files:**
- Modify: `backend/src/Service/Parser/FeedFormatParser/Rss2Parser.php`
- Modify: `backend/src/Service/Parser/Support/FeedImageExtractor.php` (`fromRss2Channel` only)
- Modify: `backend/src/Service/Parser/Support/XmlHelper.php` (delete `childTextInOwnNamespace`)
- Test: `backend/tests/Service/Parser/Support/FeedImageExtractorTest.php`

**Interfaces:**
- Consumes: `CoreElement` from Task 1.
- Produces: `FeedImageExtractor::fromRss2Channel(CoreElement $channel): ?string`.

- [ ] **Step 1: Update the RSS 2.0 tests to the new signature**

In `FeedImageExtractorTest.php` add `use App\Service\Parser\Pass\CoreElement;`, change `rss2Channel()` to return the wrapped channel, and add a wrapper helper:

```php
    private function rss2Channel(string $imageMarkup): CoreElement
    {
        $document = $this->document(/** @lang TEXT */ <<<XML
            <?xml version="1.0"?>
            <rss version="2.0">
                <channel>
                    <title>Example</title>
                    $imageMarkup
                </channel>
            </rss>
            XML);

        return self::channelOf($document);
    }

    private static function channelOf(\DOMDocument $document): CoreElement
    {
        $channel = $document->getElementsByTagName('channel')->item(0);
        self::assertInstanceOf(\DOMElement::class, $channel);

        return new CoreElement($channel, $channel->namespaceURI);
    }
```

In `testReadsTheImageOfAnRss2FeedInADefaultNamespace`, replace

```php
        $channel = $document->getElementsByTagName('channel')->item(0);
        self::assertInstanceOf(\DOMElement::class, $channel);

        self::assertSame('https://example.com/logo.png', FeedImageExtractor::fromRss2Channel($channel));
```

with

```php
        self::assertSame('https://example.com/logo.png', FeedImageExtractor::fromRss2Channel(self::channelOf($document)));
```

Every other `fromRss2Channel($channel)` call already receives `rss2Channel(...)` and needs no change.

- [ ] **Step 2: Run the test to verify it fails**

Run: `php bin/phpunit tests/Service/Parser/Support/FeedImageExtractorTest.php`
Expected: FAIL — `TypeError: ...fromRss2Channel(): Argument #1 ($channel) must be of type DOMElement, App\Service\Parser\Pass\CoreElement given`.

- [ ] **Step 3: Switch `fromRss2Channel` to `CoreElement`**

In `FeedImageExtractor.php` add `use App\Service\Parser\Pass\CoreElement;` and replace `fromRss2Channel` with:

```php
    /** RSS 2.0: <channel><image><url>, else the podcast artwork. An extension's *:image is never the <image>. */
    public static function fromRss2Channel(CoreElement $channel): ?string
    {
        foreach ($channel->children('image') as $image) {
            $url = HttpsImageUrl::orNull($channel->at($image)->text('url'));
            if ($url !== null) {
                return $url;
            }
        }

        return self::podcastArtwork($channel->element);
    }
```

- [ ] **Step 4: Rewrite `Rss2Parser`**

Replace the body of `backend/src/Service/Parser/FeedFormatParser/Rss2Parser.php` from `public function parseFeed` to the end of the class with:

```php
    public function parseFeed(\DOMDocument $skeleton, array $entries): ParsedFeedModel
    {
        $channelElement = $skeleton->getElementsByTagName('channel')->item(0);
        if (!$channelElement instanceof \DOMElement) {
            throw new FeedParseException('RSS document without <channel>');
        }
        $channel = self::core($channelElement);

        return (new ParsedFeedModel(
            PlainText::from($channel->text('title')),
            $channel->text('link'),
            $channel->text('description'),
            FeedImageExtractor::fromRss2Channel($channel),
            $entries,
        ))->withShowArtwork(PodcastArtwork::of($channelElement));
    }

    public function parseEntry(\DOMElement $entry): ?ParsedEntryModel
    {
        $item = self::core($entry);
        $title = $item->text('title');
        $link = $item->text('link');
        if ($title === null && $link === null) {
            return null;
        }

        $description = self::coreOrDublinCore($item, 'description', 'description');
        $contentEncoded = XmlHelper::childText($entry, 'encoded', self::CONTENT_NS);

        $image = $this->imageSelector->fromRss2($entry, $contentEncoded ?? $description);
        $mediaBundle = $this->mediaExtractor->extract($entry);

        return new ParsedEntryModel(
            guid: GuidFallback::for($item->text('guid'), $link, $title),
            url: $link,
            title: PlainText::from($title) ?? '(untitled)',
            author: self::coreOrDublinCore($item, 'author', 'creator'),
            summary: $contentEncoded !== null ? $description : null,
            contentHtml: FeedBodyHtml::of($contentEncoded ?? $description) ?? MediaDescription::html($entry),
            publishedAt: DateParser::parse(self::coreOrDublinCore($item, 'pubDate', 'date')),
            media: new ParsedEntryMediaModel($image, $mediaBundle),
            categories: ItemCategoryExtractor::extract($entry),
            discussion: self::discussion($item),
        );
    }

    /** RSS 2.0 core elements share their parent's namespace: none, or the document's default one. */
    private static function core(\DOMElement $element): CoreElement
    {
        return new CoreElement($element, $element->namespaceURI);
    }

    private static function coreOrDublinCore(CoreElement $item, string $coreName, string $dublinCoreName): ?string
    {
        return $item->text($coreName) ?? XmlHelper::childText($item->element, $dublinCoreName, self::DC_NS);
    }

    private static function discussion(CoreElement $item): Discussion
    {
        return Discussion::of(
            $item->httpUrl('comments'),
            XmlHelper::childHttpUrl($item->element, 'commentRss', self::WFW_NS),
            CommentsLoad::Manual,
        );
    }
}
```

Add `use App\Service\Parser\Pass\CoreElement;` to the imports (alphabetical, after `App\Service\Parser\Model\ParsedFeedModel`).

- [ ] **Step 5: Delete `XmlHelper::childTextInOwnNamespace`**

Remove the method and its docblock from `XmlHelper.php`. Confirm nothing references it:

Run: `grep -rn childTextInOwnNamespace src tests`
Expected: no output.

- [ ] **Step 6: Run the tests to verify they pass**

Run: `php bin/phpunit tests/Service/Parser`
Expected: PASS, including every `Rss2ParserTest` case (#1467 shadowing included) unchanged.

- [ ] **Step 7: Commit**

```bash
git add src/Service/Parser/FeedFormatParser/Rss2Parser.php src/Service/Parser/Support/FeedImageExtractor.php src/Service/Parser/Support/XmlHelper.php tests/Service/Parser/Support/FeedImageExtractorTest.php
git commit -m "refactor(#1484): rss 2.0 reads core elements through CoreElement"
```

---

### Task 3: RSS 1.0 reads through `CoreElement`

**Files:**
- Modify: `backend/src/Service/Parser/FeedFormatParser/Rss1Parser.php`
- Modify: `backend/src/Service/Parser/Support/FeedImageExtractor.php` (`fromRss1Document` → `fromRss1Channel`)
- Test: `backend/tests/Service/Parser/Support/FeedImageExtractorTest.php`

**Interfaces:**
- Consumes: `CoreElement` from Task 1.
- Produces: `FeedImageExtractor::fromRss1Channel(CoreElement $channel): ?string` (replaces `fromRss1Document(\DOMDocument, string)`).

- [ ] **Step 1: Update the RSS 1.0 tests**

In `FeedImageExtractorTest.php` add the helper

```php
    private static function rss1Channel(\DOMDocument $document): CoreElement
    {
        $channel = $document->getElementsByTagNameNS(self::RSS1_NS, 'channel')->item(0);
        self::assertInstanceOf(\DOMElement::class, $channel);

        return new CoreElement($channel, self::RSS1_NS);
    }
```

and replace each of the three calls `FeedImageExtractor::fromRss1Document($document, self::RSS1_NS)` with `FeedImageExtractor::fromRss1Channel(self::rss1Channel($document))`.

- [ ] **Step 2: Run the test to verify it fails**

Run: `php bin/phpunit tests/Service/Parser/Support/FeedImageExtractorTest.php`
Expected: FAIL — `Call to undefined method App\Service\Parser\Support\FeedImageExtractor::fromRss1Channel()`.

- [ ] **Step 3: Replace `fromRss1Document` with `fromRss1Channel`**

In `FeedImageExtractor.php`:

```php
    /**
     * RSS 1.0: the channel only points at the image by rdf:resource; the <image> holding the <url> is its sibling at
     * the RDF root.
     */
    public static function fromRss1Channel(CoreElement $channel): ?string
    {
        $root = $channel->element->ownerDocument?->documentElement;
        if ($root === null) {
            return null;
        }

        // Direct children only: a document-wide search finds the channel's url-less <image rdf:resource> first.
        $image = $channel->at($root)->child('image');

        return $image === null ? null : HttpsImageUrl::orNull($channel->at($image)->text('url'));
    }
```

If PHPStan reports the nullsafe `?->` as unnecessary (`ownerDocument` non-null in the 8.4 stubs), drop the `?` and the `$root === null` guard keeps covering `documentElement`.

- [ ] **Step 4: Rewrite `Rss1Parser`**

Add `use App\Service\Parser\Pass\CoreElement;` and replace from `public function parseFeed` to the end of the class with:

```php
    public function parseFeed(\DOMDocument $skeleton, array $entries): ParsedFeedModel
    {
        $channelElement = $skeleton->getElementsByTagNameNS(self::RSS1_NS, 'channel')->item(0);
        if (!$channelElement instanceof \DOMElement) {
            throw new FeedParseException('RSS 1.0 document without <channel>');
        }
        $channel = self::core($channelElement);

        return new ParsedFeedModel(
            PlainText::from($channel->text('title')),
            $channel->text('link'),
            $channel->text('description'),
            FeedImageExtractor::fromRss1Channel($channel),
            $entries,
        );
    }

    public function parseEntry(\DOMElement $entry): ?ParsedEntryModel
    {
        $item = self::core($entry);
        $title = $item->text('title');
        $link = $item->text('link');
        if ($title === null && $link === null) {
            return null;
        }

        $about = trim($entry->getAttributeNS(self::RDF_NS, 'about'));
        $description = $item->text('description');
        $contentEncoded = XmlHelper::childText($entry, 'encoded', self::CONTENT_NS);
        $image = $this->imageSelector->fromRss1($entry, $contentEncoded ?? $description);
        $mediaBundle = $this->mediaExtractor->extract($entry);

        return new ParsedEntryModel(
            guid: GuidFallback::for($about === '' ? null : $about, $link, $title),
            url: $link ?? ($about === '' ? null : $about),
            title: PlainText::from($title) ?? '(untitled)',
            author: XmlHelper::childText($entry, 'creator', self::DC_NS),
            summary: $contentEncoded !== null ? $description : null,
            contentHtml: FeedBodyHtml::of($contentEncoded ?? $description),
            publishedAt: DateParser::parse(XmlHelper::childText($entry, 'date', self::DC_NS)),
            media: new ParsedEntryMediaModel($image, $mediaBundle),
            categories: ItemCategoryExtractor::extract($entry),
        );
    }

    private static function core(\DOMElement $element): CoreElement
    {
        return new CoreElement($element, self::RSS1_NS);
    }
}
```

- [ ] **Step 5: Run the tests to verify they pass**

Run: `php bin/phpunit tests/Service/Parser`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add src/Service/Parser/FeedFormatParser/Rss1Parser.php src/Service/Parser/Support/FeedImageExtractor.php tests/Service/Parser/Support/FeedImageExtractorTest.php
git commit -m "refactor(#1484): rss 1.0 reads core elements through CoreElement"
```

---

### Task 4: Atom reads through `CoreElement`

**Files:**
- Modify: `backend/src/Service/Parser/FeedFormatParser/AbstractAtomParser.php`
- Modify: `backend/src/Service/Parser/Support/FeedImageExtractor.php` (`fromAtomFeed`)
- Modify: `backend/src/Service/Parser/Support/AtomDiscussion.php`
- Modify: `backend/src/Service/Parser/ItemImageExtractor.php` (`fromAtomEnclosure`)
- Modify: `backend/src/Service/Parser/FeedItemImageSelector.php` (`fromAtom`)
- Test: `backend/tests/Service/Parser/Support/FeedImageExtractorTest.php`, `backend/tests/Service/Parser/ItemImageExtractorTest.php`, `backend/tests/Service/Parser/FeedItemImageSelectorTest.php`

**Interfaces:**
- Consumes: `CoreElement` from Task 1.
- Produces:
  - `FeedImageExtractor::fromAtomFeed(CoreElement $feed): ?string`
  - `AtomDiscussion::from(CoreElement $entry): Discussion`
  - `ItemImageExtractor::fromAtomEnclosure(CoreElement $entry): ?DeclaredImageModel`
  - `FeedItemImageSelector::fromAtom(CoreElement $entry, array $bodyHtmlCandidates): ?DeclaredImageModel`

- [ ] **Step 1: Update the Atom tests**

`FeedImageExtractorTest.php` — change `atomRoot()` to return the wrapped root:

```php
    private function atomRoot(string $xml): CoreElement
    {
        $root = $this->document($xml)->documentElement;
        self::assertInstanceOf(\DOMElement::class, $root);

        return new CoreElement($root, self::ATOM_NS);
    }
```

In `testReadsTheAtomLogo` and `testAtomIconIsNotUsedAsTheFeedImage`, replace the `$document = $this->document(...)` + `$root = $document->documentElement;` + `self::assertInstanceOf(\DOMElement::class, $root);` lines with `$root = $this->atomRoot(...)` over the same XML heredoc. Then replace every `FeedImageExtractor::fromAtomFeed($root, self::ATOM_NS)` with `FeedImageExtractor::fromAtomFeed($root)`.

`ItemImageExtractorTest.php` — add `use App\Service\Parser\Pass\CoreElement;`, change `atomEntry()` to:

```php
    private function atomEntry(string $innerXml): CoreElement
    {
        $document = new \DOMDocument();
        $document->loadXML('<feed xmlns="' . self::ATOM_NAMESPACE . '"><entry>' . $innerXml . '</entry></feed>');
        $entry = $document->getElementsByTagName('entry')->item(0);
        self::assertInstanceOf(\DOMElement::class, $entry);

        return new CoreElement($entry, self::ATOM_NAMESPACE);
    }
```

and delete the `self::ATOM_NAMESPACE,` second argument from the five `fromAtomEnclosure(...)` calls.

`FeedItemImageSelectorTest.php` — add `use App\Service\Parser\Pass\CoreElement;`, change `atomEntry()` to:

```php
    private function atomEntry(string $innerXml): CoreElement
    {
        $document = new \DOMDocument();
        $atom = '<entry xmlns="http://www.w3.org/2005/Atom">' . $innerXml . '</entry>';
        $document->loadXML($atom);
        $entry = $document->documentElement;
        self::assertInstanceOf(\DOMElement::class, $entry);

        return new CoreElement($entry, 'http://www.w3.org/2005/Atom');
    }
```

and delete the `'http://www.w3.org/2005/Atom', ` second argument from the four `$this->selector->fromAtom(...)` calls.

- [ ] **Step 2: Run the tests to verify they fail**

Run: `php bin/phpunit tests/Service/Parser`
Expected: FAIL — `TypeError`s on `fromAtomFeed`, `fromAtomEnclosure` and `fromAtom` (a `CoreElement` given where `DOMElement` is declared).

- [ ] **Step 3: Switch the Atom collaborators to `CoreElement`**

`FeedImageExtractor.php`:

```php
    /** Atom: <feed><logo>, else the podcast artwork. */
    public static function fromAtomFeed(CoreElement $feed): ?string
    {
        return HttpsImageUrl::orNull($feed->text('logo')) ?? self::podcastArtwork($feed->element);
    }
```

`AtomDiscussion.php` — add `use App\Service\Parser\Pass\CoreElement;`, then:

```php
    public static function from(CoreElement $entry): Discussion
    {
        $page = null;
        $commentsFeed = null;
        foreach (self::repliesLinks($entry) as $link) {
```

(rest of `from` unchanged) and

```php
    /** @return iterable<\DOMElement> */
    private static function repliesLinks(CoreElement $entry): iterable
    {
        foreach ($entry->children('link') as $link) {
            if ($link->getAttribute('rel') === 'replies') {
                yield $link;
            }
        }
    }
```

Remove the now-unused `XmlHelper` reference (`AtomDiscussion` sits in the same namespace as `XmlHelper`, so there is no import to delete — just confirm no `XmlHelper::` remains in the file).

`ItemImageExtractor.php` — add `use App\Service\Parser\Pass\CoreElement;`, then:

```php
    /** Atom <link rel="enclosure" type="image/*" href="…">. */
    public function fromAtomEnclosure(CoreElement $entry): ?DeclaredImageModel
    {
        foreach ($entry->children('link') as $link) {
```

(rest of the method unchanged).

`FeedItemImageSelector.php` — add `use App\Service\Parser\Pass\CoreElement;`, then:

```php
    /** @param list<?string> $bodyHtmlCandidates */
    public function fromAtom(CoreElement $entry, array $bodyHtmlCandidates): ?DeclaredImageModel
    {
        $declared = $this->extractor->fromMedia($entry->element)
            ?? $this->extractor->fromAtomEnclosure($entry)
            ?? $this->extractor->fromCustomImageElement($entry->element);

        return self::withBodyImage($declared, $this->firstBodyImage($bodyHtmlCandidates))
            ?? PodcastArtwork::of($entry->element);
    }
```

- [ ] **Step 4: Rewrite `AbstractAtomParser`**

Add `use App\Service\Parser\Pass\CoreElement;` and replace from `public function parseFeed` to the end of the class with:

```php
    public function parseFeed(\DOMDocument $skeleton, array $entries): ParsedFeedModel
    {
        $root = $skeleton->documentElement;
        if ($root === null) {
            throw new FeedParseException('Atom document without root element');
        }
        $feed = $this->core($root);

        $title = $feed->text('title');

        // A feed in the right namespace from which we extracted nothing is a
        // broken document: fail loudly so discovery/refresh report a real error
        // rather than silently creating an empty, title-less subscription.
        if ($title === null && $entries === []) {
            throw new FeedParseException('Atom feed had neither a title nor any entries');
        }

        return (new ParsedFeedModel(
            PlainText::from($title),
            self::alternateLink($feed),
            $feed->text($this->descriptionElement()),
            FeedImageExtractor::fromAtomFeed($feed),
            $entries,
        ))->withShowArtwork(PodcastArtwork::of($root));
    }

    public function parseEntry(\DOMElement $entry): ?ParsedEntryModel
    {
        $atomEntry = $this->core($entry);
        $title = $atomEntry->text('title');
        $id = $atomEntry->text('id');
        // Some WordPress Atom feeds carry the permalink only in <id>; only an absolute http(s) id may stand in,
        // since a urn:/tag: id is not fetchable.
        $link = self::alternateLink($atomEntry) ?? AbsoluteHttpUrl::orNull($id);
        if ($title === null && $link === null) {
            return null;
        }

        $contentHtml = self::elementMarkup($atomEntry, 'content');
        $image = $this->imageSelector->fromAtom(
            $atomEntry,
            [$contentHtml, self::elementMarkup($atomEntry, 'summary')],
        );
        $mediaBundle = $this->mediaExtractor->extract($entry);
        $summary = $atomEntry->text('summary');

        return new ParsedEntryModel(
            guid: GuidFallback::for($id, $link, $title),
            url: $link,
            title: PlainText::from($title) ?? '(untitled)',
            author: self::authorChildText($atomEntry, 'name'),
            summary: $summary,
            contentHtml: FeedBodyHtml::of($contentHtml) ?? ($summary === null ? MediaDescription::html($entry) : null),
            publishedAt: DateParser::parse($this->firstDate($atomEntry)),
            media: new ParsedEntryMediaModel($image, $mediaBundle),
            categories: ItemCategoryExtractor::extract($entry),
            discussion: AtomDiscussion::from($atomEntry),
            authorUrl: AbsoluteHttpUrl::orNull(self::authorChildText($atomEntry, 'uri')),
        );
    }

    private function core(\DOMElement $element): CoreElement
    {
        return new CoreElement($element, $this->namespaceUri());
    }

    private static function authorChildText(CoreElement $entry, string $localName): ?string
    {
        $author = $entry->child('author');

        return $author === null ? null : $entry->at($author)->text($localName);
    }

    /** The first present entry date, in this dialect's preference order. */
    private function firstDate(CoreElement $entry): ?string
    {
        foreach ($this->dateElements() as $element) {
            $value = $entry->text($element);
            if ($value !== null) {
                return $value;
            }
        }

        // Some Atom feeds date entries only with Dublin Core <dc:date>; dropping it would show every entry as "now".
        return XmlHelper::childText($entry->element, 'date', XmlHelper::DUBLIN_CORE_NAMESPACE);
    }

    private static function alternateLink(CoreElement $parent): ?string
    {
        $fallback = null;
        foreach ($parent->children('link') as $link) {
            $href = trim($link->getAttribute('href'));
            if ($href === '') {
                continue;
            }
            $rel = $link->getAttribute('rel');
            if ($rel === 'alternate') {
                return $href;
            }
            if ($rel === '') {
                $fallback ??= $href;
            }
        }

        return $fallback;
    }

    /**
     * An Atom text construct's markup: a type="xhtml" one carries real child elements that must be serialized, every
     * other type carries text. Both forms let an <img> be found in a summary-only entry.
     */
    private static function elementMarkup(CoreElement $entry, string $localName): ?string
    {
        $element = $entry->child($localName);
        if ($element === null) {
            return null;
        }
        if ($element->getAttribute('type') === 'xhtml') {
            $html = '';
            foreach ($element->childNodes as $inner) {
                $html .= $element->ownerDocument?->saveXML($inner);
            }
            $html = trim($html);

            return $html === '' ? null : $html;
        }
        $text = trim($element->textContent);

        return $text === '' ? null : $text;
    }
}
```

This folds the one-line `authorName()`/`authorUri()` wrappers into their single call sites: each only named a `$localName` that the call site now passes directly, and keeping them would add a pass-through hop.

- [ ] **Step 5: Run the tests to verify they pass**

Run: `php bin/phpunit tests/Service/Parser`
Expected: PASS (Atom10, Atom03, YouTube and FeedParser tests unchanged).

- [ ] **Step 6: Confirm no core-element call site still passes a dialect namespace**

Run: `grep -rnE 'namespaceUri\(\)|RSS1_NS|->namespaceURI|atomNamespace|rss1Namespace' src/Service/Parser`
Expected: only `Rss1Parser` (`RSS1_NS` const, `isEntry`, `getElementsByTagNameNS`, `core()`), `AbstractAtomParser` (`supports`, `isEntry`, `core()`, the abstract declaration), the Atom subclasses, `Rss2Parser::core()`, `XmlHelper` internals, and `StreamedFeedDocument`/`FeedParserFactory` (document-level detection, out of scope). Anything else is a missed call site — convert it.

- [ ] **Step 7: Commit**

```bash
git add src/Service/Parser tests/Service/Parser
git commit -m "refactor(#1484): atom reads core elements through CoreElement"
```

---

### Task 5: Gates

**Files:** none new; fix whatever a gate reports in files this branch touched.

- [ ] **Step 1: phptramp**

Run: `composer show phptramp/phptramp | grep source` then `composer tramp`
Expected: the `$coreName` warning from #1484 is gone and no new chain appears. If a 3-hop warning remains on a `$localName` that is genuine helper-API layering, suppress it with a `// phptramp-ignore` comment on the hop's declaration line (the `#[TrampIgnore]` attribute class lives in a dev-only package, so `src` must not import it). Do not drop a layer just to shorten a chain.

- [ ] **Step 2: Style, static analysis, codesize**

Run: `composer cs && composer stan && composer md`
Expected: all clean. PHPMD must be clean on every touched `src` file (CLAUDE.md standing rule). Then run PhpStorm `lint_files` on the changed PHP files; block on ERROR/WARNING.

- [ ] **Step 3: Both test legs**

Run: `composer test:parallel`
Expected: PASS.

Run (from the repo root, after checking the php container serves this checkout): `docker compose exec php composer test`
Expected: PASS.

- [ ] **Step 4: Mutation gate**

Run: `composer infection:diff`
Expected: MSI at or above `minMsi` in `infection.json5`. Kill any escaped mutant on a touched line with a test in the matching test file (most likely candidates: `XmlHelper::firstHttpUrl`'s `matches` branch, `fromRss1Channel`'s null guard).

- [ ] **Step 5: Commit any gate fixes**

```bash
git add -A src tests
git commit -m "refactor(#1484): gate fixes"
```

(Skip if nothing changed.)

---

## Execution notes

- `CoreElementTest` was reshaped during the gates: each case passes only its children, which `core()` wraps in a root element in the core namespace. Cases that need a foreign element declare `xmlns:x` inline through `OTHER_NAMESPACE` (PHPCS line length; PhpStorm flagged an unused namespace declaration on the shared root). The `children()` case reads `rel` instead of `href`, because PhpStorm resolves `href` values as files.
- Two pre-existing `atomRoot(<<<'XML'` heredocs in `FeedImageExtractorTest` got `/** @lang TEXT */`, like the rest of the file, which clears PhpStorm's "XML declaration should precede" ERRORs.
- Infection let the nullsafe `ownerDocument?->` in `fromRss1Channel` escape. `testAChannelOutsideAnyDocumentYieldsNoRss1Image` pins it with a detached `\DOMElement`; the mutant fails it via `failOnWarning`.
- phptramp reported no chains at all after Task 4, so no suppression was needed.
