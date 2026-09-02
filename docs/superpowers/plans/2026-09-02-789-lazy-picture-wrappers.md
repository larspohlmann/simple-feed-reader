# #789 Lazy Picture Wrappers Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** The reader keeps the photos of a page whose pictures are lazy (`<source data-srcset>`), wrapped in custom elements, or wrapped in text-less containers whose class readability scores negative — nature.com 495343 renders all nine.

**Architecture:** Three repairs in the shared pre-readability pass (`FetchedPageNormalizer::repair()`): `LazyImageSources` reads a `<source>`'s candidates from the same `data-*` attributes it already honours on `<img>`; a new `CustomElementUnwrapper` replaces every hyphenated custom element with its children before readability and the sanitizer ever see it; a new `ImageWrapperClassRemover` strips `class`/`id` from text-less single-image wrappers so readability's negative class weight cannot delete a picture. Nothing downstream changes.

**Tech Stack:** PHP 8.4 `\Dom\HTMLDocument`, PHPUnit; one Angular constant.

**Spec:** `docs/superpowers/specs/2026-09-02-789-lazy-picture-wrappers-design.md`

## Global Constraints

- Branch `fix/789-lazy-picture-wrappers`; commit messages `type(#789): summary`; no attribution lines, no `Co-Authored-By`.
- PHP: `declare(strict_types=1)`, `final readonly class`, PSR-12, PHPStan level max, **every touched `src` file PHPMD-clean** (`composer md`). No boolean flag parameters. Comments only for a why, one line, three at most; class docblocks stay short.
- Run from `backend/`: `composer cs` (autofix `composer cs:fix`), `composer stan` (after `bin/console cache:warmup --env=dev >/dev/null`), `composer md`, `php bin/phpunit <path>`.
- Step order inside `FetchedPageNormalizer::repair()` is binding: `CustomElementUnwrapper` runs **first**; `ImageWrapperClassRemover` runs **last**, after `ShareWidgetRemover` and the screen-reader-only removal.
- The `<img>` element's own attributes are never touched by the wrapper-class step; `body` is never touched.
- `FetchedPageNormalizer` is constructed by hand in tests: update every `new FetchedPageNormalizer(` call site (`rg -n "new FetchedPageNormalizer\(" tests`).
- Frontend: run Jest inside the Docker frontend container (`docker compose exec -T frontend npx jest <path>` from the repo root).
- `grep` on this machine is ugrep; use `rg` or `grep -F`.

---

### Task 1: A lazy `<picture>` keeps its image

**Files:**
- Modify: `backend/src/Service/Reader/LazyImageSources.php`
- Modify: `backend/tests/Service/Reader/LazyImageSourcesTest.php`

**Interfaces:**
- Produces: unchanged public API `LazyImageSources::resolveIn(HTMLDocument): void`; a `<source>` now yields candidates from `data-lazy-srcset`, `data-srcset`, `srcset` in that order.

- [ ] **Step 1: Write the failing tests**

Append to `LazyImageSourcesTest` (the helpers `resolvedSource()` and `resolvedHtml()` exist at the bottom of the class):

```php
    /** nature.com 495343: a lazy <picture> carries its candidates on `data-srcset`, and its <img> has no src at all. */
    public function testPromotesTheLazySourceOfAPictureWhoseImageIsBare(): void
    {
        $source = $this->resolvedSource(
            '<picture data-lazy="true"><source data-srcset="./assets/a/photo-750x422.webp 750w,'
            . ' ./assets/a/photo-2560x1440.webp 2560w" type="image/webp"><img alt="A"></picture>'
        );

        self::assertSame('./assets/a/photo-750x422.webp', $source);
    }

    public function testPrefersAPictureSourcesLazyListOverItsPlaceholderList(): void
    {
        $source = $this->resolvedSource(
            '<picture><source srcset="https://images.example.com/blank.gif 20w"'
            . ' data-srcset="https://images.example.com/real.jpg 750w"><img alt="A"></picture>'
        );

        self::assertSame('https://images.example.com/real.jpg', $source);
    }

    public function testAdoptsAWiderLazyPictureSourceOverThePlaceholderImage(): void
    {
        $source = $this->resolvedSource(
            '<picture><source data-srcset="https://images.example.com/large.jpg 2000w">'
            . '<img src="https://images.example.com/small.jpg?w=300" alt="A"></picture>'
        );

        self::assertSame('https://images.example.com/large.jpg', $source);
    }

    public function testFlattensALazyPictureOncePromoted(): void
    {
        $html = $this->resolvedHtml(
            '<figure><picture data-lazy="true"><source data-srcset="https://images.example.com/photo.jpg 750w">'
            . '<img alt="A"></picture></figure>'
        );

        self::assertStringNotContainsString('<picture', $html);
        self::assertStringNotContainsString('<source', $html);
        self::assertStringContainsString('<img alt="A" src="https://images.example.com/photo.jpg">', $html);
    }
```

