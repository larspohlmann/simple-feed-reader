# Each Jev connection keeps its own profile connection (#1349) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Replace the account-wide `user_ai_settings.profile_source` flag with a per-connection pointer, so every borrowing (Jev) connection keeps its own profile connection.

**Architecture:** `AiProviderSettings::$profileConnection` is a nullable self-referencing ManyToOne (`profile_connection_id`, `ON DELETE SET NULL`), mirroring `User::$activeAiProviderSettings`. One migration adds it, carries every flagged row over to that account's `jev-*` rows, and drops `profile_source`. `ProfileConnectionResolver::borrowedFor()` reads the active row's pointer and still checks it at read time. `PUT /api/me/ai/configs/{id}/profile` names the borrowing row in `{id}` and the target in the body. `AiProviderConfigurator` clears pointers to a row before it removes the row. In the frontend, each Jev row's select binds to that row's `profileConnectionId`.

**Tech Stack:** Symfony 7.4 / PHP 8.4 / Doctrine ORM 3 + Migrations (MySQL 8.4, SQLite), PHPUnit 12, Angular 20 (signals, standalone), Jest.

**Spec:** GitHub issue #1349 (`gh issue view 1349 --repo larspohlmann/simple-feed-reader`). The design below is settled with Lars: implement it, do not redesign it.

## Global Constraints

- Branch `feature/1349-profile-connection-per-jev` (already checked out). Another Claude session may share this checkout: check `git status` and the branch before any `switch`, `reset` or `stash`. Never commit to `develop`.
- Commits: `feat(#1349): …` / `test(#1349): …` / `docs(#1349): …`, lower-case summary, no attribution lines.
- CLAUDE.md house style is binding: `final readonly class`, intent-revealing names (no abbreviations, no single letters), guard clauses, no boolean flag parameters, comments only for non-obvious invariants (one line, three at most), thin controllers (no entity mutation, no private methods), queries only in `src/Repository`, domain code knows no HTTP, PSR-12 with 120 columns. Code lines in this plan longer than 120 columns are wrapped by the implementer.
- Every `src` file a task touches must be PHPMD-clean (`composer md`) before its commit, not merely free of new findings.
- Deletion checks are binding for every new pin: break the covered code, run the test, quote the FAIL in the task report, restore. Restore by copying aside first (`cp <file> "$TMPDIR/<name>.orig"` … `mv` back), never `git checkout -- <file>`.
- Migrations: CI migrates from empty on SQLite and MySQL, then runs `doctrine:schema:validate`. The migrated schema must match the ORM mapping exactly; FK and index names are Doctrine's (`FK_53B8EF3023D39AC1`, `IDX_53B8EF3023D39AC1`, computed with DBAL's `_generateIdentifierName` over `user_ai_settings` + `profile_connection_id`).
- Live dev DB: never clear it, never write SQL to it behind the app. Apply the migration with `doctrine:migrations:migrate` after backing up `user_ai_settings`, at the end of Task 3 (the first point at which the mapping matches the migration).
- Frontend: Jest only inside the Docker `frontend` container, one Jest process at a time; Prettier 100 columns; hex colours and ad-hoc `px` are forbidden in `.scss` (no SCSS change is planned).
- Native-iOS rule (architecture §6): the changed endpoint stays bearer-authenticated, stateless, JSON in, `application/problem+json` out.
- Commands: backend commands run from `backend/`; `docker compose …` from the repository root.
- An implementer reports to the planner, who amends this plan in-branch.

## Decisions this plan makes (the brief left them open)

1. **`{id}` must borrow.** A `PUT` whose `{id}` is a connection that builds its own profile throws `ProfileNotBorrowedException` (`Service/Recommendation/Exception/`), mapped in `RecommendationRunProblems` to `422 profile_not_borrowed`. The borrower check runs before the target check. `DELETE` never checks: clearing is always valid and idempotent (`204`).
2. **Ownership** is checked in the controller only: both ids go through `AiConfigurationForUser::require()`, so another account's row (borrower or target) answers `404`. The chooser does not check it a second time.
3. **The JSON carries the raw pointer** (`profileConnectionId`), even when the target has since become unusable. The resolver filters it at read time, so a run fails with `NO_PROFILE_CONNECTION` as before.
4. **Carry-over** gives each account's `jev-*` rows the newest flagged row (`MAX(id)`). That matches the old `findProfileSourceFor()` tie-break (`id DESC`). The Jev test is the resolver's case-sensitive `jev-` prefix: `CAST(LEFT(model, 4) AS BINARY) = 'jev-'` on MySQL, `substr(model, 1, 4) = 'jev-'` on SQLite (whose `=` is case-sensitive).
5. **`down()`** restores the column and flags each account's newest pointed-at row (`MAX(profile_connection_id)`), which loses information when two Jev rows point at different rows. The SQLite `down()` drops the FK-carrying column with `DROP COLUMN`, following `Version20260809130249`. If SQLite refuses that, report it; the fallback is to make the SQLite `down()` irreversible.
6. **Pointer clean-up on delete** iterates `findAllForUser()` (at most 20 rows) in `AiProviderConfigurator`, entity-level only. No new repository query, and no module edge to `Recommendation`.
7. **Frontend in-flight pick:** one `profilePick` linkedSignal on the component, keyed by borrower id, replaces `shownProfileSourceId`. Only one write can be in flight, because `busy` disables every select. That makes a single keyed pick enough, so no child component is needed. The failure scope `'profile'` gains a `configId` so the banner shows under the row that failed. `drop()` nulls pointers to a removed row, as the server does. `reloadWhenGone` stays: a `404` still means a stale list, with the borrower or the target deleted in another tab.
8. **Fixture API:** `seedReadyAiSettingsFor()` now returns the row. New are `seedInactiveAiSettingsFor()` and `seedProfileConnectionBorrowedBy()`. `seedProfileConnectionFor($user)` keeps its signature and points the user's active row at the new connection, so `TickContextFactoryTest`, `TickLockTtlTest` and `TickPhasesTest` need no change.

## File map

| File | Change |
|---|---|
| `backend/src/Entity/AiProviderSettings.php` | + `$profileConnection` (T1); − `$profileSource` (T3) |
| `backend/migrations/Version20261002200000.php` | create (T1) |
| `backend/src/Service/Ai/AiProviderConfigurator.php` | clear pointers before `remove()` (T2) |
| `backend/src/Service/Recommendation/Profile/ProfileConnectionResolver.php` | read the pointer; + `borrows()`; − `findUsableFor()` (T3) |
| `backend/src/Service/Recommendation/Profile/ProfileConnectionChooser.php` | `choose($borrower, $connection)`, `clear($borrower)` (T3) |
| `backend/src/Service/Recommendation/Exception/ProfileNotBorrowedException.php` | create (T3) |
| `backend/src/Http/Problem/ExceptionProblems/RecommendationRunProblems.php` | + `profile_not_borrowed` arm (T3) |
| `backend/src/Dto/Ai/ChooseProfileConnectionRequest.php` | create (T3) |
| `backend/src/Controller/Api/AiProfileConnectionController.php` | body-mapped target (T3) |
| `backend/src/Http/AiSettingsJson.php` | `profileSource` → `profileConnectionId` (T3) |
| `backend/src/Repository/AiProviderSettingsRepository.php` | − `findProfileSourceFor()` (T3) |
| `backend/tests/Support/RecommendationRunFixtures.php` | pointer fixtures (T3) |
| backend tests | see each task |
| `frontend/src/app/settings/ai/*` | per-row picker (T4) |
| `frontend/public/i18n/{en,de}.json` | per-row info text (T4) |
| `README.md`, `docs/recommendations-runs.md`, `JevProfileStep.php`, `ai-availability.service.ts` | wording (T5) |

---

### Task 1: The pointer column and its migration

**Files:**
- Modify: `backend/src/Entity/AiProviderSettings.php`
- Create: `backend/migrations/Version20261002200000.php`
- Test: `backend/tests/Entity/AiProviderSettingsTest.php`

**Interfaces:**
- Produces: `AiProviderSettings::getProfileConnection(): ?AiProviderSettings`, `AiProviderSettings::setProfileConnection(?AiProviderSettings $profileConnection): void`; column `user_ai_settings.profile_connection_id`. `profileSource`, `isProfileSource()` and `setProfileSource()` stay until Task 3.

- [ ] **Step 1: Write the failing entity tests**

Append to `AiProviderSettingsTest`:

```php
    public function testANewRowBorrowsNoProfileConnection(): void
    {
        self::assertNull($this->settings()->getProfileConnection());
    }

    public function testPointingAtAProfileConnectionRoundTrips(): void
    {
        $jev = $this->settings('Jev');
        $profile = $this->settings('Profile');

        $jev->setProfileConnection($profile);

        self::assertSame($profile, $jev->getProfileConnection());
    }

    public function testClearingTheProfileConnectionLeavesNone(): void
    {
        $jev = $this->settings('Jev');
        $jev->setProfileConnection($this->settings('Profile'));

        $jev->setProfileConnection(null);

        self::assertNull($jev->getProfileConnection());
    }
```

- [ ] **Step 2: Run it and watch it fail**

Run: `php bin/phpunit tests/Entity/AiProviderSettingsTest.php`
Expected: 3 errors, `Call to undefined method App\Entity\AiProviderSettings::getProfileConnection()` / `setProfileConnection()`.

- [ ] **Step 3: Add the mapping**

In `AiProviderSettings`, directly below the `$profileSource` property:

```php
    /**
     * The connection that distils the profile for this one when its engine cannot. AiProviderConfigurator clears it
     * before removing that row; ON DELETE SET NULL is only the database floor.
     */
    #[ORM\ManyToOne(targetEntity: self::class)]
    #[ORM\JoinColumn(name: 'profile_connection_id', nullable: true, onDelete: 'SET NULL')]
    private ?self $profileConnection = null;
```

Directly below `setProfileSource()`:

```php
    public function getProfileConnection(): ?self
    {
        return $this->profileConnection;
    }

    public function setProfileConnection(?self $profileConnection): void
    {
        $this->profileConnection = $profileConnection;
    }
```

- [ ] **Step 4: Run the tests and watch them pass**

Run: `php bin/phpunit tests/Entity/AiProviderSettingsTest.php`
Expected: OK.

- [ ] **Step 5: Write the migration**

Create `backend/migrations/Version20261002200000.php`:

