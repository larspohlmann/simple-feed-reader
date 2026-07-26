# Onboarding Feed Catalog Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Give a new user a category-grouped picker of 111 curated feeds, subscribe to a multi-selection in one action, and file each chosen feed under a tag named after its category.

**Architecture:** Two new Doctrine entities (`CatalogCategory`, `CatalogFeed`). The migration creates the tables and nothing else — the catalog is *data*, shipped as `resources/catalog/catalog.opml` and applied by an admin-triggered import with `merge` and `replace` modes, so a corrected feed URL is an import rather than a new migration. Favicon bytes are cached on the catalog row and filled by a budgeted warmer the admin UI drives after an import — deployment-agnostic, so a self-hosted install gets icons without any deploy script; no request path ever fetches one inline. Bulk subscription logic is **extracted** from `OpmlImporter` into a shared `BulkSubscriber` so the cap, dedup and position arithmetic exist once. The frontend adds a `/discover` picker — continuous scroll with a scroll-tracking category rail on desktop, a chip strip on mobile — and the reader shell owns the post-subscribe refresh under a state-driven rule.

**Tech Stack:** Symfony 7.4 / PHP 8.4 / Doctrine ORM, Angular 20 standalone components + signals, Transloco, PHPUnit, Jest, Playwright.

**Design doc:** [`docs/superpowers/specs/2026-07-26-onboarding-feed-catalog-design.md`](../specs/2026-07-26-onboarding-feed-catalog-design.md)
**Issue:** [#99](https://github.com/larspohlmann/simple-feed-reader/issues/99)

---

## Before you start

**Branch.** `git checkout develop && git pull && git checkout -b feature/99-onboarding-feed-catalog`. Another session may share this checkout — check `git status` before any checkout, reset or stash.

**Quality gate.** Every backend commit must pass, from `backend/`:

```bash
composer check && composer md && php bin/phpunit
```

`composer stan` needs a warm dev cache — run `bin/console cache:warmup` if it complains. Every `src` file you touch must be PHPMD-clean before commit, not merely free of *new* findings. Frontend commits must pass `npm run check` from `frontend/`.

**Phases are shippable.** Phase 1 gives an importable, queryable catalog. Phase 2 adds icons. Task 11 is optional and Strato-specific — skip it on a fork. Phase 3 adds subscribing. Phase 4–5 add the UI. Phase 6 adds admin. Each can be its own PR into `develop`.

---

## File Structure

**Phase 1–2 — catalog and favicons (backend)**

| File | Responsibility |
| --- | --- |
| `backend/src/Entity/CatalogCategory.php` | Category row: key, name, icon, colour, position, enabled |
| `backend/src/Entity/CatalogFeed.php` | Feed row plus the favicon cache columns |
| `backend/src/Repository/CatalogCategoryRepository.php` | Enabled categories in position order |
| `backend/src/Repository/CatalogFeedRepository.php` | Enabled feeds, favicon warm queue |
| `backend/src/Http/CatalogJson.php` | Serialisation for `GET /api/catalog` |
| `backend/src/Controller/Api/CatalogController.php` | `GET /api/catalog`, `GET /api/catalog/feeds/{id}/favicon` |
| `backend/src/Service/Catalog/CatalogFaviconFetcher.php` | Guarded download of one already-resolved icon URL |
| `backend/src/Service/Catalog/MonogramFavicon.php` | Deterministic SVG placeholder |
| `backend/src/Service/Catalog/Exception/FaviconUnavailableException.php` | Typed failure |
| `backend/src/Service/Catalog/CatalogFaviconWarmer.php` | Budgeted warming loop, shared by the endpoint and the command |
| `backend/src/Command/WarmCatalogFaviconsCommand.php` | `app:catalog:warm-favicons`, for cron or a shell |
| `backend/resources/catalog/catalog.opml` | The 13 categories and 111 feeds, one source of truth |
| `backend/src/Service/Opml/OpmlBodyReader.php` | Hardened OPML→DOM, shared with the user-facing import |
| `backend/src/Service/Catalog/CatalogDocument.php` | Parses and fully validates a catalog OPML document |
| `backend/src/Service/Catalog/CatalogImporter.php` | Applies a document in merge or replace mode |
| `backend/src/Controller/Admin/AdminCatalogImportController.php` | `POST /api/admin/catalog/import` |
| `backend/src/Command/ImportCatalogCommand.php` | `app:catalog:import`, for unattended seeding |
| `backend/migrations/Version20260726120000.php` | Creates both tables — no data |

**Phase 3 — bulk subscribe**

| File | Responsibility |
| --- | --- |
| `backend/src/Service/Subscription/BulkSubscribeItem.php` | One feed to subscribe, with its tag and styling |
| `backend/src/Service/Subscription/BulkSubscribeResult.php` | Counts plus the tags created |
| `backend/src/Service/Subscription/TagStyle.php` | Colour/icon applied only when a tag is created |
| `backend/src/Service/Subscription/BulkSubscriber.php` | The shared batch: dedup, cap, positions, one flush |
| `backend/src/Service/Catalog/CatalogSubscriber.php` | Catalog ids → `BulkSubscribeItem`s |
| `backend/src/Dto/Onboarding/OnboardingSubscribeRequest.php` | Request payload |
| `backend/src/Controller/Api/OnboardingController.php` | `POST /api/onboarding/subscribe` |
| `backend/src/Service/Opml/OpmlImporter.php` | **Modified** — delegates the batch to `BulkSubscriber` |

**Phase 4–5 — picker and progress (frontend)**

| File | Responsibility |
| --- | --- |
| `frontend/src/app/discover/catalog.models.ts` | DTO interfaces |
| `frontend/src/app/discover/catalog-api.ts` | HTTP client |
| `frontend/src/app/discover/catalog-selection.store.ts` | Selection state and counts |
| `frontend/src/app/discover/active-category.ts` | Scroll-spy state, shared by rail and chips |
| `frontend/src/app/discover/discover.component.ts/.html/.scss` | The picker |
| `frontend/src/app/discover/category-rail.component.ts` | Desktop rail |
| `frontend/src/app/discover/category-chips.component.ts` | Mobile chip strip |
| `frontend/src/app/discover/onboarding-skip.ts` | Session-scoped skip flag |
| `frontend/src/app/reader/refresh.service.ts` | **Modified** — per-slice tick |
| `frontend/src/app/reader/reader-shell.component.*` | **Modified** — redirect, sweep ownership, banner |

**Phase 6–7 — admin, rot check, e2e**

| File | Responsibility |
| --- | --- |
| `backend/src/Controller/Admin/AdminCatalogController.php` | Catalog CRUD and reorder |
| `backend/src/Dto/Admin/*` | Admin request payloads |
| `frontend/src/app/admin/admin-catalog.component.*` | Admin UI |
| `.github/workflows/catalog-rot-check.yml` | Weekly URL verification |

---

# Phase 1 — Catalog data and read API

### Task 1: Catalog entities and repositories

**Files:**
- Create: `backend/src/Entity/CatalogCategory.php`
- Create: `backend/src/Entity/CatalogFeed.php`
- Create: `backend/src/Repository/CatalogCategoryRepository.php`
- Create: `backend/src/Repository/CatalogFeedRepository.php`
- Test: `backend/tests/Repository/CatalogCategoryRepositoryTest.php`

- [ ] **Step 1: Write the failing test**

`backend/tests/Repository/CatalogCategoryRepositoryTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Repository;

use App\Entity\CatalogCategory;
use App\Entity\CatalogFeed;
use App\Repository\CatalogCategoryRepository;
use App\Tests\DbTestCase;
use Doctrine\ORM\EntityManagerInterface;

final class CatalogCategoryRepositoryTest extends DbTestCase
{
    public function testEnabledCategoriesComeBackInPositionOrderWithEnabledFeedsOnly(): void
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);

        $second = new CatalogCategory('science', 'Science', 'science', '#14b8a6');
        $second->setPosition(1);
        $first = new CatalogCategory('technology', 'Technology', 'memory', '#3b82f6');
        $first->setPosition(0);
        $hidden = new CatalogCategory('hidden', 'Hidden', 'lock', '#000000');
        $hidden->setPosition(2);
        $hidden->setEnabled(false);

        $live = new CatalogFeed($first, 'The Verge', 'https://www.theverge.com/rss/index.xml');
        $live->setPosition(0);
        $retired = new CatalogFeed($first, 'Retired', 'https://example.com/gone.xml');
        $retired->setPosition(1);
        $retired->setEnabled(false);

        foreach ([$second, $first, $hidden, $live, $retired] as $row) {
            $em->persist($row);
        }
        $em->flush();

        $repository = self::getContainer()->get(CatalogCategoryRepository::class);
        self::assertInstanceOf(CatalogCategoryRepository::class, $repository);

        $rows = $repository->findEnabledWithFeeds();

        self::assertSame(['Technology', 'Science'], array_map(
            static fn (CatalogCategory $c): string => $c->getName(),
            $rows,
        ));
        self::assertSame(['The Verge'], array_map(
            static fn (CatalogFeed $f): string => $f->getTitle(),
            $rows[0]->getEnabledFeeds(),
        ));
    }
}
```

- [ ] **Step 2: Run it to confirm it fails**

Run from `backend/`: `php bin/phpunit tests/Repository/CatalogCategoryRepositoryTest.php`
Expected: FAIL — `Class "App\Entity\CatalogCategory" not found`.

- [ ] **Step 3: Create `CatalogCategory`**

`backend/src/Entity/CatalogCategory.php`:

```php
<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\CatalogCategoryRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

/**
 * One group in the onboarding catalog. Its name becomes the name of the Tag a
 * subscribing user gets, and its colour and icon seed that tag on creation —
 * which is why the same validation applies here as to CreateTagRequest.
 */
#[ORM\Entity(repositoryClass: CatalogCategoryRepository::class)]
#[ORM\Table(name: 'catalog_category')]
#[ORM\UniqueConstraint(name: 'uniq_catalog_category_key', columns: ['category_key'])]
class CatalogCategory
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(name: 'category_key', length: 64)]
    private string $key;

    #[ORM\Column(length: 100)]
    private string $name;

    #[ORM\Column(length: 64)]
    private string $icon;

    #[ORM\Column(length: 7)]
    private string $color;

    #[ORM\Column(options: ['default' => 0])]
    private int $position = 0;

    #[ORM\Column(options: ['default' => true])]
    private bool $enabled = true;

    /**
     * A locked row is the admin's, not the catalog document's: an import will
     * neither overwrite nor delete it. See CatalogImporter.
     */
    #[ORM\Column(options: ['default' => false])]
    private bool $locked = false;

    /** @var Collection<int, CatalogFeed> */
    #[ORM\OneToMany(targetEntity: CatalogFeed::class, mappedBy: 'category')]
    #[ORM\OrderBy(['position' => 'ASC', 'title' => 'ASC'])]
    private Collection $feeds;

    public function __construct(string $key, string $name, string $icon, string $color)
    {
        $this->key = $key;
        $this->name = $name;
        $this->icon = $icon;
        $this->color = $color;
        $this->feeds = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getKey(): string
    {
        return $this->key;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): void
    {
        $this->name = $name;
    }

    public function getIcon(): string
    {
        return $this->icon;
    }

    public function setIcon(string $icon): void
    {
        $this->icon = $icon;
    }

    public function getColor(): string
    {
        return $this->color;
    }

    public function setColor(string $color): void
    {
        $this->color = $color;
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

    /**
     * Only the feeds a user may actually pick. Disabling a feed is how a retired
     * source leaves the picker without deleting a row an admin may have edited.
     *
     * @return list<CatalogFeed>
     */
    public function getEnabledFeeds(): array
    {
        return array_values(
            array_filter(
                $this->feeds->toArray(),
                static fn (CatalogFeed $feed): bool => $feed->isEnabled(),
            ),
        );
    }
}
```

- [ ] **Step 4: Create `CatalogFeed`**

`backend/src/Entity/CatalogFeed.php`:

```php
<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\SourceFormat;
use App\Repository\CatalogFeedRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * One suggestion in the onboarding catalog. The URL is a VERIFIED direct feed
 * URL, which is what lets the subscribe path skip discovery entirely.
 *
 * The favicon bytes live on the row rather than on disk so the cache shares the
 * catalog's backup/restore unit and needs no writable var/ path on Strato. They
 * are filled exclusively by app:catalog:warm-favicons — never by a request.
 */
#[ORM\Entity(repositoryClass: CatalogFeedRepository::class)]
#[ORM\Table(name: 'catalog_feed')]
#[ORM\UniqueConstraint(name: 'uniq_catalog_feed_url', columns: ['url'])]
class CatalogFeed
{
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

    #[ORM\Column(length: 750, nullable: true)]
    private ?string $faviconSourceUrl = null;

    #[ORM\Column(type: 'blob', nullable: true)]
    private mixed $faviconData = null;

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $faviconContentType = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $faviconFetchedAt = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $faviconFailedAt = null;

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
        $this->url = $url;
    }

    public function getSiteUrl(): ?string
    {
        return $this->siteUrl;
    }

    public function setSiteUrl(?string $siteUrl): void
    {
        $this->siteUrl = $siteUrl;
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

    public function getFaviconSourceUrl(): ?string
    {
        return $this->faviconSourceUrl;
    }

    /**
     * Doctrine hands back a stream for a BLOB column; callers want bytes. Null
     * means "no icon cached", which is what makes the endpoint serve a monogram.
     */
    public function getFaviconBytes(): ?string
    {
        if (null === $this->faviconData) {
            return null;
        }
        if (\is_resource($this->faviconData)) {
            rewind($this->faviconData);

            return (string) stream_get_contents($this->faviconData);
        }

        return (string) $this->faviconData;
    }

    public function getFaviconContentType(): ?string
    {
        return $this->faviconContentType;
    }

    public function getFaviconFetchedAt(): ?\DateTimeImmutable
    {
        return $this->faviconFetchedAt;
    }

    public function getFaviconFailedAt(): ?\DateTimeImmutable
    {
        return $this->faviconFailedAt;
    }

    public function storeFavicon(string $sourceUrl, string $bytes, string $contentType, \DateTimeImmutable $at): void
    {
        $this->faviconSourceUrl = $sourceUrl;
        $this->faviconData = $bytes;
        $this->faviconContentType = $contentType;
        $this->faviconFetchedAt = $at;
        $this->faviconFailedAt = null;
    }

    public function recordFaviconFailure(\DateTimeImmutable $at): void
    {
        $this->faviconFailedAt = $at;
    }
}
```

- [ ] **Step 5: Create the repositories**

`backend/src/Repository/CatalogCategoryRepository.php`:

```php
<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\CatalogCategory;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<CatalogCategory>
 */
class CatalogCategoryRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, CatalogCategory::class);
    }

    /**
     * Enabled categories in display order, with their feeds already loaded — the
     * picker renders every category at once, so a lazy collection here would be
     * 13 extra queries per page load.
     *
     * @return list<CatalogCategory>
     */
    public function findEnabledWithFeeds(): array
    {
        /** @var list<CatalogCategory> $rows */
        $rows = $this->createQueryBuilder('c')
            ->leftJoin('c.feeds', 'f')->addSelect('f')
            ->andWhere('c.enabled = true')
            ->orderBy('c.position', 'ASC')
            ->addOrderBy('c.name', 'ASC')
            ->getQuery()
            ->getResult();

        return $rows;
    }

    /**
     * Every category in admin order, enabled or not.
     *
     * @return list<CatalogCategory>
     */
    public function findAllOrdered(): array
    {
        /** @var list<CatalogCategory> $rows */
        $rows = $this->createQueryBuilder('c')
            ->orderBy('c.position', 'ASC')
            ->addOrderBy('c.name', 'ASC')
            ->getQuery()
            ->getResult();

        return $rows;
    }

    public function nextPosition(): int
    {
        $max = $this->createQueryBuilder('c')
            ->select('MAX(c.position)')
            ->getQuery()
            ->getSingleScalarResult();

        return null === $max ? 0 : (int) $max + 1;
    }
}
```

`backend/src/Repository/CatalogFeedRepository.php`:

```php
<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\CatalogFeed;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<CatalogFeed>
 */
class CatalogFeedRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, CatalogFeed::class);
    }

    /**
     * The enabled feeds matching these ids. Fewer results than ids means one or
     * more ids were unknown or disabled — which the subscribe path IGNORES
     * rather than rejecting, so a stale picker never fails the whole submit.
     *
     * @param list<int> $ids
     *
     * @return list<CatalogFeed>
     */
    public function findEnabledByIds(array $ids): array
    {
        if ([] === $ids) {
            return [];
        }

        /** @var list<CatalogFeed> $rows */
        $rows = $this->createQueryBuilder('f')
            ->innerJoin('f.category', 'c')->addSelect('c')
            ->andWhere('f.id IN (:ids)')->setParameter('ids', $ids)
            ->andWhere('f.enabled = true')
            ->andWhere('c.enabled = true')
            ->orderBy('c.position', 'ASC')
            ->addOrderBy('f.position', 'ASC')
            ->getQuery()
            ->getResult();

        return $rows;
    }

    /**
     * The warm queue: enabled feeds with no cached icon, or one older than
     * $staleBefore, skipping rows whose last failure is newer than $retryBefore
     * so a dead icon is not re-attempted on every deploy.
     *
     * @return list<CatalogFeed>
     */
    public function findNeedingFavicon(
        \DateTimeImmutable $staleBefore,
        \DateTimeImmutable $retryBefore,
        ?int $limit,
    ): array {
        $qb = $this->createQueryBuilder('f')
            ->andWhere('f.enabled = true')
            ->andWhere('f.faviconFetchedAt IS NULL OR f.faviconFetchedAt < :stale')
            ->setParameter('stale', $staleBefore)
            ->andWhere('f.faviconFailedAt IS NULL OR f.faviconFailedAt < :retry')
            ->setParameter('retry', $retryBefore)
            ->orderBy('f.id', 'ASC');

        if (null !== $limit) {
            $qb->setMaxResults($limit);
        }

        /** @var list<CatalogFeed> $rows */
        $rows = $qb->getQuery()->getResult();

        return $rows;
    }

    public function nextPositionInCategory(int $categoryId): int
    {
        $max = $this->createQueryBuilder('f')
            ->select('MAX(f.position)')
            ->andWhere('f.category = :category')->setParameter('category', $categoryId)
            ->getQuery()
            ->getSingleScalarResult();

        return null === $max ? 0 : (int) $max + 1;
    }
}
```

- [ ] **Step 6: Run the test to verify it passes**

Run: `php bin/phpunit tests/Repository/CatalogCategoryRepositoryTest.php`
Expected: PASS, 1 test.

- [ ] **Step 7: Run the gate and commit**

```bash
composer check && composer md
git add src/Entity/CatalogCategory.php src/Entity/CatalogFeed.php src/Repository/CatalogCategoryRepository.php src/Repository/CatalogFeedRepository.php tests/Repository/CatalogCategoryRepositoryTest.php
git commit -m "feat(catalog): add CatalogCategory and CatalogFeed entities (#99)"
```

---

### Task 2: The catalog OPML document

The catalog ships as an **OPML file in the repo**. Nothing reads it at runtime and no migration inserts it: it is the document an admin imports through the admin area, and the document the rot check verifies. Keeping it in git means catalog changes are reviewable, diffable and revertable like any other change.

OPML is the right format here rather than an invention: it is what feed catalogs are exchanged in, the repo already imports and exports it, and the file is consequently openable in any other reader. Categories map to group outlines and feeds to `xmlUrl` outlines. The three things OPML has no standard attribute for — a category's stable `key`, `icon` and `color` — ride as extra attributes on the group outline, which OPML 2.0 explicitly permits.

**The hardened parsing already exists** in `OpmlImporter::parseBody()`: no network, no DTD, must be an `<opml>` root with a `<body>`. That is a security boundary, so it gets **extracted into one shared parser** rather than copied. `OpmlImporter` adopts it in Task 12.

**Files:**
- Create: `backend/resources/catalog/catalog.opml`
- Create: `backend/src/Service/Opml/OpmlBodyReader.php`
- Create: `backend/src/Service/Catalog/CatalogDocument.php`
- Create: `backend/src/Service/Catalog/CatalogDocumentCategory.php`
- Create: `backend/src/Service/Catalog/CatalogDocumentFeed.php`
- Create: `backend/src/Service/Catalog/Exception/InvalidCatalogDocumentException.php`
- Test: `backend/tests/Service/Opml/OpmlBodyReaderTest.php`
- Test: `backend/tests/Service/Catalog/CatalogDocumentTest.php`

- [ ] **Step 1: Write the failing test for the shared parser**

`backend/tests/Service/Opml/OpmlBodyReaderTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Service\Opml;

use App\Exception\InvalidOpmlException;
use App\Service\Opml\OpmlBodyReader;
use PHPUnit\Framework\TestCase;

/**
 * This parser is a security boundary — it is the one place untrusted OPML is
 * turned into a DOM — so it is tested as one.
 */
final class OpmlBodyReaderTest extends TestCase
{
    public function testReturnsTheBodyOfAWellFormedDocument(): void
    {
        $body = (new OpmlBodyReader())->read(
            '<opml version="2.0"><head/><body><outline text="x"/></body></opml>',
        );

        self::assertSame('body', $body->localName);
    }

    public function testRejectsANonOpmlRoot(): void
    {
        $this->expectException(InvalidOpmlException::class);
        (new OpmlBodyReader())->read('<rss version="2.0"><channel/></rss>');
    }

    public function testRejectsADocumentWithNoBody(): void
    {
        $this->expectException(InvalidOpmlException::class);
        (new OpmlBodyReader())->read('<opml version="2.0"><head/></opml>');
    }

    public function testRejectsMalformedXml(): void
    {
        $this->expectException(InvalidOpmlException::class);
        (new OpmlBodyReader())->read('<opml><body>');
    }

    public function testRejectsADoctype(): void
    {
        $this->expectException(InvalidOpmlException::class);
        (new OpmlBodyReader())->read(
            '<!DOCTYPE opml [<!ENTITY x "boom">]><opml version="2.0"><body/></opml>',
        );
    }
}
```

- [ ] **Step 2: Run it to confirm it fails**

Run from `backend/`: `php bin/phpunit tests/Service/Opml/OpmlBodyReaderTest.php`
Expected: FAIL — `Class "App\Service\Opml\OpmlBodyReader" not found`.

- [ ] **Step 3: Write the shared parser**

This is `OpmlImporter::parseBody()` moved, unchanged in behaviour.

`backend/src/Service/Opml/OpmlBodyReader.php`:

```php
<?php

declare(strict_types=1);

namespace App\Service\Opml;

use App\Exception\InvalidOpmlException;

/**
 * Turns untrusted OPML into a DOM, hardened the same way FeedParser hardens
 * feeds: no network, no DTD, and a root that must actually be <opml>.
 *
 * One copy, shared by the user-facing OPML import and the catalog document —
 * this is a security boundary, and a second copy is a second thing to get wrong.
 */
final readonly class OpmlBodyReader
{
    public function read(string $opml): \DOMElement
    {
        $document = new \DOMDocument();
        $previous = libxml_use_internal_errors(true);
        try {
            $loaded = $document->loadXML($opml, \LIBXML_NONET | \LIBXML_COMPACT);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        $root = $document->documentElement;
        if (false === $loaded || null === $root || null !== $document->doctype || 'opml' !== $root->localName) {
            throw new InvalidOpmlException('Not a well-formed OPML 2.0 document.');
        }

        $body = $document->getElementsByTagName('body')->item(0);
        if (!$body instanceof \DOMElement) {
            throw new InvalidOpmlException('OPML has no <body>.');
        }

        return $body;
    }
}
```

- [ ] **Step 4: Run it to verify it passes**

Run: `php bin/phpunit tests/Service/Opml/OpmlBodyReaderTest.php`
Expected: PASS, 5 tests.

- [ ] **Step 5: Write the failing test for the catalog document**

`backend/tests/Service/Catalog/CatalogDocumentTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Service\Catalog;

use App\Service\Catalog\CatalogDocument;
use App\Service\Catalog\Exception\InvalidCatalogDocumentException;
use App\Service\Opml\OpmlBodyReader;
use PHPUnit\Framework\TestCase;

/**
 * The shipped document is validated like production data: it is what an admin
 * imports, and a malformed outline would otherwise become a bad catalog_feed
 * row.
 */
final class CatalogDocumentTest extends TestCase
{
    private function parser(): CatalogDocument
    {
        return new CatalogDocument(new OpmlBodyReader());
    }

    private function shippedOpml(): string
    {
        return (string) file_get_contents(__DIR__ . '/../../../resources/catalog/catalog.opml');
    }

    /** @param list<string> $feedOutlines */
    private function opml(array $feedOutlines, string $categoryAttributes = 'text="Technology" key="technology" icon="memory" color="#3b82f6"'): string
    {
        return '<opml version="2.0"><head><title>t</title></head><body>'
            . '<outline ' . $categoryAttributes . '>' . implode('', $feedOutlines) . '</outline>'
            . '</body></opml>';
    }

    public function testTheShippedDocumentParsesAndCarriesTheFullCatalog(): void
    {
        $document = $this->parser()->parse($this->shippedOpml());

        self::assertCount(13, $document->categories);
        self::assertSame(111, $document->feedCount());
    }

    public function testEveryCategoryIsWellFormedAndUniquelyKeyed(): void
    {
        $document = $this->parser()->parse($this->shippedOpml());

        $keys = [];
        foreach ($document->categories as $category) {
            self::assertMatchesRegularExpression('/^[a-z0-9_]+$/', $category->key);
            self::assertMatchesRegularExpression('/^[a-z0-9_]+$/', $category->icon);
            self::assertMatchesRegularExpression('/^#[0-9a-fA-F]{6}$/', $category->color);
            self::assertNotSame('', trim($category->name));
            $keys[] = $category->key;
        }

        self::assertSame($keys, array_values(array_unique($keys)));
    }

    public function testEveryFeedUrlIsHttpBoundedAndUniqueAcrossTheWholeDocument(): void
    {
        $document = $this->parser()->parse($this->shippedOpml());

        $urls = [];
        foreach ($document->categories as $category) {
            foreach ($category->feeds as $feed) {
                self::assertNotSame('', trim($feed->title));
                self::assertLessThanOrEqual(750, mb_strlen($feed->url));
                self::assertMatchesRegularExpression('#^https?://#', $feed->url);
                $urls[] = $feed->url;
            }
        }

        self::assertSame($urls, array_values(array_unique($urls)));
    }

    public function testReadsTitleSiteUrlAndDescriptionFromTheStandardAttributes(): void
    {
        $document = $this->parser()->parse($this->opml([
            '<outline type="rss" text="The Verge" xmlUrl="https://www.theverge.com/rss/index.xml"'
            . ' htmlUrl="https://www.theverge.com" description="Tech, science and culture"/>',
        ]));

        $feed = $document->categories[0]->feeds[0];
        self::assertSame('The Verge', $feed->title);
        self::assertSame('https://www.theverge.com/rss/index.xml', $feed->url);
        self::assertSame('https://www.theverge.com', $feed->siteUrl);
        self::assertSame('Tech, science and culture', $feed->description);
    }

    public function testMalformedOpmlIsRejected(): void
    {
        $this->expectException(InvalidCatalogDocumentException::class);
        $this->parser()->parse('<opml><body>');
    }

    public function testADuplicateFeedUrlIsRejected(): void
    {
        $this->expectException(InvalidCatalogDocumentException::class);
        $this->parser()->parse($this->opml([
            '<outline type="rss" text="One" xmlUrl="https://example.com/rss.xml"/>',
            '<outline type="rss" text="Two" xmlUrl="https://example.com/rss.xml"/>',
        ]));
    }

    public function testABadColourIsRejected(): void
    {
        $this->expectException(InvalidCatalogDocumentException::class);
        $this->parser()->parse($this->opml([], 'text="X" key="x" icon="memory" color="red"'));
    }

    public function testACategoryWithoutAKeyIsRejected(): void
    {
        $this->expectException(InvalidCatalogDocumentException::class);
        $this->parser()->parse($this->opml([], 'text="X" icon="memory" color="#000000"'));
    }

    public function testNestedCategoriesAreRejectedRatherThanSilentlyFlattened(): void
    {
        $this->expectException(InvalidCatalogDocumentException::class);
        $this->parser()->parse($this->opml([
            '<outline text="Nested" key="nested" icon="memory" color="#000000">'
            . '<outline type="rss" text="A" xmlUrl="https://example.com/a.xml"/></outline>',
        ]));
    }

    public function testAnEmptyDocumentIsRejected(): void
    {
        $this->expectException(InvalidCatalogDocumentException::class);
        $this->parser()->parse('<opml version="2.0"><head/><body/></opml>');
    }
}
```

- [ ] **Step 6: Run it to confirm it fails**

Run: `php bin/phpunit tests/Service/Catalog/CatalogDocumentTest.php`
Expected: FAIL — `Class "App\Service\Catalog\CatalogDocument" not found`.

- [ ] **Step 7: Write the exception**

`backend/src/Service/Catalog/Exception/InvalidCatalogDocumentException.php`:

```php
<?php

declare(strict_types=1);

namespace App\Service\Catalog\Exception;

/**
 * The catalog document is not usable. Thrown BEFORE anything is written, so a
 * bad import changes nothing at all.
 */
final class InvalidCatalogDocumentException extends \RuntimeException
{
}
```

- [ ] **Step 8: Write the document value objects**

`backend/src/Service/Catalog/CatalogDocumentCategory.php`:

```php
<?php

declare(strict_types=1);

namespace App\Service\Catalog;

final readonly class CatalogDocumentCategory
{
    /**
     * @param list<CatalogDocumentFeed> $feeds
     */
    public function __construct(
        public string $key,
        public string $name,
        public string $icon,
        public string $color,
        public array $feeds,
    ) {
    }
}
```

`backend/src/Service/Catalog/CatalogDocumentFeed.php`:

```php
<?php

declare(strict_types=1);

namespace App\Service\Catalog;

final readonly class CatalogDocumentFeed
{
    public function __construct(
        public string $title,
        public string $url,
        public ?string $siteUrl,
        public ?string $description,
        public string $sourceFormat,
    ) {
    }
}
```

`backend/src/Service/Catalog/ParsedCatalog.php` — what a successful parse yields:

```php
<?php

declare(strict_types=1);

namespace App\Service\Catalog;

final readonly class ParsedCatalog
{
    /**
     * @param list<CatalogDocumentCategory> $categories
     */
    public function __construct(
        public array $categories,
    ) {
    }

    public function feedCount(): int
    {
        return array_sum(array_map(
            static fn (CatalogDocumentCategory $category): int => \count($category->feeds),
            $this->categories,
        ));
    }
}
```

- [ ] **Step 9: Write the parser**

`backend/src/Service/Catalog/CatalogDocument.php`:

```php
<?php

declare(strict_types=1);

namespace App\Service\Catalog;

use App\Enum\SourceFormat;
use App\Exception\InvalidOpmlException;
use App\Service\Catalog\Exception\InvalidCatalogDocumentException;
use App\Service\Opml\OpmlBodyReader;

/**
 * Parses and fully validates a catalog OPML document.
 *
 * Shape: one level of group outlines, each a category, each containing only feed
 * outlines. A group outline carries the standard `text` plus three extra
 * attributes OPML has no equivalent for — `key`, `icon`, `color` — which OPML
 * 2.0 permits. A feed outline uses the standard `xmlUrl`, `htmlUrl` and
 * `description`.
 *
 * Validation happens here, at the boundary, so the importer can assume every
 * field is sound and an invalid document is rejected before a single row is
 * touched. There is no partial import.
 */
final readonly class CatalogDocument
{
    public function __construct(
        private OpmlBodyReader $bodyReader,
    ) {
    }

    public function parse(string $opml): ParsedCatalog
    {
        try {
            $body = $this->bodyReader->read($opml);
        } catch (InvalidOpmlException $e) {
            throw new InvalidCatalogDocumentException($e->getMessage(), 0, $e);
        }

        $categories = [];
        $seenKeys = [];
        $seenUrls = [];
        foreach ($this->outlines($body) as $outline) {
            $category = $this->category($outline, $seenUrls);
            if (isset($seenKeys[$category->key])) {
                throw new InvalidCatalogDocumentException(\sprintf('Duplicate category key "%s".', $category->key));
            }
            $seenKeys[$category->key] = true;
            $categories[] = $category;
        }

        if ([] === $categories) {
            throw new InvalidCatalogDocumentException('A catalog with no categories would empty the picker.');
        }

        return new ParsedCatalog($categories);
    }

    /**
     * @return list<\DOMElement>
     */
    private function outlines(\DOMElement $node): array
    {
        $out = [];
        foreach ($node->childNodes as $child) {
            if ($child instanceof \DOMElement && 'outline' === $child->localName) {
                $out[] = $child;
            }
        }

        return $out;
    }

    /**
     * @param array<string, true> $seenUrls carried across categories: a URL is unique in the whole document
     */
    private function category(\DOMElement $outline, array &$seenUrls): CatalogDocumentCategory
    {
        if ('' !== trim($outline->getAttribute('xmlUrl'))) {
            throw new InvalidCatalogDocumentException('A feed cannot sit at the top level; it must be inside a category.');
        }

        $key = $this->pattern($outline, 'key', '/^[a-z0-9_]+$/', 64);
        $name = $this->text($outline, 'text', 100);
        $icon = $this->pattern($outline, 'icon', '/^[a-z0-9_]+$/', 64);
        $color = $this->pattern($outline, 'color', '/^#[0-9a-fA-F]{6}$/', 7);

        $feeds = [];
        foreach ($this->outlines($outline) as $child) {
            $feed = $this->feed($child);
            if (isset($seenUrls[$feed->url])) {
                throw new InvalidCatalogDocumentException(\sprintf('Duplicate feed URL "%s".', $feed->url));
            }
            $seenUrls[$feed->url] = true;
            $feeds[] = $feed;
        }

        return new CatalogDocumentCategory($key, $name, $icon, $color, $feeds);
    }

    private function feed(\DOMElement $outline): CatalogDocumentFeed
    {
        $url = trim($outline->getAttribute('xmlUrl'));
        if ('' === $url) {
            // A nested group, not a feed. Rejected rather than flattened: the
            // picker renders exactly one level, so silently absorbing a second
            // one would lose the grouping the author wrote.
            throw new InvalidCatalogDocumentException('Nested categories are not supported; a category contains feeds only.');
        }
        if (mb_strlen($url) > 750) {
            throw new InvalidCatalogDocumentException(\sprintf('Feed URL "%s" exceeds 750 characters.', $url));
        }
        if (1 !== preg_match('#^https?://#', $url)) {
            throw new InvalidCatalogDocumentException(\sprintf('Feed URL "%s" is not http(s).', $url));
        }

        $format = trim($outline->getAttribute('sourceFormat'));
        if ('' === $format) {
            $format = SourceFormat::XML;
        }
        if (!\in_array($format, [SourceFormat::XML, SourceFormat::SCRAPED], true)) {
            throw new InvalidCatalogDocumentException(\sprintf('Unknown sourceFormat "%s".', $format));
        }

        return new CatalogDocumentFeed(
            title: $this->text($outline, 'text', 200),
            url: $url,
            siteUrl: $this->optional($outline, 'htmlUrl', 750),
            description: $this->optional($outline, 'description', 255),
            sourceFormat: $format,
        );
    }

    /** `text` is the OPML standard; `title` is accepted as the common alias. */
    private function text(\DOMElement $outline, string $attribute, int $max): string
    {
        $value = trim($outline->getAttribute($attribute));
        if ('' === $value && 'text' === $attribute) {
            $value = trim($outline->getAttribute('title'));
        }
        if ('' === $value) {
            throw new InvalidCatalogDocumentException(\sprintf('Missing or empty "%s".', $attribute));
        }
        if (mb_strlen($value) > $max) {
            throw new InvalidCatalogDocumentException(\sprintf('"%s" exceeds %d characters.', $attribute, $max));
        }

        return $value;
    }

    private function optional(\DOMElement $outline, string $attribute, int $max): ?string
    {
        $value = trim($outline->getAttribute($attribute));
        if ('' === $value) {
            return null;
        }
        if (mb_strlen($value) > $max) {
            throw new InvalidCatalogDocumentException(\sprintf('"%s" exceeds %d characters.', $attribute, $max));
        }

        return $value;
    }

    private function pattern(\DOMElement $outline, string $attribute, string $pattern, int $max): string
    {
        $value = $this->text($outline, $attribute, $max);
        if (1 !== preg_match($pattern, $value)) {
            throw new InvalidCatalogDocumentException(\sprintf('"%s" value "%s" is malformed.', $attribute, $value));
        }

        return $value;
    }
}
```

- [ ] **Step 10: Write the catalog document**

`backend/resources/catalog/catalog.opml`. Transcribe all 13 categories and all 111 feeds from the ["Proposed catalog" tables in issue #99](https://github.com/larspohlmann/simple-feed-reader/issues/99), in the order they appear there. Category order in the file is display order; feed order within a category is its display order.

```xml
<?xml version="1.0" encoding="UTF-8"?>
<!--
  The onboarding feed catalog.

  Imported through the admin area (or `bin/console app:catalog:import`); nothing
  reads this file at runtime and no migration inserts it. Ordinary OPML 2.0, so
  it opens in any other reader — with three extra attributes on each category
  outline that OPML has no equivalent for: `key` (stable identity across
  imports), `icon` (Material Symbol) and `color`.
-->
<opml version="2.0">
  <head>
    <title>simple-feed-reader onboarding catalog</title>
  </head>
  <body>
    <outline text="Technology" key="technology" icon="memory" color="#3b82f6">
      <outline type="rss" text="404 Media"
               xmlUrl="https://www.404media.co/rss/"
               htmlUrl="https://www.404media.co"
               description="Journalist-owned technology reporting"/>
      <outline type="rss" text="9to5Mac"
               xmlUrl="https://9to5mac.com/feed/"
               htmlUrl="https://9to5mac.com"
               description="Apple news and rumours"/>
      <outline type="rss" text="Ars Technica"
               xmlUrl="https://feeds.arstechnica.com/arstechnica/index"
               htmlUrl="https://arstechnica.com"
               description="Technology news and analysis"/>
    </outline>
  </body>
</opml>
```

**Every feed outline needs `text`, `xmlUrl`, `htmlUrl` and `description`.** `htmlUrl` is the publisher's homepage — the favicon fetcher resolves the icon from it, so a missing one costs that feed its icon. Descriptions are one short English line; they render under the title in the picker. `sourceFormat` is optional and defaults to `xml`. Ampersands in URLs must be escaped as `&amp;`.

- [ ] **Step 11: Run the tests to verify they pass**

Run: `php bin/phpunit tests/Service/Catalog/CatalogDocumentTest.php`
Expected: PASS, 10 tests. If the counts fail, the transcription is incomplete — 13 categories and 111 feeds exactly.

- [ ] **Step 12: Run the gate and commit**

```bash
composer check && composer md
git add resources/catalog/catalog.opml src/Service/Opml/OpmlBodyReader.php src/Service/Catalog tests/Service/Opml/OpmlBodyReaderTest.php tests/Service/Catalog/CatalogDocumentTest.php
git commit -m "feat(catalog): shipped catalog.opml and its validating parser (#99)"
```

---

### Task 3: The migration — tables only

**No data.** The migration creates the two tables and nothing else; the catalog arrives by import.

**Files:**
- Create: `backend/migrations/Version20260726120000.php`

- [ ] **Step 1: Write the migration**

Follow the pattern in `backend/migrations/Version20260724120000.php` — platform detection, `abortIf` on anything that is neither MySQL nor SQLite, and per-table idempotence so a database baselined from `doctrine:schema:create` is not re-created.

`backend/migrations/Version20260726120000.php`:

```php
<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Creates the onboarding catalog tables. DDL ONLY — no seed rows.
 *
 * The catalog is data, not schema: it ships as resources/catalog/catalog.opml
 * and an admin imports it. That keeps catalog changes out of the migration
 * chain entirely, so a corrected feed URL is an import rather than a new
 * migration, and rows an admin has edited are never rewritten by a deploy.
 *
 * PLATFORM-AWARE DDL for the same reason Version20260724120000 is: tests build
 * their schema from ORM metadata, so a dialect error here would not be caught
 * by the suite — only by CI's dedicated migration leg.
 */
final class Version20260726120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create catalog_category and catalog_feed (no data; the catalog is imported)';
    }

    public function isTransactional(): bool
    {
        return false;
    }

    public function up(Schema $schema): void
    {
        $platform = $this->connection->getDatabasePlatform();
        $mysql = $platform instanceof AbstractMySQLPlatform;
        $sqlite = $platform instanceof SQLitePlatform;

        $this->abortIf(!$mysql && !$sqlite, \sprintf(
            'No DDL defined for platform %s; only MySQL and SQLite are supported.',
            $platform::class,
        ));

        // Already baselined from ORM metadata: nothing to create.
        if ($schema->hasTable('catalog_category')) {
            return;
        }

        if ($mysql) {
            $this->addSql(<<<'SQL'
                CREATE TABLE catalog_category (
                    id INT AUTO_INCREMENT NOT NULL,
                    category_key VARCHAR(64) NOT NULL,
                    name VARCHAR(100) NOT NULL,
                    icon VARCHAR(64) NOT NULL,
                    color VARCHAR(7) NOT NULL,
                    position INT DEFAULT 0 NOT NULL,
                    enabled TINYINT(1) DEFAULT 1 NOT NULL,
                    locked TINYINT(1) DEFAULT 0 NOT NULL,
                    UNIQUE INDEX uniq_catalog_category_key (category_key),
                    PRIMARY KEY(id)
                ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
                SQL);
            $this->addSql(<<<'SQL'
                CREATE TABLE catalog_feed (
                    id INT AUTO_INCREMENT NOT NULL,
                    category_id INT NOT NULL,
                    title VARCHAR(200) NOT NULL,
                    url VARCHAR(750) NOT NULL,
                    site_url VARCHAR(750) DEFAULT NULL,
                    description VARCHAR(255) DEFAULT NULL,
                    source_format VARCHAR(20) DEFAULT 'xml' NOT NULL,
                    position INT DEFAULT 0 NOT NULL,
                    enabled TINYINT(1) DEFAULT 1 NOT NULL,
                    locked TINYINT(1) DEFAULT 0 NOT NULL,
                    favicon_source_url VARCHAR(750) DEFAULT NULL,
                    favicon_data LONGBLOB DEFAULT NULL,
                    favicon_content_type VARCHAR(100) DEFAULT NULL,
                    favicon_fetched_at DATETIME DEFAULT NULL,
                    favicon_failed_at DATETIME DEFAULT NULL,
                    UNIQUE INDEX uniq_catalog_feed_url (url),
                    INDEX idx_catalog_feed_category (category_id),
                    PRIMARY KEY(id)
                ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
                SQL);
            $this->addSql('ALTER TABLE catalog_feed ADD CONSTRAINT fk_catalog_feed_category FOREIGN KEY (category_id) REFERENCES catalog_category (id) ON DELETE CASCADE');
        }

        if ($sqlite) {
            $this->addSql(<<<'SQL'
                CREATE TABLE catalog_category (
                    id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
                    category_key VARCHAR(64) NOT NULL,
                    name VARCHAR(100) NOT NULL,
                    icon VARCHAR(64) NOT NULL,
                    color VARCHAR(7) NOT NULL,
                    position INTEGER DEFAULT 0 NOT NULL,
                    enabled BOOLEAN DEFAULT 1 NOT NULL,
                    locked BOOLEAN DEFAULT 0 NOT NULL
                )
                SQL);
            $this->addSql('CREATE UNIQUE INDEX uniq_catalog_category_key ON catalog_category (category_key)');
            $this->addSql(<<<'SQL'
                CREATE TABLE catalog_feed (
                    id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
                    category_id INTEGER NOT NULL,
                    title VARCHAR(200) NOT NULL,
                    url VARCHAR(750) NOT NULL,
                    site_url VARCHAR(750) DEFAULT NULL,
                    description VARCHAR(255) DEFAULT NULL,
                    source_format VARCHAR(20) DEFAULT 'xml' NOT NULL,
                    position INTEGER DEFAULT 0 NOT NULL,
                    enabled BOOLEAN DEFAULT 1 NOT NULL,
                    locked BOOLEAN DEFAULT 0 NOT NULL,
                    favicon_source_url VARCHAR(750) DEFAULT NULL,
                    favicon_data BLOB DEFAULT NULL,
                    favicon_content_type VARCHAR(100) DEFAULT NULL,
                    favicon_fetched_at DATETIME DEFAULT NULL,
                    favicon_failed_at DATETIME DEFAULT NULL,
                    CONSTRAINT fk_catalog_feed_category FOREIGN KEY (category_id) REFERENCES catalog_category (id) ON DELETE CASCADE
                )
                SQL);
            $this->addSql('CREATE UNIQUE INDEX uniq_catalog_feed_url ON catalog_feed (url)');
            $this->addSql('CREATE INDEX idx_catalog_feed_category ON catalog_feed (category_id)');
        }
    }

    public function down(Schema $schema): void
    {
        // catalog_feed first, because of the FK.
        $this->addSql('DROP TABLE IF EXISTS catalog_feed');
        $this->addSql('DROP TABLE IF EXISTS catalog_category');
    }
}
```

- [ ] **Step 2: Verify it applies from empty on SQLite**

```bash
rm -f var/data_migrate.db
DATABASE_URL="sqlite:///%kernel.project_dir%/var/data_migrate.db" php bin/console doctrine:migrations:migrate --no-interaction
DATABASE_URL="sqlite:///%kernel.project_dir%/var/data_migrate.db" php bin/console doctrine:schema:validate
```

Expected: migrations run green, and `schema:validate` reports the mapping and database are in sync.

- [ ] **Step 3: Verify it applies on MySQL too**

```bash
docker compose exec php bin/console doctrine:migrations:migrate --no-interaction
docker compose exec php bin/console doctrine:schema:validate
```

Expected: green on both.

- [ ] **Step 4: Commit**

```bash
rm -f var/data_migrate.db
git add migrations/Version20260726120000.php
git commit -m "feat(catalog): migration creating the catalog tables (#99)"
```

---

### Task 4: `CatalogImporter`

Two modes, and they differ in exactly one respect:

- **merge** — upsert everything in the document; rows not mentioned are left alone.
- **replace** — upsert everything in the document; rows not mentioned are **deleted**.

Both preserve the cached favicon of any feed whose URL survives, matched on `catalog_feed.url`. Replace is deliberately *not* truncate-and-insert: throwing away 111 cached icons on every re-import would mean a full re-warm each time, and would discard a category's `enabled` flag for no reason.

**Locked rows are exempt from both.** A locked row belongs to the admin, not to the document: an import will neither overwrite nor delete it. That is what makes `replace` safe to run — an admin can add feeds the shipped catalog does not carry, lock them, and still re-import the catalog wholesale without losing them.

Lock protects against overwriting as well as deleting, on purpose. Delete-only protection would still let a re-import silently revert an edited title or description, which is the same unwelcome surprise wearing a different hat.

One consequence needs care: **a category is protected from deletion if it holds any locked feed**, even when the category itself is unlocked and the document omits it. Deleting it would cascade to its feeds and take the locked ones with it, defeating the lock entirely.

**Files:**
- Create: `backend/src/Service/Catalog/CatalogImportMode.php`
- Create: `backend/src/Service/Catalog/CatalogImportResult.php`
- Create: `backend/src/Service/Catalog/CatalogImporter.php`
- Test: `backend/tests/Service/Catalog/CatalogImporterTest.php`

- [ ] **Step 1: Write the failing test**

`backend/tests/Service/Catalog/CatalogImporterTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Service\Catalog;

use App\Entity\CatalogCategory;
use App\Entity\CatalogFeed;
use App\Service\Catalog\CatalogDocument;
use App\Service\Catalog\CatalogImporter;
use App\Service\Catalog\CatalogImportMode;
use App\Service\Catalog\ParsedCatalog;
use App\Tests\DbTestCase;
use Doctrine\ORM\EntityManagerInterface;

final class CatalogImporterTest extends DbTestCase
{
    private function em(): EntityManagerInterface
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);

        return $em;
    }

    private function importer(): CatalogImporter
    {
        $importer = self::getContainer()->get(CatalogImporter::class);
        self::assertInstanceOf(CatalogImporter::class, $importer);

        return $importer;
    }

    /**
     * @param list<array{title: string, url: string}> $feeds
     */
    private function document(array $feeds, string $key = 'technology', string $name = 'Technology'): ParsedCatalog
    {
        $outlines = '';
        foreach ($feeds as $feed) {
            $outlines .= \sprintf(
                '<outline type="rss" text="%s" xmlUrl="%s"/>',
                htmlspecialchars($feed['title'], \ENT_XML1),
                htmlspecialchars($feed['url'], \ENT_XML1),
            );
        }

        $parser = self::getContainer()->get(CatalogDocument::class);
        self::assertInstanceOf(CatalogDocument::class, $parser);

        return $parser->parse(\sprintf(
            '<opml version="2.0"><head><title>t</title></head><body>'
            . '<outline text="%s" key="%s" icon="memory" color="#3b82f6">%s</outline>'
            . '</body></opml>',
            $name,
            $key,
            $outlines,
        ));
    }

    public function testAFirstImportCreatesEverything(): void
    {
        $result = $this->importer()->import(
            $this->document([
                ['title' => 'The Verge', 'url' => 'https://www.theverge.com/rss/index.xml'],
                ['title' => 'Ars Technica', 'url' => 'https://feeds.arstechnica.com/arstechnica/index'],
            ]),
            CatalogImportMode::Merge,
        );

        self::assertSame(1, $result->categoriesCreated);
        self::assertSame(2, $result->feedsCreated);
        self::assertSame(0, $result->feedsUpdated);
        self::assertSame(0, $result->feedsRemoved);
    }

    public function testReimportingUpdatesInPlaceAndKeepsTheCachedFavicon(): void
    {
        $document = $this->document([
            ['title' => 'The Verge', 'url' => 'https://www.theverge.com/rss/index.xml'],
        ]);
        $this->importer()->import($document, CatalogImportMode::Merge);

        $feed = $this->em()->getRepository(CatalogFeed::class)->findOneBy([
            'url' => 'https://www.theverge.com/rss/index.xml',
        ]);
        self::assertNotNull($feed);
        $feed->storeFavicon('https://www.theverge.com/favicon.ico', 'PNGBYTES', 'image/png', new \DateTimeImmutable());
        $this->em()->flush();

        $renamed = $this->document([
            ['title' => 'The Verge (renamed)', 'url' => 'https://www.theverge.com/rss/index.xml'],
        ]);
        $result = $this->importer()->import($renamed, CatalogImportMode::Merge);

        self::assertSame(0, $result->feedsCreated);
        self::assertSame(1, $result->feedsUpdated);

        $this->em()->clear();
        $reloaded = $this->em()->getRepository(CatalogFeed::class)->findOneBy([
            'url' => 'https://www.theverge.com/rss/index.xml',
        ]);
        self::assertNotNull($reloaded);
        self::assertSame('The Verge (renamed)', $reloaded->getTitle());
        self::assertSame('PNGBYTES', $reloaded->getFaviconBytes(), 'a surviving URL keeps its icon');
    }

    public function testMergeLeavesRowsTheDocumentDoesNotMention(): void
    {
        $this->importer()->import(
            $this->document([['title' => 'Keep me', 'url' => 'https://keep.example.com/rss.xml']]),
            CatalogImportMode::Merge,
        );

        $result = $this->importer()->import(
            $this->document([['title' => 'New', 'url' => 'https://new.example.com/rss.xml']]),
            CatalogImportMode::Merge,
        );

        self::assertSame(0, $result->feedsRemoved);
        self::assertCount(2, $this->em()->getRepository(CatalogFeed::class)->findAll());
    }

    public function testReplaceRemovesRowsTheDocumentDoesNotMention(): void
    {
        $this->importer()->import(
            $this->document([['title' => 'Retired', 'url' => 'https://retired.example.com/rss.xml']]),
            CatalogImportMode::Merge,
        );

        $result = $this->importer()->import(
            $this->document([['title' => 'New', 'url' => 'https://new.example.com/rss.xml']]),
            CatalogImportMode::Replace,
        );

        self::assertSame(1, $result->feedsRemoved);

        $this->em()->clear();
        $remaining = $this->em()->getRepository(CatalogFeed::class)->findAll();
        self::assertCount(1, $remaining);
        self::assertSame('New', $remaining[0]->getTitle());
    }

    public function testReplaceAlsoRemovesACategoryTheDocumentDropped(): void
    {
        $this->importer()->import($this->document([], 'gone', 'Gone'), CatalogImportMode::Merge);
        $result = $this->importer()->import($this->document([]), CatalogImportMode::Replace);

        self::assertSame(1, $result->categoriesRemoved);

        $this->em()->clear();
        self::assertCount(1, $this->em()->getRepository(CatalogCategory::class)->findAll());
    }

    public function testReplaceKeepsALockedFeedTheDocumentNoLongerLists(): void
    {
        $this->importer()->import(
            $this->document([['title' => 'Mine', 'url' => 'https://mine.example.com/rss.xml']]),
            CatalogImportMode::Merge,
        );

        $feed = $this->em()->getRepository(CatalogFeed::class)->findOneBy(['title' => 'Mine']);
        self::assertNotNull($feed);
        $feed->setLocked(true);
        $this->em()->flush();

        $result = $this->importer()->import(
            $this->document([['title' => 'New', 'url' => 'https://new.example.com/rss.xml']]),
            CatalogImportMode::Replace,
        );

        self::assertSame(0, $result->feedsRemoved);
        self::assertSame(1, $result->lockedSkipped);

        $this->em()->clear();
        self::assertCount(2, $this->em()->getRepository(CatalogFeed::class)->findAll());
    }

    public function testALockedFeedIsNotOverwrittenByTheDocument(): void
    {
        $this->importer()->import(
            $this->document([['title' => 'Original', 'url' => 'https://locked.example.com/rss.xml']]),
            CatalogImportMode::Merge,
        );

        $feed = $this->em()->getRepository(CatalogFeed::class)->findOneBy(['title' => 'Original']);
        self::assertNotNull($feed);
        $feed->setLocked(true);
        $this->em()->flush();

        $result = $this->importer()->import(
            $this->document([['title' => 'Renamed by the document', 'url' => 'https://locked.example.com/rss.xml']]),
            CatalogImportMode::Merge,
        );

        self::assertSame(0, $result->feedsUpdated);
        self::assertSame(1, $result->lockedSkipped);

        $this->em()->clear();
        $reloaded = $this->em()->getRepository(CatalogFeed::class)->findOneBy([
            'url' => 'https://locked.example.com/rss.xml',
        ]);
        self::assertNotNull($reloaded);
        self::assertSame('Original', $reloaded->getTitle());
    }

    public function testReplaceKeepsACategoryThatStillHoldsALockedFeed(): void
    {
        $this->importer()->import(
            $this->document([['title' => 'Mine', 'url' => 'https://mine.example.com/rss.xml']], 'mine', 'Mine'),
            CatalogImportMode::Merge,
        );

        $feed = $this->em()->getRepository(CatalogFeed::class)->findOneBy(['title' => 'Mine']);
        self::assertNotNull($feed);
        $feed->setLocked(true);
        $this->em()->flush();

        // The document drops the whole 'mine' category. Removing it would
        // cascade to the locked feed, so it has to survive.
        $result = $this->importer()->import($this->document([]), CatalogImportMode::Replace);

        self::assertSame(0, $result->categoriesRemoved);

        $this->em()->clear();
        self::assertCount(1, $this->em()->getRepository(CatalogFeed::class)->findAll());
        self::assertCount(2, $this->em()->getRepository(CatalogCategory::class)->findAll());
    }

    public function testReplaceKeepsALockedCategory(): void
    {
        $this->importer()->import($this->document([], 'mine', 'Mine'), CatalogImportMode::Merge);

        $category = $this->em()->getRepository(CatalogCategory::class)->findOneBy(['key' => 'mine']);
        self::assertNotNull($category);
        $category->setLocked(true);
        $this->em()->flush();

        $result = $this->importer()->import($this->document([]), CatalogImportMode::Replace);

        self::assertSame(0, $result->categoriesRemoved);
        self::assertSame(1, $result->lockedSkipped);
    }

    public function testPositionsFollowDocumentOrder(): void
    {
        $this->importer()->import(
            $this->document([
                ['title' => 'First', 'url' => 'https://first.example.com/rss.xml'],
                ['title' => 'Second', 'url' => 'https://second.example.com/rss.xml'],
            ]),
            CatalogImportMode::Merge,
        );

        $this->em()->clear();
        $second = $this->em()->getRepository(CatalogFeed::class)->findOneBy(['title' => 'Second']);
        self::assertNotNull($second);
        self::assertSame(1, $second->getPosition());
    }
}
```

- [ ] **Step 2: Run it to confirm it fails**

Run: `php bin/phpunit tests/Service/Catalog/CatalogImporterTest.php`
Expected: FAIL — `Class "App\Service\Catalog\CatalogImporter" not found`.

- [ ] **Step 3: Write the mode and the result**

`backend/src/Service/Catalog/CatalogImportMode.php`:

```php
<?php

declare(strict_types=1);

namespace App\Service\Catalog;

/**
 * The two import modes differ in exactly one respect: what happens to rows the
 * document does not mention. Both upsert what it does mention, and both keep the
 * cached favicon of any feed whose URL survives.
 */
enum CatalogImportMode: string
{
    /** Rows the document does not mention are left alone. */
    case Merge = 'merge';

    /** Rows the document does not mention are deleted. */
    case Replace = 'replace';
}
```

`backend/src/Service/Catalog/CatalogImportResult.php`:

```php
<?php

declare(strict_types=1);

namespace App\Service\Catalog;

final readonly class CatalogImportResult
{
    public function __construct(
        public int $categoriesCreated = 0,
        public int $categoriesUpdated = 0,
        public int $categoriesRemoved = 0,
        public int $feedsCreated = 0,
        public int $feedsUpdated = 0,
        public int $feedsRemoved = 0,
        /** Locked rows the import left alone — reported so the admin can see the lock did something. */
        public int $lockedSkipped = 0,
    ) {
    }

    public function with(
        int $categoriesCreated = 0,
        int $categoriesUpdated = 0,
        int $categoriesRemoved = 0,
        int $feedsCreated = 0,
        int $feedsUpdated = 0,
        int $feedsRemoved = 0,
        int $lockedSkipped = 0,
    ): self {
        return new self(
            $this->categoriesCreated + $categoriesCreated,
            $this->categoriesUpdated + $categoriesUpdated,
            $this->categoriesRemoved + $categoriesRemoved,
            $this->feedsCreated + $feedsCreated,
            $this->feedsUpdated + $feedsUpdated,
            $this->feedsRemoved + $feedsRemoved,
            $this->lockedSkipped + $lockedSkipped,
        );
    }
}
```

- [ ] **Step 4: Write the importer**

`backend/src/Service/Catalog/CatalogImporter.php`:

```php
<?php

declare(strict_types=1);

namespace App\Service\Catalog;

use App\Entity\CatalogCategory;
use App\Entity\CatalogFeed;
use App\Repository\CatalogCategoryRepository;
use App\Repository\CatalogFeedRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Applies a validated catalog document to the database.
 *
 * Matching is by natural key — category by `key`, feed by `url` — never by id,
 * because the document has no ids and an admin may have edited the rows since
 * the last import. A feed whose URL survives keeps its row, and therefore its
 * cached favicon: re-importing must not cost a full re-warm.
 *
 * LOCKED rows belong to the admin, not the document: never overwritten, never
 * removed. A category holding a locked feed is protected too, or the cascade
 * would delete the very row the lock was protecting.
 *
 * The whole import runs in one transaction. A document is validated by
 * CatalogDocument before it gets here, so a failure at this point is
 * infrastructural, and a half-applied catalog is worse than none.
 */
final readonly class CatalogImporter
{
    public function __construct(
        private CatalogCategoryRepository $categories,
        private CatalogFeedRepository $feeds,
        private EntityManagerInterface $em,
    ) {
    }

    public function import(ParsedCatalog $document, CatalogImportMode $mode): CatalogImportResult
    {
        return $this->em->wrapInTransaction(function () use ($document, $mode): CatalogImportResult {
            $result = new CatalogImportResult();

            /** @var array<string, CatalogCategory> $existingCategories keyed by category key */
            $existingCategories = [];
            foreach ($this->categories->findAllOrdered() as $category) {
                $existingCategories[$category->getKey()] = $category;
            }

            /** @var array<string, CatalogFeed> $existingFeeds keyed by url */
            $existingFeeds = [];
            foreach ($this->feeds->findAll() as $feed) {
                $existingFeeds[$feed->getUrl()] = $feed;
            }

            $keptCategoryKeys = [];
            $keptFeedUrls = [];

            foreach ($document->categories as $position => $documentCategory) {
                $category = $existingCategories[$documentCategory->key] ?? null;
                if (null === $category) {
                    $category = new CatalogCategory(
                        $documentCategory->key,
                        $documentCategory->name,
                        $documentCategory->icon,
                        $documentCategory->color,
                    );
                    $this->em->persist($category);
                    $result = $result->with(categoriesCreated: 1);
                } elseif ($category->isLocked()) {
                    // Locked: the row is the admin's. Its feeds are still
                    // processed — locking a category protects the category, not
                    // the membership of the catalog underneath it.
                    $result = $result->with(lockedSkipped: 1);
                } else {
                    $category->setName($documentCategory->name);
                    $category->setIcon($documentCategory->icon);
                    $category->setColor($documentCategory->color);
                    $category->setPosition($position);
                    $result = $result->with(categoriesUpdated: 1);
                }
                $keptCategoryKeys[$documentCategory->key] = true;

                foreach ($documentCategory->feeds as $feedPosition => $documentFeed) {
                    $result = $this->applyFeed(
                        $documentFeed,
                        $category,
                        $feedPosition,
                        $existingFeeds[$documentFeed->url] ?? null,
                        $result,
                    );
                    $keptFeedUrls[$documentFeed->url] = true;
                }
            }

            if (CatalogImportMode::Replace === $mode) {
                $result = $this->removeUnmentioned(
                    $existingCategories,
                    $existingFeeds,
                    $keptCategoryKeys,
                    $keptFeedUrls,
                    $result,
                );
            }

            $this->em->flush();

            return $result;
        });
    }

    private function applyFeed(
        CatalogDocumentFeed $documentFeed,
        CatalogCategory $category,
        int $position,
        ?CatalogFeed $existing,
        CatalogImportResult $result,
    ): CatalogImportResult {
        if (null !== $existing && $existing->isLocked()) {
            // The admin's row. Not overwritten — and, because the caller has
            // already recorded its URL as kept, not removed either.
            return $result->with(lockedSkipped: 1);
        }

        if (null === $existing) {
            $feed = new CatalogFeed($category, $documentFeed->title, $documentFeed->url);
            $this->em->persist($feed);
            $result = $result->with(feedsCreated: 1);
        } else {
            // Matched on URL, so the row — and its cached favicon — survives.
            $feed = $existing;
            $feed->setTitle($documentFeed->title);
            $feed->setCategory($category);
            $result = $result->with(feedsUpdated: 1);
        }

        $feed->setSiteUrl($documentFeed->siteUrl);
        $feed->setDescription($documentFeed->description);
        $feed->setSourceFormat($documentFeed->sourceFormat);
        $feed->setPosition($position);

        return $result;
    }

    /**
     * @param array<string, CatalogCategory> $existingCategories
     * @param array<string, CatalogFeed>     $existingFeeds
     * @param array<string, true>            $keptCategoryKeys
     * @param array<string, true>            $keptFeedUrls
     */
    private function removeUnmentioned(
        array $existingCategories,
        array $existingFeeds,
        array $keptCategoryKeys,
        array $keptFeedUrls,
        CatalogImportResult $result,
    ): CatalogImportResult {
        // Categories that must survive because a locked feed lives in them —
        // removing one would cascade and take the locked feed with it, which
        // would defeat the lock.
        $holdingALockedFeed = [];

        foreach ($existingFeeds as $url => $feed) {
            if ($feed->isLocked()) {
                $holdingALockedFeed[$feed->getCategory()->getKey()] = true;
                if (!isset($keptFeedUrls[$url])) {
                    $result = $result->with(lockedSkipped: 1);
                }

                continue;
            }
            if (isset($keptFeedUrls[$url])) {
                continue;
            }
            $this->em->remove($feed);
            $result = $result->with(feedsRemoved: 1);
        }

        foreach ($existingCategories as $key => $category) {
            if (isset($keptCategoryKeys[$key])) {
                continue;
            }
            if ($category->isLocked() || isset($holdingALockedFeed[$key])) {
                $result = $result->with(lockedSkipped: 1);

                continue;
            }
            // Its remaining feeds go with it via the FK's ON DELETE CASCADE —
            // safe, because every locked feed has already reserved its category.
            $this->em->remove($category);
            $result = $result->with(categoriesRemoved: 1);
        }

        return $result;
    }
}
```

- [ ] **Step 5: Run the tests to verify they pass**

Run: `php bin/phpunit tests/Service/Catalog/CatalogImporterTest.php`
Expected: PASS, 10 tests.

- [ ] **Step 6: Run the gate and commit**

```bash
composer check && composer md
git add src/Service/Catalog/CatalogImporter.php src/Service/Catalog/CatalogImportMode.php src/Service/Catalog/CatalogImportResult.php tests/Service/Catalog/CatalogImporterTest.php
git commit -m "feat(catalog): importer with merge and replace modes (#99)"
```

---

### Task 5: Import endpoint and console command

The upload endpoint takes the document as a JSON request body rather than a multipart upload — the admin UI reads the chosen file and posts its contents. That keeps the API pure JSON, which the native-iOS constraint in [`docs/architecture.md`](../../architecture.md) §6 asks for.

There is a **second route that needs no file at all.** The release ships `resources/catalog/catalog.opml`, so an admin looking at an empty catalog can import it with one click rather than hunting for a file that is already on the server. `BundledCatalog` owns reading it, shared by that route and the console command, so "where the bundled catalog lives" is stated once.

**Files:**
- Create: `backend/src/Service/Catalog/BundledCatalog.php`
- Create: `backend/src/Dto/Admin/CatalogImportRequest.php`
- Create: `backend/src/Dto/Admin/CatalogImportModeRequest.php`
- Create: `backend/src/Controller/Admin/AdminCatalogImportController.php`
- Create: `backend/src/Command/ImportCatalogCommand.php`
- Test: `backend/tests/Controller/Admin/AdminCatalogImportControllerTest.php`
- Test: `backend/tests/Command/ImportCatalogCommandTest.php`

- [ ] **Step 1: Write the failing controller test**

`backend/tests/Controller/Admin/AdminCatalogImportControllerTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Controller\Admin;

use App\Entity\CatalogFeed;
use App\Tests\Support\UserFactory;
use Doctrine\ORM\EntityManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class AdminCatalogImportControllerTest extends WebTestCase
{
    /**
     * @param list<string> $roles
     *
     * @return array<string, string>
     */
    private function authHeader(string $email, array $roles): array
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $hasher = self::getContainer()->get(UserPasswordHasherInterface::class);
        self::assertInstanceOf(UserPasswordHasherInterface::class, $hasher);

        $user = (new UserFactory($em, $hasher))->create($email, roles: $roles);

        $tokens = self::getContainer()->get(JWTTokenManagerInterface::class);
        self::assertInstanceOf(JWTTokenManagerInterface::class, $tokens);

        return [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $tokens->create($user),
            'CONTENT_TYPE' => 'application/json',
        ];
    }

    /** @param list<array<string, string>> $feeds */
    private function payload(array $feeds, string $mode): string
    {
        return json_encode([
            'mode' => $mode,
            'document' => [
                'categories' => [
                    [
                        'key' => 'technology',
                        'name' => 'Technology',
                        'icon' => 'memory',
                        'color' => '#3b82f6',
                        'feeds' => $feeds,
                    ],
                ],
            ],
        ], \JSON_THROW_ON_ERROR);
    }

    public function testANonAdminIsRefused(): void
    {
        $client = self::createClient();
        $client->request(
            'POST',
            '/api/admin/catalog/import',
            server: $this->authHeader('plain@example.com', []),
            content: $this->payload([], 'merge'),
        );

        self::assertResponseStatusCodeSame(403);
    }

    public function testAdminCanImportAndGetsCounts(): void
    {
        $client = self::createClient();
        $headers = $this->authHeader('importer@example.com', ['ROLE_ADMIN']);

        $client->request(
            'POST',
            '/api/admin/catalog/import',
            server: $headers,
            content: $this->payload(
                [['title' => 'The Verge', 'url' => 'https://www.theverge.com/rss/index.xml']],
                'merge',
            ),
        );

        self::assertResponseIsSuccessful();
        $body = json_decode((string) $client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);

        self::assertSame(1, $body['categoriesCreated']);
        self::assertSame(1, $body['feedsCreated']);
    }

    public function testAMalformedDocumentChangesNothingAndReturns422(): void
    {
        $client = self::createClient();
        $headers = $this->authHeader('badimport@example.com', ['ROLE_ADMIN']);

        $client->request(
            'POST',
            '/api/admin/catalog/import',
            server: $headers,
            content: json_encode([
                'mode' => 'merge',
                'document' => ['categories' => [['key' => 'x', 'name' => 'X', 'icon' => 'memory', 'color' => 'not-a-colour', 'feeds' => []]]],
            ], \JSON_THROW_ON_ERROR),
        );

        self::assertResponseStatusCodeSame(422);

        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        self::assertCount(0, $em->getRepository(CatalogFeed::class)->findAll());
    }

    public function testAnUnknownModeIsRejected(): void
    {
        $client = self::createClient();
        $headers = $this->authHeader('mode@example.com', ['ROLE_ADMIN']);

        $client->request(
            'POST',
            '/api/admin/catalog/import',
            server: $headers,
            content: $this->payload([], 'obliterate'),
        );

        self::assertResponseStatusCodeSame(422);
    }

    public function testTheBundledDocumentIsDescribedWithoutImportingIt(): void
    {
        $client = self::createClient();
        $headers = $this->authHeader('bundledinfo@example.com', ['ROLE_ADMIN']);

        $client->request('GET', '/api/admin/catalog/bundled', server: $headers);

        self::assertResponseIsSuccessful();
        $body = json_decode((string) $client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);

        self::assertTrue($body['available']);
        self::assertSame(13, $body['categories']);
        self::assertSame(111, $body['feeds']);

        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        self::assertCount(0, $em->getRepository(CatalogFeed::class)->findAll(), 'describing must not import');
    }

    public function testTheBundledDocumentCanBeImportedWithoutUploadingAFile(): void
    {
        $client = self::createClient();
        $headers = $this->authHeader('bundled@example.com', ['ROLE_ADMIN']);

        $client->request(
            'POST',
            '/api/admin/catalog/import/bundled',
            server: $headers,
            content: json_encode(['mode' => 'merge'], \JSON_THROW_ON_ERROR),
        );

        self::assertResponseIsSuccessful();
        $body = json_decode((string) $client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);

        self::assertSame(13, $body['categoriesCreated']);
        self::assertSame(111, $body['feedsCreated']);
    }
}
```

- [ ] **Step 2: Run it to confirm it fails**

Run: `php bin/phpunit tests/Controller/Admin/AdminCatalogImportControllerTest.php`
Expected: FAIL — 404 on `/api/admin/catalog/import`.

- [ ] **Step 3: Write `BundledCatalog`**

`backend/src/Service/Catalog/BundledCatalog.php`:

```php
<?php

declare(strict_types=1);

namespace App\Service\Catalog;

use App\Service\Catalog\Exception\InvalidCatalogDocumentException;

/**
 * The catalog document this release ships. One owner for the path, shared by the
 * admin's one-click import and the console command.
 *
 * It is a starting point, not a source of truth: once imported, the database is
 * authoritative and the admin may edit it freely.
 */
final readonly class BundledCatalog
{
    public function __construct(
        private CatalogDocument $parser,
        private string $projectDir,
    ) {
    }

    public function path(): string
    {
        return $this->projectDir . '/resources/catalog/catalog.opml';
    }

    public function isAvailable(): bool
    {
        return is_file($this->path()) && is_readable($this->path());
    }

    public function document(): ParsedCatalog
    {
        if (!$this->isAvailable()) {
            throw new InvalidCatalogDocumentException(
                \sprintf('No readable catalog document at %s.', $this->path()),
            );
        }

        return $this->parser->parse((string) file_get_contents($this->path()));
    }
}
```

Wire the project directory in `backend/config/services.yaml`:

```yaml
    App\Service\Catalog\BundledCatalog:
        arguments:
            $projectDir: '%kernel.project_dir%'
```

- [ ] **Step 4: Write the request DTOs**

`backend/src/Dto/Admin/CatalogImportRequest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Dto\Admin;

use App\Service\Catalog\CatalogImportMode;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * The document arrives as OPML *text* inside an ordinary JSON body: the admin UI
 * reads the chosen file and posts its contents verbatim, so no multipart
 * handling is needed and the admin API stays pure JSON. CatalogDocument does the
 * real validation.
 */
final readonly class CatalogImportRequest
{
    public function __construct(
        #[Assert\NotNull]
        public ?CatalogImportMode $mode = null,
        #[Assert\NotBlank]
        #[Assert\Length(max: 2_000_000)]
        public string $document = '',
    ) {
    }
}
```

`backend/src/Dto/Admin/CatalogImportModeRequest.php` — the bundled import needs no document, only a mode:

```php
<?php

declare(strict_types=1);

namespace App\Dto\Admin;

use App\Service\Catalog\CatalogImportMode;
use Symfony\Component\Validator\Constraints as Assert;

final readonly class CatalogImportModeRequest
{
    public function __construct(
        #[Assert\NotNull]
        public ?CatalogImportMode $mode = null,
    ) {
    }
}
```

- [ ] **Step 5: Write the controller**

`backend/src/Controller/Admin/AdminCatalogImportController.php`:

```php
<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Dto\Admin\CatalogImportModeRequest;
use App\Dto\Admin\CatalogImportRequest;
use App\Service\Catalog\BundledCatalog;
use App\Service\Catalog\CatalogDocument;
use App\Service\Catalog\CatalogImporter;
use App\Service\Catalog\CatalogImportResult;
use App\Service\Catalog\Exception\InvalidCatalogDocumentException;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Catalog import. Access is enforced by ROLE_ADMIN on ^/api/admin/ in the
 * firewall, consistent with the other admin controllers.
 */
#[Route('/api/admin/catalog')]
final class AdminCatalogImportController
{
    public function __construct(
        private readonly CatalogImporter $importer,
        private readonly CatalogDocument $parser,
        private readonly BundledCatalog $bundled,
    ) {
    }

    /**
     * What the shipped document would import, without importing it. Lets the
     * admin UI label its one-click button with real numbers instead of asking
     * the admin to take it on faith.
     */
    #[Route('/bundled', name: 'api_admin_catalog_bundled', methods: ['GET'])]
    public function describeBundled(): JsonResponse
    {
        try {
            $document = $this->bundled->document();
        } catch (InvalidCatalogDocumentException) {
            // Missing or corrupt: report it as unavailable rather than 500. The
            // admin can still upload a file, which is the more useful answer.
            return new JsonResponse(['available' => false, 'categories' => 0, 'feeds' => 0]);
        }

        return new JsonResponse([
            'available' => true,
            'categories' => \count($document->categories),
            'feeds' => $document->feedCount(),
        ]);
    }

    /**
     * Import the document this release ships — no upload. The common case is an
     * admin who has just been told the catalog is empty; making them locate a
     * file that is already on the server would be busywork.
     */
    #[Route('/import/bundled', name: 'api_admin_catalog_import_bundled', methods: ['POST'])]
    public function importBundled(#[MapRequestPayload] CatalogImportModeRequest $request): JsonResponse
    {
        try {
            $document = $this->bundled->document();
        } catch (InvalidCatalogDocumentException $e) {
            throw new UnprocessableEntityHttpException($e->getMessage(), $e);
        }

        return $this->respond($this->importer->import(
            $document,
            $request->mode ?? throw new UnprocessableEntityHttpException('A mode is required.'),
        ));
    }

    #[Route('/import', name: 'api_admin_catalog_import', methods: ['POST'])]
    public function import(#[MapRequestPayload] CatalogImportRequest $request): JsonResponse
    {
        try {
            $document = $this->parser->parse($request->document);
        } catch (InvalidCatalogDocumentException $e) {
            // 422, not 500: the upload is the user's input, and nothing was
            // written — validation happens entirely before the importer runs.
            throw new UnprocessableEntityHttpException($e->getMessage(), $e);
        }

        return $this->respond($this->importer->import(
            $document,
            $request->mode ?? throw new UnprocessableEntityHttpException('A mode is required.'),
        ));
    }

    private function respond(CatalogImportResult $result): JsonResponse
    {
        return new JsonResponse([
            'categoriesCreated' => $result->categoriesCreated,
            'categoriesUpdated' => $result->categoriesUpdated,
            'categoriesRemoved' => $result->categoriesRemoved,
            'feedsCreated' => $result->feedsCreated,
            'feedsUpdated' => $result->feedsUpdated,
            'feedsRemoved' => $result->feedsRemoved,
            'lockedSkipped' => $result->lockedSkipped,
        ]);
    }
}
```

- [ ] **Step 6: Write the console command and its test**

`backend/tests/Command/ImportCatalogCommandTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Entity\CatalogFeed;
use App\Tests\DbTestCase;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

final class ImportCatalogCommandTest extends DbTestCase
{
    private function tester(): CommandTester
    {
        $application = new Application(self::$kernel);

        return new CommandTester($application->find('app:catalog:import'));
    }

    public function testImportsTheShippedDocumentByDefault(): void
    {
        $tester = $this->tester();
        $tester->execute([]);

        self::assertSame(0, $tester->getStatusCode());

        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        self::assertCount(111, $em->getRepository(CatalogFeed::class)->findAll());
    }

    public function testAMissingFileIsAnError(): void
    {
        $tester = $this->tester();
        $tester->execute(['--file' => '/nonexistent/catalog.opml']);

        self::assertSame(1, $tester->getStatusCode());
    }
}
```

`backend/src/Command/ImportCatalogCommand.php`:

```php
<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\Catalog\BundledCatalog;
use App\Service\Catalog\CatalogDocument;
use App\Service\Catalog\CatalogImporter;
use App\Service\Catalog\CatalogImportMode;
use App\Service\Catalog\Exception\InvalidCatalogDocumentException;
use App\Service\Catalog\ParsedCatalog;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Imports a catalog document from disk, defaulting to the one this release
 * ships. The admin area is the primary route; this exists so a fresh
 * environment — a developer's box, the e2e stack — can be seeded without
 * clicking through the UI.
 */
#[AsCommand(
    name: 'app:catalog:import',
    description: 'Import a catalog OPML document',
)]
final class ImportCatalogCommand extends Command
{
    public function __construct(
        private readonly CatalogImporter $importer,
        private readonly CatalogDocument $parser,
        private readonly BundledCatalog $bundled,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('file', null, InputOption::VALUE_REQUIRED, 'Path to a catalog OPML document')
            ->addOption('mode', null, InputOption::VALUE_REQUIRED, 'merge or replace', CatalogImportMode::Merge->value);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $mode = CatalogImportMode::tryFrom((string) $input->getOption('mode'));
        if (null === $mode) {
            $io->error('Mode must be "merge" or "replace".');

            return Command::FAILURE;
        }

        $fileOption = $input->getOption('file');
        $explicitPath = \is_string($fileOption) && '' !== $fileOption ? $fileOption : null;

        try {
            $document = null === $explicitPath
                ? $this->bundled->document()
                : $this->read($explicitPath);
        } catch (InvalidCatalogDocumentException $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }

        $result = $this->importer->import($document, $mode);

        $io->success(\sprintf(
            'Catalog imported (%s): categories +%d ~%d -%d, feeds +%d ~%d -%d.',
            $mode->value,
            $result->categoriesCreated,
            $result->categoriesUpdated,
            $result->categoriesRemoved,
            $result->feedsCreated,
            $result->feedsUpdated,
            $result->feedsRemoved,
        ));

        return Command::SUCCESS;
    }

    private function read(string $path): ParsedCatalog
    {
        if (!is_file($path) || !is_readable($path)) {
            throw new InvalidCatalogDocumentException(
                \sprintf('No readable catalog document at %s.', $path),
            );
        }

        return $this->parser->parse((string) file_get_contents($path));
    }
}
```

`BundledCatalog` is autowired, so the command needs no `services.yaml` entry of its own — the one added in Step 3 covers it.

- [ ] **Step 7: Run the tests to verify they pass**

```bash
php bin/phpunit tests/Controller/Admin/AdminCatalogImportControllerTest.php
php bin/phpunit tests/Command/ImportCatalogCommandTest.php
```

Expected: PASS, 6 + 2 tests.

- [ ] **Step 8: Seed the Docker stack and eyeball it**

```bash
docker compose exec php bin/console doctrine:migrations:migrate --no-interaction
docker compose exec php bin/console app:catalog:import
docker compose exec php bin/console dbal:run-sql "SELECT COUNT(*) FROM catalog_feed"
```

Expected: 111.

- [ ] **Step 9: Run the gate and commit**

```bash
composer check && composer md && php bin/phpunit
git add src/Service/Catalog/BundledCatalog.php src/Dto/Admin/CatalogImportRequest.php src/Dto/Admin/CatalogImportModeRequest.php src/Controller/Admin/AdminCatalogImportController.php src/Command/ImportCatalogCommand.php config/services.yaml tests/Controller/Admin/AdminCatalogImportControllerTest.php tests/Command/ImportCatalogCommandTest.php
git commit -m "feat(catalog): admin import, bundled one-click import and app:catalog:import (#99)"
```

---

### Task 6: `GET /api/catalog`

**Files:**
- Create: `backend/src/Http/CatalogJson.php`
- Create: `backend/src/Controller/Api/CatalogController.php`
- Test: `backend/tests/Controller/Api/CatalogControllerTest.php`

- [ ] **Step 1: Write the failing test**

`backend/tests/Controller/Api/CatalogControllerTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Controller\Api;

use App\Entity\CatalogCategory;
use App\Entity\CatalogFeed;
use App\Entity\Feed;
use App\Entity\Subscription;
use App\Tests\Support\UserFactory;
use Doctrine\ORM\EntityManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Fixtures are built in-test on purpose: tests/bootstrap.php creates the schema
 * from ORM metadata, so no migration ever runs and the catalog tables are EMPTY
 * here until something imports one. A test written against the shipped catalog
 * would depend on an import having run, which no test fixture guarantees.
 */
final class CatalogControllerTest extends WebTestCase
{
    /** @return array<string, string> */
    private function authHeader(string $email): array
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $hasher = self::getContainer()->get(UserPasswordHasherInterface::class);
        self::assertInstanceOf(UserPasswordHasherInterface::class, $hasher);

        $user = (new UserFactory($em, $hasher))->create($email);

        $tokens = self::getContainer()->get(JWTTokenManagerInterface::class);
        self::assertInstanceOf(JWTTokenManagerInterface::class, $tokens);

        return [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $tokens->create($user),
            'CONTENT_TYPE' => 'application/json',
        ];
    }

    public function testAnonymousIsRejected(): void
    {
        $client = self::createClient();
        $client->request('GET', '/api/catalog');
        self::assertResponseStatusCodeSame(401);
    }

    public function testCategoriesAndFeedsComeBackInOrderWithoutDisabledRows(): void
    {
        $client = self::createClient();
        $headers = $this->authHeader('catalog@example.com');

        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);

        $technology = new CatalogCategory('technology', 'Technology', 'memory', '#3b82f6');
        $technology->setPosition(0);
        $science = new CatalogCategory('science', 'Science', 'science', '#14b8a6');
        $science->setPosition(1);

        $verge = new CatalogFeed($technology, 'The Verge', 'https://www.theverge.com/rss/index.xml');
        $verge->setDescription('Tech, science and culture');
        $verge->setSiteUrl('https://www.theverge.com');
        $retired = new CatalogFeed($technology, 'Retired', 'https://example.com/gone.xml');
        $retired->setEnabled(false);
        $quanta = new CatalogFeed($science, 'Quanta Magazine', 'https://api.quantamagazine.org/feed/');

        foreach ([$technology, $science, $verge, $retired, $quanta] as $row) {
            $em->persist($row);
        }
        $em->flush();

        $client->request('GET', '/api/catalog', server: $headers);

        self::assertResponseIsSuccessful();
        $body = json_decode((string) $client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);

        self::assertSame(['Technology', 'Science'], array_column($body['categories'], 'name'));
        self::assertSame('#3b82f6', $body['categories'][0]['color']);
        self::assertSame(['The Verge'], array_column($body['categories'][0]['feeds'], 'title'));
        self::assertFalse($body['categories'][0]['feeds'][0]['subscribed']);
        self::assertSame(
            '/api/catalog/feeds/' . $verge->getId() . '/favicon',
            $body['categories'][0]['feeds'][0]['faviconUrl'],
        );
    }

    public function testAFeedTheUserAlreadySubscribesToIsMarkedSubscribed(): void
    {
        $client = self::createClient();
        $headers = $this->authHeader('subscribed@example.com');

        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $clock = self::getContainer()->get(ClockInterface::class);
        self::assertInstanceOf(ClockInterface::class, $clock);

        $category = new CatalogCategory('technology', 'Technology', 'memory', '#3b82f6');
        $catalogFeed = new CatalogFeed($category, 'The Verge', 'https://www.theverge.com/rss/index.xml');

        $user = $em->getRepository(\App\Entity\User::class)->findOneBy(['email' => 'subscribed@example.com']);
        self::assertNotNull($user);

        $feed = new Feed('https://www.theverge.com/rss/index.xml');
        $subscription = new Subscription($user, $feed, $clock->now());

        foreach ([$category, $catalogFeed, $feed, $subscription] as $row) {
            $em->persist($row);
        }
        $em->flush();

        $client->request('GET', '/api/catalog', server: $headers);

        self::assertResponseIsSuccessful();
        $body = json_decode((string) $client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);
        self::assertTrue($body['categories'][0]['feeds'][0]['subscribed']);
    }
}
```

- [ ] **Step 2: Run it to confirm it fails**

Run: `php bin/phpunit tests/Controller/Api/CatalogControllerTest.php`
Expected: FAIL — 404 on `/api/catalog`.

- [ ] **Step 3: Add the repository method the `subscribed` flag needs**

Add to `backend/src/Repository/SubscriptionRepository.php`:

```php
    /**
     * The feed URLs this user is subscribed to, as a lookup set. The catalog
     * marks its entries `subscribed` by URL rather than by feed id because a
     * catalog row knows a URL, not which shared Feed row it became.
     *
     * @return array<string, true>
     */
    public function subscribedFeedUrlSet(int $userId): array
    {
        /** @var list<array{url: string}> $rows */
        $rows = $this->createQueryBuilder('s')
            ->select('f.url AS url')
            ->innerJoin('s.feed', 'f')
            ->andWhere('s.user = :userId')->setParameter('userId', $userId)
            ->getQuery()
            ->getArrayResult();

        $set = [];
        foreach ($rows as $row) {
            $set[$row['url']] = true;
        }

        return $set;
    }
```

- [ ] **Step 4: Write `CatalogJson`**

`backend/src/Http/CatalogJson.php`:

```php
<?php

declare(strict_types=1);

namespace App\Http;

use App\Entity\CatalogCategory;
use App\Entity\CatalogFeed;

/**
 * Serialisation for the onboarding picker. Deliberately carries no favicon
 * bytes — only the URL of the endpoint that serves them — so the payload stays
 * small enough to send all 111 rows in one response.
 */
final class CatalogJson
{
    /**
     * @param list<CatalogCategory>  $categories
     * @param array<string, true>    $subscribedUrls
     *
     * @return array{categories: list<array<string, mixed>>}
     */
    public static function many(array $categories, array $subscribedUrls): array
    {
        return [
            'categories' => array_map(
                static fn (CatalogCategory $category): array => self::category($category, $subscribedUrls),
                $categories,
            ),
        ];
    }

    /**
     * @param array<string, true> $subscribedUrls
     *
     * @return array<string, mixed>
     */
    private static function category(CatalogCategory $category, array $subscribedUrls): array
    {
        return [
            'id' => $category->getId(),
            'key' => $category->getKey(),
            'name' => $category->getName(),
            'icon' => $category->getIcon(),
            'color' => $category->getColor(),
            'feeds' => array_map(
                static fn (CatalogFeed $feed): array => self::feed($feed, $subscribedUrls),
                $category->getEnabledFeeds(),
            ),
        ];
    }

    /**
     * @param array<string, true> $subscribedUrls
     *
     * @return array<string, mixed>
     */
    private static function feed(CatalogFeed $feed, array $subscribedUrls): array
    {
        return [
            'id' => $feed->getId(),
            'title' => $feed->getTitle(),
            'description' => $feed->getDescription(),
            'siteUrl' => $feed->getSiteUrl(),
            'faviconUrl' => '/api/catalog/feeds/' . $feed->getId() . '/favicon',
            'subscribed' => isset($subscribedUrls[$feed->getUrl()]),
        ];
    }
}
```

- [ ] **Step 5: Write the controller**

`backend/src/Controller/Api/CatalogController.php` (the favicon route arrives in Task 9):

```php
<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Entity\User;
use App\Http\CatalogJson;
use App\Repository\CatalogCategoryRepository;
use App\Repository\SubscriptionRepository;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

#[Route('/api/catalog')]
final class CatalogController
{
    public function __construct(
        private readonly CatalogCategoryRepository $categories,
        private readonly SubscriptionRepository $subscriptions,
    ) {
    }

    #[Route('', name: 'api_catalog_list', methods: ['GET'])]
    public function list(#[CurrentUser] User $user): JsonResponse
    {
        return new JsonResponse(CatalogJson::many(
            $this->categories->findEnabledWithFeeds(),
            $this->subscriptions->subscribedFeedUrlSet((int) $user->getId()),
        ));
    }
}
```

- [ ] **Step 6: Run the tests to verify they pass**

Run: `php bin/phpunit tests/Controller/Api/CatalogControllerTest.php`
Expected: PASS, 3 tests.

- [ ] **Step 7: Run the gate and commit**

```bash
composer check && composer md && php bin/phpunit
git add src/Http/CatalogJson.php src/Controller/Api/CatalogController.php src/Repository/SubscriptionRepository.php tests/Controller/Api/CatalogControllerTest.php
git commit -m "feat(catalog): GET /api/catalog with per-user subscribed flags (#99)"
```

---

# Phase 2 — Favicons

### Task 7: The monogram placeholder

**Files:**
- Create: `backend/src/Service/Catalog/MonogramFavicon.php`
- Test: `backend/tests/Service/Catalog/MonogramFaviconTest.php`

- [ ] **Step 1: Write the failing test**

`backend/tests/Service/Catalog/MonogramFaviconTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Service\Catalog;

use App\Entity\CatalogCategory;
use App\Entity\CatalogFeed;
use App\Service\Catalog\MonogramFavicon;
use PHPUnit\Framework\TestCase;

final class MonogramFaviconTest extends TestCase
{
    private function feed(string $title): CatalogFeed
    {
        $category = new CatalogCategory('technology', 'Technology', 'memory', '#3b82f6');

        return new CatalogFeed($category, $title, 'https://example.com/feed.xml');
    }

    public function testRendersTheFirstLetterOnTheCategoryColour(): void
    {
        $svg = (new MonogramFavicon())->render($this->feed('The Verge'));

        self::assertStringStartsWith('<svg ', $svg);
        self::assertStringContainsString('#3b82f6', $svg);
        self::assertStringContainsString('>T<', $svg);
    }

    public function testIsDeterministic(): void
    {
        $monogram = new MonogramFavicon();

        self::assertSame(
            $monogram->render($this->feed('Ars Technica')),
            $monogram->render($this->feed('Ars Technica')),
        );
    }

    public function testEscapesATitleThatWouldOtherwiseInjectMarkup(): void
    {
        $svg = (new MonogramFavicon())->render($this->feed('<script>'));

        self::assertStringNotContainsString('<script', $svg);
        self::assertStringContainsString('&lt;', $svg);
    }

    public function testFallsBackToAQuestionMarkForAnEmptyTitle(): void
    {
        $svg = (new MonogramFavicon())->render($this->feed(''));

        self::assertStringContainsString('>?<', $svg);
    }
}
```

- [ ] **Step 2: Run it to confirm it fails**

Run: `php bin/phpunit tests/Service/Catalog/MonogramFaviconTest.php`
Expected: FAIL — `Class "App\Service\Catalog\MonogramFavicon" not found`.

- [ ] **Step 3: Implement it**

`backend/src/Service/Catalog/MonogramFavicon.php`:

```php
<?php

declare(strict_types=1);

namespace App\Service\Catalog;

use App\Entity\CatalogFeed;

/**
 * The offline stand-in for a favicon: the feed's initial on its category colour,
 * as SVG. Serving this instead of fetching on a cache miss is what keeps the
 * picker free of outbound requests — 111 cards render with no network fan-out,
 * and e2e works with no publisher reachable.
 */
final readonly class MonogramFavicon
{
    public const string CONTENT_TYPE = 'image/svg+xml';

    public function render(CatalogFeed $feed): string
    {
        $initial = mb_strtoupper(mb_substr(trim($feed->getTitle()), 0, 1));
        if ('' === $initial) {
            $initial = '?';
        }

        $letter = htmlspecialchars($initial, \ENT_QUOTES | \ENT_SUBSTITUTE, 'UTF-8');
        $color = htmlspecialchars($feed->getCategory()->getColor(), \ENT_QUOTES | \ENT_SUBSTITUTE, 'UTF-8');

        return \sprintf(
            '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 32 32" width="32" height="32">'
            . '<rect width="32" height="32" rx="7" fill="%s"/>'
            . '<text x="16" y="17" fill="#ffffff" font-family="system-ui,sans-serif" font-size="17" '
            . 'font-weight="700" text-anchor="middle" dominant-baseline="central">%s</text>'
            . '</svg>',
            $color,
            $letter,
        );
    }
}
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `php bin/phpunit tests/Service/Catalog/MonogramFaviconTest.php`
Expected: PASS, 4 tests.

- [ ] **Step 5: Commit**

```bash
composer check && composer md
git add src/Service/Catalog/MonogramFavicon.php tests/Service/Catalog/MonogramFaviconTest.php
git commit -m "feat(catalog): deterministic monogram favicon placeholder (#99)"
```

---

### Task 8: The guarded favicon fetcher

`UrlGuard::assertSafe()` is the existing SSRF boundary (DNS resolution up front, private/reserved IP rejection). Inject it — do **not** write a second host check.

**Files:**
- Create: `backend/src/Service/Catalog/Exception/FaviconUnavailableException.php`
- Create: `backend/src/Service/Catalog/CatalogFaviconFetcher.php`
- Test: `backend/tests/Service/Catalog/CatalogFaviconFetcherTest.php`

- [ ] **Step 1: Write the failing test**

`backend/tests/Service/Catalog/CatalogFaviconFetcherTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Service\Catalog;

use App\Service\Catalog\CatalogFaviconFetcher;
use App\Service\Catalog\Exception\FaviconUnavailableException;
use App\Service\Fetch\Exception\SsrfBlockedException;
use App\Service\Fetch\GuardedUrl;
use App\Service\Fetch\UrlGuard;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class CatalogFaviconFetcherTest extends TestCase
{
    private const string ICON_URL = 'https://www.theverge.com/favicon.ico';

    private function fetcher(MockHttpClient $client, ?UrlGuard $guard = null): CatalogFaviconFetcher
    {
        $guard ??= $this->createConfiguredMock(UrlGuard::class, [
            'assertSafe' => new GuardedUrl('www.theverge.com', '93.184.216.34'),
        ]);

        return new CatalogFaviconFetcher($client, $guard);
    }

    public function testReturnsTheBytesAndContentTypeOfAnImageResponse(): void
    {
        $client = new MockHttpClient(new MockResponse('BINARY', [
            'response_headers' => ['content-type' => ['image/png']],
        ]));

        $icon = $this->fetcher($client)->download(self::ICON_URL);

        self::assertSame('BINARY', $icon->bytes);
        self::assertSame('image/png', $icon->contentType);
        self::assertSame(self::ICON_URL, $icon->sourceUrl);
    }

    public function testRejectsANonImageContentType(): void
    {
        $client = new MockHttpClient(new MockResponse('<html></html>', [
            'response_headers' => ['content-type' => ['text/html']],
        ]));

        $this->expectException(FaviconUnavailableException::class);
        $this->fetcher($client)->download(self::ICON_URL);
    }

    public function testRejectsAnOversizedResponse(): void
    {
        $client = new MockHttpClient(new MockResponse(
            str_repeat('x', CatalogFaviconFetcher::MAX_BYTES + 1),
            ['response_headers' => ['content-type' => ['image/png']]],
        ));

        $this->expectException(FaviconUnavailableException::class);
        $this->fetcher($client)->download(self::ICON_URL);
    }

    public function testRejectsANonSuccessStatus(): void
    {
        $client = new MockHttpClient(new MockResponse('', ['http_code' => 404]));

        $this->expectException(FaviconUnavailableException::class);
        $this->fetcher($client)->download(self::ICON_URL);
    }

    public function testPropagatesTheSsrfGuardAsAnUnavailableIcon(): void
    {
        $guard = $this->createMock(UrlGuard::class);
        $guard->method('assertSafe')->willThrowException(new SsrfBlockedException('private address'));

        $client = new MockHttpClient(new MockResponse('BINARY'));

        $this->expectException(FaviconUnavailableException::class);
        $this->fetcher($client, $guard)->download(self::ICON_URL);
    }
}
```

- [ ] **Step 2: Run it to confirm it fails**

Run: `php bin/phpunit tests/Service/Catalog/CatalogFaviconFetcherTest.php`
Expected: FAIL — `Class "App\Service\Catalog\CatalogFaviconFetcher" not found`.

- [ ] **Step 3: Write the exception**

`backend/src/Service/Catalog/Exception/FaviconUnavailableException.php`:

```php
<?php

declare(strict_types=1);

namespace App\Service\Catalog\Exception;

/**
 * No usable icon could be fetched for a catalog row. The caller records
 * faviconFailedAt and moves on — a missing icon degrades to the monogram, so
 * this is never fatal to anything.
 */
final class FaviconUnavailableException extends \RuntimeException
{
}
```

- [ ] **Step 4: Write the fetcher and its result object**

`backend/src/Service/Catalog/FetchedFavicon.php`:

```php
<?php

declare(strict_types=1);

namespace App\Service\Catalog;

final readonly class FetchedFavicon
{
    public function __construct(
        public string $sourceUrl,
        public string $bytes,
        public string $contentType,
    ) {
    }
}
```

`backend/src/Service/Catalog/CatalogFaviconFetcher.php`:

```php
<?php

declare(strict_types=1);

namespace App\Service\Catalog;

use App\Service\Catalog\Exception\FaviconUnavailableException;
use App\Service\Fetch\Exception\SsrfBlockedException;
use App\Service\Fetch\UrlGuard;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Downloads the bytes of one already-resolved icon URL, under the same guards as
 * the feed fetch path: the shared UrlGuard for SSRF, a bounded redirect chain, a
 * timeout, a size cap and an allow-list of image content types.
 *
 * Resolution — a site homepage to its best icon URL — is NOT this class's job.
 * The warmer resolves a whole slice at once through the shared, concurrent
 * `FaviconResolver::resolveAll()` (see #116) and hands each URL here to download.
 *
 * Invoked ONLY by the warmer. No request path fetches an icon.
 */
final readonly class CatalogFaviconFetcher
{
    public const int MAX_BYTES = 262144;

    private const int TIMEOUT_SECONDS = 8;
    private const int MAX_REDIRECTS = 3;

    /** Formats a browser will render in an <img>. SVG is excluded deliberately:
     *  it is a script-carrying document format, and we serve these bytes back
     *  from our own origin. */
    private const array ALLOWED_TYPES = [
        'image/png',
        'image/jpeg',
        'image/gif',
        'image/webp',
        'image/x-icon',
        'image/vnd.microsoft.icon',
    ];

    public function __construct(
        private HttpClientInterface $httpClient,
        private UrlGuard $urlGuard,
    ) {
    }

    public function download(string $iconUrl): FetchedFavicon
    {
        try {
            $this->urlGuard->assertSafe($iconUrl);
            $response = $this->httpClient->request('GET', $iconUrl, [
                'timeout' => self::TIMEOUT_SECONDS,
                'max_duration' => self::TIMEOUT_SECONDS,
                'max_redirects' => self::MAX_REDIRECTS,
            ]);

            if (200 !== $response->getStatusCode()) {
                throw new FaviconUnavailableException('Icon responded ' . $response->getStatusCode() . '.');
            }

            $contentType = $this->assertAllowedType($response->getHeaders(false));
            $bytes = $response->getContent();
        } catch (SsrfBlockedException | TransportException $e) {
            throw new FaviconUnavailableException($e->getMessage(), 0, $e);
        }

        if ('' === $bytes) {
            throw new FaviconUnavailableException('Icon body was empty.');
        }
        if (\strlen($bytes) > self::MAX_BYTES) {
            throw new FaviconUnavailableException('Icon exceeded ' . self::MAX_BYTES . ' bytes.');
        }

        return new FetchedFavicon($iconUrl, $bytes, $contentType);
    }

    /**
     * @param array<string, list<string>> $headers
     */
    private function assertAllowedType(array $headers): string
    {
        $raw = $headers['content-type'][0] ?? '';
        $type = mb_strtolower(trim(explode(';', $raw)[0]));

        if (!\in_array($type, self::ALLOWED_TYPES, true)) {
            throw new FaviconUnavailableException(\sprintf('Content type "%s" is not an allowed image type.', $type));
        }

        return $type;
    }
}
```

- [ ] **Step 5: Run the tests to verify they pass**

Run: `php bin/phpunit tests/Service/Catalog/CatalogFaviconFetcherTest.php`
Expected: PASS, 5 tests.

- [ ] **Step 6: Run the gate and commit**

```bash
composer check && composer md
git add src/Service/Catalog tests/Service/Catalog/CatalogFaviconFetcherTest.php
git commit -m "feat(catalog): guarded favicon fetcher reusing UrlGuard (#99)"
```

---

### Task 9: The favicon endpoint

**Files:**
- Modify: `backend/src/Controller/Api/CatalogController.php`
- Test: `backend/tests/Controller/Api/CatalogFaviconControllerTest.php`

- [ ] **Step 1: Write the failing test**

`backend/tests/Controller/Api/CatalogFaviconControllerTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Controller\Api;

use App\Entity\CatalogCategory;
use App\Entity\CatalogFeed;
use App\Tests\Support\UserFactory;
use Doctrine\ORM\EntityManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class CatalogFaviconControllerTest extends WebTestCase
{
    /** @return array<string, string> */
    private function authHeader(string $email): array
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $hasher = self::getContainer()->get(UserPasswordHasherInterface::class);
        self::assertInstanceOf(UserPasswordHasherInterface::class, $hasher);

        $user = (new UserFactory($em, $hasher))->create($email);

        $tokens = self::getContainer()->get(JWTTokenManagerInterface::class);
        self::assertInstanceOf(JWTTokenManagerInterface::class, $tokens);

        return ['HTTP_AUTHORIZATION' => 'Bearer ' . $tokens->create($user)];
    }

    private function persistFeed(bool $withIcon): CatalogFeed
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);

        $category = new CatalogCategory('technology', 'Technology', 'memory', '#3b82f6');
        $feed = new CatalogFeed($category, 'The Verge', 'https://www.theverge.com/rss/index.xml');
        if ($withIcon) {
            $feed->storeFavicon(
                'https://www.theverge.com/favicon.ico',
                'PNGBYTES',
                'image/png',
                new \DateTimeImmutable('2026-07-26 10:00:00'),
            );
        }

        $em->persist($category);
        $em->persist($feed);
        $em->flush();

        return $feed;
    }

    public function testServesTheCachedBytesWithAnEtag(): void
    {
        $client = self::createClient();
        $headers = $this->authHeader('icons@example.com');
        $feed = $this->persistFeed(withIcon: true);

        $client->request('GET', '/api/catalog/feeds/' . $feed->getId() . '/favicon', server: $headers);

        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Content-Type', 'image/png');
        self::assertSame('PNGBYTES', $client->getResponse()->getContent());
        self::assertNotNull($client->getResponse()->getEtag());
    }

    public function testServesTheMonogramWhenNoIconIsCached(): void
    {
        $client = self::createClient();
        $headers = $this->authHeader('placeholder@example.com');
        $feed = $this->persistFeed(withIcon: false);

        $client->request('GET', '/api/catalog/feeds/' . $feed->getId() . '/favicon', server: $headers);

        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Content-Type', 'image/svg+xml');
        self::assertStringContainsString('>T<', (string) $client->getResponse()->getContent());
    }

    public function testAnUnknownFeedIs404(): void
    {
        $client = self::createClient();
        $headers = $this->authHeader('missing@example.com');

        $client->request('GET', '/api/catalog/feeds/999999/favicon', server: $headers);

        self::assertResponseStatusCodeSame(404);
    }
}
```

- [ ] **Step 2: Run it to confirm it fails**

Run: `php bin/phpunit tests/Controller/Api/CatalogFaviconControllerTest.php`
Expected: FAIL — 404 on the favicon route.

- [ ] **Step 3: Add the route**

Add to `backend/src/Controller/Api/CatalogController.php` — inject `CatalogFeedRepository $feeds` and `MonogramFavicon $monogram` into the constructor, then:

```php
    /**
     * Cached bytes, or the monogram on a miss. NEVER fetches: a cache miss here
     * is a normal state, filled by app:catalog:warm-favicons at deploy time.
     * The long max-age is safe because the URL is per-feed-id and the ETag
     * changes whenever the bytes do.
     */
    #[Route('/feeds/{id}/favicon', name: 'api_catalog_favicon', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function favicon(int $id): Response
    {
        $feed = $this->feeds->find($id) ?? throw new NotFoundHttpException('No such catalog feed.');

        $bytes = $feed->getFaviconBytes();
        $contentType = $feed->getFaviconContentType();

        if (null === $bytes || null === $contentType) {
            $bytes = $this->monogram->render($feed);
            $contentType = MonogramFavicon::CONTENT_TYPE;
        }

        $response = new Response($bytes, Response::HTTP_OK, ['Content-Type' => $contentType]);
        $response->setEtag(md5($bytes));
        $response->setPublic();
        $response->setMaxAge(86400);

        return $response;
    }
