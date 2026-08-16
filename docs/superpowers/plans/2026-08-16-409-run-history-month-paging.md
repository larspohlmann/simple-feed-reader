# Month-Paged Run History Implementation Plan (#409, second pass)

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Replace the run history's flat capped list with collapsible month sections carrying their own run count and total spend, paged within a month, and render cost as `$ 0.00137` instead of a credits figure.

**Architecture:** Two routes — an overview returning every month's summary plus the newest month's first page in one round trip, and a per-month route for expanding and paging. Month buckets are computed in PHP over a two-scalar projection, because DQL has no portable month extraction and the buckets must be in the viewer's timezone while the stored value is naive UTC. History reads move to their own repository; the card splits into a stateful parent and a presentational month section.

**Tech Stack:** PHP 8.4 / Symfony 7.4 / Doctrine ORM 3.6, PHPUnit, Angular 20 standalone + signals, Transloco, Jest.

**Spec:** `docs/superpowers/specs/2026-08-16-run-history-month-paging-design.md`
(supersedes §7 and §8 of `2026-08-16-run-cost-history-design.md`)
**Issue:** https://github.com/larspohlmann/simple-feed-reader/issues/409
**PR:** https://github.com/larspohlmann/simple-feed-reader/pull/427 — this lands on the same branch, `feature/409-run-cost-history`

## Global Constraints

- Every PHP file starts with `declare(strict_types=1);`.
- `final readonly class` with constructor promotion is the house style; `final` unless designed for extension.
- Comments explain **why**, never **what**. Match the density of the file you are touching — this codebase's comments are long and justify decisions.
- Names reveal intent. No `$data`/`$info`/`$tmp`. No boolean flag parameters.
- Guard clauses over nesting. Functions do one thing at a single level of abstraction.
- Errors are typed exceptions namespaced next to their service (`Service/*/Exception/`), never `null` sentinels — except where a class already documents null as "absent".
- **Controllers hold no private method that carries responsibility** (`ThinControllerRule`, enforced by `composer stan`).
- Every `src` file you touch must be **PHPMD-clean** (`vendor/bin/phpmd <file> text codesize`), not merely free of new findings. Fix the design the metric points at; never tune a threshold.
- `composer tramp` is a CI gate: a value forwarded through a chain of methods none of them read fails the build. The fix is a context object, not a longer signature.
- **Datetimes are stored as naive UTC.** Doctrine persists wall-clock values, so any boundary compared against a column must be expressed in UTC wall clock first.
- Frontend: standalone components and signals, no NgModules. Component styles in a sibling `.scss` via `styleUrl`, never inline. **No hex colours, no raw `px` spacing, no media-query literals** outside `src/app/theme/`. Prettier at 100 columns.
- Dates render through `format.ts` helpers on `LanguageService.lang()` — `DatePipe` always renders `en-US` here.
- New i18n keys go into **both** `frontend/public/i18n/en.json` and `de.json`.
- **The standing architectural constraint:** keep a native Swift iOS client viable — bearer JWT, stateless, JSON in and `application/problem+json` out, no CSRF, no browser-only inputs.
- Commit after each task. Do not merge, push a tag, or deploy.

## File Structure

**Backend — created**

| Path | Responsibility |
|---|---|
| `backend/src/Service/Recommendation/ViewerTimeZone.php` | Fail-soft IANA timezone parse for a display preference |
| `backend/src/Service/Recommendation/MonthWindow.php` | `YYYY-MM` + viewer timezone → UTC half-open range |
| `backend/src/Service/Recommendation/Exception/UnknownHistoryMonthException.php` | A month string that names no month |
| `backend/src/Service/Recommendation/HistoryMonth.php` | One month's summary: month, run count, total spend |
| `backend/src/Service/Recommendation/HistoryMonthSummariser.php` | Spend timeline + viewer timezone → `list<HistoryMonth>` |
| `backend/src/Service/Recommendation/RecommendationRunHistoryView.php` | Orchestrates the repository calls behind each route |
| `backend/src/Repository/RecommendationRunHistoryRepository.php` | The five history queries |

**Backend — modified**

| Path | Change |
|---|---|
| `backend/src/Repository/RecommendationRunRepository.php` | `historyForUser()`, `totalCostNanoCredits()`, `HISTORY_LIMIT` move out |
| `backend/src/Http/RecommendationRunHistoryJson.php` | `overview()` and `monthPage()` mappers; `row()` unchanged |
| `backend/src/Controller/Api/RecommendationRunHistoryController.php` | Two actions, delegating to the view |

**Frontend — created**

| Path | Responsibility |
|---|---|
| `frontend/src/app/settings/recommendation-run-history-month.component.{ts,html,scss,spec.ts}` | One month section: header, rows, "show more" — presentational |

**Frontend — modified**

| Path | Change |
|---|---|
| `frontend/src/app/reader/format.ts` | `formatCost()` |
| `frontend/src/app/reader/format.spec.ts` | its tests |
| `frontend/src/app/reader/models.ts` | `RunHistoryMonth`, `RunHistoryMonthPage`, `RunHistoryOverview`; `RunHistoryPayload` deleted |
| `frontend/src/app/reader/reader-api.ts` | `runHistory(timeZone)`, `runHistoryMonth(...)` |
| `frontend/src/app/shared/disclosure/disclosure.component.{ts,html,spec.ts}` | `opened` output |
| `frontend/src/app/settings/recommendation-run-history.component.{ts,html,scss,spec.ts}` | Month sections, lazy load, dollar cost |
| `frontend/public/i18n/en.json`, `de.json` | `historyCostUnit` deleted, `historyTotal` reworded, month keys added |

---

### Task 1: Cost renders as a dollar figure

**Files:**
- Modify: `frontend/src/app/reader/format.ts`
- Test: `frontend/src/app/reader/format.spec.ts`
- Modify: `frontend/src/app/settings/recommendation-run-history.component.ts`
- Modify: `frontend/src/app/settings/recommendation-run-history.component.html`
- Modify: `frontend/src/app/settings/recommendation-run-history.component.scss`
- Modify: `frontend/src/app/settings/recommendation-run-history.component.spec.ts`
- Modify: `frontend/public/i18n/en.json`, `frontend/public/i18n/de.json`

**Interfaces:**
- Consumes: nothing.
- Produces: `formatCost(nanoCredits: number | null, locale: string): string` exported from `src/app/reader/format.ts`.

This task is self-contained and shippable on its own. It also moves the money
formatter to where Task 7 needs it: two components will render costs, and
`format.ts` is where this codebase already puts a formatter shared by two
callers (`bytesToKb` says so in its docblock).

- [ ] **Step 1: Write the failing tests**

Append to `frontend/src/app/reader/format.spec.ts`, following the file's existing
structure:

```ts
describe('formatCost', () => {
  it('renders a price the way the provider writes it in its own logs', () => {
    expect(formatCost(1_370_000, 'en')).toBe('$ 0.00137');
  });

  it('renders an em dash when the provider reported no price at all', () => {
    expect(formatCost(null, 'en')).toBe('—');
  });

  it('renders a cost of zero as zero rather than as unpriced', () => {
    expect(formatCost(0, 'en')).toBe('$ 0.00000');
  });

  it('keeps the symbol leading but the separator local', () => {
    expect(formatCost(1_370_000, 'de')).toBe('$ 0,00137');
  });

  it('rounds a sub-cent remainder to the nearest five-decimal figure', () => {
    expect(formatCost(1_374_700, 'en')).toBe('$ 0.00137');
    expect(formatCost(1_375_100, 'en')).toBe('$ 0.00138');
  });

  it('renders a large total without losing the fixed precision', () => {
    expect(formatCost(918_200_000, 'en')).toBe('$ 0.91820');
  });
});
```

Add `formatCost` to the file's existing import from `./format`.

- [ ] **Step 2: Run to verify it fails**

```bash
cd frontend && npx jest src/app/reader/format.spec.ts
```

Expected: FAIL — `formatCost is not a function`.

- [ ] **Step 3: Implement**

Append to `frontend/src/app/reader/format.ts`:

```ts
/** Nano-credits per credit. The API stores money as an integer -- floats do
 *  not hold money -- and this is the one place it becomes a human figure. */
const NANO_PER_CREDIT = 1_000_000_000;

/** How many decimals a price is worth reading at. Five is what the provider's
 *  own logs show, and it is fine enough that a single cheap run does not
 *  collapse to zero. */
const COST_FRACTION_DIGITS = 5;

/** What no reported price renders as. The provider said nothing about cost (a
 *  local model, or a run older than the column), which is a different
 *  statement from a cost of zero -- so it must not render as one, and it must
 *  not carry a currency symbol either. */
const NO_PRICE = '—';

/**
 * A price in nano-credits as the provider's own logs write it: `$ 0.00137`.
 *
 * The symbol always leads, the way the provider renders it. The number does
 * not: it goes through `Intl` on the active UI language, because `toFixed`
 * always writes a `.` and a German card showing `22. Juli 2026` beside
 * `0.00137` is two locales in one line. So German reads `$ 0,00137`.
 *
 * Shared rather than owned by the history card: the card renders the account
 * total and each month section renders its own, and a second copy of the
 * rounding would drift.
 */
export function formatCost(nanoCredits: number | null, locale: string): string {
  if (nanoCredits === null) return NO_PRICE;
  const credits = new Intl.NumberFormat(locale, {
    minimumFractionDigits: COST_FRACTION_DIGITS,
    maximumFractionDigits: COST_FRACTION_DIGITS,
  }).format(nanoCredits / NANO_PER_CREDIT);
  return `$ ${credits}`;
}
```

- [ ] **Step 4: Run to verify it passes**

```bash
cd frontend && npx jest src/app/reader/format.spec.ts
```

Expected: PASS.

- [ ] **Step 5: Swap the card over and drop the credits copy**

In `recommendation-run-history.component.ts`: delete the local `NANO_PER_CREDIT`
and `NO_PRICE` constants and the body of `cost()`, and delegate:

```ts
  /** The account's spend, and each run's, as the provider writes it. The
   *  formatting itself lives in `format.ts` -- the month sections render the
   *  same figure and a second copy of the rounding would drift. */
  cost(nanoCredits: number | null): string {
    return formatCost(nanoCredits, this.language.lang());
  }
```

Import `formatCost` alongside the existing `formatDateOr, formatTime`.

In `recommendation-run-history.component.html`: delete the whole
`<p class="run-history__unit">…</p>` element and its preceding comment — the
unit is in the figure now.

In `recommendation-run-history.component.scss`: delete the `&__unit` block.

In `frontend/public/i18n/en.json`: change `historyTotal` to `"Total spent"` and
**delete** `historyCostUnit`.
In `frontend/public/i18n/de.json`: change `historyTotal` to `"Gesamtkosten"` and
**delete** `historyCostUnit`.

In `recommendation-run-history.component.spec.ts`: update every cost assertion.
`0.0412` credits was `41_230_000` nano — it now renders `$ 0.04123`; the total
`918_200_000` renders `$ 0.91820`; the German assertions become `$ 0,04123` and
`$ 0,91820`. Delete any assertion on the removed `historyCostUnit` caption.

- [ ] **Step 6: Run the full frontend gate**

```bash
cd frontend && npm run check
```

Expected: exit 0. Grep the whole `frontend/src` and both dictionaries for
`historyCostUnit` and for the word `credits` in user-facing copy afterwards, and
confirm nothing is left.

- [ ] **Step 7: Commit**

```bash
git add frontend/src frontend/public/i18n
git commit -m "feat(#409): render run cost as a dollar figure, not credits"
```

---

### Task 2: `ViewerTimeZone` and `MonthWindow`

**Files:**
- Create: `backend/src/Service/Recommendation/ViewerTimeZone.php`
- Create: `backend/src/Service/Recommendation/MonthWindow.php`
- Create: `backend/src/Service/Recommendation/Exception/UnknownHistoryMonthException.php`
- Test: `backend/tests/Service/Recommendation/ViewerTimeZoneTest.php`
- Test: `backend/tests/Service/Recommendation/MonthWindowTest.php`

**Interfaces:**
- Consumes: nothing.
- Produces:
  - `ViewerTimeZone::of(?string $identifier): self` with `public \DateTimeZone $zone`
  - `MonthWindow::of(string $month, ViewerTimeZone $viewer): self` with
    `public string $month`, `public \DateTimeImmutable $startUtc`,
    `public \DateTimeImmutable $endUtc`
  - `UnknownHistoryMonthException extends \RuntimeException`

**Why two classes.** One resolves an untrusted display preference and fails
soft; the other converts a validated month into a database boundary and throws.
Different failure contracts, so different classes.

- [ ] **Step 1: Write the failing timezone tests**

`backend/tests/Service/Recommendation/ViewerTimeZoneTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Service\Recommendation;

use App\Service\Recommendation\ViewerTimeZone;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ViewerTimeZone::class)]
final class ViewerTimeZoneTest extends TestCase
{
    public function testTakesTheZoneTheClientNamed(): void
    {
        self::assertSame('Europe/Berlin', ViewerTimeZone::of('Europe/Berlin')->zone->getName());
    }

    public function testFallsBackToUtcWhenTheClientNamedNone(): void
    {
        self::assertSame('UTC', ViewerTimeZone::of(null)->zone->getName());
    }

    public function testFallsBackToUtcOnAZoneNoDatabaseKnows(): void
    {
        self::assertSame('UTC', ViewerTimeZone::of('Mars/Olympus_Mons')->zone->getName());
    }

    public function testFallsBackToUtcOnAnEmptyValue(): void
    {
        self::assertSame('UTC', ViewerTimeZone::of('')->zone->getName());
    }
}
```

- [ ] **Step 2: Write the failing window tests**

