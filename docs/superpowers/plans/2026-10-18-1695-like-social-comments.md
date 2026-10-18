# Like social comments (#1695) Implementation Plan

**Goal:** Every Bluesky/Mastodon comment in the article view shows its counters and a thumbs-up like button with its like count; the reader likes and unlikes it as the account that acts on the entry.

**Architecture:** The thread answers already hold each reply's address, counts and the viewer's like. `EntryCommentModel` gains `?CommentPostModel $post` (Comments module, so Comments still imports nothing from Social); the Social parsers fill it. A stateless `SocialCommentLikes` service resolves the reply through `SocialPostFinder::findReply()` and likes or unlikes it with the existing likers, then forgets the entry's cached thread (`CommentsLoader::forget()`). Frontend: the counters + like row becomes `app-social-counts-row`, shared by the post and its comments; `CommentsService.setLiked()` patches a comment optimistically.

## Backend

### Task 1: comment post model
- `src/Service/Comments/Model/CommentCountsModel.php` `(likes, reposts, replies, ?quotes)` and `CommentPostModel(string $address, CommentCountsModel $counts, bool $isLiked)`.
- `EntryCommentModel` gains a trailing `public ?CommentPostModel $post = null`; `atDepth()` carries it.
- `src/Http/CommentsJson.php`: `'post' => null | {address, counts: {likes, reposts, replies, quotes}, isLiked}`; test in `tests/Http/CommentsJsonTest.php`.

### Task 2: parsers fill it
- `src/Service/Social/Support/CommentPost.php`: `ofBlueskyPost(JsonNodeModel)` (address `uri`, counts via `PostCounts::ofBlueskyPost`, liked when `BlueskyRecordUri::likeOrNull(viewer.like)` is set) and `ofMastodonStatus(JsonNodeModel)` (address `uri` ?? `url` when absolute http, counts via `PostCounts::ofMastodonStatus`, liked when `favourited` is true). Null when the address or the counts are missing.
- `BlueskyThreadReplies::commentOf()` / `MastodonContextReplies::commentOf()` pass it. Tests in their existing test classes (counts, address, liked on the account answer, null without counts).

### Task 3: resolve and like a reply
- `SocialPostAddress::replyOrRefuse(Entry $entry, string $address)`: the entry's network, the address checked by that network's rule (AT post URI / absolute http URL), else `NotASocialPostException`.
- `SocialPostFinder::findReply(User, Entry, string $address)`: the account chosen for the entry, the reply resolved on it (shares a private `resolvedAs()` with `find()`).
- `MastodonPostResolver`: a `favourited` status resolves `alreadyLikedAs($statusId)`, so an unlike knows what to take back on both networks; the `SocialPostModel::$existingLikeId` docblock drops "Bluesky only".
- `src/Service/Social/SocialCommentLikes.php`: `like(User, Entry, string $address)` resolves and likes (Bluesky's liker already skips a liked post); `unlike()` resolves and, when `existingLikeId` is set, unlikes it. Both under `ActingAccountGuard::actAs`, then `CommentsLoader::forget($user, $entry)`.
- `CommentsLoader::forget(User, Entry)` + `CommentsCache::forget(string $key)`.
- Tests: `tests/Service/Social/SocialCommentLikesTest.php` (Mastodon fake network: like resolves + favourites, unlike of a favourited reply unfavourites, unlike of an unliked reply calls only the resolve, an address off the entry's network is refused, the cached thread is forgotten), `MastodonPostResolverTest` (favourited → existing like), `CommentsLoaderTest` (forget drops the entry).

### Task 4: endpoint
- `src/Controller/Api/EntryCommentLikeController.php`: `PUT` / `DELETE /api/entries/{id}/comments/like?address=…` (`api_entries_comment_like` / `api_entries_comment_unlike`), subscribed-entry lookup, `socialLikeLimiter`, returns `SocialLikeJson::of(bool)`. A missing address is a 400 problem.
- Functional test `tests/Controller/Api/EntryCommentLikeControllerTest.php` mirroring `EntryLikeControllerTest` (anonymous 401, like reaches the instance, unlike takes it back, an address off-network is refused, missing address 400).

## Frontend

### Task 5: shared row
- `frontend/src/app/reader/article/social-counts/social-counts-row.component.{ts,html,scss}` (`app-social-counts-row`): inputs `network`, `counts: SocialCountsDto | null`, `liked: boolean | null` (null: not likeable, the like count shows as a plain counter); output `likeToggled`. Markup and styles move from `social-counts.component.*`.
- `SocialCountsComponent` keeps loading and the optimistic like count and renders the row. Its spec keeps passing; the row gets its own spec.

### Task 6: comments
- `entry-comment.ts`: `post: EntryCommentPostDto | null` (`{address, counts: SocialCountsDto, isLiked}`).
- `ReaderApi.likeComment(entryId, address)` / `unlikeComment(...)`.
- `CommentsService.setLiked(entryId, address, isLiked)`: patches that comment's `isLiked` and likes ±1 in the `ok` state, ignores a second toggle while one is on the wire, rolls back and reports the problem on failure.
- `entry-comments.component.html`: after the embeds, `<app-social-counts-row>` for a comment with a `post`; `liked` is null unless `entry().social?.canLike`. Toast on failure through `ToastService` with `problemMessage`.
- Specs: `comments.service.spec.ts` (optimistic patch, rollback, in-flight guard), `entry-comments.component.spec.ts` (row shown for a post comment, click toggles).

## Verify
- Backend: `composer check`, `composer md`, `php bin/phpunit` (SQLite) and `docker compose exec php composer test` (MySQL), `composer infection:diff`.
- Frontend: `npm run check` in the container.
- Live: like and unlike a comment on a Bluesky and a Mastodon post on :4200.
- `/simplify`, then PR, merge when green, tag the next `v1.4.2-dev.N`.
