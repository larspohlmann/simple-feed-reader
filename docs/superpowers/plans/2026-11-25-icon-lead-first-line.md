# Icon Lead on the First Line Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Every icon in front of text that can wrap sits centred on the text's first line, and a boxed note's side padding and icon gap come from the font (`(1lh - 1cap) / 2`), not a fixed token.

**Architecture:** One Sass module, `frontend/src/app/theme/_icon-lead.scss`, holds the rule as mixins. The row measures its own line once (`--icon-lead-line: 1lh`) into a registered `<length>` custom property, so it inherits as px and the icon slot — which has its own font size — reads the text's line, not its own. Each in-scope surface's stylesheet includes the mixin; templates do not change.

**Tech Stack:** Angular 20, Dart Sass, Jest (Sass compile tests via `src/app/theme/sass-testing.ts`), Playwright.

**Spec:** issue #2558 (https://github.com/larspohlmann/simple-feed-reader/issues/2558) and the survey recorded under "Surfaces" below.

## Global Constraints

- The formula, verbatim: `--px: calc(var(--py) + (1lh - 1cap) / 2)`; the row is `align-items: start`; the icon box is `1lh` tall with the glyph centred in it.
- `1lh` and `1cap` are measured on the **row**, which must carry the first line's `font-size`, `font-family` and `line-height`. An icon slot never measures `lh` itself (its own font size would give the icon's line, not the text's).
- Single-line controls (buttons, sidebar rows, pills, chips, ellipsised or `nowrap` rows) are out of scope and unchanged.
- No template changes. Component styles stay in their sibling `.scss`.
- No hex colours, no ad-hoc `px` spacing, no media-query literals outside `src/app/theme/` (Stylelint). `0px` (never bare `0`) inside `calc()` and `@property` initial values.
- Browser floor for the units: `cap` Safari 17.2 / Chrome 118 / Firefox 97; `lh` Safari 16.4; `@property` Safari 16.4 / Firefox 128. No fallback (ruling below).
- Run every Jest command in the Docker `frontend` container: `docker compose exec -T frontend npx jest <path>`.
- Gate cadence (CLAUDE.md "Testing"): per task, only the spec(s) covering the change plus a look at the real render; no `npm run check` per task. The full frontend set runs once before the PR.
- Commits: `type(#2558): lower-case summary`, no attribution lines.

## Rulings (decided, recorded with the reason)

- **`box` only on `app-warning-box`.** It is the one free-standing boxed note. The email callouts, the Gmail hint and the AI connection row are bands inside a settings card; their `--panel-inset-x` lines their left edge up with the card's other rows, and that alignment wins over the formula's side padding. They get `first-line` only.
- **Warning boxes with no glyph** (social search empty, import truncated, unhealthy-feed mono error) take the new padding too: the formula is about the box, not the icon, and one box should not have two paddings.
- **Out of scope:** `app-feed-intro` (a 24px feed logo beside a `line-height: 1` title — a logo, governed by `--feed-logo-max-h`), favicons inline in a run of text (source-group heading, add-feed preview meta: the `text`-size rule covers those), the story-sources thumbnail tile, the "Also in" and feed-directory chip rows, `app-error-banner` (no icon).
- **No `cap` fallback.** Older Safari than 17.2 drops the `--icon-lead-band` declaration; `box` then falls back to its plain `$inset` on all sides and `first-line` is unaffected. That degradation is acceptable.

## Proposed rulings — confirm before execution

- **P1. Spacing follows the formula only at a free edge.** Where an icon leads from a box edge or a fixed/min-height row whose edge nothing else lines up with, padding and gap come from the formula. Where the edge is shared (the pane bar's `--space-4` lines the list-header lead up with the entry rows; settings-card bands line up with the card's rows), the edge stays and only the alignment and gap follow. A lockup not at an edge (the brand, the h2 lead, the bar nameplate, list actions) keeps its token gap and takes the first-line alignment only.
- **P2. The centre target is the line box, not the x-height.** `1lh` centring puts the icon on the line's middle, which is about the cap middle. Today's optical nudges aim at the x-height (`h2 > app-icon { transform: translateY(-0.1em) }`, the sidebar label's `top: 0.15em`, the byline favicon's `bottom: 0.15em`). They go where the rule applies, and the classic theme's alignment bound in `design-language.md` (x-height centre within 0.25px of graphite's) is restated as "icon centre on the line centre".
- **P3. The sidebar's leads jog by row height.** Per-row spacing makes a 36px row's lead sit ≈2px further in than a 32px row's, so the Tags card's leads and the Feeds card's leads no longer share one x. That is the formula's consequence, accepted with "spacing from formula".
- **P4. Shared rows change everywhere.** Every tag pill (user ruling), the entry pills (`entry-pills`, also in list rows) and `theme/_menu-items.scss` (every menu, including the share menu and the action sheet) take the rule once, for every consumer, not only in the header.
- **P5. Out of the header scope:** the article byline's favicon (R4), which sits inside a run of text and stays with the `text`-size rule.

