# List-view Video Autoplay Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** List-view videos (native post/quoted-post videos and YouTube Shorts) autoplay muted, one at a time, behind a per-device setting; unmuting a native video carries sound to the following native autoplays for the session.

**Architecture:** A per-device `ListAutoplayService` holds the setting. A `ListAutoplayCoordinator`, provided by `EntryListComponent` (so nothing outside the list autoplays), watches registered players with one `IntersectionObserver` and starts the most visible one. Players implement a three-member `AutoplayPlayer` interface: `VideoAutoplayPlayer` wraps a native `<video>`, and `EntryMediaComponent` registers an inline player for its Short.

**Tech Stack:** Angular 20 (standalone, signals), Jest (jsdom), Playwright, Transloco.

**Spec:** `docs/superpowers/specs/2026-10-16-1641-list-video-autoplay-design.md`

## Global Constraints

- Frontend only; no backend change.
- Branch `feature/1641-list-video-autoplay`; commits `type(#1641): lower-case summary`; no Claude attribution.
- Frontend tests run inside Docker: `docker compose exec -T frontend npx jest <path>` from the repo root; the gate is `docker compose exec -T frontend npm run check`. Never run two Jest processes at once (the container OOMs).
- Prettier at 100 columns; component styles only in sibling `.scss`; no hex colours or ad-hoc `px` outside the theme.
- Comments: default to none; one line when one is needed (CLAUDE.md "Default to no comment").
- Visibility threshold for a candidate: `0.6`. Setting key: `listAutoplay`, values `'on'` / `'off'`, through `UserDeviceStorage`.
- i18n keys `settings.listAutoplay` / `settings.listAutoplayHint` in both `frontend/public/i18n/en.json` and `de.json`.

## File Structure

| File | Responsibility |
|---|---|
| `frontend/src/app/core/reduced-motion.ts` (moved from `reader/article/reading/reduced-motion.ts`) | `prefersReducedMotion()`; core now needs it, and core must not import from `reader` |
| `frontend/src/app/core/preferences/list-autoplay.service.ts` | The per-device setting |
| `frontend/src/app/settings/preferences/preferences-section.component.{ts,html}` | The toggle row |
| `frontend/src/app/reader/media/autoplay/autoplay-player.ts` | `AutoplayPlayer` interface |
| `frontend/src/app/reader/media/autoplay/list-autoplay-coordinator.ts` | Picks and switches the playing player; sound state; suspension |
| `frontend/src/app/reader/media/autoplay/video-autoplay-player.ts` | Native `<video>` as an `AutoplayPlayer`; muted fallback; reports hand play and sound |
| `frontend/src/app/reader/media/post-media/post-media.component.ts` | Registers its videos when a coordinator is present |
| `frontend/src/app/reader/list/magazine/blocks/entry-media/short-embed.ts` | `mutedShortPlayer()` |
| `frontend/src/app/reader/list/magazine/blocks/entry-media/entry-media.component.{ts,html}` | Registers its Short; playing state holds the player URL |
| `frontend/src/app/reader/list/entry-list/entry-list.component.ts` | Provides the coordinator; suspends it while an entry is open |
| `frontend/e2e/list-video-autoplay.spec.ts` | Smoke on a stubbed list |

---

### Task 1: Per-device autoplay setting

**Files:**
- Move: `frontend/src/app/reader/article/reading/reduced-motion.ts` → `frontend/src/app/core/reduced-motion.ts`
- Modify importers: `reader/shell/audio-player-bar/big-player/big-player.component.ts`, `reader/article/reading/reading-scope.service.ts`, `reader/article/reader-view/article-gestures.service.ts`, `reader/article/reader-view/reader-view.component.ts` (and any spec that imports or mocks the old path — find with `grep -rn "reduced-motion'" frontend/src`)
- Create: `frontend/src/app/core/preferences/list-autoplay.service.ts`
- Test: `frontend/src/app/core/preferences/list-autoplay.service.spec.ts`

**Interfaces:**
- Produces: `ListAutoplayService` (root) with `readonly enabled: Signal<boolean>` and `setEnabled(enabled: boolean): void`; `prefersReducedMotion(): boolean` from `core/reduced-motion`.

- [ ] **Step 1: Move the helper**

```bash
git mv frontend/src/app/reader/article/reading/reduced-motion.ts frontend/src/app/core/reduced-motion.ts
grep -rln "reduced-motion'" frontend/src
```

Rewrite each import found to the new relative path of `frontend/src/app/core/reduced-motion` (e.g. from `reader/article/reading/reading-scope.service.ts` it is `'../../../core/reduced-motion'`; from `reader/shell/audio-player-bar/big-player/big-player.component.ts` it is `'../../../../core/reduced-motion'`). This includes `jest.mock(...)` paths in specs.

- [ ] **Step 2: Write the failing test**

`frontend/src/app/core/preferences/list-autoplay.service.spec.ts`:

```ts
import { WritableSignal, signal } from '@angular/core';
import { TestBed } from '@angular/core/testing';
import { ListAutoplayService } from './list-autoplay.service';
import { UserDeviceStorage } from './user-device-storage';
import { provideAccountIdentity } from '../../../testing/account-identity-testing';

describe('ListAutoplayService', () => {
  let userId: WritableSignal<number | null>;
  let realMatchMedia: typeof globalThis.matchMedia | undefined;

  beforeEach(() => {
    localStorage.clear();
    realMatchMedia = globalThis.matchMedia;
    userId = signal<number | null>(7);
    TestBed.configureTestingModule({ providers: [provideAccountIdentity(userId)] });
  });

  afterEach(() => {
    globalThis.matchMedia = realMatchMedia as typeof globalThis.matchMedia;
  });

  function reduceMotion(reduce: boolean) {
    globalThis.matchMedia = ((query: string) => ({
      matches: reduce && query.includes('prefers-reduced-motion'),
    })) as unknown as typeof globalThis.matchMedia;
  }

  it('autoplays by default', () => {
    reduceMotion(false);
    expect(TestBed.inject(ListAutoplayService).enabled()).toBe(true);
  });

  it('does not autoplay by default when the system asks for reduced motion', () => {
    reduceMotion(true);
    expect(TestBed.inject(ListAutoplayService).enabled()).toBe(false);
  });

  it('lets a stored choice beat the reduced-motion default', () => {
    reduceMotion(true);
    const service = TestBed.inject(ListAutoplayService);

    service.setEnabled(true);

    expect(service.enabled()).toBe(true);
    expect(localStorage.getItem('sfr.user.7.listAutoplay')).toBe('on');
  });

  it('stores a switched-off choice for this account on this device', () => {
    reduceMotion(false);
    const service = TestBed.inject(ListAutoplayService);

    service.setEnabled(false);

    expect(service.enabled()).toBe(false);
    expect(localStorage.getItem('sfr.user.7.listAutoplay')).toBe('off');
  });

  it('falls back to the default once the account forgets its device values', () => {
    reduceMotion(false);
    const service = TestBed.inject(ListAutoplayService);
    service.setEnabled(false);

    TestBed.inject(UserDeviceStorage).forgetCurrentUser();

    expect(service.enabled()).toBe(true);
  });
});
```

- [ ] **Step 3: Run it to verify it fails**

Run: `docker compose exec -T frontend npx jest src/app/core/preferences/list-autoplay.service.spec.ts`
Expected: FAIL — cannot find module `./list-autoplay.service`.

- [ ] **Step 4: Implement**

`frontend/src/app/core/preferences/list-autoplay.service.ts`:

```ts
import { Injectable, computed, inject } from '@angular/core';
import { prefersReducedMotion } from '../reduced-motion';
import { UserDeviceStorage } from './user-device-storage';

const NAME = 'listAutoplay';

/** Whether list videos start on their own; chosen per device, since it is a bandwidth choice. */
@Injectable({ providedIn: 'root' })
export class ListAutoplayService {
  private readonly storage = inject(UserDeviceStorage);

  readonly enabled = computed(() => {
    const stored = this.storage.read(NAME);
    if (stored === 'on') return true;
    if (stored === 'off') return false;
    return !prefersReducedMotion();
  });

  setEnabled(enabled: boolean): void {
    this.storage.write(NAME, enabled ? 'on' : 'off');
  }
}
```

- [ ] **Step 5: Run the new spec and the specs of the moved helper's importers**

Run: `docker compose exec -T frontend npx jest src/app/core/preferences/list-autoplay.service.spec.ts src/app/reader/article src/app/reader/shell/audio-player-bar`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add -A frontend/src/app/core frontend/src/app/reader
git commit -m "feat(#1641): per-device list autoplay setting"
```

---

### Task 2: Settings toggle

**Files:**
- Modify: `frontend/src/app/settings/preferences/preferences-section.component.ts`
- Modify: `frontend/src/app/settings/preferences/preferences-section.component.html` (new row directly after the reading-focus row)
- Modify: `frontend/public/i18n/en.json`, `frontend/public/i18n/de.json` (after `readingFocusHint`)
- Test: `frontend/src/app/settings/preferences/preferences-section.component.spec.ts`

**Interfaces:**
- Consumes: `ListAutoplayService.enabled`, `ListAutoplayService.setEnabled` (Task 1).

- [ ] **Step 1: Write the failing tests** (append inside the existing `describe`; add `import { ListAutoplayService } from '../../core/preferences/list-autoplay.service';`)

```ts
  it('offers list autoplay, switched on by default', () => {
    const fixture = mount();
    const toggle = fixture.nativeElement.querySelector('#list-autoplay-toggle') as HTMLInputElement;

    expect(fixture.nativeElement.textContent).toContain(en.settings.listAutoplay);
    expect(toggle.checked).toBe(true);
  });

  it('switches list autoplay off through its toggle', () => {
    const fixture = mount();
    const toggle = fixture.nativeElement.querySelector('#list-autoplay-toggle') as HTMLInputElement;

    toggle.click();
    fixture.detectChanges();

    expect(TestBed.inject(ListAutoplayService).enabled()).toBe(false);
  });
```

If `mount()` has no signed-in account (`UserDeviceStorage` then neither reads nor writes), add `provideAccountIdentity()` from `'../../../testing/account-identity-testing'` to `mount()`'s providers. Read `frontend/src/app/shared/toggle/toggle.component.ts` first to confirm that a click on the input emits `toggled`; if it listens on another event, dispatch that one.

- [ ] **Step 2: Run to verify failure**

Run: `docker compose exec -T frontend npx jest src/app/settings/preferences`
Expected: FAIL — no `#list-autoplay-toggle`, and `en.settings.listAutoplay` does not type-check.

- [ ] **Step 3: Implement**

`en.json`, after `"readingFocusHint"`:

```json
    "listAutoplay": "Autoplay videos in the list",
    "listAutoplayHint": "Muted, one at a time. Saved on this device.",
```

`de.json`, after `"readingFocusHint"`:

```json
    "listAutoplay": "Videos in der Liste automatisch abspielen",
    "listAutoplayHint": "Stumm, eins nach dem anderen. Gilt für dieses Gerät.",
```

Template row, after the reading-focus `</app-settings-row>`:

```html
    <app-settings-row
      [title]="'settings.listAutoplay' | transloco"
      [description]="'settings.listAutoplayHint' | transloco"
      labelFor="list-autoplay-toggle"
    >
      <app-toggle
        inputId="list-autoplay-toggle"
        [checked]="listAutoplay.enabled()"
        [label]="'settings.listAutoplay' | transloco"
        (toggled)="listAutoplay.setEnabled($event)"
      />
    </app-settings-row>
```

Component: `import { ListAutoplayService } from '../../core/preferences/list-autoplay.service';` and the field `readonly listAutoplay = inject(ListAutoplayService);` beside `readingFocus`.

- [ ] **Step 4: Run to verify pass**

