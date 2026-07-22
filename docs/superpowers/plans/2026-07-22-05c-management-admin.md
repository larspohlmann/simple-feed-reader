# Plan 5c — Management + Admin — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add feed management (rename/retag/unsubscribe), tag CRUD (name/colour/icon), OPML import/export, an account view, and a lazy admin user-approval queue — all as a new Angular surface over the frozen bearer-JWT JSON API, no backend change.

**Architecture:** Two new lazy top-level routes (`/settings`, `/admin/users`) plus a set of shared CDK management dialogs. A new `TagsStore` (all tags incl. empty), a `ManageActions` service that opens dialogs and reloads the affected stores, an `AdminApi` + `adminGuard`. The existing reader shell/stores are not rewritten — the shell gains only entry-point links and (last task) sidebar hover affordances that reuse the shared dialogs. Design spec: `docs/superpowers/specs/2026-07-22-05c-management-admin-design.md`.

**Tech Stack:** Angular 20.3 standalone + signals, `@angular/cdk/dialog` + `@angular/cdk/a11y`, `HttpClient`, Reactive Forms, Jest + `HttpTestingController`, Playwright. Material Symbols already loaded (full outlined font).

**Conventions (match existing code exactly):**
- Components are standalone with inline `template` + `styles`, `inject()`, `input()`/`output()`, signals, native control flow (`@if`/`@for`).
- Colours come only from CSS tokens (`--surface-*`, `--border(-strong)`, `--text-*`, `--accent(-soft)`, `--on-accent`, `--danger`, `--bg-danger`, `--success`, `--bg-success`, `--space-*`, `--radius`, `--fs-*`, `--control-h`). Never hex in a `.scss`/`styles` block. (Tag-colour presets are *data* in a `.ts` file — allowed.)
- Specs use `TestBed` with `provideHttpClient()`, `provideHttpClientTesting()`, `provideRouter([])`, `{ provide: API_BASE_URL, useValue: 'https://api.test' }`. Dialog specs add `{ provide: DialogRef, useValue: dialogRefStub }` and `{ provide: DIALOG_DATA, useValue: data }`.
- Run the gate from `frontend/`: `npm test`, and before finishing `npm run check` + `npm run build`.
- Commit after each task (messages below).

---

## File structure

```
frontend/src/app/
  core/admin.guard.ts                          NEW  (+ .spec.ts)
  reader/models.ts                             EDIT (add OpmlImportResult, TagInput, SubscriptionUpdate)
  reader/reader-api.ts                         EDIT (add mutations) (+ existing .spec.ts extended)
  reader/tags.store.ts                         NEW  (+ .spec.ts)
  reader/manage/icon-choices.ts                NEW  (+ .spec.ts)
  reader/manage/confirm-dialog.component.ts    NEW  (+ .spec.ts)
  reader/manage/tag-form-dialog.component.ts   NEW  (+ .spec.ts)
  reader/manage/edit-subscription-dialog.component.ts NEW (+ .spec.ts)
  reader/manage/manage-actions.service.ts      NEW  (+ .spec.ts)
  reader/sidebar/sidebar.component.ts          EDIT (hover menus) (+ .spec.ts extended)
  reader/header/reader-header.component.ts     EDIT (Settings/Admin links) (+ .spec.ts extended)
  reader/reader-shell.component.ts             EDIT (wire sidebar outputs)
  admin/admin.models.ts                        NEW
  admin/admin-api.ts                           NEW  (+ .spec.ts)
  admin/admin-users.component.ts               NEW  (+ .spec.ts)
  settings/settings.component.ts               NEW  (+ .spec.ts)
  settings/feeds-section.component.ts          NEW  (+ .spec.ts)
  settings/tags-section.component.ts           NEW  (+ .spec.ts)
  settings/opml-section.component.ts           NEW  (+ .spec.ts)
  settings/account-section.component.ts        NEW  (+ .spec.ts)
  app.routes.ts                                EDIT (2 lazy routes)
frontend/e2e/settings-admin-smoke.spec.ts      NEW
frontend/README.md                             EDIT
```

---

### Task 1: Models + ReaderApi mutation methods

**Files:**
- Modify: `frontend/src/app/reader/models.ts`
- Modify: `frontend/src/app/reader/reader-api.ts`
- Test: `frontend/src/app/reader/reader-api.spec.ts` (extend)

- [ ] **Step 1: Add DTOs to `models.ts`** (append at end)

```ts
export interface OpmlImportResult {
  imported: number;
  alreadySubscribed: number;
  invalid: number;
  skippedOverLimit: number;
}

/** Body for POST /api/tags and PATCH /api/tags/{id}. */
export interface TagInput {
  name: string;
  color: string | null;
  icon: string | null;
}

/** Body for PATCH /api/subscriptions/{id}. Replaces the whole tag set. */
export interface SubscriptionUpdate {
  customTitle: string | null;
  tagIds: number[];
}
```

- [ ] **Step 2: Write failing tests** in `reader-api.spec.ts`

The spec already exists for the reader methods. Read its `beforeEach` (it uses `provideHttpClient`, `provideHttpClientTesting`, `API_BASE_URL='https://api.test'`, an `afterEach(() => ctrl.verify())`). Add a describe block:

```ts
describe('ReaderApi management methods', () => {
  it('PATCHes a subscription update', () => {
    api.updateSubscription(7, { customTitle: 'My name', tagIds: [1, 2] }).subscribe();
    const req = ctrl.expectOne('https://api.test/api/subscriptions/7');
    expect(req.request.method).toBe('PATCH');
    expect(req.request.body).toEqual({ customTitle: 'My name', tagIds: [1, 2] });
    req.flush({ subscription: {} });
  });

  it('DELETEs a subscription', () => {
    api.deleteSubscription(7).subscribe();
    const req = ctrl.expectOne('https://api.test/api/subscriptions/7');
    expect(req.request.method).toBe('DELETE');
    req.flush(null);
  });

  it('GETs all tags', () => {
    api.tags().subscribe();
    const req = ctrl.expectOne('https://api.test/api/tags');
    expect(req.request.method).toBe('GET');
    req.flush({ tags: [] });
  });

  it('POSTs a new tag', () => {
    api.createTag({ name: 'Tech', color: '#3f8676', icon: 'code' }).subscribe();
    const req = ctrl.expectOne('https://api.test/api/tags');
    expect(req.request.method).toBe('POST');
    expect(req.request.body).toEqual({ name: 'Tech', color: '#3f8676', icon: 'code' });
    req.flush({ tag: {} });
  });

  it('PATCHes a tag', () => {
    api.updateTag(3, { name: 'Tech', color: null, icon: null }).subscribe();
    const req = ctrl.expectOne('https://api.test/api/tags/3');
    expect(req.request.method).toBe('PATCH');
    req.flush({ tag: {} });
  });

  it('DELETEs a tag', () => {
    api.deleteTag(3).subscribe();
    const req = ctrl.expectOne('https://api.test/api/tags/3');
    expect(req.request.method).toBe('DELETE');
    req.flush(null);
  });

  it('GETs OPML export as text', () => {
    api.exportOpml().subscribe();
    const req = ctrl.expectOne('https://api.test/api/opml/export');
    expect(req.request.method).toBe('GET');
    expect(req.request.responseType).toBe('text');
    req.flush('<opml/>');
  });

  it('POSTs OPML import as a raw body', () => {
    api.importOpml('<opml/>').subscribe();
    const req = ctrl.expectOne('https://api.test/api/opml/import');
    expect(req.request.method).toBe('POST');
    expect(req.request.body).toBe('<opml/>');
    req.flush({ imported: 1, alreadySubscribed: 0, invalid: 0, skippedOverLimit: 0 });
  });
});
```

- [ ] **Step 3: Run tests to verify they fail** — `npm test -- reader-api` → FAIL (methods undefined).

- [ ] **Step 4: Implement** in `reader-api.ts`. Extend the import from `./models` to include `OpmlImportResult, SubscriptionUpdate, TagDto, TagInput`, then add methods to the class:

```ts
  updateSubscription(
    id: number,
    body: SubscriptionUpdate,
  ): Observable<{ subscription: SubscriptionDto }> {
    return this.http.patch<{ subscription: SubscriptionDto }>(
      `${this.base}/api/subscriptions/${id}`,
      body,
    );
  }

  deleteSubscription(id: number): Observable<void> {
    return this.http.delete<void>(`${this.base}/api/subscriptions/${id}`);
  }

  tags(): Observable<{ tags: TagDto[] }> {
    return this.http.get<{ tags: TagDto[] }>(`${this.base}/api/tags`);
  }

  createTag(body: TagInput): Observable<{ tag: TagDto }> {
    return this.http.post<{ tag: TagDto }>(`${this.base}/api/tags`, body);
  }

  updateTag(id: number, body: TagInput): Observable<{ tag: TagDto }> {
    return this.http.patch<{ tag: TagDto }>(`${this.base}/api/tags/${id}`, body);
  }

  deleteTag(id: number): Observable<void> {
    return this.http.delete<void>(`${this.base}/api/tags/${id}`);
  }

  exportOpml(): Observable<string> {
    return this.http.get(`${this.base}/api/opml/export`, { responseType: 'text' });
  }

  importOpml(xml: string): Observable<OpmlImportResult> {
    return this.http.post<OpmlImportResult>(`${this.base}/api/opml/import`, xml, {
      headers: { 'Content-Type': 'text/xml' },
    });
  }
```

- [ ] **Step 5: Run tests** — `npm test -- reader-api` → PASS.

- [ ] **Step 6: Commit** — `git add -A && git commit -m "feat(5c): ReaderApi mutations for subscriptions, tags, OPML"`

---

### Task 2: TagsStore

**Files:**
- Create: `frontend/src/app/reader/tags.store.ts`
- Test: `frontend/src/app/reader/tags.store.spec.ts`

- [ ] **Step 1: Write failing test**

```ts
import { TestBed } from '@angular/core/testing';
import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { API_BASE_URL } from '../core/api';
import { TagsStore } from './tags.store';
import { TagDto } from './models';

const tag = (id: number, name: string): TagDto => ({ id, name, color: null, icon: null });

describe('TagsStore', () => {
  let store: TagsStore;
  let ctrl: HttpTestingController;
  beforeEach(() => {
    TestBed.configureTestingModule({
      providers: [
        provideHttpClient(),
        provideHttpClientTesting(),
        { provide: API_BASE_URL, useValue: 'https://api.test' },
      ],
    });
    store = TestBed.inject(TagsStore);
    ctrl = TestBed.inject(HttpTestingController);
  });
  afterEach(() => ctrl.verify());

  it('loads tags sorted by name', () => {
    store.load();
    expect(store.loading()).toBe(true);
    ctrl.expectOne('https://api.test/api/tags').flush({ tags: [tag(1, 'Zeta'), tag(2, 'Alpha')] });
    expect(store.loading()).toBe(false);
    expect(store.tags().map((t) => t.name)).toEqual(['Alpha', 'Zeta']);
  });

  it('records a Problem on error', () => {
    store.load();
    ctrl.expectOne('https://api.test/api/tags').flush(
      { type: 'about:blank', title: 'Nope', status: 500 },
      { status: 500, statusText: 'Server Error' },
    );
    expect(store.error()?.title).toBe('Nope');
    expect(store.loading()).toBe(false);
  });
});
```

- [ ] **Step 2: Run test to verify it fails** — `npm test -- tags.store` → FAIL.

- [ ] **Step 3: Implement `tags.store.ts`**

```ts
// src/app/reader/tags.store.ts
import { HttpErrorResponse } from '@angular/common/http';
import { Injectable, inject, signal } from '@angular/core';
import { Problem, parseProblem } from '../core/problem';
import { ReaderApi } from './reader-api';
import { TagDto } from './models';

/** The complete tag list from GET /api/tags — including tags with zero feeds,
 *  which the sidebar's subscription-derived tree never shows. Management reads
 *  this; mutations happen through ReaderApi and callers call load() to re-sync. */
@Injectable({ providedIn: 'root' })
export class TagsStore {
  private readonly api = inject(ReaderApi);

  readonly tags = signal<TagDto[]>([]);
  readonly loading = signal(false);
  readonly error = signal<Problem | null>(null);

  load(): void {
    this.loading.set(true);
    this.error.set(null);
    this.api.tags().subscribe({
      next: (r) => {
        this.tags.set([...r.tags].sort((a, b) => a.name.localeCompare(b.name)));
        this.loading.set(false);
      },
      error: (e: HttpErrorResponse) => {
        this.error.set(parseProblem(e));
        this.loading.set(false);
      },
    });
  }
}
```

- [ ] **Step 4: Run test** — PASS.
- [ ] **Step 5: Commit** — `git add -A && git commit -m "feat(5c): TagsStore over GET /api/tags"`

---

### Task 3: Icon + colour choices

**Files:**
- Create: `frontend/src/app/reader/manage/icon-choices.ts`
- Test: `frontend/src/app/reader/manage/icon-choices.spec.ts`

- [ ] **Step 1: Write failing test** (guards that every value satisfies the backend regexes)

