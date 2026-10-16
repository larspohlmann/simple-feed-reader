# List Autoplay: iOS Sound and Hidden Controls Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** On iOS, once the user turns sound on for a list video, every following autoplay in the list — including videos rendered later — starts with sound; and an autoplaying video shows no native control overlay until tapped.

**Architecture:** iOS WebKit allows an unmuted `play()` only on an element that was "unlocked" by a `muted` change inside a user gesture; the unlock survives later `src` changes and DOM moves. So a tap on a controlless autoplaying video reveals its controls, turns sound on and, in the same gesture, unlocks every registered list video and fills a list-scoped stock of spare unlocked `<video>` elements (`UnlockedVideoElements`). `PostMediaComponent` mounts its `<video>` from script, taking a spare when the list provides them, so later-rendered videos are born unlocked. Any later tap in the list re-unlocks and refills while sound is on.

**Tech Stack:** Angular 20 (standalone, signals), Jest (jsdom), Playwright.

**Spec:** GitHub issue #1654 (findings table and proposed behaviour); builds on `docs/superpowers/plans/2026-10-16-1641-list-video-autoplay.md`.

## Global Constraints

- Frontend only; no backend change.
- Branch `fix/1654-list-autoplay-ios-sound` (exists, off `develop`); commits `type(#1654): lower-case summary`; no Claude attribution; PR into `develop` with `Closes #1654`.
- Frontend tests run inside Docker, one Jest process at a time (the container OOMs otherwise): `docker compose exec -T frontend npx jest <path>` from the repo root. Gate: `docker compose exec -T frontend npm run check`.
- Prettier at 100 columns; component styles only in sibling `.scss`; no hex colours, ad-hoc `px` or media-query literals.
- Comments: default to none; one line when a future reader would get the code wrong without it.
- Unlocking toggles `muted` and must run synchronously inside the tap's event handler — never after an `await`, a timer or an effect.
- Spare stock size: `SPARE_VIDEO_ELEMENTS = 40`.
- Shorts (cross-origin YouTube embeds) cannot be unlocked and stay muted.

## Verified facts this plan relies on (iOS 26.3 simulator, issue #1654)

- Unmuted `play()` without a recent tap → `NotAllowedError`; within ~3 s of a tap it passes, 15 s later it is refused.
- Toggling `muted` (true→false or false→true) inside a tap unlocks that element; 15–30 s later it plays with sound. No `play()`, no network load.
- Detached, source-less elements unlocked in a tap keep the unlock after getting a `src`, being attached, and being moved with a new `src`.
- Elements created after the tap are refused.
- Setting `controls = false` right before the autoplay `play()` paints no overlay at all. Keeping `controls` during autoplay shows iOS's overlay for its first seconds — the current behaviour the user reported.

## File Structure

| File | Responsibility |
|---|---|
| `frontend/src/app/reader/media/autoplay/unlocked-video-elements.ts` (new) | List-scoped stock of spare `<video>` elements unlocked during a tap |
| `frontend/src/app/reader/media/autoplay/autoplay-player.ts` | `AutoplayPlayer` gains optional `unlockSound()` |
| `frontend/src/app/reader/media/autoplay/list-autoplay-coordinator.ts` | `unmutedByTap()` / `tapped()`: sound state plus unlocking players and spares |
| `frontend/src/app/reader/media/autoplay/video-autoplay-player.ts` | Controls hidden while autoplaying; tap/Enter/Space reveals and unmutes; `unlockSound()` |
| `frontend/src/app/reader/media/post-media/post-media.component.{ts,html,scss}` | Renders a slot per video and mounts the `<video>` from script, from the spares when present |
| `frontend/src/app/reader/list/entry-list/entry-list.component.ts` | Provides `UnlockedVideoElements`; forwards every tap (capture phase) to the coordinator |
| `frontend/e2e/list-video-autoplay.spec.ts` | Smoke: controls hidden while autoplaying, tap reveals them and unmutes |

---

### Task 1: Spare unlocked video elements

**Files:**
- Create: `frontend/src/app/reader/media/autoplay/unlocked-video-elements.ts`
- Test: `frontend/src/app/reader/media/autoplay/unlocked-video-elements.spec.ts`

