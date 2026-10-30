# Load list videos only near the viewport (#1903) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** A list video holds its media only while it is within about one screen of the visible list, so that on an iPhone off-screen videos stop downloading and holding decoded frames.

**Architecture:** One per-list service, `NearViewportMedia`, owns a single IntersectionObserver whose root is the list's own scroller (`.rows`, `overflow: auto`) with a `100% 0px` margin. It tells each watched element when it comes near and when it goes far. Native list videos and GIF-videos wrap their element in a `ReleasableVideo`, which sets `src` on approach and removes it on leave (keeping the iOS-unlocked element). A YouTube Short pauses through the iframe API when it leaves the viewport, and loses its frame when it goes far. Outside a list, for example in the article view, no `NearViewportMedia` is provided and every component behaves as it does today.

**Tech Stack:** Angular 20 (standalone, signals, OnPush), Jest with jsdom, hls.js (lazy import).

**Spec:** GitHub issue #1903 (the design was settled in the grilling session that produced it). The baseline numbers are in the issue body.

## Global Constraints

- List only: the article view (`article/**`) keeps today's behaviour. Every new collaborator is injected with `{ optional: true }`, and its absence means "attach at once".
- The near distance is one constant: `NEAR_VIEWPORT = '100% 0px'`, rooted on the list scroller. Never root on the implicit viewport: `.rows` clips, so a margin there is ineffective.
- Release keeps the `<video>` element. iOS unlocks sound per element (`UnlockedVideoElements`), so never replace or re-take an element to unload it.
- Releasing order: `ReleasableVideo.release()` first (it reads `currentTime`, pauses, removes `src` and calls `load()`), then `releaseHlsStream()`. hls.js's own detach then finds nothing left to do.
- Images, including animated `<img>` GIFs, are out of scope.
- Run frontend tests inside Docker: `docker compose exec -T frontend npm test -- <path>` from the repo root. Never start two Jest runs at once (the container OOMs).
- Follow the frontend conventions in CLAUDE.md: standalone and signals, comments only for non-obvious invariants, one line where possible.
- Commit subject format: `type(#1903): …`, with no attribution lines.

---

### Task 0: Verify the GIF-behind-the-article gap (manual, no code)

Decides whether GIF-videos also need releasing while an article is open on a narrow screen.

- [ ] **Step 1:** Ask Lars to open a GIF-heavy feed on the iPhone (Safari, Web Inspector attached), scroll to a GIF, open an article, and run this in the Console with the article **open**:

```js
[...document.querySelectorAll('video[data-animated]')].filter(v => !v.paused).length
```

- [ ] **Step 2:** If it returns `0`, record "not reproduced" in the PR description and skip it. If it returns more than `0`, stop and report back to the planner before Task 4. Releasing GIF-videos on `media-pause` would then be added to Task 4.

---

### Task 1: `NearViewportMedia`, the per-list near/far watcher

**Files:**
- Create: `frontend/src/app/reader/media/near-viewport-media.ts`
- Create: `frontend/src/app/reader/media/near-viewport-media.spec.ts`
- Modify: `frontend/src/testing/intersection-observer-testing.ts` (`MockIntersectionObserver` keeps its options)
- Modify: `frontend/src/app/reader/list/entry-list/entry-list.component.ts` (provide the service, give it the scroller)
- Modify: `frontend/src/app/reader/list/entry-list/entry-list.component.spec.ts` (one wiring test)

**Interfaces:**
- Produces:
  - `interface NearViewportClient { approach(): void; leave(): void; }`
  - `@Injectable() class NearViewportMedia { scrollWithin(root: Element): void; watch(element: Element, client: NearViewportClient): void; unwatch(element: Element): void; }`

- [ ] **Step 1: Let the mock keep its options.** In `intersection-observer-testing.ts`, change the `MockIntersectionObserver` constructor to:

```ts
  constructor(
    readonly callback: IntersectionObserverCallback,
    readonly options?: IntersectionObserverInit,
  ) {
    MockIntersectionObserver.instances.push(this);
  }
```

- [ ] **Step 2: Write the failing spec** `near-viewport-media.spec.ts`:

