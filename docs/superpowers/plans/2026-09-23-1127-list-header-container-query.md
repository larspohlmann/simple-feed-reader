# #1127 List header adapts to its own width — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** In a narrow split column (pane layout, wide-screen direct-search pane) the list header keeps a readable title: its actions switch to the compact icon form by the header's own width, and move to a second row when even that form does not fit.

**Architecture:** `.list-header` becomes a named inline-size container (`list-header`). Every compact rule that belongs to the header moves from `@media (width <= bp.$bp-sm)` to `@container list-header (width <= bp.$container-list-header-compact)`. The threshold equals `$bp-sm`, and on a phone the header spans the viewport, so the phone look does not change. `.heading` gets a flex basis (the title floor) and the header wraps, so the tools drop under the title only when the floor plus the compact tools do not fit. The header's ResizeObserver already republishes `--list-bar-h`, so the scroller reserves a two-row header.

**Tech Stack:** Angular 20 SCSS (component styles + the global `styles/_list-action.scss`), Stylelint, Playwright.

**Spec:** the bounded design approved in chat on 2026-09-23 (no spec file); the issue is https://github.com/larspohlmann/simple-feed-reader/issues/1127.

## Global Constraints

- Frontend styles only. No template, TypeScript or backend change.
- Container thresholds live in `frontend/src/app/theme/_breakpoints.scss` (`docs/design-language.md`: "`@container` queries take their thresholds from `_breakpoints.scss` too"). No literal width inside an `@container` rule.
- Container name: `list-header`. Threshold variable: `$container-list-header-compact`. Title floor: `6rem`.
- The reader-view nav (`reader-view.component.scss`, `.nav .mobile-icon-only`) keeps its viewport `@media` rule. Do not change the reader view.
- The phone look must not change: `frontend/e2e/list-header-actions-mobile.spec.ts` stays green unmodified.
- Comments: default to none; one line, three at most. Rewrite each moved comment that says "phone"/"mobile" so it names the narrow header instead, or delete it.
- Frontend unit tests run inside Docker: `docker compose exec -T frontend npm run check` (never two jest runs at once). Playwright runs natively from `frontend/` against the dev stack on :4200: `npx playwright test <spec>`.
- Before any Playwright measurement, prove :4200 serves the edit: `docker compose logs frontend --since 30s | grep ERROR` is empty, and the rule is in `document.styleSheets` of the running page (styles are not in `main.js`).
- Commit format `type(#1127): …`. Branch `fix/1127-list-header-container-query`; never commit to `develop`.
- Delete the throwaway `frontend/e2e/zz-scratch-1127.spec.ts` before the first commit; never commit it.

---

### Task 1: Compact actions keyed to the header's width

**Files:**
- Create: `frontend/e2e/list-header-narrow-pane.spec.ts`
- Modify: `frontend/src/app/theme/_breakpoints.scss` (append one variable)
- Modify: `frontend/src/app/reader/entry-list/entry-list.component.scss` (`.list-header` block ~line 19; `.mobile-search-title-break` media ~line 151; compact media ~line 290)
- Modify: `frontend/src/app/reader/reader-shell.component.scss` (save-search/for-you media ~line 218; list-edit media ~line 343)
- Modify: `frontend/src/styles/_list-action.scss` (the `mobile-icon-only` media block)

**Interfaces:**
- Produces: container `list-header` on `.list-header`; `bp.$container-list-header-compact`; the spec file with helpers `openSplit(page, list, paneSplit)` and `headerGeometry(page)` that Task 2 extends.

- [ ] **Step 1: Write the failing spec**

`frontend/e2e/list-header-narrow-pane.spec.ts`:

