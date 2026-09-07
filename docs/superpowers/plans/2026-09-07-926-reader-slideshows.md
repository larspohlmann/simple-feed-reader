# Reader Slideshows Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Detect image slideshows in fetched article HTML and recreate them in the reader view as swipeable carousels, degrading to a captioned image stack with no JavaScript.

**Architecture:** One scan over the raw normalized page runs a locator of recognizers, each returning source-agnostic `Slideshow` value objects (slides + title + anchor + original-container signature). `ReaderBodyCleaner` inserts a sanitizer-safe `<figure class="reader-slideshow">` per slideshow into the cleaned body (removing any surviving original container), anchored via the existing `PageTextBlocks` mechanism. A client enhancer upgrades that static markup to a swipeable carousel.

**Tech Stack:** PHP 8.4 / Symfony 7.4, `\Dom\HTMLDocument`, PHPUnit; Angular 20 standalone/signals, Jest.

**Spec:** `docs/superpowers/specs/2026-09-07-reader-slideshows-design.md`

## Global Constraints

- `declare(strict_types=1);` in every PHP file; PSR-12; PHPStan level max; PHPMD codesize-clean for every `src` file touched.
- Clean Code: `final readonly` with constructor promotion, guard clauses, names reveal intent, no boolean flag params, ≤3 params where avoidable, errors as typed exceptions, default to no comment.
- Frontend: standalone components/signals, no NgModules; component styles in `.scss` (no inline); no hex colours / raw `px` / media-query literals outside `src/app/theme/` — tokens only.
- Each slide renders as a **single-src `<img>`** — `EntrySanitizer` strips `<picture>`/`<source>`/`srcset`.
- The client marker is `class="reader-slideshow"` on the `<figure>`; `EntrySanitizer` must allow `class` on `figure`.
- Detection guard: a slideshow needs **two or more slides that each resolve to an image**. Enforced once, in `Slideshow::fromSlides()`.
- Slide-image resolution order: descendant `<img src=http…>` → lazy attr (`data-src`, `data-lazy-src`, `data-original`, `data-thumb`, `data-splide-lazy`, `data-flickity-lazyload-src`) on slide or descendant → `<a href>` to an image file.
- Owl keys on `.owl-carousel` container, never `.item`.
- New backend files: namespace `App\Service\Reader\Slideshow`, directory `backend/src/Service/Reader/Slideshow/`.
- Commit message format: `type(#926): summary`. Branch: `feature/926-reader-slideshows` (already checked out).
- After backend work, scan today's dev log: `ls -t backend/var/log/dev-*.log | head -1`.

---

### Task 1: Slide / ContainerSignature / Slideshow value objects

**Files:**
- Create: `backend/src/Service/Reader/Slideshow/Slide.php`
- Create: `backend/src/Service/Reader/Slideshow/ContainerSignature.php`
- Create: `backend/src/Service/Reader/Slideshow/Slideshow.php`
- Test: `backend/tests/Service/Reader/Slideshow/SlideshowTest.php`

**Interfaces:**
- Produces: `Slide(string $imageUrl, string $alt)`; `ContainerSignature::fromClassAttribute(?string): ?self` + `matches(Element): bool`; `Slideshow::fromSlides(list<Slide> $slides, ?string $title, ?string $precedingText, ?ContainerSignature $container): ?self` (returns null below 2 slides) with public readonly `$slides`, `$title`, `$precedingText`, `$container`.

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace App\Tests\Service\Reader\Slideshow;

use App\Service\Reader\Slideshow\Slide;
use App\Service\Reader\Slideshow\Slideshow;
use PHPUnit\Framework\TestCase;

final class SlideshowTest extends TestCase
{
    public function testTwoSlidesProduceASlideshow(): void
    {
        $show = Slideshow::fromSlides(
            [new Slide('https://img/1.jpg', 'one'), new Slide('https://img/2.jpg', 'two')],
            'Gallery',
            'Some preceding paragraph text that is long enough.',
            null,
        );

        self::assertNotNull($show);
        self::assertCount(2, $show->slides);
        self::assertSame('Gallery', $show->title);
    }

    public function testOneSlideIsRejected(): void
    {
        self::assertNull(Slideshow::fromSlides([new Slide('https://img/1.jpg', 'one')], null, null, null));
    }

    public function testEmptyIsRejected(): void
    {
        self::assertNull(Slideshow::fromSlides([], null, null, null));
    }
}
```

- [ ] **Step 2: Run it, verify it fails**

Run: `cd backend && php bin/phpunit tests/Service/Reader/Slideshow/SlideshowTest.php`
Expected: FAIL — classes do not exist.

- [ ] **Step 3: Implement the three value objects**

`Slide.php`:

```php
<?php

declare(strict_types=1);

namespace App\Service\Reader\Slideshow;

final readonly class Slide
{
    public function __construct(
        public string $imageUrl,
        public string $alt,
    ) {
    }
}
```

`ContainerSignature.php`:

```php
<?php

declare(strict_types=1);

namespace App\Service\Reader\Slideshow;

use Dom\Element;

/**
 * The class tokens of the original carousel element, so the inserter can find
 * and remove it from the cleaned body when it survived extraction — otherwise
 * the recreated slideshow would sit beside the publisher's broken original.
 */
final readonly class ContainerSignature
{
    /** @param non-empty-list<string> $classTokens */
    private function __construct(private array $classTokens)
    {
    }

    public static function fromClassAttribute(?string $classAttribute): ?self
    {
        $tokens = array_values(array_filter(explode(' ', $classAttribute ?? ''), static fn (string $t): bool => $t !== ''));

        return $tokens === [] ? null : new self($tokens);
    }

    public function matches(Element $element): bool
    {
        $present = array_filter(explode(' ', $element->getAttribute('class') ?? ''));
        foreach ($this->classTokens as $token) {
            if (!in_array($token, $present, true)) {
                return false;
            }
        }

        return true;
    }
}
```

`Slideshow.php`:

```php
<?php

declare(strict_types=1);

namespace App\Service\Reader\Slideshow;

final readonly class Slideshow
{
    /** @param list<Slide> $slides */
    private function __construct(
        public array $slides,
        public ?string $title,
        public ?string $precedingText,
        public ?ContainerSignature $container,
    ) {
    }

    /**
     * The two-slide floor lives here so every recognizer inherits it: a lone
     * image is not a slideshow.
     *
     * @param list<Slide> $slides
     */
    public static function fromSlides(
        array $slides,
        ?string $title,
        ?string $precedingText,
        ?ContainerSignature $container,
    ): ?self {
        if (count($slides) < 2) {
            return null;
        }

        return new self(array_values($slides), $title, $precedingText, $container);
    }
}
```

- [ ] **Step 4: Run tests, verify pass**

Run: `cd backend && php bin/phpunit tests/Service/Reader/Slideshow/SlideshowTest.php`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add backend/src/Service/Reader/Slideshow backend/tests/Service/Reader/Slideshow
git commit -m "feat(#926): slideshow value objects with the two-slide guard"
```

---

### Task 2: SlideshowMarkup builds the sanitizer-safe figure

**Files:**
- Create: `backend/src/Service/Reader/Slideshow/SlideshowMarkup.php`
- Test: `backend/tests/Service/Reader/Slideshow/SlideshowMarkupTest.php`

**Interfaces:**
- Consumes: `Slide`, `Slideshow` (Task 1).
- Produces: `SlideshowMarkup::figureFor(HTMLDocument $document, Slideshow $slideshow): Element` — a `<figure class="reader-slideshow">` with an optional leading `<figcaption>` (the title) and an `<ol>` of `<li><img></li>`, first `<img>` `loading="eager"`, the rest `loading="lazy"`.

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace App\Tests\Service\Reader\Slideshow;

use App\Service\Html\HtmlDocumentParser;
use App\Service\Reader\Slideshow\Slide;
use App\Service\Reader\Slideshow\Slideshow;
use App\Service\Reader\Slideshow\SlideshowMarkup;
use PHPUnit\Framework\TestCase;