```ts
import { TestBed } from '@angular/core/testing';
import { NearViewportMedia, NearViewportClient } from './near-viewport-media';
import {
  MockIntersectionObserver,
  installMockIntersectionObserver,
  restoreIntersectionObserver,
} from '../../../testing/intersection-observer-testing';

const client = (): jest.Mocked<NearViewportClient> => ({ approach: jest.fn(), leave: jest.fn() });

function service(): NearViewportMedia {
  TestBed.configureTestingModule({ providers: [NearViewportMedia] });
  return TestBed.inject(NearViewportMedia);
}

beforeEach(installMockIntersectionObserver);
afterEach(restoreIntersectionObserver);

describe('NearViewportMedia', () => {
  it('measures against the list scroller, a screen beyond its edges', () => {
    const scroller = document.createElement('div');
    service().scrollWithin(scroller);

    expect(MockIntersectionObserver.instances[0].options).toEqual({
      root: scroller,
      rootMargin: '100% 0px',
    });
  });

  it('tells a watched element when it comes near and when it goes far', () => {
    const media = service();
    const video = document.createElement('video');
    const watcher = client();
    media.scrollWithin(document.createElement('div'));
    media.watch(video, watcher);

    MockIntersectionObserver.report(video, 0.1);
    expect(watcher.approach).toHaveBeenCalledTimes(1);

    MockIntersectionObserver.report(video, 0);
    expect(watcher.leave).toHaveBeenCalledTimes(1);
  });

  it('starts watching what was registered before the scroller was known', () => {
    const media = service();
    const video = document.createElement('video');
    media.watch(video, client());

    media.scrollWithin(document.createElement('div'));

    expect(MockIntersectionObserver.observes(video)).toBe(true);
  });

  it('moves every watched element to a new scroller', () => {
    const media = service();
    const video = document.createElement('video');
    media.scrollWithin(document.createElement('div'));
    media.watch(video, client());

    media.scrollWithin(document.createElement('div'));

    expect(MockIntersectionObserver.instances[0].targets.size).toBe(0);
    expect(MockIntersectionObserver.instances[1].targets.has(video)).toBe(true);
  });

  it('forgets an element it no longer watches', () => {
    const media = service();
    const video = document.createElement('video');
    const watcher = client();
    media.scrollWithin(document.createElement('div'));
    media.watch(video, watcher);

    media.unwatch(video);
    MockIntersectionObserver.report(video, 1);

    expect(watcher.approach).not.toHaveBeenCalled();
  });

  it('brings everything near at once where the browser cannot observe', () => {
    restoreIntersectionObserver();
    const original = globalThis.IntersectionObserver;
    // @ts-expect-error -- simulating a browser without IntersectionObserver
    delete globalThis.IntersectionObserver;
    const watcher = client();

    service().watch(document.createElement('video'), watcher);

    expect(watcher.approach).toHaveBeenCalledTimes(1);
    globalThis.IntersectionObserver = original;
  });
});
```

- [ ] **Step 3: Run it to verify it fails.**
Run: `docker compose exec -T frontend npm test -- src/app/reader/media/near-viewport-media.spec.ts`
Expected: FAIL, "Cannot find module './near-viewport-media'".

- [ ] **Step 4: Implement** `near-viewport-media.ts`:

```ts
import { DestroyRef, Injectable, inject } from '@angular/core';

const NEAR_VIEWPORT = '100% 0px';

export interface NearViewportClient {
  approach(): void;
  leave(): void;
}

/** Tells list media when they come within a screen of the visible list and when they go beyond it (#1903). */
@Injectable()
export class NearViewportMedia {
  private readonly clients = new Map<Element, NearViewportClient>();
  private observer: IntersectionObserver | null = null;

  constructor() {
    inject(DestroyRef).onDestroy(() => this.observer?.disconnect());
  }

  /** The list scrolls inside its own box, so only a margin rooted on it reaches beyond the screen. */
  scrollWithin(root: Element): void {
    if (typeof IntersectionObserver === 'undefined') return;
    this.observer?.disconnect();
    this.observer = new IntersectionObserver((changes) => this.sight(changes), {
      root,
      rootMargin: NEAR_VIEWPORT,
    });
    for (const element of this.clients.keys()) this.observer.observe(element);
  }

  watch(element: Element, client: NearViewportClient): void {
    this.clients.set(element, client);
    if (typeof IntersectionObserver === 'undefined') client.approach();
    else this.observer?.observe(element);
  }

  unwatch(element: Element): void {
    this.clients.delete(element);
    this.observer?.unobserve(element);
  }

  private sight(changes: IntersectionObserverEntry[]): void {
    for (const change of changes) {
      const client = this.clients.get(change.target);
      if (change.isIntersecting) client?.approach();
      else client?.leave();
    }
  }
}
```

- [ ] **Step 5: Run the spec.** Same command. Expected: PASS (6 tests).

- [ ] **Step 6: Wire it into the list.** In `entry-list.component.ts`:
  - Add `NearViewportMedia` to `providers: [ListAutoplayCoordinator, UnlockedVideoElements, NearViewportMedia]` and import it from `'../../media/near-viewport-media'`.
  - Next to the other `private readonly` fields, add:

```ts
  private readonly nearViewport = inject(NearViewportMedia);
  private readonly _nearViewportRoot = effect(() => {
    const rows = this.rows()?.nativeElement;
    if (rows) this.nearViewport.scrollWithin(rows);
  });
```

  `rows` is the existing `viewChild('rows')`. It is re-created when the layout switches between list and magazine, and the effect re-roots the observer each time.

- [ ] **Step 7: Add a wiring test** to `entry-list.component.spec.ts`, inside `describe('EntryListComponent')`. The spec's synchronous `mount(over)` (line ~62) renders `.rows.magazine` for `{ layout: 'magazine' }` even without entries (see the "draws the magazine boxed by default" test). Import `MockIntersectionObserver`, `installMockIntersectionObserver` and `restoreIntersectionObserver` from `'../../../../testing/intersection-observer-testing'`:

```ts
  describe('near-viewport media', () => {
    beforeEach(installMockIntersectionObserver);
    afterEach(restoreIntersectionObserver);

    it('roots the watch on the list’s own scroller', () => {
      const element = mount({ layout: 'magazine' }).nativeElement as HTMLElement;
      const rows = element.querySelector('.rows.magazine');

      expect(rows).not.toBeNull();
      expect(
        MockIntersectionObserver.instances.some(
          (observer) => observer.options?.root === rows && observer.options?.rootMargin === '100% 0px',
        ),
      ).toBe(true);
    });
  });
```

  The list's other observers (paging sentinel, autoplay) also create mock instances; `some` ignores them.

