# Share a Text Selection as a Quote (#1970) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Selecting text in an article's body offers to share it as a quote with the article's link: through #1968's composer on a connected Bluesky or Mastodon account, and through the share menu everywhere else.

**Architecture:** The backend's `GET /api/social/link-composer` takes an optional `quote`, which flows `LinkTargetQuery → LinkTargetModel → SharedLinkModel`. `ComposerStartingText` writes the quote form and trims it at a word to the network's limit. A link that names a quotable post already opens a *quote* composer (#1972), and that composer starts with the selection too. Sending is unchanged. On the frontend an article-scoped `ArticleSelection` service turns `selectionchange` inside the article body into a `SelectedQuote`. Pure helpers turn a quote into plain text, a `#:~:text=` URL and filled share templates. The share menu gains a quote mode and a hint. A desktop bubble and a phone bar both render one `SelectionActionsComponent`: network buttons plus a compact share button that opens the same menu. Every network choice calls `PostActions.postLink(network, { url, title, quote })`, and the composer puts the caret before the quote.

**Tech Stack:** Symfony 7.4 / PHP 8.4, PHPUnit 12, Angular 20 (standalone, signals, zone change detection), CDK overlay/dialog, Transloco, Jest (jsdom).

**Spec:** `docs/superpowers/specs/2026-11-02-1970-share-selection-quote-design.md` (binding). Umbrella #1962; builds on #1968 (link post) and #1972 (a link naming a post becomes a quote). The executor reads both.

## Global Constraints

Copied from the spec (binding):

- **The composer always opens.** Every network choice — bubble, phone bar, share menu — opens #1968's composer prefilled with the quote. The reader adds their own text there; nothing is posted until **Send**.
- **Desktop bubble.** A small bubble appears under the last line of a selection inside the article body, ~150 ms after the selection settles, when the selection holds at least 3 words. It shows one button per network with a connected account, and a share button that opens the share menu in quote mode. It closes when the selection collapses, leaves the article body, or scrolls out of view. The selection's own highlight is the visual feedback; the bubble confirms it can be shared.
- **Share menu.** Without a selection, a muted hint sits under the network entries: “Select text in the article to share it as a quote” — only when at least one network entry exists. With a selection, the menu switches to quote mode: a snippet of the quote heads the menu, network entries read “Quote on Bluesky/Mastodon”, the system entry reads “Share quote…”, copy becomes “Copy quote with link”, and destinations that cannot carry text show dimmed with “(link only)” and share the plain link.
- **Phone.** On a coarse pointer no bubble (Safari's own callout owns that spot). A bar docks at the bottom of the article while a qualifying selection exists: word count, network buttons, share button. The last qualifying selection is remembered, so the share button still opens in quote mode when tapping it collapsed the selection. Needs a live check on the iPhone.
- **Post format.** The quote in typographic quotes, `“…”`, and nothing else; the caret sits before the quote so a comment can go first. Bluesky: text `“quote”`, the link in the card. Mastodon: `“quote”\n\nurl`. The title is dropped in quote mode — the card (Bluesky) or the link preview (Mastodon) carries it.
- **Text-fragment link.** For a quote from a single block, the shared URL gets `#:~:text=` so the link opens scrolled to the quote and highlighted. A quote spanning blocks, and an entry that is itself a social post, share the plain URL. Long quotes use the `start,end` form (first and last few words).
- **Long quote.** The prefill trims the quote at a word boundary with `…` so the starting text fits the network's limit (Bluesky 300 graphemes; Mastodon the account's maximum, the URL counted as Mastodon counts it). The composer's counter stays as it is for what the reader adds.
- **Plain text.** A selection across paragraphs, links or media collapses to plain text, whitespace normalised, paragraph breaks kept as one line break.
- **Other destinations.** The share targets are templates; quote mode changes what `{text}` and `{url}` hold: `{url}` is the fragment URL, `{text}` is `“quote” url`. A target whose template has no `{text}` is link-only. E-mail moves to `body={text}` (outside quote mode that is title and URL — an improvement, not a change of meaning). The system share passes the quote as `text`.
- **Out of scope:** Markdown or rich quote formatting; quoting from list views, comments or the magazine; sharing a selection as an image.
- **Verification:** unit tests for selection-to-text, fragment URL, template filling per mode, starting text and trimming. Live checks on desktop (`:4200`) and on the iPhone. **No live post without Lars's go.**

Project rules:

- CLAUDE.md is binding: Clean Code, role folders, `final readonly`, no comment unless a future reader would get the code wrong, PHPStan max, every touched `src` file PHPMD-clean, phptramp, `declare(strict_types=1)`, HTTP statuses as `Response::HTTP_*` (tests too), PhpStorm lint blocks on WARNING, `infection:diff` gate.
- Frontend: Prettier 100 cols, ESLint `max-params` 3, en/de i18n parity, no hex colours outside theme, no ad-hoc `px` spacing, no media-query literals, component styles in sibling `.scss`. Jest **only** in the Docker frontend container, one run at a time. Run `npm run check` **and** `npx ng build` there (`npm run check` misses template errors).
- Commits `type(#1970): summary`, no attribution lines. Branch `feature/1970-share-selection-quote` is checked out; do not switch branches.
- Never post to a social network during checks without Lars's explicit go. Never write to Strato.

## Decisions (resolved from the rulings and the real code)

1. **The quote rides `SharedLinkModel`.** `LinkPosts::resolve` already builds `SharedLinkModel($url, $title)` from the `LinkTargetModel`, so both models gain `string $quote = ''`. `ComposerStartingText::sharing` gains the account's `$maxCharacters`, because `ComposerContexts::ofLink` already holds the defaults.
2. **A link that names a quotable post starts with the quote too.** Since #1972, `LinkPosts::contextFor` answers a *quote* composer when the URL names a post on the account's network. That path ignored the starting text (`''`). It now goes through `ComposerContexts::ofQuotedLink($post, $link)`, which starts the text with `“quote”` trimmed to the account's limit (no URL: the quoted post is the embed). Without a quote it is unchanged. The rulings did not cover this path. Leaving it out would make “Quote on Bluesky” on a social-post entry open an empty composer.
3. **Trimming counts code points.** `WordBoundary::clipped` counts with `mb_strlen`. A text of at most N code points has at most N graphemes, so the Bluesky prefill always fits 300 graphemes. Mastodon's room is `max − 2 (marks) − 2 ("\n\n") − 23 (any URL)`.
4. **The quote is capped at 1000 characters on the wire.** It travels in the GET query, next to the URL and title. The frontend clips it at a word to `QUOTE_MAX = 1000` (with `…`). `LinkTargetQuery` refuses more with 422 (`Assert\Length(max: self::QUOTE_MAX)`). 1000 is far past Bluesky's 300 and Mastodon's default 500, and it keeps the request line under proxy limits.
5. **The fragment survives the backend.** `UrlNormalizer::withoutTracking` re-appends `parse_url`'s fragment verbatim (#1968, `65878caf6`), and Symfony's `Assert\Url` fragment grammar allows `:~=,%XX`. A test pins `#:~:text=…` end to end.
6. **`SelectedQuote` is `{ text, words, anchorable }`.** `anchorable` means “one block, in an entry that is no social post”: the one fact the fragment rule needs. `ArticleSelection` knows the entry (`entry.social !== null` marks a social post), and `selectionText()` reports `singleBlock`. So the selection decides once, and `quoteShareUrl(url, quote)` stays a two-argument pure function. This deviates in name only from the ruling's `singleBlock` field.
7. **Live vs remembered.** `ArticleSelection.quote()` is the live, settled, qualifying selection. The bubble and the bar show while it is non-null. `remembered()` keeps the last qualifying quote until another non-collapsed selection or another article replaces it. On a fine pointer the share menu reads `quote()` when it opens. On a coarse pointer it reads `remembered()`. The bubble's and bar's network buttons read `remembered()`. The 150 ms settle also delays the *hide*, so a phone tap that collapses the selection still lands on the bar.
8. **The selection survives a click.** The bubble, the share trigger and the share menu call `preventDefault()` on `mousedown`, so a click there does not collapse the article selection. The menu also captures its quote when it opens (`quote` signal in `ShareButtonComponent`), so it never flips mode under the pointer.
9. **One component for bubble and bar.** `SelectionActionsComponent` (network buttons + `<app-share-button appearance="compact">`) is the shared body. The bubble puts it on a CDK connected overlay at the selection's last client rect (a `Point` origin, re-applied on scroll). The bar puts it in a new `articleDock` slot of `ArticleLayerComponent`, with the word count projected in.
10. **The sheet's hint is a note item.** `ActionSheetItem` gains `ActionSheetNote { note: string }`, rendered as a muted line in place. In quote mode the sheet's title is the snippet `“…”`, clipped at a word to 60 characters. The sheet shows a link-only target's `(link only)` as its `hint` line, which is already muted. The sheet API has no dimmed state, and adding one for this alone is not worth it.
11. **Only the reader view quotes.** `ArticleSelection` is provided by `ReaderViewComponent`. `ShareButtonComponent` injects it optionally, so the link reader (rendered by the shell, outside the reader view) keeps its plain menu, with no hint and no quote mode.
12. **Caret.** `ComposerDialogComponent` already focuses the field in an `effect` once it renders. For a link target that carries a quote it also calls `setSelectionRange(0, 0)`, unless the reader has typed.

## File Structure

Backend (`backend/`):
- Modify `src/Dto/Social/LinkTargetQuery.php`: `QUOTE_MAX`, `$quote`.
- Modify `src/Service/Social/Model/LinkTargetModel.php`, `Model/SharedLinkModel.php`: `$quote`.
- Modify `src/Service/Social/Model/ComposerContextModel.php`: `withText()`.
- Modify `src/Service/Social/Support/ComposerStartingText.php`: `sharing(…, int $maxCharacters)`, `quoting()`.
- Modify `src/Service/Social/ComposerContexts.php`: `ofLink` passes the limit, new `ofQuotedLink`.
- Modify `src/Service/Social/LinkPosts.php`: quote into `SharedLinkModel`, quote path through `ofQuotedLink`.
- Tests: `tests/Service/Social/Support/ComposerStartingTextTest.php`, `tests/Service/Social/LinkPostsTest.php`, `tests/Controller/Api/SocialLinkPostControllerTest.php`.

Frontend (`frontend/`):
- Modify `src/app/reader/entry-social.ts` (`LinkTargetDto.quote`, `SharedLinkDto`), `src/app/reader/social-post-api.ts` (+spec).
- Modify `src/app/reader/article/composer/post-actions.service.ts` (+spec), `composer-dialog.component.ts` (+spec).
- Create `src/app/reader/article/selection-share/selected-quote.ts`, `selection-text.ts` (+spec), `article-selection.service.ts` (+spec), `selection-actions.component.{ts,html,scss}`, `selection-bubble.component.{ts,html,scss,spec.ts}`, `selection-bar.component.{ts,html,scss,spec.ts}`.
- Create `src/app/reader/article/share/quote-share-url.ts` (+spec), `share-content.ts` (+spec), `share-menu.ts` (+spec), `post-networks.service.ts`.
- Modify `src/app/reader/article/share/share-targets.ts` (+spec), `share-button.component.{ts,html,scss,spec.ts}`, `public/share-targets/share-targets.json`.
- Modify `src/app/shared/action-sheet/action-sheet.component.{ts,html,scss}` (+ `action-sheet.service.spec.ts`).
- Modify `src/app/reader/article/reader-view/reader-view.component.{ts,html}`, `src/app/reader/article/article-layer/article-layer.component.{html,scss}` (+spec).
- Modify `public/i18n/{en,de}.json`.

Docs: `docs/design-language.md` (sheet note, selection share surfaces).

---

### Task 1: The link composer starts from the reader's quote

**Files:**
- Modify: `backend/src/Dto/Social/LinkTargetQuery.php`
- Modify: `backend/src/Service/Social/Model/LinkTargetModel.php`, `backend/src/Service/Social/Model/SharedLinkModel.php`, `backend/src/Service/Social/Model/ComposerContextModel.php`
- Modify: `backend/src/Service/Social/Support/ComposerStartingText.php`
- Modify: `backend/src/Service/Social/ComposerContexts.php`, `backend/src/Service/Social/LinkPosts.php`
- Test: `backend/tests/Service/Social/Support/ComposerStartingTextTest.php`, `backend/tests/Service/Social/LinkPostsTest.php`, `backend/tests/Controller/Api/SocialLinkPostControllerTest.php`

**Interfaces:**
- Consumes: `WordBoundary::clipped(string $text, int $maxLength): string` (`App\Service\Text\Support`), `ComposerDefaultsModel::$maxCharacters`.
- Produces: `GET /api/social/link-composer?…&quote=` (optional, ≤ `LinkTargetQuery::QUOTE_MAX` = 1000 characters, else 422); `LinkTargetModel::__construct(SocialNetwork $network, ?int $accountId, string $url, string $title = '', string $quote = '')`; `SharedLinkModel::__construct(string $url, string $title, string $quote = '')`; `ComposerStartingText::sharing(SocialNetwork $network, SharedLinkModel $link, int $maxCharacters): string`; `ComposerStartingText::quoting(string $quote, int $room): string`; `ComposerContextModel::withText(string $text): self`; `ComposerContexts::ofQuotedLink(SocialPostModel $post, SharedLinkModel $link): ComposerContextModel`. The frontend's `QUOTE_MAX` (Task 3) mirrors `LinkTargetQuery::QUOTE_MAX`.

- [ ] **Step 1: Write the failing starting-text tests.** In `ComposerStartingTextTest.php`, make the existing provider test pass the limit, and add the quote cases:

```php
    #[DataProvider('linkPosts')]
    public function testALinkPostStartsAsItsNetworkShowsALink(SocialNetwork $network, string $title, string $text): void
    {
        self::assertSame(
            $text,
            ComposerStartingText::sharing($network, new SharedLinkModel(self::URL, $title), self::MASTODON_DEFAULT),
        );
    }

    /** @return iterable<string, array{SocialNetwork, string}> */
    public static function quotedLinkPosts(): iterable
    {
        yield 'Bluesky: the quote alone, the link rides in the card' => [SocialNetwork::Bluesky, '“A line worth sharing”'];
        yield 'Mastodon: the quote above the link' => [
            SocialNetwork::Mastodon,
            "“A line worth sharing”\n\n" . self::URL,
        ];
    }

    #[DataProvider('quotedLinkPosts')]
    public function testAQuotedLinkPostStartsWithTheQuoteInsteadOfTheTitle(SocialNetwork $network, string $text): void
    {
        $link = new SharedLinkModel(self::URL, 'A story', 'A line worth sharing');

        self::assertSame($text, ComposerStartingText::sharing($network, $link, self::MASTODON_DEFAULT));
    }

    public function testALongQuoteIsCutAtAWordToFitBluesky(): void
    {
        $link = new SharedLinkModel(self::URL, 'A story', str_repeat('word ', 100));

        $text = ComposerStartingText::sharing(SocialNetwork::Bluesky, $link, BlueskyPostWriter::MAX_GRAPHEMES);

        self::assertSame('“' . trim(str_repeat('word ', 59)) . '…”', $text);
        self::assertLessThanOrEqual(BlueskyPostWriter::MAX_GRAPHEMES, grapheme_strlen($text));
    }

    public function testALongQuoteLeavesRoomForTheLinkAsMastodonCountsIt(): void
    {
        $longUrl = 'https://news.example/' . str_repeat('path/', 40);
        $link = new SharedLinkModel($longUrl, 'A story', str_repeat('word ', 120));

        $text = ComposerStartingText::sharing(SocialNetwork::Mastodon, $link, self::MASTODON_DEFAULT);

        self::assertSame('“' . trim(str_repeat('word ', 94)) . "…”\n\n" . $longUrl, $text);
    }

    public function testAQuoteOfAPostIsTheQuoteAloneCutToTheRoomGiven(): void
    {
        self::assertSame('“A line worth sharing”', ComposerStartingText::quoting('A line worth sharing', 500));
        self::assertSame('“one two…”', ComposerStartingText::quoting('one two three four', 10));
    }

    public function testNoQuoteStartsNothing(): void
    {
        self::assertSame('', ComposerStartingText::quoting('', 500));
    }
```

Add `private const int MASTODON_DEFAULT = 500;` and `use App\Service\Social\PostWriter\BlueskyPostWriter;`. The arithmetic: Bluesky room 300 − 2 marks = 298, so the cut lands at 59 words plus `…`. Mastodon room 500 − 2 − 2 − 23 = 473, so 94 words plus `…`, and the 200-character URL counts as 23. `quoting('one two three four', 10)`: room 8, `clipped` cuts at 7 (`one two`), then `…`.

- [ ] **Step 2: Run them and watch them fail**

Run: `cd backend && php bin/phpunit tests/Service/Social/Support/ComposerStartingTextTest.php`
Expected: FAIL. `SharedLinkModel` has no third argument, and `quoting()` is undefined.

- [ ] **Step 3: Carry the quote through the models**

`LinkTargetModel.php`:

```php
/** A URL to share on a network, by the chosen account or the network's default one, its title and the quoted selection. */
final readonly class LinkTargetModel
{
    public function __construct(
        public SocialNetwork $network,
        public ?int $accountId,
        public string $url,
        public string $title = '',
        public string $quote = '',
    ) {
    }
}
```

`SharedLinkModel.php`:

```php
/** A normalised URL a link post shares, with the title the reader shares it by and the selection it quotes. */
final readonly class SharedLinkModel
{
    public function __construct(
        public string $url,
        public string $title,
        public string $quote = '',
    ) {
    }
}
```

`ComposerContextModel.php`: add below the constructor:

```php
    public function withText(string $text): self
    {
        return new self(
            $this->accounts,
            $this->account,
            $this->canSend,
            $this->defaults,
            $this->target,
            $this->kind,
            $text,
        );
    }
```

- [ ] **Step 4: Write the quote form in `ComposerStartingText`**

```php
<?php

declare(strict_types=1);

namespace App\Service\Social\Support;

use App\Enum\SocialNetwork;
use App\Service\Social\Model\SharedLinkModel;
use App\Service\Text\Support\WordBoundary;

/** Where the composer's text starts before the reader types. */
final class ComposerStartingText
{
    /** Mastodon counts every link as this many characters, whatever its length. */
    private const int MASTODON_LINK_LENGTH = 23;
    private const string LINK_GAP = "\n\n";
    private const string OPENING_QUOTE = '“';
    private const string CLOSING_QUOTE = '”';

    private function __construct()
    {
    }

    /** @param list<string> $mentions */
    public static function mentioning(array $mentions): string
    {
        return [] === $mentions ? '' : implode(' ', $mentions) . ' ';
    }

    /** A Bluesky link post carries its link in the card, so the text starts with the title, or the quote, alone. */
    public static function sharing(SocialNetwork $network, SharedLinkModel $link, int $maxCharacters): string
    {
        if ('' !== $link->quote) {
            return self::sharingQuote($network, $link, $maxCharacters);
        }

        return match (true) {
            SocialNetwork::Bluesky === $network => $link->title,
            '' === $link->title => $link->url,
            default => $link->title . self::LINK_GAP . $link->url,
        };
    }

    /** The quote in typographic quotes, cut at a word so the whole fits $room characters; nothing without a quote. */
    public static function quoting(string $quote, int $room): string
    {
        if ('' === $quote) {
            return '';
        }

        $marks = mb_strlen(self::OPENING_QUOTE . self::CLOSING_QUOTE);

        return self::OPENING_QUOTE . WordBoundary::clipped($quote, $room - $marks) . self::CLOSING_QUOTE;
    }

    private static function sharingQuote(SocialNetwork $network, SharedLinkModel $link, int $maxCharacters): string
    {
        if (SocialNetwork::Bluesky === $network) {
            return self::quoting($link->quote, $maxCharacters);
        }

        $linkRoom = mb_strlen(self::LINK_GAP) + self::MASTODON_LINK_LENGTH;

        return self::quoting($link->quote, $maxCharacters - $linkRoom) . self::LINK_GAP . $link->url;
    }
}
```

- [ ] **Step 5: Run the starting-text tests**

Run: `php bin/phpunit tests/Service/Social/Support/ComposerStartingTextTest.php`
Expected: PASS.

- [ ] **Step 6: Write the failing service tests** in `LinkPostsTest.php` (beside the existing composer tests):

```php
    public function testTheLinkComposerStartsWithTheSelectedQuoteAboveTheLink(): void
    {
        SocialFeeds::persistedAccount($this->entityManager, SocialAccounts::mastodonOf($this->owner));
        $target = new LinkTargetModel(
            SocialNetwork::Mastodon,
            null,
            self::ARTICLE_URL,
            'A story',
            'A line worth sharing',
        );

        $context = $this->linkPosts()->contextFor($this->owner, $target);

        self::assertSame(ComposerKind::Link, $context->kind);
        self::assertSame("“A line worth sharing”\n\n" . self::ARTICLE_URL, $context->text);
    }

    public function testTheSharedAddressKeepsTheTextFragmentThatPointsAtTheQuote(): void
    {
        SocialFeeds::persistedAccount($this->entityManager, SocialAccounts::mastodonOf($this->owner));
        $page = 'https://news.example/page#:~:text=A%20line%20worth%20sharing';
        $target = new LinkTargetModel(SocialNetwork::Mastodon, null, $page, 'A story', 'A line worth sharing');

        $context = $this->linkPosts()->contextFor($this->owner, $target);

        self::assertSame("“A line worth sharing”\n\n" . $page, $context->text);
    }

    public function testTheComposerForAQuotedStatusStartsWithTheSelectedQuote(): void
    {
        SocialFeeds::persistedAccount($this->entityManager, SocialAccounts::mastodonOf($this->owner));
        $preview = new PostPreviewModel('Eugen', '@Gargron@mastodon.social', 'Hello');
        $this->mastodon->knowsTheResponseContext(new ResponseContextModel(preview: $preview));
        $target = new LinkTargetModel(SocialNetwork::Mastodon, null, self::STATUS_URL, '', 'A line worth sharing');

        $context = $this->linkPosts()->contextFor($this->owner, $target);

        self::assertSame(ComposerKind::Quote, $context->kind);
        self::assertSame($preview, $context->target);
        self::assertSame('“A line worth sharing”', $context->text);
    }
```

The existing `testTheComposerForAStatusOnTheAccountsNetworkShowsTheStatusItQuotes` keeps asserting `''` without a quote.

- [ ] **Step 7: Write the failing controller tests** in `SocialLinkPostControllerTest.php` (add `use App\Dto\Social\LinkTargetQuery;`):

```php
    public function testTheLinkComposerStartsWithTheSelectedQuoteAboveTheLinkOnMastodon(): void
    {
        $client = $this->clientRouting(self::ROUTED_HOSTS, [
            self::INSTANCE => new MockResponse('{"configuration":{"statuses":{"max_characters":500}}}'),
            self::OWN_ACCOUNT => new MockResponse('{"id":"1","source":{"privacy":"public"}}'),
        ]);
        $user = $this->signIn($client, 'link-composer-quote-mastodon@example.test');
        $this->mastodonAccountOf($user);

        $client->request(
            'GET',
            self::LINK_COMPOSER . 'mastodon&title=The+article&quote=' . rawurlencode('A line worth sharing'),
        );

        self::assertResponseIsSuccessful();
        self::assertSame("“A line worth sharing”\n\nhttps://example.com/article", $this->payload($client)['text']);
    }

    public function testTheLinkComposerStartsWithTheSelectedQuoteAloneOnBluesky(): void
    {
        $client = $this->clientRouting(self::ROUTED_HOSTS, []);
        $user = $this->signIn($client, 'link-composer-quote-bluesky@example.test');
        $this->entityManager()->persist(SocialAccounts::blueskyOf($user));
        $this->entityManager()->flush();

        $client->request(
            'GET',
            self::LINK_COMPOSER . 'bluesky&title=The+article&quote=' . rawurlencode('A line worth sharing'),
        );

        self::assertResponseIsSuccessful();
        self::assertSame('“A line worth sharing”', $this->payload($client)['text']);
    }

    public function testTheLinkComposerKeepsTheTextFragmentThatPointsAtTheQuote(): void
    {
        $client = $this->clientRouting(self::ROUTED_HOSTS, [
            self::INSTANCE => new MockResponse('{"configuration":{"statuses":{"max_characters":500}}}'),
            self::OWN_ACCOUNT => new MockResponse('{"id":"1","source":{"privacy":"public"}}'),
        ]);
        $user = $this->signIn($client, 'link-composer-quote-fragment@example.test');
        $this->mastodonAccountOf($user);
        $page = 'https://example.com/article#:~:text=A%20line%20worth%20sharing';

        $client->request('GET', '/api/social/link-composer?network=mastodon&url=' . rawurlencode($page)
            . '&quote=' . rawurlencode('A line worth sharing'));

        self::assertResponseIsSuccessful();
        self::assertSame("“A line worth sharing”\n\n" . $page, $this->payload($client)['text']);
    }

    public function testTheLinkComposerRefusesAQuoteLongerThanTheFrontendSends(): void
    {
        $client = $this->clientRouting(self::ROUTED_HOSTS, []);
        $user = $this->signIn($client, 'link-composer-quote-long@example.test');
        $this->entityManager()->persist(SocialAccounts::blueskyOf($user));
        $this->entityManager()->flush();

        $client->request(
            'GET',
            self::LINK_COMPOSER . 'bluesky&quote=' . str_repeat('a', LinkTargetQuery::QUOTE_MAX + 1),
        );

        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
    }
```

- [ ] **Step 8: Run them and watch them fail**

Run: `php bin/phpunit tests/Service/Social/LinkPostsTest.php tests/Controller/Api/SocialLinkPostControllerTest.php`
Expected: the new tests FAIL (the texts are `A story\n\n…`, `The article`, `''`; the long quote answers 200).

- [ ] **Step 9: Take the quote in, and start from it**

`LinkTargetQuery.php`:

```php
final readonly class LinkTargetQuery
{
    /** The longest selection the composer starts from; share-content.ts clips to the same `QUOTE_MAX`. */
    public const int QUOTE_MAX = 1000;

    public function __construct(
        public SocialNetwork $network,
        #[Assert\NotBlank]
        #[Assert\Url(requireTld: true)]
        #[Assert\Length(max: LinkCardContent::URL_MAX)]
        public string $url,
        public string $title = '',
        #[Assert\Length(max: self::QUOTE_MAX)]
        public string $quote = '',
    ) {
    }

    public function toLinkTarget(?int $accountId): LinkTargetModel
    {
        return new LinkTargetModel(
            $this->network,
            $accountId,
            $this->url,
            mb_substr($this->title, 0, LinkCardContent::TITLE_MAX),
            $this->quote,
        );
    }
}
```

`ComposerContexts.php`: replace `ofLink` and add `ofQuotedLink`:

```php
    public function ofLink(SocialAccount $account, SharedLinkModel $link): ComposerContextModel
    {
        $defaults = $this->defaultsOn($account)->forAccount($account);

        return new ComposerContextModel(
            $this->connectedBeside($account),
            $account,
            canSend: true,
            defaults: $defaults,
            target: $this->pageCards->cardOf($link->url),
            kind: ComposerKind::Link,
            text: ComposerStartingText::sharing($account->getNetwork(), $link, $defaults->maxCharacters),
        );
    }

    /** A shared link that names a quotable post quotes it, starting with the selection the reader shares. */
    public function ofQuotedLink(SocialPostModel $post, SharedLinkModel $link): ComposerContextModel
    {
        $context = $this->ofQuote($post);

        return $context->withText(ComposerStartingText::quoting($link->quote, $context->defaults->maxCharacters));
    }
```

`LinkPosts.php`: in `contextFor` replace `$this->contexts->ofQuote($share->quoted)` with `$this->contexts->ofQuotedLink($share->quoted, $share->link)`. In `resolve` build `new SharedLinkModel($url, $target->title, $target->quote)`.

- [ ] **Step 10: Run the Social tests**

Run: `php bin/phpunit tests/Service/Social tests/Controller/Api/SocialLinkPostControllerTest.php`
Expected: PASS.

- [ ] **Step 11: Gates on the touched files**

Run: `composer check` and `composer md`. Run PhpStorm `lint_files` on the eight touched `src`/`tests` files.
Expected: clean. The endpoint keeps the §6 checklist of `docs/architecture.md`: bearer auth, a plain query parameter, JSON or problem+json out.

- [ ] **Step 12: Commit**

```bash
git add backend/src/Dto/Social/LinkTargetQuery.php backend/src/Service/Social backend/tests/Service/Social backend/tests/Controller/Api/SocialLinkPostControllerTest.php
git commit -m "feat(#1970): the link composer starts from the reader's quote, trimmed to the network's limit"
```

---

### Task 2: The composer opens on a quote, caret first

**Files:**
- Modify: `frontend/src/app/reader/entry-social.ts`
- Modify: `frontend/src/app/reader/social-post-api.ts`, `social-post-api.spec.ts`
- Modify: `frontend/src/app/reader/article/composer/post-actions.service.ts`, `post-actions.service.spec.ts`
- Modify: `frontend/src/app/reader/article/composer/composer-dialog.component.ts`, `composer-dialog.component.spec.ts`
- Modify: `frontend/src/app/reader/article/share/share-button.component.ts`, `share-button.component.spec.ts` (the call site only)

**Interfaces:**
- Consumes: Task 1's `quote` query parameter.
- Produces: `LinkTargetDto { kind: 'link'; network: SocialNetworkName; url: string; title: string; quote: string | null }`; `export type SharedLinkDto = Omit<LinkTargetDto, 'kind' | 'network'>`; `PostActions.postLink(network: SocialNetworkName, link: SharedLinkDto): void`.

- [ ] **Step 1: Write the failing specs.**

`social-post-api.spec.ts`: add `quote: null` to the three link literals (lines ~165, ~175, ~185), and add:

```ts
  it('sends the quote the composer starts from only when there is one', () => {
    const composerPath = 'https://api.test/api/social/link-composer';
    api
      .composer({ kind: 'link', network: 'mastodon', url: SHARED_URL, title: 'A story', quote: 'A line' })
      .subscribe();
    const quoted = ctrl.expectOne((request) => request.url === composerPath);
    expect(quoted.request.params.get('quote')).toBe('A line');
    quoted.flush({});

    api
      .composer({ kind: 'link', network: 'mastodon', url: SHARED_URL, title: 'A story', quote: null })
      .subscribe();
    const plain = ctrl.expectOne((request) => request.url === composerPath);
    expect(plain.request.params.has('quote')).toBe(false);
    plain.flush({});
  });
```

`post-actions.service.spec.ts`: change the existing call to `actions.postLink('bluesky', { url: 'https://example.com/story', title: 'A story', quote: null });` and its expected `data` to `{ kind: 'link', network: 'bluesky', url: 'https://example.com/story', title: 'A story', quote: null }`. Add:

```ts
  it('opens the link composer on the quote the reader selected', () => {
    const link = {
      url: 'https://example.com/story#:~:text=A%20line',
      title: 'A story',
      quote: 'A line',
    };

    actions.postLink('mastodon', link);

    expect(dialog.open).toHaveBeenCalledWith(
      ComposerDialogComponent,
      expect.objectContaining({ data: { kind: 'link', network: 'mastodon', ...link } }),
    );
  });
```

`composer-dialog.component.spec.ts`: add `quote: null,` to the `article()` factory's defaults. Inside `describe('for a link post', …)` add:

```ts
    it('puts the caret before the quote so a comment can go first', () => {
      api.composer.mockReturnValue(
        of(linkContext({ text: '“A line”\n\nhttps://example.com/story' })),
      );

      const fixture = mount(article({ quote: 'A line' }));

      expect(document.activeElement).toBe(textArea(fixture));
      expect([textArea(fixture).selectionStart, textArea(fixture).selectionEnd]).toEqual([0, 0]);
    });

    it('leaves the caret after the text of a link post without a quote', () => {
      const fixture = mount(article());

      expect(textArea(fixture).selectionStart).toBe(textArea(fixture).value.length);
    });
```

`share-button.component.spec.ts`: the two `postLink` expectations become `toHaveBeenCalledWith('mastodon', { url: 'https://example.com/a?id=1', title: 'First', quote: null })` and the same for `'bluesky'`.

- [ ] **Step 2: Run them and watch them fail**

Run: `docker compose exec -T frontend npm test -- src/app/reader/social-post-api.spec.ts src/app/reader/article/composer src/app/reader/article/share/share-button.component.spec.ts`
Expected: FAIL on the type errors (`quote` is not on `LinkTargetDto`) and the new assertions.

- [ ] **Step 3: Implement.**

`entry-social.ts`:

```ts
export interface LinkTargetDto {
  kind: 'link';
  network: SocialNetworkName;
  url: string;
  title: string;
  /** The reader's selection the composer starts from; null shares the link alone. */
  quote: string | null;
}

/** What a link post shares, before a network is chosen. */
export type SharedLinkDto = Omit<LinkTargetDto, 'kind' | 'network'>;
```

`social-post-api.ts`, `composerParams`:

```ts
function composerParams(target: PostTargetDto): Record<string, string> {
  if (target.kind !== 'link') return { kind: target.kind, ...targetParams(target) };
  const quote = target.quote === null ? {} : { quote: target.quote };
  return { ...targetParams(target), title: target.title, ...quote };
}
```

`post-actions.service.ts` (import `SharedLinkDto` beside `PostTargetDto`):

```ts
  /** Opens the composer on a post of the shared link, starting from the reader's quote when it carries one. */
  postLink(network: SocialNetworkName, link: SharedLinkDto): void {
    this.respond({ kind: 'link', network, ...link }).subscribe((sent) => this.announce(sent));
  }
```

`composer-dialog.component.ts`: below `private textTouched = false;` add

```ts
  /** A quote opens the text, so the caret waits before it for the reader's own words. */
  private readonly caretBeforeText = this.target.kind === 'link' && this.target.quote !== null;
```

Replace the constructor's focus effect with `effect(() => this.placeCaret(this.textField()?.nativeElement));` and keep its comment line. Add:

```ts
  private placeCaret(field: HTMLTextAreaElement | undefined): void {
    if (field === undefined) return;
    field.focus();
    if (this.caretBeforeText && !this.textTouched) field.setSelectionRange(0, 0);
  }
```

`share-button.component.ts` `post()`:

```ts
    this.postActions.postLink(network, {
      url: stripTracking(this.url()),
      title: this.title(),
      quote: null,
    });
```

- [ ] **Step 4: Run the specs**

Run the Step 2 command. Expected: PASS.

- [ ] **Step 5: Check and build**

Run: `docker compose exec -T frontend npm run check` and `docker compose exec -T frontend npx ng build`. Expected: clean.

- [ ] **Step 6: Commit**

```bash
git add frontend/src/app/reader/entry-social.ts frontend/src/app/reader/social-post-api.ts frontend/src/app/reader/social-post-api.spec.ts frontend/src/app/reader/article/composer frontend/src/app/reader/article/share/share-button.component.ts frontend/src/app/reader/article/share/share-button.component.spec.ts
git commit -m "feat(#1970): the link composer opens on the reader's quote with the caret before it"
```

---

### Task 3: Pure quote helpers — selection text, fragment URL, share content, templates

**Files:**
- Create: `frontend/src/app/reader/article/selection-share/selected-quote.ts`
- Create: `frontend/src/app/reader/article/selection-share/selection-text.ts`, `selection-text.spec.ts`
- Create: `frontend/src/app/reader/article/share/quote-share-url.ts`, `quote-share-url.spec.ts`
- Create: `frontend/src/app/reader/article/share/share-content.ts`, `share-content.spec.ts`
- Modify: `frontend/src/app/reader/article/share/share-targets.ts`, `share-targets.spec.ts`, `frontend/public/share-targets/share-targets.json`
- Modify: `frontend/src/app/reader/article/share/share-button.component.ts` (the `shareUrl` call)

**Interfaces:**
- Consumes: `SharedLinkDto` (Task 2).
- Produces:
  - `interface SelectedQuote { readonly text: string; readonly words: number; readonly anchorable: boolean }`
  - `selectionText(range: Range): SelectionText` with `SelectionText { readonly text: string; readonly singleBlock: boolean }`; `wordsOf(text: string): string[]`; `wordCount(text: string): number`
  - `quoteShareUrl(url: string, quote: SelectedQuote): string`
  - `QUOTE_MAX = 1000`; `SharedPage { readonly url: string; readonly title: string }`; `ShareContent { readonly url: string; readonly title: string; readonly text: string; readonly quote: string | null }`; `linkContent(page: SharedPage): ShareContent`; `quoteContent(page: SharedPage, quote: SelectedQuote): ShareContent`; `clipboardText(content: ShareContent): string`; `sharedLink(page: SharedPage, quote: SelectedQuote | null): SharedLinkDto`; `clippedAtWord(text: string, max: number): string`
  - `shareUrl(template: string, content: ShareContent): string`; `carriesText(target: ConfiguredTarget): boolean`; `export type ConfiguredTarget = Exclude<ShareTarget, { kind: 'divider' }>` (moves here from the button).

- [ ] **Step 1: Write the failing specs.**

`selection-text.spec.ts`:

```ts
import { selectionText, wordCount } from './selection-text';

describe('selectionText', () => {
  let body: HTMLElement;

  beforeEach(() => {
    body = document.createElement('div');
    document.body.append(body);
  });

  afterEach(() => body.remove());

  function textNodeWith(text: string): Text {
    const walker = document.createTreeWalker(body, NodeFilter.SHOW_TEXT);
    for (let node = walker.nextNode(); node !== null; node = walker.nextNode()) {
      if ((node as Text).data.includes(text)) return node as Text;
    }
    throw new Error(`No text node holds "${text}"`);
  }

  function select(html: string, first: string, last: string): Range {
    body.innerHTML = html;
    const start = textNodeWith(first);
    const end = textNodeWith(last);
    const range = document.createRange();
    range.setStart(start, start.data.indexOf(first));
    range.setEnd(end, end.data.indexOf(last) + last.length);
    return range;
  }

  it('reads a selection inside one paragraph as plain text, whitespace normalised', () => {
    const range = select(
      '<p>The  quick\n <a href="#">brown</a> <em>fox</em> jumps</p>',
      'quick',
      'fox',
    );

    expect(selectionText(range)).toEqual({ text: 'quick brown fox', singleBlock: true });
  });

  it('keeps one line break between paragraphs and drops media', () => {
    const range = select(
      '<p>First para here</p><figure><img src="x.jpg" alt="A"></figure><p>Second para there</p>',
      'para here',
      'Second para',
    );

    expect(selectionText(range)).toEqual({ text: 'para here\nSecond para', singleBlock: false });
  });

  it('turns a line break into one newline inside the same block', () => {
    const range = select('<p>One line<br>next line</p>', 'One', 'next');

    expect(selectionText(range)).toEqual({ text: 'One line\nnext', singleBlock: true });
  });

  it('leaves out the text of controls and scripts', () => {
    const range = select(
      '<p>Before <button>Play</button><script>x()</script>after it</p>',
      'Before',
      'after',
    );

    expect(selectionText(range).text).toBe('Before after');
  });
});

describe('wordCount', () => {
  it('counts the words between any whitespace', () => {
    expect(wordCount('  one two\nthree ')).toBe(3);
    expect(wordCount('')).toBe(0);
  });
});
```

`quote-share-url.spec.ts`:

```ts
import { SelectedQuote } from '../selection-share/selected-quote';
import { quoteShareUrl } from './quote-share-url';

const quote = (text: string, anchorable = true): SelectedQuote => ({
  text,
  words: text.split(' ').length,
  anchorable,
});

describe('quoteShareUrl', () => {
  it('points a text fragment at a short quote', () => {
    expect(quoteShareUrl('https://a.example/story', quote('the quick brown fox'))).toBe(
      'https://a.example/story#:~:text=the%20quick%20brown%20fox',
    );
  });

  it('encodes the characters a text fragment reserves', () => {
    expect(quoteShareUrl('https://a.example/s', quote('well-known, salt & pepper'))).toBe(
      'https://a.example/s#:~:text=well%2Dknown%2C%20salt%20%26%20pepper',
    );
  });

  it('names a long quote by its first and last four words', () => {
    expect(
      quoteShareUrl('https://a.example/s', quote('one two three four five six seven eight nine')),
    ).toBe('https://a.example/s#:~:text=one%20two%20three%20four,six%20seven%20eight%20nine');
  });

  it('keeps a quote of eight words whole', () => {
    expect(quoteShareUrl('https://a.example/s', quote('one two three four five six seven eight'))).toBe(
      'https://a.example/s#:~:text=one%20two%20three%20four%20five%20six%20seven%20eight',
    );
  });

  it('adds the directive after a fragment the URL already has', () => {
    expect(quoteShareUrl('https://a.example/s#part', quote('one two three'))).toBe(
      'https://a.example/s#part:~:text=one%20two%20three',
    );
  });

  it('shares the plain URL for a quote no fragment can anchor', () => {
    expect(quoteShareUrl('https://a.example/s', quote('one two three', false))).toBe(
      'https://a.example/s',
    );
  });
});
```

`share-content.spec.ts`:

```ts
import { SelectedQuote } from '../selection-share/selected-quote';
import {
  QUOTE_MAX,
  clipboardText,
  clippedAtWord,
  linkContent,
  quoteContent,
  sharedLink,
} from './share-content';

const PAGE = { url: 'https://a.example/s', title: 'A story' };
const QUOTE: SelectedQuote = { text: 'one two three', words: 3, anchorable: true };
const FRAGMENT = 'https://a.example/s#:~:text=one%20two%20three';

describe('share content', () => {
  it('shares a page by its title and URL', () => {
    expect(linkContent(PAGE)).toEqual({
      url: PAGE.url,
      title: 'A story',
      text: 'A story https://a.example/s',
      quote: null,
    });
    expect(linkContent({ ...PAGE, title: '' }).text).toBe(PAGE.url);
  });

  it('shares a quote in typographic quotes with the link that points at it', () => {
    expect(quoteContent(PAGE, QUOTE)).toEqual({
      url: FRAGMENT,
      title: 'A story',
      text: `“one two three” ${FRAGMENT}`,
      quote: '“one two three”',
    });
  });

  it('copies the link alone, or the quote above its link', () => {
    expect(clipboardText(linkContent(PAGE))).toBe(PAGE.url);
    expect(clipboardText(quoteContent(PAGE, QUOTE))).toBe(`“one two three”\n\n${FRAGMENT}`);
  });

  it('hands the composer the link alone, or the quote with the link that points at it', () => {
    expect(sharedLink(PAGE, null)).toEqual({ ...PAGE, quote: null });
    expect(sharedLink(PAGE, QUOTE)).toEqual({ url: FRAGMENT, title: 'A story', quote: 'one two three' });
  });

  it('clips a quote past the most the server takes at a word', () => {
    const long: SelectedQuote = { text: 'word '.repeat(300).trim(), words: 300, anchorable: false };

    const sent = sharedLink(PAGE, long).quote!;

    expect(sent.length).toBeLessThanOrEqual(QUOTE_MAX);
    expect(sent.endsWith('word…')).toBe(true);
  });

  it('clips at the last space before the limit', () => {
    expect(clippedAtWord('one two three', 9)).toBe('one two…');
    expect(clippedAtWord('one two', 9)).toBe('one two');
  });
});
```

`share-targets.spec.ts`: replace the first test and add one:

```ts
  it('fills the url, the title and the text, encoded', () => {
    const content = { url: 'https://a.b/?q=1', title: 'A & B', text: 'A & B https://a.b/?q=1', quote: null };

    expect(shareUrl('x?u={url}&t={title}&s={text}', content)).toBe(
      'x?u=https%3A%2F%2Fa.b%2F%3Fq%3D1&t=A%20%26%20B&s=A%20%26%20B%20https%3A%2F%2Fa.b%2F%3Fq%3D1',
    );
  });

  it('carries text through the system sheet, the clipboard and a {text} address only', () => {
    const byLabel = (label: string) =>
      SHARE_TARGETS.find(
        (target): target is ConfiguredTarget => target.kind !== 'divider' && target.label === label,
      )!;

    expect(carriesText({ kind: 'copy' })).toBe(true);
    expect(carriesText(byLabel('E-Mail'))).toBe(true);
    expect(carriesText(byLabel('WhatsApp'))).toBe(true);
    expect(carriesText(byLabel('X'))).toBe(false);
    expect(carriesText(byLabel('Facebook'))).toBe(false);
  });
```

(import `ConfiguredTarget, carriesText` alongside `SHARE_TARGETS, shareUrl`).

- [ ] **Step 2: Run them and watch them fail**

Run: `docker compose exec -T frontend npm test -- src/app/reader/article/selection-share src/app/reader/article/share/quote-share-url.spec.ts src/app/reader/article/share/share-content.spec.ts src/app/reader/article/share/share-targets.spec.ts`
Expected: FAIL. The modules don't exist yet, and `shareUrl` takes three arguments.

- [ ] **Step 3: Implement.**

`selection-share/selected-quote.ts`:

```ts
/**
 * A selection in the article body that can be shared as a quote. `anchorable`: a text fragment can point at it — it
 * stays inside one block of an entry that is no social post.
 */
export interface SelectedQuote {
  readonly text: string;
  readonly words: number;
  readonly anchorable: boolean;
}
```

`selection-share/selection-text.ts`:

```ts
/** Elements that start a line of their own; a selection that starts and ends in the same one is a single block. */
const BLOCKS = new Set([
  'ADDRESS', 'ARTICLE', 'ASIDE', 'BLOCKQUOTE', 'DD', 'DIV', 'DL', 'DT', 'FIGCAPTION', 'FIGURE', 'FOOTER',
  'H1', 'H2', 'H3', 'H4', 'H5', 'H6', 'HEADER', 'HR', 'LI', 'OL', 'P', 'PRE', 'SECTION', 'TABLE', 'TD', 'TH',
  'TR', 'UL',
]);
const SILENT = new Set(['BUTTON', 'NOSCRIPT', 'SCRIPT', 'STYLE', 'TEMPLATE']);

export interface SelectionText {
  readonly text: string;
  readonly singleBlock: boolean;
}

export function selectionText(range: Range): SelectionText {
  return {
    text: normalisedLines(textOf(range.cloneContents())),
    singleBlock: blockOf(range.startContainer) === blockOf(range.endContainer),
  };
}

export function wordsOf(text: string): string[] {
  return text.split(/\s+/).filter((word) => word !== '');
}

export function wordCount(text: string): number {
  return wordsOf(text).length;
}

function textOf(node: Node): string {
  if (node.nodeType === Node.TEXT_NODE) return node.textContent ?? '';
  if (!(node instanceof Element)) return childrenText(node);
  if (SILENT.has(node.tagName)) return '';
  if (node.tagName === 'BR') return '\n';
  return BLOCKS.has(node.tagName) ? `\n${childrenText(node)}\n` : childrenText(node);
}

function childrenText(node: Node): string {
  return Array.from(node.childNodes, textOf).join('');
}

function normalisedLines(text: string): string {
  return text
    .split('\n')
    .map((line) => line.replace(/\s+/g, ' ').trim())
    .filter((line) => line !== '')
    .join('\n');
}

function blockOf(node: Node): Element | null {
  let element = node instanceof Element ? node : node.parentElement;
  while (element !== null && !BLOCKS.has(element.tagName)) element = element.parentElement;
  return element;
}
```

(Prettier lays out the `BLOCKS` array one per line; let it.)

`share/quote-share-url.ts`:

```ts
import { SelectedQuote } from '../selection-share/selected-quote';
import { wordsOf } from '../selection-share/selection-text';

/** Past this many words a text fragment names the quote by its edges, which keeps the link short. */
const WHOLE_QUOTE_WORDS = 8;
const EDGE_WORDS = 4;

/** The URL with a text fragment that opens the page scrolled to the quote, when one can anchor it. */
export function quoteShareUrl(url: string, quote: SelectedQuote): string {
  if (!quote.anchorable) return url;
  const words = wordsOf(quote.text);
  const directive =
    words.length > WHOLE_QUOTE_WORDS
      ? `${fragmentText(words.slice(0, EDGE_WORDS))},${fragmentText(words.slice(-EDGE_WORDS))}`
      : fragmentText(words);
  return `${url}${url.includes('#') ? '' : '#'}:~:text=${directive}`;
}

/** The fragment syntax reserves '-', which encodeURIComponent leaves as it is. */
function fragmentText(words: readonly string[]): string {
  return encodeURIComponent(words.join(' ')).replaceAll('-', '%2D');
}
```

`share/share-content.ts`:

```ts
import { SharedLinkDto } from '../../entry-social';
import { SelectedQuote } from '../selection-share/selected-quote';
import { quoteShareUrl } from './quote-share-url';

/** The longest quote the composer is sent; `LinkTargetQuery::QUOTE_MAX` refuses more. */
export const QUOTE_MAX = 1000;

export interface SharedPage {
  readonly url: string;
  readonly title: string;
}

/** What a share target is filled with. `quote` is the selection in typographic quotes, null when sharing the page. */
export interface ShareContent {
  readonly url: string;
  readonly title: string;
  readonly text: string;
  readonly quote: string | null;
}

export function linkContent(page: SharedPage): ShareContent {
  const text = page.title === '' ? page.url : `${page.title} ${page.url}`;
  return { url: page.url, title: page.title, text, quote: null };
}

export function quoteContent(page: SharedPage, quote: SelectedQuote): ShareContent {
  const url = quoteShareUrl(page.url, quote);
  const quoted = `“${quote.text}”`;
  return { url, title: page.title, text: `${quoted} ${url}`, quote: quoted };
}

export function clipboardText(content: ShareContent): string {
  return content.quote === null ? content.url : `${content.quote}\n\n${content.url}`;
}

export function sharedLink(page: SharedPage, quote: SelectedQuote | null): SharedLinkDto {
  if (quote === null) return { url: page.url, title: page.title, quote: null };
  return {
    url: quoteShareUrl(page.url, quote),
    title: page.title,
    quote: clippedAtWord(quote.text, QUOTE_MAX),
  };
}

/** The text, or its head cut before a space and closed by an ellipsis, at most `max` characters in all. */
export function clippedAtWord(text: string, max: number): string {
  if (text.length <= max) return text;
  const head = text.slice(0, max - 1);
  const lastSpace = head.lastIndexOf(' ');
  return `${lastSpace > 0 ? head.slice(0, lastSpace) : head}…`;
}
```

`share/share-targets.ts`: import `ShareContent` from `./share-content`. Export the target type and replace `shareUrl`:

```ts
/** A target the menu lists, as against a divider between them. */
export type ConfiguredTarget = Exclude<ShareTarget, { kind: 'divider' }>;

/** Whether a target can carry a quote: the system sheet and the clipboard can, an address only through `{text}`. */
export function carriesText(target: ConfiguredTarget): boolean {
  return target.kind !== 'link' || target.url.includes('{text}');
}

/** Fills `{url}`, `{title}` and `{text}` into a target's address. */
export function shareUrl(template: string, content: ShareContent): string {
  return template
    .replaceAll('{url}', encodeURIComponent(content.url))
    .replaceAll('{title}', encodeURIComponent(content.title))
    .replaceAll('{text}', encodeURIComponent(content.text));
}
```

`share-targets.json`: the E-Mail entry's `"url"` becomes `"mailto:?subject={title}&body={text}"`.

`share-button.component.ts`: drop its own `type ConfiguredTarget = …` and import it from `./share-targets`. Change the link branch of `choose()` to `this.visit(shareUrl(target.url, linkContent({ url, title: this.title() })));` (import `linkContent` from `./share-content`).

- [ ] **Step 4: Run the specs**

Run the Step 2 command plus `src/app/reader/article/share/share-button.component.spec.ts`. Expected: PASS. The button's Bluesky-app URL is unchanged.

- [ ] **Step 5: Check and build** (`npm run check`, `npx ng build` in the container). Expected: clean.

- [ ] **Step 6: Commit**

```bash
git add frontend/src/app/reader/article/selection-share frontend/src/app/reader/article/share frontend/public/share-targets/share-targets.json
git commit -m "feat(#1970): turn a selection into a quote, a text-fragment link and filled share templates"
```

---

### Task 4: `ArticleSelection` — the settled selection inside the article body

**Files:**
- Create: `frontend/src/app/reader/article/selection-share/article-selection.service.ts`, `article-selection.service.spec.ts`
- Modify: `frontend/src/app/reader/article/reader-view/reader-view.component.ts`

**Interfaces:**
- Consumes: `selectionText`, `wordCount`, `SelectedQuote` (Task 3).
- Produces: `@Injectable() class ArticleSelection` (provided by `ReaderViewComponent`). It has `connect(sources: ArticleSelectionSources): void`, plus `quote: Signal<SelectedQuote | null>`, `remembered: Signal<SelectedQuote | null>` and `range: Signal<Range | null>`. `ArticleSelectionSources { readonly body: Signal<ElementRef<HTMLElement> | undefined>; readonly article: Signal<EntryDto | null> }`, `QUOTE_MIN_WORDS = 3`, `SELECTION_SETTLE_MS = 150`.

- [ ] **Step 1: Write the failing spec** `article-selection.service.spec.ts`:

```ts
import { ElementRef, signal } from '@angular/core';
import { TestBed } from '@angular/core/testing';
import { anEntry } from '../../../../testing/entry-testing';
import { EntryDto } from '../../models';
import { ArticleSelection, SELECTION_SETTLE_MS } from './article-selection.service';

describe('ArticleSelection', () => {
  const article = signal<EntryDto | null>(null);
  let body: HTMLElement;
  let outside: HTMLElement;
  let selection: ArticleSelection;

  beforeEach(() => {
    jest.useFakeTimers();
    body = document.createElement('div');
    body.innerHTML = '<p>One two three four five</p><p>Six seven eight</p>';
    outside = document.createElement('p');
    outside.textContent = 'Nine ten eleven twelve';
    document.body.append(body, outside);
    article.set(anEntry({ id: 1 }));
    TestBed.configureTestingModule({ providers: [ArticleSelection] });
    selection = TestBed.inject(ArticleSelection);
    selection.connect({ body: signal(new ElementRef(body)), article });
    TestBed.tick();
  });

  afterEach(() => {
    document.getSelection()?.removeAllRanges();
    body.remove();
    outside.remove();
    jest.useRealTimers();
  });

  const paragraph = (index: number): Node => body.querySelectorAll('p')[index].firstChild!;

  function select(start: Node, from: number, to: number, end: Node = start): void {
    const range = document.createRange();
    range.setStart(start, from);
    range.setEnd(end, to);
    const current = document.getSelection()!;
    current.removeAllRanges();
    current.addRange(range);
    document.dispatchEvent(new Event('selectionchange'));
  }

  function collapse(): void {
    document.getSelection()!.collapseToStart();
    document.dispatchEvent(new Event('selectionchange'));
  }

  const settle = () => jest.advanceTimersByTime(SELECTION_SETTLE_MS);

  it('offers three selected words in the body once the selection settles', () => {
    select(paragraph(0), 0, 'One two three'.length);
    expect(selection.quote()).toBeNull();

    settle();

    expect(selection.quote()).toEqual({ text: 'One two three', words: 3, anchorable: true });
    expect(selection.range()?.toString()).toBe('One two three');
  });

  it('ignores fewer than three words', () => {
    select(paragraph(0), 0, 'One two'.length);
    settle();

    expect(selection.quote()).toBeNull();
  });

  it('ignores a selection outside the article body', () => {
    select(outside.firstChild!, 0, 'Nine ten eleven'.length);
    settle();

    expect(selection.quote()).toBeNull();
  });

  it('cannot anchor a quote that spans blocks', () => {
    select(paragraph(0), 'One '.length, 'Six seven'.length, paragraph(1));
    settle();

    expect(selection.quote()).toEqual({
      text: 'two three four five\nSix seven',
      words: 6,
      anchorable: false,
    });
  });

  it('cannot anchor a quote in a social post', () => {
    article.set(
      anEntry({ id: 1, social: { network: 'bluesky', isLiked: false, canLike: true, isOwn: false } }),
    );
    select(paragraph(0), 0, 'One two three'.length);
    settle();

    expect(selection.quote()?.anchorable).toBe(false);
  });

  it('remembers the last quote after the selection collapses, until another selection replaces it', () => {
    select(paragraph(0), 0, 'One two three'.length);
    settle();

    collapse();
    settle();
    expect(selection.quote()).toBeNull();
    expect(selection.remembered()?.text).toBe('One two three');

    select(paragraph(1), 0, 'Six'.length);
    settle();
    expect(selection.remembered()).toBeNull();
  });

  it('forgets the quote when another article opens', () => {
    select(paragraph(0), 0, 'One two three'.length);
    settle();

    article.set(anEntry({ id: 2 }));
    TestBed.tick();

    expect(selection.quote()).toBeNull();
    expect(selection.remembered()).toBeNull();
  });

  it('keeps the quote when the same article only changes', () => {
    select(paragraph(0), 0, 'One two three'.length);
    settle();

    article.set(anEntry({ id: 1, title: 'Renamed' }));
    TestBed.tick();

    expect(selection.remembered()?.text).toBe('One two three');
  });
});
```

- [ ] **Step 2: Run it and watch it fail**

Run: `docker compose exec -T frontend npm test -- src/app/reader/article/selection-share/article-selection.service.spec.ts`
Expected: FAIL. The module does not exist yet.

- [ ] **Step 3: Implement** `article-selection.service.ts`:

```ts
import { DOCUMENT } from '@angular/common';
import {
  DestroyRef,
  ElementRef,
  Injectable,
  Injector,
  Signal,
  computed,
  effect,
  inject,
  signal,
  untracked,
} from '@angular/core';
import { takeUntilDestroyed } from '@angular/core/rxjs-interop';
import { debounceTime, fromEvent } from 'rxjs';
import { EntryDto } from '../../models';
import { SelectedQuote } from './selected-quote';
import { selectionText, wordCount } from './selection-text';

export const QUOTE_MIN_WORDS = 3;
/** A drag in progress is no quote yet; the same rest delays the hide, so a tap that collapses it still lands. */
export const SELECTION_SETTLE_MS = 150;

export interface ArticleSelectionSources {
  readonly body: Signal<ElementRef<HTMLElement> | undefined>;
  readonly article: Signal<EntryDto | null>;
}

/**
 * The reader's selection inside the article body, as a quote to share. `remembered` outlives a collapse — a tap on a
 * phone collapses the selection before the share it starts — until another selection or another article ends it.
 */
@Injectable()
export class ArticleSelection {
  private readonly document = inject(DOCUMENT);
  private readonly injector = inject(Injector);
  private readonly destroyRef = inject(DestroyRef);
  private readonly liveQuote = signal<SelectedQuote | null>(null);
  private readonly keptQuote = signal<SelectedQuote | null>(null);
  private readonly liveRange = signal<Range | null>(null);
  private sources: ArticleSelectionSources | null = null;

  readonly quote = this.liveQuote.asReadonly();
  readonly remembered = this.keptQuote.asReadonly();
  readonly range = this.liveRange.asReadonly();

  connect(sources: ArticleSelectionSources): void {
    this.sources = sources;
    const articleId = computed(() => sources.article()?.id ?? null);
    effect(
      () => {
        articleId();
        untracked(() => this.forget());
      },
      { injector: this.injector },
    );
    fromEvent(this.document, 'selectionchange')
      .pipe(debounceTime(SELECTION_SETTLE_MS), takeUntilDestroyed(this.destroyRef))
      .subscribe(() => this.read());
  }

  private read(): void {
    const range = this.selectedRange();
    const quote = range === null ? null : this.quoteIn(range);
    this.liveRange.set(quote === null ? null : range);
    this.liveQuote.set(quote);
    if (quote !== null || range !== null) this.keptQuote.set(quote);
  }

  private selectedRange(): Range | null {
    const selection = this.document.getSelection();
    if (selection === null || selection.isCollapsed || selection.rangeCount === 0) return null;
    return selection.getRangeAt(0);
  }

  private quoteIn(range: Range): SelectedQuote | null {
    const body = this.sources?.body()?.nativeElement;
    if (body === undefined || !body.contains(range.commonAncestorContainer)) return null;
    const { text, singleBlock } = selectionText(range);
    const words = wordCount(text);
    if (words < QUOTE_MIN_WORDS) return null;
    return { text, words, anchorable: singleBlock && this.sources?.article()?.social === null };
  }

  private forget(): void {
    this.liveQuote.set(null);
    this.keptQuote.set(null);
    this.liveRange.set(null);
  }
}
```

- [ ] **Step 4: Wire it into the reader view.** In `reader-view.component.ts`, import `ArticleSelection` and add it to `providers`. At the end of the constructor add

```ts
    inject(ArticleSelection).connect({ body: this.content, article: this.entry });
```

(`this.content` is the existing `computed(() => this.articleContent()?.element)`.)

- [ ] **Step 5: Run the specs**

Run: `docker compose exec -T frontend npm test -- src/app/reader/article/selection-share src/app/reader/article/reader-view`
Expected: PASS.

- [ ] **Step 6: Check and build** (`npm run check`, `npx ng build`). Expected: clean.

- [ ] **Step 7: Commit**

```bash
git add frontend/src/app/reader/article/selection-share frontend/src/app/reader/article/reader-view/reader-view.component.ts
git commit -m "feat(#1970): the reader view watches the selection in the article body as a quote"
```

---

### Task 5: The share menu's quote mode, hint, compact trigger and sheet note

**Files:**
- Create: `frontend/src/app/reader/article/share/post-networks.service.ts`
- Create: `frontend/src/app/reader/article/share/share-menu.ts`, `share-menu.spec.ts`
- Modify: `frontend/src/app/reader/article/share/share-button.component.{ts,html,scss,spec.ts}`
- Modify: `frontend/src/app/shared/action-sheet/action-sheet.component.{ts,html,scss}`, `action-sheet.service.spec.ts`
- Modify: `frontend/public/i18n/en.json`, `frontend/public/i18n/de.json`
- Modify: `docs/design-language.md` (`<app-action-sheet>` section)

**Interfaces:**
- Consumes: `ArticleSelection` (Task 4), the share-content helpers and `ConfiguredTarget`/`carriesText` (Task 3), `PostActions.postLink(network, SharedLinkDto)` (Task 2).
- Produces:
  - `PostNetworks` (root) with `connected: Signal<PostNetwork[]>`, where `PostNetwork { readonly network: SocialNetworkName; readonly handles: readonly string[] }`, Bluesky first.
  - `type ShareMenuMode = 'link' | 'hinted' | 'quote'`; `ShareItem` (`post`, `hint`, `divider`, `target`); `type TargetItem = Extract<ShareItem, { kind: 'target' }>`; `shareMenuItems(networks: readonly PostNetwork[], mode: ShareMenuMode): ShareItem[]`.
  - `ShareButtonAppearance = 'toolbar' | 'corner' | 'compact'`. The compact trigger is an `appIconButton` with `aria-label` “Share”.
  - `ActionSheetNote { readonly note: string }`; `ActionSheetItem = ActionSheetAction | ActionSheetDivider | ActionSheetNote`.
  - i18n `reader.share.{quoteSystem, quoteCopy, quoteCopied, linkOnly, quoteHint, selection, selectedWords}`.

- [ ] **Step 1: Add the copy** to `public/i18n/en.json` under `reader.share` (after `pickAccount`):

```json
      "quoteSystem": "Share quote…",
      "quoteCopy": "Copy quote with link",
      "quoteCopied": "Quote copied",
      "linkOnly": "(link only)",
      "quoteHint": "Select text in the article to share it as a quote",
      "selection": "Share the selection",
      "selectedWords": "{{count}} words selected"
```

and to `de.json`:

```json
      "quoteSystem": "Zitat teilen …",
      "quoteCopy": "Zitat mit Link kopieren",
      "quoteCopied": "Zitat kopiert",
      "linkOnly": "(nur Link)",
      "quoteHint": "Text im Artikel markieren, um ihn als Zitat zu teilen",
      "selection": "Markierung teilen",
      "selectedWords": "{{count}} Wörter markiert"
```

The network entries in quote mode reuse the existing `reader.quote.{bluesky,mastodon}` (“Quote on Bluesky” / „Auf Bluesky zitieren“).

- [ ] **Step 2: Write the failing specs.**

`share-menu.spec.ts`:

```ts
import { PostNetwork } from './post-networks.service';
import { ShareItem, TargetItem, shareMenuItems } from './share-menu';

const NETWORKS: PostNetwork[] = [
  { network: 'bluesky', handles: ['me.bsky.social'] },
  { network: 'mastodon', handles: ['@me@mastodon.social'] },
];

const kinds = (items: ShareItem[]) => items.slice(0, 4).map((item) => item.kind);
const target = (items: ShareItem[], label: string): TargetItem =>
  items.find(
    (item): item is TargetItem => item.kind === 'target' && item.target.label === label,
  )!;

describe('shareMenuItems', () => {
  it('lists the posts, a divider and the targets to share the page', () => {
    const items = shareMenuItems(NETWORKS, 'link');

    expect(kinds(items)).toEqual(['post', 'post', 'divider', 'target']);
    expect(items[0]).toEqual(
      expect.objectContaining({ network: 'bluesky', labelKey: 'reader.link.bluesky' }),
    );
    expect(target(items, 'X').linkOnly).toBe(false);
  });

  it('hints under the posts that a selection could be quoted', () => {
    expect(kinds(shareMenuItems(NETWORKS, 'hinted'))).toEqual(['post', 'post', 'hint', 'divider']);
  });

  it('hints at nothing without a network to post to', () => {
    expect(shareMenuItems([], 'hinted').some((item) => item.kind === 'hint')).toBe(false);
  });

  it('quotes on the networks, relabels the copy and marks the targets that take only a link', () => {
    const items = shareMenuItems(NETWORKS, 'quote');
    const copy = items.find(
      (item): item is TargetItem => item.kind === 'target' && item.target.kind === 'copy',
    )!;

    expect(items[0]).toEqual(expect.objectContaining({ labelKey: 'reader.quote.bluesky' }));
    expect(copy.target.labelKey).toBe('reader.share.quoteCopy');
    expect(copy.linkOnly).toBe(false);
    expect(target(items, 'X').linkOnly).toBe(true);
    expect(target(items, 'WhatsApp').linkOnly).toBe(false);
    expect(items.some((item) => item.kind === 'hint')).toBe(false);
  });
});
```

`share-button.component.spec.ts`: add `import { Provider } from '@angular/core';` (merge with the `signal` import), `ArticleSelection` and `SelectedQuote`. Add a module-level `let selectionProviders: Provider[] = [];`. Spread `...selectionProviders` into `mount`'s `providers`. Reset it to `[]` in the outer `beforeEach`. Then add:

```ts
  const QUOTE: SelectedQuote = { text: 'One two three', words: 3, anchorable: true };
  const FRAGMENT = 'https://example.com/a?id=1#:~:text=One%20two%20three';
  const selection = {
    quote: signal<SelectedQuote | null>(null),
    remembered: signal<SelectedQuote | null>(null),
  };

  function inArticle(coarse: boolean): void {
    selection.quote.set(null);
    selection.remembered.set(null);
    selectionProviders = [{ provide: ArticleSelection, useValue: selection }];
    mount(coarse);
  }

  describe('outside an article', () => {
    beforeEach(() => mount(false));

    it('hints at no quote', () => {
      expect(openMenu().querySelector('.quote-hint')).toBeNull();
    });
  });

  describe('in an article, on a fine pointer', () => {
    beforeEach(() => inArticle(false));

    it('hints under the network entries that a selection can be quoted', () => {
      const hint = openMenu().querySelector('.quote-hint');

      expect(hint?.textContent?.trim()).toBe('Select text in the article to share it as a quote');
      expect(hint?.previousElementSibling?.textContent).toContain('Post on Mastodon');
    });

    it('hints at nothing without a network to post to', () => {
      accounts.set([]);
      fixture.detectChanges();

      expect(openMenu().querySelector('.quote-hint')).toBeNull();
    });

    describe('with a selection', () => {
      beforeEach(() => selection.quote.set(QUOTE));

      it('heads the menu with the quote and quotes on the networks', () => {
        const menu = openMenu();

        expect(menu.querySelector('.snippet')?.textContent?.trim()).toBe('“One two three”');
        expect(item(menu, 'Quote on Bluesky')).toBeTruthy();
        expect(item(menu, 'Copy quote with link')).toBeTruthy();
        expect(menu.querySelector('.quote-hint')).toBeNull();
      });

      it('dims a target that takes only a link and says so', () => {
        const x = item(openMenu(), 'X');

        expect(x.classList).toContain('link-only');
        expect(x.textContent).toContain('(link only)');
      });

      it('opens the composer on the quote with a link that points at it', () => {
        item(openMenu(), 'Quote on Mastodon').click();

        expect(postActions.postLink).toHaveBeenCalledWith('mastodon', {
          url: FRAGMENT,
          title: 'First',
          quote: 'One two three',
        });
      });

      it('copies the quote above its link', async () => {
        const writeText = jest.fn().mockResolvedValue(undefined);
        Object.assign(navigator, { clipboard: { writeText } });

        item(openMenu(), 'Copy quote with link').click();
        await Promise.resolve();

        expect(writeText).toHaveBeenCalledWith(`“One two three”\n\n${FRAGMENT}`);
        expect(toast.show).toHaveBeenCalledWith(expect.objectContaining({ message: 'Quote copied' }));
      });

      it('fills a text target with the quote and its link', () => {
        const open = jest.spyOn(window, 'open').mockReturnValue(null);

        item(openMenu(), 'Open in Bluesky app').click();

        expect(open).toHaveBeenCalledWith(
          `https://bsky.app/intent/compose?text=${encodeURIComponent(`“One two three” ${FRAGMENT}`)}`,
          '_blank',
          'noopener,noreferrer',
        );
      });

      it('shares the plain link through a link-only target', () => {
        const open = jest.spyOn(window, 'open').mockReturnValue(null);

        item(openMenu(), 'X').click();

        expect(open).toHaveBeenCalledWith(
          'https://x.com/intent/post?text=First&url=https%3A%2F%2Fexample.com%2Fa%3Fid%3D1',
          '_blank',
          'noopener,noreferrer',
        );
      });

      it('keeps the quote it opened with when the selection collapses', () => {
        const menu = openMenu();
        selection.quote.set(null);
        fixture.detectChanges();

        expect(menu.querySelector('.snippet')).not.toBeNull();
      });

      it('keeps the selection when the trigger is pressed', () => {
        const press = new MouseEvent('mousedown', { bubbles: true, cancelable: true });
        (fixture.nativeElement as HTMLElement).querySelector('button')!.dispatchEvent(press);

        expect(press.defaultPrevented).toBe(true);
      });
    });
  });

  describe('in an article, on a coarse pointer', () => {
    beforeEach(() => inArticle(true));

    const sheetData = (): ActionSheetData => sheet.open.mock.calls[0][0] as ActionSheetData;

    it('puts the hint on the sheet as a note under the network entries', () => {
      press();

      expect(sheetData().actions[2]).toEqual({
        note: 'Select text in the article to share it as a quote',
      });
    });

    it('opens the sheet in quote mode from the remembered selection', () => {
      selection.remembered.set(QUOTE);
      press();

      const actions = sheetData().actions as ActionSheetAction[];
      expect(sheetData().title).toBe('“One two three”');
      expect(actions[0].label).toBe('Quote on Bluesky');
      expect(actions.find((action) => action.label === 'X')?.hint).toBe('(link only)');
    });

    it('opens the composer on the remembered quote', () => {
      selection.remembered.set(QUOTE);
      press();

      chosen.next('post-bluesky');

      expect(postActions.postLink).toHaveBeenCalledWith('bluesky', {
        url: FRAGMENT,
        title: 'First',
        quote: 'One two three',
      });
    });
  });

  describe('as a compact trigger', () => {
    beforeEach(() => mount(false, 'First', 'compact'));

    it('is an icon button that opens the same menu', () => {
      const trigger = (fixture.nativeElement as HTMLElement).querySelector('button')!;

      expect(trigger.classList).toContain('ib');
      expect(trigger.getAttribute('aria-label')).toBe('Share');
      expect(item(openMenu(), 'Copy link')).toBeTruthy();
    });
  });
