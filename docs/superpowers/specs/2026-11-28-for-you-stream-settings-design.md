# For you stream settings (#2594)

## Intent

The For you stream (#2582) runs for every account with a term set, matches at one fixed threshold and keeps
its interest terms hidden. The reader wants control and transparency: switch it off, see what it matches on,
and tune how strict the matching is.

## Goals

- **On/off** in Settings → AI → Recommendations, in a new "For you stream" group. An info tip next to the
  toggle explains in a few sentences how it works: the AI turns the profile into weighted words per language,
  every new entry's headline and text is matched against them, words rare in the reader's feeds count more,
  and a change only affects entries that arrive afterwards.
- **Off** means: the matching sweep skips the account, the interest-term AI call is not made, the sidebar entry
  is hidden (`forYouStream.available` false). Entries already in the stream stay; turning it back on resumes
  with entries swept from then on, with no backfill (the #2582 rule).
- **The generated word list**: the phrases the AI returned, per language, with their weight, read-only, shown
  under the toggle while it is on. Empty state when no term set exists yet.
- **Precision in five steps**, narrow → wide, as the shared stepper from the sidebar
  brightness control (narrow end left, wide end right, a click on the bar resets to the default). Step 4 (second widest) is
  today's `MIN_SCORE` 1.5 and is the default. A change applies to entries swept afterwards.

## Non-goals

- Editing the word list, adding or removing words.
- Rescoring or rebuilding the stream when precision or the list changes.
- Showing stems.

## Approaches (word list)

- **A. Store the AI's phrases with the term set (picked).** Readable, what the AI actually said. Costs one
  stored field; the list fills from the next term refresh. Existing term sets show an empty list until then.
- **B. Show today's stems.** No storage change, but fragments (`renewabl`) duplicated across languages and
  the language-neutral cut form; unreadable.
- **C. Re-derive phrases on read with an AI call.** Expensive and drifts from what was matched. Rejected.

## Decisions (my calls, Lars approved the direction)

- Settings live on `RecommendationSettings`: a `forYouStreamEnabled` bool (default true) and a precision enum
  column (default the second-widest step), following `RecommendationBatchSize`.
- Precision thresholds, narrow → wide: **4.0, 3.0, 2.0, 1.5, 1.0**. The enum owns the mapping to a minimum
  score; the scorer takes the account's threshold instead of the constant.
- Phrases are stored with the account's term set, replaced together with the stems in `InterestTermStore`.
  The data is develop/Strato-only, so existing rows simply start with none.
- `GET /api/me/ai/recommendations` carries the settings and the word list; the switch and precision are written
  through their own sub-resource, `PUT /api/me/ai/recommendations/for-you-stream` (`{enabled, precision}`, both
  required), which answers with the same JSON as the GET. The whole-settings PUT ignores a `forYouStream` key.
  JSON in, JSON out — nothing browser-coupled, fits the iOS constraint.
- Copy in all four languages (de/en/fr/es) per the glossary.

## Left to the builder

Module placement, exact class names, whether the phrases sit in a JSON column or a child table, the response
shape, and the step labels (e.g. Narrowest / Narrow / Balanced / Wide / Widest).
