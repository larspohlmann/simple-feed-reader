# Host-agnostic media discovery (#755) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Replace #748's three host-keyed candidate sources with ordered, host-agnostic layers, so any page with the same markup yields its media without new code.

**Architecture:** One shared `MediaUrlKind` resolver decides what a URL *is*. Layers behind the existing `MediaCandidateSourceInterface` find candidates by descending declaration strength, tagged with `#[AutoconfigureTag]` priority like `ScrapeLayerInterface`. `PageMediaScanner` takes the highest-priority non-empty result **per media kind**. A soft relevance ranker replaces the host adapters' fiat about which file belongs to the article.

**Tech Stack:** PHP 8.4, Symfony 7.4, `\Dom\HTMLDocument`, PHPUnit.

**Spec:** GitHub issue #755. Background design: `docs/superpowers/specs/2026-08-31-748-reader-media-recovery-design.md`.

## Global Constraints

- **Work in `feature/748-reader-media-recovery`.** No new branch. #748 and #755 ship in one PR closing #748, #750 and #755.
- #748's final whole-branch review did **not** run. Treat the code under you as unreviewed: read it before changing it, and if something looks wrong, say so rather than building on it.
- Commits are `type(#755): summary`.
- `declare(strict_types=1);` everywhere. PSR-12. `final readonly class` with promotion.
- Comments: default to none. One line, three at the most, and only for the *why*.
- **Do not touch `EntrySanitizer`.** Shared with feed ingest.
- **Do not generalize `EmbedProviderInterface`.** A provider answers "may I frame this URL and what is its canonical form" — inherently per-service, and a security boundary. Only *candidate sources* must stop being host-keyed.
- **Do not change `DurableMediaUrl`'s rules.** They are already host-agnostic and stay a hard filter.
- **Do not change anything downstream of `PageMediaScanner`.** `ReaderBodyCleaner`, `PageMediaInserter`, `InBodyEmbedRewriter`, `SubstackPosterLink`, `MediaMarkup` and the frontend are all out of scope.
- Every `src` file you touch must pass `composer check` and `composer md`.
- Another session may share this checkout. Check before any `checkout`, `reset` or `stash`.

## Starting state

Already implemented in the branch, and staying:

| Class | Status |
|---|---|
| `DurableMediaUrl` | keep unchanged — hard exclusions, no host knowledge |
| `EmbedProviders`, `Provider/YouTube…`, `Provider/SoundCloud…` | keep unchanged |
| `Source/PageEmbedSource` | **already host-agnostic** — keep, only gains a priority |
| `MediaCandidate`, `MediaKind`, `ArticleMedia`, `EmbedTarget`, `MediaMarkup` | keep |
| `Source/DeutschlandradioAudioSource` | **delete in Task 8** |
| `Source/NprAudioSource` | **delete in Task 8** |
| `Source/ArdVideoSource` | **delete in Task 8** |

What each measured fixture needs, so you can tell when a layer is done. Verified against `backend/tests/Fixtures/reader/media/`:

| Fixture | Generic signal that reaches it | Layer |
|---|---|---|
| heise-video | JSON-LD `VideoObject.contentUrl` = `…watch?v=M1j_uRqKMKI` (escaped slashes) | 1 — also already reached by `PageEmbedSource` |
| soundcloud-page | a real `<iframe>`; its JSON-LD `contentUrl`s are **images** | `PageEmbedSource` |
| ard-video | `og:video` is a **player page**, not a file; the MP4 is in a `data-v` attribute; poster from `og:image` | 4 |
| deutschlandradio-audio | `data-audio-src` ×12, and a `data-json` blob with `audioUrl` and `"__typename":"Audio"` beside a `"Stream"` one | 4 |
| npr-audio | a plain `<a href>` ending `.mp3` with an analytics query | 5 |

---

### Task 1: The shared "what is this URL?" resolver

Every layer needs the same answer, and it is what stops a layer emitting a player page or a thumbnail as if it were media. Build it first.

**Files:**
- Create: `backend/src/Service/Reader/Media/MediaUrlKind.php`
- Test: `backend/tests/Service/Reader/Media/MediaUrlKindTest.php`

**Interfaces:**
- Consumes: `MediaKind`, `DurableMediaUrl`, `EmbedProviders`.
- Produces: `final readonly class MediaUrlKind { public function of(string $url): ?MediaKind; }` — `null` when the URL is not playable media.

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace App\Tests\Service\Reader\Media;

use App\Service\Reader\Media\DurableMediaUrl;
use App\Service\Reader\Media\EmbedProviders;
use App\Service\Reader\Media\MediaKind;
use App\Service\Reader\Media\MediaUrlKind;
use App\Service\Reader\Media\Provider\SoundCloudEmbedProvider;
use App\Service\Reader\Media\Provider\YouTubeEmbedProvider;
use PHPUnit\Framework\TestCase;

final class MediaUrlKindTest extends TestCase
{
    private MediaUrlKind $kind;

    protected function setUp(): void
    {
        $this->kind = new MediaUrlKind(
            new DurableMediaUrl(),
            new EmbedProviders([new YouTubeEmbedProvider(), new SoundCloudEmbedProvider()]),
        );
    }

    public function testRecognisesAudioByExtension(): void
    {
        self::assertSame(MediaKind::Audio, $this->kind->of('https://x.test/a.mp3'));
        self::assertSame(MediaKind::Audio, $this->kind->of('https://x.test/a.m4a'));
    }

    public function testRecognisesVideoByExtension(): void
    {
        self::assertSame(MediaKind::Video, $this->kind->of('https://x.test/v.mp4'));
    }

    public function testRecognisesAnEmbedByProvider(): void
    {
        self::assertSame(MediaKind::Embed, $this->kind->of('https://www.youtube.com/watch?v=M1j_uRqKMKI'));
    }

    /**
     * The trap ARD sets: og:video points at a player PAGE. Treating that as
     * video would put an HTML document in a <video src>.
     */
    public function testRejectsAPlayerPage(): void
    {
        self::assertNull($this->kind->of('https://www.tagesschau.de/video/video-1640158~player.html'));
    }

    public function testRejectsAnImage(): void
    {
        self::assertNull($this->kind->of('https://x.test/photo.jpg'));
    }

    public function testRejectsAnHlsPlaylist(): void
    {
        self::assertNull($this->kind->of('https://x.test/master.m3u8'));
    }

