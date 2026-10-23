# Sidebar grouped cards (design B) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Rebuild the reader sidebar as design B from #1749. Each section becomes its own card on the page grey, every row gets the same right-edge columns, an open tag becomes a grey group with an inner box per child tag, hidden feeds get a greyed favicon with an eye badge, the footer becomes a card that scrolls with the list, and the phone drawer becomes a full-height sheet with its own header, an organise mode and a tag sheet that can move a tag up or down.

**Architecture:** Five changes carry the redesign.
- **Shared mixins.** `_sidebar-row.scss` is rewritten into the shared anatomy: `card`, `row-geometry`, `row-states`, `row-link`, `row-parts`, `chevron`, `dots`, `row-menu`, `section-head`, `group` and `drag-handle`.
- **Rows are hosts.** `app-sidebar-feed-row` and `app-sidebar-group-row` now are the row box themselves (`:host` with `row-geometry`). Their parents tell them their height, inset and type size through the inherited custom properties `--row-h`, `--row-inset` and `--row-fs`, so no styling has to reach across component boundaries.
- **Nesting.** It is built from wrapper divs and `ngTemplateOutlet`. Every `cdkDropList` stays a top-level sibling.
- **New tokens.** The new measures live in `tokens.scss`, because the CDK drag preview leaves the sidebar for `<body>` and still has to read them.
- **Drawer.** The shell owns its geometry. The sidebar gains a `closeDrawer` output.

**Tech Stack:** Angular 20 (standalone components, signals, control flow), `@angular/cdk/drag-drop`, Transloco, SCSS with Stylelint (`stylelint-config-standard-scss`), Jest (jsdom) inside the Docker `frontend` container, Playwright e2e.

**Spec:** GitHub issue #1749. Design canvas "Sidebar Redesign" (https://claude.ai/artifact/B2BABXEnfANfG4TXfgdrk8). The boards are `Grouped.dc.html` (desktop and phone through the `device` prop; `nesting=surface` is the settled variant), `GroupedOrganise.dc.html` (phone organise; `sheet=true` is the tag-actions sheet), `FullPage.dc.html` and `ds/simple-feed-reader/tokens.json`. A local copy for this planning session sat under the session scratchpad. Re-read the boards with the Artifact tool (`action: "read"`, `path: "project/<Board>.dc.html"`).

## Global Constraints

- Work on `feature/1749-sidebar-grouped-cards`. Do not switch branches. One PR into `develop`.
- Commits use `feat(#1749): …` and carry no attribution lines.
- Every task leaves `docker compose exec -T frontend npm run check` green. Run it from the repo root. Frontend tests run only inside the Docker `frontend` container, e.g. `docker compose exec -T frontend npm test -- <path>`.
- Before each gate, format the files you touched: `docker compose exec -T frontend npx prettier --write <paths relative to frontend/>`.
- **Tokens only.**
  - No hex in component `.scss`. Hex is allowed only in `src/app/theme/`, `src/styles/` and `src/styles.scss`.
  - No px in `padding*`, `margin*`, `gap`, `font-size`, `border-radius`, `width`, `height`, `min-*` or `max-*`.
  - A tuned measure that has no token takes `/* stylelint-disable-next-line declaration-property-unit-allowed-list -- <reason> */`, as the existing code does.