Run: `docker compose exec -T frontend npx jest src/app/settings/preferences`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add frontend/src/app/settings/preferences frontend/public/i18n
git commit -m "feat(#1641): list autoplay toggle in preferences"
```

---

### Task 3: Autoplay coordinator

**Files:**
- Create: `frontend/src/app/reader/media/autoplay/autoplay-player.ts`
- Create: `frontend/src/app/reader/media/autoplay/list-autoplay-coordinator.ts`
- Test: `frontend/src/app/reader/media/autoplay/list-autoplay-coordinator.spec.ts`

**Interfaces:**
- Consumes: `ListAutoplayService.enabled` (Task 1).
- Produces:
  ```ts
  export interface AutoplayPlayer {
    readonly element: Element;
    start(withSound: boolean): void;
    stop(): void;
  }
  @Injectable() export class ListAutoplayCoordinator {
    register(player: AutoplayPlayer): void;
    unregister(player: AutoplayPlayer): void;
    nowPlaying(player: AutoplayPlayer): void;   // any play, by hand or by the coordinator
    soundChanged(soundOn: boolean): void;
    suspend(): void;
    resume(): void;
  }
  ```
  Not `providedIn: 'root'` — Task 7 provides it on `EntryListComponent`.

- [ ] **Step 1: Write the failing test**

```ts
import { WritableSignal, signal } from '@angular/core';
import { TestBed } from '@angular/core/testing';
import { ListAutoplayService } from '../../../core/preferences/list-autoplay.service';
import { AutoplayPlayer } from './autoplay-player';
import { ListAutoplayCoordinator } from './list-autoplay-coordinator';

let report: (element: Element, ratio: number) => void;
let realObserver: typeof IntersectionObserver;

beforeEach(() => {
  realObserver = globalThis.IntersectionObserver;
  globalThis.IntersectionObserver = class {
    constructor(callback: IntersectionObserverCallback) {
      report = (target, intersectionRatio) =>
        callback(
          [{ target, intersectionRatio } as unknown as IntersectionObserverEntry],
          {} as IntersectionObserver,
        );
    }
    readonly observe = jest.fn();
    readonly unobserve = jest.fn();
    readonly disconnect = jest.fn();
  } as unknown as typeof IntersectionObserver;
});

afterEach(() => (globalThis.IntersectionObserver = realObserver));

const aPlayer = (): AutoplayPlayer & { start: jest.Mock; stop: jest.Mock } => ({
  element: document.createElement('div'),
  start: jest.fn(),
  stop: jest.fn(),
});

function setUp(enabled = true) {
  const setting: WritableSignal<boolean> = signal(enabled);
  TestBed.configureTestingModule({
    providers: [ListAutoplayCoordinator, { provide: ListAutoplayService, useValue: { enabled: setting } }],
  });
  const coordinator = TestBed.inject(ListAutoplayCoordinator);
  TestBed.tick();
  return { coordinator, setting };
}

describe('ListAutoplayCoordinator', () => {
  it('starts the most visible player, muted', () => {
    const { coordinator } = setUp();
    const half = aPlayer();
    const most = aPlayer();
    coordinator.register(half);
    coordinator.register(most);

    report(half.element, 0.7);
    report(most.element, 0.9);

    expect(most.start).toHaveBeenCalledWith(false);
    expect(half.stop).toHaveBeenCalledTimes(1);
  });

  it('starts nothing under the visibility threshold', () => {
    const { coordinator } = setUp();
    const player = aPlayer();
    coordinator.register(player);

    report(player.element, 0.5);

    expect(player.start).not.toHaveBeenCalled();
  });

  it('stops the playing player once it drops under the threshold, and restarts it when it returns', () => {
    const { coordinator } = setUp();
    const player = aPlayer();
    coordinator.register(player);

    report(player.element, 1);
    report(player.element, 0.2);
    report(player.element, 1);

    expect(player.stop).toHaveBeenCalledTimes(1);
    expect(player.start).toHaveBeenCalledTimes(2);
  });

  it('starts nothing while the setting is off, and stops its player when it is switched off', () => {
    const { coordinator, setting } = setUp(false);
    const player = aPlayer();
    coordinator.register(player);
    report(player.element, 1);
    expect(player.start).not.toHaveBeenCalled();

    setting.set(true);
    TestBed.tick();
    expect(player.start).toHaveBeenCalledTimes(1);

    setting.set(false);
    TestBed.tick();
    expect(player.stop).toHaveBeenCalledTimes(1);
  });

  it('stops its player while suspended and picks up again on resume', () => {
    const { coordinator } = setUp();
    const player = aPlayer();
    coordinator.register(player);
    report(player.element, 1);

    coordinator.suspend();
    TestBed.tick();
    expect(player.stop).toHaveBeenCalledTimes(1);

    coordinator.resume();
    TestBed.tick();
    expect(player.start).toHaveBeenCalledTimes(2);
  });

  it('stops the playing player when another one is played by hand, with the setting off too', () => {
    const { coordinator } = setUp(false);
    const first = aPlayer();
    const second = aPlayer();
    coordinator.register(first);
    coordinator.register(second);

    coordinator.nowPlaying(first);
    coordinator.nowPlaying(second);

    expect(first.stop).toHaveBeenCalledTimes(1);
    expect(second.stop).not.toHaveBeenCalled();
  });

  it('starts the following players with sound once sound is on, and muted once it is off again', () => {
    const { coordinator } = setUp();
    const first = aPlayer();
    const second = aPlayer();
    coordinator.register(first);
    coordinator.register(second);

    coordinator.soundChanged(true);
    report(first.element, 1);
    expect(first.start).toHaveBeenCalledWith(true);

    coordinator.soundChanged(false);
    report(first.element, 0);
    report(second.element, 1);
    expect(second.start).toHaveBeenCalledWith(false);
  });

  it('stops an unregistered playing player and moves on to the next visible one', () => {
    const { coordinator } = setUp();
    const leaving = aPlayer();
    const staying = aPlayer();
    coordinator.register(leaving);
    coordinator.register(staying);
    report(staying.element, 0.7);
    report(leaving.element, 1);

    coordinator.unregister(leaving);

    expect(leaving.stop).toHaveBeenCalledTimes(1);
    expect(staying.start).toHaveBeenCalledTimes(2);
  });
});
```

Note the last test: `staying` starts once at 0.7, is stopped when `leaving` takes over at 1, then starts again — hence 2.

- [ ] **Step 2: Run to verify failure**

Run: `docker compose exec -T frontend npx jest src/app/reader/media/autoplay`
Expected: FAIL — modules not found.

- [ ] **Step 3: Implement**

`autoplay-player.ts`:

```ts
/** A list video the coordinator can start and stop; `element` is what its visibility is measured on. */
export interface AutoplayPlayer {
  readonly element: Element;
  start(withSound: boolean): void;
  stop(): void;
}
```

`list-autoplay-coordinator.ts`:

```ts
import { DestroyRef, Injectable, computed, effect, inject, signal, untracked } from '@angular/core';
import { ListAutoplayService } from '../../../core/preferences/list-autoplay.service';
import { AutoplayPlayer } from './autoplay-player';

