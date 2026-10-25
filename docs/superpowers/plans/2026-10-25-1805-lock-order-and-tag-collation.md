# Social Import Lock Order and Tag-Name Collation — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Fix the two defects in issue #1805. The social import takes the tag lock before it writes anything. Tag names that differ only by an accent become two tags instead of crashing an import.

**Architecture:** `SocialImporter::import` swaps `wrapInTransaction` for `TagStructureLock::hold`, so the user row is locked before the removals write. `BulkSubscriber`'s own hold then nests harmlessly inside. `Tag::$name` pins `utf8mb4_bin` in its mapping, as `UserIdentity::$providerUserId` does. A MySQL-only migration converts the column, so the database, the `LOWER()` lookups and the batch cache's `mb_strtolower` agree that case doesn't count and accents do.

**Tech Stack:** Symfony 7.4, PHP 8.4, Doctrine ORM/DBAL/Migrations, PHPUnit 12, MySQL 8 (dev 8.4, prod 8.0.36) and SQLite.

**Spec:** GitHub issue #1805 (`gh issue view 1805`). The design approved in chat on 2026-10-25 is restated here:
- **Lock order:** `SocialImporter::import` runs its work inside `TagStructureLock::hold`, not `wrapInTransaction`. `BulkSubscriber` keeps its own hold, which nests.
- **Names:** tag names compare **accent-sensitive, case-insensitive**. "Bär" ≠ "Bar", while "bar" = "Bar". This comes from a `utf8mb4_bin` collation on `tag.name`, pinned in the mapping (`MySqlCollationSchemaListener` strips it on SQLite).
- **Out of scope:** Unicode normalisation (NFC vs NFD).

## Global Constraints

- Tag names: accents distinguish names, case does not. Case-insensitive matching stays where it is, in `LOWER(t.name) = LOWER(:name)` and in the batch cache's `mb_strtolower`.
- Use the collation string exactly as written: `utf8mb4_bin`.
- The migration does nothing on SQLite and refuses any other platform, like `migrations/Version20260922094802.php`.
- Every lock test is skipped on SQLite with the existing message `'SQLite has no row locks; it serialises writers itself.'`.
- CLAUDE.md applies:
  - Clean Code rules for PHP, including the comment bar (default to no comment) and PHPMD-clean touched `src` files.
  - "Migrations need their own verification": the suite never runs migrations.
- Commit messages are `fix(#1805): …` with no attribution lines.

## File Structure

| File | Change |
|---|---|
| `backend/src/Service/Social/SocialImporter.php` | the hold replaces `wrapInTransaction`; an explicit flush before returning |
| `backend/tests/Service/Social/SocialImporterTest.php` | the lock is taken before the first write (MySQL leg) |
| `backend/src/Entity/Tag.php` | `$name` gets `options: ['collation' => 'utf8mb4_bin']` |
| `backend/migrations/Version20261025170000.php` | **create**: MySQL `ALTER TABLE tag MODIFY name … COLLATE utf8mb4_bin` |
| `backend/tests/Service/Subscription/BulkSubscriberTest.php` | accent twins in one batch; a stored accented tag is not matched |
| `backend/tests/Service/Tag/TagEditorTest.php` | "Bär" beside "Bar" is accepted; a non-ASCII name in another case is still refused |

---

### Task 1: The social import locks before it writes

**Files:**
- Modify: `backend/src/Service/Social/SocialImporter.php` (constructor and `import()`)
- Test: `backend/tests/Service/Social/SocialImporterTest.php`

**Interfaces:**
- Consumes: `App\Service\Tag\TagStructureLock::hold(User $user, \Closure $work): mixed`. It begins a transaction, runs `SELECT … FOR UPDATE` on the user row, runs `$work` and commits, rolling back and rethrowing on any `\Throwable`. Unlike `wrapInTransaction`, it does **not** flush.
- Produces: nothing new for later tasks.

- [ ] **Step 1: Write the failing test**

Add the imports `use App\Tests\Support\QueryRecorder;` and `use Doctrine\DBAL\Platforms\SQLitePlatform;` to `SocialImporterTest`, then add:

```php
public function testTheTagLockIsTakenBeforeTheImportWritesAnything(): void
{
    if ($this->entityManager->getConnection()->getDatabasePlatform() instanceof SQLitePlatform) {
        self::markTestSkipped('SQLite has no row locks; it serialises writers itself.');
    }
    $goneId = $this->subscribedId($this->user, 'https://bsky.app/profile/did:plc:gone/rss');
    $account = $this->storedAccount(SocialAccounts::blueskyOf($this->user));
    $recorder = self::getContainer()->get(QueryRecorder::SERVICE_ID);
    self::assertInstanceOf(QueryRecorder::class, $recorder);
    $recorder->reset();

    $this->importer()->import($account, new SocialImportModel([
        new SocialImportFeedModel('did:plc:bob', 'Bob'),
    ], [$goneId]));

    $statements = $recorder->queries();
    $firstLock = self::firstIndexOf(
        $statements,
        static fn (string $sql): bool => str_contains(strtolower($sql), 'for update'),
    );
    $firstWrite = self::firstIndexOf(
        $statements,
        static fn (string $sql): bool => 1 === preg_match('/^\s*(insert|update|delete)\b/i', $sql),
    );
    self::assertNotNull($firstLock);
    self::assertNotNull($firstWrite);
    self::assertLessThan($firstWrite, $firstLock, 'the user row is locked before the import writes');
}

/**
 * @param list<string>           $statements
 * @param \Closure(string): bool $matches
 */
private static function firstIndexOf(array $statements, \Closure $matches): ?int
{
    foreach ($statements as $index => $statement) {
        if ($matches($statement)) {
            return $index;
        }
    }

    return null;
}
```

- [ ] **Step 2: Run on MySQL, expect FAIL**

The removal's `DELETE` runs before `BulkSubscriber`'s `FOR UPDATE`.

Run (from the repo root): `docker compose exec -T php bin/phpunit tests/Service/Social/SocialImporterTest.php --filter TheTagLockIsTaken`
Expected: FAIL on `assertLessThan`.

- [ ] **Step 3: Implement**

Inject the lock: add `private TagStructureLock $tagStructureLock,` to the constructor, with `use App\Service\Tag\TagStructureLock;`. In `import()`, replace the `wrapInTransaction` call. Keep the existing "Removals first" comment above it:

```php
// Removals first, so the slots they free count toward the subscription cap.
return $this->tagStructureLock->hold(
    $account->getUser(),
    function () use ($account, $items, $privateFeeds, $toRemove): SocialImportResultModel {
        $removed = $this->subscriptionService->unsubscribeAll(array_values($toRemove));
        $subscribed = $this->bulkSubscriber->subscribeAll($account->getUser(), $items);
        $this->importedPictures->picture(
            $account,
            array_map(static fn (BulkSubscribeItemModel $item): string => $item->feedUrl, $items),
        );
        $result = SocialImportResultModel::from(
            $subscribed,
            $this->addPrivateFeeds($account, $privateFeeds),
            $removed,
        );
        $this->entityManager->flush();

        return $result;
    },
);
```

The explicit `flush()` stands in for the one `wrapInTransaction` used to do before committing. Without it, writes made after `subscribeAll`'s own flush would stay unflushed: the pictures and the private feeds.

- [ ] **Step 4: Run, expect PASS on both legs**

Run: `php bin/phpunit tests/Service/Social` (SQLite, from `backend/`). Then run `docker compose exec -T php bin/phpunit tests/Service/Social tests/Service/Subscription tests/Service/Opml` from the repo root (MySQL).
Expected: all pass. The new test is skipped on SQLite. `OpmlImporterTest`'s and `BulkSubscriberTest`'s "exactly one `for update`" tests still pass, because only the social import nests the hold.

- [ ] **Step 5: Commit**

```bash
git add backend/src/Service/Social/SocialImporter.php backend/tests/Service/Social/SocialImporterTest.php
git commit -m "fix(#1805): the social import locks the tag structure before it writes"
```

---

### Task 2: Tag names compare accents, not case

**Files:**
- Modify: `backend/src/Entity/Tag.php` (the `$name` column)
- Create: `backend/migrations/Version20261025170000.php`
- Test: `backend/tests/Service/Subscription/BulkSubscriberTest.php`, `backend/tests/Service/Tag/TagEditorTest.php`

