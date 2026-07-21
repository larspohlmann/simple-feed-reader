# Backend Foundation Implementation Plan (Plan 1 of 6)

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Stand up the Symfony backend with quality gates, the complete Doctrine data model, a CI pipeline proving SQLite/MySQL portability, and a health endpoint.

**Architecture:** Pragmatic Symfony 7 (controllers → services → Doctrine entities), per spec `docs/superpowers/specs/2026-07-21-simple-feed-reader-design.md`. SQLite for dev/tests, MySQL in CI matrix and production. Tests create schema via Doctrine SchemaTool; production migrations are generated later (Plan 6) when a MySQL target exists.

**Tech Stack:** PHP 8.3, Symfony 7.3, Doctrine ORM 3, PHPUnit 11, PHPStan (max), PHP_CodeSniffer (PSR-12), GitHub Actions.

**Conventions used throughout:**
- All `composer`/`bin/console`/tool commands run in `backend/` unless stated otherwise.
- Commit after every green step; messages use conventional-commit style.
- Spec reference: `docs/superpowers/specs/2026-07-21-simple-feed-reader-design.md`.

---

### Task 1: Symfony skeleton

**Files:**
- Create: `backend/` (via skeleton), `.gitignore` (repo root)

- [ ] **Step 1: Create the project**

Run from repo root:
```bash
composer create-project symfony/skeleton:"7.3.*" backend
```
Expected: project created, `backend/composer.json` exists.

- [ ] **Step 2: Pin PHP platform**

In `backend/composer.json`, ensure:
```json
"require": { "php": ">=8.3" },
"config": {
    "platform": { "php": "8.3.0" },
    "sort-packages": true
}
```
Then run `composer update --lock` (regenerates lock hash only).

- [ ] **Step 3: Root .gitignore**

Create `.gitignore` at repo root:
```gitignore
.idea/
.DS_Store
```
(The skeleton ships its own `backend/.gitignore` for `vendor/`, `var/`, `.env.local*`.)

- [ ] **Step 4: Verify the app boots**

Run: `php bin/console about`
Expected: table with Symfony 7.3.x, PHP 8.3.x, no errors.

- [ ] **Step 5: Commit**

```bash
git add -A
git commit -m "feat: add Symfony 7.3 backend skeleton"
```

---

### Task 2: Quality gates — PHPCS (PSR-12) and PHPStan (max)

**Files:**
- Create: `backend/phpcs.xml.dist`, `backend/phpstan.dist.neon`
- Modify: `backend/composer.json` (scripts)

- [ ] **Step 1: Install tools**

```bash
composer require --dev squizlabs/php_codesniffer:^3.10 phpstan/phpstan:^2.1 phpstan/phpstan-symfony:^2.0 phpstan/phpstan-doctrine:^2.0
```

- [ ] **Step 2: PHPCS config**

Create `backend/phpcs.xml.dist`:
```xml
<?xml version="1.0"?>
<ruleset name="simple-feed-reader">
    <description>PSR-12 for the backend.</description>
    <arg name="extensions" value="php"/>
    <arg name="colors"/>
    <arg value="p"/>
    <rule ref="PSR12"/>
    <file>src</file>
    <file>tests</file>
</ruleset>
```

- [ ] **Step 3: PHPStan config**

Create `backend/phpstan.dist.neon`:
```neon
includes:
    - vendor/phpstan/phpstan-symfony/extension.neon
    - vendor/phpstan/phpstan-doctrine/extension.neon
    - vendor/phpstan/phpstan-doctrine/rules.neon

parameters:
    level: max
    paths:
        - src
        - tests
    symfony:
        containerXmlPath: var/cache/dev/App_KernelDevDebugContainer.xml
```

- [ ] **Step 4: Composer scripts**

Add to `backend/composer.json` `"scripts"`:
```json
"cs": "phpcs",
"cs:fix": "phpcbf",
"stan": "phpstan analyse --memory-limit=512M",
"check": ["@cs", "@stan"]
```

- [ ] **Step 5: Run both, expect green**

Run: `php bin/console cache:warmup && composer check`
Expected: PHPCS no errors; PHPStan "No errors". (`tests/` may not exist yet — if PHPCS complains about a missing dir, create empty `backend/tests/.gitkeep` first.)

- [ ] **Step 6: Commit**

```bash
git add -A
git commit -m "chore: add PHPCS (PSR-12) and PHPStan max-level gates"
```

---

### Task 3: PHPUnit + first smoke test

**Files:**
- Create: `backend/tests/SmokeTest.php`
- Modify: `backend/.env.test` (created by recipe)

- [ ] **Step 1: Install test tooling**

```bash
composer require --dev symfony/test-pack
```
Expected: `phpunit.dist.xml` (or `phpunit.xml.dist`) and `tests/` created by recipe.

- [ ] **Step 2: Write a failing smoke test**

Create `backend/tests/SmokeTest.php`:
```php
<?php

declare(strict_types=1);

namespace App\Tests;

use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class SmokeTest extends KernelTestCase
{
    public function testKernelBoots(): void
    {
        self::bootKernel();

        self::assertSame('test', self::$kernel->getEnvironment());
    }
}
```

- [ ] **Step 3: Run it**

Run: `php bin/phpunit`
Expected: PASS (1 test). If it fails, fix the environment before continuing — nothing else can proceed on a kernel that won't boot.

- [ ] **Step 4: Commit**

