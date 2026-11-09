# Mastodon search feeds: `||` alternatives — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** A Mastodon search feed (and its preview) accepts the same `||` query as a Bluesky one: one Mastodon request per alternative, the answers merged into one page, one query staying one feed.

**Architecture:** A new per-call `Pass/SearchQueryAlternatives` spells a query out as the ANDed queries it is the `||` of (shared exclusions carried into each, a negated group left out so the network only ever finds *more*; the reader's existing re-check in `MastodonSearchPage` drops the rest). `TimelineSourceInterface` splits into `requests()` (a non-empty list, sent one after another), `accepted()` (checks each answer as it lands, so a refusal stops the chain) and `pageOf()` (one page from all accepted answers). `TimelineRound` runs a feed's requests as follow-up tickets — the same `SettlementModel::request()` path the Bluesky stale renewal already uses — holding them in a per-feed `Pass/TimelineRead`. The preview loops the alternatives synchronously. Mastodon status ids are ordered per instance, so the one `newestSeenId` per feed serves every alternative as `min_id`.

**Tech Stack:** Symfony 7.4 / PHP 8.4, PHPUnit 12, the existing `Service/Social` module.

**Spec:** GitHub issue [#2104](https://github.com/larspohlmann/simple-feed-reader/issues/2104). Its open points are decided here:

| Open point | Decision |
|---|---|
| Paging | One shared `newestSeenId` as `min_id` for every alternative (ids are comparable on one instance). |
| Cap | `SearchQueryAlternatives::MAX_ALTERNATIVES = 8`; above it the query is refused (422 on preview, import and requery; a stored feed fails its poll as unreachable). |
| `-from:` naming another network's handle | Kept: measured harmless on mastodon.social (`sternschanze -from:hamburgnews.bsky.social` → 21, same as without). |
| Negated group `-(a b)` / `-(a \|\| b)` | Left out of the network query (measured: `sternschanze -(foo bar)` → 1 on Mastodon); `-(a)` becomes `-a`. |
| Preview limiter | Counts one preview, unchanged. |

## Global Constraints

- CLAUDE.md applies in full: Clean Code names, `final readonly` by default, no comments unless a future reader would get the code wrong, PHPMD-clean touched files, PHPStan max, PSR-12, `declare(strict_types=1)`.
- Commit messages: `type(#2104): …`, no attribution lines.
- The 422 needs no frontend error handling: the search form already shows a problem's `detail` (`social-search-form.component.ts:142`). The dialog's search syntax help does change (Task 4).
- Bluesky behaviour is unchanged: Bluesky understands `||` and keeps one request.

---

### Task 1: Spell a query out as its alternatives

**Files:**
- Create: `backend/src/Service/Social/Support/SearchQueryTokens.php`
- Modify: `backend/src/Service/Social/Pass/SearchQueryParser.php`
- Create: `backend/src/Service/Social/Pass/SearchQueryAlternatives.php`
- Create: `backend/src/Service/Social/Support/MastodonSearchQueries.php`
- Create: `backend/src/Service/Social/Exception/SearchQueryTooBroadException.php`
- Test: `backend/tests/Service/Social/Pass/SearchQueryAlternativesTest.php`

**Interfaces:**
- Produces: `SearchQueryTokens::of(string $query): list<array{string, string}>` with public kind constants `OR`, `OPEN`, `CLOSE`, `PHRASE`, `NOT`, `WORD`.
- Produces: `SearchQueryAlternatives::MAX_ALTERNATIVES` (8), `new SearchQueryAlternatives(string $query)`, `->queries(): non-empty-list<string>` throwing `SearchQueryTooBroadException`.
- Produces: `MastodonSearchQueries::of(string $query): non-empty-list<string>` (throws), `MastodonSearchQueries::fits(string $query): bool`, `MastodonSearchQueries::requireFits(string $query): void` (throws).

- [ ] **Step 1: Write the failing test**

`backend/tests/Service/Social/Pass/SearchQueryAlternativesTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Service\Social\Pass;

use App\Service\Social\Exception\SearchQueryTooBroadException;
use App\Service\Social\Pass\SearchQueryAlternatives;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SearchQueryAlternativesTest extends TestCase
{
    /** @return iterable<string, array{string, list<string>}> */
    public static function queries(): iterable
    {
        yield 'no alternatives' => ['rss reader', ['rss reader']];
        yield 'a group of phrases and words' => [
            '("rss reader" || "feed reader" || rssreader || feedreader)',
            ['"rss reader"', '"feed reader"', 'rssreader', 'feedreader'],
        ];
        yield 'shared exclusions go with every alternative' => [
            '(sternschanze || schanzenviertel) -"fc st. pauli" -from:hamburgnews.bsky.social',
            [
                'sternschanze -"fc st. pauli" -from:hamburgnews.bsky.social',
                'schanzenviertel -"fc st. pauli" -from:hamburgnews.bsky.social',
            ],
        ];
        yield 'two groups cross' => ['(a || b) (c || d)', ['a c', 'a d', 'b c', 'b d']];
        yield 'or binds looser than and' => ['a || b c', ['a', 'b c']];
        yield 'a negated group of alternatives is left out' => ['rss -(atom || json)', ['rss']];
        yield 'a negated group of two terms is left out' => ['rss -(atom json)', ['rss']];
        yield 'a negated group of one term is that term negated' => ['rss -(atom)', ['rss -atom']];
        yield 'repeated alternatives once' => ['rss || rss', ['rss']];
        yield 'an empty phrase is nothing' => ['rss ""', ['rss']];
        yield 'a stray closing bracket is nothing' => ['rss ) reader', ['rss reader']];
        yield 'an empty query' => ['', ['']];
    }

    /** @param list<string> $alternatives */
    #[DataProvider('queries')]
    public function testAQueryIsSpelledOutAsTheQueriesItIsTheAlternativesOf(string $query, array $alternatives): void
    {
        self::assertSame($alternatives, (new SearchQueryAlternatives($query))->queries());
    }

    public function testAsManyAlternativesAsTheCapAreSpelledOut(): void
    {
        self::assertCount(
            SearchQueryAlternatives::MAX_ALTERNATIVES,
            (new SearchQueryAlternatives('(a || b) (c || d) (e || f)'))->queries(),
        );
    }

    public function testOneAlternativeMoreThanTheCapIsTooBroad(): void
    {
        $this->expectException(SearchQueryTooBroadException::class);
        $this->expectExceptionMessage('more than 8');

        (new SearchQueryAlternatives('(a || b || c) (d || e || f)'))->queries();
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `cd backend && php bin/phpunit tests/Service/Social/Pass/SearchQueryAlternativesTest.php`
Expected: FAIL — class `SearchQueryAlternatives` not found.

- [ ] **Step 3: Extract the tokenizer the parser already has**

`backend/src/Service/Social/Support/SearchQueryTokens.php`:

```php
<?php

declare(strict_types=1);

namespace App\Service\Social\Support;

final class SearchQueryTokens
{
    public const string OR = 'or';
    public const string OPEN = 'open';
    public const string CLOSE = 'close';
    public const string PHRASE = 'phrase';
    public const string NOT = 'not';
    public const string WORD = 'word';
    private const string PATTERN = '/(?<or>\|\|)|(?<open>\()|(?<close>\))|"(?<phrase>[^"]*)"?'
        . '|(?<not>-)(?=[^\s|)])|(?<word>[^\s()"|]+)|\S/u';

    /** @return list<array{string, string}> kind and text */
    public static function of(string $query): array
    {
        preg_match_all(self::PATTERN, $query, $matches, \PREG_SET_ORDER | \PREG_UNMATCHED_AS_NULL);
        $tokens = [];
        foreach ($matches as $match) {
            foreach ([self::OR, self::OPEN, self::CLOSE, self::PHRASE, self::NOT, self::WORD] as $kind) {
                if (null !== ($match[$kind] ?? null)) {
                    $tokens[] = [$kind, $match[$kind]];
                    break;
                }
            }
        }

        return $tokens;
    }

    private function __construct()
    {
    }
}
```

In `SearchQueryParser.php`: delete the constants `OR`, `OPEN`, `CLOSE`, `PHRASE`, `NOT`, `WORD`, `TOKEN_PATTERN` and the method `tokensOf()`; add `use App\Service\Social\Support\SearchQueryTokens;`; the constructor becomes

```php
    public function __construct(string $query)
    {
        $this->tokens = SearchQueryTokens::of($query);
    }
```

and every `self::OR` / `self::OPEN` / `self::CLOSE` / `self::PHRASE` / `self::NOT` / `self::WORD` becomes `SearchQueryTokens::OR` etc. (`NETWORK_FILTER_PATTERN` and `AUTHOR_FILTER_PATTERN` stay.) Run `php bin/phpunit tests/Service/Social/Support/SocialSearchMatchTest.php` — must stay green.

- [ ] **Step 4: Write the exception, the expander and the Mastodon entry point**

`backend/src/Service/Social/Exception/SearchQueryTooBroadException.php`:

```php
<?php

declare(strict_types=1);

namespace App\Service\Social\Exception;

final class SearchQueryTooBroadException extends \RuntimeException
{
}
```

`backend/src/Service/Social/Pass/SearchQueryAlternatives.php`:

```php
<?php

declare(strict_types=1);

namespace App\Service\Social\Pass;

use App\Service\Social\Exception\SearchQueryTooBroadException;
use App\Service\Social\Support\SearchQueryTokens;

/**
 * One query spelled out as the ANDed queries it is the `||` of, for a network without `||`. A negated group is left
 * out: the network then finds more, never less, and the reader's own re-check drops the rest.
 */
final class SearchQueryAlternatives
{
    public const int MAX_ALTERNATIVES = 8;

    /** @var list<array{string, string}> */
    private readonly array $tokens;

    private int $position = 0;

    private int $groupDepth = 0;

    public function __construct(string $query)
    {
        $this->tokens = SearchQueryTokens::of($query);
    }

    /**
     * @return non-empty-list<string>
     *
     * @throws SearchQueryTooBroadException
     */
    public function queries(): array
    {
        $queries = array_values(array_unique(array_map(
            static fn (array $factors): string => implode(' ', array_unique($factors)),
            $this->alternatives(),
        )));

        return [] === $queries ? [''] : $queries;
    }

    /** @return list<list<string>> */
    private function alternatives(): array
    {
        $alternatives = $this->conjunction();
        while ($this->accepts(SearchQueryTokens::OR)) {
            $alternatives = self::capped([...$alternatives, ...$this->conjunction()]);
        }

        return $alternatives;
    }

    /** @return list<list<string>> */
    private function conjunction(): array
    {
        $alternatives = [[]];
        while (null !== $kind = $this->tokens[$this->position][0] ?? null) {
            if (SearchQueryTokens::OR === $kind || (SearchQueryTokens::CLOSE === $kind && $this->groupDepth > 0)) {
                break;
            }
            $alternatives = self::crossed($alternatives, $this->factor());
        }

        return $alternatives;
    }

    /** @return list<list<string>> */
    private function factor(): array
    {
        [$kind, $text] = $this->tokens[$this->position++] ?? ['', ''];

        return match ($kind) {
            SearchQueryTokens::NOT => self::negated($this->factor()),
            SearchQueryTokens::OPEN => $this->group(),
            SearchQueryTokens::PHRASE => '' === trim($text) ? [[]] : [['"' . $text . '"']],
            SearchQueryTokens::WORD => [[$text]],
            default => [[]],
        };
    }

    /** @return list<list<string>> */
    private function group(): array
    {
        ++$this->groupDepth;
        $alternatives = $this->alternatives();
        $this->accepts(SearchQueryTokens::CLOSE);
        --$this->groupDepth;

        return $alternatives;
    }

    private function accepts(string $kind): bool
    {
        if ($kind !== ($this->tokens[$this->position][0] ?? null)) {
            return false;
        }
        ++$this->position;

        return true;
    }

    /**
     * @param list<list<string>> $operand
     *
     * @return list<list<string>>
     */
    private static function negated(array $operand): array
    {
        return 1 === \count($operand) && 1 === \count($operand[0]) ? [['-' . $operand[0][0]]] : [[]];
    }

    /**
     * @param list<list<string>> $left
     * @param list<list<string>> $right
     *
     * @return list<list<string>>
     */
    private static function crossed(array $left, array $right): array
    {
        $crossed = [];
        foreach ($left as $leftFactors) {
            foreach ($right as $rightFactors) {
                $crossed[] = [...$leftFactors, ...$rightFactors];
            }
        }

        return self::capped($crossed);
    }

    /**
     * @param list<list<string>> $alternatives
     *
     * @return list<list<string>>
     */
    private static function capped(array $alternatives): array
    {
        return \count($alternatives) > self::MAX_ALTERNATIVES
            ? throw new SearchQueryTooBroadException(sprintf(
                'Mastodon searches each alternative on its own; this query spells out more than %d.',
                self::MAX_ALTERNATIVES,
            ))
            : $alternatives;
    }
}
```

`backend/src/Service/Social/Support/MastodonSearchQueries.php`:

```php
<?php

declare(strict_types=1);

namespace App\Service\Social\Support;

use App\Service\Social\Exception\SearchQueryTooBroadException;
use App\Service\Social\Pass\SearchQueryAlternatives;

/** A query as Mastodon can run it: one search per `||` alternative, an operator Mastodon's search does not know. */
final class MastodonSearchQueries
{
    /**
     * @return non-empty-list<string>
     *
     * @throws SearchQueryTooBroadException
     */
    public static function of(string $query): array
    {
        return (new SearchQueryAlternatives($query))->queries();
    }

    /** @throws SearchQueryTooBroadException */
    public static function requireFits(string $query): void
    {
        self::of($query);
    }

    public static function fits(string $query): bool
    {
        try {
            self::requireFits($query);
        } catch (SearchQueryTooBroadException) {
            return false;
        }

        return true;
    }

    private function __construct()
    {
    }
}
```

- [ ] **Step 5: Run the tests to verify they pass**

Run: `cd backend && php bin/phpunit tests/Service/Social/Pass/SearchQueryAlternativesTest.php tests/Service/Social/Support/SocialSearchMatchTest.php`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add backend/src/Service/Social/Support/SearchQueryTokens.php backend/src/Service/Social/Pass/SearchQueryParser.php backend/src/Service/Social/Pass/SearchQueryAlternatives.php backend/src/Service/Social/Support/MastodonSearchQueries.php backend/src/Service/Social/Exception/SearchQueryTooBroadException.php backend/tests/Service/Social/Pass/SearchQueryAlternativesTest.php
git commit -m "feat(#2104): spell a search query out as the alternatives Mastodon can run"
```

---

### Task 2: A private feed reads with several requests in one refresh

**Files:**
- Modify: `backend/src/Service/Social/TimelineSource/TimelineSourceInterface.php`
- Modify: `backend/src/Service/Social/TimelineSource/BlueskyTimelineSource.php`
- Modify: `backend/src/Service/Social/TimelineSource/MastodonTimelineSource.php`
- Modify: `backend/src/Service/Social/Support/MastodonSearchPage.php`
- Create: `backend/src/Service/Social/Pass/TimelineRead.php`
- Modify: `backend/src/Service/Social/Pass/TimelineRound.php`
- Test: `backend/tests/Service/Social/TimelineSource/MastodonTimelineSourceTest.php`, `backend/tests/Service/Social/TimelineSource/BlueskyTimelineSourceTest.php`, `backend/tests/Service/Social/Pass/TimelineRoundTest.php`

**Interfaces:**
- Consumes: `MastodonSearchQueries::of()`, `SearchQueryTooBroadException` (Task 1).
- Produces: `TimelineSourceInterface::requests(SocialFeed): non-empty-list<ApiRequestModel>`, `::accepted(SocialFeed, FetchOutcomeModel): ApiResponseModel`, `::pageOf(SocialFeed, list<ApiResponseModel>): TimelinePageModel` (replacing `request()` and the one-outcome `pageOf()`).
- Produces: `MastodonSearchPage::of(list<ApiResponseModel> $accepted, string $query): TimelinePageModel` (was one answer).

- [ ] **Step 1: Write the failing tests**

In `MastodonTimelineSourceTest.php`:

1. Every `->request($feed)` becomes `->requests($feed)[0]`, and the private helper `pageOf()` becomes

```php
    private function pageOf(FetchOutcomeModel $outcome): TimelinePageModel
    {
        $source = $this->source(new RoutedHttpClient([]));
        $home = self::socialFeed(SocialFeedKind::Home, '');

        return $source->pageOf($home, [$source->accepted($home, $outcome)]);
    }
```

2. In `testASearchAnswerMapsToItsStatusesWithoutRepliesOrStatusesTheQueryDoesNotMatch` the call becomes

```php
        $source = $this->source(new RoutedHttpClient([]));
        $search = self::socialFeed(SocialFeedKind::Search, 'hello');
        $page = $source->pageOf($search, [$source->accepted($search, self::answered($body))]);
```

3. Add:

```php
    public function testASearchAsksOncePerAlternativeEachAfterTheNewestSeenStatus(): void
    {
        $search = self::socialFeed(SocialFeedKind::Search, '(rss || atom) -json');
        $search->recordNewestSeen('110');

        $requests = $this->source(new RoutedHttpClient([]))->requests($search);

        self::assertSame([
            'https://mastodon.example/api/v2/search?type=statuses&resolve=false&limit=40&q=rss%20-json&min_id=110',
            'https://mastodon.example/api/v2/search?type=statuses&resolve=false&limit=40&q=atom%20-json&min_id=110',
        ], array_map(static fn (ApiRequestModel $request): string => $request->url, $requests));
    }

    public function testASearchTooBroadForMastodonIsNeverTurnedIntoARequest(): void
    {
        $this->expectException(SocialNetworkUnreachableException::class);
        $this->expectExceptionMessage('more than 8');

        $this->source(new RoutedHttpClient([]))
            ->requests(self::socialFeed(SocialFeedKind::Search, '(a || b || c) (d || e || f)'));
    }

    public function testTheAnswersOfASearchsAlternativesMergeIntoOnePageHoldingEachStatusOnce(): void
    {
        $source = $this->source(new RoutedHttpClient([]));
        $search = self::socialFeed(SocialFeedKind::Search, '(rss || atom)');
        $first = self::searchBody(self::searchStatus('111', null, '<p>rss</p>'), self::searchStatus('110', null, '<p>atom</p>'));
        $second = self::searchBody(self::searchStatus('111', null, '<p>rss</p>'), self::searchStatus('109', null, '<p>atom</p>'));

        $page = $source->pageOf($search, [
            $source->accepted($search, self::answered($first)),
            $source->accepted($search, self::answered($second)),
        ]);

        self::assertSame(
            ['https://mastodon.example/@bob/111', 'https://mastodon.example/@bob/110', 'https://mastodon.example/@bob/109'],
            array_map(static fn (ParsedEntryModel $entry): ?string => $entry->url, $page->parsed->entries),
        );
        self::assertSame('111', $page->newestId);
    }

    /** @param array<string, mixed> ...$statuses */
    private static function searchBody(array ...$statuses): string
    {
        return json_encode(['accounts' => [], 'hashtags' => [], 'statuses' => $statuses], \JSON_THROW_ON_ERROR);
    }
```

with `use App\Service\Fetch\Model\ApiRequestModel;`. Rewrite the existing search-answer test's `json_encode([...])` body through `searchBody()` too.

In `BlueskyTimelineSourceTest.php` apply the same mechanical rewrite: `->request($feed)` → `->requests($feed)[0]`; `$source->pageOf($feed, $outcome)` → `$source->pageOf($feed, [$source->accepted($feed, $outcome)])`. A test expecting `SocialSessionStaleException` or a refusal from `pageOf` now gets it from `accepted` — keep the expectation, the rewritten call still raises it.

In `TimelineRoundTest.php` add (with `use App\Service\Social\Model\TimelineOutcome\FetchedTimelineModel;`):

```php
    private const int SEARCH = 3;
    private const string RSS_SEARCH = 'https://mastodon.example/api/v2/search?type=statuses&resolve=false&limit=40&q=rss';
    private const string ATOM_SEARCH = 'https://mastodon.example/api/v2/search?type=statuses&resolve=false&limit=40&q=atom';

    public function testAMastodonSearchAsksForItsAlternativesOneAfterAnotherAndSettlesOnTheMergedPage(): void
    {
        $search = PrivateFeeds::mastodon(PrivateFeeds::mastodonAccount(), self::SEARCH, SocialFeedKind::Search, '(rss || atom)');
        $round = SocialApiWiring::timelineRounds($this->http->client(), $this->cache)->start([self::SEARCH => $search]);

        $tickets = iterator_to_array($round->ticketFor(self::SEARCH));
        self::assertSame(self::RSS_SEARCH, $tickets[self::SEARCH]->request->url);

        $followUp = $round->settle(self::SEARCH, self::mastodonSearchAnswer('111', 'rss'));
        self::assertSame(self::ATOM_SEARCH, $followUp->requestTicket()->request->url);

        $fetched = $round->settle(self::SEARCH, self::mastodonSearchAnswer('112', 'atom'))->settledOutcome();
        self::assertInstanceOf(FetchedTimelineModel::class, $fetched);
        self::assertCount(2, $fetched->page->parsed->entries);
        self::assertSame('112', $search->getNewestSeenId());
    }

    public function testARefusedFirstAlternativeSettlesTheSearchWithoutAskingTheNext(): void
    {
        $search = PrivateFeeds::mastodon(PrivateFeeds::mastodonAccount(), self::SEARCH, SocialFeedKind::Search, '(rss || atom)');
        $round = SocialApiWiring::timelineRounds($this->http->client(), $this->cache)->start([self::SEARCH => $search]);
        iterator_to_array($round->ticketFor(self::SEARCH));

        $refused = $round->settle(
            self::SEARCH,
            FetchOutcomeModel::answered(new ApiResponseModel(Response::HTTP_UNAUTHORIZED, '{}')),
        );

        self::assertFalse($refused->isRequest());
        self::assertInstanceOf(FailedTimelineModel::class, $refused->settledOutcome());
    }

    private static function mastodonSearchAnswer(string $id, string $word): FetchOutcomeModel
    {
        return FetchOutcomeModel::answered(new ApiResponseModel(Response::HTTP_OK, json_encode(['statuses' => [[
            'id' => $id,
            'uri' => 'https://mastodon.example/users/bob/statuses/' . $id,
            'url' => 'https://mastodon.example/@bob/' . $id,
            'created_at' => '2026-11-09T10:00:00Z',
            'content' => '<p>' . $word . '</p>',
            'in_reply_to_id' => null,
            'account' => ['acct' => 'bob', 'display_name' => 'Bob', 'url' => 'https://mastodon.example/@bob'],
        ]]], \JSON_THROW_ON_ERROR)));
    }
```

If `FetchedTimelineModel` names its page property other than `page`, use its name. If `SocialApiWiring`'s Mastodon guard host is not `mastodon.example`, use the host `PrivateFeeds::mastodonAccount()` carries in the two URL constants.

- [ ] **Step 2: Run the tests to verify they fail**

Run: `cd backend && php bin/phpunit tests/Service/Social/TimelineSource tests/Service/Social/Pass/TimelineRoundTest.php`
Expected: FAIL — `requests()` / `accepted()` undefined.

- [ ] **Step 3: Split the interface**

`TimelineSourceInterface.php` — replace `request()` and `pageOf()` with:

```php
    /**
     * @return non-empty-list<ApiRequestModel> sent one after another, a Mastodon search once per `||` alternative
     *
     * @throws SocialCredentialsRejectedException
     * @throws SocialNetworkThrottledException
     * @throws SocialNetworkUnreachableException
     */
    public function requests(SocialFeed $socialFeed): array;

    /**
     * @throws SocialSessionStaleException when only a fresh session can read it
     * @throws SocialCredentialsRejectedException
     * @throws SocialNetworkThrottledException
     * @throws SocialNetworkUnreachableException
     */
    public function accepted(SocialFeed $socialFeed, FetchOutcomeModel $outcome): ApiResponseModel;

    /**
     * @param list<ApiResponseModel> $accepted one per request, in order
     *
     * @throws SocialNetworkUnreachableException
     */
    public function pageOf(SocialFeed $socialFeed, array $accepted): TimelinePageModel;
```

(add `use App\Service\Fetch\Model\ApiResponseModel;`).

`BlueskyTimelineSource.php` — replace `request()` and `pageOf()`:

```php
    public function requests(SocialFeed $socialFeed): array
    {
        return [$this->sessions->authorizedGet($socialFeed->getSocialAccount(), self::pathOf($socialFeed))];
    }

    public function accepted(SocialFeed $socialFeed, FetchOutcomeModel $outcome): ApiResponseModel
    {
        $answer = BlueskyApiClient::answerOf($outcome);
        if (BlueskySessions::isStaleSession($answer)) {
            throw new SocialSessionStaleException(BlueskySessions::SESSION_REFUSED);
        }

        return BlueskySessions::accepted($answer);
    }

    public function pageOf(SocialFeed $socialFeed, array $accepted): TimelinePageModel
    {
        $answer = $accepted[0] ?? throw new \LogicException('A Bluesky timeline is read with one request.');

        return SocialFeedKind::Search === $socialFeed->getKind()
            ? BlueskySearchPage::of(
                $answer,
                $socialFeed->getSocialAccount()->getIdentity(),
                $socialFeed->getRemoteId(),
            )
            : self::timelinePageOf($socialFeed, $answer);
    }
```

(drop the now-unused `ApiRequestModel` import if PHPStan/phpcs flags it.)

- [ ] **Step 4: Mastodon asks once per alternative and merges**

`MastodonTimelineSource.php` — replace `request()` and `pageOf()`:

```php
    public function requests(SocialFeed $socialFeed): array
    {
        $account = $socialFeed->getSocialAccount();

        return array_map(
            fn (string $path): ApiRequestModel => $this->mastodon->authorizedGet($account, $path),
            self::pathsOf($socialFeed),
        );
    }

    public function accepted(SocialFeed $socialFeed, FetchOutcomeModel $outcome): ApiResponseModel
    {
        return MastodonApiClient::accepted(MastodonApiClient::answerOf($outcome));
    }

    public function pageOf(SocialFeed $socialFeed, array $accepted): TimelinePageModel
    {
        if (SocialFeedKind::Search === $socialFeed->getKind()) {
            return MastodonSearchPage::of($accepted, $socialFeed->getRemoteId());
        }

        return self::pageFrom($accepted[0] ?? throw new \LogicException('A Mastodon timeline is read with one request.'));
    }
```

add the two private helpers:

```php
    /** @return non-empty-list<string> */
    private static function pathsOf(SocialFeed $socialFeed): array
    {
        return SocialFeedKind::Search === $socialFeed->getKind()
            ? self::searchPathsOf($socialFeed)
            : [self::pathOf($socialFeed)];
    }

    /** @return non-empty-list<string> */
    private static function searchPathsOf(SocialFeed $socialFeed): array
    {
        try {
            $queries = MastodonSearchQueries::of($socialFeed->getRemoteId());
        } catch (SearchQueryTooBroadException $tooBroad) {
            throw new SocialNetworkUnreachableException($tooBroad->getMessage(), previous: $tooBroad);
        }

        return array_map(
            static fn (string $query): string => SocialSearchPaths::mastodon($query, $socialFeed->getNewestSeenId()),
            $queries,
        );
    }
```

and in `pathOf()` the `SocialFeedKind::Search` arm becomes part of the throwing arm:

```php
            SocialFeedKind::Search,
            SocialFeedKind::Author,
            SocialFeedKind::CustomFeed => throw new SocialNetworkUnreachableException('Mastodon has no such timeline.'),
```

(imports: `App\Service\Social\Exception\SearchQueryTooBroadException`, `App\Service\Social\Support\MastodonSearchQueries`; `SocialFeedKind::Search` never reaches `pathOf()` now.)

`MastodonSearchPage.php` — `of()` takes the list:

```php
    /**
     * @param list<ApiResponseModel> $accepted one answer per alternative of the query
     *
     * @throws SocialNetworkUnreachableException
     */
    public static function of(array $accepted, string $query): TimelinePageModel
    {
        $matches = SocialSearchMatch::matcher($query);

        return MastodonTimelinePage::of(array_values(array_filter(
            self::distinctStatusesOf($accepted),
            static fn (JsonNodeModel $status): bool => null === $status->string('in_reply_to_id')
                && $matches(self::searchedPostOf(MastodonTimelinePage::originalOf($status))),
        )));
    }

    /**
     * @param list<ApiResponseModel> $accepted
     *
     * @return list<JsonNodeModel> each status once, however many alternatives found it
     *
     * @throws SocialNetworkUnreachableException
     */
    private static function distinctStatusesOf(array $accepted): array
    {
        $statusesById = [];
        foreach ($accepted as $answer) {
            $statuses = JsonNodeModel::of($answer->json())->listAt('statuses')
                ?? throw new SocialNetworkUnreachableException('The Mastodon instance answered without search results.');
            foreach ($statuses as $status) {
                $statusesById[$status->string('id') ?? ''] ??= $status;
            }
        }

        return array_values($statusesById);
    }
```

Update the class docblock: "A search's answers, one per alternative, as entries: …".

- [ ] **Step 5: The round runs a feed's requests as follow-ups**

`backend/src/Service/Social/Pass/TimelineRead.php`:

```php
<?php

declare(strict_types=1);

namespace App\Service\Social\Pass;

use App\Service\Fetch\Model\ApiRequestModel;
use App\Service\Fetch\Model\ApiResponseModel;

/** One feed's reads in a round: its requests, sent one after another, and the answers accepted so far. */
final class TimelineRead
{
    /** @var list<ApiResponseModel> */
    private array $accepted = [];

    /** @param non-empty-list<ApiRequestModel> $requests */
    public function __construct(private readonly array $requests)
    {
    }

    public function current(): ApiRequestModel
    {
        return $this->requests[\count($this->accepted)];
    }

    public function accept(ApiResponseModel $answer): void
    {
        $this->accepted[] = $answer;
    }

    public function isComplete(): bool
    {
        return \count($this->accepted) >= \count($this->requests);
    }

    /** @return list<ApiResponseModel> */
    public function accepted(): array
    {
        return $this->accepted;
    }
}
```

In `TimelineRound.php`:

Add the field, next to `$onRenewedSession`:

```php
    /** @var array<int|string, TimelineRead> */
    private array $reads = [];
```

Replace `settle()`'s body after the withheld guard:

```php
    /** @return SettlementModel<TimelineOutcomeModelInterface> */
    public function settle(int|string $feedId, FetchOutcomeModel $outcome): SettlementModel
    {
        $socialFeed = $this->socialFeedOf($feedId);
        if ($outcome->isWithheld()) {
            return $this->ticketOf($feedId, $socialFeed);
        }

        try {
            return $this->settleAnswer($feedId, $socialFeed, $outcome);
        } catch (SocialSessionStaleException $stale) {
            return $this->settleStale($feedId, $socialFeed, $stale);
        } catch (SocialCredentialsRejectedException | SocialNetworkUnreachableException $failure) {
            return SettlementModel::outcome($this->accounts->settleFailure($socialFeed->getSocialAccount(), $failure));
        }
    }
```

add:

```php
    /**
     * The feed's next request while it has one, else its page from every answer.
     *
     * @return SettlementModel<TimelineOutcomeModelInterface>
     *
     * @throws SocialSessionStaleException
     * @throws SocialCredentialsRejectedException
     * @throws SocialNetworkUnreachableException
     */
    private function settleAnswer(int|string $feedId, SocialFeed $socialFeed, FetchOutcomeModel $outcome): SettlementModel
    {
        $account = $socialFeed->getSocialAccount();
        $source = $this->sources->of($account);
        $read = $this->reads[$feedId] ?? throw new \LogicException('A feed is settled before it was asked.');
        $read->accept($source->accepted($socialFeed, $outcome));
        if (!$read->isComplete()) {
            return SettlementModel::request($this->slots->ticketFor($account, $read->current()));
        }

        unset($this->reads[$feedId]);
        $page = $source->pageOf($socialFeed, $read->accepted());
        $socialFeed->recordNewestSeen($page->newestId);

        return SettlementModel::outcome(new FetchedTimelineModel($page));
    }
```

In `ticketOf()` replace

```php
            $request = $this->sources->of($account)->request($socialFeed);
```

with

```php
            $read = new TimelineRead($this->sources->of($account)->requests($socialFeed));
```

and the return with

```php
        $this->reads[$feedId] = $read;

        return SettlementModel::request($this->slots->ticketFor($account, $read->current()));
```

A withheld or stale answer goes through `ticketOf()` again, which starts the feed's read over with freshly built requests — the follow-up ticket of a read begun before its account's renewal is withheld by `admits()` exactly as a first ticket is. Extend the class docblock's first sentence: "a ticket per feed, and a follow-up per further request a feed reads with,".

- [ ] **Step 6: Run the tests to verify they pass**

Run: `cd backend && php bin/phpunit tests/Service/Social tests/Service/Refresh`
Expected: PASS. `grep -rn -- '->request(\$socialFeed\|->pageOf(' src tests` must show no caller left on the old signatures.

- [ ] **Step 7: Commit**

```bash
git add backend/src/Service/Social backend/tests/Service/Social
git commit -m "feat(#2104): read a Mastodon search feed once per alternative and merge the answers"
```

---

### Task 3: Preview, import and requery speak the same limit

**Files:**
- Modify: `backend/src/Service/Social/SocialSearch/NetworkSocialSearch.php`
- Modify: `backend/src/Service/Social/Support/PrivateFeedRules.php`
- Modify: `backend/src/Service/Social/SearchFeedEditor.php`
- Modify: `backend/src/Http/Problem/ExceptionProblems/SocialProblems.php`
- Test: `backend/tests/Service/Social/SocialSearch/NetworkSocialSearchTest.php`, `backend/tests/Service/Social/Support/PrivateFeedRulesTest.php`, `backend/tests/Service/Social/SearchFeedEditorTest.php`, `backend/tests/Http/Problem/ExceptionProblems/SocialProblemsTest.php`

**Interfaces:**
- Consumes: `MastodonSearchQueries::of()`, `::fits()`, `::requireFits()`, `SearchQueryTooBroadException` (Task 1); `MastodonSearchPage::of(list, query)` (Task 2).
- Produces: problem type `social_search_too_broad` (422).

- [ ] **Step 1: Write the failing tests**

`NetworkSocialSearchTest.php` — give the status helper parameters (existing callers keep the defaults):

```php
    /** @return array<string, mixed> */
    private static function mastodonStatus(string $id = '1', string $content = '<p>Which RSS reader?</p>'): array
    {
        return [
            'id' => $id,
            'uri' => 'https://mastodon.example/users/bob/statuses/' . $id,
            'url' => 'https://mastodon.example/@bob/' . $id,
            'created_at' => '2026-10-12T10:00:00Z',
            'content' => $content,
            'in_reply_to_id' => null,
            'account' => ['acct' => 'bob', 'display_name' => 'Bob', 'url' => 'https://mastodon.example/@bob'],
        ];
    }
```

and add (with `use App\Service\Social\Exception\SearchQueryTooBroadException;`):

```php
    public function testAMastodonPreviewAsksOncePerAlternativeAndMergesTheAnswers(): void
    {
        $rss = 'GET https://mastodon.example/api/v2/search?type=statuses&resolve=false&limit=40&q=rss';
        $atom = 'GET https://mastodon.example/api/v2/search?type=statuses&resolve=false&limit=40&q=atom';
        $http = new RoutedHttpClient([
            $rss => self::json(['statuses' => [self::mastodonStatus('1')]]),
            $atom => self::json(['statuses' => [self::mastodonStatus('1'), self::mastodonStatus('2', '<p>atom feeds</p>')]]),
        ]);

        $page = $this->search($http->client())->newest(SocialAccounts::mastodon(), '(rss || atom)');

        self::assertCount(2, $page->parsed->entries);
        self::assertSame([$rss, $atom], $http->requestLines());
    }

    public function testAMastodonPreviewTooBroadIsRefusedBeforeAnyRequest(): void
    {
        $http = new RoutedHttpClient([]);

        try {
            $this->search($http->client())->newest(SocialAccounts::mastodon(), '(a || b || c) (d || e || f)');
            self::fail('Nine alternatives are more than Mastodon is asked for.');
        } catch (SearchQueryTooBroadException) {
            self::assertSame([], $http->requestLines());
        }
    }
```

`PrivateFeedRulesTest.php` — add:

```php
    public function testAMastodonSearchIsAllowedOnlyWhileItSpellsOutFewEnoughAlternatives(): void
    {
        $tooBroad = PrivateFeedModel::search('(a || b || c) (d || e || f)');

        self::assertFalse(PrivateFeedRules::allows(SocialNetwork::Mastodon, $tooBroad));
        self::assertTrue(PrivateFeedRules::allows(SocialNetwork::Bluesky, $tooBroad));
        self::assertTrue(PrivateFeedRules::allows(SocialNetwork::Mastodon, PrivateFeedModel::search('(a || b) (c || d)')));
    }
```

`SearchFeedEditorTest.php` — add (with `use App\Service\Social\Exception\SearchQueryTooBroadException;`):

```php
    public function testAMastodonQueryTooBroadIsRefusedAndTheSearchStaysAsItWas(): void
    {
        $account = $this->stored(SocialAccounts::mastodonOf($this->user));
        $subscription = $this->searchFor($account, 'rss reader');

        try {
            $this->editor()->requery($subscription, '(a || b || c) (d || e || f)');
            self::fail('Mastodon cannot search nine alternatives.');
        } catch (SearchQueryTooBroadException) {
            self::assertSame('rss reader', $this->socialFeedOf($subscription)->getRemoteId());
        }
    }
```

`SocialProblemsTest.php` — add (with `use App\Service\Social\Exception\SearchQueryTooBroadException;`):

```php
    public function testASearchTooBroadForMastodonIsAnUnprocessableRequestThatSaysWhy(): void
    {
        $resolved = (new SocialProblems())->resolve(new SearchQueryTooBroadException('more than 8'));

        self::assertNotNull($resolved);
        self::assertSame('social_search_too_broad', $resolved->problem->type);
        self::assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $resolved->problem->status);
        self::assertSame('more than 8', $resolved->problem->detail);
    }
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `cd backend && php bin/phpunit tests/Service/Social/SocialSearch tests/Service/Social/Support/PrivateFeedRulesTest.php tests/Service/Social/SearchFeedEditorTest.php tests/Http/Problem/ExceptionProblems/SocialProblemsTest.php`
Expected: FAIL on the new tests.

- [ ] **Step 3: Implement**

`NetworkSocialSearch.php` — the Mastodon arm:

```php
            SocialNetwork::Mastodon => MastodonSearchPage::of(array_map(
                fn (string $alternative): ApiResponseModel => MastodonApiClient::accepted($this->mastodonClient->send(
                    $this->mastodon->authorizedGet($account, SocialSearchPaths::mastodon($alternative, null)),
                )),
                MastodonSearchQueries::of($query),
            ), $query),
```

(imports `App\Service\Fetch\Model\ApiResponseModel`, `App\Service\Social\Support\MastodonSearchQueries`; add `@throws SearchQueryTooBroadException` to `SocialSearchInterface::newest()` and import it there.)

`PrivateFeedRules.php` — the Search arm:

```php
            SocialFeedKind::Search => '' !== $privateFeed->remoteId
                && mb_strlen($privateFeed->remoteId) <= SocialSearchQuery::MAX_LENGTH
                && (SocialNetwork::Bluesky === $network || MastodonSearchQueries::fits($privateFeed->remoteId)),
```

`SearchFeedEditor.php` — in `requery()`, right after `$this->refuseAnotherSearchFor($socialFeed, $search);`:

```php
        self::refuseTooBroadFor($socialFeed, $search);
```

add `@throws SearchQueryTooBroadException when Mastodon cannot run the query` to `requery()`'s docblock, and:

```php
    /** @throws SearchQueryTooBroadException */
    private static function refuseTooBroadFor(SocialFeed $socialFeed, PrivateFeedModel $search): void
    {
        if (SocialNetwork::Mastodon === $socialFeed->getSocialAccount()->getNetwork()) {
            MastodonSearchQueries::requireFits($search->remoteId);
        }
    }
```

(imports `App\Enum\SocialNetwork`, `App\Service\Social\Exception\SearchQueryTooBroadException`, `App\Service\Social\Support\MastodonSearchQueries`.)

`SocialProblems.php` — an arm before `NotASearchFeedException`'s:

```php
            $exception instanceof SearchQueryTooBroadException => new ResolvedProblem(new ApiProblem(
                'social_search_too_broad',
                'Search too broad for Mastodon',
                Response::HTTP_UNPROCESSABLE_ENTITY,
                $exception->getMessage(),
            )),
```

If PHPMD then flags `SocialProblems::resolve()` complexity, fix the design the metric points at (e.g. group the search arms into a `searchProblemOf()` helper), never a suppression.

- [ ] **Step 4: Run the tests to verify they pass**

Run: `cd backend && php bin/phpunit tests/Service/Social tests/Http/Problem tests/Controller/Api`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add backend/src backend/tests
git commit -m "feat(#2104): preview a Mastodon search per alternative; refuse one too broad on import and requery"
```

---

### Task 4: The search syntax help shows `||` and grouping for Mastodon

The dialog's "Search syntax" disclosure (`social-search-form`) lists `||`, `( … )` and the combined example for Bluesky only, and the spec pins that Mastodon shows no `||`. Mastodon now runs the same syntax, so both networks list it; Mastodon keeps its "every word must appear" row and its full-text note, and gains a line on the per-alternative searches and their cap.

**Files:**
- Modify: `frontend/src/app/reader/feeds/social-picker/social-search-form.component.ts:37-75`
- Modify: `frontend/src/app/reader/feeds/social-picker/social-search-form.component.html:31-46`
- Modify: `frontend/public/i18n/en.json`, `frontend/public/i18n/de.json` (`settings.social.search`)
- Test: `frontend/src/app/reader/feeds/social-picker/social-search-form.component.spec.ts`

**Interfaces:**
- Consumes: the cap `SearchQueryAlternatives::MAX_ALTERNATIVES = 8` (Task 1), stated in the copy.

- [ ] **Step 1: Write the failing spec**

Replace the test `'lists the either-or operator for Bluesky only'` with:

```ts
  it.each<SocialNetworkName>(['bluesky', 'mastodon'])(
    'lists the either-or operator and grouping on %s',
    (network) => {
      const rules = byTestId(mount(network), 'social-search-syntax')?.querySelector('.rules');

      expect(rules?.textContent).toContain('rss || atom');
      expect(rules?.textContent).toContain('( … )');
    },
  );

  it('tells a Mastodon search that each alternative is searched on its own, and how many at most', () => {
    const mastodon = byTestId(mount('mastodon'), 'social-search-syntax')?.textContent ?? '';
    expect(mastodon).toContain('full-text search');
    expect(mastodon).toContain('at most 8');

    const bluesky = byTestId(mount('bluesky'), 'social-search-syntax')?.textContent ?? '';
    expect(bluesky).not.toContain('at most 8');
  });
```

and rename `'shows the combined Bluesky example on a line of its own, apart from the rules'` to an `it.each<SocialNetworkName>(['bluesky', 'mastodon'])('shows the combined example on a line of its own, apart from the rules on %s', (network) => { const fixture = mount(network); … })` with the body unchanged.

- [ ] **Step 2: Run the spec to verify it fails**

Run: `docker compose exec -T frontend npx jest src/app/reader/feeds/social-picker/social-search-form.component.spec.ts`
Expected: FAIL — Mastodon lists no `||`, shows no combined example, no "at most 8".

- [ ] **Step 3: Implement**

`social-search-form.component.ts` — both networks carry the example now, so it leaves the per-network record:

```ts
interface SyntaxRule {
  readonly operator: string;
  readonly key: string;
}

const EXAMPLE_QUERY = '"rss reader" -gazette';

const COMBINED_EXAMPLE = '("rss reader" || "feed reader" || rssreader) -gazette';

const PHRASE: SyntaxRule = { operator: '"rss reader"', key: 'settings.social.search.syntaxPhrase' };
const EITHER: SyntaxRule = { operator: 'rss || atom', key: 'settings.social.search.syntaxEither' };
const EXCLUDE: SyntaxRule = { operator: '-gazette', key: 'settings.social.search.syntaxExclude' };
const EXCLUDE_AUTHOR: SyntaxRule = {
  operator: '-from:handle',
  key: 'settings.social.search.syntaxExcludeAuthor',
};
const GROUP: SyntaxRule = { operator: '( … )', key: 'settings.social.search.syntaxGroup' };

const SEARCH_SYNTAX: Readonly<Record<SocialNetworkName, readonly SyntaxRule[]>> = {
  bluesky: [PHRASE, EITHER, EXCLUDE, EXCLUDE_AUTHOR, GROUP],
  mastodon: [
    { operator: 'rss reader', key: 'settings.social.search.syntaxAllWords' },
    PHRASE,
    EITHER,
    EXCLUDE,
    EXCLUDE_AUTHOR,
    GROUP,
  ],
};
```

The component's `syntax` computed now returns the rule list (`SEARCH_SYNTAX[this.network()]`) — rename it `syntaxRules`, and expose `protected readonly combinedExample = COMBINED_EXAMPLE;`. Delete the `SearchSyntax` interface.

`social-search-form.component.html` — the disclosure body becomes:

```html
  <dl class="rules">
    @for (rule of syntaxRules(); track rule.key) {
      <dt>
        <code>{{ rule.operator }}</code>
      </dt>
      <dd>{{ rule.key | transloco }}</dd>
    }
  </dl>
  <p class="combined" data-testid="social-search-combined">
    {{ 'settings.social.search.syntaxCombined' | transloco }}
    <code>
      @for (term of combinedExample.split(' '); track $index; let last = $last) {
        <span class="term">{{ term }}</span>
        @if (!last) {
          {{ ' ' }}
        }
      }
    </code>
  </p>
  @if (network() === 'mastodon') {
    <p class="note">{{ 'settings.social.search.syntaxMastodonNote' | transloco }}</p>
    <p class="note">{{ 'settings.social.search.syntaxMastodonAlternatives' | transloco }}</p>
  }
```

`en.json`, after `syntaxMastodonNote`:

```json
        "syntaxMastodonAlternatives": "Mastodon has no ||: each alternative is searched on its own, at most 8 per query.",
```

`de.json`, after `syntaxMastodonNote`:

```json
        "syntaxMastodonAlternatives": "Mastodon kennt kein ||: jede Alternative wird einzeln gesucht, höchstens 8 pro Suche.",
```

- [ ] **Step 4: Run the spec and the gate**

Run: `docker compose exec -T frontend npx jest src/app/reader/feeds/social-picker` then `cd frontend && npm run check`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add frontend/src/app/reader/feeds/social-picker frontend/public/i18n/en.json frontend/public/i18n/de.json
git commit -m "feat(#2104): the search syntax help lists || and grouping for Mastodon too"
```

---

### Task 5: Gates, live check, PR

- [ ] **Step 1: Gates (parallel)** — `cd backend && composer check`, `composer md` (every touched `src` file clean), `php bin/phpunit`, `docker compose exec php composer test:parallel`, `composer infection:diff`, PhpStorm `lint_files` on changed PHP.
- [ ] **Step 2: /simplify** over the branch diff; commit what it finds.
- [ ] **Step 3: Live check on localhost** — after `docker compose exec php bin/console cache:clear` and restarting `worker`, preview against the Mastodon account (`GET /api/me/social-accounts/2/search-preview`):
  - `("rss reader" || "feed reader" || rssreader || feedreader)` → count > 0 (was 0)
  - `(sternschanze || schanzenviertel || sternbrücke) -"fc st. pauli" -from:hamburgnews.bsky.social -from:hotfitlena.bsky.social` → count > 0 (was 0)
  - `(a || b || c) (d || e || f)` → 422 `social_search_too_broad`
  Scan today's `backend/var/log/dev-*.log` for new warnings. On http://localhost:4200, open the search dialog for the Mastodon account and expand "Search syntax": it lists `rss || atom` and `( … )`, shows the combined example, and both Mastodon notes.
- [ ] **Step 4: PR** into `develop`, body `Closes #2104`, listing the decisions table above and the known limitations: a negated group is not sent to Mastodon (the reader still enforces it when its terms are fully checked); a withheld or stale follow-up restarts the feed's read from its first alternative; the preview limiter counts one preview however many alternatives it asks.
