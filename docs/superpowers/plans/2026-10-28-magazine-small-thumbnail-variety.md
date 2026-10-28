# Magazine Variety for Small-Thumbnail Feeds Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** A magazine page whose slots all settled at `thumb` size or smaller lifts one slot back to the larger image block its template asked for, so a feed of small thumbnails (subscription 707, smarticular, 202×202) mixes layouts again.

**Architecture:** One post-pass in `layOutPage` (`magazine-planner.ts`), after `assign` and the second `withinBudget`. A page with no unpinned `hero`/`wide`/`split` picks its tallest unpinned slot whose template kind is an image kind and whose entry has an image, and walks that kind down the image ladder (`hero → wide → split`) to the first kind the picture can take regardless of size (orientation rule kept: no portrait in `hero`/`wide`) and the page height cap allows. Everything stays page-indexed and content-local, so prefix stability holds.

**Tech Stack:** Angular 20, TypeScript, Jest (run inside the Docker `frontend` container).

**Spec:** Issue #1852 plus the design agreed in chat:
- The main objective is variety.
- When the entries would all produce the same layout, a template slot that asks for a larger image block may keep it now and then, even though the image is below that block's usual size floor.
- Image-less blocks are avoided when an entry has an image — in the image family. The text family (high-volume, wire-style feeds) keeps its current behaviour: some text blocks there are fine.

## Global Constraints

- Frontend tests run in Docker: `docker compose exec -T frontend npx jest <path>`; the CI gate is `docker compose exec -T frontend npm run check`.
- Prettier 100 columns; no single-letter or truncated names (closure params included).
- Comments: default to none; one line when a future reader would get the code wrong without it.
- Commit format `fix(#1852): …`; no attribution lines.
- Do not change `magazine-family.ts` or the text family.

## Background the implementer needs

- `IMAGE_TEMPLATES` (`magazine-templates.ts`) authors `hero`/`wide`/`split`; `FITS` (`magazine-slot-fit.ts`) refuses them below 500/400/300px, so 202px pictures demote every such slot to `thumb`.
- `templateFor(page)` = `templates[(page * 5 + 1) % length]`. With 12 image templates: page 0 → index 1 `['wide','split','thumb','split','thumb','split']`, page 1 → index 6 `['hero','split','thumb','thumb','kicker']`, page 2 → index 11.
- `BLOCK_HEIGHT`: hero 463, media 463, wide 321, split 150, thumb 90. `PAGE_HEIGHT_CAP` 1100.
- Requirement "no image-less block for an entry with an image (image family)" is already pinned by the spec test `never plans an entry with an image into a text block in the image family`; no new code needed for it.

---

### Task 1: Lift one slot on a page without a large image block

**Files:**
- Modify: `frontend/src/app/reader/list/magazine/magazine-slot-fit.ts` (add `fitsAtAnySize`)
- Modify: `frontend/src/app/reader/list/magazine/magazine-planner.ts` (`layOutPage`, `withinBudget`, new `pageHeight`, `liftFeaturelessPage`, `hasLargeImageBlock`)
- Test: `frontend/src/app/reader/list/magazine/magazine-planner.spec.ts`

**Interfaces:**
- Produces: `export function fitsAtAnySize(kind: TemplateKind, entry: EntryDto): boolean` in `magazine-slot-fit.ts`.

- [ ] **Step 1: Write the failing tests**

Add next to the other fixtures at the top of `magazine-planner.spec.ts` (after `wire`):

```ts
// A WordPress feed's entry: one small square crop and a teaser too short for a pull quote.
const smallThumb = (id: number, over: Partial<EntryDto> = {}): EntryDto =>
  entryAt(id, {
    imageUrl: `https://i/${id}-202x202.jpg`,
    imageWidth: 202,
    imageHeight: 202,
    imageRenditions: [],
    summary: 'A teaser of about one and a half lines, the way a WordPress feed ships it. '.repeat(2),
    ...over,
  });
