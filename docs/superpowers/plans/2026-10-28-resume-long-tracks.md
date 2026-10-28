# Resume Long Tracks Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Remember where the listener stopped in a track longer than 15 minutes, and on the next Listen click offer to continue from there or start over.

**Architecture:** A per-account, device-local store (`ListenPositions`, on top of the existing `UserDeviceStorage`) holds up to 100 positions. `AudioPlayerService` gets a `ListenPositionRecorder` that writes to that store when playback pauses, the track switches, the page hides, the player closes, or every ~5 s while playing. It also forgets a track once it ends. A new `ListenLauncher` becomes the single Listen entry point for the article listen row, the list rows and embedded players. It opens a `ResumeDialogComponent` when a saved position exists and the track isn't already loaded.

**Tech Stack:** Angular 20 standalone components and signals, CDK `Dialog`, Transloco, Jest (jsdom, run in the Docker `frontend` container).

**Spec:** GitHub issue #1866 (the agreed design is in the issue body; read it first).

## Global Constraints

- A track is "long" when its duration is **> 900 s** (15 min). The duration is the player's `duration()`: the feed-declared length until metadata loads, then the real one. A track whose duration is 0 or unknown is never remembered.
- Never save or offer a position **< 15 s**. Forget the saved position when the track **ends**, or stops **within its last 15 s**.
- Save every **5 s** while playing (throttled), plus immediately on pause, track switch, page hide and player close.
- Storage: `UserDeviceStorage` name `listen-positions`, i.e. `localStorage` key `sfr.user.<userId>.listen-positions`. This replaces the issue's `sfr.audio.positions.<userId>`, because the per-user storage helper already exists and owns that key scheme. Keep the **100** most recently updated positions per user, keyed by track `url`. Nothing is read or written while the user id is unknown, and `UserDeviceStorage` already guarantees that.
- The dialog opens only from **Listen**: the article listen row, list-row play, and embed Listen. It never opens from add-to-playlist, a playlist row, next/previous or auto-advance, and never for the track already loaded in the player.
- Dialog: heading = track title. Text: en "You stopped at {{ position }} of {{ duration }}." / de "Du hast bei {{ position }} von {{ duration }} aufgehört.". Buttons: en **Continue** / **From start**, de **Fortsetzen** / **Von vorn**. Esc or a backdrop click plays nothing.
- **The dialog is a card on phones, not a bottom sheet.** This deviates from the issue text: `OverlayPanelComponent`'s house rule is that a short dialog stays a card on a phone, and `ConfirmDialogComponent` does the same.
- Frontend conventions (CLAUDE.md): standalone components, signals, styles in a sibling `.scss`, no hex colours or ad-hoc `px` spacing outside the theme. Comments only when a reader would get the code wrong without them, one line each.
- Commits: `type(#1866): …`, no Claude attribution.
- Run Jest inside Docker: `docker compose exec -T frontend npx jest <paths>` from the repo root. Never run two Jest runs at once (the container OOMs).

## File Structure

| File | Responsibility |
|---|---|
| Create `frontend/src/app/reader/audio/listen-positions.ts` | Per-account store of saved positions (get / remember / forget, newest first, cap 100) |
| Create `frontend/src/app/reader/audio/listen-position-recorder.ts` | Decides what a snapshot means (remember / forget / ignore) and when to write (now, throttled, page hide) |
| Modify `frontend/src/app/reader/audio-player.service.ts` | Owns a recorder and calls it from its event handlers |
| Create `frontend/src/app/reader/audio/resume-dialog/resume-dialog.component.{ts,html,scss}` | The Continue / From start dialog |
| Create `frontend/src/app/reader/audio/listen-launcher.ts` | The Listen entry point: plays at once, or asks first |
| Modify `frontend/src/app/reader/audio/entry-audio.ts`, `frontend/src/app/reader/article/reader-view/article-embed-audio.ts` and their call sites | Route Listen through the launcher |
| Create `frontend/src/testing/stub-listen-launcher.ts` | A launcher that starts the track on `StubAudioPlayer` at once |
| Modify `frontend/public/i18n/en.json`, `frontend/public/i18n/de.json` | `reader.audioPlayer.resume.*` |

`UserDeviceStorage.write` bumps one revision signal that every storage-reading `computed` depends on, so writing every 5 s re-runs those computeds. That was checked: `ListOrderService.oldestFirstViews` has an `equal` function, and `UnreadFilterService.unreadOnly` and `ListAutoplayService.enabled` return primitives. Nothing downstream re-fires.

---

### Task 1: `ListenPositions` store

**Files:**
- Create: `frontend/src/app/reader/audio/listen-positions.ts`
- Test: `frontend/src/app/reader/audio/listen-positions.spec.ts`

**Interfaces:**
- Consumes: `UserDeviceStorage` (`read(name): string | null`, `write(name, value: string | null)`), `provideAccountIdentity(userId)` from `frontend/src/testing/account-identity-testing.ts`.
- Produces:
  ```ts
  export interface SavedPosition { url: string; seconds: number; durationSeconds: number }
  export const KEPT_POSITIONS = 100;
  @Injectable({ providedIn: 'root' }) export class ListenPositions {
    get(url: string): SavedPosition | null;
    remember(position: SavedPosition): void;
    forget(url: string): void;
  }
  ```

- [ ] **Step 1: Write the failing test**