```php
<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/** Each jev-* row inherits its account's flagged row (the newest of two), as findProfileSourceFor() read it. */
final class Version20261002200000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Replace user_ai_settings.profile_source with a per-connection profile_connection_id (#1349).';
    }

    public function up(Schema $schema): void
    {
        $this->skipIf(
            $schema->getTable('user_ai_settings')->hasColumn('profile_connection_id'),
            'user_ai_settings.profile_connection_id already exists.',
        );

        if ($this->mysql()) {
            $this->addSql('ALTER TABLE user_ai_settings ADD profile_connection_id INT DEFAULT NULL');
            $this->addSql('ALTER TABLE user_ai_settings ADD CONSTRAINT FK_53B8EF3023D39AC1 FOREIGN KEY (profile_connection_id) REFERENCES user_ai_settings (id) ON DELETE SET NULL');
            $this->addSql('CREATE INDEX IDX_53B8EF3023D39AC1 ON user_ai_settings (profile_connection_id)');
            $this->addSql(<<<'SQL'
                UPDATE user_ai_settings borrower
                INNER JOIN (
                    SELECT user_id, MAX(id) AS id FROM user_ai_settings WHERE profile_source = 1 GROUP BY user_id
                ) chosen ON chosen.user_id = borrower.user_id
                SET borrower.profile_connection_id = chosen.id
                WHERE CAST(LEFT(borrower.model, 4) AS BINARY) = 'jev-'
                SQL);
            $this->addSql('ALTER TABLE user_ai_settings DROP profile_source');

            return;
        }

        $this->addSql('ALTER TABLE user_ai_settings ADD COLUMN profile_connection_id INTEGER DEFAULT NULL REFERENCES user_ai_settings (id) ON DELETE SET NULL');
        $this->addSql('CREATE INDEX IDX_53B8EF3023D39AC1 ON user_ai_settings (profile_connection_id)');
        $this->addSql(<<<'SQL'
            UPDATE user_ai_settings
            SET profile_connection_id = (
                SELECT MAX(chosen.id) FROM user_ai_settings chosen
                WHERE chosen.user_id = user_ai_settings.user_id AND chosen.profile_source = 1
            )
            WHERE substr(model, 1, 4) = 'jev-'
            SQL);
        $this->addSql('ALTER TABLE user_ai_settings DROP COLUMN profile_source');
    }

    public function down(Schema $schema): void
    {
        $this->skipIf(
            !$schema->getTable('user_ai_settings')->hasColumn('profile_connection_id'),
            'user_ai_settings.profile_connection_id does not exist.',
        );

        if ($this->mysql()) {
            $this->addSql('ALTER TABLE user_ai_settings ADD profile_source TINYINT(1) DEFAULT 0 NOT NULL');
            $this->addSql(<<<'SQL'
                UPDATE user_ai_settings chosen
                INNER JOIN (
                    SELECT MAX(profile_connection_id) AS id FROM user_ai_settings
                    WHERE profile_connection_id IS NOT NULL GROUP BY user_id
                ) newest ON newest.id = chosen.id
                SET chosen.profile_source = 1
                SQL);
            $this->addSql('ALTER TABLE user_ai_settings DROP FOREIGN KEY FK_53B8EF3023D39AC1');
            $this->addSql('DROP INDEX IDX_53B8EF3023D39AC1 ON user_ai_settings');
            $this->addSql('ALTER TABLE user_ai_settings DROP profile_connection_id');

            return;
        }

        $this->addSql('ALTER TABLE user_ai_settings ADD COLUMN profile_source BOOLEAN DEFAULT 0 NOT NULL');
        $this->addSql(<<<'SQL'
            UPDATE user_ai_settings SET profile_source = 1
            WHERE id IN (
                SELECT MAX(profile_connection_id) FROM user_ai_settings
                WHERE profile_connection_id IS NOT NULL GROUP BY user_id
            )
            SQL);
        $this->addSql('DROP INDEX IDX_53B8EF3023D39AC1');
        $this->addSql('ALTER TABLE user_ai_settings DROP COLUMN profile_connection_id');
    }

    /** Refuses any platform but the two supported ones: better a refusal than DDL nobody tested. */
    private function mysql(): bool
    {
        $platform = $this->connection->getDatabasePlatform();

        $this->abortIf(
            !$platform instanceof AbstractMySQLPlatform && !$platform instanceof SQLitePlatform,
            \sprintf('No DDL defined for platform %s; only MySQL and SQLite are supported.', $platform::class),
        );

        return $platform instanceof AbstractMySQLPlatform;
    }
}
```

- [ ] **Step 6: Migrate from empty on scratch SQLite and scratch MySQL**

`doctrine:schema:validate` fails until Task 3, because the entity still maps `profile_source`. Here only prove that the chain executes. From the repository root, first prove that the Docker stack serves this checkout:

```bash
bash backend/bin/e2e-preflight.sh "$(git rev-parse --show-toplevel)"
```

From `backend/`:

```bash
export SCRATCH_SQLITE='sqlite:///%kernel.project_dir%/var/migration-1349.db'
rm -f var/migration-1349.db
DATABASE_URL="$SCRATCH_SQLITE" php bin/console doctrine:migrations:migrate --no-interaction
```

From the repository root:

```bash
export SCRATCH_MYSQL='mysql://root:root@mysql:3306/feedreader_migration_1349?serverVersion=8.4&charset=utf8mb4'
docker compose exec -T -e DATABASE_URL="$SCRATCH_MYSQL" php bin/console doctrine:database:drop --force --if-exists
docker compose exec -T -e DATABASE_URL="$SCRATCH_MYSQL" php bin/console doctrine:database:create
docker compose exec -T -e DATABASE_URL="$SCRATCH_MYSQL" php bin/console doctrine:migrations:migrate --no-interaction
```

Expected on both: `[OK] Successfully migrated to version: DoctrineMigrations\Version20261002200000`.

- [ ] **Step 7: Verify the carry-over and `down()` on a seeded scratch DB, both platforms**

The seed covers these cases:
- account 1 has two flagged rows (5 is the newer), two Jev rows (2 and 8) and an upper-case `JEV-` row (3), which must not match;
- account 2 has one flagged row (6) and one Jev row (4);
- row 7 has no model.

Run the same five steps per platform, each through the platform's prefix:
- SQLite, from `backend/`: `DATABASE_URL="$SCRATCH_SQLITE" php bin/console`. Run `rm -f var/migration-1349.db` first.
- MySQL, from the repository root: `docker compose exec -T -e DATABASE_URL="$SCRATCH_MYSQL" php bin/console`. Run `doctrine:database:drop --force --if-exists` and `doctrine:database:create` first.

```bash
<prefix> doctrine:migrations:migrate 'DoctrineMigrations\Version20261002180000' --no-interaction
<prefix> dbal:run-sql "INSERT INTO app_user (id, email, roles, status, created_at) VALUES (1, 'carry-1@example.test', '[]', 'active', '2026-10-02 12:00:00'), (2, 'carry-2@example.test', '[]', 'active', '2026-10-02 12:00:00')"
<prefix> dbal:run-sql "INSERT INTO user_ai_settings (id, user_id, base_url, api_key_ciphertext, api_key_nonce, api_key_salt, api_key_hint, model, profile_source) VALUES (1, 1, 'https://llm.example.test/v1', 'c', 'n', 's', 'ab12', 'qwen/qwen3.7-flash', 1), (2, 1, 'https://jev.example.test/v1', 'c', 'n', 's', 'ab12', 'jev-latest', 0), (3, 1, 'https://jev.example.test/v1', 'c', 'n', 's', 'ab12', 'JEV-latest', 0), (4, 2, 'https://jev.example.test/v1', 'c', 'n', 's', 'ab12', 'jev-latest', 0), (5, 1, 'https://llm.example.test/v1', 'c', 'n', 's', 'ab12', 'gpt-4o', 1), (6, 2, 'https://llm.example.test/v1', 'c', 'n', 's', 'ab12', 'gpt-4o', 1), (7, 1, 'https://llm.example.test/v1', 'c', 'n', 's', 'ab12', NULL, 0), (8, 1, 'https://jev.example.test/v1', 'c', 'n', 's', 'ab12', 'jev-fast', 0)"
<prefix> doctrine:migrations:migrate --no-interaction
<prefix> dbal:run-sql "SELECT id, user_id, model, profile_connection_id FROM user_ai_settings ORDER BY id"
```

Expected `profile_connection_id` by id: `1 NULL, 2 5, 3 NULL, 4 6, 5 NULL, 6 NULL, 7 NULL, 8 5`.

Then go down and back up:

```bash
<prefix> doctrine:migrations:migrate prev --no-interaction
<prefix> dbal:run-sql "SELECT id FROM user_ai_settings WHERE profile_source = 1 ORDER BY id"
<prefix> doctrine:migrations:migrate --no-interaction
<prefix> dbal:run-sql "SELECT id, profile_connection_id FROM user_ai_settings WHERE profile_connection_id IS NOT NULL ORDER BY id"
```

Expected: the flagged ids are `5, 6`; after the second `up`, `2 5, 4 6, 8 5`. If SQLite refuses `down()`'s `DROP COLUMN profile_connection_id`, record the error verbatim and report it (decision 5). Do not work around it here.

Clean up:

```bash
rm -f var/migration-1349.db   # from backend/
docker compose exec -T -e DATABASE_URL="$SCRATCH_MYSQL" php bin/console doctrine:database:drop --force   # from the root
```

- [ ] **Step 8: Deletion checks**

1. In `setProfileConnection()`, drop the assignment. `testPointingAtAProfileConnectionRoundTrips` must fail. Restore.
2. In `setProfileConnection()`, assign `$profileConnection ?? $this->profileConnection`. `testClearingTheProfileConnectionLeavesNone` must fail. Restore.

The migration's pins are Step 7's expected values: each differs from the column's default, so a broken `UPDATE` shows up as `NULL`.

- [ ] **Step 9: Gates for the touched files, then commit**

```bash
composer cs && composer md
git add backend/src/Entity/AiProviderSettings.php backend/migrations/Version20261002200000.php backend/tests/Entity/AiProviderSettingsTest.php
git commit -m "feat(#1349): a connection points at the connection that distils its profile"
```

**Do not apply the migration to the live dev DB yet.** The entity still maps `profile_source`, which the migration drops. Run Tasks 2 and 3 straight after this one: until Task 3's live apply, the dev stack's AI pages fail on the missing `profile_connection_id`.

---

### Task 2: Deleting a connection clears the pointers to it

**Files:**
- Modify: `backend/src/Service/Ai/AiProviderConfigurator.php` (`deleteConfiguration()`)
- Test: `backend/tests/Service/Ai/AiProviderConfiguratorTest.php`
- Test: `backend/tests/Service/Account/AccountDeleterTest.php`

**Interfaces:**
- Consumes: `AiProviderSettings::getProfileConnection()` and `setProfileConnection()` (Task 1); `AiProviderSettingsRepository::findAllForUser(User): list<AiProviderSettings>` (exists).
- Produces: after `AiProviderConfigurator::deleteConfiguration($settings)`, no sibling of the same account points at `$settings`, in memory or in the database.

- [ ] **Step 1: Write the failing configurator test**

Append to `AiProviderConfiguratorTest`, with the helper beside the other private helpers:

```php
    public function testDeletingAProfileConnectionClearsOnlyThePointersToIt(): void
    {
        $configurator = $this->configurator(['gpt-4o', 'jev-latest']);
        $user = $this->user('cfg-delete-profile@example.test');
        $deleted = $this->readyConfiguration($configurator, $user, 'gpt-4o');
        $kept = $this->readyConfiguration($configurator, $user, 'gpt-4o');
        $borrower = $this->readyConfiguration($configurator, $user, 'jev-latest');
        $otherBorrower = $this->readyConfiguration($configurator, $user, 'jev-latest');
        $borrower->setProfileConnection($deleted);
        $otherBorrower->setProfileConnection($kept);
        $this->entityManager->flush();

        $configurator->deleteConfiguration($deleted);

        self::assertNull($borrower->getProfileConnection());
        self::assertSame($kept, $otherBorrower->getProfileConnection());
        $this->entityManager->clear();
        self::assertSame(
            [null, null, 'gpt-4o'],
            array_map(
                static fn (AiProviderSettings $each): ?string => $each->getProfileConnection()?->getModel(),
                $configurator->listConfigurations($this->reload('cfg-delete-profile@example.test')),
            ),
        );
    }

    private function readyConfiguration(
        AiProviderConfigurator $configurator,
        User $user,
        string $model,
    ): AiProviderSettings {
        $configuration = $configurator
            ->addConfiguration($user, null, 'https://api.example.test/v1', 'sk-abcdef1234')
            ->configuration;
        $configurator->chooseModel($configuration, $model);

        return $configuration;
    }
```

- [ ] **Step 2: Run it and watch it fail**

Run: `php bin/phpunit --filter testDeletingAProfileConnectionClearsOnlyThePointersToIt tests/Service/Ai/AiProviderConfiguratorTest.php`
Expected: FAIL. `assertNull` meets the removed `$deleted` object, or the flush throws Doctrine's "A new entity was found through the relationship".

- [ ] **Step 3: Clear the pointers before the remove**

Replace `deleteConfiguration()` in `AiProviderConfigurator` with the following, and add the private method beside `activateWhenNoneActive()`:

```php
    public function deleteConfiguration(AiProviderSettings $settings): void
    {
        $this->releasePointersTo($settings);
        $this->entityManager->remove($settings);
        $this->entityManager->flush();
    }
```

```php
    private function releasePointersTo(AiProviderSettings $settings): void
    {
        $user = $settings->getUser();
        if ($settings === $user->getActiveAiProviderSettings()) {
            $user->setActiveAiProviderSettings(null);
        }

        foreach ($this->aiProviderSettings->findAllForUser($user) as $sibling) {
            if ($sibling->getProfileConnection() === $settings) {
                $sibling->setProfileConnection(null);
            }
        }
    }
```

