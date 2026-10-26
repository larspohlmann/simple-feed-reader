# Chapter Progress Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** While a chapter of a YouTube video or a podcast episode plays, its row in the article's chapter list shows how far into that chapter playback is: a faint track across the row with a muted accent fill growing over it, behind the time and title, and the time left in that chapter, right-aligned (`3:12`).

**Architecture:** `ChapterPlayer.watch` reports a `PlaybackPosition` (seconds plus the media's duration once known) instead of bare seconds. A pure helper `chapterProgress` turns starts + index + position into a `ChapterProgress` (`fraction` 0–1, `secondsLeft`). The chapter list writes the fraction into one custom property, `--chapter-progress`, on the current row, and the time left into a right-aligned span. CSS draws the track (`::before`) and the fill (`::after`, `transform: scaleX(...)`), only on `[aria-current='true']`, so position updates cause no layout.

**Tech Stack:** Angular 20 (signals, standalone), plain DOM decorators, SCSS, Jest (jsdom).

**Spec:** GitHub issue #1814 (design agreed in a visual round: variant "wash on a track"). Builds on the chapter list from #1808 (`docs/superpowers/plans/2026-10-25-chapters.md`).

## Global Constraints

- CLAUDE.md applies in full: names reveal intent, no abbreviations, comments only when a reader would otherwise get the code wrong, one line each.
- ESLint: `max-lines-per-function` 60, `complexity` 10, `@typescript-eslint/max-params` 3, Prettier 100 columns.
- SCSS: no hex colours, no ad-hoc `px`, tokens only (`--accent-soft`, `--text-primary`). Every `.reader-chapters…` rule lives in the sibling `article-content/article-chapters.scss` (listed after `article-content.component.scss` in `styleUrls`), which keeps the component under the 14kB `anyComponentStyle` budget (changed during implementation).
- Fill colour `var(--accent-soft)`; track colour `color-mix(in srgb, var(--text-primary) 5%, transparent)`. The left accent bar on the current row stays unchanged.
- Time left: `formatDuration(secondsLeft)`, no sign (user decision mid-run), from `reader/format.ts`, right-aligned at the row's end, `--text-secondary`, tabular numerals, `aria-hidden`. No translation key needed.
- Only the current (`aria-current='true'`) row shows track, fill and time left. Before any position arrives, the list looks as it does today.
- The last chapter ends at the media's duration; while that is unknown (`null`), the row shows the track without a fill and without a time left.
- Frontend gates: `npm run check` and `npx ng build` (template errors), Jest inside the Docker frontend container: `docker compose exec -T frontend npm test -- <path>`. Never run two Jest runs in the container at once.
- Commit messages: `type(#1814): …`, no attribution lines.
- Branch `feature/1814-chapter-progress` (off `develop`, no upstream yet). Another session may share this checkout: `git status` before any `checkout`, `reset` or `stash`.

## File Structure

All paths under `frontend/src/app/reader/`.

| File | Change |
|---|---|
| `chapters/chapter-player.ts` | New `PlaybackPosition`; `watch` reports it |
| `chapters/youtube-chapter-player.ts` | Remembers the last delivered `duration`; reports `PlaybackPosition` |
| `chapters/audio-chapter-player.ts` | Reports `PlaybackPosition` from `position()` and `duration()` |
| `chapters/current-chapter.ts` | New `ChapterProgress` and `chapterProgress(starts, index, position)` |
| `article/decorators/reader-chapters.ts` | Each row gets a time-left span; writes `--chapter-progress` and the time left on the current row, clears both on the row it leaves |
| `article/article-content/article-chapters.scss` | All `.reader-chapters…` rules, moved out of `article-content.component.scss`; track, fill and a steady-width time left on the current row |
| `article/article-content/article-content.component.ts` | `styleUrls` lists both stylesheets |
| matching `*.spec.ts` | Tests per task |

`article/reader-view/article-chapters.ts` only constructs players and needs no change.

---

### Task 1: Players report a `PlaybackPosition`

The interface change and its three consumers compile only together, so they are one task.

**Files:**
- Modify: `frontend/src/app/reader/chapters/chapter-player.ts`
- Modify: `frontend/src/app/reader/chapters/youtube-chapter-player.ts`
- Modify: `frontend/src/app/reader/chapters/audio-chapter-player.ts`
- Modify: `frontend/src/app/reader/article/decorators/reader-chapters.ts:43-44` (the `player.watch` line)
- Test: `frontend/src/app/reader/chapters/youtube-chapter-player.spec.ts`
- Test: `frontend/src/app/reader/chapters/audio-chapter-player.spec.ts`
- Test: `frontend/src/app/reader/article/decorators/reader-chapters.spec.ts`

**Interfaces:**
- Produces: `export interface PlaybackPosition { readonly seconds: number; readonly durationSeconds: number | null; }` in `chapters/chapter-player.ts`, and `ChapterPlayer.watch(onPosition: (position: PlaybackPosition | null) => void): void`.

- [ ] **Step 1: Write the failing YouTube tests**

In `youtube-chapter-player.spec.ts`, add the import and change the existing position test, then add two tests after it:

```ts
import { PlaybackPosition } from './chapter-player';
import { YouTubeChapterPlayer } from './youtube-chapter-player';
```

```ts
  it('reports the position the player delivers', () => {
    const { frame } = frameWithWindow();
    const player = new YouTubeChapterPlayer(frame);
    const positions: (PlaybackPosition | null)[] = [];
    player.watch((position) => positions.push(position));

    delivery(frame, { event: 'infoDelivery', info: { currentTime: 263.3 } });
    delivery(frame, { event: 'infoDelivery', info: { volume: 100 } });

    expect(positions).toEqual([{ seconds: 263.3, durationSeconds: null }]);
    player.release();
  });

  it('carries the last delivered duration on later positions', () => {
    const { frame } = frameWithWindow();
    const player = new YouTubeChapterPlayer(frame);
    const positions: (PlaybackPosition | null)[] = [];
    player.watch((position) => positions.push(position));

    delivery(frame, { event: 'initialDelivery', info: { currentTime: 0, duration: 600 } });
    delivery(frame, { event: 'infoDelivery', info: { currentTime: 12 } });

    expect(positions).toEqual([
      { seconds: 0, durationSeconds: 600 },
      { seconds: 12, durationSeconds: 600 },
    ]);
    player.release();
  });

  it('knows no duration while the player reports none', () => {
    const { frame } = frameWithWindow();
    const player = new YouTubeChapterPlayer(frame);
    const positions: (PlaybackPosition | null)[] = [];
    player.watch((position) => positions.push(position));

    delivery(frame, { event: 'infoDelivery', info: { currentTime: 30, duration: 0 } });

    expect(positions).toEqual([{ seconds: 30, durationSeconds: null }]);
    player.release();
  });
```

In the two remaining tests that declare `const positions: (number | null)[] = [];` and `player.watch((seconds) => positions.push(seconds));`, change them to `(PlaybackPosition | null)[]` and `(position) => positions.push(position)`. Their expectations (`[]`) stay.

- [ ] **Step 2: Write the failing audio tests**

In `audio-chapter-player.spec.ts`, give the fake a duration and change the follow test, then add one:

```ts
import { PlaybackPosition } from './chapter-player';
```

```ts
function fakePlayer() {
  const current = signal<AudioTrack | null>(null);
  const position = signal(0);
  const duration = signal(0);
  return { current, position, duration, playFrom: jest.fn() };
}
```

```ts
  it('follows the position only while its episode is the current track', () => {
    const player = fakePlayer();
    const chapters = new AudioChapterPlayer(player as never, EPISODE, TestBed.inject(Injector));
    const positions: (PlaybackPosition | null)[] = [];
    chapters.watch((position) => positions.push(position));

    TestBed.tick();
    player.current.set(EPISODE);
    player.position.set(130);
    player.duration.set(600);
    TestBed.tick();
    chapters.release();
    player.position.set(140);
    TestBed.tick();

    expect(positions).toEqual([null, { seconds: 130, durationSeconds: 600 }]);
  });

  it('knows no duration before the episode reports one', () => {
    const player = fakePlayer();
    const chapters = new AudioChapterPlayer(player as never, EPISODE, TestBed.inject(Injector));
    const positions: (PlaybackPosition | null)[] = [];
    chapters.watch((position) => positions.push(position));

    player.current.set(EPISODE);
    player.position.set(5);
    TestBed.tick();
    chapters.release();

    expect(positions).toEqual([{ seconds: 5, durationSeconds: null }]);
  });
```

- [ ] **Step 3: Adapt the chapter list spec's fake player**

In `reader-chapters.spec.ts`, the fake keeps its `positionTo(seconds)` helper so the existing tests read unchanged, and gains `progressTo` for Task 3:

```ts
import { ChapterPlayer, PlaybackPosition } from '../../chapters/chapter-player';
import { addChapterList } from './reader-chapters';
```

```ts
type FakePlayer = ChapterPlayer & {
  positionTo(seconds: number | null): void;
  progressTo(position: PlaybackPosition): void;
};

function fakePlayer(): FakePlayer {
  let listener: (position: PlaybackPosition | null) => void = () => undefined;
  return {
    playFrom: jest.fn(),
    watch: (onPosition) => (listener = onPosition),
    release: jest.fn(),
    positionTo: (seconds) =>
      listener(seconds === null ? null : { seconds, durationSeconds: null }),
    progressTo: (position) => listener(position),
  };
}
```

- [ ] **Step 4: Run the tests to verify they fail**

Run: `docker compose exec -T frontend npm test -- src/app/reader/chapters src/app/reader/article/decorators/reader-chapters.spec.ts`
Expected: FAIL. The YouTube and audio specs report bare numbers where objects are expected, and TypeScript rejects the `PlaybackPosition` import.

- [ ] **Step 5: Add `PlaybackPosition` to the interface**

`chapters/chapter-player.ts` becomes:

```ts
/** Where playback is; `durationSeconds` is null until the player knows the media's length. */
export interface PlaybackPosition {
  readonly seconds: number;
  readonly durationSeconds: number | null;
}

/** What a chapter list drives: a player it can start at a chapter and follow (#1808). */
export interface ChapterPlayer {
  playFrom(seconds: number): void;
  watch(onPosition: (position: PlaybackPosition | null) => void): void;
  release(): void;
}
```

- [ ] **Step 6: The YouTube player remembers the duration**

YouTube sends `duration` in some `infoDelivery`/`initialDelivery` messages and `currentTime` in others, so the player keeps the last positive duration. A live stream reports `0`, which means unknown. In `chapters/youtube-chapter-player.ts`, replace the `onPosition` field, `onMessage`, `watch` and the `currentTimeOf` function:

```ts
import { ChapterPlayer, PlaybackPosition } from './chapter-player';
```

```ts
interface PlayerInfo {
  readonly currentTime?: unknown;
  readonly duration?: unknown;
}
```

(place `PlayerInfo` below the `WIDGET` constant)

```ts
  private onPosition: ((position: PlaybackPosition | null) => void) | null = null;
  private durationSeconds: number | null = null;

  private readonly onMessage = (event: MessageEvent): void => {
    if (event.origin !== PLAYER_ORIGIN || event.source !== this.frame.contentWindow) return;
    const info = infoOf(event.data);
    const duration = secondsOf(info?.duration);
    if (duration !== null && duration > 0) this.durationSeconds = duration;
    const seconds = secondsOf(info?.currentTime);
    if (seconds !== null) this.onPosition?.({ seconds, durationSeconds: this.durationSeconds });
  };
```

```ts
  watch(onPosition: (position: PlaybackPosition | null) => void): void {
    this.onPosition = onPosition;
  }
```

```ts
function infoOf(data: unknown): PlayerInfo | null {
  if (typeof data !== 'string') return null;
  try {
    return (JSON.parse(data) as { info?: PlayerInfo } | null)?.info ?? null;
  } catch {
    return null;
  }
}

function secondsOf(value: unknown): number | null {
  return typeof value === 'number' && Number.isFinite(value) ? value : null;
}
```

- [ ] **Step 7: The audio player reports the duration**

In `chapters/audio-chapter-player.ts`:

```ts
import { ChapterPlayer, PlaybackPosition } from './chapter-player';
```

```ts
  watch(onPosition: (position: PlaybackPosition | null) => void): void {
    this.watcher?.destroy();
    this.watcher = effect(() => onPosition(this.position()), { injector: this.injector });
  }
```

and a private method below `release()`:

```ts
  private position(): PlaybackPosition | null {
    if (this.player.current()?.url !== this.track.url) return null;
    const duration = this.player.duration();
    return { seconds: this.player.position(), durationSeconds: duration > 0 ? duration : null };
  }
```

- [ ] **Step 8: The chapter list reads `seconds` from the position**

In `article/decorators/reader-chapters.ts`, replace

```ts
  player.watch((seconds) => mark(currentChapterIndex(starts, seconds)));
```

with

```ts
  player.watch((position) => mark(currentChapterIndex(starts, position?.seconds ?? null)));
```

- [ ] **Step 9: Run the tests to verify they pass**

Run: `docker compose exec -T frontend npm test -- src/app/reader/chapters src/app/reader/article/decorators/reader-chapters.spec.ts`
Expected: PASS, every test in the four chapter spec files.

- [ ] **Step 10: Typecheck and lint**

Run (from `frontend/`): `npm run check`
Expected: exit 0.

- [ ] **Step 11: Commit**

```bash
git add frontend/src/app/reader/chapters frontend/src/app/reader/article/decorators/reader-chapters.ts frontend/src/app/reader/article/decorators/reader-chapters.spec.ts
git commit -m "feat(#1814): chapter players report the media's duration with the position"
```

---

### Task 2: `chapterProgress`

**Files:**
- Modify: `frontend/src/app/reader/chapters/current-chapter.ts`
- Test: `frontend/src/app/reader/chapters/current-chapter.spec.ts`

**Interfaces:**
- Consumes: `PlaybackPosition` from Task 1.
- Produces, in `chapters/current-chapter.ts`:
  - `export interface ChapterProgress { readonly fraction: number; readonly secondsLeft: number; }`. `fraction` is 0–1 and `secondsLeft` is ≥ 0, both with the position clamped into the chapter.
  - `export function chapterProgress(starts: readonly number[], index: number, position: PlaybackPosition): ChapterProgress | null`. It returns `null` when chapter `index` has no known end. The caller passes only an `index >= 0`.

- [ ] **Step 1: Write the failing tests**

Change the import at the top of `current-chapter.spec.ts` to `import { chapterProgress, currentChapterIndex } from './current-chapter';` and append:

```ts
describe('chapterProgress', () => {
  const starts = [0, 60, 180];
  const at = (seconds: number, durationSeconds: number | null = null) => ({
    seconds,
    durationSeconds,
  });

  it('runs from the chapter start to the next chapter start', () => {
    expect(chapterProgress(starts, 1, at(90))).toEqual({ fraction: 0.25, secondsLeft: 90 });
  });

  it('runs the last chapter to the end of the media', () => {
    expect(chapterProgress(starts, 2, at(240, 300))).toEqual({ fraction: 0.5, secondsLeft: 60 });
  });

  it('has no progress for the last chapter while the duration is unknown', () => {
    expect(chapterProgress(starts, 2, at(240))).toBeNull();
  });

  it('has no progress when the media ends before the last chapter starts', () => {
    expect(chapterProgress(starts, 2, at(170, 170))).toBeNull();
  });

  it.each([
    [at(-5), { fraction: 0, secondsLeft: 120 }],
    [at(400), { fraction: 1, secondsLeft: 0 }],
  ])('stays inside the chapter at %p', (position, progress) => {
    expect(chapterProgress(starts, 1, position)).toEqual(progress);
  });
});
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `docker compose exec -T frontend npm test -- src/app/reader/chapters/current-chapter.spec.ts`
Expected: FAIL. `chapterProgress` is not exported.

- [ ] **Step 3: Implement**

Add `import { PlaybackPosition } from './chapter-player';` at the top of `current-chapter.ts` and append:

```ts
/** How far playback is through one chapter. */
export interface ChapterProgress {
  readonly fraction: number;
  readonly secondsLeft: number;
}

/** The progress of `position` through chapter `index`; null while that chapter's end is unknown. */
export function chapterProgress(
  starts: readonly number[],
  index: number,
  position: PlaybackPosition,
): ChapterProgress | null {
  const start = starts[index];
  const end = starts[index + 1] ?? position.durationSeconds;
  if (end === null || end <= start) return null;
  const seconds = Math.min(end, Math.max(start, position.seconds));
  return { fraction: (seconds - start) / (end - start), secondsLeft: end - seconds };
}
```

- [ ] **Step 4: Run the tests to verify they pass**

Run: `docker compose exec -T frontend npm test -- src/app/reader/chapters/current-chapter.spec.ts`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add frontend/src/app/reader/chapters/current-chapter.ts frontend/src/app/reader/chapters/current-chapter.spec.ts
git commit -m "feat(#1814): work out how far playback is through a chapter"
```

---

### Task 3: The current row shows its progress and time left

**Files:**
- Modify: `frontend/src/app/reader/article/decorators/reader-chapters.ts`
- Create: `frontend/src/app/reader/article/article-content/article-chapters.scss` (the `.reader-chapters…` rules move here from `article-content.component.scss:473-498`)
- Modify: `frontend/src/app/reader/article/article-content/article-content.component.scss` and `article-content.component.ts` (`styleUrls` of both files)
- Test: `frontend/src/app/reader/article/decorators/reader-chapters.spec.ts`

**Interfaces:**
- Consumes: `PlaybackPosition` (Task 1), `ChapterProgress` and `chapterProgress` (Task 2), the spec's `fakePlayer().progressTo(position)` (Task 1, Step 3).
- Produces: on the current `.reader-chapters__row`, the inline custom property `--chapter-progress` (the fraction, four decimals) and the text of its `.reader-chapters__left` span (`formatDuration(secondsLeft)`, no sign). Every other row has neither.

- [ ] **Step 1: Write the failing tests**

Add to `reader-chapters.spec.ts`, inside `describe('addChapterList', ...)`, after `'follows the position the player reports'`. `CHAPTERS` starts at 0, 256 and 3723. Values: 128 s is half of chapter 0 with 128 s left. 3800 s in a 4000 s file is 77/277 ≈ 0.2780 of the last chapter, with 200 s left.

```ts
  function progressOf(row: HTMLElement): string {
    return row.style.getPropertyValue('--chapter-progress');
  }

  function timeLeftOf(row: HTMLElement): string | null | undefined {
    return row.querySelector('.reader-chapters__left')?.textContent;
  }

  it('fills the playing chapter and shows its time left', () => {
    const host = document.createElement('div');
    const player = fakePlayer();
    addChapterList({ host, after: null }, { chapters: CHAPTERS, heading: 'Chapters' }, player);

    player.progressTo({ seconds: 128, durationSeconds: null });

    expect(rows(host).map(progressOf)).toEqual(['0.5000', '', '']);
    expect(rows(host).map(timeLeftOf)).toEqual(['2:08', '', '']);
  });

  it('moves fill and time left to the chapter playback moves into', () => {
    const host = document.createElement('div');
    const player = fakePlayer();
    addChapterList({ host, after: null }, { chapters: CHAPTERS, heading: 'Chapters' }, player);

    player.progressTo({ seconds: 128, durationSeconds: 4000 });
    player.progressTo({ seconds: 3800, durationSeconds: 4000 });

    expect(rows(host).map(progressOf)).toEqual(['', '', '0.2780']);
    expect(rows(host).map(timeLeftOf)).toEqual(['', '', '3:20']);
  });

  it('leaves the last chapter unfilled while the duration is unknown', () => {
    const host = document.createElement('div');
    const player = fakePlayer();
    addChapterList({ host, after: null }, { chapters: CHAPTERS, heading: 'Chapters' }, player);

    player.progressTo({ seconds: 3800, durationSeconds: null });

    expect(rows(host)[2].getAttribute('aria-current')).toBe('true');
    expect(progressOf(rows(host)[2])).toBe('');
    expect(timeLeftOf(rows(host)[2])).toBe('');
  });

  it('clears fill and time left of a chapter left by a tap', () => {
    const host = document.createElement('div');
    const player = fakePlayer();
    addChapterList({ host, after: null }, { chapters: CHAPTERS, heading: 'Chapters' }, player);

    player.progressTo({ seconds: 128, durationSeconds: null });
    rows(host)[1].click();

    expect(rows(host).map(progressOf)).toEqual(['', '', '']);
    expect(rows(host).map(timeLeftOf)).toEqual(['', '', '']);
  });

  it('keeps the time left out of the row name a screen reader reads', () => {
    const host = document.createElement('div');
    addChapterList({ host, after: null }, { chapters: CHAPTERS, heading: 'Chapters' }, fakePlayer());

    const timeLeft = rows(host)[0].querySelector('.reader-chapters__left');
    expect(timeLeft?.getAttribute('aria-hidden')).toBe('true');
  });
```

The existing test `'lists every chapter with its start time …'` compares `row.textContent` to `'0:00Intro'` etc. It stays green, because the time-left span is empty until playback.

- [ ] **Step 2: Run the tests to verify they fail**

Run: `docker compose exec -T frontend npm test -- src/app/reader/article/decorators/reader-chapters.spec.ts`
Expected: FAIL. No row has `--chapter-progress` and there is no `.reader-chapters__left` span.

- [ ] **Step 3: Implement**

In `reader-chapters.ts`, extend the imports:

```ts
import { ChapterPlayer, PlaybackPosition } from '../../chapters/chapter-player';
import {
  ChapterProgress,
  chapterProgress,
  currentChapterIndex,
} from '../../chapters/current-chapter';
```

and add constants below `const LIST = 'reader-chapters';`:

```ts
const PROGRESS = '--chapter-progress';
const TIME_LEFT = `${LIST}__left`;
```

In `addChapterList`, the row the marker leaves loses its progress, and the watch callback shows the progress. Replace the `mark` closure and the `player.watch(...)` line. The click handler stays as it is:

```ts
  const mark = (index: number): void => {
    if (index === marked) return;
    const previous = rows[marked];
    if (previous) {
      previous.removeAttribute('aria-current');
      clearProgress(previous);
    }
    rows[index]?.setAttribute('aria-current', 'true');
    marked = index;
  };
```

```ts
  const starts = chapters.map((chapter) => chapter.startSeconds);
  const follow = (position: PlaybackPosition | null): void => {
    const index = currentChapterIndex(starts, position?.seconds ?? null);
    mark(index);
    if (position && index >= 0) showProgress(rows[index], chapterProgress(starts, index, position));
  };
  player.watch(follow);
```

Add two functions below `toggle`:

```ts
function showProgress(row: HTMLElement, progress: ChapterProgress | null): void {
  if (progress === null) {
    clearProgress(row);
    return;
  }
  row.style.setProperty(PROGRESS, progress.fraction.toFixed(4));
  row.querySelector(`.${TIME_LEFT}`)?.replaceChildren(formatDuration(progress.secondsLeft));
}

function clearProgress(row: HTMLElement): void {
  row.style.removeProperty(PROGRESS);
  row.querySelector(`.${TIME_LEFT}`)?.replaceChildren();
}
```

In `chapterRow`, add the time-left span after the title. It is `aria-hidden`, so a focused row's accessible name does not change every second:

```ts
  const timeLeft = document.createElement('span');
  timeLeft.className = TIME_LEFT;
  timeLeft.setAttribute('aria-hidden', 'true');
```

and change `row.append(time, title);` to `row.append(time, title, timeLeft);`.

`addChapterList` must stay under ESLint's 60-line `max-lines-per-function`. If the linter disagrees, move the `nav`/`ol` assembly into a `listElement(rows, heading)` function rather than suppressing the rule.

- [ ] **Step 4: Run the tests to verify they pass**

Run: `docker compose exec -T frontend npm test -- src/app/reader/article/decorators/reader-chapters.spec.ts`
Expected: PASS, the five new tests and the seven existing ones.

- [ ] **Step 5: Draw the track, fill and time left**

Move every `.reader-chapters…` rule out of `article-content.component.scss` into the new `article-content/article-chapters.scss` (keep its `@use '../../../theme/glass-rim';`), and set `styleUrls: ['./article-content.component.scss', './article-chapters.scss']` in `article-content.component.ts`. In the moved `:host ::ng-deep .reader-chapters__row` block add `position: relative;` and `isolation: isolate;` after `cursor: pointer;`. Then add after the `.reader-chapters__time` block:

```scss
:host ::ng-deep .reader-chapters__left {
  flex: none;
  margin-inline-start: auto;
  color: var(--text-secondary);
  font-variant-numeric: tabular-nums;
}

:host ::ng-deep .reader-chapters__row[aria-current='true'] .reader-chapters__left {
  min-width: 6ch;
  text-align: end;
}

:host ::ng-deep .reader-chapters__row[aria-current='true']::before,
:host ::ng-deep .reader-chapters__row[aria-current='true']::after {
  content: '';
  position: absolute;
  inset: 0;
  z-index: -1;
}

:host ::ng-deep .reader-chapters__row[aria-current='true']::before {
  background: color-mix(in srgb, var(--text-primary) 5%, transparent);
}

:host ::ng-deep .reader-chapters__row[aria-current='true']::after {
  background: var(--accent-soft);
  transform: scaleX(var(--chapter-progress, 0));
  transform-origin: left;
}
```

The fixed `min-width: 6ch` on the current row's time left keeps its width steady as the countdown changes digits (changed during implementation; an earlier draft hid the empty span with `:empty`).

`isolation: isolate` keeps the `z-index: -1` pseudo-elements inside the row: behind its text, above the card.

- [ ] **Step 6: Gates**

Run (from `frontend/`): `npm run check && npx ng build`
Expected: both exit 0.

- [ ] **Step 7: Commit**

```bash
git add frontend/src/app/reader/article/decorators/reader-chapters.ts frontend/src/app/reader/article/decorators/reader-chapters.spec.ts frontend/src/app/reader/article/article-content
git commit -m "feat(#1814): fill the playing chapter's row and show its time left"
```

---

### Task 4: Live check

No code unless the check finds a defect; a defect goes back to the planner with what was seen, not an improvised fix.

- [ ] **Step 1: Make sure :4200 serves this branch**

Check the frontend container is current (memory: `verify-containers-are-current`, `frontend-dev-container-serves-stale-chunk`). Open `http://localhost:4200` in the built-in browser and log in to the dev account.

- [ ] **Step 2: Find a chaptered episode and a chaptered video**

Podcast (chapters stored on the entry):

```bash
docker compose exec -T php bin/console dbal:run-sql "SELECT id, url FROM entry WHERE chapters IS NOT NULL ORDER BY id DESC LIMIT 5"
```

YouTube (chapters come from the description at serve time):

```bash
docker compose exec -T php bin/console dbal:run-sql "SELECT id, url FROM entry WHERE url LIKE '%youtube.com/watch%' AND content_html LIKE '%0:00%' ORDER BY id DESC LIMIT 5"
```

Open each entry's article view.

- [ ] **Step 3: Check the video**

Open the chapter list, play the video, and confirm:
- the playing row shows the faint track and an accent fill that grows to the right, behind time and title, and a right-aligned `m:ss` that counts down;
- seeking into another chapter (tap a row, and also drag the YouTube scrubber) moves track, fill and time left to the new row, and the old row is plain;
- a long chapter title wraps without pushing the time left out of the row, at desktop width and in the Mobile viewport;
- the last chapter fills toward the video's end. If it shows only the track, run `document.querySelector('.reader-chapters__row[aria-current]').style.cssText` in the console. An empty value means YouTube delivered no `duration`: report that to the planner;
- the fill is visible against the track in light and dark mode at default brightness. If it is not, report it with a screenshot. Do not change the 5% or the token on your own.

- [ ] **Step 4: Check the episode**

Play the episode from a chapter row and confirm the same things through the app's audio player. Then pause it.

- [ ] **Step 5: Scan the browser console**

`read_console_messages` with `onlyErrors: true`. Expected: no errors from the chapter code.

---

### Task 5: A clock icon in front of the time left

Added during implementation: the user chose the Material Symbols `schedule` icon, shown in front of the time left on the playing row.

**Files:**
- Modify: `frontend/src/app/reader/article/decorators/reader-chapters.ts`
- Modify: `frontend/src/app/reader/article/article-content/article-chapters.scss`
- Test: `frontend/src/app/reader/article/decorators/reader-chapters.spec.ts`

**Interfaces:**
- Consumes: `showProgress`, `clearProgress`, `toggle` and the `TIME_LEFT` span from Task 3.
- Produces: while a row shows progress, its `.reader-chapters__left` span holds two children: a `span.material-symbols-outlined` with text `schedule`, then a `span.reader-chapters__remaining` with `formatDuration(secondsLeft)`. A cleared row's `.reader-chapters__left` is empty, as before.

The icon and time are written together on each tick, so an idle row's DOM stays exactly as it is today. Every row's `textContent` stays `'0:00Intro'` etc. until it plays.

- [ ] **Step 1: Write the failing tests**

In `reader-chapters.spec.ts`, make `timeLeftOf` read the time span only. A cleared row has no such span, so default to `''`:

```ts
  function timeLeftOf(row: HTMLElement): string {
    return row.querySelector('.reader-chapters__remaining')?.textContent ?? '';
  }
```

Add after the test `'fills the playing chapter and shows its time left'`:

```ts
  it('puts a clock in front of the time left', () => {
    const host = document.createElement('div');
    const player = fakePlayer();
    addChapterList({ host, after: null }, { chapters: CHAPTERS, heading: 'Chapters' }, player);

    player.progressTo({ seconds: 128, durationSeconds: null });

    const timeLeft = rows(host)[0].querySelector('.reader-chapters__left');
    expect(Array.from(timeLeft?.children ?? []).map((child) => child.className)).toEqual([
      'material-symbols-outlined',
      'reader-chapters__remaining',
    ]);
    expect(timeLeft?.firstElementChild?.textContent).toBe('schedule');
  });

  it('removes the clock with the time left', () => {
    const host = document.createElement('div');
    const player = fakePlayer();
    addChapterList({ host, after: null }, { chapters: CHAPTERS, heading: 'Chapters' }, player);

    player.progressTo({ seconds: 128, durationSeconds: null });
    player.progressTo({ seconds: 300, durationSeconds: null });

    expect(rows(host)[0].querySelector('.reader-chapters__left')?.childElementCount).toBe(0);
  });
```

Run Prettier on the spec. All other progress tests keep their expectations: `timeLeftOf` now returns `''` for a cleared row, as before.

- [ ] **Step 2: Run the tests to verify they fail**

Run: `docker compose exec -T frontend npm test -- src/app/reader/article/decorators/reader-chapters.spec.ts`
Expected: FAIL. There is no `.reader-chapters__remaining` span yet, so `timeLeftOf` returns `''` where `'2:08'`, `'3:20'` and `'1:04'` are expected, and the clock test finds no children.

- [ ] **Step 3: Implement**

In `reader-chapters.ts`, give the material icon one home. Add below `toggle`:

```ts
function materialIcon(name: string): HTMLSpanElement {
  const icon = document.createElement('span');
  icon.className = 'material-symbols-outlined';
  icon.setAttribute('aria-hidden', 'true');
  icon.textContent = name;
  return icon;
}
```

In `toggle`, replace the three lines that build `icon` with `const icon = materialIcon('chevron_right');`. `render()` still sets its text right after, so the toggle behaves exactly as before.

In `showProgress`, replace the `replaceChildren(formatDuration(...))` line with:

```ts
  row
    .querySelector(`.${TIME_LEFT}`)
    ?.replaceChildren(materialIcon('schedule'), remaining(progress.secondsLeft));
```

and add:

```ts
function remaining(seconds: number): HTMLSpanElement {
  const time = document.createElement('span');
  time.className = `${LIST}__remaining`;
  time.textContent = formatDuration(seconds);
  return time;
}
```

`clearProgress` stays as it is: `replaceChildren()` empties the span.

- [ ] **Step 4: Run the tests to verify they pass**

Run: `docker compose exec -T frontend npm test -- src/app/reader/article`
Expected: PASS, including `article-chapters.spec.ts`. That spec asserts the time and title spans separately, so the icon's ligature text does not affect it.

- [ ] **Step 5: Style the icon and keep the width steady**

In `article-chapters.scss`:
- Add `display: inline-flex;`, `align-items: center;`, `justify-content: flex-end;` and `gap: var(--space-0);` to the existing `.reader-chapters__left` rule, and replace its `margin-inline-start: auto;` with `margin-inline: auto calc(-1 * var(--space-1));` (Stylelint requires the shorthand).
- Delete the rule `:host ::ng-deep .reader-chapters__row[aria-current='true'] .reader-chapters__left { min-width: 6ch; text-align: end; }`.
- Add after the `.reader-chapters__left` rule:

```scss
:host ::ng-deep .reader-chapters__left .material-symbols-outlined {
  font-size: var(--icon-sm);
  margin-inline-end: var(--space-1);
}

:host ::ng-deep .reader-chapters__row[aria-current='true'] .reader-chapters__left {
  min-width: calc(5ch + var(--icon-sm) + var(--space-0) + var(--space-1));
}
```

The playing row's box (clock + time) reserves room for `10:00` and its contents sit at its right end. The box reaches `--space-1` into the row's right padding and the icon carries the same space after it, so the time sits a little further right while the clock stays where it was; the title's space does not change as the countdown ticks. The icon comes and goes with the time, once per chapter change.

- [ ] **Step 6: Gates**

Run (from `frontend/`): `npm run check && npx ng build`
Expected: both exit 0. `ng build` stays under the 14kB `anyComponentStyle` error budget.

- [ ] **Step 7: Commit**

```bash
git add frontend/src/app/reader/article/decorators/reader-chapters.ts frontend/src/app/reader/article/decorators/reader-chapters.spec.ts frontend/src/app/reader/article/article-content/article-chapters.scss
git commit -m "feat(#1814): put a clock in front of the chapter's time left"
```

---

### Task 6: A chapter tapped before the video is ready still plays

This task was added during implementation, from a user bug report. After a fresh page load, tapping a chapter does not start the video.

It was reproduced on :4200 (entry 655277). The tap's `seekTo`/`playVideo` went out at 2342 ms, but the player's first message (`initialDelivery`, then `onReady`) arrived at 2567 ms. YouTube drops commands that reach the embed before its API is listening, so the video stayed cued at 0:00. A tap after the player is ready works.

The fix: `YouTubeChapterPlayer` holds the start a tap asks for until the frame has sent its first message, then seeks and plays it. If there are several taps before then, only the last one is held. Any message from the frame counts as "ready", not only `onReady`. A player attached to a frame whose handshake an earlier player already did sees only `infoDelivery` messages, and must still become ready.

**Files:**
- Modify: `frontend/src/app/reader/chapters/youtube-chapter-player.ts`
- Test: `frontend/src/app/reader/chapters/youtube-chapter-player.spec.ts`

**Interfaces:** The `ChapterPlayer` contract stays unchanged. `playFrom` may now defer its commands until the frame first speaks.

- [ ] **Step 1: Write the failing tests**

In `youtube-chapter-player.spec.ts`, the existing `'seeks, then plays'` test needs a player that is already ready. Deliver a message before `playFrom`:

```ts
  it('seeks, then plays', () => {
    const { frame, posted } = frameWithWindow();
    const player = new YouTubeChapterPlayer(frame);
    delivery(frame, { event: 'onReady' });

    player.playFrom(256);

    expect(posted).toEqual([
      { event: 'command', func: 'seekTo', args: [256, true], id: 1, channel: 'widget' },
      { event: 'command', func: 'playVideo', args: [], id: 1, channel: 'widget' },
    ]);
    player.release();
  });
```

Add after it:

```ts
  it('holds a chapter tapped before the player is ready, then plays it', () => {
    const { frame, posted } = frameWithWindow();
    const player = new YouTubeChapterPlayer(frame);

    player.playFrom(256);
    expect(posted).toEqual([]);

    delivery(frame, { event: 'initialDelivery', info: { currentTime: 0 } });

    expect(posted).toEqual([
      { event: 'command', func: 'seekTo', args: [256, true], id: 1, channel: 'widget' },
      { event: 'command', func: 'playVideo', args: [], id: 1, channel: 'widget' },
    ]);
    player.release();
  });

  it('plays only the last chapter tapped before the player is ready', () => {
    const { frame, posted } = frameWithWindow();
    const player = new YouTubeChapterPlayer(frame);

    player.playFrom(10);
    player.playFrom(256);
    delivery(frame, { event: 'infoDelivery', info: { currentTime: 0 } });
    delivery(frame, { event: 'infoDelivery', info: { currentTime: 1 } });

    expect(posted).toEqual([
      { event: 'command', func: 'seekTo', args: [256, true], id: 1, channel: 'widget' },
      { event: 'command', func: 'playVideo', args: [], id: 1, channel: 'widget' },
    ]);
    player.release();
  });

  it('drops a held chapter once released', () => {
    const { frame, posted } = frameWithWindow();
    const player = new YouTubeChapterPlayer(frame);

    player.playFrom(256);
    player.release();
    delivery(frame, { event: 'onReady' });

    expect(posted).toEqual([]);
  });
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `docker compose exec -T frontend npm test -- src/app/reader/chapters/youtube-chapter-player.spec.ts`
Expected: FAIL. `playFrom` posts at once, so `'holds…'` and `'plays only the last…'` see the commands too early and too many times.

- [ ] **Step 3: Implement**

In `youtube-chapter-player.ts`, add two fields below `durationSeconds`:

```ts
  private ready = false;
  private heldStart: number | null = null;
```

At the top of `onMessage`, right after the origin/source guard, add `this.becomeReady();`.

Replace `playFrom` with:

```ts
  playFrom(seconds: number): void {
    if (!this.ready) {
      this.heldStart = seconds;
      return;
    }
    this.command('seekTo', [seconds, true]);
    this.command('playVideo', []);
  }
```

Add `this.heldStart = null;` to `release()`, then add a private method below `release()`:

```ts
  private becomeReady(): void {
    if (this.ready) return;
    this.ready = true;
    const start = this.heldStart;
    this.heldStart = null;
    if (start !== null) this.playFrom(start);
  }
```

`release()` already removes the message listener, so a released player never becomes ready. The `'drops a held chapter…'` test pins this.

- [ ] **Step 4: Run the tests to verify they pass**

Run: `docker compose exec -T frontend npm test -- src/app/reader/chapters src/app/reader/article`
Expected: PASS.

- [ ] **Step 5: Gates**

Run (from `frontend/`): `npm run check`
Expected: exit 0.

- [ ] **Step 6: Commit**

```bash
git add frontend/src/app/reader/chapters/youtube-chapter-player.ts frontend/src/app/reader/chapters/youtube-chapter-player.spec.ts
git commit -m "fix(#1814): play a chapter tapped before the video is ready"
```

---

### Task 7: A frame the chapters drive may start playback

Added during implementation, from a user bug report. Clicking a chapter in Brave seeks the video but does not play it.

- **Reproduced:** in the user's Brave on :4200 (entry 655268). The click's `seekTo` lands (the player reports 259 s), but `playVideo` is refused. The player goes unstarted (-1) → buffering (3) → unstarted (-1), and never reaches playing (1).
- **Cause confirmed:** setting `allow="autoplay"` on the iframe and reloading the frame made the same click reach playing (1). A cross-origin iframe without autoplay permission ignores a play command that comes from the parent page's click.

**The fix.** A frame whose URL carries the iframe-API suffix that `withPlayerApi` adds gets `allow="autoplay"`. Every other article embed keeps no autoplay permission, as `media-embeds.spec.ts` already asserts (#748). YouTube never starts on its own: only our `playVideo` on a chapter click starts it.

**Files:**
- Modify: `frontend/src/app/reader/media/media-embeds.ts`
- Test: `frontend/src/app/reader/media/media-embeds.spec.ts`

**Interfaces:** No signature changes. `embedFrame(url, title)` additionally sets `allow="autoplay"` when `url` is a player-API URL.

- [ ] **Step 1: Write the failing tests**

In `media-embeds.spec.ts`, inside `describe('upgradeMediaEmbeds', …)`, after the test `'applies the sandbox and referrer policy'`, add the test below. Use the file's existing `articleHost` helper: `host` is `(html) => articleHost(html, upgradeMediaEmbeds)`, so pass a decorator that hands `upgradeMediaEmbeds` the player-API mapping.

```ts
  it('lets a player the chapters drive start playback', () => {
    const element = articleHost(
      '<a href="https://www.youtube-nocookie.com/embed/aaaaaaaaaaa">x</a>',
      (target) =>
        upgradeMediaEmbeds(target, (url) => withPlayerApi(url, 'http://localhost:4200')),
    );

    expect(element.querySelector('iframe')?.getAttribute('allow')).toBe('autoplay');
  });
```

The existing test `'applies the sandbox and referrer policy'` stays as it is. It pins that an ordinary embed has no autoplay.

If `articleHost`'s decorator parameter has a different shape, adapt the call to it. Read its definition (imported at the top of the spec) rather than guessing, and keep the assertion.

- [ ] **Step 2: Run the tests to verify they fail**

Run: `docker compose exec -T frontend npm test -- src/app/reader/media/media-embeds.spec.ts`
Expected: FAIL. `allow` is `null`.

- [ ] **Step 3: Implement**

In `media-embeds.ts`, below `YOUTUBE_VIDEO_FRAME`, add:

```ts
// Without autoplay, a cross-origin player ignores the playVideo a chapter tap sends (Brave, #1814).
const PLAYER_API_FRAME = /^https:\/\/www\.youtube-nocookie\.com\/embed\/[A-Za-z0-9_-]{11}\?enablejsapi=1&origin=/;
```

In `embedFrame`, after the attribute loop and before `return frame;`, add:

```ts
  if (PLAYER_API_FRAME.test(url)) frame.setAttribute('allow', 'autoplay');
```

`PlayingEmbedDirective` still overrides `allow` with `'autoplay; fullscreen'` for its own frames, so it is unaffected.

- [ ] **Step 4: Run the tests to verify they pass**

Run: `docker compose exec -T frontend npm test -- src/app/reader/media src/app/reader/article`
Expected: PASS.

- [ ] **Step 5: Gates**

Run (from `frontend/`): `npm run check`
Expected: exit 0.

- [ ] **Step 6: Commit**

```bash
git add frontend/src/app/reader/media/media-embeds.ts frontend/src/app/reader/media/media-embeds.spec.ts
git commit -m "fix(#1814): let a video the chapters drive start playing"
```

---

## Self-review notes

- Spec coverage: fill on a faint track (Task 3 SCSS, in `article-chapters.scss`); time left right-aligned on the playing row (Task 3 tests 1–2, `margin-inline-start: auto`), hidden from the accessible name (Task 3 test 5); only the current row (selector `[aria-current='true']` + `mark` clears the left row); seeking moves it (Task 3 tests 2 and 4); unchanged before playback (no `aria-current`, empty time-left span is invisible, and only the current row reserves `min-width: 6ch` for it); last chapter to duration, track only while unknown (Task 2 tests 2–3, Task 3 test 3); YouTube duration from deliveries, audio from `duration()` (Task 1); `scaleX` via one custom property (Task 3 Step 5).
- Types: `PlaybackPosition { seconds, durationSeconds }` and `ChapterProgress { fraction, secondsLeft }` are used with the same names in Tasks 1–3; `chapterProgress(starts, index, position)` is called with that order in Task 3.