```ts
import { expect, Page, test } from '@playwright/test';
import { stubAuthToken } from './support/auth';
import { savedSearchesJson } from './support/reader';

// The sidebar column plus a split main area: `sfr.paneSplit` sets the list column's share.
const DESKTOP = { width: 1280, height: 800 };

const SUBSCRIPTIONS = {
  subscriptions: [
    {
      id: 1,
      feedId: 1,
      title: 'Design feeds with a rather long title',
      faviconUrl: null,
      imageUrl: null,
      description: null,
      customTitle: null,
      feedUrl: 'https://fixtures.invalid/feed.xml',
      siteUrl: null,
      status: 'active',
      sourceFormat: 'xml',
      createdAt: '2026-08-28T00:00:00+00:00',
      lastFetchedAt: '2026-08-28T00:00:00+00:00',
      position: 0,
      tags: [],
      unreadCount: 1,
    },
  ],
  favoritesCount: 0,
  keptCount: 0,
  viewedCount: 0,
};

interface SplitList {
  name: string;
  url: string;
  layout: string;
}

const ALL_ITEMS: SplitList = { name: 'All items', url: '/', layout: 'pane' };
const FEED: SplitList = { name: 'a feed', url: '/?subscription=1', layout: 'pane' };
const DIRECT_SEARCH: SplitList = { name: 'a direct search', url: '/?q=hamburg', layout: 'magazine' };

async function openSplit(page: Page, list: SplitList, paneSplit: string): Promise<void> {
  await stubAuthToken(page);
  await page.addInitScript(
    ([layout, split]) => {
      localStorage.setItem('sfr.layout', layout);
      localStorage.setItem('sfr.paneSplit', split);
    },
    [list.layout, paneSplit],
  );
  const json = (body: unknown) => (route: { fulfill: (response: { json: unknown }) => unknown }) =>
    route.fulfill({ json: body });

  await page.route('**/api/subscriptions**', json(SUBSCRIPTIONS));
  await page.route('**/api/tags**', json({ tags: [] }));
  await page.route('**/api/entries**', json({ entries: [], nextCursor: null }));
  await page.route('**/api/me**', json({ id: 1, email: '', roles: [], preferences: {} }));
  await page.route('**/api/version**', json({ version: 'dev' }));
  await page.route('**/api/recommendations/**', json({ run: null }));
  await page.route('**/api/saved-searches**', json(savedSearchesJson()));

  await page.goto(list.url);
  await expect(page.locator('.list-header')).toBeVisible();
}

test.describe('list header in a narrow split column (#1127)', () => {
  test.use({ viewport: DESKTOP });

  test('a column at or below the compact threshold drops the action labels', async ({ page }) => {
    await openSplit(page, FEED, '45');

    await expect(page.locator('.list-header .mark-all .txt')).toBeHidden();
    await expect(page.locator('.list-header .refresh app-icon')).toHaveCSS(
      'border-top-width',
      '1px',
    );
  });

  test('a column above the compact threshold keeps the labelled links', async ({ page }) => {
    await openSplit(page, FEED, '60');

    await expect(page.locator('.list-header .mark-all .txt')).toBeVisible();
    await expect(page.locator('.list-header .refresh app-icon')).toHaveCSS(
      'border-top-width',
      '0px',
    );
  });

  test('a direct search keeps its short labels in the compact form', async ({ page }) => {
    await openSplit(page, DIRECT_SEARCH, '45');

    for (const action of await page.locator('.list-header :is(.mark-all, .save-search)').all()) {
      await expect(action.locator('.txt')).toBeHidden();
      await expect(action.locator('.txt-short')).toBeVisible();
    }
  });
});
```

Column widths at 1280 px (sidebar 288 px, main 992 px): split 45 → 446 px (≤ 560, compact); split 60 → 595 px (> 560, labelled).

- [ ] **Step 2: Run it and see it fail**

Run (from `frontend/`): `npx playwright test e2e/list-header-narrow-pane.spec.ts --reporter=line`
Expected: the "drops the action labels" and "keeps its short labels" tests FAIL (labels visible, border `0px`); "keeps the labelled links" PASSES.

