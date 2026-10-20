# Timeline Root Self-Thread Implementation Plan (#1727)

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** When a timeline feed (Bluesky home/list/custom feed, Mastodon home/list) stores a post whose network reports replies, the post is queued for the self-thread pass. That pass reads the thread from the network and folds the author's self-replies, as it already does for RSS roots. Today such a post folds only when its replies happen to be on the same timeline page, so a custom feed like Bluesky *Discover* never shows Techpresso's threads.

**Architecture:** `KnownSharesModel` (the network's own answer about a page's posts) learns which guids the network says have replies: a Bluesky post view with `replyCount > 0`, or a Mastodon status with `replies_count > 0`. `ShareEnrichmentQueue` decides one queued kind per new entry. `KnownShareSettler` stops persisting rows. It returns the kind the entry still waits for, and the queue persists the row. A post with known replies is queued as `SelfThread` instead of `LinkCards`. `SelfThreadEnricher::handOnOrDrop()` already hands a still-uncarded root on to the link-card pass after the fold, exactly as `PostEnricher::settle()` does for RSS. No new pass, no new network client.

**Tech Stack:** Symfony 7.4, PHP 8.4, Doctrine ORM, PHPUnit 12.

**Spec:** GitHub issue #1727, plus the root-cause trace in the session. The live case is subscription 1904 (Discover), entry 627341. `getPostThread` lists one reply by techpresso.bsky.social for that entry, but its `thread` is `[]`.

## Global Constraints

- CLAUDE.md applies in full: Clean Code names, `final readonly`, one role per folder, no boolean flag parameters, three parameters at most, default to no comment, PHPMD-clean touched files.
- No Service-module cycle. `Completion → Bluesky` and `Social → Completion` already exist. Do not add `Completion → Social`.
- No backfill. Entries that already exist stay as they are.
- The fold rules of #1675 do not change. A page fold and the network fold already exclude each other. `SelfThreadEnricher::foldThreadOf()` skips an entry that `ThreadPostRepository::hasAnyFor()` finds rows for. `SelfThreadPlacement` skips a reply whose URL is already a stored position.
- Commits use `type(#1727): …`, with no Claude attribution. The branch is `fix/1727-timeline-root-self-thread`, off `develop`. Never commit to develop.

## Decisions (recorded, not to re-litigate)

- **Both networks.** Mastodon lists have the same gap as Bluesky custom feeds, and the general fix costs one more field. `MastodonPendingThread` stays as it is: it queues RSS statuses unconditionally, because RSS carries no reply count.
- **Any reply count, not only self-replies.** The timeline item doesn't say who replied. The RSS path queues on the same `replyCount > 0` test (`PostEnricher::hasReplies()`). Volume is bounded by `SelfThreadEnricher::THREADS_PER_PASS` (25 per feed pass) and the give-up window.
- **A root whose replies folded from the page still gets queued.** The enricher then skips it on `hasAnyFor()`. That costs one thread read, which is not worth an extra exclusion path.
- **Known minor, unchanged:** a queued text post shows the article view's pending link-card skeleton until its completion runs. This is the parked "plain Mastodon RSS posts show a pending skeleton" item from #1668, and it is out of scope here.

## File map

| File | Change |
|---|---|
| `backend/src/Service/Completion/Model/KnownSharesModel.php` | Add `repliedGuids`; `ofBlueskyPosts()` derives it from `replyCount`; `ofMastodonShares()` takes it; `hasRepliesFor()` |
| `backend/src/Service/Social/Support/MastodonTimelinePage.php` | `knownSharesOf()` passes the guids whose status has `replies_count > 0` |
| `backend/src/Service/Completion/KnownShareSettler.php` | `settleEmbedded()` / `settleCarded()` return `?ShareEnrichmentKind` instead of persisting; drop `EntityManagerInterface` and the `$queuedAt` parameters |
| `backend/src/Service/Completion/ShareEnrichmentQueue.php` | One `awaitedKindOf()` decision per entry; known replies turn it into `SelfThread`; one `persist` |
| `backend/tests/Support/PostEnrichers.php` | `KnownShareSettler` constructor follows |
| `backend/tests/Service/Social/Support/BlueskyTimelinePageTest.php` | Replied guid known |
| `backend/tests/Service/Social/Support/MastodonTimelinePageTest.php` | Replied guid known |
| `backend/tests/Service/Completion/ShareEnrichmentQueueTest.php` | Queued kinds for replied posts |
| `backend/tests/Service/Refresh/FeedOutcomePersisterTimelineTest.php` | A lone root that reports replies is queued through the real refresh wiring |