```bash
git add -A
git commit -m "test: add PHPUnit with kernel smoke test"
```

---

### Task 4: Doctrine ORM with switchable SQLite/MySQL

**Files:**
- Create: `backend/tests/DbTestCase.php`
- Modify: `backend/.env`, `backend/.env.test`

- [ ] **Step 1: Install ORM**

```bash
composer require symfony/orm-pack
composer require --dev dama/doctrine-test-bundle
```
Accept recipes. (`dama/doctrine-test-bundle` wraps each test in a rolled-back transaction — keeps DB tests fast and isolated on both SQLite and MySQL.)

- [ ] **Step 2: Configure DSNs**

In `backend/.env` set the dev default:
```dotenv
DATABASE_URL="sqlite:///%kernel.project_dir%/var/data_dev.db"
```
In `backend/.env.test`:
```dotenv
DATABASE_URL="sqlite:///%kernel.project_dir%/var/data_test.db"
```
(File-based test DB, not `:memory:` — schema is created once by the test bootstrap and reused; DAMA rolls back per test. CI overrides `DATABASE_URL` for the MySQL leg.)

- [ ] **Step 3: Enable DAMA extension in PHPUnit config**

In the PHPUnit config file (`phpunit.dist.xml`), add inside `<phpunit>`:
```xml
<extensions>
    <bootstrap class="DAMA\DoctrineTestBundle\PHPUnit\PHPUnitExtension"/>
</extensions>
```

- [ ] **Step 4: Schema bootstrap for tests**

Create `backend/tests/DbTestCase.php`:
```php
<?php

declare(strict_types=1);

namespace App\Tests;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

abstract class DbTestCase extends KernelTestCase
{
    private static bool $schemaReady = false;

    protected EntityManagerInterface $em;

    protected function setUp(): void
    {
        self::bootKernel();
        /** @var EntityManagerInterface $em */
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $this->em = $em;

        self::ensureSchema($em);
    }

    private static function ensureSchema(EntityManagerInterface $em): void
    {
        if (self::$schemaReady) {
            return;
        }

        $metadata = $em->getMetadataFactory()->getAllMetadata();
        $schemaTool = new SchemaTool($em);
        $schemaTool->dropSchema($metadata);
        $schemaTool->createSchema($metadata);
        self::$schemaReady = true;
    }
}
```

- [ ] **Step 5: Run checks and tests**

Run: `composer check && php bin/phpunit`
Expected: all green (schema bootstrap has no consumers yet; it compiles and the smoke test still passes).

- [ ] **Step 6: Commit**

```bash
git add -A
git commit -m "feat: add Doctrine ORM with SQLite default and DB test base"
```

---

### Task 5: User entity + UserStatus

**Files:**
- Create: `backend/src/Enum/UserStatus.php`, `backend/src/Entity/User.php`, `backend/src/Repository/UserRepository.php`
- Test: `backend/tests/Entity/UserTest.php`

- [ ] **Step 1: Write the failing test**

Create `backend/tests/Entity/UserTest.php`:
```php
<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\User;
use App\Enum\UserStatus;
use App\Tests\DbTestCase;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;

final class UserTest extends DbTestCase
{
    public function testPersistAndReload(): void
    {
        $user = new User('lars@example.com', new \DateTimeImmutable('2026-07-21 10:00:00'));
        $this->em->persist($user);
        $this->em->flush();
        $this->em->clear();

        $reloaded = $this->em->getRepository(User::class)->findOneBy(['email' => 'lars@example.com']);

        self::assertNotNull($reloaded);
        self::assertSame(UserStatus::PendingVerification, $reloaded->getStatus());
        self::assertNull($reloaded->getPasswordHash());
        self::assertNull($reloaded->getApprovedAt());
        self::assertSame(['ROLE_USER'], $reloaded->getRoles());
    }

    public function testEmailIsUnique(): void
    {
        $now = new \DateTimeImmutable();
        $this->em->persist(new User('dup@example.com', $now));
        $this->em->flush();

        $this->em->persist(new User('dup@example.com', $now));

        $this->expectException(UniqueConstraintViolationException::class);
        $this->em->flush();
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php bin/phpunit tests/Entity/UserTest.php`
Expected: FAIL — `Class "App\Entity\User" not found`.

- [ ] **Step 3: Implement enum, entity, repository**

Create `backend/src/Enum/UserStatus.php`:
```php
<?php

declare(strict_types=1);

namespace App\Enum;

enum UserStatus: string
{
    case PendingVerification = 'pending_verification';
    case PendingApproval = 'pending_approval';
    case Active = 'active';
    case Rejected = 'rejected';
    case Suspended = 'suspended';
}
```