**Interfaces:**
- Produces: `@Injectable() class UnlockedVideoElements` (not root — the entry list provides it) with `take(): HTMLVideoElement` and `refill(): void`; `export const SPARE_VIDEO_ELEMENTS = 40`.

- [ ] **Step 1: Write the failing test**

```ts
import { SPARE_VIDEO_ELEMENTS, UnlockedVideoElements } from './unlocked-video-elements';

const settle = () => new Promise((resolve) => setTimeout(resolve));

function recordCreatedVideos() {
  const created: HTMLVideoElement[] = [];
  const volumeChanges = jest.fn();
  const create = document.createElement.bind(document);
  jest.spyOn(document, 'createElement').mockImplementation(((tagName: string) => {
    const element = create(tagName) as HTMLVideoElement;
    element.addEventListener('volumechange', volumeChanges);
    created.push(element);
    return element;
  }) as typeof document.createElement);
  return { created, volumeChanges };
}

afterEach(() => jest.restoreAllMocks());

describe('UnlockedVideoElements', () => {
  it('hands out a fresh video element while nothing is unlocked', () => {
    const { created } = recordCreatedVideos();
    const elements = new UnlockedVideoElements();

    const video = elements.take();

    expect(video).toBeInstanceOf(HTMLVideoElement);
    expect(created).toEqual([video]);
  });

  it('unlocks a stock of spares by toggling their sound, and hands those out first', async () => {
    const { created, volumeChanges } = recordCreatedVideos();
    const elements = new UnlockedVideoElements();

    elements.refill();
    await settle();

    expect(created).toHaveLength(SPARE_VIDEO_ELEMENTS);
    expect(volumeChanges).toHaveBeenCalledTimes(2 * SPARE_VIDEO_ELEMENTS);
    const handedOut = Array.from({ length: SPARE_VIDEO_ELEMENTS }, () => elements.take());
    expect(new Set(handedOut)).toEqual(new Set(created));
    expect(handedOut.every((video) => !video.muted)).toBe(true);

    elements.take();
    expect(created).toHaveLength(SPARE_VIDEO_ELEMENTS + 1);
  });

  it('only tops the stock up on a later refill', () => {
    const { created } = recordCreatedVideos();
    const elements = new UnlockedVideoElements();
    elements.refill();
    elements.take();
    elements.take();

    elements.refill();

    expect(created).toHaveLength(SPARE_VIDEO_ELEMENTS + 2);
  });
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `docker compose exec -T frontend npx jest src/app/reader/media/autoplay/unlocked-video-elements.spec.ts`
Expected: FAIL — cannot find module `./unlocked-video-elements`.

- [ ] **Step 3: Write the implementation**

```ts
import { Injectable } from '@angular/core';

export const SPARE_VIDEO_ELEMENTS = 40;

/** Video elements iOS lets play with sound later: it unlocks per element, and only inside a tap. */
@Injectable()
export class UnlockedVideoElements {
  private readonly spares: HTMLVideoElement[] = [];

  take(): HTMLVideoElement {
    return this.spares.pop() ?? document.createElement('video');
  }