`backend/tests/Service/Recommendation/MonthWindowTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Service\Recommendation;

use App\Service\Recommendation\Exception\UnknownHistoryMonthException;
use App\Service\Recommendation\MonthWindow;
use App\Service\Recommendation\ViewerTimeZone;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(MonthWindow::class)]
final class MonthWindowTest extends TestCase
{
    public function testSpansTheMonthInUtcWhenTheViewerIsInUtc(): void
    {
        $window = MonthWindow::of('2026-08', ViewerTimeZone::of('UTC'));

        self::assertSame('2026-08', $window->month);
        self::assertSame('2026-08-01 00:00:00', $window->startUtc->format('Y-m-d H:i:s'));
        self::assertSame('2026-09-01 00:00:00', $window->endUtc->format('Y-m-d H:i:s'));
    }

    /**
     * The stored value is naive UTC, so a Berlin viewer's August starts two
     * hours before UTC midnight on 1 August and ends two hours before it on
     * 1 September — which is exactly why the boundary cannot be the literal
     * month string.
     */
    public function testShiftsTheBoundariesIntoUtcForAViewerAheadOfIt(): void
    {
        $window = MonthWindow::of('2026-08', ViewerTimeZone::of('Europe/Berlin'));

        self::assertSame('2026-07-31 22:00:00', $window->startUtc->format('Y-m-d H:i:s'));
        self::assertSame('2026-08-31 22:00:00', $window->endUtc->format('Y-m-d H:i:s'));
    }

    /** A month whose start and end sit on different sides of a DST change keeps
     *  local midnight at both ends rather than drifting an hour. */
    public function testKeepsLocalMidnightAcrossADaylightSavingChange(): void
    {
        $window = MonthWindow::of('2026-10', ViewerTimeZone::of('Europe/Berlin'));

        self::assertSame('2026-09-30 22:00:00', $window->startUtc->format('Y-m-d H:i:s'));
        self::assertSame('2026-10-31 23:00:00', $window->endUtc->format('Y-m-d H:i:s'));
    }

    public function testSpansDecemberIntoTheFollowingJanuary(): void
    {
        $window = MonthWindow::of('2026-12', ViewerTimeZone::of('UTC'));

        self::assertSame('2027-01-01 00:00:00', $window->endUtc->format('Y-m-d H:i:s'));
    }

    public function testBothBoundariesAreExpressedInUtc(): void
    {
        $window = MonthWindow::of('2026-08', ViewerTimeZone::of('Europe/Berlin'));

        self::assertSame('UTC', $window->startUtc->getTimezone()->getName());
        self::assertSame('UTC', $window->endUtc->getTimezone()->getName());
    }

    public function testRefusesAMonthNumberNoYearHas(): void
    {
        $this->expectException(UnknownHistoryMonthException::class);

        MonthWindow::of('2026-13', ViewerTimeZone::of('UTC'));
    }

    public function testRefusesAMonthNumberOfZero(): void
    {
        $this->expectException(UnknownHistoryMonthException::class);

        MonthWindow::of('2026-00', ViewerTimeZone::of('UTC'));
    }

    public function testRefusesSomethingThatIsNotAMonthAtAll(): void
    {
        $this->expectException(UnknownHistoryMonthException::class);

        MonthWindow::of('August', ViewerTimeZone::of('UTC'));
    }
}
```

Verify the two Berlin expectations against the real tz database before trusting
them — `php -r "…"` — and correct the literals if the offsets differ. Do not
adjust the implementation to match a wrong expectation.

- [ ] **Step 3: Run to verify both fail**

```bash
cd backend && php bin/phpunit tests/Service/Recommendation/ViewerTimeZoneTest.php tests/Service/Recommendation/MonthWindowTest.php
```

Expected: FAIL — classes not found.

- [ ] **Step 4: Implement**

`backend/src/Service/Recommendation/ViewerTimeZone.php`:

```php
<?php

declare(strict_types=1);

namespace App\Service\Recommendation;

/**
 * The timezone a client wants its run history bucketed by (#409).
 *
 * Runs are stored as naive UTC, but the card prints each row in the reader's
 * own zone, so the months have to be cut in that same zone — otherwise a run
 * at 23:30 UTC on 31 August prints as 1 September and files under August, and
 * the section header contradicts the row beneath it.
 *
 * Fails soft on purpose. This is a display preference, not a security
 * boundary: a client shipping a stale timezone database should see its history
 * bucketed in the wrong zone, not lose access to it. A plain IANA identifier,
 * so a native client sends it as readily as a browser does.
 */
final readonly class ViewerTimeZone
{
    private function __construct(public \DateTimeZone $zone)
    {
    }

    public static function of(?string $identifier): self
    {
        if (null === $identifier || '' === $identifier) {
            return new self(new \DateTimeZone('UTC'));
        }

        try {
            return new self(new \DateTimeZone($identifier));
        } catch (\Exception) {
            return new self(new \DateTimeZone('UTC'));
        }
    }
}
```

`backend/src/Service/Recommendation/Exception/UnknownHistoryMonthException.php`:

```php
<?php

declare(strict_types=1);

namespace App\Service\Recommendation\Exception;

/**
 * A month string that names no month. The history route's own requirement
 * rejects these before a controller sees them, so reaching this is a caller
 * mistake rather than a user one — but the value object states its contract
 * rather than trusting the route to be the only way in.
 */
final class UnknownHistoryMonthException extends \RuntimeException
{
}
```

`backend/src/Service/Recommendation/MonthWindow.php`:

```php
<?php

declare(strict_types=1);

namespace App\Service\Recommendation;

use App\Service\Recommendation\Exception\UnknownHistoryMonthException;

/**
 * One calendar month of a viewer's history, as a range the database can be
 * asked about (#409).
 *
 * Half-open — `>= startUtc AND < endUtc` — so a run at the very last instant
 * of a month cannot fall into both this window and the next one, and no
 * end-of-month arithmetic has to know how long February is.
 *
 * The boundaries come out in UTC because that is the wall clock Doctrine
 * persists: the month is cut in the viewer's zone, then expressed in the
 * zone the column is written in. Doing it the other way round buckets a
 * viewer's late-evening runs into the following month.
 */
final readonly class MonthWindow
{
    private function __construct(
        public string $month,
        public \DateTimeImmutable $startUtc,
        public \DateTimeImmutable $endUtc,
    ) {
    }

    /**
     * @throws UnknownHistoryMonthException
     */
    public static function of(string $month, ViewerTimeZone $viewer): self
    {
        if (1 !== preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month)) {
            throw new UnknownHistoryMonthException(sprintf('"%s" is not a calendar month.', $month));
        }

        // Anchored to local midnight on the first, then advanced by a whole
        // month rather than by a day count: `+1 month` keeps local midnight
        // across a daylight-saving change, where adding 30 or 31 days would
        // land an hour out.
        $start = new \DateTimeImmutable($month . '-01 00:00:00', $viewer->zone);

        return new self($month, self::inUtc($start), self::inUtc($start->modify('+1 month')));
    }

    private static function inUtc(\DateTimeImmutable $local): \DateTimeImmutable
    {
        return $local->setTimezone(new \DateTimeZone('UTC'));
    }
}
```

- [ ] **Step 5: Run to verify both pass**

```bash
cd backend && php bin/phpunit tests/Service/Recommendation/ViewerTimeZoneTest.php tests/Service/Recommendation/MonthWindowTest.php
```

Expected: PASS.

- [ ] **Step 6: Lint and commit**

```bash
cd backend && composer cs && vendor/bin/phpmd src/Service/Recommendation/ViewerTimeZone.php,src/Service/Recommendation/MonthWindow.php text codesize
```

