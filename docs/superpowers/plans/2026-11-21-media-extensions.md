# One Home for Playable Media Extensions Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Every media file extension list has one home per purpose, and `wma`, `mkv`, `avi`, `ogv` behave the same wherever they turn up (#2413).

**Architecture:** A new `App\Entity\PlayableMediaFile` holds the only list of audio and video extensions a browser plays. It lives in `App\Entity` because `EntryAttachment` uses it (CLAUDE.md, "Shared values have one home"). `EntryAttachment`, `FeedMediaClassifier`, `MediaUrlKind` and `LiveAudioStream` all ask it. The feed side keeps one list of its own, the media a feed may carry that no browser plays reliably (`wma`, `mkv`, `avi`, `ogv`). Those classify as `FeedMediaKind::Other`, so the enclosure is kept as a downloadable attachment but never becomes an audio player or a video medium.

**Tech Stack:** PHP 8.5, PHPUnit 12.

**Spec:** GitHub issue #2413. The user ruled "one playable list": unplayable extensions become `Other` on the feed side.

## Global Constraints

- `final` class with a private constructor; static methods only (a `Support`-style helper in `App\Entity`, as `PersistenceClassesAreFinalRule` requires `final`).
- Method names follow the CLAUDE.md verb table; no `of…`/`…Of` names.
- Playable audio: `mp3, m4a, aac, ogg, oga, opus, wav, flac`. Playable video: `mp4, m4v, webm, mov`. These are exactly today's `MediaUrlKind` lists, which match the frontend's `audio-attachment.ts`.
- Feed-only unplayable media: `wma, mkv, avi, ogv`.
- An explicit `type`/`medium` still decides before any extension (unchanged).
- No comments beyond the CLAUDE.md bar.

## Review Focus

1. A typeless `.mkv` enclosure must still yield one attachment (it must not be dropped as `Unknown`), and no video medium. Pinned in Task 2's `ItemMediaExtractorTest`.
2. An attachment with `audio/x-ms-wma` MIME is still audio, because the MIME type decides. Pinned in Task 1's `EntryAttachmentTest`.
3. Upper-case extensions (`.MKV`, `.WMA`) behave like lower-case ones. Pinned in Task 2's classifier provider.
4. `LiveAudioStream` gains `wav`/`flac` stream mounts (`/stream.flac`) and must keep refusing `stream.mp4`. Pinned in Task 3.
5. A `.wma`/`.mkv` URL found on a page is still refused by `MediaUrlKind`. Pinned in Task 3.

---

### Task 1: `PlayableMediaFile` and `EntryAttachment`

**Files:**
- Create: `backend/src/Entity/PlayableMediaFile.php`
- Modify: `backend/src/Entity/EntryAttachment.php` (drop `AUDIO_EXTENSIONS`, move `extractExtension()` out)
- Test: `backend/tests/Entity/PlayableMediaFileTest.php`, `backend/tests/Entity/EntryAttachmentTest.php`

**Interfaces:**
- Produces:
  - `PlayableMediaFile::extractExtension(string $url): string`: the lower-cased extension of the URL's trimmed path, `''` when there is none. It moves verbatim from `EntryAttachment`.
  - `PlayableMediaFile::isAudioExtension(string $extension): bool`
  - `PlayableMediaFile::isVideoExtension(string $extension): bool`

- [ ] **Step 1: Write the failing tests.** `PlayableMediaFileTest`:
  - `testRecognisesBrowserPlayableAudio`: a provider over the eight audio extensions, each `isAudioExtension` true and `isVideoExtension` false.
  - `testRecognisesBrowserPlayableVideo`: a provider over the four video extensions, the reverse.
  - `testRefusesMediaBrowsersDoNotPlay`: a provider over `wma, mkv, avi, ogv, ''`, both checks false.
  - `testExtractsTheLowerCasedExtensionOfTheUrlPath`: `'https://x.test/a/Ep.MP3?v=1'` gives `'mp3'`, and `'https://x.test/episode'` gives `''`.

  In the `EntryAttachmentTest::attachments()` provider, add:
  - `yield 'no mime type, wma extension' => [new EntryAttachment('https://pod.test/episode.wma'), false];`
  - `yield 'wma mime type' => [new EntryAttachment('https://pod.test/episode.wma', 'audio/x-ms-wma'), true];`
- [ ] **Step 2: Run** `php bin/phpunit tests/Entity/PlayableMediaFileTest.php tests/Entity/EntryAttachmentTest.php`. Expected: FAIL (class not found, and the wma case returns true).
- [ ] **Step 3: Implement `PlayableMediaFile`** with private `AUDIO_EXTENSIONS`/`VIDEO_EXTENSIONS` constants. `EntryAttachment::isAudio()` calls `PlayableMediaFile::isAudioExtension(PlayableMediaFile::extractExtension($this->url))`. Point the only other `extractExtension` caller (`FeedMediaClassifier`) at the new home.
- [ ] **Step 4: Run** the same command. Expected: PASS.
- [ ] **Step 5: Commit:** `refactor(#2413): one home for the media extensions a browser plays`

