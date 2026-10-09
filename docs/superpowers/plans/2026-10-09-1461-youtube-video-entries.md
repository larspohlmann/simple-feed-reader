# #1461 YouTube video entries Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** A YouTube channel subscription opens each video as a playable nocookie player above the video's description, and its discovery candidate is named after the channel instead of "RSS".

**Architecture:** Four general changes, none YouTube-specific in code: (1) the feed parsers fall back to a Media RSS `media:description` when an item has no body; (2) an ingest platform rule prepends the standard embed link when the entry's own URL is a video an `EmbedProvider` recognises; (3) the reader skips fetching a page an `EmbedProvider` recognises and answers a new `player_page` failure, which the SPA treats as a silent fallback to the feed body; (4) discovery replaces a generic feed-link label ("RSS", "Atom feed") with the page's name.

**Tech Stack:** Symfony 7.4 / PHP 8.4 (`backend/`), Angular 20 + Jest (`frontend/`).

**Spec:** GitHub issue #1461 (the design agreed in chat; amended in chat to include items 3 and 4).

## Global Constraints

- Branch `feature/1461-youtube-video-entries` off `develop`; one PR, body `Closes #1461`.
- Commit format `type(#1461): …`; no attribution lines.
- CLAUDE.md Clean Code rules apply: `final readonly`, no abbreviations, no comments unless they clear the high bar, static-only helpers in `Support/`, every touched `src` file PHPMD-clean.
- Gates before the PR: `composer cs`, `composer stan`, `composer md`, `composer tramp`, `php bin/phpunit` (SQLite) and `docker compose exec php composer test` (MySQL), `composer infection:diff`; frontend `docker compose exec -T frontend npm run check` (Jest inside the container, one run at a time).
- After backend edits in the Docker stack: `docker compose restart php worker` before verifying in the app.

---

### Task 1: Media RSS description becomes the body of a body-less item

**Files:**
- Create: `backend/src/Service/Text/Support/LinkedPlainText.php`
- Create: `backend/src/Service/Parser/Support/MediaDescription.php`
- Modify: `backend/src/Service/Parser/Support/XmlHelper.php` (add `MEDIA_RSS_NAMESPACE`)
- Modify: `backend/src/Service/Parser/ItemMediaExtractor.php`, `ItemImageExtractor.php`, `Pass/FeedMediaNode.php` (drop their private `MEDIA_NS`, use `XmlHelper::MEDIA_RSS_NAMESPACE` — a fourth copy would be the third-occurrence refactor)
- Modify: `backend/src/Service/Parser/FeedFormatParser/AbstractAtomParser.php` (`parseEntry`)
- Modify: `backend/src/Service/Parser/FeedFormatParser/Rss2Parser.php` (`parseEntry`)
- Create: `backend/tests/Fixtures/youtube/channel-videos.xml` (the real feed from `https://www.youtube.com/feeds/videos.xml?channel_id=UCgMJGv4cQl8-q71AyFeFmtg`, trimmed to two `<entry>`s, descriptions kept verbatim)
- Test: `backend/tests/Service/Text/Support/LinkedPlainTextTest.php`, `backend/tests/Service/Parser/Support/MediaDescriptionTest.php`, `backend/tests/Service/Parser/FeedFormatParser/YouTubeFeedTest.php`

**Interfaces:**
- Produces: `LinkedPlainText::asHtml(string $text): ?string` — escaped HTML; blank-line-separated blocks become `<p>`, single newlines `<br>`, bare `http(s)://` URLs `<a href>`; null for blank text.
- Produces: `MediaDescription::html(\DOMElement $item): ?string` — the item's own `media:description`, else the first `media:group`'s; `type="html"` returned as-is, plain (default) through `LinkedPlainText`; null when absent or blank.
- Produces: `XmlHelper::MEDIA_RSS_NAMESPACE = 'http://search.yahoo.com/mrss/'`.

- [ ] **Step 1: Write the failing `LinkedPlainTextTest`**

```php
<?php

declare(strict_types=1);

namespace App\Tests\Service\Text\Support;

use App\Service\Text\Support\LinkedPlainText;
use PHPUnit\Framework\TestCase;

final class LinkedPlainTextTest extends TestCase
{
    public function testBlankLinesSplitParagraphsAndSingleBreaksBecomeBr(): void
    {
        self::assertSame(
            '<p>First line<br>second line</p><p>Next paragraph</p>',
            LinkedPlainText::asHtml("First line\r\nsecond line\n\n  \nNext paragraph\n"),
        );
    }

    public function testMarkupIsEscapedNotInterpreted(): void
    {
        self::assertSame('<p>&lt;b&gt;Tom &amp; Jerry&lt;/b&gt; &quot;quoted&quot;</p>', LinkedPlainText::asHtml('<b>Tom & Jerry</b> "quoted"'));
    }

    public function testBareUrlsBecomeLinksWithoutTrailingPunctuation(): void
    {
        self::assertSame(
            '<p>Shop: <a href="https://example.com/a?b=1&amp;c=2">https://example.com/a?b=1&amp;c=2</a>. '
            . '(<a href="http://x.example/">http://x.example/</a>)</p>',
            LinkedPlainText::asHtml('Shop: https://example.com/a?b=1&c=2. (http://x.example/)'),
        );
    }

    public function testBlankTextIsNull(): void
    {
        self::assertNull(LinkedPlainText::asHtml(" \n\n "));
    }
}
```

- [ ] **Step 2: Run it — expect FAIL (class not found)**

Run: `cd backend && php bin/phpunit tests/Service/Text/Support/LinkedPlainTextTest.php`

- [ ] **Step 3: Implement `LinkedPlainText`**

