# Reader media recovery (#748) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Render the audio, video and embeds the reader currently loses, in place where the position is known and at the top where it is not.

**Architecture:** Discovery reads the raw source page in `PageMediaScanner`, because `FetchedPageNormalizer::repair()` strips every `<script>` before parsing. Rendering mutates the extracted body in `ReaderBodyCleaner`, which runs before `EntrySanitizer` and therefore still sees in-body `<iframe>`s with their `src`. Embeds are emitted as links and upgraded to iframes by the Angular reader at render, so neither sanitizer is weakened.

**Tech Stack:** PHP 8.4, Symfony 7.4, `\Dom\HTMLDocument`, PHPUnit; Angular 20 with signals, Jest.

**Spec:** `docs/superpowers/specs/2026-08-31-748-reader-media-recovery-design.md`

## Global Constraints

- `declare(strict_types=1);` in every PHP file. PSR-12 (`composer cs`).
- `final readonly class` with constructor promotion is the house style. `final` unless designed for extension.
- Errors are typed exceptions in `Service/*/Exception/`. Never signal failure with `null`... **except** where this plan explicitly returns `null` for "no match", which is a value, not a failure.
- Comments: default to none. One line, three at the absolute most, and only for the *why*.
- **Do not modify `backend/src/Service/Sanitize/EntrySanitizer.php`.** It is shared with feed ingest (`Service/Ingest/EntryIngestor.php:44`). It already passes `<audio>` and `<video>` and already drops `autoplay`.
- **Fail closed everywhere.** No clean candidate means the body is untouched. Never remove content to add media.
- No signed or expiring URL in the body. The IndexedDB article cache has no TTL.
- Frontend: no hex colours and no raw `px` outside `src/app/theme/`. Component styles live in the sibling `.scss`, never inline.
- Media cap: **20** items per article (`ArticleMedia::MAX_ITEMS`).
- Do **not** reuse the alt string `Video — open the original article to watch`. `reader-view.component.scss` paints a play badge on it (#627, commit `145262d9`).
- Branch: `feature/748-reader-media-recovery`, off `develop`. Commits are `type(#748): summary`.
- Every `src` file you touch must be PHPMD-clean before commit. Run `composer check` and `composer md`.

---

### Task 1: Media value objects and the durability guard

The pure, dependency-free core. Everything later composes these.

**Files:**
- Create: `backend/src/Service/Reader/Media/MediaKind.php`
- Create: `backend/src/Service/Reader/Media/MediaCandidate.php`
- Create: `backend/src/Service/Reader/Media/ArticleMedia.php`
- Create: `backend/src/Service/Reader/Media/DurableMediaUrl.php`
- Test: `backend/tests/Service/Reader/Media/DurableMediaUrlTest.php`
- Test: `backend/tests/Service/Reader/Media/ArticleMediaTest.php`

**Interfaces:**
- Consumes: nothing.
- Produces:
  - `enum MediaKind: string { case Audio; case Video; case Embed; }`
  - `final readonly class MediaCandidate { public function __construct(public MediaKind $kind, public string $url, public ?string $posterUrl = null, public ?string $label = null) {} }`
  - `final readonly class ArticleMedia { public const int MAX_ITEMS = 20; /** @param list<MediaCandidate> $candidates */ public function __construct(public array $candidates) {} public static function none(): self; public function isEmpty(): bool; public function withoutEmbeds(): self; }`
  - `final readonly class DurableMediaUrl { public function accepts(string $url): bool; }`

- [ ] **Step 1: Write the failing test for `DurableMediaUrl`**

Create `backend/tests/Service/Reader/Media/DurableMediaUrlTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Service\Reader\Media;

use App\Service\Reader\Media\DurableMediaUrl;
use PHPUnit\Framework\TestCase;

final class DurableMediaUrlTest extends TestCase
{
    private DurableMediaUrl $guard;

    protected function setUp(): void
    {
        $this->guard = new DurableMediaUrl();
    }

    public function testAcceptsAPlainHttpsFile(): void
    {
        self::assertTrue($this->guard->accepts('https://ondemand-mp3.dradio.de/file/dradio/2026/08/a.mp3'));
    }

    public function testRejectsHttp(): void
    {
        self::assertFalse($this->guard->accepts('http://ondemand-mp3.dradio.de/a.mp3'));
    }

    /**
     * Adapters strip the query before the guard sees the URL, so anything left
     * is unexplained and may be a signature.
     */
    public function testRejectsAnyRemainingQueryString(): void
    {
        self::assertFalse($this->guard->accepts('https://cdn.example.com/a.mp3?Expires=1&Signature=x'));
        self::assertFalse($this->guard->accepts('https://cdn.example.com/a.mp3?utm_source=x'));
    }

    /** Substack's machine narration: public, unsigned, 200 audio/mpeg, and wrong. */
    public function testRejectsMachineNarration(): void
    {
        self::assertFalse($this->guard->accepts(
            'https://substack-video.s3.amazonaws.com/video_upload/post/1/tts/x-OnyxTurboMultilingualNeural.mp3'
        ));
        self::assertFalse($this->guard->accepts('https://cdn.example.com/audio/x-OnyxTurboMultilingualNeural.mp3'));
    }

    public function testRejectsALiveStream(): void
    {
        self::assertFalse($this->guard->accepts('https://st01.sslstream.dlf.de/dlf/01/128/mp3/stream.mp3'));
        self::assertFalse($this->guard->accepts('https://example.com/live/stream.mp3'));
    }

    public function testRejectsAMalformedUrl(): void
    {
        self::assertFalse($this->guard->accepts('not-a-url'));
        self::assertFalse($this->guard->accepts(''));
    }
}
```

- [ ] **Step 2: Run the test and confirm it fails**

```bash
cd backend && php bin/phpunit tests/Service/Reader/Media/DurableMediaUrlTest.php
```

Expected: FAIL — `Class "App\Service\Reader\Media\DurableMediaUrl" not found`.

- [ ] **Step 3: Write `MediaKind`, `MediaCandidate` and `DurableMediaUrl`**

`backend/src/Service/Reader/Media/MediaKind.php`:

```php
<?php

declare(strict_types=1);

namespace App\Service\Reader\Media;

enum MediaKind: string
{
    case Audio = 'audio';
    case Video = 'video';
    case Embed = 'embed';
}
```

`backend/src/Service/Reader/Media/MediaCandidate.php`:

```php
<?php

declare(strict_types=1);

namespace App\Service\Reader\Media;

/**
 * One piece of media the source page offers for this article. `posterUrl` is the
 * still a video shows before playback; `label` is the link text an embed falls
 * back to when the provider has no cheap poster.
 */
final readonly class MediaCandidate
{
    public function __construct(
        public MediaKind $kind,
        public string $url,
        public ?string $posterUrl = null,
        public ?string $label = null,
    ) {
    }
}
```

`backend/src/Service/Reader/Media/DurableMediaUrl.php`:

```php
<?php

declare(strict_types=1);

namespace App\Service\Reader\Media;

/**
 * Decides whether a media URL is safe to write into a body the client caches
 * without a TTL. A signed URL that expires would rot into a dead player, so the
 * bar is deliberately high: https, no query at all, and none of the shapes that
 * are technically reachable but belong to something other than this article.
 *
 * Adapters strip known analytics parameters before the guard runs. Whatever
 * query survives that is unexplained, so it is refused rather than guessed at.
 */
final readonly class DurableMediaUrl
{
    /** Machine narration of the article the reader is already showing. */
    private const string NARRATION_PATTERN = '#/tts/|Neural\.mp3$#i';

    /** A station stream is not this episode. */
    private const string LIVE_PATTERN = '#(^|\.)sslstream\.|/live/|/stream\.mp3$#i';

    public function accepts(string $url): bool
    {
        $parts = parse_url($url);
        if ($parts === false || !isset($parts['host'], $parts['path'])) {
            return false;
        }
        if (($parts['scheme'] ?? '') !== 'https' || isset($parts['query'])) {
            return false;
        }

        return !$this->isExcluded($parts['host'] . $parts['path']);
    }

    private function isExcluded(string $hostAndPath): bool
    {
        return preg_match(self::NARRATION_PATTERN, $hostAndPath) === 1
            || preg_match(self::LIVE_PATTERN, $hostAndPath) === 1;
    }
}
```

- [ ] **Step 4: Run the test and confirm it passes**

```bash
cd backend && php bin/phpunit tests/Service/Reader/Media/DurableMediaUrlTest.php
```

Expected: PASS, 6 tests.

- [ ] **Step 5: Write the failing test for `ArticleMedia`**

Create `backend/tests/Service/Reader/Media/ArticleMediaTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Service\Reader\Media;

use App\Service\Reader\Media\ArticleMedia;
use App\Service\Reader\Media\MediaCandidate;
use App\Service\Reader\Media\MediaKind;
use PHPUnit\Framework\TestCase;

final class ArticleMediaTest extends TestCase
{
    public function testNoneIsEmpty(): void
    {
        self::assertTrue(ArticleMedia::none()->isEmpty());
    }

    public function testCandidatesAreKept(): void
    {
        $media = new ArticleMedia([new MediaCandidate(MediaKind::Audio, 'https://x.test/a.mp3')]);

        self::assertFalse($media->isEmpty());
        self::assertCount(1, $media->candidates);
    }

    /**
     * A discovered embed is suppressed when the body already recovered one in
     * place, so the reader never shows the same video twice.
     */
    public function testWithoutEmbedsDropsOnlyEmbeds(): void
    {
        $media = new ArticleMedia([
            new MediaCandidate(MediaKind::Embed, 'https://www.youtube-nocookie.com/embed/aaaaaaaaaaa'),
            new MediaCandidate(MediaKind::Audio, 'https://x.test/a.mp3'),
            new MediaCandidate(MediaKind::Video, 'https://x.test/v.mp4', 'https://x.test/p.jpg'),
        ]);

        $kept = $media->withoutEmbeds()->candidates;

        self::assertCount(2, $kept);
        self::assertSame(MediaKind::Audio, $kept[0]->kind);
        self::assertSame(MediaKind::Video, $kept[1]->kind);
    }

    public function testMaxItemsIsTwenty(): void
    {
        self::assertSame(20, ArticleMedia::MAX_ITEMS);
    }
}
```

- [ ] **Step 6: Run it and confirm it fails**

```bash
cd backend && php bin/phpunit tests/Service/Reader/Media/ArticleMediaTest.php
```

Expected: FAIL — `ArticleMedia` not found.

- [ ] **Step 7: Write `ArticleMedia`**

`backend/src/Service/Reader/Media/ArticleMedia.php`:

```php
<?php

declare(strict_types=1);

namespace App\Service\Reader\Media;

/**
 * The media a source page offers for one article, in source order.
 *
 * The cap is a runaway guard, not an editorial choice: the largest measured
 * article carries ten embeds, and truncating that one would recreate the bug
 * this work fixes.
 */
final readonly class ArticleMedia
{
    public const int MAX_ITEMS = 20;

    /** @param list<MediaCandidate> $candidates */
    public function __construct(public array $candidates)
    {
    }

    public static function none(): self
    {
        return new self([]);
    }

    public function isEmpty(): bool
    {
        return $this->candidates === [];
    }

    public function withoutEmbeds(): self
    {
        return new self(array_values(
            array_filter($this->candidates, static fn (MediaCandidate $c): bool => $c->kind !== MediaKind::Embed)
        ));
    }
}
```

- [ ] **Step 8: Run both test files and the gates**

```bash
cd backend && php bin/phpunit tests/Service/Reader/Media/ && composer cs && composer stan && composer md
```

Expected: all PASS.

- [ ] **Step 9: Commit**

```bash
git add backend/src/Service/Reader/Media backend/tests/Service/Reader/Media
git commit -m "feat(#748): media value objects and the durable-URL guard"
```

---

### Task 2: Embed provider registry — YouTube and SoundCloud

The two providers with measured sample articles. The registry is the extension point.

**Files:**
- Create: `backend/src/Service/Reader/Media/EmbedProviderInterface.php`
- Create: `backend/src/Service/Reader/Media/EmbedTarget.php`
- Create: `backend/src/Service/Reader/Media/EmbedProviders.php`
- Create: `backend/src/Service/Reader/Media/Provider/YouTubeEmbedProvider.php`
- Create: `backend/src/Service/Reader/Media/Provider/SoundCloudEmbedProvider.php`
- Modify: `backend/config/services.yaml` (add an `_instanceof` tag)
- Test: `backend/tests/Service/Reader/Media/Provider/YouTubeEmbedProviderTest.php`
- Test: `backend/tests/Service/Reader/Media/Provider/SoundCloudEmbedProviderTest.php`
- Test: `backend/tests/Service/Reader/Media/EmbedProvidersWiringTest.php`

**Interfaces:**
- Consumes: nothing from Task 1.
- Produces:
  - `interface EmbedProviderInterface { public function matches(string $url): bool; public function normalize(string $url): ?string; public function poster(string $url): ?string; public function label(): string; }`
  - `final readonly class EmbedTarget { public function __construct(public string $url, public ?string $posterUrl, public string $label) {} }`
  - `final readonly class EmbedProviders { public function resolve(string $url): ?EmbedTarget; }`
  - Tag: `app.embed_provider`

- [ ] **Step 1: Write the failing test for the YouTube provider**

Create `backend/tests/Service/Reader/Media/Provider/YouTubeEmbedProviderTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Service\Reader\Media\Provider;

use App\Service\Reader\Media\Provider\YouTubeEmbedProvider;
use PHPUnit\Framework\TestCase;

final class YouTubeEmbedProviderTest extends TestCase
{
    private YouTubeEmbedProvider $provider;

    protected function setUp(): void
    {
        $this->provider = new YouTubeEmbedProvider();
    }

    /** The OZORA listicle's own markup: a share token in the query. */
    public function testNormalizesAnEmbedUrlAndDropsTheShareToken(): void
    {
        self::assertSame(
            'https://www.youtube-nocookie.com/embed/M1j_uRqKMKI',
            $this->provider->normalize('https://www.youtube.com/embed/M1j_uRqKMKI?si=abcdefgh')
        );
    }

    public function testNormalizesAWatchUrl(): void
    {
        self::assertSame(
            'https://www.youtube-nocookie.com/embed/M1j_uRqKMKI',
            $this->provider->normalize('https://www.youtube.com/watch?v=M1j_uRqKMKI&t=30')
        );
    }

    public function testNormalizesAShortUrl(): void
    {
        self::assertSame(
            'https://www.youtube-nocookie.com/embed/M1j_uRqKMKI',
            $this->provider->normalize('https://youtu.be/M1j_uRqKMKI')
        );
    }

    public function testAlreadyNocookieIsIdempotent(): void
    {
        $url = 'https://www.youtube-nocookie.com/embed/M1j_uRqKMKI';

        self::assertSame($url, $this->provider->normalize($url));
    }

    public function testPosterIsTheThumbnail(): void
    {
        self::assertSame(
            'https://i.ytimg.com/vi/M1j_uRqKMKI/hqdefault.jpg',
            $this->provider->poster('https://www.youtube.com/embed/M1j_uRqKMKI')
        );
    }

    public function testRejectsAnIdOfTheWrongLength(): void
    {
        self::assertFalse($this->provider->matches('https://www.youtube.com/embed/tooshort'));
        self::assertNull($this->provider->normalize('https://www.youtube.com/embed/tooshort'));
    }

    public function testRejectsAnotherHost(): void
    {
        self::assertFalse($this->provider->matches('https://www.googletagmanager.com/ns.html?id=GTM-1'));
    }

    /** A look-alike host must not pass: the check is the host, not a substring. */
    public function testRejectsALookalikeHost(): void
    {
        self::assertFalse($this->provider->matches('https://youtube.com.evil.test/embed/M1j_uRqKMKI'));
    }
}
```

- [ ] **Step 2: Run it and confirm it fails**

```bash
cd backend && php bin/phpunit tests/Service/Reader/Media/Provider/YouTubeEmbedProviderTest.php
```

Expected: FAIL — class not found.

- [ ] **Step 3: Write the interface, the target, and the YouTube provider**

`backend/src/Service/Reader/Media/EmbedProviderInterface.php`:

```php
<?php

declare(strict_types=1);

namespace App\Service\Reader\Media;

/**
 * Recognises one embed host and reduces any of its URL spellings to a single
 * durable embed URL. Implementations drop the entire query: that one rule
 * removes share tokens, autoplay and player chrome together.
 */
interface EmbedProviderInterface
{
    public function matches(string $url): bool;

    /** The canonical embed URL, or null when the URL is malformed for this host. */
    public function normalize(string $url): ?string;

    /** A still to show before playback, or null when the host offers none cheaply. */
    public function poster(string $url): ?string;

    /** Link text used when there is no poster. */
    public function label(): string;
}
```

`backend/src/Service/Reader/Media/EmbedTarget.php`:

```php
<?php

declare(strict_types=1);

namespace App\Service\Reader\Media;

final readonly class EmbedTarget
{
    public function __construct(
        public string $url,
        public ?string $posterUrl,
        public string $label,
    ) {
    }
}
```

`backend/src/Service/Reader/Media/Provider/YouTubeEmbedProvider.php`:

```php
<?php

declare(strict_types=1);

namespace App\Service\Reader\Media\Provider;

use App\Service\Reader\Media\EmbedProviderInterface;

/**
 * YouTube in every spelling a publisher uses, reduced to one nocookie embed.
 * The video id is the whole payload, so the query goes: `?si=` is a share
 * token, and `rel`/`autoplay`/`showinfo` are player preferences we override.
 */
final readonly class YouTubeEmbedProvider implements EmbedProviderInterface
{
    private const string ID = '[A-Za-z0-9_-]{11}';

    private const array HOSTS = [
        'youtube.com', 'www.youtube.com',
        'youtube-nocookie.com', 'www.youtube-nocookie.com',
        'youtu.be', 'www.youtu.be',
    ];

    public function matches(string $url): bool
    {
        return $this->videoId($url) !== null;
    }

    public function normalize(string $url): ?string
    {
        $id = $this->videoId($url);

        return $id === null ? null : 'https://www.youtube-nocookie.com/embed/' . $id;
    }

    public function poster(string $url): ?string
    {
        $id = $this->videoId($url);

        return $id === null ? null : 'https://i.ytimg.com/vi/' . $id . '/hqdefault.jpg';
    }

    public function label(): string
    {
        return 'Watch on YouTube';
    }

    private function videoId(string $url): ?string
    {
        $parts = parse_url($url);
        if (!isset($parts['host'], $parts['path']) || !\in_array(strtolower($parts['host']), self::HOSTS, true)) {
            return null;
        }

        return $this->idFromPath($parts['path']) ?? $this->idFromQuery($parts['query'] ?? '');
    }

    private function idFromPath(string $path): ?string
    {
        return preg_match('#^/(?:embed/|v/)?(' . self::ID . ')$#', $path, $m) === 1 ? $m[1] : null;
    }

    private function idFromQuery(string $query): ?string
    {
        parse_str($query, $params);
        $id = $params['v'] ?? null;

        return \is_string($id) && preg_match('#^' . self::ID . '$#', $id) === 1 ? $id : null;
    }
}
```

- [ ] **Step 4: Run it and confirm it passes**

```bash
cd backend && php bin/phpunit tests/Service/Reader/Media/Provider/YouTubeEmbedProviderTest.php
```

Expected: PASS, 8 tests.

- [ ] **Step 5: Write the failing test for the SoundCloud provider**

Create `backend/tests/Service/Reader/Media/Provider/SoundCloudEmbedProviderTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Service\Reader\Media\Provider;

use App\Service\Reader\Media\Provider\SoundCloudEmbedProvider;
use PHPUnit\Framework\TestCase;

final class SoundCloudEmbedProviderTest extends TestCase
{
    private SoundCloudEmbedProvider $provider;

    protected function setUp(): void
    {
        $this->provider = new SoundCloudEmbedProvider();
    }

    /** Copied from 5 Magazine's page: autoplay and chrome flags must not survive. */
    public function testNormalizesTheTrackAndDropsAutoplay(): void
    {
        $source = 'https://w.soundcloud.com/player/?url=https%3A//api.soundcloud.com/tracks/2370150908'
            . '&color=%23ff5500&auto_play=true&hide_related=true&show_comments=true';

        self::assertSame(
            'https://w.soundcloud.com/player/?url=https%3A%2F%2Fapi.soundcloud.com%2Ftracks%2F2370150908',
            $this->provider->normalize($source)
        );
    }

    public function testHasNoPosterSoItFallsBackToALabel(): void
    {
        $url = 'https://w.soundcloud.com/player/?url=https%3A//api.soundcloud.com/tracks/2370150908';

        self::assertNull($this->provider->poster($url));
        self::assertSame('Listen on SoundCloud', $this->provider->label());
    }

    public function testRejectsAPlayerWithoutATrackId(): void
    {
        self::assertFalse($this->provider->matches('https://w.soundcloud.com/player/?url=https%3A//example.test/x'));
    }

    public function testRejectsANonNumericTrackId(): void
    {
        self::assertFalse($this->provider->matches(
            'https://w.soundcloud.com/player/?url=https%3A//api.soundcloud.com/tracks/abc'
        ));
    }

    public function testRejectsAnotherHost(): void
    {
        self::assertFalse($this->provider->matches('https://player.example.test/?url=x'));
    }
}
```

- [ ] **Step 6: Run it and confirm it fails**

```bash
cd backend && php bin/phpunit tests/Service/Reader/Media/Provider/SoundCloudEmbedProviderTest.php
```

Expected: FAIL — class not found.

- [ ] **Step 7: Write the SoundCloud provider**

`backend/src/Service/Reader/Media/Provider/SoundCloudEmbedProvider.php`:

```php
<?php

declare(strict_types=1);

namespace App\Service\Reader\Media\Provider;

use App\Service\Reader\Media\EmbedProviderInterface;

/**
 * The SoundCloud widget. The track id is permanent and the src is unsigned, so
 * the player survives a cache with no TTL. Only the track is kept: rebuilding
 * the URL from the id alone is what guarantees `auto_play=true` cannot survive.
 */
final readonly class SoundCloudEmbedProvider implements EmbedProviderInterface
{
    private const string HOST = 'w.soundcloud.com';
    private const string TRACK_PATTERN = '#^https?://api\.soundcloud\.com/tracks/(\d+)$#';

    public function matches(string $url): bool
    {
        return $this->trackId($url) !== null;
    }

    public function normalize(string $url): ?string
    {
        $id = $this->trackId($url);
        if ($id === null) {
            return null;
        }

        return 'https://w.soundcloud.com/player/?url='
            . rawurlencode('https://api.soundcloud.com/tracks/' . $id);
    }

    public function poster(string $url): ?string
    {
        return null;
    }

    public function label(): string
    {
        return 'Listen on SoundCloud';
    }

    private function trackId(string $url): ?string
    {
        $parts = parse_url($url);
        if (strtolower($parts['host'] ?? '') !== self::HOST || !isset($parts['query'])) {
            return null;
        }

        parse_str($parts['query'], $params);
        $track = $params['url'] ?? null;

        return \is_string($track) && preg_match(self::TRACK_PATTERN, $track, $m) === 1 ? $m[1] : null;
    }
}
```

- [ ] **Step 8: Run it and confirm it passes**

```bash
cd backend && php bin/phpunit tests/Service/Reader/Media/Provider/SoundCloudEmbedProviderTest.php
```

Expected: PASS, 5 tests.

- [ ] **Step 9: Write `EmbedProviders` and tag the interface**

`backend/src/Service/Reader/Media/EmbedProviders.php`:

```php
<?php

declare(strict_types=1);

namespace App\Service\Reader\Media;

use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

/**
 * The embed allow-list. A URL no provider claims resolves to null, and the
 * caller then leaves the markup alone — the sanitizer drops it as it does today.
 */
final readonly class EmbedProviders
{
    /** @param iterable<EmbedProviderInterface> $providers */
    public function __construct(
        #[AutowireIterator('app.embed_provider')]
        private iterable $providers,
    ) {
    }

    public function resolve(string $url): ?EmbedTarget
    {
        foreach ($this->providers as $provider) {
            $normalized = $provider->matches($url) ? $provider->normalize($url) : null;
            if ($normalized !== null) {
                return new EmbedTarget($normalized, $provider->poster($url), $provider->label());
            }
        }

        return null;
    }
}
```

In `backend/config/services.yaml`, inside the existing `_instanceof:` block, add:

```yaml
        # Same reason as the blocks above: a plain application interface is not
        # auto-tagged, so EmbedProviders' #[AutowireIterator] would silently
        # collect nothing. EmbedProvidersWiringTest guards it.
        App\Service\Reader\Media\EmbedProviderInterface:
            tags: ['app.embed_provider']
```

- [ ] **Step 10: Write the wiring test**

A hand-listed provider array would stay green with an empty tag, which is the silent failure `OAuthProviderWiringTest` documents. Create `backend/tests/Service/Reader/Media/EmbedProvidersWiringTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Service\Reader\Media;

use App\Service\Reader\Media\EmbedProviders;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class EmbedProvidersWiringTest extends KernelTestCase
{
    private function providers(): EmbedProviders
    {
        self::bootKernel();
        $providers = self::getContainer()->get(EmbedProviders::class);
        self::assertInstanceOf(EmbedProviders::class, $providers);

        return $providers;
    }

    public function testYouTubeResolvesThroughTheTaggedIterator(): void
    {
        $target = $this->providers()->resolve('https://www.youtube.com/embed/M1j_uRqKMKI?si=x');

        self::assertNotNull($target);
        self::assertSame('https://www.youtube-nocookie.com/embed/M1j_uRqKMKI', $target->url);
    }

    public function testSoundCloudResolvesThroughTheTaggedIterator(): void
    {
        $target = $this->providers()->resolve(
            'https://w.soundcloud.com/player/?url=https%3A//api.soundcloud.com/tracks/2370150908&auto_play=true'
        );

        self::assertNotNull($target);
        self::assertStringNotContainsString('auto_play', $target->url);
    }

    public function testAnUnknownHostResolvesToNull(): void
    {
        self::assertNull($this->providers()->resolve('https://www.googletagmanager.com/ns.html?id=GTM-1'));
    }
}
```

- [ ] **Step 11: Run the suite and the gates**

```bash
cd backend && bin/console cache:warmup && php bin/phpunit tests/Service/Reader/Media/ && composer check && composer md
```

Expected: all PASS. If the wiring test fails with an empty iterator, the `_instanceof` entry is missing or misspelled.

- [ ] **Step 12: Commit**

```bash
git add backend/src/Service/Reader/Media backend/tests/Service/Reader/Media backend/config/services.yaml
git commit -m "feat(#748): embed provider registry with YouTube and SoundCloud"
```

---

### Task 3: Capture the evidence the remaining hosts need

**This task ships no production code. It decides which later tasks exist.** Four providers and two candidate sources rest on unverified claims; this task turns each into a committed fixture or removes it from the plan.

**Files:**
- Create: `backend/tests/Fixtures/Reader/Media/` (one `.html` per captured page)
- Create: `docs/superpowers/plans/2026-08-31-748-evidence.md` (the findings record)

**Interfaces:**
- Consumes: nothing.
- Produces: fixture filenames used by Tasks 4, 5 and 6, and a go/no-go per host.

- [ ] **Step 1: Capture the pages**

Tests must never reach the network, so each page is fetched once by hand and committed.

```bash
cd backend/tests/Fixtures/Reader/Media
curl -sL -A 'Mozilla/5.0' 'https://www.deutschlandfunkkultur.de/bildung-100.html' -o deutschlandradio-audio.html
curl -sL -A 'Mozilla/5.0' 'https://www.npr.org/2026/08/30/nx-s1-5948814/launch-nancy-grace-roman-space-telescope-nasa' -o npr-audio.html
curl -sL -A 'Mozilla/5.0' 'https://www.tagesschau.de/' -o ard-video.html
curl -sL -A 'Mozilla/5.0' 'https://5mag.net/audio/dj-set-czboogie-live-at-horse-meat-disco-metro-smartbar-2026/' -o soundcloud-page.html
curl -sL -A 'Mozilla/5.0' 'https://www.heise.de/' -o heise-video.html
```

Replace the tagesschau and heise URLs with the exact article URLs for entries 491483 and 487567. Get them with:

```bash
docker compose exec -T php bin/console dbal:run-sql \
  "SELECT id, url FROM entry WHERE id IN (491483, 487567, 489867, 488630, 465683)"
```

- [ ] **Step 2: Answer the five open questions, in writing**

For each host, record the answer in `docs/superpowers/plans/2026-08-31-748-evidence.md`:

1. **NPR** — is the MP3 URL in an attribute, in a `<script>` JSON block, or both? Check with `grep -o 'ondemand[^"]*' npr-audio.html | head`.
2. **heise** — the issue contradicts itself. Its body files entry 487567 as mechanism A (an in-body iframe); an earlier comment says the inline player is a proprietary `<a-video>` element with the YouTube reference elsewhere on the page. Determine which is true, and whether video `M1j_uRqKMKI` is this article's companion or an unrelated channel link. **If it is not the article's own video, heise is out of scope** and no rule may target it.
3. **ARD** — confirm the progressive MP4 renditions are inside a `<script>`. This is the whole justification for reading the raw source rather than the normalized document. If it is false, say so.
4. **Deutschlandradio** — confirm the first `data-audio-src` is this article's file and not a teaser, by matching the slug.
5. **Vimeo, Bandcamp, Spotify, Mixcloud** — find one real subscribed article per provider that embeds it. Search the entry table:

```bash
docker compose exec -T php bin/console dbal:run-sql \
  "SELECT id, url FROM entry WHERE content_html LIKE '%player.vimeo.com%' LIMIT 5"
```

Repeat for `bandcamp.com/EmbeddedPlayer`, `open.spotify.com/embed`, `mixcloud.com/widget`.

- [ ] **Step 3: Decide, and record the decision**

Write a table with one row per provider and per candidate source: **ship** (a fixture exists) or **drop** (no real sample found). Per spec decision D8, a provider with no captured page **does not ship** — delete its section from Task 4 rather than writing a speculative regex.

- [ ] **Step 4: Commit**

```bash
git add backend/tests/Fixtures/Reader/Media docs/superpowers/plans/2026-08-31-748-evidence.md
git commit -m "test(#748): capture source-page fixtures and record the host evidence"
```

---

### Task 4: The remaining embed providers

Write **only** the providers Task 3 marked ship. Each one repeats the Task 2 shape: a host check, an id extraction, a rebuilt URL, and a test that proves a wrong host and a malformed id are refused.

**Files:**
- Create: `backend/src/Service/Reader/Media/Provider/VimeoEmbedProvider.php` (if shipping)
- Create: `backend/src/Service/Reader/Media/Provider/BandcampEmbedProvider.php` (if shipping)
- Create: `backend/src/Service/Reader/Media/Provider/SpotifyEmbedProvider.php` (if shipping)
- Create: `backend/src/Service/Reader/Media/Provider/MixcloudEmbedProvider.php` (if shipping)
- Test: one `…Test.php` per provider under `backend/tests/Service/Reader/Media/Provider/`

**Interfaces:**
- Consumes: `EmbedProviderInterface`, `EmbedTarget` from Task 2. The `_instanceof` tag already collects any new implementation, so no `services.yaml` change is needed.
- Produces: nothing new.

- [ ] **Step 1: Write the failing test for Vimeo**

```php
<?php

declare(strict_types=1);

namespace App\Tests\Service\Reader\Media\Provider;

use App\Service\Reader\Media\Provider\VimeoEmbedProvider;
use PHPUnit\Framework\TestCase;

final class VimeoEmbedProviderTest extends TestCase
{
    private VimeoEmbedProvider $provider;

    protected function setUp(): void
    {
        $this->provider = new VimeoEmbedProvider();
    }

    public function testNormalizesAPlayerUrlAndDropsTheQuery(): void
    {
        self::assertSame(
            'https://player.vimeo.com/video/76979871',
            $this->provider->normalize('https://player.vimeo.com/video/76979871?h=abc&autoplay=1')
        );
    }

    public function testHasNoPosterSoItFallsBackToALabel(): void
    {
        self::assertNull($this->provider->poster('https://player.vimeo.com/video/76979871'));
        self::assertSame('Watch on Vimeo', $this->provider->label());
    }

    public function testRejectsANonNumericId(): void
    {
        self::assertFalse($this->provider->matches('https://player.vimeo.com/video/abc'));
    }

    public function testRejectsAnotherHost(): void
    {
        self::assertFalse($this->provider->matches('https://vimeo.com.evil.test/video/76979871'));
    }
}
```

- [ ] **Step 2: Run it and confirm it fails**

```bash
cd backend && php bin/phpunit tests/Service/Reader/Media/Provider/VimeoEmbedProviderTest.php
```

Expected: FAIL — class not found.

- [ ] **Step 3: Write the Vimeo provider**

```php
<?php

declare(strict_types=1);

namespace App\Service\Reader\Media\Provider;

use App\Service\Reader\Media\EmbedProviderInterface;

/** The Vimeo player. The numeric video id is the whole payload; the query goes. */
final readonly class VimeoEmbedProvider implements EmbedProviderInterface
{
    private const string HOST = 'player.vimeo.com';

    public function matches(string $url): bool
    {
        return $this->videoId($url) !== null;
    }

    public function normalize(string $url): ?string
    {
        $id = $this->videoId($url);

        return $id === null ? null : 'https://player.vimeo.com/video/' . $id;
    }

    public function poster(string $url): ?string
    {
        return null;
    }

    public function label(): string
    {
        return 'Watch on Vimeo';
    }

    private function videoId(string $url): ?string
    {
        $parts = parse_url($url);
        if (strtolower($parts['host'] ?? '') !== self::HOST) {
            return null;
        }

        return preg_match('#^/video/(\d+)$#', $parts['path'] ?? '', $m) === 1 ? $m[1] : null;
    }
}
```

- [ ] **Step 4: Run it and confirm it passes**

```bash
cd backend && php bin/phpunit tests/Service/Reader/Media/Provider/VimeoEmbedProviderTest.php
```

Expected: PASS, 4 tests.

- [ ] **Step 5: Repeat Steps 1–4 for each remaining shipping provider**

Use these host and id rules. Keep the same four test cases each time: normalize-and-drop-query, poster/label, malformed id refused, look-alike host refused.

- **Spotify** — host `open.spotify.com`, path `^/embed/(track|album|playlist|episode|show)/([A-Za-z0-9]{22})$`, emits `https://open.spotify.com/embed/{type}/{id}`, label `Listen on Spotify`, no poster.
- **Mixcloud** — host `www.mixcloud.com`, path `/widget/iframe/`, keeps only the `feed` query parameter, emits `https://www.mixcloud.com/widget/iframe/?feed={rawurlencode(feed)}`, label `Listen on Mixcloud`, no poster. Refuse a `feed` value that is not a path beginning `/`.
- **Bandcamp** — host `bandcamp.com`, path `^/EmbeddedPlayer/`. Bandcamp carries its parameters as `key=value` **path segments**, not a query, so keep only the segments whose key is `album`, `track`, `size`, `bgcol`, `linkcol` or `artwork`, in that order, and drop the rest. Emits `https://bandcamp.com/EmbeddedPlayer/{kept}/`, label `Listen on Bandcamp`, no poster. Refuse when neither `album` nor `track` survives.

- [ ] **Step 6: Extend the wiring test**

Add one case per shipping provider to `EmbedProvidersWiringTest`, proving it resolves through the container's tagged iterator and not just when constructed by hand.

- [ ] **Step 7: Run everything and commit**

```bash
cd backend && php bin/phpunit tests/Service/Reader/Media/ && composer check && composer md
git add backend/src/Service/Reader/Media backend/tests/Service/Reader/Media
git commit -m "feat(#748): Vimeo, Spotify, Mixcloud and Bandcamp embed providers"
```

Amend the commit message to name only the providers that actually shipped.

---

### Task 5: Candidate sources and the page scanner

Discovery. Reads the raw page, so `<script>` payloads are reachable.

**Files:**
- Create: `backend/src/Service/Reader/Media/MediaCandidateSourceInterface.php`
- Create: `backend/src/Service/Reader/Media/PageMediaScanner.php`
- Create: `backend/src/Service/Reader/Media/Source/DeutschlandradioAudioSource.php`
- Create: `backend/src/Service/Reader/Media/Source/PageEmbedSource.php`
- Modify: `backend/config/services.yaml`
- Test: `backend/tests/Service/Reader/Media/Source/DeutschlandradioAudioSourceTest.php`
- Test: `backend/tests/Service/Reader/Media/Source/PageEmbedSourceTest.php`
- Test: `backend/tests/Service/Reader/Media/PageMediaScannerWiringTest.php`

**Interfaces:**
- Consumes: `MediaCandidate`, `MediaKind`, `ArticleMedia`, `DurableMediaUrl` (Task 1); `EmbedProviders`, `EmbedTarget` (Task 2).
- Produces:
  - `interface MediaCandidateSourceInterface { /** @return list<MediaCandidate> */ public function find(string $pageHtml, string $pageUrl): array; }`
  - `final readonly class PageMediaScanner { public function scan(string $pageHtml, string $pageUrl): ArticleMedia; }`
  - Tag: `app.media_candidate_source`

- [ ] **Step 1: Write the failing test for the Deutschlandradio source**

```php
<?php

declare(strict_types=1);

namespace App\Tests\Service\Reader\Media\Source;

use App\Service\Reader\Media\DurableMediaUrl;
use App\Service\Reader\Media\MediaKind;
use App\Service\Reader\Media\Source\DeutschlandradioAudioSource;
use PHPUnit\Framework\TestCase;

final class DeutschlandradioAudioSourceTest extends TestCase
{
    private DeutschlandradioAudioSource $source;

    protected function setUp(): void
    {
        $this->source = new DeutschlandradioAudioSource(new DurableMediaUrl());
    }

    private const string URL = 'https://www.deutschlandfunkkultur.de/bildung-100.html';

    public function testTakesTheFirstArticleAudio(): void
    {
        $html = '<html><body>'
            . '<div data-audio-src="https://ondemand-mp3.dradio.de/file/dradio/2026/08/bildung.mp3"></div>'
            . '<div data-audio-src="https://ondemand-mp3.dradio.de/file/dradio/2026/08/teaser.mp3"></div>'
            . '</body></html>';

        $found = $this->source->find($html, self::URL);

        self::assertCount(1, $found);
        self::assertSame(MediaKind::Audio, $found[0]->kind);
        self::assertStringEndsWith('bildung.mp3', $found[0]->url);
    }

    public function testSkipsTheLiveStreamAndTakesTheEpisode(): void
    {
        $html = '<html><body>'
            . '<div data-audio-src="https://st01.sslstream.dlf.de/dlf/01/128/mp3/stream.mp3"></div>'
            . '<div data-audio-src="https://ondemand-mp3.dradio.de/file/dradio/2026/08/bildung.mp3"></div>'
            . '</body></html>';

        $found = $this->source->find($html, self::URL);

        self::assertCount(1, $found);
        self::assertStringEndsWith('bildung.mp3', $found[0]->url);
    }

    public function testIgnoresAnotherHost(): void
    {
        $html = '<html><body><div data-audio-src="https://ondemand-mp3.dradio.de/a.mp3"></div></body></html>';

        self::assertSame([], $this->source->find($html, 'https://www.spiegel.de/x.html'));
    }

    public function testFindsNothingWhenThePageHasNoAudio(): void
    {
        self::assertSame([], $this->source->find('<html><body><p>text</p></body></html>', self::URL));
    }
}
```

- [ ] **Step 2: Run it and confirm it fails**

```bash
cd backend && php bin/phpunit tests/Service/Reader/Media/Source/DeutschlandradioAudioSourceTest.php
```

Expected: FAIL — class not found.

- [ ] **Step 3: Write the interface and the Deutschlandradio source**

`backend/src/Service/Reader/Media/MediaCandidateSourceInterface.php`:

```php
<?php

declare(strict_types=1);

namespace App\Service\Reader\Media;

/**
 * Finds the media one publisher's pages offer. Reads the RAW page, not the
 * normalized document: FetchedPageNormalizer strips every <script> before it
 * parses, and some hosts carry their media only in player JSON.
 */
interface MediaCandidateSourceInterface
{
    /** @return list<MediaCandidate> */
    public function find(string $pageHtml, string $pageUrl): array;
}
```

`backend/src/Service/Reader/Media/Source/DeutschlandradioAudioSource.php`:

```php
<?php

declare(strict_types=1);

namespace App\Service\Reader\Media\Source;

use App\Service\Html\HtmlDocumentParser;
use App\Service\Reader\Media\DurableMediaUrl;
use App\Service\Reader\Media\MediaCandidate;
use App\Service\Reader\Media\MediaCandidateSourceInterface;
use App\Service\Reader\Media\MediaKind;

/**
 * Deutschlandradio publishes the episode as the first `data-audio-src` on the
 * page. The ones after it are related teasers, and the live station stream is
 * not this episode — so only the first durable URL is taken.
 */
final readonly class DeutschlandradioAudioSource implements MediaCandidateSourceInterface
{
    private const array HOSTS = [
        'www.deutschlandfunk.de', 'deutschlandfunk.de',
        'www.deutschlandfunkkultur.de', 'deutschlandfunkkultur.de',
        'www.deutschlandfunknova.de', 'deutschlandfunknova.de',
    ];

    public function __construct(private DurableMediaUrl $durable)
    {
    }

    public function find(string $pageHtml, string $pageUrl): array
    {
        if (!$this->isDeutschlandradio($pageUrl)) {
            return [];
        }

        $episode = $this->firstDurableAudio($pageHtml);

        return $episode === null ? [] : [new MediaCandidate(MediaKind::Audio, $episode)];
    }

    private function isDeutschlandradio(string $pageUrl): bool
    {
        return \in_array(strtolower(parse_url($pageUrl, \PHP_URL_HOST) ?? ''), self::HOSTS, true);
    }

    private function firstDurableAudio(string $pageHtml): ?string
    {
        $document = HtmlDocumentParser::parseOrNull($pageHtml);
        if ($document === null) {
            return null;
        }

        foreach ($document->querySelectorAll('[data-audio-src]') as $element) {
            $source = $element->getAttribute('data-audio-src') ?? '';
            if ($this->durable->accepts($source)) {
                return $source;
            }
        }

        return null;
    }
}
```

- [ ] **Step 4: Run it and confirm it passes**

```bash
cd backend && php bin/phpunit tests/Service/Reader/Media/Source/DeutschlandradioAudioSourceTest.php
```

Expected: PASS, 4 tests.

- [ ] **Step 5: Write the failing test for `PageEmbedSource`**

```php
<?php

declare(strict_types=1);

namespace App\Tests\Service\Reader\Media\Source;

use App\Service\Reader\Media\EmbedProviders;
use App\Service\Reader\Media\MediaKind;
use App\Service\Reader\Media\Provider\SoundCloudEmbedProvider;
use App\Service\Reader\Media\Provider\YouTubeEmbedProvider;
use App\Service\Reader\Media\Source\PageEmbedSource;
use PHPUnit\Framework\TestCase;

final class PageEmbedSourceTest extends TestCase
{
    private PageEmbedSource $source;

    protected function setUp(): void
    {
        $this->source = new PageEmbedSource(
            new EmbedProviders([new YouTubeEmbedProvider(), new SoundCloudEmbedProvider()])
        );
    }

    /** 5 Magazine: readability removes this iframe before the body cleaner runs. */
    public function testFindsASoundCloudPlayerOnThePage(): void
    {
        $html = '<html><body><iframe src="https://w.soundcloud.com/player/'
            . '?url=https%3A//api.soundcloud.com/tracks/2370150908&amp;auto_play=true"></iframe></body></html>';

        $found = $this->source->find($html, 'https://5mag.net/audio/dj-set/');

        self::assertCount(1, $found);
        self::assertSame(MediaKind::Embed, $found[0]->kind);
        self::assertStringNotContainsString('auto_play', $found[0]->url);
        self::assertSame('Listen on SoundCloud', $found[0]->label);
    }

    public function testIgnoresAnIframeNoProviderClaims(): void
    {
        $html = '<html><body><iframe src="https://www.googletagmanager.com/ns.html?id=GTM-1"></iframe></body></html>';

        self::assertSame([], $this->source->find($html, 'https://example.test/x'));
    }

    public function testKeepsSourceOrderAndDeduplicates(): void
    {
        $html = '<html><body>'
            . '<iframe src="https://www.youtube.com/embed/aaaaaaaaaaa"></iframe>'
            . '<iframe src="https://www.youtube.com/embed/bbbbbbbbbbb"></iframe>'
            . '<iframe src="https://www.youtube-nocookie.com/embed/aaaaaaaaaaa"></iframe>'
            . '</body></html>';

        $found = $this->source->find($html, 'https://example.test/x');

        self::assertCount(2, $found);
        self::assertStringEndsWith('aaaaaaaaaaa', $found[0]->url);
        self::assertStringEndsWith('bbbbbbbbbbb', $found[1]->url);
    }
}
```

Note: `EmbedProviders` takes an `iterable`, so an array is a valid constructor argument in a unit test.

- [ ] **Step 6: Run it and confirm it fails**

```bash
cd backend && php bin/phpunit tests/Service/Reader/Media/Source/PageEmbedSourceTest.php
```

Expected: FAIL — class not found.

- [ ] **Step 7: Write `PageEmbedSource`**

```php
<?php

declare(strict_types=1);

namespace App\Service\Reader\Media\Source;

use App\Service\Html\HtmlDocumentParser;
use App\Service\Reader\Media\EmbedProviders;
use App\Service\Reader\Media\MediaCandidate;
use App\Service\Reader\Media\MediaCandidateSourceInterface;
use App\Service\Reader\Media\MediaKind;

/**
 * Host-agnostic: any iframe on the page an embed provider claims. This is the
 * only route for an embed readability removes before the body cleaner can see
 * it (5 Magazine's SoundCloud player).
 *
 * A whole-page scan also sees sidebar and related-teaser embeds. The caller
 * suppresses these whenever the body recovered its own, so nothing outside the
 * article is inserted while the article has media of its own.
 */
final readonly class PageEmbedSource implements MediaCandidateSourceInterface
{
    public function __construct(private EmbedProviders $providers)
    {
    }

    public function find(string $pageHtml, string $pageUrl): array
    {
        $document = HtmlDocumentParser::parseOrNull($pageHtml);
        if ($document === null) {
            return [];
        }

        $found = [];
        foreach ($document->getElementsByTagName('iframe') as $iframe) {
            $target = $this->providers->resolve($iframe->getAttribute('src') ?? '');
            if ($target !== null) {
                $found[$target->url] = new MediaCandidate(
                    MediaKind::Embed,
                    $target->url,
                    $target->posterUrl,
                    $target->label,
                );
            }
        }

        return array_values($found);
    }
}
```

- [ ] **Step 8: Run it and confirm it passes**

```bash
cd backend && php bin/phpunit tests/Service/Reader/Media/Source/PageEmbedSourceTest.php
```

Expected: PASS, 3 tests.

- [ ] **Step 9: Write `PageMediaScanner` and tag the source interface**

```php
<?php

declare(strict_types=1);

namespace App\Service\Reader\Media;

use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

/**
 * Runs every candidate source over the raw page and returns what they find, in
 * source order, capped.
 *
 * It reads the raw HTML rather than FetchedPageNormalizer's document on purpose:
 * that pass strips <script> blocks from the string before parsing, and ARD keeps
 * its renditions in player JSON. Working from the source costs one extra parse,
 * the same trade collapseWrapperChains() already makes.
 */
final readonly class PageMediaScanner
{
    /** @param iterable<MediaCandidateSourceInterface> $sources */
    public function __construct(
        #[AutowireIterator('app.media_candidate_source')]
        private iterable $sources,
    ) {
    }

    public function scan(string $pageHtml, string $pageUrl): ArticleMedia
    {
        $found = [];
        foreach ($this->sources as $source) {
            foreach ($source->find($pageHtml, $pageUrl) as $candidate) {
                $found[$candidate->url] = $candidate;
            }
        }

        return new ArticleMedia(\array_slice(array_values($found), 0, ArticleMedia::MAX_ITEMS));
    }
}
```

In `backend/config/services.yaml`, inside `_instanceof:`, add:

```yaml
        # As above: PageMediaScanner's #[AutowireIterator] collects the candidate
        # sources only if this plain interface is tagged.
        App\Service\Reader\Media\MediaCandidateSourceInterface:
            tags: ['app.media_candidate_source']
```

- [ ] **Step 10: Write the scanner wiring test**

```php
<?php

declare(strict_types=1);

namespace App\Tests\Service\Reader\Media;

use App\Service\Reader\Media\PageMediaScanner;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class PageMediaScannerWiringTest extends KernelTestCase
{
    public function testTheTaggedSourcesAreCollected(): void
    {
        self::bootKernel();
        $scanner = self::getContainer()->get(PageMediaScanner::class);
        self::assertInstanceOf(PageMediaScanner::class, $scanner);

        $html = '<html><body><iframe src="https://www.youtube.com/embed/M1j_uRqKMKI"></iframe></body></html>';
        $media = $scanner->scan($html, 'https://example.test/article');

        self::assertFalse($media->isEmpty());
        self::assertSame('https://www.youtube-nocookie.com/embed/M1j_uRqKMKI', $media->candidates[0]->url);
    }
}
```

- [ ] **Step 11: Run and commit**

```bash
cd backend && bin/console cache:warmup && php bin/phpunit tests/Service/Reader/Media/ && composer check && composer md
git add backend/src/Service/Reader/Media backend/tests/Service/Reader/Media backend/config/services.yaml
git commit -m "feat(#748): page media scanner with the Deutschlandradio and page-embed sources"
```

---

### Task 6: The NPR and ARD candidate sources

Written against Task 3's fixtures. If Task 3 found either host's media is not where the issue claims, follow the fixture, not this plan, and say so in the commit message.

**Files:**
- Create: `backend/src/Service/Reader/Media/Source/NprAudioSource.php`
- Create: `backend/src/Service/Reader/Media/Source/ArdVideoSource.php`
- Test: `backend/tests/Service/Reader/Media/Source/NprAudioSourceTest.php`
- Test: `backend/tests/Service/Reader/Media/Source/ArdVideoSourceTest.php`

**Interfaces:**
- Consumes: `MediaCandidateSourceInterface`, `MediaCandidate`, `MediaKind`, `DurableMediaUrl`. The tag from Task 5 collects both automatically.
- Produces: nothing new.

- [ ] **Step 1: Write the failing test for NPR**

The point of this test is the analytics-versus-signature distinction. NPR's URL looks signed and is not: the bare path returns an identical `200 audio/mpeg`.

```php
<?php

declare(strict_types=1);

namespace App\Tests\Service\Reader\Media\Source;

use App\Service\Reader\Media\DurableMediaUrl;
use App\Service\Reader\Media\MediaKind;
use App\Service\Reader\Media\Source\NprAudioSource;
use PHPUnit\Framework\TestCase;

final class NprAudioSourceTest extends TestCase
{
    private NprAudioSource $source;

    protected function setUp(): void
    {
        $this->source = new NprAudioSource(new DurableMediaUrl());
    }

    private const string URL = 'https://www.npr.org/2026/08/30/nx-s1-5948814/roman-space-telescope';

    public function testStripsTheAnalyticsQuery(): void
    {
        $html = '<html><body><audio src="https://ondemand.npr.org/anon.npr-mp3/npr/wesun/dark_energy.mp3'
            . '?t=progseg&amp;sc=siteplayer&amp;aw_0_1st.playerid=siteplayer"></audio></body></html>';

        $found = $this->source->find($html, self::URL);

        self::assertCount(1, $found);
        self::assertSame(MediaKind::Audio, $found[0]->kind);
        self::assertSame('https://ondemand.npr.org/anon.npr-mp3/npr/wesun/dark_energy.mp3', $found[0]->url);
    }

    public function testIgnoresAnotherHost(): void
    {
        $html = '<html><body><audio src="https://ondemand.npr.org/anon.npr-mp3/a.mp3"></audio></body></html>';

        self::assertSame([], $this->source->find($html, 'https://www.spiegel.de/x.html'));
    }

    public function testIgnoresAnNprUrlOutsideTheAnonymousPath(): void
    {
        $html = '<html><body><audio src="https://ondemand.npr.org/members-only/a.mp3"></audio></body></html>';

        self::assertSame([], $this->source->find($html, self::URL));
    }
}
```

- [ ] **Step 2: Run it and confirm it fails**

```bash
cd backend && php bin/phpunit tests/Service/Reader/Media/Source/NprAudioSourceTest.php
```

Expected: FAIL — class not found.

- [ ] **Step 3: Write the NPR source**

Adjust the element selector to whatever Task 3's fixture actually shows. If the URL lives only in a `<script>` JSON block, replace `mp3Elements()` with a `preg_match_all` over `$pageHtml` for `https://ondemand\.npr\.org/anon\.npr-mp3/[^"'\\s]+\.mp3`, which works on the raw source and is why this class receives it.

```php
<?php

