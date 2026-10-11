# Social Post Preview Card Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** A quoted social-media post shows as a link card in the reader and the magazine (#1525).

**Architecture:** Backend only. Each source gets a card through the path it already has:
- **Bluesky:** a quote becomes a `LinkCardContent` at ingest, from AppView data the renderer already reads. It replaces the `figure.quote-post` HTML.
- **Mastodon:** a `RE:` quote link stops being excluded from shared links, so the existing Open Graph fetch cards it. Once carded, its `RE:` line leaves the body.

The frontend already renders `linkCards`.

**Tech Stack:** Symfony 7.4 / PHP 8.4, PHPUnit 12, `Dom\HTMLDocument`.

**Spec:** `docs/superpowers/specs/2026-10-11-1525-social-post-preview-card-design.md`

## Global Constraints

- Branch `feature/1525-social-post-preview-card`. Commits are `type(#1525): <what>`, with no attribution lines.
- **Backend gates**, run from `backend/`:
  - `composer check` (cs, stan, tramp)
  - `composer md`
  - `php bin/phpunit <path>` for the files you touch
- **Clean Code rules from `CLAUDE.md`:**
  - Intent-revealing names; no single-letter or abbreviated names.
  - `final` classes. Static-only helpers live in `Support/`, with a private constructor.
  - No boolean flag parameters.
- **Comments:**
  - Default to none. Write one only where a future reader would otherwise get the code wrong.
  - One line, three at most.
  - Delete a comment that restates code.
- No frontend change. No migration. No new database column.
- A quote card never sets the entry's lead image.

## File map

- `backend/src/Service/Bluesky/Model/RenderedEmbedModel.php`: `?LinkCardContent $linkCard` becomes `list<LinkCardContent> $linkCards`.
- `backend/src/Service/Bluesky/PostEmbedRenderer.php`: `quote()` returns a card, and `quotedImage()` is new.
- `backend/src/Service/Bluesky/EntryEmbedWriter/EntryEmbedWriter.php`: stores every card and strips each card's trailing URL.
- Create `backend/src/Service/LinkCard/Support/QuoteLine.php`: `isQuote(Element)` (moved from `SharedLinks`) and `removedFrom(string, string)`.
- `backend/src/Service/LinkCard/Support/SharedLinks.php`: drops the quote exclusion.
- `backend/src/Service/LinkCard/PostLinkCards.php`: after storing a row's cards, strips the carded quote line and re-derives the summary.
- **Tests:**
  - `backend/tests/Service/Bluesky/PostEmbedRendererTest.php`
  - `backend/tests/Service/Bluesky/EntryEmbedWriter/EntryEmbedWriterTest.php`
  - `backend/tests/Service/LinkCard/Support/SharedLinksTest.php`
  - create `backend/tests/Service/LinkCard/Support/QuoteLineTest.php`
  - `backend/tests/Service/LinkCard/PostLinkCardsTest.php`

---

### Task 1: A Bluesky quote is a link card

**Files:**
- Modify: `backend/src/Service/Bluesky/Model/RenderedEmbedModel.php`
- Modify: `backend/src/Service/Bluesky/PostEmbedRenderer.php:129-145` (`quote`), and add `quotedImage`
- Modify: `backend/src/Service/Bluesky/EntryEmbedWriter/EntryEmbedWriter.php:35-39`
- Test: `backend/tests/Service/Bluesky/PostEmbedRendererTest.php`, `backend/tests/Service/Bluesky/EntryEmbedWriter/EntryEmbedWriterTest.php`

**Interfaces:**
- Produces:
  - `RenderedEmbedModel::$linkCards` (`list<LinkCardContent>`, in post order)
  - `RenderedEmbedModel::card(LinkCardContent $card, ?DeclaredImageModel $leadImage): self`, unchanged signature, now a one-card list
  - `followedBy()` concatenates the card lists.

