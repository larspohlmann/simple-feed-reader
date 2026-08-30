# Magazine boxed/airy entry design Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Give each account a setting that switches the magazine view between the
current bordered-card design (`boxed`) and a boxless one separated by hairline
rules (`airy`).

**Architecture:** The card chrome stops being three lines copied into seven
component stylesheets and becomes five custom properties set once on
`.rows.magazine`. Airy is then a different set of values for those properties,
plus one `.airy` class that carries the structural half — the wider gap, the
rules on the slots, the group inset and the hover idiom. The chosen style is an
account column reached by its own PATCH endpoint, mirrored into `localStorage`
as a per-device paint cache so the first frame is never wrong.

**Tech Stack:** Symfony 7.4 / PHP 8.4 / Doctrine ORM on the backend; Angular 20
standalone components with signals, SCSS and Transloco on the frontend.
PHPUnit, Jest and Playwright.

**Spec:** [GitHub issue #723](https://github.com/larspohlmann/simple-feed-reader/issues/723)

## Global Constraints

- Branch is `feature/723-magazine-airy-style`, off `develop`. It already exists
  and is checked out.
- Commit messages take the form `type(#723): summary`.
- Every PHP file carries `declare(strict_types=1)`. PSR-12, PHPStan level max,
  PHPMD-clean for every `src` file touched. Run `composer check` and
  `composer md` before each backend commit.
- **Clean Code is mandatory.** Names reveal intent, functions do one thing,
  `final readonly class` with constructor promotion is the house style,
  controllers hold no private method that carries responsibility.
- **Comments: default to none.** A clear name or a smaller method beats a
  sentence about the code. Comment only for the *why* the code cannot state — a
  non-obvious invariant, a defensive branch, a decision that costs more to
  rediscover than to read. **One line; three at the absolute most**, docblocks
  included, in every language in this tree. Delete on sight, in code you write
  and code you touch: a comment restating the next line, a `@param`/`@return`
  that repeats the signature, a docblock repeating the class name, a section
  banner, a narration of the change, and all commented-out code. The code blocks
  in this plan are held to the same rule — if one of them carries a comment that
  breaks it, cut the comment, do not copy it.
- **Migrations follow the house shape:** a real `getDescription()`, the
  generator's "auto-generated, please modify" boilerplate deleted, and
  platform-neutral SQL guarded by `assertSupportedPlatform()`. 54 of the 55
  migrations in the tree already do this — read `Version20260829165039.php`.
- No hex colours in `.scss` outside `src/app/theme/`, and no ad-hoc `px`
  spacing or media-query literals. Stylelint fails the build on all three.
- Component styles live in a sibling `.scss` file, never inline in the `.ts`.
- Frontend gate is `npm run check` from `frontend/`. Jest runs inside the
  Docker frontend container: `docker compose exec -T frontend npm test`.
- Datetimes are stored as naive UTC. No new datetime column here, so this
  constrains nothing, but do not introduce one.
- Keep a native Swift iOS client viable: bearer auth, stateless JSON in and
  `application/problem+json` out. The new endpoint must not be browser-coupled.
- The default value is `boxed` everywhere — entity default, column default,
  frontend fallback. An account that never touches the setting must see exactly
  what it sees today.

---

## File Structure

**Backend — create**

| File | Responsibility |
|---|---|
| `backend/src/Service/Reader/MagazineStyle.php` | The `boxed`/`airy` backed enum. Its own file next to the reader concern, mirroring `DigestCadence`. |
| `backend/src/Dto/Me/UpdateMagazineStyleRequest.php` | The PATCH payload: one required `MagazineStyle`. |
| `backend/migrations/VersionYYYYMMDDHHMMSS.php` | Adds `user_preferences.magazine_style`. |
| `backend/tests/Controller/Api/MagazineStyleControllerTest.php` | Functional cover for the endpoint. |
| `backend/tests/Service/Reader/MagazineStyleTest.php` | The enum's cases and default. |

**Backend — modify**

| File | Change |
|---|---|
| `backend/src/Entity/Preferences.php` | New `magazineStyle` column, getter and setter. |
| `backend/src/Controller/Api/MeController.php` | New `PATCH /api/me/magazine-style` action. |
| `backend/src/Http/MeJson.php` | Expose `preferences.magazineStyle`. |
| `backend/src/Service/Backup/AccountBackupExporter.php:190` | Write the key. |
| `backend/src/Service/Backup/Dto/AccountLine.php` | Read the key. |
| `backend/src/Service/Backup/RestoreLoadPass.php:106` | Apply it on restore. |
| `backend/tests/Support/BackupFieldDeclarations.php:49` | Declare it, or the drift guard fails. |

**Frontend — create**

| File | Responsibility |
|---|---|
| `frontend/src/app/core/magazine-style.ts` | The `MagazineStyle` type, its values, the storage key and the parser. No Angular. |
| `frontend/src/app/core/magazine-style-writer.ts` | The writer interface and its injection token. |
| `frontend/src/app/core/http-magazine-style-writer.ts` | The real HTTP writer. |
| `frontend/src/app/core/magazine-style.service.ts` | The signal, the `localStorage` cache, `set`/`adopt`/`reset`. |
| `frontend/src/app/shared/magazine-style-switcher/` | The `.seg` control (`.ts`, `.html`, `.scss`, `.spec.ts`). |
| `frontend/e2e/magazine-airy-style.spec.ts` | The Playwright smoke. |

**Frontend — modify**

| File | Change |
|---|---|
| `frontend/src/app/core/auth.service.ts` | `UserPreferences.magazineStyle`; adopt and reset it. |
| `frontend/src/app/reader/entry-list/entry-list.component.html:272` | `[class.airy]` on `.rows.magazine`. |
| `frontend/src/app/reader/entry-list/entry-list.component.ts` | Inject the service. |
| `frontend/src/app/reader/entry-list/entry-list.component.scss` | The `--card-*` declarations, the airy overrides, the rules, `magazine-gap-close`. |
| The seven magazine block stylesheets | Consume `--card-*` instead of literal chrome; the airy hover and quote rule. |
| `frontend/src/app/settings/preferences-section.component.{ts,html}` | The new settings row. |
| `frontend/public/i18n/{en,de}.json` | Five new keys. |

---

## Task 1: The MagazineStyle enum, the column and the migration

**Files:**
- Create: `backend/src/Service/Reader/MagazineStyle.php`
- Create: `backend/tests/Service/Reader/MagazineStyleTest.php`
- Modify: `backend/src/Entity/Preferences.php`
- Create: `backend/migrations/VersionYYYYMMDDHHMMSS.php` (generated)

**Interfaces:**
- Consumes: nothing.
- Produces: `App\Service\Reader\MagazineStyle` with cases `Boxed = 'boxed'` and
  `Airy = 'airy'`; `Preferences::getMagazineStyle(): MagazineStyle` and
  `Preferences::setMagazineStyle(MagazineStyle $magazineStyle): void`.

- [ ] **Step 1: Write the failing test**

Create `backend/tests/Service/Reader/MagazineStyleTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Service\Reader;

use App\Service\Reader\MagazineStyle;
use PHPUnit\Framework\TestCase;

final class MagazineStyleTest extends TestCase
{
    public function testTheTwoStylesAreTheOnlyOnes(): void
    {
        self::assertSame(
            ['boxed', 'airy'],
            array_map(static fn (MagazineStyle $style): string => $style->value, MagazineStyle::cases()),
        );
    }

    public function testAnUnknownValueIsNotAStyle(): void
    {
        self::assertNull(MagazineStyle::tryFrom('cards'));
    }
}
```

- [ ] **Step 2: Run it to make sure it fails**

Run: `cd backend && php bin/phpunit tests/Service/Reader/MagazineStyleTest.php`
Expected: FAIL, `Class "App\Service\Reader\MagazineStyle" does not exist`.

- [ ] **Step 3: Write the enum**

Create `backend/src/Service/Reader/MagazineStyle.php`:

```php
<?php

declare(strict_types=1);

namespace App\Service\Reader;

/** How the magazine view draws an entry (#723). */
enum MagazineStyle: string
{
    case Boxed = 'boxed';
    case Airy = 'airy';
}
```

- [ ] **Step 4: Run the test to make sure it passes**

Run: `cd backend && php bin/phpunit tests/Service/Reader/MagazineStyleTest.php`
Expected: PASS, 2 tests.

- [ ] **Step 5: Add the column to the entity**

In `backend/src/Entity/Preferences.php`, add the import
`use App\Service\Reader\MagazineStyle;` and, after the `$scrapeFallbackEnabled`
property, this property:

```php
    /** Boxed by default: what every existing account already sees (#723). */
    #[ORM\Column(name: 'magazine_style', length: 10, enumType: MagazineStyle::class, options: ['default' => 'boxed'])]
    private MagazineStyle $magazineStyle = MagazineStyle::Boxed;
```

and, after `setScrapeFallbackEnabled()`, this pair:

```php
    public function getMagazineStyle(): MagazineStyle
    {
        return $this->magazineStyle;
    }

    public function setMagazineStyle(MagazineStyle $magazineStyle): void
    {
        $this->magazineStyle = $magazineStyle;
    }
```

- [ ] **Step 6: Generate the migration**

Run: `cd backend && bin/console doctrine:migrations:diff --no-interaction`

Open the generated file. It must add one column to `user_preferences` with a
`'boxed'` default and no data loss, and its `down()` must drop that column.
Check the SQL names the default — a nullable-free column added to a populated
table without one fails on MySQL. If the diff is empty, the ORM cache is stale:
run `bin/console cache:warmup` and diff again.

- [ ] **Step 7: Run the migration on both dialects**

```bash
docker compose exec php bin/console doctrine:migrations:migrate --no-interaction
docker compose exec php bin/console doctrine:schema:validate
```

Expected: the MySQL migration applies and `schema:validate` reports the mapping
and the database in sync. `tests/bootstrap.php` builds the schema from ORM
metadata, so no unit test ever executes a migration — this step is the only
proof the migration itself works.

**`tests/Service/Backup` is expected to be RED from this commit until Task 3.**
Adding a mapped column to a backed-up entity is exactly what `BackupSchemaCoverageTest`
exists to catch (#556), and watching it fail is Task 3's opening step. Do not
silence it here, and do not run that directory as part of this task's gate.

- [ ] **Step 8: Gates and commit**

```bash
cd backend && composer check && composer md
git add backend/src/Service/Reader/MagazineStyle.php backend/src/Entity/Preferences.php backend/migrations backend/tests/Service/Reader/MagazineStyleTest.php
git commit -m "feat(#723): add the magazine style column and its enum"
```

---

## Task 2: The PATCH endpoint

**Files:**
- Create: `backend/src/Dto/Me/UpdateMagazineStyleRequest.php`
- Create: `backend/tests/Controller/Api/MagazineStyleControllerTest.php`
- Modify: `backend/src/Controller/Api/MeController.php`
- Modify: `backend/src/Http/MeJson.php:33-42`

**Interfaces:**
- Consumes: `MagazineStyle`, `Preferences::setMagazineStyle()` from Task 1.
- Produces: `PATCH /api/me/magazine-style`, route name
  `api_me_update_magazine_style`, body `{"magazineStyle": "airy"}`, response
  `MeJson::profile`. `MeJson` gains `preferences.magazineStyle`, a string.

- [ ] **Step 1: Write the failing test**

Create `backend/tests/Controller/Api/MagazineStyleControllerTest.php`. The base
class is `App\Tests\Support\ApiTestCase`; it gives you `factory()`, `users()`,
`em()` and `payload()`, and each test mints its own bearer token rather than
posting to `/api/auth/login`, so these cases never touch the login throttler's
filesystem pool.

```php
<?php

declare(strict_types=1);

namespace App\Tests\Controller\Api;

use App\Entity\User;
use App\Service\Reader\MagazineStyle;
use App\Tests\Support\ApiTestCase;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

/**
 * The account's own view of, and write path onto, the magazine's entry design
 * (#723). Tokens come straight from the JWT manager, as in MeControllerTest.
 */
final class MagazineStyleControllerTest extends ApiTestCase
{
    /** Attaches a bearer token to every subsequent request this client makes. */
    private function authenticate(KernelBrowser $client, string $email): void
    {
        $user = $this->users()->findOneByEmail($email);
        self::assertInstanceOf(User::class, $user);

        /** @var JWTTokenManagerInterface $manager */
        $manager = self::getContainer()->get(JWTTokenManagerInterface::class);

        $client->setServerParameter('HTTP_AUTHORIZATION', 'Bearer ' . $manager->create($user));
    }

    private function patchStyle(KernelBrowser $client, string $style): void
    {
        $client->request(
            'PATCH',
            '/api/me/magazine-style',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: sprintf('{"magazineStyle":"%s"}', $style),
        );
    }

    public function testANewAccountIsBoxed(): void
    {
        $client = static::createClient();
        $this->factory()->create('boxed-by-default@example.test');
        $this->authenticate($client, 'boxed-by-default@example.test');

        $client->request('GET', '/api/me');

        self::assertResponseIsSuccessful();
        self::assertSame('boxed', $this->payload($client)['preferences']['magazineStyle']);
    }

    public function testChangingTheStylePersistsIt(): void
    {
        $client = static::createClient();
        $this->factory()->create('airy-reader@example.test');
        $this->authenticate($client, 'airy-reader@example.test');

        $this->patchStyle($client, 'airy');

        self::assertResponseIsSuccessful();
        self::assertSame('airy', $this->payload($client)['preferences']['magazineStyle']);

        $this->em()->clear();
        $reloaded = $this->users()->findOneBy(['email' => 'airy-reader@example.test']);
        self::assertNotNull($reloaded);
        self::assertSame(MagazineStyle::Airy, $reloaded->getPreferences()->getMagazineStyle());
    }

    public function testAnUnknownStyleIsRejected(): void
    {
        $client = static::createClient();
        $this->factory()->create('cards-please@example.test');
        $this->authenticate($client, 'cards-please@example.test');

        $this->patchStyle($client, 'cards');

        self::assertResponseStatusCodeSame(422);
    }

    public function testAnEmptyBodyIsRejected(): void
    {
        $client = static::createClient();
        $this->factory()->create('empty-body@example.test');
        $this->authenticate($client, 'empty-body@example.test');

        $client->request(
            'PATCH',
            '/api/me/magazine-style',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: '{}',
        );

        self::assertResponseStatusCodeSame(422);
    }

    public function testASignedOutClientIsRejected(): void
    {
        $client = static::createClient();

        $this->patchStyle($client, 'airy');

        self::assertResponseStatusCodeSame(401);
    }

    public function testAPreferencesWriteLeavesTheStyleAlone(): void
    {
        $client = static::createClient();
        $this->factory()->create('independent-writes@example.test');
        $this->authenticate($client, 'independent-writes@example.test');
        $this->patchStyle($client, 'airy');

        $client->request(
            'PATCH',
            '/api/me/preferences',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: '{"scrapeFallbackEnabled":true}',
        );

        self::assertResponseIsSuccessful();
        self::assertSame('airy', $this->payload($client)['preferences']['magazineStyle']);
    }
}
```

The last test is the point of the separate endpoint, not a formality: it is what
#180 bought and what a merged DTO would take away.

- [ ] **Step 2: Run it to make sure it fails**

Run: `cd backend && php bin/phpunit tests/Controller/Api/MagazineStyleControllerTest.php`
Expected: FAIL — the GET assertion fails on a missing `magazineStyle` key, and
every PATCH returns 404 because the route does not exist.

- [ ] **Step 3: Write the DTO**

Create `backend/src/Dto/Me/UpdateMagazineStyleRequest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Dto\Me;

use App\Service\Reader\MagazineStyle;

/**
 * Its own request, not a field on UpdatePreferencesRequest: folding it in would
 * make every scrape-fallback write resend the style, the coupling #180 refused.
 * The promoted enum type answers a bad value with a 422, so no Assert is needed.
 */
final readonly class UpdateMagazineStyleRequest
{
    public function __construct(
        public MagazineStyle $magazineStyle,
    ) {
    }
}
```

- [ ] **Step 4: Add the action**

In `backend/src/Controller/Api/MeController.php`, add
`use App\Dto\Me\UpdateMagazineStyleRequest;` to the imports and this action
directly after `updatePreferences()`:

```php
    /** Its own PATCH for the reason updatePreferences() gives (#723). */
    #[Route('/api/me/magazine-style', name: 'api_me_update_magazine_style', methods: ['PATCH'])]
    public function updateMagazineStyle(
        #[CurrentUser] User $user,
        #[MapRequestPayload] UpdateMagazineStyleRequest $request,
    ): JsonResponse {
        $user->getPreferences()->setMagazineStyle($request->magazineStyle);
        $this->entityManager->flush();

        return new JsonResponse(MeJson::profile($user, $this->mail->isEnabled(), $this->instanceTimezone));
    }
```

The action reads the request, delegates and returns. It adds no private helper,
so `ThinControllerRule` has nothing to catch.

- [ ] **Step 5: Expose it in MeJson**

In `backend/src/Http/MeJson.php`, inside the `'preferences'` array and directly
after the `scrapeFallbackEnabled` line:

```php
                'magazineStyle' => $preferences->getMagazineStyle()->value,
```

- [ ] **Step 6: Run the tests to make sure they pass**

Run: `cd backend && php bin/phpunit tests/Controller/Api/MagazineStyleControllerTest.php`
Expected: PASS, 6 tests.

- [ ] **Step 7: Prove the guard by breaking it**

Temporarily change the action to write `MagazineStyle::Boxed` instead of
`$request->magazineStyle`. Re-run the test file. `testTheStyleCanBeSwitchedToAiry`
and `testTheStyleIsPersisted` must both fail. Revert the change and confirm they
pass again. A test whose first green run is its only run has not been tested.

- [ ] **Step 8: Gates and commit**

```bash
cd backend && composer check && composer md && php bin/phpunit
git add backend/src backend/tests
git commit -m "feat(#723): add the magazine style endpoint"
```

---

## Task 3: The backup format

**Files:**
- Modify: `backend/src/Service/Backup/Dto/AccountLine.php`
- Modify: `backend/src/Service/Backup/AccountBackupExporter.php:189-190`
- Modify: `backend/src/Service/Backup/RestoreLoadPass.php:105-106`
- Modify: `backend/tests/Support/BackupFieldDeclarations.php:49`
- Modify: `backend/tests/Service/Backup/Dto/AccountLineTest.php`

**Interfaces:**
- Consumes: `MagazineStyle` and the entity accessors from Task 1.
- Produces: `AccountLine::$magazineStyle` of type `MagazineStyle`; the backup key
  `magazineStyle` on the account line.

- [ ] **Step 1: Watch the drift guard fail**

Run: `cd backend && php bin/phpunit tests/Service/Backup/BackupSchemaCoverageTest.php`
Expected: FAIL. Task 1 added a mapped column to a backed-up entity and nothing
declares it, which is precisely the miss #556 exists to catch. Read the failure
message — it names `magazineStyle` and tells you what a declaration looks like.

- [ ] **Step 2: Write the failing DTO test**

In `backend/tests/Service/Backup/Dto/AccountLineTest.php`, follow the file's
existing style and add:

```php
    public function testItReadsTheMagazineStyle(): void
    {
        $line = AccountLine::fromLine([
            'locale' => 'de',
            'scrapeFallbackEnabled' => true,
            'magazineStyle' => 'airy',
        ]);

        self::assertSame(MagazineStyle::Airy, $line->magazineStyle);
    }

    public function testAMissingMagazineStyleFallsBackToBoxed(): void
    {
        $line = AccountLine::fromLine(['locale' => 'de', 'scrapeFallbackEnabled' => true]);

        self::assertSame(MagazineStyle::Boxed, $line->magazineStyle);
    }
```

The second test is not optional politeness: a backup written before this task
has no such key, and a restore of one must not fail. `RecommendationSettings`
already solves the same problem with `LineField::boolWithDefault`.

- [ ] **Step 3: Run it to make sure it fails**

Run: `cd backend && php bin/phpunit tests/Service/Backup/Dto/AccountLineTest.php`
Expected: FAIL, unknown property `magazineStyle`.

- [ ] **Step 4: Add the field to the DTO**

In `AccountLine.php`, add `use App\Service\Reader\MagazineStyle;`, add
`public MagazineStyle $magazineStyle,` to the constructor after
`$scrapeFallbackEnabled`, and in `fromLine()`:

```php
            magazineStyle: MagazineStyle::tryFrom(LineField::stringWithDefault($line, 'magazineStyle', 'boxed'))
                ?? MagazineStyle::Boxed,
```

`LineField` has no `stringWithDefault` yet — add it directly after
`boolWithDefault` at :98, mirroring that method exactly:

```php
    /**
     * Reads a string that a pre-existing backup may not carry, for the reason
     * boolWithDefault gives.
     *
     * @param array<string, mixed> $line
     */
    public static function stringWithDefault(array $line, string $key, string $default): string
    {
        if (!\array_key_exists($key, $line)) {
            return $default;
        }

        return self::string($line, $key);
    }
```

The `?? MagazineStyle::Boxed` after it is not dead code: a hand-edited backup can
carry any string, and a restore must not fatal on one.

- [ ] **Step 5: Write and read the key**

In `AccountBackupExporter.php`, after the `scrapeFallbackEnabled` line:

```php
            'magazineStyle' => $user->getPreferences()->getMagazineStyle()->value,
```

In `RestoreLoadPass.php`, after the `setScrapeFallbackEnabled` line:

```php
        $this->user->getPreferences()->setMagazineStyle($line->magazineStyle);
```

- [ ] **Step 6: Declare it to the drift guard**

In `backend/tests/Support/BackupFieldDeclarations.php:49`, change the
`Preferences::class` entry to:

```php
        Preferences::class => [
            'scrapeFallbackEnabled' => 'scrapeFallbackEnabled',
            'magazineStyle' => 'magazineStyle',
        ],
```

- [ ] **Step 7: Run the whole backup suite**

Run: `cd backend && php bin/phpunit tests/Service/Backup`
Expected: PASS, including `BackupSchemaCoverageTest` and the export/restore
round trip in `AccountRestorerTest`.

- [ ] **Step 8: Gates and commit**

```bash
cd backend && composer check && composer md
git add backend/src backend/tests
git commit -m "feat(#723): carry the magazine style through backup and restore"
```

---

## Task 4: The frontend service and its paint cache

**Files:**
- Create: `frontend/src/app/core/magazine-style.ts`
- Create: `frontend/src/app/core/magazine-style-writer.ts`
- Create: `frontend/src/app/core/http-magazine-style-writer.ts`
- Create: `frontend/src/app/core/magazine-style.service.ts`
- Create: `frontend/src/app/core/magazine-style.service.spec.ts`
- Modify: `frontend/src/app/core/auth.service.ts:23-25`

**Interfaces:**
- Consumes: `preferences.magazineStyle` from the `/api/me` response (Task 2).
- Produces: `MagazineStyle = 'boxed' | 'airy'`; `MAGAZINE_STYLE_KEY = 'sfr.magazineStyle'`;
  `asMagazineStyle(value: string | null): MagazineStyle | null`;
  `MAGAZINE_STYLE_WRITER` token over `{ write(style: MagazineStyle): Observable<boolean> }`;
  `MagazineStyleService` with `style: Signal<MagazineStyle>`,
  `saveFailed: Signal<boolean>`, `set(style)`, `adopt(user)`, `reset()`.

- [ ] **Step 1: Write the failing test**

Create `frontend/src/app/core/magazine-style.service.spec.ts`:

```typescript
import { TestBed } from '@angular/core/testing';
import { Observable, of } from 'rxjs';
import { CurrentUser } from './auth.service';
import { MAGAZINE_STYLE_KEY, MagazineStyle } from './magazine-style';
import { MagazineStyleService } from './magazine-style.service';
import { MAGAZINE_STYLE_WRITER } from './magazine-style-writer';

function userWith(magazineStyle: MagazineStyle): CurrentUser {
  return { preferences: { magazineStyle } } as CurrentUser;
}

describe('MagazineStyleService', () => {
  let written: MagazineStyle[];
  let result: Observable<boolean>;

  function make(): MagazineStyleService {
    TestBed.configureTestingModule({
      providers: [
        {
          provide: MAGAZINE_STYLE_WRITER,
          useValue: {
            write: (style: MagazineStyle) => {
              written.push(style);
              return result;
            },
          },
        },
      ],
    });
    return TestBed.inject(MagazineStyleService);
  }

  beforeEach(() => {
    localStorage.clear();
    written = [];
    result = of(true);
    TestBed.resetTestingModule();
  });

  it('starts boxed when nothing is cached', () => {
    expect(make().style()).toBe('boxed');
  });

  it('starts from the cache, so the first frame is never wrong', () => {
    localStorage.setItem(MAGAZINE_STYLE_KEY, 'airy');

    expect(make().style()).toBe('airy');
  });

  it('ignores a cached value it does not know', () => {
    localStorage.setItem(MAGAZINE_STYLE_KEY, 'cards');

    expect(make().style()).toBe('boxed');
  });

  it('applies locally and writes through', () => {
    const service = make();

    service.set('airy');

    expect(service.style()).toBe('airy');
    expect(localStorage.getItem(MAGAZINE_STYLE_KEY)).toBe('airy');
    expect(written).toEqual(['airy']);
  });

  it('reports a failed account write without reverting the local value', () => {
    result = of(false);
    const service = make();

    service.set('airy');

    expect(service.style()).toBe('airy');
    expect(service.saveFailed()).toBe(true);
  });

  it('clears a previous failure on the next write', () => {
    const service = make();
    result = of(false);
    service.set('airy');
    result = of(true);

    service.set('boxed');

    expect(service.saveFailed()).toBe(false);
  });

  it('adopts the account value without writing it back', () => {
    const service = make();

    service.adopt(userWith('airy'));

    expect(service.style()).toBe('airy');
    expect(localStorage.getItem(MAGAZINE_STYLE_KEY)).toBe('airy');
    expect(written).toEqual([]);
  });

  it('drops the signed-out account style, cache included', () => {
    const service = make();
    service.adopt(userWith('airy'));

    service.reset();

    expect(service.style()).toBe('boxed');
    expect(localStorage.getItem(MAGAZINE_STYLE_KEY)).toBeNull();
  });
});
```

The "without writing it back" assertion matters: `adopt` runs on every
`loadMe()`, and a version that wrote through would PATCH the server its own
value on every page load.

- [ ] **Step 2: Run it to make sure it fails**

Run: `docker compose exec -T frontend npx jest src/app/core/magazine-style.service.spec.ts`
Expected: FAIL, cannot resolve `./magazine-style`.

If every container spec dies on a `jest-preset-angular` error, the jest cache is
stale: run `docker compose exec -T frontend npx jest --clearCache`. Do not run
`npm ci`.

- [ ] **Step 3: Write the value module**

Create `frontend/src/app/core/magazine-style.ts`:

```typescript
// src/app/core/magazine-style.ts

export type MagazineStyle = 'boxed' | 'airy';

export const MAGAZINE_STYLES: readonly MagazineStyle[] = ['boxed', 'airy'];

/**
 * The per-device paint cache, not the record: it exists so the first magazine
 * frame after a cold start is not drawn boxed until `loadMe()` resolves.
 */
export const MAGAZINE_STYLE_KEY = 'sfr.magazineStyle';

export function asMagazineStyle(value: string | null): MagazineStyle | null {
  return MAGAZINE_STYLES.includes(value as MagazineStyle) ? (value as MagazineStyle) : null;
}
```

- [ ] **Step 4: Write the writer token**

Create `frontend/src/app/core/magazine-style-writer.ts`:

```typescript
// src/app/core/magazine-style-writer.ts
import { InjectionToken } from '@angular/core';
import { Observable, of } from 'rxjs';
import { MagazineStyle } from './magazine-style';

/**
 * Behind a token for the reason `PREFERENCES_WRITER` is: the service is read by
 * reader components that must not pull in the HTTP layer.
 */
export interface MagazineStyleWriter {
  /** Resolves `true` on success, `false` on failure. Never errors. */
  write(style: MagazineStyle): Observable<boolean>;
}

export const MAGAZINE_STYLE_WRITER = new InjectionToken<MagazineStyleWriter>(
  'MAGAZINE_STYLE_WRITER',
  { providedIn: 'root', factory: (): MagazineStyleWriter => ({ write: () => of(true) }) },
);
```

- [ ] **Step 5: Write the HTTP writer**

Create `frontend/src/app/core/http-magazine-style-writer.ts`:

```typescript
// src/app/core/http-magazine-style-writer.ts
import { HttpClient } from '@angular/common/http';
import { Injectable, inject } from '@angular/core';
import { Observable, catchError, map, of } from 'rxjs';
import { API_BASE_URL } from './api';
import { MagazineStyle } from './magazine-style';
import { MagazineStyleWriter } from './magazine-style-writer';

/**
 * Goes straight at `HttpClient` rather than through `AuthService`, which would
 * close a dependency cycle — the same reason `HttpPreferencesWriter` does.
 */
@Injectable({ providedIn: 'root' })
export class HttpMagazineStyleWriter implements MagazineStyleWriter {
  private readonly http = inject(HttpClient);
  private readonly base = inject(API_BASE_URL);

  write(magazineStyle: MagazineStyle): Observable<boolean> {
    return this.http.patch(`${this.base}/api/me/magazine-style`, { magazineStyle }).pipe(
      map(() => true),
      catchError(() => of(false)),
    );
  }
}
```

Register it in `frontend/src/app/app.config.ts`, directly after the
`PREFERENCES_WRITER` provider at :57:

```typescript
    { provide: MAGAZINE_STYLE_WRITER, useExisting: HttpMagazineStyleWriter },
```

with the two matching imports. The comment above that line explains why the
token defaults to a no-op; leave it, it now covers both providers.

- [ ] **Step 6: Write the service**

Create `frontend/src/app/core/magazine-style.service.ts`:

```typescript
// src/app/core/magazine-style.service.ts
import { Injectable, inject, signal } from '@angular/core';
import { CurrentUser } from './auth.service';
import { MAGAZINE_STYLE_KEY, MagazineStyle, asMagazineStyle } from './magazine-style';
import { MAGAZINE_STYLE_WRITER } from './magazine-style-writer';

/**
 * The account is the record; `localStorage` is a paint cache. It answers the
 * warning in `PreferencesService`: the first frame comes from the last known
 * style, so no window exists in which a wrong value is shown or written.
 */
@Injectable({ providedIn: 'root' })
export class MagazineStyleService {
  private readonly writer = inject(MAGAZINE_STYLE_WRITER);

  readonly style = signal<MagazineStyle>(this.cached());

  /** True when the style applied locally but the account write failed. */
  readonly saveFailed = signal(false);

  /** Applies locally first, then writes through; a failed write is surfaced. */
  set(style: MagazineStyle): void {
    this.cache(style);
    this.saveFailed.set(false);

    this.writer.write(style).subscribe((ok) => {
      if (!ok) this.saveFailed.set(true);
    });
  }

  /**
   * Caches only — a value that just arrived from the server is never PATCHed
   * straight back to it, and `adopt` runs on every `loadMe()`.
   */
  adopt(user: CurrentUser): void {
    const style = asMagazineStyle(user.preferences.magazineStyle);
    if (style === null) return;
    this.cache(style);
  }

  /**
   * Cache included: unlike the language, this is per-account, so leaving it set
   * would show the next account the previous one's reader.
   */
  reset(): void {
    localStorage.removeItem(MAGAZINE_STYLE_KEY);
    this.style.set('boxed');
    this.saveFailed.set(false);
  }

  private cache(style: MagazineStyle): void {
    localStorage.setItem(MAGAZINE_STYLE_KEY, style);
    this.style.set(style);
  }

  private cached(): MagazineStyle {
    return asMagazineStyle(localStorage.getItem(MAGAZINE_STYLE_KEY)) ?? 'boxed';
  }
}
```

- [ ] **Step 7: Wire it into auth**

In `frontend/src/app/core/auth.service.ts`, add `magazineStyle: string;` to the
`UserPreferences` interface, inject `MagazineStyleService` beside
`PreferencesService`, call `this.magazineStyle.adopt(u)` next to
`this.preferences.adopt(u)`, and `this.magazineStyle.reset()` next to
`this.preferences.reset()`.

- [ ] **Step 8: Run the tests to make sure they pass**

```bash
docker compose exec -T frontend npx jest src/app/core/magazine-style.service.spec.ts
docker compose exec -T frontend npm test
```

Expected: the new file PASSes 8 tests, and the whole suite stays green — the
`UserPreferences` change touches every spec that builds a fake user, so fix any
that the compiler now rejects.

- [ ] **Step 9: Gates and commit**

```bash
cd frontend && npm run check
git add frontend/src/app/core
git commit -m "feat(#723): read and write the magazine style on the account"
```

---

## Task 5: The settings control

**Files:**
- Create: `frontend/src/app/shared/magazine-style-switcher/magazine-style-switcher.component.ts`
- Create: `frontend/src/app/shared/magazine-style-switcher/magazine-style-switcher.component.html`
- Create: `frontend/src/app/shared/magazine-style-switcher/magazine-style-switcher.component.scss`
- Create: `frontend/src/app/shared/magazine-style-switcher/magazine-style-switcher.component.spec.ts`
- Modify: `frontend/src/app/settings/preferences-section.component.{ts,html}`
- Modify: `frontend/public/i18n/en.json`, `frontend/public/i18n/de.json`

**Interfaces:**
- Consumes: `MagazineStyleService` from Task 4.
- Produces: `<app-magazine-style-switcher />`, selector
  `app-magazine-style-switcher`.

- [ ] **Step 1: Add the copy**

In `frontend/public/i18n/en.json`, inside `settings`:

```json
    "magazineStyle": "Magazine style",
    "magazineStyleHint": "How the magazine view draws an entry.",
    "magazineStyleSaveFailed": "The magazine style could not be saved to your account.",
    "magazineStyleBoxed": "Boxed",
    "magazineStyleAiry": "Airy",
```

and in `de.json`, at the same place:

```json
    "magazineStyle": "Magazin-Stil",
    "magazineStyleHint": "Wie die Magazinansicht einen Eintrag darstellt.",
    "magazineStyleSaveFailed": "Der Magazin-Stil konnte nicht in deinem Konto gespeichert werden.",
    "magazineStyleBoxed": "Mit Rahmen",
    "magazineStyleAiry": "Luftig",
```

Match the surrounding entries' key order and indentation. Check the file's own
address form — if the neighbouring German strings use "Sie", follow them instead
of the "du" written above.

- [ ] **Step 2: Write the failing test**

Create `magazine-style-switcher.component.spec.ts`:

```typescript
import { ComponentFixture, TestBed } from '@angular/core/testing';
import { By } from '@angular/platform-browser';
import { of } from 'rxjs';
import { MagazineStyleService } from '../../core/magazine-style.service';
import { MAGAZINE_STYLE_WRITER } from '../../core/magazine-style-writer';
import { MagazineStyleSwitcherComponent } from './magazine-style-switcher.component';
import { provideTranslocoTesting } from '../../../testing/transloco-testing';

describe('MagazineStyleSwitcherComponent', () => {
  let fixture: ComponentFixture<MagazineStyleSwitcherComponent>;
  let service: MagazineStyleService;

  beforeEach(async () => {
    localStorage.clear();
    await TestBed.configureTestingModule({
      imports: [MagazineStyleSwitcherComponent, provideTranslocoTesting()],
      providers: [{ provide: MAGAZINE_STYLE_WRITER, useValue: { write: () => of(true) } }],
    }).compileComponents();
    fixture = TestBed.createComponent(MagazineStyleSwitcherComponent);
    service = TestBed.inject(MagazineStyleService);
    fixture.detectChanges();
  });

  function buttons(): HTMLButtonElement[] {
    return fixture.debugElement.queryAll(By.css('button')).map((d) => d.nativeElement);
  }

  it('offers exactly the two styles', () => {
    expect(buttons()).toHaveLength(2);
  });

  it('marks the active style pressed', () => {
    expect(buttons()[0].getAttribute('aria-pressed')).toBe('true');
    expect(buttons()[1].getAttribute('aria-pressed')).toBe('false');
  });

  it('switches the style on click', () => {
    buttons()[1].click();
    fixture.detectChanges();

    expect(service.style()).toBe('airy');
    expect(buttons()[1].getAttribute('aria-pressed')).toBe('true');
  });
});
```

`provideTranslocoTesting()` goes in `imports`, not `providers` — that is how
`language-switcher.component.spec.ts` uses it, and this control is its twin.

- [ ] **Step 3: Run it to make sure it fails**

Run: `docker compose exec -T frontend npx jest src/app/shared/magazine-style-switcher`
Expected: FAIL, cannot resolve the component.

- [ ] **Step 4: Write the component**

`magazine-style-switcher.component.ts`:

```typescript
// src/app/shared/magazine-style-switcher/magazine-style-switcher.component.ts
import { ChangeDetectionStrategy, Component, inject } from '@angular/core';
import { TranslocoPipe } from '@jsverse/transloco';
import { MAGAZINE_STYLES } from '../../core/magazine-style';
import { MagazineStyleService } from '../../core/magazine-style.service';

@Component({
  selector: 'app-magazine-style-switcher',
  imports: [TranslocoPipe],
  templateUrl: './magazine-style-switcher.component.html',
  styleUrl: './magazine-style-switcher.component.scss',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class MagazineStyleSwitcherComponent {
  protected readonly magazineStyle = inject(MagazineStyleService);
  protected readonly styles = MAGAZINE_STYLES;
}
```

`magazine-style-switcher.component.html`:

```html
<div class="seg" role="group" [attr.aria-label]="'settings.magazineStyle' | transloco">
  @for (s of styles; track s) {
    <button
      type="button"
      [class.on]="magazineStyle.style() === s"
      [attr.aria-pressed]="magazineStyle.style() === s"
      (click)="magazineStyle.set(s)"
    >
      {{ 'settings.magazineStyle' + (s === 'boxed' ? 'Boxed' : 'Airy') | transloco }}
    </button>
  }
</div>
```

`magazine-style-switcher.component.scss` — the same `.seg` the language switcher
uses. Copy it verbatim from
`frontend/src/app/shared/language-switcher/language-switcher.component.scss`.
This is the second occurrence, not the third, so a copy is correct here; if a
third segmented control ever appears, that is the moment to extract a shared
`.seg` partial, and leave a comment in both files saying so.

- [ ] **Step 5: Run the tests to make sure they pass**

Run: `docker compose exec -T frontend npx jest src/app/shared/magazine-style-switcher`
Expected: PASS, 3 tests.

- [ ] **Step 6: Add the settings row**

In `preferences-section.component.html`, after the reading-focus row and inside
the same `app-settings-group`:

```html
    <app-settings-row
      [title]="'settings.magazineStyle' | transloco"
      [description]="'settings.magazineStyleHint' | transloco"
      [stackable]="true"
    >
      <app-magazine-style-switcher />
    </app-settings-row>
    @if (magazineStyle.saveFailed()) {
      <app-error-banner class="state" [message]="'settings.magazineStyleSaveFailed' | transloco" />
    }
```

No `labelFor`: a segmented group is not one control, which is why the language
row has none either.

In `preferences-section.component.ts`, import
`MagazineStyleSwitcherComponent`, add it to `imports`, and add
`readonly magazineStyle = inject(MagazineStyleService);`.

- [ ] **Step 7: Run the suite and the gate**

```bash
docker compose exec -T frontend npm test
cd frontend && npm run check
```

Expected: green, including the existing `preferences-section.component.spec.ts`.

- [ ] **Step 8: Commit**

```bash
git add frontend/src/app/shared/magazine-style-switcher frontend/src/app/settings frontend/public/i18n
git commit -m "feat(#723): add the magazine style control to preferences"
```

---

## Task 6: Move the card chrome into custom properties (no visual change)

This task changes no pixel. It exists on its own so a reviewer can confirm that,
and so the refactor is not tangled with the feature in one diff.

**Files:**
- Modify: `frontend/src/app/reader/entry-list/entry-list.component.scss:460-466`
- Modify: `frontend/src/app/reader/magazine/entry-hero.component.scss`
- Modify: `frontend/src/app/reader/magazine/entry-wide.component.scss`
- Modify: `frontend/src/app/reader/magazine/entry-split.component.scss`
- Modify: `frontend/src/app/reader/magazine/entry-thumb.component.scss`
- Modify: `frontend/src/app/reader/magazine/entry-kicker.component.scss`
- Modify: `frontend/src/app/reader/magazine/entry-quote.component.scss`
- Modify: `frontend/src/app/reader/magazine/source-group.component.scss`

**Interfaces:**
- Consumes: nothing.
- Produces: five custom properties declared on `.rows.magazine` and read by the
  seven block stylesheets — `--card-bg`, `--card-border`, `--card-radius`,
  `--card-pad`.

- [ ] **Step 1: Declare the properties**

In `entry-list.component.scss`, inside the existing `.rows.magazine` block:

```scss
  /* The card's chrome in one place, so airy is a different set of VALUES
     rather than a branch in seven block components (#723). */
  --card-bg: var(--surface-2);
  --card-border: 1px solid var(--border);
  --card-radius: var(--radius-lg);
  --card-pad: var(--space-3);
  --card-pad-x: var(--space-4);
```

- [ ] **Step 2: Consume them in each block**

In each of the seven stylesheets, replace the literal chrome with the
properties. In `entry-hero.component.scss` the `.hero` rule becomes:

```scss
.hero {
  background: var(--card-bg);
  border: var(--card-border);
  border-radius: var(--card-radius);
  overflow: hidden;
  cursor: pointer;
}
```

Do the same for `.wide`, `.split`, `.thumb`, `.kicker-card`, `.quote` and
`.group`. Where a block declares its own padding, route that through
`--card-pad` too:

- `.split`, `.thumb`, `.kicker-card`, `.quote`: `padding: var(--card-pad);`
- `.hero .body`, `.wide .body`: `padding: var(--card-pad) var(--card-pad-x);`
- `source-group`'s `.ghead` and `.more`: `padding: var(--card-pad) var(--card-pad-x);`
- `entry-compact`'s `.compact`: `padding: var(--card-pad) var(--card-pad-x);`

Both axes go through a property. Only the vertical one would leave hero, wide,
the group header and compact rows inset by 16px in airy while split, thumb and
kicker sat flush at the column edge — one design with two left margins.

Leave every other declaration in those files untouched — the hover rules, the
image sizing, the line clamps and their comments all stay exactly as they are.

- [ ] **Step 3: Prove nothing moved**

Run: `docker compose exec -T frontend npm test`, then start the stack and open
the magazine view. Compare against a screenshot taken before this task in both
light and dark. The four values are identical to what the literals were, so any
visible difference is a mistake in this task, not a design decision.

- [ ] **Step 4: Gate and commit**

```bash
cd frontend && npm run check
git add frontend/src/app/reader
git commit -m "refactor(#723): give the magazine card chrome one home"
```

---

## Task 7: The airy style

**Files:**
- Modify: `frontend/src/app/reader/entry-list/entry-list.component.html:272`
- Modify: `frontend/src/app/reader/entry-list/entry-list.component.ts`
- Modify: `frontend/src/app/reader/entry-list/entry-list.component.scss` (the
  `.rows.magazine` block and `magazine-gap-close` at :440-448)
- Modify: `frontend/src/app/reader/magazine/entry-quote.component.scss`
- Modify: `frontend/src/app/reader/magazine/source-group.component.scss`
- Modify: `frontend/src/app/reader/magazine/entry-compact.component.scss`
- Modify: the six other block stylesheets (the hover rule only)
- Modify: `frontend/src/app/reader/entry-list/entry-list.component.spec.ts`

**Interfaces:**
- Consumes: `MagazineStyleService.style()` from Task 4, `--card-*` from Task 6.
- Produces: the `.airy` class on `.rows.magazine`.

- [ ] **Step 1: Write the failing test**

In `entry-list.component.spec.ts`, following the file's existing setup:

```typescript
  it('draws the magazine boxed by default', () => {
    const rows = fixture.debugElement.query(By.css('.rows.magazine'));

    expect(rows.nativeElement.classList).not.toContain('airy');
  });

  it('marks the magazine airy when the account chose it', () => {
    TestBed.inject(MagazineStyleService).set('airy');
    fixture.detectChanges();

    const rows = fixture.debugElement.query(By.css('.rows.magazine'));
    expect(rows.nativeElement.classList).toContain('airy');
  });
```

The spec must already render in the magazine layout for these to find `.rows.magazine`
— check how the file sets the layout and reuse that, and provide
`MAGAZINE_STYLE_WRITER` as in Task 5.

- [ ] **Step 2: Run it to make sure it fails**

Run: `docker compose exec -T frontend npx jest src/app/reader/entry-list`
Expected: the second test FAILs; `airy` is never on the element.

- [ ] **Step 3: Bind the class**

In `entry-list.component.ts`, add
`protected readonly magazineStyle = inject(MagazineStyleService);`.
In `entry-list.component.html:272`, add to the `.rows.magazine` element:

```html
    [class.airy]="magazineStyle.style() === 'airy'"
```

- [ ] **Step 4: Run the tests to make sure they pass**

Run: `docker compose exec -T frontend npx jest src/app/reader/entry-list`
Expected: PASS.

- [ ] **Step 5: Make the gap a variable the animation can read**

In `entry-list.component.scss`, inside `.rows.magazine`, add
`--magazine-gap: var(--space-3);` beside the `--card-*` block, change
`gap: var(--space-3);` to `gap: var(--magazine-gap);`, and change the keyframe
at :446 from `margin-top: calc(-1 * var(--space-3));` to:

```scss
    margin-top: calc(-1 * var(--magazine-gap));
```

Extend that keyframe's comment with a sentence: the gap is a variable because
airy widens it, and a hardcoded close would leave half a gap behind there.

- [ ] **Step 6: Write the airy values and structure**

In `entry-list.component.scss`, after the `.rows.magazine` block:

```scss
/* The gap grows because it is now the block's only padding AND the bed the rule
   sits in: centred in a 12px gap the rule leaves 6px each side and reads as a
   strikethrough (#723). */
.rows.magazine.airy {
  --card-bg: none;
  --card-border: 0;
  --card-radius: 0;
  --card-pad: 0;
  --card-pad-x: 0;
  --magazine-gap: var(--space-5);
}

/* On the SLOT, so no block stylesheet learns about the rule and none is drawn
   above the first or below the last. It skips `app-run-header`, which is not a
   slot: that header is already a rule, and a second one beside it reads wrong. */
.rows.magazine.airy > .magazine-slot + .magazine-slot {
  border-top: 1px solid var(--border);
}
```

- [ ] **Step 7: Keep the images rounded**

Airy sets `--card-radius: 0`, and `.hero`/`.wide` clipped their image with
`overflow: hidden` on the card. Give those images their own corners, in
`entry-hero.component.scss` and `entry-wide.component.scss`:

```scss
/* Boxed clipped this to the card's corners; airy has no card, so the image
   carries the radius itself and both styles round it alike (#723). */
.img {
  border-radius: var(--radius-lg);
}
```

Add the declaration to the existing `.img` rule rather than writing a second one.
`.split` and `.thumb` already round their images at `var(--radius)` and need no
change.

- [ ] **Step 8: Give hover somewhere to live**

Airy has no border to darken. In each of the seven block stylesheets, beside the
existing pointer-guarded hover rule, add its airy counterpart.

The selector is `:host-context(.airy)`, never a bare `.airy` descendant: the
class sits on `.rows.magazine`, outside the block's own template, and Angular's
emulated encapsulation will not match across that boundary. In
`entry-hero.component.scss`:

```scss
/* No border to darken, so hover marks what the block opens: its title — the
   idiom `.source:hover` already uses here. Pointer-only (#704). */
@media (hover: hover) {
  :host-context(.airy) .hero:hover .title {
    text-decoration: underline;
    text-underline-offset: 3px;
  }
}
```

Repeat per block, swapping the block class and, for `.quote`, targeting `.pull`
rather than `.title` — the pull quote is what that block leads with. In
`entry-compact.component.scss` the airy rule must also switch the existing fill
off, because `var(--surface-0)` is the canvas colour in airy and the fill would
be invisible:

```scss
@media (hover: hover) {
  :host-context(.airy) .compact:hover {
    background: none;
  }

  :host-context(.airy) .compact:hover .title {
    text-decoration: underline;
    text-underline-offset: 3px;
  }
}
```

Verify the first of these in a browser before writing the other six — if
`:host-context` does not reach the block, none of the seven will.

- [ ] **Step 9: Mark the pull quote**

In `entry-quote.component.scss`:

```scss
/* The card is what said "quote". On a text-rich feed a quote is one block in
   five among other title-and-dek text, so it keeps the blockquote mark instead.
   No radius: a single-sided border never takes one (#723). */
:host-context(.airy) .quote {
  border-left: 2px solid var(--border-strong);
  border-radius: 0;
  padding-left: var(--space-3);
}
```

- [ ] **Step 10: Inset the group's dividers**

In `source-group.component.scss`, the group loses its box with the rest, so its
nesting has to show some other way:

```scss
/* The box is gone, so indentation carries the nesting: the full-width slot rule
   separates ENTRIES, and an indented item with an indented rule is one item
   inside a group. The header keeps its full-width rule — it belongs to the
   group, it does not sit between two of its items (#723). */
:host-context(.airy) .item,
:host-context(.airy) .more {
  margin-left: var(--space-3);
}
```

The indent moves the item's text and its rule together, which is the point: this
is the indentation half of the decision, not a rule-only inset.

- [ ] **Step 11: Look at all seven blocks**

Start the stack and open the magazine view. Switch to airy in Settings. Screenshot
`hero`, `wide`, `split`, `thumb`, `kicker`, `quote`, a `source-group` and a
`compact` row inside one, in light and in dark. Check specifically: the rule is
centred in the gap; no rule above the first block or below the last; no plain
rule beside the run header's accent rule; the quote's left rule reads as a
quote mark; hover underlines the title and nothing else; images are still
rounded; a group's dividers are inset and its text is not.

A rule that reads wrong passes every assertion that can be written for it, so
this step is the real test and is not optional.

- [ ] **Step 12: Scan the dev log**

```bash
ls -t backend/var/log/dev-*.log | head -1
```

Read the newest file. Deprecations and swallowed errors surface only there.

- [ ] **Step 13: Gate and commit**

```bash
docker compose exec -T frontend npm test
cd frontend && npm run check
git add frontend/src/app/reader
git commit -m "feat(#723): draw the magazine airy when the account chose it"
```

---

## Task 8: The Playwright smoke and the pull request

**Files:**
- Create: `frontend/e2e/magazine-airy-style.spec.ts`

**Interfaces:**
- Consumes: everything above.
- Produces: nothing further.

- [ ] **Step 1: Write the smoke**

Create `frontend/e2e/magazine-airy-style.spec.ts`. The spec owns the data it
asserts on and leaves the fixture as it found it — it stubs both `/api/me` and
`/api/entries` rather than reading whatever the seeded account happens to hold,
which is how #96's specs rotted.

```typescript
// e2e/magazine-airy-style.spec.ts
import { test, expect, Page } from '@playwright/test';

// The seeded e2e admin, as in `magazine-kicker-one-line.spec.ts`.
const ADMIN_EMAIL = process.env.E2E_ADMIN_EMAIL ?? 'e2e-admin@example.com';
const ADMIN_PASSWORD = process.env.E2E_ADMIN_PASSWORD ?? 'e2e-admin-password-123';

function entry(id: number, source: string) {
  return {
    id,
    title: `Fixture entry ${id}`,
    url: `https://fixtures.invalid/${id}`,
    author: null,
    summary: 'A summary long enough that the planner has a dek to place.',
    contentHtml: '<p>Fixture body.</p>',
    imageUrl: null,
    imageWidth: null,
    imageHeight: null,
    publishedAt: '2026-08-01T12:50:34+00:00',
    createdAt: '2026-08-01T12:50:34+00:00',
    subscriptionId: 1,
    source,
    faviconUrl: null,
    isHidden: false,
    isFavorite: false,
    isKept: false,
  };
}