declare(strict_types=1);

namespace App\Service\Reader\Media\Source;

use App\Service\Reader\Media\DurableMediaUrl;
use App\Service\Reader\Media\MediaCandidate;
use App\Service\Reader\Media\MediaCandidateSourceInterface;
use App\Service\Reader\Media\MediaKind;

/**
 * NPR's segment audio. The URL carries playback analytics (`t=progseg`,
 * `sc=siteplayer`, `aw_0_1st.playerid`) that read like a signature and are not:
 * the bare path returns an identical 200 audio/mpeg. Stripping the query is
 * therefore safe and is what makes the URL durable enough to embed.
 */
final readonly class NprAudioSource implements MediaCandidateSourceInterface
{
    private const array HOSTS = ['www.npr.org', 'npr.org', 'text.npr.org'];

    /** The anonymous, tokenless delivery path — the host segment says so. */
    private const string AUDIO_PATTERN = '#https://ondemand\.npr\.org/anon\.npr-mp3/[^"\'\s\\\\]+?\.mp3#i';

    public function __construct(private DurableMediaUrl $durable)
    {
    }

    public function find(string $pageHtml, string $pageUrl): array
    {
        if (!\in_array(strtolower(parse_url($pageUrl, \PHP_URL_HOST) ?? ''), self::HOSTS, true)) {
            return [];
        }

        preg_match_all(self::AUDIO_PATTERN, $pageHtml, $matches);

        return $this->firstDurable($matches[0]);
    }

    /**
     * @param list<string> $urls
     *
     * @return list<MediaCandidate>
     */
    private function firstDurable(array $urls): array
    {
        foreach ($urls as $url) {
            if ($this->durable->accepts($url)) {
                return [new MediaCandidate(MediaKind::Audio, $url)];
            }
        }

        return [];
    }
}
```

Because the pattern stops at `.mp3`, the analytics query is never captured, so the URL reaching `DurableMediaUrl` is already query-free.

- [ ] **Step 4: Run it and confirm it passes**

```bash
cd backend && php bin/phpunit tests/Service/Reader/Media/Source/NprAudioSourceTest.php
```

Expected: PASS, 3 tests.

- [ ] **Step 5: Write the failing test for ARD**

The mandatory poster is the point: without it a depublished video becomes a dead frame in a cache with no TTL.

```php
<?php