- [ ] **Step 3: Add the threshold**

Append to `frontend/src/app/theme/_breakpoints.scss`:

```scss
$container-list-header-compact: $bp-sm; // a phone's header spans the viewport, so phones keep their look
```

- [ ] **Step 4: Make the header a container**

In `entry-list.component.scss`, in the `.list-header` rule, after `border-bottom: 1px solid var(--border);`, add:

```scss
  container: list-header / inline-size;
```

- [ ] **Step 5: Move the entry-list compact rules to the container**

Replace

```scss
@media (width <= bp.$bp-sm) {
  .mobile-search-title-break {
    display: block;
  }
}
```

with

```scss
@container list-header (width <= bp.$container-list-header-compact) {
  .mobile-search-title-break {
    display: block;
  }
}
```

Replace the compact block (the comment "Compact controls: below this width …" and its `@media (width <= bp.$bp-sm) { … }`) with:

```scss
/* Compact controls: in a narrow header the labels drop so a long feed or tag
   title stays readable. The icons, named by aria-label/title, carry the meaning. */
@container list-header (width <= bp.$container-list-header-compact) {
  /* Bordered icon buttons sit tighter than the text links of the wide header. */
  .tools {
    gap: var(--space-1);
  }

  .unread-switch .txt,
  .mark-all .txt,
  .refresh .txt {
    display: none;
  }

  /* Only a direct search keeps a label here. A saved-search result is a
     standing list, so its Mark all read action uses the icon-only form (#971). */
  .list-header.is-search .mark-all:not(.mobile-icon-only) .txt-short {
    display: inline-flex;
  }
}
```

Update the `.txt-short` comment above (~line 218, "Hidden on the desktop button … the mobile media query below swaps the two") to: `/* The short label beside the icon (#581 follow-up); the compact container query below swaps it in. */`

- [ ] **Step 6: Move the shell's projected-action rules**

In `reader-shell.component.scss`, replace the `@media (width <= bp.$bp-sm) { .for-you-run .label, .save-search .txt … }` block with the same body under `@container list-header (width <= bp.$container-list-header-compact) { … }`, and change its comment's "Below this breakpoint" to "In a narrow list header". Replace the `.list-edit .txt` block the same way, with the comment:

```scss
/* In a narrow list header the label drops, as Mark all read/Refresh do, so a
   long title keeps the row; aria-label/title still names the icon. */
@container list-header (width <= bp.$container-list-header-compact) {
  .list-edit .txt {
    display: none;
  }
}
```

The container query resolves on the DOM ancestor, so it reaches these actions although they are projected from the shell's view.

- [ ] **Step 7: Give the global icon-only box both triggers**

Replace the `@media (width <= bp.$bp-sm) { .list-action.mobile-icon-only … }` block in `frontend/src/styles/_list-action.scss` (and its three-line comment above it) with:

```scss
// Icon-only actions get the sidebar chevron treatment: a full tap target around
// a smaller bordered glyph box. Explicit modifier, since some keep a short label (#679).
@mixin icon-only-box {
  .list-action.mobile-icon-only {
    justify-content: center;
    width: var(--tap-target);
    height: var(--tap-target);
  }

  .list-action.mobile-icon-only app-icon {
    display: inline-flex;
    padding: var(--space-2);
    border: 1px solid var(--border);
    border-radius: var(--radius-sm);
  }
}

// The list header keys to its own width; the reader view's nav has no such
// container and keeps the viewport rule.
@container list-header (width <= bp.$container-list-header-compact) {
  @include icon-only-box;
}

@media (width <= bp.$bp-sm) {
  @include icon-only-box;
}
```

- [ ] **Step 8: Run the new spec and the phone spec**

Check the dev container first (see Global Constraints). Then, from `frontend/`:
`npx playwright test e2e/list-header-narrow-pane.spec.ts e2e/list-header-actions-mobile.spec.ts --reporter=line`
Expected: all PASS.

