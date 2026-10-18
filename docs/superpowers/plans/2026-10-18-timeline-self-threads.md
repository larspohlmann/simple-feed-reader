# Timeline Self-Threads Implementation Plan (#1675)

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Social-account timelines (Bluesky home/list/feed/author, Mastodon home/list) fold an author's self-replies into the root entry as `thread_post` rows, as #1668 does for RSS, and the article's comments section stops hiding the folded replies.

**Architecture:** The timeline page mappers (`BlueskyTimelinePage`, `MastodonTimelinePage`) no longer turn a self-reply straight into an entry. They hand it on as a `TimelineSelfReplyModel` with its root guid, its parent's URL, its fallback entry and its post view. The refresh plans a fold before ingest. A new Social service `TimelineSelfThreads` looks up the stored roots and their stored thread positions, and `SelfThreadPlacement` (a Pass object) puts each reply on its root's chain. A reply whose root is neither on the page nor stored is ingested as its own entry, as it is today. After ingest, the placed replies are written through `SelfThreadFolder::foldAt()`. Each network's `ReplyThreadSourceInterface::selfReplyOf()` renders the reply into the same `EntryCommentModel` the RSS fold stores. No network call is added.

**Tech Stack:** Symfony 7.4, PHP 8.4, Doctrine ORM, PHPUnit 12.

**Spec:** GitHub issue #1675 (the brief), plus the user's ruling in the session: the comments section must show the folded replies again.

## Global Constraints

- CLAUDE.md applies in full: Clean Code names, `final readonly`, one role per folder (Model/ Pass/ Support/ Factory/), queries only in `src/Repository`, no boolean flag parameters, three parameters at most, default to no comment.
- No Service-module cycle. Refresh → Social already exists. Social must never import `App\Service\Refresh\*`.
- The chain rules are `SelfReplyChain`'s: an unbroken chain by the author, `SelfReplyChain::MAX_REPLIES` (3), and the oldest reply wins a position.
- No backfill. Entries that already exist stay as they are and are never deleted.
- `FeedOutcomePersister` keeps at most 9 constructor parameters (PHPMD `ExcessiveParameterList` fires at 10).
- Commits use `type(#1675): …`, with no Claude attribution. The branch is `feature/1675-timeline-self-threads`, never commit to develop.

## Fold rules (what the tests pin)

For each self-reply on a page, oldest `publishedAt` first:

1. **Already stored as an entry** (its guid exists in the feed, from before this feature): it is skipped. It is neither folded nor re-ingested.
2. **Root unknown** (the root guid is neither a page entry nor a stored entry of the feed): the reply becomes its **own entry** (its fallback `ParsedEntryModel`).
3. **Root known**: the thread's positions are seeded with `rootUrl => 0` plus every stored `thread_post` `url => position`. Placement then runs to a fixpoint:
   - A reply whose own URL is already in the positions was folded on an earlier refresh. It is skipped.
   - A reply whose `parentUrl` is in the positions gets `position = parent + 1`, and its URL is recorded at that position. It is **placed** only if `position <= 3` and no other reply holds that position. Otherwise it is **dropped**: it is deeper than 3, or a second branch, and it shows in the comments, as on RSS.
   - A reply whose parent never resolves (a gap, or a parent that is a stored pre-feature entry) becomes its **own entry**.
4. Placed replies are folded after ingest into the root entry. That is the entry just created from the page, or the stored one.

## File map

| File | Change |
|---|---|
| `backend/src/Service/Social/Support/FoldedReplies.php` + test | **Delete** (comments show folded replies again) |
| `backend/src/Service/Social/SocialRepliesSource.php` | Drop the `FoldedReplies` filter and the `ThreadPostRepository` dependency |
| `backend/src/Repository/ThreadPostRepository.php` | Replace `urlsOf()` with `positionsByEntryIds()` |
| `backend/src/Service/Social/Model/TimelineSelfReplyModel.php` | **Create**: a self-reply as a timeline answered it |
| `backend/src/Service/Social/Model/PlacedSelfReplyModel.php` | **Create**: root guid + position + post view |
| `backend/src/Service/Social/Model/TimelineThreadsModel.php` | **Create**: the placed replies of one page; `none()` |
| `backend/src/Service/Social/Model/TimelinePlanModel.php` | **Create**: the page's entries to ingest + its threads |
| `backend/src/Service/Social/Model/TimelinePageModel.php` | Add `selfReplies` + `withSelfReplies()` |
| `backend/src/Service/Social/Support/BlueskyTimelinePage.php` | Classify self-replies |
| `backend/src/Service/Social/Support/MastodonTimelinePage.php` | Classify self-replies whose chain to the root is on the page |
| `backend/src/Service/Social/Pass/SelfThreadPlacement.php` | **Create**: rule 3 for one root |
| `backend/src/Service/Social/SelfThreadFolder.php` | Add `foldAt()`; `fold()` delegates |
| `backend/src/Service/Social/ReplyThreadSource/*` | Add `selfReplyOf(JsonNodeModel): EntryCommentModel` |
| `backend/src/Service/Social/BlueskyThreadReplies.php`, `MastodonContextReplies.php` | Public `selfReplyOf()` |
| `backend/src/Service/Social/TimelineSelfThreads.php` | **Create**: `plan()` + `fold()` |
| `backend/src/Service/Refresh/IncomingEntries.php` | **Create**: builds a timeline's `IncomingFeedModel` and settles created entries (enrichment queue + fold) |
| `backend/src/Service/Refresh/Model/IncomingFeedModel.php` | Carries `TimelineThreadsModel $threads` |
| `backend/src/Service/Refresh/FeedOutcomePersister.php` | `storeParsed()` → `storeTimeline(Feed, TimelinePageModel, now)`; `ShareEnrichmentQueue` → `IncomingEntries` |
| `backend/src/Service/Refresh/Pass/PassOutcomeStore.php` | Calls `storeTimeline()` |
| `backend/tests/Support/FeedOutcomePersisters.php`, `FakeReplyThreadSource.php` | Follow the signatures |

