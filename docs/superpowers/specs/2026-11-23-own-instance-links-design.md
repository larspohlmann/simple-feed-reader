# Links open on the reader's own instance (#2496)

## Intent

A reader with a Mastodon or Lemmy account wants every link to a Mastodon or Lemmy page to open on
*their* instance, where they are logged in, instead of a foreign instance where they are not.
Today only Mastodon is handled, and only on some links; Lemmy is not handled at all ("Open original"
on a Lemmy post lands on the post's origin instance).

## Goals

- One link rule that decides, for any URL, where it opens for this reader. Every outbound link in
  the SPA goes through it, so a link added later cannot skip it.
- Lemmy joins Mastodon: posts, communities and users open on the reader's Lemmy instance.
- Links inside ordinary (non-social) articles are rewritten too, but only where the URL shape
  is safe.

## Non-goals

- Bluesky. It has no per-user instance.
- Resolving a remote Lemmy post that did not come from the reader's account to its exact page
  through `resolve_object`. That needs an async lookup at click time, which iOS Safari's popup
  blocker defeats. Those posts use the search page.
- Moving the rule into the backend.

## Anti-goals

- Rewriting a link that is not a Mastodon or Lemmy page. A blog's `/post/123`, or a Medium
  `/@author/slug`, must stay untouched. A wrong rewrite is worse than a missed one.
- Rewriting when the reader has no account on that network.

## Constraints

- The rule lives in the frontend, in `frontend/src/app/reader/own-instance/`. It grows out of
  `OwnInstanceLinks` and `own-instance-url.ts`. A future Swift client would carry its own copy.
  That is accepted, and the rule stays a small pure function so the copy is easy to make.
- Article bodies come from a cache shared by all users, so per-user rewriting has to happen in the
  client.
- The instance is the first connected account of that network, as Mastodon does today
  (`SubscriptionsStore.socialAccounts`).

## Approaches

1. **One frontend link rule (picked).** A pure function maps a URL to its own-instance address
   using what the app knows. Every anchor and `openExternal` goes through it. Cost: the iOS client
   would need its own copy.
2. **The backend rewrites URLs in the API.** iOS would get it for free, but article bodies come
   from a per-URL cache shared by all users, so bodies would still need client-side rewriting.
   That splits the rule across two sides. Rejected.
3. **The backend resolves Lemmy posts with `resolve_object`.** This gives the exact page instead of
   a search page, but needs a click-time async lookup, which the iOS popup blocker defeats.
   Rejected.

Lars picked 1, including the blog-link rule. For posts from his own Lemmy account he chose a stored
home address over the search page (below).

## The rule

Input: a URL, plus what the app knows about it, when anything:

- the network it belongs to (an entry's `social.network`, or Lemmy when the entry has a
  `community`), and
- the post's address on the home instance, when it has one (see "Account posts" below).

Output: the address on the reader's instance, or the URL unchanged.

### Mastodon (instance = first Mastodon account's origin)

Existing behaviour stays: a foreign post or profile goes to
`home/authorize_interaction?uri=<encoded>`, and a hashtag goes to `home/tags/<tag>`.

- **Known Mastodon URL** (the caller says it is Mastodon): any foreign http(s) address goes
  through `authorize_interaction`.
- **Unknown URL** (blog links, link cards, story sources): only these safe shapes, on any host:
  - `/@name/<digits>` (a status)
  - `/users/<name>/statuses/<digits>` (a status)
  - a mention (`/@name` or `/users/name`, when the link text starts with `@`), as today
  - a hashtag (`/tags/<tag>`, when the link text starts with `#`), as today

  A bare `/@name` without `@` link text stays untouched (Medium and others use it).

### Lemmy (instance = first Lemmy account's origin)

- **Post** `https://host/post/<id>` → `home/search?q=<encoded url>&type=All`. Lemmy's search page
  resolves a remote object for a logged-in user. A post already on the home instance stays as is.
- **Comment** `https://host/comment/<id>` → the same search form.
- **Community** `https://host/c/<name>` → `home/c/<name>@host`.
- **User** `https://host/u/<name>` → `home/u/<name>@host`.
- **When the shape applies:**
  - when the caller says the URL is Lemmy (a Lemmy entry's discussion link, its community pill,
    comment author links, Lemmy notifications), or
  - when the URL's host is a **known Lemmy host**.

  On any other host these shapes stay untouched.

**Known Lemmy hosts** come from data the reader already has: the hosts of their Lemmy accounts,
the hosts of their subscribed feeds whose network is Lemmy, and the hosts of the community URLs on
their entries (`lemmy_post.community_url`). The backend serves them as one list, the frontend loads
it with the social accounts and holds it in a signal, and `known` means exact host match.

### Account posts (Lars: direct link, stored)

Posts from the reader's Lemmy account timeline or search (`LemmyPostEntries`) are fetched from the
home instance, so the answer carries their home id (`post.id`). The entry stores the post's
**home address** (`home/post/<id>`, a full URL), and the rule links straight to it, with no search page.

- **The origin address stays the thread.** `discussionUrl` and the canonical and cross-post keys
  keep using the origin `ap_id` (`LemmyPostKey::resolvePostPage`). The home address is a separate,
  nullable field, so one post never splits into one item per instance.
- **It lives on the entry, never on the canonical item.** A canonical item is shared by readers on
  other instances. An account feed belongs to one `SocialAccount`, so its entries belong to one reader.
- **Duplicates:** when the visible row of a collapsed group has no home address (say a community
  RSS copy), the API takes it from another of the reader's copies of the same canonical item.
- **Several Lemmy accounts:** the stored URL carries its instance. The rule uses it only when its
  origin equals the instance the rule targets (the first Lemmy account), and otherwise falls back
  to the search page.
- **Existing entries:** no backfill. When a later timeline refresh sees a post again and the
  ingest updates the entry, it gains the address; otherwise it falls back to the search page.
  The builder checks whether ingest updates existing rows, and says so in the PR. (My call.)
- **Migration:** one nullable column on `entry`. It goes through the migration leg on SQLite and
  MySQL. Mind the `entry_search` triggers if SQLite rebuilds the table.

## Where the rule applies

Every outbound link in the reader goes through one entry point:

- a directive on `<a target="_blank">` that rewrites `href` (taking the network hint as an input),
  replacing the `[href]="links.open(…)"` calls,
- the article-body decorator: `openMastodonLinksOnOwnInstance` becomes a general rewrite and runs
  for **every** entry, not only Mastodon ones (blog links), and
- `openExternal()` and `share-button`'s open.

This covers the links that skip the own instance today: "Open original", the Discussion link,
"all comments" and "open discussion", comment author and comment links, notification rows,
story-source faces, link cards, crosspost pills, quoted posts, and the entry-source profile and
community links.

Settings-page links (feed health, admin, the feed directory) are out of scope. They point at
feeds and tools, not at posts.

## Left to the builder

- Exact names and file layout inside `reader/own-instance/`.
- The endpoint shape for known Lemmy hosts, and the field name for the home address on the entry
  JSON, within the iOS checklist (architecture.md §6).
- Which embeddable the new column joins.
- Whether the directive replaces the existing `links.open` calls one by one or all at once.
