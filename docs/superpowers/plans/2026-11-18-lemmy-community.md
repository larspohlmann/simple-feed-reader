# Lemmy community and entry_lemmy_post Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Store each Lemmy post's community and score in a new `entry_lemmy_post` table and show the community as
`c/<name>` next to the poster, except in that community's own feed.

**Architecture:** `EntryLemmyPost` is a side entity owned from its side, like `EntryTranscript`. Ingest (API and RSS)
carries a `PostCommunity` value on `ParsedEntryModel`; a writer next to `EntryTranscriptWriter` creates and refreshes
rows. `EntryListRowEnricher` loads a page's rows in one query and `EntryJson` serves `score` and `community`.

**Tech Stack:** Symfony 8.1 / Doctrine ORM / PHP 8.5, Angular 20 signals.

**Spec:** `docs/superpowers/specs/2026-11-18-lemmy-community-design.md` (issue #2347)

## Global Constraints

- Work in the worktree `.claude/worktrees/2347-lemmy-community-line`, branch `feature/2347-lemmy-community-line`.
- Commit messages: `type(#2347): …`, no attribution lines.
- `comment_count` stays on `entry`; only `score` moves. `discussion_url`, `comments_feed_url`, `comments_load`,
  `self_reply_count` stay.
- The label is `c/<name>` where `<name>` is the part of the identity before `@`.
- `entry_lemmy_post` is never mapped on `Entry` (an inverse one-to-one loads eagerly into every list).
- Every touched `src` file PHPMD-clean; `composer check` green; no new PHPStan ignores or rule edits.
- From this worktree the Docker stack serves the main checkout's code: the MySQL leg, e2e and the live look run only
  once the branch is in the main checkout (Task 5 hands that list to Lars).

## Review Focus

- A community on a non-https instance (`LemmyCommunity::identityOf` null): the row stores the score, community null.
- An RSS post in a remote community (`to` link `/c/technology@lemmy.ml` on `lemmy.world`): identity
  `technology@lemmy.ml`, url `https://lemmy.ml/c/technology`.
- A stored Lemmy entry from before the migration whose score was null has no row: a refresh creates it.
- A post in its own community feed (`https://lemmy.world/feeds/c/technology.xml`, community
  `technology@lemmy.world`) serves `community: null`; the same community from a user feed serves it.
- Deleting an entry (retention) deletes its row (`onDelete: CASCADE`).

---

### Task 1: The `entry_lemmy_post` table, and `score` leaves `entry`

**Files:**
- Create: `backend/src/Entity/PostCommunity.php`, `backend/src/Entity/EntryLemmyPost.php`,
  `backend/src/Repository/EntryLemmyPostRepository.php`, `backend/migrations/Version20261118150000.php`
- Modify: `backend/src/Entity/EntryDiscussion.php` (drop `score`), `backend/src/Entity/Entry.php:282-294`
- Test: `backend/tests/Entity/EntryLemmyPostTest.php`, `backend/tests/Entity/PostCommunityTest.php`,
  `backend/tests/Entity/EntryTest.php`, `backend/tests/Repository/EntryLemmyPostRepositoryTest.php`

**Interfaces:**
- Produces:
  - `final readonly class PostCommunity { public string $identity; public string $url; static of(string $identity, string $url): self; shortName(): string }`
    — `identity` lower-cased `name@host`; `shortName()` is the part before `@`.
  - `EntryLemmyPost::__construct(Entry $entry)`, `recordScore(?int $score): void` (null keeps the stored score),
    `placeIn(PostCommunity $community): void`, `getScore(): ?int`, `getCommunity(): ?PostCommunity`, `getEntry(): Entry`.
    Columns `score` (int, nullable), `community_identity` (string 255, nullable), `community_url` (string 2048,
    nullable); `OneToOne` `entry` with `JoinColumn(nullable: false, unique: true, onDelete: 'CASCADE')`.
  - `EntryLemmyPostRepository::byEntryIds(list<int> $entryIds): array<int, EntryLemmyPost>` keyed by entry id, one
    query, `[]` without a query for `[]`.
  - `Entry::recordDiscussionCounts(DiscussionCounts $counts)` now stores the comment count only (null keeps it);
    `Entry::getCommentCount(): ?int` replaces `getDiscussionCounts()`.

- [ ] **Step 1: Write the failing tests**

```php
// EntryLemmyPostTest
public function testANullScoreKeepsTheStoredOne(): void
{
    $post = new EntryLemmyPost($entry);
    $post->recordScore(12);
    $post->recordScore(null);
    self::assertSame(12, $post->getScore());
}
public function testAPostInACommunityNamesIt(): void
{
    $post->placeIn(PostCommunity::of('technology@lemmy.world', 'https://lemmy.world/c/technology'));
    self::assertEquals(PostCommunity::of('technology@lemmy.world', 'https://lemmy.world/c/technology'), $post->getCommunity());
}
// PostCommunityTest
self::assertSame('technology', PostCommunity::of('technology@lemmy.world', 'https://lemmy.world/c/technology')->shortName());
// EntryTest (replaces the getDiscussionCounts cases)
$entry->recordDiscussionCounts(DiscussionCounts::of(12, 4));
self::assertSame(4, $entry->getCommentCount());
$entry->recordDiscussionCounts(DiscussionCounts::unknown());
self::assertSame(4, $entry->getCommentCount());
// EntryLemmyPostRepositoryTest: two persisted entries, one with a row
self::assertSame([$withRowId], array_keys($repository->byEntryIds([$withRowId, $withoutRowId])));
self::assertSame([], $repository->byEntryIds([]));
// and: removing the entry removes its row (findAll() === [] after remove + flush)
```

- [ ] **Step 2: Run them, expect FAIL** — `php bin/phpunit tests/Entity tests/Repository/EntryLemmyPostRepositoryTest.php`, classes not found.

- [ ] **Step 3: Implement** the value, entity (modelled on `EntryTranscript`), repository, and the `Entry` /
  `EntryDiscussion` change. Fix every other caller of `getDiscussionCounts()` (`EntryJson::discussionOf` reads
  `getCommentCount()` and serves `score => null` until Task 3; adjust `EntryJsonTest`, `IngestedEntryFactoryTest`,
  `RefreshStoredEntriesTest` to assert the comment count only).

- [ ] **Step 4: Write the migration** `Version20261118150000`, description `entry_lemmy_post holds a Lemmy post's
  community and score (#2347)`. Both platforms: create `entry_lemmy_post` with a unique index on `entry_id` and the
  cascading FK; `INSERT INTO entry_lemmy_post (entry_id, score) SELECT id, score FROM entry WHERE score IS NOT NULL`;
  drop `entry.score`. Generate the DDL with `bin/console doctrine:migrations:diff` on each platform and merge it into
  one class that branches on the platform, as `Version20261118120000` does. SQLite: after the `entry` rebuild, add
  every statement of `EntrySearchIndex::install()`. `down()` restores the column and copies the score back.

- [ ] **Step 5: Verify the migration on SQLite** — migrate an empty SQLite database from zero
  (`DATABASE_URL=sqlite:///%kernel.project_dir%/var/migrate_check.db bin/console doctrine:migrations:migrate -n`
  then `doctrine:schema:validate`), expect both mappings and database OK; then confirm the triggers exist:
  `sqlite3 var/migrate_check.db "SELECT name FROM sqlite_master WHERE type='trigger'"` lists the `entry_search`
  triggers. Delete the file afterwards.

- [ ] **Step 6: Run the tests, expect PASS** — `php bin/phpunit tests/Entity tests/Repository tests/Http/EntryJsonTest.php tests/Service/Ingestion`

- [ ] **Step 7: Commit** — `feat(#2347): entry_lemmy_post holds a Lemmy post's score, entry keeps the comment count`

### Task 2: Ingest records the community and score

**Files:**
- Modify: `backend/src/Service/Ingestion/Parser/Model/ParsedEntryModel.php`,
  `backend/src/Service/Social/Social/LemmyPostEntries.php`,
  `backend/src/Service/Ingestion/Ingest/Support/LemmyPostHeader.php`,
  `backend/src/Service/Ingestion/Ingest/Model/LemmyFeedPostModel.php`,
  `backend/src/Service/Ingestion/Ingest/PlatformEntryRule/LemmyEntryRule.php`,
  `backend/src/Service/Ingestion/Ingest/EntryIngestor.php`
- Create: `backend/src/Service/Ingestion/Ingest/EntryLemmyPostWriter.php`,
  `backend/src/Service/Ingestion/Ingest/Support/LemmyCommunityLink.php`
- Test: `tests/Service/Social/Social/LemmyPostEntriesTest.php`, `tests/Service/Ingestion/Ingest/Support/LemmyPostHeaderTest.php`
  (or its existing home), `tests/Service/Ingestion/Ingest/Support/LemmyCommunityLinkTest.php`,
  `tests/Service/Ingestion/Ingest/EntryLemmyPostWriterTest.php`, `tests/Service/Ingestion/Ingest/RefreshStoredEntriesTest.php`

**Interfaces:**
- Consumes: Task 1's `PostCommunity`, `EntryLemmyPost`, `EntryLemmyPostRepository::byEntryIds`.
- Produces:
  - `ParsedEntryModel::$community` (`?PostCommunity`, set in the constructor body to null like `discussionCounts`)
    and `#[\NoDiscard] inCommunity(PostCommunity $community): self`.
  - `LemmyCommunityLink::of(string $href): ?PostCommunity` — `https://lemmy.world/c/technology` →
    `technology@lemmy.world`; `https://lemmy.world/c/Technology@lemmy.ML` → `technology@lemmy.ml` with url
    `https://lemmy.ml/c/technology`; non-https or no `/c/` path → null.
  - `LemmyFeedPostModel::$community` (`?PostCommunity`), filled by `LemmyPostHeader::read` from the `to` link.
  - `EntryLemmyPostWriter::attach(list<array{0: Entry, 1: ParsedEntryModel}> $pairs): void` and
    `refresh(list<array{0: Entry, 1: ParsedEntryModel}> $pairs): void` — a pair is Lemmy when its parse carries a
    community or a non-null score; `refresh` loads existing rows with one `byEntryIds` call and creates missing ones.
    The caller flushes.

- [ ] **Step 1: Write the failing tests**

```php
// LemmyPostEntriesTest: a post view with community {name: 'technology', actor_id: 'https://lemmy.world/c/technology'}
self::assertEquals(PostCommunity::of('technology@lemmy.world', 'https://lemmy.world/c/technology'), $entry->community);
// a community with actor_id 'http://old.example/c/x' → $entry->community === null, $entry->discussionCounts->score still set
// LemmyCommunityLinkTest: the three cases in the Interfaces block
// LemmyPostHeaderTest: header '… to <a href="https://lemmy.world/c/technology">technology</a> …' → community as above
// EntryLemmyPostWriterTest
public function testANewLemmyPostGetsARowWithItsCommunityAndScore(): void   // score 7, community technology@lemmy.world
public function testAnEntryWithoutScoreOrCommunityGetsNoRow(): void          // findAll() === []
// RefreshStoredEntriesTest
public function testARefreshUpdatesTheStoredScore(): void                     // 7 → 9
public function testARefreshCreatesTheRowAStoredLemmyPostLacks(): void
```

- [ ] **Step 2: Run them, expect FAIL** — `php bin/phpunit tests/Service/Social/Social/LemmyPostEntriesTest.php tests/Service/Ingestion`

- [ ] **Step 3: Implement.** `LemmyPostEntries::entryOf` adds `inCommunity()` when `LemmyCommunity::identityOf`
  answers (url = the community's `actor_id`). `LemmyEntryRule::apply` adds it from `$post->community`.
  `EntryIngestor` calls `attach($newPairs)` after the transcript writer and collects the stored pairs in
  `refreshStoredEntries` for one `refresh()` call (the platform rule is already applied there).

- [ ] **Step 4: Run them, expect PASS**, then `php bin/phpunit tests/Service/Ingestion tests/Service/Social`.

- [ ] **Step 5: Commit** — `feat(#2347): ingest records a Lemmy post's community and score in entry_lemmy_post`

### Task 3: The API serves the score and the community

**Files:**
- Create: `backend/src/Repository/EntryListRowLemmy.php`
- Modify: `backend/src/Repository/EntryListRowEnricher.php`, `backend/src/Repository/EntryListRowEnrichment.php`,
  `backend/src/Http/EntryJson.php`
- Test: `tests/Repository/EntryListRowEnricherTest.php`, `tests/Http/EntryJsonTest.php`, plus a functional test
  through the list endpoint and `EntryDetailController` (wherever the existing entry-list functional tests live)

**Interfaces:**
- Consumes: Task 1's repository and entity.
- Produces:
  - `final readonly class EntryListRowLemmy { ?int $score; ?PostCommunity $community; ?VoteScore $myVote }` — it
    replaces `EntryListRowEnrichment::$myVote` with `public ?EntryListRowLemmy $lemmy = null` (null for every
    non-Lemmy entry), so the enrichment keeps its parameter count.
  - JSON: `score` from `lemmy.score`; `myVote` from `lemmy.myVote`; new `community: array{name: string, url: string}|null`
    with `name` = `shortName()`, null when the entry's feed URL is that community's own feed
    (`ProfileFeedUrl::identityOf(SocialNetwork::Lemmy, $feed->getUrl())` equals the identity, case-insensitively).
    Add it to the `EntryRow` phpstan type.

- [ ] **Step 1: Write the failing tests**

```php
// EntryJsonTest
public function testALemmyPostNamesItsCommunity(): void
// row with lemmy (score 5, community technology@lemmy.world) in a Lemmy timeline feed
self::assertSame(['name' => 'technology', 'url' => 'https://lemmy.world/c/technology'], $json['community']);
self::assertSame(5, $json['score']);
public function testAPostInItsOwnCommunityFeedNamesNoCommunity(): void   // feed https://lemmy.world/feeds/c/technology.xml
public function testALemmyUserFeedNamesTheCommunity(): void              // feed https://lemmy.world/feeds/u/alice.xml
public function testAnArticleHasNoCommunity(): void                      // community null, score null
// EntryListRowEnricherTest: a row with an entry_lemmy_post row gets lemmy.score/community; myVote keeps its #2217 cases
```

- [ ] **Step 2: Run them, expect FAIL** — `php bin/phpunit tests/Http/EntryJsonTest.php tests/Repository/EntryListRowEnricherTest.php`

- [ ] **Step 3: Implement.** The enricher gains `EntryLemmyPostRepository`, queried once per page for all entry ids.
  If PHPMD's ExcessiveParameterList fires on the enricher's constructor (it now takes 10), **stop and ask Lars**:
  no count-driven split (CLAUDE.md, #2025).

- [ ] **Step 4: Run them, expect PASS**, then the whole suite: `composer test:parallel`.

- [ ] **Step 5: Commit** — `feat(#2347): entries serve their Lemmy community and read their score from entry_lemmy_post`

### Task 4: The frontend shows `c/<name>`

**Files:**
- Modify: `frontend/src/app/reader/models.ts` (`EntryDto`), `frontend/src/app/reader/entry/entry-source/entry-source.component.{html,ts,scss}`
- Test: `frontend/src/app/reader/entry/entry-source/entry-source.component.spec.ts`; every `EntryDto` fixture
  builder that `tsc` flags gets `community: null`.

**Interfaces:**
- Consumes: JSON `community: {name: string; url: string} | null`.
- Produces: `EntryDto.community: { name: string; url: string } | null`.

- [ ] **Step 1: Write the failing tests**

```ts
it('names the community after the poster in a row, unlinked', () => {
  // entry with poster alice, community {name: 'technology', url: 'https://lemmy.world/c/technology'}, layout 'row'
  expect(host.textContent).toContain('alice · c/technology');
  expect(host.querySelector('a.community')).toBeNull();
});
it('links the community in the article through OwnInstanceLinks', () => {
  // layout 'article'
  expect(host.querySelector('a.community')?.getAttribute('href')).toBe(links.open('https://lemmy.world/c/technology', 'lemmy'));
});
it('names no community when the entry has none', () => { /* no '· c/' in the text */ });
```

- [ ] **Step 2: Run, expect FAIL.** In the worktree, `npm ci` once, then
  `./node_modules/.bin/jest src/app/reader/entry/entry-source --testPathIgnorePatterns "/node_modules/" "/e2e/"`
  (the Docker `frontend` container serves the main checkout, not this worktree).

- [ ] **Step 3: Implement.** Render `<span class="community"> · c/{{ name }}</span>` (a link with class `community`
  in the article layout) right after the source name, before `savedIn`. In the kicker it is `flex: none` so the
  source keeps being the one item that ellipsises. Styles in the sibling `.scss`, tokens only.

- [ ] **Step 4: Run, expect PASS**, then the gate's static half: `npm run lint && npx tsc -p tsconfig.app.json --noEmit && npx tsc -p tsconfig.spec.json --noEmit` (or `npm run check` with the jest flags above).

- [ ] **Step 5: Commit** — `feat(#2347): list rows, kickers and the article byline name a post's Lemmy community`

### Task 5: Gates and the hand-off list

- [ ] **Step 1:** `composer check`, `composer md` on touched files, `composer infection:diff` — all green.
- [ ] **Step 2:** `/simplify` over the branch, then commit what it changes.
- [ ] **Step 3:** Push `feature/2347-lemmy-community-line`; the PR (`Closes #2347`) only on Lars's ask.
- [ ] **Step 4:** Hand Lars what this worktree cannot run, for when the branch is in the main checkout: the MySQL leg
  (`docker compose exec php composer test:parallel`), the migration on the dev MySQL, and the live look at a
  Lemmy timeline row, a magazine kicker, an article, and a community feed (no community shown).
