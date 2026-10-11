# Link Cards Drained in the Background Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** `POST /api/entries/complete` makes at most one page fetch per call, and a background sweep (worker every minute, Strato maintenance tick) drains the rest of the queued link cards.

**Architecture:** `ShareLinkCards::complete()` takes a `LinkCardBudgetModel` (fetch count; its length is count × 8 s) instead of its hard-coded 5 fetches / 40 s, and returns how many rows it finished. `EntryCompletion` passes 1 fetch. A new `LinkCardSweep` reads the oldest claimable `link_cards` rows across all feeds and completes them with a budget its caller picks: 5 fetches from a new worker message, and whatever whole fetches fit the tick's window (at most 2) from `MaintenanceTick`.

**Tech Stack:** PHP 8.4, Symfony 7.4 (Scheduler, Messenger), Doctrine ORM, PHPUnit 12.

**Spec:** `docs/superpowers/specs/2026-10-11-1514-link-cards-drained-in-background-design.md`

## Global Constraints

- Request slice: **1 page fetch per call** (`EntryCompletion`).
- Worker: `every('1 minute')`, **5 fetches** (40 s), message **appended last** in `WorkerSchedule`.
- Tick: link-card sweep runs **before** the saved-search membership sweep, budget = whole fetches of 8 s that fit before the tick deadline, **at most 2**; with less than one fetch left it does nothing.
- Posts stay whole: `ShareLinkCards` keeps "the first row always runs", even past the budget.
- Fetch timeout unchanged (`HtmlPageFetcher::HEAD_MAX_DURATION_SECONDS` = 8.0). Frontend unchanged.
- House rules (`CLAUDE.md`): `final readonly`, names without abbreviations, no comments unless a future reader would get the code wrong without one, PHPMD-clean files, `declare(strict_types=1)`.
- Gates, from `backend/`: `php bin/phpunit`, `composer check`, `composer md`, `composer infection:diff`; MySQL leg with `docker compose exec php composer test`.

---

### Task 1: A fetch budget the caller passes, and one fetch per completion call

**Files:**
- Create: `backend/src/Service/LinkCard/Model/LinkCardBudgetModel.php`
- Modify: `backend/src/Service/LinkCard/ShareLinkCards.php`
- Modify: `backend/src/Service/Completion/EntryCompletion.php`
- Test: `backend/tests/Service/LinkCard/Model/LinkCardBudgetModelTest.php` (create)
- Test: `backend/tests/Service/LinkCard/ShareLinkCardsTest.php`
- Test: `backend/tests/Controller/Api/EntryCompletionControllerTest.php`

**Interfaces:**
- Produces: `LinkCardBudgetModel::fetches(int $count): self`, `->fetches` (public int), `->seconds(): float`, `->isEmpty(): bool`.
- Produces: `ShareLinkCards::complete(array $queued, LinkCardBudgetModel $budget): int` — the number of rows it marked done.

- [ ] **Step 1: Write the failing model test**

`backend/tests/Service/LinkCard/Model/LinkCardBudgetModelTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Service\LinkCard\Model;

use App\Service\LinkCard\Model\LinkCardBudgetModel;
use PHPUnit\Framework\TestCase;

final class LinkCardBudgetModelTest extends TestCase
{
    public function testItsLengthIsEightSecondsPerFetch(): void
    {
        self::assertSame(40.0, LinkCardBudgetModel::fetches(5)->seconds());
        self::assertSame(5, LinkCardBudgetModel::fetches(5)->fetches);
    }

    public function testNoFetchIsAnEmptyBudget(): void
    {
        self::assertTrue(LinkCardBudgetModel::fetches(0)->isEmpty());
        self::assertFalse(LinkCardBudgetModel::fetches(1)->isEmpty());
    }

    public function testANegativeCountIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        LinkCardBudgetModel::fetches(-1);
    }
}
```

- [ ] **Step 2: Run it to verify it fails**

Run (from `backend/`): `php bin/phpunit tests/Service/LinkCard/Model/LinkCardBudgetModelTest.php`
Expected: FAIL, class `LinkCardBudgetModel` not found.

- [ ] **Step 3: Write the model**

`backend/src/Service/LinkCard/Model/LinkCardBudgetModel.php`:

```php
<?php

declare(strict_types=1);

namespace App\Service\LinkCard\Model;

use App\Service\Reader\HtmlPageFetcher;

/** How many page fetches one pass may spend on link cards, and so how long it may take. */
final readonly class LinkCardBudgetModel
{
    private function __construct(public int $fetches)
    {
    }

    public static function fetches(int $count): self
    {
        if ($count < 0) {
            throw new \InvalidArgumentException('A link-card budget cannot be negative.');
        }

        return new self($count);
    }

    public function seconds(): float
    {
        return $this->fetches * HtmlPageFetcher::HEAD_MAX_DURATION_SECONDS;
    }

    public function isEmpty(): bool
    {
        return $this->fetches === 0;
    }
}
```