```

Add the imports: `App\Repository\CatalogFeedRepository`, `App\Service\Catalog\MonogramFavicon`, `Symfony\Component\HttpFoundation\Response`, `Symfony\Component\HttpKernel\Exception\NotFoundHttpException`.

- [ ] **Step 4: Run the tests to verify they pass**

Run: `php bin/phpunit tests/Controller/Api/CatalogFaviconControllerTest.php`
Expected: PASS, 3 tests.

- [ ] **Step 5: Run the gate and commit**

```bash
composer check && composer md && php bin/phpunit
git add src/Controller/Api/CatalogController.php tests/Controller/Api/CatalogFaviconControllerTest.php
git commit -m "feat(catalog): favicon endpoint with monogram fallback (#99)"
```

---

### Task 10: The favicon warmer and its console command

Warming has to work on **every** deployment, not just one. So the loop lives in a service with a **time budget**, and gets two callers: an admin endpoint the UI drives with a progress bar (Task 24/25), and this console command for cron or a CLI-minded operator. Nothing in the app depends on either one having run — a cold cache renders monograms, which is a working picker.

The budget plus a `remaining` count is the same contract `/api/refresh` already uses, so the frontend has a pattern for it and a slow publisher can never hang a request.

**Files:**
- Create: `backend/src/Service/Catalog/CatalogWarmReport.php`
- Create: `backend/src/Service/Catalog/CatalogFaviconWarmer.php`
- Create: `backend/src/Command/WarmCatalogFaviconsCommand.php`
- Test: `backend/tests/Service/Catalog/CatalogFaviconWarmerTest.php`
- Test: `backend/tests/Command/WarmCatalogFaviconsCommandTest.php`

- [ ] **Step 1: Write the failing test**

`backend/tests/Command/WarmCatalogFaviconsCommandTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Entity\CatalogCategory;
use App\Entity\CatalogFeed;
use App\Service\Catalog\CatalogFaviconFetcher;
use App\Service\Catalog\Exception\FaviconUnavailableException;
use App\Service\Catalog\FetchedFavicon;
use App\Service\Fetch\FaviconResolver;
use App\Tests\DbTestCase;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