```php
<?php

declare(strict_types=1);

namespace App\Service\Text\Support;

/** Text known to carry no markup, as HTML: escaped, paragraphed, its bare URLs linked. */
final class LinkedPlainText
{
    private const string URL_PATTERN = '#(https?://[^\s<>"]+?)(?=[.,;:!?)\]]*(?:\s|$))#i';

    public static function asHtml(string $text): ?string
    {
        $normalised = trim(str_replace(["\r\n", "\r"], "\n", $text));
        if ($normalised === '') {
            return null;
        }

        $paragraphs = preg_split('/\n\s*\n/', $normalised) ?: [];

        return implode('', array_map(self::paragraph(...), $paragraphs));
    }

    private static function paragraph(string $paragraph): string
    {
        $lines = array_map(static fn (string $line): string => self::linked(trim($line)), explode("\n", $paragraph));

        return '<p>' . implode('<br>', $lines) . '</p>';
    }

    private static function linked(string $line): string
    {
        $parts = preg_split(self::URL_PATTERN, $line, -1, \PREG_SPLIT_DELIM_CAPTURE) ?: [$line];
        $html = '';
        foreach ($parts as $index => $part) {
            $escaped = htmlspecialchars($part, \ENT_QUOTES | \ENT_HTML5);
            $html .= $index % 2 === 1 ? '<a href="' . $escaped . '">' . $escaped . '</a>' : $escaped;
        }

        return $html;
    }

    private function __construct()
    {
    }
}
```

Note: `htmlspecialchars` with `ENT_HTML5` encodes `"` as `&quot;` — the test expects exactly that. If the URL regex leaves trailing punctuation inside the link, fix the lookahead, not the test.

- [ ] **Step 4: Run it — expect PASS**

- [ ] **Step 5: Write the failing `MediaDescriptionTest`**

```php
<?php

declare(strict_types=1);

namespace App\Tests\Service\Parser\Support;

use App\Service\Parser\Support\MediaDescription;
use PHPUnit\Framework\TestCase;

final class MediaDescriptionTest extends TestCase
{
    private static function item(string $children): \DOMElement
    {
        $document = new \DOMDocument();
        $document->loadXML('<item xmlns:media="http://search.yahoo.com/mrss/">' . $children . '</item>');
        $item = $document->documentElement;
        self::assertInstanceOf(\DOMElement::class, $item);

        return $item;
    }

    public function testAGroupDescriptionIsPlainTextByDefault(): void
    {
        $item = self::item('<media:group><media:description>A &lt;b&gt;
see https://e.example/x</media:description></media:group>');

        self::assertSame(
            '<p>A &lt;b&gt;<br>see <a href="https://e.example/x">https://e.example/x</a></p>',
            MediaDescription::html($item),
        );
    }

    public function testTheItemsOwnDescriptionWinsOverTheGroups(): void
    {
        $item = self::item('<media:description>Own</media:description>'
            . '<media:group><media:description>Group</media:description></media:group>');

        self::assertSame('<p>Own</p>', MediaDescription::html($item));
    }

    public function testAnHtmlTypedDescriptionIsKeptAsMarkup(): void
    {
        $item = self::item('<media:description type="html">&lt;p&gt;Hi&lt;/p&gt;</media:description>');

        self::assertSame('<p>Hi</p>', MediaDescription::html($item));
    }

    public function testNoOrBlankDescriptionIsNull(): void
    {
        self::assertNull(MediaDescription::html(self::item('<title>t</title>')));
        self::assertNull(MediaDescription::html(self::item('<media:group><media:description> </media:description></media:group>')));
    }
}
```

- [ ] **Step 6: Run it — expect FAIL**

- [ ] **Step 7: Add `XmlHelper::MEDIA_RSS_NAMESPACE`, switch the three `MEDIA_NS` copies to it, implement `MediaDescription`**

In `XmlHelper` next to `DUBLIN_CORE_NAMESPACE`:

```php
    public const string MEDIA_RSS_NAMESPACE = 'http://search.yahoo.com/mrss/';
```

Replace `self::MEDIA_NS` with `XmlHelper::MEDIA_RSS_NAMESPACE` in `ItemMediaExtractor`, `ItemImageExtractor` and `Pass/FeedMediaNode`, and delete their `private const string MEDIA_NS`.

```php
<?php

declare(strict_types=1);

namespace App\Service\Parser\Support;

use App\Service\Text\Support\LinkedPlainText;

/** A Media RSS item's description as a body: the item's own, else its group's. */
final class MediaDescription
{
    public static function html(\DOMElement $item): ?string
    {
        $description = self::descriptionOf($item) ?? self::groupDescription($item);
        if ($description === null) {
            return null;
        }

        $text = trim($description->textContent);
        if ($text === '') {
            return null;
        }

        return $description->getAttribute('type') === 'html' ? $text : LinkedPlainText::asHtml($text);
    }

    private static function groupDescription(\DOMElement $item): ?\DOMElement
    {
        $group = XmlHelper::childElement($item, 'group', XmlHelper::MEDIA_RSS_NAMESPACE);

        return $group === null ? null : self::descriptionOf($group);
    }

    private static function descriptionOf(\DOMElement $parent): ?\DOMElement
    {
        return XmlHelper::childElement($parent, 'description', XmlHelper::MEDIA_RSS_NAMESPACE);
    }

    private function __construct()
    {
    }
}
```

- [ ] **Step 8: Run `MediaDescriptionTest` and the existing parser suite — expect PASS**

Run: `php bin/phpunit tests/Service/Parser tests/Service/Text`

- [ ] **Step 9: Create the fixture and write the failing `YouTubeFeedTest`**

Fetch the real feed, keep the `<feed>` header and the first two `<entry>` elements verbatim:

```bash
curl -s 'https://www.youtube.com/feeds/videos.xml?channel_id=UCgMJGv4cQl8-q71AyFeFmtg' > /tmp/yt-feed.xml
```

Save as `backend/tests/Fixtures/youtube/channel-videos.xml`. Then:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Service\Parser\FeedFormatParser;

