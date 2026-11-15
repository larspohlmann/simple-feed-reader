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

**A feed's language is guessed once and saved; social timelines guess per entry** (Lars, 2026-11-14). This
replaces the first ruling and a short-lived build-time guess.

- **New feed:** when a feed has no language, the first refresh sets one.
  - A declared language wins.
  - Otherwise, the detector's best guess over all the parsed entries (headlines plus full plain summaries), with no
    confidence floor.
  - Only text with no letters leaves it empty.
- **Later refreshes** never change a feed language that is already set. Re-detection on every refresh is gone, and so
  is "detection may overrule a declared language". A wrong declaration is fixed by hand.
- **Edit:** any subscriber can set the feed's language in the edit-subscription dialog: one of the four, or
  "Automatic", which clears it so the next refresh guesses again.
  - The language belongs to the feed, so the change applies to every subscriber.
  - Trending builds one snapshot over all feeds, so a per-user value could not steer it.
- **Header:** the list view header for a single feed shows its language name. It is hidden when there is none.
- **Social timelines** (`SourceFormat::SOCIAL`) get no feed language and no editor.
  - Each of their entries stores its own best guess at ingest (`entry.language`), from its headline and plain text.
  - Trending reads `entry.language` first, then `feed.language`.
- **No backfill.** The column is unreleased: existing feeds are guessed on their next refresh.
- **German compounds** that the six-character cut used to join (*Dreiertreffen / Dreiergipfel*) are measured after
  this change. If they matter, they get an issue of their own.

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

## Outcome

Measured 2026-11-14/15 on the dev stack, as "Success measure" asks. Baseline is this branch with `feed.language` and
`entry.language` both NULL, so every entry takes the six-character cut. Branch is the same code with both columns
filled by the branch's own rules: `FeedLanguageResolver` over each feed's stored last 20 entries plus its declared tag,
and `SocialEntryLanguage` over each stored social entry. The two runs went back to back on the same data.

The measurement validates the mechanism only. **No table was curated from it** (Lars, 2026-11-15): a week's news says
little about the news in three months. Two edits tried during the run were reverted or never committed (see Rulings).

### Coverage, pairs, cost

| | Day base | Day branch | Week base | Week branch |
|---|---|---|---|---|
| Entries | 4,284 | 4,284 | 28,709 | 28,714 |
| Languages | all none | de 1,320, en 2,270, fr 307, es 314, none 73 | all none | de 11,027, en 16,354, fr 408, es 602, none 323 |
| Coverage | – | 98.3 % | – | 98.9 % |
| Groups | 561 | 562 | 3,735 | 3,686 |
| Grouped pairs | 1,721 | 1,719 (new 160, lost 162, **net −2**) | 15,889 | 16,355 (new 2,629, lost 2,163, **net +466**) |
| Wall time | 2.1 s | 4.2 s | 57 s | 75 s |
| Peak RSS | 86.7 MB | 87.1 MB | 234.5 MB | 237.5 MB |

Four runs over the same evening show how much the window drifts. Day net was +51, +44, +31 and −2; week net was +584,
+617, +625 and +466. **The week is a clear net gain. The day is about even.** Task 6's first run, before the per-entry
and once-guessed languages, was −223 (day) and −2,029 (week).

The language boundary is gone as a loss. Pairs with a language-less member lost 7 and gained 7 over the week (it was
about 1,800 lost against 160 gained). Cross-language pairs, such as de–en, lost 128 and gained 15 over the week, and
lost 12 and gained 4 over the day. German within German is still net negative: lost 741 and gained 541 over the week,
lost 81 and gained 40 over the day. English is net positive: en–en and en–social lost 1,191 and gained 1,967 over the
week.

### Eye check

The largest new and grown groups are real stories joined more completely. Examples: the FAA ground stop (33 → 39), the
Scott Colom win, the Nvidia/Jensen Huang affair and resignation, the Hegseth Tomb incident, Mecklenburg-Vorpommern's
coalition, Aigner for Bundespräsidentin, the Brooklyn collapse, and Italy fining Meta.

Wrong merges found:
- *Norway v Wales: Nations League – live* joined *Portugal vs Denmark LIVE: UEFA Nations League* (day).
- heise's *Hier ein paar zuletzt häufig geteilte #News:* digest post joined the Microsoft outage story (day and week).

