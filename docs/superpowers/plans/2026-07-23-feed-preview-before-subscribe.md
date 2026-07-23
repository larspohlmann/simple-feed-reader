# Feed Preview Before Subscribe — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Let a user preview each candidate feed's content shape (headlines, full-text vs. summary vs. titles-only, images) inline in the Add-a-feed dialog before subscribing.

**Architecture:** A new authenticated, rate-limited `POST /api/feeds/preview` reuses the existing SSRF-guarded `FeedFetcherInterface` + `FeedParser` to fetch and parse a candidate feed, then returns a compact preview (feed title, item count, a content-richness verdict, a has-images flag, and 4 sample items). The feed parsers gain a parse-time `imageUrl` (from Media RSS / enclosures / inline `<img>`) so image detection is accurate; nothing is persisted. The Angular dialog fires one preview per candidate in parallel and renders comparison cards.

**Tech Stack:** Symfony 7.4 / PHP 8.4 (backend), Angular 20.3 standalone + signals (frontend), PHPUnit (Docker `php` container), Jest (frontend).

**Reference:** design spec `docs/superpowers/specs/2026-07-23-feed-preview-before-subscribe-design.md`.

**Conventions for every task:**
- Backend tests run in Docker: `docker compose exec -T php php bin/phpunit <args>` (run from repo root, or `backend/` — match the existing scripts).
- Frontend tests run from `frontend/`: `npx jest <path>`.
- Any touched backend `src` file must be phpmd-clean before its commit (standing rule).
- Follow existing patterns: `final readonly` DTOs, `XmlHelper`/namespace-by-local-name traversal, RFC7807 problem responses.

---

### Task 1: `ParsedEntry.imageUrl` + `ItemImageExtractor` helper

**Files:**
- Modify: `backend/src/Service/Parser/ParsedEntry.php`
- Create: `backend/src/Service/Parser/ItemImageExtractor.php`
- Create: `backend/tests/Service/Parser/ItemImageExtractorTest.php`

- [ ] **Step 1: Add the field to `ParsedEntry`** (new nullable ctor param, last position so existing named-arg callers are unaffected until updated):

```php
final readonly class ParsedEntry
{
    public function __construct(
        public string $guid,
        public ?string $url,
        public string $title,
        public ?string $author,
        public ?string $summary,
        public ?string $contentHtml,
        public ?\DateTimeImmutable $publishedAt,
        public ?string $imageUrl = null,
    ) {
    }
}
```

- [ ] **Step 2: Write the failing test** `ItemImageExtractorTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Service\Parser;

use App\Service\Parser\ItemImageExtractor;
use PHPUnit\Framework\TestCase;

final class ItemImageExtractorTest extends TestCase
{
    private function item(string $innerXml): \DOMElement
    {
        $doc = new \DOMDocument();
        $doc->loadXML(
            '<item xmlns:media="http://search.yahoo.com/mrss/">' . $innerXml . '</item>',
        );
        $el = $doc->documentElement;
        \assert($el instanceof \DOMElement);

        return $el;
    }

    public function testMediaThumbnailWins(): void
    {
        $item = $this->item(
            '<media:thumbnail url="https://x/thumb.jpg"/>'
            . '<media:content url="https://x/big.jpg" medium="image"/>',
        );
        self::assertSame('https://x/thumb.jpg', ItemImageExtractor::fromMedia($item));
    }

    public function testMediaContentImageByMedium(): void
    {
        $item = $this->item('<media:content url="https://x/pic.jpg" medium="image"/>');
        self::assertSame('https://x/pic.jpg', ItemImageExtractor::fromMedia($item));
    }

    public function testMediaContentImageByType(): void
    {
        $item = $this->item('<media:content url="https://x/pic.png" type="image/png"/>');
        self::assertSame('https://x/pic.png', ItemImageExtractor::fromMedia($item));
    }

    public function testMediaContentNonImageIgnored(): void
    {
        $item = $this->item('<media:content url="https://x/clip.mp4" type="video/mp4"/>');
        self::assertNull(ItemImageExtractor::fromMedia($item));
    }

    public function testRssImageEnclosure(): void
    {
        $item = $this->item('<enclosure url="https://x/a.jpg" type="image/jpeg" length="1"/>');
        self::assertSame('https://x/a.jpg', ItemImageExtractor::fromRssEnclosure($item));
    }

    public function testRssNonImageEnclosureIgnored(): void
    {
        $item = $this->item('<enclosure url="https://x/a.mp3" type="audio/mpeg" length="1"/>');
        self::assertNull(ItemImageExtractor::fromRssEnclosure($item));
    }

    public function testFirstImgFromHtml(): void
    {
        self::assertSame(
            'https://x/inline.jpg',
            ItemImageExtractor::fromHtml('<p>hi</p><img src="https://x/inline.jpg" alt="x"> more'),
        );
    }

    public function testHtmlWithoutImgIsNull(): void
    {
        self::assertNull(ItemImageExtractor::fromHtml('<p>no image here</p>'));
        self::assertNull(ItemImageExtractor::fromHtml(null));
    }
}
```

- [ ] **Step 3: Run it, expect failure** (`ItemImageExtractor` not found):

Run: `docker compose exec -T php php bin/phpunit tests/Service/Parser/ItemImageExtractorTest.php`
Expected: FAIL (class not found).

