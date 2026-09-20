# Plan: #1098 — attachDuplicates optimizer hint via a generalized plan walker

Spec: GitHub issue #1098. This plan is its argument; the issue is the binding authority.

## Context

`EntryListRepository::attachDuplicates` runs a second query per list page to load
the copies the #496 collapse hid. On MySQL the `e.id NOT IN (:survivorIds)`
predicate steers the optimizer off `idx_entry_url_hash`, so it scans every entry
of every subscribed feed (300–400 ms on the heaviest user). The fix keeps the
query's meaning and adds the optimizer hint
`JOIN_PREFIX(<entry alias>) INDEX(<entry alias> idx_entry_url_hash)`, rendered by
the existing custom output walker, generalized to render more than one plan.

Today `App\Doctrine\EntryListJoinOrderWalker` renders exactly one hint
(`JOIN_PREFIX(<entry alias>)`) for the date-ordered fan-in list. #1099 will build
on the generalized walker, so land this first.

## Global Constraints

- **The `DateOrderedWalk` hint output stays byte-for-byte identical** to today's
  `SELECT /*+ JOIN_PREFIX(<alias>) */ …`. The `listForUser` call site keeps
  emitting exactly that.
- **`DuplicateLookup` renders** `SELECT /*+ JOIN_PREFIX(<alias>) INDEX(<alias> idx_entry_url_hash) */ …`.
- Both hints are optimizer-hint comments; **one SQL string still serves SQLite and
  MySQL** (SQLite reads the comment as a comment). No engine-specific branch.
- The `NOT IN (:survivorIds)` predicate in `attachDuplicates` is **kept** — do not
  drop it and filter in PHP.
- The #496 collapse (`DuplicateCollapseDql`) is **not touched**.
- Response bodies must be **byte-identical** before and after for the same request.
- House style: `final` classes, `declare(strict_types=1)`, guard clauses, names
  reveal intent, no comments that restate code. Must pass `composer check` and
  `composer md`.
- Index-name guard: MySQL only *warns* (does not error) on a hint naming a missing
  index, so a rename of `idx_entry_url_hash` would silently bring the scan back. A
  test must assert the index name the walker renders exists in `Entry`'s ORM
  metadata.

## Task 1: Generalize the walker to a keyed plan hint and switch attachDuplicates onto it

Do the whole change in one commit-worthy unit. Sub-steps:

1. **Add an enum** `App\Doctrine\EntryPlanHint` with two cases:
   `DateOrderedWalk` and `DuplicateLookup`. Give it a method that renders the
   optimizer-hint body for a given entry SQL alias, e.g.
   `optimizerHint(string $entryAlias): string`, returning:
   - `DateOrderedWalk` → `JOIN_PREFIX(<alias>)`
   - `DuplicateLookup` → `JOIN_PREFIX(<alias>) INDEX(<alias> idx_entry_url_hash)`
   The index name `idx_entry_url_hash` must be reachable as a constant (on the enum
   or the walker) so the guard test can assert it against ORM metadata rather than
   re-typing the literal.

2. **Generalize the walker.** Rename `EntryListJoinOrderWalker` to a name that
   matches the wider job (suggested: `EntryPlanHintWalker`) in
   `src/Doctrine/`. It reads the chosen `EntryPlanHint` from a custom query hint
   (a public `HINT` constant on the walker, distinct from
   `Query::HINT_CUSTOM_OUTPUT_WALKER`) and renders that plan's optimizer comment on
   the outer SELECT, keyed to the entry table's own SQL alias exactly as today. A
   missing/unknown hint value must fail loudly (do not silently emit no hint when
   the walker was selected — the repository always pairs the two hints).

3. **Update `listForUser`** in `EntryListRepository`: where it sets
   `HINT_CUSTOM_OUTPUT_WALKER`, also set the plan-hint custom hint to
   `EntryPlanHint::DateOrderedWalk`. Byte-identical SQL to today.

4. **Switch `attachDuplicates`** onto the walker: set
   `HINT_CUSTOM_OUTPUT_WALKER` to the walker and the plan hint to
   `EntryPlanHint::DuplicateLookup` on its query, unconditionally. No caller
   changes — all three list paths (`listForUser`, `searchForUser`,
   `rowsByIdsForUser`) share the method.

5. **Update the walker test** (`tests/Doctrine/EntryListJoinOrderWalkerTest.php`,
   rename to match): assert both enum cases render the expected SQL prefix on the
   outer SELECT only (still exactly one `JOIN_PREFIX` occurrence for the collapse
   query, subselect stays unhinted), that a query without the walker emits no
   comment, and that an unknown/missing plan hint value fails loudly. Keep the
   existing "still parses and runs" assertion for both cases.

6. **Add the metadata guard test**: assert the index name the walker renders
   (`idx_entry_url_hash`, read from the constant, not re-typed) exists in `Entry`'s
   ORM `ClassMetadata` table indexes. This lives with the walker tests or in a
   focused metadata test.

7. **Extend `tests/Repository/EntryListTest.php`** (both legs, SQLite native +
   MySQL): with duplicate copies of one article in scope, a survivor never appears
   in its own `duplicates`; a hidden copy in scope does appear; a copy outside the
   scope (e.g. a different tag) does not. Cover all three list paths' shared
   `attachDuplicates` at least via the list path; if `searchForUser` /
   `rowsByIdsForUser` already have duplicate coverage, do not duplicate it.

### Verification

- `composer cs && composer stan && composer md` clean on touched files.
- `php bin/phpunit` (SQLite leg) green for the walker, metadata and repository
  tests.
- Confirm no other references to the old walker class name remain
  (`grep -rn EntryListJoinOrderWalker src tests config`).

### Manual verification (record in the PR, run on the MySQL dev stack)

- `EXPLAIN` shows `idx_entry_url_hash` driving the duplicates query for all-items,
  a tag scope and a search scope.
- Profiler `db` collector: `GET /api/entries?view=all` doctrine time in the tens of
  ms (was ~400 ms).
- Response body byte-identical to before for the same request.