- [ ] **Step 4: Run the model test to verify it passes**

Run: `php bin/phpunit tests/Service/LinkCard/Model/LinkCardBudgetModelTest.php`
Expected: PASS (3 tests).

- [ ] **Step 5: Pass the budget through every existing `ShareLinkCardsTest` call**

Every call in `backend/tests/Service/LinkCard/ShareLinkCardsTest.php` has the shape `->complete(<one argument>);` on one line. Append the budget as a second argument and add the helper:

```bash
perl -0pi -e 's/->complete\(((?:[^;\n])*)\);/->complete($1, self::fiveFetches());/g' tests/Service/LinkCard/ShareLinkCardsTest.php
```

Then add `use App\Service\LinkCard\Model\LinkCardBudgetModel;` to the imports and this helper next to `sharing()`:

```php
    private static function fiveFetches(): LinkCardBudgetModel
    {
        return LinkCardBudgetModel::fetches(5);
    }
```

Check with `grep -n -- '->complete(' tests/Service/LinkCard/ShareLinkCardsTest.php`: every hit ends in `, self::fiveFetches());`.

- [ ] **Step 6: Add the failing budget tests to `ShareLinkCardsTest`**

```php
    public function testACallSpendsNoMoreFetchesThanItsBudget(): void
    {
        $oldest = $this->queuedPost(1, self::sharing(1, 1));
        $newer = $this->queuedPost(2, self::sharing(2, 1));

        $completed = $this->shareLinkCards->complete($this->claimable(), LinkCardBudgetModel::fetches(1));

        self::assertSame(1, $completed);
        self::assertSame(1, $this->client->getRequestsCount());
        self::assertCount(1, $this->cardTitles($oldest));
        self::assertSame([$newer->requireId()], $this->enrichments()->pendingEntryIdsAmong([$newer->requireId()]));
    }

    public function testAPostWithMoreLinksThanTheBudgetIsStillCardedWhole(): void
    {
        $threeLinks = $this->queuedPost(1, self::sharing(1, 3));

        $completed = $this->shareLinkCards->complete($this->claimable(), LinkCardBudgetModel::fetches(1));

        self::assertSame(1, $completed);
        self::assertSame(3, $this->client->getRequestsCount());
        self::assertCount(3, $this->cardTitles($threeLinks));
    }

    public function testACallStopsAtItsBudgetsDeadline(): void
    {
        $posts = array_map(
            fn (int $number): Entry => $this->queuedPost($number, self::sharing($number, 1)),
            range(1, 3),
        );
        $slowPages = new MockHttpClient(function (string $method, string $url): MockResponse {
            $this->clock->sleep(9);

            return self::page($method, $url);
        });
        $shareLinkCards = ShareLinkCardsWiring::build($this->entityManager, $slowPages, $this->clock);

        $completed = $shareLinkCards->complete($this->claimable(), LinkCardBudgetModel::fetches(2));

        self::assertSame(1, $completed);
        self::assertSame(1, $slowPages->getRequestsCount());
        self::assertSame(
            [$posts[1]->requireId(), $posts[2]->requireId()],
            array_map(static fn (ShareEnrichment $row): int => $row->getEntry()->requireId(), $this->claimable()),
        );
    }
```

The deadline test's numbers: a 2-fetch budget lasts 16 s. The first page takes 9 s, so the second post would need 9 + 8 = 17 s > 16 s and is released, not started.

- [ ] **Step 7: Run them to verify they fail**

Run: `php bin/phpunit tests/Service/LinkCard/ShareLinkCardsTest.php`
Expected: the three new tests FAIL (`complete()` takes one argument and returns void). The renamed existing calls also error until Step 8.

- [ ] **Step 8: Make `ShareLinkCards` take the budget and return the rows it finished**

In `backend/src/Service/LinkCard/ShareLinkCards.php`:

1. Delete both constants (`FETCHES_PER_CALL`, `DEADLINE_SECONDS`) and the now unused `use App\Service\LinkCard\Support\SharedLinks;` if nothing else in the file uses it. Add `use App\Service\LinkCard\Model\LinkCardBudgetModel;`.
2. Replace `complete()`:

```php
    /**
     * @param list<ShareEnrichment> $queued link-card rows, oldest first
     *
     * @return int the rows this call finished
     */
    public function complete(array $queued, LinkCardBudgetModel $budget): int
    {
        $uncardedUrlsByRowId = self::withinBudget($queued, $this->cardedUrls($queued), $budget->fetches);
        $claimedAt = $this->clock->now();
        $claimed = $this->enrichments->claim(
            array_values(array_filter(
                $queued,
                static fn (ShareEnrichment $row): bool => isset($uncardedUrlsByRowId[$row->requireId()]),
            )),
            $claimedAt,
        );

        $pageCardsByRowId = [];
        foreach ($claimed as $index => $row) {
            $uncardedUrls = $uncardedUrlsByRowId[$row->requireId()];
            if ($index > 0 && !$this->hasTimeFor($uncardedUrls, $claimedAt, $budget)) {
                $this->enrichments->release(\array_slice($claimed, $index), $claimedAt);
                break;
            }
            $pageCardsByRowId[$row->requireId()] = $this->pageCardsFor($uncardedUrls);
        }

        return $this->store($claimed, $pageCardsByRowId, $claimedAt);
    }
```

3. `store()` returns `int`: `return 0;` in the `$held === []` guard, and `return \count($held);` after `markDone`. Update its docblock with `@return int the rows marked done`.
4. `hasTimeFor()`:

```php
    /**
     * A later entry starts only if all its links can still be fetched before the budget runs out, well inside the lease.
     *
     * @param list<string> $uncardedUrls
     */
    private function hasTimeFor(array $uncardedUrls, \DateTimeImmutable $claimedAt, LinkCardBudgetModel $budget): bool
    {
        $elapsed = (float) $this->clock->now()->format('U.u') - (float) $claimedAt->format('U.u');

        return $elapsed + \count($uncardedUrls) * HtmlPageFetcher::HEAD_MAX_DURATION_SECONDS <= $budget->seconds();
    }
```

5. `withinBudget(array $queued, array $cardedUrls, int $fetches)`: start with `$remaining = $fetches;`; update its docblock line to "The oldest rows whose uncarded links fit `$fetches`".

- [ ] **Step 9: Run `ShareLinkCardsTest` to verify it passes**

Run: `php bin/phpunit tests/Service/LinkCard/ShareLinkCardsTest.php`
Expected: PASS, all tests.

- [ ] **Step 10: Change the controller test to one fetch per call**

In `backend/tests/Controller/Api/EntryCompletionControllerTest.php`, replace `testACallSpendsAtMostFivePageFetchesOnTheOldestPosts` with:

```php
    public function testACallSpendsOnePageFetchOnTheOldestPost(): void
    {
        [$headers, $user] = $this->auth('complete-budget@example.com');
        $feed = $this->subscribedFeed($user);
        $posts = array_map(
            fn (int $number): Entry => $this->queuedLinkPost($feed, $number, self::sharing($number)),
            [3, 1, 2],
        );

        $this->complete($headers, array_map(static fn (Entry $post): int => $post->requireId(), $posts));

        self::assertResponseIsSuccessful();
        self::assertSame(1, $this->pages->getRequestsCount());
        $oldest = $posts[1];
        $expected = [];
        foreach ($posts as $post) {
            $expected[$post->requireId()] = $post !== $oldest;
        }
        ksort($expected);
        self::assertSame($expected, $this->pendingById($this->payload($this->client)['entries']));
    }
```

- [ ] **Step 11: Run it to verify it fails**

Run: `php bin/phpunit tests/Controller/Api/EntryCompletionControllerTest.php`
Expected: the new test FAILS (and `EntryCompletion` errors, since `complete()` now needs a budget).

- [ ] **Step 12: Pass one fetch from `EntryCompletion`**

In `backend/src/Service/Completion/EntryCompletion.php`, add `use App\Service\LinkCard\Model\LinkCardBudgetModel;`, a constant under the class opening brace:

```php
    private const int LINK_CARD_FETCHES = 1;
```

and change the link-card line in `completeQueued()` to:

```php
        $this->shareLinkCards->complete(
            self::ofKind($queued, ShareEnrichmentKind::LinkCards),
            LinkCardBudgetModel::fetches(self::LINK_CARD_FETCHES),
        );
```

Update the docblock of `completeQueued()` to: "Bluesky posts go first, as one AppView call fills them all; then one page fetch for the oldest link post, so a call holds its worker briefly."

- [ ] **Step 13: Run the controller test to verify it passes**

Run: `php bin/phpunit tests/Controller/Api/EntryCompletionControllerTest.php`
Expected: PASS.

- [ ] **Step 14: Commit**

