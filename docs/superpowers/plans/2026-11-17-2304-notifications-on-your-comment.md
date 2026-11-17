# Notifications about your comment (#2304) Implementation Plan

> Executed by one implementer subagent and one reviewer. Follow-up to #2296 (spec `docs/superpowers/specs/2026-11-17-notification-context-design.md`).

**Goal:** A like, repost, reply or quote reads "… your comment" instead of "… your post" when the post it is about is your comment, that is, a reply in a thread, on Bluesky, Mastodon and Lemmy.

**Architecture:** There is one general flag, `onComment`, on the notification's `NotificationContext`, and no kind per verb. Lemmy's `comment_reply` kind (#2296) becomes a `reply` with `onComment = true`, and a migration converts the stored rows. The frontend picks the "comment" wording from the flag.

## Decided

- **Storage:** `NotificationContext` gains `bool $onComment = false`, a non-null boolean column defaulting to false (Doctrine derives `subject_context_on_comment`; confirm with `--dump-sql`). It gets a `#[\NoDiscard] withOnComment(): self` wither, written with clone.
- **Migration** `Version20261117210000`, in the shape of `Version20261117140000`, runs on MySQL and SQLite.
  - `up`: add the column with `NOT NULL DEFAULT 0` (skip it if present), then `UPDATE social_notification SET kind = 'reply', subject_context_on_comment = 1 WHERE kind = 'comment_reply'`.
  - `down`: put `kind = 'comment_reply'` back where it is `reply` with the flag set, then drop the column.
  - Description: `'Mark notifications about your comment; comment_reply becomes reply (#2304).'`
- **Remove** `SocialNotificationKind::CommentReply`, Lemmy's `REPLY_KINDS` (`markRead` goes back to `Reply` → reply endpoint, otherwise mention), and on the frontend the `comment_reply` kind, its glyph entry, its SCSS selector and its `action.comment_reply` strings.
- **When `onComment` is true:**
  - **Lemmy (fetch):** a `/user/replies` item whose path has a parent (`LemmyCommentPath::parentOf` not null). The kind stays `Reply`.
  - **Bluesky (fetch):**
    - Reply: `reply.parent` is authored by the account (`AtPostUri::authorityOf(parent) === $account->getIdentity()`) and `reply.root !== reply.parent`.
    - Like and repost: the liked post (already fetched through `getPosts` for its text) has a `record.reply`. Extend the existing lookup so it yields text and "is a reply" per URI.
    - Quote: decided in the context source, where the quoted post is fetched: its `record.reply` is present.
  - **Mastodon:**
    - Like and repost (fetch): `status.in_reply_to_id !== null`.
    - Quote (fetch): `status.quote.quoted_status.in_reply_to_id !== null`.
    - Reply: decided in the context source, where the answered status is fetched: its `in_reply_to_id !== null`.
  - Mentions, follows and polls are never `onComment`.
- **Grouping:** likes and reposts group by subject, so the flag is the same for every row of a group. The list mapper reads it from the newest row, like the other context fields.
- **API:** each group gains `onComment: bool` (`SocialNotificationsJson`).
- **Frontend:** `NotificationGroupDto.onComment: boolean`.
  - `notificationLine` uses `reader.notifications.actionOnComment.{kind}.{one|other}` when `onComment` is true and the kind is like, repost, reply or quote. Otherwise it keeps `action.{kind}.{number}`.
  - The group factories in the specs and the e2e spec default the flag to `false`.

| Key under `reader.notifications.actionOnComment` | en | de | fr | es |
|---|---|---|---|---|
| `like.one` / `like.other` | `{{who}} liked your comment` | `{{who}} hat deinen Kommentar geliked` / `{{who}} haben deinen Kommentar geliked` | `{{who}} a aimé votre commentaire` / `{{who}} ont aimé votre commentaire` | `A {{who}} le gustó tu comentario` / `A {{who}} les gustó tu comentario` |
| `repost.one` / `.other` | `{{who}} reposted your comment` | `{{who}} hat deinen Kommentar repostet` / `{{who}} haben deinen Kommentar repostet` | `{{who}} a partagé votre commentaire` / `{{who}} ont partagé votre commentaire` | `{{who}} compartió tu comentario` / `{{who}} compartieron tu comentario` |
| `reply.one` / `.other` | `{{who}} replied to your comment` | (the existing `comment_reply` strings) | (existing) | (existing) |
| `quote.one` / `.other` | `{{who}} quoted your comment` | `{{who}} hat deinen Kommentar zitiert` / `{{who}} haben deinen Kommentar zitiert` | `{{who}} a cité votre commentaire` / `{{who}} ont cité votre commentaire` | `{{who}} citó tu comentario` / `{{who}} citaron tu comentario` |

Before writing de, fr and es, check how the existing `action.like` / `action.repost` / `action.quote` strings are phrased in each file and mirror them exactly with "comment" in place of "post". The table is a fallback only.

## Tests (write each before its code)

- **Model and persistence:**
  - `NotificationContext` round-trips `onComment` (repository flush and reload).
  - The controller test sees `onComment` true and false.
- **Migration:** migrate from empty plus `schema:validate` on SQLite and on a throwaway MySQL database (`127.0.0.1:33306`, root/root, then drop it). A seeded `comment_reply` row becomes `reply` with the flag set (check it by hand on SQLite and note the result in the report).
- **Lemmy:** reply 7 (path with a parent) is `Reply` with `onComment` true and its remote id still `reply:7`. Reply 6 is false. `markRead` of a reply uses the reply endpoint.
- **Bluesky:**
  - A reply to your reply in someone's thread → true.
  - A reply to your thread-starting post → false.
  - A reply whose parent is not yours → false.
  - A like of your reply → true, and a like of a top-level post → false.
  - A quote of your reply → true (context source).
- **Mastodon:**
  - A favourite of a status with `in_reply_to_id` → true.
  - A quote whose quoted status is a reply → true.
  - A reply whose answered status (from the batch) has `in_reply_to_id` → true; without it → false.
- **Frontend:**
  - `notificationLine` gives "Carol liked your comment" for `like` + onComment, and "Carol liked your post" without it.
  - Same for reply, repost and quote.
  - The i18n dictionary spec passes in all four languages.

## Gates

- From `backend/`:
  - `composer check`, PHPMD clean on touched src (`composer md`);
  - `php bin/phpunit` (SQLite);
  - the MySQL leg natively: `DATABASE_URL="mysql://root:root@127.0.0.1:33306/feedreader2304?serverVersion=8.4&charset=utf8mb4" composer test:parallel`. A first run can fail with "Unknown database"; rerun once. Then drop the `feedreader2304*` databases. Never run it at the same time as the SQLite leg;
  - `php -d memory_limit=1G vendor/bin/infection --git-diff-filter=AM --git-diff-base=origin/develop --threads=max --ignore-msi-with-no-mutations` (minMsi 80).
- From `frontend/`: `npm ci`, `npm run check:static`, and `./node_modules/.bin/jest --testPathIgnorePatterns "/node_modules/" "/e2e/"`, natively.
- Commits: `type(#2304): …`, no attribution.
