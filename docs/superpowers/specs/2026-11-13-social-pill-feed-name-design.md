# Social post pill names its feed (#2194)

## Why

A Bluesky or Mastodon post's pill only says the network ("bsky", "mastodon"). It should say which feed the
post came from and take the reader there.

## Design

- The pill shows the network logo plus the feed's display title (from `SubscriptionRows`, so an unrenamed
  home feed reads its localised "Home" label, an unrenamed search its query).
- It links to that feed (`?subscription=<id>`), with the full title on hover; its click still does not open
  the post.
- The pill looks and behaves the same everywhere it appears: list cards, the article page, and magazine
  source groups.

## Non-goals

- No new way into the network's combined view; it stays reachable from the sidebar.
- No backend change.

## Approaches

1. **Read the feed off `SubscriptionRows` in `EntryPillsComponent`** (picked): the component already looks
   the feed up there for its network; the title and id come from the same row.
2. A backend field carrying the feed title on each entry: more payload and work for data the client already
   holds.
3. A separate feed-pill component: a second pill implementation for one pill.

## Left to the builder

Specs cover the feed name, the feed link, and an unrenamed home feed's localised title; a live check on :4200.
