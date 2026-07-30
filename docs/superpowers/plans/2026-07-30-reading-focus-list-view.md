# Reading-Focus Effect for List View — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Replicate the article view's scroll-based reading-focus opacity gradient in the entry-list component, but only on the mobile (narrow) layout. On wide screens the list sits in a split pane next to the article, so the effect is not needed.

**Architecture:** The list view scrolls `.rows` which contains either `<app-entry-row>` elements (list layout) or magazine block components (magazine layout). We hook into the existing `onRowsScroll()` handler to schedule focus recomputation via `requestAnimationFrame`. Each direct child of `.rows` is treated as a reading block — no DOM descent needed because list entries are already at the correct granularity. The pure math from `reading-focus.ts` (`focusOpacity`) is reused unchanged. The effect is gated on `!screen.isWide()` — on wide screens, `applyFocus()` clears any residual inline opacities. An Angular effect watches `isWide()` to trigger a recompute when the breakpoint changes.

**Tech Stack:** Angular 20 standalone component, signals, `reading-focus.ts` pure math module.

---

## Files to modify

| File | Change |
|------|--------|
| `frontend/src/app/reader/entry-list/entry-list.component.ts` | Add `scheduleFocus()`, `applyFocus()`, wire into scroll + resize + `isWide()` signal |
| `frontend/src/app/reader/entry-list/entry-list.component.scss` | Add opacity transition rule for `.rows > *` (narrow only) and reduced-motion override |

No new files created. No changes to `reading-focus.ts` — it is imported as-is.

---

### Task 1: Add reading-focus to the entry-list component

**Files:**
- Modify: `frontend/src/app/reader/entry-list/entry-list.component.ts:1-15` (imports)
- Modify: `frontend/src/app/reader/entry-list/entry-list.component.ts:128-129` (reduceMotion already exists)
- Modify: `frontend/src/app/reader/entry-list/entry-list.component.ts:236-249` (onRowsScroll)
- Modify: `frontend/src/app/reader/entry-list/entry-list.component.ts:491-499` (ngOnDestroy)

- [ ] **Step 1: Add imports for focusOpacity and DestroyRef**

Replace the Angular core import block with:

```typescript
import {
  Component,
  DestroyRef,
  ElementRef,
  OnDestroy,
  computed,
  effect,
  inject,
  input,
  output,
  signal,
  viewChild,
} from '@angular/core';
```

Add after the existing `../reading-layout.service` import:

```typescript
import { focusOpacity } from '../reading-focus';
```

- [ ] **Step 2: Add focusRaf field**

After the existing `lastScrollTop` field (line 194), add:

```typescript
private focusRaf = 0;
```

- [ ] **Step 3: Inject DestroyRef**

Add after the existing `catalog` inject (line 174):

```typescript
private readonly destroyRef = inject(DestroyRef);
```

- [ ] **Step 4: Add scheduleFocus and applyFocus methods**

Add after the `onRowsScroll` method (after line 249):

```typescript
/** Coalesce reading-focus recomputes to one per animation frame. */
private scheduleFocus(): void {
  if (this.reduceMotion || this.focusRaf) return;
  this.focusRaf = requestAnimationFrame(() => {
    this.focusRaf = 0;
    this.applyFocus();
  });
}

/** Dim each list entry by its distance from the scroll viewport's centre.
 *  Only active on the narrow (mobile) layout — on wide screens any residual
 *  inline opacities are cleared. */
private applyFocus(): void {
  const rows = this.rows()?.nativeElement;
  if (!rows) return;
  if (this.screen.isWide()) {
    for (const child of Array.from(rows.children) as HTMLElement[]) {
      child.style.opacity = '';
    }
    return;
  }
  const viewport = rows.clientHeight;
  const rowsTop = rows.getBoundingClientRect().top;
  for (const child of Array.from(rows.children) as HTMLElement[]) {
    if (child.classList.contains('foot')) continue;
    const rect = child.getBoundingClientRect();
    const center = rect.top - rowsTop + rect.height / 2;
    child.style.opacity = String(focusOpacity(center, viewport));
  }
}
```

- [ ] **Step 5: Wire scheduleFocus into onRowsScroll**

Inside `onRowsScroll()`, add `this.scheduleFocus();` after the `this.scrolled.emit(top);` line (after line 245):

```typescript
this.scheduleFocus();
```

- [ ] **Step 6: Add effect to recompute on breakpoint change**

Add an effect in the constructor that watches `isWide()` and re-runs the focus when it changes (e.g. device rotation, browser resize past the breakpoint):

```typescript
effect(() => {
  this.screen.isWide();
  this.scheduleFocus();
});
```

- [ ] **Step 7: Wire scheduleFocus into a resize listener**

In the constructor, after the existing `host.addEventListener` calls (after line 188), add a resize listener:

```typescript
const onResize = () => this.scheduleFocus();
window.addEventListener('resize', onResize, { passive: true });
this.destroyRef.onDestroy(() => {
  window.removeEventListener('resize', onResize);
});
```

- [ ] **Step 8: Clean up focusRaf in ngOnDestroy**

In `ngOnDestroy()`, add before the existing cleanup (before line 491):

```typescript
if (this.focusRaf && typeof cancelAnimationFrame !== 'undefined') {
  cancelAnimationFrame(this.focusRaf);
}
```

- [ ] **Step 9: Run lint**

Run from `frontend/`:

```bash
npm run check
```

Expected: PASS

- [ ] **Step 10: Commit**

```bash
git add frontend/src/app/reader/entry-list/entry-list.component.ts
git commit -m "feat(list): add reading-focus opacity gradient to entry list (mobile only)

Reuses focusOpacity from reading-focus.ts. Each direct child of .rows
is dimmed based on distance from the viewport centre on narrow layouts.
Wide screens: opacities are cleared. Respects prefers-reduced-motion."
```

---

### Task 2: Add CSS transition for smooth opacity animation

**Files:**
- Modify: `frontend/src/app/reader/entry-list/entry-list.component.scss:244-258` (.rows rule)
- Modify: `frontend/src/app/reader/entry-list/entry-list.component.scss:337-354` (prefers-reduced-motion)

- [ ] **Step 1: Add opacity transition to .rows children (narrow only)**

After the `.rows` block (after line 258), add:

```scss
@media (width < bp.$bp-lg) {
  .rows > * {
    transition: opacity 0.2s ease-out;
  }
}
```

Uses the existing `bp` breakpoint import already at the top of the file.

- [ ] **Step 2: Disable the transition under prefers-reduced-motion**

Inside the existing `@media (prefers-reduced-motion: reduce)` block (lines 337-354), add:

```scss
.rows > * {
  transition: none;
}
```

- [ ] **Step 3: Run lint**

Run from `frontend/`:

```bash
npm run check
```

Expected: PASS

- [ ] **Step 4: Commit**

```bash
git add frontend/src/app/reader/entry-list/entry-list.component.scss
git commit -m "style(list): add opacity transition for reading-focus effect (mobile only)

200ms ease-out on .rows children at narrow widths, disabled under
prefers-reduced-motion."
```

---

### Task 3: Verify and run tests

- [ ] **Step 1: Run frontend unit tests**

Run from `frontend/`:

```bash
npm test
```

Expected: All tests pass.

- [ ] **Step 2: Run full lint suite**

Run from `frontend/`:

```bash
npm run check
```

Expected: PASS (ESLint + Prettier + Stylelint + Jest)

- [ ] **Step 3: Final commit if any fixups were needed**

```bash
git add -u
git commit -m "fix(list): address lint/test findings for reading-focus"
```

Only if changes were required.