Extend the class docblock's last sentence: `The active configuration is a single pointer on User, not a per-row flag; a borrowing row's profile connection is a pointer on that row.`

- [ ] **Step 4: Run the configurator tests**

Run: `php bin/phpunit tests/Service/Ai/AiProviderConfiguratorTest.php`
Expected: OK, including the two existing delete tests.

- [ ] **Step 5: Guard the account-deletion cascade (MySQL leg)**

Account deletion relies on the database: `user_id` cascades, and the new self-FK sets NULL inside that same cascade. Pin it. In `AccountDeleterTest`, add the helper and the test below. The two existing AI tests build the same row inline, which makes this the third occurrence, so rewrite them onto the helper too:

```php
    /** User holds no inverse collection of its AI configurations: only the FK ON DELETE CASCADE can remove them. */
    public function testDeletionTakesTheAccountsAiConfigurations(): void
    {
        $admin = $this->userFactory->create('admin-ai@example.com', roles: ['ROLE_ADMIN']);
        $target = $this->userFactory->create('target-ai@example.com');
        $configurationId = $this->persistedConfigurationOf($target)->requireId();

        $this->deleter->deleteAsAdmin($target, $admin);

        $this->entityManager->clear();
        self::assertNull($this->entityManager->getRepository(AiProviderSettings::class)->find($configurationId));
    }

    /**
     * The full cycle: app_user.active_ai_config_id points at user_ai_settings (ON DELETE SET NULL), whose user_id
     * points back (ON DELETE CASCADE). The delete must resolve both without a violation and leave no row behind.
     */
    public function testDeletionResolvesTheActiveAiConfigurationCycle(): void
    {
        $admin = $this->userFactory->create('admin-ai-2@example.com', roles: ['ROLE_ADMIN']);
        $target = $this->userFactory->create('target-ai-2@example.com');
        $configuration = $this->persistedConfigurationOf($target);
        $target->setActiveAiProviderSettings($configuration);
        $this->entityManager->flush();
        $targetId = $target->requireId();
        $configurationId = $configuration->requireId();

        $this->deleter->deleteAsAdmin($target, $admin);

        $this->entityManager->clear();
        self::assertNull($this->entityManager->getRepository(User::class)->find($targetId));

        $count = $this->entityManager->getConnection()->executeQuery(
            'SELECT COUNT(*) FROM user_ai_settings WHERE id = ?',
            [$configurationId],
        )->fetchOne();
        self::assertSame(0, is_numeric($count) ? (int) $count : -1);
    }

    /** profile_connection_id points at a sibling (ON DELETE SET NULL) that the same cascade removes. */
    public function testDeletionTakesABorrowingConnectionAndTheSiblingItBorrowsFrom(): void
    {
        $admin = $this->userFactory->create('admin-ai-3@example.com', roles: ['ROLE_ADMIN']);
        $target = $this->userFactory->create('target-ai-3@example.com');
        $profileConnection = $this->persistedConfigurationOf($target);
        $borrower = $this->persistedConfigurationOf($target);
        $borrower->setProfileConnection($profileConnection);
        $target->setActiveAiProviderSettings($borrower);
        $this->entityManager->flush();
        $targetId = $target->requireId();

        $this->deleter->deleteAsAdmin($target, $admin);

        $count = $this->entityManager->getConnection()->executeQuery(
            'SELECT COUNT(*) FROM user_ai_settings WHERE user_id = ?',
            [$targetId],
        )->fetchOne();
        self::assertSame(0, is_numeric($count) ? (int) $count : -1);
    }

    private function persistedConfigurationOf(User $owner): AiProviderSettings
    {
        /** @var ApiKeyCipher $cipher */
        $cipher = self::getContainer()->get(ApiKeyCipher::class);
        $configuration = new AiProviderSettings(
            $owner,
            null,
            'https://api.example.test/v1',
            $cipher->seal($owner->requireId(), 'sk-throwaway1234'),
            '1234',
            new \DateTimeImmutable(self::NOW),
        );
        $this->entityManager->persist($configuration);
        $this->entityManager->flush();

        return $configuration;
    }
```

Run on both legs. The new test passes at once: it guards the cascade and is not TDD.

```bash
php bin/phpunit tests/Service/Account/AccountDeleterTest.php
docker compose exec -T php vendor/bin/phpunit tests/Service/Account/AccountDeleterTest.php   # from the root
```

Expected: OK on both.

- [ ] **Step 6: Deletion checks**

1. Delete the `foreach` in `releasePointersTo()`. `testDeletingAProfileConnectionClearsOnlyThePointersToIt` must fail. Restore.
2. Flip `===` to `!==` in that `foreach`. The same test must fail on `assertSame($kept, …)`. Restore.
3. On the MySQL leg, temporarily remove `onDelete: 'SET NULL'` from the `profile_connection_id` JoinColumn. The test schema is built from the mapping. Then run `docker compose exec -T php vendor/bin/phpunit --filter testDeletionTakesABorrowingConnectionAndTheSiblingItBorrowsFrom tests/Service/Account/AccountDeleterTest.php`. Expected: an FK violation error. Restore.

Quote each FAIL.

- [ ] **Step 7: Gates for the touched files, then commit**

```bash
composer cs && composer md && composer stan
git add backend/src/Service/Ai/AiProviderConfigurator.php backend/tests/Service/Ai/AiProviderConfiguratorTest.php backend/tests/Service/Account/AccountDeleterTest.php
git commit -m "feat(#1349): deleting a connection clears the profile pointers to it"
```

---

### Task 3: The backend reads and writes the pointer

**Files:**
- Create: `backend/src/Service/Recommendation/Exception/ProfileNotBorrowedException.php`
- Create: `backend/src/Dto/Ai/ChooseProfileConnectionRequest.php`
- Modify: `backend/src/Service/Recommendation/Profile/ProfileConnectionResolver.php` (whole file)
- Modify: `backend/src/Service/Recommendation/Profile/ProfileConnectionChooser.php` (whole file)
- Modify: `backend/src/Http/Problem/ExceptionProblems/RecommendationRunProblems.php`
- Modify: `backend/src/Controller/Api/AiProfileConnectionController.php` (whole file)
- Modify: `backend/src/Http/AiSettingsJson.php:42`
- Modify: `backend/src/Repository/AiProviderSettingsRepository.php` (delete `findProfileSourceFor()`)
- Modify: `backend/src/Entity/AiProviderSettings.php` (delete `$profileSource`, `isProfileSource()`, `setProfileSource()`)
- Modify: `backend/tests/Support/RecommendationRunFixtures.php`
- Test: `backend/tests/Service/Recommendation/Profile/ProfileConnectionResolverTest.php` (whole file)
- Test: `backend/tests/Service/Recommendation/Profile/ProfileConnectionChooserTest.php` (whole file)
- Test: `backend/tests/Controller/Api/AiProfileConnectionControllerTest.php` (whole file)
- Test: `backend/tests/Http/AiSettingsJsonTest.php`
- Test: `backend/tests/Service/Recommendation/Jev/JevPipelineTest.php`
- Test: `backend/tests/Service/Recommendation/Jev/JevRecommendationEngineTest.php`
- Test: `backend/tests/Service/Recommendation/Run/TickLockTtlTest.php` (one docblock)

`TickContextFactory`, `TickLockTtl`, `TickContextFactoryTest`, `TickPhasesTest` and `RecommendationEngineResolverTest` stay unchanged. They call `borrowedFor()` and `seedProfileConnectionFor($user)`, whose signatures and meaning are kept.

**Interfaces:**
- Consumes: Task 1's accessors; Task 2's `deleteConfiguration()` clean-up.
- Produces:
  - `ProfileConnectionResolver::borrowedFor(AiProviderSettings $active): ?AiProviderSettings`
  - `ProfileConnectionResolver::borrows(AiProviderSettings $connection): bool`
  - `ProfileConnectionResolver::canBuildProfiles(AiProviderSettings $connection): bool`
  - `ProfileConnectionChooser::choose(AiProviderSettings $borrower, AiProviderSettings $connection): void`, which throws `ProfileNotBorrowedException` or `ProfileConnectionRejectedException`
  - `ProfileConnectionChooser::clear(AiProviderSettings $borrower): void`
  - constants `ProfileConnectionChooser::REJECTION` and `ProfileConnectionChooser::NOT_BORROWING`
  - `PUT /api/me/ai/configs/{id}/profile` with body `{"connectionId": int}`, answering `200` with the borrower's config JSON, or a `404`/`422` problem
  - `DELETE` on the same route, answering `204`
  - config JSON key `profileConnectionId: ?int` (and no `profileSource`)
  - fixtures: `seedReadyAiSettingsFor(User, string): AiProviderSettings`, `seedInactiveAiSettingsFor(User, string): AiProviderSettings`, `seedProfileConnectionFor(User, string = PROFILE_MODEL): AiProviderSettings`, `seedProfileConnectionBorrowedBy(AiProviderSettings, string = PROFILE_MODEL): AiProviderSettings`

- [ ] **Step 1: Point the fixtures at the pointer**

In `RecommendationRunFixtures`, replace `seedReadyAiSettingsFor()` and `seedProfileConnectionFor()` with:

```php
    public function seedReadyAiSettingsFor(User $user, string $model): AiProviderSettings
    {
        $settings = $this->seedInactiveAiSettingsFor($user, $model);
        $user->setActiveAiProviderSettings($settings);
        $this->entityManager->flush();

        return $settings;
    }

    /** A ready connection on the default endpoint that the account has not activated. */
    public function seedInactiveAiSettingsFor(User $user, string $model): AiProviderSettings
    {
        $now = new \DateTimeImmutable('2026-08-07 09:00:00');
        $settings = new AiProviderSettings(
            $user,
            null,
            'https://api.example.test/v1',
            $this->cipher->seal($user->requireId(), 'sk-throwaway1234'),
            '1234',
            $now,
        );
        $this->entityManager->persist($settings);
        $settings->chooseModel($model, $now, 32768);
        $this->entityManager->flush();

        return $settings;
    }

    /** A ready connection, not active, that the active connection borrows its profile from. */
    public function seedProfileConnectionFor(User $user, string $model = self::PROFILE_MODEL): AiProviderSettings
    {
        $borrower = $user->getActiveAiProviderSettings()
            ?? throw new \LogicException('Cannot seed a profile connection before a provider is seeded.');

        return $this->seedProfileConnectionBorrowedBy($borrower, $model);
    }

    /** A ready connection, not active, that $borrower borrows its profile from; its base URL tells its calls apart. */
    public function seedProfileConnectionBorrowedBy(
        AiProviderSettings $borrower,
        string $model = self::PROFILE_MODEL,
    ): AiProviderSettings {
        $owner = $borrower->getUser();
        $now = new \DateTimeImmutable('2026-08-07 09:00:00');
        $connection = new AiProviderSettings(
            $owner,
            'Profile',
            self::PROFILE_BASE_URL,
            $this->cipher->seal($owner->requireId(), 'sk-profile5678'),
            '5678',
            $now,
        );
        $this->entityManager->persist($connection);
        $connection->chooseModel($model, $now, 32768);
        $borrower->setProfileConnection($connection);
        $this->entityManager->flush();

        return $connection;
    }