- [ ] **Step 2: Run the tests to verify they fail**

Run from `backend/`: `php bin/phpunit tests/Service/Reader/LazyImageSourcesTest.php`
Expected: the first, second and fourth fail (image removed: `null` / no `<img`); the third returns the small image.

- [ ] **Step 3: Read a source's candidate list from the lazy attributes too**

In `LazyImageSources`, add a private helper after `usableSrcsetHead()`:

```php
    /** A <source>'s candidate list, from the same lazy attributes an <img> is read by. */
    private function srcsetOf(Element $source): ?string
    {
        foreach (self::SRCSET_ATTRIBUTES as $attribute) {
            $srcset = $source->getAttribute($attribute);
            if ($srcset !== null && trim($srcset) !== '') {
                return $srcset;
            }
        }

        return null;
    }
```

Use it in the two places that read a `<source>`:

- `renditionOf()`: replace `Srcset::widest($source->getAttribute('srcset'))` with `Srcset::widest($this->srcsetOf($source))`.
- `candidateFromEnclosingPicture()`: replace `$this->usableSrcsetHead($source->getAttribute('srcset') ?? '')` with `$this->usableSrcsetHead($this->srcsetOf($source) ?? '')`.

Add to the class docblock, after the ZDFheute paragraph, one sentence: ` * A lazy <picture> keeps those candidates on `data-srcset` (nature.com, #789); the <source> is read by the same lazy attributes as the <img>.`

- [ ] **Step 4: Run the tests to verify they pass**

Run: `php bin/phpunit tests/Service/Reader/LazyImageSourcesTest.php tests/Service/Reader/FetchedPageNormalizerTest.php tests/Service/Reader/PageImageInventoryTest.php`
Expected: green.

- [ ] **Step 5: Lint**

Run: `composer cs && composer md && bin/console cache:warmup --env=dev >/dev/null && composer stan`
Expected: clean.

- [ ] **Step 6: Commit**

```bash
git add src/Service/Reader/LazyImageSources.php tests/Service/Reader/LazyImageSourcesTest.php
git commit -m "fix(#789): read a lazy picture's candidates from the source's data-srcset"
```

---

### Task 2: Custom elements are unwrapped before readability

**Files:**
- Create: `backend/src/Service/Reader/CustomElementUnwrapper.php`
- Create: `backend/tests/Service/Reader/CustomElementUnwrapperTest.php`
- Modify: `backend/src/Service/Reader/FetchedPageNormalizer.php` (constructor, `repair()`, docblock bullet)
- Modify: `backend/tests/Service/Reader/FetchedPageNormalizerTest.php` (setUp + one test)
- Modify: `backend/tests/Service/Reader/ArticleExtractorTest.php` (the `new FetchedPageNormalizer(` in `extractor()`), and any other call site `rg -n "new FetchedPageNormalizer\(" tests` lists

**Interfaces:**
- Produces: `CustomElementUnwrapper::unwrapIn(\Dom\HTMLDocument $document): void`.
- Produces: `FetchedPageNormalizer::__construct(CustomElementUnwrapper $customElements, LazyImageSources $lazyImages, ShareWidgetRemover $shareWidgets, ShareIntentLinkRemover $shareIntentLinks, SubstackGatedVideoPlaceholder $substackPlaceholder)` — the new collaborator goes **first**, in step order.