final class SlideshowMarkupTest extends TestCase
{
    public function testBuildsFigureWithCaptionAndLazyImages(): void
    {
        $document = HtmlDocumentParser::parseOrNull('<body></body>');
        self::assertNotNull($document);
        $show = Slideshow::fromSlides(
            [new Slide('https://img/1.jpg', 'first chart'), new Slide('https://img/2.jpg', 'second chart')],
            'Poll gallery',
            null,
            null,
        );
        self::assertNotNull($show);

        $figure = (new SlideshowMarkup())->figureFor($document, $show);
        $document->body?->appendChild($figure);
        $html = $document->saveHtml();

        self::assertStringContainsString('<figure class="reader-slideshow">', $html);
        self::assertStringContainsString('<figcaption>Poll gallery</figcaption>', $html);
        self::assertSame(2, substr_count($html, '<li>'));
        self::assertStringContainsString('src="https://img/1.jpg" alt="first chart" loading="eager"', $html);
        self::assertStringContainsString('src="https://img/2.jpg" alt="second chart" loading="lazy"', $html);
    }

    public function testOmitsCaptionWhenNoTitle(): void
    {
        $document = HtmlDocumentParser::parseOrNull('<body></body>');
        self::assertNotNull($document);
        $show = Slideshow::fromSlides(
            [new Slide('https://img/1.jpg', 'a'), new Slide('https://img/2.jpg', 'b')],
            null,
            null,
            null,
        );
        self::assertNotNull($show);

        $figure = (new SlideshowMarkup())->figureFor($document, $show);
        $document->body?->appendChild($figure);

        self::assertStringNotContainsString('<figcaption>', $document->saveHtml());
    }
}
```

- [ ] **Step 2: Run it, verify it fails**

Run: `cd backend && php bin/phpunit tests/Service/Reader/Slideshow/SlideshowMarkupTest.php`
Expected: FAIL — `SlideshowMarkup` missing.

- [ ] **Step 3: Implement**

```php
<?php

declare(strict_types=1);

namespace App\Service\Reader\Slideshow;

use Dom\Element;
use Dom\HTMLDocument;

/**
 * Builds the one markup shape every recreated slideshow uses: a
 * <figure class="reader-slideshow"> with an <ol> of single-image slides. The
 * class on the figure is the mark the client upgrades to a swipeable carousel;
 * with no JavaScript the figure reads as a captioned vertical image stack.
 *
 * Single <img> per slide, not <picture>: EntrySanitizer strips srcset and
 * <source>, so the recognizer already picked one URL per slide.
 */
final readonly class SlideshowMarkup
{
    public function figureFor(HTMLDocument $document, Slideshow $slideshow): Element
    {
        $figure = $document->createElement('figure');
        $figure->setAttribute('class', 'reader-slideshow');

        if ($slideshow->title !== null && $slideshow->title !== '') {
            $caption = $document->createElement('figcaption');
            $caption->appendChild($document->createTextNode($slideshow->title));
            $figure->appendChild($caption);
        }

        $list = $document->createElement('ol');
        foreach ($slideshow->slides as $index => $slide) {
            $list->appendChild($this->item($document, $slide, $index === 0));
        }
        $figure->appendChild($list);

        return $figure;
    }

    private function item(HTMLDocument $document, Slide $slide, bool $eager): Element
    {
        $image = $document->createElement('img');
        $image->setAttribute('src', $slide->imageUrl);
        $image->setAttribute('alt', $slide->alt);
        // The first image loads at once; the rest wait so a 24-slide gallery is
        // not 24 immediate requests.
        $image->setAttribute('loading', $eager ? 'eager' : 'lazy');

        $item = $document->createElement('li');
        $item->appendChild($image);

        return $item;
    }
}
```

- [ ] **Step 4: Run tests, verify pass**

Run: `cd backend && php bin/phpunit tests/Service/Reader/Slideshow/SlideshowMarkupTest.php`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add backend/src/Service/Reader/Slideshow/SlideshowMarkup.php backend/tests/Service/Reader/Slideshow/SlideshowMarkupTest.php
git commit -m "feat(#926): sanitizer-safe reader-slideshow markup builder"
```

---

### Task 3: Allow the reader-slideshow marker through EntrySanitizer

**Files:**
- Modify: `backend/src/Service/Sanitize/EntrySanitizer.php:39`
- Test: `backend/tests/Service/Sanitize/EntrySanitizerTest.php` (add cases)

**Interfaces:**
- Consumes: nothing new.
- Produces: after `sanitize()`, `<figure class="reader-slideshow">…<img>…</figure>` is preserved; a non-allowed attribute inside it is still stripped.

- [ ] **Step 1: Write the failing test** (append to the existing test class)

```php
    public function testKeepsTheSlideshowFigureMarker(): void
    {
        $html = '<figure class="reader-slideshow"><ol><li>'
            . '<img src="https://img/1.jpg" alt="a" loading="eager"></li>'
            . '<li><img src="https://img/2.jpg" alt="b" loading="lazy"></li></ol></figure>';

        $clean = (new EntrySanitizer())->sanitize($html);

        self::assertNotNull($clean);
        self::assertStringContainsString('class="reader-slideshow"', $clean);
        self::assertSame(2, substr_count($clean, '<img'));
    }

    public function testStripsAHostileAttributeInsideTheSlideshow(): void
    {
        $html = '<figure class="reader-slideshow" onclick="steal()"><ol>'
            . '<li><img src="https://img/1.jpg" alt="a"></li>'
            . '<li><img src="https://img/2.jpg" alt="b"></li></ol></figure>';

        $clean = (new EntrySanitizer())->sanitize($html);

        self::assertNotNull($clean);
        self::assertStringNotContainsString('onclick', $clean);
        self::assertStringContainsString('class="reader-slideshow"', $clean);
    }
```

- [ ] **Step 2: Run it, verify it fails**

Run: `cd backend && php bin/phpunit tests/Service/Sanitize/EntrySanitizerTest.php --filter Slideshow`
Expected: `testKeepsTheSlideshowFigureMarker` FAILS — `class` on `<figure>` is stripped today (allowed only on `audio`).

- [ ] **Step 3: Widen the allow-list** — change line 39:

```php
            // The narration mark on <audio> and the slideshow mark on <figure>
            // must cross this barrier; class carries no script, so no styling or
            // XSS hole opens (#903, #926).
            ->allowAttribute('class', ['audio', 'figure'])
```

- [ ] **Step 4: Run tests, verify pass**

Run: `cd backend && php bin/phpunit tests/Service/Sanitize/EntrySanitizerTest.php`
Expected: PASS (whole file — no regression on existing cases).

- [ ] **Step 5: Commit**

```bash
git add backend/src/Service/Sanitize/EntrySanitizer.php backend/tests/Service/Sanitize/EntrySanitizerTest.php
git commit -m "feat(#926): let the reader-slideshow figure marker cross the sanitizer"
```

---

### Task 4: SlideImageResolver — resolve a slide's image URL

**Files:**
- Create: `backend/src/Service/Reader/Slideshow/SlideImageResolver.php`
- Test: `backend/tests/Service/Reader/Slideshow/SlideImageResolverTest.php`

**Interfaces:**
- Produces: `SlideImageResolver::resolve(Element $slide): ?string` — the first image URL found by: descendant `<img src=http…>`; then a lazy attribute on the slide or a descendant; then an `<a href>` to an image file. Null when nothing resolves.

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace App\Tests\Service\Reader\Slideshow;

use App\Service\Html\HtmlDocumentParser;
use App\Service\Reader\Slideshow\SlideImageResolver;
use Dom\Element;
use PHPUnit\Framework\TestCase;

final class SlideImageResolverTest extends TestCase
{
    private function slide(string $inner): Element
    {
        $document = HtmlDocumentParser::parseOrNull('<body><div class="slide">' . $inner . '</div></body>');
        self::assertNotNull($document);
        $slide = $document->querySelector('.slide');
        self::assertNotNull($slide);

        return $slide;
    }

    public function testPrefersARealImgSrc(): void
    {
        $url = (new SlideImageResolver())->resolve($this->slide('<img src="https://img/1.jpg">'));
        self::assertSame('https://img/1.jpg', $url);
    }

    public function testFallsBackToDataSrc(): void
    {
        $url = (new SlideImageResolver())->resolve($this->slide('<a data-src="https://img/2.jpg">x</a>'));
        self::assertSame('https://img/2.jpg', $url);
    }

    public function testFallsBackToAnchorHrefImage(): void
    {
        $url = (new SlideImageResolver())->resolve($this->slide('<a href="https://img/3.webp">x</a>'));
        self::assertSame('https://img/3.webp', $url);
    }

