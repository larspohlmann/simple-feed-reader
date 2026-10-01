# Article chrome outside the scroller (#1332) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** A tap on the article's toolbar, nameplate or back-to-top button lands on an iPhone while the article is still coasting, as it already does on the list.

**Architecture:** iOS WebKit spends a tap inside a coasting overflow scroller on stopping the scroll. Today `ReaderViewComponent`'s host *is* the scroller, so its sticky toolbar, nameplate and fixed back-to-top button are all inside it. The host stops scrolling: it holds a `.frame` (the nameplate and toolbar floating over an inner `.scroller`) and the back-to-top button beside it, the arrangement the entry list already uses (`.list-header` over `.rows`, #87). The scroller reserves the toolbar's measured height (`--reader-bar-h`, published like `--list-bar-h`). The scroller becomes a view query the reader view hands to its helpers, so the `READER_SCROLLER` token goes.

**Tech Stack:** Angular 20 standalone components + signals, Jest (jsdom), Playwright e2e, SCSS + Stylelint.

**Spec:** GitHub issue #1332 (https://github.com/larspohlmann/simple-feed-reader/issues/1332) and its root-cause comment. The first attempt (#1333: out-of-zone scroll listener) did not fix it on the device and was reverted (#1334).

## Global Constraints

- Branch `fix/1332-article-chrome-outside-scroller` off `develop`; PR into `develop` with body `Closes #1332`; merge and deploy only when Lars asks.
- Commit format `type(#1332): lower-case summary`; no attribution lines.
- Frontend tests only inside the container, one Jest run at a time: `docker compose exec -T frontend npm test -- <path>`. The gate is `docker compose exec -T frontend npm run check` (ESLint + Prettier 100-col + Stylelint + Jest).
- No hex colours, ad-hoc `px` spacing or media-query literals outside `src/app/theme/` and `src/styles*`.
- Comments only where a future reader would get the code wrong without them; one line, three at most. Delete comments the change makes stale.
- Keep `#100`: no transformed ancestor over a `position: fixed` element. Keep `#128`: the article chrome stays in the article's own layer, never portalled into the shell.
- e2e is outside the gate; update the specs that scroll the pane so they keep working, but don't run the full e2e suite.

## File Structure

| File | Responsibility |
|---|---|
| `frontend/src/app/reader/scroll/reader-scroller.ts` | **Deleted**: the `READER_SCROLLER` token |
| `frontend/src/app/reader/article/reader-view/reader-view.component.{ts,html,scss}` | The article layer: frame, chrome, inner scroller, back-to-top; owns the scroller query and the toolbar measurement |
| `frontend/src/app/reader/article/reader-view/article-gestures.service.ts` | Return gestures: touch on the whole layer; swipe moves the frame, pull moves the article inside the scroller, no transform at rest |
| `frontend/src/app/reader/article/reading/article-scroll-restore.service.ts` | Restores into the connected scroller; wheel abort on the layer |
| `frontend/src/app/reader/article/reading/reading-scope.service.ts` | Reading focus and measurements against the connected scroller |
| `frontend/src/app/reader/article/entry-comments/entry-comments.component.{ts,spec.ts}` | Takes its scroll root as an input |
| `frontend/src/app/reader/article/reader-view/reader-view.component.spec.ts` | Scrolls `.scroller`; new structure, measurement, transform and heading tests |
| `frontend/src/app/theme/tokens.scss` | `--reader-bar-h: 0px` default |
| `docs/design-language.md` | Token row; "chrome a tap must reach stays outside the scroller" rule |
| `frontend/e2e/*.spec.ts` (8 specs) | Scroll and measure `app-reader-view .scroller` instead of the host |

---

### Task 1: The reader view hands its scroller to its helpers

A refactor with no behaviour change: the host is still the scroller, but nothing injects it any more.

**Files:**
- Delete: `frontend/src/app/reader/scroll/reader-scroller.ts`
- Modify: `article-gestures.service.ts`, `article-scroll-restore.service.ts`, `reading-scope.service.ts`, `entry-comments.component.ts`, `entry-comments.component.spec.ts`, `reader-view.component.{ts,html}`

**Interfaces:**
- Produces:
  - `ArticleGestureHost { fullscreen: Signal<boolean>; scroller: Signal<HTMLElement | undefined>; close: () => void }`
  - `ArticleScrollRestore.connect(scroller: Signal<HTMLElement | undefined>): void`
  - `ReadingScope.connect(parts: ReadingScopeParts)` with `ReadingScopeParts { scroller: Signal<HTMLElement | undefined>; content: ElementQuery; comments: ElementQuery }`
  - `EntryCommentsComponent.scroller = input.required<HTMLElement>()`

