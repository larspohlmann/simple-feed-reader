# Lemmy link posts and a Lemmy pill (#2295)

## Intent

The share menu gets "Post to Lemmy", and the crosspost composer from #2287 gets a third pill, so one share can go to Bluesky, Mastodon and Lemmy together. A Lemmy share is a Lemmy **link post** in a community the reader picks: title, URL, and the shared text as body.

Lars asked to include Lemmy during #2287. That composer was built Lemmy-ready (see the #2287 spec, "Keep Lemmy in mind"): one lane per network, lanes from `LINK_POST_NETWORKS`, a per-lane controls block under the text field, and each lane builds its own draft.

## Decisions

1. **Community picker: a plain select of the reader's subscribed communities only** (Lars, mockup A). No search, no typing. The list is sorted A–Z and each option reads `!name@instance` (my call).
2. **The last-used community is remembered in the browser, per Lemmy account** (Lars, approach 2). It goes in `localStorage`, keyed by the social account id, and is written after a Lemmy link post succeeds. It is preselected the next time the composer opens with that account, if that community is still in the subscribed list. Each device remembers its own. It is a convenience only: losing it (private window, cleared storage) just means picking again, and every read and write tolerates storage being unavailable. The server stores nothing new. This is the only thing any composer remembers.
3. **The title is required, up to 200 characters, and prefilled with the page title** (cut at a word when longer). It has its own counter under its input.
4. **The body may be empty** for a Lemmy link post. A title and a link are a complete Lemmy post. The body limit is Lemmy's existing 10,000 (`LemmyComposerDefaults::MAX_CHARACTERS`).
5. **A selection quote goes into the body.** It is part of the shared text, so this needs no special handling.
6. **Send is off until a community is chosen** for the Lemmy lane. Decision 1 makes the last-used community the starting value, so this bites only on the first share, or when the remembered community is no longer subscribed.
7. **No subscribed communities:** the Lemmy lane shows the note "Subscribe to a community on Lemmy first", and Send stays off while the Lemmy pill is on, as with a lane over its limit in #2287. Turning the pill off lets the other networks post (my call).
8. **No duplicate protection for Lemmy** (my call). Lemmy's API has no idempotency key and no client-chosen post id. A retry after an unclear failure may therefore post twice. The #2287 lane model already resends only failed lanes, so a Lemmy lane that succeeded is never resent.
9. **Lemmy never quotes.** A shared URL that names a Bluesky or Mastodon post is still a plain link post on Lemmy. The #2287 kind notes ("{{ network }} shares this as a link") already say so in a crosspost.

## Layout

The #2287 layout, with a third pill. The Lemmy controls block sits under the text field, in lane order after Mastodon's, and shows only while the Lemmy pill is on:

- *Post as* select, only when the reader has more than one Lemmy account (as on other lanes)
- *Community* select (decision 1)
- *Title* input with its counter "34 / 200"

The shared textarea is the Lemmy body. Lemmy's counter sits on the Clear row like the others ("Lemmy 13/10000").

With Lemmy as the only lane (opened from "Post to Lemmy"), the heading reads "Post to Lemmy", and Send is labelled with the link copy.

## Starting text

`ComposerStartingText::sharing` starts Bluesky and Mastodon with the page title, or the quote when there is one. On Lemmy the title has its own field, so **a Lemmy lane's starting text is the quote alone, or empty** (my call). This holds only when Lemmy is the primary lane. In a crosspost, the text comes from the primary lane, so the body may also start with the title. The reader can edit it.

## Backend

- `LemmyPostWriter::share` creates the post with `POST /api/v3/post`: `name` (the title), `url`, `body` (omitted when blank) and `community_id`. The answer's `post_view.post.ap_id` is the published address.
  - **No `language_id`** (my call, from the builder check). The composer's language is only the browser's language, and many communities allow just some languages, so sending it would make posts fail with `language_not_allowed` and give the reader no way around it. Lemmy picks the post language from the account's own settings, as Lemmy comments already do here.
  - Validation, as typed exceptions mapped to 422 problems: a missing or blank title, a title over 200 characters, a missing community, and a body over 10,000 characters.
- The draft carries the Lemmy-only fields (title, community id), the way it already carries the Mastodon-only ones. The link-post request DTO accepts them.
- The Lemmy link composer context carries:
  - the subscribed communities (id, `!name@host` handle), from the existing paged `community/list?type_=Subscribed` fetch that `LemmyCandidateSource` uses. Share the fetch rather than copy it. The shared part must keep the raw community view, because `FollowedAccountModel` drops the community id.
  - Communities the reader can't post to are left out (my call, from the builder check): removed, deleted, and `posting_restricted_to_mods`. A subscription the reader can't use only produces an error. A ban isn't visible in the list, so a post to a community the reader is banned from fails with the existing refusal.
  - the title limit (200) and the page title as the starting title
- The context loads when the Lemmy lane loads, which in #2287 happens only when its pill turns on.
- `SharedPostQuoteFinder` must never return a quotable post for a Lemmy account (decision 9).

## Frontend

- `LINK_POST_NETWORKS` gains `'lemmy'`, which puts it in the share menu and the pills.
- The Lemmy lane holds `community` and `title` signals, seeded from its context, and adds them to its own draft fields. The community starts from the remembered one (decision 2) when it is in the list, else none.
- After the Lemmy lane's post succeeds, the frontend remembers its community for that account. A failed post remembers nothing.
- The lane is ready to send only with a community and a title that is non-blank and within 200 characters. This feeds the existing `pendingFit` / `canSend` rules.
- The per-lane controls block renders the Lemmy fields.
- i18n in en, de, fr and es: "Post to Lemmy", community, title, the no-communities note, and any new problem messages.

## Goals, non-goals

- Goal: share a link to Lemmy alone or alongside Bluesky and Mastodon, in one action.
- Non-goals:
  - searching communities you don't subscribe to
  - Lemmy cross-post detection ("already posted here")
  - NSFW flag, thumbnails or custom thumbnails
  - editing or deleting Lemmy posts
- Anti-goal: anything that makes the Bluesky and Mastodon paths behave differently from #2287.

## Approaches

- **Where to remember the last community** (Lars picked 2):
  1. On the server, per Lemmy account: one database field, shared across devices, ready for a native iOS client.
  2. In the browser: no server change, but per device. A native client would need its own store. This is fine because the value is only a convenience.
- **Picker** (Lars picked A):
  - A: a plain select of subscribed communities
  - B: a type-to-filter list
  - C: B plus instance-wide search

## Left to the builder

- The names of the DTO members, the storage key and the i18n keys.
- How the paged subscribed-community fetch is shared between the candidate source and the composer context.
