# #2126 Sparse all/unread scope skips the join hint — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** The all/unread entry list stops walking the whole `entry` table for a reader whose subscriptions match few entries, while a dense reader keeps the `JOIN_PREFIX(e)` plan and gets no slower.

**Architecture:** `DateOrderedPage::rows()` sends every chronological fan-in (all/unread, with or without a tag, network or feed group) through the #1099 probe → windowed hinted attempt → unhinted fallback that today serves only the narrowed scopes. `EntryQuery::isNarrowedFanIn()` loses its only caller and is deleted. No SQL, index or repository query changes.

**Tech Stack:** Symfony 7.4, PHP 8.4, Doctrine ORM (custom output walker `EntryPlanHintWalker`), PHPUnit 12, MySQL 8 (Docker) and SQLite (native).

**Spec:** GitHub issue #2126 (`gh issue view 2126`). History: e5ba3d8e1 (#1040, the hint), a0b4011ba (#1098, keyed hint), 976a0760e (#1099, probe-then-fallback for tags), #1143 (oldest-first window, `ListOrder::atOrBefore()`).

## As shipped

The plan's window-everything design shipped with one amendment for the caught-up dense reader:

- When the windowed page comes back short, an **unnarrowed** all/unread list runs a **scope probe**. The probe takes one entry id at offset K from the reader's All scope, joined through the subscription. It has no hint, no duplicate collapse and no read state.
  - If an entry exists at offset K, the list keeps today's hinted walk.
  - If none exists, the list runs the plain page.
- **Narrowed scopes (tag, network, feed group) are unchanged** from #1099: a short window always falls back plain.
- `DateOrderedPage::rows()` takes a `DateOrderedQueries` parameter object (page, window probe, scope probe) instead of three loose callables.
- `EntryQuery::inAllView()` gives the probe the same scope with the view dropped.
- Every all/unread page now runs one extra statement, the window probe, measured at +0.4–0.5 ms. The scope probe runs only when the window comes back short.

## Global Constraints

- Commit format `fix(#2126): …`; no Claude attribution, no `Co-Authored-By`, no session links.
- Branch `fix/2126-sparse-scope-skips-join-hint` is already checked out; do not switch branches (other sessions may share the checkout).
- CLAUDE.md Clean Code rules apply to src and tests: intent-revealing names, no new comments unless a reader would get the code wrong without one, every touched `src` file PHPMD-clean.
- Never write to the dev DB except through the app's API; never truncate, clear or `docker compose down -v`.
- Hint tests assert on the recorded statements (`QueryRecorder`), never on timing.

## Why this design (verified against the code)

- `windowEdge()` runs the `$windowProbe` from `EntryListRepository::listForUser()`: an **Entry-only** query (`SELECT e.effectiveDate FROM Entry e [cursor] ORDER BY effectiveDate, id … OFFSET K LIMIT 1`). It has no subscription join, so it reads K+1 rows of `idx_entry_effective (effective_date, id)` for **every** reader, dense or sparse: a cost bounded by K = 2000, independent of the reader. It does not reintroduce the full walk.
- Because the probe counts the whole table, not the reader's scope, the issue's literal wording ("when `windowEdge()` finds fewer than K rows in the scope, use `plainResult()`") does not match the code: a `null` edge means fewer than K entries **in the whole table** beyond the cursor, where the hinted walk is already bounded by K. The sparse case is caught one step later: the windowed hinted attempt (`effective_date >= edge` for newest-first, `<=` for oldest-first) visits at most K entries, returns a short page for a sparse reader, and `narrowedRows()` falls back to `plainResult()`. So the existing method is exactly right for all/unread; only the routing in `rows()` changes.
- Dense reader: probe (≈K index entries) + the same hinted walk as today, stopped at the edge. The page fills inside the window, so no fallback; the added cost is the probe alone. Sparse reader (0 or 1 subscription): probe + ≤K-row hinted walk + the unhinted plan (0.25 ms on the dev DB) instead of the 120,433-row walk (1,607 ms).
- Known risk, measured in Task 3: a dense reader whose newest K entries are almost all read gets a short **unread** window and falls back to the unhinted plan, which cost 1.5 s for a dense scope on #1143. #1099 accepted that for tags; the measurement decides whether it holds for Lars's unread view.

