# One Owner for the Reader Article's Tail Padding — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** One rule sets the article's `padding-bottom`; each reason for tail room only names its own size, and the larger wins regardless of source order.

**Architecture:** `.reader article` gets `padding-bottom: max(var(--reader-focus-tail, 0%), var(--reader-cinema-tail, 0%))`. `ReadingScope`'s `.with-tail` class (#107) sets `--reader-focus-tail: 50cqb`, and the cinema container query (#1479) sets `--reader-cinema-tail: calc(100cqb - var(--reader-top))`. Both sizes now use the reading pane's height (`cqb` against the `reader-pane` container on `.scroller`), which is the height `ReadingScope` measures (`scroller.clientHeight`), so the `vh`/`dvh` pair and its three stylelint-disable comments go away: custom properties are not padding declarations, and `%` is an allowed padding unit.

**Why not move the cinema decision into `ReadingScope`** (the issue's example): `ReadingScope` would then need the cinema container breakpoint (`$container-reader-cinema`, a CSS container-query fact) and the DOM class `reader-cinema.ts` toggles. That would duplicate both in TypeScript, and the toggle state would have to be bridged into a signal. The cinema tail is unconditional on overflow by design (#1479: a short article must still scroll a widened video to the top), so there is no `ReadingScope` logic for it to pass through. `ReadingScope` keeps owning the one decision it measures, "does the article overflow?", and the CSS composes the two sizes in one place.

**Tech Stack:** Angular 20, SCSS, Playwright e2e.

**Spec:** GitHub issue #1482 (`gh issue view 1482`).

## Global Constraints

- CLAUDE.md frontend rules: no hex colours or ad-hoc `px` spacing outside the theme; component styles stay in the sibling `.scss`.
- Comments: one line where possible, only what a reader would otherwise get wrong.
- `npm run check` is the gate. Frontend unit tests run inside the Docker frontend container. e2e runs from this checkout against the Docker stack.
- Commit format: `refactor(#1482): <lower-case summary>`. No attribution lines.

---

### Task 1: e2e pins the composed tail

**Files:**
- Modify: `frontend/e2e/article-tail-space.spec.ts`

**Interfaces:**
- Consumes: the existing `stubArticle`, `openArticle`, `LONG_BODY` and `SHORT_BODY` in that spec.

- [ ] **Step 1: Add the cinema cases**

Append inside the file, after the existing `test.describe`:

```ts
const DESKTOP = { width: 1920, height: 1000 };
const VIDEO = '<video src="https://example.invalid/v.mp4" width="1280" height="720"></video>';

/** The article's bottom padding and the room a widened video needs, in px. */
async function tailMetrics(pane: Locator) {
  return pane.locator('.scroller').evaluate((scroller) => {
    const article = scroller.querySelector('article')!;
    const readerTop = parseFloat(getComputedStyle(scroller.querySelector('.reader')!).paddingTop);
    return {
      padding: parseFloat(getComputedStyle(article).paddingBottom),
      cinemaRoom: scroller.clientHeight - readerTop,
      focusRoom: scroller.clientHeight / 2,
    };
  });
}

test.describe('Article tail space with a widened video', () => {
  test.use({ viewport: DESKTOP });

  // #1479/#1482: a widened video can reach the top of the pane however short the
  // article, and the larger of the two tail needs wins whatever the rule order.
  test('a short article reserves the widened video its room', async ({ page }) => {
    const signedIn = await signInWithLayout(page, 'list');
    test.skip(!signedIn, 'seeded admin login unavailable (run app:e2e:seed-admin)');
    await stubArticle(page, VIDEO + SHORT_BODY);
    await page.reload();
    const pane = await openArticle(page);

    expect((await tailMetrics(pane)).padding).toBeLessThanOrEqual(1);

    await pane.locator('.reader-cinema__toggle').click();
    const metrics = await tailMetrics(pane);
    expect(Math.abs(metrics.padding - metrics.cinemaRoom)).toBeLessThanOrEqual(1);
  });

  test('a long article takes the larger tail while the video is widened', async ({ page }) => {
    const signedIn = await signInWithLayout(page, 'list');
    test.skip(!signedIn, 'seeded admin login unavailable (run app:e2e:seed-admin)');
    await stubArticle(page, VIDEO + LONG_BODY);
    await page.reload();
    const pane = await openArticle(page);

    const reading = await tailMetrics(pane);
    expect(Math.abs(reading.padding - reading.focusRoom)).toBeLessThanOrEqual(1);

    await pane.locator('.reader-cinema__toggle').click();
    const widened = await tailMetrics(pane);
    expect(Math.abs(widened.padding - Math.max(widened.focusRoom, widened.cinemaRoom))).toBeLessThanOrEqual(1);
  });
});
```

Add `Locator` to the `@playwright/test` import.

- [ ] **Step 2: Run against the current CSS**

Run (from `frontend/`, Docker stack up): `npx playwright test e2e/article-tail-space.spec.ts`

Expected: the new desktop cases PASS on the old CSS, apart from the focus-room check, which FAILs. The old focus tail is `50dvh`, which is larger than half the pane on desktop, because the pane sits below the app bar. That failure documents the unit change. If the video doesn't decorate (no `.reader-cinema__toggle`), check that the stubbed body keeps the `<video>` through the frontend renderer. If it doesn't, use a landscape YouTube embed the way `reader-cinema.spec.ts` builds one.

---

### Task 2: one padding rule

**Files:**
- Modify: `frontend/src/app/reader/article/reader-view/reader-view.component.scss` (the `.reader.with-tail article` block and the `@container reader-pane (width > …)` block below it)

- [ ] **Step 1: Replace both blocks**

```scss
/* The article's tail is the larger of its two needs: the reading focus's (#107),
   which ReadingScope grants only when the article overflows, and a widened video's
   (#1479), which must reach the pane's top however short the article. */
.reader article {
  padding-bottom: max(var(--reader-focus-tail, 0%), var(--reader-cinema-tail, 0%));
}

.reader.with-tail article {
  --reader-focus-tail: 50cqb;
}

@container reader-pane (width > #{bp.$container-reader-cinema}) {
  .reader article:has(.reader-cinema--on) {
    --reader-cinema-tail: calc(100cqb - var(--reader-top));
  }
}
```

- [ ] **Step 2: Run the e2e spec and the gate**

Run: `npx playwright test e2e/article-tail-space.spec.ts`. Expected: all four tests PASS, including the phone cases from #107.
Run: `docker compose exec -T frontend npm run check`. Expected: PASS, with no stylelint disables left in the tail rules.

- [ ] **Step 3: Check it on the real render**

Open a real long article and a real article with a YouTube embed in the dev app (`:4200`) at desktop width. Scroll to the end with cinema off and with it on; the video must reach the pane top. Repeat on the mobile viewport preset: the last paragraph must reach the reading centre, as in #107.

- [ ] **Step 4: Commit**

```bash
git add frontend/src/app/reader/article/reader-view/reader-view.component.scss frontend/e2e/article-tail-space.spec.ts
git commit -m "refactor(#1482): one rule owns the article's tail padding"
```

The commit body carries the design rationale from the Architecture section above: why the size moved to `cqb`, and why the cinema decision stays in CSS.