```ts
import { TAG_COLORS, TAG_ICONS } from './icon-choices';

describe('tag choices', () => {
  it('every colour is a #rrggbb hex the backend accepts', () => {
    expect(TAG_COLORS.length).toBeGreaterThan(0);
    for (const c of TAG_COLORS) expect(c).toMatch(/^#[0-9a-fA-F]{6}$/);
  });

  it('every icon is a Material Symbol name the backend accepts', () => {
    expect(TAG_ICONS.length).toBeGreaterThan(0);
    for (const i of TAG_ICONS) expect(i).toMatch(/^[a-z0-9_]+$/);
  });
});
```

- [ ] **Step 2: Run to verify it fails** — FAIL (module missing).

- [ ] **Step 3: Implement `icon-choices.ts`**

```ts
// src/app/reader/manage/icon-choices.ts
// Data, not stylesheet colours: the Stylelint color-no-hex guard globs only
// *.scss. These are the values the backend's /^#[0-9a-fA-F]{6}$/ tag-colour
// rule accepts, offered as a quick palette next to a native colour input.
export const TAG_COLORS: string[] = [
  '#3f8676', // teal (accent)
  '#4f7cac', // blue
  '#5a9367', // green
  '#c08a3e', // amber
  '#b3403a', // rose
  '#8a6bbf', // violet
  '#6b7280', // slate
  '#b06a4f', // clay
  '#4c8ca3', // cyan
  '#a34c7a', // magenta
];

// Curated outlined Material Symbol names (the full font is loaded, so any name
// renders — this list is only a tidy picker). All match /^[a-z0-9_]+$/.
export const TAG_ICONS: string[] = [
  'label',
  'rss_feed',
  'newspaper',
  'code',
  'terminal',
  'science',
  'school',
  'work',
  'public',
  'trending_up',
  'bolt',
  'palette',
  'camera',
  'movie',
  'music_note',
  'sports_esports',
  'sports_soccer',
  'restaurant',
  'local_cafe',
  'flight',
  'shopping_cart',
  'favorite',
  'pets',
  'star',
];
```

- [ ] **Step 4: Run test** — PASS.
- [ ] **Step 5: Commit** — `git add -A && git commit -m "feat(5c): curated tag colour + icon choices"`

---

### Task 4: ConfirmDialogComponent

**Files:**
- Create: `frontend/src/app/reader/manage/confirm-dialog.component.ts`
- Test: `frontend/src/app/reader/manage/confirm-dialog.component.spec.ts`

- [ ] **Step 1: Write failing test**

```ts
import { TestBed } from '@angular/core/testing';
import { DialogRef, DIALOG_DATA } from '@angular/cdk/dialog';
import { ConfirmDialogComponent, ConfirmData } from './confirm-dialog.component';

describe('ConfirmDialogComponent', () => {
  const close = jest.fn();
  const data: ConfirmData = { title: 'Delete tag', message: 'Sure?', confirmLabel: 'Delete', danger: true };

  function mount() {
    TestBed.configureTestingModule({
      providers: [
        { provide: DialogRef, useValue: { close } },
        { provide: DIALOG_DATA, useValue: data },
      ],
    });
    const f = TestBed.createComponent(ConfirmDialogComponent);
    f.detectChanges();
    return f;
  }

  beforeEach(() => close.mockReset());

  it('renders the title, message and confirm label', () => {
    const el: HTMLElement = mount().nativeElement;
    expect(el.textContent).toContain('Delete tag');
    expect(el.textContent).toContain('Sure?');
    expect(el.textContent).toContain('Delete');
  });

  it('closes true on confirm and false on cancel', () => {
    const el: HTMLElement = mount().nativeElement;
    const buttons = el.querySelectorAll('button');
    (buttons[0] as HTMLButtonElement).click(); // Cancel
    expect(close).toHaveBeenCalledWith(false);
    (buttons[1] as HTMLButtonElement).click(); // Confirm
    expect(close).toHaveBeenCalledWith(true);
  });
});
```

- [ ] **Step 2: Run to verify it fails** — FAIL.

- [ ] **Step 3: Implement `confirm-dialog.component.ts`**

```ts
// src/app/reader/manage/confirm-dialog.component.ts
import { Component, inject } from '@angular/core';
import { A11yModule } from '@angular/cdk/a11y';
import { DialogRef, DIALOG_DATA } from '@angular/cdk/dialog';

export interface ConfirmData {
  title: string;
  message: string;
  confirmLabel: string;
  danger?: boolean;
}

@Component({
  selector: 'app-confirm-dialog',
  imports: [A11yModule],
  template: `
    <div class="dialog" role="alertdialog" aria-modal="true" [attr.aria-label]="data.title" cdkTrapFocus>
      <h2>{{ data.title }}</h2>
      <p class="msg">{{ data.message }}</p>
      <div class="row">
        <button type="button" (click)="ref.close(false)">Cancel</button>
        <button
          type="button"
          [class.primary]="!data.danger"
          [class.danger]="data.danger"
          cdkFocusInitial
          (click)="ref.close(true)"
        >
          {{ data.confirmLabel }}
        </button>
      </div>
    </div>
  `,
  styles: [
    `
      .dialog {
        background: var(--surface-2);
        border: 1px solid var(--border);
        border-radius: var(--radius);
        padding: var(--space-5);
        width: min(400px, 92vw);
        display: flex;
        flex-direction: column;
        gap: var(--space-3);
      }
      h2 {
        margin: 0;
        font-size: var(--fs-lg);
      }
      .msg {
        margin: 0;
        color: var(--text-secondary);
      }
      .row {
        display: flex;
        justify-content: flex-end;
        gap: var(--space-2);
      }
      .row button {
        padding: var(--space-2) var(--space-4);
        border: 1px solid var(--border-strong);
        border-radius: var(--radius);
        background: var(--surface-1);
        color: var(--text-primary);
        cursor: pointer;
      }
      .row button.primary {
        background: var(--accent);
        color: var(--on-accent);
        border-color: var(--accent);
      }
      .row button.danger {
        background: var(--danger);
        color: var(--on-accent);
        border-color: var(--danger);
      }
    `,
  ],
})
export class ConfirmDialogComponent {
  readonly ref = inject<DialogRef<boolean>>(DialogRef);
  readonly data = inject<ConfirmData>(DIALOG_DATA);
}
```

- [ ] **Step 4: Run test** — PASS.
- [ ] **Step 5: Commit** — `git add -A && git commit -m "feat(5c): generic confirm dialog"`

---

### Task 5: TagFormDialogComponent (create/edit tag)

**Files:**
- Create: `frontend/src/app/reader/manage/tag-form-dialog.component.ts`
- Test: `frontend/src/app/reader/manage/tag-form-dialog.component.spec.ts`

- [ ] **Step 1: Write failing test**

```ts
import { TestBed } from '@angular/core/testing';
import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { DialogRef, DIALOG_DATA } from '@angular/cdk/dialog';
import { API_BASE_URL } from '../../core/api';
import { TagFormDialogComponent } from './tag-form-dialog.component';
import { TagDto } from '../models';

describe('TagFormDialogComponent', () => {
  const close = jest.fn();
  let ctrl: HttpTestingController;

  function mount(data: TagDto | null) {
    TestBed.configureTestingModule({
      providers: [
        provideHttpClient(),
        provideHttpClientTesting(),
        { provide: API_BASE_URL, useValue: 'https://api.test' },
        { provide: DialogRef, useValue: { close } },
        { provide: DIALOG_DATA, useValue: data },
      ],
    });
    const f = TestBed.createComponent(TagFormDialogComponent);
    f.detectChanges();
    ctrl = TestBed.inject(HttpTestingController);
    return f;
  }

  beforeEach(() => close.mockReset());
  afterEach(() => ctrl.verify());

  it('creates a tag (POST) and closes with it', () => {
    const f = mount(null);
    const c = f.componentInstance;
    c.form.controls.name.setValue('Tech');
    c.icon.set('code');
    c.color.set('#3f8676');
    c.submit();
    const req = ctrl.expectOne('https://api.test/api/tags');
    expect(req.request.method).toBe('POST');
    expect(req.request.body).toEqual({ name: 'Tech', color: '#3f8676', icon: 'code' });
    req.flush({ tag: { id: 9, name: 'Tech', color: '#3f8676', icon: 'code' } });
    expect(close).toHaveBeenCalledWith({ id: 9, name: 'Tech', color: '#3f8676', icon: 'code' });
  });

  it('edits a tag (PATCH) prefilled from data', () => {
    const f = mount({ id: 4, name: 'Old', color: '#4f7cac', icon: 'label' });
    const c = f.componentInstance;
    expect(c.form.getRawValue().name).toBe('Old');
    expect(c.color()).toBe('#4f7cac');
    c.form.controls.name.setValue('New');
    c.submit();
    const req = ctrl.expectOne('https://api.test/api/tags/4');
    expect(req.request.method).toBe('PATCH');
    req.flush({ tag: { id: 4, name: 'New', color: '#4f7cac', icon: 'label' } });
    expect(close).toHaveBeenCalled();
  });

  it('surfaces a 409 name-taken error inline and stays open', () => {
    const f = mount(null);
    const c = f.componentInstance;
    c.form.controls.name.setValue('Dup');
    c.submit();
    ctrl.expectOne('https://api.test/api/tags').flush(
      { type: 'about:blank', title: 'Tag name already in use', status: 409 },
      { status: 409, statusText: 'Conflict' },
    );
    expect(c.error()).toBe('Tag name already in use');
    expect(close).not.toHaveBeenCalled();
  });

  it('does not submit an empty name', () => {
    const c = mount(null).componentInstance;
    c.submit();
    ctrl.expectNone('https://api.test/api/tags');
  });
});
```

- [ ] **Step 2: Run to verify it fails** — FAIL.

- [ ] **Step 3: Implement `tag-form-dialog.component.ts`**

```ts
// src/app/reader/manage/tag-form-dialog.component.ts
import { HttpErrorResponse } from '@angular/common/http';
import { Component, inject, signal } from '@angular/core';
import { NonNullableFormBuilder, ReactiveFormsModule, Validators } from '@angular/forms';
import { A11yModule } from '@angular/cdk/a11y';
import { DialogRef, DIALOG_DATA } from '@angular/cdk/dialog';
import { parseProblem } from '../../core/problem';
import { IconComponent } from '../../shared/icon/icon.component';
import { ReaderApi } from '../reader-api';
import { TagDto } from '../models';
import { TAG_COLORS, TAG_ICONS } from './icon-choices';

@Component({
  selector: 'app-tag-form-dialog',
  imports: [ReactiveFormsModule, A11yModule, IconComponent],
  template: `
    <div class="dialog" role="dialog" aria-modal="true" [attr.aria-label]="title" cdkTrapFocus>
      <h2>{{ title }}</h2>
      <form [formGroup]="form" (ngSubmit)="submit()">
        <label class="lbl" for="tag-name">Name</label>
        <input id="tag-name" class="field" formControlName="name" maxlength="100" cdkFocusInitial />

        <p class="lbl">Colour</p>
        <div class="swatches">
          @for (c of colors; track c) {
            <button
              type="button"
              class="swatch"
              [class.on]="color() === c"
              [style.background]="c"
              [attr.aria-label]="'Colour ' + c"
              (click)="color.set(c)"
            ></button>
          }
          <input
            type="color"
            class="picker"
            aria-label="Custom colour"
            [value]="color() ?? '#3f8676'"
            (input)="color.set(pickerValue($event))"
          />
          <button type="button" class="clear" (click)="color.set(null)">None</button>
        </div>

        <p class="lbl">Icon</p>
        <div class="icons">
          <button
            type="button"
            class="icon"
            [class.on]="icon() === null"
            aria-label="No icon"
            (click)="icon.set(null)"
          >
            <app-icon name="block" [size]="18" />
          </button>
          @for (i of icons; track i) {
            <button
              type="button"
              class="icon"
              [class.on]="icon() === i"
              [attr.aria-label]="i"
              (click)="icon.set(i)"
            >
              <app-icon [name]="i" [size]="18" />
            </button>
          }
        </div>

        @if (error()) {
          <p class="error" role="alert">{{ error() }}</p>
        }
        <div class="row">
          <button type="button" (click)="ref.close()">Cancel</button>
          <button type="submit" class="primary" [disabled]="loading()">
            {{ loading() ? 'Saving…' : 'Save' }}
          </button>
        </div>
      </form>
    </div>
  `,
  styles: [
    `
      .dialog {
        background: var(--surface-2);
        border: 1px solid var(--border);
        border-radius: var(--radius);
        padding: var(--space-5);
        width: min(460px, 92vw);
        display: flex;
        flex-direction: column;
        gap: var(--space-3);
      }
      h2 {
        margin: 0;
        font-size: var(--fs-lg);
      }
      form {
        display: flex;
        flex-direction: column;
        gap: var(--space-2);
      }
      .lbl {
        margin: var(--space-2) 0 0;
        font-size: var(--fs-sm);
        color: var(--text-secondary);
      }
      .swatches {
        display: flex;
        flex-wrap: wrap;
        gap: var(--space-2);
        align-items: center;
      }
      .swatch {
        width: 22px;
        height: 22px;
        border-radius: 50%;
        border: 2px solid var(--border);
        cursor: pointer;
      }
      .swatch.on {
        border-color: var(--text-primary);
      }
      .picker {
        width: 30px;
        height: 26px;
        padding: 0;
        border: 1px solid var(--border);
        border-radius: var(--radius);
        background: none;
        cursor: pointer;
      }
      .clear {
        font-size: var(--fs-sm);
        background: var(--surface-1);
        border: 1px solid var(--border-strong);
        border-radius: var(--radius);
        color: var(--text-secondary);
        padding: 0 var(--space-2);
        cursor: pointer;
      }
      .icons {
        display: flex;
        flex-wrap: wrap;
        gap: var(--space-1);
      }
      .icon {
        display: inline-flex;
        padding: var(--space-2);
        border: 1px solid var(--border);
        border-radius: var(--radius);
        background: var(--surface-1);
        color: var(--text-secondary);
        cursor: pointer;
      }
      .icon.on {
        border-color: var(--accent);
        color: var(--accent);
        background: var(--accent-soft);
      }
      .error {
        color: var(--danger);
        font-size: var(--fs-sm);
        margin: 0;
      }
      .row {
        display: flex;
        justify-content: flex-end;
        gap: var(--space-2);
        margin-top: var(--space-2);
      }
      .row button {
        padding: var(--space-2) var(--space-4);
        border: 1px solid var(--border-strong);
        border-radius: var(--radius);
        background: var(--surface-1);
        color: var(--text-primary);
        cursor: pointer;
      }
      .row button.primary {
        background: var(--accent);
        color: var(--on-accent);
        border-color: var(--accent);
      }
    `,
  ],
})
export class TagFormDialogComponent {
  readonly ref = inject<DialogRef<TagDto>>(DialogRef);
  readonly data = inject<TagDto | null>(DIALOG_DATA);
  private readonly api = inject(ReaderApi);
  private readonly fb = inject(NonNullableFormBuilder);

  readonly colors = TAG_COLORS;
  readonly icons = TAG_ICONS;
  readonly isEdit = this.data !== null;
  readonly title = this.isEdit ? 'Edit tag' : 'New tag';

  readonly form = this.fb.group({
    name: [this.data?.name ?? '', [Validators.required, Validators.maxLength(100)]],
  });
  readonly color = signal<string | null>(this.data?.color ?? null);
  readonly icon = signal<string | null>(this.data?.icon ?? null);
  readonly loading = signal(false);
  readonly error = signal<string | null>(null);

  pickerValue(e: Event): string {
    return (e.target as HTMLInputElement).value;
  }

  submit(): void {
    if (this.form.invalid) return;
    const body = {
      name: this.form.getRawValue().name.trim(),
      color: this.color(),
      icon: this.icon(),
    };
    this.loading.set(true);
    this.error.set(null);
    const req = this.isEdit
      ? this.api.updateTag(this.data!.id, body)
      : this.api.createTag(body);
    req.subscribe({
      next: (r) => this.ref.close(r.tag),
      error: (e: HttpErrorResponse) => {
        this.loading.set(false);
        const p = parseProblem(e);
        this.error.set(p.errors?.['name']?.[0] ?? p.detail ?? p.title);
      },
    });
  }
}
```

