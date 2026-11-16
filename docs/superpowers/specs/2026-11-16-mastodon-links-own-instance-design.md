# Mastodon links open on the reader's own instance (#2261)

## Intent

A link into Mastodon opens on the remote server, where the reader is not signed in and cannot follow, reply or
boost. "Open original" already sends the reader to their own instance (#2048). Every other link into Mastodon
should do the same.

## Goals

On a Mastodon entry, these links open on the reader's instance:

- the poster's name (cards, rows, article header)
- a comment's author and its timestamp (the reply itself)
- a quoted post and its nested quote
- mentions (`/@name`) and hashtags (`/tags/name`) inside the post body
- "Open original", which moves from the server to the same client rule

## Non-goals

- Link cards and magazine "more sources" faces. They point at articles, not into Mastodon.
- Mastodon links on entries that are not Mastodon posts (an RSS article that links a toot).
- Keeping the mention/hashtag classes at ingest. The sanitizer strips `class` from `<a>`, and stored posts
  would get no benefit, so the body rule matches address shapes instead.

## Anti-goals

- Guessing Mastodon from a host name. The rule only touches links on entries whose `social.network` is
  `mastodon`.
- Breaking a link. With no Mastodon account, or when the target already lives on the reader's instance, the
  original URL stays.

## Approaches

1. **The server rewrites every link** (a sibling field next to each URL, plus body HTML rewritten when served).
   One home for the rule, and a native iOS client gets it free. More backend work across three payloads.
2. **The client rewrites links** *(chosen by Lars)*. The server only tells the client which instance is the
   reader's. One frontend rule rewrites structured links as they render and body links in an article
   decorator. Less backend work. A native client would have to reimplement the rule.

## Design

- **Instance to the client.** The sidebar payload's `socialAccounts` refs already reach the reader. Each ref gains
  its `serviceUrl` and whether it is its network's acting account (`isDefault` first, then the earliest
  connected, as `SocialAccountRepository::ACTING_ORDER` decides). The client reads the acting Mastodon
  account's origin from it.
- **One rule, one home.** A frontend `OwnInstanceLinks` service holds the rule:
  - post or profile address → `<instance>/authorize_interaction?uri=<encoded address>`
  - hashtag address (`<host>/tags/<name>`) → `<instance>/tags/<name>`
  - no Mastodon account, or the address's origin equals the instance → the address unchanged
- **Structured links** (poster, comment author and timestamp, quote, nested quote, Open original) go through the
  service where they render, only on Mastodon entries.
- **Body links.** A new article decorator rewrites `<a href>` in the body of a Mastodon entry when the path is
  `/@name`, `/@name/<id>`, `/users/<name>`, `/users/<name>/statuses/<id>` or `/tags/<name>`. Other links stay.
- **Server cleanup.** `OwnInstancePostUrl` and `social.openUrl` are deleted. "Open original" uses the client
  rule, so the rule has one home (Lars's choice of approach 2 implies it; my call).

## Left to the builder

- Where the decorator sits in `decorate-article.ts`'s order.
- The exact shape of the acting flag on the account ref.
- Whether the card list's poster link and the article header share one computed value.