  /** Must run synchronously inside a tap's event handler. */
  refill(): void {
    while (this.spares.length < SPARE_VIDEO_ELEMENTS) {
      const video = document.createElement('video');
      video.muted = true;
      video.muted = false;
      this.spares.push(video);
    }
  }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `docker compose exec -T frontend npx jest src/app/reader/media/autoplay/unlocked-video-elements.spec.ts`
Expected: PASS (3 tests).

- [ ] **Step 5: Commit**

```bash
git add frontend/src/app/reader/media/autoplay/unlocked-video-elements.ts frontend/src/app/reader/media/autoplay/unlocked-video-elements.spec.ts
git commit -m "feat(#1654): keep a stock of video elements unlocked during a tap"
```

---

### Task 2: The coordinator unlocks on a tap

**Files:**
- Modify: `frontend/src/app/reader/media/autoplay/autoplay-player.ts`
- Modify: `frontend/src/app/reader/media/autoplay/list-autoplay-coordinator.ts`
- Test: `frontend/src/app/reader/media/autoplay/list-autoplay-coordinator.spec.ts`

**Interfaces:**
- Consumes: `UnlockedVideoElements.refill()` (Task 1).
- Produces: `AutoplayPlayer.unlockSound?(): void`; `ListAutoplayCoordinator.unmutedByTap(): void` (sound on + unlock) and `ListAutoplayCoordinator.tapped(): void` (unlock only while sound is on). Both must be called synchronously from a tap handler. The coordinator now injects `UnlockedVideoElements`, so every injector that provides the coordinator must provide it too (Task 5 does it for the entry list).

- [ ] **Step 1: Write the failing tests**

In `list-autoplay-coordinator.spec.ts`:

1. Add the import:

```ts
import { UnlockedVideoElements } from './unlocked-video-elements';
```

2. Replace the `FakePlayer` type and `aDetachedPlayer` with:

```ts
type FakePlayer = AutoplayPlayer & { start: jest.Mock; stop: jest.Mock; unlockSound: jest.Mock };

const aDetachedPlayer = (): FakePlayer => ({
  element: document.createElement('div'),
  start: jest.fn(),
  stop: jest.fn(),
  unlockSound: jest.fn(),
});
```

3. Replace `setUp` with:

```ts
function setUp(enabled = true) {
  const setting: WritableSignal<boolean> = signal(enabled);
  const unlockedVideos = { refill: jest.fn(), take: jest.fn() };
  TestBed.configureTestingModule({
    providers: [
      ListAutoplayCoordinator,
      { provide: ListAutoplayService, useValue: { enabled: setting } },
      { provide: UnlockedVideoElements, useValue: unlockedVideos },
    ],
  });
  const coordinator = TestBed.inject(ListAutoplayCoordinator);
  TestBed.tick();
  return { coordinator, setting, unlockedVideos };
}
```

4. Add these tests at the end of the `describe`:

```ts
  it('turns sound on and unlocks every player and the spares on an unmuting tap', () => {
    const { coordinator, unlockedVideos } = setUp();
    const playing = aPlayer();
    const waiting = aPlayer();
    coordinator.register(playing);
    coordinator.register(waiting);
    report(playing.element, 1);

    coordinator.unmutedByTap();

    expect(playing.unlockSound).toHaveBeenCalledTimes(1);
    expect(waiting.unlockSound).toHaveBeenCalledTimes(1);
    expect(unlockedVideos.refill).toHaveBeenCalledTimes(1);
    report(playing.element, 0);
    report(waiting.element, 1);
    expect(waiting.start).toHaveBeenCalledWith(true);
  });

  it('unlocks again on a later tap only while sound is on', () => {
    const { coordinator, unlockedVideos } = setUp();
    const player = aPlayer();
    coordinator.register(player);

    coordinator.tapped();
    expect(player.unlockSound).not.toHaveBeenCalled();
    expect(unlockedVideos.refill).not.toHaveBeenCalled();

    coordinator.soundChanged(true);
    coordinator.tapped();
    expect(player.unlockSound).toHaveBeenCalledTimes(1);
    expect(unlockedVideos.refill).toHaveBeenCalledTimes(1);
  });

  it('passes over a player that cannot unlock sound', () => {
    const { coordinator, unlockedVideos } = setUp();
    const short: AutoplayPlayer = {
      element: document.createElement('div'),
      start: jest.fn(),
      stop: jest.fn(),
    };
    document.body.append(short.element);
    coordinator.register(short);

    coordinator.unmutedByTap();

    expect(unlockedVideos.refill).toHaveBeenCalledTimes(1);
  });
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `docker compose exec -T frontend npx jest src/app/reader/media/autoplay/list-autoplay-coordinator.spec.ts`
Expected: FAIL — `coordinator.unmutedByTap is not a function` / `coordinator.tapped is not a function` (and TS errors on the missing members).

- [ ] **Step 3: Implement**

`autoplay-player.ts` becomes:

```ts
/** A list video the coordinator can start and stop; `element` is what its visibility is measured on. */
export interface AutoplayPlayer {
  readonly element: Element;
  start(withSound: boolean): void;
  stop(): void;
  /** Absent where sound cannot be unlocked, as in a cross-origin Short. */
  unlockSound?(): void;
}
```

In `list-autoplay-coordinator.ts`:

- import `UnlockedVideoElements` from `./unlocked-video-elements`;
- add the field after `private readonly setting = inject(ListAutoplayService);`:

```ts
  private readonly unlockedVideos = inject(UnlockedVideoElements);
```

- add after `soundChanged()`:

```ts
  unmutedByTap(): void {
    this.soundOn = true;
    this.unlockSound();
  }