- [ ] **Step 1: Point the comments spec at the input (failing test)**

In `entry-comments.component.spec.ts` drop the `READER_SCROLLER` import and provider, and set the input in `show()`:

```ts
function show(comments: CommentsLoad, id = 5, discussionUrl: string | null = DISCUSSION): void {
  fixture.componentRef.setInput('scroller', scroller);
  fixture.componentRef.setInput('entry', { id, comments, discussionUrl });
  fixture.detectChanges();
}
```

- [ ] **Step 2: Run it, expect FAIL** (`NG0303: Can't set value of the 'scroller' input`)

`docker compose exec -T frontend npm test -- src/app/reader/article/entry-comments`

- [ ] **Step 3: Comments take the input**

```ts
readonly entry = input.required<CommentsEntry>();
/** The pane whose viewport the section loads on sight of. */
readonly scroller = input.required<HTMLElement>();
```

`loadOnSight` reads `const scroller = this.scroller();` and passes `root: scroller, rootMargin: prefetchMargin(scroller.clientHeight)`. Remove the `READER_SCROLLER` import and field.

- [ ] **Step 4: Helpers receive the scroller**

`ArticleGestures`: touch listeners attach to the layer it is provided on (`inject<ElementRef<HTMLElement>>(ElementRef).nativeElement`) — one line of comment saying a swipe may start anywhere on the article layer. `ArticleGestureHost` gains `scroller`; the default host carries `scroller: signal(undefined)`. In `onTouchStart`:

```ts
const scroller = this.host.scroller();
this.atBottomOnStart = scroller !== undefined && atBottom(scroller);
```

`ArticleScrollRestore`: the wheel listener moves to the layer (`inject(ElementRef)`), `private scroller: Signal<HTMLElement | undefined> = signal(undefined);`, `connect(scroller)` assigns it, and `startRestore` takes `const scroller = this.scroller(); if (!pending || !scroller) return;` and writes `scroller.scrollTop` in the first landing and in `step`.

`ReadingScope`:

```ts
export interface ReadingScopeParts {
  readonly scroller: Signal<HTMLElement | undefined>;
  readonly content: ElementQuery;
  readonly comments: ElementQuery;
}
```

`connect(parts)` stores all three. The applier effect reads `const scroller = this.scroller();` beside the content and returns early without either. `measureScrollRange` reads `untracked(this.scroller)`; with no scroller or no content it zeroes all three measurements. `bottomInScroller` becomes a module function `bottomWithin(scroller: HTMLElement, element: HTMLElement): number`.

- [ ] **Step 5: The reader view connects them**

Remove the `READER_SCROLLER` provider and import. Add `protected readonly scroller = computed(() => this.host.nativeElement);` (Task 2 replaces it with the view query), and connect:

```ts
this.gestures.connect({ fullscreen: this.fullscreen, scroller: this.scroller, close: () => this.close.emit() });
this.restore.connect(this.scroller);
...
this.scope.connect({ scroller: this.scroller, content: this.content, comments: this.commentsSection });
```

Template: `<app-entry-comments [entry]="e" [scroller]="scroller()" />`. Delete `reader/scroll/reader-scroller.ts`.

- [ ] **Step 6: Run the article specs, expect PASS**

`docker compose exec -T frontend npm test -- src/app/reader/article`

- [ ] **Step 7: Commit**

```bash
git add -A frontend/src/app/reader
git commit -m "refactor(#1332): the reader view hands its scroller to its helpers"
```

---

### Task 2: The article chrome floats outside the scroller

**Files:**
- Modify: `reader-view.component.{ts,html,scss,spec.ts}`, `article-gestures.service.ts`, `frontend/src/app/theme/tokens.scss`

**Interfaces:**
- Consumes: Task 1's `connect` signatures.
- Produces: DOM `app-reader-view > .frame > (.mini?, .bar, .scroller > .reader)` plus `app-reader-view > app-to-top-button`; CSS var `--reader-bar-h` on the host; `ArticleGestures.swipeTransform`, `.pullTransform`, `.snapTransition` (replacing `readerTransform` / `readerTransition`).

- [ ] **Step 1: Failing tests**

Add a helper and rewrite the scroll tests against it:

```ts
const scrollerOf = (fixture: ComponentFixture<ReaderViewComponent>): HTMLElement =>
  (fixture.nativeElement as HTMLElement).querySelector('.scroller') as HTMLElement;
```

