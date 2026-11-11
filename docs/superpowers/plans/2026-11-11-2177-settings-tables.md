# Settings tables instead of user_preferences Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** `Preferences` and `user_preferences` go away (#2177). Their settings move to `user_reading_settings`, `user_sharing_settings` and `user_digest_settings`, one row per user, and `passkey_offer_answered_at` moves onto `app_user`. The API and the backup format stay byte-identical.

**Architecture:** Each settings table gets a `final` entity in `App\Entity` that owns a `OneToOne` to `User` (`user_id`, unique, `ON DELETE CASCADE`), and a repository with `forUser(User): X`. **`User` does not map the inverse sides.** A Doctrine entity listener (`UserSettingsRowsListener`, `prePersist` on `User`) persists the three rows in the same flush that inserts the account, so every creation path gets them, as the `User` constructor guaranteed for `Preferences`. Readers inject the category's repository. `MeProfileJson`, the backup export and the restore load all three rows through `App\Service\Account\AccountSettings::of(User): AccountSettingsModel`. One category moves per task. Each move is atomic: entity, migration (create, copy, drop the old columns), callers, tests and backup declarations land in one commit. `Preferences` shrinks task by task until Task 4 deletes it.

**Tech Stack:** PHP 8.5, Symfony 8.1, Doctrine ORM 3.6 / DBAL 4, Doctrine Migrations, PHPUnit 12, MySQL 8.4 and SQLite.

**Spec:** GitHub issue #2177, plus these rulings from Lars: the table names and the field-to-table split below; `passkeyOfferAnsweredAt` moves to `User`; one row per user, created with the user; a data migration on both dialects followed by `schema:validate`; `/api/me`, every PATCH and the backup line stay byte-identical. `user_feed_directory_scope` (#2176) is out of scope.

## Decisions this plan makes, and why

1. **User maps no settings association.** `User` has 15 fields today, which is PHPMD's `TooManyFields` limit (checked: one more field reports "User has 16 fields"). Mirroring `Preferences` would give `User` three inverse `OneToOne`s plus `passkeyOfferAnsweredAt` minus `preferences`, so 18 fields. Without the inverse sides, `User` ends at 15 (−`preferences`, +`passkeyOfferAnsweredAt`). This is not a split to dodge the count. Doctrine always loads an inverse-side `OneToOne` eagerly, with one extra `SELECT` per hydrated `User`. Today every authenticated request pays that for `preferences` (and for `recommendationSettings`). With three inverse sides, it would pay three. After this change a request reads a settings row only when it uses one.
2. **The listener replaces the constructor.** Without an association, the `User` constructor cannot cascade-persist its rows. `prePersist` on `User` covers every creation path: `SignupUserFactory`, `OAuthUserFactory`, `BootstrapAdminProvisioner`, `E2eSeedAdminCommand`, and the 178 test files that call `new User(` and persist it. Nothing persists a `User` by cascade (no `cascade: ['persist']` points at `User`), so the listener always runs inside an explicit `persist($user)`.
3. **`RecommendationSettings` is not the model for row creation.** Its docblock says "No row = all defaults ... the row exists only once the user saves the settings form" (`RecommendationSettingsWriter::loadOrCreate`). The ruling is "created with the user", so this plan follows how `Preferences` creates its row. From `RecommendationSettings` it takes the shape: surrogate `id`, owning `OneToOne` with `ON DELETE CASCADE`, a `uniq_*_user` constraint, and `findOneBy(['user' => $user])`.
4. **One category per task, each an atomic switch; no dual writes.** A "new tables first, callers later" cut would copy the data in Task 1 and then keep writing `user_preferences` until the callers move. Every write made in between would be lost, including the worker's digest watermark on the dev DB. Each task instead creates its table, copies the data, drops the old columns, and moves every caller in one commit. `schema:validate` and both PHPUnit legs pass after every task.
5. **The user's table is `app_user`**, not `user`.
6. **The digest settings stay out of the backup.** The digest fields are `NOT_BACKED_UP` on `Preferences` today (`DIGEST_BACKUP_NOT_YET_WIRED`). `DigestSettings` therefore goes to `ACCOUNT_SCOPED_WHOLLY_DROPPED`, with a row in `docs/backup.md` §6.2. `ReadingSettings` and `SharingSettings` are `BACKED_UP` on the `account` line under the same JSON keys as before.
7. **Clean names inside each table.** The category prefix goes from properties and columns: `DigestSettings::$cadence` / `cadence`, `SharingSettings::$hiddenTargets` / `hidden_targets`. Only the backup keys and the JSON keys keep their old spelling, because those are the wire. `hidden_targets` becomes `NOT NULL` (`[]`): the reason it was nullable ("so the column could be added to existing rows") does not apply to a new table.
8. **A missing settings row is corruption.** `forUser()` throws `\LogicException`, the same as `User::getPreferences()` does today.

## Global Constraints

- Every PHP rule in `CLAUDE.md` binds. In particular: `final` entities and repositories; persistence imports no `App\Service` (`PersistenceKnowsNoServiceRule`); no comments unless a future reader would get the code wrong without one; `Response::HTTP_*` constants, never numbers; every touched `src` file PHPMD-clean before commit; no `@phpstan-ignore`, no baselines, no changes to `tests/PhpStan`.
- `/api/me`, every `PATCH /api/me/*` response and the `account` backup line stay byte-identical. `MeControllerTest::testThePreferencesObjectKeepsItsShapeForEveryStoredSetting` (Task 1) and `AccountLineTest::testItWritesTheAccountsSettingsAndNetworkSwitches` pin them. Their expected strings never change in this plan.
- Old backups still restore: `AccountLine::fromLine()` and its key names do not change.
- Commit format `refactor(#2177): …`. No Claude attribution. Do not push. Do not switch branches; the branch is `refactor/2177-settings-tables`.
- **`forUser()` needs a flushed user.** It queries the database, and the listener's rows only exist after the flush that inserts the user. Code that creates a user and sets a setting in one go must `flush()` first (see `E2eSeedAdminCommand`, Task 1).
- **The dev MySQL holds real data.** Back it up before each destructive migration. Never `docker compose down -v`, never drop the `feedreader` database. The scratch databases below are `var/migration-check.db` (SQLite) and `feedreader_migrations` (MySQL).
- After a task's migration, apply it to the dev Docker MySQL in that same task. Then run `docker compose exec php bin/console cache:clear` and `docker compose restart worker`: php-fpm would otherwise serve a stale DI container, and the worker would keep running old code.

### Test commands

```bash
cd backend && php bin/phpunit <path>                 # one file, SQLite
cd backend && composer test:parallel                 # full SQLite leg
docker compose exec php composer test:parallel       # full MySQL leg (from the repo root)
cd backend && composer check && composer md          # gates
```

### Migration verification recipe (each task runs it with its own SELECTs)

`backend/var/` is git-ignored; keep all verification files in `backend/var/2177-verify/`.

**A. SQLite: data copy plus schema:validate.** Seed once in Task 1, then reuse.

```bash
cd backend && mkdir -p var/2177-verify
export DATABASE_URL='sqlite:///%kernel.project_dir%/var/migration-check.db'
rm -f var/migration-check.db
bin/console doctrine:migrations:migrate 'DoctrineMigrations\Version20261109120000' --no-interaction
sqlite3 var/migration-check.db < var/2177-verify/seed-sqlite.sql
bin/console doctrine:migrations:migrate --no-interaction
bin/console doctrine:schema:validate
sqlite3 -header var/migration-check.db '<the task SELECT>'
unset DATABASE_URL
```

**B. SQLite from empty**, as CI does: `rm -f var/migration-check.db`, then `migrate` and `schema:validate` with the same `DATABASE_URL`.

**C. MySQL from empty**, in Docker, on the scratch database (never `feedreader`):

```bash
docker compose exec -e DATABASE_URL='mysql://root:root@mysql:3306/feedreader_migrations?serverVersion=8.4&charset=utf8mb4' php \
  sh -c 'bin/console doctrine:database:drop --force --if-exists && bin/console doctrine:database:create \
    && bin/console doctrine:migrations:migrate --no-interaction && bin/console doctrine:schema:validate'
```

**D. Dev MySQL, forward only, with a before/after SELECT:**

```bash
docker compose exec -T mysql mysqldump -uroot -proot feedreader user_preferences app_user > backend/var/2177-verify/<task>-backup.sql
docker compose exec -T mysql mysql -uroot -proot feedreader -e '<SELECT from user_preferences>' > backend/var/2177-verify/<task>-before.tsv
docker compose exec php bin/console doctrine:migrations:list | tail -5     # only this task's migration is pending
docker compose exec php bin/console doctrine:migrations:migrate --no-interaction
docker compose exec -T mysql mysql -uroot -proot feedreader -e '<the same SELECT from the new table>' > backend/var/2177-verify/<task>-after.tsv
diff backend/var/2177-verify/<task>-before.tsv backend/var/2177-verify/<task>-after.tsv && echo IDENTICAL
docker compose exec -T mysql mysql -uroot -proot feedreader -e 'SELECT (SELECT COUNT(*) FROM app_user) AS users, (SELECT COUNT(*) FROM <new table>) AS settings_rows'
docker compose exec php bin/console cache:clear && docker compose restart worker
```