final class WarmCatalogFaviconsCommandTest extends DbTestCase
{
    private function em(): EntityManagerInterface
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);

        return $em;
    }

    private function persistFeed(string $title, string $url): CatalogFeed
    {
        $category = new CatalogCategory('technology' . $title, 'Technology', 'memory', '#3b82f6');
        $feed = new CatalogFeed($category, $title, $url);
        $feed->setSiteUrl('https://example.com');
        $this->em()->persist($category);
        $this->em()->persist($feed);
        $this->em()->flush();

        return $feed;
    }

    private function tester(CatalogFaviconFetcher $fetcher): CommandTester
    {
        self::getContainer()->set(CatalogFaviconFetcher::class, $fetcher);

        // Stub resolution too, so the warmer's up-front resolveAll() never
        // touches the network: hand every site the same canned icon URL, which
        // the mocked fetcher above then "downloads".
        $resolver = $this->createMock(FaviconResolver::class);
        $resolver->method('resolveAll')->willReturnCallback(
            static fn (array $bases): array => array_map(
                static fn (): string => 'https://example.com/favicon.ico',
                $bases,
            ),
        );
        self::getContainer()->set(FaviconResolver::class, $resolver);

        $application = new Application(self::$kernel);

        return new CommandTester($application->find('app:catalog:warm-favicons'));
    }

    public function testFillsAMissingIconAndIsANoOpOnASecondRun(): void
    {
        $feed = $this->persistFeed('The Verge', 'https://www.theverge.com/rss/index.xml');

        $fetcher = $this->createMock(CatalogFaviconFetcher::class);
        $fetcher->expects(self::once())
            ->method('download')
            ->willReturn(new FetchedFavicon('https://example.com/favicon.ico', 'PNGBYTES', 'image/png'));

        $tester = $this->tester($fetcher);

        $tester->execute([]);
        self::assertSame(0, $tester->getStatusCode());
        self::assertStringContainsString('fetched 1', $tester->getDisplay());

        $this->em()->clear();
        $reloaded = $this->em()->find(CatalogFeed::class, $feed->getId());
        self::assertNotNull($reloaded);
        self::assertSame('PNGBYTES', $reloaded->getFaviconBytes());

        // Second run: the row is fresh, so the fetcher must not be called again —
        // guaranteed by expects(once()) above.
        $tester->execute([]);
        self::assertSame(0, $tester->getStatusCode());
        self::assertStringContainsString('fetched 0', $tester->getDisplay());
    }

    public function testRecordsAFailureAndStillExitsZero(): void
    {
        $this->persistFeed('Dead Feed', 'https://dead.example.com/rss.xml');

        $fetcher = $this->createMock(CatalogFaviconFetcher::class);
        $fetcher->method('download')->willThrowException(new FaviconUnavailableException('gone'));

        $tester = $this->tester($fetcher);
        $tester->execute([]);

        self::assertSame(0, $tester->getStatusCode());
        self::assertStringContainsString('failed 1', $tester->getDisplay());

        $this->em()->clear();
        $rows = $this->em()->getRepository(CatalogFeed::class)->findAll();
        self::assertNotNull($rows[0]->getFaviconFailedAt());
    }

    public function testLimitBoundsTheRun(): void
    {
        $this->persistFeed('One', 'https://one.example.com/rss.xml');
        $this->persistFeed('Two', 'https://two.example.com/rss.xml');

        $fetcher = $this->createMock(CatalogFaviconFetcher::class);
        $fetcher->expects(self::once())
            ->method('download')
            ->willReturn(new FetchedFavicon('https://example.com/favicon.ico', 'PNGBYTES', 'image/png'));

        $tester = $this->tester($fetcher);
        $tester->execute(['--limit' => '1']);

        self::assertSame(0, $tester->getStatusCode());
    }
}
```

- [ ] **Step 2: Run it to confirm it fails**

Run: `php bin/phpunit tests/Command/WarmCatalogFaviconsCommandTest.php`
Expected: FAIL — `Command "app:catalog:warm-favicons" is not defined`.

- [ ] **Step 3: Write the report and the warmer**

`backend/src/Service/Catalog/CatalogWarmReport.php`:

```php
<?php