declare(strict_types=1);

namespace App\Tests\Service\Reader\Media\Source;

use App\Service\Reader\Media\DurableMediaUrl;
use App\Service\Reader\Media\MediaKind;
use App\Service\Reader\Media\Source\ArdVideoSource;
use PHPUnit\Framework\TestCase;

final class ArdVideoSourceTest extends TestCase
{
    private ArdVideoSource $source;

    protected function setUp(): void
    {
        $this->source = new ArdVideoSource(new DurableMediaUrl());
    }

    private const string URL = 'https://www.tagesschau.de/ausland/beispiel-100.html';

    public function testFindsTheProgressiveVideoWithItsPoster(): void
    {
        $html = '<html><head><meta property="og:image" content="https://images.tagesschau.de/p.jpg"></head>'
            . '<body><script type="application/json">{"streams":['
            . '"https://tagesschau-progressive.ard-mcdn.de/v/webl.mp4",'
            . '"https://tagesschau-progressive.ard-mcdn.de/v/webxxl.mp4"]}</script></body></html>';

        $found = $this->source->find($html, self::URL);

        self::assertCount(1, $found);
        self::assertSame(MediaKind::Video, $found[0]->kind);
        self::assertSame('https://images.tagesschau.de/p.jpg', $found[0]->posterUrl);
    }

