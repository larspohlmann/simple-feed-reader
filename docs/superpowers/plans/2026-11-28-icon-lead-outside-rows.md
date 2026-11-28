# Icon-lead outside rows and strut guard Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Every icon beside text in the reader goes through `theme/_icon-lead.scss`, and an override of the cap strut fails a test.

**Architecture:** The five rows the #2563 audit found move onto `icon-lead.first-line` / `icon-lead.box`. `app-tag-glyph` publishes its box through `self-sized`. The strut gets `!important` in the module. A new e2e spec reads computed styles on every live slot across surfaces, on a fine and a coarse pointer.

**Tech Stack:** Angular 20, SCSS (Dart Sass), Playwright, Jest.

**Spec:** `docs/superpowers/specs/2026-11-28-icon-lead-outside-rows-design.md`

## Global Constraints

- Contract: design-language.md §"Icons beside text" (`docs/design-language.md:150-198`). The row carries the first line's font; a slot's host spaces its children with margins, not `gap`; a resized slot host sets `--icon-lead-self`, not `width`/`height`.
- `TOLERANCE` stays 0.5px (`e2e/support/first-line.ts:4`); no case loosens it.
- No visible change beyond sub-pixel alignment and formula gaps: same tokens and colours, no row-height change on a fine pointer, tap targets unchanged on a coarse one.
- No hex colours, no ad-hoc `px` spacing, no media-query literals outside `src/app/theme/` (Stylelint).
- Comments: default none; one line at most (CLAUDE.md "Default to no comment").
- Commits: `type(#2563): lower-case summary`, no attribution lines.
- **Gate cadence (CLAUDE.md "Testing"):** per task, run only the e2e cases and Jest specs covering the touched code, plus Stylelint/Prettier on changed files. No `npm run check`, no full e2e. Foreground commands only; no background shells, no `until`/`sleep` loops.
- **Running e2e from this worktree.** The repo config's global setup refuses a worktree, so use the scratch config. It serves the worktree on :4201 and every icon-lead read is stubbed:
  `cd frontend && ./node_modules/.bin/playwright test --config /private/tmp/claude-501/-Users-lars--claude-spares-spare-n0GYVI/7e3c4674-3251-4b97-a6b4-ebcbb78df433/scratchpad/playwright.worktree.config.ts e2e/<spec> -g "<name>" --reporter=line`
- **Jest from this worktree.** The Docker `frontend` container mounts the MAIN checkout, so it cannot test worktree code. Run natively from `frontend/`: `./node_modules/.bin/jest <path> --testPathIgnorePatterns /node_modules/ /e2e/`.
- **Stylelint/Prettier:** `./node_modules/.bin/stylelint <files>` and `./node_modules/.bin/prettier --check <files>` from `frontend/`.

## Review Focus

1. **A row at 150% text whose count wraps or whose avatar is taller than the line** (comment byline, 20px avatar beside `--fs-sm` text). The avatar must not push the text down and must keep its box: add a 150% byline case (Task 5).
2. **Coarse pointer on `entry-like`.** The `@media (pointer: coarse)` block grows the button's padding and margins. The strut must survive it: the scanner's coarse pass covers the list surface (Task 1 + Task 3).
3. **The vote pill's `block-size: auto` exception** must still win after the strut gets `!important`. The existing vote-pill case must stay green (Task 1).
4. **`alone()` slots** (icon-only buttons) must still drop the strut (`content: none`) once `content` is `!important`. The scanner asserts no strut on an `alone` slot (Task 1).
5. **`app-tag-glyph` outside any slot** (sidebar, organise, admin) must keep its square size at every `size`. Add a Jest assertion per size (Task 2).

---

### Task 1: Strut guard — `!important` strut and a slot-scanner spec

**Files:**
- Modify: `frontend/src/app/theme/_icon-lead.scss` (`-first-line-slots`, `alone`)
- Create: `frontend/e2e/icon-lead-slots.spec.ts`
- Modify: `frontend/e2e/support/first-line.ts` (export the scanner if it is shared; otherwise keep it in the spec)
- Modify: `docs/design-language.md` (the "Icons beside text" bullets: the strut is `!important`; name the new spec beside `icon-lead-first-line.spec.ts`)

**Interfaces:**
- Produces: `findLeadSlots(page: Page): Promise<SlotReport[]>` with `interface SlotReport { selector: string; alone: boolean; strutBlockSize: number; capHeight: number; display: string; alignSelf: string; hasText: boolean }`. Later tasks add surfaces to its `SURFACES` table.

Slot discovery: walk `document.styleSheets` recursively (into `CSSMediaRule`, `CSSContainerRule`, `CSSSupportsRule`, `CSSLayerBlockRule`). Collect every style rule whose selector ends in `::before` and whose `block-size` is `var(--icon-lead-cap)`. Strip `::before` to get the slot selector. Then report every live element matching it, reading `getComputedStyle(element, '::before')`. A slot is `alone` when its `::before` computed `content` is `none`.