  tapped(): void {
    if (this.soundOn) this.unlockSound();
  }
```

- add as the last private method:

```ts
  private unlockSound(): void {
    for (const player of this.players.values()) player.unlockSound?.();
    this.unlockedVideos.refill();
  }
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `docker compose exec -T frontend npx jest src/app/reader/media/autoplay/list-autoplay-coordinator.spec.ts`
Expected: PASS, the existing tests included.

- [ ] **Step 5: Commit**

```bash
git add frontend/src/app/reader/media/autoplay/autoplay-player.ts frontend/src/app/reader/media/autoplay/list-autoplay-coordinator.ts frontend/src/app/reader/media/autoplay/list-autoplay-coordinator.spec.ts
git commit -m "feat(#1654): unlock list video sound on every tap while sound is on"
```

---

### Task 3: The video player hides its controls while autoplaying

**Files:**
- Modify: `frontend/src/app/reader/media/autoplay/video-autoplay-player.ts`
- Test: `frontend/src/app/reader/media/autoplay/video-autoplay-player.spec.ts`

**Interfaces:**
- Consumes: `ListAutoplayCoordinator.unmutedByTap()` (Task 2).
- Produces: `VideoAutoplayPlayer.unlockSound(): void`. `start()` removes `controls` and makes the element focusable (`tabindex="0"`); `stop()` restores `controls` and drops the `tabindex`. A click, Enter or Space on the element while it has no controls reveals them, unmutes, and calls `coordinator.unmutedByTap()` — all synchronously. The element is expected to carry `controls` before the first `start()` (Task 4 mounts it that way).

- [ ] **Step 1: Write the failing tests**

In `video-autoplay-player.spec.ts`, replace `setUp` with:

```ts
function setUp() {
  const video = document.createElement('video');
  video.controls = true;
  const play = jest.spyOn(video, 'play').mockResolvedValue(undefined);
  const pause = jest.spyOn(video, 'pause').mockImplementation(() => undefined);
  const coordinator = { nowPlaying: jest.fn(), soundChanged: jest.fn(), unmutedByTap: jest.fn() };
  const player = new VideoAutoplayPlayer(video, coordinator);
  return { video, play, pause, coordinator, player };
}
```

and add these tests at the end of the `describe`:

```ts
  it('hides its controls while autoplaying and shows them again once stopped', () => {
    const { video, player } = setUp();

    player.start(false);
    expect(video.controls).toBe(false);
    expect(video.getAttribute('tabindex')).toBe('0');

    player.stop();
    expect(video.controls).toBe(true);
    expect(video.hasAttribute('tabindex')).toBe(false);
  });

  it('shows its controls and turns sound on when its autoplay is tapped', async () => {
    const { video, coordinator, player } = setUp();
    player.start(false);
    await settle();

    video.click();

    expect(video.controls).toBe(true);
    expect(video.muted).toBe(false);
    expect(coordinator.unmutedByTap).toHaveBeenCalledTimes(1);
    await settle();
    expect(coordinator.soundChanged).toHaveBeenLastCalledWith(true);
  });

  it('leaves a tap on its visible controls to the browser', () => {
    const { video, coordinator } = setUp();
    video.muted = true;

    video.click();

    expect(video.muted).toBe(true);
    expect(coordinator.unmutedByTap).not.toHaveBeenCalled();
  });

  it('takes Enter or Space on its controlless autoplay as the tap', () => {
    const { video, coordinator, player } = setUp();
    player.start(false);
    const space = new KeyboardEvent('keydown', { key: ' ', cancelable: true });

    video.dispatchEvent(space);

    expect(space.defaultPrevented).toBe(true);
    expect(video.controls).toBe(true);
    expect(coordinator.unmutedByTap).toHaveBeenCalledTimes(1);
  });

  it('ignores other keys', () => {
    const { video, coordinator, player } = setUp();
    player.start(false);

    video.dispatchEvent(new KeyboardEvent('keydown', { key: 'a', cancelable: true }));

    expect(video.controls).toBe(false);
    expect(coordinator.unmutedByTap).not.toHaveBeenCalled();
  });

  it('unlocks sound without changing or reporting its muted state', async () => {
    const { video, coordinator, player } = setUp();
    player.start(false);
    await settle();

    player.unlockSound();
    await settle();

    expect(video.muted).toBe(true);
    expect(coordinator.soundChanged).not.toHaveBeenCalled();
  });
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `docker compose exec -T frontend npx jest src/app/reader/media/autoplay/video-autoplay-player.spec.ts`
Expected: FAIL — controls stay `true` after `start`, `unlockSound` is not a function.

- [ ] **Step 3: Implement**

`video-autoplay-player.ts` becomes:

```ts
import { AutoplayPlayer } from './autoplay-player';
import { ListAutoplayCoordinator } from './list-autoplay-coordinator';

type Coordinator = Pick<ListAutoplayCoordinator, 'nowPlaying' | 'soundChanged' | 'unmutedByTap'>;

export class VideoAutoplayPlayer implements AutoplayPlayer {
  private pendingOwnChanges = 0;
  private wanted = false;
  private readonly listeners = new AbortController();

