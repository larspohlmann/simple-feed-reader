# #2131 Caught-up readers skip the hinted walk — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** A caught-up reader whose All scope holds about 2k–20k entries gets the unhinted plan for a short All/Unread page (≈0.6 s instead of ≈2.2 s). A reader whose unread entries lie just past the window keeps a fast hinted page. Dense, caught-up dense and sparse readers keep their #2126 numbers.

**Architecture:** Only `DateOrderedPage` changes. An unnarrowed All/Unread page whose 2,000-row window comes back short now takes one of four paths:
1. The scope probe at offset K = 2,000 finds no entry: the plain unhinted query. This is #2126's sparse path, unchanged.
2. The same probe at offset N = 20,000 finds an entry: the hinted full walk. This is #2126's dense path, unchanged.
3. Otherwise (K < scope ≤ N): a second hinted attempt inside a wide window of 20,000 table rows.
4. If that wide window is short too: the plain unhinted query.

The probe closure, `EntryListRepository`, `EntryQuery` and every SQL statement stay as they are. Narrowed scopes (tag, network, feed group) keep #1099's plain fallback.

**Tech Stack:** Symfony 7.4, PHP 8.4, Doctrine ORM (`EntryPlanHintWalker`), PHPUnit 12, MySQL 8.4 (Docker) and SQLite (native).

**Spec:** GitHub issue #2131 (`gh issue view 2131`). Predecessor: PR #2132 / #2126, plan `docs/superpowers/plans/2026-11-09-2126-sparse-scope-skips-join-hint.md` (read its "As shipped" section), report `.superpowers/sdd/2126-report.md`.

## Global Constraints

- Every change starts from issue #2131. Work on branch `fix/2131-caught-up-readers-skip-hinted-walk`, cut off `develop`. Before any `checkout`, run `git status` and check that no other session is mid-edit in this checkout. Never commit to `develop`.
- Commit format: `fix(#2131): …`. No Claude attribution, no `Co-Authored-By`, no session links.
- CLAUDE.md is binding:
  - intent-revealing names, no abbreviations
  - no boolean flag parameters
  - guard clauses
  - default to no comment; a class docblock of three lines at most
  - every touched `src` file PHPMD-clean, with no count-driven class split
  - phptramp clean
  - PHPStan level max, with no new ignores or baselines and no edits under `tests/PhpStan`
  - `Response::HTTP_*` never applies here (no HTTP)
- Narrowed scopes (tag, network, feed group) keep #1099's behaviour. A short window falls back plain, with no scope probe and no wide window.
- The dev DB is data only. Run read-only `SELECT`/`EXPLAIN ANALYZE` and nothing else: no `INSERT`/`UPDATE`/`DELETE`, no `docker compose down -v`, no `cache:pool:clear` on the doctrine pools.
- Measure the real statements Doctrine issues, captured with the recorder below. Warm runs only: run each statement 3–4 times, drop the first, report the others. Never trust a hand-reduced probe (memory: *measure the real statement, not a probe*).
- Hint tests assert on the recorded statements (`QueryRecorder`), never on timing.
- Temp files in the php container: only `/tmp/rec2131.php` and `/tmp/rec2131-cache`. Remove them with plain `rm` of those exact paths when done. Scratch files on the host go in the session scratchpad, written below as `$SCRATCH`.

## Research (dev DB, 2026-11-09, warm `EXPLAIN ANALYZE`, top-level actual time)

The `entry` table holds 120,548 rows. Readers with an All scope above 500 entries:

| user | subscriptions | All-scope entries |
|---|---|---|
| 2 | 231 | 83,845 |
| 88 | 195 | 73,763 |
| 144 | 19 | 15,926 |
| 61 | 14 | 12,400 |

No real reader sits between 16k and 73k. The band was covered synthetically with user 2's real unread statement, restricted to a prefix of their subscriptions (`AND s0_.id IN (…)`, plus the same on `s6_` in the collapse).

**Method.** "Caught up" means the real unread statement with each watermark predicate `(sN_.marked_read_until IS NULL OR eM_.effective_date > sN_.marked_read_until)` replaced by `eM_.effective_date > GREATEST(T, COALESCE(sN_.marked_read_until, T))`, with T the reader's 11th-newest in-scope date. This is the same non-sargable substitution as #2126. "Hinted full walk" is that statement without the window edge. "Plain" is the same statement with the `JOIN_PREFIX` hint stripped.

### 1. Caught-up crossover depends on scope relative to table size

| scope entries | reader | hinted full walk | plain |
|---|---|---|---|
| 12,400 | user 61 | 2,201–2,279 | **419–431** |
| 15,926 | user 144 | 2,058–2,151 | **510–550** |
| 21,378 | user 2 subset | 2,043–2,099 | **612–638** |
| 30,451 | user 2 subset | 2,075–2,169 | **827–924** |
| 40,805 | user 2 subset | 2,156–2,226 | **1,150–1,230** |
| 50,588 | user 2 subset | 2,169–2,221 | **1,370–1,466** |
| 61,063 | user 2 subset | 2,187–2,279 | **1,787–1,932** |
| 70,347 | user 2 subset | 2,269–2,626 | 2,056–2,179 |
| 73,763 | user 88 | 2,200–2,293 | 1,993–2,380 |
| 83,845 | user 2 | 2,290–2,315 | 2,338–2,587 |

The two plans scale differently:
- **Plain** costs about 0.028 ms per scope entry. It reads the whole scope through `idx_entry_feed_effective_hash`, then sorts.
- **The hinted full walk** costs about 2.1–2.3 s, nearly constant. It walks the whole `entry` table.

They cross at about **70–75k scope entries, roughly 0.6 × the table**. Because the crossover is a share of the table, a fixed limit gets more conservative as the table grows, and riskier if retention shrinks it.

### 2. The spread of unread entries matters more than scope size

Below, the caught-up statement also counts as unread every in-scope entry in a block of 10,000 table rows that starts at table offset S. That simulates a reader who read the newest entries and left a backlog behind them.

| reader (scope) | S = 3,000 hinted / plain | S = 20,000 hinted / plain | S = 60,000 hinted / plain |
|---|---|---|---|
| 61 (12.4k) | **39–40** / 328–377 | **172–173** / 357–362 | 1,014–1,091 / **342–380** |
| 144 (15.9k) | **25–28** / 500–505 | **139–140** / 430–481 | 843–857 / **486–506** |
| user 2 subset (21.4k) | **28–30** / 567–599 | | |
| user 2 subset (40.8k) | **29** / 1,007–1,096 | | |