---

### Task 1: The comments section shows folded replies again

**Files:**
- Delete: `backend/src/Service/Social/Support/FoldedReplies.php`, `backend/tests/Service/Social/Support/FoldedRepliesTest.php`
- Modify: `backend/src/Service/Social/SocialRepliesSource.php` (`read()`, constructor, imports)
- Modify: `backend/tests/Service/Social/SocialRepliesSourceTest.php` (the `testAFoldedReplyIsLeftOutButItsChildrenStay` test and `source()`)

- [ ] **Step 1: Turn the existing test around (failing).** Rename it to `testAFoldedReplyStillShowsWithItsChildren` and change only the assertion:

```php
        self::assertSame(['folded', 'child', 'other'], array_map(
            static fn (EntryCommentModel $comment): string => $comment->html,
            $page->comments,
        ));
```

(`ReplyThreadOrder` sorts by thread time and nests children under their parent. If the real order differs, assert on the set and pin the order the order service gives. Read `ReplyThreadOrderTest` first and do not guess.)

- [ ] **Step 2:** Run `php bin/phpunit --filter SocialRepliesSourceTest` and expect a FAIL, because `folded` is missing.
- [ ] **Step 3: Implement.** In `SocialRepliesSource::read()`:

```php
    public function read(Entry $entry, string $answer): CommentsPageModel
    {
        $post = self::postOf($entry);

        return $this->order->pageOf($this->threadOn($post->network)->repliesIn($post->address, $answer));
    }
```

Remove the `ThreadPostRepository $threadPosts` constructor parameter and the `FoldedReplies` / `ThreadPostRepository` imports. Delete `FoldedReplies.php` and `FoldedRepliesTest.php`. In the test's `source()`, drop the `$threadPosts` argument and its two lines. The test still persists a `ThreadPost`, which is fine and proves the row no longer filters anything. Leave `ThreadPostRepository::urlsOf()` alone; Task 4 replaces it.
- [ ] **Step 4:** Run `php bin/phpunit --filter 'SocialRepliesSourceTest|Comments'` and expect a PASS.
- [ ] **Step 5: Commit** `fix(#1675): the comments show the author's folded replies again`

---

### Task 2: Timeline pages hand self-replies on instead of making them entries

**Files:**
- Create: `backend/src/Service/Social/Model/TimelineSelfReplyModel.php`
- Modify: `backend/src/Service/Social/Model/TimelinePageModel.php`
- Modify: `backend/src/Service/Social/Support/BlueskyTimelinePage.php`, `MastodonTimelinePage.php`
- Test: `backend/tests/Service/Social/Support/BlueskyTimelinePageTest.php`, `MastodonTimelinePageTest.php`

**Interfaces (produced):**

```php
namespace App\Service\Social\Model;

use App\Service\Bluesky\Model\JsonNodeModel;
use App\Service\Parser\Model\ParsedEntryModel;

/** An author's reply to their own post, as a timeline answered it: the entry it becomes when its root is unknown. */
final readonly class TimelineSelfReplyModel
{
    public function __construct(
        public string $rootGuid,
        public string $parentUrl,
        public ParsedEntryModel $entry,
        public JsonNodeModel $post,
    ) {
    }
}
```

`TimelinePageModel` gains `/** @var list<TimelineSelfReplyModel> */ public array $selfReplies = []` as the last constructor parameter, plus:

```php
    /** @param list<TimelineSelfReplyModel> $selfReplies */
    public function withSelfReplies(array $selfReplies): self
    {
        return new self($this->parsed, $this->knownShares, $this->newestId, $selfReplies);
    }
```

- [ ] **Step 1: Failing Bluesky tests.** Add to `BlueskyTimelinePageTest`. The `item()` helper already exists; add a `reply()` helper:

```php
    private const string ROOT_URI = 'at://did:plc:bob/app.bsky.feed.post/3root';
    private const string PARENT_URI = 'at://did:plc:bob/app.bsky.feed.post/3parent';

    public function testAnAuthorsReplyToTheirOwnThreadIsHandedOnNotListed(): void
    {
        $page = BlueskyTimelinePage::of([self::item(['reply' => self::reply(self::BOB, self::BOB, self::PARENT_URI)])], self::ALICE);

        self::assertSame([], $page->parsed->entries);
        $selfReply = $page->selfReplies[0];
        self::assertSame(self::ROOT_URI, $selfReply->rootGuid);
        self::assertSame('https://bsky.app/profile/did:plc:bob/post/3parent', $selfReply->parentUrl);
        self::assertSame(self::POST_URI, $selfReply->entry->guid);
        self::assertSame(self::POST_URI, $selfReply->post->string('uri'));
        self::assertSame(self::POST_URI, $page->knownShares->blueskyPostFor(self::POST_URI)?->string('uri'));
    }

    public function testTheAuthorsOwnFeedHandsItsSelfRepliesOnToo(): void
    {
        $page = BlueskyTimelinePage::ofAuthor([self::item(['reply' => self::reply(self::BOB, self::BOB, self::ROOT_URI)])], self::BOB);

        self::assertSame([], $page->parsed->entries);
        self::assertSame('https://bsky.app/profile/did:plc:bob/post/3root', $page->selfReplies[0]->parentUrl);
    }

    /** @param array<string, mixed> $item */
    #[DataProvider('notSelfReplies')]
    public function testAnythingButASelfReplyStaysAnEntry(array $item): void
    {
        $page = BlueskyTimelinePage::of([self::item($item)], self::BOB);

        self::assertCount(1, $page->parsed->entries);
        self::assertSame([], $page->selfReplies);
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function notSelfReplies(): iterable
    {
        yield 'a reply to someone else' => [['reply' => self::reply(self::ALICE, self::ALICE, self::PARENT_URI)]];
        yield 'a self-reply under someone else\'s root' => [['reply' => self::reply(self::ALICE, self::BOB, self::PARENT_URI)]];
        yield 'a repost of a self-reply' => [[
            'reply' => self::reply(self::BOB, self::BOB, self::PARENT_URI),
            'reason' => ['$type' => 'app.bsky.feed.defs#reasonRepost', 'by' => ['did' => self::ALICE], 'indexedAt' => '2026-10-12T09:30:00Z'],
        ]];
        yield 'a reply without its root' => [['reply' => ['parent' => ['uri' => self::PARENT_URI, 'author' => ['did' => self::BOB]]]]];
    }

    /** @return array<string, mixed> */
    private static function reply(string $rootAuthor, string $parentAuthor, string $parentUri): array
    {
        return [
            'root' => ['uri' => self::ROOT_URI, 'author' => ['did' => $rootAuthor]],
            'parent' => ['uri' => $parentUri, 'author' => ['did' => $parentAuthor]],
        ];
    }
```

`isShown` keeps the ALICE→ALICE case visible when the account is BOB only if ALICE is followed. In the "reply to someone else" case, pass `'viewer' => ['following' => 'at://x']` on the parent author so that it is shown and lands as an entry. Adjust `reply()` to accept that, or inline the array for that case.

- [ ] **Step 2:** Run `php bin/phpunit --filter BlueskyTimelinePageTest` and expect a FAIL (`selfReplies` is undefined).
- [ ] **Step 3: Implement Bluesky.** In `pageOf()`:

```php
        $entries = [];
        $selfReplies = [];
        $posts = [];
        foreach ($items as $item) {
            $entry = $isShown($item) ? self::entryOf($item) : null;
            if (null === $entry) {
                continue;
            }
            $posts[$entry->guid] = $item->node('post');
            $selfReply = self::selfReplyOf($item, $entry);
            if (null === $selfReply) {
                $entries[] = $entry;
                continue;
            }
            $selfReplies[] = $selfReply;
        }

        return TimelinePageModel::of($entries, KnownSharesModel::ofBlueskyPosts($posts), null)
            ->withSelfReplies($selfReplies);
```

```php
    private static function selfReplyOf(JsonNodeModel $item, ParsedEntryModel $entry): ?TimelineSelfReplyModel
    {
        $reply = $item->node('reply');
        $rootUri = $reply->node('root')->string('uri');
        $parentUrl = AtPostUri::webUrl($reply->node('parent')->string('uri') ?? '');
        if (null === $rootUri || null === $parentUrl || !self::continuesOwnThread($item)) {
            return null;
        }

        return new TimelineSelfReplyModel($rootUri, $parentUrl, $entry, $item->node('post'));
    }

    /** A reply to its author's own post, under a root they wrote, and not a repost of one. */
    private static function continuesOwnThread(JsonNodeModel $item): bool
    {
        $author = $item->node('post')->node('author')->string('did');
        $reply = $item->node('reply');

        return null !== $author
            && self::REPOST !== $item->node('reason')->type()
            && $author === $reply->node('parent')->node('author')->string('did')
            && $author === $reply->node('root')->node('author')->string('did');
    }
```

Also update the class docblock: `… as entries and self-replies; …`.

- [ ] **Step 4: Failing Mastodon tests.** A Mastodon status is a self-reply when `in_reply_to_account_id === account.id` and its chain up to a non-reply status lies on the page. The `statusFields()` helper needs `'id'`, `'in_reply_to_id' => null`, `'in_reply_to_account_id' => null`, and `'account' => ['id' => '7', …]`.

```php
    public function testAThreadOnThePageFoldsIntoItsRoot(): void
    {
        $root = self::timelineStatus(['id' => '100', 'uri' => 'https://mastodon.example/users/bob/statuses/100', 'url' => 'https://mastodon.example/@bob/100']);
        $first = self::selfReply('101', '100');
        $second = self::selfReply('102', '101');

        $page = MastodonTimelinePage::of([$second, $first, $root]);

        self::assertSame(['https://mastodon.example/users/bob/statuses/100'], array_map(
            static fn (ParsedEntryModel $entry): string => $entry->guid,
            $page->parsed->entries,
        ));
        self::assertSame(
            [['https://mastodon.example/users/bob/statuses/100', 'https://mastodon.example/@bob/101'],
             ['https://mastodon.example/users/bob/statuses/100', 'https://mastodon.example/@bob/100']],
            array_map(static fn (TimelineSelfReplyModel $reply): array => [$reply->rootGuid, $reply->parentUrl], $page->selfReplies),
        );
    }

    public function testASelfReplyWhoseParentIsNotOnThePageStaysAnEntry(): void
    {
        $page = MastodonTimelinePage::of([self::selfReply('101', '99')]);

        self::assertCount(1, $page->parsed->entries);
        self::assertSame([], $page->selfReplies);
    }

    public function testAReplyToSomeoneElseStaysAnEntry(): void
    {
        $root = self::timelineStatus(['id' => '100']);
        $reply = self::timelineStatus(['id' => '101', 'uri' => 'https://mastodon.example/users/bob/statuses/101', 'in_reply_to_id' => '100', 'in_reply_to_account_id' => '8']);

        self::assertSame([], MastodonTimelinePage::of([$reply, $root])->selfReplies);
    }

    private static function selfReply(string $id, string $parentId): JsonNodeModel
    {
        return self::timelineStatus([
            'id' => $id,
            'uri' => 'https://mastodon.example/users/bob/statuses/' . $id,
            'url' => 'https://mastodon.example/@bob/' . $id,
            'in_reply_to_id' => $parentId,
            'in_reply_to_account_id' => '7',
        ]);
    }
```