    /** A query is stripped before the extension is read, then re-checked. */
    public function testStripsAQueryBeforeJudging(): void
    {
        self::assertSame(MediaKind::Audio, $this->kind->of('https://x.test/a.mp3?t=progseg&sc=siteplayer'));
    }

    /** DurableMediaUrl's exclusions still bind: narration is not this article. */
    public function testRejectsMachineNarration(): void
    {
        self::assertNull($this->kind->of('https://x.test/tts/a-OnyxTurboMultilingualNeural.mp3'));
    }

    public function testRejectsALiveStream(): void
    {
        self::assertNull($this->kind->of('https://st01.sslstream.dlf.de/dlf/01/128/mp3/stream.mp3'));
    }
}
```

- [ ] **Step 2: Run it and confirm it fails**

```bash
cd backend && php bin/phpunit tests/Service/Reader/Media/MediaUrlKindTest.php
```

Expected: FAIL — class not found.

- [ ] **Step 3: Write `MediaUrlKind`**

```php
<?php

declare(strict_types=1);

namespace App\Service\Reader\Media;

/**
 * What a URL is, if it is playable media at all.
 *
 * Every layer asks this instead of carrying its own idea of a media URL, which
 * is what keeps a player page, a poster image or an HLS playlist from being
 * emitted as a file. Analytics parameters are stripped before judging, then the
 * bare URL must still satisfy DurableMediaUrl — so a real signature, which does
 * not survive stripping, is refused.
 */
final readonly class MediaUrlKind
{
    private const array AUDIO_EXTENSIONS = ['mp3', 'm4a', 'aac', 'oga', 'ogg', 'opus', 'wav', 'flac'];
    private const array VIDEO_EXTENSIONS = ['mp4', 'm4v', 'webm', 'mov'];

    public function __construct(
        private DurableMediaUrl $durable,
        private EmbedProviders $providers,
    ) {
    }

    public function of(string $url): ?MediaKind
    {
        if ($this->providers->resolve($url) !== null) {
            return MediaKind::Embed;
        }

        $bare = $this->withoutQuery($url);
        if ($bare === null || !$this->durable->accepts($bare)) {
            return null;
        }

        return $this->byExtension($bare);
    }

    private function withoutQuery(string $url): ?string
    {
        $parts = parse_url($url);
        if (!isset($parts['scheme'], $parts['host'], $parts['path'])) {
            return null;
        }

        return $parts['scheme'] . '://' . $parts['host'] . $parts['path'];
    }

    private function byExtension(string $bareUrl): ?MediaKind
    {
        $extension = strtolower(pathinfo(parse_url($bareUrl, \PHP_URL_PATH) ?? '', \PATHINFO_EXTENSION));

        return match (true) {
            \in_array($extension, self::AUDIO_EXTENSIONS, true) => MediaKind::Audio,
            \in_array($extension, self::VIDEO_EXTENSIONS, true) => MediaKind::Video,
            default => null,
        };
    }
}
```

Note the query strip happens here, so no layer needs its own. `DurableMediaUrl` still refuses anything with a surviving query, which is why a genuinely signed URL cannot slip through: strip its signature and the bare path is a different resource, but the guard never sees the tokenized form at all.

- [ ] **Step 4: Run it and confirm it passes**

```bash
cd backend && php bin/phpunit tests/Service/Reader/Media/MediaUrlKindTest.php
```

Expected: PASS, 9 tests.

- [ ] **Step 5: Commit**

```bash
git add backend/src/Service/Reader/Media/MediaUrlKind.php backend/tests/Service/Reader/Media/MediaUrlKindTest.php
git commit -m "feat(#755): shared resolver for what a media URL actually is"
```

---

### Task 2: The relevance ranker

Replaces "the first `data-audio-src` on a Deutschlandradio page" with a rule that names no host.

**Files:**
- Create: `backend/src/Service/Reader/Media/MediaRelevance.php`
- Test: `backend/tests/Service/Reader/Media/MediaRelevanceTest.php`

**Interfaces:**
- Consumes: nothing.
- Produces: `final readonly class MediaRelevance { /** @param list<string> $urls @return list<string> */ public function rank(array $urls, string $pageUrl): array; }` — same URLs, best first, ties keeping source order.

- [ ] **Step 1: Write the failing test**

The two real cases are the point: both were verified by hand in #748 and then thrown away in favour of "take the first".

```php
<?php

declare(strict_types=1);

namespace App\Tests\Service\Reader\Media;

use App\Service\Reader\Media\MediaRelevance;
use PHPUnit\Framework\TestCase;

final class MediaRelevanceTest extends TestCase
{
    private MediaRelevance $relevance;

    protected function setUp(): void
    {
        $this->relevance = new MediaRelevance();
    }

    /** Deutschlandradio: the page slug and the episode filename share "bildung". */
    public function testPrefersAFileWhoseNameEchoesThePageSlug(): void
    {
        $ranked = $this->relevance->rank(
            [
                'https://ondemand-mp3.dradio.de/file/dradio/2026/08/29/teaser_something_else.mp3',
                'https://ondemand-mp3.dradio.de/file/dradio/2026/08/29/bildung_wie_kann_schule_besser_werden.mp3',
            ],
            'https://www.deutschlandfunkkultur.de/bildung-100.html',
        );

        self::assertStringContainsString('bildung_wie_kann', $ranked[0]);
    }

    /** NPR: the slug says "telescope", and so does the segment filename. */
    public function testMatchesOnASharedTokenNotTheWholeSlug(): void
    {
        $ranked = $this->relevance->rank(
            [
                'https://ondemand.npr.org/anon.npr-mp3/npr/wesun/unrelated_promo.mp3',
                'https://ondemand.npr.org/anon.npr-mp3/npr/wesun/new_telescope_will_help_scientists.mp3',
            ],
            'https://www.npr.org/2026/08/30/nx-s1-5948814/launch-nancy-grace-roman-space-telescope-nasa',
        );

        self::assertStringContainsString('telescope', $ranked[0]);
    }

    /** Ranking is soft: nothing is dropped, so a no-match page still gets media. */
    public function testKeepsEveryCandidateEvenWhenNothingMatches(): void
    {
        $urls = ['https://x.test/one.mp3', 'https://x.test/two.mp3'];

        self::assertCount(2, $this->relevance->rank($urls, 'https://x.test/article-100.html'));
    }