```

`mount`'s third parameter type becomes `ShareButtonAppearance` (import it).

`action-sheet.service.spec.ts`: add

```ts
  it('renders a note as a line of text between the actions', () => {
    open({
      title: 'Share',
      actions: [{ id: 'post', label: 'Post' }, { note: 'Select text to quote it' }],
    }).subscribe();

    expect(items()).toHaveLength(1);
    expect(container.querySelector('.note')?.textContent?.trim()).toBe('Select text to quote it');
  });
```

- [ ] **Step 3: Run them and watch them fail**

Run: `docker compose exec -T frontend npm test -- src/app/reader/article/share src/app/shared/action-sheet`
Expected: FAIL. `share-menu` and the note type are missing, and the new button tests fail.

- [ ] **Step 4: Implement the note in the sheet.**

`action-sheet.component.ts`, beside the divider:

```ts
/** A line of text among the actions, already translated: a hint that belongs to no single action. */
export interface ActionSheetNote {
  readonly note: string;
}
export type ActionSheetItem = ActionSheetAction | ActionSheetDivider | ActionSheetNote;
```

and in the class

```ts
  protected isNote(item: ActionSheetItem): item is ActionSheetNote {
    return 'note' in item;
  }
```

`action-sheet.component.html`: inside the `@for`, between the divider branch and the button:

```html
    } @else if (isNote(item)) {
      <p class="note" role="presentation">{{ item.note }}</p>
    } @else {
```

`action-sheet.component.scss`:

```scss
.note {
  margin: 0;
  padding: 0 var(--row-pad-x) var(--space-1);
  color: var(--text-muted);
  font-size: var(--fs-sm);
}
```

`docs/design-language.md`, `<app-action-sheet>` section, after the `ACTION_SHEET_DIVIDER` sentence: “A `{ note }` item renders a muted line of text in place among the actions — a hint no single action owns (the share sheet's quote hint, #1970).”

- [ ] **Step 5: Implement `PostNetworks`** (`post-networks.service.ts`):

```ts
import { Injectable, computed, inject } from '@angular/core';
import { SOCIAL_NETWORKS, SocialNetworkName } from '../../../core/social-network';
import { SubscriptionsStore } from '../../state/subscriptions.store';

export interface PostNetwork {
  readonly network: SocialNetworkName;
  readonly handles: readonly string[];
}

/** The networks the reader can post to, Bluesky first, with the handles of each one's connected accounts. */
@Injectable({ providedIn: 'root' })
export class PostNetworks {
  private readonly subscriptions = inject(SubscriptionsStore);

  readonly connected = computed((): PostNetwork[] => {
    const connected = this.subscriptions
      .socialAccounts()
      .filter((account) => account.status === 'connected');
    return SOCIAL_NETWORKS.flatMap((network) => {
      const handles = connected
        .filter((account) => account.network === network)
        .map((account) => account.handle);
      return handles.length === 0 ? [] : [{ network, handles }];
    });
  });
}
```

- [ ] **Step 6: Implement `share-menu.ts`:**

```ts
import { SocialNetworkName } from '../../../core/social-network';
import { PostNetwork } from './post-networks.service';
import { ConfiguredTarget, SHARE_TARGETS, ShareTarget, carriesText } from './share-targets';

/** What the menu shares: the page, the page with a hint that a selection could be quoted, or a selected quote. */
export type ShareMenuMode = 'link' | 'hinted' | 'quote';

/** One entry of the menu and of the sheet, under the id the sheet answers with. */
export type ShareItem =
  | {
      readonly kind: 'post';
      readonly id: string;
      readonly network: SocialNetworkName;
      readonly handles: readonly string[];
      readonly labelKey: string;
    }
  | { readonly kind: 'hint'; readonly id: string }
  | { readonly kind: 'divider'; readonly id: string }
  | {
      readonly kind: 'target';
      readonly id: string;
      readonly target: ConfiguredTarget;
      readonly linkOnly: boolean;
    };

export type TargetItem = Extract<ShareItem, { kind: 'target' }>;

const QUOTE_LABELS: Partial<Record<ConfiguredTarget['kind'], string>> = {
  system: 'reader.share.quoteSystem',
  copy: 'reader.share.quoteCopy',
};

export function shareMenuItems(networks: readonly PostNetwork[], mode: ShareMenuMode): ShareItem[] {
  const posts = networks.map((network) => postItem(network, mode));
  const hint: ShareItem[] =
    mode === 'hinted' && posts.length > 0 ? [{ kind: 'hint', id: 'quote-hint' }] : [];
  const divider: ShareItem[] = posts.length === 0 ? [] : [{ kind: 'divider', id: 'post-divider' }];
  const targets = SHARE_TARGETS.map((target, index) => configuredItem(target, index, mode));
  return [...posts, ...hint, ...divider, ...targets];
}

function postItem({ network, handles }: PostNetwork, mode: ShareMenuMode): ShareItem {
  const labelKey = mode === 'quote' ? `reader.quote.${network}` : `reader.link.${network}`;
  return { kind: 'post', id: `post-${network}`, network, handles, labelKey };
}

function configuredItem(target: ShareTarget, index: number, mode: ShareMenuMode): ShareItem {
  const id = String(index);
  if (target.kind === 'divider') return { kind: 'divider', id };
  if (mode !== 'quote') return { kind: 'target', id, target, linkOnly: false };
  const labelKey = QUOTE_LABELS[target.kind] ?? target.labelKey;
  return { kind: 'target', id, target: { ...target, labelKey }, linkOnly: !carriesText(target) };
}
```

- [ ] **Step 7: Rewrite `share-button.component.ts`:**

```ts
import {
  ChangeDetectionStrategy,
  Component,
  DestroyRef,
  computed,
  effect,
  inject,
  input,
  signal,
  viewChild,
} from '@angular/core';
import { takeUntilDestroyed } from '@angular/core/rxjs-interop';
import { CdkTrapFocus } from '@angular/cdk/a11y';
import { CdkConnectedOverlay, CdkOverlayOrigin, ConnectedPosition } from '@angular/cdk/overlay';
import { TranslocoPipe, TranslocoService } from '@jsverse/transloco';
import {
  ACTION_SHEET_DIVIDER,
  ActionSheetAction,
  ActionSheetItem,
} from '../../../shared/action-sheet/action-sheet.component';
import { ActionSheet } from '../../../shared/action-sheet/action-sheet.service';
import { IconComponent } from '../../../shared/icon/icon.component';
import { IconButtonDirective } from '../../../shared/icon-button/icon-button.directive';
import { ListActionDirective } from '../../../shared/list-action/list-action.directive';
import { MaskedLogoComponent } from '../../../shared/masked-logo/masked-logo.component';
import { NetworkLogoComponent } from '../../../shared/network-logo/network-logo.component';
import { openExternal } from '../../../shared/open-external';
import { CONFIRMATION_DURATION_MS, ToastService } from '../../../shared/toast/toast.service';
import { stepMenuFocus } from '../../../shared/menu-keys';
import { SocialNetworkName } from '../../../core/social-network';
import { LayoutService } from '../../layout.service';
import { PostActions } from '../composer/post-actions.service';
import { ArticleSelection } from '../selection-share/article-selection.service';
import { SelectedQuote } from '../selection-share/selected-quote';
import { PostNetworks } from './post-networks.service';
import {
  ShareContent,
  SharedPage,
  clipboardText,
  clippedAtWord,
  linkContent,
  quoteContent,
  sharedLink,
} from './share-content';
import { ShareItem, ShareMenuMode, TargetItem, shareMenuItems } from './share-menu';
import { SHARE_GLYPH, shareLogoUrl, shareUrl } from './share-targets';
import { stripTracking } from './tracking-params';

/** Where the trigger sits: in the article toolbar, as a disc in the article's corner, or among a selection's actions. */
export type ShareButtonAppearance = 'toolbar' | 'corner' | 'compact';

const POSITIONS: Record<ShareButtonAppearance, ConnectedPosition[]> = {
  toolbar: [
    { originX: 'end', originY: 'bottom', overlayX: 'end', overlayY: 'top', offsetY: 6 },
    { originX: 'end', originY: 'top', overlayX: 'end', overlayY: 'bottom', offsetY: -6 },
  ],
  corner: [
    { originX: 'start', originY: 'top', overlayX: 'start', overlayY: 'bottom', offsetY: -6 },
  ],
  compact: [
    { originX: 'center', originY: 'bottom', overlayX: 'center', overlayY: 'top', offsetY: 6 },
    { originX: 'center', originY: 'top', overlayX: 'center', overlayY: 'bottom', offsetY: -6 },
  ],
};

const SHEET_SNIPPET_LENGTH = 60;

@Component({
  selector: 'app-share-button',
  imports: [
    TranslocoPipe,
    IconComponent,
    IconButtonDirective,
    ListActionDirective,
    MaskedLogoComponent,
    NetworkLogoComponent,
    CdkConnectedOverlay,
    CdkOverlayOrigin,
    CdkTrapFocus,
  ],
  templateUrl: './share-button.component.html',
  styleUrl: './share-button.component.scss',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class ShareButtonComponent {
  readonly url = input.required<string>();
  readonly title = input('');
  readonly appearance = input<ShareButtonAppearance>('toolbar');

  private readonly toast = inject(ToastService);
  private readonly i18n = inject(TranslocoService);
  private readonly screen = inject(LayoutService);
  private readonly sheet = inject(ActionSheet);
  private readonly destroyRef = inject(DestroyRef);
  private readonly postActions = inject(PostActions);
  private readonly selection = inject(ArticleSelection, { optional: true });
  private readonly networks = inject(PostNetworks).connected;

  protected readonly glyph = SHARE_GLYPH;
  protected readonly positions = computed(() => POSITIONS[this.appearance()]);
  protected readonly logoUrl = shareLogoUrl;
  protected readonly open = signal(false);
  protected readonly origin = viewChild.required(CdkOverlayOrigin);
  /** Caught when the menu opens: the press that opens it may collapse the selection it shares. */
  protected readonly quote = signal<SelectedQuote | null>(null);
  private readonly mode = computed((): ShareMenuMode => {
    if (this.quote() !== null) return 'quote';
    return this.selection === null ? 'link' : 'hinted';
  });
  protected readonly items = computed(() => shareMenuItems(this.networks(), this.mode()));
  private readonly itemsById = computed(() => new Map(this.items().map((item) => [item.id, item])));

  constructor() {
    effect(() => {
      this.url();
      this.open.set(false);
    });
  }

  protected press(): void {
    this.quote.set(this.selectedQuote());
    if (this.screen.isCoarse()) this.openSheet();
    else this.open.update((open) => !open);
  }

  /** Runs inside the click, so Safari still sees the gesture that share and clipboard need. */
  protected choose(item: TargetItem): void {
    this.open.set(false);
    const content = this.contentFor(item);
    const target = item.target;
    if (target.kind === 'system') this.shareThroughSystem(content);
    if (target.kind === 'copy') this.copy(content);
    if (target.kind === 'link') this.visit(shareUrl(target.url, content));
  }

  protected post(network: SocialNetworkName): void {
    this.open.set(false);
    this.postActions.postLink(network, sharedLink(this.page(), this.quote()));
  }

  protected closed(): void {
    this.open.set(false);
    (this.origin().elementRef.nativeElement as HTMLElement).focus();
  }

  protected step(event: KeyboardEvent): void {
    const menu = (event.target as Element).closest('[role="menu"]');
    if (menu !== null) stepMenuFocus(event, menu);
  }

  private selectedQuote(): SelectedQuote | null {
    if (this.selection === null) return null;
    return this.screen.isCoarse() ? this.selection.remembered() : this.selection.quote();
  }

  private page(): SharedPage {
    return { url: stripTracking(this.url()), title: this.title() };
  }

  private contentFor(item: TargetItem): ShareContent {
    const quote = this.quote();
    return quote === null || item.linkOnly
      ? linkContent(this.page())
      : quoteContent(this.page(), quote);
  }

  private postHint(handles: readonly string[]): string {
    return handles.length === 1
      ? this.i18n.translate('reader.share.postAs', { handle: handles[0] })
      : this.i18n.translate('reader.share.pickAccount');
  }

  private openSheet(): void {
    this.sheet
      .open({
        title: this.sheetTitle(),
        actions: this.items().map((item) => this.sheetItem(item)),
      })
      .pipe(takeUntilDestroyed(this.destroyRef))
      .subscribe((choice) => this.chooseById(choice));
  }

  private sheetTitle(): string {
    const quote = this.quote();
    return quote === null
      ? this.i18n.translate('reader.share.button')
      : `“${clippedAtWord(quote.text, SHEET_SNIPPET_LENGTH)}”`;
  }

  private sheetItem(item: ShareItem): ActionSheetItem {
    switch (item.kind) {
      case 'divider':
        return ACTION_SHEET_DIVIDER;
      case 'hint':
        return { note: this.i18n.translate('reader.share.quoteHint') };
      case 'post':
        return {
          id: item.id,
          label: this.i18n.translate(item.labelKey),
          hint: this.postHint(item.handles),
          logo: {
            url: shareLogoUrl(`${item.network}.svg`),
            color: `var(--brand-${item.network})`,
          },
        };
      case 'target':
        return this.targetSheetItem(item);
    }
  }

  private targetSheetItem({ id, target, linkOnly }: TargetItem): ActionSheetAction {
    return {
      id,
      label: target.labelKey ? this.i18n.translate(target.labelKey) : (target.label ?? ''),
      symbol: target.icon ? undefined : (target.symbol ?? this.glyph),
      logo: target.icon ? { url: shareLogoUrl(target.icon), color: target.color } : undefined,
      ...(linkOnly ? { hint: this.i18n.translate('reader.share.linkOnly') } : {}),
    };
  }

  private chooseById(choice: string | undefined): void {
    const item = choice === undefined ? undefined : this.itemsById().get(choice);
    if (item?.kind === 'post') this.post(item.network);
    if (item?.kind === 'target') this.choose(item);
  }

  private shareThroughSystem(content: ShareContent): void {
    const text = content.quote === null ? {} : { text: content.quote };
    navigator.share({ title: content.title, url: content.url, ...text }).catch((error: unknown) => {
      if (error instanceof DOMException && error.name === 'AbortError') return;
      this.notify('reader.share.failed');
    });
  }

  private copy(content: ShareContent): void {
    const copied = content.quote === null ? 'reader.share.copied' : 'reader.share.quoteCopied';
    (navigator.clipboard?.writeText(clipboardText(content)) ?? Promise.reject()).then(
      () => this.notify(copied, CONFIRMATION_DURATION_MS),
      () => this.notify('reader.share.copyFailed'),
    );
  }

  /** mailto: hands off to another program; a new tab for it would stay blank. */
  private visit(address: string): void {
    if (/^https?:/i.test(address)) openExternal(address);
    else window.location.assign(address);
  }

  private notify(key: string, durationMs?: number): void {
    this.toast.show({ message: this.i18n.translate(key), durationMs });
  }
}
```

The `SubscriptionsStore` import, `activeLang`, `postItems` and `configuredItem` go. `PostNetworks` and `share-menu.ts` own them now, and the popover's hints translate through the pipe.

- [ ] **Step 8: Rewrite `share-button.component.html`:**

```html
@switch (appearance()) {
  @case ('corner') {
    <button
      class="corner-share"
      type="button"
      aria-haspopup="menu"
      [attr.aria-expanded]="open()"
      [attr.aria-label]="'reader.share.button' | transloco"
      [attr.title]="'reader.share.button' | transloco"
      cdkOverlayOrigin
      (mousedown)="$event.preventDefault()"
      (click)="press()"
    >
      <app-icon [name]="glyph" size="dense" />
    </button>
  }
  @case ('compact') {
    <button
      appIconButton
      type="button"
      aria-haspopup="menu"
      [attr.aria-expanded]="open()"
      [attr.aria-label]="'reader.share.button' | transloco"
      [attr.title]="'reader.share.button' | transloco"
      cdkOverlayOrigin
      (mousedown)="$event.preventDefault()"
      (click)="press()"
    >
      <app-icon [name]="glyph" size="sm" />
    </button>
  }
  @default {
    <button
      appListAction
      class="mobile-icon-only"
      type="button"
      aria-haspopup="menu"
      [attr.aria-expanded]="open()"
      [attr.aria-label]="'reader.share.button' | transloco"
      cdkOverlayOrigin
      (mousedown)="$event.preventDefault()"
      (click)="press()"
    >
      <app-icon [name]="glyph" size="sm" />
      <span class="txt">{{ 'reader.share.button' | transloco }}</span>
    </button>
  }
}
<ng-template
  cdkConnectedOverlay
  [cdkConnectedOverlayOrigin]="origin()"
  [cdkConnectedOverlayOpen]="open()"
  [cdkConnectedOverlayPositions]="positions()"
  [cdkConnectedOverlayPush]="true"
  [cdkConnectedOverlayViewportMargin]="8"
  [cdkConnectedOverlayHasBackdrop]="true"
  [cdkConnectedOverlayDisposeOnNavigation]="true"
  cdkConnectedOverlayBackdropClass="cdk-overlay-transparent-backdrop"
  (backdropClick)="open.set(false)"
  (overlayKeydown)="step($event)"
  (detach)="closed()"
>
  <div
    class="menu"
    cdkTrapFocus
    cdkTrapFocusAutoCapture
    role="menu"
    [attr.aria-label]="'reader.share.button' | transloco"
    (mousedown)="$event.preventDefault()"
  >
    @if (quote(); as quote) {
      <p class="snippet" role="presentation">“{{ quote.text }}”</p>
      <hr role="separator" />
    }
    @for (item of items(); track item.id) {
      @switch (item.kind) {
        @case ('post') {
          <button
            role="menuitem"
            type="button"
            [title]="
              item.handles.length === 1
                ? ('reader.share.postAs' | transloco: { handle: item.handles[0] })
                : ('reader.share.pickAccount' | transloco)
            "
            (click)="post(item.network)"
          >
            <app-network-logo [network]="item.network" />
            <span class="label">{{ item.labelKey | transloco }}</span>
          </button>
        }
        @case ('hint') {
          <p class="quote-hint" role="presentation">{{ 'reader.share.quoteHint' | transloco }}</p>
        }
        @case ('divider') {
          <hr role="separator" />
        }
        @case ('target') {
          <button
            role="menuitem"
            type="button"
            [class.link-only]="item.linkOnly"
            (click)="choose(item)"
          >
            @if (item.target.icon) {
              <app-masked-logo [src]="logoUrl(item.target.icon)" [color]="item.target.color" />
            } @else {
              <app-icon [name]="item.target.symbol ?? glyph" size="sm" />
            }
            <span class="label">{{
              item.target.labelKey ? (item.target.labelKey | transloco) : item.target.label
            }}</span>
            @if (item.linkOnly) {
              <span class="link-only-note">{{ 'reader.share.linkOnly' | transloco }}</span>
            }
            @if (item.target.external) {
              <app-icon class="external" name="open_in_new" size="sm" />
            }
          </button>
        }
      }
    }
  </div>
</ng-template>
```

- [ ] **Step 9: Style the new parts** in `share-button.component.scss` (append):

```scss
/* Sized by the menu, never sizing it: a long quote ellipsises inside the items' width. */
.snippet,
.quote-hint {
  inline-size: 0;
  min-inline-size: 100%;
  margin: 0;
  padding: var(--row-pad-y) var(--row-pad-x);
}

.snippet {
  overflow: hidden;
  white-space: nowrap;
  text-overflow: ellipsis;
  font-style: italic;
  color: var(--text-secondary);
}

.quote-hint {
  padding-block-start: 0;
  font-size: var(--fs-sm);
  color: var(--text-muted);
}

.link-only .label {
  color: var(--text-muted);
}

.link-only-note {
  margin-inline-start: auto;
  font-size: var(--fs-sm);
  color: var(--text-muted);
}
```

- [ ] **Step 10: Run the specs**

Run: `docker compose exec -T frontend npm test -- src/app/reader/article/share src/app/shared/action-sheet src/app/reader/article/reader-view src/app/reader/article/corner-actions src/app/reader/article/link-reader`
Expected: PASS. The existing button specs keep passing: there is no `ArticleSelection` there, so the mode stays `link`.

- [ ] **Step 11: Check and build** (`npm run check`, `npx ng build`). Expected: clean, i18n parity included.

- [ ] **Step 12: Commit**

```bash
git add frontend/src/app/reader/article/share frontend/src/app/shared/action-sheet frontend/public/i18n docs/design-language.md
git commit -m "feat(#1970): the share menu shares a selected quote, and hints at it without one"
```

---

### Task 6: The desktop selection bubble

**Files:**
- Create: `frontend/src/app/reader/article/selection-share/selection-actions.component.{ts,html,scss}`
- Create: `frontend/src/app/reader/article/selection-share/selection-bubble.component.{ts,html,scss,spec.ts}`
- Modify: `frontend/src/app/reader/article/reader-view/reader-view.component.{ts,html}`
- Modify: `docs/design-language.md` (Conventions: a short “Selection share (#1970)” subsection)

**Interfaces:**
- Consumes: `ArticleSelection.{quote, remembered, range}` (Task 4), `PostNetworks.connected`, `ShareButtonComponent` `appearance="compact"` (Task 5), `sharedLink`, `stripTracking`, `PostActions.postLink`.
- Produces: `<app-selection-actions [url] [title]>` (projects extra content first, then one `appIconButton` per network with `aria-label` `reader.quote.<network>`, then the compact share button); `<app-selection-bubble [url] [title]>`.

- [ ] **Step 1: Write the failing spec** `selection-bubble.component.spec.ts`:

```ts
import { signal } from '@angular/core';
import { ComponentFixture, TestBed } from '@angular/core/testing';
import { provideTranslocoTesting } from '../../../../testing/transloco-testing';
import { ActionSheet } from '../../../shared/action-sheet/action-sheet.service';
import { ToastService } from '../../../shared/toast/toast.service';
import { LayoutService } from '../../layout.service';
import { SocialAccountRefDto } from '../../social-feed';
import { SubscriptionsStore } from '../../state/subscriptions.store';
import { PostActions } from '../composer/post-actions.service';
import { ArticleSelection } from './article-selection.service';
import { SelectedQuote } from './selected-quote';
import { SelectionBubbleComponent } from './selection-bubble.component';

const QUOTE: SelectedQuote = { text: 'One two three', words: 3, anchorable: true };

function rangeAt(top: number): Range {
  const rect = { left: 100, top, bottom: top + 20, right: 300, width: 200, height: 20 } as DOMRect;
  return { getClientRects: () => ({ length: 1, item: () => rect }) } as unknown as Range;
}

describe('SelectionBubbleComponent', () => {
  const postActions = { postLink: jest.fn() };
  const selection = {
    quote: signal<SelectedQuote | null>(null),
    remembered: signal<SelectedQuote | null>(null),
    range: signal<Range | null>(null),
  };
  const accounts = signal<SocialAccountRefDto[]>([
    { id: 1, network: 'bluesky', handle: 'me.bsky.social', status: 'connected' },
  ]);
  let fixture: ComponentFixture<SelectionBubbleComponent>;

  beforeEach(() => {
    postActions.postLink.mockReset();
    selection.quote.set(null);
    selection.remembered.set(null);
    selection.range.set(null);
    TestBed.configureTestingModule({
      imports: [SelectionBubbleComponent, provideTranslocoTesting()],
      providers: [
        { provide: ArticleSelection, useValue: selection },
        { provide: PostActions, useValue: postActions },
        { provide: SubscriptionsStore, useValue: { socialAccounts: accounts } },
        { provide: LayoutService, useValue: { isCoarse: signal(false) } },
        { provide: ActionSheet, useValue: { open: jest.fn() } },
        { provide: ToastService, useValue: { show: jest.fn() } },
      ],
    });
    fixture = TestBed.createComponent(SelectionBubbleComponent);
    fixture.componentRef.setInput('url', 'https://example.com/a?utm_source=rss');
    fixture.componentRef.setInput('title', 'First');
    fixture.detectChanges();
  });

  afterEach(() => fixture.destroy());

  const bubble = () => document.querySelector<HTMLElement>('.selection-bubble');

  function select(top = 180): void {
    selection.quote.set(QUOTE);
    selection.remembered.set(QUOTE);
    selection.range.set(rangeAt(top));
    fixture.detectChanges();
  }

  it('shows nothing without a quote', () => {
    expect(bubble()).toBeNull();
  });

  it('offers a quote per connected network and the share menu', () => {
    select();

    expect(bubble()!.querySelector('[aria-label="Quote on Bluesky"]')).not.toBeNull();
    expect(bubble()!.querySelector('[aria-label="Quote on Mastodon"]')).toBeNull();
    expect(bubble()!.querySelector('[aria-label="Share"]')).not.toBeNull();
  });

  it('opens the composer on the quote with a link that points at it', () => {
    select();

    bubble()!.querySelector<HTMLButtonElement>('[aria-label="Quote on Bluesky"]')!.click();

    expect(postActions.postLink).toHaveBeenCalledWith('bluesky', {
      url: 'https://example.com/a#:~:text=One%20two%20three',
      title: 'First',
      quote: 'One two three',
    });
  });

  it('keeps the selection when pressed', () => {
    select();
    const press = new MouseEvent('mousedown', { bubbles: true, cancelable: true });

    bubble()!.querySelector('button')!.dispatchEvent(press);

    expect(press.defaultPrevented).toBe(true);
  });

  it('closes when the selection scrolls out of view', () => {
    select(-100);

    expect(bubble()).toBeNull();
  });

  it('closes when the selection ends', () => {
    select();
    selection.quote.set(null);
    selection.range.set(null);
    fixture.detectChanges();

    expect(bubble()).toBeNull();
  });
});
```

- [ ] **Step 2: Run it and watch it fail**

Run: `docker compose exec -T frontend npm test -- src/app/reader/article/selection-share/selection-bubble.component.spec.ts`
Expected: FAIL. The component does not exist yet.

- [ ] **Step 3: Implement `SelectionActionsComponent`.**

`selection-actions.component.ts`:

```ts
import { ChangeDetectionStrategy, Component, inject, input } from '@angular/core';
import { TranslocoPipe } from '@jsverse/transloco';
import { SocialNetworkName } from '../../../core/social-network';
import { IconButtonDirective } from '../../../shared/icon-button/icon-button.directive';
import { NetworkLogoComponent } from '../../../shared/network-logo/network-logo.component';
import { PostActions } from '../composer/post-actions.service';
import { PostNetworks } from '../share/post-networks.service';
import { ShareButtonComponent } from '../share/share-button.component';
import { sharedLink } from '../share/share-content';
import { stripTracking } from '../share/tracking-params';
import { ArticleSelection } from './article-selection.service';

/** What a selection offers, in the bubble and the phone bar alike: a quote per network, and the share menu. */
@Component({
  selector: 'app-selection-actions',
  imports: [TranslocoPipe, IconButtonDirective, NetworkLogoComponent, ShareButtonComponent],
  templateUrl: './selection-actions.component.html',
  styleUrl: './selection-actions.component.scss',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class SelectionActionsComponent {
  readonly url = input.required<string>();
  readonly title = input('');

  private readonly selection = inject(ArticleSelection);
  private readonly postActions = inject(PostActions);
  protected readonly networks = inject(PostNetworks).connected;

  protected post(network: SocialNetworkName): void {
    const page = { url: stripTracking(this.url()), title: this.title() };
    this.postActions.postLink(network, sharedLink(page, this.selection.remembered()));
  }
}
```

`selection-actions.component.html`:

```html
<div class="actions" role="toolbar" [attr.aria-label]="'reader.share.selection' | transloco">
  <ng-content />
  @for (entry of networks(); track entry.network) {
    <button
      appIconButton
      type="button"
      [attr.aria-label]="'reader.quote.' + entry.network | transloco"
      [attr.title]="'reader.quote.' + entry.network | transloco"
      (click)="post(entry.network)"
    >
      <app-network-logo [network]="entry.network" />
    </button>
  }
  <app-share-button appearance="compact" [url]="url()" [title]="title()" />
</div>
```

`selection-actions.component.scss`:

```scss
:host {
  display: block;
}

.actions {
  display: flex;
  align-items: center;
  gap: var(--space-1);
}

app-network-logo {
  width: var(--icon-sm);
  height: var(--icon-sm);
}
```

- [ ] **Step 4: Implement `SelectionBubbleComponent`.**

`selection-bubble.component.ts`:

```ts
import { DOCUMENT } from '@angular/common';
import { ChangeDetectionStrategy, Component, computed, inject, input, signal } from '@angular/core';
import { takeUntilDestroyed } from '@angular/core/rxjs-interop';
import {
  CdkConnectedOverlay,
  ConnectedPosition,
  FlexibleConnectedPositionStrategyOrigin,
} from '@angular/cdk/overlay';
import { fromEvent } from 'rxjs';
import { ArticleSelection } from './article-selection.service';
import { SelectionActionsComponent } from './selection-actions.component';

const UNDER_THE_LAST_LINE: ConnectedPosition[] = [
  { originX: 'end', originY: 'bottom', overlayX: 'center', overlayY: 'top', offsetY: 8 },
  { originX: 'end', originY: 'top', overlayX: 'center', overlayY: 'bottom', offsetY: -8 },
];

/** The selection's actions under its last line, on a fine pointer; a phone's own callout owns that spot. */
@Component({
  selector: 'app-selection-bubble',
  imports: [CdkConnectedOverlay, SelectionActionsComponent],
  templateUrl: './selection-bubble.component.html',
  styleUrl: './selection-bubble.component.scss',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class SelectionBubbleComponent {
  readonly url = input.required<string>();
  readonly title = input('');

  private readonly selection = inject(ArticleSelection);
  private readonly scrolls = signal(0);
  protected readonly positions = UNDER_THE_LAST_LINE;
  protected readonly nowhere: FlexibleConnectedPositionStrategyOrigin = { x: 0, y: 0 };
  /** The last line of a quotable selection while it is on screen; null closes the bubble. */
  protected readonly origin = computed((): FlexibleConnectedPositionStrategyOrigin | null => {
    this.scrolls();
    const range = this.selection.range();
    if (this.selection.quote() === null || range === null) return null;
    const lines = range.getClientRects();
    const last = lines.item(lines.length - 1);
    if (last === null || !onScreen(last)) return null;
    return { x: last.left, y: last.top, width: last.width, height: last.height };
  });

  constructor() {
    fromEvent(inject(DOCUMENT), 'scroll', { capture: true, passive: true })
      .pipe(takeUntilDestroyed())
      .subscribe(() => this.scrolls.update((count) => count + 1));
  }
}

function onScreen(line: DOMRect): boolean {
  return line.bottom > 0 && line.top < window.innerHeight;
}
```

`selection-bubble.component.html`:

```html
<ng-template
  cdkConnectedOverlay
  [cdkConnectedOverlayOrigin]="origin() ?? nowhere"
  [cdkConnectedOverlayOpen]="origin() !== null"
  [cdkConnectedOverlayPositions]="positions"
  [cdkConnectedOverlayPush]="true"
  [cdkConnectedOverlayViewportMargin]="8"
>
  <app-selection-actions
    class="selection-bubble"
    [url]="url()"
    [title]="title()"
    (mousedown)="$event.preventDefault()"
  />
</ng-template>
```

`selection-bubble.component.scss`:

```scss
@use '../../../theme/glass-rim';

.selection-bubble {
  @include glass-rim.filled(var(--surface-2));

  border-radius: var(--radius-pill);
  padding: var(--space-1);
  box-shadow: var(--panel-shadow);
}
```

- [ ] **Step 5: Place it in the reader view.** In `reader-view.component.ts` import `SelectionBubbleComponent` into `imports`, and add `protected readonly coarse = this.screen.isCoarse;` below the `screen` field. In `reader-view.component.html`, right before `<app-loading-overlay …>`:

```html
    @if (e.url && !coarse()) {
      <app-selection-bubble [url]="e.url" [title]="e.title" />
    }
```

- [ ] **Step 6: Document the surface.** In `docs/design-language.md` §3 Conventions, add a `### Selection share (#1970)` subsection:

> A selection of three or more words in the article body offers itself as a quote. On a fine pointer a pill-shaped bubble sits under the selection's last line (CDK overlay, `--radius-pill`, glass rim, `--panel-shadow`). On a coarse pointer a bar docks at the foot of the article (the layer's `articleDock` slot). Both render `<app-selection-actions>`: one `appIconButton` per connected network, then `<app-share-button appearance="compact">`. Their `mousedown` is `preventDefault`-ed so a click keeps the selection.

- [ ] **Step 7: Run the specs**

Run: `docker compose exec -T frontend npm test -- src/app/reader/article/selection-share src/app/reader/article/reader-view`
Expected: PASS.

- [ ] **Step 8: Check and build** (`npm run check`, `npx ng build`). Expected: clean.

- [ ] **Step 9: Commit**

```bash
git add frontend/src/app/reader/article/selection-share frontend/src/app/reader/article/reader-view docs/design-language.md
git commit -m "feat(#1970): a bubble under the selection quotes it on a network or opens the share menu"
```

---

### Task 7: The phone bar

**Files:**
- Create: `frontend/src/app/reader/article/selection-share/selection-bar.component.{ts,html,scss,spec.ts}`
- Modify: `frontend/src/app/reader/article/article-layer/article-layer.component.{html,scss,spec.ts}`
- Modify: `frontend/src/app/reader/article/reader-view/reader-view.component.{ts,html}`

**Interfaces:**
- Consumes: `SelectionActionsComponent` (Task 6), `ArticleSelection.quote` (Task 4), i18n `reader.share.selectedWords` (Task 5).
- Produces: `<app-selection-bar [url] [title]>`; `ArticleLayerComponent` slot `[articleDock]`.

- [ ] **Step 1: Write the failing specs.**

`selection-bar.component.spec.ts`:

```ts
import { signal } from '@angular/core';
import { ComponentFixture, TestBed } from '@angular/core/testing';
import { provideTranslocoTesting } from '../../../../testing/transloco-testing';
import { ActionSheet } from '../../../shared/action-sheet/action-sheet.service';
import { ToastService } from '../../../shared/toast/toast.service';
import { LayoutService } from '../../layout.service';
import { SocialAccountRefDto } from '../../social-feed';
import { SubscriptionsStore } from '../../state/subscriptions.store';
import { PostActions } from '../composer/post-actions.service';
import { ArticleSelection } from './article-selection.service';
import { SelectedQuote } from './selected-quote';
import { SelectionBarComponent } from './selection-bar.component';

const QUOTE: SelectedQuote = { text: 'One two three', words: 3, anchorable: true };

describe('SelectionBarComponent', () => {
  const postActions = { postLink: jest.fn() };
  const selection = {
    quote: signal<SelectedQuote | null>(null),
    remembered: signal<SelectedQuote | null>(null),
  };
  const accounts = signal<SocialAccountRefDto[]>([
    { id: 1, network: 'bluesky', handle: 'me.bsky.social', status: 'connected' },
    { id: 2, network: 'mastodon', handle: '@me@mastodon.social', status: 'connected' },
  ]);
  let fixture: ComponentFixture<SelectionBarComponent>;

  beforeEach(() => {
    postActions.postLink.mockReset();
    selection.quote.set(null);
    selection.remembered.set(null);
    TestBed.configureTestingModule({
      imports: [SelectionBarComponent, provideTranslocoTesting()],
      providers: [
        { provide: ArticleSelection, useValue: selection },
        { provide: PostActions, useValue: postActions },
        { provide: SubscriptionsStore, useValue: { socialAccounts: accounts } },
        { provide: LayoutService, useValue: { isCoarse: signal(true) } },
        { provide: ActionSheet, useValue: { open: jest.fn() } },
        { provide: ToastService, useValue: { show: jest.fn() } },
      ],
    });
    fixture = TestBed.createComponent(SelectionBarComponent);
    fixture.componentRef.setInput('url', 'https://example.com/a');
    fixture.componentRef.setInput('title', 'First');
    fixture.detectChanges();
  });

  afterEach(() => fixture.destroy());

  const element = () => fixture.nativeElement as HTMLElement;

  it('stays away without a selection', () => {
    expect(element().querySelector('.selection-bar')).toBeNull();
  });

  it('docks with the word count, a quote per network and the share button', () => {
    selection.quote.set(QUOTE);
    selection.remembered.set(QUOTE);
    fixture.detectChanges();

    const bar = element().querySelector('.selection-bar')!;
    expect(bar.querySelector('.count')?.textContent?.trim()).toBe('3 words selected');
    expect(bar.querySelectorAll('[aria-label^="Quote on"]')).toHaveLength(2);
    expect(bar.querySelector('[aria-label="Share"]')).not.toBeNull();
  });

  it('quotes the remembered selection when the tap has collapsed it', () => {
    selection.quote.set(QUOTE);
    selection.remembered.set(QUOTE);
    fixture.detectChanges();
    const mastodon = element().querySelector<HTMLButtonElement>('[aria-label="Quote on Mastodon"]')!;
    selection.quote.set(null);

    mastodon.click();

    expect(postActions.postLink).toHaveBeenCalledWith('mastodon', {
      url: 'https://example.com/a#:~:text=One%20two%20three',
      title: 'First',
      quote: 'One two three',
    });
  });
});
```

`article-layer.component.spec.ts`: add a test that projects `<span articleDock class="docked">x</span>` (mirror how the spec's host template projects `articleCorner`) and expects `.dock .docked` to exist.

- [ ] **Step 2: Run them and watch them fail**

Run: `docker compose exec -T frontend npm test -- src/app/reader/article/selection-share/selection-bar.component.spec.ts src/app/reader/article/article-layer`
Expected: FAIL.

- [ ] **Step 3: Implement the bar.**

`selection-bar.component.ts`:

```ts
import { ChangeDetectionStrategy, Component, inject, input } from '@angular/core';
import { TranslocoPipe } from '@jsverse/transloco';
import { ArticleSelection } from './article-selection.service';
import { SelectionActionsComponent } from './selection-actions.component';

/** The selection's actions docked at the foot of the article on a coarse pointer, while a quote is selected. */
@Component({
  selector: 'app-selection-bar',
  imports: [TranslocoPipe, SelectionActionsComponent],
  templateUrl: './selection-bar.component.html',
  styleUrl: './selection-bar.component.scss',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class SelectionBarComponent {
  readonly url = input.required<string>();
  readonly title = input('');

  protected readonly quote = inject(ArticleSelection).quote;
}
```

`selection-bar.component.html`:

```html
@if (quote(); as quote) {
  <app-selection-actions class="selection-bar" [url]="url()" [title]="title()">
    <span class="count">{{ 'reader.share.selectedWords' | transloco: { count: quote.words } }}</span>
  </app-selection-actions>
}
```

`selection-bar.component.scss`:

```scss
@use '../../../theme/glass-rim';

:host {
  display: contents;
}

.selection-bar {
  @include glass-rim.filled(var(--surface-2));

  flex: 1;
  border-radius: var(--radius-lg);
  padding: var(--space-1) var(--space-2);
  box-shadow: var(--panel-shadow);
}

.count {
  margin-inline-end: auto;
  font-size: var(--fs-sm);
  color: var(--text-muted);
}
```

- [ ] **Step 4: Add the dock to the article layer.** In `article-layer.component.html`, after the `corner-actions` div:

```html
  <div class="dock">
    <ng-content select="[articleDock]" />
  </div>
```

and in `article-layer.component.scss`, after `.end-corner`:

```scss
/* A selection's bar, across the pane's foot and over the corner discs while it lasts. */
.dock {
  @include circle.corner-anchor(left, 21);

  right: var(--space-5);
}
```

- [ ] **Step 5: Place it in the reader view.** Import `SelectionBarComponent` in `reader-view.component.ts`. In the template, beside the bubble block (as a direct child of `<app-article-layer>`, so the slot can take it):

```html
    @if (e.url && coarse()) {
      <app-selection-bar articleDock [url]="e.url" [title]="e.title" />
    }
```

Keep the two `@if`s separate. Each block has a single root element, which content projection needs (NG8011).

- [ ] **Step 6: Run the specs**

Run: `docker compose exec -T frontend npm test -- src/app/reader/article/selection-share src/app/reader/article/article-layer src/app/reader/article/reader-view`
Expected: PASS.

- [ ] **Step 7: Check and build** (`npm run check`, `npx ng build`). Expected: clean (Stylelint included).

- [ ] **Step 8: Commit**

```bash
git add frontend/src/app/reader/article/selection-share frontend/src/app/reader/article/article-layer frontend/src/app/reader/article/reader-view
git commit -m "feat(#1970): on a phone a bar at the article's foot shares the selection"
```

---

### Task 8: Gates, review, live check

- [ ] **Step 1: Backend gates** (from `backend/`): `composer check`, `composer md`, `php bin/phpunit`, `docker compose exec -T php composer test:parallel` (the two legs can run at once), `composer infection:diff`. Scan today's dev log: `ls -t var/log/dev-*.log | head -1 | xargs tail -n 100 | jq .`. If `composer tramp` goes red with no app change to explain it, check `composer show larspohlmann/phptramp` first.
- [ ] **Step 2: Frontend gates** in the container, one at a time: `docker compose exec -T frontend npm run check`, then `docker compose exec -T frontend npx ng build`.
- [ ] **Step 3: `/simplify`, then an independent adversarial review** of the branch diff. Fix only the confirmed findings, and commit each as `fix(#1970): …`.
- [ ] **Step 4: Make the containers current.** `docker compose exec -T php bin/console cache:clear`. Then confirm `:4200` serves the new chunk (hard reload; the stale-chunk trap).
- [ ] **Step 5: Live check on desktop (`localhost:4200`)**, read-only. Open the composer, then **Cancel**. No Send without Lars's go.
  - Select three or more words in a paragraph. After ~150 ms a bubble sits under the last line. Select one or two words: no bubble. Select in the comments or the title: no bubble.
  - Scroll until the selection leaves the screen: the bubble goes. Click elsewhere: it goes.
  - Bubble → Bluesky: the composer shows `“…”` with the caret at the very start, and the card in the header. Bubble → Mastodon: `“…”` + blank line + a URL ending `#:~:text=…`.
  - Select a 70-word paragraph on Bluesky: the counter shows ≤ 300 and the quote ends `…”`.
  - Bubble → share: the menu opens in quote mode (snippet, “Quote on …”, “Copy quote with link”, X dimmed “(link only)”). Copy, paste into a scratch field: `“…”\n\nurl#:~:text=…`. Open that URL in a new tab: Chrome scrolls to the quote and highlights it.
  - Without a selection the toolbar menu shows the hint under the network entries. The link reader's menu shows no hint.
  - A selection across two paragraphs: the copied URL has no fragment. A Bluesky/Mastodon post entry: no fragment, and the network entry opens a **quote** composer that starts with `“…”`.
  - The E-mail target opens with body “title url” (no selection), or `“quote” url`.
- [ ] **Step 6: Live check on the iPhone** (a prod build on `:4300`, the iPhone-testing memory), read-only, same Cancel rule:
  - Selecting text shows Safari's own callout and **no** bubble. The bar docks at the article's foot with “N words selected”, the network buttons and the share button. It is not hidden behind Safari's toolbar or the corner discs.
  - Tapping a network button opens the composer with the quote, although the tap collapsed the selection.
  - Tapping share opens the sheet in quote mode: snippet title, “(link only)” hints. Without a selection the sheet shows the hint note under the network entries.
  - Note any clash between the bar and Safari's callout or the bottom toolbar, and report it with screenshots.
- [ ] **Step 7: Report to the planner:** gate results, review findings and fixes, the live-check notes with screenshots. Nothing gets pushed, merged or deployed unasked. The PR body, when Lars asks for one, says `Closes #1970`.