use App\Tests\Support\FeedFormatParsers;
use App\Tests\Support\ReadsFixtures;
use PHPUnit\Framework\TestCase;

final class YouTubeFeedTest extends TestCase
{
    use ReadsFixtures;

    public function testAVideoEntryCarriesItsDescriptionAsTheBody(): void
    {
        $entry = FeedFormatParsers::feed($this->fixture('youtube/channel-videos.xml'))->entries[0];

        self::assertNotNull($entry->contentHtml);
        self::assertStringStartsWith('<p>', $entry->contentHtml);
        self::assertStringContainsString('<a href="https://screencrushmerch.com/pages/subscribe">', $entry->contentHtml);
    }
}
```

(Adjust the asserted link to one that appears in the first fixture entry's description.)

Add to `Atom10ParserTest` an entry whose `<content>` exists alongside a `media:group` description and assert `contentHtml` is the `<content>` (the description is a fallback only), and one with a `<summary>` but no content asserting `contentHtml` stays null. Add to `Rss2ParserTest` an item with no `<description>`/`content:encoded` and a `media:description` asserting `contentHtml === '<p>…</p>'`, and one with a `<description>` asserting it wins.

- [ ] **Step 10: Run them — expect FAIL on the description assertions**

- [ ] **Step 11: Wire the fallback into both parsers**

`AbstractAtomParser::parseEntry`:

```php
        $summary = XmlHelper::childText($entry, 'summary', $this->namespaceUri());
        …
            summary: $summary,
            contentHtml: FeedBodyHtml::of($contentHtml) ?? ($summary === null ? MediaDescription::html($entry) : null),
```

`Rss2Parser::parseEntry`:

```php
            contentHtml: FeedBodyHtml::of($contentEncoded ?? $description) ?? MediaDescription::html($entry),
```

- [ ] **Step 12: Run `php bin/phpunit tests/Service/Parser tests/Service/Text` — expect PASS**

- [ ] **Step 13: Commit**

```bash
git add backend/src/Service/Text/Support/LinkedPlainText.php backend/src/Service/Parser backend/tests/Service/Text backend/tests/Service/Parser backend/tests/Fixtures/youtube
git commit -m "feat(#1461): a body-less feed item reads its Media RSS description"
```

---

### Task 2: An entry that is a video page gets the embed player link

**Files:**
- Modify: `backend/src/Service/Parser/Model/ParsedEntryModel.php` (add `withContentHtml`)
- Create: `backend/src/Service/Ingest/PlatformEntryRule/VideoPageEntryRule.php`
- Test: `backend/tests/Service/Ingest/PlatformEntryRule/VideoPageEntryRuleTest.php`, extend `backend/tests/Service/Ingest/PlatformEntryRulesWiringTest.php`

**Interfaces:**
- Consumes: `App\Service\Reader\Media\EmbedProviders::resolve(string $url): ?EmbedTargetModel` (`url`, `posterUrl`, `label`); `App\Service\Reader\Media\MediaMarkup::embedLink(HTMLDocument, EmbedTargetModel): Element`.
- Produces: `ParsedEntryModel::withContentHtml(?string $contentHtml): self`; `VideoPageEntryRule implements PlatformEntryRuleInterface` (auto-tagged `app.platform_entry_rule` by `services.yaml` `_instanceof`).

Rule semantics: `supports` is true when the entry URL resolves to an embed target, the entry has no playable attachment (`$entry->media->mediaBundle?->isEpisode()` — a SoundCloud/podcast enclosure already plays via Listen, #1434), and the body does not already carry that player link (an `<a href>` equal to the target URL, `#fragment` ignored — only such a link becomes a player; iframes are stripped by the sanitizer and watch links are not upgraded). `apply` prepends `MediaMarkup::embedLink` serialised, to the body (or as the body when null).

- [ ] **Step 1: Write the failing `VideoPageEntryRuleTest`**

```php
<?php

declare(strict_types=1);

namespace App\Tests\Service\Ingest\PlatformEntryRule;

use App\Enum\AttachmentKind;
use App\Service\Ingest\PlatformEntryRule\VideoPageEntryRule;
use App\Service\Parser\Model\ParsedAttachmentModel;
use App\Service\Parser\Model\ParsedEntryMediaModel;
use App\Service\Parser\Model\ParsedEntryModel;
use App\Service\Parser\Model\ParsedMediaBundleModel;
use App\Service\Reader\Media\EmbedProvider\VimeoEmbedProvider;
use App\Service\Reader\Media\EmbedProvider\YouTubeEmbedProvider;
use App\Service\Reader\Media\EmbedProviders;
use App\Service\Reader\Media\MediaMarkup;
use PHPUnit\Framework\TestCase;

final class VideoPageEntryRuleTest extends TestCase
{
    private const string WATCH = 'https://www.youtube.com/watch?v=Xic3faS00Qs';
    private const string PLAYER_LINK = '<a href="https://www.youtube-nocookie.com/embed/Xic3faS00Qs">'
        . '<img src="https://i.ytimg.com/vi/Xic3faS00Qs/hqdefault.jpg" alt="Watch on YouTube"></a>';

    private static function rule(): VideoPageEntryRule
    {
        return new VideoPageEntryRule(
            new EmbedProviders([new YouTubeEmbedProvider(), new VimeoEmbedProvider()]),
            new MediaMarkup(),
        );
    }

    private static function entry(?string $url, ?string $contentHtml, ?ParsedMediaBundleModel $bundle = null): ParsedEntryModel
    {
        return new ParsedEntryModel('yt:video:1', $url, 'T', null, null, $contentHtml, null, new ParsedEntryMediaModel(null, $bundle));
    }

    public function testAVideoPagesBodyLeadsWithItsPlayerLink(): void
    {
        $entry = self::entry(self::WATCH, '<p>Description</p>');

        self::assertTrue(self::rule()->supports($entry));
        self::assertSame(self::PLAYER_LINK . '<p>Description</p>', self::rule()->apply($entry)->contentHtml);
    }

    public function testABodylessVideoPageGetsThePlayerLinkAsItsBody(): void
    {
        self::assertSame(self::PLAYER_LINK, self::rule()->apply(self::entry(self::WATCH, null))->contentHtml);
    }

    public function testAnArticleOrAMissingUrlIsNotAVideoPage(): void
    {
        self::assertFalse(self::rule()->supports(self::entry('https://example.com/post', '<p>x</p>')));
        self::assertFalse(self::rule()->supports(self::entry(null, '<p>x</p>')));
    }

    public function testABodyThatAlreadyEmbedsTheVideoIsLeftAlone(): void
    {
        $iframe = '<iframe src="https://www.youtube.com/embed/Xic3faS00Qs"></iframe>';

        self::assertFalse(self::rule()->supports(self::entry(self::WATCH, $iframe)));
        self::assertFalse(self::rule()->supports(self::entry(self::WATCH, self::PLAYER_LINK)));
    }

    public function testABodyEmbeddingAnotherVideoStillGetsItsOwn(): void
    {
        self::assertTrue(self::rule()->supports(
            self::entry(self::WATCH, '<a href="https://youtu.be/aaaaaaaaaaa">other</a>'),
        ));
    }

    public function testAnEpisodeThatAlreadyPlaysItsEnclosureGetsNoPlayer(): void
    {
        $bundle = new ParsedMediaBundleModel([], [/* one playable audio ParsedAttachmentModel — build it as ItemMediaExtractorTest does */]);

        self::assertFalse(self::rule()->supports(self::entry(self::WATCH, null, $bundle)));
    }
}
```

