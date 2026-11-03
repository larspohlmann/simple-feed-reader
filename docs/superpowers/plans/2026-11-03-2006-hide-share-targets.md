# Remove share targets and switch sharing off (#2006) — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Let a user switch the share feature off entirely, or remove single share targets from the share menu, per account.

**Architecture:** Two new columns on `user_preferences` (`sharing_enabled`, `hidden_share_targets`) are written by `PATCH /api/me/sharing`, read through `/api/me` and carried by the backup. In the SPA, `SharingService` caches them the way `MagazineStyleService` does. `ShareAvailability` decides whether the share button and the selection share render, and `shareMenuItems` drops hidden targets. A "Sharing" group under Settings › Preferences holds a master switch and one switch per target.

**Tech Stack:** Symfony 7.4 / PHP 8.4 / Doctrine (MySQL + SQLite), Angular 20 standalone + signals, Jest, Transloco.

**Spec:** [docs/superpowers/specs/2026-11-03-2006-hide-share-targets-design.md](../specs/2026-11-03-2006-hide-share-targets-design.md)

## Global Constraints

- Branch `feature/2006-hide-share-targets` off `develop` (already created; spec committed). Commit format `type(#2006): summary`. Spec and plan are already committed on the branch.
- Target id format `^[a-z0-9-]{1,32}$`, at most 64 hidden ids, duplicates dropped. Ids are never checked against `share-targets.json`.
- Sharing is on and nothing is hidden by default, for new accounts, existing rows and backups without the fields.
- Sharing is unavailable when it is off **or** every target the browser lists is hidden. Then: no share button (toolbar and corner), no selection bubble, no phone bar. "Post as @handle" entries go with them.
- Unknown hidden ids survive a write from the settings switches.
- PHP follows CLAUDE.md Clean Code rules (`final readonly`, no comments unless a reader would get the code wrong, thin controllers, Domain knows no HTTP, PHPMD-clean touched files).
- Frontend tests run inside Docker: `docker compose exec -T frontend npx jest <path>`. Gate: `docker compose exec -T frontend npm run check`.
- Backend gates: `composer check`, `composer md`, `php bin/phpunit`, `docker compose exec php composer test:parallel`, `composer infection:diff`. Migrations are verified by migrating from empty on SQLite and MySQL plus `doctrine:schema:validate` (Task 1).

---

### Task 1: Persist the sharing settings on `Preferences`

**Files:**
- Modify: `backend/src/Entity/Preferences.php`
- Create: `backend/migrations/Version20261103090000.php`
- Test: `backend/tests/Entity/PreferencesSharingTest.php`

**Interfaces:**
- Produces: `Preferences::TARGET_ID_PATTERN = '/^[a-z0-9-]{1,32}$/'`, `Preferences::MAX_HIDDEN_SHARE_TARGETS = 64`, `isSharingEnabled(): bool`, `setSharingEnabled(bool): void`, `getHiddenShareTargets(): list<string>`, `setHiddenShareTargets(list<string>): void`.

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\Preferences;
use App\Entity\User;
use PHPUnit\Framework\TestCase;

final class PreferencesSharingTest extends TestCase
{
    public function testSharingIsOnWithNothingHiddenByDefault(): void
    {
        $preferences = new Preferences($this->createStub(User::class));

        self::assertTrue($preferences->isSharingEnabled());
        self::assertSame([], $preferences->getHiddenShareTargets());
    }

    public function testItKeepsWhatItIsGiven(): void
    {
        $preferences = new Preferences($this->createStub(User::class));

        $preferences->setSharingEnabled(false);
        $preferences->setHiddenShareTargets(['facebook', 'x']);

        self::assertFalse($preferences->isSharingEnabled());
        self::assertSame(['facebook', 'x'], $preferences->getHiddenShareTargets());
    }
}
```

If `User` is final and cannot be stubbed, build it the way neighbouring entity tests in `backend/tests/Entity/` do (check one with `grep -l "new Preferences\|getPreferences" backend/tests/Entity/*.php`) and use `$user->getPreferences()`.

- [ ] **Step 2: Run it and watch it fail**

Run (from `backend/`): `php bin/phpunit tests/Entity/PreferencesSharingTest.php`
Expected: FAIL, `Call to undefined method App\Entity\Preferences::isSharingEnabled()`.

- [ ] **Step 3: Add the columns**

In `Preferences.php`, add the constants under the class opening brace and the two fields after `passkeyOfferAnsweredAt`:

```php
    public const string TARGET_ID_PATTERN = '/^[a-z0-9-]{1,32}$/';

    public const int MAX_HIDDEN_SHARE_TARGETS = 64;
```

```php
    #[ORM\Column(name: 'sharing_enabled', options: ['default' => true])]
    private bool $sharingEnabled = true;

    /** Nullable so the column could be added to existing rows; null reads as nothing hidden. */
    #[ORM\Column(name: 'hidden_share_targets', type: Types::JSON, nullable: true)]
    private ?array $hiddenShareTargets = null;
```

and the accessors at the end of the class:

```php
    public function isSharingEnabled(): bool
    {
        return $this->sharingEnabled;
    }

    public function setSharingEnabled(bool $sharingEnabled): void
    {
        $this->sharingEnabled = $sharingEnabled;
    }

    /** @return list<string> */
    public function getHiddenShareTargets(): array
    {
        return array_values(array_filter($this->hiddenShareTargets ?? [], \is_string(...)));
    }

    /** @param list<string> $hiddenShareTargets */
    public function setHiddenShareTargets(array $hiddenShareTargets): void
    {
        $this->hiddenShareTargets = $hiddenShareTargets;
    }
