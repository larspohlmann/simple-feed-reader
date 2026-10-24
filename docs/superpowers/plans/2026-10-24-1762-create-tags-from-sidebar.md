# Create Tags from the Sidebar Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Create tags without leaving the sidebar — a ⋯ menu on the Tags header ("New tag") and "Create tag here" on every top-level tag, with the same actions as action sheets in mobile Organise mode.

**Architecture:** Frontend only. The existing `TagFormDialogComponent` gains a discriminated data type (edit a tag / create one under an optional parent), and `ManageActions.createTag(parentId)` passes the preset through. The sidebar's shared `#sectionHead` template gets two optional context slots — a fine-pointer menu template and an Organise-mode sheet opener — that only the Tags head fills. `SidebarRowActions` gains the Tags-head sheet and a "Create tag here" action on the root-tag sheet.

**Tech Stack:** Angular 20 (standalone, signals), CDK Dialog, Transloco, Jest (jsdom).

**Spec:** GitHub issue #1762 (design agreed in chat; no separate spec file).

## Global Constraints

- Branch `feature/1762-create-tags-from-sidebar`; commits `feat(#1762): …`; no Claude attribution anywhere.
- Child tags never get "Create tag here" (tags nest one level). The rule is `tag.parentId === null`.
- Desktop row/head menus render only when `!screen.isCoarse()`; on coarse pointers the head sheet exists only in Organise mode. Outside Organise, mobile is unchanged.
- With zero tags the Tags card stays hidden (`@if (tagTree().length)` untouched).
- The preset parent stays editable in the dialog.
- No new comments unless a reader would get the code wrong without one (CLAUDE.md). No hex/px in `.scss`.
- Frontend tests run inside the Docker frontend container: `docker compose exec -T frontend npx jest <path>` from the repo root. Only one Jest run at a time (container OOM).
- New i18n keys in **both** `frontend/public/i18n/en.json` and `de.json`.

---

### Task 1: Tag dialog accepts a preset parent

**Files:**
- Modify: `frontend/src/app/reader/feeds/manage/tag-form-dialog.component.ts`
- Modify: `frontend/src/app/reader/feeds/manage/manage-actions.service.ts:211-230`
- Test: `frontend/src/app/reader/feeds/manage/tag-form-dialog.component.spec.ts`
- Test: `frontend/src/app/reader/feeds/manage/manage-actions.service.spec.ts:310-317`

**Interfaces:**
- Produces: `export type TagFormData = { kind: 'edit'; tag: TagDto } | { kind: 'create'; parentId: number | null };` exported from `tag-form-dialog.component.ts`.
- Produces: `ManageActions.createTag(parentId: number | null = null): void` (existing no-arg callers keep working, e.g. `settings/organise/organise-section.component.html:45`).

- [ ] **Step 1: Write the failing dialog tests**

In `tag-form-dialog.component.spec.ts`, import the type and switch `mount` to it:

```ts
import { TagFormDialogComponent, TagFormData } from './tag-form-dialog.component';

const createAtTop: TagFormData = { kind: 'create', parentId: null };
const editing = (tag: TagDto): TagFormData => ({ kind: 'edit', tag });
```

Change `function mount(data: TagDto | null, tags: TagDto[] = [])` to `function mount(data: TagFormData, tags: TagDto[] = [])`. Replace every `mount(null…)` with `mount(createAtTop…)` and every `mount(tagOf(…)…)` / `mount({ id: 4, … })` with `mount(editing(…)…)`.

Add:

