# Notification Opens Entry Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** A social notification row whose post is one of the user's readable entries opens that entry in the reader. Every other row keeps its external link.

**Architecture:** At poll time each notification stores up to three canonical-key hashes (the post, the post it answers, the thread root), built with the same normalisation the `CanonicalKeyRule`s apply to entries. When the list page loads, one batched query resolves the hashes through `canonical_item` to the lowest-id entry in a feed the user subscribes to. The first candidate that resolves becomes the group's `entryId`, and the SPA turns that row into an `?entry=` router link.

**Tech Stack:** Symfony 8.1 / Doctrine ORM (PHP 8.5), Angular 20 standalone + signals, Jest, PHPUnit.

**Spec:** GitHub issue #2391, plus the decisions below (agreed with Lars on 2026-11-21; they override the issue where the two differ).

## Decisions (the spec for this plan)

1. **Candidates per kind, in order** (first readable match wins):
   | Network | Kind | post | answered | root |
   |---|---|---|---|---|
   | Mastodon | like, repost, poll | `status.uri` (your post) | — | — |
   | Mastodon | reply | `status.uri` (their reply) | answered status `uri` (from the context source) | — |
   | Mastodon | mention | `status.uri` | — | — |
   | Mastodon | quote | `status.uri` (the quoting post) | `status.quote.quoted_status.uri` | — |
   | Bluesky | like, repost | `record.subject.uri` | — | — |
   | Bluesky | reply | notification `uri` | `record.reply.parent.uri` | `record.reply.root.uri` when ≠ parent |
   | Bluesky | mention | notification `uri` | — | — |
   | Bluesky | quote | notification `uri` | the quoted post's uri (`BlueskyReplyReferences` already reads it) | — |
   | Lemmy | reply, mention | `post.ap_id` | — | — |
   | any | follow | — | — | — |
2. Hashes are stored, not keys: `CanonicalItem::hashKey($key)` (sha256, 64 chars). The kind comes from the account's network.
3. The lookup happens when the list loads, never at poll time. A post that becomes an entry after the notification arrived still resolves.
4. Readable means the entry's feed has a `subscription` row for the user: the same inner join `EntryProjection::rowQueryBuilder` uses for `GET /api/entries/{id}`. When several copies are readable, the lowest entry id wins.
5. JSON: the group gains `entryId: int|null`. `linkUrl` stays unchanged as the fallback, and the iOS client needs it.
6. Frontend: a row with an `entryId` navigates in the same tab with `queryParams: entryChangeParams(String(entryId))`, `queryParamsHandling: 'merge'`, with the notifications view underneath. Every other row stays an external `<a target="_blank">`.
7. Out of scope: scrolling to a comment (follow-up issue), a second "open on network" link, any notification-list redesign.
8. Backfill: an **uncommitted** throwaway script, run on the local Docker stack and then on Strato over ssh. It never lands in git.

## Global Constraints

- Columns are exactly `subject_post_key_hash`, `subject_answered_key_hash`, `subject_root_key_hash`: `VARCHAR(64)`, nullable, on `social_notification`. No index.
- The migration works on SQLite and MySQL: one `ALTER TABLE … ADD` per column, guarded with `skipIf` as in `Version20261121150000`, `isTransactional(): false`.
- `App\Entity` imports no `App\Service` class (`PersistenceKnowsNoServiceRule`). Entities take hash strings, not `CanonicalKeyModel`.
- The notification and the entry build keys through one shared helper per network, so they cannot drift.
- Method names follow the CLAUDE.md verb table (`parse…` returns null when the input is not one; no `of…`/`…Of`).
- Every touched `src` file is PHPMD-clean. A new `src` class passes the PHPMD gate only if it would exist without PHPMD.
- Commits: `type(#2391): …`, no attribution lines.

## Review Focus