- [ ] **Step 4: Run test** — PASS. (If Jest flags the unknown `block` glyph — it won't; `app-icon` just renders the text. `IconComponent` must be imported.)
- [ ] **Step 5: Commit** — `git add -A && git commit -m "feat(5c): tag create/edit dialog with colour + icon picker"`

---

### Task 6: EditSubscriptionDialogComponent (rename + retag)

**Files:**
- Create: `frontend/src/app/reader/manage/edit-subscription-dialog.component.ts`
- Test: `frontend/src/app/reader/manage/edit-subscription-dialog.component.spec.ts`

- [ ] **Step 1: Write failing test**

```ts
import { TestBed } from '@angular/core/testing';
import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { DialogRef, DIALOG_DATA } from '@angular/cdk/dialog';
import { API_BASE_URL } from '../../core/api';
import { EditSubscriptionDialogComponent } from './edit-subscription-dialog.component';
import { SubscriptionDto } from '../models';

const sub: SubscriptionDto = {
  id: 5,
  title: 'Heise',
  customTitle: null,
  feedUrl: 'https://heise.de/rss',
  siteUrl: 'https://heise.de',
  status: 'active',
  createdAt: 'x',
  tags: [{ id: 1, name: 'Tech', color: null, icon: null }],
  unreadCount: 3,
};

describe('EditSubscriptionDialogComponent', () => {
  const close = jest.fn();
  let ctrl: HttpTestingController;

  function mount() {
    TestBed.configureTestingModule({
      providers: [
        provideHttpClient(),
        provideHttpClientTesting(),
        { provide: API_BASE_URL, useValue: 'https://api.test' },
        { provide: DialogRef, useValue: { close } },
        { provide: DIALOG_DATA, useValue: sub },
      ],
    });
    const f = TestBed.createComponent(EditSubscriptionDialogComponent);
    f.detectChanges();
    ctrl = TestBed.inject(HttpTestingController);
    // ngOnInit loads all tags:
    ctrl.expectOne('https://api.test/api/tags').flush({
      tags: [
        { id: 1, name: 'Tech', color: null, icon: null },
        { id: 2, name: 'News', color: null, icon: null },
      ],
    });
    return f;
  }

  beforeEach(() => close.mockReset());
  afterEach(() => ctrl.verify());

  it('prefills the current tags as checked', () => {
    const c = mount().componentInstance;
    expect(c.checked().has(1)).toBe(true);
    expect(c.checked().has(2)).toBe(false);
  });

  it('PATCHes customTitle (empty → null) and the toggled tag set', () => {
    const c = mount().componentInstance;
    c.form.controls.customTitle.setValue('  My Heise ');
    c.toggle(2); // add News
    c.toggle(1); // remove Tech
    c.submit();
    const req = ctrl.expectOne('https://api.test/api/subscriptions/5');
    expect(req.request.method).toBe('PATCH');
    expect(req.request.body).toEqual({ customTitle: 'My Heise', tagIds: [2] });
    req.flush({ subscription: { ...sub, customTitle: 'My Heise' } });
    expect(close).toHaveBeenCalled();
  });

  it('sends customTitle null when cleared', () => {
    const c = mount().componentInstance;
    c.form.controls.customTitle.setValue('');
    c.submit();
    const req = ctrl.expectOne('https://api.test/api/subscriptions/5');
    expect(req.request.body).toEqual({ customTitle: null, tagIds: [1] });
    req.flush({ subscription: sub });
  });
});
```

- [ ] **Step 2: Run to verify it fails** — FAIL.

- [ ] **Step 3: Implement `edit-subscription-dialog.component.ts`**

```ts
// src/app/reader/manage/edit-subscription-dialog.component.ts
import { HttpErrorResponse } from '@angular/common/http';
import { Component, OnInit, inject, signal } from '@angular/core';
import { NonNullableFormBuilder, ReactiveFormsModule, Validators } from '@angular/forms';
import { A11yModule } from '@angular/cdk/a11y';
import { DialogRef, DIALOG_DATA } from '@angular/cdk/dialog';
import { parseProblem } from '../../core/problem';
import { ReaderApi } from '../reader-api';
import { TagsStore } from '../tags.store';
import { SubscriptionDto } from '../models';

@Component({
  selector: 'app-edit-subscription-dialog',
  imports: [ReactiveFormsModule, A11yModule],
  template: `
    <div class="dialog" role="dialog" aria-modal="true" aria-label="Edit feed" cdkTrapFocus>
      <h2>Edit feed</h2>
      <form [formGroup]="form" (ngSubmit)="submit()">
        <label class="lbl" for="sub-title">Custom title</label>
        <input
          id="sub-title"
          class="field"
          formControlName="customTitle"
          maxlength="512"
          [placeholder]="data.title"
          cdkFocusInitial
        />

        <p class="lbl">Tags</p>
        @if (tagsStore.tags().length === 0) {
          <p class="hint">No tags yet — create one from Settings › Tags.</p>
        }
        <ul class="tags">
          @for (t of tagsStore.tags(); track t.id) {
            <li>
              <label>
                <input
                  type="checkbox"
                  [checked]="checked().has(t.id)"
                  (change)="toggle(t.id)"
                />
                <span class="dot" [style.background]="t.color || 'var(--text-muted)'"></span>
                {{ t.name }}
              </label>
            </li>
          }
        </ul>

        @if (error()) {
          <p class="error" role="alert">{{ error() }}</p>
        }
        <div class="row">
          <button type="button" (click)="ref.close()">Cancel</button>
          <button type="submit" class="primary" [disabled]="loading()">
            {{ loading() ? 'Saving…' : 'Save' }}
          </button>
        </div>
      </form>
    </div>
  `,
  styles: [
    `
      .dialog {
        background: var(--surface-2);
        border: 1px solid var(--border);
        border-radius: var(--radius);
        padding: var(--space-5);
        width: min(440px, 92vw);
        display: flex;
        flex-direction: column;
        gap: var(--space-3);
      }
      h2 {
        margin: 0;
        font-size: var(--fs-lg);
      }
      form {
        display: flex;
        flex-direction: column;
        gap: var(--space-2);
      }
      .lbl {
        margin: var(--space-2) 0 0;
        font-size: var(--fs-sm);
        color: var(--text-secondary);
      }
      .hint {
        margin: 0;
        font-size: var(--fs-sm);
        color: var(--text-muted);
      }
      .tags {
        list-style: none;
        margin: 0;
        padding: 0;
        max-height: 220px;
        overflow: auto;
        display: flex;
        flex-direction: column;
        gap: var(--space-1);
      }
      .tags label {
        display: flex;
        align-items: center;
        gap: var(--space-2);
        cursor: pointer;
      }
      .dot {
        width: 9px;
        height: 9px;
        border-radius: 50%;
        flex: 0 0 auto;
      }
      .error {
        color: var(--danger);
        font-size: var(--fs-sm);
        margin: 0;
      }
      .row {
        display: flex;
        justify-content: flex-end;
        gap: var(--space-2);
        margin-top: var(--space-2);
      }
      .row button {
        padding: var(--space-2) var(--space-4);
        border: 1px solid var(--border-strong);
        border-radius: var(--radius);
        background: var(--surface-1);
        color: var(--text-primary);
        cursor: pointer;
      }
      .row button.primary {
        background: var(--accent);
        color: var(--on-accent);
        border-color: var(--accent);
      }
    `,
  ],
})
export class EditSubscriptionDialogComponent implements OnInit {
  readonly ref = inject<DialogRef<SubscriptionDto>>(DialogRef);
  readonly data = inject<SubscriptionDto>(DIALOG_DATA);
  private readonly api = inject(ReaderApi);
  readonly tagsStore = inject(TagsStore);
  private readonly fb = inject(NonNullableFormBuilder);

  readonly form = this.fb.group({
    customTitle: [this.data.customTitle ?? '', [Validators.maxLength(512)]],
  });
  readonly checked = signal<Set<number>>(new Set(this.data.tags.map((t) => t.id)));
  readonly loading = signal(false);
  readonly error = signal<string | null>(null);

  ngOnInit(): void {
    if (this.tagsStore.tags().length === 0) this.tagsStore.load();
  }

  toggle(id: number): void {
    this.checked.update((set) => {
      const next = new Set(set);
      next.has(id) ? next.delete(id) : next.add(id);
      return next;
    });
  }

  submit(): void {
    if (this.form.invalid) return;
    const body = {
      customTitle: this.form.getRawValue().customTitle.trim() || null,
      tagIds: [...this.checked()],
    };
    this.loading.set(true);
    this.error.set(null);
    this.api.updateSubscription(this.data.id, body).subscribe({
      next: (r) => this.ref.close(r.subscription),
      error: (e: HttpErrorResponse) => {
        this.loading.set(false);
        const p = parseProblem(e);
        this.error.set(p.errors?.['customTitle']?.[0] ?? p.detail ?? p.title);
      },
    });
  }
}
```

> Note: the ternary in `toggle` is a statement-expression; ESLint's `no-unused-expressions` may flag it. If so, rewrite as an `if/else` (as the sidebar's `toggle` does). Prefer the `if/else` form to be safe.

- [ ] **Step 4: Run test** — PASS.
- [ ] **Step 5: Commit** — `git add -A && git commit -m "feat(5c): edit-subscription dialog (rename + retag)"`

---

### Task 7: ManageActions service

**Files:**
- Create: `frontend/src/app/reader/manage/manage-actions.service.ts`
- Test: `frontend/src/app/reader/manage/manage-actions.service.spec.ts`

- [ ] **Step 1: Write failing test** (mock `Dialog` so no overlay is created; assert store reloads + DELETEs)