```ts
  it('preselects the preset parent and sends it', () => {
    const fixture = mount({ kind: 'create', parentId: 1 }, [tagOf(1, 'Reading', null)]);
    const select = (fixture.nativeElement as HTMLElement).querySelector(
      'select#tag-parent',
    ) as HTMLSelectElement;
    expect(select.value).toBe('1');
    expect(fixture.componentInstance.isEdit).toBe(false);
    fixture.componentInstance.form.controls.name.setValue('Long reads');
    fixture.componentInstance.submit();
    const testRequest = ctrl.expectOne('https://api.test/api/tags');
    expect(testRequest.request.method).toBe('POST');
    expect(testRequest.request.body).toEqual({
      name: 'Long reads',
      color: null,
      icon: null,
      parentId: 1,
    });
    testRequest.flush({ tag: tagOf(9, 'Long reads', 1) });
  });
```

- [ ] **Step 2: Write the failing ManageActions tests**

In `manage-actions.service.spec.ts`, after `'createTag: reloads tags when the dialog returns a tag'`:

```ts
  it('createTag: opens the dialog creating at the top level by default', () => {
    closed = undefined;
    service.createTag();
    expect(open).toHaveBeenCalledWith(expect.anything(), {
      data: { kind: 'create', parentId: null },
      panelClass: 'app-dialog',
    });
  });

  it('createTag: presets the given parent', () => {
    closed = undefined;
    service.createTag(7);
    expect(open).toHaveBeenCalledWith(expect.anything(), {
      data: { kind: 'create', parentId: 7 },
      panelClass: 'app-dialog',
    });
  });

  it('editTag: opens the dialog editing the tag', () => {
    closed = undefined;
    service.editTag(tag);
    expect(open).toHaveBeenCalledWith(expect.anything(), {
      data: { kind: 'edit', tag },
      panelClass: 'app-dialog',
    });
  });
```

- [ ] **Step 3: Run both specs to verify they fail**

Run (repo root): `docker compose exec -T frontend npx jest src/app/reader/feeds/manage/tag-form-dialog.component.spec.ts src/app/reader/feeds/manage/manage-actions.service.spec.ts`
Expected: FAIL — `TagFormData` not exported / data shape mismatch.

- [ ] **Step 4: Implement the dialog data type**

In `tag-form-dialog.component.ts`, export the type above the `@Component`:

```ts
export type TagFormData = { kind: 'edit'; tag: TagDto } | { kind: 'create'; parentId: number | null };
```

Replace the data/derived fields:

```ts
  readonly data = inject<TagFormData>(DIALOG_DATA);
  private readonly api = inject(ReaderApi);
  private readonly fb = inject(NonNullableFormBuilder);
  private readonly tagsStore = inject(TagsStore);

  private readonly editedTag = this.data.kind === 'edit' ? this.data.tag : null;
  readonly isEdit = this.editedTag !== null;
  readonly titleKey = this.isEdit ? 'dialog.tagForm.editTitle' : 'dialog.tagForm.newTitle';

  readonly form = this.fb.group({
    name: [this.editedTag?.name ?? '', [Validators.required, Validators.maxLength(100)]],
  });
  readonly color = signal<string | null>(this.editedTag?.color ?? null);
  readonly icon = signal<string | null>(this.editedTag?.icon ?? null);
  readonly loading = signal(false);
  readonly error = signal<string | null>(null);
  readonly parentId = signal<number | null>(
    this.data.kind === 'edit' ? this.data.tag.parentId : this.data.parentId,
  );
  readonly parentError = signal<string | null>(null);
  readonly parentOptions = computed(() =>
    this.tagsStore.rootTags().filter((tag) => tag.id !== this.editedTag?.id),
  );
  readonly hasChildren = computed(
    () =>
      this.editedTag !== null &&
      this.tagsStore.tags().some((tag) => tag.parentId === this.editedTag?.id),
  );
```

In `submit()`, replace the request selection:

```ts
    const request =
      this.editedTag !== null
        ? this.api.updateTag(this.editedTag.id, body)
        : this.api.createTag(body);
```

(Removes the `this.data!.id` non-null assertion.) The template uses only `isEdit`-independent fields, so it is unchanged.

- [ ] **Step 5: Implement `createTag(parentId)` / `editTag` data**