The implementer reads `ParsedAttachmentModel`'s constructor and `ItemMediaExtractorTest` to build one playable audio attachment for the last test (and fixes the imports to the real enum), so the test exercises `isEpisode() === true`. Confirm the exact `MediaMarkup` serialisation (attribute order, `<img …>` without a self-closing slash) by running the test once and comparing; the expected constant follows the real output of `saveHtml`, as long as it is `<a href=embed><img src=poster alt=label></a>`.

- [ ] **Step 2: Run it — expect FAIL (class not found)**

Run: `php bin/phpunit tests/Service/Ingest/PlatformEntryRule/VideoPageEntryRuleTest.php`

- [ ] **Step 3: Add `ParsedEntryModel::withContentHtml`**

```php
    public function withContentHtml(?string $contentHtml): self
    {
        return new self(
            guid: $this->guid,
            url: $this->url,
            title: $this->title,
            author: $this->author,
            summary: $this->summary,
            contentHtml: $contentHtml,
            publishedAt: $this->publishedAt,
            media: $this->media,
            categories: $this->categories,
            discussion: $this->discussion,
            authorUrl: $this->authorUrl,
        );
    }
```

- [ ] **Step 4: Implement `VideoPageEntryRule`**

```php
<?php

declare(strict_types=1);

namespace App\Service\Ingest\PlatformEntryRule;

use App\Service\Html\Support\HtmlDocumentParser;
use App\Service\Parser\Model\ParsedEntryModel;
use App\Service\Reader\Media\EmbedProviders;
use App\Service\Reader\Media\MediaMarkup;
use App\Service\Reader\Media\Model\EmbedTargetModel;
use Dom\HTMLDocument;

/** An entry whose link is a video page leads its body with that video's player link; the reader upgrades it. */
final readonly class VideoPageEntryRule implements PlatformEntryRuleInterface
{
    private const string EMBEDDING_ELEMENTS = 'a[href], iframe[src], embed[src]';

    public function __construct(
        private EmbedProviders $embeds,
        private MediaMarkup $markup,
    ) {
    }

    public function supports(ParsedEntryModel $entry): bool
    {
        $target = $this->target($entry);

        return $target !== null
            && !($entry->media->mediaBundle?->isEpisode() ?? false)
            && !$this->bodyEmbeds($entry->contentHtml ?? '', $target);
    }

    public function apply(ParsedEntryModel $entry): ParsedEntryModel
    {
        $target = $this->target($entry);
        if ($target === null) {
            return $entry;
        }

        return $entry->withContentHtml($this->playerLink($target) . ($entry->contentHtml ?? ''));
    }

    private function target(ParsedEntryModel $entry): ?EmbedTargetModel
    {
        return $entry->url === null ? null : $this->embeds->resolve($entry->url);
    }

    private function bodyEmbeds(string $body, EmbedTargetModel $target): bool
    {
        if (trim($body) === '') {
            return false;
        }

        foreach (HtmlDocumentParser::parseFragment($body)->querySelectorAll(self::EMBEDDING_ELEMENTS) as $element) {
            $source = $element->getAttribute('href') ?? $element->getAttribute('src') ?? '';
            if ($this->embeds->resolve($source)?->url === $target->url) {
                return true;
            }
        }

        return false;
    }

    private function playerLink(EmbedTargetModel $target): string
    {
        $document = HTMLDocument::createEmpty();

        return $document->saveHtml($this->markup->embedLink($document, $target));
    }
}
```

- [ ] **Step 5: Run the rule test — expect PASS**

- [ ] **Step 6: Extend `PlatformEntryRulesWiringTest`** with a test that ingests one `ParsedEntryModel` with URL `https://www.youtube.com/watch?v=Xic3faS00Qs` and body `<p>Description</p>` through the container's `EntryIngestor` (same setup as the Reddit test in that file) and asserts the stored `Entry::getContentHtml()` contains `href="https://www.youtube-nocookie.com/embed/Xic3faS00Qs"` and `<p>Description</p>` — proving the tag wiring and that `EntrySanitizer` keeps the link and poster.