- [ ] **Step 5:** Run `php bin/phpunit --filter MastodonTimelinePageTest` and expect a FAIL.
- [ ] **Step 6: Implement Mastodon.** In `of()`, index the non-boost statuses by id first, then classify each entry like Bluesky:

```php
        $byId = self::ownStatusesById($statuses);
        // … in the loop, after $postsByGuid[...]:
        $selfReply = self::selfReplyOf($status, $entry, $byId);
```

```php
    /** @param array<string, JsonNodeModel> $byId */
    private static function selfReplyOf(JsonNodeModel $status, ParsedEntryModel $entry, array $byId): ?TimelineSelfReplyModel
    {
        $parent = self::ownParentOf($status, $byId);
        if (null === $parent) {
            return null;
        }
        $root = self::rootOf($parent, $byId);
        $rootUri = $root->string('uri');
        $parentUrl = self::urlOf($parent);
        if (null === $rootUri || null === $parentUrl || null !== $root->string('in_reply_to_id')) {
            return null;
        }

        return new TimelineSelfReplyModel($rootUri, $parentUrl, $entry, $status);
    }

    /** @param array<string, JsonNodeModel> $byId */
    private static function rootOf(JsonNodeModel $status, array $byId): JsonNodeModel
    {
        $parent = self::ownParentOf($status, $byId);

        return null === $parent ? $status : self::rootOf($parent, $byId);
    }

    /**
     * The post a status answers when its author answers themself and the post is on the page.
     *
     * @param array<string, JsonNodeModel> $byId
     */
    private static function ownParentOf(JsonNodeModel $status, array $byId): ?JsonNodeModel
    {
        $accountId = $status->node('account')->string('id');
        if (null === $accountId || $accountId !== $status->string('in_reply_to_account_id')) {
            return null;
        }

        return $byId[$status->string('in_reply_to_id') ?? ''] ?? null;
    }
```

Here `ownStatusesById()` maps `id => status` for every non-boost status, and `urlOf(JsonNodeModel $post): ?string` is extracted from `entryOf()` (`AbsoluteHttpUrl::orNull($post->string('url')) ?? AbsoluteHttpUrl::orNull($post->string('uri'))`), which `entryOf()` reuses. Ids are unique per page, so `rootOf()` terminates. Boosts are never self-replies, because `selfReplyOf` is only asked about a status that is not a boost: guard with `self::isBoost($status)` at the top. Update the class docblock to say a thread on the page is handed on.
- [ ] **Step 7:** Run `php bin/phpunit --filter 'TimelinePageTest'` and expect a PASS.
- [ ] **Step 8: Commit** `feat(#1675): timeline pages hand an author's self-replies on`

Note: until Task 5 lands, self-replies are dropped on refresh. The branch is not shippable between commits, and that is acceptable inside the PR.

---

### Task 3: `SelfThreadPlacement` — placing replies on one root's chain

**Files:**
- Create: `backend/src/Service/Social/Pass/SelfThreadPlacement.php`, `backend/src/Service/Social/Model/PlacedSelfReplyModel.php`
- Test: `backend/tests/Service/Social/Pass/SelfThreadPlacementTest.php`

**Interfaces (produced):**

```php
final readonly class PlacedSelfReplyModel
{
    public function __construct(public string $rootGuid, public int $position, public JsonNodeModel $post)
    {
    }
}

/** Puts a root's self-replies on its chain: one per position, at most SelfReplyChain::MAX_REPLIES. */
final class SelfThreadPlacement
{
    /** @param array<string, int> $positions url => position, the root at 0 and its stored thread posts */
    public function __construct(private readonly string $rootGuid, private array $positions);

    /** @param list<TimelineSelfReplyModel> $replies oldest first */
    public function place(array $replies): void;

    /** @return list<PlacedSelfReplyModel> */
    public function placed(): array;

    /** @return list<ParsedEntryModel> the replies whose parent never joined the chain: they stay entries */
    public function unplaced(): array;
}
```

- [ ] **Step 1: Failing tests** (a plain `TestCase`). Build replies with a helper `reply(string $url, string $parentUrl): TimelineSelfReplyModel`, using a `ParsedEntryModel` with that URL as guid and URL, and `JsonNodeModel::of(['uri' => $url])` as the post. Cases:
  - `testAChainFromTheRootTakesPositionsOneToThree`: root `r` at 0; replies `a←r`, `b←a`, `c←b` placed `[1,2,3]`.
  - `testAFourthReplyIsDroppedNotListed`: `d←c` is neither placed nor unplaced.
  - `testRepliesArriveInAnyOrder`: given `[c, a, b]`, positions are still 1, 2, 3 (the fixpoint).
  - `testAStoredThreadPostIsContinued`: positions `{r:0, a:1}`; reply `b←a` is placed at 2; reply `a` (URL already known) is skipped.
  - `testASecondBranchIsDropped`: `a←r`, `x←r` with `a` first: only `a` is placed, `x` is in neither list, and a child `y←x` is in neither list.
  - `testAReplyWhoseParentNeverJoinsStaysAnEntry`: `b←a` with `a` unknown: unplaced is `[b's entry]`.

