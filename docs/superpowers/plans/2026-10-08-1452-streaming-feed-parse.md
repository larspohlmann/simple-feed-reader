# Streaming Feed Parse (#1452, option 3b) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Parse a feed without building the whole-document DOM. `XMLReader` streams the body, each entry is expanded into a DOM of its own and parsed right away, and only the feed's non-entry "skeleton" stays in memory.

**Architecture:** A new per-call `Parser/Pass/StreamedFeedDocument` walks the body with `XMLReader`. It rebuilds every non-entry node into a skeleton `\DOMDocument`. For each element it asks the dialect parser `isEntry()`, and for an entry it calls `expand()` and `parseEntry()`. When the walk ends it calls `parseFeed($skeleton, $entries)`. The per-entry and channel-level code (`XmlHelper`, `ItemMediaExtractor`, `FeedItemImageSelector`, `PodcastArtwork`, `FeedImageExtractor`) stays unchanged, because it already works on `\DOMElement`s.

**Tech Stack:** PHP 8.4 (`XMLReader::fromString`), legacy `\DOMDocument`, PHPUnit 12.

**Spec:** GitHub issue #1452 and its "Option 3b" comment (https://github.com/larspohlmann/simple-feed-reader/issues/1452#issuecomment-6057227985).

## Global Constraints

- The cap (`ResponseTooLargeException::MAX_BYTES`) and a `TooLarge` reason are **out of scope**. They follow in their own PRs.
- Behaviour must stay the same for every existing parser test: same entries, same order, same feed metadata, same rejections.
- Security behaviour stays the same: any DOCTYPE is rejected, no `LIBXML_NOENT`/`LIBXML_DTDLOAD`, `LIBXML_NONET` stays, and `testDoesNotFetchAnExternalDtdOverTheNetwork` stays green.
- A malformed document fails the whole feed (`FeedParseException('Document is not well-formed XML')`), even after some entries have parsed.
- Only libxml **fatal** errors fail a feed. Recoverable ones (an undefined namespace prefix) don't, matching `loadXML` today.
- CLAUDE.md house rules: `final`, no abbreviations, no comment unless a reader would get the code wrong without it, PHPMD clean on every touched `src` file, and a `type(#1452):` commit format.
- The PR body says `Refs #1452`, never a closing keyword: the issue stays open for the cap raise and `TooLarge`.

## File Structure

| File | Change |
|---|---|
| `backend/tests/Support/FeedFormatParsers.php` | Add `all()` and `feed(string $xml)`, so tests parse a string through the real entry point |
| `backend/tests/Service/Parser/FeedFormatParser/{Rss2,Rss1,Atom10,Atom03}ParserTest.php`, `tests/Service/Parser/Support/ItemCategoryExtractorTest.php` | Parse strings through `FeedFormatParsers::feed()` instead of handing a `\DOMDocument` to `parse()` |
| `backend/tests/Service/Parser/FeedParserTest.php`, `tests/Support/RefreshRunners.php` | Use `FeedFormatParsers::all()`; add characterization tests |
| `backend/src/Service/Parser/FeedFormatParser/FeedFormatParserInterface.php` | `parse(\DOMDocument)` becomes `isEntry` + `parseEntry` + `parseFeed` |
| `backend/src/Service/Parser/FeedFormatParser/{Rss2Parser,Rss1Parser,AbstractAtomParser}.php` | Implement the new interface |
| `backend/src/Service/Parser/Pass/StreamedFeedDocument.php` | **New**: the XMLReader walk |
| `backend/src/Service/Parser/FeedParser.php` | Drive `StreamedFeedDocument` instead of `loadXML` |

---

### Task 1: Tests parse strings, and characterization tests pin the risky behaviour

The format-parser tests hand `parse()` a `\DOMDocument`, which won't exist after Task 2. This task moves them to string input through `FeedParser`, the real entry point, and adds pins for behaviour the rewrite could break. Everything here passes against the **current** implementation. That is the point: it is the safety net for Task 2.