```ts
import { WritableSignal, signal } from '@angular/core';
import { TestBed } from '@angular/core/testing';
import { provideAccountIdentity } from '../../../testing/account-identity-testing';
import { KEPT_POSITIONS, ListenPositions, SavedPosition } from './listen-positions';

const KEY = 'sfr.user.7.listen-positions';

function at(url: string, seconds = 600): SavedPosition {
  return { url, seconds, durationSeconds: 3600 };
}

describe('ListenPositions', () => {
  let userId: WritableSignal<number | null>;
  let positions: ListenPositions;

  beforeEach(() => {
    localStorage.clear();
    userId = signal<number | null>(7);
    TestBed.configureTestingModule({ providers: [provideAccountIdentity(userId)] });
    positions = TestBed.inject(ListenPositions);
  });

  it('remembers a position under the signed-in user', () => {
    positions.remember(at('https://x.test/a.mp3'));

    expect(positions.get('https://x.test/a.mp3')).toEqual(at('https://x.test/a.mp3'));
    expect(JSON.parse(localStorage.getItem(KEY) ?? '[]')).toEqual([at('https://x.test/a.mp3')]);
  });

  it('has nothing for a track never remembered', () => {
    expect(positions.get('https://x.test/a.mp3')).toBeNull();
  });

  it('replaces the earlier position of the same track', () => {
    positions.remember(at('https://x.test/a.mp3', 600));
    positions.remember(at('https://x.test/a.mp3', 900));

    expect(positions.get('https://x.test/a.mp3')?.seconds).toBe(900);
    expect(JSON.parse(localStorage.getItem(KEY) ?? '[]')).toHaveLength(1);
  });

  it('forgets a track', () => {
    positions.remember(at('https://x.test/a.mp3'));
    positions.remember(at('https://x.test/b.mp3'));

    positions.forget('https://x.test/a.mp3');

    expect(positions.get('https://x.test/a.mp3')).toBeNull();
    expect(positions.get('https://x.test/b.mp3')).not.toBeNull();
  });

  it('keeps only the most recently remembered positions', () => {
    for (let index = 0; index <= KEPT_POSITIONS; index++) {
      positions.remember(at(`https://x.test/${index}.mp3`));
    }

    expect(positions.get('https://x.test/0.mp3')).toBeNull();
    expect(positions.get(`https://x.test/${KEPT_POSITIONS}.mp3`)).not.toBeNull();
    expect(JSON.parse(localStorage.getItem(KEY) ?? '[]')).toHaveLength(KEPT_POSITIONS);
  });

  it('a re-remembered track counts as recent again', () => {
    for (let index = 0; index < KEPT_POSITIONS; index++) {
      positions.remember(at(`https://x.test/${index}.mp3`));
    }
    positions.remember(at('https://x.test/0.mp3'));
    positions.remember(at('https://x.test/new.mp3'));

    expect(positions.get('https://x.test/0.mp3')).not.toBeNull();
    expect(positions.get('https://x.test/1.mp3')).toBeNull();
  });

  it("shows one user nothing of another's", () => {
    positions.remember(at('https://x.test/a.mp3'));
    userId.set(8);

    expect(positions.get('https://x.test/a.mp3')).toBeNull();
  });

  it('reads a corrupt or foreign value as empty', () => {
    localStorage.setItem(KEY, '{not json');
    expect(positions.get('https://x.test/a.mp3')).toBeNull();

    localStorage.setItem(KEY, JSON.stringify([{ url: 'https://x.test/a.mp3' }, 'junk']));
    expect(positions.get('https://x.test/a.mp3')).toBeNull();
  });
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `docker compose exec -T frontend npx jest src/app/reader/audio/listen-positions.spec.ts`
Expected: FAIL, "Cannot find module './listen-positions'"

- [ ] **Step 3: Write minimal implementation**

```ts
import { Injectable, inject } from '@angular/core';
import { UserDeviceStorage } from '../../core/preferences/user-device-storage';

export interface SavedPosition {
  url: string;
  seconds: number;
  durationSeconds: number;
}

export const KEPT_POSITIONS = 100;

const NAME = 'listen-positions';

/** Where each long track was left, per account on this device, newest first (#1866). */
@Injectable({ providedIn: 'root' })
export class ListenPositions {
  private readonly storage = inject(UserDeviceStorage);

  get(url: string): SavedPosition | null {
    return this.all().find((saved) => saved.url === url) ?? null;
  }

  remember(position: SavedPosition): void {
    this.write([position, ...this.without(position.url)].slice(0, KEPT_POSITIONS));
  }

  forget(url: string): void {
    if (this.get(url)) this.write(this.without(url));
  }

  private without(url: string): SavedPosition[] {
    return this.all().filter((saved) => saved.url !== url);
  }

  private all(): SavedPosition[] {
    try {
      const stored: unknown = JSON.parse(this.storage.read(NAME) ?? '[]');
      return Array.isArray(stored) ? stored.filter(isSavedPosition) : [];
    } catch {
      return [];
    }
  }

  private write(positions: SavedPosition[]): void {
    this.storage.write(NAME, JSON.stringify(positions));
  }
}

function isSavedPosition(value: unknown): value is SavedPosition {
  const saved = value as Partial<SavedPosition> | null;
  return (
    typeof saved?.url === 'string' &&
    typeof saved.seconds === 'number' &&
    typeof saved.durationSeconds === 'number'
  );
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `docker compose exec -T frontend npx jest src/app/reader/audio/listen-positions.spec.ts`
Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add frontend/src/app/reader/audio/listen-positions.ts frontend/src/app/reader/audio/listen-positions.spec.ts
git commit -m "feat(#1866): a per-account store of where long tracks were left"
```

---

### Task 2: `ListenPositionRecorder`

**Files:**
- Create: `frontend/src/app/reader/audio/listen-position-recorder.ts`
- Test: `frontend/src/app/reader/audio/listen-position-recorder.spec.ts`

**Interfaces:**
- Consumes: `ListenPositions`, `SavedPosition` (Task 1).
- Produces:
  ```ts
  export const LONG_TRACK_SECONDS = 15 * 60;
  export const POSITION_MARGIN_SECONDS = 15;
  export class ListenPositionRecorder {
    constructor(positions: ListenPositions, snapshot: () => SavedPosition | null);
    record(): void;
    recordThrottled(): void;
    recordOnPageHide(destroyRef: DestroyRef): void;
  }
  ```
  This mirrors `PlaylistPersistence` ([playlist-persistence.ts](../../../frontend/src/app/reader/audio/playlist-persistence.ts)): a plain class the player builds with `new`, fed by a snapshot closure.

- [ ] **Step 1: Write the failing test**