declare(strict_types=1);

namespace App\Service\Catalog;

/**
 * One budgeted slice of warming. `remaining` is what a caller polls on — the
 * same shape RefreshReport uses, so the frontend drives this with the loop it
 * already has.
 */
final readonly class CatalogWarmReport
{
    public function __construct(
        public int $warmed = 0,
        public int $failed = 0,
        public int $remaining = 0,
    ) {
    }
}
```

`backend/src/Service/Catalog/CatalogFaviconWarmer.php`:

```php
<?php

declare(strict_types=1);

namespace App\Service\Catalog;

use App\Entity\CatalogFeed;
use App\Repository\CatalogFeedRepository;
use App\Service\Catalog\Exception\FaviconUnavailableException;
use App\Service\Fetch\FaviconResolver;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;

/**
 * Fills in missing and stale catalog favicons, a budgeted slice at a time.
 *
 * Each slice resolves its icon URLs in a single concurrent batch through the
 * shared `FaviconResolver::resolveAll()` (see #116) — one burst of guarded
 * homepage fetches rather than 25 sequential ones — then downloads each icon's
 * bytes and commits per row.
 *
 * Budgeted because 111 publisher round trips cannot happen inside one HTTP
 * request: the caller gets `remaining` back and comes again, exactly as
 * /api/refresh works. The console command passes a large budget and simply
 * loops itself.
 *
 * Deliberately NOT tied to any deployment mechanism. The admin UI drives it
 * after an import, so a self-hosted install with no deploy script gets icons
 * the same way this project's own server does.
 *
 * No lock: each row commits on its own and the due-query skips anything already
 * fresh, so two concurrent runs merely duplicate a little work rather than
 * corrupting anything.
 */
final readonly class CatalogFaviconWarmer
{
    private const string STALE_AFTER = 'P90D';
    private const string RETRY_FAILURES_AFTER = 'P14D';
    private const int BATCH_LIMIT = 25;

    public function __construct(
        private CatalogFeedRepository $feeds,
        private FaviconResolver $faviconResolver,
        private CatalogFaviconFetcher $fetcher,
        private EntityManagerInterface $em,
        private ClockInterface $clock,
    ) {
    }

    public function warm(int $budgetSeconds, bool $force = false): CatalogWarmReport
    {
        $now = $this->clock->now();
        $deadline = $now->getTimestamp() + $budgetSeconds;
        [$staleBefore, $retryBefore] = $this->windows($now, $force);

        $due = $this->feeds->findNeedingFavicon($staleBefore, $retryBefore, self::BATCH_LIMIT);

        // Resolve the whole slice's icon URLs up front, in one concurrent burst.
        // `$due` is a list, so its 0..n keys line the resolved URLs up with the
        // feeds below. resolveAll never throws and returns a URL (or null) per key.
        $iconUrls = $this->faviconResolver->resolveAll(
            array_map(static fn (CatalogFeed $feed): string => $feed->getSiteUrl() ?? $feed->getUrl(), $due),
        );

        $warmed = 0;
        $failed = 0;
        foreach ($due as $index => $feed) {
            $this->store($feed, $iconUrls[$index] ?? null, $now) ? ++$warmed : ++$failed;

            // Check AFTER the download, never before: a budget that stops early
            // would report progress it did not make. One overshoot by a single
            // icon's timeout is the price of an honest count. (Resolution already
            // happened above as one bounded burst, so the loop only downloads.)
            if (time() >= $deadline) {
                break;
            }
        }

        return new CatalogWarmReport(
            $warmed,
            $failed,
            $this->feeds->countNeedingFavicon($staleBefore, $retryBefore),
        );
    }

    /**
     * @return array{0: \DateTimeImmutable, 1: \DateTimeImmutable}
     */
    private function windows(\DateTimeImmutable $now, bool $force): array
    {
        if ($force) {
            // Everything is stale and every failure is retryable.
            return [$now->add(new \DateInterval('P1D')), $now->add(new \DateInterval('P1D'))];
        }

        return [
            $now->sub(new \DateInterval(self::STALE_AFTER)),
            $now->sub(new \DateInterval(self::RETRY_FAILURES_AFTER)),
        ];
    }

    /**
     * Re-fetch one row's icon on demand — the admin "refresh favicon" action.
     * Resolves this one site (a one-item batch) and downloads through the same
     * guarded path warming uses, so the two callers cannot drift apart.
     */
    public function refresh(CatalogFeed $feed): void
    {
        $iconUrl = $this->faviconResolver->resolveAll([$feed->getSiteUrl() ?? $feed->getUrl()])[0] ?? null;
        $this->store($feed, $iconUrl, $this->clock->now());
    }

    /**
     * Downloads and stores one already-resolved icon, or records a failure when
     * the URL is unresolved or the download is rejected. Commits per row so an
     * interrupted run resumes rather than restarting. Returns whether an icon
     * was stored.
     */
    private function store(CatalogFeed $feed, ?string $iconUrl, \DateTimeImmutable $now): bool
    {
        if (null !== $iconUrl) {
            try {
                $icon = $this->fetcher->download($iconUrl);
                $feed->storeFavicon($icon->sourceUrl, $icon->bytes, $icon->contentType, $now);
                $this->em->flush();

                return true;
            } catch (FaviconUnavailableException) {
                // Fall through: an undownloadable icon is a recorded failure.
            }
        }

        $feed->recordFaviconFailure($now);
        $this->em->flush();

        return false;
    }
}
```

Add the counterpart count to `backend/src/Repository/CatalogFeedRepository.php`:

```php
    /**
     * How many rows still want an icon — what a polling caller stops on.
     */
    public function countNeedingFavicon(\DateTimeImmutable $staleBefore, \DateTimeImmutable $retryBefore): int
    {
        return (int) $this->createQueryBuilder('f')
            ->select('COUNT(f.id)')
            ->andWhere('f.enabled = true')
            ->andWhere('f.faviconFetchedAt IS NULL OR f.faviconFetchedAt < :stale')
            ->setParameter('stale', $staleBefore)
            ->andWhere('f.faviconFailedAt IS NULL OR f.faviconFailedAt < :retry')
            ->setParameter('retry', $retryBefore)
            ->getQuery()
            ->getSingleScalarResult();
    }
```

- [ ] **Step 4: Write the command**

`backend/src/Command/WarmCatalogFaviconsCommand.php`:

```php
<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\Catalog\CatalogFaviconWarmer;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Warms every missing or stale catalog favicon, looping CatalogFaviconWarmer
 * until nothing is left.
 *
 * A convenience, not the mechanism: the admin UI drives the same warmer over
 * HTTP after an import, so an install that never runs a console command still
 * gets its icons. This exists for cron and for operators who prefer a shell.
 *
 * Self-limiting: minutes on the first run against an empty cache, a no-op after,
 * because cached rows match neither the missing nor the stale predicate.
 */
#[AsCommand(
    name: 'app:catalog:warm-favicons',
    description: 'Fetch and cache missing or stale catalog favicons',
)]
final class WarmCatalogFaviconsCommand extends Command
{
    private const string STALE_AFTER = 'P90D';
    private const string RETRY_FAILURES_AFTER = 'P14D';

    public function __construct(
        private readonly CatalogFaviconWarmer $warmer,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('force', null, InputOption::VALUE_NONE, 'Ignore the freshness and failure windows');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $force = (bool) $input->getOption('force');

        $warmed = 0;
        $failed = 0;
        do {
            // A generous per-slice budget: nothing is waiting on this, unlike the
            // HTTP caller. The loop exists because the warmer works in batches.
            $report = $this->warmer->warm(budgetSeconds: 120, force: $force);
            $warmed += $report->warmed;
            $failed += $report->failed;
            $io->writeln(\sprintf('  %d warmed, %d failed, %d remaining', $warmed, $failed, $report->remaining));

            // A slice that achieved nothing while claiming work remains would
            // spin forever — every candidate is failing, so stop and report it.
            if (0 === $report->warmed && 0 === $report->failed) {
                break;
            }
        } while ($report->remaining > 0);

        $io->success(\sprintf('Catalog favicons: warmed %d, failed %d.', $warmed, $failed));

        // Always 0: an unreachable publisher is not an error condition.
        return Command::SUCCESS;
    }
}
```

- [ ] **Step 4: Run the tests to verify they pass**

Run: `php bin/phpunit tests/Command/WarmCatalogFaviconsCommandTest.php`
Expected: PASS, 3 tests.

- [ ] **Step 5: Run the gate and commit**

```bash
composer check && composer md && php bin/phpunit
git add src/Command/WarmCatalogFaviconsCommand.php tests/Command/WarmCatalogFaviconsCommandTest.php
git commit -m "feat(catalog): app:catalog:warm-favicons command (#99)"
```

---

### Task 11: Optional — warm at deploy on this project's own server

**Nothing depends on this step.** Icons are warmed by the admin UI after an import, which works on any deployment. This adds a convenience for *this* repo's Strato deploy so its server never needs the manual nudge; a fork deploying by Docker, rsync or anything else is unaffected and equally supported.

Skip this task entirely if you are not deploying to Strato.

**Files:**
- Modify: `deploy/strato/activate-release.sh` (after the "Flipping current" block)

- [ ] **Step 1: Add the post-flip step**

Append to `deploy/strato/activate-release.sh`, **after** the `mv -Tf "${ROOT}/current.tmp" "${ROOT}/current"` line and before the final `echo`:

```bash
echo "==> Warming catalog favicons"
# A convenience for this server, not a requirement of the app: the admin UI warms
# icons after an import on any deployment, and a cold cache renders monograms,
# which is a working picker. Forks deploying some other way lose nothing.
#
# Deliberately AFTER the flip and deliberately non-fatal. The release is live at
# this point, and a publisher's icon host being down must not turn a good deploy
# red.
#
# Self-limiting: minutes on the first deploy against an empty cache, a no-op on
# every deploy after, because cached rows are neither missing nor stale.
if ! console app:catalog:warm-favicons; then
    echo "!!! Favicon warming failed; the release is live and serving." >&2
    echo "!!! Icons fall back to monograms until the next successful run." >&2
fi
```

- [ ] **Step 2: Verify the script still parses**

```bash
bash -n deploy/strato/activate-release.sh && shellcheck deploy/strato/activate-release.sh
```

Expected: no output from `bash -n`; `shellcheck` clean, or only pre-existing findings.

- [ ] **Step 3: Verify the command runs in the Docker stack**

```bash
docker compose exec php bin/console app:catalog:warm-favicons --limit=2
```

Expected: exit 0 and a `Catalog favicons: fetched N, failed M, skipped K.` line. Either outcome is acceptable — the container may have no outbound network.

- [ ] **Step 4: Commit**

```bash
git add deploy/strato/activate-release.sh
git commit -m "feat(deploy): warm catalog favicons after the release flip (#99)"
```

---

# Phase 3 — Bulk subscribe

`OpmlImporter::import()` already solves this problem correctly: batch-local dedup maps guarding `uniq_subscription_user_feed` and `uniq_tag_user_name`, in-memory position counters seeded from the committed max, find-or-create against the shared `Feed` table, cap handling counted in memory, and one terminal flush. Phase 3 **extracts** that into a `BulkSubscriber` both callers use, rather than writing a second copy next door.

**Task 12 must be a pure refactor.** The existing `tests/Service/Opml/OpmlImporterTest.php` is the safety net: it must pass unchanged, before and after, with no edits to its assertions.

### Task 12: Extract `BulkSubscriber` from `OpmlImporter`

**Files:**
- Create: `backend/src/Service/Subscription/TagStyle.php`
- Create: `backend/src/Service/Subscription/BulkSubscribeItem.php`
- Create: `backend/src/Service/Subscription/BulkSubscribeResult.php`
- Create: `backend/src/Service/Subscription/BulkSubscriber.php`
- Modify: `backend/src/Service/Opml/OpmlImporter.php`
- Test: `backend/tests/Service/Subscription/BulkSubscriberTest.php`

- [ ] **Step 1: Record the green baseline**

Run: `php bin/phpunit tests/Service/Opml/OpmlImporterTest.php`
Expected: PASS. Note the test count — the same count must pass at the end of this task with the assertions untouched.

- [ ] **Step 2: Write the failing test for the new service**

`backend/tests/Service/Subscription/BulkSubscriberTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Service\Subscription;

use App\Entity\Feed;
use App\Entity\Subscription;
use App\Entity\Tag;
use App\Entity\User;
use App\Service\Subscription\BulkSubscribeItem;
use App\Service\Subscription\BulkSubscriber;
use App\Service\Subscription\TagStyle;
use App\Tests\DbTestCase;
use App\Tests\Support\UserFactory;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class BulkSubscriberTest extends DbTestCase
{
    private function em(): EntityManagerInterface
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);

        return $em;
    }

    private function subscriber(): BulkSubscriber
    {
        $subscriber = self::getContainer()->get(BulkSubscriber::class);
        self::assertInstanceOf(BulkSubscriber::class, $subscriber);

        return $subscriber;
    }

    private function user(string $email): User
    {
        $hasher = self::getContainer()->get(UserPasswordHasherInterface::class);
        self::assertInstanceOf(UserPasswordHasherInterface::class, $hasher);

        return (new UserFactory($this->em(), $hasher))->create($email);
    }

    public function testSubscribesEachItemOnceAndTagsItUnderItsCategory(): void
    {
        $user = $this->user('bulk@example.com');

        $result = $this->subscriber()->subscribeAll($user, [
            new BulkSubscribeItem('https://a.example.com/rss.xml', 'A Feed', 'Technology', new TagStyle('#3b82f6', 'memory')),
            new BulkSubscribeItem('https://b.example.com/rss.xml', 'B Feed', 'Technology', new TagStyle('#3b82f6', 'memory')),
        ]);

        self::assertSame(2, $result->imported);
        self::assertCount(1, $result->tagsCreated);

        $tag = $result->tagsCreated[0];
        self::assertSame('Technology', $tag->getName());
        self::assertSame('#3b82f6', $tag->getColor());
        self::assertSame('memory', $tag->getIcon());
    }

    public function testSeedsTheFeedTitleOnlyWhenTheSharedFeedRowIsNew(): void
    {
        $existing = new Feed('https://shared.example.com/rss.xml');
        $existing->setTitle('Publisher Title');
        $this->em()->persist($existing);
        $this->em()->flush();

        $user = $this->user('titles@example.com');

        $this->subscriber()->subscribeAll($user, [
            new BulkSubscribeItem('https://shared.example.com/rss.xml', 'Catalog Title', null, null),
            new BulkSubscribeItem('https://fresh.example.com/rss.xml', 'Catalog Title', null, null),
        ]);

        $shared = $this->em()->getRepository(Feed::class)->findOneBy(['url' => 'https://shared.example.com/rss.xml']);
        $fresh = $this->em()->getRepository(Feed::class)->findOneBy(['url' => 'https://fresh.example.com/rss.xml']);

        self::assertNotNull($shared);
        self::assertNotNull($fresh);
        self::assertSame('Publisher Title', $shared->getTitle(), 'an existing shared Feed row is never retitled');
        self::assertSame('Catalog Title', $fresh->getTitle(), 'a new Feed row is seeded from the catalog');
    }

    public function testReusesAnExistingTagAndLeavesItsStylingAlone(): void
    {
        $user = $this->user('reuse@example.com');

        $existing = new Tag($user, 'Technology');
        $existing->setColor('#123456');
        $existing->setIcon('star');
        $this->em()->persist($existing);
        $this->em()->flush();

        $result = $this->subscriber()->subscribeAll($user, [
            new BulkSubscribeItem('https://c.example.com/rss.xml', 'C Feed', 'Technology', new TagStyle('#3b82f6', 'memory')),
        ]);

        self::assertSame(1, $result->imported);
        self::assertCount(0, $result->tagsCreated, 'a reused tag was not created');

        $this->em()->clear();
        $reloaded = $this->em()->getRepository(Tag::class)->findOneBy(['name' => 'Technology']);
        self::assertNotNull($reloaded);
        self::assertSame('#123456', $reloaded->getColor());
        self::assertSame('star', $reloaded->getIcon());
    }

    public function testCountsARepeatedUrlAsAlreadySubscribedRatherThanPersistingTwice(): void
    {
        $user = $this->user('dupe@example.com');

        $result = $this->subscriber()->subscribeAll($user, [
            new BulkSubscribeItem('https://d.example.com/rss.xml', 'D Feed', null, null),
            new BulkSubscribeItem('https://d.example.com/rss.xml', 'D Feed', null, null),
        ]);

        self::assertSame(1, $result->imported);
        self::assertSame(1, $result->alreadySubscribed);
        self::assertCount(1, $this->em()->getRepository(Subscription::class)->findAll());
    }

    public function testRejectsAnUnusableUrlWithoutAbortingTheBatch(): void
    {
        $user = $this->user('invalid@example.com');

        $result = $this->subscriber()->subscribeAll($user, [
            new BulkSubscribeItem('not-a-url', 'Bad', null, null),
            new BulkSubscribeItem('https://e.example.com/rss.xml', 'E Feed', null, null),
        ]);

        self::assertSame(1, $result->invalid);
        self::assertSame(1, $result->imported);
    }
}
```

- [ ] **Step 3: Run it to confirm it fails**

Run: `php bin/phpunit tests/Service/Subscription/BulkSubscriberTest.php`
Expected: FAIL — `Class "App\Service\Subscription\BulkSubscriber" not found`.

- [ ] **Step 4: Write the value objects**

`backend/src/Service/Subscription/TagStyle.php`:

```php
<?php

declare(strict_types=1);

namespace App\Service\Subscription;

/**
 * Colour and icon applied to a tag ONLY at the moment it is created. A tag the
 * user already owns keeps whatever styling they gave it — the catalog never
 * overwrites a customised tag.
 */
final readonly class TagStyle
{
    public function __construct(
        public ?string $color = null,
        public ?string $icon = null,
    ) {
    }
}
```

`backend/src/Service/Subscription/BulkSubscribeItem.php`:

```php
<?php

declare(strict_types=1);

namespace App\Service\Subscription;

use App\Enum\SourceFormat;

/**
 * One feed to subscribe to in a batch. $feedTitle seeds a NEW shared Feed row so
 * the sidebar reads properly before the first fetch; it is ignored when the Feed
 * already exists, because another user's row is not ours to retitle.
 */
final readonly class BulkSubscribeItem
{
    public function __construct(
        public string $feedUrl,
        public ?string $feedTitle = null,
        public ?string $tagName = null,
        public ?TagStyle $tagStyle = null,
        public string $sourceFormat = SourceFormat::XML,
    ) {
    }
}
```

`backend/src/Service/Subscription/BulkSubscribeResult.php`:

```php
<?php

declare(strict_types=1);

namespace App\Service\Subscription;

use App\Entity\Tag;

final readonly class BulkSubscribeResult
{
    /**
     * @param list<Tag> $tagsCreated tags this batch brought into being, not ones it reused
     */
    public function __construct(
        public int $imported = 0,
        public int $alreadySubscribed = 0,
        public int $invalid = 0,
        public int $skippedOverLimit = 0,
        public array $tagsCreated = [],
    ) {
    }

    /**
     * @param list<Tag> $tagsCreated
     */
    public function with(
        int $imported = 0,
        int $alreadySubscribed = 0,
        int $invalid = 0,
        int $skippedOverLimit = 0,
        array $tagsCreated = [],
    ): self {
        return new self(
            $this->imported + $imported,
            $this->alreadySubscribed + $alreadySubscribed,
            $this->invalid + $invalid,
            $this->skippedOverLimit + $skippedOverLimit,
            [...$this->tagsCreated, ...$tagsCreated],
        );
    }
}
```

- [ ] **Step 5: Write `BulkSubscriber`, moving the logic out of `OpmlImporter`**

This body is `OpmlImporter::import()`'s loop, `resolveTag()` and `attachTag()` relocated verbatim in behaviour, with the tag styling and feed-title seeding added.

`backend/src/Service/Subscription/BulkSubscriber.php`:

```php
<?php

declare(strict_types=1);

namespace App\Service\Subscription;

use App\Entity\Feed;
use App\Entity\Subscription;
use App\Entity\Tag;
use App\Entity\User;
use App\Repository\FeedRepository;
use App\Repository\SubscriptionRepository;
use App\Repository\SubscriptionTagRepository;
use App\Repository\TagRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;

/**
 * Subscribes a batch of feeds in one unit of work, WITHOUT fetching or
 * discovering anything. Shared by OPML import and the onboarding catalog so the
 * cap, the duplicate checks and the position arithmetic exist exactly once.
 *
 * Nothing flushes until the end, so a repository lookup cannot see rows created
 * earlier in THIS batch. Two batch-local maps stand in for that, guarding the
 * unique constraints the deferred flush would otherwise trip:
 *  - $tagCache (uniq_tag_user_name): several items naming one tag reuse a single
 *    Tag row instead of persisting duplicates.
 *  - $seen (uniq_subscription_user_feed): a URL listed twice subscribes once and
 *    counts as alreadySubscribed.
 */
