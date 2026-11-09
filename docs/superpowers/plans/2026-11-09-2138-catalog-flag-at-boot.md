# #2138 The reader learns "the catalog has entries" from `/api/me`, not from the whole catalog — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** A reader boot makes no `GET /api/catalog` request. The one fact the reader needs, whether the catalog offers at least one feed, arrives as `catalog.hasEntries` in `/api/me`, and the full catalog loads only on `/discover`.

**Architecture:** `CatalogFeedRepository::hasPickableFeed()` runs one `SELECT EXISTS (…)` over enabled feeds in enabled categories. `MeProfileJson` adds `'catalog' => ['hasEntries' => …]` to every profile response. On the frontend, `CurrentUser` gains `catalog: { hasEntries: boolean }`, and a new read-only `CatalogAvailability` (root) turns `AuthService.user()` / `accountLoadFailed()` into the `resolved` + `hasEntries` pair that `ReaderOnboarding` and `ListEmptyStateComponent` used to read off `CatalogStore`. `CatalogStore` stays, used only by `/discover`. The picker and the admin catalog editor, which both hold the full answer, push it back through `AuthService.markCatalogHasEntries()`, so the flag stays correct for the session.

**Tech Stack:** Symfony 7.4, PHP 8.4, Doctrine ORM 3 / DBAL 4.4 (MySQL 8.4 + SQLite), PHPUnit 12, Infection; Angular 20 standalone + signals, Jest, Playwright.

**Spec:** GitHub issue #2138 (`gh issue view 2138`).

## Global Constraints

- Branch `fix/2138-catalog-flag-at-boot` off `develop`. The checkout may hold another session's work (at planning time `fix/2137-php-fpm-pool-size` was checked out with uncommitted `docker/` and `docs/docker-production.md` changes). Run `git status` and ask before any `checkout`, `reset` or `stash`. Never commit to `develop`. The PR body says `Closes #2138`.
- Commit format `fix(#2138): …`. No Claude attribution, no `Co-Authored-By`, no session links.
- CLAUDE.md is binding. Clean Code: intent-revealing names, no new comment unless a reader would get the code wrong without it. Every touched `backend/src` file is PHPMD-clean. No `@phpstan-ignore`, no baseline, no edits under `backend/tests/PhpStan/`.
- No new `backend/src` class. The query lives in `backend/src/Repository/` (`QueriesLiveInRepositoriesRule`). The controller is not touched (`ThinControllerRule`). The response shape comes from the `src/Http` mapper `MeProfileJson`.
- The wire field is exactly `"catalog": {"hasEntries": <bool>}` on `GET /api/me`, and therefore also on the bodies of `PATCH /api/me`, `/api/me/digest`, `/api/me/preferences`, `/api/me/magazine-style`, `/api/me/sharing` and `/api/me/trending`, which all return `MeProfileJson::of()`. It is a plain JSON boolean behind bearer auth, so `docs/architecture.md` §6 holds: bearer token, stateless, JSON, no browser-only input. A native iOS client reads it as it is.
- `hasPickableFeed()` must be true exactly when `GET /api/catalog` would return a category with a non-empty `feeds` list, that is, when `CatalogCategoryRepository::findEnabledWithFeeds()` holds a category whose `getEnabledFeeds()` is non-empty. The repository test asserts both definitions side by side.
- Frontend: standalone + signals; no hex colours, no inline styles, no i18n change. Frontend Jest runs in the container: `docker compose exec -T frontend npm test` (one file: `docker compose exec -T frontend npx jest <path>`).
- `composer infection:diff` reads committed files only (`--git-diff-filter=AM` against `origin/develop`), so commit before running it.
- Never `docker compose down -v`. Never clear the dev database. Run e2e only from the checkout that ran `docker compose up`.

## Decisions (each verified against the tree)

1. **`/api/me` carries the flag.** What the reader fetches at boot:
   - `authGuard` (`core/auth/auth.guard.ts`) starts `AuthService.ensureUserLoaded()` → `GET /api/me` on every signed-in route, before the shell mounts. `finishSignIn()` reloads it after every sign-in.
   - The shell loads `GET /api/subscriptions` through `SubscriptionsStore`.
   - `GET /api/setup/status` is anonymous and only read on `/login`, `/setup` and in e2e helpers (`SetupApi`, `SetupService`), not by the reader boot, so it is ruled out.

   `/api/me` already carries instance facts beside the account (`mail.enabled`, `ai`, `preferences.digest.timezone`), and "does this instance's catalog offer anything" is the same kind of fact. `/api/subscriptions` is ruled out because it is per-user list data, reloaded after every subscribe, bulk action and import (`SubscriptionsStore.load()`). The flag would add a query to each of those reloads and mix an instance fact into a list payload. `/api/me` is fetched once per session (`ensureUserLoaded` shares it). The extra cost is one indexed `EXISTS` on the profile responses.
2. **Query.** It is native SQL on the DBAL connection inside `CatalogFeedRepository`:
   `SELECT EXISTS (SELECT 1 FROM catalog_feed feed INNER JOIN catalog_category category ON category.id = feed.category_id WHERE feed.enabled = :enabled AND category.enabled = :enabled)`, with `:enabled` bound as `ParameterType::BOOLEAN`.
   - It returns one row on both SQLite and MySQL and stops at the first match.
   - Native SQL rather than a DQL `setMaxResults(1)`: the integer literal would give Infection an equivalent `IncrementInteger` mutant (`1 → 2`) that no test can kill. The `(bool)` cast's removal is a `TypeError` under `strict_types`, so it is killed.
   - Column names come from `CatalogFeed` (`#[ORM\Table(name: 'catalog_feed')]`, `$category` → `category_id` under the `underscore_number_aware` naming strategy) and `CatalogCategory` (`catalog_category`).
