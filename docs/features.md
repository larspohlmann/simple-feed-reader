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

## Listening

- A persistent audio player with a playlist: episode artwork, previous and
  next, and a panel to reorder or remove episodes. The next episode loads ahead
  of time, so it starts the moment the current one ends.
- Play and Add to playlist sit on every audio entry in the magazine, list and
  two-pane views; the reader offers the same beside Listen.
- Episodes show the artwork their podcast declares, and fall back to the
  show's.
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
- Mastodon and Bluesky profiles show as posts: the post's own text stands in
  for the missing headline, and the reader shows it without loading a page.
- A Bluesky post shows what it embeds — images, video, a link card or the
  post it quotes — fetched from Bluesky's public AppView once the post
  arrives, and retried on later refreshes for three days.
- Save a Bluesky or Mastodon search as a feed: new posts that match the query
  appear under Searches in the sidebar. Preview what it finds before you
  subscribe, and edit the query later in the feed dialog.
  - The query takes exact phrases in quotes, `||` for either word, `-word` to
    leave out posts with a word, `-from:handle` to leave out an account, and
    parentheses to group terms.
  - Mastodon has no `||`, so each alternative runs as its own search, at most 8
    per query. Mastodon search needs full-text search on your instance.
- Preview every feed before you subscribe: Add shows the feeds it finds at an
  address with their recent items, whether entries carry images, and whether
  the feed delivers full text or only summaries.
- Import and export your subscriptions as OPML.
- Feed health is visible in the settings — active, erroring, or gone, with a
  plain-language explanation.
- An Organise page gathers every feed and tag in one place: reorder them by
  drag, select several at once to tag them or change their visibility in bulk,
  and retry or unsubscribe feeds that are erroring or gone.
- For sites without any feed, an opt-in experimental mode scrapes the
  article list into a pseudo-feed.
- When a WordPress site's feed carries only summaries, the app detects its
  REST API while finding the feed and offers it as a richer alternative —
  full article text, chosen in the same subscribe dialog.

## Tags

- Group feeds with tags; each tag has a name, a color, and an icon.
- Assign tags in the feed dialog, or drag a feed onto a tag.
- Unread counts per feed and per tag.

## Trending

- The stories several of your feeds are covering, from the last 24 hours or
  the last 7 days, ranked by how many sources ran them. No AI is involved.
- Each story is one card; expand it to see every outlet that covered it.
- Hide a story you don't care about, or turn Trending off in Settings →
  Preferences.

## "For you" — AI recommendations (optional)

- A ranked selection of your unread articles, based on what you favorite,
  keep, and read.
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
- A cost overview shows token usage and cost per run, spend per month, and
  the total spent — so there are no surprises with paid providers.

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

## Interface

- Special effort went into mobile: a collapsible sidebar with swipe gestures,
  pull-to-refresh, and touch-friendly controls.
- Light and dark themes, following your system setting if you want.
- The interface is available in English, German, French and Spanish.
