# #782 HLS Streams and Brightcove Embeds Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** A page whose only playable form is a Brightcove player page or an HLS playlist renders a player in the reader: Brightcove joins both embed allow-lists, and HLS becomes a `Stream` kind the frontend plays through hls.js where the browser has no native HLS.

**Architecture:** Backend: a `BrightcoveEmbedProvider` (auto-tagged through `EmbedProviderInterface`), `JsonLdMediaSource` passing the declared thumbnail to a poster-less embed, `MediaKind::Stream` with `isVideo()`, `MediaUrlKind` resolving `.m3u8` to it, every video-building source treating a stream like a video, `PageMediaInserter` emitting it as `<video>`, and `ArticleMedia::withoutRedundantStreams()` so a stream never doubles a file. Frontend: the Brightcove pattern in `ALLOWED`, and a new `attachHlsStreams()` pass that lazy-loads hls.js.

**Tech Stack:** PHP 8.4, PHPUnit; Angular 20, Jest, hls.js ^1.7.

**Spec:** `docs/superpowers/specs/2026-09-02-782-hls-and-brightcove-design.md`

## Global Constraints

- Branch `fix/782-hls-and-brightcove`; commit messages `type(#782): summary`; no attribution lines, no `Co-Authored-By`.
- PHP: `declare(strict_types=1)`, `final readonly class`, PSR-12, PHPStan level max, **every touched `src` file PHPMD-clean** (`composer md`). No boolean flag parameters. Comments only for a why, one line, three at most.
- Run from `backend/`: `composer cs` (autofix `composer cs:fix`), `composer stan` (after `bin/console cache:warmup --env=dev >/dev/null`), `composer md`, `php bin/phpunit <path>`.
- `VIDEO_EXTENSIONS` never gains `m3u8`; the playlist is `MediaKind::Stream`.
- The Brightcove normalised URL is exactly `https://players.brightcove.net/<digits>/<player>/index.html?videoId=<digits>`; the frontend `ALLOWED` regex accepts exactly that and nothing else. Never add a same-origin URL to `ALLOWED`.
- The `<video>` wire shape stays `<video controls preload="none" poster="…" src="…">` for files and streams alike (native iOS client).
- Frontend: run Jest inside the Docker frontend container (`docker compose exec -T frontend npx jest <path>` from the repo root). Install dependencies on the host AND in the container (`npm ci` / `docker compose exec -T frontend npm ci`) after adding hls.js. **No CSS added to `reader-view.component.scss`** (8 kB budget, 7.97 kB used).
- `grep` on this machine is ugrep; use `rg` or `grep -F`.

---

### Task 1: Brightcove as an embed provider, with the declared poster

**Files:**
- Create: `backend/src/Service/Reader/Media/Provider/BrightcoveEmbedProvider.php`
- Create: `backend/tests/Service/Reader/Media/Provider/BrightcoveEmbedProviderTest.php`
- Modify: `backend/src/Service/Reader/Media/EmbedProviderInterface.php` (docblock)
- Modify: `backend/src/Service/Reader/Media/Source/JsonLdMediaSource.php:130-134` (`toCandidate`, embed branch)
- Modify: `backend/tests/Service/Reader/Media/EmbedProvidersWiringTest.php` (one test)
- Modify: `backend/tests/Service/Reader/Media/Source/JsonLdMediaSourceTest.php` (one test)
- Create: `backend/tests/Fixtures/reader/media/aljazeera-brightcove.html`
- Modify: `backend/tests/Service/Reader/Media/HostAgnosticDiscoveryTest.php` (one test)

**Interfaces:**
- Produces: `BrightcoveEmbedProvider implements EmbedProviderInterface` — `matches`, `normalize` → `https://players.brightcove.net/<account>/<player>/index.html?videoId=<id>`, `poster` → null, `label` → `'Watch the video'`. Auto-tagged by the `_instanceof` block in `config/services.yaml`.
- Produces: an `Embed` candidate from JSON-LD carries `thumbnailUrl` as `posterUrl` when the provider has none.

- [ ] **Step 1: Write the failing tests**

`backend/tests/Service/Reader/Media/Provider/BrightcoveEmbedProviderTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Service\Reader\Media\Provider;

use App\Service\Reader\Media\Provider\BrightcoveEmbedProvider;
use PHPUnit\Framework\TestCase;

final class BrightcoveEmbedProviderTest extends TestCase
{
    private const string AL_JAZEERA =
        'https://players.brightcove.net/665003303001/6tKQRAx7lu_default/index.html?videoId=6403736850112';

    private BrightcoveEmbedProvider $provider;

    protected function setUp(): void
    {
        $this->provider = new BrightcoveEmbedProvider();
    }

    /** Al Jazeera 469835: the VideoObject's embedUrl, exactly as declared. */
    public function testNormalizesADeclaredPlayerUrlToItself(): void
    {
        self::assertTrue($this->provider->matches(self::AL_JAZEERA));
        self::assertSame(self::AL_JAZEERA, $this->provider->normalize(self::AL_JAZEERA));
    }

    public function testKeepsOnlyTheVideoIdOfTheQuery(): void
    {
        self::assertSame(
            self::AL_JAZEERA,
            $this->provider->normalize(self::AL_JAZEERA . '&autoplay=1&muted=true'),
        );
    }

    public function testKeepsThePlayerIdVerbatim(): void
    {
        $url = 'https://players.brightcove.net/123/AbC_x-1/index.html?videoId=456';

        self::assertSame($url, $this->provider->normalize($url));
    }

    public function testRejectsAPlayerUrlWithoutAVideoId(): void
    {
        self::assertFalse($this->provider->matches('https://players.brightcove.net/123/abc_default/index.html'));
    }

    public function testRejectsANonNumericVideoId(): void
    {
        self::assertFalse($this->provider->matches('https://players.brightcove.net/123/abc_default/index.html?videoId=x'));
    }

    public function testRejectsALookAlikeHost(): void
    {
        self::assertFalse($this->provider->matches('https://players.brightcove.net.evil.test/123/abc_default/index.html?videoId=1'));
        self::assertFalse($this->provider->matches('http://players.brightcove.net/123/abc_default/index.html?videoId=1'));
    }

    public function testOffersNoPosterAndAGenericLabel(): void
    {
        self::assertNull($this->provider->poster(self::AL_JAZEERA));
        self::assertSame('Watch the video', $this->provider->label());
    }
}
```