final readonly class BulkSubscriber
{
    private const int MAX_TAG_NAME = 100;

    /**
     * Feed.url is VARCHAR(750); a longer URL would pass a naive scheme/host check
     * yet blow up the deferred flush with "data too long" on MySQL (strict mode),
     * losing the whole batch. Bound it here so an over-long URL is merely counted
     * invalid and the rest still lands.
     */
    private const int MAX_FEED_URL = 750;

    public function __construct(
        private EntityManagerInterface $em,
        private FeedRepository $feeds,
        private SubscriptionRepository $subscriptions,
        private SubscriptionTagRepository $subscriptionTags,
        private TagRepository $tags,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @param iterable<BulkSubscribeItem> $items
     */
    public function subscribeAll(User $user, iterable $items): BulkSubscribeResult
    {
        $userId = (int) $user->getId();
        $state = new BulkSubscribeState(
            existing: $this->subscriptions->countForUser($userId),
            nextSubscriptionPosition: $this->subscriptions->nextPositionForUser($userId),
            nextTagPosition: $this->tags->nextPositionForUser($userId),
        );
        $result = new BulkSubscribeResult();

        foreach ($items as $item) {
            $result = $this->subscribeOne($user, $item, $state, $result);
        }

        $this->em->flush();

        return $result;
    }

    private function subscribeOne(
        User $user,
        BulkSubscribeItem $item,
        BulkSubscribeState $state,
        BulkSubscribeResult $result,
    ): BulkSubscribeResult {
        $url = $item->feedUrl;

        if (!$this->isSubscribableUrl($url)) {
            return $result->with(invalid: 1);
        }
        if (isset($state->seen[$url])) {
            return $result->with(alreadySubscribed: 1);
        }

        // Look up but do NOT create yet: an over-limit batch must not leave orphan
        // Feed rows behind for feeds it never subscribes to.
        $feed = $this->feeds->findOneBy(['url' => $url]);
        if (null !== $feed && $this->subscriptions->existsForUserAndFeed((int) $user->getId(), (int) $feed->getId())) {
            return $result->with(alreadySubscribed: 1);
        }
        if ($state->existing >= SubscriptionService::MAX_SUBSCRIPTIONS_PER_USER) {
            return $result->with(skippedOverLimit: 1);
        }

        if (null === $feed) {
            $feed = new Feed($url);
            $feed->setSourceFormat($item->sourceFormat);
            // Seeded from the catalog so the sidebar reads properly before the
            // first fetch. Only on creation: a shared row another user already
            // has is not ours to retitle.
            $feed->setTitle($item->feedTitle);
            $feed->setNextFetchAt($this->clock->now()); // due now → next refresh populates it
            $this->em->persist($feed);
        }

        $subscription = new Subscription($user, $feed, $this->clock->now());
        $subscription->setPosition($state->nextSubscriptionPosition++);
        $this->em->persist($subscription);

        $created = $this->attachTag($user, $subscription, $item, $state);

        $state->seen[$url] = true;
        ++$state->existing;

        return $result->with(imported: 1, tagsCreated: $created);
    }

    /**
     * @return list<Tag> the tag if this call brought it into being, else empty
     */
    private function attachTag(
        User $user,
        Subscription $subscription,
        BulkSubscribeItem $item,
        BulkSubscribeState $state,
    ): array {
        if (null === $item->tagName) {
            return [];
        }

        $name = mb_substr($item->tagName, 0, self::MAX_TAG_NAME);
        $key = mb_strtolower($name);

        $created = [];
        $tag = $state->tagCache[$key] ?? $this->tags->findOneByNameForUser((int) $user->getId(), $name);
        if (null === $tag) {
            $tag = new Tag($user, $name);
            $tag->setColor($item->tagStyle?->color);
            $tag->setIcon($item->tagStyle?->icon);
            $tag->setPosition($state->nextTagPosition++);
            $this->em->persist($tag);
            $created[] = $tag;
        }
        $state->tagCache[$key] = $tag;

        // Nothing is flushed yet, so DB MAX(position) cannot see joins made in
        // this batch: a tag created here starts at 0, an existing one appends
        // past its committed feeds.
        $oid = spl_object_id($tag);
        $state->nextFeedPositionInTag[$oid] ??= null === $tag->getId()
            ? 0
            : $this->subscriptionTags->nextPositionForTag($tag);
        $subscription->addTag($tag, $state->nextFeedPositionInTag[$oid]++);

        return $created;
    }

    private function isSubscribableUrl(string $url): bool
    {
        if (mb_strlen($url) > self::MAX_FEED_URL) {
            return false;
        }
        $scheme = parse_url($url, \PHP_URL_SCHEME);
        $host = parse_url($url, \PHP_URL_HOST);

        return \in_array($scheme, ['http', 'https'], true) && \is_string($host) && '' !== $host;
    }
}
```

`backend/src/Service/Subscription/BulkSubscribeState.php` — the mutable per-batch bookkeeping, kept in its own object so `subscribeAll` does not thread six by-reference parameters through every helper:

```php
<?php

declare(strict_types=1);

namespace App\Service\Subscription;

use App\Entity\Tag;

/**
 * Mutable bookkeeping for ONE batch. Not a domain concept — it exists so the
 * batch-local identity maps and position counters travel together instead of as
 * six by-reference parameters.
 *
 * @internal to BulkSubscriber
 */
final class BulkSubscribeState
{
    /** @var array<string, Tag> keyed by lowercased tag name */
    public array $tagCache = [];

    /** @var array<string, true> feed URLs subscribed during this batch */
    public array $seen = [];

    /** @var array<int, int> keyed by spl_object_id(tag) */
    public array $nextFeedPositionInTag = [];

    public function __construct(
        public int $existing,
        public int $nextSubscriptionPosition,
        public int $nextTagPosition,
    ) {
    }
}
```

- [ ] **Step 6: Rewrite `OpmlImporter` to delegate**

Replace the body of `import()` and delete `resolveTag()`, `attachTag()` and `isImportableUrl()` — they now live in `BulkSubscriber`. Also delete `parseBody()`: Task 2 extracted it into `OpmlBodyReader`, and this is where the duplicate goes away. `collectFeeds()` stays — walking outlines into feed/tag pairs is `OpmlImporter`'s own job.

The new `backend/src/Service/Opml/OpmlImporter.php` constructor and `import()`:

```php
    public function __construct(
        private OpmlBodyReader $bodyReader,
        private BulkSubscriber $subscriber,
    ) {
    }

    public function import(User $user, string $opml): OpmlImportResult
    {
        $body = $this->bodyReader->read($opml);

        $items = [];
        // Depth-first: each feed outline inherits the nearest ancestor group's
        // title as its tag. `null` tag = body root (untagged). OPML carries no
        // styling, so imported tags get the app's default colour and icon.
        foreach ($this->collectFeeds($body, null) as [$xmlUrl, $tagName]) {
            $items[] = new BulkSubscribeItem(feedUrl: $xmlUrl, tagName: $tagName);
        }

        $result = $this->subscriber->subscribeAll($user, $items);

        return new OpmlImportResult(
            imported: $result->imported,
            alreadySubscribed: $result->alreadySubscribed,
            invalid: $result->invalid,
            skippedOverLimit: $result->skippedOverLimit,
        );
    }
```

Update the imports: drop `Feed`, `Subscription`, `Tag`, `FeedRepository`, `SubscriptionRepository`, `SubscriptionTagRepository`, `TagRepository`, `EntityManagerInterface`, `ClockInterface`, `SubscriptionService`, `InvalidOpmlException`; add `App\Service\Subscription\BulkSubscribeItem` and `App\Service\Subscription\BulkSubscriber`. Keep the `MAX_TAG_NAME` truncation comment out of this class — `BulkSubscriber` owns it now.

`OpmlBodyReader` throws the same `InvalidOpmlException` on the same conditions, so the existing OPML tests covering malformed input must pass untouched. If one needs changing, the extraction changed behaviour.

- [ ] **Step 7: Verify the OPML tests still pass, unchanged**

Run: `php bin/phpunit tests/Service/Opml/OpmlImporterTest.php tests/Controller/Api/OpmlControllerTest.php`
Expected: PASS, the same test count as Step 1, with **no edits to any assertion**. If a test needs changing, the extraction changed behaviour — fix `BulkSubscriber`, not the test.

- [ ] **Step 8: Run the new tests and the full suite**

```bash
php bin/phpunit tests/Service/Subscription/BulkSubscriberTest.php
php bin/phpunit
```

Expected: 5 new tests pass; the whole suite is green.

- [ ] **Step 9: Run the gate and commit**

```bash
composer check && composer md
git add src/Service/Subscription src/Service/Opml/OpmlImporter.php tests/Service/Subscription/BulkSubscriberTest.php
git commit -m "refactor(subscribe): extract BulkSubscriber shared by OPML and catalog (#99)"
```

---

### Task 13: `CatalogSubscriber`

**Files:**
- Create: `backend/src/Service/Catalog/CatalogSubscriber.php`
- Test: `backend/tests/Service/Catalog/CatalogSubscriberTest.php`

- [ ] **Step 1: Write the failing test**

`backend/tests/Service/Catalog/CatalogSubscriberTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Service\Catalog;

use App\Entity\CatalogCategory;
use App\Entity\CatalogFeed;
use App\Entity\Tag;
use App\Entity\User;
use App\Service\Catalog\CatalogSubscriber;
use App\Tests\DbTestCase;
use App\Tests\Support\UserFactory;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class CatalogSubscriberTest extends DbTestCase
{
    private function em(): EntityManagerInterface
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);

        return $em;
    }

    private function user(string $email): User
    {
        $hasher = self::getContainer()->get(UserPasswordHasherInterface::class);
        self::assertInstanceOf(UserPasswordHasherInterface::class, $hasher);

        return (new UserFactory($this->em(), $hasher))->create($email);
    }

    /** @return array{0: CatalogFeed, 1: CatalogFeed, 2: CatalogFeed} */
    private function catalog(): array
    {
        $technology = new CatalogCategory('technology', 'Technology', 'memory', '#3b82f6');
        $technology->setPosition(0);
        $science = new CatalogCategory('science', 'Science', 'science', '#14b8a6');
        $science->setPosition(1);

        $verge = new CatalogFeed($technology, 'The Verge', 'https://www.theverge.com/rss/index.xml');
        $ars = new CatalogFeed($technology, 'Ars Technica', 'https://feeds.arstechnica.com/arstechnica/index');
        $quanta = new CatalogFeed($science, 'Quanta Magazine', 'https://api.quantamagazine.org/feed/');

        foreach ([$technology, $science, $verge, $ars, $quanta] as $row) {
            $this->em()->persist($row);
        }
        $this->em()->flush();

        return [$verge, $ars, $quanta];
    }

    private function subscriber(): CatalogSubscriber
    {
        $subscriber = self::getContainer()->get(CatalogSubscriber::class);
        self::assertInstanceOf(CatalogSubscriber::class, $subscriber);

        return $subscriber;
    }

    public function testCreatesOneTagPerCategoryTheUserPickedFrom(): void
    {
        [$verge, $ars, $quanta] = $this->catalog();
        $user = $this->user('picker@example.com');

        $result = $this->subscriber()->subscribe($user, [
            (int) $verge->getId(),
            (int) $ars->getId(),
            (int) $quanta->getId(),
        ]);

        self::assertSame(3, $result->imported);
        self::assertSame(['Technology', 'Science'], array_map(
            static fn (Tag $tag): string => $tag->getName(),
            $result->tagsCreated,
        ));
        self::assertSame('#3b82f6', $result->tagsCreated[0]->getColor());
        self::assertSame('memory', $result->tagsCreated[0]->getIcon());
    }

    public function testACategoryNothingWasPickedFromCreatesNoTag(): void
    {
        [$verge, , ] = $this->catalog();
        $user = $this->user('partial@example.com');

        $result = $this->subscriber()->subscribe($user, [(int) $verge->getId()]);

        self::assertCount(1, $result->tagsCreated);
        self::assertSame('Technology', $result->tagsCreated[0]->getName());
    }

    public function testUnknownAndDisabledIdsAreIgnoredRatherThanFatal(): void
    {
        [$verge, , ] = $this->catalog();
        $disabled = new CatalogFeed($verge->getCategory(), 'Retired', 'https://retired.example.com/rss.xml');
        $disabled->setEnabled(false);
        $this->em()->persist($disabled);
        $this->em()->flush();

        $user = $this->user('stale@example.com');

        $result = $this->subscriber()->subscribe($user, [
            (int) $verge->getId(),
            (int) $disabled->getId(),
            999999,
        ]);

        self::assertSame(1, $result->imported);
    }

    public function testResubmittingTheSameSelectionIsANoOp(): void
    {
        [$verge, , ] = $this->catalog();
        $user = $this->user('repeat@example.com');

        $this->subscriber()->subscribe($user, [(int) $verge->getId()]);
        $second = $this->subscriber()->subscribe($user, [(int) $verge->getId()]);

        self::assertSame(0, $second->imported);
        self::assertSame(1, $second->alreadySubscribed);
        self::assertCount(0, $second->tagsCreated);
    }
}
```

- [ ] **Step 2: Run it to confirm it fails**

Run: `php bin/phpunit tests/Service/Catalog/CatalogSubscriberTest.php`
Expected: FAIL — `Class "App\Service\Catalog\CatalogSubscriber" not found`.

- [ ] **Step 3: Implement it**

`backend/src/Service/Catalog/CatalogSubscriber.php`:

```php
<?php

declare(strict_types=1);

namespace App\Service\Catalog;

use App\Entity\CatalogFeed;
use App\Entity\User;
use App\Repository\CatalogFeedRepository;
use App\Service\Subscription\BulkSubscribeItem;
use App\Service\Subscription\BulkSubscribeResult;
use App\Service\Subscription\BulkSubscriber;
use App\Service\Subscription\TagStyle;

/**
 * Turns a picker selection into subscriptions. NO DISCOVERY: catalog rows carry
 * a verified direct feed URL and its sourceFormat, so 110 selections must never
 * become 110 outbound discovery fetches.
 *
 * Unknown, disabled and already-subscribed ids are ignored rather than rejected,
 * so a picker rendered against a since-edited catalog still submits cleanly.
 */
final readonly class CatalogSubscriber
{
    public function __construct(
        private CatalogFeedRepository $feeds,
        private BulkSubscriber $subscriber,
    ) {
    }

    /**
     * @param list<int> $catalogFeedIds
     */
    public function subscribe(User $user, array $catalogFeedIds): BulkSubscribeResult
    {
        return $this->subscriber->subscribeAll(
            $user,
            array_map(
                static fn (CatalogFeed $feed): BulkSubscribeItem => new BulkSubscribeItem(
                    feedUrl: $feed->getUrl(),
                    feedTitle: $feed->getTitle(),
                    tagName: $feed->getCategory()->getName(),
                    tagStyle: new TagStyle(
                        $feed->getCategory()->getColor(),
                        $feed->getCategory()->getIcon(),
                    ),
                    sourceFormat: $feed->getSourceFormat(),
                ),
                // Ordered by category position then feed position, so tags are
                // created in catalog order and feeds sit in catalog order inside
                // each tag.
                $this->feeds->findEnabledByIds($catalogFeedIds),
            ),
        );
    }
}
```

- [ ] **Step 4: Run the tests to verify they pass**

Run: `php bin/phpunit tests/Service/Catalog/CatalogSubscriberTest.php`
Expected: PASS, 4 tests.

- [ ] **Step 5: Run the gate and commit**

```bash
composer check && composer md
git add src/Service/Catalog/CatalogSubscriber.php tests/Service/Catalog/CatalogSubscriberTest.php
git commit -m "feat(catalog): CatalogSubscriber mapping selections to bulk items (#99)"
```

---

### Task 14: `POST /api/onboarding/subscribe`

**Files:**
- Create: `backend/src/Dto/Onboarding/OnboardingSubscribeRequest.php`
- Create: `backend/src/Controller/Api/OnboardingController.php`
- Test: `backend/tests/Controller/Api/OnboardingControllerTest.php`

- [ ] **Step 1: Write the failing test**

`backend/tests/Controller/Api/OnboardingControllerTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Controller\Api;

use App\Entity\CatalogCategory;
use App\Entity\CatalogFeed;
use App\Service\Discovery\FeedDiscoveryInterface;
use App\Tests\Support\UserFactory;
use Doctrine\ORM\EntityManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class OnboardingControllerTest extends WebTestCase
{
    /** @return array<string, string> */
    private function authHeader(string $email): array
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $hasher = self::getContainer()->get(UserPasswordHasherInterface::class);
        self::assertInstanceOf(UserPasswordHasherInterface::class, $hasher);

        $user = (new UserFactory($em, $hasher))->create($email);

        $tokens = self::getContainer()->get(JWTTokenManagerInterface::class);
        self::assertInstanceOf(JWTTokenManagerInterface::class, $tokens);

        return [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $tokens->create($user),
            'CONTENT_TYPE' => 'application/json',
        ];
    }

    /** @return list<CatalogFeed> */
    private function catalog(): array
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);

        $category = new CatalogCategory('technology', 'Technology', 'memory', '#3b82f6');
        $verge = new CatalogFeed($category, 'The Verge', 'https://www.theverge.com/rss/index.xml');
        $ars = new CatalogFeed($category, 'Ars Technica', 'https://feeds.arstechnica.com/arstechnica/index');

        foreach ([$category, $verge, $ars] as $row) {
            $em->persist($row);
        }
        $em->flush();

        return [$verge, $ars];
    }

    public function testAnonymousIsRejected(): void
    {
        $client = self::createClient();
        $client->request('POST', '/api/onboarding/subscribe', content: '{"catalogFeedIds":[1]}');
        self::assertResponseStatusCodeSame(401);
    }

    public function testSubscribesAndReportsTheTagsItCreated(): void
    {
        $client = self::createClient();
        $headers = $this->authHeader('onboard@example.com');
        [$verge, $ars] = $this->catalog();

        $client->request(
            'POST',
            '/api/onboarding/subscribe',
            server: $headers,
            content: json_encode(
                ['catalogFeedIds' => [(int) $verge->getId(), (int) $ars->getId()]],
                \JSON_THROW_ON_ERROR,
            ),
        );

        self::assertResponseIsSuccessful();
        $body = json_decode((string) $client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);

        self::assertSame(2, $body['subscribed']);
        self::assertSame(0, $body['skipped']);
        self::assertSame(['Technology'], array_column($body['tagsCreated'], 'name'));
        self::assertSame('#3b82f6', $body['tagsCreated'][0]['color']);
    }

    /**
     * Through the real HTTP kernel, not a direct service call: a direct
     * invocation here could assert something the wired-up app never does.
     */
    public function testSubscribingIssuesNoDiscoveryRequests(): void
    {
        $client = self::createClient();
        $headers = $this->authHeader('nodiscovery@example.com');
        [$verge] = $this->catalog();

        $discovery = $this->createMock(FeedDiscoveryInterface::class);
        $discovery->expects(self::never())->method('discover');
        self::getContainer()->set(FeedDiscoveryInterface::class, $discovery);

        $client->request(
            'POST',
            '/api/onboarding/subscribe',
            server: $headers,
            content: json_encode(['catalogFeedIds' => [(int) $verge->getId()]], \JSON_THROW_ON_ERROR),
        );

        self::assertResponseIsSuccessful();
    }

    public function testAnEmptySelectionIsRejected(): void
    {
        $client = self::createClient();
        $headers = $this->authHeader('empty@example.com');

        $client->request(
            'POST',
            '/api/onboarding/subscribe',
            server: $headers,
            content: json_encode(['catalogFeedIds' => []], \JSON_THROW_ON_ERROR),
        );

        self::assertResponseStatusCodeSame(422);
    }

    public function testResubmittingTheSameSelectionIsANoOpNotAnError(): void
    {
        $client = self::createClient();
        $headers = $this->authHeader('again@example.com');
        [$verge] = $this->catalog();
        $payload = json_encode(['catalogFeedIds' => [(int) $verge->getId()]], \JSON_THROW_ON_ERROR);

        $client->request('POST', '/api/onboarding/subscribe', server: $headers, content: $payload);
        self::assertResponseIsSuccessful();

        $client->request('POST', '/api/onboarding/subscribe', server: $headers, content: $payload);
        self::assertResponseIsSuccessful();

        $body = json_decode((string) $client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);
        self::assertSame(0, $body['subscribed']);
        self::assertSame(1, $body['skipped']);
        self::assertSame([], $body['tagsCreated']);
    }
}
```

- [ ] **Step 2: Run it to confirm it fails**

Run: `php bin/phpunit tests/Controller/Api/OnboardingControllerTest.php`
Expected: FAIL — 404 on `/api/onboarding/subscribe`.

- [ ] **Step 3: Write the request DTO**

`backend/src/Dto/Onboarding/OnboardingSubscribeRequest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Dto\Onboarding;

use Symfony\Component\Validator\Constraints as Assert;

final readonly class OnboardingSubscribeRequest
{
    /**
     * @param list<int> $catalogFeedIds
     */
    public function __construct(
        #[Assert\Count(min: 1, max: 500)]
        #[Assert\All([new Assert\Type('integer'), new Assert\Positive()])]
        public array $catalogFeedIds = [],
    ) {
    }
}
```

- [ ] **Step 4: Write the controller**

`backend/src/Controller/Api/OnboardingController.php`:

```php
<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Dto\Onboarding\OnboardingSubscribeRequest;
use App\Entity\Tag;
use App\Entity\User;
use App\Http\TagJson;
use App\Service\Catalog\CatalogSubscriber;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

#[Route('/api/onboarding')]
final class OnboardingController
{
    public function __construct(
        private readonly CatalogSubscriber $subscriber,
    ) {
    }

    /**
     * Subscribes a picker selection. Fetches nothing: the new feeds are due
     * immediately and the frontend triggers the sweep after it has navigated
     * into the reader, so this request returns promptly however many feeds were
     * selected.
     */
    #[Route('/subscribe', name: 'api_onboarding_subscribe', methods: ['POST'])]
    public function subscribe(
        #[CurrentUser] User $user,
        #[MapRequestPayload] OnboardingSubscribeRequest $request,
    ): JsonResponse {
        $result = $this->subscriber->subscribe($user, $request->catalogFeedIds);

        return new JsonResponse([
            'subscribed' => $result->imported,
            'skipped' => $result->alreadySubscribed + $result->invalid + $result->skippedOverLimit,
            'skippedOverLimit' => $result->skippedOverLimit,
            'tagsCreated' => array_map(static fn (Tag $tag) => TagJson::one($tag), $result->tagsCreated),
        ]);
    }
}
```

- [ ] **Step 5: Run the tests to verify they pass**

Run: `php bin/phpunit tests/Controller/Api/OnboardingControllerTest.php`
Expected: PASS, 5 tests.

- [ ] **Step 6: Add the cap-boundary test**

Append to `backend/tests/Controller/Api/OnboardingControllerTest.php`:

```php
    public function testASelectionCrossingTheCapStopsCleanlyAndReportsWhatItCreated(): void
    {
        $client = self::createClient();
        $headers = $this->authHeader('cap@example.com');

        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $clock = self::getContainer()->get(\Psr\Clock\ClockInterface::class);
        self::assertInstanceOf(\Psr\Clock\ClockInterface::class, $clock);

        $user = $em->getRepository(\App\Entity\User::class)->findOneBy(['email' => 'cap@example.com']);
        self::assertNotNull($user);

        // One short of the cap, so a two-feed selection lands one and skips one.
        for ($i = 0; $i < \App\Service\Subscription\SubscriptionService::MAX_SUBSCRIPTIONS_PER_USER - 1; ++$i) {
            $feed = new \App\Entity\Feed(\sprintf('https://filler%d.example.com/rss.xml', $i));
            $em->persist($feed);
            $em->persist(new \App\Entity\Subscription($user, $feed, $clock->now()));
        }
        $em->flush();

        [$verge, $ars] = $this->catalog();

        $client->request(
            'POST',
            '/api/onboarding/subscribe',
            server: $headers,
            content: json_encode(
                ['catalogFeedIds' => [(int) $verge->getId(), (int) $ars->getId()]],
                \JSON_THROW_ON_ERROR,
            ),
        );

        self::assertResponseIsSuccessful();
        $body = json_decode((string) $client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);

        self::assertSame(1, $body['subscribed']);
        self::assertSame(1, $body['skippedOverLimit']);
    }
```

- [ ] **Step 7: Run the tests and the gate, then commit**

```bash
php bin/phpunit tests/Controller/Api/OnboardingControllerTest.php
composer check && composer md && php bin/phpunit
git add src/Dto/Onboarding src/Controller/Api/OnboardingController.php tests/Controller/Api/OnboardingControllerTest.php
git commit -m "feat(onboarding): POST /api/onboarding/subscribe (#99)"
```

---

# Phase 4 — The picker

All frontend work happens in `frontend/`. The gate is `npm run check` (ESLint + Prettier + Stylelint + Jest). Component styles are inline in the `.ts` and token-only — **hex colours are forbidden outside `src/app/theme/`**, enforced by Stylelint `color-no-hex`. Category colours are the exception the design forces: they arrive as `#rrggbb` strings from the API and are bound with `[style.background]`, never written into a stylesheet.

### Task 15: Catalog models, API client and store

The store is not indirection for its own sake. The reader shell has to know
whether the catalog has anything in it **before** it redirects — an empty catalog
must not send a new user to a blank picker — and `/discover` needs the same data
immediately afterwards. One root-provided store means one fetch serves both.

**Files:**
- Create: `frontend/src/app/discover/catalog.models.ts`
- Create: `frontend/src/app/discover/catalog-api.ts`
- Create: `frontend/src/app/discover/catalog.store.ts`
- Test: `frontend/src/app/discover/catalog-api.spec.ts`
- Test: `frontend/src/app/discover/catalog.store.spec.ts`

- [ ] **Step 1: Write the failing test**

`frontend/src/app/discover/catalog-api.spec.ts`:

```ts
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { TestBed } from '@angular/core/testing';
import { provideHttpClient } from '@angular/common/http';
import { API_BASE_URL } from '../core/api';
import { CatalogApi } from './catalog-api';

describe('CatalogApi', () => {
  let api: CatalogApi;
  let http: HttpTestingController;

  beforeEach(() => {
    TestBed.configureTestingModule({
      providers: [
        provideHttpClient(),
        provideHttpClientTesting(),
        { provide: API_BASE_URL, useValue: 'https://api.test' },
      ],
    });
    api = TestBed.inject(CatalogApi);
    http = TestBed.inject(HttpTestingController);
  });

  afterEach(() => http.verify());

  it('loads the catalog', () => {
    let categories: unknown;
    api.load().subscribe((r) => (categories = r.categories));

    const req = http.expectOne('https://api.test/api/catalog');
    expect(req.request.method).toBe('GET');
    req.flush({ categories: [{ id: 1, key: 'technology', name: 'Technology', feeds: [] }] });

    expect(categories).toHaveLength(1);
  });

  it('posts the selected ids to the onboarding endpoint', () => {
    api.subscribe([3, 1, 2]).subscribe();

    const req = http.expectOne('https://api.test/api/onboarding/subscribe');
    expect(req.request.method).toBe('POST');
    expect(req.request.body).toEqual({ catalogFeedIds: [3, 1, 2] });
    req.flush({ subscribed: 3, skipped: 0, skippedOverLimit: 0, tagsCreated: [] });
  });
});
```

- [ ] **Step 2: Run it to confirm it fails**

Run: `npx jest src/app/discover/catalog-api.spec.ts`
Expected: FAIL — cannot resolve `./catalog-api`.

- [ ] **Step 3: Write the models**

`frontend/src/app/discover/catalog.models.ts`:

```ts
// src/app/discover/catalog.models.ts
import { TagDto } from '../reader/models';

export interface CatalogFeedDto {
  id: number;
  title: string;
  description: string | null;
  siteUrl: string | null;
  /** Path on our own origin — never a publisher URL, so the picker makes no
   *  outbound requests. Serves cached bytes or a monogram placeholder. */
  faviconUrl: string;
  subscribed: boolean;
}

export interface CatalogCategoryDto {
  id: number;
  key: string;
  name: string;
  icon: string;
  color: string;
  feeds: CatalogFeedDto[];
}

export interface OnboardingSubscribeResult {
  subscribed: number;
  skipped: number;
  skippedOverLimit: number;
  tagsCreated: TagDto[];
}
```

- [ ] **Step 4: Write the client**

`frontend/src/app/discover/catalog-api.ts`:

```ts
// src/app/discover/catalog-api.ts
import { HttpClient } from '@angular/common/http';
import { Injectable, inject } from '@angular/core';
import { Observable } from 'rxjs';
import { API_BASE_URL } from '../core/api';
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

- [ ] **Step 5: Run the test to verify it passes**

Run: `npx jest src/app/discover/catalog-api.spec.ts`
Expected: PASS, 2 tests.

- [ ] **Step 6: Write the failing store test**

`frontend/src/app/discover/catalog.store.spec.ts`:

```ts
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { TestBed } from '@angular/core/testing';
import { provideHttpClient } from '@angular/common/http';
import { API_BASE_URL } from '../core/api';
import { CatalogStore } from './catalog.store';

const WITH_FEEDS = {
  categories: [
    {
      id: 1,
      key: 'technology',
      name: 'Technology',
      icon: 'memory',
      color: '#3b82f6',
      feeds: [
        { id: 10, title: 'The Verge', description: null, siteUrl: null, faviconUrl: '/f/10', subscribed: false },
      ],
    },
  ],
};

describe('CatalogStore', () => {
  let store: CatalogStore;
  let http: HttpTestingController;

  beforeEach(() => {
    TestBed.configureTestingModule({
      providers: [
        provideHttpClient(),
        provideHttpClientTesting(),
        { provide: API_BASE_URL, useValue: 'https://api.test' },
      ],
    });
    store = TestBed.inject(CatalogStore);
    http = TestBed.inject(HttpTestingController);
  });

  afterEach(() => http.verify());

  it('starts unresolved, which is not the same as empty', () => {
    expect(store.resolved()).toBe(false);
    expect(store.hasEntries()).toBe(false);
  });

  it('resolves with entries', () => {
    store.load();
    http.expectOne('https://api.test/api/catalog').flush(WITH_FEEDS);

    expect(store.resolved()).toBe(true);
    expect(store.hasEntries()).toBe(true);
  });

  it('treats a catalog of categories with no feeds as empty', () => {
    store.load();
    http.expectOne('https://api.test/api/catalog').flush({
      categories: [{ id: 1, key: 'empty', name: 'Empty', icon: 'memory', color: '#3b82f6', feeds: [] }],
    });

    expect(store.resolved()).toBe(true);
    expect(store.hasEntries()).toBe(false);
  });

  it('fetches once however many callers ask', () => {
    store.load();
    store.load();

    http.expectOne('https://api.test/api/catalog').flush(WITH_FEEDS);
    // A second expectOne would throw if the store had issued another request.
  });

  it('resolves as empty when the request fails, so nothing redirects into a broken picker', () => {
    store.load();
    http.expectOne('https://api.test/api/catalog').flush('nope', { status: 500, statusText: 'Server Error' });

    expect(store.resolved()).toBe(true);
    expect(store.hasEntries()).toBe(false);
    expect(store.error()).not.toBeNull();
  });
});
```

- [ ] **Step 7: Run it to confirm it fails**

Run: `npx jest src/app/discover/catalog.store.spec.ts`
Expected: FAIL — cannot resolve `./catalog.store`.

- [ ] **Step 8: Write the store**

`frontend/src/app/discover/catalog.store.ts`:

```ts
// src/app/discover/catalog.store.ts
import { HttpErrorResponse } from '@angular/common/http';
import { Injectable, computed, inject, signal } from '@angular/core';
import { Problem, parseProblem } from '../core/problem';
import { CatalogApi } from './catalog-api';
import { CatalogCategoryDto } from './catalog.models';

/**
 * The catalog, fetched once per session and shared.
 *
 * Two consumers with different needs: the reader shell asks only "is there
 * anything to show?" before it decides whether to send a new user to the picker,
 * and the picker itself renders the whole thing. One store means the shell's
 * check does not cost an extra round trip when the redirect does happen.
 */
@Injectable({ providedIn: 'root' })
export class CatalogStore {
  private readonly api = inject(CatalogApi);

  readonly categories = signal<CatalogCategoryDto[]>([]);
  readonly loading = signal(false);
  readonly error = signal<Problem | null>(null);

  /** True once a load has finished, successfully or not. Distinct from "empty":
   *  before the answer arrives, neither is known. */
  readonly resolved = signal(false);

  /** A catalog nobody has imported yet — or one whose categories are all empty —
   *  has nothing to offer, and must never be redirected into. */
  readonly hasEntries = computed(() =>
    this.categories().some((category) => category.feeds.length > 0),
  );

  load(): void {
    if (this.loading() || this.resolved()) return;
    this.loading.set(true);
    this.error.set(null);
    this.api.load().subscribe({
      next: (r) => {
        this.categories.set(r.categories);
        this.loading.set(false);
        this.resolved.set(true);
      },
      error: (e: HttpErrorResponse) => {
        // Resolve as empty on failure: a redirect into a picker that cannot load
        // is worse than leaving the user in the reader with a link.
        this.categories.set([]);
        this.error.set(parseProblem(e));
        this.loading.set(false);
        this.resolved.set(true);
      },
    });
  }

  /** Forget the cached catalog so the next load() refetches — used after a
   *  successful subscribe, since every picked feed is now `subscribed`. */
  invalidate(): void {
    this.resolved.set(false);
    this.categories.set([]);
  }
}
```

- [ ] **Step 9: Run the tests to verify they pass**

Run: `npx jest src/app/discover`
Expected: PASS — 2 API tests and 5 store tests.

- [ ] **Step 10: Commit**

```bash
npm run check
git add src/app/discover/catalog.models.ts src/app/discover/catalog-api.ts src/app/discover/catalog.store.ts src/app/discover/catalog-api.spec.ts src/app/discover/catalog.store.spec.ts
git commit -m "feat(discover): catalog models, API client and shared store (#99)"
```

---

### Task 16: The selection store

**Files:**
- Create: `frontend/src/app/discover/catalog-selection.store.ts`
- Test: `frontend/src/app/discover/catalog-selection.store.spec.ts`

- [ ] **Step 1: Write the failing test**

`frontend/src/app/discover/catalog-selection.store.spec.ts`:

```ts
import { CatalogCategoryDto } from './catalog.models';
import { CatalogSelection } from './catalog-selection.store';

function category(overrides: Partial<CatalogCategoryDto> = {}): CatalogCategoryDto {
  return {
    id: 1,
    key: 'technology',
    name: 'Technology',
    icon: 'memory',
    color: '#3b82f6',
    feeds: [
      { id: 10, title: 'A', description: null, siteUrl: null, faviconUrl: '/a', subscribed: false },
      { id: 11, title: 'B', description: null, siteUrl: null, faviconUrl: '/b', subscribed: false },
    ],
    ...overrides,
  };
}

describe('CatalogSelection', () => {
  it('starts empty and toggles one feed at a time', () => {
    const selection = new CatalogSelection();
    selection.setCategories([category()]);

    expect(selection.selectedCount()).toBe(0);

    selection.toggle(10);
    expect(selection.isSelected(10)).toBe(true);
    expect(selection.selectedCount()).toBe(1);

    selection.toggle(10);
    expect(selection.isSelected(10)).toBe(false);
  });

  it('pre-selects already-subscribed feeds and refuses to toggle them', () => {
    const subscribed = category({
      feeds: [
        { id: 10, title: 'A', description: null, siteUrl: null, faviconUrl: '/a', subscribed: true },
      ],
    });
    const selection = new CatalogSelection();
    selection.setCategories([subscribed]);

    expect(selection.isSelected(10)).toBe(true);
    expect(selection.isLocked(10)).toBe(true);

    selection.toggle(10);
    expect(selection.isSelected(10)).toBe(true);

    // A locked feed is already subscribed, so it is not part of what we submit.
    expect(selection.selectedIds()).toEqual([]);
  });

  it('selects and clears a whole category without touching locked feeds', () => {
    const mixed = category({
      feeds: [
        { id: 10, title: 'A', description: null, siteUrl: null, faviconUrl: '/a', subscribed: false },
        { id: 11, title: 'B', description: null, siteUrl: null, faviconUrl: '/b', subscribed: true },
      ],
    });
    const selection = new CatalogSelection();
    selection.setCategories([mixed]);

    selection.selectAll(1);
    expect(selection.selectedIds()).toEqual([10]);
    expect(selection.selectedInCategory(1)).toBe(1);

    selection.clearCategory(1);
    expect(selection.selectedIds()).toEqual([]);
    expect(selection.isSelected(11)).toBe(true);
  });

  it('counts the categories a selection spans, which is how many tags it creates', () => {
    const selection = new CatalogSelection();
    selection.setCategories([
      category(),
      category({
        id: 2,
        key: 'science',
        name: 'Science',
        feeds: [
          {
            id: 20,
            title: 'Q',
            description: null,
            siteUrl: null,
            faviconUrl: '/q',
            subscribed: false,
          },
        ],
      }),
    ]);

    selection.toggle(10);
    expect(selection.selectedCategoryCount()).toBe(1);

    selection.toggle(20);
    expect(selection.selectedCategoryCount()).toBe(2);
  });
});
```

- [ ] **Step 2: Run it to confirm it fails**

Run: `npx jest src/app/discover/catalog-selection.store.spec.ts`
Expected: FAIL — cannot resolve `./catalog-selection.store`.

- [ ] **Step 3: Implement it**

`frontend/src/app/discover/catalog-selection.store.ts`:

```ts
// src/app/discover/catalog-selection.store.ts
import { Injectable, computed, signal } from '@angular/core';
import { CatalogCategoryDto } from './catalog.models';

/**
 * Client-side picker state. Nothing is written until Subscribe, so this is the
 * whole model of "what have I chosen".
 *
 * Already-subscribed feeds are LOCKED: they render selected and disabled, and
 * they are excluded from selectedIds() because re-submitting them would only
 * produce skips.
 */
@Injectable()
export class CatalogSelection {
  private readonly categories = signal<CatalogCategoryDto[]>([]);
  private readonly picked = signal<ReadonlySet<number>>(new Set());

  /** feedId -> categoryId, rebuilt whenever the catalog changes. */
  private readonly categoryOf = computed(() => {
    const map = new Map<number, number>();
    for (const category of this.categories()) {
      for (const feed of category.feeds) map.set(feed.id, category.id);
    }
    return map;
  });

  private readonly locked = computed(() => {
    const ids = new Set<number>();
    for (const category of this.categories()) {
      for (const feed of category.feeds) if (feed.subscribed) ids.add(feed.id);
    }
    return ids;
  });

  readonly selectedIds = computed(() => [...this.picked()]);
  readonly selectedCount = computed(() => this.picked().size);

  readonly selectedCategoryCount = computed(() => {
    const of = this.categoryOf();
    const categories = new Set<number>();
    for (const id of this.picked()) {
      const categoryId = of.get(id);
      if (categoryId !== undefined) categories.add(categoryId);
    }
    return categories.size;
  });

  setCategories(categories: CatalogCategoryDto[]): void {
    this.categories.set(categories);
    this.picked.set(new Set());
  }

  isLocked(feedId: number): boolean {
    return this.locked().has(feedId);
  }

  /** Locked feeds read as selected so the card renders ticked and disabled. */
  isSelected(feedId: number): boolean {
    return this.locked().has(feedId) || this.picked().has(feedId);
  }

  selectedInCategory(categoryId: number): number {
    const of = this.categoryOf();
    let count = 0;
    for (const id of this.picked()) if (of.get(id) === categoryId) count++;
    return count;
  }

  toggle(feedId: number): void {
    if (this.locked().has(feedId)) return;
    const next = new Set(this.picked());
    if (!next.delete(feedId)) next.add(feedId);
    this.picked.set(next);
  }

  selectAll(categoryId: number): void {
    const category = this.categories().find((c) => c.id === categoryId);
    if (!category) return;
    const next = new Set(this.picked());
    for (const feed of category.feeds) if (!feed.subscribed) next.add(feed.id);
    this.picked.set(next);
  }

  clearCategory(categoryId: number): void {
    const category = this.categories().find((c) => c.id === categoryId);
    if (!category) return;
    const next = new Set(this.picked());
    for (const feed of category.feeds) next.delete(feed.id);
    this.picked.set(next);
  }
}
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `npx jest src/app/discover/catalog-selection.store.spec.ts`
Expected: PASS, 4 tests.

- [ ] **Step 5: Commit**

```bash
npm run check
git add src/app/discover/catalog-selection.store.ts src/app/discover/catalog-selection.store.spec.ts
git commit -m "feat(discover): catalog selection store (#99)"
```

---

### Task 17: Scroll-spy state

The rail and the chip strip render the same state, so it lives in one place. The click-to-jump suspension is the load-bearing part: without it the highlight strobes through every category the smooth scroll passes.

**Files:**
- Create: `frontend/src/app/discover/active-category.ts`
- Test: `frontend/src/app/discover/active-category.spec.ts`

- [ ] **Step 1: Write the failing test**

`frontend/src/app/discover/active-category.spec.ts`:

```ts
import { ActiveCategory } from './active-category';

describe('ActiveCategory', () => {
  it('starts with nothing active and follows observed sections', () => {
    const active = new ActiveCategory();
    expect(active.activeId()).toBeNull();

    active.observed(7);
    expect(active.activeId()).toBe(7);
  });

  it('ignores observations while a jump is settling, then resumes', () => {
    const active = new ActiveCategory();

    active.jumpTo(3);
    expect(active.activeId()).toBe(3);

    // Sections flying past during the smooth scroll must not steal the highlight.
    active.observed(4);
    active.observed(5);
    expect(active.activeId()).toBe(3);

    active.settled();
    active.observed(5);
    expect(active.activeId()).toBe(5);
  });
});
```

