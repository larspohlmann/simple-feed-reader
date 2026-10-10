# Video Cinema Mode Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** A one-click "Cinema" toggle under every landscape video in the reader that widens it from the 720px reading column to the reader pane's width, plus a `t` shortcut (#1479).

**Architecture:** A new article decorator (`reader-cinema.ts`, beside `reader-slideshow.ts`) wraps each landscape player in a `.reader-cinema` box with a list-action button beneath it; the button flips a `reader-cinema--on` class. CSS does the sizing: the reader's `.scroller` becomes a size container, so the "on" box is `max(100%, min(pane width − gutters, usable pane height × 16/9))` wide and centred on the column with negative inline margins. A container query hides the toggle where the pane is too narrow to gain anything. The `t` key is a document keydown host binding on `ReaderViewComponent` that clicks the toggle of the video in view.

**Tech Stack:** Angular 20 (standalone, signals), Jest + jsdom, SCSS with container queries, Transloco i18n, Material Symbols font.

**Spec:** GitHub issue #1479 (agreed design: visual round, variant B — quiet button under the player, icon + "Cinema" + `t` hint; label flips to "Exit cinema"; not remembered across articles; inside the reader pane, not a lightbox).

## Global Constraints

- Branch `feature/1479-video-cinema-mode`; commits `type(#1479): lower-case summary`.
- Landscape only: `.reader-embed` without `--tall`/`--portrait`, and native `<video>`. No toggle for audio, Shorts, Spotify collections.
- Not remembered: state lives on the DOM; a new article or re-render starts normal size. No storage.
- Never over the player: the button sits below it.
- Styles in the sibling `.scss`; no hex colours, no ad-hoc px spacing, breakpoints from `theme/_breakpoints.scss` (Stylelint enforces).
- Comments: default none; one line where a reader would otherwise get it wrong.
- Frontend tests run in the Docker frontend container: `docker compose exec -T frontend npm test -- <path>`. Gate: `docker compose exec -T frontend npm run check`.

---

### Task 1: Cinema toggle decorator

**Files:**
- Create: `frontend/src/app/reader/article/decorators/reader-cinema.ts`
- Test: `frontend/src/app/reader/article/decorators/reader-cinema.spec.ts`
- Modify: `frontend/src/app/reader/article/decorators/decorate-article.ts` (call after `upgradeMediaEmbeds`)
- Modify: `frontend/public/i18n/en.json`, `frontend/public/i18n/de.json` (after `slideshowPosition` in the `reader` block)

**Interfaces:**
- Produces: `interface CinemaLabels { enter: string; exit: string }`, `addCinemaToggles(host: HTMLElement, labels: CinemaLabels): void`. DOM contract used by Tasks 2–3: wrapper `div.reader-cinema` (+ `reader-cinema--on` when widened) containing the player, then `div.reader-cinema__bar` > `button.list-action.reader-cinema__toggle[aria-keyshortcuts="t"]`.

- [ ] **Step 1: Write the failing spec**