- **No full `border: <n>px solid`** outside the glass-rim mixins (`theme/glass-rim-coverage.spec.ts`). A card, frame or popover rim is `glass-rim.filled(...)`. Single-side borders stay flat.
- **Never nest `cdkDropList`s.** Wrapping divs around drop lists are fine.
- Component styles live in sibling `.scss` files.
- **Comments.** Default to no comment, one line where one is needed. Delete restating comments in code you touch.
- **i18n.** Keep the existing keys. Add every new key to both `frontend/public/i18n/en.json` and `frontend/public/i18n/de.json`. German uses the informal "du".
- Section head titles keep linking to their combined lists (#1735) and keep their counts.
- The phone header's tag pill row (`app-reader-header`) is not touched.
- No tints derived from tag or icon colours anywhere. Surfaces use the neutral tokens.
- Touch density is keyed on `@media (pointer: coarse)`, as design-language §3 requires. The drawer geometry is keyed on the shell's `.is-narrow` class.

---

## Mockup inventory (every visual property → token)

"Exact" means the token resolves to the mockup's value in both themes. A † marks a value with no exact token. Each † lists the plan's resolution, and they come back in the open questions at the end.

### Surfaces and colours

| Mockup | Value | Plan |
|---|---|---|
| page and sidebar column (`--s0`) | `#f5f5f4` / `#161616` | `--surface-0` (exact) |
| card, inner child box, popover menu (`--s1`) | `#fff` / `#1c1c1c` | `--surface-1` (exact) |
| open tag group (`--sk`) | `#f2f2f0` / `#171717` | `--surface-sunken` (exact) |
| stepper and segmented frame fill (`--sf2`) | `#fff` / `#242424` | `--surface-2` (exact) |
| row hover, field fill, tool-button hover | `--s0` | `--surface-0` |
| active row (`.row.on`) | `--acs` fill, `--ac` text, glyph and count | `--accent-soft`, `--accent` |
| text (`--tp` / `--ts` / `--tm`) | | `--text-primary` / `--text-secondary` / `--text-muted` |
| danger item (Unsubscribe, Delete tag) | `--dg` | `--danger` |
| Done button, checked switch knob (`--oa`) | | `--on-accent` |
| icon-button hover and open fill, switch off-track (`--btn`) | `#e4e4e2` / `#383836` | † `--border-strong`: exact in dark, one step darker in light (the light `#e4e4e2` is `--border`) |
| phone round-button tint (`::before`) | `color-mix(in srgb, var(--tp) 7%, transparent)` | the same expression over `--text-primary` (a neutral, not a tag colour) |
| drawer shadow | `6px 0 24px rgba(0,0,0,.18)` | new `--drawer-shadow` |
| scrim behind the drawer | `rgba(0,0,0,.32)` / `.55` | new mode token `--drawer-scrim` |
| hidden-feed favicon | opacity `.35` light / `.55` dark, `grayscale(1)` | new mode token `--hidden-favicon-opacity`, plus `filter: grayscale(1)` |
| drag preview shadow | `0 6px 20px rgba(0,0,0,.28)` | † inline `rgb(0 0 0 / 28%)`; the existing preview already has an inline shadow and no token fits |
| drop line in organise | 2px `--ac` | `--space-0` tall, `--accent` |

### Borders and radii

| Mockup | Value | Plan |
|---|---|---|
| card rim | 1px `--bd`, radius 12 | `glass-rim.filled(var(--surface-1), var(--border))`, `--radius-lg`. † A glass rim, not a flat line (house rule, enforced) |
| stepper and segmented frame rim | 1px `--bd3`, radius 8 | `glass-rim.filled(var(--surface-2), var(--border-strong), control)`, `--radius` (same rim note) |
| frame inner dividers, segment dividers | 1px `--bd` | flat `border-inline` / `border-left: 1px solid var(--border)` |
| phone drawer | `border-right: 1px solid --bd`, radius 0 | flat `border-right` |
| popover menu | 1px `--bd`, radius 8, padding 4 | `glass-rim.filled(var(--surface-1), var(--border))`, `--radius`, `--space-1` |
| menu separator, social divider | 1px `--bd` | `border-top: 1px solid var(--border)` (`hr`) / a 1px-high `div` |
| eye badge ring | `box-shadow: 0 0 0 1px --bd` | identical, with `--border` |
| row, group, inner box, tool button, field (right corners) | radius 8 | `--radius` |
| 28px icon buttons, menu items | radius 6 | † `calc(var(--radius) - var(--space-0))` |
| search field (left corners) | radius 11 | `calc(var(--radius-lg) - var(--space-0) / 2)` (exact) |
| pills, phone round buttons, switch | 999 | `--radius-pill` |

### Sizes

| Mockup | Value | Plan |
|---|---|---|
| desktop card column | 316 | new `--sidebar-nav-w` |
| desktop sidebar slot | 332 (316 + 8 + 8) | `calc(var(--sidebar-nav-w) + 2 * var(--space-2))` |
| phone card column | 336 | new `--sidebar-drawer-nav-w` |
| phone sheet | 358 plus the 1px border, padding 12/12/12/10 | `box-sizing: content-box`, width `--sidebar-drawer-nav-w`, padding `--space-3` and `calc(var(--space-2) + var(--space-0))` |
| row heights, desktop | 36 / 32 / 30 | new `--sidebar-row-h` / `--sidebar-sub-row-h` / `--sidebar-leaf-row-h` |
| row and head height, phone | 44 | `--tap-target` |
| section head, desktop | 32 | `--sidebar-sub-row-h` |
| ⋯, chevron and empty slot/trail columns | 28 (44 phone) | new `--sidebar-slot` (`--tap-target` on coarse) |
| search card | 40 | `--control-h` |
| search field and tool buttons inside it | 38 tall, buttons 40 wide | `calc(var(--control-h) - var(--space-0))`, `--control-h` |
| phone header tool buttons | 44 | `--tap-target` |
| stepper and segmented frames | 36 (44 phone) | new `--sidebar-frame-h` (`--tap-target`) |
| stepper end buttons | 36 wide (44 phone) | `--sidebar-frame-h` (`--tap-target`) |
| segment buttons | 32 wide (44 phone) | `--space-6` (`--tap-target`) |
| organise switch track | 40 × 24, knob 18, inset 3 | † tuned measures (disable comment), inset `calc(var(--space-0) * 1.5)` |
| organise switch row | 44 (52 in organise) | `--tap-target` (`calc(var(--tap-target) + var(--space-2))`) |
| eye badge | 13 × 13 at right −5 / bottom −5 | † width 13px (disable comment), `aspect-ratio: 1`, offsets `calc(var(--space-1) * -1.25)` |
| organise drag handle | 40 wide | † tuned 40px (disable comment) |
| organise ⋯ and chevron | 44 | `--tap-target` |
| popover menu min-width | 210 | † tuned 210px (disable comment, as the 140px today) |
| meta row | min-height 28 (32 in organise) | `calc(var(--space-5) + var(--space-1))` (`--space-6`) |

### Spacing

| Mockup | Value | Plan |
|---|---|---|
| drawer gutter, desktop | 8 | `--space-2` |
| panel and scroll gap | 8 (12 phone, 8 in organise) | `--space-2` (`--space-3`) |
| card padding | 6 (8 / 6 phone) | † new `--sidebar-card-pad` = `calc(var(--space-1) + var(--space-0))` (`--space-2` vertical on coarse) |
| card row gap | 2 | `--space-0` |
| row padding | 0 8 0 10 | `--space-2` right; † new `--sidebar-row-inset` = `calc(var(--space-2) + var(--space-0))` |
| row gap | 12 (14 phone) | `--space-3` († `calc(var(--space-3) + var(--space-0))`) |
| `.slot` and `.dots` trick | `margin-right: -12px` | `calc(var(--space-3) * -1)` |
| row inset inside a surface | 42 | † new `--sidebar-row-deep-inset` = `calc(inset + --icon-md + --space-3)` (exact) |
| inner child box | margin 2 6 6 30, padding 2 0 | `--space-0`, `--sidebar-card-pad`, `calc(var(--sidebar-row-inset) + var(--icon-md))` (exact) |
| open tag group | padding-bottom 6, radius 8 | `--sidebar-card-pad`, `--radius` |
| desktop search card | padding 0 4 0 0, gap 2 | `--space-1`, `--space-0` |
| search field | padding 0 10 | `--sidebar-row-inset` |
| phone header | padding 4 0 4 12, gap 2 | `--space-1`, `--space-3`, `--space-0` |
| desktop footer card | padding 8 10 8 8, gap 6 | `--space-2`, `calc(var(--space-2) + var(--space-0))`, `calc(var(--space-1) + var(--space-0))` |
| phone footer card | padding 10, gap 8 | `calc(var(--space-2) + var(--space-0))`, `--space-2` |
| organise footer card | padding 0 4 6 0, gap 2 | `--space-1`, `calc(var(--space-1) + var(--space-0))`, `--space-0` |
| organise switch | gap 10, padding 0 4 0 8 (0 4 0 12 in organise) | `calc(var(--space-2) + var(--space-0))`, `--space-1`, `--space-2` / `--space-3` |
| meta row | gap 8 | `--space-2` |
| update badge | padding 3 10 | `calc(var(--space-0) * 1.5) calc(var(--space-2) + var(--space-0))` |
| menu item | padding 8 12 | `--row-pad-y` / `--row-pad-x` |
| menu separator | margin 4 8 | `--space-1` / `--space-2` |
| social divider | margin 4 12 4 42 | `--space-1`, `--space-3`, `--sidebar-row-deep-inset` |
| organise header | padding 0 4 0 12, gap 8; Done padding 0 18 | `--space-1`, `--space-3`, `--space-2`; `calc(var(--space-4) + var(--space-0))` |
| organise hint | margin 10 12 0 | `calc(var(--space-2) + var(--space-0))`, `--space-3` |
| organise card | padding 4 0; group margin 0 4, padding-bottom 4; inner box margin-bottom 4; parent feeds inset 24 | `--space-1`, `--space-5` |
| organise drop line | margin 1 12 1 64 | `calc(var(--space-0) / 2)`, `--space-3`, `calc(var(--space-7) + var(--space-4))` |
| phone round-button tint | inset 7 | `calc(var(--space-2) - var(--space-0) / 2)` |

### Type and icons

| Mockup | Value | Plan |
|---|---|---|
| top-level row label | 15 | `--fs-base` |
| nested row label (`.sm`), menu item | 14 | † new type step `--fs-dense` (14px) |
| section head label | 13 / 600 / `--ts`, sentence case | `--fs-sm`, 600, `--text-secondary` (the uppercase and letter-spacing are dropped) |
| count | 13, `--ts`, tabular, right | `--fs-sm`, `--text-secondary`, `font-variant-numeric: tabular-nums` |
| bold label (`.b`) | 600 | 600 on the active row and on an open tag |
| phone header and organise title | 18 / 600 | `--fs-lg` |
| field input, Done, organise switch | 15 | `--fs-base` |
| stepper value | 11 (12 phone) | `--fs-xs` († 12 has no step) |
| update badge | 12 / 600 | † `--fs-xs` (11) / 600 |
| meta row, organise hint | 13 (hint line-height 1.5) | `--fs-sm`, `--lh-normal` |
| row lead glyph, tool and stepper-end glyphs | 20 | `--icon-md` (`size="md"`) |
| button glyphs (chevron, ⋯ in rows), child-tag glyph, segment glyphs, network logo in a head | 18 | † new icon step `--icon-dense` (`size="dense"`) |
| favicon | 16, margin 0 2 | `app-favicon` (16), `margin-inline: var(--space-0)` |
| eye glyph | 10 | † `size="text"` (0.85em) in a `font-size: var(--icon-xs)` badge, about 10.2px |
| organise network logo | 16 | `--icon-sm` |

---

## File structure

| File | Responsibility | Tasks |
|---|---|---|
| `frontend/src/app/theme/tokens.scss` | the new sidebar geometry, drawer and hidden-favicon tokens | 1 |
| `frontend/src/app/theme/_type-scale.scss`, `type-scale.spec.ts` | the `dense` type step | 1 |
| `frontend/src/app/shared/icon/icon.component.ts`, `.spec.ts` | the `dense` icon step | 1 |
| `frontend/src/app/reader/shell/reader-shell.component.{html,scss,ts,spec.ts}` | the desktop column on a gutter; the phone full-height sheet; `closeDrawer` wiring | 2, 3 |
| `frontend/src/app/reader/shell/search-field/search-field.component.scss` | the `inset` host variant for the search card | 3 |
| `frontend/src/app/reader/shell/sidebar/_sidebar-row.scss` | the shared anatomy mixins (rewritten) | 4, 5, 8 |
| `frontend/src/app/reader/shell/sidebar/sidebar.component.{html,scss,ts,spec.ts}` | the cards, heads, tag nesting and organise layout | 2–6, 8, 9 |
| `frontend/src/app/reader/shell/sidebar/sidebar-feed-row.component.*` | the feed row as a host row; the hidden-feed badge | 4, 6, 8 |
| `frontend/src/app/reader/shell/sidebar/sidebar-group-row.component.*` | the tag-style group row as a host row | 4 |
| `frontend/src/app/reader/shell/sidebar/sidebar-row-menu.component.*` | the ⋯ menu restyle and its open state | 4 |
| `frontend/src/app/reader/shell/sidebar/sidebar-saved-searches.component.*` | the Saved searches card | 4 |
| `frontend/src/app/reader/shell/sidebar/sidebar-social-section.component.*` | the network cards | 4, 5, 8 |
| `frontend/src/app/reader/shell/sidebar/sidebar-foot.component.*` | the footer card | 7, 8 |
| `frontend/src/app/theme/_segmented.scss`, `text-size-control.*`, `brightness-control.*`, `view-controls/*` | the footer frames | 7 |
| `frontend/src/app/reader/shell/sidebar/sidebar-row-actions.service.{ts,spec.ts}` | the tag sheet with Move up and Move down | 9 |
| `frontend/src/app/theme/glass-rim-coverage.spec.ts` | drops the `.section-chevron app-icon` exemption | 4 |
| `frontend/public/i18n/{en,de}.json` | new keys | 3, 8, 9 |
| `docs/design-language.md` | the sidebar sections | 10 |
| `frontend/e2e/sidebar-mobile.spec.ts`, `sidebar-toggle-desktop.spec.ts`, `sidebar-tag-truncation.spec.ts` | the e2e updates | 11 |

---

### Task 1: Tokens — sidebar geometry, `dense` type and icon steps

**Files:**
- Modify: `frontend/src/app/theme/tokens.scss`
- Modify: `frontend/src/app/theme/_type-scale.scss`
- Modify: `frontend/src/app/shared/icon/icon.component.ts`
- Test: `frontend/src/app/theme/type-scale.spec.ts`, `frontend/src/app/shared/icon/icon.component.spec.ts`

**Interfaces:**
- Produces these CSS custom properties, used by every later task:
  - mode-invariant: `--sidebar-nav-w`, `--sidebar-drawer-nav-w`, `--sidebar-row-h`, `--sidebar-sub-row-h`, `--sidebar-leaf-row-h`, `--sidebar-slot`, `--sidebar-frame-h`, `--sidebar-card-pad`, `--sidebar-row-inset`, `--sidebar-row-deep-inset`, `--drawer-shadow`, `--icon-dense`, `--fs-dense`
  - per mode: `--drawer-scrim`, `--hidden-favicon-opacity`
- Produces `IconSize` that includes `'dense'`, which `app-icon` and `app-tag-glyph` accept.

- [ ] **Step 1: Write the failing tests**

In `frontend/src/app/theme/type-scale.spec.ts`, add the `dense` step to `SIZES`:

```ts
const SIZES = {
  '2xs': '10px',
  xs: '11px',
  sm: '13px',
  dense: '14px',
  base: '15px',
  read: '16px',
  lg: '18px',
  xl: '24px',
};
```

In `frontend/src/app/shared/icon/icon.component.spec.ts`, add a host next to `SizedHost`:

```ts
@Component({
  imports: [IconComponent],
  template: `<app-icon name="expand_more" size="dense" />`,
})
class DenseHost {}
```

Then add this test inside `describe('IconComponent', …)`:

```ts
  it('sizes the dense step from its own token', async () => {
    await TestBed.configureTestingModule({ imports: [DenseHost] }).compileComponents();
    const fixture = TestBed.createComponent(DenseHost);
    fixture.detectChanges();

    const host: HTMLElement = fixture.nativeElement.querySelector('app-icon');
    expect(host.style.fontSize).toBe('var(--icon-dense)');
  });
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `docker compose exec -T frontend npm test -- src/app/theme/type-scale.spec.ts src/app/shared/icon/icon.component.spec.ts`

Expected:
- FAIL: `keeps --fs-dense at its fixed size on the root` (received `undefined`).
- FAIL: the icon spec fails to compile, because `"dense"` is not assignable to `IconSize`.

- [ ] **Step 3: Add the type step**

In `frontend/src/app/theme/_type-scale.scss`, insert `'dense': 14px` into the map:

```scss
$sizes: (
  '2xs': 10px,
  'xs': 11px,
  'sm': 13px,
  'dense': 14px,
  'base': 15px,
  'read': 16px,
  'lg': 18px,
  'xl': 24px,
);
```

- [ ] **Step 4: Add the icon step**

In `frontend/src/app/shared/icon/icon.component.ts`:

```ts
export type IconSize = 'text' | 'xs' | 'sm' | 'dense' | 'md' | 'lg';
```

and in `ICON_SIZE_TOKEN`:

```ts
export const ICON_SIZE_TOKEN: Record<IconSize, string> = {
  text: '0.85em',
  xs: 'var(--icon-xs)',
  sm: 'var(--icon-sm)',
  dense: 'var(--icon-dense)',
  md: 'var(--icon-md)',
  lg: 'var(--icon-lg)',
};
```

- [ ] **Step 5: Add the tokens**

In `frontend/src/app/theme/tokens.scss`, light block (`:root, :root[data-theme='light']`), add these directly after the `--panel-shadow: …;` line:

```scss
  --drawer-scrim: rgb(0 0 0 / 32%);
  --hidden-favicon-opacity: 0.35;
```

In the dark block (`:root[data-theme='dark']`), add them after its `--panel-shadow: …;` line:

```scss
  --drawer-scrim: rgb(0 0 0 / 55%);
  --hidden-favicon-opacity: 0.55;
```

In the mode-invariant `:root` block, add this after `--reader-mini-h: 28px;`:

```scss

  /* The grouped-card sidebar's measures (#1749). On :root because the drag
     preview leaves the sidebar for <body> and still reads them. */
  --sidebar-nav-w: 316px;
  --sidebar-drawer-nav-w: 336px;
  --sidebar-row-h: 36px;
  --sidebar-sub-row-h: 32px;
  --sidebar-leaf-row-h: 30px;
  --sidebar-slot: 28px;
  --sidebar-frame-h: 36px;
  --sidebar-card-pad: calc(var(--space-1) + var(--space-0));
  --sidebar-row-inset: calc(var(--space-2) + var(--space-0));
  --sidebar-row-deep-inset: calc(var(--sidebar-row-inset) + var(--icon-md) + var(--space-3));
  --drawer-shadow: 6px 0 24px rgb(0 0 0 / 18%);
```

Then replace the icon block:

```scss
  /* Icon sizes. Four steps because the same tag glyph is rendered from a
     12px pill up to a 20px sidebar lead; three steps would inflate the pill. */
  --icon-xs: 12px;
  --icon-sm: 16px;
  --icon-md: 20px;
  --icon-lg: 24px;
```

with:

```scss
  /* Icon sizes. The same tag glyph runs from a 12px pill to a 20px sidebar
     lead; `dense` is the glyph of the sidebar's 28px buttons (#1749). */
  --icon-xs: 12px;
  --icon-sm: 16px;
  --icon-dense: 18px;
  --icon-md: 20px;
  --icon-lg: 24px;
```

- [ ] **Step 6: Run the tests to verify they pass**

Run: `docker compose exec -T frontend npm test -- src/app/theme/type-scale.spec.ts src/app/shared/icon/icon.component.spec.ts`

Expected: PASS.

- [ ] **Step 7: Gate**

Run: `docker compose exec -T frontend npx prettier --write src/app/theme/tokens.scss src/app/theme/_type-scale.scss src/app/theme/type-scale.spec.ts src/app/shared/icon/icon.component.ts src/app/shared/icon/icon.component.spec.ts`

Then: `docker compose exec -T frontend npm run check`

Expected: exit 0.

- [ ] **Step 8: Commit**

```bash
git add frontend/src/app/theme/tokens.scss frontend/src/app/theme/_type-scale.scss frontend/src/app/theme/type-scale.spec.ts frontend/src/app/shared/icon/icon.component.ts frontend/src/app/shared/icon/icon.component.spec.ts
git commit -m "feat(#1749): sidebar geometry tokens and the dense type and icon steps"
```

---

### Task 2: Shell geometry — gutter column on the page grey; phone full-height sheet

This is a pure restyle, so it needs no new unit test. Task 11 adds the e2e geometry assertions.

**Files:**
- Modify: `frontend/src/app/reader/shell/reader-shell.component.scss`
- Modify: `frontend/src/app/reader/shell/reader-shell.component.html`
- Modify: `frontend/src/app/reader/shell/reader-shell.component.ts` (stale comments only)
- Modify: `frontend/src/app/reader/shell/reader-shell.component.spec.ts` (one stale comment)
- Modify: `frontend/src/app/reader/shell/sidebar/sidebar.component.scss`

**Interfaces:**
- Consumes: `--sidebar-nav-w`, `--sidebar-drawer-nav-w`, `--drawer-shadow`, `--drawer-scrim` (Task 1).
- Produces:
  - The desktop `aside.sidebar` is 332px wide on `--surface-0` with an 8px gutter and no right border.
  - The phone `aside.sidebar` sits at `top: 0` above the app bar.
  - `nav.sidebar` carries no padding of its own.

- [ ] **Step 1: Drop the drawer's top offset in the template**

In `reader-shell.component.html`, remove `[style.top.px]="headerHeight() || null"` from both the `.backdrop` button and the `aside.sidebar`. They become:

```html
  @if (sidebarOpen()) {
    <button
      class="backdrop"
      type="button"
      aria-label="Close menu"
      (click)="setSidebarOpen(false)"
    ></button>
  }
  <aside class="sidebar" data-drawer-panel [class.open]="sidebarOpen()">
```

- [ ] **Step 2: Restyle the desktop column**

In `reader-shell.component.scss`, replace the whole `.sidebar { … }` rule (the one with `width: 288px`) with:

```scss
.sidebar {
  width: calc(var(--sidebar-nav-w) + 2 * var(--space-2));
  flex: 0 0 auto;
  background: var(--surface-0);

  /* A column, not a scroller — it can't pass under the bar, so it starts below
     it. This offset lives here, not on `.body`, because it's keyed to the
     element that needs it, not to a breakpoint (they diverge 721–899px). */
  padding: calc(var(--app-bar-h, var(--bar-h)) + var(--space-2)) var(--space-2) var(--space-2);
}
```

- [ ] **Step 3: Restyle the phone drawer and its backdrop**

Replace the block that starts with the comment `/* The drawer presentation. …` and runs through the end of `.body.is-narrow .backdrop { … }`. It covers `.body.is-narrow .sidebar`, `.body.is-narrow .sidebar.open` and `.body.is-narrow .backdrop`. Replace it with:

```scss
/* The drawer presentation. Keyed to the `is-narrow` class from
   LayoutService.isNarrow(), not a media query, so the 720px boundary is
   declared once (#185). A full-height sheet over the app bar (#1749). */
.body.is-narrow .sidebar {
  /* Anchored to the shell host (in document flow), not the viewport: a
     `fixed` drawer stays pinned while iOS rubber-bands the page on
     pull-to-refresh, so it drifts against the header and list. */
  position: absolute;
  inset-block: 0;
  left: 0;
  z-index: 20;
  box-sizing: content-box;
  width: var(--sidebar-drawer-nav-w);
  max-width: calc(100% - var(--space-7));
  padding: var(--space-3) var(--space-3) var(--space-3) calc(var(--space-2) + var(--space-0));
  border-right: 1px solid var(--border);
  box-shadow: var(--drawer-shadow);

  /* The extra step parks the shadow off-screen with the sheet. */
  transform: translateX(calc(-100% - var(--space-6)));
  transition: transform 0.2s ease;
}

.body.is-narrow .sidebar.open {
  transform: translateX(0);
}

.body.is-narrow .backdrop {
  display: block;
  position: absolute;
  inset: 0;
  z-index: 15;
  background: var(--drawer-scrim);
}
```

`z-index` 20 and 15 already sit above `app-reader-header` (10) in the host's stacking context, so the sheet and the scrim now cover the bar.

- [ ] **Step 4: Drop the nav's own padding**

In `frontend/src/app/reader/shell/sidebar/sidebar.component.scss`, the `.sidebar` rule becomes:

```scss
.sidebar {
  display: flex;
  flex-direction: column;
  gap: var(--space-0);
  overflow: auto;
  height: 100%;
}
```

- [ ] **Step 5: Fix the comments the change made stale**

In `reader-shell.component.ts` → `ngAfterViewInit`, replace:

```ts
    // This height drives the content area's top padding and the mobile
    // drawer's offset too, not just the header's slide. Until the observer's
    // first callback lands, the stylesheet's own 56px holds -- hence `headerHeight() || null`.
```

with:

```ts
    // This height drives the content area's top padding, not just the header's
    // slide. Until the observer's first callback lands, the stylesheet's own 56px holds.
```

Replace the doc comment above `setSidebarOpen(open: boolean)` with:

```ts
  /**
   * Closing resets Organise. While open, `headerOverlayOpen` holds the bar shown;
   * on close it returns to what the *list's* scroll position implies, asked of
   * the list itself since the last scroller to fire may have been an article's
   * (#128) — a bar left expanded over a scrolled-down list dead-zones touch-scroll
   * in the strip it overlays.
   */
```

In `reader-shell.component.spec.ts`, the test `shows the header again when the mobile drawer opens` starts with this comment:

```ts
      // The drawer hangs below the bar, so opening it under a retracted header
      // would leave a strip of backdrop where the bar belongs.
```

Replace it with:

```ts
      // An open drawer holds the bar shown, whatever the list's scroll.
```

- [ ] **Step 6: Gate**

Run: `docker compose exec -T frontend npx prettier --write src/app/reader/shell/reader-shell.component.html src/app/reader/shell/reader-shell.component.scss src/app/reader/shell/reader-shell.component.ts src/app/reader/shell/reader-shell.component.spec.ts src/app/reader/shell/sidebar/sidebar.component.scss`

Then: `docker compose exec -T frontend npm run check`

Expected: exit 0.

- [ ] **Step 7: Commit**

```bash
git add frontend/src/app/reader/shell/reader-shell.component.html frontend/src/app/reader/shell/reader-shell.component.scss frontend/src/app/reader/shell/reader-shell.component.ts frontend/src/app/reader/shell/reader-shell.component.spec.ts frontend/src/app/reader/shell/sidebar/sidebar.component.scss
git commit -m "feat(#1749): sidebar column on the page grey, phone drawer as a full-height sheet"
```

---

### Task 3: Sidebar head — desktop search card, phone "Feeds" header with close

The labelled Refresh / Add feed `.actions` row goes away.

- On a wide layout, a 40px search card holds the search field and the Refresh, Add feed and Hide sidebar icon buttons.
- On a narrow layout, a header row reads "Feeds" and holds Refresh, Add feed and Close sidebar.
- Close emits a new `closeDrawer` output. The shell wires it to `setSidebarOpen(false)`, the same handler the header's own `closeDrawer` uses.

**Files:**
- Modify: `frontend/src/app/reader/shell/sidebar/sidebar.component.html`, `.ts`, `.scss`
- Modify: `frontend/src/app/reader/shell/search-field/search-field.component.scss`
- Modify: `frontend/src/app/reader/shell/reader-shell.component.html`
- Modify: `frontend/public/i18n/en.json`, `frontend/public/i18n/de.json`
- Test: `frontend/src/app/reader/shell/sidebar/sidebar.component.spec.ts`, `frontend/src/app/reader/shell/reader-shell.component.spec.ts`

**Interfaces:**
- Produces:
  - `SidebarComponent.closeDrawer: OutputEmitterRef<void>`
  - DOM: the classes `.search-card`, `.drawer-head`, `.drawer-title`, `.tool` (with `.collapse` and `.close-drawer`), and `app-search-field.inset`
  - The template ref `#feedTools`
- Consumes: the Task 2 geometry.

- [ ] **Step 1: Write the failing sidebar tests**

In `sidebar.component.spec.ts`, update `emits refresh and addFeed from the action buttons`. Replace the two `.act[aria-label=…]` selectors with `.tool[aria-label="Refresh"]` and `.tool[aria-label="Add feed"]`. In `disables Refresh while refreshing and shows no progress bar of its own`, replace `.act[aria-label="Refresh"]` with `.tool[aria-label="Refresh"]`.

In `organising hides the actions, global views, view controls and trial line`, replace:

```ts
    expect(element.querySelector('.actions')).toBeNull();
```

with:

```ts
    expect(element.querySelector('.tool')).toBeNull();
```

In `navigation mode keeps all of them`, replace:

```ts
    expect(element.querySelector('.actions')).not.toBeNull();
```

with:

```ts
    expect(element.querySelector('.tool[aria-label="Refresh"]')).not.toBeNull();
```

Add a new `describe` right after `describe('search field', …)`:

```ts
  describe('sidebar head', () => {
    it('puts the search field, refresh, add feed and hide sidebar into one card on a wide layout', () => {
      const element = mount({ narrow: false }).nativeElement as HTMLElement;
      const card = element.querySelector('.search-card')!;
      expect(card.querySelector('app-search-field.inset')).not.toBeNull();
      expect([...card.querySelectorAll('.tool')].map((tool) => tool.getAttribute('aria-label'))).toEqual(
        ['Refresh', 'Add feed', 'Hide sidebar'],
      );
      expect(element.querySelector('.drawer-head')).toBeNull();
    });

    it('titles the drawer Feeds and offers refresh, add feed and close on a narrow layout', () => {
      const element = mount({ narrow: true }).nativeElement as HTMLElement;
      const head = element.querySelector('.drawer-head')!;
      expect(head.querySelector('.drawer-title')!.textContent?.trim()).toBe('Feeds');
      expect([...head.querySelectorAll('.tool')].map((tool) => tool.getAttribute('aria-label'))).toEqual(
        ['Refresh', 'Add feed', 'Close sidebar'],
      );
      expect(element.querySelector('.search-card')).toBeNull();
    });

    it('emits closeDrawer from the close button', () => {
      const fixture = mount({ narrow: true });
      const closed = jest.fn();
      fixture.componentInstance.closeDrawer.subscribe(closed);

      (fixture.nativeElement as HTMLElement).querySelector<HTMLButtonElement>('.close-drawer')!.click();

      expect(closed).toHaveBeenCalledTimes(1);
    });
  });
```

- [ ] **Step 2: Write the failing shell test**

In `reader-shell.component.spec.ts`, inside `describe('sidebar organising', …)`, add a helper after the `beforeEach`. The two existing tests in that describe repeat its body, and this task would add a third copy:

```ts
    function bootDrawerShell(): ComponentFixture<ReaderShellComponent> {
      const controller = TestBed.inject(HttpTestingController);
      const fixture = TestBed.createComponent(ReaderShellComponent);
      fixture.detectChanges();
      controller
        .match(() => true)
        .forEach((testRequest) =>
          testRequest.flush({
            subscriptions: [],
            tags: [],
            entries: [],
            savedSearches: [],
            favoritesCount: 0,
            unreadTotals: NO_UNREAD_TOTALS_DTO,
            socialAccounts: [],
            keptCount: 0,
            nextCursor: null,
          }),
        );
      fixture.detectChanges();
      return fixture;
    }
```

Then make three changes in that describe:
- In `pauses the drawer swipe while organising`, replace everything from `const controller = …` through the second `fixture.detectChanges();` with `const fixture = bootDrawerShell();`.
- In `resets organising when the drawer closes`, replace everything from `const controller = …` through the end of the `.forEach(…)` call with `const fixture = bootDrawerShell();`.
- Add this test:

```ts
    it('closes the drawer from the sidebar close button', () => {
      const fixture = bootDrawerShell();
      fixture.componentInstance.setSidebarOpen(true);
      fixture.detectChanges();

      (fixture.nativeElement as HTMLElement)
        .querySelector<HTMLButtonElement>('app-sidebar .close-drawer')!
        .click();
      fixture.detectChanges();

      expect(fixture.componentInstance.sidebarOpen()).toBe(false);
    });
```

- [ ] **Step 3: Run the tests to verify they fail**

Run: `docker compose exec -T frontend npm test -- src/app/reader/shell/sidebar/sidebar.component.spec.ts src/app/reader/shell/reader-shell.component.spec.ts`

Expected:
- FAIL: the three `sidebar head` tests, the two `.tool[aria-label=…]` action tests and `navigation mode keeps all of them`.
- FAIL: `closes the drawer from the sidebar close button` (the close button does not exist).
- The spec file may also fail to compile on `closeDrawer` not existing, which counts as a fail.

- [ ] **Step 4: Add the i18n key**

In `frontend/public/i18n/en.json` → `reader`, add this after `"showSidebar": "Show sidebar",`:

```json
    "closeSidebar": "Close sidebar",
```

In `frontend/public/i18n/de.json` → `reader`, add this after `"showSidebar": "Seitenleiste einblenden",`:

```json
    "closeSidebar": "Seitenleiste schließen",
```

- [ ] **Step 5: Add the output and imports**

In `sidebar.component.ts`:
- Add `import { NgTemplateOutlet } from '@angular/common';`.
- Add `NgTemplateOutlet` to `imports`.
- Remove `IconButtonDirective` from `imports` and delete its `import` line. The collapse button no longer uses it.
- Below `readonly addFeed = output<void>();`, add:

```ts
  readonly closeDrawer = output<void>();
```

- [ ] **Step 6: Replace the head markup**

In `sidebar.component.html`, replace everything from the opening `<nav …>` line down to, but not including, the first `<a` with `class="nav all"`. That covers the `.search-row` block and the `.actions` block. Replace it with:

```html
<nav class="sidebar" [attr.aria-label]="'reader.feeds' | transloco" cdkDropListGroup>
  @if (!organising()) {
    @if (screen.isNarrow()) {
      <div class="drawer-head">
        <span class="drawer-title">{{ 'reader.feeds' | transloco }}</span>
        <ng-container *ngTemplateOutlet="feedTools" />
        <button
          class="tool close-drawer"
          type="button"
          [attr.aria-label]="'reader.closeSidebar' | transloco"
          [attr.title]="'reader.closeSidebar' | transloco"
          (click)="closeDrawer.emit()"
        >
          <app-icon name="close" size="md" />
        </button>
      </div>
    } @else {
      <div class="search-card">
        <app-search-field
          class="inset"
          [term]="selection().term ?? ''"
          [populateTerm]="selection().kind !== 'saved-search'"
          [loading]="searchLoading()"
          (search)="search.emit($event)"
        />
        <ng-container *ngTemplateOutlet="feedTools" />
        <button
          #collapseButton
          class="tool collapse"
          type="button"
          [attr.aria-label]="'reader.hideSidebar' | transloco"
          [attr.title]="'reader.hideSidebar' | transloco"
          (click)="visibility.hide()"
        >
          <app-icon name="left_panel_close" size="md" />
        </button>
      </div>
    }
```

The existing `<a class="nav all" …>` and the other smart-view links follow unchanged, inside the same `@if (!organising())`.

Then, directly before the closing `</nav>`, add:

```html
  <ng-template #feedTools>
    <button
      class="tool"
      type="button"
      [attr.aria-label]="'reader.refresh' | transloco"
      [attr.title]="'reader.refresh' | transloco"
      [disabled]="refreshSvc.running()"
      (click)="refresh.emit()"
    >
      <app-icon name="refresh" size="md" />
    </button>
    <button
      class="tool"
      type="button"
      [attr.aria-label]="'reader.addFeed' | transloco"
      [attr.title]="'reader.addFeed' | transloco"
      (click)="addFeed.emit()"
    >
      <app-icon name="add" size="md" />
    </button>
  </ng-template>
```

- [ ] **Step 7: Style the head**

In `sidebar.component.scss`, delete:
- the `.search-row` comment and rule
- `.search-row app-search-field`
- `.actions`
- `.act`
- the `@media (hover: hover) { .act:hover … }` block
- `.act:disabled`

In the final `@media (pointer: coarse)` block, drop `.act,` from the selector list. Then add the following directly after the `.sidebar` rule:

```scss
.search-card {
  @include glass-rim.filled(var(--surface-1), var(--border));

  display: flex;
  flex: none;
  align-items: flex-start;
  gap: var(--space-0);
  padding-right: var(--space-1);
  border-radius: var(--radius-lg);
}

.search-card app-search-field {
  flex: 1;
  min-width: 0;
}

.drawer-head {
  display: flex;
  flex: none;
  align-items: center;
  gap: var(--space-0);
  padding: var(--space-1) 0 var(--space-1) var(--space-3);
}

.drawer-title {
  flex: 1;
  min-width: 0;
  font-size: var(--fs-lg);
  font-weight: 600;
}

.tool {
  flex: none;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: var(--control-h);
  height: calc(var(--control-h) - var(--space-0));
  padding: 0;
  border: 0;
  border-radius: var(--radius);
  background: none;
  color: var(--text-secondary);
  cursor: pointer;
}

.tool:disabled {
  color: var(--text-muted);
  cursor: default;
}

@media (hover: hover) {
  .tool:hover:not(:disabled) {
    background: var(--surface-0);
  }
}

@media (pointer: coarse) {
  .tool {
    width: var(--tap-target);
    height: var(--tap-target);
  }
}
```

- [ ] **Step 8: Give the search field its inset variant**

Append to `frontend/src/app/reader/shell/search-field/search-field.component.scss`:

```scss
// The sidebar's search card holds the field as an inset well: the card draws the rim.
:host(.inset) .search-field {
  margin-bottom: 0;
}

:host(.inset) .row {
  height: calc(var(--control-h) - var(--space-0));
  padding: 0 var(--sidebar-row-inset);
  border-radius: calc(var(--radius-lg) - var(--space-0) / 2) var(--radius) var(--radius)
    calc(var(--radius-lg) - var(--space-0) / 2);
  background: var(--surface-0);
}

:host(.inset) .input {
  font-size: var(--fs-base);
}

:host(.inset) .hint {
  margin-inline-start: var(--sidebar-row-inset);
}
```

`background` on the rim-filled `.row` deliberately erases its rim (design-language "States set the variables"). The card around the field carries the rim.

- [ ] **Step 9: Wire the output in the shell**

In `reader-shell.component.html`, on `<app-sidebar …>`, add this after `(addFeed)="refresh.addFeed()"`:

```html
      (closeDrawer)="setSidebarOpen(false)"
```

- [ ] **Step 10: Run the tests to verify they pass**

Run: `docker compose exec -T frontend npm test -- src/app/reader/shell/sidebar/sidebar.component.spec.ts src/app/reader/shell/reader-shell.component.spec.ts`

Expected: PASS.

- [ ] **Step 11: Gate**

Run: `docker compose exec -T frontend npx prettier --write src/app/reader/shell/sidebar src/app/reader/shell/search-field/search-field.component.scss src/app/reader/shell/reader-shell.component.html src/app/reader/shell/reader-shell.component.spec.ts public/i18n/en.json public/i18n/de.json`

Then: `docker compose exec -T frontend npm run check`

Expected: exit 0.

- [ ] **Step 12: Commit**

```bash
git add frontend/src/app/reader/shell/sidebar frontend/src/app/reader/shell/search-field/search-field.component.scss frontend/src/app/reader/shell/reader-shell.component.html frontend/src/app/reader/shell/reader-shell.component.spec.ts frontend/public/i18n/en.json frontend/public/i18n/de.json
git commit -m "feat(#1749): search card on desktop, Feeds header with close on the phone"
```

---

### Task 4: Row anatomy and cards

This task rewrites `_sidebar-row.scss` into the shared anatomy and applies it everywhere.

- Every section becomes a `.card`: smart views, Saved searches, Tags, Feeds, each network.
- Feed rows and group rows become host rows.
- Every row ends in the same columns: count, a 28px ⋯ (or an empty `.slot`), then a 28px chevron (or an empty `.trail`). Rows and heads keep an 8px right padding.
- Section heads take the 13px/600 secondary look and stay links.
- The ⋯ menu gets the mockup popover, separators and danger items.

Nesting (Task 5), the hidden-feed badge (Task 6), the footer (Task 7) and organise mode (Task 8) come later. This task is a restyle. Its only behaviour-adjacent addition is the host classes on the group row, which get one test.

**Files:**
- Rewrite: `frontend/src/app/reader/shell/sidebar/_sidebar-row.scss`
- Rewrite: `sidebar.component.html`, `sidebar.component.scss`
- Modify: `sidebar.component.ts` (`feedsSectionShown`)
- Rewrite: `sidebar-feed-row.component.html`, `.scss`. Modify: `.ts` (host class)
- Rewrite: `sidebar-group-row.component.html`, `.scss`. Modify: `.ts` (`glyphSize`, host classes)
- Modify: `sidebar-row-menu.component.html`
- Rewrite: `sidebar-saved-searches.component.html`, `.scss`
- Rewrite: `sidebar-social-section.component.html`, `.scss`. Modify: `.ts` (import `NetworkLogoComponent`)
- Modify: `frontend/src/app/theme/glass-rim-coverage.spec.ts`
- Test: `sidebar.component.spec.ts`, `sidebar-group-row.component.spec.ts`

**Interfaces:**
- Produces, in `_sidebar-row.scss`, the mixins `card`, `row-geometry` (declarations only), `row-states($row, $current)`, `row-link($link)`, `row-parts`, `chevron($button)`, `dots`, `row-menu`, `section-head` and `drag-handle`.
- Produces the context custom properties `--row-h`, `--row-inset`, `--row-fs` and `--dots-color`.
- `SidebarGroupRowComponent`:
  - new input `glyphSize = input<IconSize>('md')`
  - host classes `current` (while `active()`) and `open` (while `expanded()`)
- `SidebarFeedRowComponent`: host class `current` while `active()`.
- `SidebarComponent.feedsSectionShown: Signal<boolean>` (protected).
- Template ref `#rowColumns`.

- [ ] **Step 1: Write the failing group-row test**

Append to `sidebar-group-row.component.spec.ts` inside `describe('SidebarGroupRowComponent', …)`:

```ts
  it('marks its host current while active and open while expanded', () => {
    const fixture = mount();
    const row = root(fixture).querySelector('app-sidebar-group-row') as HTMLElement;
    expect(row.classList).not.toContain('current');
    expect(row.classList).not.toContain('open');

    fixture.componentInstance.active.set(true);
    fixture.componentInstance.expanded.set(true);
    fixture.detectChanges();

    expect(row.classList).toContain('current');
    expect(row.classList).toContain('open');
  });
```

- [ ] **Step 2: Run it to verify it fails**

Run: `docker compose exec -T frontend npm test -- src/app/reader/shell/sidebar/sidebar-group-row.component.spec.ts`

Expected: FAIL, `expect(received).toContain("current")`.

- [ ] **Step 3: Rewrite `_sidebar-row.scss`**

Replace the whole file with:

```scss
@use '../../../theme/glass-rim';

// The grouped-card sidebar's anatomy (#1749), shared by the sidebar and its row components. A row
// takes its height, left inset and type size from the surface it sits on: --row-h, --row-inset, --row-fs.

@mixin card {
  .card {
    @include glass-rim.filled(var(--surface-1), var(--border));

    display: flex;
    flex-direction: column;
    flex: none;
    gap: var(--space-0);
    padding: var(--sidebar-card-pad);
    border-radius: var(--radius-lg);
  }

  @media (pointer: coarse) {
    .card {
      padding: var(--space-2) var(--sidebar-card-pad);
    }
  }
}

@mixin row-geometry {
  display: flex;
  align-items: center;
  gap: var(--space-3);
  height: var(--row-h, var(--sidebar-row-h));
  padding: 0 var(--space-2) 0 var(--row-inset, var(--sidebar-row-inset));
  border-radius: var(--radius);
  color: var(--text-primary);
  font-size: var(--row-fs, var(--fs-base));
  text-decoration: none;

  @media (pointer: coarse) {
    gap: calc(var(--space-3) + var(--space-0));
    height: var(--tap-target);
  }
}

@mixin row-states($row, $current) {
  @media (hover: hover) {
    #{$row}:hover {
      --dots-color: var(--text-primary);

      background: var(--surface-0);
    }
  }

  #{$current} {
    background: var(--accent-soft);
    color: var(--accent);
  }
}

@mixin row-link($link) {
  #{$link} {
    flex: 1;
    align-self: stretch;
    display: flex;
    align-items: center;
    gap: inherit;
    min-width: 0;
    color: inherit;
    text-decoration: none;
  }
}

@mixin row-parts {
  .name {
    flex: 1;
    min-width: 0;
    overflow: hidden;
    white-space: nowrap;
    text-overflow: ellipsis;
  }

  .lead {
    flex: none;
    display: inline-flex;
    align-items: center;
    justify-content: center;
  }

  .count {
    flex: none;
    color: var(--text-secondary);
    font-size: var(--fs-sm);
    font-variant-numeric: tabular-nums;
    text-align: right;
  }

  .active .name {
    font-weight: 600;
  }

  .active .count,
  .active > app-icon {
    color: var(--accent);
  }

  .slot,
  .trail {
    flex: none;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: var(--sidebar-slot);
    height: var(--sidebar-slot);
  }

  // Pulls the chevron column in, so a row without ⋯ keeps its count where a row with ⋯ has it.
  .slot {
    margin-right: calc(var(--space-3) * -1);
  }

  @media (pointer: coarse) {
    .slot {
      display: none;
    }

    .trail {
      width: var(--tap-target);
      height: var(--tap-target);
    }
  }
}

@mixin chevron($button) {
  #{$button} {
    flex: none;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: var(--sidebar-slot);
    height: var(--sidebar-slot);
    padding: 0;
    border: 0;
    border-radius: calc(var(--radius) - var(--space-0));
    background: none;
    color: var(--text-secondary);
    cursor: pointer;
  }

  @media (hover: hover) {
    #{$button}:hover {
      background: var(--border-strong);
    }
  }

  @media (pointer: coarse) {
    #{$button} {
      position: relative;
      width: var(--tap-target);
      height: var(--tap-target);
      border-radius: var(--radius-pill);
    }

    #{$button}::before {
      content: '';
      position: absolute;
      inset: calc(var(--space-2) - var(--space-0) / 2);
      border-radius: var(--radius-pill);
      background: color-mix(in srgb, var(--text-primary) 7%, transparent);
    }

    #{$button} app-icon {
      position: relative;
    }
  }
}

@mixin dots {
  .dots {
    flex: none;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: var(--sidebar-slot);
    height: var(--sidebar-slot);
    padding: 0;
    border: 0;
    border-radius: calc(var(--radius) - var(--space-0));
    background: none;
    color: var(--dots-color, var(--text-muted));
    cursor: pointer;
  }

  .dots.open {
    --dots-color: var(--text-primary);

    background: var(--border-strong);
  }

  @media (hover: hover) {
    .dots:hover {
      --dots-color: var(--text-primary);

      background: var(--border-strong);
    }
  }

  @media (pointer: coarse) {
    .dots {
      width: var(--tap-target);
      height: var(--tap-target);
      border-radius: var(--radius-pill);
    }
  }
}

/* The row menu component's own styles; its items are projected from the row's template, hence `::ng-deep`. */
@mixin row-menu {
  :host {
    position: relative;
    flex: none;
    margin-right: calc(var(--space-3) * -1);
  }

  @include dots;

  .pop {
    @include glass-rim.filled(var(--surface-1), var(--border));

    position: absolute;
    top: calc(100% + var(--space-0));
    right: calc(var(--space-4) - var(--sidebar-slot) - var(--space-2));
    z-index: 3;
    display: flex;
    flex-direction: column;
    width: max-content;
    max-width: 22rem;
    padding: var(--space-1);
    border-radius: var(--radius);

    /* stylelint-disable-next-line declaration-property-unit-allowed-list --
       tuned popover measure, not a spacing value. */
    min-width: 210px;
  }

  .pop ::ng-deep button {
    display: block;
    width: 100%;
    padding: var(--row-pad-y) var(--row-pad-x);
    border: 0;
    border-radius: calc(var(--radius) - var(--space-0));
    background: none;
    color: var(--text-primary);
    font: inherit;
    font-size: var(--fs-dense);
    text-align: left;
    white-space: nowrap;
    cursor: pointer;
  }

  .pop ::ng-deep button.danger {
    color: var(--danger);
  }

  .pop ::ng-deep button:disabled {
    color: var(--text-muted);
    cursor: default;
  }

  .pop ::ng-deep hr {
    margin: var(--space-1) var(--space-2);
    border: 0;
    border-top: 1px solid var(--border);
  }

  @media (hover: hover) {
    .pop ::ng-deep button:hover:not(:disabled) {
      background: var(--surface-0);
    }
  }

  @media (pointer: coarse) {
    .pop ::ng-deep button {
      min-height: var(--tap-target);
    }
  }
}

/* A section head — Saved searches, Tags, Feeds, a network: a title that opens the combined list (#1735). */
@mixin section-head {
  .section-head {
    display: flex;
    flex: none;
    align-items: center;
    gap: var(--space-3);
    height: var(--sidebar-sub-row-h);
    padding: 0 var(--space-2) 0 var(--sidebar-row-inset);
  }

  @media (pointer: coarse) {
    .section-head {
      height: var(--tap-target);
    }
  }

  .section-link {
    flex: 1;
    align-self: stretch;
    display: flex;
    align-items: center;
    gap: var(--space-3);
    min-width: 0;
    margin-left: calc(var(--sidebar-row-inset) * -1);
    padding-left: var(--sidebar-row-inset);
    border-radius: var(--radius);
    color: inherit;
    text-decoration: none;
  }

  @media (hover: hover) {
    .section-link:hover {
      background: var(--surface-0);
    }
  }

  .section-link.active {
    background: var(--accent-soft);
  }

  .section-head .label {
    flex: 1;
    min-width: 0;
    overflow: hidden;
    color: var(--text-secondary);
    font-size: var(--fs-sm);
    font-weight: 600;
    white-space: nowrap;
    text-overflow: ellipsis;
  }

  .section-count {
    flex: none;
    color: var(--text-secondary);
    font-size: var(--fs-sm);
    font-variant-numeric: tabular-nums;
  }

  .section-link.active .label,
  .section-link.active .section-count {
    color: var(--accent);
  }
}

/* Organise-mode drag handle: the ONLY drag origin there (cdkDragHandle), so a
   plain touch on the row body scrolls or expands without competing. */
@mixin drag-handle {
  .handle {
    flex: none;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: var(--tap-target);
    align-self: stretch;
    color: var(--text-muted);
    cursor: grab;
  }
}
```

- [ ] **Step 4: Drop the stale glass-rim exemption**

In `frontend/src/app/theme/glass-rim-coverage.spec.ts`, delete this `EXEMPT` entry. Its rule no longer exists, so its `still needs its exemption` test would fail:

```ts
  {
    file: 'app/reader/shell/sidebar/_sidebar-row.scss',
    selector: '.section-chevron app-icon',
    reason: 'a transparent border that only matches the .chevzone glyph box metrics',
  },
```

- [ ] **Step 5: Rewrite the row menu trigger**

Replace `sidebar-row-menu.component.html` with:

```html
<div [appDismissOnOutside]="open()" (dismiss)="rows.closeMenu()">
  <button
    class="dots"
    type="button"
    aria-haspopup="menu"
    [class.open]="open()"
    [attr.aria-expanded]="open()"
    [attr.aria-label]="label()"
    (click)="rows.toggleMenu(menuKey(), $event)"
  >
    <app-icon name="more_horiz" size="dense" />
  </button>
  @if (open()) {
    <div class="pop" role="menu">
      <ng-container [ngTemplateOutlet]="items()" />
    </div>
  }
</div>
```

The menu component's `.scss` (`@include row.row-menu;`) stays as it is.

- [ ] **Step 6: Make the group row a host row**

Replace `sidebar-group-row.component.html` with:

```html
<a
  class="nav grow"
  [class.active]="active()"
  [routerLink]="['/']"
  [queryParams]="link()"
  queryParamsHandling="merge"
>
  <span class="lead">
    <app-tag-glyph [name]="glyph()" [color]="glyphColor()" [size]="glyphSize()" />
  </span>
  <span class="name">{{ label() }}</span>
  @if (count() > 0) {
    <span class="count">{{ count() }}</span>
  }
</a>
<ng-content />
<button
  class="chevzone"
  type="button"
  [attr.aria-expanded]="expanded()"
  [attr.aria-label]="toggleLabel()"
  (click)="toggled.emit()"
>
  <app-icon [name]="expanded() ? 'expand_more' : 'chevron_right'" size="dense" />
</button>
```

Replace `sidebar-group-row.component.scss` with:

```scss
@use 'sidebar-row' as row;
@include row.row-parts;
@include row.row-link('.nav');
@include row.chevron('.chevzone');
@include row.row-states(':host', ':host(.current)');

:host {
  @include row.row-geometry;
}

:host(.open) .name {
  font-weight: 600;
}
```

In `sidebar-group-row.component.ts`:
- Import `IconSize` with `import { IconComponent, IconSize } from '../../../shared/icon/icon.component';`.
- Add this to the decorator:

```ts
  host: { '[class.current]': 'active()', '[class.open]': 'expanded()' },
```

- Add this input below `glyphColor`:

```ts
  readonly glyphSize = input<IconSize>('md');
```

- [ ] **Step 7: Make the feed row a host row**

Replace `sidebar-feed-row.component.html` with:

```html
@let feed = subscription();
@let name = subscription().title;
@if (organising()) {
  @if (draggable) {
    <span class="handle" cdkDragHandle aria-hidden="true">
      <app-icon name="drag_indicator" size="sm" />
    </span>
  }
  <span class="feedname">
    <app-favicon [url]="feed.faviconUrl" [avatarUrl]="avatarUrl()" />
    <span class="name">{{ name }}</span>
  </span>
  <button
    class="dots"
    type="button"
    aria-haspopup="dialog"
    [attr.aria-label]="'reader.manage' | transloco: { name: name }"
    (click)="rows.openFeedSheet(feed)"
  >
    <app-icon name="more_horiz" size="md" />
  </button>
  <span class="trail" aria-hidden="true"></span>
} @else {
  <a
    class="nav"
    [class.tag-sub]="tagId() !== null"
    [class.active]="active()"
    [routerLink]="['/']"
    [queryParams]="selectionQueryParams({ subscription: feed.id })"
    queryParamsHandling="merge"
  >
    <app-favicon
      [url]="feed.faviconUrl"
      [avatarUrl]="avatarUrl()"
      [class.has-avatar]="avatarUrl() !== null"
    />
    <span class="name">{{ name }}</span>
    @if (markerSurfaces().length > 0) {
      <app-icon
        class="feed-exclusion-marker"
        name="visibility_off"
        size="xs"
        [title]="markerTitle()"
      />
    }
    @if (feed.unreadCount > 0) {
      <span class="count">{{ feed.unreadCount }}</span>
    }
  </a>
  @if (!screen.isCoarse()) {
    <app-sidebar-row-menu
      [menuKey]="menuKey()"
      [label]="'reader.manage' | transloco: { name: name }"
    >
      <ng-template>
        <button role="menuitem" (click)="manage.editSubscription(feed); rows.closeMenu()">
          {{ 'reader.editFeed' | transloco }}
        </button>
        <hr />
        @for (flag of feedFlags; track flag.surface) {
          <button role="menuitem" (click)="rows.toggleFeedFlag(flag, feed)">
            {{ feedFlagLabelKey(flag, feed) | transloco }}
          </button>
        }
        <hr />
        <button
          role="menuitem"
          class="danger"
          (click)="manage.unsubscribe(feed); rows.closeMenu()"
        >
          {{ 'reader.unsubscribe' | transloco }}
        </button>
      </ng-template>
    </app-sidebar-row-menu>
  }
  <span class="trail" aria-hidden="true"></span>
}
```

Replace `sidebar-feed-row.component.scss` with:

```scss
@use 'sidebar-row' as row;
@include row.row-parts;
@include row.row-link('.nav');
@include row.row-states(':host', ':host(.current)');
@include row.drag-handle;
@include row.dots;

:host {
  @include row.row-geometry;
}

.nav app-favicon {
  margin-inline: var(--space-0);
  opacity: 0.4;
}

.nav app-favicon.has-avatar {
  opacity: 1;
}

.feed-exclusion-marker {
  color: var(--text-muted);
}

.feedname {
  flex: 1;
  min-width: 0;
  display: flex;
  align-items: center;
  gap: var(--space-3);
}

:host-context(.cdk-drag-preview) .rowmenu {
  display: none;
}
```

(Task 6 replaces the favicon opacity and the trailing marker.)

In `sidebar-feed-row.component.ts`, add this to the decorator:

```ts
  host: { '[class.current]': 'active()' },
```

- [ ] **Step 8: Rewrite the Saved searches card**

Replace `sidebar-saved-searches.component.html` with:

```html
@if (savedSearches().length) {
  <div class="card">
    <div class="section-head savedsearch-head">
      <!-- savedsearch-toggle carries no CSS; it is a test anchor for specs and e2e. Keep it. -->
      <a
        class="section-link savedsearch-toggle"
        [class.active]="combinedActive()"
        [routerLink]="['/searches/saved/all']"
      >
        <span class="label">{{ 'reader.savedSearches' | transloco }}</span>
        @if (savedSearchesUnread() > 0) {
          <span class="count section-count">{{ savedSearchesUnread() }}</span>
        }
      </a>
      <span class="slot" aria-hidden="true"></span>
      <button
        class="chevzone"
        type="button"
        [attr.aria-expanded]="savedSearchesExpanded()"
        [attr.aria-label]="'reader.toggleSavedSearches' | transloco"
        (click)="toggleSavedSearches()"
      >
        <app-icon [name]="savedSearchesExpanded() ? 'expand_more' : 'chevron_right'" size="dense" />
      </button>
    </div>
    @if (savedSearchesExpanded()) {
      <div class="savedsearch-list">
        @for (saved of visibleSavedSearches(); track saved.id) {
          <a
            class="nav savedsearch-item"
            [class.active]="saved.id === activeSavedSearchId()"
            [routerLink]="['/searches/saved', saved.slug]"
          >
            <app-icon name="search" size="md" />
            <span class="saved-term-group">
              <span class="saved-term">{{ saved.term }}</span>
              @if (saved.wholeWord) {
                <span class="sr-only">{{ 'reader.searchWholeWord' | transloco }}</span>
                <span
                  class="whole-word-badge"
                  aria-hidden="true"
                  [attr.title]="'reader.searchWholeWordHint' | transloco"
                >
                  {{ 'reader.searchWholeWordShort' | transloco }}
                </span>
              }
              @if (saved.phrase) {
                <span class="sr-only">{{ 'reader.searchPhrase' | transloco }}</span>
                <span
                  class="phrase-badge"
                  aria-hidden="true"
                  [attr.title]="'reader.searchPhraseHint' | transloco"
                >
                  {{ 'reader.searchPhraseShort' | transloco }}
                </span>
              }
            </span>
            @if (saved.unreadCount > 0) {
              <span class="count">{{ saved.unreadCount }}</span>
            }
            @if (showDigestToggles()) {
              <button
                class="digest-toggle"
                type="button"
                [attr.aria-pressed]="saved.includeInDigest"
                [attr.aria-label]="'reader.digest.toggleAria' | transloco: { term: saved.term }"
                (click)="
                  toggleDigest.emit(saved); $event.stopPropagation(); $event.preventDefault()
                "
              >
                <app-icon name="mail" size="dense" [class.muted]="!saved.includeInDigest" />
              </button>
            } @else {
              <span class="slot" aria-hidden="true"></span>
            }
            <span class="trail" aria-hidden="true"></span>
          </a>
        }
        @if (savedSearchListExpanded() || hiddenSavedSearchCount() > 0) {
          <button
            class="savedsearch-more"
            type="button"
            [attr.aria-expanded]="savedSearchListExpanded()"
            (click)="toggleSavedSearchList()"
          >
            <app-icon
              [name]="savedSearchListExpanded() ? 'expand_less' : 'expand_more'"
              size="dense"
            />
            <span>{{
              savedSearchListExpanded()
                ? ('reader.showLess' | transloco)
                : ('reader.showMoreSavedSearches' | transloco: { count: hiddenSavedSearchCount() })
            }}</span>
          </button>
        }
      </div>
    }
  </div>
}
```

Replace `sidebar-saved-searches.component.scss` with:

```scss
@use 'sidebar-row' as row;
@include row.card;
@include row.row-parts;
@include row.section-head;
@include row.chevron('.chevzone');

:host {
  display: contents;
}

.savedsearch-list {
  --row-h: var(--sidebar-sub-row-h);
  --row-fs: var(--fs-dense);

  display: flex;
  flex-direction: column;
  gap: var(--space-0);
}

.savedsearch-item {
  @include row.row-geometry;
}

@include row.row-states('.savedsearch-item', '.savedsearch-item.active');

.savedsearch-item > app-icon {
  color: var(--text-muted);
}

/* Term and its W/P pills as one group: the pills stay glued to the word, the count keeps its column (#636). */
.saved-term-group {
  flex: 1;
  min-width: 0;
  display: flex;
  align-items: center;
  gap: var(--space-1);
}

.saved-term {
  min-width: 0;
  overflow: hidden;
  white-space: nowrap;
  text-overflow: ellipsis;
}

.whole-word-badge,
.phrase-badge {
  flex: none;
  padding: 0 var(--space-1);
  font-size: var(--fs-2xs);
}

/* The mail button takes the ⋯ column, so the count lines up with the rows that have none. */
.digest-toggle {
  flex: none;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: var(--sidebar-slot);
  height: var(--sidebar-slot);
  margin-right: calc(var(--space-3) * -1);
  padding: 0;
  border: 0;
  border-radius: calc(var(--radius) - var(--space-0));
  background: none;
  color: var(--text-secondary);
  cursor: pointer;
}

@media (hover: hover) {
  .digest-toggle:hover {
    background: var(--border-strong);
  }
}

@media (pointer: coarse) {
  .digest-toggle {
    width: var(--tap-target);
    height: var(--tap-target);
    margin-right: 0;
  }
}

/* Opacity alone marks inclusion, so a glance down the list reads which searches feed the digest. */
.digest-toggle .muted {
  opacity: 0.35;
}

.savedsearch-more {
  --row-h: var(--sidebar-leaf-row-h);

  @include row.row-geometry;

  width: 100%;
  border: 0;
  background: none;
  color: var(--text-secondary);
  font: inherit;
  font-size: var(--fs-dense);
  text-align: left;
  cursor: pointer;
}

.savedsearch-more app-icon {
  color: var(--text-muted);
}

@media (hover: hover) {
  .savedsearch-more:hover {
    background: var(--surface-0);
    color: var(--text-primary);
  }
}
```

If Stylelint's `declaration-empty-line-before` / `custom-property-empty-line-before` asks for a different order in `.savedsearch-more`, put `@include row.row-geometry;` first, then a blank line, then `--row-h`, then a blank line, then the declarations. The custom property applies to the element either way.

- [ ] **Step 9: Rewrite the network card**

Replace `sidebar-social-section.component.html` with:

```html
@let current = section();
<div class="card">
  <div class="section-head" [attr.data-testid]="'sidebar-social-' + current.network">
    <a
      class="section-link"
      [class.active]="active()"
      [routerLink]="['/']"
      [queryParams]="selectionQueryParams({ network: current.network })"
      queryParamsHandling="merge"
    >
      <app-network-logo [network]="current.network" />
      <span class="label">{{ 'settings.social.' + current.network | transloco }}</span>
      @if (current.unreadCount > 0) {
        <span class="section-count">{{ current.unreadCount }}</span>
      }
    </a>
    @let manageLabel =
      'reader.manageSocialSection'
        | transloco: { network: ('settings.social.' + current.network | transloco) };
    @if (screen.isCoarse()) {
      <button
        class="dots"
        type="button"
        aria-haspopup="dialog"
        [attr.aria-label]="manageLabel"
        (click)="rows.openSocialSheet(current)"
      >
        <app-icon name="more_horiz" size="dense" />
      </button>
    } @else {
      <app-sidebar-row-menu [menuKey]="menuKey()" [label]="manageLabel">
        <ng-template>
          @for (action of rows.socialAccountActions(current); track action.id; let index = $index) {
            <button
              role="menuitem"
              [disabled]="action.disabled"
              (click)="rows.manageSocialFeeds(current.networkAccounts[index])"
            >
              {{ action.label }}
            </button>
          }
          <button role="menuitem" (click)="rows.toggleNetworkInSavedSearches(current)">
            {{ rows.networkSavedSearchesAction(current).label }}
          </button>
        </ng-template>
      </app-sidebar-row-menu>
    }
    <button
      class="section-chevron"
      type="button"
      [attr.aria-expanded]="expanded()"
      [attr.aria-label]="
        'reader.toggleSocialSection'
          | transloco: { network: ('settings.social.' + current.network | transloco) }
      "
      (click)="toggle()"
    >
      <app-icon [name]="expanded() ? 'expand_more' : 'chevron_right'" size="dense" />
    </button>
  </div>
  @if (expanded()) {
    <div class="rows">
      @for (group of current.accounts; track group.accountId) {
        @if (current.showsHandles && group.handle) {
          <p class="account-handle">{{ group.handle }}</p>
        }
        @for (subscription of group.subscriptions; track subscription.id) {
          <div class="feedrow">
            <app-sidebar-feed-row
              [subscription]="subscription"
              [selection]="selection()"
              [organising]="organising()"
            />
          </div>
        }
      }
      @if (current.profiles.subscriptions.length) {
        @if (current.accounts.length) {
          <div class="divider" aria-hidden="true"></div>
        }
        <div class="tag" data-testid="sidebar-social-feeds">
          <app-sidebar-group-row
            [link]="selectionQueryParams({ network: current.network, feedKind: 'profile' })"
            [label]="'reader.socialFeeds' | transloco"
            glyph="group"
            [count]="current.profiles.unreadCount"
            [active]="profilesActive()"
            [expanded]="profilesExpanded()"
            [toggleLabel]="
              'reader.toggleSocialFeeds'
                | transloco: { network: ('settings.social.' + current.network | transloco) }
            "
            (toggled)="toggleProfiles()"
          >
            <span class="slot" aria-hidden="true"></span>
          </app-sidebar-group-row>
        </div>
        @if (profilesExpanded()) {
          <div class="tagfeeds">
            @for (subscription of current.profiles.subscriptions; track subscription.id) {
              <div class="feedrow">
                <app-sidebar-feed-row
                  [subscription]="subscription"
                  [selection]="selection()"
                  [organising]="organising()"
                />
              </div>
            }
          </div>
        }
      }
      @if (current.saved; as saved) {
        <div class="feedrow" data-testid="sidebar-social-saved">
          <app-sidebar-feed-row
            [subscription]="saved"
            [selection]="selection()"
            [organising]="organising()"
          />
        </div>
      }
    </div>
  }
</div>
```

Replace `sidebar-social-section.component.scss` with:

```scss
@use 'sidebar-row' as row;
@include row.card;
@include row.row-parts;
@include row.section-head;
@include row.chevron('.section-chevron');
@include row.dots;

:host {
  display: contents;
}

.section-head app-network-logo {
  width: var(--icon-dense);
  height: var(--icon-dense);
}

.rows {
  --row-h: var(--sidebar-sub-row-h);
  --row-fs: var(--fs-dense);

  display: flex;
  flex-direction: column;
  gap: var(--space-0);
}

.tagfeeds {
  --row-h: var(--sidebar-leaf-row-h);
  --row-inset: var(--sidebar-row-deep-inset);

  display: flex;
  flex-direction: column;
}

.divider {
  flex: none;
  margin: var(--space-1) var(--space-3) var(--space-1) var(--sidebar-row-deep-inset);
  border-top: 1px solid var(--border);
}

.account-handle {
  /* The sidebar is a scrolling flex column, and `overflow: hidden` drops the auto min-height: without this the
     line shrinks to nothing once the sidebar overflows. */
  flex: none;
  margin: var(--space-2) var(--space-2) var(--space-1) var(--sidebar-row-inset);
  overflow: hidden;
  color: var(--text-muted);
  font-size: var(--fs-xs);
  white-space: nowrap;
  text-overflow: ellipsis;
}
```

In `sidebar-social-section.component.ts`, add `import { NetworkLogoComponent } from '../../../shared/network-logo/network-logo.component';` and put `NetworkLogoComponent` in `imports`.

- [ ] **Step 10: Add `feedsSectionShown`**

In `sidebar.component.ts`, add this directly below the `readonly dragging = signal(false);` field and its doc comment:

```ts
  protected readonly feedsSectionShown = computed(
    () => this.untagged().length > 0 || this.dragging() || this.organising(),
  );
```

- [ ] **Step 11: Rewrite the sidebar template**

Replace the whole of `sidebar.component.html` with:

```html
<nav
  class="sidebar"
  [class.organising]="organising()"
  [attr.aria-label]="'reader.feeds' | transloco"
  cdkDropListGroup
>
  @if (!organising()) {
    @if (screen.isNarrow()) {
      <div class="drawer-head">
        <span class="drawer-title">{{ 'reader.feeds' | transloco }}</span>
        <ng-container *ngTemplateOutlet="feedTools" />
        <button
          class="tool close-drawer"
          type="button"
          [attr.aria-label]="'reader.closeSidebar' | transloco"
          [attr.title]="'reader.closeSidebar' | transloco"
          (click)="closeDrawer.emit()"
        >
          <app-icon name="close" size="md" />
        </button>
      </div>
    } @else {
      <div class="search-card">
        <app-search-field
          class="inset"
          [term]="selection().term ?? ''"
          [populateTerm]="selection().kind !== 'saved-search'"
          [loading]="searchLoading()"
          (search)="search.emit($event)"
        />
        <ng-container *ngTemplateOutlet="feedTools" />
        <button
          #collapseButton
          class="tool collapse"
          type="button"
          [attr.aria-label]="'reader.hideSidebar' | transloco"
          [attr.title]="'reader.hideSidebar' | transloco"
          (click)="visibility.hide()"
        >
          <app-icon name="left_panel_close" size="md" />
        </button>
      </div>
    }
    <div class="card">
      <a
        class="nav all"
        [class.active]="selection().kind === 'all'"
        [routerLink]="['/']"
        [queryParams]="selectionQueryParams({})"
        queryParamsHandling="merge"
      >
        <app-icon name="inbox" size="md" />
        <span class="name">{{ 'reader.allItems' | transloco }}</span>
        @if (totalUnread() > 0) {
          <span class="count">{{ totalUnread() }}</span>
        }
        <ng-container *ngTemplateOutlet="rowColumns" />
      </a>
      <a
        class="nav"
        [class.active]="selection().kind === 'favorites'"
        [routerLink]="['/']"
        [queryParams]="selectionQueryParams({ view: 'favorites' })"
        queryParamsHandling="merge"
      >
        <app-icon name="star" size="md" />
        <span class="name">{{ 'reader.favorites' | transloco }}</span>
        @if (favoritesCount() > 0) {
          <span class="count">{{ favoritesCount() }}</span>
        }
        <ng-container *ngTemplateOutlet="rowColumns" />
      </a>
      <a
        class="nav"
        [class.active]="selection().kind === 'kept'"
        [routerLink]="['/']"
        [queryParams]="selectionQueryParams({ view: 'kept' })"
        queryParamsHandling="merge"
      >
        <app-icon name="bookmark" size="md" />
        <span class="name">{{ 'reader.kept' | transloco }}</span>
        @if (keptCount() > 0) {
          <span class="count">{{ keptCount() }}</span>
        }
        <ng-container *ngTemplateOutlet="rowColumns" />
      </a>
      <a
        class="nav"
        [class.active]="selection().kind === 'viewed'"
        [routerLink]="['/']"
        [queryParams]="selectionQueryParams({ view: 'viewed' })"
        queryParamsHandling="merge"
      >
        <app-icon name="history" size="md" />
        <span class="name">{{ 'reader.viewed' | transloco }}</span>
        @if (viewedCount() > 0) {
          <span class="count">{{ viewedCount() }}</span>
        }
        <ng-container *ngTemplateOutlet="rowColumns" />
      </a>
      @if (ai.ready()) {
        <a
          class="nav for-you"
          [class.active]="selection().kind === 'for-you'"
          [routerLink]="['/']"
          [queryParams]="selectionQueryParams({ view: 'for-you' })"
          queryParamsHandling="merge"
        >
          <app-icon name="auto_awesome" size="md" [class.pulse]="recs.running()" />
          <span class="name">{{ 'reader.forYou' | transloco }}</span>
          @if (forYouUnread() > 0) {
            <span class="count">{{ forYouUnread() }}</span>
          }
          <ng-container *ngTemplateOutlet="rowColumns" />
        </a>
      }
    </div>
  }

  <app-sidebar-saved-searches
    [savedSearches]="savedSearches()"
    [activeSavedSearchId]="activeSavedSearchId()"
    [combinedActive]="selection().kind === 'saved-searches'"
    [showDigestToggles]="showDigestToggles()"
    (toggleDigest)="toggleDigest.emit($event)"
  />

  @if (tagTree().length) {
    <div class="card">
      <div class="section-head tags-head">
        <a
          class="section-link"
          [class.active]="activeGroup() === 'tags'"
          [routerLink]="['/']"
          [queryParams]="selectionQueryParams({ group: 'tags' })"
          queryParamsHandling="merge"
        >
          <span class="label">{{ 'reader.tags' | transloco }}</span>
          @if (groupUnread().tags > 0) {
            <span class="section-count">{{ groupUnread().tags }}</span>
          }
        </a>
        <span class="slot" aria-hidden="true"></span>
        <button
          class="section-chevron"
          type="button"
          [attr.aria-expanded]="tagsExpanded()"
          [attr.aria-label]="'reader.toggleTags' | transloco"
          (click)="toggleTags()"
        >
          <app-icon [name]="tagsExpanded() ? 'expand_more' : 'chevron_right'" size="dense" />
        </button>
      </div>
      @if (tagsExpanded()) {
        <!-- Each tag contributes two TOP-LEVEL drop lists, never nested: a header
             list that reorders tags/accepts a feed, and an expanded feed list that
             reorders/receives feeds. CDK breaks cross-list drops if nested. -->
        <div class="tags">
          @for (row of tagRows(); track row.kind + '-' + row.node.tag.id) {
            @let node = row.node;
            @if (row.kind === 'head') {
              <div
                class="taghead"
                cdkDropList
                [cdkDropListData]="tagDrop(node.tag)"
                [cdkDropListEnterPredicate]="acceptOnTagHead"
                [cdkDropListSortingDisabled]="true"
                [class.child]="row.depth === 1"
                [class.drop-line]="dropHover() === 'tag-' + node.tag.id && dragKind() === 'tag'"
                [class.drop-active]="dropHover() === 'tag-' + node.tag.id && dragKind() === 'feed'"
                (cdkDropListDropped)="onTagHeadDrop($event)"
                (cdkDropListEntered)="dropHover.set('tag-' + node.tag.id)"
                (cdkDropListExited)="dropHover.set(null)"
              >
                <div
                  class="tag"
                  cdkDrag
                  [cdkDragData]="node.tag"
                  [cdkDragDisabled]="dragLocked()"
                  [cdkDragStartDelay]="dragDelay()"
                  (cdkDragStarted)="onDragStart('tag')"
                  (cdkDragEnded)="onDragEnd()"
                >
                  @if (organising()) {
                    <span class="handle" cdkDragHandle aria-hidden="true">
                      <app-icon name="drag_indicator" size="sm" />
                    </span>
                    <button
                      class="rowbody"
                      type="button"
                      [attr.aria-expanded]="expanded().has(node.tag.id)"
                      [attr.aria-label]="'reader.toggleTag' | transloco: { name: node.tag.name }"
                      (click)="toggle(node.tag.id)"
                    >
                      <span class="lead">
                        <app-tag-glyph [name]="node.tag.icon" [color]="node.tag.color" size="sm" />
                      </span>
                      <span class="name">{{ node.tag.name }}</span>
                    </button>
                    <button
                      class="dots"
                      type="button"
                      [attr.aria-label]="'reader.manage' | transloco: { name: node.tag.name }"
                      (click)="rows.openTagSheet(node.tag)"
                    >
                      <app-icon name="more_horiz" size="sm" />
                    </button>
                  } @else {
                    <app-sidebar-group-row
                      [link]="selectionQueryParams({ tag: node.tag.id })"
                      [label]="node.tag.name"
                      [glyph]="node.tag.icon"
                      [glyphColor]="node.tag.color"
                      [glyphSize]="row.depth === 1 ? 'dense' : 'md'"
                      [count]="node.unreadCount"
                      [active]="selection().kind === 'tag' && selection().id === node.tag.id"
                      [expanded]="expanded().has(node.tag.id)"
                      [toggleLabel]="'reader.toggleTag' | transloco: { name: node.tag.name }"
                      (toggled)="toggle(node.tag.id)"
                    >
                      @if (!screen.isCoarse()) {
                        <app-sidebar-row-menu
                          [menuKey]="'tag-' + node.tag.id"
                          [label]="'reader.manage' | transloco: { name: node.tag.name }"
                        >
                          <ng-template>
                            <button
                              role="menuitem"
                              (click)="manage.editTag(node.tag); rows.closeMenu()"
                            >
                              {{ 'reader.editTag' | transloco }}
                            </button>
                            <hr />
                            <button
                              role="menuitem"
                              class="danger"
                              (click)="manage.deleteTag(node.tag); rows.closeMenu()"
                            >
                              {{ 'reader.deleteTag' | transloco }}
                            </button>
                          </ng-template>
                        </app-sidebar-row-menu>
                      }
                    </app-sidebar-group-row>
                  }
                </div>
              </div>
            } @else {
              <div
                class="tagfeeds"
                cdkDropList
                [cdkDropListData]="tagDrop(node.tag)"
                [cdkDropListEnterPredicate]="isFeedDrag"
                [class.child]="row.depth === 1"
                (cdkDropListDropped)="onDrop($event)"
              >
                @for (subscription of node.subscriptions; track subscription.id) {
                  <div
                    class="feedrow"
                    cdkDrag
                    [cdkDragData]="subscription"
                    [cdkDragDisabled]="dragLocked()"
                    [cdkDragStartDelay]="dragDelay()"
                    (cdkDragStarted)="onDragStart('feed')"
                    (cdkDragEnded)="onDragEnd()"
                  >
                    <app-sidebar-feed-row
                      [subscription]="subscription"
                      [selection]="selection()"
                      [organising]="organising()"
                      [tagId]="node.tag.id"
                    />
                  </div>
                }
              </div>
            }
          }
        </div>
      }
    </div>
  }

  <div class="feeds-card" [class.card]="feedsSectionShown()">
    @if (feedsSectionShown()) {
      <div class="section-head feeds-head">
        <a
          class="section-link"
          [class.active]="activeGroup() === 'feeds'"
          [routerLink]="['/']"
          [queryParams]="selectionQueryParams({ group: 'feeds' })"
          queryParamsHandling="merge"
        >
          <span class="label">{{ 'reader.feeds' | transloco }}</span>
          @if (groupUnread().feeds > 0) {
            <span class="section-count">{{ groupUnread().feeds }}</span>
          }
        </a>
        <span class="slot" aria-hidden="true"></span>
        <button
          class="section-chevron"
          type="button"
          [attr.aria-expanded]="feedsExpanded()"
          [attr.aria-label]="'reader.toggleFeeds' | transloco"
          (click)="toggleFeeds()"
        >
          <app-icon [name]="feedsExpanded() ? 'expand_more' : 'chevron_right'" size="dense" />
        </button>
      </div>
    }
    <!-- The drop list stays mounted even while collapsed: it is the untag target,
         so unmounting it would strand a feed drag out of a tag. Only its rows
         collapse. A drag or Organise mode force-shows them so the target reads. -->
    <div
      class="feedlist"
      cdkDropList
      [cdkDropListData]="untaggedDrop"
      [cdkDropListEnterPredicate]="isFeedDrag"
      (cdkDropListDropped)="onDrop($event)"
    >
      @if (feedsExpanded() || dragging() || organising()) {
        @for (subscription of untagged(); track subscription.id) {
          <div
            class="feedrow"
            cdkDrag
            [cdkDragData]="subscription"
            [cdkDragDisabled]="dragLocked()"
            [cdkDragStartDelay]="dragDelay()"
            (cdkDragStarted)="onDragStart('feed')"
            (cdkDragEnded)="onDragEnd()"
          >
            <app-sidebar-feed-row
              [subscription]="subscription"
              [selection]="selection()"
              [organising]="organising()"
            />
          </div>
        }
      }
      @if (dragging() && untagged().length === 0) {
        <p class="dropzone-hint">{{ 'reader.dropToRemoveTags' | transloco }}</p>
      }
    </div>
  </div>

  @for (section of socialSections(); track section.network) {
    <app-sidebar-social-section
      [section]="section"
      [selection]="selection()"
      [organising]="organising()"
    />
  }

  <app-sidebar-foot [(organising)]="organising" />

  <ng-template #feedTools>
    <button
      class="tool"
      type="button"
      [attr.aria-label]="'reader.refresh' | transloco"
      [attr.title]="'reader.refresh' | transloco"
      [disabled]="refreshSvc.running()"
      (click)="refresh.emit()"
    >
      <app-icon name="refresh" size="md" />
    </button>
    <button
      class="tool"
      type="button"
      [attr.aria-label]="'reader.addFeed' | transloco"
      [attr.title]="'reader.addFeed' | transloco"
      (click)="addFeed.emit()"
    >
      <app-icon name="add" size="md" />
    </button>
  </ng-template>

  <ng-template #rowColumns>
    <span class="slot" aria-hidden="true"></span>
    <span class="trail" aria-hidden="true"></span>
  </ng-template>
</nav>
```

- [ ] **Step 12: Rewrite the sidebar stylesheet**

Replace the whole of `sidebar.component.scss` with:

```scss
@use '../../../theme/glass-rim';
@use 'sidebar-row' as row;
@include row.card;
@include row.row-parts;
@include row.section-head;
@include row.chevron('.section-chevron');
@include row.dots;
@include row.drag-handle;

.sidebar {
  display: flex;
  flex-direction: column;
  gap: var(--space-2);
  height: 100%;
  overflow: auto;
}

@media (pointer: coarse) {
  .sidebar {
    gap: var(--space-3);
  }
}

.search-card {
  @include glass-rim.filled(var(--surface-1), var(--border));

  display: flex;
  flex: none;
  align-items: flex-start;
  gap: var(--space-0);
  padding-right: var(--space-1);
  border-radius: var(--radius-lg);
}

.search-card app-search-field {
  flex: 1;
  min-width: 0;
}

.drawer-head {
  display: flex;
  flex: none;
  align-items: center;
  gap: var(--space-0);
  padding: var(--space-1) 0 var(--space-1) var(--space-3);
}

.drawer-title {
  flex: 1;
  min-width: 0;
  font-size: var(--fs-lg);
  font-weight: 600;
}

.tool {
  flex: none;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: var(--control-h);
  height: calc(var(--control-h) - var(--space-0));
  padding: 0;
  border: 0;
  border-radius: var(--radius);
  background: none;
  color: var(--text-secondary);
  cursor: pointer;
}

.tool:disabled {
  color: var(--text-muted);
  cursor: default;
}

@media (hover: hover) {
  .tool:hover:not(:disabled) {
    background: var(--surface-0);
  }
}

@media (pointer: coarse) {
  .tool {
    width: var(--tap-target);
    height: var(--tap-target);
  }
}

.nav {
  @include row.row-geometry;
}

@include row.row-states('.nav', '.nav.active');

.nav > app-icon {
  color: var(--text-muted);
}

.nav app-icon.pulse {
  animation: for-you-pulse 1.4s ease-in-out infinite;
}

@keyframes for-you-pulse {
  0%,
  100% {
    opacity: 1;
  }

  50% {
    opacity: 0.35;
  }
}

@media (prefers-reduced-motion: reduce) {
  .nav app-icon.pulse {
    animation: none;
  }
}

.feeds-card:not(.card) {
  display: contents;
}

.tags,
.feedlist,
.tagfeeds {
  display: flex;
  flex-direction: column;
  gap: var(--space-0);
}

.feedlist,
.tagfeeds,
.taghead.child {
  --row-h: var(--sidebar-sub-row-h);
  --row-fs: var(--fs-dense);
}

.tagfeeds,
.taghead.child {
  --row-inset: var(--sidebar-row-deep-inset);
}

.tagfeeds.child {
  --row-h: var(--sidebar-leaf-row-h);
  --row-inset: calc(var(--sidebar-row-deep-inset) + var(--icon-md) + var(--space-3));
}

.organising .tag {
  display: flex;
  align-items: center;
}

/* Organise-mode row body: the expand/collapse target across its whole width. */
.rowbody {
  flex: 1;
  min-width: 0;
  display: flex;
  align-items: center;
  gap: var(--space-2);
  padding: var(--row-pad-y) 0;
  background: none;
  border: none;
  color: var(--text-primary);
  font: inherit;
  text-align: left;
  cursor: pointer;
}

.feedrow.cdk-drag,
.tag.cdk-drag {
  cursor: grab;
}

.taghead,
.feedlist {
  border-radius: var(--radius);
}

.taghead {
  position: relative;
}

/* A tag dragged over a tag header: a signal-coloured insertion line on the
   top edge. A pseudo-element, not an inset shadow, keeps it square against
   the header's rounded corners, sitting in the gap above so nothing shifts. */
.drop-line::before {
  content: '';
  position: absolute;
  left: 0;
  right: 0;
  top: calc(var(--space-0) * -1);
  height: var(--space-0);
  background: var(--accent);
}

/* A feed dragged over a tag header: highlight the whole target container. */
.drop-active {
  outline: 2px solid var(--accent);
  outline-offset: -2px;
  background: var(--accent-soft);
}

.dropzone-hint {
  margin: 0 var(--space-1);
  padding: var(--space-3) var(--space-2);
  text-align: center;
  font-size: var(--fs-sm);
  color: var(--text-muted);
  border: 1px dashed var(--border-strong);
  border-radius: var(--radius);
}

/* Drag visuals — the clone is appended to <body>, but Angular emulated
   styles still match it via its retained content attribute. */
.cdk-drag-preview {
  @include glass-rim.filled(var(--surface-2), var(--border), control);

  border-radius: var(--radius);
  box-shadow: 0 6px 20px rgb(0 0 0 / 35%);
}

.cdk-drag-preview .rowmenu {
  display: none;
}

/* Feed reorder insertion point: a straight signal-coloured line where the
   dragged feed will land (it takes the row's slot). Square edges — a
   rounded pill read as stray corners. */
.feedrow.cdk-drag-placeholder {
  height: var(--space-0);
  min-height: var(--space-0);
  box-sizing: border-box;
  margin: var(--space-0) 0;
  padding: 0;
  background: var(--accent);
  border-radius: 0;
  opacity: 1;
  overflow: hidden;
}

.feedrow.cdk-drag-placeholder > * {
  display: none;
}

/* A dragged tag's placeholder hops between header lists, reading as a second
   stray line — hidden, since `.drop-line` is the only cue. Zero height, not
   `display: none`: that reports a 0,0 rect and flies the tag to the corner. */
.tag.cdk-drag-placeholder {
  height: 0;
  min-height: 0;
  margin: 0;
  padding: 0;
  border: 0;
  overflow: hidden;
}

/* A tag reorder reloads from the server on drop, so skip CDK's settle
   animation for tags — otherwise the row slides across for no reason. */
.tag.cdk-drag-animating {
  transition: none;
}

.cdk-drag-animating {
  transition: transform 0.2s cubic-bezier(0, 0, 0.2, 1);
}

@media (pointer: coarse) {
  .rowbody {
    min-height: var(--tap-target);
  }
}
```

- [ ] **Step 13: Update the menu specs for the separators**

In `sidebar.component.spec.ts`, the four `[role="menuitem"]:nth-child(…)` selectors count the new `<hr>` separators. Switch them to `nth-of-type`, which counts buttons only:
- line ~719: `'.feedrow .pop [role="menuitem"]:nth-child(2)'` → `'.feedrow .pop [role="menuitem"]:nth-of-type(2)'`
- line ~728: `'.feedrow .pop [role="menuitem"]:nth-child(3)'` → `'.feedrow .pop [role="menuitem"]:nth-of-type(3)'`
- line ~771: `'.tag-sub + .rowmenu .pop [role="menuitem"]:nth-child(2)'` → `'.tag-sub + .rowmenu .pop [role="menuitem"]:nth-of-type(2)'`
- line ~780: `'.tag-sub + .rowmenu .pop [role="menuitem"]:nth-child(3)'` → `'.tag-sub + .rowmenu .pop [role="menuitem"]:nth-of-type(3)'`

- [ ] **Step 14: Run the sidebar specs**

Run: `docker compose exec -T frontend npm test -- src/app/reader/shell/sidebar src/app/theme/glass-rim-coverage.spec.ts`

Expected: PASS, including the new group-row test. If an existing test fails on a selector, check it against the class names this task keeps before changing the test:
- `.nav`, `.nav.all`, `.nav.grow`, `.count`, `.lead`, `.tag`, `.taghead`, `.tagfeeds`, `.feedrow`, `.feedlist`, `.tags`
- `.tags-head`, `.feeds-head`, `.section-link`, `.section-count`, `.section-chevron`, `.chevzone`
- `.rowmenu`, `.dots`, `.pop`, `.handle`, `.rowbody`, `.tag-sub`
- `.savedsearch-*`, `.saved-term`, `.digest-toggle`, `.feed-exclusion-marker`, `.account-handle`, the `data-testid`s

A real behaviour regression is a bug to fix, not a test to rewrite.

- [ ] **Step 15: Gate**

Run: `docker compose exec -T frontend npx prettier --write src/app/reader/shell/sidebar src/app/theme/glass-rim-coverage.spec.ts`

Then: `docker compose exec -T frontend npm run check`

Expected: exit 0.

- [ ] **Step 16: Commit**

```bash
git add frontend/src/app/reader/shell/sidebar frontend/src/app/theme/glass-rim-coverage.spec.ts
git commit -m "feat(#1749): grouped cards and one row anatomy across the sidebar"
```

---

### Task 5: Tag nesting — grey group, inner box per child tag

An open root tag becomes a grey group on `--surface-sunken`. In order, it holds:
1. its head
2. one `--surface-1` inner box per child tag, holding the child's head and, while the child is open, its feeds
3. the root's own feeds

Every drop list stays a top-level sibling: only plain divs wrap them. The Bluesky/Mastodon profiles group gets the same grey group.

**Files:**
- Modify: `sidebar.component.ts` (replace `TagRow`/`tagRows` with `TagGroupView`/`tagGroups`)
- Modify: `sidebar.component.html` (tags block plus two templates), `sidebar.component.scss`
- Modify: `_sidebar-row.scss` (`group` mixin)
- Modify: `sidebar-social-section.component.html`, `.scss`
- Test: `sidebar.component.spec.ts`

**Interfaces:**
- Produces:

  ```ts
  export interface TagChildView {
    readonly node: TagNode;
    readonly open: boolean;
  }

  export interface TagGroupView {
    readonly node: TagNode;
    readonly open: boolean;
    readonly children: readonly TagChildView[];
  }
  ```

  and `SidebarComponent.tagGroups: Signal<TagGroupView[]>`.
- Removes: `TagRow`, `tagRows`, `headAndFeeds` and `feedsRow`. No other file uses them; `grep -rn "tagRows\|TagRow" frontend/src` must come back empty after this task.
- Produces the template refs `#tagHead` (context `$implicit: TagNode`, `depth: 0 | 1`, `open: boolean`) and `#tagFeeds` (context `$implicit: TagNode`, `depth: 0 | 1`).
- Produces the mixin `group` (styles `.group.open`) and the DOM classes `.tag-group.group` (`.open` while open) and `.tag-child`.

- [ ] **Step 1: Write the failing tests**

In `sidebar.component.spec.ts`, inside `describe('child tags (#1736)', …)`, replace the test `lists an expanded parent's children before the parent's own feeds` with:

```ts
      it('nests an open root as a group: its head, a box per child, then its own feeds', () => {
        const fixture = mount({
          tagTree: [{ ...parentNode(10, [tagNode(11, [sub(2)], 10)]), subscriptions: [sub(1)] }],
        });
        fixture.componentInstance.toggle(10);
        fixture.componentInstance.toggle(11);
        fixture.detectChanges();

        const group = (fixture.nativeElement as HTMLElement).querySelector('.tags > .tag-group')!;
        expect(group.classList).toContain('open');
        expect([...group.children].map((child) => child.classList[0])).toEqual([
          'taghead',
          'tag-child',
          'tagfeeds',
        ]);
        const box = group.querySelector('.tag-child')!;
        expect([...box.children].map((child) => child.classList[0])).toEqual(['taghead', 'tagfeeds']);
        expect(box.querySelector('.taghead')!.classList).toContain('child');
      });

      it('shows a closed child as a box with its head only', () => {
        const fixture = mount({ tagTree: [parentNode(10, [tagNode(11, [sub(2)], 10)])] });
        fixture.componentInstance.toggle(10);
        fixture.detectChanges();

        const box = (fixture.nativeElement as HTMLElement).querySelector('.tag-child')!;
        expect([...box.children].map((child) => child.classList[0])).toEqual(['taghead']);
      });

      it('leaves a closed root a plain group with only its head', () => {
        const fixture = mount({ tagTree: tree() });

        const group = (fixture.nativeElement as HTMLElement).querySelector('.tags > .tag-group')!;
        expect(group.classList).not.toContain('open');
        expect([...group.children].map((child) => child.classList[0])).toEqual(['taghead']);
      });

      it('keeps every drop list a sibling, never nested in another', () => {
        const fixture = mount({
          tagTree: [{ ...parentNode(10, [tagNode(11, [sub(2)], 10)]), subscriptions: [sub(1)] }],
        });
        fixture.componentInstance.toggle(10);
        fixture.componentInstance.toggle(11);
        fixture.detectChanges();
        const element = fixture.nativeElement as HTMLElement;

        expect(element.querySelectorAll('.tags .cdk-drop-list')).toHaveLength(4);
        expect(element.querySelectorAll('.cdk-drop-list .cdk-drop-list')).toHaveLength(0);
      });
```

Keep the existing `shows children indented, and only while their parent is expanded` test unchanged. It reads `.taghead` in document order and the `.child` class, and both still hold.

- [ ] **Step 2: Run them to verify they fail**

Run: `docker compose exec -T frontend npm test -- src/app/reader/shell/sidebar/sidebar.component.spec.ts -t "child tags"`

Expected: FAIL, because `.tags > .tag-group` matches nothing.

- [ ] **Step 3: Replace the view model**

In `sidebar.component.ts`, delete:
- the `TagRow` interface
- the `tagRows` computed
- the `headAndFeeds` and `feedsRow` methods

Add these interfaces where `TagRow` was:

```ts
export interface TagChildView {
  readonly node: TagNode;
  readonly open: boolean;
}

export interface TagGroupView {
  readonly node: TagNode;
  readonly open: boolean;
  readonly children: readonly TagChildView[];
}
```

Add this where `tagRows` was:

```ts
  readonly tagGroups = computed<TagGroupView[]>(() => {
    const expanded = this.expanded();
    return this.tagTree().map((root) => {
      const open = expanded.has(root.tag.id);
      const children = open
        ? root.children.map((child) => ({ node: child, open: expanded.has(child.tag.id) }))
        : [];
      return { node: root, open, children };
    });
  });
```

- [ ] **Step 4: Rebuild the tags block**

In `sidebar.component.html`, replace the drop-list comment and the whole `<div class="tags"> … </div>` inside `@if (tagsExpanded()) { … }` with:

```html
        <!-- Every drop list stays a TOP-LEVEL sibling, wrapped by plain divs only: a head
             list reorders tags/accepts a feed, a feed list reorders/receives feeds.
             CDK breaks cross-list drops if nested. -->
        <div class="tags">
          @for (group of tagGroups(); track group.node.tag.id) {
            <div class="tag-group group" [class.open]="group.open">
              <ng-container
                *ngTemplateOutlet="
                  tagHead;
                  context: { $implicit: group.node, depth: 0, open: group.open }
                "
              />
              @for (child of group.children; track child.node.tag.id) {
                <div class="tag-child">
                  <ng-container
                    *ngTemplateOutlet="
                      tagHead;
                      context: { $implicit: child.node, depth: 1, open: child.open }
                    "
                  />
                  @if (child.open) {
                    <ng-container
                      *ngTemplateOutlet="tagFeeds; context: { $implicit: child.node, depth: 1 }"
                    />
                  }
                </div>
              }
              @if (group.open) {
                <ng-container
                  *ngTemplateOutlet="tagFeeds; context: { $implicit: group.node, depth: 0 }"
                />
              }
            </div>
          }
        </div>
```

Then, directly before `<ng-template #feedTools>`, add the two templates. They hold the head and feed-list markup that used to sit inline. Declare them inside `<nav>`: their drop lists must find `cdkDropListGroup` through the declaration's injector.

```html
  <ng-template #tagHead let-node let-depth="depth" let-open="open">
    <div
      class="taghead"
      cdkDropList
      [cdkDropListData]="tagDrop(node.tag)"
      [cdkDropListEnterPredicate]="acceptOnTagHead"
      [cdkDropListSortingDisabled]="true"
      [class.child]="depth === 1"
      [class.drop-line]="dropHover() === 'tag-' + node.tag.id && dragKind() === 'tag'"
      [class.drop-active]="dropHover() === 'tag-' + node.tag.id && dragKind() === 'feed'"
      (cdkDropListDropped)="onTagHeadDrop($event)"
      (cdkDropListEntered)="dropHover.set('tag-' + node.tag.id)"
      (cdkDropListExited)="dropHover.set(null)"
    >
      <div
        class="tag"
        cdkDrag
        [cdkDragData]="node.tag"
        [cdkDragDisabled]="dragLocked()"
        [cdkDragStartDelay]="dragDelay()"
        (cdkDragStarted)="onDragStart('tag')"
        (cdkDragEnded)="onDragEnd()"
      >
        @if (organising()) {
          <span class="handle" cdkDragHandle aria-hidden="true">
            <app-icon name="drag_indicator" size="sm" />
          </span>
          <button
            class="rowbody"
            type="button"
            [attr.aria-expanded]="open"
            [attr.aria-label]="'reader.toggleTag' | transloco: { name: node.tag.name }"
            (click)="toggle(node.tag.id)"
          >
            <span class="lead">
              <app-tag-glyph [name]="node.tag.icon" [color]="node.tag.color" size="sm" />
            </span>
            <span class="name">{{ node.tag.name }}</span>
          </button>
          <button
            class="dots"
            type="button"
            [attr.aria-label]="'reader.manage' | transloco: { name: node.tag.name }"
            (click)="rows.openTagSheet(node.tag)"
          >
            <app-icon name="more_horiz" size="sm" />
          </button>
        } @else {
          <app-sidebar-group-row
            [link]="selectionQueryParams({ tag: node.tag.id })"
            [label]="node.tag.name"
            [glyph]="node.tag.icon"
            [glyphColor]="node.tag.color"
            [glyphSize]="depth === 1 ? 'dense' : 'md'"
            [count]="node.unreadCount"
            [active]="selection().kind === 'tag' && selection().id === node.tag.id"
            [expanded]="open"
            [toggleLabel]="'reader.toggleTag' | transloco: { name: node.tag.name }"
            (toggled)="toggle(node.tag.id)"
          >
            @if (!screen.isCoarse()) {
              <app-sidebar-row-menu
                [menuKey]="'tag-' + node.tag.id"
                [label]="'reader.manage' | transloco: { name: node.tag.name }"
              >
                <ng-template>
                  <button role="menuitem" (click)="manage.editTag(node.tag); rows.closeMenu()">
                    {{ 'reader.editTag' | transloco }}
                  </button>
                  <hr />
                  <button
                    role="menuitem"
                    class="danger"
                    (click)="manage.deleteTag(node.tag); rows.closeMenu()"
                  >
                    {{ 'reader.deleteTag' | transloco }}
                  </button>
                </ng-template>
              </app-sidebar-row-menu>
            }
          </app-sidebar-group-row>
        }
      </div>
    </div>
  </ng-template>

  <ng-template #tagFeeds let-node let-depth="depth">
    <div
      class="tagfeeds"
      cdkDropList
      [cdkDropListData]="tagDrop(node.tag)"
      [cdkDropListEnterPredicate]="isFeedDrag"
      [class.child]="depth === 1"
      (cdkDropListDropped)="onDrop($event)"
    >
      @for (subscription of node.subscriptions; track subscription.id) {
        <div
          class="feedrow"
          cdkDrag
          [cdkDragData]="subscription"
          [cdkDragDisabled]="dragLocked()"
          [cdkDragStartDelay]="dragDelay()"
          (cdkDragStarted)="onDragStart('feed')"
          (cdkDragEnded)="onDragEnd()"
        >
          <app-sidebar-feed-row
            [subscription]="subscription"
            [selection]="selection()"
            [organising]="organising()"
            [tagId]="node.tag.id"
          />
        </div>
      }
    </div>
  </ng-template>
```

- [ ] **Step 5: Add the `group` mixin**

Append to `_sidebar-row.scss`:

```scss
/* An open group — a tag, or a network's people — on the recessed surface; its rows step in with it. */
@mixin group {
  .group.open {
    display: flex;
    flex-direction: column;
    padding-bottom: var(--sidebar-card-pad);
    border-radius: var(--radius);
    background: var(--surface-sunken);
  }
}
```

- [ ] **Step 6: Style the nesting**

In `sidebar.component.scss`:
- Add `@include row.group;` after `@include row.drag-handle;`.
- Replace the three context rules from Task 4 (`.feedlist, .tagfeeds, .taghead.child {…}`, `.tagfeeds, .taghead.child {…}` and `.tagfeeds.child {…}`) with:

```scss
.feedlist {
  --row-h: var(--sidebar-sub-row-h);
  --row-fs: var(--fs-dense);
}

.tag-group > .tagfeeds {
  --row-h: var(--sidebar-sub-row-h);
  --row-fs: var(--fs-dense);
  --row-inset: var(--sidebar-row-deep-inset);

  gap: 0;
}

/* The child's own surface: its rows start at the box's edge again, so each chevron keeps its gap to it. */
.tag-child {
  --row-h: var(--sidebar-sub-row-h);
  --row-fs: var(--fs-dense);
  --row-inset: var(--sidebar-row-inset);

  display: flex;
  flex-direction: column;
  gap: var(--space-0);
  margin: var(--space-0) var(--sidebar-card-pad) var(--sidebar-card-pad)
    calc(var(--sidebar-row-inset) + var(--icon-md));
  padding: var(--space-0) 0;
  border-radius: var(--radius);
  background: var(--surface-1);
}

.tag-child .tagfeeds {
  --row-h: var(--sidebar-leaf-row-h);
  --row-inset: var(--sidebar-row-deep-inset);
}
```

The inner box deliberately has no `overflow: hidden`: it would clip the ⋯ popovers of the rows inside it.

- [ ] **Step 7: Give the network's people the same group**

In `sidebar-social-section.component.html`, wrap the profiles group row and its feed list in a group div. Replace:

```html
        <div class="tag" data-testid="sidebar-social-feeds">
```

through the closing `}` of `@if (profilesExpanded()) { … }` with:

```html
        <div class="group" [class.open]="profilesExpanded()">
          <div class="tag" data-testid="sidebar-social-feeds">
            <app-sidebar-group-row
              [link]="selectionQueryParams({ network: current.network, feedKind: 'profile' })"
              [label]="'reader.socialFeeds' | transloco"
              glyph="group"
              [count]="current.profiles.unreadCount"
              [active]="profilesActive()"
              [expanded]="profilesExpanded()"
              [toggleLabel]="
                'reader.toggleSocialFeeds'
                  | transloco: { network: ('settings.social.' + current.network | transloco) }
              "
              (toggled)="toggleProfiles()"
            >
              <span class="slot" aria-hidden="true"></span>
            </app-sidebar-group-row>
          </div>
          @if (profilesExpanded()) {
            <div class="tagfeeds">
              @for (subscription of current.profiles.subscriptions; track subscription.id) {
                <div class="feedrow">
                  <app-sidebar-feed-row
                    [subscription]="subscription"
                    [selection]="selection()"
                    [organising]="organising()"
                  />
                </div>
              }
            </div>
          }
        </div>
```

In `sidebar-social-section.component.scss`, add `@include row.group;` after `@include row.dots;`. Then replace the `.tagfeeds` rule with:

```scss
.tagfeeds {
  --row-inset: var(--sidebar-row-deep-inset);

  display: flex;
  flex-direction: column;
}
```

Leaf height is only for the rows inside a child box. The people under an open "Feeds" row are the group's own rows, so they keep the sub-row height, like a root tag's own feeds.

- [ ] **Step 8: Run the tests to verify they pass**

Run: `docker compose exec -T frontend npm test -- src/app/reader/shell/sidebar`

Expected: PASS, including `sidebar-social-section.component.spec.ts`. Its `.tagfeeds app-sidebar-feed-row` query still matches.

- [ ] **Step 9: Gate**

Run: `docker compose exec -T frontend npx prettier --write src/app/reader/shell/sidebar`

Then: `docker compose exec -T frontend npm run check`

Expected: exit 0.

- [ ] **Step 10: Commit**

```bash
git add frontend/src/app/reader/shell/sidebar
git commit -m "feat(#1749): open tag as a grey group with an inner box per child tag"
```

---

### Task 6: Hidden-feed favicon with an eye badge

A feed hidden from any surface no longer shows the trailing `visibility_off` marker. Its favicon is greyed and faded, and a 13px eye badge sits on the favicon's bottom-right corner. The count keeps its column. Every other favicon renders at full strength: the old global 0.4 opacity goes.

**Files:**
- Modify: `sidebar-feed-row.component.html`, `.scss`, `.ts`
- Test: `sidebar.component.spec.ts`

**Interfaces:**
- Consumes: `--hidden-favicon-opacity` (Task 1).
- Produces:
  - DOM: `.favicon-slot` (gets `.hidden-feed` when hidden) wraps `app-favicon`, plus `span.feed-exclusion-marker[role=img]` with `aria-label` and `title`, holding `app-icon[name=visibility_off][size=text]`.
  - `SidebarFeedRowComponent.excluded: Signal<boolean>` (protected).

- [ ] **Step 1: Write the failing tests**

In `sidebar.component.spec.ts`, replace `renders the exclusion marker without displacing the unread count` with:

```ts
  it('greys a hidden feed’s favicon and badges it with the eye, keeping the count', () => {
    const fixture = mount({ untagged: [{ ...sub(2, 4), includeInForYou: false }] });
    const row = (fixture.nativeElement as HTMLElement).querySelector('.feedrow')!;
    const slot = row.querySelector('.favicon-slot')!;
    const marker = slot.querySelector('.feed-exclusion-marker')!;

    expect(slot.classList).toContain('hidden-feed');
    expect(slot.querySelector('app-favicon')).not.toBeNull();
    expect(marker.textContent).toContain('visibility_off');
    expect(marker.getAttribute('role')).toBe('img');
    expect(marker.getAttribute('aria-label')).toBe(marker.getAttribute('title'));
    expect(row.querySelector('.count')?.textContent).toContain('4');
  });

  it('leaves a visible feed’s favicon unmarked', () => {
    const fixture = mount({ untagged: [sub(2, 4)] });
    const slot = (fixture.nativeElement as HTMLElement).querySelector('.feedrow .favicon-slot')!;

    expect(slot.classList).not.toContain('hidden-feed');
    expect(slot.querySelector('.feed-exclusion-marker')).toBeNull();
  });
```

In `describe('organise mode', …)`, add:

```ts
  it('badges a hidden feed while organising too', () => {
    const element = mount({
      coarse: true,
      organising: true,
      untagged: [{ ...sub(9), includeInAllItems: false }],
    }).nativeElement as HTMLElement;

    expect(element.querySelector('.feedrow .favicon-slot.hidden-feed .feed-exclusion-marker')).not.toBeNull();
  });
```

The other three marker tests stay. They query `.feed-exclusion-marker` and its `title`, and both still hold.

- [ ] **Step 2: Run them to verify they fail**

Run: `docker compose exec -T frontend npm test -- src/app/reader/shell/sidebar/sidebar.component.spec.ts -t "favicon|badges"`

Expected: FAIL, because `.favicon-slot` does not exist.

- [ ] **Step 3: Add `excluded` to the feed row**

In `sidebar-feed-row.component.ts`, add this below `markerTitle`:

```ts
  protected readonly excluded = computed(() => this.markerSurfaces().length > 0);
```

- [ ] **Step 4: Render the slot in both branches**

In `sidebar-feed-row.component.html`:
- In the organise branch, replace `<app-favicon [url]="feed.faviconUrl" [avatarUrl]="avatarUrl()" />` with `<ng-container *ngTemplateOutlet="favicon" />`.
- In the nav branch, replace the `<app-favicon … [class.has-avatar]=…/>` element and the whole `@if (markerSurfaces().length > 0) { … }` block with `<ng-container *ngTemplateOutlet="favicon" />`.
- Append this at the end of the file:

```html
<ng-template #favicon>
  <span class="favicon-slot" [class.hidden-feed]="excluded()">
    <app-favicon [url]="subscription().faviconUrl" [avatarUrl]="avatarUrl()" />
    @if (excluded()) {
      <span
        class="feed-exclusion-marker"
        role="img"
        [attr.aria-label]="markerTitle()"
        [title]="markerTitle()"
      >
        <app-icon name="visibility_off" size="text" />
      </span>
    }
  </span>
</ng-template>
```

In `sidebar-feed-row.component.ts`, add `import { NgTemplateOutlet } from '@angular/common';` and put `NgTemplateOutlet` in `imports`.

- [ ] **Step 5: Style it**

In `sidebar-feed-row.component.scss`, delete `.nav app-favicon { … }`, `.nav app-favicon.has-avatar { … }` and `.feed-exclusion-marker { … }`. Add:

```scss
.favicon-slot {
  position: relative;
  flex: none;
  display: inline-flex;
  margin-inline: var(--space-0);
}

.favicon-slot app-favicon {
  margin-inline-end: 0;
}

.hidden-feed > app-favicon {
  opacity: var(--hidden-favicon-opacity);
  filter: grayscale(1);
}

.feed-exclusion-marker {
  position: absolute;
  right: calc(var(--space-1) * -1.25);
  bottom: calc(var(--space-1) * -1.25);
  display: inline-flex;
  align-items: center;
  justify-content: center;

  /* stylelint-disable-next-line declaration-property-unit-allowed-list --
     tuned badge measure, not a spacing value. */
  width: 13px;
  aspect-ratio: 1;
  border-radius: var(--radius-pill);
  background: var(--surface-1);
  box-shadow: 0 0 0 1px var(--border);
  color: var(--text-primary);
  font-size: var(--icon-xs);
}
```

- [ ] **Step 6: Run the tests to verify they pass**

Run: `docker compose exec -T frontend npm test -- src/app/reader/shell/sidebar`

Expected: PASS. `sidebar-social-section.component.spec.ts` queries `.feed-exclusion-marker` four times, and those assertions still hold.

- [ ] **Step 7: Gate**

Run: `docker compose exec -T frontend npx prettier --write src/app/reader/shell/sidebar`

Then: `docker compose exec -T frontend npm run check`

Expected: exit 0.

- [ ] **Step 8: Commit**

```bash
git add frontend/src/app/reader/shell/sidebar
git commit -m "feat(#1749): hidden feeds show a greyed favicon with an eye badge"
```

---

### Task 7: Footer card

The foot becomes a card that rides at the end of the scroll column (`margin-top: auto`), so it scrolls with the list. It holds:
- the text-size and brightness steppers, each a full-width 36px frame
- the layout and theme segments on one row, pushed apart
- the trial line
- the meta row: the update badge first, then version · Feedback

The Organise switch is a label plus a 40×24 switch, with no icon and no rim.

**Files:**
- Modify: `frontend/src/app/theme/_segmented.scss`
- Modify: `text-size-control.component.html`, `.scss`
- Modify: `brightness-control.component.html`, `.scss`
- Modify: `frontend/src/app/reader/shell/view-controls/view-controls.component.html`, `.scss`
- Rewrite: `sidebar-foot.component.html`, `.scss`
- Test: `sidebar-foot.component.spec.ts`

**Interfaces:**
- Consumes: `--sidebar-frame-h` and `--icon-dense` (Task 1).
- Produces:
  - The meta row's children, in order: `.update-badge`, `.version`, `.separator`, `.feedback`.
  - The stepper end buttons carry `class="end …"`.
  - The organise switch label is `.organise-label`.

- [ ] **Step 1: Write the failing test**

In `sidebar-foot.component.spec.ts`, replace `keeps the update badge out of the version and feedback row` with:

```ts
  it('leads the version and feedback row with the update badge', () => {
    const fixture = mount();
    const versions = TestBed.inject(VersionService);
    versions.latest.set({ version: 'v9.9.9', notesUrl: 'https://github.test/releases/tag/v9.9.9' });
    versions.updateAvailable.set(true);
    fixture.detectChanges();

    const host = fixture.nativeElement as HTMLElement;
    const meta = host.querySelector('.meta');
    expect(host.querySelector('.update-badge')?.parentElement).toBe(meta);
    expect(Array.from(meta?.children ?? []).map((child) => child.className)).toEqual([
      'update-badge',
      'version',
      'separator',
      'feedback',
    ]);
  });
```

- [ ] **Step 2: Run it to verify it fails**

Run: `docker compose exec -T frontend npm test -- src/app/reader/shell/sidebar/sidebar-foot.component.spec.ts`

Expected: FAIL, because the badge's parent is the host, not `.meta`.

- [ ] **Step 3: Rewrite the foot template**

Replace `sidebar-foot.component.html` with:

```html
@if (screen.isCoarse()) {
  <button
    class="organise"
    type="button"
    role="switch"
    [attr.aria-checked]="organising()"
    (click)="organising.set(!organising())"
  >
    <span class="organise-label">{{ 'reader.organise' | transloco }}</span>
    <span class="track" aria-hidden="true"><span class="knob"></span></span>
  </button>
}
@if (!organising()) {
  <app-text-size-control class="text-size" />
  <app-brightness-control class="brightness" />
  <app-view-controls class="controls" />
  @if (trialDaysLeft(); as days) {
    <p class="trial" [class.soon]="trialEndingSoon()">
      <app-icon name="schedule" size="sm" />
      <span>{{
        (days === 1 ? 'reader.trialDayLeft' : 'reader.trialDaysLeft') | transloco: { days }
      }}</span>
    </p>
  }
}
<div class="meta">
  @if (availableUpdate(); as update) {
    <a
      class="update-badge"
      [href]="update.notesUrl"
      target="_blank"
      rel="noopener noreferrer"
      [attr.aria-label]="'reader.updateAvailableLabel' | transloco: { version: update.version }"
      >{{ 'reader.updateAvailable' | transloco: { version: update.version } }}</a
    >
  }
  <a
    class="version"
    routerLink="/settings"
    [attr.aria-label]="'reader.versionLabel' | transloco: { version }"
    >{{ version }}</a
  >
  <span class="separator" aria-hidden="true">·</span>
  <a
    class="feedback"
    href="https://github.com/larspohlmann/simple-feed-reader/issues"
    target="_blank"
    rel="noopener noreferrer"
    >Feedback</a
  >
</div>
```

- [ ] **Step 4: Rewrite the foot stylesheet**

Replace `sidebar-foot.component.scss` with:

```scss
@use '../../../theme/glass-rim';

:host {
  @include glass-rim.filled(var(--surface-1), var(--border));

  display: flex;
  flex: none;
  flex-direction: column;
  gap: calc(var(--space-1) + var(--space-0));
  margin-top: auto;
  padding: var(--space-2) calc(var(--space-2) + var(--space-0)) var(--space-2) var(--space-2);
  border-radius: var(--radius-lg);
}

@media (pointer: coarse) {
  :host {
    gap: var(--space-2);
    padding: calc(var(--space-2) + var(--space-0));
  }
}

.organise {
  display: flex;
  align-items: center;
  gap: calc(var(--space-2) + var(--space-0));
  height: var(--tap-target);
  padding: 0 var(--space-1) 0 var(--space-2);
  border: 0;
  background: none;
  color: var(--text-primary);
  font: inherit;
  font-size: var(--fs-base);
  cursor: pointer;
}

.organise-label {
  flex: 1;
  text-align: left;
}

/* stylelint-disable declaration-property-unit-allowed-list --
   tuned switch measures, not spacing values. */
.organise .track {
  position: relative;
  flex: none;
  width: 40px;
  height: 24px;
  border-radius: var(--radius-pill);
  background: var(--border-strong);
  transition: background 0.15s;
}

.organise .knob {
  position: absolute;
  top: calc(var(--space-0) * 1.5);
  left: calc(var(--space-0) * 1.5);
  width: 18px;
  height: 18px;
  border-radius: var(--radius-pill);
  background: var(--surface-1);
  transition: transform 0.15s;
}
/* stylelint-enable declaration-property-unit-allowed-list */

.organise[aria-checked='true'] .track {
  background: var(--accent);
}

/* Travel: the 40px track less the 18px knob and both 3px insets. */
.organise[aria-checked='true'] .knob {
  background: var(--on-accent);
  transform: translateX(var(--space-4));
}

@media (prefers-reduced-motion: reduce) {
  .organise .track,
  .organise .knob {
    transition: none;
  }
}

.trial {
  display: flex;
  align-items: center;
  gap: var(--space-1);
  margin: 0;
  padding: 0 var(--space-1);
  color: var(--text-muted);
  font-size: var(--fs-sm);
}

.trial.soon {
  color: var(--danger);
}

.meta {
  display: flex;
  align-items: center;
  gap: var(--space-2);
  min-height: calc(var(--space-5) + var(--space-1));
  color: var(--text-secondary);
  font-size: var(--fs-sm);
}

.update-badge {
  padding: calc(var(--space-0) * 1.5) calc(var(--space-2) + var(--space-0));
  border-radius: var(--radius-pill);
  background: var(--accent-soft);
  color: var(--accent);
  font-size: var(--fs-xs);
  font-weight: 600;
  text-decoration: none;
  white-space: nowrap;
}

.version {
  margin-inline-start: auto;
  color: var(--text-secondary);
  text-decoration: none;
  white-space: nowrap;
}

.feedback {
  color: var(--accent);
  text-decoration: none;
  white-space: nowrap;
}

@media (hover: hover) {
  .update-badge:hover {
    background: var(--accent);
    color: var(--on-accent);
  }

  .version:hover {
    color: var(--text-primary);
  }
}
```

If Stylelint rejects the block-level `stylelint-disable`/`stylelint-enable` pair, put one `/* stylelint-disable-next-line declaration-property-unit-allowed-list -- tuned switch measure, not a spacing value. */` line above each of the four px declarations instead.

- [ ] **Step 5: Restyle the segmented frames**

Replace `frontend/src/app/theme/_segmented.scss` with:

```scss
@use 'glass-rim';

// The sidebar foot's frames (#1749): the view-controls groups and the two steppers.
@mixin frame {
  .seg {
    @include glass-rim.filled(var(--surface-2), var(--border-strong), control);

    display: inline-flex;
    flex: 1 1 auto;
    height: var(--sidebar-frame-h);
    border-radius: var(--radius);
    overflow: hidden;
  }

  .seg button {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    padding: 0;
    border: none;
    background: var(--surface-2);
    color: var(--text-secondary);
    cursor: pointer;
  }

  @media (hover: hover) {
    .seg button:hover:not(:disabled) {
      background: var(--surface-0);
    }
  }

  @media (pointer: coarse) {
    .seg {
      height: var(--tap-target);
    }
  }
}

// The steppers (brightness, text size): glyph buttons either side of a
// full-height accent fill that resets on click.
@mixin stepper-bar {
  .seg {
    align-items: stretch;
  }

  .seg button:disabled {
    color: var(--text-muted);
    cursor: default;
  }

  .seg .end {
    flex: none;
    width: var(--sidebar-frame-h);
  }

  .seg .bar {
    flex: 1 1 auto;
    border-inline: 1px solid var(--border);
  }

  .track {
    display: block;
    block-size: 100%;
    inline-size: 100%;
    background: var(--surface-2);
  }

  .fill {
    display: block;
    block-size: 100%;
    background: var(--accent-soft);
    transition: inline-size 0.12s ease;
  }

  @media (pointer: coarse) {
    .seg .end {
      width: var(--tap-target);
    }
  }
}
```

In `text-size-control.component.html`, change `class="smaller"` to `class="end smaller"` and `class="larger"` to `class="end larger"`. In `brightness-control.component.html`, change `class="darker"` to `class="end darker"` and `class="brighter"` to `class="end brighter"`.

In `text-size-control.component.scss`, delete the `.seg .smaller, .seg .larger { padding: var(--space-2); }` rule. In `brightness-control.component.scss`, delete the `.seg .darker, .seg .brighter { padding: var(--space-2); }` rule.

- [ ] **Step 6: Restyle the view controls**

In `view-controls.component.html`, change every `size="md"` to `size="dense"`. There are four: the magazine-style loop, list, pane and the theme loop.

Replace `view-controls.component.scss` with:

```scss
@use '../../../theme/breakpoints' as bp;
@use '../../../theme/segmented';
@include segmented.frame;

:host {
  display: flex;
  justify-content: space-between;
  gap: var(--space-2);
}

.seg {
  flex: none;
}

.seg button {
  width: var(--space-6);
}

.seg button + button {
  border-left: 1px solid var(--border);
}

.seg button.active {
  background: var(--accent-soft);
  color: var(--accent);
}

@media (pointer: coarse) {
  .seg button {
    width: var(--tap-target);
  }
}

/* Pane layout needs a wide viewport, so hide it on narrow screens. */
@media (width <= bp.$bp-md) {
  .seg button.pane {
    display: none;
  }
}
```

- [ ] **Step 7: Run the tests to verify they pass**

Run: `docker compose exec -T frontend npm test -- src/app/reader/shell/sidebar src/app/reader/shell/view-controls`

Expected: PASS, including `keeps the foot order: organise, text size, brightness, view controls, trial, meta`. The badge now lives inside `.meta`, so the host order is unchanged.

- [ ] **Step 8: Gate**

Run: `docker compose exec -T frontend npx prettier --write src/app/theme/_segmented.scss src/app/reader/shell/sidebar src/app/reader/shell/view-controls`

Then: `docker compose exec -T frontend npm run check`

Expected: exit 0.

- [ ] **Step 9: Commit**

```bash
git add frontend/src/app/theme/_segmented.scss frontend/src/app/reader/shell/sidebar frontend/src/app/reader/shell/view-controls
git commit -m "feat(#1749): footer card with the app's own steppers, segments and meta row"
```

---

### Task 8: Phone organise mode

Organise mode follows `GroupedOrganise.dc.html`:

- The head becomes "Organise" plus an accent **Done** button that ends organising, followed by the hint line.
- Saved searches are left out.
- The Tags card is forced open, and its head, like every section head in organise mode, is plain text with no count and no chevron.
- Each 44px row reads handle (40) · body (inert) · ⋯ (44) · chevron (44). A feed row has an empty 44px column where a tag row has its chevron.
- The organise cards pad 4px 0, groups inset 4px, and a parent's own feeds start 24px in.
- The drag preview and the drop line match the board.

**Files:**
- Modify: `sidebar.component.html`, `.scss`
- Modify: `_sidebar-row.scss` (`drag-handle`)
- Modify: `sidebar-feed-row.component.html`, `.scss`, `.ts`
- Modify: `sidebar-social-section.component.html`, `.scss`
- Modify: `sidebar-foot.component.scss`, `.ts`
- Modify: `frontend/public/i18n/en.json`, `de.json`
- Test: `sidebar.component.spec.ts`

**Interfaces:**
- Produces:
  - DOM classes `.organise-head`, `.organise-done`, `.organise-hint` and `.handle-gap`.
  - Host class `organising` on `app-sidebar-feed-row` and `app-sidebar-foot`, and class `organising` on the social `.card`.
- Consumes: `tagGroups` and `#tagHead` (Task 5) and `.favicon-slot` (Task 6).

- [ ] **Step 1: Write the failing tests**

In `sidebar.component.spec.ts`, inside `describe('organise mode', …)`, replace the test `organise rows carry a drag handle and expand via the row body` with:

```ts
  it('organise tag rows carry a handle, an inert body, ⋯ and a chevron that expands', () => {
    const fixture = mount({ coarse: true, organising: true, tagTree: tree });
    const element = fixture.nativeElement as HTMLElement;
    const row = element.querySelector('.tag')!;

    expect([...row.children].map((child) => child.classList[0])).toEqual([
      'handle',
      'rowbody',
      'dots',
      'chevzone',
    ]);
    expect(row.querySelector('.rowbody')!.tagName).toBe('SPAN');
    expect(row.querySelector('.nav.grow')).toBeNull();
    expect(row.querySelector('.chevzone')!.getAttribute('aria-expanded')).toBe('false');

    row.querySelector<HTMLButtonElement>('.chevzone')!.click();
    fixture.detectChanges();

    expect(element.querySelector('.tag .chevzone')!.getAttribute('aria-expanded')).toBe('true');
    expect(element.querySelector('.tagfeeds .handle')).not.toBeNull();
  });

  it('swaps the drawer head for Organise and Done, and Done ends organising', () => {
    const fixture = mount({ coarse: true, organising: true, narrow: true });
    const element = fixture.nativeElement as HTMLElement;

    expect(element.querySelector('.drawer-head')).toBeNull();
    expect(element.querySelector('.organise-head .drawer-title')!.textContent?.trim()).toBe(
      'Organise',
    );
    expect(element.querySelector('.organise-hint')).not.toBeNull();

    element.querySelector<HTMLButtonElement>('.organise-done')!.click();
    fixture.detectChanges();

    expect(fixture.componentInstance.organising()).toBe(false);
    expect(element.querySelector('.drawer-head')).not.toBeNull();
  });

  describe('organise layout', () => {
    afterEach(() => localStorage.clear());

    it('leaves out saved searches and keeps the tags open under a plain head', () => {
      const fixture = mount({
        coarse: true,
        tagTree: tree,
        savedSearches: [
          {
            id: 1,
            slug: '1-climate',
            term: 'climate',
            wholeWord: false,
            phrase: false,
            position: 0,
            unreadCount: 3,
            memberCount: 3,
            includeInDigest: false,
          },
        ],
      });
      fixture.componentInstance.toggleTags();
      fixture.componentRef.setInput('organising', true);
      fixture.detectChanges();
      const element = fixture.nativeElement as HTMLElement;

      expect(element.querySelector('app-sidebar-saved-searches')).toBeNull();
      expect(element.querySelector('.tags .taghead')).not.toBeNull();
      expect(element.querySelector('.tags-head .section-link')).toBeNull();
      expect(element.querySelector('.tags-head .section-chevron')).toBeNull();
      expect(element.querySelector('.tags-head .label')!.textContent?.trim()).toBe('Tags');
    });
  });
```

- [ ] **Step 2: Run them to verify they fail**

Run: `docker compose exec -T frontend npm test -- src/app/reader/shell/sidebar/sidebar.component.spec.ts -t "organise"`

Expected: FAIL, because there is no `.chevzone` in organise rows, no `.organise-head`, and saved searches still render.

- [ ] **Step 3: Add the i18n keys**

`en.json` → `reader`, after `"organise": "Organise",`:

```json
    "organiseDone": "Done",
    "organiseHint": "Drag a handle to reorder within its level, or tap ⋯ for a row’s options. Drop a feed on a tag to tag it.",
```

`de.json` → `reader`, after `"organise": "Organisieren",`:

```json
    "organiseDone": "Fertig",
    "organiseHint": "Zieh einen Griff, um innerhalb seiner Ebene umzusortieren, oder tippe auf ⋯ für die Optionen einer Zeile. Leg einen Feed auf einen Tag, um ihn zu taggen.",
```

- [ ] **Step 4: Head, saved searches and section heads in the template**

In `sidebar.component.html`, change the opening `@if (!organising()) {` of the head-and-smart-views block into an if/else. The new organise branch comes first:

```html
  @if (organising()) {
    <div class="organise-head">
      <span class="drawer-title">{{ 'reader.organise' | transloco }}</span>
      <button class="organise-done" type="button" (click)="organising.set(false)">
        {{ 'reader.organiseDone' | transloco }}
      </button>
    </div>
    <p class="organise-hint">{{ 'reader.organiseHint' | transloco }}</p>
  } @else {
```

The rest of that block is the existing `@if (screen.isNarrow()) {…} @else {…}` and the smart-views card. It stays as the else body, closed by the existing `}`.

Wrap `<app-sidebar-saved-searches … />` in `@if (!organising()) { … }`.

Replace the inside of `<div class="section-head tags-head">` (link, slot and chevron) with:

```html
        @if (organising()) {
          <span class="label">{{ 'reader.tags' | transloco }}</span>
        } @else {
          <a
            class="section-link"
            [class.active]="activeGroup() === 'tags'"
            [routerLink]="['/']"
            [queryParams]="selectionQueryParams({ group: 'tags' })"
            queryParamsHandling="merge"
          >
            <span class="label">{{ 'reader.tags' | transloco }}</span>
            @if (groupUnread().tags > 0) {
              <span class="section-count">{{ groupUnread().tags }}</span>
            }
          </a>
          <span class="slot" aria-hidden="true"></span>
          <button
            class="section-chevron"
            type="button"
            [attr.aria-expanded]="tagsExpanded()"
            [attr.aria-label]="'reader.toggleTags' | transloco"
            (click)="toggleTags()"
          >
            <app-icon [name]="tagsExpanded() ? 'expand_more' : 'chevron_right'" size="dense" />
          </button>
        }
```

and change `@if (tagsExpanded()) {` (around the `.tags` block) to `@if (tagsExpanded() || organising()) {`.

Do the same for `<div class="section-head feeds-head">`:

```html
      <div class="section-head feeds-head">
        @if (organising()) {
          <span class="label">{{ 'reader.feeds' | transloco }}</span>
        } @else {
          <a
            class="section-link"
            [class.active]="activeGroup() === 'feeds'"
            [routerLink]="['/']"
            [queryParams]="selectionQueryParams({ group: 'feeds' })"
            queryParamsHandling="merge"
          >
            <span class="label">{{ 'reader.feeds' | transloco }}</span>
            @if (groupUnread().feeds > 0) {
              <span class="section-count">{{ groupUnread().feeds }}</span>
            }
          </a>
          <span class="slot" aria-hidden="true"></span>
          <button
            class="section-chevron"
            type="button"
            [attr.aria-expanded]="feedsExpanded()"
            [attr.aria-label]="'reader.toggleFeeds' | transloco"
            (click)="toggleFeeds()"
          >
            <app-icon [name]="feedsExpanded() ? 'expand_more' : 'chevron_right'" size="dense" />
          </button>
        }
      </div>
```

- [ ] **Step 5: The organise tag row**

In the `#tagHead` template, replace the whole `@if (organising()) { … }` branch (handle, `button.rowbody`, `button.dots`) with:

```html
        @if (organising()) {
          <span class="handle" cdkDragHandle aria-hidden="true">
            <app-icon name="drag_indicator" size="md" />
          </span>
          <span class="rowbody">
            <span class="lead">
              <app-tag-glyph
                [name]="node.tag.icon"
                [color]="node.tag.color"
                [size]="depth === 1 ? 'dense' : 'md'"
              />
            </span>
            <span class="name">{{ node.tag.name }}</span>
          </span>
          <button
            class="dots"
            type="button"
            aria-haspopup="dialog"
            [attr.aria-label]="'reader.manage' | transloco: { name: node.tag.name }"
            (click)="rows.openTagSheet(node.tag)"
          >
            <app-icon name="more_horiz" size="md" />
          </button>
          <button
            class="chevzone"
            type="button"
            [attr.aria-expanded]="open"
            [attr.aria-label]="'reader.toggleTag' | transloco: { name: node.tag.name }"
            (click)="toggle(node.tag.id)"
          >
            <app-icon [name]="open ? 'expand_more' : 'chevron_right'" size="dense" />
          </button>
        }
```

- [ ] **Step 6: The organise feed row**

In `sidebar-feed-row.component.html`, replace the organise branch's handle block:

```html
  @if (draggable) {
    <span class="handle" cdkDragHandle aria-hidden="true">
      <app-icon name="drag_indicator" size="sm" />
    </span>
  }
```

with:

```html
  @if (draggable) {
    <span class="handle" cdkDragHandle aria-hidden="true">
      <app-icon name="drag_indicator" size="md" />
    </span>
  } @else {
    <span class="handle-gap" aria-hidden="true"></span>
  }
```

In `sidebar-feed-row.component.ts`, extend the host bindings:

```ts
  host: { '[class.current]': 'active()', '[class.organising]': 'organising()' },
```

Append to `sidebar-feed-row.component.scss`:

```scss
:host(.organising) {
  gap: 0;
  padding-right: 0;
}

:host(.organising) .feedname {
  padding-right: var(--space-1);
}

:host-context(.cdk-drag-preview) .handle {
  color: var(--accent);
}

:host-context(.cdk-drag-preview) .dots,
:host-context(.cdk-drag-preview) .trail,
:host-context(.cdk-drag-preview) .count {
  display: none;
}
```

- [ ] **Step 7: The handle measure**

In `_sidebar-row.scss`, replace `@mixin drag-handle { … }` with:

```scss
/* Organise-mode drag handle: the ONLY drag origin there (cdkDragHandle), so a
   plain touch on the row body scrolls or expands without competing. A row that
   cannot be dragged keeps the column empty, so the names stay in line. */
@mixin drag-handle {
  .handle,
  .handle-gap {
    flex: none;
    align-self: stretch;

    /* stylelint-disable-next-line declaration-property-unit-allowed-list --
       tuned handle measure, not a spacing value. */
    width: 40px;
  }

  .handle {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    color: var(--text-muted);
    cursor: grab;
  }
}
```

- [ ] **Step 8: The organise styles in the sidebar**

In `sidebar.component.scss`:
- Add `@include row.chevron('.chevzone');` after `@include row.chevron('.section-chevron');`.
- Delete the interim `.organising .tag { display: flex; align-items: center; }` rule, the `.rowbody { … }` rule with its comment, and the trailing `@media (pointer: coarse) { .rowbody { … } }` block.
- Replace the `.cdk-drag-preview { … }` and `.cdk-drag-preview .rowmenu { … }` rules with:

```scss
.cdk-drag-preview {
  @include glass-rim.filled(var(--surface-1), var(--border));

  border-radius: var(--radius);
  box-shadow: 0 6px 20px rgb(0 0 0 / 28%);
}

.cdk-drag-preview .handle {
  color: var(--accent);
}

.cdk-drag-preview .rowmenu,
.cdk-drag-preview .dots,
.cdk-drag-preview .chevzone {
  display: none;
}
```

Then add:

```scss
.sidebar.organising {
  --row-inset: 0;
  --row-fs: var(--fs-base);

  gap: var(--space-2);
}

.organise-head {
  display: flex;
  flex: none;
  align-items: center;
  gap: var(--space-2);
  padding: 0 var(--space-1) 0 var(--space-3);
}

.organise-done {
  height: var(--tap-target);
  padding: 0 calc(var(--space-4) + var(--space-0));
  border: 0;
  border-radius: var(--radius);
  background: var(--accent);
  color: var(--on-accent);
  font: inherit;
  font-size: var(--fs-base);
  font-weight: 600;
  cursor: pointer;
}

.organise-hint {
  flex: none;
  margin: calc(var(--space-2) + var(--space-0)) var(--space-3) var(--space-1);
  color: var(--text-secondary);
  font-size: var(--fs-sm);
  line-height: var(--lh-normal);
}

.organising .card {
  padding: var(--space-1) 0;
}

.organising .section-head {
  height: var(--control-h);
  padding-left: var(--space-3);
}

.organising .feedlist,
.organising .tagfeeds,
.organising .tag-child {
  --row-fs: var(--fs-base);
  --row-inset: 0;
}

.organising .tag-group > .tagfeeds,
.organising .tag-child .tagfeeds {
  --row-inset: var(--space-5);
}

.organising .tag-group.open {
  margin: 0 var(--space-1);
  padding-bottom: var(--space-1);
}

.organising .tag-child {
  margin-bottom: var(--space-1);
}

.organising .tag {
  display: flex;
  align-items: center;
  height: var(--tap-target);
  padding-left: var(--row-inset, 0);
  border-radius: var(--radius);
  font-size: var(--row-fs, var(--fs-base));
}

.rowbody {
  flex: 1;
  min-width: 0;
  display: flex;
  align-items: center;
  gap: var(--space-3);
  padding-right: var(--space-1);
  color: var(--text-primary);
}

.organising .feedrow.cdk-drag-placeholder {
  margin: calc(var(--space-0) / 2) var(--space-3) calc(var(--space-0) / 2)
    calc(var(--space-7) + var(--space-4));
}
```

- [ ] **Step 9: The network card in organise mode**

In `sidebar-social-section.component.html`:
- Change the opening `<div class="card">` to `<div class="card" [class.organising]="organising()">`.
- Wrap the head's current contents (link, menu/dots, chevron) in the else branch of:

```html
    @if (organising()) {
      <app-network-logo [network]="current.network" />
      <span class="label">{{ 'settings.social.' + current.network | transloco }}</span>
    } @else {
```

- Change `@if (expanded()) {` (around `.rows`) to `@if (expanded() || organising()) {`.

Append to `sidebar-social-section.component.scss`:

```scss
.card.organising {
  padding: var(--space-1) 0;
}

.organising .section-head {
  gap: calc(var(--space-2) + var(--space-0));
  height: var(--control-h);
  padding-left: var(--space-3);
}

.organising .section-head app-network-logo {
  width: var(--icon-sm);
  height: var(--icon-sm);
}

.organising .rows {
  --row-fs: var(--fs-base);
  --row-inset: 0;
}
```

- [ ] **Step 10: The organise foot**

In `sidebar-foot.component.ts`, add this to the decorator:

```ts
  host: { '[class.organising]': 'organising()' },
```

Append to `sidebar-foot.component.scss`:

```scss
:host(.organising) {
  gap: var(--space-0);
  padding: 0 var(--space-1) calc(var(--space-1) + var(--space-0)) 0;
}

:host(.organising) .organise {
  height: calc(var(--tap-target) + var(--space-2));
  padding: 0 var(--space-1) 0 var(--space-3);
}

:host(.organising) .meta {
  min-height: var(--space-6);
  padding: 0 var(--space-1) 0 var(--space-3);
}
```

- [ ] **Step 11: Run the tests to verify they pass**

Run: `docker compose exec -T frontend npm test -- src/app/reader/shell/sidebar`

Expected: PASS. `resets organising when the pointer stops being coarse` still finds `.tag .chevzone` after the reset, from the nav group row. `the tag dots open the action sheet and route the choice` still passes here; Task 9 changes its expected actions.

- [ ] **Step 12: Gate**

Run: `docker compose exec -T frontend npx prettier --write src/app/reader/shell/sidebar public/i18n/en.json public/i18n/de.json`

Then: `docker compose exec -T frontend npm run check`

Expected: exit 0.

- [ ] **Step 13: Commit**

```bash
git add frontend/src/app/reader/shell/sidebar frontend/public/i18n/en.json frontend/public/i18n/de.json
git commit -m "feat(#1749): phone organise mode with handle, body, ⋯ and chevron rows"
```

---

### Task 9: Tag sheet — Edit tag, Move up, Move down, Delete tag

The ⋯ on an organise tag row opens the shared action sheet with these choices:
- Edit tag
- Move up
- Move down
- a divider
- Delete tag (danger)

Move up and Move down reorder the tag within its sibling set through the same `ManageActions.reorderTags` path the drag uses. They are disabled at the ends of that set.

**Files:**
- Modify: `frontend/src/app/reader/shell/sidebar/sidebar-row-actions.service.ts`
- Modify: `frontend/public/i18n/en.json`, `de.json`
- Test: `sidebar-row-actions.service.spec.ts`, `sidebar.component.spec.ts`

**Interfaces:**
- Consumes: `TagsStore.tags: WritableSignal<TagDto[]>`, `siblingIdsOf(tags, tag): number[]`, `ManageActions.reorderTags(ids: number[]): void` and `ACTION_SHEET_DIVIDER`.
- Produces the sheet action ids `edit`, `moveUp`, `moveDown` and `delete`.

- [ ] **Step 1: Write the failing service tests**

In `sidebar-row-actions.service.spec.ts`:
- Add the imports `import { TagsStore } from '../../state/tags.store';`, `import { TagDto } from '../../models';` and `import { ACTION_SHEET_DIVIDER } from '../../../shared/action-sheet/action-sheet.component';`.
- In both existing `providers` arrays (the top-level `titles an unrenamed Home's feed sheet …` test and `describe('social accounts')`'s `beforeEach`), add `{ provide: TagsStore, useValue: { tags: signal<TagDto[]>([]) } }`. The service now injects `TagsStore`, whose real implementation needs `HttpClient`.
- Append a new describe:

```ts
  describe('the tag sheet', () => {
    const tag = (id: number, position: number, parentId: number | null = null): TagDto => ({
      id,
      name: `t${id}`,
      color: null,
      icon: null,
      position,
      parentId,
    });
    const sheetOpen = jest.fn();
    const reorderTags = jest.fn();
    const editTag = jest.fn();
    const deleteTag = jest.fn();
    const tags = signal<TagDto[]>([]);

    beforeEach(() => {
      [sheetOpen, reorderTags, editTag, deleteTag].forEach((spy) => spy.mockReset());
      sheetOpen.mockReturnValue(of(undefined));
      tags.set([tag(1, 0), tag(2, 1), tag(3, 2), tag(11, 0, 1), tag(12, 1, 1)]);
      TestBed.configureTestingModule({
        imports: [provideTranslocoTesting()],
        providers: [
          SidebarRowActions,
          { provide: ActionSheet, useValue: { open: sheetOpen } },
          { provide: ManageActions, useValue: { reorderTags, editTag, deleteTag } },
          { provide: SavedSearchesStore, useValue: {} },
          { provide: TagsStore, useValue: { tags } },
        ],
      });
    });

    const actionsFor = (target: TagDto) => {
      TestBed.inject(SidebarRowActions).openTagSheet(target);
      return sheetOpen.mock.calls[0][0].actions as { id?: string; disabled?: boolean }[];
    };

    it('offers edit, move up, move down and, after a divider, delete', () => {
      TestBed.inject(SidebarRowActions).openTagSheet(tag(2, 1));

      expect(sheetOpen).toHaveBeenCalledWith({
        title: 't2',
        actions: [
          { id: 'edit', label: 'Edit tag', symbol: 'edit' },
          { id: 'moveUp', label: 'Move up', symbol: 'arrow_upward', disabled: false },
          { id: 'moveDown', label: 'Move down', symbol: 'arrow_downward', disabled: false },
          ACTION_SHEET_DIVIDER,
          { id: 'delete', label: 'Delete tag', symbol: 'delete', danger: true },
        ],
      });
    });

    it('disables Move up on the first sibling', () => {
      const actions = actionsFor(tag(1, 0));
      expect(actions.find((action) => action.id === 'moveUp')?.disabled).toBe(true);
      expect(actions.find((action) => action.id === 'moveDown')?.disabled).toBe(false);
    });

    it('disables Move down on the last child of its parent, not of the roots', () => {
      const actions = actionsFor(tag(12, 1, 1));
      expect(actions.find((action) => action.id === 'moveUp')?.disabled).toBe(false);
      expect(actions.find((action) => action.id === 'moveDown')?.disabled).toBe(true);
    });

    it('moves a root up among the roots', () => {
      sheetOpen.mockReturnValue(of('moveUp'));
      TestBed.inject(SidebarRowActions).openTagSheet(tag(2, 1));
      expect(reorderTags).toHaveBeenCalledWith([2, 1, 3]);
    });

    it('moves a child down within its parent only', () => {
      sheetOpen.mockReturnValue(of('moveDown'));
      TestBed.inject(SidebarRowActions).openTagSheet(tag(11, 0, 1));
      expect(reorderTags).toHaveBeenCalledWith([12, 11]);
    });

    it('still routes edit and delete', () => {
      sheetOpen.mockReturnValueOnce(of('edit'));
      TestBed.inject(SidebarRowActions).openTagSheet(tag(2, 1));
      sheetOpen.mockReturnValueOnce(of('delete'));
      TestBed.inject(SidebarRowActions).openTagSheet(tag(3, 2));

      expect(editTag).toHaveBeenCalledWith(tag(2, 1));
      expect(deleteTag).toHaveBeenCalledWith(tag(3, 2));
      expect(reorderTags).not.toHaveBeenCalled();
    });
  });
```

- [ ] **Step 2: Update the sidebar spec's expectation**

In `sidebar.component.spec.ts` → `the tag dots open the action sheet and route the choice`, replace the expected `actions` array. That tree holds one tag, so both moves are disabled:

```ts
      actions: [
        { id: 'edit', label: 'Edit tag', symbol: 'edit' },
        { id: 'moveUp', label: 'Move up', symbol: 'arrow_upward', disabled: true },
        { id: 'moveDown', label: 'Move down', symbol: 'arrow_downward', disabled: true },
        ACTION_SHEET_DIVIDER,
        { id: 'delete', label: 'Delete tag', symbol: 'delete', danger: true },
      ],
```

Add `import { ACTION_SHEET_DIVIDER } from '../../../shared/action-sheet/action-sheet.component';` to that spec.

- [ ] **Step 3: Run the tests to verify they fail**

Run: `docker compose exec -T frontend npm test -- src/app/reader/shell/sidebar/sidebar-row-actions.service.spec.ts src/app/reader/shell/sidebar/sidebar.component.spec.ts -t "tag sheet|tag dots"`

Expected: FAIL, because the sheet still offers only edit and delete, and the labels `Move up` / `Move down` do not exist.

- [ ] **Step 4: Add the i18n keys**

`en.json` → `reader`, after `"deleteTag": …,`:

```json
    "moveTagUp": "Move up",
    "moveTagDown": "Move down",
```

`de.json` → `reader`, after its `"deleteTag": …,`:

```json
    "moveTagUp": "Nach oben",
    "moveTagDown": "Nach unten",
```

- [ ] **Step 5: Implement the sheet**

In `sidebar-row-actions.service.ts`:
- Add the imports `import { moveItemInArray } from '@angular/cdk/drag-drop';`, `import { ACTION_SHEET_DIVIDER, ActionSheetAction } from '../../../shared/action-sheet/action-sheet.component';` (replacing the `ActionSheetAction`-only import), `import { TagsStore } from '../../state/tags.store';` and `import { siblingIdsOf } from '../../state/subscription-derivations';`.
- Add this field: `private readonly tags = inject(TagsStore);`.
- Replace `openTagSheet` and its doc comment with:

```ts
  /** ⋯ on a tag row (coarse): the tag's actions; the moves stay within its sibling set, like a drag. */
  openTagSheet(tag: TagDto): void {
    const siblingIds = siblingIdsOf(this.tags.tags(), tag);
    const index = siblingIds.indexOf(tag.id);
    this.sheet
      .open({
        title: tag.name,
        actions: [
          { id: 'edit', label: this.transloco.translate('reader.editTag'), symbol: 'edit' },
          {
            id: 'moveUp',
            label: this.transloco.translate('reader.moveTagUp'),
            symbol: 'arrow_upward',
            disabled: index <= 0,
          },
          {
            id: 'moveDown',
            label: this.transloco.translate('reader.moveTagDown'),
            symbol: 'arrow_downward',
            disabled: index < 0 || index === siblingIds.length - 1,
          },
          ACTION_SHEET_DIVIDER,
          {
            id: 'delete',
            label: this.transloco.translate('reader.deleteTag'),
            symbol: 'delete',
            danger: true,
          },
        ],
      })
      // A sheet can outlive the sidebar (e.g. the shell unmounts); a late
      // choice must not emit into destroyed outputs.
      .pipe(takeUntilDestroyed(this.destroyRef))
      .subscribe((choice) => {
        if (choice === 'edit') this.manage.editTag(tag);
        if (choice === 'moveUp') this.moveTag(siblingIds, index, index - 1);
        if (choice === 'moveDown') this.moveTag(siblingIds, index, index + 1);
        if (choice === 'delete') this.manage.deleteTag(tag);
      });
  }

  private moveTag(siblingIds: number[], from: number, to: number): void {
    moveItemInArray(siblingIds, from, to);
    this.manage.reorderTags(siblingIds);
  }
```

The sheet renders a disabled action as inert (`[disabled]`), so the `moveUp`/`moveDown` choices only arrive from an enabled action.

- [ ] **Step 6: Run the tests to verify they pass**

Run: `docker compose exec -T frontend npm test -- src/app/reader/shell/sidebar`

Expected: PASS.

- [ ] **Step 7: Gate**

Run: `docker compose exec -T frontend npx prettier --write src/app/reader/shell/sidebar public/i18n/en.json public/i18n/de.json`

Then: `docker compose exec -T frontend npm run check`

Expected: exit 0.

- [ ] **Step 8: Commit**

```bash
git add frontend/src/app/reader/shell/sidebar frontend/public/i18n/en.json frontend/public/i18n/de.json
git commit -m "feat(#1749): move a tag up or down from its organise sheet"
```

---

### Task 10: design-language.md

**Files:**
- Modify: `docs/design-language.md`

**Interfaces:** none (documentation).

- [ ] **Step 1: §1 Structural sizing — add the sidebar geometry**

Directly after the paragraph that ends `` …so the `calc()` above reads as arithmetic. `` (the end of "Structural sizing"), add:

````markdown
### Sidebar geometry (#1749)

The grouped-card sidebar's own measures. They sit on `:root`, not in the sidebar's stylesheet, because the CDK drag preview leaves the sidebar for `<body>` and still reads them.

| Token | Value | For |
|---|---|---|
| `--sidebar-nav-w` | `316px` | the desktop card column; the column adds an 8px gutter each side (332px) |
| `--sidebar-drawer-nav-w` | `336px` | the phone sheet's card column; with its 12/12/12/10 padding and right border the sheet is 359px, inside a 390pt iPhone |
| `--sidebar-row-h` | `36px` | a row straight on a card: the smart views, a root tag |
| `--sidebar-sub-row-h` | `32px` | a section head, a row in a section (feeds, saved searches, a network's feeds), a child tag, a group's own feeds |
| `--sidebar-leaf-row-h` | `30px` | a child tag's feeds, inside its box; the saved-search "Show more" |
| `--sidebar-slot` | `28px` | the ⋯ and chevron columns |
| `--sidebar-frame-h` | `36px` | the foot's stepper and segmented frames |
| `--sidebar-card-pad` | `calc(var(--space-1) + var(--space-0))` | a card's padding, a group's bottom padding, the inner box's right and bottom margin |
| `--sidebar-row-inset` | `calc(var(--space-2) + var(--space-0))` | a row's left padding on its own surface |
| `--sidebar-row-deep-inset` | inset + `--icon-md` + `--space-3` | a row stepped in under a lead: it starts where its parent's label starts |
| `--drawer-shadow` | `6px 0 24px rgb(0 0 0 / 18%)` | the phone sheet's cast |
| `--drawer-scrim` | `rgb(0 0 0 / 32%)` light, `55%` dark | the backdrop behind the phone sheet |
| `--hidden-favicon-opacity` | `0.35` light, `0.55` dark | a hidden feed's greyed favicon |

Every row height is `--tap-target` on a coarse pointer.
````

- [ ] **Step 2: §1 Icon sizes — the dense step**

Replace the intro sentence `Four steps, because the same tag glyph is rendered from a 12px pill up to a 20px sidebar lead; three steps would inflate the pill.` with:

```markdown
Five steps. The same tag glyph runs from a 12px pill to a 20px sidebar lead, and the sidebar's 28px buttons, its segmented controls and a child tag's lead carry an 18px glyph (#1749).
```

In the table, add this row between `--icon-sm` and `--icon-md`:

```markdown
| `--icon-dense` | `18px` | `dense` |
```

- [ ] **Step 3: §1 Type — the dense step**

In the Type table, add this row between `--fs-sm` and `--fs-base`:

```markdown
| `--fs-dense` | `14px` | the sidebar's nested rows and its row menu (#1749) |
```

- [ ] **Step 4: §1 Row density — the sidebar's rows**

Replace:

```markdown
In use by `discover/category-rail`, `discover/category-chips`,
`reader/sidebar`, `admin/admin-catalog`.
```

with:

```markdown
In use by `discover/category-rail`, `discover/category-chips`,
the `reader/sidebar` row menu, `admin/admin-catalog`. The sidebar's rows
themselves are fixed heights since #1749 (see §3 *Sidebar cards*).
```

- [ ] **Step 5: §1 Glass rim — "Stays flat"**

In the **Stays flat** paragraph, delete `` `.section-chevron app-icon` (a transparent alignment border); `` together with its trailing space. The rule is gone, and so is its `EXEMPT` entry.

- [ ] **Step 6: §2 `<app-search-field>` — the inset variant**

After the paragraph that ends ``…drive one code path.``, add:

```markdown
In the desktop sidebar the field sits in the search card as `<app-search-field class="inset">`: the card draws the rim, and the field becomes a `--surface-0` well 38px tall with its left corners following the card's (#1749).
```

- [ ] **Step 7: §3 — replace "Sidebar row markers" with the cards and the hidden-feed badge**

Replace the whole `### Sidebar row markers` section, up to but not including `### Text-size control`, with:

````markdown
### Sidebar cards (#1749)

The sidebar is a column of cards on `--surface-0`: the search card (desktop) or the "Feeds" header with refresh, add feed and close (phone), then smart views, Saved searches, Tags, Feeds, one per network, and the footer. A card is `glass-rim.filled(var(--surface-1), var(--border))`, `--radius-lg`, `--sidebar-card-pad`; cards stand `--space-2` apart (`--space-3` on a coarse pointer). The footer card rides at the end of the column (`margin-top: auto`) and scrolls with it. The mixins live in `reader/shell/sidebar/_sidebar-row.scss`.

**A row** is `row-geometry`: a fixed-height flex line, `0 var(--space-2) 0 var(--row-inset)`, hover `--surface-0`, active `--accent-soft` with accent text, glyph and count. Its height, left inset and type size come from the surface it sits on, through the inherited `--row-h`, `--row-inset` and `--row-fs`, so a row component never reaches into its parent and a parent never pierces a row.

**The right edge is columns, per surface.** Every row and head ends count · ⋯ (28px) · chevron (28px) · 8px. A row without a ⋯ puts an empty `.slot` there, and `.slot` and the ⋯ host both carry `margin-right: calc(var(--space-3) * -1)`, so the count sits in the same place either way. A row without a chevron ends in an empty `.trail`. On a coarse pointer the ⋯ and `.slot` go and the chevron column is 44px.

**Heads** (`section-head`) are 13px/600 `--text-secondary`, sentence case, and their title still opens the combined list (#1735).

**Nesting** (one level, #1736). An open tag is a grey group (`.group.open`, `--surface-sunken`). Each child tag sits in its own `--surface-1` box; the parent's own feeds follow the boxes. Rows inside a box start at the box's edge again, so every chevron keeps the same gap to its own surface. The drop lists stay top-level siblings: the group and the box are plain divs, and the heads and feed lists are rendered through `#tagHead` / `#tagFeeds` templates.

**Phone.** The drawer is a full-height sheet over the app bar, flush left, `--drawer-shadow` over `--drawer-scrim`. Organise mode swaps the header for "Organise" and **Done**, leaves Saved searches out, and lays every row out as handle (40px) · body · ⋯ · chevron, all 44px. Its tag sheet offers Edit tag, Move up, Move down and Delete tag; the moves reorder within the sibling set the drag uses and disable at its ends.

### Hidden-feed favicon

A feed hidden from All items, For you or saved searches (#688) shows it on its favicon, not with a trailing glyph. The favicon greys and fades (`--hidden-favicon-opacity`, `grayscale(1)`), and a small eye badge sits on its bottom-right corner:

```html
<span class="favicon-slot" [class.hidden-feed]="excluded()">
  <app-favicon [url]="subscription().faviconUrl" [avatarUrl]="avatarUrl()" />
  @if (excluded()) {
    <span class="feed-exclusion-marker" role="img" [attr.aria-label]="markerTitle()" [title]="markerTitle()">
      <app-icon name="visibility_off" size="text" />
    </span>
  }
</span>
```

The rules a future marker should reuse:

- **It marks the lead, not the line.** The badge rides the favicon, so the label and the count keep their places whether or not it shows.
- **The badge is a chip of its own surface:** `--surface-1` with a 1px `--border` ring, so it reads on the card, the grey group and the inner box alike.
- **Every other favicon renders at full strength.** Only the hidden state fades.
- **A `title` and an `aria-label` name the exact condition** (`exclusionTitle()`), never a generic "hidden".
````

- [ ] **Step 8: §3 Density — point to the mixins**

In the `### Density` section, replace `` `@media (pointer: coarse)` in its own stylesheet (#185). `` with `` `@media (pointer: coarse)` in `_sidebar-row.scss` (#185, #1749). ``

- [ ] **Step 9: Commit**

```bash
git add docs/design-language.md
git commit -m "feat(#1749): design language for the grouped-card sidebar"
```

---

### Task 11: e2e updates and the final gate

**Files:**
- Modify: `frontend/e2e/sidebar-mobile.spec.ts`
- Modify: `frontend/e2e/sidebar-toggle-desktop.spec.ts`
- Modify: `frontend/e2e/sidebar-tag-truncation.spec.ts` (stale comment)

**Interfaces:** none.

- [ ] **Step 1: The phone sheet's geometry and close button**

In `sidebar-mobile.spec.ts`, directly after `await expect(sidebar).toBeVisible();` (after opening the drawer), add:

```ts
    // A full-height sheet over the app bar (#1749).
    const drawer = page.locator('aside.sidebar');
    expect((await drawer.boundingBox())!.y).toBe(0);
```

At the end of the test, after the final `await expect(sidebar.getByRole('link', { name: /All items/ })).toBeVisible();`, add:

```ts
    // The sheet's own close button dismisses it.
    await sidebar.getByRole('button', { name: 'Close sidebar' }).click();
    await expect(drawer).not.toHaveClass(/\bopen\b/);
```

The test already clicks `Manage News` while organising and asserts `Delete tag` on the sheet. Extend it right after `await expect(sheet.getByRole('menuitem', { name: 'Delete tag' })).toBeVisible();`:

```ts
    await expect(sheet.getByRole('menuitem', { name: 'Move up' })).toBeDisabled();
    await expect(sheet.getByRole('menuitem', { name: 'Move down' })).toBeDisabled();
```

(The stub holds one tag, News, so both moves sit at the ends of its sibling set.)

- [ ] **Step 2: The desktop column's width**

In `sidebar-toggle-desktop.spec.ts`, after `await expect(sidebar).toBeVisible();` (the first one), add:

```ts
  // 316px of cards in an 8px gutter each side (#1749).
  expect((await page.locator('aside.sidebar').boundingBox())!.width).toBe(332);
```

- [ ] **Step 3: The stale comment in the truncation spec**

In `sidebar-tag-truncation.spec.ts`, replace `the row grows past the 260px column and pushes the count and` with `the row grows past the column and pushes the count and`.

- [ ] **Step 4: Run the Playwright smokes**

They need the Docker stack up, started from this checkout (not a worktree):

Run: `cd frontend && npm run e2e -- sidebar-mobile sidebar-toggle-desktop sidebar-tag-truncation saved-search-layout header-scroll-mobile add-feed-mobile list-scroll-reset reader-smoke`

Expected: PASS, or tests skipped with "seeded admin login unavailable" when the seed is missing. A skip is not a pass: seed the admin with `docker compose exec php bin/console app:e2e:seed-admin` and re-run.

- [ ] **Step 5: Final gate**

Run: `docker compose exec -T frontend npx prettier --write e2e/sidebar-mobile.spec.ts e2e/sidebar-toggle-desktop.spec.ts e2e/sidebar-tag-truncation.spec.ts`

Then: `docker compose exec -T frontend npm run check`

Expected: exit 0.

- [ ] **Step 6: Check the dev server is current, then look at the result**

Live-check on `http://localhost:4200` against the boards. Check each of these:
- desktop light and dark, at the top and scrolled to the footer
- the phone drawer in navigation mode and in organise mode, and the tag sheet

Before judging a visual, confirm the frontend container serves the current chunk; a stale chunk is a known trap. Compare the 316px column, the row heights (36/32/30), the column alignment of counts, ⋯ and chevrons, the grey group, the inner box and the eye badge.

- [ ] **Step 7: Commit**

```bash
git add frontend/e2e/sidebar-mobile.spec.ts frontend/e2e/sidebar-toggle-desktop.spec.ts frontend/e2e/sidebar-tag-truncation.spec.ts
git commit -m "feat(#1749): e2e for the phone sheet, its close button and the desktop column"
```

---

## Open questions and places the mockup had no exact token

1. **New scale steps.** The plan adds `--fs-dense` (14px) and `--icon-dense` (18px) so that nested rows, the menu and every 28px button match the mockup exactly. If Lars would rather not grow the scales, use `--fs-sm` (13) or `--fs-base` (15) and `sm` (16) or `md` (20) instead. Those are one-line swaps in Tasks 1, 4 and 7.
2. **12px text.** The update badge and the phone stepper value are 12px in the mockup and get `--fs-xs` (11px). There is no 12 step.
3. **`--btn` (icon-button hover/open fill and switch off-track) maps to `--border-strong`.** That is exact in dark. In light it is one step darker than the board's `#e4e4e2`, which is `--border`.
4. **Glass rims.** The mockup's flat 1px card, frame and menu borders become glass rims. That is the house rule, enforced by `glass-rim-coverage.spec.ts`.
5. **The list toolbar band is 56px (decided by Lars, 2026-10-23).** On wide layouts (`width >= bp.$bp-lg`) `.list-header` in `entry-list.component.scss` is pinned to `--bar-h` (56px) high, content centred, so the 40px search card sits centred in the band; the narrow collapsed bar is unchanged. Implemented in Task 2, Step "toolbar band".
6. **The tag sheet uses the shared `app-action-sheet`** (grabber, title, round close) with symbols and a divider. The mockup's own head (glyph plus "1 sub-tag · 3 feeds"), the "Name, icon, colour and parent tag" subtitle under Edit tag and the Cancel button are not built, because they would restyle a shared component.
7. **One inner box per child tag.** The mockup shows only one child, so whether several children share one box or get one each is a guess.
8. **Social details not built.** These need a decision:
   - The phone navigation-mode network head keeps its ⋯ (the mockup hides every ⋯ on the phone). It is the only phone entry point to account management.
   - Social rows in organise mode get an empty handle column. They are not draggable today, and the spec pins that.
   - The Mastodon hashtag divider and the profile-avatar stack on the people row are not built.
   - The "7 more feeds" row is not built, because tag feeds are not truncated today.
9. **Bold labels.** The mockup bolds All items, News de and Lokales. The plan bolds the active row and every open tag.
10. **The menu-open row tint is left out.** The mockup gives a row with an open menu 75% `--surface-1`; in the plan only the ⋯ button's fill marks the open menu, which avoids cross-component `:has()`.
11. **Saved searches are left out of organise mode, per the board.** Their component unmounts, so the expanded state resets after organising.
12. **The update badge moves into the version · Feedback row.** This reverses the earlier spec "keeps the update badge out of the version and feedback row".
13. **The search field's glyph stays 16px.** The field is shared with the phone header bar; the mockup has 18px.
14. **The inner box has no `overflow: hidden`.** It would clip row menus.
15. **The eye glyph is about 10.2px**, not 10px: the `text` size (0.85em) inside a 12px badge.
