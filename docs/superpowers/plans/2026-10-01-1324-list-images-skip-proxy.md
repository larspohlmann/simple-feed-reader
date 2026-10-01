# List Images Skip the Proxy (#1324) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** An image on a list surface that fails to load is hidden at once; only the article view retries it through `/api/image-proxy`.

**Architecture:** `ProxiedImageDirective` (`img[appProxiedImage]`) turns a browser `error` into a proxy retry and emits `imageFailed` only after the retry fails. List surfaces drop the directive and bind the native `(error)` event to the hide signal they already own. `reader-view` keeps the directive and its body-image `recover()` call unchanged.

**Tech Stack:** Angular 20 standalone components, Jest (jsdom).

**Spec:** GitHub issue #1324 (user ruling: "use the proxy attempts only for images in the article view. never in the lists").

## Global Constraints

- Frontend tests run in the container: `docker compose exec -T frontend npm test -- <path>`; never two Jest runs at once (container OOM).
- `npm run check` (ESLint + Prettier 100-col + Stylelint + Jest) is the CI gate.
- Commit format `fix(#1324): <lower-case summary>`.
- No new comments unless a future reader would get the code wrong without them.

## Surfaces

| Surface | Template | Hide signal |
|---|---|---|
| Entry row (classic list) | `frontend/src/app/reader/list/entry-row/entry-row.component.html` | `imgError.set(true)` |
| Magazine thumb | `frontend/src/app/reader/list/magazine/blocks/entry-thumb/entry-thumb.component.html` | `imgError.set(true)` |
| Magazine split | `frontend/src/app/reader/list/magazine/blocks/entry-split/entry-split.component.html` | `imgError.set(true)` |
| Magazine hero | `frontend/src/app/reader/list/magazine/blocks/entry-hero/entry-hero.component.html` | `imgError.set(true)` |
| Magazine wide | `frontend/src/app/reader/list/magazine/blocks/entry-wide/entry-wide.component.html` | `imgError.set(true)` |
| Feed intro logo | `frontend/src/app/reader/shell/feed-intro/feed-intro.component.html` | `broken.set(true)` |
| Add-feed preview row | `frontend/src/app/reader/feeds/add-feed/preview-entry-row/preview-entry-row.component.html` | `failedImageUrl.set(item().imageUrl)` |

Untouched: `frontend/src/app/reader/article/reader-view/*`, `frontend/src/app/shared/proxied-image/*`.

---

### Task 1: List surfaces hide a failed image without the proxy

**Files:**
- Create: `frontend/src/testing/image-proxy-testing.ts`
- Modify: the seven templates above, and the matching `.component.ts` (drop `ProxiedImageDirective` from `imports` and its import line)
- Test: the seven matching `.component.spec.ts`

**Interfaces:**
- Produces: `neverRecoveringImageProxy(): { attempts: HTMLImageElement[]; recover(image: HTMLImageElement): Promise<ProxyOutcome> }` in `src/testing/image-proxy-testing.ts`. No `jest` in it: `tsconfig.app.json` compiles every non-spec file under `src`, so a `jest.fn` there breaks `ng serve`/`ng build` with TS2304 while Jest stays green.

- [ ] **Step 1: Add the test double**

`frontend/src/testing/image-proxy-testing.ts`:

```ts
import { ProxyOutcome } from '../app/shared/proxied-image/image-proxy.service';

/** A proxy whose retry never settles, so a surface that waits on it never hides its image. */
export function neverRecoveringImageProxy() {
  const attempts: HTMLImageElement[] = [];
  return {
    attempts,
    recover: (image: HTMLImageElement): Promise<ProxyOutcome> => {
      attempts.push(image);
      return new Promise<ProxyOutcome>(() => undefined);
    },
  };
}
```

- [ ] **Step 2: Write the failing test in each of the seven specs**

Each spec provides the double and asserts the image is gone synchronously after `error`, with no proxy attempt. Entry-row example (its `TestBed.configureTestingModule` sits in a `beforeEach`; add the provider there and keep `imageProxy` in a `let`):

```ts
let imageProxy: ReturnType<typeof neverRecoveringImageProxy>;
// in the beforeEach, before configureTestingModule:
imageProxy = neverRecoveringImageProxy();
// providers: [..., { provide: ImageProxyService, useValue: imageProxy }]

it('hides a thumbnail that fails to load, without a proxy retry', () => {
  const fixture = mount(entry());
  const element = fixture.nativeElement as HTMLElement;
  element.querySelector('img.thumb')!.dispatchEvent(new Event('error'));
  fixture.detectChanges();
  expect(element.querySelector('img.thumb')).toBeNull();
  expect(imageProxy.attempts).toEqual([]);
});
```