const VISIBLE_ENOUGH = 0.6;
const THRESHOLDS = [0, 0.2, 0.4, 0.6, 0.8, 1];

/** Plays the list's most visible video, one at a time. Provided by the entry list, so nothing outside it autoplays. */
@Injectable()
export class ListAutoplayCoordinator {
  private readonly setting = inject(ListAutoplayService);
  private readonly suspended = signal(false);
  private readonly active = computed(() => this.setting.enabled() && !this.suspended());
  private readonly players = new Map<Element, AutoplayPlayer>();
  private readonly visibility = new Map<Element, number>();
  private readonly observer =
    typeof IntersectionObserver === 'undefined'
      ? null
      : new IntersectionObserver((changes) => this.measure(changes), { threshold: THRESHOLDS });
  private current: AutoplayPlayer | null = null;
  private soundOn = false;

  constructor() {
    effect(() => {
      const active = this.active();
      untracked(() => (active ? this.choose() : this.stopCurrent()));
    });
    inject(DestroyRef).onDestroy(() => this.observer?.disconnect());
  }

  register(player: AutoplayPlayer): void {
    this.players.set(player.element, player);
    this.observer?.observe(player.element);
  }

  unregister(player: AutoplayPlayer): void {
    this.players.delete(player.element);
    this.visibility.delete(player.element);
    this.observer?.unobserve(player.element);
    if (this.current !== player) return;
    this.stopCurrent();
    this.choose();
  }

  nowPlaying(player: AutoplayPlayer): void {
    if (this.current === player) return;
    this.current?.stop();
    this.current = player;
  }

  soundChanged(soundOn: boolean): void {
    this.soundOn = soundOn;
  }

  suspend(): void {
    this.suspended.set(true);
  }

  resume(): void {
    this.suspended.set(false);
  }

  private measure(changes: IntersectionObserverEntry[]): void {
    for (const change of changes) this.visibility.set(change.target, change.intersectionRatio);
    this.choose();
  }

  private choose(): void {
    if (!this.active()) return;
    const candidate = this.mostVisible();
    if (candidate === this.current) return;
    this.stopCurrent();
    this.current = candidate;
    candidate?.start(this.soundOn);
  }

  private mostVisible(): AutoplayPlayer | null {
    let best: AutoplayPlayer | null = null;
    let bestRatio = 0;
    for (const [element, ratio] of this.visibility) {
      const player = this.players.get(element);
      if (!player || ratio < VISIBLE_ENOUGH || ratio <= bestRatio) continue;
      best = player;
      bestRatio = ratio;
    }
    return best;
  }

  private stopCurrent(): void {
    this.current?.stop();
    this.current = null;
  }
}
```

`choose()` reads `active()` outside the effect too (from `measure`); that is a plain signal read and is intended.

- [ ] **Step 4: Run to verify pass**

Run: `docker compose exec -T frontend npx jest src/app/reader/media/autoplay`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add frontend/src/app/reader/media/autoplay
git commit -m "feat(#1641): coordinator plays the most visible list video"
```

---

### Task 4: Native video player

**Files:**
- Create: `frontend/src/app/reader/media/autoplay/video-autoplay-player.ts`
- Test: `frontend/src/app/reader/media/autoplay/video-autoplay-player.spec.ts`

**Interfaces:**
- Consumes: `AutoplayPlayer`, `ListAutoplayCoordinator.nowPlaying`, `.soundChanged` (Task 3).
- Produces: `new VideoAutoplayPlayer(video: HTMLVideoElement, coordinator: Pick<ListAutoplayCoordinator, 'nowPlaying' | 'soundChanged'>)`.

- [ ] **Step 1: Write the failing test**