- [ ] **Step 2: Run it to confirm it fails**

Run: `npx jest src/app/discover/active-category.spec.ts`
Expected: FAIL — cannot resolve `./active-category`.

- [ ] **Step 3: Implement it**

`frontend/src/app/discover/active-category.ts`:

```ts
// src/app/discover/active-category.ts
import { Injectable, signal } from '@angular/core';

/**
 * Which category the scroll is currently inside. Rendered twice — as the desktop
 * rail's highlighted row and as the mobile chip strip's active chip — so the
 * state lives here rather than in either component.
 *
 * jumpTo() sets the active id directly AND suspends observation: a click-to-jump
 * scroll passes through every category in between, and each one would otherwise
 * report itself active on the way past.
 */
@Injectable()
export class ActiveCategory {
  private readonly id = signal<number | null>(null);
  private suspended = false;

  readonly activeId = this.id.asReadonly();

  /** An IntersectionObserver reported this section as the one in view. */
  observed(categoryId: number): void {
    if (this.suspended) return;
    this.id.set(categoryId);
  }

  /** The user clicked a rail row or a chip. */
  jumpTo(categoryId: number): void {
    this.suspended = true;
    this.id.set(categoryId);
  }

  /** The smooth scroll finished; observations count again. */
  settled(): void {
    this.suspended = false;
  }
}
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `npx jest src/app/discover/active-category.spec.ts`
Expected: PASS, 2 tests.

- [ ] **Step 5: Commit**

```bash
npm run check
git add src/app/discover/active-category.ts src/app/discover/active-category.spec.ts
git commit -m "feat(discover): scroll-spy state with jump suspension (#99)"
```

---

### Task 18: The skip flag

**Files:**
- Create: `frontend/src/app/discover/onboarding-skip.ts`
- Test: `frontend/src/app/discover/onboarding-skip.spec.ts`

- [ ] **Step 1: Write the failing test**

`frontend/src/app/discover/onboarding-skip.spec.ts`:

```ts
import { OnboardingSkip } from './onboarding-skip';

describe('OnboardingSkip', () => {
  beforeEach(() => sessionStorage.clear());

  it('is not skipped by default', () => {
    expect(new OnboardingSkip().wasSkipped()).toBe(false);
  });

  it('remembers a skip for the session', () => {
    const skip = new OnboardingSkip();
    skip.remember();
    expect(new OnboardingSkip().wasSkipped()).toBe(true);
  });

  it('survives a storage that throws, because a private-mode failure must not break the reader', () => {
    const broken = jest.spyOn(Storage.prototype, 'setItem').mockImplementation(() => {
      throw new Error('QuotaExceeded');
    });

    expect(() => new OnboardingSkip().remember()).not.toThrow();
    broken.mockRestore();
  });
});
```

- [ ] **Step 2: Run it to confirm it fails**

Run: `npx jest src/app/discover/onboarding-skip.spec.ts`
Expected: FAIL — cannot resolve `./onboarding-skip`.

- [ ] **Step 3: Implement it**

`frontend/src/app/discover/onboarding-skip.ts`:

```ts
// src/app/discover/onboarding-skip.ts
import { Injectable } from '@angular/core';

const KEY = 'onboarding.skipped';

/**
 * Remembers, for this browser session only, that the user chose "Skip for now".
 *
 * Session-scoped rather than a database column on purpose: the trigger for the
 * picker is "this user has zero subscriptions", and an empty reader SHOULD keep
 * offering the picker on a later visit. The flag exists only so that skipping
 * does not bounce the user straight back into the redirect that sent them there.
 */
@Injectable({ providedIn: 'root' })
export class OnboardingSkip {
  wasSkipped(): boolean {
    try {
      return sessionStorage.getItem(KEY) === '1';
    } catch {
      // Storage disabled (private mode, blocked cookies): treat as not skipped.
      return false;
    }
  }

  remember(): void {
    try {
      sessionStorage.setItem(KEY, '1');
    } catch {
      // Not being able to remember a skip is survivable; throwing here is not.
    }
  }
}
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `npx jest src/app/discover/onboarding-skip.spec.ts`
Expected: PASS, 3 tests.

- [ ] **Step 5: Commit**

```bash
npm run check
git add src/app/discover/onboarding-skip.ts src/app/discover/onboarding-skip.spec.ts
git commit -m "feat(discover): session-scoped onboarding skip flag (#99)"
```

---

### Task 19: The picker component

**Files:**
- Create: `frontend/src/app/discover/discover.component.ts`
- Create: `frontend/src/app/discover/discover.component.html`
- Create: `frontend/src/app/discover/category-rail.component.ts`
- Create: `frontend/src/app/discover/category-chips.component.ts`
- Modify: `frontend/public/i18n/en.json`, `frontend/public/i18n/de.json`
- Modify: `frontend/src/app/app.routes.ts`
- Test: `frontend/src/app/discover/discover.component.spec.ts`

- [ ] **Step 1: Add the translation keys**

Add a `discover` block to `frontend/public/i18n/en.json`:

```json
  "discover": {
    "title": "Discover feeds",
    "subtitle": "Pick a few to get started — you can always add more later",
    "categories": "Categories",
    "selectAll": "Select all",
    "clear": "Clear",
    "subscribed": "Already subscribed",
    "summary": "{{feeds}} feeds in {{categories}} categories",
    "createsTags": "creates {{count}} tags",
    "subscribe": "Subscribe",
    "skip": "Skip for now",
    "loadFailed": "The catalog could not be loaded.",
    "retry": "Try again",
    "emptyCatalog": "No suggestions have been set up yet. An administrator can import a catalog from the admin area."
  }
```

And the German equivalents in `frontend/public/i18n/de.json`:

```json
  "discover": {
    "title": "Feeds entdecken",
    "subtitle": "Wähle ein paar aus – du kannst jederzeit weitere hinzufügen",
    "categories": "Kategorien",
    "selectAll": "Alle auswählen",
    "clear": "Zurücksetzen",
    "subscribed": "Bereits abonniert",
    "summary": "{{feeds}} Feeds in {{categories}} Kategorien",
    "createsTags": "erstellt {{count}} Tags",
    "subscribe": "Abonnieren",
    "skip": "Später",
    "loadFailed": "Der Katalog konnte nicht geladen werden.",
    "retry": "Erneut versuchen",
    "emptyCatalog": "Es sind noch keine Vorschläge eingerichtet. Ein Administrator kann im Admin-Bereich einen Katalog importieren."
  }
```

- [ ] **Step 2: Write the failing component test**

`frontend/src/app/discover/discover.component.spec.ts`:

```ts
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { ComponentFixture, TestBed } from '@angular/core/testing';
import { provideHttpClient } from '@angular/common/http';
import { provideRouter, Router } from '@angular/router';
import { getTranslocoModule } from '../../testing/transloco-testing';
import { API_BASE_URL } from '../core/api';
import { DiscoverComponent } from './discover.component';

const CATALOG = {
  categories: [
    {
      id: 1,
      key: 'technology',
      name: 'Technology',
      icon: 'memory',
      color: '#3b82f6',
      feeds: [
        { id: 10, title: 'The Verge', description: 'Tech', siteUrl: null, faviconUrl: '/f/10', subscribed: false },
        { id: 11, title: 'Wired', description: null, siteUrl: null, faviconUrl: '/f/11', subscribed: true },
      ],
    },
  ],
};

describe('DiscoverComponent', () => {
  let fixture: ComponentFixture<DiscoverComponent>;
  let http: HttpTestingController;

  beforeEach(async () => {
    await TestBed.configureTestingModule({
      imports: [DiscoverComponent, getTranslocoModule()],
      providers: [
        provideHttpClient(),
        provideHttpClientTesting(),
        provideRouter([]),
        { provide: API_BASE_URL, useValue: 'https://api.test' },
      ],
    }).compileComponents();

    fixture = TestBed.createComponent(DiscoverComponent);
    http = TestBed.inject(HttpTestingController);
    fixture.detectChanges();
    http.expectOne('https://api.test/api/catalog').flush(CATALOG);
    fixture.detectChanges();
  });

  afterEach(() => http.verify());

  it('renders every category and its feeds', () => {
    const cards = fixture.nativeElement.querySelectorAll('[data-testid="catalog-feed"]');
    expect(cards).toHaveLength(2);
  });

  it('renders an already-subscribed feed as checked and disabled', () => {
    const boxes: HTMLInputElement[] = Array.from(
      fixture.nativeElement.querySelectorAll('[data-testid="catalog-feed"] input'),
    );
    expect(boxes[1].checked).toBe(true);
    expect(boxes[1].disabled).toBe(true);
  });

  it('submits only the newly picked ids', () => {
    const boxes: HTMLInputElement[] = Array.from(
      fixture.nativeElement.querySelectorAll('[data-testid="catalog-feed"] input'),
    );
    boxes[0].click();
    fixture.detectChanges();

    fixture.nativeElement.querySelector('[data-testid="subscribe"]').click();

    const req = http.expectOne('https://api.test/api/onboarding/subscribe');
    expect(req.request.body).toEqual({ catalogFeedIds: [10] });
    req.flush({ subscribed: 1, skipped: 0, skippedOverLimit: 0, tagsCreated: [] });
  });

  it('navigates into the reader after a successful subscribe', async () => {
    const router = TestBed.inject(Router);
    const navigate = jest.spyOn(router, 'navigate').mockResolvedValue(true);

    const boxes: HTMLInputElement[] = Array.from(
      fixture.nativeElement.querySelectorAll('[data-testid="catalog-feed"] input'),
    );
    boxes[0].click();
    fixture.detectChanges();
    fixture.nativeElement.querySelector('[data-testid="subscribe"]').click();

    http
      .expectOne('https://api.test/api/onboarding/subscribe')
      .flush({ subscribed: 1, skipped: 0, skippedOverLimit: 0, tagsCreated: [] });
    await fixture.whenStable();

    expect(navigate).toHaveBeenCalledWith(['/']);
  });
});
```

If `src/testing/transloco-testing.ts` does not exist, copy the pattern from an existing spec that imports Transloco — `src/app/reader/entry-list/entry-list.component.spec.ts` shows how this codebase sets it up.

- [ ] **Step 3: Run it to confirm it fails**

Run: `npx jest src/app/discover/discover.component.spec.ts`
Expected: FAIL — cannot resolve `./discover.component`.

- [ ] **Step 4: Write the rail and the chip strip**

`frontend/src/app/discover/category-rail.component.ts`:

```ts
// src/app/discover/category-rail.component.ts
import { ChangeDetectionStrategy, Component, input, output } from '@angular/core';
import { TranslocoPipe } from '@jsverse/transloco';
import { CatalogCategoryDto } from './catalog.models';

/**
 * Desktop navigation for the picker. Two jobs: jump to a category, and show how
 * many feeds have been picked from each one so "what have I chosen so far" is
 * answerable without scrolling back.
 *
 * Navigation only — clicking a row never selects a feed.
 */
@Component({
  selector: 'app-category-rail',
  standalone: true,
  imports: [TranslocoPipe],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <nav class="rail" [attr.aria-label]="'discover.categories' | transloco">
      <ul>
        @for (category of categories(); track category.id) {
          <li>
            <button
              type="button"
              [class.active]="category.id === activeId()"
              [attr.aria-current]="category.id === activeId() ? 'true' : null"
              (click)="jump.emit(category.id)"
            >
              <span class="dot" [style.background]="category.color"></span>
              <span class="name">{{ category.name }}</span>
              <span class="count" [class.picked]="picked()[category.id] > 0">
                {{ picked()[category.id] || category.feeds.length }}
              </span>
            </button>
          </li>
        }
      </ul>
    </nav>
  `,
  styles: `
    .rail {
      position: sticky;
      top: 0;
      align-self: start;
      width: 200px;
      padding: var(--space-2) 0;
      border-right: 1px solid var(--border);
      background: var(--surface-1);
    }
    ul {
      margin: 0;
      padding: 0;
      list-style: none;
    }
    button {
      display: flex;
      gap: var(--space-2);
      align-items: center;
      width: 100%;
      padding: var(--space-1) var(--space-3);
      border: 0;
      background: none;
      color: var(--text-secondary);
      font-size: var(--fs-sm);
      text-align: left;
      cursor: pointer;
    }
    button.active {
      box-shadow: inset 2px 0 0 var(--accent);
      background: var(--accent-soft);
      color: var(--text-primary);
      font-weight: 600;
    }
    .dot {
      flex: none;
      width: 8px;
      height: 8px;
      border-radius: 3px;
    }
    .name {
      overflow: hidden;
      text-overflow: ellipsis;
      white-space: nowrap;
    }
    .count {
      margin-left: auto;
      color: var(--text-muted);
      font-size: var(--fs-sm);
    }
    .count.picked {
      color: var(--accent);
      font-weight: 700;
    }
    @media (max-width: 800px) {
      .rail {
        display: none;
      }
    }
  `,
})
export class CategoryRailComponent {
  readonly categories = input.required<CatalogCategoryDto[]>();
  readonly activeId = input<number | null>(null);
  /** categoryId -> how many of its feeds are picked. */
  readonly picked = input.required<Record<number, number>>();
  readonly jump = output<number>();
}
```

`frontend/src/app/discover/category-chips.component.ts`:

```ts
// src/app/discover/category-chips.component.ts
import { ChangeDetectionStrategy, Component, input, output } from '@angular/core';
import { TranslocoPipe } from '@jsverse/transloco';
import { CatalogCategoryDto } from './catalog.models';

/**
 * The rail, laid on its side, for viewports too narrow to carry it. Same state,
 * same jump behaviour — only the rendering differs, which is why ActiveCategory
 * lives outside both components.
 */
