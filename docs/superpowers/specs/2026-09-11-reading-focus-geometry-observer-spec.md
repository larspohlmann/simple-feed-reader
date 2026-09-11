# Reading-focus dim: observe geometry instead of enumerating triggers — Spec

## Problem

The reading-focus dim (Mechanism A: fade each block by distance from the scroll
viewport centre) recomputes only when a hand-listed set of triggers fires. The
list keeps that list in the `_readingFocus` effect
(`entry-list.component.ts:513`); its own comment says each source it forgot to
list "was its own bug" (#462 and family).

Two facts make the enumeration a dead end:

- A same-breakpoint phone rotation (portrait↔landscape) flips **no** tracked
  signal (`screen.isWide()` stays `false`), so the declarative effect never
  re-runs. Only the imperative window-`resize` path fires, and it schedules a
  single rАF that samples geometry **before** the post-rotation layout settles.
  The wrong opacities then stick until some later event runs another pass.
- Enumerating causes never terminates: every new layout cause (orientation, a
  late-loading image, a font swap) is a new omission.

## Approach

Stop reacting to the *causes* of a geometry change. Observe the geometry.

One shared, framework-agnostic class, `ReadingFocusApplier`, owns the whole
recompute mechanism. Both the list and the article view construct one and feed
it their scroller, their block source, and their curve. The class drives its
recompute from:

- a **scroll listener** on the scroller — the viewport centre moves (already the
  right kind of signal today);
- a **multi-target `ResizeObserver`** over the scroller **and** each current
  block — the scroller target catches every viewport change (resize,
  orientation, split-pane, address bar) and fires *after* layout; the block
  targets catch content reflow (density change, row collapse, late images).

Two inputs have **no geometric signature** and stay explicit, pushed in by the
component as `refresh()` / `clear()` calls:

- **enable** — the on/off gate (`ReadingFocusService.enabled`);
- **the block set changing** — a finished load / load-more append / view switch
  (#462) / scroller element swap. The component already holds these as signals
  (`entries`, `selection`, the `rows`/`content` viewChild); it calls
  `refresh()`, which re-syncs the observed targets and schedules a pass.

### What this deletes

- The window-`resize` listener for focus in both components
  (`entry-list.component.ts:419-425`; the focus half of
  `reader-view.component.ts:405-409`).
- The `magazineStyle.style()` (density) focus trigger — airy resizes every row,
  now observed.
- The `onContentSettled` / `row-leave` `animationend` focus trigger (#478) — a
  collapsing row resizes, now observed.
- The per-component `scheduleFocus` / `applyFocus` / `clearFocus` / `focusRaf`
  and the `_readingFocus` effect body — moved into the applier.

### What stays

- The scroll listeners' **other** work (header collapse, `showToTop`,
  `scroll.save`, toolbar, `saveEntry`) — only the `this.scheduleFocus()` call is
  removed from them; the applier owns its own scroll listener.
- `screen.isWide()` gating — folded into the applier's `isActive()`.
- Reduced-motion gating — folded into `isActive()`.
- The article view's `contentObs` for `measureScrollRange` (a separate concern)
  and the `measureScrollRange()` call on resize.

## Contract

```ts
interface ReadingFocusConfig {
  scroller: HTMLElement;            // viewport; its clientHeight is the centre source
  blocks: () => HTMLElement[];      // current blocks to fade AND observe
  curve: FocusCurve;                // LIST_FOCUS_CURVE | ARTICLE_FOCUS_CURVE
  isActive: () => boolean;          // enabled && !isWide && !reduceMotion
  runOutsideZone?: <T>(run: () => T) => T; // default: run() directly
}

class ReadingFocusApplier {
  constructor(config: ReadingFocusConfig);
  refresh(): void; // re-sync observed targets, then schedule one coalesced pass
  clear(): void;   // synchronously blank every block's opacity; cancel a pending pass
  destroy(): void; // disconnect observer, remove scroll listener, cancel the rAF
}
```

Recompute (one rАF, coalesced, via `runOutsideZone`):

```
if (!isActive()) { clear(); return; }
const viewport = scroller.clientHeight;
const scrollerTop = scroller.getBoundingClientRect().top;
for (const block of blocks()) {
  const rect = block.getBoundingClientRect();
  const top = rect.top - scrollerTop;
  block.style.opacity = String(focusOpacityForSpan(top, top + rect.height, viewport, curve));
}
```

`clear()` runs synchronously (a disable must clear the same tick — the existing
list spec asserts the cleared state without awaiting a frame).

The scroll listener registration itself, not only the rAF, must go through `runOutsideZone` — otherwise the list's scroll path re-enters the Angular zone on every tick (#501).

## Global constraints (from CLAUDE.md)

- Angular 20 standalone + signals; no NgModules. Node 22.
- `ReadingFocusApplier` is a `final`-style plain class with no Angular imports,
  so it unit-tests without TestBed.
- Component styles in sibling `.scss`; no hex outside `theme/`; no inline styles.
- `npm run check` (ESLint + Prettier 100-col + Stylelint + Jest) is the gate.
- Frontend unit tests run in the Docker frontend container:
  `docker compose exec -T frontend npm test`.
- jsdom has **no** `ResizeObserver` and does **no** layout: tests install a mock
  `ResizeObserver` and drive its callback by hand; they assert on the inline
  `style.opacity` string each pass writes (`""` = "pass never touched this
  block", the #462 sentinel). The real observer firing on a real rotation is
  proven in a Playwright smoke, outside the Jest/mutation gate.
- Keep a native Swift iOS client viable (no browser-only server coupling — N/A
  here, this is frontend-only).

## Out of scope

- Mechanism B (scroll-position restore / `ListScrollReset` / `ListScrollMemory`)
  — untouched. Its `scrollTop` math does not read block offsets and is
  wrapper-safe regardless.
- The dim's fade math (`reading-focus.ts`) — unchanged.
- Introducing a single all-rows wrapper — rejected: `.rows` is the scroller and
  its direct children are iterated by the pass and styled by
  `.rows.magazine > *`; one wrapper would break both.