```ts
import { VideoAutoplayPlayer } from './video-autoplay-player';

const refusal = () => new DOMException('autoplay with sound refused', 'NotAllowedError');
const settle = () => new Promise((resolve) => setTimeout(resolve));

function setUp() {
  const video = document.createElement('video');
  const play = jest.spyOn(video, 'play').mockResolvedValue(undefined);
  const pause = jest.spyOn(video, 'pause').mockImplementation(() => undefined);
  const coordinator = { nowPlaying: jest.fn(), soundChanged: jest.fn() };
  const player = new VideoAutoplayPlayer(video, coordinator);
  return { video, play, pause, coordinator, player };
}

describe('VideoAutoplayPlayer', () => {
  it('starts muted unless sound is on', () => {
    const { video, play, player } = setUp();

    player.start(false);
    expect(video.muted).toBe(true);

    player.start(true);
    expect(video.muted).toBe(false);
    expect(play).toHaveBeenCalledTimes(2);
  });

  it('falls back to muted when the browser refuses sound', async () => {
    const { video, play, player } = setUp();
    play.mockRejectedValueOnce(refusal());

    player.start(true);
    await settle();

    expect(video.muted).toBe(true);
    expect(play).toHaveBeenCalledTimes(2);
  });

  it('leaves the poster when even a muted start is refused', async () => {
    const { play, player } = setUp();
    play.mockRejectedValue(refusal());

    player.start(false);
    await settle();

    expect(play).toHaveBeenCalledTimes(1);
  });

  it('does not retry muted when a start with sound fails for another reason', async () => {
    const { video, play, player } = setUp();
    play.mockRejectedValueOnce(new DOMException('source swapped', 'AbortError'));

    player.start(true);
    await settle();

    expect(video.muted).toBe(false);
    expect(play).toHaveBeenCalledTimes(1);
  });

  it('pauses when stopped', () => {
    const { pause, player } = setUp();
    player.stop();
    expect(pause).toHaveBeenCalledTimes(1);
  });

  it('tells the coordinator whenever it plays', () => {
    const { video, coordinator, player } = setUp();
    video.dispatchEvent(new Event('play'));
    expect(coordinator.nowPlaying).toHaveBeenCalledWith(player);
  });

  it('reports the user unmuting and muting, but not its own muted start', () => {
    const { video, coordinator, player } = setUp();

    player.start(false);
    video.dispatchEvent(new Event('volumechange'));
    expect(coordinator.soundChanged).not.toHaveBeenCalled();

    video.muted = false;
    video.dispatchEvent(new Event('volumechange'));
    expect(coordinator.soundChanged).toHaveBeenLastCalledWith(true);

    video.muted = true;
    video.dispatchEvent(new Event('volumechange'));
    expect(coordinator.soundChanged).toHaveBeenLastCalledWith(false);
  });
});
```

`video.muted` defaults to `false` in jsdom, so `start(false)` changes it and arms the "own change" skip; the user changes after that are reported. If jsdom itself queues a `volumechange` when `muted` is set, the hand-dispatched events above double up: check with a one-line probe first, and if it does, `await settle()` after each change instead of dispatching.

- [ ] **Step 2: Run to verify failure**

Run: `docker compose exec -T frontend npx jest src/app/reader/media/autoplay/video-autoplay-player.spec.ts`
Expected: FAIL — module not found.

- [ ] **Step 3: Implement**

```ts
import { AutoplayPlayer } from './autoplay-player';
import { ListAutoplayCoordinator } from './list-autoplay-coordinator';

type Coordinator = Pick<ListAutoplayCoordinator, 'nowPlaying' | 'soundChanged'>;

export class VideoAutoplayPlayer implements AutoplayPlayer {
  /** The muted state this player just set itself, whose `volumechange` is not the user's. */
  private ownMuted: boolean | null = null;

  constructor(
    readonly element: HTMLVideoElement,
    private readonly coordinator: Coordinator,
  ) {
    element.addEventListener('play', () => coordinator.nowPlaying(this));
    element.addEventListener('volumechange', () => this.reportSound());
  }

  start(withSound: boolean): void {
    this.setMuted(!withSound);
    this.element.play().catch((error: unknown) => {
      if (withSound && isRefusal(error)) this.start(false);
    });
  }

  stop(): void {
    this.element.pause();
  }

  private setMuted(muted: boolean): void {
    if (this.element.muted === muted) return;
    this.ownMuted = muted;
    this.element.muted = muted;
  }

  private reportSound(): void {
    const own = this.ownMuted === this.element.muted;
    this.ownMuted = null;
    if (!own) this.coordinator.soundChanged(!this.element.muted);
  }
}

/** Only a refused autoplay earns the muted retry; an HLS source swap aborts the first play too. */
function isRefusal(error: unknown): boolean {
  return error instanceof DOMException && error.name === 'NotAllowedError';
}
```

- [ ] **Step 4: Run to verify pass**

Run: `docker compose exec -T frontend npx jest src/app/reader/media/autoplay`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add frontend/src/app/reader/media/autoplay
git commit -m "feat(#1641): native video autoplay with a muted fallback"
```

---

### Task 5: Post media registers its videos

**Files:**
- Modify: `frontend/src/app/reader/media/post-media/post-media.component.ts`
- Test: `frontend/src/app/reader/media/post-media/post-media.component.spec.ts`

**Interfaces:**
- Consumes: `ListAutoplayCoordinator.register/unregister` (Task 3), `VideoAutoplayPlayer` (Task 4).

- [ ] **Step 1: Write the failing tests** (append to the spec; add imports for `ListAutoplayCoordinator` and `VideoAutoplayPlayer`)

```ts
describe('PostMediaComponent in the list', () => {
  const coordinator = { register: jest.fn(), unregister: jest.fn(), nowPlaying: jest.fn(), soundChanged: jest.fn() };

  beforeEach(() => Object.values(coordinator).forEach((spy) => spy.mockReset()));

  async function mountInList(media: EntryMediumDto[]) {
    TestBed.configureTestingModule({
      imports: [HostComponent],
      providers: [{ provide: ListAutoplayCoordinator, useValue: coordinator }],
    });
    const fixture = TestBed.createComponent(HostComponent);
    fixture.componentInstance.media = media;
    fixture.detectChanges();
    await fixture.whenStable();
    return fixture;
  }

  it('registers each video for autoplay', async () => {
    const fixture = await mountInList([clip, image(2)]);
    const video = fixture.nativeElement.querySelector('video');

    expect(coordinator.register).toHaveBeenCalledTimes(1);
    const player = coordinator.register.mock.calls[0][0] as VideoAutoplayPlayer;
    expect(player).toBeInstanceOf(VideoAutoplayPlayer);
    expect(player.element).toBe(video);
  });

  it('unregisters its videos when destroyed', async () => {
    const fixture = await mountInList([clip]);
    const player = coordinator.register.mock.calls[0][0];

    fixture.destroy();

    expect(coordinator.unregister).toHaveBeenCalledWith(player);
  });

  it('unregisters a video a re-render removed', async () => {
    const fixture = await mountInList([clip]);
    const player = coordinator.register.mock.calls[0][0];

    fixture.componentInstance.media = [image(1)];
    fixture.changeDetectorRef.markForCheck();
    fixture.detectChanges();
    await fixture.whenStable();

    expect(coordinator.unregister).toHaveBeenCalledWith(player);
  });
});
```

The existing `describe('PostMediaComponent')` tests mount without a coordinator and must keep passing unchanged — that is the article-view case. If `HostComponent` is `OnPush`-less and the re-render test still sees the old media, use `fixture.componentRef.changeDetectorRef.detectChanges()` instead; check how the existing spec re-renders before adding a new mechanism.

- [ ] **Step 2: Run to verify failure**

Run: `docker compose exec -T frontend npx jest src/app/reader/media/post-media`
Expected: FAIL — `register` not called.

- [ ] **Step 3: Implement**

In `post-media.component.ts` add imports:

```ts
import { ListAutoplayCoordinator } from '../autoplay/list-autoplay-coordinator';
import { VideoAutoplayPlayer } from '../autoplay/video-autoplay-player';
```

Fields:

```ts
  private readonly autoplay = inject(ListAutoplayCoordinator, { optional: true });
  private readonly players = new Map<HTMLVideoElement, VideoAutoplayPlayer>();
