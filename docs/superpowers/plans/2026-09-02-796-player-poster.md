# #796 Player poster beside the holder Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** A video found in a page attribute keeps its player when the page has no `og:image` but the player's own still stands beside the element that holds the URL.

**Architecture:** A new `PlayerPoster` value helper walks from the holding element up to three ancestors (never `body`) for the first https `<img>`. `AttributeMediaSource::bestCandidate()` uses it as the fallback after `og:image`. Everything downstream (scanner merge, inserter reconcile, frontend) is untouched.

**Tech Stack:** PHP 8.4 / Symfony 7.4, `\Dom\HTMLDocument`, PHPUnit.

**Spec:** `docs/superpowers/specs/2026-09-02-796-player-poster-design.md`

## Global Constraints

- Poster precedence in `AttributeMediaSource`: `og:image` first, then `PlayerPoster::near($holder)`, then drop (D5). A page with an `og:image` yields exactly what it yields today.
- `PlayerPoster::near()` looks inside the holder, then its parent, grandparent, great-grandparent (three levels), stops before `body`, and accepts only `src` values starting with `https://` (case-insensitive).
- Host-agnostic: no host or class name in `src/`.
- Measured baseline: over 71 files (every `backend/tests/Fixtures/**/*.html` plus the #782 survey and live pages) the rule changes the tagesschau broadcast page only. Every existing test in `tests/Service/Reader/Media` stays green without changed expectations.
- Reader cache `VERSION` +1 from the value on develop at branch time.
- Clean Code per CLAUDE.md: `final readonly`, guard clauses, every touched `src` file PHPMD-clean, comments ≤ 3 lines. Commit messages `type(#796): summary`. Run backend tests from `backend/` with `php bin/phpunit <path>`.

---

### Task 1: `PlayerPoster`

**Files:**
- Create: `backend/src/Service/Reader/Media/PlayerPoster.php`
- Create: `backend/tests/Service/Reader/Media/PlayerPosterTest.php`

**Interfaces:**
- Produces: `PlayerPoster::near(Element $holder): ?string`.

- [ ] **Step 1: Write the failing tests**

```php
<?php

declare(strict_types=1);

namespace App\Tests\Service\Reader\Media;

use App\Service\Html\HtmlDocumentParser;
use App\Service\Reader\Media\PlayerPoster;
use Dom\Element;
use PHPUnit\Framework\TestCase;

final class PlayerPosterTest extends TestCase
{
    private function holder(string $bodyHtml): Element
    {
        $document = HtmlDocumentParser::parseOrNull('<html><body>' . $bodyHtml . '</body></html>');
        self::assertNotNull($document);
        $holder = $document->querySelector('[data-v]');
        self::assertInstanceOf(Element::class, $holder);

        return $holder;
    }

    public function testTakesAnImageInsideTheHolder(): void
    {
        $holder = $this->holder('<div data-v="x"><img src="https://x.test/still.jpg"></div>');

        self::assertSame('https://x.test/still.jpg', PlayerPoster::near($holder));
    }

    /** tagesschau: the still sits in the player wrapper, one level above the element holding the URL. */
    public function testTakesAnImageBesideTheHolderInItsParent(): void
    {
        $holder = $this->holder(
            '<div class="wrapper"><picture><img src="https://x.test/still.jpg"></picture><div data-v="x"></div></div>',
        );

        self::assertSame('https://x.test/still.jpg', PlayerPoster::near($holder));
    }

    public function testReachesThreeLevelsUp(): void
    {
        $holder = $this->holder(
            '<section><img src="https://x.test/still.jpg"><div><div><div data-v="x"></div></div></div></section>',
        );

        self::assertSame('https://x.test/still.jpg', PlayerPoster::near($holder));
    }

    public function testDoesNotReachAFourthLevel(): void
    {
        $holder = $this->holder(
            '<section><img src="https://x.test/far.jpg"><div><div><div><div data-v="x"></div></div></div></div></section>',
        );

        self::assertNull(PlayerPoster::near($holder));
    }

    /** A shallow holder must not inherit the page's first picture, typically the logo. */
    public function testNeverSearchesTheBodyItself(): void
    {
        $holder = $this->holder('<img src="https://x.test/logo.svg"><div data-v="x"></div>');

        self::assertNull(PlayerPoster::near($holder));
    }

    public function testSkipsANonHttpsSource(): void
    {
        $holder = $this->holder(
            '<div data-v="x"><img src="data:image/gif;base64,R0lGOD"><img src="/relative.jpg">'
            . '<img src="https://x.test/still.jpg"></div>',
        );

        self::assertSame('https://x.test/still.jpg', PlayerPoster::near($holder));
    }

    public function testNullWhenThereIsNoImage(): void
    {
        $holder = $this->holder('<div><div data-v="x"><p>text</p></div></div>');

        self::assertNull(PlayerPoster::near($holder));
    }
}
```

- [ ] **Step 2: Run them to verify they fail**

Run: `php bin/phpunit tests/Service/Reader/Media/PlayerPosterTest.php`
Expected: error — class `PlayerPoster` not found.

- [ ] **Step 3: Write the class**

```php
<?php

declare(strict_types=1);

namespace App\Service\Reader\Media;

use Dom\Element;

/**
 * The still a player keeps beside itself: the first picture inside the element
 * holding the media URL or a near ancestor — a broadcast page has no og:image,
 * but its player wrapper draws the poster next to the player (#796).
 */
final readonly class PlayerPoster
{
    private const int ANCESTOR_LEVELS = 3;

    public static function near(Element $holder): ?string
    {
        $scope = $holder;
        for ($level = 0; $level <= self::ANCESTOR_LEVELS; $level++) {
            if (!$scope instanceof Element || $scope->localName === 'body') {
                return null;
            }
            $poster = self::firstImageIn($scope);
            if ($poster !== null) {
                return $poster;
            }
            $scope = $scope->parentNode;
        }

        return null;
    }

    private static function firstImageIn(Element $scope): ?string
    {
        foreach ($scope->querySelectorAll('img[src]') as $image) {
            $source = $image->getAttribute('src') ?? '';
            if (preg_match('#^https://#i', $source) === 1) {
                return $source;
            }
        }

        return null;
    }
}
```

`$scope->parentNode` is typed `?Node`; the `instanceof Element` guard at the loop head handles it for PHPStan. If PHPStan wants the property typed, assign through a local `?Node`.

- [ ] **Step 4: Run the tests**

Run: `php bin/phpunit tests/Service/Reader/Media/PlayerPosterTest.php`
Expected: PASS, 7 tests.

- [ ] **Step 5: Gates and commit**

Run from `backend/`: `composer cs:fix && composer check && composer md`
Expected: clean.

```bash
git add backend/src/Service/Reader/Media/PlayerPoster.php backend/tests/Service/Reader/Media/PlayerPosterTest.php
git commit -m "feat(#796): find the still a player keeps beside the element holding its URL"
```

---

### Task 2: `AttributeMediaSource` falls back to the nearby still

**Files:**
- Modify: `backend/src/Service/Reader/Media/Source/AttributeMediaSource.php:113-128` (`bestCandidate()`)
- Modify: `backend/tests/Service/Reader/Media/Source/AttributeMediaSourceTest.php:55-65` (reword one test, add two)

**Interfaces:**
- Consumes: `PlayerPoster::near()` (Task 1); `ScannedPage::posterUrl`.

- [ ] **Step 1: Write the failing tests**

Replace `testDropsAVideoWhenThePageHasNoOgImage()` with:

```php
    /** D5: a video with no poster from the page or the player would rot into a dead frame, so it is dropped. */
    public function testDropsAVideoWhenNeitherOgImageNorAStillBesideThePlayerExists(): void
    {
        $html = '<body><div data-v="https://x.test/clip.mp4"></div></body>';

        $found = $this->source->find($html, 'https://x.test/a.html');

        self::assertSame([], $found);
    }

    /** tagesschau's broadcast pages: no og:image, the still sits in the player wrapper beside the URL holder. */
    public function testTakesTheStillBesideThePlayerWhenThePageHasNoOgImage(): void
    {
        $html = '<body><div class="wrapper"><picture><img src="https://x.test/sendungsbild.jpg"></picture>'
            . '<div data-v="https://x.test/clip.mp4"></div></div></body>';

        $found = $this->source->find($html, 'https://x.test/a.html');

        self::assertCount(1, $found);
        self::assertSame(MediaKind::Video, $found[0]->kind);
        self::assertSame('https://x.test/sendungsbild.jpg', $found[0]->posterUrl);
    }

    public function testPrefersTheOgImageOverTheStillBesideThePlayer(): void
    {
        $html = '<html><head><meta property="og:image" content="https://x.test/share.jpg"></head>'
            . '<body><div><img src="https://x.test/still.jpg"><div data-v="https://x.test/clip.mp4"></div></div></body></html>';

        $found = $this->source->find($html, 'https://x.test/a.html');

        self::assertSame('https://x.test/share.jpg', $found[0]->posterUrl);
    }
```

- [ ] **Step 2: Run them to verify they fail**

Run: `php bin/phpunit tests/Service/Reader/Media/Source/AttributeMediaSourceTest.php`
Expected: `TakesTheStill…` FAILS (no candidate); the other two pass already.

- [ ] **Step 3: Change `bestCandidate()`**

```php
    /** @param array<string, Element> $origins durable url => the element holding it */
    private function bestCandidate(MediaKind $kind, array $origins, ScannedPage $page): ?MediaCandidate
    {
        $best = $this->relevance->rank(array_keys($origins), $page->url)[0];
        $precedingText = $page->blocks->before($origins[$best]);
        if ($kind === MediaKind::Audio) {
            return new MediaCandidate(MediaKind::Audio, $best, null, null, $precedingText);
        }

        // A publisher depublishes video on a schedule and the reader's cache
        // has no TTL; a poster-less video would rot into a dead frame instead
        // of a still with a failing play control, so it is dropped outright.
        $poster = $page->posterUrl ?? PlayerPoster::near($origins[$best]);

        return $poster === null ? null : new MediaCandidate($kind, $best, $poster, null, $precedingText);
    }
```

with `use App\Service\Reader\Media\PlayerPoster;`. Extend the class docblock's first paragraph by one clause: the poster comes from `og:image` or, failing that, the still beside the player.

- [ ] **Step 4: Run the media suites**

Run: `php bin/phpunit tests/Service/Reader/Media`
Expected: PASS; `HostAgnosticDiscoveryTest` unchanged (every fixture with a video has an `og:image`).

- [ ] **Step 5: Gates and commit**

Run from `backend/`: `composer cs:fix && composer check && composer md`
Expected: clean (PHPMD on `AttributeMediaSource.php`).

```bash
git add backend/src/Service/Reader/Media/Source/AttributeMediaSource.php backend/tests/Service/Reader/Media/Source/AttributeMediaSourceTest.php
git commit -m "fix(#796): a video with no og:image takes the still beside its player instead of being dropped"
```

---

### Task 3: The broadcast shape through the container and the extractor

**Files:**
- Create: `backend/tests/Fixtures/reader/media/ard-broadcast-no-og-image.html`
- Modify: `backend/tests/Service/Reader/Media/HostAgnosticDiscoveryTest.php` (append one test)
- Modify: `backend/tests/Service/Reader/ArticleExtractorTest.php` (append one test)

- [ ] **Step 1: Write the fixture**

```html
<!doctype html>
<!-- Reduced extract — media-bearing markup only. Source: https://www.tagesschau.de/tagesschau_in_einfacher_sprache/tse-1410.html. Captured 2026-09-02. No og:image on this page type; the player wrapper draws the still beside the player. -->
<html lang="de">
<head>
<meta charset="UTF-8">
<title>tagesschau in Einfacher Sprache — Reduced media fixture</title>
<meta name="twitter:card" content="summary_large_image"/>
</head>
<body>
<nav><a href="/">Startseite</a></nav>
<main>
<article>
<h1>tagesschau in Einfacher Sprache vom 02.09.2026</h1>
<div class="mediaplayer__wrapper">
  <div class="ts-picture__poster-wrapper"><picture><img class="ts-image" src="https://images.tagesschau.de/image/7aad00cd-f0bb-47c3-a805-5159b6d9a739/AAABoGLjHI4/AAABnSSvrFg/16x9-big/sendungsbild-1789662.jpg?width=1280" alt="Sendungsbild"></picture></div>
  <div class="v-instance"
    data-v="{&quot;mc&quot;:{&quot;streams&quot;:[{&quot;media&quot;:[{&quot;url&quot;:&quot;https://tagesschau-podcast.ard-mcdn.de/audio/2026/0902/TV-20260902-1804-0100.mp3&quot;,&quot;mimeType&quot;:&quot;audio/mpeg&quot;},{&quot;url&quot;:&quot;https://tagesschau-progressive.ard-mcdn.de/video/2026/0902/TV-20260902-1804-0100.webxxl.h264.mp4&quot;,&quot;mimeType&quot;:&quot;video/mp4&quot;,&quot;maxHResolutionPx&quot;:1920},{&quot;url&quot;:&quot;https://tagesschau-progressive.ard-mcdn.de/video/2026/0902/TV-20260902-1804-0100.webs.h264.mp4&quot;,&quot;mimeType&quot;:&quot;video/mp4&quot;,&quot;maxHResolutionPx&quot;:480},{&quot;url&quot;:&quot;https://adaptive.tagesschau.de/i/video/2026/0902/TV-20260902-1804-0100,.webs.h264.mp4,.webxxl.h264.mp4,.csmil/master.m3u8&quot;,&quot;mimeType&quot;:&quot;application/vnd.apple.mpegurl&quot;}]}]}}"
  ></div>
</div>
<p>Die Themen der Sendung in einfacher Sprache: Die Bundesregierung berät über den Haushalt für das kommende Jahr, und in vielen Städten beginnen die Schulen wieder nach den Sommerferien.</p>
<p>Außerdem: Das Wetter bleibt in den nächsten Tagen wechselhaft, mit Regen im Norden und Sonne im Süden des Landes.</p>
</article>
</main>
<aside><h3>Mehr Sendungen</h3><a href="/tagesschau/ts-80742.html"><img src="https://images.tagesschau.de/image/other/16x9-big/sendungsbild-other.jpg" alt=""></a></aside>
<footer>© ARD</footer>
</body>
</html>
```

- [ ] **Step 2: Write the failing tests**

Append to `HostAgnosticDiscoveryTest`:

```php
    /** tagesschau 496523: a broadcast page has no og:image; the still beside the player is the poster. */
    public function testABroadcastPageWithoutOgImageYieldsItsVideoWithThePlayersStillAndItsAudio(): void
    {
        $media = $this->scanner()->scan(
            $this->fixture('ard-broadcast-no-og-image.html'),
            'https://www.tagesschau.de/tagesschau_in_einfacher_sprache/tse-1410.html',
        );

        $kinds = array_map(static fn ($c) => $c->kind, $media->candidates);
        self::assertContains(MediaKind::Video, $kinds);
        self::assertContains(MediaKind::Audio, $kinds);
        self::assertNotContains(MediaKind::Stream, $kinds, 'the file beside the HLS master wins');
        $video = array_values(array_filter($media->candidates, static fn ($c): bool => $c->kind === MediaKind::Video))[0];
        self::assertStringContainsString('sendungsbild-1789662', (string) $video->posterUrl);
    }
```

Append to `ArticleExtractorTest` beside the other media tests:

```php
    /** tagesschau 496523: both players reach the body; the video carries the still the page drew beside it. */
    public function testABroadcastPageWithoutOgImageKeepsItsVideoBesideTheAudio(): void
    {
        $html = (string) file_get_contents(__DIR__ . '/../../Fixtures/reader/media/ard-broadcast-no-og-image.html');
        $result = $this->extractor(
            [new MockResponse($html, ['http_code' => 200])],
            ['www.tagesschau.de' => ['93.184.216.34']],
        )->extract('https://www.tagesschau.de/tagesschau_in_einfacher_sprache/tse-1410.html');

        self::assertTrue($result->ok);
        $body = (string) $result->contentHtml;
        self::assertMatchesRegularExpression(
            '#<video[^>]*src="https://tagesschau-progressive\.ard-mcdn\.de/video/2026/0902/TV-20260902-1804-0100\.[a-z]+\.h264\.mp4"[^>]*poster="https://images\.tagesschau\.de/[^"]*sendungsbild-1789662[^"]*"#',
            $body,
        );
        self::assertStringContainsString('<audio controls preload="none" src="https://tagesschau-podcast.ard-mcdn.de/audio/2026/0902/TV-20260902-1804-0100.mp3"', $body);
        self::assertStringNotContainsString('sendungsbild-other', $body);
    }
```

If the sanitizer reorders `<video>` attributes, split the regex into two `assertMatchesRegularExpression` calls (one for `src`, one for `poster`) rather than loosening either.

- [ ] **Step 3: Run them to verify the state**

Run: `php bin/phpunit tests/Service/Reader/Media/HostAgnosticDiscoveryTest.php tests/Service/Reader/ArticleExtractorTest.php --filter Broadcast`
Expected: PASS already (Tasks 1–2 did the work) — the tests exist to pin the shape through the real container and the full pipeline. If either fails, the fixture or the rule is wrong; fix the rule, not the expectation.

- [ ] **Step 4: Run the reader suites**

Run: `php bin/phpunit tests/Service/Reader`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add backend/tests/Fixtures/reader/media/ard-broadcast-no-og-image.html backend/tests/Service/Reader/Media/HostAgnosticDiscoveryTest.php backend/tests/Service/Reader/ArticleExtractorTest.php
git commit -m "test(#796): a broadcast page without og:image yields its video and audio through the container and the extractor"
```

---

### Task 4: Reader cache version

**Files:**
- Modify: `frontend/src/app/reader/reader-cache.service.ts` (the `VERSION` constant and its comment block)

- [ ] **Step 1: Bump**

Read the current value on the branch and add, in the existing comment style, above the constant (current + 1):

```ts
  // vN: v(N-1) records hold only the audio of a broadcast page whose video had
  // no og:image poster (#796).
  private static readonly VERSION = N;
```

- [ ] **Step 2: Test and gate**

Run: `docker compose exec -T frontend npx jest src/app/reader/reader-cache.service.spec.ts`
From `frontend/`: `npm run check`
Expected: PASS / clean.

- [ ] **Step 3: Commit**

```bash
git add frontend/src/app/reader/reader-cache.service.ts
git commit -m "fix(#796): bump the reader cache version so cached broadcast entries refetch"
```

---

### Task 5: Verification

- [ ] **Step 1: Backend gates and both legs**

From `backend/`: `composer check && composer md && php bin/phpunit && composer infection:diff`
From the repo root: `docker compose exec php vendor/bin/phpunit`
Expected: all green; MSI at or above `minMsi`.

- [ ] **Step 2: Corpus statement**

Run the scanner over every `backend/tests/Fixtures/**/*.html` before and after (the planner's measurement: 71 files including the #782 survey pages, one changed). State the file count and the one changed page in the PR.

- [ ] **Step 3: Refresh the stack and check live**

`docker compose restart php worker`, then reload entry 496523 in Chrome through the reader's refresh control: the video with its still above the audio. An article entry with `og:image` (any recent tagesschau `/video/…` entry) is unchanged.

- [ ] **Step 4: PR**

Branch `fix/796-player-poster-without-og-image` → `develop`, body `Closes #796` with the corpus statement and the live-check result. Note the rendition-choice observation from the spec's non-goals as a candidate follow-up issue.