```ts
import { DestroyRef } from '@angular/core';
import { TestBed } from '@angular/core/testing';
import { provideAccountIdentity } from '../../../testing/account-identity-testing';
import {
  LONG_TRACK_SECONDS,
  ListenPositionRecorder,
  POSITION_MARGIN_SECONDS,
} from './listen-position-recorder';
import { ListenPositions, SavedPosition } from './listen-positions';

const URL = 'https://x.test/ep.mp3';
const HOUR = 3600;

function setUp() {
  TestBed.configureTestingModule({ providers: [provideAccountIdentity()] });
  const positions = TestBed.inject(ListenPositions);
  let snapshot: SavedPosition | null = null;
  const recorder = new ListenPositionRecorder(positions, () => snapshot);
  const at = (seconds: number, durationSeconds = HOUR): void => {
    snapshot = { url: URL, seconds, durationSeconds };
  };
  return { positions, recorder, at, clear: () => (snapshot = null) };
}

describe('ListenPositionRecorder', () => {
  beforeEach(() => localStorage.clear());
  afterEach(() => jest.useRealTimers());

  it('remembers where a long track was left', () => {
    const { positions, recorder, at } = setUp();
    at(600);

    recorder.record();

    expect(positions.get(URL)).toEqual({ url: URL, seconds: 600, durationSeconds: HOUR });
  });

  it('remembers nothing of a track of 15 minutes or less', () => {
    const { positions, recorder, at } = setUp();
    at(600, LONG_TRACK_SECONDS);

    recorder.record();

    expect(positions.get(URL)).toBeNull();
  });

  it('remembers nothing of a track whose length is unknown', () => {
    const { positions, recorder, at } = setUp();
    at(600, 0);

    recorder.record();

    expect(positions.get(URL)).toBeNull();
  });

  it('remembers nothing before the first 15 seconds, and keeps what was saved before', () => {
    const { positions, recorder, at } = setUp();
    at(600);
    recorder.record();
    at(POSITION_MARGIN_SECONDS - 1);

    recorder.record();

    expect(positions.get(URL)?.seconds).toBe(600);
  });

  it('remembers from the 15th second on', () => {
    const { positions, recorder, at } = setUp();
    at(POSITION_MARGIN_SECONDS);

    recorder.record();

    expect(positions.get(URL)?.seconds).toBe(POSITION_MARGIN_SECONDS);
  });

  it('forgets a track stopped within its last 15 seconds', () => {
    const { positions, recorder, at } = setUp();
    at(600);
    recorder.record();
    at(HOUR - POSITION_MARGIN_SECONDS + 1);

    recorder.record();

    expect(positions.get(URL)).toBeNull();
  });

  it('forgets a track that played to its end', () => {
    const { positions, recorder, at } = setUp();
    at(600);
    recorder.record();
    at(HOUR);

    recorder.record();

    expect(positions.get(URL)).toBeNull();
  });

  it('does nothing without a track', () => {
    const { recorder, clear } = setUp();
    clear();

    recorder.record();

    expect(localStorage.length).toBe(0);
  });

  it('records at most every five seconds while throttled', () => {
    jest.useFakeTimers();
    const { positions, recorder, at } = setUp();
    at(600);
    recorder.recordThrottled();
    at(601);
    recorder.recordThrottled();

    expect(positions.get(URL)?.seconds).toBe(600);

    jest.advanceTimersByTime(5000);
    recorder.recordThrottled();

    expect(positions.get(URL)?.seconds).toBe(601);
  });

  it('records as the page hides, until destroyed', () => {
    const { positions, recorder, at } = setUp();
    const onDestroy: (() => void)[] = [];
    recorder.recordOnPageHide({ onDestroy: (callback: () => void) => onDestroy.push(callback) } as unknown as DestroyRef);
    at(600);

    window.dispatchEvent(new Event('pagehide'));
    expect(positions.get(URL)?.seconds).toBe(600);

    onDestroy.forEach((callback) => callback());
    at(700);
    window.dispatchEvent(new Event('pagehide'));
    expect(positions.get(URL)?.seconds).toBe(600);
  });
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `docker compose exec -T frontend npx jest src/app/reader/audio/listen-position-recorder.spec.ts`
Expected: FAIL, "Cannot find module './listen-position-recorder'"

- [ ] **Step 3: Write minimal implementation**

```ts
import { DestroyRef } from '@angular/core';
import { ListenPositions, SavedPosition } from './listen-positions';

/** Only a track longer than this has its place remembered (#1866). */
export const LONG_TRACK_SECONDS = 15 * 60;
/** A place this close to either end is not worth offering. */
export const POSITION_MARGIN_SECONDS = 15;

const SAVE_INTERVAL_MS = 5000;

/** Remembers where a long track was left, and forgets it once the track is finished. */
export class ListenPositionRecorder {
  private lastSaveAt = 0;

  constructor(
    private readonly positions: ListenPositions,
    private readonly snapshot: () => SavedPosition | null,
  ) {}

  record(): void {
    const position = this.snapshot();
    if (!position || position.durationSeconds <= LONG_TRACK_SECONDS) return;
    if (position.durationSeconds - position.seconds < POSITION_MARGIN_SECONDS) {
      return this.positions.forget(position.url);
    }
    if (position.seconds >= POSITION_MARGIN_SECONDS) this.positions.remember(position);
  }

  recordThrottled(): void {
    if (Date.now() - this.lastSaveAt < SAVE_INTERVAL_MS) return;
    this.lastSaveAt = Date.now();
    this.record();
  }