    public function testATieKeepsSourceOrder(): void
    {
        $urls = ['https://x.test/first.mp3', 'https://x.test/second.mp3'];

        self::assertSame($urls, $this->relevance->rank($urls, 'https://x.test/article-100.html'));
    }

    /** Short and numeric slug parts are noise and must not decide the ranking. */
    public function testIgnoresShortAndNumericSlugTokens(): void
    {
        $ranked = $this->relevance->rank(
            ['https://x.test/100-promo.mp3', 'https://x.test/schule-episode.mp3'],
            'https://x.test/schule-wie-100.html',
        );

        self::assertStringContainsString('schule-episode', $ranked[0]);
    }
}
```

- [ ] **Step 2: Run it and confirm it fails**

```bash
cd backend && php bin/phpunit tests/Service/Reader/Media/MediaRelevanceTest.php
```

Expected: FAIL — class not found.

- [ ] **Step 3: Write `MediaRelevance`**

```php
<?php

declare(strict_types=1);

namespace App\Service\Reader\Media;

/**
 * Orders media candidates by how much their URL looks like this article's.
 *
 * Publishers name a file after the piece it belongs to, so a token shared with
 * the page slug is evidence — this is the check #748 used by hand to confirm its
 * host rules were picking the right file, promoted to being the rule.
 *
 * Deliberately soft: it reorders and never drops, so a publisher whose filenames
 * do not echo the slug still gets its media. Dropping is DurableMediaUrl's job.
 */
final readonly class MediaRelevance
{
    /** Below this length a token is noise ("de", "der", "the"). */
    private const int MIN_TOKEN_LENGTH = 4;

    /**
     * @param list<string> $urls
     *
     * @return list<string>
     */
    public function rank(array $urls, string $pageUrl): array
    {
        $slugTokens = $this->tokens(parse_url($pageUrl, \PHP_URL_PATH) ?? '');
        if ($slugTokens === []) {
            return $urls;
        }

        $ordered = $urls;
        usort($ordered, fn (string $a, string $b): int => $this->score($b, $slugTokens) <=> $this->score($a, $slugTokens));

        return $ordered;
    }

    /** @param list<string> $slugTokens */
    private function score(string $url, array $slugTokens): int
    {
        $urlTokens = $this->tokens(parse_url($url, \PHP_URL_PATH) ?? '');

        return \count(array_intersect($slugTokens, $urlTokens));
    }

    /** @return list<string> */
    private function tokens(string $path): array
    {
        $words = preg_split('#[^a-z0-9]+#i', strtolower($path)) ?: [];
        $keep = array_filter(
            $words,
            static fn (string $w): bool => \strlen($w) >= self::MIN_TOKEN_LENGTH && !ctype_digit($w),
        );

        return array_values(array_unique($keep));
    }
}
```

`usort` is not stable across equal scores in every PHP build, so if the tie test fails, sort on `[score, originalIndex]` pairs instead and unwrap afterwards.

- [ ] **Step 4: Run it and confirm it passes**

```bash
cd backend && php bin/phpunit tests/Service/Reader/Media/MediaRelevanceTest.php
```

Expected: PASS, 5 tests.

- [ ] **Step 5: Commit**

```bash
git add backend/src/Service/Reader/Media/MediaRelevance.php backend/tests/Service/Reader/Media/MediaRelevanceTest.php
git commit -m "feat(#755): rank media candidates by slug correlation instead of host fiat"
```

---

### Task 3: Priority ordering, and highest-declared wins per kind

Turn the interface into an ordered set and teach the scanner to prefer declarations over discoveries. Also fix two docblocks that assert something the fixtures disprove.

**Files:**
- Modify: `backend/src/Service/Reader/Media/MediaCandidateSourceInterface.php`
- Modify: `backend/src/Service/Reader/Media/PageMediaScanner.php`
- Modify: `backend/src/Service/Reader/Media/Source/PageEmbedSource.php` (priority attribute only)
- Modify: `backend/config/services.yaml` (remove the `_instanceof` entry)
- Test: `backend/tests/Service/Reader/Media/PageMediaScannerTest.php` (create)
- Test: `backend/tests/Service/Reader/Media/PageMediaScannerWiringTest.php` (keep passing)

**Interfaces:**
- Consumes: `MediaCandidateSourceInterface`.
- Produces: the same interface, now carrying `#[AutoconfigureTag('app.media_candidate_source')]`; `PageMediaScanner::scan()` unchanged in signature.

- [ ] **Step 1: Write the failing scanner test**

```php
<?php

declare(strict_types=1);

namespace App\Tests\Service\Reader\Media;

use App\Service\Reader\Media\ArticleMedia;
use App\Service\Reader\Media\MediaCandidate;
use App\Service\Reader\Media\MediaCandidateSourceInterface;
use App\Service\Reader\Media\MediaKind;
use App\Service\Reader\Media\PageMediaScanner;
use PHPUnit\Framework\TestCase;

final class PageMediaScannerTest extends TestCase
{
    /** @param list<MediaCandidate> $candidates */
    private function source(array $candidates): MediaCandidateSourceInterface
    {
        return new class($candidates) implements MediaCandidateSourceInterface {
            /** @param list<MediaCandidate> $candidates */
            public function __construct(private readonly array $candidates)
            {
            }

            public function find(string $pageHtml, string $pageUrl): array
            {
                return $this->candidates;
            }
        };
    }

    /** A declared file beats a scanned one: the first layer to yield a kind owns it. */
    public function testTheFirstSourceToYieldAKindWins(): void
    {
        $scanner = new PageMediaScanner([
            $this->source([new MediaCandidate(MediaKind::Audio, 'https://x.test/declared.mp3')]),
            $this->source([new MediaCandidate(MediaKind::Audio, 'https://x.test/scanned.mp3')]),
        ]);

        $media = $scanner->scan('<html></html>', 'https://x.test/a');

        self::assertCount(1, $media->candidates);
        self::assertStringContainsString('declared', $media->candidates[0]->url);
    }

    /** Kinds are independent, so NPR keeps both its video embed and its audio. */
    public function testADifferentKindStillComesThroughALaterSource(): void
    {
        $scanner = new PageMediaScanner([
            $this->source([new MediaCandidate(MediaKind::Embed, 'https://www.youtube-nocookie.com/embed/aaaaaaaaaaa')]),
            $this->source([new MediaCandidate(MediaKind::Audio, 'https://x.test/companion.mp3')]),
        ]);

        $media = $scanner->scan('<html></html>', 'https://x.test/a');

        self::assertCount(2, $media->candidates);
    }

    /** OZORA: many embeds from one source all survive; only later SOURCES lose. */
    public function testOneSourceMayYieldManyOfAKind(): void
    {
        $scanner = new PageMediaScanner([
            $this->source([
                new MediaCandidate(MediaKind::Embed, 'https://www.youtube-nocookie.com/embed/aaaaaaaaaaa'),
                new MediaCandidate(MediaKind::Embed, 'https://www.youtube-nocookie.com/embed/bbbbbbbbbbb'),
            ]),
        ]);

        self::assertCount(2, $scanner->scan('<html></html>', 'https://x.test/a')->candidates);
    }

    public function testTheCapStillApplies(): void
    {
        $many = [];
        for ($i = 0; $i < ArticleMedia::MAX_ITEMS + 5; $i++) {
            $many[] = new MediaCandidate(MediaKind::Embed, 'https://x.test/e' . $i);
        }

        $scanner = new PageMediaScanner([$this->source($many)]);

        self::assertCount(ArticleMedia::MAX_ITEMS, $scanner->scan('<html></html>', 'https://x.test/a')->candidates);
    }
}
```

