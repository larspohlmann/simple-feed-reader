# Trending: social posts' link cards (#2229)

## Intent

A social post ties into a Trending story today only through its link card's **URL**, which must hash the same as an
article in the window. The card's title and description go unused, though the title is usually the linked article's
own headline. And social posts never show in Trending: they count towards a story's score, but a story needs two
*article* feeds the reader follows, and its card lists only entries from article feeds.

A link card is the article a post shares. This change reads the card's text into the grouping, in the card's own
language, lets a shared article count as an article source, and shows the posts that share it as the story's sources,
led by the shared article when the reader follows no outlet that carries it.

## Goals

- A story card's title joins the post's headline stems and its description the body stems, with today's weights.
- The card text is stemmed in its own language when a long text plainly reads as another than the post's.
- Cards that name no story are left out by a structural rule, measured on the window, never by a list.
- A story is shown when two article feeds **or two distinct article sites** the reader follows carry it.
- Posts that share a story's article are shown as its shares ("Shared by …"), one row per article; when the reader
  follows no outlet carrying it, the shared card leads (D6, as approved).
- French and Spanish are measured on their own as well as in the shared window.

## Non-goals

- Storing a card's language (D3), resolving shorteners (`trib.al`, `n.pr`, `bit.ly`), or counting two article feeds of
  one site as one source (D4).
- A new Trending card design beyond D6's shares line; any API change that is not additive.
- Any change to the weights, filler shares, link thresholds, the stemmer, the German splitter or the equivalence tables.
- Posts without a story card: they keep counting towards the score only, and are never shown.

## Anti-goals

- **Curation from the window.** No word list, site list, profile pattern or tuned value comes from the current news.
  The window shows the shapes; the rules are structural and general.
- **Wrong merges** from generic card titles (*Instagram*, *- YouTube*, *Startseite*). The rule in D2 exists for them,
  and the eye check looks for them first.
- **A week regression:** as in #2227, #2230 and #2231, the week must not lose real stories net.
- **A browser-only API.** The endpoint keeps its JSON shape; a native iOS client sees a social lead as the same
  `EntryDto` it already renders in every other list.

## Constraints

- PHP 8.5, Symfony 8.1, shared host (Strato): the week rebuild runs in the worker, about 25 s there today.
- House rules (CLAUDE.md): `final readonly`, one role per folder, at most three parameters, every touched `src` file
  PHPMD-clean with no count-driven split, queries in `src/Repository`, no comment unless a reader would get the code
  wrong.
- Lands on `develop` after #2230 and #2231 (merged, f5e4b51af).

## Research

All measurements: dev MySQL, read-only (`START TRANSACTION READ ONLY`), on 2026-11-15. The grouping runs use the #2227
kit (`simp-explain-at.php`, `compare.py`) with "now" pinned to **2026-11-15 09:00:00 UTC**; a prototype's files were
swapped into the checkout for its runs and restored from saved copies. The scripts, the prototype and every output
are in the session scratchpad under `2229/`. Language is `COALESCE(entry.language, feed.language)` throughout.

### R1. What the cards hold

Social posts of the week window (since 2026-11-08 09:00), their first top-level card (`thread_post_id IS NULL`, lowest
position):

| Post language | Posts | With a card | Card share | With a title | With a description |
|---|---|---|---|---|---|
| de | 3,133 | 1,664 | 53 % | 100 % | 91 % |
| en | 7,336 | 3,644 | 50 % | 100 % | 88 % |
| fr | 107 | 44 | 41 % | 100 % | 80 % |
| es | 125 | 49 | 39 % | 100 % | 92 % |
| none | 380 | 55 | 14 % | 100 % | 89 % |
| **all** | 11,081 | 5,456 | 49 % | 100 % | 89 % |

Every card has a title (the column is `NOT NULL` and no stored title is blank). 5,424 of the carded posts are link
shares, 37 quote posts. 92 % of cards carry an image. 239 English article-feed entries carry a card too (link
aggregators); no German, French or Spanish article entry does.

**Shapes**, by structure (5,460 week cards):

| Shape | Cards | What it is | Examples (by eye) |
|---|---|---|---|
| URL with no path (query or not) | 61 | a site, not an article | *taz.de*, *Startseite* (netzpolitik.org), *Home \| Cartoon Movement*, *Discuss Online – a general purpose instance* |
| Title names an account (`@` and a letter, digit or `_`) | 50 | a post or profile, not an article | *Anonymous Germany (@Anonymous00708) on X*, *Paul Cantrell (@inthehands@hachyderm.io)*, *Sascha Kersken (@sascha-kersken.de)* |
| Title, letters and digits only, is the site name or the host's first label | 41 | the site's name | *Reddit* (9), *- YouTube* (3), *Instagram*, *Facebook*, *Google Maps*, *pubmed.ncbi.nlm.nih.gov* |
| Card title equals the post's own title | 503 | the post *is* the share (bridges, outlet accounts) | adds no new stem; stems are a set |
| Path of one segment | 1,567 | mostly shorteners and short article ids | `trib.al/…`, `n.pr/…`, `taz.de/!6228451` — real articles |

The three rules together leave out **124 cards (2.3 %)**; 14 cards match two. A one-segment path is not a profile
signal: 1,567 cards have one, nearly all articles behind shorteners.

### R2. The card's language