- [ ] **Step 1: Update the renderer tests to the new shape (failing).** In `PostEmbedRendererTest`:

  1. Every `$embed->linkCard` / `$rendered?->linkCard` / `$rendered->linkCard` assertion becomes a list assertion:
     - `assertEquals(new LinkCardContent(...), $x->linkCard)` → `assertEquals([new LinkCardContent(...)], $x->linkCards)`
     - `assertNull($x->linkCard)` → `assertSame([], $x->linkCards)`
     - `$rendered?->linkCard?->siteName` → `$rendered?->linkCards[0]->siteName` (lines 266, 302, 303 likewise via `linkCards[0]`)
  2. Replace `testAQuoteShowsTheQuotedTextAndAuthorButNotTheQuotedPostsOwnEmbed` with:

```php
    public function testAQuoteIsACardOfTheQuotedAuthorAndTextWithNoHtmlAndNoLeadImage(): void
    {
        $embed = $this->rendered('record');

        self::assertSame('', $embed->html);
        self::assertEquals([new LinkCardContent(
            self::MOTHER_JONES . '3mxhvfsp7n32t',
            'Mother Jones (@motherjones.com)',
            "Big money has long dominated American politics, with wealthy donors, corporations, and special"
                . " interests dumping tons of cash into presidential and congressional elections. \n\nBut this year,"
                . ' the quid pro quo of campaign money for preferential government treatment is more brazen than'
                . ' ever before.',
            null,
            'bsky.app',
        )], $embed->linkCards);
        self::assertNull($embed->leadImage);
        self::assertSame([], $embed->media);
    }
```

  3. Replace `testAQuoteWithMediaRendersTheQuoteThenTheMedia` with:

```php
    public function testAQuoteWithMediaGivesTheQuoteCardThenTheMediasCard(): void
    {
        $embed = $this->rendered('record-with-media');

        self::assertSame('', $embed->html);
        self::assertSame(
            [
                'https://bsky.app/profile/did:plc:rhgbyqyye2vpydw7c75j4wnr/post/3mxh76vhjfk2k',
                self::REDDIT_CARD,
            ],
            array_map(static fn (LinkCardContent $card): string => $card->url, $embed->linkCards),
        );
        self::assertSame('Amy Littlefield (@amylittlefield.bsky.social)', $embed->linkCards[0]->title);
        self::assertSame(self::REDDIT_THUMB, $embed->leadImage?->url);
    }
```

  4. Add a data-provided test for the quoted post's image:

```php
    /** @return iterable<string, array{array<mixed>, ?string}> */
    public static function quotedEmbeds(): iterable
    {
        yield 'images' => [[
            '$type' => 'app.bsky.embed.images#view',
            'images' => [
                ['thumb' => 'https://cdn.example/first-thumb', 'fullsize' => 'https://cdn.example/first-full'],
                ['thumb' => 'https://cdn.example/second-thumb'],
            ],
        ], 'https://cdn.example/first-thumb'];
        yield 'video' => [[
            '$type' => 'app.bsky.embed.video#view',
            'playlist' => 'https://video.example/p.m3u8',
            'thumbnail' => 'https://video.example/thumbnail.jpg',
        ], 'https://video.example/thumbnail.jpg'];
        yield 'external' => [[
            '$type' => 'app.bsky.embed.external#view',
            'external' => ['uri' => 'https://example.com/a', 'title' => 'A', 'thumb' => 'https://cdn.example/a'],
        ], 'https://cdn.example/a'];
        yield 'quote with media' => [[
            '$type' => 'app.bsky.embed.recordWithMedia#view',
            'media' => ['$type' => 'app.bsky.embed.images#view', 'images' => [['thumb' => 'https://cdn.example/m']]],
            'record' => ['record' => []],
        ], 'https://cdn.example/m'];
        yield 'nested quote alone' => [['$type' => 'app.bsky.embed.record#view', 'record' => []], null];
        yield 'an http image' => [[
            '$type' => 'app.bsky.embed.images#view',
            'images' => [['thumb' => 'http://cdn.example/insecure']],
        ], null];
    }

    /** @param array<mixed> $quotedEmbed */
    #[DataProvider('quotedEmbeds')]
    public function testAQuoteCardShowsTheQuotedPostsFirstImage(array $quotedEmbed, ?string $expected): void
    {
        $rendered = $this->renderer()->render(self::post([
            '$type' => 'app.bsky.embed.record#view',
            'record' => [
                '$type' => 'app.bsky.embed.record#viewRecord',
                'uri' => 'at://did:plc:c/app.bsky.feed.post/d',
                'author' => ['handle' => 'someone.example'],
                'value' => ['$type' => 'app.bsky.feed.post', 'text' => 'Look.'],
                'embeds' => [$quotedEmbed],
            ],
        ]));

        self::assertSame($expected, $rendered?->linkCards[0]->imageUrl);
        self::assertNull($rendered->leadImage);
    }
```

  5. Replace `testAQuotedAuthorsHostileNameAndTextAreEscaped` with a test that the card holds the raw text. Escaping is the frontend's job, as it is for every link card:

```php
    public function testAQuotedAuthorsHostileNameAndTextStayPlainTextInTheCard(): void
    {
        $rendered = $this->renderer()->render(self::post([
            '$type' => 'app.bsky.embed.record#view',
            'record' => [
                '$type' => 'app.bsky.embed.record#viewRecord',
                'uri' => 'at://did:plc:c/app.bsky.feed.post/d',
                'author' => ['handle' => 'evil.example', 'displayName' => '<img src=x onerror=alert(1)>'],
                'value' => ['$type' => 'app.bsky.feed.post', 'text' => "one <b>\ntwo"],
            ],
        ]));

        self::assertSame('', $rendered?->html);
        self::assertSame('<img src=x onerror=alert(1)> (@evil.example)', $rendered->linkCards[0]->title);
        self::assertSame("one <b>\ntwo", $rendered->linkCards[0]->description);
    }
```

  6. Replace `testAQuotedAuthorWithoutADisplayNameShowsTheHandle` with:

```php
    public function testAQuotedAuthorWithoutADisplayNameIsTitledByHandleAndBlankTextIsNoDescription(): void
    {
        $rendered = $this->renderer()->render(self::post([
            '$type' => 'app.bsky.embed.record#view',
            'record' => [
                '$type' => 'app.bsky.embed.record#viewRecord',
                'uri' => 'at://did:plc:c/app.bsky.feed.post/d',
                'author' => ['handle' => 'pantspants.bsky.social', 'displayName' => ' '],
                'value' => ['$type' => 'app.bsky.feed.post', 'text' => ''],
            ],
        ]));

        self::assertEquals([new LinkCardContent(
            'https://bsky.app/profile/did:plc:c/post/d',
            '@pantspants.bsky.social',
            null,
            null,
            'bsky.app',
        )], $rendered?->linkCards);
    }
```

  7. In `sanitizedParts()`, delete the `'quote'` yield. A quote renders no HTML now.

  Keep the fallback provider cases ("a quoted post that is gone" etc.) unchanged. They must still render `FALLBACK`.

- [ ] **Step 2: Update the writer tests (failing).** In `EntryEmbedWriterTest`, replace `testAQuoteWithALinkCardKeepsTheQuoteInTheBodyAndStoresTheCard` with:

```php
    public function testAQuoteLeavesNoFigureAndIsStoredAsACardBeforeTheMediasCard(): void
    {
        $entry = $this->entry('<p>Look.</p>');

        $this->writer()->fill($entry, Bluesky::post('record-with-media'));
        $this->entityManager->flush();

        self::assertSame('<p>Look.</p>', $entry->getContentHtml());
        self::assertSame(
            ['Amy Littlefield (@amylittlefield.bsky.social)', 'From the IAmA community on Reddit'],
            array_map(static fn (LinkCardContent $card): string => $card->title, $this->storedCards($entry)),
        );
    }

    public function testAQuoteAloneIsStoredAsTheOnlyCardAndSetsNoImage(): void
    {
        $entry = $this->entry('<p>Watch this.</p>');

        $this->writer()->fill($entry, Bluesky::post('record'));
        $this->entityManager->flush();

        self::assertSame('<p>Watch this.</p>', $entry->getContentHtml());
        self::assertNull($entry->getImageUrl());
        self::assertSame(
            ['Mother Jones (@motherjones.com)'],
            array_map(static fn (LinkCardContent $card): string => $card->title, $this->storedCards($entry)),
        );
    }
```

- [ ] **Step 3: Run the tests to see them fail.**

Run: `cd backend && php bin/phpunit tests/Service/Bluesky`
Expected: failures and errors on `linkCards` (undefined property) and on the quote tests.

