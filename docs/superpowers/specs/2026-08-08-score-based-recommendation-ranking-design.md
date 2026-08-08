# Score-based recommendation ranking — design

**Issue:** #316
**Date:** 2026-08-08
**Status:** agreed in brainstorming, pending spec review

## Problem

The recommendation pipeline was designed around a single provider call, and its
output contract still says so: *"Order the array best first. Include at most
100 picks."* Batching was added later (#308 capped a batch at 40 candidates),
which quietly broke the contract's meaning:

- A batch holds at most 40 candidates, so "at most 100 picks" constrains
  nothing. The batch phase filters nothing; every batch can return its whole
  input.
- The merge phase truncates each batch's winner list to
  `2 × picksLimit / N` entries (`MERGE_WINNERS_PER_BATCH_FACTOR`) before the
  merge model sees them. With 25 batches that is a silent top-8 cut whose
  quality rests entirely on per-batch ordering — ordering the batch model was
  never told matters this much.
- The merge model must then re-rank up to ~200 thin one-line entries (title,
  source, date, reason — no description) and emit up to 100 JSON objects in
  one reply. Models routinely stop far short of 100, so entries the batch
  phase ranked well never reach the final list.

Observed symptom: quality. The final list misses entries the reader expected,
and the funnel — not the models' judgment — is where they get lost.

## Goals

- The final list is the best `picksLimit` entries **over the total candidate
  pool**, not an artifact of batch boundaries.
- Cross-batch "several sources cover one story — keep one" dedup is kept.
- The heavy-output failure mode (a model asked to emit 100 JSON objects)
  is removed.
- The final ordering is deterministic and inspectable from the run row.

## Non-goals

- No change to the run state machine's driver contract (poll tick and worker
  sweep keep calling the same `advance()`), to run scheduling, or to locking.
- No change to `RecommendationItem`, the API response shapes, or the frontend.
  `picksLimit` keeps its meaning: the size of the final list.
- No third provider-call phase. The call count per run is unchanged
  (N batch calls + at most 1 merge-turned-dedup call).
- No deterministic (non-LLM) duplicate detection. Fuzzy same-story matching
  in PHP is out of scope.

## Design overview

```
batch phase      N calls    each scores EVERY candidate 0–100  → {id, score, reason}
global cut       code       flatten, stable-sort by score desc, cut to 2 × picksLimit
dedup phase      ≤1 call    sees the score-ordered lines, returns ONLY ids to drop
finalize         code       remove dropped ids, take top picksLimit by score
```

The model's job per batch changes from "pick and order the best" to "score
each candidate against the same fixed rubric". The cross-batch comparison that
the merge model used to do implicitly now happens in code, on numbers the
batches produced against a shared anchor: every batch sees the identical
history sections and the identical rubric, which is what keeps scores from
independent calls comparable enough to sort.

The merge call survives but shrinks to the one job code cannot do: spotting
that two lines cover the same story. Its reply is a handful of ids, so reply
truncation stops being a failure mode.

## Batch phase

### Prompt changes (`RecommendationPromptText`)

`SYSTEM_ROLE` keeps its framing (four sections, history weighting, recency
preference) and changes its task statement: score every candidate, anchored:

- **90–100** — squarely inside a theme the history shows strong, repeated
  interest in (FAVORITES weigh strongest, KEPT next, VIEWED least).
- **60–89** — clearly matches a visible interest.
- **30–59** — plausibly interesting; the connection to the history is loose.
- **0–29** — no visible connection to the history.

The in-batch same-story rule stays, restated for scoring: *"When several
candidates cover the same story, score only the best source and omit the
others."* This is what lets a single-batch run keep skipping the second call.

`OUTPUT_CONTRACT` (batch form) becomes:

```
Reply with JSON only, no prose: {"recommendations": [{"id": <candidate id>,
"score": <0-100>, "reason": "<one short sentence>"}]}. Score every candidate.
Use only ids that appear in the candidate lines.
```

No ordering requirement, no count cap — both are code's job now. The
`%d`-formatted pick limit leaves the contract entirely.

`CORRECTIVE` is unchanged in role; reword only if the final phrasing needs it
("exactly in the required shape, using only candidate ids" still fits both
phases).

### Parsing (`RecommendationPickParser`)

`RecommendationPick` gains `score` (int). Salvage rules for the new field:

- Accept an int, a float, or a numeric string; round to the nearest int and
  clamp into `[0, 100]`.
- A pick whose `score` is missing or non-numeric is discarded — same
  treatment as an invalid id. (A reply in the old scoreless shape therefore
  salvages zero picks, is unusable, and triggers the normal corrective
  retry.)
- Everything else is unchanged: invalid ids and duplicates are skipped,
  partial credit stands, zero surviving picks means unusable.

The `$limit` argument passed for a batch reply becomes the batch's candidate
count (i.e. effectively uncapped) instead of `picksLimit`.

### Packing (`RecommendationPromptBuilder::packBatches`)

The response reserve currently scales with `picksLimit`
(`picksLimit × TOKENS_PER_PICK`, 4 000 tokens at the defaults). The reply now
scales with the batch, so the reserve becomes
`MAXIMUM_BATCH_SIZE × TOKENS_PER_PICK` (1 600 tokens), a constant. Slightly
more room for candidate lines per batch; no other packing change.

## Global cut (code, no call)

- Flatten `batchWinners` in batch order. Batches partition the snapshot, so
  ids cannot repeat across batches; no cross-batch id dedup is needed.