- back-to-top: `scrollHostTo` scrolls `scrollerOf(fixture)`, and the `scrollTo` spy goes on the scroller.
- scroll restore: `mountRemembering` runs `detectChanges()` first, then `trackScrollTop(scrollerOf(fixture))`.
- tail space: `stubGeometry` pins `clientHeight` and `getBoundingClientRect` on the scroller.
- hide-on-scroll: `scrollHostTo` scrolls the scroller.
- "reserves the app bar only in the split pane": the class sits on `.frame`.
- back-button slide-out: the transform is read from `.frame`.

New tests:

```ts
// #1332: iOS spends a tap inside a coasting scroller on stopping it.
it('keeps the chrome a tap must reach outside the article’s scroller', () => {
  const fixture = TestBed.createComponent(ReaderViewComponent);
  fixture.componentRef.setInput('entry', entry());
  fixture.componentRef.setInput('fullscreen', true);
  fixture.detectChanges();
  const element = fixture.nativeElement as HTMLElement;
  const scroller = scrollerOf(fixture);
  scroller.scrollTop = 900;
  scroller.dispatchEvent(new Event('scroll'));
  fixture.detectChanges();

  for (const chrome of ['.mini', '.bar', 'app-to-top-button']) {
    expect(scroller.contains(element.querySelector(chrome))).toBe(false);
  }
  expect(scroller.contains(element.querySelector('.content'))).toBe(true);
  fixture.destroy();
});

it('reserves the toolbar’s measured height above the article', () => {
  const fixture = mount(entry());
  const element = fixture.nativeElement as HTMLElement;
  const bar = element.querySelector('.bar') as HTMLElement;
  Object.defineProperty(bar, 'offsetHeight', { configurable: true, value: 44 });

  MockResizeObserver.instances.find((observer) => observer.targets.has(bar))!.fire();

  expect(element.style.getPropertyValue('--reader-bar-h')).toBe('44px');
});

it('lands a contents jump below the chrome covering the scroller', async () => {
  const fixture = mount(entryWithBody('<h2 id="a">A</h2><p>a</p><h2 id="b">B</h2><p>b</p>'));
  await Promise.resolve();
  fixture.detectChanges();
  const scroller = scrollerOf(fixture);
  const heading = scroller.querySelector('.content h2:last-of-type') as HTMLElement;
  heading.getBoundingClientRect = () => ({ top: 700 }) as DOMRect;
  scroller.getBoundingClientRect = () => ({ top: 0 }) as DOMRect;
  jest.spyOn(window, 'getComputedStyle').mockReturnValue({ scrollPaddingTop: '120px' } as CSSStyleDeclaration);
  const scrollTo = jest.fn();
  scroller.scrollTo = scrollTo as unknown as typeof scroller.scrollTo;

  fixture.componentInstance.scrollToHeading(heading.id);

  expect(scrollTo).toHaveBeenCalledWith(expect.objectContaining({ top: 580 }));
});
```

In the gestures block:

```ts
it('moves nothing at rest, the layer on a swipe and the article on a pull', () => {
  const fixture = fullscreen();
  const element = fixture.nativeElement as HTMLElement;
  const frame = element.querySelector('.frame') as HTMLElement;
  const reader = element.querySelector('.reader') as HTMLElement;
  expect([frame.style.transform, reader.style.transform]).toEqual(['none', 'none']);

  gestures(fixture).onTouchStart(touch(0, 0));
  gestures(fixture).onTouchMove(touch(80, 4));
  fixture.detectChanges();
  expect(frame.style.transform).toBe('translate3d(80px, 0, 0)');
  expect(reader.style.transform).toBe('none');
  gestures(fixture).onTouchEnd();

  gestures(fixture).onTouchStart(touch(5, 300));
  gestures(fixture).onTouchMove(touch(7, 280));
  fixture.detectChanges();
  expect(frame.style.transform).toBe('none');
  expect(reader.style.transform).toMatch(/^translate3d\(0, -\d/);
  fixture.destroy();
});
```

(The expected heading id: read it from the rendered heading rather than assuming the decorator keeps `b`.)

- [ ] **Step 2: Run, expect FAIL** (no `.scroller`, no `.frame`, no `--reader-bar-h`)

`docker compose exec -T frontend npm test -- src/app/reader/article/reader-view`

- [ ] **Step 3: Gestures split the transform**

```ts
readonly swipeTransform = computed(() =>
  this.dragX() === 0 ? 'none' : `translate3d(${this.dragX()}px, 0, 0)`,
);
readonly pullTransform = computed(() =>
  this.pull() === 0 ? 'none' : `translate3d(0, ${-this.pull()}px, 0)`,
);
readonly snapTransition = computed(() =>
  !this.reduceMotion && this.snapping() ? `transform ${LEAVE_ANIM_MS}ms ease-out` : 'none',
);
```

