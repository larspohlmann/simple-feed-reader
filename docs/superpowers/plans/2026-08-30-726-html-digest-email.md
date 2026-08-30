# HTML Digest Email Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Send the daily/weekly digest as an HTML email that echoes the reader's magazine "airy/light" view — a white sheet of hairline-separated cards, each a thumbnail beside a headline, source, time and excerpt — with a per-user `digest_format` preference (`html` default, or `text`) and the existing plain-text body kept as the `multipart/alternative` fallback.

**Architecture:** A new `DigestHtmlRenderer` renders the same `DigestModel` the text renderer uses, capped to ~30 cards by a `DigestPageBuilder`. A `DigestImageEmbedder` fetches each entry's thumbnail and feed favicon through the existing SSRF-guarded downloader, resizes them with GD, and hands them back as CID parts. A `DigestMailBuilder` assembles the `Email`; `DigestMailer` becomes a thin transport that branches on the user's `digest_format`. The format preference threads through `Preferences`, `UpdateDigestRequest`, `DigestEnablement`, `MeJson`, and the `email-section` settings UI.

**Tech Stack:** PHP 8.4 / Symfony 7.4, Doctrine ORM, GD extension, Symfony Mime (`Email::embed`), PHPUnit (SQLite natively); Angular 20 signals, Transloco.