- [ ] **Step 7: Run `php bin/phpunit tests/Service/Ingest` — expect PASS**

- [ ] **Step 8: Commit**

```bash
git add backend/src/Service/Parser/Model/ParsedEntryModel.php backend/src/Service/Ingest/PlatformEntryRule/VideoPageEntryRule.php backend/tests/Service/Ingest
git commit -m "feat(#1461): an entry that is a video page leads with its player"
```

---

### Task 3: The reader skips a video page and the SPA falls back silently

**Files:**
- Modify: `backend/src/Service/Reader/Model/ExtractionFailure.php` (add `PlayerPage = 'player_page'`)
- Create: `backend/src/Service/Reader/ArticleExtractor/PlayerPageExtractor.php` (decorator)
- Test: `backend/tests/Service/Reader/ArticleExtractor/PlayerPageExtractorTest.php`
- Modify: `frontend/src/app/reader/models.ts:439` (add `'player_page'` to the reason union)
- Modify: `frontend/src/app/reader/article/content/article-source.service.ts` (`NO_ARTICLE_REASONS` gains `'player_page'`)
- Modify: `frontend/src/app/reader/article/reader-view/reader-view.component.ts` (`fallbackNoticeShown` is false for `'player_page'`)
- Test: `frontend/src/app/reader/article/reader-view/reader-view.component.spec.ts`

**Interfaces:**
- Consumes: `EmbedProviders::resolve`, `ArticleExtractorInterface::extract(string $url, EntryHintsModel $hints = new EntryHintsModel()): ExtractionResultModel`, `ExtractionResultModel::failed(?string $url, ExtractionFailure $failure, ?string $detail = null)` (check the real signature).
- Produces: wire value `reason: 'player_page'`.

- [ ] **Step 1: Write the failing backend test**

```php
<?php

declare(strict_types=1);

namespace App\Tests\Service\Reader\ArticleExtractor;

use App\Service\Reader\ArticleExtractor\ArticleExtractorInterface;
use App\Service\Reader\ArticleExtractor\PlayerPageExtractor;
use App\Service\Reader\Media\EmbedProvider\YouTubeEmbedProvider;
use App\Service\Reader\Media\EmbedProviders;
use App\Service\Reader\Model\EntryHintsModel;
use App\Service\Reader\Model\ExtractionFailure;
use App\Service\Reader\Model\ExtractionResultModel;
use PHPUnit\Framework\TestCase;

final class PlayerPageExtractorTest extends TestCase
{
    public function testAVideoPageIsNeverFetched(): void
    {
        $inner = $this->createMock(ArticleExtractorInterface::class);
        $inner->expects($this->never())->method('extract');

        $result = (new PlayerPageExtractor($inner, new EmbedProviders([new YouTubeEmbedProvider()])))
            ->extract('https://www.youtube.com/watch?v=Xic3faS00Qs');

        self::assertFalse($result->ok);
        self::assertSame(ExtractionFailure::PlayerPage, $result->failure);
    }

    public function testAnyOtherPageIsExtracted(): void
    {
        $extracted = ExtractionResultModel::failed('https://example.com/a', ExtractionFailure::Empty);
        $hints = new EntryHintsModel(title: 'T');
        $inner = $this->createMock(ArticleExtractorInterface::class);
        $inner->expects($this->once())->method('extract')->with('https://example.com/a', $hints)->willReturn($extracted);

        $result = (new PlayerPageExtractor($inner, new EmbedProviders([new YouTubeEmbedProvider()])))
            ->extract('https://example.com/a', $hints);

        self::assertSame($extracted, $result);
    }
}
```

(Match `$result->failure` to the real property name on `ExtractionResultModel`.)

- [ ] **Step 2: Run it — expect FAIL**

- [ ] **Step 3: Add the enum case and the decorator**

```php
    /** The page is a video or audio player; the feed body carries it. */
    case PlayerPage = 'player_page';
```

```php
<?php

declare(strict_types=1);

namespace App\Service\Reader\ArticleExtractor;

use App\Service\Reader\Media\EmbedProviders;
use App\Service\Reader\Model\EntryHintsModel;
use App\Service\Reader\Model\ExtractionFailure;
use App\Service\Reader\Model\ExtractionResultModel;
use Symfony\Component\DependencyInjection\Attribute\AsDecorator;
use Symfony\Component\DependencyInjection\Attribute\AutowireDecorated;

#[AsDecorator(ArticleExtractor::class)]
final readonly class PlayerPageExtractor implements ArticleExtractorInterface
{
    public function __construct(
        #[AutowireDecorated]
        private ArticleExtractorInterface $inner,
        private EmbedProviders $embeds,
    ) {
    }

    public function extract(string $url, EntryHintsModel $hints = new EntryHintsModel()): ExtractionResultModel
    {
        if ($this->embeds->resolve($url) !== null) {
            return ExtractionResultModel::failed($url, ExtractionFailure::PlayerPage);
        }

        return $this->inner->extract($url, $hints);
    }
}
```

Verify the wiring: `docker compose exec -T php bin/console debug:container 'App\Service\Reader\ArticleExtractor\ArticleExtractorInterface'` resolves to `PlayerPageExtractor`. If `ServiceRoleRule` objects to the decorator's folder, move it where the rule says.

- [ ] **Step 4: Run `php bin/phpunit tests/Service/Reader/ArticleExtractor` — expect PASS**

- [ ] **Step 5: Frontend failing test** — in `reader-view.component.spec.ts`, beside the existing `it.each(['empty', 'unextractable', 'mismatch'])` blocks (lines ~985 and ~1037): add `'player_page'` to the no-Retry list, and add a test that a `failedContent({ reason: 'player_page' })` renders neither `.reader-fallback` nor `.reader-fallback-quiet` while the feed body is shown.

- [ ] **Step 6: Run** `docker compose exec -T frontend npx jest src/app/reader/article/reader-view` — expect FAIL.

