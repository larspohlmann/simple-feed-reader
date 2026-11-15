# Clean tracking parameters from stored article URLs (#2237) — design

## Intent

An article URL is stored once, without the parameters that only track the click, the way Brave's *Copy clean link*
hands it out. Feed ingest, social link cards and the backup restore all store the same cleaned form, and the dedupe
hash is computed from it, so the stored URL and its `url_hash` always agree. Existing rows are brought in line by a
bounded console command that Lars runs.

Lars (verbatim, #2237 ledger): "the resolved url should be cleaned (similar to 'copy clean link' in brave), the same
cleaning should happen during ingest of rss feeds".

## Goals

- G1. One cleaner, in `Service/Foundation/Url`, that every writer of an article URL calls. `UrlNormalizer` uses the
  same list for the hash, so `normalize(clean(u)) === normalize(u)` holds for every URL.
- G2. The list is compiled from each vendor's own documentation, cited below. Nothing is copied from Brave (MPL-2.0),
  ClearURLs (LGPL-3.0) or AdGuard (GPL-3.0), and nothing comes from the current news window.
- G3. A URL carrying no tracking parameter comes back byte for byte. The path, the host's spelling and every other
  parameter keep their order and bytes. A fragment goes only when it is nothing but tracking pairs.
- G4. Existing data: `entry.url`, `entry.url_hash` and `link_card.url` are cleaned and recomputed by
  `app:urls:clean-tracking`. It works in batches, is idempotent, has a `--dry-run` count, and runs on a copy first.
- G5. #2239 (`shared_link` keyed by the hash of the cleaned card URL) and #2236 (resolved URL, cleaned) build on this
  cleaner; neither needs a second one.

## Non-goals

- **No value rule** (superseded by the D3/D4 ruling). Dropping any pair whose value is `rss`, `feed` or `atom` (`cmp=rss` at CBC, `traffic_source=rss`
  at Al Jazeera, `via=rss` at Slate, `ref=rss` at Der Standard) was measured as variant B2 and rejected (D4).
- **No publisher-internal keys** (superseded by the D3/D4 ruling). `mrfcid` (Slate, 331 cards), `cmp` (CBC, Guardian), `traffic_source`, `via`,
  `sara_ref` (Spiegel), `reduced` (SZ), Substack's `r`, `triedRedirect` and `showWelcomeOnShare`, Instagram's `exln`,
  `srtk`, `rpxt`, and X's `s`/`t` have no vendor documentation. Adding them would be curation from the data (D3).
- **No other URL columns.** Enclosures and media, chapter files, author and avatar URLs, `discussion_url`,
  `quoted_url` and image URLs are not article URLs, and stay as given.
- **No redirect resolution.** That is #2236.
- **No `shared_link` table.** That is #2239.
- **No Doctrine migration.** The schema does not change (D7).
- **No frontend change.** The reader shows whatever URL the entry stores.

## Approaches considered

1. **A method on `UrlNormalizer` (`clean()`), injected everywhere.** This is what the issue suggests. But the card
   choke point `StorableLinkCard::of()` is a static `Support` helper, called from other static helpers
   (`MastodonCard`, `BlueskyExternalCard`, `PostedLinkCard`) and from the backup DTO `EntryLine`. Injecting a service
   would turn four static helpers into services and thread the cleaner through their callers. Rejected for its blast
   radius.
2. **A static `Support/TrackingFreeUrl` in the Url module, used by `UrlNormalizer` and by every writer.** The list and
   the pair test live in one class. `UrlNormalizer::normalize()` filters its query through it, and `withoutTracking()`
   becomes a call to it. It is still one cleaner, in the module the issue names, and the hash and the stored URL share
   one list by construction. **Chosen** (D1).
3. **Clean at read time only.** The stored URL would stay dirty, and saved copies and backups would carry the trackers.
   This does not meet the issue. Rejected.

## Design

### The cleaner: `App\Service\Foundation\Url\Support\TrackingFreeUrl`

`final class` with a private constructor (the `StorableLinkCard` shape), static only:

- `public static function of(string $url): string`. This is the cleaned URL.
  - A URL without scheme and host comes back unchanged. Validating URLs is not this cleaner's job.
  - The query is split on `&`. A pair is dropped when it is tracking (below). The kept pairs are rejoined in their
    order, byte for byte.
  - When every pair goes, the `?` goes too. A bare `?` that had no pairs stays (G3).
  - The fragment is dropped when it holds `=` and every `&`-separated pair in it is tracking (`#xtor=RSS-3-[lestitres]`,
    `#Echobox=1762…`). Any other fragment stays verbatim (`#comment-5`, `#:~:text=…`, `#section-3`).
  - The scheme, host, port, path, kept pairs and any other fragment are copied verbatim. Splitting happens on the raw
    string at the first `?` and the first `#`; `parse_url` is used only to read the host and to reject URLs that are
    not absolute.
- `public static function query(string $query, string $host): string`. This is the filtered query, without a leading
  `?`, for `UrlNormalizer::normalize()`.

A pair is **tracking** when its key, after `rawurldecode`, lower-casing and removing one leading `amp;`, is one of:

| Family | Keys | Vendor source (page title, URL; confirmed 2026-11-15) |
|---|---|---|
| Google Analytics campaign (prefix) | `utm_*` | "URL builders: Collect campaign data with custom URLs" (support.google.com/analytics/answer/10917952) |
| Google Ads | `gclid` | "About auto-tagging" (support.google.com/google-ads/answer/3095550) |
| Google Ads iOS measurement | `gbraid`, `wbraid` | "Updates to iOS 14 campaign measurement" (support.google.com/google-ads/answer/10417364) |
| Search Ads 360 | `gclsrc` | "About click IDs" (support.google.com/sa360/answer/7342044) |
| Campaign Manager 360 | `dclid` | "Enhanced attribution" (support.google.com/campaignmanager/answer/9182069) |
| Merchant Center | `srsltid` | "About auto-tagging" (support.google.com/merchants/answer/15191080) |
| GA4 cross-domain linker | `_gl` | "Set up cross-domain measurement" (support.google.com/analytics/answer/10071811) |
| Meta | `fbclid` | "ClickID and the fbp and fbc Parameters" (developers.facebook.com/docs/marketing-api/conversions-api/parameters/fbp-and-fbc) |
| Microsoft Advertising | `msclkid` | "Conversions API (CAPI) - Microsoft Advertising API" (learn.microsoft.com/en-us/advertising/guides/uet-conversion-api-integration) |
| TikTok | `ttclid` | "About TikTok Click ID" (ads.tiktok.com/help/article/tiktok-click-id) |
| X (Twitter) | `twclid` | "Conversion API for Web" (docs.x.com/x-ads-api/measurement/web-conversions) |
| LinkedIn | `li_fat_id` | "Enabling Click IDs - LinkedIn" (learn.microsoft.com/en-us/linkedin/marketing/conversions/enabling-first-party-cookies) |
| Yandex | `yclid` | "URL parameters" (yandex.com/support/direct/en/statistics/url-tags) |
| Mailchimp | `mc_cid`, `mc_eid`, `mc_tc` | "Getting started with e-commerce" (mailchimp.com/developer/guides/getting-started-with-ecommerce/) |
| HubSpot email | `_hsenc`, `_hsmi` | "Links in emails or legacy CTAs lead to an error page" (knowledge.hubspot.com/marketing-email/links-in-emails-or-ctas-lead-to-an-error-page); the page's list prints `_hsenc` without its underscore |
| HubSpot cross-domain | `__hstc`, `__hssc`, `__hsfp` | "Set up site tracking in HubSpot" (knowledge.hubspot.com/reports/set-up-sources-tracking) |
| Marketo (Adobe) | `mkt_tok` | "Lead Tracking" (experienceleague.adobe.com/en/docs/marketo-developer/marketo/javascriptapi/leadtracking/lead-tracking) and "Disable Tracking for an Email Link" (experienceleague.adobe.com/en/docs/marketo/using/product-docs/email-marketing/general/functions-in-the-editor/disable-tracking-for-an-email-link) |
| Adobe Experience Cloud | `adobe_mc` | "Share identity across domains" (experienceleague.adobe.com/en/docs/experience-platform/web-sdk/identity/id-sharing) |
| Vero | `vero_id`, `vero_conv` | "Why is vero_id appended to links?" (help.getvero.com/vero-1/customers/what-is-vero_id) and "Conversion goals" (help.getvero.com/vero-2/campaigns/conversion-tracking) |
| Omeda | `oly_enc_id` | "Web Behavioral Tracking - Olytics Overview" (knowledgebase.omeda.com/omedaclientkb/cdp-olytics-overview) |
| Matomo | prefix `mtm_*`; `pk_campaign`, `pk_kwd`, `pk_keyword`, `pk_source`, `pk_medium`, `pk_content`, `pk_cid`, `piwik_campaign`, `piwik_kwd`, `matomo_campaign`, `matomo_kwd` | Matomo FAQ, "What URL parameters are supported for campaign tracking?" (matomo.org/faq/faq_120/) |
| Piano Analytics (AT Internet) | prefix `at_*`; `xtor` | "How does the tracking of marketing campaigns work" (docs.piano.io/en/analytics/how-does-the-tracking-of-marketing-campaigns-work) |
| Mapp Intelligence (Webtrekk) | prefix `wt_*` | "Campaigns" (docs.mapp.com/docs/tracking-configuration-campaign), which names `wt_mc`, `wt_cc1` and `wt_ga` |
| Echobox | `echobox` | Echobox Help, "Query parameters" (help.echobox.com/query-parameters). Echobox adds it as a fragment, or as a query pair when the URL already has a fragment |

**Per-host keys.** These are the share-attribution parameters each site appends to its own share links. They are
applied only on the named domain and its subdomains (`host === domain` or it ends in `.domain`), because the key is
generic elsewhere. No vendor documents them; each is a single observed fact, not a copied list.

| Domain | Keys | Source | Why it never names the page |
|---|---|---|---|
| `youtube.com` (incl. `www.`, `m.`, `music.`), `youtu.be` | `si`, `feature` | `si`: "YouTube-Videolinks neuerdings mit Tracking-Zusatz" (ifun.de/youtube-videolinks-neuerdings-mit-tracking-zusatz-223731/); `feature`: observed on the site's own share button | The share sheet's share-session id and the share-surface label. The video is `v` or the path, the time is `t`, a playlist is `list`, and those stay. |
| `spotify.com` (`open.spotify.com`) | `si` | observed on the site's own share button | The share-session id. The item is the path. |
| `nytimes.com` and subdomains | `smid`, `smtyp` | observed on the site's own share button | The share-medium tags. The article is the path. `unlocked_article_code` (a gift link) stays. |

**Undocumented trackers (D3 ruling).** No vendor documents these; each is a key whose name never carries content.

| Tier | Keys | Where |
|---|---|---|
| Everywhere | `mrfcid` (Slate), `traffic_source` (Al Jazeera), `sara_ref` (Spiegel), `igshid`, `igsh` (Instagram), `_bhlid` (beehiiv), `sfmc_id` (Salesforce Marketing Cloud), `rb_clickid`, `triedRedirect`, `showWelcomeOnShare` (Substack) | every host |
| Host-scoped | `r` | `substack.com` and subdomains |
| | `s`, `t` | `x.com`, `twitter.com` and subdomains |
| | `exln`, `srtk`, `rpxt` | `instagram.com` and subdomains |
| Value-scoped (D4 ruling) | `cmp`, `via`, `ref` | any host, only when the value is `rss`, `feed` or `atom` (case-insensitive) |

YouTube's `t` (a time) is content and stays everywhere.

**Gift keys are never stripped.** Checked before every rule above, on every host: `unlocked_article_code` (NYT),
`gift` (The Atlantic), `pwapi_token` (Washington Post), `accessToken` (Bloomberg, FT), `sharetype` (FT),
`giftToken` (Spiegel), and the generic `giftcode`, `gift_token`, `share_token`, `access_token`; `st` on `wsj.com`.

The prefixes `utm_`, `at_`, `wt_` and `mtm_` stay prefixes, as `utm_` and its siblings already are today. Every other
entry matches the whole key. `pk_` is listed key by key, because a `pk_` key elsewhere can be a primary key.

**Citations are checked before they ship.** Task 1 opens each source. When a page does not name its key, the key is
dropped from the list and the drop is recorded in the Outcome. A key is never kept without its citation. The pages
marked *checked 2026-11-15* were confirmed during this spec. The rest are the vendors' documented pages as known and
are confirmed in Task 1.

### `UrlNormalizer`

- `TRACKING_PREFIXES`, `TRACKING_KEYS` and `isTracking()` go. `query()` calls `TrackingFreeUrl::query($query, $host)`.
- `normalize()` is otherwise unchanged: the scheme and host lower-cased, the default port dropped, the fragment
  dropped. `hash()` is unchanged.
- `withoutTracking(?string $url)` returns `null` when `normalize($url)` is `null`, else `TrackingFreeUrl::of($url)`.
  The shared address therefore keeps the host's spelling and loses a tracking fragment, which is what `LinkPosts`
  shares.

### Writers

| Writer | Change |
|---|---|
| RSS/Atom and social ingest (`IngestedEntryFactory::create`) | Store `TrackingFreeUrl::of($parsed->url)` (then the existing 2048 cut). The hash `EntryIngestor` computes from the raw URL is unchanged, by G1. |
| Every link card (`StorableLinkCard::of`: Mastodon, Bluesky, posted cards, `PageCardReader`, `SharedPageCards`, backup lines, quoted-post cards) | Builds the card with `TrackingFreeUrl::of($card->url)`, after the URL checks. |
| Backup restore (`EntryLine::fromLine`, entry `url`) | `TrackingFreeUrl::of()` on the restored URL. `EntryBatchInserter` already recomputes `url_hash`. |
| `LinkPosts` share | Already goes through `withoutTracking()`. |

Nothing else writes an article URL into an entry. `E2eSeedAdminSubscriptionCommand` writes fixed fixture URLs.

### Checking a stripped URL where a card is fetched

Lars (verbatim): "to be safe you could compare the response of the link with parameters to the response with
stripped parameters during link-card creation".

Lars (verbatim, on checking only undocumented keys): "yes, skip the comparison for documented trackers".

- Ruling: only the share link-card sweep checks (`ShareLinkCards` → `ShareTargetReader` → `PostedUrlCheck`), and
  only for a link whose posted href lost a key no vendor documents: one of the undocumented everywhere-tier keys, a
  host-scoped key (YouTube, Spotify, NYT, Substack, X, Instagram) or a value-scoped feed marker
  (`TrackingFreeUrl::stripsAnUndocumentedKey()`, which `SharedLinksModel::$postedForms` filters by). A link that
  lost only documented vendor keys (`utm_*` and the other prefixes, the click ids, the cited table) is stored
  cleaned with no second read. Cards that come in a network payload, titled shares (their entry URL was cleaned at
  ingest) and RSS ingest fetch nothing extra and stay trust-based. Cost if wrong: a wrongly stripped key there, or
  a documented key that names a page after all, goes unnoticed.
- Ruling: the cleaned URL is read first, the posted href second, both through `PageCardReader` (the same outbound
  client, SSRF boundary and timeouts). Two pages are the same when their titles (`og:title`, `twitter:title`, else
  `<title>`) match and either their tracking-free final URLs or their canonical URLs (`<link rel="canonical">`,
  else `og:url`) match. Bodies are not compared. Cost if wrong: a site that varies its title per visit keeps its
  posted URL.
- Ruling: same page → the cleaned URL is stored. Another page, or the cleaned URL fails while the posted one is
  read → the posted href is stored as is, with the posted page's card, and one info line logs the host, the
  stripped keys and which of the two it was ("showed another page" or "the cleaned URL failed"). The posted URL failing, or a file (no page identity), trusts the cleaner. Nothing is learned or
  stored besides the card. Cost if wrong: none to data; the log is the only trace.
- Ruling: each check is one more fetch against `LinkCardBudgetModel`; a row's check is dropped when the budget has
  no room left for it, and the row is carded on its cleaned URLs. A stored posted-form card still cards its link,
  because `ShareTargets::uncardedOf()` compares carded URLs tracking-free.
- Ruling: `app:urls:clean-tracking` cleans every card URL, a kept posted form included; it is a one-off run after
  the deploy, so only cards kept before it are affected. Cost if wrong: such a card loses its posted key again.
- Ruling: a kept posted URL still hashes as its cleaned form, because `UrlNormalizer::hash()` strips the same key
  again, so Trending groups it with the cleaned page's entries. Accepted: the check protects the link the reader
  clicks, not grouping; no such case is known, and folding it into the hash would need a learned per-host exception.
  Cost if wrong: one card grouped with a page the check called different.
- Measured (dev copy `feedreader_2237_uncleaned`, read-only): the 1,708 link-card rows queued in the week before
  2026-11-15 12:00 hold 1,931 page fetches. 108 of them (5.6 %) carry a stripped key, mostly heise (`wt_mc`, 60),
  Substack (15), mathrubhumi (6), Instagram (4), NYT, Ars Technica (3 each). Only 13 (0.7 %) lost an undocumented
  key and are checked: NYT `smid` (3), YouTube `si` (3), Instagram `rpxt`/`exln`/`srtk` (4), X `s`, Substack `r`,
  beehiiv `_bhlid` (1 each).

### Existing data: `app:urls:clean-tracking`

- **Command:** `App\Command\CleanTrackingUrlsCommand` (`app:urls:clean-tracking`, option `--dry-run`). It is thin: it
  prints the report.
- **Service:** `App\Service\Operations\UrlCleanup\TrackingUrlCleanup`. It owns the walk and the unit of work.
- **Model:** `UrlCleanup/Model/StoredEntryUrlModel` (`id`, `guid`, `url`, `urlHash`) and
  `UrlCleanup/Model/TrackingUrlCleanupReportModel` (entries cleaned, hashes changed, cards cleaned).
- **Implemented differently:** the entry walk reuses `EntryRepository::entriesAfterId()` with a new
  `Entry::relocate()`, and there is no `StoredEntryUrlModel` (ledger ruling: PHPMD TooManyPublicMethods). The card
  side is as below.
- **Repositories:**
  - `EntryRepository::storedUrlsAfterId(int $afterId, int $limit): list<StoredEntryUrlModel>`, scalar, keyset by id;
  - `EntryRepository::relocate(int $entryId, ?string $url, ?string $urlHash): void`, a DQL `UPDATE`;
  - `LinkCardRepository::urlsAfterId(int $afterId, int $limit): array<int, string>` (id ⇒ url);
  - `LinkCardRepository::moveTo(int $cardId, string $url): void`.
- **Per entry:** `$url = TrackingFreeUrl::of($stored->url)` and `$hash = EntryUrlHash::of($stored->guid, $url)`. The
  row is written only when either differs from what is stored. This also fills the 1,458 legacy rows whose `url_hash`
  is `NULL` (R1). `EntryUrlHash` keeps the Bluesky identity hash, which comes from the GUID.
- **Per card:** the URL is written only when `TrackingFreeUrl::of()` changes it.
- **Batching:** a batch is 500 rows (a constructor int, so tests can pass 2). Each batch runs in its own
  `wrapInTransaction`, and the identity map is cleared after it. A second run reports 0 and 0.
- **`--dry-run`:** the same walk and the same counts, with no write.
- **Where it runs:** the dev Docker DB and Strato, by Lars, after the deploy that makes ingest clean. On Strato that
  is a manual step after the dev-tag deploy. The PR body names it.

### What readers see

- **Reader:** "open original" and the article extraction use the cleaned URL. The reader's article cache is keyed by
  URL, so each cleaned old entry misses once and is fetched again.
- **Saved copies:** copied from the entry after ingest, so they are clean. Old saved copies are entries too, so the
  command cleans them.
- **Backups:** an export written after the command carries clean URLs. A restore of an older file cleans on the way in.
- **Search:** neither index (FTS5, Meilisearch) stores the URL. Nothing changes.
- **Trending:** entry hashes change once (R1). Card URLs are hashed at read time today, so the card side changes as
  soon as the code ships.

## Decisions

- **D1. The cleaner is a static `Support/TrackingFreeUrl`, used by `UrlNormalizer` and every writer.** Why: the card
  choke point is static, and one class holding the list and the pair test is the "one cleaner" the issue asks for.
  Cost if wrong: one class to turn into a service later, with its static callers.
- **D2. The list is the vendor-documented keys and prefixes in the table above, plus three per-host rules (YouTube,
  Spotify, NYT).** Why: every key is a fact from its vendor's own page, so it is licence-free; the per-host keys are
  generic elsewhere (`si`, `feature`, `smid`). Cost if wrong: a documented tracker the list lacks stays in a URL. That
  is the status quo, and adding it later is one line.
  Ruling (2026-11-15, coordinator for Lars): the three per-host rules stay. Task 1 had dropped them as undocumented;
  they are restored because Lars asked for cleaning "similar to 'copy clean link' in brave", which strips exactly
  these share keys, and the licence rule forbids copying a list, not stating a single observed fact. Cost if wrong:
  a page that uses `si` or `smid` for content on one of these hosts loses it; none is known.
- **D3. No publisher-internal or undocumented keys** (`mrfcid`, `cmp`, `via`, `traffic_source`, `sara_ref`, Substack
  `r`, Instagram `exln`/`srtk`, X `s`/`t`, `igshid`, `rb_clickid`, `_bhlid`, `sfmc_id`). Why: no vendor documents
  them. A list built from what the dev DB happens to hold is curation from the data, which Lars has ruled out. Cost if
  wrong: Slate's per-share `mrfcid` keeps 331 cards from sharing a hash. #2236/#2239 can still fold them by title.
  Ruling (2026-11-15, Lars, overrules D3 and D4): "strip them. only exception I see: parameters which allow
  paywalled articles to be read (e.g. gifted articles on nyt or 'the atlantic')". The undocumented keys are stripped
  in three tiers (everywhere, host-scoped, value-scoped), and gift keys are never stripped (tables above). Cost if
  wrong: a page that names its content with one of these keys on its host loses it; none is known.
- **D4. No value rule** (`=rss`/`=feed`/`=atom`). Why: it changes 4,866 stored hashes for 2 merges, and its week run
  moved 177 pairs in and 95 out (−29 de–de). The churn comes from grouping order, not from better matches. Cost if
  wrong: CBC, Al Jazeera and Slate outlet URLs keep `cmp=rss`/`traffic_source=rss`/`via=rss`. That is cosmetic, since
  they are constant per feed.
  Ruling: see D3 (Lars overrules D4: `cmp`, `via`, `ref` go when their value is `rss`, `feed` or `atom`).
- **D5. Byte identity when nothing tracks, and no other normalisation of the stored URL.** Why: the command then
  rewrites only rows that carry a tracker, and a reader sees the publisher's own URL. Cost if wrong: none for dedupe,
  which normalises separately.
- **D6. A fragment goes only when every pair in it is tracking.** Why: `#xtor=` (franceinfo, 82 entries) and
  `#Echobox=` (348 cards) are documented trackers; `#:~:text=` and anchors are navigation. Cost if wrong: a mixed
  fragment keeps its tracker.
- **D7. Existing rows are fixed by a command, not a migration.** Why: the cleaner and `EntryUrlHash` are PHP services
  a migration cannot reach safely; the brief rules out SQL behind the running app; and Lars runs it on a copy first.
  The schema does not change, so no Doctrine migration is needed. Cost if wrong: an instance that never runs the
  command keeps old URLs. Dedupe stays correct either way, since new ingest computes the same hash as before for
  every URL the old list already covered.
- **D8. The command also fills `NULL` hashes.** Why: it walks every entry anyway; 1,458 legacy rows have no hash and
  never collapse with their 21 twins. Cost if wrong: those 21 old twins start collapsing in lists. That is the
  intended dedupe.
- **D9. One leading `amp;` is removed from a key before the test.** Why: a feed that double-escapes `&` (Riffreporter,
  251 entries) otherwise hides `utm_*` behind `amp;`. It is structural, not per-host. Cost if wrong: a key genuinely
  named `amp;…` would be tested as its tail. No such key exists.
- **D10. `withoutTracking()` becomes `TrackingFreeUrl::of()` behind a validity check.** Why: one cleaner for the
  shared address too. Cost if wrong: a shared address keeps the host's original case, which every server ignores.
- **D11. Interaction with #2239 and #2236.** `shared_link.url` is `TrackingFreeUrl::of(card url)`, and its key is
  `UrlNormalizer::hash()` of that. #2236 cleans the resolved URL with `TrackingFreeUrl::of()` before storing it. Both
  run after this command, so they backfill from clean cards. Cost if wrong: none here; it is their contract.

## Licences

The list is facts: parameter names, each taken from the documentation of the vendor that defines it (table above).
No list was copied. The following were read for comparison only and are not used: Brave's `brave-core` query filter
(MPL-2.0), ClearURLs rules (LGPL-3.0) and AdGuard's URL-tracking filter (GPL-3.0). The repository stays MIT.

## Measurements (dev MySQL, read-only, 2026-11-15)

Scripts in `scratchpad/2237/` (`measure.php`, `window.php`, `bylang.php`, `run.sh`). The prototype cleaner is
`UrlNormalizer.proto.php`. B1 is the design above; B2 is B1 plus the value rule.

**R1. Stored data.** There are 124,455 entries and 8,147 link cards.

| | B1 (design) | B2 (+ value rule) |
|---|---|---|
| Entry URLs cleaned (all outlet) | 9,558 | 14,172 |
| Entry `url_hash` changes | 252 | 4,866 |
| Entries that become one article (collisions) | 0 | 2 groups (CBC `?cmp=rss` vs its plain URL in another feed) |
| Card URLs cleaned | 1,476 (social 1,471, outlet 5) | 1,481 |
| Card hash changes | 60 | 65 |
| Card hashes that merge | 4 | 4 |
| Cards whose hash matches an outlet entry | 3,632 → 3,638 | 3,632 → 3,648 |

- Entry keys dropped (B1): `at_medium`/`at_campaign` 4,003 (BBC), `utm_source` 3,261, `utm_medium` 2,170, `wt_mc`
  2,002 (heise), `utm_campaign` 1,871, `amp;utm_*` 251 (Riffreporter), `utm_souce` 208 (Statista's typo, caught by
  the prefix), `#xtor` 82 (franceinfo), the BBC `at_*` family 17 each, `smid` 1.
- Card keys dropped (B1): `utm_*` 3,050 pairs, `#Echobox` 348, `wt_mc` 315, `at_*` 202, `si` 31, `smid` 13,
  `feature` 10, `fbclid` 6, `mtm_campaign` 2, `pk_campaign` 2, `adobe_mc` 1, `srsltid` 1.
- The 252 entry hash changes are the `amp;utm_*` rows and one NYT `smid`. The old list already covered every other key.
- **Legacy `NULL` hashes:** 1,458 entries (1,457 RSS, 1 saved), mostly from 2026-08. 21 of them share their hash with
  another entry. One more row holds a raw `sha256(url)`.
- **French and Spanish:** 82 French entries are cleaned (`#xtor=RSS-3-[lestitres]` at franceinfo and France 3
  régions) and 8 French cards. No Spanish entry carries a tracker. 3 Spanish-tagged cards are cleaned
  (comicsands, heise, techcrunch).

**R2. Eye check.** About 110 before/after pairs were checked, one per host, entries and cards (`samples.json`). Each
cleaned URL keeps the path that names the article, and every content key stays:
- `v`, `t`, `list`, `id`, `p`;
- NYT `unlocked_article_code`, Vulture `view_token`, Spiegel `gifttoken`;
- SZ `reduced`, Krautreporter `shared`, CNN `post-id`, Substack `r`.

The tracking-only cases come back as the bare article: BBC, heise, Politico, CBC, franceinfo `#xtor`, YouTube `si`,
fragdenstaat `pk_campaign`, netzpolitik `mtm_campaign`. Text fragments (`#:~:text=`) stay. No URL was fetched; the
judgement rests on the URL structure and the vendor documentation.

**R3. Trending** (kit, pin `2026-11-15 12:00:00`). A is develop. B is the prototype in a scratch copy of the backend,
with outlet entry hashes recomputed at read time, because the DB still holds the old hashes.

| Window | A pairs | B1 net | B2 new / lost / net |
|---|---|---|---|
| day, all | 2,192 | 0 | 0 |
| week, all | 20,134 | +49 (de–de +1, mixed +48, lost 0) | 177 / 95 / +82 (de–de −16, en–en +29, fr–fr +5) |
| French-only day / week | 187 / 224 | 0 / 0 | not run |
| Spanish-only day / week | 29 / 70 | 0 / 0 | not run |

- **B1 week:** one group grows by one member. A German post about the Van Hollen interview joins the 49-member group
  through its cleaned card URL. The join is correct.
- **B2 week:** 30 groups change. The changes include splits that the value rule's hosts cannot explain: German
  bomb-threat groups and the Nvidia group re-partitioning. That is grouping-order churn (D4).
- **Run time and peak memory are unchanged:** day 7.3 s / 163 MB, week 78 s / 300 MB RSS.
- **In the window:** the day window has 1 outlet hash change and 11 card hash changes; the week window has 13 and 46.
  Trending already matches most of these stories by headline, so cleaner URLs add little to it. The gain is in
  stored data, shares and the coming `shared_link` key (#2239).

## Risks

- A vendor page may not name its key exactly. Task 1 checks each one and drops the unconfirmed keys.
- The command rewrites up to about 11,000 rows on dev, and a similar share on Strato. It is batched and idempotent,
  and it runs on a copy first. The reader's cache misses once per cleaned entry.
- A future publisher may use a listed key name for content. That is unlikely for vendor-namespaced keys; the per-host
  rules are the only generic names, and they are scoped.

## Outcome

**Citations (Task 1, 2026-11-15).** Every source in the vendor table was opened on the vendor's own domain; the
table records the page that names each key.

- dropped `s_cid`: Adobe does not document it; its campaign page reads `cid` (experienceleague.adobe.com/en/docs/analytics/implementation/vars/page-vars/campaign)
- dropped `oly_anon_id`: Omeda documents it as a cookie only, never a link parameter (knowledgebase.omeda.com/omedaclientkb/cdp-olytics-overview)
- `si`, `feature` (YouTube), `si` (Spotify), `smid`, `smtyp` (NYT) were dropped as undocumented, then restored by
  the D2 ruling as host-scoped share keys; the per-host table gives each one's source.
- moved: `gclsrc` is not on the Google Ads auto-tagging page; Search Ads 360 "About click IDs" names it, so it stays
  with that citation. `msclkid` is cited from Microsoft Learn, the Microsoft Advertising developer docs, because the
  help.ads.microsoft.com article did not load.


**Measured outcome (Task 5, dev MySQL, 2026-11-15).** Lars ruled the measurement the other way round: `feedreader`
was copied to `feedreader_2237_uncleaned` (kept, uncleaned), and the command ran on `feedreader` itself. The worker
was stopped from the copy to the last B run, so both sides hold the same 124,484 entries and 8,134 cards (row counts
and a CRC over id, url and url_hash matched before the run).

| Step | Entries cleaned | Hashes changed | Cards cleaned | Wall time | Peak RSS |
|---|---|---|---|---|---|
| `--dry-run` | 9,556 | 1,710 | 1,417 | 27 s | – |
| run | 9,556 | 1,710 | 1,417 | 31 s | 209 MB |
| second run | 0 | 0 | 0 | 27 s | – |

- The 1,710 hash changes are the 1,458 legacy `NULL` fills plus 252 from the wider list, as R1 predicted. No entry
  with a URL is left without a hash.
- Cards: 1,417, not R1's 1,476; the 59 are the YouTube/Spotify/NYT share keys this list no longer drops.
- Entries in duplicate hash groups: 6,316 groups / 12,970 entries before, 6,327 / 12,996 after (+11 groups, +26 entries),
  from the `NULL` fills and the `amp;utm_*` rows.
- One stored URL still holds `utm_`: a freitag.de path segment (`…-utm_source-kl2sjq1-1`), which is no query pair.

**Trending** (pin `2026-11-15 12:00:00`; A = develop on the uncleaned copy, B = HEAD on the cleaned `feedreader`):

| Window | A groups / pairs | B groups / pairs | new | lost | net |
|---|---|---|---|---|---|
| day, all | 619 / 2,253 | 619 / 2,253 | 0 | 0 | 0 |
| week, all | 3,820 / 20,155 | 3,820 / 20,155 | 0 | 0 | 0 |
| French-only day / week | 57 / 188, 70 / 225 | same | 0 | 0 | 0 |
| Spanish-only day / week | 20 / 30, 42 / 71 | same | 0 | 0 | 0 |

Every language pair (de–de, en–en, fr–fr, es–es, mixed) is therefore unchanged. Run time and memory are unchanged
(day 7.7 s / 161 MB, week 77.5 s / 296–302 MB RSS). R3's week +49 came from one card joining the Van Hollen group
through its cleaned `youtu.be/…?si=` URL (and a NYT `?smid=` card beside it); with the per-host keys dropped, that
join does not happen. The week window holds 14 entry hash changes, none of which moves a group.

**Eye check.** 20 cleaned URLs drawn at random: 5 French (franceinfo `#xtor=RSS-3-[lestitres]`), 5 German (heise
`wt_mc`, Riffreporter `utm_*&amp;utm_*`), 5 English (Politico Europe, Rest of World, BBC `at_*`), and 8 French- and
Spanish-tagged cards (BBC, heise, comicsands, TechCrunch); no Spanish entry carries a tracker. Each keeps its article
path. Five were fetched live (franceinfo, heise, Riffreporter, Rest of World, comicsands): all answer 200 at the
cleaned URL.

**Re-measured with the per-host keys restored (D2 ruling).**
- On `feedreader` the command, rerun, cleaned 1 more entry, changed 1 more hash and cleaned 52 more cards
  (27 s, 89 MB RSS); a third run reported 0/0/0.
- For a frozen B, a scratch copy of `feedreader_2237_uncleaned` was cleaned by HEAD from scratch (9,557 entries,
  1,711 hashes, 1,471 cards; then 0/0/0), measured, and dropped. It matched the cleaned `feedreader` row for row.
- Trending, A = develop on `feedreader_2237_uncleaned`, B = HEAD on that cleaned copy: day all, French-only and
  Spanish-only day and week are unchanged (new 0, lost 0). Week all: 20,155 → 20,204 pairs, new 49, lost 0, net +49
  (de–de +1, en–en 0, fr–fr 0, es–es 0, mixed +48). The one change is R3's: the German post on the Van Hollen
  interview joins the 49-member group through its cleaned card URL, making it 50. Run time and memory unchanged
  (day 7.7 s / 162 MB, week 77.8 s / 299 MB).

**Re-measured with the D3/D4 ruling (three tiers, gift keys kept).**
- On `feedreader` the rerun cleaned 4,613 more entries, changed 4,613 more hashes and cleaned 374 more cards (53 s,
  187 MB RSS); a second run reported 0/0/0.
- Frozen B (a scratch copy of `feedreader_2237_uncleaned`, cleaned from scratch, then dropped): 14,172 entries, 6,293
  hashes, 1,491 cards; then 0/0/0. It matched the cleaned `feedreader` row for row. Duplicate hash groups:
  6,316 / 12,970 entries before, 6,329 / 13,000 after.
- The newly cleaned rows are English and German only: Al Jazeera `traffic_source=rss`, CBC `cmp=rss`, Slate `via=rss`
  and `mrfcid`, Der Standard `ref=rss`, Spiegel `sara_ref`, Substack `r`, X `s`, Instagram `exln`/`srtk`/`rpxt`. No
  French or Spanish row carries one of the new keys. 20 drawn by eye keep their article path; 5 fetched live answer 200.
- Trending, A = develop on the uncleaned copy, B = HEAD on the cleaned copy:

| Window | de–de | en–en | fr–fr | es–es | mixed | net |
|---|---|---|---|---|---|---|
| day, all | 0 | 0 | 0 | 0 | 0 | 0 |
| week, all | +1 | 0 (2 new, 2 lost) | 0 | 0 | +48 | +49 (51 new, 2 lost) |
| French-only day / week | | | 0 / 0 | | | 0 / 0 |
| Spanish-only day / week | | | | 0 / 0 | | 0 / 0 |

  The week's +49 is the Van Hollen join (D2). The 2 new and 2 lost en–en pairs are one 3-member FAA-outage group
  that swaps its Techmeme post for a second CBC story on the same outage: CBC's `cmp=rss` hashes changed, and the
  grouping order with them. Run time and memory unchanged (day 7.9 s / 161 MB, week 89 s / 283 MB).