```

In the `afterRenderEffect`, after `this.pauseWhenScrolledAway();`, add `this.registerForAutoplay();`. In `onDestroy`, after `releaseHlsStreams(this.host);`, add `this.unregisterVideosWhere(() => true);`.

Methods:

```ts
  private registerForAutoplay(): void {
    const autoplay = this.autoplay;
    if (autoplay === null) return;
    const videos = new Set(Array.from(this.host.querySelectorAll('video')));
    this.unregisterVideosWhere((video) => !videos.has(video));
    for (const video of videos) {
      if (this.players.has(video)) continue;
      const player = new VideoAutoplayPlayer(video, autoplay);
      this.players.set(video, player);
      autoplay.register(player);
    }
  }

  private unregisterVideosWhere(shouldUnregister: (video: HTMLVideoElement) => boolean): void {
    for (const [video, player] of this.players) {
      if (!shouldUnregister(video)) continue;
      this.autoplay?.unregister(player);
      this.players.delete(video);
    }
  }
```

- [ ] **Step 4: Run to verify pass**

Run: `docker compose exec -T frontend npx jest src/app/reader/media/post-media src/app/reader/quoted-post`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add frontend/src/app/reader/media/post-media
git commit -m "feat(#1641): list post videos register for autoplay"
```

---

### Task 6: Shorts register for autoplay

**Files:**
- Modify: `frontend/src/app/reader/list/magazine/blocks/entry-media/short-embed.ts`
- Modify: `frontend/src/app/reader/list/magazine/blocks/entry-media/entry-media.component.ts`
- Modify: `frontend/src/app/reader/list/magazine/blocks/entry-media/entry-media.component.html`
- Test: `short-embed.spec.ts`, `entry-media.component.spec.ts` (same folder)

**Interfaces:**
- Consumes: `AutoplayPlayer`, `ListAutoplayCoordinator.register/unregister/nowPlaying` (Task 3).
- Produces: `mutedShortPlayer(player: string): string`.

- [ ] **Step 1: Write the failing tests**

`short-embed.spec.ts` (import `mutedShortPlayer`):

```ts
describe('mutedShortPlayer', () => {
  it('mutes a Short player and keeps it inline, for starting without a tap', () => {
    expect(
      mutedShortPlayer('https://www.youtube-nocookie.com/embed/87Ov3cS-xMs?autoplay=1#shorts'),
    ).toBe(
      'https://www.youtube-nocookie.com/embed/87Ov3cS-xMs?autoplay=1&mute=1&playsinline=1#shorts',
    );
  });
});
```

`entry-media.component.spec.ts` — add a `describe` (import `ListAutoplayCoordinator` from `'../../../../media/autoplay/list-autoplay-coordinator'` and `AutoplayPlayer` from `'../../../../media/autoplay/autoplay-player'`):

```ts
describe('EntryMediaComponent in an autoplaying list', () => {
  const coordinator = { register: jest.fn(), unregister: jest.fn(), nowPlaying: jest.fn(), soundChanged: jest.fn() };

  beforeEach(() => Object.values(coordinator).forEach((spy) => spy.mockReset()));

  async function mountInList(testEntry: EntryDto) {
    TestBed.configureTestingModule({
      imports: [EntryMediaComponent, provideTranslocoTesting()],
      providers: [
        { provide: EntryActionHandler, useValue: entryActions },
        { provide: ListAutoplayCoordinator, useValue: coordinator },
        provideRouter([]),
      ],
    });
    const fixture = TestBed.createComponent(EntryMediaComponent);
    fixture.componentRef.setInput('entry', testEntry);
    fixture.detectChanges();
    await fixture.whenStable();
    return fixture;
  }

  const registered = () => coordinator.register.mock.calls[0][0] as AutoplayPlayer;

  it('registers its Short, measured on the player box', async () => {
    const fixture = await mountInList(short());

    expect(coordinator.register).toHaveBeenCalledTimes(1);
    expect(registered().element).toBe(fixture.nativeElement.querySelector('.short-player'));
  });

  it('plays the Short muted when the list starts it, and puts it away when stopped', async () => {
    const fixture = await mountInList(short());

    registered().start(true);
    fixture.detectChanges();
    await fixture.whenStable();
    expect(fixture.nativeElement.querySelector('iframe').getAttribute('src')).toBe(
      'https://www.youtube-nocookie.com/embed/87Ov3cS-xMs?autoplay=1&mute=1&playsinline=1#shorts',
    );

    registered().stop();
    fixture.detectChanges();
    await fixture.whenStable();
    expect(fixture.nativeElement.querySelector('iframe')).toBeNull();
  });

  it('tells the list a tapped Short is playing, with sound', async () => {
    const fixture = await mountInList(short());

    fixture.nativeElement.querySelector('button.play').click();
    fixture.detectChanges();
    await fixture.whenStable();

    expect(coordinator.nowPlaying).toHaveBeenCalledWith(registered());
    expect(fixture.nativeElement.querySelector('iframe').getAttribute('src')).toBe(
      'https://www.youtube-nocookie.com/embed/87Ov3cS-xMs?autoplay=1#shorts',
    );
  });

  it('unregisters its Short when destroyed', async () => {
    const fixture = await mountInList(short());
    const player = registered();

    fixture.destroy();

    expect(coordinator.unregister).toHaveBeenCalledWith(player);
  });

  it('registers nothing for a share without a Short', async () => {
    await mountInList(entry());
    expect(coordinator.register).not.toHaveBeenCalled();
  });
});
```

