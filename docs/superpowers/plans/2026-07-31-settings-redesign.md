# Settings Area Redesign (#180) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Replace the flat `/settings` scroll with a nav-rail (desktop) / hub-and-spoke (mobile) shell of lazy per-section routes, and move the fully reworked admin pages inside it.

**Architecture:** A config array (`settings-sections.ts`) drives both nav renderings; the shell, hub and nav are three small components. Sections become lazy child routes in a new `settings.routes.ts`. The admin catalog swaps its always-editable grid for read-only rows plus CDK edit dialogs (the tag-form pattern); admin users gets design-language rows and two-step destructive actions.

**Tech Stack:** Angular 20 standalone components + signals, CDK Dialog + BreakpointObserver (via the existing `LayoutService`), Transloco, Jest, Playwright. Frontend only — no PHP.

**Branch:** `feature/180-settings-redesign` (already created; spec committed).

**Spec:** `docs/superpowers/specs/2026-07-31-settings-redesign-design.md`

**Per-task hygiene (applies to every task):**
- All commands run from `frontend/`.
- Run `npx prettier --write <changed files>` before committing (Prettier is part of the CI gate and enforces 100-col).
- SCSS: no hex, no raw `px` for spacing/font-size/radius (tokens only), breakpoints via `@use '../theme/breakpoints' as bp;` — the documented escape hatch (`stylelint-disable-next-line` + reason) only for tuned component dimensions.
- Component styles always in a sibling `.scss` referenced by `styleUrl` — never inline `styles:`.

---

## File map

**Create:**

| File | Responsibility |
|---|---|
| `src/app/settings/settings-sections.ts` (+spec) | The one config array describing every section |
| `src/app/settings/settings-nav.component.{ts,html,scss}` (+spec) | Renders the config as rail or hub |
| `src/app/settings/settings-hub.component.{ts,scss}` (+spec) | Mobile landing page; desktop forward to first section |
| `src/app/settings/settings-shell.component.{ts,html,scss}` (+spec) | Top bar + rail + `<router-outlet>`; back-target logic; wide-column flag |
| `src/app/settings/settings.routes.ts` | Lazy child routes for all sections |
| `src/app/admin/category-form-dialog.component.{ts,html,scss}` (+spec) | Create/edit a catalog category |
| `src/app/admin/feed-form-dialog.component.{ts,html,scss}` (+spec) | Create/edit a catalog feed |

**Modify:**

| File | Change |
|---|---|
| `src/app/app.routes.ts` | `settings` → `loadChildren`; `/admin/*` → redirects |
| `src/app/app.routes.spec.ts` | Assert the redirects and settings path |
| `src/app/settings/tags-section.component.ts` (+spec) | Takes over the store loading the deleted `SettingsComponent` did |
| `src/app/settings/account-section.component.{ts,html,scss}` | Remove the buried admin links |
| `src/app/reader/reader-shell.component.html` | Empty-catalog link → `/settings/admin/catalog` |
| `src/app/admin/admin-users.component.{ts,html,scss}` (+spec) | Rework: h2 heading, design-language rows, confirm dialogs |
| `src/app/admin/admin-catalog.component.{ts,html,scss}` (+spec) | Rework: read-only rows, dialogs, tools card |
| `src/app/admin/admin.models.ts` | Export `DEFAULT_CATEGORY_COLOR` |
| `public/i18n/en.json`, `public/i18n/de.json` | New nav/dialog/confirm keys |
| `e2e/settings-admin-smoke.spec.ts` | Cover the new navigation, redirects and catalog dialogs |

**Delete:**

| File | Why |
|---|---|
| `src/app/settings/settings.component.{ts,html,scss,spec.ts}` | Replaced by shell + routes |

---

### Task 1: Section config

**Files:**
- Create: `src/app/settings/settings-sections.ts`
- Create: `src/app/settings/settings-sections.spec.ts`

- [ ] **Step 1: Write the failing test**

```ts
// src/app/settings/settings-sections.spec.ts
import { SETTINGS_SECTIONS } from './settings-sections';

describe('SETTINGS_SECTIONS', () => {
  it('has unique paths', () => {
    const paths = SETTINGS_SECTIONS.map((s) => s.path);
    expect(new Set(paths).size).toBe(paths.length);
  });

  it('keeps admin sections under the admin/ path prefix, and only them', () => {
    for (const s of SETTINGS_SECTIONS) {
      expect(s.path.startsWith('admin/')).toBe(s.group === 'admin');
    }
  });

  it('gives every section an icon and a label key', () => {
    for (const s of SETTINGS_SECTIONS) {
      expect(s.icon).not.toBe('');
      expect(s.labelKey).toMatch(/^\w+\./);
    }
  });
});
```

- [ ] **Step 2: Run it to verify it fails**

Run: `npx jest src/app/settings/settings-sections.spec.ts`
Expected: FAIL — `Cannot find module './settings-sections'`

- [ ] **Step 3: Write the config**

```ts
// src/app/settings/settings-sections.ts
export type SettingsGroup = 'general' | 'admin';

export interface SettingsSection {
  /** Child route path under /settings — doubles as the routerLink target. */
  readonly path: string;
  /** Material Symbol name for <app-icon>. */
  readonly icon: string;
  readonly labelKey: string;
  readonly group: SettingsGroup;
  /** Opts the section out of the default content column width. */
  readonly wide?: boolean;
}

/** The one list both nav renderings (rail and hub) draw from. Adding a section
 *  means one entry here plus one route in settings.routes.ts — the shell stays
 *  untouched (#180's extensibility criterion). */
export const SETTINGS_SECTIONS: readonly SettingsSection[] = [
  { path: 'tags', icon: 'sell', labelKey: 'settings.tags.title', group: 'general' },
  { path: 'import', icon: 'import_export', labelKey: 'settings.opml.title', group: 'general' },
  { path: 'preferences', icon: 'tune', labelKey: 'settings.preferences', group: 'general' },
  { path: 'account', icon: 'person', labelKey: 'settings.account.title', group: 'general' },
  { path: 'about', icon: 'info', labelKey: 'settings.about.title', group: 'general' },
  { path: 'admin/users', icon: 'shield_person', labelKey: 'admin.title', group: 'admin' },
  {
    path: 'admin/catalog',
    icon: 'category',
    labelKey: 'admin.catalog',
    group: 'admin',
    wide: true,
  },
];
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `npx jest src/app/settings/settings-sections.spec.ts`
Expected: PASS (3 tests)

- [ ] **Step 5: Commit**

```bash
git add src/app/settings/settings-sections.ts src/app/settings/settings-sections.spec.ts
git commit -m "feat(settings): section config array driving the new nav (#180)"
```

---

### Task 2: SettingsNavComponent (rail + hub renderings)

**Files:**
- Create: `src/app/settings/settings-nav.component.ts`
- Create: `src/app/settings/settings-nav.component.html`
- Create: `src/app/settings/settings-nav.component.scss`
- Create: `src/app/settings/settings-nav.component.spec.ts`
- Modify: `public/i18n/en.json`, `public/i18n/de.json` (nav group key)

- [ ] **Step 1: Add the i18n key**

In `public/i18n/en.json`, inside the existing `"settings"` object, add:

```json
"nav": { "admin": "Admin" }
```

In `public/i18n/de.json`, inside `"settings"`:

```json
"nav": { "admin": "Administration" }
```

(Keep JSON key order tidy — place `"nav"` right after `"backReader"` in both files.)

- [ ] **Step 2: Write the failing test**

```ts
// src/app/settings/settings-nav.component.spec.ts
import { TestBed } from '@angular/core/testing';
import { provideRouter } from '@angular/router';
import { provideTranslocoTesting } from '../../testing/transloco-testing';
import { AuthService } from '../core/auth.service';
import { SettingsNavComponent } from './settings-nav.component';
import { SETTINGS_SECTIONS } from './settings-sections';

describe('SettingsNavComponent', () => {
  function mount(roles: string[], variant: 'rail' | 'hub' = 'rail') {
    TestBed.configureTestingModule({
      imports: [provideTranslocoTesting()],
      providers: [
        provideRouter([]),
        { provide: AuthService, useValue: { user: () => ({ roles }), isAdmin: () => roles.includes('ROLE_ADMIN') } },
      ],
    });
    const f = TestBed.createComponent(SettingsNavComponent);
    f.componentRef.setInput('variant', variant);
    f.detectChanges();
    return f;
  }

  it('renders a link per general section for a plain user, and no admin group', () => {
    const f = mount(['ROLE_USER']);
    const links = f.nativeElement.querySelectorAll('a');
    const generalCount = SETTINGS_SECTIONS.filter((s) => s.group === 'general').length;
    expect(links.length).toBe(generalCount);
  });

  it('renders the admin group for an admin', () => {
    const f = mount(['ROLE_USER', 'ROLE_ADMIN']);
    const links = [...f.nativeElement.querySelectorAll('a')] as HTMLAnchorElement[];
    expect(links.length).toBe(SETTINGS_SECTIONS.length);
    expect(links.some((a) => a.getAttribute('href') === '/settings/admin/catalog')).toBe(true);
  });

  it('carries the variant as a host-level class', () => {
    const f = mount(['ROLE_USER'], 'hub');
    expect(f.nativeElement.querySelector('nav').classList).toContain('hub');
  });
});
```

- [ ] **Step 3: Run it to verify it fails**

Run: `npx jest src/app/settings/settings-nav.component.spec.ts`
Expected: FAIL — `Cannot find module './settings-nav.component'`

- [ ] **Step 4: Implement the component**

```ts
// src/app/settings/settings-nav.component.ts
import { ChangeDetectionStrategy, Component, computed, inject, input } from '@angular/core';
import { RouterLink, RouterLinkActive } from '@angular/router';
import { TranslocoPipe } from '@jsverse/transloco';
import { AuthService } from '../core/auth.service';
import { IconComponent } from '../shared/icon/icon.component';
import { SETTINGS_SECTIONS, SettingsSection } from './settings-sections';

interface NavGroup {
  /** null for the unlabelled general group. */
  readonly labelKey: string | null;
  readonly sections: readonly SettingsSection[];
}

/** The settings navigation, rendered from SETTINGS_SECTIONS in one of two
 *  framings: `rail` (persistent desktop column) or `hub` (the full-page list
 *  that IS the mobile /settings route). Same data, same markup — the variant
 *  class picks the styling. */