Create `backend/src/Entity/User.php`:
```php
<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\UserStatus;
use App\Repository\UserRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: UserRepository::class)]
#[ORM\Table(name: 'app_user')]
#[ORM\UniqueConstraint(name: 'uniq_user_email', columns: ['email'])]
class User
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 180)]
    private string $email;

    #[ORM\Column(nullable: true)]
    private ?string $passwordHash = null;

    /** @var list<string> */
    #[ORM\Column(type: Types::JSON)]
    private array $roles = [];

    #[ORM\Column(length: 30, enumType: UserStatus::class)]
    private UserStatus $status = UserStatus::PendingVerification;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $approvedAt = null;

    public function __construct(string $email, \DateTimeImmutable $createdAt)
    {
        $this->email = $email;
        $this->createdAt = $createdAt;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getEmail(): string
    {
        return $this->email;
    }

    public function getPasswordHash(): ?string
    {
        return $this->passwordHash;
    }

    public function setPasswordHash(?string $passwordHash): void
    {
        $this->passwordHash = $passwordHash;
    }

    /** @return list<string> */
    public function getRoles(): array
    {
        $roles = $this->roles;
        $roles[] = 'ROLE_USER';

        return array_values(array_unique($roles));
    }

    /** @param list<string> $roles */
    public function setRoles(array $roles): void
    {
        $this->roles = $roles;
    }

    public function getStatus(): UserStatus
    {
        return $this->status;
    }

    public function setStatus(UserStatus $status): void
    {
        $this->status = $status;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getApprovedAt(): ?\DateTimeImmutable
    {
        return $this->approvedAt;
    }

    public function setApprovedAt(?\DateTimeImmutable $approvedAt): void
    {
        $this->approvedAt = $approvedAt;
    }
}
```

Create `backend/src/Repository/UserRepository.php`:
```php
<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<User>
 */
class UserRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, User::class);
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php bin/phpunit tests/Entity/UserTest.php`
Expected: PASS (2 tests).

- [ ] **Step 5: Quality gates**

Run: `composer check`
Expected: green.

- [ ] **Step 6: Commit**

```bash
git add -A
git commit -m "feat: add User entity with status lifecycle"
```

---

### Task 6: Feed + Entry entities

**Files:**
- Create: `backend/src/Enum/FeedStatus.php`, `backend/src/Entity/Feed.php`, `backend/src/Entity/Entry.php`, `backend/src/Repository/FeedRepository.php`, `backend/src/Repository/EntryRepository.php`
- Test: `backend/tests/Entity/FeedEntryTest.php`

- [ ] **Step 1: Write the failing test**

Create `backend/tests/Entity/FeedEntryTest.php`:
```php
<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\Entry;
use App\Entity\Feed;
use App\Enum\FeedStatus;
use App\Tests\DbTestCase;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;

final class FeedEntryTest extends DbTestCase
{
    public function testFeedDefaults(): void
    {
        $feed = new Feed('https://example.com/feed.xml');
        $this->em->persist($feed);
        $this->em->flush();
        $this->em->clear();

        $reloaded = $this->em->getRepository(Feed::class)->findOneBy(['url' => 'https://example.com/feed.xml']);

        self::assertNotNull($reloaded);
        self::assertSame(FeedStatus::Active, $reloaded->getStatus());
        self::assertSame(60, $reloaded->getFetchIntervalMinutes());
        self::assertSame(0, $reloaded->getConsecutiveFailures());
        self::assertNull($reloaded->getEtag());
    }

    public function testFeedUrlIsUnique(): void
    {
        $this->em->persist(new Feed('https://dup.example.com/feed.xml'));
        $this->em->flush();

        $this->em->persist(new Feed('https://dup.example.com/feed.xml'));

        $this->expectException(UniqueConstraintViolationException::class);
        $this->em->flush();
    }

    public function testEntryGuidHashIsComputedAndUniquePerFeed(): void
    {
        $feed = new Feed('https://example.com/a.xml');
        $this->em->persist($feed);

        $entry = new Entry(
            feed: $feed,
            guid: 'urn:uuid:1234',
            url: 'https://example.com/post/1',
            title: 'First post',
            createdAt: new \DateTimeImmutable(),
        );
        $this->em->persist($entry);
        $this->em->flush();

        self::assertSame(hash('sha256', 'urn:uuid:1234'), $entry->getGuidHash());

        $duplicate = new Entry(
            feed: $feed,
            guid: 'urn:uuid:1234',
            url: 'https://example.com/post/1-copy',
            title: 'Same guid again',
            createdAt: new \DateTimeImmutable(),
        );
        $this->em->persist($duplicate);

        $this->expectException(UniqueConstraintViolationException::class);
        $this->em->flush();
    }

    public function testSameGuidOnDifferentFeedsIsAllowed(): void
    {
        $feedA = new Feed('https://a.example.com/feed.xml');
        $feedB = new Feed('https://b.example.com/feed.xml');
        $this->em->persist($feedA);
        $this->em->persist($feedB);
        $now = new \DateTimeImmutable();

        $this->em->persist(new Entry($feedA, 'shared-guid', 'https://a.example.com/1', 'A', $now));
        $this->em->persist(new Entry($feedB, 'shared-guid', 'https://b.example.com/1', 'B', $now));
        $this->em->flush();

        $this->addToAssertionCount(1);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php bin/phpunit tests/Entity/FeedEntryTest.php`
Expected: FAIL — `Class "App\Entity\Feed" not found`.

- [ ] **Step 3: Implement**

Create `backend/src/Enum/FeedStatus.php`:
```php
<?php

declare(strict_types=1);

namespace App\Enum;

enum FeedStatus: string
{
    case Active = 'active';
    case Erroring = 'erroring';
    case Gone = 'gone';
}
```

