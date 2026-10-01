# Responsive feed images (#1330) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Keep every declared-width rendition of an entry's lead picture (Media RSS variants, the body `<img>`'s `w`-descriptor `srcset`), persist it next to the image, serve it as `imageRenditions`, and let every list block hand the browser a `srcset` + `sizes` so it loads the smallest sufficient file.

**Architecture:** The parser collects a rendition ladder per declared image (`DeclaredImageModel::$renditions`, values of the new `App\Entity\ImageRendition`) and merges ladders only between URLs that `ImageIdentityModel::isSameAsset()` calls one picture and whose declared dimensions describe one crop. `EntryImageWriter` upgrades, dedupes and sorts the ladder and stores it in a nullable JSON column `image_renditions` on the `EntryImage` embeddable; every mutator that replaces or clears the URL clears it. `EntryJson` and the backup carry it additively; the Angular list blocks bind it through one `img[appRenditions]` directive with a per-block `sizes` derived from the block's CSS.

**Tech Stack:** PHP 8.4, Symfony 7.4, Doctrine ORM (MySQL 8.4 + SQLite), PHPUnit 12; Angular 20 standalone components + signals, Jest (jsdom).

**Spec:** GitHub issue #1330 (https://github.com/larspohlmann/simple-feed-reader/issues/1330)

## Global Constraints

- PHP 8.4 / Symfony 7.4 LTS; `declare(strict_types=1)` in every PHP file of `src`, `tests`, `migrations`.
- CLAUDE.md Clean Code is binding: intent-revealing names, no abbreviations, ≤3 parameters, no boolean flags, guard clauses, `final readonly` by default, one role per folder (`Model/`, `Support/`, `Factory/`, `Pass/`, `Dto/`, `Exception/`), no `new` on a collaborator in a method.
- Comments only when a future reader would get the code wrong without them; one line, three at most. Delete restating docblocks in code you touch.
- PHPStan level max, no new baseline, no unexplained `@phpstan-ignore` (`composer stan`; warm the cache first: `bin/console cache:warmup`).
- Every touched `src` file PHPMD-clean (`composer md`), phpcs clean (`composer cs`), phptramp clean (`composer tramp`).
- Mutation gate: `composer infection:diff` must meet `minMsi: 80` (`backend/infection.json5`); it ignores untracked files, so commit before running it.
- Commit format `type(#1330): lower-case summary`; no attribution lines.
- Branch `feature/1330-responsive-feed-images` off `develop` (check `git status` and for concurrent sessions before any checkout); PR into `develop` with body `Closes #1330`.
- Backend tests on both legs: `php bin/phpunit` (SQLite, from `backend/`) and `docker compose exec php composer test` (MySQL).
- Frontend tests only inside the container: `docker compose exec -T frontend npm test -- <path>`; never two Jest runs at once. `npm run check` (ESLint + Prettier 100-col + Stylelint + Jest) is the gate: `docker compose exec -T frontend npm run check`.
- Never run e2e from another checkout; e2e is not part of this plan's gates.
- Working directory: commands and `git add` paths in Tasks 1–7 are relative to `backend/`; Tasks 8–10 run from the repository root unless a step says otherwise (`docker compose` works from either).
- Keep a native iOS client viable: the API change is additive JSON only.
- Out of scope (possible follow-ups): `FeedPreviewJson` and the mail digest; WordPress-JSON (`WordPressJsonParser`) jetpack image + body ladder; backfilling ladders onto already-stored entries; reading `?w=` query widths (Bild) as declared widths.

## File Structure

| File | Responsibility |
|---|---|
| `backend/src/Service/Image/Model/ImageIdentityModel.php` (moved from `Service/Reader/Model/`) | URL fingerprint; `isSameAsset()` decides "same picture" |
| `backend/src/Service/Image/Support/ImageProxyUrl.php` (moved from `Service/Reader/Support/`) | unwraps proxy/fetch URLs (Substack `image/fetch`) |
| `backend/tests/Service/Image/Model/ImageIdentityModelTest.php` (moved) | the matcher's existing tests |
| `backend/src/Service/Reader/{ReaderLeadImage,PageRepair/NoscriptImageUnwrapper,Model/LeadFigureCaptionsModel,Model/FeedMediaModel,Model/PageImageInventoryModel,BodyCleaning/BodyCleaningStep/TeaserPlayerInserter,Media/PageMediaInserter}.php` | import the moved model |
| `backend/tests/Service/Reader/Model/PageImageInventoryModelTest.php` | imports the moved model |
| `backend/src/Entity/ImageRendition.php` (new) | one `{url, width}` rendition: storage shape, completeness, ladder ordering |
| `backend/src/Service/Image/Model/DeclaredImageModel.php` | carries `renditions`; `joinedWith()` merges same-picture, same-crop ladders |
| `backend/src/Service/Image/Model/ImageDimensionsModel.php` | `isAnotherCropThan()` aspect comparison |
| `backend/src/Service/Html/Support/Srcset.php` | `candidates()` becomes public |
| `backend/src/Service/Parser/ItemImageExtractor.php` | builds own + srcset renditions; Media RSS ladder of the widest picture |
| `backend/src/Service/Parser/FeedItemImageSelector.php` | joins the body image's ladder onto a declared image (RSS 2, Atom, new RSS 1) |
| `backend/src/Service/Parser/FeedFormatParser/Rss1Parser.php` | goes through `FeedItemImageSelector::fromRss1()` |
| `backend/tests/Support/FeedFormatParsers.php` | wires `Rss1Parser` with the selector |
| `backend/src/Entity/EntryImage.php` | `image_renditions` JSON column; `storeRenditions()`, `getRenditions()`; `storePending()`/`drop()` clear it |
| `backend/migrations/Version20261001150000.php` (new) | adds `entry.image_renditions` (JSON on MySQL, CLOB on SQLite) |
| `backend/src/Service/Ingest/EntryImageWriter.php` | stores the https-upgraded, deduped, sorted ladder |
| `backend/src/Http/EntryJson.php` | `imageRenditions` on list and detail rows |
| `backend/src/Service/Backup/BackupLines.php`, `Dto/EntryLine.php`, `backend/src/Repository/EntryBatchInserter.php` | carry `imageRenditions` through export and restore |
| `backend/tests/Support/{BackupFieldDeclarations,FullyPopulatedAccount}.php`, `backend/tests/Service/Backup/Support/BackupSchemaCoverageTest.php`, `docs/backup.md` | backup coverage for the new field |
| `frontend/src/app/reader/models.ts` | `ImageRenditionDto`, `EntryDto.imageRenditions` |
| `frontend/src/app/reader/list/preview-image.ts` | `renditionSrcset()` pure helper |
| `frontend/src/app/reader/list/renditions.directive.ts` (new) | `img[appRenditions]`: binds `srcset`/`sizes`, or nothing |
| entry-row, entry-hero, entry-wide, entry-split, entry-thumb `.html` + `.ts` | bind the directive with their `sizes` |
| 21 specs + `frontend/e2e/support/reader.ts` | `imageRenditions: []` in every typed `EntryDto` literal |
| `docs/design-language.md` | the per-block `sizes` table |

---

### Task 1: Move the image identity matcher to the Image module

A pure move: no behaviour changes. Image depends only on Clock/Fetch/Url, Reader and Parser already depend on Image, and the moved classes import only each other and `App\Service\Url\Support\AbsoluteHttpUrl`, so no Service-module cycle appears. `ImageProxyUrl` has no test file of its own; `ImageIdentityModelTest` covers it (its imgproxy, `?url=` and percent-encoded-path cases).

**Files:**
- Move: `backend/src/Service/Reader/Model/ImageIdentityModel.php` → `backend/src/Service/Image/Model/ImageIdentityModel.php`
- Move: `backend/src/Service/Reader/Support/ImageProxyUrl.php` → `backend/src/Service/Image/Support/ImageProxyUrl.php`
- Move: `backend/tests/Service/Reader/Model/ImageIdentityModelTest.php` → `backend/tests/Service/Image/Model/ImageIdentityModelTest.php`
- Modify: `backend/src/Service/Reader/ReaderLeadImage.php`, `backend/src/Service/Reader/PageRepair/NoscriptImageUnwrapper.php`, `backend/src/Service/Reader/Model/LeadFigureCaptionsModel.php`, `backend/src/Service/Reader/Model/FeedMediaModel.php`, `backend/src/Service/Reader/Model/PageImageInventoryModel.php`, `backend/src/Service/Reader/BodyCleaning/BodyCleaningStep/TeaserPlayerInserter.php`, `backend/src/Service/Reader/Media/PageMediaInserter.php`, `backend/tests/Service/Reader/Model/PageImageInventoryModelTest.php`

**Interfaces:**
- Consumes: nothing.
- Produces: `App\Service\Image\Model\ImageIdentityModel` (`fromUrl(string): self`, `isSameAsset(self): bool`, `matches(self): bool`, `isShareRender(): bool`) and `App\Service\Image\Support\ImageProxyUrl::resolve(string): string`, unchanged in behaviour.

- [ ] **Step 1: Move the test first**

```bash
cd backend
git mv tests/Service/Reader/Model/ImageIdentityModelTest.php tests/Service/Image/Model/ImageIdentityModelTest.php
```

In the moved test replace the header lines

```php
namespace App\Tests\Service\Reader\Model;

use App\Service\Reader\Model\ImageIdentityModel;
```

with

```php
namespace App\Tests\Service\Image\Model;

use App\Service\Image\Model\ImageIdentityModel;
```

- [ ] **Step 2: Run it to see it fail**

Run: `php bin/phpunit tests/Service/Image/Model/ImageIdentityModelTest.php`
Expected: FAIL / ERROR — `Class "App\Service\Image\Model\ImageIdentityModel" not found`.

- [ ] **Step 3: Move the two classes**

```bash
git mv src/Service/Reader/Model/ImageIdentityModel.php src/Service/Image/Model/ImageIdentityModel.php
git mv src/Service/Reader/Support/ImageProxyUrl.php src/Service/Image/Support/ImageProxyUrl.php
```

`src/Service/Image/Model/ImageIdentityModel.php` header becomes:

```php
namespace App\Service\Image\Model;

use App\Service\Image\Support\ImageProxyUrl;
```

`src/Service/Image/Support/ImageProxyUrl.php` header becomes:

```php
namespace App\Service\Image\Support;

use App\Service\Url\Support\AbsoluteHttpUrl;
```

Update every user (exact `use` blocks after the edit):

`src/Service/Reader/ReaderLeadImage.php`:
```php
use App\Service\Image\Model\ImageIdentityModel;
use App\Service\Reader\Model\LeadImageCandidateModel;
use App\Service\Url\Support\AbsoluteHttpUrl;
use Dom\Element;
use Dom\HTMLDocument;
```

`src/Service/Reader/PageRepair/NoscriptImageUnwrapper.php`:
```php
use App\Service\Image\Model\ImageIdentityModel;
use Dom\Element;
use Dom\HTMLDocument;
```

`src/Service/Reader/Model/LeadFigureCaptionsModel.php` (same namespace before, so it gains an import):
```php
use App\Service\Image\Model\ImageIdentityModel;
use App\Service\Text\Support\Whitespace;
use App\Service\Url\Support\AbsoluteHttpUrl;
use Dom\Element;
use Dom\HTMLDocument;
```

`src/Service/Reader/Model/FeedMediaModel.php`:
```php
use App\Entity\Entry;
use App\Entity\EntryAttachment;
use App\Entity\EntryMedium;
use App\Service\Image\Model\ImageIdentityModel;
```

`src/Service/Reader/Model/PageImageInventoryModel.php`:
```php
use App\Service\Html\Support\Srcset;
use App\Service\Image\Model\ImageIdentityModel;
use Dom\HTMLDocument;
```

`src/Service/Reader/BodyCleaning/BodyCleaningStep/TeaserPlayerInserter.php`:
```php
use App\Service\Image\Model\ImageIdentityModel;
use App\Service\Reader\BodyCleaning\Pass\BodyCleaningPass;
use App\Service\Reader\Media\Model\ArticleMediaModel;
use App\Service\Reader\Media\Model\MediaCandidateModel;
use App\Service\Reader\Media\Teaser\Model\TeaserPlayerModel;
use App\Service\Reader\Media\Teaser\TeaserPlayerMarkup;
use Dom\Element;
use Dom\HTMLDocument;
```

`src/Service/Reader/Media/PageMediaInserter.php`:
```php
use App\Service\Image\Model\ImageIdentityModel;
use App\Service\Reader\Media\Model\ArticleMediaModel;
use App\Service\Reader\Media\Model\EmbedTargetModel;
use App\Service\Reader\Media\Model\MediaCandidateModel;
use App\Service\Reader\Media\Model\MediaInsertionPlanModel;
use App\Service\Reader\Media\Model\MediaKind;
use App\Service\Reader\Media\Model\PageTextBlocksModel;
use Dom\Element;
use Dom\HTMLDocument;
```

`tests/Service/Reader/Model/PageImageInventoryModelTest.php`:
```php
use App\Service\Image\Model\ImageIdentityModel;
use App\Service\Reader\Model\PageImageInventoryModel;
use App\Tests\Support\ParsesHtml;
use PHPUnit\Framework\TestCase;
```

- [ ] **Step 4: Prove nothing still names the old paths**

Run: `grep -rn 'Reader\\Model\\ImageIdentityModel\|Reader\\Support\\ImageProxyUrl' src tests config`
Expected: no output. (`tests/Service/Reader/Model/LeadFigureCaptionsModelTest.php` mentions the class only in a comment; leave it.)

- [ ] **Step 5: Run the tests and the module rules**

Run: `php bin/phpunit tests/Service/Image tests/Service/Reader`
Expected: PASS.
Run: `bin/console cache:warmup && composer stan`
Expected: no errors (`ServiceRoleRule`, `ServiceModuleCycleRule` pass).

- [ ] **Step 6: Commit**

```bash
git add src/Service/Image/Model/ImageIdentityModel.php src/Service/Image/Support/ImageProxyUrl.php \
  src/Service/Reader tests/Service/Image/Model/ImageIdentityModelTest.php \
  tests/Service/Reader/Model/PageImageInventoryModelTest.php
git commit -m "refactor(#1330): move the image identity matcher to the image module"
```