Both SELECTs alias the columns to the same names and order by `user_id`, so `diff` compares values only. Take "before" immediately before `migrate`: the running worker may move `digest_last_sent_at` earlier than that.

### Exact DDL

Write the `CREATE TABLE` and FK statements in the migrations from what Doctrine generates, not from memory. On a scratch database migrated to the previous head, run `bin/console doctrine:schema:update --dump-sql` (SQLite: recipe A's `DATABASE_URL`; MySQL: recipe C's `-e DATABASE_URL=…`) after the entity exists. Copy the statements for the new table verbatim, including the generated `FK_…` names. The SQL in this plan shows the intended shape. Where it differs from the dump in a name or a type spelling, the dump wins. `schema:validate` is the arbiter.

---

### Task 1: Reading settings table, the listener, and the API/backup pins

**Files:**
- Create: `backend/src/Entity/ReadingSettings.php`
- Create: `backend/src/Repository/ReadingSettingsRepository.php`
- Create: `backend/src/Repository/UserSettingsRow.php`
- Create: `backend/src/Doctrine/UserSettingsRowsListener.php`
- Create: `backend/src/Service/Account/AccountSettings.php`
- Create: `backend/src/Service/Account/Model/AccountSettingsModel.php`
- Create: `backend/migrations/Version20261111100000.php`
- Modify: `backend/src/Entity/Preferences.php` (remove `scrapeFallbackEnabled`, `magazineStyle`, `trendingEnabled` and their accessors)
- Modify: `backend/src/Http/MeJson.php`, `backend/src/Http/MeProfileJson.php`
- Modify: `backend/src/Service/Discovery/ScrapeFallbackPolicy.php`
- Modify: `backend/src/Service/Trending/TrendingStories.php`
- Modify: `backend/src/Service/Account/AccountPreferencesWriter.php`, `backend/src/Service/Account/AccountReset.php`
- Modify: `backend/src/Command/E2eSeedAdminCommand.php`
- Modify: `backend/src/Service/Backup/Dto/AccountLine.php`, `backend/src/Service/Backup/Pass/RestoreLoadPass.php`, `backend/src/Service/Backup/RestoreLoader.php`, `backend/src/Service/Backup/AccountBackupExporter.php`
- Create tests: `backend/tests/Entity/ReadingSettingsTest.php`, `backend/tests/Repository/ReadingSettingsRepositoryTest.php`, `backend/tests/Repository/UserSettingsRowTest.php`, `backend/tests/Doctrine/UserSettingsRowsListenerTest.php`, `backend/tests/Support/StoredUserSettings.php`
- Delete tests: `backend/tests/Entity/PreferencesTrendingTest.php` (moves into `ReadingSettingsTest`)
- Modify tests: `backend/tests/Controller/Api/MeControllerTest.php` (new pin), `tests/Entity/PreferencesTest.php` (scrape cases move out), `tests/Http/MeJsonTest.php`, `tests/Http/MeProfileJsonTest.php`, `tests/Service/Discovery/ScrapeFallbackPolicyTest.php` (becomes `DbTestCase`), `tests/Service/Preview/FeedPreviewServiceTest.php` (becomes `DbTestCase`), `tests/Service/Subscription/SubscriptionServiceTest.php`, `tests/Controller/Api/FeedPreviewControllerTest.php`, `tests/Controller/Api/MagazineStyleControllerTest.php`, `tests/Controller/Api/ReaderPreferencesControllerTest.php`, `tests/Controller/Api/SubscriptionControllerTest.php`, `tests/Controller/Api/SubscriptionTrendingCountsTest.php`, `tests/Service/Trending/TrendingStoriesTest.php` (constructor at line 680), `tests/Service/Opml/OpmlImporterTest.php`, `tests/Command/E2eSeedAdminCommandTest.php`, `tests/Service/Account/AccountPreferencesWriterTest.php`, `tests/Service/Account/AccountResetTest.php`, `tests/Service/Backup/Dto/AccountLineTest.php`, `tests/Service/Backup/Pass/RestoreLoadPassTest.php`, `tests/Service/Backup/AccountRestorerTest.php`, `tests/Service/Backup/Support/BackupSchemaCoverageTest.php`, `tests/Support/BackupFieldDeclarations.php`, `tests/Support/FullyPopulatedAccount.php`
- Modify docs: `docs/backup.md` §6.3 "On each row you own"

**Interfaces:**
- Produces: `App\Entity\ReadingSettings` (`__construct(User)`, `getUser()`, `isScrapeFallbackEnabled()/setScrapeFallbackEnabled(bool)`, `getMagazineStyle()/setMagazineStyle(MagazineStyle)`, `isTrendingEnabled()/setTrendingEnabled(bool)`); `ReadingSettingsRepository::forUser(User): ReadingSettings`; `UserSettingsRow::required(?object, User): object` (templated); `UserSettingsRowsListener`; `AccountSettings::of(User): AccountSettingsModel`; `AccountSettingsModel(ReadingSettings $reading)`; `MeJson::profile(User, bool $mailEnabled)` and `MeJson::preferences(User, AccountSettingsModel, string $instanceTimezone)`; `AccountLine::of(User, AccountSettingsModel, AccountNetworkSwitchesModel)`; `RestoreLoadPass` gains a fifth constructor parameter, `AccountSettingsModel $accountSettings`; `tests/Support/StoredUserSettings::reading(EntityManagerInterface, User): ReadingSettings`.

- [ ] **Step 1: Pin the API shape before any change.** Add this to `backend/tests/Controller/Api/MeControllerTest.php`. It sets every setting through the public endpoints, so it survives every later task unchanged:

```php
public function testThePreferencesObjectKeepsItsShapeForEveryStoredSetting(): void
{
    $client = self::createClient();
    $this->signIn($client, 'every-setting@example.test');

    $client->request('POST', '/api/me/passkey-offer/answer');
    self::assertResponseStatusCodeSame(Response::HTTP_NO_CONTENT);
    $this->patch($client, '/api/me/preferences', ['scrapeFallbackEnabled' => true]);
    $this->patch($client, '/api/me/magazine-style', ['magazineStyle' => 'airy']);
    $this->patch($client, '/api/me/trending', ['trendingEnabled' => false]);
    $this->patch($client, '/api/me/sharing', ['enabled' => false, 'hiddenTargets' => ['x', 'email'], 'rememberShares' => false]);
    $this->patch(
        $client,
        '/api/me/digest',
        ['enabled' => true, 'cadence' => 'weekly', 'sendHour' => 9, 'weekday' => 3, 'format' => 'text'],
    );
    $expected = '{"scrapeFallbackEnabled":true,"magazineStyle":"airy","passkeyOfferAnswered":true,'
        . '"trendingEnabled":false,"sharing":{"enabled":false,"hiddenTargets":["x","email"],"rememberShares":false},'
        . '"digest":{"enabled":true,"cadence":"weekly","format":"text","sendHour":9,"weekday":3,"timezone":"UTC"}}';
    self::assertSame($expected, json_encode($this->payload($client)['preferences'], \JSON_THROW_ON_ERROR));

    $client->request('GET', '/api/me');

    self::assertResponseIsSuccessful();
    self::assertSame($expected, json_encode($this->payload($client)['preferences'], \JSON_THROW_ON_ERROR));
}

/** @param array<string, mixed> $body */
private function patch(KernelBrowser $client, string $uri, array $body): void
{
    $client->request('PATCH', $uri, server: ['CONTENT_TYPE' => 'application/json'], content: json_encode($body, \JSON_THROW_ON_ERROR));
    self::assertResponseIsSuccessful();
}
```

Add the `KernelBrowser` import if it is missing. If `MeControllerTest` already has a PATCH helper, use that instead of adding a second one. Run `php bin/phpunit tests/Controller/Api/MeControllerTest.php`. It must PASS on the unchanged code: it is a characterization pin. If the timezone is not `UTC` in the test env, read the value the existing defaults test (line ~175) expects and use that. Commit it on its own: `test(#2177): pin the preferences object for every stored setting`.

- [ ] **Step 2: Seed file for recipe A.** Write `backend/var/2177-verify/seed-sqlite.sql`. Read the NOT NULL columns without a default from `sqlite3 var/migration-check.db '.schema app_user'` after migrating to `Version20261109120000`, and fill them in. Three users: user 1 has a preferences row with every value non-default; user 2 has a row with defaults and `hidden_share_targets` NULL; user 3 has no row, to prove the self-healing insert.

```sql
INSERT INTO app_user (id, email, roles, status, created_at) VALUES
  (1, 'copy-one@example.test', '[]', 'active', '2026-11-01 10:00:00'),
  (2, 'copy-two@example.test', '[]', 'active', '2026-11-01 10:00:00'),
  (3, 'copy-three@example.test', '[]', 'active', '2026-11-01 10:00:00');
INSERT INTO user_preferences (user_id, scrape_fallback_enabled, magazine_style, digest_enabled, digest_cadence,
  digest_format, digest_send_hour, digest_weekday, digest_last_sent_at, passkey_offer_answered_at, sharing_enabled,
  hidden_share_targets, remember_shares, trending_enabled) VALUES
  (1, 1, 'airy', 1, 'weekly', 'text', 9, 3, '2026-11-10 08:00:00', '2026-11-01 12:00:00', 0, '["x","email"]', 0, 0),
  (2, 0, 'boxed', 0, 'daily', 'html', 8, 1, NULL, NULL, 1, NULL, 1, 1);
```