**Interfaces:**
- Consumes: `MySqlCollationSchemaListener` (`backend/src/Doctrine/`), which strips `utf8mb4_*` collations from generated schemas on non-MySQL platforms. `BulkSubscriberTest` already has `user()`, `subscriber()` and `namesOf()`. `TagEditorTest` has `user()` and `editor()`. Check both by reading the files.
- Produces: nothing new.

- [ ] **Step 1: Write the failing tests**

`BulkSubscriberTest` (it already imports `Tag`):

```php
public function testNamesThatDifferOnlyByAnAccentAreTwoTags(): void
{
    $user = $this->user('accent-twins@example.com');

    $result = $this->subscriber()->subscribeAll($user, [
        new BulkSubscribeItemModel('https://cafe-accented.example.com/rss.xml', 'Accented', ['Café']),
        new BulkSubscribeItemModel('https://cafe-plain.example.com/rss.xml', 'Plain', ['Cafe']),
    ]);

    self::assertSame(['Café', 'Cafe'], $this->namesOf($result->tagsCreated));
}

public function testAStoredTagIsNotMatchedByItsNameWithoutTheAccent(): void
{
    $user = $this->user('accent-stored@example.com');
    $this->entityManager->persist(new Tag($user, 'Café'));
    $this->entityManager->flush();

    $result = $this->subscriber()->subscribeAll($user, [
        new BulkSubscribeItemModel('https://cafe-later.example.com/rss.xml', 'Later', ['Cafe']),
    ]);

    self::assertSame(['Cafe'], $this->namesOf($result->tagsCreated));
}
```

`TagEditorTest` (add `use Doctrine\DBAL\Platforms\SQLitePlatform;` if absent):

```php
public function testCreateAcceptsANameThatDiffersOnlyByAnAccent(): void
{
    $user = $this->user('tag-accent@example.com');
    $this->editor()->create($user, new TagDetailsModel('Bar'));

    $tag = $this->editor()->create($user, new TagDetailsModel('Bär'));

    self::assertSame('Bär', $tag->getName());
}

public function testCreateRefusesANonAsciiNameTheUserHasInAnotherCase(): void
{
    if ($this->entityManager->getConnection()->getDatabasePlatform() instanceof SQLitePlatform) {
        self::markTestSkipped('SQLite LOWER() folds ASCII only.');
    }
    $user = $this->user('tag-umlaut-case@example.com');
    $this->editor()->create($user, new TagDetailsModel('Ärger'));

    $this->expectException(TagNameTakenException::class);
    $this->editor()->create($user, new TagDetailsModel('ÄRGER'));
}
```

- [ ] **Step 2: Run on MySQL, expect three FAILs and one PASS**

Run (repo root): `docker compose exec -T php bin/phpunit tests/Service/Subscription/BulkSubscriberTest.php tests/Service/Tag/TagEditorTest.php --filter 'Accent|NonAscii'`
Expected:
- `testNamesThatDifferOnlyByAnAccentAreTwoTags`: error, `UniqueConstraintViolationException` on `uniq_tag_user_name`.
- `testAStoredTagIsNotMatchedByItsNameWithoutTheAccent`: fails, because no tag is created; the lookup returns "Café".
- `testCreateAcceptsANameThatDiffersOnlyByAnAccent`: error, `TagNameTakenException`.
- `testCreateRefusesANonAsciiNameTheUserHasInAnotherCase`: PASS. It pins behaviour that the change must keep.

On SQLite (`php bin/phpunit` with the same filter from `backend/`) the three accent tests already pass, since SQLite is binary. That is expected; MySQL is the leg that proves the fix.

- [ ] **Step 3: Pin the collation in the mapping**

In `Tag.php`, change the `$name` column to:

```php
/** `utf8mb4_bin` so an accent makes another name; the lookups' LOWER() keeps case out of it (#1805). */
#[ORM\Column(length: 100, options: ['collation' => 'utf8mb4_bin'])]
private string $name;
```

The MySQL test database is built from metadata, so this alone turns the Step 1 tests green there. Run Step 2's command again now. Expected: all four pass.

- [ ] **Step 4: Find the exact DDL the mapping expects**