---

### Task 2: Parse the rendition ladder of each declared image

**Files:**
- Create: `backend/src/Entity/ImageRendition.php`
- Modify: `backend/src/Service/Image/Model/DeclaredImageModel.php`
- Modify: `backend/src/Service/Html/Support/Srcset.php` (`candidates()` public)
- Modify: `backend/src/Service/Parser/ItemImageExtractor.php`
- Test: `backend/tests/Service/Parser/ItemImageExtractorTest.php`

**Interfaces:**
- Consumes: `App\Service\Html\Support\Srcset`, `App\Service\Html\Model\SrcsetCandidateModel` (`public string $url`, `public ?int $width`).
- Produces:
  - `App\Entity\ImageRendition` — `final readonly`, `__construct(public string $url, public int $width)`.
  - `DeclaredImageModel::__construct(string $url, ?int $width = null, ?int $height = null, array $renditions = [])`, `public array $renditions` typed `list<ImageRendition>`.
  - `Srcset::candidates(?string $srcset): list<SrcsetCandidateModel>` (now public).
  - Extractor: every image built from an element with a declared width carries `[new ImageRendition($url, $width)]`; `fromHtml()` carries the srcset `w` candidates first, then the `src` with its `width` attribute.

- [ ] **Step 1: Write the failing tests**

Append to `backend/tests/Service/Parser/ItemImageExtractorTest.php` (add `use App\Entity\ImageRendition;` to its imports):

```php
    public function testReadsTheWidthDescribedSrcsetOfABodyImage(): void
    {
        $image = $this->extractor->fromHtml(
            '<img width="696" height="464" src="https://mag.example/funk-system-1024x683.jpg"'
            . ' srcset="https://mag.example/funk-system-1024x683.jpg 1024w,'
            . ' https://mag.example/funk-system-300x200.jpg 300w,'
            . ' https://mag.example/funk-system-1536x1024.jpg 1536w">',
        );

        self::assertNotNull($image);
        self::assertSame('https://mag.example/funk-system-1024x683.jpg', $image->url);
        self::assertEquals(
            [
                new ImageRendition('https://mag.example/funk-system-1024x683.jpg', 1024),
                new ImageRendition('https://mag.example/funk-system-300x200.jpg', 300),
                new ImageRendition('https://mag.example/funk-system-1536x1024.jpg', 1536),
                new ImageRendition('https://mag.example/funk-system-1024x683.jpg', 696),
            ],
            $image->renditions,
        );
    }

    public function testIgnoresDensityBareAndMalformedSrcsetCandidates(): void
    {
        $image = $this->extractor->fromHtml(
            '<img src="https://i/a.jpg" srcset="https://i/a-2x.jpg 2x, https://i/bare.jpg,'
            . ' https://i/zero.jpg 0w, https://i/wide.jpg 800wide, https://i/a-640.jpg 640w">',
        );

        self::assertNotNull($image);
        self::assertEquals([new ImageRendition('https://i/a-640.jpg', 640)], $image->renditions);
    }

    public function testAnInlineImgsDeclaredWidthIsItsOwnRendition(): void
    {
        $image = $this->extractor->fromHtml('<img src="https://i/a.jpg" width="640" height="360">');

        self::assertNotNull($image);
        self::assertEquals([new ImageRendition('https://i/a.jpg', 640)], $image->renditions);
    }

    public function testAnInlineImgWithoutWidthOrSrcsetHasNoRenditions(): void
    {
        $image = $this->extractor->fromHtml('<img src="https://i/a.jpg" height="360">');

        self::assertNotNull($image);
        self::assertSame([], $image->renditions);
    }

    public function testAnEnclosureWithADeclaredWidthIsItsOwnRendition(): void
    {
        $image = $this->extractor->fromRssEnclosure(
            $this->item('<enclosure url="https://i/e.jpg" type="image/jpeg" width="1200"/>'),
        );

        self::assertNotNull($image);
        self::assertEquals([new ImageRendition('https://i/e.jpg', 1200)], $image->renditions);
    }

    public function testAMediaVariantWithoutAWidthHasNoRendition(): void
    {
        $image = $this->extractor->fromMedia($this->item('<media:content url="https://i/a.jpg" medium="image"/>'));

        self::assertNotNull($image);
        self::assertSame([], $image->renditions);
    }
```

- [ ] **Step 2: Run them to see them fail**

Run: `php bin/phpunit tests/Service/Parser/ItemImageExtractorTest.php`
Expected: ERROR — `Class "App\Entity\ImageRendition" not found`.

- [ ] **Step 3: Implement**

`backend/src/Entity/ImageRendition.php` (Task 4 extends it with its storage shape):

```php
<?php

declare(strict_types=1);

namespace App\Entity;

/** One declared-width rendition of an entry's lead picture, as a `srcset` candidate names it. */
final readonly class ImageRendition
{
    public function __construct(
        public string $url,
        public int $width,
    ) {
    }
}
```

`backend/src/Service/Image/Model/DeclaredImageModel.php`:

```php
<?php

declare(strict_types=1);

namespace App\Service\Image\Model;

use App\Entity\ImageRendition;

/**
 * An image URL with the width and height its source declared, each independently nullable (most feeds declare
 * neither; the Guardian declares width only). Null means unknown: reserve no space rather than guess.
 */
final readonly class DeclaredImageModel
{
    /** @param list<ImageRendition> $renditions the same picture at each declared width */
    public function __construct(
        public string $url,
        public ?int $width = null,
        public ?int $height = null,
        public array $renditions = [],
    ) {
    }

    public function declaresBeacon(): bool
    {
        return $this->width !== null
            && $this->height !== null
            && (new ImageDimensionsModel($this->width, $this->height))->isBeacon();
    }
}
```

`backend/src/Service/Html/Support/Srcset.php` — only the visibility and docblock of `candidates` change:

```php
    /** @return list<SrcsetCandidateModel> every candidate, in list order */
    public static function candidates(?string $srcset): array
    {
        if ($srcset === null) {
            return [];
        }

        return array_map(self::candidateFrom(...), self::candidateTokens($srcset));
    }
```

`backend/src/Service/Parser/ItemImageExtractor.php` — full new version:

```php
<?php

declare(strict_types=1);

namespace App\Service\Parser;

use App\Entity\ImageRendition;
use App\Service\Html\Support\HtmlDocumentParser;
use App\Service\Html\Support\Srcset;
use App\Service\Image\Model\DeclaredImageModel;
use App\Service\Parser\Support\MediaImageClassifier;
use Dom\Element;

/**
 * The images a feed item declares, source by source; FeedItemImageSelector combines them in each format's order.
 * Within Media RSS the widest variant wins (#148): an undeclared width loses to any declared one, document order breaks
 * ties, and URLs stay unresolved. Every declared width is a rendition, as is each srcset `w` candidate (#1330).
 */
final readonly class ItemImageExtractor
{
    private const string MEDIA_NS = 'http://search.yahoo.com/mrss/';

    /** Media RSS image, searching <media:group> when nothing is attached directly. */
    public function fromMedia(\DOMElement $item): ?DeclaredImageModel
    {
        $candidates = self::mediaCandidatesIn($item);

        foreach ($item->childNodes as $child) {
            if (self::isMediaElement($child, 'group')) {
                /** @var \DOMElement $child */
                $candidates = [...$candidates, ...self::mediaCandidatesIn($child)];
            }
        }

        return self::widest($candidates);
    }

    /** RSS 2.0 <enclosure type="image/*" url="…">. */
    public function fromRssEnclosure(\DOMElement $item): ?DeclaredImageModel
    {
        foreach ($item->childNodes as $child) {
            if (!$child instanceof \DOMElement || $child->localName !== 'enclosure') {
                continue;
            }
            if (!str_starts_with(strtolower($child->getAttribute('type')), 'image/')) {
                continue;
            }
            $url = trim($child->getAttribute('url'));
            if ($url !== '') {
                return self::imageFrom($child, $url);
            }
        }

        return null;
    }

    /** Atom <link rel="enclosure" type="image/*" href="…">. */
    public function fromAtomEnclosure(\DOMElement $entry, string $atomNamespace): ?DeclaredImageModel
    {
        foreach ($entry->childNodes as $child) {
            if (
                !$child instanceof \DOMElement
                || $child->localName !== 'link'
                || $child->namespaceURI !== $atomNamespace
                || $child->getAttribute('rel') !== 'enclosure'
            ) {
                continue;
            }
            if (!str_starts_with(strtolower($child->getAttribute('type')), 'image/')) {
                continue;
            }
            $href = trim($child->getAttribute('href'));
            if ($href !== '') {
                return self::imageFrom($child, $href);
            }
        }

        return null;
    }

    /**
     * Non-standard item-level <image>/<image_big> carrying a `url` attribute: <image_big> wins, then the widest.
     * Requiring the attribute keeps the standard channel <image>, which nests a <url> child, from matching.
     */
    public function fromCustomImageElement(\DOMElement $item): ?DeclaredImageModel
    {
        return self::widest(self::customImageCandidates($item, 'image_big'))
            ?? self::widest(self::customImageCandidates($item, 'image'));
    }

    /** First non-beacon <img src="…"> in a fragment of HTML, with the dimensions and renditions it declares. */
    public function fromHtml(?string $html): ?DeclaredImageModel
    {
        if ($html === null || $html === '') {
            return null;
        }
        $document = HtmlDocumentParser::parseOrEmpty($html);
        foreach ($document->getElementsByTagName('img') as $element) {
            $image = self::inlineImage($element);
            if ($image !== null && !$image->declaresBeacon()) {
                return $image;
            }
        }

        return null;
    }

    private static function inlineImage(Element $element): ?DeclaredImageModel
    {
        $src = trim($element->getAttribute('src') ?? '');
        if ($src === '') {
            return null;
        }
        $width = self::positiveInt($element->getAttribute('width') ?? '');

        return new DeclaredImageModel(
            $src,
            $width,
            self::positiveInt($element->getAttribute('height') ?? ''),
            // srcset first: a `w` descriptor is the file's width, the width attribute only its display size.
            [...self::srcsetRenditions($element->getAttribute('srcset')), ...self::ownRendition($src, $width)],
        );
    }

    /**
     * Only a `w` descriptor states a file's pixel width; a density or bare candidate says nothing about it.
     *
     * @return list<ImageRendition>
     */
    private static function srcsetRenditions(?string $srcset): array
    {
        $renditions = [];
        foreach (Srcset::candidates($srcset) as $candidate) {
            if ($candidate->width !== null && $candidate->width > 0) {
                $renditions[] = new ImageRendition($candidate->url, $candidate->width);
            }
        }

        return $renditions;
    }

    /** @return list<ImageRendition> */
    private static function ownRendition(string $url, ?int $width): array
    {
        return $width === null ? [] : [new ImageRendition($url, $width)];
    }

    /** @return list<DeclaredImageModel> */
    private static function mediaCandidatesIn(\DOMElement $parent): array
    {
        $candidates = [];
        foreach ($parent->childNodes as $child) {
            if (!self::isMediaElement($child, 'thumbnail') && !self::isMediaElement($child, 'content')) {
                continue;
            }
            /** @var \DOMElement $child */
            $url = trim($child->getAttribute('url'));
            if ($url === '' || !MediaImageClassifier::isImage($child)) {
                continue;
            }
            $candidates[] = self::imageFrom($child, $url);
        }

        return $candidates;
    }

    /** @return list<DeclaredImageModel> */
    private static function customImageCandidates(\DOMElement $item, string $localName): array
    {
        $candidates = [];
        foreach ($item->childNodes as $child) {
            if (!$child instanceof \DOMElement || $child->localName !== $localName) {
                continue;
            }
            $url = trim($child->getAttribute('url'));
            if ($url !== '') {
                $candidates[] = self::imageFrom($child, $url);
            }
        }

        return $candidates;
    }

    private static function isMediaElement(\DOMNode $node, string $localName): bool
    {
        return $node instanceof \DOMElement
            && $node->localName === $localName
            && $node->namespaceURI === self::MEDIA_NS;
    }

    private static function imageFrom(\DOMElement $element, string $url): DeclaredImageModel
    {
        $width = self::positiveInt($element->getAttribute('width'));

        return new DeclaredImageModel(
            $url,
            $width,
            self::positiveInt($element->getAttribute('height')),
            self::ownRendition($url, $width),
        );
    }

    private static function positiveInt(string $raw): ?int
    {
        $value = filter_var(trim($raw), FILTER_VALIDATE_INT);

        return \is_int($value) && $value > 0 ? $value : null;
    }

    /** @param list<DeclaredImageModel> $candidates */
    private static function widest(array $candidates): ?DeclaredImageModel
    {
        $best = $candidates[0] ?? null;
        foreach ($candidates as $candidate) {
            if (($candidate->width ?? 0) > ($best->width ?? 0)) {
                $best = $candidate;
            }
        }

        return $best;
    }
}
```

`ImageRendition::ladder()` (Task 4) dedupes per URL keeping the first, which is why `inlineImage` lists the srcset before the `src`: 5mag declares `width="696"` on a `-1024x683.jpg` file that its srcset correctly calls `1024w`.

- [ ] **Step 4: Run the tests**

Run: `php bin/phpunit tests/Service/Parser tests/Service/Html tests/Service/Image`
Expected: PASS (existing extractor, selector, Srcset and DeclaredImageModel tests unchanged and green).

- [ ] **Step 5: Commit**

```bash
git add src/Entity/ImageRendition.php src/Service/Image/Model/DeclaredImageModel.php \
  src/Service/Html/Support/Srcset.php src/Service/Parser/ItemImageExtractor.php \
  tests/Service/Parser/ItemImageExtractorTest.php
git commit -m "feat(#1330): parse the declared-width rendition ladder of feed images"
```

---

### Task 3: Merge ladders only between renditions of the same picture

