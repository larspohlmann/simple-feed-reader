# Trending Story Context Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** A Trending story reaches its widgets through one story context provided around a card, so the shared magazine blocks and the list row take only `[entry]`.

**Architecture:**
- The story footer sits at fixed places inside each block's own template (the kicker line, `app-more-sources`), so a wrapping component cannot reach it. A directive `[appStory]` on the card's host provides a `STORY_CONTEXT` instead. `MagazineBlockComponent` puts it on an `ng-container` around its `@switch`, and the list layout puts it on `app-entry-row`.
- Only the leaf widgets inject it: the kicker line, more-sources, entry-duplicates, entry-link and entry-row. Each reads it through `injectStory(): Signal<TrendingStoryDto | null>`.
- The story branch of `more-sources` moves into its own `app-story-sources`. `more-sources` keeps one dispatch: story sources when a story is in context, the duplicates footer otherwise.

**Tech Stack:** Angular 20 (standalone components, signals, DI), Jest in Docker, Playwright.

**Spec:** GitHub issue #2337 (`gh issue view 2337`). It is the follow-up from the #2328 reviews (PR #2334).

## Global Constraints

- Frontend only. Behaviour and look stay identical. Before and after screenshots of Trending (magazine and list, day and week, desktop 1440×900 at 4/3 and phone 390×760 at 2x) with the same stubbed data must be byte-identical, or show only relative-time drift.
- Component styles live in sibling `.scss` files. No hex colours or ad-hoc px values outside `src/app/theme/`, `src/styles/` and `src/styles.scss`.
- All Jest runs go through `docker compose exec -T frontend npx jest <paths>`, and the full gate is `docker compose exec -T frontend npm run check`. Never two Jest runs at once.
- Commits are `refactor(#2337): …`, with no attribution. Branch `refactor/2337-trending-story-card`, one PR with `Closes #2337`.
- Out of scope (YAGNI): a `{ lead, story }` row shape for the list layer. The list template keeps `leadOf`/`storyOf`.

## Review Focus

1. **Nested rows.** A copy's row opened from a story card's "Also covered by" popover (`entry-duplicates` renders `<app-entry-row [entry]="copy">` in a CDK overlay) must not show story pieces: no source count, no sharers, no story actions, no story unread rule. The overlay's template inherits the card's injector, so the popover must reset the context to `null`. Pinned in Task 2.
2. **Lists outside Trending** provide no context, so every widget renders exactly as an ordinary entry. Pinned in Task 2.
3. **A patched story** (an optimistic read on one article makes a new story object) must update the card's unread mark and counts, so widgets must read the context as a signal and not snapshot it at construction. Pinned in Task 1.
4. **The link block's "led by its card"** must follow the story in context, and must be false without one. Pinned in Task 2.
5. **A block kind added later** needs no story wiring. This is pinned by the type system: no block has a `story` input after Task 2.

---

### Task 1: The story context

**Files:**
- Create: `frontend/src/app/reader/trending/story-context.ts`
- Test: `frontend/src/app/reader/trending/story-context.spec.ts`

**Interfaces:**
- Produces:
  - `STORY_CONTEXT: InjectionToken<Signal<TrendingStoryDto | null>>`
  - `@Directive({ selector: '[appStory]', providers: [{ provide: STORY_CONTEXT, useFactory: …the directive's story signal… }] }) export class StoryContextDirective { readonly story = input<TrendingStoryDto | null>(null, { alias: 'appStory' }); }`
  - `export function injectStory(): Signal<TrendingStoryDto | null>` returns `inject(STORY_CONTEXT, { optional: true }) ?? signal(null).asReadonly()`. It must be called in an injection context.