    /** No poster, no candidate: a posterless video rots into a dead frame. */
    public function testDropsTheVideoWhenThePageOffersNoPoster(): void
    {
        $html = '<html><head></head><body><script type="application/json">'
            . '{"streams":["https://tagesschau-progressive.ard-mcdn.de/v/webl.mp4"]}</script></body></html>';

        self::assertSame([], $this->source->find($html, self::URL));
    }

    public function testIgnoresAnHlsPlaylist(): void
    {
        $html = '<html><head><meta property="og:image" content="https://images.tagesschau.de/p.jpg"></head>'
            . '<body><script type="application/json">'
            . '{"streams":["https://tagesschau-progressive.ard-mcdn.de/v/master.m3u8"]}</script></body></html>';

        self::assertSame([], $this->source->find($html, self::URL));
    }

    public function testIgnoresAnotherHost(): void
    {
        $html = '<html><head><meta property="og:image" content="https://x.test/p.jpg"></head>'
            . '<body><script>"https://tagesschau-progressive.ard-mcdn.de/v/webl.mp4"</script></body></html>';

        self::assertSame([], $this->source->find($html, 'https://www.spiegel.de/x.html'));
    }
}
```

- [ ] **Step 6: Run it and confirm it fails**

```bash
cd backend && php bin/phpunit tests/Service/Reader/Media/Source/ArdVideoSourceTest.php
```

Expected: FAIL — class not found.

- [ ] **Step 7: Write the ARD source**

```php
<?php