`isSameAsset`, not `matches`: `matches` accepts any one shared filename word even across differing path UUIDs' tokens and asset numbers, while `isSameAsset` refuses two different path UUIDs, image ids or trailing asset numbers — the stricter verdict, and a wrong merge would show a different picture. Same-picture also requires the same crop when both sides declare both dimensions (Ars Technica ships a 500×500 square `media:thumbnail` of a 1152×648 picture, which `isSameAsset` rightly calls one asset).

Measured shapes covered below: Guardian (one path, signed `?width=…&s=…` query), Substack (enclosure and body both wrap one S3 URL in `substackcdn.com/image/fetch/…`), WordPress (full upload vs `-1024x683` size), Ars (square crop), kursfahrradstadt (a gallery of `…-festtag-21` / `…-festtag-33`).

**Files:**
- Modify: `backend/src/Service/Image/Model/DeclaredImageModel.php`
- Modify: `backend/src/Service/Image/Model/ImageDimensionsModel.php`
- Modify: `backend/src/Service/Parser/ItemImageExtractor.php` (`fromMedia`)
- Modify: `backend/src/Service/Parser/FeedItemImageSelector.php`
- Modify: `backend/src/Service/Parser/FeedFormatParser/Rss1Parser.php`
- Modify: `backend/tests/Support/FeedFormatParsers.php`
- Test: `backend/tests/Service/Image/Model/DeclaredImageModelTest.php`, `backend/tests/Service/Image/Model/ImageDimensionsModelTest.php`, `backend/tests/Service/Parser/ItemImageExtractorTest.php`, `backend/tests/Service/Parser/FeedItemImageSelectorTest.php`

**Interfaces:**
- Consumes: `ImageIdentityModel::fromUrl()`, `->isSameAsset()` (Task 1); `DeclaredImageModel::$renditions`, `ImageRendition` (Task 2).
- Produces:
  - `DeclaredImageModel::joinedWith(DeclaredImageModel ...$others): DeclaredImageModel` — keeps url/width/height, appends each other's renditions when it shows the same picture in the same crop; skips itself.
  - `ImageDimensionsModel::isAnotherCropThan(ImageDimensionsModel $other): bool`.
  - `FeedItemImageSelector::fromRss1(\DOMElement $item, ?string $bodyHtml): ?DeclaredImageModel` (new); `fromRss2`/`fromAtom` signatures unchanged.
  - `Rss1Parser::__construct(FeedItemImageSelector $imageSelector, ItemMediaExtractor $mediaExtractor)`.

- [ ] **Step 1: Write the failing tests**

`backend/tests/Service/Image/Model/ImageDimensionsModelTest.php` — append:

```php
    public function testRenditionsWhoseHeightsRoundDifferentlyAreOneCrop(): void
    {
        self::assertFalse((new ImageDimensionsModel(1024, 683))->isAnotherCropThan(new ImageDimensionsModel(300, 200)));
    }

    public function testARoundingGapOfExactlyTheSummedWidthsIsStillOneCrop(): void
    {
        self::assertFalse((new ImageDimensionsModel(100, 50))->isAnotherCropThan(new ImageDimensionsModel(200, 103)));
    }

    public function testASquareCropOfALandscapePictureIsAnotherCrop(): void
    {
        $landscape = new ImageDimensionsModel(1152, 648);
        $square = new ImageDimensionsModel(500, 500);

        self::assertTrue($landscape->isAnotherCropThan($square));
        self::assertTrue($square->isAnotherCropThan($landscape));
    }
```

`backend/tests/Service/Image/Model/DeclaredImageModelTest.php` — add `use App\Entity\ImageRendition;` and append:

```php
    private const string GUARDIAN_PHOTO =
        'https://i.guim.co.uk/img/media/f6d33de551f7fcdc046178cfccc4037e79b99f3e/276_0_4639_3711/master/4639.jpg';

    private const string SUBSTACK_SOURCE = 'https%3A%2F%2Fsubstack-post-media.s3.amazonaws.com%2Fpublic%2Fimages'
        . '%2F10a5f3c6-6b92-48ff-8280-0cd3a9f25e41_750x1054.jpeg';

    private static function sized(string $url, int $width, ?int $height = null): DeclaredImageModel
    {
        return new DeclaredImageModel($url, $width, $height, [new ImageRendition($url, $width)]);
    }

    private static function substack(string $transforms): string
    {
        return 'https://substackcdn.com/image/fetch/$s_!v2GA!,' . $transforms . '/' . self::SUBSTACK_SOURCE;
    }

    public function testJoinsGuardianWidthsOfOnePathDespiteTheirSignedQueries(): void
    {
        $wide = self::sized(self::GUARDIAN_PHOTO . '?width=700&quality=85&auto=format&fit=max&s=6192bfa4', 700);
        $narrow = self::sized(self::GUARDIAN_PHOTO . '?width=140&quality=85&auto=format&fit=max&s=406198660', 140);

        self::assertEquals(
            [...$wide->renditions, ...$narrow->renditions],
            $wide->joinedWith($narrow)->renditions,
        );
    }

    public function testKeepsAnotherGuardianPhotoApart(): void
    {
        $wide = self::sized(self::GUARDIAN_PHOTO . '?width=700&s=6192bfa4', 700);
        $other = self::sized(
            'https://i.guim.co.uk/img/media/0d73ce3f33e41d2cc83104a798beda3a6a56487f/344_0_3409_2726/master/3409.jpg'
            . '?width=140&s=b77e32d9',
            140,
        );

        self::assertEquals($wide->renditions, $wide->joinedWith($other)->renditions);
    }

    public function testASubstackEnclosureTakesTheLadderOfTheBodyImageWrappingTheSameSource(): void
    {
        $enclosure = new DeclaredImageModel(self::substack('f_auto,q_auto:good,fl_progressive:steep'));
        $body = new DeclaredImageModel(
            self::substack('w_1456,c_limit,f_auto,q_auto:good,fl_progressive:steep'),
            750,
            1054,
            [
                new ImageRendition(self::substack('w_424,c_limit,f_auto,q_auto:good,fl_progressive:steep'), 424),
                new ImageRendition(self::substack('w_848,c_limit,f_auto,q_auto:good,fl_progressive:steep'), 848),
            ],
        );

        $joined = $enclosure->joinedWith($body);

        self::assertSame($enclosure->url, $joined->url);
        self::assertNull($joined->width);
        self::assertEquals($body->renditions, $joined->renditions);
    }

    public function testJoinsAWordPressSizeOfTheSameUpload(): void
    {
        $full = self::sized('https://cdn.example/wp-content/uploads/2026/09/funk-system-1800.jpg', 1800, 1200);
        $large = new DeclaredImageModel(
            'https://cdn.example/wp-content/uploads/2026/09/funk-system-1800-1024x683.jpg',
            1024,
            683,
            [new ImageRendition('https://cdn.example/wp-content/uploads/2026/09/funk-system-1800-300x200.jpg', 300)],
        );

        self::assertEquals(
            [...$full->renditions, ...$large->renditions],
            $full->joinedWith($large)->renditions,
        );
    }

    public function testKeepsASquareCropOfTheSameAssetOutInBothDirections(): void
    {
        $landscape = self::sized(
            'https://cdn.arstechnica.net/wp-content/uploads/2026/09/GettyImages-1042124682-1152x648.jpg',
            1152,
            648,
        );
        $square = self::sized(
            'https://cdn.arstechnica.net/wp-content/uploads/2026/09/GettyImages-1042124682-500x500.jpg',
            500,
            500,
        );

        self::assertEquals($landscape->renditions, $landscape->joinedWith($square)->renditions);
        self::assertEquals($square->renditions, $square->joinedWith($landscape)->renditions);
    }

    public function testJoiningItselfAddsNothing(): void
    {
        $image = self::sized('https://i/photo-landscape.jpg', 700);

        self::assertEquals($image->renditions, $image->joinedWith($image)->renditions);
    }
```

`backend/tests/Service/Parser/ItemImageExtractorTest.php` — append:

```php
    public function testCollectsEveryWidthOfTheWidestMediaPicture(): void
    {
        $photo = 'https://i.guim.co.uk/img/media/f6d33de551f7fcdc046178cfccc4037e79b99f3e'
            . '/276_0_4639_3711/master/4639.jpg';
        $image = $this->extractor->fromMedia($this->item(
            '<media:content width="140" url="' . $photo . '?width=140&amp;s=406198660"/>'
            . '<media:content width="460" url="' . $photo . '?width=460&amp;s=fed507e2"/>'
            . '<media:content width="700" url="' . $photo . '?width=700&amp;s=6192bfa4"/>',
        ));

        self::assertNotNull($image);
        self::assertSame($photo . '?width=700&s=6192bfa4', $image->url);
        self::assertEquals(
            [
                new ImageRendition($photo . '?width=700&s=6192bfa4', 700),
                new ImageRendition($photo . '?width=140&s=406198660', 140),
                new ImageRendition($photo . '?width=460&s=fed507e2', 460),
            ],
            $image->renditions,
        );
    }

    public function testKeepsAnotherPictureOfAMediaGalleryOutOfTheLadder(): void
    {
        $uploads = 'https://kursfahrradstadt.de/wp-content/uploads/2026/05/';
        $image = $this->extractor->fromMedia($this->item(
            '<media:content url="' . $uploads . 'superbuettel-eroeffnung-relli-festtag-21.jpg" medium="image"'
            . ' width="1200"/>'
            . '<media:content url="' . $uploads . 'superbuettel-eroeffnung-relli-festtag-33.jpg" medium="image"'
            . ' width="800"/>',
        ));

        self::assertNotNull($image);
        self::assertEquals(
            [new ImageRendition($uploads . 'superbuettel-eroeffnung-relli-festtag-21.jpg', 1200)],
            $image->renditions,
        );
    }

    public function testKeepsASquareThumbnailCropOutOfTheLadder(): void
    {
        $uploads = 'https://cdn.arstechnica.net/wp-content/uploads/2026/09/';
        $image = $this->extractor->fromMedia($this->item(
            '<media:content height="648" medium="image" url="' . $uploads . 'GettyImages-1042124682-1152x648.jpg"'
            . ' width="1152"/>'
            . '<media:thumbnail height="500" url="' . $uploads . 'GettyImages-1042124682-500x500.jpg" width="500"/>',
        ));

        self::assertNotNull($image);
        self::assertEquals(
            [new ImageRendition($uploads . 'GettyImages-1042124682-1152x648.jpg', 1152)],
            $image->renditions,
        );
    }
```

`backend/tests/Service/Parser/FeedItemImageSelectorTest.php` — add `use App\Entity\ImageRendition;` and append (the RSS 1.0 helper is new):

```php
    private const string SUBSTACK_SOURCE = 'https%3A%2F%2Fsubstack-post-media.s3.amazonaws.com%2Fpublic%2Fimages'
        . '%2F10a5f3c6-6b92-48ff-8280-0cd3a9f25e41_750x1054.jpeg';

    private static function substack(string $transforms): string
    {
        return 'https://substackcdn.com/image/fetch/$s_!v2GA!,' . $transforms . '/' . self::SUBSTACK_SOURCE;
    }

    private function rss1Item(string $innerXml): \DOMElement
    {
        $document = new \DOMDocument();
        /** @noinspection XmlUnusedNamespaceDeclaration */
        $rdf = '<rdf:RDF xmlns:rdf="http://www.w3.org/1999/02/22-rdf-syntax-ns#"'
            . ' xmlns="http://purl.org/rss/1.0/" xmlns:media="http://search.yahoo.com/mrss/"><item>'
            . $innerXml . '</item></rdf:RDF>';
        $document->loadXML($rdf);
        $item = $document->getElementsByTagName('item')->item(0);
        self::assertInstanceOf(\DOMElement::class, $item);

        return $item;
    }

    public function testASubstackEnclosureTakesTheBodyImagesLadder(): void
    {
        $item = $this->rss2Item(
            '<enclosure url="' . self::substack('f_auto,q_auto:good,fl_progressive:steep')
            . '" length="0" type="image/jpeg"/>',
        );
        $body = '<img src="' . self::substack('w_1456,c_limit,f_auto') . '" width="750" height="1054"'
            . ' srcset="' . self::substack('w_424,c_limit,f_auto') . ' 424w, '
            . self::substack('w_1456,c_limit,f_auto') . ' 1456w">';

        $image = $this->selector->fromRss2($item, $body);

        self::assertNotNull($image);
        self::assertSame(self::substack('f_auto,q_auto:good,fl_progressive:steep'), $image->url);
        self::assertEquals(
            [
                new ImageRendition(self::substack('w_424,c_limit,f_auto'), 424),
                new ImageRendition(self::substack('w_1456,c_limit,f_auto'), 1456),
                new ImageRendition(self::substack('w_1456,c_limit,f_auto'), 750),
            ],
            $image->renditions,
        );
    }

    public function testABodyImageOfAnotherPictureLendsNoRenditions(): void
    {
        $item = $this->rss2Item('<media:content url="https://i/harbor-lighthouse.jpg" medium="image" width="700"/>');
        $body = '<img src="https://i/mountain-summit.jpg" srcset="https://i/mountain-summit-300.jpg 300w">';

        $image = $this->selector->fromRss2($item, $body);

        self::assertNotNull($image);
        self::assertEquals([new ImageRendition('https://i/harbor-lighthouse.jpg', 700)], $image->renditions);
    }

    public function testABodyImageAloneKeepsItsOwnLadder(): void
    {
        $image = $this->selector->fromRss2(
            $this->rss2Item('<description>no media</description>'),
            '<img src="https://i/harbor-lighthouse-1024.jpg" srcset="https://i/harbor-lighthouse-300.jpg 300w">',
        );

        self::assertNotNull($image);
        self::assertEquals([new ImageRendition('https://i/harbor-lighthouse-300.jpg', 300)], $image->renditions);
    }

    public function testAnAtomEnclosureTakesTheFirstBodyImagesLadder(): void
    {
        $entry = $this->atomEntry(
            '<link rel="enclosure" type="image/jpeg" href="https://i/harbor-lighthouse.jpg"/>',
        );

        $image = $this->selector->fromAtom($entry, 'http://www.w3.org/2005/Atom', [
            null,
            '<img src="https://i/harbor-lighthouse-1024.jpg" srcset="https://i/harbor-lighthouse-300.jpg 300w">',
        ]);

        self::assertNotNull($image);
        self::assertSame('https://i/harbor-lighthouse.jpg', $image->url);
        self::assertEquals([new ImageRendition('https://i/harbor-lighthouse-300.jpg', 300)], $image->renditions);
    }

    public function testAnRss1MediaImageTakesTheBodyImagesLadder(): void
    {
        $item = $this->rss1Item('<media:content url="https://i/harbor-lighthouse.jpg" medium="image"/>');

        $image = $this->selector->fromRss1(
            $item,
            '<img src="https://i/harbor-lighthouse-1024.jpg" srcset="https://i/harbor-lighthouse-300.jpg 300w">',
        );

        self::assertNotNull($image);
        self::assertSame('https://i/harbor-lighthouse.jpg', $image->url);
        self::assertEquals([new ImageRendition('https://i/harbor-lighthouse-300.jpg', 300)], $image->renditions);
    }

    public function testAnRss1ItemFallsBackToItsBodyImage(): void
    {
        $image = $this->selector->fromRss1($this->rss1Item('<title>t</title>'), '<img src="https://i/body.jpg">');

        self::assertSame('https://i/body.jpg', $image?->url);
    }
```