## File structure

| File | Change |
|---|---|
| `backend/src/Repository/DateOrderedPage.php` | `rows()` windows every chronological fan-in; `narrowedRows()` renamed `windowedRows()`; class docblock updated |
| `backend/src/Repository/EntryQuery.php` | delete `isNarrowedFanIn()` (no caller left); `isNarrowed()` stays for `hidesExcludedFeeds()` |
| `backend/tests/Repository/EntryListTest.php` | sparse/newcomer/dense regression tests for all and unread; `testAllItemsScopeIssuesNoProbe` replaced |
| `backend/tests/Repository/EntryQueryTest.php` | drop the `isNarrowedFanIn()` assertions |

---

### Task 1: Baseline measurement on the dev stack (before any code change)

**Files:** none (read-only; results go into the PR body later). Keep scratch files in your session scratchpad, referred to below as `$SCRATCH`.

- [ ] **Step 1: Confirm the Docker stack runs this checkout's code**

```bash
cd /Users/lars/Documents/work/eigenes/simple-feed-reader
docker compose ps
git -C . log -1 --oneline
```
Expected: `php`, `mysql`, `nginx`, `frontend` up. The code under test is still develop's (`a77f67b0f`), so the "before" plan is the always-hinted one.

- [ ] **Step 2: Find the dense and sparse readers**