```

Type the property `/** @var list<string>|null */` if PHPStan asks for the value type.

- [ ] **Step 4: Write the migration**

`backend/migrations/Version20261103090000.php`. JSON columns are added nullable on both dialects, as `Version20261102120000` did for `quoted_link_card`:

```php
<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261103090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Records whether the account shares and which share targets it hides (#2006).';
    }

    public function up(Schema $schema): void
    {
        $this->skipIf(
            $schema->getTable('user_preferences')->hasColumn('sharing_enabled'),
            'user_preferences.sharing_enabled already exists.',
        );

        if ($this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform) {
            $this->addSql('ALTER TABLE user_preferences ADD sharing_enabled TINYINT(1) DEFAULT 1 NOT NULL,'
                . ' ADD hidden_share_targets JSON DEFAULT NULL');

            return;
        }

        $this->assertSqlite();
        $this->addSql('ALTER TABLE user_preferences ADD COLUMN sharing_enabled BOOLEAN DEFAULT 1 NOT NULL');
        $this->addSql('ALTER TABLE user_preferences ADD COLUMN hidden_share_targets CLOB DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->skipIf(
            !$schema->getTable('user_preferences')->hasColumn('sharing_enabled'),
            'user_preferences.sharing_enabled does not exist.',
        );

        if ($this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform) {
            $this->addSql('ALTER TABLE user_preferences DROP sharing_enabled, DROP hidden_share_targets');

            return;
        }

        $this->assertSqlite();
        $this->addSql('ALTER TABLE user_preferences DROP COLUMN sharing_enabled');
        $this->addSql('ALTER TABLE user_preferences DROP COLUMN hidden_share_targets');
    }

    private function assertSqlite(): void
    {
        $platform = $this->connection->getDatabasePlatform();
        $this->abortIf(
            !$platform instanceof SQLitePlatform,
            \sprintf('No DDL defined for platform %s; only MySQL and SQLite are supported.', $platform::class),
        );
    }
}
```

Compare the exact DDL with what Doctrine wants before relying on it: `bin/console doctrine:schema:update --dump-sql` on a database migrated to the previous version. If the boolean renders differently (e.g. `TINYINT(1) DEFAULT 1 NOT NULL` vs. `TINYINT DEFAULT 1 NOT NULL`), use Doctrine's spelling, the way `scrape_fallback_enabled`'s migration did (`grep -l scrape_fallback_enabled backend/migrations/*.php`).

- [ ] **Step 5: Run the test and verify the migration on both dialects**

Run: `php bin/phpunit tests/Entity/PreferencesSharingTest.php` → PASS.

SQLite (from `backend/`):
```bash
rm -f var/migrate-check.db && DATABASE_URL="sqlite:///%kernel.project_dir%/var/migrate-check.db" bin/console doctrine:migrations:migrate --no-interaction && DATABASE_URL="sqlite:///%kernel.project_dir%/var/migrate-check.db" bin/console doctrine:schema:validate
```
MySQL:
```bash
docker compose exec php bin/console doctrine:migrations:migrate --no-interaction
docker compose exec php bin/console doctrine:schema:validate
```
Expected: both report "The database schema is in sync with the mapping files." Then run `down` once on SQLite (`doctrine:migrations:migrate prev`) and migrate up again to prove the down path.

- [ ] **Step 6: Commit**

```bash
git add backend/src/Entity/Preferences.php backend/migrations/Version20261103090000.php backend/tests/Entity/PreferencesSharingTest.php
git commit -m "feat(#2006): store sharing on/off and hidden share targets per account"
```

---

### Task 2: `PATCH /api/me/sharing` and the profile field

**Files:**
- Create: `backend/src/Service/Account/Model/SharingSettingsModel.php`
- Create: `backend/src/Dto/Me/UpdateSharingRequest.php`
- Modify: `backend/src/Service/Account/AccountPreferencesWriter.php`
- Modify: `backend/src/Controller/Api/MeController.php`
- Modify: `backend/src/Http/MeJson.php`
- Create: `backend/tests/Controller/Api/SharingControllerTest.php`
- Modify: `backend/tests/Controller/Api/MeControllerTest.php` (the two exact `preferences` arrays, ~lines 145–160 and 222–237)

**Interfaces:**
- Consumes: Task 1's `Preferences` accessors and constants.
- Produces: `SharingSettingsModel(bool $enabled, list<string> $hiddenTargets)`; `AccountPreferencesWriter::changeSharing(User, SharingSettingsModel): void`; wire shape `preferences.sharing = { "enabled": bool, "hiddenTargets": string[] }` on every `/api/me` response; request `PATCH /api/me/sharing` `{ "enabled": bool, "hiddenTargets": string[] }` → 200 with the profile, 422 on a missing field, a bad id or more than 64 ids.

- [ ] **Step 1: Write the failing controller test**

```php
<?php

declare(strict_types=1);

namespace App\Tests\Controller\Api;

use App\Entity\Preferences;
use App\Tests\Support\ApiTestCase;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

final class SharingControllerTest extends ApiTestCase
{
    /** @param array<string, mixed> $body */
    private function patchSharing(KernelBrowser $client, array $body): void
    {
        $client->request(
            'PATCH',
            '/api/me/sharing',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode($body, \JSON_THROW_ON_ERROR),
        );
    }

    private function sharing(KernelBrowser $client): mixed
    {
        $preferences = $this->payload($client)['preferences'];
        self::assertIsArray($preferences);

        return $preferences['sharing'];
    }

    private function storedPreferences(string $email): Preferences
    {
        $this->entityManager()->clear();
        $user = $this->users()->findOneBy(['email' => $email]);
        self::assertNotNull($user);

        return $user->getPreferences();
    }

    public function testANewAccountSharesEverything(): void
    {
        $client = static::createClient();
        $this->signIn($client, 'shares-by-default@example.test');

        $client->request('GET', '/api/me');

        self::assertResponseIsSuccessful();
        self::assertSame(['enabled' => true, 'hiddenTargets' => []], $this->sharing($client));
    }

    public function testChangingSharingPersistsIt(): void
    {
        $client = static::createClient();
        $this->signIn($client, 'quiet-sharer@example.test');

        $this->patchSharing($client, ['enabled' => false, 'hiddenTargets' => ['facebook', 'x']]);

        self::assertResponseIsSuccessful();
        self::assertSame(['enabled' => false, 'hiddenTargets' => ['facebook', 'x']], $this->sharing($client));
        $stored = $this->storedPreferences('quiet-sharer@example.test');
        self::assertFalse($stored->isSharingEnabled());
        self::assertSame(['facebook', 'x'], $stored->getHiddenShareTargets());
    }

    public function testATargetListedTwiceIsStoredOnce(): void
    {
        $client = static::createClient();
        $this->signIn($client, 'twice-hidden@example.test');

        $this->patchSharing($client, ['enabled' => true, 'hiddenTargets' => ['x', 'reddit', 'x']]);

        self::assertResponseIsSuccessful();
        self::assertSame(['x', 'reddit'], $this->storedPreferences('twice-hidden@example.test')->getHiddenShareTargets());
    }

    public function testAnUnknownButWellFormedIdIsKept(): void
    {
        $client = static::createClient();
        $this->signIn($client, 'future-target@example.test');

        $this->patchSharing($client, ['enabled' => true, 'hiddenTargets' => ['mastodon-app']]);

        self::assertResponseIsSuccessful();
        self::assertSame(['mastodon-app'], $this->storedPreferences('future-target@example.test')->getHiddenShareTargets());
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function invalidBodies(): iterable
    {
        yield 'empty body' => [[]];
        yield 'no list' => [['enabled' => false]];
        yield 'no flag' => [['hiddenTargets' => []]];
        yield 'upper case id' => [['enabled' => true, 'hiddenTargets' => ['Facebook']]];
        yield 'id with a space' => [['enabled' => true, 'hiddenTargets' => ['face book']]];
        yield 'empty id' => [['enabled' => true, 'hiddenTargets' => ['']]];
        yield 'id over 32 characters' => [['enabled' => true, 'hiddenTargets' => [str_repeat('a', 33)]]];
        yield 'non-string id' => [['enabled' => true, 'hiddenTargets' => [['x']]]];
        yield 'too many ids' => [[
            'enabled' => true,
            'hiddenTargets' => array_map(
                static fn (int $index): string => 'target-' . $index,
                range(1, Preferences::MAX_HIDDEN_SHARE_TARGETS + 1),
            ),
        ]];
    }

    /**
     * @param array<string, mixed> $body
     *
     * @dataProvider invalidBodies
     */
    public function testAnInvalidBodyIsRejectedAndChangesNothing(array $body): void
    {
        $client = static::createClient();
        $this->signIn($client, 'rejected-sharing@example.test');

        $this->patchSharing($client, $body);

        self::assertResponseStatusCodeSame(422);
        $stored = $this->storedPreferences('rejected-sharing@example.test');
        self::assertTrue($stored->isSharingEnabled());
        self::assertSame([], $stored->getHiddenShareTargets());
    }

    public function testExactlyTheMaximumIsAccepted(): void
    {
        $client = static::createClient();
        $this->signIn($client, 'many-hidden@example.test');
        $ids = array_map(
            static fn (int $index): string => 'target-' . $index,
            range(1, Preferences::MAX_HIDDEN_SHARE_TARGETS),
        );

        $this->patchSharing($client, ['enabled' => true, 'hiddenTargets' => $ids]);

        self::assertResponseIsSuccessful();
    }

    public function testASignedOutClientIsRejected(): void
    {
        $client = static::createClient();

        $this->patchSharing($client, ['enabled' => false, 'hiddenTargets' => []]);

        self::assertResponseStatusCodeSame(401);
    }
}
```

Use whichever data-provider style the suite already uses (`#[DataProvider('invalidBodies')]` attribute if `grep -rn "DataProvider(" backend/tests | head -1` finds it; the docblock form otherwise).

