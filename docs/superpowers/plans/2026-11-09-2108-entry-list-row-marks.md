# EntryListRow Marks Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** `EntryListRow` keeps the reader's marks on the entry as one `EntryListRowMarks` value and its subscription as an `EntryListRowSubscription` field, instead of unpacking two parameter bundles into flat fields.

**Architecture:** A new `final readonly` value `App\Repository\EntryListRowMarks` (hidden, favourite, kept, viewedAt, sharedAt; `isViewed()` and `isShared()` derived from the instants) replaces `EntryListRowViewState` and the row's five flat mark fields. The row's constructor becomes `entry, subscription, marks, markedReadUntil, duplicates, enrichment`, both held as public fields; `copyWith()` passes them through. Every reader moves from `$row->isKept` to `$row->marks->isKept` and from `$row->subscriptionTitle` to `$row->subscription->title`.

**Tech Stack:** PHP 8.4, Symfony 7.4, Doctrine ORM (DQL projections), PHPUnit 12.

**Spec:** GitHub issue #2108 (`gh issue view 2108`).

## Global Constraints

- Branch `refactor/2108-entry-list-row-marks` is already checked out; commit there. Commit format `refactor(#2108): summary`. No Claude attribution in commits.
- CLAUDE.md Clean Code rules apply: `final readonly`, no abbreviations, no boolean flag parameters, default to no comment (one line if one is needed), delete stale comments on touched lines.
- `EntryListRowMarks` lives in `App\Repository`, next to `EntryListRow`, `EntryListRowSubscription`, `EntryListRowSocial`, `EntryListRowDiscussion`: it is a row value the repositories build, it names no Service class, so `PersistenceKnowsNoServiceRule` (architecture.md §8) is satisfied; `src/Repository` has no role folders (§10 governs `src/Service` and `src/Http`).
- Consumers read `$row->marks->isKept` / `$row->marks->isViewed()` and `$row->subscription->title`, not narrow accessors on the row: same churn at every read site, but no seven forwarding methods on the row, and the read says whose value it is.
- The `entry_state.is_viewed` column stays (queries filter on it); only the row stops carrying it.
- **isViewed derived from viewedAt is safe:** `EntryState::markViewed(\DateTimeImmutable $when)` (non-nullable since #307, `4cde62266`) sets both, `clearViewed()` and `markUnread()` clear both, `EntryStateRepository::ensureRow()` inserts `false, null`, `SavedStateMover` and `RestoredEntryStateFactory` go through `markViewed()` with a non-null instant, the only migration that touches the columns (`Version20260807120000`) adds `is_viewed NOT NULL` (0 for existing rows) and `viewed_at NULL` together, and no SQL/DQL writes `is_viewed` elsewhere. So `is_viewed = 1 ⇔ viewed_at IS NOT NULL` holds for all data.
- **The For-you projection must start selecting `es.viewedAt`.** `RecommendationItemRepository::hydrateRow()` today passes `isViewed` from `es.isViewed` and `viewedAt: null`; deriving from viewedAt without the select would make every For-you row unviewed. `RecommendationFeedTest` (`assertTrue($byGuid['viewed']->row->isViewed)`) guards this.
- `markedReadUntil` folding stays byte-for-byte: both hydrators still compute `isHidden` via `EffectiveReadState::isHidden($esHidden, $markedReadUntil, $entry->getEffectiveDate())` and keep passing `markedReadUntil` to the row; `EntryStateResolver` still seeds with `$row->marks->isHidden ? $row->markedReadUntil : null`.
- Every touched `src` file must be PHPMD-clean before commit. No PHPStan ignores, no rule edits, no baselines.

---

### Task 1: The marks value

**Files:**
- Create: `backend/src/Repository/EntryListRowMarks.php`
- Test: `backend/tests/Repository/EntryListRowMarksTest.php`

**Interfaces:**
- Produces: `App\Repository\EntryListRowMarks::__construct(bool $isHidden = false, bool $isFavorite = false, bool $isKept = false, ?\DateTimeImmutable $viewedAt = null, ?\DateTimeImmutable $sharedAt = null)`, public readonly properties of the same names, `isViewed(): bool`, `isShared(): bool`.

- [ ] **Step 1: Write the failing test**

`backend/tests/Repository/EntryListRowMarksTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Repository;

use App\Repository\EntryListRowMarks;
use PHPUnit\Framework\TestCase;

final class EntryListRowMarksTest extends TestCase
{
    public function testAViewInstantMakesTheEntryViewed(): void
    {
        $marks = new EntryListRowMarks(viewedAt: new \DateTimeImmutable('2026-11-09T10:00:00Z'));

        self::assertTrue($marks->isViewed());
    }

    public function testNoViewInstantLeavesTheEntryUnviewed(): void
    {
        self::assertFalse((new EntryListRowMarks())->isViewed());
    }

    public function testAShareInstantMakesTheEntryShared(): void
    {
        $marks = new EntryListRowMarks(sharedAt: new \DateTimeImmutable('2026-11-09T10:00:00Z'));

        self::assertTrue($marks->isShared());
    }

    public function testNoShareInstantLeavesTheEntryUnshared(): void
    {
        self::assertFalse((new EntryListRowMarks())->isShared());
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run (from `backend/`): `php bin/phpunit tests/Repository/EntryListRowMarksTest.php`
Expected: FAIL, `Class "App\Repository\EntryListRowMarks" not found`.

- [ ] **Step 3: Write the implementation**

`backend/src/Repository/EntryListRowMarks.php`:

```php
<?php

declare(strict_types=1);

namespace App\Repository;

/** The reader's own marks on a row's entry; isHidden already has the subscription's watermark folded in. */
final readonly class EntryListRowMarks
{
    public function __construct(
        public bool $isHidden = false,
        public bool $isFavorite = false,
        public bool $isKept = false,
        public ?\DateTimeImmutable $viewedAt = null,
        public ?\DateTimeImmutable $sharedAt = null,
    ) {
    }

    public function isViewed(): bool
    {
        return null !== $this->viewedAt;
    }

    public function isShared(): bool
    {
        return null !== $this->sharedAt;
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php bin/phpunit tests/Repository/EntryListRowMarksTest.php`
Expected: PASS (4 tests).

- [ ] **Step 5: Commit**

```bash
git add backend/src/Repository/EntryListRowMarks.php backend/tests/Repository/EntryListRowMarksTest.php
git commit -m "refactor(#2108): add EntryListRowMarks, the reader's marks on a row's entry"
```

---

### Task 2: The row holds subscription and marks; every reader follows

This is one atomic change: the constructor and every caller must move together for the tree to compile.

**Files:**
- Modify: `backend/src/Repository/EntryListRow.php` (whole file)
- Delete: `backend/src/Repository/EntryListRowViewState.php`
- Modify: `backend/src/Repository/EntryListRowHydrator.php:23-37`
- Modify: `backend/src/Repository/EntryProjection.php:77`
- Modify: `backend/src/Repository/RecommendationItemRepository.php:69, 192-210`
- Modify: `backend/src/Repository/EntryListSort.php:55-60`
- Modify: `backend/src/Http/EntryJson.php:95-104, 198`
- Modify: `backend/src/Service/Reading/EntryStateResolver.php:38`
- Modify: `backend/src/Service/Reading/EntryStateUpdater.php:69, 77`
- Modify: `backend/src/Service/Mail/Digest/DigestComposer.php:71`
- Modify: `backend/src/Service/Search/EntrySearch/IndexedEntrySearch.php:60, 71`
- Modify (tests that build rows): `backend/tests/Repository/EntryListRowEnricherTest.php`, `backend/tests/Http/EntryPageTest.php`, `backend/tests/Http/SearchPageTest.php`, `backend/tests/Http/EntryJsonTest.php`, `backend/tests/Http/SavedSearchPageTest.php`, `backend/tests/Http/RecommendationFeedJsonTest.php`, `backend/tests/Service/Reading/EntryStateResolverTest.php`, `backend/tests/Service/SavedPosts/SavedBlueskyPostInteractionTest.php`, `backend/tests/Service/Trending/Pass/StoryCardSourceTest.php`
- Modify (tests that read rows): `backend/tests/Repository/EntryListTest.php`, `backend/tests/Repository/RecommendationFeedTest.php`

**Interfaces:**
- Consumes: `EntryListRowMarks` from Task 1.
- Produces: `EntryListRow::__construct(Entry $entry, EntryListRowSubscription $subscription, EntryListRowMarks $marks, ?\DateTimeImmutable $markedReadUntil, array $duplicates = [], EntryListRowEnrichment $enrichment = new EntryListRowEnrichment())`; public readonly `$entry`, `$subscription`, `$marks`, `$markedReadUntil`, `$duplicates`, `$enrichment`. Removed: `$subscriptionId`, `$subscriptionTitle`, `$isHidden`, `$isFavorite`, `$isKept`, `$isViewed`, `$viewedAt`, `$sharedAt` on the row; class `EntryListRowViewState`.

- [ ] **Step 1: Point the read tests at the new shape (they become the failing tests)**

`backend/tests/Repository/RecommendationFeedTest.php`, lines 207–227, replace:

```php
        self::assertTrue($byGuid['old']->row->isHidden);
        self::assertSame(
            $watermark->getTimestamp(),
            $byGuid['old']->row->markedReadUntil?->getTimestamp(),
        );
        self::assertSame('reason old', $byGuid['old']->reason);
        self::assertFalse($byGuid['old']->row->isFavorite);
        self::assertFalse($byGuid['old']->row->isKept);
        self::assertFalse($byGuid['old']->row->isViewed);

        self::assertTrue($byGuid['fav']->row->isFavorite);
        self::assertFalse($byGuid['fav']->row->isKept);

        self::assertTrue($byGuid['kept']->row->isKept);
        self::assertFalse($byGuid['kept']->row->isFavorite);

        self::assertTrue($byGuid['viewed']->row->isViewed);
        self::assertFalse($byGuid['old']->row->isViewed);

        self::assertSame($this->subscription->getId(), $byGuid['old']->row->subscriptionId);
        self::assertSame('Example', $byGuid['old']->row->subscriptionTitle);
```

with:

```php
        self::assertTrue($byGuid['old']->row->marks->isHidden);
        self::assertSame(
            $watermark->getTimestamp(),
            $byGuid['old']->row->markedReadUntil?->getTimestamp(),
        );
        self::assertSame('reason old', $byGuid['old']->reason);
        self::assertFalse($byGuid['old']->row->marks->isFavorite);
        self::assertFalse($byGuid['old']->row->marks->isKept);
        self::assertFalse($byGuid['old']->row->marks->isViewed());

        self::assertTrue($byGuid['fav']->row->marks->isFavorite);
        self::assertFalse($byGuid['fav']->row->marks->isKept);

        self::assertTrue($byGuid['kept']->row->marks->isKept);
        self::assertFalse($byGuid['kept']->row->marks->isFavorite);

        self::assertTrue($byGuid['viewed']->row->marks->isViewed());
        self::assertFalse($byGuid['old']->row->marks->isViewed());

        self::assertSame($this->subscription->getId(), $byGuid['old']->row->subscription->id);
        self::assertSame('Example', $byGuid['old']->row->subscription->title);
```

Same file, line 351: `$rows[0]->row->subscriptionTitle` → `$rows[0]->row->subscription->title`; line 381: `$rows[0]->row->subscriptionTitle` → `$rows[0]->row->subscription->title`.

`backend/tests/Repository/EntryListTest.php`, exact line edits:

| Line | Before | After |
|---|---|---|
| 322 | `$rows[0]->subscriptionId` | `$rows[0]->subscription->id` |
| 323 | `$rows[0]->subscriptionTitle` | `$rows[0]->subscription->title` |
| 324 | `$rows[0]->isHidden` | `$rows[0]->marks->isHidden` |
| 393 | `$byGuid['old']->isHidden` | `$byGuid['old']->marks->isHidden` |
| 394 | `$byGuid['new']->isHidden` | `$byGuid['new']->marks->isHidden` |
| 413 | `$unread[0]->isHidden` | `$unread[0]->marks->isHidden` |
| 436 | `$favorites[0]->isFavorite` | `$favorites[0]->marks->isFavorite` |
| 467 | `$rows[0]->viewedAt` | `$rows[0]->marks->viewedAt` |
| 492 | `$page1[0]->viewedAt` | `$page1[0]->marks->viewedAt` |
| 690 | `$row->isViewed;` | `$row->marks->isViewed();` |
| 768 | `$rows[0]->isHidden` | `$rows[0]->marks->isHidden` |
| 769 | `$rows[0]->isFavorite` | `$rows[0]->marks->isFavorite` |
| 1135 | `$rows[0]->isHidden` | `$rows[0]->marks->isHidden` |
| 1136 | `$rows[1]->isHidden` | `$rows[1]->marks->isHidden` |

(Keep the trailing `// under the watermark` / `// above it` comments on 393–394 as they are.)

- [ ] **Step 2: Point the row-building tests at the new constructor**

In each file below, two edits: the imports and the `new EntryListRow(` call.

`backend/tests/Repository/EntryListRowEnricherTest.php` — imports lines 32–34:

```php
use App\Repository\EntryListRowSocial;
use App\Repository\EntryListRowSubscription;
use App\Repository\EntryListRowViewState;
```
→
```php
use App\Repository\EntryListRowMarks;
use App\Repository\EntryListRowSocial;
use App\Repository\EntryListRowSubscription;
```
and in `row()` (line 593):
```php
        return new EntryListRow(
            $entry,
            new EntryListRowSubscription(1, 'S'),
            false,
            false,
            false,
            new EntryListRowViewState(false, null, null),
            null,
            $duplicates,
        );
```
→
```php
        return new EntryListRow(
            $entry,
            new EntryListRowSubscription(1, 'S'),
            new EntryListRowMarks(),
            null,
            $duplicates,
        );
```

`backend/tests/Http/EntryPageTest.php` — imports lines 13–14:
```php
use App\Repository\EntryListRowSubscription;
use App\Repository\EntryListRowViewState;
```
→
```php
use App\Repository\EntryListRowMarks;
use App\Repository\EntryListRowSubscription;
```
and (line 246):
```php
        return new EntryListRow(
            entry: $entry,
            subscription: new EntryListRowSubscription(1, 'Example'),
            isHidden: false,
            isFavorite: false,
            isKept: false,
            viewState: new EntryListRowViewState(isViewed: $viewedAt !== null, viewedAt: $viewedAt, sharedAt: null),
            markedReadUntil: null,
        );
```
→
```php
        return new EntryListRow(
            entry: $entry,
            subscription: new EntryListRowSubscription(1, 'Example'),
            marks: new EntryListRowMarks(viewedAt: $viewedAt),
            markedReadUntil: null,
        );
```

`backend/tests/Http/SearchPageTest.php` — imports lines 12–13 (same before→after as EntryPageTest), and (line 126):
```php
            subscription: new EntryListRowSubscription(1, 'Example'),
            isHidden: false,
            isFavorite: false,
            isKept: false,
            viewState: new EntryListRowViewState(isViewed: false, viewedAt: null, sharedAt: null),
            markedReadUntil: null,
```
→
```php
            subscription: new EntryListRowSubscription(1, 'Example'),
            marks: new EntryListRowMarks(),
            markedReadUntil: null,
```

`backend/tests/Http/SavedSearchPageTest.php` — imports lines 12–13 (same before→after as EntryPageTest; `EntryListRowEnrichment` on line 11 stays above), and (line 66):
```php
            subscription: new EntryListRowSubscription(1, 'Example'),
            isHidden: false,
            isFavorite: false,
            isKept: false,
            viewState: new EntryListRowViewState(isViewed: false, viewedAt: null, sharedAt: null),
            markedReadUntil: null,
            enrichment: $enrichment,
```
→
```php
            subscription: new EntryListRowSubscription(1, 'Example'),
            marks: new EntryListRowMarks(),
            markedReadUntil: null,
            enrichment: $enrichment,
```

`backend/tests/Http/RecommendationFeedJsonTest.php` — imports lines 10–11 (same before→after as EntryPageTest), and (line 109):
```php
            subscription: new EntryListRowSubscription(1, 'Seeded'),
            isHidden: false,
            isFavorite: false,
            isKept: false,
            viewState: new EntryListRowViewState(isViewed: false, viewedAt: null, sharedAt: null),
            markedReadUntil: null,
```
→
```php
            subscription: new EntryListRowSubscription(1, 'Seeded'),
            marks: new EntryListRowMarks(),
            markedReadUntil: null,
```

`backend/tests/Http/EntryJsonTest.php` — imports lines 25–27:
```php
use App\Repository\EntryListRowSocial;
use App\Repository\EntryListRowSubscription;
use App\Repository\EntryListRowViewState;
```
→
```php
use App\Repository\EntryListRowMarks;
use App\Repository\EntryListRowSocial;
use App\Repository\EntryListRowSubscription;
```
Two calls (lines 448 and 805), each:
```php
            new EntryListRowSubscription(1, 'Source'),
            false,
            false,
            false,
            new EntryListRowViewState(false, null, null),
            null,
```
→
```php
            new EntryListRowSubscription(1, 'Source'),
            new EntryListRowMarks(),
            null,
```
(the following `enrichment:` argument lines stay unchanged in both).

`backend/tests/Service/Reading/EntryStateResolverTest.php` — imports lines 14–15 (same before→after as EntryPageTest), and in `listRow()` (line 87):
```php
        return new EntryListRow(
            $entry,
            new EntryListRowSubscription($this->subscription->requireId(), 'Resolver Feed'),
            $isHidden,
            false,
            false,
            new EntryListRowViewState(false, null, null),
            $markedReadUntil,
        );
```
→
```php
        return new EntryListRow(
            $entry,
            new EntryListRowSubscription($this->subscription->requireId(), 'Resolver Feed'),
            new EntryListRowMarks(isHidden: $isHidden),
            $markedReadUntil,
        );
```

`backend/tests/Service/SavedPosts/SavedBlueskyPostInteractionTest.php` — imports lines 15–17:
```php
use App\Repository\EntryListRowSocial;
use App\Repository\EntryListRowSubscription;
use App\Repository\EntryListRowViewState;
```
→
```php
use App\Repository\EntryListRowMarks;
use App\Repository\EntryListRowSocial;
use App\Repository\EntryListRowSubscription;
```
and (line 103):
```php
            new EntryListRowSubscription(1, 'Saved from Bluesky'),
            false,
            false,
            false,
            new EntryListRowViewState(false, null, null),
            null,
        );
```
→
```php
            new EntryListRowSubscription(1, 'Saved from Bluesky'),
            new EntryListRowMarks(),
            null,
        );
```

`backend/tests/Service/Trending/Pass/StoryCardSourceTest.php` — imports lines 8–9 (same before→after as EntryPageTest), and (line 22):
```php
            new EntryListRowSubscription(1, 'Alpha'),
            false,
            false,
            false,
            new EntryListRowViewState(false, null, null),
            null,
        );
```
→
```php
            new EntryListRowSubscription(1, 'Alpha'),
            new EntryListRowMarks(),
            null,
        );
```

- [ ] **Step 3: Run the touched tests to verify they fail**

Run: `php bin/phpunit tests/Repository/RecommendationFeedTest.php tests/Http/EntryPageTest.php`
Expected: FAIL/ERROR — `Undefined property: App\Repository\EntryListRow::$marks` (and an `ArgumentCountError`/unknown named parameter `marks` from the old constructor).

- [ ] **Step 4: Rewrite the row**

`backend/src/Repository/EntryListRow.php` (whole file):

```php
<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Entry;

/** One row of the entry list: the shared Entry plus the caller's subscription to it and marks on it. */
final readonly class EntryListRow
{
    public function __construct(
        public Entry $entry,
        public EntryListRowSubscription $subscription,
        public EntryListRowMarks $marks,
        /**
         * The subscription's watermark, already folded into marks->isHidden; carried so EntryStateResolver can record
         * when it hid the entry.
         */
        public ?\DateTimeImmutable $markedReadUntil,
        /** @var list<self> */
        public array $duplicates = [],
        public EntryListRowEnrichment $enrichment = new EntryListRowEnrichment(),
    ) {
    }

    /**
     * @param list<self> $duplicates the in-scope copies this row collapsed
     */
    public function withDuplicates(array $duplicates): self
    {
        return $this->copyWith($duplicates, $this->enrichment);
    }

    /**
     * This row and each of its duplicates, given the enrichment of its own entry.
     *
     * @param array<int, EntryListRowEnrichment> $enrichmentsByEntryId
     */
    public function enrichedFrom(array $enrichmentsByEntryId): self
    {
        return $this->copyWith(
            array_map(
                static fn (self $duplicate): self => $duplicate->enrichedFrom($enrichmentsByEntryId),
                $this->duplicates,
            ),
            $enrichmentsByEntryId[$this->entry->requireId()],
        );
    }

    /**
     * @param list<self> $duplicates
     */
    private function copyWith(array $duplicates, EntryListRowEnrichment $enrichment): self
    {
        return new self(
            $this->entry,
            $this->subscription,
            $this->marks,
            $this->markedReadUntil,
            $duplicates,
            $enrichment,
        );
    }
}
```

Delete `backend/src/Repository/EntryListRowViewState.php`:

```bash
git rm backend/src/Repository/EntryListRowViewState.php
```

- [ ] **Step 5: Build the marks in both hydrators**

`backend/src/Repository/EntryListRowHydrator.php`, the `return new EntryListRow(...)` in `hydrate()`:

```php
        return new EntryListRow(
            entry: $entry,
            subscription: new EntryListRowSubscription(self::toInt($row['subscriptionId']), $this->rowTitle($row)),
            isHidden: $this->rowIsHidden($row, $entry),
            isFavorite: (bool) ($row['esFavorite'] ?? false),
            isKept: (bool) ($row['esKept'] ?? false),
            viewState: new EntryListRowViewState(
                isViewed: (bool) ($row['esViewed'] ?? false),
                viewedAt: $row['esViewedAt'] instanceof \DateTimeImmutable ? $row['esViewedAt'] : null,
                sharedAt: $row['eshSharedAt'] instanceof \DateTimeImmutable ? $row['eshSharedAt'] : null,
            ),
            markedReadUntil: $row['markedReadUntil'] instanceof \DateTimeImmutable
                ? $row['markedReadUntil']
                : null,
        );
```
→
```php
        return new EntryListRow(
            entry: $entry,
            subscription: new EntryListRowSubscription(self::toInt($row['subscriptionId']), $this->rowTitle($row)),
            marks: new EntryListRowMarks(
                isHidden: $this->rowIsHidden($row, $entry),
                isFavorite: (bool) ($row['esFavorite'] ?? false),
                isKept: (bool) ($row['esKept'] ?? false),
                viewedAt: $row['esViewedAt'] instanceof \DateTimeImmutable ? $row['esViewedAt'] : null,
                sharedAt: $row['eshSharedAt'] instanceof \DateTimeImmutable ? $row['eshSharedAt'] : null,
            ),
            markedReadUntil: $row['markedReadUntil'] instanceof \DateTimeImmutable
                ? $row['markedReadUntil']
                : null,
        );
```

`backend/src/Repository/EntryProjection.php`, `rowQueryBuilder()`: delete the line

```php
            ->addSelect('es.isViewed AS esViewed')
```

(`es.viewedAt AS esViewedAt` on the next line stays; nothing else reads `esViewed` from this projection.)

`backend/src/Repository/RecommendationItemRepository.php`, `rowQueryBuilder()` line 69:

```php
            ->addSelect('es.isViewed AS esViewed')
```
→
```php
            ->addSelect('es.viewedAt AS esViewedAt')
```

and in `hydrateRow()` (line 192):

```php
        $listRow = new EntryListRow(
            entry: $entry,
            subscription: new EntryListRowSubscription(self::toInt($row['subscriptionId']), $this->rowTitle($row)),
            isHidden: EffectiveReadState::isHidden(
                $esHidden === null ? null : (bool) $esHidden,
                $markedReadUntil instanceof \DateTimeInterface ? $markedReadUntil : null,
                $entry->getEffectiveDate(),
            ),
            isFavorite: (bool) ($row['esFavorite'] ?? false),
            isKept: (bool) ($row['esKept'] ?? false),
            // The for-you feed ranks by score, never by view time, so its rows
            // carry no viewedAt — nothing reads it on this path.
            viewState: new EntryListRowViewState(
                isViewed: (bool) ($row['esViewed'] ?? false),
                viewedAt: null,
                sharedAt: $row['eshSharedAt'] instanceof \DateTimeImmutable ? $row['eshSharedAt'] : null,
            ),
            markedReadUntil: $markedReadUntil instanceof \DateTimeImmutable ? $markedReadUntil : null,
        );
```
→
```php
        $listRow = new EntryListRow(
            entry: $entry,
            subscription: new EntryListRowSubscription(self::toInt($row['subscriptionId']), $this->rowTitle($row)),
            marks: new EntryListRowMarks(
                isHidden: EffectiveReadState::isHidden(
                    $esHidden === null ? null : (bool) $esHidden,
                    $markedReadUntil instanceof \DateTimeInterface ? $markedReadUntil : null,
                    $entry->getEffectiveDate(),
                ),
                isFavorite: (bool) ($row['esFavorite'] ?? false),
                isKept: (bool) ($row['esKept'] ?? false),
                viewedAt: $row['esViewedAt'] instanceof \DateTimeImmutable ? $row['esViewedAt'] : null,
                sharedAt: $row['eshSharedAt'] instanceof \DateTimeImmutable ? $row['eshSharedAt'] : null,
            ),
            markedReadUntil: $markedReadUntil instanceof \DateTimeImmutable ? $markedReadUntil : null,
        );
```

(The stale "carry no viewedAt" comment goes: the row now carries it, because `isViewed()` is derived from it.)

- [ ] **Step 6: Move every reader to the new fields**

`backend/src/Repository/EntryListSort.php`, `instantOf()`:
```php
            self::ViewedAt => $row->viewedAt ?? throw new \LogicException(
```
→ `self::ViewedAt => $row->marks->viewedAt ?? throw new \LogicException(`
```php
            self::SharedAt => $row->sharedAt ?? throw new \LogicException(
```
→ `self::SharedAt => $row->marks->sharedAt ?? throw new \LogicException(`

`backend/src/Http/EntryJson.php`, `listRow()`:
```php
            'subscriptionId' => $row->subscriptionId,
            'source' => $row->subscriptionTitle,
```
→
```php
            'subscriptionId' => $row->subscription->id,
            'source' => $row->subscription->title,
```
and
```php
            'isHidden' => $row->isHidden,
            'isFavorite' => $row->isFavorite,
            'isKept' => $row->isKept,
            'isShared' => $row->sharedAt !== null,
            'isViewed' => $row->isViewed,
```
→
```php
            'isHidden' => $row->marks->isHidden,
            'isFavorite' => $row->marks->isFavorite,
            'isKept' => $row->marks->isKept,
            'isShared' => $row->marks->isShared(),
            'isViewed' => $row->marks->isViewed(),
```
and in `posterOf()` (line 198): `'name' => $row->subscriptionTitle,` → `'name' => $row->subscription->title,`

`backend/src/Service/Reading/EntryStateResolver.php` line 38:
```php
        $this->states->ensureRow($userId, $entryId, $row->isHidden ? $row->markedReadUntil : null);
```
→
```php
        $this->states->ensureRow($userId, $entryId, $row->marks->isHidden ? $row->markedReadUntil : null);
```

`backend/src/Service/Reading/EntryStateUpdater.php`, `applyShared()`: line 69 `return $row->sharedAt;` → `return $row->marks->sharedAt;`; line 77 `return $this->shares->record($user, $row->entry) ?? $row->sharedAt;` → `return $this->shares->record($user, $row->entry) ?? $row->marks->sharedAt;`

`backend/src/Service/Mail/Digest/DigestComposer.php` line 71: `$row->subscriptionTitle,` → `$row->subscription->title,`

`backend/src/Service/Search/EntrySearch/IndexedEntrySearch.php`: docblock line 60 `EntryListRow::$isHidden by the projection. The read rows still counted` → `EntryListRowMarks::$isHidden by the projection. The read rows still counted`; line 71 `static fn (EntryListRow $row): bool => !$row->isHidden,` → `static fn (EntryListRow $row): bool => !$row->marks->isHidden,`

- [ ] **Step 7: Confirm no reader of the old fields is left**

Run (from `backend/`):
```bash
grep -rnE -- "EntryListRowViewState|esViewed\b|->row->(isHidden|isFavorite|isKept|isViewed|subscriptionId|subscriptionTitle)|\\\$row->(isHidden|isFavorite|isKept|isViewed|viewedAt|sharedAt|subscriptionId|subscriptionTitle)\b" src tests
```
Expected: only hits whose `$row` is not an `EntryListRow` (e.g. none, or `$change->`-style DTO reads are excluded by the pattern). Any `EntryListRow` hit means a missed site — fix it the same way.

- [ ] **Step 8: Run the touched tests**

Run:
```bash
php bin/phpunit tests/Repository/EntryListRowMarksTest.php tests/Repository/EntryListTest.php tests/Repository/RecommendationFeedTest.php tests/Repository/EntryListRowEnricherTest.php tests/Http/EntryPageTest.php tests/Http/SearchPageTest.php tests/Http/EntryJsonTest.php tests/Http/SavedSearchPageTest.php tests/Http/RecommendationFeedJsonTest.php tests/Service/Reading/EntryStateResolverTest.php tests/Service/SavedPosts/SavedBlueskyPostInteractionTest.php tests/Service/Trending/Pass/StoryCardSourceTest.php
```
Expected: PASS. If `RecommendationFeedTest` fails on `viewed`→`isViewed()`, the `es.viewedAt AS esViewedAt` select in `RecommendationItemRepository` is missing.

- [ ] **Step 9: Commit**

```bash
git add -A backend/src/Repository backend/src/Http/EntryJson.php backend/src/Service/Reading backend/src/Service/Mail/Digest/DigestComposer.php backend/src/Service/Search/EntrySearch/IndexedEntrySearch.php backend/tests
git commit -m "refactor(#2108): keep the row's marks and subscription as fields, drop EntryListRowViewState"
```

---

### Task 3: Gates

**Files:** none new; fix whatever a gate reports in the files Tasks 1–2 touched.

- [ ] **Step 1: Code style**

Run (from `backend/`): `composer cs`
Expected: no errors. Fix with `composer cs:fix` if it is only formatting.

- [ ] **Step 2: PHPStan**

Run: `bin/console cache:warmup && composer stan`
Expected: `[OK] No errors`. A `property.notFound` on `EntryListRow` names a missed read site — move it to `->marks->` / `->subscription->`.

- [ ] **Step 3: PHPMD**

Run: `composer md`
Expected: no finding in any touched `src` file (`EntryListRow`, `EntryListRowMarks`, `EntryListRowHydrator`, `EntryProjection`, `RecommendationItemRepository`, `EntryListSort`, `EntryJson`, `EntryStateResolver`, `EntryStateUpdater`, `DigestComposer`, `IndexedEntrySearch`). If a pre-existing finding sits in one of them, it must be fixed by a real design change before commit (CLAUDE.md standing rule); report it rather than suppressing.

- [ ] **Step 4: phptramp**

Run: `composer tramp`
Expected: no failing chain.

- [ ] **Step 5: Full suite**

Run: `php bin/phpunit`
Expected: OK.

- [ ] **Step 6: Mutation gate**

Run: `composer infection:diff`
Expected: MSI at or above `minMsi`. `EntryListRowMarks::isViewed()`/`isShared()` mutants are killed by `EntryListRowMarksTest`; the `viewedAt` line in `RecommendationItemRepository` by `RecommendationFeedTest`.

- [ ] **Step 7: Commit any gate fixes**

```bash
git add -A backend/src backend/tests
git commit -m "refactor(#2108): satisfy the gates"
```
(Skip if nothing changed.)