A hinted walk stops as soon as it has 50 unread entries. If they lie just past the 2,000-row window, which is the usual "read the top of Unread" pattern, hinted costs 25–170 ms against 330–600 ms plain.

So the issue's simplest fix, raising the probe offset to N and then going plain, would make that common case **7–20× slower**: at scope 12–20k, about 40 ms becomes about 400–600 ms. A wide hinted window first is what keeps it fast.

### 3. Probe cost at offset N

The statement is the real scope probe: `SELECT e0_.id AS id_0 FROM entry e0_ INNER JOIN subscription s1_ ON (s1_.feed_id = e0_.feed_id AND s1_.user_id = U) WHERE s1_.include_in_all_items = 1 LIMIT 1 OFFSET N`.

| user | N = 2,000 | 20,000 | 40,000 | 60,000 |
|---|---|---|---|---|
| 2 | 0.52–0.65 | 3.5–4.8 | 7.1–7.7 | 11–12 |
| 88 | 0.37–0.50 | 3.5–4.2 | 7.9–8.1 | 12–12.5 |
| 144 | 0.39–0.48 | 3.1–3.6 (none) | 2.7–3.6 (none) | 2.7–3.0 (none) |
| 61 | 0.46–0.47 | 2.5–2.6 (none) | 2.1–2.5 (none) | 2.3–2.7 (none) |

The probe runs only after a short window, so a dense reader whose window fills never pays it.

### 4. The wide window costs

- The window probe at `OFFSET 20000` on `idx_entry_effective` takes 4.4–4.6 ms.
- The hinted page inside a 20,000-row edge, for caught-up readers (short):
  - user 61: 156–174 ms
  - user 144: 137–142 ms
  - user 2 subset 21.4k: 155 ms
- The same window for the gap readers at S = 3,000 (full): 28–41 ms.

### 5. No cheaper signal exists

- **Subscription count** does not track scope: user 61 has 14 subscriptions and 12.4k entries, user 88 has 195 and 73.8k. One social or news feed can hold 10k entries.
- **`subscription_day.arrived`** undercounts. Its sums for users 2, 61, 88 and 144 are 66,485, 7,662, 58,900 and 10,838, against the scopes above, because backfill and retention bypass it.
- **No stored per-reader scope size exists.** `SubscribedEntryCountRepository` and `UnreadCountRepository` count live, which costs the same as the probe or more.

The capped probe (`LIMIT 1 OFFSET N`) is the cheapest exact signal: at most about 4 ms at N = 20,000.

### Designs compared

Times are totals per short All/Unread page, in ms: the window probe plus the window, then the fallback.

| case | #2126 today | A: probe at N → plain | **B: probe K, probe N, wide window, plain (chosen)** |
|---|---|---|---|
| dense, window full (user 2 all/unread) | 1.65 / 2.14 | unchanged | unchanged (no fallback runs) |
| sparse, 0 or few subscriptions | 9.3–20.5 | +0–1 ms (probe at N reads the tiny scope) | unchanged (probe K finds none) |
| caught-up 12.4k (61) | ≈2,260 | ≈450 | **≈620** (20 + 0.5 + 2.5 + 4.4 + 165 + 425) |
| caught-up 15.9k (144) | ≈2,110 | ≈550 | **≈700** |
| caught-up 73.8k (88) | ≈2,270 | ≈2,275 | ≈2,275 (+4 ms probe) |
| caught-up dense 83.8k (2), #2126 2,343 | ≈2,330 | ≈2,335 | **≈2,335 (+4 ms, within run noise of ±40 ms)** |
| gap reader, unread just past window (61, S=3k) | ≈60 | **≈400** (regression) | ≈70 |
| gap reader, unread past 20k rows (61, S=20k) | ≈195 | ≈385 | ≈595 (regression, see risks) |
| gap reader, unread past 60k rows (61, S=60k) | ≈1,070 | ≈385 | ≈595 |

Both A and B meet the issue's done-criteria. A is simpler but regresses the common gap reader 7–20×. B costs one more branch and one more window. It is the simplest design that meets the criteria **without** a measured regression on the usual reading pattern.

**N = 20,000 (`DEFAULT_PLAIN_SCOPE_LIMIT`)**:
- It covers the issue's band.
- At N, plain costs 0.6 s against 2.1 s hinted.
- It stays below the crossover (≈0.6 × table) for any table of 35k rows or more.
- Raising it to 40k would save about 1 s for caught-up readers at 20–40k scope, and no such real reader exists. But it would need a table of 67k rows or more, and it would double the worst gap-reader loss.

**Wide window = 20,000 table rows (`DEFAULT_WIDE_WINDOW_SIZE`)**:
- It catches gap readers whose unread lie within 20k rows, the S = 3k–20k rows above.
- It costs about 160 ms on the caught-up path.

## As shipped

Dev DB (120.5k entries), warm `EXPLAIN ANALYZE`, median of 3–8 runs after one discarded, summed over every statement a path issues. Run on the branch code (354840919); the dev data moved between the two runs, so only same-statement deltas are exact.