- [ ] **Step 8: Run both specs.**
Run: `docker compose exec -T frontend npm test -- src/app/reader/media/near-viewport-media.spec.ts src/app/reader/list/entry-list`
Expected: PASS.

- [ ] **Step 9: Commit.**

```bash
git add frontend/src/app/reader/media/near-viewport-media.ts frontend/src/app/reader/media/near-viewport-media.spec.ts frontend/src/testing/intersection-observer-testing.ts frontend/src/app/reader/list/entry-list/entry-list.component.ts frontend/src/app/reader/list/entry-list/entry-list.component.spec.ts
git commit -m "feat(#1903): the list tells its media when they come near and go far"
```

---

### Task 2: `ReleasableVideo` and a per-video HLS release

**Files:**
- Create: `frontend/src/app/reader/media/releasable-video.ts`
- Create: `frontend/src/app/reader/media/releasable-video.spec.ts`
- Modify: `frontend/src/app/reader/media/hls-streams.ts`
- Modify: `frontend/src/app/reader/media/hls-streams.spec.ts`

**Interfaces:**
- Produces:
  - `class ReleasableVideo { constructor(element: HTMLVideoElement, source: string); readonly element: HTMLVideoElement; attach(): void; release(): void; }`
  - `export function releaseHlsStream(host: HTMLElement, video: HTMLVideoElement): void` in `hls-streams.ts`

- [ ] **Step 1: Write the failing spec** `releasable-video.spec.ts`:

```ts
import { ReleasableVideo } from './releasable-video';

const SOURCE = 'https://video.test/clip.mp4';

let load: jest.SpyInstance;
let pause: jest.SpyInstance;

beforeEach(() => {
  load = jest.spyOn(HTMLMediaElement.prototype, 'load').mockImplementation(() => undefined);
  pause = jest.spyOn(HTMLMediaElement.prototype, 'pause').mockImplementation(() => undefined);
});

afterEach(() => jest.restoreAllMocks());

function at(video: HTMLVideoElement, seconds: number): void {
  Object.defineProperty(video, 'currentTime', { value: seconds, writable: true, configurable: true });
}

describe('ReleasableVideo', () => {
  it('carries no source until it is attached', () => {
    const video = new ReleasableVideo(document.createElement('video'), SOURCE);

    expect(video.element.hasAttribute('src')).toBe(false);
    video.attach();
    expect(video.element.getAttribute('src')).toBe(SOURCE);
  });

  it('frees its media on release but keeps the element', () => {
    const element = document.createElement('video');
    const video = new ReleasableVideo(element, SOURCE);
    video.attach();

    video.release();

    expect(video.element).toBe(element);
    expect(element.hasAttribute('src')).toBe(false);
    expect(pause).toHaveBeenCalled();
    expect(load).toHaveBeenCalledTimes(1);
  });

  it('does nothing when released without a source', () => {
    new ReleasableVideo(document.createElement('video'), SOURCE).release();

    expect(load).not.toHaveBeenCalled();
  });

  it('resumes where it was released, once the media is back', () => {
    const element = document.createElement('video');
    const video = new ReleasableVideo(element, SOURCE);
    video.attach();
    at(element, 42);
    video.release();
    at(element, 0);

    video.attach();
    element.dispatchEvent(new Event('loadedmetadata'));

    expect(element.currentTime).toBe(42);
  });

  it('starts over once a video played to its end', () => {
    const element = document.createElement('video');
    const video = new ReleasableVideo(element, SOURCE);
    video.attach();
    at(element, 30);
    Object.defineProperty(element, 'ended', { value: true, configurable: true });
    video.release();
    at(element, 0);

    video.attach();
    element.dispatchEvent(new Event('loadedmetadata'));

    expect(element.currentTime).toBe(0);
  });

  it('drops a pending resume when released again before the media arrived', () => {
    const element = document.createElement('video');
    const video = new ReleasableVideo(element, SOURCE);
    video.attach();
    at(element, 42);
    video.release();
    video.attach();
    video.release();
    at(element, 0);

    element.dispatchEvent(new Event('loadedmetadata'));

    expect(element.currentTime).toBe(0);
  });
});
```

- [ ] **Step 2: Run it to verify it fails.**
Run: `docker compose exec -T frontend npm test -- src/app/reader/media/releasable-video.spec.ts`
Expected: FAIL, "Cannot find module './releasable-video'".

- [ ] **Step 3: Implement** `releasable-video.ts`:

```ts
/** A list video whose media comes and goes with its distance from the screen (#1903). The element
 *  stays: iOS unlocks sound per element. */
export class ReleasableVideo {
  private resumeAt = 0;
  private pendingResume: AbortController | null = null;

  constructor(
    readonly element: HTMLVideoElement,
    private readonly source: string,
  ) {}

  attach(): void {
    if (this.element.hasAttribute('src')) return;
    this.element.setAttribute('src', this.source);
    if (this.resumeAt > 0) this.resumeOnceLoaded(this.resumeAt);
  }

  release(): void {
    if (!this.element.hasAttribute('src')) return;
    this.pendingResume?.abort();
    this.pendingResume = null;
    this.resumeAt = this.element.ended ? 0 : this.element.currentTime;
    this.element.pause();
    this.element.removeAttribute('src');
    this.element.load();
  }

  private resumeOnceLoaded(seconds: number): void {
    this.pendingResume = new AbortController();
    this.element.addEventListener('loadedmetadata', () => (this.element.currentTime = seconds), {
      once: true,
      signal: this.pendingResume.signal,
    });
  }
}
```

  Note on the last test: the second `release()` reads `currentTime` 42 again (the `at` override is still in place), so `resumeAt` stays 42. The assertion holds because the pending listener was aborted and no `attach()` followed. That is the behaviour under test.

