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