```bash
docker compose exec -T mysql sh -c 'mysql -uroot -p"$MYSQL_ROOT_PASSWORD" $MYSQL_DATABASE' <<'SQL'
SELECT COUNT(*) AS entries FROM entry;
SELECT u.id, u.email, COUNT(s.id) AS subscriptions
  FROM app_user u LEFT JOIN subscription s ON s.user_id = u.id
  GROUP BY u.id, u.email ORDER BY subscriptions DESC LIMIT 5;
SELECT u.id, u.email FROM app_user u LEFT JOIN subscription s ON s.user_id = u.id
  GROUP BY u.id, u.email HAVING COUNT(s.id) = 0 ORDER BY u.id DESC LIMIT 3;
SELECT u.id, u.email FROM app_user u LEFT JOIN subscription s ON s.user_id = u.id
  GROUP BY u.id, u.email HAVING COUNT(s.id) = 1 ORDER BY u.id DESC LIMIT 3;
SQL
```
Record: `DENSE` = the top row (Lars's own account), `NONE` = a 0-subscription id, `ONE` = a 1-subscription id.
If no 0-subscription user exists, create one through the app by running the onboarding e2e once (Task 5 shows the limiter-clear and run command). If no 1-subscription user exists, ask Lars to subscribe one throwaway account to a single feed in the UI; do not insert rows.

- [ ] **Step 3: Pull the exact hinted list statements for Lars**

Ask Lars to open the All view and then the Unread view on http://localhost:4200 (his own session) if today's log has no list statement for `DENSE`. Then:

```bash
cd /Users/lars/Documents/work/eigenes/simple-feed-reader/backend
LOG=$(ls -t var/log/dev-*.log | head -1)
jq -c 'select(.channel=="doctrine") | select(.context.sql | test("^SELECT /\\*\\+ JOIN_PREFIX\\(e[0-9]+_\\) \\*/")) | .context' "$LOG" | tail -n 4 > "$SCRATCH/hinted.jsonl"
wc -l "$SCRATCH/hinted.jsonl"
```
The pattern excludes the `INDEX(… idx_entry_url_hash)` duplicate lookup. Identify the all-view and unread-view statements (the unread one carries the read-state predicate on `marked_read_until`/`is_read`); save each as one line in `$SCRATCH/all.json` and `$SCRATCH/unread.json`.

- [ ] **Step 4: Inline the params**

```bash
cat > "$SCRATCH/inline.php" <<'PHP'
<?php
$context = json_decode(file_get_contents($argv[1]), true);
$parameters = $context['params'];
$swapFrom = $argv[2] ?? null;
$swapTo = $argv[3] ?? null;
$position = 0;
echo preg_replace_callback('/\?/', static function () use (&$position, $parameters, $swapFrom, $swapTo): string {
    $value = $parameters[++$position] ?? $parameters[$position - 1];
    if ($swapFrom !== null && (string) $value === $swapFrom) {
        $value = (int) $swapTo;
    }
    if (is_bool($value)) {
        return $value ? '1' : '0';
    }
    if (is_int($value) || is_float($value)) {
        return (string) $value;
    }

    return "'" . addslashes((string) $value) . "'";
}, $context['sql']), ";\n";
PHP
php "$SCRATCH/inline.php" "$SCRATCH/all.json" > "$SCRATCH/all-dense.sql"
php "$SCRATCH/inline.php" "$SCRATCH/all.json" DENSE NONE > "$SCRATCH/all-none.sql"
php "$SCRATCH/inline.php" "$SCRATCH/all.json" DENSE ONE > "$SCRATCH/all-one.sql"
# same three for unread.json → unread-dense.sql, unread-none.sql, unread-one.sql
```
Replace `DENSE`/`NONE`/`ONE` with the ids from Step 2. Before trusting a swap, read the inlined SQL: every replaced value must sit on a `user_id =` comparison (`s0_.user_id`, `es…user_id`, `s…2…user_id` inside the collapse). If the dense id also appears as an unrelated value (a flag, a limit), swap by hand instead.

- [ ] **Step 5: EXPLAIN ANALYZE, hinted and unhinted**

`--comments` keeps the `/*+ … */` hint; without it the client may strip it.

```bash
for name in all-dense all-none all-one unread-dense unread-none unread-one; do
  echo "== $name (hinted)"
  { printf 'EXPLAIN ANALYZE '; cat "$SCRATCH/$name.sql"; } \
    | docker compose exec -T mysql sh -c 'mysql --comments -uroot -p"$MYSQL_ROOT_PASSWORD" $MYSQL_DATABASE' \
    | head -c 4000
  echo "== $name (unhinted)"
  { printf 'EXPLAIN ANALYZE '; sed -E 's#/\*\+ JOIN_PREFIX\([^)]*\) \*/ ##' "$SCRATCH/$name.sql"; } \
    | docker compose exec -T mysql sh -c 'mysql --comments -uroot -p"$MYSQL_ROOT_PASSWORD" $MYSQL_DATABASE' \
    | head -c 4000
done | tee "$SCRATCH/before.txt"
```
Run each twice and keep the second (warm) number. Record per statement: top-level `actual time`, rows scanned on `idx_entry_effective`, NOT EXISTS loops. Expected shape: dense hinted ≈ 0.1 s and much faster than unhinted; `none` hinted ≈ 1.6 s against ≈ 0.25 ms unhinted (the issue's numbers).

---

### Task 2: Window every chronological fan-in, fall back unhinted when short

**Files:**
- Modify: `backend/src/Repository/DateOrderedPage.php`
- Modify: `backend/src/Repository/EntryQuery.php:73-81`
- Test: `backend/tests/Repository/EntryListTest.php`
- Test: `backend/tests/Repository/EntryQueryTest.php:70-78`

**Interfaces:**
- Consumes: `EntryListTest::repositoryWithWindow(int)`, `recordedList(EntryListRepository, EntryQuery): array{rows, queries}`, `entryIn(Feed, string, string)`, `fillerFeed()`, `guids(list<EntryListRow>)` — all existing.
- Produces: `DateOrderedPage::rows()` unchanged signature; `EntryQuery::isNarrowedFanIn()` removed.

- [ ] **Step 1: Write the failing tests in `EntryListTest`**

Add the import next to the others:

```php
use PHPUnit\Framework\Attributes\DataProvider;
```

Add two fixture helpers after `fillerFeed()`:

```php
    private function anotherReadersBusyFeed(): void
    {
        $reader = new User('busy-reader@example.com', new \DateTimeImmutable('2026-07-01T00:00:00Z'));
        $feed = new Feed('https://busy.example.com/feed.xml');
        $this->entityManager->persist($reader);
        $this->entityManager->persist($feed);
        $this->entityManager->persist(new Subscription($reader, $feed, new \DateTimeImmutable('2026-07-01T00:00:00Z')));
        $this->entityManager->flush();

        foreach (range(10, 14) as $day) {
            $this->entryIn($feed, 'busy-' . $day, sprintf('2026-07-%02dT00:00:00Z', $day));
        }
    }

    private function newcomer(): User
    {
        $newcomer = new User('newcomer@example.com', new \DateTimeImmutable('2026-07-01T00:00:00Z'));
        $this->entityManager->persist($newcomer);
        $this->entityManager->flush();

        return $newcomer;
    }
```

Replace `testAllItemsScopeIssuesNoProbe()` (it pins the always-hinted route this issue removes) with these, in the same place:

```php
    /**
     * @return iterable<string, array{EntryView}>
     */
    public static function chronologicalViews(): iterable
    {
        yield 'all' => [EntryView::All];
        yield 'unread' => [EntryView::Unread];
    }

    public function testAnAllViewBelowTheWindowSizeRunsTheProbeThenOneHintedPage(): void
    {
        $this->entry('a', '2026-07-05T00:00:00Z');

        $queries = $this->recordedList(
            $this->repository(),
            new EntryQuery($this->user->requireId(), EntryView::All),
        )['queries'];

        self::assertCount(2, $queries, 'a null probe must run exactly one hinted page query');
        self::assertStringNotContainsString('JOIN_PREFIX', $queries[0], 'the probe itself is never hinted');
        self::assertStringContainsString('JOIN_PREFIX', $queries[1]);
    }

    #[DataProvider('chronologicalViews')]
    public function testADenseReaderStaysHintedInsideTheWindow(EntryView $view): void
    {
        $this->entryIn($this->feed, 'own-1', '2026-07-10T00:00:00Z');
        $this->entryIn($this->feed, 'own-2', '2026-07-09T00:00:00Z');
        $this->entryIn($this->fillerFeed(), 'filler', '2026-07-08T00:00:00Z');
        $query = new EntryQuery($this->user->requireId(), $view, limit: 2);

        $recorded = $this->recordedList($this->repositoryWithWindow(2), $query);

        self::assertCount(2, $recorded['queries'], 'a full windowed page must never fall back');
        self::assertStringContainsString('JOIN_PREFIX', $recorded['queries'][1]);
        self::assertSame(['own-1', 'own-2'], $this->guids($recorded['rows']));
    }

    #[DataProvider('chronologicalViews')]
    public function testASparseReaderNextToABusyFeedFallsBackToThePlainUnhintedQuery(EntryView $view): void
    {
        $this->anotherReadersBusyFeed();
        $this->entryIn($this->feed, 'own-1', '2026-07-05T00:00:00Z');
        $this->entryIn($this->feed, 'own-2', '2026-07-04T00:00:00Z');
        $query = new EntryQuery($this->user->requireId(), $view, limit: 2);

        $recorded = $this->recordedList($this->repositoryWithWindow(2), $query);

        self::assertCount(3, $recorded['queries'], 'a short windowed page must fall back to a third, plain query');
        self::assertStringContainsString('JOIN_PREFIX', $recorded['queries'][1], 'the windowed attempt is hinted');
        self::assertStringNotContainsString('JOIN_PREFIX', $recorded['queries'][2], 'the fallback is unhinted');
        self::assertSame(['own-1', 'own-2'], $this->guids($recorded['rows']));
    }

    #[DataProvider('chronologicalViews')]
    public function testAReaderWithoutSubscriptionsGetsTheUnhintedPlanNextToABusyFeed(EntryView $view): void
    {
        $this->anotherReadersBusyFeed();
        $query = new EntryQuery($this->newcomer()->requireId(), $view, limit: 2);

        $recorded = $this->recordedList($this->repositoryWithWindow(2), $query);

        self::assertCount(3, $recorded['queries'], 'an empty windowed page must fall back to a third, plain query');
        self::assertStringNotContainsString('JOIN_PREFIX', $recorded['queries'][2], 'the fallback is unhinted');
        self::assertSame([], $recorded['rows']);
    }
```

Also update the `repositoryWithWindow()` docblock's first line, which names only the tag branch:

```php
    /**
     * A repository wired with a small window, so the fan-in probe/window branch can be forced through a handful
     * of fixture rows instead of DateOrderedPage::DEFAULT_WINDOW_SIZE.
     */
```

`testTheChronologicalFanInViewsCarryTheJoinOrderHint` stays as it is: with an empty table the probe is `null` and exactly one hinted query still runs.

- [ ] **Step 2: Run the new tests to see them fail**

```bash
cd /Users/lars/Documents/work/eigenes/simple-feed-reader/backend
php bin/phpunit tests/Repository/EntryListTest.php --filter 'BelowTheWindowSize|DenseReaderStaysHinted|SparseReaderNextToABusyFeed|WithoutSubscriptionsGetsTheUnhinted'
```
Expected: 7 failures, each on the first `assertCount` (`1` query recorded where 2 or 3 are expected), because all/unread still run one always-hinted query.

- [ ] **Step 3: Route every chronological fan-in through the window**

Replace the class docblock, `rows()` and the `narrowedRows()` header in `backend/src/Repository/DateOrderedPage.php`:

```php
/**
 * Runs a fan-in page: unhinted for a single scope or a state-driven view; a chronological fan-in is probed, walked
 * hinted inside the window, and falls back unhinted when the window is short, since a sparse scope would otherwise
 * walk the whole entry table (#1099, #2126).
 */
final readonly class DateOrderedPage
{
    public const int DEFAULT_WINDOW_SIZE = 2000;

    public function __construct(private int $windowSize = self::DEFAULT_WINDOW_SIZE)
    {
    }

    /**
     * @param callable(): QueryBuilder $pageQuery   a fresh, complete page query; called up to twice
     * @param callable(): QueryBuilder $windowProbe a fresh Entry-only effectiveDate query in the page's order, cursored
     *
     * @return list<array<array-key, mixed>>
     */
    public function rows(EntryQuery $query, callable $pageQuery, callable $windowProbe): array
    {
        if (!$query->isDateOrderedFanIn()) {
            return $this->plainResult($pageQuery());
        }

        return $this->windowedRows($query, $pageQuery, $windowProbe);
    }

    /**
     * @param callable(): QueryBuilder $pageQuery
     * @param callable(): QueryBuilder $windowProbe
     *
     * @return list<array<array-key, mixed>>
     */
    private function windowedRows(EntryQuery $query, callable $pageQuery, callable $windowProbe): array
    {
```
The body of `windowedRows()` is the unchanged body of `narrowedRows()`; `windowEdge()`, `windowed()`, `hintedResult()` and `plainResult()` stay as they are.

- [ ] **Step 4: Delete the dead `isNarrowedFanIn()`**

In `backend/src/Repository/EntryQuery.php` remove this block entirely (`isNarrowed()` below it stays; `hidesExcludedFeeds()` uses it):

```php
    /**
     * A fan-in list narrowed to one tag, a network or a feed group — the shape that can be sparse enough
     * to make the join-order hint a loss rather than a win (#1099).
     */
    public function isNarrowedFanIn(): bool
    {
        return $this->isDateOrderedFanIn() && $this->isNarrowed();
    }

```

In `backend/tests/Repository/EntryQueryTest.php` replace `testATagOrANetworkNarrowsTheFanIn()` with:

```php
    public function testANetworkScopedChronologicalViewStillFansIn(): void
    {
        $query = new EntryQuery(1, EntryView::Unread, networkScope: NetworkScope::whole(SocialNetwork::Bluesky));

        self::assertTrue($query->isDateOrderedFanIn());
    }
```

Confirm nothing else references it:

```bash
cd /Users/lars/Documents/work/eigenes/simple-feed-reader/backend && grep -rn "isNarrowedFanIn\|narrowedRows" src tests
```
Expected: no output.

- [ ] **Step 5: Run the repository tests on SQLite**

```bash
cd /Users/lars/Documents/work/eigenes/simple-feed-reader/backend
php bin/phpunit tests/Repository/EntryListTest.php tests/Repository/EntryQueryTest.php
```
Expected: all pass, including the existing tag/network window tests (`testSparseTagFallsBackToThePlainUnhintedQuery`, `testANetworkIsWindowedLikeATag`, …), whose route is unchanged.

- [ ] **Step 6: Run the same tests on the MySQL leg**

```bash
cd /Users/lars/Documents/work/eigenes/simple-feed-reader
docker compose exec -T php bin/phpunit tests/Repository/EntryListTest.php tests/Repository/EntryQueryTest.php
```
Expected: all pass. This is the MySQL regression leg: a sparse reader and a newcomer beside another reader's busy feed get the unhinted statement last; the dense reader's last statement is hinted. Note for the executor: if the container rejects the bare `bin/phpunit` (worker isolation), run it with `TEST_TOKEN=1` set, e.g. `docker compose exec -T -e TEST_TOKEN=1 php bin/phpunit …`.

- [ ] **Step 7: Commit**

```bash
cd /Users/lars/Documents/work/eigenes/simple-feed-reader
git add backend/src/Repository/DateOrderedPage.php backend/src/Repository/EntryQuery.php \
  backend/tests/Repository/EntryListTest.php backend/tests/Repository/EntryQueryTest.php
git commit -m "fix(#2126): window the all/unread walk so a sparse reader falls back unhinted"
```

---

### Task 3: After measurement on the dev stack

**Files:** none (read-only).

- [ ] **Step 1: Make the running stack serve the new code**

```bash
cd /Users/lars/Documents/work/eigenes/simple-feed-reader
docker compose exec -T php bin/console cache:clear
```

- [ ] **Step 2: Capture the new statements for Lars**

Ask Lars to reload the All and the Unread view on http://localhost:4200. Then pull the last statements of each request:

```bash
cd /Users/lars/Documents/work/eigenes/simple-feed-reader/backend
LOG=$(ls -t var/log/dev-*.log | head -1)
jq -c 'select(.channel=="doctrine") | select(.context.sql | test("^SELECT e[0-9]+_\\.effective_date AS|^SELECT /\\*\\+ JOIN_PREFIX\\(e[0-9]+_\\) \\*/|^SELECT s[0-9]+_\\.id AS id_0")) | .context' "$LOG" | tail -n 12 > "$SCRATCH/after.jsonl"
```
For each view expect: the probe (`SELECT e?_.effective_date … LIMIT 1 OFFSET 2000`) and the windowed hinted page (the old statement plus one `effective_date >= ?` predicate). A third, unhinted page statement for Lars's request means the dense window came up short: record it; it is the risk named in "Why this design".
Save `probe.json`, `all-windowed.json`, `unread-windowed.json` (one context line each).

- [ ] **Step 3: Build the sparse readers' statements**

The probe and the edge param are the same for every reader on the first page, so the sparse readers' windowed statements are Lars's with the user id swapped, and their fallback is the windowed statement minus the hint and the edge predicate (the plain page):

```bash
php "$SCRATCH/inline.php" "$SCRATCH/probe.json" > "$SCRATCH/probe.sql"
for view in all unread; do
  php "$SCRATCH/inline.php" "$SCRATCH/$view-windowed.json" > "$SCRATCH/$view-windowed-dense.sql"
  php "$SCRATCH/inline.php" "$SCRATCH/$view-windowed.json" DENSE NONE > "$SCRATCH/$view-windowed-none.sql"
  php "$SCRATCH/inline.php" "$SCRATCH/$view-windowed.json" DENSE ONE > "$SCRATCH/$view-windowed-one.sql"
done
```
The fallback statements are the unhinted `before` statements of Task 1 (`all-none.sql` with the hint stripped, and so on); reuse those numbers.

- [ ] **Step 4: EXPLAIN ANALYZE**

```bash
for name in probe all-windowed-dense all-windowed-none all-windowed-one unread-windowed-dense unread-windowed-none unread-windowed-one; do
  echo "== $name"
  { printf 'EXPLAIN ANALYZE '; cat "$SCRATCH/$name.sql"; } \
    | docker compose exec -T mysql sh -c 'mysql --comments -uroot -p"$MYSQL_ROOT_PASSWORD" $MYSQL_DATABASE' \
    | head -c 4000
done | tee "$SCRATCH/after.txt"
```
Warm numbers (second run). Pass criteria:
- Probe reads ≈ 2001 index entries on `idx_entry_effective` and takes low single-digit ms.
- Dense, per view: probe + windowed ≤ the Task 1 dense hinted time (noise allowance a few ms), and the windowed page returns the full 50 rows. If dense unread falls back, or dense gets slower, stop and report both tables to Lars before going on.
- Sparse (`none`, `one`), per view: probe + windowed (≤ 2000 rows walked) + unhinted fallback, far below the Task 1 hinted ≈ 1.6 s.

Put a before/after table (statement, rows walked, warm ms) into the PR body.

---

### Task 4: Gates

**Files:** none unless a gate points at a touched file.

- [ ] **Step 1: Static gates** (from `backend/`; these may run in parallel)

```bash
cd /Users/lars/Documents/work/eigenes/simple-feed-reader/backend
composer cs
bin/console cache:warmup && composer stan
composer md
composer tramp
```
Expected: all clean. `composer md` must report nothing for `src/Repository/DateOrderedPage.php` and `src/Repository/EntryQuery.php`. If `composer tramp` fails on a chain through `windowedRows()`, check `composer show larspohlmann/phptramp` first: the chain is the same one `narrowedRows()` already carried.
Also run the PhpStorm inspections (`mcp__phpstorm__lint_files`) on the four changed files; block on ERROR and WARNING.

- [ ] **Step 2: Both suite legs** (they can run at the same time)

```bash
cd /Users/lars/Documents/work/eigenes/simple-feed-reader/backend && php bin/phpunit
cd /Users/lars/Documents/work/eigenes/simple-feed-reader && docker compose exec php composer test:parallel
```
Expected: both green.

- [ ] **Step 3: Mutation gate**

```bash
cd /Users/lars/Documents/work/eigenes/simple-feed-reader/backend && composer infection:diff
```
Expected: at or above `minMsi`. The mutant that drops the `isDateOrderedFanIn()` guard is killed by `testAScopedOrStateDrivenViewDropsTheJoinOrderHint` (favorites would be hinted). Never lower `minMsi`.

- [ ] **Step 4: Scan the dev log**

```bash
cd /Users/lars/Documents/work/eigenes/simple-feed-reader/backend
ls -t var/log/dev-*.log | head -1 | xargs tail -n 200 | jq -c 'select(.level_name=="ERROR" or .level_name=="WARNING" or .level_name=="CRITICAL")'
```
Expected: nothing new from the entry list.

---

### Task 5: Onboarding e2e passes

**Files:** none.

- [ ] **Step 1: Clear the limiter and run the skip test**

```bash
cd /Users/lars/Documents/work/eigenes/simple-feed-reader
docker compose exec -T php bin/console cache:pool:clear cache.rate_limiter altcha.replay.cache
cd frontend && npx playwright test e2e/onboarding.spec.ts -g "skipping" --reporter=list
```
Expected: `skipping goes to the reader and leaves a way back` (`frontend/e2e/onboarding.spec.ts:241`) passes; the empty state ("Browse suggested feeds") appears without the earlier ~6 s `/api/entries?view=all` stall. Run it only from this checkout (the e2e preflight guard fails fast otherwise).