Magazine blocks configure TestBed inside `mount()`; add the provider there from a module-level `imageProxy` reset in `beforeEach`. Selector is `img.img`. Feed intro: replace the `outcome`-driven `ImageProxyService` stub with the double, make the two existing `error` tests synchronous (drop `await fixture.whenStable()`), add `expect(imageProxy.attempts).toEqual([])` to "hides a broken image…", and delete "keeps the feed image while the proxy recovers it" — that behaviour is what #1324 removes. Preview row: add the provider in its `beforeEach`; selector `img.thumb`.

- [ ] **Step 3: Run to see them fail**

Run: `docker compose exec -T frontend npm test -- src/app/reader/list src/app/reader/shell/feed-intro src/app/reader/feeds/add-feed/preview-entry-row`
Expected: the eight new/changed tests FAIL — the `<img>` is still present while the proxy retry is pending.

- [ ] **Step 4: Swap the directive for `(error)` on each surface**

In each template remove the `appProxiedImage` attribute and rename the output binding, e.g. `entry-thumb.component.html`:

```html
    <img
      class="img"
      [src]="image()!.url"
      [attr.width]="image()!.width"
      [attr.height]="image()!.height"
      alt=""
      loading="lazy"
      decoding="async"
      referrerpolicy="no-referrer"
      (error)="imgError.set(true)"
    />
```

In each `.component.ts` remove `ProxiedImageDirective` from `imports: [...]` and delete its `import` line.

- [ ] **Step 5: Run to see them pass, then the gate**

Run: `docker compose exec -T frontend npm test -- src/app/reader/list src/app/reader/shell/feed-intro src/app/reader/feeds/add-feed/preview-entry-row src/app/reader/article src/app/shared/proxied-image`
Expected: PASS (reader-view and directive specs unchanged and green).
Then: `docker compose exec -T frontend npm run check` and `npx tsc -p tsconfig.app.json --noEmit` (the Jest gate does not typecheck the app build) — PASS.

- [ ] **Step 6: Verify in the browser**

Open `http://localhost:4200/?subscription=1073` (Radio Hamburg, every image a 404). Expected: no broken-image boxes once the page settles, and `read_network_requests` with `urlPattern: image-proxy` shows no request. Open one article: its lead image may still request `/api/image-proxy` once.

- [ ] **Step 7: Commit**

```bash
git add frontend/src docs/superpowers/plans/2026-10-01-1324-list-images-skip-proxy.md
git commit -m "fix(#1324): list images hide on error instead of retrying through the proxy"
```

---

## Backend (added on the same PR, user ruling: "fix both issues in the same pr")

