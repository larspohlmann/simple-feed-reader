# Settings Polish and Tag Management Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Give the settings area one consistent card surface and honest loading states, add drag-to-reorder and inline editing to the tags section, and make a language change reach the server so account emails follow it.

**Architecture:** Two new shared components (`SettingsCardComponent`, `SkeletonComponent`) land first; every settings and admin surface then converts to them. The tags section gains reordering — reusing the already-shipped `PATCH /api/tags/reorder` and `ManageActions.reorderTags()`, so no backend work — and inline row editing that reuses the same field primitives the dialog uses. Finally `MeController` gains a `PATCH` so `LanguageService` can write the chosen language through to `User.locale`, with the server as the source of truth and `localStorage` as a cache.

**Tech Stack:** Angular 20 standalone + signals, Transloco, Angular CDK drag-drop, Jest, Playwright; Symfony 7.4 / PHP 8.4, Doctrine, PHPUnit.

**Branch:** `feature/180-settings-polish-tags` (already created off `develop`; spec committed).

**Spec:** `docs/superpowers/specs/2026-07-31-settings-polish-and-tag-management-design.md`

## Global Constraints

- **Frontend commands run from `frontend/`; backend commands from `backend/`.** Always use absolute paths in shell calls — the Bash working directory persists between calls.
- **Angular 20: standalone components and signals, no NgModules.** `ChangeDetectionStrategy.OnPush` on new shared components, matching `ErrorBannerComponent`.
- **Component styles live in a sibling `.scss` referenced by `styleUrl`, never inline `styles:` in the `.ts`.** Stylelint has no TS syntax installed, so inline styles are silently unlinted.
- **SCSS: no hex colours, and no raw `px` for spacing, font-size or radius, outside `src/app/theme/`** — design tokens only. Breakpoints via `@use '../theme/breakpoints' as bp;`, never a literal media-query value. Both fail `npm run check`.
- **The font-size tokens are `--fs-xs`, `--fs-sm`, `--fs-base`, `--fs-read`, `--fs-lg`, `--fs-xl`.** There is no `--fs-md`.
- **i18n keys go in BOTH `frontend/public/i18n/en.json` and `de.json`**; both must stay valid JSON. No hardcoded English in a template, and no key left dead.
- **`DatePipe` is unusable in this app.** No `LOCALE_ID` is provided and the language switches at runtime through Transloco, so it renders `en-US` permanently. Dates go through `formatDateOr(iso, fallbackKey)` / `formatLongDate(iso, lang)` from `frontend/src/app/reader/format.ts`.
- **Never nest `cdkDropList`s.** Nesting silently breaks cross-list drag. Use sibling lists.
- **Frontend gate:** `npm run check` from `frontend/` (ESLint + Prettier at 100 columns + Stylelint + Jest). Run `npx prettier --write <changed files>` before committing.
- **Backend gates:** `composer cs` (PSR-12; `composer cs:fix` autofixes), `composer stan` (PHPStan level max — warm the cache with `bin/console cache:warmup` first), `php bin/phpunit` with no path argument so `phpunit.dist.xml`'s E2E exclusion applies.
- **PHPMD:** the whole-`src` sweep (`composer md`) is broken on this checkout by a pre-existing pdepend/PHP-8.4 incompatibility, tracked as issue #183 — do not try to fix it. Satisfy the standing rule per file: `vendor/bin/phpmd <file> text phpmd.xml.dist`, exit 0 required, for every `src` file created or modified.
- **`declare(strict_types=1)` in every PHP file.** PHP Clean Code is mandatory (`CLAUDE.md`): intention-revealing names, one thing per function, few parameters, no boolean flag parameters, guard clauses over nesting, `final readonly class` with constructor promotion, injected interfaces, typed exceptions.
- **Native iOS viability:** every endpoint is JSON in, `application/problem+json` out, bearer-authenticated, stateless. No cookie, no CSRF token, no browser-only input.
- **Prove every new assertion by mutation.** Break the production code, watch the test fail, restore it, and report the evidence. Eight of the nine tasks in the previous phase (#193) shipped tests that stayed green while the code was broken; reading never caught it, mutation always did.

---

## File map

**Create (frontend):**

| File | Responsibility |
|---|---|
| `src/app/shared/settings-card/settings-card.component.{ts,html,scss}` (+spec) | The one titled surface every settings and admin section sits in |
| `src/app/shared/skeleton/skeleton.component.{ts,html,scss}` (+spec) | Placeholder rows shown while a list loads |

**Modify (frontend):**

| File | Change |
|---|---|
| `src/app/settings/about-section.component.{html,ts,scss}` | Card; spinner while the version loads |
| `src/app/settings/account-section.component.{html,scss}` | Card |
| `src/app/settings/opml-section.component.{html,scss}` | Card; `app-error-banner` replaces the hand-rolled error |
| `src/app/settings/preferences-section.component.{html,scss}` | Card |
| `src/app/settings/tags-section.component.{ts,html,scss}` (+spec) | Card, loading/error/empty states, drag-to-reorder, inline edit |
| `src/app/admin/admin-users.component.{html,scss}` | Card; skeleton |
| `src/app/admin/admin-catalog.component.{html,scss}` | Card; skeleton |
| `src/app/admin/admin-user-detail.component.{html,scss}` | Card; skeleton; `--fs-md` → `--fs-base` |
| `src/app/reader/entry-split.component.scss:59`, `entry-thumb.component.scss:52` | `--fs-md` → `--fs-base` |
| `src/app/core/auth.service.ts` | `CurrentUser` gains `locale`; `updateLocale()` |
| `src/app/core/language.service.ts` (+spec) | Adopt the server value on login; write through on switch |
| `public/i18n/en.json`, `de.json` | New keys |
| `docs/design-language.md` | Catalog entries for the card, the skeleton and the existing `app-spinner` |
| `e2e/settings-admin-smoke.spec.ts` | Reorder a tag and edit one inline |

**Modify (backend):**

| File | Change |
|---|---|
| `src/Controller/Api/MeController.php` | `__invoke` becomes `show()` + `updateLocale()`; `locale` on the response |
| `src/Dto/Me/UpdateLocaleRequest.php` (create) | The PATCH body, with the allow-list constraint |
| `tests/Controller/Api/MeControllerTest.php` | GET shape, PATCH happy path, 422, 401 |

---

### Task 1: The shared settings card

**Files:**
- Create: `frontend/src/app/shared/settings-card/settings-card.component.ts`
- Create: `frontend/src/app/shared/settings-card/settings-card.component.html`
- Create: `frontend/src/app/shared/settings-card/settings-card.component.scss`
- Test: `frontend/src/app/shared/settings-card/settings-card.component.spec.ts`
- Modify: `docs/design-language.md`

**Interfaces:**
- Consumes: nothing.
- Produces: `SettingsCardComponent`, selector `app-settings-card`, with `heading = input.required<string>()` and `description = input<string | null>(null)`. Content is projected. Tasks 3, 4 and 5 wrap their sections in it.

- [ ] **Step 1: Write the failing test**

Create `frontend/src/app/shared/settings-card/settings-card.component.spec.ts`:

```ts
import { Component } from '@angular/core';
import { TestBed } from '@angular/core/testing';
import { SettingsCardComponent } from './settings-card.component';

@Component({
  imports: [SettingsCardComponent],
  template: `
    <app-settings-card [heading]="'Tags'" [description]="desc">
      <p class="projected">body</p>
    </app-settings-card>
  `,
})
class HostComponent {
  desc: string | null = null;
}

describe('SettingsCardComponent', () => {
  async function render(desc: string | null = null) {
    await TestBed.configureTestingModule({ imports: [HostComponent] }).compileComponents();
    const fixture = TestBed.createComponent(HostComponent);
    fixture.componentInstance.desc = desc;
    fixture.detectChanges();
    return fixture.nativeElement as HTMLElement;
  }

  it('renders the heading as a level-2 heading', async () => {
    const el = await render();
    expect(el.querySelector('h2')?.textContent?.trim()).toBe('Tags');
  });

  it('projects its content', async () => {
    const el = await render();
    expect(el.querySelector('.projected')?.textContent).toBe('body');
  });

  it('omits the description element when there is no description', async () => {
    const el = await render(null);
    expect(el.querySelector('.description')).toBeNull();
  });

  it('renders the description when one is given', async () => {
    const el = await render('Group your feeds.');
    expect(el.querySelector('.description')?.textContent?.trim()).toBe('Group your feeds.');
  });
});
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `cd /Users/lars/Documents/work/eigenes/simple-feed-reader/frontend && npx jest settings-card`
Expected: FAIL — cannot resolve `./settings-card.component`.

- [ ] **Step 3: Write the component**

`frontend/src/app/shared/settings-card/settings-card.component.ts`:

```ts
// src/app/shared/settings-card/settings-card.component.ts
import { ChangeDetectionStrategy, Component, input } from '@angular/core';

/**
 * The one surface a settings or admin section sits in: a heading, an optional
 * description, and the section's own content. Extracted in #180 Phase 4, when
 * five different card/panel treatments had accumulated across seven
 * stylesheets -- see docs/design-language.md.
 *
 * A card wraps a *section*, not a row. Rows stay plain rows inside one card;
 * the tags list used to give each row its own border and read as nested cards.
 *
 * `heading` and `description` take already-translated strings rather than i18n
 * keys, so this shared component never hardcodes a feature's translation keys
 * -- the caller resolves those with its own `transloco` pipe.
 */
@Component({
  selector: 'app-settings-card',
  templateUrl: './settings-card.component.html',
  styleUrl: './settings-card.component.scss',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class SettingsCardComponent {
  readonly heading = input.required<string>();

  /** `null` renders the card with no description line. */
  readonly description = input<string | null>(null);
}
```

`frontend/src/app/shared/settings-card/settings-card.component.html`:

```html
<!-- src/app/shared/settings-card/settings-card.component.html -->
<section class="card">
  <header class="head">
    <h2>{{ heading() }}</h2>
    <ng-content select="[cardActions]" />
  </header>
  @if (description(); as text) {
    <p class="description">{{ text }}</p>
  }
  <ng-content />
</section>
```

`frontend/src/app/shared/settings-card/settings-card.component.scss`:

```scss
.card {
  background: var(--surface-1);
  border: 1px solid var(--border);
  border-radius: var(--radius-lg);
  padding: var(--space-4);
}

.head {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: var(--space-3);
  margin-bottom: var(--space-3);
}

h2 {
  font-size: var(--fs-lg);
  margin: 0;
}

.description {
  color: var(--text-muted);
  font-size: var(--fs-sm);
  /* The description sits under the heading, so the heading's own bottom margin
     would double up with it. */
  margin: calc(var(--space-3) * -1 + var(--space-2)) 0 var(--space-3);
}
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `cd /Users/lars/Documents/work/eigenes/simple-feed-reader/frontend && npx jest settings-card`
Expected: PASS — 4 tests.

- [ ] **Step 5: Prove the tests are real**

Delete the `@if (description(); as text)` guard so the description always renders. Re-run: the "omits the description element" test must fail. Restore it.

- [ ] **Step 6: Document it in the catalog**

In `docs/design-language.md`, add these two entries to the shared component catalog, immediately after the `<app-error-banner>` entry, matching that entry's format exactly:

````markdown
### `<app-settings-card>`

The one surface a settings or admin section sits in: a heading, an optional
description line, and the section's own projected content. A `cardActions` slot
puts a control (a "New tag" button, a filter) on the heading row.

| Input | Type | Default |
|---|---|---|
| `heading` | `string` (required) | — |
| `description` | `string \| null` | `null` — omits the line |

```html
<app-settings-card [heading]="'settings.tags.title' | transloco">
  <app-button cardActions size="sm" variant="primary" (click)="manage.createTag()">
    {{ 'settings.tags.new' | transloco }}
  </app-button>
  <ul class="list">…</ul>
</app-settings-card>
```

`heading` and `description` take already-translated strings, not i18n keys — the
component lives in `shared/` and must not hardcode a feature's translation keys.
Extracted in #180 Phase 4, when five card/panel treatments had accumulated
across seven stylesheets.

**A card wraps a section, not a row.** Rows stay plain rows inside one card.
Giving each row its own border reads as nested cards — that is what the tags
list did before this component existed.

**Not for:** a dialog surface (use the CDK dialog with `panelClass: 'app-dialog'`)
or an overlay (`<app-overlay-panel>`).

---

### `<app-spinner>`

The RSS mark, animating, as the app's loading indicator. Used for a fetch whose
result is not a list — a list should show `<app-skeleton>` instead, so the
layout does not jump when rows arrive.

| Input | Type | Default |
|---|---|---|
| `size` | `number` (px) | `18` |
| `decorative` | `boolean` | `false` — keeps `role="status"` and the "Loading" label |
| `animate` | `boolean` | `true` |

```html
@if (loading()) {
  <app-spinner />
}
```

`decorative` is for the brand mark in the top bar, which is not announcing a
load. `animate: false` holds the mark still in the signal colour — the brand
mark only animates while a refresh is actually running.

**Not for:** a list load. Use `<app-skeleton>`.

---
````

- [ ] **Step 7: Run the full gate and commit**

```bash
cd /Users/lars/Documents/work/eigenes/simple-feed-reader/frontend
npx prettier --write src/app/shared/settings-card/ ../docs/design-language.md
npm run check
```

Expected: green.

```bash
cd /Users/lars/Documents/work/eigenes/simple-feed-reader
git add frontend/src/app/shared/settings-card docs/design-language.md
git commit -m "feat(settings): add the shared settings card (#180)"
```

---

### Task 2: The shared skeleton

**Files:**
- Create: `frontend/src/app/shared/skeleton/skeleton.component.ts`
- Create: `frontend/src/app/shared/skeleton/skeleton.component.html`
- Create: `frontend/src/app/shared/skeleton/skeleton.component.scss`
- Test: `frontend/src/app/shared/skeleton/skeleton.component.spec.ts`
- Modify: `docs/design-language.md`

**Interfaces:**
- Consumes: nothing.
- Produces: `SkeletonComponent`, selector `app-skeleton`, with `rows = input<number>(3)` and `label = input.required<string>()`. Tasks 4 and 5 use it.

- [ ] **Step 1: Write the failing test**

Create `frontend/src/app/shared/skeleton/skeleton.component.spec.ts`:

```ts
import { TestBed } from '@angular/core/testing';
import { SkeletonComponent } from './skeleton.component';

describe('SkeletonComponent', () => {
  async function render(rows?: number) {
    await TestBed.configureTestingModule({ imports: [SkeletonComponent] }).compileComponents();
    const fixture = TestBed.createComponent(SkeletonComponent);
    fixture.componentRef.setInput('label', 'Loading tags');
    if (rows !== undefined) fixture.componentRef.setInput('rows', rows);
    fixture.detectChanges();
    return fixture.nativeElement as HTMLElement;
  }

  it('renders three placeholder rows by default', async () => {
    const el = await render();
    expect(el.querySelectorAll('.row')).toHaveLength(3);
  });

  it('renders the requested number of rows', async () => {
    const el = await render(6);
    expect(el.querySelectorAll('.row')).toHaveLength(6);
  });

  it('announces the load with the given label', async () => {
    const el = await render();
    const status = el.querySelector('[role="status"]');
    expect(status?.getAttribute('aria-label')).toBe('Loading tags');
  });

  it('hides the placeholder rows from assistive technology', async () => {
    const el = await render();
    // The rows are decoration; the role=status label is what gets announced.
    expect(el.querySelector('.rows')?.getAttribute('aria-hidden')).toBe('true');
  });
});
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `cd /Users/lars/Documents/work/eigenes/simple-feed-reader/frontend && npx jest skeleton`
Expected: FAIL — cannot resolve `./skeleton.component`.

- [ ] **Step 3: Write the component**

`frontend/src/app/shared/skeleton/skeleton.component.ts`:

```ts
// src/app/shared/skeleton/skeleton.component.ts
import { ChangeDetectionStrategy, Component, computed, input } from '@angular/core';

/**
 * Placeholder rows for a list that is still loading. Sized from the same
 * comfortable row-density tokens the real rows use, so nothing shifts when the
 * data arrives -- which a spinner cannot do, because it does not know how tall
 * the list will be.
 *
 * `label` takes an already-translated string rather than an i18n key, so this
 * shared component never hardcodes a feature's translation keys.
 */
@Component({
  selector: 'app-skeleton',
  templateUrl: './skeleton.component.html',
  styleUrl: './skeleton.component.scss',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class SkeletonComponent {
  /** What is loading, announced to assistive technology. */
  readonly label = input.required<string>();

  /** How many placeholder rows to draw. */
  readonly rows = input<number>(3);

  protected readonly placeholders = computed(() => Array.from({ length: this.rows() }));
}
```

`frontend/src/app/shared/skeleton/skeleton.component.html`:

```html
<!-- src/app/shared/skeleton/skeleton.component.html -->
<div role="status" [attr.aria-label]="label()">
  <div class="rows" aria-hidden="true">
    @for (row of placeholders(); track $index) {
      <div class="row"></div>
    }
  </div>
</div>
```

`frontend/src/app/shared/skeleton/skeleton.component.scss`:

```scss
.rows {
  display: flex;
  flex-direction: column;
  gap: var(--space-1);
}

.row {
  /* Matches a comfortable list row: two padding steps plus a line of text. */
  height: calc(var(--row-pad-comfy-y) * 2 + var(--fs-base));
  border-radius: var(--radius);
  background: var(--surface-2);
  animation: pulse 1.4s ease-in-out infinite;
}

@keyframes pulse {
  0%,
  100% {
    opacity: 1;
  }

  50% {
    opacity: 0.45;
  }
}

/* A pulsing block is exactly the kind of motion that triggers vestibular
   symptoms; hold it still when the reader asks for reduced motion. */
@media (prefers-reduced-motion: reduce) {
  .row {
    animation: none;
  }
}
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `cd /Users/lars/Documents/work/eigenes/simple-feed-reader/frontend && npx jest skeleton`
Expected: PASS — 4 tests.

- [ ] **Step 5: Prove the tests are real**

Change the default `rows` from `3` to `4`: the "three placeholder rows by default" test must fail. Remove `aria-hidden="true"` from `.rows`: the fourth test must fail. Restore both.

- [ ] **Step 6: Document it in the catalog**

In `docs/design-language.md`, add this entry immediately after the `<app-spinner>` entry added in Task 1, in the same format:

````markdown
### `<app-skeleton>`

Placeholder rows for a list that is still loading. Sized from the comfortable
row-density tokens, so the layout does not shift when the real rows arrive.

| Input | Type | Default |
|---|---|---|
| `label` | `string` (required) | — |
| `rows` | `number` | `3` |

```html
@if (store.loading()) {
  <app-skeleton [label]="'settings.tags.loading' | transloco" [rows]="4" />
}
```

`label` takes an already-translated string, not an i18n key. The placeholder
rows are `aria-hidden`; the `role="status"` label is what gets announced. The
pulse animation is disabled under `prefers-reduced-motion`.

**Not for:** a non-list fetch — use `<app-spinner>`, which does not pretend to
know the shape of what is coming.

---
````

- [ ] **Step 7: Run the full gate and commit**

```bash
cd /Users/lars/Documents/work/eigenes/simple-feed-reader/frontend
npx prettier --write src/app/shared/skeleton/ ../docs/design-language.md
npm run check
```

Expected: green.

```bash
cd /Users/lars/Documents/work/eigenes/simple-feed-reader
git add frontend/src/app/shared/skeleton docs/design-language.md
git commit -m "feat(settings): add the shared list skeleton (#180)"
```

---

### Task 3: Convert the four simple settings sections

**Files:**
- Modify: `frontend/src/app/settings/about-section.component.{ts,html,scss}`
- Modify: `frontend/src/app/settings/account-section.component.{html,scss}`
- Modify: `frontend/src/app/settings/opml-section.component.{ts,html,scss}`
- Modify: `frontend/src/app/settings/preferences-section.component.{html,scss}`
- Modify: `frontend/src/app/admin/admin-user-detail.component.scss:37`
- Modify: `frontend/src/app/reader/entry-split.component.scss:59`
- Modify: `frontend/src/app/reader/entry-thumb.component.scss:52`
- Modify: `frontend/public/i18n/en.json`, `frontend/public/i18n/de.json`
- Test: the existing specs for these sections, plus new cases below

**Interfaces:**
- Consumes: `SettingsCardComponent` (Task 1), `SpinnerComponent` (`src/app/shared/spinner/spinner.component.ts`), `ErrorBannerComponent` (`src/app/shared/error-banner/error-banner.component.ts`).
- Produces: nothing new. Task 5 follows the same conversion shape for tags.

- [ ] **Step 1: Fix the three `--fs-md` references**

`--fs-md` is not defined in `frontend/src/app/theme/tokens.scss` — the scale is `--fs-xs`, `--fs-sm`, `--fs-base`, `--fs-read`, `--fs-lg`, `--fs-xl`. Three declarations reference it and silently resolve to nothing. Replace each with `--fs-base`:

- `frontend/src/app/admin/admin-user-detail.component.scss:37` — `h3 { font-size: var(--fs-md); }`
- `frontend/src/app/reader/entry-split.component.scss:59`
- `frontend/src/app/reader/entry-thumb.component.scss:52`

Verify none remain:

```bash
cd /Users/lars/Documents/work/eigenes/simple-feed-reader/frontend
grep -rn "fs-md" src/ && echo "STILL PRESENT" || echo "clean"
```

Expected: `clean`.

- [ ] **Step 2: Add the i18n keys**

In `frontend/public/i18n/en.json`, inside the existing `settings` object:

```json
"loadingVersion": "Loading version information",
"opmlError": "Import or export failed."
```

In `frontend/public/i18n/de.json`, in the same place:

```json
"loadingVersion": "Versionsinformationen werden geladen",
"opmlError": "Import oder Export ist fehlgeschlagen."
```

Both files must remain valid JSON:

```bash
cd /Users/lars/Documents/work/eigenes/simple-feed-reader/frontend
node -e "JSON.parse(require('fs').readFileSync('public/i18n/en.json'));JSON.parse(require('fs').readFileSync('public/i18n/de.json'));console.log('valid')"
```

- [ ] **Step 3: Write the failing tests**

Add to `frontend/src/app/settings/about-section.component.spec.ts` (create the file in the manner of the existing settings specs if it does not exist):

```ts
it('shows a spinner while the version is loading', async () => {
  const el = await renderWhileLoading();
  expect(el.querySelector('app-spinner')).not.toBeNull();
});

it('renders inside a settings card', async () => {
  const el = await renderLoaded();
  expect(el.querySelector('app-settings-card')).not.toBeNull();
});
```

Add to `frontend/src/app/settings/opml-section.component.spec.ts`:

```ts
it('reports a failed import through the shared error banner', async () => {
  const el = await renderAfterFailedImport();
  const banner = el.querySelector('app-error-banner');
  expect(banner).not.toBeNull();
  expect(el.querySelector('p.error')).toBeNull();
});
```

Write `renderWhileLoading`, `renderLoaded` and `renderAfterFailedImport` as local helpers that configure `TestBed`, stub the service the component injects, and drive it into that state — follow the arrangement already used in `frontend/src/app/admin/admin-users.component.spec.ts`.

- [ ] **Step 4: Run the tests to verify they fail**

Run: `cd /Users/lars/Documents/work/eigenes/simple-feed-reader/frontend && npx jest about-section opml-section`
Expected: FAIL — no `app-settings-card`, no `app-spinner`, no `app-error-banner`.

- [ ] **Step 5: Convert the four templates**

For each of About, Account, OPML and Preferences, replace the outer `<section>` and its `<h2>` with the card. The pattern, using OPML as the worked example — its template currently opens with `<section>` and a `<h2>`:

```html
<app-settings-card [heading]="'settings.opml.title' | transloco">
  @if (error(); as problem) {
    <app-error-banner [message]="problem.detail || problem.title" />
  }
  <!-- the section's existing content, unchanged -->
</app-settings-card>
```

Add `SettingsCardComponent` to each component's `imports` array, and `ErrorBannerComponent` / `SpinnerComponent` where the steps above require them. Delete the now-duplicated `h2` rule and, in OPML, the `.error` block at `opml-section.component.scss:29-33` and the per-block card rules at `:6-16` — the card supplies both.

For About, wrap the version rows:

```html
@if (loading()) {
  <app-spinner />
} @else {
  <!-- the existing version rows -->
}
```

- [ ] **Step 6: Run the tests to verify they pass**

Run: `cd /Users/lars/Documents/work/eigenes/simple-feed-reader/frontend && npx jest about-section opml-section account-section preferences-section`
Expected: PASS.

- [ ] **Step 7: Prove the tests are real**

Remove `SettingsCardComponent` from the About component's `imports` and unwrap its template: the "renders inside a settings card" test must fail. Restore. Point the OPML error back at a `<p class="error">`: the banner test must fail. Restore.

- [ ] **Step 8: Run the full gate and commit**

```bash
cd /Users/lars/Documents/work/eigenes/simple-feed-reader/frontend
npx prettier --write src/app/settings/ src/app/admin/ src/app/reader/ public/i18n/
npm run check
```

Expected: green.

```bash
cd /Users/lars/Documents/work/eigenes/simple-feed-reader
git add frontend/
git commit -m "refactor(settings): put the simple sections in the shared card (#180)"
```

---

### Task 4: Convert the three admin surfaces

**Files:**
- Modify: `frontend/src/app/admin/admin-users.component.{ts,html,scss}`
- Modify: `frontend/src/app/admin/admin-catalog.component.{ts,html,scss}`
- Modify: `frontend/src/app/admin/admin-user-detail.component.{ts,html,scss}`
- Modify: `frontend/public/i18n/en.json`, `frontend/public/i18n/de.json`
- Test: `frontend/src/app/admin/admin-users.component.spec.ts`, `admin-catalog.component.spec.ts`, `admin-user-detail.component.spec.ts`

**Interfaces:**
- Consumes: `SettingsCardComponent` (Task 1), `SkeletonComponent` (Task 2).
- Produces: nothing new.

- [ ] **Step 1: Add the i18n keys**

In `frontend/public/i18n/en.json`, inside the existing `admin` object:

```json
"loadingUsers": "Loading users",
"loadingCatalog": "Loading the catalog",
"loadingUser": "Loading the account"
```

In `frontend/public/i18n/de.json`:

```json
"loadingUsers": "Benutzer werden geladen",
"loadingCatalog": "Katalog wird geladen",
"loadingUser": "Konto wird geladen"
```

- [ ] **Step 2: Write the failing tests**

Add to `frontend/src/app/admin/admin-users.component.spec.ts`:

```ts
it('shows skeleton rows instead of a spinner while the list loads', async () => {
  const el = await renderWhileLoading();
  expect(el.querySelector('app-skeleton')).not.toBeNull();
  expect(el.querySelector('app-spinner')).toBeNull();
});

it('renders the list inside a settings card', async () => {
  const el = await renderWithUsers();
  expect(el.querySelector('app-settings-card')).not.toBeNull();
});
```

Add the equivalent pair to `admin-catalog.component.spec.ts` and `admin-user-detail.component.spec.ts`, using each file's existing render helpers.

- [ ] **Step 3: Run the tests to verify they fail**

Run: `cd /Users/lars/Documents/work/eigenes/simple-feed-reader/frontend && npx jest admin-users admin-catalog admin-user-detail`
Expected: FAIL — `app-skeleton` and `app-settings-card` are absent.

- [ ] **Step 4: Convert the three components**

In each, replace `<app-spinner />` in the list-loading branch with the skeleton, and wrap the section in the card:

```html
<app-settings-card [heading]="'admin.users.title' | transloco">
  @if (loading()) {
    <app-skeleton [label]="'admin.loadingUsers' | transloco" [rows]="5" />
  } @else if (error(); as problem) {
    <app-error-banner
      [message]="problem.detail || problem.title"
      [actionLabel]="'admin.retry' | transloco"
      (action)="load()"
    />
  } @else {
    <!-- the existing list -->
  }
</app-settings-card>
```

Add `SettingsCardComponent` and `SkeletonComponent` to each `imports` array; drop `SpinnerComponent` from any component that no longer uses it — a dead import fails ESLint. Delete each stylesheet's now-duplicated card/panel rules (`admin-catalog.component.scss:31,87`; `admin-user-detail.component.scss:52-58`) and its `h2` rule.

Keep `admin-user-detail`'s inner `<h3>` sub-headings inside the card — the card supplies one `h2`, and the detail page's sub-sections stay `h3`.

- [ ] **Step 5: Run the tests to verify they pass**

Run: `cd /Users/lars/Documents/work/eigenes/simple-feed-reader/frontend && npx jest admin`
Expected: PASS.

- [ ] **Step 6: Prove the tests are real**

In `admin-users.component.html`, put `<app-spinner />` back in the loading branch alongside the skeleton: the "instead of a spinner" test must fail. Restore.

- [ ] **Step 7: Run the full gate and commit**

```bash
cd /Users/lars/Documents/work/eigenes/simple-feed-reader/frontend
npx prettier --write src/app/admin/ public/i18n/
npm run check
```

Expected: green.

```bash
cd /Users/lars/Documents/work/eigenes/simple-feed-reader
git add frontend/
git commit -m "refactor(admin): shared card and list skeletons on the admin screens (#180)"
```

---

### Task 5: The tags section — card, loading, and the empty-state bug

**Files:**
- Modify: `frontend/src/app/settings/tags-section.component.ts`
- Modify: `frontend/src/app/settings/tags-section.component.html`
- Modify: `frontend/src/app/settings/tags-section.component.scss`
- Create: `frontend/src/app/settings/tags-section.component.spec.ts`
- Modify: `frontend/public/i18n/en.json`, `frontend/public/i18n/de.json`

**Interfaces:**
- Consumes: `SettingsCardComponent` (Task 1), `SkeletonComponent` (Task 2), `ErrorBannerComponent`, and `TagsStore` (`src/app/reader/tags.store.ts`) which already exposes `tags: Signal<TagDto[]>`, `loading: Signal<boolean>`, `error: Signal<Problem | null>` and `load(): void`.
- Produces: a `tags-section.component.spec.ts` with render helpers that Tasks 6 and 7 extend.

**This task fixes a real bug.** `tags-section.component.html`'s only branch is `@if (tagsStore.tags().length === 0)`, and `TagsStore.load()` starts with an empty array — so every time the section loads, a user who *has* tags is told "You have no tags" until the response lands.

- [ ] **Step 1: Add the i18n keys**

In `frontend/public/i18n/en.json`, inside the existing `settings.tags` object:

```json
"loading": "Loading tags",
"loadFailed": "Could not load your tags.",
"retry": "Try again"
```

In `frontend/public/i18n/de.json`:

```json
"loading": "Tags werden geladen",
"loadFailed": "Deine Tags konnten nicht geladen werden.",
"retry": "Erneut versuchen"
```

- [ ] **Step 2: Write the failing test**

Create `frontend/src/app/settings/tags-section.component.spec.ts`:

```ts
import { TestBed } from '@angular/core/testing';
import { signal } from '@angular/core';
import { TranslocoTestingModule } from '@jsverse/transloco';
import { TagsSectionComponent } from './tags-section.component';
import { TagsStore } from '../reader/tags.store';
import { SubscriptionsStore } from '../reader/subscriptions.store';
import { ManageActions } from '../reader/manage/manage-actions.service';
import { Problem } from '../core/problem';
import { TagDto } from '../reader/models';
import en from '../../../public/i18n/en.json';

const TAG: TagDto = { id: 1, name: 'Tech', color: '#ff8800', icon: 'memory', position: 0 };

describe('TagsSectionComponent', () => {
  let tags: ReturnType<typeof signal<TagDto[]>>;
  let loading: ReturnType<typeof signal<boolean>>;
  let error: ReturnType<typeof signal<Problem | null>>;

  async function render() {
    tags = signal<TagDto[]>([]);
    loading = signal(false);
    error = signal<Problem | null>(null);

    await TestBed.configureTestingModule({
      imports: [
        TagsSectionComponent,
        TranslocoTestingModule.forRoot({
          langs: { en },
          translocoConfig: { availableLangs: ['en'], defaultLang: 'en' },
        }),
      ],
      providers: [
        { provide: TagsStore, useValue: { tags, loading, error, load: jest.fn() } },
        { provide: SubscriptionsStore, useValue: { subscriptions: signal([]), load: jest.fn() } },
        { provide: ManageActions, useValue: { createTag: jest.fn(), deleteTag: jest.fn() } },
      ],
    }).compileComponents();

    const fixture = TestBed.createComponent(TagsSectionComponent);
    fixture.detectChanges();
    return { fixture, el: fixture.nativeElement as HTMLElement };
  }

  it('does not claim the list is empty while it is still loading', async () => {
    const { fixture, el } = await render();
    loading.set(true);
    fixture.detectChanges();

    expect(el.querySelector('app-skeleton')).not.toBeNull();
    expect(el.textContent).not.toContain(en.settings.tags.none);
  });

  it('shows the empty state only once loading has finished with no tags', async () => {
    const { fixture, el } = await render();
    loading.set(false);
    fixture.detectChanges();

    expect(el.querySelector('app-skeleton')).toBeNull();
    expect(el.textContent).toContain(en.settings.tags.none);
  });

  it('shows the tag list once tags arrive', async () => {
    const { fixture, el } = await render();
    tags.set([TAG]);
    fixture.detectChanges();

    expect(el.textContent).toContain('Tech');
    expect(el.textContent).not.toContain(en.settings.tags.none);
  });

  it('reports a failed load through the shared error banner, with a retry', async () => {
    const { fixture, el } = await render();
    error.set({ title: 'Server error', detail: 'Could not load your tags.', status: 500 });
    fixture.detectChanges();

    expect(el.querySelector('app-error-banner')).not.toBeNull();
    expect(el.textContent).not.toContain(en.settings.tags.none);
  });
});
```

If `Problem`'s shape differs from `{ title, detail, status }`, read `frontend/src/app/core/problem.ts` and build the literal to match — do not weaken the assertion.

- [ ] **Step 3: Run the test to verify it fails**

Run: `cd /Users/lars/Documents/work/eigenes/simple-feed-reader/frontend && npx jest tags-section`
Expected: FAIL — the first test fails because the empty state renders during loading, which is the bug.

- [ ] **Step 4: Rewrite the template**

`frontend/src/app/settings/tags-section.component.html`:

```html
<app-settings-card [heading]="'settings.tags.title' | transloco">
  <app-button
    cardActions
    class="new"
    size="sm"
    variant="primary"
    (click)="manage.createTag()"
  >
    <app-icon name="add" size="sm" /> {{ 'settings.tags.new' | transloco }}
  </app-button>

  @if (tagsStore.loading()) {
    <app-skeleton [label]="'settings.tags.loading' | transloco" [rows]="4" />
  } @else if (tagsStore.error(); as problem) {
    <app-error-banner
      [message]="problem.detail || problem.title"
      [actionLabel]="'settings.tags.retry' | transloco"
      (action)="tagsStore.load()"
    />
  } @else if (tagsStore.tags().length === 0) {
    <p class="muted">{{ 'settings.tags.none' | transloco }}</p>
  } @else {
    <ul class="list">
      @for (t of tagsStore.tags(); track t.id) {
        <li class="tag">
          <span class="ident">
            <span class="dot" [style.background]="t.color || 'var(--text-muted)'"></span>
            @if (t.icon) {
              <app-icon [name]="t.icon" size="sm" />
            }
            <span class="name">{{ t.name }}</span>
            <span class="count">{{
              ((usage()[t.id] || 0) === 1
                ? 'settings.tags.feedCountOne'
                : 'settings.tags.feedCountOther'
              ) | transloco: { count: usage()[t.id] || 0 }
            }}</span>
          </span>
          <span class="acts">
            <app-button size="sm" (click)="manage.editTag(t)">
              <app-icon name="edit" size="sm" /> {{ 'settings.tags.edit' | transloco }}
            </app-button>
            <app-button size="sm" variant="danger-outline" (click)="manage.deleteTag(t)">
              <app-icon name="delete" size="sm" /> {{ 'settings.tags.delete' | transloco }}
            </app-button>
          </span>
        </li>
      }
    </ul>
  }
</app-settings-card>
```

The branch order is the whole fix: loading, then error, then empty, then the list. The empty state is unreachable until a load has finished.

- [ ] **Step 5: Update the component's imports**

In `frontend/src/app/settings/tags-section.component.ts`, add to the `imports` array:

```ts
imports: [
  ButtonComponent,
  IconComponent,
  TranslocoPipe,
  SettingsCardComponent,
  SkeletonComponent,
  ErrorBannerComponent,
],
```

with the matching import statements:

```ts
import { SettingsCardComponent } from '../shared/settings-card/settings-card.component';
import { SkeletonComponent } from '../shared/skeleton/skeleton.component';
import { ErrorBannerComponent } from '../shared/error-banner/error-banner.component';
```

- [ ] **Step 6: Drop the per-row card from the stylesheet**

In `frontend/src/app/settings/tags-section.component.scss`, remove the `border`, `border-radius` and `background` declarations from `.tag` (lines 33-37) — rows are rows inside one card now — and delete the `.head` and `h2` rules, which the card supplies. Keep the padding, the flex layout and the narrow-screen block.

- [ ] **Step 7: Run the tests to verify they pass**

Run: `cd /Users/lars/Documents/work/eigenes/simple-feed-reader/frontend && npx jest tags-section`
Expected: PASS — 4 tests.

- [ ] **Step 8: Prove the tests are real**

Move the `@else if (tagsStore.tags().length === 0)` branch above the loading branch, reproducing the original bug: the first test must fail. Restore the order.

- [ ] **Step 9: Run the full gate and commit**

```bash
cd /Users/lars/Documents/work/eigenes/simple-feed-reader/frontend
npx prettier --write src/app/settings/ public/i18n/
npm run check
```

Expected: green.

```bash
cd /Users/lars/Documents/work/eigenes/simple-feed-reader
git add frontend/
git commit -m "fix(settings): stop the tags list claiming to be empty while it loads (#180)"
```

---

### Task 6: Drag-to-reorder in the tags section

**Files:**
- Modify: `frontend/src/app/settings/tags-section.component.ts`
- Modify: `frontend/src/app/settings/tags-section.component.html`
- Modify: `frontend/src/app/settings/tags-section.component.scss`
- Modify: `frontend/src/app/settings/tags-section.component.spec.ts`
- Modify: `frontend/public/i18n/en.json`, `frontend/public/i18n/de.json`

**Interfaces:**
- Consumes: `ManageActions.reorderTags(tagIds: number[]): void` (`src/app/reader/manage/manage-actions.service.ts:74`), which updates `TagsStore` optimistically and then calls `PATCH /api/tags/reorder`, reconciling with `TagsStore.load()`.
- Produces: `onTagDrop(event: CdkDragDrop<TagDto[]>): void` on `TagsSectionComponent`.

**No backend work.** `Tag.position` exists, `TagController::create` assigns it, and `PATCH /api/tags/reorder` persists a full permutation of the user's tag ids. The reader sidebar already drives all of it.

**The list is one flat `cdkDropList` of sibling rows.** Do not nest drop lists — nesting silently breaks cross-list drag, which is a standing project rule and has cost this project once already.

- [ ] **Step 1: Add the i18n key**

`frontend/public/i18n/en.json`, in `settings.tags`:

```json
"reorder": "Reorder {{name}}"
```

`frontend/public/i18n/de.json`:

```json
"reorder": "{{name}} verschieben"
```

- [ ] **Step 2: Write the failing test**

Add to `frontend/src/app/settings/tags-section.component.spec.ts`. Extend the `ManageActions` stub in `render()` with `reorderTags: jest.fn()` first, and capture it so the test can assert on it.

```ts
it('persists the new order when a row is dropped', async () => {
  const { fixture } = await render();
  tags.set([
    { id: 1, name: 'Tech', color: null, icon: null, position: 0 },
    { id: 2, name: 'News', color: null, icon: null, position: 1 },
    { id: 3, name: 'Fun', color: null, icon: null, position: 2 },
  ]);
  fixture.detectChanges();

  const component = fixture.componentInstance;
  component.onTagDrop({ previousIndex: 2, currentIndex: 0 } as CdkDragDrop<TagDto[]>);

  const manage = TestBed.inject(ManageActions);
  expect(manage.reorderTags).toHaveBeenCalledWith([3, 1, 2]);
});

it('ignores a drop that does not move the row', async () => {
  const { fixture } = await render();
  tags.set([
    { id: 1, name: 'Tech', color: null, icon: null, position: 0 },
    { id: 2, name: 'News', color: null, icon: null, position: 1 },
  ]);
  fixture.detectChanges();

  fixture.componentInstance.onTagDrop({
    previousIndex: 1,
    currentIndex: 1,
  } as CdkDragDrop<TagDto[]>);

  expect(TestBed.inject(ManageActions).reorderTags).not.toHaveBeenCalled();
});

it('gives every row a drag handle', async () => {
  const { fixture, el } = await render();
  tags.set([{ id: 1, name: 'Tech', color: null, icon: null, position: 0 }]);
  fixture.detectChanges();

  expect(el.querySelectorAll('.drag-handle')).toHaveLength(1);
});
```

Add the import: `import { CdkDragDrop } from '@angular/cdk/drag-drop';`

- [ ] **Step 3: Run the tests to verify they fail**

Run: `cd /Users/lars/Documents/work/eigenes/simple-feed-reader/frontend && npx jest tags-section`
Expected: FAIL — `onTagDrop` is not a function.

- [ ] **Step 4: Add the drop handler**

In `frontend/src/app/settings/tags-section.component.ts`:

```ts
import { CdkDrag, CdkDragDrop, CdkDragHandle, CdkDropList } from '@angular/cdk/drag-drop';
import { TagDto } from '../reader/models';
```

Add `CdkDropList`, `CdkDrag` and `CdkDragHandle` to `imports`, and the method:

```ts
/**
 * Persist a new tag order after a drop. The whole list is one flat drop list:
 * nesting cdkDropLists silently breaks cross-list drag, which is a standing
 * rule in this project (see CLAUDE.md).
 */
onTagDrop(event: CdkDragDrop<TagDto[]>): void {
  if (event.previousIndex === event.currentIndex) return;

  const ids = this.tagsStore.tags().map((t) => t.id);
  const [moved] = ids.splice(event.previousIndex, 1);
  ids.splice(event.currentIndex, 0, moved);
  this.manage.reorderTags(ids);
}
```

- [ ] **Step 5: Wire the template**

In `frontend/src/app/settings/tags-section.component.html`, make the `<ul>` the drop list and each `<li>` a draggable with a handle:

```html
<ul class="list" cdkDropList (cdkDropListDropped)="onTagDrop($event)">
  @for (t of tagsStore.tags(); track t.id) {
    <li class="tag" cdkDrag [cdkDragData]="t">
      <button
        type="button"
        class="drag-handle"
        cdkDragHandle
        [attr.aria-label]="'settings.tags.reorder' | transloco: { name: t.name }"
      >
        <app-icon name="drag_indicator" size="sm" />
      </button>
      <!-- the existing .ident and .acts spans, unchanged -->
    </li>
  }
</ul>
```

The handle is explicit rather than dragging the whole row, because Task 7 turns the row into an editable form and a whole-row drag target would fight the controls.

- [ ] **Step 6: Style the handle**

Append to `frontend/src/app/settings/tags-section.component.scss`:

```scss
.drag-handle {
  display: inline-flex;
  align-items: center;
  background: none;
  border: 0;
  padding: 0;
  color: var(--text-muted);
  cursor: grab;
  flex: 0 0 auto;
}

.drag-handle:active {
  cursor: grabbing;
}

/* The CDK clones the row into an overlay while dragging; without a matching
   surface the clone renders transparent over the page. */
.tag.cdk-drag-preview {
  background: var(--surface-1);
  border: 1px solid var(--border);
  border-radius: var(--radius);
  padding: var(--row-pad-comfy-y) var(--row-pad-comfy-x);
}
```

- [ ] **Step 7: Run the tests to verify they pass**

Run: `cd /Users/lars/Documents/work/eigenes/simple-feed-reader/frontend && npx jest tags-section`
Expected: PASS — 7 tests.

- [ ] **Step 8: Prove the tests are real**

Delete the `if (event.previousIndex === event.currentIndex) return;` guard: the "ignores a drop that does not move the row" test must fail. Change `splice(event.currentIndex, 0, moved)` to append instead: the first test must fail. Restore both.

- [ ] **Step 9: Run the full gate and commit**

```bash
cd /Users/lars/Documents/work/eigenes/simple-feed-reader/frontend
npx prettier --write src/app/settings/ public/i18n/
npm run check
```

Expected: green.

```bash
cd /Users/lars/Documents/work/eigenes/simple-feed-reader
git add frontend/
git commit -m "feat(settings): drag to reorder tags in the settings list (#180)"
```

---

### Task 7: Inline tag editing

**Files:**
- Modify: `frontend/src/app/settings/tags-section.component.ts`
- Modify: `frontend/src/app/settings/tags-section.component.html`
- Modify: `frontend/src/app/settings/tags-section.component.scss`
- Modify: `frontend/src/app/settings/tags-section.component.spec.ts`
- Modify: `frontend/public/i18n/en.json`, `frontend/public/i18n/de.json`

**Interfaces:**
- Consumes: `ReaderApi.updateTag(id: number, body: TagInput): Observable<{ tag: TagDto }>` (`src/app/reader/reader-api.ts:109`), where `TagInput` is `{ name: string; color: string | null; icon: string | null }`; `TagsStore.load()`; `SubscriptionsStore.load()`; and the field primitives `app-field`, `app-color-field`, `app-icon-picker`.
- Produces: `editingId: Signal<number | null>`, `startEdit(tag: TagDto): void`, `cancelEdit(): void`, `saveEdit(): void` on `TagsSectionComponent`.

**`TagFormDialogComponent` is not touched.** The reader sidebar keeps opening it, and creating a tag from settings keeps using it. Only editing an existing tag from the settings list becomes inline — the sidebar is too narrow for a form, settings has the room.

- [ ] **Step 1: Add the i18n keys**

`frontend/public/i18n/en.json`, in `settings.tags`:

```json
"save": "Save",
"cancel": "Cancel",
"editing": "Editing {{name}}",
"saveFailed": "Could not save the tag."
```

`frontend/public/i18n/de.json`:

```json
"save": "Speichern",
"cancel": "Abbrechen",
"editing": "{{name}} wird bearbeitet",
"saveFailed": "Der Tag konnte nicht gespeichert werden."
```

- [ ] **Step 2: Write the failing tests**

Add to `frontend/src/app/settings/tags-section.component.spec.ts`. Add `{ provide: ReaderApi, useValue: { updateTag: jest.fn(() => of({ tag: TAG })) } }` to the providers in `render()`, and import `of` from `rxjs` and `ReaderApi` from `../reader/reader-api`.

```ts
it('opens an inline editor on the row and not the dialog', async () => {
  const { fixture, el } = await render();
  tags.set([TAG]);
  fixture.detectChanges();

  fixture.componentInstance.startEdit(TAG);
  fixture.detectChanges();

  expect(el.querySelector('.tag .editor')).not.toBeNull();
  expect(TestBed.inject(ManageActions).editTag).not.toHaveBeenCalled();
});

it('saves through updateTag and reloads', async () => {
  const { fixture } = await render();
  tags.set([TAG]);
  fixture.detectChanges();

  const component = fixture.componentInstance;
  component.startEdit(TAG);
  component.draftName.set('Technology');
  component.saveEdit();

  expect(TestBed.inject(ReaderApi).updateTag).toHaveBeenCalledWith(1, {
    name: 'Technology',
    color: '#ff8800',
    icon: 'memory',
  });
  expect(component.editingId()).toBeNull();
});

it('cancels without saving', async () => {
  const { fixture } = await render();
  tags.set([TAG]);
  fixture.detectChanges();

  const component = fixture.componentInstance;
  component.startEdit(TAG);
  component.draftName.set('Technology');
  component.cancelEdit();

  expect(TestBed.inject(ReaderApi).updateTag).not.toHaveBeenCalled();
  expect(component.editingId()).toBeNull();
});

it('edits only one row at a time', async () => {
  const { fixture, el } = await render();
  const second: TagDto = { id: 2, name: 'News', color: null, icon: null, position: 1 };
  tags.set([TAG, second]);
  fixture.detectChanges();

  const component = fixture.componentInstance;
  component.startEdit(TAG);
  component.startEdit(second);
  fixture.detectChanges();

  expect(component.editingId()).toBe(2);
  expect(el.querySelectorAll('.editor')).toHaveLength(1);
});

it('refuses to save an empty name', async () => {
  const { fixture } = await render();
  tags.set([TAG]);
  fixture.detectChanges();

  const component = fixture.componentInstance;
  component.startEdit(TAG);
  component.draftName.set('   ');
  component.saveEdit();

  expect(TestBed.inject(ReaderApi).updateTag).not.toHaveBeenCalled();
  expect(component.editingId()).toBe(1);
});
```

- [ ] **Step 3: Run the tests to verify they fail**

Run: `cd /Users/lars/Documents/work/eigenes/simple-feed-reader/frontend && npx jest tags-section`
Expected: FAIL — `startEdit` is not a function.

- [ ] **Step 4: Add the editing state**

In `frontend/src/app/settings/tags-section.component.ts`:

```ts
import { signal } from '@angular/core';
import { ReaderApi } from '../reader/reader-api';
```

```ts
private readonly api = inject(ReaderApi);

/** The row currently in edit mode, or null. Only one row edits at a time. */
readonly editingId = signal<number | null>(null);
readonly draftName = signal('');
readonly draftColor = signal<string | null>(null);
readonly draftIcon = signal<string | null>(null);
readonly saveError = signal(false);

startEdit(tag: TagDto): void {
  this.editingId.set(tag.id);
  this.draftName.set(tag.name);
  this.draftColor.set(tag.color);
  this.draftIcon.set(tag.icon);
  this.saveError.set(false);
}

cancelEdit(): void {
  this.editingId.set(null);
}

saveEdit(): void {
  const id = this.editingId();
  const name = this.draftName().trim();
  // An empty name is the one client-side rule: the server rejects it too, but
  // sending a request we know will 422 just to be told so is wasteful.
  if (id === null || name === '') return;

  this.api.updateTag(id, { name, color: this.draftColor(), icon: this.draftIcon() }).subscribe({
    next: () => {
      this.editingId.set(null);
      this.tagsStore.load();
      this.subs.load(); // the embedded tag colour and name on each feed changed too
    },
    error: () => this.saveError.set(true),
  });
}
```

Change `private readonly subs = inject(SubscriptionsStore);` to `readonly subs` if the template needs it; otherwise leave it private.

- [ ] **Step 5: Add the inline editor to the template**

Replace the row's `.ident` / `.acts` pair with a branch on `editingId()`:

Note the primitives' real API, taken from `tag-form-dialog.component.html`:
`app-field` takes `[label]` and **projects** the `<input>` — it has no `value`
input of its own. `app-color-field` and `app-icon-picker` take
`[value]` / `(valueChange)`, and the icon picker uses `''` for "no icon", not
`null`.

```html
@if (editingId() === t.id) {
  <div class="editor">
    <app-field [label]="'dialog.tagForm.name' | transloco">
      <input
        [value]="draftName()"
        maxlength="100"
        (input)="draftName.set($any($event.target).value)"
        (keydown.escape)="cancelEdit()"
        (keydown.enter)="saveEdit()"
      />
    </app-field>

    <p class="lbl">{{ 'dialog.tagForm.colour' | transloco }}</p>
    <app-color-field [value]="draftColor()" (valueChange)="draftColor.set($event)">
      {{ 'dialog.tagForm.none' | transloco }}
    </app-color-field>

    <p class="lbl">{{ 'dialog.tagForm.icon' | transloco }}</p>
    <app-icon-picker
      inline
      [value]="draftIcon() ?? ''"
      (valueChange)="draftIcon.set($event || null)"
    />

    @if (saveError()) {
      <app-error-banner [message]="'settings.tags.saveFailed' | transloco" />
    }
    <span class="acts">
      <app-button size="sm" variant="primary" (click)="saveEdit()">
        {{ 'settings.tags.save' | transloco }}
      </app-button>
      <app-button size="sm" (click)="cancelEdit()">
        {{ 'settings.tags.cancel' | transloco }}
      </app-button>
    </span>
  </div>
} @else {
  <!-- the existing .ident span, unchanged -->
  <span class="acts">
    <app-button size="sm" (click)="startEdit(t)">
      <app-icon name="edit" size="sm" /> {{ 'settings.tags.edit' | transloco }}
    </app-button>
    <app-button size="sm" variant="danger-outline" (click)="manage.deleteTag(t)">
      <app-icon name="delete" size="sm" /> {{ 'settings.tags.delete' | transloco }}
    </app-button>
  </span>
}
```

Reuse `dialog.tagForm.name`, `dialog.tagForm.colour`, `dialog.tagForm.icon` and `dialog.tagForm.none` — the dialog's existing keys — rather than adding settings-scoped duplicates for the same three labels. Add `FieldComponent`, `ColorFieldComponent`, `IconPickerComponent` and `ErrorBannerComponent` to `imports`.

- [ ] **Step 6: Style the editor**

Append to `frontend/src/app/settings/tags-section.component.scss`:

```scss
.editor {
  display: flex;
  flex-direction: column;
  gap: var(--space-2);
  flex: 1 1 auto;
  min-width: 0;
}

.tag:has(.editor) {
  align-items: flex-start;
}
```

- [ ] **Step 7: Run the tests to verify they pass**

Run: `cd /Users/lars/Documents/work/eigenes/simple-feed-reader/frontend && npx jest tags-section`
Expected: PASS — 12 tests.

- [ ] **Step 8: Prove the tests are real**

Remove the `name === ''` guard from `saveEdit()`: the "refuses to save an empty name" test must fail. Change `editingId.set(tag.id)` in `startEdit` to append to an array of open rows: the "only one row at a time" test must fail. Restore both.

- [ ] **Step 9: Run the full gate and commit**

```bash
cd /Users/lars/Documents/work/eigenes/simple-feed-reader/frontend
npx prettier --write src/app/settings/ public/i18n/
npm run check
```

Expected: green.

```bash
cd /Users/lars/Documents/work/eigenes/simple-feed-reader
git add frontend/
git commit -m "feat(settings): edit a tag inline instead of in a dialog (#180)"
```

---

### Task 8: The locale write endpoint

**Files:**
- Modify: `backend/src/Controller/Api/MeController.php`
- Create: `backend/src/Dto/Me/UpdateLocaleRequest.php`
- Test: `backend/tests/Controller/Api/MeControllerTest.php`

**Interfaces:**
- Consumes: `User::getLocale(): string` (`backend/src/Entity/User.php:204`) and `User::setLocale(string $locale): void` (`:209`). Both already exist; the entity needs no change.
- Produces: `GET /api/me` returning `{ id, email, roles, status, createdAt, locale }`, and `PATCH /api/me` accepting `{ "locale": "en" | "de" }` and returning the same shape. Task 9 consumes both.

- [ ] **Step 1: Write the failing test**

Add to `backend/tests/Controller/Api/MeControllerTest.php` (create it in the manner of `backend/tests/Controller/Admin/AdminUserControllerTest.php` if absent):

```php
public function testTheProfileCarriesTheAccountLocale(): void
{
    $client = static::createClient();
    $user = UserFactory::create($this->entityManager(), 'locale-reader@example.test');
    $user->setLocale('de');
    $this->entityManager()->flush();

    $this->authenticate($client, 'locale-reader@example.test');
    $client->request('GET', '/api/me');

    self::assertResponseIsSuccessful();
    self::assertSame('de', $this->payload($client)['locale']);
}

public function testChangingTheLocalePersistsIt(): void
{
    $client = static::createClient();
    UserFactory::create($this->entityManager(), 'switcher@example.test');
    $this->authenticate($client, 'switcher@example.test');

    $client->request('PATCH', '/api/me', server: ['CONTENT_TYPE' => 'application/json'], content: '{"locale":"de"}');

    self::assertResponseIsSuccessful();
    self::assertSame('de', $this->payload($client)['locale']);

    $this->entityManager()->clear();
    $reloaded = $this->users()->findOneBy(['email' => 'switcher@example.test']);
    self::assertNotNull($reloaded);
    self::assertSame('de', $reloaded->getLocale());
}

public function testAnUnsupportedLocaleIsRejected(): void
{
    $client = static::createClient();
    UserFactory::create($this->entityManager(), 'klingon@example.test');
    $this->authenticate($client, 'klingon@example.test');

    $client->request('PATCH', '/api/me', server: ['CONTENT_TYPE' => 'application/json'], content: '{"locale":"tlh"}');

    self::assertResponseStatusCodeSame(422);
    self::assertStringStartsWith('application/problem+json', (string) $client->getResponse()->headers->get('Content-Type'));

    $this->entityManager()->clear();
    $unchanged = $this->users()->findOneBy(['email' => 'klingon@example.test']);
    self::assertNotNull($unchanged);
    self::assertSame('en', $unchanged->getLocale());
}

public function testChangingTheLocaleRequiresAuthentication(): void
{
    $client = static::createClient();
    $client->request('PATCH', '/api/me', server: ['CONTENT_TYPE' => 'application/json'], content: '{"locale":"de"}');

    self::assertResponseStatusCodeSame(401);
}
```

Reuse the `authenticate()`, `payload()`, `entityManager()` and `users()` helpers already present in the admin controller test; if the class does not exist yet, copy their shape from there rather than inventing new ones.

- [ ] **Step 2: Run the test to verify it fails**

Run: `cd /Users/lars/Documents/work/eigenes/simple-feed-reader/backend && php bin/phpunit --filter MeControllerTest`
Expected: FAIL — no `locale` key, and `PATCH /api/me` returns 405.

- [ ] **Step 3: Write the request DTO**

`backend/src/Dto/Me/UpdateLocaleRequest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Dto\Me;

use Symfony\Component\Validator\Constraints as Assert;

final readonly class UpdateLocaleRequest
{
    /**
     * The languages the UI ships translations for. An unsupported value is a
     * 422 rather than a silent fall back to English: a locale that degrades
     * quietly is exactly how User.locale went unwritten and unnoticed since
     * registration (#180).
     */
    public const array SUPPORTED = ['en', 'de'];

    public function __construct(
        #[Assert\NotBlank]
        #[Assert\Choice(choices: self::SUPPORTED)]
        public string $locale = '',
    ) {
    }
}
```

- [ ] **Step 4: Convert the controller**

`backend/src/Controller/Api/MeController.php`:

```php
<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Dto\Me\UpdateLocaleRequest;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * The client's view of its own account. Deliberately hand-built rather than
 * serialised from the entity, so a column added later (a password hash, a
 * token, an internal flag) cannot leak into the response by default.
 */
final readonly class MeController
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    #[Route('/api/me', name: 'api_me', methods: ['GET'])]
    public function show(#[CurrentUser] User $user): JsonResponse
    {
        return new JsonResponse($this->profile($user));
    }

    /**
     * The account's language. The server is the source of truth: the SPA caches
     * it per device, but this is what AccountMailer reads to pick the language
     * of every transactional email, and what a native client has to read
     * because it cannot see browser storage.
     */
    #[Route('/api/me', name: 'api_me_update_locale', methods: ['PATCH'])]
    public function updateLocale(
        #[CurrentUser] User $user,
        #[MapRequestPayload] UpdateLocaleRequest $request,
    ): JsonResponse {
        $user->setLocale($request->locale);
        $this->entityManager->flush();

        return new JsonResponse($this->profile($user));
    }

    /**
     * @return array<string, mixed>
     */
    private function profile(User $user): array
    {
        return [
            'id' => $user->getId(),
            'email' => $user->getEmail(),
            'roles' => $user->getRoles(),
            'status' => $user->getStatus()->value,
            'createdAt' => $user->getCreatedAt()->format(\DateTimeInterface::ATOM),
            'locale' => $user->getLocale(),
        ];
    }
}
```

`User::getLocale(): string` and `User::setLocale(string $locale): void` already exist at `backend/src/Entity/User.php:204` and `:209` — no entity change is needed.

- [ ] **Step 5: Run the test to verify it passes**

Run: `cd /Users/lars/Documents/work/eigenes/simple-feed-reader/backend && php bin/phpunit --filter MeControllerTest`
Expected: PASS — 4 tests.

- [ ] **Step 6: Prove the tests are real**

Widen the `Assert\Choice` to accept any string: the "unsupported locale" test must fail. Remove `'locale' => $user->getLocale()` from `profile()`: the first two tests must fail. Restore both.

- [ ] **Step 7: Run the backend gates and commit**

```bash
cd /Users/lars/Documents/work/eigenes/simple-feed-reader/backend
composer cs
bin/console cache:warmup && composer stan
php bin/phpunit
vendor/bin/phpmd src/Controller/Api/MeController.php text phpmd.xml.dist; echo "exit=$?"
vendor/bin/phpmd src/Dto/Me/UpdateLocaleRequest.php text phpmd.xml.dist; echo "exit=$?"
```

Expected: all clean, both phpmd runs exit 0.

```bash
cd /Users/lars/Documents/work/eigenes/simple-feed-reader
git add backend/
git commit -m "feat(me): let an account change its own locale (#180)"
```

---

### Task 9: Language write-through on the client

**Files:**
- Modify: `frontend/src/app/core/auth.service.ts`
- Modify: `frontend/src/app/core/language.service.ts`
- Test: `frontend/src/app/core/language.service.spec.ts`
- Modify: `frontend/public/i18n/en.json`, `frontend/public/i18n/de.json`

**Interfaces:**
- Consumes: `GET /api/me` now returning `locale`, and `PATCH /api/me` accepting `{ locale }` (Task 8). `Lang`, `LANGS`, `LANG_KEY`, `asLang`, `detectLang` from `frontend/src/app/core/language.ts`. `AuthService.user: Signal<CurrentUser | null>` and `AuthService.loadMe(): Observable<CurrentUser>`.
- Produces: `CurrentUser` gains `locale: string`; `AuthService.updateLocale(locale: Lang): Observable<CurrentUser>`; `LanguageService.adopt(locale: string | null): void`.

- [ ] **Step 1: Add the i18n key**

`frontend/public/i18n/en.json`, in `settings`:

```json
"languageSaveFailed": "The language changed on this device, but could not be saved to your account."
```

`frontend/public/i18n/de.json`:

```json
"languageSaveFailed": "Die Sprache wurde auf diesem Gerät geändert, konnte aber nicht in deinem Konto gespeichert werden."
```

- [ ] **Step 2: Write the failing test**

Create `frontend/src/app/core/language.service.spec.ts`:

```ts
import { TestBed } from '@angular/core/testing';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { provideHttpClient } from '@angular/common/http';
import { getTranslocoModule } from '../../testing/transloco-testing';
import { LanguageService } from './language.service';
import { LANG_KEY } from './language';
import { API_BASE_URL } from './api';

describe('LanguageService', () => {
  let http: HttpTestingController;

  beforeEach(() => {
    localStorage.clear();
    TestBed.configureTestingModule({
      imports: [getTranslocoModule()],
      providers: [
        provideHttpClient(),
        provideHttpClientTesting(),
        { provide: API_BASE_URL, useValue: '' },
      ],
    });
    http = TestBed.inject(HttpTestingController);
  });

  afterEach(() => http.verify());

  it('writes the chosen language through to the account', () => {
    const service = TestBed.inject(LanguageService);
    service.set('de');

    const req = http.expectOne({ method: 'PATCH', url: '/api/me' });
    expect(req.request.body).toEqual({ locale: 'de' });
    req.flush({ locale: 'de' });

    expect(service.lang()).toBe('de');
    expect(localStorage.getItem(LANG_KEY)).toBe('de');
  });

  it('adopts the account language on login, over the cached one', () => {
    localStorage.setItem(LANG_KEY, 'en');
    const service = TestBed.inject(LanguageService);

    service.adopt('de');

    expect(service.lang()).toBe('de');
    expect(localStorage.getItem(LANG_KEY)).toBe('de');
    http.expectNone({ method: 'PATCH', url: '/api/me' });
  });

  it('ignores an unsupported account language rather than breaking the UI', () => {
    localStorage.setItem(LANG_KEY, 'en');
    const service = TestBed.inject(LanguageService);

    service.adopt('tlh');

    expect(service.lang()).toBe('en');
  });

  it('keeps the language applied locally when the write fails', () => {
    const service = TestBed.inject(LanguageService);
    service.set('de');

    http
      .expectOne({ method: 'PATCH', url: '/api/me' })
      .flush({ title: 'Server error' }, { status: 500, statusText: 'Server Error' });

    expect(service.lang()).toBe('de');
    expect(service.saveFailed()).toBe(true);
  });
});
```

If `frontend/src/testing/transloco-testing.ts` does not exist, configure `TranslocoTestingModule` inline exactly as the tags-section spec in Task 5 does.

- [ ] **Step 3: Run the test to verify it fails**

Run: `cd /Users/lars/Documents/work/eigenes/simple-feed-reader/frontend && npx jest language.service`
Expected: FAIL — `adopt` and `saveFailed` do not exist, and no request is made.

- [ ] **Step 4: Extend `AuthService`**

In `frontend/src/app/core/auth.service.ts`, add `locale` to `CurrentUser` and a write method:

```ts
export interface CurrentUser {
  id: number;
  email: string;
  roles: string[];
  status: string;
  createdAt: string;
  locale: string;
}
```

```ts
updateLocale(locale: string): Observable<CurrentUser> {
  return this.http
    .patch<CurrentUser>(`${this.base}/api/me`, { locale })
    .pipe(tap((u) => this.user.set(u)));
}
```

- [ ] **Step 5: Rewrite `LanguageService`**

```ts
// src/app/core/language.service.ts
import { Injectable, inject, signal } from '@angular/core';
import { TranslocoService } from '@jsverse/transloco';
import { AuthService } from './auth.service';
import { Lang, LANG_KEY, asLang, detectLang } from './language';

/**
 * The active UI language. The account is the source of truth -- AccountMailer
 * picks the language of every transactional email from User.locale, and a
 * native client cannot read browser storage -- so `sfr.lang` is a per-device
 * cache that keeps the pre-login screens and the next cold start in the right
 * language, not the record.
 */
@Injectable({ providedIn: 'root' })
export class LanguageService {
  private readonly transloco = inject(TranslocoService);
  private readonly auth = inject(AuthService);

  readonly lang = signal<Lang>(this.initial());

  /** True when the language applied locally but the account write failed. */
  readonly saveFailed = signal(false);

  constructor() {
    this.apply(this.lang());
  }

  /**
   * Switch the language. Applies immediately so the UI does not wait on the
   * network, then writes through. A failed write is surfaced rather than left
   * to make the two copies disagree in silence.
   */
  set(lang: Lang): void {
    this.cache(lang);
    this.saveFailed.set(false);

    this.auth.updateLocale(lang).subscribe({
      error: () => this.saveFailed.set(true),
    });
  }

  /**
   * Take the account's language after login. An unsupported value is ignored
   * rather than applied: an old or hand-edited locale must not leave the UI
   * with no translations.
   */
  adopt(locale: string | null): void {
    const lang = asLang(locale);
    if (lang === null || lang === this.lang()) return;
    this.cache(lang);
  }

  private cache(lang: Lang): void {
    localStorage.setItem(LANG_KEY, lang);
    this.lang.set(lang);
    this.apply(lang);
  }

  private apply(lang: Lang): void {
    this.transloco.setActiveLang(lang);
    // Keep the document language in step so screen readers pronounce content in
    // the right language and the browser offers the right translation prompts.
    if (typeof document !== 'undefined') document.documentElement.lang = lang;
  }

  private initial(): Lang {
    return asLang(localStorage.getItem(LANG_KEY)) ?? detectLang(navigator.language);
  }
}
```

- [ ] **Step 6: Adopt the account language after `loadMe()`**

Find every caller of `AuthService.loadMe()` and, on success, call `LanguageService.adopt(user.locale)`:

```bash
cd /Users/lars/Documents/work/eigenes/simple-feed-reader/frontend
grep -rn "loadMe" src/
```

Inject `LanguageService` at each call site rather than inside `AuthService` — `LanguageService` already injects `AuthService`, and doing it the other way round creates a circular dependency.

- [ ] **Step 7: Surface a failed write**

In `frontend/src/app/settings/preferences-section.component.html`, below the language switcher:

```html
@if (language.saveFailed()) {
  <app-error-banner [message]="'settings.languageSaveFailed' | transloco" />
}
```

Inject `LanguageService` as `readonly language` on the component and add `ErrorBannerComponent` to its `imports`.

- [ ] **Step 8: Run the tests to verify they pass**

Run: `cd /Users/lars/Documents/work/eigenes/simple-feed-reader/frontend && npx jest language.service`
Expected: PASS — 4 tests.

- [ ] **Step 9: Prove the tests are real**

Drop the `auth.updateLocale(...)` call from `set()`: the first and fourth tests must fail. Replace `asLang(locale)` in `adopt()` with a bare cast: the "unsupported account language" test must fail. Restore both.

- [ ] **Step 10: Run the full gate and commit**

```bash
cd /Users/lars/Documents/work/eigenes/simple-feed-reader/frontend
npx prettier --write src/app/core/ src/app/settings/ public/i18n/
npm run check
```

Expected: green.

```bash
cd /Users/lars/Documents/work/eigenes/simple-feed-reader
git add frontend/
git commit -m "feat(settings): write the chosen language through to the account (#180)"
```

---

### Task 10: End-to-end coverage and full verification

**Files:**
- Modify: `frontend/e2e/settings-admin-smoke.spec.ts`
- Test: everything

**Interfaces:**
- Consumes: everything from Tasks 1–9.
- Produces: nothing. **Do not open a pull request** — the human partner decides that separately.

- [ ] **Step 1: Extend the smoke test**

Add to `frontend/e2e/settings-admin-smoke.spec.ts`, following the file's existing selector and setup conventions:

```ts
test('a tag can be reordered and renamed from settings', async ({ page }) => {
  await page.goto('/settings/tags');

  const rows = page.locator('.tag');
  await expect(rows.first()).toBeVisible();
  const firstName = await rows.first().locator('.name').innerText();

  // Reorder: drag the first row's handle below the second.
  await rows.first().locator('.drag-handle').hover();
  await page.mouse.down();
  await rows.nth(1).hover();
  await page.mouse.up();

  await expect(rows.first().locator('.name')).not.toHaveText(firstName);

  // Inline edit: the editor opens on the row, not in a dialog.
  await rows.first().getByRole('button', { name: /edit|bearbeiten/i }).click();
  await expect(rows.first().locator('.editor')).toBeVisible();
  await expect(page.locator('.app-dialog')).toHaveCount(0);
});
```

If the seeded account has fewer than two tags, create them in the test's setup rather than weakening the assertions — an e2e that passes because the fixture is empty proves nothing.

- [ ] **Step 2: Run the full verification sweep**

Record the real result of every leg, including any failure.

```bash
cd /Users/lars/Documents/work/eigenes/simple-feed-reader/frontend && npm run check
```

```bash
cd /Users/lars/Documents/work/eigenes/simple-feed-reader/backend && php bin/phpunit
```

```bash
cd /Users/lars/Documents/work/eigenes/simple-feed-reader && docker compose up -d && docker compose exec php vendor/bin/phpunit
```

**Never run `docker compose down -v`** — it deletes the MySQL volume.

```bash
cd /Users/lars/Documents/work/eigenes/simple-feed-reader/backend
composer cs && bin/console cache:warmup && composer stan
```

Per-file PHPMD on every `src` file this branch touched:

```bash
cd /Users/lars/Documents/work/eigenes/simple-feed-reader
for f in $(git diff --name-only develop...HEAD -- backend/src); do
  (cd backend && vendor/bin/phpmd "${f#backend/}" text phpmd.xml.dist >/dev/null 2>&1; echo "$f exit=$?")
done
```

Expected: every file `exit=0`.

Scan for deprecations and swallowed errors:

```bash
cd /Users/lars/Documents/work/eigenes/simple-feed-reader && tail -100 backend/var/log/dev.log
```

Playwright, with the Docker stack up:

```bash
cd /Users/lars/Documents/work/eigenes/simple-feed-reader/frontend && npm run e2e
```

This branch adds no migration, so the migrate-from-empty leg needs no new attention beyond CI's own.

- [ ] **Step 3: Check the i18n dictionaries agree**

```bash
cd /Users/lars/Documents/work/eigenes/simple-feed-reader/frontend
node -e "
const flat=(o,p='')=>Object.entries(o).flatMap(([k,v])=>typeof v==='object'&&v?flat(v,p+k+'.'):[p+k]);
const en=flat(require('./public/i18n/en.json')), de=flat(require('./public/i18n/de.json'));
console.log('en-only:', en.filter(k=>!de.includes(k)));
console.log('de-only:', de.filter(k=>!en.includes(k)));
"
```

Expected: both lists empty.

- [ ] **Step 4: Check no key was left dead**

For each key added in Tasks 3–9, confirm it is referenced in a template or a `.ts` file:

```bash
cd /Users/lars/Documents/work/eigenes/simple-feed-reader/frontend
for k in loadingVersion opmlError loadingUsers loadingCatalog loadingUser loading loadFailed retry reorder save cancel editing saveFailed languageSaveFailed; do
  printf '%s: ' "$k"; grep -rl "$k" src/ | head -1 || echo "DEAD"
done
```

Any key with no hit must be either used or removed.

- [ ] **Step 5: Commit**

```bash
cd /Users/lars/Documents/work/eigenes/simple-feed-reader
git add frontend/e2e
git commit -m "test(settings): cover tag reorder and inline edit in the smoke (#180)"
```

---

## Self-review

**Spec coverage.**

| Spec requirement | Task |
|---|---|
| §1 `SettingsCardComponent`, all surfaces convert | 1, 3, 4, 5 |
| §1 `--fs-md` → `--fs-base` in three files | 3 |
| §1 catalog entries for the card, the skeleton, `app-spinner` | 1, 2 |
| §2 `SkeletonComponent` on the four list surfaces | 2, 4, 5 |
| §2 `app-spinner` for About's non-list fetch | 3 |
| §2 `app-error-banner` adopted by OPML and Tags | 3, 5 |
| §2 the tags empty-state bug, pinned by a test | 5 |
| §3 drag-to-reorder, flat drop list, existing endpoint | 6 |
| §3 inline edit, dialog untouched, one row at a time | 7 |
| §4 `GET /api/me` gains `locale`; `PATCH /api/me`; 422 allow-list | 8 |
| §4 server-wins reconcile, failed write surfaced | 9 |
| Testing: mutation evidence for every new assertion | every task's "prove the tests are real" step |
| Testing: e2e | 10 |

No gaps.

**Type consistency.** `SettingsCardComponent` takes `heading` / `description` in Tasks 1, 3, 4 and 5. `SkeletonComponent` takes `label` / `rows` in Tasks 2, 4 and 5. `onTagDrop(event: CdkDragDrop<TagDto[]>)` is defined in Task 6 and used only there. `editingId` / `draftName` / `draftColor` / `draftIcon` / `startEdit` / `cancelEdit` / `saveEdit` are defined and used within Task 7. `CurrentUser.locale` is produced by Task 8's endpoint and consumed by Task 9's interface change. `LanguageService.adopt(locale: string | null)` and `saveFailed()` are defined in Task 9 and used in Task 9's Steps 6 and 7.

**Two assumptions checked against the code while writing this plan, and corrected inline:**

- `User::getLocale()` and `User::setLocale()` already exist (`backend/src/Entity/User.php:204`, `:209`). Task 8 needs no entity change.
- `app-field` takes `[label]` and **projects** its `<input>` — it has no `value` input. Task 7's editor template was rewritten to match `tag-form-dialog.component.html`, including the icon picker's `''`-means-none convention and the four `dialog.tagForm.*` keys it reuses rather than duplicating.

**One key not to add.** Task 7 uses the dialog's existing `dialog.tagForm.name`, `dialog.tagForm.colour`, `dialog.tagForm.icon` and `dialog.tagForm.none`. Task 10, Step 4 checks only the keys this plan introduces.
