# api_entries_list: put `entry` first in the join order

- **Issue:** #1040
- **Date:** 2026-09-14
- **Status:** Analysis done on the dev MySQL stack; proposal ready for implementation.

## Root cause

`EntryListRepository::listForUser` orders by `(effective_date, id) DESC` with a
`LIMIT`. MySQL can only stop early on `idx_entry_effective` when `entry` is the
**first** table in the join order. The optimizer instead drives the query from
`subscription` (181 rows for the heaviest user, selected by the unique
`(user_id, feed_id)` index), fetches every entry of every subscribed feed
through the plain `feed_id` index, writes all 43,518 rows including
`content_html` into a temporary table, and sorts that to keep 50.

The correlated `NOT EXISTS` of `DuplicateCollapseDql` is **not** a second
problem. It is a point lookup on `idx_entry_url_hash` per visited row; it runs
31,148 times only because the plan visits 31,148 rows. When the plan visits 52
rows it runs 52 times.

## Evidence

Dev MySQL 8.4.11, user 2 (181 subscriptions, 43,837 subscribed entries of
46,657 total), page size 50, warm cache. `EXPLAIN ANALYZE` on the SQL Doctrine
generates (the scratchpad files `explain*.sql` hold every statement).

| # | Shape | Time | Rows visited |
|---|---|---|---|
| V0 | as generated, all view | 1547 ms | 43,518 |
| V1 | V0 without the collapse predicate | 1130 ms | 43,837 |
| V4 | V0 projecting only `e.id` | 586 ms | 43,518 |
| V5 | as generated, unread view | 1127 ms | 43,702 |
| V11 | `STRAIGHT_JOIN`, entry first, no index hint, all view | 1.3 ms | 52 |
| V19 | `/*+ JOIN_PREFIX(e3_) */`, all view | 0.6 ms | 52 |
| V6 | forced date index, unread view | 1.5 ms | 52 |
| V7 | forced date index, all view, cursor page | 3.1 ms | 54 |
| V18 | `JOIN_PREFIX`, unread view, last page (30 rows left) | 245 ms | 10,189 |
| V12 | 181-branch `UNION ALL` per-feed top-50, all view | 136 ms | 6,563 |
| V13 | same, unread view | 98 ms | 3,179 |
| V8 | forced date index, **single-feed** scope | 163 ms | 46,657 |
| V9 | single-feed scope, planner free | 0.04 ms | 10 |
| V14 | favorites view, planner free | 4 ms | 51 |
| V17 | favorites view, entry first | 613 ms | 44,963 |
| V15 | tag scope (23 feeds), planner free | 44 ms | 1,676 |
| V16 | tag scope, entry first | 30 ms | 3,010 |
| V10 | subscription as `IN (subquery)` | 1212 ms | 43,519 |

Readings:

- With `entry` first the planner picks `idx_entry_effective` on its own and
  stops after ~52 rows; no `FORCE INDEX` or `ORDER_INDEX` is needed (V11, V19).
  With a cursor it becomes a reverse range scan (V7).
- Rewriting the subscription join as a subquery does not change the plan (V10).
- The bounded per-feed fan-in (V12/V13) is 10x better than today but 100x worse
  than the ordered walk here, needs native SQL and a second copy of the scope
  predicates, and hits SQLite's compound-select limit. Not worth it now.
- Entry-first is wrong for scopes the planner already handles well: a single
  subscription (V8 vs V9) and the state-driven views favorites / kept / viewed
  (V17 vs V14; "viewed" orders by `es.viewed_at`, V20 = 49 ms planner free).
- The hint form `/*+ JOIN_PREFIX(alias) */` is a comment to SQLite, so one SQL
  string is valid on both platforms and effective on MySQL.

## Proposal

1. **Join-order hint for the multi-feed, date-ordered shapes only.** A Doctrine
   custom output walker (`Query::HINT_CUSTOM_OUTPUT_WALKER`, a `SqlWalker`
   subclass under `src/Doctrine/`) overrides `walkSelectClause` to emit
   `SELECT /*+ JOIN_PREFIX(<entry sql alias>) */ ...` for the outer statement.
   The alias comes from `getSQLTableAlias()` for DQL alias `e`; the collapse
   subselect uses `walkSimpleSelectClause`, so it stays unhinted.
   `listForUser` sets the hint when the query is a fan-in:
   `subscriptionId === null` and view `all` or `unread` (tag scope included,
   V15/V16). Express that as a named predicate on `EntryQuery`, not as a
   boolean flag threaded through the repository. Single-subscription scope and
   favorites / kept / viewed keep the free plan.
2. **No change to the collapse.** With ~52 visited rows its cost is negligible;
   the exact predicate keeps every page at `limit` rows and stable across
   pages. Collapsing only the current slice, or in the frontend, buys nothing
   here and would leave holes or shrink pages, and every client (including a
   native iOS one) would have to reimplement the rule.
3. **No projection change.** The `content_html` cost (V0 vs V4) only exists
   because 43k rows reach the temporary table; with 50 rows it is gone.

## Tests

- Unit: the walker emits the hint with the right alias for the fan-in query and
  emits plain `SELECT` for a subscription-scoped or favorites query.
- Existing `EntryListRepository` functional tests must stay green on SQLite
  (proves the SQL still parses with the comment) and on the Docker MySQL leg
  (`docker compose exec php composer test`).
- Manual: rerun V0 and V19 from the scratchpad against Docker MySQL after the
  change; compare the `api_entries_list` p95 in Tempo before and after.
- `composer infection:diff` gates the walker and the predicate.

## Residual and follow-ups (out of scope)

- The ordered walk visits every row newer than the page's oldest hit, whatever
  feed it belongs to. A user whose feeds are dormant relative to the whole
  instance pays for other users' entries (V8 is the extreme). Cost per visited
  row is ~3 µs (index walk) against ~30 µs today (temporary table with TEXT).
- Deep pages of the unread view walk through read rows until they find unread
  ones (V18: the last page cost 245 ms against 1127 ms today). Bounding that
  needs a per-user unread structure; not this issue.
- `searchForUser` (#408), `unreadCountsForUser` and `SavedSearchMatchIds::forAll`
  keep their O(corpus) shape, as the issue records.