- [ ] **Step 4: Implement `ItemImageExtractor`:**

```php
<?php

declare(strict_types=1);

namespace App\Service\Parser;

/**
 * Finds the first usable image URL attached to a feed item. Callers combine the
 * sources in the precedence their format prefers (Media RSS, then a format's
 * enclosure, then an inline <img>). URLs are returned verbatim — callers that
 * need an absolute URL resolve it themselves; the preview only needs presence.
 */
final class ItemImageExtractor
{
    private const MEDIA_NS = 'http://search.yahoo.com/mrss/';

    /** Media RSS image: <media:thumbnail> preferred, else an image <media:content>. */
    public static function fromMedia(\DOMElement $item): ?string
    {
        $thumb = self::mediaUrl($item, 'thumbnail');
        if ($thumb !== null) {
            return $thumb;
        }

        foreach ($item->childNodes as $child) {
            if (
                !$child instanceof \DOMElement
                || $child->localName !== 'content'
                || $child->namespaceURI !== self::MEDIA_NS
            ) {
                continue;
            }
            $url = trim($child->getAttribute('url'));
            $medium = strtolower($child->getAttribute('medium'));
            $type = strtolower($child->getAttribute('type'));
            if ($url !== '' && ($medium === 'image' || str_starts_with($type, 'image/'))) {
                return $url;
            }
        }

        return null;
    }

    /** RSS 2.0 <enclosure type="image/*" url="…">. */
    public static function fromRssEnclosure(\DOMElement $item): ?string
    {
        foreach ($item->childNodes as $child) {
            if (!$child instanceof \DOMElement || $child->localName !== 'enclosure') {
                continue;
            }
            $url = trim($child->getAttribute('url'));
            if ($url !== '' && str_starts_with(strtolower($child->getAttribute('type')), 'image/')) {
                return $url;
            }
        }

        return null;
    }

    /** Atom <link rel="enclosure" type="image/*" href="…">. */
    public static function fromAtomEnclosure(\DOMElement $entry, string $ns): ?string
    {
        foreach ($entry->childNodes as $child) {
            if (
                !$child instanceof \DOMElement
                || $child->localName !== 'link'
                || $child->namespaceURI !== $ns
                || $child->getAttribute('rel') !== 'enclosure'
            ) {
                continue;
            }
            $href = trim($child->getAttribute('href'));
            if ($href !== '' && str_starts_with(strtolower($child->getAttribute('type')), 'image/')) {
                return $href;
            }
        }

        return null;
    }

    /** First <img src="…"> in a fragment of HTML. */
    public static function fromHtml(?string $html): ?string
    {
        if ($html === null || $html === '') {
            return null;
        }
        if (preg_match('/<img\b[^>]*?\bsrc\s*=\s*("|\')(.*?)\1/i', $html, $m) !== 1) {
            return null;
        }
        $src = trim(html_entity_decode($m[2], ENT_QUOTES | ENT_HTML5));

        return $src === '' ? null : $src;
    }

    private static function mediaUrl(\DOMElement $item, string $localName): ?string
    {
        foreach ($item->childNodes as $child) {
            if (
                $child instanceof \DOMElement
                && $child->localName === $localName
                && $child->namespaceURI === self::MEDIA_NS
            ) {
                $url = trim($child->getAttribute('url'));
                if ($url !== '') {
                    return $url;
                }
            }
        }

        return null;
    }
}
```

- [ ] **Step 5: Run tests, expect pass:**

Run: `docker compose exec -T php php bin/phpunit tests/Service/Parser/ItemImageExtractorTest.php`
Expected: PASS.

- [ ] **Step 6: Commit:**

```bash
git add backend/src/Service/Parser/ParsedEntry.php backend/src/Service/Parser/ItemImageExtractor.php backend/tests/Service/Parser/ItemImageExtractorTest.php
git commit -m "feat(parser): add item image extraction helper + ParsedEntry.imageUrl"
```

---

### Task 2: Wire image extraction into `Rss2Parser`

**Files:**
- Modify: `backend/src/Service/Parser/Rss2Parser.php`
- Modify: `backend/tests/Service/Parser/Rss2ParserTest.php` (add cases; keep existing green)

