# Inline article audio in our player — Implementation Plan (#2084)

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Every `<audio>` in an article body (narration aside) is replaced in the SPA by the inline Listen row, so the clip plays in our player and can be queued; ingest emits one canonical `<audio>` carrying the clip's title and duration and never a live stream.

**Architecture:** Backend: one `AudioPlayerMarkup` builds every `<audio>`; `AttributeMediaSource` reads the clip's title/duration through a new `AudioClipFacts`; a new `AudioPlayerNormalizer` body-cleaning step rebuilds pass-through `<audio>`; `LiveAudioStream` keeps live streams out at `MediaUrlKind` and in the normalizer; `EntrySanitizer` lets `data-duration` through. Frontend: `ListenRowComponent` takes a narrow `ListenOffer`; `BodyAudio` turns an `<audio>` into a track and offer; `ArticleBodyAudio` mounts the row before each `<audio>` and hides it.

**Tech Stack:** PHP 8.4 / Symfony 7.4 (`Dom\HTMLDocument`), PHPUnit 12; Angular 20 standalone + signals, Jest.

**Spec:** `docs/superpowers/specs/2026-11-07-2084-inline-audio-in-our-player-design.md`

## Global Constraints

- Canonical shape, attribute order fixed: `<audio controls="" preload="none" src="…" title="…" data-duration="2871" class="reader-narration">`; `title`, `data-duration`, `class` only when present; no `<source>` children.
- `data-duration` is whole seconds, a positive integer; anything else is dropped.
- Narration (`audio.reader-narration`) keeps its toggle and is never replaced by a Listen row.
- Live-stream rule is host-agnostic (no host names): file stem `stream`, `live` or `livestream` with no extension or an audio extension; or extension `pls`/`m3u`. `.m3u8` is HLS and never a live audio stream here.
- Clip facts rule is host-agnostic: the first attribute named `data-…title` / `data-…duration` on any element holding the clip URL. The bare `title` attribute is NOT read (DLF's button says "Audio abspielen").
- CLAUDE.md applies in full: Clean Code names, `final readonly`, guard clauses, no comments unless a reader would get the code wrong, PHPMD-clean touched `src` files, `Response::HTTP_*` n/a here, role folders (`Model/`, `Support/`).
- Commit format `type(#2084): …`; no Claude attribution.
- Backend tests: `php bin/phpunit <path>` from `backend/`. Frontend tests: `docker compose exec -T frontend npx jest <path>` from the repo root (paths relative to `frontend/`).

---

### Task 1: Live audio streams never become media

**Files:**
- Create: `backend/src/Service/Reader/Media/Support/LiveAudioStream.php`
- Modify: `backend/src/Service/Reader/Media/MediaUrlKind.php` (`resolveFile`)
- Test: `backend/tests/Service/Reader/Media/Support/LiveAudioStreamTest.php`, `backend/tests/Service/Reader/Media/MediaUrlKindTest.php`

**Interfaces:**
- Produces: `LiveAudioStream::is(string $url): bool` — a static-only `final class` in `Support/` (compare `src/Service/Url/Support/YouTubeUrl.php` for the house shape).

- [ ] **Step 1: Write the failing tests**

`backend/tests/Service/Reader/Media/Support/LiveAudioStreamTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Service\Reader\Media\Support;

use App\Service\Reader\Media\Support\LiveAudioStream;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class LiveAudioStreamTest extends TestCase
{
    /** @return iterable<string, array{string}> */
    public static function liveStreams(): iterable
    {
        yield 'icecast mount with mp3' => ['https://st01.sslstream.dlf.de/dlf/01/128/mp3/stream.mp3?aggregator=web'];
        yield 'bare stream mount' => ['https://radio.test/stream'];
        yield 'live aac' => ['https://radio.test/live.aac'];
        yield 'livestream' => ['https://radio.test/LiveStream.MP3'];
        yield 'pls playlist' => ['https://radio.test/listen.pls'];
        yield 'm3u playlist' => ['https://radio.test/listen.m3u'];
    }

    #[DataProvider('liveStreams')]
    public function testRecognisesALiveStream(string $url): void
    {
        self::assertTrue(LiveAudioStream::is($url));
    }

    /** @return iterable<string, array{string}> */
    public static function episodes(): iterable
    {
        yield 'episode mp3' => ['https://ondemand-mp3.dradio.de/file/dradio/2026/11/07/ohne_echte_lobby_dlf.mp3'];
        yield 'hls playlist' => ['https://radio.test/live.m3u8'];
        yield 'stream as a directory' => ['https://radio.test/stream/episode-12.mp3'];
        yield 'extensionless upload' => ['https://pub.test/api/v1/audio/upload/7adcfe96/src'];
        yield 'stream mp4 video' => ['https://radio.test/stream.mp4'];
        yield 'not a url' => ['stream.mp3'];
    }

    #[DataProvider('episodes')]
    public function testLeavesAnythingElse(string $url): void
    {
        self::assertFalse(LiveAudioStream::is($url));
    }
}
```

Append to `MediaUrlKindTest`:

```php
    public function testRefusesALiveAudioStream(): void
    {
        self::assertNull($this->kind->resolve('https://st01.sslstream.dlf.de/dlf/01/128/mp3/stream.mp3?aggregator=web'));
    }
```

- [ ] **Step 2: Run them to verify they fail**

Run: `php bin/phpunit tests/Service/Reader/Media/Support/LiveAudioStreamTest.php tests/Service/Reader/Media/MediaUrlKindTest.php`
Expected: FAIL — class `LiveAudioStream` not found; `testRefusesALiveAudioStream` gets a resolved Audio URL.

- [ ] **Step 3: Implement**

`backend/src/Service/Reader/Media/Support/LiveAudioStream.php`:

```php
<?php

declare(strict_types=1);

namespace App\Service\Reader\Media\Support;

final class LiveAudioStream
{
    private const array STREAM_STEMS = ['stream', 'live', 'livestream'];
    private const array AUDIO_EXTENSIONS = ['', 'mp3', 'aac', 'm4a', 'ogg', 'oga', 'opus'];
    private const array PLAYLIST_EXTENSIONS = ['pls', 'm3u'];

    public static function is(string $url): bool
    {
        $path = parse_url($url, \PHP_URL_PATH);
        if (!\is_string($path) || parse_url($url, \PHP_URL_HOST) === null) {
            return false;
        }

        $extension = strtolower(pathinfo($path, \PATHINFO_EXTENSION));
        if (\in_array($extension, self::PLAYLIST_EXTENSIONS, true)) {
            return true;
        }

        $stem = strtolower(pathinfo($path, \PATHINFO_FILENAME));

        return \in_array($stem, self::STREAM_STEMS, true) && \in_array($extension, self::AUDIO_EXTENSIONS, true);
    }
}
```

In `MediaUrlKind::resolveFile()`, right after the `$bare` guard:

```php
        if (LiveAudioStream::is($bare)) {
            return null;
        }
```

(add `use App\Service\Reader\Media\Support\LiveAudioStream;`).

- [ ] **Step 4: Run them to verify they pass**

Run: `php bin/phpunit tests/Service/Reader/Media/Support/LiveAudioStreamTest.php tests/Service/Reader/Media/MediaUrlKindTest.php tests/Service/Reader/Media`
Expected: PASS (whole `Media` directory stays green).

- [ ] **Step 5: Commit**

```bash
git add backend/src/Service/Reader/Media/Support/LiveAudioStream.php backend/src/Service/Reader/Media/MediaUrlKind.php backend/tests/Service/Reader/Media/Support/LiveAudioStreamTest.php backend/tests/Service/Reader/Media/MediaUrlKindTest.php
git commit -m "feat(#2084): live audio streams never become reader media"
```

---

### Task 2: One builder for every `<audio>`

**Files:**
- Create: `backend/src/Service/Reader/Media/Model/AudioClipModel.php`
- Create: `backend/src/Service/Reader/Media/AudioPlayerMarkup.php`
- Test: `backend/tests/Service/Reader/Media/AudioPlayerMarkupTest.php`, `backend/tests/Service/Reader/Media/Model/AudioClipModelTest.php`

**Interfaces:**
- Produces:
  - `final readonly class AudioClipModel { __construct(public string $url, public ?string $title = null, public ?int $durationSeconds = null, public bool $narrated = false) }`
  - `AudioClipModel::secondsFrom(?string $value): ?int` — a positive whole-seconds string to int, else null.
  - `AudioPlayerMarkup::element(HTMLDocument $document, AudioClipModel $clip): Element`

- [ ] **Step 1: Write the failing tests**

`backend/tests/Service/Reader/Media/Model/AudioClipModelTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Service\Reader\Media\Model;

use App\Service\Reader\Media\Model\AudioClipModel;
use PHPUnit\Framework\TestCase;

final class AudioClipModelTest extends TestCase
{
    public function testReadsWholePositiveSeconds(): void
    {
        self::assertSame(2871, AudioClipModel::secondsFrom('2871'));
        self::assertSame(2871, AudioClipModel::secondsFrom(' 2871 '));
    }

    public function testRejectsAnythingThatIsNotAPositiveWholeNumber(): void
    {
        self::assertNull(AudioClipModel::secondsFrom(null));
        self::assertNull(AudioClipModel::secondsFrom(''));
        self::assertNull(AudioClipModel::secondsFrom('0'));
        self::assertNull(AudioClipModel::secondsFrom('-5'));
        self::assertNull(AudioClipModel::secondsFrom('47:51'));
        self::assertNull(AudioClipModel::secondsFrom('12.5'));
        self::assertNull(AudioClipModel::secondsFrom('PT47M51S'));
    }
}
```

`backend/tests/Service/Reader/Media/AudioPlayerMarkupTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Service\Reader\Media;

use App\Service\Reader\Media\AudioPlayerMarkup;
use App\Service\Reader\Media\Model\AudioClipModel;
use App\Tests\Support\ParsesHtml;
use PHPUnit\Framework\TestCase;

final class AudioPlayerMarkupTest extends TestCase
{
    use ParsesHtml;

    public function testABareClipIsAControlledLazyPlayerWithOneSource(): void
    {
        self::assertSame(
            '<audio controls="" preload="none" src="https://x.test/a.mp3"></audio>',
            $this->html(new AudioClipModel('https://x.test/a.mp3')),
        );
    }

    public function testCarriesTheClipsTitleAndDuration(): void
    {
        self::assertSame(
            '<audio controls="" preload="none" src="https://x.test/a.mp3" title="Ohne echte Lobby"'
            . ' data-duration="2871"></audio>',
            $this->html(new AudioClipModel('https://x.test/a.mp3', 'Ohne echte Lobby', 2871)),
        );
    }

    public function testMarksNarration(): void
    {
        self::assertSame(
            '<audio controls="" preload="none" src="https://x.test/full.mp3" class="reader-narration"></audio>',
            $this->html(new AudioClipModel('https://x.test/full.mp3', null, null, true)),
        );
    }

    private function html(AudioClipModel $clip): string
    {
        $document = $this->document('<p></p>');

        return $document->saveHtml(new AudioPlayerMarkup()->element($document, $clip));
    }
}
```

Before writing these, open `backend/tests/Support/ParsesHtml.php` and use its real method name for building a document (the tests above assume `document(string $html): HTMLDocument`, which `PlayerChromeCleanerTest` uses).

- [ ] **Step 2: Run them to verify they fail**

Run: `php bin/phpunit tests/Service/Reader/Media/AudioPlayerMarkupTest.php tests/Service/Reader/Media/Model/AudioClipModelTest.php`
Expected: FAIL — classes not found.

- [ ] **Step 3: Implement**

`backend/src/Service/Reader/Media/Model/AudioClipModel.php`:

```php
<?php

declare(strict_types=1);

namespace App\Service\Reader\Media\Model;

/** One audio clip as the body plays it; title and duration only when the page stated them. */
final readonly class AudioClipModel
{
    public function __construct(
        public string $url,
        public ?string $title = null,
        public ?int $durationSeconds = null,
        public bool $narrated = false,
    ) {
    }

    public static function secondsFrom(?string $value): ?int
    {
        $trimmed = trim((string) $value);
        if (!ctype_digit($trimmed) || (int) $trimmed === 0) {
            return null;
        }

        return (int) $trimmed;
    }
}
```

`backend/src/Service/Reader/Media/AudioPlayerMarkup.php`:

```php
<?php

declare(strict_types=1);

namespace App\Service\Reader\Media;

use App\Service\Reader\Media\Model\AudioClipModel;
use Dom\Element;
use Dom\HTMLDocument;

/**
 * The one shape every body <audio> takes: the client swaps it for its Listen row by `title` and `data-duration`, and
 * a client without that upgrade still plays it.
 */
final readonly class AudioPlayerMarkup
{
    public function element(HTMLDocument $document, AudioClipModel $clip): Element
    {
        $player = $document->createElement('audio');
        $player->setAttribute('controls', '');
        // Never fetch megabytes for an article the reader may only be skimming.
        $player->setAttribute('preload', 'none');
        $player->setAttribute('src', $clip->url);
        if ($clip->title !== null && trim($clip->title) !== '') {
            $player->setAttribute('title', trim($clip->title));
        }
        if ($clip->durationSeconds !== null) {
            $player->setAttribute('data-duration', (string) $clip->durationSeconds);
        }
        // The client keys its compact, translated presentation off this class, the only mark both sanitizers keep.
        if ($clip->narrated) {
            $player->setAttribute('class', 'reader-narration');
        }

        return $player;
    }
}
```

(The two inline comments move verbatim from `PageMediaInserter::player()`, which loses them in Task 4.)

- [ ] **Step 4: Run them to verify they pass**

Run: `php bin/phpunit tests/Service/Reader/Media/AudioPlayerMarkupTest.php tests/Service/Reader/Media/Model/AudioClipModelTest.php`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add backend/src/Service/Reader/Media/Model/AudioClipModel.php backend/src/Service/Reader/Media/AudioPlayerMarkup.php backend/tests/Service/Reader/Media/AudioPlayerMarkupTest.php backend/tests/Service/Reader/Media/Model/AudioClipModelTest.php
git commit -m "feat(#2084): one markup builds every reader audio player"
```

---

### Task 3: The page's clip title and duration reach the candidate

**Files:**
- Modify: `backend/src/Service/Reader/Media/Model/MediaCandidateModel.php`
- Create: `backend/src/Service/Reader/Media/AudioClipFacts.php`
- Modify: `backend/src/Service/Reader/Media/MediaCandidateSource/AttributeMediaSource.php`
- Modify: `backend/src/Service/Reader/Media/Model/ScannedPageModel.php`
- Test: `backend/tests/Service/Reader/Media/AudioClipFactsTest.php`, `backend/tests/Service/Reader/Media/MediaCandidateSource/AttributeMediaSourceTest.php`, `backend/tests/Service/Reader/Media/Model/MediaCandidateModelTest.php` (exists; append, adding `use App\Service\Reader\Media\Model\AudioClipModel;`)

**Interfaces:**
- Consumes: `AudioClipModel::secondsFrom()` (Task 2), `PageFurniture::holds(Element): bool` (existing).
- Produces:
  - `MediaCandidateModel` gains a last constructor parameter `public ?int $durationSeconds = null`, carried by `withMimeType`, `resolvePoster`, `completedBy` (`$this->durationSeconds ?? $later->durationSeconds`) and `at`.
  - `MediaCandidateModel::toAudioClip(): AudioClipModel` → `new AudioClipModel($this->url, $this->label, $this->durationSeconds, $this->narrated)`.
  - `AudioClipFacts::of(HTMLDocument $document, string $clipUrl): AudioClipModel` — scans every element (furniture skipped) whose attributes contain `$clipUrl`, returns `new AudioClipModel($clipUrl, <first data-…title value>, <first data-…duration positive seconds>)`.
  - `AttributeMediaSource` constructor gains `private AudioClipFacts $clipFacts` (last parameter); its audio candidate carries `label` = title, `durationSeconds`.

- [ ] **Step 1: Write the failing tests**

`backend/tests/Service/Reader/Media/AudioClipFactsTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Service\Reader\Media;

use App\Service\Reader\Media\AudioClipFacts;
use App\Service\Reader\Media\Model\AudioClipModel;
use App\Service\Reader\Media\PageFurniture;
use App\Tests\Support\ParsesHtml;
use PHPUnit\Framework\TestCase;

final class AudioClipFactsTest extends TestCase
{
    use ParsesHtml;

    private const string CLIP = 'https://ondemand.test/file/2026/11/07/episode.mp3';

    /** The first holder is a JSON blob; the play button beside it names the clip, its bare title the action. */
    public function testReadsTheClipFactsFromAnyElementHoldingItsUrl(): void
    {
        $html = '<body><script class="js-client-queries" data-json=\'{"url":"' . self::CLIP . '"}\'></script>'
            . '<button title="Audio abspielen" data-audio-src="' . self::CLIP . '?tracking=1"'
            . ' data-audiotitle="Ohne echte Lobby" data-audio-duration="2871"'
            . ' data-audio-download-tracking-title="Tracking copy"></button></body>';

        $facts = new AudioClipFacts(new PageFurniture())->of($this->document($html), self::CLIP);

        self::assertEquals(new AudioClipModel(self::CLIP, 'Ohne echte Lobby', 2871), $facts);
    }

    public function testSkipsADurationThatIsNotWholeSeconds(): void
    {
        $html = '<body><div data-src="' . self::CLIP . '" data-duration="47:51"></div>'
            . '<a href="' . self::CLIP . '" data-media-duration="2871"></a></body>';

        $facts = new AudioClipFacts(new PageFurniture())->of($this->document($html), self::CLIP);

        self::assertSame(2871, $facts->durationSeconds);
    }

    public function testKnowsNothingOfAClipNoElementDescribes(): void
    {
        $html = '<body><audio title="Play" src="' . self::CLIP . '"></audio>'
            . '<div data-audio-title="Another clip" data-src="https://ondemand.test/other.mp3"></div></body>';

        $facts = new AudioClipFacts(new PageFurniture())->of($this->document($html), self::CLIP);

        self::assertEquals(new AudioClipModel(self::CLIP), $facts);
    }
}
```

Append to `AttributeMediaSourceTest` (and add `new AudioClipFacts(new PageFurniture())` as the last argument in its `source()` helper):

```php
    /** Deutschlandfunk's shape: the episode beside a live stream, named and timed on its play button. */
    public function testAnAudioCandidateCarriesTheClipsTitleAndDurationAndNeverTheLiveStream(): void
    {
        $html = '<body><button data-audio-src="https://ondemand-mp3.test/file/2026/11/07/lobby_dlf.mp3"'
            . ' data-audio-title="Ohne echte Lobby" data-audio-duration="2871"></button>'
            . '<button data-audio-src="https://st01.sslstream.test/dlf/01/128/mp3/stream.mp3?aggregator=web"'
            . ' data-audio-title="Deutschlandfunk live"></button></body>';

        $found = $this->find($html, 'https://www.x.test/ohne-echte-lobby-100.html');

        self::assertCount(1, $found);
        self::assertSame('https://ondemand-mp3.test/file/2026/11/07/lobby_dlf.mp3', $found[0]->url);
        self::assertSame('Ohne echte Lobby', $found[0]->label);
        self::assertSame(2871, $found[0]->durationSeconds);
    }
```

`MediaCandidateModelTest` (create or append):

```php
    public function testCompletingKeepsTheFirstDurationAndFillsAMissingOne(): void
    {
        $first = new MediaCandidateModel(MediaKind::Audio, 'https://x.test/a.mp3');
        $later = new MediaCandidateModel(MediaKind::Audio, 'https://x.test/a.mp3', null, 'Title', null, false, null, 300);

        self::assertSame(300, $first->completedBy($later)->durationSeconds);
        self::assertSame('Title', $first->completedBy($later)->label);
    }

    public function testBecomesTheAudioClipItPlays(): void
    {
        $candidate = new MediaCandidateModel(MediaKind::Audio, 'https://x.test/a.mp3', null, 'Title', null, true, null, 300);

        self::assertEquals(new AudioClipModel('https://x.test/a.mp3', 'Title', 300, true), $candidate->toAudioClip());
    }

    public function testTheOtherCopiesKeepTheDuration(): void
    {
        $candidate = new MediaCandidateModel(MediaKind::Audio, 'https://x.test/a.mp3', null, null, null, false, null, 300);

        self::assertSame(300, $candidate->withMimeType('audio/mpeg')->durationSeconds);
        self::assertSame(300, $candidate->at('https://cdn.test/a.mp3')->durationSeconds);
    }
```

- [ ] **Step 2: Run them to verify they fail**

Run: `php bin/phpunit tests/Service/Reader/Media/AudioClipFactsTest.php tests/Service/Reader/Media/MediaCandidateSource/AttributeMediaSourceTest.php tests/Service/Reader/Media/Model/MediaCandidateModelTest.php`
Expected: FAIL — `AudioClipFacts` not found; unknown named/positional `durationSeconds`.

- [ ] **Step 3: Implement**

`MediaCandidateModel`: add `public ?int $durationSeconds = null,` after `$mimeType`; pass `$this->durationSeconds` as the new last argument in `withMimeType`, `resolvePoster` and `at`; `completedBy` passes `$this->durationSeconds ?? $later->durationSeconds`; update the `completedBy` docblock to name the duration among the gaps; add

```php
    public function toAudioClip(): AudioClipModel
    {
        return new AudioClipModel($this->url, $this->label, $this->durationSeconds, $this->narrated);
    }
```

`backend/src/Service/Reader/Media/AudioClipFacts.php`:

```php
<?php

declare(strict_types=1);

namespace App\Service\Reader\Media;

use App\Service\Reader\Media\Model\AudioClipModel;
use Dom\Attr;
use Dom\Element;
use Dom\HTMLDocument;

/**
 * What a page says of one clip in the data attributes beside its URL. A bare `title` names the play button's action,
 * not the clip, so only `data-…title` counts.
 */
final readonly class AudioClipFacts
{
    private const string TITLE_ATTRIBUTE = '/^data-[\w-]*title$/';
    private const string DURATION_ATTRIBUTE = '/^data-[\w-]*duration$/';

    public function __construct(private PageFurniture $furniture)
    {
    }

    public function of(HTMLDocument $document, string $clipUrl): AudioClipModel
    {
        $title = null;
        $durationSeconds = null;
        foreach ($this->holders($document, $clipUrl) as $holder) {
            $title ??= $this->titleOn($holder);
            $durationSeconds ??= $this->durationOn($holder);
        }

        return new AudioClipModel($clipUrl, $title, $durationSeconds);
    }

    /** @return iterable<Element> */
    private function holders(HTMLDocument $document, string $clipUrl): iterable
    {
        foreach ($document->querySelectorAll('*') as $element) {
            if (!$this->furniture->holds($element) && $this->holdsUrl($element, $clipUrl)) {
                yield $element;
            }
        }
    }

    private function holdsUrl(Element $element, string $clipUrl): bool
    {
        foreach ($element->attributes as $attribute) {
            if (str_contains($attribute->value, $clipUrl)) {
                return true;
            }
        }

        return false;
    }

    private function titleOn(Element $element): ?string
    {
        $title = trim($this->firstNamed($element, self::TITLE_ATTRIBUTE)?->value ?? '');

        return $title === '' ? null : $title;
    }

    private function durationOn(Element $element): ?int
    {
        foreach ($element->attributes as $attribute) {
            if (preg_match(self::DURATION_ATTRIBUTE, $attribute->name) === 1) {
                $seconds = AudioClipModel::secondsFrom($attribute->value);
                if ($seconds !== null) {
                    return $seconds;
                }
            }
        }

        return null;
    }

    private function firstNamed(Element $element, string $pattern): ?Attr
    {
        foreach ($element->attributes as $attribute) {
            if (preg_match($pattern, $attribute->name) === 1) {
                return $attribute;
            }
        }

        return null;
    }
}
```

Note: the scan returns the first holder's title even if a later attribute on it is a tracking copy (`data-audio-download-tracking-title` sits after `data-audiotitle` on DLF). If PHPMD flags the class, fix the design it points at; do not suppress.

`ScannedPageModel` (`src/Service/Reader/Media/Model/ScannedPageModel.php`) does not carry the document today. Add `public HTMLDocument $document` as its last constructor field and pass `$page->document` in `from()` — threading the document through `candidates()`/`bestCandidate()` as a parameter instead would be tramp data. Update its docblock's list ("…and the document, for what the page says beside the URL"). If `ScannedPageModelTest` constructs it, nothing changes (the constructor is private; `from()` is the only way in).

`AttributeMediaSource`: add the constructor parameter `private AudioClipFacts $clipFacts` (last). The audio branch of `bestCandidate()` becomes:

```php
        if ($kind === MediaKind::Audio) {
            $narrated = $this->narration->narrates($best, $origins[$best]);
            $facts = $this->clipFacts->of($page->document, $best);

            return new MediaCandidateModel(
                MediaKind::Audio,
                $best,
                null,
                $facts->title,
                $precedingText,
                $narrated,
                null,
                $facts->durationSeconds,
            );
        }
```

Find every other `new AttributeMediaSource(` in `backend/tests` (`grep -rn "new AttributeMediaSource" backend/tests`) and add `new AudioClipFacts(new PageFurniture())` as the last argument; the container autowires it.

- [ ] **Step 4: Run them to verify they pass**

Run: `php bin/phpunit tests/Service/Reader/Media`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add backend/src/Service/Reader/Media backend/tests/Service/Reader/Media
git commit -m "feat(#2084): an audio candidate carries the page's clip title and duration"
```

---

### Task 4: Placed players and teasers use the one builder

**Files:**
- Modify: `backend/src/Service/Reader/Media/PageMediaInserter.php`
- Modify: `backend/src/Service/Reader/Media/Teaser/TeaserPlayerMarkup.php`
- Modify tests constructing them: `backend/tests/Service/Reader/Media/PageMediaInserterTest.php`, `backend/tests/Service/Reader/Media/Teaser/TeaserPlayerMarkupTest.php`, `backend/tests/Service/Reader/BodyCleaning/BodyCleaningStep/PageMediaPlacementTest.php`, `backend/tests/Service/Reader/BodyCleaning/BodyCleaningStep/TeaserPlayerInserterTest.php`, `backend/tests/Service/Reader/ReaderBodyCleanerTest.php`

**Interfaces:**
- Consumes: `AudioPlayerMarkup::element()`, `AudioClipModel`, `MediaCandidateModel::toAudioClip()`.
- Produces: `PageMediaInserter::__construct(MediaMarkup $markup, AudioPlayerMarkup $audioMarkup)`; `TeaserPlayerMarkup::__construct(AudioPlayerMarkup $audioMarkup)`.

- [ ] **Step 1: Write the failing tests**

`PageMediaInserterTest` — change `setUp` to `new PageMediaInserter(new MediaMarkup(), new AudioPlayerMarkup())` and add:

```php
    public function testAnAudioPlayerCarriesTheClipsTitleAndDuration(): void
    {
        $media = new ArticleMediaModel([
            new MediaCandidateModel(MediaKind::Audio, 'https://x.test/a.mp3', null, 'Ohne echte Lobby', null, false, null, 2871),
        ]);

        $out = $this->insert('<body><p>Teaser</p></body>', $media);

        self::assertStringContainsString(
            '<audio controls="" preload="none" src="https://x.test/a.mp3" title="Ohne echte Lobby" data-duration="2871">',
            $out,
        );
    }

    public function testAFeedDeclaredAudioTypeStillPlaysFromOneSource(): void
    {
        $media = new ArticleMediaModel([
            (new MediaCandidateModel(MediaKind::Audio, 'https://x.test/a.mp3'))->withMimeType('audio/mpeg'),
        ]);

        $out = $this->insert('<body><p>Teaser</p></body>', $media);

        self::assertStringContainsString('<audio controls="" preload="none" src="https://x.test/a.mp3">', $out);
        self::assertStringNotContainsString('<source', $out);
    }
```

`TeaserPlayerMarkupTest` — change `setUp` to `new TeaserPlayerMarkup(new AudioPlayerMarkup())` and add (build the `TeaserPlayerModel` the way the existing audio test in that file does; read its constructor first):

```php
    public function testAnAudioTeaserNamesItsClipAfterItsHeadline(): void
    {
        $html = $this->figureHtml(new TeaserPlayerModel(
            MediaKind::Audio,
            'https://x.test/clip.mp3',
            'https://x.test/still.jpg',
            'The headline',
            'https://x.test/related.html',
        ));

        self::assertStringContainsString(
            '<audio controls="" preload="none" src="https://x.test/clip.mp3" title="The headline">',
            $html,
        );
    }
```

(`figureHtml` = however the existing tests in the file render the figure; reuse that helper, do not add a second one.)

- [ ] **Step 2: Run them to verify they fail**

Run: `php bin/phpunit tests/Service/Reader/Media/PageMediaInserterTest.php tests/Service/Reader/Media/Teaser/TeaserPlayerMarkupTest.php`
Expected: FAIL — constructor arity, and missing title/duration attributes.

- [ ] **Step 3: Implement**

`PageMediaInserter`: constructor `public function __construct(private MediaMarkup $markup, private AudioPlayerMarkup $audioMarkup)`. In `element()`:

```php
            MediaKind::Audio => $this->audioMarkup->element($document, $candidate->toAudioClip()),
            MediaKind::Video, MediaKind::Stream => $this->video($document, $candidate),
```

Rename `player()` to `video()`, drop its `$tag` parameter (always `'video'`), and delete the narration branch and the two comments that moved to `AudioPlayerMarkup` — keep the `preload="none"` line and its comment only if the video path still needs it (it does; keep it there as well — it is the same comment, now in two builders of two different elements, which is fine). `attachSource()` stays for video.

`TeaserPlayerMarkup`: constructor `public function __construct(private AudioPlayerMarkup $audioMarkup)`. In `appendPlayer()`'s audio branch:

```php
            $figure->appendChild($this->audioMarkup->element(
                $document,
                new AudioClipModel($teaser->mediaUrl, $teaser->caption),
            ));
```

and the `player()` helper keeps serving only the `<video>` (rename to `video()`, drop `$tag`).

Update every construction in tests: `grep -rn "new PageMediaInserter\|new TeaserPlayerMarkup" backend/tests` → add `new AudioPlayerMarkup()` (in `ReaderBodyCleanerTest::steps()`, create `$audioMarkup = new AudioPlayerMarkup();` beside `$markup` and pass it to both).

- [ ] **Step 4: Run them to verify they pass**

Run: `php bin/phpunit tests/Service/Reader`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add backend/src/Service/Reader/Media backend/tests/Service/Reader
git commit -m "refactor(#2084): placed and teaser audio players come from the one builder"
```

---

### Task 5: Pass-through audio is rebuilt into the one shape

**Files:**
- Create: `backend/src/Service/Reader/BodyCleaning/BodyCleaningStep/AudioPlayerNormalizer.php`
- Modify: `backend/config/services.yaml` (the `$steps` list, after `TeaserPlayerInserter`)
- Modify: `backend/tests/Service/Reader/ReaderBodyCleanerWiringTest.php` (`ORDER`), `backend/tests/Service/Reader/ReaderBodyCleanerTest.php` (`steps()`)
- Test: `backend/tests/Service/Reader/BodyCleaning/BodyCleaningStep/AudioPlayerNormalizerTest.php`

**Interfaces:**
- Consumes: `AudioPlayerMarkup`, `AudioClipModel`, `AudioClipModel::secondsFrom()`, `LiveAudioStream::is()`.
- Produces: `AudioPlayerNormalizer implements BodyCleaningStepInterface`, constructor `(AudioPlayerMarkup $markup)`.

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace App\Tests\Service\Reader\BodyCleaning\BodyCleaningStep;

use App\Service\Reader\BodyCleaning\BodyCleaningStep\AudioPlayerNormalizer;
use App\Service\Reader\Media\AudioPlayerMarkup;
use App\Tests\Support\BodyCleaningPasses;
use App\Tests\Support\ParsesHtml;
use PHPUnit\Framework\TestCase;

final class AudioPlayerNormalizerTest extends TestCase
{
    use ParsesHtml;

    public function testCollapsesASourceListToTheFirstPlayableAudio(): void
    {
        $out = $this->normalize('<audio controls autoplay loop>'
            . '<source src="https://x.test/a.webm" type="video/webm">'
            . '<source src="https://x.test/a.mp3?_=1" type="audio/mpeg">'
            . '<source src="https://x.test/a.ogg"></audio>');

        self::assertStringContainsString('<audio controls="" preload="none" src="https://x.test/a.mp3?_=1"></audio>', $out);
    }

    public function testTheElementsOwnSourceWinsOverItsChildren(): void
    {
        $out = $this->normalize('<audio src="https://x.test/own.mp3"><source src="https://x.test/child.mp3"></audio>');

        self::assertStringContainsString('src="https://x.test/own.mp3"', $out);
        self::assertStringNotContainsString('child.mp3', $out);
    }

    public function testKeepsTheTitleAWholeDurationAndTheNarrationMark(): void
    {
        $out = $this->normalize('<audio class="reader-narration" title="Clip" data-duration="300"'
            . ' src="https://x.test/full.mp3"></audio>');

        self::assertStringContainsString(
            '<audio controls="" preload="none" src="https://x.test/full.mp3" title="Clip" data-duration="300"'
            . ' class="reader-narration"></audio>',
            $out,
        );
    }

    public function testDropsADurationThatIsNotWholeSeconds(): void
    {
        $out = $this->normalize('<audio data-duration="5:00" src="https://x.test/a.mp3"></audio>');

        self::assertStringNotContainsString('data-duration', $out);
    }

    public function testRemovesALiveStream(): void
    {
        $out = $this->normalize('<p>Intro</p><audio controls src="https://radio.test/stream.mp3?x=1"></audio>');

        self::assertStringNotContainsString('<audio', $out);
        self::assertStringContainsString('Intro', $out);
    }

    public function testRemovesAPlayerWithNothingToPlay(): void
    {
        $out = $this->normalize('<audio controls>Your browser cannot play this.</audio>');

        self::assertStringNotContainsString('<audio', $out);
    }

    public function testAPlayerAlreadyInShapeIsUnchanged(): void
    {
        $canonical = '<audio controls="" preload="none" src="https://x.test/a.mp3" title="Clip" data-duration="300"></audio>';

        self::assertStringContainsString($canonical, $this->normalize($canonical));
    }

    private function normalize(string $html): string
    {
        $document = $this->document($html);
        new AudioPlayerNormalizer(new AudioPlayerMarkup())->cleanIn(BodyCleaningPasses::over($document));

        return $document->saveHtml();
    }
}
```

- [ ] **Step 2: Run it to verify it fails**

Run: `php bin/phpunit tests/Service/Reader/BodyCleaning/BodyCleaningStep/AudioPlayerNormalizerTest.php`
Expected: FAIL — class not found.

- [ ] **Step 3: Implement**

```php
<?php

declare(strict_types=1);

namespace App\Service\Reader\BodyCleaning\BodyCleaningStep;

use App\Service\Reader\BodyCleaning\Pass\BodyCleaningPass;
use App\Service\Reader\Media\AudioPlayerMarkup;
use App\Service\Reader\Media\Model\AudioClipModel;
use App\Service\Reader\Media\Support\LiveAudioStream;
use Dom\Element;

/** Rebuilds every body <audio> into the one shape AudioPlayerMarkup emits; a live stream or a silent player goes. */
final readonly class AudioPlayerNormalizer implements BodyCleaningStepInterface
{
    public function __construct(private AudioPlayerMarkup $markup)
    {
    }

    public function cleanIn(BodyCleaningPass $pass): void
    {
        foreach (iterator_to_array($pass->document->querySelectorAll('audio')) as $player) {
            $clip = $this->clipOf($player);
            if ($clip === null) {
                $player->remove();
                continue;
            }
            $player->replaceWith($this->markup->element($pass->document, $clip));
        }
    }

    private function clipOf(Element $player): ?AudioClipModel
    {
        $url = $this->sourceOf($player);
        if ($url === null || LiveAudioStream::is($url)) {
            return null;
        }

        return new AudioClipModel(
            $url,
            $player->getAttribute('title'),
            AudioClipModel::secondsFrom($player->getAttribute('data-duration')),
            $player->classList->contains('reader-narration'),
        );
    }

    private function sourceOf(Element $player): ?string
    {
        $own = trim($player->getAttribute('src') ?? '');
        if ($own !== '') {
            return $own;
        }
        foreach ($player->querySelectorAll(':scope > source[src]') as $source) {
            $type = $source->getAttribute('type') ?? '';
            $url = trim($source->getAttribute('src') ?? '');
            if ($url !== '' && ($type === '' || str_starts_with($type, 'audio/'))) {
                return $url;
            }
        }

        return null;
    }
}
```

`services.yaml`, directly after the `TeaserPlayerInserter` line:

```yaml
                # After every step that builds a player, so each body <audio> leaves in the one shape.
                - '@App\Service\Reader\BodyCleaning\BodyCleaningStep\AudioPlayerNormalizer'
```

`ReaderBodyCleanerWiringTest::ORDER`: add `AudioPlayerNormalizer::class,` after `TeaserPlayerInserter::class,` (plus its `use`). `ReaderBodyCleanerTest::steps()`: add `new AudioPlayerNormalizer($audioMarkup),` after the `TeaserPlayerInserter` entry.

- [ ] **Step 4: Run them to verify they pass**

Run: `php bin/phpunit tests/Service/Reader`
Expected: PASS, including `ReaderBodyCleanerWiringTest`. If a pre-existing test now fails because a pass-through `<audio>` gained `controls=""`/`preload="none"` or lost a `<source>`, update the assertion to the canonical shape — that is the intended change; any other failure is a real regression to investigate.

- [ ] **Step 5: Commit**

```bash
git add backend/src/Service/Reader/BodyCleaning/BodyCleaningStep/AudioPlayerNormalizer.php backend/config/services.yaml backend/tests/Service/Reader
git commit -m "feat(#2084): pass-through body audio is rebuilt into the one shape"
```

---

### Task 6: The duration crosses the sanitizer

**Files:**
- Modify: `backend/src/Service/Sanitize/EntrySanitizer.php`
- Test: `backend/tests/Service/Sanitize/EntrySanitizerTest.php`

- [ ] **Step 1: Write the failing test**

```php
    public function testKeepsTheClipTitleAndDurationOnAnAudioPlayer(): void
    {
        $clean = (string) $this->sanitizer->sanitize(
            '<audio controls preload="none" src="https://x.test/a.mp3" title="Clip" data-duration="2871"'
            . ' onplay="evil()"></audio>',
        );

        self::assertStringContainsString('title="Clip"', $clean);
        self::assertStringContainsString('data-duration="2871"', $clean);
        self::assertStringNotContainsString('onplay', $clean);
    }

    public function testStillStripsDataDurationFromOtherElements(): void
    {
        $clean = (string) $this->sanitizer->sanitize('<p data-duration="5">Text</p>');

        self::assertStringNotContainsString('data-duration', $clean);
    }
```

- [ ] **Step 2: Run to verify it fails**

Run: `php bin/phpunit tests/Service/Sanitize/EntrySanitizerTest.php`
Expected: FAIL — `data-duration` stripped.

- [ ] **Step 3: Implement**

After the `allowAttribute('class', …)` line in `EntrySanitizer`:

```php
            ->allowAttribute('data-duration', ['audio'])
```

and widen the comment above `class` to cover both marks in one line, e.g. `// The client's marks must cross this barrier: narration and slideshow classes, an audio clip's duration.` — keep it to the existing two lines at most, and keep "no styling or XSS hole opens".

- [ ] **Step 4: Run to verify it passes**

Run: `php bin/phpunit tests/Service/Sanitize/EntrySanitizerTest.php`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add backend/src/Service/Sanitize/EntrySanitizer.php backend/tests/Service/Sanitize/EntrySanitizerTest.php
git commit -m "feat(#2084): an audio clip's duration crosses the sanitizer"
```

---

### Task 7: A body `<audio>` becomes a track and a Listen offer

**Files:**
- Create: `frontend/src/app/reader/audio/listen-offer.ts`
- Modify: `frontend/src/app/reader/article/listen-row/listen-row.component.ts`
- Create: `frontend/src/app/reader/audio/body-audio.ts`
- Test: `frontend/src/app/reader/audio/body-audio.spec.ts`

**Interfaces:**
- Consumes: `EmbedTrackOrigin` (`audio/embed-track.ts`), `AudioTrack` / `AudioPlayerService` (`audio-player.service.ts`), `ListenLauncher.listen(track)`, `formatDuration(seconds)` (`reader/format.ts`).
- Produces:
  - `interface ListenOffer { duration(): string | null; queued(): boolean; play(): void; toggleQueued(): void; }`
  - `ListenRowComponent.audio = input.required<ListenOffer>()` (`EntryAudio` satisfies it structurally).
  - `bodyAudioTrack(element: HTMLAudioElement, origin: EmbedTrackOrigin): AudioTrack | null`
  - `class BodyAudio implements ListenOffer { constructor(track: AudioTrack, player: AudioPlayerService, launcher: ListenLauncher) }`

- [ ] **Step 1: Write the failing test**

```ts
import { StubAudioPlayer } from '../../../testing/stub-audio-player';
import { StubListenLauncher } from '../../../testing/stub-listen-launcher';
import { anEntry } from '../../../testing/entry-testing';
import { embedTrackOrigin } from './embed-track';
import { BodyAudio, bodyAudioTrack } from './body-audio';

const ORIGIN = embedTrackOrigin(anEntry({ id: 7, title: 'The article' }));

function audioOf(html: string): HTMLAudioElement {
  const host = document.createElement('div');
  host.innerHTML = html;
  return host.querySelector('audio')!;
}

describe('bodyAudioTrack', () => {
  it('names and times the clip from its own attributes', () => {
    const track = bodyAudioTrack(
      audioOf('<audio src="https://x.test/a.mp3" title="Ohne echte Lobby" data-duration="2871"></audio>'),
      ORIGIN,
    );

    expect(track).toEqual(
      expect.objectContaining({
        url: 'https://x.test/a.mp3',
        title: 'Ohne echte Lobby',
        durationInSeconds: 2871,
        imageUrl: ORIGIN.imageUrl,
        faviconUrl: ORIGIN.faviconUrl,
        entry: ORIGIN.entry,
      }),
    );
  });

  it('falls back to the article title and an unknown length', () => {
    const track = bodyAudioTrack(audioOf('<audio src="https://x.test/a.mp3" data-duration="5:00"></audio>'), ORIGIN);

    expect(track).toEqual(expect.objectContaining({ title: 'The article', durationInSeconds: null }));
  });

  it('plays the first source of an older bare player', () => {
    const track = bodyAudioTrack(
      audioOf('<audio><source src="https://x.test/b.mp3" type="audio/mpeg"></audio>'),
      ORIGIN,
    );

    expect(track?.url).toBe('https://x.test/b.mp3');
  });

  it('has no track for a player with nothing to play', () => {
    expect(bodyAudioTrack(audioOf('<audio></audio>'), ORIGIN)).toBeNull();
  });
});

describe('BodyAudio', () => {
  function setUp() {
    const player = new StubAudioPlayer();
    const track = bodyAudioTrack(
      audioOf('<audio src="https://x.test/a.mp3" data-duration="2871"></audio>'),
      ORIGIN,
    )!;
    const offer = new BodyAudio(track, player.asService(), new StubListenLauncher(player).asService());
    return { player, track, offer };
  }

  it('shows the clip length', () => {
    expect(setUp().offer.duration()).toBe('47:51');
  });

  it('plays the clip through the launcher', () => {
    const { player, track, offer } = setUp();

    offer.play();

    expect(player.played).toEqual([track]);
  });

  it('queues and unqueues the clip', () => {
    const { offer } = setUp();

    offer.toggleQueued();
    expect(offer.queued()).toBe(true);

    offer.toggleQueued();
    expect(offer.queued()).toBe(false);
  });
});
```

Before running: open `frontend/src/testing/stub-listen-launcher.ts` and `frontend/src/testing/entry-testing.ts` and confirm `StubListenLauncher(player).asService()` and `anEntry({...})` are their real APIs (both are used exactly so in `article-embed-audio.spec.ts`).

- [ ] **Step 2: Run to verify it fails**

Run: `docker compose exec -T frontend npx jest src/app/reader/audio/body-audio.spec.ts`
Expected: FAIL — cannot find module `./body-audio`.

- [ ] **Step 3: Implement**

`frontend/src/app/reader/audio/listen-offer.ts`:

```ts
/** What the inline Listen row plays and queues: an entry's enclosure or a clip in its body. */
export interface ListenOffer {
  duration(): string | null;
  queued(): boolean;
  play(): void;
  toggleQueued(): void;
}
```

`listen-row.component.ts`: replace the `EntryAudio` import with `import { ListenOffer } from '../../audio/listen-offer';` and `readonly audio = input.required<ListenOffer>();`.

`frontend/src/app/reader/audio/body-audio.ts`:

```ts
import { computed } from '@angular/core';
import { AudioPlayerService, AudioTrack } from '../audio-player.service';
import { formatDuration } from '../format';
import { EmbedTrackOrigin } from './embed-track';
import { ListenLauncher } from './listen-launcher';
import { ListenOffer } from './listen-offer';

const WHOLE_SECONDS = /^\d+$/;

/** The track a body <audio> plays, named and timed by its own `title` and `data-duration` when ingest set them. */
export function bodyAudioTrack(element: HTMLAudioElement, origin: EmbedTrackOrigin): AudioTrack | null {
  const url = sourceOf(element);
  if (!url) return null;
  return {
    url,
    title: element.getAttribute('title')?.trim() || origin.title,
    faviconUrl: origin.faviconUrl,
    imageUrl: origin.imageUrl,
    durationInSeconds: secondsOf(element.getAttribute('data-duration')),
    entry: origin.entry,
  };
}

export class BodyAudio implements ListenOffer {
  readonly queued = computed(() => this.player.isQueued(this.track.url));

  constructor(
    private readonly track: AudioTrack,
    private readonly player: AudioPlayerService,
    private readonly launcher: ListenLauncher,
  ) {}

  duration(): string | null {
    const seconds = this.track.durationInSeconds;
    return seconds ? formatDuration(seconds) : null;
  }

  play(): void {
    this.launcher.listen(this.track);
  }

  toggleQueued(): void {
    this.player.toggleQueued(this.track);
  }
}

function sourceOf(element: HTMLAudioElement): string | null {
  const own = element.getAttribute('src')?.trim();
  if (own) return own;
  return element.querySelector('source[src]')?.getAttribute('src')?.trim() || null;
}

function secondsOf(value: string | null): number | null {
  const trimmed = value?.trim() ?? '';
  const seconds = WHOLE_SECONDS.test(trimmed) ? Number(trimmed) : 0;
  return seconds > 0 ? seconds : null;
}
```

- [ ] **Step 4: Run to verify it passes**

Run: `docker compose exec -T frontend npx jest src/app/reader/audio/body-audio.spec.ts src/app/reader/audio/entry-audio.spec.ts src/app/reader/article/reader-view`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add frontend/src/app/reader/audio/listen-offer.ts frontend/src/app/reader/audio/body-audio.ts frontend/src/app/reader/audio/body-audio.spec.ts frontend/src/app/reader/article/listen-row/listen-row.component.ts
git commit -m "feat(#2084): a body audio clip becomes a track the Listen row can offer"
```

---

### Task 8: The Listen row replaces every body `<audio>`

**Files:**
- Create: `frontend/src/app/reader/article/article-content/article-body-audio.ts`
- Modify: `frontend/src/app/reader/article/article-content/article-body.service.ts` (construct it; call it in `render()` inside the existing `this.zone.run(...)` block, after `this.narration.mount(host)`)
- Modify: `frontend/src/app/reader/article/article-content/article-content.component.scss` (hide the replaced element; body spacing for the row)
- Test: `frontend/src/app/reader/article/article-content/article-body-audio.spec.ts`

**Interfaces:**
- Consumes: `ArticleIslands.mountBefore()`, `ListenRowComponent`, `BodyAudio`, `bodyAudioTrack` (Task 7), `EmbedTrackOrigin`.
- Produces: `class ArticleBodyAudio { constructor(islands: ArticleIslands, player: AudioPlayerService, launcher: ListenLauncher); mount(host: HTMLElement, origin: EmbedTrackOrigin | null): void }`; CSS class `reader-audio--replaced`.

The render effect can decorate the same DOM twice (chapters change without new HTML; see `article-narration.spec.ts` "mounted again over the same DOM"), so the `<audio>` is hidden, never removed: a second mount must find it again.

- [ ] **Step 1: Write the failing test**

```ts
import { TestBed } from '@angular/core/testing';
import { injectIslands } from '../../../../testing/article-islands-testing';
import { provideAccountIdentity } from '../../../../testing/account-identity-testing';
import { articleHost } from '../../../../testing/article-host-testing';
import { provideTranslocoTesting } from '../../../../testing/transloco-testing';
import { anEntry } from '../../../../testing/entry-testing';
import { StubAudioPlayer } from '../../../../testing/stub-audio-player';
import { StubListenLauncher } from '../../../../testing/stub-listen-launcher';
import { embedTrackOrigin } from '../../audio/embed-track';
import { ArticleIslands } from './article-islands';
import { ArticleBodyAudio } from './article-body-audio';

const ORIGIN = embedTrackOrigin(anEntry({ id: 7, title: 'The article' }));
const CLIP = '<audio controls src="https://x.test/a.mp3" title="Clip" data-duration="2871"></audio>';

describe('ArticleBodyAudio', () => {
  let islands: ArticleIslands;
  let player: StubAudioPlayer;
  let bodyAudio: ArticleBodyAudio;

  beforeEach(() => {
    TestBed.configureTestingModule({
      imports: [provideTranslocoTesting()],
      providers: [provideAccountIdentity()],
    });
    islands = injectIslands();
    player = new StubAudioPlayer();
    bodyAudio = new ArticleBodyAudio(
      islands,
      player.asService(),
      new StubListenLauncher(player).asService(),
    );
  });

  it('puts the Listen row in place of a body audio player', () => {
    const host = articleHost(CLIP);

    bodyAudio.mount(host, ORIGIN);

    expect(host.querySelector('app-listen-row + audio.reader-audio--replaced')).not.toBeNull();
  });

  it('plays the clip from the row', () => {
    const host = articleHost(CLIP);
    bodyAudio.mount(host, ORIGIN);

    host.querySelector<HTMLButtonElement>('app-listen-row button.listen')!.click();

    expect(player.played.map((track) => track.title)).toEqual(['Clip']);
  });

  it('shows the clip length on the row', () => {
    const host = articleHost(CLIP);

    bodyAudio.mount(host, ORIGIN);

    expect(host.querySelector('.listen-duration')?.textContent).toBe('47:51');
  });

  it('leaves narration to its own toggle', () => {
    const host = articleHost('<audio class="reader-narration" src="https://x.test/full.mp3"></audio>');

    bodyAudio.mount(host, ORIGIN);

    expect(host.querySelector('app-listen-row')).toBeNull();
  });

  it('leaves a player with nothing to play, and every player without an article to name it', () => {
    const silent = articleHost('<audio controls></audio>');
    const orphan = articleHost(CLIP);

    bodyAudio.mount(silent, ORIGIN);
    bodyAudio.mount(orphan, null);

    expect(silent.querySelector('app-listen-row')).toBeNull();
    expect(orphan.querySelector('app-listen-row')).toBeNull();
    expect(orphan.querySelector('audio.reader-audio--replaced')).toBeNull();
  });

  it('mounts one row again over the same DOM', () => {
    const host = articleHost(CLIP);
    bodyAudio.mount(host, ORIGIN);

    islands.releaseAll();
    bodyAudio.mount(host, ORIGIN);

    expect(host.querySelectorAll('app-listen-row')).toHaveLength(1);
    expect(host.querySelectorAll('audio')).toHaveLength(1);
  });
});
```

- [ ] **Step 2: Run to verify it fails**

Run: `docker compose exec -T frontend npx jest src/app/reader/article/article-content/article-body-audio.spec.ts`
Expected: FAIL — cannot find module `./article-body-audio`.

- [ ] **Step 3: Implement**

`frontend/src/app/reader/article/article-content/article-body-audio.ts`:

```ts
import { inputBinding } from '@angular/core';
import { AudioPlayerService } from '../../audio-player.service';
import { BodyAudio, bodyAudioTrack } from '../../audio/body-audio';
import { EmbedTrackOrigin } from '../../audio/embed-track';
import { ListenLauncher } from '../../audio/listen-launcher';
import { ListenRowComponent } from '../listen-row/listen-row.component';
import { ArticleIslands } from './article-islands';

const REPLACED = 'reader-audio--replaced';

/** Hidden, not removed: a re-decoration over the same body must find the player again. */
export class ArticleBodyAudio {
  constructor(
    private readonly islands: ArticleIslands,
    private readonly player: AudioPlayerService,
    private readonly launcher: ListenLauncher,
  ) {}

  mount(host: HTMLElement, origin: EmbedTrackOrigin | null): void {
    if (!origin) return;
    for (const element of Array.from(host.querySelectorAll<HTMLAudioElement>('audio:not(.reader-narration)'))) {
      const track = bodyAudioTrack(element, origin);
      if (!track) continue;
      const offer = new BodyAudio(track, this.player, this.launcher);
      this.islands.mountBefore(element, ListenRowComponent, [inputBinding('audio', () => offer)]);
      element.classList.add(REPLACED);
    }
  }
}
```

`article-body.service.ts`: add the field

```ts
  private readonly bodyAudio = new ArticleBodyAudio(this.islands, this.audio, inject(ListenLauncher));
```

(`inject(ListenLauncher)` is already called once for `embedAudio`; hoist it to `private readonly launcher = inject(ListenLauncher);` and use it in both — a third use would be the DRY trigger anyway.) In `render()`, inside `this.zone.run(() => { … })`, after `this.narration.mount(host);`:

```ts
      this.bodyAudio.mount(host, parts.origin());
```

`article-content.component.scss`, next to the narration rules (around line 424):

```scss
:host ::ng-deep audio.reader-audio--replaced {
  display: none;
}

:host ::ng-deep app-listen-row.reader-island {
  margin: var(--space-5) 0;
}
```

- [ ] **Step 4: Run to verify it passes**

Run: `docker compose exec -T frontend npx jest src/app/reader/article/article-content src/app/reader/audio`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add frontend/src/app/reader/article/article-content
git commit -m "feat(#2084): the Listen row replaces every body audio player"
```

---

### Task 9: Gates and the live check

- [ ] **Step 1: Backend gates** (from `backend/`; run in parallel where possible)

```bash
composer cs
composer stan
composer md
composer tramp
php bin/phpunit
docker compose exec php composer test:parallel
composer infection:diff
```

Expected: all clean. PHPMD must be clean on every touched `src` file (`PageMediaInserter`, `TeaserPlayerMarkup`, `AttributeMediaSource`, `MediaCandidateModel`, `EntrySanitizer`, `MediaUrlKind` and the new classes). Run PhpStorm `lint_files` on the changed PHP.

- [ ] **Step 2: Frontend gate** (from `frontend/`)

```bash
docker compose exec -T frontend npm run check
```

Expected: ESLint, Prettier, Stylelint, tsc and Jest all green.

- [ ] **Step 3: Dev log scan**

```bash
ls -t backend/var/log/dev-*.log | head -1 | xargs tail -n 200 | jq -c 'select(.level >= 300)'
```

Expected: nothing new from the reader pipeline.

- [ ] **Step 4: Live check on :4200**

Make sure the Docker `php` and `worker` containers run the branch's code (recreate if their compiled container is stale), then open
`http://green-tara.local:4200/?subscription=627&entry=724939-ohne-echte-lobby-wie-landliche-regionen-um-ihre-zukunft-kampfen`
in Reader mode. Expected: no native audio control; a Listen row reading `Listen 47:51` · `Add to playlist`; Listen plays the episode in the bottom bar titled "Ohne echte Lobby: Wie ländliche Regionen um ihre Zukunft kämpfen"; Add to playlist queues it; a podcast entry with an enclosure still shows only its header Listen row (no duplicate in the body).

- [ ] **Step 5: Commit any gate fixes, push, and open the PR** (`Closes #2084`), after running `/simplify` on the branch.