/** Enough entries, across enough sources, that the planner emits several slots. */
const ENTRIES = [
  entry(1, 'Heise'),
  entry(2, 'Tagesschau'),
  entry(3, 'Heise'),
  entry(4, 'Der Spiegel'),
  entry(5, 'Tagesschau'),
  entry(6, 'Der Spiegel'),
];

/**
 * `/api/me` is rewritten, not replaced: the profile carries roles, mail state and
 * AI readiness the shell reads on boot, and a malformed one 401s into a login
 * redirect.
 */
async function stubAccount(page: Page, magazineStyle: 'boxed' | 'airy'): Promise<void> {
  await page.route(
    (url) => url.pathname === '/api/me',
    async (route) => {
      if (route.request().method() !== 'GET') return route.fallback();
      const response = await route.fetch();
      const profile = await response.json();
      await route.fulfill({
        response,
        json: { ...profile, preferences: { ...profile.preferences, magazineStyle } },
      });
    },
  );

  await page.route(
    (url) => url.pathname === '/api/entries',
    async (route) => {
      if (route.request().method() !== 'GET') return route.fallback();
      await route.fulfill({ status: 200, json: { entries: ENTRIES, nextCursor: null } });
    },
  );
}

async function signInAsAdmin(page: Page): Promise<boolean> {
  await page.goto('/login');
  await page.locator('input[type=email]').fill(ADMIN_EMAIL);
  await page.locator('input[type=password]').fill(ADMIN_PASSWORD);
  await page.getByRole('button', { name: 'Sign in' }).click();

  const sidebar = page.getByRole('navigation', { name: 'Feeds' });
  const loginError = page.getByRole('alert');
  await expect(sidebar.or(loginError)).toBeVisible();
  return sidebar.isVisible();
}