---

### Task 1: The network's answer names the replied posts

**Files:**
- Modify: `backend/src/Service/Completion/Model/KnownSharesModel.php`
- Modify: `backend/src/Service/Social/Support/MastodonTimelinePage.php:121-133`
- Test: `backend/tests/Service/Social/Support/BlueskyTimelinePageTest.php`
- Test: `backend/tests/Service/Social/Support/MastodonTimelinePageTest.php`

**Interfaces:**
- Produces: `KnownSharesModel::hasRepliesFor(string $guid): bool`.
- Produces: `KnownSharesModel::ofMastodonShares(array $cardsByGuid, array $quotesByGuid, array $repliedGuids = [])`, where `$repliedGuids` is a `list<string>`.
- `ofBlueskyPosts(array $postsByGuid)` keeps its signature.

- [ ] **Step 1: Write the failing tests**

In `BlueskyTimelinePageTest`, after `testThePostViewIsKeptForItsEmbedAndThePageNamesNoTitle`:

```php
    public function testAPostTheNetworkCountsRepliesForIsKnownAsReplied(): void
    {
        $page = BlueskyTimelinePage::of([self::item(post: ['replyCount' => 1])], self::ALICE);

        self::assertTrue($page->knownShares->hasRepliesFor(self::POST_URI));
    }

    public function testAPostWithoutRepliesIsNotKnownAsReplied(): void
    {
        $unreplied = BlueskyTimelinePage::of([self::item(post: ['replyCount' => 0])], self::ALICE);
        $uncounted = BlueskyTimelinePage::of([self::item()], self::ALICE);

        self::assertFalse($unreplied->knownShares->hasRepliesFor(self::POST_URI));
        self::assertFalse($uncounted->knownShares->hasRepliesFor(self::POST_URI));
    }
```

`JsonNodeModel` exposes no raw array, and none is to be added. Give the test's `item()` a second parameter that is merged into the default post fields. The existing `$overrides` callers stay unchanged:

```php
    /**
     * @param array<string, mixed> $overrides
     * @param array<string, mixed> $post
     */
    private static function item(array $overrides = [], array $post = []): JsonNodeModel
    {
        return JsonNodeModel::of($overrides + [
            'post' => $post + [
                'uri' => self::POST_URI,
                // … the existing default post fields, unchanged …
            ],
        ]);
    }
```

In `MastodonTimelinePageTest`, after `testAStatusWithoutAQuoteKnowsNone`:

```php
    public function testAStatusTheInstanceCountsRepliesForIsKnownAsReplied(): void
    {
        $page = MastodonTimelinePage::of([self::timelineStatus(['replies_count' => 2])]);

        self::assertTrue($page->knownShares->hasRepliesFor(self::URI));
    }

    public function testAStatusWithoutRepliesIsNotKnownAsReplied(): void
    {
        self::assertFalse(
            MastodonTimelinePage::of([self::timelineStatus(['replies_count' => 0])])->knownShares->hasRepliesFor(self::URI),
        );
        self::assertFalse(MastodonTimelinePage::of([self::timelineStatus()])->knownShares->hasRepliesFor(self::URI));
    }

    public function testABoostIsKnownAsRepliedByItsOriginalsCount(): void
    {
        $page = MastodonTimelinePage::of([self::timelineStatus([
            'id' => '200',
            'uri' => 'https://mastodon.example/users/alice/statuses/200/activity',
            'replies_count' => 0,
            'reblog' => self::statusFields(['replies_count' => 3]),
        ])]);

        self::assertTrue($page->knownShares->hasRepliesFor(self::URI));
    }
```

