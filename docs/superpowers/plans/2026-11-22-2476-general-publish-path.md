# One General Publish Path Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Replace the target-shaped social write path (reply / quote / share-a-link) with one general publish path per network, and move the composer out of the article area — with no user-visible behaviour change — so #2474 (standalone text post) and #2475 (images) add a kind and an embed instead of a parallel path.

**Architecture:** A new backend module `Service/Social/Publishing` owns what is written (`PublicationModel` = generic `PostDraftModel` + optional `inReplyTo` + optional embed), the one writer method `publish()`, composer defaults and contexts, and the reply/quote/link orchestrators. Network modules implement its interfaces; deletion stays in `Social/Social` so the lower module never names Publishing. In the SPA the composer becomes `reader/social/composer/`, opened through a generic `Composer` service with a `ComposeRequest`; the article area keeps only its side effects in `ArticlePostActions`.

**Tech Stack:** Symfony 8.1 / PHP 8.5 (PHPUnit 12, PHPStan max with the project rules, PHPMD, phptramp, Infection), Angular 20 (signals, standalone, Jest, Playwright, Transloco).

**Spec:** GitHub issue #2476 (design grilled 2026-11-22), built on by #2474; images later in #2475.

## Global Constraints

- No user-visible behaviour change: same composer, same wire payloads to Bluesky / Mastodon / Lemmy, same problem codes. The only API changes are the JSON key `target` → `subject` (nullable) in the composer context, and `POST /api/social/links` reading network, url, title and quote from the JSON body instead of the query string.
- CLAUDE.md naming table and role folders apply to every new name; no `of…`/`…Of` names; `final readonly`, withers via `clone($this, [...])` with `#[\NoDiscard]`.
- `ServiceModuleCycleRule` must stay green: `Social/Social` never names `Social/Publishing`; `Publishing` may name `Social/Social`; `Bluesky`, `Mastodon`, `Lemmy` may name both.
- Every `src` file touched is PHPMD-clean. New `src` classes trip the `.claude/hooks/phpmd-gate.sh` commit gate only while a PHPMD finding is open — fix the finding, do not split to dodge it.
- Translation keys change in `en`, `de`, `fr`, `es` together (`frontend/public/i18n/*.json`).
- Renames get lean testing: no new test whose only purpose is a rename.
- Commits: `refactor(#2476): …` (feature-shaped tests may use `test(#2476): …`); no Claude attribution.

## Review Focus

1. **Mastodon local length check rejects a post Mastodon accepts** — a status with long URLs, `@user@remote.host` mentions or emoji near 500 counted characters must still go out; only text over the instance limit as Mastodon counts it is refused (Task 6 tests).
2. **Bluesky resend after a timeout** — a resend with the same idempotency key that finds its record must not rebuild the embed (no second thumbnail upload); the lazy-fields path survives the `publish()` rewrite (Task 3 test).
3. **A shared URL that names a quotable post** — still becomes a quote (blank text → `social_post_text_missing`), now with the URL in the body (Task 5 controller test).
4. **Mastodon link post budget** — the composer for a Mastodon link still reserves 25 characters for the appended link and starts from the title within that budget (Task 4 test).
5. **Crosspost partial failure** — after the frontend move a retry still posts only to the lanes that have not taken the post (existing `composer-lanes.spec.ts` moves with the code and must stay green unchanged in substance, Task 7).

---

## File Structure

**Backend — new module `backend/src/Service/Social/Publishing/`**

| Path | Responsibility |
|---|---|
| `PostWriting.php` | Picks the network writer and runs it under `ActingAccountGuard` |
| `ComposerContexts.php` | Builds a composer's starting state for a reply, a quote, a link |
| `SocialResponses.php` | Reply/quote from an entry: find target, check permission, publish, forget caches, record share |
| `LinkSharing.php` (was `Social/LinkPosts.php`) | Share a URL; a URL naming a quotable post becomes a quote |
| `ResponseTargetFinder.php` | Entry + address → the post to answer, as the acting account |
| `SharedPostQuoteFinder.php` | URL → quotable post on the same network |
| `PostWriter/PostWriterInterface.php` | `publish()` per network |
| `ComposerDefaults/ComposerDefaultsInterface.php` | `resolveDefaults()` per network |
| `Model/PostDraftModel.php` | What the user typed: text, visibility, CW, language, idempotency key |
| `Model/PublicationModel.php` | Draft + `inReplyTo` + embed + Lemmy placement |
| `Model/LemmyCommunityPostModel.php` | Community and title of a Lemmy post |
| `Model/PostEmbed/PostEmbedModelInterface.php`, `QuotedPostEmbedModel.php`, `LinkEmbedModel.php` | What a post embeds |
| `Model/ComposerContextModel.php`, `ComposerDefaultsModel.php`, `ComposerKind.php`, `LemmyPostFieldsModel.php` (was `LinkPostChoicesModel`), `CommunityModel.php`, `PublishedPostModel.php`, `ResponseTargetModel.php`, `LinkTargetModel.php`, `LinkShareModel.php`, `SharedLinkModel.php` | moved from `Social/Social/Model` |
| `Support/ComposerStartingText.php` | moved; loses `LINK_ROOM` |
| `Exception/SocialPostTooLongException.php`, `SocialPostTextMissingException.php`, `SocialPostTitleTooShortException.php`, `SocialPostCommunityMissingException.php` | moved; publish-only |

