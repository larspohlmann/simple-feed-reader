# Mount Angular components into the rendered article body (#1886) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** A reader UI inside the `[innerHTML]` article body can be a real Angular component. The chapter list under an embedded video becomes the first one, `app-embed-chapters`, built from the same `app-reader-panel` + `app-audio-chapters` as the header's episode list. Its hand-built DOM copy and duplicated SCSS are deleted.

**Architecture:** `ArticleIslands`, a small per-body registry constructed by `ArticleBody`, mounts a component after a body node:
- `createComponent` with `environmentInjector`, `elementInjector` and Angular 20 `bindings` (`inputBinding`);
- `ApplicationRef.attachView`;
- created inside the Angular zone;
- tagged `.reader-island`;
- rendered once synchronously.

`ArticleBody` destroys every mounted component in the per-render cleanup and on destroy, next to the existing releases. Decorators keep choosing *where* a widget goes. The body's bare-element typography (`:host ::ng-deep li`, `ol`, `a[href]` …) gets a `:where(:not(.reader-island, .reader-island *))` barrier, so a mounted component looks the same in the body as in the header.

**Tech Stack:** Angular 20.3 standalone components + signals (`createComponent`, `inputBinding`), Transloco, Jest (jsdom), Playwright.

**Spec:** GitHub issue #1886.

## Global Constraints

- Component styles in a sibling `.scss` (`styleUrl`); no hex colours and no ad-hoc `px`, tokens only (`npm run check` enforces).
- New components are standalone, use signals and `ChangeDetectionStrategy.OnPush`.
- Mounted components are created **inside** the Angular zone. `ArticleBody` runs its embed wiring in `runOutsideAngular`, and a template listener registered outside the zone would run no change detection.
- A mounted component never outlives the body it was mounted into. It is destroyed in `decorateEachRender`'s `onCleanup` and in `ArticleBody`'s `DestroyRef`.
- Behaviour of the embed list stays as it is:
  - collapsed by default, with a `<button>` toggle (fixed type scale inside `.text-scaled`);
  - a tapped row plays that chapter and is marked current at once;
  - the current row fills to `--chapter-progress` and shows its time left;
  - once the embed is the audio track (Listen), the list seeks the audio player.
- Comments: default to none (CLAUDE.md). Commit format `refactor(#1886): …`, no attribution lines.
- Frontend tests run in the container: `docker compose exec -T frontend npx jest <path>`. Never run two Jest runs at once. The gate is `docker compose exec -T frontend npm run check` plus `docker compose exec -T frontend npx ng build` (the check misses template errors).

## Decisions taken in this plan

- **Registry owns component lifetime; `ArticleChapters` owns the chapter players.**
  - `ArticleChapters.release()` releases the players only.
  - `ArticleIslands.releaseAll()` destroys and removes the components.
  - `ArticleBody` calls both, islands first.