declare(strict_types=1);

namespace App\Service\Reader\Media\Source;

use App\Service\Reader\Media\DurableMediaUrl;
use App\Service\Reader\Media\MediaCandidate;
use App\Service\Reader\Media\MediaCandidateSourceInterface;
use App\Service\Reader\Media\MediaKind;

/**
 * ARD's progressive MP4. Public broadcasters depublish on a schedule, and the
 * reader's article cache has no TTL, so the poster is not decoration: it turns
 * eventual depublication into a still with a play control that fails, instead of
 * a black frame. A page that offers no poster yields no candidate.
 *
 * The renditions live in the page's player JSON, which the normalizer's script
 * strip removes — this class reads the raw source, so they are still there.
 */
final readonly class ArdVideoSource implements MediaCandidateSourceInterface
{
    private const array HOSTS = [
        'www.tagesschau.de', 'tagesschau.de', 'www.ndr.de', 'ndr.de',
        'www.daserste.de', 'daserste.de',
    ];

    private const string VIDEO_PATTERN = '#https://[a-z0-9-]+\.ard-mcdn\.de/[^"\'\s\\\\]+?\.mp4#i';

    private const string POSTER_PATTERN = '#<meta[^>]+property=["\']og:image["\'][^>]+content=["\']([^"\']+)["\']#i';

    /** Widest first: ARD labels renditions by size, and the largest reads best. */
    private const array RENDITION_ORDER = ['webxxl', 'webxl', 'webl', 'webm', 'webs'];

    public function __construct(private DurableMediaUrl $durable)
    {
    }

    public function find(string $pageHtml, string $pageUrl): array
    {
        if (!\in_array(strtolower(parse_url($pageUrl, \PHP_URL_HOST) ?? ''), self::HOSTS, true)) {
            return [];
        }

        $poster = $this->poster($pageHtml);
        $video = $poster === null ? null : $this->widestVideo($pageHtml);

        return $video === null ? [] : [new MediaCandidate(MediaKind::Video, $video, $poster)];
    }

    private function poster(string $pageHtml): ?string
    {
        if (preg_match(self::POSTER_PATTERN, $pageHtml, $m) !== 1) {
            return null;
        }

        return preg_match('#^https://#i', $m[1]) === 1 ? $m[1] : null;
    }

    private function widestVideo(string $pageHtml): ?string
    {
        preg_match_all(self::VIDEO_PATTERN, $pageHtml, $matches);
        $durable = array_values(array_filter($matches[0], fn (string $u): bool => $this->durable->accepts($u)));
        if ($durable === []) {
            return null;
        }

        foreach (self::RENDITION_ORDER as $rendition) {
            foreach ($durable as $url) {
                if (str_contains($url, $rendition)) {
                    return $url;
                }
            }
        }

        return $durable[0];
    }
}
```

The HLS case needs no rule: the pattern matches `.mp4` only, so `master.m3u8` is never captured.

- [ ] **Step 8: Run it and confirm it passes**

```bash
cd backend && php bin/phpunit tests/Service/Reader/Media/Source/ArdVideoSourceTest.php
```

Expected: PASS, 4 tests.

- [ ] **Step 9: Add a fixture test per source**

For each of Deutschlandradio, NPR and ARD, add one test that reads the real captured page from Task 3 and asserts the source finds the expected URL. This is what catches a markup change that hand-written HTML would not.

```php
public function testFindsTheAudioInTheCapturedPage(): void
{
    $html = file_get_contents(__DIR__ . '/../../../../Fixtures/Reader/Media/deutschlandradio-audio.html');
    self::assertIsString($html);

    $found = $this->source->find($html, 'https://www.deutschlandfunkkultur.de/bildung-100.html');

    self::assertCount(1, $found);
    self::assertStringContainsString('.mp3', $found[0]->url);
}
```

- [ ] **Step 10: Run everything and commit**

```bash
cd backend && php bin/phpunit tests/Service/Reader/Media/ && composer check && composer md
git add backend/src/Service/Reader/Media backend/tests/Service/Reader/Media
git commit -m "feat(#748): NPR audio and ARD video candidate sources"
```

---

### Task 7: Rewrite in-body embeds and the Substack poster

Mechanism A. `ReaderBodyCleaner` runs before `EntrySanitizer`, so the publisher's `<iframe>` is still present with its `src`, at the right position.

**Files:**
- Create: `backend/src/Service/Reader/Media/InBodyEmbedRewriter.php`
- Create: `backend/src/Service/Reader/Media/SubstackPosterLink.php`
- Create: `backend/src/Service/Reader/Media/MediaMarkup.php`
- Test: `backend/tests/Service/Reader/Media/InBodyEmbedRewriterTest.php`
- Test: `backend/tests/Service/Reader/Media/SubstackPosterLinkTest.php`

**Interfaces:**
- Consumes: `EmbedProviders`, `EmbedTarget` (Task 2); `YouTubeEmbedProvider` (Task 2) for the Substack id path.
- Produces:
  - `final readonly class MediaMarkup { public function embedLink(\Dom\HTMLDocument $document, EmbedTarget $target): \Dom\Element; }`
  - `final readonly class InBodyEmbedRewriter { public function rewriteIn(\Dom\HTMLDocument $body): bool; }` — true if it rewrote anything.
  - `final readonly class SubstackPosterLink { public function linkIn(\Dom\HTMLDocument $body): void; }`

- [ ] **Step 1: Write the failing test for the rewriter**

```php
<?php

declare(strict_types=1);

namespace App\Tests\Service\Reader\Media;

use App\Service\Html\HtmlDocumentParser;
use App\Service\Reader\Media\EmbedProviders;
use App\Service\Reader\Media\InBodyEmbedRewriter;
use App\Service\Reader\Media\MediaMarkup;
use App\Service\Reader\Media\Provider\SoundCloudEmbedProvider;
use App\Service\Reader\Media\Provider\YouTubeEmbedProvider;
use PHPUnit\Framework\TestCase;

