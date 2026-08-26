# Entry State Hidden Rename Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Rename the entry-state `isRead` concept to `isHidden` across persistence, backend contracts, backups, and the Angular client without changing behavior.

**Architecture:** Make one atomic backend and backup contract change because the entity accessors, API fields, queries, and backup reader must compile together. Then change the Angular client against the new API contract. Do not add compatibility aliases: backup schema version 2 is the explicit break, while the HTTP contract changes in the same release.

**Tech Stack:** Symfony 7.4, PHP 8.4, Doctrine ORM and migrations, PHPUnit 12, Angular 20, TypeScript, Jest, Docker Compose, MySQL 8.4, SQLite.

**Spec:** `docs/superpowers/specs/2026-08-18-482-viewed-hidden-model-design.md` and GitHub issue #483.

## Global Constraints

- This is a pure rename. Do not change read/viewed behavior.
- Rename `EntryState.isRead` to `isHidden`, `readAt` to `hiddenAt`, and `markRead()` to `hide()`.
- Rename database columns `is_read` to `is_hidden` and `read_at` to `hidden_at` with a forward and reverse migration that preserves data.
- Rename API and backup JSON keys to `isHidden` and `hiddenAt`.
- Increase `BackupSchema::VERSION` from `1` to `2`. Version-1 backups must fail the existing exact-version check. Do not add backward compatibility.
- Keep `MarkReadService`, `MarkReadRequest`, the mark-read routes, `isReadByAnotherUser`, `EffectiveReadState` as a class name, `markUnread()`, and all user-facing “Mark all read” text unchanged.
- Rename `EffectiveReadState::isRead()` to `EffectiveReadState::isHidden()` and update its internal terminology, but do not rename the class.
- Rename `ViewedImpliesReadListener` to `ViewedImpliesHiddenListener`; keep the on-flush subset invariant unchanged.
- Keep `isViewed` and `viewedAt` unchanged.
- Do not change historic plan or spec documents merely because they contain the old symbol names.
- Keep the native iOS API viable: JSON requests and responses only, bearer auth, and `application/problem+json` errors.
- Every changed backend `src` file must be PHPMD-clean. Do not loosen any quality threshold.
- Run frontend tests inside the Docker frontend container.
- Use commit subjects in the form `type(#483): lower-case summary`.

---

### Task 1: Rename the backend, persistence, and backup contract

**Files:**

- Create: `backend/migrations/Version20260826120000.php`
- Rename: `backend/src/Doctrine/ViewedImpliesReadListener.php` to `backend/src/Doctrine/ViewedImpliesHiddenListener.php`
- Modify: `backend/src/Entity/EntryState.php`
- Modify: `backend/src/Doctrine/ViewedImpliesHiddenListener.php`
- Modify: `backend/src/Dto/Entry/UpdateEntryStateRequest.php`
- Modify: `backend/src/Controller/Api/EntryController.php`
- Modify: `backend/src/Controller/Api/EntrySearchController.php`
- Modify: `backend/src/Command/E2eSeedAdminSubscriptionCommand.php`
- Modify: `backend/src/Http/EntryJson.php`
- Modify: `backend/src/Http/EntryStateJson.php`
- Modify: `backend/src/Repository/EffectiveReadState.php`
- Modify: `backend/src/Repository/EntryListRepository.php`
- Modify: `backend/src/Repository/EntryListRow.php`
- Modify: `backend/src/Repository/EntryStateRepository.php`
- Modify: `backend/src/Repository/RecommendationItemRepository.php`
- Modify: `backend/src/Repository/UnreadDql.php`
- Modify: `backend/src/Service/Reader/EntryStateResolver.php`
- Modify: `backend/src/Service/Reader/MarkReadService.php`
- Modify: `backend/src/Service/Reader/SearchMarkReadService.php`
- Modify: `backend/src/Service/Backup/BackupSchema.php`
- Modify: `backend/src/Service/Backup/AccountBackupExporter.php`
- Modify: `backend/src/Service/Backup/Dto/EntryStateLine.php`
- Modify: `backend/src/Service/Backup/RestoreEntryLoader.php`
- Create: `backend/tests/Fixtures/backup/version-2.ndjson`
- Modify: `backend/tests/Command/E2eSeedAdminSubscriptionCommandTest.php`
- Modify: `backend/tests/Controller/Api/EntryControllerTest.php`
- Modify: `backend/tests/Controller/Api/EntrySearchMarkReadTest.php`
- Modify: `backend/tests/E2e/ReaderJourneyE2eTest.php`
- Modify: `backend/tests/Entity/EntryStateTest.php`
- Modify: `backend/tests/Entity/SubscriptionTest.php`
- Modify: `backend/tests/Http/EntryPageTest.php`
- Modify: `backend/tests/Http/RecommendationFeedJsonTest.php`
- Modify: `backend/tests/Http/SearchPageTest.php`
- Modify: `backend/tests/Repository/EntryListTest.php`
- Modify: `backend/tests/Repository/RecommendationFeedTest.php`
- Modify: `backend/tests/Repository/StateCountsTest.php`
- Modify: `backend/tests/Service/Backup/AccountBackupExporterTest.php`
- Modify: `backend/tests/Service/Backup/AccountRestorerTest.php`
- Modify: `backend/tests/Service/Backup/BackupInspectorTest.php`
- Modify: `backend/tests/Service/Backup/BackupReaderTest.php`
- Modify: `backend/tests/Service/Backup/GoldenBackupRestoreTest.php`
- Modify: `backend/tests/Service/Backup/RestorePreviewerTest.php`
- Modify: `backend/tests/Service/Reader/MarkReadServiceTest.php`
- Modify: `backend/tests/Service/Reader/SearchMarkReadServiceTest.php`
- Modify: `backend/tests/Support/BackupFieldDeclarations.php`
- Modify: `backend/tests/Support/FullyPopulatedAccount.php`
- Modify: `docs/backup.md`