- [ ] **Step 1: Add a failing test** — append to `Rss2ParserTest.php` (match the file's existing fixture/assertion style; parse a `<channel>` with one `<item>` carrying a `<media:content medium="image">` and one carrying only an inline `<img>` in `content:encoded`, plus one with no image):

```php
public function testExtractsItemImageFromMediaContent(): void
{
    $xml = <<<XML
    <rss version="2.0" xmlns:media="http://search.yahoo.com/mrss/">
      <channel><title>T</title>
        <item><title>A</title><link>https://e/a</link>
          <media:content url="https://e/a.jpg" medium="image"/>
        </item>
        <item><title>B</title><link>https://e/b</link>
          <description>&lt;p&gt;hi&lt;/p&gt;&lt;img src="https://e/b.jpg"&gt;</description>
        </item>
        <item><title>C</title><link>https://e/c</link><description>plain</description></item>
      </channel>
    </rss>
    XML;

    $feed = $this->parse($xml); // use the test's existing parse helper

    self::assertSame('https://e/a.jpg', $feed->entries[0]->imageUrl);
    self::assertSame('https://e/b.jpg', $feed->entries[1]->imageUrl);
    self::assertNull($feed->entries[2]->imageUrl);
}
```

> If `Rss2ParserTest` has no `parse()` helper, build the parser the same way the existing tests in that file do (they already construct `Rss2Parser` and hand it a `\DOMDocument`). Reuse that exact setup.

- [ ] **Step 2: Run it, expect failure** (`imageUrl` is null):

Run: `docker compose exec -T php php bin/phpunit tests/Service/Parser/Rss2ParserTest.php`
Expected: FAIL on the new assertions.

- [ ] **Step 3: Implement** — in `Rss2Parser::parseItem()`, compute the image and pass it. Replace the `return new ParsedEntry(...)` with:

```php
$image = ItemImageExtractor::fromMedia($item)
    ?? ItemImageExtractor::fromRssEnclosure($item)
    ?? ItemImageExtractor::fromHtml($contentEncoded ?? $description);

return new ParsedEntry(
    guid: GuidFallback::for(XmlHelper::childText($item, 'guid'), $link, $title),
    url: $link,
    title: $title ?? '(untitled)',
    author: XmlHelper::childText($item, 'author') ?? XmlHelper::childText($item, 'creator', self::DC_NS),
    summary: $contentEncoded !== null ? $description : null,
    contentHtml: $contentEncoded ?? $description,
    publishedAt: DateParser::parse(
        XmlHelper::childText($item, 'pubDate') ?? XmlHelper::childText($item, 'date', self::DC_NS),
    ),
    imageUrl: $image,
);
```

- [ ] **Step 4: Run tests, expect pass** (new + existing):

Run: `docker compose exec -T php php bin/phpunit tests/Service/Parser/Rss2ParserTest.php`
Expected: PASS.

- [ ] **Step 5: Commit:**

```bash
git add backend/src/Service/Parser/Rss2Parser.php backend/tests/Service/Parser/Rss2ParserTest.php
git commit -m "feat(parser): extract item image in RSS 2.0 feeds"
```

---

### Task 3: Wire image extraction into the Atom parsers

**Files:**
- Modify: `backend/src/Service/Parser/AbstractAtomParser.php`
- Modify: `backend/tests/Service/Parser/Atom10ParserTest.php` (add cases; Atom 0.3 inherits the code path)

- [ ] **Step 1: Add a failing test** to `Atom10ParserTest.php` — an entry with `<media:thumbnail>`, an entry with `<link rel="enclosure" type="image/png">`, and an entry with only an inline `<img>` in `<content type="html">`; assert `imageUrl` for each and null for an image-less entry. Mirror the file's existing parser construction.

- [ ] **Step 2: Run it, expect failure:**

Run: `docker compose exec -T php php bin/phpunit tests/Service/Parser/Atom10ParserTest.php`
Expected: FAIL.

- [ ] **Step 3: Implement** — in `AbstractAtomParser::parseEntry()`, compute the content once, derive the image, and pass it:

```php
private function parseEntry(\DOMElement $entry, string $ns): ?ParsedEntry
{
    $title = XmlHelper::childText($entry, 'title', $ns);
    $link = $this->alternateLink($entry, $ns);
    if ($title === null && $link === null) {
        return null;
    }

    $contentHtml = $this->contentHtml($entry, $ns);
    $image = ItemImageExtractor::fromMedia($entry)
        ?? ItemImageExtractor::fromAtomEnclosure($entry, $ns)
        ?? ItemImageExtractor::fromHtml($contentHtml);

    return new ParsedEntry(
        guid: GuidFallback::for(XmlHelper::childText($entry, 'id', $ns), $link, $title),
        url: $link,
        title: $title ?? '(untitled)',
        author: $this->authorName($entry, $ns),
        summary: XmlHelper::childText($entry, 'summary', $ns),
        contentHtml: $contentHtml,
        publishedAt: DateParser::parse($this->firstDate($entry, $ns)),
        imageUrl: $image,
    );
}
```

- [ ] **Step 4: Run tests, expect pass** (Atom 1.0 + 0.3 + existing):

Run: `docker compose exec -T php php bin/phpunit tests/Service/Parser/`
Expected: PASS.

- [ ] **Step 5: Commit:**

```bash
git add backend/src/Service/Parser/AbstractAtomParser.php backend/tests/Service/Parser/Atom10ParserTest.php
git commit -m "feat(parser): extract item image in Atom feeds"
```

---

### Task 4: Wire image extraction into `Rss1Parser`

**Files:**
- Modify: `backend/src/Service/Parser/Rss1Parser.php`
- Modify: `backend/tests/Service/Parser/Rss1ParserTest.php`

- [ ] **Step 1: Add a failing test** to `Rss1ParserTest.php` — an RDF item with `content:encoded` containing an inline `<img>`, and an item with a `<media:content medium="image">`; assert `imageUrl`; assert null when neither is present.

- [ ] **Step 2: Run it, expect failure:**

Run: `docker compose exec -T php php bin/phpunit tests/Service/Parser/Rss1ParserTest.php`
Expected: FAIL.

- [ ] **Step 3: Implement** — in `Rss1Parser::parseItem()`, before the `return`:

```php
$image = ItemImageExtractor::fromMedia($item)
    ?? ItemImageExtractor::fromHtml($contentEncoded ?? $description);
```

and pass `imageUrl: $image` as the final named argument of the `ParsedEntry`.

- [ ] **Step 4: Run tests, expect pass:**

Run: `docker compose exec -T php php bin/phpunit tests/Service/Parser/Rss1ParserTest.php`
Expected: PASS.

- [ ] **Step 5: Commit:**

```bash
git add backend/src/Service/Parser/Rss1Parser.php backend/tests/Service/Parser/Rss1ParserTest.php
git commit -m "feat(parser): extract item image in RSS 1.0 feeds"
```

---

### Task 5: `FeedPreview` DTOs + `FeedPreviewService`

**Files:**
- Create: `backend/src/Service/Preview/FeedPreview.php`
- Create: `backend/src/Service/Preview/FeedPreviewItem.php`
- Create: `backend/src/Service/Preview/FeedPreviewService.php`
- Create: `backend/src/Exception/FeedPreviewException.php`
- Create: `backend/tests/Service/Preview/FeedPreviewServiceTest.php`

**Design notes for this task:**
- The service fetches via `FeedFetcherInterface` and parses via `FeedParser`, exactly like `FeedDiscovery` — catch `FetchException` and `FeedParseException` and rethrow as `FeedPreviewException` (so the controller maps one exception type to 422). Empty body → `FeedPreviewException`.
- Sample = first `SAMPLE_SIZE = 4` entries. `itemCount` = total parsed entries.
- Per item: plain text = `strip_tags` of `contentHtml` (preferred) else `summary`, with entities decoded and whitespace collapsed. `textLength` = `mb_strlen` of that. `snippet` = first 200 chars on a word boundary, `…` if truncated. Tier: `full` if `contentHtml !== null` and its plain-text length ≥ `FULL_TEXT_MIN = 600`; else `summary` if any text; else `title-only`. `hasImage` = `imageUrl !== null`.
- Feed `content` verdict = mode of the sampled tiers, ties → richer (`full` > `summary` > `title-only`); default `title-only` when there are no items. Feed `hasImages` = any sampled item has an image.

- [ ] **Step 1: Create the DTOs** (`FeedPreviewItem`, `FeedPreview`):

```php
// FeedPreviewItem.php
final readonly class FeedPreviewItem
{
    public function __construct(
        public string $title,
        public ?\DateTimeImmutable $publishedAt,
        public ?string $author,
        public bool $hasImage,
        public int $textLength,
        public string $snippet,
    ) {
    }
}
```

```php
// FeedPreview.php
final readonly class FeedPreview
{
    /** @param 'full'|'summary'|'title-only' $content
     *  @param list<FeedPreviewItem> $items */
    public function __construct(
        public ?string $title,
        public int $itemCount,
        public string $content,
        public bool $hasImages,
        public array $items,
    ) {
    }
}
```

```php
// FeedPreviewException.php  (namespace App\Exception)
final class FeedPreviewException extends \RuntimeException
{
}
```

- [ ] **Step 2: Write the failing test** `FeedPreviewServiceTest.php` — stub `FeedFetcherInterface` to return a `FetchResponse` with a fixture body; use the real `FeedParser` (constructed with the four real sub-parsers, as other tests do). Cover:
  - a full-text feed (long `content:encoded` per item) → `content === 'full'`, `itemCount` correct, `items` capped at 4;
  - a summary-only feed → `content === 'summary'`;
  - a titles-only feed → `content === 'title-only'`;
  - a feed with a media image on one item → `hasImages === true` and that item's `hasImage === true`;
  - snippet truncation adds `…` and stays ≤ ~201 chars;
  - a fetch that throws `FetchException` → service throws `FeedPreviewException`;
  - an unparseable body → `FeedPreviewException`.

```php
// sketch of the stub + one case
$fetcher = new class implements FeedFetcherInterface {
    public string $body = '';
    public function fetch(string $url, ?string $etag = null, ?string $lastModified = null): FetchResponse
    {
        return new FetchResponse(false, $url, false, $this->body, null, null);
    }
};
$parser = new FeedParser(new Rss2Parser(), new Atom10Parser(), new Atom03Parser(), new Rss1Parser());
$service = new FeedPreviewService($fetcher, $parser);
$fetcher->body = /* full-text RSS fixture with 5 items */;
$preview = $service->preview('https://feed');
self::assertSame('full', $preview->content);
self::assertCount(4, $preview->items);
```

> Confirm the real `FetchResponse` constructor argument order against `backend/src/Service/Fetch/FetchResponse.php` before writing the stub.

- [ ] **Step 3: Run it, expect failure:**

Run: `docker compose exec -T php php bin/phpunit tests/Service/Preview/FeedPreviewServiceTest.php`
Expected: FAIL (service not found).

- [ ] **Step 4: Implement `FeedPreviewService`:**

```php
<?php

declare(strict_types=1);

namespace App\Service\Preview;

use App\Exception\FeedPreviewException;
use App\Service\Fetch\Exception\FetchException;
use App\Service\Fetch\FeedFetcherInterface;
use App\Service\Parser\Exception\FeedParseException;
use App\Service\Parser\FeedParser;
use App\Service\Parser\ParsedEntry;

final readonly class FeedPreviewService
{
    private const SAMPLE_SIZE = 4;
    private const FULL_TEXT_MIN = 600;
    private const SNIPPET_LEN = 200;

    /** Tier ranking, richest last — used to resolve verdict ties toward the richer tier. */
    private const TIER_RANK = ['title-only' => 0, 'summary' => 1, 'full' => 2];

    public function __construct(
        private FeedFetcherInterface $fetcher,
        private FeedParser $parser,
    ) {
    }

    public function preview(string $url): FeedPreview
    {
        try {
            $response = $this->fetcher->fetch($url);
        } catch (FetchException $e) {
            throw new FeedPreviewException('The feed could not be loaded.', 0, $e);
        }

        $body = $response->body ?? '';
        if (trim($body) === '') {
            throw new FeedPreviewException('The feed returned an empty document.');
        }

        try {
            $feed = $this->parser->parse($body);
        } catch (FeedParseException $e) {
            throw new FeedPreviewException('That address is not a readable feed.', 0, $e);
        }

        $sample = \array_slice($feed->entries, 0, self::SAMPLE_SIZE);
        $items = array_map(fn (ParsedEntry $e) => $this->item($e), $sample);

        return new FeedPreview(
            title: $feed->title,
            itemCount: \count($feed->entries),
            content: $this->verdict($items),
            hasImages: array_any($items, static fn (FeedPreviewItem $i) => $i->hasImage),
            items: $items,
        );
    }

    private function item(ParsedEntry $entry): FeedPreviewItem
    {
        $text = $this->plainText($entry->contentHtml ?? $entry->summary);

        return new FeedPreviewItem(
            title: $entry->title,
            publishedAt: $entry->publishedAt,
            author: $entry->author,
            hasImage: $entry->imageUrl !== null,
            textLength: mb_strlen($text),
            snippet: $this->snippet($text),
        );
    }

    /** Per-item content tier from the richest body the item ships. */
    private function tier(ParsedEntry $entry): string
    {
        if ($entry->contentHtml !== null && mb_strlen($this->plainText($entry->contentHtml)) >= self::FULL_TEXT_MIN) {
            return 'full';
        }
        if ($this->plainText($entry->contentHtml ?? $entry->summary) !== '') {
            return 'summary';
        }

        return 'title-only';
    }

    /** @param list<FeedPreviewItem> $items */
    private function verdict(array $items): string
    {
        // NOTE: recompute tiers here from the sampled ParsedEntry list; see impl.
        // (Kept alongside item() — pass tiers through instead of re-deriving.)
        return 'title-only';
    }

    private function plainText(?string $html): string
    {
        if ($html === null) {
            return '';
        }
        $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }

    private function snippet(string $text): string
    {
        if (mb_strlen($text) <= self::SNIPPET_LEN) {
            return $text;
        }
        $cut = mb_substr($text, 0, self::SNIPPET_LEN);
        $lastSpace = mb_strrpos($cut, ' ');
        if ($lastSpace !== false && $lastSpace > 0) {
            $cut = mb_substr($cut, 0, $lastSpace);
        }

        return rtrim($cut) . '…';
    }
}
```

> **Implementer note — resolve the `verdict()`/tier plumbing cleanly:** the sketch above shows `tier()` and `verdict()` separately for clarity, but `verdict` must actually use the sampled entries' tiers. Implement it as: compute `$tiers = array_map(fn($e) => $this->tier($e), $sample);` in `preview()`, pass `$tiers` to a `verdict(array $tiers): string` that returns the most frequent tier (ties → higher `TIER_RANK`), defaulting to `'title-only'` for an empty list. Keep `item()` and `tier()` operating on the same `$sample`. Ensure the final code has no unused private method and passes phpmd (CyclomaticComplexity/NPath within limits — these helpers are small).
>
> `array_any`/`array_all` are PHP 8.4 built-ins (this project targets 8.4) — fine to use; otherwise fall back to a `foreach`.

- [ ] **Step 5: Run tests, expect pass:**

Run: `docker compose exec -T php php bin/phpunit tests/Service/Preview/FeedPreviewServiceTest.php`
Expected: PASS.

- [ ] **Step 6: phpmd the touched/created src files, then commit:**

```bash
# phpmd must be clean for every new src file
git add backend/src/Service/Preview backend/src/Exception/FeedPreviewException.php backend/tests/Service/Preview
git commit -m "feat(preview): FeedPreviewService — content-shape summary of a feed"
```

---

### Task 6: `feed_preview` rate limiter + `FeedPreviewController` + JSON shaping

**Files:**
- Modify: `backend/config/packages/rate_limiter.yaml`
- Create: `backend/src/Dto/Feed/PreviewFeedRequest.php`
- Create: `backend/src/Http/FeedPreviewJson.php`
- Create: `backend/src/Controller/Api/FeedPreviewController.php`
- Create: `backend/tests/Controller/Api/FeedPreviewControllerTest.php`

- [ ] **Step 1: Add the limiter** — append to `rate_limiter.yaml` under `rate_limiter:` (mirror the `reader` block's shape and comment style):

```yaml
        # Per-user cap on feed preview. Previewing candidates fires one outbound
        # fetch per candidate (2-3 on a typical dialog open), so it is keyed on
        # the user id and sized like reader: honest usage is a handful of opens.
        # Sliding window and the same pool as its neighbours.
        feed_preview:
            policy: 'sliding_window'
            limit: 60
            interval: '5 minutes'
            cache_pool: cache.rate_limiter
```

- [ ] **Step 2: Create the request DTO** `PreviewFeedRequest.php` (mirror `SubscribeRequest`):

```php
<?php

declare(strict_types=1);

namespace App\Dto\Feed;

use Symfony\Component\Validator\Constraints as Assert;

final class PreviewFeedRequest
{
    public function __construct(
        #[Assert\NotBlank]
        #[Assert\Url(protocols: ['http', 'https'], requireTld: true)]
        #[Assert\Length(max: 750)]
        public string $url = '',
    ) {
    }
}
```

> Copy the exact attribute set/format from `backend/src/Dto/Subscription/SubscribeRequest.php` so validation and problem output match the subscribe path.

- [ ] **Step 3: Create the JSON shaper** `FeedPreviewJson.php` (mirror `ReaderJson`):

```php
<?php

declare(strict_types=1);

namespace App\Http;

use App\Service\Preview\FeedPreview;
use App\Service\Preview\FeedPreviewItem;

final class FeedPreviewJson
{
    /**
     * @return array{feed: array{title: string|null, itemCount: int,
     *   content: string, hasImages: bool, items: list<array{title: string,
     *   publishedAt: string|null, author: string|null, hasImage: bool,
     *   textLength: int, snippet: string}>}}
     */
    public static function one(FeedPreview $preview): array
    {
        return ['feed' => [
            'title' => $preview->title,
            'itemCount' => $preview->itemCount,
            'content' => $preview->content,
            'hasImages' => $preview->hasImages,
            'items' => array_map(
                static fn (FeedPreviewItem $i) => [
                    'title' => $i->title,
                    'publishedAt' => $i->publishedAt?->format(\DateTimeInterface::ATOM),
                    'author' => $i->author,
                    'hasImage' => $i->hasImage,
                    'textLength' => $i->textLength,
                    'snippet' => $i->snippet,
                ],
                $preview->items,
            ),
        ]];
    }
}
```

- [ ] **Step 4: Write the failing controller test** `FeedPreviewControllerTest.php` — a `WebTestCase` (mirror the existing controller tests, e.g. how `SubscriptionControllerTest`/`EntryControllerTest` boot the kernel, authenticate a user, and override services). Replace the `FeedPreviewService` (or the underlying `FeedFetcherInterface`) in the test container with a stub. Assert:
  - authenticated happy path → 200 and the documented JSON shape;
  - a stub that throws `FeedPreviewException` → 422 problem;
  - unauthenticated → 401;
  - (optional if easily expressible) rate-limit exhaustion → 429.

> Follow the existing controller-test harness in `backend/tests/Controller/Api/` for auth + service overrides; do not invent a new bootstrapping style.

- [ ] **Step 5: Run it, expect failure:**

Run: `docker compose exec -T php php bin/phpunit tests/Controller/Api/FeedPreviewControllerTest.php`
Expected: FAIL (route/controller missing).

- [ ] **Step 6: Implement `FeedPreviewController`:**

```php
<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Dto\Feed\PreviewFeedRequest;
use App\Entity\User;
use App\Exception\FeedPreviewException;
use App\Exception\RateLimitedException;
use App\Http\FeedPreviewJson;
use App\Service\Preview\FeedPreviewService;
use Psr\Clock\ClockInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

#[Route('/api/feeds')]
final class FeedPreviewController
{
    public function __construct(
        private readonly FeedPreviewService $previews,
        private readonly RateLimiterFactoryInterface $feedPreviewLimiter,
        private readonly ClockInterface $clock,
    ) {
    }

    #[Route('/preview', name: 'api_feeds_preview', methods: ['POST'])]
    public function preview(
        #[CurrentUser] User $user,
        #[MapRequestPayload] PreviewFeedRequest $request,
    ): JsonResponse {
        $this->enforceLimit($user);

        try {
            $preview = $this->previews->preview($request->url);
        } catch (FeedPreviewException $e) {
            throw new UnprocessableEntityHttpException($e->getMessage(), $e);
        }

        return new JsonResponse(FeedPreviewJson::one($preview));
    }

    private function enforceLimit(User $user): void
    {
        $limit = $this->feedPreviewLimiter->create('user-' . $user->getId())->consume();
        if ($limit->isAccepted()) {
            return;
        }

        throw new RateLimitedException(
            max(1, $limit->getRetryAfter()->getTimestamp() - $this->clock->now()->getTimestamp()),
        );
    }
}
```

> The `$feedPreviewLimiter` argument name binds to the `feed_preview` limiter by Symfony's naming convention (as `$readerLimiter` does for `reader`). Confirm the injected type is `RateLimiterFactoryInterface`, matching `EntryController`.

- [ ] **Step 7: Run tests, expect pass** (plus the whole suite to catch regressions):

Run: `docker compose exec -T php php bin/phpunit tests/Controller/Api/FeedPreviewControllerTest.php`
then `docker compose exec -T php php bin/phpunit`
Expected: PASS.

- [ ] **Step 8: phpmd the new src files, then commit:**

```bash
git add backend/config/packages/rate_limiter.yaml backend/src/Dto/Feed backend/src/Http/FeedPreviewJson.php backend/src/Controller/Api/FeedPreviewController.php backend/tests/Controller/Api/FeedPreviewControllerTest.php
git commit -m "feat(api): POST /api/feeds/preview endpoint (rate-limited, SSRF-guarded)"
```

---

### Task 7: Frontend model + API method

**Files:**
- Modify: `frontend/src/app/reader/models.ts`
- Modify: `frontend/src/app/reader/reader-api.ts`
- Modify: `frontend/src/app/reader/reader-api.spec.ts` (if it exists; else add a focused spec)

- [ ] **Step 1: Add the models** to `models.ts` (and fix the existing `FeedCandidate.title` to match the backend's nullable emission):

```ts
/** A candidate feed returned by POST /subscriptions when the URL was an HTML page. */
export interface FeedCandidate {
  url: string;
  title: string | null;
}

export interface FeedPreviewItem {
  title: string;
  publishedAt: string | null;
  author: string | null;
  hasImage: boolean;
  textLength: number;
  snippet: string;
}

/** A pre-subscribe preview of a candidate feed's content shape. */
export interface FeedPreview {
  title: string | null;
  itemCount: number;
  content: 'full' | 'summary' | 'title-only';
  hasImages: boolean;
  items: FeedPreviewItem[];
}
```

- [ ] **Step 2: Add a failing API test** — assert `previewFeed('https://f')` issues `POST {base}/api/feeds/preview` with body `{ url: 'https://f' }`. Use the `HttpTestingController` pattern already used in the frontend specs.

- [ ] **Step 3: Run it, expect failure:**

Run (from `frontend/`): `npx jest src/app/reader/reader-api.spec.ts`
Expected: FAIL (`previewFeed` undefined).

- [ ] **Step 4: Implement** — add to `ReaderApi` (import `FeedPreview` in the models import list):

```ts
/** Preview a candidate feed's contents before subscribing. */
previewFeed(url: string): Observable<{ feed: FeedPreview }> {
  return this.http.post<{ feed: FeedPreview }>(`${this.base}/api/feeds/preview`, { url });
}
```

- [ ] **Step 5: Run it, expect pass:**

Run (from `frontend/`): `npx jest src/app/reader/reader-api.spec.ts`
Expected: PASS.

- [ ] **Step 6: Commit:**

```bash
git add frontend/src/app/reader/models.ts frontend/src/app/reader/reader-api.ts frontend/src/app/reader/reader-api.spec.ts
git commit -m "feat(reader): FeedPreview model + previewFeed API method"
```

---

### Task 8: Dialog preview cards (auto-load, parallel)

**Files:**
- Modify: `frontend/src/app/reader/add-feed/add-feed-dialog.component.ts`
- Modify: `frontend/src/app/reader/add-feed/add-feed-dialog.component.spec.ts`

**Behavior:**
- When `candidates` is populated (in `subscribe()`'s candidate branch), reset a `previews` signal and fire `previewFeed(c.url)` for every candidate in parallel, writing each result into the signal keyed by URL.
- Preview state per URL: `{ status: 'loading' } | { status: 'error' } | { status: 'ok'; preview: FeedPreview }`.
- Render each candidate as a **card**: heading (`preview.title || c.title || c.url`) + item count; badges for content tier (**Full text** / **Summary only** / **Titles only**) and images (**With images** / **No images**); up to 3 sample headlines with dates; a **Subscribe** button that calls `pick(c.url)`. Loading → a muted "Loading preview…" placeholder; error → a muted "Preview unavailable" note. The Subscribe button is always present regardless of preview state.

- [ ] **Step 1: Update the component TypeScript.** Add the state type and signal, populate on candidates, and add a helper for the tier label:

```ts
import { FeedCandidate, FeedPreview, SubscriptionDto } from '../models';

type PreviewState =
  | { status: 'loading' }
  | { status: 'error' }
  | { status: 'ok'; preview: FeedPreview };

// in the class:
readonly previews = signal<Record<string, PreviewState>>({});

private loadPreviews(candidates: FeedCandidate[]): void {
  this.previews.set(Object.fromEntries(candidates.map((c) => [c.url, { status: 'loading' }])));
  for (const c of candidates) {
    this.api.previewFeed(c.url).subscribe({
      next: (r) => this.previews.update((m) => ({ ...m, [c.url]: { status: 'ok', preview: r.feed } })),
      error: () => this.previews.update((m) => ({ ...m, [c.url]: { status: 'error' } })),
    });
  }
}

contentLabel(content: FeedPreview['content']): string {
  return content === 'full' ? 'Full text' : content === 'summary' ? 'Summary only' : 'Titles only';
}
```

In `subscribe()`'s else branch, after `this.candidates.set(res.candidates); this.searched.set(true);`, add `this.loadPreviews(res.candidates);`.

- [ ] **Step 2: Update the template** — replace the `.candidates` `<ul>` block with cards. Sketch (adapt classes/styles to the existing token vocabulary already in the component):

```html
@if (candidates().length) {
  <p class="hint">We found these feeds — pick one:</p>
  <ul class="candidates">
    @for (c of candidates(); track c.url) {
      @let state = previews()[c.url];
      <li class="card">
        <div class="card-head">
          <span class="card-title">
            {{ (state?.status === 'ok' ? state.preview.title : null) || c.title || c.url }}
          </span>
          @if (state?.status === 'ok') {
            <span class="count">{{ state.preview.itemCount }} items</span>
          }
        </div>

        @if (state?.status === 'loading') {
          <p class="muted">Loading preview…</p>
        } @else if (state?.status === 'error') {
          <p class="muted">Preview unavailable</p>
        } @else if (state?.status === 'ok') {
          <div class="badges">
            <span class="badge">{{ contentLabel(state.preview.content) }}</span>
            <span class="badge">{{ state.preview.hasImages ? 'With images' : 'No images' }}</span>
          </div>
          @if (state.preview.items.length) {
            <ul class="samples">
              @for (it of state.preview.items.slice(0, 3); track it.title) {
                <li>{{ it.title }}</li>
              }
            </ul>
          } @else {
            <p class="muted">No recent items</p>
          }
        }

        <button type="button" class="subscribe" (click)="pick(c.url)">Subscribe</button>
      </li>
    }
  </ul>
}
```

Add matching styles (`.card`, `.card-head`, `.badge`, `.samples`, `.muted`, `.subscribe`) using the existing `var(--…)` tokens already in the component's `styles`. Keep the `.subscribe` button visually the primary action per card.

> `@let` with a discriminated union: if template narrowing on `state.status` fights the Angular type-checker, read the union member into locals or add a tiny `asOk(state)` helper. Keep the Subscribe button outside all `@if` branches so it always renders.

- [ ] **Step 3: Update the spec** `add-feed-dialog.component.spec.ts`. Extend the existing candidate-flow test:
  - After the subscribe POST returns `{ candidates: [{url:'https://f/rss', title:'RSS'}, {url:'https://f/atom', title:'ATOM'}] }`, expect **two** `POST /api/feeds/preview` requests (one per candidate URL).
  - Flush one with a full-text+images preview and assert the card shows "Full text" and "With images" and a sample headline.
  - Flush the other with an error (500) and assert its card shows "Preview unavailable" **and** still renders a Subscribe button.
  - Click a Subscribe button and assert it issues `POST /api/subscriptions {url}` and closes on the `{subscription}` response.

  Mirror the existing spec's TestBed/HttpTestingController setup (see `add-feed-dialog.component.spec.ts` and `reader-shell.component.spec.ts` for the DialogRef mock + `expectOne`/`flush` patterns).

- [ ] **Step 4: Run the spec, expect failure then implement to green:**

Run (from `frontend/`): `npx jest src/app/reader/add-feed/add-feed-dialog.component.spec.ts`
Expected: PASS after implementation.

- [ ] **Step 5: Full frontend suite + lint:**

Run (from `frontend/`): `npx jest` and the project's lint (`npm run lint` if present).
Expected: PASS.

- [ ] **Step 6: Commit:**

```bash
git add frontend/src/app/reader/add-feed/
git commit -m "feat(add-feed): preview candidate feeds as comparison cards"
```

---

### Task 9: Quality gates, live verification, finish

**Files:** none (verification + wrap-up).

- [ ] **Step 1: Backend quality gates** — run the project's standard gates on the changed backend files: cs-fixer (check), phpstan, phpmd (must be clean for every touched `src` file), and PhpStorm inspections via the MCP `lint_files` on the changed PHP (block on ERROR/WARNING). Fix anything they flag.

- [ ] **Step 2: Full backend suite in Docker:**

Run: `docker compose exec -T php php bin/phpunit`
Expected: all green (existing + new).

- [ ] **Step 3: Full frontend suite + build:**

Run (from `frontend/`): `npx jest` and `npm run build`
Expected: green build, all specs pass.

- [ ] **Step 4: Live check** — with the Docker stack + Angular dev server up, open the Add-a-feed dialog and enter a multi-feed site (e.g. `https://www.tagesschau.de`). Confirm: candidates render as cards, each auto-loads a preview in parallel, badges + sample headlines distinguish the feeds, a failed preview degrades to "Preview unavailable" with Subscribe still working, and Subscribe creates the subscription and closes the dialog. Watch `backend` dev.log for deprecations/errors during the fetches.

- [ ] **Step 5: Update memory** — note in the native-iOS-readiness memory that `POST /api/feeds/preview` is a stateless bearer-auth JSON endpoint (native-ready), and in the feed-reader-plan-progress memory that feed-preview shipped on `feature/feed-preview`.

- [ ] **Step 6: Final review + finish** — dispatch a final code review over the whole branch diff, address findings, then use the **finishing-a-development-branch** skill. Do NOT merge without explicit user instruction (standing rule): present the merge options and wait.

---

## Self-review notes

- **Spec coverage:** endpoint (Task 6), reuse of guarded fetch+parse (Task 5), thorough image detection across all four formats (Tasks 1–4), content-tier verdict + snippet/textLength (Task 5), rate limiting (Task 6), auto parallel preview cards with graceful failure (Task 8), models/api (Task 7). Testing and gates in every task + Task 9.
- **No schema/migration:** `imageUrl` lives only on the parse-time `ParsedEntry`; the ingest pipeline ignores the extra named arg (it constructs `ParsedEntry` by name and simply doesn't read `imageUrl`). Confirm no code positionally reconstructs `ParsedEntry` — all observed callers use named args.
- **Type consistency:** backend `content` is `'full'|'summary'|'title-only'`; frontend union matches. `FeedPreview`/`FeedPreviewItem` field names identical across `FeedPreviewJson`, the TS models, and the tests.
- **Verdict plumbing:** Task 5 explicitly calls out threading sampled tiers into `verdict()` rather than leaving the placeholder — implementer must not ship the `return 'title-only';` stub.