@Component({
  selector: 'app-category-chips',
  standalone: true,
  imports: [TranslocoPipe],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <nav class="chips" [attr.aria-label]="'discover.categories' | transloco">
      @for (category of categories(); track category.id) {
        <button
          type="button"
          [class.active]="category.id === activeId()"
          [attr.aria-current]="category.id === activeId() ? 'true' : null"
          (click)="jump.emit(category.id)"
        >
          {{ category.name }}
          @if (picked()[category.id] > 0) {
            <span class="n">{{ picked()[category.id] }}</span>
          }
        </button>
      }
    </nav>
  `,
  styles: `
    .chips {
      display: none;
      position: sticky;
      top: 0;
      z-index: 2;
      gap: var(--space-1);
      padding: var(--space-2) var(--space-3);
      overflow-x: auto;
      border-bottom: 1px solid var(--border);
      background: var(--surface-1);
      scrollbar-width: none;
    }
    .chips::-webkit-scrollbar {
      display: none;
    }
    button {
      flex: none;
      padding: var(--space-1) var(--space-3);
      border: 1px solid var(--border);
      border-radius: 999px;
      background: var(--surface-1);
      color: var(--text-secondary);
      font-size: var(--fs-sm);
      white-space: nowrap;
      cursor: pointer;
    }
    button.active {
      border-color: var(--accent);
      background: var(--accent);
      color: var(--on-accent);
      font-weight: 600;
    }
    .n {
      margin-left: var(--space-1);
      font-weight: 700;
    }
    @media (max-width: 800px) {
      .chips {
        display: flex;
      }
    }
  `,
})
export class CategoryChipsComponent {
  readonly categories = input.required<CatalogCategoryDto[]>();
  readonly activeId = input<number | null>(null);
  readonly picked = input.required<Record<number, number>>();
  readonly jump = output<number>();
}
```

- [ ] **Step 5: Write the picker component**

`frontend/src/app/discover/discover.component.ts`:

```ts
// src/app/discover/discover.component.ts
import { HttpErrorResponse } from '@angular/common/http';
import {
  AfterViewInit,
  ChangeDetectionStrategy,
  Component,
  OnDestroy,
  computed,
  inject,
  signal,
  viewChildren,
} from '@angular/core';
import { ElementRef } from '@angular/core';
import { Router } from '@angular/router';
import { TranslocoPipe } from '@jsverse/transloco';
import { Problem, parseProblem } from '../core/problem';
import { IconComponent } from '../shared/icon/icon.component';
import { SubscriptionsStore } from '../reader/subscriptions.store';
import { TagsStore } from '../reader/tags.store';
import { ActiveCategory } from './active-category';
import { CatalogApi } from './catalog-api';
import { CatalogStore } from './catalog.store';
import { CatalogSelection } from './catalog-selection.store';
import { CategoryChipsComponent } from './category-chips.component';
import { CategoryRailComponent } from './category-rail.component';
import { OnboardingSkip } from './onboarding-skip';

/** How long a smooth scroll is given to settle before observations count again. */
const JUMP_SETTLE_MS = 700;

@Component({
  selector: 'app-discover',
  standalone: true,
  imports: [TranslocoPipe, IconComponent, CategoryRailComponent, CategoryChipsComponent],
  changeDetection: ChangeDetectionStrategy.OnPush,
  providers: [CatalogSelection, ActiveCategory],
  templateUrl: './discover.component.html',
  styleUrl: './discover.component.scss',
})
export class DiscoverComponent implements AfterViewInit, OnDestroy {
  private readonly api = inject(CatalogApi);
  private readonly router = inject(Router);
  private readonly subs = inject(SubscriptionsStore);
  private readonly tags = inject(TagsStore);
  private readonly skip = inject(OnboardingSkip);
  private readonly catalog = inject(CatalogStore);

  readonly selection = inject(CatalogSelection);
  readonly active = inject(ActiveCategory);

  readonly categories = this.catalog.categories;
  readonly loading = this.catalog.loading;
  readonly submitting = signal(false);
  readonly error = signal<Problem | null>(null);

  /** Resolved and there is genuinely nothing to pick — nobody has imported a
   *  catalog yet. The shell will not redirect into this state, but the route
   *  stays reachable from Settings, so it has to say something honest. */
  readonly catalogEmpty = computed(() => this.catalog.resolved() && !this.catalog.hasEntries());

  private readonly sections = viewChildren<ElementRef<HTMLElement>>('section');
  private observer: IntersectionObserver | null = null;
  private settleTimer: ReturnType<typeof setTimeout> | null = null;

  /** categoryId -> picked count, recomputed for the rail and the chips. */
  readonly pickedByCategory = computed(() => {
    const counts: Record<number, number> = {};
    for (const category of this.categories()) {
      counts[category.id] = this.selection.selectedInCategory(category.id);
    }
    return counts;
  });

  constructor() {
    this.load();
  }

  load(): void {
    this.error.set(null);
    this.catalog.load();
  }

  /** Selection state follows whatever the store holds, including a reload after
   *  an invalidate. */
  private readonly syncSelection = effect(() => {
    this.selection.setCategories(this.catalog.categories());
  });

  ngAfterViewInit(): void {
    // rootMargin pins the "active" band near the top of the viewport, so the
    // active category is the one whose header you just scrolled past — not
    // whichever section happens to occupy the most pixels.
    this.observer = new IntersectionObserver(
      (entries) => {
        for (const entry of entries) {
          if (!entry.isIntersecting) continue;
          const id = Number((entry.target as HTMLElement).dataset['categoryId']);
          if (!Number.isNaN(id)) this.active.observed(id);
        }
      },
      { rootMargin: '0px 0px -70% 0px', threshold: 0 },
    );
    for (const section of this.sections()) this.observer.observe(section.nativeElement);
  }

  ngOnDestroy(): void {
    this.observer?.disconnect();
    if (this.settleTimer) clearTimeout(this.settleTimer);
  }

  onJump(categoryId: number): void {
    this.active.jumpTo(categoryId);
    const target = this.sections().find(
      (s) => Number(s.nativeElement.dataset['categoryId']) === categoryId,
    );
    target?.nativeElement.scrollIntoView({ behavior: 'smooth', block: 'start' });

    if (this.settleTimer) clearTimeout(this.settleTimer);
    this.settleTimer = setTimeout(() => this.active.settled(), JUMP_SETTLE_MS);
  }

  onSkip(): void {
    this.skip.remember();
    void this.router.navigate(['/']);
  }

  /**
   * Subscribe, then navigate — and nothing else. The sweep is NOT started here:
   * the reader shell owns it, driven by "subscriptions exist that have never
   * been fetched", so there is no ordering between two components to get wrong.
   */
  onSubscribe(): void {
    const ids = this.selection.selectedIds();
    if (ids.length === 0 || this.submitting()) return;

    this.submitting.set(true);
    this.error.set(null);
    this.api.subscribe(ids).subscribe({
      next: () => {
        this.subs.load();
        this.tags.load();
        // Every picked feed is now `subscribed`, so the cached catalog is stale.
        this.catalog.invalidate();
        this.submitting.set(false);
        void this.router.navigate(['/']);
      },
      error: (e: HttpErrorResponse) => {
        this.error.set(parseProblem(e));
        this.submitting.set(false);
      },
    });
  }
}
```

- [ ] **Step 6: Write the template**

`frontend/src/app/discover/discover.component.html`:

```html
<div class="discover">
  <header class="top">
    <h1>{{ 'discover.title' | transloco }}</h1>
    <p>{{ 'discover.subtitle' | transloco }}</p>
  </header>

  <app-category-chips
    [categories]="categories()"
    [activeId]="active.activeId()"
    [picked]="pickedByCategory()"
    (jump)="onJump($event)"
  />

  <div class="body">
    <app-category-rail
      [categories]="categories()"
      [activeId]="active.activeId()"
      [picked]="pickedByCategory()"
      (jump)="onJump($event)"
    />

    <main class="sections">
      @if (error(); as problem) {
        <p class="error" role="alert">
          {{ 'discover.loadFailed' | transloco }}
          <button type="button" (click)="load()">{{ 'discover.retry' | transloco }}</button>
        </p>
      }

      @if (catalogEmpty()) {
        <p class="empty-catalog">{{ 'discover.emptyCatalog' | transloco }}</p>
      }

      @for (category of categories(); track category.id) {
        <!-- role="group", not the implicit "region" a labelled <section> gets:
             the design calls each category a labelled GROUP of checkboxes, and
             the e2e locates them with getByRole('group'). -->
        <section
          #section
          role="group"
          [attr.data-category-id]="category.id"
          [attr.aria-labelledby]="'cat-' + category.id"
        >
          <div class="cat-head">
            <span class="cat-ico" [style.background]="category.color">
              <app-icon [name]="category.icon" [size]="14" />
            </span>
            <h2 [id]="'cat-' + category.id">{{ category.name }}</h2>
            <span class="cat-count">{{ category.feeds.length }}</span>
            <span class="cat-act">
              <button type="button" (click)="selection.selectAll(category.id)">
                {{ 'discover.selectAll' | transloco }}
              </button>
              <button type="button" (click)="selection.clearCategory(category.id)">
                {{ 'discover.clear' | transloco }}
              </button>
            </span>
          </div>

          <ul class="grid">
            @for (feed of category.feeds; track feed.id) {
              <li data-testid="catalog-feed">
                <label [class.on]="selection.isSelected(feed.id)" [class.locked]="selection.isLocked(feed.id)">
                  <input
                    type="checkbox"
                    [checked]="selection.isSelected(feed.id)"
                    [disabled]="selection.isLocked(feed.id)"
                    (change)="selection.toggle(feed.id)"
                  />
                  <img class="fav" [src]="feed.faviconUrl" alt="" width="20" height="20" loading="lazy" />
                  <span class="text">
                    <span class="title">{{ feed.title }}</span>
                    @if (selection.isLocked(feed.id)) {
                      <span class="desc">{{ 'discover.subscribed' | transloco }}</span>
                    } @else if (feed.description) {
                      <span class="desc">{{ feed.description }}</span>
                    }
                  </span>
                </label>
              </li>
            }
          </ul>
        </section>
      }
    </main>
  </div>

  <footer class="foot">
    <span class="cnt" aria-live="polite">
      {{
        'discover.summary'
          | transloco: { feeds: selection.selectedCount(), categories: selection.selectedCategoryCount() }
      }}
    </span>
    <button type="button" class="ghost" (click)="onSkip()">{{ 'discover.skip' | transloco }}</button>
    <button
      type="button"
      class="primary"
      data-testid="subscribe"
      [disabled]="selection.selectedCount() === 0 || submitting()"
      (click)="onSubscribe()"
    >
      {{ 'discover.subscribe' | transloco }}
    </button>
  </footer>
</div>
```

- [ ] **Step 7: Write the stylesheet**

`frontend/src/app/discover/discover.component.scss` — token-only, no hex. The one exception is the category colour, which is bound inline from the API in the template above, never written here.

```scss
.discover {
  display: flex;
  flex-direction: column;
  min-height: 100dvh;
  background: var(--surface-0);
}

.top {
  padding: var(--space-4) var(--space-5);
  border-bottom: 1px solid var(--border);
  background: var(--surface-1);

  h1 {
    margin: 0;
    font-size: var(--fs-xl);
  }

  p {
    margin: var(--space-1) 0 0;
    color: var(--text-secondary);
    font-size: var(--fs-sm);
  }
}

.body {
  display: flex;
  flex: 1;
  align-items: flex-start;
}

.sections {
  flex: 1;
  min-width: 0;
  padding: var(--space-4) var(--space-5) var(--space-6);
}

.cat-head {
  position: sticky;
  top: 0;
  z-index: 1;
  display: flex;
  gap: var(--space-2);
  align-items: center;
  margin: 0 calc(var(--space-5) * -1) var(--space-3);
  padding: var(--space-2) var(--space-5);
  border-bottom: 1px solid var(--border);
  background: var(--surface-0);

  h2 {
    margin: 0;
    font-size: var(--fs-base);
  }
}

.cat-ico {
  display: flex;
  flex: none;
  align-items: center;
  justify-content: center;
  width: 26px;
  height: 26px;
  border-radius: var(--radius);
  color: var(--on-accent);
}

.cat-count {
  color: var(--text-muted);
  font-size: var(--fs-sm);
}

.cat-act {
  display: flex;
  gap: var(--space-1);
  margin-left: auto;

  button {
    padding: var(--space-1) var(--space-3);
    border: 1px solid var(--border);
    border-radius: 999px;
    background: var(--accent-soft);
    color: var(--accent);
    font-size: var(--fs-sm);
    cursor: pointer;
  }
}

.grid {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(230px, 1fr));
  gap: var(--space-2);
  margin: 0 0 var(--space-5);
  padding: 0;
  list-style: none;
}

label {
  display: flex;
  gap: var(--space-2);
  align-items: flex-start;
  height: 100%;
  padding: var(--space-2) var(--space-3);
  border: 1px solid var(--border);
  border-radius: var(--radius);
  background: var(--surface-1);
  cursor: pointer;

  &.on {
    border-color: var(--accent);
    background: var(--accent-soft);
  }

  &.locked {
    cursor: default;
    opacity: 0.55;
  }
}

.fav {
  flex: none;
  border-radius: 5px;
}

.text {
  display: flex;
  flex-direction: column;
  min-width: 0;
}

.title {
  font-size: var(--fs-sm);
  font-weight: 600;
}

.desc {
  overflow: hidden;
  color: var(--text-muted);
  font-size: var(--fs-sm);
  text-overflow: ellipsis;
  white-space: nowrap;
}

.foot {
  position: sticky;
  bottom: 0;
  display: flex;
  gap: var(--space-3);
  align-items: center;
  padding: var(--space-3) var(--space-5);
  border-top: 1px solid var(--border);
  background: var(--surface-1);

  .cnt {
    font-size: var(--fs-sm);
    font-weight: 600;
  }

  .ghost {
    margin-left: auto;
    border: 0;
    background: none;
    color: var(--text-secondary);
    cursor: pointer;
  }

  .primary {
    height: var(--control-h);
    padding: 0 var(--space-5);
    border: 0;
    border-radius: var(--radius);
    background: var(--accent);
    color: var(--on-accent);
    font-weight: 600;
    cursor: pointer;

    &:disabled {
      cursor: default;
      opacity: 0.5;
    }
  }
}

.error {
  margin: 0 0 var(--space-4);
  padding: var(--space-3);
  border-radius: var(--radius);
  background: var(--bg-danger);
  color: var(--danger);
}

.empty-catalog {
  margin: var(--space-6) 0;
  color: var(--text-secondary);
  text-align: center;
}
```

- [ ] **Step 8: Register the route**

In `frontend/src/app/app.routes.ts`, add above the `''` route:

```ts
  {
    path: 'discover',
    canActivate: [authGuard],
    loadComponent: () =>
      import('./discover/discover.component').then((m) => m.DiscoverComponent),
  },
```

- [ ] **Step 9: Run the tests to verify they pass**

Run: `npx jest src/app/discover`
Expected: PASS — all discover specs, including the 4 component tests.

- [ ] **Step 10: Run the gate and commit**

```bash
npm run check
git add src/app/discover src/app/app.routes.ts public/i18n/en.json public/i18n/de.json
git commit -m "feat(discover): catalog picker with tracking rail and chip strip (#99)"
```

---

# Phase 5 — Entry, sweep and progress

### Task 20: Per-slice tick on `RefreshService`

Today only `onDone` fires, so a 35-feed sweep leaves the entry list frozen until the whole thing ends. The list must repopulate as slices land.

**Files:**
- Modify: `frontend/src/app/reader/refresh.service.ts`
- Modify: `frontend/src/app/reader/refresh.service.spec.ts`

- [ ] **Step 1: Write the failing test**

Append to `frontend/src/app/reader/refresh.service.spec.ts`:

```ts
  it('emits a slice tick for every partial report, not just at the end', () => {
    const ticks: number[] = [];
    const service = TestBed.inject(RefreshService);

    TestBed.runInInjectionContext(() => {
      effect(() => ticks.push(service.slice()));
    });

    service.run();

    http.expectOne(`${BASE}/api/refresh`).flush({
      status: 'partial',
      total: 4,
      fetched: 2,
      notModified: 0,
      failed: 0,
      skippedForBudget: 0,
      remaining: 2,
      pruned: 0,
    });
    TestBed.tick();

    http.expectOne(`${BASE}/api/refresh`).flush({
      status: 'completed',
      total: 4,
      fetched: 4,
      notModified: 0,
      failed: 0,
      skippedForBudget: 0,
      remaining: 0,
      pruned: 0,
    });
    TestBed.tick();

    // 0 on subscribe, then one increment per landed report.
    expect(ticks).toEqual([0, 1, 2]);
  });
```

Match the existing spec's setup — reuse whatever `BASE`, `http` and `beforeEach` it already defines rather than introducing new ones, and add `effect` to the `@angular/core` import.

- [ ] **Step 2: Run it to confirm it fails**

Run: `npx jest src/app/reader/refresh.service.spec.ts`
Expected: FAIL — `service.slice is not a function`.

- [ ] **Step 3: Add the signal**

In `frontend/src/app/reader/refresh.service.ts`, add the signal beside the others:

```ts
  /** Increments every time a report lands, including partial slices. Consumers watch
   *  this to refetch progressively — waiting for onDone would leave a new user
   *  staring at an empty list for the whole sweep. */
  readonly slice = signal(0);
```

Bump it in `step()`'s `next` handler, as the first thing after the report is stored:

```ts
      next: (r) => {
        this.report.set(r);
        this.slice.update((n) => n + 1);
        if (r.status === 'partial' && r.remaining > 0) {
```

And reset it in `run()`, next to the existing resets:

```ts
    this.report.set(null);
    this.slice.set(0);
    this.error.set(null);
```

- [ ] **Step 4: Run the tests to verify they pass**

Run: `npx jest src/app/reader/refresh.service.spec.ts`
Expected: PASS — the new test plus every pre-existing one.

- [ ] **Step 5: Commit**

```bash
npm run check
git add src/app/reader/refresh.service.ts src/app/reader/refresh.service.spec.ts
git commit -m "feat(refresh): emit a tick per landed slice (#99)"
```

---

### Task 21: Shell owns the redirect and the sweep

Two rules, both state-driven, so no component has to run before another:

- *Subscriptions resolved, none exist, not skipped this session, **and the catalog has something to offer*** → redirect to `/discover` with `replaceUrl`.
- *Subscriptions exist and none has ever been fetched* → run an unscoped sweep.

The catalog condition matters because nothing seeds the catalog: a deployment where the admin has not imported yet would otherwise send every new user to a blank picker. So the shell asks the catalog store first, and only redirects once it knows there is something there. The lookup costs an extra request **only** in the zero-subscription case, and the store is shared with `/discover`, so a redirect that does happen still fetches the catalog exactly once.

**Files:**
- Modify: `frontend/src/app/reader/subscriptions.store.ts`
- Modify: `frontend/src/app/reader/reader-shell.component.ts`
- Test: `frontend/src/app/reader/reader-shell.component.spec.ts`

- [ ] **Step 1: Write the failing tests**

Append to `frontend/src/app/reader/reader-shell.component.spec.ts` (reuse the file's existing harness for creating the component and flushing the subscriptions request):

```ts
  it('redirects a user with no subscriptions to the picker, replacing the URL', async () => {
    const router = TestBed.inject(Router);
    const navigate = jest.spyOn(router, 'navigate').mockResolvedValue(true);

    flushSubscriptions({ subscriptions: [], favoritesCount: 0, keptCount: 0 });
    await fixture.whenStable();

    http.expectOne(`${BASE}/api/catalog`).flush({
      categories: [
        {
          id: 1,
          key: 'technology',
          name: 'Technology',
          icon: 'memory',
          color: '#3b82f6',
          feeds: [
            { id: 10, title: 'The Verge', description: null, siteUrl: null, faviconUrl: '/f/10', subscribed: false },
          ],
        },
      ],
    });
    await fixture.whenStable();

    expect(navigate).toHaveBeenCalledWith(['/discover'], { replaceUrl: true });
  });

  it('does not redirect when nobody has imported a catalog yet', async () => {
    const router = TestBed.inject(Router);
    const navigate = jest.spyOn(router, 'navigate').mockResolvedValue(true);

    flushSubscriptions({ subscriptions: [], favoritesCount: 0, keptCount: 0 });
    await fixture.whenStable();

    http.expectOne(`${BASE}/api/catalog`).flush({ categories: [] });
    await fixture.whenStable();

    expect(navigate).not.toHaveBeenCalledWith(['/discover'], { replaceUrl: true });
  });

  it('does not redirect when the catalog cannot be loaded', async () => {
    const router = TestBed.inject(Router);
    const navigate = jest.spyOn(router, 'navigate').mockResolvedValue(true);

    flushSubscriptions({ subscriptions: [], favoritesCount: 0, keptCount: 0 });
    await fixture.whenStable();

    http
      .expectOne(`${BASE}/api/catalog`)
      .flush('nope', { status: 500, statusText: 'Server Error' });
    await fixture.whenStable();

    expect(navigate).not.toHaveBeenCalledWith(['/discover'], { replaceUrl: true });
  });

  it('does not even ask for the catalog when the user has subscriptions', async () => {
    flushSubscriptions({
      subscriptions: [{ ...SUBSCRIPTION_FIXTURE, id: 1, lastFetchedAt: '2026-07-26T10:00:00+00:00' }],
      favoritesCount: 0,
      keptCount: 0,
    });
    await fixture.whenStable();

    http.expectNone(`${BASE}/api/catalog`);
  });

  it('does not redirect when the user skipped this session, and does not fetch the catalog', async () => {
    TestBed.inject(OnboardingSkip).remember();
    const router = TestBed.inject(Router);
    const navigate = jest.spyOn(router, 'navigate').mockResolvedValue(true);

    flushSubscriptions({ subscriptions: [], favoritesCount: 0, keptCount: 0 });
    await fixture.whenStable();

    http.expectNone(`${BASE}/api/catalog`);
    expect(navigate).not.toHaveBeenCalledWith(['/discover'], { replaceUrl: true });
  });

  it('sweeps once when subscriptions exist that have never been fetched', async () => {
    const refresh = TestBed.inject(RefreshService);
    const run = jest.spyOn(refresh, 'run').mockImplementation(() => undefined);

    flushSubscriptions({
      subscriptions: [
        { ...SUBSCRIPTION_FIXTURE, id: 1, lastFetchedAt: null },
        { ...SUBSCRIPTION_FIXTURE, id: 2, lastFetchedAt: null },
      ],
      favoritesCount: 0,
      keptCount: 0,
    });
    await fixture.whenStable();

    expect(run).toHaveBeenCalledTimes(1);
  });

  it('does not sweep when every subscription has been fetched before', async () => {
    const refresh = TestBed.inject(RefreshService);
    const run = jest.spyOn(refresh, 'run').mockImplementation(() => undefined);

    flushSubscriptions({
      subscriptions: [{ ...SUBSCRIPTION_FIXTURE, id: 1, lastFetchedAt: '2026-07-26T10:00:00+00:00' }],
      favoritesCount: 0,
      keptCount: 0,
    });
    await fixture.whenStable();

    expect(run).not.toHaveBeenCalled();
  });
```

- [ ] **Step 2: Run them to confirm they fail**

Run: `npx jest src/app/reader/reader-shell.component.spec.ts`
Expected: FAIL — no redirect is issued and `run` is never called.

- [ ] **Step 3: Give the store a resolved flag**

The redirect must not fire before the answer is known, and `loading` is false both *before* the first load and *after* it. Add to `frontend/src/app/reader/subscriptions.store.ts`:

```ts
  /** True once a load has completed, successfully or not. `loading` cannot serve
   *  here: it is false BEFORE the first request too, which would let a redirect
   *  fire against an empty list the server has not answered on yet. */
  readonly resolved = signal(false);
```

Set it in both `load()` handlers, beside `this.loading.set(false)`:

```ts
        this.loading.set(false);
        this.resolved.set(true);
```

- [ ] **Step 4: Add the two rules to the shell**

In `frontend/src/app/reader/reader-shell.component.ts`, inject `OnboardingSkip` and add these to the class:

```ts
  private readonly skip = inject(OnboardingSkip);
  private readonly catalog = inject(CatalogStore);

  /** Is the picker worth showing at all? Nothing seeds the catalog — it arrives
   *  by admin import — so a deployment without one must not redirect anybody
   *  into a blank page. */
  private readonly onboardingAvailable = computed(
    () => this.catalog.resolved() && this.catalog.hasEntries(),
  );

  /** A brand-new subscription set: rows exist, none has ever been fetched. This
   *  is what a just-completed onboarding looks like from the shell's side. */
  private readonly awaitingFirstFetch = computed(
    () =>
      this.subs.resolved() &&
      this.subs.subscriptions().length > 0 &&
      this.subs.subscriptions().every((s) => s.lastFetchedAt === null),
  );

  private sweptOnce = false;
```

And in the constructor, after the existing initialisation:

```ts
    // Nothing to read and nothing skipped: send the user to the picker. Purely
    // state-driven — no guard, no resolver, so nothing blocks the route — and
    // gated on `resolved` so it never fires against a list the server has not
    // answered on yet. replaceUrl, or Back from /discover lands here and
    // redirects again: a dead Back button.
    effect(() => {
      if (!this.subs.resolved()) return;
      if (this.subs.subscriptions().length > 0) return;
      if (this.skip.wasSkipped()) return;

      // Ask what the catalog holds before deciding. load() is a no-op once
      // resolved, and the store is shared with /discover, so the redirect path
      // still fetches the catalog exactly once.
      this.catalog.load();
      if (!this.onboardingAvailable()) return;

      void this.router.navigate(['/discover'], { replaceUrl: true });
    });

    // The post-onboarding sweep. The shell owns it BY STATE rather than by being
    // called: RefreshService.run() early-returns while a refresh is already
    // running, so a call made from the picker could be silently swallowed by the
    // shell's own load. Expressing it as "feeds exist that have never been
    // fetched" removes the ordering question entirely.
    effect(() => {
      if (!this.awaitingFirstFetch() || this.sweptOnce) return;
      this.sweptOnce = true;
      this.refreshSvc.run();
    });

    // Repopulate as slices land, not only when the sweep ends. Landing in a
    // reader that stays empty for two minutes is the bad first impression this
    // whole feature exists to remove.
    effect(() => {
      if (this.refreshSvc.slice() === 0) return;
      this.subs.load();
      this.entries.load(queryFromSelection(this.selection()));
    });
```

- [ ] **Step 5: Run the tests to verify they pass**

Run: `npx jest src/app/reader/reader-shell.component.spec.ts`
Expected: PASS — the four new tests plus every pre-existing one.

- [ ] **Step 6: Commit**

```bash
npm run check
git add src/app/reader/subscriptions.store.ts src/app/reader/reader-shell.component.ts src/app/reader/reader-shell.component.spec.ts
git commit -m "feat(reader): state-driven onboarding redirect and first sweep (#99)"
```

---

### Task 22: Progress — hairline and banner

**Files:**
- Create: `frontend/src/app/shared/progress-hairline/progress-hairline.component.ts`
- Modify: `frontend/src/app/reader/reader-shell.component.html`
- Modify: `frontend/public/i18n/en.json`, `frontend/public/i18n/de.json`
- Test: `frontend/src/app/shared/progress-hairline/progress-hairline.component.spec.ts`

- [ ] **Step 1: Write the failing test**

`frontend/src/app/shared/progress-hairline/progress-hairline.component.spec.ts`:

```ts
import { ComponentFixture, TestBed } from '@angular/core/testing';
import { ProgressHairlineComponent } from './progress-hairline.component';

describe('ProgressHairlineComponent', () => {
  let fixture: ComponentFixture<ProgressHairlineComponent>;

  beforeEach(async () => {
    await TestBed.configureTestingModule({ imports: [ProgressHairlineComponent] }).compileComponents();
    fixture = TestBed.createComponent(ProgressHairlineComponent);
  });

  it('renders nothing when idle', () => {
    fixture.componentRef.setInput('active', false);
    fixture.componentRef.setInput('value', 0);
    fixture.detectChanges();

    expect(fixture.nativeElement.querySelector('.bar')).toBeNull();
  });

  it('exposes the progress to assistive technology', () => {
    fixture.componentRef.setInput('active', true);
    fixture.componentRef.setInput('value', 0.42);
    fixture.detectChanges();

    const bar = fixture.nativeElement.querySelector('.bar');
    expect(bar.getAttribute('role')).toBe('progressbar');
    expect(bar.getAttribute('aria-valuenow')).toBe('42');
  });
});
```

- [ ] **Step 2: Run it to confirm it fails**

Run: `npx jest src/app/shared/progress-hairline`
Expected: FAIL — cannot resolve `./progress-hairline.component`.

- [ ] **Step 3: Implement it**

`frontend/src/app/shared/progress-hairline/progress-hairline.component.ts`:

```ts
// src/app/shared/progress-hairline/progress-hairline.component.ts
import { ChangeDetectionStrategy, Component, computed, input } from '@angular/core';

/**
 * A 2px determinate bar under the header. Zero layout shift, so it can sit in
 * the reader permanently and upgrade EVERY refresh — not just the onboarding
 * sweep — from "an icon is spinning" to "this much of it is done".
 */
@Component({
  selector: 'app-progress-hairline',
  standalone: true,
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    @if (active()) {
      <div
        class="bar"
        role="progressbar"
        aria-valuemin="0"
        aria-valuemax="100"
        [attr.aria-valuenow]="percent()"
      >
        <span [style.width.%]="percent()"></span>
      </div>
    }
  `,
  styles: `
    .bar {
      height: 2px;
      background: var(--border);
    }
    span {
      display: block;
      height: 100%;
      background: var(--accent);
      transition: width 0.3s ease-out;
    }
  `,
})
export class ProgressHairlineComponent {
  readonly active = input.required<boolean>();
  /** 0..1, straight from RefreshService.progress(). */
  readonly value = input.required<number>();

  readonly percent = computed(() => Math.round(Math.min(1, Math.max(0, this.value())) * 100));
}
```

- [ ] **Step 4: Mount the hairline in the shell**

In `frontend/src/app/reader/reader-shell.component.html`, immediately after the header element, add:

```html
<app-progress-hairline [active]="refreshSvc.running()" [value]="refreshSvc.progress()" />
```

Import `ProgressHairlineComponent` in the shell's `imports` array.

- [ ] **Step 5: Add the onboarding banner**

Add the strings to `frontend/public/i18n/en.json` under `reader`:

```json
    "fetchingFeeds": "Fetching your feeds — {{done}} of {{total}}",
    "fetchFailed": "Some feeds could not be fetched.",
    "retryFetch": "Retry"
```

and to `de.json`:

```json
    "fetchingFeeds": "Feeds werden geladen – {{done}} von {{total}}",
    "fetchFailed": "Einige Feeds konnten nicht geladen werden.",
    "retryFetch": "Erneut versuchen"
```

In `reader-shell.component.ts`, add the banner state:

```ts
  /** The counted banner belongs to the post-onboarding sweep only. Every other
   *  refresh has the hairline, which is enough context for a user who already
   *  knows what their reader looks like. */
  readonly showFetchBanner = computed(
    () => this.sweptOnce && (this.refreshSvc.running() || this.refreshSvc.error() !== null),
  );

  readonly fetchProgress = computed(() => {
    const report = this.refreshSvc.report();
    if (!report) return { done: 0, total: 0 };
    return { done: report.total - report.remaining, total: report.total };
  });
```

`sweptOnce` must become a signal for this to be reactive — change the Task 21 declaration to `private readonly sweptOnce = signal(false);`, read it with `this.sweptOnce()` and set it with `this.sweptOnce.set(true)`.

In `reader-shell.component.html`, above the entry list:

```html
@if (showFetchBanner()) {
  <p class="fetch-banner" role="status" aria-live="polite">
    @if (refreshSvc.error()) {
      {{ 'reader.fetchFailed' | transloco }}
      <button type="button" (click)="onRefresh()">{{ 'reader.retryFetch' | transloco }}</button>
    } @else {
      {{ 'reader.fetchingFeeds' | transloco: fetchProgress() }}
    }
  </p>
}
```

And in `reader-shell.component.scss`:

```scss
.fetch-banner {
  display: flex;
  gap: var(--space-2);
  align-items: center;
  margin: 0;
  padding: var(--space-2) var(--space-4);
  border-bottom: 1px solid var(--border);
  background: var(--accent-soft);
  color: var(--text-primary);
  font-size: var(--fs-sm);

  button {
    border: 0;
    background: none;
    color: var(--accent);
    font-weight: 600;
    cursor: pointer;
  }
}
```

- [ ] **Step 6: Run the frontend suite**

Run: `npx jest src/app/shared/progress-hairline src/app/reader`
Expected: PASS.

- [ ] **Step 7: Run the gate and commit**

```bash
npm run check
git add src/app/shared/progress-hairline src/app/reader/reader-shell.component.html src/app/reader/reader-shell.component.scss src/app/reader/reader-shell.component.ts public/i18n/en.json public/i18n/de.json
git commit -m "feat(reader): refresh hairline and post-onboarding fetch banner (#99)"
```

---

### Task 23: Permanent links, and the admin's empty-catalog warning

Onboarding now goes quiet when the catalog is empty (Task 21). That is right for the user and dangerous for the admin: a deployment where nobody imported would silently never onboard anyone, with nothing anywhere saying so. This task adds the one thing that makes the silence safe — an admin-only warning.

**Files:**
- Modify: `frontend/src/app/settings/settings.component.html`
- Modify: `frontend/src/app/reader/add-feed/add-feed-dialog.component.html`
- Modify: `frontend/src/app/reader/entry-list/entry-list.component.html`
- Modify: `frontend/src/app/reader/reader-shell.component.ts/.html/.scss`
- Modify: `frontend/public/i18n/en.json`, `frontend/public/i18n/de.json`
- Test: `frontend/src/app/reader/reader-shell.component.spec.ts`

- [ ] **Step 1: Add the strings**

`en.json` under `discover`:

```json
    "browseCatalog": "Browse suggested feeds",
    "adminEmptyWarning": "No feed catalog has been imported, so new users are not being offered any suggestions.",
    "adminEmptyAction": "Import one"
```

`de.json` under `discover`:

```json
    "browseCatalog": "Feed-Vorschläge durchsuchen",
    "adminEmptyWarning": "Es wurde kein Feed-Katalog importiert – neue Nutzer bekommen deshalb keine Vorschläge.",
    "adminEmptyAction": "Katalog importieren"
```

- [ ] **Step 2: Add the links**

In each of the three templates, add a router link to `/discover` labelled `discover.browseCatalog`, following whatever link markup each file already uses:

```html
<a routerLink="/discover">{{ 'discover.browseCatalog' | transloco }}</a>
```

In the entry list this belongs in the existing empty-state block — a user who skipped must have a visible way back — but **hide it when the catalog is known to be empty**, since offering a link to nothing is worse than offering nothing:

```html
@if (!catalogEmpty()) {
  <a routerLink="/discover">{{ 'discover.browseCatalog' | transloco }}</a>
}
```

with, in the entry-list component:

```ts
  private readonly catalog = inject(CatalogStore);

  /** Only ever true after the shell has resolved the catalog, which it does
   *  exactly in the situation this empty state appears in. Unresolved reads as
   *  "not empty", so the link is never hidden on a guess. */
  readonly catalogEmpty = computed(() => this.catalog.resolved() && !this.catalog.hasEntries());
```

The Settings and Add-feed links stay unconditional — neither page loads the catalog, and making them do so to decide whether to draw a link is not worth a request. `/discover` explains itself when there is nothing there (Task 19).

Make sure `RouterLink` and `TranslocoPipe` are in each component's `imports`.

- [ ] **Step 3: Write the failing test for the admin warning**

Append to `frontend/src/app/reader/reader-shell.component.spec.ts`:

```ts
  it('warns an admin that no catalog has been imported', async () => {
    jest.spyOn(TestBed.inject(AuthService), 'isAdmin').mockReturnValue(true);

    flushSubscriptions({
      subscriptions: [{ ...SUBSCRIPTION_FIXTURE, id: 1, lastFetchedAt: '2026-07-26T10:00:00+00:00' }],
      favoritesCount: 0,
      keptCount: 0,
    });
    await fixture.whenStable();

    // An admin gets the catalog fetched even with subscriptions of their own —
    // otherwise nobody would ever be told onboarding is switched off.
    http.expectOne(`${BASE}/api/catalog`).flush({ categories: [] });
    fixture.detectChanges();

    const warning = fixture.nativeElement.querySelector('[data-testid="catalog-empty-warning"]');
    expect(warning).not.toBeNull();
    expect(warning.querySelector('a').getAttribute('href')).toBe('/admin/catalog');
  });

  it('shows an admin no warning once a catalog exists', async () => {
    jest.spyOn(TestBed.inject(AuthService), 'isAdmin').mockReturnValue(true);

    flushSubscriptions({
      subscriptions: [{ ...SUBSCRIPTION_FIXTURE, id: 1, lastFetchedAt: '2026-07-26T10:00:00+00:00' }],
      favoritesCount: 0,
      keptCount: 0,
    });
    await fixture.whenStable();

    http.expectOne(`${BASE}/api/catalog`).flush({
      categories: [
        {
          id: 1,
          key: 'technology',
          name: 'Technology',
          icon: 'memory',
          color: '#3b82f6',
          feeds: [
            { id: 10, title: 'The Verge', description: null, siteUrl: null, faviconUrl: '/f/10', subscribed: false },
          ],
        },
      ],
    });
    fixture.detectChanges();

    expect(fixture.nativeElement.querySelector('[data-testid="catalog-empty-warning"]')).toBeNull();
  });

  it('never shows the warning to a non-admin', async () => {
    jest.spyOn(TestBed.inject(AuthService), 'isAdmin').mockReturnValue(false);

    flushSubscriptions({ subscriptions: [], favoritesCount: 0, keptCount: 0 });
    await fixture.whenStable();

    http.expectOne(`${BASE}/api/catalog`).flush({ categories: [] });
    fixture.detectChanges();

    expect(fixture.nativeElement.querySelector('[data-testid="catalog-empty-warning"]')).toBeNull();
  });
```

Note the earlier test *"does not even ask for the catalog when the user has subscriptions"* — it must now assert that for a **non-admin**. If the harness does not already stub `isAdmin`, add `jest.spyOn(TestBed.inject(AuthService), 'isAdmin').mockReturnValue(false);` to it.

- [ ] **Step 4: Run it to confirm it fails**

Run: `npx jest src/app/reader/reader-shell.component.spec.ts`
Expected: FAIL — no `[data-testid="catalog-empty-warning"]` element.

- [ ] **Step 5: Add the warning to the shell**

In `frontend/src/app/reader/reader-shell.component.ts`:

```ts
  private readonly auth = inject(AuthService);

  /** Admins get the catalog resolved unconditionally — they are the only ones
   *  who can fix an empty one, and the suppressed onboarding is otherwise
   *  invisible. One cached request per session. */
  private readonly loadCatalogForAdmin = effect(() => {
    if (this.auth.isAdmin()) this.catalog.load();
  });

  readonly showCatalogEmptyWarning = computed(
    () => this.auth.isAdmin() && this.catalog.resolved() && !this.catalog.hasEntries(),
  );
```

`AuthService` is already injected in the shell — reuse the existing field rather than adding a second one.

In `frontend/src/app/reader/reader-shell.component.html`, directly under the progress hairline:

```html
@if (showCatalogEmptyWarning()) {
  <p class="catalog-warning" role="status" data-testid="catalog-empty-warning">
    {{ 'discover.adminEmptyWarning' | transloco }}
    <a routerLink="/admin/catalog">{{ 'discover.adminEmptyAction' | transloco }}</a>
  </p>
}
```

And in `frontend/src/app/reader/reader-shell.component.scss`:

```scss
.catalog-warning {
  display: flex;
  gap: var(--space-2);
  align-items: center;
  margin: 0;
  padding: var(--space-2) var(--space-4);
  border-bottom: 1px solid var(--border);
  background: var(--bg-danger);
  color: var(--danger);
  font-size: var(--fs-sm);

  a {
    color: inherit;
    font-weight: 600;
  }
}
```

- [ ] **Step 6: Run the tests to verify they pass**

Run: `npx jest src/app/reader/reader-shell.component.spec.ts`
Expected: PASS — the three new tests plus every pre-existing one.

- [ ] **Step 7: Verify and commit**

```bash
npm run check
git add src/app/settings src/app/reader/add-feed src/app/reader/entry-list src/app/reader/reader-shell.component.ts src/app/reader/reader-shell.component.html src/app/reader/reader-shell.component.scss src/app/reader/reader-shell.component.spec.ts public/i18n
git commit -m "feat(discover): catalog links and an admin warning when none is imported (#99)"
```

---

# Phase 6 — Admin

### Task 24: Admin catalog API

Access is enforced by `ROLE_ADMIN` on `^/api/admin/` in the firewall — see the note at the top of `AdminUserController`. No per-action attribute is needed.

**Files:**
- Create: `backend/src/Dto/Admin/CatalogCategoryRequest.php`
- Create: `backend/src/Dto/Admin/CatalogFeedRequest.php`
- Create: `backend/src/Dto/Admin/ReorderRequest.php`
- Create: `backend/src/Http/AdminCatalogJson.php`
- Create: `backend/src/Controller/Admin/AdminCatalogController.php`
- Test: `backend/tests/Controller/Admin/AdminCatalogControllerTest.php`

- [ ] **Step 1: Write the failing test**

`backend/tests/Controller/Admin/AdminCatalogControllerTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Controller\Admin;

use App\Entity\CatalogCategory;
use App\Entity\CatalogFeed;
use App\Service\Catalog\CatalogFaviconFetcher;
use App\Service\Catalog\Exception\FaviconUnavailableException;
use App\Service\Fetch\FaviconResolver;
use App\Tests\Support\UserFactory;
use Doctrine\ORM\EntityManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class AdminCatalogControllerTest extends WebTestCase
{
    /**
     * Make the favicon path hermetic: resolution hands back a canned URL and the
     * download of it always fails. Both the warm slice and the single-row refresh
     * then exercise their real wiring — the endpoint, the warmer, the failure
     * bookkeeping — without any test reaching the network.
     */
    private function stubFaviconServicesToFail(): void
    {
        $fetcher = $this->createMock(CatalogFaviconFetcher::class);
        $fetcher->method('download')->willThrowException(new FaviconUnavailableException('offline'));
        self::getContainer()->set(CatalogFaviconFetcher::class, $fetcher);

        $resolver = $this->createMock(FaviconResolver::class);
        $resolver->method('resolveAll')->willReturnCallback(
            static fn (array $bases): array => array_map(
                static fn (): string => 'https://example.com/favicon.ico',
                $bases,
            ),
        );
        self::getContainer()->set(FaviconResolver::class, $resolver);
    }

    /** @param list<string> $roles */
    private function authHeader(string $email, array $roles): array
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $hasher = self::getContainer()->get(UserPasswordHasherInterface::class);
        self::assertInstanceOf(UserPasswordHasherInterface::class, $hasher);

        $user = (new UserFactory($em, $hasher))->create($email, roles: $roles);

        $tokens = self::getContainer()->get(JWTTokenManagerInterface::class);
        self::assertInstanceOf(JWTTokenManagerInterface::class, $tokens);

        return [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $tokens->create($user),
            'CONTENT_TYPE' => 'application/json',
        ];
    }

    public function testANonAdminIsRefused(): void
    {
        $client = self::createClient();
        $client->request('GET', '/api/admin/catalog', server: $this->authHeader('plain@example.com', []));
        self::assertResponseStatusCodeSame(403);
    }

    public function testAdminCanCreateUpdateAndDeleteACategory(): void
    {
        $client = self::createClient();
        $headers = $this->authHeader('admin@example.com', ['ROLE_ADMIN']);

        $client->request(
            'POST',
            '/api/admin/catalog/categories',
            server: $headers,
            content: json_encode(
                ['key' => 'technology', 'name' => 'Technology', 'icon' => 'memory', 'color' => '#3b82f6', 'enabled' => true],
                \JSON_THROW_ON_ERROR,
            ),
        );
        self::assertResponseStatusCodeSame(201);
        $created = json_decode((string) $client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);
        $id = $created['category']['id'];

        $client->request(
            'PATCH',
            '/api/admin/catalog/categories/' . $id,
            server: $headers,
            content: json_encode(
                ['key' => 'technology', 'name' => 'Tech', 'icon' => 'memory', 'color' => '#3b82f6', 'enabled' => false],
                \JSON_THROW_ON_ERROR,
            ),
        );
        self::assertResponseIsSuccessful();

        $client->request('DELETE', '/api/admin/catalog/categories/' . $id, server: $headers);
        self::assertResponseStatusCodeSame(204);
    }

    public function testAdminCanCreateAFeedAndRefreshItsFavicon(): void
    {
        $client = self::createClient();
        $headers = $this->authHeader('admin2@example.com', ['ROLE_ADMIN']);

        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $category = new CatalogCategory('science', 'Science', 'science', '#14b8a6');
        $em->persist($category);
        $em->flush();

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

        $feed = $em->getRepository(CatalogFeed::class)->findOneBy(['title' => 'Quanta Magazine']);
        self::assertNotNull($feed);

        // The refresh action must answer even when the download fails — a dead
        // icon is a recorded failure, not a 500.
        $this->stubFaviconServicesToFail();
        $client->request('POST', '/api/admin/catalog/feeds/' . $feed->getId() . '/favicon', server: $headers);
        self::assertResponseIsSuccessful();
    }

    public function testWarmingReportsASliceAndWhatIsLeft(): void
    {
        $client = self::createClient();
        $headers = $this->authHeader('warm@example.com', ['ROLE_ADMIN']);

        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $category = new CatalogCategory('technology', 'Technology', 'memory', '#3b82f6');
        $feed = new CatalogFeed($category, 'The Verge', 'https://www.theverge.com/rss/index.xml');
        $em->persist($category);
        $em->persist($feed);
        $em->flush();

        // The favicon services are stubbed: this asserts the endpoint's contract,
        // not that the internet is reachable from CI.
        $this->stubFaviconServicesToFail();

        $client->request('POST', '/api/admin/catalog/favicons/warm', server: $headers);

        self::assertResponseIsSuccessful();
        $body = json_decode((string) $client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);

        self::assertSame(0, $body['warmed']);
        self::assertSame(1, $body['failed']);
        self::assertArrayHasKey('remaining', $body);
    }

    public function testReorderingRewritesPositions(): void
    {
        $client = self::createClient();
        $headers = $this->authHeader('admin3@example.com', ['ROLE_ADMIN']);

        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);

        $first = new CatalogCategory('a', 'A', 'memory', '#111111');
        $first->setPosition(0);
        $second = new CatalogCategory('b', 'B', 'memory', '#222222');
        $second->setPosition(1);
        $em->persist($first);
        $em->persist($second);
        $em->flush();

        $client->request(
            'PATCH',
            '/api/admin/catalog/categories/reorder',
            server: $headers,
            content: json_encode(['ids' => [$second->getId(), $first->getId()]], \JSON_THROW_ON_ERROR),
        );

        self::assertResponseIsSuccessful();
        $em->clear();
        $reloaded = $em->getRepository(CatalogCategory::class)->findOneBy(['key' => 'b']);
        self::assertNotNull($reloaded);
        self::assertSame(0, $reloaded->getPosition());
    }
}
```

- [ ] **Step 2: Run it to confirm it fails**

Run: `php bin/phpunit tests/Controller/Admin/AdminCatalogControllerTest.php`
Expected: FAIL — 404 on the admin catalog routes.

- [ ] **Step 3: Write the DTOs**

`backend/src/Dto/Admin/CatalogCategoryRequest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Dto\Admin;

use Symfony\Component\Validator\Constraints as Assert;

final readonly class CatalogCategoryRequest
{
    public function __construct(
        #[Assert\NotBlank]
        #[Assert\Length(max: 64)]
        #[Assert\Regex(pattern: '/^[a-z0-9_]+$/', message: 'Key must be a lowercase slug.')]
        public string $key = '',
        #[Assert\NotBlank]
        #[Assert\Length(max: 100)]
        public string $name = '',
        #[Assert\NotBlank]
        #[Assert\Length(max: 64)]
        #[Assert\Regex(pattern: '/^[a-z0-9_]+$/', message: 'Icon must be a Material Symbol name.')]
        public string $icon = '',
        #[Assert\Regex(pattern: '/^#[0-9a-fA-F]{6}$/', message: 'Color must be a hex value like #ff8800.')]
        public string $color = '#000000',
        public bool $enabled = true,
        /** Locked rows are the admin's: an import will neither overwrite nor delete them. */
        public bool $locked = true,
    ) {
    }
}
```

`backend/src/Dto/Admin/CatalogFeedRequest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Dto\Admin;

use App\Enum\SourceFormat;
use Symfony\Component\Validator\Constraints as Assert;

final readonly class CatalogFeedRequest
{
    public function __construct(
        #[Assert\Positive]
        public int $categoryId = 0,
        #[Assert\NotBlank]
        #[Assert\Length(max: 200)]
        public string $title = '',
        #[Assert\NotBlank]
        #[Assert\Url(requireTld: true)]
        #[Assert\Length(max: 750)]
        public string $url = '',
        #[Assert\Url(requireTld: true)]
        #[Assert\Length(max: 750)]
        public ?string $siteUrl = null,
        #[Assert\Length(max: 255)]
        public ?string $description = null,
        #[Assert\Choice([SourceFormat::XML, SourceFormat::SCRAPED])]
        public string $sourceFormat = SourceFormat::XML,
        public bool $enabled = true,
        /** Locked rows are the admin's: an import will neither overwrite nor delete them. */
        public bool $locked = true,
    ) {
    }
}
```

`backend/src/Dto/Admin/ReorderRequest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Dto\Admin;

use Symfony\Component\Validator\Constraints as Assert;

final readonly class ReorderRequest
{
    /**
     * @param list<int> $ids
     */
    public function __construct(
        #[Assert\Count(min: 1)]
        #[Assert\All([new Assert\Type('integer'), new Assert\Positive()])]
        public array $ids = [],
    ) {
    }
}
```

- [ ] **Step 4: Write the serialiser**

`backend/src/Http/AdminCatalogJson.php`:

```php
<?php

declare(strict_types=1);

namespace App\Http;

use App\Entity\CatalogCategory;
use App\Entity\CatalogFeed;

/**
 * The admin view of the catalog: every row, enabled or not, plus the favicon
 * bookkeeping an admin needs to see to know whether an icon is missing because
 * it has not been warmed or because it keeps failing.
 */