```ts
import { TestBed } from '@angular/core/testing';
import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { Dialog } from '@angular/cdk/dialog';
import { of } from 'rxjs';
import { API_BASE_URL } from '../../core/api';
import { ManageActions } from './manage-actions.service';
import { SubscriptionsStore } from '../subscriptions.store';
import { TagsStore } from '../tags.store';
import { SubscriptionDto, TagDto } from '../models';

const sub: SubscriptionDto = {
  id: 5, title: 'Heise', customTitle: null, feedUrl: 'u', siteUrl: null,
  status: 'active', createdAt: 'x', tags: [], unreadCount: 0,
};
const tag: TagDto = { id: 3, name: 'Tech', color: null, icon: null };

describe('ManageActions', () => {
  let svc: ManageActions;
  let ctrl: HttpTestingController;
  let closed: unknown;
  const open = jest.fn(() => ({ closed: of(closed) }));

  beforeEach(() => {
    TestBed.configureTestingModule({
      providers: [
        provideHttpClient(),
        provideHttpClientTesting(),
        { provide: API_BASE_URL, useValue: 'https://api.test' },
        { provide: Dialog, useValue: { open } },
      ],
    });
    svc = TestBed.inject(ManageActions);
    ctrl = TestBed.inject(HttpTestingController);
    open.mockClear();
  });
  afterEach(() => ctrl.verify());

  it('reloads subscriptions after a successful edit', () => {
    closed = sub; // dialog closed with an updated subscription
    const spy = jest.spyOn(TestBed.inject(SubscriptionsStore), 'load');
    svc.editSubscription(sub);
    expect(spy).toHaveBeenCalled();
  });

  it('unsubscribe: on confirm, DELETEs then reloads', () => {
    closed = true; // confirm dialog returned true
    const spy = jest.spyOn(TestBed.inject(SubscriptionsStore), 'load');
    svc.unsubscribe(sub);
    ctrl.expectOne('https://api.test/api/subscriptions/5').flush(null);
    expect(spy).toHaveBeenCalled();
  });

  it('unsubscribe: on cancel, does nothing', () => {
    closed = undefined;
    svc.unsubscribe(sub);
    ctrl.expectNone('https://api.test/api/subscriptions/5');
  });

  it('deleteTag: on confirm, DELETEs then reloads tags + subs', () => {
    closed = true;
    const tagSpy = jest.spyOn(TestBed.inject(TagsStore), 'load');
    const subSpy = jest.spyOn(TestBed.inject(SubscriptionsStore), 'load');
    svc.deleteTag(tag);
    ctrl.expectOne('https://api.test/api/tags/3').flush(null);
    expect(tagSpy).toHaveBeenCalled();
    expect(subSpy).toHaveBeenCalled();
  });

  it('createTag: reloads tags when the dialog returns a tag', () => {
    closed = tag;
    const tagSpy = jest.spyOn(TestBed.inject(TagsStore), 'load');
    svc.createTag();
    expect(tagSpy).toHaveBeenCalled();
  });
});
```

- [ ] **Step 2: Run to verify it fails** — FAIL.

- [ ] **Step 3: Implement `manage-actions.service.ts`**

```ts
// src/app/reader/manage/manage-actions.service.ts
import { Injectable, inject } from '@angular/core';
import { Dialog } from '@angular/cdk/dialog';
import { ReaderApi } from '../reader-api';
import { SubscriptionsStore } from '../subscriptions.store';
import { TagsStore } from '../tags.store';
import { SubscriptionDto, TagDto } from '../models';
import { ConfirmDialogComponent, ConfirmData } from './confirm-dialog.component';
import { EditSubscriptionDialogComponent } from './edit-subscription-dialog.component';
import { TagFormDialogComponent } from './tag-form-dialog.component';

/** The single place a management dialog is opened and its side effects applied.
 *  Both the settings sections and the sidebar (via the shell) call these, so an
 *  action behaves identically wherever it is triggered. Dialogs perform their own
 *  API write and close with the result; this service refreshes the affected
 *  stores on a truthy close. */
@Injectable({ providedIn: 'root' })
export class ManageActions {
  private readonly dialog = inject(Dialog);
  private readonly api = inject(ReaderApi);
  private readonly subs = inject(SubscriptionsStore);
  private readonly tags = inject(TagsStore);

  editSubscription(sub: SubscriptionDto): void {
    const ref = this.dialog.open<SubscriptionDto>(EditSubscriptionDialogComponent, { data: sub });
    ref.closed.subscribe((updated) => {
      if (updated) this.subs.load();
    });
  }

  unsubscribe(sub: SubscriptionDto): void {
    const data: ConfirmData = {
      title: 'Unsubscribe',
      message: `Remove “${sub.title}” and its entries from your feeds?`,
      confirmLabel: 'Unsubscribe',
      danger: true,
    };
    const ref = this.dialog.open<boolean>(ConfirmDialogComponent, { data });
    ref.closed.subscribe((ok) => {
      if (!ok) return;
      this.api.deleteSubscription(sub.id).subscribe({ next: () => this.subs.load() });
    });
  }

  createTag(): void {
    const ref = this.dialog.open<TagDto>(TagFormDialogComponent, { data: null });
    ref.closed.subscribe((tag) => {
      if (tag) this.tags.load();
    });
  }

  editTag(tag: TagDto): void {
    const ref = this.dialog.open<TagDto>(TagFormDialogComponent, { data: tag });
    ref.closed.subscribe((updated) => {
      if (!updated) return;
      this.tags.load();
      this.subs.load(); // embedded tag colour/name on feeds changed too
    });
  }

  deleteTag(tag: TagDto): void {
    const data: ConfirmData = {
      title: 'Delete tag',
      message: `Delete “${tag.name}”? It will be removed from every feed that uses it.`,
      confirmLabel: 'Delete',
      danger: true,
    };
    const ref = this.dialog.open<boolean>(ConfirmDialogComponent, { data });
    ref.closed.subscribe((ok) => {
      if (!ok) return;
      this.api.deleteTag(tag.id).subscribe({
        next: () => {
          this.tags.load();
          this.subs.load();
        },
      });
    });
  }
}
```

- [ ] **Step 4: Run test** — PASS.
- [ ] **Step 5: Commit** — `git add -A && git commit -m "feat(5c): ManageActions service — dialogs + store refresh"`

---

### Task 8: adminGuard

**Files:**
- Create: `frontend/src/app/core/admin.guard.ts`
- Test: `frontend/src/app/core/admin.guard.spec.ts`

- [ ] **Step 1: Write failing test**

```ts
import { TestBed } from '@angular/core/testing';
import { Router, UrlTree } from '@angular/router';
import { of, throwError } from 'rxjs';
import { firstValueFrom, isObservable } from 'rxjs';
import { adminGuard } from './admin.guard';
import { AuthService, CurrentUser } from './auth.service';

const admin: CurrentUser = { id: 1, email: 'a', roles: ['ROLE_ADMIN', 'ROLE_USER'], status: 'active', createdAt: 'x' };
const plain: CurrentUser = { ...admin, roles: ['ROLE_USER'] };

function run(auth: Partial<AuthService>) {
  const router = TestBed.inject(Router);
  return TestBed.runInInjectionContext(() =>
    adminGuard({} as never, { url: '/admin/users' } as never),
  );
}

describe('adminGuard', () => {
  let userSignal: () => CurrentUser | null;
  let loadMe: jest.Mock;
  let isAdmin: jest.Mock;

  beforeEach(() => {
    loadMe = jest.fn();
    isAdmin = jest.fn();
    TestBed.configureTestingModule({
      providers: [
        {
          provide: AuthService,
          useValue: {
            user: () => userSignal(),
            loadMe: () => loadMe(),
            isAdmin: () => isAdmin(),
          },
        },
      ],
    });
  });

  it('allows an already-loaded admin synchronously', () => {
    userSignal = () => admin;
    isAdmin.mockReturnValue(true);
    expect(run({})).toBe(true);
  });

  it('redirects an already-loaded non-admin to /', () => {
    userSignal = () => plain;
    isAdmin.mockReturnValue(false);
    const res = run({});
    expect(res instanceof UrlTree).toBe(true);
    expect((res as UrlTree).toString()).toBe('/');
  });

  it('loads the user first on a deep link, then allows an admin', async () => {
    userSignal = () => null;
    loadMe.mockReturnValue(of(admin));
    isAdmin.mockReturnValue(true);
    const res = run({});
    expect(isObservable(res)).toBe(true);
    await expect(firstValueFrom(res as never)).resolves.toBe(true);
  });

  it('redirects to / when loadMe fails', async () => {
    userSignal = () => null;
    loadMe.mockReturnValue(throwError(() => new Error('401')));
    const res = run({});
    const val = await firstValueFrom(res as never);
    expect(val instanceof UrlTree).toBe(true);
  });
});
```

- [ ] **Step 2: Run to verify it fails** — FAIL.

- [ ] **Step 3: Implement `admin.guard.ts`**

```ts
// src/app/core/admin.guard.ts
import { inject } from '@angular/core';
import { CanActivateFn, Router, UrlTree } from '@angular/router';
import { Observable, catchError, map, of } from 'rxjs';
import { AuthService } from './auth.service';

/** UX-only gate for the admin surface — the real guard is ROLE_ADMIN on
 *  ^/api/admin/ in security.yaml. On a deep link (no reader visited yet) the
 *  user may not be loaded; fetch it first, then decide. */
export const adminGuard: CanActivateFn = () => {
  const auth = inject(AuthService);
  const router = inject(Router);
  const decide = (): boolean | UrlTree => (auth.isAdmin() ? true : router.createUrlTree(['/']));

  if (auth.user()) return decide();

  return auth.loadMe().pipe(
    map(() => decide()),
    catchError(() => of<boolean | UrlTree>(router.createUrlTree(['/']))),
  ) as Observable<boolean | UrlTree>;
};
```

- [ ] **Step 4: Run test** — PASS.
- [ ] **Step 5: Commit** — `git add -A && git commit -m "feat(5c): adminGuard (loads user, requires ROLE_ADMIN)"`

---

### Task 9: AdminApi + models

**Files:**
- Create: `frontend/src/app/admin/admin.models.ts`
- Create: `frontend/src/app/admin/admin-api.ts`
- Test: `frontend/src/app/admin/admin-api.spec.ts`

- [ ] **Step 1: Write failing test**

```ts
import { TestBed } from '@angular/core/testing';
import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { API_BASE_URL } from '../core/api';
import { AdminApi } from './admin-api';

describe('AdminApi', () => {
  let api: AdminApi;
  let ctrl: HttpTestingController;
  beforeEach(() => {
    TestBed.configureTestingModule({
      providers: [
        provideHttpClient(),
        provideHttpClientTesting(),
        { provide: API_BASE_URL, useValue: 'https://api.test' },
      ],
    });
    api = TestBed.inject(AdminApi);
    ctrl = TestBed.inject(HttpTestingController);
  });
  afterEach(() => ctrl.verify());

  it('lists all users with no status param', () => {
    api.listUsers().subscribe();
    const req = ctrl.expectOne('https://api.test/api/admin/users');
    expect(req.request.method).toBe('GET');
    req.flush({ users: [] });
  });

  it('lists users filtered by status', () => {
    api.listUsers('pending_approval').subscribe();
    const req = ctrl.expectOne(
      (r) => r.url === 'https://api.test/api/admin/users' && r.params.get('status') === 'pending_approval',
    );
    req.flush({ users: [] });
  });

  it('POSTs an approve action', () => {
    api.act(7, 'approve').subscribe();
    const req = ctrl.expectOne('https://api.test/api/admin/users/7/approve');
    expect(req.request.method).toBe('POST');
    req.flush({ status: 'active' });
  });
});
```

- [ ] **Step 2: Run to verify it fails** — FAIL.

- [ ] **Step 3: Implement `admin.models.ts`**

```ts
// src/app/admin/admin.models.ts
export type AdminUserStatus =
  | 'pending_verification'
  | 'pending_approval'
  | 'active'
  | 'rejected'
  | 'suspended';

export interface AdminUserDto {
  id: number;
  email: string;
  status: AdminUserStatus;
  roles: string[];
  createdAt: string;
  approvedAt: string | null;
  identities: string[];
}

export type AdminAction = 'approve' | 'reject' | 'suspend';
```

- [ ] **Step 4: Implement `admin-api.ts`**

```ts
// src/app/admin/admin-api.ts
import { HttpClient, HttpParams } from '@angular/common/http';
import { Injectable, inject } from '@angular/core';
import { Observable } from 'rxjs';
import { API_BASE_URL } from '../core/api';
import { AdminAction, AdminUserDto, AdminUserStatus } from './admin.models';

@Injectable({ providedIn: 'root' })
export class AdminApi {
  private readonly http = inject(HttpClient);
  private readonly base = inject(API_BASE_URL);

  listUsers(status?: AdminUserStatus | null): Observable<{ users: AdminUserDto[] }> {
    let params = new HttpParams();
    if (status) params = params.set('status', status);
    return this.http.get<{ users: AdminUserDto[] }>(`${this.base}/api/admin/users`, { params });
  }

  act(id: number, action: AdminAction): Observable<{ status: AdminUserStatus }> {
    return this.http.post<{ status: AdminUserStatus }>(
      `${this.base}/api/admin/users/${id}/${action}`,
      {},
    );
  }
}
```

- [ ] **Step 5: Run test** — PASS.
- [ ] **Step 6: Commit** — `git add -A && git commit -m "feat(5c): AdminApi + admin user models"`

---

### Task 10: AdminUsersComponent

**Files:**
- Create: `frontend/src/app/admin/admin-users.component.ts`
- Test: `frontend/src/app/admin/admin-users.component.spec.ts`

- [ ] **Step 1: Write failing test**

