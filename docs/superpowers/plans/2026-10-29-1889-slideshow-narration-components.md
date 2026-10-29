# Slideshow and narration as mounted components (#1889) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** The slideshow's controls and the narration player's toggle become Angular components, mounted into the article body through `ArticleIslands` (#1886). That replaces the DOM builders in `decorators/reader-slideshow.ts` and `decorators/reader-narration.ts` and moves their control styles out of the body stylesheet. One shared helper also settles where chapter lists sit relative to player bars, which #1888 left open.

**Architecture:**
- **Control islands, not adoption.** The components *drive* the body's own nodes (the slideshow track and slides, the narration `<audio>`) and never move them.
  - Ruling against the issue's "adopt the nodes into the component" proposal: `ArticleBody` re-renders over the *same* DOM when only the chapters change. `ArticleIslands.releaseAll()` then removes every island host, and any body node moved inside a host would be removed with it, taking slides or audio out of the article.
  - With control islands the body nodes stay where the backend put them, and a fresh island picks their state back up from them, the way `app-player-bar` reads `reader-cinema--on`.
- **The narration toggle** is mounted *before* the audio. It needs `ArticleIslands.mountBefore`. It shows or hides the audio by toggling `reader-narration--open` on it, and seeds its open state from that class.
- **The slideshow controls** (prev, counter, next) are mounted after the track inside the figure. The component:
  - marks the figure ready;
  - owns the current slide as a signal seeded from the slide marked `aria-hidden="false"`;
  - applies the transform, `aria-hidden` and `inert`;
  - binds the arrow keys and the axis-locking swipe on the figure, with cleanup on destroy.
- **The shared helper:** `playerBoxOf(frame)` is the cinema box, else the embed box, else the frame. Chapter lists anchor after the player's bar when one follows the box, so under a non-cinema embed (SoundCloud) the order becomes embed, bar, list, the same as player, bar, list in a cinema box.

**Tech Stack:** Angular 20.3 standalone components + signals, Transloco, Jest (jsdom), Playwright.

**Spec:** GitHub issue #1889 (follows #1886, #1888).

## Global Constraints

- Component styles in a sibling `.scss` (`styleUrl`); no hex colours and no ad-hoc `px`, tokens only (`npm run check` enforces).
- New components are standalone, use signals and `ChangeDetectionStrategy.OnPush`.
- Mount only through `ArticleIslands`, which enters the Angular zone itself. `ArticleBody` already releases islands per render and on destroy; add no new lifecycle code there.
- **Body nodes are never moved into an island.** A component may set classes, attributes and styles on body nodes it is handed. A same-DOM re-render must leave the slides and the audio in the article.
- **Slideshow behaviour stays as it is:**
  - the first slide shows and the figure gets `reader-slideshow--ready`, `aria-roledescription="carousel"` and `tabindex="0"`;
  - each slide gets `aria-label="N / total"`;
  - the track is `translateX(-N*100%)`;
  - non-current slides get `aria-hidden="true"` and `inert`;
  - the counter is `aria-live="polite"`, with text from `reader.slideshowPosition`;
  - prev and next wrap around, keep their `‹`/`›` glyphs and take `aria-label`s from `reader.slideshowPrevious`/`reader.slideshowNext`;
  - ArrowLeft and ArrowRight step and `preventDefault`, other keys do nothing;
  - a leftward swipe steps forward and a mostly-vertical one is ignored;
  - a horizontal drag is claimed (`stopPropagation` + `preventDefault` on `touchmove`, `stopPropagation` on a stepping `touchend`), while a vertical drag bubbles;
  - a single-slide figure is left un-hydrated.
- **Narration behaviour stays as it is:** one quiet, muted line labelled `reader.narrationPlayer`, collapsed by default; opening it shows the player.
- Class names stay: `.reader-slideshow__controls` (host), `__prev`, `__next`, `__counter`.
- Comments: default to none (CLAUDE.md). Commit format `refactor(#1889): …`, no attribution lines.
- Frontend tests run in the container (`docker compose exec -T frontend npx jest <path>`); never two Jest runs at once.
- Gate: `docker compose exec -T frontend npm run check` plus production `docker compose exec -T frontend npx ng build`. `article-content.component.scss` stays under its 14 kB error budget.

