# Lemmy Cross-Post Grouping Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** When one author posts the same link or text into several Lemmy communities, lists show one row per group. The earliest-published post leads, and "Also in c/<community>" pills carry each other post's score and comment count and open that post in the reader. Trending counts the group once (#2420).

**Architecture:** A nullable, indexed `canonical_item.cross_post_hash`. Ingestion fills it after linking, through a tagged `CrossPostKeyRuleInterface` that has one Lemmy implementation. The hash comes from a static `Support/` helper that the backfill migration reuses. `DuplicateCollapseDql` gains a third per-key semi-join on the hash, scoped without the read filter. `attachDuplicates` attaches the hidden members to the leader, and the SPA's "Also in" chips render them as cross-post pills. Trending gets a third bucket in `TrendingRun`, a third no-earlier probe, and a member count that counts a group once.

**Tech Stack:** Symfony 8.1 / PHP 8.5 / Doctrine ORM (MySQL 8.4 + SQLite), Angular 20 signals, PHPUnit 12, Jest.

**Spec:** `docs/superpowers/specs/2026-11-21-lemmy-crossposts-design.md`. Read it before your task; this plan argues from it.

## Global Constraints

- Branch `feature/2420-lemmy-crossposts` (already checked out; never switch, stash or reset; the checkout is shared). Commits are `type(#2420): …`, with no Claude attribution anywhere.
- Link posts match on the same author and the same linked URL. Text posts match on the same author, the same title and the same body. Lemmy's `cross_posts` is not used, and no extra API calls are made.
- The earliest-published post leads, with the entry id as tiebreak.
- Votes, likes, comments and read state stay per post. `buildItemKey`, `entry-row-cache` `findCopies`/`collectCopyIds`, `CopyReadStatePropagator` and unread counts are untouched.
- The group row reads as read when its leading post is read: the cross-post semi-join's scope leaves out the read filter.
- Trending: a group adds one source and one member to a story, not one per community.
- The shared layer stays kind-neutral. `canonical_item`, its entity, `DuplicateCollapseDql`, `EntryListRepository` and the Trending SQL name no network. Only the rule decides "Lemmy".
- `cross_post_hash` is `VARCHAR(64)` (a sha256 hex), nullable, indexed `idx_canonical_item_cross_post_hash`.
- One semi-join per key. Never `OR` the keys into one `EXISTS` (MySQL loses the indexes).
- The backfill is batched and keyed by id, with no correlated per-row UPDATE. The mapping, the index and the backfill are in one migration, for both dialects.
- CLAUDE.md applies in full: the verb table, no `…Of` names in PHP, `final readonly`, queries only in `src/Repository`, no module cycle, PHPMD-clean touched files (if PHPMD flags a class, report it to the controller; do not split a class to get under the count), PHPStan max with no new ignores or rule edits, phptramp, and the high bar for comments.
- Frontend: no hex colours or ad-hoc `px` in `.scss`; styles live in a sibling `.scss` file; tokens come from `docs/design-language.md`. Jest runs only via `docker compose exec -T frontend npm run check`.
- **Measurement runs on a scratch MySQL copy.** Run `mysqldump feedreader` into a scratch database, measure there, and drop the scratch database afterwards. Never write `feedreader` during measurement.

## Decisions this plan makes (the design left them open)

- **The rule interface is owned by Ingestion.** It is `Service/Ingestion/Ingest/CrossPostKeyRule/CrossPostKeyRuleInterface`, tag `app.cross_post_key_rule`. Its Lemmy implementation sits in the same folder, as `LemmyEntryRule` does in `PlatformEntryRule/`. That avoids an `Ingestion → Social/Lemmy` edge.
- **The RSS linked URL is read off the post body.** `LemmyLinkedUrl::parse()` reads the body left after the header, and the migration reads stored `content_html` with the same helper. The API path uses `post.url`, as the design says.
  - A post is a link post when its body leads with an `<a>` whose text is its href. RSS leads with a bare `<a>`; API leads with `<p><a></a></p>`. Dev data confirms both shapes.
  - A text post whose body opens with a bare autolinked URL would be keyed as a link post on RSS only. That only fails to group.
- **The hash format:**
  - `sha256` of `"link\n<author>\n<url>"` or `"text\n<author>\n<title>\n<body>"`.
  - Author: lowercased, with any trailing `/` trimmed.
  - URL: `UrlNormalizer::normalize()`, done by the caller, because a Support helper cannot take a service.
  - Title and body text: Unicode whitespace collapsed to one space, trimmed, lowercased.
  - The body text is the `textContent` of `HtmlDocumentParser::parseFragment()`.
  - The result is null when the author is missing, or when there is no URL and both title and body are empty.
- **The first copy's hash wins.** `recordCrossPostHash()` writes only when the item has none, which matches the migration's lowest-id copy.
- **"Earlier" in the cross-post semi-join:**
  - It means a different item `(published_at, id)` earlier than `e`. Restricting it to a different item keeps the semi-join from fighting the item collapse, whose copies share `published_at`.
  - Two entries with a null `published_at` do not hide each other. Lemmy posts always carry one.