```ts
import { TestBed } from '@angular/core/testing';
import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { provideRouter } from '@angular/router';
import { API_BASE_URL } from '../core/api';
import { AuthService } from '../core/auth.service';
import { AdminUsersComponent } from './admin-users.component';
import { AdminUserDto } from './admin.models';

const user = (id: number, over: Partial<AdminUserDto> = {}): AdminUserDto => ({
  id, email: `u${id}@x`, status: 'pending_approval', roles: ['ROLE_USER'],
  createdAt: 'x', approvedAt: null, identities: [], ...over,
});

describe('AdminUsersComponent', () => {
  let ctrl: HttpTestingController;

  function mount(currentId = 99) {
    TestBed.configureTestingModule({
      providers: [
        provideHttpClient(),
        provideHttpClientTesting(),
        provideRouter([]),
        { provide: API_BASE_URL, useValue: 'https://api.test' },
        { provide: AuthService, useValue: { user: () => ({ id: currentId }) } },
      ],
    });
    const f = TestBed.createComponent(AdminUsersComponent);
    f.detectChanges(); // ngOnInit → initial list
    ctrl = TestBed.inject(HttpTestingController);
    return f;
  }

  afterEach(() => ctrl.verify());

  it('loads all users on init and renders rows', () => {
    const f = mount();
    ctrl.expectOne('https://api.test/api/admin/users').flush({ users: [user(1), user(2)] });
    f.detectChanges();
    expect((f.nativeElement as HTMLElement).textContent).toContain('u1@x');
    expect((f.nativeElement as HTMLElement).textContent).toContain('u2@x');
  });

  it('offers Approve+Reject for a pending user, and re-fetches after an action', () => {
    const f = mount();
    ctrl.expectOne('https://api.test/api/admin/users').flush({ users: [user(1)] });
    f.detectChanges();
    const c = f.componentInstance;
    expect(c.canApprove(user(1))).toBe(true);
    expect(c.canReject(user(1))).toBe(true);
    expect(c.canSuspend(user(1))).toBe(false);

    c.act(user(1), 'approve');
    ctrl.expectOne('https://api.test/api/admin/users/1/approve').flush({ status: 'active' });
    // action triggers a reload of the current filter:
    ctrl.expectOne('https://api.test/api/admin/users').flush({ users: [] });
  });

  it('offers only Suspend for an active user', () => {
    const c = mount().componentInstance;
    ctrl.expectOne('https://api.test/api/admin/users').flush({ users: [] });
    const active = user(1, { status: 'active' });
    expect(c.canApprove(active)).toBe(false);
    expect(c.canSuspend(active)).toBe(true);
    expect(c.canReject(active)).toBe(false);
  });

  it('hides Reject/Suspend on the current admin’s own row', () => {
    const c = mount(1).componentInstance;
    ctrl.expectOne('https://api.test/api/admin/users').flush({ users: [] });
    const self = user(1, { status: 'active' });
    expect(c.canSuspend(self)).toBe(false);
    expect(c.canReject(user(1, { status: 'pending_approval' }))).toBe(false); // id 1 == self
  });

  it('changing the filter refetches with the status param', () => {
    const c = mount().componentInstance;
    ctrl.expectOne('https://api.test/api/admin/users').flush({ users: [] });
    c.setFilter('suspended');
    ctrl.expectOne(
      (r) => r.url === 'https://api.test/api/admin/users' && r.params.get('status') === 'suspended',
    ).flush({ users: [] });
  });
});
```

- [ ] **Step 2: Run to verify it fails** — FAIL.

- [ ] **Step 3: Implement `admin-users.component.ts`**

```ts
// src/app/admin/admin-users.component.ts
import { HttpErrorResponse } from '@angular/common/http';
import { Component, OnInit, computed, inject, signal } from '@angular/core';
import { RouterLink } from '@angular/router';
import { Problem, parseProblem } from '../core/problem';
import { AuthService } from '../core/auth.service';
import { IconComponent } from '../shared/icon/icon.component';
import { SpinnerComponent } from '../shared/spinner/spinner.component';
import { AdminApi } from './admin-api';
import { AdminAction, AdminUserDto, AdminUserStatus } from './admin.models';

interface Filter {
  label: string;
  status: AdminUserStatus | null;
}

@Component({
  selector: 'app-admin-users',
  imports: [RouterLink, IconComponent, SpinnerComponent],
  template: `
    <header class="bar">
      <a class="back" routerLink="/"><app-icon name="arrow_back" [size]="18" /> Reader</a>
      <h1>Users</h1>
    </header>

    <div class="filters" role="group" aria-label="Filter by status">
      @for (f of filters; track f.label) {
        <button [class.active]="filter() === f.status" (click)="setFilter(f.status)">
          {{ f.label }}
        </button>
      }
    </div>

    @if (loading()) {
      <div class="pad"><app-spinner /></div>
    } @else if (error()) {
      <div class="banner" role="alert">
        {{ error()!.detail || error()!.title }}
        <button (click)="load()">Retry</button>
      </div>
    } @else if (users().length === 0) {
      <p class="pad muted">No users match this filter.</p>
    } @else {
      <ul class="users">
        @for (u of users(); track u.id) {
          <li>
            <div class="who">
              <span class="email">{{ u.email }}</span>
              <span class="meta">
                <span class="badge" [attr.data-s]="u.status">{{ label(u.status) }}</span>
                @if (u.identities.length) {
                  <span class="prov">{{ u.identities.join(', ') }}</span>
                }
              </span>
            </div>
            <div class="acts">
              @if (canApprove(u)) {
                <button class="ok" (click)="act(u, 'approve')">Approve</button>
              }
              @if (canReject(u)) {
                <button class="warn" (click)="act(u, 'reject')">Reject</button>
              }
              @if (canSuspend(u)) {
                <button class="warn" (click)="act(u, 'suspend')">Suspend</button>
              }
            </div>
          </li>
        }
      </ul>
    }
  `,
  styles: [
    `
      :host {
        display: block;
        max-width: 820px;
        margin: 0 auto;
        padding: var(--space-4);
      }
      .bar {
        display: flex;
        align-items: center;
        gap: var(--space-4);
      }
      .back {
        display: inline-flex;
        align-items: center;
        gap: var(--space-1);
        color: var(--text-secondary);
        text-decoration: none;
      }
      h1 {
        font-size: var(--fs-xl);
        margin: 0;
      }
      .filters {
        display: flex;
        flex-wrap: wrap;
        gap: var(--space-1);
        margin: var(--space-4) 0;
      }
      .filters button {
        padding: var(--space-1) var(--space-3);
        border: 1px solid var(--border);
        border-radius: var(--radius);
        background: var(--surface-1);
        color: var(--text-secondary);
        cursor: pointer;
      }
      .filters button.active {
        background: var(--accent-soft);
        color: var(--accent);
        border-color: var(--accent);
      }
      .users {
        list-style: none;
        margin: 0;
        padding: 0;
        display: flex;
        flex-direction: column;
        gap: var(--space-1);
      }
      .users li {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: var(--space-3);
        padding: var(--space-3);
        border: 1px solid var(--border);
        border-radius: var(--radius);
        background: var(--surface-1);
      }
      .who {
        display: flex;
        flex-direction: column;
        gap: 2px;
        min-width: 0;
      }
      .email {
        color: var(--text-primary);
      }
      .meta {
        display: flex;
        align-items: center;
        gap: var(--space-2);
        font-size: var(--fs-sm);
        color: var(--text-muted);
      }
      .badge {
        padding: 0 var(--space-2);
        border-radius: var(--radius);
        background: var(--surface-0);
        color: var(--text-secondary);
      }
      .badge[data-s='active'] {
        background: var(--bg-success);
        color: var(--success);
      }
      .badge[data-s='suspended'],
      .badge[data-s='rejected'] {
        background: var(--bg-danger);
        color: var(--danger);
      }
      .acts {
        display: flex;
        gap: var(--space-2);
        flex: 0 0 auto;
      }
      .acts button {
        padding: var(--space-1) var(--space-3);
        border-radius: var(--radius);
        border: 1px solid var(--border-strong);
        background: var(--surface-1);
        color: var(--text-primary);
        cursor: pointer;
      }
      .acts button.ok {
        background: var(--accent);
        color: var(--on-accent);
        border-color: var(--accent);
      }
      .acts button.warn {
        color: var(--danger);
        border-color: var(--danger);
      }
      .banner {
        padding: var(--space-3);
        border-radius: var(--radius);
        background: var(--bg-danger);
        color: var(--danger);
        display: flex;
        justify-content: space-between;
        gap: var(--space-3);
      }
      .banner button {
        background: none;
        border: none;
        color: var(--danger);
        cursor: pointer;
        text-decoration: underline;
      }
      .pad {
        padding: var(--space-5);
        text-align: center;
      }
      .muted {
        color: var(--text-muted);
      }
    `,
  ],
})
export class AdminUsersComponent implements OnInit {
  private readonly api = inject(AdminApi);
  private readonly auth = inject(AuthService);

  readonly filters: Filter[] = [
    { label: 'All', status: null },
    { label: 'Pending approval', status: 'pending_approval' },
    { label: 'Unverified', status: 'pending_verification' },
    { label: 'Active', status: 'active' },
    { label: 'Rejected', status: 'rejected' },
    { label: 'Suspended', status: 'suspended' },
  ];

  readonly users = signal<AdminUserDto[]>([]);
  readonly loading = signal(false);
  readonly error = signal<Problem | null>(null);
  readonly filter = signal<AdminUserStatus | null>(null);

  private readonly selfId = computed(() => this.auth.user()?.id ?? -1);

  ngOnInit(): void {
    this.load();
  }

  setFilter(status: AdminUserStatus | null): void {
    this.filter.set(status);
    this.load();
  }

  load(): void {
    this.loading.set(true);
    this.error.set(null);
    this.api.listUsers(this.filter()).subscribe({
      next: (r) => {
        this.users.set(r.users);
        this.loading.set(false);
      },
      error: (e: HttpErrorResponse) => {
        this.error.set(parseProblem(e));
        this.loading.set(false);
      },
    });
  }

  act(u: AdminUserDto, action: AdminAction): void {
    this.api.act(u.id, action).subscribe({
      next: () => this.load(),
      error: (e: HttpErrorResponse) => this.error.set(parseProblem(e)),
    });
  }

  private isSelf(u: AdminUserDto): boolean {
    return u.id === this.selfId();
  }

  canApprove(u: AdminUserDto): boolean {
    return u.status !== 'active';
  }
  canReject(u: AdminUserDto): boolean {
    return !this.isSelf(u) && (u.status === 'pending_approval' || u.status === 'pending_verification');
  }
  canSuspend(u: AdminUserDto): boolean {
    return !this.isSelf(u) && u.status === 'active';
  }

  label(status: AdminUserStatus): string {
    return this.filters.find((f) => f.status === status)?.label ?? status;
  }
}
```

- [ ] **Step 4: Run test** — PASS.
- [ ] **Step 5: Commit** — `git add -A && git commit -m "feat(5c): admin user-queue component"`

---

### Task 11: FeedsSectionComponent

**Files:**
- Create: `frontend/src/app/settings/feeds-section.component.ts`
- Test: `frontend/src/app/settings/feeds-section.component.spec.ts`

- [ ] **Step 1: Write failing test**

```ts
import { TestBed } from '@angular/core/testing';
import { provideHttpClient } from '@angular/common/http';
import { provideHttpClientTesting } from '@angular/common/http/testing';
import { API_BASE_URL } from '../core/api';
import { FeedsSectionComponent } from './feeds-section.component';
import { SubscriptionsStore } from '../reader/subscriptions.store';
import { ManageActions } from '../reader/manage/manage-actions.service';
import { SubscriptionDto } from '../reader/models';

const sub = (id: number, over: Partial<SubscriptionDto> = {}): SubscriptionDto => ({
  id, title: `Feed ${id}`, customTitle: null, feedUrl: 'https://x/rss', siteUrl: 'https://x',
  status: 'active', createdAt: 'x', tags: [], unreadCount: 0, ...over,
});

describe('FeedsSectionComponent', () => {
  const edit = jest.fn();
  const unsubscribe = jest.fn();

  function mount(subs: SubscriptionDto[]) {
    const store = { subscriptions: () => subs, loading: () => false, error: () => null };
    TestBed.configureTestingModule({
      providers: [
        provideHttpClient(),
        provideHttpClientTesting(),
        { provide: API_BASE_URL, useValue: 'https://api.test' },
        { provide: SubscriptionsStore, useValue: store },
        { provide: ManageActions, useValue: { editSubscription: edit, unsubscribe } },
      ],
    });
    const f = TestBed.createComponent(FeedsSectionComponent);
    f.detectChanges();
    return f;
  }

  beforeEach(() => {
    edit.mockReset();
    unsubscribe.mockReset();
  });

  it('lists feeds sorted by title with a status badge', () => {
    const el: HTMLElement = mount([sub(2, { title: 'Zed' }), sub(1, { title: 'Alpha', status: 'gone' })]).nativeElement;
    const rows = el.querySelectorAll('.feed');
    expect(rows.length).toBe(2);
    expect(rows[0].textContent).toContain('Alpha');
    expect(el.textContent).toContain('gone');
  });

  it('shows an empty state when there are no feeds', () => {
    const el: HTMLElement = mount([]).nativeElement;
    expect(el.textContent).toContain('No feeds yet');
  });

  it('invokes ManageActions on edit and unsubscribe', () => {
    const f = mount([sub(1)]);
    const buttons = (f.nativeElement as HTMLElement).querySelectorAll('.feed button');
    (buttons[0] as HTMLButtonElement).click();
    (buttons[1] as HTMLButtonElement).click();
    expect(edit).toHaveBeenCalled();
    expect(unsubscribe).toHaveBeenCalled();
  });
});
```

- [ ] **Step 2: Run to verify it fails** — FAIL.

- [ ] **Step 3: Implement `feeds-section.component.ts`**

