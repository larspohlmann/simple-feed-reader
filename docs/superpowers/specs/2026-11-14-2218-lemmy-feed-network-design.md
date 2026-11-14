# #2218 (part 2) — Lemmy as a feed network — design

Approved by Lars on 2026-11-14, during his real-account trial of PR #2226. His words: "i want to see a section
(like bluesky and mastodon) where all the feeds are combined. i want to be able to 'manage feeds' like with
bluesky or mastodon", "try to generalise the existing code for bluesky and mastodon. they are all 3 kind
'network'". He also chose **option 2**: Lemmy community feeds already subscribed as plain RSS are grouped under
Lemmy as well. This design undoes the "account only" ruling in
[2026-11-14-2218-lemmy-account-design.md](2026-11-14-2218-lemmy-account-design.md) and ships in the same PR
(#2226). Decisions Lars did not make himself are marked **Ruling**, each with what it costs if wrong.

## Intent

Lemmy is a network like Bluesky and Mastodon, not a special case:

- one sidebar section combines every Lemmy feed;
- the Lemmy account has "Manage feeds…";
- every place that lists networks lists Lemmy.

The code becomes general over the three networks. The capability split added in part 1 goes away.

## Goals

- Remove `SocialNetwork::carriesFeeds()`, `feedNetworks()` and `FEED_NETWORK_REQUIREMENT`'s hand-written
  `bluesky|mastodon`. Remove the frontend `ACCOUNT_NETWORKS` / `AccountNetworkName` / `FeedAccountRefDto`
  split. Every enumeration of networks uses `SocialNetwork::cases()` / `SOCIAL_NETWORKS`. Where one network
  can't do something, that is an explicit per-network capability, never a "feed network" filter.
- A community RSS URL `https://<host>/feeds/c/<name>.xml` classifies as Lemmy, the way `/@user.rss` URLs
  classify as Mastodon (`ProfileFeedUrl`). Existing subscriptions are reclassified by the existing preFlush
  listener on their next write. A one-off backfill is a migration (below), so they move at deploy.
- "Manage feeds…" on a Lemmy account has two tabs:
  - **Communities:** the account's subscribed communities (`GET /api/v3/community/list?type_=Subscribed`,
    paged). Each one is subscribed as its RSS feed on the community's own instance
    (`https://<community host>/feeds/c/<name>.xml`), exactly as Mastodon follows become profile RSS feeds.
    A community already subscribed shows as such.
  - **Timelines:** Subscribed (`SocialFeedKind::Home`), Local and All, read through the account
    (`GET /api/v3/post/list?type_=Subscribed|Local|All&sort=New&limit=50`, bearer auth).
- A `LemmyTimelineSource` (`TimelineSourceInterface`, tagged by `SocialNetwork::Lemmy`) fetches those
  timelines. It stores each post in the same shape as a Lemmy RSS entry, so votes, replies and the comment
  thread work unchanged:
  - guid = the post's `ap_id`;
  - the discussion thread is `Discussion::platformThread(ap_id)`;
  - the link is the post's external `url` when it has one, otherwise `ap_id`;
  - title, body (Markdown rendered as the RSS path renders it), the author's `name@host` identity, published
    time, thumbnail, and score and comment count from `counts`.
- Settings → Social accounts: the Lemmy account row gets "Manage feeds…" like the others. The sidebar's Lemmy
  section has the same row actions.
- Network exclusions (For you, All items, Trending) and the saved-search network filter list Lemmy.
- A Lemmy network logo in `app-network-logo`, plus Transloco keys in en / de / fr / es for every new string.

## Non-goals

- Link-sharing a post to Lemmy (it needs a community
  to post in; the share menu stays Bluesky + Mastodon by an explicit capability), Lemmy likes/reposts via
  `entry.social` (votes stay on the vote pill), subscribing to or leaving communities from the reader, user
  (`/feeds/u/`) feeds, PieFed/Mbin.
- Deduplicating a post that arrives both through a timeline and through a community RSS feed. Bluesky and
  Mastodon have the same overlap (home timeline versus profile feeds).

## Constraints

- Lemmy 0.19, API v3, as in part 1. The plan's first backend task re-checks `community/list` and `post/list`
  against the lemmy-js-client 0.19 types: request params, the response shapes `communities[].community
  {name, actor_id}` and `posts[]` (PostView), and paging.
- Every call goes through `GuardedApiClient` via `LemmyApiClient`. The JWT goes only to the account's own
  `serviceUrl`.
- The module rules from architecture.md §9 still hold. So do PHPMD, phptramp and Infection.
- The native iOS client stays viable: the existing endpoints are reused and no new endpoint shape is added.

## Approaches

1. **Generalise: Lemmy as a full peer** (chosen by Lars). Undo the split. Lemmy implements the per-network
   strategies the others implement (timeline source, candidate source, private-feed rules, profile-feed URL).
   Community feeds stay RSS.
2. *Group in the sidebar only, by URL, and keep Lemmy off `Feed.network`.* Rejected: a frontend patch that
   leaves the special case in place. It is the opposite of "they are all 3 kind 'network'".
3. *Communities as private API feeds (`community_name=`) instead of RSS.* Rejected: duplicates the RSS path
   already proven for votes and comments, and makes existing RSS subscriptions second-class.

## Design decisions