- [ ] **Step 4: Run the spec.** Same command. Expected: PASS (6 tests).

- [ ] **Step 5: Write the failing HLS tests.** Add to `hls-streams.spec.ts`, importing `releaseHlsStream`:

```ts
describe('releaseHlsStream', () => {
  it('destroys the one video’s stream and lets it arm afresh', async () => {
    const host = await playedStream();
    const video = videoIn(host);

    releaseHlsStream(host, video);
    expect(fakeHls.destroy).toHaveBeenCalledTimes(1);

    fakeHls.attachMedia.mockClear();
    armHlsStreams(host);
    await firstPlay(video);
    expect(fakeHls.attachMedia).toHaveBeenCalledTimes(1);
  });

  it('disarms a video that never played, so it never gets two streams', async () => {
    const host = attachedArticleHost(PLAYLIST_VIDEO);
    const video = videoIn(host);
    armHlsStreams(host);

    releaseHlsStream(host, video);
    armHlsStreams(host);
    await firstPlay(video);

    expect(fakeHls.attachMedia).toHaveBeenCalledTimes(1);
  });

  it('attaches nothing to a video released while hls.js was still loading', async () => {
    const host = attachedArticleHost(PLAYLIST_VIDEO);
    const video = videoIn(host);
    armHlsStreams(host);
    startPlaying(video);

    video.removeAttribute('src');
    releaseHlsStream(host, video);
    await flushHls();

    expect(fakeHls.attachMedia).not.toHaveBeenCalled();
  });
});
```

- [ ] **Step 6: Run to verify it fails.**
Run: `docker compose exec -T frontend npm test -- src/app/reader/media/hls-streams.spec.ts`
Expected: FAIL, `releaseHlsStream` is not exported.

- [ ] **Step 7: Implement in `hls-streams.ts`.**
  - Replace `const armed = new WeakSet<HTMLVideoElement>();` with:

```ts
const armed = new WeakMap<HTMLVideoElement, AbortController>();
```

  - In `armHlsStreams`, delete the `armed.add(video);` line. `armFirstPlay` now records the arming. The loop's `armed.has(video)` check stays.
  - Add after `releaseHlsStreams`:

```ts
/** Releases one video's stream and its arming, for a list video losing its source (#1903). */
export function releaseHlsStream(host: HTMLElement, video: HTMLVideoElement): void {
  armed.get(video)?.abort();
  armed.delete(video);
  releaseWhere(host, (streamed) => streamed === video);
}
```

  - Replace `armFirstPlay`:

```ts
function armFirstPlay(host: HTMLElement, video: HTMLVideoElement, source: string): void {
  const arming = new AbortController();
  armed.set(video, arming);
  const rearm = (): void => armFirstPlay(host, video, source);
  video.addEventListener('play', () => void play(host, video, source).catch(rearm), {
    once: true,
    signal: arming.signal,
  });
}
```

  - At the top of `play()`, after the `isSupported` check, add the released-video guard before the existing paused check:

```ts
  if (!isPlaylist(video)) return;
```

  `streamsThroughHls` keeps reading `armed.has(video)`; a `WeakMap` answers the same way.

- [ ] **Step 8: Run the HLS spec.** Same command. Expected: PASS, the existing tests included.

- [ ] **Step 9: Commit.**

```bash
git add frontend/src/app/reader/media/releasable-video.ts frontend/src/app/reader/media/releasable-video.spec.ts frontend/src/app/reader/media/hls-streams.ts frontend/src/app/reader/media/hls-streams.spec.ts
git commit -m "feat(#1903): a video can give up its media and take it back where it left off"
```

---

### Task 3: List videos (`app-post-media`) load near and release far

**Files:**
- Modify: `frontend/src/app/reader/media/post-media/post-media.component.ts`
- Modify: `frontend/src/app/reader/media/post-media/post-media.component.spec.ts`

**Interfaces:**
- Consumes: `NearViewportMedia`, `NearViewportClient` (Task 1); `ReleasableVideo`, `releaseHlsStream` (Task 2).

- [ ] **Step 1: Write the failing tests.** Add to `post-media.component.spec.ts`. They use a fake service so they don't depend on IntersectionObserver:

```ts
import { NearViewportClient, NearViewportMedia } from '../near-viewport-media';

const nearViewport = { watch: jest.fn(), unwatch: jest.fn() };
const clientOf = (video: Element): NearViewportClient =>
  nearViewport.watch.mock.calls.find(([element]) => element === video)![1];

async function mountInList(media: EntryMediumDto[]) {
  TestBed.configureTestingModule({
    imports: [HostComponent, provideTranslocoTesting()],
    providers: [{ provide: NearViewportMedia, useValue: nearViewport }],
  });
  const fixture = TestBed.createComponent(HostComponent);
  fixture.componentInstance.media = media;
  fixture.detectChanges();
  await fixture.whenStable();
  return fixture;
}

describe('PostMediaComponent in a list', () => {
  beforeEach(() => Object.values(nearViewport).forEach((spy) => spy.mockReset()));

  const mp4: EntryMediumDto = { ...clip, url: 'https://video.test/clip.mp4' };
  const videoOf = (fixture: { nativeElement: HTMLElement }) =>
    fixture.nativeElement.querySelector('.video-slot video') as HTMLVideoElement;

  it('gives a video no source until it comes near the screen', async () => {
    const fixture = await mountInList([mp4]);
    const video = videoOf(fixture);

    expect(video.hasAttribute('src')).toBe(false);
    expect(video.getAttribute('poster')).toBe('https://video.test/thumb.jpg');

    clientOf(video).approach();
    expect(video.getAttribute('src')).toBe('https://video.test/clip.mp4');
  });

  it('takes the source back when the video goes far, keeping the element', async () => {
    const fixture = await mountInList([mp4]);
    const video = videoOf(fixture);
    clientOf(video).approach();

    clientOf(video).leave();

    expect(videoOf(fixture)).toBe(video);
    expect(video.hasAttribute('src')).toBe(false);
  });

  it('destroys a far video’s hls.js stream', async () => {
    const fixture = await mountInList([clip]);
    const video = videoOf(fixture);
    clientOf(video).approach();
    await firstPlay(video);

    clientOf(video).leave();

    expect(fakeHls.destroy).toHaveBeenCalledTimes(1);
  });

  it('arms a playlist that came near for its first play', async () => {
    const fixture = await mountInList([clip]);
    const video = videoOf(fixture);

    clientOf(video).approach();
    await firstPlay(video);

    expect(fakeHls.loadSource).toHaveBeenCalledWith('https://video.test/playlist.m3u8');
  });

  it('releases its videos and stops watching them when it goes away', async () => {
    const fixture = await mountInList([mp4]);
    const video = videoOf(fixture);
    clientOf(video).approach();

    fixture.componentInstance.media = [];
    fixture.detectChanges();
    fixture.destroy();

    expect(nearViewport.unwatch).toHaveBeenCalledWith(video);
    expect(video.hasAttribute('src')).toBe(false);
  });
});
```

  Also add one test to the existing `describe('PostMediaComponent')` block (mounted without the service) to pin the article-view behaviour:

```ts
  it('gives a video its source at once outside a list', async () => {
    const { fixture } = await mount([clip]);

    expect(
      fixture.nativeElement.querySelector('.video-slot video').getAttribute('src'),
    ).toBe('https://video.test/playlist.m3u8');
  });
```

  If `HostComponent`'s `@if`/`@for` keeps the component alive after `media = []`, the `fixture.destroy()` call alone triggers teardown, and the assertion still holds.

- [ ] **Step 2: Run to verify the list tests fail.**
Run: `docker compose exec -T frontend npm test -- src/app/reader/media/post-media`
Expected: the "in a list" tests FAIL (the video has a `src` from the start); the outside-a-list test passes.

- [ ] **Step 3: Implement** in `post-media.component.ts`:
  - Imports: `import { armHlsStreams, releaseHlsStream, releaseHlsStreams } from '../hls-streams';`, `import { NearViewportMedia } from '../near-viewport-media';` and `import { ReleasableVideo } from '../releasable-video';`.
  - Fields:

```ts
  private readonly nearViewport = inject(NearViewportMedia, { optional: true });
  private readonly releasable = new Map<HTMLVideoElement, ReleasableVideo>();
```

  - Replace the `onDestroy` body's `for (const video of this.mountedVideos()) video.pause();` line with:

```ts
      for (const video of this.releasable.keys()) {
        this.nearViewport?.unwatch(video);
        this.release(video);
      }
```

  Keep `releaseHlsStreams(this.host)` after it, as a catch-all.
  - Replace `newVideo`:

```ts
  private newVideo(clip: EntryMediumDto): HTMLVideoElement {
    const element = this.unlockedVideos?.take() ?? document.createElement('video');
    element.controls = true;
    element.setAttribute('playsinline', '');
    element.setAttribute('preload', 'none');
    if (clip.previewImageUrl) element.setAttribute('poster', clip.previewImageUrl);
    const video = new ReleasableVideo(element, clip.url);
    this.releasable.set(element, video);
    this.attachWhenNear(video);
    return element;
  }

  private attachWhenNear(video: ReleasableVideo): void {
    if (this.nearViewport === null) {
      video.attach();
      return;
    }
    this.nearViewport.watch(video.element, {
      approach: () => {
        video.attach();
        armHlsStreams(this.host);
      },
      leave: () => this.release(video.element),
    });
  }

  private release(element: HTMLVideoElement): void {
    this.releasable.get(element)?.release();
    releaseHlsStream(this.host, element);
  }
```

  `newVideo` runs before the element is appended. Outside a list the immediate `attach()` keeps today's order (`src` set before `armHlsStreams` in the same `afterRenderEffect`).

- [ ] **Step 4: Run the post-media spec.** Same command. Expected: PASS, all old and new tests.

- [ ] **Step 5: Commit.**

```bash
git add frontend/src/app/reader/media/post-media
git commit -m "feat(#1903): list videos load near the screen and let go of their media far from it"
```

---

### Task 4: GIF-videos (`app-gif`) load near and release far