    public function testNullWhenNoImage(): void
    {
        self::assertNull((new SlideImageResolver())->resolve($this->slide('<p>only text</p>')));
    }
}
```

- [ ] **Step 2: Run it, verify it fails**

Run: `cd backend && php bin/phpunit tests/Service/Reader/Slideshow/SlideImageResolverTest.php`
Expected: FAIL — class missing.

- [ ] **Step 3: Implement**

```php
<?php

declare(strict_types=1);

namespace App\Service\Reader\Slideshow;

use Dom\Element;

/**
 * Finds the one image URL a slide points at. Real galleries carry the URL in
 * several shapes: a plain <img>, a library lazy attribute (Swiper/Owl/Flickity
 * all put it on data-src), or a lightbox <a href> to the image file — probed
 * over real pages (#926). The first hit wins; a slide that resolves to nothing
 * does not count as a slide.
 */
final readonly class SlideImageResolver
{
    private const array LAZY_ATTRIBUTES = [
        'data-src', 'data-lazy-src', 'data-original', 'data-thumb',
        'data-splide-lazy', 'data-flickity-lazyload-src',
    ];

    private const string IMAGE_FILE = '/\.(?:jpe?g|png|webp|gif|avif)(?:$|\?)/i';

    public function resolve(Element $slide): ?string
    {
        return $this->fromImg($slide)
            ?? $this->fromLazyAttribute($slide)
            ?? $this->fromAnchorHref($slide);
    }

    private function fromImg(Element $slide): ?string
    {
        foreach ($slide->getElementsByTagName('img') as $image) {
            $source = $image->getAttribute('src') ?? '';
            if ($this->isRemote($source)) {
                return $source;
            }
        }

        return null;
    }

    private function fromLazyAttribute(Element $slide): ?string
    {
        foreach ($this->selfAndDescendants($slide) as $element) {
            foreach (self::LAZY_ATTRIBUTES as $attribute) {
                $value = $element->getAttribute($attribute) ?? '';
                if ($this->isRemote($value)) {
                    return $value;
                }
            }
        }

        return null;
    }

    private function fromAnchorHref(Element $slide): ?string
    {
        foreach ($slide->getElementsByTagName('a') as $anchor) {
            $href = $anchor->getAttribute('href') ?? '';
            if ($this->isRemote($href) && preg_match(self::IMAGE_FILE, $href) === 1) {
                return $href;
            }
        }

        return null;
    }

    /** @return iterable<Element> */
    private function selfAndDescendants(Element $slide): iterable
    {
        yield $slide;
        yield from $slide->getElementsByTagName('*');
    }

    private function isRemote(string $url): bool
    {
        return str_starts_with($url, 'http') || str_starts_with($url, '//');
    }
}
```

- [ ] **Step 4: Run tests, verify pass**

Run: `cd backend && php bin/phpunit tests/Service/Reader/Slideshow/SlideImageResolverTest.php`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add backend/src/Service/Reader/Slideshow/SlideImageResolver.php backend/tests/Service/Reader/Slideshow/SlideImageResolverTest.php
git commit -m "feat(#926): resolve a slide image from img, lazy attr, or anchor href"
```

---

### Task 5: MarkupCarouselRecognizer + the recognizer interface

**Files:**
- Create: `backend/src/Service/Reader/Slideshow/SlideshowRecognizerInterface.php`
- Create: `backend/src/Service/Reader/Slideshow/MarkupCarouselRecognizer.php`
- Modify: `backend/config/services.yaml` (tag the interface)
- Test: `backend/tests/Service/Reader/Slideshow/MarkupCarouselRecognizerTest.php`

**Interfaces:**
- Consumes: `SlideImageResolver` (Task 4), `PageTextBlocks` (`fromDocument`, `before`), `Slide`/`Slideshow`/`ContainerSignature` (Task 1).
- Produces: `SlideshowRecognizerInterface::recognize(HTMLDocument $document, PageTextBlocks $textBlocks): list<Slideshow>`. `MarkupCarouselRecognizer` implements it for the CSS-class libraries.

- [ ] **Step 1: Write the failing test** — fixtures use markup copied from the real pages the probe validated.

```php
<?php

declare(strict_types=1);

namespace App\Tests\Service\Reader\Slideshow;

use App\Service\Html\HtmlDocumentParser;
use App\Service\Reader\Media\PageTextBlocks;
use App\Service\Reader\Slideshow\MarkupCarouselRecognizer;
use App\Service\Reader\Slideshow\SlideImageResolver;
use Dom\HTMLDocument;
use PHPUnit\Framework\TestCase;

final class MarkupCarouselRecognizerTest extends TestCase
{
    private function recognize(string $html): array
    {
        $document = HtmlDocumentParser::parseOrNull($html);
        self::assertNotNull($document);

        return (new MarkupCarouselRecognizer(new SlideImageResolver()))
            ->recognize($document, PageTextBlocks::fromDocument($document));
    }

    public function testDetectsSwiperWithRealImages(): void
    {
        $shows = $this->recognize(
            '<body><p>An intro paragraph long enough to anchor the gallery below.</p>'
            . '<div class="swiper"><div class="swiper-wrapper">'
            . '<a class="swiper-slide" data-src="https://img/a.jpg"><img src="https://img/a.jpg" alt="A"></a>'
            . '<a class="swiper-slide" data-src="https://img/b.jpg"><img src="https://img/b.jpg" alt="B"></a>'
            . '</div></div></body>',
        );

        self::assertCount(1, $shows);
        self::assertCount(2, $shows[0]->slides);
        self::assertSame('https://img/a.jpg', $shows[0]->slides[0]->imageUrl);
        self::assertSame('An intro paragraph long enough to anchor the gallery below.', $shows[0]->precedingText);
    }

    public function testDetectsOwlByContainerNotItemClass(): void
    {
        $shows = $this->recognize(
            '<body><div class="owl-carousel owl-theme">'
            . '<a class="owl-carousel-item" data-src="https://img/1.jpg">x</a>'
            . '<a class="owl-carousel-item" data-src="https://img/2.jpg">y</a>'
            . '</div></body>',
        );

        self::assertCount(1, $shows);
        self::assertCount(2, $shows[0]->slides);
    }

    public function testDetectsFlickityByCellGroupedByParent(): void
    {
        $shows = $this->recognize(
            '<body><div class="main-carousel">'
            . '<div class="carousel-cell"><img src="https://img/1.jpg" alt="1"></div>'
            . '<div class="carousel-cell"><img src="https://img/2.jpg" alt="2"></div>'
            . '</div></body>',
        );

        self::assertCount(1, $shows);
        self::assertCount(2, $shows[0]->slides);
    }

    public function testAbstainsOnImagelessGlideFrames(): void
    {
        self::assertSame([], $this->recognize(
            '<body><div class="glide"><ul class="glide__slides">'
            . '<li class="glide__slide"><div class="frame"></div></li>'
            . '<li class="glide__slide"><div class="frame"></div></li>'
            . '</ul></div></body>',
        ));
    }

    public function testAbstainsOnASingleImageSlide(): void
    {
        self::assertSame([], $this->recognize(
            '<body><div class="swiper"><div class="swiper-wrapper">'
            . '<div class="swiper-slide"><img src="https://img/a.jpg" alt="A"></div>'
            . '</div></div></body>',
        ));
    }
}
```

- [ ] **Step 2: Run it, verify it fails**

Run: `cd backend && php bin/phpunit tests/Service/Reader/Slideshow/MarkupCarouselRecognizerTest.php`
Expected: FAIL — classes missing.

- [ ] **Step 3: Implement the interface and recognizer**

`SlideshowRecognizerInterface.php`:

```php
<?php

declare(strict_types=1);

namespace App\Service\Reader\Slideshow;

use App\Service\Reader\Media\PageTextBlocks;
use Dom\HTMLDocument;

interface SlideshowRecognizerInterface
{
    /** @return list<Slideshow> */
    public function recognize(HTMLDocument $document, PageTextBlocks $textBlocks): array;
}
```

`MarkupCarouselRecognizer.php`:

```php
<?php

declare(strict_types=1);

namespace App\Service\Reader\Slideshow;

use App\Service\Reader\Media\PageTextBlocks;
use Dom\Element;
use Dom\HTMLDocument;

/**
 * Recognizes the CSS-class carousel libraries. One config row per library, not
 * one class each: Swiper/Splide/Glide/Embla share the shape container-class ›
 * slide-class, so separate classes would only duplicate. A row with a null
 * slide class (Owl) takes the container's element children as slides; a row with
 * a null container class (Flickity, tiny-slider) groups slide-class elements by
 * their shared parent. Slick and tiny-slider are opportunistic — their classes
 * usually appear only after the browser runs the library (#926).
 */
final readonly class MarkupCarouselRecognizer implements SlideshowRecognizerInterface
{
    /** @var list<array{container: ?string, slide: ?string}> */
    private const array RULES = [
        ['container' => 'swiper', 'slide' => 'swiper-slide'],
        ['container' => 'splide', 'slide' => 'splide__slide'],
        ['container' => 'glide', 'slide' => 'glide__slide'],
        ['container' => 'embla', 'slide' => 'embla__slide'],
        ['container' => 'owl-carousel', 'slide' => null],
        ['container' => null, 'slide' => 'carousel-cell'],
        ['container' => 'slick-slider', 'slide' => 'slick-slide'],
        ['container' => null, 'slide' => 'tns-item'],
    ];

    public function __construct(private SlideImageResolver $images)
    {
    }

    public function recognize(HTMLDocument $document, PageTextBlocks $textBlocks): array
    {
        $found = [];
        foreach (self::RULES as $rule) {
            foreach ($this->containers($document, $rule) as $container) {
                $show = $this->slideshow($container, $this->slidesOf($container, $rule), $textBlocks);
                if ($show !== null) {
                    $found[] = $show;
                }
            }
        }

        return $found;
    }

    /**
     * @param array{container: ?string, slide: ?string} $rule
     *
     * @return list<Element>
     */
    private function containers(HTMLDocument $document, array $rule): array
    {
        if ($rule['container'] !== null) {
            return $this->elementsByClass($document, $rule['container']);
        }

        // Container-less libraries: each set of slide-class siblings is a carousel.
        $byParent = [];
        foreach ($document->querySelectorAll('.' . $rule['slide']) as $slide) {
            $parent = $slide->parentElement;
            if ($parent !== null) {
                $byParent[spl_object_id($parent)] = $parent;
            }
        }

        return array_values($byParent);
    }

    /**
     * @param array{container: ?string, slide: ?string} $rule
     *
     * @return list<Element>
     */
    private function slidesOf(Element $container, array $rule): array
    {
        $slides = [];
        if ($rule['slide'] === null) {
            foreach ($container->children as $child) {
                $slides[] = $child;
            }

            return $slides;
        }

        foreach ($container->querySelectorAll('.' . $rule['slide']) as $slide) {
            $slides[] = $slide;
        }

        return $slides;
    }

    /** @param list<Element> $slideElements */
    private function slideshow(Element $container, array $slideElements, PageTextBlocks $textBlocks): ?Slideshow
    {
        $slides = [];
        foreach ($slideElements as $element) {
            $url = $this->images->resolve($element);
            if ($url !== null) {
                $slides[] = new Slide($url, $element->getAttribute('title') ?? $this->altOf($element));
            }
        }

        return Slideshow::fromSlides(
            $slides,
            null,
            $textBlocks->before($container),
            ContainerSignature::fromClassAttribute($container->getAttribute('class')),
        );
    }

    private function altOf(Element $slide): string
    {
        foreach ($slide->getElementsByTagName('img') as $image) {
            $alt = $image->getAttribute('alt') ?? '';
            if ($alt !== '') {
                return $alt;
            }
        }

        return '';
    }

    /** @return list<Element> */
    private function elementsByClass(HTMLDocument $document, string $class): array
    {
        $elements = [];
        foreach ($document->querySelectorAll('.' . $class) as $element) {
            $elements[] = $element;
        }

        return $elements;
    }
}
```

- [ ] **Step 4: Tag the interface** — in `backend/config/services.yaml`, under `_instanceof:` add:

```yaml
        App\Service\Reader\Slideshow\SlideshowRecognizerInterface:
            tags: ['app.slideshow_recognizer']
```

- [ ] **Step 5: Run tests, verify pass**

Run: `cd backend && php bin/phpunit tests/Service/Reader/Slideshow/MarkupCarouselRecognizerTest.php`
Expected: PASS (all five cases — detection for swiper/owl/flickity, abstain for glide/single).

- [ ] **Step 6: Commit**

```bash
git add backend/src/Service/Reader/Slideshow backend/config/services.yaml backend/tests/Service/Reader/Slideshow/MarkupCarouselRecognizerTest.php
git commit -m "feat(#926): recognize CSS-class carousel libraries"
```

---

### Task 6: TagesschauCarouselRecognizer — decode the data-v JSON

**Files:**
- Create: `backend/src/Service/Reader/Slideshow/TagesschauCarouselRecognizer.php`
- Test: `backend/tests/Service/Reader/Slideshow/TagesschauCarouselRecognizerTest.php`
- Test fixture: `backend/tests/Fixtures/Slideshow/tagesschau-carousel.html`

**Interfaces:**
- Consumes: `SlideshowRecognizerInterface`, `PageTextBlocks`, model.
- Produces: a recognizer that reads `[data-v-type="Carousel"]`, decodes the HTML-entity-encoded `data-v` JSON (`name` → title; `images[].alttext` → alt; `images[].imageUrls.l ?? m ?? s ?? xs` → image), anchors on the preceding prose block.

- [ ] **Step 1: Create the fixture** `backend/tests/Fixtures/Slideshow/tagesschau-carousel.html` (shape copied from the real page — three slides is enough to prove decoding and the guard; entity-encode the JSON exactly as the page does):

```html
<body>
<a href="/multimedia/bilder/wahl/electionalbum-ts-ltw26st-102.html" class="copytext-galerie">
  <h2 class="copytext-galerie__headline">Die Hauptgründe für das Ergebnis in Sachsen-Anhalt</h2>
</a>
<div class="v-instance carousel__prerender-height--gallery" data-v-type="Carousel"
     data-v="{&quot;ratio&quot;:&quot;16x9&quot;,&quot;name&quot;:&quot;Die Hauptgründe für das Ergebnis in Sachsen-Anhalt&quot;,&quot;images&quot;:[{&quot;alttext&quot;:&quot;Umfrage eins&quot;,&quot;title&quot;:&quot;Umfrage eins&quot;,&quot;imageUrls&quot;:{&quot;xs&quot;:&quot;https://images.tagesschau.de/1-xs.webp?width=840&quot;,&quot;s&quot;:&quot;https://images.tagesschau.de/1-s.webp?width=960&quot;,&quot;m&quot;:&quot;https://images.tagesschau.de/1-m.webp?width=960&quot;,&quot;l&quot;:&quot;https://images.tagesschau.de/1-l.webp?width=1280&quot;}},{&quot;alttext&quot;:&quot;Umfrage zwei&quot;,&quot;title&quot;:&quot;Umfrage zwei&quot;,&quot;imageUrls&quot;:{&quot;xs&quot;:&quot;https://images.tagesschau.de/2-xs.webp?width=840&quot;,&quot;s&quot;:&quot;https://images.tagesschau.de/2-s.webp?width=960&quot;,&quot;m&quot;:&quot;https://images.tagesschau.de/2-m.webp?width=960&quot;,&quot;l&quot;:&quot;https://images.tagesschau.de/2-l.webp?width=1280&quot;}},{&quot;alttext&quot;:&quot;Umfrage drei&quot;,&quot;title&quot;:&quot;Umfrage drei&quot;,&quot;imageUrls&quot;:{&quot;xs&quot;:&quot;https://images.tagesschau.de/3-xs.webp?width=840&quot;,&quot;s&quot;:&quot;https://images.tagesschau.de/3-s.webp?width=960&quot;,&quot;m&quot;:&quot;https://images.tagesschau.de/3-m.webp?width=960&quot;,&quot;l&quot;:&quot;https://images.tagesschau.de/3-l.webp?width=1280&quot;}}]}"></div>
</body>
```

- [ ] **Step 2: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace App\Tests\Service\Reader\Slideshow;

use App\Service\Html\HtmlDocumentParser;
use App\Service\Reader\Media\PageTextBlocks;
use App\Service\Reader\Slideshow\TagesschauCarouselRecognizer;
use PHPUnit\Framework\TestCase;