Note: `entry()` with `media: [image(1)]` renders `app-post-media` without a video, so post-media registers nothing either.

- [ ] **Step 2: Run to verify failure**

Run: `docker compose exec -T frontend npx jest src/app/reader/list/magazine/blocks/entry-media`
Expected: FAIL — `mutedShortPlayer` missing; `register` not called.

- [ ] **Step 3: Implement**

`short-embed.ts`, append:

```ts
/** The player started without a tap: browsers only allow that muted, and iOS only inline. */
export function mutedShortPlayer(player: string): string {
  const muted = new URL(player);
  muted.searchParams.set('mute', '1');
  muted.searchParams.set('playsinline', '1');
  return muted.toString();
}
```

`entry-media.component.html` — replace `@if (playingShort()) {` and the frame's binding:

```html
        @if (playingShort(); as playing) {
          <div
            class="item frame"
            [appPlayingEmbed]="playing"
            [embedTitle]="headline() || ('reader.playShort' | transloco)"
          ></div>
        } @else {
```

`entry-media.component.ts`:
- imports: `import { autoplayingShortPlayer, mutedShortPlayer } from './short-embed';`, `import { AutoplayPlayer } from '../../../../media/autoplay/autoplay-player';`, `import { ListAutoplayCoordinator } from '../../../../media/autoplay/list-autoplay-coordinator';`
- fields:

```ts
  private readonly autoplay = inject(ListAutoplayCoordinator, { optional: true });
  private shortAutoplay?: AutoplayPlayer;
  protected readonly playingShort = signal<string | null>(null);
```

- `afterRenderEffect` body: add `this.registerShortForAutoplay();` after `this.pauseWhenScrolledAway();`.
- `onDestroy`: add `if (this.shortAutoplay) this.autoplay?.unregister(this.shortAutoplay);`.
- Methods:

```ts
  private registerShortForAutoplay(): void {
    const element = this.host.querySelector('.short-player');
    if (this.autoplay === null || element === null || this.shortAutoplay) return;
    this.shortAutoplay = {
      element,
      start: () => this.startShort(mutedShortPlayer),
      stop: () => this.stopShort(),
    };
    this.autoplay.register(this.shortAutoplay);
  }

  protected playShort(event: Event): void {
    event.stopPropagation();
    this.startShort((player) => player);
    if (this.shortAutoplay) this.autoplay?.nowPlaying(this.shortAutoplay);
  }

  protected stopShort(): void {
    this.playingShort.set(null);
  }

  private startShort(variant: (player: string) => string): void {
    const player = this.shortPlayer();
    if (player !== null) this.playingShort.set(variant(player));
  }
```

(`playShort` and `stopShort` replace the existing ones.)

- [ ] **Step 4: Run to verify pass**

Run: `docker compose exec -T frontend npx jest src/app/reader/list/magazine/blocks`
Expected: PASS, including every existing Short test.

- [ ] **Step 5: Commit**

```bash
git add frontend/src/app/reader/list/magazine/blocks/entry-media
git commit -m "feat(#1641): shorts autoplay muted in the list"
```

---

### Task 7: The list provides the coordinator

**Files:**
- Modify: `frontend/src/app/reader/list/entry-list/entry-list.component.ts`
- Test: `frontend/src/app/reader/list/entry-list/entry-list.component.spec.ts`

**Interfaces:**
- Consumes: `ListAutoplayCoordinator.suspend/resume` (Task 3).

- [ ] **Step 1: Write the failing test**