Guessing every card's language at read time with `LanguageGuess` as configured (every profile the detector ships, so
Italian does not rank as Spanish) costs **1.82 ms per card**: 9.9 s for the week's 5,460 cards, 14 % of a 70 s run.
The issue's 0.16 ms is the detector loaded with only the four app languages: **0.185 ms per card**, 1.0 s for the week.
Where the full detector names one of the four, the four-language detector agrees on all 5,175 cards.

Guessing every card (four languages, no length rule) against the post's language:

| Post → card | Cards | By eye |
|---|---|---|
| de → en | 60 | about 7 in 10 right (*Putin may not want peace…*); the rest generic (*Instagram*, *Why No AI?*) |
| en → de | 46 | mostly wrong: short or generic titles (*SocialSpecing & HyperTexting*, *Reddit*, *- YouTube*) |
| en → es / fr | 40 / 29 | mostly wrong: short English `politi.co` titles read as Spanish or French |
| fr → en, es → en | 15, 12 | mostly right (*Watch: Inside Ukraine power plant…*, *National Weather Service reports…*) |
| de → es / fr | 6 / 2 | wrong (*Kora OS: Ex-Cyanogen-CEO kündigt…*) |

With #2227's rule for social posts — the stated language stands unless the text is at least 150 characters, and then
the guess wins — 95 cards change language: de → en 20, en → de 24, fr → en 12, es → en 6, en → fr 8, en → es 12,
fr → de 3, es → de 2, none → a language 36. The French and Spanish posts linking English articles are mostly right;
the remaining wrong guesses are about 20 long English `politi.co` cards read as French or Spanish.

Storing the language at write time instead would need a `link_card.language` column, both `LinkCardRepository` write
paths, the backup format (`EntryLine`) and a backfill command, because the week window holds cards written before the
column. Read time costs 1.0 s on a 70 s week run (1.4 %).

### R3. Card text in the grouping (prototype)

Prototype: the card title's stems joined the headline stems and the description's (cut to 300 characters, as a body)
the body stems; the R1 rules applied; the card stemmed in its own language under the R2 length rule. Same entries for
A (cards off) and B, back to back:

| | Day A | Day B | Week A | Week B |
|---|---|---|---|---|
| Entries | 4,675 | 4,675 | 29,361 | 29,361 |
| Groups | 549 | 553 | 3,761 | 3,752 |
| Grouped pairs | — | +195 net | — | +2,375 net |
| Explain time | 4.3 s | 4.8 s | 68.7 s | 82.5 s |
| Peak PHP | 54.9 MB | 58.0 MB | 177.0 MB | 189.5 MB |
| Peak RSS | 94.5 MB | 94.7 MB | 246.1 MB | 250.6 MB |

| new / lost / **net** | Day | Week |
|---|---|---|
| de–de | 68 / 33 / **+35** | 1,003 / 443 / **+560** |
| en–en | 210 / 58 / **+152** | 3,609 / 1,948 / **+1,661** |
| fr–fr | 0 / 0 / **0** | 4 / 0 / **+4** |
| es–es | 0 / 0 / **0** | 2 / 0 / **+2** |
| mixed or none | 12 / 4 / **+8** | 181 / 33 / **+148** |