- [ ] **Step 2: Run it and watch it fail**

Run: `php bin/phpunit tests/Controller/Api/SharingControllerTest.php`
Expected: FAIL. The PATCH answers 404/405 and `preferences.sharing` is undefined.

- [ ] **Step 3: Add the model**

`backend/src/Service/Account/Model/SharingSettingsModel.php`:

```php
<?php

declare(strict_types=1);

namespace App\Service\Account\Model;

final readonly class SharingSettingsModel
{
    /** @param list<string> $hiddenTargets */
    public function __construct(
        public bool $enabled,
        public array $hiddenTargets,
    ) {
    }
}
```

If `Service/Account` has no `Model/` folder yet, this creates it. `ServiceRoleRule` accepts a data class there.

- [ ] **Step 4: Add the request DTO**

`backend/src/Dto/Me/UpdateSharingRequest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Dto\Me;

use App\Entity\Preferences;
use App\Service\Account\Model\SharingSettingsModel;
use Symfony\Component\Validator\Constraints as Assert;

/** No defaults: an empty body must never switch sharing off or unhide every target. */
final readonly class UpdateSharingRequest
{
    /** @param list<string> $hiddenTargets */
    public function __construct(
        public bool $enabled,
        #[Assert\Count(max: Preferences::MAX_HIDDEN_SHARE_TARGETS)]
        #[Assert\All([new Assert\Type('string'), new Assert\Regex(Preferences::TARGET_ID_PATTERN)])]
        public array $hiddenTargets,
    ) {
    }

    public function toSettings(): SharingSettingsModel
    {
        return new SharingSettingsModel($this->enabled, array_values(array_unique($this->hiddenTargets)));
    }
}
```

A non-string element fails `Type` first. If `Regex` throws `UnexpectedValueException` on the array element in the "non-string id" case instead of adding a violation, wrap the regex in `new Assert\Sequentially([new Assert\Type('string'), new Assert\Regex(...)])` inside `All`.

- [ ] **Step 5: Writer, controller, profile**

`AccountPreferencesWriter` gets:

```php
    public function changeSharing(User $user, SharingSettingsModel $sharing): void
    {
        $user->getPreferences()->setSharingEnabled($sharing->enabled);
        $user->getPreferences()->setHiddenShareTargets($sharing->hiddenTargets);
        $this->entityManager->flush();
    }
```

`MeController` gets, after `updateMagazineStyle`:

```php
    /** Its own PATCH for the reason updatePreferences() gives. */
    #[Route('/api/me/sharing', name: 'api_me_update_sharing', methods: ['PATCH'])]
    public function updateSharing(
        #[CurrentUser] User $user,
        #[MapRequestPayload] UpdateSharingRequest $request,
    ): JsonResponse {
        $this->preferences->changeSharing($user, $request->toSettings());

        return new JsonResponse($this->profile->of($user));
    }
```

`MeJson::profile`, inside `'preferences'` after `'passkeyOfferAnswered'`:

```php
                'sharing' => [
                    'enabled' => $preferences->isSharingEnabled(),
                    'hiddenTargets' => $preferences->getHiddenShareTargets(),
                ],
```

- [ ] **Step 6: Update the exact profile assertions**

In `MeControllerTest`, both `assertSame([...], $this->payload($client)['preferences'])` arrays gain, after `'passkeyOfferAnswered' => false,`:

```php
                'sharing' => ['enabled' => true, 'hiddenTargets' => []],
```

- [ ] **Step 7: Run tests and gates**

Run: `php bin/phpunit tests/Controller/Api/SharingControllerTest.php tests/Controller/Api/MeControllerTest.php tests/Controller/Api/MagazineStyleControllerTest.php tests/Http`
Expected: PASS.
Run: `composer check && composer md`. Expected: clean.

- [ ] **Step 8: Commit**

```bash
git add backend/src/Service/Account backend/src/Dto/Me/UpdateSharingRequest.php backend/src/Controller/Api/MeController.php backend/src/Http/MeJson.php backend/tests/Controller/Api/SharingControllerTest.php backend/tests/Controller/Api/MeControllerTest.php
git commit -m "feat(#2006): PATCH /api/me/sharing and the sharing block on the profile"
```

---

### Task 3: Carry sharing in the backup

**Files:**
- Modify: `backend/src/Service/Backup/BackupLines.php` (`accountLine`)
- Modify: `backend/src/Service/Backup/Dto/AccountLine.php`
- Modify: `backend/src/Service/Backup/Pass/RestoreLoadPass.php` (`loadAccount`)
- Modify: `backend/tests/Support/BackupFieldDeclarations.php`, `backend/tests/Support/FullyPopulatedAccount.php`
- Modify: `backend/tests/Service/Backup/Dto/AccountLineTest.php`
- Modify: `docs/backup.md` (the account-line field list)

**Interfaces:**
- Consumes: Task 1's accessors and `Preferences::TARGET_ID_PATTERN`.
- Produces: account line keys `sharingEnabled` (bool) and `hiddenShareTargets` (string list); `AccountLine::$sharingEnabled`, `AccountLine::$hiddenShareTargets`.

- [ ] **Step 1: Write the failing tests**

Append to `AccountLineTest`:

```php
    public function testAnOlderBackupSharesEverything(): void
    {
        $line = AccountLine::fromLine(['locale' => 'de', 'scrapeFallbackEnabled' => true]);

        self::assertTrue($line->sharingEnabled);
        self::assertSame([], $line->hiddenShareTargets);
    }

    public function testItReadsSharing(): void
    {
        $line = AccountLine::fromLine([
            'locale' => 'de',
            'scrapeFallbackEnabled' => true,
            'sharingEnabled' => false,
            'hiddenShareTargets' => ['facebook', 'x'],
        ]);

        self::assertFalse($line->sharingEnabled);
        self::assertSame(['facebook', 'x'], $line->hiddenShareTargets);
    }

    public function testMalformedAndRepeatedTargetIdsAreDroppedRatherThanRefused(): void
    {
        $line = AccountLine::fromLine([
            'locale' => 'de',
            'scrapeFallbackEnabled' => true,
            'hiddenShareTargets' => ['x', 'Not An Id', 'x', '', 'reddit'],
        ]);

        self::assertSame(['x', 'reddit'], $line->hiddenShareTargets);
    }
```