- **Lemmy RSS feeds become network feeds** (Lars approved the consequence). They now count as social, like
  Mastodon profile feeds:
  - they leave Trending;
  - network exclusions can hide them;
  - a saved search matches them only when Lemmy is ticked in its network filter;
  - retention and unread rules follow the social path.

  The sidebar group for them is the existing "profile" group, labelled for Lemmy as communities.
- **Ruling — timeline kinds:** two new `SocialFeedKind` cases, `Local` (`local`) and `All` (`all`), allowed
  for Lemmy only by `PrivateFeedRules`. Subscribed reuses `Home`. `PrivateFeedRules` gets an explicit Lemmy
  arm for every kind; `UserList` must no longer fall into the Mastodon branch. *If wrong:* a rename.
- **Ruling — new posts and cursors:** each round reads the first page (`sort=New`, `limit=50`).
  `newestSeenId` is the highest local post id seen. Ingestion's guid dedupe drops repeats. There is no
  `min_id` in Lemmy 0.19. *If wrong:* a busy All timeline loses posts between rounds; that is acceptable for a
  firehose.
- **Ruling — source format:** a new `SourceFormat::LEMMY_API` joins `SOCIAL` and `NOT_PROFILES`. Every
  `isSocial` / `NetworkWithoutFeedsException` site gets a deliberate Lemmy arm. `NetworkWithoutFeedsException`
  goes away: every network now has feeds and search.
- **Ruling — poster name:** a network RSS feed normally shows the subscription title as the poster
  (`EntryJson`). For Lemmy, the post's own author (already set by `LemmyEntryRule`) is shown, because a
  community feed has many authors. *If wrong:* a one-line switch.
- **Ruling — logo:** a simple monochrome Lemmy mark drawn for `app-network-logo`, in the existing logos' style.
  *If wrong:* Lars swaps the SVG.
- **Ruling — migration:** sets `feed.network = 'lemmy'` where the URL matches the community-feed pattern, on
  SQLite and MySQL, so existing subscriptions move at deploy. The data is unreleased, but Lars's dev and Strato
  databases already hold such feeds. *If wrong:* the preFlush listener would fix them on the next write
  anyway.

## Left to the builder

- Exact class names within `Service/Social/Social` and how each `match` site gets its Lemmy arm.
- The frontend picker's tab keys and labels for Lemmy.
- Whether a Lemmy community subscribed from the picker is matched to an existing RSS subscription by URL
  (preferred) or by identity.

## Builder questions, settled

- **Which rule makes an entry a Lemmy post:** `LemmyPostAddress::of($entry)`, on both paths. Votes,
  replies and the comment thread key off that rule alone. Timeline posts live in private feeds
  (`network = lemmy`, `SourceFormat::LEMMY_API`, one per account and kind). They are shaped so that
  `LemmyPostAddress::of` matches: the discussion thread is the `ap_id` post page, which
  `LemmyPostUrl::parse` accepts. `entry.social` stays null for every Lemmy entry.
- **Community RSS feeds keep `SourceFormat` RSS.** Only `Feed.network` changes, exactly like Mastodon
  profile RSS feeds. The sites that key off `network IS NULL` apply to them; `isSocial` sites do not.
- **Community RSS feeds belong to no account**, like Mastodon profile feeds. The picker's "already
  subscribed" check is by URL, so with two Lemmy accounts the same feed shows as subscribed under both.
- **Ruling — lifecycle:** generalised as for the other networks. Connecting creates the Home (Subscribed)
  feed, because the `carriesFeeds()` guard in `SocialAccountConnector` goes away. Local and All are created
  only when ticked. Disconnect removes the account's private feeds through the existing path.
- **The cursor:** `newestSeenId` is recorded but not sent; the existing refresh limits apply, with no
  extra cap.
- **Search:** a saved search's network filter matches stored entries only (unchanged).
- **Entry building:** `LemmyTimelineSource` builds each `ParsedEntryModel` itself: author, counts and
  discussion thread. It renders the Markdown body with the renderer the Lemmy comment path already uses;
  no second renderer. `LemmyEntryRule` (RSS only) is unchanged.
- **Commits:** ordered, each green on its own. The generalisation comes first and changes no behaviour.
  Lemmy behaviour follows in later commits.
- **Capabilities:** explicit per-network methods on `SocialNetwork` (e.g. `takesLinkPosts()`) are the
  intended shape.

## Search subscriptions (Lars, 2026-11-14: "i also want to be able to subscribe to a search, if that is possible")

- Lemmy joins social search subscriptions (#2086) like the others: the search tab in "Manage feeds…", the
  preview of results, and subscribing as a `SocialFeedKind::Search` private feed.
- API: `GET /api/v3/search?q=<query>&type_=Posts&sort=New&listing_type=All&limit=50`, bearer auth, on the
  account's home instance. The response's `posts[]` are PostViews, mapped by the same entry builder as the
  timelines. The plan's API-check task verifies the `Search` / `SearchResponse` types in lemmy-js-client 0.19.
- **Ruling — syntax:** Lemmy search is plain text. No `||` alternatives (that is Mastodon's #2104 split);
  only the existing length cap applies (`SocialSearchQuery::MAX_LENGTH`). The search form shows a
  Lemmy-specific syntax hint ("plain words; matches post titles and bodies"). *If wrong:* a hint text.
- **Ruling — scope:** `listing_type=All`, so a search covers everything the home instance knows, not only
  subscribed communities. *If wrong:* one parameter.