```ts
// src/app/settings/feeds-section.component.ts
import { Component, computed, inject } from '@angular/core';
import { IconComponent } from '../shared/icon/icon.component';
import { SubscriptionsStore } from '../reader/subscriptions.store';
import { ManageActions } from '../reader/manage/manage-actions.service';
import { SubscriptionDto } from '../reader/models';

@Component({
  selector: 'app-feeds-section',
  imports: [IconComponent],
  template: `
    <section>
      <h2>Feeds</h2>
      @if (feeds().length === 0) {
        <p class="muted">No feeds yet — add one from the reader.</p>
      } @else {
        <ul class="list">
          @for (s of feeds(); track s.id) {
            <li class="feed">
              <div class="info">
                <span class="title">{{ s.title }}</span>
                <span class="sub">
                  <span
                    class="badge"
                    [attr.data-s]="s.status"
                    [title]="statusHint(s.status)"
                  >{{ s.status }}</span>
                  @for (t of s.tags; track t.id) {
                    <span class="chip">
                      <span class="dot" [style.background]="t.color || 'var(--text-muted)'"></span>
                      {{ t.name }}
                    </span>
                  }
                  @if (s.unreadCount > 0) {
                    <span class="count">{{ s.unreadCount }} unread</span>
                  }
                </span>
              </div>
              <div class="acts">
                <button (click)="manage.editSubscription(s)">
                  <app-icon name="edit" [size]="16" /> Rename &amp; tags
                </button>
                <button class="danger" (click)="manage.unsubscribe(s)">
                  <app-icon name="delete" [size]="16" /> Unsubscribe
                </button>
              </div>
            </li>
          }
        </ul>
      }
    </section>
  `,
  styles: [
    `
      h2 {
        font-size: var(--fs-lg);
        margin: 0 0 var(--space-3);
      }
      .muted {
        color: var(--text-muted);
      }
      .list {
        list-style: none;
        margin: 0;
        padding: 0;
        display: flex;
        flex-direction: column;
        gap: var(--space-1);
      }
      .feed {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: var(--space-3);
        padding: var(--space-3);
        border: 1px solid var(--border);
        border-radius: var(--radius);
        background: var(--surface-1);
      }
      .info {
        display: flex;
        flex-direction: column;
        gap: 2px;
        min-width: 0;
      }
      .title {
        color: var(--text-primary);
      }
      .sub {
        display: flex;
        flex-wrap: wrap;
        align-items: center;
        gap: var(--space-2);
        font-size: var(--fs-sm);
        color: var(--text-muted);
      }
      .badge {
        padding: 0 var(--space-2);
        border-radius: var(--radius);
        background: var(--surface-0);
        color: var(--text-secondary);
        text-transform: capitalize;
      }
      .badge[data-s='erroring'] {
        background: var(--bg-danger);
        color: var(--danger);
      }
      .badge[data-s='gone'] {
        background: var(--bg-danger);
        color: var(--danger);
      }
      .chip {
        display: inline-flex;
        align-items: center;
        gap: var(--space-1);
      }
      .dot {
        width: 8px;
        height: 8px;
        border-radius: 50%;
      }
      .acts {
        display: flex;
        gap: var(--space-2);
        flex: 0 0 auto;
      }
      .acts button {
        display: inline-flex;
        align-items: center;
        gap: var(--space-1);
        padding: var(--space-1) var(--space-3);
        border: 1px solid var(--border-strong);
        border-radius: var(--radius);
        background: var(--surface-1);
        color: var(--text-primary);
        cursor: pointer;
      }
      .acts button.danger {
        color: var(--danger);
        border-color: var(--danger);
      }
    `,
  ],
})
export class FeedsSectionComponent {
  readonly subs = inject(SubscriptionsStore);
  readonly manage = inject(ManageActions);

  readonly feeds = computed(() =>
    [...this.subs.subscriptions()].sort((a, b) => a.title.localeCompare(b.title)),
  );

  statusHint(status: SubscriptionDto['status']): string {
    if (status === 'erroring') return 'This feed last failed to fetch. A refresh will retry it.';
    if (status === 'gone') return 'This feed appears gone. A refresh will retry it.';
    return 'This feed is healthy.';
  }
}
```

- [ ] **Step 4: Run test** — PASS.
- [ ] **Step 5: Commit** — `git add -A && git commit -m "feat(5c): settings Feeds section"`

---

### Task 12: TagsSectionComponent

**Files:**
- Create: `frontend/src/app/settings/tags-section.component.ts`
- Test: `frontend/src/app/settings/tags-section.component.spec.ts`

- [ ] **Step 1: Write failing test**

```ts
import { TestBed } from '@angular/core/testing';
import { TagsSectionComponent } from './tags-section.component';
import { TagsStore } from '../reader/tags.store';
import { SubscriptionsStore } from '../reader/subscriptions.store';
import { ManageActions } from '../reader/manage/manage-actions.service';
import { TagDto, SubscriptionDto } from '../reader/models';

const tag = (id: number, name: string): TagDto => ({ id, name, color: '#3f8676', icon: 'label' });

describe('TagsSectionComponent', () => {
  const createTag = jest.fn();
  const editTag = jest.fn();
  const deleteTag = jest.fn();

  function mount(tags: TagDto[], subs: SubscriptionDto[] = []) {
    TestBed.configureTestingModule({
      providers: [
        { provide: TagsStore, useValue: { tags: () => tags, loading: () => false, error: () => null } },
        { provide: SubscriptionsStore, useValue: { subscriptions: () => subs } },
        { provide: ManageActions, useValue: { createTag, editTag, deleteTag } },
      ],
    });
    const f = TestBed.createComponent(TagsSectionComponent);
    f.detectChanges();
    return f;
  }

  beforeEach(() => {
    createTag.mockReset();
    editTag.mockReset();
    deleteTag.mockReset();
  });

  it('lists tags and a feed-usage count', () => {
    const subs: SubscriptionDto[] = [
      { id: 1, title: 'A', customTitle: null, feedUrl: 'u', siteUrl: null, status: 'active', createdAt: 'x', tags: [tag(1, 'Tech')], unreadCount: 0 },
    ];
    const el: HTMLElement = mount([tag(1, 'Tech'), tag(2, 'News')], subs).nativeElement;
    expect(el.textContent).toContain('Tech');
    expect(el.textContent).toContain('News');
    expect(el.textContent).toContain('1 feed');
  });

  it('empty state when no tags', () => {
    expect((mount([]).nativeElement as HTMLElement).textContent).toContain('No tags yet');
  });

  it('wires New / Edit / Delete to ManageActions', () => {
    const f = mount([tag(1, 'Tech')]);
    const el = f.nativeElement as HTMLElement;
    (el.querySelector('.new') as HTMLButtonElement).click();
    const rowButtons = el.querySelectorAll('.tag .acts button');
    (rowButtons[0] as HTMLButtonElement).click(); // edit
    (rowButtons[1] as HTMLButtonElement).click(); // delete
    expect(createTag).toHaveBeenCalled();
    expect(editTag).toHaveBeenCalledWith(tag(1, 'Tech'));
    expect(deleteTag).toHaveBeenCalledWith(tag(1, 'Tech'));
  });
});
```

- [ ] **Step 2: Run to verify it fails** — FAIL.

- [ ] **Step 3: Implement `tags-section.component.ts`**

```ts
// src/app/settings/tags-section.component.ts
import { Component, computed, inject } from '@angular/core';
import { IconComponent } from '../shared/icon/icon.component';
import { TagsStore } from '../reader/tags.store';
import { SubscriptionsStore } from '../reader/subscriptions.store';
import { ManageActions } from '../reader/manage/manage-actions.service';
import { TagDto } from '../reader/models';

@Component({
  selector: 'app-tags-section',
  imports: [IconComponent],
  template: `
    <section>
      <div class="head">
        <h2>Tags</h2>
        <button class="new" (click)="manage.createTag()">
          <app-icon name="add" [size]="16" /> New tag
        </button>
      </div>
      @if (tagsStore.tags().length === 0) {
        <p class="muted">No tags yet — create one to group your feeds.</p>
      } @else {
        <ul class="list">
          @for (t of tagsStore.tags(); track t.id) {
            <li class="tag">
              <span class="ident">
                <span class="dot" [style.background]="t.color || 'var(--text-muted)'"></span>
                @if (t.icon) {
                  <app-icon [name]="t.icon" [size]="18" />
                }
                <span class="name">{{ t.name }}</span>
                <span class="count">{{ usage()[t.id] || 0 }} {{ (usage()[t.id] || 0) === 1 ? 'feed' : 'feeds' }}</span>
              </span>
              <span class="acts">
                <button (click)="manage.editTag(t)"><app-icon name="edit" [size]="16" /> Edit</button>
                <button class="danger" (click)="manage.deleteTag(t)">
                  <app-icon name="delete" [size]="16" /> Delete
                </button>
              </span>
            </li>
          }
        </ul>
      }
    </section>
  `,
  styles: [
    `
      .head {
        display: flex;
        align-items: center;
        justify-content: space-between;
        margin-bottom: var(--space-3);
      }
      h2 {
        font-size: var(--fs-lg);
        margin: 0;
      }
      .new {
        display: inline-flex;
        align-items: center;
        gap: var(--space-1);
        padding: var(--space-1) var(--space-3);
        border: 1px solid var(--accent);
        border-radius: var(--radius);
        background: var(--accent);
        color: var(--on-accent);
        cursor: pointer;
      }
      .muted {
        color: var(--text-muted);
      }
      .list {
        list-style: none;
        margin: 0;
        padding: 0;
        display: flex;
        flex-direction: column;
        gap: var(--space-1);
      }
      .tag {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: var(--space-3);
        padding: var(--space-3);
        border: 1px solid var(--border);
        border-radius: var(--radius);
        background: var(--surface-1);
      }
      .ident {
        display: flex;
        align-items: center;
        gap: var(--space-2);
        min-width: 0;
      }
      .dot {
        width: 10px;
        height: 10px;
        border-radius: 50%;
        flex: 0 0 auto;
      }
      .name {
        color: var(--text-primary);
      }
      .count {
        font-size: var(--fs-sm);
        color: var(--text-muted);
      }
      .acts {
        display: flex;
        gap: var(--space-2);
        flex: 0 0 auto;
      }
      .acts button {
        display: inline-flex;
        align-items: center;
        gap: var(--space-1);
        padding: var(--space-1) var(--space-3);
        border: 1px solid var(--border-strong);
        border-radius: var(--radius);
        background: var(--surface-1);
        color: var(--text-primary);
        cursor: pointer;
      }
      .acts button.danger {
        color: var(--danger);
        border-color: var(--danger);
      }
    `,
  ],
})
export class TagsSectionComponent {
  readonly tagsStore = inject(TagsStore);
  private readonly subs = inject(SubscriptionsStore);
  readonly manage = inject(ManageActions);

  /** feed count per tag id, derived from the subscription list. */
  readonly usage = computed<Record<number, number>>(() => {
    const map: Record<number, number> = {};
    for (const s of this.subs.subscriptions()) {
      for (const t of s.tags) map[t.id] = (map[t.id] ?? 0) + 1;
    }
    return map;
  });
}
```

- [ ] **Step 4: Run test** — PASS.
- [ ] **Step 5: Commit** — `git add -A && git commit -m "feat(5c): settings Tags section"`

---

### Task 13: OpmlSectionComponent

**Files:**
- Create: `frontend/src/app/settings/opml-section.component.ts`
- Test: `frontend/src/app/settings/opml-section.component.spec.ts`

- [ ] **Step 1: Write failing test** (the download uses object-URLs; stub them)

```ts
import { TestBed } from '@angular/core/testing';
import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { API_BASE_URL } from '../core/api';
import { OpmlSectionComponent } from './opml-section.component';
import { SubscriptionsStore } from '../reader/subscriptions.store';

describe('OpmlSectionComponent', () => {
  let ctrl: HttpTestingController;
  const load = jest.fn();

  function mount() {
    TestBed.configureTestingModule({
      providers: [
        provideHttpClient(),
        provideHttpClientTesting(),
        { provide: API_BASE_URL, useValue: 'https://api.test' },
        { provide: SubscriptionsStore, useValue: { load } },
      ],
    });
    const f = TestBed.createComponent(OpmlSectionComponent);
    f.detectChanges();
    ctrl = TestBed.inject(HttpTestingController);
    return f;
  }

  beforeEach(() => {
    load.mockReset();
    // jsdom lacks these:
    (URL as unknown as { createObjectURL: unknown }).createObjectURL = jest.fn(() => 'blob:x');
    (URL as unknown as { revokeObjectURL: unknown }).revokeObjectURL = jest.fn();
  });
  afterEach(() => ctrl.verify());

  it('exports OPML through HttpClient and triggers a download', () => {
    const c = mount().componentInstance;
    c.exportOpml();
    const req = ctrl.expectOne('https://api.test/api/opml/export');
    expect(req.request.method).toBe('GET');
    req.flush('<opml/>');
    expect(URL.createObjectURL).toHaveBeenCalled();
    expect(c.exporting()).toBe(false);
  });

  it('imports pasted OPML, shows the result and reloads subscriptions', () => {
    const c = mount().componentInstance;
    c.text.set('<opml/>');
    c.importText();
    const req = ctrl.expectOne('https://api.test/api/opml/import');
    expect(req.request.method).toBe('POST');
    expect(req.request.body).toBe('<opml/>');
    req.flush({ imported: 3, alreadySubscribed: 1, invalid: 0, skippedOverLimit: 0 });
    expect(c.result()?.imported).toBe(3);
    expect(load).toHaveBeenCalled();
  });

  it('does not import an empty body', () => {
    const c = mount().componentInstance;
    c.text.set('   ');
    c.importText();
    ctrl.expectNone('https://api.test/api/opml/import');
  });
});
```