  constructor(
    readonly element: HTMLVideoElement,
    private readonly coordinator: Coordinator,
  ) {
    const { signal } = this.listeners;
    element.addEventListener('play', () => coordinator.nowPlaying(this), { signal });
    element.addEventListener('volumechange', () => this.reportSound(), { signal });
    element.addEventListener('click', () => this.revealByTap(), { signal });
    element.addEventListener('keydown', (event) => this.revealByKey(event), { signal });
  }

  start(withSound: boolean): void {
    this.wanted = true;
    this.hideControls();
    this.setMuted(!withSound);
    this.element.play().catch((error: unknown) => {
      if (withSound && this.wanted && isRefusal(error)) this.start(false);
    });
  }

  stop(): void {
    this.wanted = false;
    this.element.pause();
    this.showControls();
  }

  /** Must run synchronously inside a tap's event handler. */
  unlockSound(): void {
    const muted = this.element.muted;
    this.setMuted(!muted);
    this.setMuted(muted);
  }

  dispose(): void {
    this.listeners.abort();
  }

  private revealByTap(): void {
    if (this.element.controls) return;
    this.showControls();
    this.element.muted = false;
    this.coordinator.unmutedByTap();
  }

  private revealByKey(event: KeyboardEvent): void {
    if (this.element.controls || (event.key !== 'Enter' && event.key !== ' ')) return;
    event.preventDefault();
    this.revealByTap();
  }

  private hideControls(): void {
    this.element.controls = false;
    this.element.tabIndex = 0;
  }

  private showControls(): void {
    this.element.controls = true;
    this.element.removeAttribute('tabindex');
  }

  private setMuted(muted: boolean): void {
    if (this.element.muted === muted) return;
    this.pendingOwnChanges++;
    this.element.muted = muted;
  }

