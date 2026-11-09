# #2130 Catalog favicons load directly from the publisher — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** The onboarding picker stops firing one PHP request per catalog favicon. Each catalog feed stores a resolved icon URL, and the picker hotlinks it with `app-favicon`.

**Architecture:** `CatalogFeed` keeps one icon column, `favicon_url`. It is the renamed `favicon_source_url`, so warmed installs keep their icons without a backfill. The bytes and warm-bookkeeping columns are dropped. A new `CatalogFaviconLookup` service fills `faviconUrl` through the existing `FaviconResolverInterface` for every catalog feed that has none. It runs after an import commits (`CatalogImporter`) and after an admin create or edit (`CatalogFeedEditor`). The entity forgets its icon when its site moves, so a moved site is looked up again. `CatalogJson` returns the stored URL. Everything that served or warmed bytes is deleted: the public route, the warmer, the console command, the admin endpoints and buttons, and the deploy and installer steps.

**Tech Stack:** Symfony 7.4, PHP 8.4, Doctrine ORM 3 / Migrations (MySQL 8.4 + SQLite), PHPUnit 12, Infection; Angular 20 standalone + signals, Jest, Playwright.

**Spec:** GitHub issue #2130 (`gh issue view 2130 --comments`). The owner's comment of 2026-11-09 ("direct for all") is binding and replaces the options in the issue body, including "keep the no external request guarantee".

## Global Constraints

- Branch `fix/2130-catalog-favicons-direct` off `develop`. Check `git status` and ask whether another session is mid-edit before any `checkout`. Never commit to `develop`. The PR body says `Closes #2130`.
- Commit format `fix(#2130): …`. No Claude attribution, no `Co-Authored-By`, no session links.
- CLAUDE.md is binding. Clean Code: intent-revealing names, no new comment unless a reader would get the code wrong without it. Every touched `backend/src` file is PHPMD-clean. No `@phpstan-ignore`, no baseline, no edits under `backend/tests/PhpStan/`.
- Do not touch `frontend/src/app/shared/icon/`; another session is editing it on its own branch. `app-favicon` (`frontend/src/app/shared/favicon/`) is used as-is.
- `faviconUrl` on the wire is an absolute `https://` URL or `null`. No relative path and no browser-only input (docs/architecture.md §6, the iOS client).
- No backup format change. Catalog tables are not part of `backend/src/Service/Backup` (verified: no `catalog` reference there), the catalog OPML document carries no icon attribute, and there is no catalog export.
- Never `docker compose down -v`. Never clear the dev database. Write to the dev DB only through migrations and the app.
- `composer infection:diff` reads committed files only (`--git-diff-filter=AM` against `origin/develop`), so commit before running it.

## Decisions (each verified against the tree)

1. **Existing rows keep their icons.** `catalog_feed.favicon_source_url` (VARCHAR 750) already holds the https icon URL the warmer downloaded: `CatalogFaviconWarmer::store()` → `storeFavicon($icon->sourceUrl, …)`, and `GuardedImageDownloader::download()` returns `new DownloadedImageModel($imageUrl, …)`, i.e. the resolved URL. The migration renames the column to `favicon_url`, keeps its length, and drops `favicon_data`, `favicon_content_type`, `favicon_fetched_at` and `favicon_failed_at`. MySQL and SQLite both support `RENAME COLUMN` and `DROP COLUMN`. CI's SQLite is 3.45, and none of the columns is indexed or referenced by a trigger. The FTS5 triggers (`EntrySearchIndex`) sit on `entry` and `entry_transcript` only, so `EntrySearchIndex::install()` is not needed. The style is per-platform, with `skipIf` on `hasColumn` and an `assertSqlite()` guard, copied from `Version20261105110000`.
2. **The length stays 750, not `Feed::faviconUrl`'s 2048.** On SQLite a length change would need a table rebuild to pass `doctrine:schema:validate`. `FaviconResolver` caps URLs at 2048 (`URL_MAX`), so `CatalogFeed::setFaviconUrl()` drops a longer URL to `null` (`FAVICON_URL_MAX = 750`). One over-long icon must not fail the flush of a whole import on MySQL.
3. **Resolution cost.** `FaviconResolver::resolveAll()` fetches each origin's homepage through `ConcurrentFeedFetcher`: `FETCH_CONCURRENCY=8` overall, `fetch_host_concurrency: 2`, and `ConcurrentRequestEngine::TIMEOUT_SECONDS = 10.0` with `max_duration` 20 s per request. It never throws and always returns `https://<host>/favicon.ico` as a fallback. It returns `null` only for a URL without a host. `CatalogFaviconLookup` therefore:
   - resolves only rows whose `faviconUrl` is `null`;
   - resolves in slices of 25 and flushes after each slice, so a request cut off by Strato's 240 s `max_execution_time` keeps what it found, and a later import resumes with the rest.

   The entity clears `faviconUrl` when its icon site (`siteUrl ?? url`) changes, so "missing" also covers "site changed". Every import also looks up older rows that still have no icon, such as rows the warmer never reached. That is the recovery path for existing installs. The lookup runs after the import transaction commits, so homepage fetches never hold the transaction open. `GET /api/catalog` only reads the column, so the picker never waits on a lookup.
