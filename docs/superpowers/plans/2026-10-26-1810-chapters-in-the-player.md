# Chapters in the audio player (#1810) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** The chapters #1808 shows as a list in the article view travel with the track into the audio player: a chapter list and previous/next chapter controls in the big player, the current chapter's title in the mini bar and in the Media Session metadata (lock screen, AirPods), chapter marks on both scrub bars. A restored session keeps them.

**Architecture:** Chapters become part of `AudioTrack` (`chapters?: ChapterDto[]`), so they are saved and restored by `PlaylistStore` with nothing else changing. The builders that make a track from an entry (`toAudioTrack`, and `toEmbedTrack` from #1815) take the chapters the article view has loaded; a track queued from a list row, where no chapters are loaded, gets them back-filled when its article is opened (`AudioPlayerService.describeChapters`). The service derives the current chapter from its own `position` with `currentChapterIndex` (#1808) and exposes chapter steps that are seeks. Both scrubbers become one `<app-scrubber>` component, which draws the marks. The article view's chapter list stays as it is: `AudioChapterPlayer` already drives the service.

**Tech Stack:** Angular 20 (standalone, signals, OnPush), Jest (jsdom), SCSS with theme tokens. No backend change: `GET /api/entries/{id}` already serves `chapters`.

**Spec:** GitHub issue #1810.

## Alignment with #1815 (listen to a YouTube or SoundCloud embed)

The two plans are independent and may merge in either order. The seams they share, named the same way in `docs/superpowers/plans/2026-10-26-1815-listen-to-embeds.md`:

- `AudioTrack` is the one shape both build. This plan adds `chapters?: ChapterDto[]`; #1815 adds nothing. `toAudioTrack(entry, attachment, chapters = [])` here and `toEmbedTrack(entry, media, chapters = [])` there take the same trailing argument; whichever plan lands second adds the parameter to the other's builder and passes `source.chapters()` from the article view.
- The `entry` block of a track is built once, by `audioTrackEntry(entry)` in `audio-attachment.ts` (Task 1 in both plans); the second plan to land reuses it.
- `describeChapters(url, chapters)` (Task 2 here) back-fills a queued track. Once both plans are in, `ArticleBody` calls it for the chaptered player's url: the enclosure's, else the embed url of the frame `ArticleChapters` drives (`embedMediaOfFrame`, #1815 Task 1).
- Chapter steps and scrub marks seek through `AudioPlayerService.seek`, which #1815's `FramePlayback` honours (`currentTime` seeks once started and is kept as a start position before). Nothing here is provider-specific.
- After #1815's Listen, `ArticleChapters.followTrack(track)` hands the article's chapter list to the audio player, and the big player shows the same chapters from `track.chapters`.
- Media Session: the chapter title goes into the metadata the top frame sets (Task 3); browsers show the top frame's metadata for audio a child frame produces, so an embed track's lock-screen title follows its chapters too.

## Building on #1814 (chapter progress, merged)

#1814 gave the article's chapter list a filled row and a time left for the playing chapter. Its pieces are reused, not rebuilt:

- `PlaybackPosition { seconds, durationSeconds }` (`chapters/chapter-player.ts`) and `chapterProgress(starts, index, position)` (`chapters/current-chapter.ts`) compute a chapter's fraction and seconds left; the service exposes them (Task 2) and the big player's rows and strip read them (Task 6).
- The row markup (`reader-chapters__row` with time, title and an `aria-hidden` time-left span carrying a `schedule` icon) and the `--chapter-progress` custom property that fills the row live in `decorators/reader-chapters.ts` and `article-content/article-chapters.scss`. The big player's list keeps the same look: Task 6 moves the row rules into a shared partial both stylesheets `@use`.
- `AudioChapterPlayer` reports the player's duration with the position, so the article's list already shows progress for the enclosure; nothing in this plan changes it.

## Visual round (decided 2026-10-26)

The layouts were settled on a mockup of the three surfaces drawn with the app's tokens (https://claude.ai/artifact/66VxSS4hgJJg8QoTmzJbdq). What it fixed, beyond the issue text:

- **Mini bar title:** one line, the chapter title first and the track title after a middle dot, muted; a track without chapters keeps today's line.
- **Mini bar chapter steps:** two chevron buttons (`chevron_left`, `chevron_right`) flanking the transport group, shown only while the track has chapters; previous/next stay track steps on every surface, the lock screen included.
- **Mini bar on the phone** (below `bp.$bp-sm`): the artwork grows to `calc(var(--space-7) + var(--space-5))` and spans three rows beside it: the title line with the playlist and close buttons at its end, the transport centred under it, the scrubber with its times at the bottom. The 15-second buttons fit again and come back on the phone. Desktop keeps today's one row.
- **Big player:** the chapter strip sits under the transport; the chapter list is open by default and the strip's title collapses it.
- **Scrub marks:** notches, `var(--space-0)` wide in the surface colour the bar lies on, the bar's height, on both scrubbers.
- **Queue:** a chaptered track's row shows its chapter count after the duration and a chevron that expands the track's chapters under the row; the current track's chapters open with the queue, the others stay closed until tapped. A tapped chapter of another track plays that track from the chapter.

## Global Constraints

- CLAUDE.md applies: Clean Code names, no boolean flag parameters, guard clauses, comments only where a reader would otherwise get the code wrong, one line each.
- Component styles in the sibling `.scss`; no hex colours, no ad-hoc `px` (Stylelint). The scrubber keeps its native `<input type="range">` for keyboard and screen-reader seeking; marks are a decorative layer with `aria-hidden`.
- Chapter times are whole seconds (`ChapterDto.startSeconds`), as #1808 stores them.
- `AudioPlayerService` keeps holding no view state; the big player and the bar reflect its signals. The chapter list's open/closed state is the big player's.
- Frontend tests: `docker compose exec -T frontend npx jest <paths>` in the container; the gate is `cd frontend && npm run check` plus `npm run build`. Never two Jest processes at once in the container.
- Commits: `feat(#1810): …` / `refactor(#1810): …` / `test(#1810): …`, no attribution lines. Branch `feature/1810-chapters-in-player`. Merge `develop` in regularly.

## File Structure

| File | Responsibility |
|---|---|
| Modify `frontend/src/app/reader/audio/audio-track.ts` | `chapters?: ChapterDto[]` on `AudioTrack` |
| Modify `frontend/src/app/reader/audio/audio-attachment.ts` | `toAudioTrack(entry, attachment, chapters = [])`; `audioTrackEntry(entry)` extracted |
| Modify `frontend/src/app/reader/audio/entry-audio.ts` | Optional `chapters` source; the track carries them |
| Modify `frontend/src/app/reader/audio/playlist.ts` | `withChapters(playlist, url, chapters)`: the queued track with those chapters |
| Create `frontend/src/app/reader/audio/track-chapters.ts` | `previousChapterStart(starts, position, threshold)` / `nextChapterStart(starts, position)`: where a chapter step seeks to; `TrackChapters`: the chapter signals derived from the player's position |
| Create `frontend/src/app/reader/audio/playlist-persistence.ts`, `cached-end.ts` | The save policy (throttle, pagehide, clear) and the buffered-range computation, moved out of the service to keep it under ESLint's `max-lines` |
| Modify `frontend/src/app/reader/audio/media-session-controls.ts` | `showTrack(track, chapter)`: chapter title first, track title as artist |
| Modify `frontend/src/app/reader/audio-player.service.ts` | `chapters`, `chapterIndex`, `currentChapter`, `chapterProgress`, `hasPreviousChapter`, `hasNextChapter`; `previousChapter()`, `nextChapter()`, `playChapter(index)`, `playTrackChapter(trackIndex, chapterIndex)`, `describeChapters(url, chapters)`; metadata follows the chapter |
| Create `frontend/src/app/reader/shell/audio-player-bar/scrubber/scrubber.component.{ts,html,scss,spec.ts}` | `<app-scrubber>`: the one range input both surfaces use, with fill and chapter marks |
| Create `frontend/src/app/reader/audio/scrub-marks.ts` | `scrubMarks(chapters, duration)`: mark positions as percentages, the first chapter's mark at 0 omitted |
| Modify `frontend/src/app/reader/shell/audio-player-bar/audio-player-bar.component.{html,scss,spec.ts}` | Uses `<app-scrubber>`; the title line shows the current chapter; chapter chevrons beside the transport; the phone's three-row layout |
| Create `frontend/src/app/reader/shell/audio-player-bar/audio-chapters/audio-chapters.component.{ts,html,scss,spec.ts}` | A chapter list (rows with the fill and time left of #1814) for the big player and for a queue row |
| Modify `frontend/src/app/reader/shell/audio-player-bar/audio-playlist/audio-playlist.component.{ts,html,scss,spec.ts}` | Chapter count and chevron on a chaptered row; the expanded track's chapters nested under it |
| Create `frontend/src/app/reader/chapters/_chapter-rows.scss`; modify `frontend/src/app/reader/article/article-content/article-chapters.scss` | The chapter row rules both lists share |
| Modify `frontend/src/app/reader/shell/audio-player-bar/big-player/big-player.component.{ts,html,scss,spec.ts}` | Uses `<app-scrubber>`; chapter strip (previous, current title, next); chapter list toggle and section |
| Modify `frontend/src/testing/big-player-audio-signals.ts` | The chapter signals the surfaces read |
| Modify `frontend/src/app/reader/article/reader-view/reader-view.component.ts` | `EntryAudio` gets `source.chapters`; back-fills a queued track's chapters on open |
| Modify `frontend/public/i18n/en.json`, `de.json` | `reader.audioPlayer.previousChapter`, `nextChapter`, `chapterCount`, `chapterPosition`, `trackChapters` |
| Modify `docs/design-language.md` | §2: `<app-scrubber>`; §3: chapter marks and the chapter strip |

Specs beside every created or changed `.ts` file.

---

### Task 1: Chapters on the track

**Files:**
- Modify: `frontend/src/app/reader/audio/audio-track.ts`, `audio-attachment.ts`, `entry-audio.ts`, `playlist.ts`, `playlist.store.ts` (spec only)
- Modify: `frontend/src/app/reader/article/reader-view/reader-view.component.ts`
- Test: `audio-attachment.spec.ts`, `entry-audio.spec.ts`, `playlist.spec.ts`, `playlist.store.spec.ts`

**Interfaces:**

```ts
// audio-track.ts
export interface AudioTrack {
  …
  /** The track's chapters, ascending by start; absent on tracks saved before #1810 and on tracks queued where none were loaded. */
  chapters?: ChapterDto[];
}

// audio-attachment.ts
export function audioTrackEntry(entry: EntryDto): AudioTrackEntry;
export function toAudioTrack(entry: EntryDto, attachment: EntryAttachmentDto, chapters: ChapterDto[] = []): AudioTrack;
// `chapters` lands on the track only when non-empty, so a track without chapters stays byte-identical to today's.

// entry-audio.ts
export class EntryAudio {
  constructor(
    private readonly entry: () => EntryDto | null | undefined,
    private readonly player: AudioPlayerService,
    private readonly chapters: () => ChapterDto[] = () => [],
  ) {}
  // `track` computed passes chapters() to toAudioTrack
}

// playlist.ts
/** The playlist with the queued track at `url` carrying `chapters`; unchanged when it is not queued. */
export function withChapters(playlist: Playlist, url: string, chapters: ChapterDto[]): Playlist;
```

The reader view builds `new EntryAudio(this.entry, inject(AudioPlayerService), this.source.chapters)`. `entry-actions.component.ts` keeps its two-argument call.

- [ ] **Step 1: Write the failing specs**: `toAudioTrack` without chapters has no `chapters` key; with chapters carries them; `audioTrackEntry` equals the block `toAudioTrack` builds. `EntryAudio.track()` carries the chapters its source gives and re-computes when they load. `describe` replaces the chapters of the queued track only, keeps `index`, and returns the same playlist for an unknown url. `PlaylistStore` round-trips a track with chapters and loads one saved without them.
- [ ] **Step 2: Run them to verify they fail** — `docker compose exec -T frontend npx jest src/app/reader/audio`
- [ ] **Step 3: Implement**
- [ ] **Step 4: Specs green; `npm run check:static`**
- [ ] **Step 5: Commit** — `feat(#1810): chapters travel with the track into the playlist`

---

### Task 2: The service knows its chapter

**Files:**
- Create: `frontend/src/app/reader/audio/track-chapters.ts`, `.spec.ts`
- Modify: `frontend/src/app/reader/audio-player.service.ts`, `.spec.ts`
- Modify: `frontend/src/app/reader/article/reader-view/reader-view.component.ts`, `.spec.ts`

**Interfaces:**

```ts
// track-chapters.ts (ESLint allows 3 parameters, so the step is two functions, not a flag)
/** Where previous chapter seeks to, or null when there is nowhere to go: the current chapter's start
 *  once `position` is past it by more than `threshold` (like `previous()` on a track), else the one before. */
export function previousChapterStart(starts: readonly number[], position: number, threshold: number): number | null;
/** The following chapter's start, or null in the last chapter. */
export function nextChapterStart(starts: readonly number[], position: number): number | null;
/** The playing track's chapters, followed from the player's position: `index`, `current`, `progress`
 *  (#1814's helper; null without a chapter or while its end is unknown), `previousStart`, `nextStart`, `hasPrevious`, `hasNext`. */
export class TrackChapters { constructor(chapters: Signal<readonly ChapterDto[]>, position: Signal<PlaybackPosition>, restartThreshold: number) }

// audio-player.service.ts
readonly chapters = computed(() => this.current()?.chapters ?? []);
private readonly trackChapters = new TrackChapters(this.chapters, this.playbackPosition, RESTART_THRESHOLD_SECONDS);
readonly chapterIndex = this.trackChapters.index;
readonly currentChapter = this.trackChapters.current;
readonly chapterProgress = this.trackChapters.progress;
readonly hasPreviousChapter = this.trackChapters.hasPrevious;
readonly hasNextChapter = this.trackChapters.hasNext;

previousChapter(): void;          // seek to the target, if any
nextChapter(): void;
playChapter(index: number): void; // seek to the chapter's start and resume
/** Plays the queued track at `trackIndex` from its chapter `chapterIndex`: select, then seek once its metadata is known (like `playFrom`). */
playTrackChapter(trackIndex: number, chapterIndex: number): void;
/** Gives the queued track at `url` its chapters, for a track queued before its article was opened. */
describeChapters(url: string, chapters: ChapterDto[]): void;   // apply(withChapters(playlist, url, chapters)) without reloading: the url is unchanged, so `apply` sees no change and only persists
```

`previousChapter` uses `RESTART_THRESHOLD_SECONDS`, the threshold `previous()` already uses. `chapterIndex` is -1 before the first chapter or without chapters; the computeds read `_position`, so they move with playback and with a seek. `describeChapters` is idempotent (`withChapters` returns the same playlist when the track already carries those chapters), so the reader view's effect, which reads the playlist through `isQueued`, settles. The service sits at ESLint's `max-lines` (300): this task moves the save policy into `PlaylistPersistence`, the buffered-range loop into `cachedEnd` and the "only the playing element is heard" guard into `AudioDeck`; Task 3's effect will need more such room.

The reader view back-fills: an `effect` that, when `source.chapters()` is non-empty and the entry's enclosure url is queued (`player.isQueued`), calls `describeChapters(url, chapters)`. (With #1815, `ArticleBody` extends the same call to the chaptered embed's url.)

- [ ] **Step 1: Write the specs**: `previousChapterStart`/`nextChapterStart` for every branch (before the first chapter, inside the first, past the threshold, at the last chapter, no chapters). Service: `currentChapter` follows `timeupdate`; `chapterProgress` is null before metadata on the last chapter and fills in once the duration is known; `nextChapter` seeks to the next start and `hasNextChapter` turns false on the last; `previousChapter` restarts the chapter past the threshold and steps back within it; `playChapter` seeks and resumes a paused player; `playTrackChapter` on another track selects it and seeks once loaded, on the current track it behaves like `playChapter`; `describeChapters` reaches the saved playlist without reloading the element (`audio.src` untouched, `load` not called). Reader view: opening an article whose enclosure is queued describes its chapters once they load.
- [ ] **Step 2: Run to verify they fail**
- [ ] **Step 3: Implement**
- [ ] **Step 4: Specs green**
- [ ] **Step 5: Commit** — `feat(#1810): the player follows the current chapter and steps between chapters`

---

### Task 3: The chapter on the lock screen

**Files:**
- Modify: `frontend/src/app/reader/audio/media-session-controls.ts`, `.spec.ts`
- Modify: `frontend/src/app/reader/audio-player.service.ts`, `.spec.ts`

`showTrack(track: AudioTrack | null, chapter: ChapterDto | null)`: with a chapter, `title: chapter.title`, `artist: track.title`, `album: track.entry?.feedTitle ?? ''`; without, `title: track.title`, `artist: track.entry?.feedTitle ?? ''`. Artwork as today. The service replaces its `showTrack(track)` call in `load` with an `effect` on `current()` and `currentChapter()`, so the lock screen changes with the chapter. The `previoustrack`/`nexttrack` handlers stay track steps: iOS swaps the ±15 s buttons for those once a handler exists (see `setTrackSteps`), and turning them into chapter steps is a separate decision.

- [ ] **Step 1: Write the specs**: metadata with and without a chapter; the service updates the metadata as the chapter changes and clears it on stop.
- [ ] **Step 2: Run to verify they fail**
- [ ] **Step 3: Implement**
- [ ] **Step 4: Specs green**
- [ ] **Step 5: Commit** — `feat(#1810): the current chapter titles the Media Session metadata`

---

### Task 4: One scrubber with chapter marks

**Files:**
- Create: `frontend/src/app/reader/audio/scrub-marks.ts`, `.spec.ts`
- Create: `frontend/src/app/reader/shell/audio-player-bar/scrubber/scrubber.component.{ts,html,scss,spec.ts}`
- Modify: `frontend/src/app/reader/shell/audio-player-bar/audio-player-bar.component.{html,scss,spec.ts}`
- Modify: `frontend/src/app/reader/shell/audio-player-bar/big-player/big-player.component.{ts,html,scss,spec.ts}`

The two surfaces carry the same range input with the same fill variables; the third copy would be the marks, so the input becomes `<app-scrubber>`:

```ts
@Component({ selector: 'app-scrubber', … })
export class ScrubberComponent {
  readonly position = input.required<number>();
  readonly duration = input.required<number>();
  readonly buffered = input.required<number>();
  readonly chapters = input<ChapterDto[]>([]);
  readonly label = input.required<string>();          // the aria-label, translated by the caller
  readonly seek = output<number>();
  readonly dragging = output<boolean>();              // the big player thickens its bar while dragging
  protected readonly fill = computed(() => scrubFill(…));
  protected readonly marks = computed(() => scrubMarks(this.chapters(), this.duration()));
}
```

```ts
// scrub-marks.ts
/** Where chapter marks sit on a scrubber, as percentages; a chapter starting at 0 draws no mark. */
export function scrubMarks(chapters: readonly ChapterDto[], duration: number): string[];
```

Template: the `<input type="range">` as today, then `<div class="marks" aria-hidden="true">` with one `<span class="mark" [style.left]="mark">` per mark, positioned over the bar, `pointer-events: none`. Styles move from both components into `scrubber.component.scss`; the bar height variable (`--bar`) stays a host-settable custom property so the big player's `.dragging` thickening keeps working. A mark is a notch: `var(--space-0)` wide, the bar's height, in the surface colour the bar lies on (`var(--surface-1)` on both surfaces).

- [ ] **Step 1: Write the specs**: `scrubMarks` for chapters at 0, 90 and 300 over 600 s (`['15%', '50%']`), an unknown duration (`[]`), a chapter past the duration (clamped out). `ScrubberComponent` renders the fill variables, one mark per chapter and emits `seek` on input. Bar and big-player specs updated to find the scrubber by its component.
- [ ] **Step 2: Run to verify they fail**
- [ ] **Step 3: Implement**, replacing both inputs
- [ ] **Step 4: Specs green; `npm run stylelint`; `npm run build`**
- [ ] **Step 5: Commit** — `refactor(#1810): one scrubber for the bar and the big player, with chapter marks`

---

### Task 5: The chapter in the mini bar

**Files:**
- Modify: `frontend/src/app/reader/shell/audio-player-bar/audio-player-bar.component.{html,scss,spec.ts}`

The title line reads `<chapter title> · <track title>` when a chapter plays: the chapter in `.title`, the track title after it in a muted `.subtitle` span, the line still one row, clipped with an ellipsis. Without a chapter the line is the track title, as today. The scrubber gets `[chapters]="player.chapters()"`.

**Chapter chevrons:** while `player.chapters()` is non-empty, a `chevron_left` button before `previous` and a `chevron_right` button after `next` (`.ctrl.previous-chapter`, `.ctrl.next-chapter`, labels `reader.audioPlayer.previousChapter`/`nextChapter`), disabled with `hasPreviousChapter`/`hasNextChapter`, calling `previousChapter()`/`nextChapter()`. Previous/next keep stepping tracks.

**Phone layout:** the transport buttons (chevrons included) are wrapped in `.transport`, the playlist and close buttons in `.tail`; both are `display: contents` so the desktop row stays as it is. Below `bp.$bp-sm` `.controls` becomes a grid of three columns (`auto minmax(0, 1fr) auto`) and three rows: the artwork in column 1 spanning the rows at `calc(var(--space-7) + var(--space-5))` with `var(--radius)`; the title line in row 1 column 2 and `.tail` in row 1 column 3, its buttons with `var(--space-0)` vertical padding; `.transport` centred across columns 2 to 3 in row 2, pulled up by `var(--space-1)`; the scrubber row across columns 2 to 3 in row 3. The rule hiding `.back`/`.forward` on the phone goes. `.meta` becomes `display: contents` below the breakpoint so its title and scrubber rows place themselves.

- [ ] **Step 1: Write the specs**: the title line with and without a current chapter; the line changes as the chapter changes; the chevrons appear only with chapters, disable at the ends and call the service; the transport and tail wrappers exist (the grid itself is checked visually, jsdom has no media queries).
- [ ] **Step 2: Run to verify they fail**
- [ ] **Step 3: Implement**
- [ ] **Step 4: Specs green**
- [ ] **Step 5: Commit** — `feat(#1810): the mini bar names the current chapter and steps between chapters`

---

### Task 6: The chapter strip and list in the big player

**Files:**
- Create: `frontend/src/app/reader/shell/audio-player-bar/audio-chapters/audio-chapters.component.{ts,html,scss,spec.ts}`
- Modify: `frontend/src/app/reader/shell/audio-player-bar/big-player/big-player.component.{ts,html,scss,spec.ts}`
- Modify: `frontend/src/testing/big-player-audio-signals.ts`
- Modify: `frontend/public/i18n/en.json`, `de.json`

**Chapter strip**, under the transport and only when the track has chapters: previous-chapter button (`chevron_left`), the current chapter's title centred with `reader.audioPlayer.chapterPosition` ("Chapter {{ index }} of {{ count }}") and the chapter's time left (`schedule` icon, from `player.chapterProgress()`) muted beneath it, next-chapter button (`chevron_right`). The buttons disable with `hasPreviousChapter`/`hasNextChapter`; the title is a button that toggles the chapter list.

**Chapter list** (`<app-audio-chapters>`): the rows of the article's list as a component that reads nothing from the service, so a queue row can show another track's chapters with it (Task 7):

```ts
readonly chapters = input.required<ChapterDto[]>();
readonly currentIndex = input<number>(-1);            // -1: no row marked
readonly progress = input<ChapterProgress | null>(null);
readonly pick = output<number>();
```

Rows are `reader-chapters__row` with time, title and the `aria-hidden` time-left span, `aria-current="true"` on `currentIndex`, `--chapter-progress` set from `progress.fraction` and the time left (`schedule` icon, `formatDuration(secondsLeft)`) on the current row, exactly as `showProgress` draws them in `reader-chapters.ts`; a click emits `pick(index)`. The row rules move from `article-chapters.scss` into `chapters/_chapter-rows.scss`, which both stylesheets `@use`; the article stylesheet keeps only its `:host ::ng-deep` wrapping and the list frame. The big player binds `player.chapters()`, `player.chapterIndex()`, `player.chapterProgress()` and calls `player.playChapter` on `pick`, under a heading button `reader.audioPlayer.chapterCount` with a chevron, between the extras and the queue section. It is open by default: a local `chaptersShown` signal starts true and the strip's title and the heading toggle it; on the phone sheet it scrolls into view when reopened. On the card (desktop) it shows in place; the card already scrolls (`CdkScrollable`).

**i18n:** `reader.audioPlayer.previousChapter` ("Previous chapter" / "Vorheriges Kapitel"), `nextChapter` ("Next chapter" / "Nächstes Kapitel"), `chapterCount` ("Chapters ({{ count }})" / "Kapitel ({{ count }})"), `chapterPosition` ("Chapter {{ index }} of {{ count }}" / "Kapitel {{ index }} von {{ count }}"). The list's heading reuses `reader.chapters`.

`bigPlayerAudioSignals()` grows `chapters`, `chapterIndex`, `currentChapter`, `hasPreviousChapter`, `hasNextChapter`; the big-player stub grows `previousChapter`, `nextChapter`, `playChapter` mocks.

- [ ] **Step 1: Write the specs**: no strip and no list without chapters; the strip shows the current title, position and time left; previous/next call the service and disable at the ends; the list is open at first and the title toggles it; `<app-audio-chapters>` marks `currentIndex`, fills it from `progress`, shows its time left and emits `pick` for a tapped row; the big player plays the picked chapter; the sheet scrolls the list into view when reopened. `article-chapters.scss` still renders the article list unchanged after the move (the existing `reader-chapters.spec.ts` and a visual check).
- [ ] **Step 2: Run to verify they fail**
- [ ] **Step 3: Implement**
- [ ] **Step 4: Specs green; translation-key spec green; `npm run build`**
- [ ] **Step 5: Commit** — `feat(#1810): chapter strip and chapter list in the big player`

---

### Task 7: Chapters in the queue

**Files:**
- Modify: `frontend/src/app/reader/shell/audio-player-bar/audio-playlist/audio-playlist.component.{ts,html,scss,spec.ts}`
- Modify: `frontend/public/i18n/en.json`, `de.json`

A chaptered row (`row.track.chapters?.length`) shows, after `.row-time`, a `.row-chapters` span (`list` icon at `--icon-xs`, the count, `aria-label` from `reader.audioPlayer.chapterCount`) and, before the move buttons, an `appIconButton` `.expand` (`chevron_right`, `expand_more` when open; `aria-expanded`; label `reader.audioPlayer.trackChapters`: "Chapters of {{ title }}" / "Kapitel von {{ title }}"). A local `expanded = signal<Set<string>>` of track urls starts with the current track's url whenever the queue is created, so the playing track's chapters open with the queue and the others wait for a tap. An expanded row is followed, inside the same `<li cdkDrag>` so it drags along, by `<app-audio-chapters class="nested">` with `[chapters]="queued.chapters"`, `[currentIndex]` and `[progress]` from the service for the current track and `-1`/`null` for any other, `(pick)="player.playTrackChapter(index, $event)"`. The nested list is indented past the handle and the artwork (`padding-inline-start: calc(var(--space-4) + var(--icon-sm) + 2 * var(--space-1) + var(--icon-lg) + var(--space-2))`) and sets `font-size: var(--fs-xs)` on its rows. It is not a `cdkDropList` (nested drop lists break cross-list drag, CLAUDE.md).

- [ ] **Step 1: Write the specs**: a row without chapters shows neither count nor chevron; a chaptered row shows the count; the current track's list is open when the queue renders and another track's is closed; the chevron opens and closes a row; a tapped chapter of another track calls `playTrackChapter` with both indexes; the current row's nested list carries the service's index and progress.
- [ ] **Step 2: Run to verify they fail**
- [ ] **Step 3: Implement**
- [ ] **Step 4: Specs green; translation-key spec green; `npm run build`**
- [ ] **Step 5: Commit** — `feat(#1810): the queue opens a track's chapters`

---

### Task 8: Docs and gate

**Files:**
- Modify: `docs/design-language.md`

- [ ] **Step 1: Design language** — §2 catalog entry for `<app-scrubber>` (inputs, outputs, the `--bar` custom property, the marks layer); §3 a note on the chapter strip, the mini bar's phone layout, the queue's expandable rows, and that chapters are view-less data on the track.
- [ ] **Step 2:** `cd frontend && npm run check && npm run build`
- [ ] **Step 3: Manual pass** in the Docker stack, desktop and a phone: a podcast with Podlove chapters and a YouTube entry with description chapters (after #1815, sent to the player): lock-screen title follows the chapter; AirPods previous/next still step tracks; marks line up with the list's times; previous chapter restarts the chapter past the threshold; a track queued from a list row gains its chapters when its article is opened; a reload keeps the chapters; the phone mini bar lays out as the mockup (artwork spanning three rows, chevrons beside the transport); the queue opens the current track's chapters and a tapped chapter of a queued track starts it there.
- [ ] **Step 4:** Merge `develop`, open the PR with `Closes #1810`.