## File structure

| File | Change |
|---|---|
| `frontend/src/app/reader/article/decorators/player-box.ts` + `.spec.ts` | **new**: `EMBED_BOX`, `playerBoxOf(frame)` |
| `frontend/src/app/reader/article/reader-view/article-chapters.ts` + spec | anchors after `playerBoxOf(frame)`, or after the bar that follows it |
| `frontend/src/app/reader/article/reader-view/article-player-bars.ts` | uses `EMBED_BOX` from `player-box.ts` |
| `frontend/src/app/reader/article/reader-view/article-islands.ts` + spec | **adds** `mountBefore` |
| `frontend/src/app/reader/article/narration-toggle/narration-toggle.component.{ts,html,scss,spec.ts}` | **new** |
| `frontend/src/app/reader/article/reader-view/article-narration.ts` + `.spec.ts` | **new**: mounts the toggles |
| `frontend/src/app/reader/article/slideshow/slideshow-controls.component.{ts,html,scss,spec.ts}` | **new** |
| `frontend/src/app/reader/article/slideshow/slide-gestures.ts` | **new**: arrow keys + axis-locking swipe on the figure, returning a remover |
| `frontend/src/app/reader/article/reader-view/article-slideshows.ts` + `.spec.ts` | **new**: mounts the controls |
| `frontend/src/app/reader/article/reader-view/article-body.service.ts` + spec | mounts narration toggles and slideshow controls in `render` |
| `frontend/src/app/reader/article/decorators/decorate-article.ts` + spec | stops calling the two deleted decorators |
| `frontend/src/app/reader/article/decorators/reader-faq.ts` + spec | the narration exclusion goes, since no narration `<details>` exists any more |
| `frontend/src/app/reader/article/article-content/article-content.component.scss` | narration box rules → two `audio.reader-narration` rules; the slideshow control rules leave |
| **delete** | `decorators/reader-slideshow.ts` + spec, `decorators/reader-narration.ts` + spec |
| `docs/design-language.md` | records the two new islands, the control-island rule and the placement order |

Paths below are relative to `frontend/src/app/reader/article/` unless they start with `frontend/` or `docs/`.

---

### Task 1: `playerBoxOf`, and chapter lists after the bar

**Files:**
- Create: `decorators/player-box.ts`, `decorators/player-box.spec.ts`
- Modify: `reader-view/article-chapters.ts` + spec, `reader-view/article-player-bars.ts`, `reader-view/article-body.service.spec.ts` (ordering assertions only)

**Interfaces:**
- Produces:
  - `export const EMBED_BOX = '.reader-embed'`;
  - `export function playerBoxOf(frame: Element): Element`.
- Consumes: `CINEMA_BOX_CLASS` from `decorators/reader-cinema.ts`.

- [ ] **Step 1: Failing spec** (`decorators/player-box.spec.ts`)

```ts
import { playerBoxOf } from './player-box';

function frameIn(html: string): HTMLIFrameElement {
  const host = document.createElement('div');
  host.innerHTML = html;
  return host.querySelector('iframe')!;
}

describe('playerBoxOf', () => {
  it('is the cinema box around a widened player', () => {
    const frame = frameIn('<div class="reader-cinema"><div class="reader-embed"><iframe></iframe></div></div>');
    expect(playerBoxOf(frame).className).toBe('reader-cinema');
  });

  it('is the embed box of a player without a cinema box', () => {
    const frame = frameIn('<div class="reader-embed reader-embed--strip"><iframe></iframe></div>');
    expect(playerBoxOf(frame).classList).toContain('reader-embed');
  });

  it('is the frame itself without either box', () => {
    const frame = frameIn('<iframe></iframe>');
    expect(playerBoxOf(frame)).toBe(frame);
  });
});
```

- [ ] **Step 2: Run, see it fail.** `docker compose exec -T frontend npx jest src/app/reader/article/decorators/player-box.spec.ts` gives "Cannot find module".