**Interfaces:**

- Consumes: the #482 invariant `isViewed => isRead`, the existing exact backup-version check, and the current mark-all-read behavior.
- Produces: `EntryState::isHidden()`, `setIsHidden()`, `getHiddenAt()`, `setHiddenAt()`, and `hide()`; API keys `isHidden` and `hiddenAt`; backup schema version 2; database columns `is_hidden` and `hidden_at`.

- [ ] **Step 1: Change focused tests to name the new contract**

Update entity, controller, repository, JSON, mark-all-read, and backup tests before production code. The tests must require these exact shapes:

```php
self::assertTrue($state->isHidden());
self::assertSame($when, $state->getHiddenAt());

self::assertSame([
    'entryId' => $entryId,
    'isHidden' => true,
    'isFavorite' => false,
    'isKept' => false,
    'hiddenAt' => $when->format(\DateTimeInterface::ATOM),
    'isViewed' => true,
    'viewedAt' => $when->format(\DateTimeInterface::ATOM),
], $json);
```

Keep tests that prove these behavior rules:

```text
viewed true forces hidden true through the real EntityManager flush
viewed false leaves hidden true
mark-all-read changes hidden only and does not change viewed
isHidden false clears viewed through markUnread()
```

Change backup expectations to schema version `2`, `isHidden`, and `hiddenAt`. Keep `current.ndjson` and `oldest-supported.ndjson` unchanged as version-1 input. Add `version-2.ndjson` with the current data and the new keys. Change the golden test so version 2 restores and both version-1 fixtures fail with the existing unsupported-version error.

- [ ] **Step 2: Run the focused tests and confirm contract failures**

Run:

```bash
cd backend
php bin/phpunit tests/Entity/EntryStateTest.php tests/Controller/Api/EntryControllerTest.php tests/Repository/EntryListTest.php tests/Repository/StateCountsTest.php tests/Service/Backup tests/Service/Reader/MarkReadServiceTest.php tests/Service/Reader/SearchMarkReadServiceTest.php
```

Expected: FAIL because the new methods, fields, JSON keys, and backup version do not exist yet. Confirm at least one failure names each contract group: entity, API/JSON, repository query, and backup.

- [ ] **Step 3: Rename the entity and invariant listener**

Use this entity surface exactly:

```php
#[ORM\Column]
private bool $isHidden = false;

#[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
private ?\DateTimeImmutable $hiddenAt = null;

public function isHidden(): bool
public function setIsHidden(bool $isHidden): void
public function getHiddenAt(): ?\DateTimeImmutable
public function setHiddenAt(?\DateTimeImmutable $hiddenAt): void
public function hide(\DateTimeImmutable $when): void
```

