# Social Notifications Backend (#2240, PR 1 of 2) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Poll the notifications of every connected Bluesky, Mastodon and Lemmy account into a table of their own, and serve them as grouped rows with an unseen count and a "Mark all seen" that moves the network's own seen marker.

**Architecture:** A new module `App\Service\Social\Notification` holds a source per network behind a tagged interface, a store that upserts what a source read, a poll sweep run by the worker every 5 minutes (plus a maintenance tick sweep), a list reader that groups rows at read time, and a seen marker. Two new tables, `social_notification` and `social_notification_poll`, reference only `social_account`, with cascading deletes. Rows link out to the network; nothing here reads or writes feeds, entries or subscriptions.

**Tech Stack:** Symfony 8.1, PHP 8.5, Doctrine ORM (MySQL + SQLite), Symfony Scheduler and Messenger, PHPUnit 12 with `MockHttpClient`.

**Spec:** issue #2240 (https://github.com/larspohlmann/simple-feed-reader/issues/2240) and its "Settled for the plan" comment (https://github.com/larspohlmann/simple-feed-reader/issues/2240#issuecomment-6921751762). Where they differ, the comment wins.

## Global Constraints

- Scope: a quick look at whether anything is new, not a social network client. Rows link out; no in-app thread view, no reply or like from the list.
- Own tables only: `social_notification` and `social_notification_poll` reference only `social_account` (`ON DELETE CASCADE`). Nothing shared with feeds or entries.
- Kinds: `like`, `repost`, `reply`, `mention`, `quote`, `follow`, `poll` (Mastodon's poll ended). Bluesky `like-via-repost` → `like`, `repost-via-repost` → `repost`. Every other network kind is dropped on fetch.
- Lemmy private messages are never fetched, counted or marked; never call Lemmy's `mark_all_as_read`.
- Mastodon reads `GET /api/v1/notifications` only; v2 grouped notifications are not used.
- Poll: a scheduler entry every 5 minutes; an account is due 5 minutes after its last check; 20 accounts per worker firing, 1 per maintenance tick; each account isolated; 429s recorded through `HostThrottle`.
- Bluesky checks `getUnreadCount` before listing.
- A fetch reads one page of 50. Only notifications from the last 30 days are stored. The first fetch adopts the network's existing seen state.
- Retention 30 days, pruned by the worker.
- Grouping happens at read time: same account, same kind, same subject, same side of the seen line is one row. `reply`, `mention`, `quote` never group. `follow` groups with the account itself as subject.
- No automatic marking. "Mark all seen" moves the marker of every account on one network up to the newest notification the list has shown (`upTo`); anything newer stays unseen. A marker moved in the network's own app is read by the next poll.
- Links: Bluesky posts and profiles on `https://bsky.app`; Mastodon posts and profiles on the user's own instance (`{serviceUrl}/@{acct}/{status id}`, `{serviceUrl}/@{acct}`); Lemmy comments on the user's own instance (`{serviceUrl}/comment/{comment id}`).
- API (iOS-viable, JSON in, problem+json out): `GET /api/me/notifications?network=&cursor=` (keyset), `POST /api/me/notifications/seen {network, upTo}` → 204; unseen counts ride in the existing `GET /api/subscriptions/counts` response.
- A Mastodon token without the notifications scope is recorded by the poll and listed in the response so the client can show a reconnect hint; connecting is unchanged.
- Naive UTC datetimes; `NaiveUtcClock` for every persisted "now". `Response::HTTP_*` constants only. Entities and repositories `final`, services `final readonly`, one role per folder. No PHPStan ignores, no PHPMD suppressions.
- Commits `type(#2240): …`, no Claude attribution. The PR body says `Refs #2240`, never a closing keyword (PR 2 closes the issue).

## Review Focus

1. **The seen state moved in the native app, including rows older than the fetched page.** Every stored row at or before the network's marker must read as seen after the next poll, not only the 50 just fetched. Pinned in Task 5: `testSeenUntilMarksOlderStoredRowsSeen`.
2. **A notification arrives between the list rendering and the click on "Mark all seen".** It must stay unseen, here and on the network. Pinned in Task 8: `testMarksNothingNewerThanUpTo`.
3. **A Mastodon token without the notifications scope.** The account is flagged, not marked as needing a reconnect, and the other accounts still get polled. Pinned in Task 6: `testScopeMissingFlagsTheAccountAndContinues`.
4. **A 429 in the middle of Lemmy's one-by-one marking.** What Lemmy already marked reads as seen here; the rest stays unseen; the caller gets the throttle. Pinned in Task 8: `testLemmyThrottleKeepsWhatWasMarked`.
5. **A throttled host is skipped but never stamped,** so it stays first in the due order and starves every other account. A skip must stamp the account as checked. Pinned in Task 6: `testThrottledHostIsStampedSoOthersGetTheirTurn`.

---

## File Structure

Module `backend/src/Service/Social/Notification/`:

| Path | Responsibility |
|---|---|
| `NotificationSource/NotificationSourceInterface.php` | One page of an account's notifications in, the seen marker out; tagged `app.social_notification_source`, keyed by `SocialNetwork` value |
| `NotificationSource/BlueskyNotificationSource.php`, `MastodonNotificationSource.php`, `LemmyNotificationSource.php` | One per network |
| `Model/FetchedNotificationModel.php`, `Model/NotificationPageModel.php` | What a source read |
| `Model/NotificationGroupModel.php`, `Model/NotificationListPageModel.php` | What the list serves |
| `Model/NotificationPollBudgetModel.php`, `Model/NotificationPollReportModel.php` | Sweep budget and report |
| `Support/Excerpt.php` | Cuts subject text to 280 characters |
| `Support/MastodonIds.php` | Orders Mastodon's numeric string ids |
| `NotificationStore.php` | Upserts a page, applies the seen line, prunes |
| `NotificationPoll.php` | Polls one account |
| `NotificationPollSweep.php` | Polls the due accounts, each isolated |
| `NotificationList.php` | A grouped page for one network |
| `NotificationSeenMarker.php` | "Mark all seen" for one network |

Elsewhere in `backend/`:

| Path | Responsibility |
|---|---|
| `src/Enum/SocialNotificationKind.php` | The seven kinds |
| `src/Entity/NotificationActor.php`, `src/Entity/NotificationSubject.php` | Embeddables an entity stores |
| `src/Entity/SocialNotification.php`, `src/Entity/SocialNotificationPoll.php` | The two tables |
| `src/Repository/SocialNotificationRepository.php`, `src/Repository/SocialNotificationPollRepository.php` | Every query |
| `migrations/Version20261117120000.php` | Both tables, MySQL and SQLite |
| `src/Service/Operations/Worker/Message/PollSocialNotifications.php`, `src/Service/Operations/Worker/Handler/PollSocialNotificationsHandler.php` | Worker entry |
| `src/Service/Operations/Maintenance/MaintenanceSweep/NotificationPollTickSweep.php` | Tick fallback |
| `src/Controller/Api/SocialNotificationController.php` | The two endpoints |
| `src/Dto/Social/MarkNotificationsSeenRequest.php` | Body of the seen call |
| `src/Http/SocialNotificationsJson.php` | List response mapper |

---

### Task 1: Tables, entities, enum

**Files:**
- Create: `backend/src/Enum/SocialNotificationKind.php`, `backend/src/Entity/NotificationActor.php`, `backend/src/Entity/NotificationSubject.php`, `backend/src/Entity/SocialNotification.php`, `backend/src/Entity/SocialNotificationPoll.php`, `backend/src/Repository/SocialNotificationRepository.php`, `backend/src/Repository/SocialNotificationPollRepository.php` (constructor only), `backend/migrations/Version20261117120000.php`
- Modify: `backend/tests/Service/Operations/Backup/Support/BackupSchemaCoverageTest.php` (`ACCOUNT_SCOPED_WHOLLY_DROPPED`), `docs/backup.md` §6.2 table
- Test: `backend/tests/Enum/SocialNotificationKindTest.php`, `backend/tests/Entity/SocialNotificationPollTest.php`, `backend/tests/Migrations/Version20261117120000Test.php`

**Interfaces:**
- Produces:
  - `enum SocialNotificationKind: string` with cases `Like='like'`, `Repost='repost'`, `Reply='reply'`, `Mention='mention'`, `Quote='quote'`, `Follow='follow'`, `Poll='poll'`, and `groups(): bool` (true for Like, Repost, Follow, Poll).
  - `#[ORM\Embeddable] final readonly class NotificationActor(string $handle, ?string $displayName, ?string $avatarUrl, string $profileUrl)` with public properties.
  - `#[ORM\Embeddable] final readonly class NotificationSubject(string $groupKey, ?string $excerpt, string $linkUrl)` with public properties.
  - `SocialNotification::__construct(SocialAccount $socialAccount, string $remoteId, SocialNotificationKind $kind, NotificationActor $actor, NotificationSubject $subject, \DateTimeImmutable $createdAt, bool $seen)`; getters `getId()`, `requireId()`, `getSocialAccount()`, `getRemoteId()`, `getKind()`, `getActor()`, `getSubject()`, `getCreatedAt()`, `isSeen()`; `markSeen(): void`.
  - `SocialNotificationPoll::__construct(SocialAccount $socialAccount)`; `recordChecked(\DateTimeImmutable $at): void` (stamps and clears the scope flag); `recordScopeMissing(\DateTimeImmutable $at): void` (stamps and sets it); `isScopeMissing(): bool`; `getCheckedAt(): ?\DateTimeImmutable`; `getSocialAccount(): SocialAccount`.

`social_notification` columns: `id` INT AI; `social_account_id` INT NOT NULL FK CASCADE; `remote_id` VARCHAR(512); `kind` VARCHAR(16); `actor_handle` VARCHAR(255); `actor_display_name` VARCHAR(255) NULL; `actor_avatar_url` VARCHAR(2048) NULL; `actor_profile_url` VARCHAR(2048); `subject_group_key` VARCHAR(512); `subject_excerpt` VARCHAR(500) NULL; `subject_link_url` VARCHAR(2048); `created_at` DATETIME; `seen` BOOLEAN. Keys: `UNIQUE uniq_social_notification_account_remote (social_account_id, remote_id)`, `INDEX idx_social_notification_group (social_account_id, seen, kind, subject_group_key)`, `INDEX idx_social_notification_created (created_at)`.

`social_notification_poll` columns: `id` INT AI; `social_account_id` INT NOT NULL UNIQUE FK CASCADE; `checked_at` DATETIME NULL; `scope_missing` BOOLEAN NOT NULL DEFAULT 0.

- [ ] **Step 1: Write the failing tests**

```php
// SocialNotificationKindTest
public function testOnlyLikeRepostFollowPollGroup(): void
{
    $grouping = array_map(static fn (SocialNotificationKind $kind): bool => $kind->groups(), SocialNotificationKind::cases());
    self::assertSame([true, true, false, false, false, true, true], $grouping); // Like Repost Reply Mention Quote Follow Poll
}

// SocialNotificationPollTest
public function testCheckedClearsAnEarlierScopeFlag(): void
{
    $poll = new SocialNotificationPoll(SocialAccounts::mastodon());
    $poll->recordScopeMissing(new \DateTimeImmutable('2026-11-17 10:00'));
    self::assertTrue($poll->isScopeMissing());
    $poll->recordChecked(new \DateTimeImmutable('2026-11-17 10:05'));
    self::assertFalse($poll->isScopeMissing());
    self::assertEquals(new \DateTimeImmutable('2026-11-17 10:05'), $poll->getCheckedAt());
}

// Version20261117120000Test — follow Version20261116220000Test: run down, then up, on the test connection
public function testUpCreatesBothTablesAndDownDropsThem(): void
// asserts tablesExist(['social_notification', 'social_notification_poll']) after up, false after down,
// and that the indexes uniq_social_notification_account_remote and idx_social_notification_group exist after up
```

- [ ] **Step 2: Run** `cd backend && php bin/phpunit --filter 'SocialNotificationKind|SocialNotificationPoll|Version20261117120000'`. Expected: FAIL, classes not found.
- [ ] **Step 3: Implement.** The migration follows `Version20261114120000`: `TABLE` constants, `skipIf($schema->hasTable(...))`, `abortIfUnsupportedPlatform()`, `isTransactional(): false`, `upOnMySql()` (utf8mb4, `ALTER TABLE … ADD CONSTRAINT … ON DELETE CASCADE`) and `upOnSqlite()` (inline `CONSTRAINT … ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE`); `down()` drops the poll table first. FK and plain-index names are the ones Doctrine generates, so `doctrine:schema:validate` stays in sync.
- [ ] **Step 4: Add both entities to `ACCOUNT_SCOPED_WHOLLY_DROPPED`** and a `docs/backup.md` §6.2 row each: `SocialNotification` — "Notifications of a connected account (#2240); the network keeps them, the next poll refills them." `SocialNotificationPoll` — "When an account's notifications were last checked (#2240); account telemetry."
- [ ] **Step 5: Run** the Step 2 filter plus `BackupSchemaCoverage`, then validate the mapping against a schema built by migrating a fresh SQLite file from empty (`DATABASE_URL=sqlite:///$PWD/var/validate.db bin/console doctrine:migrations:migrate -n --env=test && DATABASE_URL=… bin/console doctrine:schema:validate --env=test`, then delete the file). Expected: PASS, "in sync".
- [ ] **Step 6: Commit** `feat(#2240): store social notifications in a table of their own`.

### Task 2: Source interface and the Bluesky source

**Files:**
- Create: `NotificationSource/NotificationSourceInterface.php`, `NotificationSource/BlueskyNotificationSource.php`, `Model/FetchedNotificationModel.php`, `Model/NotificationPageModel.php`, `Support/Excerpt.php` (all in the module)
- Test: `backend/tests/Service/Social/Notification/NotificationSource/BlueskyNotificationSourceTest.php`, `backend/tests/Service/Social/Notification/Model/NotificationPageModelTest.php`, fixtures `backend/tests/Fixtures/Bluesky/notifications-list.json`, `notifications-unread.json`, `notifications-posts.json`

**Interfaces:**
- Consumes: Task 1's enum, embeddables, `SocialNotification`.
- Produces:
  - `#[AutoconfigureTag('app.social_notification_source')] interface NotificationSourceInterface`:
    - `fetch(SocialAccount $account): NotificationPageModel` — throws `SocialCredentialsRejectedException`, `SocialTokenScopeMissingException`, `SocialNetworkThrottledException`, `SocialNetworkUnreachableException`.
    - `markSeen(SocialAccount $account, list<SocialNotification> $unseen, \Closure $marked): void` — `$unseen` is non-empty, all of `$account`, ordered by id. The source moves the network's marker to the newest of them and calls `$marked($row)` for every row the network now holds as seen. Same four throws.
  - Implementations carry `#[AsTaggedItem(index: SocialNetwork::<Case>->value)]`; consumers take `#[AutowireLocator('app.social_notification_source')] ContainerInterface $sources` and get by `$account->getNetwork()->value` (the `SocialPostFinder` pattern).
  - `FetchedNotificationModel(string $remoteId, SocialNotificationKind $kind, NotificationActor $actor, NotificationSubject $subject, \DateTimeImmutable $createdAt, bool $seen)`.
  - `NotificationPageModel(list<FetchedNotificationModel> $notifications, ?\DateTimeImmutable $seenUntil)` — `$seenUntil`: every stored row created at or before it is seen. `oldestFirst(): list<FetchedNotificationModel>` sorts by `createdAt`, then `remoteId`.
  - `Excerpt::of(?string $text): ?string` — trimmed, cut to 280 characters with `mb_substr`, null for null or empty.

Bluesky mapping (`BlueskySessions::askAs` + `BlueskySessions::accepted`; `actAs` to write):
- `fetch` first asks `/xrpc/app.bsky.notification.getUnreadCount`. A `count` of 0 returns `new NotificationPageModel([], $clock->now())` (`NaiveUtcClock`): nothing unread, so every stored row is seen. Otherwise it asks `/xrpc/app.bsky.notification.listNotifications?limit=50`.
- `reason` → kind: `like`/`like-via-repost` → Like, `repost`/`repost-via-repost` → Repost, `reply`, `mention`, `quote`, `follow` as named; any other reason is dropped.
- `remoteId` = `uri`; `seen` = `isRead`; `createdAt` = `indexedAt` converted to UTC, naive; `seenUntil` = top-level `seenAt` in UTC, or null when absent.
- Actor: `handle` = `author.handle`, `displayName` = `author.displayName`, `avatarUrl` = `author.avatar`, `profileUrl` = `https://bsky.app/profile/{author.did}`.
- Subject: Like and Repost — `groupKey` = `reasonSubject`, `linkUrl` = `AtPostUri::webUrl(reasonSubject)` (fallback `https://bsky.app/profile/{account identity}`), `excerpt` = the subject post's `record.text` from `/xrpc/app.bsky.feed.getPosts?` + one `uris=<rawurlencode(uri)>` pair per subject joined by `&` (XRPC repeats the bare key; `http_build_query` would write `uris[0]=`), at most `AppViewClient::URIS_PER_CALL` distinct subjects per call, null for a post the answer lacks. Reply, Mention, Quote — `groupKey` = `uri`, `linkUrl` = `AtPostUri::webUrl(uri)`, `excerpt` = `record.text`. Follow — `groupKey` = `''`, `linkUrl` = the actor's profile URL, `excerpt` = null. Every excerpt goes through `Excerpt::of`.
- `markSeen`: `actAs($account, '/xrpc/app.bsky.notification.updateSeen', ['seenAt' => <max createdAt of $unseen as UTC DATE_ATOM>])`, then `$marked` for each row.

- [ ] **Step 1: Write the failing tests** (`MockHttpClient` through the real `BlueskySessions` wiring, as `BlueskySessionsTest` builds it):
  - `NotificationPageModelTest::testOldestFirstSortsByCreatedAtThenRemoteId` — three models, two sharing a `createdAt`; asserts the remote-id order `['a', 'b', 'c']` where `a` and `b` tie.
  - `testMapsEveryKeptKind` — the list fixture holds one of each kept reason, `like-via-repost` and `repost-via-repost` included; asserts the kinds in page order and, for the like, `groupKey` = its `reasonSubject`, `linkUrl` = `https://bsky.app/profile/<did>/post/<rkey>`, excerpt from the getPosts fixture.
  - `testDropsUnlistedKinds` — a `starterpack-joined` entry is absent from the page.
  - `testSeenComesFromTheNetwork` — `seen` mirrors `isRead`; `seenUntil` equals the fixture's `seenAt` in UTC.
  - `testZeroUnreadSkipsTheList` — unread count 0: exactly one request is made, the page is empty, `seenUntil` equals the `MockClock` now.
  - `testMarkSeenSendsTheNewestMarker` — two rows; the request body is `{"seenAt": "<newer createdAt>"}`; `$marked` was called for both.
- [ ] **Step 2: Run** `php bin/phpunit --filter 'BlueskyNotificationSource|NotificationPageModel'`. Expected: FAIL.
- [ ] **Step 3: Implement** the interface, models, `Excerpt` and the Bluesky source.
- [ ] **Step 4: Run** the same filter. Expected: PASS.
- [ ] **Step 5: Commit** `feat(#2240): read bluesky notifications`.

### Task 3: Mastodon source

**Files:**
- Create: `NotificationSource/MastodonNotificationSource.php`, `Support/MastodonIds.php`
- Test: `backend/tests/Service/Social/Notification/NotificationSource/MastodonNotificationSourceTest.php`, `backend/tests/Service/Social/Notification/Support/MastodonIdsTest.php`, fixtures `backend/tests/Fixtures/Mastodon/notifications-list.json`, `notifications-markers.json`, `notifications-marker-item.json`, `verify-credentials-notifications.json`

**Interfaces:**
- Consumes: Task 2's interface, models, `Excerpt`.
- Produces: `MastodonIds::atOrBefore(string $id, string $other): bool` and `MastodonIds::newest(list<string> $ids): string` (numeric strings: compare by length, then lexicographically).

Mapping (reads through `MastodonAccountApi::askAboutPost`, which already throws `SocialTokenScopeMissingException` on a 403 "outside the authorized scopes" and `SocialCredentialsRejectedException` on a 401; a `SocialPostNotFoundException` or `SocialPostRefusedException` from it is rethrown as `SocialNetworkUnreachableException`):
- `fetch` asks, in order: `/api/v1/accounts/verify_credentials` (own account `id`), `/api/v1/markers?timeline[]=notifications`, `/api/v1/notifications?limit=50&types[]=mention&types[]=favourite&types[]=reblog&types[]=follow&types[]=poll&types[]=quote`.
- Type → kind: `favourite` → Like, `reblog` → Repost, `follow` → Follow, `poll` → Poll, `quote` → Quote, `mention` → Reply when `status.in_reply_to_account_id` equals the own account id, else Mention. Any other type is dropped.
- `remoteId` = `id`; `createdAt` = `created_at` in UTC; `seen` = `MastodonIds::atOrBefore(id, markers.notifications.last_read_id)` (false when there is no marker).
- `seenUntil` = the `created_at` of the notification whose id is `last_read_id`: taken from the page when it holds it, else from one `GET /api/v1/notifications/{last_read_id}`; null when there is no marker or that answer is not found.
- Actor: `handle` = `account.acct`, `displayName` = `account.display_name` (null when empty), `avatarUrl` = `account.avatar`, `profileUrl` = `{serviceUrl}/@{account.acct}`.
- Subject: Like, Repost, Poll — `groupKey` = `status.uri`; Reply, Mention, Quote — `groupKey` = `id`; Follow — `groupKey` = `''`. `linkUrl` = `{serviceUrl}/@{status.account.acct}/{status.id}`, or the actor's profile URL for a follow. `excerpt` = `Excerpt::of(PlainText::fromHtmlBlocks(status.content))`.
- `markSeen`: `MastodonAccountApi::writeAs($account, ApiRequestModel::postJson($account->getServiceUrl() . '/api/v1/markers', ['notifications' => ['last_read_id' => MastodonIds::newest(<remote ids of $unseen>)]]), MastodonAccountApi::STATUSES_SCOPE)`, then `$marked` for each row.

- [ ] **Step 1: Write the failing tests**:
  - `MastodonIdsTest::testComparesByLengthThenDigits` — `atOrBefore('99', '100')` true, `atOrBefore('101', '100')` false, `atOrBefore('100', '100')` true; `newest(['9', '110', '101'])` is `'110'`.
  - `testMapsEveryKeptKind` — kinds in page order; the favourite's `linkUrl` is `https://mastodon.example/@alice/1100`, its `groupKey` the status `uri`; the follow's `linkUrl` is `https://mastodon.example/@bob@other.example`.
  - `testDropsUnlistedKinds` — an `admin.sign_up` entry is absent.
  - `testReplyToOwnAccountIsReply` — a mention whose `in_reply_to_account_id` is the verified id maps to Reply; another to Mention.
  - `testSeenComesFromTheNetwork` — ids at or before `last_read_id` are seen; `seenUntil` is the marker item's `created_at`, read from `notifications-marker-item.json` when the page lacks it.
  - `testScopeRefusalThrowsScopeMissing` — a 403 `{"error":"This action is outside the authorized scopes"}` on the list throws `SocialTokenScopeMissingException`.
  - `testMarkSeenSendsTheNewestMarker` — rows `'99'` and `'100'`: the JSON body is `{"notifications":{"last_read_id":"100"}}`.
- [ ] **Step 2: Run** `php bin/phpunit --filter 'MastodonNotificationSource|MastodonIds'`. Expected: FAIL.
- [ ] **Step 3: Implement.**
- [ ] **Step 4: Run** the same filter. Expected: PASS.
- [ ] **Step 5: Commit** `feat(#2240): read mastodon notifications`.

### Task 4: Lemmy source

**Files:**
- Create: `NotificationSource/LemmyNotificationSource.php`
- Test: `backend/tests/Service/Social/Notification/NotificationSource/LemmyNotificationSourceTest.php`, fixtures `backend/tests/Fixtures/Lemmy/notifications-replies.json`, `notifications-mentions.json`

**Interfaces:**
- Consumes: Task 2's interface, models, `Excerpt`.

Mapping (`LemmyAccountApi::get` / `post`; its `SocialPostRefusedException` and `SocialPostNotFoundException` are rethrown as `SocialNetworkUnreachableException`):
- `fetch` asks `/api/v3/user/replies?sort=New&limit=50&unread_only=false` and `/api/v3/user/mention?sort=New&limit=50&unread_only=false`. `seenUntil` = null (Lemmy has no marker).
- Replies: `remoteId` = `reply:{comment_reply.id}`, kind Reply, `seen` = `comment_reply.read`, `createdAt` = `comment_reply.published` in UTC. Mentions: `remoteId` = `mention:{person_mention.id}`, kind Mention, `seen` = `person_mention.read`, `createdAt` = `person_mention.published`.
- Actor: `handle` = `{creator.name}@{host of creator.actor_id}`, `displayName` = `creator.display_name`, `avatarUrl` = `creator.avatar`, `profileUrl` = `{serviceUrl}/u/{creator.name}@{host}`.
- Subject: `groupKey` = the remote id, `linkUrl` = `{serviceUrl}/comment/{comment.id}`, `excerpt` = `Excerpt::of(comment.content)` (markdown, kept raw).
- `markSeen`: for each row, oldest first, `POST /api/v3/comment/mark_as_read {"comment_reply_id": <id>, "read": true}` or `POST /api/v3/user/mention/mark_as_read {"person_mention_id": <id>, "read": true}`, then `$marked($row)`. A thrown exception stops the loop and propagates.

- [ ] **Step 1: Write the failing tests**:
  - `testMapsRepliesAndMentions` — remote ids `reply:7` and `mention:3`; the reply's `linkUrl` is `https://lemmy.example/comment/41`; the actor handle `carol@lemmy.other`.
  - `testSeenComesFromTheNetwork` — `seen` mirrors `read`; `seenUntil` is null.
  - `testNeverAsksForPrivateMessages` — no request path contains `private_message`.
  - `testMarkSeenMarksEachItemAndReportsEach` — one reply and one mention: two POSTs with the bodies above; `$marked` called twice in order.
  - `testThrottleStopsTheMarking` — second POST answers 429: `SocialNetworkThrottledException` propagates; `$marked` was called once.
- [ ] **Step 2: Run** `php bin/phpunit --filter LemmyNotificationSource`. Expected: FAIL.
- [ ] **Step 3: Implement.**
- [ ] **Step 4: Run** the same filter. Expected: PASS.
- [ ] **Step 5: Commit** `feat(#2240): read lemmy notifications`.

### Task 5: Notification store

**Files:**
- Create: `NotificationStore.php` (module)
- Modify: `backend/src/Repository/SocialNotificationRepository.php`
- Test: `backend/tests/Service/Social/Notification/NotificationStoreTest.php` (`DbTestCase`)

**Interfaces:**
- Consumes: Task 2's `NotificationPageModel`, `FetchedNotificationModel`.
- Produces:
  - `NotificationStore::record(SocialAccount $account, NotificationPageModel $page): int` — new rows stored. Skips fetched notifications created before `now - 30 days`; inserts new ones in `oldestFirst()` order so ids ascend with time; for a remote id it already holds, marks the row seen when the fetched one is seen and the stored one is not (a seen row never turns unseen); then applies `seenUntil` through `markSeenUpTo`; one flush.
  - `NotificationStore::prune(): int` — deletes rows created before `now - 30 days`.
  - Repository: `findByRemoteIds(SocialAccount $account, list<string> $remoteIds): array<string, SocialNotification>`; `markSeenUpTo(SocialAccount $account, \DateTimeImmutable $until): void` (bulk DQL `UPDATE … SET seen = true WHERE socialAccount = :account AND createdAt <= :until AND seen = false`); `deleteCreatedBefore(\DateTimeImmutable $cutoff): int`.
  - `public const string RETENTION = '-30 days';` on the store.

- [ ] **Step 1: Write the failing tests** (`MockClock` at `2026-11-17 12:00`):
  - `testInsertsNewRowsOldestFirst` — a page listed newest first; reloaded rows ordered by id have ascending `createdAt`; `record` returns 3.
  - `testRefetchUpdatesSeenWithoutDuplicating` — record a row unseen, record it again seen: one row in the table, `isSeen()` true, `record` returned 0 the second time.
  - `testSeenUntilMarksOlderStoredRowsSeen` — store rows at 09:00 and 11:00 unseen, record an empty page with `seenUntil` 10:00; after `clear()`, the 09:00 row is seen and the 11:00 row is not.
  - `testSkipsNotificationsOlderThanThirtyDays` — a fetched row at `2026-10-17 11:59` is not stored.
  - `testPruneDeletesOnlyRowsOlderThanThirtyDays` — rows at `2026-10-17 11:59` and `2026-10-17 12:01`: `prune()` returns 1 and the newer stays.
- [ ] **Step 2: Run** `php bin/phpunit --filter NotificationStore`. Expected: FAIL.
- [ ] **Step 3: Implement.** Tests reload with `$entityManager->clear()` after the bulk update.
- [ ] **Step 4: Run** the filter on SQLite and on MySQL (`docker compose exec php php bin/phpunit --filter NotificationStore`). Expected: PASS on both.
- [ ] **Step 5: Commit** `feat(#2240): keep a page of notifications per account`.

### Task 6: Poll, sweep, worker entry, tick sweep

**Files:**
- Create: `NotificationPoll.php`, `NotificationPollSweep.php`, `Model/NotificationPollBudgetModel.php`, `Model/NotificationPollReportModel.php` (module); `backend/src/Service/Operations/Worker/Message/PollSocialNotifications.php`, `backend/src/Service/Operations/Worker/Handler/PollSocialNotificationsHandler.php`, `backend/src/Service/Operations/Maintenance/MaintenanceSweep/NotificationPollTickSweep.php`
- Modify: `backend/src/Repository/SocialNotificationPollRepository.php`, `backend/src/Service/Operations/Worker/WorkerSchedule.php` (append last: `->add(RecurringMessage::every('5 minutes', new PollSocialNotifications()))`)
- Test: `backend/tests/Service/Social/Notification/NotificationPollSweepTest.php` (`DbTestCase`, fake sources registered under the tag's keys), `backend/tests/Repository/SocialNotificationPollRepositoryTest.php`, `backend/tests/Service/Operations/Worker/PollSocialNotificationsHandlerTest.php`, extend `backend/tests/Service/Operations/Worker/WorkerScheduleWiringTest.php`

**Interfaces:**
- Consumes: Tasks 1–5.
- Produces:
  - `SocialNotificationPollRepository::findAccountIdsDue(\DateTimeImmutable $now, int $limit): list<int>` — `SocialAccount` with `status = connected`, owner restricted by `ActiveAccountDql::restrict`, `LEFT JOIN` its poll row; due when there is no poll row or `checkedAt < now - 5 minutes`; never-checked first, then oldest `checkedAt`, then id.
  - `SocialNotificationPollRepository::stampOf(SocialAccount $account): SocialNotificationPoll` — finds the row or persists a new one.
  - `NotificationPoll::poll(SocialAccount $account): int` (new rows):
    - `HostThrottle::remainingSeconds($account->getServiceUrl()) > 0` → `recordChecked`, flush, return 0.
    - Otherwise `ActingAccountGuard::actAs($account, fn () => $source->fetch($account))`, `NotificationStore::record`, `recordChecked`, flush.
    - `SocialTokenScopeMissingException` → `recordScopeMissing`, flush, return 0 (no rethrow, no reconnect mark).
    - `SocialNetworkThrottledException` → `HostThrottle::record($account->getServiceUrl(), $exception->retryAfterSeconds)`, `recordChecked`, flush, return 0.
  - `NotificationPollSweep::sweep(NotificationPollBudgetModel $budget): NotificationPollReportModel` — the `PrivateFeedSyncSweep` shape: each account in its own try/catch; a failure is logged (`'Notification poll failed for an account.'`, `socialAccountId`, `exception`), the account stamped checked in a guarded second step, the entity manager cleared if open; `ManagerRegistry::resetManager()` when it closed, stopping when it stays closed. After the accounts, `NotificationStore::prune()` once.
  - `NotificationPollBudgetModel` mirrors `PrivateFeedSyncBudgetModel` (`accounts(int)`, `fittingBefore(\DateTimeImmutable $deadline, \DateTimeImmutable $now, int $maxAccounts)`, `isEmpty()`, public `int $accounts`).
  - `NotificationPollReportModel`: `none()`, `addingPolled(int $newRows): self`, `addingFailure(): self`, `withPruned(int $pruned): self`, `toLogContext(): array{accounts: int, failed: int, newRows: int, pruned: int}`.
  - Handler: `ACCOUNTS = 20`, logs `'Worker notification poll finished.'` with the report. Tick sweep: `#[AsTaggedItem(index: 'notificationPoll', priority: 15)]`, `MAX_ACCOUNTS = 1`, `idleReport()` = `none()`.

- [ ] **Step 1: Write the failing tests**:
  - `SocialNotificationPollRepositoryTest::testDueOrderAndExclusions` — accounts never checked, checked 6 min ago, checked 4 min ago, needing reconnect, of a deactivated owner: returns the first two in that order.
  - `testPollsDueAccountsOnly` — the source fake is called for the due account only.
  - `testScopeMissingFlagsTheAccountAndContinues` — two accounts, the first source throws scope-missing: the first is flagged (`isScopeMissing()`), its status still `Connected`; the second's rows are stored; both stamped.
  - `testThrottledHostIsStampedSoOthersGetTheirTurn` — first account's host has a recorded wait: its source is never called, it is stamped; a `findAccountIdsDue` right after returns only accounts not yet stamped.
  - `testThrottleRecordsTheHostWait` — source throws `new SocialNetworkThrottledException('…', 120)`: `HostThrottle::remainingSeconds` for that host is > 0 and the account is stamped.
  - `testOneAccountFailingDoesNotStopTheSweep` — the first source throws `\RuntimeException`: the second still polled, the report's `failed` is 1, the first stamped.
  - `testPrunesAfterTheAccounts` — a 31-day-old row is gone after the sweep; report `pruned` 1.
  - `PollSocialNotificationsHandlerTest::testSweepsTwentyAccounts` — 21 due accounts: 20 polled.
  - `WorkerScheduleWiringTest::testPollSocialNotificationsIsTheLastEntryEveryFiveMinutes`.
- [ ] **Step 2: Run** `php bin/phpunit --filter 'NotificationPoll|SocialNotificationPollRepository|PollSocialNotifications|WorkerScheduleWiring'`. Expected: FAIL.
- [ ] **Step 3: Implement.**
- [ ] **Step 4: Run** the same filter on SQLite and MySQL. Expected: PASS on both.
- [ ] **Step 5: Commit** `feat(#2240): poll notifications every five minutes`.

### Task 7: Grouped list and unseen counts

**Files:**
- Create: `NotificationList.php`, `Model/NotificationGroupModel.php`, `Model/NotificationListPageModel.php` (module), `backend/src/Http/SocialNotificationsJson.php`, `backend/src/Controller/Api/SocialNotificationController.php` (the `list` action)
- Modify: `backend/src/Repository/SocialNotificationRepository.php`, `backend/src/Repository/SocialNotificationPollRepository.php`, `backend/src/Service/Library/Subscription/SubscriptionTallyReader.php`, `backend/src/Service/Library/Subscription/Model/SubscriptionTalliesModel.php`, `backend/src/Http/SubscriptionCountsJson.php`, the `new SubscriptionTalliesModel(` call sites in `backend/tests/Http/`
- Test: `backend/tests/Repository/SocialNotificationRepositoryTest.php`, `backend/tests/Controller/Api/SocialNotificationControllerTest.php` (`ApiTestCase`), `backend/tests/Http/SubscriptionCountsJsonTest.php`

**Interfaces:**
- Consumes: Task 1 entities, Task 5 store (to seed rows in tests).
- Produces:
  - `SocialNotificationRepository::pageOfGroups(list<int> $accountIds, ?int $before, int $limit): list<array{accountId: int, kind: string, groupKey: string, seen: bool, latestId: int, count: int}>` — rows of those accounts; a group is `(socialAccount, kind, subject.groupKey, seen)` for kinds where `groups()` is true, and the single row (`groupKey` = remote id) otherwise; ordered by `MAX(id) DESC`; `HAVING MAX(id) < :before` when `$before` is set; `$limit + 1` rows so the caller knows whether a next page exists.
  - `SocialNotificationRepository::newestOfGroups(list<array{…}> $groups, int $perGroup): array<int, list<SocialNotification>>` — per group, keyed by `latestId`, its `$perGroup` newest rows (newest first).
  - `SocialNotificationRepository::countUnseenGroups(User $user): array<string, int>` — distinct unseen groups per network value for the user's accounts; networks without unseen rows are absent.
  - `SocialNotificationPollRepository::scopeMissingHandles(list<int> $accountIds): list<string>`.
  - `NotificationList::page(User $user, SocialNetwork $network, ?int $cursor): NotificationListPageModel` — accounts from `SocialAccountRepository::findAllOnNetwork($user, $network)`; none → empty page; `PAGE_SIZE = 30`; `ACTORS_PER_GROUP = 3`.
  - `NotificationGroupModel(int $latestId, SocialNotificationKind $kind, string $accountHandle, list<NotificationActor> $actors, int $actorCount, NotificationSubject $subject, \DateTimeImmutable $latestAt, bool $seen)` — `$subject` and `$latestAt` from the group's newest row.
  - `NotificationListPageModel(list<NotificationGroupModel> $groups, ?int $nextCursor, list<string> $scopeMissingHandles)` — `$nextCursor` = the last group's `latestId` when a further page exists, else null.
  - `SubscriptionTalliesModel` gains `public array $notificationsUnseen` (`array<string, int>`, network value → unseen groups), filled by `SubscriptionTallyReader` from `countUnseenGroups`.
  - `SubscriptionCountsJson::surfaceTotals()` gains `'notifications' => list<array{network: string, unseenCount: int}>`, one entry per network present in the map, in `SocialNetwork::cases()` order.
  - `GET /api/me/notifications` (`api_me_notifications`): `#[MapQueryParameter] SocialNetwork $network`, `#[MapQueryParameter] ?int $cursor = null`, `#[CurrentUser] User $user`. No rate limiter: a scoped, indexed read of the user's own rows.
  - Response shape (`SocialNotificationsJson::page()`), datetimes as `DATE_ATOM` in UTC:

```json
{"groups":[{"id":123,"kind":"like","account":"@me.bsky.social","actors":[{"handle":"anna.bsky.social","displayName":"Anna","avatarUrl":"https://…","profileUrl":"https://bsky.app/profile/did:plc:…"}],"actorCount":12,"excerpt":"…","linkUrl":"https://bsky.app/profile/…/post/…","latestAt":"2026-11-16T12:00:00+00:00","seen":false}],"nextCursor":122,"scopeMissing":["@me@mastodon.example"]}
```

- [ ] **Step 1: Write the failing tests**:
  - `testLikesOnOnePostAfterTheSeenLineAreOneGroup` — three unseen likes on subject `at://x/1`: one group, `count` 3.
  - `testSeenAndUnseenLikesOnOnePostAreTwoGroups` — two seen and one unseen like on one subject: two groups, the unseen one first when its row is newest.
  - `testRepliesNeverGroup` — two replies on one post: two groups.
  - `testFollowsGroupAcrossActors` — three unseen follows: one group, `count` 3.
  - `testCursorPagesByLatestId` — 4 groups, limit 2: page 1 returns 3 rows; with `before` = second group's `latestId`, the remaining 2.
  - `testOtherUsersAccountsNeverShow` — another user's rows on the same network: absent from the list and the count.
  - `testCountsUnseenGroupsPerNetwork` — 3 unseen likes on one post and 1 unseen Mastodon mention: `['bluesky' => 1, 'mastodon' => 1]`.
  - `SocialNotificationControllerTest::testListReturnsGroupsWithActorsCappedAtThree` — 5 likes on one post: `actors` has 3 entries, `actorCount` 5, `id` is the newest row's id, `seen` false.
  - `testUnknownNetworkIs400` — `?network=friendster` → `Response::HTTP_BAD_REQUEST`, problem+json.
  - `testListsScopeMissingHandles` — a Mastodon poll row flagged: `scopeMissing` holds that account's handle.
  - `SubscriptionCountsJsonTest::testCarriesNotificationCounts` — `['mastodon' => 2, 'bluesky' => 1]` → `[{network: bluesky, unseenCount: 1}, {network: mastodon, unseenCount: 2}]`.
- [ ] **Step 2: Run** `php bin/phpunit --filter 'SocialNotificationRepository|SocialNotificationController|SubscriptionCountsJson|SubscriptionJsonList'`. Expected: FAIL.
- [ ] **Step 3: Implement.** The grouping is portable DQL (a `CASE` on kind picks `subject.groupKey` or `remoteId` as the group expression); check both legs.
- [ ] **Step 4: Run** the same filter on SQLite and MySQL. Expected: PASS on both.
- [ ] **Step 5: Commit** `feat(#2240): serve notifications as grouped rows with an unseen count`.

### Task 8: Mark all seen

**Files:**
- Create: `NotificationSeenMarker.php` (module), `backend/src/Dto/Social/MarkNotificationsSeenRequest.php`
- Modify: `backend/src/Controller/Api/SocialNotificationController.php` (the `seen` action), `backend/src/Repository/SocialNotificationRepository.php`
- Test: `backend/tests/Service/Social/Notification/NotificationSeenMarkerTest.php` (`DbTestCase`, fake sources), extend `SocialNotificationControllerTest`

**Interfaces:**
- Consumes: Task 2 interface, Task 7 controller.
- Produces:
  - `SocialNotificationRepository::findUnseenUpTo(SocialAccount $account, int $upTo): list<SocialNotification>` — unseen rows with `id <= :upTo`, ordered by id.
  - `NotificationSeenMarker::markSeen(User $user, SocialNetwork $network, int $upTo): void` — for each account from `findAllOnNetwork`, with unseen rows up to `$upTo`: `ActingAccountGuard::actAs($account, fn () => $source->markSeen($account, $rows, static fn (SocialNotification $row) => $row->markSeen()))`, flushing in a `finally` so rows marked before a failure persist; a failure propagates after that flush. An account without such rows calls no network.
  - `MarkNotificationsSeenRequest` (`#[MapRequestPayload]`): `#[Assert\NotNull] public ?SocialNetwork $network`, `#[Assert\NotNull] #[Assert\Positive] public ?int $upTo`; `toNetwork(): SocialNetwork`, `toUpTo(): int`.
  - `POST /api/me/notifications/seen` (`api_me_notifications_seen`) → `Response::HTTP_NO_CONTENT`; `RateLimitGuard::enforceForUser($this->socialWritesLimiter, $user)` first. Errors map through the existing `SocialInteractionProblems` and `SocialProblems` arms (throttled → 503 with `Retry-After`, unreachable → 502, scope missing and needs-reconnect → their existing statuses).

- [ ] **Step 1: Write the failing tests**:
  - `testMarksNothingNewerThanUpTo` — rows 1, 2, 3 unseen, `upTo` 2: the fake source got rows 1 and 2; after `clear()`, rows 1 and 2 seen, row 3 unseen.
  - `testMarksEveryAccountOnTheNetworkOnly` — two Bluesky accounts and one Mastodon account with unseen rows: both Bluesky sources called, the Mastodon rows untouched.
  - `testLemmyThrottleKeepsWhatWasMarked` — the fake Lemmy source calls `$marked` for 2 of 4 rows, then throws `SocialNetworkThrottledException`: the exception propagates; after `clear()`, exactly those 2 rows are seen.
  - `testNoUnseenRowsCallsNoNetwork` — every row seen: the fake source is never called.
  - Controller: `testSeenReturns204` — body `{"network":"bluesky","upTo":<id>}`; `testUpToOfAnotherUserMarksNothing` — `upTo` above the other user's ids marks none of their rows; `testMissingUpToIs422` — `Response::HTTP_UNPROCESSABLE_ENTITY`.
- [ ] **Step 2: Run** `php bin/phpunit --filter 'NotificationSeenMarker|SocialNotificationController'`. Expected: FAIL.
- [ ] **Step 3: Implement.**
- [ ] **Step 4: Run** the same filter. Expected: PASS.
- [ ] **Step 5: Commit** `feat(#2240): mark a network's notifications seen, here and there`.

### Task 9: Docs, gates, migrations, live check, PR

**Files:**
- Modify: `docs/security.md` (the stale Mastodon scope list near line 364: the token asks for `read`, `write:favourites`, `write:statuses`; add that `read` covers notifications and `write:statuses` the notifications marker), `docs/features.md` (one line in the social section: "Notifications from connected accounts — backend; the list follows").

- [ ] **Step 1: Make the docs edits** and check every claim against the code (the scope list in `MastodonAppRegistrar` or wherever the app registers its scopes).
- [ ] **Step 2: Run the gates in parallel** from `backend/`: `composer check`; `rm -rf ~/.pdepend && composer md`; `php bin/phpunit`; `docker compose exec php composer test:parallel`; `composer infection:diff`. Expected: all green, MSI at or above `minMsi`.
- [ ] **Step 3: Migrate from empty** on a fresh SQLite file and on a scratch MySQL database in the Docker stack, then `doctrine:schema:validate` on each. Expected: "in sync" twice.
- [ ] **Step 4: Apply the migration to the live Docker dev DB** (`docker compose exec php bin/console doctrine:migrations:migrate -n`) and restart the worker container so it loads the new schedule.
- [ ] **Step 5: Live check** with the dev stack's real test accounts: after one worker firing, `GET /api/me/notifications?network=bluesky` (and mastodon, lemmy) with a dev JWT returns rows whose links open on the right host; `/api/subscriptions/counts` carries `notifications`; `POST /api/me/notifications/seen` clears the notification in the Bluesky app; marking a Mastodon notification read in its web app shows as seen after the next poll.
- [ ] **Step 6: Scan the dev log**: `ls -t var/log/dev-*.log | head -1 | xargs tail -n 300 | jq 'select(.level_name != "DEBUG")'`. Expected: no deprecations, no swallowed errors from this module.
- [ ] **Step 7: Commit** `docs(#2240): name the notifications scope and feature`, run `/simplify`, then open the PR into `develop` with body `Refs #2240`.

---

The spec's read-only Strato check follows a dev-tag deploy, which is Lars's call; it is not part of this PR.

PR 2 (frontend: sidebar row, list, "Mark all seen", strings in en/de/fr/es) gets its own plan once this API is merged and the mockup is approved.