In `manage-actions.service.ts`, import `TagFormData` alongside `TagFormDialogComponent` and change:

```ts
  createTag(parentId: number | null = null): void {
    const data: TagFormData = { kind: 'create', parentId };
    const ref = this.dialog.open<TagDto>(TagFormDialogComponent, {
      data,
      panelClass: 'app-dialog',
    });
    ref.closed.subscribe((tag) => {
      if (tag) this.tags.load();
    });
  }

  editTag(tag: TagDto): void {
    const data: TagFormData = { kind: 'edit', tag };
    const ref = this.dialog.open<TagDto>(TagFormDialogComponent, {
      data,
      panelClass: 'app-dialog',
    });
```

(rest of `editTag` unchanged).

- [ ] **Step 6: Run both specs to verify they pass**

Run: same command as Step 3. Expected: PASS.

- [ ] **Step 7: Commit**

```bash
git add frontend/src/app/reader/feeds/manage/
git commit -m "feat(#1762): tag dialog takes a preset parent for a new tag"
```

---

### Task 2: Action sheets — Tags head sheet and "Create tag here"

**Files:**
- Modify: `frontend/src/app/reader/shell/sidebar/sidebar-row-actions.service.ts` (`openTagSheet`, new `openTagsHeadSheet`)
- Modify: `frontend/public/i18n/en.json`, `frontend/public/i18n/de.json` (the `reader` block next to `"editTag"`, ~line 1206)
- Test: `frontend/src/app/reader/shell/sidebar/sidebar-row-actions.service.spec.ts` (`describe('the tag sheet')`, ~line 170)

**Interfaces:**
- Consumes: `ManageActions.createTag(parentId: number | null = null)` from Task 1.
- Produces: `SidebarRowActions.openTagsHeadSheet(): void`; i18n keys `reader.newTag`, `reader.createTagHere`.

- [ ] **Step 1: Add the i18n keys**

`en.json`, directly after `"editTag": "Edit tag",` in the `reader` block:

```json
    "newTag": "New tag",
    "createTagHere": "Create tag here",
```

`de.json`, directly after `"editTag": "Tag bearbeiten",`:

```json
    "newTag": "Neuer Tag",
    "createTagHere": "Tag hier erstellen",
```

- [ ] **Step 2: Write the failing tests**

In the `the tag sheet` describe: add `const createTag = jest.fn();`, include it in the `mockReset` list and in the `ManageActions` mock (`{ reorderTags, editTag, deleteTag, createTag }`).

Update `'offers edit, move up, move down and, after a divider, delete'` — rename to `'offers edit, create here, move up, move down and, after a divider, delete'` and insert after the edit entry:

```ts
          { id: 'createHere', label: 'Create tag here', symbol: 'add' },
```

Add:

```ts
    it('offers no Create tag here on a child tag', () => {
      const actions = actionsFor(tag(11, 0, 1));
      expect(actions.find((action) => action.id === 'createHere')).toBeUndefined();
    });

    it('creates a tag under the root it was opened on', () => {
      sheetOpen.mockReturnValue(of('createHere'));
      TestBed.inject(SidebarRowActions).openTagSheet(tag(2, 1));
      expect(createTag).toHaveBeenCalledWith(2);
      expect(reorderTags).not.toHaveBeenCalled();
    });

    it('the Tags head sheet offers New tag and creates at the top level', () => {
      sheetOpen.mockReturnValue(of('new'));
      TestBed.inject(SidebarRowActions).openTagsHeadSheet();
      expect(sheetOpen).toHaveBeenCalledWith({
        title: 'Tags',
        actions: [{ id: 'new', label: 'New tag', symbol: 'add' }],
      });
      expect(createTag).toHaveBeenCalledWith();
    });
```

- [ ] **Step 3: Run to verify failure**

Run: `docker compose exec -T frontend npx jest src/app/reader/shell/sidebar/sidebar-row-actions.service.spec.ts`
Expected: FAIL — no `createHere` action, `openTagsHeadSheet` is not a function.

