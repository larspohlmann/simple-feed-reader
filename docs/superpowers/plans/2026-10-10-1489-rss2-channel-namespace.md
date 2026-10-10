# RSS 2.0 Channel in the Dialect Namespace — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** `Rss2Parser::parseFeed` reads `<channel>` as a direct core child of the `<rss>` root, so a `<channel>` in a foreign namespace, or nested deeper, is never taken as the feed's channel.

**Architecture:** In `parseFeed`, replace the document-wide, namespace-blind `$skeleton->getElementsByTagName('channel')->item(0)` with `CoreElement::inOwnNamespace($root)->child('channel')` (#1484, #1487). The skeleton `StreamedFeedDocument` builds keeps namespaces (`createElementNS`), so the root's namespace is the dialect's: none, or a default such as `http://backend.userland.com/rss2`. A document with no root, or no core channel under it, still throws `FeedParseException('RSS document without <channel>')`.

**Measured before planning:** 235 dev-database feeds; 203 have an `<rss>` root. Every one of them has exactly one `<channel>`, and it is a direct child of the root in the root's namespace. A corpus baseline from `develop` (titles, site URLs, descriptions, images, entries) is saved for the before/after diff.

**Spec:** GitHub issue #1489.

## Global Constraints

- CLAUDE.md Clean Code rules; no new comments.
- Commit format: `fix(#1489): <lower-case summary>`; no attribution lines. Run from `backend/`.

---

### Task 1: the channel is a core child of the root

**Files:**
- Modify: `backend/src/Service/Parser/FeedFormatParser/Rss2Parser.php` (`parseFeed`)
- Test: `backend/tests/Service/Parser/FeedFormatParser/Rss2ParserTest.php`

- [ ] **Step 1: Failing tests.** Add `use App\Service\Parser\Exception\FeedParseException;` and append:

```php
    public function testAChannelInAnotherNamespaceIsNotTheFeedsChannel(): void
    {
        $feed = FeedFormatParsers::feed(<<<'XML'
            <rss version="2.0">
              <channel xmlns="urn:example:other"><title>Decoy</title></channel>
              <channel><title>Core</title></channel>
            </rss>
            XML);

        self::assertSame('Core', $feed->title);
    }

    public function testANestedChannelIsNotTheFeedsChannel(): void
    {
        $feed = FeedFormatParsers::feed(<<<'XML'
            <rss version="2.0" xmlns:x="urn:example:other">
              <x:meta><channel><title>Decoy</title></channel></x:meta>
              <channel><title>Core</title></channel>
            </rss>
            XML);

        self::assertSame('Core', $feed->title);
    }

    public function testADocumentWithoutAChannelIsAParseError(): void
    {
        $document = new \DOMDocument();
        $document->loadXML('<rss version="2.0"/>');

        $this->expectException(FeedParseException::class);

        FeedFormatParsers::rss2()->parseFeed($document, []);
    }
```

In `testDescriptionIsReadInADefaultNamespacedRssDocument`, also assert `self::assertSame('Blog', $feed->title);`. The channel there sits in the default namespace and must still be found.

Run: `php bin/phpunit tests/Service/Parser/FeedFormatParser/Rss2ParserTest.php`. Expected: the two decoy tests FAIL (`'Decoy'` instead of `'Core'`), and the other two PASS already.

- [ ] **Step 2: Implement.** In `Rss2Parser::parseFeed` replace

```php
        $channelElement = $skeleton->getElementsByTagName('channel')->item(0);
        if (!$channelElement instanceof \DOMElement) {
            throw new FeedParseException('RSS document without <channel>');
        }
        $channel = CoreElement::inOwnNamespace($channelElement);
```

with

```php
        $root = $skeleton->documentElement;
        $channelElement = $root === null ? null : CoreElement::inOwnNamespace($root)->child('channel');
        if ($channelElement === null) {
            throw new FeedParseException('RSS document without <channel>');
        }
        $channel = CoreElement::inOwnNamespace($channelElement);
```

`$channelElement` still feeds `PodcastArtwork::of($channelElement)` below, unchanged.

- [ ] **Step 3: Run** `php bin/phpunit tests/Service/Parser`. Expected: PASS.

- [ ] **Step 4: Corpus diff.** Re-run the corpus script over the 235 feeds on this branch and diff it against the `develop` baseline. Expected: identical.

- [ ] **Step 5: Commit.** `git commit -m "fix(#1489): the rss 2.0 channel is a core child of the root"`

### Task 2: Gates

- [ ] Run `composer cs`, `stan`, `md`, `tramp` and PhpStorm `lint_files` on the changed files. Then `composer test:parallel`, `docker compose exec php composer test` (repo root) and `composer infection:diff`. Kill any escaped mutant on a touched line.

---

## Execution notes

- The plan held as written. Both decoy tests failed first with `'Decoy'`, and the missing-channel and default-namespace assertions passed on `develop` already.
- Corpus: the parser output for all 235 feeds (224 parsed), covering titles, site URLs, descriptions, images and entries, is byte-identical between `develop` and the branch.
- Gates: `infection:diff` killed 2/2 mutants. Both suites pass with 7686 tests each.