- [ ] **Step 1: Write the failing tests**

`backend/tests/Service/Reader/CustomElementUnwrapperTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Service\Reader;

use App\Service\Reader\CustomElementUnwrapper;
use Dom\HTMLDocument;
use PHPUnit\Framework\TestCase;

final class CustomElementUnwrapperTest extends TestCase
{
    private CustomElementUnwrapper $unwrapper;

    protected function setUp(): void
    {
        $this->unwrapper = new CustomElementUnwrapper();
    }

    /** nature.com 495343: every section photo sits inside <sh-background-transition>, which the sanitizer drops with its children. */
    public function testReplacesACustomElementWithItsChildrenInPlace(): void
    {
        $html = $this->unwrapped(
            '<div id="s"><sh-background-transition class="Layer--one"><div id="v"><img src="https://x.test/a.jpg" alt=""></div>'
            . '</sh-background-transition><p>Caption.</p></div>'
        );

        self::assertStringNotContainsString('sh-background-transition', $html);
        self::assertStringContainsString('<div id="s"><div id="v"><img src="https://x.test/a.jpg" alt=""></div><p>Caption.</p></div>', $html);
    }

    public function testUnwrapsNestedCustomElementsAndKeepsTheirText(): void
    {
        $html = $this->unwrapped('<p>Before <my-outer><my-inner>inside</my-inner> tail</my-outer> after</p>');

        self::assertStringContainsString('<p>Before inside tail after</p>', $html);
    }

    public function testLeavesStandardElementsAlone(): void
    {
        $html = $this->unwrapped('<figure class="a-b"><img src="https://x.test/a.jpg" alt=""><figcaption>C</figcaption></figure>');

        self::assertStringContainsString('<figure class="a-b">', $html);
        self::assertStringContainsString('<figcaption>C</figcaption>', $html);
    }

    public function testAnEmptyCustomElementSimplyDisappears(): void
    {
        self::assertStringNotContainsString('lite-youtube', $this->unwrapped('<p>A</p><lite-youtube videoid="x"></lite-youtube>'));
    }

    private function unwrapped(string $bodyHtml): string
    {
        $document = HTMLDocument::createFromString('<html lang="en"><body>' . $bodyHtml . '</body></html>', LIBXML_NOERROR);
        $this->unwrapper->unwrapIn($document);

        return $document->saveHtml();
    }
}
```

In `FetchedPageNormalizerTest`, change `setUp()` to construct the normalizer with `new CustomElementUnwrapper()` as the first argument (add the `use App\Service\Reader\CustomElementUnwrapper;` import) and append:

```php
    public function testUnwrapsACustomElementSoItsPhotoReachesReadability(): void
    {
        $normalized = $this->normalized(
            '<html lang="en"><body><article><sh-background-transition><div><img src="https://x.test/a.jpg" alt=""></div>'
            . '</sh-background-transition><p>Caption.</p></article></body></html>'
        );

        self::assertStringNotContainsString('sh-background-transition', $normalized);
        self::assertStringContainsString('<img src="https://x.test/a.jpg" alt="">', $normalized);
    }
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `php bin/phpunit tests/Service/Reader/CustomElementUnwrapperTest.php tests/Service/Reader/FetchedPageNormalizerTest.php`
Expected: class not found.

- [ ] **Step 3: Write the unwrapper**

```php
<?php

declare(strict_types=1);

namespace App\Service\Reader;

use Dom\HTMLDocument;

/**
 * Replaces every custom element (a hyphen in the tag name, per the HTML spec)
 * with its children. The sanitizer drops an element it does not know together
 * with its content, so a photo inside nature's <sh-background-transition>
 * never reached the reader (#789). Unwrapped here, it is ordinary content.
 */
