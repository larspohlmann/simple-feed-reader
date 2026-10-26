# Chapters of embedded YouTube videos (#1824) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** A post that embeds YouTube videos (Trancentral's "Psytrance mixes") shows each video's chapters under its embed, and Listen (#1815) hands the audio player that video's chapters, so #1810's player surfaces show them.

**Architecture:** A new endpoint `GET /api/youtube/videos/{videoId}/chapters` reads the video's description from YouTube's keyless player endpoint, applies #1808's `DescriptionChapters` rule to it and caches the answer per video id. The article body asks for it once per YouTube API-player frame that the entry's own chapters don't cover. `ArticleChapters` changes from holding one list to holding one list per frame, and the Listen wiring and the queue back-fill read the fetched chapters per embed URL.

**Tech Stack:** Symfony 7.4 (PHP 8.4), PHPUnit 12; Angular 20 (signals, standalone), Jest.

**Spec:** GitHub issue #1824. Background: #1808's design `docs/superpowers/specs/2026-10-25-chapters-design.md` (the description rule), #1815 (Listen), #1810 (chapters in the player).

## Measured (2026-10-26)

- `POST https://www.youtube.com/youtubei/v1/player?prettyPrint=false`, `Content-Type: application/json`, body `{"videoId":"570hdDp8l4g","context":{"client":{"clientName":"WEB","clientVersion":"2.20241001.00.00","hl":"en"}}}` answers about 13 KB of JSON whose `videoDetails.shortDescription` is the description as plain text (newlines, bare URLs). From the Mac and from the Strato host it returned the same 19 stamps. `playabilityStatus.status` reads `UNPLAYABLE` without a session; it is irrelevant here, so it is not checked.
- The Trancentral post `psytrance-mixes-week-43-2026` embeds `570hdDp8l4g`, `N-2_zkN25s4`, `P1Kncl21hEU`, `RYHYRmr3344` and `SxcUgxsPahg`; four have chapter blocks (19, 0, 8, 15 and 15 stamps). The post has no timestamps of its own.
- Every YouTube video frame in a rendered body already carries `enablejsapi=1` (`withPlayerApi`), so `YouTubeChapterPlayer` can drive any of them.

## Global Constraints

- CLAUDE.md applies in full: Clean Code names, `final readonly` services, one role per folder (`docs/architecture.md` §10), typed exceptions next to their service and mapped in `src/Http/Problem/ExceptionProblems/*Problems`, HTTP statuses as `Response::HTTP_*`, comments only where a reader would otherwise get the code wrong.
- Gates: `composer check`, `composer md` (every touched `src` file PHPMD-clean), `php bin/phpunit` and `docker compose exec php composer test`, `composer infection:diff`; frontend `npm run check` and `npm run build`. Frontend Jest only in the container (`docker compose exec -T frontend npx jest <paths>`), one Jest process at a time.
- iOS viability (`docs/architecture.md` §6): bearer auth, JSON in, `application/problem+json` out, no browser-only input.
- Outbound requests go through `GuardedApiClient` (SSRF guard, budget, size cap). The detail request already spends up to 3 s on a chapters file; this endpoint is a separate request with its own 3 s budget.
- Cache: 30 days for a video whose description has chapters, 1 day for one without; a transient failure is never cached.
- Commits `feat(#1824): …`, `test(#1824): …`, `docs(#1824): …`, no attribution lines. Branch `feature/1824-embedded-video-chapters`. Merge `develop` in regularly.

## File Structure