- [ ] **Step 3: Implement** (`decorators/player-box.ts`)

```ts
import { CINEMA_BOX_CLASS } from './reader-cinema';

export const EMBED_BOX = '.reader-embed';

/** The outermost box a player sits in: its cinema box, else its embed box, else the frame. */
export function playerBoxOf(frame: Element): Element {
  return frame.closest(`.${CINEMA_BOX_CLASS}`) ?? frame.closest(EMBED_BOX) ?? frame;
}
```

- [ ] **Step 4: Use it.**
  - In `reader-view/article-player-bars.ts`, delete the local `EMBED_BOX` and import it from `../decorators/player-box`.
  - In `reader-view/article-chapters.ts` `attachToFrame`, replace the `after` expression with `listAnchorOf(frame)`, a module function in that file:
    ```ts
    /** After the player's box, or after the bar that follows it, so a list never splits a player from its bar. */
    function listAnchorOf(frame: Element): Element {
      const box = playerBoxOf(frame);
      const next = box.nextElementSibling;
      return next?.matches('app-player-bar') ? next : box;
    }
    ```
    Drop the now-unused `CINEMA_BOX_CLASS` import.

  `ArticleBody.render` mounts the bars before the chapter lists (#1888), so the bar exists when the list's anchor is computed. A list that arrives later from fetched details finds it too.

- [ ] **Step 5: Specs**
  - `reader-view/article-chapters.spec.ts`: add a test "puts a SoundCloud list after the widget's player bar". Build `<div class="reader-embed reader-embed--strip"><iframe src="<WIDGET_URL from frontend/src/testing/embed-media-testing.ts>"></iframe></div><app-player-bar></app-player-bar><p>Notes</p>`; a plain `app-player-bar` element is enough as the sibling marker. Attach chapters to the frame, open nothing, and expect `host.children[2]` to be the `.reader-chapters` list.
  - In `reader-view/article-body.service.spec.ts`, any assertion that a SoundCloud or other non-cinema list sits directly after its `.reader-embed` now expects it after the `app-player-bar` that follows the embed. Grep the spec for `nextElementSibling` and `.reader-embed` near list assertions.
  - Cinema-box assertions (`.reader-cinema + .reader-chapters`) stay as they are.

- [ ] **Step 6: Run** `docker compose exec -T frontend npx jest src/app/reader/article`; it passes. Commit with `refactor(#1889): place chapter lists after the player's bar through one helper`.

---

### Task 2: The narration toggle

**Files:**
- Modify: `reader-view/article-islands.ts` + spec
- Create: `narration-toggle/narration-toggle.component.{ts,html,scss,spec.ts}`, `reader-view/article-narration.ts` + `.spec.ts`
- Modify:
  - `reader-view/article-body.service.ts`;
  - `decorators/decorate-article.ts` + spec;
  - `decorators/reader-faq.ts` + spec;
  - `article-content/article-content.component.scss`.
- Delete: `decorators/reader-narration.ts` + spec

**Interfaces:**
- Produces:
  - `ArticleIslands.mountBefore<C>(anchor: Element, component: Type<C>, bindings: Binding[]): ComponentRef<C>`;
  - `NarrationToggleComponent` (`app-narration-toggle`, input `player: HTMLAudioElement`, required);
  - `class ArticleNarration { constructor(islands: ArticleIslands); mount(host: HTMLElement): void }`.

- [ ] **Step 1: `mountBefore`.** Refactor `ArticleIslands` so `mountAfter` and `mountBefore` share one private method that takes the insertion as a callback:

```ts
mountAfter<C>(anchor: Element, component: Type<C>, bindings: Binding[]): ComponentRef<C> {
  return this.mount(component, bindings, (host) => anchor.after(host));
}

mountBefore<C>(anchor: Element, component: Type<C>, bindings: Binding[]): ComponentRef<C> {
  return this.mount(component, bindings, (host) => anchor.before(host));
}

private mount<C>(component: Type<C>, bindings: Binding[], insert: (host: HTMLElement) => void): ComponentRef<C> {
  return this.injector.get(NgZone).run(() => {
    const ref = createComponent(component, {
      environmentInjector: this.injector.get(EnvironmentInjector),
      elementInjector: this.injector,
      bindings,
    });
    const host = hostOf(ref);
    host.classList.add(READER_ISLAND);
    insert(host);
    this.injector.get(ApplicationRef).attachView(ref.hostView);
    ref.changeDetectorRef.detectChanges();
    this.mounted.push(ref);
    return ref;
  });
}
```

In `article-islands.spec.ts`, add "mounts a component right before its anchor": the host becomes `host.children[0]` and the anchor `host.children[1]`.

- [ ] **Step 2: Failing component spec** (`narration-toggle/narration-toggle.component.spec.ts`)

```ts
import { TestBed } from '@angular/core/testing';
import { provideTranslocoTesting } from '../../../../testing/transloco-testing';
import { NarrationToggleComponent } from './narration-toggle.component';

function render(player = document.createElement('audio')) {
  TestBed.configureTestingModule({ imports: [NarrationToggleComponent, provideTranslocoTesting()] });
  const fixture = TestBed.createComponent(NarrationToggleComponent);
  fixture.componentRef.setInput('player', player);
  fixture.detectChanges();
  const button = (fixture.nativeElement as HTMLElement).querySelector('button')!;
  return { fixture, player, button };
}

describe('NarrationToggleComponent', () => {
  it('is a collapsed line labelled as machine narration', () => {
    const { player, button } = render();

    expect(button.type).toBe('button');
    expect(button.textContent).toContain('Listen — machine-generated narration');
    expect(button.getAttribute('aria-expanded')).toBe('false');
    expect(player.classList).not.toContain('reader-narration--open');
  });

  it('shows the player when opened and hides it again', () => {
    const { fixture, player, button } = render();

    button.click();
    fixture.detectChanges();
    expect(player.classList).toContain('reader-narration--open');
    expect(button.getAttribute('aria-expanded')).toBe('true');

    button.click();
    fixture.detectChanges();
    expect(player.classList).not.toContain('reader-narration--open');
    expect(button.getAttribute('aria-expanded')).toBe('false');
  });

  it('starts open on a player already shown', () => {
    const player = document.createElement('audio');
    player.classList.add('reader-narration--open');
    const { button } = render(player);

    expect(button.getAttribute('aria-expanded')).toBe('true');
  });
});
```

- [ ] **Step 3: Implement the component**

`narration-toggle.component.ts`:

```ts
import { ChangeDetectionStrategy, Component, input, linkedSignal } from '@angular/core';
import { TranslocoPipe } from '@jsverse/transloco';
import { IconComponent } from '../../../shared/icon/icon.component';

const OPEN = 'reader-narration--open';

/** Shows or hides a machine-generated narration player in the body (#903, #1889). */
@Component({
  selector: 'app-narration-toggle',
  imports: [TranslocoPipe, IconComponent],
  templateUrl: './narration-toggle.component.html',
  styleUrl: './narration-toggle.component.scss',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class NarrationToggleComponent {
  readonly player = input.required<HTMLAudioElement>();

  protected readonly open = linkedSignal(() => this.player().classList.contains(OPEN));

  protected toggle(): void {
    this.open.set(this.player().classList.toggle(OPEN));
  }
}
```

`narration-toggle.component.html`:

```html
<button type="button" [attr.aria-expanded]="open()" (click)="toggle()">
  <app-icon [name]="open() ? 'expand_more' : 'chevron_right'" size="sm" />
  {{ 'reader.narrationPlayer' | transloco }}
</button>
```

`narration-toggle.component.scss`. The look is today's `.reader-narration-box` (muted `--fs-sm` line, `--space-3` above):

```scss
:host {
  display: block;
  margin-top: var(--space-3);
}

button {
  display: inline-flex;
  align-items: center;
  gap: var(--space-1);
  padding: 0;
  background: none;
  border: none;
  font: inherit;
  font-size: var(--fs-sm);
  color: var(--text-muted);
  cursor: pointer;
}
```

- [ ] **Step 4: `reader-view/article-narration.ts`**

```ts
import { inputBinding } from '@angular/core';
import { NarrationToggleComponent } from '../narration-toggle/narration-toggle.component';
import { ArticleIslands } from './article-islands';

/** A toggle above every machine-generated narration player in a rendered body (#903, #1889). */
export class ArticleNarration {
  constructor(private readonly islands: ArticleIslands) {}

  mount(host: HTMLElement): void {
    for (const player of Array.from(host.querySelectorAll<HTMLAudioElement>('audio.reader-narration'))) {
      this.islands.mountBefore(player, NarrationToggleComponent, [inputBinding('player', () => player)]);
    }
  }
}
```

Spec (`article-narration.spec.ts`), using the TestBed setup of `article-player-bars.spec.ts` (Transloco and account identity):
- a marked player gets an `app-narration-toggle` right before it;
- an ordinary `<audio>` gets none;
- a second `mount` after `releaseAll()` over the same DOM leaves the audio in the body and gives it exactly one toggle.

- [ ] **Step 5: Wire it.**
  - `ArticleBody`: add a `private readonly narration = new ArticleNarration(this.islands);` field after `playerBars`, and call `this.narration.mount(host);` in `render`'s `runOutsideAngular` block, right after `this.playerBars.mount(host, listen);`.
  - `decorators/decorate-article.ts`: remove `markNarrationPlayers` and its import.
  - `git rm` `decorators/reader-narration.ts` and its spec.
  - `decorators/reader-faq.ts`: drop the `reader-narration-box` exclusion and the docblock sentences about it. The docblock keeps its first three sentences.
  - `reader-faq.spec.ts`: delete "leaves the machine-narration box collapsed and unstyled".
  - `decorate-article.spec.ts`: "keeps the narration box a collapsed player while a publisher disclosure becomes a FAQ" becomes "turns a publisher disclosure into a FAQ and leaves the narration player in place". It expects exactly one `details.reader-faq[open]`, and an `audio.reader-narration` that is not inside any `details`.

- [ ] **Step 6: Body styles.** In `article-content.component.scss`, replace the three `.reader-narration-box` rules with:

```scss
:host ::ng-deep audio.reader-narration:not(.reader-narration--open) {
  display: none;
}

:host ::ng-deep audio.reader-narration {
  margin: var(--space-2) 0 var(--space-3);
}
```

These out-specify the barred `audio#{$prose}` rule (0,2,1 against 0,1,1).

- [ ] **Step 7: Body spec.** In `reader-view/article-body.service.spec.ts`, add "puts a narration toggle above a narrated body's player". `connectBody('<p>x</p><audio class="reader-narration" src="https://x.test/n.mp3"></audio>')`, then expect `content.querySelector('app-narration-toggle + audio.reader-narration')` not to be null.

- [ ] **Step 8: Run and gate.**
  - Run `docker compose exec -T frontend npx jest src/app/reader/article`.
  - Break-check `ArticleNarration`'s re-render test: temporarily make `NarrationToggleComponent` move the player into its own host (`hostElement.append(player)` via `inject(ElementRef)` in the constructor). Confirm the third spec fails, then restore by edit.
  - Commit with `refactor(#1889): mount the narration toggle as app-narration-toggle`.

---

### Task 3: The slideshow controls

**Files:**
- Create:
  - `slideshow/slide-gestures.ts`;
  - `slideshow/slideshow-controls.component.{ts,html,scss,spec.ts}`;
  - `reader-view/article-slideshows.ts` + `.spec.ts`.
- Modify:
  - `reader-view/article-body.service.ts`;
  - `decorators/decorate-article.ts`;
  - `article-content/article-content.component.scss`.
- Delete: `decorators/reader-slideshow.ts` + spec

**Interfaces:**
- Consumes: `AXIS_LOCK_MIN`, `isSlideSwipe` (`../../reader-gestures`) and `ArticleIslands.mountAfter`.
- Produces:
  - `bindSlideGestures(figure: HTMLElement, step: (delta: number) => void): () => void`;
  - `SlideshowControlsComponent` (`app-slideshow-controls`, inputs `figure: HTMLElement` and `slides: readonly HTMLElement[]`, both required; host class `reader-slideshow__controls`);
  - `class ArticleSlideshows { constructor(islands: ArticleIslands); mount(host: HTMLElement): void }`.

- [ ] **Step 1: `slideshow/slide-gestures.ts`.** Move `bindArrowKeys` and `bindSwipe` out of `decorators/reader-slideshow.ts` verbatim, comment included ("The reader body is a horizontally-swipeable…"), into one exported function. Each `addEventListener` is paired with its `removeEventListener` in the returned remover:

```ts
import { AXIS_LOCK_MIN, isSlideSwipe } from '../../reader-gestures';

/** Arrow keys and swipes on a slideshow figure step it; the returned function unbinds them. */
export function bindSlideGestures(figure: HTMLElement, step: (delta: number) => void): () => void {
  // …the keydown listener and the three touch listeners from reader-slideshow.ts, unchanged in behaviour,
  // each a named const so it can be removed…
  return () => {
    // removeEventListener for each, with the same options objects
  };
}
```

Write the listener bodies exactly as they are in `reader-slideshow.ts` today, including the `{ passive: true }` and `{ passive: false }` options.

- [ ] **Step 2: Failing component spec** (`slideshow/slideshow-controls.component.spec.ts`). Port every test of `decorators/reader-slideshow.spec.ts` except "is idempotent" and "leaves a single-slide figure un-hydrated", which move to `article-slideshows.spec.ts`. Mount the component through `ArticleIslands` so the controls land inside the figure:

```ts
import { Injector, inputBinding } from '@angular/core';
import { TestBed } from '@angular/core/testing';
import { articleHost } from '../../../../testing/article-host-testing';
import { provideTranslocoTesting } from '../../../../testing/transloco-testing';
import { ArticleIslands } from '../reader-view/article-islands';
import { SlideshowControlsComponent } from './slideshow-controls.component';

const SLIDES =
  '<figure class="reader-slideshow"><ol>' +
  '<li><img src="https://img/1.jpg" alt="one"></li>' +
  '<li><img src="https://img/2.jpg" alt="two"></li>' +
  '<li><img src="https://img/3.jpg" alt="three"></li></ol></figure>';

function mounted(html = SLIDES) {
  TestBed.configureTestingModule({ imports: [provideTranslocoTesting()] });
  const host = articleHost(html);
  const figure = host.querySelector<HTMLElement>('.reader-slideshow')!;
  const slides = Array.from(figure.querySelectorAll<HTMLElement>('li'));
  const islands = new ArticleIslands(TestBed.inject(Injector));
  islands.mountAfter(slides[0].parentElement!, SlideshowControlsComponent, [
    inputBinding('figure', () => figure),
    inputBinding('slides', () => slides),
  ]);
  TestBed.tick();
  return { host, figure, slides, islands };
}
```

The ported tests assert the same values. The counter text is now the English Transloco string `"1 of 3"` (`reader.slideshowPosition`), and the `aria-label`s are "Previous slide" and "Next slide". Call `TestBed.tick()` after every click or key or touch event before asserting. Keep the touch helper `touch(x, y, type)` from the old spec.

Add two tests:
- "picks up the slide a previous mount showed": mount, click next, call `islands.releaseAll()`, mount again on the same DOM; the counter reads "2 of 3" and the track is `translateX(-100%)`.
- "unbinds its gestures when destroyed": after `releaseAll()`, an ArrowRight `keydown` on the figure is not `defaultPrevented`.

- [ ] **Step 3: Implement the component**

`slideshow/slideshow-controls.component.ts`:

```ts
import {
  ChangeDetectionStrategy,
  Component,
  effect,
  input,
  linkedSignal,
  untracked,
} from '@angular/core';
import { TranslocoPipe } from '@jsverse/transloco';
import { bindSlideGestures } from './slide-gestures';

const READY = 'reader-slideshow--ready';

/** The prev/next controls of a body slideshow, stepping its own slides (#926, #1889). */
@Component({
  selector: 'app-slideshow-controls',
  imports: [TranslocoPipe],
  templateUrl: './slideshow-controls.component.html',
  styleUrl: './slideshow-controls.component.scss',
  changeDetection: ChangeDetectionStrategy.OnPush,
  host: { class: 'reader-slideshow__controls' },
})
export class SlideshowControlsComponent {
  readonly figure = input.required<HTMLElement>();
  readonly slides = input.required<readonly HTMLElement[]>();

  protected readonly current = linkedSignal(() =>
    Math.max(0, this.slides().findIndex((slide) => slide.getAttribute('aria-hidden') === 'false')),
  );

  constructor() {
    effect((onCleanup) => {
      const figure = this.figure();
      const slides = this.slides();
      untracked(() => markReady(figure, slides));
      onCleanup(bindSlideGestures(figure, (delta) => this.step(delta)));
    });
    effect(() => show(this.slides(), this.current()));
  }

  protected step(delta: number): void {
    const total = this.slides().length;
    this.current.update((index) => (index + delta + total) % total);
  }
}

function markReady(figure: HTMLElement, slides: readonly HTMLElement[]): void {
  figure.classList.add(READY);
  figure.setAttribute('aria-roledescription', 'carousel');
  figure.tabIndex = 0;
  slides.forEach((slide, index) => slide.setAttribute('aria-label', `${index + 1} / ${slides.length}`));
}

function show(slides: readonly HTMLElement[], current: number): void {
  const track = slides[0]?.parentElement;
  if (track) track.style.transform = `translateX(${current * -100}%)`;
  slides.forEach((slide, index) => {
    const hidden = index !== current;
    slide.setAttribute('aria-hidden', String(hidden));
    // A caption link in an off-screen slide must leave the tab order, or an
    // aria-hidden slide would hold a focusable element (an ARIA violation).
    slide.toggleAttribute('inert', hidden);
  });
}
```

Keep the comment on `inert` verbatim from the old file: it guards a real accessibility bug. Drop the old "Slide the track; CSS transitions the transform" comment.

`slideshow/slideshow-controls.component.html`:

```html
<button
  type="button"
  class="reader-slideshow__prev"
  [attr.aria-label]="'reader.slideshowPrevious' | transloco"
  (click)="step(-1)"
>‹</button>
<span class="reader-slideshow__counter" aria-live="polite">{{
  'reader.slideshowPosition' | transloco: { current: current() + 1, total: slides().length }
}}</span>
<button
  type="button"
  class="reader-slideshow__next"
  [attr.aria-label]="'reader.slideshowNext' | transloco"
  (click)="step(1)"
>›</button>
```

If Prettier rewraps the button glyphs with whitespace, make sure `button.textContent` is still exactly `‹`/`›`. The ported spec asserts that. If needed, put the glyph inside a `<span aria-hidden="true">` and assert on the trimmed text.

`slideshow/slideshow-controls.component.scss`. These are the rules moved from `article-content.component.scss` (`.reader-slideshow__controls`, `__counter`, `__prev`/`__next`). Copy their declarations verbatim from that file:

```scss
@use '../../../theme/glass-rim';

:host {
  display: flex;
  align-items: center;
  justify-content: center;
  gap: var(--space-3);
  margin-top: var(--space-2);
}

.reader-slideshow__counter {
  font-variant-numeric: tabular-nums;
  color: var(--text-muted);
}

.reader-slideshow__prev,
.reader-slideshow__next {
  @include glass-rim.filled(var(--surface-2), var(--border), control);

  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: var(--tap-target);
  height: var(--tap-target);
  border-radius: var(--radius-pill);
  color: var(--text-secondary);
  font-size: var(--fs-xl);
  line-height: 1;
  cursor: pointer;
}
```

- [ ] **Step 4: `reader-view/article-slideshows.ts`**

```ts
import { inputBinding } from '@angular/core';
import { SlideshowControlsComponent } from '../slideshow/slideshow-controls.component';
import { ArticleIslands } from './article-islands';

/** Controls for every backend-emitted slideshow of two or more slides in a rendered body (#926, #1889). */
export class ArticleSlideshows {
  constructor(private readonly islands: ArticleIslands) {}

  mount(host: HTMLElement): void {
    for (const figure of Array.from(host.querySelectorAll<HTMLElement>('.reader-slideshow'))) {
      const slides = Array.from(figure.querySelectorAll<HTMLElement>('li'));
      const track = slides[0]?.parentElement;
      if (slides.length < 2 || !track) continue;
      this.islands.mountAfter(track, SlideshowControlsComponent, [
        inputBinding('figure', () => figure),
        inputBinding('slides', () => slides),
      ]);
    }
  }
}
```

Spec (`article-slideshows.spec.ts`):
- a three-slide figure gets one `app-slideshow-controls` right after its `ol`, and the figure gets `reader-slideshow--ready`;
- a single-slide figure gets neither;
- a second `mount` after `releaseAll()` over the same DOM leaves all three `li` in the figure and gives it exactly one set of controls.

- [ ] **Step 5: Wire it.**
  - `ArticleBody`: add `private readonly slideshows = new ArticleSlideshows(this.islands);` and call `this.slideshows.mount(host);` right after `this.narration.mount(host);`.
  - `decorate-article.ts`: remove `hydrateSlideshows`, its labels and its import. If `decorateArticle` no longer uses `i18n`, leave the parameter; `addCinemaToggles`' labels went in #1888, so check whether anything else still reads it. If nothing does, remove the parameter and update the one caller and the spec.
  - `git rm` `decorators/reader-slideshow.ts` and its spec.

- [ ] **Step 6: Body styles.** In `article-content.component.scss`, delete the `.reader-slideshow__controls`, `.reader-slideshow__counter` and `.reader-slideshow__prev`/`__next` rules. The figure, track, `--ready` and reduced-motion rules stay: they style body nodes.

- [ ] **Step 7: Run and gate.**
  - `docker compose exec -T frontend npx jest src/app/reader/article`;
  - `docker compose exec -T frontend npm run check`;
  - `docker compose exec -T frontend npx ng build`, quoting the article-content budget line.

  Break-check "picks up the slide a previous mount showed": temporarily seed `current` with `signal(0)`, confirm the test fails, then restore by edit. Commit with `refactor(#1889): mount the slideshow controls as app-slideshow-controls`.

---

### Task 4: Docs, and a live check on the real render

- [ ] **Step 1: `docs/design-language.md`.**
  - In the #1886 paragraph ("A component inside the article body is mounted, not decorated"):
    - list the users as `app-embed-chapters`, `app-player-bar`, `app-narration-toggle` and `app-slideshow-controls`;
    - add one sentence: a mounted component drives the body nodes it is handed and never moves them, because a same-DOM re-render releases every island;
    - mention `mountBefore` next to `mountAfter`.
  - The convention above it (decorators use a plain `<details>`): remove the narration player and keep FAQs.
  - Grep `docs/design-language.md` for `reader-narration.ts`, `reader-slideshow.ts`, `reader-narration-box` and `hydrateSlideshows`, and fix each live mention.
  - Commit with `docs(#1889): record the slideshow and narration islands`.
- [ ] **Step 2: Live check on :4200 (controller).**
  - Use an entry with a slideshow (`content_html LIKE '%reader-slideshow%'` in the dev DB, read-only) and one with a narration player (`'%reader-narration%'`).
  - Set the pane viewport to 1280×900 first.
  - Slideshow: next/prev and the counter step, ArrowRight steps, the track translates, and only the current slide is not `inert`.
  - Narration: the toggle line is muted `--fs-sm` above the player, opening shows the player, and closing hides it.
  - A SoundCloud embed with chapters shows embed, bar, list in that order.
  - No console errors.
- [ ] **Step 3: e2e.** From `frontend/`, run `npx playwright test e2e/chapters.spec.ts e2e/listen-to-embed.spec.ts e2e/article-tail-space.spec.ts`; all pass.
