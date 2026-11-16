# Strip only keys that never name content (#2248) — design

## Intent

The #2237 cleaner (`Pass/TrackedUrl`, read by `Support/TrackingFreeUrl` and `UrlNormalizer::hash()`) strips some
keys whose content use is plausible or whose evidence is unclear. A key that names content and is stripped changes
the page a reader opens and merges unrelated pages in Trending. This change narrows the lists to what the audit
(`.superpowers/sdd/2026-11-15-2248-strip-audit/research.md`) found safe, and pins what must stay.

Lars (verbatim, 2026-11-15):
- "Don't strip link_source and taid. Do some research about other parameters which shouldn't be stripped. When in
  doubt: don't strip"
- "Also search the web in your research about parameters which you should not strip"
- "When there is already a link card with the doubtful parameter stripped in the db which carries the same data, you
  can strip"

## Goals

- G1. The prefixes `at_`, `wt_` and `mtm_` are no longer stripped as prefixes. Each vendor's exactly documented keys
  take their place, each with its source (table below). `utm_` stays the only prefix.
- G2. `mkt_tok`, `oly_enc_id`, NYT `smtyp`, `traffic_source` (on every host) and `ref` (as a feed marker) are no
  longer stripped.
- G3. `link_source` and `taid` stay unstripped (no change), and so do the gift-like keys the audit found. A test pins
  every one of them, and the keys this change stops stripping, against every rule on every host.
- G4. The hash and the stored URL keep agreeing: `normalize(clean(u)) === normalize(u)` for every URL (unchanged
  invariant, `testCleaningAgreesWithTheHashAndIsIdempotent`).
- G5. `app:urls:clean-tracking` brings existing data in line with the narrowed lists, including `shared_link.url_hash`,
  which it does not recompute today.
- G6. The Trending effect is measured per language pair (de–de, en–en, fr–fr, es–es, mixed) and in the French-only
  and Spanish-only windows, day and week.

## Non-goals

- **No new stripped key.** This change only removes rules (the exact at_/wt_/mtm_ keys replace prefixes that already
  covered them). The "tracking-like but kept" keys in the research (`a`, `ref_src`, `pp`, `referringSource`, …) stay.
- **No host-scoped `traffic_source`.** See D5.
- **No DB-match rule.** See D8; it is decided by the measurement below.
- **No restore of rows cleaned with the old lists.** See D9.
- **No migration, no schema change, no frontend change, no JSON shape change.** The iOS client sees the same shapes.

## Design

### The lists in `TrackedUrl`

`DOCUMENTED_PREFIXES` becomes one prefix, `utm_` (D1). The keys below join `DOCUMENTED_KEYS`; they are documented,
so stripping one does not trigger the posted-form check (`strippedAnUndocumentedKey()` stays false for them).

| Vendor | Exact keys | Source (page title, URL; opened 2026-11-16) |
|---|---|---|
| Piano Analytics (AT Internet) | `at_medium`, `at_campaign` | "How does the tracking of marketing campaigns work" (docs.piano.io/en/analytics/how-does-the-tracking-of-marketing-campaigns-work) |
| | `at_platform`, `at_creation`, `at_variant`, `at_network`, `at_term` | "Managing sources on Piano Analytics" (docs.piano.io/en/analytics/managing-sources-on-piano-analytics), its example tracking URL |
| Mapp Intelligence (Webtrekk) | `wt_mc`, `wt_cc1` | "Campaigns" (docs.mapp.com/docs/tracking-configuration-campaign), its example campaign URL |
| Matomo | `mtm_campaign`, `mtm_source`, `mtm_medium`, `mtm_keyword`, `mtm_content`, `mtm_cid`, `mtm_group`, `mtm_placement` | "Advanced: How to manually build campaign tracking URLs" (matomo.org/faq/reports/advanced-how-to-manually-build-campaign-tracking-urls/), "all eight campaign tracking parameters" |
| | `mtm_kwd` | "How do I customise the Matomo Campaign parameters?" (matomo.org/faq/faq_120/) |