- Stable-sort by score descending. Ties keep flattening order, which is
  snapshot order, which is the candidate loader's recency order — so ties
  break toward newer entries, matching the feature's recency preference.
- Cut to `DEDUP_INPUT_CAP = 2 × picksLimit` lines (200 at the defaults;
  ~200 thin lines is roughly 8 k tokens and fits the 32 k fallback context
  window with the fixed overhead). `MERGE_WINNERS_PER_BATCH_FACTOR` and the
  per-batch truncation it drove are deleted.

## Dedup phase (the renamed merge phase)

### Prompt

`MERGE_ROLE` is replaced by a dedup role:

```
You remove duplicate stories from a ranked list built for one reader of an
RSS reader. The user message lists RANKED entries, best first; each line
starts with the entry id in square brackets, followed by title, source, date
and the reason it was chosen. When several entries cover the same story,
keep the best source and name the others as duplicates.
```

User message: `RANKED (best first):` followed by the score-ordered lines in
the existing `- [id] title — source — date — reason` shape.

Output contract (dedup form):

```
Reply with JSON only, no prose: {"duplicates": [<entry id>, ...]}. List only
ids of entries that duplicate a better-ranked entry. If there are no
duplicates, reply {"duplicates": []}. Use only ids that appear in the lines.
```

The user's guidance prompt is **not** included in the dedup call: guidance
shapes what to recommend, and this call no longer recommends anything.

### Parsing

A new small parser (own class next to `RecommendationPickParser`, sharing the
code-fence strip) with different validity rules, because "empty" flips
meaning:

- Unparsable JSON or a missing/`non-array` `duplicates` key → unusable →
  the existing corrective-retry machinery, unchanged.
- An **empty `duplicates` array is a usable reply** ("no duplicates found") —
  the opposite of the pick parser's zero-picks rule, which is why this is not
  a flag on the existing parser.
- Ids not present in the shown lines are ignored (partial credit, as today).

### Failure handling — degrade, do not fail

Today an exhausted merge phase fails the whole run. With the new shape that
throws away N successful batch calls because a cosmetic cleanup reply was
malformed three times. Decision: when the dedup phase exhausts
`MAX_ATTEMPTS`, the run **completes with the undeduped top `picksLimit`**
instead of failing. Transport failures keep their own ceiling and keep
failing the run — an unreachable provider is not a degraded answer.

## Finalize

- Survivors = the cut list minus the dropped ids, in score order.
- Take the top `picksLimit` survivors. Never reach below the cut line for
  backfill: entries beyond the cap were never shown to the dedup call, so
  pulling them in could reintroduce unchecked duplicates. A final list
  shorter than `picksLimit` is acceptable and rare (it requires more than
  `picksLimit` drops out of `2 × picksLimit` lines).
- The existing existence re-check and dense-position item writing are
  unchanged. `RecommendationItem` does not learn about scores; the score's
  home is the run row (below).

A single-batch run (`needsMerge` false today) finalizes as before with no
second call — but now sorts its one winner list by score and cuts to
`picksLimit` first, so the code path is "global cut → finalize" minus the
dedup call, not a special case.

## Entity and state changes (`RecommendationRun`)

- `batchWinners` entries gain `score`:
  `list<list<array{id: int, score: int, reason: string}>>`. JSON column — no
  schema migration. Scores being persisted here is also the inspectability
  story: the full scored pool of a run can be read straight off the row.
- A winner entry without a `score` key (a run in flight across the deploy)
  reads as score 0: it sorts to the back, the run still completes, and the
  next run self-heals. No migration code for this; runs live seconds to
  minutes.
- `RecommendationRunProgress` logic is unchanged (the dedup call still counts
  as the one extra step). `needsMerge`/`isMergePhase` are renamed
  `needsDedup`/`isDedupPhase`; plain value object, no persistence impact.
- Advancer/private-method names follow (`mergeTick` → `dedupTick`, …).

## Error handling summary

| Event | Behaviour |
| --- | --- |
| Unusable batch reply | Unchanged: corrective retry, fail after `MAX_ATTEMPTS`. |
| Old-shape (scoreless) batch reply | Zero picks salvage → unusable → corrective retry carries the new contract. |
| Unusable dedup reply | Corrective retry; after `MAX_ATTEMPTS`, **complete undeduped** (new). |
| Transport failure, either phase | Unchanged: own ceiling, fails the run. |
| Entries pruned mid-run | Unchanged: absent lines are skipped; all-pruned short-circuits stay. |

## Testing

- **Parser:** score salvage table — int, float, numeric string, out-of-range
  clamp, missing score discards the pick, old-shape reply is unusable.
- **Dedup parser:** empty array usable, bad shape unusable, foreign ids
  ignored.
- **Prompt builder:** batch contract carries no pick cap; dedup message is
  score-ordered and capped at `2 × picksLimit`; reserve constant; guidance
  absent from the dedup call.
- **Advancer (functional, through `advance()`):** global cut ordering and
  tie-breaking; dedup drops applied; degrade-on-exhausted-dedup completes
  with items; single-batch run sorts and cuts without a second call;
  legacy scoreless winners sort last and do not crash finalize.
- Existing suites updated where they pin the old contract strings or the
  `{id, reason}` winner shape.
- Gates as always: both phpunit legs, `composer check`, `composer md` on
  touched files, `composer infection:diff`, PhpStorm inspections.