final class TagesschauCarouselRecognizerTest extends TestCase
{
    public function testDecodesTheDataVGallery(): void
    {
        $html = file_get_contents(__DIR__ . '/../../../Fixtures/Slideshow/tagesschau-carousel.html');
        self::assertIsString($html);
        $document = HtmlDocumentParser::parseOrNull($html);
        self::assertNotNull($document);

        $shows = (new TagesschauCarouselRecognizer())
            ->recognize($document, PageTextBlocks::fromDocument($document));

        self::assertCount(1, $shows);
        $show = $shows[0];
        self::assertCount(3, $show->slides);
        self::assertSame('Die Hauptgründe für das Ergebnis in Sachsen-Anhalt', $show->title);
        self::assertSame('https://images.tagesschau.de/1-l.webp?width=1280', $show->slides[0]->imageUrl);
        self::assertSame('Umfrage eins', $show->slides[0]->alt);
    }
}
```

- [ ] **Step 3: Run it, verify it fails**

Run: `cd backend && php bin/phpunit tests/Service/Reader/Slideshow/TagesschauCarouselRecognizerTest.php`
Expected: FAIL — class missing.

- [ ] **Step 4: Implement**

```php
<?php

declare(strict_types=1);

namespace App\Service\Reader\Slideshow;

use App\Service\Reader\Media\PageTextBlocks;
use Dom\Element;
use Dom\HTMLDocument;

/**
 * Recognizes a tagesschau "Bildergalerie": a <div data-v-type="Carousel"> whose
 * slides live only in an HTML-entity-encoded JSON blob on its data-v attribute
 * (the site's JavaScript hydrates them, so our fetch and Readability see no
 * <img> at all, #926). Reads the top-level name as the title and each image's
 * alttext and largest rendition.
 */
final readonly class TagesschauCarouselRecognizer implements SlideshowRecognizerInterface
{
    /** Widest first: pick the largest rendition the sanitizer will keep as a bare src. */
    private const array RENDITIONS = ['l', 'm', 's', 'xs'];

    public function recognize(HTMLDocument $document, PageTextBlocks $textBlocks): array
    {
        $found = [];
        foreach ($document->querySelectorAll('[data-v-type="Carousel"]') as $carousel) {
            $show = $this->fromCarousel($carousel, $textBlocks);
            if ($show !== null) {
                $found[] = $show;
            }
        }

        return $found;
    }

    private function fromCarousel(Element $carousel, PageTextBlocks $textBlocks): ?Slideshow
    {
        $data = json_decode($carousel->getAttribute('data-v') ?? '', true);
        if (!is_array($data) || !isset($data['images']) || !is_array($data['images'])) {
            return null;
        }

        $slides = [];
        foreach ($data['images'] as $image) {
            $slide = $this->slide($image);
            if ($slide !== null) {
                $slides[] = $slide;
            }
        }

        return Slideshow::fromSlides(
            $slides,
            is_string($data['name'] ?? null) ? $data['name'] : null,
            $textBlocks->before($carousel),
            ContainerSignature::fromClassAttribute($carousel->getAttribute('class')),
        );
    }

    /** @param mixed $image */
    private function slide($image): ?Slide
    {
        if (!is_array($image) || !is_array($image['imageUrls'] ?? null)) {
            return null;
        }

        foreach (self::RENDITIONS as $size) {
            $url = $image['imageUrls'][$size] ?? null;
            if (is_string($url) && $url !== '') {
                return new Slide($url, is_string($image['alttext'] ?? null) ? $image['alttext'] : '');
            }
        }

        return null;
    }
}
```

- [ ] **Step 5: Run tests, verify pass**

Run: `cd backend && php bin/phpunit tests/Service/Reader/Slideshow/TagesschauCarouselRecognizerTest.php`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add backend/src/Service/Reader/Slideshow/TagesschauCarouselRecognizer.php backend/tests/Service/Reader/Slideshow/TagesschauCarouselRecognizerTest.php backend/tests/Fixtures/Slideshow/tagesschau-carousel.html
git commit -m "feat(#926): recognize the tagesschau data-v Bildergalerie"
```

---

### Task 7: SlideshowScanner runs the recognizer locator

**Files:**
- Create: `backend/src/Service/Reader/Slideshow/SlideshowScanner.php`
- Test: `backend/tests/Service/Reader/Slideshow/SlideshowScannerTest.php`

**Interfaces:**
- Consumes: the `app.slideshow_recognizer` tagged iterator, `PageTextBlocks`.
- Produces: `SlideshowScanner::scan(HTMLDocument $document): list<Slideshow>` — builds `PageTextBlocks` once and concatenates each recognizer's result.

- [ ] **Step 1: Write the failing test** (inject both real recognizers directly, no container)

```php
<?php

declare(strict_types=1);

namespace App\Tests\Service\Reader\Slideshow;

use App\Service\Html\HtmlDocumentParser;
use App\Service\Reader\Slideshow\MarkupCarouselRecognizer;
use App\Service\Reader\Slideshow\SlideImageResolver;
use App\Service\Reader\Slideshow\SlideshowScanner;
use App\Service\Reader\Slideshow\TagesschauCarouselRecognizer;
use PHPUnit\Framework\TestCase;

final class SlideshowScannerTest extends TestCase
{
    public function testScanCollectsFromEveryRecognizer(): void
    {
        $scanner = new SlideshowScanner([
            new MarkupCarouselRecognizer(new SlideImageResolver()),
            new TagesschauCarouselRecognizer(),
        ]);
        $document = HtmlDocumentParser::parseOrNull(
            '<body><p>A paragraph long enough to serve as the gallery anchor here.</p>'
            . '<div class="swiper"><div class="swiper-wrapper">'
            . '<div class="swiper-slide"><img src="https://img/a.jpg" alt="A"></div>'
            . '<div class="swiper-slide"><img src="https://img/b.jpg" alt="B"></div>'
            . '</div></div></body>',
        );
        self::assertNotNull($document);

        $shows = $scanner->scan($document);

        self::assertCount(1, $shows);
        self::assertCount(2, $shows[0]->slides);
    }
}
```

- [ ] **Step 2: Run it, verify it fails**

Run: `cd backend && php bin/phpunit tests/Service/Reader/Slideshow/SlideshowScannerTest.php`
Expected: FAIL — class missing.

- [ ] **Step 3: Implement**

```php
<?php

declare(strict_types=1);

namespace App\Service\Reader\Slideshow;

use App\Service\Reader\Media\PageTextBlocks;
use Dom\HTMLDocument;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

/**
 * Runs every slideshow recognizer over the raw normalized page and returns the
 * combined result. Builds the page's prose blocks once so each recognizer can
 * anchor its slideshow to the text it followed.
 */
final readonly class SlideshowScanner
{
    /** @param iterable<SlideshowRecognizerInterface> $recognizers */
    public function __construct(
        #[AutowireIterator('app.slideshow_recognizer')]
        private iterable $recognizers,
    ) {
    }

    /** @return list<Slideshow> */
    public function scan(HTMLDocument $document): array
    {
        $textBlocks = PageTextBlocks::fromDocument($document);
        $found = [];
        foreach ($this->recognizers as $recognizer) {
            foreach ($recognizer->recognize($document, $textBlocks) as $slideshow) {
                $found[] = $slideshow;
            }
        }

        return $found;
    }
}
```

- [ ] **Step 4: Run tests, verify pass**