- [ ] **Step 3: Failing tests for the entity, the repository, the helper and the listener.**

`backend/tests/Entity/ReadingSettingsTest.php` (`TestCase`): a new row has the scrape fallback off, `MagazineStyle::Boxed` and trending on; each setter keeps what it is given; `getUser()` returns the user it was built with. Move the cases from `PreferencesTrendingTest` and the scrape cases from `PreferencesTest` (`testANewUserHasPreferencesWithScrapingDisabled`, `testScrapeFallbackCanBeEnabled`) here, then delete `PreferencesTrendingTest.php` and those two methods.

`backend/tests/Repository/UserSettingsRowTest.php` (`TestCase`, use `AssignsEntityIds` to give the user id 7):

```php
public function testAPresentRowIsReturned(): void
{
    $row = new \stdClass();

    self::assertSame($row, UserSettingsRow::required($row, $this->user()));
}

public function testAMissingRowIsCorruption(): void
{
    $this->expectException(\LogicException::class);
    $this->expectExceptionMessage('User 7 has no settings row');

    UserSettingsRow::required(null, $this->user());
}
```

`backend/tests/Doctrine/UserSettingsRowsListenerTest.php` (`DbTestCase`):

```php
public function testAPersistedAccountGetsItsSettingsRowsInTheSameFlush(): void
{
    $user = new User('rows@example.test', new \DateTimeImmutable('2026-11-11 09:00:00'));
    $this->entityManager->persist($user);
    $this->entityManager->flush();
    $this->entityManager->clear();

    $reloaded = $this->entityManager->find(User::class, $user->requireId());
    self::assertInstanceOf(User::class, $reloaded);
    self::assertFalse(StoredUserSettings::reading($this->entityManager, $reloaded)->isScrapeFallbackEnabled());
}

public function testPersistingAManagedAccountAgainAddsNoSecondRow(): void
{
    $user = new User('once@example.test', new \DateTimeImmutable('2026-11-11 09:00:00'));
    $this->entityManager->persist($user);
    $this->entityManager->flush();
    $user->setLocale('de');
    $this->entityManager->persist($user);
    $this->entityManager->flush();

    self::assertSame(1, $this->entityManager->getRepository(ReadingSettings::class)->count(['user' => $user]));
}
```

`backend/tests/Repository/ReadingSettingsRepositoryTest.php` (`DbTestCase`): `forUser()` returns the user's row, and not another user's (create two users, change one, assert the other is still at its default); a user whose row was deleted (`DELETE FROM user_reading_settings` through `$this->entityManager->getConnection()->executeStatement`, then `clear()`) makes `forUser()` throw `\LogicException`.

`backend/tests/Support/StoredUserSettings.php`:

```php
final class StoredUserSettings
{
    public static function reading(EntityManagerInterface $entityManager, User $user): ReadingSettings
    {
        $repository = $entityManager->getRepository(ReadingSettings::class);
        Assert::assertInstanceOf(ReadingSettingsRepository::class, $repository);

        return $repository->forUser($user);
    }

    private function __construct()
    {
    }
}
```

Run all four. They fail because the classes do not exist yet.

- [ ] **Step 4: The entity, repository, helper and listener.**

`backend/src/Entity/ReadingSettings.php`:

```php
<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\MagazineStyle;
use App\Repository\ReadingSettingsRepository;
use Doctrine\ORM\Mapping as ORM;

/** One row per account, created with it by UserSettingsRowsListener. */
#[ORM\Entity(repositoryClass: ReadingSettingsRepository::class)]
#[ORM\Table(name: 'user_reading_settings')]
#[ORM\UniqueConstraint(name: 'uniq_reading_settings_user', columns: ['user_id'])]
final class ReadingSettings
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\OneToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $user;

    /** Off by default and presented as experimental: scraped extraction breaks whenever the target page changes. */
    #[ORM\Column(options: ['default' => false])]
    private bool $scrapeFallbackEnabled = false;

    #[ORM\Column(length: 10, enumType: MagazineStyle::class, options: ['default' => 'boxed'])]
    private MagazineStyle $magazineStyle = MagazineStyle::Boxed;

    #[ORM\Column(options: ['default' => true])]
    private bool $trendingEnabled = true;

    /** @noinspection AutowireWrongClass Built with new, never autowired */
    public function __construct(User $user)
    {
        $this->user = $user;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function isScrapeFallbackEnabled(): bool
    {
        return $this->scrapeFallbackEnabled;
    }

    public function setScrapeFallbackEnabled(bool $scrapeFallbackEnabled): void
    {
        $this->scrapeFallbackEnabled = $scrapeFallbackEnabled;
    }

    public function getMagazineStyle(): MagazineStyle
    {
        return $this->magazineStyle;
    }

    public function setMagazineStyle(MagazineStyle $magazineStyle): void
    {
        $this->magazineStyle = $magazineStyle;
    }

    public function isTrendingEnabled(): bool
    {
        return $this->trendingEnabled;
    }

    public function setTrendingEnabled(bool $trendingEnabled): void
    {
        $this->trendingEnabled = $trendingEnabled;
    }
}
```

The `@noinspection` follows `Preferences` (an entity constructor is the agreed exception). Keep it only if PhpStorm flags the constructor without it. If PHPStan reports `$id` as never read, that means the phpstan-doctrine extension did not mark it, so add `getId(): ?int` as `Preferences` has. Do not add an ignore.

`backend/src/Repository/UserSettingsRow.php`:

```php
<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\User;

final class UserSettingsRow
{
    /**
     * @template T of object
     *
     * @param T|null $row
     *
     * @return T
     */
    public static function required(?object $row, User $user): object
    {
        if (null === $row) {
            throw new \LogicException(sprintf(
                'User %d has no settings row; UserSettingsRowsListener creates every one with the account.',
                $user->requireId(),
            ));
        }

        return $row;
    }

    private function __construct()
    {
    }
}
```

`backend/src/Repository/ReadingSettingsRepository.php`:

```php
/** @extends ServiceEntityRepository<ReadingSettings> */
final class ReadingSettingsRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ReadingSettings::class);
    }

    public function forUser(User $user): ReadingSettings
    {
        return UserSettingsRow::required($this->findOneBy(['user' => $user]), $user);
    }
}
```

`backend/src/Doctrine/UserSettingsRowsListener.php`:

```php
<?php

declare(strict_types=1);

namespace App\Doctrine;

use App\Entity\ReadingSettings;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsEntityListener;
use Doctrine\ORM\Event\PrePersistEventArgs;
use Doctrine\ORM\Events;

/** The settings repositories read a row per account and never create one; this is where every account gets them. */
#[AsEntityListener(event: Events::prePersist, method: 'prePersist', entity: User::class)]
final readonly class UserSettingsRowsListener
{
    public function prePersist(User $user, PrePersistEventArgs $event): void
    {
        $event->getObjectManager()->persist(new ReadingSettings($user));
    }
}
```

Doctrine fires `prePersist` only for a new entity, so a second `persist()` adds nothing; the listener test pins that. If the listener test shows the row missing after the flush (a nested `persist()` inside `prePersist` not scheduled), switch to an `onFlush` listener: for each `User` in `getScheduledEntityInsertions()`, persist the row and call `$unitOfWork->computeChangeSet($metadata, $row)`, modelled on `ViewedImpliesHiddenListener`. Do not fall back to creating rows in the factories.

Run the four tests from Step 3. They pass now: `tests/bootstrap.php` builds the test schema from ORM metadata, so the new table exists in tests before the migration does (Step 6).

- [ ] **Step 5: Shrink `Preferences`.** Remove `scrapeFallbackEnabled`, `magazineStyle`, `trendingEnabled`, their six accessors, and the `MagazineStyle` import from `backend/src/Entity/Preferences.php`.

- [ ] **Step 6: The migration.** Create `backend/migrations/Version20261111100000.php`. Model it on `Version20261105100000` (platform branches, `isTransactional(): false`, `abortIfUnsupportedPlatform()`) and `Version20261109120000` (SQLite `DROP COLUMN` per column). Description: `'Reading settings move from user_preferences to user_reading_settings (#2177).'`

`up()`: `skipIf($schema->hasTable('user_reading_settings'), 'Already applied.')`, then:

MySQL:
```sql
CREATE TABLE user_reading_settings (id INT AUTO_INCREMENT NOT NULL, user_id INT NOT NULL,
  scrape_fallback_enabled TINYINT(1) DEFAULT 0 NOT NULL, magazine_style VARCHAR(10) DEFAULT 'boxed' NOT NULL,
  trending_enabled TINYINT(1) DEFAULT 1 NOT NULL, UNIQUE INDEX uniq_reading_settings_user (user_id),
  PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4;
ALTER TABLE user_reading_settings ADD CONSTRAINT FK_<generated> FOREIGN KEY (user_id) REFERENCES app_user (id) ON DELETE CASCADE;
INSERT INTO user_reading_settings (user_id, scrape_fallback_enabled, magazine_style, trending_enabled)
  SELECT user_id, scrape_fallback_enabled, magazine_style, trending_enabled FROM user_preferences;
INSERT INTO user_reading_settings (user_id)
  SELECT id FROM app_user WHERE id NOT IN (SELECT user_id FROM user_reading_settings);
ALTER TABLE user_preferences DROP scrape_fallback_enabled, DROP magazine_style, DROP trending_enabled;
```