```bash
git add backend/src/Service/Recommendation backend/tests/Service/Recommendation
git commit -m "feat(#409): add the viewer timezone and month window value objects"
```

---

### Task 3: `RecommendationRunHistoryRepository`

**Files:**
- Create: `backend/src/Repository/RecommendationRunHistoryRepository.php`
- Modify: `backend/src/Repository/RecommendationRunRepository.php`
- Modify: `backend/src/Http/RecommendationRunHistoryJson.php` (import path only)
- Modify: `backend/src/Controller/Api/RecommendationRunHistoryController.php` (injected type only)
- Test: `backend/tests/Repository/RecommendationRunHistoryRepositoryTest.php`

**Interfaces:**
- Consumes: `MonthWindow` (Task 2).
- Produces on `RecommendationRunHistoryRepository`:
  - `public const int HISTORY_LIMIT = 50;`
  - `pageForMonth(User $user, MonthWindow $window, ?int $beforeRunId): array` → `list<HistoryRow>`, newest first, at most `HISTORY_LIMIT + 1` rows read (see below)
  - `spendTimeline(User $user): array` → `list<array{createdAt: \DateTimeImmutable, costNanoCredits: int|string|null}>`, every run the account owns
  - `totalCostNanoCredits(User $user): ?int` (moved verbatim)
  - the `HistoryRow` phpstan type alias (moved)
- `RecommendationRunRepository` **loses** `historyForUser()`, `totalCostNanoCredits()`, `HISTORY_LIMIT` and the `HistoryRow` alias, returning it to eight public methods.

**The cursor, and why there is no count query.** `pageForMonth()` asks for
`HISTORY_LIMIT + 1` rows. The caller keeps at most `HISTORY_LIMIT` of them; the
presence of the extra row is what says "there is another page", and the last
kept row's id is the next cursor. A `COUNT` for the same answer would be a
second query per page.

**Why this repository is legitimate now.** A previous review rejected a
`RecommendationRunHistoryRepository` because it existed to hold a verbatim copy
of `findNewestForUser()` and the PHPMD count that justified it was inflated by
that duplicate. This one holds five distinct queries, duplicates nothing, and
the move gives `RecommendationRunRepository` back the headroom it has run out
of. Say so in the class docblock so the next reader does not re-litigate it.

- [ ] **Step 1: Write the failing repository test**

`backend/tests/Repository/RecommendationRunHistoryRepositoryTest.php`. Read an
existing database-backed repository test under `backend/tests/Repository/` first
and copy its harness exactly (base class, entity manager access, user fixture).
The test must own every row it asserts on and must include a second account's
run to prove it is neither returned nor counted.

Cover:

```php
public function testReturnsOnlyTheRunsInsideTheMonthWindow(): void
// Seed runs on 2026-07-31 23:00 UTC, 2026-08-01 00:00 UTC, 2026-08-31 23:59 UTC
// and 2026-09-01 00:00 UTC. A UTC August window returns exactly the middle two,
// newest first. This is what proves the range is half-open at both ends.

public function testABerlinViewersAugustExcludesTheRunThatPrintsAsSeptember(): void
// A run stored at 2026-08-31 23:30 UTC prints as 1 September in Berlin, so a
// Berlin August window must NOT return it, and a Berlin September window must.

public function testReadsOneRowMoreThanTheLimitSoTheCallerCanTellThereIsAnother(): void
// Seed HISTORY_LIMIT + 3 runs in one month; assert the result holds exactly
// HISTORY_LIMIT + 1 rows.

public function testPagesBackwardsFromTheCursor(): void
// Seed 5 runs in one month; page with $beforeRunId = the third id and assert
// only the two older ones come back, newest first.

public function testNeverReturnsAnotherAccountsRuns(): void

public function testTheSpendTimelineCarriesEveryRunOfTheAccountAndNoOther(): void
// Including an unpriced one, to prove null survives to the caller.
```

Fill the fixtures in with the harness's own helpers. Runs are created through
`new RecommendationRun($user, $createdAt)` and persisted; to give one a price,
write `cost_nano_credits` through the connection the way
`RecommendationRunHistoryControllerTest::priceRun()` already does — `ProviderUsage`
has no cost setter by design — then `clear()` the entity manager before asserting.

- [ ] **Step 2: Run to verify it fails**

```bash
cd backend && php bin/phpunit tests/Repository/RecommendationRunHistoryRepositoryTest.php
```

Expected: FAIL — class not found.

- [ ] **Step 3: Create the repository**

`backend/src/Repository/RecommendationRunHistoryRepository.php`. Move
`totalCostNanoCredits()` and the `HistoryRow` phpstan type alias across
unchanged, keeping their docblocks. `historyForUser()` does **not** move — it is
replaced by `pageForMonth()`. Then:

```php
    /**
     * One month's runs, newest first, as the history payload needs them:
     * scalars, not entities. A RecommendationRun carries the frozen candidate
     * pool, every batch winner with its free-text reason, the last rejected
     * provider reply and the error text, and none of that belongs on the path
     * that formats twelve numbers.
     *
     * Reads one row more than the limit. The caller keeps the limit's worth
     * and reads the extra row purely as "there is another page" — a COUNT for
     * the same answer would be a second query on every page.
     *
     * $beforeRunId pages backwards within the month. Ids ascend with creation
     * time, so one integer expresses the whole keyset; the opaque composite
     * cursor RecommendationCursor encodes exists because the for-you feed
     * orders by two columns, and this does not.
     *
     * @return list<HistoryRow>
     */
    public function pageForMonth(User $user, MonthWindow $window, ?int $beforeRunId): array
    {
        $query = $this->historyRowsFor($user)
            ->andWhere('r.createdAt >= :start')->setParameter('start', $window->startUtc)
            ->andWhere('r.createdAt < :end')->setParameter('end', $window->endUtc)
            ->orderBy('r.id', 'DESC')
            ->setMaxResults(self::HISTORY_LIMIT + 1);

        if (null !== $beforeRunId) {
            $query->andWhere('r.id < :before')->setParameter('before', $beforeRunId);
        }

        /** @var list<HistoryRow> $rows */
        $rows = $query->getQuery()->getArrayResult();

        return $rows;
    }

    /**
     * Every run's creation time and price, newest first — the two scalars the
     * month summaries are built from.
     *
     * Grouped in PHP rather than by the database, and deliberately so: DQL has
     * no month extraction, and the buckets have to be cut in the viewer's
     * timezone while the column holds naive UTC, which no portable expression
     * can shift before grouping. The alternative is platform-branched native
     * SQL, which this codebase confines to migrations.
     *
     * The cost of that choice is this read: two scalars for every run the
     * account owns. It is the same shape #409's first pass removed from the
     * history page and the difference is the point — that one pulled twelve
     * fields plus the JSON and TEXT columns above.
     *
     * @return list<array{createdAt: \DateTimeImmutable, costNanoCredits: int|string|null}>
     */
    public function spendTimeline(User $user): array
    {
        /** @var list<array{createdAt: \DateTimeImmutable, costNanoCredits: int|string|null}> $rows */
        $rows = $this->createQueryBuilder('r')
            ->select('r.createdAt AS createdAt', 'r.providerUsage.costNanoCredits AS costNanoCredits')
            ->andWhere('r.user = :user')->setParameter('user', $user)
            ->orderBy('r.id', 'DESC')
            ->getQuery()
            ->getArrayResult();

        return $rows;
    }
```