Add directly after the existing `it('pauses every video in the list when an entry opens', …)` (it uses the spec's `mount(over, providers)` helper and `setInput('openEntryId', …)`):

```ts
  it('suspends list autoplay while an entry is open and resumes it once closed', () => {
    const fixture = mount();
    const coordinator = fixture.debugElement.injector.get(ListAutoplayCoordinator);
    const suspend = jest.spyOn(coordinator, 'suspend');
    const resume = jest.spyOn(coordinator, 'resume');

    fixture.componentRef.setInput('openEntryId', 2);
    fixture.detectChanges();
    expect(suspend).toHaveBeenCalledTimes(1);

    fixture.componentRef.setInput('openEntryId', null);
    fixture.detectChanges();
    expect(resume).toHaveBeenCalledTimes(1);
  });
```

Import `ListAutoplayCoordinator` from `'../../media/autoplay/list-autoplay-coordinator'`.

- [ ] **Step 2: Run to verify failure**

Run: `docker compose exec -T frontend npx jest src/app/reader/list/entry-list/entry-list.component.spec.ts -t "autoplay"`
Expected: FAIL — no provider for `ListAutoplayCoordinator`.

- [ ] **Step 3: Implement**

In `@Component({...})` of `EntryListComponent` add `providers: [ListAutoplayCoordinator],` and the import. Add the field `private readonly autoplay = inject(ListAutoplayCoordinator);` next to `host`, and replace `_pauseMediaOfOpenEntry`:

```ts
  private readonly _pauseMediaOfOpenEntry = effect(() => {
    if (this.openEntryId() === null) {
      this.autoplay.resume();
      return;
    }
    this.autoplay.suspend();
    pauseMedia(this.host.nativeElement);
  });
```

- [ ] **Step 4: Run the whole list spec**

Run: `docker compose exec -T frontend npx jest src/app/reader/list`
Expected: PASS. If unrelated list tests now fail on a missing `AccountIdentity` dependency (pulled in by `ListAutoplayService → UserDeviceStorage`), add `provideAccountIdentity()` from `src/testing/account-identity-testing` to the spec's shared providers.

- [ ] **Step 5: Commit**

```bash
git add frontend/src/app/reader/list/entry-list
git commit -m "feat(#1641): the entry list drives autoplay and holds it while reading"
```

---

### Task 8: Playwright smoke

**Files:**
- Create: `frontend/e2e/list-video-autoplay.spec.ts`

Playwright's Chromium has no H.264, and the spec must not depend on a media file, so it replaces `HTMLMediaElement.prototype.play` with a recorder. That proves the wiring (visible list video → muted `play()`), which is what this feature adds.

- [ ] **Step 1: Write the spec**

Read `frontend/e2e/magazine-kicker-one-line.spec.ts` and `frontend/e2e/support/reader.ts` first; reuse `signInAsAdmin`, `entryWire`, and the `/api/entries` stub shape. Then:

```ts
import { test, expect, Page } from '@playwright/test';
import { signInAsAdmin } from './support/auth';
import { entryWire } from './support/reader';

const CLIP = 'https://fixtures.invalid/clip.mp4';

const ENTRIES = [
  entryWire({
    id: 1,
    url: 'https://bsky.app/profile/someone/post/1',
    title: 'A donkey at sunrise',
    titleDerived: true,
    share: 'media',
    excerpt: 'A donkey at sunrise',
    media: [{ url: CLIP, kind: 'video', width: 640, height: 360 }],
  }),
];

async function recordPlays(page: Page): Promise<void> {
  await page.addInitScript(() => {
    const plays: { src: string; muted: boolean }[] = [];
    (window as unknown as { plays: typeof plays }).plays = plays;
    HTMLMediaElement.prototype.play = function (this: HTMLMediaElement) {
      plays.push({ src: this.currentSrc || this.src, muted: this.muted });
      return Promise.resolve();
    };
  });
}

async function stubEntries(page: Page): Promise<void> {
  await page.route(
    (url) => url.pathname === '/api/entries',
    async (route) => {
      if (route.request().method() !== 'GET') return route.fallback();
      await route.fulfill({ status: 200, json: { entries: ENTRIES, nextCursor: null } });
    },
  );
}

const plays = (page: Page) =>
  page.evaluate(() => (window as unknown as { plays: { src: string; muted: boolean }[] }).plays);

test('a visible list video starts on its own, muted', async ({ page }) => {
  await recordPlays(page);
  await stubEntries(page);
  test.skip(!(await signInAsAdmin(page)), 'no admin account on this stack');

  await expect(page.locator('app-entry-media video')).toBeVisible();
  await expect.poll(() => plays(page)).toEqual([{ src: CLIP, muted: true }]);
});

test('nothing starts once autoplay is switched off', async ({ page }) => {
  await recordPlays(page);
  await stubEntries(page);
  test.skip(!(await signInAsAdmin(page)), 'no admin account on this stack');

  await page.goto('/settings/preferences');
  const toggle = page.locator('#list-autoplay-toggle');
  await expect(toggle).toBeChecked();
  await toggle.click();
  await expect(toggle).not.toBeChecked();

  await page.goto('/');
  await expect(page.locator('app-entry-media video')).toBeVisible();
  await page.waitForTimeout(500);
  expect(await plays(page)).toEqual([]);

  await page.goto('/settings/preferences');
  await page.locator('#list-autoplay-toggle').click();
});
```

Adjust to what `signInAsAdmin` actually returns and how sibling specs skip (copy their idiom exactly); check the real settings route and whether the toggle input is clickable or its label is (copy from an existing settings e2e if one exists: `grep -rln "settings/preferences\|reading-focus-toggle" frontend/e2e`). The last two lines restore the fixture account's setting, as CLAUDE.md requires. If the stubbed entry does not render as `app-entry-media` (a magazine block picks the shape), look at `magazine-slot-fit.ts` `sharedMedia` and adjust the entry fields until it does; the selector stays.

- [ ] **Step 2: Run it against the Docker stack**

Run (from `frontend/`, Docker stack up from this checkout): `npx playwright test e2e/list-video-autoplay.spec.ts`
Expected: 2 passed.

- [ ] **Step 3: Break-test the guard**

Temporarily change `VISIBLE_ENOUGH` to `1.1` in `list-autoplay-coordinator.ts`, re-run the first test, and confirm it FAILS (no play recorded). Restore the line by editing it back (not `git checkout --`), and confirm it passes again.

- [ ] **Step 4: Commit**

```bash
git add frontend/e2e/list-video-autoplay.spec.ts
git commit -m "test(#1641): e2e smoke for list video autoplay"
```

---

### Task 9: Gate, real-device check, PR

- [ ] **Step 1: Full frontend gate**

Run: `docker compose exec -T frontend npm run check`
Expected: ESLint, Prettier, Stylelint, tsc and Jest all green. Fix anything it reports in the files this branch touched.

- [ ] **Step 2: Real-browser check**

On the dev stack (`https://localhost:8443`) in the built-in browser at Mobile viewport: scroll a list with a Bluesky video and a Short; confirm one plays at a time, muted; unmute a native one, scroll to the next native one and confirm it starts with sound; open an entry and confirm the list's video stops; switch the setting off and confirm nothing starts. Note in the PR that iOS Safari behaviour (Low Power Mode, YouTube `mute=1` inline) needs a check on a real iPhone against a prod build, since the simulator does not reproduce it.

- [ ] **Step 3: Push and open the PR into `develop`**

```bash
git push -u origin feature/1641-list-video-autoplay
gh pr create --base develop --title "feat(#1641): autoplay list videos muted, with a per-device setting" --body "Closes #1641

- Per-device setting (Settings → Preferences), on by default, off by default under reduced motion.
- The entry list's coordinator plays the most visible video (≥ 60 % in view), one at a time, and holds autoplay while an entry is open.
- Unmuting a native video carries sound to the following native autoplays for the session; a refused start with sound falls back to muted.
- Shorts always autoplay muted (\`mute=1&playsinline=1\`); a tapped Short keeps sound.
- Wi-Fi-only is deferred (Network Information API is Chrome-Android-only)."
```