The largest splits are real stories losing members:
- *Dreiertreffen / Dreiergipfel* (8 → 5 + 3)
- *Landesparteitag / Landesspitze* for BSW Sachsen-Anhalt (8 → 6 + 1 + 1)
- *Trauerakt / Trauerstaatsakt / Trauerfeier* for Aiwanger
- *Kandidatur / kandidiert* for Heil (13 → 11 + 2)
- *Ukraine-Verhandlungen / -Gespräche* in Abu Dhabi (14 → 5 + 5 + 3 + …)
- *Stacy Keach / Mike Hammer*, which splits into an English and a German group

### What the losses are

For each lost week pair, the classifier looked at the words that shared a cut stem under the baseline and share no
stem under the branch, and classified them by a heuristic (`t6e-bridges.php` in the task scratchpad; the labels are
approximate). The counts below are pairs, and one pair can fall in more than one class. Of the 2,163 lost pairs:
- 1,331 (62 %) have no lost shared word. They split through transitivity (a group is a connected component, so one
  missing link drops many pairs) or through shifted weights: `deutsch` and `deutschland` were one window-filler stem
  under the cut and are now two linking stems below the 3 % line.
- 705 pairs have only structural bridges.
- 66 pairs have only news-specific bridges.
- 61 pairs have both.

| Class | Kind | Week pairs | Examples |
|---|---|---|---|
| Inflection or derivation the stemmer splits (mostly German verb *-t*, English derivations) | structural | 306 + 102 | *national/nationwide* 87, *leader/leadership* 32, *verhandeln/verhandelt/Verhandlungen* 23, *nominiert/Nominierung*, *kündigt/kündigen* |
| German compounds | structural | 255 (day 35) | *Spionage/Spionageverdacht* 12, *Polizei/Polizeieinsatz* 10, *Sonntagmittag/-vormittag* 8, *Cyberangriff/Cyberattacke* 7, *Regierung/Regierungsbildung* 6, *Landesparteitag/Landesspitze* 5, *Dreiergipfel/Dreiertreffen* 4 |
| A name stemmed by two languages' stemmers | news-specific | 104 | *Mike/Stacy (Keach)* 24/20, *Manchester United* 10, *Booker* 9, *Wenger* 6 |
| Same ordinary word, two stemmers (de vs en) | structural | 77 | *Hammer* 24, *Prize*, *Fridays* |
| Demonym and country, as a class | structural | 66 | *Australia/Australian* 13, *Europe/European* 8, *Ukraine/Ukrainian* 6, *Russia/Russian* 5, *Europas/europäische* |
| A name in two spellings | news-specific | 34 | mostly misfiled (*democrats/democracy*); real transliteration losses (*Selenskyj/Selenskij*) are about 0 |
| Function word that the branch now seeds | structural, a false link removed | 29 | *sollen*, *mehrere*, *seiner*, *ihre*, *wurden* |
| Cognates across languages | structural | 23 | *humanitaire/humanitarian*, *immédiat/immediate* |

**German compounds cost about 255 week pairs and 35 day pairs** (heuristic, upper bound: some derivations such as
*Kandidaten/kandidiert* fall in this class). The worst cases are listed above. No compound splitting was built.

The name-spelling loss is small. Transliterations that the cut joined share six letters, and few did. The real name
loss is one name stemmed by two stemmers (*Hammer* → de `hamm`, en `hammer`), at about 100–130 week pairs.

The top shared stems that are still no seed are function words: de *was, doch, nun, ich, uns, kein, muss, war, viel,
wieder, gibt, könnte*; en *now, one, here, back, like, get, make*; fr *son, été, avoir*; es *han, hace, solo, durante,
dos, puede*. Others are web boilerplate: *www, https, com, ots, lesen*. And de `and` (*andere*) collides with English
*and*.

### Sources that do not depend on the news (proposals, not built)

- **Function words:** the Snowball project's stopword lists for de/en/fr/es.
  - Replace `HeadlineFiller::SEED_WORDS` and the cut `SEED_STEMS`.
  - They cover the function words above.
- **German compounds and verb forms:** a general German decompounder and lemmatizer driven by a dictionary, not by
  feeds, such as the LibreOffice/Hunspell `de_DE` dictionary with its compound flags, or a lemma list.
  - This adds the head and modifier stems; no table is replaced.
  - Estimated gain: up to the about 255 compound pairs, plus part of the 306 inflection pairs.