Create `backend/src/Entity/Feed.php`:
```php
<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\FeedStatus;
use App\Repository\FeedRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: FeedRepository::class)]
#[ORM\Table(name: 'feed')]
#[ORM\UniqueConstraint(name: 'uniq_feed_url', columns: ['url'])]
class Feed
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 750)]
    private string $url;

    #[ORM\Column(length: 2048, nullable: true)]
    private ?string $siteUrl = null;

    #[ORM\Column(length: 512, nullable: true)]
    private ?string $title = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $description = null;

    #[ORM\Column(length: 2048, nullable: true)]
    private ?string $faviconUrl = null;

    #[ORM\Column(length: 20, enumType: FeedStatus::class)]
    private FeedStatus $status = FeedStatus::Active;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $lastFetchedAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $nextFetchAt = null;

    #[ORM\Column]
    private int $fetchIntervalMinutes = 60;

    #[ORM\Column]
    private int $consecutiveFailures = 0;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $lastErrorMessage = null;

    #[ORM\Column(length: 512, nullable: true)]
    private ?string $etag = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $lastModified = null;

    public function __construct(string $url)
    {
        $this->url = $url;
    }

    public function getId(): ?int
    {
        return $this->id;
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

    public function getTitle(): ?string
    {
        return $this->title;
    }

    public function setTitle(?string $title): void
    {
        $this->title = $title;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(?string $description): void
    {
        $this->description = $description;
    }

    public function getFaviconUrl(): ?string
    {
        return $this->faviconUrl;
    }

    public function setFaviconUrl(?string $faviconUrl): void
    {
        $this->faviconUrl = $faviconUrl;
    }

    public function getStatus(): FeedStatus
    {
        return $this->status;
    }

    public function setStatus(FeedStatus $status): void
    {
        $this->status = $status;
    }

    public function getLastFetchedAt(): ?\DateTimeImmutable
    {
        return $this->lastFetchedAt;
    }

    public function setLastFetchedAt(?\DateTimeImmutable $lastFetchedAt): void
    {
        $this->lastFetchedAt = $lastFetchedAt;
    }

    public function getNextFetchAt(): ?\DateTimeImmutable
    {
        return $this->nextFetchAt;
    }

    public function setNextFetchAt(?\DateTimeImmutable $nextFetchAt): void
    {
        $this->nextFetchAt = $nextFetchAt;
    }

    public function getFetchIntervalMinutes(): int
    {
        return $this->fetchIntervalMinutes;
    }

    public function setFetchIntervalMinutes(int $minutes): void
    {
        $this->fetchIntervalMinutes = $minutes;
    }

    public function getConsecutiveFailures(): int
    {
        return $this->consecutiveFailures;
    }

    public function setConsecutiveFailures(int $consecutiveFailures): void
    {
        $this->consecutiveFailures = $consecutiveFailures;
    }

    public function getLastErrorMessage(): ?string
    {
        return $this->lastErrorMessage;
    }

    public function setLastErrorMessage(?string $lastErrorMessage): void
    {
        $this->lastErrorMessage = $lastErrorMessage;
    }

    public function getEtag(): ?string
    {
        return $this->etag;
    }

    public function setEtag(?string $etag): void
    {
        $this->etag = $etag;
    }

    public function getLastModified(): ?string
    {
        return $this->lastModified;
    }

    public function setLastModified(?string $lastModified): void
    {
        $this->lastModified = $lastModified;
    }
}
```

Create `backend/src/Entity/Entry.php`:
```php
<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\EntryRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: EntryRepository::class)]
#[ORM\Table(name: 'entry')]
#[ORM\UniqueConstraint(name: 'uniq_entry_feed_guid', columns: ['feed_id', 'guid_hash'])]
#[ORM\Index(name: 'idx_entry_feed_published', columns: ['feed_id', 'published_at'])]
class Entry
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Feed::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Feed $feed;

    #[ORM\Column(type: Types::TEXT)]
    private string $guid;

    #[ORM\Column(length: 64)]
    private string $guidHash;

    #[ORM\Column(length: 2048, nullable: true)]
    private ?string $url;

    #[ORM\Column(length: 1024)]
    private string $title;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $author = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $summary = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $contentHtml = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $publishedAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    public function __construct(
        Feed $feed,
        string $guid,
        ?string $url,
        string $title,
        \DateTimeImmutable $createdAt,
    ) {
        $this->feed = $feed;
        $this->guid = $guid;
        $this->guidHash = hash('sha256', $guid);
        $this->url = $url;
        $this->title = $title;
        $this->createdAt = $createdAt;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getFeed(): Feed
    {
        return $this->feed;
    }

    public function getGuid(): string
    {
        return $this->guid;
    }

    public function getGuidHash(): string
    {
        return $this->guidHash;
    }

    public function getUrl(): ?string
    {
        return $this->url;
    }

    public function getTitle(): string
    {
        return $this->title;
    }

    public function setTitle(string $title): void
    {
        $this->title = $title;
    }

    public function getAuthor(): ?string
    {
        return $this->author;
    }

    public function setAuthor(?string $author): void
    {
        $this->author = $author;
    }

    public function getSummary(): ?string
    {
        return $this->summary;
    }

    public function setSummary(?string $summary): void
    {
        $this->summary = $summary;
    }

    public function getContentHtml(): ?string
    {
        return $this->contentHtml;
    }

    public function setContentHtml(?string $contentHtml): void
    {
        $this->contentHtml = $contentHtml;
    }

    public function getPublishedAt(): ?\DateTimeImmutable
    {
        return $this->publishedAt;
    }

    public function setPublishedAt(?\DateTimeImmutable $publishedAt): void
    {
        $this->publishedAt = $publishedAt;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
```