final class InBodyEmbedRewriterTest extends TestCase
{
    private InBodyEmbedRewriter $rewriter;

    protected function setUp(): void
    {
        $this->rewriter = new InBodyEmbedRewriter(
            new EmbedProviders([new YouTubeEmbedProvider(), new SoundCloudEmbedProvider()]),
            new MediaMarkup(),
        );
    }

    private function rewrite(string $html): string
    {
        $document = HtmlDocumentParser::parseOrNull($html);
        self::assertNotNull($document);
        $this->rewriter->rewriteIn($document);

        return $document->saveHtml();
    }

    /** The OZORA shape: a heading, then the embed, ten times over. */
    public function testKeepsEachEmbedAtItsHeadingPosition(): void
    {
        $html = '<body><h3>One</h3><div><iframe src="https://www.youtube.com/embed/aaaaaaaaaaa?si=x"></iframe></div>'
            . '<h3>Two</h3><div><iframe src="https://www.youtube.com/embed/bbbbbbbbbbb"></iframe></div></body>';

        $out = $this->rewrite($html);

        self::assertStringContainsString('<h3>One</h3>', $out);
        self::assertStringContainsString('youtube-nocookie.com/embed/aaaaaaaaaaa', $out);
        self::assertStringContainsString('youtube-nocookie.com/embed/bbbbbbbbbbb', $out);
        self::assertLessThan(
            strpos($out, 'bbbbbbbbbbb'),
            strpos($out, 'aaaaaaaaaaa'),
            'embeds must keep source order'
        );
        self::assertStringNotContainsString('<iframe', $out);
        self::assertStringNotContainsString('si=x', $out);
    }

    public function testAYouTubeEmbedBecomesAPosterLink(): void
    {
        $out = $this->rewrite('<body><iframe src="https://www.youtube.com/embed/aaaaaaaaaaa"></iframe></body>');

        self::assertStringContainsString('href="https://www.youtube-nocookie.com/embed/aaaaaaaaaaa"', $out);
        self::assertStringContainsString('i.ytimg.com/vi/aaaaaaaaaaa/hqdefault.jpg', $out);
    }

    /** No cheap poster, so the link carries text a reader can act on. */
    public function testASoundCloudEmbedBecomesATextLink(): void
    {
        $out = $this->rewrite('<body><iframe src="https://w.soundcloud.com/player/'
            . '?url=https%3A//api.soundcloud.com/tracks/2370150908&amp;auto_play=true"></iframe></body>');

        self::assertStringContainsString('Listen on SoundCloud', $out);
        self::assertStringNotContainsString('auto_play', $out);
    }

    public function testAnUnknownIframeIsLeftForTheSanitizer(): void
    {
        $html = '<body><iframe src="https://www.googletagmanager.com/ns.html?id=GTM-1"></iframe></body>';

        self::assertStringContainsString('googletagmanager', $this->rewrite($html));
    }

    public function testReportsWhetherItActed(): void
    {
        $none = HtmlDocumentParser::parseOrNull('<body><p>text</p></body>');
        self::assertNotNull($none);
        self::assertFalse($this->rewriter->rewriteIn($none));

        $one = HtmlDocumentParser::parseOrNull('<body><iframe src="https://youtu.be/aaaaaaaaaaa"></iframe></body>');
        self::assertNotNull($one);
        self::assertTrue($this->rewriter->rewriteIn($one));
    }

    /** Do not reuse #627's alt text: its CSS paints a play badge on that string. */
    public function testDoesNotReuseTheSubstackPlaceholderAltText(): void
    {
        $out = $this->rewrite('<body><iframe src="https://www.youtube.com/embed/aaaaaaaaaaa"></iframe></body>');

        self::assertStringNotContainsString('Video — open the original article to watch', $out);
    }
}
```

- [ ] **Step 2: Run it and confirm it fails**

```bash
cd backend && php bin/phpunit tests/Service/Reader/Media/InBodyEmbedRewriterTest.php
```

Expected: FAIL — class not found.

- [ ] **Step 3: Write `MediaMarkup` and `InBodyEmbedRewriter`**

`backend/src/Service/Reader/Media/MediaMarkup.php`:

```php
<?php

declare(strict_types=1);

namespace App\Service\Reader\Media;

use Dom\Element;
use Dom\HTMLDocument;

/**
 * Builds the one markup shape every recovered embed uses: a link to the durable
 * embed URL, with a poster inside it when the provider has one.
 *
 * A link, not an iframe. EntrySanitizer is shared with feed ingest, so allowing
 * iframes there would let any feed inject one; the reader upgrades this link to
 * a real player at render instead.
 */
final readonly class MediaMarkup
{
    public function embedLink(HTMLDocument $document, EmbedTarget $target): Element
    {
        $link = $document->createElement('a');
        $link->setAttribute('href', $target->url);

        $poster = $target->posterUrl;
        if ($poster === null) {
            $link->appendChild($document->createTextNode($target->label));

            return $link;
        }

        $image = $document->createElement('img');
        $image->setAttribute('src', $poster);
        $image->setAttribute('alt', $target->label);
        $link->appendChild($image);

        return $link;
    }
}
```

`backend/src/Service/Reader/Media/InBodyEmbedRewriter.php`:

```php
<?php

declare(strict_types=1);

namespace App\Service\Reader\Media;

use Dom\Element;
use Dom\HTMLDocument;

/**
 * Turns a publisher's in-body player into a link the reader can render.
 *
 * This runs before EntrySanitizer, so the iframe is still present with its src
 * and at the position the publisher chose — which is why the media needs no
 * re-fetch and lands back exactly where it belongs. Rewriting rather than
 * removing also disposes of the empty containers the sanitizer used to leave.
 *
 * An iframe no provider claims is left untouched, and the sanitizer drops it as
 * it does today.
 */
final readonly class InBodyEmbedRewriter
{
    public function __construct(
        private EmbedProviders $providers,
        private MediaMarkup $markup,
    ) {
    }

    public function rewriteIn(HTMLDocument $body): bool
    {
        $rewritten = false;
        foreach (iterator_to_array($body->getElementsByTagName('iframe')) as $iframe) {
            $rewritten = $this->rewriteOne($body, $iframe) || $rewritten;
        }

        return $rewritten;
    }

    private function rewriteOne(HTMLDocument $body, Element $iframe): bool
    {
        $target = $this->providers->resolve($iframe->getAttribute('src') ?? '');
        if ($target === null || $iframe->parentNode === null) {
            return false;
        }

        $iframe->parentNode->replaceChild($this->markup->embedLink($body, $target), $iframe);

        return true;
    }
}
```

`getElementsByTagName` returns a live list, so `iterator_to_array` snapshots it before the tree is mutated.

- [ ] **Step 4: Run it and confirm it passes**

```bash
cd backend && php bin/phpunit tests/Service/Reader/Media/InBodyEmbedRewriterTest.php
```

Expected: PASS, 6 tests.

- [ ] **Step 5: Write the failing test for the Substack poster link**

```php
<?php

declare(strict_types=1);

namespace App\Tests\Service\Reader\Media;

use App\Service\Html\HtmlDocumentParser;
use App\Service\Reader\Media\SubstackPosterLink;
use PHPUnit\Framework\TestCase;

final class SubstackPosterLinkTest extends TestCase
{
    private SubstackPosterLink $rule;

    protected function setUp(): void
    {
        $this->rule = new SubstackPosterLink();
    }

    private function link(string $html): string
    {
        $document = HtmlDocumentParser::parseOrNull($html);
        self::assertNotNull($document);
        $this->rule->linkIn($document);

        return $document->saveHtml();
    }

    /** The poster URL carries the video id, so no re-fetch is needed. */
    public function testWrapsTheYouTubePosterInAWatchLink(): void
    {
        $out = $this->link(
            '<body><figure><img src="https://substackcdn.com/image/youtube/w_728,c_limit/_ipOL6Zq7Z8" alt=""></figure></body>'
        );

        self::assertStringContainsString('href="https://www.youtube-nocookie.com/embed/_ipOL6Zq7Z8"', $out);
    }

    /**
     * #627's gated placeholder inserts its own poster anchor before readability,
     * so an already-linked image belongs to that rule and must be left alone.
     */
    public function testLeavesAnImageThatIsAlreadyLinked(): void
    {
        $html = '<body><a href="https://example.test/post"><img '
            . 'src="https://substackcdn.com/image/youtube/w_728,c_limit/_ipOL6Zq7Z8" alt=""></a></body>';

        self::assertStringNotContainsString('youtube-nocookie', $this->link($html));
    }

    public function testLeavesAnOrdinarySubstackImage(): void
    {
        $html = '<body><img src="https://substackcdn.com/image/fetch/w_1456/photo.jpg" alt=""></body>';

        self::assertStringNotContainsString('youtube-nocookie', $this->link($html));
    }

    public function testLeavesAMalformedId(): void
    {
        $html = '<body><img src="https://substackcdn.com/image/youtube/w_728,c_limit/tooshort" alt=""></body>';

        self::assertStringNotContainsString('youtube-nocookie', $this->link($html));
    }
}
```

- [ ] **Step 6: Run it and confirm it fails**

```bash
cd backend && php bin/phpunit tests/Service/Reader/Media/SubstackPosterLinkTest.php
```

Expected: FAIL — class not found.

- [ ] **Step 7: Write `SubstackPosterLink`**

```php
<?php

declare(strict_types=1);

namespace App\Service\Reader\Media;

use Dom\Element;
use Dom\HTMLDocument;

/**
 * Substack strips the YouTube iframe but leaves its poster, and that poster's
 * URL contains the video id — so a dead thumbnail becomes a working link with
 * no re-fetch and no new host trust.
 *
 * An image already inside a link is skipped: #627's gated placeholder inserts
 * its own poster anchor before readability, and this rule must not touch it.
 */
final readonly class SubstackPosterLink
{
    private const string POSTER_PATTERN =
        '#^https://substackcdn\.com/image/youtube/[^/]+/([A-Za-z0-9_-]{11})$#';

    public function linkIn(HTMLDocument $body): void
    {
        foreach (iterator_to_array($body->getElementsByTagName('img')) as $image) {
            $this->linkOne($body, $image);
        }
    }

    private function linkOne(HTMLDocument $body, Element $image): void
    {
        $videoId = $this->videoId($image);
        if ($videoId === null || $image->parentNode === null) {
            return;
        }

        $link = $body->createElement('a');
        $link->setAttribute('href', 'https://www.youtube-nocookie.com/embed/' . $videoId);
        $image->parentNode->replaceChild($link, $image);
        $link->appendChild($image);
        $image->setAttribute('alt', 'Watch on YouTube');
    }

    private function videoId(Element $image): ?string
    {
        if ($image->closest('a') !== null) {
            return null;
        }

        return preg_match(self::POSTER_PATTERN, $image->getAttribute('src') ?? '', $m) === 1 ? $m[1] : null;
    }
}
```

- [ ] **Step 8: Run it and confirm it passes**

```bash
cd backend && php bin/phpunit tests/Service/Reader/Media/SubstackPosterLinkTest.php
```

Expected: PASS, 4 tests.

- [ ] **Step 9: Run the gates and commit**

```bash
cd backend && php bin/phpunit tests/Service/Reader/ && composer check && composer md
git add backend/src/Service/Reader/Media backend/tests/Service/Reader/Media
git commit -m "feat(#748): rewrite in-body embeds and link the Substack poster"
```

---

### Task 8: Wire the pipeline into the extractor

The two mechanisms meet here, plus the extraction gate change.

**Files:**
- Create: `backend/src/Service/Reader/Media/PageMediaInserter.php`
- Modify: `backend/src/Service/Reader/ReaderBodyCleaner.php`
- Modify: `backend/src/Service/Reader/ArticleExtractor.php`
- Test: `backend/tests/Service/Reader/Media/PageMediaInserterTest.php`
- Test: `backend/tests/Service/Reader/ReaderBodyCleanerTest.php` (modify)
- Test: `backend/tests/Service/Reader/ArticleExtractorTest.php` (modify)

**Interfaces:**
- Consumes: everything from Tasks 1, 5 and 7.
- Produces:
  - `final readonly class PageMediaInserter { public function insertInto(\Dom\HTMLDocument $body, ArticleMedia $media): void; }`
  - `ReaderBodyCleaner::clean(string $contentHtml, array $titleCandidates, LeadImageCandidate $leadImage, ArticleMedia $media): string` — **one new final parameter**, taking it from 3 to 4.

- [ ] **Step 1: Write the failing test for `PageMediaInserter`**

```php
<?php

declare(strict_types=1);

namespace App\Tests\Service\Reader\Media;

use App\Service\Html\HtmlDocumentParser;
use App\Service\Reader\Media\ArticleMedia;
use App\Service\Reader\Media\MediaCandidate;
use App\Service\Reader\Media\MediaKind;
use App\Service\Reader\Media\MediaMarkup;
use App\Service\Reader\Media\PageMediaInserter;
use PHPUnit\Framework\TestCase;