| File | Responsibility |
|---|---|
| Create `backend/src/Service/Chapter/VideoDescriptionSource/VideoDescriptionSourceInterface.php` | `descriptionOf(string $videoId): string` |
| Create `backend/src/Service/Chapter/VideoDescriptionSource/YouTubePlayerDescriptionSource.php` | The keyless player call through `GuardedApiClient` |
| Create `backend/src/Service/Chapter/Exception/VideoDescriptionUnreachableException.php` | YouTube didn't answer; nothing is cached |
| Modify `backend/src/Service/Fetch/Model/ResponseSizeLimit.php` | `case VideoPlayer = 512;` (kB) |
| Create `backend/src/Service/Chapter/VideoChaptersCache.php` | The `video.chapters.cache` pool, keyed by video id |
| Create `backend/src/Service/Chapter/VideoChapters.php` | Cache, else source + `DescriptionChapters`, then remember |
| Create `backend/src/Controller/Api/YouTubeVideoChaptersController.php` | The route, the per-user limiter |
| Create `backend/src/Http/VideoChaptersJson.php` | `{ "chapters": [...] }` |
| Create `backend/src/Http/Problem/ExceptionProblems/ChapterProblems.php` | Unreachable → 503 |
| Modify `backend/config/packages/cache.yaml`, `rate_limiter.yaml` | The pool and the `video_chapters` limiter |
| Modify `frontend/src/app/reader/reader-api.ts` | `videoChapters(videoId)` |
| Modify `frontend/src/app/reader/article/decorators/reader-chapters.ts` | `addChapterList` no longer removes another list |
| Modify `frontend/src/app/reader/article/reader-view/article-chapters.ts` | One shown list per frame; `attachToFrame`; `followTrack` finds the list by URL |
| Create `frontend/src/app/reader/article/reader-view/article-embed-chapters.ts` | Asks for the chapters of a body's other video frames and remembers them per embed URL |
| Modify `frontend/src/app/reader/article/reader-view/article-body.service.ts` | Wires the fetch into `render`, `chaptersOf` and the back-fill |

Specs/tests beside every created or changed file.

---

### Task 1: A video's description from YouTube

**Files:**
- Create: `backend/src/Service/Chapter/VideoDescriptionSource/VideoDescriptionSourceInterface.php`
- Create: `backend/src/Service/Chapter/VideoDescriptionSource/YouTubePlayerDescriptionSource.php`
- Create: `backend/src/Service/Chapter/Exception/VideoDescriptionUnreachableException.php`
- Modify: `backend/src/Service/Fetch/Model/ResponseSizeLimit.php`
- Test: `backend/tests/Service/Chapter/VideoDescriptionSource/YouTubePlayerDescriptionSourceTest.php`, `backend/tests/Service/Chapter/Exception/VideoDescriptionUnreachableExceptionTest.php`

**Interfaces:**
- Produces: `VideoDescriptionSourceInterface::descriptionOf(string $videoId): string` (`''` when the video has no description or YouTube answers without one; throws `VideoDescriptionUnreachableException` on a network failure or a transient status), `ResponseSizeLimit::VideoPlayer`.

The pattern is `GuardedChapterFileSource`: read it and its test first and mirror both.

```php
/** Reads a video's description from YouTube's player endpoint, which needs no key (#1824). */
final readonly class YouTubePlayerDescriptionSource implements VideoDescriptionSourceInterface
{
    public const float BUDGET_SECONDS = 3.0;
    private const string PLAYER = 'https://www.youtube.com/youtubei/v1/player?prettyPrint=false';
    private const string CLIENT_VERSION = '2.20241001.00.00';

    public function __construct(private GuardedApiClient $api)
    {
    }

    public function descriptionOf(string $videoId): string
    {
        try {
            $response = $this->api->sendWithin(
                ApiRequestModel::postJson(self::PLAYER, self::playerRequest($videoId)),
                new ApiBudgetModel(self::BUDGET_SECONDS, self::BUDGET_SECONDS, ResponseSizeLimit::VideoPlayer),
            );
        } catch (SsrfBlockedException | ResponseTooLargeException) {
            return '';
        } catch (FetchException $exception) {
            throw VideoDescriptionUnreachableException::from($videoId, $exception);
        }
        if ($response->isTransientFailure()) {
            throw VideoDescriptionUnreachableException::answered($videoId, $response->status);
        }

        return $response->isSuccessful() ? self::descriptionIn($response->json()) : '';
    }

    /** @return array<string, mixed> */
    private static function playerRequest(string $videoId): array
    {
        return [
            'videoId' => $videoId,
            'context' => ['client' => ['clientName' => 'WEB', 'clientVersion' => self::CLIENT_VERSION, 'hl' => 'en']],
        ];
    }

    /** @param array<mixed> $player */
    private static function descriptionIn(array $player): string
    {
        $details = $player['videoDetails'] ?? null;
        $description = \is_array($details) ? ($details['shortDescription'] ?? null) : null;

        return \is_string($description) ? $description : '';
    }
}
```

