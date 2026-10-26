# Chapters of embedded YouTube videos and SoundCloud tracks (#1824) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** A post that embeds YouTube videos or SoundCloud tracks (Trancentral's "Psytrance mixes", an Anjunadeep mix) shows, under **each** embed whose description has a chapter block, a clickable chapter list: a tap plays that embed from the chapter, and the list follows its playback. Listen (#1815) hands the audio player that embed's chapters, so #1810's player surfaces show them.

**Architecture:** One endpoint per provider, `GET /api/youtube/videos/{videoId}/chapters` and `GET /api/soundcloud/tracks/{trackId}/chapters`, both served by one `EmbedChapters` service: it reads the embed's description through the provider's `EmbedDescriptionSourceInterface` (YouTube's keyless player endpoint, SoundCloud's oEmbed), applies #1808's `DescriptionChapters` rule and caches the answer per embed. The article body asks once per embed frame the entry's own chapters don't cover. `ArticleChapters` changes from holding one list to one list per frame, driving the frame through `YouTubeChapterPlayer` or a new `SoundCloudChapterPlayer`. The Listen wiring and the queue back-fill read the fetched chapters per embed URL.

**Tech Stack:** Symfony 7.4 (PHP 8.4), PHPUnit 12; Angular 20 (signals, standalone), Jest.

**Spec:** GitHub issue #1824. Background: #1808's design `docs/superpowers/specs/2026-10-25-chapters-design.md` (the description rule, the chapter list), #1815 (Listen, the SoundCloud widget protocol), #1810 (chapters in the player).

## Measured (2026-10-26)