- [ ] **Step 4: Implement**

In `openTagSheet`, insert after the `edit` action:

```ts
          ...(tag.parentId === null
            ? [
                {
                  id: 'createHere',
                  label: this.transloco.translate('reader.createTagHere'),
                  symbol: 'add',
                },
              ]
            : []),
```

and in its subscriber add `if (choice === 'createHere') this.manage.createTag(tag.id);` after the `edit` line.

Add a method after `openTagSheet`:

```ts
  /** ⋯ on the Tags head (Organise): create a top-level tag. */
  openTagsHeadSheet(): void {
    this.sheet
      .open({
        title: this.transloco.translate('reader.tags'),
        actions: [
          { id: 'new', label: this.transloco.translate('reader.newTag'), symbol: 'add' },
        ],
      })
      .pipe(takeUntilDestroyed(this.destroyRef))
      .subscribe((choice) => {
        if (choice === 'new') this.manage.createTag();
      });
  }
```

- [ ] **Step 5: Run to verify pass**

Run: same as Step 3. Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add frontend/public/i18n/en.json frontend/public/i18n/de.json frontend/src/app/reader/shell/sidebar/sidebar-row-actions.service.ts frontend/src/app/reader/shell/sidebar/sidebar-row-actions.service.spec.ts
git commit -m "feat(#1762): Create tag here and the Tags head sheet in Organise mode"
```

---

### Task 3: Sidebar — Tags head ⋯ menu, Organise ⋯ button, root-row item

**Files:**
- Modify: `frontend/src/app/reader/shell/sidebar/section-head-template.directive.ts`
- Modify: `frontend/src/app/reader/shell/sidebar/sidebar.component.html` (tags head ~139, feeds head ~183, tag row menu ~299-316, `#sectionHead` ~353-378)
- Modify: `frontend/src/app/reader/shell/sidebar/sidebar.component.ts` (next to `tagsHead`, ~line 223)
- Test: `frontend/src/app/reader/shell/sidebar/sidebar.component.spec.ts`

**Interfaces:**
- Consumes: `ManageActions.createTag(parentId?)` (Task 1), `SidebarRowActions.openTagsHeadSheet()` and keys `reader.newTag`, `reader.createTagHere` (Task 2).

- [ ] **Step 1: Write the failing tests**

In `sidebar.component.spec.ts`, add `createTag: jest.fn(),` to the `manageActions` mock (line ~29).