In `BackupFieldDeclarations::BACKED_UP[Preferences::class]` add:

```php
            'sharingEnabled' => 'sharingEnabled',
            'hiddenShareTargets' => 'hiddenShareTargets',
```

In `FullyPopulatedAccount::create`, after the magazine style:

```php
        $user->getPreferences()->setSharingEnabled(false);
        $user->getPreferences()->setHiddenShareTargets(['facebook', 'x']);
```

- [ ] **Step 2: Run them and watch them fail**

Run: `php bin/phpunit tests/Service/Backup`
Expected: FAIL. `AccountLine` has no `sharingEnabled`, and the coverage and round-trip tests (`BackupSchemaCoverageTest`, `AccountRestorerTest`) report the two Preferences fields as not exported or not restored.

- [ ] **Step 3: Export, read, restore**

`BackupLines::accountLine`, after `magazineStyle`:

```php
            'sharingEnabled' => $user->getPreferences()->isSharingEnabled(),
            'hiddenShareTargets' => $user->getPreferences()->getHiddenShareTargets(),
```

`AccountLine`: two constructor properties after `$magazineStyle` (`public bool $sharingEnabled,` and `public array $hiddenShareTargets,` with `@param list<string> $hiddenShareTargets` in the constructor docblock); in `fromLine`:

```php
            sharingEnabled: LineFieldWithDefault::bool($line, 'sharingEnabled', true),
            hiddenShareTargets: self::shareTargetIds($line),
```

and the helper:

```php
    /**
     * @param array<string, mixed> $line
     *
     * @return list<string>
     */
    private static function shareTargetIds(array $line): array
    {
        return array_values(array_unique(array_filter(
            LineFieldWithDefault::stringList($line, 'hiddenShareTargets'),
            static fn (string $id): bool => preg_match(Preferences::TARGET_ID_PATTERN, $id) === 1,
        )));
    }
```

Import `App\Entity\Preferences`. Check `LineFieldWithDefault::stringList` returns `[]` for a missing key (it does for the network lists) and how it treats a non-string element; keep that behaviour.

`RestoreLoadPass::loadAccount`, after the magazine style:

```php
        $this->user->getPreferences()->setSharingEnabled($line->sharingEnabled);
        $this->user->getPreferences()->setHiddenShareTargets($line->hiddenShareTargets);
```

Add the two keys to the account-line table in `docs/backup.md`, worded like its neighbours.

- [ ] **Step 4: Run tests and gates**

Run: `php bin/phpunit tests/Service/Backup tests/Support`
Expected: PASS.
Run: `composer check && composer md`. Expected: clean (`AccountLine` must stay PHPMD-clean; if its constructor now trips the parameter count, the network pair or the sharing pair moves into its own small value in `Service/Backup/Model/` the way `AccountNetworkSwitchesModel` did).

- [ ] **Step 5: Commit**

```bash
git add backend/src/Service/Backup backend/tests/Support backend/tests/Service/Backup docs/backup.md
git commit -m "feat(#2006): back up and restore the sharing settings"
```

---

### Task 4: Stable target ids, hidden targets and divider collapse in the menu

**Files:**
- Modify: `frontend/public/share-targets/share-targets.json`
- Modify: `frontend/src/app/reader/article/share/share-targets.ts`
- Modify: `frontend/src/app/reader/article/share/share-menu.ts`
- Test: `frontend/src/app/reader/article/share/share-targets.spec.ts`, `share-menu.spec.ts`

**Interfaces:**
- Produces: `ShareEntry.id: string` on every non-divider target; `TARGET_ID_PATTERN = /^[a-z0-9-]{1,32}$/`; `shareMenuItems(networks, mode, hidden: ReadonlySet<string> = new Set())`; a target item's `id` is its target's `id`; divider ids are `divider-<index>`.

- [ ] **Step 1: Write the failing tests**

`share-targets.spec.ts`. Change the `carriesText({ kind: 'copy' })` literal to `carriesText({ kind: 'copy', id: 'copy' })`, import `TARGET_ID_PATTERN`, and add:

```ts
  it('names every target with a unique, well-formed id', () => {
    const ids = SHARE_TARGETS.flatMap((target) => (target.kind === 'divider' ? [] : [target.id]));

    expect(new Set(ids).size).toBe(ids.length);
    expect(ids.filter((id) => !TARGET_ID_PATTERN.test(id))).toEqual([]);
  });
```

`share-menu.spec.ts`, add:

```ts
  const targetIds = (items: ShareItem[]) =>
    items.flatMap((item) => (item.kind === 'target' ? [item.id] : []));

  it('keys the targets by their id, not their position', () => {
    expect(targetIds(shareMenuItems([], 'link'))).toContain('x');
  });

  it('leaves the hidden targets out', () => {
    const ids = targetIds(shareMenuItems(NETWORKS, 'link', new Set(['x', 'facebook'])));

    expect(ids).not.toContain('x');
    expect(ids).not.toContain('facebook');
    expect(ids).toContain('reddit');
  });

  it('never starts, ends or doubles up on a divider', () => {
    const hidden = new Set(['bluesky', 'threads', 'x', 'facebook', 'linkedin', 'reddit', 'instapaper']);
    const items = shareMenuItems([], 'link', hidden);
    const shape = items.map((item) => item.kind);

    expect(shape[0]).not.toBe('divider');
    expect(shape.at(-1)).not.toBe('divider');
    expect(shape.join(' ')).not.toContain('divider divider');
    expect(targetIds(items)).toEqual(expect.arrayContaining(['copy', 'email', 'whatsapp', 'telegram']));
  });

  it('drops the divider after the posts when every target is hidden', () => {
    const all = new Set(
      shareMenuItems([], 'link').flatMap((item) => (item.kind === 'target' ? [item.id] : [])),
    );

    expect(shareMenuItems(NETWORKS, 'link', all).map((item) => item.kind)).toEqual(['post', 'post']);
  });
```

`'system'` is filtered out under jsdom (no `navigator.share`), so these tests do not list it.

- [ ] **Step 2: Run them and watch them fail**

Run: `docker compose exec -T frontend npx jest src/app/reader/article/share`
Expected: FAIL. `target.id` is undefined and `shareMenuItems` ignores a third argument.

- [ ] **Step 3: Give every target an id**

In `share-targets.json`, add `"id"` as the first key of each non-divider entry, in order: `system`, `copy`, `email`, `bluesky`, `threads`, `x`, `facebook`, `linkedin`, `reddit`, `whatsapp`, `telegram`, `instapaper`.

In `share-targets.ts`:

```ts
interface ShareEntry {
  /** Stored per account to hide the target, so it never changes once shipped. */
  readonly id: string;
  readonly label?: string;
  // …unchanged
}

/** The format the account stores hidden target ids in; `Preferences::TARGET_ID_PATTERN` on the server. */
export const TARGET_ID_PATTERN = /^[a-z0-9-]{1,32}$/;
```

- [ ] **Step 4: Filter and collapse in `share-menu.ts`**

