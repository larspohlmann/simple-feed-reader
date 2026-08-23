# Settings Design System Rollout Implementation Plan (#547, #454)

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Every settings and admin section composes the shared "Grouped" primitives, group spacing has one owner that survives component host boundaries, and `app-settings-card` is deleted.

**Architecture:** Two primitive changes land first — a new `<app-settings-stack>` that owns the vertical rhythm between groups, and a `[groupActions]` slot on `<app-settings-group>`. Every section then swaps its `<app-settings-card>` shell for a stack of `<app-settings-group>`s, moving markup rather than rewriting it. `/settings/import` gains a page component so its two sections become siblings (#454). `app-settings-card` is deleted once the tree-wide grep is clean.

**Tech Stack:** Angular 20 standalone components + signals, Transloco i18n, SCSS with design tokens, Jest (jsdom) for unit tests, ESLint + Prettier + Stylelint.

**Spec:** `docs/superpowers/specs/2026-08-23-547-settings-design-system-rollout-design.md`

## Global Constraints

Read these once; every task's requirements implicitly include them.

- **Work from `frontend/`.** Every command in this plan assumes `cd frontend` first. All paths in **Files:** blocks are relative to `frontend/`.
- **Branch:** `feature/547-settings-design-system` (already created, off `develop`).
- **Commit format:** `type(#547): summary` — the issue number is the scope. Never a word scope, never trailing parens. The import task commits under `(#454)`.
- **No hex colours in `.scss` outside `src/app/theme/`.** No ad-hoc `px` spacing values, no media-query literals. All three fail `npm run check`. Use tokens (`--space-N`, `--radius`, `--fs-sm`) and `@use '../theme/breakpoints' as bp;`.
- **Component styles live in a sibling `.scss` file** via `styleUrl`, never inline in the `.ts`. Stylelint has no TS syntax installed, so inline styles are silently unlinted.
- **Standalone components only.** No NgModules. `changeDetection: ChangeDetectionStrategy.OnPush` on every new component.
- **Shared components take already-translated strings, not i18n keys.** `app-settings-group`'s `icon`, `title` and `caption` are resolved by the caller's `transloco` pipe.
- **Every new i18n key goes into BOTH `public/i18n/en.json` and `public/i18n/de.json`.** `src/app/core/i18n-dictionaries.spec.ts` fails the build on a key present in one dictionary and absent from the other, and on any empty value.
- **Add captions sparingly.** `app-settings-group`'s `caption` is optional. Only add one where suitable text already exists as a key that the conversion displaces. Do not invent caption copy just to fill the slot — every invented string costs two dictionary entries.
- **Behaviour does not change.** This is a composition change. Controls persist exactly when they persist today. A section spec that needs rewriting beyond its selectors is a signal the conversion changed something it should not have — stop and report rather than adjusting the assertion.
- **Run a single spec file** with `./node_modules/.bin/jest <path>` (not `npx jest` — a global babel-jest can shadow the local TS transform).
- **The full gate is `npm run check`** from `frontend/`: ESLint + Prettier + Stylelint + `tsc -p tsconfig.spec.json --noEmit` + Jest.

---

## File Structure

**New files:**

| File | Responsibility |
|---|---|
| `src/app/shared/settings/stack/settings-stack.component.ts` | The stack primitive: a flex column owning the one canonical gap between groups |
| `src/app/shared/settings/stack/settings-stack.component.html` | One `<ng-content />` |
| `src/app/shared/settings/stack/settings-stack.component.scss` | `:host` flex column, `gap: var(--space-7)`, `min-width: 0` |
| `src/app/shared/settings/stack/settings-stack.component.spec.ts` | Projection + host-boundary spacing contract |
| `src/app/settings/import-section.component.ts` | Route component for `/settings/import`; renders OPML and backup as siblings |
| `src/app/settings/import-section.component.html` | One stack, two sibling sections |
| `src/app/settings/import-section.component.spec.ts` | Both children render, as siblings |

**Deleted files (Task 13):**

| File | Why |
|---|---|
| `src/app/shared/settings-card/settings-card.component.ts` | Nothing composes it any more |
| `src/app/shared/settings-card/settings-card.component.html` | ” |
| `src/app/shared/settings-card/settings-card.component.scss` | ” |
| `src/app/shared/settings-card/settings-card.component.spec.ts` | ” |

**Modified:** the two primitives (`settings-group`, `settings-row` docs), eleven section components with their templates, stylesheets and specs, `settings.routes.ts`, `src/styles/_base.scss`, `docs/design-language.md`, both i18n dictionaries.

---

## Task 1: The `app-settings-stack` primitive

**Files:**
- Create: `src/app/shared/settings/stack/settings-stack.component.ts`
- Create: `src/app/shared/settings/stack/settings-stack.component.html`
- Create: `src/app/shared/settings/stack/settings-stack.component.scss`
- Test: `src/app/shared/settings/stack/settings-stack.component.spec.ts`
- Modify: `docs/design-language.md` (add an entry under "Settings design system")

**Interfaces:**
- Consumes: nothing.
- Produces: `SettingsStackComponent`, selector `app-settings-stack`, no inputs, no outputs. Every later task imports it from `'../shared/settings/stack/settings-stack.component'` (settings sections), `'../../shared/settings/stack/settings-stack.component'` (`admin/`) or `'../../../shared/settings/stack/settings-stack.component'` (`settings/admin/*`).

- [ ] **Step 1: Write the failing test**

Create `src/app/shared/settings/stack/settings-stack.component.spec.ts`:

```ts
import { Component } from '@angular/core';
import { TestBed } from '@angular/core/testing';
import { SettingsStackComponent } from './settings-stack.component';

@Component({
  imports: [SettingsStackComponent],
  template: `
    <app-settings-stack>
      <div data-first>one</div>
      <div data-second>two</div>
    </app-settings-stack>
  `,
})
class HostComponent {}

describe('SettingsStackComponent', () => {
  async function render() {
    await TestBed.configureTestingModule({ imports: [HostComponent] }).compileComponents();
    const fixture = TestBed.createComponent(HostComponent);
    fixture.detectChanges();
    return fixture.nativeElement as HTMLElement;
  }

  it('projects every child', async () => {
    const el = await render();

    expect(el.querySelector('app-settings-stack [data-first]')?.textContent).toBe('one');
    expect(el.querySelector('app-settings-stack [data-second]')?.textContent).toBe('two');
  });

  // The gap lives on the stack host, not on the children, so a child that is
  // another component's host element is spaced exactly like an inline one.
  // That is the whole point of the primitive: `app-settings-card +
  // app-settings-card` was a sibling selector and died at a host boundary
  // (#454). Asserting the children carry no spacing of their own is what stops
  // a future compensating margin from creeping back in.
  it('keeps its children free of their own spacing', async () => {
    const el = await render();
    const first = el.querySelector<HTMLElement>('[data-first]')!;

    expect(first.style.marginBlockStart).toBe('');
    expect(first.style.marginTop).toBe('');
  });
});
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `./node_modules/.bin/jest src/app/shared/settings/stack/settings-stack.component.spec.ts`
Expected: FAIL — `Cannot find module './settings-stack.component'`.

- [ ] **Step 3: Write the component**

Create `src/app/shared/settings/stack/settings-stack.component.ts`:

```ts
import { ChangeDetectionStrategy, Component } from '@angular/core';

/**
 * The vertical rhythm of a settings or admin page: a column that stacks
 * `<app-settings-group>`s with the one canonical gap between them.
 *
 * The gap belongs here, on a flex container, rather than in a global adjacent-
 * sibling rule. `app-settings-card + app-settings-card` in `_base.scss` was
 * such a rule, and it stopped firing the moment one card was rendered from
 * inside another component -- the child then had to carry a compensating
 * margin (#454). A stack's children are flex items, so a child that happens to
 * be another component's host element is spaced identically to a group written
 * inline, and no consumer ever compensates.
 *
 * `min-width: 0` is on the host because it is a flex item of the settings
 * shell's own column, and a flex item's `min-width` defaults to `auto` -- which
 * refuses to shrink below its content's intrinsic width. Without it a wide
 * descendant widens the whole page instead of scrolling inside its own
 * container (#409).
 */
@Component({
  selector: 'app-settings-stack',
  templateUrl: './settings-stack.component.html',
  styleUrl: './settings-stack.component.scss',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class SettingsStackComponent {}
```

Create `src/app/shared/settings/stack/settings-stack.component.html`:

```html
<ng-content />
```

Create `src/app/shared/settings/stack/settings-stack.component.scss`:

```scss
// src/app/shared/settings/stack/settings-stack.component.scss
// The column that stacks a page's groups. `--space-7` is the gap #541 settled
// on for the AI settings page; it is the rhythm every settings page now uses.
:host {
  display: flex;
  flex-direction: column;
  gap: var(--space-7);
  min-width: 0;
}
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `./node_modules/.bin/jest src/app/shared/settings/stack/settings-stack.component.spec.ts`
Expected: PASS, 2 tests.

- [ ] **Step 5: Document the primitive**

In `docs/design-language.md`, insert this section immediately **before** the `### \`<app-settings-group>\`` heading (so the stack is introduced before the thing it stacks):

```markdown
### `<app-settings-stack>`

The vertical rhythm of one settings or admin page: a flex column that stacks the
page's groups with the one canonical gap. It is the template root of every
settings and admin route component, and it takes no inputs.

```html
<app-settings-stack>
  <app-settings-group …>…</app-settings-group>
  <app-settings-group …>…</app-settings-group>