- **YouTube.** `POST https://www.youtube.com/youtubei/v1/player?prettyPrint=false`, `Content-Type: application/json`, body `{"videoId":"570hdDp8l4g","context":{"client":{"clientName":"WEB","clientVersion":"2.20241001.00.00","hl":"en"}}}` answers about 13 KB of JSON; `videoDetails.shortDescription` is the description as plain text. Same 19 stamps from the Mac and from the Strato host. `playabilityStatus.status` reads `UNPLAYABLE` without a session and is irrelevant here.
- **The Trancentral post** `psytrance-mixes-week-43-2026` embeds `570hdDp8l4g`, `N-2_zkN25s4`, `P1Kncl21hEU`, `RYHYRmr3344`, `SxcUgxsPahg`; their descriptions carry 19, 0, 8, 15 and 15 stamps. The post has none of its own.
- **SoundCloud.** `GET https://soundcloud.com/oembed?format=json&url=https%3A%2F%2Fapi.soundcloud.com%2Ftracks%2F<id>` needs no key and returns `description`, identical to the track page's (2683 of 2683 characters for `anjunadeep/catching-flies-anjunadeep-open`); it answers from Strato too. That mix's tracklist is `[00:00] 1. Jon Hopkins/Olafur Arnalds - Forever Held` … `[01:14:07] 32. Tourist - Protector`, which #1808's rule already reads (bracketed stamps, hour form, `0:00` start). Of 28 sampled tracks from 10 labels and artists only that one had a tracklist: SoundCloud chapters are rarer than YouTube's, so the cost per embed must stay one cached request.
- **SoundCloud embeds are normalised.** `SoundCloudEmbedProvider` rebuilds every widget as `https://w.soundcloud.com/player/?url=https%3A%2F%2Fapi.soundcloud.com%2Ftracks%2F<digits>`, so a numeric track id identifies every embed.
- **A late-attached SoundCloud widget obeys.** In Chromium, posting to a widget that had loaded six seconds earlier (its `ready` unheard): `addEventListener playProgress`/`play`, `getDuration` → `6443230` ms; `play` → `playProgress` arrives; `seekTo 600000` after `play` → position `601684` ms. The seek-after-play rule matches `SoundCloudPlayback.seekOnPlay` (#1815).
- Every YouTube video frame in a rendered body already carries `enablejsapi=1` (`withPlayerApi`), so `YouTubeChapterPlayer` can drive any of them.

## Global Constraints

- CLAUDE.md applies in full: Clean Code names, `final readonly` services, one role per folder (`docs/architecture.md` §10), strategies through a tag and a keyed locator (`Service/Refresh/FeedBodyParser.php`), typed exceptions next to their service and mapped in `src/Http/Problem/ExceptionProblems/*Problems`, HTTP statuses as `Response::HTTP_*`, comments only where a reader would otherwise get the code wrong.
- Gates: `composer check`, `composer md` (every touched `src` file PHPMD-clean), `php bin/phpunit` and `docker compose exec php composer test`, `composer infection:diff`; frontend `npm run check` and `npm run build`. Frontend Jest only in the container (`docker compose exec -T frontend npx jest <paths>`), one Jest process at a time.
- iOS viability (`docs/architecture.md` §6): bearer auth, JSON out, `application/problem+json` errors, no browser-only input.
- Outbound requests go through `GuardedApiClient` (SSRF guard, budget, size cap), 3 s budget each.
- Cache: 30 days for an embed whose description has chapters, 1 day for one without; a transient failure is never cached.
- Commits `feat(#1824): …`, `refactor(#1824): …`, `test(#1824): …`, no attribution lines. Branch `feature/1824-embedded-video-chapters`. Merge `develop` in regularly.

## File Structure

| File | Responsibility |
|---|---|
| Create `backend/src/Service/Chapter/Model/ChapteredEmbed.php` | Enum `youtube`, `soundcloud`: the providers whose embeds have chapter descriptions |
| Create `backend/src/Service/Chapter/Model/EmbedAddressModel.php` | `{ ChapteredEmbed $provider, string $id }`, the cache identity |
| Create `backend/src/Service/Chapter/EmbedDescriptionSource/EmbedDescriptionSourceInterface.php` | `descriptionOf(string $id): string`, `provider()` for the locator index |
| Create `backend/src/Service/Chapter/EmbedDescriptionSource/GuardedDescriptionRequest.php` | The budgeted request and its status handling both sources share |
| Create `backend/src/Service/Chapter/EmbedDescriptionSource/YouTubePlayerDescriptionSource.php` | The keyless player call |
| Create `backend/src/Service/Chapter/EmbedDescriptionSource/SoundCloudOembedDescriptionSource.php` | The oEmbed call |
| Create `backend/src/Service/Chapter/Exception/EmbedDescriptionUnreachableException.php` | The provider didn't answer; nothing is cached |
| Modify `backend/src/Service/Fetch/Model/ResponseSizeLimit.php` | `case EmbedDescription = 512;` (kB) |
| Create `backend/src/Service/Chapter/EmbedChaptersCache.php` | The `embed.chapters.cache` pool |
| Create `backend/src/Service/Chapter/EmbedChapters.php` | Cache, else source + `DescriptionChapters`, then remember |
| Create `backend/src/Controller/Api/EmbedChaptersController.php` | The two routes, the per-user limiter |
| Create `backend/src/Http/EmbedChaptersJson.php` | `{ "chapters": [...] }` |
| Create `backend/src/Http/Problem/ExceptionProblems/ChapterProblems.php` | Unreachable → 503 |
| Modify `backend/config/packages/cache.yaml`, `rate_limiter.yaml` | The pool and the `embed_chapters` limiter |
| Modify `frontend/src/app/reader/audio/embed-media.ts` | `soundCloudTrackId(url)` |
| Modify `frontend/src/app/reader/reader-api.ts`, `models.ts` | `embedChapters(media)`, `EmbedChaptersDto` |
| Create `frontend/src/app/reader/chapters/soundcloud-chapter-player.ts` | `ChapterPlayer` over a visible SoundCloud widget |
| Modify `frontend/src/app/reader/article/decorators/reader-chapters.ts` | `addChapterList` no longer removes another list |
| Modify `frontend/src/app/reader/article/reader-view/article-chapters.ts` | One list per frame; `attachToFrame`; `chapterFrames`; `followTrack` by URL |
| Create `frontend/src/app/reader/article/reader-view/article-embed-chapters.ts` | Asks for a body's embed chapters and remembers them per embed URL |
| Modify `frontend/src/app/reader/article/reader-view/article-body.service.ts` | Wires the fetch into `render`, `chaptersOf` and the back-fill |

Specs/tests beside every created or changed file.

---

### Task 1: An embed's description from its provider

**Files:**
- Create: `backend/src/Service/Chapter/Model/ChapteredEmbed.php`, `Model/EmbedAddressModel.php`
- Create: `backend/src/Service/Chapter/EmbedDescriptionSource/EmbedDescriptionSourceInterface.php`, `GuardedDescriptionRequest.php`, `YouTubePlayerDescriptionSource.php`, `SoundCloudOembedDescriptionSource.php`
- Create: `backend/src/Service/Chapter/Exception/EmbedDescriptionUnreachableException.php`
- Modify: `backend/src/Service/Fetch/Model/ResponseSizeLimit.php`
- Test: `backend/tests/Service/Chapter/EmbedDescriptionSource/GuardedDescriptionRequestTest.php`, `YouTubePlayerDescriptionSourceTest.php`, `SoundCloudOembedDescriptionSourceTest.php`, `backend/tests/Service/Chapter/Exception/EmbedDescriptionUnreachableExceptionTest.php`

**Interfaces:**
- Produces:
  - `enum ChapteredEmbed: string { case YouTube = 'youtube'; case SoundCloud = 'soundcloud'; }`
  - `final readonly class EmbedAddressModel { public function __construct(public ChapteredEmbed $provider, public string $id) {} public function cacheKey(): string /* "{provider}.{id}" */ }`
  - `EmbedDescriptionSourceInterface`, tagged like `PostCountsSourceInterface`:
    ```php
    /** One provider's account-free way to an embed's description, keyed by its ChapteredEmbed value. */
    #[AutoconfigureTag('app.embed_description_source')]
    interface EmbedDescriptionSourceInterface
    {
        public static function provider(): string;

        /** '' when the embed has no description. @throws EmbedDescriptionUnreachableException */
        public function descriptionOf(EmbedAddressModel $embed): string;
    }
    ```
  - `GuardedDescriptionRequest::answerOf(ApiRequestModel $request, EmbedAddressModel $embed): array<mixed>` — the decoded answer, `[]` for a blocked host, an oversized body or a non-transient non-success; throws `EmbedDescriptionUnreachableException` on a network failure or a transient status.
  - `ResponseSizeLimit::EmbedDescription` (512 kB; the measured answers are 13 KB and 4 KB).

The request and status handling is `GuardedChapterFileSource::chaptersAt`'s; read it and its test first. Both sources need it, so it lives once, in `Service/Chapter/EmbedDescriptionSource/GuardedDescriptionRequest.php` (a stateless service in the interface's folder, as it serves only its implementations). `GuardedChapterFileSource` keeps its own copy: it throws another exception and returns chapters, and converging it is not this issue's work.