4. **Failure.** A host-less URL stores `null`. A dead icon URL makes `app-favicon`'s `<img>` fire `(error)`, and the component shows its fallback.
5. **Fallback glyph.** The brief says "letter fallback", but `app-favicon` falls back to the `rss_feed` glyph (`favicon.component.html`), the same glyph the sidebar shows for `Feed::faviconUrl`. The picker uses the component unchanged: the owner chose the sidebar's way, and `shared/icon/` is off-limits in this branch. The letter monogram disappears with `MonogramFavicon`.
6. **Tests stay offline.** In the test env, `services_test.yaml` wires `CatalogFaviconLookup` to a new `App\Tests\Support\StubFaviconResolver`. That stub answers `https://<host>/favicon.ico`, records what it was asked, and never touches the network. Without it, `ImportCatalogCommandTest` and `AdminCatalogImportControllerTest`, which import the shipped ~300-feed document, would fetch real homepages. Only the catalog lookup is rewired. `MissingFaviconResolver` keeps the real resolver, which the refresh tests drive through a stubbed `BatchFeedFetcherInterface`.
7. **Admin JSON** replaces `faviconFetchedAt`/`faviconFailedAt` with `faviconUrl`. The admin UI loses the "Fetch missing icons" panel and the per-row refresh button. Re-saving a feed whose site changed already looks its icon up again.
8. **Onboarding e2e** drops the "no request to a publisher domain" listener and assertion on purpose (owner's decision).
9. **Out of scope:** the historical design specs under `docs/superpowers/specs/` that mention `warm-favicons`, and the `.github/workflows/e2e-rot-check.yml` seed step. Its `app:catalog:import` now also looks up icons (~300 homepage fetches in CI, best-effort, never failing the step).

## File structure

| File | Change |
|---|---|
| `backend/src/Entity/CatalogFeed.php` | bytes/status fields and methods out; `faviconUrl` (column `favicon_url`), `getFaviconBaseUrl()`, `setFaviconUrl()`; `setUrl`/`setSiteUrl` forget a moved site's icon |
| `backend/migrations/Version20261109120000.php` | new: rename + drop, MySQL and SQLite, reversible |
| `backend/src/Repository/CatalogFeedRepository.php` | warm-queue methods out |
| `backend/src/Controller/Api/CatalogController.php` | favicon action out |
| `backend/src/Controller/Admin/AdminCatalogController.php` | warm action out |
| `backend/src/Controller/Admin/AdminCatalogFeedController.php` | refresh-favicon action out |
| `backend/src/Http/CatalogJson.php`, `AdminCatalogJson.php` | `faviconUrl` from the entity; `warmReport()` out |
| `backend/config/packages/security.yaml` | favicon `PUBLIC_ACCESS` rule out |
| `backend/src/Service/Catalog/CatalogFaviconLookup.php` | new |
| `backend/src/Service/Catalog/CatalogImporter.php`, `CatalogFeedEditor.php` | call the lookup |
| `backend/src/Service/Catalog/Model/CatalogImportMode.php` | docblock wording |
| `backend/config/services_test.yaml`, `backend/tests/Support/StubFaviconResolver.php` | offline lookup in tests |
| deleted (src) | `Command/WarmCatalogFaviconsCommand.php`, `Http/CatalogFaviconResponse.php`, `Repository/CatalogFaviconDueCriteria.php`, `Service/Catalog/CatalogFaviconSource.php`, `Service/Catalog/CatalogFaviconWarmer.php`, `Service/Catalog/MonogramFavicon.php`, `Service/Catalog/Model/CatalogFaviconModel.php`, `Service/Catalog/Model/CatalogWarmReportModel.php` |
| deleted (tests) | `Command/WarmCatalogFaviconsCommandTest.php`, `Controller/Api/CatalogFaviconControllerTest.php`, `Http/CatalogFaviconResponseTest.php`, `Service/Catalog/CatalogFaviconSourceTest.php`, `Service/Catalog/MonogramFaviconTest.php`, `Service/Catalog/CatalogFaviconWarmerTest.php` |
| frontend picker | `reader/feeds/catalog/catalog.models.ts`, `catalog-api.ts`, `catalog-api.spec.ts`, `discover/discover.component.{ts,html,scss,spec.ts}`, `e2e/onboarding.spec.ts` |
| frontend admin | `admin/admin.models.ts`, `admin/admin-api.ts`, `admin/catalog/admin-catalog.component.{ts,html,scss,spec.ts}`, `admin/catalog/feed-form-dialog.component.spec.ts`, `public/i18n/{en,de}.json` |
| ops/docs | `deploy/strato/activate-release.sh`, `scripts/lib.sh`, `scripts/test/installer-output.test.sh`, `docs/docker-production.md` |

---

### Task 1: Drop the byte cache, the warmer and the favicon routes; reshape `CatalogFeed`

**Files:**
- Modify: `backend/src/Entity/CatalogFeed.php`
- Create: `backend/migrations/Version20261109120000.php`
- Modify: `backend/src/Repository/CatalogFeedRepository.php`
- Modify: `backend/src/Controller/Api/CatalogController.php`, `backend/src/Controller/Admin/AdminCatalogController.php`, `backend/src/Controller/Admin/AdminCatalogFeedController.php`
- Modify: `backend/src/Http/CatalogJson.php`, `backend/src/Http/AdminCatalogJson.php`
- Modify: `backend/config/packages/security.yaml:78-79`
- Delete: the 8 src files and 6 test files listed under "File structure"
- Create: `backend/tests/Entity/CatalogFeedTest.php`
- Modify: `backend/tests/Repository/CatalogFeedRepositoryTest.php`, `backend/tests/Controller/Admin/AdminCatalogControllerTest.php`, `backend/tests/Controller/Api/CatalogControllerTest.php`, `backend/tests/Http/AdminCatalogJsonTest.php`, `backend/tests/Service/Catalog/CatalogImporterTest.php`

**Interfaces:**
- Produces: `CatalogFeed::FAVICON_URL_MAX = 750`, `CatalogFeed::getFaviconUrl(): ?string`, `CatalogFeed::setFaviconUrl(?string $faviconUrl): void`, `CatalogFeed::getFaviconBaseUrl(): string` (`siteUrl ?? url`). `setUrl()`/`setSiteUrl()` set `faviconUrl` to `null` when `getFaviconBaseUrl()` changes. JSON: `CatalogJson` feed `faviconUrl: string|null`, `AdminCatalogJson::feed()` `faviconUrl: string|null` (no `faviconFetchedAt`/`faviconFailedAt`). Routes `api_catalog_favicon`, `api_admin_catalog_warm_favicons`, `api_admin_catalog_feed_favicon` and command `app:catalog:warm-favicons` no longer exist.

- [ ] **Step 1: Write the failing entity test** — `backend/tests/Entity/CatalogFeedTest.php`

```php
<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\CatalogCategory;
use App\Entity\CatalogFeed;
use PHPUnit\Framework\TestCase;

final class CatalogFeedTest extends TestCase
{
    private const string ICON = 'https://www.example.com/favicon.ico';

    public function testTheIconIsLookedUpFromTheSiteWhenThereIsOneAndFromTheFeedOtherwise(): void
    {
        $feed = $this->feed();
        self::assertSame('https://feeds.example.com/rss.xml', $feed->getFaviconBaseUrl());

        $feed->setSiteUrl('https://www.example.com');

        self::assertSame('https://www.example.com', $feed->getFaviconBaseUrl());
    }

    public function testMovingTheSiteForgetsTheIcon(): void
    {
        $feed = $this->feedWithIcon('https://www.example.com');

        $feed->setSiteUrl('https://www.example.org');

        self::assertNull($feed->getFaviconUrl());
    }

    public function testSettingTheSameSiteKeepsTheIcon(): void
    {
        $feed = $this->feedWithIcon('https://www.example.com');

        $feed->setSiteUrl('https://www.example.com');

        self::assertSame(self::ICON, $feed->getFaviconUrl());
    }

    public function testMovingTheFeedUrlForgetsTheIconOfAFeedWithoutASite(): void
    {
        $feed = $this->feedWithIcon(null);

        $feed->setUrl('https://feeds.example.org/rss.xml');

        self::assertNull($feed->getFaviconUrl());
    }

    public function testMovingTheFeedUrlKeepsTheIconOfAFeedWithASite(): void
    {
        $feed = $this->feedWithIcon('https://www.example.com');

        $feed->setUrl('https://feeds.example.org/rss.xml');

        self::assertSame(self::ICON, $feed->getFaviconUrl());
    }

    public function testAnIconUrlAsLongAsTheColumnIsKept(): void
    {
        $icon = 'https://example.com/' . str_repeat('ä', CatalogFeed::FAVICON_URL_MAX - 20);
        $feed = $this->feed();

        $feed->setFaviconUrl($icon);

        self::assertSame($icon, $feed->getFaviconUrl());
    }

    public function testAnIconUrlLongerThanTheColumnIsDropped(): void
    {
        $feed = $this->feed();

        $feed->setFaviconUrl('https://example.com/' . str_repeat('a', CatalogFeed::FAVICON_URL_MAX - 19));

        self::assertNull($feed->getFaviconUrl());
    }

    public function testTheIconCanBeCleared(): void
    {
        $feed = $this->feedWithIcon(null);

        $feed->setFaviconUrl(null);

        self::assertNull($feed->getFaviconUrl());
    }

    private function feed(): CatalogFeed
    {
        return new CatalogFeed(
            new CatalogCategory('technology', 'Technology', 'memory', '#3b82f6'),
            'Example',
            'https://feeds.example.com/rss.xml',
        );
    }

    private function feedWithIcon(?string $siteUrl): CatalogFeed
    {
        $feed = $this->feed();
        $feed->setSiteUrl($siteUrl);
        $feed->setFaviconUrl(self::ICON);

        return $feed;
    }
}
```

`'https://example.com/'` is 20 characters. The `ä` case is 750 characters but 1480 bytes, which kills an `mb_strlen` → `strlen` mutant.

- [ ] **Step 2: Run it and see it fail**

Run (from `backend/`): `php bin/phpunit tests/Entity/CatalogFeedTest.php`
Expected: FAIL / Error, `Call to undefined method App\Entity\CatalogFeed::getFaviconBaseUrl()` and undefined constant `FAVICON_URL_MAX`.

- [ ] **Step 3: Reshape the entity** — replace `backend/src/Entity/CatalogFeed.php` with:

```php
<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\SourceFormat;
use App\Repository\CatalogFeedRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * One onboarding-catalog suggestion. Its URL is a verified direct feed URL, so subscribing skips discovery.
 */
#[ORM\Entity(repositoryClass: CatalogFeedRepository::class)]
#[ORM\Table(name: 'catalog_feed')]
#[ORM\UniqueConstraint(name: 'uniq_catalog_feed_url', columns: ['url'])]
final class CatalogFeed implements PositionedInterface
{
    use PersistedId;

    public const int FAVICON_URL_MAX = 750;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: CatalogCategory::class, inversedBy: 'feeds')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private CatalogCategory $category;

    #[ORM\Column(length: 200)]
    private string $title;

    /** Same 750 limit as Feed::$url, so a catalog row can never be too long to subscribe to. */
    #[ORM\Column(length: 750)]
    private string $url;

    #[ORM\Column(length: 750, nullable: true)]
    private ?string $siteUrl = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $description = null;

    #[ORM\Column(length: 20, options: ['default' => SourceFormat::XML])]
    private string $sourceFormat = SourceFormat::XML;

    #[ORM\Column(options: ['default' => 0])]
    private int $position = 0;

    #[ORM\Column(options: ['default' => true])]
    private bool $enabled = true;

    /**
     * A locked row is the admin's, not the catalog document's: an import will
     * neither overwrite nor delete it. This is what lets an admin add a feed the
     * shipped catalog does not carry and still run a `replace` import.
     */
    #[ORM\Column(options: ['default' => false])]
    private bool $locked = false;

    #[ORM\Column(length: self::FAVICON_URL_MAX, nullable: true)]
    private ?string $faviconUrl = null;

    public function __construct(CatalogCategory $category, string $title, string $url)
    {
        $this->category = $category;
        $this->title = $title;
        $this->url = $url;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getCategory(): CatalogCategory
    {
        return $this->category;
    }

    public function setCategory(CatalogCategory $category): void
    {
        $this->category = $category;
    }

    public function getTitle(): string
    {
        return $this->title;
    }

    public function setTitle(string $title): void
    {
        $this->title = $title;
    }

    public function getUrl(): string
    {
        return $this->url;
    }

    public function setUrl(string $url): void
    {
        $previousBaseUrl = $this->getFaviconBaseUrl();
        $this->url = $url;
        $this->forgetFaviconOnMoveFrom($previousBaseUrl);
    }

    public function getSiteUrl(): ?string
    {
        return $this->siteUrl;
    }

    public function setSiteUrl(?string $siteUrl): void
    {
        $previousBaseUrl = $this->getFaviconBaseUrl();
        $this->siteUrl = $siteUrl;
        $this->forgetFaviconOnMoveFrom($previousBaseUrl);
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(?string $description): void
    {
        $this->description = $description;
    }

    public function getSourceFormat(): string
    {
        return $this->sourceFormat;
    }

    public function setSourceFormat(string $sourceFormat): void
    {
        $this->sourceFormat = $sourceFormat;
    }

    public function getPosition(): int
    {
        return $this->position;
    }

    public function setPosition(int $position): void
    {
        $this->position = $position;
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    public function setEnabled(bool $enabled): void
    {
        $this->enabled = $enabled;
    }

    public function isLocked(): bool
    {
        return $this->locked;
    }

    public function setLocked(bool $locked): void
    {
        $this->locked = $locked;
    }

    public function getFaviconBaseUrl(): string
    {
        return $this->siteUrl ?? $this->url;
    }

    public function getFaviconUrl(): ?string
    {
        return $this->faviconUrl;
    }

    /** An icon URL longer than the column is dropped: on MySQL it would fail the flush of a whole import. */
    public function setFaviconUrl(?string $faviconUrl): void
    {
        $this->faviconUrl = null !== $faviconUrl && mb_strlen($faviconUrl) <= self::FAVICON_URL_MAX
            ? $faviconUrl
            : null;
    }

    // An icon belongs to the site it was looked up from; a moved site waits for the next lookup.
    private function forgetFaviconOnMoveFrom(string $previousBaseUrl): void
    {
        if ($this->getFaviconBaseUrl() !== $previousBaseUrl) {
            $this->faviconUrl = null;
        }
    }
}
```

- [ ] **Step 4: Run the entity test**

Run: `php bin/phpunit tests/Entity/CatalogFeedTest.php`
Expected: PASS (8 tests).

- [ ] **Step 5: Write the migration** — `backend/migrations/Version20261109120000.php`

```php
<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261109120000 extends AbstractMigration
{
    private const array DROPPED_COLUMNS = [
        'favicon_data',
        'favicon_content_type',
        'favicon_fetched_at',
        'favicon_failed_at',
    ];

    public function getDescription(): string
    {
        return 'Catalog feeds keep only their icon URL; the cached bytes and warm bookkeeping go (#2130).';
    }

    public function up(Schema $schema): void
    {
        $this->skipIf(
            $schema->getTable('catalog_feed')->hasColumn('favicon_url'),
            'catalog_feed.favicon_url already exists.',
        );

        if ($this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform) {
            $this->addSql('ALTER TABLE catalog_feed RENAME COLUMN favicon_source_url TO favicon_url');
            $this->addSql(
                'ALTER TABLE catalog_feed DROP favicon_data, DROP favicon_content_type, '
                . 'DROP favicon_fetched_at, DROP favicon_failed_at',
            );

            return;
        }

        $this->assertSqlite();
        $this->addSql('ALTER TABLE catalog_feed RENAME COLUMN favicon_source_url TO favicon_url');
        foreach (self::DROPPED_COLUMNS as $column) {
            $this->addSql('ALTER TABLE catalog_feed DROP COLUMN ' . $column);
        }
    }

    public function down(Schema $schema): void
    {
        $this->skipIf(
            !$schema->getTable('catalog_feed')->hasColumn('favicon_url'),
            'catalog_feed.favicon_url does not exist.',
        );

        if ($this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform) {
            $this->addSql('ALTER TABLE catalog_feed RENAME COLUMN favicon_url TO favicon_source_url');
            $this->addSql(
                'ALTER TABLE catalog_feed ADD favicon_data LONGBLOB DEFAULT NULL, '
                . 'ADD favicon_content_type VARCHAR(100) DEFAULT NULL, '
                . 'ADD favicon_fetched_at DATETIME DEFAULT NULL, ADD favicon_failed_at DATETIME DEFAULT NULL',
            );

            return;
        }

        $this->assertSqlite();
        $this->addSql('ALTER TABLE catalog_feed RENAME COLUMN favicon_url TO favicon_source_url');
        $this->addSql('ALTER TABLE catalog_feed ADD COLUMN favicon_data BLOB DEFAULT NULL');
        $this->addSql('ALTER TABLE catalog_feed ADD COLUMN favicon_content_type VARCHAR(100) DEFAULT NULL');
        $this->addSql('ALTER TABLE catalog_feed ADD COLUMN favicon_fetched_at DATETIME DEFAULT NULL');
        $this->addSql('ALTER TABLE catalog_feed ADD COLUMN favicon_failed_at DATETIME DEFAULT NULL');
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

The column types in `down()` match `Version20260726120000`, which created them.

- [ ] **Step 6: Delete the warm pipeline, the byte route and their tests**

```bash
cd backend
git rm src/Command/WarmCatalogFaviconsCommand.php \
  src/Http/CatalogFaviconResponse.php \
  src/Repository/CatalogFaviconDueCriteria.php \
  src/Service/Catalog/CatalogFaviconSource.php \
  src/Service/Catalog/CatalogFaviconWarmer.php \
  src/Service/Catalog/MonogramFavicon.php \
  src/Service/Catalog/Model/CatalogFaviconModel.php \
  src/Service/Catalog/Model/CatalogWarmReportModel.php \
  tests/Command/WarmCatalogFaviconsCommandTest.php \
  tests/Controller/Api/CatalogFaviconControllerTest.php \
  tests/Http/CatalogFaviconResponseTest.php \
  tests/Service/Catalog/CatalogFaviconSourceTest.php \
  tests/Service/Catalog/MonogramFaviconTest.php \
  tests/Service/Catalog/CatalogFaviconWarmerTest.php
```

`ConsoleOption::limit()` stays, because `CheckCatalogUrlsCommand` still calls it. `GuardedImageDownloader` and `HtmlEscape` stay too, since other services use them.

- [ ] **Step 7: Strip the controllers**

`backend/src/Controller/Api/CatalogController.php` becomes:

```php
<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Entity\User;
use App\Http\CatalogJson;
use App\Repository\CatalogCategoryRepository;
use App\Repository\FeedRepository;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

#[Route('/api/catalog')]
final readonly class CatalogController
{
    public function __construct(
        private CatalogCategoryRepository $categories,
        private FeedRepository $feeds,
    ) {
    }

    #[Route('', name: 'api_catalog_list', methods: ['GET'])]
    public function list(#[CurrentUser] User $user): JsonResponse
    {
        return new JsonResponse(CatalogJson::many(
            $this->categories->findEnabledWithFeeds(),
            $this->feeds->subscribedUrlSetForUser($user->requireId()),
        ));
    }
}
```

`backend/src/Controller/Admin/AdminCatalogController.php` becomes:

```php
<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Http\AdminCatalogJson;
use App\Repository\CatalogCategoryRepository;
use App\Repository\CatalogFeedRepository;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/admin/catalog')]
final readonly class AdminCatalogController
{
    public function __construct(
        private CatalogCategoryRepository $categories,
        private CatalogFeedRepository $feeds,
    ) {
    }

    #[Route('', name: 'api_admin_catalog_list', methods: ['GET'])]
    public function list(): JsonResponse
    {
        return new JsonResponse(AdminCatalogJson::listing(
            $this->categories->findAllOrdered(),
            $this->feeds->findAllOrdered(),
        ));
    }
}
```

In `backend/src/Controller/Admin/AdminCatalogFeedController.php`:
- remove `use App\Service\Catalog\CatalogFaviconWarmer;`;
- remove the constructor parameter `private CatalogFaviconWarmer $warmer,`;
- delete the whole `refreshFavicon()` action together with its docblock (the `#[Route('/{id}/favicon', name: 'api_admin_catalog_feed_favicon', …)]` block at the end of the class).

The constructor is left with `CatalogFeedRepository $feeds` and `CatalogFeedEditor $editor`.

- [ ] **Step 8: Strip the repository** — in `backend/src/Repository/CatalogFeedRepository.php`, delete:
- `findNeedingFavicon()`;
- `countNeedingFavicon()`;
- `resetFaviconFreshness()`;
- the private `needingFaviconQueryBuilder()`;
- `use Doctrine\ORM\QueryBuilder;`.

`getById()`, `findAllOrdered()`, `findEnabledByIds()` and `nextPositionInCategory()` stay.

- [ ] **Step 9: Map `faviconUrl` from the entity**

`backend/src/Http/CatalogJson.php`:
- delete the class docblock (it describes the removed endpoint);
- in `feed()`, replace `'faviconUrl' => '/api/catalog/feeds/' . $feed->getId() . '/favicon',` with:

```php
            'faviconUrl' => $feed->getFaviconUrl(),
```

`backend/src/Http/AdminCatalogJson.php`:
- remove `use App\Service\Catalog\Model\CatalogWarmReportModel;`;
- replace the class docblock with `/** The admin view of the catalog: every row, enabled or not. */`;
- delete `warmReport()`;
- in `feed()`, replace the two lines `'faviconFetchedAt' => …` and `'faviconFailedAt' => …` with:

```php
            'faviconUrl' => $feed->getFaviconUrl(),
```

- [ ] **Step 10: Drop the public firewall rule** — in `backend/config/packages/security.yaml`, delete these two lines (78-79):

```yaml
        # Catalog favicons are public image bytes for plain <img> tags, which cannot send the JWT; no user data.
        - { path: '^/api/catalog/feeds/\d+/favicon$', roles: PUBLIC_ACCESS }
```

- [ ] **Step 11: Update the surviving tests**

`backend/tests/Repository/CatalogFeedRepositoryTest.php`:
- delete `use App\Repository\CatalogFaviconDueCriteria;` and `private const string NOW = …`;
- delete the tests `testFindNeedingFaviconAppliesStaleAndRetryThresholdsAndSkipsDisabledFeeds`, `testCountNeedingFaviconCountsTheRowsFindNeedingFaviconReturns` and `testFindNeedingFaviconRespectsLimit`;
- delete the helpers `faviconCriteria()` and `persistFaviconQueue()`.

`backend/tests/Controller/Admin/AdminCatalogControllerTest.php`:
- delete the imports of `GuardedImageDownloader`, `GuardedImageDownloaderInterface`, `GuardedImageUnavailableException`, `FaviconResolver` and `FaviconResolverInterface`;
- delete `stubFaviconServicesToFail()` and `testWarmingReportsASliceAndWhatIsLeft()`;
- replace `testAdminCanCreateAFeedAndRefreshItsFavicon()` with:

```php
    public function testAdminCanCreateAFeed(): void
    {
        $client = self::createClient();
        $headers = $this->jsonBearerFor($this->factory()->create('admin2@example.com', roles: ['ROLE_ADMIN']));

        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $category = new CatalogCategory('science', 'Science', 'science', '#14b8a6');
        $entityManager->persist($category);
        $entityManager->flush();

        $client->request(
            'POST',
            '/api/admin/catalog/feeds',
            server: $headers,
            content: json_encode([
                'categoryId' => $category->getId(),
                'title' => 'Quanta Magazine',
                'url' => 'https://api.quantamagazine.org/feed/',
                'siteUrl' => 'https://www.quantamagazine.org',
                'description' => 'Maths and physics reporting',
                'enabled' => true,
            ], \JSON_THROW_ON_ERROR),
        );

        self::assertResponseStatusCodeSame(201);
        self::assertNotNull(
            $entityManager->getRepository(CatalogFeed::class)->findOneBy(['title' => 'Quanta Magazine']),
        );
    }
```

`backend/tests/Controller/Api/CatalogControllerTest.php`, in `testCategoriesAndFeedsComeBackInOrderWithoutDisabledRows()`:
- after `$verge->setSiteUrl('https://www.theverge.com');` add `$verge->setFaviconUrl('https://www.theverge.com/favicon.ico');`;
- replace the closing `assertSame('/api/catalog/feeds/' . $verge->getId() . '/favicon', …)` with:

```php
        self::assertSame('https://www.theverge.com/favicon.ico', $body['categories'][0]['feeds'][0]['faviconUrl']);
        self::assertIsArray($body['categories'][1]);
        self::assertIsArray($body['categories'][1]['feeds']);
        self::assertIsArray($body['categories'][1]['feeds'][0]);
        self::assertNull($body['categories'][1]['feeds'][0]['faviconUrl']);
```

`backend/tests/Http/AdminCatalogJsonTest.php`:
- delete `use App\Service\Catalog\Model\CatalogWarmReportModel;` and `testTheWarmReportMapsEveryCount()`;
- add:

```php
    public function testAFeedCarriesItsIconUrlAndNoWarmBookkeeping(): void
    {
        $category = new CatalogCategory('technology', 'Technology', 'memory', '#3b82f6');
        $feed = new CatalogFeed($category, 'The Verge', 'https://example.com/verge.xml');
        $feed->setFaviconUrl('https://example.com/favicon.ico');

        $json = AdminCatalogJson::feed($feed);

        self::assertSame('https://example.com/favicon.ico', $json['faviconUrl']);
        self::assertArrayNotHasKey('faviconFetchedAt', $json);
        self::assertArrayNotHasKey('faviconFailedAt', $json);
    }
```

`backend/tests/Service/Catalog/CatalogImporterTest.php`: replace `testReimportingUpdatesInPlaceAndKeepsTheCachedFavicon()` with:

```php
    public function testReimportingUpdatesInPlaceAndKeepsTheIconItAlreadyHas(): void
    {
        $this->importer()->import(
            $this->document([['title' => 'The Verge', 'url' => 'https://www.theverge.com/rss/index.xml']]),
            CatalogImportMode::Merge,
        );
        $feed = $this->storedFeed('https://www.theverge.com/rss/index.xml');
        $feed->setFaviconUrl('https://cdn.theverge.com/icon.png');
        $this->entityManager->flush();

        $result = $this->importer()->import(
            $this->document([['title' => 'The Verge (renamed)', 'url' => 'https://www.theverge.com/rss/index.xml']]),
            CatalogImportMode::Merge,
        );

        self::assertSame(0, $result->feedsCreated);
        self::assertSame(1, $result->feedsUpdated);
        $reloaded = $this->storedFeed('https://www.theverge.com/rss/index.xml');
        self::assertSame('The Verge (renamed)', $reloaded->getTitle());
        self::assertSame('https://cdn.theverge.com/icon.png', $reloaded->getFaviconUrl());
    }
```

and add the helper next to `importer()`:

```php
    private function storedFeed(string $url): CatalogFeed
    {
        $this->entityManager->clear();
        $feed = $this->entityManager->getRepository(CatalogFeed::class)->findOneBy(['url' => $url]);
        self::assertNotNull($feed);

        return $feed;
    }
```

- [ ] **Step 12: Confirm nothing references the removed code**

Run (repo root):
```bash
git grep -n "CatalogFavicon\|MonogramFavicon\|CatalogWarmReport\|WarmCatalogFavicons\|warm-favicons\|storeFavicon\|recordFaviconFailure\|getFaviconBytes\|faviconFetchedAt\|faviconFailedAt\|FaviconSourceUrl" -- backend
```
Expected: no output.

Run (from `backend/`): `bin/console debug:router | grep -i favicon`
Expected: no output.

- [ ] **Step 13: Verify the migration from empty on both platforms**

`$SCRATCH` is your session scratchpad directory.

```bash
cd backend
DATABASE_URL="sqlite:///$SCRATCH/migration-check-2130.db" php bin/console doctrine:migrations:migrate --no-interaction
DATABASE_URL="sqlite:///$SCRATCH/migration-check-2130.db" php bin/console doctrine:schema:validate
DATABASE_URL="sqlite:///$SCRATCH/migration-check-2130.db" php bin/console doctrine:migrations:migrate prev --no-interaction
DATABASE_URL="sqlite:///$SCRATCH/migration-check-2130.db" php bin/console doctrine:migrations:migrate --no-interaction
```
Expected: the first and last runs end on `Version20261109120000`. `schema:validate` reports `[OK] The database schema is in sync with the mapping files.` `prev` runs the `down()` without error.

```bash
cd ..
docker compose exec -e DATABASE_URL="mysql://root:root@mysql:3306/feedreader_migration_check_2130?serverVersion=8.4&charset=utf8mb4" php sh -c \
  'bin/console doctrine:database:drop --force --if-exists && bin/console doctrine:database:create \
   && bin/console doctrine:migrations:migrate --no-interaction && bin/console doctrine:schema:validate \
   && bin/console doctrine:migrations:migrate prev --no-interaction && bin/console doctrine:migrations:migrate --no-interaction \
   && bin/console doctrine:database:drop --force'
```
Expected: the same result on MySQL. The scratch database `feedreader_migration_check_2130` is dropped at the end. The dev `feedreader` database is not touched here; Task 6 migrates it.

- [ ] **Step 14: Run the backend gates**

```bash
cd backend
composer cs
bin/console cache:warmup && composer stan
composer md | grep -E "CatalogFeed\.php|CatalogFeedRepository|CatalogController|AdminCatalogController|AdminCatalogFeedController|CatalogJson|AdminCatalogJson"
composer tramp
php bin/phpunit
```
Expected: cs, stan and tramp clean. The `composer md` filter prints nothing. phpunit is green. Run PhpStorm `lint_files` on every changed PHP file and fix every ERROR and WARNING.

Start the MySQL leg alongside it: `docker compose exec php composer test:parallel` (green).

- [ ] **Step 15: Commit and gate on mutation**

```bash
git add -A backend
git commit -m "fix(#2130): catalog feeds keep only their icon URL; the byte cache, warmer and favicon routes go"
cd backend && composer infection:diff
```
Expected: MSI at or above `minMsi`. Kill any escaped mutant on the new lines with a test, never with an ignore.

---

### Task 2: Look icons up on import and on admin save

**Files:**
- Create: `backend/src/Service/Catalog/CatalogFaviconLookup.php`
- Modify: `backend/src/Service/Catalog/CatalogImporter.php`, `backend/src/Service/Catalog/CatalogFeedEditor.php`, `backend/src/Service/Catalog/Model/CatalogImportMode.php`
- Create: `backend/tests/Support/StubFaviconResolver.php`
- Modify: `backend/config/services_test.yaml`
- Create: `backend/tests/Service/Catalog/CatalogFaviconLookupTest.php`
- Modify: `backend/tests/Service/Catalog/CatalogImporterTest.php`, `backend/tests/Service/Catalog/CatalogFeedEditorTest.php`, `backend/tests/Controller/Admin/AdminCatalogControllerTest.php`

**Interfaces:**
- Consumes: from Task 1, `CatalogFeed::getFaviconUrl()`, `setFaviconUrl()`, `getFaviconBaseUrl()` and the forget-on-move rule. Also `App\Service\Fetch\FaviconResolver\FaviconResolverInterface::resolveAll(array<int, string> $baseUrlsByFeedId): array<int, string|null>`.
- Produces: `App\Service\Catalog\CatalogFaviconLookup::lookUpMissing(array<CatalogFeed> $feeds): void`, which resolves rows with no icon 25 at a time and flushes per slice. `App\Tests\Support\StubFaviconResolver`, public in the test container, with `public array $resolvedBaseUrls` (`list<string>`) and `public array $batchSizes` (`list<int>`).

- [ ] **Step 1: Add the offline resolver stub** — `backend/tests/Support/StubFaviconResolver.php`

```php
<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Service\Fetch\FaviconResolver\FaviconResolverInterface;

/** Answers every site with its host's /favicon.ico without a request, and null for a URL without a host. */
final class StubFaviconResolver implements FaviconResolverInterface
{
    /** @var list<string> */
    public array $resolvedBaseUrls = [];

    /** @var list<int> */
    public array $batchSizes = [];

    public function resolveAll(array $baseUrlsByFeedId): array
    {
        $this->batchSizes[] = \count($baseUrlsByFeedId);
        $icons = [];
        foreach ($baseUrlsByFeedId as $key => $baseUrl) {
            $this->resolvedBaseUrls[] = $baseUrl;
            $host = parse_url($baseUrl, \PHP_URL_HOST);
            $icons[$key] = \is_string($host) && '' !== $host ? 'https://' . $host . '/favicon.ico' : null;
        }

        return $icons;
    }
}
```

- [ ] **Step 2: Write the failing lookup test** — `backend/tests/Service/Catalog/CatalogFaviconLookupTest.php`

```php
<?php

declare(strict_types=1);

namespace App\Tests\Service\Catalog;

use App\Entity\CatalogCategory;
use App\Entity\CatalogFeed;
use App\Service\Catalog\CatalogFaviconLookup;
use App\Tests\Support\StubFaviconResolver;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;

final class CatalogFaviconLookupTest extends TestCase
{
    public function testAFeedWithoutAnIconGetsTheIconOfItsSite(): void
    {
        $feed = $this->feed('https://feeds.example.com/rss.xml');
        $feed->setSiteUrl('https://www.example.com');
        $resolver = new StubFaviconResolver();

        $this->lookup($resolver)->lookUpMissing([$feed]);

        self::assertSame('https://www.example.com/favicon.ico', $feed->getFaviconUrl());
        self::assertSame(['https://www.example.com'], $resolver->resolvedBaseUrls);
    }

    public function testAFeedWithoutASiteIsLookedUpByItsFeedUrl(): void
    {
        $feed = $this->feed('https://feeds.example.com/rss.xml');
        $resolver = new StubFaviconResolver();

        $this->lookup($resolver)->lookUpMissing([$feed]);

        self::assertSame('https://feeds.example.com/favicon.ico', $feed->getFaviconUrl());
    }

    public function testAFeedThatHasAnIconIsNotLookedUpAgain(): void
    {
        $feed = $this->feed('https://feeds.example.com/rss.xml');
        $feed->setFaviconUrl('https://cdn.example.com/icon.png');
        $resolver = new StubFaviconResolver();
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects($this->never())->method('flush');

        (new CatalogFaviconLookup($resolver, $entityManager))->lookUpMissing([$feed]);

        self::assertSame('https://cdn.example.com/icon.png', $feed->getFaviconUrl());
        self::assertSame([], $resolver->resolvedBaseUrls);
    }

    public function testASiteWithoutAHostKeepsNoIcon(): void
    {
        $feed = $this->feed('not a url');

        $this->lookup(new StubFaviconResolver())->lookUpMissing([$feed]);

        self::assertNull($feed->getFaviconUrl());
    }

    public function testFeedsAreLookedUpAndSavedTwentyFiveAtATime(): void
    {
        $feeds = array_map(
            fn (int $index): CatalogFeed => $this->feed(\sprintf('https://site%d.example.com/rss.xml', $index)),
            range(1, 26),
        );
        $resolver = new StubFaviconResolver();
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects($this->exactly(2))->method('flush');

        (new CatalogFaviconLookup($resolver, $entityManager))->lookUpMissing($feeds);

        self::assertSame([25, 1], $resolver->batchSizes);
        self::assertSame('https://site1.example.com/favicon.ico', $feeds[0]->getFaviconUrl());
        self::assertSame('https://site26.example.com/favicon.ico', $feeds[25]->getFaviconUrl());
    }

    private function lookup(StubFaviconResolver $resolver): CatalogFaviconLookup
    {
        return new CatalogFaviconLookup($resolver, $this->createStub(EntityManagerInterface::class));
    }

    private function feed(string $url): CatalogFeed
    {
        return new CatalogFeed(new CatalogCategory('technology', 'Technology', 'memory', '#3b82f6'), 'Feed', $url);
    }
}
```

- [ ] **Step 3: Run it and see it fail**

Run: `php bin/phpunit tests/Service/Catalog/CatalogFaviconLookupTest.php`
Expected: Error, `Class "App\Service\Catalog\CatalogFaviconLookup" not found`.

- [ ] **Step 4: Implement the lookup** — `backend/src/Service/Catalog/CatalogFaviconLookup.php`

```php
<?php

declare(strict_types=1);

namespace App\Service\Catalog;

use App\Entity\CatalogFeed;
use App\Service\Fetch\FaviconResolver\FaviconResolverInterface;
use Doctrine\ORM\EntityManagerInterface;

/** Looks up an icon URL for each catalog feed that has none, one concurrent batch of homepages per slice. */
final readonly class CatalogFaviconLookup
{
    // Saved per slice, so a request cut off mid-lookup keeps the icons it already found.
    private const int SLICE_SIZE = 25;

    public function __construct(
        private FaviconResolverInterface $faviconResolver,
        private EntityManagerInterface $entityManager,
    ) {
    }

    /** @param array<CatalogFeed> $feeds */
    public function lookUpMissing(array $feeds): void
    {
        $missing = array_filter($feeds, static fn (CatalogFeed $feed): bool => null === $feed->getFaviconUrl());
        foreach (array_chunk($missing, self::SLICE_SIZE) as $slice) {
            $this->lookUp($slice);
            $this->entityManager->flush();
        }
    }

    /** @param list<CatalogFeed> $slice */
    private function lookUp(array $slice): void
    {
        $icons = $this->faviconResolver->resolveAll(
            array_map(static fn (CatalogFeed $feed): string => $feed->getFaviconBaseUrl(), $slice),
        );
        foreach ($slice as $index => $feed) {
            $feed->setFaviconUrl($icons[$index] ?? null);
        }
    }
}
```

`array_chunk()` re-indexes each slice from 0, which keeps the `resolveAll()` keys aligned with `$slice`.

- [ ] **Step 5: Run the lookup test**

Run: `php bin/phpunit tests/Service/Catalog/CatalogFaviconLookupTest.php`
Expected: PASS (5 tests).

- [ ] **Step 6: Keep the container's lookup offline in tests** — append to `backend/config/services_test.yaml`:

```yaml

    # Public: catalog tests read what it was asked, and its one consumer would otherwise inline it away.
    App\Tests\Support\StubFaviconResolver:
        public: true

    # Every catalog import and admin save looks icons up from the stub, never from ~300 real homepages.
    App\Service\Catalog\CatalogFaviconLookup:
        autowire: true
        arguments:
            $faviconResolver: '@App\Tests\Support\StubFaviconResolver'
```

- [ ] **Step 7: Write the failing importer and editor tests**

In `backend/tests/Service/Catalog/CatalogImporterTest.php`, add `use App\Tests\Support\StubFaviconResolver;`, then add these tests and the helper:

```php
    public function testAnImportLooksUpTheIconOfEveryNewFeedFromItsSite(): void
    {
        $this->importer()->import($this->parsed(
            '<outline text="Technology" key="technology" icon="memory" color="#3b82f6">'
            . '<outline type="rss" text="Verge" xmlUrl="https://feeds.verge.example.com/rss.xml"'
            . ' htmlUrl="https://www.verge.example.com/"/>'
            . '<outline type="rss" text="No site" xmlUrl="https://nosite.example.com/rss.xml"/>'
            . '</outline>',
        ), CatalogImportMode::Merge);

        self::assertSame(
            'https://www.verge.example.com/favicon.ico',
            $this->storedFeed('https://feeds.verge.example.com/rss.xml')->getFaviconUrl(),
        );
        self::assertSame(
            'https://nosite.example.com/favicon.ico',
            $this->storedFeed('https://nosite.example.com/rss.xml')->getFaviconUrl(),
        );
    }

    public function testAReimportDoesNotLookUpAnIconTheFeedAlreadyHas(): void
    {
        $document = $this->document([['title' => 'Kept', 'url' => 'https://kept.example.com/rss.xml']]);
        $this->importer()->import($document, CatalogImportMode::Merge);
        $this->faviconResolver()->resolvedBaseUrls = [];

        $this->importer()->import($document, CatalogImportMode::Merge);

        self::assertSame([], $this->faviconResolver()->resolvedBaseUrls);
    }

    public function testAnImportThatMovesAFeedsSiteLooksItsIconUpAgain(): void
    {
        $withSite = fn (string $siteUrl): ParsedCatalogModel => $this->parsed(\sprintf(
            '<outline text="Technology" key="technology" icon="memory" color="#3b82f6">'
            . '<outline type="rss" text="Mover" xmlUrl="https://feeds.mover.example.com/rss.xml" htmlUrl="%s"/>'
            . '</outline>',
            $siteUrl,
        ));
        $this->importer()->import($withSite('https://old.mover.example.com/'), CatalogImportMode::Merge);

        $this->importer()->import($withSite('https://new.mover.example.com/'), CatalogImportMode::Merge);

        self::assertSame(
            'https://new.mover.example.com/favicon.ico',
            $this->storedFeed('https://feeds.mover.example.com/rss.xml')->getFaviconUrl(),
        );
    }

    public function testAnImportAlsoLooksUpARowItDoesNotMentionThatStillHasNoIcon(): void
    {
        $category = new CatalogCategory('kept', 'Kept', 'memory', '#3b82f6');
        $this->entityManager->persist($category);
        $this->entityManager->persist(new CatalogFeed($category, 'Iconless', 'https://iconless.example.com/rss.xml'));
        $this->entityManager->flush();

        $this->importer()->import(
            $this->document([['title' => 'New', 'url' => 'https://new.example.com/rss.xml']]),
            CatalogImportMode::Merge,
        );

        self::assertSame(
            'https://iconless.example.com/favicon.ico',
            $this->storedFeed('https://iconless.example.com/rss.xml')->getFaviconUrl(),
        );
    }

    private function faviconResolver(): StubFaviconResolver
    {
        $resolver = self::getContainer()->get(StubFaviconResolver::class);
        self::assertInstanceOf(StubFaviconResolver::class, $resolver);

        return $resolver;
    }
```

In `backend/tests/Service/Catalog/CatalogFeedEditorTest.php`, add:

```php
    public function testCreateLooksUpTheIconOfTheFeedsSite(): void
    {
        $feed = $this->editor()->create(new CatalogFeedDetailsModel(
            $this->category('feed_editor_icon')->requireId(),
            'Icon',
            'https://feeds.icon.feed-editor.example.com/rss',
            'https://www.icon.feed-editor.example.com',
        ));

        self::assertSame('https://www.icon.feed-editor.example.com/favicon.ico', $this->reload($feed)->getFaviconUrl());
    }

    public function testUpdateLooksTheIconUpAgainWhenTheSiteMoves(): void
    {
        $category = $this->category('feed_editor_moved');
        $feed = $this->editor()->create(new CatalogFeedDetailsModel(
            $category->requireId(),
            'Moved',
            'https://feeds.moved.feed-editor.example.com/rss',
            'https://old.moved.feed-editor.example.com',
        ));

        $this->editor()->update($feed, new CatalogFeedDetailsModel(
            $category->requireId(),
            'Moved',
            'https://feeds.moved.feed-editor.example.com/rss',
            'https://new.moved.feed-editor.example.com',
        ));

        self::assertSame('https://new.moved.feed-editor.example.com/favicon.ico', $this->reload($feed)->getFaviconUrl());
    }

    public function testUpdateKeepsTheIconWhenTheSiteStays(): void
    {
        $category = $this->category('feed_editor_stays');
        $details = new CatalogFeedDetailsModel(
            $category->requireId(),
            'Stays',
            'https://feeds.stays.feed-editor.example.com/rss',
            'https://www.stays.feed-editor.example.com',
        );
        $feed = $this->editor()->create($details);
        $feed->setFaviconUrl('https://cdn.stays.feed-editor.example.com/icon.png');
        $this->entityManager->flush();

        $this->editor()->update($feed, new CatalogFeedDetailsModel(
            $category->requireId(),
            'Stays, renamed',
            $details->url,
            $details->siteUrl,
        ));

        self::assertSame('https://cdn.stays.feed-editor.example.com/icon.png', $this->reload($feed)->getFaviconUrl());
    }
```

In `backend/tests/Controller/Admin/AdminCatalogControllerTest.php`, at the end of `testAdminCanCreateAFeed()` (from Task 1), add:

```php
        $created = $this->payload($client)['feed'];
        self::assertIsArray($created);
        self::assertSame('https://www.quantamagazine.org/favicon.ico', $created['faviconUrl']);
```

- [ ] **Step 8: Run them and see them fail**

Run: `php bin/phpunit tests/Service/Catalog/CatalogImporterTest.php tests/Service/Catalog/CatalogFeedEditorTest.php tests/Controller/Admin/AdminCatalogControllerTest.php`
Expected: FAIL on the new tests, for example `Failed asserting that null is identical to 'https://www.verge.example.com/favicon.ico'`. `testAReimportDoesNotLookUpAnIconTheFeedAlreadyHas` passes already, which is fine because it guards the slice filter.

- [ ] **Step 9: Wire the lookup into the importer** — `backend/src/Service/Catalog/CatalogImporter.php` becomes:

```php
<?php

declare(strict_types=1);

namespace App\Service\Catalog;

use App\Repository\CatalogCategoryRepository;
use App\Repository\CatalogFeedRepository;
use App\Service\Catalog\Model\CatalogImportMode;
use App\Service\Catalog\Model\CatalogImportResultModel;
use App\Service\Catalog\Model\ParsedCatalogModel;
use App\Service\Catalog\Pass\CatalogImportPass;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Applies a validated catalog document in one transaction, matching rows by natural key (category key, feed URL) so
 * a surviving feed keeps its icon, then looks up every icon still missing. Locked rows belong to the admin: never
 * overwritten, never removed.
 */
final readonly class CatalogImporter
{
    public function __construct(
        private CatalogCategoryRepository $categories,
        private CatalogFeedRepository $feeds,
        private EntityManagerInterface $entityManager,
        private CatalogFaviconLookup $faviconLookup,
    ) {
    }

    public function import(ParsedCatalogModel $document, CatalogImportMode $mode): CatalogImportResultModel
    {
        $result = $this->entityManager->wrapInTransaction(
            fn (): CatalogImportResultModel => $this->apply($document, $mode),
        );
        // After the commit, so minutes of homepage fetches never hold the import's transaction open.
        $this->faviconLookup->lookUpMissing($this->feeds->findAll());

        return $result;
    }

    private function apply(ParsedCatalogModel $document, CatalogImportMode $mode): CatalogImportResultModel
    {
        $pass = new CatalogImportPass(
            $this->entityManager,
            $this->categories->findAllOrdered(),
            $this->feeds->findAll(),
        );
        $pass->apply($document);
        if (CatalogImportMode::Replace === $mode) {
            $pass->removeUnmentioned();
        }

        $this->entityManager->flush();

        return $pass->result();
    }
}
```

In `backend/src/Service/Catalog/Model/CatalogImportMode.php`, the docblock ends with `…and both keep the cached favicon of any feed whose URL survives.` Change that to `…and both keep the icon of any feed whose URL and site survive.`

- [ ] **Step 10: Wire the lookup into the editor** — in `backend/src/Service/Catalog/CatalogFeedEditor.php`:
- add the constructor parameter `private CatalogFaviconLookup $faviconLookup,` after `private CatalogFeedFactory $feedFactory,`;
- change `create()` and `update()` to:

```php
    public function create(CatalogFeedDetailsModel $details): CatalogFeed
    {
        $feed = $this->feedFactory->create($details);
        $this->entityManager->persist($feed);
        $this->entityManager->flush();
        $this->faviconLookup->lookUpMissing([$feed]);

        return $feed;
    }

    public function update(CatalogFeed $feed, CatalogFeedDetailsModel $details): void
    {
        $feed->setCategory($this->categories->getById($details->categoryId));
        $feed->setTitle($details->title);
        $feed->setUrl($details->url);
        $this->applyEditableFields($feed, $details);
        $this->entityManager->flush();
        $this->faviconLookup->lookUpMissing([$feed]);
    }
```

The edit is flushed before the lookup, so a slow publisher cannot lose the admin's change.

- [ ] **Step 11: Run the tests**

Run: `php bin/phpunit tests/Service/Catalog tests/Controller/Admin tests/Command/ImportCatalogCommandTest.php tests/Entity/CatalogFeedTest.php`
Expected: PASS. No test reaches the network; the `ImportCatalogCommandTest` shipped-document import finishes in about a second.

- [ ] **Step 12: Run the backend gates**

```bash
cd backend
composer cs
bin/console cache:warmup && composer stan
composer md | grep -E "CatalogFaviconLookup|CatalogImporter|CatalogFeedEditor|CatalogImportMode"
composer tramp
php bin/phpunit
```
Expected: clean, with no output from the `md` filter, and phpunit green. Start `docker compose exec php composer test:parallel` alongside it (green). Run PhpStorm `lint_files` on the changed PHP files.

- [ ] **Step 13: Commit and gate on mutation**

```bash
git add -A backend
git commit -m "fix(#2130): look catalog icons up when a feed is imported or saved by an admin"
cd backend && composer infection:diff
```
Expected: at or above `minMsi`, with no escaped mutant on `CatalogFaviconLookup`, `CatalogImporter::import()` or `CatalogFeedEditor::create()/update()`.

---

### Task 3: The picker hotlinks icons with `app-favicon`

**Files:**
- Modify: `frontend/src/app/reader/feeds/catalog/catalog.models.ts`, `frontend/src/app/reader/feeds/catalog/catalog-api.ts`, `frontend/src/app/reader/feeds/catalog/catalog-api.spec.ts`
- Modify: `frontend/src/app/discover/discover.component.ts`, `discover.component.html:85-92`, `discover.component.scss:167-170`, `discover.component.spec.ts`
- Modify: `frontend/e2e/onboarding.spec.ts:200-240`

**Interfaces:**
- Consumes: `GET /api/catalog` feed `faviconUrl: string | null`, an absolute https URL (Task 1). `FaviconComponent` (`app-favicon`, `frontend/src/app/shared/favicon/favicon.component.ts`) with inputs `url: string | null`, `avatarUrl: string | null` and `size: number` (default 16). It renders `<img class="favicon" referrerpolicy="no-referrer" loading="lazy">`, or `<app-icon name="rss_feed">` when `url` is null or the image errors.
- Produces: `CatalogFeedDto.faviconUrl: string | null`. `CatalogApi.load()` returns the payload unmapped.

- [ ] **Step 1: Write the failing specs**

In `frontend/src/app/reader/feeds/catalog/catalog-api.spec.ts`, replace the test `it('resolves favicon paths against the API base', …)` and its two-line comment above it with:

```ts
  it('passes publisher favicon URLs through untouched', () => {
    let categories: CatalogCategoryDto[] = [];
    api.load().subscribe((response) => (categories = response.categories));

    http.expectOne('https://api.test/api/catalog').flush({
      categories: [
        {
          id: 1,
          key: 'technology',
          name: 'Technology',
          feeds: [
            {
              id: 10,
              title: 'A',
              description: null,
              siteUrl: null,
              faviconUrl: 'https://www.theverge.com/favicon.ico',
              subscribed: false,
            },
            { id: 11, title: 'B', description: null, siteUrl: null, faviconUrl: null, subscribed: false },
          ],
        },
      ],
    });

    expect(categories[0].feeds.map((feed) => feed.faviconUrl)).toEqual([
      'https://www.theverge.com/favicon.ico',
      null,
    ]);
  });
```

In `frontend/src/app/discover/discover.component.spec.ts`, inside the `CATALOG` constant:
- set The Verge's `faviconUrl: '/f/10',` to `faviconUrl: 'https://www.theverge.com/favicon.ico',`;
- set Wired's `faviconUrl: '/f/11',` to `faviconUrl: null,`.

Then add after `it('renders every category and its feeds', …)`:

```ts
  it("shows a feed's icon from its publisher, and the fallback glyph when it has none", () => {
    const cards: HTMLElement[] = Array.from(
      fixture.nativeElement.querySelectorAll('[data-testid="catalog-feed"]'),
    );

    expect(cards[0].querySelector('app-favicon img')?.getAttribute('src')).toBe(
      'https://www.theverge.com/favicon.ico',
    );
    expect(cards[1].querySelector('app-favicon img')).toBeNull();
    expect(cards[1].querySelector('app-favicon app-icon')).not.toBeNull();
  });
```

- [ ] **Step 2: Run them and see them fail**

Run: `docker compose exec -T frontend npx jest src/app/reader/feeds/catalog/catalog-api.spec.ts src/app/discover/discover.component.spec.ts`
Expected: FAIL. The API spec receives `'https://api.testhttps://www.theverge.com/favicon.ico'` and `'https://api.testnull'`. The discover spec finds no `app-favicon`.

- [ ] **Step 3: Implement**

`frontend/src/app/reader/feeds/catalog/catalog.models.ts`: replace the commented `faviconUrl: string;` (with its two-line comment) by:

```ts
  faviconUrl: string | null;
```

`frontend/src/app/reader/feeds/catalog/catalog-api.ts` becomes:

```ts
import { HttpClient } from '@angular/common/http';
import { Injectable, inject } from '@angular/core';
import { Observable } from 'rxjs';
import { API_BASE_URL } from '../../../core/api';
import { CatalogCategoryDto, OnboardingSubscribeResult } from './catalog.models';

@Injectable({ providedIn: 'root' })
export class CatalogApi {
  private readonly http = inject(HttpClient);
  private readonly base = inject(API_BASE_URL);

  load(): Observable<{ categories: CatalogCategoryDto[] }> {
    return this.http.get<{ categories: CatalogCategoryDto[] }>(`${this.base}/api/catalog`);
  }

  subscribe(catalogFeedIds: number[]): Observable<OnboardingSubscribeResult> {
    return this.http.post<OnboardingSubscribeResult>(`${this.base}/api/onboarding/subscribe`, {
      catalogFeedIds,
    });
  }
}
```

`frontend/src/app/discover/discover.component.ts`: add `import { FaviconComponent } from '../shared/favicon/favicon.component';` and put `FaviconComponent,` into the `imports` array after `IconComponent,`.

`frontend/src/app/discover/discover.component.html`: replace

```html
                  <img
                    class="fav"
                    [src]="feed.faviconUrl"
                    alt=""
                    width="20"
                    height="20"
                    loading="lazy"
                  />
```

with

```html
                  <app-favicon class="fav" [url]="feed.faviconUrl" [size]="20" />
```

`frontend/src/app/discover/discover.component.scss`: replace

```scss
.fav {
  flex: none;
  border-radius: var(--radius-sm);
}
```

with

```scss
// The card's gap already spaces the icon from the title.
.fav {
  margin-inline-end: 0;
}
```

`app-favicon`'s `:host` adds `margin-inline-end: 0.35em` for inline runs. The card is a flex row with `gap: var(--space-2)`, and the old `<img>` had no margin. The parent's `.fav[_ngcontent]` (specificity 0,2,0) overrides `[_nghost]` (0,1,0). The host already sets `flex: none`, and the inner `img.favicon` carries `border-radius: var(--radius-sm)`.

- [ ] **Step 4: Run the specs**

Run: `docker compose exec -T frontend npx jest src/app/reader/feeds/catalog/catalog-api.spec.ts src/app/discover/discover.component.spec.ts`
Expected: PASS.

- [ ] **Step 5: Drop the publisher-domain assertion from the onboarding e2e**

In `frontend/e2e/onboarding.spec.ts`, test `'a new user is sent to the picker and lands in a reader with their tags'`, delete:
- `const failures: string[] = [];`;
- the whole comment block starting `// The picker must make NO request to a publisher domain`;
- the `page.on('request', (request) => { … });` listener;
- the final `expect(failures).toEqual([]);`.

The test then begins with `await registerAndVerify(page);`.

- [ ] **Step 6: Run the frontend gates**

```bash
cd frontend
npm run check
npm run build
cd .. && docker compose exec -T frontend npm test
```
Expected: all green. `npm run check` misses template errors, so the build is what proves `<app-favicon>` binds.

- [ ] **Step 7: Commit**

```bash
git add frontend/src/app/reader/feeds/catalog frontend/src/app/discover frontend/e2e/onboarding.spec.ts
git commit -m "fix(#2130): the onboarding picker loads each icon straight from its publisher"
```

---

### Task 4: Remove the admin warm and refresh-icon UI

**Files:**
- Modify: `frontend/src/app/admin/admin.models.ts`, `frontend/src/app/admin/admin-api.ts`
- Modify: `frontend/src/app/admin/catalog/admin-catalog.component.ts`, `.html`, `.scss`, `.spec.ts`
- Modify: `frontend/src/app/admin/catalog/feed-form-dialog.component.spec.ts:44-45`
- Modify: `frontend/public/i18n/en.json`, `frontend/public/i18n/de.json`

**Interfaces:**
- Consumes: `AdminCatalogJson::feed()` now sends `faviconUrl: string | null` instead of `faviconFetchedAt`/`faviconFailedAt` (Task 1). `POST /api/admin/catalog/favicons/warm` and `POST /api/admin/catalog/feeds/{id}/favicon` are gone.
- Produces: `AdminCatalogFeedDto.faviconUrl: string | null`. `CatalogWarmReport`, `AdminApi.warmFavicons()` and `AdminApi.refreshFavicon()` no longer exist.

- [ ] **Step 1: Update the specs first**

`frontend/src/app/admin/catalog/admin-catalog.component.spec.ts`:
- in `PAYLOAD.feeds[0]`, replace `faviconFetchedAt: null,` and `faviconFailedAt: null,` with `faviconUrl: null,`;
- delete the tests `'refreshes a favicon on demand'`, `'does not show a zero remaining count before favicon warming reports progress'` and `'explains when favicon warming fails'`;
- in `'imports the bundled document without transferring a file'`, delete everything after `ctrl.expectOne('https://api.test/api/admin/catalog').flush(PAYLOAD);` (the comment and both `favicons/warm` expectations with their progressbar assertions), then add:

```ts
    fixture.detectChanges();
    expect(fixture.nativeElement.querySelector('[role="progressbar"]')).toBeNull();
```

  `afterEach(() => ctrl.verify())` then fails if a warm request still fires.
- add:

```ts
  it('offers no icon warming or per-feed icon refresh', () => {
    const root: HTMLElement = mountLoaded().nativeElement;
    expect(root.querySelector('[data-testid="refresh-favicon"]')).toBeNull();
    expect(root.textContent).not.toContain('Fetch missing icons');
  });
```

`frontend/src/app/admin/catalog/feed-form-dialog.component.spec.ts`: replace `faviconFetchedAt: null,` and `faviconFailedAt: null,` with `faviconUrl: null,`.

- [ ] **Step 2: Run them and see them fail**

Run: `docker compose exec -T frontend npx jest src/app/admin/catalog`
Expected: FAIL. `ctrl.verify()` reports the unexpected `favicons/warm` request, and the new test finds `refresh-favicon`. The `tsc` side of `npm run check` would also reject `faviconUrl` on `AdminCatalogFeedDto`.

- [ ] **Step 3: Models and API**

`frontend/src/app/admin/admin.models.ts`:
- delete `export interface CatalogWarmReport { … }`;
- in `AdminCatalogFeedDto`, replace `faviconFetchedAt: string | null;` and `faviconFailedAt: string | null;` with `faviconUrl: string | null;`.

`frontend/src/app/admin/admin-api.ts`:
- remove `CatalogWarmReport,` from the import list;
- delete `refreshFavicon()` and `warmFavicons()`;
- in `saveFeed()` change the body type to:

```ts
    body: Omit<AdminCatalogFeedDto, 'id' | 'position' | 'faviconUrl'>,
```

- [ ] **Step 4: Component**

`frontend/src/app/admin/catalog/admin-catalog.component.ts`:
- remove `CatalogWarmReport,` from the `../admin.models` import;
- delete the signals `warming`, `warmRemaining`, `warmReport`, `warmError`;
- delete the methods `refreshFavicon()`, `warm()` and `warmNextSlice()`;
- `importBusy` becomes:

```ts
  readonly importBusy = computed(() => this.importing() || this.refreshingCatalog());
```

- `handleImportSuccess()` becomes (and its three-line comment above is replaced by the one line shown):

```ts
  // A fresh import leaves the lists stale, so we refetch them.
  private handleImportSuccess(counts: CatalogImportCounts): void {
    this.importCounts.set(counts);
    this.clearPendingDocument();
    this.importing.set(false);
    this.refreshingCatalog.set(true);
    this.fetchCatalog(
      () => this.refreshingCatalog.set(false),
      () => this.refreshingCatalog.set(false),
    );
  }
```

`frontend/src/app/admin/catalog/admin-catalog.component.html`:
- delete the block from `<div class="warm">` through the closing `}` of `@if (warmError()) { <app-error-banner … /> }`, which sits just before `</section>`;
- delete the row button:

```html
                    <button
                      class="icon-act"
                      data-testid="refresh-favicon"
                      (click)="refreshFavicon(feed)"
                      [attr.aria-label]="'admin.refreshFavicon' | transloco"
                    >
                      <app-icon name="refresh" size="md" />
                    </button>
```

`frontend/src/app/admin/catalog/admin-catalog.component.scss`: delete the `.warm { … }`, `.progress { … }` and `.progress-panel progress { … }` rules. Only the warm panel used them. `.progress-panel` stays, because the import status panels use it.

`frontend/public/i18n/en.json` and `frontend/public/i18n/de.json`: under `admin`, delete the keys `warmIcons`, `warmingIconsLabel`, `warmingIconsStarting`, `warmingIcons`, `warmDone` and `refreshFavicon`.

- [ ] **Step 5: Confirm nothing is left**

Run (repo root): `git grep -n "warmFavicons\|refreshFavicon\|CatalogWarmReport\|warmIcons\|warmingIcons\|warmDone\|faviconFetchedAt\|faviconFailedAt\|favicons/warm" -- frontend`
Expected: no output.

- [ ] **Step 6: Run the frontend gates**

```bash
cd frontend
npm run check
npm run build
cd .. && docker compose exec -T frontend npm test
```
Expected: all green.

- [ ] **Step 7: Commit**

```bash
git add frontend/src/app/admin frontend/public/i18n
git commit -m "fix(#2130): drop the admin icon warming and per-feed icon refresh"
```

---

### Task 5: Deploy script, installer and docs

**Files:**
- Modify: `deploy/strato/activate-release.sh:185-199`
- Modify: `scripts/lib.sh:1017-1040` (`seed_catalog`)
- Modify: `scripts/test/installer-output.test.sh:138,143`
- Modify: `docs/docker-production.md:152-164`

**Interfaces:**
- Consumes: Task 1 deleted `app:catalog:warm-favicons`. Task 2 made `app:catalog:import` look icons up.

- [ ] **Step 1: Strato** — in `deploy/strato/activate-release.sh`, delete the whole block from `echo "==> Warming catalog favicons"` through its closing `fi` and the blank line after it. That is the comment, `if ! console app:catalog:warm-favicons; then`, its two `echo "!!! …" >&2` lines and `fi`. `echo "==> Active release is now ${NAME}"` then follows the `mv -Tf …` flip directly. Strato's rows keep their warmed URLs through the migration's rename; the migration runs earlier in this script.

- [ ] **Step 2: Installer** — in `scripts/lib.sh`, replace the comment above `seed_catalog()` and the function with:

```bash
# Fill the onboarding catalog of a NEW instance. The import also looks up each
# feed's icon on its homepage, so it takes a few minutes. The installer calls
# this, and nothing else does: from the first start onwards the catalog belongs
# to the admin, so an update must never re-apply the shipped document over what
# they have made of it (#272).
#
# Not worth failing an install that is already up and serving -- the admin area
# imports it with one click -- so a failure warns and returns.
seed_catalog() {
  # --if-empty guards the case this script cannot see: an install pointed at a
  # database that already holds a catalog.
  if ! run_step 'Importing the bundled catalog (this takes a few minutes)' \
    prod_compose exec -T -u www-data php bin/console app:catalog:import --if-empty; then
    warn 'The bundled catalog was not imported. Import it in the admin area under Catalog.'
  fi
  return 0
}
```

In `scripts/test/installer-output.test.sh`, the second sample warning names the removed step. Replace `warn 'Some catalog favicons are missing.' 2>/dev/null` and its `assert_contains 'Some catalog favicons are missing.' "${notes}" 'the second warning'` with a warning `lib.sh` still emits:

```bash
warn 'Could not pull updated base images; building on the cached ones.' 2>/dev/null
```
```bash
assert_contains 'Could not pull updated base images; building on the cached ones.' "${notes}" 'the second warning'
```

- [ ] **Step 3: Docs** — in `docs/docker-production.md`, replace the paragraph starting `Once the stack is up, the installer fills the **onboarding catalog**` and its two-command block with:

````markdown
Once the stack is up, the installer fills the **onboarding catalog** from the
document this release ships. The import also looks up each feed's icon on its
homepage — a few minutes of requests, paid once — and the picker then loads
every icon straight from its publisher. Only the installer does this: from
then on the catalog is yours, and neither an update nor a restart re-applies
the shipped document over your edits. A manual install does the same in one
command:

```bash
docker compose -p simple-feed-reader-prod -f docker-compose.prod.yml --env-file .env.prod \
  exec -u www-data php bin/console app:catalog:import --if-empty
```
````

- [ ] **Step 4: Check**

```bash
shellcheck scripts/lib.sh scripts/test/installer-output.test.sh deploy/strato/activate-release.sh
scripts/test/installer-output.test.sh
git grep -n "warm-favicons\|Warming catalog favicons\|catalog favicons are missing" -- ':!docs/superpowers'
```
Expected: shellcheck reports nothing, the installer test passes, and the grep prints nothing.

- [ ] **Step 5: Commit**

```bash
git add deploy/strato/activate-release.sh scripts/lib.sh scripts/test/installer-output.test.sh docs/docker-production.md
git commit -m "fix(#2130): no favicon warming step on deploy or install; the import looks icons up"
```

---

### Task 6: Verify on the Docker dev stack

**Files:** none changed. If a step fails, fix the cause in the task that owns it.

- [ ] **Step 1: Make sure the stack runs this checkout**

```bash
docker compose ps
docker compose exec php bin/console cache:clear
docker compose restart worker
```
Expected: php, nginx, frontend, mysql and worker are up. The e2e preflight (`backend/bin/e2e-preflight.sh`) must name this checkout, not a worktree.

- [ ] **Step 2: Apply the migration to the dev DB**

```bash
docker compose exec php bin/console doctrine:migrations:migrate --no-interaction
docker compose exec php bin/console doctrine:schema:validate
docker compose exec php bin/console dbal:run-sql "SELECT COUNT(*) AS feeds, COUNT(favicon_url) AS with_icon FROM catalog_feed"
```
Expected: `Version20261109120000` runs and the schema is in sync. `with_icon` matches the warmed icons from before, with no backfill.

- [ ] **Step 3: Confirm the old route is gone and the catalog carries URLs**

```bash
docker compose exec php bin/console debug:router | grep -i "catalog"
```
Expected: no `api_catalog_favicon`, no `api_admin_catalog_warm_favicons` and no `api_admin_catalog_feed_favicon`.

Open `http://localhost:4200/discover` as a fresh user in the browser pane. The network panel shows no `/api/catalog/feeds/*/favicon` request. The icons load from publisher hosts, and a feed without an icon shows the rss glyph.

- [ ] **Step 4: Run the two affected Playwright specs**

```bash
docker compose exec -T php bin/console cache:pool:clear cache.rate_limiter altcha.replay.cache
cd frontend && npx playwright test e2e/onboarding.spec.ts e2e/settings-admin-smoke.spec.ts
```
Expected: both pass. The issue reported `onboarding.spec.ts:200` failing 4/4 with 4 workers before this change; it should now pass on every run. Subscribe is no longer queued behind 142 favicon requests.

- [ ] **Step 5: Scan the dev log**

```bash
ls -t backend/var/log/dev-*.log | head -1 | xargs tail -n 200 | jq -c 'select(.level_name == "ERROR" or .level_name == "CRITICAL" or (.message | test("eprecat")))'
```
Expected: nothing new from catalog or favicon code.

- [ ] **Step 6: Full suites once more, both legs**

```bash
cd backend && php bin/phpunit
docker compose exec php composer test:parallel
```
Expected: green on SQLite and MySQL. Then run `/simplify` and open the PR with `Closes #2130`. The PR body names the decisions above, including the dropped e2e assertion and the rss-glyph fallback.