- [ ] **Step 7: Implement** — add `'player_page'` to the `reason` union in `models.ts` and to `NO_ARTICLE_REASONS`; in `fallbackNoticeShown` add `&& this.source.failureReason() !== 'player_page'`.

- [ ] **Step 8: Run the spec again — expect PASS**, then `docker compose exec -T frontend npm run check`.

- [ ] **Step 9: Commit**

```bash
git add backend/src/Service/Reader backend/tests/Service/Reader/ArticleExtractor frontend/src/app/reader
git commit -m "feat(#1461): the reader leaves a video page to the feed body"
```

---

### Task 4: A generic feed-link label gives way to the page's name

**Files:**
- Modify: `backend/src/Service/Discovery/FeedLinkScanner.php`
- Test: `backend/tests/Service/Discovery/FeedLinkScannerTest.php`

**Interfaces:**
- Produces: candidate `title` = page name (`og:title`, else `<title>`, normalised and capped like other labels) when the link's own label is a bare format word; unchanged otherwise. Applies to both the strict and the fuzzy pass.

Generic label pattern (case-insensitive, whole label): `rss`, `atom`, `feed`, `rss feed`, `atom feed`, `rss 2.0`, `rss2`, `atom 1.0`, `subscribe`… — implement as `#^(?:(?:rss|atom)\s*\d*(?:\.\d+)?(?:\s+feed)?|feed|news\s*feed)$#i`; do not add words the tests do not cover.

- [ ] **Step 1: Write failing tests**

```php
    public function testAGenericLinkLabelTakesThePagesName(): void
    {
        $html = /** @lang TEXT */ <<<'HTML'
            <!doctype html><html><head><title>ScreenCrush - YouTube</title>
              <meta property="og:title" content="ScreenCrush">
              <link rel="alternate" type="application/rss+xml" title="RSS" href="/feeds/videos.xml?channel_id=UC1">
              <link rel="alternate" type="application/atom+xml" title="Atom Feed" href="/atom">
            </head><body></body></html>
            HTML;

        $titles = array_map(static fn ($candidate) => $candidate->title, $this->scanner->scan($html, 'https://example.com/'));

        self::assertSame(['ScreenCrush', 'ScreenCrush'], $titles);
    }

    public function testWithoutOgTitleTheDocumentTitleNamesIt(): void
    {
        $html = '<html><head><title>Example Blog</title>'
            . '<link rel="alternate" type="application/rss+xml" title="RSS 2.0" href="/rss"></head></html>';

        self::assertSame('Example Blog', $this->scanner->scan($html, 'https://example.com/')[0]->title);
    }

    public function testASpecificLabelIsKept(): void
    {
        $html = '<html><head><meta property="og:title" content="Site">'
            . '<link rel="alternate" type="application/rss+xml" title="Comments Feed" href="/c"></head></html>';

        self::assertSame('Comments Feed', $this->scanner->scan($html, 'https://example.com/')[0]->title);
    }

    public function testAGenericLabelOnANamelessPageStays(): void
    {
        $html = '<html><head><link rel="alternate" type="application/rss+xml" title="RSS" href="/rss"></head></html>';

        self::assertSame('RSS', $this->scanner->scan($html, 'https://example.com/')[0]->title);
    }
```

- [ ] **Step 2: Run — expect FAIL**

Run: `php bin/phpunit tests/Service/Discovery/FeedLinkScannerTest.php`

- [ ] **Step 3: Implement** — compute the page name once in `scan()` and carry it with the document into both passes without lengthening every signature (phptramp: if the name would be forwarded through ≥3 methods, hold `$document` + page name in a small `Pass/` object or compute the label in one place: change `label(Element $link)` to `label(Element $link, ?string $pageName)` only if tramp stays clean; otherwise a `Pass/ScannedPage` with `document`, `pageUrls`, `pageName`). Page name: `TextNormalizer::normalize(og:title content)`, else `<title>` text, `mb_substr(…, 0, MAX_LABEL_CHARS)`, null when empty. A generic label is replaced by the page name when one exists.

- [ ] **Step 4: Run the Discovery suite — expect PASS**

Run: `php bin/phpunit tests/Service/Discovery`

- [ ] **Step 5: Commit**

```bash
git add backend/src/Service/Discovery backend/tests/Service/Discovery
git commit -m "feat(#1461): a feed link labelled only 'RSS' is named after its page"
```

---

### Task 5: Gates, live verification, PR

- [ ] **Step 1: Backend gates** — `cd backend && composer cs && composer stan && composer md && composer tramp && composer test:parallel`; then `docker compose exec php composer test`; then `composer infection:diff` (stage new files first — infection:diff ignores untracked files). Fix every finding in touched files.
- [ ] **Step 2: PhpStorm inspections** on changed PHP (`mcp__phpstorm__lint_files`); block on ERROR/WARNING.
- [ ] **Step 3: Live check** — `docker compose restart php worker`; run the discovery probe for `https://www.youtube.com/@ScreenCrush` (title is now `ScreenCrush`); subscribe a second channel (or a fresh test subscription) and confirm via the reader endpoint that an entry returns `reason: player_page` and its stored `content_html` starts with the nocookie embed link followed by the description; open it in the SPA (prod build or :4200) and confirm a player and the description, no fallback notice, no duplicate poster.
- [ ] **Step 4: Scan today's dev log** (`ls -t backend/var/log/dev-*.log | head -1 | xargs tail -n 200 | jq -c 'select(.level >= 300)'`).
- [ ] **Step 5: Push and open the PR** into `develop`, body ends with `Closes #1461`; note that stored YouTube entries from before the change stay body-less (ingest does not rewrite existing entries).

---

### Task 6: YouTube Shorts play in a portrait box