Create `backend/src/Repository/FeedRepository.php`:
```php
<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Feed;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Feed>
 */
class FeedRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Feed::class);
    }
}
```

Create `backend/src/Repository/EntryRepository.php`:
```php
<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Entry;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Entry>
 */
class EntryRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Entry::class);
    }
}
```

**Note on `Feed::url` length 750:** MySQL InnoDB caps unique index keys at 3072 bytes (768 chars at utf8mb4), so the uniquely indexed `Feed::url` is 750 chars. Other URL columns are not indexed and stay at 2048.

- [ ] **Step 4: Run test to verify it passes**

Run: `php bin/phpunit tests/Entity/FeedEntryTest.php`
Expected: PASS (4 tests).

- [ ] **Step 5: Quality gates**

Run: `composer check`
Expected: green.

- [ ] **Step 6: Commit**

```bash
git add -A
git commit -m "feat: add Feed and Entry entities with guid-hash dedup"
```

---

### Task 7: Tag, Subscription, EntryState

**Files:**
- Create: `backend/src/Entity/Tag.php`, `backend/src/Entity/Subscription.php`, `backend/src/Entity/EntryState.php`, `backend/src/Repository/TagRepository.php`, `backend/src/Repository/SubscriptionRepository.php`, `backend/src/Repository/EntryStateRepository.php`
- Test: `backend/tests/Entity/SubscriptionTest.php`

- [ ] **Step 1: Write the failing test**

Create `backend/tests/Entity/SubscriptionTest.php`:
```php
<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\Entry;
use App\Entity\EntryState;
use App\Entity\Feed;
use App\Entity\Subscription;
use App\Entity\Tag;
use App\Entity\User;
use App\Tests\DbTestCase;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;

final class SubscriptionTest extends DbTestCase
{
    private function makeUser(string $email = 'reader@example.com'): User
    {
        $user = new User($email, new \DateTimeImmutable());
        $this->em->persist($user);

        return $user;
    }

    private function makeFeed(string $url = 'https://example.com/feed.xml'): Feed
    {
        $feed = new Feed($url);
        $this->em->persist($feed);

        return $feed;
    }

    public function testSubscriptionWithMultipleTags(): void
    {
        $user = $this->makeUser();
        $feed = $this->makeFeed();

        $tech = new Tag($user, 'Tech');
        $tech->setColor('#3366ff');
        $tech->setIcon('memory');
        $linux = new Tag($user, 'Linux');
        $this->em->persist($tech);
        $this->em->persist($linux);

        $subscription = new Subscription($user, $feed, new \DateTimeImmutable());
        $subscription->addTag($tech);
        $subscription->addTag($linux);
        $this->em->persist($subscription);
        $this->em->flush();
        $this->em->clear();

        $reloaded = $this->em->getRepository(Subscription::class)->findOneBy(['user' => $user->getId()]);

        self::assertNotNull($reloaded);
        self::assertCount(2, $reloaded->getTags());
        self::assertNull($reloaded->getMarkedReadUntil());
    }

    public function testUserCannotSubscribeTwiceToSameFeed(): void
    {
        $user = $this->makeUser();
        $feed = $this->makeFeed();
        $now = new \DateTimeImmutable();

        $this->em->persist(new Subscription($user, $feed, $now));
        $this->em->flush();

        $this->em->persist(new Subscription($user, $feed, $now));

        $this->expectException(UniqueConstraintViolationException::class);
        $this->em->flush();
    }

    public function testTagNameUniquePerUserButNotGlobally(): void
    {
        $userA = $this->makeUser('a@example.com');
        $userB = $this->makeUser('b@example.com');

        $this->em->persist(new Tag($userA, 'News'));
        $this->em->persist(new Tag($userB, 'News'));
        $this->em->flush();

        $this->em->persist(new Tag($userA, 'News'));

        $this->expectException(UniqueConstraintViolationException::class);
        $this->em->flush();
    }

    public function testEntryStateCompositeKey(): void
    {
        $user = $this->makeUser();
        $feed = $this->makeFeed();
        $entry = new Entry($feed, 'guid-1', 'https://example.com/1', 'Post', new \DateTimeImmutable());
        $this->em->persist($entry);
        $this->em->flush();

        $state = new EntryState($user, $entry);
        $state->setIsRead(true);
        $state->setReadAt(new \DateTimeImmutable());
        $this->em->persist($state);
        $this->em->flush();
        $this->em->clear();

        $reloaded = $this->em->find(EntryState::class, ['user' => $user->getId(), 'entry' => $entry->getId()]);

        self::assertNotNull($reloaded);
        self::assertTrue($reloaded->isRead());
        self::assertFalse($reloaded->isFavorite());
        self::assertFalse($reloaded->isKept());
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php bin/phpunit tests/Entity/SubscriptionTest.php`
Expected: FAIL — `Class "App\Entity\Tag" not found`.

- [ ] **Step 3: Implement**

Create `backend/src/Entity/Tag.php`:
```php
<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\TagRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: TagRepository::class)]
#[ORM\Table(name: 'tag')]
#[ORM\UniqueConstraint(name: 'uniq_tag_user_name', columns: ['user_id', 'name'])]
class Tag
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\Column(length: 100)]
    private string $name;

    #[ORM\Column(length: 20, nullable: true)]
    private ?string $color = null;

    #[ORM\Column(length: 64, nullable: true)]
    private ?string $icon = null;

    public function __construct(User $user, string $name)
    {
        $this->user = $user;
        $this->name = $name;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): void
    {
        $this->name = $name;
    }

    public function getColor(): ?string
    {
        return $this->color;
    }

    public function setColor(?string $color): void
    {
        $this->color = $color;
    }

    public function getIcon(): ?string
    {
        return $this->icon;
    }

    public function setIcon(?string $icon): void
    {
        $this->icon = $icon;
    }
}
```

