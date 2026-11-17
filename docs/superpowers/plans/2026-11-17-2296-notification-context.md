# Notification Context (#2296) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Each notification row says what a reply answered ("You: …") and where it happened, and Lemmy tells a reply to your comment from a reply to your post. The context is looked up once, during the poll, for new rows only.

**Architecture:** A source still fetches one page and records, at no extra cost, whatever the payload already carries (Lemmy post title and community, the Lemmy comment path, Mastodon's embedded quoted post, the ids and URIs a reply or quote points at). A new `NotificationContextLookup` asks the store which of those rows are new and hands only those to a per-network `NotificationContextSourceInterface`, which fetches the answered post or comment and the Bluesky thread start. The store then saves the rows with a nested `NotificationContext` embeddable inside `NotificationSubject`; the list and the JSON mapper read it from the newest row of a group, as they read the excerpt today.

**Tech Stack:** Symfony 8.1, PHP 8.5, Doctrine ORM (MySQL + SQLite), PHPUnit 12 with `RoutedHttpClient`/`MockResponse`; Angular 20 standalone components and signals, Transloco, Jest.

**Spec:** `docs/superpowers/specs/2026-11-17-notification-context-design.md`. The design decisions there are settled; this plan only fills in what it leaves to the builder.

## Global Constraints

- Context lookups run only for rows the store does not yet hold. A poll whose page holds no new rows makes no context call at all.
- Opening the list never waits on a network call: `GET /api/me/notifications` stays a pure database read.
- A context lookup that fails stores the row without that context. The poll still counts as a success. A deleted parent post or comment is left out. Each context source catches `SocialPostNotFoundException`, `SocialPostRefusedException`, `SocialNetworkUnreachableException` and `SocialNetworkThrottledException`. `SocialCredentialsRejectedException` still propagates to the `ActingAccountGuard`, as it does for the fetch.
- Storage: nullable columns on the `NotificationSubject` embeddable, through a nested `App\Entity\NotificationContext` embeddable (four optional fields are one value, so a value object rather than seven constructor parameters). The fields are `answeredExcerpt` (500), `threadTitle` (500), `threadPlace` (255) and `threadBy` (255). Each text goes through `Excerpt::of` (280 characters), and `threadBy` is a display name or a handle.
- New kind `SocialNotificationKind::CommentReply = 'comment_reply'`, for Lemmy only. It is marked read like `reply`, through `/api/v3/comment/mark_as_read` with `comment_reply_id`. It never groups, because its `groupKey` is its remote id, as for `reply`.
- The Lemmy remote id keeps the prefix of its envelope: `reply:<id>` for every `/user/replies` item, `comment_reply` included, and `mention:<id>` for mentions. Rows stored before this change keep matching and are never duplicated.
- Per network:
  - **Lemmy:** path `0.<id>` is a reply to the post, and a longer path is a reply to the comment one segment above the last. `threadTitle` = `post.name`, `threadPlace` = `c/` + `community.name` on replies and mentions. The answered text comes from `GET /api/v3/comment?id=<parent>` → `comment_view.comment.content`, once per new `comment_reply`.
  - **Mastodon:** a `Reply` points at `status.in_reply_to_id`. One `GET /api/v1/statuses?id[]=…` per poll with new replies, in chunks of `STATUSES_PER_CALL = 20`; it may return fewer. A quote's `answeredExcerpt` is `status.quote.quoted_status.content`, read at fetch time. Mastodon has no thread line.
  - **Bluesky:** a reply points at `record.reply.parent.uri`. A quote points at `record.embed.record.uri`, or at `record.embed.record.record.uri` for `app.bsky.embed.recordWithMedia`. The thread root `record.reply.root.uri` is looked up only when it differs from the parent and `AtPostUri::authorityOf(root)` is not `$account->getIdentity()`. All of them go through one batched `app.bsky.feed.getPosts`, `AppViewClient::URIS_PER_CALL` (25) per call. `threadBy` = root `author.displayName`, falling back to `author.handle`. `threadTitle` = root `record.text`.
- API: each group gains `answeredExcerpt`, `threadTitle`, `threadPlace` and `threadBy`, each `string|null`.
- Frontend layout A:
  1. the action line;
  2. the reply text (two-line clamp);
  3. the quote line: a muted 3px left rule, bold "You:", one line with an ellipsis;
  4. the place line: muted, `--fs-xs`, one line with an ellipsis. The icon is `article` when `threadPlace` is set (Lemmy) and `forum` with "in a thread by {{name}}:" when `threadBy` is set (Bluesky).

  For `like` and `repost`, the excerpt (your post) takes the quote style and there is no reply text. For `quote`, the excerpt is their text and `answeredExcerpt` is your post. `comment_reply` uses glyph `chat_bubble` and the accent colour.
- Strings: en, de, fr and es all get `action.comment_reply.{one,other}`, `you` and `threadBy`. Use natural phrasing and keep the English loanwords where they are usual (see the existing `geliked` and `repostet`).
- House rules from `CLAUDE.md` apply in full. Services are `final readonly` and models live in `Model/`. A static pure helper goes in `Support/`, and each interface sits in a folder named after it. No boolean flag parameters, no comments by default, and `Response::HTTP_*` only. PHPMD stays clean on every touched `src` file. phptramp must pass: 4 hops fail and 3 warn, so fix a warning with a field, not a longer signature. No PHPStan ignores or rule edits.
- Commits use `type(#2296): …` with no Claude attribution. The PR goes into `develop` with `Closes #2296`.
- Work only in this worktree. `backend/vendor` is a real directory (`composer install`, or `cp -R` from the main checkout, never a symlink), and `frontend/node_modules` comes from `npm ci`. The Docker stack serves the main checkout, so Jest runs natively here with `./node_modules/.bin/jest --testPathIgnorePatterns "/node_modules/" "/e2e/"`. The MySQL leg runs natively against a throwaway database on the Docker MySQL (`127.0.0.1:33306`, see Task 7). It never uses `feedreader` or `feedreader_test`.

## Review Focus

1. **A Lemmy comment reply stored before this change as `reply:7`.** The next poll must not insert a second `comment_reply` row for it. Pinned in Task 3: `testACommentReplyKeepsTheReplyRemoteId`.
2. **A page holding the same remote id twice, once seen and once unseen.** The context swap must keep the seen flag the store picks. Pinned in Task 2: `testContextKeepsTheSeenFlagOfADuplicate`.
3. **A 429 halfway through Lemmy's one-by-one parent lookups.** The poll succeeds, the rows already looked up keep their text, the rest store without it, and no further lookup is sent. Pinned in Task 3: `testAThrottleStopsTheLookupsAndKeepsTheRows`.
4. **A Mastodon server older than 4.3, which has no `GET /api/v1/statuses`.** Replies store without answered text and the poll succeeds. Pinned in Task 4: `testAServerWithoutBatchStatusesStoresRepliesWithoutContext`.
5. **A Bluesky reply whose thread starts with your own post, or that answers the thread's first post directly.** It shows no thread line, and its root is never looked up. Pinned in Task 5: `testNoThreadLineForYourOwnThreadOrADirectReplyToItsStart`.

---

## File Structure

Module `backend/src/Service/Social/Notification/`:

| Path | Responsibility |
|---|---|
| `NotificationContextLookup.php` (new) | Picks the page's new rows, hands them to the network's context source, swaps their subjects back into the page |
| `NotificationContextSource/NotificationContextSourceInterface.php` (new) | Adds context to new rows; tagged `app.social_notification_context`, keyed by `SocialNetwork` value |
| `NotificationContextSource/{Lemmy,Mastodon,Bluesky}NotificationContextSource.php` (new) | One per network |
| `BlueskyPostLookup.php` (new) | The batched `getPosts`, shared by the Bluesky source (liked texts) and context source |
| `Model/ContextReferenceModel.php` (new) | What a fetched row points at: `answeredId`, `threadRootId` |
| `Support/LemmyCommentPath.php` (new) | The parent comment id of a Lemmy path |
| `Support/BlueskyReplyReferences.php` (new) | Parent, root and quoted URIs of a Bluesky record |
| `Model/FetchedNotificationModel.php`, `Model/NotificationPageModel.php`, `NotificationStore.php`, `NotificationPoll.php`, the three `NotificationSource/*` | Modified |

Elsewhere: `src/Entity/NotificationContext.php` (new embeddable), `src/Entity/NotificationSubject.php`, `src/Enum/SocialNotificationKind.php`, `src/Http/SocialNotificationsJson.php`, `migrations/Version20261117140000.php` (new). On the frontend: `reader/notifications/notification.models.ts`, `notification-line.ts`, `notification-context.ts` (new), `notification-row/*`, `public/i18n/{en,de,fr,es}.json`, and `e2e/social-notifications.spec.ts` (its factory).

---

### Task 1: Context columns, the new kind, API fields

**Files:**
- Create: `backend/src/Entity/NotificationContext.php`, `backend/migrations/Version20261117140000.php`
- Modify: `backend/src/Entity/NotificationSubject.php`, `backend/src/Enum/SocialNotificationKind.php`, `backend/src/Http/SocialNotificationsJson.php`
- Test: `backend/tests/Entity/NotificationSubjectTest.php`, `backend/tests/Controller/Api/SocialNotificationControllerTest.php`, `backend/tests/Repository/SocialNotificationRepositoryTest.php`

**Interfaces:**
- Produces:
  - `#[ORM\Embeddable] final readonly class NotificationContext(?string $answeredExcerpt = null, ?string $threadTitle = null, ?string $threadPlace = null, ?string $threadBy = null)`. Every column is nullable, with the lengths from Global Constraints. It has `#[\NoDiscard] withAnsweredExcerpt(?string $answeredExcerpt): self` and `#[\NoDiscard] withThread(?string $threadTitle, ?string $threadBy): self`, both written with `clone($this, […])`.
  - `NotificationSubject` gains a fourth promoted parameter, `#[ORM\Embedded(class: NotificationContext::class, columnPrefix: 'context_')] public NotificationContext $context = new NotificationContext()`, and `#[\NoDiscard] withContext(NotificationContext $context): self`. `follow()` is unchanged.
  - `SocialNotificationKind::CommentReply = 'comment_reply'`, which fits the existing `length: 16`.
  - `GroupJson` gains `answeredExcerpt`, `threadTitle`, `threadPlace` and `threadBy` (each `string|null`), read from `$group->subject->context`.
- Migration `Version20261117140000`: in the shape of `Version20261114100000`, add the four columns to `social_notification`. Use the names Doctrine derives, which is what `bin/console doctrine:schema:update --dump-sql` prints for the nested prefix (expected `subject_context_answered_excerpt` and so on). Write `VARCHAR(n) DEFAULT NULL` on both dialects. `up` skips the columns already present and `down` drops them. Its description is `'Store what a notification answered and where it happened (#2296).'`

- [ ] **Step 1: Write the failing tests**
  - `NotificationSubjectTest::testASubjectStartsWithoutContext`: `(new NotificationSubject('k', null, 'u'))->context == new NotificationContext()`.
  - `NotificationSubjectTest::testWithContextKeepsTheRest`: groupKey, excerpt and linkUrl are unchanged and the context is replaced.
  - `SocialNotificationRepositoryTest::testContextSurvivesAFlushAndReload`: persist a row whose subject has all four fields, `clear()`, reload, and `assertEquals` the context.
  - `SocialNotificationControllerTest::testAGroupCarriesItsContext`: a Lemmy `CommentReply` row with `NotificationContext('my comment', 'Post title', 'c/php', null)` gives `answeredExcerpt` `'my comment'`, `threadTitle` `'Post title'`, `threadPlace` `'c/php'`, `threadBy` `null` and `kind` `'comment_reply'`.
  - Extend `testListReturnsGroupsWithActorsCappedAtThree`: the four keys are present and `null`.
- [ ] **Step 2: Run** `php bin/phpunit --filter 'NotificationSubject|SocialNotificationRepository|SocialNotificationController'`. Expected: FAIL, because `NotificationContext` is unknown.
- [ ] **Step 3: Implement** the embeddable, the subject field, the enum case, the mapper fields and the migration.
- [ ] **Step 4: Run** the same filter. Expected: PASS. Then on a fresh SQLite file run `DATABASE_URL="sqlite:///$PWD/var/migrate-2296.db" bin/console doctrine:migrations:migrate -n && DATABASE_URL="sqlite:///$PWD/var/migrate-2296.db" bin/console doctrine:schema:validate`. Expected: "in sync". Delete the file afterwards.
- [ ] **Step 5: Commit** `feat(#2296): store and serve a notification's context`.

### Task 2: Look up context for new rows only

**Files:**
- Create: `backend/src/Service/Social/Notification/NotificationContextLookup.php`, `NotificationContextSource/NotificationContextSourceInterface.php`, `Model/ContextReferenceModel.php`, `backend/tests/Support/RecordingContextSource.php`
- Modify: `Model/FetchedNotificationModel.php`, `Model/NotificationPageModel.php`, `NotificationStore.php`, `NotificationPoll.php`, `backend/tests/Support/NotificationPollWiring.php`
- Test: `backend/tests/Service/Social/Notification/NotificationContextLookupTest.php` (new, `DbTestCase`), `NotificationPollSweepTest.php`, `Model/NotificationPageModelTest.php`

**Interfaces:**
- Consumes: Task 1 (`NotificationSubject::withContext`, `NotificationContext`).
- Produces:
  - `final readonly class ContextReferenceModel(public ?string $answeredId = null, public ?string $threadRootId = null)` with `isEmpty(): bool`.
  - `FetchedNotificationModel` gains a 7th parameter, `public ContextReferenceModel $references = new ContextReferenceModel()`, and `#[\NoDiscard] withSubject(NotificationSubject $subject): self`.
  - `NotificationPageModel::withSubjectsOf(list<FetchedNotificationModel> $completed): self` (`#[\NoDiscard]`). Every notification whose remote id is in `$completed` takes that subject. Its `seen`, `createdAt` and everything else are kept.
  - `NotificationStore::unstored(SocialAccount $account, NotificationPageModel $page): list<FetchedNotificationModel>`: the rows `record()` would insert, meaning retained (within 30 days, deduplicated) and absent from `findByRemoteIds`. `record()` reuses the same private helpers.
  - `#[AutoconfigureTag('app.social_notification_context')] interface NotificationContextSourceInterface { public function withContext(SocialAccount $account, array $fresh): array; }`. The docblock says it takes `@param non-empty-list<FetchedNotificationModel> $fresh` and returns `@return list<FetchedNotificationModel>`, the same rows in the same order with context added where found. It throws only `SocialCredentialsRejectedException`. Every implementation returns a row with empty `references` untouched and makes no call when no row has references.
  - `NotificationContextLookup(#[AutowireLocator('app.social_notification_context')] ContainerInterface $contextSources, NotificationStore $store)` with `completing(SocialAccount $account, NotificationPageModel $page): NotificationPageModel`. With no unstored rows it returns `$page` unchanged and never resolves a source. Otherwise it returns `$page->withSubjectsOf($source->withContext($account, $fresh))`, with the source picked by `$account->getNetwork()->value`.
  - `NotificationPoll` gains `NotificationContextLookup $contextLookup`. `fetch()` becomes `$this->guard->actAs($account, fn () => $this->contextLookup->completing($account, $source->fetch($account)))`, so the context calls run under the same guard as the fetch.
  - Test double `RecordingContextSource implements NotificationContextSourceInterface`. It has `public array $asked = []` (a list of remote-id lists, one per call) and an optional `?NotificationContext $adds`, which it puts on every row it is handed.
  - `NotificationPollWiring::sweep(…)` gains a last parameter, `NotificationContextSourceInterface $contextSource = new RecordingContextSource()`, registered for every network.
- One line why a separate collaborator and not a method on `NotificationSourceInterface`: it runs on a different input (new rows only) under a different failure rule (swallow, don't throw), and the four source doubles stay untouched.

- [ ] **Step 1: Write the failing tests**
  - `NotificationContextLookupTest::testHandsOnlyUnstoredRowsToTheContextSource`: `r1` is stored and the page holds `r1` and `r2`. The source is asked once, with `['r2']`.
  - `testNoUnstoredRowsAsksNothing`: every row is stored, so `asked === []` and the page comes back identical (`assertSame`).
  - `testRowsOutsideRetentionAreNotLookedUp`: a 31-day-old fetched row is never handed over.
  - `testContextKeepsTheSeenFlagOfADuplicate`: the page holds `r1` unseen and `r1` seen, and the source adds a context. After `record()`, the one stored `r1` is seen and carries the context.
  - `testEveryNetworkResolvesAContextSource`, with a provider over `SocialNetwork::cases()`: on the real container, `NotificationContextLookup` completes a page holding one unstored follow row with no references, and returns it with an empty context. This proves the wiring without any HTTP.
  - `NotificationPollSweepTest::testContextIsLookedUpOnlyForNewRows`: the first sweep stores `r1` and `r2`, so `asked === [['r1','r2']]`. The clock moves 6 minutes. The second sweep's page holds `r1`, `r2` and `r3`, so `asked` gains `['r3']`. The third sweep repeats that page and `asked` stays two entries long.
  - `NotificationPollSweepTest::testTheStoredRowCarriesTheContext`: the double adds `new NotificationContext('You said')`, and the reloaded row's `getSubject()->context->answeredExcerpt === 'You said'`.
  - `NotificationPageModelTest::testWithSubjectsOfSwapsSubjectsByRemoteId`.
- [ ] **Step 2: Run** `php bin/phpunit --filter 'NotificationContextLookup|NotificationPollSweep|NotificationPageModel'`. Expected: FAIL.
- [ ] **Step 3: Implement** the interface, the lookup, the model changes, `unstored()` and the poll wiring. Give each network a context source class that returns `$fresh` unchanged for now. Tasks 3–5 fill them in, and the wiring test needs all three keys now.
- [ ] **Step 4: Run** the same filter, then the whole `tests/Service/Social/Notification` directory. Expected: PASS.
- [ ] **Step 5: Commit** `feat(#2296): look up notification context for new rows only`.

### Task 3: Lemmy — comment replies, post title, community, answered comment

**Files:**
- Create: `Support/LemmyCommentPath.php`, `backend/tests/Fixtures/lemmy/notifications-parent-comment.json` (a `{"comment_view": {"comment": {"id": 26906403, "content": "My **original** comment"}}}` shape)
- Modify: `NotificationSource/LemmyNotificationSource.php`, `NotificationContextSource/LemmyNotificationContextSource.php`, `backend/tests/Fixtures/lemmy/notifications-replies.json`, `notifications-mentions.json` (add `comment.path`, `post: {id, name}` and `community: {id, name}`. Reply 7 gets path `0.26906403.41` and reply 6 gets `0.38`.)
- Test: `LemmyNotificationSourceTest.php`, `backend/tests/Service/Social/Notification/NotificationContextSource/LemmyNotificationContextSourceTest.php` (new), `backend/tests/Service/Social/Notification/Support/LemmyCommentPathTest.php` (new)

**Interfaces:**
- Consumes: Task 2 (`ContextReferenceModel`, `NotificationContextSourceInterface`), Task 1 (`CommentReply`, `NotificationContext`).
- Produces:
  - `LemmyCommentPath::parentOf(?string $path): ?string`. It returns `null` for `null`, `''`, `'0'` and `'0.<id>'`, and the second-last segment otherwise. A malformed segment (not all digits) gives `null`.
  - Source: a `/user/replies` item whose `parentOf(comment.path)` is not null becomes `CommentReply`, with `references = new ContextReferenceModel(answeredId: <parent>)`. Every reply and mention subject carries `new NotificationContext(threadTitle: Excerpt::of(post.name), threadPlace: 'c/' . community.name)`, and the place is `null` when the community name is missing. The remote id prefix comes from the envelope (`reply`/`mention`), not from the kind. `markRead` sends `MARK_REPLY` for `Reply` and `CommentReply`.
  - Context source: `LemmyNotificationContextSource(LemmyAccountApi $lemmy)`. For each row with an `answeredId` it sends `GET /api/v3/comment?id=<id>` and sets `withAnsweredExcerpt(Excerpt::of(comment_view.comment.content))`. Not-found, refused and unreachable leave that row unchanged. A throttle stops the loop, and the rows not yet looked up stay unchanged.

- [ ] **Step 1: Write the failing tests**
  - `LemmyCommentPathTest::testParentOf`, with a provider: `'0.26906406'` → `null`, `'0.26906403.26906408'` → `'26906403'`, `'0.1.2.3'` → `'2'`, `null`/`''`/`'0'` → `null`, `'0.x.5'` → `null`.
  - `LemmyNotificationSourceTest::testMapsRepliesAndMentions` (update): reply 7 is `CommentReply` with `references->answeredId === '26906403'`, reply 6 is `Reply` with empty references, and both carry `threadTitle` and `threadPlace` from the fixture. The mention carries the place too.
  - `testACommentReplyKeepsTheReplyRemoteId`: reply 7's remote id and group key are `'reply:7'`.
  - `testMarkSeenMarksACommentReplyThroughTheReplyEndpoint`: a stored `CommentReply` with remote id `reply:7` posts `{"comment_reply_id":7,"read":true}` to `MARK_REPLY`.
  - `LemmyNotificationContextSourceTest::testAddsTheAnsweredComment`: the row gets `'My original comment'` (the excerpt is scrubbed as elsewhere; assert the exact `Excerpt::of` output), and `threadTitle` and `threadPlace` are kept.
  - `testAGoneParentLeavesTheRowWithoutAnsweredText`: a 404 gives the same row back.
  - `testAThrottleStopsTheLookupsAndKeepsTheRows`: three rows. The first lookup gives 200, the second 429, and the third route is never requested (`RoutedHttpClient` records that). All three come back, only the first with text.
  - `testRowsWithoutReferencesMakeNoCall`.
- [ ] **Step 2: Run** `php bin/phpunit --filter 'Lemmy(NotificationSource|NotificationContextSource|CommentPath)'`. Expected: FAIL.
- [ ] **Step 3: Implement.**
- [ ] **Step 4: Run** the same filter. Expected: PASS. Then run `composer md` on the two touched source files. Expected: no finding.
- [ ] **Step 5: Commit** `feat(#2296): tell Lemmy comment replies apart and add their context`.

### Task 4: Mastodon — the answered post and the quoted post

**Files:**
- Create: `backend/tests/Fixtures/Mastodon/notifications-statuses.json` (a list holding status `4000`, content `<p>My <b>own</b> post</p>`)
- Modify: `NotificationSource/MastodonNotificationSource.php`, `NotificationContextSource/MastodonNotificationContextSource.php`, `backend/tests/Fixtures/Mastodon/notifications-list.json` (mention 1080 gets `in_reply_to_id: "4000"`; the quote 1030 gets `status.quote: {"state": "accepted", "quoted_status": {"id": "4001", "content": "<p>The post they quoted</p>", …}}`)
- Test: `MastodonNotificationSourceTest.php`, `backend/tests/Service/Social/Notification/NotificationContextSource/MastodonNotificationContextSourceTest.php` (new)

**Interfaces:**
- Consumes: Tasks 1–2.
- Produces:
  - Source: a `Reply` gets `references = new ContextReferenceModel(answeredId: status.in_reply_to_id)`. A `Quote` subject gets `new NotificationContext(answeredExcerpt: Excerpt::of(PlainText::fromHtmlBlocks(status.quote.quoted_status.content)))`, and no context when that path is missing.
  - Context source: `MastodonNotificationContextSource(MastodonAccountApi $mastodon)` with `STATUSES_PER_CALL = 20`. It collects the unique `answeredId`s and sends `askAboutPost($account, '/api/v1/statuses?' . implode('&', 'id[]=' . rawurlencode($id)))` per chunk. It maps the answer by status `id` to `Excerpt::of(PlainText::fromHtmlBlocks(content))` and sets `withAnsweredExcerpt` on each row whose id came back. A failed chunk (the four caught exceptions) leaves its rows unchanged, and the other chunks still apply.

- [ ] **Step 1: Write the failing tests**
  - `MastodonNotificationSourceTest::testAReplyPointsAtThePostItAnswers`: 1080 has `answeredId '4000'`, and the plain mention 1070 has empty references.
  - `testAQuoteCarriesTheQuotedPost`: 1030's `subject->context->answeredExcerpt === 'The post they quoted'`, and its excerpt stays their own text.
  - `MastodonNotificationContextSourceTest::testAddsTheAnsweredPostsInOneCall`: two rows answering `4000` cause one request, `GET …/api/v1/statuses?id%5B%5D=4000` (assert the exact URL the client recorded), and both rows get `'My own post'`.
  - `testAPostMissingFromTheAnswerLeavesItsRowAlone`: ids `4000` and `4002` are asked and only `4000` is answered.
  - `testAServerWithoutBatchStatusesStoresRepliesWithoutContext`: a 404 returns the rows unchanged and throws nothing.
  - `testChunksOfTwenty`: 21 distinct ids cause two requests.
- [ ] **Step 2: Run** `php bin/phpunit --filter 'Mastodon(NotificationSource|NotificationContextSource)'`. Expected: FAIL.
- [ ] **Step 3: Implement.**
- [ ] **Step 4: Run** the same filter. Expected: PASS.
- [ ] **Step 5: Commit** `feat(#2296): add the answered and quoted post to Mastodon notifications`.

### Task 5: Bluesky — the answered post and the thread start

**Files:**
- Create: `BlueskyPostLookup.php`, `Support/BlueskyReplyReferences.php`, `backend/tests/Fixtures/Bluesky/notifications-context-posts.json` (holds the parent `at://did:plc:alice/app.bsky.feed.post/3lmine`, the root `at://did:plc:zoe/app.bsky.feed.post/3lroot` with author `{did: "did:plc:zoe", handle: "zoe.bsky.social", displayName: "Zoe"}` and text `"How do you all handle feeds?"`, and the quoted post `at://did:plc:alice/app.bsky.feed.post/3lquoted`)
- Modify: `NotificationSource/BlueskyNotificationSource.php`, `NotificationContextSource/BlueskyNotificationContextSource.php`, `backend/tests/Fixtures/Bluesky/notifications-list.json` (the reply `3l05` gets `record.reply.{parent,root}.uri`; the quote `3l07` gets `record.embed: {"$type": "app.bsky.embed.record", "record": {"uri": …3lquoted}}`; add a second quote with `app.bsky.embed.recordWithMedia` → `record.embed.record.record.uri`)
- Test: `BlueskyNotificationSourceTest.php`, `backend/tests/Service/Social/Notification/BlueskyPostLookupTest.php` (new), `backend/tests/Service/Social/Notification/NotificationContextSource/BlueskyNotificationContextSourceTest.php` (new), `backend/tests/Service/Social/Notification/Support/BlueskyReplyReferencesTest.php` (new)

**Interfaces:**
- Consumes: Tasks 1–2.
- Produces:
  - `BlueskyPostLookup(BlueskySessions $sessions)` with `byUri(SocialAccount $account, list<string> $uris): array<string, JsonNodeModel>`. It holds the existing `postTexts` loop (chunks of `AppViewClient::URIS_PER_CALL` and the `uris=` query) and returns each post node keyed by its `uri`. It throws what `BlueskySessions::askAs`/`accepted` throw. The source's `postTexts` becomes a call to it, and the liked-text behaviour and tests are unchanged.
  - `BlueskyReplyReferences::of(JsonNodeModel $record, SocialNotificationKind $kind): ContextReferenceModel`. `Reply` gives `answeredId` = parent URI and `threadRootId` = root URI when root ≠ parent. `Quote` gives `answeredId` = the embedded record URI, for either embed type. Anything else gives empty references.
  - Source: reply and quote rows carry `BlueskyReplyReferences::of(...)`.
  - Context source: `BlueskyNotificationContextSource(BlueskyPostLookup $posts)`. It drops a `threadRootId` whose `AtPostUri::authorityOf` equals `$account->getIdentity()`, then makes one `byUri` call over the unique answered and root URIs. It sets `withAnsweredExcerpt(Excerpt::of(post.record.text))` and `withThread(Excerpt::of(root.record.text), root.author.displayName ?: root.author.handle)`. A missing post leaves its field `null`. Unreachable or throttled leaves every row unchanged.

- [ ] **Step 1: Write the failing tests**
  - `BlueskyReplyReferencesTest`, with a provider: a reply with distinct root and parent, a reply where root = parent (`threadRootId` null), a record quote, a recordWithMedia quote, and a like (empty).
  - `BlueskyNotificationSourceTest::testRepliesAndQuotesPointAtWhatTheyAnswer`.
  - `testAsksOnePostLookupWithRepeatedUrisKeys` keeps passing unchanged: the liked-text call is the same.
  - `BlueskyPostLookupTest::testKeysPostsByUriInChunksOfTwentyFive`: 26 URIs cause two calls.
  - `BlueskyNotificationContextSourceTest::testAddsTheAnsweredPostAndTheThreadStart`: the reply gets `answeredExcerpt` (parent text), `threadTitle 'How do you all handle feeds?'` and `threadBy 'Zoe'`. There is one getPosts call, holding both URIs.
  - `testThreadByFallsBackToTheHandle`: an empty display name gives `'zoe.bsky.social'`.
  - `testNoThreadLineForYourOwnThreadOrADirectReplyToItsStart`: a root authored by `did:plc:alice` (the account's identity) and a root equal to the parent both give `threadTitle`/`threadBy` null, and the recorded getPosts query holds no root URI.
  - `testAQuoteGetsYourQuotedPost`.
  - `testAnUnreachableAppViewLeavesTheRowsAlone`: a 502 returns the rows unchanged and throws nothing.
- [ ] **Step 2: Run** `php bin/phpunit --filter 'Bluesky(NotificationSource|NotificationContextSource|PostLookup|ReplyReferences)'`. Expected: FAIL.
- [ ] **Step 3: Implement.**
- [ ] **Step 4: Run** the same filter, then the whole `tests/Service/Social/Notification` directory and `composer md`. Expected: PASS, and no PHPMD finding on `BlueskyNotificationSource.php`.
- [ ] **Step 5: Commit** `feat(#2296): add the answered post and thread start to Bluesky notifications`.

### Task 6: Frontend — layout A, the new kind, strings

**Files:**
- Create: `frontend/src/app/reader/notifications/notification-context.ts`, `notification-context.spec.ts`
- Modify: `notification.models.ts`, `notification-line.ts`, `notification-row/notification-row.component.{ts,html,scss,spec.ts}`, `frontend/public/i18n/{en,de,fr,es}.json`, and the group factories in `notification-line.spec.ts`, `notifications.store.spec.ts`, `notification-list/notification-list.component.spec.ts` and `frontend/e2e/social-notifications.spec.ts` (the new fields default to `null`)

**Interfaces:**
- Consumes: Task 1's JSON fields.
- Produces:
  - `NotificationKind` gains `'comment_reply'`. `NotificationGroupDto` gains `answeredExcerpt`, `threadTitle`, `threadPlace` and `threadBy`, each `string | null`. `NOTIFICATION_GLYPH.comment_reply = 'chat_bubble'`.
  - `notificationContext(group: NotificationGroupDto): NotificationContextView`, where `NotificationContextView` is `{ readonly replyText: string | null; readonly quoted: string | null; readonly place: NotificationPlace | null }` and `NotificationPlace` is `{ readonly icon: 'article' | 'forum'; readonly by: string | null; readonly title: string; readonly community: string | null }`. For `like` and `repost`: `replyText = null` and `quoted = excerpt`. Every other kind: `replyText = excerpt` and `quoted = answeredExcerpt`. `place` is `null` without a `threadTitle`. `icon` is `'forum'` when `threadBy` is set and `'article'` otherwise.
  - The row has `protected readonly context = computed(() => notificationContext(this.group()))`. The template renders, in order:
    1. `.line`;
    2. `.excerpt` (`replyText`);
    3. `p.quote`, made of `<span class="you">{{ 'reader.notifications.you' | transloco }}</span>` and the text;
    4. `p.place`, made of `<app-icon [name]="place.icon" size="xs" />`, then `threadBy` text when `by` is set, then `<span class="title">`, then ` · {{ community }}` when it is set;
    5. `.account`.
  - SCSS: `.quote` gets `border-left: 3px solid var(--border-strong)`, `padding-left: var(--space-2)`, `color: var(--text-muted)`, `font-size: var(--fs-sm)`, and one line with `white-space: nowrap; overflow: hidden; text-overflow: ellipsis`. `.you` is `font-weight: 600`. `.place` has `color: var(--text-muted)`, `font-size: var(--fs-xs)`, the same one-line ellipsis, `display: flex; gap: var(--space-1); align-items: center; min-width: 0`, and its `.title` also gets the ellipsis with `min-width: 0`. `.kind.comment_reply` joins the accent list.
  - Strings under `reader.notifications`:

    | Key | en | de | fr | es |
    |---|---|---|---|---|
    | `action.comment_reply.one` | `{{who}} replied to your comment` | `{{who}} hat auf deinen Kommentar geantwortet` | `{{who}} a répondu à votre commentaire` | `{{who}} respondió a tu comentario` |
    | `action.comment_reply.other` | `{{who}} replied to your comment` | `{{who}} haben auf deinen Kommentar geantwortet` | `{{who}} ont répondu à votre commentaire` | `{{who}} respondieron a tu comentario` |
    | `you` | `You:` | `Du:` | `Vous :` | `Tú:` |
    | `threadBy` | `in a thread by {{name}}:` | `im Thread von {{name}}:` | `dans un fil de {{name}} :` | `en un hilo de {{name}}:` |

- [ ] **Step 1: Write the failing tests**
  - `notification-context.spec.ts`:
    - `quotes your own post for a like and a repost`: `replyText` is null and `quoted` is the excerpt.
    - `shows their text and quotes what it answered for a reply`.
    - `shows their text and quotes your post for a quote`.
    - `places a Lemmy reply under its post and community`: icon `article`, title and community set, `by` null.
    - `places a Bluesky reply in a thread by its author`: icon `forum`, `by` set, community null.
    - `has no place without a thread title`.
  - `notification-row.component.spec.ts`:
    - `renders reply text, quote line and place line in that order`: query `.excerpt`, `.quote` and `.place` and compare `compareDocumentPosition`.
    - `prefixes the quote with a bold You:`: `.quote .you` text is `'You:'`.
    - `draws a like's excerpt as a quote, not as reply text`: no `.excerpt`, and `.quote` holds the excerpt.
    - `says "in a thread by Zoe:" before a Bluesky thread title`.
    - `draws a comment reply with the reply glyph in the accent tone`: the glyph is `chat_bubble` and the kind class is `comment_reply`.
    - `shows no quote or place line without context`.
  - `notification-line.spec.ts`:
    - `says a comment reply answered your comment`: `'Carol replied to your comment'`.
  - `core/i18n/i18n-dictionaries.spec.ts` already fails until all four files have the keys. Run it to confirm.
- [ ] **Step 2: Run** from `frontend/`, after `npm ci`: `./node_modules/.bin/jest --testPathIgnorePatterns "/node_modules/" "/e2e/" src/app/reader/notifications src/app/core/i18n`. Expected: FAIL.
- [ ] **Step 3: Implement.**
- [ ] **Step 4: Run** the same command, then `npm run check:static`. Expected: PASS. Stylelint is clean, with no hex and no px on spacing.
- [ ] **Step 5: Commit** `feat(#2296): show what a notification answered and where it happened`.

### Task 7: Gates, migrations on both dialects, PR

**Files:** none new.

- [ ] **Step 1: Run `/simplify`** over the branch diff and commit its fixes as `refactor(#2296): …`.
- [ ] **Step 2: Run the backend gates** from `backend/`: `composer check`, `rm -rf ~/.pdepend && composer md`, `php bin/phpunit` and `composer infection:diff`. Expected: all green, no tramp warning on the new chains, and MSI at or above `minMsi`.
- [ ] **Step 3: Run the MySQL leg natively** against a throwaway database on the Docker MySQL. It is safe because it never touches `feedreader`/`feedreader_test`: `DATABASE_URL="mysql://root:root@127.0.0.1:33306/feedreader2296?serverVersion=8.4&charset=utf8mb4" php bin/phpunit` (the test env adds `_test`). Expected: green. If the Docker stack is not up, this leg moves to after the merge into the main checkout (`docker compose exec php composer test:parallel`), and the PR body says so.
- [ ] **Step 4: Migrate from empty** on both dialects and validate:
  - SQLite: the fresh-file command from Task 1, Step 4.
  - MySQL: `bin/console doctrine:database:create`, then `doctrine:migrations:migrate -n`, then `doctrine:schema:validate`, all with `DATABASE_URL=…/scratch_2296…` (`APP_ENV=dev`, a database named only for this check).

  Expected: "in sync" on both. Then drop `scratch_2296` and `feedreader2296_test` with `doctrine:database:drop --force` on those exact URLs.
- [ ] **Step 5: Run the frontend gate** natively: `npm run check:static && ./node_modules/.bin/jest --testPathIgnorePatterns "/node_modules/" "/e2e/"`. Expected: green.
- [ ] **Step 6: Open the PR** into `develop`. The title is `feat(#2296): notification context` and the body says `Closes #2296`. It lists the settled choices from this plan's Global Constraints and the live-check checklist below. Watch `gh pr checks` until green.
- [ ] **Step 7: Hand Lars the live checklist** for after the branch reaches the main checkout. Run `doctrine:migrations:migrate -n` in the php container and restart the worker. After one poll with new notifications:
  - (a) A Lemmy reply to your comment reads "replied to your comment", quotes your comment and shows the post title · `c/<community>`.
  - (b) A Mastodon reply quotes your post.
  - (c) A Bluesky reply in someone else's thread shows "in a thread by …:".
  - (d) A like shows your post in the quote style.
  - (e) A long URL in a quote ellipsizes on a 375px viewport.
  - (f) `ls -t backend/var/log/dev-*.log | head -1 | xargs tail -n 300 | jq 'select(.level_name != "DEBUG")'` shows no errors from the module.

---

## Self-review notes

- Spec coverage:
  - Lemmy `path` rule: Task 3.
  - "You: …" for each network: Tasks 3–5.
  - The place line: Lemmy in Task 3 and Bluesky in Task 5. Mastodon has none.
  - Likes and reposts in the quote style: Task 6.
  - Columns and migration: Task 1.
  - `comment_reply` (kind, marking, never grouping): Tasks 1 and 3.
  - New rows only: Task 2.
  - Failure rule: Tasks 3–5.
  - API: Task 1.
  - Strings: Task 6.
  - No backfill: nothing reads old rows.
- Type names used across tasks are `NotificationContext`, `ContextReferenceModel(answeredId, threadRootId)`, `NotificationContextSourceInterface::withContext`, `NotificationContextLookup::completing`, `NotificationStore::unstored`, `NotificationPageModel::withSubjectsOf`, `FetchedNotificationModel::withSubject` and `BlueskyPostLookup::byUri`.