- [ ] **Step 2: Run it and confirm it fails**

```bash
cd backend && php bin/phpunit tests/Service/Reader/Media/PageMediaScannerTest.php
```

Expected: FAIL — the current scanner merges every source, so `testTheFirstSourceToYieldAKindWins` sees 2 candidates.

- [ ] **Step 3: Change the interface and the scanner**

In `MediaCandidateSourceInterface.php`, replace the docblock and add the tag:

```php
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * One host-agnostic way to find the media a page offers. Implementations are
 * collected in AsTaggedItem priority order, highest first, and the first one to
 * yield a given MediaKind owns it — so a publisher's own declaration outranks
 * anything discovered by scanning.
 *
 * Reads the RAW page, not FetchedPageNormalizer's document: that pass is tuned
 * for readability scoring, removes elements, and is free to change again.
 *
 * Mirrors Service/Scraper/Layer/ScrapeLayerInterface, which solves the same
 * problem shape for feedless pages.
 */
#[AutoconfigureTag('app.media_candidate_source')]
interface MediaCandidateSourceInterface
{
    /** @return list<MediaCandidate> */
    public function find(string $pageHtml, string $pageUrl): array;
}
```

Delete the `App\Service\Reader\Media\MediaCandidateSourceInterface` entry from the `_instanceof` block in `backend/config/services.yaml`. The attribute replaces it.

Rewrite `PageMediaScanner::scan()`:

```php
    public function scan(string $pageHtml, string $pageUrl): ArticleMedia
    {
        $byKind = [];
        foreach ($this->sources as $source) {
            $this->collect($source->find($pageHtml, $pageUrl), $byKind);
        }

        $found = array_merge(...array_values($byKind)) ?: [];

        return new ArticleMedia(\array_slice($found, 0, ArticleMedia::MAX_ITEMS));
    }

    /**
     * @param list<MediaCandidate>                        $candidates
     * @param array<string, list<MediaCandidate>>         $byKind
     */
    private function collect(array $candidates, array &$byKind): void
    {
        foreach ($candidates as $candidate) {
            // The first source to supply a kind owns it: a later, weaker layer
            // must not append a scanned file beside a declared one.
            if (isset($byKind[$candidate->kind->value]) && !$this->sameSourceRun($byKind, $candidate)) {
                continue;
            }
            $byKind[$candidate->kind->value][$candidate->url] = $candidate;
        }
    }
```

Simplify rather than copy this if you see a cleaner shape — the required behaviour is exactly what the four tests state. A straightforward version: build `$byKind` per source into a local array, then for each kind, keep it only if that kind is not already present.

Update the `PageMediaScanner` class docblock: delete the sentence claiming ARD keeps renditions in player JSON. It is false — they are in a `data-v` attribute. Replace the reason with: the normalization pass is tuned for readability scoring and removes elements, so discovery must not depend on it.

Add `#[AsTaggedItem(priority: 80)]` to `PageEmbedSource`.

- [ ] **Step 4: Run the scanner tests and the wiring test**

```bash
cd backend && bin/console cache:warmup && php bin/phpunit tests/Service/Reader/Media/
```

Expected: PASS. The three host sources still work — they are untouched until Task 8.

- [ ] **Step 5: Commit**

```bash
git add backend/src/Service/Reader/Media backend/tests/Service/Reader/Media backend/config/services.yaml
git commit -m "feat(#755): order candidate sources by priority; declared media wins per kind"
```

---

### Task 4: The declared layers — JSON-LD and meta

Highest priority, because a publisher naming its media is the strongest signal there is.

**Files:**
- Create: `backend/src/Service/Reader/Media/Source/JsonLdMediaSource.php`
- Create: `backend/src/Service/Reader/Media/Source/MetaMediaSource.php`
- Test: `backend/tests/Service/Reader/Media/Source/JsonLdMediaSourceTest.php`
- Test: `backend/tests/Service/Reader/Media/Source/MetaMediaSourceTest.php`

**Interfaces:**
- Consumes: `MediaUrlKind` (Task 1), `MediaCandidateSourceInterface`.
- Produces: two tagged sources at priority 100 and 90.

- [ ] **Step 1: Write the failing JSON-LD test**