**Backend — stays in / added to `Social/Social`:** `PostDeleter/PostDeleterInterface.php`, `PostDeletions.php`, `OwnReplyDeletion.php` (from `SocialResponses::delete`), `Model/PostContextModel.php` (was `ResponseContextModel`). Deleted: `Model/ResponseKind.php`, `PostWriting.php`, `LinkPosts.php`.

**Backend — network modules:** `Mastodon/Support/MastodonTextLength.php` (new), `Mastodon/MastodonStatusLimit.php` (new), `Lemmy/Support/LemmyPostLimits.php` (new).

**Frontend — new `frontend/src/app/reader/social/`**

| Path | Responsibility |
|---|---|
| `post-networks.service.ts` | moved from `reader/article/share/` |
| `composer/` | every file of `reader/article/composer/` except `post-actions.service.*` and `sent-comment.*` |
| `composer/compose-request.ts` | `ComposeRequest` union + `crossposts()` |
| `composer/composer-dto.ts` | composer DTOs moved out of `reader/entry-social.ts` |
| `composer/composer-api.ts` | `ComposerApi` (composer GET + send), out of `SocialPostApi` |
| `composer/composer.service.ts` | `Composer`: open the dialog, announce what went out |

**Frontend — `reader/article/post-actions/`:** `article-post-actions.service.ts` (was `PostActions`), `sent-comment.ts` (moved).

---

### Task 1: Rename `ResponseContextModel` → `PostContextModel`

The model holds generic post metadata (author, thread root, visibility, permissions, preview); every network resolver builds it.

**Files:**
- Rename: `backend/src/Service/Social/Social/Model/ResponseContextModel.php` → `PostContextModel.php`, and its test `backend/tests/Service/Social/Social/Model/ResponseContextModelTest.php` → `PostContextModelTest.php`
- Modify: every file `grep -rln "responseContext\|ResponseContextModel\|inResponseContext" backend/src backend/tests` lists (20 files today)

**Interfaces:**
- Produces: `PostContextModel` (same constructor); `SocialPostModel::$postContext`; wither `SocialPostModel::withPostContext(PostContextModel $postContext): self` (replaces `inResponseContext`). `allows(ResponseKind)` stays until Task 3.

- [ ] **Step 1: Rename class, property, wither and all uses** (PhpStorm rename refactoring, or by hand; never a bulk PhpStorm reformat).
- [ ] **Step 2: Verify** — `cd backend && composer check && php bin/phpunit --filter 'Social'` → green; `grep -rn "responseContext\|ResponseContext" src tests` → no hits.
- [ ] **Step 3: Commit** — `refactor(#2476): a post's context is PostContextModel, not a response context`

---

### Task 2: Move the write path into `Social/Publishing`; split deletion off

Pure move plus the deletion split; behaviour and signatures unchanged except where noted.

**Files:**
- Move (namespace `App\Service\Social\Publishing\…`): `PostWriting`, `ComposerContexts`, `SocialResponses`, `ResponseTargetFinder`, `SharedPostQuoteFinder`, `PostWriter/PostWriterInterface`, `ComposerDefaults/ComposerDefaultsInterface`, `Support/ComposerStartingText`, the Model classes listed in File Structure (keep `LinkPostChoicesModel`/`LinkPosts` names until Tasks 3–4), and the four publish-only exceptions. `ResponseKind` moves to `Publishing/Model` for now.
- Create: `backend/src/Service/Social/Social/PostDeleter/PostDeleterInterface.php`, `backend/src/Service/Social/Social/PostDeletions.php`, `backend/src/Service/Social/Social/OwnReplyDeletion.php`
- Modify: `Bluesky/BlueskyPostWriter.php`, `Mastodon/MastodonPostWriter.php`, `Lemmy/LemmyPostWriter.php` (implement both interfaces), `Social/OwnPostDeletion.php`, `Social/ActingAccountChooser.php`, `Controller/Api/EntrySocialPostController.php`, `Controller/Api/SocialLinkPostController.php`, `Http/ComposerContextJson.php`, `Http/PublishedPostJson.php`, `Http/Problem/ExceptionProblems/SocialInteractionProblems.php`, the three `Dto/Social/*` requests, `Mastodon/Support/MastodonVisibility.php`, `Lemmy/LemmyCommunities.php`, `tests/Support/Fake*ComposerDefaults.php`, `tests/Support/FakeMastodonNetwork.php`, `tests/Support/QueuedLinkPosts.php`, and the tests mirroring moved classes (move them to `tests/Service/Social/Publishing/`).
- Modify: `docs/architecture.md` §9 table (`Social` row gains `Publishing`) and the "network's account side" bullet: networks depend on `Social/Publishing` too; `Social/Social` never names it.

