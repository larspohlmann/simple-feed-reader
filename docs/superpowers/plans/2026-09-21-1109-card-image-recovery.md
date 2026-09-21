# Card-Image Recovery (https-upgrade + background verify) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Recover card `image_url`s that go NULL despite a usable body image, by upgrading `http`→`https` optimistically at ingest and confirming reachability and real dimensions in a bounded background step, without ever showing a broken or beacon-sized card image.

**Architecture:** Ingest stays fast: the body-image extractor becomes a real DOM parse that also captures declared dimensions, the selector prefers a native-https source over an http one, and any surviving `http`/protocol-relative URL is rewritten to `https` and persisted **optimistically** (marked *pending*). A new bounded step inside `MaintenanceTick` (Strato's single cron entry point) downloads each pending image through the existing SSRF-guarded fetcher, measures its real pixels, and either stores the measured dimensions, drops the URL (unreachable after retries, non-image, or a ≤100px-both-edges beacon), or leaves it for a later tick. `image_url` therefore stays a known-good, measured value for every consumer (list cards, magazine planner, `hasImages`, digest email, a future iOS client). Existing rows are grandfathered out of the queue by the migration, keeping shipped behavior forward-only.

**Tech Stack:** Symfony 7.4 LTS (PHP 8.4), Doctrine ORM with an embedded `EntryImage` value object, MySQL (prod/Docker) + SQLite (native tests), `\Dom\HTMLDocument` (lexbor) HTML parsing, GD `getimagesizefromstring` for dimensions, PHPUnit 12.

**Spec:** GitHub issue #1109 (`Closes #1109`). Memory: `memory/1109-card-image-recovery-https-upgrade-verify.md`. Supersedes #1108 (closed).

## Global Constraints

- **Clean Code is mandatory** (CLAUDE.md): intent-revealing names, one-thing functions, guard clauses, no boolean-flag params, `final readonly` by default, depend on interfaces, typed namespaced exceptions, comments only for a genuinely non-obvious invariant. One line, three at the absolute most, per statement/comment.
- **Every `src` file you touch must be PHPMD-clean** before commit (`composer md`), not merely free of new findings.
- **Gates:** `composer check` (PSR-12 `composer cs`, PHPStan level max `composer stan`, phptramp `composer tramp`) + `composer md` + PhpStorm inspections (`mcp__phpstorm__lint_files`, block on ERROR/WARNING) on changed PHP. `declare(strict_types=1)` in every file.
- **Mutation testing gates changed files:** `composer infection:diff` must stay at or above `minMsi` in `infection.json5`. Escaped mutants arrive as PR annotations.
- **Datetimes are stored as naive UTC.** Obtain a persisted "now" only via `App\Service\Clock\NaiveUtcClock::now()`. Never `new \DateTimeImmutable('now', ...)` on a persistence path.
- **Migrations need their own verification.** Tests build schema from ORM metadata and never run a migration; a broken migration passes the suite green. Write the migration platform-branched (MySQL + SQLite) exactly like `migrations/Version20260916092710.php`, and verify it by migrating from empty on both dialects.
- **Native iOS client stays viable:** JSON only, bearer auth, no browser-coupled endpoint. This plan adds no client-facing endpoint and changes no wire shape (`imageUrl`/`imageWidth`/`imageHeight` keys are unchanged; their values just become measured).
- **Tests are production code** — same naming and standards. No `#[DataProvider]` is used in the touched files; keep plain `testX(): void` methods. Unit tests extend `PHPUnit\Framework\TestCase`; DB tests extend `App\Tests\DbTestCase`.
- **Commit format** `type(#1109): summary`. **No attribution lines** in commits or the PR body.
- **git-flow:** work on `feature/1109-card-image-recovery` off `develop`; PR into `develop` with `Closes #1109`.
- **Prose replies to the user** use ASD-STE100 Simplified Technical English. This does not apply to code, comments, or commit messages.

## Interfaces at a glance (names later tasks rely on)

- `App\Service\Url\HttpsImageUrl::orNullUpgrading(?string $url): ?string` — Task 2.
- `App\Service\Parser\ItemImageExtractor::fromHtml(?string $html): ?DeclaredImage` — DOM parse + declared dims, Task 3.
- `App\Service\Parser\FeedItemImageSelector::fromRss2(\DOMElement, ?string): ?DeclaredImage` / `fromAtom(\DOMElement, string, list<?string>): ?DeclaredImage` — prefer native https, Task 4.
- `App\Entity\EntryImage`: `storePending(?string,?int,?int)`, `storeVerified(?string,?int,?int,\DateTimeImmutable)`, `recordMeasurement(int,int,\DateTimeImmutable)`, `drop()`, `recordFailedProbe()`, `getUrl()`, `getWidth()`, `getHeight()`, `getCheckedAt(): ?\DateTimeImmutable`, `getVerifyAttempts(): int` — Task 1.
- `App\Entity\Entry::getImage(): EntryImage` — Task 1.
- `App\Service\Image\ImageDimensions::fromBytes(string): ?self` with `int $width`, `int $height`, `bothEdgesAtMost(int): bool` — Task 6.
- `App\Service\Image\ImageVerifyOutcome` enum: `Measured`, `Dropped`, `Retried` — Task 7.
- `App\Service\Image\ImageVerifier::verify(EntryImage): ImageVerifyOutcome` — Task 7.
- `App\Repository\EntryRepository::findPendingImageVerification(int $limit): list<Entry>` — Task 8.
- `App\Service\Image\ImageVerificationReport(int $measured,int $dropped,int $retried)` with `toArray(): array{measured:int,dropped:int,retried:int}` — Task 9.
- `App\Service\Image\ImageVerificationSweep::verifyDue(): ImageVerificationReport` — Task 9.
- `App\Tests\Support\StubFaviconFetcher` (test double for `CatalogFaviconFetcherInterface`) — Task 7.

---

## Task 0: Branch

- [ ] **Step 1: Create the feature branch off develop**

Check no other session is mid-edit first (concurrent-checkout rule), then:

```bash
cd /Users/lars/Documents/work/eigenes/simple-feed-reader
git checkout develop && git pull --ff-only
git checkout -b feature/1109-card-image-recovery
```

---

## Task 1: `EntryImage` verification state + migration

Add the persisted verification lifecycle to the embedded image value object, expose it on `Entry` via `getImage()`, and add the two columns with a grandfathering migration.

**Files:**
- Modify: `backend/src/Entity/EntryImage.php`
- Modify: `backend/src/Entity/Entry.php`
- Create: `backend/migrations/Version<generated>.php`

**Interfaces:**
- Produces: `EntryImage::storePending/storeVerified/recordMeasurement/drop/recordFailedProbe/getCheckedAt/getVerifyAttempts`; `Entry::getImage(): EntryImage`. Columns `image_checked_at` (nullable datetime), `image_verify_attempts` (nullable int).

- [ ] **Step 1: Rewrite the `EntryImage` embeddable**

Replace the whole class body of `backend/src/Entity/EntryImage.php` with (keep the file's existing class-level docblock, adjust its last sentence about `set()`):

```php
<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * An entry's lead image: the URL, the dimensions, and its verification state.
 *
 * Embedded into Entry rather than three of its own scalar columns — these
 * values are stamped and read together and mean nothing apart. The image is
 * stored optimistically at ingest (checkedAt null = pending); a bounded
 * background step then downloads it, records the measured dimensions and
 * stamps checkedAt, or drops an unreachable/beacon image. The column names are
 * unprefixed and stated explicitly, so the table is unchanged.
 */
#[ORM\Embeddable]
class EntryImage
{
    #[ORM\Column(name: 'image_url', length: 2048, nullable: true)]
    private ?string $url = null;

    #[ORM\Column(name: 'image_width', nullable: true)]
    private ?int $width = null;

    #[ORM\Column(name: 'image_height', nullable: true)]
    private ?int $height = null;

    /** Null marks an image awaiting background verification; a value marks it settled. */
    #[ORM\Column(name: 'image_checked_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $checkedAt = null;

    #[ORM\Column(name: 'image_verify_attempts', nullable: true)]
    private ?int $verifyAttempts = null;

    public function storePending(?string $url, ?int $width, ?int $height): void
    {
        $this->url = $url;
        $this->width = $width;
        $this->height = $height;
        $this->checkedAt = null;
        $this->verifyAttempts = null;
    }

    public function storeVerified(?string $url, ?int $width, ?int $height, \DateTimeImmutable $checkedAt): void
    {
        $this->url = $url;
        $this->width = $width;
        $this->height = $height;
        $this->checkedAt = $checkedAt;
        $this->verifyAttempts = null;
    }

    public function recordMeasurement(int $width, int $height, \DateTimeImmutable $checkedAt): void
    {
        $this->width = $width;
        $this->height = $height;
        $this->checkedAt = $checkedAt;
    }

    public function drop(): void
    {
        $this->url = null;
        $this->width = null;
        $this->height = null;
    }

    public function recordFailedProbe(): void
    {
        $this->verifyAttempts = ($this->verifyAttempts ?? 0) + 1;
    }

    public function getUrl(): ?string
    {
        return $this->url;
    }

    public function getWidth(): ?int
    {
        return $this->width;
    }

    public function getHeight(): ?int
    {
        return $this->height;
    }

    public function getCheckedAt(): ?\DateTimeImmutable
    {
        return $this->checkedAt;
    }

    public function getVerifyAttempts(): int
    {
        return $this->verifyAttempts ?? 0;
    }
}
```

- [ ] **Step 2: Replace `Entry::setImage()` with `Entry::getImage()`**

First confirm `setImage(` has no callers outside `EntryIngestor`:

```bash
cd backend && grep -rn '\->setImage(' src tests
```

Expected: only occurrences inside `src/Service/Ingest/EntryIngestor.php` (rewritten in Task 5). If any other caller exists, stop and reconsider — do not remove `setImage` blindly.

In `backend/src/Entity/Entry.php`, keep `getImageUrl()`, `getImageWidth()`, `getImageHeight()` unchanged, and replace the `setImage(...)` method (lines 190-193) with:

```php
    public function getImage(): EntryImage
    {
        return $this->image;
    }
```

- [ ] **Step 3: Generate the migration file skeleton**

```bash
cd backend && php bin/console doctrine:migrations:generate
```

Note the created path `migrations/Version<TS>.php` and its class name; keep that class name.

- [ ] **Step 4: Write the migration body**

Replace the generated file's body with this (keep the generated `Version<TS>` class name). It adds both columns and **grandfathers every existing image out of the verification queue** by stamping a sentinel `image_checked_at`, so only images written after deploy are pending:

```php
<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Add the image verification columns (#1109): image_checked_at (null =
 * awaiting the background verify) and image_verify_attempts (transient-failure
 * retry counter). Existing images are stamped with a sentinel checked_at so
 * only images written after this deploy enter the verification queue — the
 * shipped behavior is forward-only. PLATFORM-AWARE DDL — tests build schema
 * from ORM metadata and never run a migration, so a dialect error here is
 * caught only by CI's migrate-from-empty leg.
 */
final class Version<TS> extends AbstractMigration
{
    private const string GRANDFATHER_SENTINEL = '1970-01-01 00:00:00';

    public function getDescription(): string
    {
        return 'Add entry image verification columns and grandfather existing images (#1109).';
    }

    public function up(Schema $schema): void
    {
        $platform = $this->assertSupportedPlatform();

        if ($platform instanceof AbstractMySQLPlatform) {
            $this->addSql('ALTER TABLE entry ADD image_checked_at DATETIME DEFAULT NULL, ADD image_verify_attempts INT DEFAULT NULL');
        } else {
            $this->addSql('ALTER TABLE entry ADD COLUMN image_checked_at DATETIME DEFAULT NULL');
            $this->addSql('ALTER TABLE entry ADD COLUMN image_verify_attempts INTEGER DEFAULT NULL');
        }

        $this->addSql(
            'UPDATE entry SET image_checked_at = :sentinel WHERE image_url IS NOT NULL',
            ['sentinel' => self::GRANDFATHER_SENTINEL],
        );
    }

    public function down(Schema $schema): void
    {
        $platform = $this->assertSupportedPlatform();

        if ($platform instanceof AbstractMySQLPlatform) {
            $this->addSql('ALTER TABLE entry DROP image_checked_at, DROP image_verify_attempts');

            return;
        }

        $this->addSql('ALTER TABLE entry DROP COLUMN image_checked_at');
        $this->addSql('ALTER TABLE entry DROP COLUMN image_verify_attempts');
    }

    public function isTransactional(): bool
    {
        return false;
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

- [ ] **Step 5: Verify the schema matches metadata and the migration runs on both dialects**

SQLite (native), migrate from empty against a scratch DB and validate:

```bash
cd backend
rm -f var/plan1109.sqlite
DATABASE_URL="sqlite:///%kernel.project_dir%/var/plan1109.sqlite" php bin/console doctrine:migrations:migrate --no-interaction
DATABASE_URL="sqlite:///%kernel.project_dir%/var/plan1109.sqlite" php bin/console doctrine:schema:validate
rm -f var/plan1109.sqlite
```
Expected: migrations run clean; `schema:validate` reports the mapping and database are in sync.

MySQL leg (Docker):

```bash
docker compose exec php bin/console doctrine:migrations:migrate --no-interaction
docker compose exec php bin/console doctrine:schema:validate
```
Expected: clean, in sync.

- [ ] **Step 6: Verify grandfathering data update**

Confirm the sentinel stamps only rows that have an image URL:

```bash
cd backend
rm -f var/plan1109gf.sqlite
DATABASE_URL="sqlite:///%kernel.project_dir%/var/plan1109gf.sqlite" php bin/console doctrine:migrations:migrate prev --no-interaction 2>/dev/null || true
# Build schema up to the migration BEFORE this one, seed a row with an image, then migrate:
DATABASE_URL="sqlite:///%kernel.project_dir%/var/plan1109gf.sqlite" php -r '
require "vendor/autoload.php";
// simplest check: after full migrate, insert an image row via console SQL is not available;
// instead assert the column exists and defaults to NULL for a fresh insert.
'
rm -f var/plan1109gf.sqlite
```
If a scratch seed is impractical, verify the grandfather intent by reading the migration SQL back with `php bin/console doctrine:migrations:migrate --no-interaction --dry-run` on the Docker MySQL stack and confirming the `UPDATE entry SET image_checked_at = ... WHERE image_url IS NOT NULL` statement is emitted. Record the dry-run output line in the commit body.

- [ ] **Step 7: Run the suite to prove the metadata change did not break persistence**

```bash
cd backend && php bin/phpunit --filter=EntryIngestor
```
Expected: PASS (Task 5 tests come later; existing image tests still pass because `storePending`/`storeVerified` cover the same columns — note `testHttpImageUrlIsDroppedAsMixedContent` will be updated in Task 5).

- [ ] **Step 8: Commit**

```bash
cd backend && git add src/Entity/EntryImage.php src/Entity/Entry.php migrations/
git commit -m "feat(#1109): add entry image verification columns and grandfather existing images"
```

---

## Task 2: `HttpsImageUrl` optimistic upgrade

Add a scheme-upgrading variant for card images; leave the strict `orNull` (used by feed logos) untouched.

**Files:**
- Modify: `backend/src/Service/Url/HttpsImageUrl.php`
- Create: `backend/tests/Service/Url/HttpsImageUrlTest.php`

**Interfaces:**
- Produces: `HttpsImageUrl::orNullUpgrading(?string $url): ?string`.
- Consumes: `HttpsImageUrl::MAX_LENGTH` (existing).

- [ ] **Step 1: Write the failing test**

Create `backend/tests/Service/Url/HttpsImageUrlTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Service\Url;

use App\Service\Url\HttpsImageUrl;
use PHPUnit\Framework\TestCase;

final class HttpsImageUrlTest extends TestCase
{
    public function testUpgradesHttpToHttps(): void
    {
        self::assertSame('https://i/x.jpg', HttpsImageUrl::orNullUpgrading('http://i/x.jpg'));
    }

    public function testUpgradesProtocolRelativeToHttps(): void
    {
        self::assertSame('https://i/x.jpg', HttpsImageUrl::orNullUpgrading('//i/x.jpg'));
    }

    public function testKeepsNativeHttps(): void
    {
        self::assertSame('https://i/x.jpg', HttpsImageUrl::orNullUpgrading('https://i/x.jpg'));
    }

    public function testRejectsDataUri(): void
    {
        self::assertNull(HttpsImageUrl::orNullUpgrading('data:image/png;base64,AAAA'));
    }

    public function testRejectsSiteRelative(): void
    {
        self::assertNull(HttpsImageUrl::orNullUpgrading('/img/x.jpg'));
    }

    public function testRejectsJavascriptScheme(): void
    {
        self::assertNull(HttpsImageUrl::orNullUpgrading('javascript:alert(1)'));
    }

    public function testRejectsNull(): void
    {
        self::assertNull(HttpsImageUrl::orNullUpgrading(null));
    }

    public function testRejectsAnUpgradedUrlOverTheLengthLimit(): void
    {
        $overlong = 'http://i/' . str_repeat('u', HttpsImageUrl::MAX_LENGTH) . '.jpg';
        self::assertNull(HttpsImageUrl::orNullUpgrading($overlong));
    }

    public function testDoesNotChangeTheStrictGate(): void
    {
        self::assertNull(HttpsImageUrl::orNull('http://i/x.jpg'));
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

```bash
cd backend && php bin/phpunit --filter=HttpsImageUrlTest
```
Expected: FAIL — `orNullUpgrading` undefined.

- [ ] **Step 3: Implement `orNullUpgrading`**

In `backend/src/Service/Url/HttpsImageUrl.php`, add below `orNull()` (do not modify `orNull`). Extend the class docblock with one sentence noting the upgrading variant for card images:

```php
    /**
     * The card-image variant of {@see orNull}: an http:// URL is rewritten to
     * https:// rather than rejected, because the same asset is very often
     * reachable over https; the background verify then confirms it. data:,
     * site-relative and javascript: URLs still have no scheme to upgrade and
     * are dropped, and the length gate is unchanged.
     */
    public static function orNullUpgrading(?string $url): ?string
    {
        if ($url === null) {
            return null;
        }

        $upgraded = self::toHttps($url);
        if ($upgraded === null) {
            return null;
        }

        return mb_strlen($upgraded) > self::MAX_LENGTH ? null : $upgraded;
    }

    private static function toHttps(string $url): ?string
    {
        if (str_starts_with($url, '//')) {
            return 'https:' . $url;
        }
        if (str_starts_with($url, 'https://')) {
            return $url;
        }
        if (str_starts_with($url, 'http://')) {
            return 'https://' . substr($url, 7);
        }

        return null;
    }
```

- [ ] **Step 4: Run the test to verify it passes**

```bash
cd backend && php bin/phpunit --filter=HttpsImageUrlTest
```
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
cd backend && git add src/Service/Url/HttpsImageUrl.php tests/Service/Url/HttpsImageUrlTest.php
git commit -m "feat(#1109): add optimistic http-to-https upgrade for card images"
```

---

## Task 3: `ItemImageExtractor::fromHtml` DOM parse + declared dimensions

Replace the quote-requiring regex with a real DOM parse that also captures declared `width`/`height`.

**Files:**
- Modify: `backend/src/Service/Parser/ItemImageExtractor.php`
- Modify: `backend/tests/Service/Parser/ItemImageExtractorTest.php`

**Interfaces:**
- Produces: `ItemImageExtractor::fromHtml(?string $html): ?DeclaredImage` — now DOM-based, dims captured.
- Consumes: `App\Service\Html\HtmlDocumentParser::parseOrNull(string): ?\Dom\HTMLDocument`.

- [ ] **Step 1: Add failing tests**

Append these methods to `backend/tests/Service/Parser/ItemImageExtractorTest.php` (before the closing brace):

```php
    public function testReadsAnInlineImgWithAnUnquotedSrc(): void
    {
        $image = ItemImageExtractor::fromHtml('<p>x</p><img width=287 height=107 src=https://i/webp.webp>');

        self::assertNotNull($image);
        self::assertSame('https://i/webp.webp', $image->url);
        self::assertSame(287, $image->width);
        self::assertSame(107, $image->height);
    }

    public function testCapturesDeclaredDimensionsFromAnInlineImg(): void
    {
        $image = ItemImageExtractor::fromHtml('<img src="https://i/a.jpg" width="640" height="360">');

        self::assertNotNull($image);
        self::assertSame(640, $image->width);
        self::assertSame(360, $image->height);
    }

    public function testIgnoresNonIntegerDimensionAttributes(): void
    {
        $image = ItemImageExtractor::fromHtml('<img src="https://i/a.jpg" width="100%" height="auto">');

        self::assertNotNull($image);
        self::assertNull($image->width);
        self::assertNull($image->height);
    }

    public function testSkipsALeadingImgWithoutASrcAndTakesTheNext(): void
    {
        $image = ItemImageExtractor::fromHtml('<img alt="spacer"><img src="https://i/real.jpg">');

        self::assertNotNull($image);
        self::assertSame('https://i/real.jpg', $image->url);
    }

    public function testReturnsNullWhenTheHtmlHasNoImg(): void
    {
        self::assertNull(ItemImageExtractor::fromHtml('<p>just words</p>'));
    }
```

- [ ] **Step 2: Run to verify the unquoted-src test fails**

```bash
cd backend && php bin/phpunit --filter=testReadsAnInlineImgWithAnUnquotedSrc
```
Expected: FAIL — the current regex requires quotes, so it returns null.

- [ ] **Step 3: Rewrite `fromHtml`**

In `backend/src/Service/Parser/ItemImageExtractor.php`: add `use App\Service\Html\HtmlDocumentParser;` under the existing `use App\Service\Image\DeclaredImage;`. Replace the `fromHtml` method (and update its one-line docblock — dimensions ARE now captured):

```php
    /** First <img src="…"> in a fragment of HTML, with the dimensions it declares. */
    public static function fromHtml(?string $html): ?DeclaredImage
    {
        if ($html === null || $html === '') {
            return null;
        }
        $document = HtmlDocumentParser::parseOrNull($html);
        if ($document === null) {
            return null;
        }
        foreach ($document->getElementsByTagName('img') as $image) {
            $src = trim($image->getAttribute('src'));
            if ($src !== '') {
                return new DeclaredImage(
                    $src,
                    self::positiveInt($image->getAttribute('width')),
                    self::positiveInt($image->getAttribute('height')),
                );
            }
        }

        return null;
    }
```

Note: `positiveInt()` already exists and returns null for `"100%"`/`"auto"` (FILTER_VALIDATE_INT). The lexbor DOM returns already-decoded attribute values, so no `html_entity_decode` is needed. Remove the now-unused regex.

- [ ] **Step 4: Run the extractor tests**

```bash
cd backend && php bin/phpunit --filter=ItemImageExtractorTest
```
Expected: PASS, including the pre-existing `testReadsAnInlineImgWithoutDimensions`.

- [ ] **Step 5: Commit**

```bash
cd backend && git add src/Service/Parser/ItemImageExtractor.php tests/Service/Parser/ItemImageExtractorTest.php
git commit -m "feat(#1109): parse inline feed images via DOM and capture declared dimensions"
```

---

## Task 4: `FeedItemImageSelector` prefers a native-https source

Fix the short-circuit that lets an http enclosure win over an https body image (Smashing). Prefer the first native-https candidate in precedence order; fall back to the first http candidate only when no https one exists.

**Files:**
- Modify: `backend/src/Service/Parser/FeedItemImageSelector.php`
- Create: `backend/tests/Service/Parser/FeedItemImageSelectorTest.php`

**Interfaces:**
- Produces: `FeedItemImageSelector::fromRss2(\DOMElement, ?string): ?DeclaredImage`, `fromAtom(\DOMElement, string, list<?string>): ?DeclaredImage` — signatures unchanged; ranking changed.

- [ ] **Step 1: Write the failing test**

Create `backend/tests/Service/Parser/FeedItemImageSelectorTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Service\Parser;

use App\Service\Parser\FeedItemImageSelector;
use PHPUnit\Framework\TestCase;

final class FeedItemImageSelectorTest extends TestCase
{
    private function rss2Item(string $innerXml): \DOMElement
    {
        $doc = new \DOMDocument();
        /** @noinspection XmlUnusedNamespaceDeclaration */
        $rss = '<rss xmlns:media="http://search.yahoo.com/mrss/"><channel><item>'
            . $innerXml . '</item></channel></rss>';
        $doc->loadXML($rss);
        $item = $doc->getElementsByTagName('item')->item(0);
        self::assertInstanceOf(\DOMElement::class, $item);

        return $item;
    }

    public function testPrefersAnHttpsBodyImageOverAnHttpEnclosure(): void
    {
        $item = $this->rss2Item('<enclosure url="http://files.example/e.jpg" type="image/jpeg" length="0"/>');
        $body = '<p>x</p><img src="https://files.example/e.jpg" width="900" height="600">';

        $image = FeedItemImageSelector::fromRss2($item, $body);

        self::assertNotNull($image);
        self::assertSame('https://files.example/e.jpg', $image->url);
        self::assertSame(900, $image->width);
    }

    public function testFallsBackToTheHttpEnclosureWhenNoHttpsCandidateExists(): void
    {
        $item = $this->rss2Item('<enclosure url="http://files.example/e.jpg" type="image/jpeg" length="0"/>');

        $image = FeedItemImageSelector::fromRss2($item, '<p>no image here</p>');

        self::assertNotNull($image);
        self::assertSame('http://files.example/e.jpg', $image->url);
    }

    public function testReturnsAnHttpBodyImageWhenThatIsAllThereIs(): void
    {
        $image = FeedItemImageSelector::fromRss2(
            $this->rss2Item('<description>no media</description>'),
            '<img src="http://www.techmeme.com/x/i1.jpg" width="134" height="76">',
        );

        self::assertNotNull($image);
        self::assertSame('http://www.techmeme.com/x/i1.jpg', $image->url);
    }

    public function testReturnsNullWhenNoSourceYieldsAnImage(): void
    {
        self::assertNull(FeedItemImageSelector::fromRss2(
            $this->rss2Item('<description>nothing</description>'),
            '<p>words only</p>',
        ));
    }

    public function testKeepsNativeHttpsMediaImmediately(): void
    {
        $item = $this->rss2Item('<media:content url="https://i/big.jpg" medium="image" width="700"/>');

        $image = FeedItemImageSelector::fromRss2($item, '<img src="https://i/body.jpg">');

        self::assertNotNull($image);
        self::assertSame('https://i/big.jpg', $image->url);
    }
}
```

- [ ] **Step 2: Run to verify it fails**

```bash
cd backend && php bin/phpunit --filter=FeedItemImageSelectorTest
```
Expected: FAIL — `testPrefersAnHttpsBodyImageOverAnHttpEnclosure` returns the http enclosure today.

- [ ] **Step 3: Rewrite the selector**

Replace the whole body of `backend/src/Service/Parser/FeedItemImageSelector.php`:

```php
<?php

declare(strict_types=1);

namespace App\Service\Parser;

use App\Service\Image\DeclaredImage;

/**
 * Chooses one image for a feed item across its sources, in precedence order
 * (Media RSS, format enclosure, custom <image>, inline body <img>). A source
 * that is already https wins over an earlier http one, because the http URL is
 * only upgraded optimistically and may not be reachable; the first http
 * candidate is the fallback when nothing native-https is found.
 */
final class FeedItemImageSelector
{
    public static function fromRss2(\DOMElement $item, ?string $bodyHtml): ?DeclaredImage
    {
        return self::preferNativeHttps([
            static fn (): ?DeclaredImage => ItemImageExtractor::fromMedia($item),
            static fn (): ?DeclaredImage => ItemImageExtractor::fromRssEnclosure($item),
            static fn (): ?DeclaredImage => ItemImageExtractor::fromCustomImageElement($item),
            static fn (): ?DeclaredImage => ItemImageExtractor::fromHtml($bodyHtml),
        ]);
    }

    /** @param list<?string> $bodyHtmlCandidates */
    public static function fromAtom(\DOMElement $entry, string $namespace, array $bodyHtmlCandidates): ?DeclaredImage
    {
        $sources = [
            static fn (): ?DeclaredImage => ItemImageExtractor::fromMedia($entry),
            static fn (): ?DeclaredImage => ItemImageExtractor::fromAtomEnclosure($entry, $namespace),
            static fn (): ?DeclaredImage => ItemImageExtractor::fromCustomImageElement($entry),
        ];
        foreach ($bodyHtmlCandidates as $bodyHtml) {
            $sources[] = static fn (): ?DeclaredImage => ItemImageExtractor::fromHtml($bodyHtml);
        }

        return self::preferNativeHttps($sources);
    }

    /** @param list<callable(): ?DeclaredImage> $sources */
    private static function preferNativeHttps(array $sources): ?DeclaredImage
    {
        $upgradeCandidate = null;
        foreach ($sources as $source) {
            $image = $source();
            if ($image === null) {
                continue;
            }
            if (self::isNativeHttps($image->url)) {
                return $image;
            }
            $upgradeCandidate ??= $image;
        }

        return $upgradeCandidate;
    }

    private static function isNativeHttps(string $url): bool
    {
        return str_starts_with($url, 'https://') || str_starts_with($url, '//');
    }
}
```

Note the laziness: each source is a closure, so `fromMedia` returning a native-https image returns immediately and the body HTML is never parsed. The body is parsed only when the earlier sources are empty or http.

- [ ] **Step 4: Run the selector tests, then the whole parser suite (behavior change)**

```bash
cd backend && php bin/phpunit --filter=FeedItemImageSelectorTest
php bin/phpunit tests/Service/Parser
```
Expected: PASS. If a parser/integration test asserted a prior http-over-https pick, update that assertion to the new native-https preference and note it in the commit.

- [ ] **Step 5: Commit**

```bash
cd backend && git add src/Service/Parser/FeedItemImageSelector.php tests/Service/Parser/FeedItemImageSelectorTest.php
git commit -m "feat(#1109): prefer a native-https feed image over an http one"
```

---

## Task 5: `EntryIngestor` optimistic upgrade + pending/trusted write

Route both ingest write paths through the upgrading gate; mark an image trusted (verified at ingest) only when it is native-https with both dimensions declared, otherwise pending.

**Files:**
- Modify: `backend/src/Service/Ingest/EntryIngestor.php`
- Modify: `backend/tests/Service/Ingest/EntryIngestorTest.php`
- Modify: `backend/tests/Service/Maintenance/MaintenanceTickTest.php` (constructor arity only)
- Check/modify: any other `new EntryIngestor(` construction site

**Interfaces:**
- Consumes: `HttpsImageUrl::orNullUpgrading` (Task 2), `Entry::getImage()` + `EntryImage::storePending/storeVerified` (Task 1), `App\Service\Clock\NaiveUtcClock`.

- [ ] **Step 1: Update / add tests for ingest behavior**

In `backend/tests/Service/Ingest/EntryIngestorTest.php`:

(a) Add the clock import and update `setUp()` to pass a 6th constructor argument. Add near the other `use` lines:
```php
use App\Service\Clock\NaiveUtcClock;
use Symfony\Component\Clock\MockClock;
```
Change the `new EntryIngestor(...)` in `setUp()` to:
```php
        $this->ingestor = new EntryIngestor(
            $this->em,
            $entryRepository,
            new EntrySanitizer(),
            new UrlNormalizer(),
            new EntryCategoryWriter($this->em, $categoryRepository, new CategoryNormalizer()),
            new NaiveUtcClock(new MockClock('2026-09-21 12:00:00')),
        );
```

(b) Replace `testHttpImageUrlIsDroppedAsMixedContent` with the inverted expectation:
```php
    public function testHttpImageUrlIsUpgradedToHttpsAndKept(): void
    {
        $feed = $this->feed();
        $this->ingestor->ingest($feed, new ParsedFeed('T', null, null, null, [
            $this->parsedEntryWithImage('http-image', new DeclaredImage('http://i.example.com/img.jpg', 400, 300)),
        ]), self::context());
        $this->em->flush();

        $entry = $this->em->getRepository(Entry::class)->findOneBy(['guid' => 'http-image']);
        self::assertNotNull($entry);
        self::assertSame('https://i.example.com/img.jpg', $entry->getImageUrl());
    }
```

(c) Add tests asserting the pending vs trusted state:
```php
    public function testAnHttpUpgradedImageIsLeftPendingVerification(): void
    {
        $feed = $this->feed();
        $this->ingestor->ingest($feed, new ParsedFeed('T', null, null, null, [
            $this->parsedEntryWithImage('http-pending', new DeclaredImage('http://i/x.jpg', 400, 300)),
        ]), self::context());
        $this->em->flush();

        $entry = $this->em->getRepository(Entry::class)->findOneBy(['guid' => 'http-pending']);
        self::assertNotNull($entry);
        self::assertNull($entry->getImage()->getCheckedAt());
    }

    public function testANativeHttpsImageWithDeclaredDimensionsIsTrustedAtIngest(): void
    {
        $feed = $this->feed();
        $this->ingestor->ingest($feed, new ParsedFeed('T', null, null, null, [
            $this->parsedEntryWithImage('https-trusted', new DeclaredImage('https://i/x.jpg', 400, 300)),
        ]), self::context());
        $this->em->flush();

        $entry = $this->em->getRepository(Entry::class)->findOneBy(['guid' => 'https-trusted']);
        self::assertNotNull($entry);
        self::assertNotNull($entry->getImage()->getCheckedAt());
    }

    public function testANativeHttpsImageWithoutDimensionsStaysPending(): void
    {
        $feed = $this->feed();
        $this->ingestor->ingest($feed, new ParsedFeed('T', null, null, null, [
            $this->parsedEntryWithImage('https-nodims', new DeclaredImage('https://i/x.jpg', null, null)),
        ]), self::context());
        $this->em->flush();

        $entry = $this->em->getRepository(Entry::class)->findOneBy(['guid' => 'https-nodims']);
        self::assertNotNull($entry);
        self::assertNull($entry->getImage()->getCheckedAt());
    }
```

`testProtocolRelativeImageUrlIsUpgradedToHttpsAndKept`, `testDataUriImageIsDropped`, `testSiteRelativeImageUrlIsDropped`, `testAnImageUrlOverTheColumnLimitIsDroppedRatherThanTruncated`, `testMissingImageLeavesTheColumnsNull` stay unchanged and must still pass.

- [ ] **Step 2: Run to verify the new/changed tests fail**

```bash
cd backend && php bin/phpunit --filter=EntryIngestor
```
Expected: FAIL — constructor arity (6th arg) and the inverted http test drive the change.

- [ ] **Step 3: Rewrite the ingestor write path**

In `backend/src/Service/Ingest/EntryIngestor.php`:

(a) Add `use App\Service\Clock\NaiveUtcClock;` with the other imports. Add the clock to the constructor:
```php
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly EntryRepository $entryRepository,
        private readonly EntrySanitizer $sanitizer,
        private readonly UrlNormalizer $urlNormalizer,
        private readonly EntryCategoryWriter $categoryWriter,
        private readonly NaiveUtcClock $clock,
    ) {
    }
```

(b) Replace `applyImage()` and `persistableImageUrl()` with a shared `storeImage()` and the trusted-at-ingest rule:
```php
    private function applyImage(Entry $entry, ?DeclaredImage $image): void
    {
        if ($image === null || !$this->storeImage($entry, $image)) {
            $entry->getImage()->storePending(null, null, null);
        }
    }

    private function storeImage(Entry $entry, DeclaredImage $image): bool
    {
        $url = HttpsImageUrl::orNullUpgrading($image->url);
        if ($url === null) {
            return false;
        }
        if (self::trustedAtIngest($image)) {
            $entry->getImage()->storeVerified($url, $image->width, $image->height, $this->clock->now());
        } else {
            $entry->getImage()->storePending($url, $image->width, $image->height);
        }

        return true;
    }

    private static function trustedAtIngest(DeclaredImage $image): bool
    {
        return $image->width !== null
            && $image->height !== null
            && (str_starts_with($image->url, 'https://') || str_starts_with($image->url, '//'));
    }
```

(c) In `fillMissingImages()`, replace the `persistableImageUrl` + `setImage` block with the shared writer:
```php
            $entry = $existing[self::guidHash($parsedEntry->guid)] ?? null;
            if ($entry === null || $entry->getImageUrl() !== null) {
                continue;
            }
            if ($this->storeImage($entry, $image)) {
                $updated++;
            }
```

- [ ] **Step 4: Update every other `new EntryIngestor(` site**

```bash
cd backend && grep -rn 'new EntryIngestor(' src tests
```
For each site (known: `tests/Service/Maintenance/MaintenanceTickTest.php` inside the by-hand `RefreshRunner`; possibly `tests/Service/Refresh/RefreshRunnerTest.php`), add the 6th argument `new NaiveUtcClock(new MockClock(...))` (import both classes if absent). In `MaintenanceTickTest` the by-hand test already imports `MockClock` and has a `$clock`; pass `new NaiveUtcClock($clock)` and add `use App\Service\Clock\NaiveUtcClock;`.

- [ ] **Step 5: Run ingest + refresh + maintenance tests**

```bash
cd backend && php bin/phpunit --filter=EntryIngestor
php bin/phpunit tests/Service/Refresh tests/Service/Maintenance
```
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
cd backend && git add src/Service/Ingest/EntryIngestor.php tests/Service/Ingest/EntryIngestorTest.php tests/Service/Maintenance/MaintenanceTickTest.php
# add any other construction site touched (e.g. tests/Service/Refresh/RefreshRunnerTest.php)
git commit -m "feat(#1109): upgrade and persist card images optimistically, marking them pending"
```

---

## Task 6: `ImageDimensions` value object

A small, testable reader of real pixel dimensions from image bytes.

**Files:**
- Create: `backend/src/Service/Image/ImageDimensions.php`
- Create: `backend/tests/Service/Image/ImageDimensionsTest.php`

**Interfaces:**
- Produces: `ImageDimensions::fromBytes(string $bytes): ?self`; `public int $width`, `public int $height`; `bothEdgesAtMost(int $edge): bool`.

- [ ] **Step 1: Write the failing test**

Create `backend/tests/Service/Image/ImageDimensionsTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Service\Image;

use App\Service\Image\ImageDimensions;
use PHPUnit\Framework\TestCase;

final class ImageDimensionsTest extends TestCase
{
    private function pngBytes(int $width, int $height): string
    {
        $image = imagecreatetruecolor($width, $height);
        ob_start();
        imagepng($image);
        imagedestroy($image);

        return (string) ob_get_clean();
    }

    public function testReadsWidthAndHeightFromPngBytes(): void
    {
        $dimensions = ImageDimensions::fromBytes($this->pngBytes(320, 200));

        self::assertNotNull($dimensions);
        self::assertSame(320, $dimensions->width);
        self::assertSame(200, $dimensions->height);
    }

    public function testReturnsNullForUndecodableBytes(): void
    {
        self::assertNull(ImageDimensions::fromBytes('this is not an image'));
    }

    public function testBothEdgesAtMostIsTrueForABeacon(): void
    {
        $dimensions = ImageDimensions::fromBytes($this->pngBytes(1, 1));

        self::assertNotNull($dimensions);
        self::assertTrue($dimensions->bothEdgesAtMost(100));
    }

    public function testBothEdgesAtMostIsFalseWhenOneEdgeExceeds(): void
    {
        $dimensions = ImageDimensions::fromBytes($this->pngBytes(134, 76));

        self::assertNotNull($dimensions);
        self::assertFalse($dimensions->bothEdgesAtMost(100));
    }
}
```

- [ ] **Step 2: Run to verify it fails**

```bash
cd backend && php bin/phpunit --filter=ImageDimensionsTest
```
Expected: FAIL — class not found.

- [ ] **Step 3: Implement**

Create `backend/src/Service/Image/ImageDimensions.php`:

```php
<?php

declare(strict_types=1);

namespace App\Service\Image;

/**
 * The real pixel dimensions of image bytes, read once via GD. Null when the
 * bytes are not a decodable image, which the caller treats as a failed probe.
 */
final readonly class ImageDimensions
{
    public function __construct(
        public int $width,
        public int $height,
    ) {
    }

    public static function fromBytes(string $bytes): ?self
    {
        $size = @getimagesizefromstring($bytes);
        if ($size === false) {
            return null;
        }

        return new self($size[0], $size[1]);
    }

    public function bothEdgesAtMost(int $edge): bool
    {
        return $this->width <= $edge && $this->height <= $edge;
    }
}
```

- [ ] **Step 4: Run to verify it passes**

```bash
cd backend && php bin/phpunit --filter=ImageDimensionsTest
```
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
cd backend && git add src/Service/Image/ImageDimensions.php tests/Service/Image/ImageDimensionsTest.php
git commit -m "feat(#1109): add ImageDimensions reader for verified image sizes"
```

---

## Task 7: `ImageVerifier` — verify one pending image

Download a pending image through the SSRF-guarded fetcher, measure it, and record the outcome on the `EntryImage`.

**Files:**
- Create: `backend/src/Service/Image/ImageVerifyOutcome.php`
- Create: `backend/src/Service/Image/ImageVerifier.php`
- Create: `backend/tests/Support/StubFaviconFetcher.php`
- Create: `backend/tests/Service/Image/ImageVerifierTest.php`

**Interfaces:**
- Produces: `ImageVerifyOutcome{Measured,Dropped,Retried}`; `ImageVerifier::verify(EntryImage $image): ImageVerifyOutcome`; `StubFaviconFetcher`.
- Consumes: `CatalogFaviconFetcherInterface::download(string): FetchedFavicon` (throws `FaviconUnavailableException`), `FetchedFavicon::$bytes`, `ImageDimensions` (Task 6), `EntryImage` (Task 1), `NaiveUtcClock`.

- [ ] **Step 1: Create the test double**

Create `backend/tests/Support/StubFaviconFetcher.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Service\Catalog\CatalogFaviconFetcherInterface;
use App\Service\Catalog\Exception\FaviconUnavailableException;
use App\Service\Catalog\FetchedFavicon;

final class StubFaviconFetcher implements CatalogFaviconFetcherInterface
{
    /** @var array<string, string|\Throwable> */
    private array $byUrl = [];
    private string|\Throwable|null $default = null;
    private string $contentType = 'image/png';

    public function willReturnBytes(string $url, string $bytes): void
    {
        $this->byUrl[$url] = $bytes;
    }

    public function willFail(string $url, \Throwable $error): void
    {
        $this->byUrl[$url] = $error;
    }

    public function willAlwaysReturn(string $bytes): void
    {
        $this->default = $bytes;
    }

    public function willAlwaysFail(\Throwable $error): void
    {
        $this->default = $error;
    }

    public function download(string $iconUrl): FetchedFavicon
    {
        $result = $this->byUrl[$iconUrl] ?? $this->default;
        if ($result === null) {
            throw new FaviconUnavailableException('no stub configured for ' . $iconUrl);
        }
        if ($result instanceof \Throwable) {
            throw $result;
        }

        return new FetchedFavicon($iconUrl, $result, $this->contentType);
    }
}
```

- [ ] **Step 2: Write the failing verifier test**

Create `backend/tests/Service/Image/ImageVerifierTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Service\Image;

use App\Entity\EntryImage;
use App\Service\Catalog\Exception\FaviconUnavailableException;
use App\Service\Clock\NaiveUtcClock;
use App\Service\Image\ImageVerifier;
use App\Service\Image\ImageVerifyOutcome;
use App\Tests\Support\StubFaviconFetcher;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

final class ImageVerifierTest extends TestCase
{
    private function pngBytes(int $width, int $height): string
    {
        $image = imagecreatetruecolor($width, $height);
        ob_start();
        imagepng($image);
        imagedestroy($image);

        return (string) ob_get_clean();
    }

    private function pendingImage(string $url): EntryImage
    {
        $image = new EntryImage();
        $image->storePending($url, null, null);

        return $image;
    }

    private function verifier(StubFaviconFetcher $fetcher): ImageVerifier
    {
        return new ImageVerifier($fetcher, new NaiveUtcClock(new MockClock('2026-09-21 12:00:00')));
    }

    public function testMeasuresAndStampsAReachableImage(): void
    {
        $fetcher = new StubFaviconFetcher();
        $fetcher->willReturnBytes('https://i/ok.png', $this->pngBytes(600, 400));
        $image = $this->pendingImage('https://i/ok.png');

        $outcome = $this->verifier($fetcher)->verify($image);

        self::assertSame(ImageVerifyOutcome::Measured, $outcome);
        self::assertSame(600, $image->getWidth());
        self::assertSame(400, $image->getHeight());
        self::assertNotNull($image->getCheckedAt());
    }

    public function testDropsABeacon(): void
    {
        $fetcher = new StubFaviconFetcher();
        $fetcher->willReturnBytes('https://i/pixel.png', $this->pngBytes(1, 1));
        $image = $this->pendingImage('https://i/pixel.png');

        $outcome = $this->verifier($fetcher)->verify($image);

        self::assertSame(ImageVerifyOutcome::Dropped, $outcome);
        self::assertNull($image->getUrl());
    }

    public function testKeepsAThumbnailWithOneEdgeOverTheCeiling(): void
    {
        $fetcher = new StubFaviconFetcher();
        $fetcher->willReturnBytes('https://i/thumb.png', $this->pngBytes(134, 76));
        $image = $this->pendingImage('https://i/thumb.png');

        $outcome = $this->verifier($fetcher)->verify($image);

        self::assertSame(ImageVerifyOutcome::Measured, $outcome);
        self::assertSame('https://i/thumb.png', $image->getUrl());
        self::assertSame(134, $image->getWidth());
    }

    public function testRetriesOnAFetchFailureBelowTheCap(): void
    {
        $fetcher = new StubFaviconFetcher();
        $fetcher->willFail('https://i/gone.png', new FaviconUnavailableException('timeout'));
        $image = $this->pendingImage('https://i/gone.png');

        $outcome = $this->verifier($fetcher)->verify($image);

        self::assertSame(ImageVerifyOutcome::Retried, $outcome);
        self::assertSame('https://i/gone.png', $image->getUrl());
        self::assertSame(1, $image->getVerifyAttempts());
        self::assertNull($image->getCheckedAt());
    }

    public function testDropsAfterTheRetryCapIsReached(): void
    {
        $fetcher = new StubFaviconFetcher();
        $fetcher->willFail('https://i/gone.png', new FaviconUnavailableException('timeout'));
        $image = $this->pendingImage('https://i/gone.png');
        $verifier = $this->verifier($fetcher);

        self::assertSame(ImageVerifyOutcome::Retried, $verifier->verify($image));
        self::assertSame(ImageVerifyOutcome::Retried, $verifier->verify($image));
        $outcome = $verifier->verify($image);

        self::assertSame(ImageVerifyOutcome::Dropped, $outcome);
        self::assertNull($image->getUrl());
    }

    public function testTreatsUndecodableBytesAsAFailedProbe(): void
    {
        $fetcher = new StubFaviconFetcher();
        $fetcher->willReturnBytes('https://i/broken.png', 'not an image');
        $image = $this->pendingImage('https://i/broken.png');

        $outcome = $this->verifier($fetcher)->verify($image);

        self::assertSame(ImageVerifyOutcome::Retried, $outcome);
        self::assertSame(1, $image->getVerifyAttempts());
    }
}
```

- [ ] **Step 3: Run to verify it fails**

```bash
cd backend && php bin/phpunit --filter=ImageVerifierTest
```
Expected: FAIL — enum/class not found.

- [ ] **Step 4: Implement the enum and the verifier**

Create `backend/src/Service/Image/ImageVerifyOutcome.php`:

```php
<?php

declare(strict_types=1);

namespace App\Service\Image;

enum ImageVerifyOutcome
{
    case Measured;
    case Dropped;
    case Retried;
}
```

Create `backend/src/Service/Image/ImageVerifier.php`:

```php
<?php

declare(strict_types=1);

namespace App\Service\Image;

use App\Entity\EntryImage;
use App\Service\Catalog\CatalogFaviconFetcherInterface;
use App\Service\Catalog\Exception\FaviconUnavailableException;
use App\Service\Clock\NaiveUtcClock;

/**
 * Confirms one optimistically-stored image: it downloads the URL through the
 * SSRF-guarded fetcher, measures the real pixels, and records the outcome on
 * the image. A reachable image keeps its URL with measured dimensions; a
 * beacon (both edges at or below the ceiling) is dropped; an unreachable or
 * undecodable image is retried a few ticks and then dropped, so a passing
 * network blip never nulls a good image.
 */
final readonly class ImageVerifier
{
    private const int BEACON_EDGE_CEILING = 100;
    private const int MAX_ATTEMPTS = 3;

    public function __construct(
        private CatalogFaviconFetcherInterface $fetcher,
        private NaiveUtcClock $clock,
    ) {
    }

    public function verify(EntryImage $image): ImageVerifyOutcome
    {
        $url = $image->getUrl();
        if ($url === null) {
            return ImageVerifyOutcome::Dropped;
        }

        try {
            $bytes = $this->fetcher->download($url)->bytes;
        } catch (FaviconUnavailableException) {
            return $this->recordFailure($image);
        }

        $dimensions = ImageDimensions::fromBytes($bytes);
        if ($dimensions === null) {
            return $this->recordFailure($image);
        }
        if ($dimensions->bothEdgesAtMost(self::BEACON_EDGE_CEILING)) {
            $image->drop();

            return ImageVerifyOutcome::Dropped;
        }

        $image->recordMeasurement($dimensions->width, $dimensions->height, $this->clock->now());

        return ImageVerifyOutcome::Measured;
    }

    private function recordFailure(EntryImage $image): ImageVerifyOutcome
    {
        if ($image->getVerifyAttempts() + 1 >= self::MAX_ATTEMPTS) {
            $image->drop();

            return ImageVerifyOutcome::Dropped;
        }

        $image->recordFailedProbe();

        return ImageVerifyOutcome::Retried;
    }
}
```

- [ ] **Step 5: Run to verify it passes**

```bash
cd backend && php bin/phpunit --filter=ImageVerifierTest
```
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
cd backend && git add src/Service/Image/ImageVerifyOutcome.php src/Service/Image/ImageVerifier.php tests/Support/StubFaviconFetcher.php tests/Service/Image/ImageVerifierTest.php
git commit -m "feat(#1109): add ImageVerifier for background reachability and size checks"
```

---

## Task 8: `EntryRepository::findPendingImageVerification`

Find a bounded batch of entries whose image awaits verification.

**Files:**
- Modify: `backend/src/Repository/EntryRepository.php`
- Create: `backend/tests/Repository/PendingImageVerificationTest.php`

**Interfaces:**
- Produces: `EntryRepository::findPendingImageVerification(int $limit): list<Entry>`.

- [ ] **Step 1: Write the failing test**

Create `backend/tests/Repository/PendingImageVerificationTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Repository;

use App\Entity\Entry;
use App\Entity\Feed;
use App\Repository\EntryRepository;
use App\Tests\DbTestCase;

final class PendingImageVerificationTest extends DbTestCase
{
    public function testReturnsOnlyEntriesWithAPendingImage(): void
    {
        $feed = $this->feed();
        $pending = $this->entry($feed, 'pending');
        $pending->getImage()->storePending('https://i/pending.jpg', null, null);
        $verified = $this->entry($feed, 'verified');
        $verified->getImage()->storeVerified('https://i/verified.jpg', 800, 600, new \DateTimeImmutable('2026-09-21 10:00:00'));
        $this->entry($feed, 'no-image');
        $this->em->flush();

        $found = $this->repository()->findPendingImageVerification(50);

        $guids = array_map(static fn (Entry $entry): string => $entry->getGuid(), $found);
        self::assertContains('pending', $guids);
        self::assertNotContains('verified', $guids);
        self::assertNotContains('no-image', $guids);
    }

    public function testHonoursTheLimit(): void
    {
        $feed = $this->feed();
        foreach (['a', 'b', 'c'] as $guid) {
            $this->entry($feed, $guid)->getImage()->storePending('https://i/' . $guid . '.jpg', null, null);
        }
        $this->em->flush();

        self::assertCount(2, $this->repository()->findPendingImageVerification(2));
    }

    private function feed(): Feed
    {
        $feed = new Feed('https://example.test/feed.xml');
        $this->em->persist($feed);

        return $feed;
    }

    private function entry(Feed $feed, string $guid): Entry
    {
        $entry = new Entry(
            $feed,
            $guid,
            'https://example.test/' . $guid,
            'Title ' . $guid,
            new \DateTimeImmutable('2026-09-21 06:00:00'),
            new \DateTimeImmutable('2026-09-21 05:00:00'),
        );
        $this->em->persist($entry);

        return $entry;
    }

    private function repository(): EntryRepository
    {
        $repository = $this->em->getRepository(Entry::class);
        self::assertInstanceOf(EntryRepository::class, $repository);

        return $repository;
    }
}
```

- [ ] **Step 2: Run to verify it fails**

```bash
cd backend && php bin/phpunit --filter=PendingImageVerificationTest
```
Expected: FAIL — method not defined.

- [ ] **Step 3: Add the finder**

In `backend/src/Repository/EntryRepository.php`, add (embeddable fields are addressed with the dotted path `e.image.*`):

```php
    /**
     * A bounded batch of entries whose image was stored optimistically and has
     * not been verified yet — the background verify's work queue.
     *
     * @return list<Entry>
     */
    public function findPendingImageVerification(int $limit): array
    {
        /** @var list<Entry> $entries */
        $entries = $this->createQueryBuilder('e')
            ->andWhere('e.image.url IS NOT NULL')
            ->andWhere('e.image.checkedAt IS NULL')
            ->orderBy('e.id', 'ASC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();

        return $entries;
    }
```

- [ ] **Step 4: Run to verify it passes**

```bash
cd backend && php bin/phpunit --filter=PendingImageVerificationTest
```
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
cd backend && git add src/Repository/EntryRepository.php tests/Repository/PendingImageVerificationTest.php
git commit -m "feat(#1109): add pending-image-verification finder"
```

---

## Task 9: `ImageVerificationSweep` — the bounded maintenance step

Drive the verifier over a capped batch per tick, flush once, and report counts.

**Files:**
- Create: `backend/src/Service/Image/ImageVerificationReport.php`
- Create: `backend/src/Service/Image/ImageVerificationSweep.php`
- Create: `backend/tests/Service/Image/ImageVerificationSweepTest.php`

**Interfaces:**
- Produces: `ImageVerificationReport(int,int,int)` with `toArray(): array{measured:int,dropped:int,retried:int}`; `ImageVerificationSweep::verifyDue(): ImageVerificationReport`.
- Consumes: `EntryRepository::findPendingImageVerification` (Task 8), `ImageVerifier::verify` (Task 7), `EntityManagerInterface`.

- [ ] **Step 1: Write the failing integration test**

Create `backend/tests/Service/Image/ImageVerificationSweepTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Service\Image;

use App\Entity\Entry;
use App\Entity\Feed;
use App\Repository\EntryRepository;
use App\Service\Clock\NaiveUtcClock;
use App\Service\Image\ImageVerificationSweep;
use App\Service\Image\ImageVerifier;
use App\Tests\DbTestCase;
use App\Tests\Support\StubFaviconFetcher;
use Symfony\Component\Clock\MockClock;

final class ImageVerificationSweepTest extends DbTestCase
{
    private function pngBytes(int $width, int $height): string
    {
        $image = imagecreatetruecolor($width, $height);
        ob_start();
        imagepng($image);
        imagedestroy($image);

        return (string) ob_get_clean();
    }

    private function sweep(StubFaviconFetcher $fetcher): ImageVerificationSweep
    {
        /** @var EntryRepository $repository */
        $repository = $this->em->getRepository(Entry::class);
        $verifier = new ImageVerifier($fetcher, new NaiveUtcClock(new MockClock('2026-09-21 12:00:00')));

        return new ImageVerificationSweep($repository, $verifier, $this->em);
    }

    private function pendingEntry(Feed $feed, string $guid, string $imageUrl): void
    {
        $entry = new Entry(
            $feed,
            $guid,
            'https://example.test/' . $guid,
            'Title ' . $guid,
            new \DateTimeImmutable('2026-09-21 06:00:00'),
            new \DateTimeImmutable('2026-09-21 05:00:00'),
        );
        $entry->getImage()->storePending($imageUrl, null, null);
        $this->em->persist($entry);
    }

    public function testMeasuresDropsAndReportsCounts(): void
    {
        $feed = new Feed('https://example.test/feed.xml');
        $this->em->persist($feed);
        $this->pendingEntry($feed, 'good', 'https://i/good.png');
        $this->pendingEntry($feed, 'beacon', 'https://i/beacon.png');
        $this->em->flush();

        $fetcher = new StubFaviconFetcher();
        $fetcher->willReturnBytes('https://i/good.png', $this->pngBytes(600, 400));
        $fetcher->willReturnBytes('https://i/beacon.png', $this->pngBytes(1, 1));

        $report = $this->sweep($fetcher)->verifyDue()->toArray();

        self::assertSame(1, $report['measured']);
        self::assertSame(1, $report['dropped']);
        self::assertSame(0, $report['retried']);

        $this->em->clear();
        $good = $this->em->getRepository(Entry::class)->findOneBy(['guid' => 'good']);
        self::assertNotNull($good);
        self::assertSame(600, $good->getImageWidth());
        self::assertNotNull($good->getImage()->getCheckedAt());
        $beacon = $this->em->getRepository(Entry::class)->findOneBy(['guid' => 'beacon']);
        self::assertNotNull($beacon);
        self::assertNull($beacon->getImageUrl());
    }

    public function testAVerifiedImageLeavesTheQueue(): void
    {
        $feed = new Feed('https://example.test/feed.xml');
        $this->em->persist($feed);
        $this->pendingEntry($feed, 'good', 'https://i/good.png');
        $this->em->flush();

        $fetcher = new StubFaviconFetcher();
        $fetcher->willAlwaysReturn($this->pngBytes(600, 400));
        $this->sweep($fetcher)->verifyDue();
        $this->em->clear();

        /** @var EntryRepository $repository */
        $repository = $this->em->getRepository(Entry::class);
        self::assertCount(0, $repository->findPendingImageVerification(50));
    }
}
```

- [ ] **Step 2: Run to verify it fails**

```bash
cd backend && php bin/phpunit --filter=ImageVerificationSweepTest
```
Expected: FAIL — classes not found.

- [ ] **Step 3: Implement the report and the sweep**

Create `backend/src/Service/Image/ImageVerificationReport.php`:

```php
<?php

declare(strict_types=1);

namespace App\Service\Image;

final readonly class ImageVerificationReport
{
    public function __construct(
        public int $measured,
        public int $dropped,
        public int $retried,
    ) {
    }

    /**
     * @return array{measured: int, dropped: int, retried: int}
     */
    public function toArray(): array
    {
        return [
            'measured' => $this->measured,
            'dropped' => $this->dropped,
            'retried' => $this->retried,
        ];
    }
}
```

Create `backend/src/Service/Image/ImageVerificationSweep.php`:

```php
<?php

declare(strict_types=1);

namespace App\Service\Image;

use App\Repository\EntryRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * One tick's image verification: a capped batch of pending images, each
 * confirmed by the verifier, with a wall-clock budget so a run of dead hosts
 * (each blocking to the fetch timeout) can never overrun the tick. Whatever is
 * left stays pending for the next tick.
 */
final readonly class ImageVerificationSweep
{
    private const int MAX_PER_TICK = 25;
    private const int BUDGET_SECONDS = 15;

    public function __construct(
        private EntryRepository $entryRepository,
        private ImageVerifier $imageVerifier,
        private EntityManagerInterface $em,
    ) {
    }

    public function verifyDue(): ImageVerificationReport
    {
        $deadline = microtime(true) + self::BUDGET_SECONDS;
        $measured = 0;
        $dropped = 0;
        $retried = 0;
        $processed = 0;

        foreach ($this->entryRepository->findPendingImageVerification(self::MAX_PER_TICK) as $entry) {
            if (microtime(true) >= $deadline) {
                break;
            }
            match ($this->imageVerifier->verify($entry->getImage())) {
                ImageVerifyOutcome::Measured => $measured++,
                ImageVerifyOutcome::Dropped => $dropped++,
                ImageVerifyOutcome::Retried => $retried++,
            };
            $processed++;
        }

        if ($processed > 0) {
            $this->em->flush();
        }

        return new ImageVerificationReport($measured, $dropped, $retried);
    }
}
```

- [ ] **Step 4: Run to verify it passes**

```bash
cd backend && php bin/phpunit --filter=ImageVerificationSweepTest
```
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
cd backend && git add src/Service/Image/ImageVerificationReport.php src/Service/Image/ImageVerificationSweep.php tests/Service/Image/ImageVerificationSweepTest.php
git commit -m "feat(#1109): add bounded image-verification maintenance step"
```

---

## Task 10: Wire the sweep into `MaintenanceTick`

Run the sweep as a fifth tick step under the same aborted-EntityManager guard, and add its counts to the tick report.

**Files:**
- Modify: `backend/src/Service/Maintenance/MaintenanceTick.php`
- Modify: `backend/src/Service/Maintenance/MaintenanceTickReport.php`
- Modify: `backend/tests/Service/Maintenance/MaintenanceTickTest.php`

**Interfaces:**
- Consumes: `ImageVerificationSweep::verifyDue()` (Task 9), `ImageVerificationReport` (Task 9).

- [ ] **Step 1: Update the tick tests**

In `backend/tests/Service/Maintenance/MaintenanceTickTest.php`:

(a) In `testRunProducesAReportCarryingBothHalves`, add after the `logShipping` assertions:
```php
        self::assertIsInt($report['imageVerification']['measured']);
        self::assertIsInt($report['imageVerification']['dropped']);
        self::assertIsInt($report['imageVerification']['retried']);
        self::assertArrayNotHasKey('skipped', $report['imageVerification']);
```

(b) In `testSkipsTheRecommendationSweepWhenRefreshAborts`, build an `ImageVerificationSweep` and pass it as the 5th collaborator, then assert the skipped image-verification sub-array. Add imports:
```php
use App\Service\Image\ImageVerificationSweep;
use App\Service\Image\ImageVerifier;
use App\Service\Clock\NaiveUtcClock;
use App\Tests\Support\StubFaviconFetcher;
```
Before the `new MaintenanceTick(...)` line, add:
```php
        /** @var EntryRepository $sweepEntryRepository */
        $sweepEntryRepository = $this->em->getRepository(Entry::class);
        $imageVerificationSweep = new ImageVerificationSweep(
            $sweepEntryRepository,
            new ImageVerifier(new StubFaviconFetcher(), new NaiveUtcClock($clock)),
            $this->em,
        );
```
Change the construction to:
```php
        $tick = new MaintenanceTick($refreshRunner, $forYouSweep, $sendDueDigests, $imageVerificationSweep, $logSpoolShipper);
```
And add, alongside the other skipped-branch assertions:
```php
        self::assertSame(
            [
                'measured' => 0,
                'dropped' => 0,
                'retried' => 0,
                'skipped' => 'refresh aborted: the shared EntityManager is unusable this tick',
            ],
            $report['imageVerification'],
        );
```
(The sweep is passed but must never be called on the aborted path — the assertion proves it was skipped.)

- [ ] **Step 2: Run to verify it fails**

```bash
cd backend && php bin/phpunit --filter=MaintenanceTick
```
Expected: FAIL — arity and missing `imageVerification` key.

- [ ] **Step 3: Extend `MaintenanceTickReport`**

In `backend/src/Service/Maintenance/MaintenanceTickReport.php`, add the `imageVerification` array as the fourth constructor parameter (before `logShipping`) and to `toArray()`:

```php
    public function __construct(
        public array $refresh,
        public array $recommendations,
        public array $digests,
        public array $imageVerification,
        public array $logShipping,
    ) {
    }
```
and in `toArray()` add `'imageVerification' => $this->imageVerification,` between `digests` and `logShipping` (update the `@return` shape docblock to match).

- [ ] **Step 4: Wire the sweep into `MaintenanceTick`**

In `backend/src/Service/Maintenance/MaintenanceTick.php`:

Add imports:
```php
use App\Service\Image\ImageVerificationReport;
use App\Service\Image\ImageVerificationSweep;
```
Add the collaborator to the constructor (5th):
```php
        private ImageVerificationSweep $imageVerificationSweep,
```
Rewrite `run()`:
```php
    public function run(): MaintenanceTickReport
    {
        $refresh = $this->refreshRunner->run(RefreshRequest::allDue(self::REFRESH_BUDGET_SECONDS));
        if ($refresh->isAborted()) {
            $recommendations = $this->skippedRecommendations();
            $digests = $this->skippedDigests();
            $imageVerification = $this->skippedImageVerification();
        } else {
            $recommendations = $this->forYouSweep->sweepOnce()->toArray();
            $digests = $this->sendDueDigests->run()->toArray();
            $imageVerification = $this->imageVerificationSweep->verifyDue()->toArray();
        }
        $logShipping = $this->logSpoolShipper->ship()->toArray();

        return new MaintenanceTickReport(
            $refresh->toArray(),
            $recommendations,
            $digests,
            $imageVerification,
            $logShipping,
        );
    }
```
Add the skip helper next to `skippedDigests()`:
```php
    /**
     * @return array{measured: int, dropped: int, retried: int, skipped: string}
     */
    private function skippedImageVerification(): array
    {
        return (new ImageVerificationReport(0, 0, 0))->toArray() + ['skipped' => self::ABORTED_REASON];
    }
```
Update the class docblock with one sentence: the tick also verifies a bounded batch of pending images, under the same aborted-EM guard because it flushes through the default EntityManager.

- [ ] **Step 5: Run the tick tests**

```bash
cd backend && php bin/phpunit --filter=MaintenanceTick
```
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
cd backend && git add src/Service/Maintenance/MaintenanceTick.php src/Service/Maintenance/MaintenanceTickReport.php tests/Service/Maintenance/MaintenanceTickTest.php
git commit -m "feat(#1109): run image verification inside the maintenance tick"
```

---

## Task 11: `HeroImageSelector` minimum-width gate (Original view)

Stop a small feed picture from upscaling into the Original-view hero, while keeping the trust-unknown-width convention.

**Files:**
- Modify: `backend/src/Service/Reader/HeroImageSelector.php`
- Modify: `backend/tests/Service/Reader/HeroImageSelectorTest.php`

**Interfaces:**
- Consumes: `DeclaredImage::$width` (existing).

- [ ] **Step 1: Add failing tests**

Append to `backend/tests/Service/Reader/HeroImageSelectorTest.php`:

```php
    public function testRejectsAHeroNarrowerThanTheMinimum(): void
    {
        $hero = new DeclaredImage('https://cdn.test/small.jpg', 300, 200);

        self::assertNull($this->selector->select($hero, '<p>Just words.</p>'));
    }

    public function testKeepsAHeroAtTheMinimumWidth(): void
    {
        $hero = new DeclaredImage('https://cdn.test/wide.jpg', 480, 300);

        self::assertSame($hero, $this->selector->select($hero, '<p>Just words.</p>'));
    }

    public function testKeepsAHeroWithUnknownWidth(): void
    {
        $hero = new DeclaredImage('https://cdn.test/unknown.jpg');

        self::assertSame($hero, $this->selector->select($hero, '<p>Just words.</p>'));
    }
```

- [ ] **Step 2: Run to verify the narrow-hero test fails**

```bash
cd backend && php bin/phpunit --filter=testRejectsAHeroNarrowerThanTheMinimum
```
Expected: FAIL — no size gate today.

- [ ] **Step 3: Add the gate**

In `backend/src/Service/Reader/HeroImageSelector.php`, add the constant and one guard clause in `select()` (a known width below the floor is rejected; unknown width is trusted, matching the frontend planner):

```php
    /** A known width below this would only upscale into the hero band. */
    private const int MIN_HERO_WIDTH = 480;
```
and after the scheme guard, before the body parse:
```php
        if ($candidate->width !== null && $candidate->width < self::MIN_HERO_WIDTH) {
            return null;
        }
```
Update the class docblock with one sentence: a known width below the floor is rejected so a small picture never upscales into the hero.

- [ ] **Step 4: Run the hero tests**

```bash
cd backend && php bin/phpunit --filter=HeroImageSelectorTest
```
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
cd backend && git add src/Service/Reader/HeroImageSelector.php tests/Service/Reader/HeroImageSelectorTest.php
git commit -m "feat(#1109): gate the original-view hero on a minimum width"
```

---

## Task 12: Full gate, both DB legs, mutation, and issue close

**Files:**
- No new source; verification and PR.

- [ ] **Step 1: Static analysis and style on all touched files**

```bash
cd backend && composer check && composer md
```
Expected: green. Fix any PHPMD finding by improving the design, not by tuning a threshold. (If phptramp is red, first check `composer show larspohlmann/phptramp` — CI runs its `develop` tip.)

- [ ] **Step 2: PhpStorm inspections on changed PHP**

Run `mcp__phpstorm__lint_files` over every changed/created `.php` file. Block on ERROR and WARNING.

- [ ] **Step 3: Full SQLite suite (native)**

```bash
cd backend && php bin/phpunit
```
Expected: green.

- [ ] **Step 4: Scan today's dev log for swallowed errors/deprecations**

```bash
cd backend && ls -t var/log/dev-*.log | head -1 | xargs tail -n 80 | jq . 2>/dev/null | tail -40
```
Address anything the image work introduced.

- [ ] **Step 5: MySQL leg + migrations leg (Docker)**

```bash
docker compose exec php composer test
docker compose exec php bin/console doctrine:migrations:migrate --no-interaction
docker compose exec php bin/console doctrine:schema:validate
```
Expected: green and in sync.

- [ ] **Step 6: Mutation testing over the diff**

```bash
cd backend && composer infection:diff
```
Expected: at or above `minMsi`. Kill escaped mutants with real assertions (not by deleting cases).

- [ ] **Step 7: Push and open the PR**

```bash
cd backend && cd .. && git push -u origin feature/1109-card-image-recovery
```
Open a PR into `develop` whose body summarizes the four root causes and the ingest+verify design, ends with `Closes #1109`, and has **no attribution lines**. After CI is green and it merges, verify #1109 closed.

- [ ] **Step 8: Update the memory note**

After merge, update `memory/1109-card-image-recovery-https-upgrade-verify.md`: mark the design as implemented, record the final column names (`image_checked_at`, `image_verify_attempts`), the grandfathering decision, the sweep caps (`MAX_PER_TICK = 25`, `BUDGET_SECONDS = 15`, verifier `MAX_ATTEMPTS = 3`, `BEACON_EDGE_CEILING = 100`), and that the extract→upgrade core for the uncommitted backfill is `ItemImageExtractor::fromHtml` + `HttpsImageUrl::orNullUpgrading` + `EntryImage::storePending`.

---

## Out of scope (from #1109)

- Article-page scraping for feeds whose RSS carries no image at all (Al Jazeera, Nature, HN, NYT, TechCrunch, Economist, Scientific American).
- Re-introducing any client-side body-`<img>` scanning (removed in #1100).
- Extending optimistic-upgrade + verify to `media[]` / `attachments[]`.
- A committed backfill command or script. Historical NULL rows are recovered, if wanted, by a **throwaway, uncommitted** CLI script that re-extracts from stored `content_html` via `ItemImageExtractor::fromHtml`, upgrades via `HttpsImageUrl::orNullUpgrading`, writes with `EntryImage::storePending` (checkedAt null), and lets the shipped tick drain it. This plan keeps that core callable on an arbitrary entry, which is the only structural requirement for the backfill.

## Self-review notes

- **Spec coverage:** ingest DOM extraction + dims (T3), native-https preference / Smashing (T4), optimistic upgrade / Techmeme (T2,T5), pending vs trusted (T1,T5), background verify with beacon drop / NPR pixel and retry cap (T6,T7), bounded tick step under the single cron (T9,T10), NULL-authoritative `image_url` for planner/`hasImages`/digest (unchanged consumers), Original-view hero gate (T11), forward-only via grandfathering (T1), backfill core reusability (T2,T3,T1). Frontend is deliberately untouched: the magazine planner already width-gates slots and reads `imageWidth`/`imageHeight`, whose values simply become measured.
- **Type consistency:** `EntryImage` method names, `ImageVerifyOutcome` cases, `findPendingImageVerification`, `ImageVerificationReport`/`Sweep`, and the `MaintenanceTickReport` fifth argument order (`imageVerification` before `logShipping`) are used identically across tasks.
- **Placeholder scan:** the migration class name is the only intentional fill-in (`Version<generated>` / `Version<TS>` — keep the name Doctrine generates in Task 1 Step 3).
