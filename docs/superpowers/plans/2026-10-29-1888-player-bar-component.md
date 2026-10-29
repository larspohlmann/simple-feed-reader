# Player bar under embeds as a mounted component (#1888) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** The bar under body players becomes one Angular component, `app-player-bar`, mounted into the article body through `ArticleIslands` (#1886). The bar holds the Cinema toggle plus the Listen and Add-to-playlist buttons, and the component replaces their hand-built DOM, the `showQueued` push plumbing and the bar's `::ng-deep` body styles.

**Architecture:**
- The cinema decorator keeps wrapping a landscape player in `.reader-cinema`. That wrapping happens before the frame loads; moving a live iframe later would reload it.
- A new `ArticlePlayerBars` (in `reader-view/`, like `ArticleChapters`) mounts one `app-player-bar`:
  - inside every `.reader-cinema`, after its player;
  - after every other `.reader-embed` that Listen can play.
- The component:
  - takes the cinema box (or null) and an `EmbedListen` offer (media, frame and actions, or null);
  - toggles `.reader-cinema--on` on the box;
  - reads the queue state as a `computed` of `AudioPlayerService.isQueued`;
  - takes its labels from Transloco.
- `ArticleEmbedAudio.follow` and `EmbedListenControl` go.

**Tech Stack:** Angular 20.3 standalone components + signals, Transloco, Jest (jsdom), Playwright.

**Spec:** GitHub issue #1888 (follows #1886).

## Global Constraints