**Interfaces:**
- Produces:
  - `PostDeleterInterface` with `#[AutoconfigureTag('app.social_post_deleter')]`: `delete(SocialAccount $account, string $postId): PostDeletion` (the method and docblock move off `PostWriterInterface`). The three writers implement it beside `PostWriterInterface`; their existing `#[AsTaggedItem]` index serves both tags.
  - `PostDeletions::delete(SocialAccount $account, string $postId): PostDeletion` — locator `app.social_post_deleter`, run under `ActingAccountGuard::actAs` (what `PostWriting::delete` did). `PostWriting::delete` is removed.
  - `OwnReplyDeletion::delete(User $user, Entry $entry, string $address): void` — body of `SocialResponses::delete` with `findOwnReply` and the cache forgetting; `EntrySocialPostController::deleteReply` calls it. `SocialResponses` loses `delete`, `findOwnReply` and the dependencies only they used.
  - `ResponseTargetFinder` absorbs `ActingAccountChooser::forResponse` as a private `requireActingAccount(User $user, ResponseTargetModel $target, SocialNetwork $network): SocialAccount` (inject `SocialAccountForUser`); `forResponse` and the `ResponseTargetModel` import leave `ActingAccountChooser`.

- [ ] **Step 1: Write the failing test** `tests/Service/Social/Social/PostDeletionsTest.php`:
  - `testDeletesThroughTheWriterOfTheAccountsNetwork` — a recording deleter keyed `mastodon`; `delete($mastodonAccount, '109')` returns its `PostDeletion::Deleted` and it saw `('109')`.
  - `testRefusesAnAccountThatNeedsReconnecting` — account `needs_reconnect` → `SocialAccountNeedsReconnectException`, deleter never called.
- [ ] **Step 2: Run** — `php bin/phpunit tests/Service/Social/Social/PostDeletionsTest.php` → FAIL (class missing).
- [ ] **Step 3: Do the moves and the split.** Move `SocialResponsesTest`'s delete cases to `tests/Service/Social/Social/OwnReplyDeletionTest.php` unchanged in substance.
- [ ] **Step 4: Verify** — `composer check` (cycle rule green, no `Social/Social` → `Publishing` edge: `grep -rn "Social\\\\Publishing" src/Service/Social/Social` → no hits), `composer md`, `php bin/phpunit` → green, `bin/console lint:container` → OK.
- [ ] **Step 5: Commit** — `refactor(#2476): the social write path is a Publishing module; deleting stays in Social`

---

### Task 3: One `publish()` per network

**Files:**
- Create: `Publishing/Model/PublicationModel.php`, `Publishing/Model/LemmyCommunityPostModel.php`, `Publishing/Model/PostEmbed/PostEmbedModelInterface.php`, `QuotedPostEmbedModel.php`, `LinkEmbedModel.php`, `backend/src/Dto/Social/PostDraftFields.php` (trait)
- Rename: `Publishing/LinkPosts.php` → `LinkSharing.php` (+ test)
- Delete: `Publishing/Model/ResponseKind.php`
- Modify: `PostWriterInterface`, `PostWriting`, the three network writers, `SocialResponses`, `LinkSharing`, `ComposerContexts`, `SharedPostQuoteFinder`, `ComposerKind`, `PostContextModel` (drop `allows`), `PostDraftModel`, `Dto/Social/PostDraftRequest.php`, `Dto/Social/LinkPostDraftRequest.php`, both controllers, `tests/Support/QueuedLinkPosts.php`
- Test: `tests/Service/Social/Publishing/Model/PublicationModelTest.php`, `tests/Service/Social/Publishing/Model/ComposerKindTest.php`, the three writer tests, `PostWritingTest`, `SocialResponsesTest`, `LinkSharingTest`