Check `testABoostShowsTheOriginalWithATrailingAttributionAtTheBoostTime` for the exact boost fields the fixture needs (booster account and so on), and copy them. The entry guid of a boost is the original's `uri` (`self::URI`).

- [ ] **Step 2: Run them to verify they fail**

Run: `php bin/phpunit tests/Service/Social/Support/BlueskyTimelinePageTest.php tests/Service/Social/Support/MastodonTimelinePageTest.php`
Expected: FAIL with "Call to undefined method …KnownSharesModel::hasRepliesFor()".

- [ ] **Step 3: Implement**

`KnownSharesModel`:

```php
    /**
     * @param array<string, JsonNodeModel>     $blueskyPosts
     * @param array<string, LinkCardContent>   $linkCards
     * @param array<string, QuotedPostContent> $quotedPosts
     * @param array<string, true>              $repliedGuids
     */
    private function __construct(
        private array $blueskyPosts,
        private array $linkCards,
        private array $quotedPosts,
        private array $repliedGuids,
    ) {
    }

    public static function none(): self
    {
        return new self([], [], [], []);
    }

    /** @param array<string, JsonNodeModel> $postsByGuid the post views a timeline answered with */
    public static function ofBlueskyPosts(array $postsByGuid): self
    {
        $replied = array_filter($postsByGuid, static fn (JsonNodeModel $post): bool => ($post->int('replyCount') ?? 0) > 0);

        return new self($postsByGuid, [], [], array_fill_keys(array_map('strval', array_keys($replied)), true));
    }

    /**
     * @param array<string, LinkCardContent>   $cardsByGuid  the preview cards the instance already built
     * @param array<string, QuotedPostContent> $quotesByGuid the statuses the instance says a post quotes
     * @param list<string>                     $repliedGuids the statuses the instance counts replies for
     */
    public static function ofMastodonShares(array $cardsByGuid, array $quotesByGuid, array $repliedGuids = []): self
    {
        return new self([], $cardsByGuid, $quotesByGuid, array_fill_keys($repliedGuids, true));
    }

    public function hasRepliesFor(string $guid): bool
    {
        return isset($this->repliedGuids[$guid]);
    }
```

PHP turns numeric-string array keys into ints, so `array_keys()` can return ints. The `strval` map keeps PHPStan's `array<string, true>` honest. If PHPStan accepts `array_fill_keys(array_keys($replied), true)` as `array<string, true>` without the map, drop the map.

`MastodonTimelinePage::knownSharesOf()`: add the replied guids.

```php
        $replied = array_filter(
            $postsByGuid,
            static fn (JsonNodeModel $post): bool => ($post->int('replies_count') ?? 0) > 0,
        );

        return KnownSharesModel::ofMastodonShares($cards, $quotes, array_map('strval', array_keys($replied)));
```

`$postsByGuid` already holds the original of a boost (`self::originalOf($status)`), so a boost counts its original's replies.

- [ ] **Step 4: Run them to verify they pass**