```php
public function testTakesContentUrlFromAVideoObject(): void
{
    $html = '<html><body><script type="application/ld+json">'
        . '{"@type":"VideoObject","contentUrl":"https:\\/\\/www.youtube.com\\/watch?v=M1j_uRqKMKI"}'
        . '</script></body></html>';

    $found = $this->source->find($html, 'https://www.heise.de/news/x.html');

    self::assertCount(1, $found);
    self::assertSame(MediaKind::Embed, $found[0]->kind);
    self::assertSame('https://www.youtube-nocookie.com/embed/M1j_uRqKMKI', $found[0]->url);
}

public function testFindsAVideoObjectNestedUnderAnArticle(): void
{
    $html = '<html><body><script type="application/ld+json">'
        . '{"@type":"NewsArticle","video":{"@type":"VideoObject","contentUrl":"https://x.test/v.mp4"}}'
        . '</script></body></html>';

    self::assertCount(1, $this->source->find($html, 'https://x.test/a.html'));
}

/** 5 Magazine's JSON-LD contentUrls are images; they must not become media. */
public function testIgnoresAnImageContentUrl(): void
{
    $html = '<html><body><script type="application/ld+json">'
        . '{"@type":"ImageObject","contentUrl":"https://5mag.net/wp-content/uploads/x.jpg"}'
        . '</script></body></html>';

    self::assertSame([], $this->source->find($html, 'https://5mag.net/audio/x/'));
}

public function testIgnoresMalformedJson(): void
{
    $html = '<html><body><script type="application/ld+json">{not json</script></body></html>';

    self::assertSame([], $this->source->find($html, 'https://x.test/a.html'));
}

public function testFindsTheCompanionVideoInTheCapturedHeisePage(): void
{
    $html = file_get_contents(__DIR__ . '/../../../../Fixtures/reader/media/heise-video.html');
    self::assertIsString($html);

    $found = $this->source->find($html, 'https://www.heise.de/news/x.html');

    self::assertNotSame([], $found);
    self::assertStringContainsString('M1j_uRqKMKI', $found[0]->url);
}
```

- [ ] **Step 2: Run it and confirm it fails**

```bash
cd backend && php bin/phpunit tests/Service/Reader/Media/Source/JsonLdMediaSourceTest.php
```

Expected: FAIL — class not found.

- [ ] **Step 3: Write `JsonLdMediaSource`**

Walk every `<script type="application/ld+json">` block, decode it, and recurse the whole structure collecting any `contentUrl` or `embedUrl` string. Do **not** try to model schema.org's shapes — a recursive key search is shorter, and `MediaUrlKind` already refuses anything that is not playable, which is what makes the broad search safe.

`Service/Scraper/JsonLdArticles.php` already parses this tree; read it first and reuse whatever it exposes rather than writing a second JSON-LD walker. If it exposes nothing reusable, say so in the commit message.

Emit `new MediaCandidate($kind, $normalizedUrl, …)`, where an `Embed` kind takes its URL from `EmbedProviders::resolve()` so it is the canonical embed URL, not the watch URL.

Tag with `#[AsTaggedItem(priority: 100)]`.

- [ ] **Step 4: Run it and confirm it passes**

```bash
cd backend && php bin/phpunit tests/Service/Reader/Media/Source/JsonLdMediaSourceTest.php
```

Expected: PASS, 5 tests.

- [ ] **Step 5: Write the failing meta test**

The ARD trap is the whole point of this test file.

```php
public function testTakesOgAudioWhenItIsAFile(): void
{
    $html = '<html><head><meta property="og:audio" content="https://x.test/a.mp3"></head></html>';

    $found = $this->source->find($html, 'https://x.test/a.html');

    self::assertCount(1, $found);
    self::assertSame(MediaKind::Audio, $found[0]->kind);
}

/** ARD's og:video is a player PAGE, not a file. It must be refused. */
public function testRefusesAnOgVideoThatPointsAtAPlayerPage(): void
{
    $html = '<html><head><meta property="og:video" '
        . 'content="https://www.tagesschau.de/video/video-1640158~player.html"></head></html>';

    self::assertSame([], $this->source->find($html, 'https://www.tagesschau.de/x.html'));
}

public function testRefusesTheCapturedArdPageThroughThisLayer(): void
{
    $html = file_get_contents(__DIR__ . '/../../../../Fixtures/reader/media/ard-video.html');
    self::assertIsString($html);

    self::assertSame([], $this->source->find($html, 'https://www.tagesschau.de/x.html'));
}
```

- [ ] **Step 6: Run it, confirm it fails, then write `MetaMediaSource`**

Read `og:audio`, `og:audio:secure_url`, `og:video:secure_url`, `og:video`, `twitter:player:stream`. Pass every value through `MediaUrlKind`; emit only what it recognises. Tag `#[AsTaggedItem(priority: 90)]`.

- [ ] **Step 7: Run both files and commit**

```bash
cd backend && php bin/phpunit tests/Service/Reader/Media/Source/ && composer check && composer md
git add backend/src/Service/Reader/Media backend/tests/Service/Reader/Media
git commit -m "feat(#755): JSON-LD and meta media layers"
```

---

### Task 5: The semantic layer

`<audio>`, `<video>` and `<source>` elements — the case that needs no cleverness at all, and that no #748 host adapter covered.

**Files:**
- Create: `backend/src/Service/Reader/Media/Source/SemanticMediaSource.php`
- Test: `backend/tests/Service/Reader/Media/Source/SemanticMediaSourceTest.php`

**Interfaces:**
- Consumes: `MediaUrlKind`.
- Produces: a tagged source at priority 70.

- [ ] **Step 1: Write the failing test**

```php
public function testFindsAnAudioElement(): void
{
    $found = $this->source->find('<body><audio src="https://x.test/a.mp3"></audio></body>', 'https://x.test/a');

    self::assertCount(1, $found);
    self::assertSame(MediaKind::Audio, $found[0]->kind);
}

public function testFindsAVideoWithSourceChildrenAndKeepsItsPoster(): void
{
    $html = '<body><video poster="https://x.test/p.jpg"><source src="https://x.test/v.mp4" type="video/mp4">'
        . '</video></body>';

    $found = $this->source->find($html, 'https://x.test/a');

    self::assertCount(1, $found);
    self::assertSame('https://x.test/p.jpg', $found[0]->posterUrl);
}

public function testSkipsAVideoWithNoPoster(): void
{
    $html = '<body><video><source src="https://x.test/v.mp4" type="video/mp4"></video></body>';

    self::assertSame([], $this->source->find($html, 'https://x.test/a'));
}

public function testSkipsAnHlsSource(): void
{
    $html = '<body><video poster="https://x.test/p.jpg"><source src="https://x.test/master.m3u8"></video></body>';

    self::assertSame([], $this->source->find($html, 'https://x.test/a'));
}
```

The no-poster rule is #748's decision D5 and still binds: a video with no still rots into a dead frame in a cache with no TTL.

- [ ] **Step 2: Run it, confirm it fails, then write `SemanticMediaSource`**