  private reportSound(): void {
    if (this.pendingOwnChanges > 0) {
      this.pendingOwnChanges--;
      return;
    }
    this.coordinator.soundChanged(!this.element.muted);
  }
}

/** Only a refused autoplay earns the muted retry; an HLS source swap aborts the first play too. */
function isRefusal(error: unknown): boolean {
  return error instanceof DOMException && error.name === 'NotAllowedError';
}
```

Why `revealByTap` sets `muted` directly and not through `setMuted`: the unmute is the user's choice, so its `volumechange` must reach `soundChanged(true)`. When the coordinator then unlocks this same player, the two counted toggles queue behind the user's event; every event reads the final `muted === false`, so whichever one is reported reports `true`.

- [ ] **Step 4: Run tests to verify they pass**

Run: `docker compose exec -T frontend npx jest src/app/reader/media/autoplay/video-autoplay-player.spec.ts`
Expected: PASS, the existing tests included.

- [ ] **Step 5: Commit**

```bash
git add frontend/src/app/reader/media/autoplay/video-autoplay-player.ts frontend/src/app/reader/media/autoplay/video-autoplay-player.spec.ts
git commit -m "feat(#1654): hide controls while autoplaying; a tap reveals them and turns sound on"
```

---

### Task 4: Post media mounts its videos from the unlocked stock

**Files:**
- Modify: `frontend/src/app/reader/media/post-media/post-media.component.html`
- Modify: `frontend/src/app/reader/media/post-media/post-media.component.ts`
- Modify: `frontend/src/app/reader/media/post-media/post-media.component.scss`
- Test: `frontend/src/app/reader/media/post-media/post-media.component.spec.ts`

**Interfaces:**
- Consumes: `UnlockedVideoElements.take()` (Task 1), injected optionally — the reader view (quoted posts) has none and gets plain elements. `VideoAutoplayPlayer` (Task 3) expects `controls` on the element before its first `start()`.
- Produces: each video medium renders as `<div class="item video-slot">` containing a script-mounted `<video controls playsinline preload="none" src=… poster=… aria-label=…>`. Selectors elsewhere (`app-entry-media video`, `pauseMedia`, `armHlsStreams`) keep working because the `<video>` stays inside the component host.

Why script-mounted: iOS unlocks an *element*, so a later-rendered video must *be* one of the elements unlocked during the tap. A template-rendered `<video>` is always a new element.

- [ ] **Step 1: Write the failing tests**

In `post-media.component.spec.ts`:

1. Add the import:

```ts
import { UnlockedVideoElements } from '../autoplay/unlocked-video-elements';
```

2. In `'plays a video inline from its poster, named by the given label'`, change the selector `'video.item'` to `'.video-slot video'`, and add `expect(video.getAttribute('src')).toBe(clip.url);` after the `aria-label` expectation.

3. In `describe('PostMediaComponent in the list')`, add `unmutedByTap: jest.fn(),` to the `coordinator` literal, and replace `mountInList` with:

```ts
  async function mountInList(
    media: EntryMediumDto[],
    unlockedVideos = { take: () => document.createElement('video') },
  ) {
    TestBed.configureTestingModule({
      imports: [HostComponent],
      providers: [
        { provide: ListAutoplayCoordinator, useValue: coordinator },
        { provide: UnlockedVideoElements, useValue: unlockedVideos },
      ],
    });
    const fixture = TestBed.createComponent(HostComponent);
    fixture.componentInstance.media = media;
    fixture.detectChanges();
    await fixture.whenStable();
    return fixture;
  }
```

4. Add to that `describe`:

```ts
  it("mounts each video from the list's unlocked elements", async () => {
    const unlocked = document.createElement('video');
    const fixture = await mountInList([clip], { take: () => unlocked });

    expect(fixture.nativeElement.querySelector('.video-slot video')).toBe(unlocked);
    expect(unlocked.controls).toBe(true);
    expect(unlocked.getAttribute('src')).toBe(clip.url);
    expect(unlocked.getAttribute('poster')).toBe(clip.previewImageUrl);
  });