```ts
export function shareMenuItems(
  networks: readonly PostNetwork[],
  mode: ShareMenuMode,
  hidden: ReadonlySet<string> = new Set(),
): ShareItem[] {
  const posts = networks.map((network) => postItem(network, mode));
  const hint: ShareItem[] =
    mode === 'hinted' && posts.length > 0 ? [{ kind: 'hint', id: 'quote-hint' }] : [];
  const divider: ShareItem[] = posts.length === 0 ? [] : [{ kind: 'divider', id: 'post-divider' }];
  const targets = SHARE_TARGETS.filter((target) => target.kind === 'divider' || !hidden.has(target.id)).map(
    (target, index) => configuredItem(target, index, mode),
  );
  return withoutStrayDividers([...posts, ...hint, ...divider, ...targets]);
}

function withoutStrayDividers(items: readonly ShareItem[]): ShareItem[] {
  const kept: ShareItem[] = [];
  for (const item of items) {
    const previous = kept.at(-1);
    if (item.kind === 'divider' && (previous === undefined || previous.kind === 'divider')) continue;
    kept.push(item);
  }
  return kept.at(-1)?.kind === 'divider' ? kept.slice(0, -1) : kept;
}
```

In `configuredItem`, use `const id = target.kind === 'divider' ? \`divider-${index}\` : target.id;`. The divider arm returns `{ kind: 'divider', id }` and both target arms use `target.id`.

- [ ] **Step 5: Run the tests**

Run: `docker compose exec -T frontend npx jest src/app/reader/article/share`
Expected: PASS, including the existing `share-button.component.spec.ts` (the sheet answers by item id; ids are still unique).

- [ ] **Step 6: Commit**

```bash
git add frontend/public/share-targets/share-targets.json frontend/src/app/reader/article/share
git commit -m "feat(#2006): stable share target ids; the menu drops hidden targets and stray dividers"
```

---

### Task 5: `SharingService`: account record with a paint cache

**Files:**
- Create: `frontend/src/app/core/preferences/sharing.ts`
- Create: `frontend/src/app/core/preferences/sharing-writer.ts`
- Create: `frontend/src/app/core/preferences/http-sharing-writer.ts`
- Create: `frontend/src/app/core/preferences/sharing.service.ts`
- Test: `frontend/src/app/core/preferences/sharing.service.spec.ts`
- Modify: `frontend/src/app/core/auth/auth.service.ts` (`UserPreferences`, `loadMe`, `logout`)
- Modify: `frontend/src/app/app.config.ts` (provide the HTTP writer)
- Modify: every spec or fixture that builds a full `UserPreferences` literal (find them with `grep -rln "passkeyOfferAnswered" frontend/src frontend/testing`)

**Interfaces:**
- Produces: `SharingSettings { enabled: boolean; hiddenTargets: readonly string[] }`; `SHARING_KEY = 'sfr.sharing'`; `SHARING_WRITER` token, `SharingWriter.write(settings): Observable<boolean>`; `SharingService` with `enabled: Signal<boolean>`, `hidden: Signal<ReadonlySet<string>>`, `saveFailed: WritableSignal<boolean>`, `setEnabled(enabled: boolean)`, `setTargetShown(id: string, shown: boolean)`, `adopt(user: CurrentUser)`, `reset()`; `UserPreferences.sharing: SharingSettings`.

- [ ] **Step 1: Write the failing spec**

```ts
import { TestBed } from '@angular/core/testing';
import { Observable, of } from 'rxjs';
import { CurrentUser } from '../auth/auth.service';
import { SHARING_KEY, SharingSettings } from './sharing';
import { SharingService } from './sharing.service';
import { SHARING_WRITER } from './sharing-writer';

function userWith(sharing: SharingSettings): CurrentUser {
  return { preferences: { sharing } } as CurrentUser;
}

describe('SharingService', () => {
  let written: SharingSettings[];
  let result: Observable<boolean>;

  function make(): SharingService {
    TestBed.configureTestingModule({
      providers: [
        {
          provide: SHARING_WRITER,
          useValue: {
            write: (settings: SharingSettings) => {
              written.push(settings);
              return result;
            },
          },
        },
      ],
    });
    return TestBed.inject(SharingService);
  }

  beforeEach(() => {
    localStorage.clear();
    written = [];
    result = of(true);
    TestBed.resetTestingModule();
  });

  it('shares everything when nothing is cached', () => {
    const service = make();

    expect(service.enabled()).toBe(true);
    expect([...service.hidden()]).toEqual([]);
  });

  it('starts from the cache, so the first frame is never wrong', () => {
    localStorage.setItem(SHARING_KEY, JSON.stringify({ enabled: false, hiddenTargets: ['x'] }));
    const service = make();

    expect(service.enabled()).toBe(false);
    expect([...service.hidden()]).toEqual(['x']);
  });

  it.each(['not json', '{"enabled":"yes","hiddenTargets":[]}', '{"enabled":true,"hiddenTargets":[1]}'])(
    'ignores a cached value it cannot read: %s',
    (cached) => {
      localStorage.setItem(SHARING_KEY, cached);

      expect(make().enabled()).toBe(true);
    },
  );

  it('switches sharing off locally and writes the whole settings through', () => {
    localStorage.setItem(SHARING_KEY, JSON.stringify({ enabled: true, hiddenTargets: ['x'] }));
    const service = make();

    service.setEnabled(false);

    expect(service.enabled()).toBe(false);
    expect(written).toEqual([{ enabled: false, hiddenTargets: ['x'] }]);
    expect(JSON.parse(localStorage.getItem(SHARING_KEY)!)).toEqual({ enabled: false, hiddenTargets: ['x'] });
  });

  it('hides and shows one target and keeps ids it does not know', () => {
    localStorage.setItem(SHARING_KEY, JSON.stringify({ enabled: true, hiddenTargets: ['retired-app'] }));
    const service = make();

    service.setTargetShown('x', false);
    service.setTargetShown('x', false);
    service.setTargetShown('retired-app', true);
    service.setTargetShown('reddit', false);

    expect(written.at(-1)).toEqual({ enabled: true, hiddenTargets: ['x', 'reddit'] });
    expect(written[0]).toEqual({ enabled: true, hiddenTargets: ['retired-app', 'x'] });
  });

  it('reports a failed account write without reverting, and clears it on the next', () => {
    const service = make();
    result = of(false);
    service.setEnabled(false);

    expect(service.enabled()).toBe(false);
    expect(service.saveFailed()).toBe(true);

    result = of(true);
    service.setEnabled(true);
    expect(service.saveFailed()).toBe(false);
  });

  it('adopts the account without writing it back', () => {
    const service = make();

    service.adopt(userWith({ enabled: false, hiddenTargets: ['facebook'] }));

    expect(service.enabled()).toBe(false);
    expect([...service.hidden()]).toEqual(['facebook']);
    expect(written).toEqual([]);
  });

  it('resets to sharing everything and forgets the cache', () => {
    const service = make();
    service.setEnabled(false);

    service.reset();

    expect(service.enabled()).toBe(true);
    expect(localStorage.getItem(SHARING_KEY)).toBeNull();
  });
});
```

- [ ] **Step 2: Run it and watch it fail**

Run: `docker compose exec -T frontend npx jest src/app/core/preferences/sharing.service.spec.ts`
Expected: FAIL, cannot find module `./sharing`.

- [ ] **Step 3: Implement**

`sharing.ts`:

```ts
export interface SharingSettings {
  readonly enabled: boolean;
  readonly hiddenTargets: readonly string[];
}

export const SHARING_EVERYTHING: SharingSettings = { enabled: true, hiddenTargets: [] };

/** The per-device paint cache, not the record: the first article frame after a cold start must not flash a share button. */
export const SHARING_KEY = 'sfr.sharing';

export function asSharingSettings(value: unknown): SharingSettings | null {
  if (typeof value !== 'object' || value === null) return null;
  const { enabled, hiddenTargets } = value as Record<string, unknown>;
  if (typeof enabled !== 'boolean' || !Array.isArray(hiddenTargets)) return null;
  if (!hiddenTargets.every((id) => typeof id === 'string')) return null;
  return { enabled, hiddenTargets: hiddenTargets as string[] };
}
```

`sharing-writer.ts`:

```ts
import { InjectionToken } from '@angular/core';
import { Observable, of } from 'rxjs';
import { SharingSettings } from './sharing';

/** Behind a token for the reason `MAGAZINE_STYLE_WRITER` is. */
export interface SharingWriter {
  /** Resolves `true` on success, `false` on failure. Never errors. */
  write(settings: SharingSettings): Observable<boolean>;
}

export const SHARING_WRITER = new InjectionToken<SharingWriter>('SHARING_WRITER', {
  providedIn: 'root',
  factory: (): SharingWriter => ({ write: () => of(true) }),
});
```

`http-sharing-writer.ts`:

```ts
import { HttpClient } from '@angular/common/http';
import { Injectable, inject } from '@angular/core';
import { Observable, catchError, map, of } from 'rxjs';
import { API_BASE_URL } from '../api';
import { SharingSettings } from './sharing';
import { SharingWriter } from './sharing-writer';

/** Goes straight at `HttpClient` for the reason `HttpMagazineStyleWriter` does. */
@Injectable({ providedIn: 'root' })
export class HttpSharingWriter implements SharingWriter {
  private readonly http = inject(HttpClient);
  private readonly base = inject(API_BASE_URL);

  write(settings: SharingSettings): Observable<boolean> {
    return this.http.patch(`${this.base}/api/me/sharing`, settings).pipe(
      map(() => true),
      catchError(() => of(false)),
    );
  }
}
```

`sharing.service.ts`:

```ts
import { Injectable, computed, inject, signal } from '@angular/core';
import { CurrentUser } from '../auth/auth.service';
import { SHARING_EVERYTHING, SHARING_KEY, SharingSettings, asSharingSettings } from './sharing';
import { SHARING_WRITER } from './sharing-writer';

/** The account is the record; `localStorage` is a paint cache, as for `MagazineStyleService`. */
@Injectable({ providedIn: 'root' })
export class SharingService {
  private readonly writer = inject(SHARING_WRITER);
  private readonly settings = signal<SharingSettings>(this.cached());

  readonly enabled = computed(() => this.settings().enabled);
  readonly hidden = computed((): ReadonlySet<string> => new Set(this.settings().hiddenTargets));
  readonly saveFailed = signal(false);

  setEnabled(enabled: boolean): void {
    this.save({ ...this.settings(), enabled });
  }

  /** Keeps ids this device does not list, so another device's hidden targets survive. */
  setTargetShown(id: string, shown: boolean): void {
    const others = this.settings().hiddenTargets.filter((hiddenId) => hiddenId !== id);
    this.save({ ...this.settings(), hiddenTargets: shown ? others : [...others, id] });
  }

  /** Caches only: a value that just arrived from the server is never written straight back. */
  adopt(user: CurrentUser): void {
    const settings = asSharingSettings(user.preferences.sharing);
    if (settings !== null) this.cache(settings);
  }

  reset(): void {
    localStorage.removeItem(SHARING_KEY);
    this.settings.set(SHARING_EVERYTHING);
    this.saveFailed.set(false);
  }

  private save(settings: SharingSettings): void {
    this.cache(settings);
    this.saveFailed.set(false);
    this.writer.write(settings).subscribe((ok) => {
      if (!ok) this.saveFailed.set(true);
    });
  }

  private cache(settings: SharingSettings): void {
    localStorage.setItem(SHARING_KEY, JSON.stringify(settings));
    this.settings.set(settings);
  }

  private cached(): SharingSettings {
    try {
      return asSharingSettings(JSON.parse(localStorage.getItem(SHARING_KEY) ?? 'null')) ?? SHARING_EVERYTHING;
    } catch {
      return SHARING_EVERYTHING;
    }
  }
}
```

- [ ] **Step 4: Wire it into the account lifecycle**

`auth.service.ts`: import `SharingService` and `SharingSettings`; add `sharing: SharingSettings;` to `UserPreferences`; inject `private readonly sharing = inject(SharingService);`; call `this.sharing.adopt(user);` after `this.magazineStyle.adopt(user);` in `loadMe` and `this.sharing.reset();` after `this.magazineStyle.reset();` in `logout`. Extend `auth.service.spec.ts` the way it covers the magazine style (grep `magazineStyle` there): one test that `loadMe` adopts `preferences.sharing`, one that `logout` resets it.

`app.config.ts`: next to line 72, `{ provide: SHARING_WRITER, useExisting: HttpSharingWriter },` with the imports.

Fix every full `UserPreferences` literal the type check now rejects by adding `sharing: { enabled: true, hiddenTargets: [] }`.

- [ ] **Step 5: Run the tests and the type check**

Run: `docker compose exec -T frontend npx jest src/app/core`
Expected: PASS.
Run: `docker compose exec -T frontend npm run check`
Expected: clean.

- [ ] **Step 6: Commit**

```bash
git add frontend/src/app/core frontend/src/app/app.config.ts frontend/src frontend/testing
git commit -m "feat(#2006): SharingService keeps the account's sharing settings with a paint cache"
```

---

### Task 6: Hide sharing in the article when it is unavailable

**Files:**
- Create: `frontend/src/app/reader/article/share/share-availability.ts`
- Test: `frontend/src/app/reader/article/share/share-availability.spec.ts`
- Modify: `frontend/src/app/reader/article/share/share-button.component.ts` (pass `hidden` to `shareMenuItems`)
- Modify: `frontend/src/app/reader/article/article-page/article-page.component.ts` and `.html`
- Test: `frontend/src/app/reader/article/article-page/article-page.component.spec.ts`, `share-button.component.spec.ts`

**Interfaces:**
- Consumes: `SharingService.enabled`, `SharingService.hidden` (Task 5); `SHARE_TARGETS`, target `id` and the `hidden` parameter (Task 4).
- Produces: `ShareAvailability.available: Signal<boolean>`.

- [ ] **Step 1: Write the failing specs**

`share-availability.spec.ts`:

```ts
import { TestBed } from '@angular/core/testing';
import { SharingService } from '../../../core/preferences/sharing.service';
import { ShareAvailability } from './share-availability';
import { SHARE_TARGETS } from './share-targets';

describe('ShareAvailability', () => {
  beforeEach(() => {
    localStorage.clear();
    TestBed.resetTestingModule();
  });

  const allIds = (): string[] =>
    SHARE_TARGETS.flatMap((target) => (target.kind === 'divider' ? [] : [target.id]));

  it('is available while sharing is on and a target is left', () => {
    TestBed.inject(SharingService).setTargetShown('x', false);

    expect(TestBed.inject(ShareAvailability).available()).toBe(true);
  });

  it('is unavailable with sharing switched off', () => {
    TestBed.inject(SharingService).setEnabled(false);

    expect(TestBed.inject(ShareAvailability).available()).toBe(false);
  });

  it('is unavailable with every listed target hidden', () => {
    const sharing = TestBed.inject(SharingService);
    allIds().forEach((id) => sharing.setTargetShown(id, false));

    expect(TestBed.inject(ShareAvailability).available()).toBe(false);
  });
});
```