```bash
git add src/Service/LinkCard src/Service/Completion tests/Service/LinkCard tests/Controller/Api/EntryCompletionControllerTest.php
git commit -m "feat(#1514): a completion call spends one page fetch on link cards"
```

---

### Task 2: The link-card sweep

**Files:**
- Modify: `backend/src/Repository/ShareEnrichmentRepository.php`
- Create: `backend/src/Service/LinkCard/LinkCardSweep.php`
- Create: `backend/src/Service/LinkCard/Model/LinkCardSweepReportModel.php`
- Test: `backend/tests/Repository/ShareEnrichmentRepositoryTest.php`
- Test: `backend/tests/Service/LinkCard/LinkCardSweepTest.php` (create)

**Interfaces:**
- Consumes: `LinkCardBudgetModel`, `ShareLinkCards::complete(array, LinkCardBudgetModel): int` (Task 1).
- Produces: `ShareEnrichmentRepository::findClaimableLinkCards(\DateTimeImmutable $now, int $limit): list<ShareEnrichment>`.
- Produces: `LinkCardSweep::sweep(LinkCardBudgetModel $budget): LinkCardSweepReportModel`.
- Produces: `LinkCardSweepReportModel(int $considered, int $completed)` with `toLogContext(): array{considered: int, completed: int}`.

- [ ] **Step 1: Write the failing repository test**