- [ ] **Step 2: Run them to see them fail**

Run: `php bin/phpunit tests/Service/Image/Model tests/Service/Parser`
Expected: ERROR — `Call to undefined method …ImageDimensionsModel::isAnotherCropThan()`, `…DeclaredImageModel::joinedWith()`, `…FeedItemImageSelector::fromRss1()`; the Media RSS ladder test FAILS with only the 700 rendition.

- [ ] **Step 3: Implement**

`backend/src/Service/Image/Model/ImageDimensionsModel.php` — add after `isBeacon()`:

```php
    /** Each height may be a pixel off by rounding, which moves the cross product by up to the sum of the widths. */
    public function isAnotherCropThan(self $other): bool
    {
        return abs($this->width * $other->height - $other->width * $this->height) > $this->width + $other->width;
    }
```

`backend/src/Service/Image/Model/DeclaredImageModel.php` — full new version:

```php
<?php

declare(strict_types=1);

namespace App\Service\Image\Model;

use App\Entity\ImageRendition;

/**
 * An image URL with the width and height its source declared, each independently nullable (most feeds declare
 * neither; the Guardian declares width only). Null means unknown: reserve no space rather than guess.
 */
final readonly class DeclaredImageModel
{
    /** @param list<ImageRendition> $renditions the same picture at each declared width */
    public function __construct(
        public string $url,
        public ?int $width = null,
        public ?int $height = null,
        public array $renditions = [],
    ) {
    }

    public function declaresBeacon(): bool
    {
        return $this->declaredDimensions()?->isBeacon() ?? false;
    }

    /** This image, adding the renditions of each other image that shows the same picture in the same crop. */
    public function joinedWith(self ...$others): self
    {
        $renditions = $this->renditions;
        foreach ($others as $other) {
            if ($other !== $this && $this->showsSamePictureAs($other)) {
                $renditions = [...$renditions, ...$other->renditions];
            }
        }

        return new self($this->url, $this->width, $this->height, $renditions);
    }

    private function showsSamePictureAs(self $other): bool
    {
        return !$this->declaresAnotherCropThan($other)
            && ImageIdentityModel::fromUrl($this->url)->isSameAsset(ImageIdentityModel::fromUrl($other->url));
    }

    private function declaresAnotherCropThan(self $other): bool
    {
        $dimensions = $this->declaredDimensions();
        $otherDimensions = $other->declaredDimensions();

        return $dimensions !== null && $otherDimensions !== null && $dimensions->isAnotherCropThan($otherDimensions);
    }

    private function declaredDimensions(): ?ImageDimensionsModel
    {
        if ($this->width === null || $this->height === null) {
            return null;
        }

        return new ImageDimensionsModel($this->width, $this->height);
    }
}
```

`backend/src/Service/Parser/ItemImageExtractor.php` — `fromMedia` becomes:

```php
    /** Media RSS image, searching <media:group> when nothing is attached directly; its other widths join it. */
    public function fromMedia(\DOMElement $item): ?DeclaredImageModel
    {
        $candidates = self::mediaCandidatesIn($item);

        foreach ($item->childNodes as $child) {
            if (self::isMediaElement($child, 'group')) {
                /** @var \DOMElement $child */
                $candidates = [...$candidates, ...self::mediaCandidatesIn($child)];
            }
        }

        return self::widest($candidates)?->joinedWith(...$candidates);
    }
```

`backend/src/Service/Parser/FeedItemImageSelector.php` — full new version:

```php
<?php

declare(strict_types=1);

namespace App\Service\Parser;

use App\Service\Image\Model\DeclaredImageModel;

/** Each format's image order; the body image stands in for a missing declared one, or lends it its renditions. */
final readonly class FeedItemImageSelector
{
    public function __construct(private ItemImageExtractor $extractor)
    {
    }

    public function fromRss2(\DOMElement $item, ?string $bodyHtml): ?DeclaredImageModel
    {
        $declared = $this->extractor->fromMedia($item)
            ?? $this->extractor->fromRssEnclosure($item)
            ?? $this->extractor->fromCustomImageElement($item);

        return self::withBodyImage($declared, $this->extractor->fromHtml($bodyHtml));
    }

    public function fromRss1(\DOMElement $item, ?string $bodyHtml): ?DeclaredImageModel
    {
        $declared = $this->extractor->fromMedia($item) ?? $this->extractor->fromCustomImageElement($item);

        return self::withBodyImage($declared, $this->extractor->fromHtml($bodyHtml));
    }

    /** @param list<?string> $bodyHtmlCandidates */
    public function fromAtom(
        \DOMElement $entry,
        string $namespace,
        array $bodyHtmlCandidates,
    ): ?DeclaredImageModel {
        $declared = $this->extractor->fromMedia($entry)
            ?? $this->extractor->fromAtomEnclosure($entry, $namespace)
            ?? $this->extractor->fromCustomImageElement($entry);

        return self::withBodyImage($declared, $this->firstBodyImage($bodyHtmlCandidates));
    }

    /** @param list<?string> $bodyHtmlCandidates */
    private function firstBodyImage(array $bodyHtmlCandidates): ?DeclaredImageModel
    {
        foreach ($bodyHtmlCandidates as $bodyHtml) {
            $image = $this->extractor->fromHtml($bodyHtml);
            if ($image !== null) {
                return $image;
            }
        }

        return null;
    }

    private static function withBodyImage(
        ?DeclaredImageModel $declared,
        ?DeclaredImageModel $bodyImage,
    ): ?DeclaredImageModel {
        if ($declared === null) {
            return $bodyImage;
        }

        return $bodyImage === null ? $declared : $declared->joinedWith($bodyImage);
    }
}
```

The body HTML is now parsed for every item, not only for items without a declared image. `HtmlDocumentParser` (lexbor) parses a feed body in well under a millisecond; a 50-item refresh adds tens of milliseconds at most. Do not add a `str_contains($html, 'srcset')` shortcut: a body `<img>` without srcset still contributes its `width` rendition.

`backend/src/Service/Parser/FeedFormatParser/Rss1Parser.php` — replace `use App\Service\Parser\ItemImageExtractor;` with `use App\Service\Parser\FeedItemImageSelector;` (keep alphabetical order: it sits right after `use App\Service\Parser\Exception\FeedParseException;`), and:

```php
    public function __construct(
        private FeedItemImageSelector $imageSelector,
        private ItemMediaExtractor $mediaExtractor,
    ) {
    }
```

```php
        $contentEncoded = XmlHelper::childText($item, 'encoded', self::CONTENT_NS);
        $image = $this->imageSelector->fromRss1($item, $contentEncoded ?? $description);
        $mediaBundle = $this->mediaExtractor->extract($item);
```

`backend/tests/Support/FeedFormatParsers.php`:

```php
    public static function rss1(): Rss1Parser
    {
        return new Rss1Parser(self::imageSelector(), new ItemMediaExtractor());
    }
```

Keep `use App\Service\Parser\ItemImageExtractor;` there — `imageSelector()` still builds one.

- [ ] **Step 4: Run the tests**

Run: `php bin/phpunit tests/Service/Image tests/Service/Parser tests/Service/Preview tests/Service/Scraper`
Expected: PASS (the existing `Rss1ParserTest`, `FeedParserWiringTest` and selector tests included).

- [ ] **Step 5: Commit**

```bash
git add src/Service/Image/Model src/Service/Parser tests/Support/FeedFormatParsers.php \
  tests/Service/Image/Model tests/Service/Parser
git commit -m "feat(#1330): join rendition ladders only between renditions of one picture"
```

---

### Task 4: Persist the ladder on the entry image

Every `EntryImage` mutator was checked: `storePending()` (ingest, fill-missing) replaces the URL and clears the ladder, `drop()` (verifier, via `Entry::dropImage`) clears it, `recordMeasurement()`/`keepUnmeasured()`/`recordFailedProbe()` keep the URL and so keep the ladder. `OriginalHeroResolver` and `ReaderLeadImage` only read the image; nothing in `src/Repository` updates image columns except the restore's `EntryBatchInserter` (Task 7). Relative image URLs are never resolved against the item link anywhere — `HttpsImageUrl` drops them — so renditions get the same treatment in Task 5.

**Files:**
- Modify: `backend/src/Entity/ImageRendition.php`
- Modify: `backend/src/Entity/EntryImage.php`
- Create: `backend/migrations/Version20261001150000.php`
- Test: `backend/tests/Entity/ImageRenditionTest.php` (new), `backend/tests/Entity/EntryImageTest.php`

**Interfaces:**
- Consumes: `App\Entity\ImageRendition` (Task 2), `App\Entity\Exception\IncompleteStoredMediaException`.
- Produces:
  - `ImageRendition implements \JsonSerializable`: `isComplete(array $stored): bool`, `fromStored(array $stored): self`, `ladder(list<ImageRendition>): list<ImageRendition>` (one per URL, first wins, ascending width), `toJsonList(list<ImageRendition>): list<array{url: string, width: int}>`, `jsonSerialize(): array{url: string, width: int}`.
  - `EntryImage::storeRenditions(list<ImageRendition> $renditions): void`, `EntryImage::getRenditions(): list<ImageRendition>`; column `entry.image_renditions` (JSON, nullable).

- [ ] **Step 1: Write the failing tests**

`backend/tests/Entity/ImageRenditionTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\Exception\IncompleteStoredMediaException;
use App\Entity\ImageRendition;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ImageRenditionTest extends TestCase
{
    public function testALadderKeepsTheFirstRenditionOfEachUrlNarrowestFirst(): void
    {
        $ladder = ImageRendition::ladder([
            new ImageRendition('https://i/a-1024.jpg', 1024),
            new ImageRendition('https://i/a-300.jpg', 300),
            new ImageRendition('https://i/a-1024.jpg', 696),
        ]);

        self::assertEquals(
            [new ImageRendition('https://i/a-300.jpg', 300), new ImageRendition('https://i/a-1024.jpg', 1024)],
            $ladder,
        );
    }

    public function testAStoredRenditionReadsBackAsWritten(): void
    {
        $rendition = new ImageRendition('https://i/a.jpg', 640);

        self::assertEquals($rendition, ImageRendition::fromStored($rendition->jsonSerialize()));
    }

    public function testTheJsonListCarriesUrlAndWidth(): void
    {
        self::assertSame(
            [['url' => 'https://i/a.jpg', 'width' => 640]],
            ImageRendition::toJsonList([new ImageRendition('https://i/a.jpg', 640)]),
        );
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function incompleteStoredRenditions(): iterable
    {
        yield 'no url' => [['width' => 640]];
        yield 'no width' => [['url' => 'https://i/a.jpg']];
        yield 'a zero width' => [['url' => 'https://i/a.jpg', 'width' => 0]];
        yield 'a string width' => [['url' => 'https://i/a.jpg', 'width' => '640']];
    }

    /** @param array<string, mixed> $stored */
    #[DataProvider('incompleteStoredRenditions')]
    public function testAnIncompleteStoredRenditionIsRefused(array $stored): void
    {
        self::assertFalse(ImageRendition::isComplete($stored));

        $this->expectException(IncompleteStoredMediaException::class);

        ImageRendition::fromStored($stored);
    }
}
```

`backend/tests/Entity/EntryImageTest.php` — add `use App\Entity\ImageRendition;` and append:

```php
    public function testStoredRenditionsReadBack(): void
    {
        $image = new EntryImage();
        $image->storePending('https://i/a-1024.jpg', 1024, 683);
        $renditions = [
            new ImageRendition('https://i/a-300.jpg', 300),
            new ImageRendition('https://i/a-1024.jpg', 1024),
        ];

        $image->storeRenditions($renditions);

        self::assertEquals($renditions, $image->getRenditions());
    }

    public function testStoringNoRenditionsReadsBackAsNone(): void
    {
        $image = new EntryImage();
        $image->storePending('https://i/a.jpg', null, null);

        $image->storeRenditions([]);

        self::assertSame([], $image->getRenditions());
    }

    public function testStoringAnotherImageClearsTheRenditions(): void
    {
        $image = new EntryImage();
        $image->storePending('https://i/a.jpg', 1024, 683);
        $image->storeRenditions([new ImageRendition('https://i/a-300.jpg', 300)]);

        $image->storePending('https://i/b.jpg', 800, 600);

        self::assertSame([], $image->getRenditions());
    }

    public function testDroppingClearsTheRenditions(): void
    {
        $image = new EntryImage();
        $image->storePending('https://i/a.jpg', 1024, 683);
        $image->storeRenditions([new ImageRendition('https://i/a-300.jpg', 300)]);

        $image->drop(new \DateTimeImmutable('2026-10-01 12:00:00'));

        self::assertSame([], $image->getRenditions());
    }

    public function testMeasuringTheImageKeepsItsRenditions(): void
    {
        $image = new EntryImage();
        $image->storePending('https://i/a.jpg', 1024, 683);
        $renditions = [new ImageRendition('https://i/a-300.jpg', 300)];
        $image->storeRenditions($renditions);

        $image->recordMeasurement(1024, 683, new \DateTimeImmutable('2026-10-01 12:00:00'));

        self::assertEquals($renditions, $image->getRenditions());
    }
```