- **"Scope without read state" is a second callback.** A new `CollapseScope` value carries it. `EntryQuery::withoutReadFilter()` drops only the `Unread` view; Favorites, Kept, Shared and Viewed stay. `EntrySearchQuery::withoutReadFilter()` sets `unread: false`. Every other caller passes one scope for all three semi-joins.
- **The pills come from the same scope without read state.** They are the members the semi-join hid, one per other item (its lowest entry id), ordered by `published_at`, then id.
- **Trending's probe order:** the Trending probe uses `entry_id <` like its siblings, because "any one member counts" is all a count needs. The same-feed rule in `mayLink` stays.
- **The pill:**
  - It reads `c/<name>`, then `arrow_upward` + compact score, then `chat_bubble` + compact comment count, each part only when non-null. The relative time is dropped.
  - A click calls `open(copy)`, so the post opens in the reader; no overlay. Same-item copies keep the overlay.
  - A copy counts as a cross-post when both items are non-null and differ, and the copy has a `community`.
- **Restore stays ungrouped.** Rows restored from a backup are linked by `RestoredEntryFinisher` without a `ParsedEntryModel`, so they get no hash. Those items stay ungrouped (no harm), and the plan leaves them out of scope.
- **The live check migrates the dev database.** It needs the dev DB `feedreader` migrated, but only after the scratch run proved the migration. Measurement itself never writes `feedreader`.

## Review Focus

1. **A cross-post group where the earliest-published post has the highest entry id.** This is the real dev data, PerfectDark: item 10181 is published 02:02:13 but has the highest id. The leader is the earliest-published post, and no member and no copy of the leader leaves a hole. Pinned in Task 4: `testEarliestPublishedLeadsEvenWithTheHighestId`.
2. **Unread view, leader read, later member unread.** No row for the group, and no pill row surfacing as unread. Pinned in Task 4: `testUnreadViewHidesTheGroupOnceItsLeaderIsRead`.
3. **The same post in two feeds (item copies) inside a cross-post group.** One row, one pill per other item (not per copy), and the leader's own copies stay overlay chips. Pinned in Task 5: `testEachOtherItemGetsOnePillHoweverManyCopiesItHas`.
4. **API and RSS copies of one link cross-post.** The display name differs, the author URL differs in case or trailing slash, and the body shapes are `<p><a>` and `<a>`. Both produce one key. Pinned in Task 1: `testApiAndRssShapesOfOneLinkPostShareAKey`, and in Task 3 on stored rows.
5. **A Favorites view where only a later member is favourited.** It still shows, because only the read filter leaves the cross-post scope. Pinned in Task 4: `testFavoritesViewShowsAFavouritedLaterMember`.

---

### Task 1: Linked URL and the cross-post hash

**Files:**
- Modify: `backend/src/Service/Ingestion/Parser/Model/ParsedEntryModel.php`: a `public ?string $linkedUrl` property (initialised null in the constructor like `$community`) and a wither.
- Modify: `backend/src/Service/Social/Lemmy/LemmyPostEntries.php` (`parseEntry` chain, ~l.124): `->withLinkedUrl($post->string('url'))`.
- Modify: `backend/src/Service/Ingestion/Ingest/PlatformEntryRule/LemmyEntryRule.php` (`apply`): `->withLinkedUrl(LemmyLinkedUrl::parse($post->bodyHtml))`.
- Create: `backend/src/Service/Ingestion/Ingest/Support/LemmyLinkedUrl.php`
- Create: `backend/src/Service/Ingestion/Ingest/Model/CrossPostModel.php`
- Create: `backend/src/Service/Ingestion/Ingest/Support/CrossPostHash.php`
- Test: `backend/tests/Service/Ingestion/Ingest/Support/LemmyLinkedUrlTest.php`, `backend/tests/Service/Ingestion/Ingest/Support/CrossPostHashTest.php`, and an added case each in `tests/Service/Social/Lemmy/LemmyPostEntriesTest.php` and `tests/Service/Ingestion/Ingest/PlatformEntryRule/LemmyEntryRuleTest.php`.

**Interfaces:**
- Produces:
  - `ParsedEntryModel::withLinkedUrl(?string $linkedUrl): self`, with `#[\NoDiscard]`, built as `clone($this, ['linkedUrl' => …])`. Read it as `$parsed->linkedUrl`.
  - `final class LemmyLinkedUrl { public static function parse(?string $bodyHtml): ?string }`.
    - The href of a leading `<a>`, or of a `<p>` whose only content is one `<a>`, when that anchor's trimmed text equals its href and the href is absolute http(s).
    - Leading whitespace text nodes are skipped. Anything else gives null.
  - `final readonly class CrossPostModel { __construct(public ?string $authorUrl, public ?string $normalizedLinkedUrl, public string $title, public ?string $bodyHtml) }`.
  - `final class CrossPostHash { public static function build(CrossPostModel $post): ?string }`, in the format under Decisions.