- [ ] **Step 4: Implement `RenderedEmbedModel`.**

```php
final readonly class RenderedEmbedModel
{
    /**
     * @param list<ParsedMediumModel> $media
     * @param list<LinkCardContent>   $linkCards in post order
     */
    public function __construct(
        public string $html,
        public ?DeclaredImageModel $leadImage = null,
        public array $media = [],
        public array $linkCards = [],
    ) {
    }

    public static function card(LinkCardContent $card, ?DeclaredImageModel $leadImage): self
    {
        return new self('', $leadImage, linkCards: [$card]);
    }

    public function followedBy(self $next): self
    {
        return new self(
            $this->html . $next->html,
            $this->leadImage ?? $next->leadImage,
            [...$this->media, ...$next->media],
            [...$this->linkCards, ...$next->linkCards],
        );
    }
}
```

- [ ] **Step 5: Implement `quote()` and `quotedImage()` in `PostEmbedRenderer`.** Replace `quote()`:

```php
    private function quote(JsonNodeModel $record): ?RenderedEmbedModel
    {
        $quotedUrl = AtPostUri::webUrl($record->string('uri') ?? '');
        $value = $record->node('value');
        if ($record->type() !== self::VIEW_RECORD || $value->type() !== self::POST_RECORD || $quotedUrl === null) {
            return null;
        }
        $card = StorableLinkCard::of(new LinkCardContent(
            $quotedUrl,
            self::authorName($record->node('author')),
            $value->string('text'),
            self::quotedImage($record->nodes('embeds')[0] ?? JsonNodeModel::of(null)),
            HostKey::forUrl($quotedUrl),
        ));

        return $card === null ? null : RenderedEmbedModel::card($card, null);
    }

    private static function quotedImage(JsonNodeModel $embed): ?string
    {
        return match ($embed->type()) {
            self::IMAGES => ($embed->nodes('images')[0] ?? JsonNodeModel::of(null))->string('thumb'),
            self::VIDEO => $embed->string('thumbnail'),
            self::EXTERNAL => $embed->node('external')->string('thumb'),
            self::RECORD_WITH_MEDIA => self::quotedImage($embed->node('media')),
            default => null,
        };
    }
```

`StorableLinkCard::of` drops an image that is not https, so `quotedImage` returns the raw URL. Remove the imports that are now unused: `ParagraphedText`, and `HtmlEscape` only if nothing else uses it (`images()` and `linkParagraph()` still do, so it stays).

- [ ] **Step 6: Store every card in `EntryEmbedWriter::fill`.** Replace the `if ($embed->linkCard !== null) { … }` block:

```php
        $body = $entry->getContentHtml() ?? '';
        foreach ($embed->linkCards as $card) {
            $body = TrailingUrl::removedFrom($body, $card->url);
        }
        if ($embed->linkCards !== []) {
            $this->linkCards->add($entry, $embed->linkCards);
        }
```

- [ ] **Step 7: Run the tests to see them pass.**

Run: `cd backend && php bin/phpunit tests/Service/Bluesky tests/Service/Completion tests/Service/LinkCard`
Expected: OK.

- [ ] **Step 8: Gates for the touched files.**

Run, from `backend/`:
- `composer cs`
- `vendor/bin/phpstan analyse src/Service/Bluesky tests/Service/Bluesky --memory-limit=1G`
- `composer md`

Expected: clean. Fix what they report.

- [ ] **Step 9: Commit.**

```bash
git add backend/src/Service/Bluesky backend/tests/Service/Bluesky
git commit -m "feat(#1525): a Bluesky quote is stored as a link card, not as body HTML"
```

---

### Task 2: A Mastodon `RE:` quote gets a link card

**Files:**
- Create: `backend/src/Service/LinkCard/Support/QuoteLine.php`
- Modify: `backend/src/Service/LinkCard/Support/SharedLinks.php` (`isShare`, delete `isQuote`, drop the now-unused imports)
- Modify: `backend/src/Service/LinkCard/PostLinkCards.php` (`store`)
- Create: `backend/tests/Service/LinkCard/Support/QuoteLineTest.php`
- Modify: `backend/tests/Service/LinkCard/Support/SharedLinksTest.php`, `backend/tests/Service/LinkCard/PostLinkCardsTest.php`