Add after `'calls editTag / deleteTag when a tag row menu action is used'` (and update that test's indices: Create tag here is now `buttons[1]`, so the delete click becomes `buttons2[2]`):

```ts
  it('creates a tag under a root tag from its row menu, not under a child', () => {
    const child: TagNode = {
      tag: { id: 21, name: 'Long reads', color: null, icon: null, position: 0, parentId: 20 },
      subscriptions: [],
      children: [],
      unreadCount: 0,
      entryCount: 0,
    };
    const root: TagNode = {
      tag: { id: 20, name: 'Reading', color: null, icon: null, position: 0, parentId: null },
      subscriptions: [],
      children: [child],
      unreadCount: 0,
      entryCount: 0,
    };
    const fixture = mount({ tagTree: [root] });
    const element = fixture.nativeElement as HTMLElement;
    // Child rows render only once their root is expanded (`tagGroups`).
    element.querySelector<HTMLButtonElement>('.tag .chevzone')!.click();
    fixture.detectChanges();
    const menuItems = (tagName: string) => {
      const row = [...element.querySelectorAll('.tag')].find((candidate) =>
        candidate.textContent?.includes(tagName),
      )!;
      row.querySelector<HTMLButtonElement>('.dots')!.click();
      fixture.detectChanges();
      return [...row.querySelectorAll<HTMLButtonElement>('.pop [role="menuitem"]')];
    };

    expect(menuItems('Long reads').map((item) => item.textContent?.trim())).not.toContain(
      'Create tag here',
    );
    menuItems('Long reads'); // closes it again
    menuItems('Reading')
      .find((item) => item.textContent?.trim() === 'Create tag here')!
      .click();
    fixture.detectChanges();

    expect(manageActions.createTag).toHaveBeenCalledWith(20);
    expect(element.querySelector('.tag .pop')).toBeNull();
  });

  it('creates a top-level tag from the Tags head menu', () => {
    const fixture = mount({ tagTree: [tagNodeFixture] });
    const element = fixture.nativeElement as HTMLElement;
    expect(element.querySelector('.tags-head .slot')).toBeNull();
    expect(element.querySelector('.feeds-head .dots')).toBeNull();

    element.querySelector<HTMLButtonElement>('.tags-head .dots')!.click();
    fixture.detectChanges();
    const items = element.querySelectorAll<HTMLButtonElement>('.tags-head .pop [role="menuitem"]');
    expect([...items].map((item) => item.textContent?.trim())).toEqual(['New tag']);
    items[0].click();
    fixture.detectChanges();

    expect(manageActions.createTag).toHaveBeenCalledWith();
    expect(element.querySelector('.tags-head .pop')).toBeNull();
  });
```

In `describe('organise mode')`, add:

```ts
  it('the Tags head carries a ⋯ that opens the Tags sheet while organising', () => {
    const fixture = mount({ coarse: true, organising: true, tagTree: tree, sheetChoice: 'new' });
    const element = fixture.nativeElement as HTMLElement;
    expect(element.querySelector('.feeds-head .dots')).toBeNull();

    element.querySelector<HTMLButtonElement>('.tags-head .dots')!.click();

    expect(TestBed.inject(ActionSheet).open).toHaveBeenCalledWith(
      expect.objectContaining({ title: 'Tags' }),
    );
    expect(manageActions.createTag).toHaveBeenCalledWith();
  });
```

The existing `'coarse navigation shows the trailing chevron and no inline menu'` asserts `element.querySelector('.dots')` is null on coarse outside Organise — it now also guards that the head menu stays desktop-only. Keep it.

- [ ] **Step 2: Run to verify failure**

Run: `docker compose exec -T frontend npx jest src/app/reader/shell/sidebar/sidebar.component.spec.ts`
Expected: FAIL — no `.tags-head .dots`, no "Create tag here" item.

- [ ] **Step 3: Extend the section-head context type**

In `section-head-template.directive.ts`, import `TemplateRef` from `@angular/core` and change the context:

```ts
interface SectionHeadContext {
  readonly $implicit: SectionHeadView;
  readonly menu: TemplateRef<unknown> | null;
  readonly sheet: (() => void) | null;
}
```

- [ ] **Step 4: Expose the sheet opener on the component**

In `sidebar.component.ts`, directly after the `tagsHead` field:

```ts
  protected readonly openTagsHeadSheet = (): void => this.rows.openTagsHeadSheet();
```

- [ ] **Step 5: Wire the template**

Tags head outlet (~line 139):

```html
        <ng-container
          *ngTemplateOutlet="
            sectionHead;
            context: { $implicit: tagsHead, menu: tagsHeadMenu, sheet: openTagsHeadSheet }
          "
        />
```

Feeds head outlet (~line 183):

```html
        <ng-container
          *ngTemplateOutlet="sectionHead; context: { $implicit: feedsHead, menu: null, sheet: null }"
        />
```

In the tag row menu's `<ng-template>` (~line 304), between the Edit button and `<hr />`:

```html
                  @if (node.tag.parentId === null) {
                    <button
                      role="menuitem"
                      (click)="manage.createTag(node.tag.id); rows.closeMenu()"
                    >
                      {{ 'reader.createTagHere' | transloco }}
                    </button>
                  }
```

Replace the `#sectionHead` template (~line 353) with:

```html
  <ng-template #sectionHead appSectionHead let-head let-menu="menu" let-sheet="sheet">
    @if (organising()) {
      <span class="label">{{ head.label | transloco }}</span>
      @if (sheet) {
        <button
          class="dots"
          type="button"
          aria-haspopup="dialog"
          [attr.aria-label]="'reader.manage' | transloco: { name: (head.label | transloco) }"
          (click)="sheet()"
        >
          <app-icon name="more_horiz" size="md" />
        </button>
      }
    } @else {
      <a
        class="section-link"
        [routerLink]="['/']"
        [queryParams]="selectionQueryParams({ group: head.group })"
        queryParamsHandling="merge"
      >
        <span class="label">{{ head.label | transloco }}</span>
        @if (groupUnread()[head.group] > 0) {
          <span class="section-count">{{ groupUnread()[head.group] }}</span>
        }
      </a>
      @if (menu && !screen.isCoarse()) {
        <app-sidebar-row-menu
          [menuKey]="head.group + '-head'"
          [label]="'reader.manage' | transloco: { name: (head.label | transloco) }"
        >
          <ng-template><ng-container *ngTemplateOutlet="menu" /></ng-template>
        </app-sidebar-row-menu>
      } @else {
        <span class="slot" aria-hidden="true"></span>
      }
      <button
        class="section-chevron"
        type="button"
        [attr.aria-expanded]="head.section.expanded()"
        [attr.aria-label]="head.toggleLabel | transloco"
        (click)="head.section.toggle()"
      >
        <app-icon [name]="head.section.expanded() ? 'expand_more' : 'chevron_right'" size="dense" />
      </button>
    }
  </ng-template>

  <ng-template #tagsHeadMenu>
    <button role="menuitem" (click)="manage.createTag(); rows.closeMenu()">
      {{ 'reader.newTag' | transloco }}
    </button>
  </ng-template>
```

The row menu's host carries the same negative right margin as `.slot` (`_sidebar-row.scss` `row-menu` mixin), so the chevron column stays aligned with the tag rows; no SCSS change is expected. If the live check shows the head ⋯ misaligned, fix it in `sidebar.component.scss` with tokens only.

- [ ] **Step 6: Run to verify pass**

Run: same as Step 2. Expected: PASS (whole sidebar spec, including the updated edit/delete indices).

- [ ] **Step 7: Commit**

```bash
git add frontend/src/app/reader/shell/sidebar/
git commit -m "feat(#1762): New tag on the Tags head, Create tag here on root tags"
```

---

### Task 4: Gates and live check

**Files:** none new (fix-ups only if a gate fails).

- [ ] **Step 1: Full frontend gate**

Run (repo root): `docker compose exec -T frontend npm run check`
Expected: ESLint, Prettier, Stylelint, tsc and Jest all green. Prettier is 100 columns — run `docker compose exec -T frontend npx prettier --write <file>` on any file it flags.

- [ ] **Step 2: Template-strict build**

Run: `docker compose exec -T frontend npx ng build`
Expected: success (catches template type errors `npm run check` misses).

- [ ] **Step 3: Live check on http://localhost:4200**

Verify the frontend container serves the current code first (stale-chunk trap). Desktop width: Tags head shows ⋯ between label/count and chevron, aligned with the tag rows' ⋯ column; "New tag" opens the dialog with "No parent"; a root tag's ⋯ shows "Create tag here" and the dialog opens with that parent selected; a child tag's ⋯ has no such item. Mobile viewport (coarse): no head ⋯ outside Organise; in Organise the Tags head has ⋯ → sheet "New tag"; a root tag's sheet has "Create tag here", a child's does not. Create one tag each way, confirm it lands where expected, then delete the test tags.

- [ ] **Step 4: Commit any fix-ups**

```bash
git add -A frontend/
git commit -m "fix(#1762): <what the gate or live check caught>"
```