Assert `placed()` as `[[rootGuid, position, post uri], …]` and `unplaced()` as guids.
- [ ] **Step 2:** Run `php bin/phpunit --filter SelfThreadPlacementTest` and expect a FAIL.
- [ ] **Step 3: Implement.**

```php
    public function place(array $replies): void
    {
        $pending = array_filter(
            $replies,
            fn (TimelineSelfReplyModel $reply): bool => !isset($this->positions[$reply->entry->url]),
        );
        do {
            $joined = array_filter(
                $pending,
                fn (TimelineSelfReplyModel $reply): bool => isset($this->positions[$reply->parentUrl]),
            );
            array_walk($joined, $this->join(...));
            $pending = array_diff_key($pending, $joined);
        } while ($joined !== []);
        $this->unplaced = array_values(array_map(
            static fn (TimelineSelfReplyModel $reply): ParsedEntryModel => $reply->entry,
            $pending,
        ));
    }

    /** Every joined reply holds its position, so its own replies resolve too, even when it is dropped. */
    private function join(TimelineSelfReplyModel $reply): void
    {
        $position = $this->positions[$reply->parentUrl] + 1;
        $this->positions[$reply->entry->url] = $position;
        if ($position > SelfReplyChain::MAX_REPLIES || isset($this->taken[$position])) {
            return;
        }
        $this->taken[$position] = true;
        $this->placed[] = new PlacedSelfReplyModel($this->rootGuid, $position, $reply->post);
    }
```

In the constructor, seed `$this->taken` from the stored positions greater than 0. `array_filter` keeps keys, so `array_diff_key` works on the input list keys. The `array_walk` callback gets `(value, key)`. Use a `foreach` if PHPStan objects to the second argument.
- [ ] **Step 4:** Run `php bin/phpunit --filter SelfThreadPlacementTest` and expect a PASS.
- [ ] **Step 5: Commit** `feat(#1675): place a root's self-replies on its chain`

---

### Task 4: Fold building blocks — repository positions, `foldAt`, `selfReplyOf`

**Files:**
- Modify: `backend/src/Repository/ThreadPostRepository.php` (replace `urlsOf()` with `positionsByEntryIds()`), `backend/tests/Repository/ThreadPostRepositoryTest.php`
- Modify: `backend/src/Service/Social/SelfThreadFolder.php`, `backend/tests/Service/Social/SelfThreadFolderTest.php`
- Modify: `backend/src/Service/Social/ReplyThreadSource/ReplyThreadSourceInterface.php`, `BlueskyReplyThreadSource.php`, `MastodonReplyThreadSource.php`, `backend/tests/Support/FakeReplyThreadSource.php`
- Modify: `backend/src/Service/Social/BlueskyThreadReplies.php`, `MastodonContextReplies.php` and their tests

**Interfaces (produced):**
- `ThreadPostRepository::positionsByEntryIds(list<int> $entryIds): array<int, array<string, int>>` gives, per entry id, `url => position` for the posts with a URL.
- `SelfThreadFolder::foldAt(Entry $entry, int $position, EntryCommentModel $reply): void`. A reply with a link card makes an unshared entry (`shareKind` null) a `ShareKind::Link`, exactly as `fold()` did.
- `ReplyThreadSourceInterface::selfReplyOf(JsonNodeModel $post): EntryCommentModel` returns "The author's own reply, from a timeline's post view, as a thread fold stores it." Both implementations delegate to `BlueskyThreadReplies::selfReplyOf()` / `MastodonContextReplies::selfReplyOf()`.

- [ ] **Step 1: Failing tests.**
  - `ThreadPostRepositoryTest`: replace the `urlsOf` assertion with `positionsByEntryIds([$id])` equals `[$id => ['https://x/1' => 1, 'https://x/2' => 2]]`. `[]` gives `[]`, and a post with a null URL is absent.
  - `SelfThreadFolderTest::testFoldAtAppendsAtTheGivenPosition`: the entry has a stored post at 1; `foldAt($entry, 2, reply('<p>two</p>', $card))` and flush. `byEntryIds` gives html `['…', '<p>two</p>']`, the card sits on post 2, and `shareKind` is Link.
  - `BlueskyThreadRepliesTest::testASelfReplyRendersLikeItsThreadComment`: use the `self-thread-replies.json` fixture. Take one reply's `post` node and assert that `selfReplyOf($post)` equals the `comment()` of the matching `ReplyNode` from `of()` (same html, url, publishedAt, embeds), with `byEntryAuthor` true.
  - `MastodonContextRepliesTest::testASelfReplyRendersLikeItsThreadComment`: the same, using a descendant from its existing fixture.
- [ ] **Step 2:** Run `php bin/phpunit --filter 'ThreadPostRepositoryTest|SelfThreadFolderTest|BlueskyThreadRepliesTest|MastodonContextRepliesTest'` and expect a FAIL.
- [ ] **Step 3: Implement.**

