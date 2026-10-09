# #1467 RSS Core Elements in Their Own Namespace Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** A prefixed extension element (`media:title`, `itunes:title`, `itunes:author`, `media:description`, `atom:link`, …) placed before an RSS 2.0 core element never shadows it.

**Architecture:** `XmlHelper` loses its "null = any namespace" wildcard: the namespace argument becomes required and exact, `null` meaning un-namespaced. RSS 2.0 core elements live in whatever namespace the RSS elements themselves use — none for a plain feed, the default namespace for `<rss xmlns="http://backend.userland.com/rss2">` — so a new `XmlHelper::childTextInOwnNamespace($parent, $localName)` reads a child in its parent's namespace. `Rss2Parser` and `FeedImageExtractor::fromRss2Channel` use it for every core element; `childTextOutsideMediaRss()` is deleted. The `atom:link` case keeps working because `atom:link` is in another namespace than `<channel>`. `dc:description` stops matching by accident and becomes an explicit fallback after `<description>`, the same pattern as `author` → `dc:creator` and `pubDate` → `dc:date`.

**Tech Stack:** PHP 8.4, DOM, PHPUnit 12.

**Spec:** GitHub issue #1467.

## Global Constraints

- CLAUDE.md Clean Code rules; `composer check`, `composer md` clean on touched `src` files; `infection:diff` gate.
- Keep `testPrefersTheRealLinkOverASelfReferencingAtomLink`, `testDescriptionIsReadInADefaultNamespacedRssDocument` and the default-namespace `FeedImageExtractorTest` case green unchanged.

---

### Task 1: Shadowing tests, exact namespaces, own-namespace reads

**Files:**
- Modify: `backend/src/Service/Parser/Support/XmlHelper.php`
- Modify: `backend/src/Service/Parser/FeedFormatParser/Rss2Parser.php`
- Modify: `backend/src/Service/Parser/Support/FeedImageExtractor.php`
- Test: `backend/tests/Service/Parser/FeedFormatParser/Rss2ParserTest.php`

**Interfaces:**
- Produces: `XmlHelper::childTextInOwnNamespace(\DOMElement $parent, string $localName): ?string`; `childText`, `childElement`, `childHttpUrl`, `childElements` take a required `?string $namespaceUri` (exact; `null` = un-namespaced).

- [ ] **Step 1: Failing tests** in `Rss2ParserTest` (use the existing `parseSingleItem()` helper, declaring the prefixes on the `<item>`):
  - `testAnExtensionTitleBeforeTheTitleDoesNotShadowIt` — `<media:title>Media</media:title><itunes:title>Itunes</itunes:title><title>Core</title>` → `title === 'Core'`.
  - `testAnItunesAuthorBeforeTheAuthorDoesNotShadowIt` — `<itunes:author>Itunes</itunes:author><author>Core</author>` → `author === 'Core'`.
  - `testAPrefixedLinkBeforeTheLinkDoesNotShadowIt` — item `<atom:link href="https://example.com/self">https://example.com/self</atom:link><link>https://example.com/a</link>` → `url === 'https://example.com/a'` (atom:link with text, so the old "first with text" rule can't save it).
  - `testAPrefixedGuidBeforeTheGuidDoesNotShadowIt` — `<foo:guid xmlns:foo="urn:x">other</foo:guid><guid>core-guid</guid>` → `guid === 'core-guid'`.
  - `testDublinCoreDescriptionFillsAnItemWithoutADescription` — `<dc:description>&lt;p&gt;Dc&lt;/p&gt;</dc:description>` only → `contentHtml === '<p>Dc</p>'`.
  - `testDescriptionWinsOverDublinCoreDescription` — `<dc:description>Dc</dc:description><description>&lt;p&gt;Body&lt;/p&gt;</description>` → `'<p>Body</p>'`.
  - `testAPrefixedChannelTitleBeforeTheTitleDoesNotShadowIt` — full feed, channel `<itunes:title>Itunes</itunes:title><title>Core</title>` → feed title `'Core'`.

- [ ] **Step 2:** `php bin/phpunit --filter Rss2ParserTest` — the shadowing tests FAIL (title 'Media', author 'Itunes', url self, guid 'other', channel 'Itunes'); the dc:description ones pass today by accident.

- [ ] **Step 3: XmlHelper.** Make `$namespaceUri` required (`?string`, no default) on `childText`, `childElement`, `childHttpUrl`, `childElements`; in `childElements` compare exactly (`$child->namespaceURI !== $namespaceUri` → continue). Docblock on `childElements`: "Direct children with this local name in exactly this namespace; null is no namespace." Drop the atom:link sentence from `childText`. Add:

```php
    /** RSS 2.0 core elements share their parent's namespace: none, or the document's default one. */
    public static function childTextInOwnNamespace(\DOMElement $parent, string $localName): ?string
    {
        return self::childText($parent, $localName, $parent->namespaceURI);
    }
```

Delete `childTextOutsideMediaRss()` and `outsideMediaRss()`.

- [ ] **Step 4: Rss2Parser.** Every former wildcard read becomes `XmlHelper::childTextInOwnNamespace(...)` (channel title/link/description; item title/link/guid/author/pubDate); description becomes `childTextInOwnNamespace($entry, 'description') ?? XmlHelper::childText($entry, 'description', self::DC_NS)`; `childHttpUrl($item, 'comments', $item->namespaceURI)`.

- [ ] **Step 5: FeedImageExtractor::fromRss2Channel.** `XmlHelper::childElements($channel, 'image', $channel->namespaceURI)` (the manual namespace `continue` goes) and `XmlHelper::childTextInOwnNamespace($image, 'url')`.

- [ ] **Step 6:** `php bin/phpunit tests/Service/Parser` all green; then the full suite `composer test:parallel`.

- [ ] **Step 7: Break-test** the core guard: temporarily make `childTextInOwnNamespace` pass `null` → the default-namespace tests must FAIL; restore by edit.

- [ ] **Step 8: Gates** `composer check`, `composer md` (touched files clean), `composer infection:diff`, MySQL leg `docker compose exec php composer test`.

- [ ] **Step 9: Commit** `fix(#1467): read rss core elements in their own namespace so prefixed elements no longer shadow them`.