- [ ] **Step 1: Write the failing tests.**
  - `LemmyLinkedUrlTest`:
    - `testRssBodyLeadsWithTheLink`: `'<a href="https://x.example/a" rel="noopener">https://x.example/a</a><blockquote><p>q</p></blockquote>'` → `'https://x.example/a'`.
    - `testApiBodyLeadsWithTheLinkParagraph`: `'<p><a href="https://x.example/a">https://x.example/a</a></p><p>body</p>'` → same.
    - `testTextPostHasNone`: `'<p>There’s a separate thread</p>'` → null.
    - `testAnchorWithOtherTextIsNotTheLink`: `'<p><a href="https://x.example/a">read this</a></p>'` → null.
    - `testNullAndEmptyHaveNone`: null and `''` → null.
  - `CrossPostHashTest`:
    - `testApiAndRssShapesOfOneLinkPostShareAKey`: author `https://lemmy.world/u/PerfectDark` against `https://lemmy.world/u/perfectdark/`, with one URL and bodies of different shape. The hashes are equal and match `/^[0-9a-f]{64}$/`.
    - `testAnotherAuthorIsAnotherKey`: a different author with the same link → not equal.
    - `testTextPostsMatchOnTitleAndBodyText`:
      - `'<p>Hello   <em>world</em></p>'` and `"<div>Hello world</div>\n"` under the title `'Ask  Lemmy'` vs `'ask lemmy'` → equal.
      - A different body word → not equal.
    - `testALinkPostAndATextPostNeverShareAKey`: one author, a URL vs none, the same title → not equal.
    - `testNoAuthorNoKey`: null author → null.
    - `testNothingToMatchNoKey`: a URL of null, title `''` and body `'<p> </p>'` → null.
  - `LemmyPostEntriesTest::testALinkPostCarriesItsLinkedUrl`: the existing link-post fixture → `$entry->linkedUrl` is the post's `url`, and a text post → null.
  - `LemmyEntryRuleTest::testALinkPostCarriesItsLinkedUrl`: the first item of `tests/Fixtures/lemmy/politics.xml` → `'https://www.businessinsider.com/dhs-demands-50-billion-to-prevent-another-huge-faa-fiasco-2026-11'`, and the megathread item (text) → null.
- [ ] **Step 2: Run** `cd backend && php bin/phpunit --filter 'LemmyLinkedUrlTest|CrossPostHashTest|LemmyPostEntriesTest|LemmyEntryRuleTest'`. Expect a FAIL (class or method not defined).
- [ ] **Step 3: Implement** the signatures above.
  - Both helpers are static-only `final class`es with a private constructor, as `LemmyPostHeader` is.
  - `CrossPostHash` reads text through `App\Service\Foundation\Html\Support\HtmlDocumentParser::parseFragment()`.
- [ ] **Step 4: Run** the filter from Step 2. Expect a PASS. Then run `composer cs && composer stan && composer md`, which must be clean on the touched files.
- [ ] **Step 5: Commit** with `feat(#2420): Lemmy posts carry their linked URL, and a cross-post hash builds from author, link or text`.

### Task 2: `canonical_item.cross_post_hash`, filled at ingestion

**Files:**
- Modify: `backend/src/Entity/CanonicalItem.php`: column, index, getter and recorder.
- Create: `backend/src/Service/Ingestion/Ingest/CrossPostKeyRule/CrossPostKeyRuleInterface.php`
- Create: `backend/src/Service/Ingestion/Ingest/CrossPostKeyRule/LemmyCrossPostKeyRule.php`
- Create: `backend/src/Service/Ingestion/Ingest/CrossPostKeys.php`
- Modify: `backend/config/services.yaml`: a `CrossPostKeys` `$rules: !tagged_iterator app.cross_post_key_rule`, beside `CanonicalKeys` (~l.341).
- Modify: `backend/src/Service/Ingestion/Ingest/CanonicalItemWriter.php`
- Test: `backend/tests/Service/Ingestion/Ingest/CrossPostKeyRule/LemmyCrossPostKeyRuleTest.php`, and added cases in `backend/tests/Service/Ingestion/Ingest/CanonicalItemWriterTest.php`.

**Interfaces:**
- Consumes: `CrossPostModel`, `CrossPostHash::build()` and `ParsedEntryModel::$linkedUrl` (Task 1).
- Produces:
  - `CanonicalItem`:
    - `#[ORM\Column(name: 'cross_post_hash', length: 64, nullable: true)] private ?string $crossPostHash = null;`
    - `#[ORM\Index(name: 'idx_canonical_item_cross_post_hash', columns: ['cross_post_hash'])]`
    - `getCrossPostHash(): ?string`
    - `recordCrossPostHash(string $hash): void`, which writes only while the item has none.
  - `#[AutoconfigureTag('app.cross_post_key_rule')] interface CrossPostKeyRuleInterface { public function findHash(CanonicalItem $item, ParsedEntryModel $parsed): ?string; }`
  - `LemmyCrossPostKeyRule` (injects `UrlNormalizer`):
    - Null unless `$item->getKind() === CanonicalItemKind::Lemmy`.
    - Otherwise `CrossPostHash::build(new CrossPostModel($parsed->authorUrl, $urlNormalizer->normalize($parsed->linkedUrl), $parsed->headline->text, $parsed->contentHtml))`.
  - `final readonly class CrossPostKeys { /** @param iterable<CrossPostKeyRuleInterface> $rules */ public function findHash(CanonicalItem $item, ParsedEntryModel $parsed): ?string }`. The first non-null answer wins, mirroring `CanonicalKeys`.
  - `CanonicalItemWriter::attach()`: for each linked position it also records `$crossPostKeys->findHash(...)` on `$entry->getCanonicalItem()` when non-null.