| case | #2126 (Task 1, ms) | #2131 (Task 4, ms) |
|---|---|---|
| dense all / unread (user 2) | 1.3 / 1.2 | 2.2 / 2.0 (identical statements; the page statement itself measures 1.7 ms today against 0.8 ms then) |
| caught-up dense (user 2, 83.8k) | 2,510 | 1,790 (the walk now fills 50 rows after new entries; added probes cost ≈ 4.5 ms) |
| caught-up 73.8k (user 88) | 2,482 | 2,468 (added probes ≈ 5 ms, inside the walk's ±100 ms noise) |
| caught-up 15.9k (user 144) | 2,197 | 720 |
| caught-up 12.4k (user 61) | 2,437 | 647 |
| gap reader (user 61 / 144, unread 3k rows past the top) | 67 / 47 | 71 / 56 |
| sparse, 0 subscriptions | ≈ 9.5 | ≈ 9.8 |
| known slower case: user 61, 50th unread ≈ 20k rows deep | ≈ 203 | ≈ 637 |

## File structure

| File | Change |
|---|---|
| `backend/src/Repository/DateOrderedPage.php` | Two new constants and constructor params. `fallbackRows()` runs the four-way decision. `scopeOutgrowsTheWindow()` becomes `scopeReaches(\Closure, int)`, `windowEdge()` takes the window size, and `wideWindowedRows()` is new. The class docblock is updated. |
| `backend/src/Repository/DateOrderedQueries.php` | `$page` docblock: "called once per attempt" (up to three now) |
| `backend/tests/Repository/EntryListTest.php` | `repositoryWith(DateOrderedPage)` helper. The caught-up test is rewritten for the plain-scope limit, and three new tests cover the wide window. |

No change to `EntryListRepository`, `EntryQuery`, the DI config or any SQL builder. `DateOrderedPage` is autowired, and its scalar params take their defaults.

---

### Task 1: Baseline measurement on the dev stack (before any code change)

**Files:** none. The output is kept in `$SCRATCH/before.txt` for Task 4.

- [ ] **Step 1: Confirm the stack runs develop's code**

```bash
cd /Users/lars/Documents/work/eigenes/simple-feed-reader
docker compose ps --format '{{.Service}} {{.Status}}'
git log -1 --oneline
```
Expected: `php`, `mysql` and `nginx` are up, and HEAD is develop's tip or this branch with no `src` change yet.

- [ ] **Step 2: List readers by All-scope size**

```bash
docker compose exec -T mysql sh -c 'mysql -uroot -p"$MYSQL_ROOT_PASSWORD" $MYSQL_DATABASE' 2>/dev/null <<'SQL'
SELECT COUNT(*) AS table_rows FROM entry;
SELECT s.user_id, COUNT(DISTINCT s.id) subscriptions, COUNT(e.id) scope
  FROM subscription s JOIN entry e ON e.feed_id = s.feed_id
 WHERE s.include_in_all_items = 1
 GROUP BY s.user_id HAVING scope > 500 ORDER BY scope DESC;
SQL
```
Expected: users 2, 88, 144 and 61, roughly as in the Research table. If new readers exist between 16k and 73k, add them to every loop below.

- [ ] **Step 3: Write the read-only recorder and the measuring helpers**

`$SCRATCH/rec2131.php` boots the dev kernel with its own cache dir. It prints every statement one `listForUser()` call issues, with its params inlined, and writes nothing:

```php
<?php
// Read-only: php rec2131.php USER all|unread [oldest]
declare(strict_types=1);
use App\Enum\EntryView;
use App\Enum\ListOrder;
use App\Repository\EntryListRepository;
use App\Repository\EntryQuery;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\Compiler\PassConfig;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\Dotenv\Dotenv;

require '/app/vendor/autoload.php';
(new Dotenv())->bootEnv('/app/.env');

final class RecKernel extends App\Kernel {
    public function getProjectDir(): string { return '/app'; }
    public function getCacheDir(): string { return '/tmp/rec2131-cache/' . $this->environment; }
    protected function build(ContainerBuilder $container): void {
        parent::build($container);
        $container->addCompilerPass(new class implements CompilerPassInterface {
            public function process(ContainerBuilder $c): void {
                foreach ([EntryListRepository::class, 'doctrine.debug_data_holder'] as $id) {
                    if ($c->hasDefinition($id)) { $c->getDefinition($id)->setPublic(true); }
                    if ($c->hasAlias($id)) { $c->getAlias($id)->setPublic(true); }
                }
            }
        }, PassConfig::TYPE_BEFORE_OPTIMIZATION);
    }
}
$kernel = new RecKernel('dev', true);
$kernel->boot();
$repo = $kernel->getContainer()->get(EntryListRepository::class);
$holder = $kernel->getContainer()->get('doctrine.debug_data_holder');
$view = $argv[2] === 'unread' ? EntryView::Unread : EntryView::All;
$order = ($argv[3] ?? 'newest') === 'oldest' ? ListOrder::OldestFirst : ListOrder::NewestFirst;
$holder->reset();
$rows = $repo->listForUser(new EntryQuery((int) $argv[1], $view, order: $order));
fprintf(STDERR, "rows=%d\n", count($rows));
foreach ($holder->getData() as $queries) {
    foreach ($queries as $q) {
        $params = $q['params'] ?? [];
        $i = 0;
        $sql = preg_replace_callback('/\?/', function () use (&$i, $params) {
            $v = $params[$i++] ?? null;
            if ($v instanceof \DateTimeInterface) return "'" . $v->format('Y-m-d H:i:s') . "'";
            if (is_bool($v)) return $v ? '1' : '0';
            if (is_int($v) || is_float($v)) return (string) $v;
            if (is_array($v)) return implode(',', array_map(fn($x) => is_int($x) ? (string) $x : "'" . addslashes((string) $x) . "'", $v));
            if ($v === null) return 'NULL';
            return "'" . addslashes((string) $v) . "'";
        }, $q['sql']);
        printf("-- %.2fms\n%s;\n", ($q['executionMS'] ?? 0) * 1000, $sql);
    }
}
```
`getProjectDir()` must point at `/app`. Without it, `ReaderOutputVersionPass` looks under `/tmp/src` and the boot fails.

`$SCRATCH/ea.sh` prints the warm top-level time and rows of a statement file:

```bash
#!/bin/bash
# ea.sh FILE [runs]: first run discarded
cd /Users/lars/Documents/work/eigenes/simple-feed-reader
runs=${2:-3}; out=""
for i in $(seq 0 $runs); do
  t=$({ printf 'EXPLAIN ANALYZE '; cat "$1"; } \
    | docker compose exec -T mysql sh -c 'mysql --comments -N -r -uroot -p"$MYSQL_ROOT_PASSWORD" $MYSQL_DATABASE' 2>/dev/null \
    | head -1 | grep -oE 'actual time=[0-9.]+\.\.[0-9.]+ rows=[0-9]+' | head -1 \
    | sed -E 's/actual time=[0-9.]+\.\.([0-9.]+) rows=([0-9]+)/\1ms(\2)/')
  [ $i -gt 0 ] && out="$out $t"
done
echo "$(basename "$1"):$out"
```

`$SCRATCH/caught.php` turns a recorded unread statement into its caught-up form. `$SCRATCH/gap.php` adds a backlog block on top:

```php
<?php
// caught.php RECORDED.sql T edge|full hint|nohint
[$_, $file, $t, $mode, $hint] = $argv;
$sql = null;
foreach (file($file) as $line) { if (str_starts_with($line, 'SELECT /*+ JOIN_PREFIX(e4_) */')) { $sql = trim($line); break; } }
$sql = preg_replace_callback('/\(s(\d+)_\.marked_read_until IS NULL OR e(\d+)_\.effective_date > s\1_\.marked_read_until\)/',
    fn ($m) => "e{$m[2]}_.effective_date > GREATEST('$t', COALESCE(s{$m[1]}_.marked_read_until, '$t'))", $sql);
if ($mode === 'full') { $sql = preg_replace("/ AND e4_\.effective_date (>=|<=) '[^']+'/", '', $sql); }
if ($hint === 'nohint') { $sql = str_replace('/*+ JOIN_PREFIX(e4_) */ ', '', $sql); }
echo $sql, "\n";
```

```php
<?php
// gap.php CAUGHT.sql FROM TO
[$_, $file, $from, $to] = $argv;
echo preg_replace("/(e(\d+)_\.effective_date > GREATEST\('[^']+', COALESCE\(s\d+_\.marked_read_until, '[^']+'\)\))/",
    "($1 OR e$2_.effective_date BETWEEN '$from' AND '$to')", trim(file_get_contents($file))), "\n";
```

Copy the recorder in:

```bash
chmod +x "$SCRATCH/ea.sh"
docker compose cp "$SCRATCH/rec2131.php" php:/tmp/rec2131.php
```

- [ ] **Step 4: Record, then measure the before matrix**

```bash
cd /Users/lars/Documents/work/eigenes/simple-feed-reader
q() { docker compose exec -T mysql sh -c 'mysql -N -uroot -p"$MYSQL_ROOT_PASSWORD" $MYSQL_DATABASE' 2>/dev/null; }
for u in 2 61 88 144; do
  docker compose exec -T php php /tmp/rec2131.php $u unread > "$SCRATCH/u$u.sql"
  docker compose exec -T php php /tmp/rec2131.php $u all > "$SCRATCH/a$u.sql"
  T=$(echo "SELECT e.effective_date FROM entry e JOIN subscription s ON s.feed_id=e.feed_id AND s.user_id=$u WHERE s.include_in_all_items=1 ORDER BY e.effective_date DESC LIMIT 1 OFFSET 10;" | q)
  echo "$T" > "$SCRATCH/T$u"
  php "$SCRATCH/caught.php" "$SCRATCH/u$u.sql" "$T" full hint   > "$SCRATCH/c$u-hinted.sql"
  php "$SCRATCH/caught.php" "$SCRATCH/u$u.sql" "$T" full nohint > "$SCRATCH/c$u-plain.sql"
  php "$SCRATCH/caught.php" "$SCRATCH/u$u.sql" "$T" edge hint   > "$SCRATCH/c$u-window.sql"
done
TO=$(echo "SELECT effective_date FROM entry ORDER BY effective_date DESC LIMIT 1 OFFSET 3000;" | q)
FROM=$(echo "SELECT effective_date FROM entry ORDER BY effective_date DESC LIMIT 1 OFFSET 13000;" | q)
for u in 61 144; do for v in hinted plain; do
  php "$SCRATCH/gap.php" "$SCRATCH/c$u-$v.sql" "$FROM" "$TO" > "$SCRATCH/g$u-$v.sql"
done; done
sed -n 2p "$SCRATCH/a2.sql" > "$SCRATCH/d2-all-probe.sql";    sed -n 4p "$SCRATCH/a2.sql" > "$SCRATCH/d2-all-page.sql"
sed -n 2p "$SCRATCH/u2.sql" > "$SCRATCH/d2-unread-probe.sql"; sed -n 4p "$SCRATCH/u2.sql" > "$SCRATCH/d2-unread-page.sql"
{ for f in d2-all-probe d2-all-page d2-unread-probe d2-unread-page \
           c61-window c61-hinted c61-plain c144-window c144-hinted c144-plain \
           c88-window c88-hinted c2-window c2-hinted g61-hinted g61-plain g144-hinted g144-plain; do
    "$SCRATCH/ea.sh" "$SCRATCH/$f.sql" 3
  done; } | tee "$SCRATCH/before.txt"
```
Before trusting each file, read it:
- **`u$u.sql`:** line 2 is the window probe, line 4 the windowed hinted page and line 6 the duplicate lookup. Every reader's real window fills today (3 statements).
- **`c*`:** each holds exactly two `GREATEST(` substitutions, one for the main query and one for the collapse.

Expected shape:
- d2 page 1.2–1.4 ms
- c61/c144 hinted ≈ 2.1–2.3 s against plain ≈ 0.4–0.55 s
- g61/g144 hinted 25–40 ms against plain 330–550 ms

---

### Task 2: A caught-up mid-size scope walks a wide window, then falls back plain

**Files:**
- Modify: `backend/src/Repository/DateOrderedPage.php`
- Modify: `backend/src/Repository/DateOrderedQueries.php:13`
- Test: `backend/tests/Repository/EntryListTest.php`

**Interfaces:**
- Consumes these, all existing:
  - `EntryListTest::recordedList()`, `entryIn(Feed, string, string)`, `fillerFeed()`, `hidden(Entry)`, `dayToward(ListOrder, int)`, `guids()`
  - the `bothOrders()` data provider
  - `$this->feed`, `$this->user`, `$this->entityManager`
- Produces:
  - `DateOrderedPage::__construct(int $windowSize = DEFAULT_WINDOW_SIZE, int $wideWindowSize = DEFAULT_WIDE_WINDOW_SIZE, int $plainScopeLimit = DEFAULT_PLAIN_SCOPE_LIMIT)`
  - `public const int DEFAULT_WIDE_WINDOW_SIZE = 20000`
  - `public const int DEFAULT_PLAIN_SCOPE_LIMIT = 20000`
  - `rows()` keeps its signature
  - test helper `EntryListTest::repositoryWith(DateOrderedPage): EntryListRepository`

The statement sequence of a short, unnarrowed All/Unread page after this task:

| # | statement | when |
|---|---|---|
| 0 | window probe `… LIMIT 1 OFFSET K` | always |
| 1 | hinted page, edge K | edge found |
| 2 | scope probe `LIMIT 1 OFFSET K` | page 1 short |
| 3 | scope probe `LIMIT 1 OFFSET N` | probe 2 found an entry |
| 4 | window probe `… LIMIT 1 OFFSET W` | probe 3 found none |
| 5 | hinted page, edge W | probe 4 found an edge (else: hinted page without edge, the end) |
| 6 | plain page | page 5 short |

- [ ] **Step 1: Give the test a repository built from a given `DateOrderedPage`**

In `EntryListTest`, rename `repositoryWithWindow(int $windowSize)` to `repositoryWith(DateOrderedPage $dateOrderedPage)`:
- Pass `$dateOrderedPage` where it does `new DateOrderedPage($windowSize)`.
- Its docblock reads: `A repository wired with the given windows, so the fan-in probe/window branches can be forced through a handful of fixture rows instead of the production sizes.`

Then add back the old helper on top of it:

```php
    private function repositoryWithWindow(int $windowSize): EntryListRepository
    {
        return $this->repositoryWith(new DateOrderedPage($windowSize));
    }
```
Every existing `repositoryWithWindow(2)` caller now gets the production wide window and scope limit (20,000), so only the tests below need the small ones.

- [ ] **Step 2: Replace the caught-up test and add the wide-window tests**

Replace `testACaughtUpReaderWithAScopeBeyondTheWindowFallsBackToTheHintedWalk` (same place, after `testAReaderWithoutSubscriptionsGetsTheUnhintedPlanNextToABusyFeed`) with these four tests. They use the distinct sizes K = 2, W = 5, N = 4, so a mutant that swaps two sizes changes a statement's `OFFSET`:

```php
    #[DataProvider('bothOrders')]
    public function testACaughtUpReaderWithAScopeBeyondThePlainScopeLimitKeepsTheHintedWalk(ListOrder $order): void
    {
        foreach (range(10, 13) as $day) {
            $read = $this->entryIn($this->feed, 'read-' . $day, $this->dayToward($order, $day));
            $this->entityManager->persist($this->hidden($read));
        }
        $this->entryIn($this->feed, 'unread', $this->dayToward($order, 2));
        $this->entityManager->flush();
        $query = new EntryQuery($this->user->requireId(), EntryView::Unread, limit: 2, order: $order);

        $recorded = $this->recordedList($this->repositoryWith(new DateOrderedPage(2, 5, 4)), $query);

        self::assertCount(5, $recorded['queries'], 'a scope beyond the plain limit skips the wide window');
        self::assertStringContainsString('LIMIT 1 OFFSET 2', $recorded['queries'][2], 'the scope probe at the window');
        self::assertStringContainsString('LIMIT 1 OFFSET 4', $recorded['queries'][3], 'the scope probe at the limit');
        self::assertStringNotContainsString('JOIN_PREFIX', $recorded['queries'][3], 'the scope probe is never hinted');
        self::assertStringContainsString('JOIN_PREFIX', $recorded['queries'][4], 'a big scope stays hinted');
        self::assertSame(['unread'], $this->guids($recorded['rows']));
    }

    #[DataProvider('bothOrders')]
    public function testACaughtUpReaderWithinThePlainScopeLimitTriesTheWideWindowThenFallsBackPlain(
        ListOrder $order,
    ): void {
        foreach (range(11, 13) as $day) {
            $read = $this->entryIn($this->feed, 'read-' . $day, $this->dayToward($order, $day));
            $this->entityManager->persist($this->hidden($read));
        }
        $filler = $this->fillerFeed();
        foreach (range(8, 10) as $day) {
            $this->entryIn($filler, 'filler-' . $day, $this->dayToward($order, $day));
        }
        $this->entryIn($this->feed, 'unread', $this->dayToward($order, 2));
        $this->entityManager->flush();
        $query = new EntryQuery($this->user->requireId(), EntryView::Unread, limit: 2, order: $order);

        $recorded = $this->recordedList($this->repositoryWith(new DateOrderedPage(2, 5, 4)), $query);

        self::assertCount(7, $recorded['queries'], 'a short wide window falls back to a seventh, plain query');
        self::assertStringContainsString('LIMIT 1 OFFSET 5', $recorded['queries'][4], 'the wide window probe');
        self::assertStringContainsString('JOIN_PREFIX', $recorded['queries'][5], 'the wide window is hinted');
        self::assertStringNotContainsString('JOIN_PREFIX', $recorded['queries'][6], 'the fallback is unhinted');
        self::assertSame(['unread'], $this->guids($recorded['rows']));
    }

    #[DataProvider('bothOrders')]
    public function testUnreadEntriesJustPastTheWindowComeFromTheHintedWideWindow(ListOrder $order): void
    {
        foreach ([13, 12] as $day) {
            $read = $this->entryIn($this->feed, 'read-' . $day, $this->dayToward($order, $day));
            $this->entityManager->persist($this->hidden($read));
        }
        $filler = $this->fillerFeed();
        foreach ([11, 10] as $day) {
            $this->entryIn($filler, 'filler-' . $day, $this->dayToward($order, $day));
        }
        $this->entryIn($this->feed, 'unread-9', $this->dayToward($order, 9));
        $this->entryIn($this->feed, 'unread-8', $this->dayToward($order, 8));
        $this->entityManager->flush();
        $query = new EntryQuery($this->user->requireId(), EntryView::Unread, limit: 2, order: $order);

        $recorded = $this->recordedList($this->repositoryWith(new DateOrderedPage(2, 5, 4)), $query);

        self::assertCount(6, $recorded['queries'], 'a full wide window never falls back');
        self::assertStringContainsString('JOIN_PREFIX', $recorded['queries'][5], 'the wide window is hinted');
        self::assertSame(['unread-9', 'unread-8'], $this->guids($recorded['rows']));
    }

    #[DataProvider('bothOrders')]
    public function testAWideWindowBeyondTheTableWalksTheRestHinted(ListOrder $order): void
    {
        foreach (range(11, 13) as $day) {
            $read = $this->entryIn($this->feed, 'read-' . $day, $this->dayToward($order, $day));
            $this->entityManager->persist($this->hidden($read));
        }
        $this->entryIn($this->feed, 'unread', $this->dayToward($order, 2));
        $this->entityManager->flush();
        $query = new EntryQuery($this->user->requireId(), EntryView::Unread, limit: 2, order: $order);

        $recorded = $this->recordedList($this->repositoryWith(new DateOrderedPage(2, 10, 4)), $query);

        self::assertCount(6, $recorded['queries'], 'no wide edge means one hinted walk over the few rows left');
        self::assertStringContainsString('JOIN_PREFIX', $recorded['queries'][5]);
        self::assertSame(['unread'], $this->guids($recorded['rows']));
    }
```

Fixture arithmetic, so a reviewer can check it. Table rows are newest-first; the oldest-first runs are the mirror image through `dayToward()`.
- **Beyond-limit:**
  - Own scope has 5 entries.
  - The window probe at offset 2 lands on day 11. The page there is short: nothing unread on or after day 11.
  - Scope probe 2 finds an entry, and scope probe 4 finds the 5th.
  - Hinted walk.
- **Within-limit:**
  - Own scope has 4 entries; the table has 7: days 13, 12, 11, 10, 9, 8 and 2.
  - The window edge is day 11, so the page is short.
  - Scope probe 2 finds an entry; scope probe 4 finds none.
  - The wide probe at offset 5 lands on day 8. The page there is short.
  - Plain.
- **Gap:**
  - Own scope has 4 entries; the table has 6: days 13, 12, 11, 10, 9 and 8.
  - The edge is day 11, so the page is short.
  - Scope probe 2 finds an entry; scope probe 4 finds none.
  - The wide edge (offset 5) is day 8, so the page holds unread-9 and unread-8 and is full.
- **Beyond-table:**
  - The table has 4 rows, so the wide probe at offset 10 returns null.
  - Hinted page without an edge.

The existing `testAFeedExcludedFromAllItemsDoesNotCountTowardTheScope`, `testASparseReader…` and `testAReaderWithoutSubscriptions…` keep `repositoryWithWindow(2)` and their 4-statement expectations, because their scope fits K, so scope probe 2 finds nothing.

- [ ] **Step 3: Run the new tests and watch them fail**

```bash
cd /Users/lars/Documents/work/eigenes/simple-feed-reader/backend
php bin/phpunit tests/Repository/EntryListTest.php --filter 'BeyondThePlainScopeLimit|WithinThePlainScopeLimit|JustPastTheWindow|WideWindowBeyondTheTable'
```
Expected: 8 failures (4 tests × 2 orders), each on the first `assertCount`, with "actual size 4". PHP ignores the extra constructor arguments, so today's code probes at K, finds an entry and runs the hinted walk.

- [ ] **Step 4: Implement the four-way fallback in `DateOrderedPage`**

Replace the class from the docblock down to the end of `scopeOutgrowsTheWindow()`, and the `windowEdge()` method, in `backend/src/Repository/DateOrderedPage.php` with the following. `windowed()`, `hintedResult()` and `plainResult()` stay as they are.

```php
/**
 * Runs a fan-in page; a chronological fan-in is walked hinted inside a window. A short window falls back unhinted,
 * except an all/unread scope beyond the window, which first tries a wider window or, too big to sort, the full walk.
 */
final readonly class DateOrderedPage
{
    public const int DEFAULT_WINDOW_SIZE = 2000;
    public const int DEFAULT_WIDE_WINDOW_SIZE = 20000;
    public const int DEFAULT_PLAIN_SCOPE_LIMIT = 20000;

    public function __construct(
        private int $windowSize = self::DEFAULT_WINDOW_SIZE,
        private int $wideWindowSize = self::DEFAULT_WIDE_WINDOW_SIZE,
        private int $plainScopeLimit = self::DEFAULT_PLAIN_SCOPE_LIMIT,
    ) {
    }

    /**
     * @return list<array<array-key, mixed>>
     */
    public function rows(EntryQuery $query, DateOrderedQueries $queries): array
    {
        if (!$query->isDateOrderedFanIn()) {
            return $this->plainResult(($queries->page)());
        }

        return $this->windowedRows($query, $queries);
    }

    /**
     * @return list<array<array-key, mixed>>
     */
    private function windowedRows(EntryQuery $query, DateOrderedQueries $queries): array
    {
        $windowEdge = $this->windowEdge($queries->windowProbe, $this->windowSize);
        if ($windowEdge === null) {
            return $this->hintedResult(($queries->page)());
        }

        $windowed = $this->hintedResult($this->windowed(($queries->page)(), $windowEdge, $query->order));
        if (\count($windowed) === $query->limit) {
            return $windowed;
        }

        return $this->fallbackRows($query, $queries);
    }

    /**
     * @return list<array<array-key, mixed>>
     */
    private function fallbackRows(EntryQuery $query, DateOrderedQueries $queries): array
    {
        if ($query->isNarrowed() || !$this->scopeReaches($queries->scopeProbe, $this->windowSize)) {
            return $this->plainResult(($queries->page)());
        }
        if ($this->scopeReaches($queries->scopeProbe, $this->plainScopeLimit)) {
            return $this->hintedResult(($queries->page)());
        }

        return $this->wideWindowedRows($query, $queries);
    }

    /**
     * @return list<array<array-key, mixed>>
     */
    private function wideWindowedRows(EntryQuery $query, DateOrderedQueries $queries): array
    {
        $wideEdge = $this->windowEdge($queries->windowProbe, $this->wideWindowSize);
        if ($wideEdge === null) {
            return $this->hintedResult(($queries->page)());
        }

        $windowed = $this->hintedResult($this->windowed(($queries->page)(), $wideEdge, $query->order));
        if (\count($windowed) === $query->limit) {
            return $windowed;
        }

        return $this->plainResult(($queries->page)());
    }

    /**
     * @param \Closure(): QueryBuilder $scopeProbe
     */
    private function scopeReaches(\Closure $scopeProbe, int $entryCount): bool
    {
        $beyondTheCount = $scopeProbe()
            ->setFirstResult($entryCount)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        return $beyondTheCount !== null;
    }

    /**
     * The effectiveDate $windowSize rows beyond the cursor in the page's own order, or null when fewer lie beyond it.
     *
     * @param \Closure(): QueryBuilder $windowProbe
     */
    private function windowEdge(\Closure $windowProbe, int $windowSize): ?\DateTimeImmutable
    {
        /** @var array{effectiveDate: \DateTimeImmutable}|null $pivot */
        $pivot = $windowProbe()
            ->setFirstResult($windowSize)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        return $pivot === null ? null : $pivot['effectiveDate'];
    }
```

`scopeReaches($probe, $count)` is true when the scope holds **more** than `$count` entries, because it reads the row at offset `$count`. That is the #2126 semantics, kept.

`windowedRows()` and `wideWindowedRows()` share four lines but end differently: the first in `fallbackRows()`, the second in `plainResult()`. Two occurrences stay inline (DRY triggers at the third). Do not fold them behind a callable or a nullable return.

In `backend/src/Repository/DateOrderedQueries.php`, change the `$page` line to:

```php
     * @param \Closure(): QueryBuilder $page        the complete page query; called once per attempt
```

- [ ] **Step 5: Run the repository tests on SQLite**

```bash
cd /Users/lars/Documents/work/eigenes/simple-feed-reader/backend
php bin/phpunit tests/Repository/EntryListTest.php tests/Repository/EntryQueryTest.php
```
Expected: all pass. That includes the narrowed tests (`testADenseFeedGroupWithAShortWindowFallsBackPlainWithoutAScopeProbe`, `testUnreadViewOnADenseTagWhoseNewestEntriesAreAllReadFallsBack`, the tag and network window tests), which prove #1099 is untouched.

- [ ] **Step 6: Run the same tests on the MySQL leg**

```bash
cd /Users/lars/Documents/work/eigenes/simple-feed-reader
docker compose exec -T -e TEST_TOKEN=1 php bin/phpunit tests/Repository/EntryListTest.php tests/Repository/EntryQueryTest.php
```
Expected: all pass.

- [ ] **Step 7: Break-test the guard, restoring without git**

Copy `DateOrderedPage.php` to `$SCRATCH/DateOrderedPage.php.bak`. In `wideWindowedRows()`, change `return $this->plainResult(($queries->page)());` to `return $this->hintedResult(($queries->page)());`, then re-run the Step 3 command. Expected: `testACaughtUpReaderWithinThePlainScopeLimitTriesTheWideWindowThenFallsBackPlain` fails in both orders, on "the fallback is unhinted". Restore with `cp "$SCRATCH/DateOrderedPage.php.bak" backend/src/Repository/DateOrderedPage.php`, never with `git checkout`, and confirm with `git diff --stat` that only the intended change remains.

- [ ] **Step 8: Commit**

```bash
cd /Users/lars/Documents/work/eigenes/simple-feed-reader
git add backend/src/Repository/DateOrderedPage.php backend/src/Repository/DateOrderedQueries.php \
  backend/tests/Repository/EntryListTest.php
git commit -m "fix(#2131): a caught-up scope under 20k entries tries a wide hinted window, then the plain plan

A caught-up reader's plain plan beats the hinted full walk up to ~0.6 x the entry table (70-75k of
120.5k on the dev DB), but a reader whose unread lie just past the window gets them from a hinted
walk in 25-170 ms against 330-600 ms plain. So a short all/unread window over a scope between the
window and 20k entries first tries a 20k-row hinted window, and only a short wide window goes plain."
```

---

### Task 3: Gates

**Files:** none, unless a gate points at a touched file.

- [ ] **Step 1: Static gates** (from `backend/`; these may run in parallel)

```bash
cd /Users/lars/Documents/work/eigenes/simple-feed-reader/backend
composer cs
bin/console cache:warmup && composer stan
composer md
composer tramp
```
Expected:
- All clean, and `composer md` reports nothing for `src/Repository/DateOrderedPage.php` or `src/Repository/DateOrderedQueries.php`.
- **If PHPMD flags `DateOrderedPage`:** fix the design it points at. Do not split the class to dodge a count; the commit hook denies a new `src` class while a finding is open.
- **`composer tramp`:** `$query`/`$queries` travel through `windowedRows → fallbackRows → wideWindowedRows` inside one class, below `minClasses: 2`, so no finding is expected. If one appears, run `composer show larspohlmann/phptramp` first.

Run the PhpStorm inspections (`mcp__phpstorm__lint_files`) on the three changed files; block on ERROR and WARNING.

- [ ] **Step 2: Both suite legs** (run them at the same time)

```bash
cd /Users/lars/Documents/work/eigenes/simple-feed-reader/backend && php bin/phpunit
cd /Users/lars/Documents/work/eigenes/simple-feed-reader && docker compose exec php composer test:parallel
```
Expected: both green. If MySQL shows a single unexplained failure, re-run once and name the failing test. Memory notes a rate-limiter flake, so do not assume it.

- [ ] **Step 3: Mutation gate**

```bash
cd /Users/lars/Documents/work/eigenes/simple-feed-reader/backend && composer infection:diff
```
Expected: at or above `minMsi`, with every mutant in `DateOrderedPage` killed:
- The size swaps die on the `OFFSET 2/4/5` assertions.
- `||`→`&&` in `fallbackRows()` dies on the narrowed tag and feed-group tests plus the sparse tests.
- `=== null`→`!== null` on the wide edge dies on the beyond-table and gap tests.
- `=== $query->limit` in the wide method dies on the gap and within-limit tests.

Never lower `minMsi`; an escaped mutant gets a test.

- [ ] **Step 4: Scan the dev log**

```bash
cd /Users/lars/Documents/work/eigenes/simple-feed-reader/backend
ls -t var/log/dev-*.log | head -1 | xargs tail -n 300 | jq -c 'select(.level_name=="ERROR" or .level_name=="CRITICAL" or .level_name=="WARNING") | {datetime, channel, message}'
```
Expected: nothing new from the entry list.

---

### Task 4: After measurement on the dev DB and the before/after table

**Files:**
- Modify: `docs/superpowers/plans/2026-11-09-2131-caught-up-readers-skip-hinted-walk.md` (append an "As shipped" section with the table)

- [ ] **Step 1: Serve the new code**

```bash
cd /Users/lars/Documents/work/eigenes/simple-feed-reader
docker compose exec -T php bin/console cache:clear
docker compose exec -T php rm -r /tmp/rec2131-cache
docker compose cp "$SCRATCH/rec2131.php" php:/tmp/rec2131.php
```
The recorder boots its own kernel cache, so remove the old one or it serves the pre-change container.

- [ ] **Step 2: Confirm that dense readers still issue the #2126 statements**

```bash
for u in 2 88 61 144; do
  docker compose exec -T php php /tmp/rec2131.php $u unread > "$SCRATCH/after-u$u.sql"
  grep -c '^-- ' "$SCRATCH/after-u$u.sql"
done
```
Expected: 3 statements each (window probe, windowed page, duplicate lookup), byte-identical to `u$u.sql` apart from the edge date. A real window that fills never reaches the new code.

- [ ] **Step 3: Measure the new fallback statements**

The fallback statements are the Task 1 caught-up statements plus three new ones: the scope probe at 20,000, the wide window probe and the wide windowed page. Build them:

```bash
q() { docker compose exec -T mysql sh -c 'mysql -N -uroot -p"$MYSQL_ROOT_PASSWORD" $MYSQL_DATABASE' 2>/dev/null; }
WIDE=$(echo "SELECT effective_date FROM entry ORDER BY effective_date DESC, id DESC LIMIT 1 OFFSET 20000;" | q)
sed 's/OFFSET 2000/OFFSET 20000/' "$SCRATCH/d2-unread-probe.sql" > "$SCRATCH/wide-probe.sql"
for u in 2 61 88 144; do
  for offset in 2000 20000; do
    echo "SELECT e0_.id AS id_0 FROM entry e0_ INNER JOIN subscription s1_ ON (s1_.feed_id = e0_.feed_id AND s1_.user_id = $u) WHERE s1_.include_in_all_items = 1 LIMIT 1 OFFSET $offset;" > "$SCRATCH/scope$u-$offset.sql"
  done
done
for u in 61 144; do
  sed -E "s/AND e4_\.effective_date >= '[^']+'/AND e4_.effective_date >= '$WIDE'/" "$SCRATCH/c$u-window.sql" > "$SCRATCH/wide$u.sql"
  sed -E "s/ ORDER BY e4_\.effective_date DESC/ AND e4_.effective_date >= '$WIDE' ORDER BY e4_.effective_date DESC/" "$SCRATCH/g$u-hinted.sql" > "$SCRATCH/widegap$u.sql"
done
{ for f in d2-all-probe d2-all-page d2-unread-probe d2-unread-page wide-probe \
           scope2-2000 scope2-20000 scope88-2000 scope88-20000 scope61-2000 scope61-20000 scope144-2000 scope144-20000 \
           c2-window c2-hinted c88-window c88-hinted c61-window wide61 c61-plain c144-window wide144 c144-plain \
           g61-hinted widegap61 g144-hinted widegap144; do
    "$SCRATCH/ea.sh" "$SCRATCH/$f.sql" 3
  done; } | tee "$SCRATCH/after.txt"
```
Before trusting the scope-probe lines, check that the SQL matches what the code now issues. Run `php bin/phpunit tests/Repository/EntryListTest.php --filter BeyondThePlainScopeLimit` with a temporary `var_dump($recorded['queries'])`, then remove it. Alternatively, read the recorder output for a sparse reader such as user 58: its fourth statement is `SELECT e0_.id … LIMIT 1 OFFSET 2000`.

Pass criteria (sum each path's statements):

| case | path | must be |
|---|---|---|
| dense all / unread (2) | window probe + page | ≤ 1.65 / 2.14 ms + 1 ms noise (#2126) |
| caught-up dense (2) | probe + window + scope@2000 + scope@20000 + hinted walk | ≤ 2,343 ms + 1% (#2126 ≈ 2,343) |
| caught-up 73.8k (88) | same | ≈ Task 1 hinted + ≤ 5 ms |
| caught-up 12.4k (61), 15.9k (144) | probe + window + scope@2000 + scope@20000 + wide probe + wide page + plain | ≤ 800 ms (Task 1: ≈ 2.1–2.3 s) |
| gap reader (61, 144; unread 3k rows past the top) | probe + window + scope@2000 + scope@20000 + wide probe + wide gap page | ≤ Task 1 hinted gap total + 15 ms |
| sparse (0-subscription user, e.g. 58) | unchanged statements | as #2126 (≈ 9–10 ms) |

If a pass criterion fails, stop and report both tables to Lars before changing a constant.

- [ ] **Step 4: Record the table and clean up**

Append to this plan, after the Research section:

```markdown
## As shipped

| case | #2126 (Task 1, ms) | #2131 (Task 4, ms) |
|---|---|---|
| dense all / unread (user 2) | … | … |
| caught-up dense (user 2, 83.8k) | … | … |
| caught-up 73.8k (user 88) | … | … |
| caught-up 15.9k (user 144) | … | … |
| caught-up 12.4k (user 61) | … | … |
| gap reader (user 61 / 144, unread 3k rows past the top) | … | … |
| sparse, 0 subscriptions | … | … |
```
Fill every cell with the summed warm numbers from `before.txt` and `after.txt`; leave no "…". Then:

```bash
cd /Users/lars/Documents/work/eigenes/simple-feed-reader
docker compose exec -T php rm /tmp/rec2131.php
docker compose exec -T php rm -r /tmp/rec2131-cache
git add docs/superpowers/plans/2026-11-09-2131-caught-up-readers-skip-hinted-walk.md
git commit -m "fix(#2131): record the before/after measurements in the plan"
```
Put the same table in the PR body, which ends `Closes #2131`.

## Known risks (measured, accepted)

- **Gap readers whose 50th unread lies between about 20k and 45k table rows past the cursor** get the wide window (≈160 ms) and then plain, instead of a hinted walk that would stop there. User 61 with S = 20k: ≈195 ms becomes ≈595 ms. Past about 45k rows the new path wins (S = 60k: ≈1,070 becomes ≈595).
- **Caught-up readers with 20k–70k scope entries** keep the hinted full walk (≈2.2 s), as today. No real reader sits there; synthetic subsets show plain would save 0.3–1.5 s. Raise `DEFAULT_PLAIN_SCOPE_LIMIT` only with a fresh crossover measurement, because the crossover is a share of the table (≈0.6 ×), not a fixed count.
- **Both 20,000 constants were tuned on a 120.5k-row table.** A much smaller table (under about 35k) moves the crossover below N; re-measure there (Strato) before relying on these numbers.