Measured on the dev stack before planning: 13,859 entries pending image verification, all at 0 attempts, the oldest from 2026-08-15; ~2,700 entries ingested a day, a third of them with an https image that declares both dimensions (trusted today). `ImageVerificationSweep` runs only inside `MaintenanceTick` (Strato's `/maintenance` cron); the worker's `WorkerSchedule` never runs it, so on Docker installs the queue never drains.

Backend constraints: `composer check` + `composer md` clean on every touched `src` file; `php bin/phpunit` (SQLite) and `docker compose exec php composer test` (MySQL); `composer infection:diff` gates touched files.

### Task 2: A 404 or 410 is never re-driven over another route

**Files:**
- Modify: `backend/src/Service/Fetch/CrossFamilyFailover.php`
- Test: `backend/tests/Service/Fetch/CrossFamilyFailoverTest.php`

#358 retries any status ≥ 400 on the next family, and #938 reuses the rule for the egress-proxy → direct fallback. A 404 and a 410 say the resource does not exist, not that the route is refused, so retrying them only doubles the cost of every dead URL (each `/api/image-proxy` call for Radio Hamburg's dead image took 2 × 2.2 s).

- [ ] **Step 1: Failing test**

```php
    public function testAMissingResourceStatusDoesNotWarrantAnotherFamily(): void
    {
        self::assertFalse($this->failover->isRetryableStatus(404));
        self::assertFalse($this->failover->isRetryableStatus(410));
    }
```

- [ ] **Step 2:** `php bin/phpunit tests/Service/Fetch/CrossFamilyFailoverTest.php` — FAIL (404 is retryable).

- [ ] **Step 3: Implement**

```php
    private const array MISSING_RESOURCE_STATUSES = [404, 410];

    /** A 4xx or 5xx can be tied to the source address, so another route may answer differently; a missing resource is missing on every route. */
    public function isRetryableStatus(int $statusCode): bool
    {
        return $statusCode >= 400 && !\in_array($statusCode, self::MISSING_RESOURCE_STATUSES, true);
    }
```

- [ ] **Step 4:** the test file and `tests/Service/Fetch` — PASS.

### Task 3: Every ingested image waits for verification

**Files:**
- Modify: `backend/src/Service/Ingest/EntryImageWriter.php` (drop `trustedAtIngest`, always `storePending`)
- Modify: `backend/src/Entity/EntryImage.php` (delete `storeVerified`, now unused)
- Modify: `backend/src/Service/Url/Support/HttpsImageUrl.php` (delete `isNativeHttps`, now unused)
- Test: `backend/tests/Service/Ingest/EntryIngestorTest.php`, `backend/tests/Repository/PendingImageVerificationTest.php`, `backend/tests/Service/Url/Support/HttpsImageUrlTest.php`

Declared dimensions say nothing about whether the URL serves an image (Radio Hamburg declares 250×250 on a 404). `storePending` keeps the declared URL and dimensions, so the card renders exactly as before until the sweep measures or drops it.

- [ ] **Step 1: Failing test** — rename `testANativeHttpsImageWithDeclaredDimensionsIsTrustedAtIngest` to `testANativeHttpsImageWithDeclaredDimensionsStaysPending`, assert `getCheckedAt()` is null, `getVerifyAttempts()` is 0, and URL + declared 400×300 are kept. Delete `testANativeHttpsDeclaredBeaconStaysPending` (it guarded an exception to a trust rule that no longer exists; the half-dimension and no-dimension tests stay as they pin the same outcome for other inputs).
- [ ] **Step 2:** `php bin/phpunit --filter EntryIngestorTest` — FAIL.
- [ ] **Step 3: Implement** — `EntryImageWriter::write` becomes:

```php
    public function write(Entry $entry, DeclaredImageModel $image): bool
    {
        $url = HttpsImageUrl::orNullUpgrading($image->url);
        if ($url === null) {
            return false;
        }
        $entry->getImage()->storePending($url, $image->width, $image->height);

        return true;
    }
```

  The class loses its `NaiveUtcClock` dependency; its docblock becomes `Stores a feed-declared image on an entry, pending the background verify.` Delete `EntryImage::storeVerified` and `HttpsImageUrl::isNativeHttps` with its three tests; `PendingImageVerificationTest` builds its verified entry with `storePending(...)` then `recordMeasurement(800, 600, …)`.
- [ ] **Step 4:** `php bin/phpunit tests/Service/Ingest tests/Repository tests/Service/Url tests/Entity` — PASS.

### Task 4: The worker drains the image-verify queue

**Files:**
- Create: `backend/src/Service/Worker/Message/VerifyPendingImages.php`, `backend/src/Service/Worker/Handler/VerifyPendingImagesHandler.php`
- Modify: `backend/src/Service/Worker/WorkerSchedule.php` (`RecurringMessage::every('1 minute', new VerifyPendingImages())`, appended last)
- Modify: `backend/src/Service/Image/Model/ImageVerificationReportModel.php` (`toLogContext(): array{measured:int,kept:int,dropped:int,retried:int}`)
- Test: `backend/tests/Service/Worker/VerifyPendingImagesHandlerTest.php`, `backend/tests/Service/Worker/WorkerScheduleWiringTest.php`

One sweep is ≤ 25 images within 15 s; every minute drains the dev backlog in ~9 h and keeps up with ~2,700 a day with room to spare.

- [ ] **Step 1: Failing tests** — wiring test expects 7 entries, `VerifyPendingImages::class` last at `'every 1 minute'`. Handler test (mirrors `ImageVerificationSweepTest`): one pending entry, `StubFaviconFetcher` returns `PngImageFactory::bytes(600, 400)`; invoking the handler logs one `info` record whose `report` is `['measured' => 1, 'kept' => 0, 'dropped' => 0, 'retried' => 0]`.
- [ ] **Step 2:** both FAIL (class not found / count 6).
- [ ] **Step 3: Implement** — property-less message; handler:

```php
#[AsMessageHandler]
final readonly class VerifyPendingImagesHandler
{
    public function __construct(
        private ImageVerificationSweep $imageVerificationSweep,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(VerifyPendingImages $message): void
    {
        $report = $this->imageVerificationSweep->verifyDue();
        $this->logger->info('Worker image verification sweep finished.', ['report' => $report->toLogContext()]);
    }
}
```

- [ ] **Step 4:** `php bin/phpunit tests/Service/Worker tests/Service/Image` — PASS.

### Task 5: Gates, both legs, live check, commit

- [ ] `composer check`, `composer md`, `php bin/phpunit`, `docker compose exec php composer test`, `composer infection:diff`.
- [ ] Restart the worker (it holds old code), then watch the dev log for `Worker image verification sweep finished.` and the pending count falling.
- [ ] Commit per task: `fix(#1324): a missing resource is not retried over another route`, `fix(#1324): verify every ingested image instead of trusting declared dimensions`, `fix(#1324): the worker runs the image verification sweep`.