**Files:**
- Modify: `frontend/src/app/reader/media/gif/gif.component.ts`
- Modify: `frontend/src/app/reader/media/gif/gif.component.html`
- Modify: `frontend/src/app/reader/media/gif/gif.component.spec.ts`

**Interfaces:**
- Consumes: `NearViewportMedia` (Task 1), `ReleasableVideo` (Task 2).

- [ ] **Step 1: Write the failing tests.** Add to `gif.component.spec.ts`. It already defines `gifv`, `HostComponent`, `play`, `pause` and the `viewport` stub. Read its existing mount code first and match it:

```ts
import { NearViewportClient, NearViewportMedia } from '../near-viewport-media';

describe('GifComponent in a list', () => {
  const nearViewport = { watch: jest.fn(), unwatch: jest.fn() };
  let load: jest.SpyInstance;

  beforeEach(() => {
    Object.values(nearViewport).forEach((spy) => spy.mockReset());
    load = jest.spyOn(HTMLMediaElement.prototype, 'load').mockImplementation(() => undefined);
  });

  async function mountInList() {
    TestBed.configureTestingModule({
      imports: [HostComponent, provideTranslocoTesting()],
      providers: [{ provide: NearViewportMedia, useValue: nearViewport }],
    });
    const fixture = TestBed.createComponent(HostComponent);
    fixture.componentInstance.medium = gifv;
    fixture.detectChanges();
    await fixture.whenStable();
    const host = fixture.nativeElement.querySelector('app-gif') as HTMLElement;
    const client = nearViewport.watch.mock.calls[0][1] as NearViewportClient;
    return { fixture, host, client, clip: host.querySelector('video')! };
  }

  it('watches its own box', async () => {
    const { host } = await mountInList();

    expect(nearViewport.watch).toHaveBeenCalledWith(host, expect.anything());
  });

  it('gives its clip no source until it comes near', async () => {
    const { clip, client } = await mountInList();
    expect(clip.hasAttribute('src')).toBe(false);

    client.approach();

    expect(clip.getAttribute('src')).toBe('https://files.test/wave.mp4');
  });

  it('lets go of its clip’s media when it goes far', async () => {
    const { clip, client } = await mountInList();
    client.approach();

    client.leave();

    expect(clip.hasAttribute('src')).toBe(false);
    expect(load).toHaveBeenCalled();
  });

  it('releases and stops watching when it goes away', async () => {
    const { fixture, host, clip, client } = await mountInList();
    client.approach();

    fixture.destroy();

    expect(nearViewport.unwatch).toHaveBeenCalledWith(host);
    expect(clip.hasAttribute('src')).toBe(false);
  });
});
```

  Add to the existing `describe('GifComponent')`, which uses the spec's `mount(medium)` helper (it returns `{ gif }`, the `app-gif` element):

```ts
  it('gives its clip a source at once outside a list', async () => {
    const { gif } = await mount(gifv);

    expect(gif.querySelector('video')!.getAttribute('src')).toBe('https://files.test/wave.mp4');
  });
```

  Teardown now calls `load()`, which jsdom does not implement. Add `jest.spyOn(HTMLMediaElement.prototype, 'load').mockImplementation(() => undefined);` to the spec's top-level `beforeEach`, next to the `play`/`pause` spies; `jest.restoreAllMocks()` there already undoes it. The list block's own `load` spy then becomes redundant: keep `load` as a variable assigned from the same spy only if the test reads it.

- [ ] **Step 2: Run to verify they fail.**
Run: `docker compose exec -T frontend npm test -- src/app/reader/media/gif`
Expected: the "in a list" tests FAIL.

- [ ] **Step 3: Template.** In `gif.component.html`, delete the `[src]="gif.url"` line from the `<video #clip …>` element. Nothing else changes.

- [ ] **Step 4: Component.** In `gif.component.ts`:
  - Imports: `untracked` from `@angular/core`, `NearViewportMedia` from `'../near-viewport-media'`, `ReleasableVideo` from `'../releasable-video'`.
  - Fields:

```ts
  private readonly nearViewportMedia = inject(NearViewportMedia, { optional: true });
  private readonly host = inject<ElementRef<HTMLElement>>(ElementRef).nativeElement;
  private releasable: ReleasableVideo | null = null;
```

  - Change the constructor's first line to `this.watchViewport(this.host);`. After the existing play/pause `effect`, add:

```ts
    effect(() => {
      const clip = this.clip()?.nativeElement;
      if (!clip || this.releasable?.element === clip) return;
      this.releasable = new ReleasableVideo(clip, untracked(this.medium).url);
      untracked(() => this.attachWhenNear());
    });
    inject(DestroyRef).onDestroy(() => {
      this.nearViewportMedia?.unwatch(this.host);
      this.releasable?.release();
    });
```

  - Add the method:

```ts
  private attachWhenNear(): void {
    const video = this.releasable;
    if (video === null) return;
    if (this.nearViewportMedia === null) {
      video.attach();
      return;
    }
    this.nearViewportMedia.watch(this.host, {
      approach: () => {
        video.attach();
        if (this.playing()) video.element.play().catch(() => undefined);
      },
      leave: () => video.release(),
    });
  }
```

  The `play()` on approach covers a GIF that is already "near" by its own 200px observer when the list observer reports. The two observers fire in no guaranteed order.

- [ ] **Step 5: Run the GIF spec.** Same command. Expected: PASS, all old and new tests.

