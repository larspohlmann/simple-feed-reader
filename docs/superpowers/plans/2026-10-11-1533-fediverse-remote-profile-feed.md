# #1533 — a fediverse remote-profile link subscribes the account's feed

**Goal:** `https://systemli.social/@linuzifer@23.social` subscribes `https://23.social/@linuzifer.rss`.

**Cause (measured with `SimpleFeedReader/1.0`):** the viewing instance answers a non-browser UA with a
`302` to `/redirect/accounts/<id>`, an interstitial that links no feed. The account's home profile
(`https://23.social/@linuzifer`) already advertises its `.rss` and discovers fine.

**Design:** a `ShareLinkFeedInterface` resolver, `FediverseRemoteProfileFeed`, host-agnostic:
any host, path `/@<user>@<home host>` followed by end or `/` (profile tabs, a post) maps to
`https://<home host, lowercased>/@<user>.rss`. No fetch of the viewing instance. Mastodon and Misskey
serve that path; for software that doesn't, `FeedDiscovery::shareLinkFeed` already fails over to
discovering the entered page, so a wrong guess costs one request and never loses a subscription.
The outbound fetcher's SSRF guard covers the home host.

Not matched: a local profile (`/@user`, no second `@`), a home host without a dot, anything else.

## Tasks

1. **Resolver unit test** `tests/Service/Discovery/ShareLinkFeed/FediverseRemoteProfileFeedTest.php`:
   data providers for mapped links (profile, trailing slash, `/with_replies`, a post id, mixed-case
   home host, dots/hyphens in the user, http) and ignored links (local profile, no dot in the home
   host, `/users/x@y`, query-only `@`, not a URL). Run → fails (class missing).
2. **Resolver** `src/Service/Discovery/ShareLinkFeed/FediverseRemoteProfileFeed.php`, shaped like
   `GitHubRepositoryFeed`. Run → passes. The interface tag wires it.
3. **Wiring + discovery:** add the class to `ShareLinkFeedsAreConsultedTest`; add a
   `PlatformLinkDiscoveryTest` case proving the remote profile subscribes the home feed and fetches
   only it.
4. **Gates:** `composer check`, `composer md`, the suite, `composer infection:diff`, PhpStorm lint.
5. **Real run:** discover the issue URL against the live site in the Docker stack.