SQLite:
```sql
CREATE TABLE user_reading_settings (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, user_id INTEGER NOT NULL,
  scrape_fallback_enabled BOOLEAN DEFAULT 0 NOT NULL, magazine_style VARCHAR(10) DEFAULT 'boxed' NOT NULL,
  trending_enabled BOOLEAN DEFAULT 1 NOT NULL, CONSTRAINT FK_<generated> FOREIGN KEY (user_id)
  REFERENCES app_user (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE);
CREATE UNIQUE INDEX uniq_reading_settings_user ON user_reading_settings (user_id);
-- the two INSERTs as above
ALTER TABLE user_preferences DROP COLUMN scrape_fallback_enabled;
ALTER TABLE user_preferences DROP COLUMN magazine_style;
ALTER TABLE user_preferences DROP COLUMN trending_enabled;
```

`down()`: `skipIf(!$schema->hasTable('user_reading_settings'), 'Not applied.')`. Add the three columns back to `user_preferences` with their old definitions (MySQL: `TINYINT(1) DEFAULT 0 NOT NULL`, `VARCHAR(10) DEFAULT 'boxed' NOT NULL`, `TINYINT(1) DEFAULT 1 NOT NULL`; SQLite: `ADD COLUMN … BOOLEAN DEFAULT 0 NOT NULL` and so on). Copy the values back with `UPDATE user_preferences SET <col> = COALESCE((SELECT r.<col> FROM user_reading_settings r WHERE r.user_id = user_preferences.user_id), <default>)` for each column, then `DROP TABLE user_reading_settings`.

Use the generated FK name and type spellings from `--dump-sql` (see "Exact DDL").

- [ ] **Step 7: Move the callers.**

`backend/src/Service/Account/Model/AccountSettingsModel.php`:

```php
final readonly class AccountSettingsModel
{
    public function __construct(
        public ReadingSettings $reading,
    ) {
    }
}
```

`backend/src/Service/Account/AccountSettings.php`:

```php
final readonly class AccountSettings
{
    public function __construct(
        private ReadingSettingsRepository $readingSettings,
    ) {
    }

    public function of(User $user): AccountSettingsModel
    {
        return new AccountSettingsModel($this->readingSettings->forUser($user));
    }
}
```

`backend/src/Http/MeJson.php`: split it. `profile(User $user, bool $mailEnabled)` returns every key up to and including `emailVerified`. The new `preferences(User $user, AccountSettingsModel $settings, string $instanceTimezone)` returns the `preferences` array in exactly today's key order: `scrapeFallbackEnabled`, `magazineStyle`, `passkeyOfferAnswered`, `trendingEnabled`, `sharing`, `digest`. In this task the reading keys come from `$settings->reading`; `passkeyOfferAnswered`, `sharing` and `digest` still come from `$user->getPreferences()`. Keep the class docblock.

`backend/src/Http/MeProfileJson.php`: inject `AccountSettings $accountSettings`, and:

```php
return [
    ...MeJson::profile($user, $this->mail->isEnabled()),
    'preferences' => MeJson::preferences($user, $this->accountSettings->of($user), $this->instanceTimezone),
    'ai' => $this->activeAi->of($user),
    'catalog' => ['hasEntries' => $this->catalogFeeds->hasPickableFeed()],
];
```

Update the docblock to name both `MeJson` methods.

`ScrapeFallbackPolicy`: inject `ReadingSettingsRepository $readingSettings`, and have `forUser()` read `$this->readingSettings->forUser($user)->isScrapeFallbackEnabled()`.

`TrendingStories::unopenedFor()`: inject `ReadingSettingsRepository $readingSettings` and replace the `getPreferences()` read.

`AccountPreferencesWriter`: inject `ReadingSettingsRepository $readingSettings`. `changeScrapeFallback`, `changeMagazineStyle` and `changeTrending` set through `$this->readingSettings->forUser($user)`.

`AccountReset::reset()`: inject `ReadingSettingsRepository $readingSettings`, and run `$this->readingSettings->forUser($user)->setScrapeFallbackEnabled(false);` before the flush.

`E2eSeedAdminCommand`: inject `ReadingSettingsRepository $readingSettings`. Persist and flush the user first, then set the scrape fallback through the repository and flush again. Keep the existing comment about why the fixture states the preference (it clears the bar) next to the new line.

`AccountLine::of(User $user, AccountSettingsModel $settings, AccountNetworkSwitchesModel $networks)` reads the three reading values from `$settings->reading`, and sharing still from `$user->getPreferences()`. `AccountBackupExporter` injects `AccountSettings $accountSettings` and passes `$this->accountSettings->of($user)` for the user it already loads with `getById($userId)`; bind that user to a variable first.

`RestoreLoadPass`: add the constructor parameter `private readonly AccountSettingsModel $accountSettings`. `loadAccount()` sets the three reading values on `$this->accountSettings->reading`. `RestoreLoader` injects `AccountSettings $accountSettings` and passes `$this->accountSettings->of($user)` as the fifth argument.

- [ ] **Step 8: Move the tests.** Wherever a test set or read `getPreferences()->…ScrapeFallback…/…MagazineStyle…/…Trending…`:
  - In a `DbTestCase` or `ApiTestCase`: use `StoredUserSettings::reading($this->entityManager, $user)` (`$this->entityManager()` in `ApiTestCase`) on a **flushed** user, then flush. If the test reloads after `clear()`, read again through the helper.
  - `ScrapeFallbackPolicyTest` and `FeedPreviewServiceTest` become `DbTestCase`s. Create users through `UserFactory`, take `ScrapeFallbackPolicy` from the container (or `new ScrapeFallbackPolicy($repository)` with the repository from the container), and replace `new ScrapeFallbackPolicy()` in `SubscriptionServiceTest` the same way.
  - `TrendingStoriesTest` line 680: pass `ReadingSettingsRepository` from the container.
  - `MeJsonTest`: call `MeJson::profile($user, true)` for `mail` and `emailVerified`, and `MeJson::preferences($user, new AccountSettingsModel(new ReadingSettings($user)), 'Europe/Berlin')` for the `digest` assertion. The digest values are still set on `getPreferences()` in this task.
  - `MeProfileJsonTest`: the expected arrays become `[...MeJson::profile($user, false), 'preferences' => MeJson::preferences($user, $this->accountSettings()->of($user), 'Europe/Berlin'), 'ai' => …, 'catalog' => …]`, and `profileJson()` passes `AccountSettings` from the container.
  - `AccountLineTest::testItWritesTheAccountsSettingsAndNetworkSwitches`: build `$settings = new AccountSettingsModel(new ReadingSettings($user))`, set the reading values on `$settings->reading`, keep the sharing values on `getPreferences()`, and call `AccountLine::of($user, $settings, $networks)`. **The expected line string does not change.**
  - `RestoreLoadPassTest`: pass `new AccountSettingsModel(new ReadingSettings($user))` as the fifth argument at all four construction sites.
  - `FullyPopulatedAccount::create()`: set the three reading values through `$this->entityManager->getRepository(ReadingSettings::class)` (assert the repository class, as `StoredUserSettings` does), or through `StoredUserSettings::reading()`.
  - `BackupFieldDeclarations`: add `ReadingSettings::class => ['scrapeFallbackEnabled' => 'scrapeFallbackEnabled', 'magazineStyle' => 'magazineStyle', 'trendingEnabled' => 'trendingEnabled']` and `ReadingSettings::class => BackupSchema::KIND_ACCOUNT`, and remove those three from `Preferences::class`.
  - `BackupSchemaCoverageTest::NOT_BACKED_UP`: add `ReadingSettings::class => ['user' => self::OWNER_IS_THE_RESTORING_ACCOUNT]`.
  - `AccountRestorerTest`: `fixtureRowsOf()` returns `'readingSettings' => StoredUserSettings::reading($this->entityManager, $user)` (and its array-shape docblock), `testEveryBackedUpFieldSurvivesTheRestoreRoundTrip` adds `assertFieldsRoundTripped(ReadingSettings::class, …)`, and lines ~287/375 use the helper.
  - `docs/backup.md` §6.3, "On each row you own": the opening sentence names "the reading settings" as well. The test only checks that the backticked `user` row exists, which it does.

- [ ] **Step 9: Run the touched tests, then the full legs.**

```bash
cd backend && php bin/phpunit tests/Entity tests/Repository/ReadingSettingsRepositoryTest.php tests/Repository/UserSettingsRowTest.php \
  tests/Doctrine/UserSettingsRowsListenerTest.php tests/Http tests/Service/Discovery tests/Service/Preview \
  tests/Service/Subscription tests/Service/Trending tests/Service/Account tests/Service/Backup tests/Command \
  tests/Controller/Api/MeControllerTest.php tests/Controller/Api/ReaderPreferencesControllerTest.php \
  tests/Controller/Api/MagazineStyleControllerTest.php tests/Controller/Api/FeedPreviewControllerTest.php \
  tests/Controller/Api/SubscriptionControllerTest.php tests/Controller/Api/SubscriptionTrendingCountsTest.php
cd backend && composer test:parallel
docker compose exec php composer test:parallel
```