1. **A non-Mastodon fediverse post seen through Mastodon** (e.g. a Pleroma `https://pl.example/objects/<uuid>` uri) has to hash the same way the key rule hashes that entry's guid, so the AbsoluteHttpUrl fallback is shared. Pinned in Task 1.
2. **A Lemmy `ap_id` with an upper-case host or a trailing slash** has to produce the entry's key (`LemmyPostUrl` lower-cases and allows `/`). Pinned in Task 1.
3. **Another user's subscription, or an entry left in a feed the user unsubscribed from**, must never resolve. Pinned in Task 4.
4. **Several readable copies of one post** (timeline and a hashtag feed) resolve to the lowest id, deterministically. Pinned in Task 4.
5. **A group whose first candidate has no entry but whose second does** (a reply to your post: their reply isn't in your feeds, your post is) resolves to the second. Pinned in Task 5.

---

### Task 1: One canonical-key helper per network, shared by the key rules

**Files:**
- Create: `backend/src/Service/Social/Mastodon/Support/MastodonStatusKey.php`
- Create: `backend/src/Service/Social/Bluesky/Support/BlueskyPostKey.php`
- Create: `backend/src/Service/Social/Lemmy/Support/LemmyPostKey.php`
- Modify: `backend/src/Service/Social/Mastodon/MastodonCanonicalKeyRule.php`, `…/Bluesky/BlueskyCanonicalKeyRule.php`, `…/Lemmy/LemmyCanonicalKeyRule.php`
- Test: `backend/tests/Service/Social/{Mastodon,Bluesky,Lemmy}/Support/*KeyTest.php`. The existing `*CanonicalKeyRuleTest` files must stay green unchanged.

**Interfaces:**
- Produces (static, `final class` with private constructor, in `Support/`):
  - `MastodonStatusKey::parseKey(?string $address): ?CanonicalKeyModel`: `MastodonStatusAddress::parseAddress($address)?->uri()`, else `AbsoluteHttpUrl::keepIfAbsolute($address)`, as `CanonicalItemKind::Mastodon`. Null for null or a non-absolute input.
  - `BlueskyPostKey::parseKey(?string $uri): ?CanonicalKeyModel`: the uri itself when `AtPostUri::matches()`, as `CanonicalItemKind::Bluesky`.
  - `LemmyPostKey::fromPage(LemmyPostPageModel $page): CanonicalKeyModel` (`https://<host>/post/<id>`) and `LemmyPostKey::parseKey(?string $url): ?CanonicalKeyModel` (via `LemmyPostUrl::parse`).
- The rules keep their behaviour. `MastodonCanonicalKeyRule` tries `parseKey($guid)` (suffix stripped), then `parseKey($url)`. Check the existing rule tests: today the rule prefers a parsed status from *either* guid or url over an absolute guid. Keep exactly that order: parsed guid → parsed url → absolute guid → absolute url. Split `parseKey` into `parseStatusKey` / `keepAbsoluteKey` if that's what it takes to keep it.

- [ ] **Step 1: Write the failing tests**

```php
// MastodonStatusKeyTest
public function testStatusPageAndUriShareOneKey(): void
{
    self::assertSame(
        'https://mastodon.example/users/alice/statuses/123',
        MastodonStatusKey::parseKey('https://mastodon.example/@alice/123')?->key,
    );
    self::assertSame(
        MastodonStatusKey::parseKey('https://mastodon.example/users/alice/statuses/123')?->keyHash(),
        MastodonStatusKey::parseKey('https://mastodon.example/@alice/123')?->keyHash(),
    );
}
public function testNonMastodonUriKeepsItsAbsoluteAddress(): void
{
    self::assertSame('https://pl.example/objects/9f1c', MastodonStatusKey::parseKey('https://pl.example/objects/9f1c')?->key);
}
public function testNoAddressIsNoKey(): void
{
    self::assertNull(MastodonStatusKey::parseKey(null));
    self::assertNull(MastodonStatusKey::parseKey('not a url'));
}
// LemmyPostKeyTest
public function testApIdWithUpperCaseHostAndSlashIsThePostKey(): void
{
    self::assertSame('https://lemmy.example/post/42', LemmyPostKey::parseKey('https://Lemmy.Example/post/42/')?->key);
}
// BlueskyPostKeyTest
public function testAtUriIsItsOwnKey(): void
{
    $uri = 'at://did:plc:abc/app.bsky.feed.post/3k';
    self::assertSame($uri, BlueskyPostKey::parseKey($uri)?->key);
    self::assertNull(BlueskyPostKey::parseKey('https://bsky.app/profile/x/post/3k'));
}
```

- [ ] **Step 2: Run them and confirm they fail**: `cd backend && php bin/phpunit --filter 'StatusKeyTest|PostKeyTest'`. Expected: FAIL, class not found.
- [ ] **Step 3: Implement the three helpers and switch the three rules over to them.**
- [ ] **Step 4: Run** `php bin/phpunit --filter 'KeyTest|CanonicalKeyRuleTest'`. Expected: PASS, with the rule tests unchanged.
- [ ] **Step 5: Commit** `refactor(#2391): one canonical-key helper per network, shared by the key rules`

### Task 2: `NotificationPostKeys` embeddable, the migration, and the source/subject contract

**Files:**
- Create: `backend/src/Entity/NotificationPostKeys.php`
- Modify: `backend/src/Entity/NotificationSubject.php`
- Create: `backend/migrations/Version20261121180000.php`
- Modify: `backend/src/Service/Social/Notification/NotificationContextSource/NotificationContextSourceInterface.php`, `…/Notification/NotificationContextLookup.php`, `…/Notification/Model/NotificationPageModel.php`, `…/Notification/Model/FetchedNotificationModel.php`, and the three `*NotificationContextSource` implementations
- Test: `backend/tests/Entity/NotificationPostKeysTest.php`, `tests/Entity/NotificationSubjectTest.php`, `tests/Service/Social/Notification/Model/NotificationPageModelTest.php`, and the three context-source tests (update the expected values to subjects)

**Interfaces:**
- Produces:
  - `#[ORM\Embeddable] final readonly class NotificationPostKeys(public ?string $postHash = null, public ?string $answeredHash = null, public ?string $rootHash = null)`. Columns are named so that, embedded in `NotificationSubject` (prefix `subject_`), they come out as `subject_post_key_hash`, `subject_answered_key_hash` and `subject_root_key_hash`. Confirm with `bin/console doctrine:schema:validate` after the migration.
  - `NotificationPostKeys::hashes(): list<string>`: post, answered, root in that order, nulls dropped, duplicates dropped.
  - `#[\NoDiscard] NotificationPostKeys::withAnswered(?string $answeredHash): self`.
  - `NotificationSubject` gains `public NotificationPostKeys $keys = new NotificationPostKeys()` as its last constructor parameter, plus `#[\NoDiscard] withKeys(NotificationPostKeys $keys): self`. `NotificationSubject::follow()` keeps empty keys.
  - Context sources complete the whole subject: `NotificationContextSourceInterface::findSubjects(SocialAccount $account, array $fresh): array<array-key, NotificationSubject>` (by remote id, each built on the row's existing subject) replaces `findContexts`. `NotificationPageModel::withSubjects(array $subjects)` replaces `withContexts`, and `FetchedNotificationModel::withSubject(NotificationSubject)` replaces `withContext`. Every implementation keeps its current context behaviour: `$row->subject->withContext($context)`.

- [ ] **Step 1: Write the failing tests**

```php
public function testHashesFollowCandidateOrderWithoutGapsOrRepeats(): void
{
    self::assertSame(['p', 'r'], new NotificationPostKeys('p', null, 'r')->hashes());
    self::assertSame(['p'], new NotificationPostKeys('p', 'p')->hashes());
    self::assertSame([], new NotificationPostKeys()->hashes());
}
public function testWithAnsweredKeepsTheOtherHashes(): void
{
    self::assertEquals(new NotificationPostKeys('p', 'a', 'r'), new NotificationPostKeys('p', null, 'r')->withAnswered('a'));
}
// NotificationPageModelTest: withSubjects replaces the subject of the listed remote ids only (adapt the two withContexts tests)
```

- [ ] **Step 2: Run them and confirm they fail**: `php bin/phpunit tests/Entity tests/Service/Social/Notification`.
- [ ] **Step 3: Implement the embeddable and the subject field, then the interface rename across the lookup, models and three sources. Write the migration**: three `ALTER TABLE social_notification ADD <col> VARCHAR(64) DEFAULT NULL` statements, each guarded by `skipIf(hasColumn)`. `down()` drops them.
- [ ] **Step 4: Verify the migration on both dialects**: `php bin/console doctrine:migrations:migrate --no-interaction` against a scratch SQLite DB (`DATABASE_URL=sqlite:///$SCRATCH/m.db`, from empty), then `doctrine:schema:validate`. Then `docker compose exec php bin/console doctrine:migrations:migrate --no-interaction && docker compose exec php bin/console doctrine:schema:validate`. Expected: both report "in sync".
- [ ] **Step 5: Run** `php bin/phpunit tests/Entity tests/Service/Social tests/Repository/SocialNotificationRepositoryTest.php`. Expected: PASS.
- [ ] **Step 6: Commit** `feat(#2391): a notification subject carries post, answered and root key hashes`

### Task 3: The sources fill the keys

**Files:**
- Modify: `backend/src/Service/Social/Mastodon/MastodonNotificationSource.php`, `…/Mastodon/MastodonNotificationContextSource.php`, `…/Bluesky/BlueskyNotificationSource.php`, `…/Lemmy/LemmyNotificationSource.php`
- Test: `MastodonNotificationSourceTest`, `MastodonNotificationContextSourceTest`, `BlueskyNotificationSourceTest`, `LemmyNotificationSourceTest`

**Interfaces:**
- Consumes: Task 1's `parseKey`/`fromPage` helpers (use `?->keyHash()`); Task 2's `NotificationPostKeys` and `NotificationSubject::withKeys`.
- Fill the keys per the Decisions table. Mastodon's answered hash for a reply is set in `MastodonNotificationContextSource::findSubjects` from the answered status's `uri`, via `$subject->withKeys($subject->keys->withAnswered(...))`. It is set even when no excerpt changes, so add the row to the returned map whenever the answered status was found. Bluesky's answered/root hashes come from the `ContextReferenceModel` that `fetched()` already builds.

- [ ] **Step 1: Write the failing tests.** Add one per row of the Decisions table to the matching source test, each asserting the stored hash *equals the key rule's hash for an entry holding that post*, so notification and entry provably agree. For example, in `MastodonNotificationSourceTest`:

```php
public function testALikeCarriesTheLikedStatusKey(): void
{
    $fetched = $this->fetchOne(/* favourite of status uri https://mastodon.example/users/me/statuses/7 */);
    self::assertSame(
        MastodonStatusKey::parseKey('https://mastodon.example/@me/7')?->keyHash(),
        $fetched->subject->keys->postHash,
    );
    self::assertNull($fetched->subject->keys->answeredHash);
}
public function testAQuoteCarriesTheQuotedStatusAsAnswered(): void { /* answeredHash = key of quote.quoted_status.uri */ }
public function testAFollowCarriesNoKeys(): void { /* hashes() === [] */ }
```

Also add `MastodonNotificationContextSourceTest::testAReplyGainsTheAnsweredStatusKey`; Bluesky `testAReplyCarriesParentAndRoot`, `testALikeCarriesTheSubjectPost`; Lemmy `testAReplyCarriesThePostApIdKey` (expected value: `LemmyPostKey::parseKey($apId)?->keyHash()`).
- [ ] **Step 2: Run them and confirm they fail**: `php bin/phpunit --filter 'NotificationSourceTest|NotificationContextSourceTest'`.
- [ ] **Step 3: Implement.**
- [ ] **Step 4: Run the same filter.** Expected: PASS. Then run `composer md` on the touched files.
- [ ] **Step 5: Commit** `feat(#2391): notification sources store the canonical keys of the post, the answered post and the root`

### Task 4: Batched readable-entry lookup

**Files:**
- Modify: `backend/src/Repository/CanonicalItemRepository.php`
- Modify: `backend/src/Enum/CanonicalItemKind.php`
- Test: `backend/tests/Repository/CanonicalItemRepositoryTest.php`, `backend/tests/Enum/CanonicalItemKindTest.php`

**Interfaces:**
- Produces:
  - `CanonicalItemRepository::findReadableEntryIds(int $userId, CanonicalItemKind $kind, list<string> $keyHashes): array<string, int>`: key hash ⇒ the lowest id among entries with that `canonical_item_id` whose feed the user subscribes to. Hashes without a match are absent. Empty input means no query. One query: `canonical_item ⋈ entry ⋈ subscription (feed, user)`, `WHERE kind = :kind AND key_hash IN (:hashes)`, `GROUP BY key_hash`, `MIN(entry.id)`.
  - `CanonicalItemKind::fromNetwork(SocialNetwork $network): self`, an explicit `match`.

- [ ] **Step 1: Write the failing tests**

```php
public function testFindsTheLowestReadableCopyPerHash(): void   // two subscribed copies → lower id
public function testIgnoresAnotherUsersSubscription(): void     // entry only in a feed user B subscribes → absent for A
public function testIgnoresAnUnsubscribedFeedsEntry(): void     // entry exists, A's subscription removed → absent
public function testIgnoresTheSameHashOfAnotherKind(): void     // same key_hash under Lemmy, asked as Mastodon → absent
public function testAnEmptyAskMakesNoQuery(): void              // [] → []
```

- [ ] **Step 2: Run them and confirm they fail.** `php bin/phpunit tests/Repository/CanonicalItemRepositoryTest.php`
- [ ] **Step 3: Implement.**
- [ ] **Step 4: Run them on both legs**: `php bin/phpunit tests/Repository/CanonicalItemRepositoryTest.php` and `docker compose exec php bin/phpunit tests/Repository/CanonicalItemRepositoryTest.php`. Expected: PASS.
- [ ] **Step 5: Commit** `feat(#2391): find the lowest readable entry per canonical key hash`

### Task 5: The group answers `entryId`

**Files:**
- Modify: `backend/src/Service/Social/Notification/NotificationList.php`, `…/Model/NotificationGroupModel.php`, `backend/src/Http/SocialNotificationsJson.php`
- Test: `backend/tests/Controller/Api/SocialNotificationControllerTest.php`

**Interfaces:**
- Consumes: Task 4's `findReadableEntryIds` and `fromNetwork`; Task 2's `NotificationPostKeys::hashes()`.
- Produces: `NotificationGroupModel` gains `public ?int $entryId` (last parameter). The JSON gains `'entryId' => int|null`, which also goes into the `GroupJson` phpstan type. `NotificationList::page` collects every shown group's `subject->keys->hashes()`, makes one lookup per page, and gives each group the id of its first hash that resolved.

- [ ] **Step 1: Write the failing functional tests**, one per network, each with a stored notification row whose hashes are set and an entry with the matching `canonical_item`:

```php
public function testAMastodonLikeOnASubscribedPostAnswersItsEntryId(): void   // groups[0].entryId === $entry->requireId(), linkUrl unchanged
public function testABlueskyReplyResolvesThroughTheAnsweredPost(): void       // post hash has no entry, answered does → answered's entry id
public function testALemmyReplyAnswersThePostsEntryId(): void
public function testAPostOnlyInAnotherUsersFeedAnswersNoEntryId(): void       // entryId === null, linkUrl still set
public function testAFollowAnswersNoEntryId(): void
```

- [ ] **Step 2: Run them and confirm they fail.** `php bin/phpunit tests/Controller/Api/SocialNotificationControllerTest.php`
- [ ] **Step 3: Implement.** If PHPMD flags `NotificationList`, fix the design it points at rather than splitting to get under the count.
- [ ] **Step 4: Run** `php bin/phpunit tests/Controller/Api/SocialNotificationControllerTest.php`. Expected: PASS. Then run `composer check` and `composer md`.
- [ ] **Step 5: Commit** `feat(#2391): a notification group answers the readable entry its post resolves to`

### Task 6: A resolved row opens the entry

**Files:**
- Modify: `frontend/src/app/reader/notifications/notification.models.ts`, `…/notification-row/notification-row.component.{ts,html}`
- Test: `frontend/src/app/reader/notifications/notification-row/notification-row.component.spec.ts`

**Interfaces:**
- Consumes: `entryId: number | null` on `NotificationGroupDto`.
- Produces: a row with an `entryId` renders `<a class="row" [routerLink]="[]" [queryParams]="entryLink()" queryParamsHandling="merge">`, where `entryLink = computed(() => entryChangeParams(String(id)))` (from `reader/query/link-param.ts`). Otherwise the row renders the current external anchor. Both anchors share one body through an `<ng-template>` and `NgTemplateOutlet`; don't duplicate the markup. Classes and SCSS stay unchanged.

- [ ] **Step 1: Write the failing spec**

```ts
it('opens a resolved row in the reader, same tab', () => {
  render({ ...group, entryId: 514 });
  const link = row();
  expect(link.getAttribute('target')).toBeNull();
  expect(link.getAttribute('href')).toContain('entry=514');
});
it('keeps an unresolved row as an external link', () => {
  render({ ...group, entryId: null });
  expect(row().getAttribute('href')).toBe(group.linkUrl);
  expect(row().getAttribute('target')).toBe('_blank');
});
```

Add `entryId: null` to every existing `NotificationGroupDto` fixture.
- [ ] **Step 2: Run it and confirm it fails**: `docker compose exec -T frontend npx jest src/app/reader/notifications`.
- [ ] **Step 3: Implement.**
- [ ] **Step 4: Run** `docker compose exec -T frontend npm run check`. Expected: PASS.
- [ ] **Step 5: Live check** on http://localhost:4200: open the notifications view of a network with a resolved row and click it. The article opens over the list, Back returns to the list, and an unresolved row still opens a new tab. Take a screenshot of the opened entry.
- [ ] **Step 6: Commit** `feat(#2391): a notification row whose post is an entry opens it in the reader`

### Task 7: Throwaway backfill (never committed)

**Files:**
- Create (scratchpad only, not in the repo): `backfill-2391.php`

Follow the `strato-one-off-script-via-kernel-subclass` recipe in memory: a `require` of `<release>/vendor/autoload.php`, `bootEnv`, an `App\Kernel` subclass with its own cache dir and a compiler pass that makes the needed services public. Dry run by default; `--apply` writes. For every connected account:
- **Mastodon**: `MastodonNotificationSource::fetch()`, then `MastodonNotificationContextSource::findSubjects()` over the replies, for the answered hash.
- **Lemmy**: `LemmyNotificationSource::fetch()`.
- **Bluesky**: stored rows only. Post hash from `groupKey` (like, repost) or `remoteId` (others). Parent/root from `BlueskyPostLookup::byUri()` over the reply and quote remote ids, then `BlueskyReplyReferences::fromRecord()`. This bypasses `fetch()`'s unread shortcut.

Write the hashes with one `UPDATE social_notification SET … WHERE social_account_id = ? AND remote_id = ? AND subject_post_key_hash IS NULL`, and print a count per network: rows updated, rows left NULL.

- [ ] **Step 1: Dry run locally**: `docker compose cp <scratch>/backfill-2391.php php:/app/var/` then `docker compose exec php php /app/var/backfill-2391.php /app`. Expected: per-network counts, no writes.
- [ ] **Step 2: Apply locally** with `--apply`, then check that a known reply-to-your-post row now shows `entryId` in the notifications list response (the route `SocialNotificationController` serves).
- [ ] **Step 3: On Strato, only after the branch is merged and deployed** (the deploy is a separate go from Lars): run the dry run, then `--apply` over ssh as the recipe describes. Lars has allowed this run.
- [ ] **Step 4: Delete the script** from Docker `var/` and from Strato `~`.

### Finish

- [ ] Run both backend legs in parallel: `php bin/phpunit` and `docker compose exec php composer test:parallel`. Then `composer infection:diff`, and scan today's dev log.
- [ ] Run `/simplify`, then open a PR into `develop` with `Closes #2391`. Open a follow-up issue for scrolling to the comment.