- [ ] **Step 1: Write the failing specs** in `story-context.spec.ts`, using a tiny host test component whose template is `<ng-container [appStory]="story()"><app-probe /></ng-container>`. `app-probe` reads `injectStory()`.
  - `gives a child the story its host provides`: set the host's `story` to `aStory({ id: 4 })`; the probe's signal returns a story with id 4.
  - `follows the host when the story object changes`: replace it with `aStory({ id: 4, viewed: true })`, run `detectChanges`; the probe sees `viewed === true` (Review Focus 3).
  - `gives null without a host`: a probe rendered alone returns `null`.
  - `lets an inner host reset the story`: a nested `<ng-container [appStory]="null">` around a second probe gives `null` while the outer probe still sees the story (Review Focus 1's mechanism).
- [ ] **Step 2: Run** `docker compose exec -T frontend npx jest src/app/reader/trending/story-context.spec.ts`. Expected: FAIL (module not found).
- [ ] **Step 3: Implement** `story-context.ts` with the interfaces above. The provider hands out the directive's `story` input signal itself (`useFactory: () => inject(StoryContextDirective).story`), so the value stays live.
- [ ] **Step 4: Run the spec again.** Expected: PASS.
- [ ] **Step 5: Commit** — `refactor(#2337): a story context reaches a card's widgets`

### Task 2: Widgets read the context; blocks and the row take only the entry

**Files:**
- Modify:
  - `frontend/src/app/reader/list/magazine/entry-block-base.ts` (delete `story`)
  - `frontend/src/app/reader/list/magazine/blocks/*/*.component.html` (11 templates: drop `[story]="story()"` from `app-entry-kicker-line` and `app-more-sources`)
  - `frontend/src/app/reader/list/magazine/blocks/entry-link/entry-link.component.ts`
  - `frontend/src/app/reader/list/magazine/magazine-block.component.{ts,html}`
  - `frontend/src/app/reader/list/magazine/entry-kicker-line.component.ts`
  - `frontend/src/app/reader/list/magazine/more-sources.component.ts`
  - `frontend/src/app/reader/list/magazine/entry-duplicates.component.{ts,html}`
  - `frontend/src/app/reader/list/entry-row/entry-row.component.{ts,html}`
  - `frontend/src/app/reader/list/entry-list/entry-list.component.{ts,html}`
- Test: the specs of each modified component, plus `list/entry-list/entry-list.component.spec.ts`

**Interfaces:**
- Consumes: Task 1's `StoryContextDirective` and `injectStory()`.
- Produces:
  - No `story` input remains on `EntryBlockBase`, `EntryRowComponent`, `EntryKickerLineComponent`, `MoreSourcesComponent` or `EntryDuplicatesComponent`. Each of them that needs the story holds `protected readonly story = injectStory()`.
  - `magazine-block.component.html` wraps its `@switch` in `<ng-container [appStory]="card.story">` and passes only `[entry]` and `[tags]` to each block.
  - `entry-list.component.html` renders `<app-entry-row [appStory]="storyOf(item)" [entry]="e" …>`.
  - `entry-duplicates.component.html` wraps the popover's `<app-entry-row [entry]="copy">` in `<ng-container [appStory]="null">`.

- [ ] **Step 1: Move the specs to the context first.** Every spec that sets a `story` input on these components now renders the component inside a host with `[appStory]`. Add a shared helper `frontend/src/testing/story-host.ts` if three or more specs need the same host. Keep every assertion unchanged. Add these new specs:
  - `entry-duplicates.component.spec.ts` › `shows a copy's row in the popover without the story's pieces`: inside a story context, open a copy's popover. The popover's `app-entry-row` renders no `app-story-source-count`, no `app-story-sharers` and no `app-story-actions` (Review Focus 1).
  - `entry-row.component.spec.ts` › `renders no story pieces without a story context` (Review Focus 2).
  - `entry-link.component.spec.ts` › `is led by its card only when the story in context shares one`, as two cases: with a story whose lead has a shared card → true; without a context → false (Review Focus 4).
  - `magazine-block.component.spec.ts` › `hands a story block's story to its widgets`: a `hero` block with `story: aStory()` renders `app-story-source-count` inside the hero.
- [ ] **Step 2: Run** the touched specs. Expected: FAIL (the components still read inputs).
- [ ] **Step 3: Implement** the Interfaces. Import `StoryContextDirective` wherever a template uses `[appStory]`.
- [ ] **Step 4: Run** `docker compose exec -T frontend npm run check`. Expected: green, with no `story` input left (`grep -rn "story = input" frontend/src/app/reader/list` prints nothing).
- [ ] **Step 5: Commit** — `refactor(#2337): blocks and rows take only the entry; widgets read the story context`

### Task 3: The story footer is its own component

**Files:**
- Create: `frontend/src/app/reader/trending/story-sources/story-sources.component.{ts,html,scss}`
- Modify: `frontend/src/app/reader/list/magazine/more-sources.component.{ts,html,scss}`
- Test: `frontend/src/app/reader/trending/story-sources/story-sources.component.spec.ts`, `list/magazine/more-sources.component.spec.ts`

**Interfaces:**
- Consumes: Task 1's `injectStory()`.
- Produces:
  - `app-story-sources`, with `story = input.required<TrendingStoryDto>()`. It moves the whole story branch of `more-sources` unchanged: the sharers, the story line (actions, counts, toggle), the mini cards, broken-thumb tracking, the `face` template and the styles they use.
  - `more-sources` becomes `@if (story(); as story) { <app-story-sources [story]="story" /> } @else if (hasDuplicates()) { <app-entry-duplicates [entry]="entry()" /> }`. The story fields and imports it no longer uses are deleted. Styles move with their markup; selectors keep their names, so the rendered DOM classes are unchanged.

- [ ] **Step 1: Move the story cases** from `more-sources.component.spec.ts` into `story-sources.component.spec.ts`, rendered with `[story]`, assertions unchanged. Keep in `more-sources.component.spec.ts`:
  - `renders the story sources inside a story context`;
  - `renders the duplicates footer without one`.
- [ ] **Step 2: Run** both specs. Expected: FAIL (component missing).
- [ ] **Step 3: Implement** the split.
- [ ] **Step 4: Run** `docker compose exec -T frontend npm run check`. Expected: green.
- [ ] **Step 5: Commit** — `refactor(#2337): the story footer is its own component`

### Task 4: Proof of the same look

**Files:** none committed. The capture script lives in the session scratchpad.

- [ ] **Step 1: Before Task 2 starts, capture the "before" shots** from the tip of Task 1. Write a standalone Playwright capture script in the scratchpad, run from `frontend/` so it resolves `playwright`. It stubs auth and every `/api` call the way `frontend/e2e/trending-smoke.spec.ts` and `frontend/e2e/support/*.ts` do, and stubs `/api/trending?window=day|week` with 6 story-first stories. Those stories cover: with and without images, 2–4 articles, shares on 3 stories, one followed, one viewed, and story ids different from lead ids. It also takes a `--shift-hours` option for relative-time drift.

  Capture these as full-page PNGs: magazine and list × day and week × desktop and phone.
- [ ] **Step 2: After Task 3, capture the "after" shots** with the same script and compare them with `cmp`. Expected: all 8 identical. If one differs only by relative time, re-run with `--shift-hours` matching the gap.
- [ ] **Step 3: Run** `cd frontend && npm run e2e -- trending-smoke.spec.ts`. Expected: 2 passed.

### Final: before the PR
- [ ] Run `/simplify` over the branch (frontend only), then `docker compose exec -T frontend npm run check`, then open the PR with `Closes #2337`.