In `EmbedProvidersWiringTest`, add:

```php
    public function testBrightcoveResolvesThroughTheTaggedIterator(): void
    {
        $target = $this->providers()->resolve(
            'https://players.brightcove.net/665003303001/6tKQRAx7lu_default/index.html?videoId=6403736850112&autoplay=1'
        );

        self::assertNotNull($target);
        self::assertSame(
            'https://players.brightcove.net/665003303001/6tKQRAx7lu_default/index.html?videoId=6403736850112',
            $target->url,
        );
    }
```

In `JsonLdMediaSourceTest` (read its `setUp`/helpers first and follow them; the source is constructed with a `MediaUrlKind` and an `EmbedProviders` — give both a `new BrightcoveEmbedProvider()`), add:

```php
    /** Al Jazeera: the provider has no poster of its own, so the declared thumbnail stands in and the body image reconciles. */
    public function testAnEmbedWithoutAProviderPosterCarriesTheDeclaredThumbnail(): void
    {
        $html = '<html><head><script type="application/ld+json">{"@type":"VideoObject",'
            . '"embedUrl":"https://players.brightcove.net/665003303001/6tKQRAx7lu_default/index.html?videoId=6403736850112",'
            . '"thumbnailUrl":"https://www.aljazeera.com/wp-content/uploads/2026/08/image-1787184739.jpg?resize=1609%2C1080"}'
            . '</script></head><body></body></html>';

        $found = $this->source->find($html, 'https://www.aljazeera.com/video/x');

        self::assertCount(1, $found);
        self::assertSame(MediaKind::Embed, $found[0]->kind);
        self::assertSame('https://www.aljazeera.com/wp-content/uploads/2026/08/image-1787184739.jpg?resize=1609%2C1080', $found[0]->posterUrl);
    }
```

Fixture `backend/tests/Fixtures/reader/media/aljazeera-brightcove.html`:

```html
<!DOCTYPE html>
<html lang="en"><head><title>Harry Kane scores goal by winning Golden Shoe for the second time — Site</title>
<meta property="og:image" content="https://www.aljazeera.com/wp-content/uploads/2026/08/image-1787184739.jpg?resize=1200%2C630">
<script type="application/ld+json">{"@context":"https://schema.org","@type":"VideoObject","name":"Harry Kane scores goal by winning Golden Shoe for the second time","uploadDate":"2026-08-20T00:12:59Z","description":"English footballer Harry Kane won the title of Europe's best goalscorer.","embedUrl":"https://players.brightcove.net/665003303001/6tKQRAx7lu_default/index.html?videoId=6403736850112","duration":"PT0H0M50S","thumbnailUrl":"https://www.aljazeera.com/wp-content/uploads/2026/08/image-1787184739.jpg?resize=1609%2C1080&quality=80"}</script>
</head>
<body>
  <nav><a href="/">Home</a><a href="/video">Video</a></nav>
  <main>
    <article>
      <h1>Harry Kane scores goal by winning Golden Shoe for the second time</h1>
      <figure><img src="https://www.aljazeera.com/wp-content/uploads/2026/08/image-1787184739.jpg?resize=1609%2C1080&amp;quality=80" alt="Harry Kane holds the Golden Shoe"></figure>
      <p>English footballer Harry Kane won the title of Europe's best goalscorer, awarded the Golden Shoe for the second time, after a season in which he scored more league goals than any other player on the continent and carried his club to the title.</p>
      <p>The striker, who moved to Germany two summers ago, collected the trophy in Munich on Tuesday and said the award belonged to the whole squad, which had supplied the chances he turned into the goals that decided the race.</p>
    </article>
  </main>
  <aside><h3>More video</h3><a href="/video/other"><img src="https://www.aljazeera.com/wp-content/uploads/2026/08/other.jpg" alt=""></a></aside>
  <footer>© 2026</footer>
</body></html>
```

In `HostAgnosticDiscoveryTest`, add:

```php
    /** Al Jazeera 469835: the VideoObject offers nothing but a Brightcove player page. */
    public function testAlJazeeraYieldsItsBrightcovePlayerWithTheDeclaredPoster(): void
    {
        $media = $this->scanner()->scan(
            $this->fixture('aljazeera-brightcove.html'),
            'https://www.aljazeera.com/video/newsfeed/2026/8/20/harry-kane-scores-goal',
        );

        self::assertCount(1, $media->candidates);
        self::assertSame(MediaKind::Embed, $media->candidates[0]->kind);
        self::assertSame(
            'https://players.brightcove.net/665003303001/6tKQRAx7lu_default/index.html?videoId=6403736850112',
            $media->candidates[0]->url,
        );
        self::assertStringContainsString('image-1787184739.jpg', (string) $media->candidates[0]->posterUrl);
    }
```

- [ ] **Step 2: Run the tests to verify they fail**

Run from `backend/`: `php bin/phpunit tests/Service/Reader/Media/Provider/BrightcoveEmbedProviderTest.php tests/Service/Reader/Media/EmbedProvidersWiringTest.php tests/Service/Reader/Media/Source/JsonLdMediaSourceTest.php tests/Service/Reader/Media/HostAgnosticDiscoveryTest.php`
Expected: class not found; the wiring, JSON-LD and corpus tests fail with null / empty.

- [ ] **Step 3: Write the provider**