- [ ] **Step 6: Commit.**

```bash
git add frontend/src/app/reader/media/gif
git commit -m "feat(#1903): GIF clips in the list load near the screen and let go far from it"
```

---

### Task 5: Shorts pause off-screen and lose their frame far away

Today a Short's frame is removed the moment it leaves the viewport, or when the autoplay coordinator stops it. Decided: Shorts follow the shared distance. Leaving the viewport, or a coordinator `stop()`, **pauses** the frame through the YouTube iframe API, and going far removes it. A `media-pause` (article opened) still removes it. Position is not kept beyond the frame's life (Shorts restart).

**Files:**
- Modify: `frontend/src/app/reader/list/magazine/blocks/entry-media/short-embed.ts`
- Modify: `frontend/src/app/reader/list/magazine/blocks/entry-media/short-embed.spec.ts`
- Modify: `frontend/src/app/reader/list/magazine/blocks/entry-media/entry-media.component.ts`
- Modify: `frontend/src/app/reader/list/magazine/blocks/entry-media/entry-media.component.spec.ts`

**Interfaces:**
- Consumes: `NearViewportMedia` (Task 1); the existing `pauseEmbedFrame(frame, 'youtube')` from `media/embed-frame-commands.ts` and `playerCommand`/`postToPlayerFrame` from `media/youtube-player-protocol.ts`.
- Produces: `autoplayingShortPlayer(shortPlayerUrl: string | null, origin: string): string | null`

- [ ] **Step 1: Failing URL test.** In `short-embed.spec.ts`, pass `'https://reader.test'` as the second argument to every `autoplayingShortPlayer` call. Change the first expectation to:

```ts
    expect(
      autoplayingShortPlayer(
        'https://www.youtube-nocookie.com/embed/87Ov3cS-xMs#shorts',
        'https://reader.test',
      ),
    ).toBe(
      'https://www.youtube-nocookie.com/embed/87Ov3cS-xMs?autoplay=1&enablejsapi=1&origin=https%3A%2F%2Freader.test#shorts',
    );
```

  Rename that test to "starts the allow-listed player of a Short on load, scriptable from the reader". Update the `mutedShortPlayer` input and expectation the same way (its input now carries `&enablejsapi=1&origin=…`, and `&mute=1&playsinline=1` is appended after it).

- [ ] **Step 2: Run to verify it fails.**
Run: `docker compose exec -T frontend npm test -- src/app/reader/list/magazine/blocks/entry-media/short-embed.spec.ts`
Expected: FAIL on the URL.

- [ ] **Step 3: Implement** in `short-embed.ts`:

```ts
/** A Short's player, starting on load and scriptable so the list can pause it; null when there is
 *  none or it is not allow-listed. */
export function autoplayingShortPlayer(shortPlayerUrl: string | null, origin: string): string | null {
  if (shortPlayerUrl === null || !isAllowedEmbed(shortPlayerUrl)) return null;
  const player = new URL(shortPlayerUrl);
  player.searchParams.set('autoplay', '1');
  player.searchParams.set('enablejsapi', '1');
  player.searchParams.set('origin', origin);
  return player.toString();
}
```

  Run Step 2's command. Expected: PASS.

- [ ] **Step 4: Failing component tests.** In `entry-media.component.spec.ts`:
  - Every expected Short iframe `src` gains `&enablejsapi=1&origin=http%3A%2F%2Flocalhost` after `autoplay=1`. That is jsdom's origin; check it with `location.origin` if unsure.
  - Replace the test "puts a Short’s player back to its cover when the card scrolls out of view" with:

```ts
  it('pauses a Short’s player, keeping it, when the card scrolls out of view', async () => {
    const { observed, report } = stubIntersectionObserver();
    const fixture = await mountFixture(short());
    const element = fixture.nativeElement as HTMLElement;
    element.querySelector<HTMLButtonElement>('.play')!.click();
    fixture.detectChanges();
    await fixture.whenStable();
    const box = element.querySelector('.short-player')!;
    expect(observed).toContain(box);
    const frame = element.querySelector('iframe')!;
    const post = jest.fn();
    Object.defineProperty(frame, 'contentWindow', { value: { postMessage: post } });

    report(box, false);
    fixture.detectChanges();

    expect(element.querySelector('iframe')).toBe(frame);
    expect(JSON.parse(post.mock.calls[0][0])).toMatchObject({ event: 'command', func: 'pauseVideo' });
  });
```

  - In "plays the Short muted when the list starts it, and puts it away when stopped", rename it to "…and pauses it when stopped". After `registered().stop()`, expect the iframe to still be there and a `pauseVideo` command posted, using the same `contentWindow` stub defined right after the start.
  - Add a test that a stopped Short started again resumes the same frame:

```ts
  it('resumes the same paused frame when the list starts the Short again', async () => {
    const fixture = await mountInList(short());
    registered().start(false);
    fixture.detectChanges();
    await fixture.whenStable();
    const frame = fixture.nativeElement.querySelector('iframe') as HTMLIFrameElement;
    const post = jest.fn();
    Object.defineProperty(frame, 'contentWindow', { value: { postMessage: post } });
    registered().stop();

    registered().start(false);
    fixture.detectChanges();

    expect(fixture.nativeElement.querySelector('iframe')).toBe(frame);
    expect(JSON.parse(post.mock.calls.at(-1)![0])).toMatchObject({ func: 'playVideo' });
  });
```

  - Add the far-away tests, with the fake service provided in `mountInList`'s providers (`{ provide: NearViewportMedia, useValue: nearViewport }`, where `nearViewport = { watch: jest.fn(), unwatch: jest.fn() }` is reset in a `beforeEach`):