**Spec:** [`docs/superpowers/plans/2026-08-30-726-html-digest-email-spec.md`](2026-08-30-726-html-digest-email-spec.md) and [GitHub issue #726](https://github.com/larspohlmann/simple-feed-reader/issues/726)

## Global Constraints

- **Branch** `feature/726-html-digest-email`, off `develop`. Commits `type(#726): summary`.
- **Gates:** `composer check` (cs + stan-max + tramp) and `composer md` before each backend commit; every `src` file touched must be PHPMD-clean. `npm run check` before each frontend commit.
- **Clean Code is mandatory:** `final readonly` with constructor promotion; names reveal intent; guard clauses; errors are typed exceptions namespaced next to their service; no boolean flag parameters; depend on injected interfaces.
- **Thin controllers:** no private method carrying responsibility on `MeController`; field mapping lives in `DigestEnablement` (enforced by `ThinControllerRule`).
- **Datetimes are naive UTC.** Format `publishedAt` in the `UTC` timezone.
- **Single renderer source:** the HTML renderer consumes the same `DigestModel` as `DigestTextRenderer`; grouping / `hasMore` / cap logic is never duplicated.
- **Reuse the SSRF-guarded fetch stack** (`CatalogFaviconFetcherInterface`) for every outbound image request. Never open a new HTTP path.
- **Single-implementation interfaces autowire** with no `services.yaml` alias (matches `CatalogFaviconFetcherInterface`).
- **Translation keys go in BOTH `emails.en.yaml` and `emails.de.yaml`** — no global parity guard exists; `DigestTextRendererTest` only catches keys the text renderer emits.

---

### Task 1: `DigestFormat` enum + `Preferences.digestFormat` column + migration

**Files:**
- Create: `backend/src/Service/Mail/Digest/DigestFormat.php`
- Modify: `backend/src/Entity/Preferences.php`
- Create: `backend/migrations/Version<NEW_TIMESTAMP>.php`
- Test: `backend/tests/Entity/PreferencesDigestFormatTest.php`

**Interfaces:**
- Produces: `enum DigestFormat: string { case Html = 'html'; case Text = 'text'; }`; `Preferences::getDigestFormat(): DigestFormat`, `Preferences::setDigestFormat(DigestFormat): void`; new column `digest_format VARCHAR(10) DEFAULT 'html' NOT NULL`.

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\Preferences;
use App\Entity\User;
use App\Service\Mail\Digest\DigestFormat;
use PHPUnit\Framework\TestCase;

final class PreferencesDigestFormatTest extends TestCase
{
    public function testDefaultsToHtml(): void
    {
        $preferences = new Preferences(new User('reader@example.com', new \DateTimeImmutable('2026-07-01T00:00:00Z')));

        self::assertSame(DigestFormat::Html, $preferences->getDigestFormat());
    }

    public function testAcceptsText(): void
    {
        $preferences = new Preferences(new User('reader@example.com', new \DateTimeImmutable('2026-07-01T00:00:00Z')));
        $preferences->setDigestFormat(DigestFormat::Text);

        self::assertSame(DigestFormat::Text, $preferences->getDigestFormat());
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php bin/phpunit tests/Entity/PreferencesDigestFormatTest.php`
Expected: FAIL — `DigestFormat` and the getter/setter do not exist yet.

- [ ] **Step 3: Create the enum**

`backend/src/Service/Mail/Digest/DigestFormat.php`:

```php
<?php

declare(strict_types=1);

namespace App\Service\Mail\Digest;

enum DigestFormat: string
{
    case Html = 'html';
    case Text = 'text';
}
```

- [ ] **Step 4: Add the column, getter and setter to `Preferences`**

Add the `use App\Service\Mail\Digest\DigestFormat;` import. Add the property beside `digestCadence` (mirror its enum-backed column exactly):

```php
    #[ORM\Column(name: 'digest_format', length: 10, enumType: DigestFormat::class, options: ['default' => 'html'])]
    private DigestFormat $digestFormat = DigestFormat::Html;
```

Add beside the other digest accessors:

```php
    public function getDigestFormat(): DigestFormat
    {
        return $this->digestFormat;
    }

    public function setDigestFormat(DigestFormat $digestFormat): void
    {
        $this->digestFormat = $digestFormat;
    }
```

- [ ] **Step 5: Run test to verify it passes**

Run: `php bin/phpunit tests/Entity/PreferencesDigestFormatTest.php`
Expected: PASS.

- [ ] **Step 6: Write the migration**

Generate a timestamp: `date +%Y%m%d%H%M%S`. Create `backend/migrations/Version<that>.php`, mirroring `Version20260830090229` (the `magazine_style` migration — same table, same enum-backed VARCHAR-with-default shape):

```php
<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version<NEW_TIMESTAMP> extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add digest_format to user_preferences (#726).';
    }

    public function up(Schema $schema): void
    {
        $this->assertSupportedPlatform();
        $this->addSql("ALTER TABLE user_preferences ADD digest_format VARCHAR(10) DEFAULT 'html' NOT NULL");
    }

    public function down(Schema $schema): void
    {
        $this->assertSupportedPlatform();
        $this->addSql('ALTER TABLE user_preferences DROP digest_format');
    }

    private function assertSupportedPlatform(): void
    {
        $platform = $this->connection->getDatabasePlatform();
        $this->abortIf(
            !($platform instanceof AbstractMySQLPlatform) && !($platform instanceof SQLitePlatform),
            \sprintf('No DDL defined for platform %s; only MySQL and SQLite are supported.', $platform::class),
        );
    }
}
```

- [ ] **Step 7: Verify the migration on both platforms**

Run: `php bin/console doctrine:migrations:migrate --no-interaction` (SQLite dev DB), then `php bin/console doctrine:schema:validate`.
Expected: migration applies; schema validates (mapping in sync). Note: the test suite builds its schema from ORM metadata, so it never runs this migration — the CI migrate leg is what guards it.

- [ ] **Step 8: Commit**

```bash
git add backend/src/Service/Mail/Digest/DigestFormat.php backend/src/Entity/Preferences.php backend/migrations/ backend/tests/Entity/PreferencesDigestFormatTest.php
git commit -m "feat(#726): add digest_format preference column"
```

---

### Task 2: Thread `digest_format` through the API (DTO, mapper, MeJson)

**Files:**
- Modify: `backend/src/Dto/Me/UpdateDigestRequest.php`
- Modify: `backend/src/Service/Mail/Digest/DigestEnablement.php`
- Modify: `backend/src/Http/MeJson.php:38-44`
- Test: `backend/tests/Service/Mail/Digest/DigestEnablementTest.php` (extend); update every `new UpdateDigestRequest(...)` call site.

**Interfaces:**
- Consumes: `DigestFormat` (Task 1).
- Produces: `UpdateDigestRequest::$format: DigestFormat` (required); `DigestEnablement::applyTo()` sets it; `MeJson` emits `digest.format`.

- [ ] **Step 1: Write the failing test** (add to `DigestEnablementTest`)

```php
    public function testApplyToSetsTheDigestFormat(): void
    {
        $preferences = new Preferences(new User('reader@example.com', new \DateTimeImmutable('2026-07-01T00:00:00Z')));
        $request = new UpdateDigestRequest(
            enabled: true,
            cadence: DigestCadence::Daily,
            sendHour: 8,
            weekday: 1,
            format: DigestFormat::Text,
        );

        $this->enablement()->applyTo($preferences, $request);

        self::assertSame(DigestFormat::Text, $preferences->getDigestFormat());
    }
```

Add imports `use App\Service\Mail\Digest\DigestFormat;` and (if absent) `use App\Service\Mail\Digest\DigestCadence;`, `use App\Entity\Preferences;`, `use App\Entity\User;`. `enablement()` is however the existing test builds `DigestEnablement` (it takes a `ClockInterface`); reuse the existing helper/setup.

- [ ] **Step 2: Run test to verify it fails**

Run: `php bin/phpunit tests/Service/Mail/Digest/DigestEnablementTest.php`
Expected: FAIL — `UpdateDigestRequest` has no `format` param.

- [ ] **Step 3: Add `format` to `UpdateDigestRequest`**

Add the import and the required constructor param (native enum type-hint gives 422 validation with no attribute, matching `cadence`):

```php
use App\Service\Mail\Digest\DigestFormat;
// ...
        public int $weekday,
        public DigestFormat $format,
    ) {
```

- [ ] **Step 4: Set the field in `DigestEnablement::applyTo`**

Alongside the other setters, before the first-enable check:

```php
        $preferences->setDigestFormat($request->format);
```

- [ ] **Step 5: Emit it in `MeJson`**

In the `'digest' => [...]` block, add the key:

```php
            'format' => $preferences->getDigestFormat()->value,
```

- [ ] **Step 6: Fix every other `new UpdateDigestRequest(...)` call site**

Run: `grep -rn "new UpdateDigestRequest" backend/tests backend/src`
For each, add `format: DigestFormat::Html,` (or `::Text` where the test's intent needs it) and the import. These are all-required-args constructions that will not compile otherwise.

- [ ] **Step 7: Run tests to verify they pass**

Run: `php bin/phpunit tests/Service/Mail/Digest/DigestEnablementTest.php tests/Controller/Api` and any MeController test.
Expected: PASS. If a functional `PATCH /api/me/digest` test exists, add `'format' => 'html'` to its request body and assert `response.digest.format === 'html'`.

- [ ] **Step 8: Commit**

```bash
git add backend/src/Dto/Me/UpdateDigestRequest.php backend/src/Service/Mail/Digest/DigestEnablement.php backend/src/Http/MeJson.php backend/tests
git commit -m "feat(#726): carry digest_format through the me API"
```

---

### Task 3: `ext-gd` + `GdImageResizer`

**Files:**
- Modify: `backend/composer.json` (add `"ext-gd": "*"` to `require`)
- Modify: `docker/php/Dockerfile` (add `gd` to `install-php-extensions` in BOTH the dev and prod stages)
- Create: `backend/src/Service/Mail/Digest/DigestImageResizerInterface.php`
- Create: `backend/src/Service/Mail/Digest/GdImageResizer.php`
- Create: `backend/src/Service/Mail/Digest/Exception/ImageProcessingException.php`
- Test: `backend/tests/Service/Mail/Digest/GdImageResizerTest.php`

**Interfaces:**
- Produces: `DigestImageResizerInterface::coverJpeg(string $sourceBytes, int $width, int $height): string` and `containPng(string $sourceBytes, int $width, int $height): string`; both throw `ImageProcessingException` on undecodable or oversized input.

- [ ] **Step 1: Add `ext-gd` and the Docker extension**

`composer.json` `require`: add `"ext-gd": "*"`. In `docker/php/Dockerfile`, both stages, append `gd` to the `install-php-extensions ...` line. Then `composer install` locally (the dev host already has GD; Strato's PHP has GD bundled 2.1.0 — confirmed).

- [ ] **Step 2: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace App\Tests\Service\Mail\Digest;

use App\Service\Mail\Digest\Exception\ImageProcessingException;
use App\Service\Mail\Digest\GdImageResizer;
use PHPUnit\Framework\TestCase;

final class GdImageResizerTest extends TestCase
{
    private function sourceJpeg(int $width, int $height): string
    {
        $image = imagecreatetruecolor($width, $height);
        imagefill($image, 0, 0, imagecolorallocate($image, 200, 100, 50));
        ob_start();
        imagejpeg($image);
        $bytes = (string) ob_get_clean();
        imagedestroy($image);

        return $bytes;
    }

    public function testCoverJpegProducesExactTargetDimensions(): void
    {
        $out = (new GdImageResizer())->coverJpeg($this->sourceJpeg(400, 300), 176, 132);

        [$width, $height] = getimagesizefromstring($out);
        self::assertSame(176, $width);
        self::assertSame(132, $height);
    }

    public function testContainPngProducesExactTargetDimensions(): void
    {
        $out = (new GdImageResizer())->containPng($this->sourceJpeg(64, 32), 32, 32);

        [$width, $height, $type] = getimagesizefromstring($out);
        self::assertSame(32, $width);
        self::assertSame(32, $height);
        self::assertSame(IMAGETYPE_PNG, $type);
    }

    public function testUndecodableBytesThrow(): void
    {
        $this->expectException(ImageProcessingException::class);
        (new GdImageResizer())->coverJpeg('not an image', 176, 132);
    }

    public function testOversizedSourceThrows(): void
    {
        $this->expectException(ImageProcessingException::class);
        (new GdImageResizer(maxSourcePixels: 100))->coverJpeg($this->sourceJpeg(400, 300), 176, 132);
    }
}
```

- [ ] **Step 3: Run test to verify it fails**

Run: `php bin/phpunit tests/Service/Mail/Digest/GdImageResizerTest.php`
Expected: FAIL — classes do not exist.

- [ ] **Step 4: Create the exception and interface**

`Exception/ImageProcessingException.php`:

```php
<?php

declare(strict_types=1);

namespace App\Service\Mail\Digest\Exception;

final class ImageProcessingException extends \RuntimeException
{
}
```

`DigestImageResizerInterface.php`:

```php
<?php

declare(strict_types=1);

namespace App\Service\Mail\Digest;

interface DigestImageResizerInterface
{
    /** Scale-and-centre-crop to exactly $width×$height, re-encoded as JPEG. */
    public function coverJpeg(string $sourceBytes, int $width, int $height): string;

    /** Fit within $width×$height, centred on a transparent canvas, as PNG. */
    public function containPng(string $sourceBytes, int $width, int $height): string;
}
```

- [ ] **Step 5: Implement `GdImageResizer`**

```php
<?php

declare(strict_types=1);

namespace App\Service\Mail\Digest;

use App\Service\Mail\Digest\Exception\ImageProcessingException;

/**
 * Rasterises a fetched image to a fixed digest thumbnail (cover-crop JPEG) or
 * favicon (contained PNG) with GD. The source is bounded before decode so a
 * small-bytes / huge-pixels image cannot exhaust the memory limit.
 */
final readonly class GdImageResizer implements DigestImageResizerInterface
{
    public function __construct(private int $maxSourcePixels = 25_000_000)
    {
    }

    public function coverJpeg(string $sourceBytes, int $width, int $height): string
    {
        $source = $this->decode($sourceBytes);
        $canvas = imagecreatetruecolor($width, $height);

        $scale = max($width / imagesx($source), $height / imagesy($source));
        $this->drawCentred($canvas, $source, $scale, $width, $height);

        $out = $this->capture(static fn (\GdImage $image): bool => imagejpeg($image, null, 80), $canvas);
        imagedestroy($source);
        imagedestroy($canvas);

        return $out;
    }

    public function containPng(string $sourceBytes, int $width, int $height): string
    {
        $source = $this->decode($sourceBytes);
        $canvas = imagecreatetruecolor($width, $height);
        imagealphablending($canvas, false);
        imagesavealpha($canvas, true);
        imagefill($canvas, 0, 0, imagecolorallocatealpha($canvas, 0, 0, 0, 127));

        $scale = min($width / imagesx($source), $height / imagesy($source));
        $this->drawCentred($canvas, $source, $scale, $width, $height);

        $out = $this->capture(static fn (\GdImage $image): bool => imagepng($image), $canvas);
        imagedestroy($source);
        imagedestroy($canvas);

        return $out;
    }

    private function decode(string $bytes): \GdImage
    {
        $size = getimagesizefromstring($bytes);
        if ($size === false || $size[0] * $size[1] > $this->maxSourcePixels) {
            throw new ImageProcessingException('Source image is undecodable or exceeds the pixel cap.');
        }

        $image = @imagecreatefromstring($bytes);
        if ($image === false) {
            throw new ImageProcessingException('GD could not decode the source image.');
        }

        return $image;
    }

    private function drawCentred(\GdImage $canvas, \GdImage $source, float $scale, int $width, int $height): void
    {
        $targetWidth = max(1, (int) round(imagesx($source) * $scale));
        $targetHeight = max(1, (int) round(imagesy($source) * $scale));
        $offsetX = (int) (($width - $targetWidth) / 2);
        $offsetY = (int) (($height - $targetHeight) / 2);

        imagecopyresampled(
            $canvas, $source,
            $offsetX, $offsetY, 0, 0,
            $targetWidth, $targetHeight, imagesx($source), imagesy($source),
        );
    }

    /** @param callable(\GdImage): bool $encode */
    private function capture(callable $encode, \GdImage $canvas): string
    {
        ob_start();
        $encode($canvas);

        return (string) ob_get_clean();
    }
}
```

- [ ] **Step 6: Run test to verify it passes**

Run: `php bin/phpunit tests/Service/Mail/Digest/GdImageResizerTest.php`
Expected: PASS (all four).

- [ ] **Step 7: Commit**

```bash
git add backend/composer.json backend/composer.lock docker/php/Dockerfile backend/src/Service/Mail/Digest/GdImageResizer.php backend/src/Service/Mail/Digest/DigestImageResizerInterface.php backend/src/Service/Mail/Digest/Exception/ImageProcessingException.php backend/tests/Service/Mail/Digest/GdImageResizerTest.php
git commit -m "feat(#726): GD image resizer for digest thumbnails and favicons"
```

---

### Task 4: Widen `DigestEntry` and populate it in `DigestComposer`

**Files:**
- Modify: `backend/src/Service/Mail/Digest/DigestEntry.php`
- Modify: `backend/src/Service/Mail/Digest/DigestComposer.php:63-71`
- Test: `backend/tests/Service/Mail/Digest/DigestComposerTest.php` (extend); update direct `new DigestEntry(...)` sites.

**Interfaces:**
- Produces: `DigestEntry` gains `?\DateTimeImmutable $publishedAt`, `?string $imageUrl`, `?int $imageWidth`, `?int $imageHeight`, `?string $faviconUrl` (all after the existing four, all required).

- [ ] **Step 1: Write the failing test** (add to `DigestComposerTest`)

First extend the `row()` helper to set an image, published date and favicon so the new fields have something to carry:

```php
        // inside row(), after constructing $entry, before building EntryListRow:
        $entry->setPublishedAt(new \DateTimeImmutable('2026-08-15T09:48:00Z'));
        $entry->setImage('https://cdn.example.com/' . $id . '.jpg', 1200, 900);
        $entry->getFeed()->setFaviconUrl('https://example.com/favicon.ico');
```

Then the assertions:

```php
    public function testEntryCarriesImagePublishedDateAndFavicon(): void
    {
        $rust = new SavedSearch($this->user, 'rust', false);
        $this->savedSearches->method('findIncludedInDigestForUser')->willReturn([$rust]);
        $this->entries->method('unreadMatchIdsSince')->willReturn([1]);
        $this->entries->method('rowsByIdsForUser')->willReturn([$this->row(1, 'Title', 'Feed A')]);

        $model = $this->composer()->compose($this->user, $this->since);

        self::assertNotNull($model);
        $entry = $model->groups[0]->entries[0];
        self::assertSame('https://cdn.example.com/1.jpg', $entry->imageUrl);
        self::assertSame(1200, $entry->imageWidth);
        self::assertSame(900, $entry->imageHeight);
        self::assertSame('https://example.com/favicon.ico', $entry->faviconUrl);
        self::assertEquals(new \DateTimeImmutable('2026-08-15T09:48:00Z'), $entry->publishedAt);
    }
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php bin/phpunit tests/Service/Mail/Digest/DigestComposerTest.php`
Expected: FAIL — `DigestEntry` has no `imageUrl`.

- [ ] **Step 3: Widen `DigestEntry`**

```php
final readonly class DigestEntry
{
    public function __construct(
        public string $title,
        public string $feedName,
        public string $shortDescription,
        public string $url,
        public ?\DateTimeImmutable $publishedAt,
        public ?string $imageUrl,
        public ?int $imageWidth,
        public ?int $imageHeight,
        public ?string $faviconUrl,
    ) {
    }
}
```

- [ ] **Step 4: Populate them in `DigestComposer::entry`**

```php
    private function entry(EntryListRow $row): DigestEntry
    {
        $entry = $row->entry;

        return new DigestEntry(
            $entry->getTitle(),
            $row->subscriptionTitle,
            $this->shortDescription($row),
            $this->links->entryUrl((int) $entry->getId()),
            $entry->getPublishedAt(),
            $entry->getImageUrl(),
            $entry->getImageWidth(),
            $entry->getImageHeight(),
            $entry->getFeed()->getFaviconUrl(),
        );
    }
```

- [ ] **Step 5: Fix direct `new DigestEntry(...)` sites**

Run: `grep -rn "new DigestEntry(" backend/tests`. In `DigestMailerTest::model()` (and any other), append the five new args, e.g.:

```php
            new DigestEntry('Rust 1.80 released', 'Rust Blog', 'A short summary.', 'https://example.com/1', null, null, null, null, null),
```

- [ ] **Step 6: Run tests to verify they pass**

Run: `php bin/phpunit tests/Service/Mail/Digest`
Expected: PASS.

- [ ] **Step 7: Commit**

```bash
git add backend/src/Service/Mail/Digest/DigestEntry.php backend/src/Service/Mail/Digest/DigestComposer.php backend/tests/Service/Mail/Digest
git commit -m "feat(#726): carry image, published date and favicon on DigestEntry"
```

---

### Task 5: `DigestPage` + `DigestPageBuilder` (the ~30-card cap)

**Files:**
- Create: `backend/src/Service/Mail/Digest/DigestPage.php`
- Create: `backend/src/Service/Mail/Digest/DigestPageGroup.php`
- Create: `backend/src/Service/Mail/Digest/DigestPageBuilder.php`
- Test: `backend/tests/Service/Mail/Digest/DigestPageBuilderTest.php`

**Interfaces:**
- Consumes: `DigestModel`, `DigestGroup`, `DigestEntry`.
- Produces: `DigestPageBuilder::build(DigestModel $model, int $maxCards): DigestPage`; `DigestPageBuilder::DEFAULT_MAX_CARDS = 30`; `DigestPage{ list<DigestPageGroup> $groups; int $totalCount }`; `DigestPageGroup{ string $term; int $totalCount; list<DigestEntry> $cards; int $remaining; string $moreUrl }`.

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace App\Tests\Service\Mail\Digest;

use App\Service\Mail\Digest\DigestEntry;
use App\Service\Mail\Digest\DigestGroup;
use App\Service\Mail\Digest\DigestModel;
use App\Service\Mail\Digest\DigestPageBuilder;
use PHPUnit\Framework\TestCase;

final class DigestPageBuilderTest extends TestCase
{
    private function entry(string $title): DigestEntry
    {
        return new DigestEntry($title, 'Feed', '', 'https://example.com/e', null, null, null, null, null);
    }

    /** @param int $count */
    private function group(string $term, int $count, int $totalCount): DigestGroup
    {
        $entries = array_map(fn (int $i): DigestEntry => $this->entry("{$term} {$i}"), range(1, $count));

        return new DigestGroup($term, $totalCount, $entries, $totalCount > $count, "https://example.com/?q={$term}");
    }

    public function testCapsTotalCardsAndMarksOverflowGroupsHeadingOnly(): void
    {
        $model = new DigestModel(
            [$this->group('a', 10, 10), $this->group('b', 10, 10), $this->group('c', 10, 40)],
            60,
        );

        $page = (new DigestPageBuilder())->build($model, 30);

        self::assertCount(10, $page->groups[0]->cards);
        self::assertSame(0, $page->groups[0]->remaining);
        self::assertCount(10, $page->groups[1]->cards);
        self::assertCount(10, $page->groups[2]->cards, 'The third group fills the budget to exactly 30.');
        self::assertSame(30, $page->groups[2]->remaining, 'Its 40 matches, minus the 10 shown.');
        self::assertSame(60, $page->totalCount);
    }

    public function testGroupsPastTheBudgetGetNoCards(): void
    {
        $model = new DigestModel(
            [$this->group('a', 30, 30), $this->group('b', 5, 5)],
            35,
        );

        $page = (new DigestPageBuilder())->build($model, 30);

        self::assertCount(30, $page->groups[0]->cards);
        self::assertCount(0, $page->groups[1]->cards);
        self::assertSame(5, $page->groups[1]->remaining);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php bin/phpunit tests/Service/Mail/Digest/DigestPageBuilderTest.php`
Expected: FAIL — classes do not exist.

- [ ] **Step 3: Create the value objects**

`DigestPageGroup.php`:

```php
<?php

declare(strict_types=1);

namespace App\Service\Mail\Digest;

final readonly class DigestPageGroup
{
    /** @param list<DigestEntry> $cards */
    public function __construct(
        public string $term,
        public int $totalCount,
        public array $cards,
        public int $remaining,
        public string $moreUrl,
    ) {
    }
}
```

`DigestPage.php`:

```php
<?php

declare(strict_types=1);

namespace App\Service\Mail\Digest;

final readonly class DigestPage
{
    /** @param list<DigestPageGroup> $groups */
    public function __construct(
        public array $groups,
        public int $totalCount,
    ) {
    }
}
```

- [ ] **Step 4: Implement `DigestPageBuilder`**

```php
<?php

declare(strict_types=1);

namespace App\Service\Mail\Digest;

/**
 * Projects a DigestModel to the capped page an HTML mail renders: it places
 * cards newest-group-first until the overall budget is spent, then leaves later
 * groups as heading-only so the HTML body stays under Gmail's ~102 KB clip.
 */
final readonly class DigestPageBuilder
{
    public const int DEFAULT_MAX_CARDS = 30;

    public function build(DigestModel $model, int $maxCards): DigestPage
    {
        $budget = $maxCards;
        $groups = [];

        foreach ($model->groups as $group) {
            $take = max(0, min($budget, \count($group->entries)));
            $cards = \array_slice($group->entries, 0, $take);
            $budget -= $take;

            $groups[] = new DigestPageGroup(
                $group->term,
                $group->totalCount,
                $cards,
                $group->totalCount - \count($cards),
                $group->moreUrl,
            );
        }

        return new DigestPage($groups, $model->totalCount);
    }
}
```

- [ ] **Step 5: Run test to verify it passes**

Run: `php bin/phpunit tests/Service/Mail/Digest/DigestPageBuilderTest.php`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add backend/src/Service/Mail/Digest/DigestPage.php backend/src/Service/Mail/Digest/DigestPageGroup.php backend/src/Service/Mail/Digest/DigestPageBuilder.php backend/tests/Service/Mail/Digest/DigestPageBuilderTest.php
git commit -m "feat(#726): cap the digest to a rendered page of ~30 cards"
```

---

### Task 6: `DigestImageEmbedder` (fetch + resize + dedup → CID set)

**Files:**
- Create: `backend/src/Service/Mail/Digest/EmbeddedImage.php`
- Create: `backend/src/Service/Mail/Digest/DigestImageSet.php`
- Create: `backend/src/Service/Mail/Digest/DigestImageEmbedderInterface.php`
- Create: `backend/src/Service/Mail/Digest/DigestImageEmbedder.php`
- Test: `backend/tests/Service/Mail/Digest/DigestImageEmbedderTest.php`

**Interfaces:**
- Consumes: `DigestPage`/`DigestPageGroup`/`DigestEntry` (Tasks 4–5), `DigestImageResizerInterface` (Task 3), `App\Service\Catalog\CatalogFaviconFetcherInterface::download(string): App\Service\Catalog\FetchedFavicon` (throws `App\Service\Catalog\Exception\FaviconUnavailableException`).
- Produces: `DigestImageEmbedderInterface::embed(DigestPage $page): DigestImageSet`; `DigestImageSet::cidFor(?string $url): ?string`; `DigestImageSet::$images: list<EmbeddedImage>`; `EmbeddedImage{ string $cid; string $bytes; string $contentType }`.

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace App\Tests\Service\Mail\Digest;

use App\Service\Catalog\CatalogFaviconFetcherInterface;
use App\Service\Catalog\Exception\FaviconUnavailableException;
use App\Service\Catalog\FetchedFavicon;
use App\Service\Mail\Digest\DigestEntry;
use App\Service\Mail\Digest\DigestImageEmbedder;
use App\Service\Mail\Digest\DigestImageResizerInterface;
use App\Service\Mail\Digest\DigestPage;
use App\Service\Mail\Digest\DigestPageGroup;
use Psr\Log\NullLogger;
use PHPUnit\Framework\TestCase;

final class DigestImageEmbedderTest extends TestCase
{
    private function card(string $imageUrl, string $faviconUrl): DigestEntry
    {
        return new DigestEntry('T', 'Feed', '', 'https://example.com/e', null, $imageUrl, null, null, $faviconUrl);
    }

    private function page(DigestEntry ...$cards): DigestPage
    {
        return new DigestPage([new DigestPageGroup('t', \count($cards), $cards, 0, '')], \count($cards));
    }

    public function testDedupesASharedFaviconToOneEmbed(): void
    {
        $fetcher = $this->createStub(CatalogFaviconFetcherInterface::class);
        $fetcher->method('download')->willReturnCallback(
            static fn (string $url): FetchedFavicon => new FetchedFavicon($url, 'RAW', 'image/png'),
        );
        $resizer = $this->createStub(DigestImageResizerInterface::class);
        $resizer->method('coverJpeg')->willReturn('JPG');
        $resizer->method('containPng')->willReturn('PNG');

        $set = (new DigestImageEmbedder($fetcher, $resizer, new NullLogger()))->embed($this->page(
            $this->card('https://cdn/1.jpg', 'https://site/favicon.ico'),
            $this->card('https://cdn/2.jpg', 'https://site/favicon.ico'),
        ));

        // 2 distinct thumbnails + 1 shared favicon = 3 embeds, not 4.
        self::assertCount(3, $set->images);
        self::assertNotNull($set->cidFor('https://cdn/1.jpg'));
        self::assertSame($set->cidFor('https://site/favicon.ico'), $set->cidFor('https://site/favicon.ico'));
    }

    public function testAFetchFailureDropsThatImageOnly(): void
    {
        $fetcher = $this->createStub(CatalogFaviconFetcherInterface::class);
        $fetcher->method('download')->willReturnCallback(
            static function (string $url): FetchedFavicon {
                if ($url === 'https://cdn/bad.jpg') {
                    throw new FaviconUnavailableException('boom');
                }

                return new FetchedFavicon($url, 'RAW', 'image/png');
            },
        );
        $resizer = $this->createStub(DigestImageResizerInterface::class);
        $resizer->method('coverJpeg')->willReturn('JPG');
        $resizer->method('containPng')->willReturn('PNG');

        $set = (new DigestImageEmbedder($fetcher, $resizer, new NullLogger()))->embed($this->page(
            $this->card('https://cdn/bad.jpg', 'https://site/favicon.ico'),
        ));

        self::assertNull($set->cidFor('https://cdn/bad.jpg'));
        self::assertNotNull($set->cidFor('https://site/favicon.ico'));
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php bin/phpunit tests/Service/Mail/Digest/DigestImageEmbedderTest.php`
Expected: FAIL — classes do not exist.

- [ ] **Step 3: Create the value objects**

`EmbeddedImage.php`:

```php
<?php

declare(strict_types=1);

namespace App\Service\Mail\Digest;

final readonly class EmbeddedImage
{
    public function __construct(
        public string $cid,
        public string $bytes,
        public string $contentType,
    ) {
    }
}
```

`DigestImageSet.php`:

```php
<?php

declare(strict_types=1);

namespace App\Service\Mail\Digest;

final readonly class DigestImageSet
{
    /**
     * @param list<EmbeddedImage>  $images
     * @param array<string, string> $cidByUrl
     */
    public function __construct(
        public array $images,
        private array $cidByUrl,
    ) {
    }

    public function cidFor(?string $url): ?string
    {
        return $url === null ? null : ($this->cidByUrl[$url] ?? null);
    }
}
```

`DigestImageEmbedderInterface.php`:

```php
<?php

declare(strict_types=1);

namespace App\Service\Mail\Digest;

interface DigestImageEmbedderInterface
{
    public function embed(DigestPage $page): DigestImageSet;
}
```

- [ ] **Step 4: Implement `DigestImageEmbedder`**

```php
<?php

declare(strict_types=1);

namespace App\Service\Mail\Digest;

use App\Service\Catalog\CatalogFaviconFetcherInterface;
use App\Service\Catalog\Exception\FaviconUnavailableException;
use App\Service\Mail\Digest\Exception\ImageProcessingException;
use Psr\Log\LoggerInterface;

/**
 * Fetches every thumbnail and favicon a rendered page references, resizes each,
 * and returns them as CID parts keyed by source URL. Downloads run through the
 * shared SSRF-guarded fetcher; a distinct URL is fetched once and reused, and
 * any fetch or resize failure drops that one image rather than the mail.
 */
final readonly class DigestImageEmbedder implements DigestImageEmbedderInterface
{
    private const int THUMBNAIL_WIDTH = 176;
    private const int THUMBNAIL_HEIGHT = 132;
    private const int FAVICON_SIZE = 32;

    public function __construct(
        private CatalogFaviconFetcherInterface $downloader,
        private DigestImageResizerInterface $resizer,
        private LoggerInterface $logger,
    ) {
    }

    public function embed(DigestPage $page): DigestImageSet
    {
        $images = [];
        $cidByUrl = [];

        foreach ($this->requests($page) as $url => $isFavicon) {
            $embedded = $this->tryEmbed($url, $isFavicon);
            if ($embedded === null) {
                continue;
            }

            $cidByUrl[$url] = $embedded->cid;
            $images[] = $embedded;
        }

        return new DigestImageSet($images, $cidByUrl);
    }

    /**
     * Distinct source URLs, each flagged favicon-or-thumbnail. A favicon URL and
     * a thumbnail URL never collide in practice; first sighting wins the flag.
     *
     * @return array<string, bool>
     */
    private function requests(DigestPage $page): array
    {
        $requests = [];

        foreach ($page->groups as $group) {
            foreach ($group->cards as $card) {
                if ($card->faviconUrl !== null) {
                    $requests[$card->faviconUrl] ??= true;
                }
                if ($card->imageUrl !== null) {
                    $requests[$card->imageUrl] ??= false;
                }
            }
        }

        return $requests;
    }

    private function tryEmbed(string $url, bool $isFavicon): ?EmbeddedImage
    {
        try {
            $raw = $this->downloader->download($url)->bytes;
            $bytes = $isFavicon
                ? $this->resizer->containPng($raw, self::FAVICON_SIZE, self::FAVICON_SIZE)
                : $this->resizer->coverJpeg($raw, self::THUMBNAIL_WIDTH, self::THUMBNAIL_HEIGHT);

            return new EmbeddedImage(
                'img' . substr(hash('xxh128', $url), 0, 16),
                $bytes,
                $isFavicon ? 'image/png' : 'image/jpeg',
            );
        } catch (FaviconUnavailableException | ImageProcessingException $e) {
            $this->logger->debug('Digest image skipped: {url}', ['url' => $url, 'exception' => $e]);

            return null;
        }
    }
}
```

- [ ] **Step 5: Run test to verify it passes**

Run: `php bin/phpunit tests/Service/Mail/Digest/DigestImageEmbedderTest.php`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add backend/src/Service/Mail/Digest/EmbeddedImage.php backend/src/Service/Mail/Digest/DigestImageSet.php backend/src/Service/Mail/Digest/DigestImageEmbedderInterface.php backend/src/Service/Mail/Digest/DigestImageEmbedder.php backend/tests/Service/Mail/Digest/DigestImageEmbedderTest.php
git commit -m "feat(#726): fetch, resize and CID-embed digest images with dedup"
```

---

### Task 7: `DigestHtmlRenderer` + translation keys + settings link

**Files:**
- Modify: `backend/src/Service/Mail/Digest/DigestLinkBuilder.php` (add `settingsEmailUrl()`)
- Modify: `backend/translations/emails.en.yaml`, `backend/translations/emails.de.yaml`
- Create: `backend/src/Service/Mail/Digest/DigestHtmlRenderer.php`
- Test: `backend/tests/Service/Mail/Digest/DigestHtmlRendererTest.php`, extend `backend/tests/Service/Mail/Digest/DigestLinkBuilderTest.php`

**Visual source of truth:** the signed-off mockup [`2026-08-30-726-html-digest-email-preview.html`](2026-08-30-726-html-digest-email-preview.html) — open it in a browser and match its inlined styles (600px column, `#f5f5f4` ground, `#fff` sheet, `#e4e4e2` hairlines, 88×66 thumbnail radius 8, headline 15px `#2a2a2a`, excerpt 13px `#5f5f5c`, kicker 13px `#8f8f8b`, accent `#3f8676`). The static HTML is the pixel reference; the renderer reproduces it.

**Interfaces:**
- Consumes: `DigestPage`, `DigestImageSet`, `DigestLinkBuilder`.
- Produces: `DigestHtmlRenderer::render(DigestPage $page, DigestImageSet $images, string $locale): string`; `DigestLinkBuilder::settingsEmailUrl(): string`.

- [ ] **Step 1: Add the translation keys** (BOTH files)

`emails.en.yaml` under `digest:`:

```yaml
    header: '%date% · %count% new entries'
    open_reader: 'Open in the reader'
    manage_html: 'Manage your digest in %link%.'
    manage_link_label: 'Settings → Email'
    more_link: '+%count% more in "%term%"'
```

`emails.de.yaml` under `digest:`:

```yaml
    header: '%date% · %count% neue Beiträge'
    open_reader: 'Im Reader öffnen'
    manage_html: 'Verwalte deinen Digest unter %link%.'
    manage_link_label: 'Einstellungen → E-Mail'
    more_link: '+%count% weitere in „%term%"'
```

- [ ] **Step 2: Write the failing `DigestLinkBuilder` test** (add to `DigestLinkBuilderTest`)

```php
    public function testSettingsEmailUrl(): void
    {
        $links = new DigestLinkBuilder(new FixedPublicBaseUrl('https://reader.example'));

        self::assertSame('https://reader.example/settings/email', $links->settingsEmailUrl());
    }
```

- [ ] **Step 3: Add `settingsEmailUrl()`**

```php
    public function settingsEmailUrl(): string
    {
        return $this->base() . 'settings/email';
    }
```

- [ ] **Step 4: Write the failing `DigestHtmlRenderer` test**

```php
<?php

declare(strict_types=1);

namespace App\Tests\Service\Mail\Digest;

use App\Tests\Support\FixedPublicBaseUrl;
use App\Service\Mail\Digest\DigestEntry;
use App\Service\Mail\Digest\DigestHtmlRenderer;
use App\Service\Mail\Digest\DigestImageSet;
use App\Service\Mail\Digest\DigestLinkBuilder;
use App\Service\Mail\Digest\DigestPage;
use App\Service\Mail\Digest\DigestPageGroup;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Translation\Loader\YamlFileLoader;
use Symfony\Component\Translation\Translator;

final class DigestHtmlRendererTest extends TestCase
{
    private function renderer(): DigestHtmlRenderer
    {
        $translator = new Translator('en');
        $translator->addLoader('yaml', new YamlFileLoader());
        $dir = \dirname(__DIR__, 4) . '/translations';
        $translator->addResource('yaml', "{$dir}/emails.en.yaml", 'en', 'emails');
        $translator->addResource('yaml', "{$dir}/emails.de.yaml", 'de', 'emails');

        return new DigestHtmlRenderer($translator, new DigestLinkBuilder(new FixedPublicBaseUrl('https://reader.example')));
    }

    private function card(string $title, ?string $imageUrl): DigestEntry
    {
        return new DigestEntry(
            $title, 'ZDFheute', 'A short summary.', 'https://reader.example/?entry=1',
            new \DateTimeImmutable('2026-08-30T09:48:00Z'), $imageUrl, null, null, 'https://site/favicon.ico',
        );
    }

    public function testRendersCardWithImageAndTheEntryLink(): void
    {
        $page = new DigestPage([new DigestPageGroup('Thailand', 10, [$this->card('Thailand-Urlaub', 'https://cdn/1.jpg')], 7, 'https://reader.example/?q=Thailand')], 10);
        $images = new DigestImageSet([], ['https://cdn/1.jpg' => 'imgABC', 'https://site/favicon.ico' => 'imgFAV']);

        $html = $this->renderer()->render($page, $images, 'en');

        self::assertStringContainsString('Thailand-Urlaub', $html);
        self::assertStringContainsString('https://reader.example/?entry=1', $html);
        self::assertStringContainsString('cid:imgABC', $html);
        self::assertStringContainsString('cid:imgFAV', $html);
        self::assertStringContainsString('+7 more in "Thailand"', $html);
    }

    public function testTextOnlyCardHasNoImgTag(): void
    {
        $page = new DigestPage([new DigestPageGroup('Thailand', 1, [$this->card('No image here', null)], 0, 'https://reader.example/?q=Thailand')], 1);
        $images = new DigestImageSet([], ['https://site/favicon.ico' => 'imgFAV']);

        $html = $this->renderer()->render($page, $images, 'en');

        self::assertStringContainsString('No image here', $html);
        self::assertStringNotContainsString('cid:imgABC', $html);
    }

    public function testOverflowGroupRendersHeadingAndMoreLinkOnly(): void
    {
        $page = new DigestPage([new DigestPageGroup('Bundesliga', 12, [], 12, 'https://reader.example/?q=Bundesliga')], 12);

        $html = $this->renderer()->render($page, new DigestImageSet([], []), 'en');

        self::assertStringContainsString('Bundesliga', $html);
        self::assertStringContainsString('+12 more in "Bundesliga"', $html);
    }

    public function testFooterCarriesTheSettingsLink(): void
    {
        $page = new DigestPage([], 0);

        $html = $this->renderer()->render($page, new DigestImageSet([], []), 'en');

        self::assertStringContainsString('https://reader.example/settings/email', $html);
        self::assertStringContainsString('Settings → Email', $html);
    }
}
```

- [ ] **Step 5: Run test to verify it fails**

Run: `php bin/phpunit tests/Service/Mail/Digest/DigestHtmlRendererTest.php`
Expected: FAIL — `DigestHtmlRenderer` does not exist.

- [ ] **Step 6: Implement `DigestHtmlRenderer`**

```php
<?php

declare(strict_types=1);

namespace App\Service\Mail\Digest;

use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Renders a capped DigestPage to the airy/light HTML email (#726). Table-based
 * for Outlook, every style inlined, light-only. The whole card is the reader
 * deep link; images are referenced by their CID from the DigestImageSet.
 */
final readonly class DigestHtmlRenderer
{
    public function __construct(
        private TranslatorInterface $translator,
        private DigestLinkBuilder $links,
    ) {
    }

    public function render(DigestPage $page, DigestImageSet $images, string $locale): string
    {
        $body = $this->header($page->totalCount, $locale)
            . $this->intro($locale)
            . implode('', array_map(fn (DigestPageGroup $group): string => $this->group($group, $images, $locale), $page->groups))
            . $this->footer($locale);

        return $this->document($body);
    }

    private function document(string $body): string
    {
        return '<!doctype html><html><body style="margin:0;background:#f5f5f4;">'
            . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f5f5f4;">'
            . '<tr><td align="center" style="padding:24px 12px;">'
            . '<table role="presentation" width="600" cellpadding="0" cellspacing="0" '
            . 'style="width:600px;max-width:600px;background:#ffffff;font-family:system-ui,-apple-system,\'Segoe UI\',roboto,sans-serif;color:#2a2a2a;">'
            . $body
            . '</table></td></tr></table></body></html>';
    }

    private function header(int $totalCount, string $locale): string
    {
        $line = $this->trans('digest.header', ['%date%' => $this->today($locale), '%count%' => (string) $totalCount], $locale);

        return '<tr><td style="padding:24px 24px 18px;border-bottom:1px solid #e4e4e2;">'
            . '<span style="font-size:15px;font-weight:600;color:#2a2a2a;">simple feed reader</span>'
            . '<div style="margin-top:14px;font-size:13px;color:#8f8f8b;">' . $this->escape($line) . '</div>'
            . '</td></tr>';
    }

    private function intro(string $locale): string
    {
        return '<tr><td style="padding:16px 24px 0;font-size:14px;line-height:1.5;color:#5f5f5c;">'
            . $this->escape($this->trans('digest.intro', [], $locale)) . '</td></tr>';
    }

    private function group(DigestPageGroup $group, DigestImageSet $images, string $locale): string
    {
        $heading = $this->trans('digest.group_heading', ['%term%' => $group->term, '%count%' => (string) $group->totalCount], $locale);
        $cards = implode('', array_map(fn (DigestEntry $card): string => $this->card($card, $images, $locale), $group->cards));
        $more = $group->remaining > 0 ? $this->moreLink($group, $locale) : '';

        return '<tr><td style="padding:20px 24px 4px;">'
            . '<div style="padding-bottom:10px;border-bottom:1px solid #e4e4e2;font-size:13px;font-weight:600;color:#5f5f5c;">'
            . $this->escape($heading) . '</div>'
            . $cards . $more . '</td></tr>';
    }

    private function card(DigestEntry $card, DigestImageSet $images, string $locale): string
    {
        $thumbnailCid = $images->cidFor($card->imageUrl);
        $thumbnail = $thumbnailCid === null ? '' : '<td valign="top" width="88" style="width:88px;padding:0 12px 0 0;">'
            . '<img src="cid:' . $thumbnailCid . '" width="88" height="66" alt="" '
            . 'style="display:block;width:88px;height:66px;border-radius:8px;object-fit:cover;"></td>';

        return '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" '
            . 'style="border-top:1px solid #e4e4e2;margin-top:20px;padding-top:20px;"><tr>'
            . $thumbnail
            . '<td valign="top">' . $this->kicker($card, $images, $locale)
            . '<a href="' . $this->escape($card->url) . '" style="display:block;font-size:15px;font-weight:500;'
            . 'line-height:1.35;color:#2a2a2a;text-decoration:none;margin:4px 0;">' . $this->escape($card->title) . '</a>'
            . $this->dek($card)
            . '</td></tr></table>';
    }

    private function kicker(DigestEntry $card, DigestImageSet $images, string $locale): string
    {
        $faviconCid = $images->cidFor($card->faviconUrl);
        $favicon = $faviconCid === null ? '' : '<img src="cid:' . $faviconCid . '" width="16" height="16" alt="" '
            . 'style="width:16px;height:16px;border-radius:4px;vertical-align:middle;margin-right:6px;">';
        $when = $this->when($card->publishedAt, $locale);
        $time = $when === '' ? '' : '<span style="color:#c4c4c1;"> · </span>' . $this->escape($when);

        return '<div style="font-size:13px;color:#8f8f8b;">' . $favicon
            . $this->escape($card->feedName) . $time . '</div>';
    }

    private function dek(DigestEntry $card): string
    {
        if ($card->shortDescription === '') {
            return '';
        }

        return '<div style="font-size:13px;line-height:1.4;color:#5f5f5c;margin-top:4px;">'
            . $this->escape($card->shortDescription) . '</div>';
    }

    private function moreLink(DigestPageGroup $group, string $locale): string
    {
        $label = $this->trans('digest.more_link', ['%count%' => (string) $group->remaining, '%term%' => $group->term], $locale);

        return '<a href="' . $this->escape($group->moreUrl) . '" '
            . 'style="display:inline-block;margin:12px 0 2px;font-size:13px;color:#3f8676;text-decoration:none;font-weight:500;">'
            . $this->escape($label) . ' →</a>';
    }

    private function footer(string $locale): string
    {
        $manageLink = '<a href="' . $this->escape($this->links->settingsEmailUrl()) . '" '
            . 'style="color:#8f8f8b;text-decoration:underline;">'
            . $this->escape($this->trans('digest.manage_link_label', [], $locale)) . '</a>';
        $manage = strtr($this->escape($this->trans('digest.manage_html', ['%link%' => "\0"], $locale)), ["\0" => $manageLink]);
        $openReader = '<a href="' . $this->escape($this->links->base()) . '" style="font-size:13px;color:#3f8676;text-decoration:none;">'
            . $this->escape($this->trans('digest.open_reader', [], $locale)) . ' →</a>';

        return '<tr><td style="padding:22px 24px 26px;border-top:1px solid #e4e4e2;">'
            . '<div style="margin-bottom:12px;">' . $openReader . '</div>'
            . '<div style="font-size:12px;line-height:1.5;color:#a7a7a3;">' . $manage . '</div>'
            . '</td></tr>';
    }

    private function today(string $locale): string
    {
        $formatter = new \IntlDateFormatter($locale, \IntlDateFormatter::FULL, \IntlDateFormatter::NONE, 'UTC');

        return (string) $formatter->format(new \DateTimeImmutable('now', new \DateTimeZone('UTC')));
    }

    private function when(?\DateTimeImmutable $publishedAt, string $locale): string
    {
        if ($publishedAt === null) {
            return '';
        }

        $formatter = new \IntlDateFormatter($locale, \IntlDateFormatter::MEDIUM, \IntlDateFormatter::SHORT, 'UTC');

        return (string) $formatter->format($publishedAt);
    }

    /** @param array<string, string> $parameters */
    private function trans(string $key, array $parameters, string $locale): string
    {
        return $this->translator->trans($key, $parameters, 'emails', $locale);
    }

    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
```

Note: `today()` uses the clock; a renderer that reads the wall clock is acceptable here (it is display copy, not domain state), but if a mutation-test mutant survives on it, inject `Psr\Clock\ClockInterface`. `DigestLinkBuilder::base()` is currently `private`; change it to `public` for the "open in reader" link (it is already a pure getter).

- [ ] **Step 7: Run tests to verify they pass**

Run: `php bin/phpunit tests/Service/Mail/Digest/DigestHtmlRendererTest.php tests/Service/Mail/Digest/DigestLinkBuilderTest.php`
Expected: PASS.

- [ ] **Step 8: Commit**

```bash
git add backend/src/Service/Mail/Digest/DigestHtmlRenderer.php backend/src/Service/Mail/Digest/DigestLinkBuilder.php backend/translations/emails.en.yaml backend/translations/emails.de.yaml backend/tests/Service/Mail/Digest
git commit -m "feat(#726): render the airy HTML digest body"
```

---

### Task 8: `DigestMailBuilder` + rewire `DigestMailer` to branch on format

**Files:**
- Create: `backend/src/Service/Mail/Digest/DigestMailBuilder.php`
- Modify: `backend/src/Service/Mail/Digest/DigestMailer.php`
- Test: rewrite `backend/tests/Service/Mail/Digest/DigestMailerTest.php`; add `backend/tests/Service/Mail/Digest/DigestMailBuilderTest.php`

**Interfaces:**
- Consumes: `DigestPageBuilder`, `DigestImageEmbedderInterface`, `DigestTextRenderer`, `DigestHtmlRenderer`, `DigestLinkBuilder`, `DigestFormat`, `Preferences::getDigestFormat()`.
- Produces: `DigestMailBuilder::build(User $user, DigestModel $model): Symfony\Component\Mime\Email`; `DigestMailer::__construct(MailerInterface $mailer, DigestMailBuilder $builder)`.

- [ ] **Step 1: Write the failing `DigestMailBuilder` test**

```php
<?php

declare(strict_types=1);

namespace App\Tests\Service\Mail\Digest;

use App\Tests\Support\FixedPublicBaseUrl;
use App\Entity\User;
use App\Service\Mail\Digest\DigestEntry;
use App\Service\Mail\Digest\DigestFormat;
use App\Service\Mail\Digest\DigestGroup;
use App\Service\Mail\Digest\DigestHtmlRenderer;
use App\Service\Mail\Digest\DigestImageEmbedderInterface;
use App\Service\Mail\Digest\DigestImageSet;
use App\Service\Mail\Digest\DigestLinkBuilder;
use App\Service\Mail\Digest\DigestMailBuilder;
use App\Service\Mail\Digest\DigestModel;
use App\Service\Mail\Digest\DigestPage;
use App\Service\Mail\Digest\DigestPageBuilder;
use App\Service\Mail\Digest\DigestTextRenderer;
use App\Service\Mail\Digest\EmbeddedImage;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Translation\Loader\YamlFileLoader;
use Symfony\Component\Translation\Translator;

final class DigestMailBuilderTest extends TestCase
{
    private function builder(DigestImageSet $set): DigestMailBuilder
    {
        $translator = new Translator('en');
        $translator->addLoader('yaml', new YamlFileLoader());
        $dir = \dirname(__DIR__, 4) . '/translations';
        $translator->addResource('yaml', "{$dir}/emails.en.yaml", 'en', 'emails');
        $translator->addResource('yaml', "{$dir}/emails.de.yaml", 'de', 'emails');
        $links = new DigestLinkBuilder(new FixedPublicBaseUrl('https://reader.example'));

        $embedder = $this->createStub(DigestImageEmbedderInterface::class);
        $embedder->method('embed')->willReturn($set);

        return new DigestMailBuilder(
            new DigestPageBuilder(),
            $embedder,
            new DigestTextRenderer($translator),
            new DigestHtmlRenderer($translator, $links),
            $links,
            'noreply@feeds.example.com',
            'Simple Feed Reader',
        );
    }

    private function model(): DigestModel
    {
        $entry = new DigestEntry('Rust 1.80', 'Rust Blog', 'Summary.', 'https://reader.example/?entry=1', null, null, null, null, null);

        return new DigestModel([new DigestGroup('rust', 1, [$entry], false, 'https://reader.example/?q=rust')], 1);
    }

    private function user(DigestFormat $format): User
    {
        $user = new User('reader@example.com', new \DateTimeImmutable('2026-07-21 12:00:00'));
        $user->getPreferences()->setDigestFormat($format);

        return $user;
    }

    public function testHtmlFormatBuildsAlternativeWithHtmlTextAndInlineImage(): void
    {
        $set = new DigestImageSet([new EmbeddedImage('imgFAV', 'PNGBYTES', 'image/png')], []);

        $email = $this->builder($set)->build($this->user(DigestFormat::Html), $this->model());

        self::assertNotNull($email->getHtmlBody());
        self::assertNotNull($email->getTextBody());
        self::assertCount(1, $email->getAttachments(), 'The one embedded image is an inline part.');
        self::assertNotEmpty($email->getHeaders()->get('List-Unsubscribe'));
    }

    public function testTextFormatBuildsPlainTextOnly(): void
    {
        $email = $this->builder(new DigestImageSet([], []))->build($this->user(DigestFormat::Text), $this->model());

        self::assertNull($email->getHtmlBody());
        self::assertNotNull($email->getTextBody());
        self::assertCount(0, $email->getAttachments());
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php bin/phpunit tests/Service/Mail/Digest/DigestMailBuilderTest.php`
Expected: FAIL — `DigestMailBuilder` does not exist.

- [ ] **Step 3: Implement `DigestMailBuilder`**

```php
<?php

declare(strict_types=1);

namespace App\Service\Mail\Digest;

use App\Entity\User;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;

/**
 * Builds the digest Email from a DigestModel, honouring the recipient's
 * digest_format: `text` sends the existing plain body only; `html` adds the
 * airy HTML body plus its CID images, keeping the text part as the alternative
 * fallback (#726).
 */
final readonly class DigestMailBuilder
{
    public function __construct(
        private DigestPageBuilder $pageBuilder,
        private DigestImageEmbedderInterface $embedder,
        private DigestTextRenderer $textRenderer,
        private DigestHtmlRenderer $htmlRenderer,
        private DigestLinkBuilder $links,
        #[Autowire('%env(MAIL_FROM)%')]
        private string $fromAddress,
        #[Autowire('%env(MAIL_FROM_NAME)%')]
        private string $fromName,
    ) {
    }

    public function build(User $user, DigestModel $model): Email
    {
        $locale = $user->getLocale();
        $text = $this->textRenderer->render($model, $locale);

        $email = (new Email())
            ->from(new Address($this->fromAddress, $this->fromName))
            ->to($user->getEmail())
            ->subject($text->subject)
            ->text($text->body);

        $email->getHeaders()->addTextHeader('List-Unsubscribe', '<' . $this->links->settingsEmailUrl() . '>');

        if ($user->getPreferences()->getDigestFormat() === DigestFormat::Text) {
            return $email;
        }

        return $this->addHtml($email, $model, $locale);
    }

    private function addHtml(Email $email, DigestModel $model, string $locale): Email
    {
        $page = $this->pageBuilder->build($model, DigestPageBuilder::DEFAULT_MAX_CARDS);
        $images = $this->embedder->embed($page);

        $email->html($this->htmlRenderer->render($page, $images, $locale));
        foreach ($images->images as $image) {
            $email->embed($image->bytes, $image->cid, $image->contentType);
        }

        return $email;
    }
}
```

- [ ] **Step 4: Simplify `DigestMailer` to a transport**

```php
<?php

declare(strict_types=1);

namespace App\Service\Mail\Digest;

use App\Entity\User;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\MailerInterface;

/**
 * Sends a rendered digest to its recipient. The message shape (plain text, or
 * HTML + text) is decided by DigestMailBuilder from the user's digest_format.
 */
final readonly class DigestMailer implements DigestMailerInterface
{
    public function __construct(
        private MailerInterface $mailer,
        private DigestMailBuilder $builder,
    ) {
    }

    /**
     * @throws TransportExceptionInterface
     */
    public function send(User $user, DigestModel $model): void
    {
        $this->mailer->send($this->builder->build($user, $model));
    }
}
```

- [ ] **Step 5: Rewrite `DigestMailerTest`**

Replace the setup so the transport is stubbed and the `DigestMailer` wraps a real `DigestMailBuilder` (reuse the `builder()` construction from `DigestMailBuilderTest`, or inject a stub `DigestMailBuilder` returning a fixed `Email`). Keep the assertions that matter through the transport: an HTML-format user yields a message whose `getHtmlBody()` is non-null and whose `getTextBody()` contains `Settings` (en) / `Einstellungen` (de); a text-format user yields `getHtmlBody() === null`. Delete the old `assertNull($email->getHtmlBody())` unconditional assertion.

- [ ] **Step 6: Run tests to verify they pass**

Run: `php bin/phpunit tests/Service/Mail/Digest`
Expected: PASS. Also run `tests/Service/Worker/SendDueDigestsHandlerTest.php` and `SendDueDigestsTest.php` — they route through `DigestMailerInterface` and must stay green (the interface is unchanged).

- [ ] **Step 7: Commit**

```bash
git add backend/src/Service/Mail/Digest/DigestMailBuilder.php backend/src/Service/Mail/Digest/DigestMailer.php backend/tests/Service/Mail/Digest
git commit -m "feat(#726): assemble the digest email and branch on digest_format"
```

---

### Task 9: `SendTestDigest` honours the format (verification)

**Files:**
- Test: `backend/tests/Service/Mail/Digest/SendTestDigestTest.php` (extend)

`SendTestDigest` already routes through `DigestMailerInterface::send(User, DigestModel)`, so it inherits the format branch with no code change. Add a test that proves it.

**Interfaces:**
- Consumes: `SendTestDigest`, `DigestFormat`.

- [ ] **Step 1: Write the failing test**

Add a test that sets the user's `digest_format` to `Html`, runs `SendTestDigest::send($user, $days)`, and asserts the captured `Email` has a non-null HTML body; and a second with `Text` asserting `getHtmlBody()` is null. Capture the `Email` via the same stubbed `MailerInterface` pattern the existing `SendTestDigestTest` uses (it already builds a real mailer or a fake — reuse that seam; if it currently stubs `DigestMailerInterface`, switch that one test to a real `DigestMailer` + `DigestMailBuilder` so the format branch is exercised end to end).

- [ ] **Step 2: Run to verify it fails, implement (test-only), run to verify it passes**

Run: `php bin/phpunit tests/Service/Mail/Digest/SendTestDigestTest.php`
Expected: PASS once the test builds the real mailer/builder chain. No production code changes.

- [ ] **Step 3: Full backend gate**

Run: `php bin/phpunit && composer check && composer md`
Expected: all green; every touched `src` file PHPMD-clean. Warm the cache first for stan: `php bin/console cache:warmup`.

- [ ] **Step 4: Commit**

```bash
git add backend/tests/Service/Mail/Digest/SendTestDigestTest.php
git commit -m "test(#726): SendTestDigest exercises the chosen digest format"
```

---

### Task 10: Frontend — the `digest_format` control

**Files:**
- Modify: `frontend/src/app/core/digest-writer.ts` (`DigestConfig.format`)
- Modify: `frontend/src/app/core/auth.service.ts` (`CurrentUser.preferences.digest.format` type)
- Modify: `frontend/src/app/core/digest.service.ts` (signal, setter, adopt, reset, writeAll)
- Modify: `frontend/src/app/settings/email-section.component.ts` (`onFormat`)
- Modify: `frontend/src/app/settings/email-section.component.html` (format `<select>`)
- Modify: the Transloco catalogues holding `settings.email.cadence` (add `format`, `formatHtml`, `formatText`)
- Test: `frontend/src/app/core/digest.service.spec.ts` (or the existing digest-service spec)

**Interfaces:**
- Consumes: the backend `me` payload's `digest.format` (Task 2).
- Produces: a `format` field on `DigestConfig`, sent in every `PATCH /api/me/digest`.

- [ ] **Step 1: Write the failing frontend test**

In the digest-service spec, assert that `setFormat('text')` sends a `write()` whose config includes `format: 'text'`, and that `adopt()` reads `format` from the payload. Mirror the existing `setCadence` spec exactly.

- [ ] **Step 2: Run to verify it fails**

Run: `docker compose exec -T frontend npm test -- --testPathPattern digest`
Expected: FAIL — `setFormat` / `format` do not exist.

- [ ] **Step 3: Add `format` to `DigestConfig`**

`digest-writer.ts`:

```ts
export interface DigestConfig {
  enabled: boolean;
  cadence: 'daily' | 'weekly';
  sendHour: number;
  weekday: number;
  format: 'html' | 'text';
}
```

- [ ] **Step 4: Add `format` to the `CurrentUser` digest type**

In `auth.service.ts`, find the `digest` shape under `preferences` (it has `cadence: 'daily' | 'weekly'`) and add `format: 'html' | 'text';` beside it.

- [ ] **Step 5: Thread `format` through `DigestService`**

`digest.service.ts`: add `const DEFAULT_FORMAT = 'html';`, `readonly format = signal<'html' | 'text'>(DEFAULT_FORMAT);`, `setFormat(format: 'html' | 'text'): void { this.format.set(format); this.writeAll(); }`; in `adopt()` add `this.format.set(digest?.format ?? DEFAULT_FORMAT);`; in `reset()` add `this.format.set(DEFAULT_FORMAT);`; in `writeAll()` add `format: this.format(),` to the `config` object.

- [ ] **Step 6: Add the control handler and template**

`email-section.component.ts`:

```ts
  onFormat(event: Event): void {
    const format = (event.target as HTMLSelectElement).value as 'html' | 'text';
    this.digest.setFormat(format);
  }
```

`email-section.component.html` (mirror the cadence `app-settings-row`, placed beside it):

```html
<app-settings-row [stackable]="true" [title]="'settings.email.format' | transloco">
  <select
    data-testid="digest-format"
    [disabled]="controlsDisabled()"
    (change)="onFormat($event)"
  >
    <option value="html" [selected]="digest.format() === 'html'">
      {{ 'settings.email.formatHtml' | transloco }}
    </option>
    <option value="text" [selected]="digest.format() === 'text'">
      {{ 'settings.email.formatText' | transloco }}
    </option>
  </select>
</app-settings-row>
```

- [ ] **Step 7: Add the Transloco keys**

Run: `grep -rln "settings.email.cadence\|\"cadence\"" frontend/src` to find the en/de catalogues. Add, beside `cadence`/`daily`/`weekly`:
- en: `format: 'Format'`, `formatHtml: 'HTML (rich)'`, `formatText: 'Plain text'`
- de: `format: 'Format'`, `formatHtml: 'HTML (mit Bildern)'`, `formatText: 'Nur Text'`

- [ ] **Step 8: Run tests and the gate**

Run: `docker compose exec -T frontend npm test -- --testPathPattern digest` then `npm run check`.
Expected: PASS; ESLint + Prettier + Stylelint + Jest green.

- [ ] **Step 9: Commit**

```bash
git add frontend/src/app/core/digest-writer.ts frontend/src/app/core/auth.service.ts frontend/src/app/core/digest.service.ts frontend/src/app/settings/email-section.component.ts frontend/src/app/settings/email-section.component.html frontend/src/app/**/i18n/**
git commit -m "feat(#726): add the digest format control to email settings"
```

---

## Final verification

- [ ] `php bin/phpunit` (SQLite) and `docker compose exec php vendor/bin/phpunit` (MySQL) both green.
- [ ] `composer check` + `composer md` green; `composer infection:diff` meets `minMsi`.
- [ ] Migration leg: migrate from empty on SQLite and MySQL, then `doctrine:schema:validate`.
- [ ] `npm run check` green.
- [ ] Manual: `SendTestDigest` against the Docker stack, once with `digest_format=html` and once with `text`; open both in a real client (Apple Mail / Gmail web). Confirm embedded thumbnails and favicons render with no network, the text mail is plain, and the HTML body stays well under Gmail's ~102 KB clip at the 30-card cap.
- [ ] Scan today's dev log (`ls -t backend/var/log/dev-*.log | head -1`) for deprecations or swallowed image-fetch errors.

## Self-review notes

- **Spec coverage:** HTML renderer (T7), CID images fetched+resized+deduped (T3/T6), ~30 cap (T5), format preference end to end (T1/T2/T10), mailer branch (T8), SendTestDigest (T9), `List-Unsubscribe` (T8), settings link (T7). All spec sections map to a task.
- **Type consistency:** `DigestImageSet::cidFor`, `EmbeddedImage{cid,bytes,contentType}`, `DigestPageGroup{term,totalCount,cards,remaining,moreUrl}`, `DigestFormat{Html,Text}`, `DigestPageBuilder::DEFAULT_MAX_CARDS` are used identically across T5–T8.
- **Open risk:** `DigestHtmlRenderer::today()` reads the wall clock; if an Infection mutant survives there, inject `ClockInterface`. Confirm `auth.service.ts`'s exact `digest` type name when editing (T10 step 4).