Removed:

| Rule | List it leaves | Why (research.md) |
|---|---|---|
| prefix `at_`, `wt_`, `mtm_` | `DOCUMENTED_PREFIXES` | A three-letter generic prefix also matches any non-vendor site's own `at_…` key; no other cleaner applies `at_`/`wt_` globally as a prefix. Piano's prefix rule holds only on Piano sites. |
| `mkt_tok` | `DOCUMENTED_KEYS` | Marketo unsubscribe and email web-view pages identify the reader by it (Bugzilla 1774008 c1; Brave keeps it on `unsubscribe`/`emailWebview`). |
| `oly_enc_id` | `DOCUMENTED_KEYS` | An Omeda subscriber identity on gated publishers; AdGuard allowlists it on one host. |
| `traffic_source` | `UNDOCUMENTED_KEYS` | A generic word; only AdGuard strips it, and only on adweek.com. |
| `smtyp` | `KEYS_BY_DOMAIN['nytimes.com']` | No vendor or third-party evidence; 0 rows. |
| `ref` | `FEED_MARKER_KEYS` | The textbook content collision (ClearURLs #353 git refs, #304, #249, #201); a git ref named `feed` is plausible. |

Everything else is unchanged: the `utm_` prefix, every other documented key, `mrfcid`, `sara_ref`, `igshid`, `igsh`,
`_bhlid`, `sfmc_id`, `rb_clickid`, `triedredirect`, `showwelcomeonshare`, the YouTube/Spotify/NYT `smid`/Substack/X/
Instagram host keys, and `cmp`/`via` when the value is `rss`, `feed` or `atom`.

### The never-stripped invariant (`TrackingFreeUrlTest`)

`GIFT_KEYS` / `GIFT_KEYS_BY_DOMAIN` become `NEVER_STRIPPED_KEYS` / `NEVER_STRIPPED_KEYS_BY_DOMAIN`, and
`testNoRuleEverMatchesAGiftKey` becomes `testNoRuleEverMatchesAKeyThatMayNameContent`. The existing harness stays:
each key is tried on `example.com` and on every `KEYS_BY_DOMAIN` host, with the value `rss` (so a feed-marker rule
would match) and with `1`. The lists gain:

- gift-like keys seen in the data (research, "Gift keys"): `freebie` (zeit.de), `shared` (krautreporter.de),
  `view_token` (vulture.com, theverge.com), `reduced` (sueddeutsche.de), `premium` (faz.net), `leadsource`
  (bloomberg.com, beside `accessToken`); on their domain: `reflink` on `wsj.com` (beside `st`), `source` on
  `nytimes.com` (The Athletic's gift link, `source=athletic_user_shared_gift_article_copylink`);
- the ruling's keys: `link_source`, `taid`;
- the keys this change stops stripping: `mkt_tok`, `oly_enc_id`, `traffic_source`, `ref`, and two observed custom
  Piano keys that only the old prefix covered, `at_bbc_team` and `at_specific`; on its domain: `smtyp` on
  `nytimes.com`. A later list change that strips one again fails the test by name.

### Existing data: `app:urls:clean-tracking`

The command already re-cleans every entry URL and recomputes `entry.url_hash` with the current lists. Its card walk
moves `shared_link.url` but never recomputes `shared_link.url_hash`. With the narrowed lists, a link whose stored URL
still carries a now-kept key (Strato: link cards written before #2237, merged into `shared_link` by the #2239
migration under the old lists) holds the old hash, and the next card for the same URL would hash differently and
get a second row. So the card walk learns the hash (D10):

- `SharedLinkRepository::urlsAfterId()` returns `array<int, StoredLinkAddressModel>` (`url`, `urlHash`) instead of
  `array<int, string>`; the model is `Service/Operations/UrlCleanup/Model/StoredLinkAddressModel`.
- `SharedLinkRepository::moveTo(int $linkId, string $url)` becomes `relocate(int $linkId, string $url, string
  $urlHash)`.
- Per link: `$url = TrackingFreeUrl::of($stored->url)`, `$hash = UrlNormalizer::hash($url)`; written only when either
  differs. The report gains `cardHashesChanged`; the command prints
  `%d entries cleaned, %d hashes changed, %d cards cleaned, %d card hashes changed`.
- A recomputed hash cannot collide with another row's: every stored hash came from a list that strips a superset of
  the new one, so two rows with distinct stored hashes keep distinct new hashes, and a new hash that keeps a
  now-kept key never equals a hash of a URL without it.

## Decisions

- **D1. `utm_` is the only prefix; Piano, Mapp and Matomo get their exact documented keys.** Why: Lars's "when in
  doubt: don't strip"; a prefix strips keys no vendor names, and `at_` collides with any site's own key. Cost if
  wrong: an undocumented vendor key (BBC's 11 custom `at_*`, 17 entries; zdfheute `at_specific`, 1) keeps its URL
  apart; measured below.
- **D2. Only keys printed on the vendor's own page count; `wt_ga` and `at_format` are not added.** Why: Mapp shows
  `wt_ga` only as a media-code name in a code sample, and no Piano page reached names `at_format` in a URL. Cost if
  wrong: none measured (0 rows each).
- **D3. `mkt_tok` and `oly_enc_id` are kept, without Brave's unsubscribe condition.** Why: a plain stop is simpler and
  0 stored rows carry either. Cost if wrong: a Marketo or Omeda tracking link stays apart from its plain twin.
- **D4. NYT `smtyp` and the `ref` feed marker are kept.** Why: no evidence for `smtyp` (0 rows); `ref` is a known
  content key. `cmp` and `via` stay feed markers (CBC, Slate; no content use found). Cost if wrong: 5 stored URLs keep
  `?ref=rss`.
- **D5. `traffic_source` is kept on every host; no `aljazeera.com` host rule.** Why: the 2,001 Al Jazeera entries hold
  a constant per-feed value, so keeping it is cosmetic, and a host rule for one site is curation from the data. Cost
  if wrong: Al Jazeera's RSS entries stop matching a share of the plain URL by hash (7 such entries in all stored
  data); title grouping still matches them (0 Trending pairs lost).
- **D6. The never-stripped list pins gift-like keys, the ruling's keys and the removed keys, on every host.** Why: it
  turns each ruling into a failing test the day a list change undoes it. Cost if wrong: none; a key that must be
  stripped one day is taken off the list in the same change, with its ruling.
- **D7. Tests that used `?ref=rss` as "an undocumented key the cleaner strips" switch to `?cmp=rss`.** Why: they test
  the posted-form check, not `ref`; `cmp=rss` is the same tier. Cost if wrong: none.
- **D8. The DB-match rule is _out_.** Why: measured below — the narrowed lists lose **0** grouped pairs in every window (day, week, French-only, Spanish-only; every language pair), and over all stored data a card-side rule could fold **1** link (zdfheute `at_specific`, of the 2 links whose hash changes). The rule is order-dependent (it folds
  only when the key-free row was stored first) and adds a fourth source of URL identity. Cost if wrong: those merges
  stay lost until a later issue builds it; research.md holds the sketch.
- **D9. Nothing is restored on localhost.** Rows already cleaned with the old lists keep their stripped form: their
  stored `url_hash` is the hash of the stripped URL, which the narrowed lists compute identically, so URL and hash
  still agree and the command finds nothing to do there (the plan's measurement task confirms it with `--dry-run` on `feedreader`, expected 0 everywhere). Restoring the
  posted forms from `feedreader_2237_uncleaned` would need a one-off writer for about 2,000 rows whose stripped form
  the audit found to be the same page (canonical drops the key; every pair matched). Cost if wrong: dev keeps
  stripped Al Jazeera/BBC/derstandard URLs; new ingest stores them with the key, so a feed mixes both forms (guid
  dedupe is unaffected).
- **D10. The cleanup recomputes `shared_link.url_hash`.** Why: Strato's links were hashed with the old lists; without
  it a kept-key URL splits into two rows on its next card. Cost if wrong: one more UPDATE column; idempotent.
- **D11. Strato order is unchanged:** deploy, then `app:urls:clean-tracking --dry-run`, then the run — now with the
  narrowed lists. The PR body names it.

## Measurements (dev MySQL, read-only on `feedreader`, 2026-11-16)

Method: `feedreader_2237_uncleaned` (the pre-cleanup snapshot, pin `2026-11-15 12:00:00`) is copied twice, to
`feedreader_2248_a` and `feedreader_2248_b`. Each copy is migrated to the current schema by its own code (the #2239
migration hashes links with the running cleaner) and cleaned by its own `app:urls:clean-tracking`: A = develop
290990c6f, B = the narrowed lists. Then `app:trending:explain` runs on each, day and week, all languages and
French-only / Spanish-only. Scripts: `scratchpad/2248/` (`run.sh`, `pairs-lang.py`, `stored.sql`).

**Stored data** (124,484 entries, 5,944 shared links on each copy):

| | A (develop) | B (narrowed) |
|---|---|---|
| `app:urls:clean-tracking` report | 14,172 entries cleaned, 6,293 hashes changed, 898 cards cleaned | 12,171 / 4,309 / 896 |
| wall time / peak RSS | 649 s / 246 MB | 363 s / 224 MB |
| `shared_link` rows after the #2239 migration | 5,944 | 5,944 |

- Entries whose URL and `url_hash` differ between A and B: **2,018**, all English: `traffic_source=rss` 2,001 (Al
  Jazeera) and BBC iPlayer's custom `at_*` set 17 (`at_mid`, `at_campaign_type`, `at_nation`, `at_brand`,
  `at_link_title`, `at_bbc_team`, …). 79 of them fall in the day window, 628 in the week window.
- Links whose hash differs: **2** (zdfheute `?at_specific=ZDFheute`, derstandard `?ref=rss`); one more link keeps a
  `#ref=rss` fragment (Spiegel), which A dropped as a tracking fragment. No French or Spanish row changes.
- Merges a DB-match rule could recover: 1 link (its key-free twin is stored); 7 Al Jazeera entries have a key-free
  twin, but entries have no card at hand, so the sketched rule would not fold them (research.md).

**Trending** (pin `2026-11-15 12:00:00`):

| Window | A groups / pairs | B groups / pairs | new | lost | net |
|---|---|---|---|---|---|
| day, all | 619 / 2,253 | 619 / 2,253 | 0 | 0 | 0 |
| week, all | 3,820 / 20,236 | 3,820 / 20,236 | 0 | 0 | 0 |
| French-only day / week | 57 / 188, 70 / 225 | same | 0 | 0 | 0 |
| Spanish-only day / week | 20 / 30, 42 / 71 | same | 0 | 0 | 0 |

Every language pair (de–de, en–en, fr–fr, es–es, mixed) is therefore unchanged: title grouping already matches the
628 week-window entries whose hash changes. Run time and memory are unchanged (day 5.3 s / 99 MB, week 76 s /
255–262 MB RSS).

## Risks

- A later list change can reintroduce a removed key; the never-stripped test names it.
- The cleanup's card rehash is new: a collision is impossible by the superset argument above, but a stored hash from a
  list that strips *less* than the new one (no such list ever shipped with `shared_link`) would break it.
- Al Jazeera, BBC custom-key and derstandard URLs keep their keys from now on, so a reader sees the tracker in the
  URL; cosmetic.
- The posted-form check now fires for fewer links (no `traffic_source`, no `ref=rss`), which only removes fetches.