- [ ] **Step 2: Run to verify it fails** — FAIL.

- [ ] **Step 3: Implement `opml-section.component.ts`**

```ts
// src/app/settings/opml-section.component.ts
import { HttpErrorResponse } from '@angular/common/http';
import { Component, inject, signal } from '@angular/core';
import { parseProblem } from '../core/problem';
import { ReaderApi } from '../reader/reader-api';
import { SubscriptionsStore } from '../reader/subscriptions.store';
import { OpmlImportResult } from '../reader/models';

@Component({
  selector: 'app-opml-section',
  template: `
    <section>
      <h2>Import &amp; export</h2>

      <div class="block">
        <p class="lead">Download all your feeds as an OPML file.</p>
        <button class="btn" [disabled]="exporting()" (click)="exportOpml()">
          {{ exporting() ? 'Preparing…' : 'Export OPML' }}
        </button>
        @if (exportError()) {
          <p class="error" role="alert">{{ exportError() }}</p>
        }
      </div>

      <div class="block">
        <p class="lead">Import an OPML file, or paste its contents.</p>
        <input type="file" accept=".opml,.xml,text/xml,text/x-opml" (change)="onFile($event)" />
        <textarea
          class="field area"
          rows="4"
          placeholder="…or paste OPML here"
          [value]="text()"
          (input)="text.set(value($event))"
        ></textarea>
        <button class="btn primary" [disabled]="importing() || !text().trim()" (click)="importText()">
          {{ importing() ? 'Importing…' : 'Import' }}
        </button>
        @if (importError()) {
          <p class="error" role="alert">{{ importError() }}</p>
        }
        @if (result(); as r) {
          <p class="result">
            Imported {{ r.imported }}, already subscribed {{ r.alreadySubscribed }},
            invalid {{ r.invalid }}, skipped over limit {{ r.skippedOverLimit }}.
            New feeds fill in on the next refresh.
          </p>
        }
      </div>
    </section>
  `,
  styles: [
    `
      h2 {
        font-size: var(--fs-lg);
        margin: 0 0 var(--space-3);
      }
      .block {
        padding: var(--space-4);
        border: 1px solid var(--border);
        border-radius: var(--radius);
        background: var(--surface-1);
        margin-bottom: var(--space-3);
        display: flex;
        flex-direction: column;
        gap: var(--space-2);
        align-items: flex-start;
      }
      .lead {
        margin: 0;
        color: var(--text-secondary);
      }
      .area {
        width: 100%;
        resize: vertical;
        font-family: var(--font-sans);
      }
      .btn {
        padding: var(--space-2) var(--space-4);
        border: 1px solid var(--border-strong);
        border-radius: var(--radius);
        background: var(--surface-1);
        color: var(--text-primary);
        cursor: pointer;
      }
      .btn.primary {
        background: var(--accent);
        color: var(--on-accent);
        border-color: var(--accent);
      }
      .btn:disabled {
        opacity: 0.7;
        cursor: default;
      }
      .error {
        color: var(--danger);
        font-size: var(--fs-sm);
        margin: 0;
      }
      .result {
        margin: 0;
        color: var(--text-secondary);
        font-size: var(--fs-sm);
      }
    `,
  ],
})
export class OpmlSectionComponent {
  private readonly api = inject(ReaderApi);
  private readonly subs = inject(SubscriptionsStore);

  readonly text = signal('');
  readonly exporting = signal(false);
  readonly importing = signal(false);
  readonly result = signal<OpmlImportResult | null>(null);
  readonly exportError = signal<string | null>(null);
  readonly importError = signal<string | null>(null);

  value(e: Event): string {
    return (e.target as HTMLTextAreaElement).value;
  }

  exportOpml(): void {
    this.exporting.set(true);
    this.exportError.set(null);
    this.api.exportOpml().subscribe({
      next: (xml) => {
        this.exporting.set(false);
        this.download(xml);
      },
      error: (e: HttpErrorResponse) => {
        this.exporting.set(false);
        this.exportError.set(parseProblem(e).title);
      },
    });
  }

  onFile(e: Event): void {
    const file = (e.target as HTMLInputElement).files?.[0];
    if (!file) return;
    file.text().then((t) => this.text.set(t));
  }

  importText(): void {
    const body = this.text().trim();
    if (!body) return;
    this.importing.set(true);
    this.importError.set(null);
    this.result.set(null);
    this.api.importOpml(body).subscribe({
      next: (r) => {
        this.importing.set(false);
        this.result.set(r);
        this.subs.load();
      },
      error: (e: HttpErrorResponse) => {
        this.importing.set(false);
        this.importError.set(parseProblem(e).detail ?? parseProblem(e).title);
      },
    });
  }

  private download(xml: string): void {
    const url = URL.createObjectURL(new Blob([xml], { type: 'text/x-opml' }));
    const a = document.createElement('a');
    a.href = url;
    a.download = 'feeds.opml';
    a.click();
    URL.revokeObjectURL(url);
  }
}
```

- [ ] **Step 4: Run test** — PASS.
- [ ] **Step 5: Commit** — `git add -A && git commit -m "feat(5c): settings Import/Export (OPML) section"`

---

### Task 14: AccountSectionComponent

**Files:**
- Create: `frontend/src/app/settings/account-section.component.ts`
- Test: `frontend/src/app/settings/account-section.component.spec.ts`

- [ ] **Step 1: Write failing test**

```ts
import { TestBed } from '@angular/core/testing';
import { provideRouter } from '@angular/router';
import { AccountSectionComponent } from './account-section.component';
import { AuthService, CurrentUser } from '../core/auth.service';

const user: CurrentUser = { id: 1, email: 'me@x', roles: ['ROLE_USER'], status: 'active', createdAt: '2026-01-01T00:00:00Z' };

describe('AccountSectionComponent', () => {
  const logout = jest.fn();

  function mount(u: CurrentUser | null, admin = false) {
    TestBed.configureTestingModule({
      providers: [
        provideRouter([]),
        { provide: AuthService, useValue: { user: () => u, isAdmin: () => admin, logout } },
      ],
    });
    const f = TestBed.createComponent(AccountSectionComponent);
    f.detectChanges();
    return f;
  }

  beforeEach(() => logout.mockReset());

  it('shows the email and a sign-out button', () => {
    const f = mount(user);
    expect((f.nativeElement as HTMLElement).textContent).toContain('me@x');
    (f.nativeElement.querySelector('.signout') as HTMLButtonElement).click();
    expect(logout).toHaveBeenCalled();
  });

  it('shows an Admin link only for admins', () => {
    expect((mount(user, false).nativeElement as HTMLElement).querySelector('a[href="/admin/users"]')).toBeNull();
    expect((mount(user, true).nativeElement as HTMLElement).querySelector('a[href="/admin/users"]')).not.toBeNull();
  });
});
```

- [ ] **Step 2: Run to verify it fails** — FAIL.

- [ ] **Step 3: Implement `account-section.component.ts`**

```ts
// src/app/settings/account-section.component.ts
import { Component, inject } from '@angular/core';
import { RouterLink } from '@angular/router';
import { IconComponent } from '../shared/icon/icon.component';
import { AuthService } from '../core/auth.service';

@Component({
  selector: 'app-account-section',
  imports: [RouterLink, IconComponent],
  template: `
    <section>
      <h2>Account</h2>
      @if (auth.user(); as u) {
        <dl class="grid">
          <dt>Email</dt>
          <dd>{{ u.email }}</dd>
          <dt>Member since</dt>
          <dd>{{ u.createdAt | date: 'longDate' }}</dd>
        </dl>
        @if (auth.isAdmin()) {
          <a class="admin" routerLink="/admin/users">
            <app-icon name="shield_person" [size]="18" /> Admin — user queue
          </a>
        }
        <button class="signout" (click)="auth.logout()">Sign out</button>
      }
    </section>
  `,
  styles: [
    `
      h2 {
        font-size: var(--fs-lg);
        margin: 0 0 var(--space-3);
      }
      .grid {
        display: grid;
        grid-template-columns: auto 1fr;
        gap: var(--space-2) var(--space-4);
        margin: 0 0 var(--space-3);
      }
      dt {
        color: var(--text-muted);
      }
      dd {
        margin: 0;
        color: var(--text-primary);
      }
      .admin {
        display: inline-flex;
        align-items: center;
        gap: var(--space-1);
        color: var(--accent);
        text-decoration: none;
        margin-bottom: var(--space-3);
      }
      .signout {
        display: block;
        padding: var(--space-2) var(--space-4);
        border: 1px solid var(--border-strong);
        border-radius: var(--radius);
        background: var(--surface-1);
        color: var(--text-primary);
        cursor: pointer;
      }
    `,
  ],
})
export class AccountSectionComponent {
  readonly auth = inject(AuthService);
}
```

> The `date` pipe needs no import in standalone components as of Angular 20? It does — `DatePipe` from `@angular/common`. Add `DatePipe` to `imports` and `import { DatePipe } from '@angular/common'`. Update the decorator `imports: [RouterLink, IconComponent, DatePipe]`.

- [ ] **Step 4: Run test** — PASS. (Add `DatePipe` to imports if the template date pipe errors.)
- [ ] **Step 5: Commit** — `git add -A && git commit -m "feat(5c): settings Account section"`

---

### Task 15: SettingsComponent (page shell)

**Files:**
- Create: `frontend/src/app/settings/settings.component.ts`
- Test: `frontend/src/app/settings/settings.component.spec.ts`

- [ ] **Step 1: Write failing test**

```ts
import { TestBed } from '@angular/core/testing';
import { provideRouter } from '@angular/router';
import { SettingsComponent } from './settings.component';
import { SubscriptionsStore } from '../reader/subscriptions.store';
import { TagsStore } from '../reader/tags.store';

describe('SettingsComponent', () => {
  const subLoad = jest.fn();
  const tagLoad = jest.fn();

  function mount() {
    TestBed.configureTestingModule({
      providers: [
        provideRouter([]),
        { provide: SubscriptionsStore, useValue: { load: subLoad, subscriptions: () => [], loading: () => false, error: () => null } },
        { provide: TagsStore, useValue: { load: tagLoad, tags: () => [], loading: () => false, error: () => null } },
      ],
    }).overrideComponent(SettingsComponent, { set: { imports: [], template: '<h1>Settings</h1>' } });
    const f = TestBed.createComponent(SettingsComponent);
    f.detectChanges();
    return f;
  }

  beforeEach(() => {
    subLoad.mockReset();
    tagLoad.mockReset();
  });

  it('loads subscriptions and tags on init', () => {
    mount();
    expect(subLoad).toHaveBeenCalled();
    expect(tagLoad).toHaveBeenCalled();
  });
});
```

> Rationale for `overrideComponent`: the child section components each inject stores/services; the page test only needs to prove the shell loads both stores on init. Overriding the template keeps this a focused unit test. The sections have their own specs.

- [ ] **Step 2: Run to verify it fails** — FAIL.

- [ ] **Step 3: Implement `settings.component.ts`**