Add to `backend/tests/Repository/ShareEnrichmentRepositoryTest.php`, after `testFindsAFeedsClaimableAppViewRowsOldestFirstUpToTheLimit` (it uses the file's existing `feed()`, `queued()`, `now()`, `repository()` and `guidsOf()` helpers):

```php
    public function testFindsClaimableLinkCardRowsOfEveryFeedOldestFirstUpToTheLimit(): void
    {
        $mastodon = $this->feed('https://mastodon.test/@a.rss');
        $news = $this->feed('https://news.test/feed');
        $this->queued($mastodon, 'https://mastodon.test/@a/new', '2026-10-10 11:00:00', ShareEnrichmentKind::LinkCards);
        $this->queued($news, 'https://news.test/old', '2026-10-10 09:00:00', ShareEnrichmentKind::LinkCards);
        $this->queued($mastodon, 'https://mastodon.test/@a/mid', '2026-10-10 10:00:00', ShareEnrichmentKind::LinkCards);
        $this->queued($mastodon, 'at://did:plc:a/app.bsky.feed.post/oldest', '2026-10-10 08:00:00');
        $held = $this->queued($news, 'https://news.test/held', '2026-10-10 07:00:00', ShareEnrichmentKind::LinkCards);
        $this->entityManager->flush();
        $this->repository()->claim([$held], $this->now());

        $rows = $this->repository()->findClaimableLinkCards($this->now(), 2);

        self::assertSame(['https://news.test/old', 'https://mastodon.test/@a/mid'], self::guidsOf($rows));
    }
```

- [ ] **Step 2: Run it to verify it fails**

Run: `php bin/phpunit tests/Repository/ShareEnrichmentRepositoryTest.php --filter LinkCardRowsOfEveryFeed`
Expected: FAIL, undefined method `findClaimableLinkCards`.

- [ ] **Step 3: Add the query**

In `backend/src/Repository/ShareEnrichmentRepository.php`, after `findClaimableAppViewForFeed()`:

```php
    /** @return list<ShareEnrichment> the oldest link-card rows no worker holds, of every feed */
    public function findClaimableLinkCards(\DateTimeImmutable $now, int $limit): array
    {
        /** @var list<ShareEnrichment> $rows */
        $rows = $this->claimable($now)
            ->andWhere('pending.kind = :kind')
            ->setParameter('kind', ShareEnrichmentKind::LinkCards)
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();

        return $rows;
    }
```

- [ ] **Step 4: Run it to verify it passes**

Run: `php bin/phpunit tests/Repository/ShareEnrichmentRepositoryTest.php`
Expected: PASS.

- [ ] **Step 5: Write the failing sweep test**

`backend/tests/Service/LinkCard/LinkCardSweepTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Service\LinkCard;

use App\Entity\Entry;
use App\Entity\Feed;
use App\Entity\ShareEnrichment;
use App\Enum\ShareEnrichmentKind;
use App\Service\Clock\NaiveUtcClock;
use App\Service\LinkCard\LinkCardSweep;
use App\Service\LinkCard\Model\LinkCardBudgetModel;
use App\Service\LinkCard\Model\LinkCardSweepReportModel;
use App\Tests\DbTestCase;
use App\Tests\Support\PostEnrichers;
use App\Tests\Support\ShareLinkCardsWiring;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class LinkCardSweepTest extends DbTestCase
{
    private MockClock $clock;
    private MockHttpClient $client;
    private LinkCardSweep $sweep;

    protected function setUp(): void
    {
        parent::setUp();
        $this->clock = new MockClock('2026-10-11 12:00:00', 'UTC');
        $this->client = new MockHttpClient(static fn (string $method, string $url): MockResponse => new MockResponse(
            '<html><head><title>Card for ' . $url . '</title></head></html>',
            ['response_headers' => ['content-type' => 'text/html']],
        ));
        $this->sweep = new LinkCardSweep(
            PostEnrichers::enrichments($this->entityManager),
            ShareLinkCardsWiring::build($this->entityManager, $this->client, $this->clock),
            new NaiveUtcClock($this->clock),
        );
    }

    public function testCardsTheOldestQueuedPostsOfEveryFeedWithinItsBudget(): void
    {
        $older = $this->queuedPost('https://mastodon.test/@a.rss', 1);
        $old = $this->queuedPost('https://news.test/feed', 2);
        $newest = $this->queuedPost('https://mastodon.test/@b.rss', 3);

        $report = $this->sweep->sweep(LinkCardBudgetModel::fetches(2));

        self::assertEquals(new LinkCardSweepReportModel(2, 2), $report);
        self::assertSame(2, $this->client->getRequestsCount());
        self::assertSame(
            [$newest->requireId()],
            PostEnrichers::enrichments($this->entityManager)->pendingEntryIdsAmong(
                [$older->requireId(), $old->requireId(), $newest->requireId()],
            ),
        );
    }

    public function testAnEmptyBudgetDoesNothing(): void
    {
        $this->queuedPost('https://mastodon.test/@a.rss', 1);

        $report = $this->sweep->sweep(LinkCardBudgetModel::fetches(0));

        self::assertEquals(new LinkCardSweepReportModel(0, 0), $report);
        self::assertSame(0, $this->client->getRequestsCount());
    }

    public function testReportsItsCountsForTheLog(): void
    {
        self::assertSame(
            ['considered' => 3, 'completed' => 2],
            (new LinkCardSweepReportModel(3, 2))->toLogContext(),
        );
    }

    /** Queued `10 - $order` minutes ago, so a lower order is older; one shared link, in a feed of its own. */
    private function queuedPost(string $feedUrl, int $order): Entry
    {
        $feed = new Feed($feedUrl);
        $this->entityManager->persist($feed);
        $now = $this->clock->now();
        $entry = new Entry($feed, $feedUrl . '#' . $order, null, 'Post', $now, $now);
        $entry->setContentHtml('<p>https://example.com/' . $order . '</p>');
        $entry->markTitleDerived();
        $entry->getImage()->storePending(null, null, null);
        $this->entityManager->persist($entry);
        $this->entityManager->persist(new ShareEnrichment(
            $entry,
            ShareEnrichmentKind::LinkCards,
            $now->modify(\sprintf('-%d minutes', 10 - $order)),
        ));
        $this->entityManager->flush();

        return $entry;
    }
}
```

- [ ] **Step 6: Run it to verify it fails**

Run: `php bin/phpunit tests/Service/LinkCard/LinkCardSweepTest.php`
Expected: FAIL, class `LinkCardSweep` not found.

- [ ] **Step 7: Write the report model and the sweep**

`backend/src/Service/LinkCard/Model/LinkCardSweepReportModel.php`:

```php
<?php

declare(strict_types=1);

namespace App\Service\LinkCard\Model;

final readonly class LinkCardSweepReportModel
{
    public function __construct(
        public int $considered,
        public int $completed,
    ) {
    }

    /** @return array{considered: int, completed: int} */
    public function toLogContext(): array
    {
        return ['considered' => $this->considered, 'completed' => $this->completed];
    }
}
```

`backend/src/Service/LinkCard/LinkCardSweep.php`:

```php
<?php

declare(strict_types=1);

namespace App\Service\LinkCard;

use App\Repository\ShareEnrichmentRepository;
use App\Service\Clock\NaiveUtcClock;
use App\Service\LinkCard\Model\LinkCardBudgetModel;
use App\Service\LinkCard\Model\LinkCardSweepReportModel;

/** Cards the oldest queued link posts of every feed, outside any request. */
final readonly class LinkCardSweep
{
    public function __construct(
        private ShareEnrichmentRepository $enrichments,
        private ShareLinkCards $shareLinkCards,
        private NaiveUtcClock $clock,
    ) {
    }

    public function sweep(LinkCardBudgetModel $budget): LinkCardSweepReportModel
    {
        if ($budget->isEmpty()) {
            return new LinkCardSweepReportModel(0, 0);
        }

        $queued = $this->enrichments->findClaimableLinkCards($this->clock->now(), $budget->fetches);

        return new LinkCardSweepReportModel(\count($queued), $this->shareLinkCards->complete($queued, $budget));
    }
}
```

- [ ] **Step 8: Run it to verify it passes**

Run: `php bin/phpunit tests/Service/LinkCard/LinkCardSweepTest.php`
Expected: PASS (3 tests).

- [ ] **Step 9: Commit**

```bash
git add src/Repository/ShareEnrichmentRepository.php src/Service/LinkCard tests/Repository/ShareEnrichmentRepositoryTest.php tests/Service/LinkCard/LinkCardSweepTest.php
git commit -m "feat(#1514): a sweep cards the oldest queued link posts of every feed"
```

---

### Task 3: The worker runs the sweep every minute

**Files:**
- Create: `backend/src/Service/Worker/Message/CompleteQueuedLinkCards.php`
- Create: `backend/src/Service/Worker/Handler/CompleteQueuedLinkCardsHandler.php`
- Modify: `backend/src/Service/Worker/WorkerSchedule.php`
- Test: `backend/tests/Service/Worker/CompleteQueuedLinkCardsHandlerTest.php` (create)
- Test: `backend/tests/Service/Worker/WorkerScheduleWiringTest.php`

**Interfaces:**
- Consumes: `LinkCardSweep::sweep(LinkCardBudgetModel): LinkCardSweepReportModel` (Task 2).
- Produces: message `App\Service\Worker\Message\CompleteQueuedLinkCards` (no properties).

- [ ] **Step 1: Write the failing handler test**

`backend/tests/Service/Worker/CompleteQueuedLinkCardsHandlerTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Service\Worker;

use App\Service\Clock\NaiveUtcClock;
use App\Service\LinkCard\LinkCardSweep;
use App\Service\Worker\Handler\CompleteQueuedLinkCardsHandler;
use App\Service\Worker\Message\CompleteQueuedLinkCards;
use App\Tests\DbTestCase;
use App\Tests\Support\PostEnrichers;
use App\Tests\Support\RecordingLogger;
use App\Tests\Support\ShareLinkCardsWiring;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpClient\MockHttpClient;

final class CompleteQueuedLinkCardsHandlerTest extends DbTestCase
{
    public function testFiringSweepsAndLogsTheReport(): void
    {
        $clock = new MockClock('2026-10-11 12:00:00', 'UTC');
        $logger = new RecordingLogger();
        $handler = new CompleteQueuedLinkCardsHandler(
            new LinkCardSweep(
                PostEnrichers::enrichments($this->entityManager),
                ShareLinkCardsWiring::build($this->entityManager, new MockHttpClient(), $clock),
                new NaiveUtcClock($clock),
            ),
            $logger,
        );

        $handler(new CompleteQueuedLinkCards());

        self::assertCount(1, $logger->records);
        self::assertSame('info', $logger->records[0]['level']);
        self::assertSame('Worker link-card sweep finished.', $logger->records[0]['message']);
        self::assertSame(['considered' => 0, 'completed' => 0], $logger->records[0]['context']['report']);
    }
}
```

- [ ] **Step 2: Pin the schedule entry in `WorkerScheduleWiringTest`**

In `testTheWorkerScheduleCarriesExactlyTheDecidedEntries`: `assertCount(9, …)`, append `CompleteQueuedLinkCards::class` as the last class and `'every 1 minute'` as the last frequency, and import `App\Service\Worker\Message\CompleteQueuedLinkCards`.

- [ ] **Step 3: Run both to verify they fail**

Run: `php bin/phpunit tests/Service/Worker/CompleteQueuedLinkCardsHandlerTest.php tests/Service/Worker/WorkerScheduleWiringTest.php`
Expected: FAIL (class not found; schedule has 8 entries).

- [ ] **Step 4: Write the message, the handler, and the schedule entry**

`backend/src/Service/Worker/Message/CompleteQueuedLinkCards.php`:

```php
<?php

declare(strict_types=1);

namespace App\Service\Worker\Message;

final readonly class CompleteQueuedLinkCards
{
}
```

`backend/src/Service/Worker/Handler/CompleteQueuedLinkCardsHandler.php`:

```php
<?php

declare(strict_types=1);

namespace App\Service\Worker\Handler;

use App\Service\LinkCard\LinkCardSweep;
use App\Service\LinkCard\Model\LinkCardBudgetModel;
use App\Service\Worker\Message\CompleteQueuedLinkCards;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class CompleteQueuedLinkCardsHandler
{
    private const int FETCHES = 5;

    public function __construct(
        private LinkCardSweep $linkCardSweep,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(CompleteQueuedLinkCards $message): void
    {
        $report = $this->linkCardSweep->sweep(LinkCardBudgetModel::fetches(self::FETCHES));
        $this->logger->info('Worker link-card sweep finished.', ['report' => $report->toLogContext()]);
    }
}
```

In `backend/src/Service/Worker/WorkerSchedule.php`, import the message and add, as the **last** `->add(...)` line (after `StartDueProfileRuns`):

```php
            ->add(RecurringMessage::every('1 minute', new CompleteQueuedLinkCards()))
```

- [ ] **Step 5: Run them to verify they pass**

Run: `php bin/phpunit tests/Service/Worker/`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add src/Service/Worker tests/Service/Worker
git commit -m "feat(#1514): the worker cards queued link posts every minute"
```

---

### Task 4: The Strato maintenance tick runs the sweep in its window

**Files:**
- Modify: `backend/src/Service/LinkCard/Model/LinkCardBudgetModel.php`
- Modify: `backend/src/Service/Maintenance/MaintenanceTick.php`
- Modify: `backend/src/Service/Maintenance/Model/MaintenanceSweepsModel.php`
- Modify: `backend/src/Http/MaintenanceTickJson.php`
- Test: `backend/tests/Service/LinkCard/Model/LinkCardBudgetModelTest.php`
- Test: `backend/tests/Service/Maintenance/MaintenanceTickTest.php`
- Test: `backend/tests/Http/MaintenanceTickJsonTest.php`

**Interfaces:**
- Consumes: `LinkCardSweep`, `LinkCardSweepReportModel` (Task 2).
- Produces: `LinkCardBudgetModel::fittingBefore(\DateTimeImmutable $deadline, \DateTimeImmutable $now, int $maxFetches): self`.
- Produces: `MaintenanceSweepsModel::$linkCards` (`LinkCardSweepReportModel`), 4th positional argument, before `$savedSearchMemberships`; tick JSON key `linkCards` → `{considered, completed}`.

- [ ] **Step 1: Write the failing budget tests**

Add to `LinkCardBudgetModelTest`:

```php
    public function testFitsTheWholeFetchesLeftBeforeADeadlineUpToAMaximum(): void
    {
        $now = new \DateTimeImmutable('2026-10-11 12:00:00');

        self::assertSame(2, LinkCardBudgetModel::fittingBefore($now->modify('+20 seconds'), $now, 2)->fetches);
        self::assertSame(1, LinkCardBudgetModel::fittingBefore($now->modify('+15 seconds'), $now, 2)->fetches);
        self::assertSame(1, LinkCardBudgetModel::fittingBefore($now->modify('+8 seconds'), $now, 2)->fetches);
        self::assertTrue(LinkCardBudgetModel::fittingBefore($now->modify('+7 seconds'), $now, 2)->isEmpty());
        self::assertTrue(LinkCardBudgetModel::fittingBefore($now->modify('-3 seconds'), $now, 2)->isEmpty());
    }
```

- [ ] **Step 2: Run it to verify it fails**

Run: `php bin/phpunit tests/Service/LinkCard/Model/LinkCardBudgetModelTest.php`
Expected: FAIL, undefined method `fittingBefore`.

- [ ] **Step 3: Add `fittingBefore()`**

In `LinkCardBudgetModel`, after `fetches()`:

```php
    /** The whole fetches that end before $deadline, at most $maxFetches: none once less than one fits. */
    public static function fittingBefore(\DateTimeImmutable $deadline, \DateTimeImmutable $now, int $maxFetches): self
    {
        $remaining = $deadline->getTimestamp() - $now->getTimestamp();
        $fitting = (int) floor(max(0, $remaining) / HtmlPageFetcher::HEAD_MAX_DURATION_SECONDS);

        return self::fetches(min($maxFetches, $fitting));
    }
```

- [ ] **Step 4: Run it to verify it passes**

Run: `php bin/phpunit tests/Service/LinkCard/Model/LinkCardBudgetModelTest.php`
Expected: PASS.

- [ ] **Step 5: Write the failing tick JSON expectations**

In `backend/tests/Http/MaintenanceTickJsonTest.php`:
- import `App\Service\LinkCard\Model\LinkCardSweepReportModel`;
- in `testACompletedTickSendsEveryHalfUnderItsKey`, pass `new LinkCardSweepReportModel(16, 17)` as the 4th argument of `new MaintenanceSweepsModel(...)` (before the `SavedSearchMembershipSweepReportModel`), and expect, between `'imageVerification'` and `'savedSearchMemberships'`:

```php
                'linkCards' => ['considered' => 16, 'completed' => 17],
```

- in `testSweepsSkippedAfterAnAbortedRefreshSayWhy`, expect at the same place:

```php
                'linkCards' => ['considered' => 0, 'completed' => 0, 'skipped' => $reason],
```

- [ ] **Step 6: Run it to verify it fails**

Run: `php bin/phpunit tests/Http/MaintenanceTickJsonTest.php`
Expected: FAIL (too many constructor arguments / missing key).

- [ ] **Step 7: Carry the report through the sweeps model and the JSON**

`MaintenanceSweepsModel`: import `App\Service\LinkCard\Model\LinkCardSweepReportModel`; add `public LinkCardSweepReportModel $linkCards,` after `$imageVerification`; in `skippedAfterAbortedRefresh()` pass `new LinkCardSweepReportModel(0, 0),` in the same position.

`MaintenanceTickJson::report()`: between `'imageVerification'` and `'savedSearchMemberships'`:

```php
            'linkCards' => self::markedIfSkipped($sweeps, $sweeps->linkCards->toLogContext()),
```

- [ ] **Step 8: Run it to verify it passes**

Run: `php bin/phpunit tests/Http/MaintenanceTickJsonTest.php`
Expected: PASS.

- [ ] **Step 9: Run the sweep from the tick**

`MaintenanceTick`:
- constructor: add `private LinkCardSweep $linkCardSweep,` after `$imageVerificationSweep`;
- constant, next to the others:

```php
    /** Link cards fill the tick's spare window, two page fetches at most. */
    private const int LINK_CARD_MAX_FETCHES = 2;
```

- `sweep()` becomes:

```php
    private function sweep(\DateTimeImmutable $deadline): MaintenanceSweepsModel
    {
        $recommendations = $this->forYouSweep->sweepOnce();
        $digests = $this->sendDueDigests->run();
        $imageVerification = $this->imageVerificationSweep->verifyDue();
        $linkCards = $this->linkCardSweep->sweep(
            LinkCardBudgetModel::fittingBefore($deadline, $this->clock->now(), self::LINK_CARD_MAX_FETCHES),
        );
        $budget = SweepBudgetModel::remainingUntil($deadline, $this->clock->now(), self::MEMBERSHIP_BUDGET_SECONDS);

        return new MaintenanceSweepsModel(
            $recommendations,
            $digests,
            $imageVerification,
            $linkCards,
            $this->membershipSweep->sweep($budget),
        );
    }
```

- update the class docblock's first line to "Refresh first, then the sweeps (link cards before saved-search memberships, which take what is left), then the log spool."
- imports: `App\Service\LinkCard\LinkCardSweep`, `App\Service\LinkCard\Model\LinkCardBudgetModel`.

`MaintenanceTickTest::testSkipsTheRecommendationSweepWhenRefreshAborts` constructs the tick by hand: pass a `LinkCardSweep` after `$imageVerificationSweep`:

```php
        $linkCardSweep = self::getContainer()->get(LinkCardSweep::class);
        self::assertInstanceOf(LinkCardSweep::class, $linkCardSweep);
```

(import `App\Service\LinkCard\LinkCardSweep`). The test proves the skip, so the sweep must not run; the container's sweep is enough because the aborted path never calls it.

- [ ] **Step 10: Run the tick tests**

Run: `php bin/phpunit tests/Service/Maintenance/ tests/Http/MaintenanceTickJsonTest.php tests/Controller/MaintenanceControllerTest.php`
Expected: PASS.

- [ ] **Step 11: Commit**

```bash
git add src/Service/LinkCard/Model src/Service/Maintenance src/Http/MaintenanceTickJson.php tests/Service/LinkCard/Model tests/Service/Maintenance tests/Http
git commit -m "feat(#1514): the maintenance tick cards link posts in its spare window"
```

---

### Task 5: Gates

- [ ] **Step 1:** `php bin/phpunit` (SQLite) — all green.
- [ ] **Step 2:** `docker compose exec php composer test` (MySQL) — all green. The container must run this checkout's code (see the memory note on stale containers: check before trusting a result).
- [ ] **Step 3:** `composer check` (cs + stan + tramp) and `composer md` — clean on every touched `src` file.
- [ ] **Step 4:** `composer infection:diff` — at or above `minMsi`. Kill an escaped mutant with a test, not a threshold change.
- [ ] **Step 5:** Scan today's dev log: `ls -t var/log/dev-*.log | head -1 | xargs tail -n 50 | jq .` — no new deprecations or errors.
- [ ] **Step 6:** Commit any gate fixes: `git commit -m "chore(#1514): gate fixes"` (only if something changed).