Added on request after Tasks 1–4. A Short's entry URL is `https://www.youtube.com/shorts/<id>`, which `YouTubeEmbedProvider` does not read, so Shorts get no player; and a Short is 9:16, which the 16:9 `.reader-embed` box would pillarbox.

**Files:**
- Modify: `backend/src/Service/Reader/Media/EmbedProvider/YouTubeEmbedProvider.php`
- Test: `backend/tests/Service/Reader/Media/EmbedProvider/YouTubeEmbedProviderTest.php`
- Regenerate: `frontend/src/app/reader/embed-frame-allowlist.generated.json` (`docker compose exec -T php bin/console app:embed:dump-frame-allowlist` — read the command for its output target; `EmbedFrameAllowlistTest` pins that the committed file matches)
- Modify: `frontend/src/app/reader/media-embeds.ts`, `frontend/src/app/reader/article/reader-view/reader-view.component.content.scss`
- Test: `frontend/src/app/reader/media-embeds.spec.ts`

**Interfaces:**
- Produces: `YouTubeEmbedProvider::normalize('https://www.youtube.com/shorts/<id>')` → `https://www.youtube-nocookie.com/embed/<id>#shorts`; every other YouTube spelling unchanged (`…/embed/<id>`). `poster()` unchanged (`i.ytimg.com/vi/<id>/hqdefault.jpg`). `framePattern()` → `^https://www\.youtube-nocookie\.com/embed/[A-Za-z0-9_-]{11}(?:#shorts)?$`.
- Produces: SPA box class `reader-embed reader-embed--portrait` for an allowed URL ending in `#shorts`; the iframe `src` keeps the fragment (it never reaches YouTube).

Ruling: the Short marker rides in a URL fragment because the URL is the only thing that survives `EntrySanitizer` (class is allowed only on audio/figure) and the SPA's article cache — the same way the Spotify tall box is keyed on its URL path. A fragment is never sent to YouTube, so the player request is unchanged.

- [ ] **Step 1: Failing backend tests** in `YouTubeEmbedProviderTest`: `https://www.youtube.com/shorts/GhUuOxrCato` and `https://youtube.com/shorts/GhUuOxrCato?feature=share` match, normalise to `https://www.youtube-nocookie.com/embed/GhUuOxrCato#shorts` and give poster `https://i.ytimg.com/vi/GhUuOxrCato/hqdefault.jpg`; `https://www.youtube.com/shorts/` and `/shorts/tooShort` do not match; the normalised Short URL matches `framePattern()`, and a `#other` fragment does not. Existing cases stay green.
- [ ] **Step 2: Run** `php bin/phpunit tests/Service/Reader/Media` — expect FAIL.
- [ ] **Step 3: Implement** — a Short is recognised from the path `^/shorts/(<ID>)/?$`; `normalize` appends `#shorts` for it; `idFromPath` keeps reading `/embed/`, `/v/`, bare id; `framePattern` gains the optional fragment. Keep methods short; no flag parameters (a `shortsId()` and `videoId()` split, or a small private match, not a bool).
- [ ] **Step 4: Run** `php bin/phpunit tests/Service/Reader tests/Service/Ingest` — expect PASS (the Task 2 rule and Task 3 decorator now cover Shorts for free). Regenerate the allow-list JSON and run `EmbedFrameAllowlistTest`.
- [ ] **Step 5: Frontend failing test** in `media-embeds.spec.ts`: an anchor to `https://www.youtube-nocookie.com/embed/GhUuOxrCato#shorts` is replaced by a `div.reader-embed.reader-embed--portrait` whose iframe `src` is that URL; a plain `…/embed/<id>` stays `reader-embed` without the modifier.
- [ ] **Step 6: Implement** — in `media-embeds.ts` pick the box class from the URL as the Spotify branch does (`YOUTUBE_SHORT = /^https:\/\/www\.youtube-nocookie\.com\/embed\/[A-Za-z0-9_-]{11}#shorts$/` → `reader-embed reader-embed--portrait`). In the content SCSS add beside `.reader-embed--tall`:

```scss
/* A YouTube Short is portrait: a 9:16 box, centred and capped so it fits a desktop screen. */
.content ::ng-deep .reader-embed--portrait {
  aspect-ratio: 9 / 16;
  width: min(100%, 22.5rem);
  margin-inline: auto;
}
```

- [ ] **Step 7: Run** `docker compose exec -T frontend npx jest src/app/reader/media-embeds` then `docker compose exec -T frontend npm run check` — expect PASS.
- [ ] **Step 8: Gates + commit** — `composer cs stan md tramp` clean; commit `feat(#1461): a YouTube Short plays in a portrait box`.

---

### Task 7: The list marks a YouTube Short

Added on request after Task 6. The user chose a "Short" badge in the corner of the entry's thumbnail; an entry shown without an image carries a "Short" pill in its meta row instead.

