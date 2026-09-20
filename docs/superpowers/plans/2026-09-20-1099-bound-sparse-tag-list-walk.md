# Plan: #1099 — bound the sparse-tag list walk, fall back unhinted

Spec: GitHub issue #1099 (binding authority). This plan is its argument. Builds on
#1098 (merged): `EntryPlanHint` / `EntryPlanHintWalker`.

## Context

#1040 added `JOIN_PREFIX(entry)` (now `EntryPlanHint::DateOrderedWalk`) to
date-ordered fan-in lists. It is a large win on dense scopes and a 20–40× loss on
sparse tag scopes: MySQL walks most of `entry` backwards probing the tag join
before it fills a page (tags 85/92/95: 270–585 ms vs ~15 ms unhinted).

`EntryListRepository::listForUser` (backend/src/Repository/EntryListRepository.php)
builds the page query, applies the `DateOrderedWalk` hint when
`EntryQuery::isDateOrderedFanIn()`, runs it, hydrates, and calls `attachDuplicates`.
`applyCursor` (AbstractEntryProjectionRepository) is the single speller of the
keyset "before" predicate; `EntryListSort::PublishedDate` drives fan-in ordering.

## Global Constraints

- **Correct under every filter** (unread view, #496 collapse, cursor): the rule
  judges the *result* (full page vs short page), never an estimate. No statistics,
  no count queries.
- **Response bodies byte-identical** before and after for the same request.
- **One code path for both engines.** SQLite runs the same two-step logic; the hint
  is a comment there. No engine-specific branch.
- **K = 2000**, a named constant, injectable so tests use a small K with small
  fixtures.
- The windowing/fallback decision lives in its **own collaborator**, not in more
  branches inside `listForUser` (keep `EntryListRepository` PHPMD-clean).
- **Reuse the cursor predicate; do not re-spell it.** The probe's keyset predicate
  must come from the same `applyCursor` the page query uses.
- `attachDuplicates`, hydration, `EntryPlanHint`, and the walker are unchanged.
- House style: `final`, `declare(strict_types=1)`, guard clauses, intent names, no
  restating comments, comment-length rule. `composer check` + `composer md` clean;
  PhpStorm inspections clean on changed PHP.

## Design (concrete — follow unless you find it wrong; report if so)

### `EntryQuery` — new intent-revealing predicate

Add `isTagScopedFanIn(): bool` returning `$this->isDateOrderedFanIn() && $this->tagId !== null`.
`isDateOrderedFanIn()` keeps its current meaning. (Name it for the concept, not the
mechanism; `isTagScopedFanIn` is fine.)

### `App\Repository\DateOrderedPage` — the decision collaborator

A `final readonly class` that owns the probe / window / hint / fallback. It touches
only the `QueryBuilder`s the repository hands it, so it needs **no** EntityManager —
this keeps it trivially unit-testable and keeps `applyCursor` the single speller.

```
public const int DEFAULT_WINDOW_SIZE = 2000;
public function __construct(private int $windowSize = self::DEFAULT_WINDOW_SIZE) {}

/**
 * @param callable(): QueryBuilder $pageQuery  a FRESH, fully-built page query each
 *        call (ordered, scoped, collapsed, cursored, limited) — called up to twice.
 * @param callable(): QueryBuilder $windowProbe a FRESH Entry-only query selecting
 *        e.effectiveDate, ordered effectiveDate DESC, id DESC, carrying the same
 *        keyset cursor predicate as the page — no offset/limit yet.
 * @return list<array<array-key, mixed>>   raw rows; the repository hydrates.
 */
public function rows(EntryQuery $query, callable $pageQuery, callable $windowProbe): array
```

`rows()` decides:

1. `!$query->isDateOrderedFanIn()` → `$pageQuery()->getQuery()->getResult()` — no
   hint, exactly today's plain path (single subscription, favorites/kept/viewed).
2. fan-in but `!$query->isTagScopedFanIn()` (all / unread) → hint the page query and
   run once. No probe. (Their unhinted plan is 1.5 s; there is no useful fallback.)
3. `$query->isTagScopedFanIn()` → windowed attempt with fallback:
   - **Probe**: `$windowProbe()->setFirstResult($this->windowSize)->setMaxResults(1)`,
     read `e.effectiveDate` of the K-th row beyond the cursor. `getOneOrNullResult()`
     → null means fewer than K rows beyond the cursor: hint the page query, run once,
     no window, no fallback. Return.
   - Otherwise **windowed attempt**: a fresh `$pageQuery()` + `andWhere('e.effectiveDate >= :windowStart')`
     (`Types::DATETIME_IMMUTABLE`), hinted, run.
   - If it returns a **full page** (`count === $query->limit`) → that is the answer
     (exact: rows are `effectiveDate DESC`, window is a `>=` lower bound on the same
     column, so `limit` rows inside the window are the newest `limit` of the scope;
     `>=` keeps ties at the boundary instant).
   - Else **fallback**: fresh `$pageQuery()`, unhinted, unwindowed. Return that.

Hint application: `EntryPlanHintWalker::apply($qb->getQuery(), EntryPlanHint::DateOrderedWalk)`
then `getResult()`. Extract a private helper so the three hinted call sites read
once (e.g. `hintedResult(QueryBuilder): array`).

### `listForUser` rewiring

Replace the inline `if isDateOrderedFanIn { apply hint }` + `getResult()` block with:

```
$pageQuery = function () use ($query, $sort, $applyScope): QueryBuilder {
    $qb = $this->orderedBy($this->rowQueryBuilder($query->userId), $sort)
        ->setMaxResults($query->limit);
    $applyScope($qb, EntryAliases::primary());
    $this->collapse->apply($qb, $applyScope, $query->userId);
    $this->applyCursor($qb, $query->cursor, $sort);
    return $qb;
};
$windowProbe = function () use ($query): QueryBuilder {
    $qb = $this->createQueryBuilder('e')
        ->select('e.effectiveDate')
        ->orderBy('e.effectiveDate', 'DESC')
        ->addOrderBy('e.id', 'DESC');
    $this->applyCursor($qb, $query->cursor, EntryListSort::PublishedDate);
    return $qb;
};
$rows = $this->dateOrderedPage->rows($query, $pageQuery, $windowProbe);
$survivors = array_map(fn (array $row): EntryListRow => $this->rowHydrator->hydrate($row), $rows);
return $this->attachDuplicates($survivors, $applyScope, $query->userId);
```

Inject `DateOrderedPage $dateOrderedPage` into the constructor. Wire it in
`config/services.yaml` alongside the other `App\Repository` services; the production
instance uses the default window size (2000).

## Testing

Repository tests (both legs), extending `tests/Repository/EntryListTest.php`, using a
small injected K (construct `EntryListRepository` with a `DateOrderedPage(K=<small>)`,
or construct `DateOrderedPage` directly in a focused test — pick what keeps fixtures
small and the wiring honest):

- **Dense tag**: full page from the windowed attempt equals the page the plain query
  returns.
- **Sparse tag**: fewer than `limit` rows inside the window, more outside → equals the
  plain query's page (fallback path).
- **Scope with fewer than `limit` rows total**: short page, no duplicates, no loss.
- **Boundary tie**: several entries share the window-start instant and straddle the
  page edge — none dropped or duplicated.
- **Cursor page**: window measured from the cursor; second page continues without gap
  or overlap, in both the windowed and fallback paths.
- **Unread view on a dense tag whose recent entries are all read** → fallback path,
  correct rows.
- **Fewer than K rows beyond the cursor** → single hinted query, no window.
- **All-items scope issues no probe** — assert via query count (`QueryRecorder`, see
  the existing `joinPrefixQueries` helper).

`DateOrderedPage` unit tests are optional if the repository tests exercise every
branch through real wiring; prefer the repository tests (they prove the SQL and the
cursor reuse). If a branch is awkward to force through the repository, add a focused
`DateOrderedPage` test with fake callables returning stub `QueryBuilder`s.

### Verification

- `composer cs && composer stan && composer md` clean on touched files; PhpStorm
  inspections clean.
- Full `php bin/phpunit` (SQLite) green. Run affected tests on the MySQL leg via
  `docker compose exec php composer test -- --filter=...` before pushing.
- **Re-run `composer cs` and the affected tests after EVERY edit, including
  test-only edits, before committing** — CI fails on PSR-12 warnings.
- `composer infection:diff` locally before pushing — the mutation-changed-files leg
  gates CI; expected 100% on the changed lines.

### Manual verification (record in the PR, MySQL dev stack, user 2)

Re-measure the issue's table through EXPLAIN/timing for tags 80 (dense), 83 (dense),
92/95 (sparse) and all-items: sparse tags drop from 270–585 ms to < 60 ms; dense tags
and all-items stay within a few ms of today; all-items issues no probe.

## Acceptance

- No tag list's page query costs more than ~2× its unhinted plan; tags 85/92/95 drop
  from 270–585 ms to < 60 ms.
- Dense tags and all items stay within a few ms of today.
- Response bodies byte-identical before and after for the same request.