### Task 2: Feed classification

**Files:**
- Modify: `backend/src/Service/Ingestion/Parser/Support/FeedMediaClassifier.php`
- Test: `backend/tests/Service/Ingestion/Parser/Support/FeedMediaClassifierTest.php`, `backend/tests/Service/Ingestion/Parser/ItemMediaExtractorTest.php`

**Interfaces:**
- Consumes: the `PlayableMediaFile` methods from Task 1.
- `FeedMediaClassifier::classifyUrl(string $url): FeedMediaKind`. Its signature is unchanged. Order: playable audio → `Audio`, playable video → `Video`, an `IMAGE_EXTENSIONS` hit → `Image`, an `UNPLAYABLE_MEDIA_EXTENSIONS` hit (`wma, mkv, avi, ogv`) → `Other`, otherwise `Unknown`. `EXTENSION_KINDS` shrinks to images only; rename it for what it now holds.

- [ ] **Step 1: Write the failing tests.**
  - In `FeedMediaClassifierTest`, add `testMediaABrowserDoesNotPlayIsOther`, a provider over `<enclosure url="https://a/ep.wma"/>`, `<media:content url="https://v/clip.mkv"/>`, `https://v/clip.AVI` and `https://v/clip.ogv`, each asserting `FeedMediaKind::Other`.
  - Add `testPlayableVideoExtensionWithoutTypeIsVideo`: `<media:content url="https://v/clip.webm"/>` → `Video`.
  - In `ItemMediaExtractorTest`, add `testATypelessUnplayableVideoEnclosureIsKeptAsAnAttachmentOnly`. Given `rssItem('<enclosure url="https://cdn/clip.mkv"/>')`, assert `$bundle->media === []`, one attachment with url `https://cdn/clip.mkv`, and kind `FeedMediaKind::Other`.
- [ ] **Step 2: Run** `php bin/phpunit tests/Service/Ingestion/Parser`. Expected: the new cases FAIL (they come out as `Audio`/`Video` today).
- [ ] **Step 3: Implement** the order above in `classifyUrl()`.
- [ ] **Step 4: Run** `php bin/phpunit tests/Service/Ingestion/Parser tests/Service/Content/LinkCard tests/Service/Social/Share`. Expected: PASS. `VisualMedium::at` callers now get `null` for `.avi`/`.mkv`; if a link-card or share test asserted otherwise, it pinned the bug, so update it to the ruling.
- [ ] **Step 5: Commit:** `fix(#2413): a feed's wma, mkv, avi and ogv enclosures are attachments, not players`

### Task 3: Reader side

**Files:**
- Modify: `backend/src/Service/Content/Reader/Media/MediaUrlKind.php`, `backend/src/Service/Content/Reader/Media/Support/LiveAudioStream.php`
- Test: `backend/tests/Service/Content/Reader/Media/MediaUrlKindTest.php`, `backend/tests/Service/Content/Reader/Media/Support/LiveAudioStreamTest.php`

**Interfaces:**
- Consumes: the `PlayableMediaFile` methods from Task 1.
- `MediaUrlKind` drops `AUDIO_EXTENSIONS`/`VIDEO_EXTENSIONS` and keeps `STREAM_EXTENSIONS`.
- `LiveAudioStream` drops `AUDIO_EXTENSIONS`. A stream-stem URL is live when its extension is `''` or `PlayableMediaFile::isAudioExtension($extension)`.

- [ ] **Step 1: Write the tests.**
  - In `MediaUrlKindTest`, add `testRefusesMediaBrowsersDoNotPlay`, a provider over `https://cdn.test/a.wma`, `/v.mkv`, `/v.avi` and `/v.ogv`, each `resolve()` returning `null`. This pins today's behaviour.
  - In `LiveAudioStreamTest::liveStreams()`, add `yield 'flac mount' => ['https://radio.test/stream.flac'];`.
- [ ] **Step 2: Run** both test files. Expected: the `flac mount` case FAILS and the rest PASS.
- [ ] **Step 3: Implement** both swaps.
- [ ] **Step 4: Run** `php bin/phpunit tests/Service/Content/Reader`. Expected: PASS.
- [ ] **Step 5: Commit:** `refactor(#2413): the reader asks PlayableMediaFile for audio and video`

### Task 4: Gates

- [ ] `grep -rn "'wma'\|'mkv'\|'flac'" backend/src` shows only `PlayableMediaFile` and `FeedMediaClassifier`.
- [ ] Run `composer check`, `composer md` on the touched files, and the full `composer test:parallel`, all green. Then run `composer infection:diff`; the score must be ≥ `minMsi`.