- [ ] **Step 1: Write the failing tests.**
  - `LemmyCrossPostKeyRuleTest`:
    - `testALemmyLinkPostIsKeyed`: a 64-hex result.
    - `testTrackingParametersDoNotSplitAGroup`: `https://x.example/a?utm_source=l` vs `https://x.example/a` → equal.
    - `testAnotherKindIsNeverKeyed`: a `CanonicalItemKind::Mastodon` item → null.
  - `CanonicalItemWriterTest`:
    - `testTwoCommunitiesOfOneLinkPostShareAHash`: two parsed Lemmy posts with different pages, one author and one `linkedUrl` give two items with an equal non-null `getCrossPostHash()`.
    - `testALaterCopyKeepsTheFirstCopysHash`: a second copy of the same item whose parsed body differs leaves the hash unchanged.
    - `testAMastodonItemGetsNoHash`.
- [ ] **Step 2: Run** `php bin/phpunit --filter 'LemmyCrossPostKeyRuleTest|CanonicalItemWriterTest'`. Expect a FAIL.
- [ ] **Step 3: Implement.** Check the wiring with `bin/console debug:container App\\Service\\Ingestion\\Ingest\\CrossPostKeys --show-arguments`; it must list `LemmyCrossPostKeyRule`.
- [ ] **Step 4: Run** the filter, then the whole suite with `php bin/phpunit`. Expect a PASS. Then run `composer check && composer md`, which must be clean. `ServiceModuleCycleRule` must stay green.
- [ ] **Step 5: Commit** with `feat(#2420): ingestion keys each Lemmy item with its cross-post hash`.

### Task 3: Migration with batched backfill

**Files:**
- Create: `backend/migrations/Version20261121120000.php`
- Test: `backend/tests/Migrations/Version20261121120000Test.php`, in the in-memory SQLite style of `tests/Migrations/Version20261116220000Test.php`.

**Interfaces:**
- Consumes: `LemmyLinkedUrl::parse()`, `CrossPostModel`, `CrossPostHash::build()` (Task 1), and `new UrlNormalizer()` (the migration is not a service).

Shape:
- **Guards.** `abortIf` on any platform but MySQL or SQLite, the same guard as `Version20261121090000::requireSupportedPlatform`. `skipIf` when `canonical_item` already has `cross_post_hash`.
- **DDL.** `ALTER TABLE canonical_item ADD cross_post_hash VARCHAR(64) DEFAULT NULL`, then `CREATE INDEX idx_canonical_item_cross_post_hash ON canonical_item (cross_post_hash)`.
- **Backfill.**
  - A keyset walk over `canonical_item` where `kind = 'lemmy'`, in batches of 1000, ordered by id. Each item reads its lowest-id copy: `JOIN entry e ON e.id = (SELECT MIN(first_copy.id) FROM entry first_copy WHERE first_copy.canonical_item_id = ci.id)`, selecting `ci.id, e.author_url, e.title, e.content_html`.
  - Each batch hashes in PHP. The URL is `normalize(LemmyLinkedUrl::parse(content_html))`.
  - Each batch queues one `UPDATE canonical_item SET cross_post_hash = CASE id WHEN … END WHERE id IN (…)` through `addSql` with bound parameters, skipping null hashes.
- **`isTransactional()`.** True on SQLite, false on MySQL, as `Version20261120120000`.
- **`down()`.** Drops the index (MySQL `DROP INDEX … ON canonical_item`), then the column.

- [ ] **Step 1: Write the failing test.** The old schema is minimal `canonical_item` (`id, kind, canonical_key, key_hash, score, comment_count, self_reply_count, created_at`) and `entry` (`id, canonical_item_id, author_url, title, content_html`).
  - `testCopiesOfOneLinkCrossPostShareAHash`:
    - The rows are three Lemmy items by `https://lemmy.world/u/PerfectDark`. Item 1's lowest copy is API-shaped (`<p><a href=U>U</a></p>`), item 2's is RSS-shaped (`<a href=U>U</a>`), and item 3's is API-shaped with an author URL ending in `/`.
    - After `up`, all three hashes are equal and non-null.
  - `testATextCrossPostSharesAHashAndAnotherTitleDoesNot`.
  - `testAMastodonItemStaysUnkeyed`: kind `mastodon` → `cross_post_hash` IS NULL.
  - `testMoreThanOneBatchIsKeyed`: 1001 Lemmy items, each with its own author → 1001 non-null hashes.
  - `testDownDropsTheColumn`: after `down`, `cross_post_hash` is no longer among the `canonical_item` columns.
  - `testRunningUpTwiceSkips`.