```

`seedReadyAiSettings()` stays as it is (it ignores the returned row).

- [ ] **Step 2: Rewrite the resolver test**

Replace `ProfileConnectionResolverTest.php` with:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Service\Recommendation\Profile;

use App\Entity\AiProviderSettings;
use App\Entity\User;
use App\Service\Ai\Crypto\ApiKeyCipher;
use App\Service\Recommendation\Profile\ProfileConnectionResolver;
use App\Tests\DbTestCase;
use App\Tests\Support\AiProviderSettingsFactory;
use App\Tests\Support\RecommendationRunFixtures;
use App\Tests\Support\SeedsUsers;

final class ProfileConnectionResolverTest extends DbTestCase
{
    use SeedsUsers;

    private User $owner;
    private RecommendationRunFixtures $fixtures;
    private AiProviderSettings $jev;

    protected function setUp(): void
    {
        parent::setUp();

        /** @var ApiKeyCipher $cipher */
        $cipher = self::getContainer()->get(ApiKeyCipher::class);
        $this->fixtures = new RecommendationRunFixtures($this->entityManager, $cipher);
        $this->owner = $this->user('profile-resolver@example.test');
        $this->jev = $this->fixtures->seedReadyAiSettingsFor($this->owner, 'jev-latest');
    }

    public function testAJevConnectionBorrowsTheConnectionItPointsAt(): void
    {
        $profile = $this->fixtures->seedProfileConnectionFor($this->owner);

        self::assertSame($profile, $this->resolver()->borrowedFor($this->jev));
    }

    public function testEachJevConnectionBorrowsItsOwnChoice(): void
    {
        $first = $this->fixtures->seedProfileConnectionFor($this->owner);
        $other = $this->fixtures->seedInactiveAiSettingsFor($this->owner, 'jev-latest');
        $second = $this->fixtures->seedProfileConnectionBorrowedBy($other, 'gpt-4o');

        self::assertSame($first, $this->resolver()->borrowedFor($this->jev));
        self::assertSame($second, $this->resolver()->borrowedFor($other));
    }

    /** A connection whose model later became a Jev model cannot distil: it reads as no profile connection. */
    public function testAChosenConnectionOnAJevModelIsNotBorrowed(): void
    {
        $this->fixtures->seedProfileConnectionFor($this->owner, 'jev-latest');

        self::assertNull($this->resolver()->borrowedFor($this->jev));
    }

    public function testAChosenConnectionWithoutAModelIsNotBorrowed(): void
    {
        $connection = AiProviderSettingsFactory::build($this->owner, 'No model', 'https://none.example.test/v1');
        $this->entityManager->persist($connection);
        $this->jev->setProfileConnection($connection);
        $this->entityManager->flush();

        self::assertNull($this->resolver()->borrowedFor($this->jev));
    }

    /** The LLM distils on its own connection, whatever its row points at. */
    public function testAnLlmConnectionBorrowsNothing(): void
    {
        $llm = $this->fixtures->seedInactiveAiSettingsFor($this->owner, 'gpt-4o');
        $this->fixtures->seedProfileConnectionBorrowedBy($llm);

        self::assertNull($this->resolver()->borrowedFor($llm));
    }

    public function testOnlyAConnectionWhoseEngineCannotDistilBorrows(): void
    {
        $llm = $this->fixtures->seedInactiveAiSettingsFor($this->owner, 'gpt-4o');

        self::assertTrue($this->resolver()->borrows($this->jev));
        self::assertFalse($this->resolver()->borrows($llm));
    }

    private function resolver(): ProfileConnectionResolver
    {
        /** @var ProfileConnectionResolver $resolver */
        $resolver = self::getContainer()->get(ProfileConnectionResolver::class);

        return $resolver;
    }
}
```

- [ ] **Step 3: Rewrite the chooser test**

Replace `ProfileConnectionChooserTest.php` with:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Service\Recommendation\Profile;

use App\Entity\AiProviderSettings;
use App\Entity\User;
use App\Service\Ai\Crypto\ApiKeyCipher;
use App\Service\Recommendation\Exception\ProfileConnectionRejectedException;
use App\Service\Recommendation\Exception\ProfileNotBorrowedException;
use App\Service\Recommendation\Profile\ProfileConnectionChooser;
use App\Tests\DbTestCase;
use App\Tests\Support\AiProviderSettingsFactory;
use App\Tests\Support\RecommendationRunFixtures;
use App\Tests\Support\SeedsUsers;

final class ProfileConnectionChooserTest extends DbTestCase
{
    use SeedsUsers;

    private User $owner;
    private RecommendationRunFixtures $fixtures;
    private AiProviderSettings $jev;

    protected function setUp(): void
    {
        parent::setUp();

        /** @var ApiKeyCipher $cipher */
        $cipher = self::getContainer()->get(ApiKeyCipher::class);
        $this->fixtures = new RecommendationRunFixtures($this->entityManager, $cipher);
        $this->owner = $this->user('profile-chooser@example.test');
        $this->jev = $this->fixtures->seedReadyAiSettingsFor($this->owner, 'jev-latest');
    }

    public function testChoosingPointsTheJevConnectionAtTheConnection(): void
    {
        $connection = $this->fixtures->seedInactiveAiSettingsFor($this->owner, 'gpt-4o');

        $this->chooser()->choose($this->jev, $connection);

        $this->entityManager->refresh($this->jev);
        self::assertSame($connection, $this->jev->getProfileConnection());
    }

    public function testChoosingAgainMovesThePointer(): void
    {
        $this->fixtures->seedProfileConnectionFor($this->owner);
        $next = $this->fixtures->seedInactiveAiSettingsFor($this->owner, 'gpt-4o');

        $this->chooser()->choose($this->jev, $next);

        $this->entityManager->refresh($this->jev);
        self::assertSame($next, $this->jev->getProfileConnection());
    }

    public function testEachJevConnectionKeepsItsOwnChoice(): void
    {
        $other = $this->fixtures->seedInactiveAiSettingsFor($this->owner, 'jev-latest');
        $first = $this->fixtures->seedInactiveAiSettingsFor($this->owner, 'gpt-4o');
        $second = $this->fixtures->seedInactiveAiSettingsFor($this->owner, 'gpt-4o-mini');

        $this->chooser()->choose($this->jev, $first);
        $this->chooser()->choose($other, $second);

        $this->entityManager->refresh($this->jev);
        $this->entityManager->refresh($other);
        self::assertSame($first, $this->jev->getProfileConnection());
        self::assertSame($second, $other->getProfileConnection());
    }

    public function testAJevConnectionIsRefusedAsTheProfileConnection(): void
    {
        $other = $this->fixtures->seedInactiveAiSettingsFor($this->owner, 'jev-latest');

        try {
            $this->chooser()->choose($this->jev, $other);
            self::fail('A Jev connection cannot build the profile.');
        } catch (ProfileConnectionRejectedException $exception) {
            self::assertSame(ProfileConnectionChooser::REJECTION, $exception->getMessage());
        }
        $this->entityManager->refresh($this->jev);
        self::assertNull($this->jev->getProfileConnection());
    }

    public function testAConnectionWithoutAModelIsRefused(): void
    {
        $connection = AiProviderSettingsFactory::build($this->owner, 'No model', 'https://none.example.test/v1');
        $this->entityManager->persist($connection);
        $this->entityManager->flush();

        $this->expectException(ProfileConnectionRejectedException::class);

        $this->chooser()->choose($this->jev, $connection);
    }

    public function testAConnectionThatBuildsItsOwnProfileBorrowsNone(): void
    {
        $llm = $this->fixtures->seedInactiveAiSettingsFor($this->owner, 'gpt-4o');
        $connection = $this->fixtures->seedInactiveAiSettingsFor($this->owner, 'gpt-4o-mini');

        try {
            $this->chooser()->choose($llm, $connection);
            self::fail('An LLM connection builds its own profile.');
        } catch (ProfileNotBorrowedException $exception) {
            self::assertSame(ProfileConnectionChooser::NOT_BORROWING, $exception->getMessage());
        }
        $this->entityManager->refresh($llm);
        self::assertNull($llm->getProfileConnection());
    }

    public function testClearingUnsetsTheChoiceAndIsIdempotent(): void
    {
        $this->fixtures->seedProfileConnectionFor($this->owner);

        $this->chooser()->clear($this->jev);
        $this->chooser()->clear($this->jev);

        $this->entityManager->refresh($this->jev);
        self::assertNull($this->jev->getProfileConnection());
    }

    private function chooser(): ProfileConnectionChooser
    {
        /** @var ProfileConnectionChooser $chooser */
        $chooser = self::getContainer()->get(ProfileConnectionChooser::class);

        return $chooser;
    }
}
```

- [ ] **Step 4: Rewrite the controller test**

Replace `AiProfileConnectionControllerTest.php` with:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Controller\Api;

use App\Tests\Support\AiConfigurationRequests;
use App\Tests\Support\ApiTestCase;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

final class AiProfileConnectionControllerTest extends ApiTestCase
{
    use AiConfigurationRequests;

    protected function setUp(): void
    {
        $this->resetTheProviderBudget();
    }

    public function testAJevConnectionBorrowsTheConnectionItIsGiven(): void
    {
        $client = $this->clientAnswering(['gpt-4o', 'jev-latest']);
        $this->accountOn($client, 'ai-profile@example.test');
        $llm = $this->addAndReadyConfiguration($client, 'gpt-4o');
        $jev = $this->addAndReadyConfiguration($client, 'jev-latest');

        $this->chooseProfileConnection($client, $jev, $llm);

        self::assertResponseIsSuccessful();
        self::assertSame($jev, $this->payload($client)['id']);
        self::assertSame($llm, $this->payload($client)['profileConnectionId']);
    }

    public function testEachJevConnectionKeepsItsOwnProfileConnection(): void
    {
        $client = $this->clientAnswering(['gpt-4o', 'gpt-4o-mini', 'jev-latest']);
        $this->accountOn($client, 'ai-profile-two@example.test');
        $first = $this->addAndReadyConfiguration($client, 'gpt-4o');
        $second = $this->addAndReadyConfiguration($client, 'gpt-4o-mini');
        $firstJev = $this->addAndReadyConfiguration($client, 'jev-latest');
        $secondJev = $this->addAndReadyConfiguration($client, 'jev-latest');

        $this->chooseProfileConnection($client, $firstJev, $first);
        $this->chooseProfileConnection($client, $secondJev, $second);

        self::assertSame(
            [$first => null, $second => null, $firstJev => $first, $secondJev => $second],
            $this->profileConnections($client),
        );
    }

    public function testAJevConnectionCannotBuildTheProfile(): void
    {
        $client = $this->clientAnswering(['jev-latest']);
        $this->accountOn($client, 'ai-profile-jev@example.test');
        $jev = $this->addAndReadyConfiguration($client, 'jev-latest');
        $otherJev = $this->addAndReadyConfiguration($client, 'jev-latest');

        $this->chooseProfileConnection($client, $jev, $otherJev);

        self::assertResponseStatusCodeSame(422);
        self::assertSame('profile_connection_rejected', $this->payload($client)['type']);
    }

    public function testAConnectionThatBuildsItsOwnProfileBorrowsNone(): void
    {
        $client = $this->clientAnswering(['gpt-4o', 'gpt-4o-mini']);
        $this->accountOn($client, 'ai-profile-own@example.test');
        $llm = $this->addAndReadyConfiguration($client, 'gpt-4o');
        $other = $this->addAndReadyConfiguration($client, 'gpt-4o-mini');

        $this->chooseProfileConnection($client, $llm, $other);

        self::assertResponseStatusCodeSame(422);
        self::assertSame('profile_not_borrowed', $this->payload($client)['type']);
    }

    public function testABodyWithoutAConnectionIsRefused(): void
    {
        $client = $this->clientAnswering(['jev-latest']);
        $this->accountOn($client, 'ai-profile-body@example.test');
        $jev = $this->addAndReadyConfiguration($client, 'jev-latest');

        $this->putJson($client, sprintf('/api/me/ai/configs/%d/profile', $jev), '{}');

        self::assertResponseStatusCodeSame(422);
    }

    public function testClearingTheProfileConnectionAnswersNoContentEveryTime(): void
    {
        $client = $this->clientAnswering(['gpt-4o', 'jev-latest']);
        $this->accountOn($client, 'ai-profile-clear@example.test');
        $llm = $this->addAndReadyConfiguration($client, 'gpt-4o');
        $jev = $this->addAndReadyConfiguration($client, 'jev-latest');
        $this->chooseProfileConnection($client, $jev, $llm);

        $client->request('DELETE', sprintf('/api/me/ai/configs/%d/profile', $jev));
        self::assertResponseStatusCodeSame(204);
        $client->request('DELETE', sprintf('/api/me/ai/configs/%d/profile', $jev));
        self::assertResponseStatusCodeSame(204);

        self::assertSame([$llm => null, $jev => null], $this->profileConnections($client));
    }

    public function testDeletingTheProfileConnectionLeavesTheJevConnectionWithoutOne(): void
    {
        $client = $this->clientAnswering(['gpt-4o', 'jev-latest']);
        $this->accountOn($client, 'ai-profile-delete@example.test');
        $llm = $this->addAndReadyConfiguration($client, 'gpt-4o');
        $jev = $this->addAndReadyConfiguration($client, 'jev-latest');
        $this->chooseProfileConnection($client, $jev, $llm);

        $client->request('DELETE', sprintf('/api/me/ai/configs/%d', $llm));

        self::assertResponseStatusCodeSame(204);
        self::assertSame([$jev => null], $this->profileConnections($client));
    }

    public function testAnotherAccountsConnectionIsNotFound(): void
    {
        $client = $this->clientAnswering(['gpt-4o', 'jev-latest']);
        $this->accountOn($client, 'ai-profile-owner@example.test');
        $theirs = $this->addAndReadyConfiguration($client, 'gpt-4o');
        $this->accountOn($client, 'ai-profile-stranger@example.test');
        $mine = $this->addAndReadyConfiguration($client, 'jev-latest');

        $this->chooseProfileConnection($client, $mine, $theirs);
        self::assertResponseStatusCodeSame(404);
        $this->chooseProfileConnection($client, $theirs, $mine);
        self::assertResponseStatusCodeSame(404);
        $client->request('DELETE', sprintf('/api/me/ai/configs/%d/profile', $theirs));
        self::assertResponseStatusCodeSame(404);

        self::assertSame([$mine => null], $this->profileConnections($client));
    }

    private function chooseProfileConnection(KernelBrowser $client, int $borrower, int $connection): void
    {
        $this->putJson(
            $client,
            sprintf('/api/me/ai/configs/%d/profile', $borrower),
            sprintf('{"connectionId":%d}', $connection),
        );
    }

    /** @return array<int|string, mixed> each configuration's profileConnectionId, keyed by its id */
    private function profileConnections(KernelBrowser $client): array
    {
        $client->request('GET', '/api/me/ai');

        return array_column($this->configs($client), 'profileConnectionId', 'id');
    }
}
```

