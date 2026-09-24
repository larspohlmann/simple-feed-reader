# Reading-focus step past the plateau Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make the article's centre paragraph stand out by stepping opacity down at once past a wider plateau, then running the curve from that lower value to the floor.

**Architecture:** `FocusCurve` in `frontend/src/app/reader/reading-focus.ts` gains a `falloff` step (opacity lost at the plateau edge); the existing exponent, currently named `falloff`, is renamed `curvature`. `focusOpacityForSpan` starts its curve at `1 - falloff` instead of 1. Only the constants change behaviour: the article curve gets the step, the list curve keeps `falloff: 0`.

**Tech Stack:** Angular 20, TypeScript, Jest (run in the Docker frontend container).

**Spec:** GitHub issue #1138 (design approved in chat, 2026-09-24).

## Global Constraints

- Article curve: `{ plateau: 0.05, falloff: 0.35, curvature: 1, min: 0.28 }`.
- List curve: `{ plateau: 0, falloff: 0, curvature: 1, min: 0.2 }` — its output must not change.
- **No test may pin the values of `ARTICLE_FOCUS_CURVE`.** The curve math is tested with inline curves so the constant can be tuned without touching a spec.
- `falloff: 0` must reproduce the pre-change curve exactly.
- Frontend tests run inside Docker: `docker compose exec -T frontend npm test`. Never run two Jest runs at once in the container.
- Commit format: `type(#1138): summary`. Prettier width 100.

---

### Task 1: Step the focus curve down past the plateau

**Files:**
- Modify: `frontend/src/app/reader/reading-focus.ts` (the `FocusCurve` interface, both curve constants, `focusOpacityForSpan`)
- Test: `frontend/src/app/reader/reading-focus.spec.ts` (replace the `describe('the article focus curve', …)` block, drop the `ARTICLE_FOCUS_CURVE` import)

**Interfaces:**
- Produces: `FocusCurve { plateau: number; falloff: number; curvature: number; min: number }`; `focusOpacityForSpan(blockTop, blockBottom, viewportHeight, curve?)` unchanged in signature.

- [ ] **Step 1: Write the failing tests**

In `reading-focus.spec.ts`, change the import to:

```ts
import {
  type FocusCurve,
  LIST_FOCUS_CURVE,
  focusOpacityForSpan,
  needsReadingTail,
  readingBlocks,
} from './reading-focus';
```

Replace the whole `describe('the article focus curve', …)` block with:

```ts
describe('a curve with a plateau and a step', () => {
  // Viewport 1000 => centre at 500, a plateau reaching 100px either side of it, a
  // step down to 0.6 at its edge, and the remaining 400px fading on to 0.2.
  const stepped: FocusCurve = { plateau: 0.1, falloff: 0.4, curvature: 1, min: 0.2 };

  it('holds full opacity across the plateau, to its very edge', () => {
    expect(focusOpacityForSpan(500, 500, 1000, stepped)).toBe(1);
    expect(focusOpacityForSpan(600, 600, 1000, stepped)).toBe(1);
    expect(focusOpacityForSpan(400, 400, 1000, stepped)).toBe(1);
  });

  it('drops by the falloff the moment a block leaves the plateau', () => {
    expect(focusOpacityForSpan(601, 601, 1000, stepped)).toBe(0.599);
    expect(focusOpacityForSpan(399, 399, 1000, stepped)).toBe(0.599);
  });

  it('runs the curve from the stepped value down to the floor', () => {
    expect(focusOpacityForSpan(800, 800, 1000, stepped)).toBe(0.4); // halfway: 0.6 - 0.5 * 0.4
  });

  it('bends the curve after the step by its curvature', () => {
    const early = { ...stepped, curvature: 0.5 };
    const late = { ...stepped, curvature: 2 };
    expect(focusOpacityForSpan(700, 700, 1000, early)).toBe(0.4); // 0.6 - sqrt(0.25) * 0.4
    expect(focusOpacityForSpan(800, 800, 1000, late)).toBe(0.5); // 0.6 - 0.5^2 * 0.4
  });

  it('reaches its floor a half-viewport away, and never goes below it', () => {
    expect(focusOpacityForSpan(1000, 1000, 1000, stepped)).toBe(0.2);
    expect(focusOpacityForSpan(9000, 9000, 1000, stepped)).toBe(0.2);
  });

  it('fades straight from full opacity when the falloff is zero', () => {
    const smooth = { ...stepped, falloff: 0 };
    expect(focusOpacityForSpan(601, 601, 1000, smooth)).toBe(0.998);
    expect(focusOpacityForSpan(800, 800, 1000, smooth)).toBe(0.6); // 1 - 0.5 * 0.8
  });

  it('leaves everything opaque when a plateau swallows the half-viewport', () => {
    expect(focusOpacityForSpan(0, 0, 1000, { ...stepped, plateau: 0.5 })).toBe(1);
    expect(focusOpacityForSpan(0, 0, 1000, { ...stepped, plateau: 0.9 })).toBe(1);
  });
});
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `docker compose exec -T frontend npx jest src/app/reader/reading-focus.spec.ts`
Expected: FAIL — TypeScript rejects `curvature` as an unknown `FocusCurve` property (or, without the typecheck, the step tests return ~0.999 instead of 0.599).

- [ ] **Step 3: Implement**

In `reading-focus.ts`, replace the interface and the two constants:

```ts
/** How steeply a surface's reading focus falls away from the reading centre. */
export interface FocusCurve {
  /** Fraction of the viewport height, each side of the centre, held fully opaque. */
  plateau: number;
  /** Opacity lost at once as a block leaves the plateau; 0 fades straight from 1. */
  falloff: number;
  /** Exponent on the fade after the step: 1 is linear, below 1 drops early. */
  curvature: number;
  /** Opacity of a block sitting a half-viewport or more from the centre. */
  min: number;
}

/** The entry list's curve: a fade off the centre line, down to a strong dim. */
export const LIST_FOCUS_CURVE: FocusCurve = { plateau: 0, falloff: 0, curvature: 1, min: 0.2 };

/**
 * The article's curve: a band around the centre at full opacity, then a step down
 * that sets the paragraph in focus apart from its neighbours (#1138). Tuned by
 * eye — no spec pins these values.
 */
export const ARTICLE_FOCUS_CURVE: FocusCurve = {
  plateau: 0.05,
  falloff: 0.35,
  curvature: 1,
  min: 0.28,
};
```

In the `focusOpacityForSpan` docblock, replace the last sentence
(`` `curve.plateau` widens … `curve.falloff` bends it. ``) with:

```
 * `curve.plateau` widens the opaque middle; past it the opacity steps down by
 * `curve.falloff` and fades on to `curve.min`, bent by `curve.curvature`.
```

Replace the function's last line with:

```ts
  const edge = 1 - curve.falloff;
  return +(edge - ratio ** curve.curvature * (edge - curve.min)).toFixed(3);
```

- [ ] **Step 4: Run the reader focus tests to verify they pass**

Run: `docker compose exec -T frontend npx jest src/app/reader/reading-focus`
Expected: PASS for `reading-focus.spec.ts` and `reading-focus-applier.spec.ts` (the list tests, including the `0.84` edge case, are unchanged).

- [ ] **Step 5: Run the frontend gate**

Run: `docker compose exec -T frontend npm run check`
Expected: ESLint, Prettier, Stylelint and Jest all green. Run `npx prettier --write` on the two files first if Prettier reflows them.

- [ ] **Step 6: Commit**

```bash
git add frontend/src/app/reader/reading-focus.ts frontend/src/app/reader/reading-focus.spec.ts
git commit -m "fix(#1138): step the article reading focus down past the plateau"
```

### Task 2: Check it on the real render

- [ ] **Step 1:** Confirm the `:4200` dev container serves the new chunk (see the stale-chunk memory), open an article in the built-in browser at a mobile viewport with reading focus on, scroll, and read the inline `opacity` of the paragraphs around the centre with `javascript_tool`. Expected: the centre paragraph at `1`, its neighbours at ~`0.6`–`0.65`, far paragraphs down toward `0.28`.
- [ ] **Step 2:** Push the branch and open a PR into `develop` with `Closes #1138`. Stop; merging is Lars's call.