```php
    // ThreadPostRepository
    /**
     * @param list<int> $entryIds
     *
     * @return array<int, array<string, int>> by entry id: each thread post's url => its position
     */
    public function positionsByEntryIds(array $entryIds): array
    {
        if ($entryIds === []) {
            return [];
        }
        /** @var list<array{entryId: int|string, url: string, position: int|string}> $rows */
        $rows = $this->createQueryBuilder('post')
            ->select('IDENTITY(post.entry) AS entryId', 'post.url', 'post.position')
            ->andWhere('post.entry IN (:entryIds)')
            ->andWhere('post.url IS NOT NULL')
            ->setParameter('entryIds', $entryIds)
            ->getQuery()
            ->getArrayResult();
        $positions = [];
        foreach ($rows as $row) {
            $positions[(int) $row['entryId']][$row['url']] = (int) $row['position'];
        }

        return $positions;
    }
```

```php
    // SelfThreadFolder
    /** @param list<EntryCommentModel> $chain */
    public function fold(Entry $entry, array $chain): void
    {
        foreach ($chain as $index => $reply) {
            $this->foldAt($entry, $index + 1, $reply);
        }
    }

    public function foldAt(Entry $entry, int $position, EntryCommentModel $reply): void
    {
        $post = $this->threadPostFactory->build($entry, $position, $reply);
        $this->entityManager->persist($post);
        $card = $reply->embeds->linkCard;
        if ($card === null) {
            return;
        }
        $this->linkCards->appendToThreadPost($post, $card);
        if ($entry->getShareKind() === null) {
            $entry->setShareKind(ShareKind::Link);
        }
    }
```

Delete `sharesALink()`.

```php
    // BlueskyThreadReplies
    public function selfReplyOf(JsonNodeModel $post): EntryCommentModel
    {
        return $this->commentOf($post, self::publishedAtOf($post), $post->node('author')->string('did'));
    }
```

`MastodonContextReplies`: change the private `commentOf()` third parameter from `MastodonReplyThread $thread` to the account match it needs. Keep `nodesOf()` passing `$thread->isByPostAuthor($status->node('account'))`. Then:

```php
    public function selfReplyOf(JsonNodeModel $status): EntryCommentModel
    {
        return $this->commentOf($status, self::publishedAtOf($status), true);
    }
```

If PHPMD or the review reads `bool $byPostAuthor` as a flag parameter, use two constructors of the comment instead. It is data passed through to `EntryCommentModel`, not a branch, so a bool is acceptable here.

`FakeReplyThreadSource::selfReplyOf()` returns `new EntryCommentModel(new CommentAuthorModel('Fake'), $post->string('uri'), null, '<p>' . $post->string('uri') . '</p>', true)`.
- [ ] **Step 4:** Run the same filter and expect a PASS. Then run `php bin/phpunit --filter 'Social|Comments'` to see nothing else broke.
- [ ] **Step 5: Commit** `feat(#1675): fold a reply at a position and render a timeline's self-reply`

---

### Task 5: Wire it — plan before ingest, fold after

**Files:**
- Create: `backend/src/Service/Social/Model/TimelineThreadsModel.php`, `TimelinePlanModel.php`
- Create: `backend/src/Service/Social/TimelineSelfThreads.php`
- Create: `backend/src/Service/Refresh/IncomingEntries.php`
- Modify: `backend/src/Service/Refresh/Model/IncomingFeedModel.php`, `FeedOutcomePersister.php`, `Pass/PassOutcomeStore.php`
- Modify: `backend/tests/Support/FeedOutcomePersisters.php`, `backend/tests/Service/Refresh/FeedOutcomePersisterTimelineTest.php`
- Test: `backend/tests/Service/Social/TimelineSelfThreadsTest.php`, plus new cases in `FeedOutcomePersisterTimelineTest`

**Interfaces (produced):**

```php
final readonly class TimelineThreadsModel
{
    /** @param list<PlacedSelfReplyModel> $placed */
    public function __construct(public array $placed) {}
    public static function none(): self { return new self([]); }
}

final readonly class TimelinePlanModel
{
    /** @param ParsedFeedModel $parsed the page's entries plus the self-replies whose root is unknown */
    public function __construct(public ParsedFeedModel $parsed, public TimelineThreadsModel $threads) {}
}

final readonly class TimelineSelfThreads
{
    public function plan(Feed $feed, TimelinePageModel $page): TimelinePlanModel;
    /** @param list<Entry> $createdEntries persisted, not yet flushed */
    public function fold(Feed $feed, TimelineThreadsModel $threads, array $createdEntries): void;
}

final readonly class IncomingEntries   // App\Service\Refresh
{
    public function ofTimeline(Feed $feed, TimelinePageModel $page): IncomingFeedModel;
    /** @param list<Entry> $createdEntries persisted, not yet flushed: the caller flushes */
    public function settle(Feed $feed, IncomingFeedModel $incoming, array $createdEntries): void;
}

// IncomingFeedModel: + public TimelineThreadsModel $threads (3rd ctor param); parsed() passes TimelineThreadsModel::none()
// FeedOutcomePersister: storeParsed() removed; + storeTimeline(Feed $feed, TimelinePageModel $page, \DateTimeImmutable $now)
```