The swipe moves the whole layer, toolbar included; the pull moves only the article inside the scroller, as the pull-back spinner it reveals lives at the article's end.

- [ ] **Step 4: Template**

```html
@if (entry(); as e) {
  <div
    class="frame"
    [class.with-bar]="!fullscreen()"
    [style.transform]="gestures.swipeTransform()"
    [style.transition]="gestures.snapTransition()"
  >
    @if (fullscreen()) { <div class="mini" aria-hidden="true">…unchanged…</div> }
    <div #bar class="bar" [class.hidden]="toolbarHidden()">…unchanged…</div>
    <div #scroller class="scroller" (scroll)="onScroll(scroller.scrollTop)">
      <div
        class="reader"
        [class.with-tail]="scope.hasTail()"
        [style.transform]="gestures.pullTransform()"
        [style.transition]="gestures.snapTransition()"
      >
        …progress-rail, article (with `<app-entry-comments [entry]="e" [scroller]="scroller" />`),
        loading overlay, pull-back, progress — unchanged…
      </div>
    </div>
  </div>
  @if (showToTop()) { <app-to-top-button (activate)="scrollToTop()" /> }
} @else { …placeholder… }
```

The unused `[class.leaving]` hook goes (no stylesheet or test reads it).

- [ ] **Step 5: Component**

- Drop `HostListener`. `private readonly scrollerRef = viewChild<ElementRef<HTMLElement>>('scroller');` and `private readonly scroller = computed(() => this.scrollerRef()?.nativeElement);` replace Task 1's host computed; `private readonly bar = viewChild<ElementRef<HTMLElement>>('bar');`.
- `protected onScroll(scrollTop: number)` — same body, taking the value from the template.
- `scrollToTop()`: `this.scroller()?.scrollTo(...)`.
- `scrollToHeading(id)`:

```ts
scrollToHeading(id: string): void {
  const scroller = this.scroller();
  const heading = this.content()?.nativeElement.querySelector<HTMLElement>(`#${CSS.escape(id)}`);
  if (!scroller || !heading) return;
  this.restore.abort(); // a jump takes over from any in-flight restore
  const top =
    heading.getBoundingClientRect().top - scroller.getBoundingClientRect().top + scroller.scrollTop;
  const covered = parseFloat(getComputedStyle(scroller).scrollPaddingTop) || 0;
  scroller.scrollTo({
    top: Math.max(0, top - covered),
    behavior: this.reduceMotion ? 'auto' : 'smooth',
  });
}
```

- Toolbar measurement, in the constructor:

```ts
// The toolbar floats over the scroller, which reserves its height (#1332).
effect((onCleanup) => {
  const bar = this.bar()?.nativeElement;
  if (!bar || typeof ResizeObserver === 'undefined') return;
  const style = this.host.nativeElement.style;
  const observer = new ResizeObserver(() =>
    style.setProperty('--reader-bar-h', `${bar.offsetHeight}px`),
  );
  observer.observe(bar);
  onCleanup(() => observer.disconnect());
});
```

Set from the observer, not through a signal: the reservation must land in the same frame as the layout, and nothing in TypeScript reads it. `offsetHeight` ignores the retraction transform, so retracting never changes the reservation.

- [ ] **Step 6: Stylesheet**

`tokens.scss`, beside `--list-bar-h`: `--reader-bar-h: 0px;` (the reader view overwrites it on its host once measured).

`reader-view.component.scss` — replace `:host`, `.reader`, `.reader.with-bar`, `.mini`, `.bar`, `.bar.hidden`, `.with-bar .bar` with:

```scss
/* `clip`, not `hidden`: the return swipe's overflow is cut without the host
   becoming a scroller the chrome would sit in (#1332). */
:host {
  display: block;
  height: 100%;
  overflow: clip;
}

/* The article layer the return swipe moves. The back-to-top button stays
   outside it: a transform re-anchors `position: fixed` (#100). */
.frame {
  position: relative;
  height: 100%;
}

/* The only scroller. Chrome a tap must reach stays outside it: iOS spends a tap
   inside a coasting scroller on stopping it (#1332). Isolated so nothing that
   scrolls, dimmed paragraphs and progress cues included, paints over that chrome. */
.scroller {
  height: 100%;
  overflow: auto;
  isolation: isolate;
  scroll-padding-top: calc(var(--reader-mini-h) + var(--bar-gap));
}

/* Opaque and full-height so this overlay hides the still-mounted list until a
   swipe slides it aside. Reserves the chrome floating above it (#87). */
