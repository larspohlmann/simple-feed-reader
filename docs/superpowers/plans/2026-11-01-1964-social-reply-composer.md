# #1964 Reply composer, replies and delete — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Reply to a Bluesky/Mastodon post (the article's post or any reply in the comments panel) from a composer dialog, and delete a reply written by one of the user's connected accounts.

**Architecture:** A new tagged strategy per network (`app.social_post_replier`) publishes and deletes a post; `SocialReplies` orchestrates (find the target on the acting account, refuse when replying is off, publish, forget the caches). A read-only composer-context endpoint gives the frontend the account choice, the length limit, Mastodon's default visibility and the mention prefill. Replies in the comments payload learn `canReply` and `isOwn` (author identity matched against the user's accounts). The frontend opens `ReplyComposerDialogComponent` in `<app-overlay-panel fillOnMobile>`, inserts the published reply into the panel and bumps the reply count locally.

**Tech Stack:** Symfony 7.4 / PHP 8.4, Angular 20 signals, CDK Dialog, Transloco, Jest, PHPUnit 12.

**Spec:** GitHub #1964 and the umbrella #1962 (agreed with Lars via /grilling, 2026-11-01).

## Global Constraints

- Same network only; acting account resolves as for likes; an explicit `accountId` must be the user's, on the entry's network, connected.
- Bluesky limit 300 graphemes (checked server-side with `grapheme_strlen`); Mastodon limit from `/api/v2/instance` `configuration.statuses.max_characters` (fallback 500), enforced by the instance.
- Mastodon visibility default = stricter of the target's visibility and the account's `source.privacy`; order public < unlisted < private < direct.
- Mastodon prefill: `@author` of the answered post, plus `@rootAuthor` (the article's post author) when different. Bluesky: no prefill.
- Mastodon sends carry `Idempotency-Key` (minted when the composer opens, sent in the body as `idempotencyKey`). No server-side dedupe.
- Language from the browser locale's primary subtag; not shown.
- Bluesky rich text: link, hashtag and mention facets built at send with UTF-8 byte offsets; an unresolvable `@handle` stays plain text.
- Limiter `social_writes` (30 / 10 min) covers reply and delete; the composer context spends `social_viewer`.
- Refusals map to existing problems (`social_post_refused` 409, `social_token_scope_missing` 409 naming `write:statuses`, …). Too-long Bluesky text → new `social_reply_too_long` 422.
- Restricted actions are disabled with a tooltip, never hidden (`canReply` false on Bluesky `viewer.replyDisabled`).
- Failed send keeps the text in the open dialog (inline `<app-error-banner>`, not a toast); closing discards silently; Send disabled while in flight.
- Delete routes through `ConfirmService` with `danger: true`.
- CLAUDE.md Clean Code rules; `HTTP_*` constants; no new comments unless they clear the bar; commit `type(#1964): …`; no attribution.

## File structure

Backend (`backend/src`):
- `Service/Fetch/Model/ApiRequestModel.php` — add `delete(url, headers)`.
- `Service/Social/Model/PostReferenceModel.php` (new) — `{uri, contentId}` Bluesky strong ref.
- `Service/Social/Model/SocialPostModel.php` — add `canReply`, `authorIdentity`, `threadRoot` (`?PostReferenceModel`), `visibility` (`?string`, Mastodon); withers `notReplyable()`, `writtenBy(string)`, `inThreadOf(PostReferenceModel)`, `seenAs(string $visibility)`.
- `Service/Social/PostResolver/{Bluesky,Mastodon}PostResolver.php` — fill the new fields.
- `Service/Social/Model/ReplyDraftModel.php` (new) — `text, ?visibility, ?contentWarning, ?language, ?idempotencyKey`.
- `Service/Social/Model/PublishedReplyModel.php` (new) — `address, ?url`.
- `Service/Social/PostReplier/{PostReplierInterface,BlueskyPostReplier,MastodonPostReplier}.php` (new) — `publish(SocialPostModel $target, ReplyDraftModel $draft): PublishedReplyModel`, `delete(SocialPostModel $ownPost): void`.
- `Service/Social/BlueskyFacets.php` (new service; mentions need the network) + `Service/Social/Support/BlueskyTextSpans.php` (new static: finds link/hashtag/mention spans with byte offsets).
- `Service/Social/BlueskyRecords.php` — add `createPost(SocialAccount, array $record): string` (returns the AT URI) and reuse `delete`.
- `Service/Social/MastodonAccountApi.php` — add `writeAs(SocialAccount, ApiRequestModel, string $scope)` (actOnPost delegates); 422 → `SocialPostRefusedException` with Mastodon's `error`.
- `Service/Social/PostReplying.php` (new, like `PostReposting`) — locator + guard.
- `Service/Social/SocialReplies.php` (new) — `reply(User, Entry, ?string $address, ?int $accountId, ReplyDraftModel)`, `delete(User, Entry, string $address)`.
- `Service/Social/SocialPostFinder.php` — add `findAs(SocialAccount, Entry, ?string $address)`.
- `Service/Social/ReplyComposer.php` (new) — composer context; `Service/Social/Model/ComposerContextModel.php` (new); `Service/Social/MastodonInstanceFacts.php` (new: max characters, account default privacy).
- `Service/Social/Support/MastodonVisibility.php` — add `stricterOf(string, string): string`.
- `Service/Social/Exception/SocialReplyTooLongException.php` (new) + arm in `Http/Problem/ExceptionProblems/SocialInteractionProblems.php`.
- Comments: `Service/Comments/Model/CommentViewerModel.php` (+`canReply`, `isOwn`), `Model/CommentAuthorModel.php` (+`identity`), `CommentsSource/CommentsSourceInterface::read(CommentsRequest, string)`, `Social/SocialRepliesSource::read` marks own replies, `Support/CommentPost`, `BlueskyThreadReplies`, `MastodonContextReplies`.
- `Dto/Social/ReplyRequest.php` (new, validated body) → `toDraft()`.
- `Controller/Api/EntryReplyController.php` (new): `GET /{id}/social/composer`, `POST /{id}/social/replies`, `DELETE /{id}/comments/reply`.
- `Http/ComposerContextJson.php`, `Http/PublishedReplyJson.php` (new); `Http/SocialViewerJson.php` and `Http/CommentsJson.php` gain `canReply` (+ `isOwn`).

Frontend (`frontend/src/app`):
- `reader/entry-social.ts` — `SocialViewerDto.canReply`, `ComposerContextDto`, `ReplyDraftDto`, `PublishedReplyDto`.
- `reader/entry-comment.ts` — `EntryCommentPostDto.canReply`, `isOwn`.
- `reader/social-post-api.ts` — `composer`, `reply`, `deleteReply`.
- `reader/article/reply-composer/` (new): `reply-composer-dialog.component.{ts,html,scss,spec.ts}`, `reply-composer.service.ts(+spec)`, `post-length.ts(+spec)`.
- `reader/article/social-counts/social-counts-row.component.*` — reply count becomes a button when `canReply !== null`; output `replyRequested`.
- `reader/article/social-counts/social-counts.service.ts` — `repost` view carries `canReply`; `stepReplies(entryId)`.
- `reader/article/entry-comments/comments.service.ts` — `insertReply`, `removeReply`.
- `reader/article/entry-comments/entry-comments.component.*` — reply + delete per reply.
- `public/i18n/{en,de}.json` — `reader.reply.*`, `reader.composer.*`, `reader.deleteReply.*`.

---

### Task 1: Post model learns reply facts; resolvers fill them

**Files:** Modify `SocialPostModel.php`, both resolvers, `Support/MastodonVisibility.php`; create `Model/PostReferenceModel.php`. Tests: `tests/Service/Social/Model/SocialPostModelTest.php`, `tests/Service/Social/PostResolver/*ResolverTest.php`, `tests/Service/Social/Support/MastodonVisibilityTest.php`.

**Produces:** `SocialPostModel::$canReply` (default true), `::$authorIdentity` (`?string`; Bluesky author DID, Mastodon `username@host` — local `acct` gets the account's host, remote `acct` is already `user@host`), `::$threadRoot` (`?PostReferenceModel`, Bluesky `record.reply.root`), `::$visibility` (`?string`, Mastodon). `MastodonVisibility::stricterOf(string $left, string $right): string`.

- [ ] Failing tests: Bluesky resolver keeps `viewer.replyDisabled: true` as `canReply false`, `author.did` as `authorIdentity`, `record.reply.root {uri,cid}` as `threadRoot`; Mastodon resolver keeps `visibility`, maps local `acct: "alice"` on `https://mastodon.example` to `alice@mastodon.example` and remote `acct: "ann@hachyderm.io"` unchanged. `stricterOf('public','private') === 'private'`, unknown values count as `public`.
- [ ] Implement via the private `with()` (extend with the new fields; null keeps).
- [ ] Run `php bin/phpunit tests/Service/Social` → green. Commit `feat(#1964): social post knows its author, thread root, visibility and reply gate`.

### Task 2: Mastodon write plumbing and DELETE requests

**Files:** `ApiRequestModel.php` (+`delete`), `MastodonAccountApi.php` (+`writeAs`, 422 arm), tests next to them.

- [ ] Failing tests: `ApiRequestModel::delete('u')->method === 'DELETE'`; `writeAs` sends the given request authorized, maps 422 `{"error":"Validation failed: Text character limit of 500 exceeded"}` to `SocialPostRefusedException` carrying that text, 403 outside scopes → scope missing naming the scope.
- [ ] Implement; `actOnPost` becomes `writeAs($account, ApiRequestModel::post(...), $scope)`.
- [ ] Green, commit `feat(#1964): mastodon account api writes any request`.

### Task 3: Bluesky facets

**Files:** create `Support/BlueskyTextSpans.php`, `BlueskyFacets.php`; tests.

**Produces:** `BlueskyFacets::of(string $text): list<array>` — facets in Bluesky's shape: `{index:{byteStart,byteEnd}, features:[{$type:'app.bsky.richtext.facet#link', uri}|{…#tag, tag}|{…#mention, did}]}`.

- [ ] Failing tests (spans): `"hi @ann.bsky.social see https://x.test/a #tag"` yields three spans with byte offsets (test a multibyte prefix like `"é @ann.bsky.social"` to prove bytes, not chars); trailing `.,;:!?)` is trimmed from links; `#123` is no tag (digits only); an e-mail address is no mention.
- [ ] Failing tests (facets): mention resolves via `BlueskyIdentityResolver::didOf`; a `SocialAccountUnresolvableException` drops that mention, links and tags stay.
- [ ] Implement (regexes mirroring Bluesky's reference: mention `(^|\s|\()(@)([a-zA-Z0-9.-]+)(\b)`, tag `(^|\s)#([^\s#]*[^\d\s#][^\s#]*)`, link `https?://\S+`).
- [ ] Green, commit `feat(#1964): bluesky facets for links, tags and resolved mentions`.

### Task 4: Repliers per network

**Files:** create `Model/ReplyDraftModel.php`, `Model/PublishedReplyModel.php`, `PostReplier/*`, `PostReplying.php`, `Exception/SocialReplyTooLongException.php`; extend `BlueskyRecords.php`; tests in `tests/Service/Social/PostReplier/`.

**Consumes:** Task 1 fields, Task 2 `writeAs`, Task 3 facets.
**Produces:** `PostReplierInterface` (`#[AutoconfigureTag('app.social_post_replier')]`): `static network(): string`, `publish(SocialPostModel $target, ReplyDraftModel $draft): PublishedReplyModel`, `delete(SocialPostModel $ownPost): void`. `PostReplying::publish(...)`/`delete(...)` wrap in `ActingAccountGuard::actAs`.

- [ ] Bluesky failing tests (RoutedHttpClient, `SocialApiWiring`): createRecord body is `{repo: did, collection:'app.bsky.feed.post', record:{$type, text, createdAt, langs:['de'], facets, reply:{root:{uri,cid}, parent:{uri,cid}}}}` — root is `threadRoot` when set, else the target; more than 300 graphemes throws `SocialReplyTooLongException` before any call; result address is the returned URI, url `AtPostUri::webUrl`. Delete calls deleteRecord with the rkey of the own post's URI.
- [ ] Mastodon failing tests: `POST /api/v1/statuses` json `{status, in_reply_to_id, visibility, spoiler_text, language}` with header `Idempotency-Key`; empty optional fields omitted; result `{uri, url}`; scope `write:statuses`. Delete: `DELETE /api/v1/statuses/{id}`, a 404 counts as deleted.
- [ ] Implement; extend `FakeMastodonNetwork` to implement `PostReplierInterface` (calls `'reply <id> <text>'`, `'delete <id>'`).
- [ ] Green, commit `feat(#1964): publish and delete a reply on bluesky and mastodon`.

### Task 5: SocialReplies + composer context

**Files:** `SocialPostFinder.php` (+`findAs`), create `SocialReplies.php`, `ReplyComposer.php`, `MastodonInstanceFacts.php`, `Model/ComposerContextModel.php`; tests (`DbTestCase`, Fake network like `SocialRepostsTest`).

**Produces:**
- `SocialReplies::reply(User, ReplyTargetModel, ReplyDraftModel): PublishedReplyModel` with `Model/ReplyTargetModel(Entry $entry, ?string $address, ?int $accountId)`.
  Refuses with `SocialPostRefusedException('The author does not take replies on this post.')` when `!canReply`; forgets `countsCache->forgetEntry` and `comments->forget`.
- `SocialReplies::delete(User, Entry, string $address): void` — for each connected account on the entry's network (`findAllOnNetwork`), resolve the address; delete when `authorIdentity === account identity`; none → `SocialPostRefusedException('No connected account wrote this reply.')`; forgets both caches.
- `ReplyComposer::contextFor(User, ReplyTargetModel): ComposerContextModel` — `{accounts: list<SocialAccount> (connected, on network), account, maxCharacters, canReply, visibility: ?string, mentions: list<string>}`. Mastodon: `MastodonInstanceFacts::maxCharactersOf(account)` (`/api/v2/instance`, fallback 500) and `defaultVisibilityOf(account)` (`/api/v1/accounts/verify_credentials` `source.privacy`, fallback public); visibility = `stricterOf(target, default)`; mentions = `@` + target `authorIdentity`, plus the article post's author when `address !== null` and different (one extra resolve). Bluesky: 300, null, [].
- [ ] Failing tests for each bullet (account choice by id incl. a foreign id → `SocialAccountNotFoundException`; caches forgotten; refusal on reply gate; delete ownership).
- [ ] Implement; green; commit `feat(#1964): social replies service and composer context`.

### Task 6: Comments learn canReply and isOwn

**Files:** `CommentViewerModel`, `CommentAuthorModel` (+`identity`), `CommentsSourceInterface::read(CommentsRequest $request, string $answer)` and every implementation (`SocialRepliesSource`, `CommentsFeedSource`, Substack source, test fakes), `CommentsLoader`, `Support/CommentPost` (Bluesky `viewer.replyDisabled`), `BlueskyThreadReplies`/`MastodonContextReplies` (author identity as in Task 1), `CommentsJson`.

- [ ] Failing tests: Bluesky reply with `viewer.replyDisabled` → `canReply false`; a reply whose author DID equals the user's Bluesky account identity → `isOwn true`, another user's → false; Mastodon local/remote acct identity matching; JSON carries `canReply`, `isOwn`.
- [ ] Implement: `SocialRepliesSource::read` reads the user's identities on the network (`findAllOnNetwork`) and marks own replies after `pageOf` (an `EntryCommentModel::withPost()` wither + `CommentViewerModel::ownedByViewer()`).
- [ ] Green (whole Comments + Social suites), commit `feat(#1964): replies say whether the viewer can answer or delete them`.

### Task 7: HTTP — request DTO, controller, JSON, problems

**Files:** `Dto/Social/ReplyRequest.php` (`text` NotBlank + Length max 10000, `visibility` Choice public|unlisted|private|direct nullable, `contentWarning` Length max 500, `language` Regex `/^[a-z]{2,3}$/`, `idempotencyKey` Uuid nullable, `accountId` Positive nullable) with `toDraft()`; `Controller/Api/EntryReplyController.php`; `Http/ComposerContextJson.php`, `Http/PublishedReplyJson.php`; `SocialViewerJson` + `canReply`; `SocialInteractionProblems` arm `social_reply_too_long` 422; controller test `tests/Controller/Api/EntryReplyControllerTest.php` (RoutesSocialApis, Mastodon routes).

Routes: `GET /api/entries/{id}/social/composer?address=&accountId=` (`api_entries_social_composer`, `socialViewerLimiter`) → `{accounts:[{id,handle}], accountId, maxCharacters, canReply, visibility, mentions}`; `POST /api/entries/{id}/social/replies?address=` (`api_entries_social_reply`, `socialWritesLimiter`, `#[MapRequestPayload]`) → 201 `{address, url}`; `DELETE /api/entries/{id}/comments/reply?address=` (`api_entries_comment_delete_reply`, `socialWritesLimiter`) → 204.

- [ ] Failing controller tests: anonymous 401; composer context for a Mastodon post (search, instance, verify_credentials routes); reply posts with Idempotency-Key and answers 201; blank text 422; foreign entry 404 without calls; delete 204 with `DELETE …/statuses/200`; write budget shared with repost (30 then 429).
- [ ] Implement; architecture §6 checklist (bearer, JSON in/out, no browser inputs) holds. Green; `composer check`, `composer md`. Commit `feat(#1964): reply, composer context and delete-reply endpoints`.

### Task 8: Frontend API, DTOs and length counter

**Files:** `entry-social.ts`, `entry-comment.ts`, `social-post-api.ts`(+spec), `reply-composer/post-length.ts`(+spec), `testing/entry-testing.ts` defaults.

**Produces:** `SocialPostApi.composer(entryId, {address?, accountId?}): Observable<ComposerContextDto>`, `reply(entryId, address: string|null, draft: ReplyDraftDto): Observable<PublishedReplyDto>`, `deleteReply(entryId, address): Observable<void>`. `postLength(text, network): number` — Bluesky: graphemes (`Intl.Segmenter`, fallback `[...text].length`); Mastodon: code points with each `https?://\S+` counted as 23.

- [ ] Failing specs (HttpTestingController URLs/bodies; length cases incl. emoji family = 1 grapheme, URL = 23).
- [ ] Implement; `npm run check` in container. Commit `feat(#1964): social post api replies and a post length counter`.

### Task 9: Composer dialog

**Files:** `reply-composer-dialog.component.{ts,html,scss,spec.ts}`, `reply-composer.service.ts(+spec)`, i18n.

**Produces:** `ReplyComposer.open(target: ReplyTarget): Observable<PublishedReplyDto|undefined>` where `ReplyTarget = {entryId; network; address: string|null; authorName: string|null; authorHandle: string|null; excerpt: string}`; opens with `{panelClass:'app-dialog', data}`.

Dialog: `<form>` around `<app-overlay-panel fillOnMobile [heading]>`; compact target (author + 3-line clamped excerpt); loads context on open (skeleton line while loading; error banner + retry on failure); account `<select>` only when `accounts.length > 1` (changing it reloads context and keeps the text); `<textarea cdkFocusInitial>` prefilled with `mentions.join(' ') + ' '`; counter `used / max`, over-limit state disables Send; Mastodon: visibility `<select>` + "Content warning" toggle revealing an input; footer Cancel (closes, no ask) + Send (`loading` while in flight). Idempotency key `crypto.randomUUID()` minted once on open. Failure: `<app-error-banner>` with `problemMessage`, text kept. Success: `ref.close(published)`.

- [ ] Failing specs: prefill; picker hidden with one account; Send disabled empty/over-limit/in flight; failure keeps text and shows the banner; success closes with the result; same idempotency key on retry.
- [ ] Implement; design-language overlay rules; `npm run check`. Commit `feat(#1964): reply composer dialog`.

### Task 10: Wire reply and delete into the article and the panel

**Files:** `social-counts-row.component.*` (inputs `canReply: boolean|null` default null; reply count renders as `<button class="reply">` with `aria-disabled` + `reader.replyForbidden.<network>` tooltip when false; output `replyRequested`), `social-counts.component.ts` + service (`RepostView` → `SocialViewView` with `canReply`; `stepReplies(entryId)`), `comments.service.ts` (`insertReply(entryId, parentAddress|null, comment)`: top-level at index 0, nested right after the parent with `depth+1`; parent's `post.counts.replies` +1; `removeReply(entryId, address)`: drops the comment and its deeper descendants), `entry-comments.component.*` (reply button via the row's `replyRequested`; delete `app-button`-free icon button when `post.isOwn`, through `ConfirmService.ask({danger:true})`, then `api.deleteReply` → `removeReply` + `stepReplies(entryId, -1)` for top-level), i18n.

The inserted comment: `{author: account handle, authorHandle, html: escaped text with line breaks, url: published.url, publishedAt: now, depth, post: {address, counts: zeros, isLiked:false, isReposted:false, canRepost:true, canReply:true, isOwn:true}, …}`.

- [ ] Failing specs: reply pill emits; disabled pill doesn't; insert/remove helpers; delete confirms before calling the API; error toast on failed delete.
- [ ] Implement; `npm run check` + `ng build`. Commit `feat(#1964): reply from the counts row and the panel, delete own replies`.

### Task 11: Gates, simplify, review, live check

- [ ] SQLite + MySQL `composer test:parallel`, `composer check`, `composer md`, `composer infection:diff`, PhpStorm lint on changed PHP; frontend `npm run check` + `ng build`.
- [ ] /simplify; independent review subagent.
- [ ] Live on :4200 with [[social-live-test-posts]]: reply to the Bluesky and Mastodon test posts and to a reply, check on the networks, delete them again.
- [ ] Push, PR into develop with `Closes #1964`.