Query `audio, video`; take `src` or the first usable `<source src>`; for a video read `poster`. Judge every URL with `MediaUrlKind`. Tag `#[AsTaggedItem(priority: 70)]`.

- [ ] **Step 3: Run and commit**

```bash
cd backend && php bin/phpunit tests/Service/Reader/Media/Source/SemanticMediaSourceTest.php
git add backend/src/Service/Reader/Media backend/tests/Service/Reader/Media
git commit -m "feat(#755): semantic audio and video layer"
```

---

### Task 6: The attribute layer

Covers Deutschlandradio and ARD without naming either. This is where the ranker earns its place.

**Files:**
- Create: `backend/src/Service/Reader/Media/Source/AttributeMediaSource.php`
- Test: `backend/tests/Service/Reader/Media/Source/AttributeMediaSourceTest.php`

**Interfaces:**
- Consumes: `MediaUrlKind`, `MediaRelevance`.
- Produces: a tagged source at priority 60.

- [ ] **Step 1: Write the failing test**

```php
/** Deutschlandradio's data-audio-src, reached without naming Deutschlandradio. */
public function testFindsAMediaUrlInAnyAttribute(): void
{
    $html = '<body><div data-audio-src="https://x.test/bildung-episode.mp3"></div></body>';

    $found = $this->source->find($html, 'https://x.test/bildung-100.html');

    self::assertCount(1, $found);
    self::assertSame(MediaKind::Audio, $found[0]->kind);
}

/** ARD keeps its renditions in a data-v attribute, with the poster in og:image. */
public function testFindsAVideoInAnAttributeAndTakesTheOgImagePoster(): void
{
    $html = '<html><head><meta property="og:image" content="https://x.test/p.jpg"></head>'
        . '<body><div data-v="https://x.test/webxxl.mp4"></div></body></html>';

    $found = $this->source->find($html, 'https://x.test/a.html');

    self::assertCount(1, $found);
    self::assertSame('https://x.test/p.jpg', $found[0]->posterUrl);
}

/** The live stream sits beside the episode on the same page; it must lose. */
public function testExcludesALiveStreamBesideAnEpisode(): void
{
    $html = '<body><div data-x="https://st01.sslstream.dlf.de/dlf/01/128/mp3/stream.mp3"></div>'
        . '<div data-y="https://x.test/bildung-episode.mp3"></div></body>';

    $found = $this->source->find($html, 'https://x.test/bildung-100.html');

    self::assertCount(1, $found);
    self::assertStringContainsString('bildung-episode', $found[0]->url);
}

/** Ranked, not first-come: the teaser appears first in source order and loses. */
public function testPrefersTheFileWhoseNameEchoesTheSlug(): void
{
    $html = '<body><div data-a="https://x.test/teaser-other.mp3"></div>'
        . '<div data-b="https://x.test/bildung-episode.mp3"></div></body>';

    $found = $this->source->find($html, 'https://x.test/bildung-100.html');

    self::assertStringContainsString('bildung-episode', $found[0]->url);
}

public function testFindsTheEpisodeInTheCapturedDeutschlandradioPage(): void
{
    $html = file_get_contents(__DIR__ . '/../../../../Fixtures/reader/media/deutschlandradio-audio.html');
    self::assertIsString($html);

    $found = $this->source->find($html, 'https://www.deutschlandfunkkultur.de/bildung-100.html');

    self::assertNotSame([], $found);
    self::assertStringContainsString('.mp3', $found[0]->url);
    self::assertStringNotContainsString('sslstream', $found[0]->url);
}

public function testFindsTheVideoInTheCapturedArdPage(): void
{
    $html = file_get_contents(__DIR__ . '/../../../../Fixtures/reader/media/ard-video.html');
    self::assertIsString($html);

    $found = $this->source->find($html, 'https://www.tagesschau.de/ausland/beispiel-100.html');

    self::assertNotSame([], $found);
    self::assertStringContainsString('.mp4', $found[0]->url);
    self::assertNotNull($found[0]->posterUrl);
}
```

- [ ] **Step 2: Run it and confirm it fails**

```bash
cd backend && php bin/phpunit tests/Service/Reader/Media/Source/AttributeMediaSourceTest.php
```

Expected: FAIL — class not found.

- [ ] **Step 3: Write `AttributeMediaSource`**

Walk every element's every attribute. For each value, pull out `https://…` URLs — an attribute may hold a whole JSON blob, so use `preg_match_all` for URL-shaped substrings rather than treating the value as one URL. Judge each with `MediaUrlKind`, keep audio and video, rank with `MediaRelevance`, and return **one candidate per kind** — the best-ranked. Read `og:image` for a video's poster and drop the video if there is none.

Tag `#[AsTaggedItem(priority: 60)]`.

Attribute values are HTML-escaped, so decode entities before matching or the URLs inside a `data-json` blob will not be found.

- [ ] **Step 4: Run it and confirm it passes**

```bash
cd backend && php bin/phpunit tests/Service/Reader/Media/Source/AttributeMediaSourceTest.php
```

Expected: PASS, 6 tests.

- [ ] **Step 5: Commit**

```bash
git add backend/src/Service/Reader/Media backend/tests/Service/Reader/Media
git commit -m "feat(#755): attribute media layer with slug-ranked selection"
```

---

### Task 7: The linked-file layer

Covers NPR without naming NPR.

**Files:**
- Create: `backend/src/Service/Reader/Media/Source/LinkedFileMediaSource.php`
- Test: `backend/tests/Service/Reader/Media/Source/LinkedFileMediaSourceTest.php`

**Interfaces:**
- Consumes: `MediaUrlKind`, `MediaRelevance`.
- Produces: a tagged source at priority 50.

- [ ] **Step 1: Write the failing test**

```php
public function testFindsAnAudioFileBehindALink(): void
{
    $html = '<body><a href="https://x.test/telescope-segment.mp3?t=progseg&amp;sc=siteplayer">Listen</a></body>';

    $found = $this->source->find($html, 'https://x.test/2026/08/30/roman-space-telescope');

    self::assertCount(1, $found);
    self::assertSame('https://x.test/telescope-segment.mp3', $found[0]->url);
}

public function testIgnoresAnOrdinaryArticleLink(): void
{
    $html = '<body><a href="https://x.test/another-story">Read on</a></body>';

    self::assertSame([], $this->source->find($html, 'https://x.test/a'));
}

public function testFindsTheSegmentInTheCapturedNprPage(): void
{
    $html = file_get_contents(__DIR__ . '/../../../../Fixtures/reader/media/npr-audio.html');
    self::assertIsString($html);

    $found = $this->source->find(
        $html,
        'https://www.npr.org/2026/08/30/nx-s1-5948814/launch-nancy-grace-roman-space-telescope-nasa',
    );

    self::assertNotSame([], $found);
    self::assertStringEndsWith('.mp3', $found[0]->url);
    self::assertStringNotContainsString('?', $found[0]->url);
}
```

