# Features

Everything the reader does, by area. The [README](../README.md) has the
screenshots; the [gallery](screenshots.md) has more.

## Reading

- Three layouts: a magazine of varied cards in one reading column, a compact
  list, and a two-pane view — with a draggable split — that shows the article
  next to the entry list.
- Reader view shows the extracted full article text, with images, audio, and
  video (including streaming video) played inline; you can switch to the feed's
  own version at any time, or open the original page. If the text is only the
  free preview of a paywalled article, the reader tells you.
- Read/unread tracking with "mark all read", favorites, and a separate
  "keep" list.
- Full-text search across your articles, including exact phrases in quotes.
  Save a search as a named view in the sidebar, each with its own unread
  count — open one, or open all as a combined list.
- Email digests: a daily or weekly summary of new articles from the saved
  searches you choose.
- Reading-time estimates, a progress bar, and an optional reading focus that
  dims everything but the passage in front of you.
- Videos in lists and comments start on their own as they scroll into view,
  muted and one at a time. A switch in the preferences turns that off, per
  device.
- Comments in the reader: a Reddit or Lemmy post shows its threaded discussion
  below it, a Bluesky or Mastodon post its replies, and the heading carries the
  count. Other feeds that publish a comments feed get a "Load comments" button.
- Article links lose their tracking parameters, so the same article arriving
  through two feeds or a shared post is recognised as one.

## Listening

- A persistent audio player with a playlist: episode artwork, previous and
  next, and a panel to reorder or remove episodes. The next episode loads ahead
  of time, so it starts the moment the current one ends.
- Play and Add to playlist sit on every audio entry in the magazine, list and
  two-pane views; the reader offers the same beside Listen.
- Episodes show the artwork their podcast declares, and fall back to the
  show's.
- Episodes and videos with chapters list them under the player; a tap jumps to
  one.
- An episode that publishes a transcript shows it in the reader. A tap on a
  line plays from there, and search finds what was said.
- Paste a SoundCloud profile link into "Add feed" and the reader offers that
  account's RSS feed.
- Paste a Mixcloud profile link (e.g. `https://www.mixcloud.com/johndigweed/`)
  into "Add feed" and the reader subscribes to its shows; they play and seek in
  the playlist. Subscriber-exclusive shows are left out.