Run: `php bin/phpunit tests/Service/Social/Support/`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add backend/src/Service/Completion/Model/KnownSharesModel.php backend/src/Service/Social/Support/MastodonTimelinePage.php backend/tests/Service/Social/Support/
git commit -m "feat(#1727): a timeline page knows which of its posts the network counts replies for"
```

---

### Task 2: A post with known replies is queued for the self-thread pass

**Files:**
- Modify: `backend/src/Service/Completion/KnownShareSettler.php`
- Modify: `backend/src/Service/Completion/ShareEnrichmentQueue.php`
- Modify: `backend/tests/Support/PostEnrichers.php:69-83`
- Test: `backend/tests/Service/Completion/ShareEnrichmentQueueTest.php`
- Test: `backend/tests/Service/Refresh/FeedOutcomePersisterTimelineTest.php`

**Interfaces:**
- Consumes: `KnownSharesModel::hasRepliesFor(string $guid): bool` and `ofMastodonShares(…, list<string> $repliedGuids)` from Task 1.
- Produces: `KnownShareSettler::settleEmbedded(Entry $entry, JsonNodeModel $post): ?ShareEnrichmentKind` and `settleCarded(Entry $entry, LinkCardContent $card): ?ShareEnrichmentKind`. Each returns `ShareEnrichmentKind::LinkCards` while a link still waits for its card, and `null` otherwise. `settleQuoted()` is unchanged.
- The `KnownShareSettler` constructor becomes `(EntryEmbedWriterInterface $embedWriter, EntryCardWriter $cardWriter, LoggerInterface $logger)`.

- [ ] **Step 1: Write the failing queue tests**

In `ShareEnrichmentQueueTest`, after `testAKnownBlueskyPostWithALinkAndNoEmbedAwaitsItsLinkCard`:

```php
    public function testAKnownBlueskyPostWithRepliesWaitsForItsThreadBeforeItsCards(): void
    {
        $post = $this->post(self::AT_POST, '<p>See <a href="https://example.com/a">https://example.com/a</a></p>');

        $this->queue->queueWithKnownShares(
            [$post],
            KnownSharesModel::ofBlueskyPosts([self::AT_POST => JsonNodeModel::of(['uri' => self::AT_POST, 'replyCount' => 1])]),
        );
        $this->entityManager->flush();

        self::assertSame([self::AT_POST => ShareEnrichmentKind::SelfThread], $this->queuedKinds());
        self::assertSame(ShareKind::Link, $this->reload($post)->getShareKind());
    }

    public function testAKnownBlueskyPostWithRepliesAndNothingToCardWaitsForItsThread(): void
    {
        $post = $this->post(self::AT_POST, '<p>Just words.</p>');

        $this->queue->queueWithKnownShares(
            [$post],
            KnownSharesModel::ofBlueskyPosts([self::AT_POST => JsonNodeModel::of(['uri' => self::AT_POST, 'replyCount' => 1])]),
        );
        $this->entityManager->flush();

        self::assertSame([self::AT_POST => ShareEnrichmentKind::SelfThread], $this->queuedKinds());
    }
```

After `testAMastodonTimelineLinkPostIsQueuedForItsCardsAsBefore`:

```php
    public function testAMastodonTimelinePostWithRepliesWaitsForItsThread(): void
    {
        $feed = $this->socialFeed('social:mastodon:3:home', SourceFormat::MASTODON_API);
        $post = $this->postIn($feed, 'https://mastodon.example/@thread/3');

        $this->queue->queueWithKnownShares(
            [$post],
            KnownSharesModel::ofMastodonShares([], [], ['https://mastodon.example/@thread/3']),
        );
        $this->entityManager->flush();

        self::assertSame(
            ['https://mastodon.example/@thread/3' => ShareEnrichmentKind::SelfThread],
            $this->queuedKinds(),
        );
    }

    public function testACardedMastodonPostWithRepliesStillWaitsForItsThread(): void
    {
        $guid = 'https://mastodon.example/@someone/1';
        $post = $this->post($guid, '<p>Read <a href="https://example.com/story">https://example.com/story</a></p>');
        $card = new LinkCardContent('https://example.com/story', 'The story', null, null, null);

        $this->queue->queueWithKnownShares([$post], KnownSharesModel::ofMastodonShares([$guid => $card], [], [$guid]));
        $this->entityManager->flush();

        self::assertSame([$guid => ShareEnrichmentKind::SelfThread], $this->queuedKinds());
        self::assertCount(1, $this->storedCards($this->reload($post)));
    }

    public function testAQuotingMastodonPostWithRepliesStillWaitsForItsThread(): void
    {
        $guid = 'https://mastodon.example/@someone/1';
        $post = $this->post($guid, '<p>RE: <a href="https://other.example/@b/9">https://other.example/@b/9</a></p><p>Mine.</p>');

        $this->queue->queueWithKnownShares(
            [$post],
            KnownSharesModel::ofMastodonShares([], [$guid => self::quotedPost()], [$guid]),
        );
        $this->entityManager->flush();

        self::assertSame([$guid => ShareEnrichmentKind::SelfThread], $this->queuedKinds());
    }