- [ ] **Step 2: Run it, confirm it fails, then write `LinkedFileMediaSource`**

Query `a[href]`, judge each with `MediaUrlKind`, rank with `MediaRelevance`, return the best per kind. Tag `#[AsTaggedItem(priority: 50)]`.

- [ ] **Step 3: Run and commit**

```bash
cd backend && php bin/phpunit tests/Service/Reader/Media/Source/LinkedFileMediaSourceTest.php
git add backend/src/Service/Reader/Media backend/tests/Service/Reader/Media
git commit -m "feat(#755): linked media file layer"
```

---

### Task 8: Delete the host adapters

The deliverable of the whole issue. Nothing may match on a hostname afterwards.

**Files:**
- Delete: `backend/src/Service/Reader/Media/Source/DeutschlandradioAudioSource.php`
- Delete: `backend/src/Service/Reader/Media/Source/NprAudioSource.php`
- Delete: `backend/src/Service/Reader/Media/Source/ArdVideoSource.php`
- Delete: the three matching test files
- Test: `backend/tests/Service/Reader/Media/HostAgnosticDiscoveryTest.php` (create)

**Interfaces:**
- Consumes: every layer from Tasks 4–7.
- Produces: nothing new.

- [ ] **Step 1: Write the failing end-to-end test**

This is the test that would have caught the original defect, so write it before deleting anything.

```php
<?php

declare(strict_types=1);

namespace App\Tests\Service\Reader\Media;

use App\Service\Reader\Media\MediaKind;
use App\Service\Reader\Media\PageMediaScanner;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Every measured page, through the real container, with no host-keyed source in
 * the set. This is the test the original design lacked.
 */
final class HostAgnosticDiscoveryTest extends KernelTestCase
{
    private function scanner(): PageMediaScanner
    {
        self::bootKernel();
        $scanner = self::getContainer()->get(PageMediaScanner::class);
        self::assertInstanceOf(PageMediaScanner::class, $scanner);

        return $scanner;
    }

    private function fixture(string $name): string
    {
        $html = file_get_contents(__DIR__ . '/../../../Fixtures/reader/media/' . $name);
        self::assertIsString($html);

        return $html;
    }

    public function testDeutschlandradioYieldsItsEpisode(): void
    {
        $media = $this->scanner()->scan(
            $this->fixture('deutschlandradio-audio.html'),
            'https://www.deutschlandfunkkultur.de/bildung-100.html',
        );

        self::assertSame(MediaKind::Audio, $media->candidates[0]->kind);
        self::assertStringNotContainsString('sslstream', $media->candidates[0]->url);
    }

    public function testNprYieldsItsSegment(): void
    {
        $media = $this->scanner()->scan(
            $this->fixture('npr-audio.html'),
            'https://www.npr.org/2026/08/30/nx-s1-5948814/launch-nancy-grace-roman-space-telescope-nasa',
        );

        self::assertFalse($media->isEmpty());
    }

    public function testArdYieldsAVideoWithAPoster(): void
    {
        $media = $this->scanner()->scan(
            $this->fixture('ard-video.html'),
            'https://www.tagesschau.de/ausland/beispiel-100.html',
        );

        $video = array_values(array_filter(
            $media->candidates,
            static fn ($c): bool => $c->kind === MediaKind::Video,
        ));

        self::assertNotSame([], $video);
        self::assertNotNull($video[0]->posterUrl);
    }

    public function testHeiseYieldsItsCompanionVideo(): void
    {
        $media = $this->scanner()->scan($this->fixture('heise-video.html'), 'https://www.heise.de/news/x.html');

        self::assertStringContainsString('M1j_uRqKMKI', $media->candidates[0]->url);
    }

    public function testFiveMagazineYieldsItsTrack(): void
    {
        $media = $this->scanner()->scan($this->fixture('soundcloud-page.html'), 'https://5mag.net/audio/dj-set/');

        self::assertStringContainsString('soundcloud', $media->candidates[0]->url);
    }
}
```

- [ ] **Step 2: Run it against the current code and confirm it passes**

```bash
cd backend && bin/console cache:warmup && php bin/phpunit tests/Service/Reader/Media/HostAgnosticDiscoveryTest.php
```

Expected: PASS — but only because the host adapters are still present. That is the point: it must keep passing after they are gone.

- [ ] **Step 3: Delete the three sources and their tests**

```bash
cd backend
git rm src/Service/Reader/Media/Source/DeutschlandradioAudioSource.php \
       src/Service/Reader/Media/Source/NprAudioSource.php \
       src/Service/Reader/Media/Source/ArdVideoSource.php \
       tests/Service/Reader/Media/Source/DeutschlandradioAudioSourceTest.php \
       tests/Service/Reader/Media/Source/NprAudioSourceTest.php \
       tests/Service/Reader/Media/Source/ArdVideoSourceTest.php
```

- [ ] **Step 4: Run it again and confirm it still passes**

```bash
cd backend && bin/console cache:warmup && php bin/phpunit tests/Service/Reader/Media/HostAgnosticDiscoveryTest.php
```

Expected: PASS with no host-keyed source in the container. If a case fails, the layer that should cover it is incomplete — fix the layer, do not restore the adapter.

- [ ] **Step 5: Prove no hostname gate survives**

```bash
cd backend && grep -rn "deutschlandfunk\|npr\.org\|ard-mcdn\|tagesschau\|ndr\.de" src/Service/Reader/Media/ || echo "clean"
```

Expected: `clean`. A hit in `src/` is a failure of this issue. Hits in `tests/` are fine — fixtures and assertions name real sites on purpose.

- [ ] **Step 6: Run everything and commit**

```bash
cd backend && php bin/phpunit tests/Service/Reader/ && composer check && composer md
git add -A backend/src/Service/Reader/Media backend/tests/Service/Reader/Media
git commit -m "refactor(#755): delete the host-keyed candidate sources"
```

