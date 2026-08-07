# Viewed Entry State Implementation Plan (#307)

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Record which entries the user *actively opened* (`is_viewed` + `viewed_at` on `entry_state`), one-way, set from the frontend on entry-detail open and on the "open original" link — training data for the AI recommendation feature (#308).

**Architecture:** A pair of additive columns on the existing `entry_state` table, a one-way `markViewed()` on the entity (no setter — the flag can never be cleared), an optional `isViewed` field on the existing `PATCH /api/entries/{id}/state` partial update, the flag carried through the shared list projection (`EntryListRow` → `EntryJson` → `EntryDto`), and two frontend call sites that reuse the existing optimistic `patchOpen()` path.

**Tech Stack:** Symfony 7.4 / PHP 8.4 / Doctrine migrations (platform-aware MySQL + SQLite), Angular 20 signals, Jest.

## Global Constraints

- `declare(strict_types=1)` in every PHP file; PSR-12 (`composer cs`); PHPStan level max (`composer stan`, warm the dev cache first with `bin/console cache:warmup`).
- Every touched `src` file must be **PHPMD-clean** (`composer md`), not merely free of new findings.
- Datetimes are stored as **naive UTC** — the controller's injected `ClockInterface` is the only time source.
- Migrations are never executed by the test suite (schema is built from ORM metadata); the DDL must be **platform-aware** (MySQL + SQLite branches) and additive. CI's migrate-from-empty leg is the only thing that runs it.
- No hidden side effects: the viewed flag is set only via the PATCH endpoint, never as a side effect of a GET.
- Controllers hold no private methods that carry responsibility (`ThinControllerRule`).
- Mutation testing gates changed files (`composer infection:diff`) — every guard branch added here needs a test that kills its mutant.
- Frontend: Prettier 100-col, `npm run check` from `frontend/` is the gate. No new UI, so no i18n keys.
- Commit messages follow the house pattern: `type(#307): imperative summary`.

## File Structure

| File | Responsibility |
|---|---|
| `backend/src/Entity/EntryState.php` | `isViewed`/`viewedAt` columns + one-way `markViewed()` |
| `backend/migrations/Version20260807120000.php` | additive ALTER on `entry_state`, both dialects |
| `backend/src/Dto/Entry/UpdateEntryStateRequest.php` | optional `isViewed`, `false` rejected by validation |
| `backend/src/Controller/Api/EntryController.php` | apply `markViewed`, extend the state response |
| `backend/src/Repository/EntryListRow.php` + `EntryRepository.php` | carry `isViewed` in the shared projection |
| `backend/src/Http/EntryJson.php` | expose `isViewed` on every listed entry |
| `frontend/src/app/reader/models.ts` | `EntryDto.isViewed`, `EntryStatePatch.isViewed`, `EntryStateDto.isViewed/viewedAt` |
| `frontend/src/app/reader/reader-shell.component.{ts,html}` | mark viewed on open (combined with mark-read) + `onOpenOriginal` |
| `frontend/src/app/reader/reader-view/reader-view.component.{ts,html}` | `openOriginal` output on the original-article link |

---

### Task 1: One-way `markViewed()` on the entity

**Files:**
- Modify: `backend/src/Entity/EntryState.php`
- Test: `backend/tests/Entity/EntryStateTest.php` (create)

**Interfaces:**
- Produces: `EntryState::isViewed(): bool`, `EntryState::getViewedAt(): ?\DateTimeImmutable`, `EntryState::markViewed(\DateTimeImmutable $when): void` (idempotent; keeps the first timestamp). There is deliberately **no** `setIsViewed()`.

- [ ] **Step 1: Write the failing test**

Create `backend/tests/Entity/EntryStateTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\Entry;
use App\Entity\EntryState;
use App\Entity\Feed;
use App\Entity\User;
use PHPUnit\Framework\TestCase;

final class EntryStateTest extends TestCase
{
    public function testAFreshStateIsNotViewed(): void
    {
        $state = $this->makeState();

        self::assertFalse($state->isViewed());
        self::assertNull($state->getViewedAt());
    }

    public function testMarkViewedSetsFlagAndTimestamp(): void
    {
        $state = $this->makeState();
        $firstOpen = new \DateTimeImmutable('2026-08-07T10:00:00Z');

        $state->markViewed($firstOpen);

        self::assertTrue($state->isViewed());
        self::assertSame($firstOpen, $state->getViewedAt());
    }

    public function testMarkViewedKeepsTheFirstTimestamp(): void
    {
        $state = $this->makeState();
        $firstOpen = new \DateTimeImmutable('2026-08-07T10:00:00Z');
        $laterOpen = new \DateTimeImmutable('2026-08-07T11:00:00Z');

        $state->markViewed($firstOpen);
        $state->markViewed($laterOpen);

        self::assertTrue($state->isViewed());
        self::assertSame($firstOpen, $state->getViewedAt());
    }

    private function makeState(): EntryState
    {
        $user = new User('reader@example.com', new \DateTimeImmutable('2026-07-01T00:00:00Z'));
        $feed = new Feed('https://example.com/feed.xml');
        $entry = new Entry(
            $feed,
            'guid-1',
            'https://example.com/1',
            'Post',
            new \DateTimeImmutable('2026-07-01T00:00:00Z'),
        );

        return new EntryState($user, $entry);
    }
}
```

If `new User(...)` or `new Feed(...)` signatures differ from what the neighboring tests use, mirror `backend/tests/Repository/EntryListTest.php:28` and `backend/tests/Controller/Api/EntryControllerTest.php:39` — those are the canonical constructions.

- [ ] **Step 2: Run the test to verify it fails**

Run (from `backend/`): `php bin/phpunit tests/Entity/EntryStateTest.php`
Expected: FAIL — `Call to undefined method App\Entity\EntryState::isViewed()`.

- [ ] **Step 3: Implement the entity change**

In `backend/src/Entity/EntryState.php`, after the `$readAt` property (line 38-39):

```php
    #[ORM\Column]
    private bool $isViewed = false;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $viewedAt = null;
```

After `setReadAt()` (end of class):

```php
    public function isViewed(): bool
    {
        return $this->isViewed;
    }

    public function getViewedAt(): ?\DateTimeImmutable
    {
        return $this->viewedAt;
    }

    /**
     * One-way by design (#307): "viewed" records that the user actively opened
     * the entry at least once, so there is no setter and no way to clear it,
     * and a repeat open keeps the first open's timestamp.
     */
    public function markViewed(\DateTimeImmutable $when): void
    {
        if ($this->isViewed) {
            return;
        }
        $this->isViewed = true;
        $this->viewedAt = $when;
    }
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `php bin/phpunit tests/Entity/EntryStateTest.php`
Expected: PASS (3 tests).

- [ ] **Step 5: Commit**

```bash
git add backend/src/Entity/EntryState.php backend/tests/Entity/EntryStateTest.php
git commit -m "feat(#307): one-way viewed flag on EntryState"
```

---

### Task 2: Migration

**Files:**
- Create: `backend/migrations/Version20260807120000.php`

**Interfaces:**
- Produces: columns `entry_state.is_viewed` (bool, NOT NULL, default 0) and `entry_state.viewed_at` (nullable DATETIME). Existing rows read as "never viewed".

- [ ] **Step 1: Write the migration**

Mirror the additive-column pattern of `Version20260731130000.php` (platform-aware, `skipIf`, non-transactional):

```php
<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Adds entry_state.is_viewed and entry_state.viewed_at for #307: "viewed"
 * records an active open, which read_at cannot express (the bulk mark-read
 * sweep stamps it too).
 *
 * PLATFORM-AWARE DDL: tests build their schema from ORM metadata and never
 * execute a migration, so a dialect error here is caught only by CI's
 * migrate-from-empty leg.
 *
 * ADDITIVE ONLY. No backfill: existing read_at data cannot distinguish a
 * click from a sweep, so every existing row reads as "never viewed".
 */
final class Version20260807120000 extends AbstractMigration
{
    private const TABLE = 'entry_state';

    public function getDescription(): string
    {
        return 'Add entry_state.is_viewed and entry_state.viewed_at (#307).';
    }

    public function isTransactional(): bool
    {
        return false;
    }

    public function up(Schema $schema): void
    {
        $this->skipIf(
            $schema->hasTable(self::TABLE)
                && $schema->getTable(self::TABLE)->hasColumn('is_viewed')
                && $schema->getTable(self::TABLE)->hasColumn('viewed_at'),
            'entry_state viewed columns already exist; nothing to do.',
        );

        $platform = $this->connection->getDatabasePlatform();

        if ($platform instanceof AbstractMySQLPlatform) {
            $this->addSql('ALTER TABLE entry_state ADD is_viewed TINYINT(1) DEFAULT 0 NOT NULL, ADD viewed_at DATETIME DEFAULT NULL');

            return;
        }

        if ($platform instanceof SQLitePlatform) {
            $this->addSql('ALTER TABLE entry_state ADD COLUMN is_viewed BOOLEAN DEFAULT 0 NOT NULL');
            $this->addSql('ALTER TABLE entry_state ADD COLUMN viewed_at DATETIME DEFAULT NULL');

            return;
        }

        $this->abortIf(true, \sprintf(
            'No DDL defined for platform %s; only MySQL and SQLite are supported.',
            $platform::class,
        ));
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE entry_state DROP COLUMN is_viewed');
        $this->addSql('ALTER TABLE entry_state DROP COLUMN viewed_at');
    }
}
```

- [ ] **Step 2: Verify against MySQL in Docker**

Run (from the repo root; requires the stack: `docker compose up -d`):

```bash
docker compose exec php bin/console doctrine:migrations:migrate --no-interaction
docker compose exec php bin/console doctrine:schema:validate
```

Expected: migration executes, and schema:validate reports **[OK]** for both mapping and database sync. If sync fails on the new columns, the DDL types drifted from the ORM metadata — fix the migration, not the entity.

- [ ] **Step 3: Commit**

```bash
git add backend/migrations/Version20260807120000.php
git commit -m "feat(#307): entry_state viewed columns migration"
```

---

### Task 3: PATCH endpoint accepts one-way `isViewed`

**Files:**
- Modify: `backend/src/Dto/Entry/UpdateEntryStateRequest.php`
- Modify: `backend/src/Controller/Api/EntryController.php:125-160` (`updateState`)
- Test: `backend/tests/Controller/Api/EntryControllerTest.php`

**Interfaces:**
- Consumes: `EntryState::markViewed(\DateTimeImmutable)` from Task 1.
- Produces: `PATCH /api/entries/{id}/state` accepts optional `isViewed` (only `true`; `false` → 422 `validation_error`). The `state` response object gains `isViewed: bool` and `viewedAt: ?string` (ATOM).

- [ ] **Step 1: Write the failing tests**

Add to `backend/tests/Controller/Api/EntryControllerTest.php`, next to the existing state tests (mirror the request style of `testPatchStateLazilyCreatesAndReturnsState`, line 181 — same `auth()`/`seedFeedWithEntries()` helpers, same JSON request shape):

```php
    public function testPatchStateMarksViewed(): void
    {
        $client = self::createClient();
        [$headers, $user] = $this->auth('e-viewed@example.com');
        $this->seedFeedWithEntries($user, 1);
        $entryId = $this->firstEntryId($client, $headers);

        $client->request(
            'PATCH',
            "/api/entries/$entryId/state",
            server: $headers + ['CONTENT_TYPE' => 'application/json'],
            content: '{"isViewed":true}',
        );

        self::assertResponseIsSuccessful();
        $body = json_decode((string) $client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($body);
        self::assertIsArray($body['state']);
        self::assertTrue($body['state']['isViewed']);
        self::assertNotNull($body['state']['viewedAt']);
        self::assertFalse($body['state']['isRead']);
    }

    public function testPatchStateRejectsUnviewing(): void
    {
        $client = self::createClient();
        [$headers, $user] = $this->auth('e-unview@example.com');
        $this->seedFeedWithEntries($user, 1);
        $entryId = $this->firstEntryId($client, $headers);

        $client->request(
            'PATCH',
            "/api/entries/$entryId/state",
            server: $headers + ['CONTENT_TYPE' => 'application/json'],
            content: '{"isViewed":false}',
        );

        self::assertResponseStatusCodeSame(422);
    }

    public function testViewedSurvivesOtherStatePatches(): void
    {
        $client = self::createClient();
        [$headers, $user] = $this->auth('e-viewed-keep@example.com');
        $this->seedFeedWithEntries($user, 1);
        $entryId = $this->firstEntryId($client, $headers);

        $client->request(
            'PATCH',
            "/api/entries/$entryId/state",
            server: $headers + ['CONTENT_TYPE' => 'application/json'],
            content: '{"isViewed":true}',
        );
        $client->request(
            'PATCH',
            "/api/entries/$entryId/state",
            server: $headers + ['CONTENT_TYPE' => 'application/json'],
            content: '{"isRead":true,"isFavorite":true}',
        );

        self::assertResponseIsSuccessful();
        $body = json_decode((string) $client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($body);
        self::assertIsArray($body['state']);
        self::assertTrue($body['state']['isViewed']);
        self::assertNotNull($body['state']['viewedAt']);
    }

    /** @param array<string,string> $headers */
    private function firstEntryId(
        \Symfony\Bundle\FrameworkBundle\KernelBrowser $client,
        array $headers,
    ): int {
        $client->request('GET', '/api/entries', server: $headers);
        $body = json_decode((string) $client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($body);
        self::assertIsArray($body['entries']);
        self::assertIsArray($body['entries'][0]);

        return (int) $body['entries'][0]['id'];
    }
```

If the existing state tests already extract an entry id another way, reuse that mechanism instead of adding `firstEntryId()` — do not have two idioms in one file.

- [ ] **Step 2: Run the tests to verify they fail**

Run: `php bin/phpunit tests/Controller/Api/EntryControllerTest.php`
Expected: the three new tests FAIL (missing `isViewed` key / 200 instead of 422); every pre-existing test still passes.

- [ ] **Step 3: Implement DTO + controller**

`backend/src/Dto/Entry/UpdateEntryStateRequest.php` — add the field and the constraint import:

```php
<?php

declare(strict_types=1);

namespace App\Dto\Entry;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * Partial update: a null field means "leave unchanged". At least one non-null
 * field is expected, but an all-null body is a harmless no-op, not an error.
 */
final readonly class UpdateEntryStateRequest
{
    public function __construct(
        public ?bool $isRead = null,
        public ?bool $isFavorite = null,
        public ?bool $isKept = null,
        // One-way (#307): `viewed` can be set, never cleared. Constraints skip
        // null, so only an explicit false is rejected.
        #[Assert\IsTrue(message: 'isViewed is one-way and can only be set to true.')]
        public ?bool $isViewed = null,
    ) {
    }
}
```

`backend/src/Controller/Api/EntryController.php`, in `updateState()` — after the `isKept` block (line 147-149):

```php
        if ($request->isViewed === true) {
            $state->markViewed($this->clock->now());
        }
```

And extend the response array (lines 153-159):

```php
        return new JsonResponse(['state' => [
            'entryId' => $id,
            'isRead' => $state->isRead(),
            'isFavorite' => $state->isFavorite(),
            'isKept' => $state->isKept(),
            'readAt' => $state->getReadAt()?->format(\DateTimeInterface::ATOM),
            'isViewed' => $state->isViewed(),
            'viewedAt' => $state->getViewedAt()?->format(\DateTimeInterface::ATOM),
        ]]);
```

- [ ] **Step 4: Run the tests to verify they pass**

Run: `php bin/phpunit tests/Controller/Api/EntryControllerTest.php`
Expected: PASS, including all pre-existing tests.

- [ ] **Step 5: Commit**

```bash
git add backend/src/Dto/Entry/UpdateEntryStateRequest.php backend/src/Controller/Api/EntryController.php backend/tests/Controller/Api/EntryControllerTest.php
git commit -m "feat(#307): accept one-way isViewed on the entry state PATCH"
```

---

### Task 4: Carry `isViewed` through the list projection

**Files:**
- Modify: `backend/src/Repository/EntryListRow.php`
- Modify: `backend/src/Repository/EntryRepository.php` (`rowQueryBuilder()` line ~125, `hydrateRow()` line ~218)
- Modify: `backend/src/Http/EntryJson.php`
- Test: `backend/tests/Repository/EntryListTest.php`, `backend/tests/Controller/Api/EntryControllerTest.php`

**Interfaces:**
- Produces: `EntryListRow::$isViewed` (public readonly bool, constructor parameter appended after `$isKept`), and `isViewed: bool` on every entry object returned by `GET /api/entries` and `GET /api/entries/{id}`. #308's history queries and the frontend both rely on this being the effective per-user value (`false` when no state row exists).

- [ ] **Step 1: Write the failing tests**

In `backend/tests/Repository/EntryListTest.php`, add a test mirroring the file's existing seeding helpers (user at line 28; follow the neighboring tests' entry/state seeding idiom exactly):

```php
    public function testCarriesTheViewedFlag(): void
    {
        // Seed two entries; mark exactly one viewed via a persisted EntryState.
        // (Adapt the seeding lines to this file's existing helpers.)
        $state = new EntryState($this->user, $viewedEntry);
        $state->markViewed(new \DateTimeImmutable('2026-08-07T10:00:00Z'));
        $this->em->persist($state);
        $this->em->flush();
        $this->em->clear();

        $rows = $this->repository->listForUser(new EntryQuery(
            userId: (int) $this->user->getId(),
            view: 'all',
        ));

        $byTitle = [];
        foreach ($rows as $row) {
            $byTitle[$row->entry->getTitle()] = $row->isViewed;
        }
        self::assertTrue($byTitle['Viewed post']);
        self::assertFalse($byTitle['Untouched post']);
    }
```

In `EntryControllerTest::testListsNewestFirstWithState()` (line 69), add one assertion next to `assertFalse($first['isRead'])`:

```php
        self::assertFalse($first['isViewed']);
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `php bin/phpunit tests/Repository/EntryListTest.php tests/Controller/Api/EntryControllerTest.php`
Expected: FAIL — `EntryListRow` has no `$isViewed`; the JSON has no `isViewed` key.

- [ ] **Step 3: Implement**

`backend/src/Repository/EntryListRow.php` — append the parameter:

```php
    public function __construct(
        public Entry $entry,
        public int $subscriptionId,
        public string $subscriptionTitle,
        public bool $isRead,
        public bool $isFavorite,
        public bool $isKept,
        public bool $isViewed,
    ) {
    }
```

`backend/src/Repository/EntryRepository.php` — in `rowQueryBuilder()`, after `->addSelect('es.isKept AS esKept')`:

```php
            ->addSelect('es.isViewed AS esViewed')
```

In `hydrateRow()`, after the `isKept:` argument:

```php
            isViewed: (bool) ($row['esViewed'] ?? false),
```

`backend/src/Http/EntryJson.php` — in `one()`, after `'isKept' => $row->isKept,`:

```php
            'isViewed' => $row->isViewed,
```

and extend the shape docblock at line 18 accordingly (`isRead: bool, isFavorite: bool, isKept: bool, isViewed: bool`).

- [ ] **Step 4: Run the backend suite**

Run: `php bin/phpunit`
Expected: PASS. Any other test constructing `EntryListRow` positionally will fail compilation — give those call sites an explicit `isViewed: false`.

- [ ] **Step 5: Commit**

```bash
git add backend/src/Repository/EntryListRow.php backend/src/Repository/EntryRepository.php backend/src/Http/EntryJson.php backend/tests/Repository/EntryListTest.php backend/tests/Controller/Api/EntryControllerTest.php
git commit -m "feat(#307): expose isViewed on listed entries"
```

---

### Task 5: Frontend models and fixtures

**Files:**
- Modify: `frontend/src/app/reader/models.ts` (EntryDto line ~54, EntryStateDto line ~82, EntryStatePatch line ~157)
- Modify: every spec fixture that builds an `EntryDto` or `EntryStateDto` literal

**Interfaces:**
- Produces: `EntryDto.isViewed: boolean` (required), `EntryStatePatch.isViewed?: boolean`, `EntryStateDto.isViewed: boolean` + `EntryStateDto.viewedAt: string | null`. Task 6 relies on these names exactly.

- [ ] **Step 1: Extend the types**

In `frontend/src/app/reader/models.ts`:

```ts
export interface EntryDto {
  // … existing fields …
  isRead: boolean;
  isFavorite: boolean;
  isKept: boolean;
  /** One-way: the user actively opened this entry at least once (#307). */
  isViewed: boolean;
}

export interface EntryStateDto {
  entryId: number;
  isRead: boolean;
  isFavorite: boolean;
  isKept: boolean;
  readAt: string | null;
  isViewed: boolean;
  viewedAt: string | null;
}

export interface EntryStatePatch {
  isRead?: boolean;
  isFavorite?: boolean;
  isKept?: boolean;
  isViewed?: boolean;
}
```

- [ ] **Step 2: Update every fixture the compiler flags**

Run (from `frontend/`): `npx jest --silent 2>&1 | head -50` and fix each TS error mechanically:

- `EntryDto` literals: add `isViewed: false` next to `isKept` — known sites: `reader-shell.component.spec.ts` (the `entry` const, line ~60), `reader-view.component.spec.ts` (the `entry()` factory, line ~10), `entries.store.spec.ts`, `entry-list.component.spec.ts`, `entry-row.component.spec.ts`, `magazine/entry-kicker.component.spec.ts`, `magazine/entry-wide.component.spec.ts`, `preview-image.spec.ts`, `reader-api.spec.ts`.
- `EntryStateDto` flush bodies (`state: { entryId: …, readAt: … }`): add `isViewed: false, viewedAt: null` (or `true`/a timestamp where the test flushes a viewed reply).
- Playwright specs under `frontend/e2e/` that stub the entries route: add `isViewed: false` to their entry objects only if `npm run check` complains — they are plain JSON otherwise.

- [ ] **Step 3: Run the frontend gate**

Run: `npm run check`
Expected: PASS (lint + prettier + stylelint + jest).

- [ ] **Step 4: Commit**

```bash
git add frontend/src
git commit -m "feat(#307): isViewed in the frontend entry models"
```

---

### Task 6: Mark viewed on open and on the original-article link

**Files:**
- Modify: `frontend/src/app/reader/reader-shell.component.ts` (effect at lines 245-253, `setRead`/`patchOpen` block at lines 483-512, `markedOnOpen` at line 232)
- Modify: `frontend/src/app/reader/reader-shell.component.html` (the `<app-reader-view>` wiring at line ~115)
- Modify: `frontend/src/app/reader/reader-view/reader-view.component.ts` (outputs at lines 103-105) and `.html` (the anchor at line 94)
- Test: `frontend/src/app/reader/reader-shell.component.spec.ts`, `frontend/src/app/reader/reader-view/reader-view.component.spec.ts`

**Interfaces:**
- Consumes: `EntryStatePatch.isViewed` from Task 5, the existing `patchOpen(e, patch, onError?)` and `withOpen(fn)`.
- Produces: one combined on-open PATCH (`{isRead?: true, isViewed?: true}` — only the flags still unset), `ReaderViewComponent.openOriginal: OutputEmitterRef<void>`, `ReaderShellComponent.onOpenOriginal(e: EntryDto): void`.

- [ ] **Step 1: Write the failing shell specs**

In `reader-shell.component.spec.ts`:

1. Extend `boot()` (line 94) to accept an entry override so tests can boot with pre-set flags:

```ts
  function boot(entryOverride: Partial<typeof entry> = {}) {
    const f = TestBed.createComponent(ReaderShellComponent);
    f.detectChanges(); // ngOnInit + initial effects
    ctrl.expectOne('https://api.test/api/subscriptions').flush(subsBody);
    ctrl.expectOne('https://api.test/api/tags').flush({ tags: [] });
    ctrl
      .expectOne((r) => r.url === 'https://api.test/api/entries')
      .flush({ entries: [{ ...entry, ...entryOverride }], nextCursor: null });
    f.detectChanges();
    return f;
  }
```

2. Update the existing on-open tests (lines 390-413): the expected body becomes `{ isRead: true, isViewed: true }`, and every `state:` flush gains `isViewed`/`viewedAt` fields.
3. Update the deep-link test (line 415): flush the fetched entry with `isRead: true, isViewed: true` and adjust its comment — both flags set so the on-open effect fires no PATCH.
4. Add the new cases:

```ts
  it('marks an already-read entry viewed on open', () => {
    const f = boot({ isRead: true });
    qp.next(convertToParamMap({ entry: '1' }));
    f.detectChanges();
    const req = ctrl.expectOne('https://api.test/api/entries/1/state');
    expect(req.request.body).toEqual({ isViewed: true });
    req.flush({
      state: {
        entryId: 1,
        isRead: true,
        isFavorite: false,
        isKept: false,
        readAt: 'x',
        isViewed: true,
        viewedAt: 'x',
      },
    });
  });

  it('does not re-mark an already-viewed entry on open', () => {
    const f = boot({ isRead: true, isViewed: true });
    qp.next(convertToParamMap({ entry: '1' }));
    f.detectChanges();
    ctrl.expectNone((r) => r.url.endsWith('/entries/1/state'));
    ctrl.verify();
  });

  it('marks the entry viewed when the original-article link is followed', () => {
    const f = boot({ isRead: true }); // open fires only the viewed patch…
    qp.next(convertToParamMap({ entry: '1' }));
    f.detectChanges();
    ctrl
      .expectOne('https://api.test/api/entries/1/state')
      .flush(
        { type: 'x', title: 't', status: 500 },
        { status: 500, statusText: 'err' },
      ); // …which fails and rolls back, so the link click is the real retry path.
    f.detectChanges();

    f.componentInstance.onOpenOriginal({ ...entry, isRead: true, isViewed: false });
    const req = ctrl.expectOne('https://api.test/api/entries/1/state');
    expect(req.request.body).toEqual({ isViewed: true });
  });
```

- [ ] **Step 2: Write the failing reader-view spec**

In `reader-view.component.spec.ts` (uses the file's `entry()` factory and `mount()` helper):

```ts
  it('emits openOriginal when the original-article link is clicked', () => {
    const f = mount(entry({ url: 'https://example.com/full-story' }));
    const emitted = jest.fn();
    f.componentInstance.openOriginal.subscribe(emitted);

    const link = f.debugElement.query(By.css('a[target="_blank"]'));
    link.triggerEventHandler('click', null);

    expect(emitted).toHaveBeenCalled();
  });
```

(Import `By` from `@angular/platform-browser` if the spec does not already.)

- [ ] **Step 3: Run the specs to verify they fail**

Run: `npx jest reader-shell reader-view`
Expected: the new tests FAIL (body mismatch / no `openOriginal` output); pre-existing tests around the on-open PATCH also FAIL until Step 4 lands — that is the point of updating them first.

- [ ] **Step 4: Implement**

`reader-view.component.ts` — add after `readonly read = output<void>();` (line 105):

```ts
  readonly openOriginal = output<void>();
```

`reader-view.component.html` — the anchor at line 94:

```html
          <a
            [href]="e.url"
            target="_blank"
            rel="noopener noreferrer"
            (click)="openOriginal.emit()"
            >{{ 'reader.openOriginal' | transloco }} <app-icon name="open_in_new" size="text"
          /></a>
```

`reader-shell.component.ts`:

1. Next to `markedOnOpen` (line 232):

```ts
  private readonly viewedOnOpen = new Set<number>();
```

2. Replace the mark-read-on-open effect (lines 245-253):

```ts
    // Mark the opened entry read and viewed, each exactly once per session —
    // even if the PATCH fails and the flags roll back, we never re-fire. One
    // combined request: the endpoint is a partial update, and both flags
    // change at the same moment (the open).
    effect(() => {
      const e = this.openEntry();
      if (!e) return;
      const patch: EntryStatePatch = {};
      if (!e.isRead && !this.markedOnOpen.has(e.id)) {
        this.markedOnOpen.add(e.id);
        patch.isRead = true;
      }
      if (!e.isViewed && !this.viewedOnOpen.has(e.id)) {
        this.viewedOnOpen.add(e.id);
        patch.isViewed = true;
      }
      if (patch.isRead === undefined && patch.isViewed === undefined) return;
      untracked(() => this.applyOpenedPatch(e, patch));
    });
```

3. Next to `setRead()` (line 483):

```ts
  /** The on-open patch: read + viewed in one request, with the unread badge
   *  kept in sync (and reverted on failure) only for the read part — viewed
   *  has no badge. */
  private applyOpenedPatch(e: EntryDto, patch: EntryStatePatch): void {
    if (patch.isRead) this.subs.decrementUnread(e.subscriptionId);
    this.patchOpen(e, patch, () => {
      if (patch.isRead) this.subs.incrementUnread(e.subscriptionId);
    });
  }

  /** Following the original-article link is an active open even when the
   *  entry was opened before; the flag is one-way, so an already-viewed
   *  entry is a no-op (this fires only after an on-open PATCH rolled back). */
  onOpenOriginal = (e: EntryDto): void => {
    if (!e.isViewed) this.patchOpen(e, { isViewed: true });
  };
```

Import `EntryStatePatch` in the shell if not already imported.

`reader-shell.component.html` — on the `<app-reader-view>` element (the block wiring `(favorite)="withOpen(onFavorite)"`, line ~115):

```html
          (openOriginal)="withOpen(onOpenOriginal)"
```

- [ ] **Step 5: Run the frontend gate**

Run: `npm run check`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add frontend/src
git commit -m "feat(#307): mark entries viewed on open and on the original link"
```

---

### Task 7: Quality gates, both database legs, PR

**Files:** none new — verification only.

- [ ] **Step 1: Backend static gates**

Run (from `backend/`):

```bash
bin/console cache:warmup
composer check
composer md
```

Expected: all clean. Fix any finding in a touched file (standing rule: touched `src` files must be PHPMD-clean, not merely no-new-findings).

- [ ] **Step 2: PhpStorm inspections**

Run `mcp__phpstorm__lint_files` on every changed PHP file. Block on ERROR and WARNING; weak warnings are advisory.

- [ ] **Step 3: Both test legs**

```bash
php bin/phpunit
docker compose exec php vendor/bin/phpunit
```

Expected: both green. (Known flake: the MySQL leg has order-dependent rate-limiter failures that pass in isolation — re-run the failing test alone before blaming this change.)

- [ ] **Step 4: Mutation gate**

Run: `composer infection:diff`
Expected: MSI at or above the `minMsi` in `infection.json5`. Escaped mutants in `markViewed()`, the controller branch, or `hydrateRow()` mean a missing assertion — add the test, do not touch the threshold.

- [ ] **Step 5: Scan the dev log**

Inspect `backend/var/log/dev.log` for new deprecations or swallowed errors after the test runs.

- [ ] **Step 6: Push and open the PR**

```bash
git push -u origin feature/307-viewed-entry-state
gh pr create --base develop --title "Record actively opened entries as viewed (#307)" --body "$(cat <<'EOF'
Closes #307.

Adds the one-way `viewed` entry state: `is_viewed` + `viewed_at` on `entry_state`, an optional `isViewed` on the state PATCH (only `true` is accepted), the flag on every listed entry, and two frontend triggers — opening the entry detail and following the original-article link. No backfill: `read_at` cannot distinguish a click from a bulk mark-read sweep.

This is groundwork for the AI recommendation feed (#308), which weights viewed posts as its third-strongest interest signal.
EOF
)"
```

Expected: PR opens against `develop`. Verify CI (including the migrate-from-empty leg) is green; after merge, verify #307 auto-closed.

---

## Self-Review

- **Spec coverage**: columns + one-way semantics (Task 1/2), PATCH extension with `false` rejected (Task 3), detail-open and external-link triggers (Task 6), no backfill (Task 2 doc + issue), effective-value exposure for #308 and the frontend guard (Task 4). ✓
- **Placeholder scan**: Task 4 Step 1 asks the executor to adapt seeding lines to `EntryListTest`'s existing helpers — deliberate: the file's factory idiom is authoritative over guessed code; everything else is concrete. ✓
- **Type consistency**: `markViewed(\DateTimeImmutable)`, `isViewed()`, `getViewedAt()` used identically in Tasks 1/3/4; `EntryStatePatch.isViewed` and `onOpenOriginal` names match between Tasks 5/6. `EntryListRow` gains `isViewed` as the last constructor parameter; Task 4 Step 4 sweeps positional call sites. ✓