```php
/** One budgeted, size-capped request to a description provider (#1824). */
final readonly class GuardedDescriptionRequest
{
    public const float BUDGET_SECONDS = 3.0;

    public function __construct(private GuardedApiClient $api)
    {
    }

    /** @return array<mixed> */
    public function answerOf(ApiRequestModel $request, EmbedAddressModel $embed): array
    {
        try {
            $response = $this->api->sendWithin(
                $request,
                new ApiBudgetModel(self::BUDGET_SECONDS, self::BUDGET_SECONDS, ResponseSizeLimit::EmbedDescription),
            );
        } catch (SsrfBlockedException | ResponseTooLargeException) {
            return [];
        } catch (FetchException $exception) {
            throw EmbedDescriptionUnreachableException::from($embed, $exception);
        }
        if ($response->isTransientFailure()) {
            throw EmbedDescriptionUnreachableException::answered($embed, $response->status);
        }

        return $response->isSuccessful() ? $response->json() : [];
    }
}
```

```php
/** Reads a video's description from YouTube's player endpoint, which needs no key. */
final readonly class YouTubePlayerDescriptionSource implements EmbedDescriptionSourceInterface
{
    private const string PLAYER = 'https://www.youtube.com/youtubei/v1/player?prettyPrint=false';
    private const string CLIENT_VERSION = '2.20241001.00.00';

    public function __construct(private GuardedDescriptionRequest $request)
    {
    }

    public static function provider(): string
    {
        return ChapteredEmbed::YouTube->value;
    }

    public function descriptionOf(EmbedAddressModel $embed): string
    {
        $player = $this->request->answerOf(ApiRequestModel::postJson(self::PLAYER, [
            'videoId' => $embed->id,
            'context' => ['client' => ['clientName' => 'WEB', 'clientVersion' => self::CLIENT_VERSION, 'hl' => 'en']],
        ]), $embed);
        $details = $player['videoDetails'] ?? null;
        $description = \is_array($details) ? ($details['shortDescription'] ?? null) : null;

        return \is_string($description) ? $description : '';
    }
}
```