```ts
import { addCinemaToggles, type CinemaLabels } from './reader-cinema';

const labels: CinemaLabels = { enter: 'Cinema', exit: 'Exit cinema' };

function host(html: string): HTMLElement {
  const element = document.createElement('div');
  element.innerHTML = html;
  return element;
}

const LANDSCAPE = '<div class="reader-embed"><iframe src="https://www.youtube-nocookie.com/embed/x"></iframe></div>';

describe('addCinemaToggles', () => {
  beforeAll(() => {
    Element.prototype.scrollIntoView = jest.fn();
  });

  it('wraps a landscape embed with a cinema toggle beneath it', () => {
    const element = host(LANDSCAPE);
    addCinemaToggles(element, labels);
    const box = element.querySelector('.reader-cinema')!;
    expect(box.firstElementChild!.classList.contains('reader-embed')).toBe(true);
    const toggle = box.querySelector('.reader-cinema__bar > button.reader-cinema__toggle')!;
    expect(toggle.classList.contains('list-action')).toBe(true);
    expect(toggle.getAttribute('type')).toBe('button');
    expect(toggle.getAttribute('aria-keyshortcuts')).toBe('t');
    expect(toggle.textContent).toBe('width_wideCinemat');
  });

  it('wraps a native video', () => {
    const element = host('<p>Intro</p><video src="https://cdn/clip.mp4"></video>');
    addCinemaToggles(element, labels);
    expect(element.querySelector('.reader-cinema > video')).not.toBeNull();
  });

  it('leaves portrait and tall embeds and audio alone', () => {
    const element = host(
      '<div class="reader-embed reader-embed--portrait"><iframe></iframe></div>' +
        '<div class="reader-embed reader-embed--tall"><iframe></iframe></div>' +
        '<audio src="https://cdn/a.mp3"></audio>',
    );
    addCinemaToggles(element, labels);
    expect(element.querySelector('.reader-cinema')).toBeNull();
  });

  it('is idempotent', () => {
    const element = host(LANDSCAPE);
    addCinemaToggles(element, labels);
    addCinemaToggles(element, labels);
    expect(element.querySelectorAll('.reader-cinema')).toHaveLength(1);
    expect(element.querySelectorAll('.reader-cinema__toggle')).toHaveLength(1);
  });

  it('widens on click, flips the label and icon, and narrows again', () => {
    const element = host(LANDSCAPE);
    addCinemaToggles(element, labels);
    const box = element.querySelector<HTMLElement>('.reader-cinema')!;
    const toggle = element.querySelector<HTMLButtonElement>('.reader-cinema__toggle')!;

    toggle.click();
    expect(box.classList.contains('reader-cinema--on')).toBe(true);
    expect(toggle.textContent).toBe('width_normalExit cinemat');
    expect(box.scrollIntoView).toHaveBeenCalledWith({ block: 'nearest' });

    toggle.click();
    expect(box.classList.contains('reader-cinema--on')).toBe(false);
    expect(toggle.textContent).toBe('width_wideCinemat');
  });
});
```

- [ ] **Step 2: Run it to verify it fails**

Run: `docker compose exec -T frontend npm test -- src/app/reader/article/decorators/reader-cinema.spec.ts`
Expected: FAIL — cannot find module `./reader-cinema`.

- [ ] **Step 3: Implement the decorator**

```ts
export interface CinemaLabels {
  enter: string;
  exit: string;
}

const LANDSCAPE_PLAYER = '.reader-embed:not(.reader-embed--tall, .reader-embed--portrait), video';
const BOX = 'reader-cinema';
const WIDENED = 'reader-cinema--on';

/** Puts a Cinema toggle beneath every landscape player, which widens it past the reading measure (#1479). Idempotent. */
export function addCinemaToggles(host: HTMLElement, labels: CinemaLabels): void {
  for (const player of Array.from(host.querySelectorAll<HTMLElement>(LANDSCAPE_PLAYER))) {
    if (player.parentElement?.classList.contains(BOX)) continue;
    const box = document.createElement('div');
    box.className = BOX;
    player.replaceWith(box);
    box.append(player, toggleBar(box, labels));
  }
}

function toggleBar(box: HTMLElement, labels: CinemaLabels): HTMLElement {
  const icon = document.createElement('span');
  icon.className = 'material-symbols-outlined';
  icon.setAttribute('aria-hidden', 'true');
  const label = document.createElement('span');
  const key = document.createElement('kbd');
  key.setAttribute('aria-hidden', 'true');
  key.textContent = 't';

  const toggle = document.createElement('button');
  toggle.type = 'button';
  toggle.className = 'list-action reader-cinema__toggle';
  toggle.setAttribute('aria-keyshortcuts', 't');
  toggle.append(icon, label, key);

  const render = (): void => {
    const widened = box.classList.contains(WIDENED);
    icon.textContent = widened ? 'width_normal' : 'width_wide';
    label.textContent = widened ? labels.exit : labels.enter;
  };
  toggle.addEventListener('click', () => {
    box.classList.toggle(WIDENED);
    render();
    box.scrollIntoView({ block: 'nearest' });
  });
  render();

  const bar = document.createElement('div');
  bar.className = 'reader-cinema__bar';
  bar.append(toggle);
  return bar;
}
```

- [ ] **Step 4: Wire it in and add the labels**

`decorate-article.ts`: `import { addCinemaToggles } from './reader-cinema';` and, directly after `upgradeMediaEmbeds(host);`:

```ts
  addCinemaToggles(host, {
    enter: i18n.translate('reader.cinema'),
    exit: i18n.translate('reader.cinemaExit'),
  });
```

`en.json` after `"slideshowPosition"`: `"cinema": "Cinema",` / `"cinemaExit": "Exit cinema",`
`de.json` after `"slideshowPosition"`: `"cinema": "Kino",` / `"cinemaExit": "Kino beenden",`