`ApiResponseModel::json()` returns `array<mixed>` (`[]` for a body that isn't JSON), which `descriptionIn` takes as is. `ResponseSizeLimit::VideoPlayer = 512` with a one-line docblock only if the existing cases carry one for their reason (the measured answer is about 13 KB; 512 kB leaves room for long descriptions without buffering a page). The exception mirrors `ChapterFileUnreachableException` (`from`, `answered`), with the docblock "YouTube did not answer for the video; nothing is cached, the next open asks again."

- [ ] **Step 1: Write the failing tests** (`MockHttpClient`, `FetchWiring::guardedApiClient`, `StaticDnsResolver(['www.youtube.com' => ['93.184.216.34']])` as in `GuardedChapterFileSourceTest`):
  - reads `videoDetails.shortDescription` from a 200 answer;
  - sends `POST` to the player URL with a JSON body whose `videoId` is the asked id and `context.client.clientName` is `WEB` (capture `$options['body']` in a callback `MockHttpClient`);
  - a 200 without `videoDetails`, or with a non-string `shortDescription`, gives `''`;
  - 404 and a 3xx give `''`; 408, 429, 500, 503 throw `VideoDescriptionUnreachableException` (data provider with `Response::HTTP_*`);
  - a network error (`['error' => 'Connection reset']`) throws;
  - the request carries `timeout` and `max_duration` 3.0 and `max_redirects` 0;
  - an answer one byte over `ResponseSizeLimit::VideoPlayer->bytes()` gives `''`.
  - The exception test mirrors `ChapterFileUnreachableExceptionTest`.
- [ ] **Step 2: Run them, expect FAIL** — `php bin/phpunit tests/Service/Chapter/VideoDescriptionSource tests/Service/Chapter/Exception`
- [ ] **Step 3: Implement** the interface, the source, the exception and the enum case.
- [ ] **Step 4: Run them, expect PASS**; `composer cs`, `composer md`.
- [ ] **Step 5: Commit** — `feat(#1824): read a YouTube video's description from the player endpoint`

---

### Task 2: A video's chapters, cached

**Files:**
- Create: `backend/src/Service/Chapter/VideoChaptersCache.php`, `backend/src/Service/Chapter/VideoChapters.php`
- Modify: `backend/config/packages/cache.yaml`
- Test: `backend/tests/Service/Chapter/VideoChaptersTest.php`, `backend/tests/Service/Chapter/VideoChaptersCacheTest.php`

**Interfaces:**
- Consumes: `VideoDescriptionSourceInterface` (Task 1), `DescriptionChapters::in(string $html): ServedChaptersModel`, `LinkedPlainText::asHtml(string $text): ?string`, `EntryChapter::isComplete/fromStored/jsonSerialize`.
- Produces: `VideoChapters::of(string $videoId): list<EntryChapter>` (throws `VideoDescriptionUnreachableException`).

```php
/** A video's chapters from its description, kept per video so every reader of every post shares one fetch. */
final readonly class VideoChapters
{
    public function __construct(
        private VideoChaptersCache $cache,
        private VideoDescriptionSourceInterface $descriptions,
    ) {
    }

    /** @return list<EntryChapter> */
    public function of(string $videoId): array
    {
        $cached = $this->cache->find($videoId);
        if ($cached !== null) {
            return $cached;
        }

        $chapters = self::chaptersIn($this->descriptions->descriptionOf($videoId));
        $this->cache->remember($videoId, $chapters);

        return $chapters;
    }

    /** @return list<EntryChapter> */
    private static function chaptersIn(string $description): array
    {
        $html = LinkedPlainText::asHtml($description);

        return $html === null ? [] : DescriptionChapters::in($html)->chapters;
    }
}
```

`VideoChaptersCache` mirrors `SocialCountsCache`: `#[Autowire(service: 'video.chapters.cache')] CacheItemPoolInterface`, key `'video_chapters.' . $videoId` (the route's requirement already restricts the id to `[A-Za-z0-9_-]{11}`, which are all PSR-6-legal key characters), `find(string $videoId): ?array` returning `null` on a miss or on a stored value that isn't a list of complete chapters, and `remember(string $videoId, array $chapters): void` storing `EntryMedia::toJsonList($chapters)` with `expiresAfter(self::FOUND_SECONDS)` (30 days) when the list is non-empty, else `self::NONE_SECONDS` (1 day). In `cache.yaml`, after `social.profile_visibility.cache`:

```yaml
            # Chapters from a YouTube video's description, per video (#1824). VideoChaptersCache sets each lifetime;
            # this one is the backstop.
            video.chapters.cache:
                adapter: cache.adapter.filesystem
                default_lifetime: 2592000
```

Also add it to `when@test` as `cache.adapter.array`, so no test inherits another's answer.

- [ ] **Step 1: Write the failing tests.** `VideoChaptersTest` with a stub `VideoDescriptionSourceInterface` and a `VideoChaptersCache` over an `ArrayAdapter`:
  - a description with the block `0:00 Intro\n3:10 Track two\n7:45 Track three` gives three chapters (starts 0, 190, 465, titles as written);
  - a description without stamps gives `[]`; an empty description gives `[]`;
  - a second call is answered from the cache (the stub counts calls: 1);
  - an unreachable source throws, and the next call asks again (count 2);
  - one of the real descriptions as a fixture: save `videoDetails.shortDescription` of `570hdDp8l4g` (fetch it with the request in "Measured") as `backend/tests/Fixtures/Chapter/youtube-description-570hdDp8l4g.txt` and assert its 19 chapters' first and last start.
  - `VideoChaptersCacheTest`: a found list is saved with `expiresAfter(2592000)` and an empty one with `expiresAfter(86400)` (a mocked `CacheItemPoolInterface`/`CacheItemInterface` expecting `expiresAfter`, as `BlueskySessionCacheTest::testARememberedSessionLivesAnHourAndAHalf` does); a remembered list reads back equal; a stored non-list, or a list with an incomplete chapter, reads as a miss.
- [ ] **Step 2: Run them, expect FAIL** — `php bin/phpunit tests/Service/Chapter`
- [ ] **Step 3: Implement**
- [ ] **Step 4: Run them, expect PASS**; `composer stan` (warm the cache first), `composer md`.
- [ ] **Step 5: Commit** — `feat(#1824): a video's chapters from its description, cached per video`

---

### Task 3: The endpoint

**Files:**
- Create: `backend/src/Controller/Api/YouTubeVideoChaptersController.php`
- Create: `backend/src/Http/VideoChaptersJson.php`
- Create: `backend/src/Http/Problem/ExceptionProblems/ChapterProblems.php`
- Modify: `backend/config/packages/rate_limiter.yaml`
- Test: `backend/tests/Controller/Api/YouTubeVideoChaptersControllerTest.php`, `backend/tests/Http/Problem/ExceptionProblems/ChapterProblemsTest.php`

**Interfaces:**
- Consumes: `VideoChapters::of` (Task 2), `RateLimitGuard::enforceForUser`.
- Produces: `GET /api/youtube/videos/{videoId}/chapters` → `200 {"chapters":[{"startSeconds":0,"title":"Intro"},…]}` (an empty list when the video has none); `503` problem when YouTube didn't answer; `404` for an id that isn't 11 id characters (route requirement); `401` anonymous; `429` over the limit.

```php
#[Route('/api/youtube/videos')]
final readonly class YouTubeVideoChaptersController
{
    public function __construct(
        private VideoChapters $videoChapters,
        private RateLimitGuard $rateLimitGuard,
        private RateLimiterFactoryInterface $videoChaptersLimiter,
    ) {
    }

    #[Route(
        '/{videoId}/chapters',
        name: 'api_youtube_video_chapters',
        methods: ['GET'],
        requirements: ['videoId' => YouTubeUrl::VIDEO_ID_PATTERN],
    )]
    public function chapters(string $videoId, #[CurrentUser] User $user): JsonResponse
    {
        $this->rateLimitGuard->enforceForUser($this->videoChaptersLimiter, $user);

        return new JsonResponse(VideoChaptersJson::of($this->videoChapters->of($videoId)));
    }
}
```

`security.yaml`'s `^/api/` rule (`IS_AUTHENTICATED_FULLY`) already covers the route; the 401 test pins it. `VideoChaptersJson::of(array $chapters): array{chapters: list<array{startSeconds: int, title: string}>}` returns `['chapters' => EntryMedia::toJsonList($chapters)]`. `ChapterProblems` mirrors `CommentsProblems`: `VideoDescriptionUnreachableException` → `ApiProblem::forStatus(Response::HTTP_SERVICE_UNAVAILABLE)`. Limiter, after `social_counts`:

```yaml
        # Per user: an article asks once per embedded YouTube video it shows (#1824). The cache answers most; a miss
        # is one call to YouTube.
        video_chapters:
            policy: 'sliding_window'
            limit: 120
            interval: '5 minutes'
            cache_pool: cache.rate_limiter
```

- [ ] **Step 1: Write the failing tests.** The controller test mirrors `EntrySocialCountsControllerTest`: clear `test.cache.rate_limiter` and `video.chapters.cache` in `setUp`; route `www.youtube.com` through `RoutedHttpClient` (the generic part of `RoutesSocialApis::clientRouting`: if you need it here as well, move `clientRouting` and `signIn` into a trait `RoutesOutboundApis` that `RoutesSocialApis` uses; don't copy them). Cases:
  - anonymous → 401;
  - a video with a chapter block → 200 and the chapters, one outbound `POST https://www.youtube.com/youtubei/v1/player?prettyPrint=false`;
  - a second request → the same body, still one outbound request;
  - a video without chapters → 200 `{"chapters":[]}`;
  - YouTube answers 503 → 503 `application/problem+json`, and a following request asks YouTube again;
  - `/api/youtube/videos/short/chapters` → 404;
  - the 121st request in the window → 429 (const `CHAPTERS_BUDGET = 120` with the "must match rate_limiter.yaml" docblock the social test uses).
  - `ChapterProblemsTest` mirrors `SocialInteractionProblemsTest`: the exception maps to 503; another exception maps to null.
- [ ] **Step 2: Run them, expect FAIL**
- [ ] **Step 3: Implement**
- [ ] **Step 4: Run them, expect PASS**; `composer check`, `composer md`; the full `php bin/phpunit` and `docker compose exec php composer test`.
- [ ] **Step 5: Live probe** in the Docker stack: `curl -sk -H "Authorization: Bearer <a dev token>" https://localhost:8443/api/youtube/videos/570hdDp8l4g/chapters | jq '.chapters | length'` gives 19. Scan today's dev log (`ls -t backend/var/log/dev-*.log | head -1`) for warnings.
- [ ] **Step 6: Commit** — `feat(#1824): GET /api/youtube/videos/{id}/chapters`

---

### Task 4: One chapter list per video frame

**Files:**
- Modify: `frontend/src/app/reader/article/decorators/reader-chapters.ts`, `reader-chapters.spec.ts`
- Modify: `frontend/src/app/reader/article/reader-view/article-chapters.ts`, `article-chapters.spec.ts`

**Interfaces:**
- Produces:
  - `addChapterList(place, list, player)` no longer removes another list from `host`; its docblock loses "it replaces any earlier list in `host`".
  - `ArticleChapters.attach(host, list, track)` unchanged in behaviour: the body's own chapters, on the episode or on the first API frame.
  - `ArticleChapters.attachToFrame(frame: HTMLIFrameElement, list: ChapterList): void` — a list after that frame's `.reader-cinema`/`.reader-embed`, driving the frame; driving the audio player instead when the player's current track is that frame's embed URL.
  - `ArticleChapters.followTrack(track: AudioTrack): void` — re-points the list whose embed URL is `track.url` (falls back to the body's own list when it has no URL, as today).
  - `ArticleChapters.release()` — releases and removes every list.

Today `shown` is one record. Make it a list of records `{ place, list, player, element, mediaUrl: string | null }`, where `mediaUrl` is `embedMediaOfFrame(frame)?.url ?? null` for a frame list and `null` for the episode's list. `followTrack` picks the record whose `mediaUrl === track.url`, else the one record whose `place.after` is the first API frame's box (the body's own YouTube list, which is how #1815's Listen on a YouTube entry works today); with neither, it does nothing. Re-pointing a record calls `addChapterList` with the same `place` — so the old element must be removed first (`element?.remove()`), as the removal no longer happens inside `addChapterList`.

`attachToFrame` reads `this.audio.current()?.url` for the "already playing in the audio player" case, so a list that arrives after Listen drives the player, not the paused frame.

- [ ] **Step 1: Write the failing specs** in `article-chapters.spec.ts` (two frames with `enablejsapi=1` inside `.reader-embed` boxes):
  - `attachToFrame` puts a list after each frame's box; both lists exist;
  - `attach` with body chapters plus `attachToFrame` on the second frame gives two lists;
  - a tap on the second list's row posts `seekTo` to the second frame only;
  - `followTrack` with the second frame's embed URL re-points only the second list (a tap now calls `audio.playFrom`/the `AudioChapterPlayer` path the existing spec already checks), and the first list still drives its frame;
  - `attachToFrame` while `audio.current()` is that frame's track drives the audio player;
  - `release` removes every list.
  - `reader-chapters.spec.ts`: replace the "replaces an earlier list" case with "leaves another list in the host".
- [ ] **Step 2: Run them, expect FAIL** — `docker compose exec -T frontend npx jest src/app/reader/article`
- [ ] **Step 3: Implement**
- [ ] **Step 4: Run them, expect PASS**
- [ ] **Step 5: Commit** — `refactor(#1824): one chapter list per video frame`

---

### Task 5: The body asks for its videos' chapters

**Files:**
- Modify: `frontend/src/app/reader/reader-api.ts`, `reader-api.spec.ts`
- Create: `frontend/src/app/reader/article/reader-view/article-embed-chapters.ts`, `.spec.ts`
- Modify: `frontend/src/app/reader/article/reader-view/article-body.service.ts`, `.spec.ts`
- Modify: `frontend/src/app/reader/models.ts` (or beside `ChapterDto`): `VideoChaptersDto { chapters: ChapterDto[] }`

**Interfaces:**
- Consumes: `ArticleChapters.attachToFrame` (Task 4), `youTubeVideoId(url)` and `embedMediaOfFrame(frame)` (`audio/embed-media.ts`), `AudioPlayerService.describeChapters(url, chapters)`, the endpoint (Task 3).
- Produces:
  - `ReaderApi.videoChapters(videoId: string): Observable<VideoChaptersDto>` — `GET ${base}/api/youtube/videos/${videoId}/chapters`.
  - `ArticleEmbedChapters` (plain class, built like `ArticleChapters` with what it needs passed in):

```ts
/** The chapters of a body's embedded YouTube videos, asked for once per frame the entry's own chapters don't cover (#1824). */
export class ArticleEmbedChapters {
  private readonly byEmbedUrl = new Map<string, ChapterDto[]>();
  private subscriptions: Subscription[] = [];

  constructor(private readonly api: ReaderApi) {}

  /** Asks for every frame in `frames`; `found` runs once per frame whose video has chapters. */
  load(frames: HTMLIFrameElement[], found: (frame: HTMLIFrameElement, chapters: ChapterDto[]) => void): void;

  /** The chapters already known for an embed URL; [] before they arrive or when the video has none. */
  chaptersOf(embedUrl: string): ChapterDto[];

  release(): void; // unsubscribes and forgets
}
```

`load` skips a frame without a YouTube video id and asks once per distinct video id. A failed request (`catchError(() => EMPTY)`) shows nothing: no list is the honest answer when YouTube or the limiter refused.

**Wiring in `ArticleBody.render`:** the frames to ask for are every `iframe[src*="enablejsapi=1"]` in the host (add `apiPlayerFrames(host): HTMLIFrameElement[]` beside `apiPlayerFrame` in `article-chapters.ts` and let `apiPlayerFrame` return its first element) **except** the frame `chapteredEmbedOf` already gives the body's chapters to, and except a frame whose video is the entry's own (`youTubeVideoId(parts.entry()?.url ?? '')`): a YouTube entry's own description already went through the same rule on the server. For each found frame: `this.chapters.attachToFrame(frame, { chapters, heading })` and `this.audio.describeChapters(embedUrl, chapters)` (a video queued from elsewhere, or Listened before the answer came, gains its chapters). `chaptersOf` in the Listen context becomes:

```ts
chaptersOf: (media) =>
  media.url === chapteredEmbed()?.url ? chapterList : this.embedChapters.chaptersOf(media.url),
```

Release `embedChapters` in the same `onCleanup` and `DestroyRef` hooks that release `chapters` and `embedAudio`. The `load` call runs inside `zone.runOutsideAngular` like the rest; the list is plain DOM.

- [ ] **Step 1: Write the failing specs.**
  - `reader-api.spec.ts`: `videoChapters('570hdDp8l4g')` requests the URL (HttpTestingController).
  - `article-embed-chapters.spec.ts` (a stub `ReaderApi` returning `of(...)`/`throwError`): asks once per distinct video id; skips a non-YouTube frame; calls `found` only for a video with chapters; `chaptersOf` returns them by the frame's embed URL (`https://www.youtube.com/watch?v=<id>`); an error calls nothing and `chaptersOf` stays `[]`; `release` unsubscribes (a `Subject` that emits after release reaches nobody).
  - `article-body.service.spec.ts`: a body with two video frames and no entry chapters asks for both and shows two lists; a YouTube entry with its own chapters asks only for the other frame; Listen on the second embed after its answer hands a track whose `chapters` are that video's; an answer for an embed already queued calls `describeChapters` with its URL.
- [ ] **Step 2: Run them, expect FAIL**
- [ ] **Step 3: Implement**
- [ ] **Step 4: Run them, expect PASS**; `cd frontend && npm run check && npm run build`.
- [ ] **Step 5: Commit** — `feat(#1824): an article shows the chapters of its embedded YouTube videos`

---

### Task 6: Live check and PR

- [ ] **Step 1:** Apply nothing to the DB (no migration). Make sure the Docker `php` and `frontend` containers serve this branch (memory: check the container is current; `:4200` can serve a stale chunk).
- [ ] **Step 2:** On `:4200`, open the Trancentral "Psytrance mixes – Week 43 2026" post in Reader view: four of the five embeds get a chapter list under them, `N-2_zkN25s4` none. A tap on a row of the third list plays that video from the chapter; the other videos stay put.
- [ ] **Step 3:** Listen on the second embed: the mini bar names the current chapter, the big player shows the strip and the list, the scrubber shows marks (#1810's surfaces). The article's list for that video now drives the audio player.
- [ ] **Step 4:** Reload the page with the track still queued: the big player keeps its chapters (restored from the playlist), and the network panel shows the chapters request answered from the cache (fast; the backend log shows no second outbound call).
- [ ] **Step 5:** A YouTube channel entry with description chapters (#1808's case) still shows one list and makes no `/api/youtube/videos/…` request for its own video.
- [ ] **Step 6:** `composer check`, `composer md`, both PHPUnit legs, `composer infection:diff`, `npm run check`, `npm run build`. Merge `develop`, open the PR with `Closes #1824`.