`SoundCloudOembedDescriptionSource` is the same shape: `provider()` returns `ChapteredEmbed::SoundCloud->value`; it asks `ApiRequestModel::get('https://soundcloud.com/oembed?' . http_build_query(['format' => 'json', 'url' => 'https://api.soundcloud.com/tracks/' . $embed->id]))` and reads the top-level string `description`. `ApiResponseModel::json()` returns `array<mixed>` (`[]` for a body that isn't JSON). The exception mirrors `ChapterFileUnreachableException`: `from(EmbedAddressModel $embed, FetchException $exception)` and `answered(EmbedAddressModel $embed, int $status)`, messages naming `$embed->provider->value` and `$embed->id`, docblock "The provider did not answer for the embed; nothing is cached, the next open asks again."

- [ ] **Step 1: Write the failing tests** (`MockHttpClient`, `FetchWiring::guardedApiClient`, `StaticDnsResolver` for `www.youtube.com` / `soundcloud.com` / `cdn.test` → `93.184.216.34`, as `GuardedChapterFileSourceTest` does).
  - `GuardedDescriptionRequestTest` (mirror `GuardedChapterFileSourceTest` case for case): a 200 gives the decoded answer; 404 and a 3xx give `[]`; 408, 429, 500, 503 throw `EmbedDescriptionUnreachableException` (data provider of `Response::HTTP_*`); a network error (`['error' => 'Connection reset']`) throws; `timeout`/`max_duration` 3.0 and `max_redirects` 0; a body at `ResponseSizeLimit::EmbedDescription->bytes()` is read and one byte over gives `[]`; a blocked host (`http://127.0.0.1/…`) gives `[]`.
  - `YouTubePlayerDescriptionSourceTest`: `POST` to the player URL with a JSON body whose `videoId` is the id and `context.client.clientName` is `WEB` (capture `$options['body']` in a callback `MockHttpClient`); reads `videoDetails.shortDescription`; no `videoDetails`, or a non-string description, gives `''`; `provider()` is `'youtube'`.
  - `SoundCloudOembedDescriptionSourceTest`: `GET https://soundcloud.com/oembed` whose `format` is `json` and whose `url` is `https://api.soundcloud.com/tracks/<id>`; reads `description`; no or a non-string `description` gives `''`; `provider()` is `'soundcloud'`.
  - `EmbedDescriptionUnreachableExceptionTest` mirrors `ChapterFileUnreachableExceptionTest`.
- [ ] **Step 2: Run them, expect FAIL** — `php bin/phpunit tests/Service/Chapter`
- [ ] **Step 3: Implement**
- [ ] **Step 4: Run them, expect PASS**; `composer cs`, `composer md`.
- [ ] **Step 5: Commit** — `feat(#1824): read an embed's description from YouTube and SoundCloud`

---

### Task 2: An embed's chapters, cached

**Files:**
- Create: `backend/src/Service/Chapter/EmbedChaptersCache.php`, `backend/src/Service/Chapter/EmbedChapters.php`
- Modify: `backend/config/packages/cache.yaml`
- Create: `backend/tests/Fixtures/Chapter/youtube-description-570hdDp8l4g.txt`, `soundcloud-description-catching-flies.txt`
- Test: `backend/tests/Service/Chapter/EmbedChaptersTest.php`, `EmbedChaptersCacheTest.php`

**Interfaces:**
- Consumes: Task 1; `DescriptionChapters::in(string $html): ServedChaptersModel`; `LinkedPlainText::asHtml(string $text): ?string`; `EntryChapter::isComplete/fromStored`; `EntryMedia::toJsonList`.
- Produces: `EmbedChapters::of(EmbedAddressModel $embed): list<EntryChapter>` (throws `EmbedDescriptionUnreachableException`).

```php
/** An embed's chapters from its description, kept per embed so every reader of every post shares one fetch (#1824). */
final readonly class EmbedChapters
{
    public function __construct(
        private EmbedChaptersCache $cache,
        #[AutowireLocator('app.embed_description_source', defaultIndexMethod: 'provider')]
        private ContainerInterface $sources,
    ) {
    }

    /** @return list<EntryChapter> */
    public function of(EmbedAddressModel $embed): array
    {
        $cached = $this->cache->find($embed);
        if ($cached !== null) {
            return $cached;
        }

        $chapters = self::chaptersIn($this->sourceFor($embed->provider)->descriptionOf($embed));
        $this->cache->remember($embed, $chapters);

        return $chapters;
    }

    /** @return list<EntryChapter> */
    private static function chaptersIn(string $description): array
    {
        $html = LinkedPlainText::asHtml($description);

        return $html === null ? [] : DescriptionChapters::in($html)->chapters;
    }

    private function sourceFor(ChapteredEmbed $provider): EmbedDescriptionSourceInterface
    {
        $source = $this->sources->get($provider->value);
        \assert($source instanceof EmbedDescriptionSourceInterface);

        return $source;
    }
}
```

`EmbedChaptersCache` mirrors `SocialCountsCache`: `#[Autowire(service: 'embed.chapters.cache')] CacheItemPoolInterface`, key `'embed_chapters.' . $embed->cacheKey()` (ids are `[A-Za-z0-9_-]` or digits, all PSR-6-legal), `find(EmbedAddressModel): ?array` — `null` on a miss or a stored value that isn't a list of complete chapters — and `remember(EmbedAddressModel, array $chapters): void` storing `EntryMedia::toJsonList($chapters)` with `expiresAfter(self::FOUND_SECONDS)` (2592000) for a non-empty list, else `self::NONE_SECONDS` (86400). `cache.yaml`, after `social.profile_visibility.cache`:

```yaml
            # Chapters from an embedded video's or track's description, per embed (#1824). EmbedChaptersCache sets
            # each lifetime; this one is the backstop.
            embed.chapters.cache:
                adapter: cache.adapter.filesystem
                default_lifetime: 2592000
```

and under `when@test` as `cache.adapter.array`.

Fixtures: save the two descriptions as fetched with the requests in "Measured" (`videoDetails.shortDescription` of `570hdDp8l4g`; `description` of the oEmbed for `https://soundcloud.com/anjunadeep/catching-flies-anjunadeep-open`).

- [ ] **Step 1: Write the failing tests.** `EmbedChaptersTest` with stub sources in a `ServiceLocator` and the cache over an `ArrayAdapter`:
  - `0:00 Intro\n3:10 Track two\n7:45 Track three` gives starts 0, 190, 465 and the titles;
  - the YouTube fixture gives 19 chapters (assert the first and last start);
  - the SoundCloud fixture gives 32 chapters, the first `0` / `1. Jon Hopkins/Olafur Arnalds - Forever Held`, the last `4447` (`01:14:07`);
  - a description without stamps, and an empty one, give `[]`;
  - the provider picks the source (a YouTube address never calls the SoundCloud stub);
  - a second call is answered from the cache (the stub counts 1 call);
  - an unreachable source throws, and the next call asks again (count 2).
  - `EmbedChaptersCacheTest`: a found list is saved with `expiresAfter(2592000)`, an empty one with `expiresAfter(86400)` (mocked pool and item expecting `expiresAfter`, as `BlueskySessionCacheTest::testARememberedSessionLivesAnHourAndAHalf`); a remembered list reads back equal over an `ArrayAdapter`; a stored non-list, or a list with an incomplete chapter, reads as a miss; a YouTube and a SoundCloud address with the same id don't share an entry.
- [ ] **Step 2: Run them, expect FAIL**
- [ ] **Step 3: Implement**
- [ ] **Step 4: Run them, expect PASS**; `composer stan` (after `bin/console cache:warmup`), `composer md`.
- [ ] **Step 5: Commit** — `feat(#1824): an embed's chapters from its description, cached per embed`

---

### Task 3: The endpoints

**Files:**
- Create: `backend/src/Controller/Api/EmbedChaptersController.php`, `backend/src/Http/EmbedChaptersJson.php`, `backend/src/Http/Problem/ExceptionProblems/ChapterProblems.php`
- Modify: `backend/config/packages/rate_limiter.yaml`
- Test: `backend/tests/Controller/Api/EmbedChaptersControllerTest.php`, `backend/tests/Http/Problem/ExceptionProblems/ChapterProblemsTest.php`

**Interfaces:**
- Consumes: `EmbedChapters::of` (Task 2), `RateLimitGuard::enforceForUser`, `YouTubeUrl::VIDEO_ID_PATTERN`.
- Produces:
  - `GET /api/youtube/videos/{videoId}/chapters` (`videoId` matches `YouTubeUrl::VIDEO_ID_PATTERN`)
  - `GET /api/soundcloud/tracks/{trackId}/chapters` (`trackId` matches `\d{1,20}`)
  - both → `200 {"chapters":[{"startSeconds":0,"title":"…"},…]}` (empty list when none); `503` problem when the provider didn't answer; `404` for an id the requirement refuses; `401` anonymous; `429` over the limit.

```php
final readonly class EmbedChaptersController
{
    public function __construct(
        private EmbedChapters $embedChapters,
        private RateLimitGuard $rateLimitGuard,
        private RateLimiterFactoryInterface $embedChaptersLimiter,
    ) {
    }

    #[Route('/api/youtube/videos/{videoId}/chapters', name: 'api_youtube_video_chapters', methods: ['GET'],
        requirements: ['videoId' => YouTubeUrl::VIDEO_ID_PATTERN])]
    public function video(string $videoId, #[CurrentUser] User $user): JsonResponse
    {
        $this->rateLimitGuard->enforceForUser($this->embedChaptersLimiter, $user);
        $chapters = $this->embedChapters->of(new EmbedAddressModel(ChapteredEmbed::YouTube, $videoId));

        return new JsonResponse(EmbedChaptersJson::of($chapters));
    }

    #[Route('/api/soundcloud/tracks/{trackId}/chapters', name: 'api_soundcloud_track_chapters', methods: ['GET'],
        requirements: ['trackId' => '\d{1,20}'])]
    public function track(string $trackId, #[CurrentUser] User $user): JsonResponse
    {
        $this->rateLimitGuard->enforceForUser($this->embedChaptersLimiter, $user);
        $chapters = $this->embedChapters->of(new EmbedAddressModel(ChapteredEmbed::SoundCloud, $trackId));

        return new JsonResponse(EmbedChaptersJson::of($chapters));
    }
}
```

No private helper: `ThinControllerRule` forbids one that carries work, and the two actions share three lines. Constructing `EmbedAddressModel` in a controller is fine: it's a Service model, not a Doctrine-mapped class. `security.yaml`'s `^/api/` rule (`IS_AUTHENTICATED_FULLY`) already covers both routes; the 401 test pins it. `EmbedChaptersJson::of(array $chapters): array{chapters: list<array{startSeconds: int, title: string}>}` returns `['chapters' => EntryMedia::toJsonList($chapters)]`. `ChapterProblems` mirrors `CommentsProblems`: `EmbedDescriptionUnreachableException` → `ApiProblem::forStatus(Response::HTTP_SERVICE_UNAVAILABLE)`. Limiter after `social_counts`:

```yaml
        # Per user: an article asks once per embedded video or track it shows (#1824). The cache answers most; a miss
        # is one call to YouTube or SoundCloud.
        embed_chapters:
            policy: 'sliding_window'
            limit: 120
            interval: '5 minutes'
            cache_pool: cache.rate_limiter
```

- [ ] **Step 1: Write the failing tests.** The controller test mirrors `EntrySocialCountsControllerTest`: clear `test.cache.rate_limiter` and `embed.chapters.cache` in `setUp`; route `www.youtube.com` and `soundcloud.com` through `RoutedHttpClient`. `RoutesSocialApis::clientRouting` and `signIn` aren't social-specific: move them into a trait `tests/Support/RoutesOutboundApis.php` that `RoutesSocialApis` uses, rather than copying them. Cases:
  - anonymous → 401 on both routes;
  - a YouTube video with chapters → 200 and the chapters; one outbound `POST https://www.youtube.com/youtubei/v1/player?prettyPrint=false`;
  - a SoundCloud track with chapters → 200 and the chapters; one outbound `GET https://soundcloud.com/oembed?…`;
  - a second request → the same body, still one outbound request;
  - no chapters → `{"chapters":[]}`;
  - the provider answers 503 → 503 `application/problem+json`, and the next request asks again;
  - `/api/youtube/videos/short/chapters` and `/api/soundcloud/tracks/abc/chapters` → 404;
  - the 121st request in the window → 429 (const `CHAPTERS_BUDGET = 120`, with the "must match rate_limiter.yaml" docblock the social test uses).
  - `ChapterProblemsTest` mirrors `SocialInteractionProblemsTest`.
- [ ] **Step 2: Run them, expect FAIL**
- [ ] **Step 3: Implement**
- [ ] **Step 4: Run them, expect PASS**; `composer check`, `composer md`, the full `php bin/phpunit` and `docker compose exec php composer test`.
- [ ] **Step 5: Live probe** against the Docker stack with a dev bearer token: `…/api/youtube/videos/570hdDp8l4g/chapters` → 19 chapters; `…/api/soundcloud/tracks/<id>/chapters` for the Catching Flies mix → 32 (its id is in the track page's `__sc_hydration` `sound` entry, or in the `html` of its oEmbed). Scan today's dev log (`ls -t backend/var/log/dev-*.log | head -1`).
- [ ] **Step 6: Commit** — `feat(#1824): chapters endpoints for YouTube videos and SoundCloud tracks`

---

### Task 4: A chapter player for a visible SoundCloud widget

**Files:**
- Create: `frontend/src/app/reader/chapters/soundcloud-chapter-player.ts`, `.spec.ts`

**Interfaces:**
- Consumes: `widgetMessage`, `widgetMessageOf`, `widgetFieldOf`, `SOUNDCLOUD_WIDGET_ORIGIN` (`media/soundcloud-widget-protocol.ts`), `finiteNumberOf` (`media/youtube-player-protocol.ts`), `ChapterPlayer`, `PlaybackPosition`.
- Produces: `class SoundCloudChapterPlayer implements ChapterPlayer { constructor(frame: HTMLIFrameElement) }`.

Mirrors `YouTubeChapterPlayer`, speaking the widget's protocol as `SoundCloudPlayback` (#1815) does:
- **Subscribe** on construction and again on the frame's `load` (the frame may still be loading): post `addEventListener` for `playProgress`, `play` and `pause`, then `getDuration`. The measured widget obeys this after its `ready` went unheard, so no ready handshake is needed.
- **Messages** are accepted only from `SOUNDCLOUD_WIDGET_ORIGIN` and `event.source === frame.contentWindow`. `getDuration` → duration in seconds (ms / 1000); `playProgress` → `onPosition({ seconds: currentPosition / 1000, durationSeconds })`; `play` → playing, then a held seek is sent; `pause` → not playing.
- **`playFrom(seconds)`:** playing → `seekTo(ms)` at once; not playing → hold `seconds` and post `play`; the `play` event sends `seekTo` (the widget seeks reliably only once the sound plays — `SoundCloudPlayback.seekOnPlay`).
- **`release()`** removes both listeners and drops the held seek and the callback.

Messages go out as `frame.contentWindow?.postMessage(JSON.stringify(widgetMessage(method, value)), SOUNDCLOUD_WIDGET_ORIGIN)`, as `pauseEmbedFrame` sends them. That is now the third place sending a widget message: add `postToWidgetFrame(frame, message)` to `soundcloud-widget-protocol.ts` and use it in `pauseEmbedFrame` and here (`SoundCloudPlayback` posts through `FramePlayback.post`, which stays).

- [ ] **Step 1: Write the failing spec** (a jsdom `iframe` appended to the body; dispatch `MessageEvent`s with `origin: SOUNDCLOUD_WIDGET_ORIGIN` and `source: frame.contentWindow`; spy on `frame.contentWindow.postMessage`):
  - construction posts the three `addEventListener`s and `getDuration`; a `load` event posts them again;
  - `playProgress` after `getDuration` reports `{ seconds: 12.5, durationSeconds: 6443.23 }`; before it, `durationSeconds: null`;
  - a message from another origin or another window reports nothing;
  - `playFrom(600)` while paused posts `play` and no `seekTo`; the `play` event then posts `seekTo 600000`, once;
  - `playFrom(600)` after a `play` event posts `seekTo 600000` at once;
  - after `release`, messages report nothing and a `play` event posts no held seek.
  - `postToWidgetFrame` in `soundcloud-widget-protocol.spec.ts`; `embed-frame-commands.spec.ts` stays green.
- [ ] **Step 2: Run it, expect FAIL** — `docker compose exec -T frontend npx jest src/app/reader/chapters src/app/reader/media`
- [ ] **Step 3: Implement**
- [ ] **Step 4: Run it, expect PASS**
- [ ] **Step 5: Commit** — `feat(#1824): a chapter player for a SoundCloud widget`

---

### Task 5: One clickable chapter list per embed

**Files:**
- Modify: `frontend/src/app/reader/article/decorators/reader-chapters.ts`, `reader-chapters.spec.ts`
- Modify: `frontend/src/app/reader/article/reader-view/article-chapters.ts`, `article-chapters.spec.ts`

**Interfaces:**
- Consumes: `SoundCloudChapterPlayer` (Task 4), `YouTubeChapterPlayer`, `embedMediaOfFrame(frame): EmbedMedia | null`.
- Produces:
  - `addChapterList(place, list, player)` no longer removes another list from `host`; its docblock loses "it replaces any earlier list in `host`".
  - `chapterFrames(host): HTMLIFrameElement[]` — every YouTube API frame (`iframe[src*="enablejsapi=1"]`) and every SoundCloud widget (`iframe[src^="https://w.soundcloud.com/player/"]`) in document order; `apiPlayerFrame(host)` stays as is (the body's own YouTube list keeps using it).
  - `ArticleChapters.attach(host, list, track)` — unchanged: the body's own chapters, on the episode or on the first YouTube API frame.
  - `ArticleChapters.attachToFrame(frame: HTMLIFrameElement, list: ChapterList): void` — a list right after that frame's `.reader-cinema`/`.reader-embed` box, its rows starting that embed at their chapter (`YouTubeChapterPlayer` or `SoundCloudChapterPlayer` by `embedMediaOfFrame(frame).kind`), or the audio player when the player's current track is that embed (`this.audio.current()?.url === media.url`), so a list that arrives after Listen drives the player and not the paused frame.
  - `ArticleChapters.followTrack(track: AudioTrack): void` — re-points the list whose embed URL is `track.url`; else, as today, the body's own list.
  - `ArticleChapters.release()` — releases and removes every list.

`shown` becomes a list of records `{ place, list, player, element, mediaUrl: string | null }`: `mediaUrl` is `embedMediaOfFrame(frame)?.url ?? null` for a frame's list (the body's own YouTube list included) and `null` for an episode's list. `followTrack` picks the record with `mediaUrl === track.url`, else the body's own record (the one `attach` made), else does nothing. Re-pointing releases the record's player, removes its element (the removal no longer happens inside `addChapterList`) and calls `addChapterList` with the same place. The list's markup, its collapsed `<details>`, its tap-to-play rows, the current-chapter mark and #1814's progress fill are `addChapterList`'s, unchanged: each embed gets exactly the list a YouTube entry shows today.

- [ ] **Step 1: Write the failing specs** in `article-chapters.spec.ts`, with a body holding a YouTube frame (`enablejsapi=1`) and a SoundCloud widget, each in a `.reader-embed` box:
  - `chapterFrames` returns both, in order, and skips a YouTube frame without `enablejsapi=1`;
  - `attachToFrame` on each puts a list right after each box; both exist;
  - a tap on the YouTube list's row posts `seekTo` to the YouTube frame only; a tap on the SoundCloud list's row posts `play` to the widget only (and `seekTo` once the widget reports `play`);
  - `attach` with body chapters plus `attachToFrame` on the widget gives two lists;
  - `followTrack` with the widget's embed URL re-points only the widget's list to the audio player (a tap calls the audio player as the existing episode spec checks); the YouTube list still drives its frame;
  - `attachToFrame` while `audio.current()` is that embed's track drives the audio player;
  - `release` removes every list.
  - `reader-chapters.spec.ts`: the "replaces an earlier list" case becomes "leaves another list in the host".
- [ ] **Step 2: Run them, expect FAIL** — `docker compose exec -T frontend npx jest src/app/reader/article`
- [ ] **Step 3: Implement**
- [ ] **Step 4: Run them, expect PASS**
- [ ] **Step 5: Commit** — `refactor(#1824): one chapter list per embed`

---

### Task 6: The body asks for its embeds' chapters

**Files:**
- Modify: `frontend/src/app/reader/audio/embed-media.ts`, `.spec.ts`
- Modify: `frontend/src/app/reader/reader-api.ts`, `reader-api.spec.ts`, `frontend/src/app/reader/models.ts`
- Create: `frontend/src/app/reader/article/reader-view/article-embed-chapters.ts`, `.spec.ts`
- Modify: `frontend/src/app/reader/article/reader-view/article-body.service.ts`, `.spec.ts`

**Interfaces:**
- Consumes: Task 5's `chapterFrames` and `attachToFrame`; `youTubeVideoId(url)`, `embedMediaOfFrame(frame)`; `AudioPlayerService.describeChapters(url, chapters)`; Task 3's endpoints.
- Produces:
  - `soundCloudTrackId(playerUrl: string): string | null` in `embed-media.ts` — the digits of the `url` parameter's `https://api.soundcloud.com/tracks/<digits>`, else null.
  - `interface EmbedChaptersDto { chapters: ChapterDto[] }` in `models.ts`, beside `ChapterDto`.
  - `ReaderApi.embedChapters(media: EmbedMedia): Observable<EmbedChaptersDto>` — `GET ${base}/api/youtube/videos/${id}/chapters` or `${base}/api/soundcloud/tracks/${id}/chapters` by `media.kind`; `EMPTY` for media without an id (keeps callers free of a null check).
  - ```ts
    /** The chapters of a body's embedded videos and tracks, asked for once per embed (#1824). */
    export class ArticleEmbedChapters {
      private readonly byEmbedUrl = new Map<string, ChapterDto[]>();
      private subscriptions: Subscription[] = [];
      constructor(private readonly api: ReaderApi) {}
      /** Asks for every frame's embed once; `found` runs per frame whose embed has chapters. */
      load(frames: HTMLIFrameElement[], found: (frame: HTMLIFrameElement, media: EmbedMedia, chapters: ChapterDto[]) => void): void;
      /** The chapters known for an embed URL; [] before they arrive or when it has none. */
      chaptersOf(embedUrl: string): ChapterDto[];
      release(): void; // unsubscribes and forgets
    }
    ```
    A failed request (`catchError(() => EMPTY)`) shows nothing: no list is the honest answer when the provider or the limiter refused. Two frames of the same embed get one request and both lists.

**Wiring in `ArticleBody.render`:** the frames to ask for are `chapterFrames(host)` **except** the frame `chapteredEmbedOf` already gave the body's chapters to, and except a YouTube frame whose video is the entry's own (`youTubeVideoId(parts.entry()?.url ?? '')`): a YouTube entry's description already went through the same rule on the server. For each found frame: `this.chapters.attachToFrame(frame, { chapters, heading })` and `this.audio.describeChapters(media.url, chapters)` (an embed queued from elsewhere, or Listened before the answer came, gains its chapters). The Listen context's `chaptersOf` becomes:

```ts
chaptersOf: (media) =>
  media.url === chapteredEmbed()?.url ? chapterList : this.embedChapters.chaptersOf(media.url),
```

`ArticleBody` builds `embedChapters = new ArticleEmbedChapters(inject(ReaderApi))` beside `chapters` and `embedAudio`, and releases it in the same `onCleanup` and `DestroyRef` hooks. `load` runs inside the existing `zone.runOutsideAngular` block: the lists are plain DOM.

- [ ] **Step 1: Write the failing specs.**
  - `embed-media.spec.ts`: `soundCloudTrackId` for the normalised player URL, for one with extra parameters, and null for a playlist URL and a non-SoundCloud URL.
  - `reader-api.spec.ts`: `embedChapters` requests the YouTube and the SoundCloud URL (HttpTestingController); media without an id makes no request.
  - `article-embed-chapters.spec.ts` (stub `ReaderApi` returning `of(…)`/`throwError(…)`/a `Subject`): one request per distinct embed; `found` only for an embed with chapters, once per frame; `chaptersOf` by embed URL for both kinds; an error calls nothing and `chaptersOf` stays `[]`; after `release` a late answer reaches nobody.
  - `article-body.service.spec.ts`: a body with a YouTube frame and a SoundCloud widget and no entry chapters asks for both and shows two lists; a YouTube entry with its own chapters asks only for the other embeds; Listen on the widget after its answer hands a track whose `chapters` are the widget's; an answer for an already-queued embed calls `describeChapters` with its URL.
- [ ] **Step 2: Run them, expect FAIL**
- [ ] **Step 3: Implement**
- [ ] **Step 4: Run them, expect PASS**; `cd frontend && npm run check && npm run build`.
- [ ] **Step 5: Commit** — `feat(#1824): an article shows the chapters of its embedded videos and tracks`

---

### Task 7: Live check and PR

- [ ] **Step 1:** No migration. Make sure the Docker `php` and `frontend` containers serve this branch (`:4200` can serve a stale chunk; restart the worker if it matters).
- [ ] **Step 2:** On `:4200`, open the Trancentral "Psytrance mixes – Week 43 2026" post in Reader view: four of the five embeds get a chapter list right under them, `N-2_zkN25s4` none. A tap on a row of the third list plays that video from the chapter, marks the row and fills it as it plays; the other videos stay put.
- [ ] **Step 3:** A post embedding a SoundCloud mix with a tracklist (find one in the dev DB with `w.soundcloud.com` in a reader body, or stub the route to the Catching Flies track as `frontend/e2e/magazine-kicker-one-line.spec.ts` stubs): the list shows 32 rows; a tap while paused starts the widget at the chapter; a tap while playing jumps.
- [ ] **Step 4:** Listen on a YouTube embed and on the SoundCloud widget: the mini bar names the current chapter, the big player shows the strip and the list, the scrubber has marks (#1810). The article's list for that embed now drives the audio player.
- [ ] **Step 5:** Reload with the tracks queued: the big player keeps their chapters, and the backend log shows no second outbound call for an embed already asked for.
- [ ] **Step 6:** A YouTube channel entry with description chapters (#1808's case) still shows one list and makes no `/api/youtube/videos/…` request for its own video.
- [ ] **Step 7:** iPhone (Safari, simulator is fine for layout; a real device for the widget's first tap): a chapter tap on a paused SoundCloud widget plays; if iOS refuses the widget's `play` without a direct tap inside it, the list still seeks once the widget plays — note what happens in the PR.
- [ ] **Step 8:** `composer check`, `composer md`, both PHPUnit legs, `composer infection:diff`, `npm run check`, `npm run build`. Merge `develop`, open the PR with `Closes #1824`.