**Interfaces:**
- Produces:
  - `QuoteLine::isQuote(Dom\Element $anchor): bool`: true when the collapsed text of the anchor's enclosing `<p>` before the anchor is exactly `RE:`.
  - `QuoteLine::removedFrom(string $html, string $url): string`
- Consumes: `PageCardReader::read($sharedUrl)` returns a card whose `url` is `$sharedUrl` (already true).

- [ ] **Step 1: Write `QuoteLineTest` (failing).**

```php
<?php

declare(strict_types=1);

namespace App\Tests\Service\LinkCard\Support;

use App\Service\LinkCard\Support\QuoteLine;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class QuoteLineTest extends TestCase
{
    private const string QUOTED = 'https://q.test/@a/1';

    /** @return iterable<string, array{string, string}> */
    public static function bodies(): iterable
    {
        yield 'a quote line of its own' => [
            '<p>RE: <a href="https://q.test/&#64;a/1"><span>https://</span><span>q.test/&#64;a/1</span></a></p>'
                . '<p>Reposting.</p>',
            '<p>Reposting.</p>',
        ];
        yield 'a non-breaking space' => [
            '<p>RE:&nbsp;<a href="https://q.test/@a/1">https://q.test/@a/1</a></p><p>Yes.</p>',
            '<p>Yes.</p>',
        ];
        yield 'text after the quote in the same paragraph' => [
            '<p>RE: <a href="https://q.test/@a/1">https://q.test/@a/1</a><br>Agreed.</p>',
            '<p><br>Agreed.</p>',
        ];
        yield 'an emoji image left in the paragraph' => [
            '<p>RE: <a href="https://q.test/@a/1">https://q.test/@a/1</a> <img alt=":bear:" src="https://e.test/b.png"></p>',
            '<p> <img alt=":bear:" src="https://e.test/b.png"></p>',
        ];
    }

    #[DataProvider('bodies')]
    public function testRemovesTheQuoteLineOfThatUrl(string $html, string $expected): void
    {
        self::assertSame($expected, QuoteLine::removedFrom($html, self::QUOTED));
    }

    /** @return iterable<string, array{string}> */
    public static function untouched(): iterable
    {
        yield 'another url quoted' => ['<p>RE: <a href="https://q.test/@b/2">https://q.test/@b/2</a></p><p>x</p>'];
        yield 'the url shared, not quoted' => [
            '<p>See <a href="https://q.test/@a/1">https://q.test/@a/1</a></p>',
        ];
        yield 'no paragraph' => ['RE: <a href="https://q.test/@a/1">https://q.test/@a/1</a>'];
    }

    #[DataProvider('untouched')]
    public function testLeavesABodyWithoutThatQuoteByteForByte(string $html): void
    {
        self::assertSame($html, QuoteLine::removedFrom($html, self::QUOTED));
    }
}
```

The expected outputs are `innerHTML` serialisations. If the serialiser writes `&nbsp;` or attribute order differently from the expectation, adjust the *expected* string to what `Dom\HTMLDocument` produces. Never adjust the rule. Confirm the removed parts are gone and the kept parts survive.

- [ ] **Step 2: Update `SharedLinksTest` (failing).** The two quote cases now share the quoted URL:

```php
        yield 'quoted post' => [
            '<p>RE: <a href="https://social.souverain.ovh/&#64;souverain/116830136337222206" target="_blank"'
            . ' rel="noopener noreferrer" translate="no"><span>https://</span>'
            . '<span>social.souverain.ovh/&#64;souverai</span><span>n/116830136337222206</span></a></p>'
            . '<p>Nous formons un mouvement ✊🏾</p>',
            ['https://social.souverain.ovh/@souverain/116830136337222206'],
        ];
```

and

```php
        yield 'quote with a non-breaking space' => [
            '<p>RE:&nbsp;<a href="https://q.test/@a/1">https://q.test/@a/1</a></p>',
            ['https://q.test/@a/1'],
        ];
```

Add a case showing that the quote comes first:

```php
        yield 'a quote then a shared link' => [
            '<p>RE: <a href="https://q.test/@a/1">https://q.test/@a/1</a></p>'
            . '<p>Read <a href="https://example.com/a">https://example.com/a</a></p>',
            ['https://q.test/@a/1', 'https://example.com/a'],
        ];
```

- [ ] **Step 3: Add the `PostLinkCardsTest` cases (failing).**

```php
    public function testACardedQuoteLeavesTheBodyAndTheSummaryIsDerivedAgain(): void
    {
        $post = $this->queuedPost(1, '<p>RE: <a href="https://q.example/@a/1">https://q.example/@a/1</a></p>'
            . '<p>Reposting for the morning crew</p>');
        $post->setSummary('RE: https://q.example/@a/1 Reposting for the morning crew');
        $this->entityManager->flush();

        $this->postLinkCards->complete($this->claimable());
        $this->entityManager->refresh($post);

        self::assertSame(['Card for https://q.example/@a/1'], $this->cardTitles($post));
        self::assertSame('<p>Reposting for the morning crew</p>', $post->getContentHtml());
        self::assertSame('Reposting for the morning crew', $post->getSummary());
    }

    public function testAQuoteWhosePageFailsKeepsItsLine(): void
    {
        $body = '<p>RE: <a href="https://q.example/missing">https://q.example/missing</a></p><p>Look.</p>';
        $post = $this->queuedPost(1, $body);

        $this->postLinkCards->complete($this->claimable());
        $this->entityManager->refresh($post);

        self::assertSame([], $this->cardTitles($post));
        self::assertSame($body, $post->getContentHtml());
    }
```

- [ ] **Step 4: Run the tests to see them fail.**

Run: `cd backend && php bin/phpunit tests/Service/LinkCard`
Expected: `QuoteLine` class not found; the two `SharedLinks` quote cases fail with `[]`; the first `PostLinkCards` case fails on the content HTML.

- [ ] **Step 5: Create `QuoteLine`.**

```php
<?php

declare(strict_types=1);

namespace App\Service\LinkCard\Support;

use App\Service\Html\Support\HtmlDocumentParser;
use App\Service\Html\Support\TextNodes;
use App\Service\Text\Support\Whitespace;
use Dom\Element;

/** A Mastodon quote: a link whose paragraph reads "RE:" before it. */
final class QuoteLine
{
    private const string MARKER = 'RE:';

    public static function isQuote(Element $anchor): bool
    {
        $paragraph = $anchor->closest('p');

        return $paragraph !== null && Whitespace::collapse(self::textBefore($anchor, $paragraph)) === self::MARKER;
    }

    public static function removedFrom(string $html, string $url): string
    {
        $document = HtmlDocumentParser::parseFragment($html);
        foreach ($document->querySelectorAll('a[href]') as $anchor) {
            if ($anchor->getAttribute('href') === $url && self::isQuote($anchor)) {
                self::remove($anchor);

                return $document->body->innerHTML ?? $html;
            }
        }

        return $html;
    }

    private static function textBefore(Element $anchor, Element $paragraph): string
    {
        $before = '';
        foreach (TextNodes::in($paragraph) as $text) {
            if ($anchor->contains($text)) {
                break;
            }
            $before .= $text->data;
        }

        return $before;
    }

    private static function remove(Element $anchor): void
    {
        $paragraph = $anchor->closest('p');
        foreach (TextNodes::in($paragraph) as $text) {
            if ($anchor->contains($text)) {
                break;
            }
            $text->remove();
        }
        $anchor->remove();
        if ($paragraph !== null && $paragraph->firstElementChild === null && Whitespace::collapse($paragraph->textContent) === '') {
            $paragraph->remove();
        }
    }
}
```

`textBefore` and the loop in `remove` walk the same text; if PHPMD or review flags the duplication, extract a `textNodesBefore(Element $anchor, Element $paragraph): list<Dom\Text>` that both use. `TextNodes::in` returns a list built up front, so removing nodes while iterating it is safe. `Whitespace::collapse` already treats a non-breaking space as whitespace (the existing `RE:&nbsp;` case depends on it).