Run (repo root), against the dev database, which is still on the old collation: `docker compose exec -T php bin/console doctrine:schema:update --dump-sql`
Expected: among the output, one statement for the tag table, in the form `ALTER TABLE tag CHANGE name name VARCHAR(100) NOT NULL COLLATE \`utf8mb4_bin\`` (or `MODIFY …`). Copy that statement's column definition **verbatim** into the migration below, so that the migration builds exactly what `schema:validate` expects. If the dump shows other, unrelated statements, ignore them and mention them in the report.

- [ ] **Step 5: Write the migration**

`backend/migrations/Version20261025170000.php`. Copy the platform guard and `isTransactional()` exactly as `Version20260922094802.php` has them. Read that file; if it has no `isTransactional()`, copy it from `Version20261025120000.php`.

```php
<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261025170000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Compare tag names by bytes on MySQL (utf8mb4_bin): an accent makes a different name, case still does not (#1805).';
    }

    public function up(Schema $schema): void
    {
        if (!$this->assertSupportedPlatform() instanceof AbstractMySQLPlatform) {
            return;
        }

        $this->addSql('ALTER TABLE tag MODIFY name VARCHAR(100) NOT NULL COLLATE `utf8mb4_bin`');
    }

    public function down(Schema $schema): void
    {
        if (!$this->assertSupportedPlatform() instanceof AbstractMySQLPlatform) {
            return;
        }

        $this->addSql('ALTER TABLE tag MODIFY name VARCHAR(100) NOT NULL');
    }

    private function assertSupportedPlatform(): AbstractMySQLPlatform|SQLitePlatform
    {
        $platform = $this->connection->getDatabasePlatform();
        $this->abortIf(
            !($platform instanceof AbstractMySQLPlatform) && !($platform instanceof SQLitePlatform),
            sprintf('No DDL defined for platform %s; only MySQL and SQLite are supported.', $platform::class),
        );

        /** @var AbstractMySQLPlatform|SQLitePlatform $platform */
        return $platform;
    }
}
```

Replace the `up()` SQL with Step 4's statement if they differ. `down()` returns the column to the table default. It fails if the user has since created accent-twin tags; that is acceptable for a rollback and needs no guard. The upgrade itself cannot raise a unique violation, because binary comparison only splits names that used to match.

- [ ] **Step 6: Verify the migration on the dev database**

Run (repo root):
- `docker compose exec -T php bin/console doctrine:migrations:migrate --no-interaction`
- then `docker compose exec -T php bin/console doctrine:schema:validate`

Expected: the migration applies, and validate reports the mapping and database **in sync**. Also confirm it took effect: `docker compose exec -T php bin/console dbal:run-sql "SELECT COLLATION_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tag' AND COLUMN_NAME = 'name'"` must print `utf8mb4_bin`.

Then, natively from `backend/`, run `php bin/console doctrine:schema:validate --skip-sync --env=test` to check the mapping. The listener strips the collation on SQLite.

CI's migration leg (migrate from empty on SQLite and MySQL, then `schema:validate`) is the final proof. Watch for it on the PR.

- [ ] **Step 7: Run the touched suites on both legs, expect PASS**

From `backend/`: `php bin/phpunit tests/Service/Subscription tests/Service/Tag tests/Service/Opml tests/Service/Backup tests/Repository/TagRepositoryTest.php`. Then the same paths from the repo root with `docker compose exec -T php bin/phpunit …`.

- [ ] **Step 8: Commit**

```bash
git add backend/src/Entity/Tag.php backend/migrations/Version20261025170000.php \
  backend/tests/Service/Subscription/BulkSubscriberTest.php backend/tests/Service/Tag/TagEditorTest.php
git commit -m "fix(#1805): tag names that differ only by an accent are two tags"
```

---

### Task 3: Gates

- [ ] From `backend/`, run `composer check` and `composer md`. Touched `src` files must be PHPMD-clean.
- [ ] Run the PhpStorm inspections (`mcp__phpstorm__lint_files`) on the changed PHP. ERROR and WARNING findings block.
- [ ] Run the full suite on both legs: `composer test:parallel` natively, and `docker compose exec -T php composer test` from the repo root.
- [ ] Run `composer infection:diff`. Kill any escaped mutant on a touched line with a test.
- [ ] Scan today's dev log: `ls -t backend/var/log/dev-*.log | head -1 | xargs tail -n 300 | jq -r 'select(.level_name=="ERROR" or .level_name=="CRITICAL")|.message'`.