Run: `cd backend && php bin/phpunit tests/Service/Reader/Slideshow/SlideshowScannerTest.php`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add backend/src/Service/Reader/Slideshow/SlideshowScanner.php backend/tests/Service/Reader/Slideshow/SlideshowScannerTest.php
git commit -m "feat(#926): scan a page through the slideshow recognizer locator"
```

---

### Task 8: SlideshowInserter seats blocks and removes originals

**Files:**
- Create: `backend/src/Service/Reader/Slideshow/SlideshowInserter.php`
- Test: `backend/tests/Service/Reader/Slideshow/SlideshowInserterTest.php`

**Interfaces:**
- Consumes: `SlideshowMarkup` (Task 2), `PageTextBlocks`, `Slideshow`.
- Produces: `SlideshowInserter::insert(HTMLDocument $body, list<Slideshow> $slideshows): void` — for each: remove the surviving original container (by `ContainerSignature`); insert the recreated figure after the `precedingText` block, else append at body end.

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace App\Tests\Service\Reader\Slideshow;

use App\Service\Html\HtmlDocumentParser;
use App\Service\Reader\Slideshow\ContainerSignature;
use App\Service\Reader\Slideshow\Slide;
use App\Service\Reader\Slideshow\Slideshow;
use App\Service\Reader\Slideshow\SlideshowInserter;
use App\Service\Reader\Slideshow\SlideshowMarkup;
use PHPUnit\Framework\TestCase;

final class SlideshowInserterTest extends TestCase
{
    private function slides(): array
    {
        return [new Slide('https://img/1.jpg', 'a'), new Slide('https://img/2.jpg', 'b')];
    }

    public function testSeatsAfterTheAnchorAndRemovesTheOriginal(): void
    {
        $document = HtmlDocumentParser::parseOrNull(
            '<body><p>The anchor paragraph that is comfortably past forty characters.</p>'
            . '<div class="swiper broken-original">leftover</div></body>',
        );
        self::assertNotNull($document);
        $show = Slideshow::fromSlides(
            $this->slides(),
            null,
            'The anchor paragraph that is comfortably past forty characters.',
            ContainerSignature::fromClassAttribute('swiper broken-original'),
        );
        self::assertNotNull($show);

        (new SlideshowInserter(new SlideshowMarkup()))->insert($document, [$show]);
        $html = $document->saveHtml();

        self::assertStringNotContainsString('broken-original', $html);
        self::assertStringContainsString('reader-slideshow', $html);
        // The figure follows the anchor paragraph.
        self::assertLessThan(strpos($html, 'reader-slideshow'), strpos($html, 'anchor paragraph'));
    }

    public function testAppendsAtBodyEndWhenNoAnchorSurvives(): void
    {
        $document = HtmlDocumentParser::parseOrNull('<body><p>short</p></body>');
        self::assertNotNull($document);
        $show = Slideshow::fromSlides($this->slides(), null, 'A heading that did not survive extraction here.', null);
        self::assertNotNull($show);

        (new SlideshowInserter(new SlideshowMarkup()))->insert($document, [$show]);

        self::assertStringContainsString('reader-slideshow', $document->saveHtml());
    }
}
```

- [ ] **Step 2: Run it, verify it fails**

Run: `cd backend && php bin/phpunit tests/Service/Reader/Slideshow/SlideshowInserterTest.php`
Expected: FAIL — class missing.

- [ ] **Step 3: Implement**

```php
<?php

declare(strict_types=1);

namespace App\Service\Reader\Slideshow;

use App\Service\Reader\Media\PageTextBlocks;
use Dom\Element;
use Dom\HTMLDocument;

/**
 * Places each recreated slideshow into the cleaned body. The original carousel
 * may survive extraction as a broken pile of markup, so it is removed first by
 * its class signature; the recreated figure then lands after the prose block the
 * gallery followed, or at the body's end when that anchor did not survive — it
 * is never dropped.
 */
final readonly class SlideshowInserter
{
    public function __construct(private SlideshowMarkup $markup)
    {
    }

    /** @param list<Slideshow> $slideshows */
    public function insert(HTMLDocument $body, array $slideshows): void
    {
        $root = $body->body;
        if ($root === null || $slideshows === []) {
            return;
        }

        $textBlocks = PageTextBlocks::fromDocument($body);
        foreach ($slideshows as $slideshow) {
            $this->removeOriginal($root, $slideshow->container);
            $this->seat($body, $root, $textBlocks, $slideshow);
        }
    }

    private function removeOriginal(Element $root, ?ContainerSignature $container): void
    {
        if ($container === null) {
            return;
        }
        foreach (iterator_to_array($root->getElementsByTagName('*')) as $element) {
            if ($container->matches($element)) {
                $element->remove();

                return;
            }
        }
    }

    private function seat(HTMLDocument $body, Element $root, PageTextBlocks $textBlocks, Slideshow $slideshow): void
    {
        $figure = $this->markup->figureFor($body, $slideshow);
        $anchor = $slideshow->precedingText === null ? null : $textBlocks->withText($slideshow->precedingText);
        if ($anchor === null) {
            $root->appendChild($figure);

            return;
        }

        $anchor->parentNode?->insertBefore($figure, $anchor->nextSibling);
    }
}
```

- [ ] **Step 4: Run tests, verify pass**

Run: `cd backend && php bin/phpunit tests/Service/Reader/Slideshow/SlideshowInserterTest.php`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add backend/src/Service/Reader/Slideshow/SlideshowInserter.php backend/tests/Service/Reader/Slideshow/SlideshowInserterTest.php
git commit -m "feat(#926): seat recreated slideshows and remove the originals"
```

---

### Task 9: Wire scanning into extraction and body cleaning

**Files:**
- Modify: `backend/src/Service/Reader/ArticleExtractor.php` (construct + call scanner, pass result to cleaner)
- Modify: `backend/src/Service/Reader/ReaderBodyCleaner.php` (new `$slideshows` param, call inserter)
- Test: `backend/tests/Service/Reader/Slideshow/SlideshowExtractionTest.php`
- Test fixture: reuse `backend/tests/Fixtures/Slideshow/tagesschau-carousel.html` wrapped as a full article, plus a Swiper article fixture `backend/tests/Fixtures/Slideshow/swiper-article.html`.

**Interfaces:**
- Consumes: `SlideshowScanner::scan()` (Task 7), `SlideshowInserter::insert()` (Task 8).
- Produces: `ReaderBodyCleaner::clean(..., array $slideshows = [])` — the recreated figures appear in the returned HTML.

- [ ] **Step 1: Write the failing integration test** (drives `ReaderBodyCleaner` directly with a scanned document, avoiding a network fetch)

```php
<?php

declare(strict_types=1);

namespace App\Tests\Service\Reader\Slideshow;

use App\Service\Html\HtmlDocumentParser;
use App\Service\Reader\Slideshow\MarkupCarouselRecognizer;
use App\Service\Reader\Slideshow\SlideImageResolver;
use App\Service\Reader\Slideshow\SlideshowScanner;
use App\Service\Reader\Slideshow\TagesschauCarouselRecognizer;
use App\Tests\Support\ReaderBodyCleanerFactory; // existing helper, or build the cleaner inline
use PHPUnit\Framework\TestCase;