Expected: all green. `testThePreferencesObjectKeepsItsShapeForEveryStoredSetting` and `testItWritesTheAccountsSettingsAndNetworkSwitches` pass with their strings unchanged.

- [ ] **Step 10: Verify the migration.** Run recipe A with

```sql
SELECT user_id, scrape_fallback_enabled, magazine_style, trending_enabled FROM user_reading_settings ORDER BY user_id;
```

Expected: `1|1|airy|0`, `2|0|boxed|1`, `3|0|boxed|1`. `schema:validate` reports both mapping and database OK. Then run recipes B and C (from empty, both OK) and recipe D with

```sql
SELECT user_id, scrape_fallback_enabled, magazine_style, trending_enabled FROM user_preferences ORDER BY user_id   -- before
SELECT user_id, scrape_fallback_enabled, magazine_style, trending_enabled FROM user_reading_settings ORDER BY user_id   -- after
```

Expected: `IDENTICAL`, and `users` equals `settings_rows`.

- [ ] **Step 11: Gates.** `cd backend && composer check && composer md` must be clean. PhpStorm `lint_files` on every changed PHP file: no ERROR or WARNING. Then commit:

```bash
git add -A backend docs/backup.md
git commit -m "refactor(#2177): reading settings get their own table"
```

---

### Task 2: Sharing settings table

**Files:**
- Create: `backend/src/Entity/SharingSettings.php`, `backend/src/Repository/SharingSettingsRepository.php`, `backend/migrations/Version20261111110000.php`
- Modify: `backend/src/Entity/Preferences.php` (remove `sharingEnabled`, `hiddenShareTargets`, `rememberingShares`, `recordsShares()`, `TARGET_ID_PATTERN`, `MAX_HIDDEN_SHARE_TARGETS`)
- Modify: `backend/src/Doctrine/UserSettingsRowsListener.php`, `backend/src/Service/Account/AccountSettings.php`, `backend/src/Service/Account/Model/AccountSettingsModel.php`
- Modify: `backend/src/Dto/Me/UpdateSharingRequest.php` (constants), `backend/src/Http/MeJson.php`, `backend/src/Service/Reading/EntryShares.php`, `backend/src/Service/Account/AccountPreferencesWriter.php`, `backend/src/Service/Backup/Dto/AccountLine.php`, `backend/src/Service/Backup/Pass/RestoreLoadPass.php`
- Create tests: `backend/tests/Entity/SharingSettingsTest.php` (from `PreferencesSharingTest`), `backend/tests/Repository/SharingSettingsRepositoryTest.php`
- Delete tests: `backend/tests/Entity/PreferencesSharingTest.php`
- Modify tests: `tests/Support/StoredUserSettings.php` (`sharing()`), `tests/Doctrine/UserSettingsRowsListenerTest.php`, `tests/Controller/Api/SharingControllerTest.php`, `tests/Controller/Api/EntrySharedTest.php`, `tests/Controller/Api/EntryRepostControllerTest.php`, `tests/Controller/Api/EntrySocialPostControllerTest.php`, `tests/Service/Reading/EntryStateUpdaterTest.php`, `tests/Service/Reading/EntryShareRecorderTest.php` (becomes `DbTestCase`), `tests/Service/Social/SocialRepostsTest.php:247`, `tests/Service/Social/SocialResponsesTest.php:521`, `tests/Http/MeJsonTest.php`, `tests/Service/Backup/Dto/AccountLineTest.php`, `tests/Service/Backup/Pass/RestoreLoadPassTest.php`, `tests/Service/Backup/AccountRestorerTest.php`, `tests/Service/Backup/Support/BackupSchemaCoverageTest.php`, `tests/Support/BackupFieldDeclarations.php`, `tests/Support/FullyPopulatedAccount.php`, `tests/Service/Account/AccountPreferencesWriterTest.php`
- Modify docs: `docs/backup.md` §6.3 "On each row you own" (name "the sharing settings")

**Interfaces:**
- Consumes: `UserSettingsRow::required`, `UserSettingsRowsListener`, `AccountSettings`, `AccountSettingsModel`, `StoredUserSettings` from Task 1.
- Produces: `App\Entity\SharingSettings` (`TARGET_ID_PATTERN`, `MAX_HIDDEN_TARGETS`, `getUser()`, `isEnabled()/setEnabled(bool)`, `getHiddenTargets(): list<string>` / `setHiddenTargets(list<string>)`, `isRememberingShares()/setRememberingShares(bool)`, `recordsShares(): bool`); `SharingSettingsRepository::forUser(User): SharingSettings`; `AccountSettingsModel(ReadingSettings $reading, SharingSettings $sharing)`; `StoredUserSettings::sharing(EntityManagerInterface, User): SharingSettings`.

- [ ] **Step 1: Failing tests.** `SharingSettingsTest` (`TestCase`) carries over every case of `PreferencesSharingTest` with the new names: sharing on, nothing hidden and shares remembered by default; it keeps what it is given; malformed and repeated ids are dropped; only the first `MAX_HIDDEN_TARGETS` are kept; `recordsShares()` is true only when both `enabled` and `rememberingShares` are true (one case each for false/true, true/false, true/true). `SharingSettingsRepositoryTest` follows `ReadingSettingsRepositoryTest`. Add a sharing assertion to both listener tests. Run them: FAIL.

- [ ] **Step 2: Entity, repository, listener.** `backend/src/Entity/SharingSettings.php` follows `ReadingSettings` (table `user_sharing_settings`, constraint `uniq_sharing_settings_user`). Its fields:

```php
public const string TARGET_ID_PATTERN = '/^[a-z0-9-]{1,32}$/';

public const int MAX_HIDDEN_TARGETS = 64;

#[ORM\Column(options: ['default' => true])]
private bool $enabled = true;

/** @var list<string> */
#[ORM\Column(type: Types::JSON)]
private array $hiddenTargets = [];

#[ORM\Column(name: 'remember_shares', options: ['default' => true])]
private bool $rememberingShares = true;
```

Move `setHiddenTargets()` over with its docblock ("Drops malformed and repeated ids and keeps the first MAX_HIDDEN_TARGETS, for the API and a restore alike."), plus `recordsShares()`. `getHiddenTargets()` returns `$this->hiddenTargets`; the `?? []` goes with the nullable column. `SharingSettingsRepository::forUser()` follows the reading one. The listener persists `new SharingSettings($user)` as well. `UpdateSharingRequest` uses `SharingSettings::MAX_HIDDEN_TARGETS` and `SharingSettings::TARGET_ID_PATTERN`. Remove the sharing members from `Preferences`.

- [ ] **Step 3: Migration `Version20261111110000`.** It has the same structure as Task 1. Description: `'Sharing settings move from user_preferences to user_sharing_settings (#2177).'`

MySQL:
```sql
CREATE TABLE user_sharing_settings (id INT AUTO_INCREMENT NOT NULL, user_id INT NOT NULL,
  enabled TINYINT(1) DEFAULT 1 NOT NULL, hidden_targets JSON NOT NULL, remember_shares TINYINT(1) DEFAULT 1 NOT NULL,
  UNIQUE INDEX uniq_sharing_settings_user (user_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4;
ALTER TABLE user_sharing_settings ADD CONSTRAINT FK_<generated> FOREIGN KEY (user_id) REFERENCES app_user (id) ON DELETE CASCADE;
INSERT INTO user_sharing_settings (user_id, enabled, hidden_targets, remember_shares)
  SELECT user_id, sharing_enabled, COALESCE(hidden_share_targets, JSON_ARRAY()), remember_shares FROM user_preferences;
INSERT INTO user_sharing_settings (user_id, hidden_targets)
  SELECT id, JSON_ARRAY() FROM app_user WHERE id NOT IN (SELECT user_id FROM user_sharing_settings);
ALTER TABLE user_preferences DROP sharing_enabled, DROP hidden_share_targets, DROP remember_shares;
```

SQLite: `hidden_targets CLOB NOT NULL`, `enabled`/`remember_shares` `BOOLEAN DEFAULT 1 NOT NULL`, the FK inline as in Task 1, `CREATE UNIQUE INDEX uniq_sharing_settings_user`, `COALESCE(hidden_share_targets, '[]')` and `'[]'` in the inserts, and three `ALTER TABLE user_preferences DROP COLUMN …`. Take the exact DDL from `--dump-sql`. On MySQL, Doctrine may emit the JSON column with a `COMMENT`; copy whatever it emits.

`down()`: re-add `sharing_enabled TINYINT(1) DEFAULT 1 NOT NULL`, `hidden_share_targets JSON DEFAULT NULL` (SQLite `CLOB DEFAULT NULL`), and `remember_shares TINYINT(1) DEFAULT 1 NOT NULL`. Copy back with `UPDATE … SET col = COALESCE((SELECT …), default)`; `hidden_share_targets` needs no `COALESCE`. Then `DROP TABLE user_sharing_settings`.