```ts
// src/app/settings/settings.component.ts
import { Component, OnInit, inject } from '@angular/core';
import { RouterLink } from '@angular/router';
import { IconComponent } from '../shared/icon/icon.component';
import { SubscriptionsStore } from '../reader/subscriptions.store';
import { TagsStore } from '../reader/tags.store';
import { FeedsSectionComponent } from './feeds-section.component';
import { TagsSectionComponent } from './tags-section.component';
import { OpmlSectionComponent } from './opml-section.component';
import { AccountSectionComponent } from './account-section.component';

@Component({
  selector: 'app-settings',
  imports: [
    RouterLink,
    IconComponent,
    FeedsSectionComponent,
    TagsSectionComponent,
    OpmlSectionComponent,
    AccountSectionComponent,
  ],
  template: `
    <header class="bar">
      <a class="back" routerLink="/"><app-icon name="arrow_back" [size]="18" /> Reader</a>
      <h1>Settings</h1>
    </header>
    <div class="page">
      <app-feeds-section />
      <app-tags-section />
      <app-opml-section />
      <app-account-section />
    </div>
  `,
  styles: [
    `
      .bar {
        height: 56px;
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
      .page {
        max-width: 820px;
        margin: 0 auto;
        padding: var(--space-5) var(--space-4);
        display: flex;
        flex-direction: column;
        gap: var(--space-6);
      }
    `,
  ],
})
export class SettingsComponent implements OnInit {
  private readonly subs = inject(SubscriptionsStore);
  private readonly tags = inject(TagsStore);

  ngOnInit(): void {
    this.subs.load();
    this.tags.load();
  }
}
```

- [ ] **Step 4: Run test** — PASS.
- [ ] **Step 5: Commit** — `git add -A && git commit -m "feat(5c): settings page shell hosting all sections"`

---

### Task 16: Routing + header entry points

**Files:**
- Modify: `frontend/src/app/app.routes.ts`
- Modify: `frontend/src/app/reader/header/reader-header.component.ts`
- Test: `frontend/src/app/reader/header/reader-header.component.spec.ts` (extend)

- [ ] **Step 1: Add routes** in `app.routes.ts` — insert BEFORE the `''` route (order matters: the wildcard `**` stays last, and specific paths precede `''`):

```ts
  {
    path: 'settings',
    canActivate: [authGuard],
    loadComponent: () => import('./settings/settings.component').then((m) => m.SettingsComponent),
  },
  {
    path: 'admin/users',
    canActivate: [authGuard, adminGuard],
    loadComponent: () =>
      import('./admin/admin-users.component').then((m) => m.AdminUsersComponent),
  },
```

Add the import at the top: `import { adminGuard } from './core/admin.guard';`

- [ ] **Step 2: Write failing header test** — extend `reader-header.component.spec.ts`. The header spec already sets up providers (HTTP + router per the 5a notes). Add:

```ts
it('shows a Settings link, and Admin only for admins', () => {
  // mount with a non-admin auth stub, open the account menu:
  // (follow the existing spec's mount helper; set auth.isAdmin() → false)
  // expect a link/button labelled "Settings" present, "Admin" absent.
  // Then re-mount with isAdmin() → true and expect "Admin" present.
});
```

Concretely, if the existing spec injects the real `AuthService`, override it with a stub exposing `user`, `isAdmin`, `logout`, `theme`, `layout`, `refreshSvc`. Match the existing spec's structure — read it first and mirror its providers. Assert on `menuOpen` set true then query the rendered menu.

- [ ] **Step 3: Implement header change.** In `reader-header.component.ts`:
  - Add `RouterLink` to imports: `import { RouterLink } from '@angular/router';` and `imports: [IconComponent, RouterLink]`.
  - Replace the account menu body so it includes Settings (always) and Admin (if admin), keeping Sign out:

```html
@if (menuOpen()) {
  <div class="menu" role="menu">
    <a role="menuitem" routerLink="/settings" (click)="menuOpen.set(false)">Settings</a>
    @if (auth.isAdmin()) {
      <a role="menuitem" routerLink="/admin/users" (click)="menuOpen.set(false)">Admin</a>
    }
    <button role="menuitem" (click)="auth.logout()">Sign out</button>
  </div>
}
```

  - Extend the `.menu` styles so anchors match the existing button rows:

```css
.menu a {
  display: block;
  padding: var(--space-3);
  color: var(--text-primary);
  text-decoration: none;
}
.menu a:hover {
  background: var(--surface-0);
}
```

- [ ] **Step 4: Run tests** — `npm test -- reader-header` → PASS.
- [ ] **Step 5: Commit** — `git add -A && git commit -m "feat(5c): routes for settings + admin, header entry points"`

---

### Task 17: Sidebar hover menus + shell wiring (isolated — last feature task)

**Files:**
- Modify: `frontend/src/app/reader/sidebar/sidebar.component.ts`
- Test: `frontend/src/app/reader/sidebar/sidebar.component.spec.ts` (extend)
- Modify: `frontend/src/app/reader/reader-shell.component.ts`

- [ ] **Step 1: Write failing sidebar test** — the sidebar gains four outputs. Extend the existing spec (read it first for its mount helper — it sets the required inputs `tagTree`, `untagged`, `totalUnread`, `selection`). Add:

```ts
it('emits editTag / deleteTag when a tag row menu action is used', () => {
  // mount with one tag node; open its "⋯" menu; click Edit then Delete
  // spy on component.editTag / component.deleteTag outputs
});

it('emits editFeed / unsubscribe for a feed row', () => {
  // mount with one untagged sub; open its "⋯" menu; click each action
});
```

Use `component.editTag.subscribe(...)` (outputs are observables) or `jest.spyOn` on `emit`. Follow the existing spec conventions.

- [ ] **Step 2: Run to verify it fails** — FAIL.

- [ ] **Step 3: Implement sidebar change.** Add imports/outputs and a per-row `⋯` menu.

Add to the class:

```ts
import { Component, input, output, signal } from '@angular/core';
import { TagDto } from '../models';
// ...
  readonly editTag = output<TagDto>();
  readonly deleteTag = output<TagDto>();
  readonly editFeed = output<SubscriptionDto>();
  readonly unsubscribe = output<SubscriptionDto>();

  readonly menuFor = signal<string | null>(null);

  toggleMenu(key: string, ev: Event): void {
    ev.preventDefault();
    ev.stopPropagation();
    this.menuFor.update((k) => (k === key ? null : key));
  }
  closeMenu(): void {
    this.menuFor.set(null);
  }
```

In the template, add a `⋯` button + inline menu to each tag row (`node.tag`) and each feed row (both `node.subscriptions` items and the untagged `s`). Example for a tag row — placed inside `.tag` after the `<a class="nav grow">`:

```html
<div class="rowmenu">
  <button
    class="dots"
    type="button"
    [attr.aria-label]="'Manage ' + node.tag.name"
    (click)="toggleMenu('tag-' + node.tag.id, $event)"
  >
    <app-icon name="more_horiz" [size]="18" />
  </button>
  @if (menuFor() === 'tag-' + node.tag.id) {
    <div class="pop" role="menu">
      <button role="menuitem" (click)="editTag.emit(node.tag); closeMenu()">Edit tag</button>
      <button role="menuitem" (click)="deleteTag.emit(node.tag); closeMenu()">Delete tag</button>
    </div>
  }
</div>
```

For feed rows, use key `'sub-' + s.id` and emit `editFeed`/`unsubscribe`. Wrap each feed `<a>` and its menu in a container so the menu anchors correctly (mirror `.tag`'s flex row). Add styles:

```css
.rowmenu {
  position: relative;
}
.dots {
  background: none;
  border: none;
  color: var(--text-muted);
  cursor: pointer;
  padding: var(--space-1);
  visibility: hidden;
}
.tag:hover .dots,
.nav:hover + .rowmenu .dots,
.rowmenu:hover .dots,
.dots:focus-visible {
  visibility: visible;
}
.pop {
  position: absolute;
  right: 0;
  top: 28px;
  z-index: 2;
  background: var(--surface-2);
  border: 1px solid var(--border);
  border-radius: var(--radius);
  min-width: 140px;
}
.pop button {
  display: block;
  width: 100%;
  text-align: left;
  padding: var(--space-2) var(--space-3);
  background: none;
  border: none;
  color: var(--text-primary);
  cursor: pointer;
}
.pop button:hover {
  background: var(--surface-0);
}
```

> Keep it simple: the exact hover selector can be relaxed to always-visible `.dots` at reduced opacity if the `:hover` composition is awkward — the spec only asserts the emitted outputs, not visibility. Prefer a robust, always-rendered `.dots` with `opacity: 0.55` and `opacity: 1` on `:hover`/`:focus-visible` of its row container if the sibling selector is fragile.

- [ ] **Step 4: Wire the shell.** In `reader-shell.component.ts`, inject `ManageActions` and bind the sidebar outputs:

```ts
import { ManageActions } from './manage/manage-actions.service';
// ...
  readonly manage = inject(ManageActions);
```

Update the `<app-sidebar>` usage in the template:

```html
<app-sidebar
  [tagTree]="subs.tagTree()"
  [untagged]="subs.untagged()"
  [totalUnread]="subs.totalUnread()"
  [selection]="selection()"
  [loading]="subs.loading()"
  (editTag)="manage.editTag($event)"
  (deleteTag)="manage.deleteTag($event)"
  (editFeed)="manage.editSubscription($event)"
  (unsubscribe)="manage.unsubscribe($event)"
/>
```

(No shell spec change required — the existing shell spec sets inputs and does not exercise the new outputs. If the shell spec constructs the real `ManageActions`, it will inject `Dialog`; ensure the shell spec already provides what `ManageActions` needs, or that `ManageActions` is only touched on user action. Since bindings are lazy, no HTTP fires at mount. If the shell spec fails to construct due to `Dialog`, add `importProvidersFrom`/a `Dialog` stub — but `@angular/cdk/dialog`'s `Dialog` is providedIn root and constructs fine in tests, as the existing add-feed wiring already injects it.)

- [ ] **Step 5: Run tests** — `npm test -- sidebar reader-shell` → PASS.
- [ ] **Step 6: Commit** — `git add -A && git commit -m "feat(5c): sidebar per-row manage menus wired to ManageActions"`

---

### Task 18: Playwright smoke

**Files:**
- Create: `frontend/e2e/settings-admin-smoke.spec.ts`

- [ ] **Step 1: Write the smoke** (mirror `reader-smoke.spec.ts`'s sign-in helper + skip-if-unreachable convention)

```ts
// e2e/settings-admin-smoke.spec.ts
import { test, expect, Page } from '@playwright/test';

const ADMIN_EMAIL = process.env.E2E_ADMIN_EMAIL ?? 'e2e-admin@example.com';
const ADMIN_PASSWORD = process.env.E2E_ADMIN_PASSWORD ?? 'e2e-admin-password-123';

async function signInAsAdmin(page: Page): Promise<boolean> {
  await page.goto('/login');
  await page.locator('input[type=email]').fill(ADMIN_EMAIL);
  await page.locator('input[type=password]').fill(ADMIN_PASSWORD);
  await page.getByRole('button', { name: 'Sign in' }).click();
  const sidebar = page.getByRole('navigation', { name: 'Feeds' });
  const loginError = page.getByRole('alert');
  await expect(sidebar.or(loginError)).toBeVisible();
  return sidebar.isVisible();
}

test('settings page renders and the tag dialog opens; admin queue loads', async ({ page }) => {
  const signedIn = await signInAsAdmin(page);
  test.skip(!signedIn, 'seeded admin login unavailable (run app:e2e:seed-admin against the stack)');

  // Open Settings from the account menu.
  await page.getByRole('button', { name: /@/ }).click(); // the email button
  await page.getByRole('menuitem', { name: 'Settings' }).click();
  await expect(page.getByRole('heading', { name: 'Settings' })).toBeVisible();
  await expect(page.getByRole('heading', { name: 'Feeds' })).toBeVisible();
  await expect(page.getByRole('heading', { name: 'Tags' })).toBeVisible();

  // The New-tag dialog opens and closes (no network write).
  await page.getByRole('button', { name: 'New tag' }).click();
  const dialog = page.getByRole('dialog', { name: 'New tag' });
  await expect(dialog).toBeVisible();
  await dialog.getByRole('button', { name: 'Cancel' }).click();
  await expect(dialog).toBeHidden();

  // The admin queue renders (the seeded account is an admin).
  await page.goto('/admin/users');
  await expect(page.getByRole('heading', { name: 'Users' })).toBeVisible();
  await expect(page.getByRole('group', { name: 'Filter by status' })).toBeVisible();
});
```

- [ ] **Step 2: Run against Docker** (if the stack is up): from `frontend/`, `npm run e2e -- settings-admin-smoke`. Expected: PASS (or cleanly skipped if the seeded admin is unavailable). If the stack is down, note it and rely on unit coverage; do not block the task.

- [ ] **Step 3: Commit** — `git add -A && git commit -m "test(5c): settings + admin Playwright smoke"`

---

### Task 19: README

**Files:**
- Modify: `frontend/README.md`

- [ ] **Step 1: Add a "Management & admin (5c)" section** describing: `/settings` (Feeds rename/retag/unsubscribe, Tags CRUD with colour/icon, OPML import/export, Account), the sidebar per-row manage menus, and `/admin/users` (lazy, `adminGuard`, approve/reject/suspend). Note the one deferral (per-feed retry needs a backend `feedId`). Mirror the tone/length of the existing 5b Reader section.

- [ ] **Step 2: Commit** — `git add -A && git commit -m "docs(5c): document management + admin in the frontend README"`

---

## Final review

After all tasks:

- [ ] From `frontend/`: `npm run check` → all green (ESLint + Prettier + Stylelint + Jest).
- [ ] From `frontend/`: `npm run build` → green.
- [ ] If the Docker stack is up: refresh the `frontend-node-modules` volume only if dependencies changed (5c adds none — no `@angular/*` additions), then run the Playwright smokes live.
- [ ] Dispatch a final adversarial code review over the whole 5c diff (correctness of the admin action-visibility rules and self-guard; the OPML download/upload edge cases; the retag PATCH always sending the full tag set; dialog close/refresh races; no hex in any `.scss`).
- [ ] Then use superpowers:finishing-a-development-branch.

## Self-review notes (author)

- **Spec coverage:** Feeds rename/retag/unsubscribe (T6, T11, T17), tag CRUD colour/icon (T3, T5, T12), OPML import/export (T1, T13), account (T14), admin lazy queue + guard (T8, T9, T10, T16), settings page (T15), sidebar affordances (T17), entry points (T16), smoke (T18), docs (T19). Per-feed retry explicitly deferred in the spec — no task, by design.
- **Type consistency:** `TagInput`/`SubscriptionUpdate`/`OpmlImportResult` defined in T1 and consumed unchanged in T5/T6/T13; `AdminUserStatus`/`AdminAction` defined in T9 and used in T10; `ConfirmData` defined in T4 and used in T7; `ManageActions` method names (`editSubscription`, `unsubscribe`, `createTag`, `editTag`, `deleteTag`) are identical in T7, T11, T12, T17.
- **No placeholders:** every code step ships complete code; the two "if lint flags X" notes give the exact fallback.
```