- [ ] **Step 5: Add the JSON tests**

Append to `AiSettingsJsonTest`:

```php
    public function testTheConfigurationShapeCarriesThePointerToItsProfileConnection(): void
    {
        $jev = self::withId($this->settings('jev-latest'), 8);
        $jev->setProfileConnection(self::withId($this->settings('gpt-4o'), 6));

        self::assertSame(6, $this->json()->configuration($jev, null)['profileConnectionId']);
    }

    public function testAConfigurationThatPointsNowhereCarriesANullProfileConnection(): void
    {
        $shape = $this->json()->configuration(self::withId($this->settings('gpt-4o'), 7), null);

        self::assertArrayHasKey('profileConnectionId', $shape);
        self::assertArrayNotHasKey('profileSource', $shape);
        self::assertNull($shape['profileConnectionId']);
    }
```

- [ ] **Step 6: Move the Jev tests onto the pointer**

`JevPipelineTest`:
- add `use App\Service\Ai\AiProviderConfigurator;`;
- add the property `private AiProviderSettings $jevConnection;` beside `$profileConnection`;
- in `setUp()`, replace the two seeding lines with:

```php
        $this->jevConnection = $this->fixtures->seedReadyAiSettingsFor($this->owner, 'jev-latest');
        $this->profileConnection = $this->fixtures->seedProfileConnectionFor($this->owner);
```

Replace the two tests and add the third and the helper:

```php
    /** No profile connection: the run fails before any call, says how to fix it, and resumes once one is chosen. */
    public function testWithoutAProfileConnectionTheRunFailsThenResumesOnceOneIsChosen(): void
    {
        $this->jevConnection->setProfileConnection(null);
        $this->entityManager->flush();
        $this->fixtures->seedFeedWithEntries($this->owner, 5);

        $failed = $this->runToCompletion($this->owner);

        self::assertSame('failed', $failed->getStatus()->value);
        self::assertSame(JevProfileStep::NO_PROFILE_CONNECTION, $failed->getError());
        self::assertSame([], $this->chat()->calls());
        self::assertSame([], $this->systemOne()->requests());

        $this->jevConnection->setProfileConnection($this->profileConnection);
        $this->entityManager->flush();
        $this->queueProfile('Likes Rust and homelab.');
        $this->systemOne()->queueNouls(static fn (int $entryId): float => 0.5);
        $this->starter()->resume($this->owner);
        $resumed = $this->tickUntilDone($this->owner);

        self::assertSame('completed', $resumed->getStatus()->value);
        self::assertSame($failed->requireId(), $resumed->requireId());
    }

    public function testTheWavesCompleteWhenTheProfileConnectionIsDeletedAfterTheProfileIsRecorded(): void
    {
        $this->fixtures->seedFeedWithEntries($this->owner, 5);
        $this->queueProfile('Likes Rust and homelab.');
        $this->systemOne()->queueNouls(static fn (int $entryId): float => 0.5);
        $this->starter()->start($this->owner);
        $this->tickUntilTheProfileIsRecorded();

        $this->configurator()->deleteConfiguration($this->profileConnection);
        $run = $this->tickUntilDone($this->owner);

        self::assertSame('completed', $run->getStatus()->value);
        self::assertCount(1, $this->systemOne()->requests());
        self::assertNull($this->jevConnection->getProfileConnection());
    }

    public function testAJevConnectionDistilsThroughItsOwnProfileConnection(): void
    {
        $other = $this->fixtures->seedInactiveAiSettingsFor($this->owner, 'jev-latest');
        $this->fixtures->seedProfileConnectionBorrowedBy($other, 'gpt-4o');
        $this->owner->setActiveAiProviderSettings($other);
        $this->entityManager->flush();
        $this->fixtures->seedFeedWithEntries($this->owner, 5);
        $this->systemOne()->queueNouls(static fn (int $entryId): float => 0.5);
        $this->queueProfile('Likes Rust and homelab.');

        $run = $this->runToCompletion($this->owner);

        self::assertSame('completed', $run->getStatus()->value);
        self::assertSame(['gpt-4o'], array_column($this->chat()->calls(), 'model'));
        self::assertSame($this->profileConnection, $this->jevConnection->getProfileConnection());
    }
```

```php
    private function configurator(): AiProviderConfigurator
    {
        /** @var AiProviderConfigurator $configurator */
        $configurator = self::getContainer()->get(AiProviderConfigurator::class);

        return $configurator;
    }
```

`JevRecommendationEngineTest`:
- rename the property `private AiProviderSettings $profileConnection;` to `private AiProviderSettings $jevConnection;`;
- replace the two `setUp()` seeding lines with:

```php
        $this->jevConnection = $this->fixtures->seedReadyAiSettingsFor($this->owner, 'jev-latest');
        $this->fixtures->seedProfileConnectionFor($this->owner);
```

- in `testWithoutAProfileConnectionTheFirstProviderTickFailsTheRun()`, replace `$this->profileConnection->setProfileSource(false);` with `$this->jevConnection->setProfileConnection(null);`.

`TickLockTtlTest`: the docblock above `testAnLlmAccountIgnoresItsProfileConnection()` becomes `/** The LLM never borrows, so a slow connection its row points at does not lengthen its lock. */`.

- [ ] **Step 7: Run the tests and watch them fail**

Run: `php bin/phpunit tests/Service/Recommendation/Profile tests/Controller/Api/AiProfileConnectionControllerTest.php tests/Http/AiSettingsJsonTest.php tests/Service/Recommendation/Jev`
Expected: failures and errors. Among them: `Class "App\Service\Recommendation\Exception\ProfileNotBorrowedException" not found`, `Call to undefined method …ProfileConnectionResolver::borrows()`, `choose()` arity errors, a missing `profileConnectionId` key, and the Jev runs failing with `NO_PROFILE_CONNECTION`, because the resolver still reads the flag.

- [ ] **Step 8: Add the exception and its problem**

Create `backend/src/Service/Recommendation/Exception/ProfileNotBorrowedException.php`:

```php
<?php

declare(strict_types=1);

namespace App\Service\Recommendation\Exception;

final class ProfileNotBorrowedException extends \RuntimeException
{
}
```

In `RecommendationRunProblems`, add `use App\Service\Recommendation\Exception\ProfileNotBorrowedException;` and this arm directly after the `ProfileConnectionRejectedException` arm:

```php
            $exception instanceof ProfileNotBorrowedException => new ResolvedProblem(new ApiProblem(
                'profile_not_borrowed',
                'This connection builds its own profile',
                Response::HTTP_UNPROCESSABLE_ENTITY,
                $exception->getMessage(),
            )),
```

- [ ] **Step 9: The resolver reads the pointer**

Replace `ProfileConnectionResolver.php` with:

```php
<?php

declare(strict_types=1);

namespace App\Service\Recommendation\Profile;

use App\Entity\AiProviderSettings;
use App\Service\Ai\Support\AiReadiness;
use App\Service\Recommendation\Engine\Model\RecommendationProfileSource;
use App\Service\Recommendation\Engine\RecommendationEngineResolver;

final readonly class ProfileConnectionResolver
{
    public function __construct(private RecommendationEngineResolver $engines)
    {
    }

    /**
     * The connection the active one borrows its profile from: null when it builds its own, chose none, or chose one
     * that can no longer build profiles.
     */
    public function borrowedFor(AiProviderSettings $active): ?AiProviderSettings
    {
        $connection = $active->getProfileConnection();
        if (null === $connection || !$this->borrows($active)) {
            return null;
        }

        return $this->canBuildProfiles($connection) ? $connection : null;
    }

    public function borrows(AiProviderSettings $connection): bool
    {
        return RecommendationProfileSource::Borrowed === $this->engines->capabilitiesFor($connection)->profileSource;
    }

    public function canBuildProfiles(AiProviderSettings $connection): bool
    {
        return AiReadiness::of($connection)
            && RecommendationProfileSource::Own === $this->engines->capabilitiesFor($connection)->profileSource;
    }
}
```

- [ ] **Step 10: The chooser writes the pointer**

Replace `ProfileConnectionChooser.php` with:

```php
<?php

declare(strict_types=1);

namespace App\Service\Recommendation\Profile;

use App\Entity\AiProviderSettings;
use App\Service\Recommendation\Exception\ProfileConnectionRejectedException;
use App\Service\Recommendation\Exception\ProfileNotBorrowedException;
use Doctrine\ORM\EntityManagerInterface;

final readonly class ProfileConnectionChooser
{
    public const string REJECTION = 'Only a ready LLM connection can build your profile.';

    public const string NOT_BORROWING = 'This connection builds its own profile and borrows none.';

    public function __construct(
        private ProfileConnectionResolver $profileConnections,
        private EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * @throws ProfileNotBorrowedException
     * @throws ProfileConnectionRejectedException
     */
    public function choose(AiProviderSettings $borrower, AiProviderSettings $connection): void
    {
        if (!$this->profileConnections->borrows($borrower)) {
            throw new ProfileNotBorrowedException(self::NOT_BORROWING);
        }
        if (!$this->profileConnections->canBuildProfiles($connection)) {
            throw new ProfileConnectionRejectedException(self::REJECTION);
        }

        $borrower->setProfileConnection($connection);
        $this->entityManager->flush();
    }

    public function clear(AiProviderSettings $borrower): void
    {
        $borrower->setProfileConnection(null);
        $this->entityManager->flush();
    }
}
```

- [ ] **Step 11: The request DTO and the controller**

Create `backend/src/Dto/Ai/ChooseProfileConnectionRequest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Dto\Ai;

use Symfony\Component\Validator\Constraints as Assert;

final readonly class ChooseProfileConnectionRequest
{
    public function __construct(
        #[Assert\Positive]
        public int $connectionId,
    ) {
    }
}
```

Replace `AiProfileConnectionController.php` with:

```php
<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Dto\Ai\ChooseProfileConnectionRequest;
use App\Entity\User;
use App\Http\AiSettingsJson;
use App\Service\Ai\AiConfigurationForUser;
use App\Service\Recommendation\Profile\ProfileConnectionChooser;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * Which saved connection distils the profile for the connection `{id}`, whose engine cannot. No provider call, so no
 * rate limit.
 */
#[Route('/api/me/ai/configs/{id}/profile', requirements: ['id' => '\d+'])]
final readonly class AiProfileConnectionController
{
    public function __construct(
        private AiConfigurationForUser $configuration,
        private ProfileConnectionChooser $profileConnections,
        private AiSettingsJson $settingsJson,
    ) {
    }

    #[Route('', name: 'api_me_ai_choose_profile', methods: ['PUT'])]
    public function choose(
        #[CurrentUser] User $user,
        int $id,
        #[MapRequestPayload] ChooseProfileConnectionRequest $request,
    ): JsonResponse {
        $borrower = $this->configuration->require($user, $id);
        $this->profileConnections->choose($borrower, $this->configuration->require($user, $request->connectionId));

        return new JsonResponse($this->settingsJson->configurationFor($borrower, $user));
    }

    #[Route('', name: 'api_me_ai_clear_profile', methods: ['DELETE'])]
    public function clear(#[CurrentUser] User $user, int $id): JsonResponse
    {
        $this->profileConnections->clear($this->configuration->require($user, $id));

        return new JsonResponse(null, Response::HTTP_NO_CONTENT);
    }
}
```

- [ ] **Step 12: The JSON, the repository and the entity drop the flag**

- `AiSettingsJson::configuration()`: replace `'profileSource' => $settings->isProfileSource(),` with `'profileConnectionId' => $settings->getProfileConnection()?->getId(),`.
- `AiProviderSettingsRepository`: delete `findProfileSourceFor()`.
- `AiProviderSettings`: delete the `$profileSource` property with its docblock and `#[ORM\Column]`, `isProfileSource()` and `setProfileSource()`.

Then prove nothing still names the flag:

```bash
grep -rn -E "isProfileSource|setProfileSource|'profileSource'|profile_source|findProfileSourceFor|findUsableFor" src tests
```

Expected: no output. `RecommendationEngineCapabilitiesModel::$profileSource` (the engine capability) stays and is not matched. `migrations/` still names `profile_source`, as it must.

- [ ] **Step 13: Run the tests and watch them pass**

```bash
php bin/phpunit tests/Service/Recommendation tests/Controller/Api/AiProfileConnectionControllerTest.php tests/Controller/Api/AiSettingsControllerTest.php tests/Http/AiSettingsJsonTest.php tests/Service/Ai tests/Entity/AiProviderSettingsTest.php tests/Service/Account
```

Expected: OK. This includes the unchanged `TickContextFactoryTest`, `TickLockTtlTest`, `TickPhasesTest` and `RecommendationEngineResolverTest`.

- [ ] **Step 14: Deletion checks**

Restore after each one and quote the FAIL:

1. Resolver: drop `|| !$this->borrows($active)`. `testAnLlmConnectionBorrowsNothing` and `TickLockTtlTest::testAnLlmAccountIgnoresItsProfileConnection` must fail.
2. Resolver: return `$connection` without `canBuildProfiles()`. `testAChosenConnectionOnAJevModelIsNotBorrowed` and `testAChosenConnectionWithoutAModelIsNotBorrowed` must fail.
3. Chooser: delete the `borrows()` guard. `testAConnectionThatBuildsItsOwnProfileBorrowsNone` (chooser and controller) must fail.
4. Chooser: delete the `canBuildProfiles()` guard. `testAJevConnectionIsRefusedAsTheProfileConnection` and the controller's `testAJevConnectionCannotBuildTheProfile` must fail.
5. Chooser: delete the `flush()` in `choose()`. `testChoosingPointsTheJevConnectionAtTheConnection` must fail; in `clear()`, `testClearingUnsetsTheChoiceAndIsIdempotent` must fail.
6. Controller: pass `$borrower` as the target (`choose($borrower, $borrower)`). `testAJevConnectionBorrowsTheConnectionItIsGiven` must fail (422).
7. JSON: map `getId()` of `$settings` instead of the pointer. `testTheConfigurationShapeCarriesThePointerToItsProfileConnection` must fail.
8. Problem arm: delete it. The controller's `testAConnectionThatBuildsItsOwnProfileBorrowsNone` must fail with a 500.

- [ ] **Step 15: iOS checklist (architecture §6)**

Record in the task report that `PUT` and `DELETE /api/me/ai/configs/{id}/profile` pass each item:
- bearer auth;
- stateless;
- JSON body in, config JSON or `204` out, `application/problem+json` on `404`/`422`;
- no `Origin`, CSRF or browser widget;
- no redirect;
- no user-facing link.

- [ ] **Step 16: Gates for the touched files, then commit**

```bash
bin/console cache:warmup
composer check && composer md
git add backend/src backend/tests
git commit -m "feat(#1349): each jev connection borrows the profile connection it points at"
```

- [ ] **Step 17: Validate the migrated schema from empty, both platforms**

Repeat Task 1 Step 6's commands from empty, then `doctrine:schema:validate` through the same prefixes:

```bash
DATABASE_URL="$SCRATCH_SQLITE" php bin/console doctrine:schema:validate                                   # backend/
docker compose exec -T -e DATABASE_URL="$SCRATCH_MYSQL" php bin/console doctrine:schema:validate          # root
```

Expected on both: `[OK] The mapping files are correct.` and `[OK] The database schema is in sync with the mapping files.` A drift names the column or index. Fix the migration (an index name differing from `IDX_53B8EF3023D39AC1` is the likely one) and re-run Task 1 Steps 6–7. Then drop both scratch DBs as in Task 1.

- [ ] **Step 18: Apply the migration to the live Docker DB**

From the repository root:

```bash
bash backend/bin/e2e-preflight.sh "$(git rev-parse --show-toplevel)"
docker compose exec -T php bin/console doctrine:migrations:list | grep -i "not migrated"
docker compose exec -T php bin/console dbal:run-sql "SELECT id, user_id, model, profile_source FROM user_ai_settings WHERE user_id = 2 ORDER BY id"
docker compose exec -T mysql mysqldump -uroot -proot feedreader user_ai_settings > backend/var/backup-1349-user_ai_settings.sql
docker compose exec -T php bin/console doctrine:migrations:migrate --no-interaction
docker compose exec -T php bin/console dbal:run-sql "SELECT id, model, profile_connection_id FROM user_ai_settings WHERE user_id = 2 ORDER BY id"
docker compose exec php bin/console cache:clear
docker compose restart worker
```

Expected:
- only `Version20261002200000` is not migrated;
- before the migration, row 6 (`qwen/qwen3.7-flash`) has `profile_source = 1` and row 8 is `jev-latest`;
- the dump file is non-empty;
- after the migration, row 8 has `profile_connection_id = 6` and every other row of user 2 has `NULL`.

If anything else is pending, or if the before-state differs, stop and report. Do not migrate.

---

### Task 4: The frontend picker is per row

**Files:**
- Modify: `frontend/src/app/settings/ai/ai-failure.ts:51-55`
- Modify: `frontend/src/app/settings/ai/ai-settings.service.ts`
- Modify: `frontend/src/app/settings/ai/ai-section.component.ts`
- Modify: `frontend/src/app/settings/ai/ai-section.component.html:158-188`
- Modify: `frontend/public/i18n/en.json`, `frontend/public/i18n/de.json` (`settings.ai.profileConnection.info`)
- Test: `frontend/src/app/settings/ai/ai-settings.service.spec.ts`
- Test: `frontend/src/app/settings/ai/ai-section.component.spec.ts`

`frontend/src/testing/recommendation-capabilities.ts` needs no change. It describes engine capabilities, not configs.

**Interfaces:**
- Consumes: Task 3's API (`PUT …/configs/{borrowerId}/profile` with `{connectionId}` returning the borrower's `AiConfig`; `DELETE` returning `204`; `profileConnectionId` on every config).
- Produces:
  - `AiConfig.profileConnectionId: number | null`
  - `AiSettingsService.chooseProfileConnection(borrowerId: number, connectionId: number): void`
  - `AiSettingsService.clearProfileConnection(borrowerId: number): void`
  - `AiFailureScope` variant `{ action: 'profile'; configId: number }`
  - component `profilePick`, `shownProfileConnectionId(config)`, `chooseProfileConnection(config, event)`, `profileFailure(configId)`

- [ ] **Step 1: Write the failing service tests**

In `ai-settings.service.spec.ts`, the `config()` factory's `profileSource: false,` becomes `profileConnectionId: null,`. Replace the six tests that run from `'chooses the profile connection and clears the flag on whichever row held it'` through `'sends nothing when no row holds the profile connection'` with:

```ts
  it('chooses a profile connection for one borrowing row and leaves the others alone', () => {
    service.configs.set([
      config({ id: 1, profileConnectionId: 5 }),
      config({ id: 2, profileConnectionId: 5 }),
      config({ id: 5 }),
    ]);

    service.chooseProfileConnection(2, 6);
    const request = ctrl.expectOne(`${base}/api/me/ai/configs/2/profile`);
    expect(request.request.method).toBe('PUT');
    expect(request.request.body).toEqual({ connectionId: 6 });
    request.flush(config({ id: 2, profileConnectionId: 6 }));

    expect(service.configs().map((each) => [each.id, each.profileConnectionId])).toEqual([
      [1, 5],
      [2, 6],
      [5, null],
    ]);
  });

  it("scopes a refused profile choice to the borrowing row's picker", () => {
    service.chooseProfileConnection(3, 9);
    ctrl.expectOne(`${base}/api/me/ai/configs/3/profile`).flush(
      {
        type: 'profile_connection_rejected',
        detail: 'Only a ready LLM connection can build your profile.',
      },
      { status: 422, statusText: 'Unprocessable Entity' },
    );

    expect(service.failure()?.scope).toEqual({ action: 'profile', configId: 3 });
    expect(service.failure()?.failure).toMatchObject({
      kind: 'unknown',
      detail: 'Only a ready LLM connection can build your profile.',
    });
  });

  it('reloads the settings when the borrowing row is gone', () => {
    service.configs.set([config({ id: 4, profileConnectionId: 5 })]);

    service.clearProfileConnection(4);
    ctrl
      .expectOne(`${base}/api/me/ai/configs/4/profile`)
      .flush(null, { status: 404, statusText: 'Not Found' });

    ctrl
      .expectOne(`${base}/api/me/ai`)
      .flush({ configs: [config({ id: 5 })], activeId: null, defaultMaxBatchSize: 50 });
    expect(service.configs().map((each) => each.id)).toEqual([5]);
    expect(service.failure()).toBeNull();
  });

  it('stays busy through the reload when the chosen profile connection is gone', () => {
    service.chooseProfileConnection(4, 5);
    ctrl
      .expectOne(`${base}/api/me/ai/configs/4/profile`)
      .flush(null, { status: 404, statusText: 'Not Found' });

    expect(service.busy()).toBe(true);

    ctrl
      .expectOne(`${base}/api/me/ai`)
      .flush({ configs: [config({ id: 5 })], activeId: null, defaultMaxBatchSize: 50 });
    expect(service.busy()).toBe(false);
    expect(service.configs().map((each) => each.id)).toEqual([5]);
    expect(service.failure()).toBeNull();
  });

  it('clears the profile connection of one borrowing row', () => {
    service.configs.set([
      config({ id: 1, profileConnectionId: 5 }),
      config({ id: 4, profileConnectionId: 5 }),
    ]);

    service.clearProfileConnection(4);
    const request = ctrl.expectOne(`${base}/api/me/ai/configs/4/profile`);
    expect(request.request.method).toBe('DELETE');
    request.flush(null, { status: 204, statusText: 'No Content' });

    expect(service.configs().map((each) => each.profileConnectionId)).toEqual([5, null]);
  });

  it('drops the pointers to a removed connection, as the server does', () => {
    service.configs.set([
      config({ id: 1, profileConnectionId: 5 }),
      config({ id: 2, profileConnectionId: 6 }),
      config({ id: 5 }),
    ]);

    service.remove(5);
    ctrl
      .expectOne(`${base}/api/me/ai/configs/5`)
      .flush(null, { status: 204, statusText: 'No Content' });

    expect(service.configs().map((each) => [each.id, each.profileConnectionId])).toEqual([
      [1, null],
      [2, 6],
    ]);
  });
```

- [ ] **Step 2: Write the failing component tests**