- [ ] **Step 1: Write the spec.**
  - `SURFACES`: reuse the stubbed surfaces of `icon-lead-first-line.spec.ts`. Move `Surface`/`openSurface` into `e2e/support/first-line.ts` if needed, as a pure move with the old spec unchanged. Include at least the article (`SCORED_ARTICLE`), the list/magazine, organise, the account menu and the tag picker.
  - Each surface runs at `DESKTOP` (fine pointer) and at `PHONE` with `touch: true` (coarse pointer).
  - `test('every icon-lead slot on <surface>, <pointer>, keeps its cap strut')`:
    - `expect(slots.length).toBeGreaterThan(0)`
    - for every non-alone slot: `expectNear(slot.strutBlockSize, slot.capHeight, ...)`, `expect(slot.display).toBe('flex')`, and `expect(slot.alignSelf).toBe('baseline')` when `hasText`
    - for every alone slot: `expect(slot.alignSelf).toBe('center')`
  - Read `capHeight` as the slot's resolved `--icon-lead-cap` (`measureToken` style).
- [ ] **Step 2: Prove it catches an override.** Temporarily add `.pill > .arrow::before { block-size: 0; }` inside `@media (pointer: coarse)` in `vote-pill.component.scss`. Run the spec. Expected: the coarse article case FAILS naming `.arrow`. Quote the FAIL line, then remove the override.
- [ ] **Step 3: Add `!important`** to the strut's `content` and `block-size` in `-first-line-slots`, and to `content: none` in `alone()`. Rerun Step 2's override: the spec now PASSES, because the override loses. Remove the override again.
- [ ] **Step 4: Run** the new spec, plus `-g "vote pill"` and `-g "paints its glyph"` in `icon-lead-first-line.spec.ts`. Expected: all PASS.
- [ ] **Step 5: Commit** `test(#2563): scan every icon-lead slot for its cap strut` and `fix(#2563): make the icon-lead strut important`, as two commits.

### Task 2: `app-tag-glyph` publishes its box

**Files:**
- Modify: `frontend/src/app/shared/tag-glyph/tag-glyph.component.ts` (host binding)
- Modify: `frontend/src/app/shared/tag-glyph/tag-glyph.component.scss`
- Test: `frontend/src/app/shared/tag-glyph/tag-glyph.component.spec.ts`

**Interfaces:**
- Produces: the host sets `--tag-glyph-size` (from `ICON_SIZE_TOKEN[size()]`). Its SCSS does `@include icon-lead.self-sized(var(--tag-glyph-size))`. No `[style.width]` / `[style.height]` binding remains.

- [ ] **Step 1: Failing Jest tests.**
  - Per size `xs|sm|md`: the host's inline style has `--tag-glyph-size: var(--icon-<size>)`, and `host.style.width` is `''`.
  - The existing square-footprint tests keep passing.
- [ ] **Step 2: Implement.** Grep every SCSS that sets `width`/`height` on `app-tag-glyph`; any such consumer switches to setting `--tag-glyph-size`.
- [ ] **Step 3: Run** the Jest spec, plus e2e `-g "tag"` in `icon-lead-first-line.spec.ts` and Task 1's scanner. Expected: PASS.
- [ ] **Step 4: Commit** `fix(#2563): size the tag glyph through icon-lead self-sized`.

### Task 3: Counts beside a glyph — `.comments`, `entry-like`, social `.count`

**Files:**
- Modify: `frontend/src/app/reader/list/entry-meta/entry-meta.component.scss` (`.comments`)
- Modify: `frontend/src/app/reader/list/entry-meta/entry-meta.component.html` (wrap the count text in `<span class="count">` so it can be the `$text`)
- Modify: `frontend/src/app/reader/entry/entry-like/entry-like.component.scss` (`button`, `.likes`)
- Modify: `frontend/src/app/reader/article/social-counts/social-counts-row.component.scss` (`.count`)
- Test: `frontend/e2e/icon-lead-first-line.spec.ts` (new `PILL_CASES`-style cases; side-vs-gap does not apply to these unpadded rows, so add a `COUNT_CASES` table asserting `expectOnCapMiddle` only)

**Interfaces:**
- Consumes: `icon-lead.first-line($slot, $text)`.
- Rows: `.comments` → `first-line('> app-icon', '> .count')`; `button` and `.likes` in entry-like → `first-line('> app-icon, > app-pending-dots', '> .count')`; social `.count` → `first-line('> app-icon', '> .number')`. Each row keeps `gap: var(--space-1)` (the icon-to-count distance is a token here, not a lead space: these rows have no inset). Keep the `transform: scale(0.9)` on entry-like's `app-icon`.

