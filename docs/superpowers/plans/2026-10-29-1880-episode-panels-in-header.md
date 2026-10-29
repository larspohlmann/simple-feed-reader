# Episode chapters and transcript in the article header (#1880) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** The episode's Chapters list and Transcript render as Angular header components next to Contents, sharing one panel look, instead of DOM-injected sections at the top of the body.

**Architecture:** A new `app-reader-panel` (toggle button + projected body) carries the look that `app-reader-toc` has today; TOC, `app-episode-chapters` and `app-episode-transcript` all wrap it. Header order: Listen row → Chapters → Transcript → Contents → (lead image) → body. The body keeps only the chapter lists under embedded videos (#1824); everything that put an episode list or the transcript atop the body is deleted.

**Tech Stack:** Angular 20 standalone components + signals, Transloco, Jest (jsdom), Playwright.

**Spec:** GitHub issue #1880 (bugs: Transcript lands below the original-feed lead image; its `<summary>` toggle scales with the text-size slider while Contents' `<button>` does not).

## Global Constraints

- Component styles in a sibling `.scss` (`styleUrl`), no hex colours, no ad-hoc `px` — tokens only (`npm run check` enforces).
- Standalone components, signals, `ChangeDetectionStrategy.OnPush` for new components.
- The panel toggle is a `<button>`: inside `.text-scaled` a button keeps the fixed UI type scale (`styles/_text-size.scss`), which is the whole style fix. Do not use `<details>/<summary>` for these panels.
- Comments: default to none (CLAUDE.md). Commit format `fix(#1880): …`, no attribution lines.
- Frontend tests run in the container: `docker compose exec -T frontend npx jest <path>`; never two Jest runs at once.

## File structure

| File | Change |
|---|---|
| `frontend/src/app/reader/article/reader-panel/reader-panel.component.{ts,html,scss,spec.ts}` | **new** — the collapsible header panel |
| `frontend/src/app/reader/article/reader-toc/reader-toc.component.{ts,html,scss}` | wraps `app-reader-panel`; keeps only its list styles |
| `frontend/src/app/reader/audio/track-chapters.ts` | **new** pure `chapterPlayhead(starts, position)`; `TrackChapters.playhead` uses it |
| `frontend/src/app/reader/article/episode-chapters/episode-chapters.component.{ts,html,scss,spec.ts}` | **new** — episode chapter rows (reuses `AudioChaptersComponent`) |
| `frontend/src/app/reader/article/episode-transcript/episode-transcript.component.{ts,html,scss,spec.ts}` | **new** — transcript paragraphs with seek buttons |
| `frontend/src/app/reader/article/reader-view/reader-view.component.{ts,html}` + spec | renders the two new components above `app-reader-toc`; drops `[transcript]`/`[transcriptIsTheBody]` |
| `frontend/src/app/reader/article/reader-view/article-body.service.ts` + spec | drops transcript and the episode chapter list |
| `frontend/src/app/reader/article/reader-view/article-chapters.ts` + spec | drops `attachToEpisode`; every shown list has a frame |
| `frontend/src/app/reader/article/decorators/reader-chapters.ts` + spec | `addChapterList(after: Element, …)`; no prepend branch |
| `frontend/src/app/reader/article/article-content/article-content.component.ts` | drops `transcript`, `transcriptIsTheBody` inputs and `article-transcript.scss` |
| **delete** | `decorators/reader-transcript.ts`, `decorators/reader-transcript.spec.ts`, `reader-view/article-transcript.ts`, `reader-view/article-transcript.spec.ts`, `article-content/article-transcript.scss` |
| `frontend/e2e/chapters.spec.ts` | episode test locates `app-episode-chapters` |

---

### Task 1: `app-reader-panel`, with the TOC moved onto it

**Files:**
- Create: `frontend/src/app/reader/article/reader-panel/reader-panel.component.ts`, `.html`, `.scss`, `.spec.ts`
- Modify: `frontend/src/app/reader/article/reader-toc/reader-toc.component.{ts,html,scss}`
- Modify: `frontend/src/app/reader/article/reader-view/reader-view.component.spec.ts:553-582` (TOC selectors)

**Interfaces:**
- Produces: `ReaderPanelComponent`, selector `app-reader-panel`, `label = input.required<string>()`, `open = model(false)`; default `<ng-content />` shown only while open. Classes: host carries the panel chrome; toggle `.reader-panel__toggle`.

- [ ] **Step 1: Failing spec** `reader-panel.component.spec.ts`

```ts
import { Component, signal } from '@angular/core';
import { TestBed } from '@angular/core/testing';
import { ReaderPanelComponent } from './reader-panel.component';

@Component({
  imports: [ReaderPanelComponent],
  template: `<app-reader-panel label="Chapters" [(open)]="open"><p class="inside">Rows</p></app-reader-panel>`,
})
class HostComponent {
  readonly open = signal(false);
}

function render() {
  const fixture = TestBed.createComponent(HostComponent);
  fixture.detectChanges();
  const element = fixture.nativeElement as HTMLElement;
  const toggle = element.querySelector<HTMLButtonElement>('.reader-panel__toggle')!;
  return { fixture, element, toggle };
}

describe('ReaderPanelComponent', () => {
  it('starts collapsed, labelled by its toggle', () => {
    const { element, toggle } = render();

    expect(toggle.textContent!.trim()).toContain('Chapters');
    expect(toggle.getAttribute('aria-expanded')).toBe('false');
    expect(element.querySelector('.inside')).toBeNull();
  });

  it('shows its body once toggled, and tells the caller', () => {
    const { fixture, element, toggle } = render();

    toggle.click();
    fixture.detectChanges();

    expect(toggle.getAttribute('aria-expanded')).toBe('true');
    expect(element.querySelector('.inside')).not.toBeNull();
    expect(fixture.componentInstance.open()).toBe(true);
  });

  it('opens when the caller opens it', () => {
    const { fixture, element } = render();

    fixture.componentInstance.open.set(true);
    fixture.detectChanges();

    expect(element.querySelector('.inside')).not.toBeNull();
  });
});
```

- [ ] **Step 2:** `docker compose exec -T frontend npx jest src/app/reader/article/reader-panel` → FAIL (module not found).

- [ ] **Step 3: Implement**

`reader-panel.component.ts`:
```ts
import { ChangeDetectionStrategy, Component, input, model } from '@angular/core';
import { IconComponent } from '../../../shared/icon/icon.component';

/** A collapsible panel of the article header: Contents, Chapters, Transcript (#1880). */
@Component({
  selector: 'app-reader-panel',
  imports: [IconComponent],
  templateUrl: './reader-panel.component.html',
  styleUrl: './reader-panel.component.scss',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class ReaderPanelComponent {
  readonly label = input.required<string>();
  readonly open = model(false);
}
```

`reader-panel.component.html`:
```html
<button
  class="reader-panel__toggle"
  type="button"
  [attr.aria-expanded]="open()"
  (click)="open.set(!open())"
>
  <app-icon [name]="open() ? 'expand_more' : 'chevron_right'" size="md" />
  {{ label() }}
</button>
@if (open()) {
  <ng-content />
}
```

`reader-panel.component.scss` — the rules moved from `reader-toc.component.scss` (`.toc` → `:host`, `.toc-toggle` → `.reader-panel__toggle`):
```scss
@use '../../../theme/glass-rim';

:host {
  @include glass-rim.filled(var(--surface-1));

  display: block;
  margin: 0 0 var(--space-4);
  border-radius: var(--radius);
}

.reader-panel__toggle {
  display: flex;
  align-items: center;
  gap: var(--space-1);
  width: 100%;
  padding: var(--space-2) var(--space-3);
  background: none;
  border: none;
  color: var(--text-secondary);
  font: inherit;
  font-size: var(--fs-sm);
  cursor: pointer;
}
```

`reader-toc.component.html`:
```html
@if (visible()) {
  <nav class="toc" [attr.aria-label]="'reader.contents' | transloco">
    <app-reader-panel [label]="'reader.contents' | transloco" [(open)]="open">
      <ul class="toc-list">
        @for (item of entries(); track item.id) {
          <li [class]="'lvl-' + item.level">
            <button type="button" class="reads-as-text" (click)="jump.emit(item.id)">
              {{ item.text }}
            </button>
          </li>
        }
      </ul>
    </app-reader-panel>
  </nav>
}
```
`reader-toc.component.ts`: `imports: [TranslocoPipe, ReaderPanelComponent]` (drop `IconComponent`). `reader-toc.component.scss`: delete the `.toc` and `.toc-toggle` blocks and the `glass-rim` `@use`; keep `:host { display: block; }` and every `.toc-list` rule.

In `reader-view.component.spec.ts` (TOC describe) replace `'.toc-toggle'` with `'.reader-panel__toggle'`; `.toc` and `.toc-list` selectors stay valid.

- [ ] **Step 4:** run `npx jest src/app/reader/article/reader-panel src/app/reader/article/reader-view/reader-view.component.spec.ts -t "table of contents"` → PASS.
- [ ] **Step 5: Commit** `fix(#1880): one collapsible header panel, with the contents list on it`

---

### Task 2: `app-episode-chapters`

**Files:**
- Modify: `frontend/src/app/reader/audio/track-chapters.ts`
- Create: `frontend/src/app/reader/article/episode-chapters/episode-chapters.component.{ts,html,scss,spec.ts}`

**Interfaces:**
- Consumes: `ReaderPanelComponent` (Task 1); `AudioChaptersComponent` (`shell/audio-player-bar/audio-chapters`, inputs `chapters`, `playhead`, output `pick: number`); `AudioPlayerService.current()`, `.playbackPosition()`, `.playFrom(track, seconds)`.
- Produces: `chapterPlayhead(starts: readonly number[], position: PlaybackPosition): ChapterPlayhead | null` in `audio/track-chapters.ts`; `EpisodeChaptersComponent`, selector `app-episode-chapters`, inputs `chapters = input.required<readonly ChapterDto[]>()`, `track = input.required<AudioTrack>()`.

- [ ] **Step 1: `chapterPlayhead`** — add to `track-chapters.ts` below `ChapterPlayhead`:
```ts
/** Where `position` is among the chapters starting at `starts`; null before the first. */
export function chapterPlayhead(
  starts: readonly number[],
  position: PlaybackPosition,
): ChapterPlayhead | null {
  const index = currentChapterIndex(starts, position.seconds);
  return index === -1 ? null : { index, progress: chapterProgress(starts, index, position) };
}
```
and make `TrackChapters.playhead` `computed(() => chapterPlayhead(this.starts(), this.position()))`. The existing `track-chapters.spec.ts` covers it unchanged — run it: `npx jest src/app/reader/audio/track-chapters` → PASS.

- [ ] **Step 2: Failing spec** `episode-chapters.component.spec.ts`
```ts
import { TestBed } from '@angular/core/testing';
import { provideTranslocoTesting } from '../../../../testing/transloco-testing';
import { AudioPlayerService } from '../../audio-player.service';
import { ChapterDto } from '../../models';
import { EpisodeChaptersComponent } from './episode-chapters.component';

const CHAPTERS: ChapterDto[] = [
  { startSeconds: 0, title: 'Intro' },
  { startSeconds: 256, title: 'How it works' },
];
const EPISODE = {
  url: 'https://x.test/ep.mp3',
  title: 'Ep',
  faviconUrl: null,
  imageUrl: null,
  durationInSeconds: 600,
};

function render() {
  TestBed.configureTestingModule({ imports: [EpisodeChaptersComponent, provideTranslocoTesting()] });
  const fixture = TestBed.createComponent(EpisodeChaptersComponent);
  fixture.componentRef.setInput('chapters', CHAPTERS);
  fixture.componentRef.setInput('track', EPISODE);
  fixture.detectChanges();
  const element = fixture.nativeElement as HTMLElement;
  element.querySelector<HTMLButtonElement>('.reader-panel__toggle')!.click();
  fixture.detectChanges();
  return { fixture, element };
}

const rows = (element: HTMLElement) =>
  Array.from(element.querySelectorAll<HTMLButtonElement>('.reader-chapters__row'));

describe('EpisodeChaptersComponent', () => {
  it('lists the episode chapters in a labelled panel', () => {
    const { element } = render();

    expect(element.querySelector('nav')!.getAttribute('aria-label')).toBe('Chapters');
    expect(rows(element).map((row) => row.textContent!.replace(/\s+/g, ''))).toEqual([
      '0:00Intro',
      '4:16Howitworks',
    ]);
  });

  it('starts the episode at a picked chapter', () => {
    const playFrom = jest
      .spyOn(TestBed.inject(AudioPlayerService), 'playFrom')
      .mockImplementation(() => undefined);
    const { element } = render();

    rows(element)[1].click();

    expect(playFrom).toHaveBeenCalledWith(EPISODE, 256);
  });

  it('marks no chapter while another track plays', () => {
    const { element } = render();

    expect(rows(element).some((row) => row.hasAttribute('aria-current'))).toBe(false);
  });
});
```
(Check `provideTranslocoTesting` resolves `reader.chapters` to `Chapters`, as the article-body spec's `'Chapters'` assertion shows; if the path depth differs, take it from `app.spec.ts`.)

- [ ] **Step 3:** run it → FAIL.

- [ ] **Step 4: Implement**

`episode-chapters.component.ts`:
```ts
import { ChangeDetectionStrategy, Component, computed, inject, input, linkedSignal } from '@angular/core';
import { TranslocoPipe } from '@jsverse/transloco';
import { AudioPlayerService, AudioTrack } from '../../audio-player.service';
import { chapterPlayhead } from '../../audio/track-chapters';
import { ChapterDto } from '../../models';
import { AudioChaptersComponent } from '../../shell/audio-player-bar/audio-chapters/audio-chapters.component';
import { ReaderPanelComponent } from '../reader-panel/reader-panel.component';

/** The episode's chapters atop its article, following the player (#1808, #1880). */
@Component({
  selector: 'app-episode-chapters',
  imports: [TranslocoPipe, ReaderPanelComponent, AudioChaptersComponent],
  templateUrl: './episode-chapters.component.html',
  styleUrl: './episode-chapters.component.scss',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class EpisodeChaptersComponent {
  readonly chapters = input.required<readonly ChapterDto[]>();
  readonly track = input.required<AudioTrack>();

  private readonly player = inject(AudioPlayerService);
  private readonly starts = computed(() => this.chapters().map((chapter) => chapter.startSeconds));

  protected readonly open = linkedSignal({ source: this.chapters, computation: () => false });
  protected readonly playhead = computed(() =>
    this.player.current()?.url === this.track().url
      ? chapterPlayhead(this.starts(), this.player.playbackPosition())
      : null,
  );

  protected play(index: number): void {
    this.player.playFrom(this.track(), this.chapters()[index].startSeconds);
  }
}
```
`episode-chapters.component.html`:
```html
<nav [attr.aria-label]="'reader.chapters' | transloco">
  <app-reader-panel [label]="'reader.chapters' | transloco" [(open)]="open">
    <app-audio-chapters [chapters]="chapters()" [playhead]="playhead()" (pick)="play($event)" />
  </app-reader-panel>
</nav>
```
`episode-chapters.component.scss`: `:host { display: block; }`

- [ ] **Step 5:** run → PASS. **Commit** `fix(#1880): the episode's chapters as a header panel`

---

### Task 3: `app-episode-transcript`

**Files:**
- Create: `frontend/src/app/reader/article/episode-transcript/episode-transcript.component.{ts,html,scss,spec.ts}`

**Interfaces:**
- Consumes: `ReaderPanelComponent`; `formatDuration` (`reader/format`); i18n keys `reader.transcript`, `reader.transcriptPlayFrom` (`{ time }`).
- Produces: `EpisodeTranscriptComponent`, selector `app-episode-transcript`, inputs `transcript = input.required<TranscriptDto>()`, `track = input<AudioTrack | null>(null)`, `startOpen = input(false)`. Classes `.reader-transcript__paragraph`, `__time`, `__speaker`, `__text` (kept from the decorator).

- [ ] **Step 1: Failing spec** — port the behaviour of `decorators/reader-transcript.spec.ts` (read it before deleting it in Task 4) to the component: it renders nothing without paragraphs; a collapsed panel labelled `Transcript` with `lang` from `transcript.language`; open when `startOpen` is true; speaker and text per paragraph; a timestamp is a `button.reader-transcript__time` with aria-label `'Play from 1:15'`-style text calling `AudioPlayerService.playFrom(track, 75)` when a track is given, and a `span.reader-transcript__time` without one; a paragraph with `startSeconds: null` has no time. Use the `TRANSCRIPT` fixture from `reader-transcript.spec.ts` and the `EPISODE` track from Task 2, set inputs with `fixture.componentRef.setInput`, open the panel by clicking `.reader-panel__toggle`.

- [ ] **Step 2:** run → FAIL.

- [ ] **Step 3: Implement**

`episode-transcript.component.ts`:
```ts
import { ChangeDetectionStrategy, Component, inject, input, linkedSignal } from '@angular/core';
import { TranslocoPipe } from '@jsverse/transloco';
import { AudioPlayerService, AudioTrack } from '../../audio-player.service';
import { formatDuration } from '../../format';
import { TranscriptDto } from '../../models';
import { ReaderPanelComponent } from '../reader-panel/reader-panel.component';

/** The episode's transcript atop its article; with a track, each timestamp starts it there (#1809, #1880). */
@Component({
  selector: 'app-episode-transcript',
  imports: [TranslocoPipe, ReaderPanelComponent],
  templateUrl: './episode-transcript.component.html',
  styleUrl: './episode-transcript.component.scss',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class EpisodeTranscriptComponent {
  readonly transcript = input.required<TranscriptDto>();
  readonly track = input<AudioTrack | null>(null);
  readonly startOpen = input(false);

  private readonly player = inject(AudioPlayerService);

  protected readonly open = linkedSignal({
    source: () => ({ transcript: this.transcript(), startOpen: this.startOpen() }),
    computation: ({ startOpen }) => startOpen,
  });
  protected readonly format = formatDuration;

  protected playFrom(track: AudioTrack, seconds: number): void {
    this.player.playFrom(track, seconds);
  }
}
```
`episode-transcript.component.html`:
```html
@if (transcript().paragraphs.length) {
  <section [attr.aria-label]="'reader.transcript' | transloco" [attr.lang]="transcript().language">
    <app-reader-panel [label]="'reader.transcript' | transloco" [(open)]="open">
      @for (paragraph of transcript().paragraphs; track $index) {
        <p class="reader-transcript__paragraph">
          @if (paragraph.startSeconds !== null) {
            @let time = format(paragraph.startSeconds);
            @if (track(); as episode) {
              <button
                type="button"
                class="reader-transcript__time"
                [attr.aria-label]="'reader.transcriptPlayFrom' | transloco: { time }"
                (click)="playFrom(episode, paragraph.startSeconds)"
              >
                {{ time }}
              </button>
            } @else {
              <span class="reader-transcript__time">{{ time }}</span>
            }
          }
          @if (paragraph.speaker) {
            <strong class="reader-transcript__speaker">{{ paragraph.speaker }}</strong>
          }
          <span class="reader-transcript__text">{{ paragraph.text }}</span>
        </p>
      }
    </app-reader-panel>
  </section>
}
```
`episode-transcript.component.scss` — paragraph/time/speaker rules from `article-transcript.scss` without `::ng-deep`; the body keeps the article's reading type it had inside `app-article-content`:
```scss
:host {
  display: block;
}

app-reader-panel {
  font-size: var(--fs-read);
  line-height: 1.75;
}

.reader-transcript__paragraph {
  margin: 0;
  padding: var(--space-1) var(--space-3);
}

.reader-transcript__paragraph:last-child {
  padding-bottom: var(--space-3);
}

.reader-transcript__time {
  margin-inline-end: var(--space-2);
  color: var(--text-secondary);
  font-variant-numeric: tabular-nums;
}

button.reader-transcript__time {
  padding: 0;
  border: 0;
  background: none;
  font: inherit;
  cursor: pointer;
}

.reader-transcript__speaker {
  margin-inline-end: var(--space-1);
}
```
The toggle sets its own `font-size: var(--fs-sm)` on the button, so the panel's `--fs-read` does not reach it.

- [ ] **Step 4:** run → PASS. **Commit** `fix(#1880): the episode's transcript as a header panel`

---

### Task 4: Render them in the header; delete the body path

**Files:**
- Modify: `reader-view/reader-view.component.{html,ts,spec.ts}`, `reader-view/article-body.service.{ts,spec.ts}`, `reader-view/article-chapters.{ts,spec.ts}`, `decorators/reader-chapters.{ts,spec.ts}`, `article-content/article-content.component.ts`, `frontend/e2e/chapters.spec.ts`
- Delete: `decorators/reader-transcript.ts`, `decorators/reader-transcript.spec.ts`, `reader-view/article-transcript.ts`, `reader-view/article-transcript.spec.ts`, `article-content/article-transcript.scss`

- [ ] **Step 1: Failing view specs** in `reader-view.component.spec.ts`, next to the #1810 audio test. Add to `FakeEntryBodyService`:
```ts
  seedTranscript(id: number, html: string, transcript: TranscriptDto): void {
    this.stateFor(id).set({ status: 'ok', html, chapters: [], transcript });
  }
```
Tests (an `episode()` helper = `entry({ attachments: [{ url: 'https://x.test/ep.mp3', mimeType: 'audio/mpeg' }] })`; stub `HTMLMediaElement.prototype.pause/load` as the #1810 test does if the player is touched):
  1. episode with chapters (`fakeBody.seed(1, '<p>Notes</p>', CHAPTERS)`) → `app-episode-chapters` exists, `.content .reader-chapters` does not.
  2. transcript (`fakeBody.seedTranscript(1, '<p>Notes</p>', TRANSCRIPT)`) → `app-episode-transcript` exists and `.content .reader-transcript__paragraph` does not.
  3. header order: with chapters + transcript + 3 headings, `Array.from(element.querySelectorAll('app-episode-chapters, app-episode-transcript, app-reader-toc, app-article-content'), (node) => node.tagName.toLowerCase())` equals `['app-episode-chapters','app-episode-transcript','app-reader-toc','app-article-content']`.
  4. original hero after the panels: in original mode with an `originalHero` (copy the setup of "shows the original hero only after toggling"), plus a transcript, `app-episode-transcript` `compareDocumentPosition(hero)` has `Node.DOCUMENT_POSITION_FOLLOWING`.
  5. without an audio attachment, chapters render no `app-episode-chapters`.
  6. `transcriptIsTheBody` (`seedTranscript(1, '', TRANSCRIPT)`) → the transcript panel's toggle has `aria-expanded="true"`.

- [ ] **Step 2:** run → FAIL.

- [ ] **Step 3: Template** — in `reader-view.component.html`, between `<app-listen-row>`'s `@if` and `<app-reader-toc>`:
```html
      @if (audio.track(); as track) {
        @if (source.chapters().length) {
          <app-episode-chapters [chapters]="source.chapters()" [track]="track" />
        }
      }
      @if (source.transcript(); as transcript) {
        <app-episode-transcript
          [transcript]="transcript"
          [track]="audio.track()"
          [startOpen]="source.transcriptIsTheBody()"
        />
      }
```
Remove `[transcript]` and `[transcriptIsTheBody]` from `<app-article-content>`. Add both components to the view's `imports`.

- [ ] **Step 4: Delete the body path**
  - `article-content.component.ts`: drop the `transcript`/`transcriptIsTheBody` inputs, their `connect` keys, `TranscriptDto` import and `'./article-transcript.scss'`.
  - `article-body.service.ts`: drop `transcript`/`transcriptIsTheBody` from `ArticleBodyParts`, `transcript` from `EpisodeText` (it becomes just chapters — inline to a `ChapterDto[]` parameter if the interface is left with one field), the `transcript` computed, `transcriptSection` and its releases, the transcript `attach` block and the "Both are prepended" comment. `attachBodyChapters`: `if (track) return;` replaces `attachToEpisode`. Leave the `parts.audioTrack` input: it still keeps an episode's chapters off its notes' video.
  - `article-chapters.ts`: delete `attachToEpisode` and its comment; `ListToShow.frame` becomes `HTMLIFrameElement`; `place` becomes `after: Element`; `attachToFrame` keeps its `frame.closest(...)` lookup but no longer needs `host` except as the existing `!after.parentElement` guard. Class doc: drop "the episode's atop the body, and".
  - `decorators/reader-chapters.ts`: `addChapterList(after: Element, list: ChapterList, player: ChapterPlayer)`, ending in `after.after(list)`; delete `ChapterListPlace`. Update its spec: drop the prepend-into-host cases, pass the anchor element directly.
  - Delete the five files listed above.
  - `article-body.service.spec.ts`: delete `describe('its transcript')` and the `transcript`/`transcriptIsTheBody` signals; replace "lists an episode's chapters atop the body" with "lists no episode chapters in the body" (`audioTrack.set(EPISODE)`, `chapters.set(CHAPTERS)` → `content.querySelector('.reader-chapters')` is null); in "keeps an episode's chapters on the episode when its notes embed a video" assert `content.querySelector('.reader-chapters')` is null instead of `firstElementChild`. Fix `article-chapters.spec.ts` cases that call `attachToEpisode` (delete those; keep the `followTrack` case on a frame list).
  - `e2e/chapters.spec.ts` episode test: `app-article-content .reader-chapters__toggle` → `app-episode-chapters .reader-panel__toggle`, rows `app-episode-chapters .reader-chapters__row`.

- [ ] **Step 5:** `grep -rn "addTranscript\|ArticleTranscript\|attachToEpisode\|ChapterListPlace\|transcriptIsTheBody" frontend/src` — only `article-source.service.ts`, the view template and the new component remain. Run the reader-view, article-body, article-chapters and reader-chapters specs → PASS.
- [ ] **Step 6: Commit** `fix(#1880): render episode panels in the header, not atop the body`

---

### Task 5: Gates and live check

- [ ] `docker compose exec -T frontend npm run check` → green; `docker compose exec -T frontend npx ng build` → green (template errors `check` misses).
- [ ] Live on `http://localhost:4200` (Mobile viewport if bot-blocked), text-size slider at 90% and 110%:
  - `?subscription=2007&entry=671347-alte-exploits-in-neuen-appliances` — Chapters/Transcript sit above the lead image, below Listen.
  - `?subscription=2006&entry=670432-1-the-phreaky-world-of-pbx-hacking` — Transcript and Contents toggles measure the same font size, line height and height at both slider values; transcript timestamps seek the player; chapter rows follow it.
  - A YouTube entry with chapters still lists them under the player.
- [ ] `docker compose exec -T frontend npm run e2e -- chapters.spec.ts` (needs the stack up).