  it('keeps its mounted video across a re-render', async () => {
    const take = jest.fn(() => document.createElement('video'));
    const fixture = await mountInList([clip], { take });

    fixture.componentInstance.media = [clip, image(2)];
    fixture.detectChanges();
    await fixture.whenStable();

    expect(take).toHaveBeenCalledTimes(1);
    expect(fixture.nativeElement.querySelectorAll('video')).toHaveLength(1);
  });
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `docker compose exec -T frontend npx jest src/app/reader/media/post-media/post-media.component.spec.ts`
Expected: FAIL — no `.video-slot` in the DOM.

- [ ] **Step 3: Implement**

`post-media.component.html` — replace the `<video …></video>` branch with a slot:

```html
@for (medium of media(); track medium.url) {
  @if (medium.kind === 'video') {
    <div
      class="item video-slot"
      (click)="$event.stopPropagation()"
      (keydown)="$event.stopPropagation()"
    ></div>
  } @else {
    <img
      class="item"
      appProxiedImage
      [src]="medium.url"
      [attr.width]="medium.width ?? null"
      [attr.height]="medium.height ?? null"
      alt=""
      loading="lazy"
      decoding="async"
      referrerpolicy="no-referrer"
    />
  }
}
```

`post-media.component.scss` — replace the `video.item` rule with:

```scss
/* Above a quoted post's stretched link (#1612), so its controls play rather than open the post. */
.video-slot {
  position: relative;
  z-index: 1;
}

// Mounted from script (#1654), so the video carries no encapsulation attribute.
.video-slot ::ng-deep video {
  @include post-media-grid.item;
}
```

`post-media.component.ts`:

- import `UnlockedVideoElements` from `'../autoplay/unlocked-video-elements'`;
- add the field after `private readonly autoplay = …`:

```ts
  private readonly unlockedVideos = inject(UnlockedVideoElements, { optional: true });
```

- in the `afterRenderEffect`, replace `this.media();` with `this.mountVideos(this.media(), this.videoLabel());` so the effect body reads:

```ts
    afterRenderEffect(() => {
      this.mountVideos(this.media(), this.videoLabel());
      armHlsStreams(this.host);
      const videos = Array.from(this.host.querySelectorAll('video'));
      this.pauseWhenScrolledAway(videos);
      this.registerForAutoplay(videos);
    });
```

- add these private methods above `pauseWhenScrolledAway`:

```ts
  private mountVideos(media: EntryMediumDto[], label: string): void {
    const clips = media.filter((medium) => medium.kind === 'video');
    this.host.querySelectorAll('.video-slot').forEach((slot, index) => {
      const video = slot.querySelector('video') ?? slot.appendChild(this.newVideo(clips[index]));
      video.setAttribute('aria-label', label);
    });
  }

  private newVideo(clip: EntryMediumDto): HTMLVideoElement {
    const video = this.unlockedVideos?.take() ?? document.createElement('video');
    video.controls = true;
    video.setAttribute('playsinline', '');
    video.setAttribute('preload', 'none');
    video.setAttribute('src', clip.url);
    if (clip.previewImageUrl) video.setAttribute('poster', clip.previewImageUrl);
    return video;
  }
```

`src` and `poster` are set as attributes because `armHlsStreams` reads `getAttribute('src')` to spot a playlist. The slots line up with `clips` by index because `@for` renders them in `media()` order; `track medium.url` keeps a slot, and with it its mounted video, across re-renders.

- [ ] **Step 4: Run tests to verify they pass**

Run: `docker compose exec -T frontend npx jest src/app/reader/media/post-media`
Expected: PASS, the existing tests included (the stop-propagation test now passes through the slot).

Then run the other suites that render post media: `docker compose exec -T frontend npx jest src/app/reader/list/magazine src/app/reader/quoted-post`
Expected: PASS. A failure that selects `video.item` means a selector to update to `.video-slot video`.

- [ ] **Step 5: Commit**

```bash
git add frontend/src/app/reader/media/post-media
git commit -m "feat(#1654): mount post videos from the list's unlocked elements"
```

---

### Task 5: The entry list provides the stock and forwards every tap

**Files:**
- Modify: `frontend/src/app/reader/list/entry-list/entry-list.component.ts` (providers at line ~61; add a constructor)
- Test: `frontend/src/app/reader/list/entry-list/entry-list.component.spec.ts`

**Interfaces:**
- Consumes: `UnlockedVideoElements` (Task 1), `ListAutoplayCoordinator.tapped()` (Task 2).
- Produces: `providers: [ListAutoplayCoordinator, UnlockedVideoElements]`; a capture-phase `click` listener on the host calling `autoplay.tapped()` — capture, because rows and the video slot stop propagation.

- [ ] **Step 1: Write the failing test**

Add next to `'suspends list autoplay while an entry is open and resumes it once closed'`:

```ts
  it('hands every tap in the list to autoplay, even one a row stops', () => {
    const fixture = mount();
    const coordinator = fixture.debugElement.injector.get(ListAutoplayCoordinator);
    const tapped = jest.spyOn(coordinator, 'tapped');
    const stopping = document.createElement('button');
    stopping.addEventListener('click', (event) => event.stopPropagation());
    (fixture.nativeElement as HTMLElement).append(stopping);

    stopping.click();

    expect(tapped).toHaveBeenCalledTimes(1);
  });
```

- [ ] **Step 2: Run test to verify it fails**

Run: `docker compose exec -T frontend npx jest src/app/reader/list/entry-list/entry-list.component.spec.ts -t "hands every tap"`
Expected: FAIL — first with a DI error (`No provider for UnlockedVideoElements`, since Task 2 made the coordinator inject it), then, once provided, `tapped` called 0 times.

- [ ] **Step 3: Implement**

In `entry-list.component.ts`:

- import `UnlockedVideoElements` from `'../../media/autoplay/unlocked-video-elements'`;
- change `providers: [ListAutoplayCoordinator],` to `providers: [ListAutoplayCoordinator, UnlockedVideoElements],`;
- add a constructor directly above `scrollToTop(): void {` (the first method; everything above it is field initializers, which run before the constructor body):

```ts
  constructor() {
    this.host.nativeElement.addEventListener('click', () => this.autoplay.tapped(), {
      capture: true,
    });
  }
```

The listener lives as long as the host element, so it needs no cleanup.

- [ ] **Step 4: Run tests to verify they pass**

Run: `docker compose exec -T frontend npx jest src/app/reader/list/entry-list`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add frontend/src/app/reader/list/entry-list/entry-list.component.ts frontend/src/app/reader/list/entry-list/entry-list.component.spec.ts
git commit -m "feat(#1654): unlock list video sound on any tap in the list"
```

---

### Task 6: Playwright smoke

**Files:**
- Modify: `frontend/e2e/list-video-autoplay.spec.ts`

- [ ] **Step 1: Add the test**

Append:

```ts
test('an autoplaying list video hides its controls until tapped, and the tap turns sound on', async ({
  page,
}) => {
  await recordPlays(page);
  await stubEntries(page);
  test.skip(!(await signInAsAdmin(page)), 'no admin account on this stack');

  const video = page.locator('app-entry-media video');
  await expect.poll(() => plays(page)).toEqual([{ src: CLIP, muted: true }]);
  await expect(video).not.toHaveAttribute('controls');

  await video.click();

  await expect(video).toHaveAttribute('controls');
  expect(await video.evaluate((element: HTMLVideoElement) => element.muted)).toBe(false);
});
```

- [ ] **Step 2: Run it against the Docker stack**

Run (from `frontend/`, with the Docker stack up from this checkout): `npx playwright test e2e/list-video-autoplay.spec.ts`
Expected: 3 passed. The spec stubs `/api/entries`, so it owns its data.

- [ ] **Step 3: Commit**

```bash
git add frontend/e2e/list-video-autoplay.spec.ts
git commit -m "test(#1654): smoke the hidden controls and the unmuting tap"
```

---

### Task 7: Gate, real-iPhone check, PR

- [ ] **Step 1: Full frontend gate**

Run: `docker compose exec -T frontend npm run check`
Expected: green (ESLint, Prettier, Stylelint, tsc, Jest).

- [ ] **Step 2: Real-iPhone check (Lars)** — the simulator cannot render the social feeds on this host, so this is the proof. On a prod build (`:4300`, not `:4200`), in a social list with several native videos:
  1. The first autoplaying video shows no control overlay at any point.
  2. Tap it: controls appear and sound plays.
  3. Scroll on, waiting well over 15 s between videos: each following autoplay starts **with sound** and without controls.
  4. Scroll until the next page loads, then on to its videos: they also start with sound.
  5. Mute one with its controls: the following autoplays start muted.
  6. With autoplay switched off in settings, videos show their controls and play only by hand.

- [ ] **Step 3: PR**

```bash
git push -u origin fix/1654-list-autoplay-ios-sound
gh pr create --base develop --title "List autoplay: sound carries to later videos on iOS; no controls while autoplaying" --body "Closes #1654"
```

Expand the body with the issue's findings table and the device-check result before creating it.