final class AdminCatalogJson
{
    /**
     * @return array<string, mixed>
     */
    public static function category(CatalogCategory $category): array
    {
        return [
            'id' => $category->getId(),
            'key' => $category->getKey(),
            'name' => $category->getName(),
            'icon' => $category->getIcon(),
            'color' => $category->getColor(),
            'position' => $category->getPosition(),
            'enabled' => $category->isEnabled(),
            'locked' => $category->isLocked(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function feed(CatalogFeed $feed): array
    {
        return [
            'id' => $feed->getId(),
            'categoryId' => $feed->getCategory()->getId(),
            'title' => $feed->getTitle(),
            'url' => $feed->getUrl(),
            'siteUrl' => $feed->getSiteUrl(),
            'description' => $feed->getDescription(),
            'sourceFormat' => $feed->getSourceFormat(),
            'position' => $feed->getPosition(),
            'enabled' => $feed->isEnabled(),
            'locked' => $feed->isLocked(),
            'faviconFetchedAt' => $feed->getFaviconFetchedAt()?->format(\DATE_ATOM),
            'faviconFailedAt' => $feed->getFaviconFailedAt()?->format(\DATE_ATOM),
        ];
    }
}
```

- [ ] **Step 5: Write the controller**

`backend/src/Controller/Admin/AdminCatalogController.php`:

```php
<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Dto\Admin\CatalogCategoryRequest;
use App\Dto\Admin\CatalogFeedRequest;
use App\Dto\Admin\ReorderRequest;
use App\Entity\CatalogCategory;
use App\Entity\CatalogFeed;
use App\Http\AdminCatalogJson;
use App\Repository\CatalogCategoryRepository;
use App\Repository\CatalogFeedRepository;
use App\Service\Catalog\CatalogFaviconWarmer;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Catalog administration. Access is enforced by ROLE_ADMIN on ^/api/admin/ in
 * the firewall, consistent with AdminUserController.
 */
/**
 * Note the `locked` default in the request DTOs: a row an admin creates BY HAND
 * is locked unless they say otherwise. They meant to add it, and a later
 * `replace` import should not quietly take it away again. Rows created by an
 * import are unlocked, because the document already owns them.
 */
#[Route('/api/admin/catalog')]
final class AdminCatalogController
{
    /** Comfortably inside any sane PHP max_execution_time, and long enough that
     *  111 icons take a handful of polls rather than dozens. */
    private const int WARM_BUDGET_SECONDS = 15;

    public function __construct(
        private readonly CatalogCategoryRepository $categories,
        private readonly CatalogFeedRepository $feeds,
        private readonly CatalogFaviconWarmer $warmer,
        private readonly EntityManagerInterface $em,
    ) {
    }

    #[Route('', name: 'api_admin_catalog_list', methods: ['GET'])]
    public function list(): JsonResponse
    {
        return new JsonResponse([
            'categories' => array_map(
                static fn (CatalogCategory $c) => AdminCatalogJson::category($c),
                $this->categories->findAllOrdered(),
            ),
            'feeds' => array_map(
                static fn (CatalogFeed $f) => AdminCatalogJson::feed($f),
                $this->feeds->findBy([], ['position' => 'ASC', 'title' => 'ASC']),
            ),
        ]);
    }

    #[Route('/categories', name: 'api_admin_catalog_category_create', methods: ['POST'])]
    public function createCategory(#[MapRequestPayload] CatalogCategoryRequest $request): JsonResponse
    {
        $category = new CatalogCategory($request->key, $request->name, $request->icon, $request->color);
        $category->setEnabled($request->enabled);
        $category->setLocked($request->locked);
        $category->setPosition($this->categories->nextPosition());
        $this->em->persist($category);
        $this->em->flush();

        return new JsonResponse(
            ['category' => AdminCatalogJson::category($category)],
            Response::HTTP_CREATED,
        );
    }

    #[Route('/categories/reorder', name: 'api_admin_catalog_category_reorder', methods: ['PATCH'])]
    public function reorderCategories(#[MapRequestPayload] ReorderRequest $request): JsonResponse
    {
        foreach ($request->ids as $index => $id) {
            $category = $this->categories->find($id) ?? throw new NotFoundHttpException('No such category.');
            $category->setPosition($index);
        }
        $this->em->flush();

        return new JsonResponse(null, Response::HTTP_NO_CONTENT);
    }

    #[Route('/categories/{id}', name: 'api_admin_catalog_category_update', methods: ['PATCH'], requirements: ['id' => '\d+'])]
    public function updateCategory(int $id, #[MapRequestPayload] CatalogCategoryRequest $request): JsonResponse
    {
        $category = $this->categories->find($id) ?? throw new NotFoundHttpException('No such category.');
        $category->setName($request->name);
        $category->setIcon($request->icon);
        $category->setColor($request->color);
        $category->setEnabled($request->enabled);
        $category->setLocked($request->locked);
        $this->em->flush();

        return new JsonResponse(['category' => AdminCatalogJson::category($category)]);
    }

    #[Route('/categories/{id}', name: 'api_admin_catalog_category_delete', methods: ['DELETE'], requirements: ['id' => '\d+'])]
    public function deleteCategory(int $id): JsonResponse
    {
        $category = $this->categories->find($id) ?? throw new NotFoundHttpException('No such category.');
        // Its feeds go with it via the FK's ON DELETE CASCADE. Subscriptions a
        // user already made are untouched: they are Feed rows, not catalog rows.
        $this->em->remove($category);
        $this->em->flush();

        return new JsonResponse(null, Response::HTTP_NO_CONTENT);
    }

    #[Route('/feeds', name: 'api_admin_catalog_feed_create', methods: ['POST'])]
    public function createFeed(#[MapRequestPayload] CatalogFeedRequest $request): JsonResponse
    {
        $category = $this->categories->find($request->categoryId)
            ?? throw new NotFoundHttpException('No such category.');

        $feed = new CatalogFeed($category, $request->title, $request->url);
        $this->applyFeed($feed, $request);
        $feed->setPosition($this->feeds->nextPositionInCategory((int) $category->getId()));
        $this->em->persist($feed);
        $this->em->flush();

        return new JsonResponse(['feed' => AdminCatalogJson::feed($feed)], Response::HTTP_CREATED);
    }

    #[Route('/feeds/reorder', name: 'api_admin_catalog_feed_reorder', methods: ['PATCH'])]
    public function reorderFeeds(#[MapRequestPayload] ReorderRequest $request): JsonResponse
    {
        foreach ($request->ids as $index => $id) {
            $feed = $this->feeds->find($id) ?? throw new NotFoundHttpException('No such feed.');
            $feed->setPosition($index);
        }
        $this->em->flush();

        return new JsonResponse(null, Response::HTTP_NO_CONTENT);
    }

    #[Route('/feeds/{id}', name: 'api_admin_catalog_feed_update', methods: ['PATCH'], requirements: ['id' => '\d+'])]
    public function updateFeed(int $id, #[MapRequestPayload] CatalogFeedRequest $request): JsonResponse
    {
        $feed = $this->feeds->find($id) ?? throw new NotFoundHttpException('No such feed.');
        $category = $this->categories->find($request->categoryId)
            ?? throw new NotFoundHttpException('No such category.');

        $feed->setCategory($category);
        $feed->setTitle($request->title);
        $feed->setUrl($request->url);
        $this->applyFeed($feed, $request);
        $this->em->flush();

        return new JsonResponse(['feed' => AdminCatalogJson::feed($feed)]);
    }

    #[Route('/feeds/{id}', name: 'api_admin_catalog_feed_delete', methods: ['DELETE'], requirements: ['id' => '\d+'])]
    public function deleteFeed(int $id): JsonResponse
    {
        $feed = $this->feeds->find($id) ?? throw new NotFoundHttpException('No such feed.');
        $this->em->remove($feed);
        $this->em->flush();

        return new JsonResponse(null, Response::HTTP_NO_CONTENT);
    }

    /**
     * One budgeted slice of favicon warming. The admin UI polls this until
     * `remaining` reaches 0 — the same contract /api/refresh uses, and for the
     * same reason: 111 publisher round trips cannot fit in one request.
     *
     * This is what makes icons a property of the app rather than of one
     * deployment: an install that never runs a console command still gets them.
     */
    #[Route('/favicons/warm', name: 'api_admin_catalog_warm_favicons', methods: ['POST'])]
    public function warmFavicons(): JsonResponse
    {
        $report = $this->warmer->warm(self::WARM_BUDGET_SECONDS);

        return new JsonResponse([
            'warmed' => $report->warmed,
            'failed' => $report->failed,
            'remaining' => $report->remaining,
        ]);
    }

    /**
     * Re-fetch one row's icon, for a publisher that changed its icon.
     * A failed fetch is a recorded failure and a 200 — the same outcome the warm
     * command produces — not a 500.
     */
    #[Route('/feeds/{id}/favicon', name: 'api_admin_catalog_feed_favicon', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function refreshFavicon(int $id): JsonResponse
    {
        $feed = $this->feeds->find($id) ?? throw new NotFoundHttpException('No such feed.');

        // The warmer resolves, downloads, records the outcome and flushes. A dead
        // icon is a recorded failure, not a 500 — so refresh() never throws here.
        $this->warmer->refresh($feed);

        return new JsonResponse(['feed' => AdminCatalogJson::feed($feed)]);
    }

    private function applyFeed(CatalogFeed $feed, CatalogFeedRequest $request): void
    {
        $feed->setSiteUrl($request->siteUrl);
        $feed->setDescription($request->description);
        $feed->setSourceFormat($request->sourceFormat);
        $feed->setEnabled($request->enabled);
        $feed->setLocked($request->locked);
    }
}
```

- [ ] **Step 6: Run the tests to verify they pass**

Run: `php bin/phpunit tests/Controller/Admin/AdminCatalogControllerTest.php`
Expected: PASS, 4 tests.

- [ ] **Step 7: Run the gate and commit**

```bash
composer check && composer md && php bin/phpunit
git add src/Dto/Admin src/Http/AdminCatalogJson.php src/Controller/Admin/AdminCatalogController.php tests/Controller/Admin/AdminCatalogControllerTest.php
git commit -m "feat(admin): catalog CRUD, reorder and favicon refresh (#99)"
```

---

### Task 25: Admin catalog UI

**Files:**
- Create: `frontend/src/app/admin/admin-catalog.component.ts`
- Create: `frontend/src/app/admin/admin-catalog.component.html`
- Create: `frontend/src/app/admin/admin-catalog.component.scss`
- Modify: `frontend/src/app/admin/admin-api.ts`
- Modify: `frontend/src/app/admin/admin.models.ts`
- Modify: `frontend/src/app/app.routes.ts`
- Test: `frontend/src/app/admin/admin-catalog.component.spec.ts`

- [ ] **Step 1: Add the models**

Append to `frontend/src/app/admin/admin.models.ts`:

```ts
export interface AdminCatalogCategoryDto {
  id: number;
  key: string;
  name: string;
  icon: string;
  color: string;
  position: number;
  enabled: boolean;
  /** Locked rows survive an import untouched — neither overwritten nor deleted. */
  locked: boolean;
}

export interface BundledCatalogInfo {
  available: boolean;
  categories: number;
  feeds: number;
}

export interface CatalogWarmReport {
  warmed: number;
  failed: number;
  remaining: number;
}

export interface CatalogImportCounts {
  categoriesCreated: number;
  categoriesUpdated: number;
  categoriesRemoved: number;
  feedsCreated: number;
  feedsUpdated: number;
  feedsRemoved: number;
  lockedSkipped: number;
}

export interface AdminCatalogFeedDto {
  id: number;
  categoryId: number;
  title: string;
  url: string;
  siteUrl: string | null;
  description: string | null;
  sourceFormat: string;
  position: number;
  enabled: boolean;
  /** Locked rows survive an import untouched — neither overwritten nor deleted. */
  locked: boolean;
  faviconFetchedAt: string | null;
  faviconFailedAt: string | null;
}
```

- [ ] **Step 2: Extend the API client**

Append to the `AdminApi` class in `frontend/src/app/admin/admin-api.ts`:

```ts
  catalog(): Observable<{
    categories: AdminCatalogCategoryDto[];
    feeds: AdminCatalogFeedDto[];
  }> {
    return this.http.get<{ categories: AdminCatalogCategoryDto[]; feeds: AdminCatalogFeedDto[] }>(
      `${this.base}/api/admin/catalog`,
    );
  }

  saveCategory(
    id: number | null,
    body: Omit<AdminCatalogCategoryDto, 'id' | 'position'>,
  ): Observable<{ category: AdminCatalogCategoryDto }> {
    const url = `${this.base}/api/admin/catalog/categories${id === null ? '' : `/${id}`}`;
    return id === null
      ? this.http.post<{ category: AdminCatalogCategoryDto }>(url, body)
      : this.http.patch<{ category: AdminCatalogCategoryDto }>(url, body);
  }

  deleteCategory(id: number): Observable<void> {
    return this.http.delete<void>(`${this.base}/api/admin/catalog/categories/${id}`);
  }

  reorderCategories(ids: number[]): Observable<void> {
    return this.http.patch<void>(`${this.base}/api/admin/catalog/categories/reorder`, { ids });
  }

  saveFeed(
    id: number | null,
    body: Omit<AdminCatalogFeedDto, 'id' | 'position' | 'faviconFetchedAt' | 'faviconFailedAt'>,
  ): Observable<{ feed: AdminCatalogFeedDto }> {
    const url = `${this.base}/api/admin/catalog/feeds${id === null ? '' : `/${id}`}`;
    return id === null
      ? this.http.post<{ feed: AdminCatalogFeedDto }>(url, body)
      : this.http.patch<{ feed: AdminCatalogFeedDto }>(url, body);
  }

  deleteFeed(id: number): Observable<void> {
    return this.http.delete<void>(`${this.base}/api/admin/catalog/feeds/${id}`);
  }

  reorderFeeds(ids: number[]): Observable<void> {
    return this.http.patch<void>(`${this.base}/api/admin/catalog/feeds/reorder`, { ids });
  }

  refreshFavicon(id: number): Observable<{ feed: AdminCatalogFeedDto }> {
    return this.http.post<{ feed: AdminCatalogFeedDto }>(
      `${this.base}/api/admin/catalog/feeds/${id}/favicon`,
      {},
    );
  }

  /** The OPML text rides inside an ordinary JSON body rather than as a multipart
   *  upload, which keeps the admin API pure JSON like the rest of the app. */
  importCatalog(
    mode: 'merge' | 'replace',
    document: string,
  ): Observable<CatalogImportCounts> {
    return this.http.post<CatalogImportCounts>(`${this.base}/api/admin/catalog/import`, {
      mode,
      document,
    });
  }

  /** What the shipped document would import, so the button can name real
   *  numbers. Does not import anything. */
  bundledCatalog(): Observable<BundledCatalogInfo> {
    return this.http.get<BundledCatalogInfo>(`${this.base}/api/admin/catalog/bundled`);
  }

  /** Import the document this release ships. No file travels — it is already on
   *  the server. */
  importBundledCatalog(mode: 'merge' | 'replace'): Observable<CatalogImportCounts> {
    return this.http.post<CatalogImportCounts>(`${this.base}/api/admin/catalog/import/bundled`, {
      mode,
    });
  }

  /** One budgeted slice of favicon warming. Poll until `remaining` is 0. */
  warmFavicons(): Observable<CatalogWarmReport> {
    return this.http.post<CatalogWarmReport>(`${this.base}/api/admin/catalog/favicons/warm`, {});
  }
```

Add the two new types to the file's imports from `./admin.models`.

- [ ] **Step 3: Write the failing component test**

`frontend/src/app/admin/admin-catalog.component.spec.ts`:

```ts
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { ComponentFixture, TestBed } from '@angular/core/testing';
import { provideHttpClient } from '@angular/common/http';
import { getTranslocoModule } from '../../testing/transloco-testing';
import { API_BASE_URL } from '../core/api';
import { AdminCatalogComponent } from './admin-catalog.component';

const PAYLOAD = {
  categories: [
    { id: 1, key: 'technology', name: 'Technology', icon: 'memory', color: '#3b82f6', position: 0, enabled: true, locked: false },
  ],
  feeds: [
    {
      id: 10,
      categoryId: 1,
      title: 'The Verge',
      url: 'https://www.theverge.com/rss/index.xml',
      siteUrl: null,
      description: null,
      sourceFormat: 'xml',
      position: 0,
      enabled: true,
      locked: false,
      faviconFetchedAt: null,
      faviconFailedAt: null,
    },
  ],
};

describe('AdminCatalogComponent', () => {
  let fixture: ComponentFixture<AdminCatalogComponent>;
  let http: HttpTestingController;

  beforeEach(async () => {
    await TestBed.configureTestingModule({
      imports: [AdminCatalogComponent, getTranslocoModule()],
      providers: [
        provideHttpClient(),
        provideHttpClientTesting(),
        { provide: API_BASE_URL, useValue: 'https://api.test' },
      ],
    }).compileComponents();

    fixture = TestBed.createComponent(AdminCatalogComponent);
    http = TestBed.inject(HttpTestingController);
    fixture.detectChanges();
    http.expectOne('https://api.test/api/admin/catalog').flush(PAYLOAD);
    http
      .expectOne('https://api.test/api/admin/catalog/bundled')
      .flush({ available: true, categories: 13, feeds: 111 });
    fixture.detectChanges();
  });

  afterEach(() => http.verify());

  it('lists categories and their feeds', () => {
    expect(fixture.nativeElement.querySelectorAll('[data-testid="admin-category"]')).toHaveLength(1);
    expect(fixture.nativeElement.querySelectorAll('[data-testid="admin-feed"]')).toHaveLength(1);
  });

  it('refreshes a favicon on demand', () => {
    fixture.nativeElement.querySelector('[data-testid="refresh-favicon"]').click();

    const req = http.expectOne('https://api.test/api/admin/catalog/feeds/10/favicon');
    expect(req.request.method).toBe('POST');
    req.flush({ feed: { ...PAYLOAD.feeds[0], faviconFetchedAt: '2026-07-26T10:00:00+00:00' } });
  });

  it('marks a locked feed as such and can toggle it', () => {
    const row = fixture.nativeElement.querySelector('[data-testid="admin-feed"]');
    const lock: HTMLInputElement = row.querySelector('[data-testid="feed-locked"]');
    expect(lock.checked).toBe(false);

    lock.click();
    row.querySelector('[data-testid="feed-save"]').click();

    const req = http.expectOne('https://api.test/api/admin/catalog/feeds/10');
    expect(req.request.method).toBe('PATCH');
    expect(req.request.body.locked).toBe(true);
    req.flush({ feed: { ...PAYLOAD.feeds[0], locked: true } });
  });

  it('imports the bundled document without transferring a file', () => {
    const button: HTMLButtonElement = fixture.nativeElement.querySelector(
      '[data-testid="import-bundled"]',
    );
    expect(button.textContent).toContain('111');

    button.click();

    const req = http.expectOne('https://api.test/api/admin/catalog/import/bundled');
    expect(req.request.body).toEqual({ mode: 'merge' });
    req.flush({
      categoriesCreated: 13,
      categoriesUpdated: 0,
      categoriesRemoved: 0,
      feedsCreated: 111,
      feedsUpdated: 0,
      feedsRemoved: 0,
      lockedSkipped: 0,
    });

    http.expectOne('https://api.test/api/admin/catalog').flush(PAYLOAD);

    // A freshly imported catalog has no icons, so warming starts on its own —
    // this is what makes icons work without any deployment-specific step.
    http
      .expectOne('https://api.test/api/admin/catalog/favicons/warm')
      .flush({ warmed: 25, failed: 0, remaining: 86 });
    http
      .expectOne('https://api.test/api/admin/catalog/favicons/warm')
      .flush({ warmed: 86, failed: 0, remaining: 0 });
  });

  it('posts a chosen document with the selected mode and reloads afterwards', async () => {
    const document =
      '<opml version="2.0"><head/><body>' +
      '<outline text="Technology" key="technology" icon="memory" color="#3b82f6"/>' +
      '</body></opml>';
    const file = new File([document], 'catalog.opml', { type: 'text/x-opml' });

    const input: HTMLInputElement = fixture.nativeElement.querySelector(
      '[data-testid="import-file"]',
    );
    Object.defineProperty(input, 'files', { value: [file] });
    input.dispatchEvent(new Event('change'));
    await fixture.whenStable();

    const mode: HTMLSelectElement = fixture.nativeElement.querySelector(
      '[data-testid="import-mode"]',
    );
    mode.value = 'replace';
    mode.dispatchEvent(new Event('change'));
    fixture.detectChanges();

    fixture.nativeElement.querySelector('[data-testid="import-run"]').click();

    const req = http.expectOne('https://api.test/api/admin/catalog/import');
    expect(req.request.body).toEqual({ mode: 'replace', document });
    req.flush({
      categoriesCreated: 0,
      categoriesUpdated: 0,
      categoriesRemoved: 1,
      feedsCreated: 0,
      feedsUpdated: 0,
      feedsRemoved: 1,
      lockedSkipped: 0,
    });

    // The lists are stale after an import, so the component refetches them.
    http.expectOne('https://api.test/api/admin/catalog').flush(PAYLOAD);
  });
});
```

- [ ] **Step 4: Run it to confirm it fails**

Run: `npx jest src/app/admin/admin-catalog.component.spec.ts`
Expected: FAIL — cannot resolve `./admin-catalog.component`.

- [ ] **Step 5: Build the component**

Follow `frontend/src/app/admin/admin-users.component.ts` for structure, styling and error handling. The component needs:

- signals `categories`, `feeds`, `loading`, `error`, loaded in the constructor via `AdminApi.catalog()`
- an **empty-catalog notice** at the very top, shown when the loaded catalog has no feeds: the same message the reader shows admins, with the bundled-import button below it as the obvious next step. This page is where the reader's warning links to, so landing here must immediately offer the fix.
- a **bundled import** control: on load, call `AdminApi.bundledCatalog()` and, when `available`, offer a button reading "Import the bundled catalog (13 categories, 111 feeds)" using the real counts from the response. Clicking it posts only the mode to `AdminApi.importBundledCatalog()` — no file is transferred, because the document is already on the server. Hide the control entirely when `available` is false.
- an **upload import** control beneath it, for a document from elsewhere: a file input accepting `.opml,.xml`, plus the shared mode selector. Read the chosen file with `File.text()` and post `{ mode, document }` — the OPML text verbatim — to `AdminApi.importCatalog()`. The browser does not parse it: OPML validation lives on the server, in one place, and a 422 names the offending outline.
- a **mode selector** (`merge` / `replace`) shared by both, defaulting to `merge`. `replace` deletes rows the document omits, so put that consequence in the label rather than behind a tooltip — along with the reassurance that locked rows are exempt.
- After either import: show the counts the endpoint returned — including `lockedSkipped`, so a lock that did something says so — reload the lists, and **start warming icons automatically** (below). On a 422 show the message from the problem body — it names the offending outline, and nothing was written.
- an **icon warming** control and progress line. `AdminApi.warmFavicons()` returns `{ warmed, failed, remaining }`; call it in a loop until `remaining` reaches 0, rendering "warming icons — N left" while it runs. Start it automatically after a successful import, since a freshly imported catalog has no icons at all and that is the moment they are wanted; also offer it as a standalone button for a later top-up. Stop the loop if a slice reports `warmed === 0 && failed === 0` — the work is not progressing, and spinning would hide that.

This loop is the reason favicons work on **every** deployment. Nothing else has to run: no cron, no deploy hook, no shell access.
- a **lock toggle** on every category and feed row, with a lock glyph shown on locked rows so the state is visible at a glance rather than only inside an edit form. The label must say what it does — a locked row survives an import untouched, neither overwritten nor deleted — because that is not guessable from the word alone.
- a category list, each row rendering `[data-testid="admin-category"]` with inline edit fields for name, icon, colour, enabled and locked, plus save and delete
- feeds grouped under their category, each rendering `[data-testid="admin-feed"]` with edit fields for title, url, siteUrl, description, category, enabled and locked, plus a `[data-testid="refresh-favicon"]` button calling `refreshFavicon(feed.id)` and writing the response back into the `feeds` signal
- reorder controls (move up / move down) that reorder the local array and post the resulting id order to `reorderCategories` / `reorderFeeds`
- an "Add category" and "Add feed" form using the same fields with `id = null`

Colours come from the API as `#rrggbb` and must be bound with `[style.background]`, never written into the `.scss` — Stylelint `color-no-hex` forbids hex outside `src/app/theme/`.

- [ ] **Step 6: Register the route**

In `frontend/src/app/app.routes.ts`, beside `admin/users`:

```ts
  {
    path: 'admin/catalog',
    canActivate: [authGuard, adminGuard],
    loadComponent: () =>
      import('./admin/admin-catalog.component').then((m) => m.AdminCatalogComponent),
  },
```

Add a link to it from wherever `admin/users` is linked in the reader header or settings, labelled from a new `admin.catalog` translation key in both `en.json` and `de.json`. The panel also needs, under `admin`:

```json
    "catalog": "Feed catalog",
    "catalogEmpty": "This catalog is empty, so new users are not being offered any suggestions.",
    "importBundled": "Import the bundled catalog ({{categories}} categories, {{feeds}} feeds)",
    "importUpload": "Import a catalog file",
    "importMode": "Mode",
    "importModeMerge": "Merge — add and update, keep everything else",
    "importModeReplace": "Replace — also delete entries this file does not list (locked entries are kept)",
    "locked": "Locked",
    "lockedHint": "Locked entries survive an import untouched — neither overwritten nor deleted.",
    "importLockedSkipped": "{{count}} locked entries left untouched",
    "importDone": "Categories +{{categoriesCreated}} ~{{categoriesUpdated}} −{{categoriesRemoved}}, feeds +{{feedsCreated}} ~{{feedsUpdated}} −{{feedsRemoved}}",
    "warmIcons": "Fetch missing icons",
    "warmingIcons": "Fetching icons — {{remaining}} left",
    "warmDone": "Icons: {{warmed}} fetched, {{failed}} unavailable"
```

with German equivalents.

- [ ] **Step 7: Run the tests and commit**

```bash
npx jest src/app/admin
npm run check
git add src/app/admin src/app/app.routes.ts public/i18n
git commit -m "feat(admin): feed catalog administration UI (#99)"
```

---

# Phase 7 — Rot check and end-to-end

### Task 26: Scheduled catalog rot check

Deliberately **not** a PR gate: a merge check that fetches 111 publisher domains will be red for reasons unrelated to the PR — rate limits, bot blocks, transient outages. Five candidates were already dropped during curation for exactly these reasons.

**Files:**
- Create: `backend/src/Command/CheckCatalogUrlsCommand.php`
- Create: `.github/workflows/catalog-rot-check.yml`
- Test: `backend/tests/Command/CheckCatalogUrlsCommandTest.php`

- [ ] **Step 1: Write the failing test**

`backend/tests/Command/CheckCatalogUrlsCommandTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Command\CheckCatalogUrlsCommand;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class CheckCatalogUrlsCommandTest extends KernelTestCase
{
    private function tester(MockHttpClient $client): CommandTester
    {
        self::bootKernel();
        self::getContainer()->set('catalog.rot_check.http_client', $client);
        $application = new Application(self::$kernel);

        return new CommandTester($application->find('app:catalog:check-urls'));
    }

    public function testExitsNonZeroWhenAUrlDoesNotServeAFeed(): void
    {
        $client = new MockHttpClient(static fn (): MockResponse => new MockResponse(
            '<html><body>not a feed</body></html>',
            ['response_headers' => ['content-type' => ['text/html']]],
        ));

        $tester = $this->tester($client);
        $tester->execute(['--limit' => '1']);

        self::assertSame(1, $tester->getStatusCode());
        self::assertStringContainsString('not a feed', $tester->getDisplay());
    }

    public function testExitsZeroWhenEveryCheckedUrlServesAFeed(): void
    {
        $client = new MockHttpClient(static fn (): MockResponse => new MockResponse(
            '<?xml version="1.0"?><rss version="2.0"><channel><title>x</title></channel></rss>',
            ['response_headers' => ['content-type' => ['application/rss+xml']]],
        ));

        $tester = $this->tester($client);
        $tester->execute(['--limit' => '1']);

        self::assertSame(0, $tester->getStatusCode());
    }
}
```

- [ ] **Step 2: Run it to confirm it fails**

Run: `php bin/phpunit tests/Command/CheckCatalogUrlsCommandTest.php`
Expected: FAIL — `Command "app:catalog:check-urls" is not defined`.

- [ ] **Step 3: Write the command**

`backend/src/Command/CheckCatalogUrlsCommand.php`:

```php
<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\Catalog\CatalogDocument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Fetches every URL in resources/catalog/catalog.opml and reports the ones that
 * no longer serve a feed. Reads the SHIPPED DOCUMENT, not the database: this
 * checks what we hand a new install, which is the thing that rots unnoticed.
 *
 * Run on a schedule, never as a PR gate — 111 publisher domains produce enough
 * rate limits, bot blocks and transient outages to make a merge check useless.
 */
#[AsCommand(
    name: 'app:catalog:check-urls',
    description: 'Verify every catalog URL still serves a feed',
)]
final class CheckCatalogUrlsCommand extends Command
{
    private const int TIMEOUT_SECONDS = 20;

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly CatalogDocument $parser,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Check at most this many URLs');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $document = $this->parser->parse(
            (string) file_get_contents(\dirname(__DIR__, 2) . '/resources/catalog/catalog.opml'),
        );

        $feeds = [];
        foreach ($document->categories as $category) {
            foreach ($category->feeds as $feed) {
                $feeds[] = $feed;
            }
        }

        $limitOption = $input->getOption('limit');
        if (null !== $limitOption) {
            $feeds = \array_slice($feeds, 0, max(1, (int) $limitOption));
        }

        $broken = [];
        foreach ($feeds as $feed) {
            $failure = $this->check($feed->url);
            if (null !== $failure) {
                $broken[] = \sprintf('%s (%s): %s', $feed->title, $feed->url, $failure);
            }
        }

        if ([] === $broken) {
            $io->success(\sprintf('All %d catalog URLs still serve a feed.', \count($feeds)));

            return Command::SUCCESS;
        }

        $io->error(\sprintf('%d of %d catalog URLs need attention:', \count($broken), \count($feeds)));
        $io->listing($broken);

        return Command::FAILURE;
    }

    /**
     * @return string|null the reason it is broken, or null when it is fine
     */
    private function check(string $url): ?string
    {
        try {
            $response = $this->httpClient->request('GET', $url, [
                'timeout' => self::TIMEOUT_SECONDS,
                'max_duration' => self::TIMEOUT_SECONDS,
                'headers' => ['User-Agent' => 'simple-feed-reader catalog check'],
            ]);

            if (200 !== $response->getStatusCode()) {
                return 'HTTP ' . $response->getStatusCode();
            }

            // A prefix is enough: a feed announces itself in its root element.
            $head = mb_substr($response->getContent(), 0, 2048);
            $isFeed = str_contains($head, '<rss')
                || str_contains($head, '<feed')
                || str_contains($head, '<rdf:RDF');

            return $isFeed ? null : 'not a feed document';
        } catch (\Throwable $e) {
            return $e->getMessage();
        }
    }
}
```

Wire the injected client in `backend/config/services.yaml` so the test can replace it:

```yaml
    catalog.rot_check.http_client:
        parent: 'http_client'

    App\Command\CheckCatalogUrlsCommand:
        arguments:
            $httpClient: '@catalog.rot_check.http_client'
```

- [ ] **Step 4: Run the tests to verify they pass**

Run: `php bin/phpunit tests/Command/CheckCatalogUrlsCommandTest.php`
Expected: PASS, 2 tests.

- [ ] **Step 5: Write the workflow**

`.github/workflows/catalog-rot-check.yml`:

```yaml
name: Catalog rot check

# Scheduled only, plus manual. NEVER a pull_request trigger: this fetches 111
# publisher domains, and rate limits, bot blocks and transient outages would
# make it a red merge check for reasons that have nothing to do with the PR.
on:
  schedule:
    - cron: '17 4 * * 1'
  workflow_dispatch:

permissions:
  contents: read
  issues: write

jobs:
  check:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v4

      - name: Set up PHP
        uses: shivammathur/setup-php@v2
        with:
          php-version: '8.4'
          extensions: intl, pdo_sqlite
          coverage: none

      - name: Install dependencies
        working-directory: backend
        run: composer install --no-interaction --no-progress

      - name: Check every catalog URL
        id: check
        working-directory: backend
        continue-on-error: true
        run: php bin/console app:catalog:check-urls | tee "${RUNNER_TEMP}/rot.txt"

      - name: Open an issue when something rotted
        if: steps.check.outcome == 'failure'
        env:
          GH_TOKEN: ${{ secrets.GITHUB_TOKEN }}
        run: |
          set -euo pipefail
          {
            echo "The weekly catalog check found URLs that no longer serve a feed."
            echo
            echo '```'
            cat "${RUNNER_TEMP}/rot.txt"
            echo '```'
            echo
            echo "Fix by editing \`backend/resources/catalog/catalog.opml\`, then"
            echo "importing it from the admin area. Use **merge** to add and correct"
            echo "without touching anything else, or **replace** to also drop the rows"
            echo "the document no longer lists."
          } > "${RUNNER_TEMP}/body.md"
          gh issue create \
            --title "Catalog rot check: feeds need attention" \
            --body-file "${RUNNER_TEMP}/body.md" \
            --label enhancement
```

- [ ] **Step 6: Verify the workflow parses and commit**

```bash
python3 -c "import yaml,sys; yaml.safe_load(open('.github/workflows/catalog-rot-check.yml')); print('ok')"
cd backend && composer check && composer md && cd ..
git add backend/src/Command/CheckCatalogUrlsCommand.php backend/config/services.yaml backend/tests/Command/CheckCatalogUrlsCommandTest.php .github/workflows/catalog-rot-check.yml
git commit -m "ci(catalog): scheduled URL rot check that opens an issue (#99)"
```

---

### Task 27: End-to-end

**Files:**
- Create: `frontend/e2e/onboarding.spec.ts`

- [ ] **Step 1: Bring the stack up, migrate, and import the catalog**

The migration creates empty tables, so the stack has **no catalog** until something imports one. That is what `app:catalog:import` is for — without this step the picker renders nothing and every assertion below fails for the wrong reason.

```bash
docker compose up -d
docker compose exec php bin/console doctrine:migrations:migrate --no-interaction
docker compose exec php bin/console app:catalog:import
docker compose exec php bin/console dbal:run-sql "SELECT COUNT(*) FROM catalog_feed"
```

Expected: 111. The e2e run then sees the **real** catalog, which is the point — it exercises the true payload size.

If `composer e2e` provisions its own database, add the import to that provisioning step too, beside the migration, so the suite is self-contained rather than depending on a manual command having been run.

- [ ] **Step 2: Write the spec**

`frontend/e2e/onboarding.spec.ts`, following the setup in the existing specs in `frontend/e2e/`:

```ts
import { expect, test } from '@playwright/test';
import { registerAndVerify } from './helpers';

test('a new user is sent to the picker and lands in a reader with their tags', async ({ page }) => {
  const failures: string[] = [];
  // The picker must make NO request to a publisher domain — favicons come from
  // our own origin, cached or monogram. This is the assertion that keeps it so.
  page.on('request', (request) => {
    const url = new URL(request.url());
    if (!['localhost', '127.0.0.1'].includes(url.hostname)) failures.push(request.url());
  });

  await registerAndVerify(page);

  await expect(page).toHaveURL(/\/discover$/);

  const technology = page.getByRole('group', { name: 'Technology' });
  await technology.getByRole('button', { name: 'Select all' }).click();

  const science = page.getByRole('group', { name: 'Science' });
  await science.getByRole('button', { name: 'Select all' }).click();

  await page.getByTestId('subscribe').click();

  await expect(page).toHaveURL(/\/$/);
  await expect(page.getByRole('link', { name: 'Technology' })).toBeVisible();
  await expect(page.getByRole('link', { name: 'Science' })).toBeVisible();

  expect(failures).toEqual([]);
});

test('skipping goes to the reader and leaves a way back', async ({ page }) => {
  await registerAndVerify(page);
  await expect(page).toHaveURL(/\/discover$/);

  await page.getByRole('button', { name: 'Skip for now' }).click();

  await expect(page).toHaveURL(/\/$/);
  await expect(page.getByRole('link', { name: /Browse suggested feeds/ })).toBeVisible();

  // The skip is session-scoped, so a reload must not bounce back to /discover.
  await page.reload();
  await expect(page).toHaveURL(/\/$/);
});
```

If `registerAndVerify` does not exist, reuse whatever registration helper the existing e2e specs use.

- [ ] **Step 3: Run it**

Run from `frontend/`: `npm run e2e -- onboarding.spec.ts`
Expected: PASS, 2 tests.

- [ ] **Step 4: Commit**

```bash
git add e2e/onboarding.spec.ts
git commit -m "test(e2e): onboarding picker to populated reader (#99)"
```

---

## Finishing up

- [ ] **Run both legs of the backend suite**

```bash
cd backend && php bin/phpunit                      # SQLite
docker compose exec php vendor/bin/phpunit         # MySQL
```

- [ ] **Run the frontend gate**

```bash
cd frontend && npm run check
```

- [ ] **Scan the dev log**

```bash
tail -n 200 backend/var/log/dev.log
```

Deprecations and swallowed errors surface there and nowhere else.

- [ ] **PhpStorm inspections on every changed PHP file**

Run `mcp__phpstorm__lint_files` over the files this plan created or modified. Block on ERROR and WARNING; weak warnings are advisory.

- [ ] **Open the PR against `develop`** — never `main`. Close [#99](https://github.com/larspohlmann/simple-feed-reader/issues/99) manually when it merges; PRs into `develop` do not auto-close issues.

---

## Self-review notes

Checked against the design doc:

- **Ordering constraint.** Task 12 (the `BulkSubscriber` extraction) touches `OpmlImporter`, which `#116` does not. If `#116` is still in flight in a shared checkout, coordinate before starting Phase 3.
- **`SubscriptionDto.lastFetchedAt`** is what Task 21's "never been fetched" rule reads. It already exists — `reader-shell.component.ts` uses it for the "Last refreshed" hint — so no DTO change is needed.
- **`sweptOnce` is a signal**, not a plain field, because Task 22's `showFetchBanner` computed reads it. Task 21 introduces it and Task 22 changes it; if executing out of order, declare it as a signal from the start.
- **Category colours are the one hex exception.** They arrive as `#rrggbb` from the API and are bound with `[style.background]` in templates. Nothing writes a hex into a `.scss` outside `src/app/theme/` — Stylelint would reject it.
- **The transcription in Task 2 is the one bulk-data step.** `CatalogDocumentTest` fails until all 13 categories and 111 feeds are present, so a partial transcription cannot pass silently. Watch for unescaped `&` in query-string feed URLs — it is the one thing that will make the XML fail to parse.
- **`OpmlBodyReader` is extracted in Task 2 and adopted in Task 12.** Between those two tasks `OpmlImporter` still has its own private `parseBody()`; Task 12 deletes it. If Task 12 is skipped or deferred, the duplicate stays, which is the one thing this extraction exists to prevent.
- **A migrated database has an empty catalog.** Nothing seeds it: the migration is DDL only, and the document arrives by import. Every environment therefore needs `app:catalog:import` run once — the Docker stack (Task 5 Step 7), the e2e stack (Task 27 Step 1), and production, where the admin imports through the UI. **A production deploy will serve an empty picker until that import happens**; wiring `app:catalog:import` into `activate-release.sh` would remove the manual step, and is deliberately not in this plan.
- **An empty catalog suppresses onboarding entirely.** The shell asks `CatalogStore` before redirecting, and a catalog that is empty — or that failed to load — leaves the user in the reader instead of a blank picker. The store resolves as *empty* on error deliberately: failing closed here means "no redirect", which is the safe direction. The catalog is fetched only in the zero-subscription case, and the store is shared with `/discover`, so a redirect that does happen costs one fetch, not two.
- **Locking is what makes `replace` usable.** A locked row is the admin's, not the document's: never overwritten, never deleted. The subtle part is that a category holding a locked feed must survive too — the FK cascade would otherwise delete the very row the lock protected. Manually created rows default to `locked: true`; imported rows do not.
- **Favicons must not depend on how the app is deployed.** The warming loop lives behind an admin endpoint the UI polls, so a self-hosted install with no deploy script, no cron and no shell still gets icons — automatically, right after the import that created the rows. `app:catalog:warm-favicons` and the Strato deploy hook (Task 11) are conveniences layered on top; nothing breaks without them, because a cold cache renders monograms and that is a working picker.
- **The admin warning is what makes the silent suppression safe.** Because an empty catalog now cancels onboarding, an admin who never imported would otherwise have no signal at all. The shell resolves the catalog unconditionally *for admins* — one cached request per session — and links them straight to the importer, where the bundled document is one click away.
- **`replace` is not truncate-and-insert.** It upserts what the document lists and deletes only what it does not, matching feeds on `url`. A feed whose URL survives keeps its row and therefore its cached favicon, so re-importing never costs a full favicon re-warm.