- [ ] **Step 2: Run them to see them fail**

Run: `php bin/phpunit tests/Entity/ImageRenditionTest.php tests/Entity/EntryImageTest.php`
Expected: ERROR — `Call to undefined method App\Entity\ImageRendition::ladder()` / `EntryImage::storeRenditions()`.

- [ ] **Step 3: Implement**

`backend/src/Entity/ImageRendition.php` — full new version:

```php
<?php

declare(strict_types=1);

namespace App\Entity;

use App\Entity\Exception\IncompleteStoredMediaException;

/** One declared-width rendition of an entry's lead picture, as a `srcset` candidate names it. */
final readonly class ImageRendition implements \JsonSerializable
{
    public function __construct(
        public string $url,
        public int $width,
    ) {
    }

    /**
     * @param array<string, mixed> $stored
     *
     * @phpstan-assert-if-true array{url: string, width: positive-int, ...<mixed>} $stored
     */
    public static function isComplete(array $stored): bool
    {
        $width = $stored['width'] ?? null;

        return \is_string($stored['url'] ?? null) && \is_int($width) && $width > 0;
    }

    /** @param array<string, mixed> $stored */
    public static function fromStored(array $stored): self
    {
        if (!self::isComplete($stored)) {
            throw new IncompleteStoredMediaException('A stored image rendition needs a url and a positive width.');
        }

        return new self($stored['url'], $stored['width']);
    }

    /**
     * One rendition per URL, the first declared winning, narrowest first.
     *
     * @param list<self> $renditions
     *
     * @return list<self>
     */
    public static function ladder(array $renditions): array
    {
        $byUrl = [];
        foreach ($renditions as $rendition) {
            $byUrl[$rendition->url] ??= $rendition;
        }
        $ladder = array_values($byUrl);
        usort($ladder, static fn (self $left, self $right): int => $left->width <=> $right->width);

        return $ladder;
    }

    /**
     * @param list<self> $renditions
     *
     * @return list<array{url: string, width: int}>
     */
    public static function toJsonList(array $renditions): array
    {
        return array_map(static fn (self $rendition): array => $rendition->jsonSerialize(), $renditions);
    }

    /** @return array{url: string, width: int} */
    public function jsonSerialize(): array
    {
        return ['url' => $this->url, 'width' => $this->width];
    }
}
```

`backend/src/Entity/EntryImage.php` — class docblock, new column, and the changed/new methods (all other members unchanged):

```php
/**
 * An entry's lead image: the URL, the dimensions, its verification state and the renditions of the same picture.
 * Embedded rather than scalar columns — these values are stamped and read together and mean nothing apart.
 */
#[ORM\Embeddable]
final class EntryImage
{
```

Add after the `$verifyAttempts` property:

```php
    /** @var list<array<string, mixed>>|null */
    #[ORM\Column(name: 'image_renditions', type: Types::JSON, nullable: true)]
    private ?array $renditions = null;
```

```php
    public function storePending(?string $url, ?int $width, ?int $height): void
    {
        $this->url = $url;
        $this->width = $width;
        $this->height = $height;
        $this->checkedAt = null;
        $this->verifyAttempts = $url === null ? null : 0;
        $this->renditions = null;
    }

    /**
     * The renditions of the stored picture; storePending() and drop() clear them, so they never outlive their URL.
     *
     * @param list<ImageRendition> $renditions
     */
    public function storeRenditions(array $renditions): void
    {
        $this->renditions = $renditions === [] ? null : ImageRendition::toJsonList($renditions);
    }
```

```php
    /** The checkedAt stays behind as a tombstone, so a refresh never restores a rejected image. */
    public function drop(\DateTimeImmutable $checkedAt): void
    {
        $this->url = null;
        $this->width = null;
        $this->height = null;
        $this->checkedAt = $checkedAt;
        $this->verifyAttempts = null;
        $this->renditions = null;
    }
```

Add after `getHeight()`:

```php
    /** @return list<ImageRendition> */
    public function getRenditions(): array
    {
        $complete = array_filter($this->renditions ?? [], ImageRendition::isComplete(...));

        return array_values(array_map(ImageRendition::fromStored(...), $complete));
    }
```

`backend/migrations/Version20261001150000.php` (if `develop` holds a migration with a later timestamp when you run this, rename the class and file to a later one):

```php
<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261001150000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add entry.image_renditions, the declared-width renditions of the lead image (#1330)';
    }

    public function up(Schema $schema): void
    {
        $this->skipIf(
            $schema->getTable('entry')->hasColumn('image_renditions'),
            'entry.image_renditions already exists.',
        );

        $platform = $this->connection->getDatabasePlatform();

        if ($platform instanceof AbstractMySQLPlatform) {
            $this->addSql('ALTER TABLE entry ADD image_renditions JSON DEFAULT NULL');

            return;
        }

        if ($platform instanceof SQLitePlatform) {
            $this->addSql('ALTER TABLE entry ADD COLUMN image_renditions CLOB DEFAULT NULL');

            return;
        }

        throw new \RuntimeException('Unsupported database platform for the entry image renditions migration.');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE entry DROP COLUMN image_renditions');
    }

    public function isTransactional(): bool
    {
        return false;
    }
}
```

- [ ] **Step 4: Run the tests**

Run: `php bin/phpunit tests/Entity tests/Service/Image tests/Repository`
Expected: PASS.

- [ ] **Step 5: Verify the migration on both platforms from empty**

Tests build the schema from ORM metadata and never run a migration, so this is the only proof. Never point these at the dev database `feedreader`.

SQLite (native, from `backend/`):
```bash
rm -f var/migration-check.db
DATABASE_URL='sqlite:///%kernel.project_dir%/var/migration-check.db' php bin/console doctrine:migrations:migrate --no-interaction
DATABASE_URL='sqlite:///%kernel.project_dir%/var/migration-check.db' php bin/console doctrine:schema:validate
rm -f var/migration-check.db
```
Expected: migrations run; `[OK] The database schema is in sync with the mapping files.`

MySQL (Docker; `feedreader_test_migrations` falls under the `feedreader\_test%` grant from `docker/mysql/init.sql`):
```bash
docker compose exec -T -e DATABASE_URL='mysql://feedreader:feedreader@mysql:3306/feedreader_test_migrations?serverVersion=8.4&charset=utf8mb4' php \
  sh -c 'php bin/console doctrine:database:drop --force --if-exists && php bin/console doctrine:database:create \
  && php bin/console doctrine:migrations:migrate --no-interaction && php bin/console doctrine:schema:validate \
  && php bin/console doctrine:database:drop --force'
```
Expected: the same `[OK] … in sync` line.

Then apply it to the live dev database: `docker compose exec php bin/console doctrine:migrations:migrate --no-interaction`.

- [ ] **Step 6: Commit**

```bash
git add src/Entity/ImageRendition.php src/Entity/EntryImage.php migrations/Version20261001150000.php \
  tests/Entity/ImageRenditionTest.php tests/Entity/EntryImageTest.php
git commit -m "feat(#1330): store the image rendition ladder beside the entry image"
```

---

### Task 5: Ingest writes the https ladder

**Files:**
- Modify: `backend/src/Service/Ingest/EntryImageWriter.php`
- Test: `backend/tests/Service/Ingest/EntryImageWriterTest.php`, `backend/tests/Service/Ingest/EntryIngestorTest.php`

**Interfaces:**
- Consumes: `DeclaredImageModel::$renditions` (Task 2), `ImageRendition::ladder()`, `EntryImage::storeRenditions()` (Task 4), `HttpsImageUrl::orNullUpgrading()`.
- Produces: `EntryImageWriter::write(Entry, DeclaredImageModel): bool` (signature unchanged) now also stores the ladder: each URL through `HttpsImageUrl::orNullUpgrading` (http upgraded, `//` upgraded, relative/`data:`/overlong dropped), one per URL, narrowest first, and none at all when fewer than two remain.

- [ ] **Step 1: Write the failing tests**

`backend/tests/Service/Ingest/EntryImageWriterTest.php` — add `use App\Entity\ImageRendition;` and append:

```php
    public function testStoresTheLadderNarrowestFirstOncePerUrl(): void
    {
        $entry = $this->entry();

        $this->writer()->write($entry, new DeclaredImageModel('https://img.example.com/a-1024.jpg', 696, 464, [
            new ImageRendition('https://img.example.com/a-1024.jpg', 1024),
            new ImageRendition('https://img.example.com/a-300.jpg', 300),
            new ImageRendition('https://img.example.com/a-1024.jpg', 696),
        ]));

        self::assertEquals(
            [
                new ImageRendition('https://img.example.com/a-300.jpg', 300),
                new ImageRendition('https://img.example.com/a-1024.jpg', 1024),
            ],
            $entry->getImage()->getRenditions(),
        );
    }

    public function testUpgradesHttpRenditionsAndDropsUnstorableOnes(): void
    {
        $entry = $this->entry();

        $this->writer()->write($entry, new DeclaredImageModel('https://img.example.com/a.jpg', null, null, [
            new ImageRendition('http://img.example.com/a-300.jpg', 300),
            new ImageRendition('/relative-600.jpg', 600),
            new ImageRendition('//img.example.com/a-900.jpg', 900),
        ]));

        self::assertEquals(
            [
                new ImageRendition('https://img.example.com/a-300.jpg', 300),
                new ImageRendition('https://img.example.com/a-900.jpg', 900),
            ],
            $entry->getImage()->getRenditions(),
        );
    }

    public function testASingleRenditionIsNoLadder(): void
    {
        $entry = $this->entry();

        $this->writer()->write($entry, new DeclaredImageModel('https://img.example.com/a.jpg', 800, 600, [
            new ImageRendition('https://img.example.com/a.jpg', 800),
            new ImageRendition('http://img.example.com/a.jpg', 800),
        ]));

        self::assertSame([], $entry->getImage()->getRenditions());
    }

    public function testAnotherImageReplacesTheStoredLadder(): void
    {
        $entry = $this->entry();
        $this->writer()->write($entry, new DeclaredImageModel('https://img.example.com/a.jpg', null, null, [
            new ImageRendition('https://img.example.com/a-300.jpg', 300),
            new ImageRendition('https://img.example.com/a-900.jpg', 900),
        ]));

        $this->writer()->write($entry, new DeclaredImageModel('https://img.example.com/b.jpg', 800, 600));

        self::assertSame([], $entry->getImage()->getRenditions());
    }
```

`backend/tests/Service/Ingest/EntryIngestorTest.php` — add `use App\Entity\ImageRendition;` and append:

```php
    public function testDeclaredRenditionsPersistBesideTheImage(): void
    {
        $feed = $this->feed();
        $this->ingestor->ingest($feed, new ParsedFeedModel('T', null, null, null, [
            $this->parsedEntryWithImage('with-ladder', new DeclaredImageModel('https://i/x-1024.jpg', 1024, 683, [
                new ImageRendition('https://i/x-1024.jpg', 1024),
                new ImageRendition('http://i/x-300.jpg', 300),
            ])),
        ]), self::context());
        $this->entityManager->flush();
        $this->entityManager->clear();

        $entry = $this->entityManager->getRepository(Entry::class)->findOneBy(['guid' => 'with-ladder']);
        self::assertNotNull($entry);
        self::assertEquals(
            [new ImageRendition('https://i/x-300.jpg', 300), new ImageRendition('https://i/x-1024.jpg', 1024)],
            $entry->getImage()->getRenditions(),
        );
    }
```

- [ ] **Step 2: Run them to see them fail**

Run: `php bin/phpunit tests/Service/Ingest/EntryImageWriterTest.php tests/Service/Ingest/EntryIngestorTest.php`
Expected: FAIL — `getRenditions()` returns `[]` where a ladder is expected.

- [ ] **Step 3: Implement**

`backend/src/Service/Ingest/EntryImageWriter.php` — full new version:

```php
<?php

declare(strict_types=1);

namespace App\Service\Ingest;

use App\Entity\Entry;
use App\Entity\ImageRendition;
use App\Service\Image\Model\DeclaredImageModel;
use App\Service\Url\Support\HttpsImageUrl;

/** Stores a feed-declared image on an entry, pending the background verify: declared dimensions don't prove it loads. */
final readonly class EntryImageWriter
{
    private const int FEWEST_RENDITIONS_TO_CHOOSE_FROM = 2;

    /** Whether the image had a URL worth storing; one without leaves the entry as it was. */
    public function write(Entry $entry, DeclaredImageModel $image): bool
    {
        $url = HttpsImageUrl::orNullUpgrading($image->url);
        if ($url === null) {
            return false;
        }
        $entry->getImage()->storePending($url, $image->width, $image->height);
        $entry->getImage()->storeRenditions(self::storableRenditions($image->renditions));

        return true;
    }

    public function writeOrMarkNone(Entry $entry, ?DeclaredImageModel $image): void
    {
        if ($image === null || !$this->write($entry, $image)) {
            $entry->getImage()->storePending(null, null, null);
        }
    }

    /**
     * The renditions under the image URL's own https rule; a single one leaves the browser no choice, so it is none.
     *
     * @param list<ImageRendition> $declared
     *
     * @return list<ImageRendition>
     */
    private static function storableRenditions(array $declared): array
    {
        $secure = [];
        foreach ($declared as $rendition) {
            $url = HttpsImageUrl::orNullUpgrading($rendition->url);
            if ($url !== null) {
                $secure[] = new ImageRendition($url, $rendition->width);
            }
        }
        $ladder = ImageRendition::ladder($secure);

        return \count($ladder) < self::FEWEST_RENDITIONS_TO_CHOOSE_FROM ? [] : $ladder;
    }
}
```

- [ ] **Step 4: Run the tests**

Run: `php bin/phpunit tests/Service/Ingest tests/Service/FillMissingImagesTest.php tests/Service/Image`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add src/Service/Ingest/EntryImageWriter.php tests/Service/Ingest/EntryImageWriterTest.php \
  tests/Service/Ingest/EntryIngestorTest.php