- [ ] **Step 9: Commit**

```bash
git add frontend/e2e/list-header-narrow-pane.spec.ts frontend/src/app/theme/_breakpoints.scss frontend/src/app/reader/entry-list/entry-list.component.scss frontend/src/app/reader/reader-shell.component.scss frontend/src/styles/_list-action.scss
git commit -m "fix(#1127): key the list header's compact actions to its own width"
```

---

### Task 2: A title floor; the tools wrap under it

**Files:**
- Modify: `frontend/e2e/list-header-narrow-pane.spec.ts`
- Modify: `frontend/src/app/reader/entry-list/entry-list.component.scss` (`.list-header`, `.heading`, `.tools`)

**Interfaces:**
- Consumes: `openSplit`, `SplitList`, `ALL_ITEMS`, `FEED`, `DIRECT_SEARCH` from Task 1.

- [ ] **Step 1: Add the failing geometry tests**

Add to the spec, above `test.describe`:

```ts
// The `.heading` flex basis in entry-list.component.scss: 6rem at the 16px root.
const TITLE_FLOOR_PX = 96;

interface HeaderGeometry {
  headingWidth: number;
  toolsRight: number;
  contentRight: number;
}

async function headerGeometry(page: Page): Promise<HeaderGeometry> {
  return page.locator('.list-header').evaluate((header) => {
    const part = (selector: string): DOMRect => {
      const element = header.querySelector(selector);
      if (!element) throw new Error(`list header has no ${selector}`);
      return element.getBoundingClientRect();
    };
    return {
      headingWidth: part('.heading').width,
      toolsRight: part('.tools').right,
      contentRight:
        header.getBoundingClientRect().right - parseFloat(getComputedStyle(header).paddingRight),
    };
  });
}
```

Add inside `test.describe`:

```ts
  for (const list of [ALL_ITEMS, FEED, DIRECT_SEARCH]) {
    for (const paneSplit of ['25.6', '35', '45', '60']) {
      test(`${list.name} at split ${paneSplit} keeps the title readable and the tools inside the column`, async ({
        page,
      }) => {
        await openSplit(page, list, paneSplit);

        const geometry = await headerGeometry(page);
        expect(geometry.headingWidth).toBeGreaterThanOrEqual(TITLE_FLOOR_PX);
        expect(geometry.toolsRight).toBeCloseTo(geometry.contentRight, 0);
      });
    }
  }
```

`toBeCloseTo(…, 0)` holds the tools to the header's right content edge within half a pixel: inside the column, and right-aligned on a second row.

- [ ] **Step 2: Run it and see it fail**

Run: `npx playwright test e2e/list-header-narrow-pane.spec.ts --reporter=line`
Expected: the split 25.6 cases FAIL (heading width 0 or below 96, tools past the edge); the Task 1 tests still PASS.

- [ ] **Step 3: Let the header wrap and give the heading its floor**

In `entry-list.component.scss`:

At the top, after `$header-clear`, add:

```scss
$title-floor: 6rem;
```

In `.list-header`, after `justify-content: space-between;`, add:

```scss
  flex-wrap: wrap;
```

Replace the `.heading` rule with:

```scss
/* The basis is the title's floor: past it the tools wrap to a second row
   (the ResizeObserver re-reserves the taller bar) instead of crushing the title. */
.heading {
  display: flex;
  flex-direction: column;
  flex: 1 1 $title-floor;
  min-width: 0; /* let the title truncate instead of shoving the tools away */
}
```

In `.tools`, after `flex: none;`, add:

```scss
  margin-left: auto;
```

- [ ] **Step 4: Run the specs**

Run: `npx playwright test e2e/list-header-narrow-pane.spec.ts e2e/list-header-actions-mobile.spec.ts e2e/list-header-count-one-line.spec.ts e2e/header-scroll-mobile.spec.ts --reporter=line`
Expected: all PASS. If the phone specs fail because a 320–375 px header now wraps, stop and report the measured heading width; do not lower the floor silently.