- [ ] **Step 4: Move the callers.** `AccountSettingsModel` gains `public SharingSettings $sharing` after `$reading`, and `AccountSettings` injects `SharingSettingsRepository $sharingSettings` and fills it. `MeJson::preferences()` builds `sharing` from `$settings->sharing` (`isEnabled()`, `getHiddenTargets()`, `isRememberingShares()`). `EntryShares` injects `SharingSettingsRepository $sharingSettings`, and `record()` checks `$this->sharingSettings->forUser($user)->recordsShares()`. `AccountPreferencesWriter` injects `SharingSettingsRepository $sharingSettings`, and `changeSharing()` loads the row once into `$settings` and sets the three values. `AccountLine::of()` reads `sharingEnabled`, `hiddenShareTargets` and `rememberShares` from `$settings->sharing`; the names of the line's constructor arguments and JSON keys do not change. `RestoreLoadPass::loadAccount()` sets the three sharing values on `$this->accountSettings->sharing`.

- [ ] **Step 5: Move the tests.** Add `StoredUserSettings::sharing()`. Use it in every listed controller and service test, on flushed users. `EntryShareRecorderTest` becomes a `DbTestCase`: persist the user through `UserFactory`, keep the `withId` entry, and build `new EntryShares(new FailingEntryShareStore(), new MockClock(…), $sharingRepositoryFromContainer)` (match the new parameter order). Apply the same constructor change in `SocialRepostsTest:247` and `SocialResponsesTest:521`. In `MeJsonTest`, `AccountLineTest` and `RestoreLoadPassTest`, the model is built as `new AccountSettingsModel(new ReadingSettings($user), new SharingSettings($user))`; the `AccountLineTest` expected string does not change. In `FullyPopulatedAccount`, set the sharing values through the repository. `BackupFieldDeclarations` gets `SharingSettings::class => ['enabled' => 'sharingEnabled', 'hiddenTargets' => 'hiddenShareTargets', 'rememberingShares' => 'rememberShares']`, its `KIND_OF` entry, and an empty `Preferences::class => []` in `BACKED_UP` (keep the key until Task 4, so the remaining `Preferences` fields still need a decision). `BackupSchemaCoverageTest::NOT_BACKED_UP` gets `SharingSettings::class => ['user' => self::OWNER_IS_THE_RESTORING_ACCOUNT]`. In `AccountRestorerTest`, `fixtureRowsOf()` adds `sharingSettings`, the round trip asserts `SharingSettings::class`, and the `Preferences::class` round-trip call goes away (no backed-up fields remain).

- [ ] **Step 6: Run** the touched test files, then both full legs. Expected: green, pins unchanged.

- [ ] **Step 7: Verify the migration.** Recipe A with `SELECT user_id, enabled, hidden_targets, remember_shares FROM user_sharing_settings ORDER BY user_id`. Expected: `1|0|["x","email"]|0`, `2|1|[]|1`, `3|1|[]|1`, and `schema:validate` OK. Recipes B and C. Recipe D with

```sql
SELECT user_id, sharing_enabled AS enabled, COALESCE(hidden_share_targets, JSON_ARRAY()) AS hidden_targets, remember_shares FROM user_preferences ORDER BY user_id   -- before
SELECT user_id, enabled, hidden_targets, remember_shares FROM user_sharing_settings ORDER BY user_id   -- after
```

Expected: `IDENTICAL`, and the row count equals the user count.

- [ ] **Step 8: Gates and commit.** `composer check`, `composer md` and PhpStorm lint, all clean. Then `git commit -m "refactor(#2177): sharing settings get their own table"`.

---

### Task 3: Digest settings table and the digest scheduler

**Files:**
- Create: `backend/src/Entity/DigestSettings.php`, `backend/src/Repository/DigestSettingsRepository.php`, `backend/migrations/Version20261111120000.php`
- Modify: `backend/src/Entity/Preferences.php` (remove the six digest fields; `passkeyOfferAnsweredAt` is all that remains)
- Delete: `backend/src/Repository/PreferencesRepository.php`
- Modify: `backend/config/services.yaml:174` (the `DigestRecipientsInterface` alias)
- Modify: `backend/src/Doctrine/UserSettingsRowsListener.php`, `backend/src/Service/Account/AccountSettings.php`, `backend/src/Service/Account/Model/AccountSettingsModel.php`, `backend/src/Http/MeJson.php`, `backend/src/Service/Account/AccountPreferencesWriter.php`
- Modify: `backend/src/Service/Mail/Digest/DigestRecipients/DigestRecipientsInterface.php`, `DigestEnablement.php`, `DigestSchedule.php`, `SendDueDigests.php`, `Factory/DigestMailFactory.php` (all under `backend/src/Service/Mail/Digest/`)
- Create tests: `backend/tests/Entity/DigestSettingsTest.php` (from the digest cases of `PreferencesTest` and from `PreferencesDigestFormatTest`), `backend/tests/Repository/DigestSettingsRepositoryTest.php` (from `PreferencesRepositoryTest`)
- Delete tests: `backend/tests/Entity/PreferencesDigestFormatTest.php`, `backend/tests/Repository/PreferencesRepositoryTest.php`
- Modify tests: `tests/Entity/PreferencesTest.php` (only `testPreferencesPointBackAtTheirUser` remains; delete the file if nothing remains), `tests/Support/StoredUserSettings.php` (`digest()`), `tests/Doctrine/UserSettingsRowsListenerTest.php`, `tests/Http/MeJsonTest.php`, `tests/Controller/Api/MeDigestControllerTest.php`, `tests/Controller/Api/MeDigestTestControllerTest.php`, `tests/Service/Mail/Digest/DigestEnablementTest.php`, `tests/Service/Mail/Digest/DigestScheduleTest.php`, `tests/Service/Mail/Digest/SendDueDigestsTest.php`, `tests/Service/Mail/Digest/SendDueDigestsHealthTest.php`, `tests/Service/Mail/Digest/SendTestDigestTest.php` (constructor at line 149), `tests/Service/Mail/Digest/Factory/DigestMailFactoryTest.php` and `tests/Service/Mail/Digest/DigestMailer/DigestMailerTest.php` (both become `DbTestCase`), `tests/Service/Maintenance/MaintenanceSweep/DigestsTickSweepTest.php`, `tests/Service/Worker/SendDueDigestsHandlerTest.php`, `tests/Service/Account/AccountPreferencesWriterTest.php`, `tests/Service/Backup/Dto/AccountLineTest.php`, `tests/Service/Backup/Pass/RestoreLoadPassTest.php`, `tests/Service/Backup/Support/BackupSchemaCoverageTest.php`
- Modify docs: `docs/backup.md` §6.2 (new `DigestSettings` row), §6.3 (remove the two digest rows under "On the account")

**Interfaces:**
- Consumes: Task 1 and 2 classes.
- Produces: `App\Entity\DigestSettings` (`getUser()`, `isEnabled()/setEnabled(bool)`, `getCadence()/setCadence(DigestCadence)`, `getFormat()/setFormat(DigestFormat)`, `getSendHour()/setSendHour(int)`, `getWeekday()/setWeekday(int)`, `getLastSentAt()/setLastSentAt(?\DateTimeImmutable)`); `DigestSettingsRepository implements DigestRecipientsInterface` with `forUser(User): DigestSettings` and `findWithDigestEnabled(): list<DigestSettings>`; `DigestEnablement::applyTo(DigestSettings, DigestConfigurationModel)`; `DigestSchedule::mostRecentDue(DigestSettings, \DateTimeImmutable)`; `AccountSettingsModel(ReadingSettings $reading, SharingSettings $sharing, DigestSettings $digest)`; `StoredUserSettings::digest(EntityManagerInterface, User): DigestSettings`.

- [ ] **Step 1: Failing tests.** `DigestSettingsTest` covers: the defaults are off, daily, html, hour 8, Monday, never sent; every field round-trips; text format is accepted; `getUser()` returns the user it was built with. `DigestSettingsRepositoryTest` carries over `testFindWithDigestEnabledReturnsOnlyEnabledRows`. Add a test that the recipients' users are fetch-joined: after `clear()`, call `findWithDigestEnabled()` and assert that `$this->entityManager->getUnitOfWork()->isUninitializedObject($row->getUser())` is false. It also has the `forUser` cases. Extend the listener tests. Run them: FAIL.

- [ ] **Step 2: Entity and repository.** `backend/src/Entity/DigestSettings.php` follows `ReadingSettings` (table `user_digest_settings`, constraint `uniq_digest_settings_user`). Its fields keep the comments that clear the bar:

```php
#[ORM\Column(options: ['default' => false])]
private bool $enabled = false;

#[ORM\Column(length: 10, enumType: DigestCadence::class, options: ['default' => 'daily'])]
private DigestCadence $cadence = DigestCadence::Daily;

#[ORM\Column(length: 10, enumType: DigestFormat::class, options: ['default' => 'html'])]
private DigestFormat $format = DigestFormat::Html;

/** The local hour (0–23) the digest is sent, interpreted in the instance timezone. */
#[ORM\Column(type: Types::SMALLINT, options: ['default' => 8])]
private int $sendHour = 8;

/** ISO-8601 weekday (1=Mon … 7=Sun); only meaningful for the weekly cadence. */
#[ORM\Column(type: Types::SMALLINT, options: ['default' => 1])]
private int $weekday = 1;

/** Naive UTC. Null until the digest is first enabled; the "since" marker. */
#[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
private ?\DateTimeImmutable $lastSentAt = null;
```

`backend/src/Repository/DigestSettingsRepository.php`:

```php
/** @extends ServiceEntityRepository<DigestSettings> */
final class DigestSettingsRepository extends ServiceEntityRepository implements DigestRecipientsInterface
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, DigestSettings::class);
    }

    public function forUser(User $user): DigestSettings
    {
        return UserSettingsRow::required($this->findOneBy(['user' => $user]), $user);
    }

    /**
     * Every row with the digest enabled, joined to its user, for the scheduler to test dueness against.
     * Enabled is the only cheap pre-filter; dueness needs the timezone maths, so it is applied in PHP.
     *
     * @return list<DigestSettings>
     */
    public function findWithDigestEnabled(): array
    {
        /** @var list<DigestSettings> $rows */
        $rows = $this->createQueryBuilder('d')
            // Fetch-join the user the sweep reads for every due row, so dueness
            // and eligibility do not each fire a lazy-load SELECT per account.
            ->addSelect('u')
            ->join('d.user', 'u')
            ->andWhere('d.enabled = true')
            ->getQuery()->getResult();

        return $rows;
    }
}
```

`DigestRecipientsInterface` returns `list<DigestSettings>`. `services.yaml:174` becomes `App\Service\Mail\Digest\DigestRecipients\DigestRecipientsInterface: '@App\Repository\DigestSettingsRepository'`. Delete `PreferencesRepository.php`, and point `Preferences`' `#[ORM\Entity]` at no repository class (plain `#[ORM\Entity]`). The listener persists `new DigestSettings($user)` too. Remove the digest members and the `DigestCadence`/`DigestFormat`/`Types` imports that are no longer used from `Preferences`.

- [ ] **Step 3: Migration `Version20261111120000`.** Description: `'Digest settings move from user_preferences to user_digest_settings (#2177).'`

MySQL:
```sql
CREATE TABLE user_digest_settings (id INT AUTO_INCREMENT NOT NULL, user_id INT NOT NULL,
  enabled TINYINT(1) DEFAULT 0 NOT NULL, cadence VARCHAR(10) DEFAULT 'daily' NOT NULL,
  format VARCHAR(10) DEFAULT 'html' NOT NULL, send_hour SMALLINT DEFAULT 8 NOT NULL,
  weekday SMALLINT DEFAULT 1 NOT NULL, last_sent_at DATETIME DEFAULT NULL,
  UNIQUE INDEX uniq_digest_settings_user (user_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4;
ALTER TABLE user_digest_settings ADD CONSTRAINT FK_<generated> FOREIGN KEY (user_id) REFERENCES app_user (id) ON DELETE CASCADE;
INSERT INTO user_digest_settings (user_id, enabled, cadence, format, send_hour, weekday, last_sent_at)
  SELECT user_id, digest_enabled, digest_cadence, digest_format, digest_send_hour, digest_weekday, digest_last_sent_at
  FROM user_preferences;
INSERT INTO user_digest_settings (user_id)
  SELECT id FROM app_user WHERE id NOT IN (SELECT user_id FROM user_digest_settings);
ALTER TABLE user_preferences DROP digest_enabled, DROP digest_cadence, DROP digest_format, DROP digest_send_hour,
  DROP digest_weekday, DROP digest_last_sent_at;
```

SQLite: `BOOLEAN`, `INTEGER`, the inline FK, `CREATE UNIQUE INDEX uniq_digest_settings_user`, and six `DROP COLUMN` statements. Take the exact DDL from `--dump-sql`. `down()` re-adds the six `digest_*` columns with their old definitions (see `Version20260828120000` and `Version20260830181347`), copies back with `COALESCE((SELECT …), default)` (`digest_last_sent_at` needs none), and drops the table.

- [ ] **Step 4: Move the callers.**
  - `DigestEnablement::applyTo(DigestSettings $settings, DigestConfigurationModel $configuration)` and `isFirstEnable(bool, bool, DigestSettings)`: rename `$preferences` to `$settings` and use the new accessor names (`setEnabled`, `setCadence`, `setSendHour`, `setWeekday`, `setFormat`, `getLastSentAt`, `setLastSentAt`). The class docblock says `lastSentAt` instead of `digestLastSentAt`.
  - `DigestSchedule::mostRecentDue(DigestSettings $settings, …)`: the same renames.
  - `SendDueDigests`: iterate `$this->recipients->findWithDigestEnabled() as $settings`; `attemptSend`, `sendAndAdvance` and `dueOccurrence` take `DigestSettings $settings`. The class docblock says "a settings row can outlive the state that made it valid".
  - `DigestMailFactory`: inject `DigestSettingsRepository $digestSettings`; `build()` reads `$this->digestSettings->forUser($user)->getFormat()`. Update the `DigestMailer` class docblock, which names `digest_format`, to "the user's digest format".
  - `AccountSettingsModel` gains `public DigestSettings $digest`, and `AccountSettings` injects `DigestSettingsRepository $digestSettings` and fills it. `MeJson::preferences()` builds `digest` from `$settings->digest` plus the timezone. Only `passkeyOfferAnswered` still reads `$user->getPreferences()`.
  - `AccountPreferencesWriter` injects `DigestSettingsRepository $digestSettings`; `changeDigest()` calls `applyTo($this->digestSettings->forUser($user), $configuration)`.

- [ ] **Step 5: Move the tests.** Add `StoredUserSettings::digest()`. `DigestEnablementTest` and `DigestScheduleTest` stay `TestCase`s and build `new DigestSettings(new User(…))`. `SendDueDigestsTest`, `SendDueDigestsHealthTest`, `SendDueDigestsHandlerTest`, `DigestsTickSweepTest` and `SendTestDigestTest` set digest values through `StoredUserSettings::digest()` on flushed users. Their in-file `DigestRecipientsInterface` doubles return `DigestSettings` rows. Their `new SendDueDigests(…)` and `new DigestMailFactory(…)` calls get the repository from the container. `DigestMailFactoryTest` and `DigestMailerTest` become `DbTestCase`s: they create the user through `UserFactory`, set the format through the helper, and pass the repository from the container. `MeJsonTest` sets the digest values on `new DigestSettings($user)` inside the model. `AccountLineTest` and `RestoreLoadPassTest` build the three-argument model. `MeDigestControllerTest` reloads through the helper (`isEnabled()`).

Backup: in `BackupSchemaCoverageTest`, remove the six digest entries from `NOT_BACKED_UP[Preferences::class]` and add to `ACCOUNT_SCOPED_WHOLLY_DROPPED`:

```php
DigestSettings::class => 'The email digest settings (#636): ' . self::DIGEST_BACKUP_NOT_YET_WIRED
    . ' The last-sent watermark is system-written; a restored one would only delay the next send.',
```

Keep the constant: `SavedSearch::includeInDigest` still uses it. In `docs/backup.md` §6.2, add a row: `` | `DigestSettings` | Your email digest settings: on or off, how often, the format, the hour and the weekday, and when the last digest went out. The backup format does not carry them yet. A restored "last sent" date would only delay the next send. | ``. In §6.3 "On the account", delete the `digestEnabled, …` row and the `digestLastSentAt` row.

- [ ] **Step 6: Run** the digest, mail, worker, maintenance, account, backup and `Http` test directories, then both full legs. Expected: green; both pins unchanged.

- [ ] **Step 7: Verify the migration.** Recipe A with `SELECT user_id, enabled, cadence, format, send_hour, weekday, last_sent_at FROM user_digest_settings ORDER BY user_id`. Expected: `1|1|weekly|text|9|3|2026-11-10 08:00:00`, `2|0|daily|html|8|1|`, `3|0|daily|html|8|1|`, and `schema:validate` OK. Recipes B and C. Recipe D with

```sql
SELECT user_id, digest_enabled AS enabled, digest_cadence AS cadence, digest_format AS format, digest_send_hour AS send_hour, digest_weekday AS weekday, digest_last_sent_at AS last_sent_at FROM user_preferences ORDER BY user_id   -- before
SELECT user_id, enabled, cadence, format, send_hour, weekday, last_sent_at FROM user_digest_settings ORDER BY user_id   -- after
```

Expected: `IDENTICAL`, and row count = user count. Restart the worker afterwards; it runs the digest sweep.

- [ ] **Step 8: Gates and commit.** `composer check`, `composer md` and PhpStorm lint, all clean. Then `git commit -m "refactor(#2177): digest settings get their own table"`.

---

### Task 4: Passkey marker onto the account, Preferences removed

**Files:**
- Delete: `backend/src/Entity/Preferences.php`
- Create: `backend/migrations/Version20261111130000.php`
- Modify: `backend/src/Entity/User.php` (remove `$preferences` and `getPreferences()`; add `passkeyOfferAnsweredAt`), `backend/src/Entity/AiProviderSettings.php:15` (docblock names `Preferences`)
- Modify: `backend/src/Service/Passkey/PasskeyOffer.php`, `backend/src/Http/MeJson.php`
- Modify tests: `tests/Service/Passkey/PasskeyOfferTest.php`, `tests/Controller/Api/PasskeyRegistrationTest.php`, `tests/Service/Account/AccountPreferencesWriterTest.php`, `tests/Entity/PreferencesTest.php` (delete), `tests/Service/Backup/AccountRestorerTest.php` (drop the `Preferences` import and the `preferences` key from `fixtureRowsOf()`), `tests/Service/Backup/Support/BackupSchemaCoverageTest.php`, `tests/Support/BackupFieldDeclarations.php`
- Modify docs: `docs/backup.md` §6.3 "On the account" (remove the `preferences` row)

