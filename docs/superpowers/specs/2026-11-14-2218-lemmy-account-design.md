# #2218 — Connect a Lemmy account: vote and reply — design

Written autonomously (Lars: "work autonomously from here on; we will try with an actual
account when you are finished"). Every decision Lars did not make himself is marked
**Ruling** with what it costs if wrong.

## Intent

#2217 shows a Lemmy post's score and comment count and each comment's score, with inert
up/down arrows. #2218 lets the reader act on them from their own Lemmy account, on any
instance: upvote / downvote a post and its comments, and reply to the post and to a
comment — the way Mastodon and Bluesky accounts connect and act today (#1596, #1962).

## Goals

- Connect, reconnect and disconnect a Lemmy account in Settings → Social accounts.
- Vote on a Lemmy post from the list row, the magazine card and the article header; vote on
  a Lemmy comment from its byline. The vote pill's arrows become buttons.
- Reply to a Lemmy post and to a Lemmy comment through the existing composer.
- The reader sees their own vote (up / down / none) wherever the pill shows.

## Non-goals

- Lemmy timelines, private feeds, search, import, saved-search or network-exclusion pickers:
  Lemmy posts keep arriving through community RSS feeds.
- Deleting one's own Lemmy comment, editing, reporting, saving, subscribing to communities.
- Hacker News, Reddit, PieFed / Mbin (Lemmy API only; PieFed exposes a Lemmy-compatible API
  but is not targeted or tested).
- Grouped magazine rows (still the open #2217 question).

## Constraints

- Lemmy 0.19 API v3 (lemmy.world and feddit.org both run 0.19.21; `/api/v4` is 404). Calls
  verified against a live instance: `POST /api/v3/user/login {username_or_email, password,
  totp_2fa_token?} → {jwt}`; auth is `Authorization: Bearer <jwt>`; `GET /api/v3/site` with
  auth carries `my_user.local_user_view.person {name, actor_id, display_name}`;
  `GET /api/v3/resolve_object?q=<url>` (auth) → `{post?: PostView, comment?: CommentView}`;
  `POST /api/v3/post/like {post_id, score}` and `POST /api/v3/comment/like {comment_id,
  score}` (score ∈ {1, 0, -1}) → the updated view with `my_vote` and `counts.score`;
  `POST /api/v3/comment {content, post_id, parent_id?}` → `{comment_view}`; `local_site
  .enable_downvotes` may be false. The plan's first backend task re-checks these against
  the lemmy-js-client 0.19 types before coding.
- Native iOS client stays viable (architecture.md §6): credential flow, bearer token, JSON
  in, problem+json out, no redirect.
- Every outbound call goes through the SSRF guard (`GuardedApiClient`); instance URLs https
  only (security.md "Social accounts").
- Module boundaries (architecture.md §9): Content/Comments imports nothing from Social.

## Approaches

1. **Lemmy as a third `SocialNetwork`, account-only** (chosen). `SocialNetwork::Lemmy` joins
   the enum so the account entity, sealed credentials, connector, verifier locator,
   NeedsReconnect handling, rate limiters and the Settings section are reused as is. A
   capability split keeps it out of everything feed-shaped: `SocialNetwork::feedNetworks()`
   (Bluesky, Mastodon) replaces `cases()` wherever the enum enumerates feed networks, and the
   frontend gets `ACCOUNT_NETWORKS` beside the unchanged `SOCIAL_NETWORKS`.
2. *A separate `LemmyAccount` entity and module* — rejected: duplicates the account
   lifecycle, credential sealing, guard and settings UI for one more network.
3. *Treat Lemmy entries as full social posts (`entry.social`)* — rejected: the social path
   keys off `Feed.network`, assumes likes/reposts/quotes and a timeline account; Lemmy posts
   arrive through RSS. A dedicated, small vote surface is cleaner.

## Design

### Account

- **Ruling — credential:** the connect form takes instance URL, username or email, password
  and an optional two-factor code. The server logs in once and seals **only the JWT** in the
  existing credential columns; the password is never stored. Lemmy 0.19 JWTs do not expire
  until logout or a password change, so there is no refresh: a 401 marks the account
  NeedsReconnect and reconnect logs in again. *If wrong:* a short-lived-token instance would
  need re-login; mitigated by NeedsReconnect.
- Identity `name@host` (from `actor_id`'s host), handle `@name@host`, serviceUrl
  `https://host`. Credential hint: none shown for Lemmy (the secret is a JWT).
- Connecting creates no private feed (no Home timeline); disconnect has nothing to remove.
- Account-wide limits, uniqueness, default-per-network: as for the other networks.

### Which entries and comments can be acted on

- An entry is a Lemmy post when `LemmyPostAddress::of($entry)` is non-null (the #2216
  discussion-thread shape) — not via `Feed.network`.
- **Ruling — resolution:** every vote or reply first resolves the target on the account's
  home instance with `resolve_object` (post page URL for a post, the comment's `ap_id` for a
  comment), then acts on the returned local id. One extra call per action, no special case
  for same-instance targets. *If wrong:* a little latency per vote.
- A comment's address is its `ap_id` (already emitted as the comment `url`).

### Vote state

- **Ruling — storage:** a new `discussion_vote` row per (user, entry): acting account, value
  (+1 / -1; no row = no vote), votedAt. The list reads it to show the reader's vote without
  calling Lemmy; opening the article refreshes it from the network (`my_vote` of the
  resolved post) the way `GET …/social/viewer` mirrors likes. Comment votes are not stored:
  they come with the comment thread (below).
- Entry JSON gains `myVote: 1 | 0 | -1 | null` — null when the entry is no Lemmy post or the
  reader has no connected Lemmy account (arrows stay inert, as in #2217).
- Displayed score: the stored feed score; a vote changes it optimistically on the client
  (±1, ±2 when flipping). The server never rewrites `entry.score` from a vote — the next
  feed fetch brings the instance's count.

### Comments with a connected account

- **Ruling — authenticated thread:** when the reader has a connected Lemmy account, the
  thread is read from their home instance, authenticated, for the resolved local post id —
  it then carries `my_vote`, local ids, and whose comments are the reader's. Cached per
  account (audience `account:<id>`). Without an account, unchanged (anonymous, feed
  instance, shared cache). *If wrong:* a home instance that federated fewer comments shows a
  shorter thread to the logged-in reader.
- Comments owns an interface for this (e.g. `LemmyThreadReaderInterface` under
  `Service/Content/Comments`), implemented in `Service/Social` — no Comments → Social import.
- Comment JSON gains `vote: { address, myVote: 1 | 0 | -1, canReply: bool } | null`.

### Endpoints (all bearer-auth, JSON, problem+json)

- `POST /api/me/social-accounts/lemmy` `{instanceUrl, username, password, totpToken?}` → 201
  account; reconnect via the existing `PUT /api/me/social-accounts/{id}/credential` with the
  same fields.
- `PUT /api/entries/{id}/vote` `{score: 1 | 0 | -1}` → `{myVote, score}` (`score` = the
  home instance's post score after the vote, for display only).
- `GET /api/entries/{id}/vote` → `{myVote}` mirrored from the network (article open).
- `PUT /api/entries/{id}/comments/vote?address=<ap_id>` `{score}` → `{myVote, score}`.
- Replies reuse `GET …/social/composer` and `POST …/social/replies?address=` with a Lemmy
  `PostWriter` (address = post page URL or comment `ap_id`); quotes refused for Lemmy.
- Limits: votes spend `social_like`; replies `social_writes`; vote read and composer
  context `social_viewer`. Errors reuse the `social_*` problem types; a refused downvote
  (instance disables them) is `social_post_refused` 409 with the instance's message.

### UI

- `app-vote-pill` gains the interactive mode: when `myVote` is not null its two `.arrow`
  spans become buttons ("Upvote" / "Downvote", `aria-pressed`), the container becomes
  `role="group"` labelled "N points". States as shown in the #2217 visual round: upvoted =
  accent (`--accent`, `--accent-soft`), downvoted = danger tone (`--danger`, `--bg-danger`);
  pressing the active arrow clears the vote. Optimistic via `OptimisticToggles`, reverted
  with the problem toast on failure.
- **Ruling — reply affordance:** a small `reply` icon button right after the vote pill in
  the article head row and in each Lemmy comment's byline, styled like the comment byline's
  existing icon buttons; shown only with a connected Lemmy account. It opens the existing
  composer (`PostActions.reply`); the sent comment is inserted into the thread as for
  Mastodon/Bluesky. Lemmy body limit 10 000 characters, Markdown sent as typed.
  *If wrong:* a visual tweak.
- Settings: a Lemmy group in Social accounts — instance URL, username or email, password
  (the shared password input), optional two-factor code with a hint; no app-password text.
- Transloco keys in en / de / fr / es.

## Left to the builder

- Exact class names and folders within the existing Social module layout.
- How `feedNetworks()` is threaded through each enumeration site (each site is a judgment).
- The `discussion_vote` table's indexes and backup decision (like `social_like`'s).