- [ ] **Step 6: Drop the exclusion from `SharedLinks`.** In `isShare`, delete the line `&& !self::isQuote($anchor);` (end the chain with `;` on the previous condition). Delete the private `isQuote` method. Remove the imports it alone used: `TextNodes` stays (used by `linkBareUrls`), and `Whitespace` goes if nothing else uses it. Update the class docblock to:

```php
/** The links a post shares, a quoted post's included, as opposed to the hashtags, mentions and words that merely link. */
```

- [ ] **Step 7: Strip the carded quote in `PostLinkCards::store`.** Replace the loop body that adds cards:

```php
        foreach ($held as $row) {
            if (!isset($carded[$row->getEntry()->requireId()])) {
                $this->storeCards($row->getEntry(), $cardsByRowId[$row->requireId()]);
            }
        }
```

and add:

```php
    /** @param list<LinkCardContent> $cards */
    private function storeCards(Entry $entry, array $cards): void
    {
        $this->linkCards->add($entry, $cards);
        $html = (string) $entry->getContentHtml();
        $withoutQuote = array_reduce(
            $cards,
            static fn (string $body, LinkCardContent $card): string => QuoteLine::removedFrom($body, $card->url),
            $html,
        );
        if ($withoutQuote !== $html) {
            $entry->setContentHtml($withoutQuote);
            $entry->setSummary(EntrySnippet::from($withoutQuote));
        }
    }
```

Imports: `App\Entity\Entry`, `App\Service\Ingest\Support\EntrySnippet`, `App\Service\LinkCard\Support\QuoteLine`. The existing `flush()` after the loop persists the entry change. `LinkCard → Ingest` is a new module edge. `composer stan` (`ServiceModuleCycleRule`) must stay green. If it reports a cycle, stop and report it rather than working around it.

- [ ] **Step 8: Run the tests to see them pass.**

Run: `cd backend && php bin/phpunit tests/Service/LinkCard tests/Service/Completion tests/Service/Text`
Expected: OK. `DerivedTitleTest` is unaffected: its `RE:` handling is its own pattern.

- [ ] **Step 9: Gates for the touched files.**

Run, from `backend/`:
- `composer cs`
- `vendor/bin/phpstan analyse src/Service/LinkCard tests/Service/LinkCard --memory-limit=1G`
- `composer md`

Expected: clean.

- [ ] **Step 10: Commit.**

```bash
git add backend/src/Service/LinkCard backend/tests/Service/LinkCard
git commit -m "feat(#1525): a Mastodon RE: quote gets a link card and leaves the post's text once carded"
```

---

### Task 3: Full gates, local conversion, real run (controller)

- [ ] **Step 1: Run the full backend gates.**
  - `cd backend && composer check && composer md && composer test:parallel`
  - `docker compose exec -T php composer test`
  - `composer infection:diff`
- [ ] **Step 2: Restart the Docker app so it runs the new code.** Clear the cache and restart the php and worker containers (see the memory notes on the stale DI container and the stale worker daemon).
- [ ] **Step 3: One-off local conversion.** Write a throwaway script under `backend/var/` (gitignored) that boots the kernel and does the following:
  - **Bluesky:** for each entry whose `content_html` contains `figure class="quote-post"`:
    - Cut the body at the first `<figure class="quote-post"`, `<figure class="post-images"`, `<figure class="post-video"` or fallback `<p><a href="https://bsky.app/…">View embedded content on Bluesky`.
    - Set `media` to empty and clear the image.
    - Fetch the post through `AppViewClient`, then call `EntryEmbedWriter::fill` and flush.
  - **Mastodon:** for each title-derived entry whose `content_html` starts with `<p>RE: <a`:
    - Delete its `link_card` rows.
    - Reset its `post_enrichment` row (`done_at`, `claimed_at` null), or create one of kind `link_cards`.
    - Run `PostLinkCards::complete` on those rows.
  - Run it in the php container, then delete it.
- [ ] **Step 4: Real run in the browser.**
  - Open `http://localhost:4200/?subscription=1745`: the quote posts show the quoted post as a card in the magazine, and the reader shows the card under the post text with no blockquote.
  - Open the two Mastodon feeds: a `RE:` post shows the quoted status as a card, and the headline no longer starts with "RE: https://".
- [ ] **Step 5: Scan today's dev log for errors** (`ls -t backend/var/log/dev-*.log | head -1`).