- [ ] **Step 1: Failing integration tests** in `FeedOutcomePersisterTimelineTest`, which extends `DbTestCase` and uses the real container wiring. Build the pages with the real `BlueskyTimelinePage::of()` from item arrays, using a local `item(uri, ?replyRoot, ?replyParent)` helper modelled on `BlueskyTimelinePageTest::item()`. All posts are by `did:plc:bob`, the account is `did:plc:alice`, and the feed is `social:bluesky:1:home`. Every test also calls `appView->knowsFixture` for nothing: these posts carry no embed, so `settleEmbedded` leaves them Unfilled with no call.
  - `testARootAndItsRepliesOnOnePageBecomeOneThreadedEntry`: the page is `[reply2←reply1, reply1←root, root]`. `storeTimeline` creates 1 entry, the root, and its thread positions are `[reply1 url => 1, reply2 url => 2]`, read with `ThreadPostRepository::positionsByEntryIds`.
  - `testRepliesInALaterRefreshFoldIntoTheStoredRoot`: refresh 1 is `[root]`, refresh 2 is `[reply1←root, root]` (the timeline refetches), and refresh 3 is `[reply2←reply1, reply1←root, root]`. The result is still 1 entry and positions 1 and 2, with no duplicate thread post across the three refreshes.
  - `testASelfReplyWhoseRootIsNotStoredStaysItsOwnEntry`: the page is `[reply1←root]` with root absent. It creates 1 entry, `reply1`, and there are no thread posts.
  - `testASelfReplyAlreadyStoredAsAnEntryIsLeftAlone`: first store a page whose `reply1` is a plain entry (build it with `TimelinePageModel::of([reply1Entry], …)`, without self-replies), then store `[reply2←reply1, reply1←root, root]`. The root is created, `reply1` is not folded, and `reply2` becomes its own entry because its parent is a stored entry. That is 3 entries and 0 thread posts.
  - Port the existing `testATimelinePostEndsAsTheSameRssPostDoes…` to `storeTimeline($feed, TimelinePageModel::of($parsed->entries, KnownSharesModel::ofBlueskyPosts([...]), null), now)`.
- [ ] **Step 2:** Run `php bin/phpunit --filter FeedOutcomePersisterTimelineTest` and expect a FAIL, because `storeTimeline` is undefined.
- [ ] **Step 3: Implement `TimelineSelfThreads`.**

```php
final readonly class TimelineSelfThreads
{
    public function __construct(
        private EntryRepository $entries,
        private ThreadPostRepository $threadPosts,
        private SelfThreadFolder $folder,
        #[AutowireLocator('app.social_reply_thread_source', defaultIndexMethod: 'network')]
        private ContainerInterface $threads,
    ) {
    }

    public function plan(Feed $feed, TimelinePageModel $page): TimelinePlanModel
    {
        $storedGuids = $this->storedGuids($feed, $page->selfReplies);
        $unstored = array_filter(
            $page->selfReplies,
            static fn (TimelineSelfReplyModel $reply): bool => !\in_array($reply->entry->guid, $storedGuids, true),
        );
        $placements = $this->placementsOf($feed, $page, $unstored);
        foreach ($placements as $placement) {
            $placement->place(self::repliesUnder($placement->rootGuid(), $unstored));
        }

        return new TimelinePlanModel(
            self::withEntries($page->parsed, [...self::orphansOf($unstored, $placements), ...self::unplacedOf($placements)]),
            new TimelineThreadsModel(array_merge([], ...array_map(
                static fn (SelfThreadPlacement $placement): array => $placement->placed(),
                array_values($placements),
            ))),
        );
    }
```

Supporting pieces (private, each one thing):
  - `storedGuids(Feed, list<TimelineSelfReplyModel>): list<string>` uses `EntryRepository::findByFeedIndexedByGuidHash()` over `(new EntryGuid($guid))->getHash()` of the reply guids, and returns their `getGuid()` values. An empty input makes no query.
  - `placementsOf(Feed, TimelinePageModel, replies): array<string, SelfThreadPlacement>` is keyed by root guid. For the reply root guids, take the stored roots (`findByFeedIndexedByGuidHash`) and seed each with `[$root->getUrl() => 0] + positionsByEntryIds(...)[$root->requireId()]`. A root guid that is not stored but is a page entry (`$page->parsed->entries` by guid) is seeded with `[$entry->url => 0]`. Any other root is not a key.
  - `repliesUnder(rootGuid, replies)` keeps the replies with that root, sorted oldest `entry->publishedAt` first, undated last (the `SelfReplyChain::sortKey` rule).
  - `orphansOf(replies, placements)` returns the entries of the replies whose root is not a key.
  - `withEntries(ParsedFeedModel, list<ParsedEntryModel>)` returns `new ParsedFeedModel($parsed->title, $parsed->siteUrl, …, [...$parsed->entries, ...$extra])`. Read `ParsedFeedModel`'s constructor for the exact fields. If it gains a `withEntries()`, put the method there instead (Parser module, a model method).
  - `SelfThreadPlacement` needs a `rootGuid(): string` accessor, or iterate the keyed array as `$rootGuid => $placement` instead. Prefer the key and do not add the accessor.
  - `TimelinePageModel` without self-replies returns `new TimelinePlanModel($page->parsed, TimelineThreadsModel::none())` early, so RSS-free pages make no query.

```php
    public function fold(Feed $feed, TimelineThreadsModel $threads, array $createdEntries): void
    {
        if ($threads->placed === []) {
            return;
        }
        $roots = $this->rootsOf($feed, $threads, $createdEntries);
        $source = $this->sourceFor($feed);
        foreach ($threads->placed as $reply) {
            $root = $roots[$reply->rootGuid] ?? null;
            if ($root !== null) {
                $this->folder->foldAt($root, $reply->position, $source->selfReplyOf($reply->post));
            }
        }
    }
```

`rootsOf` indexes the created entries by `getGuid()` and adds the stored roots looked up by guid hash. The created ones win, because a root just created has no row yet. `sourceFor` reads `$this->threads->get($feed->getNetwork()?->value ?? '')` and asserts `ReplyThreadSourceInterface`, as `SelfThreadEnricher::threadOn()` does. A root missing at fold time (the ingest deduped it by URL) is skipped: the reply was not ingested either, which is acceptable for that rare case and needs no comment.