- **The player becomes a signal input.** `followTrack` releases the old player and sets the new one on the mounted list. It no longer destroys and recreates the list, so an open list stays open when Listen hands it to the audio player. That is a small behaviour gain, and the only intended change besides the style barrier.
- **The heading comes from Transloco inside the component**, as `app-episode-chapters` does. `ChapterList.heading` goes, and `attachToFrame(frame, chapters)` takes the chapters directly.
- **The component host carries `class="reader-chapters"`.** Specs and the e2e that locate a list by `.reader-chapters`, and `.reader-cinema + .reader-chapters`, keep working.
- **Rows are built only while the panel is open** (`app-reader-panel`'s lazy `<ng-template>`). Specs that click a row first open the list.
- **The barrier also removes today's leak.** The body's `li { margin: var(--space-1) 0 }` currently spaces the embed list's rows. That leak goes, and the embed list then matches the header list's row rhythm.
- **Not doing:** merging `app-embed-chapters` and `app-episode-chapters`. They share a five-line template but get their playhead from different sources (a `ChapterPlayer` callback vs. `AudioPlayerService` signals). Two occurrences is not yet a refactor (CLAUDE.md DRY rule). The other 14 decorators also stay out of scope; each is its own follow-up.

## File structure

| File | Change |
|---|---|
| `frontend/src/app/reader/article/reader-view/article-islands.ts` + `.spec.ts` | **new**, the mount registry |
| `frontend/src/app/reader/article/embed-chapters/embed-chapters.component.{ts,html,scss,spec.ts}` | **new**, the chapter list under an embed |
| `frontend/src/app/reader/article/reader-view/article-chapters.ts` + spec | mounts `app-embed-chapters` through `ArticleIslands`; the player is a signal; `attachToFrame(frame, chapters)` |
| `frontend/src/app/reader/article/reader-view/article-body.service.ts` + spec | constructs `ArticleIslands`, releases it per render and on destroy; no `heading` |
| `frontend/src/app/reader/article/article-content/article-content.component.ts` | drops `./article-chapters.scss` from `styleUrls` |
| `frontend/src/app/reader/article/article-content/article-content.component.scss` | island barrier on every bare-element rule |
| `frontend/src/app/reader/article/reader-panel/reader-panel.component.scss` | takes the frame + toggle styles in, instead of the mixins |
| `frontend/src/app/reader/article/reader-view/reader-view.component.spec.ts` | only if a selector there breaks (line ~2254 queries `.content .reader-chapters`, which stays valid) |
| `frontend/e2e/chapters.spec.ts` | video test opens the list via `.reader-panel__toggle` |
| `docs/design-language.md` | records the mounting mechanism and the barrier; removes the "body decoration" notes for the chapter list |
| `frontend/src/app/reader/shell/audio-player-bar/audio-chapters/audio-chapters.component.scss` | takes the row styles in, instead of the mixin |
| **delete** | `decorators/reader-chapters.ts`, `decorators/reader-chapters.spec.ts`, `article-content/article-chapters.scss`, `frontend/src/app/theme/_reader-panel.scss`, `frontend/src/app/reader/chapters/_chapter-rows.scss` |

All paths below are relative to `frontend/src/app/reader/article/` unless they start with `frontend/` or `docs/`.

---

### Task 1: `ArticleIslands`, the mount registry

**Files:**
- Create: `reader-view/article-islands.ts`
- Create: `reader-view/article-islands.spec.ts`

**Interfaces:**
- Produces: `class ArticleIslands { constructor(injector: Injector); mountAfter<C>(anchor: Element, component: Type<C>, bindings: Binding[]): ComponentRef<C>; releaseAll(): void }`, and the exported constant `READER_ISLAND = 'reader-island'`.

- [ ] **Step 1: Write the failing spec** (`reader-view/article-islands.spec.ts`)

```ts
import {
  ChangeDetectionStrategy,
  Component,
  DestroyRef,
  Injector,
  NgZone,
  inject,
  input,
  inputBinding,
  signal,
} from '@angular/core';
import { TestBed } from '@angular/core/testing';
import { ArticleIslands, READER_ISLAND } from './article-islands';

let destroyed = 0;
let clickedInZone: boolean | null = null;

@Component({
  selector: 'app-island-probe',
  template: '<button type="button" (click)="clicked()">{{ label() }}</button>',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
class IslandProbeComponent {
  readonly label = input.required<string>();

  constructor() {
    inject(DestroyRef).onDestroy(() => destroyed++);
  }

  protected clicked(): void {
    clickedInZone = NgZone.isInAngularZone();
  }
}

function bodyWithAnchor(): { host: HTMLElement; anchor: HTMLElement } {
  const host = document.createElement('div');
  host.innerHTML = '<div class="anchor"></div><p>Notes</p>';
  return { host, anchor: host.querySelector<HTMLElement>('.anchor')! };
}

describe('ArticleIslands', () => {
  beforeEach(() => {
    destroyed = 0;
    clickedInZone = null;
  });

  function islands(): ArticleIslands {
    return new ArticleIslands(TestBed.inject(Injector));
  }

  it('mounts the component right after its anchor, rendered and marked as an island', () => {
    const { host, anchor } = bodyWithAnchor();

    islands().mountAfter(anchor, IslandProbeComponent, [inputBinding('label', () => 'Chapters')]);

    const mounted = host.children[1];
    expect(mounted.tagName).toBe('APP-ISLAND-PROBE');
    expect(mounted.classList).toContain(READER_ISLAND);
    expect(mounted.textContent).toBe('Chapters');
  });

  it('follows a bound signal', () => {
    const { host, anchor } = bodyWithAnchor();
    const label = signal('Chapters');

    islands().mountAfter(anchor, IslandProbeComponent, [inputBinding('label', label)]);
    label.set('Kapitel');
    TestBed.tick();

    expect(host.querySelector('app-island-probe')!.textContent).toBe('Kapitel');
  });

  it('runs its listeners in the Angular zone even when mounted outside it', () => {
    const { host, anchor } = bodyWithAnchor();
    const registry = islands();

    TestBed.inject(NgZone).runOutsideAngular(() =>
      registry.mountAfter(anchor, IslandProbeComponent, [inputBinding('label', () => 'x')]),
    );
    host.querySelector('button')!.click();

    expect(clickedInZone).toBe(true);
  });

  it('destroys and removes every mounted component on release', () => {
    const { host, anchor } = bodyWithAnchor();
    const registry = islands();
    registry.mountAfter(anchor, IslandProbeComponent, [inputBinding('label', () => 'a')]);
    registry.mountAfter(anchor, IslandProbeComponent, [inputBinding('label', () => 'b')]);

    registry.releaseAll();

    expect(destroyed).toBe(2);
    expect(host.querySelector('app-island-probe')).toBeNull();
  });

  it('releases a component whose body was already swapped out', () => {
    const { host, anchor } = bodyWithAnchor();
    const registry = islands();
    registry.mountAfter(anchor, IslandProbeComponent, [inputBinding('label', () => 'a')]);
    host.innerHTML = '<p>Next article</p>';

    registry.releaseAll();

    expect(destroyed).toBe(1);
    expect(host.innerHTML).toBe('<p>Next article</p>');
  });

  it('releases nothing twice', () => {
    const { anchor } = bodyWithAnchor();
    const registry = islands();
    registry.mountAfter(anchor, IslandProbeComponent, [inputBinding('label', () => 'a')]);

    registry.releaseAll();
    registry.releaseAll();

    expect(destroyed).toBe(1);
  });
});
```

- [ ] **Step 2: Run it to see it fail**

Run: `docker compose exec -T frontend npx jest src/app/reader/article/reader-view/article-islands.spec.ts`
Expected: FAIL, `Cannot find module './article-islands'`.

- [ ] **Step 3: Implement** (`reader-view/article-islands.ts`)

```ts
import {
  ApplicationRef,
  Binding,
  ComponentRef,
  EnvironmentInjector,
  Injector,
  NgZone,
  Type,
  createComponent,
} from '@angular/core';

export const READER_ISLAND = 'reader-island';

/** Angular components mounted into the rendered article body, each living until the next render (#1886). */
export class ArticleIslands {
  private mounted: ComponentRef<unknown>[] = [];

  constructor(private readonly injector: Injector) {}

  mountAfter<C>(anchor: Element, component: Type<C>, bindings: Binding[]): ComponentRef<C> {
    return this.injector.get(NgZone).run(() => {
      const ref = createComponent(component, {
        environmentInjector: this.injector.get(EnvironmentInjector),
        elementInjector: this.injector,
        bindings,
      });
      const host = hostOf(ref);
      host.classList.add(READER_ISLAND);
      anchor.after(host);
      this.injector.get(ApplicationRef).attachView(ref.hostView);
      ref.changeDetectorRef.detectChanges();
      this.mounted.push(ref);
      return ref;
    });
  }

  releaseAll(): void {
    for (const ref of this.mounted) {
      const host = hostOf(ref);
      ref.destroy();
      host.remove();
    }
    this.mounted = [];
  }
}

function hostOf(ref: ComponentRef<unknown>): HTMLElement {
  return ref.location.nativeElement as HTMLElement;
}
```

- [ ] **Step 4: Run it to see it pass**

Run: `docker compose exec -T frontend npx jest src/app/reader/article/reader-view/article-islands.spec.ts`
Expected: PASS, 6 tests.

- [ ] **Step 5: Break-test the zone guard.** Temporarily replace `this.injector.get(NgZone).run(() => { … })` with a direct call of the arrow function's body (no `run`). Re-run the spec and confirm that "runs its listeners in the Angular zone" FAILS. Restore by re-applying the Step 3 code with Edit; do not use `git checkout --`.

- [ ] **Step 6: Commit**

```bash
git add frontend/src/app/reader/article/reader-view/article-islands.ts frontend/src/app/reader/article/reader-view/article-islands.spec.ts
git commit -m "refactor(#1886): mount Angular components into the article body"
```

---

### Task 2: `app-embed-chapters`

**Files:**
- Create: `embed-chapters/embed-chapters.component.ts`, `.html`, `.scss`, `.spec.ts`

**Interfaces:**
- Consumes:
  - `ChapterPlayer` (`../../chapters/chapter-player`: `playFrom(seconds)`, `watch(onPosition)`, `release()`);
  - `chapterPlayhead(starts, position)` and `ChapterPlayhead` (`../../audio/track-chapters`);
  - `ReaderPanelComponent` (`../reader-panel/reader-panel.component`);
  - `AudioChaptersComponent` (`../../shell/audio-player-bar/audio-chapters/audio-chapters.component`: inputs `chapters` and `playhead`, output `pick`).
- Produces: `EmbedChaptersComponent`, selector `app-embed-chapters`, with inputs `chapters: readonly ChapterDto[]` (required) and `player: ChapterPlayer` (required). Its host carries class `reader-chapters`.

- [ ] **Step 1: Write the failing spec** (`embed-chapters/embed-chapters.component.spec.ts`). It ports every behaviour of today's `decorators/reader-chapters.spec.ts`.

```ts
import { TestBed } from '@angular/core/testing';
import { provideTranslocoTesting } from '../../../../testing/transloco-testing';
import { ChapterPlayer, PlaybackPosition } from '../../chapters/chapter-player';
import { EmbedChaptersComponent } from './embed-chapters.component';

const CHAPTERS = [
  { startSeconds: 0, title: 'Intro' },
  { startSeconds: 256, title: 'How it works' },
  { startSeconds: 3723, title: 'Late' },
];

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
    positionTo: (seconds) => listener(seconds === null ? null : { seconds, durationSeconds: null }),
    progressTo: (position) => listener(position),
  };
}

function render(player: ChapterPlayer = fakePlayer()) {
  TestBed.configureTestingModule({ imports: [EmbedChaptersComponent, provideTranslocoTesting()] });
  const fixture = TestBed.createComponent(EmbedChaptersComponent);
  fixture.componentRef.setInput('chapters', CHAPTERS);
  fixture.componentRef.setInput('player', player);
  fixture.detectChanges();
  const element = fixture.nativeElement as HTMLElement;
  const open = (): void => {
    element.querySelector<HTMLButtonElement>('.reader-panel__toggle')!.click();
    fixture.detectChanges();
  };
  return { fixture, element, open };
}

const rows = (element: HTMLElement) =>
  Array.from(element.querySelectorAll<HTMLButtonElement>('.reader-chapters__row'));
const progressOf = (row: HTMLElement) => row.style.getPropertyValue('--chapter-progress');
const timeLeftOf = (row: HTMLElement) =>
  row.querySelector('.reader-chapters__remaining')?.textContent ?? '';

describe('EmbedChaptersComponent', () => {
  it('is a chapter list labelled Chapters, collapsed until its toggle opens it', () => {
    const { element, open } = render();
    const toggle = element.querySelector<HTMLButtonElement>('.reader-panel__toggle')!;

    expect(element.classList).toContain('reader-chapters');
    expect(element.querySelector('nav')!.getAttribute('aria-label')).toBe('Chapters');
    expect(toggle.textContent?.trim()).toContain('Chapters');
    expect(toggle.getAttribute('aria-expanded')).toBe('false');
    expect(rows(element)).toHaveLength(0);

    open();

    expect(toggle.getAttribute('aria-expanded')).toBe('true');
    expect(rows(element).map((row) => row.textContent?.replace(/\s+/g, ''))).toEqual([
      '0:00Intro',
      '4:16Howitworks',
      '1:02:03Late',
    ]);
  });

  it('plays from a tapped chapter and marks it at once', () => {
    const player = fakePlayer();
    const { fixture, element, open } = render(player);
    open();

    rows(element)[1].click();
    fixture.detectChanges();

    expect(player.playFrom).toHaveBeenCalledWith(256);
    expect(rows(element).map((row) => row.getAttribute('aria-current'))).toEqual([
      null,
      'true',
      null,
    ]);
  });

  it('follows the position the player reports', () => {
    const player = fakePlayer();
    const { fixture, element, open } = render(player);
    open();

    player.positionTo(4000);
    fixture.detectChanges();
    expect(rows(element)[2].getAttribute('aria-current')).toBe('true');

    player.positionTo(null);
    fixture.detectChanges();
    expect(rows(element).some((row) => row.hasAttribute('aria-current'))).toBe(false);
  });

  it('fills the playing chapter and shows its time left behind a clock', () => {
    const player = fakePlayer();
    const { fixture, element, open } = render(player);
    open();

    player.progressTo({ seconds: 128, durationSeconds: null });
    fixture.detectChanges();

    expect(rows(element).map(progressOf)).toEqual(['0.5', '', '']);
    expect(rows(element).map(timeLeftOf)).toEqual(['2:08', '', '']);
    expect(rows(element)[0].querySelector('.reader-chapters__left app-icon')).not.toBeNull();
  });

  it('moves fill and time left to the chapter playback moves into', () => {
    const player = fakePlayer();
    const { fixture, element, open } = render(player);
    open();

    player.progressTo({ seconds: 128, durationSeconds: 4000 });
    player.progressTo({ seconds: 3800, durationSeconds: 4000 });
    fixture.detectChanges();

    expect(rows(element).map(progressOf)).toEqual(['', '', '0.278']);
    expect(rows(element).map(timeLeftOf)).toEqual(['', '', '3:20']);
  });

  it('leaves the last chapter unfilled while the duration is unknown', () => {
    const player = fakePlayer();
    const { fixture, element, open } = render(player);
    open();

    player.progressTo({ seconds: 3800, durationSeconds: null });
    fixture.detectChanges();

    expect(rows(element)[2].getAttribute('aria-current')).toBe('true');
    expect(progressOf(rows(element)[2])).toBe('');
    expect(timeLeftOf(rows(element)[2])).toBe('');
  });

  it('clears fill and time left of a chapter left by a tap', () => {
    const player = fakePlayer();
    const { fixture, element, open } = render(player);
    open();

    player.progressTo({ seconds: 128, durationSeconds: null });
    rows(element)[1].click();
    fixture.detectChanges();

    expect(rows(element).map(progressOf)).toEqual(['', '', '']);
    expect(rows(element).map(timeLeftOf)).toEqual(['', '', '']);
  });

  it('follows a new player once it is handed one, starting unmarked', () => {
    const first = fakePlayer();
    const next = fakePlayer();
    const { fixture, element, open } = render(first);
    open();
    first.positionTo(300);
    fixture.detectChanges();

    fixture.componentRef.setInput('player', next);
    fixture.detectChanges();
    expect(rows(element).some((row) => row.hasAttribute('aria-current'))).toBe(false);
    expect(element.querySelector('.reader-panel__toggle')!.getAttribute('aria-expanded')).toBe(
      'true',
    );

    next.positionTo(4000);
    rows(element)[0].click();
    fixture.detectChanges();
    expect(next.playFrom).toHaveBeenCalledWith(0);
    expect(first.playFrom).not.toHaveBeenCalled();
  });
});
```

The `--chapter-progress` values are `'0.5'` and `'0.278'`, not today's `toFixed(4)` strings. `app-audio-chapters` binds the raw fraction (`[style.--chapter-progress]="shown?.fraction"`), the same value the header list already uses. If jsdom serialises the fraction differently, assert with `Number(progressOf(…))` and `toBeCloseTo(0.5)` / `toBeCloseTo(0.278, 3)` instead; never loosen it to "non-empty".

- [ ] **Step 2: Run it to see it fail**

Run: `docker compose exec -T frontend npx jest src/app/reader/article/embed-chapters`
Expected: FAIL, `Cannot find module './embed-chapters.component'`.

- [ ] **Step 3: Implement**

`embed-chapters/embed-chapters.component.ts`:

```ts
import {
  ChangeDetectionStrategy,
  Component,
  computed,
  effect,
  input,
  signal,
  untracked,
} from '@angular/core';
import { TranslocoPipe } from '@jsverse/transloco';
import { ChapterPlayhead, chapterPlayhead } from '../../audio/track-chapters';
import { ChapterPlayer, PlaybackPosition } from '../../chapters/chapter-player';
import { ChapterDto } from '../../models';
import { AudioChaptersComponent } from '../../shell/audio-player-bar/audio-chapters/audio-chapters.component';
import { ReaderPanelComponent } from '../reader-panel/reader-panel.component';

/** The chapter list under an embedded video or SoundCloud track, mounted into the body (#1808, #1886). */
@Component({
  selector: 'app-embed-chapters',
  imports: [TranslocoPipe, ReaderPanelComponent, AudioChaptersComponent],
  templateUrl: './embed-chapters.component.html',
  styleUrl: './embed-chapters.component.scss',
  changeDetection: ChangeDetectionStrategy.OnPush,
  host: { class: 'reader-chapters' },
})
export class EmbedChaptersComponent {
  readonly chapters = input.required<readonly ChapterDto[]>();
  readonly player = input.required<ChapterPlayer>();

  private readonly starts = computed(() => this.chapters().map((chapter) => chapter.startSeconds));

  protected readonly open = signal(false);
  protected readonly playhead = signal<ChapterPlayhead | null>(null);

  constructor() {
    effect(() => {
      const player = this.player();
      untracked(() => this.follow(player));
    });
  }

  protected play(index: number): void {
    this.player().playFrom(this.chapters()[index].startSeconds);
    this.playhead.set({ index, progress: null });
  }

  private follow(player: ChapterPlayer): void {
    this.playhead.set(null);
    player.watch((position) => this.playhead.set(this.playheadAt(position)));
  }

  private playheadAt(position: PlaybackPosition | null): ChapterPlayhead | null {
    return position === null ? null : chapterPlayhead(this.starts(), position);
  }
}
```

`embed-chapters/embed-chapters.component.html`:

```html
<nav [attr.aria-label]="'reader.chapters' | transloco">
  <app-reader-panel [label]="'reader.chapters' | transloco" [(open)]="open">
    <ng-template>
      <app-audio-chapters [chapters]="chapters()" [playhead]="playhead()" (pick)="play($event)" />
    </ng-template>
  </app-reader-panel>
</nav>
```

`embed-chapters/embed-chapters.component.scss` (today's `.reader-chapters` margin is `var(--space-3) 0 var(--space-4)`, and `app-reader-panel` already brings the `--space-4` bottom margin):

```scss
:host {
  display: block;
  margin-top: var(--space-3);
}
```

- [ ] **Step 4: Run it to see it pass**

Run: `docker compose exec -T frontend npx jest src/app/reader/article/embed-chapters`
Expected: PASS, 8 tests.

- [ ] **Step 5: Break-test the player swap.** Temporarily replace the constructor's `effect(…)` with `queueMicrotask(() => this.follow(this.player()));`, which follows only the first player. Confirm that "follows a new player once it is handed one" FAILS. Restore the Step 3 constructor with Edit.

- [ ] **Step 6: Commit**

```bash
git add frontend/src/app/reader/article/embed-chapters
git commit -m "refactor(#1886): add app-embed-chapters, the embed chapter list as a component"
```

---

### Task 3: Mount the embed list through `ArticleIslands` and delete the DOM builder

**Files:**
- Modify: `reader-view/article-chapters.ts`, `reader-view/article-chapters.spec.ts`
- Modify: `reader-view/article-body.service.ts`, `reader-view/article-body.service.spec.ts`
- Modify: `article-content/article-content.component.ts` (`styleUrls` becomes `['./article-content.component.scss']`)
- Modify: `frontend/e2e/chapters.spec.ts`
- Delete: `decorators/reader-chapters.ts`, `decorators/reader-chapters.spec.ts`, `article-content/article-chapters.scss`

**Interfaces:**
- Consumes: `ArticleIslands.mountAfter` / `releaseAll` (Task 1) and `EmbedChaptersComponent` with inputs `chapters` and `player` (Task 2).
- Produces:
  - `new ArticleChapters(audio: AudioPlayerService, islands: ArticleIslands, injector: Injector)`;
  - `attachToFrame(frame: HTMLIFrameElement, chapters: readonly ChapterDto[]): void`;
  - `followTrack(track: AudioTrack): void`;
  - `release(): void`, which releases the players only.

- [ ] **Step 1: Rewrite `reader-view/article-chapters.ts`**

```ts
import { Injector, WritableSignal, inputBinding, signal } from '@angular/core';
import { AudioPlayerService, AudioTrack } from '../../audio-player.service';
import { EmbedMedia, embedMediaOfFrame } from '../../audio/embed-media';
import { AudioChapterPlayer } from '../../chapters/audio-chapter-player';
import { ChapterPlayer } from '../../chapters/chapter-player';
import { SoundCloudChapterPlayer } from '../../chapters/soundcloud-chapter-player';
import { YouTubeChapterPlayer } from '../../chapters/youtube-chapter-player';
import { SOUNDCLOUD_PLAYER_URL } from '../../media/soundcloud-widget-protocol';
import { ChapterDto } from '../../models';
import { EmbedChaptersComponent } from '../embed-chapters/embed-chapters.component';
import { ArticleIslands } from './article-islands';

const API_PLAYER = 'iframe[src*="enablejsapi=1"]';
const CHAPTER_FRAMES = `${API_PLAYER}, iframe[src^="${SOUNDCLOUD_PLAYER_URL}"]`;

/** The video player frame a body's chapter list drives. */
export function apiPlayerFrame(host: HTMLElement): HTMLIFrameElement | null {
  return host.querySelector<HTMLIFrameElement>(API_PLAYER);
}

/** Every embed a chapter list can drive: YouTube API frames and SoundCloud widgets, in document order. */
export function chapterFrames(host: HTMLElement): HTMLIFrameElement[] {
  return Array.from(host.querySelectorAll<HTMLIFrameElement>(CHAPTER_FRAMES));
}

interface ShownList {
  readonly frame: HTMLIFrameElement;
  readonly mediaUrl: string;
  readonly player: WritableSignal<ChapterPlayer>;
}

/** A rendered body's chapter lists: one under each embed (#1808, #1824, #1886). */
export class ArticleChapters {
  private shown: ShownList[] = [];

  constructor(
    private readonly audio: AudioPlayerService,
    private readonly islands: ArticleIslands,
    private readonly injector: Injector,
  ) {}

  /** The first list attached to a frame stays its only one. */
  attachToFrame(frame: HTMLIFrameElement, chapters: readonly ChapterDto[]): void {
    const media = embedMediaOfFrame(frame);
    const after = frame.closest('.reader-cinema') ?? frame.closest('.reader-embed') ?? frame;
    if (!media || !after.parentElement || chapters.length === 0 || this.hasList(frame)) return;
    const player = signal(this.embedPlayer(frame, media));
    this.islands.mountAfter(after, EmbedChaptersComponent, [
      inputBinding('chapters', () => chapters),
      inputBinding('player', player),
    ]);
    this.shown.push({ frame, mediaUrl: media.url, player });
  }

  /** Once an embed is the audio player's track, its list seeks the player, not the paused frame (#1815). */
  followTrack(track: AudioTrack): void {
    for (const shown of this.shown.filter((each) => each.mediaUrl === track.url)) {
      shown.player().release();
      shown.player.set(this.audioPlayer(track));
    }
  }

  release(): void {
    for (const shown of this.shown) shown.player().release();
    this.shown = [];
  }

  private hasList(frame: HTMLIFrameElement): boolean {
    return this.shown.some((shown) => shown.frame === frame);
  }

  private embedPlayer(frame: HTMLIFrameElement, media: EmbedMedia): ChapterPlayer {
    const current = this.audio.current();
    if (current?.url === media.url) return this.audioPlayer(current);
    return media.kind === 'youtube'
      ? new YouTubeChapterPlayer(frame)
      : new SoundCloudChapterPlayer(frame);
  }

  private audioPlayer(track: AudioTrack): AudioChapterPlayer {
    return new AudioChapterPlayer(this.audio, track, this.injector);
  }
}
```

- [ ] **Step 2: Wire `reader-view/article-body.service.ts`**
  - Import `ArticleIslands` from `./article-islands`. Drop the `ChapterList` import from `../decorators/reader-chapters`.
  - Fields, in this order:
    ```ts
    private readonly islands = new ArticleIslands(this.injector);
    private readonly chapters = new ArticleChapters(this.audio, this.islands, this.injector);
    ```
  - In both the `DestroyRef.onDestroy` callback and the `onCleanup` of `decorateEachRender`, call `this.islands.releaseAll();` immediately before `this.chapters.release();`.
  - In `render`, replace the `list` object:
    ```ts
    this.zone.runOutsideAngular(() => {
      this.attachBodyChapters(host, chapterList, track);
      this.embedAudio.follow(controls);
      this.loadEmbedDetails(chapterFrames(host));
      this.media.follow(host);
    });
    ```
  - Rewrite the two helpers:
    ```ts
    /** Before any fetched list, so the body's chapters are the one list under their frame. */
    private attachBodyChapters(host: HTMLElement, chapters: ChapterDto[], track: AudioTrack | null): void {
      if (track) return;
      // Only decoration gives the player frame its API flag, so the embed is resolved after it.
      const chaptered = chapteredEmbedOf(host, chapters);
      if (!chaptered) return;
      // A video queued from a list row gains its chapters once its article opens (#1810).
      this.embedDetails.seed(chaptered.media.url, chapters);
      this.chapters.attachToFrame(chaptered.frame, chapters);
    }

    private loadEmbedDetails(frames: HTMLIFrameElement[]): void {
      this.embedDetails.load(frames, (frame, details) =>
        this.chapters.attachToFrame(frame, details.chapters),
      );
    }
    ```
  - If `this.i18n` is no longer read anywhere in the class after this, leave it: `decorateArticle(host, this.i18n, …)` still uses it.

- [ ] **Step 3: Drop `./article-chapters.scss`** from `ArticleContentComponent`'s `styleUrls`. Delete `article-content/article-chapters.scss`, `decorators/reader-chapters.ts` and `decorators/reader-chapters.spec.ts` with `git rm`.

- [ ] **Step 4: Update `reader-view/article-chapters.spec.ts`**
  - **Setup:**
    - `TestBed.configureTestingModule({ imports: [provideTranslocoTesting()], providers: [provideAccountIdentity()] })`; import `provideTranslocoTesting` from `../../../../testing/transloco-testing`.
    - `chapters()` builds `new ArticleChapters(TestBed.inject(AudioPlayerService), new ArticleIslands(TestBed.inject(Injector)), TestBed.inject(Injector))`.
  - **Calls:** every `attachToFrame(frame, { chapters: X, heading: 'Chapters' })` becomes `attachToFrame(frame, X)`.
  - **Opening lists:** add a helper and call it before any assertion on rows, titles or `aria-current`, and again after every row click or player event (rows exist only while open, and a signal change needs a tick):
    ```ts
    function openLists(host: HTMLElement): void {
      for (const toggle of Array.from(host.querySelectorAll<HTMLButtonElement>('.reader-panel__toggle'))) {
        if (toggle.getAttribute('aria-expanded') === 'false') toggle.click();
      }
      TestBed.tick();
    }
    ```
    `titles(host)` keeps reading `.reader-chapters__title`, which now needs `openLists(host)` first.
  - **'takes its list away on release'** (and the per-embed 'takes every list away on release') becomes **'stops following its players on release'**. Attach under a YouTube frame, `openLists`, call `release()`, then `deliverMessage(frame, …)` a position inside chapter B (use the `deliverMessage` helper the file already imports, with the same message shape as the existing per-embed YouTube tests in this file), then `TestBed.tick()`. Expect no row with `aria-current`. List removal is `ArticleIslands`' job and is covered in Task 1 and by the body spec.
  - **'hands a video list to the audio player, leaving it under the video':** after `followTrack`, `openLists(host)` and then click row 1. Every other assertion stays, including `host.children[1].classList.contains('reader-chapters')` and `posted` equal to `[]`.
  - **'follows the audio player once the video is its track':** `openLists(host)` before reading the current row (after the existing `TestBed.tick()`).
  - **The remaining tests:** apply the same two rules (open before reading rows, tick after events) and keep each test's assertions unchanged.

- [ ] **Step 5: Update `reader-view/article-body.service.spec.ts`**
  - Find every row-level query: `grep -n "reader-chapters__" frontend/src/app/reader/article/reader-view/article-body.service.spec.ts`. These are `.reader-chapters__row` and `.reader-chapters__title`.
  - In each test that has one, open the lists first with an `openLists(content)` helper like the one in Step 4, defined once at the top of the file.
  - Tick after a row click: call `TestBed.tick()` (or the `fixture.detectChanges()` the file already uses) before asserting.
  - Leave list-level assertions (`.reader-chapters` present, absent or counted, `classList` contains `reader-chapters`) untouched; they keep working because the component host carries the class.
  - Add one test to the chapter `describe`:
    ```ts
    it('destroys the list under a video when the HTML changes, leaving none behind', async () => {
      // render the same video-with-chapters body the 'drives a video with chapters through its API player' test uses
      // then change the HTML input to a body without embeds and let the effect run as 'decorates again when the rendered HTML changes' does
      expect(content.querySelectorAll('.reader-island')).toHaveLength(0);
      expect(document.querySelectorAll('app-embed-chapters')).toHaveLength(0);
    });
    ```
    Fill in the arrange and act lines from those two named tests in the same file. The assertions above are fixed.

- [ ] **Step 6: Update `frontend/e2e/chapters.spec.ts`**, test "a video lists its chapters under an API-enabled player" (lines ~75–94):
  - `article.locator('.reader-chapters__list')).toBeHidden()` → `article.locator('.reader-chapters__list')).toHaveCount(0)`. A collapsed panel builds no rows.
  - `article.locator('.reader-chapters__toggle').click()` → `article.locator('.reader-chapters .reader-panel__toggle').click()`.
  - Keep the rest, including `.reader-cinema + .reader-chapters` having count 1.

- [ ] **Step 7: Run the affected specs, one Jest run**

Run: `docker compose exec -T frontend npx jest src/app/reader/article src/app/reader/chapters src/app/reader/shell/audio-player-bar`
Expected: PASS. No spec imports `decorators/reader-chapters` (`grep -rn "reader-chapters'" frontend/src` prints nothing).

- [ ] **Step 8: Commit**

```bash
git add -A frontend/src/app/reader/article frontend/e2e/chapters.spec.ts
git commit -m "refactor(#1886): mount the embed chapter list as app-embed-chapters"
```

---

### Task 4: Style barrier, and fold the panel mixins back into the panel

**Files:**
- Modify: `article-content/article-content.component.scss`
- Modify: `reader-panel/reader-panel.component.scss`, `../shell/audio-player-bar/audio-chapters/audio-chapters.component.scss`
- Delete: `frontend/src/app/theme/_reader-panel.scss`, `frontend/src/app/reader/chapters/_chapter-rows.scss`

- [ ] **Step 1: Fold the mixins.** `theme/_reader-panel.scss` now has one includer, `reader-panel.component.scss`. Replace that file with:

```scss
@use '../../../theme/glass-rim';

:host {
  @include glass-rim.filled(var(--surface-1));

  display: block;
  margin: 0 0 var(--space-4);
  border-radius: var(--radius);
}

// A <button>, so inside .text-scaled it keeps the fixed UI type scale; a <summary> would scale.
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
  line-height: var(--lh-normal);
  cursor: pointer;
}
```

Then `git rm frontend/src/app/theme/_reader-panel.scss`. Check with `grep -rn "theme/reader-panel" frontend/src`, which must print nothing.

Do the same for `frontend/src/app/reader/chapters/_chapter-rows.scss`. With `article-chapters.scss` gone, its only includer is `shell/audio-player-bar/audio-chapters/audio-chapters.component.scss`; confirm that with `grep -rn "chapter-rows" frontend/src`.
- Move the rules inside `@mixin rows { … }` into that component's scss, unwrapped, in place of `@include chapter-rows.rows;`, and drop its `@use`.
- Keep the mixin file's second comment line ("The current row fills to `--chapter-progress` and ends in its time left.") above `.reader-chapters__row[aria-current='true']::before`. Drop the first line, about two lists.
- `git rm` the mixin file.

- [ ] **Step 2: Add the barrier** to `article-content.component.scss`.
  - Below the `@use` lines:
    ```scss
    // Mounted components (#1886) keep their own look: the body's element rules stop at them.
    $prose: ':where(:not(.reader-island, .reader-island *))';
    ```
  - Append `#{$prose}` to the **subject element** of every `:host ::ng-deep` rule whose subject is a bare element or an element-with-attribute selector. Before a pseudo-element, it goes in front of the pseudo:
    - `:host ::ng-deep p` → `:host ::ng-deep p#{$prose}`
    - `:host ::ng-deep :is(h1, h2, h3, h4, h5, h6)` → `:host ::ng-deep :is(h1, h2, h3, h4, h5, h6)#{$prose}`
    - `:host ::ng-deep h2::before` → `:host ::ng-deep h2#{$prose}::before`
    - `:host ::ng-deep hr + h2::before` → `:host ::ng-deep hr + h2#{$prose}::before`
    - `:host ::ng-deep li::marker` → `:host ::ng-deep li#{$prose}::marker`
    - `:host ::ng-deep :not(pre) > code` → `:host ::ng-deep :not(pre) > code#{$prose}`
    - `:host ::ng-deep a[href]` → `:host ::ng-deep a[href]#{$prose}`
  - Apply it to every such rule from line 16 (`p`) through line 326 (`a[href]`): p, the headings, `hr + h2`, `p.lead`, `:is(ul, ol)`, li, `li::marker`, blockquote, `blockquote p:last-child`, table, `:is(th, td)`, th, mark, `:is(sub, sup)`, dl, dt, dd, figure, figcaption, `:is(img, video, iframe)`, img, `img.reader-fill`, the two `a:has(> img[alt=…])` rules, `:not(pre) > code`, pre, `pre code`, hr and `a[href]`. Also apply it to `:host ::ng-deep audio, :host ::ng-deep video` and `:host ::ng-deep video` (lines ~366–378).
  - Leave class-subject rules (`.reader-*`, `details.reader-faq …`, `.hljs-*`, the slideshow block) unchanged: no mounted component carries those classes.
  - `:where()` adds no specificity, so the cascade between body rules is unchanged.

- [ ] **Step 3: Confirm the barrier compiles through Angular's emulated-encapsulation rewrite.** Run `docker compose exec -T frontend npx ng build`. Then `grep -o 'li:where(:not(.reader-island, .reader-island \*))' frontend/dist -r | head -1` must print a match (the built CSS may minify the spaces; if so, grep for `reader-island` inside the main styles chunk and read the `li` rule).

- [ ] **Step 4: Gate**

Run: `docker compose exec -T frontend npm run check`
Expected: PASS (Stylelint, Prettier, ESLint, tsc, Jest).

- [ ] **Step 5: Commit**

```bash
git add -A frontend/src/app/reader frontend/src/app/theme
git commit -m "refactor(#1886): keep the body's element styles out of mounted components"
```

---

### Task 5: Docs, and a live check on the real render

**Files:**
- Modify: `docs/design-language.md`

- [ ] **Step 1: `docs/design-language.md`**
  - **`<app-reader-panel>` entry (~line 886).** It is no longer header-only. Make the first sentence "The article's collapsible panel: the header's Contents, Chapters and Transcript, and the chapter list under an embed (`app-embed-chapters`, #1886)".
  - **`<app-audio-chapters>` entry (~lines 1560–1577).**
    - Replace "The row rules live in `reader/chapters/_chapter-rows.scss`, a mixin the article's `article-chapters.scss` and this component both include, so the two lists cannot drift apart;" with "The row rules live in the component's own scss, the one place rows are drawn;" (Task 4 folded the mixin in).
    - Add `app-embed-chapters` (the list under an embedded video or SoundCloud track) to "Used by".
    - Delete the "**Not for:** the lists under embedded videos…" paragraph.
  - **Conventions, "Reader-body decorators use a plain `<details>`…" (~line 2095).**
    - Remove the chapter list from the decorator list and drop `article-chapters.scss`.
    - Change "where an Angular component cannot mount" to point at the new way. Add a short paragraph after it:

      > **A component inside the article body is mounted, not decorated (#1886).** A decorator finds the place; `ArticleIslands` (`reader-view/article-islands.ts`) mounts the component after it (`mountAfter(anchor, Component, [inputBinding(…)])`), inside the Angular zone, tagged `.reader-island`, and destroys it before the next render. The body's element rules end at `.reader-island` (the `$prose` barrier in `article-content.component.scss`), so a mounted component looks as it does anywhere else. First user: `app-embed-chapters`.

- [ ] **Step 2: Live check on :4200** (see memory `verify-containers-are-current`: confirm the frontend container serves this branch, and restart it if `ng serve` shows a stale chunk).
  - Open an entry with a chaptered YouTube embed. Entry 655280 has 15 chapters, entry 655279 has 9; find a subscription URL with `/api/entries/655280`, or the reader's search.
  - In the built-in browser (Mobile viewport if the Claude UA is bot-blocked), measure with `getComputedStyle`, not `requestAnimationFrame`, which hangs in a hidden pane:
    1. `app-embed-chapters` sits directly after `.reader-cinema` and has classes `reader-chapters reader-island`.
    2. At text scale 0.9, 1.1 and 1.3, its `.reader-panel__toggle` has the same `font-size`, `line-height` and `offsetHeight` as `app-reader-toc .reader-panel__toggle` (expected: 13px / 19.5px / 36px, as measured for #1880).
    3. After opening, every `.reader-chapters__list > li` has `margin-top` and `margin-bottom` of `0px` (the barrier works) and `list-style-type: none`.
    4. Click row 2: the video seeks there (YouTube posts `seekTo`), and the row gets `aria-current="true"` and then a progress fill.
    5. Toggle Reader/Original, then open the next article. `document.querySelectorAll('app-embed-chapters').length` equals the number of lists visible on the new page (0 for an article without embeds). No console errors.
    6. Listen on the video while the list is open: the list stays open and its rows now seek the audio player.
  - If row `line-height` in the body differs from the episode list in the header (both should read `--lh-normal` × `--fs-sm`), set `line-height: var(--lh-normal)` on `:host` in `embed-chapters.component.scss`, commit it, and re-measure.

- [ ] **Step 3: e2e**

Run (from `frontend/`, with the Docker stack up from this checkout): `npx playwright test e2e/chapters.spec.ts`
Expected: 2 passed.

- [ ] **Step 4: Commit**

```bash
git add docs/design-language.md
git commit -m "docs(#1886): record mounting components into the article body"
```

- [ ] **Step 5: Final gate, then scan the dev log.** Run `docker compose exec -T frontend npm run check` and `docker compose exec -T frontend npx ng build`; both green. This is frontend-only work; the backend log scan is not needed.