.reader {
  position: relative;
  min-height: 100%;
  padding-top: calc(var(--reader-mini-h) + var(--reader-bar-h) + var(--bar-gap));
  background: var(--surface-read);
  container-type: inline-size;
  container-name: reader;
}

/* Split pane: the shell's bar floats over this panel and the toolbar hangs
   beneath it, never retracting, so the whole stack stays covered. */
.with-bar .scroller {
  scroll-padding-top: calc(var(--app-bar-h, var(--bar-h)) + var(--reader-bar-h) + var(--bar-gap));
}

.with-bar .reader {
  padding-top: calc(var(--app-bar-h, var(--bar-h)) + var(--reader-bar-h) + var(--bar-gap));
}

/* Standing nameplate: the toolbar retracts, leaving nothing naming a
   scrolled-down article (#270). Above the toolbar, which slides up beneath it. */
.mini {
  position: absolute;
  top: 0;
  left: 0;
  right: 0;
  z-index: 2;
  /* …display, gap, height, padding, border, background unchanged… */
}

.bar {
  position: absolute;
  top: var(--reader-mini-h);
  left: 0;
  right: 0;
  z-index: 1;
  transition: transform 0.12s ease;
  /* …display, alignment, padding, border, background unchanged; no margin-bottom… */
}

.bar.hidden {
  transform: translateY(-100%);
}

.with-bar .bar {
  top: var(--app-bar-h, var(--bar-h));
  transition: none;
}
```

Full-screen the scroll padding covers only the nameplate: a jump down retracts the toolbar. Delete the `.bar`/`.progress`/`.progress-rail` comments about reading focus' opacity painting over the toolbar where they now argue only for the toolbar (the progress cues keep `z-index: 2` against the dimmed paragraphs inside the scroller).

- [ ] **Step 7: Run, expect PASS**

`docker compose exec -T frontend npm test -- src/app/reader/article`

- [ ] **Step 8: Commit**

```bash
git add -A frontend/src/app
git commit -m "fix(#1332): the article chrome floats outside the scroller"
```

---

### Task 3: Design language and e2e specs follow the scroller

**Files:**
- Modify: `docs/design-language.md`; `frontend/e2e/{article-mini-header,reading-focus-long-paragraph,header-scroll-mobile,article-tail-space,article-reading-progress,reading-focus-blocks,article-back-desktop,unbreakable-word}.spec.ts` where they scroll or measure the pane.

- [ ] **Step 1: Design language**

Token table, after `--list-bar-h`: ``| `--reader-bar-h` | `0px` | the article's toolbar; overwritten on the reader view's host once measured |``.

"Sticky and scroll", before the floating-bar paragraph:

```markdown
**Chrome a tap must reach stays outside the scroller.** On iOS a tap inside a
scroller that is still coasting only stops the scroll; no click fires. The list's
header always sat beside `.rows`, so its icons worked mid-fling, while the
article's toolbar, nameplate and back-to-top button sat inside the article's own
scroller and swallowed the tap (#1332). Float such chrome over the scroller and
reserve its height, as the list and the article both do now.
```

- [ ] **Step 2: e2e locators**

Every `page.locator('app-reader-view')` that is scrolled (`scrollTo`, `scrollTop`, `scrollBy`, `mouse.wheel` targets) or measured as the scroll viewport becomes `page.locator('app-reader-view .scroller')`. Locators used only for visibility or as a selector prefix (`app-reader-view .bar .close`, `app-reader-view .content`) stay.

- [ ] **Step 3: Type-check the e2e specs**

`docker compose exec -T frontend npx tsc -p e2e/tsconfig.json --noEmit` (or the e2e tsconfig the repo uses).

- [ ] **Step 4: Commit**

```bash
git add docs/design-language.md frontend/e2e
git commit -m "docs(#1332): chrome a tap must reach stays outside the scroller"
```

---

### Task 4: Gate, render check, PR

- [ ] **Step 1:** `docker compose exec -T frontend npm run check` → all green.
- [ ] **Step 2:** In the built-in browser (Mobile viewport and desktop), open an article: the toolbar sits under the nameplate and over the article; scrolling down retracts it under the nameplate and the text runs beneath; scrolling up brings it back; the article opens with its title clear of the toolbar; split pane: toolbar under the app bar, title clear of both; the back-to-top button appears after a screen and works; the back-swipe slides toolbar and article together; a pull past the end reveals the spinner. Run the touched e2e specs that cover these (`article-mini-header`, `header-scroll-mobile`, `article-back-desktop`, `article-tail-space`, `article-reading-progress`).
- [ ] **Step 3:** Push, open the PR into `develop` with `Closes #1332`, and the device test Lars needs: tap the star while the article coasts.
