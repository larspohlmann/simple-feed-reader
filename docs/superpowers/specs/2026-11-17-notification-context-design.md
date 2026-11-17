# Notification context — design (#2296)

Follow-up to #2240. Layout **A** from the 2026-11-17 mockup round.

## Why

A notification row shows only the reply text today. The text alone doesn't say what the reply answered or which post it belongs to. Lemmy also labels every reply "replied to your post", including replies to your comments. The aim is to understand a notification at a glance without opening the network. That keeps the #2240 rule: rows only link out, with no in-app thread view.

## Goals

- Lemmy shows **"replied to your comment"** for a reply to one of your comments. It tells the two apart by the comment's `path`: two segments (`0.<id>`) means a reply to the post, more means a reply to a comment.
- Under the reply text, one line quotes **what it answered** as "You: …":
  - Lemmy: your comment.
  - Mastodon: your post.
  - Bluesky: your post.
- A last muted line with **where it happened**:
  - Lemmy: the post title and `c/<community>`. This shows on replies and on mentions.
  - Bluesky: "in a thread by <author>: <first words of the thread's first post>". It shows only when that first post isn't yours and isn't the post being answered.
  - Mastodon: no line, because posts have no title and the thread start would cost a call per reply.
- Likes, reposts and quotes show the text of your post in the same quote style. On Mastodon and Bluesky that text is already stored for likes and reposts, so they only change how they look.

## Non-goals and anti-goals

- No in-app thread view, no expanding a row, no tapping into the quote.
- No backfill: rows stored before this change show only the reply text, and they age out after 30 days.
- **Anti-goal:** context lookups that repeat on every poll. A poll re-fetches up to 50 notifications per network, so a lookup made at fetch time would run every 5 minutes for rows we already hold.
- **Anti-goal:** opening the list waits on a network call.

## Approaches

1. **Load the context during the poll, for new rows only (chosen).** The poll fetches as now. It then asks the store which remote ids are new, the source looks up the context for those rows alone, and the store saves rows with context. Opening the list stays a pure database read.
2. *Look up the context with every fetch.* Simplest code, but Lemmy would make up to 50 extra calls per poll, nearly all for rows already stored.
3. *Look up the context when the list opens, and cache it.* Polling stays the same, but opening the list waits on three networks. That breaks the "glance" rule.

Lars picked 1.

## Decided

- **Storage:** nullable columns on the `NotificationSubject` embeddable:
  - `answeredExcerpt`: the "You: …" text.
  - `threadTitle`: the Lemmy post title, or the Bluesky root post's opening words.
  - `threadPlace`: `c/<community>`, Lemmy only.
  - `threadBy`: the Bluesky root author's display name, falling back to the handle.

  A migration covers MySQL and SQLite.
- **New kind** `comment_reply` (`SocialNotificationKind::CommentReply`), Lemmy only. It is marked read through the same endpoint as `reply` and never groups.
- **Fetching, per network:**
  - **Lemmy:** the title and community come free in the reply and mention payloads. The parent comment comes from `GET /api/v3/comment?id=<parent>`, once per new `comment_reply`.
  - **Mastodon:** the replied-to posts come from one batched `GET /api/v1/statuses?id[]=…` per poll that has new replies (Mastodon 4.3 and later). For a quote, the quoted post is already embedded in the notification.
  - **Bluesky:** a reply record names `reply.parent` and `reply.root`, and a quote embeds the quoted post's URI. All of them go through the batched `getPosts` call already used for likes, 25 per call.
- **Failure:** a context lookup that fails stores the row without that context; the poll still counts as a success. A deleted parent post or comment is simply left out.
- **API:** each group in `GET /api/me/notifications` gains `answeredExcerpt`, `threadTitle`, `threadPlace` and `threadBy`, each nullable.
- **Frontend row (layout A):**
  1. the action line;
  2. the reply text (two lines, as now);
  3. the quote line: a muted 3px left rule, a bold "You:" and the text clamped to one line with an ellipsis;
  4. the place line: muted, extra small, one line with an ellipsis. The icon is `article` for a Lemmy post and `forum` for a Bluesky thread.

  For likes, reposts and quotes, the stored excerpt (your post) takes the quote style.
- **Strings:** "replied to your comment", "You:" and "in a thread by {{name}}:" in en, de, fr and es.

## Left to the builder

- How the poll hands the new rows to the source: an extra method on `NotificationSourceInterface` or a separate per-network context collaborator.
- Column lengths, within the existing 500-character excerpt limit.
- Test fixtures for each network, including Lemmy `path` and Bluesky `reply.parent` and `reply.root`.