```

Copy the `LinkCardContent` constructor arguments and the quote body from `testAMastodonCardCardsItsLinkWithoutAPageFetch` and `testANativeMastodonQuoteSettlesAsAQuotedPostWithoutAnyEnrichment`, so the card and the quote are known to settle. The tests above only add the replied guid to those setups.

- [ ] **Step 2: Write the failing refresh test**

In `FeedOutcomePersisterTimelineTest`, after `testARootAndItsRepliesOnOnePageBecomeOneThreadedEntry`:

```php
    public function testARootWhoseRepliesAreNotOnThePageWaitsForItsThread(): void
    {
        $feed = $this->feed(self::HOME, SourceFormat::BLUESKY_API);
        $root = self::itemFields(self::ROOT);
        $root['post']['replyCount'] = 1;

        $this->persister()->storeTimeline($feed, self::page([JsonNodeModel::of($root)]), $this->clock->now());

        $queued = $this->entityManager->getRepository(ShareEnrichment::class)->findAll();
        self::assertCount(1, $queued);
        self::assertSame(self::ROOT, $queued[0]->getEntry()->getGuid());
        self::assertSame(ShareEnrichmentKind::SelfThread, $queued[0]->getKind());
    }
```

Add `use App\Enum\ShareEnrichmentKind;`. PHPStan level max sees `itemFields()` as `array<string, mixed>`, so `$root['post']['replyCount']` may not type-check. If it doesn't, give `itemFields()` a `?int $replyCount = null` parameter that adds the key to the post when it is set. A private test helper is no domain flag.

- [ ] **Step 3: Run them to verify they fail**

Run: `php bin/phpunit tests/Service/Completion/ShareEnrichmentQueueTest.php tests/Service/Refresh/FeedOutcomePersisterTimelineTest.php`
Expected: the five new queue tests and the new refresh test FAIL. They show `LinkCards`, or no row, where `SelfThread` is expected. Every old test passes.

- [ ] **Step 4: Implement `KnownShareSettler`**

The settler no longer writes rows. It reports what the entry still waits for:

```php
/** Settles a new entry whose network already answered its embed or its card, as the later passes would have. */
final readonly class KnownShareSettler
{
    public function __construct(
        private EntryEmbedWriterInterface $embedWriter,
        private EntryCardWriter $cardWriter,
        private LoggerInterface $logger,
    ) {
    }

    /** Ends where PostEnricher::settle() leaves an RSS Bluesky post: one without an embed or a link shares nothing. */
    public function settleEmbedded(Entry $entry, JsonNodeModel $post): ?ShareEnrichmentKind
    {
        $outcome = $this->filled($entry, $post);
        if ($outcome === FillOutcome::Unfilled) {
            $entry->setShareKind(null);

            return null;
        }

        return $outcome === FillOutcome::AwaitingLinkCards ? ShareEnrichmentKind::LinkCards : null;
    }

    /** Takes a classified link share and its card under a posted link: only the other links still wait for a page. */
    public function settleCarded(Entry $entry, LinkCardContent $card): ?ShareEnrichmentKind
    {
        $this->cardWriter->write($entry, new EntryCardsModel([], [new PageCardModel($card, null)]));

        return ShareTargets::uncardedOf($entry, [$card->url]) !== [] ? ShareEnrichmentKind::LinkCards : null;
    }
```

`settleQuoted()` and `filled()` are unchanged. Delete `awaitLinkCards()`, the `EntityManagerInterface` and `ShareEnrichment` imports, and the `$entityManager` property.

- [ ] **Step 5: Implement `ShareEnrichmentQueue`**

Replace `queueOne()` and `queueCreated()` with one decision and one write:

```php
    private function queueOne(Entry $entry, KnownSharesModel $knownShares, \DateTimeImmutable $queuedAt): void
    {
        $awaited = $knownShares->hasRepliesFor($entry->getGuid())
            ? $this->settledAwaitingThread($entry, $knownShares)
            : $this->awaitedKindOf($entry, $knownShares);
        if ($awaited !== null) {
            $this->entityManager->persist(new ShareEnrichment($entry, $awaited, $queuedAt));
        }
    }

    /** The self-thread pass hands a root that still links an uncarded page on to the link-card pass. */
    private function settledAwaitingThread(Entry $entry, KnownSharesModel $knownShares): ShareEnrichmentKind
    {
        $this->awaitedKindOf($entry, $knownShares);

        return ShareEnrichmentKind::SelfThread;
    }

    private function awaitedKindOf(Entry $entry, KnownSharesModel $knownShares): ?ShareEnrichmentKind
    {
        $post = $knownShares->blueskyPostFor($entry->getGuid());
        if ($post !== null) {
            return $this->knownShareSettler->settleEmbedded($entry, $post);
        }
        $this->classifier->classify($entry);
        $quote = self::postedQuoteOf($entry, $knownShares);
        if ($quote !== null) {
            $this->knownShareSettler->settleQuoted($entry, $quote);

            return null;
        }
        $card = self::postedCardOf($entry, $knownShares);
        if ($card !== null) {
            return $this->knownShareSettler->settleCarded($entry, $card);
        }

        return $this->awaitedOfCreated($entry);
    }

    private function awaitedOfCreated(Entry $entry): ?ShareEnrichmentKind
    {
        if ($this->pendingEmbed->awaitsEmbed($entry)) {
            return ShareEnrichmentKind::AppView;
        }
        if ($this->pendingThread->awaitsThread($entry)) {
            return ShareEnrichmentKind::SelfThread;
        }

        return $entry->getShareKind() === ShareKind::Link ? ShareEnrichmentKind::LinkCards : null;
    }
```

`$queuedAt` now reaches only `queueOne()`. Drop it from the settler calls. Known replies only ever come from a page that answered the post itself, so the `AppView` branch is never reached for them. Pin no test on that. If PHPMD or the ternary reads poorly, a plain `if` is fine. The rule to keep: the settling always runs, and known replies replace whatever kind it returned with `SelfThread`.

- [ ] **Step 6: Follow the constructor in the test support**

`backend/tests/Support/PostEnrichers.php::queue()`:

```php
            new KnownShareSettler(
                self::embedWriter($entityManager),
                ShareLinkCardsWiring::cardWriter($entityManager),
                new NullLogger(),
            ),
```

- [ ] **Step 7: Run them to verify they pass**

Run: `php bin/phpunit tests/Service/Completion tests/Service/Refresh tests/Service/Social tests/Service/Bluesky`
Expected: PASS.

- [ ] **Step 8: Commit**

```bash
git add backend/src/Service/Completion backend/tests/Support/PostEnrichers.php backend/tests/Service/Completion backend/tests/Service/Refresh/FeedOutcomePersisterTimelineTest.php
git commit -m "fix(#1727): a timeline post the network counts replies for is queued for its self-thread"
```

---

### Task 3: Gates, live check, PR

- [ ] **Step 1:** From `backend/`, run `composer check` and `composer md`. Both must be clean on every touched `src` file.
- [ ] **Step 2:** Run PhpStorm inspections (`mcp__phpstorm__lint_files`) on the changed PHP files. ERROR and WARNING block.
- [ ] **Step 3:** Run `composer test:parallel` (SQLite) and `docker compose exec php composer test` (MySQL) in parallel. Both must pass. Run `composer infection:diff` and kill every escaped mutant with a test. Never lower `minMsi`.
- [ ] **Step 4: Live.** Confirm the Docker php and worker containers serve this branch (`verify-containers-are-current`). Restart the worker, which holds stale code, and clear the dev cache. Refresh subscription 1904 (Discover) at `http://localhost:4200/?subscription=1904` until a new Techpresso post arrives. In the DB, that entry's `share_enrichment` row is `self_thread` until the completion runs, then the entry has `thread_post` rows. In the list, the post draws as a thread card (mockup E). Existing entries don't backfill, so judge only by posts ingested after the change. Scan today's `backend/var/log/dev-*.log` with `jq` for errors.
- [ ] **Step 5:** Push the branch and open a PR into `develop` with the body `Closes #1727`. Describe the cause (#1675 folds only from the page), the fix (known replies queue the existing self-thread pass, as RSS does), and the cost (one thread read per replied timeline post, bounded per pass).