- [ ] **Step 1: Failing e2e cases** in `COUNT_CASES`, each run over `SETUPS`:
  - `the list comment bubble`: `entry-meta .comments`, icon `app-icon`, text `.count`. Use the stubbed list surface with `comments` set.
  - `the list like count`: `app-entry-like .likes`.
  - `the article social count`: `app-social-counts-row .count`.
  - `the comment bubble and the vote arrows share a line`: `|bubbleCentre − arrowCentre| ≤ TOLERANCE` in `entry-meta .discussion`.
  Assertion: `expectOnCapMiddle(await measureFirstLine({ icon, firstLine: text }))`. Run them and quote the classic-100% FAIL (expected about +0.88).
- [ ] **Step 2: Implement** the three rows.
- [ ] **Step 3: Run** the new cases, the scanner's list and article surfaces, and the Jest specs of `entry-meta` and `entry-like`. Expected: PASS.
- [ ] **Step 4: Commit** `fix(#2563): put count rows on icon-lead first-line`.

### Task 4: Tag-picker SUGGESTED chips on `box`

**Files:**
- Modify: `frontend/src/app/reader/feeds/tag-picker/tag-picker.component.scss` (`.suggestion`, its coarse block)
- Test: `frontend/e2e/icon-lead-first-line.spec.ts` (`PILL_CASES`)

**Interfaces:**
- Consumes: `icon-lead.box($inset, $slot, $text)`. `.suggestion` → `box(var(--space-1), '> app-tag-glyph', '> .label')`, replacing `padding` and `gap`. On a coarse pointer, `min-height: var(--tap-target)` stays and `centred-line` keeps the one line centred.

- [ ] **Step 1: Failing cases** `a SUGGESTED tag chip` at `DESKTOP`, and a phone/touch variant, both in `PILL_CASES` (cap middle + side equals gap). Get suggestions by stubbing the tag search the picker calls. Find its route in `tag-picker` (`search.suggestions()`). Quote the FAIL (side 12 against gap 8).
- [ ] **Step 2: Implement.**
- [ ] **Step 3: Run** the two cases, the tag-picker Jest spec, and the scanner's tag-picker surface. Expected: PASS.
- [ ] **Step 4: Commit** `fix(#2563): lead tag-picker suggestions with icon-lead box`.

### Task 5: Comment byline avatar

**Files:**
- Modify: `frontend/src/app/reader/article/entry-comments/entry-comments.component.scss` (`.byline`)
- Test: `frontend/e2e/icon-lead-first-line.spec.ts`

**Interfaces:**
- Consumes:
  - `icon-lead.first-line('> app-avatar', '> .author')`
  - `icon-lead.lead-gap($gap)`, with `$gap` = the byline's existing `var(--space-1)`
  - `icon-lead.self-sized(var(--icon-md))` on `> app-avatar`, so the 20px avatar keeps its box without growing the line
- The avatar's end margin is the lead space. Declare it with `lead-space(0)` on `.byline`. Use `.author` whether it renders as `a` or `span`.

- [ ] **Step 1: Failing cases** over `SETUPS` on `SCORED_ARTICLE`'s comments:
  - `a comment byline avatar`: `expectOnCapMiddle`.
  - The avatar-to-author gap equals the resolved `--icon-lead-space`, within `TOLERANCE`. Quote the 150% FAIL (4px against about 7px).
- [ ] **Step 2: Implement.** Check that `.handle`, `.when` and the other items keep `--space-1` apart.
- [ ] **Step 3: Run** the cases, `entry-comments` Jest, and the scanner's article surface. Expected: PASS.
- [ ] **Step 4: Commit** `fix(#2563): hang the comment avatar on the byline's cap middle`.

### Task 6: `chat_bubble` → `mode_comment` trial, and docs

**Files:**
- Modify: `frontend/src/app/reader/list/entry-meta/entry-meta.component.html` (only if the trial wins), plus any other `name="chat_bubble"` that leads a count (grep)
- Modify: `docs/design-language.md` (rows now on the module; remove any of the five rows from "Not part of it")

- [ ] **Step 1: Measure.** With a throwaway Playwright script (not committed), render both glyphs at 16px in graphite and classic. Report the ink centroid against the cap middle, using the audit's method: a pixel scan of a 4× screenshot.
  - `chat_bubble` was −0.85.
  - Adopt `mode_comment` only if its absolute centroid is below 0.85 and its tail stays within the line.
  - Report `opsz` 24 against 20 for `chat_bubble`, `thumb_up` and `home` as numbers only. **Do not change `opsz`**: it is global, and the call is Lars's.
- [ ] **Step 2: Apply** the swap if it won. Take before/after screenshots of the list discussion strip at DESKTOP and PHONE in classic, then Read them.
- [ ] **Step 3: Docs.**
- [ ] **Step 4: Commit** `fix(#2563): lead comment counts with mode_comment` (if applied) and `docs(#2563): icon-lead covers count rows and bylines`.