Keep `markUnread()` as the method name, but make it clear `isHidden` and `hiddenAt`. Rename the listener class and file to `ViewedImpliesHiddenListener`. Its guard must be equivalent to:

```php
if (!$entity instanceof EntryState || !$entity->isViewed() || $entity->isHidden()) {
    continue;
}

$entity->hide($entity->getViewedAt() ?? $this->clock->now());
```

- [ ] **Step 4: Rename backend projections, DQL, API fields, and internal comments**

Rename symbols and aliases with the hidden meaning. For example:

```php
final readonly class EntryListRow
{
    public function __construct(
        // existing fields
        public bool $isHidden,
        // existing fields
    ) {}
}

final class EffectiveReadState
{
    public static function isHidden(
        ?bool $explicitFlag,
        ?\DateTimeInterface $markedReadUntil,
        \DateTimeImmutable $effectiveDate,
    ): bool
}
```

Use `es.isHidden` and `es.hiddenAt` in Doctrine DQL. Use `esHidden` for scalar query aliases. Use `isHidden` and `hiddenAt` in request DTOs and response arrays. Update comments only when they describe renamed code or the hidden/unread model. Do not change `MarkReadService`, `MarkReadRequest`, `isReadByAnotherUser`, `EffectiveReadState` as a class name, routes, translation keys, or user-facing labels.

- [ ] **Step 5: Rename the backup contract and increase the version**

Set:

```php
public const int VERSION = 2;
```

Make `EntryStateLine`, the exporter, the restore loader, field declarations, tests, and `docs/backup.md` use `isHidden` and `hiddenAt`. The restore loader must call `setIsHidden()` and `setHiddenAt()`. Do not accept version 1.

- [ ] **Step 6: Add the portable column-rename migration**

Create `Version20260826120000.php`. Accept only MySQL and SQLite. The up migration must run these operations in this order:

```sql
ALTER TABLE entry_state RENAME COLUMN is_read TO is_hidden
ALTER TABLE entry_state RENAME COLUMN read_at TO hidden_at
```

The down migration must reverse them in reverse order. Do not update row values. The #482 decision removed the legacy-row reconciliation, and this ticket is a pure rename.

- [ ] **Step 7: Run focused and full backend verification**

Run the focused command from Step 2, then:

```bash
cd backend
composer check
composer md
php bin/phpunit
composer infection:diff
```

Expected: all required gates pass. If `composer md` reports pre-existing project findings, no changed `src` file may appear in its output.

- [ ] **Step 8: Verify the migration from empty on SQLite and MySQL**

Use a new scratch SQLite database and a new scratch MySQL database. Do not clear or replace the development database. For each database, run the full migration chain and then `doctrine:schema:validate`.

Expected on both engines: all migrations apply and Doctrine reports that the mapping and database schema are in sync. Then apply the migration to the live Docker development database with the normal Doctrine migration command and restart the worker because it holds backend code in memory.

- [ ] **Step 9: Audit exclusions and commit**

Run symbol searches. Production backend and current tests must not contain old entry-state symbols, except for the required exclusions and old migration files:

```bash
rg -n '\bisRead\b|\breadAt\b|\bmarkRead\b|\bis_read\b|\bread_at\b' backend/src backend/tests backend/migrations
```

Inspect every remaining match. `MarkReadService`, `MarkReadRequest`, `isReadByAnotherUser`, `markUnread()`, user-facing text, and historical migration definitions are allowed. Entry-state properties, methods, DQL fields, API fields, backup fields, and current tests are not.

Commit:

```bash
git add backend docs/backup.md
git commit -m "refactor(#483): rename backend entry state to hidden"
```

### Task 2: Rename the Angular client contract

**Files:**