- [ ] **Step 5: Run the spec — PASS. Commit**

```bash
git add frontend/src/app/reader/article/decorators/reader-cinema.ts frontend/src/app/reader/article/decorators/reader-cinema.spec.ts frontend/src/app/reader/article/decorators/decorate-article.ts frontend/public/i18n/en.json frontend/public/i18n/de.json
git commit -m "feat(#1479): add a cinema toggle beneath landscape videos"
```

---

### Task 2: Cinema sizing (CSS)

**Files:**
- Modify: `frontend/src/app/theme/_breakpoints.scss` (new container threshold)
- Modify: `frontend/src/app/reader/article/reader-view/reader-view.component.scss` (`.scroller` container + `--reader-top`)
- Modify: `frontend/src/app/reader/article/reader-view/reader-view.component.content.scss` (cinema rules)

**Interfaces:**
- Consumes: the Task 1 DOM contract.
- Produces: container `reader-pane` on `.scroller`; custom property `--reader-top` (the chrome height `.reader` pads by).

- [ ] **Step 1: Breakpoint.** Append to `_breakpoints.scss`:

```scss
$container-reader-cinema: $bp-lg; // reader pane width at or below which a widened video gains too little to offer
```

- [ ] **Step 2: Container + chrome height.** In `reader-view.component.scss`, make `.scroller` the container and name the top padding once so the cinema height cap can subtract it:

```scss
.scroller {
  --reader-top: calc(var(--reader-mini-h) + var(--reader-bar-h) + var(--bar-gap));

  container: reader-pane / size;
  height: 100%;
  overflow: auto;
  isolation: isolate;
  scroll-padding-top: calc(var(--reader-mini-h) + var(--bar-gap));
}

.reader {
  /* …existing… */
  padding-top: var(--reader-top);
}

.with-bar .scroller {
  --reader-top: calc(var(--app-bar-h, var(--bar-h)) + var(--reader-bar-h) + var(--bar-gap));

  scroll-padding-top: var(--reader-top);
}
```

Delete the now-redundant `.with-bar .reader { padding-top: … }` rule. Keep the existing comments on these blocks. Note the full-screen `scroll-padding-top` deliberately differs from its padding (no `--reader-bar-h`) — leave it as is.

- [ ] **Step 3: Cinema rules.** In `reader-view.component.content.scss` add `@use '../../../theme/breakpoints' as bp;` at the top, and after the `.content ::ng-deep video { aspect-ratio … }` block:

```scss
/* Cinema mode (#1479): the widened box fills the pane but stays short enough for
   the whole 16:9 frame and its toggle to fit the visible pane; never narrower than the column. */
.content ::ng-deep .reader-cinema {
  --cinema-width: max(
    100%,
    min(
      100cqi - 2 * var(--space-4),
      (100cqb - var(--reader-top) - var(--tap-target) - 2 * var(--space-5)) * 16 / 9
    )
  );

  margin: var(--space-5) 0;
}

.content ::ng-deep .reader-cinema > :is(.reader-embed, video) {
  margin: 0;
}

.content ::ng-deep .reader-cinema--on {
  width: var(--cinema-width);
  margin-inline: calc((100% - var(--cinema-width)) / 2);
}

.content ::ng-deep .reader-cinema__bar {
  display: flex;
  justify-content: flex-end;
  margin-top: var(--space-2);
}

.content ::ng-deep .reader-cinema__toggle kbd {
  padding: 0 var(--space-1);
  border: 1px solid var(--border);
  border-radius: var(--radius-sm);
  font-family: inherit;
  font-size: var(--fs-xs);
  color: var(--text-muted);
}

@container reader-pane (width <= #{bp.$container-reader-cinema}) {
  .content ::ng-deep .reader-cinema__bar {
    display: none;
  }

  .content ::ng-deep .reader-cinema--on {
    width: auto;
    margin-inline: 0;
  }
}
```

All tokens above are in use elsewhere in the tree (`--radius-sm`, `--fs-xs`, `--space-1`, `--tap-target`, `--border`, `--text-muted`); `1px solid var(--border)` is the existing hairline idiom (e.g. `mail-section.component.scss:50`).