French-only and Spanish-only windows (the window query restricted to one language, as #2231 did), A → B:

| | Entries | Grouped pairs |
|---|---|---|
| French day | 405 | 153 → 153 (0) |
| French week | 511 | 178 → 182 (+4 new, 0 lost) |
| Spanish day | 408 | 20 → 20 (0) |
| Spanish week | 859 | 50 → 52 (+2 new, 0 lost) |

French and Spanish move little because only 44 and 49 of their posts carry a card in the week.

Stemming the card in the post's language instead (same prototype, guess off) gave 194 fewer week pairs, 93 of them
mixed-language pairs, which is the cross-language link the issue asks for.

**Eye check (week).** The largest grown groups reunite fragments of one event: the Microsoft outage (7 + 5 + 4 + …
→ 29), the Van Hollen challenge (30 → 50), the FAA ground stop (37 → 47), Europe at the Abu Dhabi talks (3 + 3 + 3 →
12), the Alaska count, the Nvidia/Huang resignation, the Colom and Holscher wins, and heise's *Chatbots in Brüssel*
story with the heise digest posts that share it. Lost pairs are the same events cut differently by the greedy grouper
(the 46-entry ground-stop group becomes 37 + 5 as *ground stop lifted* joins its own group), not stories lost.

**Wrong merges found.** Of the cards with a generic short title shared by two or more cards, nine posts gained pairs.
One was wrong: two unrelated posts both carrying a **"- YouTube"** card (YouTube's consent page title and boilerplate
description) linked. The site-name rule in R1 (title is the host's first label) is what removes it; it was added after
this run. *Israel's Secret Broker* joined *Jared Kushner's Secret Deals* (one channel, one subject): acceptable.
*COP to it* and *Long nights ahead* (newsletter names) joined the Burnham-at-COP31 group their posts are about.

The 20 % slower week run comes from the extra stems, not the language guess (the post-language variant ran as slow).

### R4. The two exclusions

Measured on the worker's current snapshot for the dev account (user 2: 262 feeds, 215 article feeds), counting only
feeds it follows (inclusion switches and the duplicate-copy rule are left out of this approximation). An article site
is the host of an article-feed entry's URL, or of a social post's card when the card names a story (R1); `www.` is
dropped.

**Threshold** (`minimumArticleFeeds`), stories reaching two followed feeds:

| | Day | Week |
|---|---|---|
| Stories in the snapshot | 192 | 221 |
| Shown under today's rule (two article feeds) | 54 | 159 |
| Two article feeds **plus** card sites counted separately | 108 | 210 |
| Two distinct article sites | 74 | 174 |
| Two article feeds **or** two distinct article sites (chosen, D4) | 78 ¹ | 176 |
| …of which no article feed at all (card-led) | 4 | 2 |
| Enter the top 15 under the chosen rule | 3 ¹ | 1 |

¹ Measured after the worker rebuilt the day snapshot (193 → 192 stories), where today's rule shows 58, not 54; the
week snapshot was the same for every row.

Counting article feeds and card sites separately counts an outlet twice when the reader follows both its feed and its
social account: *Tesla opens Cybercab pre-orders* and *AI Chatbots won't stop recommending scam websites* reach two
"sources" from one site each. Distinct sites alone hides 1 day and 3 week stories shown today, each carried by two feeds
of one site (heise's *Auslegungssache 172*, *Home Assistant mit KI*; *Mehr Wettbewerb im Fernverkehr*).

Stories entering the top 15, by eye: *'We can't let them down' — Democrats turn to charity* (6 feeds, 1 article feed,
2 sites), *Fetterman signals breaking point over Van Hollen* (5 feeds, card-led: Lemmy posts sharing Independent and
Newsweek articles), *Judge rejects Trump's bid to close East Potomac golf course*, and on the week *Peltola extends
lead in Alaska*. All real. Card-led stories in the pool also include the FAA ground stop (Politico and Gizmodo cards)
and *Alaska voters keep ranked-choice voting*. None is French or Spanish: the dev account follows few French or Spanish
social feeds, so this measures nothing for them.

**Display** (`articleEntryIdsByStory`), in the stories shown today:

| | Day | Week |
|---|---|---|
| Shown stories holding a social post | 11 | 109 |
| Social posts never shown | 33 | 357 |
| …of which carry a card | 30 | 272 |

## Approaches considered

1. **Card text as a separate link source** (a third `StoryLinkSourceInterface` comparing cards to headlines). Rejected:
   the headline index already weighs, caps and filters stems; a second scorer would duplicate it.
2. **Store the card language** (R2). Rejected for now: five write and restore paths plus a backfill, against 1.0 s on
   the week.
3. **Threshold by distinct sites alone.** Rejected: hides stories readers see today (R4).
4. **Threshold by article feeds plus card sites.** Rejected: double-counts outlets the reader follows twice (R4).
5. **A new Trending card for card-led stories** (the card's title as headline, the posts as a byline). Rejected as
   optional scope: the existing link block already shows a post with its card, the sources line and the source list.

## Design

### Reading the card

`TrendingEntryRepository::chunkAfter` also selects, from the same first top-level card, its `title`, `site_name` and
`SUBSTR(description, 1, 2000)`, and `CASE WHEN f.network IS NULL THEN e.url END AS article_url`.

`WindowEntryModel` keeps its first seven fields and replaces `urlHash` and `linkCardUrl` with one
`EntryLinksModel $links`, what the entry links to:

```php
final readonly class EntryLinksModel            // Model/
{
    public function __construct(
        public ?string $urlHash,                // the entry's own article, as today
        public ?string $articleUrl,             // the entry's URL when its feed publishes articles; null for a post
        public ?WindowLinkCardModel $card,      // its first top-level card, any share kind, as today
    ) {}
    public function storyCard(): ?WindowLinkCardModel;   // the card when it names a story
    public function articleSite(): ?string;              // its own site for an article entry, else its story card's
}

final readonly class WindowLinkCardModel        // Model/
{
    public function __construct(
        public string $url,
        public string $title,
        public ?string $description,
        public string $siteName,
    ) {}
    public function namesAStory(): bool;   // D2
    public function site(): ?string;       // ArticleSite::of($this->url)
}
```

`Support/ArticleSite::of(?string $url): ?string` is the URL's host, lower-cased, with a leading `www.` dropped; null
when the URL has no host. The three URL values move together because they are one thing — the entry's links — and
`TrendingGrouping::urlHashesOf` already reads two of them side by side; the model also keeps `WindowEntryModel` at
eight parameters.

### The story card rule (D2)

A card names a story unless any of:
1. its URL's path is empty or `/` (with or without a query);
2. its title holds an account handle: `@` followed by a letter, digit or underscore;
3. its title, reduced to lower-case letters and digits, equals its `site_name`, its host, or its host's first label,
   each reduced the same way (`www.` dropped first). The site name is needed: a *- YouTube* card on `youtu.be` matches
   only through it.

### Grouping

`Service/Curation/Trending/CardLanguage` (root service):

```php
public function of(string $text, ?FeedLanguage $postLanguage): ?FeedLanguage
```

It returns the post's language unless `$text` is at least 150 characters (#2227's `SocialEntryLanguage` threshold) or
the post has no language; then the guess, falling back to the post's language. Its `LanguageGuess` is a second
instance whose detector loads only the `FeedLanguage` values: `LanguageDetectionFactory::createForFeedLanguages()`,
wired in `services.yaml` as `app.trending.card_language_guess` and injected with `#[Autowire(service: …)]`.

`Model/SharedArticleModel(string $title, string $body, ?FeedLanguage $language)` is a story card's text ready to stem:
the body is `EntryText::body($card->description)`.

`TrendingGrouping::collect` builds it for an entry with a story card, `$run->add($entry, $urlHashes, $sharedArticle)`;
`TrendingRun::add` records `articleSiteOf()` from `$entry->links->articleSite()` and passes the article on;
`HeadlineWindow::add(WindowEntryModel $entry, string $body, ?SharedArticleModel $article)` unions the article title's
stems (in the article's language) into the headline stems and its body's into the body stems, a stem held by a word
anywhere counting as held by a word. Every entry with a story card takes part: a post, and the 239 aggregator entries.

### The snapshot

`trending_story_entry` gains `article_site VARCHAR(255) NULL` (migration on MySQL and SQLite, as
`Version20261114180000`). `StoryMemberModel` gains `?string $articleSite`; `TrendingSnapshotRepository::insertStory`
writes it. No backfill: day and week rebuild every 10 minutes and every hour, and a null site falls back to today's
rule.

### Who is shown (D4) and how a story counts (D11)

A story's **sources** for a reader are the distinct outlets among the feeds they follow and have switched in for
Trending (`TrendingInclusionSql::includes`): each article feed (the copies of one article URL once, as #2056 ruled),
plus each social account whose post in the story shares a story card (#2056: per account). An account is the post's
author (`entry.author_url`), else its feed when the post names none, so a post's copies in several of the reader's
timelines and two posts of one account count once, and two accounts carrying one post count twice. A post without a
story card adds nothing; a feed the reader does not follow, or has switched out, never counts (D11). `sources()` in
`TrendingStoryRepository` is that count; it ranks the stories, meets the reader's minimum, and is the label:

```sql
HAVING <sources> >= :minimum
AND (COUNT(DISTINCT CASE WHEN member_feed.network IS NULL THEN story_member.feed_id END) >= :minimumArticleSources
     OR COUNT(DISTINCT story_member.article_site) >= :minimumArticleSources)
```

`MINIMUM_ARTICLE_FEEDS` is renamed `MINIMUM_ARTICLE_SOURCES` (still 2). `noEarlierCopy()` collapses an article's
copies by URL hash, as on develop; a post counts by its account instead. The reader's preferred minimum still scales
with all their active, switched-in feeds, social ones included, since social accounts are sources. The instance-wide
`trending_story.outlet_count` had no reader-facing use left and is dropped (`Version20261115130000`); the grouping
keeps its in-memory feed count only for the explain command and the build's minimum.

### What a story shows (D6, as approved)

`articleEntryIdsByStory` becomes `shownEntryIdsByStory`: a story's entries of the feeds the reader follows in Trending
that are from an article feed **or** carry an `article_site` (a post sharing a story card). `TrendingStories` keys
each row by its article (`ShownRowModel`): an outlet's row by its own URL hash, a post's by
`UrlNormalizer::hash()` of its first top-level card. `TrendingStoryCardModel` then shows **one row per article**
(`StoryArticleModel`):

- an article a followed outlet carries is that outlet's row (newest with a picture, else newest, as today); the same
  article in a second followed feed is no second row;
- an article no followed outlet carries is the newest post sharing it whose card has a picture, else the newest link
  share, else the newest post, shown as the shared card;
- posts attach to the article their card links as **shares**, newest first, a post's timeline copies once; they are
  never rows of their own, and a post sharing a followed outlet's article folds onto that row;
- the lead is an outlet's article whenever there is one (the reader's own outlets first); otherwise the most-shared
  article, ties to the newest.

The lead's own copies from feeds outside the story (`withLeadCopies`) no longer join the card: one row per article.

**Sketch, magazine layout, a story no followed outlet carries:**

```
┌──────────────────────────────────────────────────────────┐
│ (avatar) Aaron Rupar · 2 sources · 3 shares · 2 h    •   │  kicker: sources, shares, unread dot
│ ┌──────────────────────────────────────────────────────┐ │
│ │ [image]  John Fetterman Signals Breaking Point With  │ │  app-link-card: the shared article heads the row
│ │          Democrats Over Van Hollen                   │ │  (the post's own text is not shown)
│ │          newsweek.com                                │ │
│ └──────────────────────────────────────────────────────┘ │
│ Shared by Aaron Rupar, Politico and 1 other ⌄            │  app-story-shares: opens into each post
│ ☆ ⧉ … Hide                                               │  actions, Hide last
│ ≡ 1 more source ⌄                                        │  app-more-sources: the other articles, "· N shares"
└──────────────────────────────────────────────────────────┘
```

**List layout** (`entry-row`): the article row, "N sources · M shares ·" on the attribution line, the "Shared by"
line under the meta row, and the "Also covered by" footer naming the other articles' outlets.

**D7:** `EntryDuplicatesComponent` names a copy with `sourceNameOf(copy, rows)` when its entry is a Trending story
(`entry.story`), as `MoreSourcesComponent` already does, so a share-led row reads as its poster, not as its timeline
(*Home*, *Discover*). Footers outside Trending keep naming the feed ("Also in Home").

### The rest of a story's life

- **Opening.** The lead post opens like any post (`appOpensEntry`); opening marks it viewed, and `viewedStoryIds`
  already counts social entries. The card itself opens the article in the link reader, as in every other list.
- **Hiding.** `storyIdContaining` finds the story through any entry of a feed the reader follows, social ones included,
  and `hideStory` hides every member. No change; a functional test pins it for a card-led story.
- **Unopened counts.** `unopenedFor` ranks the same stories the page shows; card-led stories count like any other.
- **The reader's own feeds first.** The lead comes from article rows whenever there is one (above).

### API

Additive only. `GET /api/trending` keeps its shape: `stories` is a list of `EntryDto`, each with `story` and
`duplicates` (the story's other articles). Each article `EntryDto` of a Trending story gains `shares`: the posts sharing
it, as ordinary post `EntryDto`s (a native client renders and opens them as it renders a post in any list), and
`story` gains `shareCount`. `outletCount` keeps its name and now means the reader's sources (D11). `POST
/api/trending/hidden` is unchanged. No browser-only input (architecture §6).

### UI text

New keys in de, en, fr and es (`vous`/`tú` per the #2205 glossary): `reader.trendingShares`,
`reader.trendingSharesOne`, `reader.sharedBy`, `reader.sharedByAndOne`, `reader.sharedByAndOthers`. Two names alone are
joined by `Intl.ListFormat` in the reader's language; the rest are counted as accounts, not people ("und 2 weiteren",
"et 2 autres", "y 2 más"). A story's expand button counts its other articles (`reader.moreArticles`,
`reader.moreArticlesOne`, replacing `reader.moreSources*`, which nothing else read). The sources line, "Also covered by"
and Hide are unchanged.

## Decisions

Lars delegated every decision. Each is "what — why — cost if wrong".

- **D1. Card title to headline, description to body, today's weights, every entry's first top-level card.** — The card
  title is the article's headline, the description its lead: exactly the two places the grouping already weighs; the
  first card is the one the URL link already uses. Measured +2,375 week and +195 day pairs net (R3). — If wrong: posts
  whose card is a teaser (*COP to it*) carry the newsletter's name; their own text still has to agree.
- **D2. A card names no story when its URL has no path, its title holds an account handle, or its title is its site's
  name.** — Three structural shapes from R1, none a name list: a site, a person, a site name. 124 cards (2.3 %). The
  third removes the one wrong merge R3 found (*- YouTube*). — If wrong: a story whose real headline is the site's name
  (a site launch) loses its card text; a quoted post's description, which may name the story, is lost with its card.
- **D3. The card's language is guessed at read time, by a four-language detector, only for a text of 150 characters or
  more, else the post's.** — 0.185 ms per card, 1.0 s on the week; the full detector costs 9.9 s, and storing needs a
  column, two write paths, the backup format and a backfill (R2). The length rule is #2227's, and short titles are where
  the guesses go wrong. — If wrong: about 20 long English cards a week stem as French or Spanish and lose their links;
  a German card on a short English title stems as English.
- **D4. Shown when two article feeds or two distinct article sites carry the story.** — The OR keeps every story shown
  today (distinct sites alone hides four), and distinct sites stop an outlet's feed and its social account counting
  twice (R4). Day pool 58 → 78, week 159 → 176. — If wrong: two feeds of one site still count as two, as today; a
  shortener host (`trib.al`) counts as one site for several outlets, so some stories stay hidden.
- **D5. The article site is stored on `trending_story_entry` at build, with no backfill.** — Host extraction in SQL
  differs between MySQL and SQLite; the snapshot is rebuilt within the hour anyway. — If wrong: for up to an hour after
  deploy the week shows today's stories.
- **D6. One row per article; posts are its shares.** Lars approved the redesign on 2026-11-15, replacing "posts are
  sources, one row per feed". — A post is the article it shares, so listing it as an outlet repeated the article and
  inflated busy stories; grouping by article shows each story once per piece of journalism, names who shared it, and
  keeps the reader's own outlets first. — If wrong: a reader who wants to see a post's own text opens the "Shared by"
  line first; two outlets with one article show one row.
- **D7. In a Trending footer a post is named by its poster.** — "Also covered by Home, Discover" names timelines, not
  sources; the magazine list already uses `sourceNameOf`. — If wrong: a share-led row whose poster is unknown reads as
  its timeline.
- **D8. Additive API (`shares`, `shareCount`) and five new labels in four languages.** — Superseded by D6's redesign;
  the shares are ordinary post `EntryDto`s, the smallest shape that both the web client and a native client can open.
  — If wrong: a busy story carries every sharing post in the payload.
- **D9. A story no followed outlet carries is headed by the shared card; the post's text is not shown.** — Superseded
  by D6: the row is the article; its sharers are named under it. — If wrong: the representative poster still shows in
  the kicker line.
- **D10. `urlHash`, `linkCardUrl` and the new article URL move into `EntryLinksModel`.** — They describe one thing and
  are read together; `WindowEntryModel` would otherwise reach PHPMD's ten-parameter limit. Recorded so review can judge
  it is not a count-driven split (#2025): `articleSite()` and `storyCard()` live on it and have no other home.

- **D11. A story's sources are the reader's own followed, Trending-included outlets: their article feeds, and each social
  account whose post shares a story card.** Lars, 2026-11-15, first: "feeds i don't follow shouldn't be counted at
  all"; then, correcting it: "Social posts should count as sources. my remark 'feeds i don't follow shouldn't be
  counted at all' relates to feeds which are in the system but followed by another user". — One count ranks, meets
  the minimum and labels the card; no other user's feed reaches it. Week story 358028's "11 sources" over three rows
  came from 8 followed timelines whose posts had no story card (the snapshot predated the migration); they no longer
  count without a card. — If wrong: an account that posts without a card, or whose author the post does not name,
  counts by its feed; one account followed both as a profile feed and through its posts' author counts twice.

**Optional scope, costed and not built:** storing the card language (D3: a column, five paths, a backfill command);
resolving shorteners for site counting (an outbound fetch per card, against the SSRF boundary); a card-title headline
variant of the link block for Trending (a block variant, its height model in `magazine-block-height.ts`, and specs);
counting two feeds of one site as one (D4: hides stories shown today).

## Measurement plan

The #2227 method, as in #2231:

- **A** = develop at f5e4b51af, **B** = this branch's HEAD. A is made by swapping in, with `git archive f5e4b51af`,
  `backend/src/Service/Curation/Trending`, `backend/src/Repository/TrendingEntryRepository.php`,
  `TrendingStoryRepository.php` and `TrendingSnapshotRepository.php`, and restoring them after each run; the container
  is cleared and warmed before each timed run.
- "Now" pinned with `simp-explain-at.php` to one time in the past hours of the run day, the same for A and B; day and
  week, back to back. The header lines must show equal entry counts; otherwise run A again.
- Report, per window: groups, grouped pairs new / lost / **net**, explain time, peak PHP memory and peak RSS
  (`/usr/bin/time -l`).
- **Per language pair:** de–de, en–en, fr–fr, es–es, mixed or none, each pair's languages looked up by its printed line
  (ambiguous lines count as mixed).
- **French-only and Spanish-only windows,** day and week: the window query restricted to one
  `COALESCE(entry.language, feed.language)` in a throwaway copy of `TrendingEntryRepository`, A and B the same way.
- **Card facts for fr and es separately:** card share, title and description share (R1), and the card-language guess
  for French and Spanish (a German or English post linking a French or Spanish article, and the other way round),
  checked by eye.
- **Eye check:** the largest new, grown and split groups, the members of cards with a generic short title, and every
  wrong merge, French and Spanish groups named separately.
- **Stories that appear only because of this change:** a throwaway script groups the pinned window with B, then for
  the dev account cuts it as `rankedForUser` does (followed feeds, the OR rule, the top 15) under A's rule and B's, and
  lists every story that appears only under B — card-led or not, French and Spanish ones named — each checked by eye.
- Expected from R3 and R4: week about +2,375 net, day about +195, fr and es week a few new and none lost, week explain
  time about +20 %. A week loss, or a wrong merge through a card title, is a finding to report, not a value to tune.

## Limitations

- Shorteners count as their own site (`trib.al` stands for several outlets, `n.pr` for NPR).
- A generic card title not shaped like the site's name (*Client Challenge*, *DPG Media Privacy Gate*, *Join –
  UsedMuse*) still adds its stems; its rarity and the 0.4 share of the lighter entry are what hold it back.
- The four-language detector reads every text as one of de, en, fr, es; the length rule limits where that matters.
- French and Spanish carry few cards on the dev stack (44 and 49 a week); their gain is measured, but small.

## Outcome

Measured on 2026-11-15 by the method above: dev MySQL read-only, "now" pinned to **2026-11-15 10:00:00 UTC**, A =
f5e4b51af's file set swapped in with `git archive`, B = this branch (868e2eb49 for the grouping, 8cedebc59 for the
rest), container cleared and warmed before each run, A and B back to back. Entry counts matched in every pair. Scripts
and outputs are in the session scratchpad under `2229/final/`.

### Grouping

| | Day A | Day B | Week A | Week B |
|---|---|---|---|---|
| Entries | 4,726 | 4,726 | 29,419 | 29,419 |
| Groups | 557 | 559 | 3,764 | 3,761 |
| Grouped pairs | 1,875 | 2,056 (+181 net) | 17,817 | 20,114 (+2,297 net) |
| Explain time | 4.3 s | 5.1 s | 71.3 s | 83.0 s |
| Peak PHP | 55.0 MB | 58.7 MB | 177.5 MB | 192.3 MB |
| Peak RSS | 94.6 MB | 96.7 MB | 239.4 MB | 253.5 MB |

| new / lost / **net** | Day | Week |
|---|---|---|
| de–de | 73 / 33 / **+40** | 991 / 415 / **+576** |
| en–en | 183 / 54 / **+129** | 3,504 / 1,907 / **+1,597** |
| fr–fr | 0 / 0 / **0** | 6 / 2 / **+4** |
| es–es | 0 / 0 / **0** | 2 / 0 / **+2** |
| mixed or none | 14 / 2 / **+12** | 195 / 77 / **+118** |
| **all** | 270 / 89 / **+181** | 4,698 / 2,401 / **+2,297** |

French-only and Spanish-only windows, A → B:

| | Entries | Groups | Grouped pairs |
|---|---|---|---|
| French day | 429 | 52 → 52 | 161 → 161 (0) |
| French week | 541 | 65 → 64 | 188 → 192 (+4 new, 0 lost) |
| Spanish day | 395 | 14 → 14 | 19 → 19 (0) |
| Spanish week | 859 | 34 → 35 | 53 → 54 (+1 new, 0 lost) |

No week loss, as R3 predicted (+2,375 / +195 there, on a window an hour earlier).

### Card facts, per language (week, social posts)

| Post language | Posts | With a card | Card share | With a description | Cards naming no story (D2) |
|---|---|---|---|---|---|
| de | 3,130 | 1,664 | 53 % | 1,512 (91 %) | 24 |
| en | 7,364 | 3,664 | 50 % | 3,228 (88 %) | 96 |
| fr | 107 | 44 | 41 % | 35 (80 %) | 0 |
| es | 122 | 49 | 40 % | 45 (92 %) | 3 |
| none | 380 | 56 | 15 % | 50 (89 %) | 4 |

Every card has a title. `CardLanguage` on the 5,350 story cards, post → card language where they differ: de → en 20,
en → de 20, en → es 14, en → fr 9, fr → en 11, fr → de 3, es → en 5, es → de 1, de → fr 2, none → de 12, none → en 38,
none → es 2. By eye, every French and Spanish case:
- **Right:** fr → en 11 (BBC, Laravel News, `trib.al` cards: English articles), fr → de 3 (a German RSS-reader review on
  `stadt-bremerhaven.de`, a ZDF COP31 card), es → en 5 (Politico, TechCrunch, Yahoo, news9), es → de 1 (heise), en → es
  4 (EFF's Spanish articles, an infobae card), none → es 1 (EFF).
- **Wrong:** en → fr 9 and en → es 7 are long English `politi.co` cards (and one TechCrunch, one Washington Examiner
  card), the misguess #2235 owns; en → es 3 are Turkish and Indonesian cards the four-language detector must name as one
  of four (harmless: they link to nothing either way); de → fr 2 is one heise card twice; none → es 1 is a COVID
  dashboard.

### Eye check (week)

- **Grown groups** reunite fragments of one event, as in R3: the Microsoft outage (7 + 5 + 4 + 3 + … → 29), Van Hollen's
  challenge (30 → 49), the FAA ground stop (37 → 47), Europe at the Abu Dhabi talks (3 + 3 + 3 + 2 → 12), the Alaska
  count (9 + 4 + 2 + 2 → 17), the Holscher win, *ground stop lifted*, the Outlook outage, the Nvidia/Huang affair and
  resignation.
- **Split groups** are the greedy grouper cutting one event differently (the Nvidia story into 10 + 5 + 2, the
  Starship catch into 8 + 7, the ground stop into 37 + 5, the Nasser hospital strike into 5 + 5), not stories lost.
- **Generic short card titles** shared by two or more cards whose posts gained pairs: *SpaceX* (named no story under
  D2; its posts gained through other cards), *Zelda's Midlife Crisis* (one Atlantic review, right), *COP to it* and
  *Long nights ahead* (Politico newsletter names, joined the Burnham-at-COP31 posts they are about, as in R3),
  *ATCSCC Advisory* (an FAA page, joined the ground stop: right), and *Israel's Secret Broker* joining a post whose
  YouTube card is *Jared Kushner's Secret Deals*: one channel, linked by *Secret* and the channel's boilerplate
  description; borderline, as in R3. No *- YouTube* merge: D2's site-name test removes it.
- **French:** the Larcher presidential group is cut differently (it gains a Le Parisien piece and loses a franceinfo
  piece). **One wrong French merge:** two posts of one account (`atlasflux.saynete.com`, a press-feed directory),
  each in two timelines, whose cards share the site's template title (*Atlas des flux – … – annuaire des RSS de la
  presse*) and template description, join one group (*Irak ajouté aux territoires* with *Mise en place du groupe dédié
  aux flux vides et bloqués*). It is never shown: one site, no article feed. A finding, not tuned (anti-goal: no list).
- **Spanish:** the one new pair is right (*Más de 10.000 retrasos…* with *Persiste el caos en los vuelos en EE.UU.*,
  through its card *EE.UU. suspende todos los vuelos…*); the full window adds the Feijóo pair.

### Who is shown (dev account, user 2)

A throwaway script grouped the pinned window with B and cut it for user 2 as `rankedForUser` does (followed feeds with
the inclusion switches, the relaxing minimum, top 15 by outlets then newest), under A's rule and B's. It leaves out
`noEarlierCopy()` and the week's exclusion of stories shown in the day, so it approximates.

| | Day | Week |
|---|---|---|
| Stories in B's grouping | 215 | 240 |
| Preferred minimum (active feeds) | 4 (109) | 5 (196) |
| Pool under A's rule / B's | 13 / 24 | 33 / 50 |
| …card-led in B's pool | 8 | 8 |
| Enter the top 15 only under B | 8 (7 card-led) | 5 (2 card-led) |
| Leave the top 15 under B | 6 | 5 |

Every story entering is real, by eye: day — Alaska keeps ranked-choice voting, Hungary's buried cash, Italy fines Meta,
Fetterman over Van Hollen, *'We can't let them down'*, the DC golf course ruling, Duffy blames an FAA contractor (all
card-led), the Tesla solar fraud ruling; week — Van Hollen, Colom's Mississippi win, Peltola in Alaska (card-led),
ranked-choice voting (card-led), the Cybercab orders. None is French or Spanish: the dev account follows few French or
Spanish social feeds.

**Finding.** The stories pushed out are article-led and include the reader's French, Spanish and German outlets: day —
Le Figaro (Netanyahou), Le Monde (Ukraine live, the 2027 presidential race), Le Parisien (Wales – New Zealand), El País
(*La bola negra*), tagesschau; week — four DIE ZEIT/RND/Hamburg stories. Card-led stories rank high because the
ranking counts feeds, and one post copied into several timelines (Home, Discover, Local) counts once per timeline.
R4 measured 3 and 1 entering on the worker's snapshot; the pinned window is busier with US election stories. The
design does not tune this (D4/D6 are the spec's rulings); a follow-up could rank by distinct sources or count a post's
timeline copies once.

### Rebuild time

Profiled in phases on the week (collect / links / groups, `hrtime`): A 9.8 / 29.7 s, B 12.5 / 32.3 s. A rebuild runs
collect and links once (the explain command runs links twice, hence its +16 %): **39.5 → 44.8 s, +13 %**.
- collect +2.7 s: the card language guess 0.87 s (3,971 of 5,551 story cards are long enough to guess), the card body
  0.02 s, stemming card text 0.6 s, the rest the per-entry links model; the chunk query is unchanged (1.0 s both).
- links +2.6 s: the added stems give 2,121 more candidate links (21,695 → 23,816) to weigh.

No cheap part: the guess is D3's rule, and the stems are the change. On Strato's ~25 s week rebuild this is about
+3 s.

### Revisions after review: copies, the count, one row per article

Three changes followed the first report (commits 07b7d9f7c, 1881e3ab1, 3f960f810, 2f68af4a3, cab2678ef, b671c5631):
a post's timeline copies count once; a story's sources are the reader's own outlets (D11); and the card shows one row
per article with its shares (D6, approved by Lars). Lars's correction then restored social accounts as sources
(66d4c9385); the tables below that one supersede these. Lars's week story 358028 showed "11 sources" over three rows: the stored count was not the cause
(the reader's count already applied the inclusion switches), 8 of the 11 were followed social timelines whose posts
had no story card in a snapshot built before the migration. Develop has the same defect since #2017: it counts every
followed social feed in the label and never shows a post.

**The grouping is unchanged** by these commits: none touches the window read, the stems, the links or the grouper, so
the pair tables above stand.

**Who is shown, again** (the same pinned window and script, now with the reader's switches as Lars had them at 11:40
UTC: 121 active feeds on the day, 208 on the week, against 109 and 196 earlier, so A differs from the run above). A =
develop's counting; B2 = D4 with a post's copies once; B3 = the branch (D4 and D11).

| Top 15 | A | B2 | B3 |
|---|---|---|---|
| Day | de 3, en 10, fr 2 | de 2, en 10, fr 3 | de 3, en 6, fr 6 |
| Week | de 1, en 14 | de 1, en 14 | en 15 |

- **Day, A → B3: returns** Le Parisien (*Guerre en Ukraine : Emmanuel Macron…*), Le Figaro (*Le gouvernement interdit
  la vente…*, *Benyamin Netanyahou promet…*), Le Monde (*EN DIRECT, guerre en Ukraine*) and tagesschau (*Astrid Halder
  zum Trauerakt*); **leave** the English stories social timelines lifted (Italy fines Meta: 11 → 3 + its card sites,
  Hungary's buried cash, the FAA equipment blame, an Al Jazeera strike) and tagesschau's *Grüne und SPD*. No Spanish
  story reaches the day's top 15 under any rule.
- **Week, A → B3:** stories with more distinct outlets replace those social timelines inflated: in come the ocean liner
  reef, the Kennedys and Trump's arch, Gadi Eisenkot, the Hegseth tomb scolding, the Nvidia investigation; out go Italy
  and Meta, the Alaska count, ZDF's *Mercedes stoppt alle neuen Investitionen* (9 → 6 sources: its social copies no
  longer count), the US soldier spying arrest, Hungary's cash, the Donbas offer. Every week top-15 story is English
  under B3: the reader's switched-in outlets are mostly English, and outlets now decide.
- Concern 1 of the first report is resolved: card-led stories no longer outrank article stories by timeline copies.

### Who is shown, final (social accounts as sources)

The same pinned window and script, the reader's switches as at 12:30 UTC (121 active feeds on the day, 208 on the
week; the preferred minimum is 4 and 6). A = develop; B = the branch (D4, D11 with social accounts, one row per
article).

| | A pool / top 15 | B pool / top 15 |
|---|---|---|
| Day | 27 stories; de 3, en 11, fr 1 | 30 stories; de 2, en 11, fr 2 |
| Week | 66 stories; de 1, en 14 | 56 stories; de 1, en 14 |

- **Day:** in come franceinfo's *Il y aura bien une hausse des points…* and NPR's Alaska ranked-choice vote; out go
  tagesschau's *Grüne und SPD stimmen Koalitionsvertrag zu* and the Guardian's New Orleans tanker lawsuit. No Spanish
  story in either.
- **Week:** in come the Guardian's New Orleans boat crash and the ocean-liner reef; out go BBC's Alaska vote count
  (its 10 sources were mostly timelines whose posts had no card) and Al Jazeera's Hungary cash. The week pool shrinks
  (66 → 56) because posts without a story card no longer count.