- **Component rules:** styles in a sibling `.scss` (`styleUrl`); no hex colours and no ad-hoc `px`, tokens only (`npm run check` enforces).
- **New components:** standalone, signals, `ChangeDetectionStrategy.OnPush`.
- **Islands:** mount only through `ArticleIslands.mountAfter`, which enters the Angular zone itself. `ArticleBody` destroys islands per render and on destroy (#1886); no new lifecycle code.
- **Behaviour stays as it is:**
  - Cinema widens and narrows the player, flips its icon (`width_wide`/`width_normal`) and label, and scrolls the box into view (`block: 'nearest'`).
  - The `t` key works (`toggleCinemaByKey`, which finds `.reader-cinema__toggle` buttons and skips hidden ones).
  - The toggle is hidden below the `reader-pane` container breakpoint.
  - Listen pauses the embed and plays it in the audio player.
  - The queue toggle shows `playlist_add`/`playlist_add_check`, "Add to playlist"/"In playlist", `aria-pressed` and `--queued`, and follows the playlist both ways.
  - No Listen controls before the entry is known.
  - The Listen buttons come before the cinema toggle.
  - A non-landscape embed (SoundCloud, portrait) gets its bar right after its `.reader-embed`.
  - A chapter list still sits after the `.reader-cinema` box, or right after `.reader-embed` for a non-cinema embed.
- **Class names stay:** `.reader-player-bar` (host), `.reader-listen` (host, when it carries Listen), `.reader-listen__button`, `.reader-listen__button--queued`, `.reader-cinema__toggle`. Specs and e2e locate by them.
- **Comments:** default to none (CLAUDE.md).
- **Commits:** format `refactor(#1888): …`, no attribution lines.
- **Tests:** frontend tests run in the container, `docker compose exec -T frontend npx jest <path>`; never two Jest runs at once.
- **Gate:** `docker compose exec -T frontend npm run check` plus production `docker compose exec -T frontend npx ng build`. `article-content.component.scss` must stay under the 14 kB error budget; this change shrinks it.

## File structure

| File | Change |
|---|---|
| `frontend/src/app/reader/article/player-bar/embed-listen.ts` | **new**: `ListenActions` (moved from `decorators/reader-listen.ts`) and `EmbedListen` |
| `frontend/src/app/reader/article/player-bar/player-bar.component.{ts,html,scss,spec.ts}` | **new**: the bar |
| `frontend/src/app/reader/article/reader-view/article-player-bars.ts` + `.spec.ts` | **new**: finds the players and mounts the bars |
| `frontend/src/app/reader/article/decorators/reader-cinema.ts` + spec | `addCinemaToggles` → `wrapCinemaBoxes(host)`, which wraps only; `playerBar`, `toggleBar`, `CinemaLabels` go; `toggleCinemaByKey` stays |
| `frontend/src/app/reader/article/decorators/decorate-article.ts` + spec | no listen, no cinema labels; returns `void` |
| `frontend/src/app/reader/article/reader-view/article-embed-audio.ts` + spec | `follow`, `release`, `following` and the `injector` parameter go; imports `ListenActions` from `player-bar/embed-listen` |
| `frontend/src/app/reader/article/reader-view/article-body.service.ts` + spec | mounts the bars in `render`, before the chapter lists |
| `frontend/src/app/reader/article/article-content/article-content.component.scss` | the bar and button rules leave; `.reader-cinema`, `.reader-cinema > …` and `.reader-cinema--on` stay (the box is body DOM) |
| **delete** | `decorators/reader-listen.ts`, `decorators/reader-listen.spec.ts` |
| `docs/design-language.md` | records `app-player-bar` as the second mounted component |

All paths below are relative to `frontend/src/app/reader/article/` unless they start with `frontend/` or `docs/`.

---

### Task 1: `app-player-bar`

**Files:**
- Create: `player-bar/embed-listen.ts`, `player-bar/player-bar.component.ts`, `.html`, `.scss`, `.spec.ts`

**Interfaces:**
- Produces:
  - `interface ListenActions { listen(media: EmbedMedia, frame: HTMLIFrameElement): void; toggleQueued(media: EmbedMedia): void }`
  - `interface EmbedListen { readonly media: EmbedMedia; readonly frame: HTMLIFrameElement; readonly actions: ListenActions }`
  - `PlayerBarComponent`, selector `app-player-bar`, inputs `cinemaBox: HTMLElement | null` (default `null`) and `listen: EmbedListen | null` (default `null`), host classes `reader-player-bar` and (when `listen` is set) `reader-listen`.
- Consumes:
  - `EmbedMedia` (`../../audio/embed-media`);
  - `AudioPlayerService.isQueued(url)` (`../../audio-player.service`), which reads the `_playlist` signal and is therefore reactive;
  - `IconComponent` (`../../../shared/icon/icon.component`, sizes include `'xs'`).

- [ ] **Step 1: `player-bar/embed-listen.ts`**

```ts
import type { EmbedMedia } from '../../audio/embed-media';

export interface ListenActions {
  listen(media: EmbedMedia, frame: HTMLIFrameElement): void;
  toggleQueued(media: EmbedMedia): void;
}

/** What a player bar needs to offer Listen and the queue toggle for one embed (#1815, #1888). */
export interface EmbedListen {
  readonly media: EmbedMedia;
  readonly frame: HTMLIFrameElement;
  readonly actions: ListenActions;
}
```

- [ ] **Step 2: Write the failing spec** (`player-bar/player-bar.component.spec.ts`)

```ts
import { signal } from '@angular/core';
import { TestBed } from '@angular/core/testing';
import { provideTranslocoTesting } from '../../../../testing/transloco-testing';
import { AudioPlayerService } from '../../audio-player.service';
import type { EmbedMedia } from '../../audio/embed-media';
import { EmbedListen } from './embed-listen';
import { PlayerBarComponent } from './player-bar.component';

const MEDIA: EmbedMedia = {
  kind: 'youtube',
  url: 'https://www.youtube.com/watch?v=GhUuOxrCato',
  posterUrl: null,
};

class QueueStub {
  readonly queued = signal<string[]>([]);
  isQueued(url: string): boolean {
    return this.queued().includes(url);
  }
}

function render(inputs: { cinemaBox?: HTMLElement | null; listen?: EmbedListen | null }) {
  const queue = new QueueStub();
  TestBed.configureTestingModule({
    imports: [PlayerBarComponent, provideTranslocoTesting()],
    providers: [{ provide: AudioPlayerService, useValue: queue }],
  });
  const fixture = TestBed.createComponent(PlayerBarComponent);
  fixture.componentRef.setInput('cinemaBox', inputs.cinemaBox ?? null);
  fixture.componentRef.setInput('listen', inputs.listen ?? null);
  fixture.detectChanges();
  return { fixture, element: fixture.nativeElement as HTMLElement, queue };
}

function offer(): EmbedListen {
  return {
    media: MEDIA,
    frame: document.createElement('iframe'),
    actions: { listen: jest.fn(), toggleQueued: jest.fn() },
  };
}

const text = (element: Element | null) => element?.textContent?.replace(/\s+/g, '') ?? '';

describe('PlayerBarComponent', () => {
  it('is the player bar, offering Listen and the queue toggle before the cinema toggle', () => {
    const { element } = render({ cinemaBox: document.createElement('div'), listen: offer() });

    expect(element.classList).toContain('reader-player-bar');
    expect(element.classList).toContain('reader-listen');
    expect(Array.from(element.querySelectorAll('button'), (button) => button.className)).toEqual([
      'reader-listen__button',
      'reader-listen__button',
      'reader-cinema__toggle',
    ]);
  });

  it('labels Listen and names what it does', () => {
    const { element } = render({ listen: offer() });
    const listen = element.querySelectorAll<HTMLButtonElement>('.reader-listen__button')[0];

    expect(text(listen)).toBe('play_circleListen');
    expect(listen.title).toBe("Play the sound in the reader's audio player");
  });

  it('hands Listen the media and the frame that shows it', () => {
    const listen = offer();
    const { element } = render({ listen });

    element.querySelectorAll<HTMLButtonElement>('.reader-listen__button')[0].click();

    expect(listen.actions.listen).toHaveBeenCalledWith(MEDIA, listen.frame);
  });

  it('hands the queue toggle the media', () => {
    const listen = offer();
    const { element } = render({ listen });

    element.querySelectorAll<HTMLButtonElement>('.reader-listen__button')[1].click();

    expect(listen.actions.toggleQueued).toHaveBeenCalledWith(MEDIA);
  });

  it('shows the queue state through icon, label, pressed state and class, following the playlist', () => {
    const { fixture, element, queue } = render({ listen: offer() });
    const toggle = () => element.querySelectorAll<HTMLButtonElement>('.reader-listen__button')[1];

    expect(text(toggle())).toBe('playlist_addAddtoplaylist');
    expect(toggle().getAttribute('aria-pressed')).toBe('false');
    expect(toggle().classList).not.toContain('reader-listen__button--queued');

    queue.queued.set([MEDIA.url]);
    fixture.detectChanges();
    expect(text(toggle())).toBe('playlist_add_checkInplaylist');
    expect(toggle().getAttribute('aria-pressed')).toBe('true');
    expect(toggle().classList).toContain('reader-listen__button--queued');

    queue.queued.set([]);
    fixture.detectChanges();
    expect(toggle().getAttribute('aria-pressed')).toBe('false');
  });

  it('offers no Listen controls without an offer', () => {
    const { element } = render({ cinemaBox: document.createElement('div') });

    expect(element.classList).not.toContain('reader-listen');
    expect(element.querySelector('.reader-listen__button')).toBeNull();
  });

  it('offers no cinema toggle without a cinema box', () => {
    const { element } = render({ listen: offer() });

    expect(element.querySelector('.reader-cinema__toggle')).toBeNull();
  });

  it('widens its box on click, flips the label and icon, and narrows again', () => {
    const box = document.createElement('div');
    box.scrollIntoView = jest.fn();
    const { fixture, element } = render({ cinemaBox: box });
    const toggle = element.querySelector<HTMLButtonElement>('.reader-cinema__toggle')!;

    expect(toggle.getAttribute('aria-keyshortcuts')).toBe('t');
    expect(text(toggle)).toBe('width_wideCinemamode(t)');

    toggle.click();
    fixture.detectChanges();
    expect(box.classList).toContain('reader-cinema--on');
    expect(text(toggle)).toBe('width_normalExitcinemamode(t)');
    expect(box.scrollIntoView).toHaveBeenCalledWith({ block: 'nearest' });

    toggle.click();
    fixture.detectChanges();
    expect(box.classList).not.toContain('reader-cinema--on');
    expect(text(toggle)).toBe('width_wideCinemamode(t)');
  });
});
```

- [ ] **Step 3: Run it to see it fail**

Run: `docker compose exec -T frontend npx jest src/app/reader/article/player-bar`
Expected: FAIL, `Cannot find module './player-bar.component'`.

- [ ] **Step 4: Implement**

`player-bar/player-bar.component.ts`:

```ts
import {
  ChangeDetectionStrategy,
  Component,
  computed,
  inject,
  input,
  signal,
} from '@angular/core';
import { TranslocoPipe } from '@jsverse/transloco';
import { IconComponent } from '../../../shared/icon/icon.component';
import { AudioPlayerService } from '../../audio-player.service';
import { EmbedListen } from './embed-listen';

const WIDENED = 'reader-cinema--on';

/** The bar under a body player: Listen, the queue toggle and Cinema (#1479, #1815, #1888). */
@Component({
  selector: 'app-player-bar',
  imports: [TranslocoPipe, IconComponent],
  templateUrl: './player-bar.component.html',
  styleUrl: './player-bar.component.scss',
  changeDetection: ChangeDetectionStrategy.OnPush,
  host: { class: 'reader-player-bar', '[class.reader-listen]': 'listen() !== null' },
})
export class PlayerBarComponent {
  readonly cinemaBox = input<HTMLElement | null>(null);
  readonly listen = input<EmbedListen | null>(null);

  private readonly audio = inject(AudioPlayerService);

  protected readonly widened = signal(false);
  protected readonly queued = computed(() => {
    const offer = this.listen();
    return offer !== null && this.audio.isQueued(offer.media.url);
  });

  protected play(offer: EmbedListen): void {
    offer.actions.listen(offer.media, offer.frame);
  }

  protected toggleQueued(offer: EmbedListen): void {
    offer.actions.toggleQueued(offer.media);
  }

  protected toggleCinema(box: HTMLElement): void {
    const widened = !this.widened();
    box.classList.toggle(WIDENED, widened);
    this.widened.set(widened);
    box.scrollIntoView({ block: 'nearest' });
  }
}
```

`player-bar/player-bar.component.html`:

```html
@if (listen(); as offer) {
  <button
    type="button"
    class="reader-listen__button"
    [title]="'reader.audioPlayer.listenEmbed' | transloco"
    (click)="play(offer)"
  >
    <app-icon name="play_circle" size="xs" />
    <span>{{ 'reader.audioPlayer.listen' | transloco }}</span>
  </button>
  <button
    type="button"
    class="reader-listen__button"
    [class.reader-listen__button--queued]="queued()"
    [attr.aria-pressed]="queued()"
    (click)="toggleQueued(offer)"
  >
    <app-icon [name]="queued() ? 'playlist_add_check' : 'playlist_add'" size="xs" />
    <span>{{
      (queued() ? 'reader.audioPlayer.inPlaylist' : 'reader.audioPlayer.addToPlaylist') | transloco
    }}</span>
  </button>
}
@if (cinemaBox(); as box) {
  <button
    type="button"
    class="reader-cinema__toggle"
    aria-keyshortcuts="t"
    (click)="toggleCinema(box)"
  >
    <app-icon [name]="widened() ? 'width_normal' : 'width_wide'" size="xs" />
    <span>{{ (widened() ? 'reader.cinemaExit' : 'reader.cinema') | transloco }}</span>
    <kbd aria-hidden="true">(t)</kbd>
  </button>
}
```

`player-bar/player-bar.component.scss`. These are the rules moved from `article-content.component.scss` (the `.reader-player-bar` and button rules, lines ~399–457). The `.material-symbols-outlined` size rule goes, because `app-icon size="xs"` sets `--icon-xs` itself.

```scss
@use '../../../theme/breakpoints' as bp;
@use '../../../theme/glass-rim';

:host {
  display: flex;
  flex-wrap: wrap;
  gap: var(--space-2);
  align-items: center;
  margin-top: var(--space-1);
}

.reader-listen__button,
.reader-cinema__toggle {
  --rim-fill: transparent;

  gap: var(--space-1);
  align-items: center;
  padding: var(--space-1) var(--space-2);
  border-radius: var(--radius-sm);
  font: inherit;
  font-size: var(--fs-xs);
  color: var(--text-secondary);
  cursor: pointer;
  background: var(--rim-fill);

  @include glass-rim.ring(var(--border), control);

  @media (hover: hover) {
    &:hover {
      color: var(--text-primary);
    }
  }
}

.reader-listen__button {
  display: inline-flex;
}

.reader-listen__button--queued {
  --rim-fill: var(--accent-soft);
}

/* Below the breakpoint the cinema toggle alone hides; the bar stays for the listen controls. */
.reader-cinema__toggle {
  display: none;
  margin-inline-start: auto;
}

.reader-cinema__toggle kbd {
  font-family: inherit;
  color: var(--text-muted);
}

@container reader-pane (width > #{bp.$container-reader-cinema}) {
  .reader-cinema__toggle {
    display: inline-flex;
  }
}
```

Before writing this file, open `article-content.component.scss` and copy those rule bodies from it verbatim. If any declaration there differs from the block above, the file wins; note it in the report. Do not change `article-content.component.scss` in this task.

- [ ] **Step 5: Run it to see it pass**

Run: `docker compose exec -T frontend npx jest src/app/reader/article/player-bar`
Expected: PASS, 8 tests.

- [ ] **Step 6: Break-test the queue binding.** Temporarily replace the `queued` computed's body with `return false;`, confirm that "shows the queue state …" FAILS, and restore with an edit (never `git checkout --`).

- [ ] **Step 7: Lint and commit**

```bash
docker compose exec -T frontend npx prettier --write src/app/reader/article/player-bar
docker compose exec -T frontend npx eslint src/app/reader/article/player-bar
docker compose exec -T frontend npx stylelint src/app/reader/article/player-bar/player-bar.component.scss
git add frontend/src/app/reader/article/player-bar
git commit -m "refactor(#1888): add app-player-bar, the bar under body players"
```

---

### Task 2: Mount the bars and delete the hand-built ones

**Files:**
- Create: `reader-view/article-player-bars.ts`, `reader-view/article-player-bars.spec.ts`
- Modify:
  - `decorators/reader-cinema.ts` + spec;
  - `decorators/decorate-article.ts` + spec;
  - `reader-view/article-embed-audio.ts` + spec;
  - `reader-view/article-body.service.ts` + spec;
  - `article-content/article-content.component.scss`.
- Delete: `decorators/reader-listen.ts`, `decorators/reader-listen.spec.ts`

**Interfaces:**
- Consumes:
  - `ArticleIslands` (`reader-view/article-islands.ts`): `mountAfter<C>(anchor: Element, component: Type<C>, bindings: Binding[]): ComponentRef<C>`;
  - `PlayerBarComponent`, `EmbedListen` and `ListenActions` (Task 1);
  - `embedMediaOfFrame` (`../../audio/embed-media`).
- Produces:
  - `class ArticlePlayerBars { constructor(islands: ArticleIslands); mount(host: HTMLElement, actions: ListenActions | undefined): void }`;
  - `wrapCinemaBoxes(host: HTMLElement): void`;
  - `decorateArticle(host, i18n, decorations): void`.

- [ ] **Step 1: `decorators/reader-cinema.ts`**
  - Replace `addCinemaToggles` with:
    ```ts
    /** Wraps every landscape player in a cinema box its player bar can widen past the reading measure (#1479). Idempotent. */
    export function wrapCinemaBoxes(host: HTMLElement): void {
      for (const player of Array.from(host.querySelectorAll<HTMLElement>(LANDSCAPE_PLAYER))) {
        if (player.parentElement?.classList.contains(BOX)) continue;
        const box = document.createElement('div');
        box.className = BOX;
        player.replaceWith(box);
        box.append(player);
      }
    }
    ```
  - Delete `CinemaLabels`, `WIDENED`, `PLAYER_BAR`, `playerBar`, `toggleBar` and the `materialIcon` import.
  - Keep `toggleCinemaByKey`, `isCinemaKey`, `isOffered`, `isInViewport` unchanged.

- [ ] **Step 2: `decorators/decorate-article.ts`**
  - Drop the `reader-listen` import, `ListenActions`, `EmbedListenControl`, `addListenControls` and `ArticleDecorations.listen`.
  - Replace `addCinemaToggles(host, {…})` with `wrapCinemaBoxes(host)`.
  - `decorateArticle` returns `void`, and its docblock becomes "Every decoration the rendered article body gets, in order."

- [ ] **Step 3: Delete** `decorators/reader-listen.ts` and `decorators/reader-listen.spec.ts` (`git rm`).

- [ ] **Step 4: `reader-view/article-embed-audio.ts`**
  - Import `ListenActions` from `../player-bar/embed-listen`.
  - Delete `follow`, `release`, the `following` field, the `EffectRef`/`effect`/`Injector` imports and the `injector` constructor parameter.
  - The docblock becomes "One rendered body's Listen and queue actions, wired to the player for the open entry (#1815)."

- [ ] **Step 5: Create `reader-view/article-player-bars.ts`**

```ts
import { inputBinding } from '@angular/core';
import { embedMediaOfFrame } from '../../audio/embed-media';
import { EmbedListen, ListenActions } from '../player-bar/embed-listen';
import { PlayerBarComponent } from '../player-bar/player-bar.component';
import { ArticleIslands } from './article-islands';

const CINEMA_BOX = '.reader-cinema';
const EMBED_BOX = '.reader-embed';

/** A rendered body's player bars: one in every cinema box and after every embed Listen plays (#1888). */
export class ArticlePlayerBars {
  constructor(private readonly islands: ArticleIslands) {}

  mount(host: HTMLElement, actions: ListenActions | undefined): void {
    for (const box of Array.from(host.querySelectorAll<HTMLElement>(CINEMA_BOX))) {
      const player = box.firstElementChild;
      if (!player) continue;
      this.mountBar(player, box, listenOf(box.querySelector(EMBED_BOX), actions));
    }
    for (const embed of Array.from(host.querySelectorAll<HTMLElement>(EMBED_BOX))) {
      if (embed.closest(CINEMA_BOX)) continue;
      const listen = listenOf(embed, actions);
      if (listen) this.mountBar(embed, null, listen);
    }
  }

  private mountBar(after: Element, cinemaBox: HTMLElement | null, listen: EmbedListen | null): void {
    this.islands.mountAfter(after, PlayerBarComponent, [
      inputBinding('cinemaBox', () => cinemaBox),
      inputBinding('listen', () => listen),
    ]);
  }
}

function listenOf(embed: Element | null, actions: ListenActions | undefined): EmbedListen | null {
  const frame = embed?.querySelector<HTMLIFrameElement>(':scope > iframe') ?? null;
  const media = frame && embedMediaOfFrame(frame);
  return actions && frame && media ? { media, frame, actions } : null;
}
```

- [ ] **Step 6: Wire `reader-view/article-body.service.ts`**
  - The field `embedAudio = new ArticleEmbedAudio(this.audio, inject(ListenLauncher))` loses the injector argument.
  - Add `private readonly playerBars = new ArticlePlayerBars(this.islands);` after the `islands` field.
  - Delete both `this.embedAudio.release();` calls.
  - In `render`:
    ```ts
    decorateArticle(host, this.i18n, {
      enclosureUrl: parts.enclosureUrl(),
      embedSource: (url) => withScriptablePlayer(url, window.location.origin),
    });
    const listen = this.embedAudio.actions({
      entry: parts.entry(),
      detailsOf: (media) => this.embedDetails.detailsOf(media.url),
      onListened: (listened) => this.chapters.followTrack(listened),
    });
    this.zone.runOutsideAngular(() => {
      this.playerBars.mount(host, listen);
      this.attachBodyChapters(host, chapterList, track);
      this.loadEmbedDetails(chapterFrames(host));
      this.media.follow(host);
    });
    ```
  - The bars mount before the chapter lists. A list for a non-cinema embed then lands between the embed and its bar, as before (`ArticleChapters` inserts right after `.reader-embed`).

- [ ] **Step 7: Body styles.** In `article-content.component.scss`, delete these rules, which now live in `player-bar.component.scss`:
  - `.reader-player-bar`;
  - `:is(.reader-cinema__toggle, .reader-listen__button)` and its `.material-symbols-outlined` rule;
  - `.reader-listen__button`;
  - `.reader-listen__button--queued`;
  - `.reader-cinema__toggle`, with the comment above it, and `.reader-cinema__toggle kbd`;
  - the `.reader-cinema__toggle` rule inside `@container reader-pane (…)`.

  Keep `.reader-cinema`, `.reader-cinema > :is(.reader-embed, video)` and the `.reader-cinema--on` rule with its comment inside the container query. If `glass-rim` is no longer used in the file, drop its `@use`; same for `bp`.

- [ ] **Step 8: Specs**
  - **`decorators/reader-cinema.spec.ts`:**
    - the `addCinemaToggles` describe becomes `wrapCinemaBoxes`: wraps a landscape embed, wraps a native video, leaves portrait/tall/audio embeds and native audio alone, idempotent;
    - the box now holds only the player; assert `box.children.length === 1`;
    - delete the "widens on click…" test (it moved to Task 1's spec);
    - `toggleCinemaByKey` tests build their toggles themselves: add a `<button class="reader-cinema__toggle">` inside a `.reader-cinema` per video in the fixture HTML, and keep each test's assertions.
  - **`decorators/decorate-article.spec.ts`:**
    - "puts the landscape embed … inside a cinema box" asserts only `.reader-cinema > .reader-embed iframe`;
    - delete the two listen tests (they move to `article-player-bars.spec.ts`);
    - the `decorateArticle` calls drop `listen` and no longer read a return value.
  - **`reader-view/article-embed-audio.spec.ts`:** delete the `follow` describe, and construct without the injector.
  - **Create `reader-view/article-player-bars.spec.ts`.** Use `TestBed` with `imports: [provideTranslocoTesting()]` and `providers: [provideAccountIdentity()]`, as `article-chapters.spec.ts` sets them up. Build `new ArticlePlayerBars(new ArticleIslands(TestBed.inject(Injector)))`. Tests:
    1. "puts a bar in a cinema box after its player, with Listen before the cinema toggle". Use the HTML `<div class="reader-cinema"><div class="reader-embed reader-embed--landscape"><iframe src="https://www.youtube-nocookie.com/embed/GhUuOxrCato?enablejsapi=1&origin=x"></iframe></div></div>` with jest-fn actions. Expect `box.children[1]` to be `app-player-bar.reader-player-bar.reader-listen`, and its buttons' classes to be `['reader-listen__button', 'reader-listen__button', 'reader-cinema__toggle']`.
    2. "gives a SoundCloud box a bar of its own, right after the box". Use `<div class="reader-embed"><iframe src="https://w.soundcloud.com/player/?url=https%3A%2F%2Fapi.soundcloud.com%2Ftracks%2F1"></iframe></div><p>Notes</p>`. Expect `host.children[1]` to be the bar, with no `.reader-cinema__toggle`. Use a real SoundCloud widget src that `embedMediaOfFrame` accepts; copy one from `article-chapters.spec.ts`'s per-embed fixtures.
    3. "offers only the cinema toggle without listen actions": the cinema fixture with `undefined` actions gives a bar with no `.reader-listen__button`.
    4. "adds no bar after an embed Listen cannot play" (e.g. a Vimeo iframe outside a cinema box).
    5. "hands Listen the media and the frame": click the Listen button, and expect `actions.listen` to have been called with `expect.objectContaining({ url: … })` and the iframe.
  - **`reader-view/article-body.service.spec.ts`:**
    - keep every Listen and cinema test;
    - selectors stay valid because the class names are unchanged;
    - where a test reads a button's state right after an action, add `TestBed.tick()` before asserting (the bar is a component now);
    - the `cinemaToggleClicks` helper spies on the button inside the bar, which stays the same element.

  If a test asserted the old toggle text, update the expected text to the new rendering and note it.

- [ ] **Step 9: Run**

Run: `docker compose exec -T frontend npx jest src/app/reader/article`
Expected: PASS. Also `grep -rn "reader-listen'" frontend/src` prints nothing, and neither does `grep -rn "addCinemaToggles\|EmbedListenControl\|showQueued" frontend/src`.

- [ ] **Step 10: Gate and commit**

```bash
docker compose exec -T frontend npm run check
docker compose exec -T frontend npx ng build
git add -A frontend/src/app/reader/article
git commit -m "refactor(#1888): mount the player bar as app-player-bar"
```

The `ng build` output's article-content budget line must show the file smaller than 13.86 kB. Quote it in the report.

---

### Task 3: Docs, and a live check on the real render

**Files:**
- Modify: `docs/design-language.md`

- [ ] **Step 1: `docs/design-language.md`**
  - In the #1886 paragraph "A component inside the article body is mounted, not decorated", change "First user: `app-embed-chapters`." to "Users: `app-embed-chapters` and `app-player-bar` (the Listen, queue and Cinema bar under a body player, #1888)."
  - Search for `reader-cinema.ts`, `reader-listen.ts`, `addCinemaToggles` and `.reader-player-bar` in `docs/` and update each live-doc mention to the component. Historical `docs/superpowers/` specs stay as they are.
  - Commit: `docs(#1888): record the player bar as a mounted component`.

- [ ] **Step 2: Live check on :4200 (controller).**
  - Use a YouTube entry with an API player; entry 655280 (subscription 1976) works. Set the browser pane's viewport to 1280×900 first: a collapsed pane measures 0 width.
  - Check:
    1. The bar is inside `.reader-cinema` after the embed, with classes `reader-player-bar reader-listen reader-island`, in the order Listen, Add to playlist, Cinema mode.
    2. Its buttons' computed `font-size`, `padding` and `border-radius` match what develop rendered: `--fs-xs`, `--space-1`/`--space-2`, `--radius-sm`.
    3. Cinema widens the box (the `.reader-cinema--on` width grows), and `t` narrows it again.
    4. Add to playlist shows "In playlist", pressed, and removing it from the player's queue flips it back.
    5. Listen pauses the video and the audio player plays it.
    6. A SoundCloud embed (find one through `/api/entries?…` or the search) gets its own bar right after its `.reader-embed`, without Cinema.
    7. No console errors.

- [ ] **Step 3: e2e.** From `frontend/`, run `npx playwright test e2e/listen-to-embed.spec.ts e2e/article-tail-space.spec.ts e2e/chapters.spec.ts`; all pass.