Create `backend/src/Entity/Subscription.php`:
```php
<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\SubscriptionRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: SubscriptionRepository::class)]
#[ORM\Table(name: 'subscription')]
#[ORM\UniqueConstraint(name: 'uniq_subscription_user_feed', columns: ['user_id', 'feed_id'])]
class Subscription
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\ManyToOne(targetEntity: Feed::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Feed $feed;

    #[ORM\Column(length: 512, nullable: true)]
    private ?string $customTitle = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $markedReadUntil = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    /** @var Collection<int, Tag> */
    #[ORM\ManyToMany(targetEntity: Tag::class)]
    #[ORM\JoinTable(name: 'subscription_tag')]
    private Collection $tags;

    public function __construct(User $user, Feed $feed, \DateTimeImmutable $createdAt)
    {
        $this->user = $user;
        $this->feed = $feed;
        $this->createdAt = $createdAt;
        $this->tags = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function getFeed(): Feed
    {
        return $this->feed;
    }

    public function getCustomTitle(): ?string
    {
        return $this->customTitle;
    }

    public function setCustomTitle(?string $customTitle): void
    {
        $this->customTitle = $customTitle;
    }

    public function getMarkedReadUntil(): ?\DateTimeImmutable
    {
        return $this->markedReadUntil;
    }

    public function setMarkedReadUntil(?\DateTimeImmutable $markedReadUntil): void
    {
        $this->markedReadUntil = $markedReadUntil;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    /** @return Collection<int, Tag> */
    public function getTags(): Collection
    {
        return $this->tags;
    }

    public function addTag(Tag $tag): void
    {
        if (!$this->tags->contains($tag)) {
            $this->tags->add($tag);
        }
    }

    public function removeTag(Tag $tag): void
    {
        $this->tags->removeElement($tag);
    }
}
```

Create `backend/src/Entity/EntryState.php`:
```php
<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\EntryStateRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: EntryStateRepository::class)]
#[ORM\Table(name: 'entry_state')]
class EntryState
{
    #[ORM\Id]
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\Id]
    #[ORM\ManyToOne(targetEntity: Entry::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Entry $entry;

    #[ORM\Column]
    private bool $isRead = false;

    #[ORM\Column]
    private bool $isFavorite = false;

    #[ORM\Column]
    private bool $isKept = false;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $readAt = null;

    public function __construct(User $user, Entry $entry)
    {
        $this->user = $user;
        $this->entry = $entry;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function getEntry(): Entry
    {
        return $this->entry;
    }

    public function isRead(): bool
    {
        return $this->isRead;
    }

    public function setIsRead(bool $isRead): void
    {
        $this->isRead = $isRead;
    }

    public function isFavorite(): bool
    {
        return $this->isFavorite;
    }

    public function setIsFavorite(bool $isFavorite): void
    {
        $this->isFavorite = $isFavorite;
    }

    public function isKept(): bool
    {
        return $this->isKept;
    }

    public function setIsKept(bool $isKept): void
    {
        $this->isKept = $isKept;
    }

    public function getReadAt(): ?\DateTimeImmutable
    {
        return $this->readAt;
    }

    public function setReadAt(?\DateTimeImmutable $readAt): void
    {
        $this->readAt = $readAt;
    }
}
```

Create the three repositories, same pattern as `UserRepository` (adjust class names):

`backend/src/Repository/TagRepository.php`:
```php
<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Tag;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Tag>
 */
class TagRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Tag::class);
    }
}
```

`backend/src/Repository/SubscriptionRepository.php`:
```php
<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Subscription;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Subscription>
 */
class SubscriptionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Subscription::class);
    }
}
```

`backend/src/Repository/EntryStateRepository.php`:
```php
<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\EntryState;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<EntryState>
 */
class EntryStateRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, EntryState::class);
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php bin/phpunit tests/Entity/SubscriptionTest.php`
Expected: PASS (4 tests).

- [ ] **Step 5: Full suite + quality gates**

Run: `php bin/phpunit && composer check`
Expected: all green.

- [ ] **Step 6: Commit**

```bash
git add -A
git commit -m "feat: add Tag, Subscription, and EntryState entities"
```

---

### Task 8: UserIdentity + ActionToken

**Files:**
- Create: `backend/src/Enum/TokenPurpose.php`, `backend/src/Entity/UserIdentity.php`, `backend/src/Entity/ActionToken.php`, `backend/src/Repository/UserIdentityRepository.php`, `backend/src/Repository/ActionTokenRepository.php`
- Test: `backend/tests/Entity/IdentityTokenTest.php`

- [ ] **Step 1: Write the failing test**