```php
<?php

declare(strict_types=1);

namespace App\Service\Reader\Media\Provider;

use App\Service\Reader\Media\EmbedProviderInterface;

/**
 * Brightcove's hosted player page, the shape broadcasters and newspapers
 * declare as a VideoObject's embedUrl (Al Jazeera, #782). The video id lives
 * in the query and nowhere else, so the query is reduced to it rather than
 * dropped; the player id is an embed id and is kept verbatim.
 */
final readonly class BrightcoveEmbedProvider implements EmbedProviderInterface
{
    private const string HOST = 'players.brightcove.net';
    private const string PATH_PATTERN = '#^/(\d+)/([A-Za-z0-9_-]+)/index\.html$#';
    private const string VIDEO_ID_PATTERN = '#^\d+$#';

    public function matches(string $url): bool
    {
        return $this->normalize($url) !== null;
    }

    public function normalize(string $url): ?string
    {
        $parts = parse_url($url);
        if (($parts['scheme'] ?? '') !== 'https' || strtolower($parts['host'] ?? '') !== self::HOST) {
            return null;
        }
        if (preg_match(self::PATH_PATTERN, $parts['path'] ?? '', $path) !== 1) {
            return null;
        }
        $videoId = $this->videoId($parts['query'] ?? '');

        return $videoId === null
            ? null
            : \sprintf('https://%s/%s/%s/index.html?videoId=%s', self::HOST, $path[1], $path[2], $videoId);
    }

    public function poster(string $url): ?string
    {
        return null;
    }

    public function label(): string
    {
        return 'Watch the video';
    }

    private function videoId(string $query): ?string
    {
        parse_str($query, $params);
        $videoId = $params['videoId'] ?? null;

        return \is_string($videoId) && preg_match(self::VIDEO_ID_PATTERN, $videoId) === 1 ? $videoId : null;
    }
}
```

In `EmbedProviderInterface.php`, replace the docblock sentence ` * durable embed URL. Implementations drop the entire query: that one rule
 * removes share tokens, autoplay and player chrome together.` with ` * durable embed URL. Implementations keep only what identifies the media —
 * for most hosts nothing of the query, for Brightcove the video id alone — so
 * share tokens, autoplay and player chrome never survive.`

- [ ] **Step 4: Hand the declared thumbnail to a poster-less embed**

In `JsonLdMediaSource::toCandidate()`, change the last line to:

```php
        return new MediaCandidate(
            MediaKind::Embed,
            $target->url,
            $target->posterUrl ?? $poster,
            $target->label,
            $precedingText,
        );
```

- [ ] **Step 5: Run the tests to verify they pass**

Run: `php bin/phpunit tests/Service/Reader/Media`
Expected: green.

- [ ] **Step 6: Lint**

Run: `composer cs && composer md && bin/console cache:warmup --env=dev >/dev/null && composer stan`
Expected: clean.

- [ ] **Step 7: Commit**

```bash
git add src/Service/Reader/Media/Provider/BrightcoveEmbedProvider.php tests/Service/Reader/Media/Provider/BrightcoveEmbedProviderTest.php src/Service/Reader/Media/EmbedProviderInterface.php src/Service/Reader/Media/Source/JsonLdMediaSource.php tests/Service/Reader/Media/EmbedProvidersWiringTest.php tests/Service/Reader/Media/Source/JsonLdMediaSourceTest.php tests/Fixtures/reader/media/aljazeera-brightcove.html tests/Service/Reader/Media/HostAgnosticDiscoveryTest.php
git commit -m "feat(#782): frame Brightcove player pages and give a poster-less embed the declared thumbnail"
```

---

### Task 2: HLS as a `Stream` kind that yields to files

**Files:**
- Modify: `backend/src/Service/Reader/Media/MediaKind.php`
- Modify: `backend/src/Service/Reader/Media/MediaUrlKind.php`
- Modify: `backend/src/Service/Reader/Media/Source/JsonLdMediaSource.php:117-127` (`toCandidate`, file branches)
- Modify: `backend/src/Service/Reader/Media/Source/SemanticMediaSource.php:50-90` (`candidateFor`, `usableUrl`)
- Modify: `backend/src/Service/Reader/Media/Source/AttributeMediaSource.php:116-129` (`bestCandidate`)
- Modify: `backend/src/Service/Reader/Media/Source/LinkedFileMediaSource.php:78-92` (`bestCandidate`)
- Modify: `backend/src/Service/Reader/Media/PageMediaInserter.php:136-160` (`element`, `player`)
- Modify: `backend/src/Service/Reader/Media/ArticleMedia.php`
- Modify: `backend/src/Service/Reader/Media/PageMediaScanner.php` (`scan`: apply `withoutRedundantStreams()`)
- Modify: `backend/tests/Service/Reader/Media/MediaUrlKindTest.php`, `ArticleMediaTest.php`, `PageMediaScannerTest.php`, `PageMediaInserterTest.php`, `Source/SemanticMediaSourceTest.php`, `Source/JsonLdMediaSourceTest.php`
- Create: `backend/tests/Fixtures/reader/media/zdf-hls-video.html`, `backend/tests/Fixtures/reader/media/unseen-hls-and-brightcove.html`, `backend/tests/Fixtures/reader/media/file-beside-stream.html`
- Modify: `backend/tests/Service/Reader/Media/HostAgnosticDiscoveryTest.php` (three tests)

**Interfaces:**
- Produces: `MediaKind::Stream` (`'stream'`) and `MediaKind::isVideo(): bool` (true for `Video` and `Stream`).
- Produces: `MediaUrlKind::resolve('….m3u8')` → `ResolvedMediaUrl(MediaKind::Stream, <bare url>)`.
- Produces: `ArticleMedia::withoutRedundantStreams(): self` — drops every `Stream` candidate when at least one `Video` candidate is present.
- Consumes: `PageMediaScanner::scan()` as it is after #788 (merge + re-confirm guard); the new call wraps its final `ArticleMedia`.

- [ ] **Step 1: Write the failing tests**

`MediaUrlKindTest`: replace `testRejectsAnHlsPlaylist` with

```php
    /** ZDF 491430: an HLS playlist is a stream, never a file — VIDEO_EXTENSIONS must not learn it. */
    public function testRecognisesAnHlsPlaylistAsAStreamNotAFile(): void
    {
        $resolved = $this->kind->resolve('https://www.zdfheute.de/api/video/istaf-berlin-em-stars-100.m3u8');

        self::assertNotNull($resolved);
        self::assertSame(MediaKind::Stream, $resolved->kind);
        self::assertNotSame(MediaKind::Video, $resolved->kind);
    }

    public function testATokenisedPlaylistIsStillRefused(): void
    {
        self::assertNull($this->kind->resolve('https://cdn.test/v/master.m3u8?hdnts=exp=1'));
    }
```

`ArticleMediaTest`, add:

```php
    public function testStreamsYieldToFiles(): void
    {
        $media = new ArticleMedia([
            new MediaCandidate(MediaKind::Stream, 'https://x.test/master.m3u8', 'https://x.test/p.jpg'),
            new MediaCandidate(MediaKind::Video, 'https://x.test/a.mp4', 'https://x.test/p.jpg'),
            new MediaCandidate(MediaKind::Audio, 'https://x.test/a.mp3'),
        ]);

        $kinds = array_map(static fn (MediaCandidate $c): MediaKind => $c->kind, $media->withoutRedundantStreams()->candidates);

        self::assertSame([MediaKind::Video, MediaKind::Audio], $kinds);
    }

    public function testAStreamStaysWhenNoFileIsOffered(): void
    {
        $media = new ArticleMedia([new MediaCandidate(MediaKind::Stream, 'https://x.test/master.m3u8', 'https://x.test/p.jpg')]);

        self::assertCount(1, $media->withoutRedundantStreams()->candidates);
    }

    public function testIsVideoCoversFilesAndStreams(): void
    {
        self::assertTrue(MediaKind::Video->isVideo());
        self::assertTrue(MediaKind::Stream->isVideo());
        self::assertFalse(MediaKind::Audio->isVideo());
        self::assertFalse(MediaKind::Embed->isVideo());
    }
```

`PageMediaScannerTest`, add:

```php
    /** ardmediathek: the HLS master sits beside progressive mp4s; the file plays everywhere without a library. */
    public function testAStreamNeverDoublesAFileAcrossSources(): void
    {
        $scanner = new PageMediaScanner([
            $this->source([new MediaCandidate(MediaKind::Stream, 'https://x.test/master.m3u8', 'https://x.test/p.jpg')]),
            $this->source([new MediaCandidate(MediaKind::Video, 'https://x.test/a.mp4', 'https://x.test/p.jpg')]),
        ]);

        $media = $scanner->scan('<html></html>', 'https://x.test/a');

        self::assertCount(1, $media->candidates);
        self::assertSame(MediaKind::Video, $media->candidates[0]->kind);
    }
```