- [ ] **Step 5: Look at the real render**

Recreate the throwaway measurement spec if needed (it is not committed), take screenshots at 1280 px for splits 25.6, 35, 45, 60 (All items, feed, direct search) and at phone widths 320, 360, 375, and Read each PNG. Check: title readable, tools inside the column, second row right-aligned, no gap between the header and the first row. Delete the throwaway spec afterwards.

- [ ] **Step 6: Commit**

```bash
git add frontend/e2e/list-header-narrow-pane.spec.ts frontend/src/app/reader/entry-list/entry-list.component.scss
git commit -m "fix(#1127): give the list title a floor and wrap the tools under it"
```

---

### Task 3: Gate and docs

**Files:**
- Modify: `docs/design-language.md` (the `@container` paragraph, ~line 191)

- [ ] **Step 1: Document the container**

After the paragraph that starts "`@container` queries take their thresholds from `_breakpoints.scss` too", add:

```md
**The list header's compact form is container-driven (#1127).** `.list-header` is
the `list-header` container; its compact actions key to
`bp.$container-list-header-compact`, so a narrow split column gets the same icon
form as a phone. A rule for a header action goes in that `@container`, not in a
viewport `@media`.
```

- [ ] **Step 2: Run the CI gate**

Run: `docker compose exec -T frontend npm run check`
Expected: ESLint, Prettier, Stylelint, typecheck and Jest all pass. Fix Prettier findings with `npx prettier --write` on the spec file only.

- [ ] **Step 3: Commit**

```bash
git add docs/design-language.md
git commit -m "docs(#1127): record the list header container in the design language"
```

---

### Task 4: Every list-header action switches form together

Added 2026-09-23 on Lars's instruction: "the visual behaviour of all action links in the headers should always be unified". In the compact form a direct search kept short labels on Save search/Remove and Mark all read (#581, #679) while the unread switch beside it went icon-only. From now on every `appListAction` in a list header is labelled in the wide form and icon-only in the compact form — no exceptions. **Scope: the list headers only.** The reader-view nav keeps its `mobile-icon-only` modifier, its `@media` rule and its labelled Reader view/Original toggle. This task overrides the Global Constraint "No template, TypeScript or backend change" for the files listed below.

**Files:**
- Modify: `frontend/src/styles/_list-action.scss` (the `icon-only-box` mixin and its two call sites)
- Modify: `frontend/src/app/reader/entry-list/entry-list.component.html` (refresh, unread switch, mark all read)
- Modify: `frontend/src/app/reader/entry-list/entry-list.component.scss` (`.txt-short` rule, compact container block)
- Modify: `frontend/src/app/reader/reader-shell.component.html` (`#listEditAction`, `#headerActions`)
- Modify: `frontend/src/app/reader/reader-shell.component.scss` (save-search / for-you / list-edit label rules)
- Modify: `frontend/src/app/reader/reader-shell.component.ts` (delete `savedSearchActionShortLabel`)
- Modify: `frontend/public/i18n/en.json`, `frontend/public/i18n/de.json` (delete `saveSearchShort`, `removeSavedSearchShort`, `markAllReadShort`)
- Modify: `frontend/src/app/reader/entry-list/entry-list.component.spec.ts`, `frontend/src/app/reader/reader-shell.component.spec.ts` (delete the short-label / modifier tests)
- Modify: `frontend/e2e/list-header-narrow-pane.spec.ts`, `frontend/e2e/list-header-actions-mobile.spec.ts`

- [ ] **Step 1: Write the failing e2e expectations**

In `frontend/e2e/list-header-narrow-pane.spec.ts`, replace the test `'a direct search keeps its short labels in the compact form'` with:

```ts
  for (const list of [ALL_ITEMS, FEED, DIRECT_SEARCH]) {
    test(`every action of ${list.name} is icon-only in the compact form`, async ({ page }) => {
      await openSplit(page, list, '45');
      await expectEveryAction(page, { labelled: false });
    });

    test(`every action of ${list.name} is labelled in the wide form`, async ({ page }) => {
      await openSplit(page, list, '60');
      await expectEveryAction(page, { labelled: true });
    });
  }
```

and add this helper above `test.describe`:

```ts
async function expectEveryAction(page: Page, form: { labelled: boolean }): Promise<void> {
  const actions = page.locator('.list-header .list-action');
  expect(await actions.count()).toBeGreaterThan(1);
  for (const action of await actions.all()) {
    const label = action.locator('.txt');
    await (form.labelled ? expect(label).toBeVisible() : expect(label).toBeHidden());
    await expect(action.locator('app-icon')).toHaveCSS(
      'border-top-width',
      form.labelled ? '0px' : '1px',
    );
  }
}
```

In `frontend/e2e/list-header-actions-mobile.spec.ts`, replace the test `'actions with a visible mobile label keep the borderless link treatment'` with:

```ts
  test('a direct search uses the same icon-only form as every other list', async ({ page }) => {
    await openReader(page, 'q=design');

    const actions = page.locator('.list-header .list-action');
    await expect(actions).toHaveCount(3);

    for (const action of await actions.all()) {
      await expect(action.locator('.txt')).toBeHidden();
      await expect(action.locator('app-icon')).toHaveCSS('border-top-width', '1px');
    }
  });
```

In the third test of that file (`'an individual saved-search result …'`), delete the line `await expect(action.locator('.txt-short')).toBeHidden();`.

- [ ] **Step 2: Run them and see them fail**

Run (from `frontend/`): `npx playwright test e2e/list-header-narrow-pane.spec.ts e2e/list-header-actions-mobile.spec.ts --reporter=line`
Expected: the direct-search compact cases FAIL (Save search / Mark all read keep a borderless icon); everything else PASSES.

- [ ] **Step 3: One compact rule for every header action**

In `frontend/src/styles/_list-action.scss`, replace the mixin and its two call sites with:

```scss
// Icon-only actions get the sidebar chevron treatment: a full tap target around
// a smaller bordered glyph box.
@mixin icon-only-box($action) {
  #{$action} {
    justify-content: center;
    width: var(--tap-target);
    height: var(--tap-target);
  }

  #{$action} app-icon {
    display: inline-flex;
    padding: var(--space-2);
    border: 1px solid var(--border);
    border-radius: var(--radius-sm);
  }
}

// Every list-header action switches form together, with no per-action exception (#1127).
@container list-header (width <= #{bp.$container-list-header-compact}) {
  @include icon-only-box('.list-header .list-action');

  .list-header .list-action .txt {
    display: none;
  }
}

// The reader view's nav has no list-header container and opts in per action.
@media (width <= bp.$bp-sm) {
  @include icon-only-box('.list-action.mobile-icon-only');
}
```

Also fix the spin comment above it (`on a phone that box wears the mobile-icon-only border`) to `in the compact form that box wears a border`.

- [ ] **Step 4: Delete the per-action exceptions in the templates**

`entry-list.component.html`:
- Refresh: `class="refresh mobile-icon-only"` → `class="refresh"`; delete `<span class="txt-short">{{ 'reader.refresh' | transloco }}</span>`.
- Unread switch: `class="unread-switch mobile-icon-only"` → `class="unread-switch"`. Its label span keeps `class="txt"`.
- Mark all read: delete `[class.mobile-icon-only]="!directSearch()"`; delete `<span class="txt-short">{{ 'reader.markAllReadShort' | transloco }}</span>`.