**Files:**
- Create: `backend/src/Service/Reader/Media/Support/YouTubeShortUrl.php` (static, private constructor): `YouTubeShortUrl::videoId(string $url): ?string` — the 11-char id of a `youtube.com`/`www.youtube.com`/`m.youtube.com` `/shorts/<id>` URL (trailing slash and query allowed), else null; `YouTubeShortUrl::is(?string $url): bool`.
- Modify: `backend/src/Service/Reader/Media/EmbedProvider/YouTubeEmbedProvider.php` — its `shortsIdFromPath`/`shortsFragment` use `YouTubeShortUrl` instead of their own `/shorts/` regex (one home for the Shorts shape).
- Modify: `backend/src/Http/EntryJson.php` — every list row (`listRow`, so duplicates and the detail shape inherit it) gains `'isShort' => YouTubeShortUrl::is($entry->getUrl())`.
- Test: `backend/tests/Service/Reader/Media/Support/YouTubeShortUrlTest.php`; extend the existing `EntryJson`/entries API test that pins the list-row keys (find it with `grep -rln "'isKept'" backend/tests`) to include `isShort` true for a `/shorts/` URL and false otherwise.
- Modify: `frontend/src/app/reader/models.ts` (`EntryDto.isShort: boolean`), the five image-bearing list layouts — `list/entry-row/entry-row.component.html`, `list/magazine/blocks/entry-hero|entry-wide|entry-split|entry-thumb/*.component.html` — and the meta pill row (`entry/entry-pills` or `list/entry-meta`, whichever renders under both image-less row and magazine blocks).
- Create: a shared standalone badge component (e.g. `frontend/src/app/reader/list/short-badge/short-badge.component.{ts,html,scss}`) used by all five layouts, so the chip is defined once.
- i18n: `reader.shortBadge` = "Short" in `frontend/public/i18n/en.json` and "Short" in `de.json` (YouTube's own German UI says "Shorts"/"Short").
- Test: specs beside each touched component, plus the badge component's own spec; update test fixtures/builders that construct `EntryDto` (grep `isKept:` in `frontend/src`) with `isShort: false`.

**Interfaces:**
- Wire: list-row JSON `isShort: boolean` (always present).
- SPA: `EntryDto.isShort`; badge shown when `entry().isShort && showImage()` (or the row's equivalent image condition); pill shown when `entry().isShort && !image shown`.

**Design constraints:** read `docs/design-language.md` first (tokens §1, catalog §2, magazine blocks §5, adding a surface §7). Use tokens only (`--fs-xs` for badge text, `--radius-pill` or the catalog's badge radius, existing surface/scrim colours); no hex, no ad-hoc px, no new media queries. The badge sits over the image's top-right corner inside the image's own box: wrap each `<img>` in a positioned frame only if needed and keep every existing image rule (aspect-ratio binding, `airy-image-rim`, renditions, error gate) working — check each layout's scss for selectors that assume the `img` is a direct child. It is decorative-plus-label: visible text "Short", not focusable, and the card's accessible name is unchanged except for the text.

- [ ] **Step 1:** Failing `YouTubeShortUrlTest` (shorts URL with/without `www.`, `m.`, trailing slash, `?feature=share` → id; watch URL, `/shorts/`, `/shorts/tooShort`, other host, null → not a Short). Implement; switch `YouTubeEmbedProvider` to it; `php bin/phpunit tests/Service/Reader/Media` green.
- [ ] **Step 2:** Failing API test for `isShort`; add the key in `EntryJson::listRow`; green. Gates `composer cs stan md tramp`.
- [ ] **Step 3:** Badge component + spec; wire into the five layouts and the pill fallback, with specs asserting: a Short with an image shows the badge and no pill; a Short without an image shows the pill; a normal entry shows neither.
- [ ] **Step 4:** Visual check of every layout on a real Short (dev entry 575795, subscription 1713) in the built-in browser at desktop and mobile widths; screenshots into the report. Restore the viewport afterwards.
- [ ] **Step 5:** `docker compose exec -T frontend npm run check` ONCE (never two Jest runs at a time — the container OOMs, #1462); then confirm the container is still up (`docker compose ps frontend`). Commit `feat(#1461): the list marks a YouTube Short`.

---

### Task 8: A Short's list image shows its cover in portrait

Added on request after Task 7 (user chose option a). A Short's feed thumbnail (`hqdefault.jpg`, 480×360) is the creator's chosen portrait cover centred in a 4:3 frame with blurred side bars. YouTube's portrait files (`oar2.jpg`, `frame0.jpg`) are auto-picked frames, not the cover, and undocumented — not used. Instead the list crops the existing image to 9:16 in CSS.

Ruling: CSS crop of the existing image only; no switch to `maxresdefault.jpg` (it 404s for some uploads, and the image pipeline would then lose the image). The cropped strip of a 480×360 source is ~200×360, so a large portrait slot is somewhat soft — accepted; a sharper source is a possible follow-up.

**Files:** the five image-bearing list layouts touched by Task 7 (`list/entry-row`, `list/magazine/blocks/entry-hero|entry-wide|entry-split|entry-thumb`) — their `.html` (a modifier class when `entry().isShort`) and sibling `.scss`.

**Behaviour:**
- Every layout: a Short's image box is portrait — `aspect-ratio: 9 / 16; object-fit: cover; object-position: center` — overriding the bound `[style.aspect-ratio]` where a layout binds one (the cover sits in the middle; the blurred bars fall outside the crop).
- Row and thumb: a narrow portrait thumbnail in place of the current one, same height as today's thumbnail box (width shrinks), so row height does not grow.
- Hero and wide (option a): the portrait image is height-capped and centred in the card — a height token-based cap (pick from `docs/design-language.md` tokens or a rem value in the style the file already uses, no ad-hoc px) such that the image never exceeds roughly 60% of a phone viewport's height nor ~28rem on desktop; the card background shows on both sides.
- Split: the side image takes the portrait box at the split's existing image width; the card may grow taller to fit, capped as in hero/wide.
- The Task 7 badge stays in the top-right corner of the visible (cropped) image in every layout.
- Image error gate, renditions (`appRenditions`/`renditionSizes`), lazy loading and `airy-image-rim` keep working; non-Short entries render exactly as before (pixel-identical — verify with a before/after look at a normal entry).

- [ ] **Step 1:** Specs: in each touched layout's spec, a Short entry renders the image with the portrait modifier class and a normal entry without it.
- [ ] **Step 2:** Implement markup + scss per layout.
- [ ] **Step 3:** Visual check in the built-in browser on entry 575795 (subscription 1713) and on a normal YouTube video (subscription 1712), magazine and list layouts, desktop and mobile widths; screenshots/observations in the report; restore the viewport.
- [ ] **Step 4:** One `docker compose exec -T frontend npm run check` (never concurrent Jest, #1462), container still Up; commit `feat(#1461): a Short's list image shows its cover in portrait`.