Create `backend/tests/Entity/IdentityTokenTest.php`:
```php
<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\ActionToken;
use App\Entity\User;
use App\Entity\UserIdentity;
use App\Enum\TokenPurpose;
use App\Tests\DbTestCase;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;

final class IdentityTokenTest extends DbTestCase
{
    public function testProviderIdentityIsGloballyUnique(): void
    {
        $now = new \DateTimeImmutable();
        $userA = new User('a@example.com', $now);
        $userB = new User('b@example.com', $now);
        $this->em->persist($userA);
        $this->em->persist($userB);

        $this->em->persist(new UserIdentity($userA, 'google', 'google-uid-1', $now));
        $this->em->flush();

        $this->em->persist(new UserIdentity($userB, 'google', 'google-uid-1', $now));

        $this->expectException(UniqueConstraintViolationException::class);
        $this->em->flush();
    }

    public function testSameProviderIdOnDifferentProvidersIsAllowed(): void
    {
        $now = new \DateTimeImmutable();
        $user = new User('c@example.com', $now);
        $this->em->persist($user);

        $this->em->persist(new UserIdentity($user, 'google', 'uid-x', $now));
        $this->em->persist(new UserIdentity($user, 'apple', 'uid-x', $now));
        $this->em->flush();

        $this->addToAssertionCount(1);
    }

    public function testActionTokenLifecycle(): void
    {
        $now = new \DateTimeImmutable('2026-07-21 12:00:00');
        $user = new User('d@example.com', $now);
        $this->em->persist($user);

        $token = new ActionToken(
            user: $user,
            purpose: TokenPurpose::VerifyEmail,
            tokenHash: hash('sha256', 'raw-token-value'),
            expiresAt: $now->modify('+24 hours'),
            createdAt: $now,
        );
        $this->em->persist($token);
        $this->em->flush();
        $this->em->clear();

        $reloaded = $this->em->getRepository(ActionToken::class)
            ->findOneBy(['tokenHash' => hash('sha256', 'raw-token-value')]);

        self::assertNotNull($reloaded);
        self::assertSame(TokenPurpose::VerifyEmail, $reloaded->getPurpose());
        self::assertNull($reloaded->getConsumedAt());
        self::assertFalse($reloaded->isExpiredAt($now->modify('+23 hours')));
        self::assertTrue($reloaded->isExpiredAt($now->modify('+25 hours')));
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php bin/phpunit tests/Entity/IdentityTokenTest.php`
Expected: FAIL — `Class "App\Entity\UserIdentity" not found`.

- [ ] **Step 3: Implement**

Create `backend/src/Enum/TokenPurpose.php`:
```php
<?php

declare(strict_types=1);

namespace App\Enum;

enum TokenPurpose: string
{
    case VerifyEmail = 'verify_email';
    case ResetPassword = 'reset_password';
}
```

Create `backend/src/Entity/UserIdentity.php`:
```php
<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\UserIdentityRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: UserIdentityRepository::class)]
#[ORM\Table(name: 'user_identity')]
#[ORM\UniqueConstraint(name: 'uniq_identity_provider_uid', columns: ['provider', 'provider_user_id'])]
class UserIdentity
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\Column(length: 30)]
    private string $provider;

    #[ORM\Column(length: 191)]
    private string $providerUserId;

    #[ORM\Column(length: 180, nullable: true)]
    private ?string $email = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    public function __construct(User $user, string $provider, string $providerUserId, \DateTimeImmutable $createdAt)
    {
        $this->user = $user;
        $this->provider = $provider;
        $this->providerUserId = $providerUserId;
        $this->createdAt = $createdAt;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function getProvider(): string
    {
        return $this->provider;
    }

    public function getProviderUserId(): string
    {
        return $this->providerUserId;
    }

    public function getEmail(): ?string
    {
        return $this->email;
    }

    public function setEmail(?string $email): void
    {
        $this->email = $email;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
```

Create `backend/src/Entity/ActionToken.php`:
```php
<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\TokenPurpose;
use App\Repository\ActionTokenRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: ActionTokenRepository::class)]
#[ORM\Table(name: 'action_token')]
#[ORM\Index(name: 'idx_action_token_hash', columns: ['token_hash'])]
class ActionToken
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\Column(length: 30, enumType: TokenPurpose::class)]
    private TokenPurpose $purpose;

    #[ORM\Column(length: 64)]
    private string $tokenHash;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $expiresAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $consumedAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    public function __construct(
        User $user,
        TokenPurpose $purpose,
        string $tokenHash,
        \DateTimeImmutable $expiresAt,
        \DateTimeImmutable $createdAt,
    ) {
        $this->user = $user;
        $this->purpose = $purpose;
        $this->tokenHash = $tokenHash;
        $this->expiresAt = $expiresAt;
        $this->createdAt = $createdAt;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function getPurpose(): TokenPurpose
    {
        return $this->purpose;
    }

    public function getTokenHash(): string
    {
        return $this->tokenHash;
    }

    public function getExpiresAt(): \DateTimeImmutable
    {
        return $this->expiresAt;
    }

    public function isExpiredAt(\DateTimeImmutable $now): bool
    {
        return $now > $this->expiresAt;
    }

    public function getConsumedAt(): ?\DateTimeImmutable
    {
        return $this->consumedAt;
    }

    public function setConsumedAt(?\DateTimeImmutable $consumedAt): void
    {
        $this->consumedAt = $consumedAt;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
```

Create `backend/src/Repository/UserIdentityRepository.php`:
```php
<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\UserIdentity;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<UserIdentity>
 */
class UserIdentityRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, UserIdentity::class);
    }
}
```

Create `backend/src/Repository/ActionTokenRepository.php`:
```php
<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\ActionToken;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ActionToken>
 */
class ActionTokenRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ActionToken::class);
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php bin/phpunit tests/Entity/IdentityTokenTest.php`
Expected: PASS (3 tests).

