# Trending: language-aware stemming (#2227)

## Intent

Trending links entries into stories by the stems their headlines and leads share. Today every word is cut to its
first six characters (`HeadlineStems`). The cut keeps inflections together (*Ukraine / ukrainische* → `ukrain`), but it
also folds unrelated words into one stem: *Bundestag / Bundesliga / Bundesregierung* → `bundes`, *Schweiz / schweigen*,
*Niederlande / Niederlage*, *Untersuchung / unterstützen*. Those false stems link unrelated entries and count as more
common than any of their words, which weakens the real links. #2225 patched a few with exact-word entries.

A longer blind cut is not the fix: measured on 2026-11-14, a cut of 8 gains 112 grouped pairs over a day but loses
1,316 over a week, because inflections stop matching and real stories split. The fix is a real stemmer for each of the
app's four languages (DE/EN/FR/ES, #2205), so inflections stay together and unrelated words stay apart.

## Goals

- Stem each entry's words with the Snowball stemmer for its feed's language.
- Know each feed's language: declared by the feed where it says so, detected from its entries where it does not.
- Keep the #2225 equivalences (cross-language names, phrases, exact words) working on the new stems.
- A feed whose language is unknown or outside the four behaves exactly as today (six-character cut).

## Non-goals

- Per-entry language. A feed is one language for this purpose.
- Languages beyond DE/EN/FR/ES.
- Any change to weighting, filler shares, link thresholds or the reader-facing Trending API.
- Showing the feed language anywhere in the UI or API.
- Per-spelling document frequency (the rarity dilution #2225 recorded) — still open, separate.

## Anti-goals

- Trending getting worse over the week window. The six-character experiment gained a day and lost a week; this change
  must not repeat that. A regression in grouped pairs that eye-checking shows to be real stories splitting blocks the PR.
- A wrongly declared language silently degrading a feed (see "Declared vs detected" below).

## Constraints

- Libraries, not a handrolled stemmer: `wamania/php-stemmer` v4 (MIT, Snowball, depends on `joomla/string`) and
  `patrickschur/language-detection` v5 (MIT). Both installed and ran on PHP 8.5 with no deprecation output (spike,
  2026-11-14).
- Lands after #2225 (merged) and before #2128, for the same reason: a grouping change after stable story identity
  regroups live stories.
- Build time is not a constraint (worker rebuild only); reader requests are untouched. Ingest time is: detection runs on
  refresh, so it must stay cheap (one detection over at most a few kilobytes of text per feed per refresh).
- A new column on `feed` needs a migration for SQLite and MySQL; the CI migrate-from-empty leg covers it. #2218
  (open, in the main checkout) adds migrations up to `Version20261114160000`; this one takes a later version so the
  two merge in either order. Until #2218 merges, measuring on the dev database needs the temporary `case Lemmy` in
  `SocialNetwork` (and now `SourceFormat::LEMMY_API`), as for #2225.
- House rules (CLAUDE.md): the enum the entity stores lives in `App\Enum`; queries stay in `src/Repository`; tuned
  verdicts are injected services, pure helpers are `Support/`.

## Approaches considered

Where the language comes from (Lars chose 3):

1. Detect per entry from headline + lead — no schema change, but the spike showed headline detection is unreliable
   (*"Selenskyj trifft Macron in Paris"* → English; the Spanish headline → French).
2. As 1, falling back to the six-character cut when unsure.
3. **Store a language per feed** — one feed is almost always one language, and many entries together are a far
   steadier signal than one headline. Costs a column, a migration and an ingest change.

How a feed gets its language (Lars chose 3):

1. Declared only (`<language>`, `xml:lang`, `dc:language`) — exact, but many feeds declare nothing.
2. Detected only, over the fetched document's entries — covers every feed, ignores what the publisher states.
3. **Declared first, detection as the fallback**; neither confident → no language → six-character cut.

## Design

### Feed language

- New backed enum `App\Enum\FeedLanguage` with cases for `de`, `en`, `fr`, `es`, and a nullable `feed.language`
  column (`length: 2`, like `FeedPlatform`'s pattern: `Feed::recordLanguage(?FeedLanguage)`, `getLanguage()`).
- The parsers carry the declared language on `ParsedFeedModel` as a raw string (`?string $declaredLanguage`):
  RSS 2.0 `<channel><language>`, RSS 1.0 `dc:language` on the channel, Atom `xml:lang` on the root `<feed>`.
- `EntryIngestor::updateFeedMetadata` records the language on every successful parse, through an injected service in
  `Service/Ingestion/…` (the verdict is tuned, so a service, not a static helper):
  - Normalise the declared value: lowercase, primary subtag (`de-AT` → `de`, `en_US` → `en`). One of the four → use it.
  - Otherwise detect over the parsed document's entries (titles and plain-text summaries, capped to a few KB),
    restricted to the four languages. Use the result only when it is confident (top score above a floor and ahead of
    the runner-up by a margin — values calibrated by the builder on the dev feeds, recorded in the code's constants).
  - Neither → no verdict.
- Every refresh re-evaluates, so a feed that changes language or starts declaring one follows. **No verdict keeps the
  stored language** (my call): only a declared or confidently detected language overwrites it. Otherwise a small or
  empty page (a first fetch capped to the newest entries, a refresh with one new post) would flip the feed to `null`
  and back, and its stories would regroup from one rebuild to the next — the instability this lands before #2128 to
  avoid. A feed declaring a language outside the four (e.g. `it`) with no confident detection does record `null`.
- **Social timeline feeds get no language** (Lars, 2026-11-14): their timelines mix languages and arrive in small
  pages, so they keep the six-character cut, exactly as today. The test is the feed's source format, in
  `SourceFormat::SOCIAL` (the network API timelines and searches), **not** `Feed::getNetwork() !== null`: the parallel
  #2218 part 2 (`docs/superpowers/specs/2026-11-14-2218-lemmy-feed-network-design.md`, not yet merged) sets
  `network = lemmy` on plain-RSS Lemmy community feeds, as Mastodon profile RSS feeds already carry `network =
  mastodon`. Those stay RSS, are one community or one person, and go through declared/detected like any feed; a
  mixed-language profile simply gets no confident verdict. Keying on `SourceFormat::SOCIAL` picks up `LEMMY_API`
  automatically once #2218 lands.
- The WordPress JSON, Mixcloud and scraped-HTML parsers declare nothing; they rely on detection (my call).
- No backfill: existing feeds get their language on their next refresh. Dev and Strato refresh continuously.

### Declared vs detected

A declared language can be wrong (a CMS left on its `en-US` default while publishing German). **Detection may overrule
the declaration** (Lars, 2026-11-14): when the declared language is one of the four, detection still runs over the
document; if it is confident and disagrees, detection wins. A declared language outside the four gives `null` unless
detection confidently names one of the four. Detection therefore runs on every refresh of every non-social feed — a few
milliseconds over a few KB of text.

### Feeds with and without a language

Stems share one id space, so a German news feed's *Bundestag* (`bundestag`) no longer matches a language-less feed's
`bundes`: links across that boundary (mostly social posts to news) are lost on words longer than six letters whose
Snowball stem differs from their first six letters. The first ruling was to accept and measure the loss, with no
bridge yet (Lars, 2026-11-14). The measurement failed that ruling. Over the week, about 1,800 grouped pairs were lost
across the boundary against 160 gained, and in total 15,852 pairs fell to 13,823. Setting every language-less feed to
`en` turned the result into a net gain.

**Every entry is stemmed** (Lars, 2026-11-14, replacing the first ruling). When an entry's feed has no language,
Trending guesses the entry's own language at build time from its headline and plain summary. The guess is always the
detector's top language among the four, even when it is not confident, because a wrong guess costs little: names stem
the same in every language. This applies to social timeline entries too. The feed column still stays empty for them,
since their feeds mix languages. Only an entry with no letters to detect keeps the six-character cut. The guess is not
stored, which costs about 0.16 ms per entry per run. German compounds that the cut used to join (*Dreiertreffen /
Dreiergipfel*) are measured after this change and get an issue of their own if they matter.

### Stemming in Trending

- `TrendingEntryRepository` selects `feed.language` with each window entry; `WindowEntryModel` gains
  `?FeedLanguage $language`. `HeadlineWindow::add` passes it to `HeadlineEquivalents::stemsOf($text, $language)`.
- Pipeline (unchanged steps 1–3 from #2225):
  1. exact words on the raw text;
  2. words: casefold and split as today — **but diacritics are stripped after stemming**, not before, because the
     Snowball German, French and Spanish stemmers read *ä, é, ñ*; folding first changes their output;
  3. phrase collapse;
  4. per word: with a language, the Snowball stemmer for it, then strip diacritics; without, the six-character cut;
  5. the equivalence map for that language (below);
  6. distinct.
- Minimum word length and the number rule stay as they are.
- The stemmers are built once per process (an injected service in the Trending module wrapping
  `StemmerFactory::create` per language), not per word.
- Stems from different languages share one id space, as today: a name stems the same in every language (*macron*),
  and the equivalence map joins the spellings that do not (*ukrain / ukrainian / ucranian*).

### Re-curating the #2225 tables

- `STEMS` becomes one map per language, keyed by that language's Snowball stem, plus the existing six-character map for
  feeds without a language. A flat map across languages would let one language's stem catch another's word (French
  *Russie* stems to `russ`, which is also an English name).
- `EXACT_WORDS` and `PHRASES` run before stemming and stay language-independent. Exact-word entries that only existed to
  dodge a six-character collision (*Schweiz*, *Austria*, *Großbritannien*, *Niederlande*) may move into the per-language
  maps where Snowball already keeps them apart; the builder decides each by the window's words.
- `HeadlineFiller::SEED_STEMS` becomes per language too (each language's seed words in its Snowball form), plus the
  current six-character set for feeds without a language. A per-language set can hold a word that names something in
  another language (*con*, *plus*, *contra* were dropped from the flat set in #2225 for that reason) without hitting it.
  `isSeed` takes the language.
- Canonical values stay opaque ids that are no real stem in any language; the #2225 invariant tests extend to every map.
- New demonym/adjective pairs Snowball keeps apart (*Ukraine / Ukrainian*, *Rusia / ruso*, *Russie / russe* already
  joins) get entries where measuring shows they matter.

### Explain command

`app:trending:explain` shows, for the run, how many window entries came from feeds with each language and how many
from feeds without one, so the measurement can see coverage.

## Success measure

Same method as #2225: on the dev stack, develop and branch back to back on the same data, `app:trending:explain` for
day and week:

- grouped pairs gained and lost; every new, grown or split group checked by eye for wrong merges and lost stories;
- run time and peak memory;
- language coverage: share of window entries whose feed has a language (before measuring, migrate the dev database and
  let the dev worker refresh every feed once so the column is filled);
- links lost across the language boundary: develop-grouped pairs with one member from a feed with a language and one
  without that the branch no longer groups.

The week window must not lose real stories net (anti-goal above).

## Left to the builder

- Detection confidence floor and margin, and the text cap, calibrated on the dev feeds; record the coverage they give.
- Which #2225 exact words move into per-language maps.
- Class names and module placement within the house rules.
- Whether the parser reads `xml:lang` on `<channel>`/`<feed>` only or also falls back to the document root attribute.
- Where the social-feed exclusion sits (the language service or the ingest call site).