</app-settings-stack>
```

**Why a container and not an adjacent-sibling rule.** The gap it replaces was
`app-settings-card + app-settings-card` in `src/styles/_base.scss`. A sibling
selector cannot cross a component host boundary, so the moment one card was
rendered from inside another component the gap vanished and the child carried a
compensating `margin-block-start` (#454). A stack's children are flex items, so
a child that is another component's host element is spaced identically to a
group written inline. **A section must never carry spacing to sit correctly in a
stack** — if it seems to need one, the stack is missing, not the margin.

`min-width: 0` on the host keeps a wide descendant (a scrolling table, the #409
run-history grid) from widening the page instead of scrolling inside its own
container.
```

- [ ] **Step 6: Run the gate**

Run: `npm run check`
Expected: PASS.

- [ ] **Step 7: Commit**

```bash
git add src/app/shared/settings/stack ../docs/design-language.md
git commit -m "feat(#547): app-settings-stack owns the gap between settings groups"
```

---

## Task 2: Extend the group and row primitives for the rollout

Two sections of the rollout need something the primitives do not offer yet: a header action (`tags-section`, `admin-catalog`, `admin-user-detail` all project into `app-settings-card`'s `cardActions` today) and an inline badge after a row title (`preferences-section`).

**Files:**
- Modify: `src/app/shared/settings/settings-group/settings-group.component.html`
- Modify: `src/app/shared/settings/settings-group/settings-group.component.scss`
- Modify: `src/app/shared/settings/settings-group/settings-group.component.ts` (doc comment only)
- Modify: `src/app/shared/settings/settings-row/settings-row.component.ts` (doc comment only)
- Test: `src/app/shared/settings/settings-group/settings-group.component.spec.ts`
- Test: `src/app/shared/settings/settings-row/settings-row.component.spec.ts`
- Modify: `docs/design-language.md`

**Interfaces:**
- Consumes: nothing.
- Produces: a named slot `<ng-content select="[groupActions]">` on `app-settings-group`, projected into `.g-head`. Consumers mark one element with the bare attribute `groupActions`. The `[rowTitleTip]` slot on `app-settings-row` is unchanged in code; its documented contract widens to "an info-tip **or** a small badge".

- [ ] **Step 1: Write the failing tests**

Append to `src/app/shared/settings/settings-group/settings-group.component.spec.ts` — add these two host components after `NoCaptionHostComponent`:

```ts
@Component({
  imports: [SettingsGroupComponent],
  template: `
    <app-settings-group icon="sell" title="Tags">
      <button groupActions data-action>New tag</button>
      <div data-projected>row</div>
    </app-settings-group>
  `,
})
class ActionsHostComponent {}
```

and these two tests inside the `describe`:

```ts
it('projects a groupActions element into the header, not the panel', async () => {
  await TestBed.configureTestingModule({ imports: [ActionsHostComponent] }).compileComponents();
  const fixture = TestBed.createComponent(ActionsHostComponent);
  fixture.detectChanges();
  const el = fixture.nativeElement as HTMLElement;

  expect(el.querySelector('.g-head [data-action]')).not.toBeNull();
  expect(el.querySelector('.panel [data-action]')).toBeNull();
});

it('still projects the body into the panel when actions are present', async () => {
  await TestBed.configureTestingModule({ imports: [ActionsHostComponent] }).compileComponents();
  const fixture = TestBed.createComponent(ActionsHostComponent);
  fixture.detectChanges();
  const el = fixture.nativeElement as HTMLElement;

  expect(el.querySelector('.panel [data-projected]')?.textContent).toBe('row');
});
```

Append to `src/app/shared/settings/settings-row/settings-row.component.spec.ts` a host and a test proving the slot takes a badge, not only an info-tip:

```ts
@Component({
  imports: [SettingsRowComponent],
  template: `
    <app-settings-row title="Scraping">
      <span rowTitleTip class="badge" data-badge>Experimental</span>
      <button data-control>on</button>
    </app-settings-row>
  `,
})
class BadgeHostComponent {}
```

```ts
// The slot is positional, not typed: it is "an inline adornment after the
// title". An info-tip was its first consumer; the Experimental badge on the
// preferences page is its second (#547).
it('places a badge in the title slot, after the title text', async () => {
  await TestBed.configureTestingModule({ imports: [BadgeHostComponent] }).compileComponents();
  const fixture = TestBed.createComponent(BadgeHostComponent);
  fixture.detectChanges();
  const el = fixture.nativeElement as HTMLElement;

  expect(el.querySelector('.row-title [data-badge]')?.textContent).toBe('Experimental');
  expect(el.querySelector('.row-control [data-control]')).not.toBeNull();
});
```

Check the top of `settings-row.component.spec.ts` before writing: if it does not already import `Component` from `@angular/core`, add it.

- [ ] **Step 2: Run the tests to verify they fail**

Run: `./node_modules/.bin/jest src/app/shared/settings`
Expected: the two group tests FAIL (the action lands in `.panel`, because with no matching `select` it falls through to the default `<ng-content />`). The row badge test may already PASS — the slot exists; the test pins the widened contract so a later refactor cannot narrow it.

- [ ] **Step 3: Add the slot to the group template**

Replace `src/app/shared/settings/settings-group/settings-group.component.html` with:

```html
<div class="g-head">
  <span class="g-icon"><app-icon [name]="icon()" size="sm" /></span>
  <div class="g-text">
    <h2 class="g-title">{{ title() }}</h2>
    @if (caption()) {
      <p class="g-caption">{{ caption() }}</p>
    }
  </div>
  <div class="g-actions"><ng-content select="[groupActions]" /></div>
</div>
<div class="panel"><ng-content /></div>
```

- [ ] **Step 4: Style the slot**

In `src/app/shared/settings/settings-group/settings-group.component.scss`, change the `.g-head` rule to allow wrapping and append the `.g-actions` rule:

```scss
.g-head {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: var(--space-3);
  margin-bottom: var(--space-4);
  padding: 0 var(--space-1);
}
```

```scss
// The header's action slot. `margin-left: auto` pushes it to the trailing edge
// on a wide header; when the actions do not fit, `.g-head`'s `flex-wrap` drops
// them onto their own line rather than crushing the title -- the admin user
// queue projects four status filters in here.
.g-actions {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: var(--space-2);
  margin-left: auto;
}

// An empty slot must not claim the row: with nothing projected the wrapper is
// still in the DOM, and a `gap` before a zero-width box would read as a stray
// trailing space on every group that projects no actions.
.g-actions:empty {
  display: none;
}
```

- [ ] **Step 5: Run the tests to verify they pass**

Run: `./node_modules/.bin/jest src/app/shared/settings`
Expected: PASS, all group, row, stack and save-bar tests.

- [ ] **Step 6: Update the two doc comments**

In `settings-group.component.ts`, append to the class doc comment:

```
 * A `[groupActions]` slot in the header takes one trailing element -- a "New"
 * button, a filter group. Angular's content projection only matches a *direct*
 * child of the component (one `@if` level deep is tolerated, two silently drop
 * the content into the default slot instead), so keep the marked element at the
 * top of the group's content.
```

In `settings-row.component.ts`, replace the sentence describing `rowTitleTip` so it reads:

```
 * A named `[rowTitleTip]` slot places an inline adornment immediately after the
 * title text inside `.row-title` -- an `<app-info-tip>`, which was its first
 * consumer, or a small badge such as the preferences page's "Experimental"
 * chip. The slot is positional, not typed.
```

- [ ] **Step 7: Document both in the design language**

In `docs/design-language.md`, under `### \`<app-settings-group>\``, append after the existing prose:

```markdown
A named `<ng-content select="[groupActions]">` slot sits at the trailing edge of
the header and takes one element — a "New" button, a filter group. The header
wraps, so actions that do not fit drop to their own line instead of crushing the
title. Projection matches only a **direct** child of the group (one `@if` deep
is tolerated, two silently fall through to the panel), so keep the marked
element at the top of the group's content.
```

Under `### \`<app-settings-row>\``, replace the sentence beginning "A named `<ng-content select="[rowTitleTip]">` slot places an info-tip…" with:

```markdown
A named `<ng-content select="[rowTitleTip]">` slot places an inline adornment
immediately after the title text inside `.row-title` — an `<app-info-tip>`, or a
small badge such as the preferences page's "Experimental" chip. The slot is
positional, not typed.
```

- [ ] **Step 8: Run the gate**

Run: `npm run check`
Expected: PASS.

- [ ] **Step 9: Commit**

```bash
git add src/app/shared/settings ../docs/design-language.md
git commit -m "feat(#547): group header action slot, row title slot takes a badge"
```

---

## Task 3: Move the two Grouped pages onto the stack

`ai-section` and `proxy-section` already compose the primitives. Moving them first proves the stack against the pages the system was designed on, before nine more depend on it.

**Files:**
- Modify: `src/app/settings/ai-section.component.html:1` (the wrapper element)
- Modify: `src/app/settings/ai-section.component.ts` (imports)
- Modify: `src/app/settings/ai-section.component.scss` (delete `.groups`)
- Modify: `src/app/settings/admin/proxy/proxy-section.component.html`
- Modify: `src/app/settings/admin/proxy/proxy-section.component.ts` (imports)

**Interfaces:**
- Consumes: `SettingsStackComponent` from Task 1.
- Produces: nothing new.

- [ ] **Step 1: Swap the AI section's wrapper**

In `src/app/settings/ai-section.component.html`, change the opening line `<div class="groups">` to `<app-settings-stack>` and the matching closing `</div>` at the end of the file to `</app-settings-stack>`. Nothing between them changes.

- [ ] **Step 2: Delete the replaced glue**

In `src/app/settings/ai-section.component.scss`, delete the whole `.groups` rule:

```scss
.groups {
  display: flex;
  flex-direction: column;
  gap: var(--space-7);
}
```

Then update the file's header comment: the sentence "What stays here is the column that stacks the groups, the ruled list of connection rows, and the internals of one connection row" loses its first clause, becoming "What stays here is the ruled list of connection rows and the internals of one connection row".

Leave the `:host { display: block; min-width: 0; }` rule and its long `#409` comment exactly as they are — the host is still a flex item of the shell's column and still needs `min-width: 0`.

- [ ] **Step 3: Register the import**

In `src/app/settings/ai-section.component.ts`, add to the imports:

```ts
import { SettingsStackComponent } from '../shared/settings/stack/settings-stack.component';
```

and add `SettingsStackComponent` to the component's `imports` array.

- [ ] **Step 4: Wrap the proxy section**

In `src/app/settings/admin/proxy/proxy-section.component.html`, wrap the whole existing template in a stack. The file currently starts `@if (svc.state()) {` — the stack goes **outside** that block so the page has a column even before state arrives:

```html
<app-settings-stack>
  @if (svc.state()) {
    <app-settings-group
```

…and close with `</app-settings-stack>` as the final line of the file. Indentation of everything in between shifts by two spaces; Prettier will settle it in Step 6.

In `src/app/settings/admin/proxy/proxy-section.component.ts`, add:

```ts
import { SettingsStackComponent } from '../../../shared/settings/stack/settings-stack.component';
```

and add `SettingsStackComponent` to the `imports` array.

- [ ] **Step 5: Run both section specs**

Run: `./node_modules/.bin/jest src/app/settings/ai-section.component.spec.ts src/app/settings/admin/proxy`
Expected: PASS, unchanged counts. These are behaviour specs; a failure here means the wrapper swap moved something it should not have.

- [ ] **Step 6: Run the gate**

Run: `npm run check`
Expected: PASS. If Prettier rewrites the two templates' indentation, that is expected — stage the result.

- [ ] **Step 7: Commit**

```bash
git add src/app/settings/ai-section.component.* src/app/settings/admin/proxy
git commit -m "refactor(#547): AI and proxy sections stack their groups with the shared primitive"
```

---

## Task 4: Convert `about-section`

One group, icon `info`. Each version line becomes an `app-settings-row`: the label is the row title, the version and commit detail are the projected control.

**Files:**
- Modify: `src/app/settings/about-section.component.html`
- Modify: `src/app/settings/about-section.component.ts`
- Modify: `src/app/settings/about-section.component.scss`
- Test: `src/app/settings/about-section.component.spec.ts:111`

**Interfaces:**
- Consumes: `SettingsStackComponent` (Task 1), `SettingsGroupComponent`, `SettingsRowComponent`.
- Produces: nothing new.

- [ ] **Step 1: Update the failing assertion**

In `src/app/settings/about-section.component.spec.ts`, replace line 111's assertion and rename the enclosing `it(...)` so it reads:

```ts
it('renders the section as a settings group', async () => {
  const { el } = await render();

  expect(el.querySelector('app-settings-group')).not.toBeNull();
});
```

(Keep whatever `render()` helper and surrounding lines the file already has — only the assertion and the test name change.)

- [ ] **Step 2: Run the spec to verify it fails**

Run: `./node_modules/.bin/jest src/app/settings/about-section.component.spec.ts`
Expected: FAIL — `expect(received).not.toBeNull()` on `app-settings-group`.

- [ ] **Step 3: Rewrite the template**

Replace `src/app/settings/about-section.component.html` with:

```html
<app-settings-stack>
  <app-settings-group icon="info" [title]="'settings.about.title' | transloco">
    @if (loading()) {
      <div class="loading">
        <app-spinner />
        <span>{{ 'settings.loadingVersion' | transloco }}</span>
      </div>
    } @else {
      @for (row of rows(); track row.labelKey) {
        <app-settings-row [title]="row.labelKey | transloco">
          @if (row.release; as release) {
            <span class="value">
              <code>{{ release.version }}</code>
              <span class="detail">
                {{ release.commit }}
                @if (release.builtAt) {
                  · {{ buildDate(release.builtAt) }}
                }
              </span>
            </span>
          } @else {
            <span class="value pending">
              {{
                (unavailable() ? 'settings.about.unavailable' : 'settings.about.loading') | transloco
              }}
            </span>
          }
        </app-settings-row>
      }

      @if (staleBundle()) {
        <p class="stale">{{ 'settings.about.stale' | transloco }}</p>
      }
    }
  </app-settings-group>
</app-settings-stack>
```

- [ ] **Step 4: Update the component imports**

In `src/app/settings/about-section.component.ts`, delete the `SettingsCardComponent` import line and add:

```ts
import { SettingsGroupComponent } from '../shared/settings/settings-group/settings-group.component';
import { SettingsRowComponent } from '../shared/settings/settings-row/settings-row.component';
import { SettingsStackComponent } from '../shared/settings/stack/settings-stack.component';
```

Change the `imports` array to:

```ts
  imports: [
    SettingsGroupComponent,
    SettingsRowComponent,
    SettingsStackComponent,
    SpinnerComponent,
    TranslocoPipe,
  ],
```

- [ ] **Step 5: Reduce the stylesheet to glue**

Replace `src/app/settings/about-section.component.scss` with:

```scss
// Layout glue only: the group chrome and the row line come from the shared
// primitives. `.row` and `.label` are gone -- `app-settings-row` draws both.
.loading {
  display: flex;
  align-items: center;
  gap: var(--space-2);
  padding: var(--space-4) var(--space-5);
  color: var(--text-muted);
}

.value {
  display: flex;
  align-items: baseline;
  gap: var(--space-2);
  flex-wrap: wrap;
  justify-content: flex-end;
}

.detail,
.pending {
  color: var(--text-muted);
  font-size: var(--fs-sm);
}

// The stale-bundle note trails the rows inside the panel, which pads its rows
// but not this paragraph.
.stale {
  margin: 0;
  padding: var(--space-3) var(--space-5) var(--space-4);
  color: var(--text-muted);
  font-size: var(--fs-sm);
}
```

- [ ] **Step 6: Run the spec to verify it passes**

Run: `./node_modules/.bin/jest src/app/settings/about-section.component.spec.ts`
Expected: PASS, unchanged test count.

- [ ] **Step 7: Check the divider gotcha**

`app-settings-row` draws its divider with `:host(:not(:last-child))`. When `staleBundle()` is true the `<p class="stale">` follows the last row, so that row is no longer `:last-child` and draws a trailing divider above the note. That reads correctly here — the note is separate content, and a rule above it is wanted. No change needed; this step is a deliberate look, not an edit.

- [ ] **Step 8: Run the gate and commit**

```bash
npm run check
git add src/app/settings/about-section.component.*
git commit -m "refactor(#547): about section composes the grouped primitives"
```

---

## Task 5: Convert `account-section`

Two groups: `person` "Account" and `warning` "Danger zone". The danger zone stops being a bordered block inside one card and becomes a group of its own.

**Files:**
- Modify: `src/app/settings/account-section.component.html`
- Modify: `src/app/settings/account-section.component.ts`
- Modify: `src/app/settings/account-section.component.scss`
- Test: `src/app/settings/account-section.component.spec.ts:78`
- Modify: `public/i18n/en.json`, `public/i18n/de.json`

**Interfaces:**
- Consumes: `SettingsStackComponent`, `SettingsGroupComponent`, `SettingsRowComponent`.
- Produces: i18n key `settings.account.dangerZone`.

- [ ] **Step 1: Add the one new i18n key to both dictionaries**

In `public/i18n/en.json`, inside the `settings.account` object, add:

```json
"dangerZone": "Danger zone",
```

In `public/i18n/de.json`, inside the same object, add:

```json
"dangerZone": "Gefahrenbereich",
```

- [ ] **Step 2: Update the failing assertion**

In `src/app/settings/account-section.component.spec.ts`, replace the assertion at line 78 and rename its test:

```ts
it('renders the account and the danger zone as separate settings groups', async () => {
  const f = await render();
  const el = f.nativeElement as HTMLElement;

  expect(el.querySelectorAll('app-settings-group').length).toBe(2);
});
```

(Use whatever render/mount helper the file already defines; only the assertion and name change.)

- [ ] **Step 3: Run the spec to verify it fails**

Run: `./node_modules/.bin/jest src/app/settings/account-section.component.spec.ts`
Expected: FAIL — received `0`, expected `2`.

- [ ] **Step 4: Rewrite the template**

Replace `src/app/settings/account-section.component.html` with:

```html
<app-settings-stack>
  @if (auth.user(); as u) {
    <app-settings-group icon="person" [title]="'settings.account.title' | transloco">
      <div class="who">
        <app-user-avatar [email]="u.email" [size]="48" />
        <span class="who-email">{{ u.email }}</span>
      </div>

      <app-settings-row [title]="'settings.account.memberSince' | transloco">
        <span class="value">{{ memberSince(u.createdAt) }}</span>
      </app-settings-row>

      <app-settings-row [title]="'settings.account.signOut' | transloco">
        <app-button (click)="auth.logout()">
          {{ 'settings.account.signOut' | transloco }}
        </app-button>
      </app-settings-row>
    </app-settings-group>

    <app-settings-group
      icon="warning"
      [title]="'settings.account.dangerZone' | transloco"
      [caption]="'settings.account.deleteNote' | transloco"
    >
      <app-settings-row [title]="'settings.account.delete' | transloco">
        <app-button variant="danger-outline" (click)="confirmThenDelete()">
          {{ 'settings.account.delete' | transloco }}
        </app-button>
      </app-settings-row>
      @if (deleteError(); as error) {
        <app-error-banner [message]="error.detail || error.title" />
      }
    </app-settings-group>
  }
</app-settings-stack>
```

The long `deleteNote` moves from a paragraph inside the card to the group's `caption`, which is exactly what a caption is for — it explains the group above its panel. No new key.

- [ ] **Step 5: Update the component imports**

In `src/app/settings/account-section.component.ts`, delete the `SettingsCardComponent` import and add:

```ts
import { SettingsGroupComponent } from '../shared/settings/settings-group/settings-group.component';
import { SettingsRowComponent } from '../shared/settings/settings-row/settings-row.component';
import { SettingsStackComponent } from '../shared/settings/stack/settings-stack.component';
```

Change the `imports` array to:

```ts
  imports: [
    ButtonComponent,
    ErrorBannerComponent,
    SettingsGroupComponent,
    SettingsRowComponent,
    SettingsStackComponent,
    TranslocoPipe,
    UserAvatarComponent,
  ],
```

- [ ] **Step 6: Reduce the stylesheet to glue**

Replace `src/app/settings/account-section.component.scss` with:

```scss
// Layout glue only. The `.grid` definition list, the `.signout` wrapper and the
// `.danger-zone` block are gone: those were a card's internal layout, and the
// rows and the second group now carry it.
.who {
  display: flex;
  align-items: center;
  gap: var(--space-3);
  padding: var(--space-4) var(--space-5);
  min-width: 0;
}

.who-email {
  font-weight: 600;
  color: var(--text-primary);
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.value {
  color: var(--text-primary);
}
```

- [ ] **Step 7: Run the spec to verify it passes**

Run: `./node_modules/.bin/jest src/app/settings/account-section.component.spec.ts`
Expected: PASS, unchanged test count.

- [ ] **Step 8: Run the gate and commit**

```bash
npm run check
git add src/app/settings/account-section.component.* public/i18n/en.json public/i18n/de.json
git commit -m "refactor(#547): account section splits into an account group and a danger zone"
```

---

## Task 6: Convert `preferences-section`

One group, `tune`. Two rows. The "Experimental" badge projects through `[rowTitleTip]` (Task 2).

**Files:**
- Modify: `src/app/settings/preferences-section.component.html`
- Modify: `src/app/settings/preferences-section.component.ts`
- Modify: `src/app/settings/preferences-section.component.scss`
- Test: `src/app/settings/preferences-section.component.spec.ts:29`

**Interfaces:**
- Consumes: `SettingsStackComponent`, `SettingsGroupComponent`, `SettingsRowComponent`, the `[rowTitleTip]` slot.
- Produces: nothing new.

- [ ] **Step 1: Update the failing assertion**

In `src/app/settings/preferences-section.component.spec.ts`, replace the assertion at line 29 and rename its test:

```ts
it('renders the section as a settings group', async () => {
  const { el } = await render();

  expect(el.querySelector('app-settings-group')).not.toBeNull();
});
```

Then add one test pinning the badge's new home, since it is the one piece of markup that moves rather than being wrapped:

```ts
it('shows the experimental badge beside the scraping row title', async () => {
  const { el } = await render();

  expect(el.querySelector('.row-title .badge')?.textContent?.trim()).toBe(en.settings.experimental);
});
```

If the spec file does not already import the English dictionary as `en`, copy the import line from `src/app/settings/tags-section.component.spec.ts`.

- [ ] **Step 2: Run the spec to verify it fails**

Run: `./node_modules/.bin/jest src/app/settings/preferences-section.component.spec.ts`
Expected: FAIL on both new assertions.

- [ ] **Step 3: Rewrite the template**

Replace `src/app/settings/preferences-section.component.html` with:

```html
<app-settings-stack>
  <app-settings-group icon="tune" [title]="'settings.preferences' | transloco">
    <app-settings-row [title]="'lang.label' | transloco" [stackable]="true">
      <app-language-switcher />
    </app-settings-row>
    @if (language.saveFailed()) {
      <app-error-banner [message]="'settings.languageSaveFailed' | transloco" />
    }

    <app-settings-row
      [title]="'settings.scraping' | transloco"
      [description]="'settings.scrapingHint' | transloco"
    >
      <span rowTitleTip class="badge">{{ 'settings.experimental' | transloco }}</span>
      <app-toggle
        inputId="scrape-fallback-toggle"
        [checked]="preferences.scrapeFallbackEnabled()"
        [label]="'settings.scraping' | transloco"
        (toggled)="preferences.setScrapeFallbackEnabled($event)"
      />
    </app-settings-row>
    @if (preferences.saveFailed()) {
      <app-error-banner [message]="'settings.scrapingSaveFailed' | transloco" />
    }
  </app-settings-group>
</app-settings-stack>
```

The old `<label class="setting-label" for="scrape-fallback-toggle">` is replaced by the row's own title, so the click-the-text-to-toggle affordance is lost. That is acceptable — `app-toggle` renders a native checkbox with its own label and `aria-label`, so keyboard and assistive-technology behaviour is unchanged, and the row title is not a control anywhere else in the system.

- [ ] **Step 4: Update the component imports**

In `src/app/settings/preferences-section.component.ts`, delete the `SettingsCardComponent` import and add:

```ts
import { SettingsGroupComponent } from '../shared/settings/settings-group/settings-group.component';
import { SettingsRowComponent } from '../shared/settings/settings-row/settings-row.component';
import { SettingsStackComponent } from '../shared/settings/stack/settings-stack.component';
```

Change the `imports` array to:

```ts
  imports: [
    ErrorBannerComponent,
    LanguageSwitcherComponent,
    SettingsGroupComponent,
    SettingsRowComponent,
    SettingsStackComponent,
    ToggleComponent,
    TranslocoPipe,
  ],
```

- [ ] **Step 5: Reduce the stylesheet to glue**

Replace `src/app/settings/preferences-section.component.scss` with:

```scss
// Layout glue only: `.row`, `.setting`, `.setting-label` and `.setting-hint`
// are gone -- `app-settings-row` draws the line, the title and the description.
// The badge is the one piece of chrome the primitives do not own; it rides the
// row's `[rowTitleTip]` slot.
.badge {
  padding: 0 var(--space-2);
  border: 1px solid var(--border);
  border-radius: var(--radius-pill);
  color: var(--text-secondary);
  font-size: var(--fs-xs);
  text-transform: uppercase;
}
```

- [ ] **Step 6: Run the spec to verify it passes**

Run: `./node_modules/.bin/jest src/app/settings/preferences-section.component.spec.ts`
Expected: PASS.

- [ ] **Step 7: Run the gate and commit**

```bash
npm run check
git add src/app/settings/preferences-section.component.*
git commit -m "refactor(#547): preferences section composes the grouped primitives"
```

---

## Task 7: Convert `tags-section`

One group, `sell`. "New tag" moves from `cardActions` to `groupActions`. The `cdkDropList` list and its stylesheet are untouched apart from the padding the card used to supply.

**Files:**
- Modify: `src/app/settings/tags-section.component.html`
- Modify: `src/app/settings/tags-section.component.ts`
- Modify: `src/app/settings/tags-section.component.scss`
- Test: `src/app/settings/tags-section.component.spec.ts:123-134`

**Interfaces:**
- Consumes: `SettingsStackComponent`, `SettingsGroupComponent`, the `[groupActions]` slot from Task 2.
- Produces: nothing new.

- [ ] **Step 1: Update the two failing assertions**

In `src/app/settings/tags-section.component.spec.ts`, replace the two tests at lines 123–134 with:

```ts
it('renders the section as a settings group', async () => {
  const { el } = await render();

  expect(el.querySelector('app-settings-group')).not.toBeNull();
});

// `groupActions` only projects from a direct child of the group: one `@if`
// level deep is tolerated, two silently drop the content into the panel body
// instead. Asserting the button lives inside `.g-head` -- not merely that it
// exists somewhere on the page -- is what catches that regression.
it('projects the New tag button into the group header', async () => {
  const { el } = await render();

  expect(el.querySelector('.g-head .new')).not.toBeNull();
});
```

- [ ] **Step 2: Run the spec to verify it fails**

Run: `./node_modules/.bin/jest src/app/settings/tags-section.component.spec.ts`
Expected: FAIL on both.

- [ ] **Step 3: Swap the shell in the template**

In `src/app/settings/tags-section.component.html`:

Replace the first two elements — the opening `<app-settings-card …>` tag and the `<app-button cardActions …>` block — with:

```html
<app-settings-stack>
  <app-settings-group icon="sell" [title]="'settings.tags.title' | transloco">
    <app-button groupActions class="new" size="sm" variant="primary" (click)="manage.createTag()">
      <app-icon name="add" size="sm" /> {{ 'settings.tags.new' | transloco }}
    </app-button>
```

Replace the closing `</app-settings-card>` with:

```html
  </app-settings-group>
</app-settings-stack>
```

Everything between is unchanged apart from indentation, which Prettier settles.

- [ ] **Step 4: Update the component imports**

In `src/app/settings/tags-section.component.ts`, delete the `SettingsCardComponent` import and add:

```ts
import { SettingsGroupComponent } from '../shared/settings/settings-group/settings-group.component';
import { SettingsStackComponent } from '../shared/settings/stack/settings-stack.component';
```

In the `imports` array, replace `SettingsCardComponent,` with `SettingsGroupComponent,` and `SettingsStackComponent,`.

Note there is no `SettingsRowComponent` here: the tag list is a list, not a stack of settings rows.

- [ ] **Step 5: Give the list the padding the card used to supply**

`app-settings-group`'s `.panel` is unpadded — rows pad themselves, and this list is not made of rows. At the top of `src/app/settings/tags-section.component.scss`, add:

```scss
// The group's panel is unpadded by design (its rows pad themselves), and this
// list is a list, not a stack of `app-settings-row`s -- so the inset the card
// used to supply lives here now.
.list,
.muted,
app-skeleton,
app-error-banner {
  display: block;
  padding: var(--space-4) var(--space-5);
}
```

Then check the rest of the file for a rule that assumed the card's own padding (a negative margin, a `margin: 0 calc(...)`) and remove it if present.

- [ ] **Step 6: Run the spec to verify it passes**

Run: `./node_modules/.bin/jest src/app/settings/tags-section.component.spec.ts`
Expected: PASS, unchanged test count.

- [ ] **Step 7: Verify drag and drop still works**

The tag list is a `cdkDropList`. Wrapping it in one more element must not nest it inside another drop list — check that no ancestor added by this task carries `cdkDropList`. It does not, but confirm by grep:

Run: `grep -n "cdkDropList" src/app/settings/tags-section.component.html`
Expected: exactly one match, on the `<ul class="list">`.

- [ ] **Step 8: Run the gate and commit**

```bash
npm run check
git add src/app/settings/tags-section.component.*
git commit -m "refactor(#547): tags section composes the grouped primitives"
```

---

## Task 8: The import page — two siblings, not a nest (#454)

`/settings/import` gets its own page component. `OpmlSectionComponent` stops rendering a feature it does not own, and `backup-section` drops its compensating margin.

**Files:**
- Create: `src/app/settings/import-section.component.ts`
- Create: `src/app/settings/import-section.component.html`
- Test: `src/app/settings/import-section.component.spec.ts`
- Modify: `src/app/settings/settings.routes.ts:22-26`
- Modify: `src/app/settings/opml-section.component.html`, `.ts`, `.scss`
- Modify: `src/app/settings/backup-section.component.html`, `.ts`, `.scss`

**Interfaces:**
- Consumes: `SettingsStackComponent`, `SettingsGroupComponent`, `OpmlSectionComponent`, `BackupSectionComponent`.
- Produces: `ImportSectionComponent`, selector `app-import-section`, no inputs. `settings.routes.ts` loads it for `path: 'import'`.

- [ ] **Step 1: Write the failing test**

Create `src/app/settings/import-section.component.spec.ts`:

```ts
import { provideHttpClient } from '@angular/common/http';
import { provideHttpClientTesting } from '@angular/common/http/testing';
import { TestBed } from '@angular/core/testing';
import { provideTransloco } from '@jsverse/transloco';
import { ImportSectionComponent } from './import-section.component';
import { translocoTestingConfig } from '../../testing/transloco-testing';

describe('ImportSectionComponent', () => {
  async function render() {
    await TestBed.configureTestingModule({
      imports: [ImportSectionComponent],
      providers: [
        provideHttpClient(),
        provideHttpClientTesting(),
        provideTransloco(translocoTestingConfig),
      ],
    }).compileComponents();
    const fixture = TestBed.createComponent(ImportSectionComponent);
    fixture.detectChanges();
    return fixture.nativeElement as HTMLElement;
  }

  it('renders both the OPML section and the backup section', async () => {
    const el = await render();

    expect(el.querySelector('app-opml-section')).not.toBeNull();
    expect(el.querySelector('app-backup-section')).not.toBeNull();
  });

  // The whole point of #454: backup is not nested inside OPML any more, so the
  // stack's gap reaches it and no component carries a compensating margin.
  // Asserting they share a parent is what stops the nest from coming back.
  it('renders them as siblings in one stack', async () => {
    const el = await render();
    const opml = el.querySelector('app-opml-section')!;
    const backup = el.querySelector('app-backup-section')!;

    expect(backup.parentElement).toBe(opml.parentElement);
    expect(opml.parentElement?.tagName.toLowerCase()).toBe('app-settings-stack');
    expect(opml.querySelector('app-backup-section')).toBeNull();
  });
});
```

Before writing, open `src/testing/transloco-testing.ts` and confirm the exported symbol's name; if it differs from `translocoTestingConfig`, copy the exact import and provider lines from `src/app/settings/opml-section.component.spec.ts` instead.

- [ ] **Step 2: Run the test to verify it fails**

Run: `./node_modules/.bin/jest src/app/settings/import-section.component.spec.ts`
Expected: FAIL — `Cannot find module './import-section.component'`.

- [ ] **Step 3: Create the page component**

Create `src/app/settings/import-section.component.ts`:

```ts
// src/app/settings/import-section.component.ts
import { ChangeDetectionStrategy, Component } from '@angular/core';
import { BackupSectionComponent } from './backup-section.component';
import { OpmlSectionComponent } from './opml-section.component';
import { SettingsStackComponent } from '../shared/settings/stack/settings-stack.component';

/**
 * The `/settings/import` page: OPML import/export and account backup, side by
 * side in one stack.
 *
 * It exists because the route used to load `OpmlSectionComponent` and let *it*
 * render `<app-backup-section />` from its own template, purely so both landed
 * on the same page (#454). That made the OPML section own an unrelated feature,
 * and it put a component host boundary between the two cards -- which the
 * global `app-settings-card + app-settings-card` gap could not cross, so the
 * backup card carried a compensating margin. A page whose only job is to
 * compose the two sections costs one file and removes both problems: reordering
 * them, or adding a third, is one line here.
 */
@Component({
  selector: 'app-import-section',
  imports: [BackupSectionComponent, OpmlSectionComponent, SettingsStackComponent],
  templateUrl: './import-section.component.html',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class ImportSectionComponent {}
```

There is no `styleUrl`: the page has no styles of its own, which is the point.

Create `src/app/settings/import-section.component.html`:

```html
<app-settings-stack>
  <app-opml-section />
  <app-backup-section />
</app-settings-stack>
```

- [ ] **Step 4: Convert the OPML section to a group and stop it rendering backup**

Replace `src/app/settings/opml-section.component.html` with:

```html
<app-settings-group icon="import_export" [title]="'settings.opml.title' | transloco">
  <div class="group">
    <p class="lead">{{ 'settings.opml.exportLead' | transloco }}</p>
    <app-button [disabled]="exporting()" (click)="exportOpml()">
      {{ (exporting() ? 'settings.opml.preparing' : 'settings.opml.export') | transloco }}
    </app-button>
    @if (exportError(); as problem) {
      <app-error-banner [message]="problem.detail || problem.title" />
    }
  </div>

  <div class="group">
    <p class="lead">{{ 'settings.opml.importLead' | transloco }}</p>
    <input type="file" accept=".opml,.xml,text/xml,text/x-opml" (change)="onFile($event)" />
    <textarea
      class="area"
      rows="4"
      [placeholder]="'settings.opml.pastePlaceholder' | transloco"
      [value]="text()"
      (input)="text.set(value($event))"
    ></textarea>
    <app-button
      variant="primary"
      [disabled]="importing() || reading() || !text().trim()"
      (click)="importText()"
    >
      {{ (importing() ? 'settings.opml.importing' : 'settings.opml.import') | transloco }}
    </app-button>
    @if (importError(); as problem) {
      <app-error-banner [message]="problem.detail || problem.title" />
    }
    @if (result(); as r) {
      <p class="result">
        {{
          'settings.opml.result'
            | transloco
              : {
                  imported: r.imported,
                  already: r.alreadySubscribed,
                  invalid: r.invalid,
                  skipped: r.skippedOverLimit,
                }
        }}
      </p>
    }
  </div>
</app-settings-group>
```

Note there is **no** `<app-settings-stack>` here and no trailing `<app-backup-section />`: this component is now one group, and the page above it owns the column.

In `src/app/settings/opml-section.component.ts`, delete the `SettingsCardComponent` and `BackupSectionComponent` imports, add:

```ts
import { SettingsGroupComponent } from '../shared/settings/settings-group/settings-group.component';
```

and replace both entries in the `imports` array with `SettingsGroupComponent`.

In `src/app/settings/opml-section.component.scss`, add at the top of the `.group` rule's block the padding the card used to supply, so the rule becomes:

```scss
.group {
  display: flex;
  flex-direction: column;
  gap: var(--space-2);
  align-items: flex-start;
  padding: var(--space-4) var(--space-5);
}
```

and delete the now-dead `.group:last-child { margin-bottom: 0; }` rule together with the `margin-bottom: var(--space-3);` that the `padding` replaces.

- [ ] **Step 5: Convert the backup section to a group and delete the workaround**

In `src/app/settings/backup-section.component.html`, replace the opening tag

```html
<app-settings-card class="backup" [heading]="'settings.backup.title' | transloco">
```

with

```html
<app-settings-group icon="backup" [title]="'settings.backup.title' | transloco">
```

and the closing `</app-settings-card>` with `</app-settings-group>`. Nothing between changes.

In `src/app/settings/backup-section.component.ts`, delete the `SettingsCardComponent` import, add:

```ts
import { SettingsGroupComponent } from '../shared/settings/settings-group/settings-group.component';
```

and swap the entry in the `imports` array.

In `src/app/settings/backup-section.component.scss`, **delete the whole `.backup` rule** — this is the fix #454 asks for:

```scss
.backup {
  /* The base's `app-settings-card + app-settings-card` gap cannot reach across
     this component's host wrapper (see `recommendation-run-history.component.scss`
     for the same fix), so the card carries the same separation from the OPML
     card above it here. */
  margin-block-start: var(--space-5);
}
```

Then apply the same padding change to its `.group` rule as in Step 4: add `padding: var(--space-4) var(--space-5);`, drop `margin-bottom: var(--space-3);` and delete `.group:last-child { margin-bottom: 0; }`.

- [ ] **Step 6: Point the route at the page**

In `src/app/settings/settings.routes.ts`, replace the `import` route entry:

```ts
      {
        path: 'import',
        title: sectionLabelKey('import'),
        loadComponent: () =>
          import('./import-section.component').then((m) => m.ImportSectionComponent),
      },
```

- [ ] **Step 7: Assert the route in the routes spec**

Append inside the `describe('SETTINGS_ROUTES', …)` block in `src/app/settings/settings.routes.spec.ts`:

```ts
it('loads the import page, not one of the two sections it composes', async () => {
  const route = sections.find((r) => r.path === 'import')!;
  const loaded = await (route.loadComponent as () => Promise<unknown>)();

  expect((loaded as { name: string }).name).toBe('ImportSectionComponent');
});
```

- [ ] **Step 8: Run the specs to verify they pass**

Run: `./node_modules/.bin/jest src/app/settings/import-section.component.spec.ts src/app/settings/opml-section.component.spec.ts src/app/settings/backup-section.component.spec.ts src/app/settings/settings.routes.spec.ts`
Expected: PASS. If `opml-section.component.spec.ts` asserted that the backup section renders inside it, delete that assertion — that nesting is exactly what this task removes.

- [ ] **Step 9: Run the gate and commit**

```bash
npm run check
git add src/app/settings/import-section.component.* src/app/settings/opml-section.component.* src/app/settings/backup-section.component.* src/app/settings/settings.routes.*
git commit -m "refactor(#454): make the import page hold OPML and backup as siblings"
```

---

## Task 9: Convert `admin-settings` (instance settings)

One group, `toggle_on`. The two raw `<input type="checkbox">` become `app-settings-row` + `app-toggle`, matching every other switch in the application. Save stays instant.

**Files:**
- Modify: `src/app/settings/admin/admin-settings/admin-settings.component.html`
- Modify: `src/app/settings/admin/admin-settings/admin-settings.component.ts`
- Modify: `src/app/settings/admin/admin-settings/admin-settings.component.scss`
- Test: `src/app/settings/admin/admin-settings/admin-settings.component.spec.ts`

**Interfaces:**
- Consumes: `SettingsStackComponent`, `SettingsGroupComponent`, `SettingsRowComponent`, `ToggleComponent`.
- Produces: nothing new.

- [ ] **Step 1: Add the failing assertion**

The existing spec queries `input[type="checkbox"]`, and `app-toggle` renders exactly that — so those five tests keep passing and keep proving behaviour. Add one test that pins the new composition. Append inside the `describe`:

```ts
it('renders both switches as settings rows in one group', () => {
  const f = mount();
  ctrl.expectOne('https://api.test/api/admin/settings').flush(settings);
  f.detectChanges();
  const el = f.nativeElement as HTMLElement;

  expect(el.querySelectorAll('app-settings-group').length).toBe(1);
  expect(el.querySelectorAll('app-settings-row app-toggle').length).toBe(2);
});
```

Match `mount()`, the flushed URL and the `settings` fixture name to whatever the file already uses at its top — copy them from the existing `'loads the settings on init and renders both toggles'` test rather than inventing names.

- [ ] **Step 2: Run the spec to verify it fails**

Run: `./node_modules/.bin/jest src/app/settings/admin/admin-settings`
Expected: FAIL on the new test only; the five existing tests PASS.

- [ ] **Step 3: Rewrite the template**

Replace `src/app/settings/admin/admin-settings/admin-settings.component.html` with:

```html
<app-settings-stack>
  <app-settings-group icon="toggle_on" [title]="'settings.instance.title' | transloco">
    @if (loading()) {
      <app-skeleton [label]="'settings.instance.loading' | transloco" [rows]="2" />
    } @else if (error()) {
      <app-error-banner
        [message]="error()!.detail || error()!.title"
        [actionLabel]="'settings.instance.retry' | transloco"
        (action)="load()"
      />
    } @else {
      <app-settings-row
        [title]="'settings.instance.requireEmailConfirmation' | transloco"
        [description]="
          (mailEnabled()
            ? 'settings.instance.requireEmailConfirmationHint'
            : 'settings.instance.mailDisabledHint'
          ) | transloco
        "
      >
        <app-toggle
          inputId="require-email-confirmation-toggle"
          [checked]="requireEmailConfirmation()"
          [disabled]="!mailEnabled()"
          [label]="'settings.instance.requireEmailConfirmation' | transloco"
          (toggled)="toggleEmailConfirmation()"
        />
      </app-settings-row>

      <app-settings-row
        [title]="'settings.instance.requireApproval' | transloco"
        [description]="'settings.instance.requireApprovalHint' | transloco"
      >
        <app-toggle
          inputId="require-approval-toggle"
          [checked]="requireApproval()"
          [label]="'settings.instance.requireApproval' | transloco"
          (toggled)="toggleApproval()"
        />
      </app-settings-row>
    }
  </app-settings-group>
</app-settings-stack>
```

`toggleEmailConfirmation()` and `toggleApproval()` take no argument and flip the current value themselves, so `(toggled)` calls them exactly as `(change)` did. Do not change the component class.

- [ ] **Step 4: Update the component imports**

In `src/app/settings/admin/admin-settings/admin-settings.component.ts`, delete the `SettingsCardComponent` import and add:

```ts
import { SettingsGroupComponent } from '../../../shared/settings/settings-group/settings-group.component';
import { SettingsRowComponent } from '../../../shared/settings/settings-row/settings-row.component';
import { SettingsStackComponent } from '../../../shared/settings/stack/settings-stack.component';
import { ToggleComponent } from '../../../shared/toggle/toggle.component';
```

Change the `imports` array to:

```ts
  imports: [
    ErrorBannerComponent,
    SettingsGroupComponent,
    SettingsRowComponent,
    SettingsStackComponent,
    SkeletonComponent,
    ToggleComponent,
    TranslocoPipe,
  ],
```

- [ ] **Step 5: Empty the stylesheet**

Every rule in `src/app/settings/admin/admin-settings/admin-settings.component.scss` (`.toggle`, `.toggle + .toggle`, `.check`, `.check.disabled`, `.hint`) described the row layout the primitives now own. Delete the file and remove its `styleUrl: './admin-settings.component.scss',` line from the component decorator.

The one thing lost is `.check.disabled`'s muted title colour when mail is off. The row's description already carries the explanation (`mailDisabledHint`) and the toggle renders its own disabled state, so the information survives; do not reintroduce a rule to recolour a row title, which would be exactly the "feature stylesheet restyles a primitive" the system forbids.

- [ ] **Step 6: Run the spec to verify it passes**

Run: `./node_modules/.bin/jest src/app/settings/admin/admin-settings`
Expected: PASS, six tests.

- [ ] **Step 7: Run the gate and commit**

```bash
npm run check
git add src/app/settings/admin/admin-settings
git commit -m "refactor(#547): instance settings compose rows and the shared toggle"
```

---

## Task 10: Convert `admin-users`

One group, `shield_person`. The status filters move to `groupActions`. The user list is untouched (spec decision 4: the group is a frame).

**Files:**
- Modify: `src/app/admin/admin-users.component.html`
- Modify: `src/app/admin/admin-users.component.ts`
- Modify: `src/app/admin/admin-users.component.scss`
- Test: `src/app/admin/admin-users.component.spec.ts:277`

**Interfaces:**
- Consumes: `SettingsStackComponent`, `SettingsGroupComponent`, the `[groupActions]` slot.
- Produces: nothing new.

- [ ] **Step 1: Update the failing assertion**

In `src/app/admin/admin-users.component.spec.ts`, replace the assertion at line 277 and rename its test:

```ts
it('renders the queue as a settings group with its filters in the header', () => {
  const f = mount();
  // …keep whatever flush/detectChanges lines the existing test has…
  const el = f.nativeElement as HTMLElement;

  expect(el.querySelector('app-settings-group')).not.toBeNull();
  expect(el.querySelector('.g-head .filters')).not.toBeNull();
});
```

- [ ] **Step 2: Run the spec to verify it fails**

Run: `./node_modules/.bin/jest src/app/admin/admin-users.component.spec.ts`
Expected: FAIL.

- [ ] **Step 3: Swap the shell and move the filters**

In `src/app/admin/admin-users.component.html`, replace the opening `<app-settings-card …>` tag and the `<div class="filters" …>` block that follows it with:

```html
<app-settings-stack>
  <app-settings-group icon="shield_person" [title]="'admin.title' | transloco">
    <div
      groupActions
      class="filters"
      role="group"
      [attr.aria-label]="'admin.filterByStatus' | transloco"
    >
      @for (f of filters; track f.status) {
        <button [class.active]="filter() === f.status" (click)="setFilter(f.status)">
          {{ 'admin.status.' + (f.status ?? 'all') | transloco }}
        </button>
      }
    </div>
```

Replace the closing `</app-settings-card>` with:

```html
  </app-settings-group>
</app-settings-stack>
```

Everything between is unchanged apart from indentation.

- [ ] **Step 4: Update the component imports**

In `src/app/admin/admin-users.component.ts`, delete the `SettingsCardComponent` import and add:

```ts
import { SettingsGroupComponent } from '../shared/settings/settings-group/settings-group.component';
import { SettingsStackComponent } from '../shared/settings/stack/settings-stack.component';
```

Swap the entry in the `imports` array for both.

- [ ] **Step 5: Adjust the stylesheet**

In `src/app/admin/admin-users.component.scss`:

- The `.filters` rule now styles a header element rather than the first child of a padded card. Delete any `margin-bottom`, `padding` or `border-bottom` it carries for that role; keep the button chrome (`.filters button`, `.active`) exactly as it is.
- Give the list and its empty/loading states the inset the card used to supply, by adding at the top of the file:

```scss
// The group's panel is unpadded by design (its rows pad themselves); this
// section projects a list, not rows, so the inset lives here.
.users,
.pad,
app-skeleton,
app-error-banner {
  display: block;
  padding: var(--space-4) var(--space-5);
}
```

Then check `.users li` for a rule that assumed the card's own padding and remove it if present.

- [ ] **Step 6: Run the spec to verify it passes**

Run: `./node_modules/.bin/jest src/app/admin/admin-users.component.spec.ts`
Expected: PASS, unchanged test count.

- [ ] **Step 7: Run the gate and commit**

```bash
npm run check
git add src/app/admin/admin-users.component.*
git commit -m "refactor(#547): admin user queue composes a settings group"
```

---

## Task 11: Convert `admin-catalog`

Two groups: `upload` "Catalog import" and `category` "Feed catalog". The "Add category" button moves to the second group's `groupActions`.

**Files:**
- Modify: `src/app/admin/admin-catalog.component.html`
- Modify: `src/app/admin/admin-catalog.component.ts`
- Modify: `src/app/admin/admin-catalog.component.scss`
- Test: `src/app/admin/admin-catalog.component.spec.ts:296`
- Modify: `public/i18n/en.json`, `public/i18n/de.json`

**Interfaces:**
- Consumes: `SettingsStackComponent`, `SettingsGroupComponent`, the `[groupActions]` slot.
- Produces: i18n key `admin.catalogImport`.

- [ ] **Step 1: Add the one new i18n key to both dictionaries**

In `public/i18n/en.json`, inside the `admin` object, next to `"catalog"`, add:

```json
"catalogImport": "Catalog import",
```

In `public/i18n/de.json`, in the same place:

```json
"catalogImport": "Katalog-Import",
```

- [ ] **Step 2: Update the failing assertion**

In `src/app/admin/admin-catalog.component.spec.ts`, replace the assertion at line 296 and rename its test:

```ts
it('renders the import block and the catalog as two settings groups', () => {
  // …keep whatever mount/flush/detectChanges lines the existing test has…
  expect(fixture.nativeElement.querySelectorAll('app-settings-group').length).toBe(2);
});
```

- [ ] **Step 3: Run the spec to verify it fails**

Run: `./node_modules/.bin/jest src/app/admin/admin-catalog.component.spec.ts`
Expected: FAIL — received `0`, expected `2`.

- [ ] **Step 4: Restructure the template into two groups**

In `src/app/admin/admin-catalog.component.html`:

Replace the opening `<app-settings-card [heading]="'admin.catalog' | transloco">` with:

```html
<app-settings-stack>
  <app-settings-group icon="upload" [title]="'admin.catalogImport' | transloco">
```

Everything from the `@if (actionError())` block down to and including `<p class="hint">{{ 'admin.lockedHint' | transloco }}</p>` stays inside this first group.

Immediately **after** that `<p class="hint">` line, close the first group and open the second, moving the `.list-head` button into its header:

```html
  </app-settings-group>

  <app-settings-group icon="category" [title]="'admin.catalog' | transloco">
    <app-button
      groupActions
      size="sm"
      variant="primary"
      data-testid="add-category"
      (click)="openCategoryDialog(null)"
    >
      <app-icon name="add" size="sm" /> {{ 'admin.addCategory' | transloco }}
    </app-button>
```

Delete the now-empty `<div class="list-head">…</div>` wrapper the button came from.

Replace the closing `</app-settings-card>` with:

```html
  </app-settings-group>
</app-settings-stack>
```

**The `@if (loading()) / @else if (error()) / @else` chain closes inside the first group.** The `<ul class="categories">` list, which currently sits inside that `@else`, must move into the second group — so repeat the guard around it there:

```html
    @if (!loading() && !error()) {
      <ul class="categories">
        …unchanged…
      </ul>
    }
```

Read the file carefully at this step: the two groups each need balanced control flow, and the `@else` branch is long. When in doubt, run `npm run check` — the Angular template compiler reports an unbalanced block as an error, not a silent misrender.

- [ ] **Step 5: Update the component imports**

In `src/app/admin/admin-catalog.component.ts`, delete the `SettingsCardComponent` import and add:

```ts
import { SettingsGroupComponent } from '../shared/settings/settings-group/settings-group.component';
import { SettingsStackComponent } from '../shared/settings/stack/settings-stack.component';
```

Swap the entry in the `imports` array for both.

- [ ] **Step 6: Adjust the stylesheet**

In `src/app/admin/admin-catalog.component.scss`:

- Delete the `.list-head` rule — the element is gone.
- Add the panel inset for the content that is not made of rows:

```scss
// The group's panel is unpadded by design (its rows pad themselves); these
// sections project blocks and lists, not rows, so the inset lives here.
.import,
.categories,
.notice,
.hint,
app-skeleton,
app-error-banner {
  display: block;
  padding: var(--space-4) var(--space-5);
}
```

- Check `.import`, `.notice` and `.hint` for `margin` values that were compensating for the card's padding, and remove them.

- [ ] **Step 7: Run the spec to verify it passes**

Run: `./node_modules/.bin/jest src/app/admin/admin-catalog.component.spec.ts`
Expected: PASS, unchanged test count. The suite has `data-testid` assertions on `import-bundled`, `add-category`, `admin-category` and `admin-feed`; all four attributes survive this task unchanged.

- [ ] **Step 8: Run the gate and commit**

```bash
npm run check
git add src/app/admin/admin-catalog.component.* public/i18n/en.json public/i18n/de.json
git commit -m "refactor(#547): split the feed catalog into an import group and a catalog group"
```

---

## Task 12: Convert `admin-user-detail`

Three groups: the user's own group (heading, actions, states, the account/activity/footprint sub-cards), then Tags, then Feeds. The back link stays outside the stack.

**Files:**
- Modify: `src/app/admin/admin-user-detail.component.html`
- Modify: `src/app/admin/admin-user-detail.component.ts`
- Modify: `src/app/admin/admin-user-detail.component.scss`
- Test: `src/app/admin/admin-user-detail.component.spec.ts:603-627`

**Interfaces:**
- Consumes: `SettingsStackComponent`, `SettingsGroupComponent`, the `[groupActions]` slot.
- Produces: nothing new.

- [ ] **Step 1: Update the two failing assertions**

In `src/app/admin/admin-user-detail.component.spec.ts`, replace the two tests at lines 603–627 with:

```ts
it('renders the user, their tags and their feeds as three settings groups', () => {
  const f = mount();
  ctrl.expectOne('https://api.test/api/admin/users/7').flush(detail);
  f.detectChanges();
  expect((f.nativeElement as HTMLElement).querySelectorAll('app-settings-group').length).toBe(3);
});

it("projects the account actions into the group's header, not its panel", () => {
  const f = mount();
  ctrl.expectOne('https://api.test/api/admin/users/7').flush({
    ...detail,
    user: { ...detail.user, status: 'pending_approval' },
  });
  f.detectChanges();
  const el = f.nativeElement as HTMLElement;

  // `groupActions` only projects from a direct child of app-settings-group:
  // one `@if` level deep is tolerated, two silently drop the content out of
  // `.g-head` and into the panel instead. Asserting the buttons live inside
  // `.g-head .acts` -- not merely that `.acts` exists somewhere on the page --
  // is what catches that regression.
  const actionsInHead = el.querySelectorAll('.g-head .acts app-button');
  expect(actionsInHead.length).toBeGreaterThan(0);
  expect(el.querySelector('.g-head .acts')?.textContent).toContain('Approve');
});
```

- [ ] **Step 2: Run the spec to verify it fails**

Run: `./node_modules/.bin/jest src/app/admin/admin-user-detail.component.spec.ts`
Expected: FAIL on both.

- [ ] **Step 3: Restructure the template**

In `src/app/admin/admin-user-detail.component.html`, leave the `<a class="back" …>` link exactly where it is, outside everything. Below it, the page becomes one stack of three groups.

**The shape to build.** The first group holds the whole loading/error/detail chain; the tags and feeds groups sit in a *second* `@if (detail(); as d)` after it. Closing the first group inside the `@else if` chain would leave the template unbalanced, and the Angular compiler rejects that — so the guard is read twice. Re-reading one signal is cheaper than an unbalanced template.

```html
<a class="back" routerLink="/settings/admin/users">…unchanged…</a>

<app-settings-stack>
  <app-settings-group icon="person" [title]="cardHeading()">
    @if (hasActions()) {
      <div groupActions class="acts">…unchanged buttons…</div>
    }

    @if (loading()) {
      …unchanged skeleton…
    } @else if (error()) {
      …unchanged error banner with retry…
    } @else if (detail(); as d) {
      @if (actionError()) {
        …unchanged dismiss banner…
      }
      <div class="cards">…unchanged account / activity / footprint sub-cards…</div>
    }
  </app-settings-group>

  @if (detail(); as d) {
    <app-settings-group icon="sell" [title]="'admin.detail.tagsTitle' | transloco">
      @if (d.tags.length === 0) {
        <p class="muted">{{ 'admin.detail.noTags' | transloco }}</p>
      } @else {
        <ul class="rows">…unchanged tag list…</ul>
      }
    </app-settings-group>

    <app-settings-group icon="rss_feed" [title]="'admin.detail.feedsTitle' | transloco">
      @if (d.subscriptions.length === 0) {
        <p class="muted">{{ 'admin.detail.noFeeds' | transloco }}</p>
      } @else {
        <ul class="rows">…unchanged feed list…</ul>
      }
    </app-settings-group>
  }
</app-settings-stack>
```

Three details inside that shape:

- The actions wrapper's attribute changes from `cardActions` to `groupActions`. Its `@if (hasActions())` guard stays — one `@if` level is tolerated by content projection, two are not.
- Delete the two `<h3>{{ 'admin.detail.tagsTitle' | transloco }}</h3>` and `<h3>{{ 'admin.detail.feedsTitle' | transloco }}</h3>` lines. Each group's own `.g-title` is that heading now; leaving both would give each section two headings.
- The tag and feed list markup inside the `@else` branches is moved verbatim. Do not retype it.

- [ ] **Step 4: Update the component imports**

In `src/app/admin/admin-user-detail.component.ts`, delete the `SettingsCardComponent` import and add:

```ts
import { SettingsGroupComponent } from '../shared/settings/settings-group/settings-group.component';
import { SettingsStackComponent } from '../shared/settings/stack/settings-stack.component';
```

Swap the entry in the `imports` array for both. Then check the doc comment on `hasActions()` at line 116 — it says "the heading row has anything to project into `cardActions`". Change `cardActions` to `groupActions`.

- [ ] **Step 5: Adjust the stylesheet**

In `src/app/admin/admin-user-detail.component.scss`:

- Delete the `h3` rule if one exists — the `<h3>`s are gone.
- Add the panel inset:

```scss
// The group's panel is unpadded by design (its rows pad themselves); this page
// projects sub-cards and lists, not rows, so the inset lives here.
.cards,
.rows,
.muted,
app-skeleton,
app-error-banner {
  display: block;
  padding: var(--space-4) var(--space-5);
}
```

- Check `.cards` and `.rows` for `margin` values that compensated for the card's padding and remove them.

- [ ] **Step 6: Run the spec to verify it passes**

Run: `./node_modules/.bin/jest src/app/admin/admin-user-detail.component.spec.ts`
Expected: PASS, unchanged test count. This is the largest suite touched by the rollout; a failure outside the two rewritten tests means the restructure moved behaviour and must be reworked, not the assertion.

- [ ] **Step 7: Run the gate and commit**

```bash
npm run check
git add src/app/admin/admin-user-detail.component.*
git commit -m "refactor(#547): admin user detail composes three settings groups"
```

---

## Task 13: Delete `app-settings-card`

Nothing composes it now. Remove the component, the global gap rule it needed, and its entry in the design language.

**Files:**
- Delete: `src/app/shared/settings-card/settings-card.component.ts`
- Delete: `src/app/shared/settings-card/settings-card.component.html`
- Delete: `src/app/shared/settings-card/settings-card.component.scss`
- Delete: `src/app/shared/settings-card/settings-card.component.spec.ts`
- Modify: `src/styles/_base.scss:36-46`
- Modify: `src/app/shared/disclosure/disclosure.component.ts` (doc comment reference)
- Modify: `docs/design-language.md`

**Interfaces:**
- Consumes: the completed conversions from Tasks 3–12.
- Produces: nothing.

- [ ] **Step 1: Prove nothing composes it**

Run: `grep -rn "app-settings-card\|SettingsCardComponent" src ../docs`
Expected: matches **only** inside `src/app/shared/settings-card/`, the doc comment in `src/app/shared/disclosure/disclosure.component.ts`, the `_base.scss` rule, and `docs/design-language.md`.

If any section still matches, that section's task is unfinished. Stop and finish it before continuing.

- [ ] **Step 2: Delete the component**

```bash
git rm -r src/app/shared/settings-card
```

- [ ] **Step 3: Delete the global gap rule**

In `src/styles/_base.scss`, delete the comment block at lines 36–39 together with both rules:

```scss
app-settings-card {
  display: block;
}

app-settings-card + app-settings-card {
  margin-block-start: var(--space-5);
}
```

Do **not** replace them with an `app-settings-group + app-settings-group` equivalent. The stack owns that spacing now, and a sibling rule would reintroduce the host-boundary trap this whole change removes.

- [ ] **Step 4: Fix the dangling doc reference**

In `src/app/shared/disclosure/disclosure.component.ts`, the class doc comment mentions "`<app-settings-card>`'s collapsible mode". That mode no longer exists. Rewrite the parenthetical to refer to `appearance="drill-in"` instead, keeping the sentence's point about the projected heading intact.

- [ ] **Step 5: Remove the card from the design language**

In `docs/design-language.md`, delete the `app-settings-card` section in the shared-component catalog. Then grep the whole file for remaining mentions:

Run: `grep -n "settings-card" ../docs/design-language.md`

Rewrite each surviving reference to name `app-settings-group` instead — in particular the `<app-settings-group>` entry's own "**Not for:** a section that is one flat card with no group header — that is still `<app-settings-card>`" line, which must now read:

```markdown
**Not for:** nothing. Every settings and admin section composes a group; the
flat `app-settings-card` it replaced was deleted in #547.
```

- [ ] **Step 6: Run the full gate**

Run: `npm run check`
Expected: PASS. A `TS2307` or an unused-import error here names a section whose conversion left a dangling import.

- [ ] **Step 7: Prove the tree is clean**

Run: `grep -rn "app-settings-card\|SettingsCardComponent" src ../docs`
Expected: no output.

- [ ] **Step 8: Commit**

```bash
git add -A src/app/shared src/styles/_base.scss ../docs/design-language.md
git commit -m "refactor(#547): delete app-settings-card, nothing composes it any more"
```

---

## Task 14: Verify the rollout on the running app

Gates green is not the deliverable. Every converted route needs a real look.

**Files:** none — this task changes nothing unless it finds a defect.

- [ ] **Step 1: Bring the stack up**

```bash
docker compose up -d
```

Wait for the frontend to be reachable, then confirm the container is serving *this* branch's code rather than a stale chunk: hard-reload, and check that `/settings/preferences` shows the Grouped look. A days-old `ng serve` container will happily serve a pre-checkout bundle. If it does, restart the frontend service.

- [ ] **Step 2: Walk every converted route at desktop width, in both themes**

Visit each and confirm the group header, the panel surface, the row dividers and the spacing between groups:

- `/settings/about`
- `/settings/account` — two groups, the danger zone separate
- `/settings/preferences` — the Experimental badge sits beside the row title
- `/settings/tags` — New tag in the header; drag a tag to a new position
- `/settings/import` — **two groups with an even gap and no doubled margin** (#454)
- `/settings/ai` — unchanged from before the branch
- `/settings/admin/settings` — two switches, the email one disabled when mail is off
- `/settings/admin/users` — filters in the header, each filter still filters
- `/settings/admin/catalog` — two groups
- `/settings/admin/users/:id` — three groups, action buttons in the header
- `/settings/admin/proxy` — unchanged from before the branch

- [ ] **Step 3: Repeat at a phone width**

Narrow the viewport below the `bp-sm` breakpoint and walk the same list. Watch for: a group header whose actions crush the title instead of wrapping (`admin/users` with four filters is the worst case), a row whose control overflows, and any horizontal page scroll.

- [ ] **Step 4: Record what you found**

If every route is correct, say so plainly with the list you walked. If anything is wrong, fix it in the owning task's files and re-run that task's spec plus `npm run check` before claiming the rollout is done.

- [ ] **Step 5: Push and open the PR**

```bash
git push -u origin feature/547-settings-design-system
```

Open a PR into `develop` whose body closes both issues:

```
Closes #547
Closes #454
```

---

## Self-Review

**Spec coverage.** Decision 1 (stack) → Task 1. Decision 2 (group actions slot) → Task 2. Decision 3 (`rowTitleTip` widening) → Task 2 + Task 6. Decision 4 (admin lists framed only) → Tasks 10–12, each keeping its list markup and adding only panel-inset glue. Decision 5 (import page, #454) → Task 8. Decision 6 (delete the card) → Task 13. Conversion map: about → 4, account → 5, preferences → 6, tags → 7, opml + backup + import → 8, admin-settings → 9, admin-users → 10, admin-catalog → 11, admin-user-detail → 12, proxy → 3. The spec's three gotchas: the row-divider positional rule is checked explicitly in Task 4 Step 7; translated-strings-not-keys is a global constraint; both-locales is a global constraint enforced by an existing spec. Manual verification (spec: "desktop + mobile, both themes") → Task 14.

**Placeholder scan.** No TBD, no "add error handling", no "similar to Task N" — the group-actions projection caveat is repeated in full in Tasks 7 and 12 rather than cross-referenced, because tasks are executed by separate agents that may read them out of order.

**Type consistency.** `SettingsStackComponent` / `app-settings-stack` is spelled identically in Tasks 1, 3–12. `SettingsGroupComponent`, `SettingsRowComponent`, `ToggleComponent` match their existing exported names. The `groupActions` and `rowTitleTip` attributes are bare (no brackets) at every consumer, matching how `cardActions` is used today. Import depths are given per directory: `../shared/...` from `settings/`, `../shared/...` from `admin/`, `../../../shared/...` from `settings/admin/*`.

**Known soft spots for the executor.** Two tasks rewrite long templates with nested Angular control flow — Task 11 (`admin-catalog`) and Task 12 (`admin-user-detail`). Both call this out and both give the target block structure. If the compiler rejects a rearrangement, keep the guard duplication the plan proposes rather than flattening the control flow.