---

### Task 9: Prove it generalizes

The acceptance criterion that actually matters: a publisher nobody designed for.

**Files:**
- Create: `backend/tests/Fixtures/reader/media/unseen-publisher.html`
- Test: `backend/tests/Service/Reader/Media/HostAgnosticDiscoveryTest.php` (extend)
- Modify: `docs/superpowers/plans/2026-08-31-748-evidence.md`

**Interfaces:**
- Consumes: the whole layer set.
- Produces: the evidence for the PR.

- [ ] **Step 1: Find a candidate page from the subscribed feeds**

Pick a publisher that is **not** heise, 5 Magazine, Deutschlandradio, NPR, ARD, Substack or psytranceportal.

```bash
docker compose exec -T php bin/console dbal:run-sql \
  "SELECT id, url FROM entry WHERE content_html LIKE '%.mp3%' OR content_html LIKE '%podcast%' LIMIT 20"
```

- [ ] **Step 2: Capture it**

```bash
curl -sL -A 'Mozilla/5.0' '<chosen url>' -o backend/tests/Fixtures/reader/media/unseen-publisher.html
```

- [ ] **Step 3: Add the test, with no new production code**

```php
public function testAnUnseenPublisherYieldsItsMediaWithNoNewCode(): void
{
    $media = $this->scanner()->scan($this->fixture('unseen-publisher.html'), '<chosen url>');

    self::assertFalse($media->isEmpty());
}
```

- [ ] **Step 4: Run it**

```bash
cd backend && php bin/phpunit tests/Service/Reader/Media/HostAgnosticDiscoveryTest.php
```

If it fails, that is a finding, not a failure to hide. Either a layer has a real gap worth closing, or the page carries no recoverable media and you should pick another. **Do not add a host rule to make it pass.** Record which it was.

- [ ] **Step 5: Record and commit**

```bash
git add backend/tests docs/superpowers/plans/2026-08-31-748-evidence.md
git commit -m "test(#755): prove discovery generalizes to an unseen publisher"
```

---

### Task 10: Verify against the real pipeline

#748's Task 10 never ran. This runs it once, for both issues.

**Files:**
- Modify: `docs/superpowers/plans/2026-08-31-748-evidence.md`

- [ ] **Step 1: Refresh the stack**

```bash
docker compose up -d --build php frontend
docker compose restart nginx worker
docker compose exec -T php bin/console cache:clear
```

`nginx` is restarted because recreating `php` leaves it on the old container IP and every `/api` call then 502s.

- [ ] **Step 2: Run both suite legs and the frontend gate**

```bash
cd backend && php bin/phpunit
docker compose exec -T php vendor/bin/phpunit
docker compose exec -T frontend npm run check
```

Expected: PASS on all three.

- [ ] **Step 3: Check the measured entries in the running app**

465658, 489867, 487567, 481606, 465683, 488630, 489854, 491093, 491483, 489312, 490933. Confirm: OZORA shows ten playable embeds with no empty containers; 5 Magazine shows its SoundCloud player; rushkoff's poster is a working link and is not double-wrapped; the three Deutschlandradio entries show a playing `<audio>` above the teaser; the ARD entries show a `<video>` with a visible poster; NPR shows both its video and its companion audio.

- [ ] **Step 4: Scan the dev log**

```bash
tail -n 200 "$(ls -t backend/var/log/dev-*.log | head -1)"
```

Expected: nothing new from the media classes.

- [ ] **Step 5: Re-run the reader audit**

```bash
docker compose exec -T php bin/console app:reader:audit
```

Compare against the #746 baseline of 17 findings across 9 feeds. The count must not rise.

- [ ] **Step 6: Mutation-test the diff**

```bash
cd backend && composer infection:diff
```

Expected: at or above `minMsi`. Kill escaped mutants with tests; never lower the gate.

- [ ] **Step 7: Record, then stop**

Append the results to the evidence file and commit.

```bash
git add docs/superpowers/plans/2026-08-31-748-evidence.md
git commit -m "docs(#755): record the verification sweep for the combined branch"
```

**Do not open the PR without Lars's explicit go-ahead.** When he gives it, the PR closes three issues:

```bash
gh pr create --base develop \
  --title "Reader: recover the media the extraction drops, host-agnostically (#748, #750, #755)" \
  --body "Closes #748, closes #750, closes #755."
```

The body should carry the before-and-after table from Step 3, the note that `EntrySanitizer` was deliberately not modified, and the Task 9 result naming the unseen publisher.

---

## Self-Review

**Spec coverage.** #755's five proposed layers map to Tasks 4 (JSON-LD, meta), 5 (semantic), 6 (attribute), 7 (linked file); `PageEmbedSource` already existed and only gains a priority in Task 3. The belongs-to-this-article rules map to Task 2 (slug correlation, tie by source order) and Task 3 (declaration strength as ordering). `DurableMediaUrl` unchanged — asserted by Task 1's tests reusing it. Embed providers unchanged — stated in Global Constraints. `#[AutoconfigureTag]` replacing the `_instanceof` entry → Task 3. "No class matches on a hostname" → Task 8 Step 5, mechanically checked. "A publisher not among the samples" → Task 9. The stale docblocks → Task 3 Step 3.

**Placeholder scan.** Tasks 4–7 give full test code and specify the implementation as concrete rules rather than as code, because each layer is a short walk whose real content is its acceptance test; where a rule is subtle — entity-decoding attribute values, one-candidate-per-kind, the poster requirement — it is stated explicitly. No step says "handle edge cases".

**Type consistency.** `MediaUrlKind::of(): ?MediaKind`, `MediaRelevance::rank(array, string): array`, `MediaCandidateSourceInterface::find(string, string): array`, `PageMediaScanner::scan(string, string): ArticleMedia`, `MediaCandidate(kind, url, posterUrl, label)` and `ArticleMedia::MAX_ITEMS` are used identically throughout, and match what is already in the branch. Priorities 100 / 90 / 80 / 70 / 60 / 50 are assigned once each and never reused.

**One risk to state.** #748's final whole-branch review never ran, so this plan builds on unreviewed code. Task 10 verifies both issues at once, but a reviewer should still read the #748 commits as part of the combined PR rather than assuming they were cleared.