```

Add these tests inside `describe('planMagazine', …)`, after `promotes a text slot to the tallest image block no taller than the slot`:

```ts
  it('gives each page of a small-thumbnail feed one larger block the template asks for', () => {
    const ks = kinds(planMagazine({ entries: many(17, smallThumb), grouping: false, complete: true }));
    expect(ks.slice(0, 11)).toEqual([
      'wide', 'thumb', 'thumb', 'thumb', 'thumb', 'thumb',
      'hero', 'thumb', 'thumb', 'thumb', 'thumb',
    ]);
    expect(ks[11]).toBe('hero');
  });

  it('lifts a page that mixes thumbnails with text blocks but shows no large image', () => {
    const entries = many(6, (index) =>
      index === 3 ? entryAt(index) : smallThumb(index),
    );
    const ks = kinds(planMagazine({ entries, grouping: false, complete: true }));
    expect(ks).toEqual(['wide', 'thumb', 'kicker', 'thumb', 'thumb', 'thumb']);
  });

  it('keeps a small portrait picture out of a lifted hero or wide', () => {
    const entries = many(11, (index) => smallThumb(index, { imageHeight: 360 }));
    const ks = kinds(planMagazine({ entries, grouping: false, complete: true }));
    expect(ks[0]).toBe('split');
    expect(ks[6]).toBe('split');
  });

  it('lifts only as far as the page height cap allows', () => {
    // Page 1 asks for a hero beside a pinned media share: 463 + 463 + 3 × 90 exceeds the cap.
    const entries = many(11, (index) => (index === 8 ? mediaShare(index) : smallThumb(index)));
    const ks = kinds(planMagazine({ entries, grouping: false, complete: true }));
    expect(ks.slice(6, 8)).toEqual(['wide', 'media']);
  });

  it('is prefix-stable for a small-thumbnail feed', () => {
    const entries = many(40, smallThumb);
    const full = planned(planMagazine({ entries, grouping: false, complete: true }));
    const partial = planned(planMagazine({ entries: entries.slice(0, 23), grouping: false, complete: false }));
    expect(full.slice(0, partial.length)).toEqual(partial);
  });
```

Run Prettier over the file after pasting (Step 4 does), so the arrays wrap as it decides.

- [ ] **Step 2: Run the tests to verify they fail**

Run: `docker compose exec -T frontend npx jest src/app/reader/list/magazine/magazine-planner.spec.ts -t "small-thumbnail|thumbnails with text|small portrait|height cap allows"`
Expected: the first four FAIL (every kind is `thumb`/`kicker`, no `wide`/`hero`/`split`); the prefix-stability test may already pass.

- [ ] **Step 3: Implement**

In `magazine-slot-fit.ts`, below `fits`:

```ts
/** Whether the entry's picture could take `kind` were its file large enough: no size floor,
 *  but a portrait cover still never fills a `hero` or `wide`. */
export function fitsAtAnySize(kind: TemplateKind, entry: EntryDto): boolean {
  if (!IMAGE_KINDS.includes(kind) || entryImage(entry) === null) return false;
  return (kind !== 'hero' && kind !== 'wide') || portraitCoverRatio(entry) === null;
}
```

In `magazine-planner.ts`:

1. Extend the slot-fit import: `import { fits, fitsAtAnySize, ImageBar, IMAGE_KINDS, shareKind, settle } from './magazine-slot-fit';`
2. Extract the height sum out of `withinBudget` and use it there (`let height = pageHeight(result, layout);`):

```ts
function pageHeight(kinds: TemplateKind[], layout: PageLayout): number {
  return kinds.reduce(
    (sum, kind, position) => sum + (layout.pinned[position]?.height ?? BLOCK_HEIGHT[kind]),
    0,
  );
}
```

3. In `layOutPage`, after `assigned`:

```ts
  const lifted = liftFeaturelessPage(wanted, assigned, slice, layout);

  return lifted.map((kind, position) =>
    toBlock(layout.pinned[position]?.kind ?? kind, slice[position], { page, position }),
  );
```

4. Add below `layOutPage`:

```ts
/** Variety over fit: a page that settled without a large image block gives its tallest asked-for
 *  image slot the largest block its picture can take at any size, within the height cap. */
function liftFeaturelessPage(
  wanted: TemplateKind[],
  assigned: TemplateKind[],
  slice: EntryDto[],
  layout: PageLayout,
): TemplateKind[] {
  if (hasLargeImageBlock(assigned, layout)) return assigned;
  const position = tallestWhere(
    wanted,
    (kind, at) =>
      layout.pinned[at] === null &&
      BLOCK_HEIGHT[kind] > BLOCK_HEIGHT[assigned[at]] &&
      fitsAtAnySize('thumb', slice[at]) &&
      IMAGE_KINDS.includes(kind),
  );
  if (position === null) return assigned;

  const lifted = [...assigned];
  for (
    let kind = wanted[position];
    BLOCK_HEIGHT[kind] > BLOCK_HEIGHT[assigned[position]];
    kind = DEMOTION[kind]
  ) {
    lifted[position] = kind;
    if (fitsAtAnySize(kind, slice[position]) && pageHeight(lifted, layout) <= PAGE_HEIGHT_CAP) {
      return lifted;
    }
  }
  return assigned;
}