final class PageMediaInserterTest extends TestCase
{
    private PageMediaInserter $inserter;

    protected function setUp(): void
    {
        $this->inserter = new PageMediaInserter(new MediaMarkup());
    }

    private function insert(string $html, ArticleMedia $media): string
    {
        $document = HtmlDocumentParser::parseOrNull($html);
        self::assertNotNull($document);
        $this->inserter->insertInto($document, $media);

        return $document->saveHtml();
    }

    public function testPutsAudioAtTheTopAboveTheTeaser(): void
    {
        $media = new ArticleMedia([new MediaCandidate(MediaKind::Audio, 'https://x.test/a.mp3')]);

        $out = $this->insert('<body><p>Teaser</p></body>', $media);

        self::assertStringContainsString('<audio controls preload="none" src="https://x.test/a.mp3">', $out);
        self::assertLessThan(strpos($out, 'Teaser'), strpos($out, '<audio'));
    }

    public function testVideoCarriesItsPoster(): void
    {
        $media = new ArticleMedia([
            new MediaCandidate(MediaKind::Video, 'https://x.test/v.mp4', 'https://x.test/p.jpg'),
        ]);

        $out = $this->insert('<body><p>Teaser</p></body>', $media);

        self::assertStringContainsString('poster="https://x.test/p.jpg"', $out);
        self::assertStringContainsString('preload="none"', $out);
    }

    public function testAnEmbedBecomesTheSameLinkShapeAsAnInBodyOne(): void
    {
        $media = new ArticleMedia([new MediaCandidate(
            MediaKind::Embed,
            'https://w.soundcloud.com/player/?url=https%3A%2F%2Fapi.soundcloud.com%2Ftracks%2F1',
            null,
            'Listen on SoundCloud',
        )]);

        $out = $this->insert('<body><p>Teaser</p></body>', $media);

        self::assertStringContainsString('Listen on SoundCloud', $out);
        self::assertStringContainsString('w.soundcloud.com/player/', $out);
    }

    public function testEmptyMediaLeavesTheBodyAlone(): void
    {
        $out = $this->insert('<body><p>Teaser</p></body>', ArticleMedia::none());

        self::assertStringNotContainsString('<audio', $out);
        self::assertStringContainsString('Teaser', $out);
    }

    public function testKeepsSourceOrder(): void
    {
        $media = new ArticleMedia([
            new MediaCandidate(MediaKind::Audio, 'https://x.test/first.mp3'),
            new MediaCandidate(MediaKind::Audio, 'https://x.test/second.mp3'),
        ]);

        $out = $this->insert('<body><p>Teaser</p></body>', $media);

        self::assertLessThan(strpos($out, 'second.mp3'), strpos($out, 'first.mp3'));
    }
}
```

- [ ] **Step 2: Run it and confirm it fails**

```bash
cd backend && php bin/phpunit tests/Service/Reader/Media/PageMediaInserterTest.php
```

Expected: FAIL — class not found.

- [ ] **Step 3: Write `PageMediaInserter`**

```php
<?php

declare(strict_types=1);

namespace App\Service\Reader\Media;

use Dom\Element;
use Dom\HTMLDocument;

/**
 * Puts media the extracted body never had at the top of it.
 *
 * These candidates come from the source page, where position is not knowable —
 * and on the pages that need this most (a public-radio article that extracts to
 * a duration line and three teaser links) the media IS the article, so the top
 * is where it belongs. The existing prose is kept below, untouched.
 */
final readonly class PageMediaInserter
{
    public function __construct(private MediaMarkup $markup)
    {
    }

    public function insertInto(HTMLDocument $body, ArticleMedia $media): void
    {
        $root = $body->body;
        if ($root === null || $media->isEmpty()) {
            return;
        }

        foreach (array_reverse($media->candidates) as $candidate) {
            $root->insertBefore($this->element($body, $candidate), $root->firstChild);
        }
    }

    private function element(HTMLDocument $body, MediaCandidate $candidate): Element
    {
        return match ($candidate->kind) {
            MediaKind::Audio => $this->player($body, 'audio', $candidate),
            MediaKind::Video => $this->player($body, 'video', $candidate),
            MediaKind::Embed => $this->markup->embedLink(
                $body,
                new EmbedTarget($candidate->url, $candidate->posterUrl, $candidate->label ?? 'Open the media'),
            ),
        };
    }

    private function player(HTMLDocument $body, string $tag, MediaCandidate $candidate): Element
    {
        $player = $body->createElement($tag);
        $player->setAttribute('controls', '');
        // Never fetch megabytes for an article the reader may only be skimming.
        $player->setAttribute('preload', 'none');
        $player->setAttribute('src', $candidate->url);
        if ($candidate->posterUrl !== null) {
            $player->setAttribute('poster', $candidate->posterUrl);
        }

        return $player;
    }
}
```

Reversing the list and inserting each at position 0 preserves source order.

- [ ] **Step 4: Run it and confirm it passes**

```bash
cd backend && php bin/phpunit tests/Service/Reader/Media/PageMediaInserterTest.php
```

Expected: PASS, 5 tests.

- [ ] **Step 5: Add the failing `ReaderBodyCleaner` tests**

Add to `backend/tests/Service/Reader/ReaderBodyCleanerTest.php`. Update the existing helper that calls `clean()` to pass `ArticleMedia::none()` as the fourth argument.

```php
public function testRewritesAnInBodyEmbedAndKeepsItsPosition(): void
{
    $html = '<h3>One</h3><div><iframe src="https://www.youtube.com/embed/aaaaaaaaaaa"></iframe></div>'
        . '<p>' . str_repeat('prose ', 40) . '</p>';

    $out = $this->cleaner()->clean($html, [null, null], $this->noLead(), ArticleMedia::none());

    self::assertStringContainsString('youtube-nocookie.com/embed/aaaaaaaaaaa', $out);
    self::assertStringNotContainsString('<iframe', $out);
}

/**
 * A discovered embed is dropped when the body recovered its own, so the same
 * video never appears twice.
 */
public function testSuppressesDiscoveredEmbedsWhenTheBodyHadItsOwn(): void
{
    $html = '<div><iframe src="https://www.youtube.com/embed/aaaaaaaaaaa"></iframe></div>'
        . '<p>' . str_repeat('prose ', 40) . '</p>';
    $discovered = new ArticleMedia([
        new MediaCandidate(MediaKind::Embed, 'https://www.youtube-nocookie.com/embed/bbbbbbbbbbb', null, 'Watch'),
    ]);

    $out = $this->cleaner()->clean($html, [null, null], $this->noLead(), $discovered);

    self::assertStringContainsString('aaaaaaaaaaa', $out);
    self::assertStringNotContainsString('bbbbbbbbbbb', $out);
}

/** Audio is not an embed, so the suppression must not reach it. */
public function testKeepsDiscoveredAudioEvenWhenTheBodyHadAnEmbed(): void
{
    $html = '<div><iframe src="https://www.youtube.com/embed/aaaaaaaaaaa"></iframe></div>'
        . '<p>' . str_repeat('prose ', 40) . '</p>';
    $discovered = new ArticleMedia([new MediaCandidate(MediaKind::Audio, 'https://x.test/a.mp3')]);

    $out = $this->cleaner()->clean($html, [null, null], $this->noLead(), $discovered);

    self::assertStringContainsString('a.mp3', $out);
}
```

- [ ] **Step 6: Run them and confirm they fail**

```bash
cd backend && php bin/phpunit tests/Service/Reader/ReaderBodyCleanerTest.php
```

Expected: FAIL — `clean()` takes 3 arguments.

- [ ] **Step 7: Change `ReaderBodyCleaner`**

Add the three collaborators to the constructor and the fourth parameter to `clean()`. Replace the method body with:

```php
    /** @param list<string|null> $titleCandidates */
    public function clean(
        string $contentHtml,
        array $titleCandidates,
        LeadImageCandidate $leadImage,
        ArticleMedia $media,
    ): string {
        $document = HtmlDocumentParser::parseOrNull($contentHtml);
        if ($document === null) {
            return $contentHtml;
        }

        // Media first: a trimmer must not remove a block that now holds a
        // recovered player, and the lead-image restore must see a poster the
        // body has gained before it decides whether to add another picture.
        $recoveredInBody = $this->embedRewriter->rewriteIn($document);
        $this->substackPoster->linkIn($document);

        $this->navigationTrimmer->trimIn($document);
        $this->titleRemover->removeFrom($document, $titleCandidates);
        $this->boilerplateTrimmer->trimIn($document);
        $this->leadImage->restore($document, $leadImage);

        $this->mediaInserter->insertInto($document, $recoveredInBody ? $media->withoutEmbeds() : $media);

        return $document->saveHtml();
    }
```

Update the class docblock to name the media step.

- [ ] **Step 8: Run the cleaner tests and confirm they pass**

```bash
cd backend && php bin/phpunit tests/Service/Reader/ReaderBodyCleanerTest.php
```

Expected: PASS.

- [ ] **Step 9: Add the failing extractor test for the gate**

Add to `backend/tests/Service/Reader/ArticleExtractorTest.php`:

```php
/**
 * On a public-radio page the audio IS the article: the prose extracts to a
 * duration line and a few teaser links, under the length gate. Media found on
 * the page is enough to call it an article.
 */
public function testAMediaCandidateSatisfiesTheLengthGate(): void
{
    $page = '<html><head><title>Bildung</title></head><body><article>'
        . '<div data-audio-src="https://ondemand-mp3.dradio.de/file/dradio/2026/08/bildung.mp3"></div>'
        . '<p>85:29 Minuten. Ein kurzer Teasertext.</p>'
        . '</article></body></html>';

    $result = $this->extractorFor($page, 'https://www.deutschlandfunkkultur.de/bildung-100.html')
        ->extract('https://www.deutschlandfunkkultur.de/bildung-100.html');

    self::assertTrue($result->ok);
    self::assertStringContainsString('bildung.mp3', (string) $result->contentHtml);
}
```

- [ ] **Step 10: Run it and confirm it fails**

```bash
cd backend && php bin/phpunit tests/Service/Reader/ArticleExtractorTest.php
```

Expected: FAIL — the result is `failed` with reason `empty`.

- [ ] **Step 11: Change `ArticleExtractor`**

Inject `PageMediaScanner`. Scan before the length gate, let media satisfy it, and pass the result to `clean()`:

```php
        $normalized = $this->normalizer->normalize($page->html);
        $pageImages = PageImageInventory::fromDocument($normalized);
        $media = $this->mediaScanner->scan($page->html, $page->finalUrl);

        $article = $this->richestArticle($normalized, $page);
        if ($article === null) {
            return ExtractionResult::failed($url, 'unextractable');
        }

        if ($article->content === null || !$article->hasContent()) {
            return ExtractionResult::failed($url, 'empty');
        }
        // A page whose media IS the article carries little prose. Recovered media
        // is itself evidence that this is an article worth showing.
        if ($media->isEmpty() && mb_strlen(trim((string) $article->textContent)) < self::MIN_CONTENT_LENGTH) {
            return ExtractionResult::failed($url, 'empty');
        }

        $leadImage = new LeadImageCandidate($article->image, $pageImages);
        $body = $this->bodyCleaner->clean($article->content, [$article->title, $entryTitle], $leadImage, $media);
```

Remove the `GatedMediaContext` line if any trace of it remains. Update the class docblock to describe the media step.

- [ ] **Step 12: Run the whole reader suite and the gates**

```bash
cd backend && bin/console cache:warmup && php bin/phpunit tests/Service/Reader/ && composer check && composer md
```

Expected: all PASS. `composer tramp` must not report a new chain — `ArticleMedia` is a value the cleaner's steps read, not a parameter forwarded unread.

- [ ] **Step 13: Commit**

```bash
git add backend/src/Service/Reader backend/tests/Service/Reader
git commit -m "feat(#748): insert recovered media and let it satisfy the length gate"
```

---

### Task 9: Render embeds in the reader

The link becomes a real player here, and nowhere else.

**Files:**
- Create: `frontend/src/app/reader/media-embeds.ts`
- Create: `frontend/src/app/reader/media-embeds.spec.ts`
- Modify: `frontend/src/app/reader/reader-view/reader-view.component.ts`
- Modify: `frontend/src/app/reader/reader-view/reader-view.component.scss`
- Modify: `frontend/src/app/reader/reader-cache.service.ts`

**Interfaces:**
- Consumes: the anchor markup Tasks 7 and 8 produce.
- Produces: `export function upgradeMediaEmbeds(host: HTMLElement): void`

- [ ] **Step 1: Write the failing spec**

Create `frontend/src/app/reader/media-embeds.spec.ts`:

```ts
import { upgradeMediaEmbeds } from './media-embeds';