`historyRowsFor()` is a private helper holding the twelve-field `select()` and
the user filter — the same field list `historyForUser()` has today, including
the `r.providerUsage.*` embeddable paths and the comment explaining why those
paths differ from the column names. Extracting it keeps `pageForMonth()` at one
level of abstraction and stops the field list existing twice.

Register the repository the way the sibling is registered — `ServiceEntityRepository`
with `RecommendationRun::class`, `@extends ServiceEntityRepository<RecommendationRun>`.
Leave `#[ORM\Entity(repositoryClass: RecommendationRunRepository::class)]` on the
entity alone: `$em->getRepository()` must keep resolving to the original.

- [ ] **Step 4: Strip the moved members from `RecommendationRunRepository`**

Delete `HISTORY_LIMIT`, `historyForUser()`, `totalCostNanoCredits()` and the
`HistoryRow` alias. Update `RecommendationRunHistoryJson`'s
`@phpstan-import-type` to the new class, and switch the controller's injected
type. The controller keeps working unchanged apart from that until Task 5.

**The controller currently calls `historyForUser()`, which no longer exists.**
Point it at `pageForMonth()` for the current month as a temporary bridge, or —
better — do Task 5 in the same session so the endpoint is never broken between
commits. Whichever you choose, the branch must be green at every commit.

- [ ] **Step 5: Run to verify it passes**

```bash
cd backend && php bin/phpunit tests/Repository tests/Http tests/Controller/Api/RecommendationRunHistoryControllerTest.php
```

Expected: PASS.

- [ ] **Step 6: Confirm the method-count pressure is actually relieved**

```bash
cd backend && grep -c "public function" src/Repository/RecommendationRunRepository.php && vendor/bin/phpmd src/Repository/RecommendationRunRepository.php,src/Repository/RecommendationRunHistoryRepository.php text codesize
```

Expected: 8 on the original, no PHPMD findings on either.

- [ ] **Step 7: Commit**

```bash
git add backend/src backend/tests
git commit -m "feat(#409): give the history reads their own repository"
```

---

### Task 4: Month summaries

**Files:**
- Create: `backend/src/Service/Recommendation/HistoryMonth.php`
- Create: `backend/src/Service/Recommendation/HistoryMonthSummariser.php`
- Test: `backend/tests/Service/Recommendation/HistoryMonthSummariserTest.php`

**Interfaces:**
- Consumes: `ViewerTimeZone` (Task 2), `RecommendationRunHistoryRepository::spendTimeline()` (Task 3).
- Produces:
  - `HistoryMonth` — `final readonly`, public `string $month`, `int $runCount`, `?int $costNanoCredits`
  - `HistoryMonthSummariser::summarise(array $spendTimeline, ViewerTimeZone $viewer): array` → `list<HistoryMonth>`, newest month first

- [ ] **Step 1: Write the failing tests**

`backend/tests/Service/Recommendation/HistoryMonthSummariserTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Service\Recommendation;

use App\Service\Recommendation\HistoryMonthSummariser;
use App\Service\Recommendation\ViewerTimeZone;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(HistoryMonthSummariser::class)]
final class HistoryMonthSummariserTest extends TestCase
{
    private HistoryMonthSummariser $summariser;

    protected function setUp(): void
    {
        $this->summariser = new HistoryMonthSummariser();
    }

    public function testCountsAndSumsEachMonthSeparately(): void
    {
        $months = $this->summariser->summarise([
            $this->run('2026-08-16 09:00:00', 1_000),
            $this->run('2026-08-01 09:00:00', 2_000),
            $this->run('2026-07-20 09:00:00', 4_000),
        ], ViewerTimeZone::of('UTC'));

        self::assertCount(2, $months);
        self::assertSame('2026-08', $months[0]->month);
        self::assertSame(2, $months[0]->runCount);
        self::assertSame(3_000, $months[0]->costNanoCredits);
        self::assertSame('2026-07', $months[1]->month);
        self::assertSame(1, $months[1]->runCount);
        self::assertSame(4_000, $months[1]->costNanoCredits);
    }

    public function testOrdersTheMonthsNewestFirstWhateverOrderTheRowsArriveIn(): void
    {
        $months = $this->summariser->summarise([
            $this->run('2026-06-01 09:00:00', 1),
            $this->run('2026-08-01 09:00:00', 1),
            $this->run('2026-07-01 09:00:00', 1),
        ], ViewerTimeZone::of('UTC'));

        self::assertSame(['2026-08', '2026-07', '2026-06'], array_map(
            static fn ($month): string => $month->month,
            $months,
        ));
    }

    /** The stored value is naive UTC; a run at 23:30 UTC on the last day of a
     *  month belongs to the next month for a viewer ahead of UTC, because that
     *  is the date the row prints. */
    public function testBucketsInTheViewersZoneNotInUtc(): void
    {
        $months = $this->summariser->summarise(
            [$this->run('2026-08-31 23:30:00', 500)],
            ViewerTimeZone::of('Europe/Berlin'),
        );

        self::assertSame('2026-09', $months[0]->month);
    }

    public function testAMonthWhoseRunsAllWentUnpricedHasNoTotal(): void
    {
        $months = $this->summariser->summarise([
            $this->run('2026-08-16 09:00:00', null),
            $this->run('2026-08-15 09:00:00', null),
        ], ViewerTimeZone::of('UTC'));

        self::assertSame(2, $months[0]->runCount);
        self::assertNull($months[0]->costNanoCredits);
    }

    public function testAMonthWithOnePricedRunAmongUnpricedOnesSumsOnlyThePriced(): void
    {
        $months = $this->summariser->summarise([
            $this->run('2026-08-16 09:00:00', null),
            $this->run('2026-08-15 09:00:00', 700),
        ], ViewerTimeZone::of('UTC'));

        self::assertSame(2, $months[0]->runCount);
        self::assertSame(700, $months[0]->costNanoCredits);
    }

    public function testACostHandedBackAsAStringIsSummedAsAnInteger(): void
    {
        $months = $this->summariser->summarise([
            ['createdAt' => new \DateTimeImmutable('2026-08-16 09:00:00', new \DateTimeZone('UTC')), 'costNanoCredits' => '900'],
        ], ViewerTimeZone::of('UTC'));

        self::assertSame(900, $months[0]->costNanoCredits);
    }

    public function testAnAccountWithNoRunsHasNoMonths(): void
    {
        self::assertSame([], $this->summariser->summarise([], ViewerTimeZone::of('UTC')));
    }

    /** @return array{createdAt: \DateTimeImmutable, costNanoCredits: int|string|null} */
    private function run(string $createdAtUtc, ?int $costNanoCredits): array
    {
        return [
            'createdAt' => new \DateTimeImmutable($createdAtUtc, new \DateTimeZone('UTC')),
            'costNanoCredits' => $costNanoCredits,
        ];
    }
}
```

