# For you stream — design

Date: 2026-11-27. Status: draft for Lars's approval.

## Intent

The AI recommendations ("For you") are a small, fixed set of picks per run, and they stay as they are.
Next to them Lars wants a **constant stream**: every newly ingested entry that roughly fits the reader's
interests lands in it, soon after it arrives. Volume matters more than precision — junk hits are accepted.

## Goals

- A second per-user list, the **For you stream**, filled continuously from newly ingested entries.
- Matching is cheap and local: stems of the entry against a weighted term list derived from the user's
  AI profile, using the stemming already used by Trending. No AI call per entry.
- It reads like a saved search: newest first, unread badge, mark read / mark all read.

## Non-goals

- No change to the AI recommendation runs, their profile prompt, or the For you page.
- No rebuild or rescoring when the profile changes: membership is a log of what matched at the time.
- No backfill when the feature first runs, and none when a user gets their first profile.
- No user-visible or editable term list; no tuning UI.
- No dedup against the AI picks: an entry may be in both.
- No email digest section: it is a stream, read in the reader (Lars).

## Anti-goals

- A stream that is mostly silent because the threshold is tight. Err towards letting entries in.
- Slowing down or enlarging ingest: matching runs after ingest, in its own budgeted sweep.

## Approaches considered (term source)

- **A. Extend the profile distillation call** to return prose plus terms. One call, always in sync, but it
  changes the prompt and reply schema the recommendation runs depend on.
- **B. A second short AI call after distillation** turns the prose profile into weighted terms per language.
  The existing prompt stays untouched; the call fails and retries on its own.
- **C. No AI: stem the prose profile directly.** Free, but single-language and noisy.

**Chosen: B** (Lars). It is the only one that matches across the four languages without touching what works.

## Decided design

### 1. Stemming moves to a shared lower module

Landed as `Service/Foundation/Stemming`: `WordStemmer`, `Support/WordStems`, `WordEquivalents`, `GermanCompoundSplitter` and `StopWordStems` (#2583).

`Curation/Trending` already depends on `Curation/Recommendation` (Trending's `StoryTitleParser`/`StoryTitleTask`
import Recommendation's `ModelReplyJsonDecoder` and `CallPromptModel`), so the stream (owned by Recommendation)
cannot import Trending. Move the language-aware word handling out of Trending
into a new module the two can both depend on — `Content/Stemming` (builder may pick the name):
`HeadlineStemmer`, `SnowballStemmerFactory`, `Support/HeadlineStems`, `GermanCompoundSplitter` with
`Pass/CompoundSplitSearch`, `Model/CompoundSplitModel`, `Model/GermanLexiconEntryModel` and `GermanLexicon/*`,
`HeadlineEquivalents`, and the seed-stem part of `HeadlineFiller`. Stop words already live in
`Foundation/Text/StopWords` and stay there. Trending keeps its
window-specific logic (`HeadlineWindow`, document frequencies, filler by window/feed). This lands first, as a
pure refactor with no behaviour change.

### 2. Interest terms (approach B)

- The term call is due whenever the user has a stored profile and no term set, or a term set older than the
  stored profile (my call). That covers accounts that already have a profile at deploy, `Unchanged` scheduled
  runs and manual-only schedules: the check runs on the profile-run tick, independent of whether a run
  regenerated the profile. No committed backfill command.
- The term call is a follow-up AI call (same connection and
  rate-limited call path as distillation, recorded with `CompletionCallRecorder`) asks for JSON:
  a list of `{term, weight}` per language the user reads (de/en/fr/es; languages from the user's
  subscribed feeds' `FeedLanguage`, or all four when unknown). Terms are specific interests and named things;
  short phrases are allowed and split into words.
- Terms are canonicalised, stemmed per language (compound parts included for German) and stored per user:
  stem, language, AI weight. A new term set replaces the old one; it only affects entries swept afterwards.
- An unusable reply is retried with a corrective message like distillation; when attempts are exhausted the
  previous term set stays in force (the profile itself is still stored either way).

### 3. Matching sweep

- A new budgeted sweep, modelled on `SavedSearchMembershipSweep`: a per-user high-water mark on entry id, walks
  settled entries above it in chunks, writes matches to a new membership table, advances the mark.
- Scheduled like the saved-search sweep: `WorkerSchedule` every minute, plus a `MaintenanceSweep` tick for
  Strato's external cron.
- Candidates: entries in feeds the user subscribes to, whose subscription is included in For you
  (`InclusionSurface::ForYou`).
- A user's mark starts at the current entry ceiling the first time they have a term set — that is what "no
  backfill" means.
- Only entries published at most 48 hours before they are swept are candidates (Lars): a
  newly subscribed feed's back catalogue does not flood the stream.
- Entry text: headline (weight 1.0) and body (0.5), stemmed in the feed's language with the shared module,
  as Trending does. Entries without a language are stemmed with the six-character cut, so each term is also
  stored in cut form and those entries still match (my call: unsharp is fine).
- Score: sum over matched distinct stems of `AI weight × rarity × position weight`. Rarity is the term's
  inverse frequency over the newest N entries of the last 30 days in the user's For-you-included feeds, per
  language (my call), computed for the user's terms only when a term set is stored and refreshed daily (cheap:
  one pass per term set, not per entry).
- An entry enters when its score clears a deliberately low threshold, a constant. Ship a loose first value and
  adjust it later from the scores stored on the rows (my call; no dry-run command).
- Store the score and the matched stems on the membership row for debugging. Nothing shows them in the UI.

### 4. Reading the stream

- Modelled on saved searches, not on an `EntryView` (my call): its own controller and route (e.g.
  `/api/entries/for-you-stream`), newest first by entry date, paged with the existing keyset cursors, enriched
  with `EntryListRowEnricher`. Subscription/tag/network/group filters do not apply.
- Its own mark-read and mark-all-read endpoints over the membership, writing per-entry read state (the generic
  per-subscription watermark is wrong for a cross-feed list, per the saved-search ruling on `isRead`).
- Unread count in the sidebar totals.
- Sidebar entry next to For you; shown only once the user has a term set.
- Client contract stays iOS-viable (bearer auth, JSON, problem+json) — run the §6 checklist.

## Left to the builder

- Module and class names, table schema, the exact threshold and term count limit (suggest ≤ 60 per language).
- Membership retention: follow the entry retention policy (rows go with their entry).
- Whether the term call shares `ProfileRun` bookkeeping or gets its own run record.