function host(html: string): HTMLElement {
  const el = document.createElement('div');
  el.innerHTML = html;
  upgradeMediaEmbeds(el);
  return el;
}

describe('upgradeMediaEmbeds', () => {
  it('replaces a YouTube link with a nocookie iframe', () => {
    const el = host('<a href="https://www.youtube-nocookie.com/embed/aaaaaaaaaaa"><img src="p.jpg"></a>');
    const frame = el.querySelector('iframe');

    expect(frame).not.toBeNull();
    expect(frame!.getAttribute('src')).toBe('https://www.youtube-nocookie.com/embed/aaaaaaaaaaa');
    expect(el.querySelector('a')).toBeNull();
  });

  it('applies the sandbox and referrer policy', () => {
    const el = host('<a href="https://www.youtube-nocookie.com/embed/aaaaaaaaaaa">x</a>');
    const frame = el.querySelector('iframe')!;

    expect(frame.getAttribute('sandbox')).toContain('allow-scripts');
    expect(frame.getAttribute('referrerpolicy')).toBe('strict-origin-when-cross-origin');
    expect(frame.getAttribute('loading')).toBe('lazy');
    expect(frame.getAttribute('allow') ?? '').not.toContain('autoplay');
  });

  it('replaces a SoundCloud player link', () => {
    const el = host(
      '<a href="https://w.soundcloud.com/player/?url=https%3A%2F%2Fapi.soundcloud.com%2Ftracks%2F2370150908">x</a>',
    );

    expect(el.querySelector('iframe')).not.toBeNull();
  });

  it('leaves an ordinary article link alone', () => {
    const el = host('<a href="https://example.test/story">Read this</a>');

    expect(el.querySelector('iframe')).toBeNull();
    expect(el.querySelector('a')).not.toBeNull();
  });

  it('leaves a link to a host that is not allow-listed', () => {
    const el = host('<a href="https://evil.test/embed/aaaaaaaaaaa">x</a>');

    expect(el.querySelector('iframe')).toBeNull();
  });

  it('rejects a look-alike host', () => {
    const el = host('<a href="https://www.youtube-nocookie.com.evil.test/embed/aaaaaaaaaaa">x</a>');

    expect(el.querySelector('iframe')).toBeNull();
  });

  it('is idempotent across repeated passes', () => {
    const el = document.createElement('div');
    el.innerHTML = '<a href="https://www.youtube-nocookie.com/embed/aaaaaaaaaaa">x</a>';
    upgradeMediaEmbeds(el);
    upgradeMediaEmbeds(el);

    expect(el.querySelectorAll('iframe').length).toBe(1);
  });
});
```

- [ ] **Step 2: Run it and confirm it fails**

```bash
docker compose exec -T frontend npx jest src/app/reader/media-embeds.spec.ts
```

Expected: FAIL — cannot resolve `./media-embeds`.

- [ ] **Step 3: Write `media-embeds.ts`**

```ts
/**
 * Turns a recovered media link into a real player.
 *
 * The backend cannot ship an `<iframe>`: its sanitizer is shared with feed
 * ingest, so allowing one there would let any feed inject an arbitrary frame,
 * and Angular's own sanitizer drops iframes from `[innerHTML]` regardless. So
 * the body carries a plain link and this pass upgrades it, building the element
 * itself from a URL it re-validates. Angular's sanitizer stays on.
 *
 * Because the link is what gets cached and the upgrade happens at render,
 * dropping a provider takes effect on articles that are already in the cache.
 *
 * Runs in the reader's post-render pass beside markInsetCards. Idempotent: the
 * anchor is gone after the first pass, and a re-render replaces the whole body.
 */
const ALLOWED = [
  /^https:\/\/www\.youtube-nocookie\.com\/embed\/[A-Za-z0-9_-]{11}$/,
  /^https:\/\/w\.soundcloud\.com\/player\/\?url=https%3A%2F%2Fapi\.soundcloud\.com%2Ftracks%2F\d+$/,
  /^https:\/\/player\.vimeo\.com\/video\/\d+$/,
  /^https:\/\/open\.spotify\.com\/embed\/(track|album|playlist|episode|show)\/[A-Za-z0-9]{22}$/,
  /^https:\/\/www\.mixcloud\.com\/widget\/iframe\/\?feed=[A-Za-z0-9%._-]+$/,
  /^https:\/\/bandcamp\.com\/EmbeddedPlayer\/[A-Za-z0-9=/_-]+$/,
];

/* `allow-same-origin` beside `allow-scripts` is safe only because every allowed
   URL is cross-origin: the frame gets its own origin and cannot reach the
   reader. Never add a same-origin URL to ALLOWED. */
const SANDBOX = 'allow-scripts allow-same-origin allow-presentation';

export function upgradeMediaEmbeds(host: HTMLElement): void {
  for (const anchor of Array.from(host.querySelectorAll('a'))) {
    const url = anchor.getAttribute('href') ?? '';
    if (!ALLOWED.some((pattern) => pattern.test(url))) continue;
    anchor.replaceWith(embedFrame(url, anchor.textContent?.trim() || 'Embedded media'));
  }
}

function embedFrame(url: string, title: string): HTMLElement {
  const box = document.createElement('div');
  box.className = 'reader-embed';

  const frame = document.createElement('iframe');
  frame.setAttribute('src', url);
  frame.setAttribute('title', title);
  frame.setAttribute('loading', 'lazy');
  frame.setAttribute('referrerpolicy', 'strict-origin-when-cross-origin');
  frame.setAttribute('sandbox', SANDBOX);
  frame.setAttribute('allowfullscreen', '');
  box.appendChild(frame);

  return box;
}
```

Remove from `ALLOWED` any provider Task 3 dropped. A pattern with no backend provider behind it is dead trust surface.

- [ ] **Step 4: Run it and confirm it passes**

```bash
docker compose exec -T frontend npx jest src/app/reader/media-embeds.spec.ts
```

Expected: PASS, 7 tests.

- [ ] **Step 5: Call it from the reader's post-render pass**

In `reader-view.component.ts`, add the import beside the others:

```ts
import { upgradeMediaEmbeds } from '../media-embeds';
```

and call it in the existing post-render effect, immediately after `markInsetCards(host);`:

```ts
        markInsetCards(host);
        upgradeMediaEmbeds(host);
```

It runs after the loop that sets `target="_blank"` on links, which is harmless: an upgraded anchor is replaced outright.

- [ ] **Step 6: Style the embed and the players**

Append to `reader-view.component.scss`, using existing tokens only — no hex, no raw `px`:

```scss
/* Recovered media (#748). The embed box keeps a 16:9 frame at any width; the
   players stretch to the column so controls stay reachable on a phone. */
.content ::ng-deep .reader-embed {
  position: relative;
  width: 100%;
  aspect-ratio: 16 / 9;
  margin: var(--space-5) 0;
}

.content ::ng-deep .reader-embed iframe {
  position: absolute;
  inset: 0;
  width: 100%;
  height: 100%;
  border: 0;
}

.content ::ng-deep audio,
.content ::ng-deep video {
  display: block;
  width: 100%;
  margin: var(--space-5) 0;
}
```

`--space-5` is what the surrounding figure and blockquote rules already use for a block-level gap in this file; keep it consistent with its neighbours.

- [ ] **Step 7: Bump the reader cache version**

Cached bodies were extracted before media recovery existed, so they must be dropped.

```bash
grep -n 'VERSION = ' frontend/src/app/reader/reader-cache.service.ts
```

Increment whatever it reads by one. At the time of writing #627 had taken it to `7`, so this becomes `8`. Do not assume — read it first.

- [ ] **Step 8: Run the full frontend gate**

```bash
docker compose exec -T frontend npm run check
```

Expected: PASS. Stylelint fails on a hex colour or a raw `px`; fix by using a token.

- [ ] **Step 9: Commit**

```bash
git add frontend/src/app/reader
git commit -m "feat(#748): upgrade recovered media links to players in the reader"
```

---

### Task 10: Verify against the real pipeline

Gates green is not the deliverable. This task proves the ten measured articles actually changed.

**Files:**
- Modify: `docs/superpowers/plans/2026-08-31-748-evidence.md` (append the results)

**Interfaces:**
- Consumes: everything.
- Produces: the evidence for the PR body.

- [ ] **Step 1: Bring the stack up to date**

The worker and the PHP container hold code from boot, and a bind-mounted edit does not reload them.

```bash
docker compose up -d --build php frontend
docker compose restart nginx worker
docker compose exec -T php bin/console cache:clear
```

`nginx` is restarted because recreating `php` leaves it pointing at the old container IP, and every `/api` call then 502s.

- [ ] **Step 2: Run both legs of the suite**

```bash
cd backend && php bin/phpunit
docker compose exec -T php vendor/bin/phpunit
docker compose exec -T frontend npm run check
```

Expected: PASS on all three.

- [ ] **Step 3: Check each measured entry in the running app**

For each of 465658, 489867, 487567, 481606, 465683, 488630, 489854, 491093, 491483, 489312 and 490933, open the reader and record what changed. Confirm specifically:

- 465658 (OZORA): ten playable embeds, each under its own `h3`, and **no empty containers**.
- 465683 (5 Magazine): the SoundCloud player is present.
- 481606 (rushkoff): the poster is a working link and is **not** double-wrapped.
- 488630 / 489854 / 491093: an `<audio>` control above the teaser, and it plays.
- 491483 / 489312 / 490933: a `<video>` with a visible poster.
- 489867 (NPR): the video and the companion audio both appear.

- [ ] **Step 4: Scan the dev log**

```bash
tail -n 200 "$(ls -t backend/var/log/dev-*.log | head -1)"
```

Expected: no new deprecations and no swallowed exceptions from the media classes.

- [ ] **Step 5: Re-run the reader audit**

```bash
docker compose exec -T php bin/console app:reader:audit
```

Compare against the #746 baseline of 17 findings across 9 feeds. The count must not rise. A new finding means a cleaner is removing something the media rules added.

- [ ] **Step 6: Run mutation testing on the diff**

```bash
cd backend && composer infection:diff
```

Expected: at or above `minMsi` in `infection.json5`. Escaped mutants arrive as PR annotations; kill them with a test rather than lowering the gate.

- [ ] **Step 7: Record the results and commit**

Append a before-and-after line per entry to the evidence file.

```bash
git add docs/superpowers/plans/2026-08-31-748-evidence.md
git commit -m "docs(#748): record the verification sweep across the measured entries"
```

- [ ] **Step 8: Open the pull request**

```bash
gh pr create --base develop \
  --title "Reader: recover the media the extraction drops (#748)" \
  --body "Closes #748, closes #750."
```

The body should carry the before-and-after table from Step 3, the providers that shipped and those Task 3 dropped, and the note that `EntrySanitizer` was deliberately not modified.

---

## Self-Review

**Spec coverage.** R1a → Task 7. R1b → Tasks 5, 6, 8. R2 durability → Task 1 (`DurableMediaUrl`), with D4's no-probe rule honoured because every adapter strips its own query. R3 rendering → Tasks 7, 8, 9. R4 exclusions → Task 1 (`/tts/`, live streams) and Task 5 (Deutschlandradio takes only the first, so teasers are excluded). D1 raw-source discovery → Task 5. D2 link-then-upgrade → Tasks 7, 9. D3 uniform embed href → Task 2. D5 mandatory ARD poster → Task 6. D6 cap of 20 → Task 1. D7 gate → Task 8. D8 fixture-gated providers → Tasks 3, 4. The `PageEmbedSource` suppression guard → Tasks 5, 8. The alt-text collision with #627 → Global Constraints and Task 7's last test. Native §6 → no endpoint is touched by any task.

**Placeholders.** Task 3 ships no production code by design; its output is fixtures and a documented go/no-go, and every step says exactly what to run and what to decide. Task 4's per-provider rules are given as concrete host, path and id specifications rather than as code for four providers that may not survive Task 3. No step says "add error handling" or "write tests for the above".

**Type consistency.** `MediaKind`, `MediaCandidate(kind, url, posterUrl, label)`, `ArticleMedia(candidates)` with `MAX_ITEMS`, `none()`, `isEmpty()`, `withoutEmbeds()`, `DurableMediaUrl::accepts()`, `EmbedProviderInterface::{matches,normalize,poster,label}`, `EmbedTarget(url, posterUrl, label)`, `EmbedProviders::resolve()`, `MediaCandidateSourceInterface::find()`, `PageMediaScanner::scan()`, `MediaMarkup::embedLink()`, `InBodyEmbedRewriter::rewriteIn()` returning `bool`, `SubstackPosterLink::linkIn()`, `PageMediaInserter::insertInto()`, and `ReaderBodyCleaner::clean()` at four parameters are used identically wherever they appear. Tags `app.embed_provider` and `app.media_candidate_source` match their `_instanceof` entries and their wiring tests.

**One dependency to respect.** Task 3 gates Tasks 4 and 6. Do not write a provider or a source for a host whose page was never captured.