- [ ] **Step 2: Run to verify it fails**

```bash
cd backend && php bin/phpunit tests/Service/Recommendation/HistoryMonthSummariserTest.php
```

Expected: FAIL — class not found.

- [ ] **Step 3: Implement**

`HistoryMonth` is a plain `final readonly` value object with the three promoted
properties and a docblock saying that a null total means no run of that month
reported a price — the same distinction a row and the all-time total already
make.

`HistoryMonthSummariser` is a `final readonly class` with no dependencies:

```php
    /**
     * @param list<array{createdAt: \DateTimeImmutable, costNanoCredits: int|string|null}> $spendTimeline
     *
     * @return list<HistoryMonth> newest month first
     */
    public function summarise(array $spendTimeline, ViewerTimeZone $viewer): array
```

Notes the implementation must honour:

- The stored `createdAt` hydrates in whatever zone Doctrine hands back; convert
  each one with `->setTimezone($viewer->zone)` before taking `format('Y-m')`, or
  the bucket is UTC's month rather than the viewer's.
- Counts include unpriced runs; the total sums only the priced ones and stays
  null when none were priced. `null` and `0` are different answers here.
- A cost may arrive as the driver's string; cast when summing.
- Sort the keys descending at the end rather than relying on the incoming order.
- Keep the method short — extract a private helper for the per-row fold if it
  starts carrying two levels of abstraction. PHPMD gates this file.

- [ ] **Step 4: Run to verify it passes, lint, commit**

```bash
cd backend && php bin/phpunit tests/Service/Recommendation/HistoryMonthSummariserTest.php && composer cs && vendor/bin/phpmd src/Service/Recommendation/HistoryMonth.php,src/Service/Recommendation/HistoryMonthSummariser.php text codesize
```

```bash
git add backend/src/Service/Recommendation backend/tests/Service/Recommendation
git commit -m "feat(#409): summarise run spend by month in the viewer's timezone"
```

---

### Task 5: The two routes

**Files:**
- Create: `backend/src/Service/Recommendation/RecommendationRunHistoryView.php`
- Modify: `backend/src/Http/RecommendationRunHistoryJson.php`
- Modify: `backend/src/Controller/Api/RecommendationRunHistoryController.php`
- Test: `backend/tests/Http/RecommendationRunHistoryJsonTest.php`
- Test: `backend/tests/Controller/Api/RecommendationRunHistoryControllerTest.php`

**Interfaces:**
- Consumes: everything from Tasks 2-4.
- Produces:
  - `RecommendationRunHistoryView::overview(User $user, ViewerTimeZone $viewer): array`
  - `RecommendationRunHistoryView::month(User $user, MonthWindow $window, ?int $beforeRunId): array`
  - `RecommendationRunHistoryJson::overview(?int $totalCostNanoCredits, array $months, ?array $latest): array`
  - `RecommendationRunHistoryJson::monthPage(string $month, array $rows, ?int $nextCursor): array`
  - Routes `api_recommendations_run_history` (`GET ''`) and
    `api_recommendations_run_history_month` (`GET /{month}`)

**Wire shapes — binding, the frontend consumes them verbatim.**

Overview:

```json
{
  "totalCostNanoCredits": 918200000,
  "months": [{ "month": "2026-08", "runCount": 47, "costNanoCredits": 2431200000 }],
  "latest": { "month": "2026-08", "runs": [], "nextCursor": 361 }
}
```

Month page: `{ "month": "2026-07", "runs": [], "nextCursor": null }`

`latest` is null when the account has never run. `nextCursor` is null when the
month is exhausted. The per-run row shape is **unchanged** — the existing
`row()` mapper and its twelve fields stay exactly as they are.

- [ ] **Step 1: Write the failing mapper tests**

Extend `backend/tests/Http/RecommendationRunHistoryJsonTest.php`. Keep every
existing row-level test — `row()` does not change. Add:

- an overview with two months and a `latest` renders all three keys, months in
  the order given, each month as `{month, runCount, costNanoCredits}`
- an overview for an account that never ran renders `months: []` and
  `latest: null` and `totalCostNanoCredits: null`
- a month page renders `{month, runs, nextCursor}` and nothing else
- a month page with a null cursor keeps the key present and null

- [ ] **Step 2: Write the failing view tests**

`backend/tests/Service/Recommendation/RecommendationRunHistoryViewTest.php`, or
extend the controller's functional test if the view is easier to prove through
the route. The behaviour that must be pinned somewhere:

- the overview's `latest` is the **newest month that has runs**, not the current
  calendar month — an account whose last run was in June opens on June
- `latest.runs` holds at most `HISTORY_LIMIT` rows even though the repository
  read one more, and `nextCursor` is the last kept row's id
- when the month holds no more than the limit, `nextCursor` is null and no row
  is dropped
- `month()` applies the same truncation

- [ ] **Step 3: Run to verify they fail**

```bash
cd backend && php bin/phpunit tests/Http/RecommendationRunHistoryJsonTest.php
```

Expected: FAIL — undefined method `overview()`.

- [ ] **Step 4: Implement the view and the mappers**

`RecommendationRunHistoryView` is a `final readonly class` injected with the
history repository and the summariser. It owns the limit-plus-one truncation —
that logic belongs in exactly one place, and both routes need it. Give it a
private helper that turns the repository's `HISTORY_LIMIT + 1` rows into
`[keptRows, nextCursor]` and have both public methods use it.

`overview()`: reads the spend timeline, summarises it, takes the newest month
from the summary (null when there is none), builds a `MonthWindow` for it and
reads its first page, and asks the repository for the all-time total.

`RecommendationRunHistoryJson` gains `overview()` and `monthPage()`. Keep
`payload()` only if something still calls it; if nothing does, delete it rather
than leaving a second entry point.

- [ ] **Step 5: Rewrite the controller**

```php
    #[Route('', name: 'api_recommendations_run_history', methods: ['GET'])]
    public function overview(#[CurrentUser] User $user, Request $request): JsonResponse
    {
        return new JsonResponse($this->view->overview(
            $user,
            ViewerTimeZone::of($request->query->get('tz')),
        ));
    }

    #[Route(
        '/{month}',
        name: 'api_recommendations_run_history_month',
        requirements: ['month' => '\d{4}-(?:0[1-9]|1[0-2])'],
        methods: ['GET'],
    )]
    public function month(string $month, #[CurrentUser] User $user, Request $request): JsonResponse
    {
        return new JsonResponse($this->view->month(
            $user,
            MonthWindow::of($month, ViewerTimeZone::of($request->query->get('tz'))),
            $request->query->getInt('before') ?: null,
        ));
    }
```

The route requirement rejects `2026-13` with a 404 from routing, which is why
neither action carries a guard — `ThinControllerRule` would fail a private
method that did. Update the class docblock: it currently says "two indexed
queries", which is no longer what the overview does.

`getInt('before')` returns 0 for an absent parameter, and `?: null` maps that to
"no cursor". Run ids start at 1, so 0 can never be a real cursor.

- [ ] **Step 6: Extend the functional test**

The existing `RecommendationRunHistoryControllerTest` asserts the old flat
shape; rewrite those assertions against the new one, keeping every guarantee it
already proves: cross-account isolation on both the list and the total, the
anonymous 401, `id` pinned on real persisted rows, and the cap. Add:

- runs in two different months produce two `months` entries with the right
  counts and totals, and `latest` is the newer one
- `GET /history/2026-07` returns only July's runs
- `GET /history/2026-13` is a 404
- a `tz` the server does not know still answers 200 (bucketed in UTC)
- `?before=` pages within a month

- [ ] **Step 7: Run everything and commit**

```bash
cd backend && bin/console cache:warmup && composer check && composer md && php bin/phpunit
```

```bash
git add backend/src backend/tests
git commit -m "feat(#409): serve the run history a month at a time"
```

---

### Task 6: `opened` output on the shared disclosure

**Files:**
- Modify: `frontend/src/app/shared/disclosure/disclosure.component.ts`
- Modify: `frontend/src/app/shared/disclosure/disclosure.component.html`
- Test: `frontend/src/app/shared/disclosure/disclosure.component.spec.ts`

**Interfaces:**
- Consumes: nothing.
- Produces: `readonly opened = output<void>()` on `DisclosureComponent`, emitted
  when the underlying `<details>` transitions to open — **not** when it closes.

**Why this belongs in `shared/`.** `<details>`'s `toggle` event does not bubble,
so a caller cannot listen for it on the component host. Task 7 needs to know
when a month section is opened in order to fetch its rows. The alternative is
hand-rolling a fourth collapsible, which is the exact thing this component's
docblock records it was extracted to stop.

- [ ] **Step 1: Write the failing test**

Append to `disclosure.component.spec.ts`, following its existing host-component
harness:

```ts
  it('announces when it is opened', () => {
    const { fixture, host } = render();
    const details = fixture.nativeElement.querySelector('details') as HTMLDetailsElement;

    details.open = true;
    details.dispatchEvent(new Event('toggle'));

    expect(host.openedCount).toBe(1);
  });

  it('stays quiet when it is closed again', () => {
    const { fixture, host } = render();
    const details = fixture.nativeElement.querySelector('details') as HTMLDetailsElement;

    details.open = true;
    details.dispatchEvent(new Event('toggle'));
    details.open = false;
    details.dispatchEvent(new Event('toggle'));

    expect(host.openedCount).toBe(1);
  });
```

Add a host component that binds `(opened)="openedCount = openedCount + 1"`.
Adapt to whatever harness the file already has rather than inventing one.

- [ ] **Step 2: Run to verify it fails**

```bash
cd frontend && npx jest src/app/shared/disclosure/disclosure.component.spec.ts
```

Expected: FAIL — `opened` is not a known property.

- [ ] **Step 3: Implement**

In `disclosure.component.ts`, add to the class:

```ts
  /**
   * Announced when the body is revealed, and only then. A caller that loads
   * its content lazily needs this because `<details>`'s own `toggle` event
   * does not bubble, so it cannot be listened for on this component's host.
   * Closing is deliberately silent: nobody has to undo a fetch.
   */
  readonly opened = output<void>();

  onToggle(details: HTMLDetailsElement): void {
    if (details.open) this.opened.emit();
  }
```

Import `output` from `@angular/core`.

In `disclosure.component.html`, bind on the existing `<details>`:

```html
<details #details (toggle)="onToggle(details)">
```

Keep the rest of the template exactly as it is.

- [ ] **Step 4: Run to verify it passes**

```bash
cd frontend && npx jest src/app/shared/disclosure/disclosure.component.spec.ts
```

Expected: PASS. Also run `npx jest src/app/settings src/app/shared` — three
existing callers project into this component and must be unaffected.

- [ ] **Step 5: Commit**

```bash
git add frontend/src/app/shared/disclosure
git commit -m "feat(#409): let a disclosure announce when it opens"
```

---

### Task 7: The month-sectioned card

**Files:**
- Modify: `frontend/src/app/reader/models.ts`
- Modify: `frontend/src/app/reader/reader-api.ts`
- Create: `frontend/src/app/settings/recommendation-run-history-month.component.{ts,html,scss,spec.ts}`
- Modify: `frontend/src/app/settings/recommendation-run-history.component.{ts,html,scss,spec.ts}`
- Modify: `frontend/public/i18n/en.json`, `de.json`
- Modify: `frontend/src/app/settings/ai-section.component.spec.ts` (the flushed request changes shape)

**Interfaces:**
- Consumes: the two routes from Task 5, `formatCost` from Task 1, `opened` from Task 6.
- Produces: nothing later tasks depend on.

- [ ] **Step 1: Replace the models**

In `models.ts`, delete `RunHistoryPayload` and add:

```ts
/** One month of an account's run history, as its section header shows it.
 *  `costNanoCredits` is null when no run of the month reported a price -- the
 *  same distinction a row and the all-time total already make. Counts and
 *  totals are computed over the whole month, not over the rows on screen, so
 *  a capped section never shows a wrong number. */
export interface RunHistoryMonth {
  month: string;
  runCount: number;
  costNanoCredits: number | null;
}

/** One page of one month's runs. `nextCursor` is the id to pass as `before`
 *  for the next page, or null when the month is exhausted. */
export interface RunHistoryMonthPage {
  month: string;
  runs: RunHistoryRow[];
  nextCursor: number | null;
}

/** What the history route answers with: every month the account has runs in,
 *  the newest month's first page so the card paints in one round trip, and the
 *  account's all-time total. `latest` is null when the account has never run. */
export interface RunHistoryOverview {
  totalCostNanoCredits: number | null;
  months: RunHistoryMonth[];
  latest: RunHistoryMonthPage | null;
}
```

`RunHistoryRow` is unchanged.

- [ ] **Step 2: Replace the API calls**

In `reader-api.ts`:

```ts
  /** Every month this account has run in, with that month's own run count and
   *  spend, plus the newest month's first page and the all-time total. One
   *  call, because the card's first paint needs all of it and each request
   *  costs a PHP boot. `timeZone` is an IANA identifier; the server buckets
   *  the months in it and falls back to UTC when it does not know it. */
  runHistory(timeZone: string): Observable<RunHistoryOverview> {
    return this.http.get<RunHistoryOverview>(`${this.base}/api/recommendations/runs/history`, {
      params: { tz: timeZone },
    });
  }

  /** One month's runs, newest first. Without `before` this is the month's
   *  first page; with it, the next page after that cursor. */
  runHistoryMonth(month: string, timeZone: string, before?: number): Observable<RunHistoryMonthPage> {
    const params: Record<string, string | number> = { tz: timeZone };
    if (before !== undefined) params['before'] = before;
    return this.http.get<RunHistoryMonthPage>(
      `${this.base}/api/recommendations/runs/history/${month}`,
      { params },
    );
  }
```

- [ ] **Step 3: Add the i18n keys**

`en.json`, inside `settings.ai.recommendations` (keep them contiguous with the
other `history*` keys):

```json
"historyMonthSummary": "{{ runs }} runs · {{ cost }}",
"historyShowMore": "Show more of {{ month }}",
"historyLoading": "Loading…"
```

`de.json`:

```json
"historyMonthSummary": "{{ runs }} Läufe · {{ cost }}",
"historyShowMore": "Mehr aus {{ month }} anzeigen",
"historyLoading": "Wird geladen…"
```

- [ ] **Step 4: Write the failing month-section spec**

`recommendation-run-history-month.component.spec.ts`. The component is
presentational: inputs only, plus two outputs. Cover:

- renders the month label through `Intl` on the active language (a `2026-08`
  section reads "August 2026" in English, "August 2026" in German — assert the
  English one and that the German one differs where the language differs, e.g.
  `2026-12` → "December 2026" / "Dezember 2026")
- the header shows the run count and the month's cost via `formatCost`
- a month whose cost is null shows `—` in the header
- rows render only when `runs` is non-null
- the "show more" control appears only when `nextCursor` is non-null
- clicking "show more" emits `showMore`
- opening the disclosure emits `opened`
- a `loading` section renders the loading label

- [ ] **Step 5: Write the failing parent spec**

Rewrite `recommendation-run-history.component.spec.ts` against the new shape.
Keep the existing guarantees — self-hiding when the account never ran, the total
is the server's all-time figure and not a row sum, the error handler leaves the
previous rows standing, the refetch on `completedStamp` — and add:

- the newest month renders expanded with its rows from `latest`; older months
  render collapsed with no rows
- opening an older month calls `runHistoryMonth` once with that month and the
  browser timezone, and renders the returned rows
- opening the same month twice fetches once
- "show more" calls `runHistoryMonth` with the section's `nextCursor` and
  **appends** rather than replaces
- a `completedStamp` bump replaces the newest month's rows and leaves an
  already-expanded older month's rows and open state alone
- the timezone sent is `Intl.DateTimeFormat().resolvedOptions().timeZone`

Mock `ReaderApi` with `{ runHistory, runHistoryMonth }` jest functions returning
`of(...)`, the pattern the file already uses.

- [ ] **Step 6: Run to verify both fail, then implement**

```bash
cd frontend && npx jest src/app/settings/recommendation-run-history
```

Expected: FAIL.

The month section component is presentational — `input()`s for the month
summary, its rows, its cursor and its loading flag, `output()`s for `opened` and
`showMore`. It owns the row markup, which moves out of the parent's template
wholesale, and its `.scss` takes the row grid with it. It injects
`LanguageService` for `formatCost` and the date helpers.

The parent holds the state. One section per month:

```ts
interface MonthSection {
  month: string;
  runCount: number;
  costNanoCredits: number | null;
  /** null until the month has been opened and its first page has arrived. */
  runs: RunHistoryRow[] | null;
  nextCursor: number | null;
  loading: boolean;
}
```

Rules the implementation must honour:

- The timezone is read once (`Intl.DateTimeFormat().resolvedOptions().timeZone`)
  and sent on every call.
- Opening a section that already has rows fetches nothing.
- "Show more" appends the returned rows and replaces `nextCursor`.
- A failed fetch clears `loading` and leaves whatever rows the section already
  had; the parent's existing error-handling comment explains why the card never
  blanks itself, and the same reasoning applies here.
- On a `completedStamp` bump the overview is refetched: month summaries and the
  total are replaced wholesale, the newest month's rows come from `latest`, and
  every other section keeps its loaded rows and open state — a run that
  completes can only land in the current month.
- Keep both component files focused. If the parent grows past roughly 150 lines
  of logic, stop and report it rather than splitting further on your own.

- [ ] **Step 7: Run the full frontend gate**

```bash
cd frontend && npm run check
```

Expected: exit 0. `ai-section.component.spec.ts` flushes
`/api/recommendations/runs/history` in two tests through `HttpTestingController`
— the URL now carries a `tz` query parameter, so those `expectOne` calls need a
predicate rather than an exact string. Fix them there; do not weaken
`afterEach(() => http.verify())`.

- [ ] **Step 8: Commit**

```bash
git add frontend/src frontend/public/i18n
git commit -m "feat(#409): page the run history by month"
```

---

### Task 8: Gates, verification, PR update

- [ ] **Step 1: Full backend gates**

```bash
cd backend && bin/console cache:warmup && composer check && composer md && php bin/phpunit
```

If `composer check` fails on its first run after a cache warm-up, re-run it
before investigating — a cold container has produced a spurious exit 1 on this
branch before. Two consecutive clean runs is the bar.

- [ ] **Step 2: MySQL leg**

```bash
docker compose exec php vendor/bin/phpunit
```

A rate-limiter failure that passes in isolation is the known order-dependent
flake, not this branch's regression.

- [ ] **Step 3: Mutation gate**

```bash
cd backend && composer infection:diff
```

At or above `minMsi` in `infection.json5`. An escaped mutant means a missing
assertion — add the test, never lower the threshold.

- [ ] **Step 4: Frontend gate and PhpStorm inspections**

```bash
cd frontend && npm run check
```

Then run `mcp__phpstorm__lint_files` over every PHP file this pass created or
modified. Block on ERROR and WARNING; weak warnings are advisory.

- [ ] **Step 5: Restart the containers that hold stale code**

```bash
docker compose restart php worker frontend
```

The worker loads code once at startup and the dev DI container is compiled;
both go stale on a branch like this. Confirm the running containers serve this
tree before believing anything in the browser.

- [ ] **Step 6: Verify by hand**

The #409 bar still governs and is still outstanding: with the debug switch
**off**, a live OpenRouter run must show a non-empty cost and an all-time total
that grew by it, and an LM Studio run must show tokens with `—`.

This pass adds its own: an account with runs in more than one month must show a
section per month whose header count and total match the rows inside it, older
months must load their rows only when opened, and a month holding more than 50
runs must page. Check the cost reads `$ 0.00137` and that the word "credits"
appears nowhere on the card.

- [ ] **Step 7: Update the PR**

Amend the body of PR #427 to describe the month paging and the dollar rendering,
and replace the verification table with the results from this pass. Keep the
outstanding live-verification note — it is still outstanding.

```bash
gh pr edit 427 --body-file <path>
```

Do not merge.

---

## Self-Review

**Spec coverage**

| Spec section | Task |
|---|---|
| §1 Months are the unit, cap 50, `—` for an unpriced month | 4, 5, 7 |
| §2 Two routes, one round trip, cursor is a plain int | 3, 5 |
| §3 Range query for one month; PHP grouping for the summary; `tz` fails soft | 2, 3, 4 |
| §4 History reads get their own repository | 3 |
| §5 `app-disclosure` + `opened`; refetch keeps older sections | 6, 7 |
| §6 `$ 0.00137`, credits copy removed, localized separator | 1 |
| Verification bar | 8 |

**Type consistency**: `ViewerTimeZone::of(?string)` and its public `$zone` are
used identically in Tasks 2, 4, 5. `MonthWindow::of(string, ViewerTimeZone)`
with `$month`/`$startUtc`/`$endUtc` matches its consumer in Task 3.
`HistoryMonth`'s three properties match the `months[]` wire fields in Task 5 and
the `RunHistoryMonth` interface in Task 7. `HISTORY_LIMIT + 1` is read in Task 3
and truncated in Task 5, in one place. `formatCost(nanoCredits, locale)` from
Task 1 is called by both components in Task 7.