function hasLargeImageBlock(kinds: TemplateKind[], layout: PageLayout): boolean {
  return kinds.some(
    (kind, position) =>
      layout.pinned[position] === null && IMAGE_KINDS.includes(kind) && kind !== 'thumb',
  );
}
```

`fitsAtAnySize('thumb', entry)` reads "has a picture at all"; keep it rather than importing `entryImage` into the planner.

- [ ] **Step 4: Run the magazine specs and format**

Run: `docker compose exec -T frontend npx prettier --write src/app/reader/list/magazine/magazine-planner.ts src/app/reader/list/magazine/magazine-slot-fit.ts src/app/reader/list/magazine/magazine-planner.spec.ts`
Run: `docker compose exec -T frontend npx jest src/app/reader/list/magazine`
Expected: the new tests PASS. If an existing test now fails, read it before touching it: a test whose expectation encodes an all-thumb (featureless) image-family page is the old monotony and gets its expectation updated to the lifted layout; any other failure is a bug in this task — fix the code, not the test. Name every changed expectation in the commit body.

- [ ] **Step 5: Verify the guard by breaking it**

Temporarily make `hasLargeImageBlock` return `true`; rerun the four new lift tests; confirm each FAILS (quote the failures). Restore the function by editing it back (not `git checkout --`), rerun, confirm PASS.

- [ ] **Step 6: Commit**

```bash
git add frontend/src/app/reader/list/magazine/magazine-slot-fit.ts frontend/src/app/reader/list/magazine/magazine-planner.ts frontend/src/app/reader/list/magazine/magazine-planner.spec.ts
git commit -m "fix(#1852): a magazine page without a large image block lifts one slot past its size floor"
```

### Task 2: Gate and live check

- [ ] **Step 1:** `docker compose exec -T frontend npm run check` — Expected: exit 0.
- [ ] **Step 2:** Open `http://green-tara.local:4200/?subscription=707` in the built-in browser (Mobile viewport preset — the desktop UA is bot-blocked) and screenshot the first two screens. Expected: a `wide` block opens the list, a `hero` follows six entries later, thumbnails between. Keep each browser call short; no long in-page waits.
- [ ] **Step 3:** Push the branch and open a PR into `develop` with `Closes #1852`.

---

## Amendment (after Lars's live check): lift every image slot, not one

Lars's verdict on the deployed-locally result: still too static — "many entries with a small image on the left and then one entry with a big image on top"; templates also have small images on the right (`split`, seeded `imageSide`): mix it up, don't repeat one pattern.

**Rule now:** on a page that settled without an unpinned `hero`/`wide`/`split`, *every* unpinned slot whose template kind is an image kind and whose entry has a picture walks down the image ladder from its asked kind to the first kind the picture can take at any size within the height cap. Slots are visited tallest-asked first (ties: first position), each cap check including the slots already lifted. The page then shows its template's authored rhythm.

### Task 3: Lift every image slot of a featureless page

**Files:** `frontend/src/app/reader/list/magazine/magazine-page-lift.ts`, `frontend/src/app/reader/list/magazine/magazine-planner.spec.ts`

- [ ] **Step 1: Update the expectations (they must fail first)**
  - `gives each page of a small-thumbnail feed …` → rename `follows each template's own rhythm in a small-thumbnail feed`; expect
    `ks.slice(0, 11)` = `['wide','split','thumb','split','thumb','split','hero','split','thumb','thumb','thumb']`
    and `ks.slice(11, 17)` = `['hero','thumb','split','thumb','thumb','thumb']`.
    (Page 0 = template 1 `wide,split,thumb,split,thumb,split` = 951; page 1 = template 6 `hero,split,thumb,thumb,kicker` → kicker slot stays `thumb`, 883; page 2 = template 11, 973.)
  - Add to the same test: the `split` blocks' `imageSide` values in the first 17 blocks include both `'left'` and `'right'`.
  - `lifts a page that mixes thumbnails with text blocks …` → expect `['wide','split','kicker','split','thumb','split']` (1001).
  - Portrait test and cap test keep their expectations (`split` at 0 and 6; `['wide','media']` at 6–7) — run them, they must still pass.
  - Run the planner spec; the two changed tests FAIL (lifted only one slot).
- [ ] **Step 2: Implement** — in `liftFeaturelessPage`, replace the single tallest-slot pick with a loop: repeatedly pick, among unpinned positions not yet visited, the tallest asked image kind taller than its settled kind whose entry `hasPicture` (reuse `tallestWhere` with a visited set or by returning on each lifted position), walk its ladder as today against the running `lifted` array, keep the first kind that `fitsAtAnySize` and keeps `pageHeight(lifted, layout) <= PAGE_HEIGHT_CAP`, else restore that slot to its settled kind; continue until no candidate remains. Keep the function under the max-params/max-lines lint limits and readable as a sentence; split a helper `liftSlot(…)` if it helps.
- [ ] **Step 3:** run `docker compose exec -T frontend npx jest src/app/reader/list/magazine`, then `docker compose exec -T frontend npm run check` (exit 0).
- [ ] **Step 4: Break test:** make the loop stop after the first lift; the rhythm test and mixed-page test FAIL (quote); restore by hand.
- [ ] **Step 5: Commit** `fix(#1852): a featureless page lifts every image slot to its template's ask`.