- [ ] **Step 2: Run** `php bin/phpunit tests/Migrations/Version20261121120000Test.php`. Expect a FAIL.
- [ ] **Step 3: Implement.**
- [ ] **Step 4: Run** the test, then the migrate-from-empty leg both ways. Expect a PASS, and `[OK] The database schema is in sync with the mapping files` on both dialects.
  - SQLite: `APP_ENV=test` with a fresh file DB, `bin/console doctrine:migrations:migrate -n && bin/console doctrine:schema:validate`.
  - MySQL: a scratch database (create `feedreader_empty_2420` as root, point `DATABASE_URL` at it with `docker compose exec -e DATABASE_URL=… php …`, then drop it). Never touch `feedreader`.
- [ ] **Step 5: Commit** with `feat(#2420): migration adds and backfills canonical_item.cross_post_hash`.

### Task 4: Collapse hides every later cross-post

**Files:**
- Create: `backend/src/Repository/CollapseScope.php`
- Modify: `backend/src/Repository/DuplicateCollapseDql.php`, `backend/src/Repository/EntryAliases.php`, `backend/src/Repository/EntryQuery.php`, `backend/src/Repository/EntrySearchQuery.php`
- Modify the callers: `EntryListRepository` (list, search, rowsByIds), `RecommendationCandidateRepository:40`, `TrendingStoryEntryListRepository:60,82`, `SavedSearchEntryRepository:87,228`. Check `grep -rn 'collapse->apply' backend/src` for completeness.
- Test: `backend/tests/Repository/CrossPostCollapseTest.php` (fixtures as in `DuplicateCollapseTest`, plus `tests/Support/LemmyPostEntries`)

**Interfaces:**
- Produces:
  - `final readonly class CollapseScope`:
    - `public static function fromScope(callable $applyScope): self`, which uses the same scope for all three semi-joins.
    - `public static function fromScopes(callable $applyCopyScope, callable $applyCrossPostScope): self`.
    - It exposes `public \Closure $copies` and `public \Closure $crossPosts`, both `\Closure(QueryBuilder, EntryAliases): void`.
  - `DuplicateCollapseDql::apply(QueryBuilder $qb, CollapseScope $scope, int $userId): void`. It replaces the callable parameter, and every caller wraps.
  - `EntryAliases::crossPostCollapse(): self` returns `('e4','es4','esh4','s4','nf4')`.
  - `EntryQuery::withoutReadFilter(): self` (`#[\NoDiscard]`): view `Unread` becomes `All`, and every other field and view stays.
  - `EntrySearchQuery::withoutReadFilter(): self`: the same query with `unread: false`.
  - The list and search callers pass `CollapseScope::fromScopes($applyScope, <scope of $query->withoutReadFilter()>)`. `rowsByIdsForUser` and the other repositories pass `fromScope`.

The third predicate, added after the two existing ones:

```
e.canonicalItem IS NULL OR NOT EXISTS (
  SELECT 1 FROM Entry e4 JOIN e4.canonicalItem crossPostItem
  JOIN CanonicalItem leadItem ON leadItem = e.canonicalItem
  <subscription / state / share joins on the e4 aliases, as buildEarlierCopyDql>
  WHERE leadItem.crossPostHash IS NOT NULL
    AND crossPostItem.crossPostHash = leadItem.crossPostHash
    AND crossPostItem <> leadItem
    AND (e4.dates.publishedAt < e.dates.publishedAt
         OR (e4.dates.publishedAt = e.dates.publishedAt AND e4.id < e.id))
    <$scope->crossPosts applied with EntryAliases::crossPostCollapse()>)
```

Share the inner join scaffolding with `buildEarlierCopyDql` rather than copying it. The class docblock's "lowest-id one" sentence gains the cross-post rule in one line.