`reader-shell.component.html`:
- Both `class="list-edit mobile-icon-only"` → `class="list-edit"`.
- Save search: delete `[class.mobile-icon-only]="viewingSavedSearch()"` and the `<span class="txt-short">…</span>` line.
- For You Start (`appListAction`): `class="for-you-run mobile-icon-only"` → `class="for-you-run"`, and its `<span class="label">` → `<span class="txt">`. The Stop `app-button` keeps `<span class="label">` (it is not a list action).

Keep `directSearch` in `entry-list.component.ts`: `effectiveLayout` still reads it. If `viewingSavedSearch` in the shell has no other reader after this edit, delete it too (check with grep).

- [ ] **Step 5: Delete the per-component label rules**

`entry-list.component.scss`: delete the `.txt-short { display: none; }` rule with its comment. In the compact container block keep only the `.tools` gap rule; delete the `.unread-switch .txt, .mark-all .txt, .refresh .txt` rule and the `.list-header.is-search … .txt-short` rule with its comment. Update the block comment so it does not claim labels are dropped here (the global sheet does it): e.g. `/* Bordered icon buttons sit tighter than the text links of the wide header. */` directly above the block, and drop the inner duplicate.

`reader-shell.component.scss`: delete both `.save-search .txt-short` rules, the `.save-search .txt`/`.save-search.mobile-icon-only .txt-short` rules and the `.list-edit .txt` container block with its comment. What remains in a compact container block is only the Stop button:

```scss
/* The For You Stop button is an app-button, not a list action, so it drops its label here. */
@container list-header (width <= #{bp.$container-list-header-compact}) {
  .for-you-run .label {
    display: none;
  }
}
```

Delete the now-empty explanatory comments above them (`/* Save/unsave the search … */`, `/* The list header's leading edit action … */`) if nothing follows them any more.

- [ ] **Step 6: Delete the dead short labels**

- `reader-shell.component.ts`: delete `savedSearchActionShortLabel` and its docblock.
- `public/i18n/en.json` and `de.json`: delete `saveSearchShort`, `removeSavedSearchShort`, `markAllReadShort` (grep `src` first to prove no other reader).
- `entry-list.component.spec.ts`: delete the tests `'renders a mobile short-label span beside the full label on mark-all and refresh'`, `'marks saved-search result actions as icon-only on mobile'`, `'keeps the short Mark read label for a direct search on mobile'` and the comment above the first.
- `reader-shell.component.spec.ts`: delete the four tests from `'renders "Save" as the mobile short label …'` to `'keeps the short action label for a direct search on mobile'` and the comment above them.

- [ ] **Step 7: Run the specs**

Run (from `frontend/`): `npx playwright test e2e/list-header-narrow-pane.spec.ts e2e/list-header-actions-mobile.spec.ts e2e/saved-search-layout.spec.ts e2e/desktop-search-split.spec.ts --reporter=line`
Expected: all PASS. Then `docker compose exec -T frontend npx jest src/app/reader/entry-list/entry-list.component.spec.ts src/app/reader/reader-shell.component.spec.ts` (one jest run at a time). Expected: PASS.

- [ ] **Step 8: Look at the real render**

Screenshot a direct search at 1280 px split 45 and at a 375 px phone, and a feed at split 60 (throwaway spec, not committed). Every action in a row has the same form.

- [ ] **Step 9: Commit**

```bash
git add frontend/src/styles/_list-action.scss frontend/src/app/reader/entry-list/entry-list.component.html frontend/src/app/reader/entry-list/entry-list.component.scss frontend/src/app/reader/entry-list/entry-list.component.spec.ts frontend/src/app/reader/reader-shell.component.html frontend/src/app/reader/reader-shell.component.scss frontend/src/app/reader/reader-shell.component.ts frontend/src/app/reader/reader-shell.component.spec.ts frontend/public/i18n/en.json frontend/public/i18n/de.json frontend/e2e/list-header-narrow-pane.spec.ts frontend/e2e/list-header-actions-mobile.spec.ts
git commit -m "fix(#1127): switch every list-header action to the compact form together"
```