git commit -m "feat(#1330): ingest stores the https rendition ladder of the lead image"
```

---

### Task 6: Serve `imageRenditions` on every entry

Additive: `imageRenditions` sits beside `imageUrl/imageWidth/imageHeight`, always present, `[]` when none — a native client that ignores it keeps working.

**Files:**
- Modify: `backend/src/Http/EntryJson.php`
- Test: `backend/tests/Http/EntryJsonTest.php`, `backend/tests/Controller/Api/EntryControllerTest.php`

**Interfaces:**
- Consumes: `EntryImage::getRenditions()`, `ImageRendition::toJsonList()` (Task 4).
- Produces: JSON field `imageRenditions: list<{url: string, width: int}>` on `EntryJson::listRow()` and `EntryJson::detail()` (and so on every duplicate row).

- [ ] **Step 1: Write the failing tests**

`backend/tests/Http/EntryJsonTest.php` — add `use App\Entity\ImageRendition;`, append to `testEmitsEmptyMediaListsWhenTheEntryHasNone` the line

```php
        self::assertSame([], $json['imageRenditions']);
```

and add:

```php
    public function testEmitsTheStoredImageRenditions(): void
    {
        $entry = new Entry(
            new Feed('https://example.com/feed'),
            'guid',
            'https://example.com/a',
            'Article',
            new \DateTimeImmutable('2026-09-07T00:00:00Z'),
            new \DateTimeImmutable('2026-09-07T00:00:00Z'),
        );
        $entry->getImage()->storePending('https://i/lead-1024.jpg', 1024, 683);
        $entry->getImage()->storeRenditions([
            new ImageRendition('https://i/lead-300.jpg', 300),
            new ImageRendition('https://i/lead-1024.jpg', 1024),
        ]);

        $json = EntryJson::listRow($this->row($entry));

        self::assertSame(
            [
                ['url' => 'https://i/lead-300.jpg', 'width' => 300],
                ['url' => 'https://i/lead-1024.jpg', 'width' => 1024],
            ],
            $json['imageRenditions'],
        );
    }
```

`backend/tests/Controller/Api/EntryControllerTest.php` — add `use App\Entity\ImageRendition;`; in `testExposesThePersistedImageOnEachEntry` add after `$withImage->getImage()->storePending('https://i.example.com/big.jpg', 948, 474);`:

```php
        $withImage->getImage()->storeRenditions([
            new ImageRendition('https://i.example.com/big-474.jpg', 474),
            new ImageRendition('https://i.example.com/big.jpg', 948),
        ]);
```

and after `self::assertSame(474, $first['imageHeight']);`:

```php
        self::assertSame(
            [
                ['url' => 'https://i.example.com/big-474.jpg', 'width' => 474],
                ['url' => 'https://i.example.com/big.jpg', 'width' => 948],
            ],
            $first['imageRenditions'],
        );
```

and after `self::assertNull($second['imageHeight']);`:

```php
        self::assertSame([], $second['imageRenditions']);
```

- [ ] **Step 2: Run them to see them fail**

Run: `php bin/phpunit tests/Http/EntryJsonTest.php tests/Controller/Api/EntryControllerTest.php`
Expected: FAIL — undefined array key `imageRenditions`.

- [ ] **Step 3: Implement**

`backend/src/Http/EntryJson.php` — add `use App\Entity\ImageRendition;`; in all three `@return array{…}` shapes replace the line

```
     *   imageUrl: string|null, imageWidth: int|null, imageHeight: int|null,
```

with

```
     *   imageUrl: string|null, imageWidth: int|null, imageHeight: int|null,
     *   imageRenditions: list<array{url: string, width: int}>,
```

and in `commonFields()`:

```php
            'imageUrl' => $entry->getImageUrl(),
            'imageWidth' => $entry->getImageWidth(),
            'imageHeight' => $entry->getImageHeight(),
            'imageRenditions' => ImageRendition::toJsonList($entry->getImage()->getRenditions()),
            'media' => EntryMedia::toJsonList($entry->getMedia()),
```

- [ ] **Step 4: Run the tests**

Run: `php bin/phpunit tests/Http tests/Controller/Api/EntryControllerTest.php`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add src/Http/EntryJson.php tests/Http/EntryJsonTest.php tests/Controller/Api/EntryControllerTest.php
git commit -m "feat(#1330): serve the image rendition ladder on every entry"
```

---

### Task 7: Carry the ladder through backup and restore

The backup (schema v3, gz-NDJSON parts) writes `imageUrl`, `imageWidth`, `imageHeight` on each `entry` line, so `imageRenditions` joins them. It is additive within v3: `LineField::objectListOrEmpty` reads a missing key as `[]`, and per `docs/backup.md` an additive field changes neither golden fixture. `BackupSchemaCoverageTest` fails until the field is declared — that is the failing test of this task. Its comment on `FILE_SCAFFOLDING` asks that a third nested value-object list get its own `NESTED_VALUE_OBJECTS` map; this is the third.

**Files:**
- Modify: `backend/src/Service/Backup/BackupLines.php`
- Modify: `backend/src/Service/Backup/Dto/EntryLine.php`
- Modify: `backend/src/Repository/EntryBatchInserter.php`
- Modify: `backend/tests/Support/BackupFieldDeclarations.php`, `backend/tests/Support/FullyPopulatedAccount.php`, `backend/tests/Service/Backup/Support/BackupSchemaCoverageTest.php`
- Modify: `docs/backup.md`
- Test: `backend/tests/Service/Backup/Dto/EntryLineTest.php`, `backend/tests/Service/Backup/EntryMediaBackupRoundTripTest.php`

**Interfaces:**
- Consumes: `EntryImage::getRenditions()`, `ImageRendition::toJsonList()` (Task 4).
- Produces: entry-line key `imageRenditions: list<{url, width}>`; `EntryLine::$imageRenditions` (`list<array<string, mixed>>`, default `[]`, last constructor parameter); `entry.image_renditions` written by `EntryBatchInserter`.

- [ ] **Step 1: Declare the field so the coverage test fails**

`backend/tests/Support/BackupFieldDeclarations.php`, in the `Entry::class` block:

```php
            'image.url' => 'imageUrl', 'image.width' => 'imageWidth',
            'image.height' => 'imageHeight', 'image.renditions' => 'imageRenditions',
```

`backend/tests/Support/FullyPopulatedAccount.php` — add `use App\Entity\ImageRendition;` and after `$entry->getImage()->storePending('https://populated.example/lead.jpg', 1200, 630);`:

```php
        $entry->getImage()->storeRenditions([
            new ImageRendition('https://populated.example/lead-600.jpg', 600),
            new ImageRendition('https://populated.example/lead.jpg', 1200),
        ]);
```

`backend/tests/Service/Backup/Support/BackupSchemaCoverageTest.php` — `FILE_SCAFFOLDING` loses its `KIND_ENTRY` block and a map takes its place:

```php
    private const array FILE_SCAFFOLDING = [
        BackupSchema::KIND_HEADER => [
            'schemaVersion', 'createdAt', 'sourceUrl', 'sourceEmail', 'backupId', 'part', 'parts',
            'totals', 'totals.entries', 'totals.entryStates',
        ],
        BackupSchema::KIND_FOOTER => [
            'counts', 'counts.tag', 'counts.savedSearch', 'counts.feed', 'counts.subscription',
            'counts.entry', 'counts.entryState',
        ],
    ];

    /** Lists of value objects, not entities, per kind: their subkeys are claimed here, dotted under the list's key. */
    private const array NESTED_VALUE_OBJECTS = [
        BackupSchema::KIND_ENTRY => [
            'media' => ['url', 'kind', 'width', 'height', 'previewImageUrl'],
            'attachments' => ['url', 'mimeType', 'durationInSeconds', 'sizeInBytes', 'title'],
            'imageRenditions' => ['url', 'width'],
        ],
    ];
```

`claimedKeysOf()` becomes, with a new helper below it:

```php
    /** @return list<string> the keys one kind of line is allowed to carry */
    private function claimedKeysOf(string $kind): array
    {
        $keys = array_merge(
            self::EVERY_LINE,
            self::FILE_SCAFFOLDING[$kind] ?? [],
            $this->nestedValueObjectKeysOf($kind),
        );
        foreach (self::BACKED_UP as $entityClass => $fields) {
            if (self::KIND_OF[$entityClass] !== $kind) {
                continue;
            }
            foreach ($fields as $declared) {
                $keys = array_merge($keys, $this->exportedKeysFor($declared));
            }
        }

        return $keys;
    }

    /** @return list<string> */
    private function nestedValueObjectKeysOf(string $kind): array
    {
        $keys = [];
        foreach (self::NESTED_VALUE_OBJECTS[$kind] ?? [] as $listKey => $subkeys) {
            foreach ($subkeys as $subkey) {
                $keys[] = $listKey . '.' . $subkey;
            }
        }

        return $keys;
    }
```

`backend/tests/Service/Backup/Dto/EntryLineTest.php` — append:

```php
    public function testReadsTheImageRenditions(): void
    {
        $line = EntryLine::fromLine($this->baseLine() + [
            'imageRenditions' => [['url' => 'https://i/lead-600.jpg', 'width' => 600]],
        ]);

        self::assertSame([['url' => 'https://i/lead-600.jpg', 'width' => 600]], $line->imageRenditions);
    }

    public function testAnOlderFileWithoutImageRenditionsReadsAsNone(): void
    {
        self::assertSame([], EntryLine::fromLine($this->baseLine())->imageRenditions);
    }
```

`backend/tests/Service/Backup/EntryMediaBackupRoundTripTest.php` — add `use App\Entity\ImageRendition;` and append:

```php
    public function testImageRenditionsSurviveExportAndRestore(): void
    {
        $user = $this->makeUser('renditions-backup@example.com');
        $feed = new Feed('https://renditions.example/feed.xml');
        $this->entityManager->persist($feed);
        $entry = new Entry(
            $feed,
            'guid-renditions',
            'https://renditions.example/post',
            'Post',
            new \DateTimeImmutable('2026-10-01T00:00:00Z'),
            new \DateTimeImmutable('2026-10-01T00:00:00Z'),
        );
        $entry->getImage()->storePending('https://i/lead.jpg', 1200, 800);
        $entry->getImage()->storeRenditions([
            new ImageRendition('https://i/lead-600.jpg', 600),
            new ImageRendition('https://i/lead.jpg', 1200),
        ]);
        $this->entityManager->persist($entry);
        $this->entityManager->persist(new Subscription($user, $feed, new \DateTimeImmutable('2026-07-01T00:00:00Z')));
        $this->entityManager->flush();

        $entryLine = $this->exportedEntryLine($user);
        self::assertSame(
            [['url' => 'https://i/lead-600.jpg', 'width' => 600], ['url' => 'https://i/lead.jpg', 'width' => 1200]],
            $entryLine['imageRenditions'],
        );

        $target = new Feed('https://restore-renditions.example/feed.xml');
        $this->entityManager->persist($target);
        $this->entityManager->flush();
        $targetId = $target->requireId();

        (new EntryBatchInserter($this->entityManager->getConnection(), new UrlNormalizer()))
            ->insert($targetId, [EntryLine::fromLine($entryLine)]);

        $this->entityManager->clear();
        $restored = $this->entityManager->getRepository(Entry::class)->findOneBy(['feed' => $targetId]);
        self::assertInstanceOf(Entry::class, $restored);
        self::assertEquals(
            [new ImageRendition('https://i/lead-600.jpg', 600), new ImageRendition('https://i/lead.jpg', 1200)],
            $restored->getImage()->getRenditions(),
        );
    }
```

- [ ] **Step 2: Run them to see them fail**

Run: `php bin/phpunit tests/Service/Backup tests/Repository/EntryBatchInserterTest.php`
Expected: FAIL — `BackupSchemaCoverageTest::testEveryBackedUpFieldReachesTheExportersOutput` ("never writes that key" for `imageRenditions`), `EntryLineTest` (undefined property `imageRenditions`), the round-trip test (undefined key).

- [ ] **Step 3: Implement**

`backend/src/Service/Backup/BackupLines.php` — add `use App\Entity\ImageRendition;` and in `entryLine()`:

```php
            'imageUrl' => $entry->getImageUrl(),
            'imageWidth' => $entry->getImageWidth(),
            'imageHeight' => $entry->getImageHeight(),
            'imageRenditions' => ImageRendition::toJsonList($entry->getImage()->getRenditions()),
            'media' => EntryMedia::toJsonList($entry->getMedia()),
```

`backend/src/Service/Backup/Dto/EntryLine.php` — last constructor parameter and `fromLine()` argument:

```php
        public ?string $discussionUrl = null,
        public ?string $commentsFeedUrl = null,
        public ?string $commentsLoad = null,
        /** @var list<array<string, mixed>> */
        public array $imageRenditions = [],
    ) {
    }
```

```php
            discussionUrl: LineField::stringOrNull($line, 'discussionUrl'),
            commentsFeedUrl: LineField::stringOrNull($line, 'commentsFeedUrl'),
            commentsLoad: LineField::stringOrNull($line, 'commentsLoad'),
            imageRenditions: LineField::objectListOrEmpty($line, 'imageRenditions'),
        );
```

`backend/src/Repository/EntryBatchInserter.php`:

```php
    private const array COLUMNS = [
        'feed_id', 'guid', 'guid_hash', 'url', 'url_hash', 'title', 'author',
        'summary', 'content_html', 'image_url', 'image_width', 'image_height', 'image_renditions',
        'media', 'attachments',
        'published_at', 'created_at', 'effective_date',
        'discussion_url', 'comments_feed_url', 'comments_load',
    ];
```

```php
    /** @return list<int|string|null> */
    private function row(int $feedId, EntryLine $line): array
    {
        return [
            $feedId, $line->guid, $line->guidHash, $line->url,
            $this->urlNormalizer->hash($line->url), $line->title,
            $line->author, $line->summary, $line->contentHtml, $line->imageUrl,
            $line->imageWidth, $line->imageHeight, self::encodeList($line->imageRenditions),
            self::encodeList($line->media), self::encodeList($line->attachments),
            self::storageDate($line->publishedAt),
            self::storageDate($line->createdAt),
            self::storageDate($line->effectiveDate),
            $line->discussionUrl, $line->commentsFeedUrl,
            CommentsLoad::tryFrom((string) $line->commentsLoad)?->value,
        ];
    }

    /**
     * Re-encodes a list to the JSON the ORM's json type reads back — null for an empty list, matching the "none"
     * case a fresh ingest persists.
     *
     * @param list<array<string, mixed>> $list
     */
    private static function encodeList(array $list): ?string
```