- [ ] **Step 5: Full suite + quality gates**

Run: `php bin/phpunit && composer check`
Expected: all green.

- [ ] **Step 6: Commit**

```bash
git add -A
git commit -m "feat: add UserIdentity and ActionToken entities"
```

---

### Task 9: Health endpoint

**Files:**
- Create: `backend/src/Controller/Api/HealthController.php`
- Test: `backend/tests/Controller/HealthControllerTest.php`

- [ ] **Step 1: Write the failing test**

Create `backend/tests/Controller/HealthControllerTest.php`:
```php
<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class HealthControllerTest extends WebTestCase
{
    public function testHealthReturnsOk(): void
    {
        $client = self::createClient();
        $client->request('GET', '/api/health');

        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('content-type', 'application/json');

        $payload = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertIsArray($payload);
        self::assertSame('ok', $payload['status']);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php bin/phpunit tests/Controller/HealthControllerTest.php`
Expected: FAIL — 404 (route does not exist).

- [ ] **Step 3: Implement**

Create `backend/src/Controller/Api/HealthController.php`:
```php
<?php

declare(strict_types=1);

namespace App\Controller\Api;

use Doctrine\DBAL\Connection;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class HealthController
{
    #[Route('/api/health', name: 'api_health', methods: ['GET'])]
    public function __invoke(Connection $connection): JsonResponse
    {
        try {
            $connection->executeQuery('SELECT 1');
        } catch (\Throwable) {
            return new JsonResponse(
                ['status' => 'error', 'database' => 'unreachable'],
                Response::HTTP_SERVICE_UNAVAILABLE,
            );
        }

        return new JsonResponse(['status' => 'ok']);
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php bin/phpunit tests/Controller/HealthControllerTest.php`
Expected: PASS.

- [ ] **Step 5: Full suite + quality gates**

Run: `php bin/phpunit && composer check`
Expected: all green.

- [ ] **Step 6: Commit**

```bash
git add -A
git commit -m "feat: add public health endpoint with DB connectivity check"
```

---

### Task 10: CI workflow with SQLite/MySQL matrix

**Files:**
- Create: `.github/workflows/ci.yml` (repo root)

- [ ] **Step 1: Write the workflow**

Create `.github/workflows/ci.yml`:
```yaml
name: CI

on:
  push:
    branches: [main]
  pull_request:

jobs:
  backend:
    name: Backend (${{ matrix.database }})
    runs-on: ubuntu-latest
    strategy:
      fail-fast: false
      matrix:
        database: [sqlite, mysql]

    services:
      mysql:
        image: mysql:8.4
        env:
          MYSQL_ROOT_PASSWORD: root
          MYSQL_DATABASE: feedreader_test
        ports:
          - 3306:3306
        options: >-
          --health-cmd "mysqladmin ping -proot"
          --health-interval 10s
          --health-timeout 5s
          --health-retries 5

    defaults:
      run:
        working-directory: backend

    steps:
      - uses: actions/checkout@v4

      - name: Set up PHP
        uses: shivammathur/setup-php@v2
        with:
          php-version: '8.3'
          extensions: intl, pdo_sqlite, pdo_mysql
          coverage: none

      - name: Install dependencies
        run: composer install --prefer-dist --no-progress

      - name: PHPCS (PSR-12)
        run: composer cs

      - name: Warm cache for PHPStan
        run: php bin/console cache:warmup

      - name: PHPStan
        run: composer stan

      - name: Select database DSN
        run: |
          if [ "${{ matrix.database }}" = "mysql" ]; then
            echo 'DATABASE_URL=mysql://root:root@127.0.0.1:3306/feedreader_test?serverVersion=8.4&charset=utf8mb4' >> "$GITHUB_ENV"
          fi

      - name: PHPUnit
        run: php bin/phpunit
```

(No `DATABASE_URL` export for the sqlite leg — `.env.test` already points to the file-based SQLite DB. For the mysql leg, the env var overrides it; `DbTestCase` creates the schema either way.)

- [ ] **Step 2: Commit and push**

```bash
git add .github/workflows/ci.yml
git commit -m "ci: add backend CI with SQLite/MySQL matrix"
git push
```

- [ ] **Step 3: Verify both matrix legs are green**

Run from repo root: `gh run watch` (or `gh run list --limit 1` and inspect).
Expected: both `Backend (sqlite)` and `Backend (mysql)` succeed. If the MySQL leg fails on schema creation, the likely cause is the unique-index length note in Task 6 — fix the column length, not the index.

---

## Verification checklist (end of plan)

- [ ] `php bin/phpunit` green locally (SQLite)
- [ ] `composer check` green (PHPCS + PHPStan max)
- [ ] CI green on both matrix legs — this is the proof of the spec's DB-portability requirement
- [ ] `curl` of a locally served `/api/health` returns `{"status":"ok"}` (optional: `php -S localhost:8000 -t public` from `backend/`)

## Explicitly deferred (later plans)

- Production Doctrine **migrations** — generated against MySQL in Plan 6 (deployment), when a MySQL target exists; dev/test use SchemaTool by design until then
- `User` implementing `UserInterface`/`PasswordAuthenticatedUserInterface` — Plan 3 (auth) adds the interfaces to the existing entity
- Frontend CI job — Plan 5
- README badges — Plan 6