- **Demonyms, countries and capitals:** CLDR's territory names for de/en/fr/es, Wikidata's demonym property (P1549),
  and the names of capitals in each language.
  - Replace the country and capital entries of `CUT_STEMS` and `STEMS_BY_LANGUAGE`, the country entries of
    `EXACT_WORDS` (*Schweiz, Austria, Niederlande, Großbritannien, Francia*), and the country phrases.
  - An uncommitted trial of English and German demonym pairs, done one by one, measured day −8 / week +121 on the same
    data. That is the order of gain a class-wide source would give.
- **Names across stemmers and in other spellings:** a general rule applied to every word.
  - A proper-noun guard: a word written capitalised mid-sentence in an en/fr/es entry keeps its bare form instead of a
    stem.
  - A transliteration fold on top of it (z/s, w/v, ck/k, and final -yj/-yy/-ij/-iy/-i). The fold would replace every
    Zelensky entry.
  - Estimated gain: about 100–130 week pairs for the guard. The fold gains little against the baseline, because the
    cut never joined those spellings, so its gain beyond the baseline is unmeasured.

### The #2225 tables

These entries are news-specific (a person or an event):
- every Zelensky spelling: `zelens` in `CUT_STEMS`, de `selenskyj`, en `zelenski`/`zelenskyy`, fr `zelensky`,
  es `zelenski`/`zelensky`;
- `WM` and de `weltmeisterschaft`;
- arguably `kreml`/`kremlin`.

The rest is geography (countries, capitals, the EU and the UN) and general by nature, though chosen from one week's
collisions. A CLDR-style source would fill it. The exact words *Schweiz, Austria, Niederlande, Großbritannien* are not
made redundant by Snowball: they run before stemming in every language and carry the cross-language join.

### Rulings during the measurement

- A German `zelensky` entry was committed, then reverted. Per-name spelling entries do not scale, and no test may pin
  one name's spellings (Lars).
- The within-language demonym joins that were tried were never committed. Curation from the current window was dropped
  altogether (Lars): "you are using todays data for testing. It might look completely different in 3 months".
- No seeds, exact words or compounds were changed.

### Limitation

Stored entries do not keep the post's stated language (Bluesky `langs`, Mastodon `language`, Lemmy `language_id`). The
fill therefore fell back to `LanguageGuess` for every social entry, whereas live ingest prefers the stated language
(`SocialEntryLanguage`). Some guesses were wrong; English Bluesky posts tagged `fr` show up in the Eurovision group,
for example. Posts were not fetched again. New social entries ingested by the running develop app during the runs
carry no language, so the numbers drift by a few pairs from run to run.

### French and Spanish (#2235)

Measured on 2026-11-15 (pin 12:00 UTC, dev MySQL read-only), A = a0f702721 (six-character cut), B = develop 3cca6677f;
tables in `2026-11-15-2235-trending-french-spanish-design.md`.

- **French-only window:** day −6 net (13 new, 19 lost), week +3 (22 / 19). **Spanish-only:** day +4 (5 / 1), week 0
  (8 / 8). Every changed group is a reshuffle of real stories; one wrong French merge is undone; the Spanish losses
  are the Snowball seeds #2231 records (*habrá*, *estados*).
- **The dev tags above are the fill's.** The *Limitation* was measured on social tags the fill wrote with the
  four-language detector: 30 % of `fr` and 24 % of `es` tags are right by eye. They are not today's rule.
- **Today's guess:** below 150 characters fr 8 of 28 and es 3 of 24 right (de and en 40 of 40 sampled); at 150 and
  above fr 23 of 24, es 21 of 24. No French or Spanish text was found tagged en or de; all 8 fr/es feeds are right.
- **Cost:** the wrong fr/es tags move no fr–fr or es–es pair (0 / 0 on both single-language windows); the posts they
  sit on link to nothing. No guess below 150 characters (G1) loses 102 day pairs, nearly all en–en; a short guess the
  bundled stopwords must confirm (G2) ties (+1 day, −6 week). No rule change (#2235 D1, D2).
- **English side effect:** today's detector gives about 359 short English posts no language (it ranks Interlingua or
  Occitan first) where the fill had `en`: −255 week pairs against the fill tags, −1 day. Recorded in #2235 D6, followed up in #2243.