(`encodeList`'s body is unchanged.)

`docs/backup.md`, section 5 `entry` row — the image clause becomes:

```
the image (`imageUrl`, `imageWidth`, `imageHeight`, and its renditions at other widths, `imageRenditions`),
```

- [ ] **Step 4: Run the tests**

Run: `php bin/phpunit tests/Service/Backup tests/Repository tests/Controller/Api`
Expected: PASS (`GoldenBackupRestoreTest` included — its fixtures stay untouched).

- [ ] **Step 5: Commit**

```bash
git add src/Service/Backup/BackupLines.php src/Service/Backup/Dto/EntryLine.php src/Repository/EntryBatchInserter.php \
  tests/Support/BackupFieldDeclarations.php tests/Support/FullyPopulatedAccount.php \
  tests/Service/Backup/Support/BackupSchemaCoverageTest.php tests/Service/Backup/Dto/EntryLineTest.php \
  tests/Service/Backup/EntryMediaBackupRoundTripTest.php ../docs/backup.md
git commit -m "feat(#1330): back up and restore the image rendition ladder"
```

---

### Task 8: Frontend model, srcset helper and the renditions directive

**Files:**
- Modify: `frontend/src/app/reader/models.ts`
- Modify: `frontend/src/app/reader/list/preview-image.ts`
- Create: `frontend/src/app/reader/list/renditions.directive.ts`
- Test: `frontend/src/app/reader/list/preview-image.spec.ts`, `frontend/src/app/reader/list/renditions.directive.spec.ts` (new)
- Modify (fixtures): the 21 specs that build an `EntryDto` literal plus `frontend/e2e/support/reader.ts` (list in Step 5)

**Interfaces:**
- Consumes: API field `imageRenditions` (Task 6).
- Produces:
  - `export interface ImageRenditionDto { url: string; width: number; }`; `EntryDto.imageRenditions: ImageRenditionDto[]`.
  - `renditionSrcset(renditions: readonly ImageRenditionDto[] | undefined): string | null` in `list/preview-image.ts`.
  - `RenditionsDirective`, selector `img[appRenditions]`, inputs `appRenditions` (`readonly ImageRenditionDto[] | undefined`, required) and `renditionSizes` (`string`, required); host binds `attr.srcset` and `attr.sizes`, both null when there are no renditions.

- [ ] **Step 1: Write the failing tests**

`frontend/src/app/reader/list/preview-image.spec.ts` — change the import to `import { entryImage, entrySnippet, renditionSrcset } from './preview-image';` and append:

```ts
describe('renditionSrcset', () => {
  it('lists the renditions narrowest first as width candidates', () => {
    expect(
      renditionSrcset([
        { url: 'https://i/a-848.jpg', width: 848 },
        { url: 'https://i/a-424.jpg', width: 424 },
      ]),
    ).toBe('https://i/a-424.jpg 424w, https://i/a-848.jpg 848w');
  });

  it('keeps the first rendition of a repeated width', () => {
    expect(
      renditionSrcset([
        { url: 'https://i/a-424.webp', width: 424 },
        { url: 'https://i/a-424.jpg', width: 424 },
      ]),
    ).toBe('https://i/a-424.webp 424w');
  });

  it('keeps the commas inside a transform url', () => {
    const url = 'https://substackcdn.com/image/fetch/$s_!v2GA!,w_424,c_limit,f_auto/https%3A%2F%2Fs3%2Fa.jpeg';
    expect(renditionSrcset([{ url, width: 424 }])).toBe(`${url} 424w`);
  });

  it('is null without renditions', () => {
    expect(renditionSrcset([])).toBeNull();
    expect(renditionSrcset(undefined)).toBeNull();
  });
});
```

`frontend/src/app/reader/list/renditions.directive.spec.ts`:

```ts
import { Component, signal } from '@angular/core';
import { TestBed } from '@angular/core/testing';
import { ImageRenditionDto } from '../models';
import { RenditionsDirective } from './renditions.directive';

@Component({
  imports: [RenditionsDirective],
  template: `<img
    alt=""
    src="https://i/a.jpg"
    [appRenditions]="renditions()"
    [renditionSizes]="'88px'"
  />`,
})
class HostComponent {
  readonly renditions = signal<ImageRenditionDto[] | undefined>([]);
}

describe('RenditionsDirective', () => {
  function mount(renditions: ImageRenditionDto[] | undefined): HTMLImageElement {
    TestBed.configureTestingModule({ imports: [HostComponent] });
    const fixture = TestBed.createComponent(HostComponent);
    fixture.componentInstance.renditions.set(renditions);
    fixture.detectChanges();
    return fixture.nativeElement.querySelector('img') as HTMLImageElement;
  }

  it('offers the renditions and the rendered width to the browser', () => {
    const img = mount([
      { url: 'https://i/a-848.jpg', width: 848 },
      { url: 'https://i/a-424.jpg', width: 424 },
    ]);
    expect(img.getAttribute('srcset')).toBe('https://i/a-424.jpg 424w, https://i/a-848.jpg 848w');
    expect(img.getAttribute('sizes')).toBe('88px');
    expect(img.hasAttribute('renditionsizes')).toBe(false);
  });

  it('leaves the plain src alone when there are no renditions', () => {
    const img = mount([]);
    expect(img.hasAttribute('srcset')).toBe(false);
    expect(img.hasAttribute('sizes')).toBe(false);
  });

  it('leaves the plain src alone when the entry omits the field', () => {
    const img = mount(undefined);
    expect(img.hasAttribute('srcset')).toBe(false);
    expect(img.hasAttribute('sizes')).toBe(false);
  });
});
```

- [ ] **Step 2: Run them to see them fail**

Run: `docker compose exec -T frontend npm test -- src/app/reader/list/preview-image.spec.ts src/app/reader/list/renditions.directive.spec.ts`
Expected: FAIL — `renditionSrcset` is not exported; `./renditions.directive` cannot be resolved.

- [ ] **Step 3: Implement**

`frontend/src/app/reader/models.ts` — add after `EntryAttachmentDto`:

```ts
/** One declared-width rendition of an entry's lead picture (#1330). */
export interface ImageRenditionDto {
  url: string;
  width: number;
}
```

and in `EntryDto`, after `imageHeight`:

```ts
  /** The same picture at each width the feed declared, for `srcset` (#1330). Always
   *  sent by the API; empty when the feed declared no ladder. */
  imageRenditions: ImageRenditionDto[];
```

`frontend/src/app/reader/list/preview-image.ts` — change the import to `import { EntryDto, HeroImageDto, ImageRenditionDto } from '../models';` and append:

```ts
/** The renditions as a `srcset`, narrowest first and one candidate per width, or null
 *  when there are none. Stubbed e2e entries predate the field and omit it. */
export function renditionSrcset(
  renditions: readonly ImageRenditionDto[] | undefined,
): string | null {
  if (!renditions?.length) return null;
  const urlByWidth = new Map<number, string>();
  for (const rendition of renditions) {
    if (!urlByWidth.has(rendition.width)) urlByWidth.set(rendition.width, rendition.url);
  }
  return [...urlByWidth]
    .sort(([left], [right]) => left - right)
    .map(([width, url]) => `${url} ${width}w`)
    .join(', ');
}
```

`frontend/src/app/reader/list/renditions.directive.ts`:

```ts
import { Directive, computed, input } from '@angular/core';
import { ImageRenditionDto } from '../models';
import { renditionSrcset } from './preview-image';

/** Lets the browser load the smallest rendition that covers the image's rendered width;
 *  an image without renditions keeps its plain `src`. */
@Directive({
  selector: 'img[appRenditions]',
  host: { '[attr.srcset]': 'srcset()', '[attr.sizes]': 'sizes()' },
})
export class RenditionsDirective {
  readonly appRenditions = input.required<readonly ImageRenditionDto[] | undefined>();
  /** The image's rendered width as a `sizes` value, read off its block's CSS. Bind it
   *  (`[renditionSizes]="'88px'"`): a static attribute would also land in the DOM. */
  readonly renditionSizes = input.required<string>();

  readonly srcset = computed(() => renditionSrcset(this.appRenditions()));
  readonly sizes = computed(() => (this.srcset() === null ? null : this.renditionSizes()));
}
```

- [ ] **Step 4: Give every typed `EntryDto` literal the new field**

`imageRenditions` is now required, so until this step the container's ts-jest rejects every spec whose `EntryDto` literal lacks it (`preview-image.spec.ts` included). Every typed literal carries a `media: [],` line right above `attachments: [],`; insert `imageRenditions: [],` before it (`[ \t]*`, not `\s*`, so a blank line above is never swallowed):

```bash
cd frontend
files=(
  e2e/support/reader.ts
  src/app/reader/article/decorators/audio-attachment.spec.ts
  src/app/reader/article/reader-view/reader-view.component.spec.ts
  src/app/reader/entry/entry-actions/entry-actions.component.spec.ts
  src/app/reader/list/entry-list/entry-list.component.spec.ts
  src/app/reader/list/entry-meta/entry-meta.component.spec.ts
  src/app/reader/list/entry-row/entry-row.component.spec.ts
  src/app/reader/list/magazine/blocks/entry-compact/entry-compact.component.spec.ts
  src/app/reader/list/magazine/blocks/entry-hero/entry-hero.component.spec.ts
  src/app/reader/list/magazine/blocks/entry-kicker/entry-kicker.component.spec.ts
  src/app/reader/list/magazine/blocks/entry-quote/entry-quote.component.spec.ts
  src/app/reader/list/magazine/blocks/entry-split/entry-split.component.spec.ts
  src/app/reader/list/magazine/blocks/entry-thumb/entry-thumb.component.spec.ts
  src/app/reader/list/magazine/blocks/entry-wide/entry-wide.component.spec.ts
  src/app/reader/list/magazine/entry-duplicates.component.spec.ts
  src/app/reader/list/magazine/entry-kicker-line.component.spec.ts
  src/app/reader/list/magazine/magazine-planner.spec.ts
  src/app/reader/list/magazine/source-group.component.spec.ts
  src/app/reader/list/preview-image.spec.ts
  src/app/reader/list/recommendation-strip/recommendation-strip.component.spec.ts
  src/app/reader/shell/reader-shell.component.spec.ts
  src/app/reader/state/entries.store.spec.ts
)
perl -0pi -e 's/^([ \t]*)media: \[\],\n/$1imageRenditions: [],\n$1media: [],\n/mg' "${files[@]}"
grep -c 'imageRenditions: \[\],' "${files[@]}"
```

Expected: `1` per file, `2` for `magazine-planner.spec.ts`. The untyped entry objects in other `frontend/e2e/*.spec.ts` stubs stay as they are — `renditionSrcset` tolerates the missing field. There is no shared `EntryDto` test factory in `reader/testing/` (only `subscription.factory.ts`), so the literals are edited in place.

- [ ] **Step 5: Run the new tests to see them pass**

Run: `docker compose exec -T frontend npm test -- src/app/reader/list/preview-image.spec.ts src/app/reader/list/renditions.directive.spec.ts`
Expected: PASS.

- [ ] **Step 6: Typecheck and run the reader suite**

Run: `docker compose exec -T frontend npx tsc -p tsconfig.spec.json --noEmit` and `docker compose exec -T frontend npx tsc -p tsconfig.app.json --noEmit`
Expected: no errors (an `EntryDto` literal the grep missed shows up here as a missing `imageRenditions`; add the field there the same way).
Run: `docker compose exec -T frontend npm test -- src/app/reader`
Expected: PASS.

- [ ] **Step 7: Commit (from the repository root)**

```bash
cd ..
git add frontend/src/app/reader frontend/e2e/support/reader.ts
git commit -m "feat(#1330): carry image renditions into the frontend as a srcset directive"
```

---

### Task 9: List blocks hand the browser their renditions

`sizes` per block, each an upper bound of the rendered width (an over-estimate costs a slightly larger file; an under-estimate would pick a blurry one). The magazine column is `width: 100%; max-width: var(--magazine-measure)` (680px, `theme/tokens.scss`) inside `.rows.magazine`'s padding: `--space-3` (12px) a side boxed, `--space-5` (24px) a side airy (`entry-list.component.scss`). Boxed cards add a 1px border and `--card-pad` 12px; airy cards have neither.

| Block | `sizes` | Derived from |
|---|---|---|
| hero, wide | `(max-width: 728px) calc(100vw - 24px), 680px` | `.img { width: 100% }` of the card (`entry-hero.component.scss`, `entry-wide.component.scss`); card ≤ `min(680px, 100vw − 24px)`; 728px = 680 + 2×24 is where even the airy column reaches the measure |
| split | `(max-width: 728px) calc(38vw - 18px), 259px` | `.img { width: 38% }` of the card's content box (`entry-split.component.scss`): airy `0.38 × (100vw − 48px)` = `38vw − 18.24px`, boxed `0.38 × (100vw − 50px)`; at the measure `0.38 × 680` = 258.4px |
| thumb | `88px` | `.img { width: 88px }` (`entry-thumb.component.scss`) |
| entry row | `88px` | `.thumb { width: 88px }` (`entry-row.component.scss`) |

Checked and unaffected: list blocks no longer use `appProxiedImage` (#1324), so `ImageProxyService.swapIn` (which strips `srcset`/`sizes` on recovery) only touches the article view, which this plan leaves alone. The hero's `onLoad` "tiny image" gate reads `naturalWidth`, which for a `w`-descriptor `srcset` is the density-corrected width ≈ the `sizes` width (≥ 296px at a 320px viewport), so a real picture never trips its `< 200` threshold.

**Files:**
- Modify: `frontend/src/app/reader/list/entry-row/entry-row.component.{ts,html,spec.ts}`
- Modify: `frontend/src/app/reader/list/magazine/blocks/entry-hero/entry-hero.component.{ts,html,spec.ts}`
- Modify: `frontend/src/app/reader/list/magazine/blocks/entry-wide/entry-wide.component.{ts,html,spec.ts}`
- Modify: `frontend/src/app/reader/list/magazine/blocks/entry-split/entry-split.component.{ts,html,spec.ts}`
- Modify: `frontend/src/app/reader/list/magazine/blocks/entry-thumb/entry-thumb.component.{ts,html,spec.ts}`
- Modify: `docs/design-language.md`

**Interfaces:**
- Consumes: `RenditionsDirective` (`img[appRenditions]`, `[appRenditions]`, `[renditionSizes]`), `EntryDto.imageRenditions`, `ImageRenditionDto` (Task 8).
- Produces: nothing new for later tasks.

- [ ] **Step 1: Write the failing tests**

In each of the five specs add `ImageRenditionDto` to the existing `../models` import (path as in that spec) and add these two tests inside its `describe`, with the selector and `sizes` from the table:

`entry-hero.component.spec.ts` (`img.img`):

```ts
  it('offers its renditions to the browser at the column width', () => {
    const renditions: ImageRenditionDto[] = [
      { url: 'https://x/a-424.jpg', width: 424 },
      { url: 'https://x/a-848.jpg', width: 848 },
    ];
    const element = mount(entry({ imageRenditions: renditions })).nativeElement as HTMLElement;
    const img = element.querySelector('img.img') as HTMLImageElement;
    expect(img.getAttribute('srcset')).toBe('https://x/a-424.jpg 424w, https://x/a-848.jpg 848w');
    expect(img.getAttribute('sizes')).toBe('(max-width: 728px) calc(100vw - 24px), 680px');
  });

  it('keeps a plain src when the entry has no renditions', () => {
    const img = (mount(entry()).nativeElement as HTMLElement).querySelector('img.img')!;
    expect(img.hasAttribute('srcset')).toBe(false);
    expect(img.hasAttribute('sizes')).toBe(false);
  });
```

`entry-wide.component.spec.ts` (`img.img`):

```ts
  it('offers its renditions to the browser at the column width', () => {
    const renditions: ImageRenditionDto[] = [
      { url: 'https://i/a-424.jpg', width: 424 },
      { url: 'https://i/a-848.jpg', width: 848 },
    ];
    const element = mount(entry({ imageRenditions: renditions })).nativeElement as HTMLElement;
    const img = element.querySelector('img.img') as HTMLImageElement;
    expect(img.getAttribute('srcset')).toBe('https://i/a-424.jpg 424w, https://i/a-848.jpg 848w');
    expect(img.getAttribute('sizes')).toBe('(max-width: 728px) calc(100vw - 24px), 680px');
  });

  it('keeps a plain src when the entry has no renditions', () => {
    const img = (mount(entry()).nativeElement as HTMLElement).querySelector('img.img')!;
    expect(img.hasAttribute('srcset')).toBe(false);
    expect(img.hasAttribute('sizes')).toBe(false);
  });
```

`entry-split.component.spec.ts` (`img.img`):

```ts
  it('offers its renditions to the browser at the side-image width', () => {
    const renditions: ImageRenditionDto[] = [
      { url: 'https://i/a-424.jpg', width: 424 },
      { url: 'https://i/a-848.jpg', width: 848 },
    ];
    const element = mount(entry({ imageRenditions: renditions })).nativeElement as HTMLElement;
    const img = element.querySelector('img.img') as HTMLImageElement;
    expect(img.getAttribute('srcset')).toBe('https://i/a-424.jpg 424w, https://i/a-848.jpg 848w');
    expect(img.getAttribute('sizes')).toBe('(max-width: 728px) calc(38vw - 18px), 259px');
  });

  it('keeps a plain src when the entry has no renditions', () => {
    const img = (mount(entry()).nativeElement as HTMLElement).querySelector('img.img')!;
    expect(img.hasAttribute('srcset')).toBe(false);
    expect(img.hasAttribute('sizes')).toBe(false);
  });
```

`entry-thumb.component.spec.ts` (`img.img`):

```ts
  it('offers its renditions to the browser at the 88px box', () => {
    const renditions: ImageRenditionDto[] = [
      { url: 'https://i/a-150.jpg', width: 150 },
      { url: 'https://i/a-300.jpg', width: 300 },
    ];
    const element = mount(entry({ imageRenditions: renditions })).nativeElement as HTMLElement;
    const img = element.querySelector('img.img') as HTMLImageElement;
    expect(img.getAttribute('srcset')).toBe('https://i/a-150.jpg 150w, https://i/a-300.jpg 300w');
    expect(img.getAttribute('sizes')).toBe('88px');
  });

  it('keeps a plain src when the entry has no renditions', () => {
    const img = (mount(entry()).nativeElement as HTMLElement).querySelector('img.img')!;
    expect(img.hasAttribute('srcset')).toBe(false);
    expect(img.hasAttribute('sizes')).toBe(false);
  });
```

`entry-row.component.spec.ts` (`img.thumb`; these go inside `describe('EntryRowComponent', …)`, whose `beforeEach` configures TestBed):

```ts
  it('offers its renditions to the browser at the 88px box', () => {
    const renditions: ImageRenditionDto[] = [
      { url: 'https://cdn.test/a-150.jpg', width: 150 },
      { url: 'https://cdn.test/a-300.jpg', width: 300 },
    ];
    const element = mount(entry({ imageRenditions: renditions })).nativeElement as HTMLElement;
    const img = element.querySelector('img.thumb') as HTMLImageElement;
    expect(img.getAttribute('srcset')).toBe(
      'https://cdn.test/a-150.jpg 150w, https://cdn.test/a-300.jpg 300w',
    );
    expect(img.getAttribute('sizes')).toBe('88px');
  });

  it('keeps a plain src when the entry has no renditions', () => {
    const img = (mount(entry()).nativeElement as HTMLElement).querySelector('img.thumb')!;
    expect(img.hasAttribute('srcset')).toBe(false);
    expect(img.hasAttribute('sizes')).toBe(false);
  });
```

- [ ] **Step 2: Run them to see them fail**

Run: `docker compose exec -T frontend npm test -- src/app/reader/list/entry-row src/app/reader/list/magazine/blocks`
Expected: the five "offers its renditions" tests FAIL (`srcset` is null); the "keeps a plain src" tests pass already.

- [ ] **Step 3: Bind the directive**

In each component `.ts` add `import { RenditionsDirective } from '<relative path>/renditions.directive';` and append `RenditionsDirective` to `imports: [...]`:
- `entry-row.component.ts`: `import { RenditionsDirective } from '../renditions.directive';`
- `entry-hero`, `entry-wide`, `entry-split`, `entry-thumb`: `import { RenditionsDirective } from '../../../renditions.directive';`

Templates — the `<img>` of each block becomes:

`entry-hero.component.html`:
```html
    <img
      class="img"
      [src]="image()!.url"
      [appRenditions]="entry().imageRenditions"
      [renditionSizes]="'(max-width: 728px) calc(100vw - 24px), 680px'"
      [style.aspect-ratio]="aspect()"
      [attr.width]="image()!.width"
      [attr.height]="image()!.height"
      alt=""
      loading="lazy"
      decoding="async"
      referrerpolicy="no-referrer"
      (load)="onLoad($event)"
      (error)="imgError.set(true)"
    />
```

`entry-wide.component.html`:
```html
    <img
      class="img"
      [src]="image()!.url"
      [appRenditions]="entry().imageRenditions"
      [renditionSizes]="'(max-width: 728px) calc(100vw - 24px), 680px'"
      [attr.width]="image()!.width"
      [attr.height]="image()!.height"
      alt=""
      loading="lazy"
      decoding="async"
      referrerpolicy="no-referrer"
      (error)="imgError.set(true)"
    />
```

`entry-split.component.html`:
```html
      <img
        class="img"
        [src]="image()!.url"
        [appRenditions]="entry().imageRenditions"
        [renditionSizes]="'(max-width: 728px) calc(38vw - 18px), 259px'"
        [style.aspect-ratio]="aspect()"
        [attr.width]="image()!.width"
        [attr.height]="image()!.height"
        alt=""
        loading="lazy"
        decoding="async"
        referrerpolicy="no-referrer"
        (error)="imgError.set(true)"
      />
```

`entry-thumb.component.html`:
```html
    <img
      class="img"
      [src]="image()!.url"
      [appRenditions]="entry().imageRenditions"
      [renditionSizes]="'88px'"
      [attr.width]="image()!.width"
      [attr.height]="image()!.height"
      alt=""
      loading="lazy"
      decoding="async"
      referrerpolicy="no-referrer"
      (error)="imgError.set(true)"
    />
```

`entry-row.component.html`:
```html
    <img
      class="thumb"
      [src]="image()!"
      [appRenditions]="entry().imageRenditions"
      [renditionSizes]="'88px'"
      alt=""
      loading="lazy"
      decoding="async"
      referrerpolicy="no-referrer"
      (error)="imgError.set(true)"
    />
```

`docs/design-language.md` — after the paragraph that ends "…would still leave an image block with no image." (end of the block table section, before `## 6. Deliberate exceptions`), add:

```md
**Each image block states its rendered width as `sizes`.** An entry with a
rendition ladder (`imageRenditions`, #1330) gets a `srcset` through
`RenditionsDirective` (`reader/list/renditions.directive.ts`), and the browser
loads the smallest file that covers the box. The values are upper bounds read
off the CSS above; a block whose width changes changes its value too:

| Block | `sizes` | Read from |
|---|---|---|
| Hero, Wide | `(max-width: 728px) calc(100vw - 24px), 680px` | a full-width image in the `--magazine-measure` column, inset `--space-3` (boxed) or `--space-5` (airy) a side |
| Split | `(max-width: 728px) calc(38vw - 18px), 259px` | 38% of that card's content box |
| Thumb, list row | `88px` | the fixed 88px box |
```

- [ ] **Step 4: Run them to see them pass, then the gate**

Run: `docker compose exec -T frontend npm test -- src/app/reader/list`
Expected: PASS.
Run: `docker compose exec -T frontend npm run check`
Expected: PASS (ESLint, Prettier, Stylelint, Jest).

- [ ] **Step 5: Commit**

```bash
git add frontend/src/app/reader/list docs/design-language.md
git commit -m "feat(#1330): list blocks let the browser pick the smallest image rendition"
```

---

### Task 10: Gates and a real-browser check

**Files:** none new; fixes land in the files of the task they belong to.

**Interfaces:** Consumes everything above; produces the PR.

- [ ] **Step 1: Backend gates (from `backend/`)**

```bash
composer cs
bin/console cache:warmup && composer stan
composer md
composer tramp
php bin/phpunit
docker compose exec php composer test
```
Expected: all clean/green. If `composer tramp` fails on code this branch did not touch, check `composer show larspohlmann/phptramp` first (CI runs its `develop` tip). PhpStorm inspections (`mcp__phpstorm__lint_files`) on every changed PHP file: no ERROR or WARNING.

- [ ] **Step 2: Mutation gate**

Everything committed first (`infection:diff` ignores untracked files). Run from `backend/`: `composer infection:diff`
Expected: MSI ≥ 80. Escaped mutants on the new lines get a killing test in the task's test file, not a lowered threshold.

- [ ] **Step 3: Migration from empty on both platforms**

Re-run Task 4 Step 5 (SQLite and MySQL blocks) on the final branch state. Expected: `[OK] The database schema is in sync with the mapping files.` on both.

- [ ] **Step 4: Frontend gate**

Run: `docker compose exec -T frontend npm run check` and `docker compose exec -T frontend npx tsc -p tsconfig.app.json --noEmit`
Expected: PASS.

- [ ] **Step 5: Make the stack run the new code**

```bash
docker compose exec php bin/console doctrine:migrations:migrate --no-interaction
docker compose exec php bin/console cache:clear
docker compose restart worker
```

Then refresh a WordPress and a Substack feed so new items are ingested with ladders (feed ids from the issue's survey: 153 = 5mag.net, 280 = adbusters.substack.com; confirm the ids in the dev database first):

```bash
docker compose exec php bin/console app:feeds:refresh --feed=153 --force
docker compose exec php bin/console app:feeds:refresh --feed=280 --force
docker compose exec -T mysql mysql -ufeedreader -pfeedreader feedreader \
  -e "SELECT id, feed_id, LEFT(image_renditions, 120) FROM entry WHERE image_renditions IS NOT NULL ORDER BY id DESC LIMIT 5"
```

Expected: rows with a JSON ladder. Ladders reach only entries ingested from now on; if neither feed has a new item, wait for one or pick another srcset feed from the survey (`grep substack` in the issue's feed list). Do not edit rows by hand.

- [ ] **Step 6: Check it in the browser**

Open `http://localhost:4200/?subscription=<the 5mag or Substack subscription id>` in the Browser pane (mobile viewport if the built-in browser's UA is bot-blocked). With `read_page`/`javascript_tool` confirm a list `<img>` carries `srcset` and `sizes`, e.g.:

```js
[...document.querySelectorAll('img[srcset]')].slice(0, 3).map((img) => ({
  sizes: img.getAttribute('sizes'),
  current: img.currentSrc,
  natural: img.naturalWidth,
}))
```

Expected: a thumb/row image with `sizes: "88px"` whose `currentSrc` is a small rendition (e.g. `…-300x200.jpg` or `…w_424…`), not the `src`. In `read_network_requests` (filter on the image host) the transferred file for that thumb is the small rendition. An entry without a ladder renders exactly as before (no `srcset`). Reset the viewport to desktop afterwards.

- [ ] **Step 7: Scan the dev log**

Run: `ls -t backend/var/log/dev-*.log | head -1 | xargs tail -n 200 | jq -c 'select(.level >= 300)'`
Expected: no new warnings or errors from parsing, ingest or `/api/entries`.

- [ ] **Step 8: Push and open the PR**

```bash
git push -u origin feature/1330-responsive-feed-images
gh pr create --base develop --title "feat(#1330): responsive feed images" \
  --body "Closes #1330"
```
