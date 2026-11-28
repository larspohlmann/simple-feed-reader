# Your stream — design

Date: 2026-11-28. Status: draft for Lars's approval (builder check done).

## Intent

The For you stream (#2582, settings #2594) only starts once an AI connection has produced a profile and a
second AI call has turned that profile into weighted phrases. Lars wants the stream **independent from AI**:
no connection, no profile and no AI call needed. The word list comes **straight from the articles the
reader has marked as interesting**. The stream is renamed **Your stream** and gets **its own settings
section**, out of Settings → AI.

## Goals

- Your stream works for every account, with or without an AI connection.
- The word list is built locally from the reader's **favourites, kept, liked and shared** articles plus
  their **saved-search terms** (Lars: no viewed). Saved searches are my call: the reader typed them, so
  they are the clearest interest signal there is.
- The stream is called **Your stream** everywhere the reader sees it: the sidebar, the list heading, empty
  states, settings, and all four languages.
- Its own settings section, **Settings → Your stream**, holds the on/off switch, precision and the
  read-only word list that today sit in the AI → Recommendations card.
- Matching, scoring, the sweep, reading, mark-read and the sidebar count behave as they do today.

## Non-goals

- No change to the AI recommendations (For you), their profile run or their settings, apart from removing
  the stream parts.
- No editing of the word list.
- No rescoring or backfill when the list or precision changes. The #2582 rule stands: a change only
  affects entries swept afterwards.
- No keeping the AI term path as an alternative source. It is removed.

## Anti-goals

- A word list full of generic news words ("government", "people"). The point of the list is what sets
  this reader apart from the feeds they read.
- A list that only refreshes once a day: a new favourite should be reflected in the list within about an
  hour.
- Anything in the new module reaching into `Curation/Recommendation` or `Curation/Ai`.

## Approaches (word source)

- **A. Words that stand out in your articles (picked, Lars).** Stem the headline and text of the interest
  articles. A stem ranks high when it shows up far more often there than in the reader's feeds overall
  (the 30-day background the rarity count already builds). The top stems per language become the list.
  Local, cheap, reuses the stemming, stop words and rarity code.
- **B. Headline names only.** Capitalised words and two-word phrases from headlines. A cleaner list, but
  it misses topics that live in the text and is thin in languages that don't capitalise nouns, so the
  stream falls silent. Rejected.
- **C. No word list: compare each new entry with the interest articles directly.** Possibly more accurate,
  but much heavier per entry, and there is no list to show in settings. Rejected.

## Decided design

### 1. Its own module, named for what it is

- The stream leaves `Service/Curation/Recommendation/Stream` for a new module, **`Service/Curation/YourStream`**
  (my call). It depends on `Foundation/Stemming`, `Foundation/Text` and the repositories, never on
  `Curation/Recommendation` or `Curation/Ai`. Add that boundary to `ServiceModuleBoundaryRule` only if Lars
  asks: the memory says no PHPStan rule edits unasked.
- The code is renamed with it (my call): `ForYouStream*` → `YourStream*`, `forYouStream` JSON keys →
  `yourStream`, the `/api/entries/for-you-stream` routes → `/api/entries/your-stream`, and the matching
  frontend names (`for-you-stream-group` and friends). A single name in code and UI avoids a permanent
  "For you stream means Your stream" translation in every reader's head.
- The stream's data is develop/Strato-only (#2582 shipped 2026-11-27), so tables and columns can be renamed
  or rebuilt freely. Membership rows stay (the reader's stream is kept). The AI term sets, their phrases
  and the term-run records are dropped.

### 2. Settings move off `RecommendationSettings`

- A new per-user settings row (e.g. `YourStreamSettings`: user, enabled, precision) replaces
  `forYouStreamEnabled` and the precision column on `RecommendationSettings`. Precision keeps the
  `ForYouStreamPrecision` steps and thresholds (renamed); default step 4, minimum score 1.5.
- The migration carries an existing `for_you_stream_enabled = false` over as off (my call: it is a choice
  someone made). Every other account starts **on** (my call). Without AI there is nothing to set up, and an account with
  no interest articles has no word list yet, so it stays hidden from the sidebar anyway.
- API: `GET /api/me/your-stream` answers `{enabled, precision, words}`; `PUT /api/me/your-stream` takes
  `{enabled, precision}` (both required) and answers with the GET body. `GET/PUT /api/me/ai/recommendations`
  lose the stream settings, the word list and the `/for-you-stream` sub-resource. JSON in, JSON out,
  problem+json errors, bearer auth: fits the iOS constraint (run the architecture §6 checklist).
- Frontend: a new `SETTINGS_SECTIONS` entry `your-stream` placed directly before `ai` (my call), with its
  own route and component holding the switch with its info tip, the precision stepper and the word list.
  The For you stream group leaves the recommendation settings card. The info tip text is rewritten: the
  list comes from your favourite, kept, liked and shared articles and your saved searches; words rare in
  your feeds count more; changes only affect entries that arrive afterwards.

### 2a. Its own inclusion switch (Lars)

- A new `InclusionSurface::YourStream` case (`your-stream`, column `include_in_your_stream`) on subscriptions
  and tags, beside All items, For you, saved searches and Trending, following that existing pattern.
  Everything the stream reads (candidates, the 30-day background, feed languages, the interest articles)
  is restricted by it instead of `InclusionSurface::ForYou`.
- It gets its own network exclusions, like For you (`hasNetworkExclusions()` true).
- The migration copies each subscription's and tag's current For you value into the new column (Lars),
  and the For you network exclusions into the new surface, so nothing changes for the reader on day one.
- The switch appears wherever the other surface switches do: the edit-feed and tag dialogs, bulk edit,
  organise, visibility, the network-exclusions endpoint, backup lines (`SubscriptionLine`, `TagLine`,
  `AccountLine`, with an older backup restoring the new switch from its For you value, my call), and the copy in all four languages.

### 3. Building the word list

- **Inputs.** The newest entries in each section the reader's history already defines in
  `ReadingHistoryRepository` (favourites, kept, liked, shared, each entry only in its highest section),
  capped by a constant per section (suggest 200; independent of the AI history caps), plus the
  saved-search terms. Only articles from the stream's own candidate feeds count (my call): a favourite
  from a feed the reader kept out of the stream would otherwise score as highly distinctive and steer the
  stream back towards it. That needs history queries restricted to the stream's feeds.
- **Stemming.** The headline (weight 1.0) and text (0.5) of each interest article are stemmed in its feed's
  language with the same `EntryStemming` the sweep uses, so the list and the sweep stem the same way.
  Entries without a language go through the cut form, as today.
- **Selection.** For each language, a stem's score compares its share of interest articles with its share
  in the 30-day background of the reader's candidate feeds (smoothed, so stems absent from the background
  don't divide by zero). A stem must appear in at least two interest articles (my call: a one-off word in a
  single favourite is noise). The top stems per language are kept (suggest 60, the #2582 limit) and mapped
  onto the 1–10 weight scale the scorer and thresholds were tuned on.
- **Saved searches** are stemmed like phrases (including German compound parts at half weight, as
  `InterestTermStems` does today) and join the selection as a strong boost, not as a guaranteed top weight
  (my call): each counts as if it appeared in several interest articles, so it still has to be distinctive
  against the background. A generic word from a multi-word search ("chang" from "climate change") then
  drops out instead of flooding the stream. No phrase matching in the scorer: it stays single-stem.
- **Display words.** Each stem keeps the surface word it came from most often, and that word is what the
  settings list shows ("Bundesliga", not "bundeslig"). Saved-search terms show as typed.
- **Rarity in the scorer.** Selection already weighs stems by how distinctive they are. Whether the
  scorer's separate rarity factor stays, is folded into the weight or is dropped is the builder's call,
  with one constraint: precision step 4 should let in roughly as many entries per day as today's AI list
  did for the dev account. Measure that on the dev data before settling it.

### 4. When the list is rebuilt

- The list is due when the reader's interest inputs have changed since the last build (a cheap fingerprint
  of the inputs: favourite/kept/liked/shared entry ids and saved-search terms), checked on the existing
  sweep tick and rebuilt at most once an hour, plus once a day regardless so the background stays current.
  The exact fingerprint is the builder's call.
- A disabled account is skipped entirely: no build, no sweep.
- An account with no usable stems keeps no words. The settings page shows an empty state: favourite,
  keep, like or share articles to fill your stream. The sidebar row shows while the stream is enabled and
  has words or member rows (my call), so a list that later goes empty doesn't hide entries already in it.
  The row holding the sweep mark is kept when the words go empty, so a later build resumes from that mark
  instead of jumping to the current ceiling.
- The first build sets the sweep mark at the current entry ceiling, as today (no backfill). A rebuild
  leaves the mark where it is.

### 5. What is removed

The AI term extraction (`LlmInterestTermExtractor`, `InterestTermParser`, the extractor interface, the
term-run entity, repository and finder, `InterestTermRefresh`'s provider error handling), its `CallPhase`
and `RunLogOwner` cases and the debug-log entries for them, the AI phrase storage, and the stream parts of
the recommendation settings JSON, DTOs, writer and frontend card.

## Left to the builder

Class and table names inside the new module, the exact selection formula and smoothing, the fingerprint,
the per-section cap, the scorer-rarity question (measured, see §3), the migration shape, and the copy in
de/en/fr/es (glossary: `docs/superpowers/plans/2026-11-13-2205-french-spanish-translations.md`).