```ts
  it('puts the Short back to its cover once it is far from the screen', async () => {
    const fixture = await mountInList(short());
    fixture.nativeElement.querySelector('button.play').click();
    fixture.detectChanges();
    await fixture.whenStable();
    const [box, client] = nearViewport.watch.mock.calls[0];
    expect(box).toBe(fixture.nativeElement.querySelector('.short-player'));

    client.leave();
    fixture.detectChanges();

    expect(fixture.nativeElement.querySelector('iframe')).toBeNull();
  });

  it('stops watching its Short when it goes away', async () => {
    const fixture = await mountInList(short());
    const box = fixture.nativeElement.querySelector('.short-player');

    fixture.destroy();

    expect(nearViewport.unwatch).toHaveBeenCalledWith(box);
  });
```

  - "puts a Short’s player away when the list pauses its media" (`MEDIA_PAUSE`) stays unchanged: it still removes the frame.

- [ ] **Step 5: Run to verify they fail.**
Run: `docker compose exec -T frontend npm test -- src/app/reader/list/magazine/blocks/entry-media`
Expected: the new and changed tests FAIL.

- [ ] **Step 6: Implement** in `entry-media.component.ts`:
  - Imports: `DOCUMENT` from `@angular/core`, `pauseEmbedFrame` from `'../../../../media/embed-frame-commands'`, `playerCommand, postToPlayerFrame` from `'../../../../media/youtube-player-protocol'`, `NearViewportMedia` from `'../../../../media/near-viewport-media'`.
  - Fields:

```ts
  private readonly nearViewport = inject(NearViewportMedia, { optional: true });
  private readonly origin = inject(DOCUMENT).location.origin;
  private watchedShort: Element | null = null;
```

  - `shortPlayer`: `computed(() => autoplayingShortPlayer(this.entry().shortPlayerUrl, this.origin))`.
  - In the `afterRenderEffect`, after `this.registerShortForAutoplay();` add `this.removeShortWhenFar();`. In `onDestroy` add `if (this.watchedShort) this.nearViewport?.unwatch(this.watchedShort);`.
  - In `pauseWhenScrolledAway`, change the callback's `this.stopShort()` to `this.pauseShort()`.
  - In `registerShortForAutoplay`, change `stop: () => this.stopShort()` to `stop: () => this.pauseShort()`.
  - Replace `startMutedShort` and add the new methods:

```ts
  private startMutedShort(): void {
    const frame = this.shortFrame();
    if (frame) {
      postToPlayerFrame(frame, playerCommand('playVideo', []));
      return;
    }
    const player = this.shortPlayer();
    if (player !== null) this.playingShort.set(mutedShortPlayer(player));
  }

  private pauseShort(): void {
    const frame = this.shortFrame();
    if (frame) pauseEmbedFrame(frame, 'youtube');
  }

  private removeShortWhenFar(): void {
    if (this.nearViewport === null || this.watchedShort) return;
    const box = this.host.querySelector('.short-player');
    if (box === null) return;
    this.watchedShort = box;
    this.nearViewport.watch(box, { approach: () => undefined, leave: () => this.stopShort() });
  }

  private shortFrame(): HTMLIFrameElement | null {
    return this.host.querySelector<HTMLIFrameElement>('.short-player iframe');
  }
```

  `stopShort()` (removing the frame) stays the handler for `(media-pause)`, and is now also used on far leave.

- [ ] **Step 7: Run the entry-media specs.** Same command as Step 5. Expected: PASS.

- [ ] **Step 8: Commit.**

```bash
git add frontend/src/app/reader/list/magazine/blocks/entry-media
git commit -m "feat(#1903): a Short pauses off-screen and gives up its player only far from it"
```

---

### Task 6: Gates, simplify, device check, PR

- [ ] **Step 1: Full frontend gate** (from the repo root):

```bash
docker compose exec -T frontend npm run check
```

  Expected: green. Then run `docker compose exec -T frontend npx ng build`, because `npm run check` misses template errors.

- [ ] **Step 2: `/simplify`** over the branch diff. Apply what it finds and rerun Step 1.

- [ ] **Step 3: Device check with Lars** (report to the planner, who relays the steps). Hard-reload `green-tara.local:4200` on the iPhone, then rerun the baseline route: the same feed, magazine layout, about a 30 s scroll, a Timeline recording with Network, CPU and Rendering Frames. Then:

```js
[...document.querySelectorAll('video')].filter(v => v.readyState >= 2).length
```

  Baseline from the issue: 18 videos, ~285 MB of video, 49 frames over 50 ms. Expected: a handful of videos (those within a screen), and a far smaller video volume. Also check by hand:
  - A video scrolled about two screens away and back shows its poster and plays from where it was.
  - A Short scrolled just past and back is paused, not rebuilt.
  - Sound unlock still works after a tap.

- [ ] **Step 4: Merge `develop` into the branch** if it moved, rerun Step 1, then open the PR against `develop`. Its body contains `Closes #1903`, the before/after numbers and the Task 0 outcome, and links #1904 as related (without a closing keyword).