@Component({
  selector: 'app-settings-nav',
  imports: [RouterLink, RouterLinkActive, TranslocoPipe, IconComponent],
  templateUrl: './settings-nav.component.html',
  styleUrl: './settings-nav.component.scss',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class SettingsNavComponent {
  readonly variant = input.required<'rail' | 'hub'>();
  private readonly auth = inject(AuthService);

  readonly groups = computed<readonly NavGroup[]>(() => {
    const groups: NavGroup[] = [
      { labelKey: null, sections: SETTINGS_SECTIONS.filter((s) => s.group === 'general') },
    ];
    if (this.auth.isAdmin()) {
      groups.push({
        labelKey: 'settings.nav.admin',
        sections: SETTINGS_SECTIONS.filter((s) => s.group === 'admin'),
      });
    }
    return groups;
  });
}
```

Note: `auth.isAdmin()` reads the `user` signal internally, so the `computed` re-evaluates when the user loads — that is what makes the admin group appear after the shell's deferred `loadMe()` (Task 4) resolves.

```html
<!-- src/app/settings/settings-nav.component.html -->
<nav [class]="variant()" [attr.aria-label]="'settings.title' | transloco">
  @for (group of groups(); track group.labelKey) {
    @if (group.labelKey; as labelKey) {
      <p class="group">{{ labelKey | transloco }}</p>
    }
    <ul>
      @for (s of group.sections; track s.path) {
        <li>
          <a [routerLink]="'/settings/' + s.path" routerLinkActive="active">
            <app-icon [name]="s.icon" size="sm" />
            <span class="label">{{ s.labelKey | transloco }}</span>
            <app-icon class="chev" name="chevron_right" size="sm" />
          </a>
        </li>
      }
    </ul>
  }
</nav>
```

```scss
// src/app/settings/settings-nav.component.scss
ul {
  list-style: none;
  margin: 0;
  padding: 0;
}

a {
  display: flex;
  align-items: center;
  gap: var(--space-2);
  color: var(--text-primary);
  text-decoration: none;
  border-radius: var(--radius);
}

.label {
  flex: 1;
  min-width: 0;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.group {
  margin: var(--space-4) 0 var(--space-1);
  padding: 0 var(--row-pad-x);
  font-size: var(--fs-xs);
  text-transform: uppercase;
  letter-spacing: 0.05em;
  color: var(--text-muted);
}

/* rail: compact rows, active section highlighted, no chevrons */
.rail a {
  padding: var(--row-pad-y) var(--row-pad-x);
  font-size: var(--fs-sm);
  color: var(--text-secondary);
}

.rail a:hover {
  background: var(--surface-1);
}

.rail a.active {
  background: var(--surface-1);
  color: var(--accent);
}

.rail .chev {
  display: none;
}

/* hub: comfortable full-width rows with chevrons, iOS-settings style */
.hub a {
  padding: var(--row-pad-comfy-y) var(--row-pad-comfy-x);
  border-bottom: 1px solid var(--border);
  border-radius: 0;
  min-height: var(--tap-target);
}

.hub .chev {
  color: var(--text-muted);
}

.hub .group {
  padding: 0 var(--row-pad-comfy-x);
}
```

- [ ] **Step 5: Run the test to verify it passes**

Run: `npx jest src/app/settings/settings-nav.component.spec.ts`
Expected: PASS (3 tests)

- [ ] **Step 6: Commit**

```bash
git add src/app/settings/settings-nav.component.* public/i18n/en.json public/i18n/de.json
git commit -m "feat(settings): nav component rendering the section config as rail or hub (#180)"
```

---

### Task 3: SettingsHubComponent

**Files:**
- Create: `src/app/settings/settings-hub.component.ts`
- Create: `src/app/settings/settings-hub.component.scss`
- Create: `src/app/settings/settings-hub.component.spec.ts`

- [ ] **Step 1: Write the failing test**

```ts
// src/app/settings/settings-hub.component.spec.ts
import { Component, signal } from '@angular/core';
import { TestBed } from '@angular/core/testing';
import { Router, provideRouter } from '@angular/router';
import { provideTranslocoTesting } from '../../testing/transloco-testing';
import { AuthService } from '../core/auth.service';
import { LayoutService } from '../reader/layout.service';
import { SettingsHubComponent } from './settings-hub.component';

@Component({ template: '' })
class BlankComponent {}

describe('SettingsHubComponent', () => {
  const isWide = signal(false);

  function mount() {
    TestBed.configureTestingModule({
      imports: [provideTranslocoTesting()],
      providers: [
        provideRouter([{ path: 'settings', children: [{ path: '**', component: BlankComponent }] }]),
        { provide: LayoutService, useValue: { isWide } },
        { provide: AuthService, useValue: { user: () => null, isAdmin: () => false } },
      ],
    });
    const f = TestBed.createComponent(SettingsHubComponent);
    f.detectChanges();
    return f;
  }

  it('renders the hub nav on a narrow viewport and stays put', async () => {
    isWide.set(false);
    const f = mount();
    await f.whenStable();
    expect(f.nativeElement.querySelector('app-settings-nav')).not.toBeNull();
    expect(TestBed.inject(Router).url).toBe('/');
  });

  it('forwards to the first section on a wide viewport', async () => {
    isWide.set(true);
    const f = mount();
    await f.whenStable();
    expect(TestBed.inject(Router).url).toBe('/settings/tags');
  });

  it('forwards when the viewport grows past the breakpoint while open', async () => {
    isWide.set(false);
    const f = mount();
    await f.whenStable();
    isWide.set(true);
    f.detectChanges();
    await f.whenStable();
    expect(TestBed.inject(Router).url).toBe('/settings/tags');
  });
});
```

- [ ] **Step 2: Run it to verify it fails**

Run: `npx jest src/app/settings/settings-hub.component.spec.ts`
Expected: FAIL — `Cannot find module './settings-hub.component'`

- [ ] **Step 3: Implement**

```ts
// src/app/settings/settings-hub.component.ts
import { ChangeDetectionStrategy, Component, effect, inject } from '@angular/core';
import { Router } from '@angular/router';
import { LayoutService } from '../reader/layout.service';
import { SettingsNavComponent } from './settings-nav.component';

/** The mobile landing page of the settings area — the "hub" of hub-and-spoke.
 *  A desktop viewport already shows the same navigation in the shell's rail,
 *  so this page forwards to the first section instead; replaceUrl keeps the
 *  redirect out of the back stack. The effect also catches the viewport
 *  crossing the breakpoint while the hub is open. */
@Component({
  selector: 'app-settings-hub',
  imports: [SettingsNavComponent],
  template: '<app-settings-nav variant="hub" />',
  styleUrl: './settings-hub.component.scss',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class SettingsHubComponent {
  private readonly layout = inject(LayoutService);
  private readonly router = inject(Router);

  constructor() {
    effect(() => {
      if (this.layout.isWide()) {
        void this.router.navigate(['/settings/tags'], { replaceUrl: true });
      }
    });
  }
}
```

```scss
// src/app/settings/settings-hub.component.scss
:host {
  display: block;
}
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `npx jest src/app/settings/settings-hub.component.spec.ts`
Expected: PASS (3 tests)

- [ ] **Step 5: Commit**

```bash
git add src/app/settings/settings-hub.component.*
git commit -m "feat(settings): hub landing page with desktop forward to first section (#180)"
```

---

### Task 4: SettingsShellComponent

**Files:**
- Create: `src/app/settings/settings-shell.component.ts`
- Create: `src/app/settings/settings-shell.component.html`
- Create: `src/app/settings/settings-shell.component.scss`
- Create: `src/app/settings/settings-shell.component.spec.ts`

- [ ] **Step 1: Write the failing test**

```ts
// src/app/settings/settings-shell.component.spec.ts
import { Component, signal } from '@angular/core';
import { TestBed } from '@angular/core/testing';
import { Router, provideRouter } from '@angular/router';
import { of } from 'rxjs';
import { provideTranslocoTesting } from '../../testing/transloco-testing';
import { AuthService } from '../core/auth.service';
import { LayoutService } from '../reader/layout.service';
import { SettingsShellComponent } from './settings-shell.component';

@Component({ template: '' })
class BlankComponent {}

describe('SettingsShellComponent', () => {
  const isWide = signal(true);
  const loadMe = jest.fn(() => of({}));
  let currentUser: object | null = null;

  function mount() {
    TestBed.configureTestingModule({
      imports: [provideTranslocoTesting()],
      providers: [
        provideRouter([
          { path: 'settings', children: [{ path: '**', component: BlankComponent }] },
        ]),
        { provide: LayoutService, useValue: { isWide } },
        { provide: AuthService, useValue: { user: () => currentUser, loadMe, isAdmin: () => false } },
      ],
    }).overrideComponent(SettingsShellComponent, {
      set: { imports: [], template: '<h1>Settings</h1>', schemas: [] },
    });
    const f = TestBed.createComponent(SettingsShellComponent);
    f.detectChanges();
    return f;
  }

  beforeEach(() => {
    loadMe.mockClear();
    currentUser = null;
    isWide.set(true);
  });

  async function goTo(url: string) {
    await TestBed.inject(Router).navigateByUrl(url);
  }

  it('fetches the current user when none is loaded (deep link)', () => {
    mount();
    expect(loadMe).toHaveBeenCalled();
  });

  it('does not re-fetch an already-loaded user', () => {
    currentUser = { id: 1 };
    mount();
    expect(loadMe).not.toHaveBeenCalled();
  });

  it('leads back to the reader from a section on desktop', async () => {
    const f = mount();
    await goTo('/settings/tags');
    expect(f.componentInstance.backTarget()).toBe('/');
  });

  it('leads back to the hub from a section on mobile', async () => {
    isWide.set(false);
    const f = mount();
    await goTo('/settings/tags');
    expect(f.componentInstance.backTarget()).toBe('/settings');
    expect(f.componentInstance.backLabelKey()).toBe('settings.title');
  });

  it('leads back to the reader from the hub on mobile', async () => {
    isWide.set(false);
    const f = mount();
    await goTo('/settings');
    expect(f.componentInstance.backTarget()).toBe('/');
  });

  it('flags the wide sections', async () => {
    const f = mount();
    await goTo('/settings/admin/catalog');
    expect(f.componentInstance.wideSection()).toBe(true);
    await goTo('/settings/tags');
    expect(f.componentInstance.wideSection()).toBe(false);
  });
});
```

- [ ] **Step 2: Run it to verify it fails**

Run: `npx jest src/app/settings/settings-shell.component.spec.ts`
Expected: FAIL — `Cannot find module './settings-shell.component'`

- [ ] **Step 3: Implement**

```ts
// src/app/settings/settings-shell.component.ts
import { ChangeDetectionStrategy, Component, OnInit, computed, inject } from '@angular/core';
import { toSignal } from '@angular/core/rxjs-interop';
import { NavigationEnd, Router, RouterLink, RouterOutlet } from '@angular/router';
import { TranslocoPipe } from '@jsverse/transloco';
import { filter, map } from 'rxjs';
import { AuthService } from '../core/auth.service';
import { LayoutService } from '../reader/layout.service';
import { IconComponent } from '../shared/icon/icon.component';
import { SettingsNavComponent } from './settings-nav.component';
import { SETTINGS_SECTIONS } from './settings-sections';

/** The frame around every settings section: top bar, the desktop nav rail and
 *  the routed content column. Owns the two pieces of cross-section logic —
 *  where "back" leads, and which sections escape the default column width. */
@Component({
  selector: 'app-settings-shell',
  imports: [RouterLink, RouterOutlet, TranslocoPipe, IconComponent, SettingsNavComponent],
  templateUrl: './settings-shell.component.html',
  styleUrl: './settings-shell.component.scss',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class SettingsShellComponent implements OnInit {
  private readonly auth = inject(AuthService);
  private readonly layout = inject(LayoutService);
  private readonly router = inject(Router);

  private readonly url = toSignal(
    this.router.events.pipe(
      filter((event): event is NavigationEnd => event instanceof NavigationEnd),
      map((event) => event.urlAfterRedirects),
    ),
    { initialValue: this.router.url },
  );

  private readonly section = computed(
    () => SETTINGS_SECTIONS.find((s) => this.url().startsWith(`/settings/${s.path}`)) ?? null,
  );

  readonly wideSection = computed(() => this.section()?.wide === true);

  /** On a phone a section page steps back to the hub; everywhere else the bar
   *  leads back to the reader. */
  readonly backTarget = computed(() =>
    !this.layout.isWide() && this.section() !== null ? '/settings' : '/',
  );

  readonly backLabelKey = computed(() =>
    this.backTarget() === '/settings' ? 'settings.title' : 'settings.backReader',
  );

  ngOnInit(): void {
    // A deep link lands here without a loaded user, and the nav needs it to
    // decide on the admin group. authGuard has already ensured a token exists.
    if (this.auth.user() === null) {
      this.auth.loadMe().subscribe({ error: () => undefined });
    }
  }
}
```

```html
<!-- src/app/settings/settings-shell.component.html -->
<header class="bar">
  <a class="back" [routerLink]="backTarget()">
    <app-icon name="arrow_back" size="sm" /> {{ backLabelKey() | transloco }}
  </a>
  <h1>{{ 'settings.title' | transloco }}</h1>
</header>
<div class="layout" [class.wide]="wideSection()">
  <app-settings-nav class="rail" variant="rail" />
  <main class="content">
    <router-outlet />
  </main>
</div>
```

```scss
// src/app/settings/settings-shell.component.scss
@use '../theme/breakpoints' as bp;

.bar {
  height: var(--bar-h);
  display: flex;
  align-items: center;
  gap: var(--space-4);
  padding: 0 var(--space-4);
  border-bottom: 1px solid var(--border);
  background: var(--surface-1);
}

.back {
  display: inline-flex;
  align-items: center;
  gap: var(--space-1);
  color: var(--text-secondary);
  text-decoration: none;
}

h1 {
  font-size: var(--fs-lg);
  margin: 0;
}

.layout {
  /* stylelint-disable-next-line declaration-property-unit-allowed-list --
     tuned page measure (rail + gap + the 820px content column), not spacing. */
  max-width: 1080px;
  margin: 0 auto;
  padding: var(--space-5) var(--space-4);
  display: flex;
  align-items: flex-start;
  gap: var(--space-6);
}

.layout.wide {
  /* stylelint-disable-next-line declaration-property-unit-allowed-list --
     tuned page measure for the admin catalog's dense rows, not spacing. */
  max-width: 1440px;
}

.rail {
  /* stylelint-disable-next-line declaration-property-unit-allowed-list --
     tuned rail measure, not a spacing value. */
  width: 220px;
  flex-shrink: 0;
  position: sticky;
  top: var(--space-5);
  align-self: flex-start;
  max-height: 100dvh;
  overflow-y: auto;
  overscroll-behavior: contain;
}

.content {
  flex: 1;
  min-width: 0;
  /* stylelint-disable-next-line declaration-property-unit-allowed-list --
     the pre-#180 settings page measure, kept for the regular sections. */
  max-width: 820px;
  display: flex;
  flex-direction: column;
  gap: var(--space-6);
}

.layout.wide .content {
  max-width: none;
}

@media (width <= bp.$bp-lg) {
  .rail {
    display: none;
  }

  .layout {
    padding: var(--space-4) var(--space-3);
  }

  .content {
    max-width: none;
  }
}
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `npx jest src/app/settings/settings-shell.component.spec.ts`
Expected: PASS (6 tests)

- [ ] **Step 5: Commit**

```bash
git add src/app/settings/settings-shell.component.*
git commit -m "feat(settings): shell with top bar, desktop rail and routed content (#180)"
```

---

### Task 5: Route rewiring, old-page deletion, link cleanup

**Files:**
- Create: `src/app/settings/settings.routes.ts`
- Modify: `src/app/app.routes.ts`
- Modify: `src/app/app.routes.spec.ts`
- Modify: `src/app/settings/tags-section.component.ts` and `.spec.ts`
- Modify: `src/app/settings/account-section.component.ts` / `.html` / `.scss`
- Modify: `src/app/reader/reader-shell.component.html:28`
- Delete: `src/app/settings/settings.component.{ts,html,scss,spec.ts}`

- [ ] **Step 1: Extend the failing routes spec**

Replace `src/app/app.routes.spec.ts` with:

```ts
// src/app/app.routes.spec.ts
import { routes } from './app.routes';

describe('routes', () => {
  const paths = routes.map((r) => r.path);

  it('exposes the exact paths the backend links to', () => {
    for (const p of [
      'login',
      'register',
      'verify-email',
      'reset-password-request',
      'reset-password',
      'auth/callback',
      '',
    ]) {
      expect(paths).toContain(p);
    }
  });

  it('lazy-loads the settings area as child routes', () => {
    const settings = routes.find((r) => r.path === 'settings');
    expect(settings?.loadChildren).toBeDefined();
  });

  it('redirects the pre-#180 admin urls into the settings area', () => {
    expect(routes.find((r) => r.path === 'admin/users')?.redirectTo).toBe('settings/admin/users');
    expect(routes.find((r) => r.path === 'admin/catalog')?.redirectTo).toBe(
      'settings/admin/catalog',
    );
  });
});
```

Run: `npx jest src/app/app.routes.spec.ts` — expected: FAIL (2 new tests).

- [ ] **Step 2: Create the settings child routes**

```ts
// src/app/settings/settings.routes.ts
import { Routes } from '@angular/router';
import { adminGuard } from '../core/admin.guard';

/** Children of /settings. Every section is lazy; the admin pair repeats the
 *  adminGuard because the parent authGuard only proves a session, not a role. */
export const SETTINGS_ROUTES: Routes = [
  {
    path: '',
    loadComponent: () =>
      import('./settings-shell.component').then((m) => m.SettingsShellComponent),
    children: [
      {
        path: '',
        loadComponent: () =>
          import('./settings-hub.component').then((m) => m.SettingsHubComponent),
      },
      {
        path: 'tags',
        loadComponent: () =>
          import('./tags-section.component').then((m) => m.TagsSectionComponent),
      },
      {
        path: 'import',
        loadComponent: () =>
          import('./opml-section.component').then((m) => m.OpmlSectionComponent),
      },
      {
        path: 'preferences',
        loadComponent: () =>
          import('./preferences-section.component').then((m) => m.PreferencesSectionComponent),
      },
      {
        path: 'account',
        loadComponent: () =>
          import('./account-section.component').then((m) => m.AccountSectionComponent),
      },
      {
        path: 'about',
        loadComponent: () =>
          import('./about-section.component').then((m) => m.AboutSectionComponent),
      },
      {
        path: 'admin/users',
        canActivate: [adminGuard],
        loadComponent: () =>
          import('../admin/admin-users.component').then((m) => m.AdminUsersComponent),
      },
      {
        path: 'admin/catalog',
        canActivate: [adminGuard],
        loadComponent: () =>
          import('../admin/admin-catalog.component').then((m) => m.AdminCatalogComponent),
      },
    ],
  },
];
```

- [ ] **Step 3: Rewire app.routes.ts**

In `src/app/app.routes.ts`, replace the `settings`, `admin/users` and `admin/catalog` entries with:

```ts
  {
    path: 'settings',
    canActivate: [authGuard],
    loadChildren: () => import('./settings/settings.routes').then((m) => m.SETTINGS_ROUTES),
  },
  { path: 'admin/users', redirectTo: 'settings/admin/users' },
  { path: 'admin/catalog', redirectTo: 'settings/admin/catalog' },
```

Remove the now-unused `adminGuard` import from `app.routes.ts` (it moved into `settings.routes.ts`).

Run: `npx jest src/app/app.routes.spec.ts` — expected: PASS.

- [ ] **Step 4: Move the store loading into the tags section (test first)**

Replace `src/app/settings/tags-section.component.spec.ts`'s mount/assertions so it also covers init loading. Add to the existing spec (keeping its current tests):

```ts
  it('loads tags and subscriptions on init', () => {
    // Arrange mocks so load() is observable — mirror the store mocks the spec
    // already uses and assert both loads ran after detectChanges().
    expect(tagLoad).toHaveBeenCalled();
    expect(subLoad).toHaveBeenCalled();
  });
```

(Adapt to the spec's existing mock names; if the current spec provides real stores, switch those two providers to `{ load: jest.fn(), … }` mocks exactly as `settings.component.spec.ts` did — that file is deleted in Step 6 and this test inherits its job.)

Run: `npx jest src/app/settings/tags-section.component.spec.ts` — expected: FAIL (no init loading yet).

Then implement in `src/app/settings/tags-section.component.ts`:

```ts
export class TagsSectionComponent implements OnInit {
  readonly tagsStore = inject(TagsStore);
  private readonly subs = inject(SubscriptionsStore);
  readonly manage = inject(ManageActions);

  // usage computed stays unchanged …

  ngOnInit(): void {
    // The deleted SettingsComponent used to preload these for all sections;
    // with per-route sections, the one section that needs them loads them.
    this.tagsStore.load();
    this.subs.load();
  }
}
```

(Add `OnInit` to the `@angular/core` import.)

Run: `npx jest src/app/settings/tags-section.component.spec.ts` — expected: PASS.

- [ ] **Step 5: Strip the admin links from the account section**

In `src/app/settings/account-section.component.html`, delete the entire `@if (auth.isAdmin()) { … }` block (the two `.admin` anchors). In `account-section.component.ts`, remove the now-unused `RouterLink` and `IconComponent` imports (keep `UserAvatarComponent`, `ButtonComponent`, `TranslocoPipe`). Check `account-section.component.scss` for a now-dead `.admin` rule and delete it. `auth` stays public (the template still reads `auth.user()`), but if `isAdmin` was its only other use, nothing else changes.

- [ ] **Step 6: Delete the old page, fix the reader link**

```bash
git rm src/app/settings/settings.component.ts src/app/settings/settings.component.html \
  src/app/settings/settings.component.scss src/app/settings/settings.component.spec.ts
```

In `src/app/reader/reader-shell.component.html` line 28, change
`routerLink="/admin/catalog"` to `routerLink="/settings/admin/catalog"`.

- [ ] **Step 7: Run the full unit suite**

Run: `npx jest`
Expected: PASS — no spec may still import `settings.component`. If `reader-shell.component.spec.ts` asserts the old admin link URL, update it to the new one.

- [ ] **Step 8: Commit**

```bash
git add -A src/app/settings src/app/app.routes.ts src/app/app.routes.spec.ts \
  src/app/reader/reader-shell.component.html
git commit -m "feat(settings): per-section lazy routes; admin urls move under /settings (#180)"
```

---

### Task 6: Admin users rework

**Files:**
- Modify: `src/app/admin/admin-users.component.ts`
- Modify: `src/app/admin/admin-users.component.html`
- Modify: `src/app/admin/admin-users.component.scss`
- Modify: `src/app/admin/admin-users.component.spec.ts`
- Modify: `public/i18n/en.json`, `public/i18n/de.json`

- [ ] **Step 1: Add the confirm i18n keys**

`public/i18n/en.json`, inside `"admin"`:

```json
"confirm": {
  "rejectTitle": "Reject user",
  "rejectMessage": "Reject {{email}}? They will not be able to sign in.",
  "suspendTitle": "Suspend user",
  "suspendMessage": "Suspend {{email}}? Their access is blocked until re-approved."
}
```

`public/i18n/de.json`, inside `"admin"`:

```json
"confirm": {
  "rejectTitle": "Benutzer ablehnen",
  "rejectMessage": "{{email}} ablehnen? Eine Anmeldung ist dann nicht möglich.",
  "suspendTitle": "Benutzer sperren",
  "suspendMessage": "{{email}} sperren? Der Zugang ist bis zur erneuten Freigabe blockiert."
}
```

- [ ] **Step 2: Write the failing tests**

Add to `src/app/admin/admin-users.component.spec.ts` (keep the existing tests; extend the TestBed providers in `mount()` with a Dialog stub):

```ts
import { Dialog } from '@angular/cdk/dialog';
import { Subject } from 'rxjs';

// inside describe(), alongside the existing setup:
const dialogClosed = new Subject<boolean | undefined>();
const dialogOpen = jest.fn(() => ({ closed: dialogClosed }));
// add to providers in mount():
//   { provide: Dialog, useValue: { open: dialogOpen } },

it('suspends only after the confirm dialog is confirmed', () => {
  const f = mount();
  ctrl.expectOne('https://api.test/api/admin/users').flush({
    users: [user(1, { status: 'active' })],
  });
  f.detectChanges();

  f.componentInstance.confirmThenAct(user(1, { status: 'active' }), 'suspend');
  expect(dialogOpen).toHaveBeenCalled();
  ctrl.expectNone('https://api.test/api/admin/users/1/suspend');

  dialogClosed.next(true);
  ctrl.expectOne('https://api.test/api/admin/users/1/suspend').flush({});
  ctrl.expectOne('https://api.test/api/admin/users').flush({ users: [] });
});

it('does nothing when the confirm dialog is cancelled', () => {
  const f = mount();
  ctrl.expectOne('https://api.test/api/admin/users').flush({
    users: [user(1, { status: 'active' })],
  });
  f.detectChanges();

  f.componentInstance.confirmThenAct(user(1, { status: 'active' }), 'suspend');
  dialogClosed.next(false);
  ctrl.expectNone('https://api.test/api/admin/users/1/suspend');
});
```

Also reset `dialogOpen` in the existing `beforeEach`, and re-create `dialogClosed` per test (`beforeEach(() => { dialogClosed = new Subject(); … })` — declare it with `let`). The Dialog stub must return a fresh `{ closed: dialogClosed }` each call.

Run: `npx jest src/app/admin/admin-users.component.spec.ts`
Expected: FAIL — `confirmThenAct` does not exist.

- [ ] **Step 3: Rework the component**

`src/app/admin/admin-users.component.ts` — remove the `RouterLink` import, add the dialog wiring:

```ts
// new imports
import { Dialog } from '@angular/cdk/dialog';
import { TranslocoPipe, TranslocoService } from '@jsverse/transloco';
import { ButtonComponent } from '../shared/button/button.component';
import { ConfirmData, ConfirmDialogComponent } from '../reader/manage/confirm-dialog.component';

@Component({
  selector: 'app-admin-users',
  imports: [ButtonComponent, IconComponent, SpinnerComponent, TranslocoPipe],
  templateUrl: './admin-users.component.html',
  styleUrl: './admin-users.component.scss',
})
export class AdminUsersComponent implements OnInit {
  private readonly api = inject(AdminApi);
  private readonly auth = inject(AuthService);
  private readonly dialog = inject(Dialog);
  private readonly i18n = inject(TranslocoService);

  // filters, users, loading, error, actionError, filter, selfId,
  // ngOnInit, setFilter, load, act, isSelf, canApprove, canReject,
  // canSuspend — all unchanged.

  /** Rejecting or suspending cuts off a person's access — that is a
   *  destructive action and gets the two-step treatment: an initiating
   *  danger-outline button, then the filled-danger confirm. */
  confirmThenAct(user: AdminUserDto, action: 'reject' | 'suspend'): void {
    const data: ConfirmData = {
      title: this.i18n.translate(`admin.confirm.${action}Title`),
      message: this.i18n.translate(`admin.confirm.${action}Message`, { email: user.email }),
      confirmLabel: this.i18n.translate(`admin.${action}`),
      danger: true,
    };
    const ref = this.dialog.open<boolean>(ConfirmDialogComponent, {
      data,
      // A destructive confirmation is an alert, not a plain dialog; the role
      // belongs on the CDK's modal container.
      role: 'alertdialog',
      panelClass: 'app-dialog',
    });
    ref.closed.subscribe((confirmed) => {
      if (confirmed) this.act(user, action);
    });
  }
}
```

Note: `IconComponent` stays imported only if the template still uses an icon; after Step 4 it does not — drop it from `imports` then.

- [ ] **Step 4: Rework the template**

Replace `src/app/admin/admin-users.component.html` with:

```html
<section>
  <h2>{{ 'admin.title' | transloco }}</h2>

  <div class="filters" role="group" [attr.aria-label]="'admin.filterByStatus' | transloco">
    @for (f of filters; track f.status) {
      <button [class.active]="filter() === f.status" (click)="setFilter(f.status)">
        {{ 'admin.status.' + (f.status ?? 'all') | transloco }}
      </button>
    }
  </div>

  @if (actionError()) {
    <div class="banner" role="alert">
      {{ actionError()!.detail || actionError()!.title }}
      <button (click)="actionError.set(null)">{{ 'admin.dismiss' | transloco }}</button>
    </div>
  }

  @if (loading()) {
    <div class="pad"><app-spinner /></div>
  } @else if (error()) {
    <div class="banner" role="alert">
      {{ error()!.detail || error()!.title }}
      <button (click)="load()">{{ 'admin.retry' | transloco }}</button>
    </div>
  } @else if (users().length === 0) {
    <p class="pad muted">{{ 'admin.noMatch' | transloco }}</p>
  } @else {
    <ul class="users">
      @for (u of users(); track u.id) {
        <li>
          <div class="who">
            <span class="email">{{ u.email }}</span>
            <span class="meta">
              <span class="badge" [attr.data-s]="u.status">{{
                'admin.status.' + u.status | transloco
              }}</span>
              @if (u.identities.length) {
                <span class="prov">{{ u.identities.join(', ') }}</span>
              }
            </span>
          </div>
          <div class="acts">
            @if (canApprove(u)) {
              <app-button size="sm" (click)="act(u, 'approve')">
                {{ 'admin.approve' | transloco }}
              </app-button>
            }
            @if (canReject(u)) {
              <app-button size="sm" variant="danger-outline" (click)="confirmThenAct(u, 'reject')">
                {{ 'admin.reject' | transloco }}
              </app-button>
            }
            @if (canSuspend(u)) {
              <app-button size="sm" variant="danger-outline" (click)="confirmThenAct(u, 'suspend')">
                {{ 'admin.suspend' | transloco }}
              </app-button>
            }
          </div>
        </li>
      }
    </ul>
  }
</section>
```

- [ ] **Step 5: Rework the stylesheet**

Replace `src/app/admin/admin-users.component.scss` with (adapt what exists — keep any badge `data-s` colour mapping the current file has, since it already uses tokens):

```scss
@use '../theme/breakpoints' as bp;

section {
  display: flex;
  flex-direction: column;
  gap: var(--space-4);
}

h2 {
  font-size: var(--fs-lg);
  margin: 0;
}

.filters {
  display: flex;
  flex-wrap: wrap;
  gap: var(--space-1);
}

.filters button {
  padding: var(--row-pad-y) var(--row-pad-x);
  font-size: var(--fs-sm);
  border: 1px solid var(--border);
  border-radius: var(--radius-pill);
  background: none;
  color: var(--text-secondary);
  cursor: pointer;
}

.filters button.active {
  border-color: var(--accent);
  color: var(--accent);
}

.users {
  list-style: none;
  margin: 0;
  padding: 0;
}

.users li {
  display: flex;
  align-items: center;
  gap: var(--space-3);
  padding: var(--row-pad-comfy-y) var(--row-pad-comfy-x);
  border-bottom: 1px solid var(--border);
}

.who {
  flex: 1;
  min-width: 0;
  display: flex;
  flex-direction: column;
  gap: var(--space-1);
}

.email {
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.meta {
  display: flex;
  align-items: center;
  gap: var(--space-2);
  font-size: var(--fs-sm);
  color: var(--text-secondary);
}

.acts {
  display: flex;
  gap: var(--space-2);
  flex-shrink: 0;
}

@media (width <= bp.$bp-sm) {
  .users li {
    flex-direction: column;
    align-items: stretch;
  }

  .acts {
    justify-content: flex-end;
  }
}
```

Carry over the existing `.badge`, `.banner`, `.pad`, `.muted` rules from the current file unchanged (they are token-based already); delete the old `.bar`, `.back`, `h1`, and raw `.ok`/`.warn` button rules.

- [ ] **Step 6: Run the tests**

Run: `npx jest src/app/admin/admin-users.component.spec.ts`
Expected: PASS. Existing tests that clicked the old raw `.ok`/`.warn` buttons must be updated to query `app-button button` elements instead — keep their behavioral assertions identical.

- [ ] **Step 7: Commit**

```bash
git add src/app/admin/admin-users.component.* public/i18n/en.json public/i18n/de.json
git commit -m "feat(admin): rework users page rows; confirm reject and suspend (#180)"
```

---

### Task 7: Category form dialog

**Files:**
- Modify: `src/app/admin/admin.models.ts` (export the default colour)
- Create: `src/app/admin/category-form-dialog.component.ts`
- Create: `src/app/admin/category-form-dialog.component.html`
- Create: `src/app/admin/category-form-dialog.component.scss`
- Create: `src/app/admin/category-form-dialog.component.spec.ts`
- Modify: `public/i18n/en.json`, `public/i18n/de.json`

- [ ] **Step 1: Add the i18n keys**

`public/i18n/en.json`, inside `"admin"`:

```json
"categoryDialog": {
  "newTitle": "New category",
  "editTitle": "Edit category",
  "name": "Name",
  "colour": "Colour",
  "icon": "Icon"
}
```

`public/i18n/de.json`:

```json
"categoryDialog": {
  "newTitle": "Neue Kategorie",
  "editTitle": "Kategorie bearbeiten",
  "name": "Name",
  "colour": "Farbe",
  "icon": "Symbol"
}
```

- [ ] **Step 2: Export the default colour**

In `src/app/admin/admin.models.ts` add:

```ts
/** The colour a brand-new category starts with (also the pre-#180 default). */
export const DEFAULT_CATEGORY_COLOR = '#3b82f6';
```

- [ ] **Step 3: Write the failing test**

```ts
// src/app/admin/category-form-dialog.component.spec.ts
import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { TestBed } from '@angular/core/testing';
import { DIALOG_DATA, DialogRef } from '@angular/cdk/dialog';
import { provideTranslocoTesting } from '../../testing/transloco-testing';
import { API_BASE_URL } from '../core/api';
import { AdminCatalogCategoryDto } from './admin.models';
import { CategoryFormDialogComponent } from './category-form-dialog.component';

const category: AdminCatalogCategoryDto = {
  id: 7,
  key: 'tech',
  name: 'Tech',
  icon: 'memory',
  color: '#112233',
  position: 0,
  enabled: true,
  locked: false,
};

describe('CategoryFormDialogComponent', () => {
  let ctrl: HttpTestingController;
  const close = jest.fn();

  function mount(data: AdminCatalogCategoryDto | null) {
    TestBed.configureTestingModule({
      imports: [provideTranslocoTesting()],
      providers: [
        provideHttpClient(),
        provideHttpClientTesting(),
        { provide: API_BASE_URL, useValue: 'https://api.test' },
        { provide: DialogRef, useValue: { close } },
        { provide: DIALOG_DATA, useValue: data },
      ],
    });
    const f = TestBed.createComponent(CategoryFormDialogComponent);
    f.detectChanges();
    ctrl = TestBed.inject(HttpTestingController);
    return f;
  }

  beforeEach(() => close.mockClear());
  afterEach(() => ctrl.verify());

  it('prefills from the edited category and PUTs on submit, closing with the result', () => {
    const f = mount(category);
    const c = f.componentInstance;
    expect(c.form.getRawValue().name).toBe('Tech');

    c.submit();
    const req = ctrl.expectOne('https://api.test/api/admin/catalog/categories/7');
    expect(req.request.body).toMatchObject({ name: 'Tech', color: '#112233', key: 'tech' });
    req.flush({ category });
    expect(close).toHaveBeenCalledWith(category);
  });

  it('POSTs a new category with the default colour and an empty key', () => {
    const f = mount(null);
    const c = f.componentInstance;
    c.form.patchValue({ name: 'News' });

    c.submit();
    const req = ctrl.expectOne('https://api.test/api/admin/catalog/categories');
    expect(req.request.body).toMatchObject({ name: 'News', key: '' });
    req.flush({ category });
    expect(close).toHaveBeenCalled();
  });

  it('does not submit an empty name', () => {
    const f = mount(null);
    f.componentInstance.submit();
    ctrl.expectNone('https://api.test/api/admin/catalog/categories');
  });
});
```

**Note on URLs:** the paths above assume `AdminApi.saveCategory` PUTs to
`/api/admin/catalog/categories/{id}` and POSTs to `/api/admin/catalog/categories`.
Verify against `src/app/admin/admin-api.ts` before running and adjust the two
`expectOne` URLs to whatever `saveCategory(id|null, …)` actually calls — the
dialog reuses that method untouched.

- [ ] **Step 4: Run it to verify it fails**

Run: `npx jest src/app/admin/category-form-dialog.component.spec.ts`
Expected: FAIL — `Cannot find module './category-form-dialog.component'`

- [ ] **Step 5: Implement**

```ts
// src/app/admin/category-form-dialog.component.ts
import { HttpErrorResponse } from '@angular/common/http';
import { Component, inject, signal } from '@angular/core';
import { NonNullableFormBuilder, ReactiveFormsModule, Validators } from '@angular/forms';
import { A11yModule } from '@angular/cdk/a11y';
import { DIALOG_DATA, DialogRef } from '@angular/cdk/dialog';
import { TranslocoPipe } from '@jsverse/transloco';
import { parseProblem } from '../core/problem';
import { ButtonComponent } from '../shared/button/button.component';
import { ColorFieldComponent } from '../shared/color-field/color-field.component';
import { FieldComponent } from '../shared/field/field.component';
import { IconPickerComponent } from '../shared/icon-picker/icon-picker.component';
import { OverlayPanelComponent } from '../shared/overlay-panel/overlay-panel.component';
import { AdminApi } from './admin-api';
import { AdminCatalogCategoryDto, DEFAULT_CATEGORY_COLOR } from './admin.models';

/** Create or edit a catalog category. The dialog performs its own API write and
 *  closes with the saved entity — the same contract as the tag form. */
@Component({
  selector: 'app-category-form-dialog',
  imports: [
    ReactiveFormsModule,
    A11yModule,
    ButtonComponent,
    ColorFieldComponent,
    FieldComponent,
    IconPickerComponent,
    OverlayPanelComponent,
    TranslocoPipe,
  ],
  templateUrl: './category-form-dialog.component.html',
  styleUrl: './category-form-dialog.component.scss',
})
export class CategoryFormDialogComponent {
  readonly ref = inject<DialogRef<AdminCatalogCategoryDto>>(DialogRef);
  readonly data = inject<AdminCatalogCategoryDto | null>(DIALOG_DATA);
  private readonly api = inject(AdminApi);
  private readonly fb = inject(NonNullableFormBuilder);

  readonly isEdit = this.data !== null;
  readonly titleKey = this.isEdit ? 'admin.categoryDialog.editTitle' : 'admin.categoryDialog.newTitle';

  readonly form = this.fb.group({
    name: [this.data?.name ?? '', [Validators.required, Validators.maxLength(100)]],
    enabled: [this.data?.enabled ?? true],
    locked: [this.data?.locked ?? false],
  });
  readonly icon = signal(this.data?.icon ?? '');
  readonly color = signal(this.data?.color ?? DEFAULT_CATEGORY_COLOR);
  readonly loading = signal(false);
  readonly error = signal<string | null>(null);

  /** A category always carries a colour; the clear-less colour field never
   *  emits null, and the guard states that invariant. */
  applyColor(color: string | null): void {
    if (color !== null) this.color.set(color);
  }

  submit(): void {
    if (this.form.invalid) return;
    const value = this.form.getRawValue();
    const body = {
      key: this.data?.key ?? '',
      name: value.name.trim(),
      icon: this.icon(),
      color: this.color(),
      enabled: value.enabled,
      locked: value.locked,
    };
    this.loading.set(true);
    this.error.set(null);
    this.api.saveCategory(this.data?.id ?? null, body).subscribe({
      next: (result) => this.ref.close(result.category),
      error: (failure: HttpErrorResponse) => {
        this.loading.set(false);
        const problem = parseProblem(failure);
        this.error.set(problem.errors?.['name']?.[0] ?? problem.detail ?? problem.title);
      },
    });
  }
}
```

```html
<!-- src/app/admin/category-form-dialog.component.html -->
<!-- The form wraps the panel: Save is projected into the panel's footer, and a
     submit button only submits a form it is a DOM descendant of. -->
<form [formGroup]="form" (ngSubmit)="submit()" cdkTrapFocus>
  <app-overlay-panel [heading]="titleKey | transloco">
    <div class="fields">
      <app-field [label]="'admin.categoryDialog.name' | transloco" [required]="true">
        <input id="category-name" formControlName="name" maxlength="100" cdkFocusInitial />
      </app-field>

      <p class="lbl">{{ 'admin.categoryDialog.colour' | transloco }}</p>
      <app-color-field
        [value]="color()"
        [clearable]="false"
        (valueChange)="applyColor($event)"
      />

      <p class="lbl">{{ 'admin.categoryDialog.icon' | transloco }}</p>
      <app-icon-picker inline [value]="icon()" (valueChange)="icon.set($event)" [color]="color()" />

      <label class="check">
        <input type="checkbox" formControlName="enabled" />
        {{ 'admin.status.active' | transloco }}
      </label>
      <label class="check">
        <input type="checkbox" formControlName="locked" />
        {{ 'admin.locked' | transloco }}
      </label>

      @if (error()) {
        <p class="error" role="alert">{{ error() }}</p>
      }
    </div>

    <app-button footer (click)="ref.close()">
      {{ 'dialog.cancel' | transloco }}
    </app-button>
    <app-button footer type="submit" variant="primary" [disabled]="loading()">
      {{ 'common.save' | transloco }}
    </app-button>
  </app-overlay-panel>
</form>
```

```scss
// src/app/admin/category-form-dialog.component.scss
:host {
  --panel-w: 460px;
}

.fields {
  display: flex;
  flex-direction: column;
  gap: var(--space-2);
}

.lbl {
  margin: var(--space-2) 0 0;
  font-size: var(--fs-sm);
  color: var(--text-secondary);
}

.check {
  display: flex;
  align-items: center;
  gap: var(--space-2);
  font-size: var(--fs-sm);
}

.error {
  margin: var(--space-2) 0 0;
  color: var(--danger, var(--text-primary));
  font-size: var(--fs-sm);
}
```

**Check before finishing:** compare the `.error` colour token with what
`tag-form-dialog.component.scss` uses and copy that exactly (the codebase has an
established danger-text token; do not invent a fallback chain if one exists).

- [ ] **Step 6: Run the test to verify it passes**

Run: `npx jest src/app/admin/category-form-dialog.component.spec.ts`
Expected: PASS (3 tests)

- [ ] **Step 7: Commit**

```bash
git add src/app/admin/category-form-dialog.component.* src/app/admin/admin.models.ts \
  public/i18n/en.json public/i18n/de.json
git commit -m "feat(admin): category form dialog (#180)"
```

---

### Task 8: Feed form dialog

**Files:**
- Create: `src/app/admin/feed-form-dialog.component.ts`
- Create: `src/app/admin/feed-form-dialog.component.html`
- Create: `src/app/admin/feed-form-dialog.component.scss`
- Create: `src/app/admin/feed-form-dialog.component.spec.ts`
- Modify: `public/i18n/en.json`, `public/i18n/de.json`

- [ ] **Step 1: Add the i18n keys**

`public/i18n/en.json`, inside `"admin"`:

```json
"feedDialog": {
  "newTitle": "New feed",
  "editTitle": "Edit feed",
  "title": "Title",
  "url": "Feed URL",
  "siteUrl": "Site URL",
  "description": "Description",
  "category": "Category"
}
```

`public/i18n/de.json`:

```json
"feedDialog": {
  "newTitle": "Neuer Feed",
  "editTitle": "Feed bearbeiten",
  "title": "Titel",
  "url": "Feed-URL",
  "siteUrl": "Website-URL",
  "description": "Beschreibung",
  "category": "Kategorie"
}
```

- [ ] **Step 2: Write the failing test**

```ts
// src/app/admin/feed-form-dialog.component.spec.ts
import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { TestBed } from '@angular/core/testing';
import { DIALOG_DATA, DialogRef } from '@angular/cdk/dialog';
import { provideTranslocoTesting } from '../../testing/transloco-testing';
import { API_BASE_URL } from '../core/api';
import { AdminCatalogCategoryDto, AdminCatalogFeedDto } from './admin.models';
import { FeedFormDialogComponent, FeedFormData } from './feed-form-dialog.component';

const categories: AdminCatalogCategoryDto[] = [
  { id: 1, key: 'a', name: 'A', icon: '', color: '#112233', position: 0, enabled: true, locked: false },
  { id: 2, key: 'b', name: 'B', icon: '', color: '#112233', position: 1, enabled: true, locked: false },
];

const feed: AdminCatalogFeedDto = {
  id: 5,
  categoryId: 1,
  title: 'Ars',
  url: 'https://example.test/feed',
  siteUrl: null,
  description: null,
  sourceFormat: 'xml',
  position: 0,
  enabled: true,
  locked: false,
  faviconFetchedAt: null,
  faviconFailedAt: null,
};

describe('FeedFormDialogComponent', () => {
  let ctrl: HttpTestingController;
  const close = jest.fn();

  function mount(data: FeedFormData) {
    TestBed.configureTestingModule({
      imports: [provideTranslocoTesting()],
      providers: [
        provideHttpClient(),
        provideHttpClientTesting(),
        { provide: API_BASE_URL, useValue: 'https://api.test' },
        { provide: DialogRef, useValue: { close } },
        { provide: DIALOG_DATA, useValue: data },
      ],
    });
    const f = TestBed.createComponent(FeedFormDialogComponent);
    f.detectChanges();
    ctrl = TestBed.inject(HttpTestingController);
    return f;
  }

  beforeEach(() => close.mockClear());
  afterEach(() => ctrl.verify());

  it('prefills from the edited feed and PUTs on submit', () => {
    const f = mount({ feed, categories, categoryId: 1 });
    const c = f.componentInstance;
    expect(c.form.getRawValue().title).toBe('Ars');

    c.submit();
    const req = ctrl.expectOne('https://api.test/api/admin/catalog/feeds/5');
    expect(req.request.body).toMatchObject({
      title: 'Ars',
      url: 'https://example.test/feed',
      categoryId: 1,
      sourceFormat: 'xml',
    });
    req.flush({ feed });
    expect(close).toHaveBeenCalledWith(feed);
  });

  it('creates a new feed preselecting the opening category, empty strings as null', () => {
    const f = mount({ feed: null, categories, categoryId: 2 });
    const c = f.componentInstance;
    expect(c.form.getRawValue().categoryId).toBe(2);
    c.form.patchValue({ title: 'New', url: 'https://example.test/new' });

    c.submit();
    const req = ctrl.expectOne('https://api.test/api/admin/catalog/feeds');
    expect(req.request.body).toMatchObject({
      title: 'New',
      categoryId: 2,
      siteUrl: null,
      description: null,
      sourceFormat: 'xml',
    });
    req.flush({ feed });
    expect(close).toHaveBeenCalled();
  });

  it('does not submit without title and url', () => {
    const f = mount({ feed: null, categories, categoryId: 1 });
    f.componentInstance.submit();
    ctrl.expectNone('https://api.test/api/admin/catalog/feeds');
  });
});
```

**Note on URLs:** as in Task 7, verify the two `expectOne` URLs against what
`AdminApi.saveFeed(id|null, …)` actually calls and adjust.

- [ ] **Step 3: Run it to verify it fails**

Run: `npx jest src/app/admin/feed-form-dialog.component.spec.ts`
Expected: FAIL — `Cannot find module './feed-form-dialog.component'`

- [ ] **Step 4: Implement**

```ts
// src/app/admin/feed-form-dialog.component.ts
import { HttpErrorResponse } from '@angular/common/http';
import { Component, inject, signal } from '@angular/core';
import { NonNullableFormBuilder, ReactiveFormsModule, Validators } from '@angular/forms';
import { A11yModule } from '@angular/cdk/a11y';
import { DIALOG_DATA, DialogRef } from '@angular/cdk/dialog';
import { TranslocoPipe } from '@jsverse/transloco';
import { parseProblem } from '../core/problem';
import { ButtonComponent } from '../shared/button/button.component';
import { FieldComponent } from '../shared/field/field.component';
import { OverlayPanelComponent } from '../shared/overlay-panel/overlay-panel.component';
import { AdminApi } from './admin-api';
import { AdminCatalogCategoryDto, AdminCatalogFeedDto } from './admin.models';

export interface FeedFormData {
  /** null → create. */
  feed: AdminCatalogFeedDto | null;
  categories: AdminCatalogCategoryDto[];
  /** Preselected category for a new feed — the block whose Add button opened us. */
  categoryId: number;
}

/** Create or edit a catalog feed. Performs its own API write and closes with
 *  the saved entity — the same contract as the tag form. */
@Component({
  selector: 'app-feed-form-dialog',
  imports: [
    ReactiveFormsModule,
    A11yModule,
    ButtonComponent,
    FieldComponent,
    OverlayPanelComponent,
    TranslocoPipe,
  ],
  templateUrl: './feed-form-dialog.component.html',
  styleUrl: './feed-form-dialog.component.scss',
})
export class FeedFormDialogComponent {
  readonly ref = inject<DialogRef<AdminCatalogFeedDto>>(DialogRef);
  readonly data = inject<FeedFormData>(DIALOG_DATA);
  private readonly api = inject(AdminApi);
  private readonly fb = inject(NonNullableFormBuilder);

  readonly isEdit = this.data.feed !== null;
  readonly titleKey = this.isEdit ? 'admin.feedDialog.editTitle' : 'admin.feedDialog.newTitle';

  readonly form = this.fb.group({
    title: [this.data.feed?.title ?? '', [Validators.required, Validators.maxLength(255)]],
    url: [this.data.feed?.url ?? '', [Validators.required]],
    siteUrl: [this.data.feed?.siteUrl ?? ''],
    description: [this.data.feed?.description ?? ''],
    categoryId: [this.data.feed?.categoryId ?? this.data.categoryId],
    enabled: [this.data.feed?.enabled ?? true],
    locked: [this.data.feed?.locked ?? false],
  });
  readonly loading = signal(false);
  readonly error = signal<string | null>(null);

  submit(): void {
    if (this.form.invalid) return;
    const value = this.form.getRawValue();
    const body = {
      categoryId: value.categoryId,
      title: value.title.trim(),
      url: value.url.trim(),
      siteUrl: value.siteUrl.trim() || null,
      description: value.description.trim() || null,
      sourceFormat: this.data.feed?.sourceFormat ?? 'xml',
      enabled: value.enabled,
      locked: value.locked,
    };
    this.loading.set(true);
    this.error.set(null);
    this.api.saveFeed(this.data.feed?.id ?? null, body).subscribe({
      next: (result) => this.ref.close(result.feed),
      error: (failure: HttpErrorResponse) => {
        this.loading.set(false);
        const problem = parseProblem(failure);
        this.error.set(problem.errors?.['url']?.[0] ?? problem.detail ?? problem.title);
      },
    });
  }
}
```

```html
<!-- src/app/admin/feed-form-dialog.component.html -->
<form [formGroup]="form" (ngSubmit)="submit()" cdkTrapFocus>
  <app-overlay-panel [heading]="titleKey | transloco">
    <div class="fields">
      <app-field [label]="'admin.feedDialog.title' | transloco" [required]="true">
        <input id="feed-title" formControlName="title" maxlength="255" cdkFocusInitial />
      </app-field>
      <app-field [label]="'admin.feedDialog.url' | transloco" [required]="true">
        <input id="feed-url" formControlName="url" type="url" />
      </app-field>
      <app-field [label]="'admin.feedDialog.siteUrl' | transloco">
        <input id="feed-site-url" formControlName="siteUrl" type="url" />
      </app-field>
      <app-field [label]="'admin.feedDialog.description' | transloco">
        <input id="feed-description" formControlName="description" />
      </app-field>
      <app-field [label]="'admin.feedDialog.category' | transloco">
        <select id="feed-category" formControlName="categoryId">
          @for (option of data.categories; track option.id) {
            <option [value]="option.id">{{ option.name }}</option>
          }
        </select>
      </app-field>

      <label class="check">
        <input type="checkbox" formControlName="enabled" />
        {{ 'admin.status.active' | transloco }}
      </label>
      <label class="check">
        <input type="checkbox" data-testid="feed-locked" formControlName="locked" />
        {{ 'admin.locked' | transloco }}
      </label>

      @if (error()) {
        <p class="error" role="alert">{{ error() }}</p>
      }
    </div>

    <app-button footer (click)="ref.close()">
      {{ 'dialog.cancel' | transloco }}
    </app-button>
    <app-button footer type="submit" variant="primary" [disabled]="loading()" data-testid="feed-save">
      {{ 'common.save' | transloco }}
    </app-button>
  </app-overlay-panel>
</form>
```

**`categoryId` select gotcha:** `<option [value]="option.id">` binds string values,
so `form.getRawValue().categoryId` may come back as a string after user
interaction. Use `<option [ngValue]="option.id">` only with `FormsModule`; with
reactive forms, keep `[value]` and normalise in `submit()`:
`categoryId: Number(value.categoryId)`. Write it that way from the start.

```scss
// src/app/admin/feed-form-dialog.component.scss
:host {
  --panel-w: 460px;
}

.fields {
  display: flex;
  flex-direction: column;
  gap: var(--space-2);
}

.check {
  display: flex;
  align-items: center;
  gap: var(--space-2);
  font-size: var(--fs-sm);
}

.error {
  margin: var(--space-2) 0 0;
  font-size: var(--fs-sm);
}
```

(Use the same danger-text colour rule as Task 7's `.error`, copied from the
tag-form dialog stylesheet.)

- [ ] **Step 5: Run the test to verify it passes**

Run: `npx jest src/app/admin/feed-form-dialog.component.spec.ts`
Expected: PASS (3 tests)

- [ ] **Step 6: Commit**

```bash
git add src/app/admin/feed-form-dialog.component.* public/i18n/en.json public/i18n/de.json
git commit -m "feat(admin): feed form dialog (#180)"
```

---

### Task 9: Admin catalog rework

**Files:**
- Modify: `src/app/admin/admin-catalog.component.ts`
- Modify: `src/app/admin/admin-catalog.component.html`
- Modify: `src/app/admin/admin-catalog.component.scss`
- Modify: `src/app/admin/admin-catalog.component.spec.ts`
- Modify: `public/i18n/en.json`, `public/i18n/de.json`

- [ ] **Step 1: Add the remaining i18n keys**

`public/i18n/en.json`, inside `"admin"`:

```json
"edit": "Edit",
"addCategory": "Add category",
"addFeed": "Add feed",
"moveUp": "Move up",
"moveDown": "Move down",
"refreshFavicon": "Refresh icon",
"inactive": "inactive",
"feedCountOne": "{{count}} feed",
"feedCountOther": "{{count}} feeds",
"confirmDelete": {
  "categoryTitle": "Delete category",
  "categoryMessage": "Delete \"{{name}}\" and every feed in it?",
  "feedTitle": "Delete feed",
  "feedMessage": "Delete \"{{title}}\"?"
}
```

`public/i18n/de.json`:

```json
"edit": "Bearbeiten",
"addCategory": "Kategorie hinzufügen",
"addFeed": "Feed hinzufügen",
"moveUp": "Nach oben",
"moveDown": "Nach unten",
"refreshFavicon": "Icon aktualisieren",
"inactive": "inaktiv",
"feedCountOne": "{{count}} Feed",
"feedCountOther": "{{count}} Feeds",
"confirmDelete": {
  "categoryTitle": "Kategorie löschen",
  "categoryMessage": "„{{name}}“ samt aller Feeds darin löschen?",
  "feedTitle": "Feed löschen",
  "feedMessage": "„{{title}}“ löschen?"
}
```

- [ ] **Step 2: Write the failing tests**

Add to `src/app/admin/admin-catalog.component.spec.ts` (read the existing spec
first; keep its load/import/warm tests, extend its providers with the Dialog
stub, and drop tests that exercised the deleted inline-edit methods —
`saveCategory`, `addCategory`, `saveFeed`, `addFeed`, `applyCategoryColor`):

```ts
import { Dialog } from '@angular/cdk/dialog';
import { Subject } from 'rxjs';

// per-test dialog stub, fresh Subject each open:
let dialogClosed: Subject<unknown>;
const dialogOpen = jest.fn(() => ({ closed: dialogClosed }));
// providers: { provide: Dialog, useValue: { open: dialogOpen } },
// beforeEach: dialogClosed = new Subject(); dialogOpen.mockClear();

it('upserts the category a closed dialog returns', () => {
  const f = mountLoaded(); // helper that mounts + flushes the catalog GET
  f.componentInstance.openCategoryDialog(null);
  expect(dialogOpen).toHaveBeenCalled();

  dialogClosed.next({ id: 9, key: '', name: 'Fresh', icon: '', color: '#112233',
    position: 5, enabled: true, locked: false });
  expect(f.componentInstance.categories().some((c) => c.id === 9)).toBe(true);
});

it('ignores a cancelled dialog', () => {
  const f = mountLoaded();
  const before = f.componentInstance.categories();
  f.componentInstance.openCategoryDialog(null);
  dialogClosed.next(undefined);
  expect(f.componentInstance.categories()).toEqual(before);
});

it('deletes a feed only after confirmation', () => {
  const f = mountLoaded(); // seeded with feed id 5
  f.componentInstance.confirmDeleteFeed(f.componentInstance.feeds()[0]);
  ctrl.expectNone('https://api.test/api/admin/catalog/feeds/5');
  dialogClosed.next(true);
  ctrl.expectOne('https://api.test/api/admin/catalog/feeds/5').flush({});
  expect(f.componentInstance.feeds().length).toBe(0);
});
```

(Adjust `mountLoaded` and URLs to the existing spec's helpers and `AdminApi`
paths — mirror how the current spec flushes the initial catalog request.)

Run: `npx jest src/app/admin/admin-catalog.component.spec.ts`
Expected: FAIL — `openCategoryDialog` / `confirmDeleteFeed` do not exist.

- [ ] **Step 3: Rework the component**

In `src/app/admin/admin-catalog.component.ts`:

Remove: `FormsModule`, `RouterLink`, `IconPickerComponent`, `ColorFieldComponent`
imports and the members `newCategory`, `newFeed`, `saveCategory`, `addCategory`,
`applyCategoryColor`, `persistCategory`, `saveFeed`, `addFeed`, `persistFeed`,
`categoryBody`, `feedBody`, `blankCategory`, `blankFeed`.

Keep unchanged: `load`, `fetchCatalog`, `loadBundled`, `feedsFor`,
`deleteCategory`, `deleteFeed`, `moveCategory`, `moveFeed`, `refreshFavicon`,
the whole import/warm block, `upsertCategory`, `upsertFeed`, `swap`.

Add:

```ts
// new imports
import { Dialog } from '@angular/cdk/dialog';
import { TranslocoPipe, TranslocoService } from '@jsverse/transloco';
import { ConfirmData, ConfirmDialogComponent } from '../reader/manage/confirm-dialog.component';
import { CategoryFormDialogComponent } from './category-form-dialog.component';
import { FeedFormDialogComponent, FeedFormData } from './feed-form-dialog.component';

// new injected services
private readonly dialog = inject(Dialog);
private readonly i18n = inject(TranslocoService);

// --- Dialog-based editing ---------------------------------------------------

openCategoryDialog(category: AdminCatalogCategoryDto | null): void {
  const ref = this.dialog.open<AdminCatalogCategoryDto>(CategoryFormDialogComponent, {
    data: category,
    panelClass: 'app-dialog',
  });
  ref.closed.subscribe((saved) => {
    if (saved) this.upsertCategory(saved);
  });
}

openFeedDialog(feed: AdminCatalogFeedDto | null, categoryId: number): void {
  const data: FeedFormData = { feed, categories: this.categories(), categoryId };
  const ref = this.dialog.open<AdminCatalogFeedDto>(FeedFormDialogComponent, {
    data,
    panelClass: 'app-dialog',
  });
  ref.closed.subscribe((saved) => {
    if (saved) this.upsertFeed(saved);
  });
}

confirmDeleteCategory(category: AdminCatalogCategoryDto): void {
  const data: ConfirmData = {
    title: this.i18n.translate('admin.confirmDelete.categoryTitle'),
    message: this.i18n.translate('admin.confirmDelete.categoryMessage', {
      name: category.name,
    }),
    confirmLabel: this.i18n.translate('common.delete'),
    danger: true,
  };
  this.openConfirm(data, () => this.deleteCategory(category));
}

confirmDeleteFeed(feed: AdminCatalogFeedDto): void {
  const data: ConfirmData = {
    title: this.i18n.translate('admin.confirmDelete.feedTitle'),
    message: this.i18n.translate('admin.confirmDelete.feedMessage', { title: feed.title }),
    confirmLabel: this.i18n.translate('common.delete'),
    danger: true,
  };
  this.openConfirm(data, () => this.deleteFeed(feed));
}

private openConfirm(data: ConfirmData, onConfirm: () => void): void {
  const ref = this.dialog.open<boolean>(ConfirmDialogComponent, {
    data,
    // A destructive confirmation is an alert, not a plain dialog.
    role: 'alertdialog',
    panelClass: 'app-dialog',
  });
  ref.closed.subscribe((confirmed) => {
    if (confirmed) onConfirm();
  });
}
```

The `imports:` array becomes:
`[FieldComponent, ButtonComponent, IconComponent, SpinnerComponent, TranslocoPipe]`.

- [ ] **Step 4: Rework the template**

Replace `src/app/admin/admin-catalog.component.html` with:

```html
<section>
  <h2>{{ 'admin.catalog' | transloco }}</h2>

  @if (actionError()) {
    <div class="banner" role="alert">
      {{ actionError()!.detail || actionError()!.title }}
      <button (click)="actionError.set(null)">{{ 'admin.dismiss' | transloco }}</button>
    </div>
  }

  @if (loading()) {
    <div class="pad"><app-spinner /></div>
  } @else if (error()) {
    <div class="banner" role="alert">
      {{ error()!.detail || error()!.title }}
      <button (click)="load()">{{ 'admin.retry' | transloco }}</button>
    </div>
  } @else {
    @if (!hasFeeds()) {
      <p class="notice">{{ 'admin.catalogEmpty' | transloco }}</p>
    }

    <section class="import">
      @if (bundled()?.available) {
        <app-button variant="primary" data-testid="import-bundled" (click)="importBundled()">
          {{
            'admin.importBundled'
              | transloco: { categories: bundled()!.categories, feeds: bundled()!.feeds }
          }}
        </app-button>
      }

      <div class="upload">
        <app-field [label]="'admin.importUpload' | transloco">
          <input
            #fileInput
            type="file"
            accept=".opml,.xml"
            data-testid="import-file"
            (change)="onFileSelected($event)"
          />
        </app-field>
        <app-field [label]="'admin.importMode' | transloco">
          <select data-testid="import-mode" (change)="setMode($event)">
            <option value="merge" [selected]="importMode() === 'merge'">
              {{ 'admin.importModeMerge' | transloco }}
            </option>
            <option value="replace" [selected]="importMode() === 'replace'">
              {{ 'admin.importModeReplace' | transloco }}
            </option>
          </select>
        </app-field>
        <app-button
          variant="primary"
          data-testid="import-run"
          [disabled]="pendingDocument() === null"
          (click)="importUpload()"
        >
          {{ 'admin.importUpload' | transloco }}
        </app-button>
      </div>

      @if (importError()) {
        <p class="banner" role="alert">{{ importError()!.detail || importError()!.title }}</p>
      }

      @if (importCounts(); as counts) {
        <p class="counts">
          {{
            'admin.importDone'
              | transloco
                : {
                    categoriesCreated: counts.categoriesCreated,
                    categoriesUpdated: counts.categoriesUpdated,
                    categoriesRemoved: counts.categoriesRemoved,
                    feedsCreated: counts.feedsCreated,
                    feedsUpdated: counts.feedsUpdated,
                    feedsRemoved: counts.feedsRemoved,
                  }
          }}
          @if (counts.lockedSkipped > 0) {
            — {{ 'admin.importLockedSkipped' | transloco: { count: counts.lockedSkipped } }}
          }
        </p>
      }

      <div class="warm">
        <app-button [disabled]="warming()" (click)="warm()">
          {{ 'admin.warmIcons' | transloco }}
        </app-button>
        @if (warming()) {
          <span class="progress">
            {{ 'admin.warmingIcons' | transloco: { remaining: warmRemaining() } }}
          </span>
        } @else if (warmReport(); as report) {
          <span class="progress">
            {{ 'admin.warmDone' | transloco: { warmed: report.warmed, failed: report.failed } }}
          </span>
        }
      </div>
    </section>

    <p class="hint">{{ 'admin.lockedHint' | transloco }}</p>

    <div class="list-head">
      <app-button
        size="sm"
        variant="primary"
        data-testid="add-category"
        (click)="openCategoryDialog(null)"
      >
        <app-icon name="add" size="sm" /> {{ 'admin.addCategory' | transloco }}
      </app-button>
    </div>

    <ul class="categories">
      @for (category of categories(); track category.id; let ci = $index) {
        <li class="category" data-testid="admin-category">
          <div class="row">
            <span class="swatch" [style.background]="category.color"></span>
            @if (category.icon) {
              <app-icon [name]="category.icon" size="sm" />
            }
            <span class="name">{{ category.name }}</span>
            @if (category.locked) {
              <app-icon class="lock" name="lock" size="sm" />
            }
            @if (!category.enabled) {
              <span class="badge">{{ 'admin.inactive' | transloco }}</span>
            }
            <span class="count">
              {{
                (feedsFor(category.id).length === 1
                  ? 'admin.feedCountOne'
                  : 'admin.feedCountOther'
                ) | transloco: { count: feedsFor(category.id).length }
              }}
            </span>
            <span class="acts">
              <button
                class="icon-act"
                (click)="moveCategory(ci, -1)"
                [attr.aria-label]="'admin.moveUp' | transloco"
              >
                <app-icon name="keyboard_arrow_up" size="md" />
              </button>
              <button
                class="icon-act"
                (click)="moveCategory(ci, 1)"
                [attr.aria-label]="'admin.moveDown' | transloco"
              >
                <app-icon name="keyboard_arrow_down" size="md" />
              </button>
              <app-button
                size="sm"
                data-testid="category-edit"
                (click)="openCategoryDialog(category)"
              >
                {{ 'admin.edit' | transloco }}
              </app-button>
              <app-button
                size="sm"
                variant="danger-outline"
                (click)="confirmDeleteCategory(category)"
              >
                {{ 'common.delete' | transloco }}
              </app-button>
            </span>
          </div>

          <ul class="feeds">
            @for (feed of feedsFor(category.id); track feed.id) {
              <li class="feed" data-testid="admin-feed">
                <span class="ident">
                  <span class="title">
                    {{ feed.title }}
                    @if (feed.locked) {
                      <app-icon class="lock" name="lock" size="text" />
                    }
                    @if (!feed.enabled) {
                      <span class="badge">{{ 'admin.inactive' | transloco }}</span>
                    }
                  </span>
                  <span class="url">{{ feed.url }}</span>
                </span>
                <span class="acts">
                  <button
                    class="icon-act"
                    (click)="moveFeed(feed, -1)"
                    [attr.aria-label]="'admin.moveUp' | transloco"
                  >
                    <app-icon name="keyboard_arrow_up" size="md" />
                  </button>
                  <button
                    class="icon-act"
                    (click)="moveFeed(feed, 1)"
                    [attr.aria-label]="'admin.moveDown' | transloco"
                  >
                    <app-icon name="keyboard_arrow_down" size="md" />
                  </button>
                  <button
                    class="icon-act"
                    data-testid="refresh-favicon"
                    (click)="refreshFavicon(feed)"
                    [attr.aria-label]="'admin.refreshFavicon' | transloco"
                  >
                    <app-icon name="refresh" size="md" />
                  </button>
                  <app-button
                    size="sm"
                    data-testid="feed-edit"
                    (click)="openFeedDialog(feed, feed.categoryId)"
                  >
                    {{ 'admin.edit' | transloco }}
                  </app-button>
                  <app-button
                    size="sm"
                    variant="danger-outline"
                    (click)="confirmDeleteFeed(feed)"
                  >
                    {{ 'common.delete' | transloco }}
                  </app-button>
                </span>
              </li>
            }

            <li class="feed-add">
              <app-button size="sm" data-testid="add-feed" (click)="openFeedDialog(null, category.id)">
                <app-icon name="add" size="sm" /> {{ 'admin.addFeed' | transloco }}
              </app-button>
            </li>
          </ul>
        </li>
      }
    </ul>
  }
</section>
```

- [ ] **Step 5: Rework the stylesheet**

Replace `src/app/admin/admin-catalog.component.scss`. Carry over the existing
token-based `.banner`, `.pad`, `.notice`, `.hint`, `.counts`, `.progress`,
`.import`, `.upload`, `.warm` rules (they style the unchanged import card);
delete `.bar`, `.back`, `h1` and every rule that styled the inline grid
(`.grow`, `.check`, `.ok`, `.warn`, old `.row`/`.feed` input layouts). New rules:

```scss
@use '../theme/breakpoints' as bp;

section {
  display: flex;
  flex-direction: column;
  gap: var(--space-4);
}

h2 {
  font-size: var(--fs-lg);
  margin: 0;
}

.list-head {
  display: flex;
  justify-content: flex-end;
}

.categories {
  list-style: none;
  margin: 0;
  padding: 0;
  display: flex;
  flex-direction: column;
  gap: var(--space-4);
}

.category {
  border: 1px solid var(--border);
  border-radius: var(--radius-lg);
  overflow: hidden;
}

.row {
  display: flex;
  align-items: center;
  gap: var(--space-2);
  padding: var(--row-pad-comfy-y) var(--row-pad-comfy-x);
  background: var(--surface-1);
  border-bottom: 1px solid var(--border);
}

.swatch {
  width: var(--space-3);
  height: var(--space-3);
  border-radius: var(--radius-sm);
  flex-shrink: 0;
}

.name {
  font-weight: 600;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.lock {
  color: var(--text-muted);
}

.badge {
  font-size: var(--fs-xs);
  color: var(--text-secondary);
  border: 1px solid var(--border);
  border-radius: var(--radius-pill);
  padding: 0 var(--space-2);
}

.count {
  font-size: var(--fs-sm);
  color: var(--text-muted);
  white-space: nowrap;
}

.acts {
  margin-left: auto;
  display: flex;
  align-items: center;
  gap: var(--space-1);
  flex-shrink: 0;
}

.icon-act {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: var(--tap-target);
  height: var(--tap-target);
  border: none;
  background: none;
  color: var(--text-secondary);
  border-radius: var(--radius);
  cursor: pointer;
}

.icon-act:hover {
  background: var(--surface-1);
  color: var(--text-primary);
}

.feeds {
  list-style: none;
  margin: 0;
  padding: 0;
}

.feed {
  display: flex;
  align-items: center;
  gap: var(--space-2);
  padding: var(--row-pad-y) var(--row-pad-comfy-x);
  border-bottom: 1px solid var(--border);
}

.ident {
  flex: 1;
  min-width: 0;
  display: flex;
  flex-direction: column;
  gap: var(--space-0);
}

.title {
  display: inline-flex;
  align-items: center;
  gap: var(--space-1);
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.url {
  font-size: var(--fs-sm);
  color: var(--text-muted);
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.feed-add {
  padding: var(--row-pad-y) var(--row-pad-comfy-x);
}

@media (width <= bp.$bp-md) {
  .row,
  .feed {
    flex-wrap: wrap;
  }

  .acts {
    width: 100%;
    justify-content: flex-end;
    margin-left: 0;
  }
}
```

(If `font-weight: 600` is not used elsewhere in the codebase, use the weight the
reader's entry rows use for titles — grep `font-weight` and match.)

- [ ] **Step 6: Run the tests, fix the fallout**

Run: `npx jest src/app/admin/admin-catalog.component.spec.ts`
Expected: PASS after removing/adapting old inline-edit tests. Then run
`npx jest` (full suite) — expected: PASS.

- [ ] **Step 7: Commit**

```bash
git add src/app/admin/admin-catalog.component.* public/i18n/en.json public/i18n/de.json
git commit -m "feat(admin): catalog becomes read-only rows with edit dialogs (#180)"
```

---

### Task 10: E2e smoke update

**Files:**
- Modify: `e2e/settings-admin-smoke.spec.ts`

Precondition: the Docker stack is up (`docker compose up -d` from the repo
root) and the seeded admin exists. First verify the visible label of the
catalog nav entry: `grep -A2 '"catalog"' public/i18n/en.json` — the assertions
below assume the `admin.title` / `admin.catalog` strings render as "Users" /
whatever that grep shows; adjust the `name:` matchers to the real strings.

- [ ] **Step 1: Rewrite the smoke**

Keep the header comment block and `signInAsAdmin` helper unchanged; replace the
test body with:

```ts
test('settings shell navigates sections; admin pages live inside it', async ({ page }) => {
  const signedIn = await signInAsAdmin(page);
  test.skip(!signedIn, 'seeded admin login unavailable (run app:e2e:seed-admin against the stack)');

  // Open Settings from the account menu. On the desktop viewport the bare
  // /settings url forwards to the first section.
  await page.getByRole('button', { name: 'Account' }).click();
  await page.getByRole('menuitem', { name: 'Settings' }).click();
  await expect(page).toHaveURL(/\/settings\/tags$/);
  await expect(page.getByRole('heading', { name: 'Settings' })).toBeVisible();
  await expect(page.getByRole('heading', { name: 'Tags' })).toBeVisible();

  // The rail navigates between sections.
  const nav = page.getByRole('navigation', { name: 'Settings' });
  await nav.getByRole('link', { name: 'Import & export' }).click();
  await expect(page).toHaveURL(/\/settings\/import$/);
  await expect(page.getByRole('heading', { name: 'Import & export' })).toBeVisible();

  // The New-tag dialog still opens from the tags section (no network write).
  await nav.getByRole('link', { name: 'Tags' }).click();
  await page.getByRole('button', { name: 'New tag' }).click();
  const dialog = page.getByRole('dialog', { name: 'New tag' });
  await expect(dialog).toBeVisible();
  await dialog.getByRole('button', { name: 'Cancel' }).click();
  await expect(dialog).toBeHidden();

  // Pre-#180 admin urls redirect into the shell.
  await page.goto('/admin/users');
  await expect(page).toHaveURL(/\/settings\/admin\/users$/);
  await expect(page.getByRole('heading', { name: 'Users' })).toBeVisible();
  await expect(page.getByRole('group', { name: 'Filter by status' })).toBeVisible();

  // The catalog renders read-only rows and its category dialog opens (no write).
  await page.goto('/settings/admin/catalog');
  await expect(page.getByTestId('add-category')).toBeVisible();
  await page.getByTestId('add-category').click();
  const categoryDialog = page.getByRole('dialog', { name: 'New category' });
  await expect(categoryDialog).toBeVisible();
  await categoryDialog.getByRole('button', { name: 'Cancel' }).click();
  await expect(categoryDialog).toBeHidden();
});
```

- [ ] **Step 2: Run the smoke against the stack**

Run: `npm run e2e -- settings-admin-smoke.spec.ts`
Expected: PASS (or a clean skip if the seeded admin is missing — then run
`docker compose exec php bin/console app:e2e:seed-admin` from the repo root and
re-run to an actual PASS).

- [ ] **Step 3: Check the other smokes still pass**

Run: `npm run e2e`
Expected: PASS — `onboarding.spec.ts` talks to `/api/admin/*` directly (API,
not UI) and is unaffected; anything that navigates to `/settings` must still
work through the redirect.

- [ ] **Step 4: Commit**

```bash
git add e2e/settings-admin-smoke.spec.ts
git commit -m "test(e2e): cover settings shell navigation and admin relocation (#180)"
```

---

### Task 11: Full verification and PR

- [ ] **Step 1: Full frontend gate**

Run from `frontend/`: `npm run check`
Expected: ESLint, Prettier, Stylelint and Jest all clean. Fix anything it
reports before proceeding.

- [ ] **Step 2: Dead-reference sweep**

```bash
grep -rn "settings.component" src/ e2e/ || echo clean
grep -rn "routerLink=\"/admin/" src/ || echo clean
```

Expected: `clean` twice.

- [ ] **Step 3: Manual verification in the browser**

With the Docker stack up and `npm start` running, verify against
`https://localhost:8443`-backed dev server:

1. Desktop ≥ 900px: `/settings` forwards to `/settings/tags`; rail shows the
   five general sections plus the Admin group (as the seeded admin); active
   item highlights; catalog page is visibly wider than the tags page.
2. Narrow the window below 900px: rail disappears; `/settings` shows the hub;
   a section's back link leads to the hub, the hub's to the reader.
3. `/admin/catalog` (old bookmark) lands on `/settings/admin/catalog`.
4. Catalog: edit a feed via dialog, cancel a delete, confirm a delete;
   category dialog colour/icon pickers work.
5. Users: suspend asks for confirmation; cancel does nothing.
6. Non-admin account: no Admin group in rail or hub; deep-linking
   `/settings/admin/users` bounces to the reader.

- [ ] **Step 4: Backend log scan**

From the repo root: `docker compose exec php sh -c "tail -n 100 var/log/dev.log"`
— confirm no new errors/deprecations from the session's API traffic.

- [ ] **Step 5: Push and open the PR**

```bash
git push -u origin feature/180-settings-redesign
gh pr create --base develop --title "settings: redesign settings area (#180)" --body "$(cat <<'EOF'
Closes #180 (phase 1 + admin rework; phases 2–4 tracked separately).

## What
- /settings becomes a shell of lazy per-section routes: nav rail on desktop,
  hub-and-spoke on mobile (bp-lg switch), driven by a single section config.
- Admin pages move to /settings/admin/{users,catalog} (old urls redirect) and
  are fully reworked: users get design-language rows and two-step
  reject/suspend; the catalog's always-editable grid becomes read-only rows
  with edit dialogs.
- Spec: docs/superpowers/specs/2026-07-31-settings-redesign-design.md

## Tests
- Unit: section config, nav, hub redirect, shell back/wide logic, both
  dialogs, users confirm flow, route redirects.
- E2e: settings-admin-smoke rewritten for the new navigation.
- npm run check clean.
EOF
)"
```

Note: #180's remaining acceptance criteria (UserSettings entity, server-side
language, settings search) are phases 2–3 — after merging, comment on #180
that phase 1 + admin shipped and spin off follow-up issues rather than closing
it via this PR. Amend the PR body's "Closes #180" to "Part of #180" if the
issue should stay open — decide with Lars at review time.

---

## Self-review notes (already applied)

- **Spec coverage:** routing table → Tasks 1/5; config extensibility → Task 1/2;
  shell/rail/hub/back/breakpoint → Tasks 2–4; users rework → Task 6; catalog
  rework + dialogs → Tasks 7–9; deletions (old page, discover link via shell
  template, admin page chrome) → Tasks 4/5/6/9; test-id and e2e risk → Tasks
  9/10; i18n → Tasks 2/6/7/8/9; old-URL redirects → Tasks 5/10.
- **"Closes #180" tension:** the issue's acceptance criteria include phases
  2–3; the PR body step flags the Closes-vs-Part-of decision explicitly.
- **Type consistency:** `SettingsSection.wide`, `FeedFormData`,
  `confirmThenAct(user, 'reject' | 'suspend')`, `openCategoryDialog(category |
  null)`, `openFeedDialog(feed | null, categoryId)` are used with these exact
  signatures throughout.
- **Known verify-at-implementation points (deliberate):** `AdminApi` save/delete
  URL shapes in dialog specs; the danger-text colour token in dialog `.error`
  rules; the `admin.catalog` translation string in the e2e; the existing
  `admin-catalog` spec's helper names.