`article-page.component.spec.ts`: add `import { SharingService } from '../../../core/preferences/sharing.service';`, add `localStorage.clear();` to the `beforeEach`, and:

```ts
  it('offers no sharing at all while sharing is switched off', () => {
    TestBed.inject(SharingService).setEnabled(false);

    const element = mount();

    expect(element.querySelector('app-share-button')).toBeNull();
    expect(element.querySelector('app-selection-share')).toBeNull();
  });
```

`share-button.component.spec.ts`: a test that a target hidden through `SharingService.setTargetShown('x', false)` is not in the opened menu. Open the menu the way the spec's existing "lists the targets" test does and assert that no menu item reads `X`.

- [ ] **Step 2: Run them and watch them fail**

Run: `docker compose exec -T frontend npx jest src/app/reader/article`
Expected: FAIL. `./share-availability` is missing, and the share button still renders.

- [ ] **Step 3: Implement**

`share-availability.ts`:

```ts
import { Injectable, computed, inject } from '@angular/core';
import { SharingService } from '../../../core/preferences/sharing.service';
import { SHARE_TARGETS } from './share-targets';

/** Removing every target turns sharing off just as the switch does, the "Post as" entries included. */
@Injectable({ providedIn: 'root' })
export class ShareAvailability {
  private readonly sharing = inject(SharingService);

  readonly available = computed(
    () =>
      this.sharing.enabled() &&
      SHARE_TARGETS.some((target) => target.kind !== 'divider' && !this.sharing.hidden().has(target.id)),
  );
}
```

`share-button.component.ts`: inject `private readonly sharing = inject(SharingService);` and change `items` to `computed(() => shareMenuItems(this.networks(), this.mode(), this.sharing.hidden()))`.

`article-page.component.ts`: `protected readonly sharing = inject(ShareAvailability);` with its import.

`article-page.component.html`, the three hosts:

```html
    @if (sharing.available() && source.url(); as url) {
      <app-share-button [url]="url" [title]="source.shareTitle()" />
    }
```

```html
  @if (sharing.available()) {
    <app-selection-share articleDock [url]="source.url()" [title]="source.shareTitle()" />
  }
```

```html
  @if (sharing.available() && source.url(); as url) {
    <app-share-button articleCorner appearance="corner" [url]="url" [title]="source.shareTitle()" />
  }
```

Check `ArticleSelection` still behaves with the selection share gone. It is provided by the article page, not created by `app-selection-share`. If something listens only while the component exists, the article spec's selection tests will show it.

- [ ] **Step 4: Run the tests**

Run: `docker compose exec -T frontend npx jest src/app/reader/article`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add frontend/src/app/reader/article
git commit -m "feat(#2006): no share button or selection share while sharing is unavailable"
```

---

### Task 7: The "Sharing" settings group

**Files:**
- Create: `frontend/src/app/reader/article/share/share-target-logo/share-target-logo.component.ts`, `.html`, `.scss`
- Modify: `frontend/src/app/reader/article/share/share-button.component.html` and `.ts` (use the logo component)
- Create: `frontend/src/app/settings/preferences/sharing-settings/sharing-settings.component.ts`, `.html`, `.scss`, `.spec.ts`
- Modify: `frontend/src/app/settings/preferences/preferences-section.component.html` and `.ts`
- Modify: `frontend/public/i18n/en.json`, `frontend/public/i18n/de.json`

**Interfaces:**
- Consumes: `SharingService` (Task 5), `SHARE_TARGETS` with ids (Task 4), `SHARE_GLYPH`, `shareLogoUrl`.
- Produces: `<app-share-target-logo [target]="ConfiguredTarget" />`; `<app-sharing-settings />`.

- [ ] **Step 1: Extract the logo the menu draws**

`share-target-logo.component.ts`:

```ts
import { ChangeDetectionStrategy, Component, input } from '@angular/core';
import { IconComponent } from '../../../../shared/icon/icon.component';
import { MaskedLogoComponent } from '../../../../shared/masked-logo/masked-logo.component';
import { ConfiguredTarget, SHARE_GLYPH, shareLogoUrl } from '../share-targets';