- Paste a show or episode link copied from Apple Podcasts into "Add feed" and
  the reader subscribes to the show's RSS feed. Any of these work:
  - `https://podcasts.apple.com/us/podcast/99-invisible/id394775318`
    (Share Show → Copy Link on iPhone, iPad or Mac, or the show page's address)
  - `https://podcasts.apple.com/us/podcast/99-invisible/id394775318?i=1000792103416`
    (an episode link subscribes to its show)
  - `https://podcasts.apple.com/us/podcast/id394775318` and
    `https://itunes.apple.com/us/podcast/99-invisible/id394775318`

## Watching

- Paste a YouTube channel link (`https://www.youtube.com/@ScreenCrush`) into
  "Add feed" to subscribe to the channel. New videos play in the reader with
  their description; Shorts play in a portrait player and are marked in the
  list.
- A playlist link (`https://www.youtube.com/playlist?list=…`, or a video
  watched inside a playlist) subscribes to the playlist; a video link
  (`https://youtu.be/…`, `…/watch?v=…`, `…/shorts/…`) subscribes to the
  video's channel.

## Feeds

- Add a feed by its address — or paste the website's address and the app
  finds the feed for you. A show link from Apple Podcasts, a SoundCloud
  profile link, a YouTube channel, playlist or video link works too, and a
  GitHub repository link subscribes to the repository's releases.
- Connect a Bluesky, Mastodon or Lemmy account in Settings → Social Media. Its
  home timeline, lists, custom feeds and follows arrive as feeds, on Lemmy your
  communities and timelines, each network in its own sidebar section.
- Mastodon and Bluesky profiles show as posts: the post's own text stands in
  for the missing headline, and the reader shows it without loading a page.
- A Lemmy community or user feed (`https://lemmy.world/feeds/c/technology.xml`)
  works without an account: posts render from the feed with their link cards,
  score and threaded comments, sorted by interest.
- Like, reply, repost (boost on Mastodon) or quote a post from the reader, and
  delete your own posts again. On Lemmy, vote a post or a comment up or down
  and reply.
- Rows show a post's like and reply counts. A Lemmy post names its community,
  and when the same post went to several communities, they share one row with
  the others as pills.
- Share a page's link to Bluesky, Mastodon or a Lemmy community from one
  composer. A passage you select in an article goes out as a quote with a link
  that jumps to it.
- A Bluesky post shows what it embeds — images, video, a link card or the
  post it quotes — fetched from Bluesky's public AppView once the post
  arrives, and retried on later refreshes for three days.
- Save a Bluesky, Mastodon or Lemmy search as a feed: new posts that match the query
  appear under Searches in the sidebar. Preview what it finds before you
  subscribe, and edit the query later in the feed dialog.
  - The query takes exact phrases in quotes, `||` for either word, `-word` to
    leave out posts with a word, `-from:handle` to leave out an account, and
    parentheses to group terms.
  - Mastodon has no `||`, so each alternative runs as its own search, at most 8
    per query. Mastodon search needs full-text search on your instance.
- Notifications from connected accounts: each network's sidebar section
  carries a bell with the unseen count. The list groups likes, reposts,
  replies, mentions, quotes, ended polls and follows; a row opens its post in
  the reader when the reader holds it, and on the network otherwise.
- A feed directory in Settings: about 50,000 news sites and podcasts from the
  InfoBubble sources catalog, narrowed to the countries and languages you
  choose. Filter by category, article length and language, search sites and
  summaries, and subscribe straight from a row.
- Preview every feed before you subscribe: Add shows the feeds it finds at an
  address with their recent items, whether entries carry images, and whether
  the feed delivers full text or only summaries.
- Import and export your subscriptions as OPML.
- Feed health is visible in the settings — active, erroring, or gone, with a
  plain-language explanation.
- An Organise page gathers every feed and tag in one place: reorder them by
  drag, select several at once to tag them or change their visibility in bulk,
  and retry or unsubscribe feeds that are erroring or gone.
- Choose what shows where: every feed, tag and network has checkboxes for All
  items, For you, saved searches and Trending, and Settings → Visibility shows
  them all in one table.
- For sites without any feed, an opt-in experimental mode scrapes the
  article list into a pseudo-feed.
- When a WordPress site's feed carries only summaries, the app detects its
  REST API while finding the feed and offers it as a richer alternative —
  full article text, chosen in the same subscribe dialog.

## Tags

- Group feeds with tags; each tag has a name, a color, and an icon.
- Assign tags in the feed dialog, or drag a feed onto a tag.
- Unread counts per feed and per tag.
- A tag can hold child tags, two levels deep. Selecting a parent lists, counts
  and marks read everything below it; OPML export and import keep the nesting.

## Trending

- The stories several of your feeds are covering, from the last 24 hours or
  the last 7 days, ranked by how many sources ran them.
- Stories are grouped without AI, in English, German, French and Spanish. An
  article counts once however many feeds and posts carry it, and the links
  that social posts share count too.
- Each story is one card with its sources and shares, when it started and when
  its last article joined. Open it to read everything that covered it as a
  list of its own.
- With an LLM connection, each story gets its own headline and a short
  synopsis; without one, its most representative headline stands.
- Follow a story to keep it in the sidebar, hide one you don't care about, or
  turn Trending off in Settings → Preferences.

## "For you" — AI recommendations (optional)

- A ranked selection of your unread articles, based on what you favorite,
  share, keep, and read, and on the posts you like or upvote on your social
  networks.
- You choose the provider: any OpenAI-compatible API works, including local
  models via LM Studio or Ollama — your data can stay on your own machine.
- Or let TypeSafe's Jev (System One, directly or through OpenRouter) score
  every unread article: one yes/no judgement per article, ranked by
  probability, built on your reading profile.
- The reading profile is built by an LLM connection of your choice in
  Settings → AI, by hand or on its own schedule.
- A free-text guidance prompt steers the picks (topics to prefer or avoid);
  runs start manually or on a schedule.
- Runs execute on the server: close the tab and come back later, stop a run,
  or resume a failed one.
- Social posts are judged by the article their link card points to.
- A cost overview shows token usage and cost per run, spend per month, and
  the total spent — so there are no surprises with paid providers. Every AI
  call is in it: recommendations, the reading profile and story titles.

## Accounts

- Sign in with email and password, with Google or Apple, or with a passkey.
- Email verification and admin approval for new accounts are optional — you
  decide per instance.
- Registration is protected by an invisible proof-of-work challenge; there is
  no CAPTCHA to solve.
- Users can export the whole account to a backup file and restore it later, or
  delete the account entirely, including all its data.

## Administration

- Most instances are self-hosted, so you are the admin: approve, suspend, or
  delete users from the settings.
- Optional trial periods and per-user feed limits.
- Configure outgoing mail (SMTP) from the admin settings, with a built-in
  connection test.
- Route the server's outbound fetches through a SOCKS5 or HTTP proxy, set and
  tested from the admin settings.
- A feed catalog suggests feeds to new users; edit it or import the bundled
  one.
- Update from the app: with the optional updater container, the admin sees
  when a new release is out and starts the update from the settings.

## Interface

- Special effort went into mobile: a collapsible sidebar with swipe gestures,
  pull-to-refresh, and touch-friendly controls. The floating buttons sit
  together in one bottom corner, on the side you choose.
- Light and dark themes, following your system setting if you want.
- The interface is available in English, German, French and Spanish.