final class SlideshowExtractionTest extends TestCase
{
    public function testTagesschauGalleryBecomesAReaderSlideshowAfterItsHeading(): void
    {
        $raw = file_get_contents(__DIR__ . '/../../../Fixtures/Slideshow/tagesschau-carousel.html');
        self::assertIsString($raw);
        $rawDocument = HtmlDocumentParser::parseOrNull($raw);
        self::assertNotNull($rawDocument);

        $scanner = new SlideshowScanner([
            new MarkupCarouselRecognizer(new SlideImageResolver()),
            new TagesschauCarouselRecognizer(),
        ]);
        $slideshows = $scanner->scan($rawDocument);

        // The cleaned "body" here is the readability output: the heading survives,
        // the attribute-only carousel div is gone.
        $body = '<h2>Die Hauptgründe für das Ergebnis in Sachsen-Anhalt</h2><p>Body text.</p>';
        $clean = ReaderBodyCleanerFactory::create()->clean(
            $body,
            ['Article title', 'Article title'],
            \App\Tests\Support\LeadImageCandidateFactory::none(),
            \App\Service\Reader\Media\ArticleMedia::empty(),
            null,
            null,
            $slideshows,
        );

        self::assertStringContainsString('reader-slideshow', $clean);
        self::assertSame(3, substr_count($clean, '<img'));
        self::assertLessThan(strpos($clean, 'reader-slideshow'), strpos($clean, 'Hauptgründe'));
    }
}
```

> Note for the implementer: build the `ReaderBodyCleaner` the way the existing `ReaderBodyCleanerTest` builds it (it constructs the collaborators directly). Reuse that construction rather than a new factory if none exists; the point of the assertions is the three `<img>` after the heading. `ArticleMedia::empty()` / `LeadImageCandidate` no-op — copy whatever the existing cleaner test uses for those arguments.

- [ ] **Step 2: Run it, verify it fails**

Run: `cd backend && php bin/phpunit tests/Service/Reader/Slideshow/SlideshowExtractionTest.php`
Expected: FAIL — `clean()` has no `$slideshows` parameter.

- [ ] **Step 3: Add the parameter and call to `ReaderBodyCleaner`**

Constructor: add `private SlideshowInserter $slideshowInserter,` to the promoted constructor.

Signature — append the parameter (keep the default so existing callers are untouched):

```php
    /**
     * @param list<string|null>                     $titleCandidates
     * @param list<\App\Service\Reader\Slideshow\Slideshow> $slideshows
     */
    public function clean(
        string $contentHtml,
        array $titleCandidates,
        LeadImageCandidate $leadImage,
        ArticleMedia $media,
        ?string $entryAuthor = null,
        ?FeedMedia $feedMedia = null,
        array $slideshows = [],
    ): string {
```

After `$this->boilerplateTrimmer->trimIn($document);` (before the media plan), insert:

```php
        // A recreated slideshow replaces the publisher's original carousel, which
        // extraction leaves as a broken pile of markup or an empty box. Runs after
        // the trimmers so a trimmer cannot drop the anchor, before media planning
        // so the plan sees the finished structure.
        $this->slideshowInserter->insert($document, $slideshows);
```

- [ ] **Step 4: Wire `ArticleExtractor`** — add `private SlideshowScanner $slideshowScanner,` to the constructor; after `$media = $this->mediaScanner->scan(...)` (line ~75) add:

```php
        $slideshows = $this->slideshowScanner->scan($normalized);
```

and pass it as the final argument to `$this->bodyCleaner->clean(...)`:

```php
        $body = $this->bodyCleaner->clean(
            $article->content,
            [$article->title, $entryTitle],
            $leadImage,
            $this->siblings->extend($media, $this->streamLocations->resolve($media), $page->html),
            $entryAuthor,
            $feedMedia,
            $slideshows,
        );
```

> `$normalized` is read-only-scanned here before Readability consumes it, the same ordering `PageImageInventory::fromDocument($normalized)` already relies on.

- [ ] **Step 5: Run tests, verify pass**

Run: `cd backend && php bin/phpunit tests/Service/Reader/Slideshow/SlideshowExtractionTest.php tests/Service/Reader/ReaderBodyCleanerTest.php`
Expected: PASS (new test green; the existing cleaner test unaffected by the defaulted parameter).

- [ ] **Step 6: Backend gate + dev log**

Run: `cd backend && composer cs:fix && composer check && composer md && bin/console cache:warmup`
Then: `ls -t backend/var/log/dev-*.log | head -1` and scan it for deprecations/errors.
Expected: all clean.

- [ ] **Step 7: Commit**

```bash
git add backend/src/Service/Reader/ArticleExtractor.php backend/src/Service/Reader/ReaderBodyCleaner.php backend/tests/Service/Reader/Slideshow backend/tests/Fixtures/Slideshow
git commit -m "feat(#926): scan for slideshows and insert them during extraction"
```

---

### Task 10: Frontend hydrateSlideshows — build the swipeable carousel

**Files:**
- Create: `frontend/src/app/reader/reader-slideshow.ts`
- Modify: `frontend/src/app/reader/reader-view/reader-view.component.ts` (import + call in the enhancement block)
- Modify: `frontend/public/i18n/en.json`, `frontend/public/i18n/de.json` (three keys)
- Test: `frontend/src/app/reader/reader-slideshow.spec.ts`

**Interfaces:**
- Consumes: sanitized markup `<figure class="reader-slideshow"><figcaption>?</figcaption><ol><li><img></li>…</ol></figure>`.
- Produces: `hydrateSlideshows(host: HTMLElement, labels: SlideshowLabels): void`, where `SlideshowLabels = { previous: string; next: string; position: (current: number, total: number) => string }`. Idempotent via a `reader-slideshow--ready` class.

- [ ] **Step 1: Write the failing test**

```ts
import { hydrateSlideshows, type SlideshowLabels } from './reader-slideshow';

const labels: SlideshowLabels = {
  previous: 'Previous',
  next: 'Next',
  position: (c, t) => `${c} / ${t}`,
};

function host(): HTMLElement {
  const el = document.createElement('div');
  el.innerHTML =
    '<figure class="reader-slideshow"><ol>' +
    '<li><img src="https://img/1.jpg" alt="one"></li>' +
    '<li><img src="https://img/2.jpg" alt="two"></li>' +
    '<li><img src="https://img/3.jpg" alt="three"></li></ol></figure>';
  return el;
}

describe('hydrateSlideshows', () => {
  it('shows the first slide and marks the figure ready', () => {
    const el = host();
    hydrateSlideshows(el, labels);
    const slides = el.querySelectorAll('.reader-slideshow li');
    expect(el.querySelector('.reader-slideshow')!.classList.contains('reader-slideshow--ready')).toBe(true);
    expect((slides[0] as HTMLElement).hidden).toBe(false);
    expect((slides[1] as HTMLElement).hidden).toBe(true);
    expect(el.textContent).toContain('1 / 3');
  });

  it('advances on the next control and wraps the counter', () => {
    const el = host();
    hydrateSlideshows(el, labels);
    el.querySelector<HTMLButtonElement>('.reader-slideshow__next')!.click();
    const slides = el.querySelectorAll('.reader-slideshow li');
    expect((slides[0] as HTMLElement).hidden).toBe(true);
    expect((slides[1] as HTMLElement).hidden).toBe(false);
    expect(el.textContent).toContain('2 / 3');
  });

  it('advances on ArrowRight', () => {
    const el = host();
    hydrateSlideshows(el, labels);
    el.querySelector<HTMLElement>('.reader-slideshow')!
      .dispatchEvent(new KeyboardEvent('keydown', { key: 'ArrowRight', bubbles: true }));
    expect(el.textContent).toContain('2 / 3');
  });

  it('is idempotent', () => {
    const el = host();
    hydrateSlideshows(el, labels);
    hydrateSlideshows(el, labels);
    expect(el.querySelectorAll('.reader-slideshow__next').length).toBe(1);
  });
});
```

- [ ] **Step 2: Run it, verify it fails**

Run: `docker compose exec -T frontend npx jest reader-slideshow`
Expected: FAIL — module missing.

- [ ] **Step 3: Implement** `frontend/src/app/reader/reader-slideshow.ts`

```ts
/**
 * Upgrades a backend-emitted `<figure class="reader-slideshow">` (a captioned
 * vertical image stack that already works with no JavaScript) into a swipeable,
 * keyboard-navigable carousel showing one slide at a time. Idempotent: a figure
 * already carrying `reader-slideshow--ready` is left alone, because the reader's
 * enhancement effect re-runs on the Reader/Original toggle.
 */
export interface SlideshowLabels {
  previous: string;
  next: string;
  position: (current: number, total: number) => string;
}

export function hydrateSlideshows(host: HTMLElement, labels: SlideshowLabels): void {
  for (const figure of Array.from(host.querySelectorAll<HTMLElement>('.reader-slideshow'))) {
    if (figure.classList.contains('reader-slideshow--ready')) continue;
    const slides = Array.from(figure.querySelectorAll<HTMLElement>('li'));
    if (slides.length < 2) continue;
    build(figure, slides, labels);
  }
}

function build(figure: HTMLElement, slides: HTMLElement[], labels: SlideshowLabels): void {
  figure.classList.add('reader-slideshow--ready');
  figure.setAttribute('aria-roledescription', 'carousel');
  figure.tabIndex = 0;

  let current = 0;
  slides.forEach((slide, index) => slide.setAttribute('aria-label', `${index + 1} / ${slides.length}`));

  const counter = document.createElement('span');
  counter.className = 'reader-slideshow__counter';
  counter.setAttribute('aria-live', 'polite');

  const show = (next: number): void => {
    current = (next + slides.length) % slides.length;
    slides.forEach((slide, index) => (slide.hidden = index !== current));
    counter.textContent = labels.position(current + 1, slides.length);
  };

  const previous = control('reader-slideshow__prev', labels.previous, () => show(current - 1));
  const next = control('reader-slideshow__next', labels.next, () => show(current + 1));

  const controls = document.createElement('div');
  controls.className = 'reader-slideshow__controls';
  controls.append(previous, counter, next);
  figure.append(controls);

  figure.addEventListener('keydown', (event) => {
    if (event.key === 'ArrowRight') show(current + 1);
    else if (event.key === 'ArrowLeft') show(current - 1);
    else return;
    event.preventDefault();
  });

  show(0);
}

function control(className: string, label: string, onClick: () => void): HTMLButtonElement {
  const button = document.createElement('button');
  button.type = 'button';
  button.className = className;
  button.setAttribute('aria-label', label);
  button.addEventListener('click', onClick);
  return button;
}
```

- [ ] **Step 4: Add i18n keys** — in `frontend/public/i18n/en.json` under `"reader"`:

```json
    "slideshowPrevious": "Previous slide",
    "slideshowNext": "Next slide",
    "slideshowPosition": "{{current}} of {{total}}",
```

and in `frontend/public/i18n/de.json` under `"reader"`:

```json
    "slideshowPrevious": "Vorheriges Bild",
    "slideshowNext": "Nächstes Bild",
    "slideshowPosition": "{{current}} von {{total}}",
```

- [ ] **Step 5: Wire into the reader** — in `reader-view.component.ts`, import it:

```ts
import { hydrateSlideshows } from '../reader-slideshow';
```

and inside the `queueMicrotask` enhancement block (beside `markNarrationPlayers(host, …)`), add:

```ts
        hydrateSlideshows(host, {
          previous: this.i18n.translate('reader.slideshowPrevious'),
          next: this.i18n.translate('reader.slideshowNext'),
          position: (current, total) =>
            this.i18n.translate('reader.slideshowPosition', { current, total }),
        });
```

- [ ] **Step 6: Run tests, verify pass**

Run: `docker compose exec -T frontend npx jest reader-slideshow`
Expected: PASS.

- [ ] **Step 7: Commit**

```bash
git add frontend/src/app/reader/reader-slideshow.ts frontend/src/app/reader/reader-slideshow.spec.ts frontend/src/app/reader/reader-view/reader-view.component.ts frontend/public/i18n/en.json frontend/public/i18n/de.json
git commit -m "feat(#926): hydrate reader slideshows into swipeable carousels"
```

---

### Task 11: Swipe, lazy reveal, and carousel styles

**Files:**
- Modify: `frontend/src/app/reader/reader-slideshow.ts` (touch swipe)
- Modify: `frontend/src/app/reader/reader-slideshow.spec.ts` (swipe test)
- Modify: `frontend/src/app/reader/reader-view/reader-view.component.scss` (`.content ::ng-deep .reader-slideshow …`)

**Interfaces:**
- Consumes/produces: same `hydrateSlideshows` signature; adds horizontal-swipe advance.

- [ ] **Step 1: Write the failing swipe test** (append)

```ts
  it('advances on a leftward swipe', () => {
    const el = host();
    hydrateSlideshows(el, labels);
    const figure = el.querySelector<HTMLElement>('.reader-slideshow')!;
    const touch = (x: number) =>
      ({ changedTouches: [{ clientX: x, clientY: 0 }] }) as unknown as TouchEvent;
    figure.dispatchEvent(Object.assign(new Event('touchstart'), touch(200)));
    figure.dispatchEvent(Object.assign(new Event('touchend'), touch(120)));
    expect(el.textContent).toContain('2 / 3');
  });
```

- [ ] **Step 2: Run it, verify it fails**

Run: `docker compose exec -T frontend npx jest reader-slideshow`
Expected: FAIL — no swipe handling yet.

- [ ] **Step 3: Add swipe** — in `build()`, after the keydown listener:

```ts
  let startX = 0;
  const SWIPE_THRESHOLD = 40;
  figure.addEventListener('touchstart', (event) => {
    startX = event.changedTouches[0]?.clientX ?? 0;
  }, { passive: true });
  figure.addEventListener('touchend', (event) => {
    const deltaX = (event.changedTouches[0]?.clientX ?? 0) - startX;
    if (Math.abs(deltaX) < SWIPE_THRESHOLD) return;
    show(deltaX < 0 ? current + 1 : current - 1);
  });
```

- [ ] **Step 4: Add styles** — in `reader-view.component.scss`, near the `.reader-narration-box` rules, add (tokens only; match the existing token names in that file):

```scss
.content ::ng-deep .reader-slideshow {
  position: relative;
  margin: var(--reader-block-gap) 0;

  ol {
    list-style: none;
    margin: 0;
    padding: 0;
  }

  li img {
    display: block;
    width: 100%;
    height: auto;
  }
}

.content ::ng-deep .reader-slideshow__controls {
  display: flex;
  align-items: center;
  justify-content: center;
  gap: var(--space-3);
  margin-top: var(--space-2);
}

.content ::ng-deep .reader-slideshow__counter {
  font-variant-numeric: tabular-nums;
  color: var(--text-muted);
}
```

> The implementer must substitute the real token names used in `reader-view.component.scss` (`--space-*`, gap, muted colour). Run Stylelint — it fails the build on any hex or raw `px`.

- [ ] **Step 5: Run tests + lint, verify pass**

Run: `docker compose exec -T frontend npx jest reader-slideshow`
Then: `cd frontend && npm run check`
Expected: PASS; Stylelint clean.

- [ ] **Step 6: Commit**

```bash
git add frontend/src/app/reader/reader-slideshow.ts frontend/src/app/reader/reader-slideshow.spec.ts frontend/src/app/reader/reader-view/reader-view.component.scss
git commit -m "feat(#926): swipe and styles for reader slideshows"
```

---

### Task 12: Full verification and real-render check

**Files:** none (verification only).

- [ ] **Step 1: Backend gate (both legs)**

Run: `cd backend && composer check && composer md && php bin/phpunit`
Then MySQL leg: `docker compose exec -T php vendor/bin/phpunit`
Expected: all green.

- [ ] **Step 2: Mutation gate on the changed files**

Run: `cd backend && composer infection:diff`
Expected: score at/above `minMsi`. Kill any escaped mutant reported on the new files (collapse identical branches; assert exact slide counts and URLs, not just presence).

- [ ] **Step 3: Frontend gate**

Run: `cd frontend && npm run check`
Then in Docker: `docker compose exec -T frontend npm test`
Expected: green.

- [ ] **Step 4: Real render** — with the Docker stack up, open the reader on the motivating entry and confirm the gallery renders as a swipeable carousel with all 24 slides:

`http://localhost:4200/?view=for-you&entry=503506-landtagswahl-sachsen-anhalt-wichtigste-umfragen-im-uberblick`

Verify: one slide visible, next/prev and arrow keys advance, counter reads `1 / 24` … `24 / 24`, and with JS disabled the same figure is a captioned vertical stack. Screenshot for the PR.

- [ ] **Step 5: Scan the dev log**

Run: `ls -t backend/var/log/dev-*.log | head -1` and read it for anything new.

- [ ] **Step 6: Open the PR**

```bash
git push -u origin feature/926-reader-slideshows
gh pr create --base develop --title "Detect slideshows and recreate them as swipeable carousels (#926)" --body "Closes #926"
```

Then confirm the issue auto-closes on merge.

---

## Self-Review

**Spec coverage:**
- One raw scan / recognizers / model → Tasks 1, 4, 5, 6, 7. ✅
- Image-resolution rule (img / lazy / href) → Task 4. ✅
- Owl by container, not `.item` → Task 5 (`owl-carousel` rule, `slide => null`). ✅
- Two-image guard, enforced once → Task 1 (`Slideshow::fromSlides`), proven in Tasks 5/6. ✅
- Sanitizer-safe single-`<img>` markup + `figure` class marker → Tasks 2, 3. ✅
- Insert + remove original + anchor/append → Task 8; wired in Task 9. ✅
- Detect on raw pre-Readability document → Task 9 (`scan($normalized)`). ✅
- Client swipeable carousel, idempotent, a11y, i18n, degrade-to-stack → Tasks 10, 11. ✅
- Testing: per-recognizer fixtures from real markup, abstain cases, tagesschau anchor + append fallback, sanitizer, mutation → Tasks 3, 5, 6, 8, 9, 12. ✅
- Extensibility (row or recognizer class) → embodied in Task 5's table and Task 6's second recognizer. ✅

**Placeholder scan:** Task 9's test references how the existing `ReaderBodyCleanerTest` constructs the cleaner rather than inventing helper classes — flagged inline as "reuse the existing construction"; the implementer copies the no-op `ArticleMedia`/`LeadImageCandidate` arguments from that test. No other placeholders.

**Type consistency:** `recognize(HTMLDocument, PageTextBlocks): list<Slideshow>` is identical across the interface, both recognizers, and the scanner. `Slideshow::fromSlides(...)` argument order matches every call site. `hydrateSlideshows(host, labels)` and `SlideshowLabels` match between Tasks 10 and 11 and the reader-view call. `clean(..., array $slideshows = [])` matches the `ArticleExtractor` call in Task 9.