  recordOnPageHide(destroyRef: DestroyRef): void {
    const record = (): void => this.record();
    window.addEventListener('pagehide', record);
    destroyRef.onDestroy(() => window.removeEventListener('pagehide', record));
  }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `docker compose exec -T frontend npx jest src/app/reader/audio/listen-position-recorder.spec.ts`
Expected: PASS. If the throttle test fails because `Date.now()` is 0 under fake timers (so `0 - 0 < 5000` skips the first write), call `jest.setSystemTime(new Date('2026-01-01'))` right after `jest.useFakeTimers()`. Don't change the implementation: `PlaylistPersistence` uses the same throttle.

- [ ] **Step 5: Commit**

```bash
git add frontend/src/app/reader/audio/listen-position-recorder.ts frontend/src/app/reader/audio/listen-position-recorder.spec.ts
git commit -m "feat(#1866): decide when a long track's place is remembered or forgotten"
```

---

### Task 3: The player records positions

**Files:**
- Modify: `frontend/src/app/reader/audio-player.service.ts`
- Test: `frontend/src/app/reader/audio-player.service.spec.ts`
- Modify (providers only, as needed): specs that inject the real `AudioPlayerService` (list in Step 6)

**Interfaces:**
- Consumes: `ListenPositionRecorder`, `ListenPositions`, `SavedPosition` (Tasks 1–2).
- Produces: no new public API. Positions appear in `ListenPositions` as the player plays.

- [ ] **Step 1: Write the failing tests**

In `audio-player.service.spec.ts`, add `provideAccountIdentity()` (import from `'../../testing/account-identity-testing'`) to the `providers` array in `make()`. Import `ListenPositions` from `'./audio/listen-positions'`. Add this `describe` block inside `describe('AudioPlayerService', …)`:

```ts
  describe('remembering where a long track was left (#1866)', () => {
    const LONG = track({ url: 'https://x.test/long.mp3', durationInSeconds: 3600 });
    const OTHER = track({ url: 'https://x.test/other.mp3', durationInSeconds: 3600 });
    const positions = () => TestBed.inject(ListenPositions);

    function playedTo(service: AudioPlayerService, ...seconds: number[]): void {
      service.play(LONG);
      for (const second of seconds) {
        audio.currentTime = second;
        audio.fire('timeupdate');
      }
    }

    it('remembers the place when playback pauses', () => {
      const service = make();
      playedTo(service, 100, 600);

      audio.pause();

      expect(positions().get(LONG.url)).toEqual({
        url: LONG.url,
        seconds: 600,
        durationSeconds: 3600,
      });
    });

    it('remembers nothing of a short track', () => {
      const service = make();
      service.play(track({ durationInSeconds: 600 }));
      audio.currentTime = 300;
      audio.fire('timeupdate');

      audio.pause();

      expect(positions().get(track().url)).toBeNull();
    });

    it('remembers the place of the track another one replaces', () => {
      const service = make();
      playedTo(service, 100, 600);

      service.play(OTHER);

      expect(positions().get(LONG.url)?.seconds).toBe(600);
    });

    it('remembers the place when the player is closed', () => {
      const service = make();
      playedTo(service, 100, 600);
      audio.pause = jest.fn();

      service.stop();

      expect(positions().get(LONG.url)?.seconds).toBe(600);
    });

    it('forgets the place once the track ends', () => {
      const service = make();
      playedTo(service, 100, 600);
      audio.pause();
      audio.currentTime = 3600;

      audio.fire('ended');

      expect(positions().get(LONG.url)).toBeNull();
    });
  });
```

The first `timeupdate` (100 s) always writes, because the throttle starts at 0. The second one (600 s) lands inside the 5 s window and is held back. So "replaces" and "closed" only pass when the track switch and `stop()` record on their own. The "closed" test sets `audio.pause = jest.fn()` because a real `pause` event arrives after `stop()` has cleared the playlist. Without that, the fake's synchronous event would do the recording and hide a missing hook.

- [ ] **Step 2: Run tests to verify they fail**

Run: `docker compose exec -T frontend npx jest src/app/reader/audio-player.service.spec.ts`
Expected: the five new tests FAIL (`get` returns null, or `seconds` is 100). Existing tests stay green.

- [ ] **Step 3: Implement**

In `audio-player.service.ts`:

Imports:
```ts
import { ListenPositionRecorder } from './audio/listen-position-recorder';
import { ListenPositions, type SavedPosition } from './audio/listen-positions';
```

Field, directly after `persistence`:
```ts
  private readonly recorder = new ListenPositionRecorder(inject(ListenPositions), () =>
    this.positionSnapshot(),
  );
```

Deck callbacks: replace `ended: () => this.playNextOrStop(),` with `ended: () => this.onEnded(),`.

Constructor: replace `this.persistence.saveOnPageHide(inject(DestroyRef));` with
```ts
    const destroyRef = inject(DestroyRef);
    this.persistence.saveOnPageHide(destroyRef);
    this.recorder.recordOnPageHide(destroyRef);
```

`stop()`: make `this.recorder.record();` its first line.

`apply()`: record the outgoing track before the playlist changes:
```ts
  private apply(playlist: Playlist): void {
    const track = currentTrack(playlist);
    if (!track) return this.stop();
    const changed = track.url !== this.current()?.url;
    if (changed) this.recorder.record();
    this._playlist.set(playlist);
    if (changed) this.load(track);
    else if (this._duration() === 0) this._duration.set(track.durationInSeconds ?? 0);
    this.persistence.save();
    this.precacheNext();
  }
```

`onTimeUpdate()`: add `this.recorder.recordThrottled();` after `this.persistence.saveThrottled();`.

`onPlaying()`:
```ts
  private onPlaying(playing: boolean): void {
    this._playing.set(playing);
    if (playing) return;
    this.persistence.save();
    this.recorder.record();
  }
```

New private methods (put `onEnded` next to `playNextOrStop`, and `positionSnapshot` next to `snapshot`):
```ts
  private onEnded(): void {
    this._position.set(this.element.currentTime);
    this.recorder.record();
    this.playNextOrStop();
  }

  private positionSnapshot(): SavedPosition | null {
    const track = this.current();
    if (!track) return null;
    return { url: track.url, seconds: this._position(), durationSeconds: this._duration() };
  }
```

- [ ] **Step 4: Run the player spec**

Run: `docker compose exec -T frontend npx jest src/app/reader/audio-player.service.spec.ts`
Expected: PASS, all tests.

- [ ] **Step 5: Run the specs that build the real player**

`AudioPlayerService` now reaches `AccountIdentity` → `AuthService` → `HttpClient`, so any spec that injects the real player without an `AccountIdentity` fails with `NullInjectorError: No provider for HttpClient`.

Run: `docker compose exec -T frontend npx jest src/app/reader`
Expected: some of these fail with that error: `shell/audio-player-bar/audio-player-bar.component.spec.ts`, `shell/audio-player-bar/audio-surface.service.spec.ts`, `shell/audio-player-bar/audio-playlist/audio-playlist.component.spec.ts`, `shell/audio-player-bar/big-player/big-player.component.spec.ts`, `article/reader-view/article-transcript.spec.ts`, `article/reader-view/article-embed-details.spec.ts`, `article/reader-view/article-chapters.spec.ts`, `article/reader-view/article-body.service.spec.ts`, `article/reader-view/reader-view.component.spec.ts`.

- [ ] **Step 6: Provide an identity in each failing spec**

Add `provideAccountIdentity()` (from `frontend/src/testing/account-identity-testing.ts`, with the relative path for that spec) to the `providers` of every spec that failed with the `HttpClient` error, and to no others. If a spec already provides `AccountIdentity`, leave it alone. Rerun `docker compose exec -T frontend npx jest src/app/reader`. Expected: PASS.

- [ ] **Step 7: Commit**

```bash
git add frontend/src
git commit -m "feat(#1866): the player remembers where a long track was left"
```

---

### Task 4: `ResumeDialogComponent`

**Files:**
- Create: `frontend/src/app/reader/audio/resume-dialog/resume-dialog.component.ts`
- Create: `frontend/src/app/reader/audio/resume-dialog/resume-dialog.component.html`
- Create: `frontend/src/app/reader/audio/resume-dialog/resume-dialog.component.scss`
- Modify: `frontend/public/i18n/en.json`, `frontend/public/i18n/de.json` (inside `reader.audioPlayer`, after `"less"`)
- Test: `frontend/src/app/reader/audio/resume-dialog/resume-dialog.component.spec.ts`

**Interfaces:**
- Consumes: `OverlayPanelComponent` (`heading` input; content projected into `[footer]`), `ButtonComponent` (`variant`, `focusInitial`), `formatDuration(seconds): string` from `frontend/src/app/reader/format.ts`.
- Produces:
  ```ts
  export type ResumeChoice = 'continue' | 'fromStart';
  export interface ResumeData { title: string; seconds: number; durationSeconds: number }
  export class ResumeDialogComponent // DialogRef<ResumeChoice>, DIALOG_DATA: ResumeData
  ```

- [ ] **Step 1: Add the translations**

`en.json`, inside `reader.audioPlayer`. Add a comma after `"less": "Less"`, then:
```json
      "resume": {
        "message": "You stopped at {{ position }} of {{ duration }}.",
        "continue": "Continue",
        "fromStart": "From start"
      }
```

`de.json`, same place, after `"less": "Weniger"`:
```json
      "resume": {
        "message": "Du hast bei {{ position }} von {{ duration }} aufgehört.",
        "continue": "Fortsetzen",
        "fromStart": "Von vorn"
      }
```

- [ ] **Step 2: Write the failing test**

```ts
import { TestBed } from '@angular/core/testing';
import { DialogRef, DIALOG_DATA } from '@angular/cdk/dialog';
import { provideTranslocoTesting } from '../../../../testing/transloco-testing';
import { hasTranslation } from '../../../../testing/translation-keys';
import { ResumeData, ResumeDialogComponent } from './resume-dialog.component';

describe('ResumeDialogComponent', () => {
  const close = jest.fn();
  const data: ResumeData = { title: 'The long episode', seconds: 1421, durationSeconds: 3490 };

  function render(): ComponentFixture<ResumeDialogComponent> {
    TestBed.configureTestingModule({
      imports: [provideTranslocoTesting()],
      providers: [
        { provide: DialogRef, useValue: { close } },
        { provide: DIALOG_DATA, useValue: data },
      ],
    });
    const fixture = TestBed.createComponent(ResumeDialogComponent);
    fixture.detectChanges();
    return fixture;
  }

  function button(element: HTMLElement, testId: string): HTMLButtonElement {
    return element.querySelector(`[data-testid="${testId}"] button`) as HTMLButtonElement;
  }

  beforeEach(() => close.mockReset());

  it('names the episode', () => {
    expect(render().nativeElement.textContent).toContain('The long episode');
  });

  it('has its copy in the dictionary', () => {
    for (const key of ['message', 'continue', 'fromStart']) {
      expect(hasTranslation(`reader.audioPlayer.resume.${key}`)).toBe(true);
    }
  });

  it('formats the place and the length as clock times', () => {
    const component = render().componentInstance;
    expect(component.position).toBe('23:41');
    expect(component.duration).toBe('58:10');
  });

  it('closes with continue, or with from start', () => {
    const element: HTMLElement = render().nativeElement;

    button(element, 'continue').click();
    expect(close).toHaveBeenLastCalledWith('continue');

    button(element, 'from-start').click();
    expect(close).toHaveBeenLastCalledWith('fromStart');
  });
});
```

Add `ComponentFixture` to the `@angular/core/testing` import. `app-button` keeps `data-testid` on its host and renders an inner `<button>`, so `[data-testid="…"] button` is the element to click.

- [ ] **Step 3: Run test to verify it fails**

Run: `docker compose exec -T frontend npx jest src/app/reader/audio/resume-dialog`
Expected: FAIL, "Cannot find module './resume-dialog.component'"

- [ ] **Step 4: Implement**

`resume-dialog.component.ts`:
```ts
import { ChangeDetectionStrategy, Component, inject } from '@angular/core';
import { A11yModule } from '@angular/cdk/a11y';
import { DialogRef, DIALOG_DATA } from '@angular/cdk/dialog';
import { TranslocoPipe } from '@jsverse/transloco';
import { ButtonComponent } from '../../../shared/button/button.component';
import { OverlayPanelComponent } from '../../../shared/overlay-panel/overlay-panel.component';
import { formatDuration } from '../../format';

export type ResumeChoice = 'continue' | 'fromStart';

export interface ResumeData {
  title: string;
  seconds: number;
  durationSeconds: number;
}

/** Asks whether a long track continues where it was left or starts over (#1866). */
@Component({
  selector: 'app-resume-dialog',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [A11yModule, TranslocoPipe, ButtonComponent, OverlayPanelComponent],
  templateUrl: './resume-dialog.component.html',
  styleUrl: './resume-dialog.component.scss',
})
export class ResumeDialogComponent {
  readonly ref = inject<DialogRef<ResumeChoice>>(DialogRef);
  readonly data = inject<ResumeData>(DIALOG_DATA);
  readonly position = formatDuration(this.data.seconds);
  readonly duration = formatDuration(this.data.durationSeconds);
}
```

`resume-dialog.component.html`:
```html
<app-overlay-panel [heading]="data.title" cdkTrapFocus>
  <p class="msg">
    {{ 'reader.audioPlayer.resume.message' | transloco: { position, duration } }}
  </p>

  <app-button footer data-testid="from-start" (click)="ref.close('fromStart')">
    {{ 'reader.audioPlayer.resume.fromStart' | transloco }}
  </app-button>
  <app-button
    footer
    focusInitial
    data-testid="continue"
    variant="primary"
    (click)="ref.close('continue')"
  >
    {{ 'reader.audioPlayer.resume.continue' | transloco }}
  </app-button>
</app-overlay-panel>
```

`resume-dialog.component.scss` (the same host and message rules as `confirm-dialog.component.scss`):
```scss
// `display: contents` lets the CDK dialog container lay out app-overlay-panel's .panel directly.
:host {
  display: contents;

  --panel-w: 400px;
}

.msg {
  margin: 0;
  color: var(--text-secondary);
}
```

- [ ] **Step 5: Run test to verify it passes**

Run: `docker compose exec -T frontend npx jest src/app/reader/audio/resume-dialog`
Expected: PASS

- [ ] **Step 6: Commit**

```bash
git add frontend/src/app/reader/audio/resume-dialog frontend/public/i18n/en.json frontend/public/i18n/de.json
git commit -m "feat(#1866): the dialog that offers to continue a long track"
```

---

### Task 5: `ListenLauncher`

**Files:**
- Create: `frontend/src/app/reader/audio/listen-launcher.ts`
- Modify: `frontend/src/testing/stub-audio-player.ts` (add `playFrom`)
- Test: `frontend/src/app/reader/audio/listen-launcher.spec.ts`

**Interfaces:**
- Consumes: `AudioPlayerService.current()`, `.play(track)`, `.playFrom(track, seconds)`; `ListenPositions.get(url)`; `ResumeDialogComponent`, `ResumeChoice`, `ResumeData` (Task 4); CDK `Dialog`.
- Produces:
  ```ts
  @Injectable({ providedIn: 'root' }) export class ListenLauncher {
    /** `onStarted` runs once the track starts; never when the dialog is dismissed. */
    listen(track: AudioTrack, onStarted?: () => void): void;
  }
  ```

- [ ] **Step 1: Give `StubAudioPlayer` a `playFrom`**

In `frontend/src/testing/stub-audio-player.ts`, add `| 'playFrom'` to the `Pick` union, add the field `readonly playedFrom: { track: AudioTrack; seconds: number }[] = [];`, and add this method after `play`:
```ts
  playFrom(track: AudioTrack, seconds: number): void {
    this.playedFrom.push({ track, seconds });
  }
```

- [ ] **Step 2: Write the failing test**

```ts
import { TestBed } from '@angular/core/testing';
import { Dialog } from '@angular/cdk/dialog';
import { of } from 'rxjs';
import { provideAccountIdentity } from '../../../testing/account-identity-testing';
import { StubAudioPlayer } from '../../../testing/stub-audio-player';
import { AudioPlayerService, AudioTrack } from '../audio-player.service';
import { ListenLauncher } from './listen-launcher';
import { ListenPositions } from './listen-positions';
import { ResumeChoice, ResumeDialogComponent } from './resume-dialog/resume-dialog.component';

const TRACK: AudioTrack = {
  url: 'https://x.test/long.mp3',
  title: 'The long episode',
  faviconUrl: null,
  imageUrl: null,
  durationInSeconds: 3600,
};

function setUp(choice: ResumeChoice | undefined = undefined) {
  localStorage.clear();
  const player = new StubAudioPlayer();
  const open = jest.fn(() => ({ closed: of(choice) }));
  TestBed.configureTestingModule({
    providers: [
      provideAccountIdentity(),
      { provide: AudioPlayerService, useValue: player.asService() },
      { provide: Dialog, useValue: { open } },
    ],
  });
  const started = jest.fn();
  return {
    player,
    open,
    started,
    launcher: TestBed.inject(ListenLauncher),
    leftAt: (seconds: number) =>
      TestBed.inject(ListenPositions).remember({ url: TRACK.url, seconds, durationSeconds: 3600 }),
  };
}

describe('ListenLauncher', () => {
  it('plays a track with no saved place at once', () => {
    const { player, open, started, launcher } = setUp();

    launcher.listen(TRACK, started);

    expect(open).not.toHaveBeenCalled();
    expect(player.played).toEqual([TRACK]);
    expect(started).toHaveBeenCalledTimes(1);
  });

  it('asks about a saved place, naming the episode, the place and the length', () => {
    const { open, launcher, leftAt } = setUp();
    leftAt(1421);

    launcher.listen(TRACK);

    expect(open).toHaveBeenCalledWith(ResumeDialogComponent, {
      data: { title: 'The long episode', seconds: 1421, durationSeconds: 3600 },
      panelClass: 'app-dialog',
    });
  });

  it('continues at the saved place', () => {
    const { player, started, launcher, leftAt } = setUp('continue');
    leftAt(1421);

    launcher.listen(TRACK, started);

    expect(player.playedFrom).toEqual([{ track: TRACK, seconds: 1421 }]);
    expect(player.played).toEqual([]);
    expect(started).toHaveBeenCalledTimes(1);
  });

  it('starts over from the beginning', () => {
    const { player, started, launcher, leftAt } = setUp('fromStart');
    leftAt(1421);

    launcher.listen(TRACK, started);

    expect(player.played).toEqual([TRACK]);
    expect(player.playedFrom).toEqual([]);
    expect(started).toHaveBeenCalledTimes(1);
  });

  it('plays nothing when the dialog is dismissed', () => {
    const { player, started, launcher, leftAt } = setUp(undefined);
    leftAt(1421);

    launcher.listen(TRACK, started);

    expect(player.played).toEqual([]);
    expect(player.playedFrom).toEqual([]);
    expect(started).not.toHaveBeenCalled();
  });

  it('never asks about the track already in the player', () => {
    const { player, open, launcher, leftAt } = setUp();
    leftAt(1421);
    player.current.set(TRACK);

    launcher.listen(TRACK);

    expect(open).not.toHaveBeenCalled();
    expect(player.played).toEqual([TRACK]);
  });
});
```

- [ ] **Step 3: Run test to verify it fails**

Run: `docker compose exec -T frontend npx jest src/app/reader/audio/listen-launcher.spec.ts`
Expected: FAIL, "Cannot find module './listen-launcher'"

- [ ] **Step 4: Implement**

```ts
import { Injectable, inject } from '@angular/core';
import { Dialog } from '@angular/cdk/dialog';
import { Observable } from 'rxjs';
import { AudioPlayerService, AudioTrack } from '../audio-player.service';
import { ListenPositions, SavedPosition } from './listen-positions';
import {
  ResumeChoice,
  ResumeData,
  ResumeDialogComponent,
} from './resume-dialog/resume-dialog.component';

/** Where every Listen starts a track: at once, or after asking whether to continue a long one (#1866). */
@Injectable({ providedIn: 'root' })
export class ListenLauncher {
  private readonly player = inject(AudioPlayerService);
  private readonly positions = inject(ListenPositions);
  private readonly dialog = inject(Dialog);

  listen(track: AudioTrack, onStarted: () => void = () => undefined): void {
    const saved = this.savedPositionOf(track);
    if (!saved) {
      this.player.play(track);
      return onStarted();
    }
    this.ask(track, saved).subscribe((choice) => {
      if (!choice) return;
      if (choice === 'continue') this.player.playFrom(track, saved.seconds);
      else this.player.play(track);
      onStarted();
    });
  }

  private savedPositionOf(track: AudioTrack): SavedPosition | null {
    if (this.player.current()?.url === track.url) return null;
    return this.positions.get(track.url);
  }

  private ask(track: AudioTrack, saved: SavedPosition): Observable<ResumeChoice | undefined> {
    const data: ResumeData = {
      title: track.title,
      seconds: saved.seconds,
      durationSeconds: saved.durationSeconds,
    };
    return this.dialog.open<ResumeChoice>(ResumeDialogComponent, {
      data,
      panelClass: 'app-dialog',
    }).closed;
  }
}
```

CDK `DialogRef.close()` emits `closed` synchronously, so `play()` still runs inside the button's click task, which is the user gesture iOS needs to start audio. Task 7 verifies this on a phone; do not add a comment for it.

- [ ] **Step 5: Run test to verify it passes**

Run: `docker compose exec -T frontend npx jest src/app/reader/audio/listen-launcher.spec.ts`
Expected: PASS

- [ ] **Step 6: Commit**

```bash
git add frontend/src/app/reader/audio/listen-launcher.ts frontend/src/app/reader/audio/listen-launcher.spec.ts frontend/src/testing/stub-audio-player.ts
git commit -m "feat(#1866): Listen asks whether to continue a long track it left"
```

---

### Task 6: Every Listen goes through the launcher

**Files:**
- Create: `frontend/src/testing/stub-listen-launcher.ts`
- Modify: `frontend/src/app/reader/audio/entry-audio.ts` (constructor and `play()`)
- Modify: `frontend/src/app/reader/audio/entry-audio.spec.ts`
- Modify: `frontend/src/app/reader/entry/entry-actions/entry-actions.component.ts:44`
- Modify: `frontend/src/app/reader/article/reader-view/reader-view.component.ts:173-177`
- Modify: `frontend/src/app/reader/article/reader-view/article-embed-audio.ts`
- Modify: `frontend/src/app/reader/article/reader-view/article-embed-audio.spec.ts:38`
- Modify: `frontend/src/app/reader/article/reader-view/article-body.service.ts:67`

**Interfaces:**
- Consumes: `ListenLauncher.listen(track, onStarted?)` (Task 5).
- Produces: `new EntryAudio(entry, player, launcher, chapters?)` and `new ArticleEmbedAudio(audio, launcher, injector)`.

- [ ] **Step 1: Create the stub launcher**

`frontend/src/testing/stub-listen-launcher.ts`:
```ts
import type { AudioTrack } from '../app/reader/audio-player.service';
import type { ListenLauncher } from '../app/reader/audio/listen-launcher';
import type { StubAudioPlayer } from './stub-audio-player';

/** Listen with no saved place: the track starts on the stub player at once. */
export class StubListenLauncher implements Pick<ListenLauncher, 'listen'> {
  constructor(private readonly player: StubAudioPlayer) {}

  listen(track: AudioTrack, onStarted: () => void = () => undefined): void {
    this.player.play(track);
    onStarted();
  }

  asService(): ListenLauncher {
    return this as unknown as ListenLauncher;
  }
}
```

- [ ] **Step 2: Write the failing tests**

`entry-audio.spec.ts`: import `StubListenLauncher` from `'../../../testing/stub-listen-launcher'`. In `setUp`, construct with
`new EntryAudio(() => subject, player.asService(), new StubListenLauncher(player).asService())`.
In each other `new EntryAudio(…)` in that file, insert `new StubListenLauncher(player).asService()` as the third argument. Where the player is created inline (`new StubAudioPlayer().asService()`), hoist it to a `const player = new StubAudioPlayer();` first. Then add:

```ts
  it('starts the track through the launcher, which may ask first (#1866)', () => {
    const player = new StubAudioPlayer();
    const listen = jest.fn();
    const audio = new EntryAudio(() => entry(), player.asService(), {
      listen,
    } as unknown as ListenLauncher);

    audio.play();

    expect(listen).toHaveBeenCalledWith(expect.objectContaining({ url: EPISODE }));
    expect(player.played).toEqual([]);
  });
```
(import `ListenLauncher` from `'./listen-launcher'`.)

`article-embed-audio.spec.ts`: in `setUp`, construct with
`new ArticleEmbedAudio(player.asService(), new StubListenLauncher(player).asService(), TestBed.inject(Injector))`. Add inside `describe('listen', …)`:

```ts
    it('follows the track only once the launcher starts it (#1866)', () => {
      const player = new StubAudioPlayer();
      const embedAudio = new ArticleEmbedAudio(
        player.asService(),
        { listen: jest.fn() } as unknown as ListenLauncher,
        TestBed.inject(Injector),
      );
      const listened: AudioTrack[] = [];
      const actions = embedAudio.actions({
        entry: ENTRY,
        detailsOf: () => null,
        onListened: (track) => listened.push(track),
      }) as ListenActions;

      actions.listen(VIDEO, frameWithWindow().frame);

      expect(listened).toEqual([]);
    });
```
(import `ListenLauncher` from `'../../audio/listen-launcher'` and `StubListenLauncher` from `'../../../../testing/stub-listen-launcher'`.)

- [ ] **Step 3: Run tests to verify they fail**

Run: `docker compose exec -T frontend npx jest src/app/reader/audio/entry-audio.spec.ts src/app/reader/article/reader-view/article-embed-audio.spec.ts`
Expected: FAIL. Type errors on the extra constructor argument, or the new tests see `player.played`/`listened` filled.

- [ ] **Step 4: Implement**

`entry-audio.ts`: import `ListenLauncher` from `'./listen-launcher'`. Change the constructor and `play()`:
```ts
  constructor(
    private readonly entry: () => EntryDto | null | undefined,
    private readonly player: AudioPlayerService,
    private readonly launcher: ListenLauncher,
    private readonly chapters: () => ChapterDto[] = () => [],
  ) {}

  play(): void {
    const track = this.track();
    if (track) this.launcher.listen(track);
  }
```

`entry-actions.component.ts:44`:
```ts
  protected readonly entryAudio = new EntryAudio(
    this.entry,
    inject(AudioPlayerService),
    inject(ListenLauncher),
  );
```
`reader-view.component.ts:173`:
```ts
  protected readonly audio = new EntryAudio(
    this.entry,
    inject(AudioPlayerService),
    inject(ListenLauncher),
    this.source.chapters,
  );
```
(both import `ListenLauncher` from the relative path to `reader/audio/listen-launcher`.)

`article-embed-audio.ts`: import `ListenLauncher` from `'../../audio/listen-launcher'`. Change the constructor and `listen`:
```ts
  constructor(
    private readonly audio: AudioPlayerService,
    private readonly launcher: ListenLauncher,
    private readonly injector: Injector,
  ) {}
```
```ts
      listen: (media, frame) => {
        pauseEmbedFrame(frame, media.kind);
        const track = trackOf(media);
        this.launcher.listen(track, () => context.onListened(track));
      },
```
`article-body.service.ts:67`:
```ts
  private readonly embedAudio = new ArticleEmbedAudio(
    this.audio,
    inject(ListenLauncher),
    this.injector,
  );
```

- [ ] **Step 5: Run the reader specs**

Run: `docker compose exec -T frontend npx jest src/app/reader`
Expected: PASS. `entry-actions.component.spec.ts` now builds a real `ListenLauncher`, which needs an `AccountIdentity`. If it fails with `No provider for HttpClient`, add `provideAccountIdentity()` to its providers. Its existing play assertions keep passing, because nothing is saved and the launcher plays at once.

- [ ] **Step 6: Commit**

```bash
git add frontend/src
git commit -m "feat(#1866): Listen in the article, the list and on embeds goes through the launcher"
```

---

### Task 7: Gates and live check

**Files:** none new.

- [ ] **Step 1: Full frontend gate**

Run (repo root): `docker compose exec -T frontend npm run check`
Expected: PASS (ESLint, Prettier, Stylelint, tsc, Jest). Fix Prettier with `docker compose exec -T frontend npx prettier --write <files>`.

- [ ] **Step 2: Template build**

Run: `docker compose exec -T frontend npx ng build`
Expected: success. `npm run check` misses template errors.

- [ ] **Step 3: Live check on http://localhost:4200**

First confirm that the `frontend` container serves the current code, which may need a hard reload. Then:
1. Open an article with a podcast enclosure longer than 15 min, click **Listen**, let it play past 0:20, and pause. In DevTools, `localStorage['sfr.user.<id>.listen-positions']` holds the url and seconds.
2. Play another track, then click **Listen** on the first episode again. The dialog shows the title and "You stopped at m:ss of h:mm:ss." **Continue** resumes there and **From start** plays from 0:00. Esc plays nothing.
3. Click **Listen** on the episode that is now loaded in the player: no dialog.
4. Start the episode from the playlist panel: no dialog, and it plays from 0:00.
5. Do the same from a list-row play button, and from an embedded YouTube player's Listen control in an article.
6. At phone width (390 px), the two buttons sit on one line with no wrapping, in both English and German.
7. On a real iPhone (or the iOS simulator against :4200), **Continue** starts audible playback with a single tap. This verifies the user-gesture path.

- [ ] **Step 4: Commit any fixes, then hand off** to superpowers:finishing-a-development-branch (PR into `develop`, body `Closes #1866`).