- Modify: `frontend/src/app/reader/models.ts`
- Modify: `frontend/src/app/reader/reader-api.ts`
- Modify: `frontend/src/app/reader/entries.store.ts`
- Modify: `frontend/src/app/reader/reader-shell.component.ts`
- Modify: `frontend/src/app/reader/entry-row/entry-row.component.html`
- Modify: `frontend/src/app/reader/magazine/entry-compact.component.html`
- Modify: `frontend/src/app/reader/magazine/entry-hero.component.html`
- Modify: `frontend/src/app/reader/magazine/entry-kicker-line.component.html`
- Modify: `frontend/src/app/reader/magazine/entry-kicker.component.html`
- Modify: `frontend/src/app/reader/magazine/entry-quote.component.html`
- Modify: `frontend/src/app/reader/magazine/entry-split.component.html`
- Modify: `frontend/src/app/reader/magazine/entry-thumb.component.html`
- Modify: `frontend/src/app/reader/magazine/entry-wide.component.html`
- Modify: all matching Jest specifications under `frontend/src/app/reader/`
- Modify: all matching Playwright fixtures and route stubs under `frontend/e2e/`

**Interfaces:**

- Consumes: backend `EntryDto`, `EntryStateDto`, and PATCH fields named `isHidden` and `hiddenAt`.
- Produces: Angular entry models, local patches, templates, unit fixtures, and e2e route fixtures that use `isHidden` only.

- [ ] **Step 1: Change frontend tests and fixtures to the new wire name**

Rename test inputs and assertions from `isRead` to `isHidden`, and `readAt` to `hiddenAt`. Keep the same boolean values and behavior. Require this local coupling:

```typescript
expect(localStatePatch({ isViewed: true })).toEqual({
  isViewed: true,
  isHidden: true,
});
expect(localStatePatch({ isViewed: false })).toEqual({ isViewed: false });
expect(localStatePatch({ isHidden: false })).toEqual({
  isHidden: false,
  isViewed: false,
});
```

- [ ] **Step 2: Run focused Jest tests and confirm failures**

Run inside Docker:

```bash
docker compose exec -T frontend npm test -- --runInBand src/app/reader/entries.store.spec.ts src/app/reader/reader-api.spec.ts src/app/reader/entry-row/entry-row.component.spec.ts src/app/reader/reader-shell.component.spec.ts
```

Expected: FAIL because the client model and implementation still use `isRead`.

- [ ] **Step 3: Rename client models, store logic, templates, and comments**

Use these fields:

```typescript
export interface EntryDto {
  // existing fields
  isHidden: boolean;
  // existing fields
}

export interface EntryStateDto {
  entryId: number;
  isHidden: boolean;
  isFavorite: boolean;
  isKept: boolean;
  hiddenAt: string | null;
  isViewed: boolean;
  viewedAt: string | null;
}

export interface EntryStatePatch {
  isHidden?: boolean;
  isFavorite?: boolean;
  isKept?: boolean;
  isViewed?: boolean;
}
```

Change `localStatePatch()` to use `isHidden` with unchanged behavior. Change unread filters, row dimming, and circle state to use `isHidden` with the same polarity they use for `isRead` today. Do not change the tick, which uses `isViewed`. Do not rename mark-all-read APIs, commands, labels, or translation keys.

- [ ] **Step 4: Format and run frontend verification**

Format all changed TypeScript, HTML, and SCSS files. Then run:

```bash
docker compose exec -T frontend npm run check
docker compose exec -T frontend npm run build
```

Expected: both commands pass.

- [ ] **Step 5: Audit exclusions and commit**

Run:

```bash
rg -n '\bisRead\b|\breadAt\b' frontend/src frontend/e2e
```

Expected: no entry-state field matches remain. Human-facing “read”, `markRead`, and `isReadByAnotherUser` are outside this symbol search or remain unchanged by design.

Commit:

```bash
git add frontend
git commit -m "refactor(#483): rename frontend entry state to hidden"
```

### Final verification and review

- [ ] Re-run `composer check`, `composer md`, `php bin/phpunit`, and `composer infection:diff` in `backend/`.
- [ ] Re-run `docker compose exec -T frontend npm run check` and `docker compose exec -T frontend npm run build`.
- [ ] Re-run the empty-database migration and schema validation on SQLite and MySQL.
- [ ] Scan the active dated backend development log for new deprecations or errors.
- [ ] Run a whole-branch review against the merge base with `develop`.
- [ ] Confirm the branch diff contains no unrelated changes and no changes to the named exclusions.