**Files:**
- Modify: `backend/tests/Support/FeedFormatParsers.php`
- Modify: `backend/tests/Service/Parser/FeedFormatParser/Rss2ParserTest.php`, `Rss1ParserTest.php`, `Atom10ParserTest.php`, `Atom03ParserTest.php`
- Modify: `backend/tests/Service/Parser/Support/ItemCategoryExtractorTest.php:135-140`
- Modify: `backend/tests/Service/Parser/FeedParserTest.php`
- Modify: `backend/tests/Support/RefreshRunners.php:189-194`

**Interfaces:**
- Produces: `FeedFormatParsers::all(): list<FeedFormatParserInterface>`, `FeedFormatParsers::feed(string $xml): ParsedFeedModel`

- [ ] **Step 1: Add the helpers**

In `tests/Support/FeedFormatParsers.php` add these imports: `App\Service\Parser\Factory\FeedParserFactory`, `App\Service\Parser\FeedFormatParser\FeedFormatParserInterface`, `App\Service\Parser\FeedParser`, `App\Service\Parser\Model\ParsedFeedModel`. Then add, after `atom03()`:

```php
    /** @return list<FeedFormatParserInterface> */
    public static function all(): array
    {
        return [self::rss2(), self::atom10(), self::atom03(), self::rss1()];
    }

    public static function feed(string $xml): ParsedFeedModel
    {
        return new FeedParser(new FeedParserFactory(self::all()))->parse($xml);
    }
```

- [ ] **Step 2: Point the existing wiring at `all()`**

`tests/Service/Parser/FeedParserTest.php`, `parser()`:

```php
    private function parser(): FeedParser
    {
        return new FeedParser(new FeedParserFactory(FeedFormatParsers::all()));
    }
```

`tests/Support/RefreshRunners.php`, inside `bodyParser()`:

```php
            XmlBodyParser::format() => static fn (): XmlBodyParser => new XmlBodyParser(
                new FeedParser(new FeedParserFactory(FeedFormatParsers::all())),
            ),
```

- [ ] **Step 3: Migrate the format-parser tests to string input**

Rss2ParserTest and Rss1ParserTest: delete the private `document()` helper. Then:

```bash
cd backend
sed -i '' -E 's/FeedFormatParsers::(rss2|rss1)\(\)->parse\(\$this->document\(\$xml\)\)/FeedFormatParsers::feed($xml)/' \
  tests/Service/Parser/FeedFormatParser/Rss2ParserTest.php tests/Service/Parser/FeedFormatParser/Rss1ParserTest.php
```

In both `parseSingleItem()` helpers, rename `$document = $this->document(<<<XML` to `$xml = <<<XML` (closing the heredoc with `XML;` instead of `XML);`), and change the return line:

```php
        return FeedFormatParsers::feed($xml)->entries[0];
```

Atom10ParserTest and Atom03ParserTest: replace the private `parse()` helper body:

```php
    private function parse(string $xml): ParsedFeedModel
    {
        return FeedFormatParsers::feed($xml);
    }
```