- [ ] **Step 4: Verify in the real render.** Restart/confirm the `frontend` container serves the current tree. In the built-in browser at 1440×900 open `http://localhost:4200/?subscription=1714&entry=575841-national-fossil-day-2026`:
  - The "Cinema t" button sits right-aligned under the player; clicking widens the player centred in the pane with no horizontal page scroll; the whole frame and the button stay visible; text keeps its 720px measure; "Exit cinema" restores.
  - Short window (1440×600): the widened player is height-capped and never narrower than the column.
  - Narrow pane (1000×800 and the Mobile preset): no button; the player is unchanged.
  - Mobile full-screen overlay still scrolls and lays out as before (size containment on `.scroller` must not collapse it).
  - Find an entry with a native `<video>` (`grep`/SQL not needed — browse a ZDF/tagesschau feed) and repeat the click check.
  - Light and dark theme: the `kbd` hint is legible.

- [ ] **Step 5: Commit**

```bash
git add frontend/src/app/theme/_breakpoints.scss frontend/src/app/reader/article/reader-view/reader-view.component.scss frontend/src/app/reader/article/reader-view/reader-view.component.content.scss
git commit -m "feat(#1479): widen a cinema video to the reader pane"
```

---

### Task 3: The `t` shortcut

**Files:**
- Create: `frontend/src/app/shared/text-entry-target.ts` (moved from `search-field.component.ts`)
- Modify: `frontend/src/app/reader/shell/search-field/search-field.component.ts` (import it, delete the local copy)
- Modify: `frontend/src/app/reader/article/decorators/reader-cinema.ts` (+ `toggleCinemaByKey`)
- Modify: `frontend/src/app/reader/article/decorators/reader-cinema.spec.ts`
- Modify: `frontend/src/app/reader/article/reader-view/reader-view.component.ts` (host binding)

**Interfaces:**
- Consumes: Task 1 DOM contract; Task 2 hides `.reader-cinema__bar` with `display: none` when cinema is not offered.
- Produces: `isTextEntryTarget(target: EventTarget | null): boolean` (shared); `toggleCinemaByKey(event: KeyboardEvent, host: HTMLElement | undefined): void`.

- [ ] **Step 1: Move `isTextEntryTarget`.** Create `shared/text-entry-target.ts`:

```ts
/** Elements a bare-key shortcut must type into rather than steal from. Matches the
 *  event target itself, so a key typed inside a field always reaches the field. */
export function isTextEntryTarget(target: EventTarget | null): boolean {
  if (!(target instanceof HTMLElement)) return false;
  return target.tagName === 'INPUT' || target.tagName === 'TEXTAREA' || target.isContentEditable;
}
```

In `search-field.component.ts` delete the local function and its doc comment, and add `import { isTextEntryTarget } from '../../../shared/text-entry-target';`.

- [ ] **Step 2: Write the failing specs** (append to `reader-cinema.spec.ts`; extend the import to `addCinemaToggles, toggleCinemaByKey, type CinemaLabels`):

```ts
function key(init: KeyboardEventInit = {}, target?: HTMLElement): KeyboardEvent {
  const event = new KeyboardEvent('keydown', { key: 't', cancelable: true, ...init });
  if (target) Object.defineProperty(event, 'target', { value: target });
  return event;
}

function twoVideos(): HTMLElement {
  const element = host(LANDSCAPE + LANDSCAPE);
  addCinemaToggles(element, labels);
  return element;
}

const widened = (element: HTMLElement) =>
  Array.from(element.querySelectorAll('.reader-cinema'), (box) =>
    box.classList.contains('reader-cinema--on'),
  );

describe('toggleCinemaByKey', () => {
  beforeAll(() => {
    Element.prototype.scrollIntoView = jest.fn();
  });

  it('toggles the first video when none is in view', () => {
    const element = twoVideos();
    const event = key();
    toggleCinemaByKey(event, element);
    expect(widened(element)).toEqual([true, false]);
    expect(event.defaultPrevented).toBe(true);
  });

  it('toggles the video in view', () => {
    const element = twoVideos();
    const second = element.querySelectorAll<HTMLElement>('.reader-cinema__toggle')[1];
    second.getBoundingClientRect = () => ({ top: 100, bottom: 400 }) as DOMRect;
    toggleCinemaByKey(key(), element);
    expect(widened(element)).toEqual([false, true]);
  });

  it.each([{ metaKey: true }, { ctrlKey: true }, { altKey: true }, { key: 'T' }, { key: 'x' }])(
    'ignores %o',
    (init) => {
      const element = twoVideos();
      toggleCinemaByKey(key(init), element);
      expect(widened(element)).toEqual([false, false]);
    },
  );

  it('ignores a key typed into a field', () => {
    const element = twoVideos();
    toggleCinemaByKey(key({}, document.createElement('input')), element);
    expect(widened(element)).toEqual([false, false]);
  });

  it('does nothing where cinema is not offered', () => {
    const element = twoVideos();
    element.querySelectorAll<HTMLElement>('.reader-cinema__bar').forEach((bar) => {
      bar.style.display = 'none';
    });
    const event = key();
    toggleCinemaByKey(event, element);
    expect(widened(element)).toEqual([false, false]);
    expect(event.defaultPrevented).toBe(false);
  });

  it('does nothing without an article', () => {
    expect(() => toggleCinemaByKey(key(), undefined)).not.toThrow();
  });
});
```