/** Computed styles: a class proves the binding fired, not that it landed. */
async function borderWidths(page: Page): Promise<{ block: string; slot: string }> {
  const rows = page.locator('.rows.magazine');
  await expect(rows).toBeVisible();

  const block = page.locator('.rows.magazine app-entry-thumb .thumb, .rows.magazine app-entry-kicker .kicker-card').first();
  await expect(block).toBeVisible();

  const slot = page.locator('.rows.magazine .magazine-slot').nth(1);
  await expect(slot).toBeVisible();

  return {
    block: await block.evaluate((el) => getComputedStyle(el).borderTopWidth),
    slot: await slot.evaluate((el) => getComputedStyle(el).borderTopWidth),
  };
}

test('the airy magazine drops the card border and rules the slots instead', async ({ page }) => {
  await stubAccount(page, 'airy');
  const signedIn = await signInAsAdmin(page);
  test.skip(!signedIn, 'seeded admin login unavailable (run app:e2e:seed-admin against the stack)');

  await expect(page.locator('.rows.magazine')).toHaveClass(/airy/);

  const widths = await borderWidths(page);
  expect(widths.block, 'an airy block still draws a card border').toBe('0px');
  expect(widths.slot, 'the hairline rule is missing from the slot').toBe('1px');
});

test('the boxed magazine keeps the card border and rules nothing', async ({ page }) => {
  await stubAccount(page, 'boxed');
  const signedIn = await signInAsAdmin(page);
  test.skip(!signedIn, 'seeded admin login unavailable (run app:e2e:seed-admin against the stack)');

  await expect(page.locator('.rows.magazine')).not.toHaveClass(/airy/);

  const widths = await borderWidths(page);
  expect(widths.block, 'the boxed card lost its border').toBe('1px');
  expect(widths.slot, 'a boxed slot drew a rule it should not have').toBe('0px');
});
```

If `/api/me` cannot be rewritten this way in this Playwright version, set the
style through the Settings UI instead of stubbing it, and keep the same computed
-style assertions — they are the part that matters.

- [ ] **Step 2: Run it**

```bash
cd frontend && npm run e2e
```

Needs the Docker stack up, from this checkout — both suites fail fast and name
the owning path if it is another one.

- [ ] **Step 3: Run every gate one more time**

```bash
cd backend && composer check && composer md && php bin/phpunit
docker compose exec php vendor/bin/phpunit
cd ../frontend && npm run check
cd ../backend && composer infection:diff
```

`composer infection:diff` is what CI gates. An escaped mutant arrives as a PR
annotation on its line; fix the test, never the threshold.

- [ ] **Step 4: Open the pull request**

```bash
git push -u origin feature/723-magazine-airy-style
gh pr create --base develop --title "feat(#723): a boxed or airy magazine entry design" --body "Closes #723 ..."
```

Base is `develop`, never `main`. `Closes #723` auto-closes the issue on merge —
verify afterwards that it did rather than closing it by hand.