In `ai-section.component.spec.ts`:
- in `AiSettingsStub`, `chooseProfileSource: jest.Mock;` / `clearProfileSource: jest.Mock;` become `chooseProfileConnection: jest.Mock;` / `clearProfileConnection: jest.Mock;`;
- in `createStub()`, likewise `chooseProfileConnection: jest.fn(),` / `clearProfileConnection: jest.fn(),`;
- in the `config()` factory, `profileSource: false,` becomes `profileConnectionId: null,`.

Inside `describe('the profile connection')`, replace these tests by name:

`'shows the same account-wide choice in every borrowed connection and follows one change'` becomes:

```ts
    it("shows each borrowed connection its own choice and saves a change for that row only", () => {
      const fixture = mountManaging([
        { ...jevActive, id: 12, name: 'row-12', active: false, profileConnectionId: 8 },
        { ...jevActive, id: 13, name: 'row-13', active: false, profileConnectionId: 11 },
        config({ id: 8, name: 'Local', ready: true, model: 'qwen' }),
        config({ id: 11, name: 'Cloud', ready: true, model: 'gpt-4o' }),
      ]);
      const selects = Array.from(
        (fixture.nativeElement as HTMLElement).querySelectorAll<HTMLSelectElement>(
          '.profile-connection select',
        ),
      );
      expect(selects.map(shown)).toEqual(['Local', 'Cloud']);

      pick(selects[1], 1);
      fixture.detectChanges();

      expect(ai.chooseProfileConnection).toHaveBeenCalledTimes(1);
      expect(ai.chooseProfileConnection).toHaveBeenCalledWith(13, 8);
      expect(selects.map(shown)).toEqual(['Local', 'Local']);
    });
```

`'selects the chosen connection and saves a new choice on change'` becomes:

```ts
    it('selects the chosen connection and saves a new choice on change', () => {
      const fixture = mountReady([
        { ...jevActive, profileConnectionId: 8 },
        config({ id: 8, name: 'Local', ready: true, model: 'qwen' }),
        config({ id: 11, name: 'Cloud', ready: true, model: 'gpt-4o' }),
      ]);
      const select = picker(fixture) as HTMLSelectElement;
      expect(shown(select)).toBe('Local');

      pick(select, 2);

      expect(ai.chooseProfileConnection).toHaveBeenCalledWith(7, 11);
    });
```

In `'offers "None", selected while nothing is chosen, and clears the choice with it'`, the last two expectations become:

```ts
      expect(ai.clearProfileConnection).toHaveBeenCalledWith(7);
      expect(ai.chooseProfileConnection).not.toHaveBeenCalled();
```

In `describe('against the real service')`, replace every test from `'shows the actual holder after a 404 reload names a different row'` to the end of that describe with:

```ts
      it('shows the stored choice after a 404 reload', () => {
        const fixture = mountWithRealService();
        const select = picker(fixture) as HTMLSelectElement;

        pick(select, 1);
        fixture.detectChanges();
        http
          .expectOne('/api/me/ai/configs/7/profile')
          .flush(null, { status: 404, statusText: 'Not Found' });
        http.expectOne('/api/me/ai').flush({
          configs: [
            { ...jevActive, profileConnectionId: 9 },
            config({ id: 8, name: 'Gone', ready: false }),
            config({ id: 9, name: 'Other', ready: true, model: 'qwen' }),
          ],
          activeId: 7,
          defaultMaxBatchSize: 50,
        });
        fixture.detectChanges();

        expect(shown(picker(fixture) as HTMLSelectElement)).toBe('Other');
      });

      it('keeps the pick and locks the select while the choice is being saved', () => {
        const fixture = mountWithRealService();
        const select = picker(fixture) as HTMLSelectElement;

        pick(select, 1);
        fixture.detectChanges();

        expect(shown(select)).toBe('Local');
        expect(select.disabled).toBe(true);

        const request = http.expectOne('/api/me/ai/configs/7/profile');
        expect(request.request.body).toEqual({ connectionId: 8 });
        request.flush({ ...jevActive, profileConnectionId: 8 });
        fixture.detectChanges();

        expect(shown(select)).toBe('Local');
        expect(select.disabled).toBe(false);
      });

      it('puts the select back on the stored choice after two refusals in a row', () => {
        const fixture = mountWithRealService();
        const select = picker(fixture) as HTMLSelectElement;
        const refuse = (): void => {
          pick(select, 1);
          fixture.detectChanges();
          http
            .expectOne('/api/me/ai/configs/7/profile')
            .flush(
              { type: 'profile_connection_rejected', detail: 'Refused.' },
              { status: 422, statusText: 'Unprocessable Entity' },
            );
          fixture.detectChanges();
        };

        refuse();
        refuse();

        expect(select.selectedIndex).toBe(0);
      });

      it("saves the second row's pick without touching the first", () => {
        const fixture = mountWithRealService([
          { ...jevActive, id: 13, name: 'second', active: false },
        ]);
        const selects = Array.from(
          (fixture.nativeElement as HTMLElement).querySelectorAll<HTMLSelectElement>(
            '.profile-connection select',
          ),
        );

        pick(selects[1], 1);
        fixture.detectChanges();
        http
          .expectOne('/api/me/ai/configs/13/profile')
          .flush({ ...jevActive, id: 13, name: 'second', active: false, profileConnectionId: 8 });
        fixture.detectChanges();

        expect(selects.map(shown)).toEqual(['None', 'Local']);
      });

      it("shows a refusal under the row that was refused and puts its select back", () => {
        const fixture = mountWithRealService([
          { ...jevActive, id: 13, name: 'second', active: false },
        ]);
        const areas = Array.from(
          (fixture.nativeElement as HTMLElement).querySelectorAll<HTMLElement>(
            '.profile-connection',
          ),
        );
        const selects = areas.map((area) => area.querySelector('select') as HTMLSelectElement);

        pick(selects[1], 1);
        fixture.detectChanges();
        http.expectOne('/api/me/ai/configs/13/profile').flush(
          {
            type: 'profile_connection_rejected',
            detail: 'Only a ready LLM connection can build your profile.',
          },
          { status: 422, statusText: 'Unprocessable Entity' },
        );
        fixture.detectChanges();

        expect(banners(areas[0])).toEqual([]);
        expect(banners(areas[1])).toEqual(['Only a ready LLM connection can build your profile.']);
        expect(selects.map(shown)).toEqual(['None', 'None']);
      });
```

- [ ] **Step 3: Run the specs and watch them fail**

Run (from the root): `docker compose exec -T frontend npx jest src/app/settings/ai`
Expected: failures. `service.chooseProfileConnection is not a function`, `profileConnectionId` missing on the configs, the stub methods not called, and the type errors on `profileSource`.

- [ ] **Step 4: The failure scope**

In `ai-failure.ts`, replace `| { readonly action: 'profile' }` with `| { readonly action: 'profile'; readonly configId: number }`.

- [ ] **Step 5: The service**

In `ai-settings.service.ts`:
- in `AiConfig`, `readonly profileSource: boolean;` becomes `readonly profileConnectionId: number | null;`;
- replace `chooseProfileSource()`, `clearProfileSource()` and `reloadWhenGone()` with:

```ts
  chooseProfileConnection(borrowerId: number, connectionId: number): void {
    this.run(
      { action: 'profile', configId: borrowerId },
      this.reloadWhenGone(
        this.http.put<AiConfig>(`${this.base}/api/me/ai/configs/${borrowerId}/profile`, {
          connectionId,
        }),
      ),
      (config) => this.upsert(config),
    );
  }

  clearProfileConnection(borrowerId: number): void {
    this.run(
      { action: 'profile', configId: borrowerId },
      this.reloadWhenGone(
        this.http.delete<void>(`${this.base}/api/me/ai/configs/${borrowerId}/profile`),
      ),
      () => this.forgetProfileConnection(borrowerId),
    );
  }

  /** A 404 means the borrowing row or the connection it names is gone: reload instead of failing. Completing empty
   *  leaves `busy` to the reload's own request. */
  private reloadWhenGone<T>(request: Observable<T>): Observable<T> {
    return request.pipe(
      catchError((error: HttpErrorResponse) => {
        if (error.status !== 404) return throwError(() => error);

        this.load();
        return EMPTY;
      }),
    );
  }

  private forgetProfileConnection(borrowerId: number): void {
    const borrower = this.configs().find((each) => each.id === borrowerId);
    if (borrower) this.upsert({ ...borrower, profileConnectionId: null });
  }
```

Replace `upsert()` and `drop()` with the following, and delete the module-level `holdsAFlagNowTaken()` function at the bottom of the file:

```ts
  /** Replaces the row by id when it exists, so a sibling row's write never
   *  reorders the list; otherwise appends (what `add` needs). A row reported
   *  `active` clears the flag on whichever row held it before -- mirroring the
   *  server's own guarantee of at most one active configuration per account. */
  private upsert(config: AiConfig): void {
    const current = this.configs();
    const index = current.findIndex((each) => each.id === config.id);
    const replaced =
      index === -1
        ? [...current, config]
        : current.map((each, position) => (position === index ? config : each));

    this.configs.set(
      replaced.map((each) =>
        each.id !== config.id && each.active && config.active ? { ...each, active: false } : each,
      ),
    );
    this.applyAvailability();
  }

  /** Removes the row and, as the server does, every profile pointer to it. */
  private drop(id: number): void {
    this.configs.set(
      this.configs()
        .filter((each) => each.id !== id)
        .map((each) =>
          each.profileConnectionId === id ? { ...each, profileConnectionId: null } : each,
        ),
    );
    this.applyAvailability();
  }
```

- [ ] **Step 6: The component**

In `ai-section.component.ts`, above the `@Component` decorator, add:

```ts
interface ProfilePick {
  readonly borrowerId: number;
  readonly connectionId: number | null;
}
```

Replace the block from `readonly profileSourceId = computed(` through the end of `chooseProfileSource()` (keep `profileCandidates` as it is) with:

```ts
  /** The pick while its write is in flight, then null: the borrowing row's select keeps the user's pick through the
   *  request and shows what the server holds once it settles. The source is an object so a busy flip that ends where
   *  it began, unread in between, still recomputes. */
  readonly profilePick = linkedSignal<{ busy: boolean }, ProfilePick | null>({
    source: () => ({ busy: this.ai.busy() }),
    computation: (source, previous) => (source.busy && previous ? previous.value : null),
  });

  shownProfileConnectionId(config: AiConfig): number | null {
    const pick = this.profilePick();
    return pick?.borrowerId === config.id ? pick.connectionId : config.profileConnectionId;
  }

  chooseProfileConnection(config: AiConfig, event: Event): void {
    const value = (event.target as HTMLSelectElement).value;
    const connectionId = value === '' ? null : Number(value);
    this.profilePick.set({ borrowerId: config.id, connectionId });
    if (connectionId === null) {
      this.ai.clearProfileConnection(config.id);
      return;
    }
    this.ai.chooseProfileConnection(config.id, connectionId);
  }

  profileFailure(configId: number): string | null {
    return this.failureFor('profile', configId);
  }
```

Replace `rowFailure()` and `messageFor()` with:

```ts
  rowFailure(configId: number): string | null {
    return this.failureFor('row', configId);
  }

  private failureFor(action: 'row' | 'profile', configId: number): string | null {
    const scoped = this.ai.failure();
    if (!scoped || scoped.scope.action !== action) return null;
    if (!('configId' in scoped.scope) || scoped.scope.configId !== configId) return null;

    return this.message(scoped.failure);
  }

  private messageFor(action: 'load' | 'add'): string | null {
    const scoped = this.ai.failure();
    if (!scoped || scoped.scope.action !== action) return null;

    return this.message(scoped.failure);
  }
```

- [ ] **Step 7: The template**

Replace the `@if (config.capabilities.profile === 'borrowed') { … }` block (lines 158–188) with:

```html
                    @if (config.capabilities.profile === 'borrowed') {
                      <div class="profile-connection">
                        @if (profileCandidates().length) {
                          <app-field
                            [label]="'settings.ai.profileConnection.label' | transloco"
                            [info]="'settings.ai.profileConnection.info' | transloco"
                          >
                            <select
                              [disabled]="ai.busy()"
                              (change)="chooseProfileConnection(config, $event)"
                            >
                              <option
                                value=""
                                [selected]="shownProfileConnectionId(config) === null"
                              >
                                {{ 'settings.ai.profileConnection.none' | transloco }}
                              </option>
                              @for (candidate of profileCandidates(); track candidate.id) {
                                <option
                                  [value]="candidate.id"
                                  [selected]="shownProfileConnectionId(config) === candidate.id"
                                >
                                  {{ label(candidate) }}
                                </option>
                              }
                            </select>
                          </app-field>
                        } @else {
                          <p class="hint">
                            {{ 'settings.ai.profileConnection.empty' | transloco }}
                          </p>
                        }
                        @if (profileFailure(config.id); as message) {
                          <app-error-banner [message]="message" />
                        }
                      </div>
                    }
```

- [ ] **Step 8: The info text names this row**

- `en.json` `settings.ai.profileConnection.info`: `"This connection's engine cannot write your reading profile itself. The connection chosen here writes it at the start of every run on this connection."`
- `de.json` `settings.ai.profileConnection.info`: `"Die Engine dieser Verbindung kann dein Leseprofil nicht selbst schreiben. Die hier gewählte Verbindung schreibt es zu Beginn jedes Durchlaufs mit dieser Verbindung."`

- [ ] **Step 9: Format, run the specs, watch them pass**

```bash
docker compose exec -T frontend npx prettier --write src/app/settings/ai
docker compose exec -T frontend npx jest src/app/settings/ai
```

Expected: all green. A stale-cache "Cannot find module" is the jest cache, not the code: re-run with `--no-cache`.

- [ ] **Step 10: Deletion checks**

Restore after each one and quote the FAIL:

1. `shownProfileConnectionId()`: always return `config.profileConnectionId`. `'keeps the pick and locks the select while the choice is being saved'` must fail on `shown(select)` mid-flight.
2. `profilePick`'s source: return `this.ai.busy()` (a boolean) and adjust the computation to `(busy, previous) => (busy && previous ? previous.value : null)`. Run the component spec. Report whether `'puts the select back on the stored choice after two refusals in a row'` or `"shows a refusal under the row that was refused…"` fails. If none does, say so in the report: the object source then guards only the unread-flip case the tests cannot reach, and the planner decides whether its comment stays.
3. `failureFor()`: drop the `configId` comparison. `"shows a refusal under the row that was refused…"` must fail on `banners(areas[0])`.
4. `drop()`: drop the `.map(…)`. `'drops the pointers to a removed connection, as the server does'` must fail.
5. `forgetProfileConnection()`: make it a no-op. `'clears the profile connection of one borrowing row'` must fail.

- [ ] **Step 11: The frontend gate, then commit**

```bash
docker compose exec -T frontend npm run check
git add frontend/src/app/settings/ai frontend/public/i18n/en.json frontend/public/i18n/de.json
git commit -m "feat(#1349): each jev connection's picker edits its own profile connection"
```

---

### Task 5: Docs and wording

**Files:**
- Modify: `README.md:73-75`
- Modify: `docs/recommendations-runs.md:105-108`
- Modify: `backend/src/Service/Recommendation/Jev/JevProfileStep.php:16`
- Modify: `frontend/src/app/core/ai-availability.service.ts:17`

- [ ] **Step 1: README**

Replace the bullet:

```markdown
- Or let TypeSafe's Jev (System One, directly or through OpenRouter) score
  every unread article: one yes/no judgement per article, ranked by
  probability, built on the reading profile an LLM connection distils.
```

with:

```markdown
- Or let TypeSafe's Jev (System One, directly or through OpenRouter) score
  every unread article: one yes/no judgement per article, ranked by
  probability, built on the reading profile distilled by the LLM connection
  you pick for that Jev connection.
```

- [ ] **Step 2: docs/recommendations-runs.md**

Replace this sentence:

> A Jev run distils first, through the profile connection the account picks in Settings → AI (falling back to the last stored profile when the distillation fails); without one the run fails with a message that says so.

with:

> A Jev run distils first, through the profile connection picked for the active Jev connection in Settings → AI (each Jev connection keeps its own pointer, `user_ai_settings.profile_connection_id`; deleting the pointed-at connection clears it). When the distillation fails, it falls back to the last stored profile; without a profile connection the run fails with a message that says so.

Keep the file's existing line wrapping at about 120 columns.

- [ ] **Step 3: Source wording**

- `JevProfileStep` docblock, first line: `* A Jev run's profile: distilled through the profile connection the active connection borrows, else the last stored`. Re-wrap the second line so the sentence stays intact: `* one, else the run fails. Failed, not cancelled, so a resume distils again once the account has fixed the cause.`
- `ai-availability.service.ts:17`: `/** Where the engine's reader profile comes from: its own connection, or the profile connection picked for it. */`

Then check for any remaining account-wide wording:

```bash
grep -rn -i -E "account picks|account's profile connection|account-wide" README.md docs backend/src frontend/src/app | grep -v "docs/superpowers"
```

Expected: no output about the profile connection.

- [ ] **Step 4: Commit**

```bash
(cd backend && composer cs && composer md)
docker compose exec -T frontend npx prettier --check src/app/core/ai-availability.service.ts
git add README.md docs/recommendations-runs.md backend/src/Service/Recommendation/Jev/JevProfileStep.php frontend/src/app/core/ai-availability.service.ts
git commit -m "docs(#1349): the profile connection belongs to each jev connection"
```

---

### Task 6: Gates and a real Jev run

**Files:** none changed unless a gate fails. A fix goes in its own `fix(#1349): …` commit, and the report names it.

- [ ] **Step 1: The stack serves this checkout, with current code**

```bash
bash backend/bin/e2e-preflight.sh "$(git rev-parse --show-toplevel)"
docker compose ps --format '{{.Service}} {{.State}}'
docker compose exec php bin/console cache:clear
docker compose restart worker
docker compose exec -T php bin/console doctrine:migrations:up-to-date
```

Expected:
- the preflight exits 0 silently;
- `php`, `worker`, `nginx`, `mysql` and `frontend` are `running`;
- the migrations report `[OK] Up-to-date!`, because Task 3 Step 18 already applied this one.

Wait with the Monitor tool until `docker compose ps worker --format '{{.Health}}'` prints `healthy`.

- [ ] **Step 2: Backend gates**

From `backend/`:

```bash
bin/console cache:warmup
composer check
composer md
composer test:parallel
```

From the root: `docker compose exec -T php composer test` (the MySQL leg). Then `composer infection:diff` from `backend/`. It mutates committed changes only, so commit first. Run the PhpStorm inspections (`mcp__phpstorm__lint_files`) on every changed PHP file; ERROR and WARNING block.

Expected:
- every gate is green;
- phptramp reports 0 warnings;
- Infection's MSI is at or above `minMsi`.

For any escaped mutant on a line this branch touched: add the missing pin, or report why it is equivalent.

- [ ] **Step 3: Frontend gate**

`docker compose exec -T frontend npm run check`. Expected: green. Run one Jest process at a time.

- [ ] **Step 4: The real Jev run (user 2, connection 8 borrowing connection 6)**

Read-only state first:

```bash
docker compose exec -T php bin/console dbal:run-sql "SELECT u.id, u.email, u.active_ai_config_id, s.model, s.profile_connection_id FROM app_user u JOIN user_ai_settings s ON s.id = u.active_ai_config_id WHERE u.id = 2"
docker compose exec -T php bin/console dbal:run-sql "SELECT id, status FROM recommendation_run WHERE user_id = 2 AND status IN ('pending', 'running')"
```

Expected:
- one row: `active_ai_config_id` 8, model `jev-latest`, `profile_connection_id` 6;
- no active run. If one is active, let it finish (poll as below); never write SQL to end it.

Start the run. The token is the output line starting `eyJ`:

```bash
EMAIL=<the email from the first query>
TOKEN=$(docker compose exec -T php bin/console lexik:jwt:generate-token "$EMAIL" | grep -E '^eyJ' | tr -d '[:space:]')
curl -sk https://localhost:8443/api/me/ai -H "Authorization: Bearer $TOKEN" | jq '.configs[] | {id, model, profileConnectionId}'
curl -sk -X POST https://localhost:8443/api/recommendations/runs -H "Authorization: Bearer $TOKEN" | jq '{status, batchesTotal, batchesDone}'
```

Expected: the configs list shows `8 → profileConnectionId 6` and `null` on every other row; the start answers `"status": "pending"`.

Poll with the Monitor tool (foreground `sleep` is blocked):

```bash
RUN_ID=$(docker compose exec -T php bin/console dbal:run-sql "SELECT MAX(id) AS id FROM recommendation_run WHERE user_id = 2" | grep -Eo '[0-9]+' | tail -n 1)
until docker compose exec -T php bin/console dbal:run-sql "SELECT status FROM recommendation_run WHERE id = $RUN_ID" | grep -qE 'completed|failed|cancelled'; do sleep 20; done
```

Check the result:

```bash
docker compose exec -T php bin/console dbal:run-sql "SELECT status, engine_kind, batches_done, JSON_LENGTH(candidate_batches) + 1 AS batches_total, transport_failures, attempts, error FROM recommendation_run WHERE id = $RUN_ID"
docker compose exec -T php bin/console dbal:run-sql "SELECT phase, batch_number, attempt, verdict, answering_model, error_detail FROM recommendation_run_log WHERE run_id = $RUN_ID ORDER BY id"
ls -t backend/var/log/dev-*.log | head -n 1 | xargs tail -n 400 | jq -c 'select(.level >= 300) | {datetime, channel, message}'
```

Expected:
- `completed`, engine `jev`, `batches_done` = `batches_total` (the distillation plus one per batch), `transport_failures` 0, `error` NULL;
- the log has one `distill` row, then one `batch` row per batch, every verdict `usable`;
- the `distill` row's `answering_model`, when the chat client records one, is connection 6's `qwen/…` model;
- the dev log has nothing at warning or above dated after the run started.

Any retry, `unusable` or `transport-failed` row is a warning to report, even when the run completed. If the column names differ from the ones above, the query fails: read `SHOW COLUMNS FROM recommendation_run` and amend this step.

Report line for the PR body: `run <id> as user 2 (Jev connection 8 borrowing connection 6, qwen/qwen3.7-flash): completed, <n>/<n> steps, 0 transport failures, every call usable on attempt 1, dev log clean`.

- [ ] **Step 5: Hand back**

Report the gates, the deletion-check FAILs from Tasks 1–4, the migration evidence from Task 1 Steps 6–7 and Task 3 Steps 17–18, and the run line. The PR (`Closes #1349`, base `develop`) is outside this plan; the caller opens it.

---

## Self-review against the issue

| Issue requirement | Task |
|---|---|
| Schema: `profile_connection_id` → `id`, nullable, SET NULL; drop `profile_source` | T1 (add + migration), T3 Step 12 (mapping drop) |
| Carry-over for `profile_source = 1` to the user's `jev-*` rows, SQLite + MySQL | T1 Steps 5–7 |
| `PUT …/{id}/profile` with `{"connectionId"}`, `DELETE` clears, same user, `canBuildProfiles`, same rejection message | T3 Steps 4, 10, 11 |
| Config JSON `profileConnectionId` | T3 Steps 5, 12 |
| iOS checklist | T3 Step 15 |
| `borrowedFor()` reads the pointer, read-time usability; `findProfileSourceFor`, `findUsableFor`, sibling loop gone | T3 Steps 9, 10, 12 |
| Configurator clears pointers before remove | T2 |
| Frontend per-row select, `profileSourceId`, flag half of `holdsAFlagNowTaken`, holder lookup gone | T4 |
| Docs | T5 |
| Two Jev connections point at different LLM connections, and a run on each borrows its own | T3 (resolver, chooser, controller, `JevPipelineTest::testAJevConnectionDistilsThroughItsOwnProfileConnection`), T4 (two rows) |
| Deleting the pointed-at LLM connection → no profile connection, `NO_PROFILE_CONNECTION`, never a 500 | T2, T3 (`testDeletingTheProfileConnectionLeavesTheJevConnectionWithoutOne`, `JevPipelineTest` deletion test) |
| Migration verified from empty + carry-over; applied to live Docker DB | T1 Steps 6–7, T3 Steps 17–18 |
| All gates green; real Jev run completes | T6 |