(`{ key: 'T' }` is ignored on purpose: Shift+t should stay free, matching YouTube's lowercase `t`.)

- [ ] **Step 3: Run — FAIL** (`toggleCinemaByKey` is not exported).

Run: `docker compose exec -T frontend npm test -- src/app/reader/article/decorators/reader-cinema.spec.ts`

- [ ] **Step 4: Implement** in `reader-cinema.ts` (add `import { isTextEntryTarget } from '../../../shared/text-entry-target';`):

```ts
/** `t` widens or narrows the video in view, else the first one, like YouTube's theatre key. */
export function toggleCinemaByKey(event: KeyboardEvent, host: HTMLElement | undefined): void {
  if (!host || !isCinemaKey(event)) return;
  const toggles = Array.from(host.querySelectorAll<HTMLButtonElement>('.reader-cinema__toggle')).filter(isOffered);
  const toggle = toggles.find(isInViewport) ?? toggles[0];
  if (!toggle) return;
  event.preventDefault();
  toggle.click();
}

function isCinemaKey(event: KeyboardEvent): boolean {
  if (event.key !== 't' || event.defaultPrevented) return false;
  if (event.metaKey || event.ctrlKey || event.altKey) return false;
  return !isTextEntryTarget(event.target);
}

function isOffered(toggle: HTMLElement): boolean {
  return toggle.parentElement !== null && getComputedStyle(toggle.parentElement).display !== 'none';
}

function isInViewport(toggle: HTMLElement): boolean {
  const { top, bottom } = toggle.getBoundingClientRect();
  return bottom > 0 && top < window.innerHeight;
}
```

- [ ] **Step 5: Bind it.** In `reader-view.component.ts` add `toggleCinemaByKey` to the `reader-cinema` import (new import line), and to the `@Component` metadata:

```ts
  host: { '(document:keydown)': 'onKeydown($event)' },
```

and a method beside `onBack()`:

```ts
  protected onKeydown(event: KeyboardEvent): void {
    toggleCinemaByKey(event, this.content()?.nativeElement);
  }
```

- [ ] **Step 6: Run the cinema and search-field specs — PASS.**

Run: `docker compose exec -T frontend npm test -- src/app/reader/article/decorators/reader-cinema.spec.ts src/app/reader/shell/search-field`

- [ ] **Step 7: Verify in the real render**: on the fossil-day entry press `t` (focus on the page, not the YouTube frame — a focused cross-origin iframe swallows keys, which is expected): the video widens, `t` again narrows; typing `t` in the sidebar search does not toggle; at the Mobile preset `t` does nothing.

- [ ] **Step 8: Commit**

```bash
git add frontend/src/app/shared/text-entry-target.ts frontend/src/app/reader/shell/search-field/search-field.component.ts frontend/src/app/reader/article/decorators/reader-cinema.ts frontend/src/app/reader/article/decorators/reader-cinema.spec.ts frontend/src/app/reader/article/reader-view/reader-view.component.ts
git commit -m "feat(#1479): toggle cinema mode with the t key"
```

---

### Task 4: Gate and PR

- [ ] **Step 1:** `docker compose exec -T frontend npm run check` — all green (ESLint, Prettier, Stylelint, tsc, Jest). Fix findings in the files they name.
- [ ] **Step 2:** Reset the built-in browser viewport (`resize_window` preset `desktop`).
- [ ] **Step 3:** Push and open a PR into `develop`, body ending `Closes #1479`, with before/after screenshots of the fossil-day entry.