**Interfaces:**
- Produces: `User::getPasskeyOfferAnsweredAt(): ?\DateTimeImmutable`, `User::markPasskeyOfferAnswered(\DateTimeImmutable $at): void`.

- [ ] **Step 1: Failing test.** `PasskeyOfferTest` drops its `preferences()` helper and asserts against `$user->getPasskeyOfferAnsweredAt()` on `new User(…)`. All three cases stay: the first answer records now; a second answer does not move it; a non-UTC clock is normalised to naive UTC. Run it: FAIL.

- [ ] **Step 2: `User`.** Remove the `preferences` property, its docblock, the constructor line that creates it, and `getPreferences()`. Add, next to the other timestamps:

```php
/** Naive UTC. Null until the account answers the one-time passkey enrolment offer, which then never shows again. */
#[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
private ?\DateTimeImmutable $passkeyOfferAnsweredAt = null;
```

Add `getPasskeyOfferAnsweredAt()` and `markPasskeyOfferAnswered(\DateTimeImmutable $at): void`. The idempotence lives in `PasskeyOffer`, whose class docblock already says so; do not carry the old method docblock over. Run `vendor/bin/phpmd analyze src/Entity/User.php --no-progress`. Expected: no output (15 fields).

`PasskeyOffer::markAnswered()`:

```php
public function markAnswered(User $user): void
{
    if (null !== $user->getPasskeyOfferAnsweredAt()) {
        return;
    }

    $user->markPasskeyOfferAnswered($this->clock->now());
}
```

`MeJson::preferences()`: `'passkeyOfferAnswered' => null !== $user->getPasskeyOfferAnsweredAt()`. `AiProviderSettings` docblock: "Unlike the settings rows created with every account (ReadingSettings and its siblings), no row is created with the account: no row means "not configured"." Delete `Preferences.php`.

- [ ] **Step 3: Migration `Version20261111130000`.** Description: `'The passkey offer marker moves onto app_user; user_preferences is dropped (#2177).'` `skipIf(!$schema->hasTable('user_preferences'), 'Already applied.')`.

MySQL:
```sql
ALTER TABLE app_user ADD passkey_offer_answered_at DATETIME DEFAULT NULL;
UPDATE app_user SET passkey_offer_answered_at =
  (SELECT p.passkey_offer_answered_at FROM user_preferences p WHERE p.user_id = app_user.id);
DROP TABLE user_preferences;
```

SQLite: `ALTER TABLE app_user ADD COLUMN passkey_offer_answered_at DATETIME DEFAULT NULL`, then the same `UPDATE` and `DROP TABLE`. `app_user` carries no FTS triggers, so `ADD COLUMN` needs no rebuild.

`down()`: recreate `user_preferences` with `id`, `user_id`, the unique index `uniq_preferences_user` and FK `fk_preferences_user`, as in `Version20260802120000`, plus `passkey_offer_answered_at DATETIME DEFAULT NULL`. Then `INSERT INTO user_preferences (user_id, passkey_offer_answered_at) SELECT id, passkey_offer_answered_at FROM app_user`, and drop the column from `app_user` (MySQL `DROP`, SQLite `DROP COLUMN`). The downs of Tasks 1–3 then re-add their columns to this table.

- [ ] **Step 4: Backup declarations and docs.** `BackupFieldDeclarations`: remove `Preferences::class` from `BACKED_UP` and `KIND_OF`, and the import. `BackupSchemaCoverageTest`: remove `NOT_BACKED_UP[Preferences::class]` and `User`'s `'preferences'` entry, and move `'passkeyOfferAnsweredAt'` with its reason under `NOT_BACKED_UP[User::class]`. `docs/backup.md` §6.3: delete the `preferences` row. The `passkeyOfferAnsweredAt` row is already in the "On the account" table, which now matches.

- [ ] **Step 5: Leftovers.** These must return nothing:

```bash
cd backend && grep -rn "Preferences\b\|getPreferences\|PreferencesRepository" src tests config | grep -v "AccountPreferencesWriter\|ReaderPreferencesController\|UpdatePreferencesRequest\|BlueskySavedCollections\|GET_PREFERENCES\|updatePreferences"
grep -rn "user_preferences" src tests config
```

`AccountPreferencesWriter`, `ReaderPreferencesController`, `UpdatePreferencesRequest` and the Bluesky `getPreferences` XRPC call are API vocabulary and stay. The frontend needs no change; its `preferences` names are its own, and the API is unchanged.

- [ ] **Step 6: Run** `tests/Service/Passkey`, `tests/Controller/Api/PasskeyOfferControllerTest.php`, `tests/Controller/Api/PasskeyRegistrationTest.php`, `tests/Controller/Api/MeControllerTest.php`, `tests/Http`, `tests/Service/Backup` and `tests/Entity`, then both full legs. Expected: green; both pins unchanged.

- [ ] **Step 7: Verify the migration.** Recipe A with `SELECT id, passkey_offer_answered_at FROM app_user ORDER BY id`. Expected: `1|2026-11-01 12:00:00`, `2|`, `3|`. Also confirm `SELECT name FROM sqlite_master WHERE name = 'user_preferences'` is empty, and `schema:validate` reports OK. Recipes B and C. Recipe D with

```sql
SELECT u.id, p.passkey_offer_answered_at FROM app_user u LEFT JOIN user_preferences p ON p.user_id = u.id ORDER BY u.id   -- before
SELECT id, passkey_offer_answered_at FROM app_user ORDER BY id   -- after
```

Expected: `IDENTICAL`. `SHOW TABLES LIKE 'user_preferences'` is empty.

- [ ] **Step 8: Gates and commit.** `composer check`, `composer md` and PhpStorm lint, all clean. Then `git commit -m "refactor(#2177): passkey offer marker moves onto the account; Preferences removed"`.

---

### Task 5: Whole-branch verification

**Files:** none planned. A fix found here gets its own commit.

- [ ] **Step 1: Both PHPUnit legs, in parallel:**

```bash
cd backend && composer test:parallel
docker compose exec php composer test:parallel
```

Expected: both green.

- [ ] **Step 2: Mutation gate.** Infection reads only tracked files, so everything must be committed first. Then run `cd backend && composer infection:diff`. Expected: MSI at or above `minMsi` in `infection.json5`. Kill escaped mutants in the new entities, repositories, `UserSettingsRow`, the listener and `AccountSettings` with tests. Never lower `minMsi`. If the initial run dies on the 512M memory limit, rerun; it is a known flake.

- [ ] **Step 3: Static gates.** Run `cd backend && composer check && composer md`; both are clean. If CI's tramp gate differs, check `composer show larspohlmann/phptramp` first.

- [ ] **Step 4: PhpStorm inspections.** Run `mcp__phpstorm__lint_files` over every PHP file changed on the branch (`git diff --name-only develop...HEAD -- '*.php'`). Expected: no ERROR or WARNING.

- [ ] **Step 5: Migrations once more, end to end.** Recipe B and recipe C (from empty, `schema:validate` OK on both). On the dev DB, `docker compose exec php bin/console doctrine:migrations:status` shows nothing pending, and `docker compose exec php bin/console doctrine:schema:validate` is OK.

- [ ] **Step 6: Live check on the dev stack.** Run `docker compose exec php bin/console cache:clear` and `docker compose restart worker`. Then:

```bash
curl -sk https://localhost:8443/api/me -H "Authorization: Bearer <token of the dev account>" | jq .preferences
```

Compare it with the values taken before Task 1 (the `before.tsv` files). Alternatively, open the settings page on :4200 and check the reading, sharing and digest sections show the stored values. Change one setting in each section and reload to see it stick.

- [ ] **Step 7: Scan the dev log.**

```bash
ls -t backend/var/log/dev-*.log | head -1 | xargs tail -n 300 | jq -c 'select(.level_name != "DEBUG" and .level_name != "INFO")'
```

Expected: no new deprecations, errors or `Column not found`.

- [ ] **Step 8: `/simplify`** over the branch diff. Apply what it finds, then re-run the touched tests and the gates, and commit.

The PR is not part of this plan; the controller opens it.

---

## Self-review notes

- **Spec coverage:** the three tables (Tasks 1–3); `passkeyOfferAnsweredAt` on `User` (Task 4); one row per user created with the user (the listener, Task 1, extended in 2–3); the data copy on MySQL and SQLite with `schema:validate` (each task's recipes A–D); `/api/me` and PATCH byte-identical (the Task 1 pin plus the existing controller tests); the backup line unchanged (`AccountLineTest` pin, round trip in `AccountRestorerTest`, coverage test); `user_preferences` dropped (Task 4); `user_feed_directory_scope` excluded.
- **Deletion paths:** `AccountDeleter` removes the `User`, and each settings row follows through `ON DELETE CASCADE` (SQLite runs with `PRAGMA foreign_keys = ON`, `SqliteConnectionSetupDriver`). Previously, `orphanRemoval` on the inverse side did it in the ORM. `AccountWipeRepository` never touched preferences, and still leaves the settings rows alone; `AccountReset` resets only the scrape fallback, as today.
- **Names used across tasks:** `forUser`, `UserSettingsRow::required`, `AccountSettings::of`, `AccountSettingsModel::$reading/$sharing/$digest`, `StoredUserSettings::reading/sharing/digest`, `MeJson::profile/preferences`.