@Component({
  selector: 'app-share-target-logo',
  imports: [IconComponent, MaskedLogoComponent],
  templateUrl: './share-target-logo.component.html',
  styleUrl: './share-target-logo.component.scss',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class ShareTargetLogoComponent {
  readonly target = input.required<ConfiguredTarget>();
  protected readonly glyph = SHARE_GLYPH;
  protected readonly logoUrl = shareLogoUrl;
}
```

`share-target-logo.component.html`:

```html
@if (target().icon; as icon) {
  <app-masked-logo [src]="logoUrl(icon)" [color]="target().color" />
} @else {
  <app-icon [name]="target().symbol ?? glyph" size="sm" />
}
```

`share-target-logo.component.scss`: `:host { display: contents; }`, so the menu's existing layout and selectors keep applying to the logo and icon.

In `share-button.component.html`, replace the `@if (item.target.icon) { … } @else { … }` block with `<app-share-target-logo [target]="item.target" />`. Import the component and drop `MaskedLogoComponent` and `logoUrl` from `share-button.component.ts` if nothing else there uses them. Run `docker compose exec -T frontend npx jest src/app/reader/article/share`. Expected: PASS, unchanged.

- [ ] **Step 2: Write the failing settings spec**

```ts
import { ComponentFixture, TestBed } from '@angular/core/testing';
import { provideTranslocoTesting } from '../../../../testing/transloco-testing';
import { SharingService } from '../../../core/preferences/sharing.service';
import { SharingSettingsComponent } from './sharing-settings.component';

describe('SharingSettingsComponent', () => {
  beforeEach(() => {
    localStorage.clear();
    TestBed.resetTestingModule();
    TestBed.configureTestingModule({ imports: [SharingSettingsComponent, provideTranslocoTesting()] });
  });

  let fixture: ComponentFixture<SharingSettingsComponent>;

  function mount(): HTMLElement {
    fixture = TestBed.createComponent(SharingSettingsComponent);
    fixture.detectChanges();
    return fixture.nativeElement as HTMLElement;
  }

  const toggle = (element: HTMLElement, id: string): HTMLInputElement =>
    element.querySelector<HTMLInputElement>(`#${id}`)!;

  it('lists one switch per target, all on by default', () => {
    const element = mount();

    expect(toggle(element, 'sharing-toggle').checked).toBe(true);
    expect(toggle(element, 'share-target-x').checked).toBe(true);
    expect(toggle(element, 'share-target-reddit').checked).toBe(true);
  });

  it('hides a target when its switch goes off', () => {
    const element = mount();

    toggle(element, 'share-target-x').click();

    expect(TestBed.inject(SharingService).hidden().has('x')).toBe(true);
  });

  it('switches sharing off and disables the target switches', () => {
    const element = mount();

    toggle(element, 'sharing-toggle').click();
    fixture.detectChanges();

    expect(TestBed.inject(SharingService).enabled()).toBe(false);
    expect(toggle(element, 'share-target-x').disabled).toBe(true);
  });

  it('reports a failed save', () => {
    TestBed.inject(SharingService).saveFailed.set(true);

    expect(mount().querySelector('app-error-banner')).not.toBeNull();
  });
});
```

Check how `app-toggle` renders its input (`toggle.component.html`): the `#id` selectors assume `inputId` lands on the `<input type="checkbox">`. If the click lands elsewhere, use the same interaction `preferences-section.component.spec.ts` uses for its toggles. With `OnPush`, if `detectChanges()` after the click does not pick up the signal change, follow what the existing toggle specs do (`fixture.autoDetectChanges()` or `await fixture.whenStable()`).

- [ ] **Step 3: Run it and watch it fail**

Run: `docker compose exec -T frontend npx jest src/app/settings/preferences/sharing-settings`
Expected: FAIL, cannot find module.

- [ ] **Step 4: Implement the group**

`sharing-settings.component.ts`:

```ts
import { ChangeDetectionStrategy, Component, inject } from '@angular/core';
import { TranslocoPipe } from '@jsverse/transloco';
import { SharingService } from '../../../core/preferences/sharing.service';
import { ShareTargetLogoComponent } from '../../../reader/article/share/share-target-logo/share-target-logo.component';
import { ConfiguredTarget, SHARE_TARGETS } from '../../../reader/article/share/share-targets';
import { ErrorBannerComponent } from '../../../shared/error-banner/error-banner.component';
import { SettingsGroupComponent } from '../../../shared/settings/settings-group/settings-group.component';
import { SettingsRowComponent } from '../../../shared/settings/settings-row/settings-row.component';
import { ToggleComponent } from '../../../shared/toggle/toggle.component';

@Component({
  selector: 'app-sharing-settings',
  imports: [
    ErrorBannerComponent,
    SettingsGroupComponent,
    SettingsRowComponent,
    ShareTargetLogoComponent,
    ToggleComponent,
    TranslocoPipe,
  ],
  templateUrl: './sharing-settings.component.html',
  styleUrl: './sharing-settings.component.scss',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class SharingSettingsComponent {
  protected readonly sharing = inject(SharingService);
  protected readonly targets = SHARE_TARGETS.filter(
    (target): target is ConfiguredTarget => target.kind !== 'divider',
  );
}
```

`sharing-settings.component.html`:

```html
<app-settings-group icon="share" [title]="'settings.sharing' | transloco">
  <app-settings-row
    [title]="'settings.sharingEnabled' | transloco"
    [description]="'settings.sharingEnabledHint' | transloco"
    labelFor="sharing-toggle"
  >
    <app-toggle
      inputId="sharing-toggle"
      [checked]="sharing.enabled()"
      [label]="'settings.sharingEnabled' | transloco"
      (toggled)="sharing.setEnabled($event)"
    />
  </app-settings-row>
  @for (target of targets; track target.id) {
    @let label = target.labelKey ? (target.labelKey | transloco) : (target.label ?? '');
    <app-settings-row [title]="label" [labelFor]="'share-target-' + target.id">
      <app-share-target-logo rowTitleTip [target]="target" />
      <app-toggle
        [inputId]="'share-target-' + target.id"
        [checked]="!sharing.hidden().has(target.id)"
        [disabled]="!sharing.enabled()"
        [label]="label"
        (toggled)="sharing.setTargetShown(target.id, $event)"
      />
    </app-settings-row>
  }
  @if (sharing.saveFailed()) {
    <app-error-banner class="state" [message]="'settings.sharingSaveFailed' | transloco" />
  }
</app-settings-group>
```

If `rowTitleTip` places the logo after the title and that reads wrong in the browser, look at `docs/design-language.md` for a leading-icon row pattern before adding one; do not add a new slot to `app-settings-row` for this alone. `sharing-settings.component.scss`: empty unless the browser check calls for spacing; copy `.state` from `preferences-section.component.scss` if the banner needs it.

`preferences-section.component.html`: add `<app-sharing-settings />` after the closing `</app-settings-group>`, inside `<app-settings-stack>`. Import `SharingSettingsComponent` in `preferences-section.component.ts`.

`en.json` under `settings`:

```json
    "sharing": "Sharing",
    "sharingEnabled": "Share articles",
    "sharingEnabledHint": "The share button and sharing a text selection. Switched off, or with every target below off, both disappear.",
    "sharingSaveFailed": "Your sharing settings could not be saved to your account.",
```

`de.json` under `settings`:

```json
    "sharing": "Teilen",
    "sharingEnabled": "Artikel teilen",
    "sharingEnabledHint": "Der Teilen-Knopf und das Teilen einer Textauswahl. Ausgeschaltet, oder mit allen Zielen unten aus, verschwinden beide.",
    "sharingSaveFailed": "Deine Teilen-Einstellungen konnten nicht in deinem Konto gespeichert werden.",
```

- [ ] **Step 5: Run the tests and the gate**

Run: `docker compose exec -T frontend npx jest src/app/settings src/app/reader/article/share`
Expected: PASS.
Run: `docker compose exec -T frontend npm run check`
Expected: clean. The i18n key-parity spec, if there is one, must pass for both locales.

- [ ] **Step 6: Look at it**

With the Docker stack up, open Settings › Preferences in the in-app browser at desktop and phone width. Check that the group reads well, the logos match the menu, and the target switches grey out while sharing is off. Open an article to confirm the share button and the selection bubble are gone while sharing is off, then turn it back on.

- [ ] **Step 7: Commit**

```bash
git add frontend/src/app/reader/article/share frontend/src/app/settings frontend/public/i18n
git commit -m "feat(#2006): a Sharing group in Settings › Preferences"
```

---

### Task 8: Full gates and the PR

- [ ] **Step 1: Backend gates**

From `backend/`: `composer check && composer md && php bin/phpunit && composer infection:diff`, then `docker compose exec php composer test:parallel`. Expected: all green. Fix escaped mutants with tests, never by lowering `minMsi`.

- [ ] **Step 2: Frontend gate**

`docker compose exec -T frontend npm run check`. Expected: green.

- [ ] **Step 3: Dev log**

`ls -t backend/var/log/dev-*.log | head -1 | xargs tail -n 200 | jq 'select(.level_name != "DEBUG" and .level_name != "INFO")'`. Expected: no deprecation or error from the new code.

- [ ] **Step 4: Push and open the PR**

```bash
git push -u origin feature/2006-hide-share-targets
gh pr create --base develop --title "feat(#2006): remove share targets and switch sharing off" --body "Closes #2006

- Per-account master switch and hidden share targets (\`PATCH /api/me/sharing\`, \`preferences.sharing\` on \`/api/me\`), in the backup.
- Stable ids in \`share-targets.json\`; the menu drops hidden targets and stray dividers.
- With sharing off or every target hidden: no share button, no selection bubble or phone bar.
- Sharing group in Settings › Preferences.

Spec: docs/superpowers/specs/2026-11-03-2006-hide-share-targets-design.md"
```