**Interfaces:**
- Produces:
  - `PostDraftModel(string $text, ?string $visibility = null, ?string $contentWarning = null, ?string $language = null, ?string $idempotencyKey = null)` — `title`/`communityId` leave it; `VISIBILITIES` and `withText()` stay; `$idempotencyKey` docblock says "a resent post".
  - `LemmyCommunityPostModel(?int $communityId, ?string $title)` — "where a Lemmy post goes and what it is called".
  - `PublicationModel(PostDraftModel $draft, ?SocialPostModel $inReplyTo = null, ?PostEmbedModelInterface $embed = null, ?LemmyCommunityPostModel $lemmy = null)`, `static fromDraft(PostDraftModel $draft): self`, withers `withReplyTo(SocialPostModel $post): self`, `withEmbed(PostEmbedModelInterface $embed): self`, `withLemmy(LemmyCommunityPostModel $lemmy): self`, `withDraft(PostDraftModel $draft): self`.
  - `QuotedPostEmbedModel(SocialPostModel $post)`; `LinkEmbedModel(string $url, string $title)` (title only feeds Lemmy's suggested title in Task 4; writers ignore it).
  - `PostWriterInterface::publish(SocialAccount $account, PublicationModel $publication): PublishedPostModel` — replaces `reply`, `quote`, `share`; carries their union of `@throws`.
  - `PostWriting::publish(SocialAccount $account, PublicationModel $publication): PublishedPostModel` — replaces `respond` and `share`.
  - `ComposerKind` keeps `Reply|Quote|Link`; gains `isAllowedBy(PostContextModel $context): bool` (Reply → `canReply`, Quote → `canQuote`, Link → true). All `ResponseKind` uses switch to `ComposerKind`; the `ComposerKind::from($kind->value)` bridge goes. The entry composer route takes `?kind=` as a string validated by `#[MapQueryParameter(filter: \FILTER_VALIDATE_REGEXP, options: ['regexp' => '/^(reply|quote)$/'])]` (default `reply`, anything else 400, as today) and hands `ComposerKind::from($kind)` on.
  - `SocialResponses::respond(User $user, ResponseTargetModel $target, ComposerKind $kind, PublicationModel $publication): PublishedPostModel`; `prepareContext(User, ResponseTargetModel, ComposerKind)`.
  - `LinkSharing::publish(User $user, LinkTargetModel $target, PublicationModel $publication): PublishedPostModel` (was `share`); `prepareContext` unchanged.
  - Trait `PostDraftFields` holds `visibility`, `contentWarning`, `language`, `idempotencyKey`, `accountId` with their constraints and `buildDraft(string $text): PostDraftModel`; `PostDraftRequest` (text NotBlank) and `LinkPostDraftRequest` (text may be blank, plus `title`, `communityId`) use it. Both expose `toPublication(): PublicationModel` (`LinkPostDraftRequest` adds `withLemmy` when `title` or `communityId` is set); `PostDraftRequest::toTarget` stays.
- Network mapping inside each writer (one private method per attachment; order of checks as today):
  - Bluesky: `inReplyTo` → `reply` refs; `QuotedPostEmbedModel` → `app.bsky.embed.record`; `LinkEmbedModel` → `BlueskyExternalEmbeds::prepareEmbed`, built only inside the lazy fields closure.
  - Mastodon: `inReplyTo` → `in_reply_to_id`; quote → `quoted_status_id`; link → `textCarrying($url, $text)`.
  - Lemmy: `inReplyTo` → `/api/v3/comment`; otherwise `/api/v3/post` with `community_id` (missing → `SocialPostCommunityMissingException`, message unchanged), title (`requireValidTitle` on `$publication->lemmy?->title`), `url` only with a `LinkEmbedModel`, `body` when text is not blank; quote → `SocialPostRefusedException('Lemmy takes no quotes.')`.

- [ ] **Step 1: Write the failing tests**
  - `PublicationModelTest::testFromDraftCarriesNothingElse` — `inReplyTo`, `embed`, `lemmy` all null; `testWithersReturnACopy` — original unchanged after `withReplyTo`.
  - `ComposerKindTest::testAReplyNeedsTheAuthorsReplyPermission` (`canReply: false` → false), `testAQuoteNeedsTheQuotePermission`, `testALinkNeedsNoPermission`.
  - `BlueskyPostWriterTest::testPublishesAStandalonePost` — createRecord fields have `text`, `langs` `['de']`, and no `reply` / `embed` key; `testAReplyThatQuotesCarriesBothTheReplyRefsAndTheRecordEmbed`; keep every existing reply/quote/share test, rewritten to `publish(...)` with the same payload assertions, including the resend-finds-its-record test asserting **no `uploadBlob` call**.
  - `MastodonPostWriterTest::testPublishesAStandaloneStatus` — JSON body is exactly `['status' => 'Hallo', 'visibility' => 'unlisted', 'language' => 'de']`; `testAReplyThatQuotesCarriesBothIds` — `in_reply_to_id` and `quoted_status_id` both set.
  - `LemmyPostWriterTest::testATopLevelPostWithoutALinkSendsNoUrl` — POST `/api/v3/post` body has `name`, `community_id`, `body`, and no `url`; existing link-post and comment tests rewritten to `publish(...)`; `testRefusesAQuote` message `Lemmy takes no quotes.`
  - `PostWritingTest::testPublishesThroughTheWriterOfTheAccountsNetwork`.
- [ ] **Step 2: Run** — `php bin/phpunit tests/Service/Social` → FAIL on the new tests.
- [ ] **Step 3: Implement** the interfaces above; each writer's existing private core (`published()`) becomes the body behind `publish()`.
- [ ] **Step 4: Verify** — `composer check`, `composer md`, `php bin/phpunit` → green; `EntrySocialPostControllerTest` and `SocialLinkPostControllerTest` pass unchanged.
- [ ] **Step 5: Commit** — `refactor(#2476): a network writer publishes one PublicationModel; reply, quote and link are what it carries`

---

### Task 4: Composer defaults from the publication; a context may have no subject

**Files:**
- Modify: `ComposerDefaultsInterface`, `Bluesky/BlueskyComposerDefaults.php`, `Mastodon/MastodonComposerDefaults.php`, `Lemmy/LemmyComposerDefaults.php`, `ComposerContexts`, `ComposerContextModel`, `ComposerDefaultsModel`, `ComposerStartingText`, `Mastodon/MastodonPostWriter.php`, `Http/ComposerContextJson.php`, both `tests/Support/Fake*ComposerDefaults.php`
- Rename: `Publishing/Model/LinkPostChoicesModel.php` → `LemmyPostFieldsModel.php`
- Modify (frontend, the key only): `frontend/src/app/reader/entry-social.ts` (`ComposerContextDto.target` → `subject: ComposerSubjectDto | null`), its readers in `composer-dialog.component.*`, `composer-lane.ts` (`sentAs`), and the stub in `frontend/e2e/crosspost-composer.spec.ts`
- Test: the three `*ComposerDefaultsTest`, `tests/Http/ComposerContextJsonTest.php`, `tests/Service/Social/Publishing/ComposerContextsTest.php` (create if absent)

**Interfaces:**
- Produces:
  - `ComposerDefaultsInterface::resolveDefaults(SocialAccount $account, PublicationModel $publication, \Closure $threadStarter): ComposerDefaultsModel` replaces `resolveDefaults`, `forAccount`, `forLinkFrom`; `$threadStarter` keeps its docblock ("the entry's own post, found only when a network asks for it") and is `static fn (): ?SocialPostModel => null` for anything but a reply.
  - Mastodon: max characters from the instance; visibility = `pickStricter(answered-or-quoted post visibility ?? 'public', account privacy)` when there is an `inReplyTo` or a `QuotedPostEmbedModel`, else the account privacy; mentions only with `inReplyTo`; minus `MastodonPostWriter::LINK_ROOM` with a `LinkEmbedModel`. `LINK_ROOM` (and its two parts) moves from `ComposerStartingText` to `MastodonPostWriter` (the writer appends the link).
  - Lemmy: `LemmyPostFieldsModel` (same fields as `LinkPostChoicesModel`) when the publication has no `inReplyTo` and has a `LinkEmbedModel`, title clipped from `LinkEmbedModel::$title`; `ComposerDefaultsModel::$linkPost` → `$lemmyPost`.
  - Bluesky: always `BlueskyPostWriter::MAX_GRAPHEMES`.
  - `ComposerContextModel::$target` → `public PostPreviewModel|LinkCardContent|null $subject`; docblocks drop "reply or quote"; `$canSend` is "whether the network lets the user send this kind".
  - `ComposerContexts`: public `buildReplyContext(SocialPostModel $post, ResponseTargetModel $target)`, `buildQuoteContext(SocialPostModel $post, string $quote)` (merges `buildQuoteContext` and `buildQuotedLinkContext`; `''` for an entry quote), `buildLinkContext(SocialAccount $account, SharedLinkModel $link)`; each builds a `PublicationModel` from an empty draft to ask for defaults.
  - `ComposerContextJson`: key `subject` (row or `null`), `LinkPostRow` → `LemmyPostRow`, the JSON key `lemmy` unchanged, `kind` type `'reply'|'quote'|'link'`.

- [ ] **Step 1: Write the failing tests**
  - `MastodonComposerDefaultsTest`: `testAStandalonePostStartsFromTheAccountsPrivacyAndTheInstanceLimit` (instance 500, privacy `unlisted` → `(500, 'unlisted', [])`); `testAReplyMentionsTheAuthorAndTheThreadStarter` (existing case via `resolveDefaults`); `testAQuoteTakesTheStricterVisibilityAndMentionsNoOne` (post `private`, account `public` → `'private'`, `[]`); `testALinkPostLeavesRoomForTheLink` (500 → 475).
  - `LemmyComposerDefaultsTest::testALinkPostOffersCommunitiesAndAClippedTitle` (existing, via `resolveDefaults`); `testAReplyOffersNoPostFields` → `lemmyPost` null.
  - `BlueskyComposerDefaultsTest::testEveryPublicationHolds300Graphemes`.
  - `ComposerContextJsonTest::testAContextWithoutSubjectSendsNull` — `['subject' => null]` in the row; existing cases assert `subject` instead of `target`.
  - `ComposerContextsTest::testAMastodonLinkContextStartsFromTheTitleWithinTheLinkBudget` — title of 600 characters, instance 500 → `text` fits 475 and `maxCharacters` 475.
- [ ] **Step 2: Run** — `php bin/phpunit tests/Service/Social tests/Http` → FAIL.
- [ ] **Step 3: Implement**; update the frontend key readers and the e2e stub in the same commit.
- [ ] **Step 4: Verify** — `composer check`, `composer md`, `php bin/phpunit` → green; `docker compose exec -T frontend npm run check` → green.
- [ ] **Step 5: Commit** — `refactor(#2476): composer defaults follow what the publication carries; a context may have no subject`

---

### Task 5: Link posts send their link in the body

**Files:**
- Modify: `backend/src/Dto/Social/LinkPostDraftRequest.php` (gains `network` and `url` with `LinkTargetQuery`'s constraints, and `toLinkTarget(): LinkTargetModel` with title and quote `''` — today's POST sends neither, and publishing never reads them), `backend/src/Controller/Api/SocialLinkPostController.php` (POST drops `#[MapQueryString]`; the GET keeps `LinkTargetQuery`), `frontend/src/app/reader/social-post-api.ts` (`send` for a link puts `network` and `url` in the body, no params), `frontend/e2e/crosspost-composer.spec.ts` (route stub asserts the body)
- Test: `backend/tests/Controller/Api/SocialLinkPostControllerTest.php`, `frontend/src/app/reader/social-post-api.spec.ts`

**Interfaces:**
- Consumes: `LinkSharing::publish(User, LinkTargetModel, PublicationModel)` (Task 3).
- Produces: `POST /api/social/links` body `{network, url, text, accountId?, visibility?, contentWarning?, language?, idempotencyKey?, title?, communityId?}` — `title` stays the Lemmy post title, as today.

- [ ] **Step 1: Write the failing tests**
  - `SocialLinkPostControllerTest::testPostsTheLinkNamedInTheBody` → 201, writer saw the URL; `testAUrlOnlyInTheQueryStringIsMissing` → 422; `testAUrlNamingAQuotablePostWithBlankTextIsRefused` → 422 `social_post_text_missing` (URL in the body).
  - `social-post-api.spec.ts`: `send` of a link target → `POST /api/social/links`, `request.params.keys()` empty, body contains `network` and `url` beside the draft.
- [ ] **Step 2: Run** — backend test and `docker compose exec -T frontend npx jest src/app/reader/social-post-api.spec.ts` → FAIL.
- [ ] **Step 3: Implement.**
- [ ] **Step 4: Verify** — `php bin/phpunit tests/Controller/Api/SocialLinkPostControllerTest.php`, `composer check`, `docker compose exec -T frontend npm run check` → green.
- [ ] **Step 5: Commit** — `refactor(#2476): a link post names its link in the body, not the query string`

---

### Task 6: Every writer checks the length before it sends

**Files:**
- Create: `backend/src/Service/Social/Mastodon/Support/MastodonTextLength.php`, `backend/src/Service/Social/Mastodon/MastodonStatusLimit.php`, `backend/src/Service/Social/Lemmy/Support/LemmyPostLimits.php`
- Modify: `MastodonPostWriter`, `MastodonComposerDefaults` (reads `MastodonStatusLimit`), `LemmyPostWriter`, `LemmyComposerDefaults` (both read `LemmyPostLimits`; neither reads the other's constants)
- Test: `tests/Service/Social/Mastodon/Support/MastodonTextLengthTest.php`, `tests/Service/Social/Mastodon/MastodonStatusLimitTest.php`, `MastodonPostWriterTest`, `LemmyPostWriterTest`

**Interfaces:**
- Produces:
  - `MastodonTextLength::count(string $text): int` — the frontend's `postLength(…, 'mastodon')` rule ported exactly: every `https?://\S+` counts 23; `@user@remote.host` counts as `@user`; the rest in code points.
  - `MastodonStatusLimit::findMaxCharacters(SocialAccount $account): int` — `/api/v2/instance` `configuration.statuses.max_characters`, 500 when unanswered (moved from `MastodonComposerDefaults::resolveMaxCharacters`).
  - `MastodonPostWriter::publish` throws `SocialPostTooLongException('A Mastodon post on this server holds %d characters.')` before any POST when `count(final text) > findMaxCharacters()`; the final text is the text after `textCarrying` for a link.
  - `LemmyPostLimits::MAX_CHARACTERS = 10000`, `MIN_TITLE_CHARACTERS = 3`, `MAX_TITLE_CHARACTERS = 200`.

- [ ] **Step 1: Write the failing tests**
  - `MastodonTextLengthTest`: `'Hallo'` → 5; `'see https://example.com/' . str_repeat('a', 200)` → 4 + 23; `'@alice@example.social hi'` → 9; `'👍🏽'` → 2 (code points, as Mastodon and the SPA count).
  - `MastodonStatusLimitTest`: answer 1000 → 1000; failing instance call → 500.
  - `MastodonPostWriterTest::testRefusesTextOverTheServersLimitWithoutSending` (limit 500, 501 code points → exception, no POST recorded); `testLongLinksCountAsTwentyThreeCharacters` (480 characters + a 300-character URL → sent).
  - `LemmyPostWriterTest`: existing length and title tests stay green.
- [ ] **Step 2: Run** — `php bin/phpunit tests/Service/Social/Mastodon tests/Service/Social/Lemmy` → FAIL.
- [ ] **Step 3: Implement.**
- [ ] **Step 4: Verify** — `composer check`, `composer md`, `php bin/phpunit` → green.
- [ ] **Step 5: Commit** — `refactor(#2476): every writer checks the length before it sends; Mastodon counts as Mastodon does`

---

### Task 7: Move the composer to `reader/social/composer/`

Moves and renames only.

**Files:**
- Move: every file in `frontend/src/app/reader/article/composer/` except `post-actions.service.*` and `sent-comment.*` → `frontend/src/app/reader/social/composer/`; `reader/article/share/post-networks.service.*` → `reader/social/post-networks.service.*`
- Create: `reader/social/composer/composer-dto.ts` (moved from `entry-social.ts`: `ComposerCardDto`, `PostComposerSubjectDto`, `LinkComposerSubjectDto`, `ComposerSubjectDto`, `ComposerKind`, `ComposerContextDto`, `LemmyPostFieldsDto` (was `LemmyLinkFieldsDto`), `MASTODON_VISIBILITIES`, `MastodonVisibility`, `PostDraftDto`, `PublishedPostDto`); `reader/social/composer/composer-api.ts` (`ComposerApi.context(request, accountId?)` and `send(request, draft)`, moved from `SocialPostApi.composer`/`send` with their private helpers and spec cases)
- Rename: `LemmyLinkFieldsComponent` → `LemmyPostFieldsComponent` (`lemmy-post-fields.component.*`, selector `app-lemmy-post-fields`)
- Modify: every importer (`grep -rn "article/composer\|share/post-networks\|from '\.\./composer" frontend/src/app`), `ComposerLanes.controlId` → `compose-${control}[-${network}]`, textarea id `compose-text`, translation keys in all four `public/i18n/*.json`: `reader.composer.*` → `social.composer.*`, `reader.posted.*` → `social.posted.*`, `reader.link.many` → `social.composer.crosspostHeading`, `reader.composer.link.sendBoth` → `social.composer.sendToBoth`. `reader.{reply,quote,link}.{network}` and `reader.{reply,quote}Forbidden.*` stay: article actions share them.
- Fix on the way: the stray duplicated doc comment above `ComposerLanes.controlId`; `PublishedPostDto`'s "A reply the network took".

- [ ] **Step 1: Move, rename, re-key.**
- [ ] **Step 2: Verify** — `docker compose exec -T frontend npm run check` → green (spec suites moved with their code, `composer-lanes.spec.ts` unchanged in substance); `grep -rn "reader\.composer\.\|reader\.posted\.\|reply-text\|article/composer/composer" frontend/src frontend/e2e` → only `reader/article/post-actions` hits; `jq` shows no `reader.composer` or `reader.posted` in any of the four JSON files.
- [ ] **Step 3: Commit** — `refactor(#2476): the composer is a social feature of its own, not part of the article view`

---

### Task 8: A generic `Composer` service; the article keeps its side effects

**Files:**
- Create: `frontend/src/app/reader/social/composer/compose-request.ts`, `frontend/src/app/reader/social/composer/composer.service.ts` (+ specs)
- Move/rename: `reader/article/composer/post-actions.service.ts` → `reader/article/post-actions/article-post-actions.service.ts` (`ArticlePostActions`), `sent-comment.*` beside it (+ specs); remove the now-empty `reader/article/composer/`
- Modify: `composer-dialog.component.{ts,html}`, `composer-lane.ts`, `composer-lanes.ts`, `composed-post.ts`, `post-sent-dialog.component.{ts,html}`, `composer-api.ts`, and every `PostActions` importer (`social-counts`, `entry-comments`, `selection-actions`, `reader-view`, `share-button`, `entry-state-actions` and their specs)

**Interfaces:**
- Produces:
  - `ComposeRequest = ReplyRequest | QuoteRequest | LinkRequest` (was `PostTargetDto`; `ReplyRequest`/`QuoteRequest` = `{kind, entryId, network, address}`, `LinkRequest` = `{kind: 'link', network, url, title, quote}`), and `crossposts(request: ComposeRequest): boolean` (link → true, else false). `ComposerLanes.networksFor` uses `crossposts()` instead of `kind === 'link'`.
  - `Composer.open(request: ComposeRequest): Observable<SentPost[]>` — opens `ComposerDialogComponent` with `panelClass: 'app-dialog'`, emits the sent posts, nothing when closed. `Composer.announce(posts: SentPost[]): void` — opens `PostSentDialogComponent`.
  - `ArticlePostActions` keeps `reply`, `quote`, `postLink`, `delete`, `deletePost` with today's signatures and side effects, delegating opening and announcing to `Composer`.
  - `SentPost.target` → `subject: ComposerSubjectDto | null`; the dialog renders `app-composer-subject` and the sent dialog its subject only when non-null.

- [ ] **Step 1: Write the failing tests**
  - `compose-request.spec.ts`: `crossposts` true for a link request, false for reply and quote.
  - `composer.service.spec.ts`: `open` opens `ComposerDialogComponent` with the request as data; closing with posts emits them once; closing with `undefined` completes without emitting; `announce` opens `PostSentDialogComponent` with the posts.
  - `composer-dialog.component.spec.ts::renders no subject when the context has none` — fake context with `subject: null` → no `app-composer-subject` element.
  - `post-sent-dialog.component.spec.ts::shows no subject for a post without one`.
- [ ] **Step 2: Run** — `docker compose exec -T frontend npx jest src/app/reader/social` → FAIL.
- [ ] **Step 3: Implement**; move the former `post-actions.service.spec.ts` cases to `article-post-actions.service.spec.ts`, faking `Composer` instead of `Dialog`.
- [ ] **Step 4: Verify** — `docker compose exec -T frontend npm run check` and `docker compose exec -T frontend npm run build` → green.
- [ ] **Step 5: Commit** — `refactor(#2476): a generic Composer opens every post; the article keeps its own side effects`

---

### Task 9: Whole-branch gates and PR

- [ ] **Step 1: /simplify** over the branch; commit its fixes on their own.
- [ ] **Step 2: Backend gates** — `composer check`, `composer md`, `php bin/phpunit` (SQLite) and `docker compose exec php composer test:parallel` (MySQL) in parallel → green; `composer infection:diff` ≥ `minMsi`.
- [ ] **Step 3: Frontend gates** — `docker compose exec -T frontend npm run check`, `npm run build` → green (never at the same time as the backend suites).
- [ ] **Step 4: e2e from the main checkout** — `composer e2e` and `npm run e2e` (incl. `crosspost-composer.spec.ts`) → green; any red is fixed, not called flaky.
- [ ] **Step 5: Live check on :4200 (read-only)** — open the reply, quote and share-as-link composers on a Bluesky and a Mastodon entry; each shows its subject, counter and account picker as before; close without sending. No real post is sent without Lars's go.
- [ ] **Step 6: Scan the dev log** — `ls -t backend/var/log/dev-*.log | head -1 | xargs tail -n 200 | jq 'select(.level_name != "DEBUG" and .level_name != "INFO")'` → no new deprecations or errors.
- [ ] **Step 7: PR into `develop`** — body `Closes #2476`, lists the two API changes (composer `subject`, link POST body) and anything skipped in /simplify; no attribution. Merge only on Lars's word.