(Keep the helper's existing return type and imports, and drop the `\DOMDocument` lines.)

`ItemCategoryExtractorTest::testRss2ParserPopulatesEntryCategories`: replace the three lines building `$document` and calling `rss2()->parse($document)` with:

```php
        $feed = FeedFormatParsers::feed($xml);
```

Then `grep -n "DOMDocument\|->parse(\$document" tests/Service/Parser/FeedFormatParser tests/Service/Parser/Support/ItemCategoryExtractorTest.php` must print only the `ItemCategoryExtractorTest` lines 15–17 (its own element helper, untouched).

- [ ] **Step 4: Add characterization tests to `FeedParserTest`**

Append these tests before the closing brace:

```php
    public function testAnUndeclaredNamespacePrefixDoesNotFailTheFeed(): void
    {
        $feed = $this->parser()->parse(
            '<?xml version="1.0"?><rss version="2.0"><channel><title>Loose</title><itunes:author>A</itunes:author>'
            . '<item><title>One</title><link>https://loose.example.com/1</link><media:thumbnail url="x"/></item>'
            . '</channel></rss>',
        );

        self::assertSame('Loose', $feed->title);
        self::assertSame(['One'], array_map(static fn ($entry) => $entry->title, $feed->entries));
    }

    public function testAMalformedItemAfterGoodOnesFailsTheWholeFeed(): void
    {
        $this->expectException(FeedParseException::class);
        $this->parser()->parse(
            '<?xml version="1.0"?><rss version="2.0"><channel><title>Broken</title>'
            . '<item><title>Good</title><link>https://broken.example.com/1</link></item>'
            . '<item><title>Bad<link>https://broken.example.com/2</link></item>'
            . '</channel></rss>',
        );
    }

    public function testATruncatedBodyFailsTheWholeFeed(): void
    {
        $this->expectException(FeedParseException::class);
        $this->parser()->parse(
            '<?xml version="1.0"?><rss version="2.0"><channel><title>Cut</title>'
            . '<item><title>Good</title><link>https://cut.example.com/1</link></item><item><title>Ha',
        );
    }

    public function testChannelMetadataAfterTheItemsStillCounts(): void
    {
        $feed = $this->parser()->parse(
            '<?xml version="1.0"?><rss version="2.0"><channel>'
            . '<item><title>One</title><link>https://late.example.com/1</link></item>'
            . '<title><![CDATA[Late & Titled]]></title><!-- note --><link>https://late.example.com/</link>'
            . '<image><url>https://late.example.com/logo.png</url></image>'
            . '</channel></rss>',
        );

        self::assertSame('Late & Titled', $feed->title);
        self::assertSame('https://late.example.com/', $feed->siteUrl);
        self::assertSame('https://late.example.com/logo.png', $feed->imageUrl);
        self::assertCount(1, $feed->entries);
    }

    public function testAnItemInsideAnExtensionElementOfTheChannelIsStillAnEntry(): void
    {
        $feed = $this->parser()->parse(
            '<?xml version="1.0"?><rss version="2.0"><channel><title>Nested</title>'
            . '<section><item><title>Deep</title><link>https://nested.example.com/1</link></item></section>'
            . '</channel></rss>',
        );

        self::assertSame(['Deep'], array_map(static fn ($entry) => $entry->title, $feed->entries));
    }

    public function testAnEmptyRssRootIsAFeedWithoutAChannel(): void
    {
        $this->expectException(FeedParseException::class);
        $this->expectExceptionMessage('RSS document without <channel>');
        $this->parser()->parse('<?xml version="1.0"?><rss version="2.0"/>');
    }

    public function testAnAtomEntryOutsideTheFeedRootLevelIsNotAnEntry(): void
    {
        $feed = $this->parser()->parse(
            '<?xml version="1.0"?><feed xmlns="http://www.w3.org/2005/Atom"><title>Atom</title>'
            . '<wrapper><entry><title>Hidden</title><link href="https://a.example.com/h"/></entry></wrapper>'
            . '<entry><title>Shown</title><link href="https://a.example.com/s"/></entry></feed>',
        );

        self::assertSame(['Shown'], array_map(static fn ($entry) => $entry->title, $feed->entries));
    }

    public function testAnAtomXhtmlContentKeepsItsMarkupAndNamespace(): void
    {
        $feed = $this->parser()->parse(
            '<?xml version="1.0"?><feed xmlns="http://www.w3.org/2005/Atom" xmlns:x="http://www.w3.org/1999/xhtml">'
            . '<title>Atom</title><entry><title>E</title><link href="https://a.example.com/e"/>'
            . '<content type="xhtml"><x:div><x:p>Hi <x:b>there</x:b></x:p></x:div></content></entry></feed>',
        );

        self::assertStringContainsString('<x:b>there</x:b>', (string) $feed->entries[0]->contentHtml);
    }
```

- [ ] **Step 5: Run the parser tests against the current code**

Run: `cd backend && php bin/phpunit tests/Service/Parser tests/Service/Refresh`
Expected: PASS, all green (the new tests are characterization tests). If one fails, the test is wrong about today's behaviour: fix the **assertion** to match what the current code does, and note it in the commit body. The point is to pin today's behaviour, not to change it.

- [ ] **Step 6: Commit**

```bash
git add backend/tests
git commit -m "test(#1452): format-parser tests parse strings through FeedParser, and pins for what a streaming parse could break"
```

---

### Task 2: Stream the document and expand each entry into its own DOM

**Files:**
- Create: `backend/src/Service/Parser/Pass/StreamedFeedDocument.php`
- Modify: `backend/src/Service/Parser/FeedFormatParser/FeedFormatParserInterface.php`
- Modify: `backend/src/Service/Parser/FeedFormatParser/Rss2Parser.php`, `Rss1Parser.php`, `AbstractAtomParser.php`
- Modify: `backend/src/Service/Parser/FeedParser.php`
- Test: the suite from Task 1 (no new tests: the change is invisible from outside except in memory, which Task 3 measures)

**Interfaces:**
- Consumes: `FeedFormatParsers::feed()` (Task 1)
- Produces:
  - `FeedFormatParserInterface::isEntry(\DOMElement $element): bool`
  - `FeedFormatParserInterface::parseEntry(\DOMElement $entry): ?ParsedEntryModel`
  - `FeedFormatParserInterface::parseFeed(\DOMDocument $skeleton, array $entries): ParsedFeedModel` (`list<ParsedEntryModel>`)
  - `StreamedFeedDocument::open(string $xml): self`, `->root(): \DOMElement`, `->parseWith(FeedFormatParserInterface $parser): ParsedFeedModel`

- [ ] **Step 1: The interface**

Replace `FeedFormatParserInterface.php`'s body after `supports()`:

```php
    public function supports(\DOMElement $root): bool;

    /** Asked of each element as the document streams past, once it sits in the skeleton under its parent. */
    public function isEntry(\DOMElement $element): bool;

    public function parseEntry(\DOMElement $entry): ?ParsedEntryModel;

    /**
     * @param \DOMDocument $skeleton the whole feed document except its entries
     * @param list<ParsedEntryModel> $entries
     */
    public function parseFeed(\DOMDocument $skeleton, array $entries): ParsedFeedModel;
```

Add `use App\Service\Parser\Model\ParsedEntryModel;`.

- [ ] **Step 2: Rss2Parser**

Replace `parse()` and `parseItem()` with:

```php
    /** Any unprefixed <item> at any depth, as getElementsByTagName('item') found them before #1452. */
    public function isEntry(\DOMElement $element): bool
    {
        return $element->nodeName === 'item';
    }

    public function parseFeed(\DOMDocument $skeleton, array $entries): ParsedFeedModel
    {
        $channel = $skeleton->getElementsByTagName('channel')->item(0);
        if (!$channel instanceof \DOMElement) {
            throw new FeedParseException('RSS document without <channel>');
        }

        return (new ParsedFeedModel(
            PlainText::from(XmlHelper::childText($channel, 'title')),
            XmlHelper::childText($channel, 'link'),
            XmlHelper::childText($channel, 'description'),
            FeedImageExtractor::fromRss2Channel($channel),
            $entries,
        ))->withShowArtwork(PodcastArtwork::of($channel));
    }

    public function parseEntry(\DOMElement $entry): ?ParsedEntryModel
    {
        $title = XmlHelper::childText($entry, 'title');
        $link = XmlHelper::childText($entry, 'link');
        if ($title === null && $link === null) {
            return null;
        }

        $description = XmlHelper::childText($entry, 'description');
        $contentEncoded = XmlHelper::childText($entry, 'encoded', self::CONTENT_NS);

        $image = $this->imageSelector->fromRss2($entry, $contentEncoded ?? $description);
        $mediaBundle = $this->mediaExtractor->extract($entry);

        return new ParsedEntryModel(
            guid: GuidFallback::for(XmlHelper::childText($entry, 'guid'), $link, $title),
            url: $link,
            title: PlainText::from($title) ?? '(untitled)',
            author: XmlHelper::childText($entry, 'author') ?? XmlHelper::childText($entry, 'creator', self::DC_NS),
            summary: $contentEncoded !== null ? $description : null,
            contentHtml: PlainTextBody::asHtml($contentEncoded ?? $description),
            publishedAt: DateParser::parse(
                XmlHelper::childText($entry, 'pubDate') ?? XmlHelper::childText($entry, 'date', self::DC_NS),
            ),
            media: new ParsedEntryMediaModel($image, $mediaBundle),
            categories: ItemCategoryExtractor::extract($entry),
            discussion: self::discussion($entry),
        );
    }
```

(`discussion(\DOMElement $item)` stays as is.)

- [ ] **Step 3: Rss1Parser**

Replace `parse()` and `parseItem()` with:

```php
    public function isEntry(\DOMElement $element): bool
    {
        return $element->localName === 'item' && $element->namespaceURI === self::RSS1_NS;
    }

    public function parseFeed(\DOMDocument $skeleton, array $entries): ParsedFeedModel
    {
        $channel = $skeleton->getElementsByTagNameNS(self::RSS1_NS, 'channel')->item(0);
        if (!$channel instanceof \DOMElement) {
            throw new FeedParseException('RSS 1.0 document without <channel>');
        }

        return new ParsedFeedModel(
            PlainText::from(XmlHelper::childText($channel, 'title', self::RSS1_NS)),
            XmlHelper::childText($channel, 'link', self::RSS1_NS),
            XmlHelper::childText($channel, 'description', self::RSS1_NS),
            FeedImageExtractor::fromRss1Document($skeleton, self::RSS1_NS),
            $entries,
        );
    }

    public function parseEntry(\DOMElement $entry): ?ParsedEntryModel
    {
        $title = XmlHelper::childText($entry, 'title', self::RSS1_NS);
        $link = XmlHelper::childText($entry, 'link', self::RSS1_NS);
        if ($title === null && $link === null) {
            return null;
        }

        $about = trim($entry->getAttributeNS(self::RDF_NS, 'about'));
        $description = XmlHelper::childText($entry, 'description', self::RSS1_NS);
        $contentEncoded = XmlHelper::childText($entry, 'encoded', self::CONTENT_NS);
        $image = $this->imageSelector->fromRss1($entry, $contentEncoded ?? $description);
        $mediaBundle = $this->mediaExtractor->extract($entry);

        return new ParsedEntryModel(
            guid: GuidFallback::for($about === '' ? null : $about, $link, $title),
            url: $link ?? ($about === '' ? null : $about),
            title: PlainText::from($title) ?? '(untitled)',
            author: XmlHelper::childText($entry, 'creator', self::DC_NS),
            summary: $contentEncoded !== null ? $description : null,
            contentHtml: PlainTextBody::asHtml($contentEncoded ?? $description),
            publishedAt: DateParser::parse(XmlHelper::childText($entry, 'date', self::DC_NS)),
            media: new ParsedEntryMediaModel($image, $mediaBundle),
            categories: ItemCategoryExtractor::extract($entry),
        );
    }
```

- [ ] **Step 4: AbstractAtomParser**

Replace `parse()` with `isEntry()` and `parseFeed()`, and make `parseEntry()` public (body unchanged):

```php
    /** Only the feed's own children: an <entry> nested anywhere else was never one. */
    public function isEntry(\DOMElement $element): bool
    {
        return $element->localName === 'entry'
            && $element->namespaceURI === $this->namespaceUri()
            && $element->parentNode === $element->ownerDocument?->documentElement;
    }

    public function parseFeed(\DOMDocument $skeleton, array $entries): ParsedFeedModel
    {
        $root = $skeleton->documentElement;
        if ($root === null) {
            throw new FeedParseException('Atom document without root element');
        }

        $title = XmlHelper::childText($root, 'title', $this->namespaceUri());

        // A feed in the right namespace from which we extracted nothing is a
        // broken document: fail loudly so discovery/refresh report a real error
        // rather than silently creating an empty, title-less subscription.
        if ($title === null && $entries === []) {
            throw new FeedParseException('Atom feed had neither a title nor any entries');
        }

        return (new ParsedFeedModel(
            PlainText::from($title),
            $this->alternateLink($root),
            XmlHelper::childText($root, $this->descriptionElement(), $this->namespaceUri()),
            FeedImageExtractor::fromAtomFeed($root, $this->namespaceUri()),
            $entries,
        ))->withShowArtwork(PodcastArtwork::of($root));
    }

    public function parseEntry(\DOMElement $entry): ?ParsedEntryModel
```

- [ ] **Step 5: StreamedFeedDocument**

Create `backend/src/Service/Parser/Pass/StreamedFeedDocument.php`:

```php
<?php

declare(strict_types=1);

namespace App\Service\Parser\Pass;

use App\Service\Parser\Exception\FeedParseException;
use App\Service\Parser\FeedFormatParser\FeedFormatParserInterface;
use App\Service\Parser\Model\ParsedEntryModel;
use App\Service\Parser\Model\ParsedFeedModel;

/**
 * One feed read as a stream: each entry is expanded into a DOM of its own and parsed on the spot, and the skeleton
 * keeps the rest, so memory follows the largest entry rather than the whole feed. The caller collects libxml errors.
 */
final class StreamedFeedDocument
{
    private const string XMLNS_NAMESPACE = 'http://www.w3.org/2000/xmlns/';
    private const array TEXT_TYPES = [\XMLReader::TEXT, \XMLReader::WHITESPACE, \XMLReader::SIGNIFICANT_WHITESPACE];

    private readonly \DOMDocument $skeleton;
    private \DOMElement $root;
    private ?\DOMElement $openElement = null;

    /** @var list<ParsedEntryModel> */
    private array $entries = [];

    private function __construct(private readonly \XMLReader $reader)
    {
        $this->skeleton = new \DOMDocument();
    }

    public static function open(string $xml): self
    {
        $document = new self(\XMLReader::fromString($xml, null, LIBXML_NONET | LIBXML_COMPACT));
        $document->readToRoot();

        return $document;
    }

    public function root(): \DOMElement
    {
        return $this->root;
    }

    public function parseWith(FeedFormatParserInterface $parser): ParsedFeedModel
    {
        $moved = $this->read();
        while ($moved) {
            $moved = $this->reader->nodeType === \XMLReader::ELEMENT
                ? $this->placeElement($parser)
                : $this->placeOtherNode();
        }

        return $parser->parseFeed($this->skeleton, $this->entries);
    }

    private function readToRoot(): void
    {
        while ($this->read()) {
            if ($this->reader->nodeType === \XMLReader::ELEMENT) {
                $this->root = $this->currentElement();
                $this->skeleton->appendChild($this->root);
                $this->openElement = $this->reader->isEmptyElement ? null : $this->root;

                return;
            }
        }

        throw new FeedParseException('Document is not well-formed XML');
    }

    private function placeElement(FeedFormatParserInterface $parser): bool
    {
        if ($this->openElement === null) {
            throw new FeedParseException('Document is not well-formed XML');
        }

        $element = $this->currentElement();
        $this->openElement->appendChild($element);
        if ($parser->isEntry($element)) {
            $element->remove();
            $this->addEntry($parser->parseEntry($this->expandedEntry()));

            return $this->skipEntry();
        }

        if (!$this->reader->isEmptyElement) {
            $this->openElement = $element;
        }

        return $this->read();
    }

    private function placeOtherNode(): bool
    {
        $type = $this->reader->nodeType;
        if ($type === \XMLReader::END_ELEMENT) {
            $parent = $this->openElement?->parentNode;
            $this->openElement = $parent instanceof \DOMElement ? $parent : null;
        } elseif (in_array($type, self::TEXT_TYPES, true)) {
            $this->openElement?->appendChild($this->skeleton->createTextNode($this->reader->value));
        } elseif ($type === \XMLReader::CDATA) {
            $this->openElement?->appendChild($this->skeleton->createCDATASection($this->reader->value));
        }

        return $this->read();
    }

    private function currentElement(): \DOMElement
    {
        $namespace = $this->reader->namespaceURI;
        $element = $namespace === ''
            ? $this->skeleton->createElement($this->reader->name)
            : $this->skeleton->createElementNS($namespace, $this->reader->name);
        while ($this->reader->moveToNextAttribute()) {
            $this->copyAttribute($element);
        }
        $this->reader->moveToElement();

        return $element;
    }

    private function copyAttribute(\DOMElement $element): void
    {
        $namespace = $this->reader->namespaceURI;
        if ($namespace === self::XMLNS_NAMESPACE) {
            return;
        }
        if ($namespace === '') {
            $element->setAttribute($this->reader->name, $this->reader->value);

            return;
        }
        $element->setAttributeNS($namespace, $this->reader->name, $this->reader->value);
    }

    private function expandedEntry(): \DOMElement
    {
        $entry = $this->reader->expand(new \DOMDocument());
        if (!$entry instanceof \DOMElement) {
            throw new FeedParseException('Document is not well-formed XML');
        }

        return $entry;
    }

    private function addEntry(?ParsedEntryModel $entry): void
    {
        if ($entry !== null) {
            $this->entries[] = $entry;
        }
    }

    private function read(): bool
    {
        return $this->checked($this->reader->read());
    }

    private function skipEntry(): bool
    {
        return $this->checked($this->reader->next());
    }

    /** A reader stops on a fatal error by reporting the end; a recoverable one (an undeclared prefix) reads on. */
    private function checked(bool $moved): bool
    {
        if (!$moved) {
            self::rejectFatalErrors();
        }
        // Feeds never need a DTD. Rejecting any doctype keeps a declared entity from ever being expanded, instead
        // of relying on libxml's amplification limit, which varies by version.
        if ($moved && $this->reader->nodeType === \XMLReader::DOC_TYPE) {
            throw new FeedParseException('Feed documents must not declare a DTD');
        }

        return $moved;
    }

    private static function rejectFatalErrors(): void
    {
        foreach (libxml_get_errors() as $error) {
            if ($error->level === LIBXML_ERR_FATAL) {
                throw new FeedParseException('Document is not well-formed XML');
            }
        }
    }
}
```

- [ ] **Step 6: FeedParser**

Replace `parse()` (keep the two private helpers):

```php
    public function parse(string $xml): ParsedFeedModel
    {
        $feedXml = $this->fromTheDeclaration($this->withoutIllegalControlCharacters($xml));

        // XMLReader::fromString('') throws a ValueError that would 500 the whole refresh run, so an empty body (a
        // BOM-only one included, once stripped) must fail here as a per-feed parse error.
        if ($feedXml === '') {
            throw new FeedParseException('Document is not well-formed XML');
        }

        $previousErrorMode = libxml_use_internal_errors(true);
        libxml_clear_errors();
        try {
            $document = StreamedFeedDocument::open($feedXml);

            return $document->parseWith($this->parserFactory->parserFor($document->root()));
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previousErrorMode);
        }
    }
```

Add `use App\Service\Parser\Pass\StreamedFeedDocument;`.

- [ ] **Step 7: Run the parser and refresh suites**

Run: `cd backend && php bin/phpunit tests/Service/Parser tests/Service/Refresh tests/Service/Ingest`
Expected: PASS with no change to any assertion from Task 1. If a test fails, the streaming code is wrong, not the test. Fix the code. Likely suspects: expanded-entry namespaces (check `$entry->namespaceURI` on an `itunes:` child) and END_ELEMENT bookkeeping around empty elements.

- [ ] **Step 8: Prove the doctype guard still guards**

Temporarily delete the `DOC_TYPE` `if` in `checked()`. Run `php bin/phpunit --filter 'Dtd|Entity' tests/Service/Parser/FeedParserTest.php` and record which tests FAIL: at least `testRejectsDocumentsDeclaringADtd` must fail. Restore the `if` by re-applying the Step 5 text with the Edit tool (no `git checkout --`). Run again and expect PASS.

- [ ] **Step 9: Full gates**

Run in `backend/`: `composer cs`, `composer stan`, `composer md`, `composer tramp`, `composer test:parallel`, `composer infection:diff`, then `docker compose exec php composer test` (MySQL leg). Lint the changed PHP with PhpStorm's `lint_files`. Expected: all green, PHPMD clean on every touched `src` file. Fix any escaped Infection mutant with a test in `FeedParserTest`.

- [ ] **Step 10: Commit**

```bash
git add backend/src/Service/Parser backend/tests
git commit -m "feat(#1452): parse feeds as a stream, expanding each entry into its own DOM"
```

---

### Task 3: Measure the memory win and report it

Steps 1–2 plus the baseline half of Step 3 run between Task 1 and Task 2.

**Files:**
- Create (scratch only, not committed): `$SCRATCH/measure-parse.php`, `$SCRATCH/big-feed.xml`, where `$SCRATCH` is the session scratchpad.

- [ ] **Step 1: Build a synthetic 20 MB podcast feed**

```bash
php -r '
$item = "<item><title>Episode %d</title><link>https://big.example.com/%d</link><guid>big-%d</guid>"
  . "<pubDate>Mon, 06 Oct 2026 10:00:00 +0000</pubDate><enclosure url=\"https://cdn.example.com/%d.mp3\" type=\"audio/mpeg\" length=\"1\"/>"
  . "<itunes:duration>1:00:00</itunes:duration><content:encoded><![CDATA[<p>" . str_repeat("Lorem ipsum dolor sit amet. ", 160) . "</p>]]></content:encoded></item>";
$out = "<?xml version=\"1.0\"?><rss version=\"2.0\" xmlns:itunes=\"http://www.itunes.com/dtds/podcast-1.0.dtd\" xmlns:content=\"http://purl.org/rss/1.0/modules/content/\"><channel><title>Big</title><link>https://big.example.com/</link>";
for ($i = 0; $i < 4400; $i++) { $out .= sprintf($item, $i, $i, $i, $i); }
file_put_contents($argv[1], $out . "</channel></rss>");
' "$SCRATCH/big-feed.xml" && ls -l "$SCRATCH/big-feed.xml"
```

Expected: about 20 MB.

- [ ] **Step 2: The measuring script**

`$SCRATCH/measure-parse.php`:

```php
<?php

declare(strict_types=1);

require $argv[1] . '/vendor/autoload.php';

function highWaterKb(): int
{
    preg_match('/VmHWM:\s+(\d+)/', (string) file_get_contents('/proc/self/status'), $match);

    return (int) $match[1];
}

$body = (string) file_get_contents($argv[2]);
$before = highWaterKb();
$started = microtime(true);
$feed = App\Tests\Support\FeedFormatParsers::feed($body);
printf("entries %d, parse peak +%d MB, %.2f s\n", count($feed->entries), (highWaterKb() - $before) / 1024, microtime(true) - $started);
```

`/proc` exists only in Linux, so run it in the php container. Copy both files into `backend/var/` first, because the container mounts `backend/`:

```bash
cp "$SCRATCH/measure-parse.php" "$SCRATCH/big-feed.xml" backend/var/
docker compose exec php php -d memory_limit=-1 var/measure-parse.php . var/big-feed.xml
```

- [ ] **Step 3: Measure both sides**

The baseline comes first: run Steps 1–2 **after Task 1's commit and before Task 2 touches `src`**, while the parser is still develop's. Record that line. After Task 2, run Step 2 again. Expected: the branch's parse peak is a fraction of the baseline (the issue measured about 4.5× the body on develop).

- [ ] **Step 4: Clean up and report**

`rm backend/var/measure-parse.php backend/var/big-feed.xml`. Post the two measurements as a comment on #1452 (`gh issue comment 1452`), with feed size, entry count, peak and time for develop and for the branch. The PR description repeats them.

---

## Self-Review

- **Spec coverage:** streaming item parse (Task 2), unchanged behaviour (Task 1 pins plus the existing suite), doctype guard (Task 2 Step 8), malformed mid-document (Task 1), memory evidence (Task 3). The cap and `TooLarge` are explicitly out of scope.
- **Placeholder scan:** every code step carries its code.
- **Type consistency:** `isEntry`, `parseEntry`, `parseFeed($skeleton, $entries)`, `StreamedFeedDocument::open`/`root`/`parseWith`, `FeedFormatParsers::all`/`feed` are used identically in all tasks.
- **Known behaviour change, accepted:** an RSS `<item>` nested inside another `<item>` was a second entry under `getElementsByTagName`. It is now part of its outer entry. No real feed nests items.