- [ ] **Step 1: Write the failing tests.** Each test persists Lemmy items whose `recordCrossPostHash('h…')` ties them into a group, in feeds A and B.
  - `testACrossPostGroupShowsAsOneRowLedByTheEarliestPublished`: the list (All) returns only the earliest-published member.
  - `testEarliestPublishedLeadsEvenWithTheHighestId`: the leader has the highest entry id → the result is `[leader id]`, with exactly one row (no hole).
  - `testACopyOfTheLeaderInAnotherFeedStillCollapsesByItem`: `[lowest-id copy of the leader's item]`.
  - `testUnreadViewHidesTheGroupOnceItsLeaderIsRead`: mark the leader read; `EntryView::Unread` → `[]`.
  - `testUnreadViewShowsTheLeaderWhileItIsUnread`: `[leader]`, even with a later member read.
  - `testFavoritesViewShowsAFavouritedLaterMember`: only the later member is a favourite → `[that member]`.
  - `testASubscriptionViewWithoutTheLeaderShowsItsOwnMember`: `subscriptionId` of feed B (the member's) → `[member]`.
  - `testUngroupedItemsAreUntouched`: a null hash → both rows.
  - `testSearchCollapsesAGroupToItsLeader`, `testUnreadSearchHidesTheGroupOnceItsLeaderIsRead`.
  - `testAPageStillHoldsLimitRows`: 3 groups of 2 plus 2 singles at `limit: 4` → 4 rows, with no group twice.
- [ ] **Step 2: Run** `php bin/phpunit --filter 'CrossPostCollapseTest|DuplicateCollapseTest'`. Expect the new tests to FAIL and the old ones to PASS.
- [ ] **Step 3: Implement.**
- [ ] **Step 4: Run** the filter. Expect everything to PASS. Then run the full SQLite suite with `php bin/phpunit`, and `docker compose exec php composer test:parallel` at the same time (never alongside Jest). Then run `composer check && composer md`; on `EntryListRepository`, report a PHPMD finding to the controller.
- [ ] **Step 5: Commit** with `feat(#2420): lists collapse a Lemmy cross-post group to its earliest post`.

### Task 5: The leader carries its cross-posts as duplicates

**Files:**
- Modify: `backend/src/Repository/EntryListRepository.php` (`attachDuplicates`, `findCopiesBy…`, `attachCopies`)
- Test: added cases in `backend/tests/Repository/CrossPostCollapseTest.php`, and in `backend/tests/Controller/Api/EntryControllerTest.php` (beside the duplicates test at ~l.402)

**Interfaces:**
- Consumes: `CollapseScope` (Task 4), `CanonicalItem::getCrossPostHash()` (Task 2)
- Produces:
  - `attachDuplicates(array $survivors, CollapseScope $scope, int $userId)`.
    - The url and item copies use `$scope->copies`, as today.
    - The cross-post copies come from `findCopiesByCrossPostHash(QueryBuilder $qb, array $hashes): array<string, list<EntryListRow>>` on `$scope->crossPosts`, filtered by `ci.crossPostHash IN (:crossPostHashes)` and ordered by `e.dates.publishedAt ASC, e.id ASC`.
  - `attachCopies` appends the cross-post copies after the existing ones, skips the survivor's own item, and keeps one per other item (the lowest entry id).
  - The JSON needs no change. `EntryJson::listRow` already emits each duplicate's `community`, `score`, `commentCount` and `myVote`, and `EntryListRowEnricher` batches over `[row, ...duplicates]`.

- [ ] **Step 1: Write the failing tests.**
  - `testTheLeaderCarriesEachCrossPostOnce`: `duplicates` ids are `[member ids in published order]`.
  - `testEachOtherItemGetsOnePillHoweverManyCopiesItHas`: a member item with copies in feeds A and B → one duplicate for it (the lower id). A copy of the leader's own item in feed B stays a duplicate too, so the total is copies + one per other item.
  - `testUnreadViewPillsIncludeAReadMember`.
  - `EntryControllerTest::testACrossPostLeaderListsItsCommunitiesWithScoreAndComments`:
    - `GET /api/entries` → one entry.
    - `duplicates[0]['community']['name'] === 'retrogaming'`, `duplicates[0]['score'] === 12`, `duplicates[0]['commentCount'] === 3`.
    - Use `LemmyPostEntries::storeCommunity` and `recordCounts`.
- [ ] **Step 2: Run** `php bin/phpunit --filter 'CrossPostCollapseTest|EntryControllerTest'`. Expect a FAIL.
- [ ] **Step 3: Implement.** Make one cross-post query per page, never one per row. Hashes are collected from the survivors with `collectKeys`, as the url and item keys are.
- [ ] **Step 4: Run** the filter, then both suite legs. Expect a PASS. Then run `composer check && composer md`.
- [ ] **Step 5: Commit** with `feat(#2420): a cross-post leader carries the other communities' posts`.

### Task 6: Trending counts a group once

**Files:**
- Modify: `backend/src/Service/Curation/Trending/Model/WindowEntryModel.php`: `public ?string $crossPostHash = null` (last).
- Modify: `backend/src/Repository/TrendingEntryRepository.php` (`chunkAfter`, `buildEntry`, row shapes): `LEFT JOIN canonical_item item ON item.id = e.canonical_item_id`, selecting `item.cross_post_hash`.
- Modify: `backend/src/Service/Curation/Trending/Pass/TrendingRun.php`: a third bucket `entriesByCrossPostHash`. Rename `collectEntriesSharingAUrlOrItem()` to `collectEntriesSharingAKey()` and fold the bucket in.
- Modify: `backend/src/Service/Curation/Trending/StoryLinkSource/SharedUrlLinks.php`, for the rename.
- Modify: `backend/src/Repository/TrendingStoryRepository.php`: `visibleStories` (`LEFT JOIN canonical_item member_item`), `buildNoEarlierCrossPost()`, and `buildMemberCount`.
- Modify: `backend/src/Repository/TrendingStoryEntryListRepository.php` (`joinedAtByEntry`): also match a joined entry whose item shares the non-null cross-post hash.
- Test: `backend/tests/Service/Curation/Trending/Pass/TrendingRunTest.php`, `backend/tests/Repository/TrendingStoryRepositoryTest.php`, `backend/tests/Repository/TrendingStoryEntryListRepositoryTest.php`

**Interfaces:**
- Produces:
  - `TrendingRun::collectEntriesSharingAKey(): list<list<int>>`.
  - `TrendingStoryRepository`:
    - `buildNoEarlierCrossPost(TrendingWindow $window): string`, which is `buildNoEarlierCopy($window, 'member_item.cross_post_hash IS NULL', ' JOIN canonical_item earlier_item ON earlier_item.id = earlier_entry.canonical_item_id', 'earlier_item.cross_post_hash = member_item.cross_post_hash')`.
    - `buildMemberCount` counts `COUNT(DISTINCT COALESCE(member_item.cross_post_hash, CONCAT('i', member_entry.canonical_item_id), CONCAT('e', member.entry_id)))`, with a `LEFT JOIN canonical_item member_item` in its subquery. Its docblock is updated in one line.
    - Verify `CONCAT` on the SQLite leg (CI's SQLite is 3.45, which has `concat()`).

- [ ] **Step 1: Write the failing tests.**
  - `TrendingRunTest::testCrossPostsInTwoFeedsShareAStoryLink`: two `WindowEntryModel`s in different feeds, within the gap, with one `crossPostHash` and different items → `collectEntriesSharingAKey()` contains `[id1, id2]`.
  - `TrendingStoryRepositoryTest`:
    - `testACrossPostGroupIsOneSource`: a story with a group of 3 Lemmy posts by one author plus article members → `outlet_count` is the same as with one of the 3.
    - `testATitlesMemberCountCountsACrossPostGroupOnce`: model it on `testATitlesMemberCountCountsCopiesOfOneItemOnce` (~l.705).
  - `TrendingStoryEntryListRepositoryTest::testAStorysCrossPostGroupListsAsOneRowAndTalliesOnce`: list → 1 row; `countMembersAndUnread()->memberCount === 1`.
- [ ] **Step 2: Run** `php bin/phpunit --filter 'TrendingRunTest|TrendingStoryRepositoryTest|TrendingStoryEntryListRepositoryTest'`. Expect a FAIL.
- [ ] **Step 3: Implement.**
- [ ] **Step 4: Run** the filter, then both suite legs. Expect a PASS. Then run `composer check && composer md`.
- [ ] **Step 5: Commit** with `feat(#2420): Trending counts a Lemmy cross-post group once`.

### Task 7: SPA cross-post pills

**Files:**
- Modify: `frontend/src/app/reader/list/magazine/also-foot.ts`: `export function isCrossPost(lead: EntryDto, copy: EntryDto): boolean`.
- Modify: `frontend/src/app/reader/list/magazine/entry-duplicates.component.ts` / `.html` / `.scss`
- Modify: `docs/design-language.md`: the "Also in" pill paragraph (~l.1594–1603) gains the cross-post variant in two sentences.
- Test: `frontend/src/app/reader/list/magazine/entry-duplicates.component.spec.ts`, `frontend/src/app/reader/list/magazine/also-foot.spec.ts`

**Interfaces:**
- Consumes: duplicates from Task 5 (`canonicalItemId`, `community`, `score`, `commentCount` on each `EntryDto`).
- Produces:
  - `isCrossPost(lead, copy)`: `lead.canonicalItemId !== null && copy.canonicalItemId !== null && copy.canonicalItemId !== lead.canonicalItemId && copy.community !== null`.
  - Each `named()` item gains `crossPost: boolean`, `score: string | null` and `comments: string | null` (formatted by `CompactCount`, as `EntryMetaComponent.comments` does).
    - The feed label is `c/${copy.community.name}` for a cross-post. The template renders `item.when` only for non-cross-posts.
    - The score span (`arrow_upward` icon + text) and the comments span (`chat_bubble` icon + text) carry `role="img"` and an `aria-label` from `reader.discussionCounts.points*` / `comments*` through `pluralKey`, as `entry-meta.component.html` does.
  - Click: for a cross-post `(click)` calls a new `openCrossPost(copy: EntryDto, event: Event): void`, which stops propagation and delegates to the existing `open(copy)` (`shellActions.open` + `close()`). Other copies keep `toggle`.

- [ ] **Step 1: Write the failing tests.**
  - `also-foot.spec.ts`:
    - `isCrossPost` is true for another item with a community.
    - It is false for the same item, a null item, and a copy without a community.
  - `entry-duplicates.component.spec.ts`. The lead is `entry({ canonicalItemId: 1, community: { name: 'games', url: 'https://lemmy.world/c/games' } })`, and the duplicate is `entry({ id: 2, canonicalItemId: 2, community: { name: 'retrogaming', url: 'https://lemmy.world/c/retrogaming' }, score: 12, commentCount: 3 })`.
    - `it('labels a cross-post chip by its community, with its score and comment count')`:
      - The chip `.feed` text is `'c/retrogaming'`, and `.also-entry` contains `'12'` and `'3'`.
      - `[role="img"]` aria-labels contain `'12'` and `'3'`.
      - It has no `.t`.
    - `it('opens a cross-post in the reader on click, with no popover')`: `entryActions.open` is called with the duplicate, and `OverlayContainer` holds no `.dup-popover`.
    - `it('omits the score and the count a cross-post does not have')`: `score: null, commentCount: null` → no `[role="img"]`.
    - `it('keeps the popover for a copy of the same item')`: the duplicate has `canonicalItemId: 1` → a popover opens.
- [ ] **Step 2: Run** `docker compose exec -T frontend npx jest src/app/reader/list/magazine`. Expect a FAIL.
- [ ] **Step 3: Implement.**
  - In `.scss`, the score and comments spans sit inline with `gap: var(--space-1)` and `color: var(--text-muted)`, and use the existing chip tokens only.
  - No new token. If one turns out to be needed, add it to `docs/design-language.md` §tokens.
- [ ] **Step 4: Run** `docker compose exec -T frontend npm run check`. Expect it to be fully green: ESLint, Prettier, Stylelint, tsc and Jest.
- [ ] **Step 5: Commit** with `feat(#2420): cross-post pills name the community, its score and comments, and open the post`.

### Task 8: Measurement, live check and whole-branch gates

**Files:** none new, unless a finding needs a fix (fold it into the owning task's code with its own commit).

- [ ] **Step 1: Run the backend gates.** Run `cd backend && composer check && composer md`, then `php bin/phpunit` and `docker compose exec php composer test:parallel` in parallel. Expect everything to be green.
- [ ] **Step 2: Run mutation testing.** Run `composer infection:diff`. The MSI must meet `minMsi` in `infection.json5`. Kill escaped mutants on the changed lines with tests; never lower `minMsi`.
- [ ] **Step 3: Measure on a scratch copy.**
  - Create and fill it:
    ```bash
    docker compose exec -T mysql mysql -uroot -proot -e 'CREATE DATABASE feedreader_scratch_2420'
    docker compose exec -T mysql sh -c 'mysqldump -uroot -proot --single-transaction feedreader | mysql -uroot -proot feedreader_scratch_2420'
    ```
  - Time the backfill: `time docker compose exec -T -e DATABASE_URL='mysql://root:root@mysql:3306/feedreader_scratch_2420?serverVersion=8.4&charset=utf8mb4' php bin/console doctrine:migrations:migrate --no-interaction`.
    - Record the time and `SELECT COUNT(*) FROM canonical_item WHERE cross_post_hash IS NOT NULL`.
    - Check that the PerfectDark items share one hash: `SELECT id, cross_post_hash FROM canonical_item WHERE id IN (10179, 10180, 10181)` returns one value three times.
    - If the backfill takes more than 5 minutes, stop and report.
- [ ] **Step 4: Migrate the dev database and check it live.**
  - Run `docker compose exec php bin/console doctrine:migrations:migrate --no-interaction`; it is safe now that Step 3 proved the migration.
  - Restart the worker if its container caches DI (see the docker gotchas memory).
  - On http://localhost:4200, signed in as dev user 2, open the unfiltered list that holds PerfectDark's "Before Rainbow Six, Psygnosis Had Plans…".
  - It must show **one** row, led by the c/<community> of item 10181 (the earliest, 02:02:13). It must carry "Also in" pills `c/…` for the other two communities, each with its own score and comment count.
  - Clicking a pill must open that post in the reader, and its URL and title must be that post's.
  - Screenshot both states. Open the same list in the Unread view and mark the leader read; the group row disappears.
- [ ] **Step 5: Measure the list query.**
  - Capture the real list statement from today's dev log: `ls -t backend/var/log/dev-*.log | head -1 | xargs grep -h '"doctrine"' | jq -r 'select(.message|test("NOT EXISTS")) | .context'`. Take the page query of `/api/entries` and substitute its parameters.
  - On `feedreader_scratch_2420`, run `EXPLAIN ANALYZE` on it, and on the same text with the third `NOT EXISTS` removed.
  - The third semi-join must read `canonical_item` by `PRIMARY` and `idx_canonical_item_cross_post_hash`, and `entry` by `idx_entry_canonical_item`. It must make no full scan of either table, and the page must stay within 1.5× of the statement without it. Otherwise, stop and report.
  - Record both timings and the relevant plan lines for the PR body.
- [ ] **Step 6: Drop the scratch database.** Run `docker compose exec -T mysql mysql -uroot -proot -e 'DROP DATABASE feedreader_scratch_2420'`, and confirm `SHOW DATABASES` no longer lists it.
- [ ] **Step 7: Scan the dev log.** Run `ls -t backend/var/log/dev-*.log | head -1 | xargs tail -n 300 | jq 'select(.level_name=="ERROR" or .level_name=="WARNING" or (.message|test("eprecat")))'`. The scan must show no new errors or deprecations from the touched code.
- [ ] **Step 8: Run the frontend gate.** Run `docker compose exec -T frontend npm run check`. Expect it to be green.
- [ ] **Step 9: Record the results.** If any step changed code, commit it as `fix(#2420): …`. Hand the measurements (backfill time and count, both `EXPLAIN ANALYZE` timings) and the screenshots to the controller for the PR body.