## Review Focus

1. **Text scale.** At the largest text size (`.text-scaled`, Settings → text size) the icon must still centre on the first line: `1lh` scales with the text, and the slot reads the px value. Pinned by the e2e in Task 4 (run once at the largest scale).
2. **Classic theme.** Newsreader's cap height differs from the system face; `1cap` must follow it. Pinned by running the Task 4 e2e under classic as well as graphite.
3. **Icon taller than the line** (settings-group chip ≈ 32px, AI connection dot): the slot centres it on the first line and it overhangs the line box symmetrically; it must not push the text down or clip. Pinned by the settings-group assertion in Task 4.
4. **One-line rows with a min-height** (action-sheet items, tag options): `align-items: start` alone would pin a single line to the top of a 44px row. `centred-line` must keep a single line vertically centred. Pinned in Task 3's render check and Task 4.
5. **Trailing controls** in a first-line row (Discover's count and Select all, the AI row's Manage, the email callout's button) stay centred on the row (`align-self: center`), not hung on the first line. Checked in Task 3's render check.

## Surfaces

`F` = `first-line`, `B` = `box`, `C` = also `centred-line`. "Row font" = what the row must carry so `1lh`/`1cap` match the first line.

| # | Surface | Stylesheet | Mixin | Slot selector | Row font |
|---|---|---|---|---|---|
| 1 | Warning box (paywall, throttled comments, reader fallback, notification reconnect hint, glyph-less consumers) | `shared/warning-box/warning-box.component.scss` (`:host`) | B, `$inset: var(--space-3)` | `> .warning-glyph, > app-icon` | inherited from the consumer (already the text's) |
| 2 | Email callouts ×4 | `settings/account/email-section.component.scss` `.callout` | F | `> app-icon` | inherited (text span inherits) |
| 3 | Gmail app-password hint | `settings/admin/mail/mail-section.component.scss` `.gmail-hint` | F | `> app-icon` | inherited |
| 4 | Settings group head | `shared/settings/settings-group/settings-group.component.scss` `.g-head` | F | `> .g-icon` | move `.g-title`'s `font-size`/`font-weight`/`letter-spacing` to `.g-head`; `.g-caption` keeps its own `font-size` |
| 5 | Feed-health error dialog hero | `settings/organise/health/health-error-dialog.component.scss` `.hero` | F | `> .hero-icon` (drop its `margin-top`) | `.hero-code`'s `font-family`/`font-size`/`line-height` on `.hero`; `.gloss` states its own |
| 6 | Notification row | `reader/notifications/notification-row/notification-row.component.scss` `.row` (grid) | F | `> .kind` (drop its `padding-top`; `place-items: center`) | `font-size: var(--fs-base); line-height: 1.35` on `.row`, removed from `.line`; every other child keeps its computed font |
| 7 | Trending story headline | `reader/trending/story-headline/story-headline.component.scss` `:host` | F | `> .icon` | `font-size: var(--fs-base); line-height: var(--lh-tight)` on `:host` |
| 8 | Composer kind-note, fixed-url | `reader/social/composer/_composer-notes.scss` mixin `note` | F | `> app-network-logo, > app-icon` | already on the note (`--fs-sm`) |
| 9 | Action-sheet item with hint | `shared/action-sheet/action-sheet.component.scss` `nav button` (not the shared `_menu-items.scss`) | F + C | `> app-icon, > app-masked-logo` | already `--fs-base`; `.labelled` gets `flex: 1 1 0; min-width: 0` |
| 10 | AI connection row | `settings/ai/ai-section.component.scss` `.conn` | F | `> .conn-dot` | the label's font on `.conn`; Manage button `align-self: center` |
| 11 | Tag-picker option and Create row | `reader/feeds/tag-picker/tag-option-list.component.scss` `.option` | F + C | `> app-tag-glyph, > app-icon` | already the name's; `.name` gets `flex: 1 1 0; min-width: 0` |
| 12 | Health card heads (organise, mail) | `settings/organise/organise-section.component.scss`, `settings/admin/mail/mail-section.component.scss` `.health-head` | F | `> app-icon` | title's font on `.health-head`; counts keep `--fs-sm`; count pill `align-self: center` |
| 13 | Discover category head | `discover/discover.component.scss` `.cat-head` | F | `> .cat-ico` | `h2`'s `font-size` on `.cat-head`; count and buttons `align-self: center` |
| 14 | Sidebar trial notice | `reader/shell/sidebar/sidebar-foot.component.scss` `.trial` | F | `> app-icon` | already `--fs-sm` |

Paths are relative to `frontend/src/app/`. Settings-group also gets `.g-text { flex: 1; min-width: 0 }` and `.g-head` loses `flex-wrap: wrap`, so a long caption no longer drops the whole text block under the chip (boy-scout fix found in the survey; commit it on its own).

---

### Task 1: The `icon-lead` module

**Files:**
- Create: `frontend/src/app/theme/_icon-lead.scss`
- Create: `frontend/src/app/theme/icon-lead.spec.ts`
- Modify: `frontend/src/styles.scss` (include `register` once, after the `@use` block)
- Modify: `frontend/src/app/shared/warning-box/warning-box.component.scss` (surface 1, the first consumer)

**Interfaces:**
- Produces:
  - `@mixin register` — emits `@property --icon-lead-line { syntax: '<length>'; inherits: true; initial-value: 0px; }`.
  - `@mixin first-line($slot)` — on the row: `--icon-lead-line: 1lh; align-items: start;` and a nested `#{$slot}` rule: `display: flex; flex: none; align-items: center; justify-content: center; block-size: var(--icon-lead-line);`.
  - `@mixin box($inset, $slot)` — `first-line($slot)` plus `--icon-lead-band: calc((1lh - 1cap) / 2); padding: #{$inset} calc(#{$inset} + var(--icon-lead-band)); gap: calc(#{$inset} + var(--icon-lead-band));`.
  - `@mixin centred-line` — `flex-wrap: wrap; align-content: center;` (a single line stays centred in a row taller than it; the text item must be `flex: 1 1 0; min-width: 0` so it never wraps under the icon).
  - `@mixin fixed-row($height, $slot)` — for a row with a fixed or minimum height and no block padding: `first-line($slot)` plus `--icon-lead-space: calc((#{$height} - 1cap) / 2); padding-inline-start: var(--icon-lead-space); gap: var(--icon-lead-space);`. (`py = (H − 1lh) / 2`, so `py + (1lh − 1cap) / 2 = (H − 1cap) / 2`.) Add its assertions to the Step 1 probe: `.fixed { @include icon-lead.fixed-row(var(--sidebar-row-h), '> .glyph'); }` → `--icon-lead-space: calc((var(--sidebar-row-h) - 1cap) / 2)`, `padding-inline-start` and `gap` both `var(--icon-lead-space)`.

- [ ] **Step 1: Write the failing test** `icon-lead.spec.ts`, in the style of `glass-rim.spec.ts` (`compileScssSource(PROBE, [__dirname])`, `rulesBySelector`). Probe: `@use 'icon-lead'; @include icon-lead.register; .row { @include icon-lead.first-line('> .glyph'); } .note { @include icon-lead.box(var(--space-3), '> .glyph'); } .item { @include icon-lead.centred-line; }`. Assertions:
  - the compiled CSS contains `@property --icon-lead-line` with `syntax: "<length>"`, `inherits: true`, `initial-value: 0px`;
  - `.row` → `--icon-lead-line: 1lh`, `align-items: start`;
  - `.row > .glyph` → `block-size: var(--icon-lead-line)`, `align-items: center`, `flex: none`;
  - `.note` → `padding: var(--space-3) calc(var(--space-3) + var(--icon-lead-band))`, `gap: calc(var(--space-3) + var(--icon-lead-band))`, `--icon-lead-band: calc((1lh - 1cap) / 2)`;
  - `.item` → `flex-wrap: wrap`, `align-content: center`.
  (`rulesBySelector` skips at-rules; assert the `@property` block with a regex on the raw CSS.)

- [ ] **Step 2: Run it, expect FAIL** — `docker compose exec -T frontend npx jest src/app/theme/icon-lead.spec.ts` → "Can't find stylesheet to import".

- [ ] **Step 3: Implement `_icon-lead.scss`** with the four mixins above. One short header comment only: why the line is a registered `<length>` (the slot's own font would otherwise resolve `1lh`). Include `icon-lead.register` in `styles.scss`.

- [ ] **Step 4: Run it, expect PASS.**

- [ ] **Step 5: Apply to `warning-box`** — replace `gap`, `align-items: baseline` and `padding` on `:host` with `@include icon-lead.box(var(--space-3), '> .warning-glyph, > app-icon')`; keep `display: flex`, the rim and the radius. Update the component's comment to the new behaviour in one line. Remove `align-self: flex-start` from `.hint-glyph` in `reader/notifications/notification-list/notification-list.component.scss` (the slot rule owns it now).

- [ ] **Step 6: Prove the mechanism on the real render** (the registered property is the one thing a compile test cannot show). On `http://localhost:4200` (Docker stack up), open an article with a paywall notice or stub one, narrow the viewport to 375px so the note wraps, and read via `getComputedStyle`: the `.warning-glyph`'s `block-size` equals the host's computed `line-height` in px (not the glyph's own), and the glyph's rect centre is within 0.5px of the first text line's centre (`document.createRange()` over the first text node, `getClientRects()[0]`). Screenshot it.
  Expected: block-size = host line-height px; |Δcentre| ≤ 0.5px.

- [ ] **Step 7: Commit** — `feat(#2558): icon-lead mixin centres a lead icon on the first line`.

### Task 2: Settings and dialog surfaces (2, 3, 4, 5, 10, 12)

**Files:** the stylesheets in rows 2, 3, 4, 5, 10, 12 of the Surfaces table.

**Interfaces:** Consumes `icon-lead.first-line($slot)` from Task 1 (`@use '<relative>/theme/icon-lead';`).

- [ ] **Step 1: Boy-scout commit first** — settings-group: `.g-text { flex: 1; min-width: 0; }`, drop `flex-wrap: wrap` from `.g-head`. Commit `fix(#2558): a long settings caption no longer drops under its icon`.
- [ ] **Step 2: Apply `first-line`** to each row with the slot and row-font move from the table; remove each surface's now-dead alignment (`align-items`, an icon `margin-top`/`padding-top` that faked the first-line position). Trailing controls named in the table get `align-self: center`.
- [ ] **Step 3: Run the existing specs of the touched components** — `docker compose exec -T frontend npx jest src/app/shared/settings src/app/settings/account src/app/settings/admin/mail src/app/settings/organise src/app/settings/ai` → PASS (no behaviour change expected; a red spec means a template-visible regression).
- [ ] **Step 4: Render check** at 1280px and 375px, graphite and classic: each surface's icon centre within 0.5px of its first line's centre (same Range measurement as Task 1 Step 6); a moved font declaration leaves every other child's computed `font-size`/`line-height` unchanged (compare before/after). Screenshot each surface at 375px.
- [ ] **Step 5: Commit** — `feat(#2558): settings notes and heads hang their icon on the first line`.

### Task 3: Reader surfaces (6, 7, 8, 9, 11, 13, 14)

**Files:** the stylesheets in rows 6, 7, 8, 9, 11, 13, 14 of the Surfaces table.

**Interfaces:** Consumes `icon-lead.first-line($slot)` and `icon-lead.centred-line` from Task 1.

- [ ] **Step 1: Apply** per the table, including `centred-line` and `flex: 1 1 0; min-width: 0` on the text item for rows 9 and 11, and `align-self: center` on trailing controls in row 13.
- [ ] **Step 2: Run the touched components' specs** — `docker compose exec -T frontend npx jest src/app/reader/notifications src/app/reader/trending src/app/reader/social/composer src/app/shared/action-sheet src/app/reader/feeds/tag-picker src/app/discover src/app/reader/shell/sidebar` → PASS.
- [ ] **Step 3: Render check** as Task 2 Step 4, plus: a one-line action-sheet item and a one-line tag option are vertically centred in their row (|text centre − row centre| ≤ 0.5px), and a two-line one has its icon on the first line.
- [ ] **Step 4: Commit** — `feat(#2558): reader rows hang their lead icon on the first line`.

### Task 4: Pin it, document it

**Files:**
- Create: `frontend/e2e/icon-lead-first-line.spec.ts`
- Modify: `docs/design-language.md` — a new `### Icon lead` subsection under `### Icon sizes`, and one line in `### <app-warning-box>` that its padding follows the font.

- [ ] **Step 1: Write the e2e**, owning its data like `e2e/icons-follow-text-size.spec.ts` (stubbed API reads, `presetLocalStorage`, the `e2e/support/icon-rows` stubs for notifications and trending). At 375×812, for: a settings-group head with a caption long enough to wrap, a notification row whose `.line` wraps, a trending story headline that wraps, and a paywall warning box that wraps — assert `|iconCentreY − firstLineCentreY| ≤ 0.5` (helper measuring the first line with a `Range`), and that the text block spans ≥ 2 lines (so the case is real). Run the whole spec under graphite, under classic, and once at the largest text scale.
- [ ] **Step 2: Break what it guards** — check out `develop`'s `warning-box.component.scss` and `settings-group.component.scss` (`git checkout develop -- <paths>`), run, expect FAIL on those two cases with the measured Δ quoted, then restore them (`git checkout HEAD -- <paths>`).
  Run: `cd frontend && npx playwright test e2e/icon-lead-first-line.spec.ts`
- [ ] **Step 3: Run it with the change, expect PASS** (all cases, three runs).
- [ ] **Step 4: Document** — `### Icon lead`: the rule (an icon in front of text that can wrap uses `icon-lead.first-line`; a free-standing boxed note uses `box`; a row with a min-height adds `centred-line`), the row-carries-the-first-line's-font requirement and why the line is a registered length, the formula, and the out-of-scope list from Rulings. Keep it as short as the `text` size rule above it.
- [ ] **Step 5: Commit** — `test(#2558): pin lead icons to the first line` and `docs(#2558): icon lead rule`.

### Task 5: Sidebar geometry from the formula

Every sidebar row's left inset and icon gap become `(row height − 1cap) / 2`, measured on that row; every indent that was built from the old `10px + 20px + 12px` is rebuilt from it. Paths are relative to `frontend/src/app/`.

**Files:**
- Modify: `theme/tokens.scss:155-168` — delete `--sidebar-row-inset` and `--sidebar-row-deep-inset`; add `--sidebar-label-x` (below).
- Modify: `reader/shell/sidebar/_sidebar-row.scss` — `row-geometry`, `section-head`, `airy-rail`, `.slot`, `row-menu`.
- Modify: every reader the survey listed: `sidebar.component.scss` (143, 149-162, 166, 178-180, 292-293, 353-359, 371-396), `sidebar-social-section.component.scss` (15-18, 34-36, 52-57, 61-62, 70, 78, 86, 105-125), `sidebar-saved-searches.component.scss` (11-22, 56-64, 71-82), `sidebar-followed-stories.component.scss` (19-30), `sidebar-feed-row.component.scss` (13-36), `sidebar-views.component.scss`, `sidebar-group-row.component.scss`, `search-field.component.scss` (83-102), `sidebar-foot.component.scss` `.trial`.
- Modify: `frontend/e2e/sidebar-airy-style.spec.ts` only if an assertion names a removed token's value; never loosen one.

**Interfaces:**
- Consumes: `icon-lead.fixed-row($height, $slot)`, `icon-lead.register` (Task 1).
- Produces:
  - `row-geometry` = `fixed-row(var(--row-h, var(--sidebar-row-h)), '> .lead, > app-icon')`, with `padding-inline-start: var(--row-inset, var(--icon-lead-space))` (an indented run overrides the start, never the gap). Right padding stays `var(--space-2)` (the ⋯ popover at `_sidebar-row.scss:265` depends on it). The coarse-pointer block sets only `height: var(--tap-target)`; the gap follows from it, so the `space-3 + space-0` override goes.
  - `@property --sidebar-label-x` (`<length>`, inherits) set on `:root` and on the sidebar root: `calc(var(--sidebar-row-h) - 1cap + var(--icon-md))` — where a top-level row's label starts (lead space + 20px lead + gap). Under `(pointer: coarse)` the same with `var(--tap-target)`. Registered so it computes in the top-level row's font, not the child row's.
  - Indented runs (tag feeds, social feed-kind feeds, the social divider) use `--row-inset: var(--sidebar-label-x)` in place of the deep inset.

**Invariants the render check measures** (each ±0.5px, at a fine and a coarse pointer, default and airy style, graphite and classic):
1. In every row, `lead.left − row.left` = `name.left − lead.right` = `(row height − cap height) / 2`, cap height read from the row's font.
2. A child run's lead starts at its parent row's label x (tag → its feeds; child tag → its feeds; social feed kind → its feeds).
3. A child tag's glyph starts at its parent tag's label x (rebuild the `.tag-child` box margin from `--sidebar-label-x`, its `--space-0` padding and the child row's own lead space).
4. The airy rail runs through the parent lead's centre: `left: calc(<parent lead space> + var(--icon-md) / 2)`.
5. A section head's lead (social head) or label follows its own formula, `(head height − 1cap) / 2`.
6. Every negative margin that cancelled the old `--space-3` gap (`.slot`, `row-menu :host`, `.digest-toggle`, social compose + bell) cancels `var(--icon-lead-space)` instead, so the trailing columns stay flush right.
7. The organise mode: the handle keeps its 40px; `.rowbody` and `.feedname` gaps take the formula on `--tap-target`; the feed placeholder margin (`sidebar.component.scss:394-396`, hand-tuned 64px) is rebuilt so it still starts at a feed row's name.
8. A drag preview (moved to `<body>`) renders with the same lead space as the row it left.
9. The sidebar search field takes `fixed-row` on its own height (`calc(var(--control-h) - var(--space-0))`); its hint's margin follows its lead space.

- [ ] **Step 1: Write the e2e** `frontend/e2e/sidebar-lead-space.spec.ts` (stubbed reads like `e2e/sidebar-airy-style.spec.ts`: a root tag with a child tag, feeds under each, a social section, saved searches) asserting invariants 1–5 at 1280×900 fine pointer, default and airy. Read the cap height with the unit itself: append a probe with `position: absolute; width: 1cap` inside the row, take `getComputedStyle(probe).width`, remove it.
- [ ] **Step 2: Run it on the branch before the change, expect FAIL** with the measured values quoted (today: inset 10, gap 12).
- [ ] **Step 3: Implement** the token, the mixin wiring and every reader listed above, rebuilding each dependency from the invariants.
- [ ] **Step 4: Run it, expect PASS**; also run `e2e/sidebar-airy-style.spec.ts` and every `frontend/e2e/*sidebar*.spec.ts` → PASS.
- [ ] **Step 5: Render check** at 375px coarse (`resize_window preset: mobile`) for invariants 6–9, and a drag of a feed for invariant 8. Screenshot the sidebar, default and airy, desktop and phone.
- [ ] **Step 6: Docs** — replace the two removed rows of the `### Sidebar geometry` table with `--sidebar-label-x`, and state the lead-space rule there in one line.
- [ ] **Step 7: Commit** — `feat(#2558): sidebar insets and icon gaps follow the font`.

### Task 6: Header icons

Paths are relative to `frontend/src/app/`. Each row cites the survey item; P1–P5 above decide edge vs gap.

| Item | Where | Mixin | Notes |
|---|---|---|---|
| A1 brand | `reader/shell/header/reader-header.component.scss` `.brand` | first-line, slot `> app-spinner` | gap stays `--space-2` (P1) |
| A2 header tag chips | same, `.chip` | `box(var(--space-1), '> app-tag-glyph')` | `.tagrow` padding unchanged (it feeds `--app-bar-h`) |
| A3/A4 account menu items | same, `.menu .item` | `fixed-row(var(--control-h), '> app-icon, > app-network-logo')` + `centred-line`; coarse: `var(--tap-target)` | `.label` gets `flex: 1 1 0; min-width: 0` |
| A5 account "who" | same, `.menu .who` | `box(var(--space-3), '> app-user-avatar')` | row font = `.who-email`'s (`--fs-sm`) |
| A6 search field | `reader/shell/search-field/search-field.component.scss` `.row` | `fixed-row(var(--control-h), '> app-icon, > app-spinner')` | the sidebar variant is Task 5 invariant 9 |
| L1 list-header lead | `reader/list/list-header/list-header.component.scss` `h2` | h2 becomes `display: flex` with first-line, slot `> app-tag-glyph, > app-favicon, > app-network-logo, > app-icon`; the title `span` carries the ellipsis (`min-width: 0; overflow: clip visible; text-overflow: ellipsis; white-space: nowrap`) | delete the four `translateY(-0.1em)` nudges and the leads' `margin-right` (P2); gap stays `--space-2`; pane-bar padding unchanged (P1). The search title and the collapsed story still wrap: their text is the flex item that wraps. Keep the collapsed story's `padding-block` derivation (`list-header.component.scss:210-213`) |
| L2 / R3 list actions | `styles/_list-action.scss` `.list-action` | first-line, slot `> app-icon, > app-pending-dots` | gap stays `--space-1` (P1); the compact icon-only box is unchanged |
| R1 mini nameplate | `reader/article/article-layer/article-layer.component.scss` `.mini` | `fixed-row(var(--reader-mini-h), '> app-favicon')` if nothing else lines up with its `--space-4` edge (check the toolbar hanging at `article-layer.component.scss:151`); otherwise first-line and gap only | remove the favicon's extra `margin-inline-end: 0.35em` here (it doubles the gap) |
| R2 bar nameplate | `reader/article/article-page/article-page.component.scss` `.bar-name` | first-line, slot `> app-favicon`; gap stays `--space-2` | remove the favicon's extra `0.35em` here too |
| R5 entry pills | `reader/entry/entry-pills/entry-pills.component.scss` `.pill` | `box(var(--space-0), '> app-network-logo, > app-tag-glyph, > app-icon')` | P4: list rows change with it |
| R6 listen row | `reader/article/listen-row/listen-row.component.scss` `.listen` | `box(var(--space-2), '> app-icon')` | |
| R7 reader panel toggle | `reader/article/reader-panel/reader-panel.component.scss` `.reader-panel__toggle` | `box(var(--space-2), '> app-icon')` unless the panel body lines up with its `--space-3` edge, then first-line only | |
| T1 tag pills, everywhere | `reader/feeds/tag-picker/tag-chips.component.scss` `.chip`, `settings/organise/bulk-tag-dialog.component.scss` (the tag choice buttons), `settings/organise/organise-feed-row.component.scss` `.pill`, `discover/category-chips.component.scss` (its chip), `admin/users/admin-user-detail.component.scss` `.chip` | `box(<the pill's current block padding>, '> app-tag-glyph')` | user ruling 2026-11-25: every tag pill is in, like the entry pills (R5) and header chips (A2); a trailing remove button or count keeps `align-self: center` |
| B1 icon + label buttons, everywhere | `shared/button/button.component.scss` (a leading icon), `reader/article/entry-comments/comment-actions.component.scss` (Reply, the vote pill, Add comment), `reader/article/social-counts/social-counts-row.component.scss`, and any other bordered/filled button that puts an icon before its label (find them; list each) | `box(<the button's current block padding>, <its icon slot>)`; a vote pill's arrows lead and trail its count — the count is centred between them on the line | user ruling 2026-11-25: every icon+label button is in; app-button changes for every consumer. User-reported: the classic ↶ Reply and ↑ 22 ↓ pill must sit on the line centre |
| R8 menus | `theme/_menu-items.scss` `[role='menuitem']` | `box(var(--row-pad-y), '> app-icon, > app-network-logo, > app-masked-logo, > app-share-target-logo')` | P4: every menu; Task 3's action-sheet row (9) then only adds `centred-line` |

- [ ] **Step 1: Extend the Task 4 e2e** with: a list-header lead (feed title, tag title, a search title long enough to wrap at 375px), an account-menu item, a menu item, and an entry pill — icon centre on the first line's centre ±0.5px; for the `box`/`fixed-row` items also `icon.left − box.left` = `text.left − icon.right` ±0.5px.
- [ ] **Step 2: Break what it guards** — check out `develop`'s `list-header.component.scss` and `_menu-items.scss`, run, expect FAIL with Δ quoted, restore.
- [ ] **Step 3: Implement** per the table.
- [ ] **Step 4: Run** the extended spec (graphite, classic, largest text scale) and `e2e/list-header-*.spec.ts`, `e2e/article-mini-header.spec.ts`, `e2e/icons-follow-text-size.spec.ts` → PASS. A changed `--list-bar-h` or `--app-bar-h` is a regression unless a padded box above changed on purpose; quote both before and after.
- [ ] **Step 5: Render check and screenshots** — app bar (desktop, phone with tag row), list header (feed, tag, search, collapsed story), article bar, mini strip, a menu, the account menu.
- [ ] **Step 6: Commit** — `feat(#2558): header icons sit on the first line`.