- [ ] **Step 4: Implement the Refresh side.**

```php
/** What a refresh does with a page's entries besides storing them: a timeline's threads, then each new entry's share. */
final readonly class IncomingEntries
{
    public function __construct(
        private ShareEnrichmentQueue $enrichmentQueue,
        private TimelineSelfThreads $selfThreads,
    ) {
    }

    public function ofTimeline(Feed $feed, TimelinePageModel $page): IncomingFeedModel
    {
        $plan = $this->selfThreads->plan($feed, $page);

        return new IncomingFeedModel($plan->parsed, $page->knownShares, $plan->threads);
    }

    public function settle(Feed $feed, IncomingFeedModel $incoming, array $createdEntries): void
    {
        $this->enrichmentQueue->queueWithKnownShares($createdEntries, $incoming->knownShares);
        $this->selfThreads->fold($feed, $incoming->threads, $createdEntries);
    }
}
```

The order matters, so `settle()` queues first: `settleEmbedded` may reset a new root's `shareKind` to null, and the fold must then be able to raise it to Link.

In `FeedOutcomePersister`, replace the `ShareEnrichmentQueue $enrichmentQueue` parameter with `IncomingEntries $incomingEntries`. Then:

```php
    /** A social timeline's page: stored through the very step a fetched body takes, its self-replies folded. */
    public function storeTimeline(Feed $feed, TimelinePageModel $page, \DateTimeImmutable $now): FeedRefreshResultModel
    {
        $context = new FeedIngestContext($now, $feed->getLastSuccessfulFetchAt());

        return $this->guarded($feed, fn (): FeedRefreshResultModel => $this->storeIngested(
            $feed,
            $this->incomingEntries->ofTimeline($feed, $page),
            $context,
        ));
    }
```

In `storeIngested()`, `$this->enrichmentQueue->queueWithKnownShares(...)` becomes `$this->incomingEntries->settle($feed, $incoming, $createdEntries);`. `PassOutcomeStore` calls `storeTimeline($feed, $outcome->page, $this->now)` and drops its `IncomingFeedModel` import. `FeedOutcomePersisters` builds `new IncomingEntries(PostEnrichers::queue(...), $this->timelineSelfThreads())`, where the helper takes `TimelineSelfThreads` from `self::getContainer()`. If the support class has no container, add it to the builder the same way `bodyParser` arrives. Grep for every `new FeedOutcomePersister(` and `IncomingFeedModel(` and `storeParsed(` in `src` and `tests`, and update each one.
- [ ] **Step 5:** Run `php bin/phpunit --filter 'FeedOutcomePersister|PassOutcomeStore|RefreshPassRecorder|TimelineRound'` and expect a PASS.
- [ ] **Step 6: `TimelineSelfThreadsTest`** (`DbTestCase`, the service from the container) covers the branches the persister test does not:
  - a Mastodon feed: `plan()` and `fold()` with `MastodonTimelinePage::of([...])` folds `101` and `102` under `100`, and the thread post HTML is the status content;
  - a page without self-replies makes no query (assert the plan's `parsed` is the page's own instance via `assertSame`);
  - a deeper-than-three chain on one page drops the fourth, which is neither an entry nor a thread post.
- [ ] **Step 7:** Run `php bin/phpunit --filter TimelineSelfThreadsTest` and expect a PASS.
- [ ] **Step 8: Commit** `feat(#1675): fold a timeline's self-replies into their root entry`

---

### Task 6: Gates, both legs, live check

- [ ] **Step 1:** In `backend/`, run `composer cs`, `bin/console cache:warmup && composer stan`, `composer md`, and `composer tramp`. Fix every finding in the touched files. PHPMD must be clean for every touched `src` file. The likely hot spots are `TimelineSelfThreads` (class length and coupling) and `MastodonTimelinePage` (method count). Split by responsibility; do not tune thresholds.
- [ ] **Step 2:** Run PhpStorm inspections (`mcp__phpstorm__lint_files`) on the changed PHP files. ERROR and WARNING block.
- [ ] **Step 3:** Run `composer test:parallel` (SQLite) and `docker compose exec php composer test` (MySQL) in parallel. Both must pass. Run `composer infection:diff` and record the MSI. Kill the escaped mutants with tests, never by lowering `minMsi`.
- [ ] **Step 4: Live.** Confirm that the Docker php and worker containers serve the branch code (`verify-containers-are-current`). Restart the worker because it holds stale code, and clear the dev cache. Trigger a refresh of feed 570 (Bluesky Home) and wait for new Techpresso posts. Then check `http://green-tara.local:4200/?subscription=1762`: a new Techpresso post must draw as one thread card (mockup E), with no separate reply entries for it. Then open the post and confirm that its comments section lists the author's replies. Scan today's `backend/var/log/dev-*.log` with `jq` for errors. Existing entries don't backfill, so judge only by posts ingested after the deploy.
- [ ] **Step 5:** Push the branch and open a PR into `develop` with body `Closes #1675`. Describe the fold rules, the Mastodon limit (a self-reply whose parent arrived in an earlier refresh stays its own entry, because the status gives only the parent's instance-local id), and the comments change.

## Known limit, offered as a follow-up

On Mastodon, a self-reply whose parent arrived in an earlier refresh stays its own entry. The status only names the parent by its id on the instance, and stored entries do not keep that id. Bluesky has no such limit, because every timeline item names its root and parent by URI. A follow-up could store the status id or ask the instance for the context. Propose it as a session card, not in this PR.