final readonly class CustomElementUnwrapper
{
    public function unwrapIn(HTMLDocument $document): void
    {
        // Innermost first: an outer element unwrapped later still holds the
        // already-unwrapped children of its former descendants.
        foreach (array_reverse(iterator_to_array($document->querySelectorAll('*'))) as $element) {
            if ($element->parentNode !== null && str_contains($element->localName, '-')) {
                $element->replaceWith(...iterator_to_array($element->childNodes));
            }
        }
    }
}
```

- [ ] **Step 4: Wire it first in `repair()`**

In `FetchedPageNormalizer`: add `private CustomElementUnwrapper $customElements,` as the **first** constructor parameter; in `repair()`, call `$this->customElements->unwrapIn($document);` as the first line after the null guard, before `$this->lazyImages->resolveIn($document);`. Add a bullet to the class docblock's repair list:

```
 *  - A custom element (nature's <sh-background-transition>) is unknown to the
 *    sanitizer, which drops it with its children. CustomElementUnwrapper
 *    replaces it with its children first, so nothing later sees it (#789).
```

Update every `new FetchedPageNormalizer(` call site in `tests/` to pass `new CustomElementUnwrapper()` first.

- [ ] **Step 5: Run the tests**

Run: `php bin/phpunit tests/Service/Reader`
Expected: green.

- [ ] **Step 6: Lint**

Run: `composer cs && composer md && bin/console cache:warmup --env=dev >/dev/null && composer stan`
Expected: clean.

- [ ] **Step 7: Commit**

```bash
git add src/Service/Reader/CustomElementUnwrapper.php tests/Service/Reader/CustomElementUnwrapperTest.php src/Service/Reader/FetchedPageNormalizer.php tests/Service/Reader/FetchedPageNormalizerTest.php tests/Service/Reader/ArticleExtractorTest.php
git commit -m "fix(#789): unwrap custom elements before readability so the sanitizer cannot drop their photos"
```

(Add any further call-site file `rg` found to the `git add`.)

---

### Task 3: A picture wrapper's class cannot get it deleted

**Files:**
- Create: `backend/src/Service/Reader/ImageWrapperClassRemover.php`
- Create: `backend/tests/Service/Reader/ImageWrapperClassRemoverTest.php`
- Modify: `backend/src/Service/Reader/FetchedPageNormalizer.php` (constructor, `repair()`, docblock bullet)
- Modify: `backend/tests/Service/Reader/FetchedPageNormalizerTest.php` (setUp + two tests)
- Modify: every `new FetchedPageNormalizer(` call site in `tests/`

**Interfaces:**
- Produces: `ImageWrapperClassRemover::removeFrom(\Dom\HTMLDocument $document): void`.
- Produces: `FetchedPageNormalizer::__construct(CustomElementUnwrapper $customElements, LazyImageSources $lazyImages, ShareWidgetRemover $shareWidgets, ShareIntentLinkRemover $shareIntentLinks, SubstackGatedVideoPlaceholder $substackPlaceholder, ImageWrapperClassRemover $imageWrapperClasses)` — the new collaborator goes **last**.

- [ ] **Step 1: Write the failing tests**

`backend/tests/Service/Reader/ImageWrapperClassRemoverTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Service\Reader;

use App\Service\Reader\ImageWrapperClassRemover;
use Dom\HTMLDocument;
use PHPUnit\Framework\TestCase;

final class ImageWrapperClassRemoverTest extends TestCase
{
    private ImageWrapperClassRemover $remover;

    protected function setUp(): void
    {
        $this->remover = new ImageWrapperClassRemover();
    }

    /** nature.com 495343: readability weights `ResponsiveMedia` −25 and removes the text-less wrapper, photo included. */
    public function testStripsClassAndIdFromTextlessSingleImageWrappers(): void
    {
        $html = $this->stripped(
            '<div id="section-x" class="Theme-Section"><div class="Theme-Layer-ResponsiveMedia">'
            . '<div class="ResponsiveMedia--image__inner" id="inner"><img src="https://x.test/a.jpg" alt=""></div></div>'
            . '<p>Caption.</p></div>'
        );

        self::assertStringContainsString('<div id="section-x" class="Theme-Section"><div><div><img src="https://x.test/a.jpg" alt=""></div></div>', $html);
    }

    public function testStopsAtAWrapperThatCarriesText(): void
    {
        $html = $this->stripped(
            '<figure class="InlineMedia"><div class="InlineMedia--image__inner"><img src="https://x.test/a.jpg" alt=""></div>'
            . '<figcaption class="Theme-Caption">Luca Bindi et al.</figcaption></figure>'
        );

        self::assertStringContainsString('<figure class="InlineMedia"><div><img', $html);
        self::assertStringContainsString('<figcaption class="Theme-Caption">', $html);
    }

    public function testLeavesAWrapperThatHoldsMoreThanOneImage(): void
    {
        $html = $this->stripped(
            '<div class="MediaGallery_carousel"><div class="cell"><img src="https://x.test/a.jpg" alt=""></div>'
            . '<div class="cell"><img src="https://x.test/b.jpg" alt=""></div></div>'
        );

        self::assertStringContainsString('<div class="MediaGallery_carousel"><div><img', $html);
        self::assertStringContainsString('</div><div><img src="https://x.test/b.jpg"', $html);
    }

    public function testNeverTouchesTheImageItselfOrTheBody(): void
    {
        $html = $this->stripped('<img class="FullSize lazy" id="hero" src="https://x.test/a.jpg" alt="">');

        self::assertStringContainsString('<body class="page"><img class="FullSize lazy" id="hero"', $html);
    }

    public function testATextWrapperKeepsItsClassWhenTheImageIsInline(): void
    {
        $html = $this->stripped('<p class="lead">Text <img src="https://x.test/i.png" alt=""> more</p>');

        self::assertStringContainsString('<p class="lead">', $html);
    }

    /** treehugger: a sidebar card's thumbnail sits in a link; readability drops the card by its `media` class, and must keep doing so. */
    public function testLeavesALinkedCardThumbnailAlone(): void
    {
        $html = $this->stripped(
            '<a class="card" href="https://x.test/other"><div class="card__media"><div class="img-placeholder">'
            . '<img src="https://x.test/thumb.jpg" alt=""></div></div></a>'
        );

        self::assertStringContainsString('<div class="card__media"><div class="img-placeholder">', $html);
    }

    public function testLeavesAnImageInsidePageFurnitureAlone(): void
    {
        $html = $this->stripped('<aside><div class="teaser-media"><img src="https://x.test/t.jpg" alt=""></div></aside>');

        self::assertStringContainsString('<div class="teaser-media">', $html);
    }

    private function stripped(string $bodyHtml): string
    {
        $document = HTMLDocument::createFromString(
            '<html lang="en"><body class="page">' . $bodyHtml . '</body></html>',
            LIBXML_NOERROR,
        );
        $this->remover->removeFrom($document);

        return $document->saveHtml();
    }
}
```

In `FetchedPageNormalizerTest`, pass `new ImageWrapperClassRemover()` as the last constructor argument (import it) and append:

```php
    public function testStripsTheClassOfATextlessPictureWrapperBeforeReadabilityScoresIt(): void
    {
        $normalized = $this->normalized(
            '<html lang="en"><body><article><div class="Theme-Layer-ResponsiveMedia"><div class="ResponsiveMedia--image__inner">'
            . '<img src="https://x.test/a.jpg" alt=""></div></div><p>Caption.</p></article></body></html>'
        );

        self::assertStringNotContainsString('ResponsiveMedia', $normalized);
        self::assertStringContainsString('<img src="https://x.test/a.jpg" alt="">', $normalized);
    }

    /** Order matters: the share-widget fingerprint is read before the picture wrapper's class goes. */
    public function testAShareWidgetThatHoldsOnlyAnIconIsStillRemoved(): void
    {
        $normalized = $this->normalized(
            '<html lang="en"><body><article><p>Text.</p><div class="sharedaddy">'
            . '<img src="https://x.test/icon.png" alt=""></div></article></body></html>'
        );

        self::assertStringNotContainsString('icon.png', $normalized);
    }
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `php bin/phpunit tests/Service/Reader/ImageWrapperClassRemoverTest.php tests/Service/Reader/FetchedPageNormalizerTest.php`
Expected: class not found.

- [ ] **Step 3: Write the remover**

```php
<?php

declare(strict_types=1);

namespace App\Service\Reader;

use App\Service\Reader\Media\PageFurniture;
use Dom\Element;
use Dom\HTMLDocument;

/**
 * Readability weights an element's class and id by word (RegExps::NEGATIVE:
 * media, promo, share, widget …) and removes a text-less <div> whose weight is
 * negative — so a picture wrapper named `ResponsiveMedia` takes the picture
 * with it (nature.com, #789). A wrapper holding one image and no text carries
 * no scoring signal worth keeping; without class and id, the picture survives.
 * A linked image is a card or teaser and keeps its wrappers' classes, so
 * readability still drops the card by them (treehugger's sidebar thumbnails).
 */
final readonly class ImageWrapperClassRemover
{
    public function removeFrom(HTMLDocument $document): void
    {
        foreach ($document->querySelectorAll('img') as $image) {
            if ($image->closest('a') === null && !PageFurniture::holds($image)) {
                $this->stripWrappersOf($image);
            }
        }
    }

    private function stripWrappersOf(Element $image): void
    {
        $wrapper = $image->parentNode;
        while ($wrapper instanceof Element && $this->isSoleImageWrapper($wrapper)) {
            $wrapper->removeAttribute('class');
            $wrapper->removeAttribute('id');
            $wrapper = $wrapper->parentNode;
        }
    }

    private function isSoleImageWrapper(Element $element): bool
    {
        return $element->localName !== 'body'
            && trim((string) $element->textContent) === ''
            && $element->querySelectorAll('img')->length === 1;
    }
}
```

- [ ] **Step 4: Wire it last in `repair()`**

In `FetchedPageNormalizer`: add `private ImageWrapperClassRemover $imageWrapperClasses,` as the **last** constructor parameter; in `repair()`, call `$this->imageWrapperClasses->removeFrom($document);` as the last line before `return $document;` (after `removeOrphanIconGlyphs`). Add the docblock bullet:

```
 *  - Readability scores a wrapper's class and id by word and removes a
 *    text-less <div> named `…Media…` with its picture. ImageWrapperClassRemover
 *    strips class and id from text-less single-image wrappers last, after the
 *    class-reading removals above have run (#789).
```

Update every `new FetchedPageNormalizer(` call site in `tests/` to pass `new ImageWrapperClassRemover()` last.

- [ ] **Step 5: Run the tests**

Run: `php bin/phpunit tests/Service/Reader tests/Service/ReaderAudit`
Expected: green, including `ConfirmedGoodArticlesTest` — the 25 confirmed-good articles must not change.

- [ ] **Step 6: Lint**

Run: `composer cs && composer md && bin/console cache:warmup --env=dev >/dev/null && composer stan`
Expected: clean.

- [ ] **Step 7: Commit**

```bash
git add src/Service/Reader/ImageWrapperClassRemover.php tests/Service/Reader/ImageWrapperClassRemoverTest.php src/Service/Reader/FetchedPageNormalizer.php tests/Service/Reader/FetchedPageNormalizerTest.php tests/Service/Reader/ArticleExtractorTest.php
git commit -m "fix(#789): strip class and id from text-less picture wrappers so readability keeps the photo"
```

---

### Task 4: The immersive-gallery fixture through the whole pipeline

**Files:**
- Create: `backend/tests/Fixtures/reader/article-immersive-gallery.html`
- Modify: `backend/tests/Service/Reader/ArticleExtractorTest.php` (append one test)

**Interfaces:**
- Consumes: Tasks 1–3 through the real `FetchedPageNormalizer` the test factory builds.
- Consumes: `ArticleExtractorTest::extractor(callable|iterable $responses, array $dnsMap = ['site.test' => …])` (exists).

- [ ] **Step 1: Write the fixture**

`backend/tests/Fixtures/reader/article-immersive-gallery.html` — the nature shape: relative `./assets/` URLs, lazy pictures on `data-srcset`, one photo in a custom element, one in a `ResponsiveMedia` wrapper, one in an `InlineMedia` figure with a caption:

```html
<!DOCTYPE html>
<html lang="en"><head><title>Say hello to the next generation of lobsters — Site</title>
<meta property="og:image" content="https://site.test/immersive/story/assets/lead/lobster-1066x600.jpg">
</head>
<body>
  <nav><a href="/">Home</a><a href="/news">News</a></nav>
  <article id="article" class="Core--rootElement">
    <header id="section-lead" class="Theme-Section">
      <div class="Layer--two"><div id="lead-viewport" class="FullSize--fullWidth">
        <picture class="FullSize--fullWidth Theme-Item-Picture" data-lazy="true">
          <source data-srcset="./assets/lead/lobster-750x422.webp 750w, ./assets/lead/lobster-2560x1440.webp 2560w" type="image/webp">
          <img class="FullSize ObjectFit--cover" alt="Juvenile lobsters in hatchery pods" width="2560" height="1440">
        </picture>
      </div></div>
      <h1>Say hello to the next generation of lobsters</h1>
      <div><p>The month’s sharpest science shots, selected by the photo team, with a note on where each was taken and why it matters to the people who took it.</p></div>
    </header>
    <div id="section-eclipse" class="Theme-Section">
      <div class="Theme-Layer-ResponsiveMedia"><div class="ResponsiveMedia--image__inner">
        <picture class="Theme-Item-Picture" data-lazy="true">
          <source data-srcset="./assets/eclipse/shadows-750x422.webp 750w, ./assets/eclipse/shadows-2560x1440.webp 2560w" type="image/webp">
          <img alt="Crescent-shaped projections on a wall during a solar eclipse" width="2560" height="1440">
        </picture>
      </div></div>
      <p><strong>Eclipse shadows.</strong> These eclipse shadows were photographed on 12 August, during the brief time when the Moon completely blocked the Sun from view over a narrow stretch of Greenland, Iceland and Spain, causing a total eclipse that researchers travelled far to study.</p>
      <p>Ana Beltran/Reuters</p>
    </div>
    <div id="section-comet" class="Theme-Section">
      <sh-background-transition class="Layer--one"><div id="comet-viewport" class="FullSize--fullWidth">
        <picture class="Theme-Item-Picture" data-lazy="true">
          <source data-srcset="./assets/comet/starlink-750x422.webp 750w, ./assets/comet/starlink-2560x1440.webp 2560w" type="image/webp">
          <img alt="" width="2560" height="1440">
        </picture>
      </div></sh-background-transition>
      <p><strong>The Starlink way.</strong> A group of newly launched satellites appears as a bright streak across the night sky in this time-lapse image, a reminder of how crowded low Earth orbit has become and how much astronomers now have to work around it.</p>
      <p>Kouyu Wang/DarkSky International</p>
    </div>
    <div id="section-rock" class="Theme-Section">
      <div class="Theme-Layer-BodyText"><div class="Theme-Layer-BodyText--inner">
        <p><strong>Hiroshimaite.</strong> Shortly before the anniversary of the atomic bombing of Hiroshima, researchers revealed how the explosion created a new type of alloy found in a fragment of fallout debris, imaged here with a scanning electron microscope and described this month.</p>
        <figure class="InlineMedia InlineMedia--image"><div class="InlineMedia--image__inner"><div class="FullSize">
          <picture data-lazy="true">
            <source data-srcset="./assets/rock/alloy-750x751.jpg 750w, ./assets/rock/alloy-900x901.jpg 900w" type="image/jpeg">
            <img alt="Scanning electron microscope image of a hiroshimaite sample" width="900" height="901">
          </picture>
        </div></div><figcaption class="Theme-Caption">Luca Bindi et al./Sci. Adv.</figcaption></figure>
        <p>The nuclear detonation produced temperatures higher than the surface of the Sun for a fraction of a second, long enough to melt and fuse metals that never meet in nature into the alloys the team has now catalogued in detail.</p>
      </div></div>
    </div>
  </article>
  <footer>© 2026</footer>
</body></html>
```

- [ ] **Step 2: Write the end-to-end test**

Append to `ArticleExtractorTest`:

```php
    /** nature.com 495343: lazy pictures on data-srcset, one in a custom element, one in a media-classed wrapper, one in a captioned figure. */
    public function testKeepsEveryPhotoOfAnImmersiveGalleryBesideItsCaption(): void
    {
        $html = (string) file_get_contents(__DIR__ . '/../../Fixtures/reader/article-immersive-gallery.html');
        $extractor = $this->extractor([new MockResponse($html, ['http_code' => 200])]);

        $result = $extractor->extract('https://site.test/immersive/story/index.html');

        self::assertTrue($result->ok);
        $body = (string) $result->contentHtml;
        foreach (['eclipse/shadows-750x422.webp', 'comet/starlink-750x422.webp', 'rock/alloy-750x751.jpg'] as $photo) {
            self::assertStringContainsString('https://site.test/immersive/story/assets/' . $photo, $body);
        }
        self::assertStringNotContainsString('sh-background-transition', $body);
        self::assertLessThan((int) strpos($body, 'Eclipse shadows.'), (int) strpos($body, 'eclipse/shadows'));
        self::assertLessThan((int) strpos($body, 'The Starlink way.'), (int) strpos($body, 'comet/starlink'));
        self::assertGreaterThan((int) strpos($body, 'Hiroshimaite.'), (int) strpos($body, 'rock/alloy'));
        self::assertLessThan((int) strpos($body, 'The nuclear detonation'), (int) strpos($body, 'rock/alloy'));
    }
```

- [ ] **Step 3: Run the test, then prove it needed all three repairs**

Run: `php bin/phpunit tests/Service/Reader/ArticleExtractorTest.php --filter ImmersiveGallery`
Expected: green.

Then, one at a time, stash one repair and confirm the test fails, restoring after each (record the three failure outputs in your report):

```bash
git stash push src/Service/Reader/LazyImageSources.php && php bin/phpunit tests/Service/Reader/ArticleExtractorTest.php --filter ImmersiveGallery; git stash pop
```

For the other two, temporarily comment out the `unwrapIn` call, then the `removeFrom` call, in `FetchedPageNormalizer::repair()`, run the same filter, and restore the line (`git diff` must be empty for `src/` afterwards).

Then the whole suite: `php bin/phpunit`
Expected: green.

- [ ] **Step 4: Lint and commit**

Run: `composer cs && composer stan`
Expected: clean.

```bash
git add tests/Fixtures/reader/article-immersive-gallery.html tests/Service/Reader/ArticleExtractorTest.php
git commit -m "test(#789): an immersive gallery keeps every photo beside its caption"
```

---

### Task 5: Reader cache version

**Files:**
- Modify: `frontend/src/app/reader/reader-cache.service.ts` (the `VERSION` constant and its comment block)

- [ ] **Step 1: Bump the version**

Read the current `private static readonly VERSION = N;` line. Add, after the last `// vN:` comment line, and set the constant to `N + 1`:

```ts
  // v<N+1>: v<N> records lost every photo held in a lazy <picture>, a custom
  // element or a media-classed wrapper (#789); an already-read gallery would
  // keep its empty figures.
```

- [ ] **Step 2: Test and check**

Run from the repo root: `docker compose exec -T frontend npx jest src/app/reader/reader-cache`
Expected: green.
Run: `docker compose exec -T frontend npm run check`
Expected: green.

- [ ] **Step 3: Commit**

```bash
git add frontend/src/app/reader/reader-cache.service.ts
git commit -m "fix(#789): bump the reader cache version so photo-less gallery bodies are refetched"
```

---

### Task 6: Verification (controller-run)

- [ ] **Step 1: Backend gates**

From `backend/`: `composer check && composer md && php bin/phpunit && composer infection:diff`
Expected: green; MSI on the changed files at or above `minMsi`.

- [ ] **Step 2: MySQL leg**

From the repo root: `docker compose exec -T php vendor/bin/phpunit tests/Service/Reader tests/Service/ReaderAudit`
Expected: green.

- [ ] **Step 3: Live check of entry 495343**

Open `http://localhost:4200/?subscription=596&entry=495343-say-hello-to-the-next-generation-of-lobsters-august-s-best-science-images`, reload the article (the reader's "Reload article" button), and count the photos: nine, each directly beside its caption (lobster, eclipse shadows, hiroshimaite, Starlink, waterlily, Sun's surface, and the three river photos). Screenshot as evidence.