3. **No new service class.** `MeProfileJson` already injects collaborators (`MailCapability`, `ActiveAiJson`). It injects `CatalogFeedRepository $catalogFeeds` directly, the same way `CatalogController` injects `CatalogCategoryRepository`. A `Service/Catalog` wrapper would only forward one call and would merge back into its only caller.
4. **Frontend consumers of `CatalogStore.hasEntries()` / `resolved()`.** Found by grep:

   | Consumer | Today | After |
   |---|---|---|
   | `ReaderOnboarding` (redirect, `running`) | `catalog.load()` when subscriptions are empty | `CatalogAvailability` |
   | `ReaderOnboarding` (admin warning) | `catalog.load()` for every admin, on every boot | `CatalogAvailability` |
   | `ListEmptyStateComponent.catalogEmpty` | reads the store, only resolved if the shell loaded it | `CatalogAvailability` |
   | `DiscoverComponent` | `catalog.load()` + renders it | unchanged; it also pushes its answer back (Decision 6) |

   `CatalogStore` is needed only on `/discover`. It stays `providedIn: 'root'` with its identity invalidation (#263) and its post-subscribe `invalidate()`. Narrowing it to a component provider is a separate simplification and out of scope here.
5. **Timing.** Today the redirect waits for subscriptions and then fires `/api/catalog`, so there are two sequential round trips. After the change it waits for subscriptions and `/api/me`. The guard starts `/api/me` before the shell mounts, in parallel with subscriptions, so the redirect decides as fast as before or faster. `running` still reads "unanswered" as running: `CatalogAvailability.resolved()` is false until `/api/me` answers. A failed `/api/me` resolves as "nothing to offer" (`accountLoadFailed()`), exactly as a failed catalog load did: there is no redirect, and no admin warning either, because `isAdmin()` needs a loaded user.

   One visible difference is intended. `ListEmptyStateComponent` used to know the catalog was empty only when the shell had loaded the store, which happened for admins or empty readers. Now it always knows after `/api/me`, so a non-admin with 1–4 subscriptions on an instance with an empty catalog no longer sees a "Browse suggested feeds" link into an empty picker.
6. **Staleness.** The flag describes the instance, not the user, so a stale `user()` from the previous identity still holds the right fact.
   - A user subscribing from `/discover` does not change the catalog's contents, so the flag needs no update. The store's existing `invalidate()` still refreshes the `subscribed` marks.
   - The two views that load the catalog themselves hand their fresher answer to `AuthService.markCatalogHasEntries(hasEntries)`, which follows the precedent of `markPasskeyOfferAnswered()`. It patches `user()` only when the value differs, and the next `loadMe()` overwrites it with server truth.
     - `DiscoverComponent`: an effect, once the store resolves without error.
     - `AdminCatalogComponent`: an effect over its loaded `categories()` / `feeds()`, the rule being "an enabled feed in an enabled category". It covers import (which refetches), create/edit (upsert), toggling `enabled` in the dialogs, and delete. An admin who imports a catalog therefore loses the "catalog is empty" warning without a reload. Today that warning stays until a reload, because the root store never refetches.
7. **e2e stubs.** Three specs answer `/api/me` with a hand-made body, and `CatalogAvailability.hasEntries` would throw on a missing `catalog`. Each gets `catalog: { hasEntries: false }`, which keeps today's behaviour (their `/api/catalog` used to 404, which resolved as empty):
   - `e2e/support/auth.ts` `stubAuthToken`;
   - `e2e/list-header-count-one-line.spec.ts`;
   - `e2e/saved-search-layout.spec.ts`.

   `magazine-airy-style` and `magazine-compact-actions-flush` rewrite the real `/api/me` body and need nothing. `desktop-search-split.spec.ts` stubbed `/api/catalog` with one feed so the signed-in admin sees no warning. That stub goes dead, so it is replaced by an `/api/me` rewrite setting `catalog.hasEntries: true` (the spec owns its data). The `discover-*` specs stub `/api/catalog` for the picker itself and stay.
8. **Docs.** No document describes the boot sequence or the `/api/me` shape. `grep -rn "api/catalog\|hasEntries\|CatalogStore\|api/me" docs README.md` outside `docs/superpowers/` finds only `/api/me/ai/...` in `recommendations-runs.md`. The docs that change are the code docblocks that describe the old wiring: the `CatalogStore` class docblock, `ListEmptyStateComponent.catalogEmpty` and `MeProfileJson`.

## File structure

| File | Change |
|---|---|
| `backend/src/Repository/CatalogFeedRepository.php` | `hasPickableFeed(): bool` |
| `backend/tests/Repository/CatalogFeedRepositoryTest.php` | data-provider test against `findEnabledWithFeeds()`, empty and mixed catalogs |
| `backend/src/Http/MeProfileJson.php` | injects `CatalogFeedRepository $catalogFeeds`, adds `catalog.hasEntries` |
| `backend/tests/Http/MeProfileJsonTest.php` | new constructor arg, `catalog` in the expected arrays, a true case |
| `backend/tests/Controller/Api/MeControllerTest.php` | functional test of the field |
| `frontend/src/app/core/auth/auth.service.ts` (+ `.spec.ts`) | `CurrentUser.catalog`, `markCatalogHasEntries()` |
| `frontend/src/app/reader/feeds/catalog/catalog-availability.ts` (+ `.spec.ts`) | new root service |
| `frontend/src/app/reader/feeds/catalog/catalog.store.ts` | class docblock only |
| typed `CurrentUser` fixtures (7 spec files) | `catalog: { hasEntries: true }` |
| `frontend/src/app/reader/shell/reader-onboarding.service.ts` (+ new `.spec.ts`) | reads `CatalogAvailability`, loads nothing |
| `frontend/src/app/reader/shell/reader-shell.component.spec.ts` | onboarding + admin-warning tests drive `auth.user()` |
| `frontend/src/app/reader/list/list-empty-state/list-empty-state.component.ts` | reads `CatalogAvailability` |
| `frontend/src/app/reader/list/entry-list/entry-list.component.spec.ts` | stubs `CatalogAvailability`, adds the empty-catalog case |
| `frontend/src/app/discover/discover.component.ts` (+ `.spec.ts`) | pushes its answer back |
| `frontend/src/app/admin/catalog/admin-catalog.component.ts` (+ `.spec.ts`) | pushes its answer back |
| `frontend/e2e/support/auth.ts`, `list-header-count-one-line.spec.ts`, `saved-search-layout.spec.ts`, `desktop-search-split.spec.ts` | `/api/me` stubs carry `catalog` |
| `frontend/e2e/boot-without-catalog.spec.ts` | new: an admin boot fires no `/api/catalog` |

---

### Task 1: `CatalogFeedRepository::hasPickableFeed()`

**Files:**
- Modify: `backend/src/Repository/CatalogFeedRepository.php`
- Test: `backend/tests/Repository/CatalogFeedRepositoryTest.php`

**Interfaces:**
- Produces: `App\Repository\CatalogFeedRepository::hasPickableFeed(): bool`, true iff an enabled `CatalogFeed` sits in an enabled `CatalogCategory`.

- [ ] **Step 1: Write the failing tests**

In `CatalogFeedRepositoryTest`, add the imports:

```php
use App\Repository\CatalogCategoryRepository;
use PHPUnit\Framework\Attributes\DataProvider;
```

Add these tests and helpers above `private function catalogFeeds()`:

```php
    /** @return iterable<string, array{bool, bool, bool}> */
    public static function enabledCombinations(): iterable
    {
        yield 'enabled feed in an enabled category' => [true, true, true];
        yield 'disabled feed in an enabled category' => [true, false, false];
        yield 'enabled feed in a disabled category' => [false, true, false];
        yield 'disabled feed in a disabled category' => [false, false, false];
    }

    #[DataProvider('enabledCombinations')]
    public function testHasPickableFeedAgreesWithWhatThePickerShows(
        bool $categoryEnabled,
        bool $feedEnabled,
        bool $pickable,
    ): void {
        $category = new CatalogCategory('pickable', 'Pickable', 'rss_feed', '#6b7280');
        $category->setEnabled($categoryEnabled);
        $feed = new CatalogFeed($category, 'Only Feed', 'https://example.com/only.xml');
        $feed->setEnabled($feedEnabled);
        $this->entityManager->persist($category);
        $this->entityManager->persist($feed);
        $this->entityManager->flush();
        $this->entityManager->clear();

        self::assertSame($pickable, $this->catalogFeeds()->hasPickableFeed());
        self::assertSame($pickable, $this->pickerShowsAFeed());
    }

    public function testAnEmptyCatalogHasNoPickableFeed(): void
    {
        self::assertFalse($this->catalogFeeds()->hasPickableFeed());
    }

    public function testAnEnabledCategoryWithoutFeedsIsNotPickable(): void
    {
        $this->entityManager->persist(new CatalogCategory('empty', 'Empty', 'inbox', '#6b7280'));
        $this->entityManager->flush();

        self::assertFalse($this->catalogFeeds()->hasPickableFeed());
    }

    public function testOnePickableFeedAmongRetiredOnesIsEnough(): void
    {
        $retiredOnly = new CatalogCategory('retired', 'Retired', 'archive', '#6b7280');
        $retired = new CatalogFeed($retiredOnly, 'Retired', 'https://example.com/retired.xml');
        $retired->setEnabled(false);
        $empty = new CatalogCategory('empty', 'Empty', 'inbox', '#6b7280');
        $live = new CatalogCategory('live', 'Live', 'bolt', '#3b82f6');
        $liveFeed = new CatalogFeed($live, 'Live Feed', 'https://example.com/live.xml');
        foreach ([$retiredOnly, $retired, $empty, $live, $liveFeed] as $row) {
            $this->entityManager->persist($row);
        }
        $this->entityManager->flush();
        $this->entityManager->clear();

        self::assertTrue($this->catalogFeeds()->hasPickableFeed());
        self::assertTrue($this->pickerShowsAFeed());
    }

    private function pickerShowsAFeed(): bool
    {
        $categories = self::getContainer()->get(CatalogCategoryRepository::class);
        self::assertInstanceOf(CatalogCategoryRepository::class, $categories);

        return array_any(
            $categories->findEnabledWithFeeds(),
            static fn (CatalogCategory $category): bool => [] !== $category->getEnabledFeeds(),
        );
    }
```

- [ ] **Step 2: Run them to see them fail**

Run (from `backend/`): `php bin/phpunit tests/Repository/CatalogFeedRepositoryTest.php`
Expected: FAIL, `Call to undefined method App\Repository\CatalogFeedRepository::hasPickableFeed()`.

- [ ] **Step 3: Implement**

In `CatalogFeedRepository`, add `use Doctrine\DBAL\ParameterType;` and, after `findEnabledByIds()`:

```php
    /** Whether the picker would offer at least one feed: an enabled feed in an enabled category. */
    public function hasPickableFeed(): bool
    {
        $pickable = $this->getEntityManager()->getConnection()->fetchOne(
            'SELECT EXISTS (SELECT 1 FROM catalog_feed feed'
            . ' INNER JOIN catalog_category category ON category.id = feed.category_id'
            . ' WHERE feed.enabled = :enabled AND category.enabled = :enabled)',
            ['enabled' => true],
            ['enabled' => ParameterType::BOOLEAN],
        );

        \assert(is_numeric($pickable));

        return (bool) $pickable;
    }
```

- [ ] **Step 4: Run the tests, both legs**

Run: `php bin/phpunit tests/Repository/CatalogFeedRepositoryTest.php`
Run: `docker compose exec -T php php bin/phpunit tests/Repository/CatalogFeedRepositoryTest.php`
Expected: PASS on SQLite and on MySQL.

- [ ] **Step 5: Gates and commit**

```bash
cd backend && composer cs && bin/console cache:warmup && composer stan && composer md && composer tramp
git add src/Repository/CatalogFeedRepository.php tests/Repository/CatalogFeedRepositoryTest.php
git commit -m "fix(#2138): the catalog feed repository answers whether the picker has a feed to offer"
```

---

### Task 2: `/api/me` carries `catalog.hasEntries`

**Files:**
- Modify: `backend/src/Http/MeProfileJson.php`
- Test: `backend/tests/Http/MeProfileJsonTest.php`, `backend/tests/Controller/Api/MeControllerTest.php`

**Interfaces:**
- Consumes: `CatalogFeedRepository::hasPickableFeed(): bool` (Task 1).
- Produces: the wire field `catalog: {hasEntries: bool}` on every `MeProfileJson::of()` response. Its key comes last, after `ai`.

- [ ] **Step 1: Write the failing tests**

`MeControllerTest`: add `use App\Entity\CatalogCategory;` and `use App\Entity\CatalogFeed;`, then after `testTheProfileCarriesMailCapabilityAndVerificationState()`:

```php
    public function testTheProfileSaysWhetherTheCatalogOffersAFeed(): void
    {
        $client = self::createClient();
        $this->signIn($client, 'catalog-flag@example.test');

        $client->request('GET', '/api/me');
        self::assertResponseIsSuccessful();
        self::assertSame(['hasEntries' => false], $this->payload($client)['catalog']);

        $category = new CatalogCategory('technology', 'Technology', 'memory', '#3b82f6');
        $this->entityManager()->persist($category);
        $this->entityManager()->persist(
            new CatalogFeed($category, 'The Verge', 'https://www.theverge.com/rss/index.xml'),
        );
        $this->entityManager()->flush();

        $client->request('GET', '/api/me');
        self::assertResponseIsSuccessful();
        self::assertSame(['hasEntries' => true], $this->payload($client)['catalog']);
    }
```

`MeProfileJsonTest`: add `use App\Entity\CatalogCategory;`, `use App\Entity\CatalogFeed;` and `use App\Repository\CatalogFeedRepository;`, and add a constant beside `NO_ACTIVE_CONNECTION`:

```php
    private const array EMPTY_CATALOG = ['hasEntries' => false];
```

In both existing tests, extend the expected array with `'catalog' => self::EMPTY_CATALOG` after `'ai' => self::NO_ACTIVE_CONNECTION`. Add:

```php
    public function testACatalogWithAPickableFeedReportsEntries(): void
    {
        $category = new CatalogCategory('technology', 'Technology', 'memory', '#3b82f6');
        $this->entityManager->persist($category);
        $this->entityManager->persist(
            new CatalogFeed($category, 'The Verge', 'https://www.theverge.com/rss/index.xml'),
        );
        $this->entityManager->flush();
        $user = $this->user();

        self::assertSame(
            [
                ...MeJson::profile($user, false, 'UTC'),
                'ai' => self::NO_ACTIVE_CONNECTION,
                'catalog' => ['hasEntries' => true],
            ],
            $this->profileJson('UTC')->of($user),
        );
    }
```

In `profileJson()`, fetch the repository and pass it third:

```php
        $catalogFeeds = self::getContainer()->get(CatalogFeedRepository::class);
        self::assertInstanceOf(CatalogFeedRepository::class, $catalogFeeds);

        return new MeProfileJson(
            $mail,
            new ActiveAiJson(new RecommendationCapabilitiesJson($engines)),
            $catalogFeeds,
            $instanceTimezone,
        );
```

- [ ] **Step 2: Run them to see them fail**

Run: `php bin/phpunit tests/Http/MeProfileJsonTest.php tests/Controller/Api/MeControllerTest.php`
Expected: FAIL. `MeProfileJson` takes no fourth argument, and the payload has no `catalog` key.

- [ ] **Step 3: Implement**

`backend/src/Http/MeProfileJson.php` becomes:

```php
<?php

declare(strict_types=1);

namespace App\Http;

use App\Entity\User;
use App\Repository\CatalogFeedRepository;
use App\Service\Mail\MailCapability;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/** MeJson::profile plus what /api/me looks up: whether mail is on, the timezone, the active AI connection, whether the catalog offers a feed. */
final readonly class MeProfileJson
{
    public function __construct(
        private MailCapability $mail,
        private ActiveAiJson $activeAi,
        private CatalogFeedRepository $catalogFeeds,
        #[Autowire('%env(string:APP_TIMEZONE)%')]
        private string $instanceTimezone,
    ) {
    }

    /** @return array<string, mixed> */
    public function of(User $user): array
    {
        return [
            ...MeJson::profile($user, $this->mail->isEnabled(), $this->instanceTimezone),
            'ai' => $this->activeAi->of($user),
            'catalog' => ['hasEntries' => $this->catalogFeeds->hasPickableFeed()],
        ];
    }
}
```

- [ ] **Step 4: Run the tests**

Run: `php bin/phpunit tests/Http/MeProfileJsonTest.php tests/Controller/Api/MeControllerTest.php tests/Controller/Api/MeTest.php tests/Controller/Api/ReaderPreferencesControllerTest.php tests/Controller/Api/MeDigestControllerTest.php`
Expected: PASS.

- [ ] **Step 5: Backend gates, both legs, mutation**

```bash
cd backend && composer cs && bin/console cache:warmup && composer stan && composer md && composer tramp
php bin/phpunit
docker compose exec php composer test:parallel
git add src/Http/MeProfileJson.php tests/Http/MeProfileJsonTest.php tests/Controller/Api/MeControllerTest.php
git commit -m "fix(#2138): /api/me says whether the catalog offers a feed"
composer infection:diff
```
Expected: all green. `infection:diff` reports no escaped mutant in `CatalogFeedRepository::hasPickableFeed` or `MeProfileJson::of`. Run PhpStorm inspections (`mcp__phpstorm__lint_files`) on the two `src` files: no ERROR or WARNING.

---

### Task 3: `CurrentUser.catalog`, `AuthService.markCatalogHasEntries()` and `CatalogAvailability`

**Files:**
- Modify: `frontend/src/app/core/auth/auth.service.ts`, `frontend/src/app/core/auth/auth.service.spec.ts`
- Create: `frontend/src/app/reader/feeds/catalog/catalog-availability.ts`, `frontend/src/app/reader/feeds/catalog/catalog-availability.spec.ts`
- Modify (typed fixtures): `frontend/src/app/reader/shell/sidebar/sidebar.component.spec.ts`, `frontend/src/app/reader/shell/sidebar/sidebar-foot.component.spec.ts`, `frontend/src/app/settings/account/email-section.component.spec.ts`, `frontend/src/app/settings/account/account-section.component.spec.ts`, `frontend/src/app/core/auth/admin.guard.spec.ts`, `frontend/src/app/core/preferences/digest.service.spec.ts`, `frontend/src/app/core/preferences/preferences.service.spec.ts`

**Interfaces:**
- Produces:
  - `CurrentUser.catalog: { hasEntries: boolean }`;
  - `AuthService.markCatalogHasEntries(hasEntries: boolean): void`;
  - `CatalogAvailability` (`providedIn: 'root'`) with `resolved: Signal<boolean>` and `hasEntries: Signal<boolean>`.

- [ ] **Step 1: Write the failing specs**

`frontend/src/app/reader/feeds/catalog/catalog-availability.spec.ts`:

```ts
import { TestBed } from '@angular/core/testing';
import { signal } from '@angular/core';
import { AuthService } from '../../../core/auth/auth.service';
import { CatalogAvailability } from './catalog-availability';

describe('CatalogAvailability', () => {
  const user = signal<{ catalog: { hasEntries: boolean } } | null>(null);
  const accountLoadFailed = signal(false);
  let availability: CatalogAvailability;

  beforeEach(() => {
    user.set(null);
    accountLoadFailed.set(false);
    TestBed.configureTestingModule({
      providers: [{ provide: AuthService, useValue: { user, accountLoadFailed } }],
    });
    availability = TestBed.inject(CatalogAvailability);
  });

  it('is unresolved until the account has answered', () => {
    expect(availability.resolved()).toBe(false);
    expect(availability.hasEntries()).toBe(false);
  });

  it('reads the answer off the account', () => {
    user.set({ catalog: { hasEntries: true } });

    expect(availability.resolved()).toBe(true);
    expect(availability.hasEntries()).toBe(true);
  });

  it('resolves a failed account load as nothing to offer', () => {
    accountLoadFailed.set(true);

    expect(availability.resolved()).toBe(true);
    expect(availability.hasEntries()).toBe(false);
  });
});
```

In `auth.service.spec.ts`, add `catalog: { hasEntries: true },` after `mail: { enabled: true },` in `meFixture()` and in the `loadMe populates…` flush body. Add after the `markPasskeyOfferAnswered flips…` test:

```ts
  it('markCatalogHasEntries adopts a fresher catalog answer without any request', () => {
    service.loadMe().subscribe();
    ctrl.expectOne('https://api.test/api/me').flush(meFixture({ passkeyOfferAnswered: true }));

    service.markCatalogHasEntries(false);

    expect(service.user()?.catalog.hasEntries).toBe(false);
  });

  it('markCatalogHasEntries leaves a signed-out session alone', () => {
    service.markCatalogHasEntries(true);

    expect(service.user()).toBeNull();
  });
```

- [ ] **Step 2: Run them to see them fail**

Run: `docker compose exec -T frontend npx jest src/app/reader/feeds/catalog/catalog-availability.spec.ts src/app/core/auth/auth.service.spec.ts`
Expected: FAIL. `./catalog-availability` cannot be resolved, and `markCatalogHasEntries` is not a function.

- [ ] **Step 3: Implement**

In `auth.service.ts`, add the field to `CurrentUser` after `mail: { enabled: boolean };`:

```ts
  catalog: { hasEntries: boolean };
```

and add a method after `markPasskeyOfferAnswered()`:

```ts
  /** Adopts the answer of a view that loaded the catalog itself, fresher than the one `/api/me` gave. */
  markCatalogHasEntries(hasEntries: boolean): void {
    const user = this.user();
    if (!user || user.catalog.hasEntries === hasEntries) return;
    this.user.set({ ...user, catalog: { hasEntries } });
  }
```

Create `frontend/src/app/reader/feeds/catalog/catalog-availability.ts`:

```ts
import { Injectable, computed, inject } from '@angular/core';
import { AuthService } from '../../../core/auth/auth.service';

/** Whether the catalog offers anything to pick, read off `/api/me` so the reader never downloads the catalog to find out (#2138). */
@Injectable({ providedIn: 'root' })
export class CatalogAvailability {
  private readonly auth = inject(AuthService);

  /** A failed account load resolves as nothing to offer: never redirect into a picker that may not load. */
  readonly resolved = computed(() => this.auth.user() !== null || this.auth.accountLoadFailed());

  readonly hasEntries = computed(() => this.auth.user()?.catalog.hasEntries ?? false);
}
```

Fix the typed fixtures. In each of the seven fixture files listed above, add `catalog: { hasEntries: true },` on the line after the fixture's `mail: { enabled: true },`. These are lines 81, 40, 60, 37, 34, 42 and 56 respectively at planning time. `tsc` names any you miss.

- [ ] **Step 4: Run the specs**

Run: `docker compose exec -T frontend npx jest src/app/reader/feeds/catalog/catalog-availability.spec.ts src/app/core/auth`
Expected: PASS.

- [ ] **Step 5: Gate and commit**

```bash
cd frontend && npm run check
git add src/app/core/auth src/app/reader/feeds/catalog/catalog-availability.ts src/app/reader/feeds/catalog/catalog-availability.spec.ts src/app/reader/shell/sidebar src/app/settings/account src/app/core/preferences/digest.service.spec.ts src/app/core/preferences/preferences.service.spec.ts
git commit -m "fix(#2138): the frontend reads whether the catalog has entries off the account"
```

---

### Task 4: The reader boot reads `CatalogAvailability` and loads no catalog

**Files:**
- Modify: `frontend/src/app/reader/shell/reader-onboarding.service.ts`
- Create: `frontend/src/app/reader/shell/reader-onboarding.service.spec.ts`
- Modify: `frontend/src/app/reader/shell/reader-shell.component.spec.ts`
- Modify: `frontend/src/app/reader/list/list-empty-state/list-empty-state.component.ts`
- Modify: `frontend/src/app/reader/list/entry-list/entry-list.component.spec.ts`
- Modify: `frontend/src/app/reader/feeds/catalog/catalog.store.ts` (class docblock)

**Interfaces:**
- Consumes: `CatalogAvailability.resolved()` / `.hasEntries()` (Task 3).
- Produces: `ReaderOnboarding` with the same public surface (`showCatalogEmptyWarning`, `sweeping`, `showFetchProgress`, `running`), now without `CatalogStore`.

- [ ] **Step 1: Write the failing unit spec**

`frontend/src/app/reader/shell/reader-onboarding.service.spec.ts`:

```ts
import { TestBed } from '@angular/core/testing';
import { signal } from '@angular/core';
import { Router } from '@angular/router';
import { AuthService } from '../../core/auth/auth.service';
import { SubscriptionsStore } from '../state/subscriptions.store';
import { RefreshService } from '../state/refresh.service';
import { OnboardingSkip } from '../feeds/catalog/onboarding-skip';
import { ReaderOnboarding } from './reader-onboarding.service';

interface Account {
  roles: string[];
  catalog: { hasEntries: boolean };
}

describe('ReaderOnboarding', () => {
  const navigate = jest.fn().mockResolvedValue(true);
  const user = signal<Account | null>(null);
  const accountLoadFailed = signal(false);
  const auth = {
    user,
    accountLoadFailed,
    isAdmin: () => user()?.roles.includes('ROLE_ADMIN') ?? false,
  };
  const subscriptions = {
    resolved: signal(true),
    error: signal<unknown>(null),
    subscriptions: signal<{ lastFetchedAt: string | null }[]>([]),
  };
  const refresh = { run: jest.fn(), running: signal(false), failure: signal(null) };

  const reader = (hasEntries: boolean): Account => ({ roles: ['ROLE_USER'], catalog: { hasEntries } });
  const admin = (hasEntries: boolean): Account => ({ roles: ['ROLE_ADMIN'], catalog: { hasEntries } });

  function start(): ReaderOnboarding {
    TestBed.configureTestingModule({
      providers: [
        ReaderOnboarding,
        { provide: Router, useValue: { navigate } },
        { provide: AuthService, useValue: auth },
        { provide: SubscriptionsStore, useValue: subscriptions },
        { provide: RefreshService, useValue: refresh },
      ],
    });
    const onboarding = TestBed.inject(ReaderOnboarding);
    TestBed.tick();
    return onboarding;
  }

  beforeEach(() => {
    sessionStorage.clear();
    navigate.mockClear();
    user.set(null);
    accountLoadFailed.set(false);
    subscriptions.resolved.set(true);
    subscriptions.error.set(null);
    subscriptions.subscriptions.set([]);
  });

  it('holds an empty reader open until the account answers, then sends it to the picker', () => {
    const onboarding = start();
    expect(onboarding.running()).toBe(true);
    expect(navigate).not.toHaveBeenCalled();

    user.set(reader(true));
    TestBed.tick();

    expect(navigate).toHaveBeenCalledWith(['/discover'], { replaceUrl: true });
  });

  it('leaves an empty reader alone when the catalog offers nothing', () => {
    user.set(reader(false));
    const onboarding = start();

    expect(onboarding.running()).toBe(false);
    expect(navigate).not.toHaveBeenCalled();
  });

  it('does not redirect when the account cannot be loaded', () => {
    accountLoadFailed.set(true);
    const onboarding = start();

    expect(onboarding.running()).toBe(false);
    expect(onboarding.showCatalogEmptyWarning()).toBe(false);
    expect(navigate).not.toHaveBeenCalled();
  });

  it('does not redirect a reader who skipped the picker this session', () => {
    TestBed.inject(OnboardingSkip).remember();
    user.set(reader(true));
    start();

    expect(navigate).not.toHaveBeenCalled();
  });

  it('warns an admin, and only an admin, about an empty catalog', () => {
    subscriptions.subscriptions.set([{ lastFetchedAt: '2026-07-26T10:00:00+00:00' }]);
    user.set(admin(false));
    const onboarding = start();
    expect(onboarding.showCatalogEmptyWarning()).toBe(true);

    user.set(admin(true));
    expect(onboarding.showCatalogEmptyWarning()).toBe(false);

    user.set(reader(false));
    expect(onboarding.showCatalogEmptyWarning()).toBe(false);
  });
});
```

- [ ] **Step 2: Run it to see it fail**

Run: `docker compose exec -T frontend npx jest src/app/reader/shell/reader-onboarding.service.spec.ts`
Expected: FAIL. The service still injects `CatalogStore`, which needs `HttpClient` (`NullInjectorError: No provider for _HttpClient`).

- [ ] **Step 3: Rewrite `ReaderOnboarding`**

`frontend/src/app/reader/shell/reader-onboarding.service.ts` becomes:

```ts
import { Injectable, computed, effect, inject, signal, untracked } from '@angular/core';
import { Router } from '@angular/router';
import { AuthService } from '../../core/auth/auth.service';
import { SubscriptionsStore } from '../state/subscriptions.store';
import { CatalogAvailability } from '../feeds/catalog/catalog-availability';
import { OnboardingSkip } from '../feeds/catalog/onboarding-skip';
import { RefreshService } from '../state/refresh.service';

@Injectable()
export class ReaderOnboarding {
  private readonly router = inject(Router);
  private readonly auth = inject(AuthService);
  private readonly subscriptions = inject(SubscriptionsStore);
  private readonly catalog = inject(CatalogAvailability);
  private readonly skip = inject(OnboardingSkip);
  private readonly refresh = inject(RefreshService);

  private readonly catalogOffered = computed(
    () => this.catalog.resolved() && this.catalog.hasEntries(),
  );

  readonly showCatalogEmptyWarning = computed(
    () => this.auth.isAdmin() && this.catalog.resolved() && !this.catalog.hasEntries(),
  );

  private readonly awaitingFirstFetch = computed(
    () =>
      this.subscriptions.resolved() &&
      this.subscriptions.subscriptions().length > 0 &&
      this.subscriptions
        .subscriptions()
        .every((subscription) => subscription.lastFetchedAt === null),
  );

  private readonly sweptOnce = signal(false);
  /** Unlike the permanent `sweptOnce` latch, clears once the sweep lands without error. */
  private readonly sweepInFlight = signal(false);
  readonly sweeping = this.sweepInFlight.asReadonly();

  readonly showFetchProgress = computed(() => this.sweeping() && this.refresh.failure() === null);

  /** A failed load also resolves empty; `error()` keeps that from reading as onboarding. */
  private readonly emptySubscriptionsNeedingOnboarding = computed(
    () =>
      this.subscriptions.resolved() &&
      !this.subscriptions.error() &&
      this.subscriptions.subscriptions().length === 0 &&
      !this.skip.wasSkipped(),
  );

  /** An unanswered catalog counts as running: the redirect is still undecided. */
  readonly running = computed(() => {
    if (this.awaitingFirstFetch() || this.sweeping()) return true;
    if (!this.emptySubscriptionsNeedingOnboarding()) return false;
    if (!this.catalog.resolved()) return true;
    return this.catalogOffered();
  });

  constructor() {
    effect(() => {
      if (!this.emptySubscriptionsNeedingOnboarding() || !this.catalogOffered()) return;
      void this.router.navigate(['/discover'], { replaceUrl: true });
    });

    // Driven by state, not a call: run() early-returns while running, so a call could be lost.
    effect(() => {
      if (!this.awaitingFirstFetch() || this.sweptOnce()) return;
      this.sweptOnce.set(true);
      this.sweepInFlight.set(true);
      this.refresh.run();
    });

    effect(() => {
      if (this.sweeping() && !this.refresh.running() && this.refresh.failure() === null) {
        untracked(() => this.sweepInFlight.set(false));
      }
    });
  }
}
```

- [ ] **Step 4: `ListEmptyStateComponent` reads `CatalogAvailability`**

In `list-empty-state.component.ts`:
- replace `import { CatalogStore } from '../../feeds/catalog/catalog.store';` with `import { CatalogAvailability } from '../../feeds/catalog/catalog-availability';`;
- replace `private readonly catalog = inject(CatalogStore);` with `private readonly catalog = inject(CatalogAvailability);`;
- replace the `catalogEmpty` docblock (the four lines starting `True only once the catalog has been resolved`) with `/** Unresolved reads as not-empty, so the /discover link is never hidden on a guess. */`.

`catalogEmpty`'s body (`this.catalog.resolved() && !this.catalog.hasEntries()`) stays.

In `catalog.store.ts`, replace the class docblock (from `/**` through ` */` above `@Injectable`) with:

```ts
/** The catalog the picker renders, fetched once per session; the reader reads `CatalogAvailability` instead (#2138). */
```

- [ ] **Step 5: Update `entry-list.component.spec.ts`**

- Line 11: `import { CatalogStore } from '../../feeds/catalog/catalog.store';` → `import { CatalogAvailability } from '../../feeds/catalog/catalog-availability';`.
- Lines 47–48, the comment above `const catalog`: `// A stub for the two signals \`catalogEmpty\` reads, so the real CatalogAvailability (and AuthService) stay out of this unit test.`
- Line 92: `{ provide: CatalogStore, useValue: catalog },` → `{ provide: CatalogAvailability, useValue: catalog },`.
- In `describe('the suggested-feeds link in the empty state', …)`, add a `beforeEach` and a case:

```ts
    beforeEach(() => {
      subscriptionsStore.resolved.set(true);
      catalog.resolved.set(false);
      catalog.hasEntries.set(false);
    });

    it('leaves it out once the catalog is known to offer nothing', () => {
      subscribedTo(0);
      catalog.resolved.set(true);
      const element = mount(emptyAll).nativeElement as HTMLElement;

      expect(element.querySelector('.empty a')).toBeNull();
    });
```

- [ ] **Step 6: Update `reader-shell.component.spec.ts`**

1. Give every account literal its catalog field (six occurrences: the `auth` double at line 88, the `beforeEach` reset at line 167, and lines 4253, 4260, 4757, 4780):

```bash
cd frontend && sed -i '' "s/email: 'a@b.c', preferences: { passkeyOfferAnswered/email: 'a@b.c', catalog: { hasEntries: false }, preferences: { passkeyOfferAnswered/" src/app/reader/shell/reader-shell.component.spec.ts
grep -c "catalog: { hasEntries: false }, preferences" src/app/reader/shell/reader-shell.component.spec.ts
```
Expected: `6`.

2. Below the `bootWith` helper, add:

```ts
  function catalogOffers(hasEntries: boolean): void {
    auth.user.set({ ...auth.user(), catalog: { hasEntries } });
  }
```

3. Delete `const CATALOG_WITH_FEEDS = { … };` (line 484 onward), which no test uses any more.

4. In `describe('onboarding redirect and first sweep', …)`:
   - `'waits for the current user subscriptions before offering the catalog'`: add `catalogOffers(true);` as the test's first line. The rest stays, including both `ctrl.expectNone('https://api.test/api/catalog')`.
   - Replace the bodies of `'redirects a user with no subscriptions to the picker, replacing the URL'` and `'does not redirect when nobody has imported a catalog yet'`:

```ts
    it('redirects a user with no subscriptions to the picker, replacing the URL', async () => {
      catalogOffers(true);
      const nav = jest.spyOn(TestBed.inject(Router), 'navigate').mockResolvedValue(true);
      const fixture = bootWith([]);
      await fixture.whenStable();
      fixture.detectChanges();
      expect(nav).toHaveBeenCalledWith(['/discover'], { replaceUrl: true });
      ctrl.expectNone('https://api.test/api/catalog');
    });

    it('does not redirect when nobody has imported a catalog yet', async () => {
      const nav = jest.spyOn(TestBed.inject(Router), 'navigate').mockResolvedValue(true);
      const fixture = bootWith([]);
      await fixture.whenStable();
      fixture.detectChanges();
      expect(nav).not.toHaveBeenCalledWith(['/discover'], { replaceUrl: true });
      ctrl.expectNone('https://api.test/api/catalog');
    });
```

   - Delete `'does not redirect when the catalog cannot be loaded'`. Its successor is `'does not redirect when the account cannot be loaded'` in `reader-onboarding.service.spec.ts`.
   - `'does not redirect when the user skipped this session, and does not fetch the catalog'`: add `catalogOffers(true);` after `TestBed.inject(OnboardingSkip).remember();`.

5. Replace `describe('admin empty-catalog warning', …)` with:

```ts
  describe('admin empty-catalog warning', () => {
    const warning = (fixture: ComponentFixture<ReaderShellComponent>) =>
      (fixture.nativeElement as HTMLElement).querySelector('[data-testid="catalog-empty-warning"]');

    it('warns an admin that no catalog has been imported, without fetching it', async () => {
      auth.isAdmin.mockReturnValue(true);
      const fixture = bootWith([
        { ...SUBSCRIPTION_FIXTURE, id: 1, lastFetchedAt: '2026-07-26T10:00:00+00:00' },
      ]);
      await fixture.whenStable();
      fixture.detectChanges();
      expect(warning(fixture)).not.toBeNull();
      expect(warning(fixture)!.querySelector('a')!.getAttribute('href')).toBe(
        '/settings/admin/catalog',
      );
      ctrl.expectNone('https://api.test/api/catalog');
    });

    it('shows an admin no warning once a catalog exists', async () => {
      auth.isAdmin.mockReturnValue(true);
      catalogOffers(true);
      const fixture = bootWith([
        { ...SUBSCRIPTION_FIXTURE, id: 1, lastFetchedAt: '2026-07-26T10:00:00+00:00' },
      ]);
      await fixture.whenStable();
      fixture.detectChanges();
      expect(warning(fixture)).toBeNull();
      ctrl.expectNone('https://api.test/api/catalog');
    });

    it('never shows the warning to a non-admin', async () => {
      const nav = jest.spyOn(TestBed.inject(Router), 'navigate').mockResolvedValue(true);
      const fixture = bootWith([]);
      await fixture.whenStable();
      fixture.detectChanges();
      expect(warning(fixture)).toBeNull();
      expect(nav).not.toHaveBeenCalledWith(['/discover'], { replaceUrl: true });
    });
  });
```

If `ComponentFixture` is not yet imported in the spec, add it to the `@angular/core/testing` import.

Then grep: `grep -n "api/catalog'" src/app/reader/shell/reader-shell.component.spec.ts`. Expected: only `expectNone` lines are left, and the `bootWith` comment at line 445 still reads true.

- [ ] **Step 7: Run the specs**

Run: `docker compose exec -T frontend npx jest src/app/reader/shell src/app/reader/list src/app/reader/feeds/catalog`
Expected: PASS, including the passkey-offer suite, which gates on `onboarding.running()`.

- [ ] **Step 8: Gate and commit**

```bash
cd frontend && npm run check
git add src/app/reader/shell src/app/reader/list src/app/reader/feeds/catalog/catalog.store.ts
git commit -m "fix(#2138): the reader boot decides onboarding and the empty-catalog warning from /api/me"
```

---

### Task 5: The picker and the admin catalog editor keep the flag fresh

**Files:**
- Modify: `frontend/src/app/discover/discover.component.ts`, `frontend/src/app/discover/discover.component.spec.ts`
- Modify: `frontend/src/app/admin/catalog/admin-catalog.component.ts`, `frontend/src/app/admin/catalog/admin-catalog.component.spec.ts`

**Interfaces:**
- Consumes: `AuthService.markCatalogHasEntries(hasEntries: boolean)` (Task 3).

- [ ] **Step 1: Write the failing specs**

`discover.component.spec.ts`: add `import { AuthService } from '../core/auth/auth.service';` and, inside the top-level `describe`:

```ts
  describe('the catalog answer it hands the reader', () => {
    it('reports what it loaded', () => {
      const mark = jest.spyOn(TestBed.inject(AuthService), 'markCatalogHasEntries');

      mountWith(MULTI);
      expect(mark).toHaveBeenLastCalledWith(true);

      mountWith({ categories: [] });
      expect(mark).toHaveBeenLastCalledWith(false);
    });

    it('reports nothing when the catalog fails to load', () => {
      const mark = jest.spyOn(TestBed.inject(AuthService), 'markCatalogHasEntries');
      TestBed.inject(CatalogStore).invalidate();
      const local = TestBed.createComponent(DiscoverComponent);
      local.detectChanges();
      http
        .expectOne('https://api.test/api/catalog')
        .flush({ type: 'x', title: 't', status: 500 }, { status: 500, statusText: 'err' });
      local.detectChanges();

      expect(mark).not.toHaveBeenCalled();
    });
  });
```

`admin-catalog.component.spec.ts`: add `import { AuthService } from '../../core/auth/auth.service';` and:

```ts
  describe('the catalog answer it hands the reader', () => {
    const IMPORT_COUNTS = {
      categoriesCreated: 1,
      categoriesUpdated: 0,
      categoriesRemoved: 0,
      feedsCreated: 1,
      feedsUpdated: 0,
      feedsRemoved: 0,
      lockedSkipped: 0,
    };

    it('reports entries once an import fills an empty catalog', () => {
      const fixture = mountLoaded({ categories: [], feeds: [] });
      const mark = jest.spyOn(TestBed.inject(AuthService), 'markCatalogHasEntries');

      fixture.componentInstance.importBundled();
      ctrl.expectOne('https://api.test/api/admin/catalog/import/bundled').flush(IMPORT_COUNTS);
      ctrl.expectOne('https://api.test/api/admin/catalog').flush(PAYLOAD);
      fixture.detectChanges();

      expect(mark).toHaveBeenLastCalledWith(true);
    });

    it('reports no entries once the last feed is deleted', () => {
      const fixture = mountLoaded();
      const mark = jest.spyOn(TestBed.inject(AuthService), 'markCatalogHasEntries');

      fixture.componentInstance.deleteFeed(fixture.componentInstance.feeds()[0]);
      ctrl.expectOne('https://api.test/api/admin/catalog/feeds/10').flush({});
      fixture.detectChanges();

      expect(mark).toHaveBeenLastCalledWith(false);
    });

    it('does not count a feed whose category is disabled', () => {
      const fixture = mountLoaded();
      const mark = jest.spyOn(TestBed.inject(AuthService), 'markCatalogHasEntries');

      fixture.componentInstance.openCategoryDialog(fixture.componentInstance.categories()[0]);
      dialogClosed.next({ ...PAYLOAD.categories[0], enabled: false });
      fixture.detectChanges();

      expect(mark).toHaveBeenLastCalledWith(false);
    });
  });
```

- [ ] **Step 2: Run them to see them fail**

Run: `docker compose exec -T frontend npx jest src/app/discover/discover.component.spec.ts src/app/admin/catalog/admin-catalog.component.spec.ts`
Expected: FAIL. `mark` is never called.

- [ ] **Step 3: Implement in `DiscoverComponent`**

- Add `untracked` to the `@angular/core` import and `import { AuthService } from '../core/auth/auth.service';`.
- Add the field `private readonly auth = inject(AuthService);` after `private readonly catalog = inject(CatalogStore);`.
- Add, after `syncSelection`:

```ts
  private readonly shareAvailability = effect(() => {
    if (!this.catalog.resolved() || this.catalog.error() !== null) return;
    const hasEntries = this.catalog.hasEntries();
    untracked(() => this.auth.markCatalogHasEntries(hasEntries));
  });
```

- [ ] **Step 4: Implement in `AdminCatalogComponent`**

- Change the `@angular/core` import to `import { Component, ElementRef, OnInit, computed, effect, inject, signal, untracked, viewChild } from '@angular/core';` and add `import { AuthService } from '../../core/auth/auth.service';`.
- Add the field `private readonly auth = inject(AuthService);` after `private readonly i18n = inject(TranslocoService);`.
- Add, after `readonly importBusy = …;`:

```ts
  private readonly fetched = signal(false);

  private readonly offersFeeds = computed(() => {
    const enabledCategories = new Set(
      this.categories()
        .filter((category) => category.enabled)
        .map((category) => category.id),
    );
    return this.feeds().some((feed) => feed.enabled && enabledCategories.has(feed.categoryId));
  });

  private readonly shareAvailability = effect(() => {
    if (!this.fetched()) return;
    const offersFeeds = this.offersFeeds();
    untracked(() => this.auth.markCatalogHasEntries(offersFeeds));
  });
```

- In `fetchCatalog()`'s `next`, add `this.fetched.set(true);` after `this.feeds.set(result.feeds);`.

- [ ] **Step 5: Run the specs**

Run: `docker compose exec -T frontend npx jest src/app/discover src/app/admin/catalog`
Expected: PASS.

- [ ] **Step 6: Gate and commit**

```bash
cd frontend && npm run check
git add src/app/discover src/app/admin/catalog
git commit -m "fix(#2138): the picker and the catalog editor hand the reader their fresher catalog answer"
```

---

### Task 6: e2e stubs carry `catalog`, and a boot proves it fires no `/api/catalog`

**Files:**
- Modify: `frontend/e2e/support/auth.ts`, `frontend/e2e/list-header-count-one-line.spec.ts`, `frontend/e2e/saved-search-layout.spec.ts`, `frontend/e2e/desktop-search-split.spec.ts`
- Create: `frontend/e2e/boot-without-catalog.spec.ts`

**Interfaces:**
- Consumes: the `/api/me` wire field from Task 2 and the frontend from Tasks 3–5.

- [ ] **Step 1: Stubbed `/api/me` bodies**

- `e2e/support/auth.ts`, in `stubAuthToken`: `route.fulfill({ json: { id: 1, email: '', roles: [], preferences: {} } })` → `route.fulfill({ json: { id: 1, email: '', roles: [], preferences: {}, catalog: { hasEntries: false } } })`.
- `e2e/list-header-count-one-line.spec.ts` (line 67): `return json({ email: 'fixture@example.invalid', roles: ['ROLE_USER'] });` → `return json({ email: 'fixture@example.invalid', roles: ['ROLE_USER'], catalog: { hasEntries: false } });`.
- `e2e/saved-search-layout.spec.ts` (line 41): the same replacement.

- [ ] **Step 2: `desktop-search-split.spec.ts` owns its catalog answer through `/api/me`**

Replace the whole `await stubGet(page, '/api/catalog', { … });` call (lines 80–98) with:

```ts
  await page.route(
    (url) => url.pathname === '/api/me',
    async (route) => {
      if (route.request().method() !== 'GET') return route.fallback();
      const response = await route.fetch();
      const profile = await response.json();
      await route.fulfill({ response, json: { ...profile, catalog: { hasEntries: true } } });
    },
  );
```

- [ ] **Step 3: The boot spec**

`frontend/e2e/boot-without-catalog.spec.ts`:

```ts
import { expect, test } from '@playwright/test';
import { signInAsAdmin } from './support/auth';

// An admin boot used to fetch the whole catalog to decide the empty-catalog
// warning; /api/me now carries that answer (#2138).
test('a reader boot asks /api/me, not /api/catalog, whether the catalog has entries', async ({
  page,
}) => {
  const catalogRequests: string[] = [];
  page.on('request', (request) => {
    if (new URL(request.url()).pathname === '/api/catalog') catalogRequests.push(request.url());
  });

  const signedIn = await signInAsAdmin(page);
  test.skip(!signedIn, 'seeded admin login unavailable (run app:e2e:seed-admin against the stack)');

  const me = page.waitForResponse((response) => new URL(response.url()).pathname === '/api/me');
  await page.reload();
  const profile = (await (await me).json()) as { catalog: { hasEntries: boolean } };
  await expect(page.getByRole('navigation', { name: 'Feeds' })).toBeVisible();
  await page.waitForLoadState('networkidle');

  expect(typeof profile.catalog.hasEntries).toBe('boolean');
  expect(catalogRequests).toEqual([]);
});
```

- [ ] **Step 4: Typecheck and lint**

Run: `cd frontend && npm run check`
Expected: PASS. The e2e `tsc` leg covers the new spec. The run itself happens in Task 7, against the rebuilt stack.

- [ ] **Step 5: Commit**

```bash
git add frontend/e2e/support/auth.ts frontend/e2e/list-header-count-one-line.spec.ts frontend/e2e/saved-search-layout.spec.ts frontend/e2e/desktop-search-split.spec.ts frontend/e2e/boot-without-catalog.spec.ts
git commit -m "fix(#2138): e2e stubs carry the catalog flag, and a boot spec proves no /api/catalog request"
```

---

### Task 7: Verify on the Docker dev stack

**Files:** none changed. If a step fails, fix the cause in the task that owns it.

- [ ] **Step 1: Make sure the stack runs this checkout, with the worker stopped**

```bash
docker compose ps
docker compose exec -T php bin/console cache:clear
docker compose ps --status running --services | grep -qx worker && docker compose stop worker
docker compose exec -T php bin/console cache:pool:clear cache.rate_limiter altcha.replay.cache
```
Expected: php, nginx, frontend and mysql are up, and the worker is stopped. The e2e preflight (`backend/bin/e2e-preflight.sh`) must name this checkout, not a worktree. If `:4200` serves a stale chunk, restart the frontend service before going on.

- [ ] **Step 2: Check the wire field**

```bash
docker compose exec -T php bin/console dbal:run-sql "SELECT COUNT(*) AS pickable FROM catalog_feed feed INNER JOIN catalog_category category ON category.id = feed.category_id WHERE feed.enabled = 1 AND category.enabled = 1"
```
Expected: a count. Then sign in on `http://localhost:4200` in the browser pane as the seeded admin. In the network panel, `/api/me` carries `"catalog":{"hasEntries":true}` exactly when that count is above 0, and there is no `/api/catalog` request on the reader. Opening `/discover` does fire `/api/catalog`.

- [ ] **Step 3: Prove the boot spec guards something**

Temporarily add `inject(CatalogStore).load();` as the first line of `ReaderOnboarding`'s constructor (with `import { CatalogStore } from '../feeds/catalog/catalog.store';`). Then run:

```bash
cd frontend && npx playwright test e2e/boot-without-catalog.spec.ts
```
Expected: FAIL. `catalogRequests` holds one URL. Remove both lines again with Edit, not `git checkout`, and confirm `git diff --stat frontend/src/app/reader/shell/reader-onboarding.service.ts` is empty.

- [ ] **Step 4: Run the affected Playwright specs**

```bash
docker compose exec -T php bin/console cache:pool:clear cache.rate_limiter altcha.replay.cache
cd frontend && npx playwright test e2e/boot-without-catalog.spec.ts e2e/onboarding.spec.ts e2e/settings-admin-smoke.spec.ts e2e/desktop-search-split.spec.ts e2e/saved-search-layout.spec.ts e2e/list-header-count-one-line.spec.ts e2e/discover-chips-mobile.spec.ts e2e/discover-rail-count.spec.ts e2e/discover-rail-scroll.spec.ts
```
Expected: all pass. `onboarding.spec.ts` proves that the redirect to `/discover` (zero subscriptions, populated catalog) and the skip path still work on the `/api/me` flag. A `test.skip` for the registration limiter means the limiter was not cleared: clear it and rerun. Any failure is fixed, whatever the branch.

- [ ] **Step 5: Scan the dev log**

```bash
ls -t backend/var/log/dev-*.log | head -1 | xargs tail -n 200 | jq -c 'select(.level_name == "ERROR" or .level_name == "CRITICAL" or (.message | test("eprecat")))'
```
Expected: nothing new from `/api/me` or catalog code.

- [ ] **Step 6: Full gates once more**

```bash
cd backend && composer cs && bin/console cache:warmup && composer stan && composer md && composer tramp
php bin/phpunit
docker compose exec php composer test:parallel
composer infection:diff
cd ../frontend && npm run check && docker compose exec -T frontend npm test && npm run build
```
Expected: all green on SQLite and MySQL, with no escaped mutant in the changed backend files. Restart the worker if it was running before Step 1 (`docker compose start worker`). Then run `/simplify` and open the PR with `Closes #2138`. The PR body names Decisions 1, 5 (the empty-state link difference) and 6 (the admin warning now clears after an import without a reload).