`PageMediaInserterTest`, add (follow the file's `insert()` helper):

```php
    /** A stream is the same <video> a file is — Safari and AVPlayer play it natively; hls.js covers the rest. */
    public function testAStreamBecomesAVideoElementWithItsPoster(): void
    {
        $html = $this->insert(
            '<html><body><p>Teaser.</p></body></html>',
            new ArticleMedia([new MediaCandidate(MediaKind::Stream, 'https://x.test/master.m3u8', 'https://x.test/p.jpg')]),
        );

        self::assertStringContainsString('<video controls="" preload="none" src="https://x.test/master.m3u8" poster="https://x.test/p.jpg">', $html);
    }
```

(If the existing video test asserts a different attribute order, match that order.)

`SemanticMediaSourceTest`, add (follow its construction helper; `MediaUrlKind` must be the real one with a `DurableMediaUrl`):

```php
    /** A page nobody designed for: a <video> whose only source is an HLS master. */
    public function testAVideoElementWithAnHlsSourceYieldsAStream(): void
    {
        $html = '<html><body><video poster="https://cdn.test/p.jpg"><source src="https://cdn.test/v/master.m3u8" type="application/x-mpegURL"></video></body></html>';

        $found = $this->source->find($html, 'https://site.test/x');

        self::assertCount(1, $found);
        self::assertSame(MediaKind::Stream, $found[0]->kind);
        self::assertSame('https://cdn.test/v/master.m3u8', $found[0]->url);
        self::assertSame('https://cdn.test/p.jpg', $found[0]->posterUrl);
    }
```

`JsonLdMediaSourceTest`, add:

```php
    /** ZDF 491430: contentUrl is an HLS playlist, thumbnailUrl a two-entry array. */
    public function testAnHlsContentUrlYieldsAStreamWithTheFirstThumbnail(): void
    {
        $html = '<html><head><script type="application/ld+json">{"@type":"VideoObject",'
            . '"thumbnailUrl":["https://www.zdfheute.de/assets/istaf-102~1920x1080?cb=1","https://www.zdfheute.de/assets/istaf-102~314x314?cb=1"],'
            . '"contentUrl":"https://www.zdfheute.de/api/video/istaf-berlin-em-stars-100.m3u8",'
            . '"embedUrl":"https://ngp.zdf.de/miniplayer/embed/?mediaID=/zdf/nachrichten/istaf-100"}</script></head><body></body></html>';

        $found = $this->source->find($html, 'https://www.zdfheute.de/video/x.html');

        self::assertCount(1, $found);
        self::assertSame(MediaKind::Stream, $found[0]->kind);
        self::assertSame('https://www.zdfheute.de/api/video/istaf-berlin-em-stars-100.m3u8', $found[0]->url);
        self::assertSame('https://www.zdfheute.de/assets/istaf-102~1920x1080?cb=1', $found[0]->posterUrl);
    }
```

Fixtures:

`backend/tests/Fixtures/reader/media/zdf-hls-video.html`:

```html
<!DOCTYPE html>
<html lang="de"><head><title>Leichtathletik: EM-Stars glänzen auch beim ISTAF — Site</title>
<meta property="og:image" content="https://www.zdfheute.de/assets/istaf-berlin-em-stars-102~1280x720?cb=1788157716969">
<script type="application/ld+json">{"@context":"https://schema.org","@type":"VideoObject","name":"Leichtathletik: EM-Stars glänzen auch beim ISTAF","description":"Zehnkämpfer Leo Neugebauer gewinnt im Dreikampf.","thumbnailUrl":["https://www.zdfheute.de/assets/istaf-berlin-em-stars-102~1920x1080?cb=1788157716969","https://www.zdfheute.de/assets/istaf-berlin-em-stars-102~314x314?cb=1788157716969"],"uploadDate":"2026-08-31T05:30:00.000+02:00","duration":"PT0M40S","contentUrl":"https://www.zdfheute.de/api/video/istaf-berlin-em-stars-100.m3u8","embedUrl":"https://ngp.zdf.de/miniplayer/embed/?mediaID=/zdf/nachrichten/zdf-morgenmagazin/istaf-berlin-em-stars-100"}</script>
</head>
<body>
  <nav><a href="/">Start</a><a href="/video">Video</a></nav>
  <main>
    <article>
      <h1>Leichtathletik: EM-Stars glänzen auch beim ISTAF</h1>
      <picture><source srcset="https://www.zdfheute.de/assets/istaf-berlin-em-stars-102~384x216?cb=1 384w, https://www.zdfheute.de/assets/istaf-berlin-em-stars-102~768x432?cb=1 768w"><img src="https://www.zdfheute.de/assets/istaf-berlin-em-stars-102~768x432?cb=1" alt=""></picture>
      <p>Zehnkämpfer Leo Neugebauer gewinnt beim ISTAF im Dreikampf genauso wie Julian Weber im Speerwurf, und Gesa Krause siegt über 2000 Meter Hindernis sogar mit Weltjahresbestleistung, vor einem Berliner Publikum, das die Europameister eine Woche nach den Titelkämpfen noch einmal feiern wollte.</p>
      <p>Die Veranstalter sprachen von der stärksten Besetzung seit Jahren und kündigten an, das Meeting im kommenden Jahr um einen zweiten Wettkampftag zu erweitern, wenn sich die Nachfrage nach Karten so entwickelt wie in diesem Sommer.</p>
    </article>
  </main>
  <footer>© 2026</footer>
</body></html>
```

`backend/tests/Fixtures/reader/media/unseen-hls-and-brightcove.html` (a publisher nobody designed for, per #755):

```html
<!DOCTYPE html>
<html lang="en"><head><title>Two ways to play — Unseen Publisher</title></head>
<body>
  <article>
    <h1>Two ways to play</h1>
    <p>The first clip is served as an adaptive stream straight from our own CDN, the way most broadcasters do it now, with a still frame as the poster so the page does not look empty before the reader presses play.</p>
    <video controls poster="https://cdn.unseen.test/stills/clip-one.jpg"><source src="https://cdn.unseen.test/v/clip-one/master.m3u8" type="application/x-mpegURL"></video>
    <p>The second clip is hosted at Brightcove, embedded with the player page the platform hands out, autoplay and all, which the reader must strip before it frames anything.</p>
    <iframe src="https://players.brightcove.net/123456789/AbCdEf_default/index.html?videoId=987654321&autoplay=1" allow="autoplay"></iframe>
  </article>
</body></html>
```

`backend/tests/Fixtures/reader/media/file-beside-stream.html` (ardmediathek shape):

```html
<!DOCTYPE html>
<html lang="de"><head><title>Sendung — Mediathek</title>
<meta property="og:image" content="https://cdn.mediathek.test/stills/tv-2031-1200.jpg">
</head>
<body>
  <article>
    <h1>Sendung vom 1. September</h1>
    <div class="player" data-streams='{"hls":"https://adaptive.mediathek.test/i/tv/2026/0901/TV-20260901-2031-1200.mp4.csmil/master.m3u8","progressive":["https://progressive.mediathek.test/2026/0901/TV-20260901-2031-1200.hd.mp4","https://progressive.mediathek.test/2026/0901/TV-20260901-2031-1200.1080.mp4"]}'></div>
    <p>Die ganze Sendung mit allen Beiträgen des Abends, wie sie um zwanzig Uhr fünfzehn ausgestrahlt wurde, mit den Themen des Tages aus dem Norden und einem Blick auf das Wetter der kommenden Woche, das nach den Regentagen wieder trockener werden soll.</p>
  </article>
</body></html>
```

`HostAgnosticDiscoveryTest`, add:

```php
    /** ZDF 491430: contentUrl is an HLS playlist, embedUrl a first-party miniplayer nobody frames. */
    public function testZdfYieldsItsStreamWithTheDeclaredPoster(): void
    {
        $media = $this->scanner()->scan(
            $this->fixture('zdf-hls-video.html'),
            'https://www.zdfheute.de/video/zdf-morgenmagazin/istaf-berlin-em-stars-100.html',
        );

        self::assertCount(1, $media->candidates);
        self::assertSame(MediaKind::Stream, $media->candidates[0]->kind);
        self::assertSame('https://www.zdfheute.de/api/video/istaf-berlin-em-stars-100.m3u8', $media->candidates[0]->url);
        self::assertStringContainsString('1920x1080', (string) $media->candidates[0]->posterUrl);
    }

    public function testAnUnseenPublisherYieldsItsStreamAndItsBrightcovePlayerWithNoNewCode(): void
    {
        $media = $this->scanner()->scan($this->fixture('unseen-hls-and-brightcove.html'), 'https://unseen.test/two-ways');

        $byKind = [];
        foreach ($media->candidates as $candidate) {
            $byKind[$candidate->kind->value] = $candidate->url;
        }
        self::assertSame('https://cdn.unseen.test/v/clip-one/master.m3u8', $byKind['stream'] ?? null);
        self::assertSame('https://players.brightcove.net/123456789/AbCdEf_default/index.html?videoId=987654321', $byKind['embed'] ?? null);
    }

    /** ardmediathek: the HLS master sits beside progressive mp4s; the file is the one player. */
    public function testAFileBesideAStreamYieldsTheFileOnly(): void
    {
        $media = $this->scanner()->scan($this->fixture('file-beside-stream.html'), 'https://www.mediathek.test/video/tv-2031');

        $kinds = array_map(static fn ($c): MediaKind => $c->kind, $media->candidates);
        self::assertSame([MediaKind::Video], $kinds);
    }
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `php bin/phpunit tests/Service/Reader/Media`
Expected: `MediaKind::Stream` undefined; the rest fail.

- [ ] **Step 3: The kind and the resolver**

`MediaKind.php`:

```php
enum MediaKind: string
{
    case Audio = 'audio';
    case Video = 'video';
    /** An HLS playlist: a <video> for Safari and the native client, hls.js elsewhere (#782). */
    case Stream = 'stream';
    case Embed = 'embed';

    /** Plays in a <video> element, so it needs a poster against the TTL-less cache. */
    public function isVideo(): bool
    {
        return $this === self::Video || $this === self::Stream;
    }
}
```

`MediaUrlKind.php`: add `private const array STREAM_EXTENSIONS = ['m3u8'];` under `VIDEO_EXTENSIONS`, add a match arm `\in_array($extension, self::STREAM_EXTENSIONS, true) => MediaKind::Stream,` in `byExtension()`, and change the docblock sentence "is what keeps a player page, a poster image or an HLS playlist from being emitted as a file" to "is what keeps a player page or a poster image from being emitted as a file, and an HLS playlist from being emitted as anything but a Stream (#782)".

- [ ] **Step 4: Every video-building source treats a stream as a video**

- `JsonLdMediaSource::toCandidate()`: replace `if ($resolved->kind === MediaKind::Video) { … new MediaCandidate(MediaKind::Video, …) }` with `if ($resolved->kind->isVideo()) { … new MediaCandidate($resolved->kind, $resolved->url, $poster, null, $precedingText) }` (keep the D5 poster guard).
- `SemanticMediaSource`: `candidateFor()` decides `$wantsVideo = $element->nodeName === 'VIDEO'`; `usableUrl(Element $element, bool $wantsVideo)` — no: keep a non-boolean signature by returning the `ResolvedMediaUrl` instead. Rewrite the two methods as:

```php
    private function candidateFor(Element $element, ?string $precedingText): ?MediaCandidate
    {
        $resolved = $this->resolvedSourceOf($element);
        if ($resolved === null) {
            return null;
        }
        if (!$resolved->kind->isVideo()) {
            return new MediaCandidate($resolved->kind, $resolved->url, null, null, $precedingText);
        }

        // A video with no poster (absent or empty) rots into a dead frame in a cache with no TTL.
        $poster = $element->getAttribute('poster');

        return $poster === null || $poster === ''
            ? null
            : new MediaCandidate($resolved->kind, $resolved->url, $poster, null, $precedingText);
    }

    /** The element's own src or its first <source> whose kind fits the element: a <video> plays files and streams, an <audio> plays audio. */
    private function resolvedSourceOf(Element $element): ?ResolvedMediaUrl
    {
        $urls = [$element->getAttribute('src')];
        foreach ($element->querySelectorAll('source') as $source) {
            $urls[] = $source->getAttribute('src');
        }
        foreach ($urls as $url) {
            $resolved = $url === null ? null : $this->urlKind->resolve($url);
            if ($resolved !== null && $resolved->kind->isVideo() === ($element->nodeName === 'VIDEO')) {
                return $resolved;
            }
        }

        return null;
    }
```

  Delete the old `usableUrl()`. Add `use App\Service\Reader\Media\ResolvedMediaUrl;`.
- `AttributeMediaSource::bestCandidate()`: in the video branch build `new MediaCandidate($kind, $best, $page->posterUrl, null, $precedingText)` (the kind may now be `Stream`).
- `LinkedFileMediaSource::bestCandidate()`: change `if ($kind === MediaKind::Video)` to `if ($kind->isVideo())` (comment stays true: no poster to show).
- `PageMediaInserter::element()`: add the arm `MediaKind::Stream => $this->player($document, 'video', $candidate),`; in `player()`, change `$candidate->kind === MediaKind::Video && …` to `$candidate->kind->isVideo() && …`.

- [ ] **Step 5: Streams yield to files**

`ArticleMedia.php`, add after `withoutEmbeds()`:

```php
    /** A file plays everywhere without a library; a stream is the fallback for a page that offers no file. */
    public function withoutRedundantStreams(): self
    {
        if (!$this->offers(MediaKind::Video)) {
            return $this;
        }

        return new self(array_values(
            array_filter($this->candidates, static fn (MediaCandidate $c): bool => $c->kind !== MediaKind::Stream)
        ));
    }

    private function offers(MediaKind $kind): bool
    {
        foreach ($this->candidates as $candidate) {
            if ($candidate->kind === $kind) {
                return true;
            }
        }

        return false;
    }
```

`PageMediaScanner::scan()`: change the return line to

```php
        return (new ArticleMedia(\array_slice(array_values($byUrl), 0, ArticleMedia::MAX_ITEMS)))
            ->withoutRedundantStreams();
```

(The merge and the re-confirm guard from #788 stay as they are; `Stream` and `Video` are distinct kinds to the guard, which is why the file-beside-stream case needs this step and not the guard.)

- [ ] **Step 6: Run the tests**

Run: `php bin/phpunit tests/Service/Reader tests/Service/ReaderAudit`
Expected: green, including every existing corpus test.

- [ ] **Step 7: Lint**

Run: `composer cs && composer md && bin/console cache:warmup --env=dev >/dev/null && composer stan`
Expected: clean. If PHPMD flags `SemanticMediaSource`, the two methods above are already the smallest shape; report rather than inline further.

- [ ] **Step 8: Commit**

```bash
git add src/Service/Reader/Media tests/Service/Reader/Media tests/Fixtures/reader/media/zdf-hls-video.html tests/Fixtures/reader/media/unseen-hls-and-brightcove.html tests/Fixtures/reader/media/file-beside-stream.html
git commit -m "feat(#782): an HLS playlist is a Stream kind, played as a video and yielding to a file"
```

---

### Task 3: End to end through the extractor

**Files:**
- Modify: `backend/tests/Service/Reader/ArticleExtractorTest.php` (`mediaScanner()` factory + two tests)

**Interfaces:**
- Consumes: Tasks 1–2. The factory's `EmbedProviders` must include `new BrightcoveEmbedProvider()` beside YouTube (both the JSON-LD source's and `MediaUrlKind`'s), and the scanner must include `new SemanticMediaSource($urlKind)` if it does not yet (check the factory: after #788 it holds JsonLd, PageEmbed and Attribute sources).

- [ ] **Step 1: Extend the factory**

In `mediaScanner()`, build `$providers = new EmbedProviders([new YouTubeEmbedProvider(), new BrightcoveEmbedProvider()])` and use it for both `MediaUrlKind` and `JsonLdMediaSource`; keep the other sources. Add the import.

- [ ] **Step 2: Write the tests**

```php
    /** Al Jazeera 469835: the Brightcove link takes the place of the thumbnail the body already shows. */
    public function testPlacesTheBrightcovePlayerWhereTheBodyShowedItsThumbnail(): void
    {
        $html = (string) file_get_contents(__DIR__ . '/../../Fixtures/reader/media/aljazeera-brightcove.html');
        $result = $this->extractor([new MockResponse($html, ['http_code' => 200])])
            ->extract('https://www.aljazeera.com/video/newsfeed/2026/8/20/harry-kane');

        self::assertTrue($result->ok);
        $body = (string) $result->contentHtml;
        self::assertStringContainsString(
            '<a href="https://players.brightcove.net/665003303001/6tKQRAx7lu_default/index.html?videoId=6403736850112">',
            $body,
        );
        self::assertSame(1, substr_count($body, 'image-1787184739.jpg'), 'the thumbnail is the poster inside the link, not a second picture');
        self::assertLessThan((int) strpos($body, 'English footballer Harry Kane won'), (int) strpos($body, 'players.brightcove.net'));
    }

    /** ZDF 491430: the stream is a <video> with its poster, the shape Safari and AVPlayer play natively. */
    public function testEmitsAnHlsStreamAsAVideoWithItsPoster(): void
    {
        $html = (string) file_get_contents(__DIR__ . '/../../Fixtures/reader/media/zdf-hls-video.html');
        $result = $this->extractor([new MockResponse($html, ['http_code' => 200])])
            ->extract('https://www.zdfheute.de/video/zdf-morgenmagazin/istaf-berlin-em-stars-100.html');

        self::assertTrue($result->ok);
        $body = (string) $result->contentHtml;
        self::assertStringContainsString('src="https://www.zdfheute.de/api/video/istaf-berlin-em-stars-100.m3u8"', $body);
        self::assertMatchesRegularExpression('#<video[^>]*poster="https://www\.zdfheute\.de/assets/istaf-berlin-em-stars-102~1920x1080[^"]*"#', $body);
        self::assertStringNotContainsString('ngp.zdf.de', $body);
    }
```

The DNS map in `extractor()` defaults to `site.test`; pass `['www.aljazeera.com' => ['93.184.216.34']]` and `['www.zdfheute.de' => ['93.184.216.34']]` as the second argument respectively (the fetcher resolves the host before the mock answers).

- [ ] **Step 3: Run**

Run: `php bin/phpunit tests/Service/Reader/ArticleExtractorTest.php`
Expected: green. If the thumbnail reconcile does not fire (two pictures), check that the fixture's `<img src>` and the JSON-LD `thumbnailUrl` share the same path (`ImageIdentity` compares the asset, not the query) and report with the body.

Then: `php bin/phpunit`
Expected: green.

- [ ] **Step 4: Lint and commit**

Run: `composer cs && composer stan`

```bash
git add tests/Service/Reader/ArticleExtractorTest.php
git commit -m "test(#782): the Brightcove player replaces its thumbnail and the HLS stream is a video with a poster"
```

---

### Task 4: Frontend — frame Brightcove, play HLS

**Files:**
- Modify: `frontend/package.json` (+ `hls.js`), `frontend/package-lock.json`
- Modify: `frontend/src/app/reader/media-embeds.ts` (`ALLOWED`)
- Modify: `frontend/src/app/reader/media-embeds.spec.ts` (two tests)
- Create: `frontend/src/app/reader/hls-streams.ts`
- Create: `frontend/src/app/reader/hls-streams.spec.ts`
- Modify: `frontend/src/app/reader/reader-view/reader-view.component.ts:357` (call after `upgradeMediaEmbeds`)

**Interfaces:**
- Produces: `attachHlsStreams(host: HTMLElement): void`.
- Consumes: the `<video src="….m3u8" poster>` element Task 2 emits; the Brightcove link Task 1 emits.

- [ ] **Step 1: Add the dependency**

From `frontend/`: `npm install hls.js@^1.7.1 --save` (host), then from the repo root `docker compose exec -T frontend npm ci` so the container's `node_modules` match the lock file.

- [ ] **Step 2: Write the failing tests**

In `media-embeds.spec.ts`, add:

```ts
  it('replaces a Brightcove player link with a sandboxed iframe', () => {
    const el = host(
      '<a href="https://players.brightcove.net/665003303001/6tKQRAx7lu_default/index.html?videoId=6403736850112"><img src="p.jpg"></a>',
    );
    const frame = el.querySelector('iframe')!;

    expect(frame).not.toBeNull();
    expect(frame.getAttribute('src')).toBe(
      'https://players.brightcove.net/665003303001/6tKQRAx7lu_default/index.html?videoId=6403736850112',
    );
    expect(frame.getAttribute('sandbox')).toContain('allow-scripts');
  });

  it('leaves a Brightcove link that carries more than the video id', () => {
    const el = host(
      '<a href="https://players.brightcove.net/665003303001/6tKQRAx7lu_default/index.html?videoId=6403736850112&autoplay=1">x</a>',
    );

    expect(el.querySelector('iframe')).toBeNull();
  });
```

`hls-streams.spec.ts`:

```ts
import { attachHlsStreams } from './hls-streams';

const loadSource = jest.fn();
const attachMedia = jest.fn();
const startLoad = jest.fn();
const destroy = jest.fn();
let supported = true;

jest.mock('hls.js', () => ({
  __esModule: true,
  default: class {
    static isSupported = () => supported;
    loadSource = loadSource;
    attachMedia = attachMedia;
    startLoad = startLoad;
    destroy = destroy;
  },
}));

function host(html: string): HTMLElement {
  const el = document.createElement('div');
  el.innerHTML = html;
  document.body.appendChild(el);
  return el;
}

const flush = () => new Promise((r) => setTimeout(r, 0));

describe('attachHlsStreams', () => {
  beforeEach(() => {
    jest.clearAllMocks();
    supported = true;
    document.body.innerHTML = '';
    HTMLMediaElement.prototype.canPlayType = () => '';
  });

  it('attaches hls.js to an m3u8 video when the browser has no native HLS', async () => {
    const el = host('<video src="https://x.test/master.m3u8" poster="p.jpg"></video>');
    attachHlsStreams(el);
    await flush();

    expect(loadSource).toHaveBeenCalledWith('https://x.test/master.m3u8');
    expect(attachMedia).toHaveBeenCalledWith(el.querySelector('video'));
  });

  it('starts loading only on the first play, so preload="none" keeps its meaning', async () => {
    const el = host('<video src="https://x.test/master.m3u8"></video>');
    attachHlsStreams(el);
    await flush();
    expect(startLoad).not.toHaveBeenCalled();

    el.querySelector('video')!.dispatchEvent(new Event('play'));
    expect(startLoad).toHaveBeenCalledTimes(1);
  });

  it('leaves a video alone when the browser plays HLS natively', async () => {
    HTMLMediaElement.prototype.canPlayType = () => 'probably';
    attachHlsStreams(host('<video src="https://x.test/master.m3u8"></video>'));
    await flush();

    expect(attachMedia).not.toHaveBeenCalled();
  });

  it('leaves a file video alone', async () => {
    attachHlsStreams(host('<video src="https://x.test/a.mp4"></video>'));
    await flush();

    expect(attachMedia).not.toHaveBeenCalled();
  });

  it('leaves the video alone when hls.js reports no support', async () => {
    supported = false;
    attachHlsStreams(host('<video src="https://x.test/master.m3u8"></video>'));
    await flush();

    expect(attachMedia).not.toHaveBeenCalled();
  });

  it('destroys the instance of a video the re-render removed', async () => {
    const el = host('<video src="https://x.test/master.m3u8"></video>');
    attachHlsStreams(el);
    await flush();
    el.innerHTML = '<p>re-rendered</p>';
    attachHlsStreams(el);
    await flush();

    expect(destroy).toHaveBeenCalledTimes(1);
  });
});
```

- [ ] **Step 3: Run the tests to verify they fail**

Run from the repo root: `docker compose exec -T frontend npx jest src/app/reader/media-embeds.spec.ts src/app/reader/hls-streams.spec.ts`
Expected: the Brightcove upgrade test fails (no iframe); `hls-streams` module not found.

- [ ] **Step 4: The allow-list**

In `media-embeds.ts`, add to `ALLOWED`:

```ts
  /^https:\/\/players\.brightcove\.net\/\d+\/[A-Za-z0-9_-]+\/index\.html\?videoId=\d+$/,
```

- [ ] **Step 5: The HLS pass**

`frontend/src/app/reader/hls-streams.ts`:

```ts
import type Hls from 'hls.js';

/**
 * Plays an HLS stream the backend emitted as a plain `<video src="….m3u8">`.
 *
 * Safari and the native client play the playlist as it is; every other browser
 * needs hls.js, which is loaded on demand — a lazy chunk, never in the initial
 * bundle — and only for a body that carries a stream. `autoStartLoad` is off
 * and loading starts on the first play, so `preload="none"` keeps its meaning.
 *
 * Runs in the reader's post-render pass beside upgradeMediaEmbeds. A re-render
 * replaces the whole body, so instances of detached videos are destroyed first.
 */
const NATIVE_HLS = 'application/vnd.apple.mpegurl';
const PLAYLIST = /\.m3u8$/i;
const instances = new Map<HTMLVideoElement, Hls>();

export function attachHlsStreams(host: HTMLElement): void {
  destroyDetached();
  for (const video of Array.from(host.querySelectorAll('video'))) {
    const src = video.getAttribute('src') ?? '';
    if (!PLAYLIST.test(src) || video.canPlayType(NATIVE_HLS) !== '' || instances.has(video)) continue;
    void attach(video, src);
  }
}

async function attach(video: HTMLVideoElement, src: string): Promise<void> {
  const { default: HlsPlayer } = await import('hls.js');
  if (!HlsPlayer.isSupported() || !video.isConnected) return;
  const hls = new HlsPlayer({ autoStartLoad: false });
  hls.loadSource(src);
  hls.attachMedia(video);
  video.addEventListener('play', () => hls.startLoad(), { once: true });
  instances.set(video, hls);
}

function destroyDetached(): void {
  for (const [video, hls] of instances) {
    if (video.isConnected) continue;
    hls.destroy();
    instances.delete(video);
  }
}
```

In `reader-view.component.ts`, import `attachHlsStreams` from `'../hls-streams'` and call `attachHlsStreams(host);` directly after `upgradeMediaEmbeds(host);`.

- [ ] **Step 6: Run the tests, the check, and the build**

Run: `docker compose exec -T frontend npx jest src/app/reader`
Expected: green.
Run: `docker compose exec -T frontend npm run check`
Expected: green.
Run from `frontend/`: `npm run build 2>&1 | grep -E "Initial total|hls|exceeded|ERROR"`
Expected: the initial total unchanged from develop within a few kB (hls.js appears as a separate lazy chunk), no error line.

- [ ] **Step 7: Commit**

```bash
git add frontend/package.json frontend/package-lock.json frontend/src/app/reader/media-embeds.ts frontend/src/app/reader/media-embeds.spec.ts frontend/src/app/reader/hls-streams.ts frontend/src/app/reader/hls-streams.spec.ts frontend/src/app/reader/reader-view/reader-view.component.ts
git commit -m "feat(#782): frame Brightcove players and play HLS streams through hls.js where native HLS is missing"
```

---

### Task 5: Reader cache version

**Files:**
- Modify: `frontend/src/app/reader/reader-cache.service.ts` (the `VERSION` constant and its comment block)

- [ ] **Step 1: Bump**

Read the current `VERSION = N`. Add after the last `// vN:` comment and set `N + 1`:

```ts
  // v<N+1>: v<N> records hold no player for a page whose only playable form is
  // an HLS playlist or a Brightcove player (#782).
```

- [ ] **Step 2: Test and commit**

Run: `docker compose exec -T frontend npx jest src/app/reader/reader-cache`

```bash
git add frontend/src/app/reader/reader-cache.service.ts
git commit -m "fix(#782): bump the reader cache version so player-less video bodies are refetched"
```

---

### Task 6: Verification (controller-run)

- [ ] **Step 1: Backend gates**

From `backend/`: `composer check && composer md && php bin/phpunit && composer infection:diff`

- [ ] **Step 2: MySQL leg**

From the repo root: `docker compose exec -T php vendor/bin/phpunit tests/Service/Reader`

- [ ] **Step 3: Frontend**

`docker compose exec -T frontend npm run check` and, from `frontend/`, `npm run build` (initial bundle unchanged, hls.js as a lazy chunk).

- [ ] **Step 4: Live checks**

Reload each article in the browser (the reader's "Reload article" button) and confirm:
- `http://localhost:4200/?subscription=674&entry=491430-em-stars-glanzen-auch-beim-istaf` and `…&entry=489815-gedenkgottesdienste-fur-konig-harald-v`: a `<video>` with the ZDF poster; pressing play starts the stream (hls.js in Chrome/Firefox, native in Safari).
- Al Jazeera 469835 and 476079 (find the deep links in the sweep report or by entry id): a Brightcove iframe where the thumbnail stood, and the thumbnail not shown twice.
- One ardmediathek entry (e.g. 495128): still exactly one player.
Screenshots as evidence.
