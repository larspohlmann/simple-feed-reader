# Split ManageActions Implementation Plan (#2111)

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Split `ManageActions` into `TagActions`, `BulkSubscriptionActions` and a slimmer `ManageActions` (single-feed actions), with each consumer injecting only the group it uses.

**Architecture:** Two new root-provided services sit next to `manage-actions.service.ts` in `frontend/src/app/reader/feeds/manage/`, named like their siblings (`*-actions.service.ts` / `*Actions`, as `mark-read-actions.service.ts` → `MarkReadActions`). Methods move verbatim; no behaviour changes. The one piece of private logic two services share — "reload feeds, and saved searches when the saved-search flag was written" (`reloadFeedsAfter` today) — becomes the exported function `reloadAfterFlagWrite` in the existing `reload-after.ts`, next to `reloadAfter`. `unsubscribe-messages.ts`, `TagPlacement`, `ConfirmService` and `ToastService` are already shared modules and are imported, not copied.

**Tech Stack:** Angular 20 (standalone, signals), RxJS, CDK Dialog, Transloco, Jest (jsdom), ESLint/Prettier.

**Spec:** GitHub issue #2111 (`gh issue view 2111`). #2106, which the issue says to land first, is merged (`9c6f9e554`).

## Global Constraints

- Branch `refactor/2111-split-manage-actions` is already checked out. Concurrent sessions may share the checkout: run `git status` before any `checkout`, `reset` or `stash`.
- Commit format: `refactor(#2111): <summary>`. No Claude attribution anywhere (no `Co-Authored-By`, no "Generated with").
- No behaviour change. Methods move with their bodies unchanged except where a step says otherwise.
- ESLint `max-lines` is 300 (blank and comment lines skipped). Every new file stays far below it (new services under 110 lines, new specs under 320 raw lines, and specs have `max-lines` off anyway). `reader-shell.component.ts` already counts about 294 lines and this plan adds 2 to it (see Task 1 Step 9). Do not add anything else to that file.
- `@typescript-eslint/max-params` is 3. `unicorn/prevent-abbreviations` flags `subs` as a variable or parameter name (properties are exempt), so new parameters are spelled out (`subscriptions`, not `subs`).
- Comments: the default is no comment. Keep a comment to one line where possible. Moved docblocks move with their method. Delete orphaned or misplaced ones (Task 2 fixes two).
- Each task ends green: `npm run check:static` from `frontend/`, the touched specs in the Docker frontend container, and `npm run build` (`npm run check` does not type-check templates, and this plan edits templates).
- Jest runs **inside the Docker frontend container** (`docker compose exec -T frontend …`, workdir `/app` = `frontend/`). **Never start two Jest runs at once**, because they OOM. That includes the native `npm test` that `npm run check` runs.
- After editing, run `npx prettier --write` on every touched `.ts`/`.html` file before the static check. The code below is pre-formatted, but the perl renames can push lines over 100 characters.

## File map

| File | Change |
|---|---|
| `frontend/src/app/reader/feeds/manage/tag-actions.service.ts` | **Create**: `TagActions` (createTag, editTag, deleteTag, reorderTags, moveTag) |
| `frontend/src/app/reader/feeds/manage/tag-actions.service.spec.ts` | **Create**: the tag tests moved out of `manage-actions.service.spec.ts` |
| `frontend/src/app/reader/feeds/manage/bulk-subscription-actions.service.ts` | **Create**: `BulkSubscriptionActions` (bulkAddTag, bulkRemoveTag, bulkSetFlags, bulkUnsubscribe, private bulkPatch) |
| `frontend/src/app/reader/feeds/manage/bulk-subscription-actions.service.spec.ts` | **Create**: the bulk tests moved out |
| `frontend/src/app/reader/feeds/manage/reload-after.ts` | Add `reloadAfterFlagWrite` (shared by ManageActions.patchFlags and BulkSubscriptionActions.bulkPatch) |
| `frontend/src/app/reader/feeds/manage/manage-actions.service.ts` | Loses the tag and bulk groups; keeps the single-feed actions |
| `frontend/src/app/reader/feeds/manage/manage-actions.service.spec.ts` | Loses the moved tests; `addFeed` test re-homed |
| `frontend/src/app/reader/shell/reader-shell.component.{ts,html,spec.ts}` | `editTag` → `TagActions` |
| `frontend/src/app/reader/shell/sidebar/sidebar-row-actions.service{,.spec}.ts` | tag sheet → `TagActions` |
| `frontend/src/app/reader/shell/sidebar/sidebar.component{,.spec}.ts` | `moveTag` → `TagActions` |
| `frontend/src/app/reader/shell/sidebar/sidebar-tag-row-menu.component.{ts,html,spec.ts}` | `ManageActions` → `TagActions` |
| `frontend/src/app/reader/shell/sidebar/sidebar-tags-head-menu.component.{ts,html}` | `ManageActions` → `TagActions` |
| `frontend/src/app/settings/organise/organise-tag-group.component.{ts,html,spec.ts}` | editTag/deleteTag → `TagActions` |
| `frontend/src/app/settings/organise/organise-section.component.{ts,html,spec.ts}` | tag and bulk calls → `TagActions` / `BulkSubscriptionActions` |
| `frontend/src/app/settings/organise/bulk-tag-dialog.component.ts` | docblock names `BulkSubscriptionActions` |
| `frontend/README.md` | the `ManageActions` bullet names the three services |

**Unchanged consumers (they use only single-feed actions):** `reader/feed-exclusions.ts` + `.spec.ts` (setIncludeIn×3), `sidebar-feed-row.component.ts` (editSubscription, unsubscribe), `settings/organise/health/health-error-dialog.component.ts` + `.spec.ts` (retryFeed, unsubscribe), `sidebar-social-section.component.spec.ts` (stubs `ManageActions` for the feed rows; `SidebarRowActions` is stubbed whole, so no `TagActions` is reached).

---

### Task 1: Extract TagActions

**Files:**
- Create: `frontend/src/app/reader/feeds/manage/tag-actions.service.ts`
- Create: `frontend/src/app/reader/feeds/manage/tag-actions.service.spec.ts`
- Modify: `frontend/src/app/reader/feeds/manage/manage-actions.service.ts`
- Modify: `frontend/src/app/reader/feeds/manage/manage-actions.service.spec.ts`
- Modify: `frontend/src/app/reader/shell/reader-shell.component.ts`, `.html`, `.spec.ts`
- Modify: `frontend/src/app/reader/shell/sidebar/sidebar-row-actions.service.ts`, `.spec.ts`
- Modify: `frontend/src/app/reader/shell/sidebar/sidebar.component.ts`, `.spec.ts`
- Modify: `frontend/src/app/reader/shell/sidebar/sidebar-tag-row-menu.component.ts`, `.html`, `.spec.ts`
- Modify: `frontend/src/app/reader/shell/sidebar/sidebar-tags-head-menu.component.ts`, `.html`
- Modify: `frontend/src/app/settings/organise/organise-tag-group.component.ts`, `.html`, `.spec.ts`
- Modify: `frontend/src/app/settings/organise/organise-section.component.ts`, `.html`, `.spec.ts`

**Interfaces:**
- Produces: `TagActions` (`@Injectable({ providedIn: 'root' })`, file `tag-actions.service.ts`) with
  `createTag(parentId: number | null = null): void`, `editTag(tag: TagDto): void`, `deleteTag(tag: TagDto): void`, `reorderTags(tagIds: readonly number[]): void`, `moveTag(tag: TagDto, drop: TagDrop): void`.
- `ManageActions` no longer has those five methods after this task.

- [ ] **Step 1: Create `tag-actions.service.ts`**

```ts
import { Injectable, inject } from '@angular/core';
import { Dialog } from '@angular/cdk/dialog';
import { TranslocoService } from '@jsverse/transloco';
import { ReaderApi } from '../../reader-api';
import { SubscriptionsStore } from '../../state/subscriptions.store';
import { TagsStore } from '../../state/tags.store';
import { TagDto } from '../../models';
import { TagDrop } from '../../tag-drop';
import { TagPlacement } from './tag-placement.service';
import { ConfirmData } from '../../../shared/confirm-dialog/confirm-dialog.component';
import { ConfirmService } from '../../../shared/confirm-dialog/confirm.service';
import { TagFormData, TagFormDialogComponent } from './tag-form-dialog.component';

/** Creates, edits, deletes and places tags for the sidebar and the Organise page alike. The tag
 *  dialog performs its own API write; a truthy close reloads the affected stores. */
@Injectable({ providedIn: 'root' })
export class TagActions {
  private readonly dialog = inject(Dialog);
  private readonly confirm = inject(ConfirmService);
  private readonly api = inject(ReaderApi);
  private readonly subs = inject(SubscriptionsStore);
  private readonly tags = inject(TagsStore);
  private readonly tagPlacement = inject(TagPlacement);
  private readonly i18n = inject(TranslocoService);

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
    ref.closed.subscribe((updated) => {
      if (!updated) return;
      this.tags.load();
      this.subs.reloadQuietly(); // embedded tag colour/name on feeds changed too
    });
  }

  deleteTag(tag: TagDto): void {
    const data: ConfirmData = {
      title: this.i18n.translate('manage.deleteTagTitle'),
      message: this.i18n.translate('manage.deleteTagMessage', { name: tag.name }),
      confirmLabel: this.i18n.translate('manage.deleteConfirm'),
      danger: true,
    };
    this.confirm.confirmThen(data, () => {
      this.api.deleteTag(tag.id).subscribe({
        next: () => {
          this.tags.load();
          this.subs.reloadQuietly();
        },
      });
    });
  }

  reorderTags(tagIds: readonly number[]): void {
    this.tagPlacement.reorder(tagIds);
  }

  moveTag(tag: TagDto, drop: TagDrop): void {
    this.tagPlacement.move(tag, drop);
  }
}
```

- [ ] **Step 2: Create `tag-actions.service.spec.ts`** (the tag tests from `manage-actions.service.spec.ts`, moved verbatim apart from `service` now being a `TagActions`)

```ts
import { TestBed } from '@angular/core/testing';
import { provideTranslocoTesting } from '../../../../testing/transloco-testing';
import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { Dialog } from '@angular/cdk/dialog';
import { of } from 'rxjs';
import { API_BASE_URL } from '../../../core/api';
import { TagActions } from './tag-actions.service';
import { SubscriptionsStore } from '../../state/subscriptions.store';
import { TagsStore } from '../../state/tags.store';
import { TagDrop } from '../../tag-drop';
import { TagDto } from '../../models';

const tag: TagDto = { id: 3, name: 'Tech', color: null, icon: null, position: 0, parentId: null };

describe('TagActions', () => {
  let service: TagActions;
  let ctrl: HttpTestingController;
  let closed: unknown;
  const open = jest.fn(() => ({ closed: of(closed) }));

  beforeEach(() => {
    TestBed.configureTestingModule({
      imports: [provideTranslocoTesting()],
      providers: [
        provideHttpClient(),
        provideHttpClientTesting(),
        { provide: API_BASE_URL, useValue: 'https://api.test' },
        { provide: Dialog, useValue: { open } },
      ],
    });
    service = TestBed.inject(TagActions);
    ctrl = TestBed.inject(HttpTestingController);
    open.mockClear();
  });
  afterEach(() => ctrl.verify());

  it('deleteTag: on confirm, DELETEs then reloads tags + subs', () => {
    closed = true;
    const tagSpy = jest
      .spyOn(TestBed.inject(TagsStore), 'load')
      .mockImplementation(() => undefined);
    const subSpy = jest
      .spyOn(TestBed.inject(SubscriptionsStore), 'reloadQuietly')
      .mockImplementation(() => undefined);
    service.deleteTag(tag);
    ctrl.expectOne('https://api.test/api/tags/3').flush(null);
    expect(tagSpy).toHaveBeenCalled();
    expect(subSpy).toHaveBeenCalled();
  });

  it('reorderTags: PATCHes /api/tags/reorder then reloads tags', () => {
    const spy = jest.spyOn(TestBed.inject(TagsStore), 'load').mockImplementation(() => undefined);
    service.reorderTags([3, 1, 2]);
    const testRequest = ctrl.expectOne('https://api.test/api/tags/reorder');
    expect(testRequest.request.method).toBe('PATCH');
    expect(testRequest.request.body).toEqual({ tagIds: [3, 1, 2] });
    testRequest.flush({ tags: [] });
    expect(spy).toHaveBeenCalled();
  });

  it('reorderTags: repositions only the listed siblings and keeps every other tag', () => {
    jest.spyOn(TestBed.inject(TagsStore), 'load').mockImplementation(() => undefined);
    const tags = TestBed.inject(TagsStore);
    const reading: TagDto = {
      id: 1,
      name: 'Reading',
      color: null,
      icon: null,
      position: 0,
      parentId: null,
    };
    const longReads: TagDto = {
      id: 2,
      name: 'Long reads',
      color: null,
      icon: null,
      position: 0,
      parentId: 1,
    };
    const tech: TagDto = {
      id: 4,
      name: 'Tech',
      color: null,
      icon: null,
      position: 1,
      parentId: null,
    };
    tags.tags.set([reading, longReads, tech]);

    service.reorderTags([4, 1]);

    expect(tags.tags().map((tag) => [tag.id, tag.position])).toEqual([
      [2, 0],
      [4, 0],
      [1, 1],
    ]);
    ctrl.expectOne('https://api.test/api/tags/reorder').flush({ tags: [] });
  });

  describe('moveTag', () => {
    const tagA: TagDto = { id: 1, name: 'A', color: null, icon: null, position: 0, parentId: null };
    const tagB: TagDto = {
      id: 2,
      name: 'B',
      color: '#fff',
      icon: 'x',
      position: 1,
      parentId: null,
    };
    let tagsStore: TagsStore;
    let tagsLoad: jest.SpyInstance;
    let subscriptionsLoad: jest.SpyInstance;

    const dropUnder = (parentId: number | null, order: number[]): TagDrop => ({
      parentId,
      order,
      mark: { kind: 'before', tagId: order[0] },
    });

    beforeEach(() => {
      tagsStore = TestBed.inject(TagsStore);
      tagsStore.tags.set([tagA, tagB]);
      tagsLoad = jest.spyOn(tagsStore, 'load').mockImplementation(() => undefined);
      subscriptionsLoad = jest
        .spyOn(TestBed.inject(SubscriptionsStore), 'reloadQuietly')
        .mockImplementation(() => undefined);
    });

    it('only reorders when the parent stays, showing the new positions at once', () => {
      service.moveTag(tagB, dropUnder(null, [2, 1]));

      expect(tagsStore.tags().map(({ id, position }) => [id, position])).toEqual([
        [2, 0],
        [1, 1],
      ]);
      const reorder = ctrl.expectOne('https://api.test/api/tags/reorder');
      expect(reorder.request.body).toEqual({ tagIds: [2, 1] });
      reorder.flush({ tags: [] });
      ctrl.expectNone('https://api.test/api/tags/2');
    });

    it('re-parents, then writes the new sibling order, then reloads tags and feeds', () => {
      service.moveTag(tagB, dropUnder(1, [2]));

      const reparent = ctrl.expectOne('https://api.test/api/tags/2');
      expect(reparent.request.body).toEqual({ name: 'B', color: '#fff', icon: 'x', parentId: 1 });
      ctrl.expectNone('https://api.test/api/tags/reorder');
      reparent.flush({ tag: tagB });
      const reorder = ctrl.expectOne('https://api.test/api/tags/reorder');
      expect(reorder.request.body).toEqual({ tagIds: [2] });
      reorder.flush({ tags: [] });
      expect(tagsLoad).toHaveBeenCalled();
      expect(subscriptionsLoad).toHaveBeenCalled();
    });

    it('shows the new parent at once', () => {
      service.moveTag(tagB, dropUnder(1, [2]));

      expect(tagsStore.tags().find((tag) => tag.id === 2)).toEqual(
        expect.objectContaining({ parentId: 1, position: 0 }),
      );
      ctrl.expectOne('https://api.test/api/tags/2').flush({ tag: tagB });
      ctrl.expectOne('https://api.test/api/tags/reorder').flush({ tags: [] });
    });

    it('skips the reorder and still reloads when the re-parent is refused', () => {
      service.moveTag(tagB, dropUnder(1, [2]));

      ctrl
        .expectOne('https://api.test/api/tags/2')
        .flush(null, { status: 422, statusText: 'Unprocessable' });
      ctrl.expectNone('https://api.test/api/tags/reorder');
      expect(tagsLoad).toHaveBeenCalled();
      expect(subscriptionsLoad).toHaveBeenCalled();
    });
  });

  it('createTag: reloads tags when the dialog returns a tag', () => {
    closed = tag;
    const tagSpy = jest
      .spyOn(TestBed.inject(TagsStore), 'load')
      .mockImplementation(() => undefined);
    service.createTag();
    expect(tagSpy).toHaveBeenCalled();
  });

  it.each([
    ['at the top level by default', undefined, null],
    ['under the given parent', 7, 7],
  ])('createTag: opens the dialog creating %s', (_case, parentId, expectedParentId) => {
    closed = undefined;
    service.createTag(parentId);
    expect(open).toHaveBeenCalledWith(expect.anything(), {
      data: { kind: 'create', parentId: expectedParentId },
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
});
```

- [ ] **Step 3: Remove the tag group from `manage-actions.service.ts`**

Delete these imports:
```ts
import { TagDrop } from '../../tag-drop';
import { TagPlacement } from './tag-placement.service';
import { ConfirmData } from '../../../shared/confirm-dialog/confirm-dialog.component';
import { TagFormData, TagFormDialogComponent } from './tag-form-dialog.component';
```
(Keep `TagDto` in the `../../models` import. `bulkAddTag`/`bulkRemoveTag` still use it until Task 2.)

Delete the field `  private readonly tagPlacement = inject(TagPlacement);`.

Delete these five methods whole, docblock-free as they are: `reorderTags(tagIds: readonly number[])`, `moveTag(tag: TagDto, drop: TagDrop)`, `createTag(parentId: number | null = null)`, `editTag(tag: TagDto)`, `deleteTag(tag: TagDto)`. Their bodies are the ones now in `TagActions` (Step 1).

- [ ] **Step 4: Remove the moved tests from `manage-actions.service.spec.ts`**

Delete `import { TagDrop } from '../../tag-drop';`. Then delete each of these blocks whole, from the opening `it(`/`it.each(`/`describe(` line through its matching closing `});`:
- `it('deleteTag: on confirm, DELETEs then reloads tags + subs', …)`
- `it('reorderTags: PATCHes /api/tags/reorder then reloads tags', …)`
- `it('reorderTags: repositions only the listed siblings and keeps every other tag', …)`
- `describe('moveTag', …)`
- `it('createTag: reloads tags when the dialog returns a tag', …)`
- `it.each([…])('createTag: opens the dialog creating %s', …)`
- `it('editTag: opens the dialog editing the tag', …)`

- [ ] **Step 5: Sidebar row actions**

`frontend/src/app/reader/shell/sidebar/sidebar-row-actions.service.ts`:
- After `import { ManageActions } from '../../feeds/manage/manage-actions.service';` add
  `import { TagActions } from '../../feeds/manage/tag-actions.service';`
- After `  private readonly manage = inject(ManageActions);` add
  `  private readonly tagActions = inject(TagActions);`
- Rename the five tag calls (editTag, createTag ×2, deleteTag, reorderTags). `flipFeedFlag(this.manage, …)` and the feed calls stay:
  ```bash
  perl -pi -e 's/this\.manage\.(createTag|editTag|deleteTag|reorderTags)\b/this.tagActions.$1/g' frontend/src/app/reader/shell/sidebar/sidebar-row-actions.service.ts
  ```

`frontend/src/app/reader/shell/sidebar/sidebar-row-actions.service.spec.ts` (the service injects both groups, so every TestBed provides both):
- After `import { ManageActions } from '../../feeds/manage/manage-actions.service';` add
  `import { TagActions } from '../../feeds/manage/tag-actions.service';`
- The two `{ provide: ManageActions, useValue: {} },` providers (the "Home's label" test and the network-sheet `beforeEach`) each get a `TagActions` stub after them:
  ```bash
  perl -pi -e 's/^(\s*)\{ provide: ManageActions, useValue: \{\} \},\n/$1\{ provide: ManageActions, useValue: {} },\n$1\{ provide: TagActions, useValue: {} },\n/' frontend/src/app/reader/shell/sidebar/sidebar-row-actions.service.spec.ts
  ```
- In the tag-sheet `beforeEach`, replace
  ```ts
          { provide: ManageActions, useValue: { reorderTags, editTag, deleteTag, createTag } },
  ```
  with
  ```ts
          { provide: ManageActions, useValue: {} },
          { provide: TagActions, useValue: { reorderTags, editTag, deleteTag, createTag } },
  ```

- [ ] **Step 6: Sidebar component**

`frontend/src/app/reader/shell/sidebar/sidebar.component.ts`:
- After `import { ManageActions } from '../../feeds/manage/manage-actions.service';` add
  `import { TagActions } from '../../feeds/manage/tag-actions.service';`
- After `  protected readonly manage = inject(ManageActions);` add
  `  private readonly tagActions = inject(TagActions);`
- In `endTagDrag()`: `this.manage.moveTag(ended.tag, ended.drop);` → `this.tagActions.moveTag(ended.tag, ended.drop);`

`frontend/src/app/reader/shell/sidebar/sidebar.component.spec.ts` (the component injects both groups, and its tag-row/tags-head menus inject `TagActions`):
- After `import { ManageActions } from '../../feeds/manage/manage-actions.service';` add
  `import { TagActions } from '../../feeds/manage/tag-actions.service';`
- Replace the stub block and its reset:
  ```ts
  const manageActions = {
    createTag: jest.fn(),
    editTag: jest.fn(),
    deleteTag: jest.fn(),
    editSubscription: jest.fn(),
    unsubscribe: jest.fn(),
    setIncludeInAllItems: jest.fn(),
    setIncludeInForYou: jest.fn(),
    setIncludeInSavedSearches: jest.fn(),
    moveFeedToTag: jest.fn(),
    reorderTags: jest.fn(),
    moveTag: jest.fn(),
    reorderUntagged: jest.fn(),
    reorderTagFeeds: jest.fn(),
  };

  beforeEach(() => Object.values(manageActions).forEach((spy) => spy.mockReset()));
  ```
  with
  ```ts
  const manageActions = {
    editSubscription: jest.fn(),
    unsubscribe: jest.fn(),
    setIncludeInAllItems: jest.fn(),
    setIncludeInForYou: jest.fn(),
    setIncludeInSavedSearches: jest.fn(),
    moveFeedToTag: jest.fn(),
    reorderUntagged: jest.fn(),
    reorderTagFeeds: jest.fn(),
  };

  const tagActions = {
    createTag: jest.fn(),
    editTag: jest.fn(),
    deleteTag: jest.fn(),
    reorderTags: jest.fn(),
    moveTag: jest.fn(),
  };

  beforeEach(() =>
    [...Object.values(manageActions), ...Object.values(tagActions)].forEach((spy) =>
      spy.mockReset(),
    ),
  );
  ```
- Rename every tag-method use (`manageActions.editTag.mockImplementation`, `expect(manageActions.createTag)`, `expect(manageActions.moveTag)`, …), and add a `TagActions` provider after each of the five `{ provide: ManageActions, useValue: manageActions },` lines:
  ```bash
  perl -pi -e 's/\bmanageActions\.(createTag|editTag|deleteTag|reorderTags|moveTag)\b/tagActions.$1/g; s/^(\s*)\{ provide: ManageActions, useValue: manageActions \},\n/$1\{ provide: ManageActions, useValue: manageActions },\n$1\{ provide: TagActions, useValue: tagActions },\n/' frontend/src/app/reader/shell/sidebar/sidebar.component.spec.ts
  ```
  Check: `grep -c "provide: TagActions" …sidebar.component.spec.ts` prints `5`, and `grep -nE "manageActions\.(createTag|editTag|deleteTag|reorderTags|moveTag)"` prints nothing.

- [ ] **Step 7: Tag row menu and Tags head menu**

`frontend/src/app/reader/shell/sidebar/sidebar-tag-row-menu.component.ts`:
- `import { ManageActions } from '../../feeds/manage/manage-actions.service';` → `import { TagActions } from '../../feeds/manage/tag-actions.service';`
- `  protected readonly manage = inject(ManageActions);` → `  protected readonly tagActions = inject(TagActions);`

`sidebar-tag-row-menu.component.html`: `manage.editTag(current)` → `tagActions.editTag(current)`, `manage.createTag(current.id)` → `tagActions.createTag(current.id)`, `manage.deleteTag(current)` → `tagActions.deleteTag(current)`.

`sidebar-tag-row-menu.component.spec.ts` (the real `SidebarRowActions` it provides still injects `ManageActions`, so that stub stays):
- After `import { ManageActions } from '../../feeds/manage/manage-actions.service';` add
  `import { TagActions } from '../../feeds/manage/tag-actions.service';`
- After `      { provide: ManageActions, useValue: {} },` add
  `      { provide: TagActions, useValue: {} },`

`frontend/src/app/reader/shell/sidebar/sidebar-tags-head-menu.component.ts`:
- `import { ManageActions } from '../../feeds/manage/manage-actions.service';` → `import { TagActions } from '../../feeds/manage/tag-actions.service';`
- `  protected readonly manage = inject(ManageActions);` → `  protected readonly tagActions = inject(TagActions);`

`sidebar-tags-head-menu.component.html`: `manage.createTag(); rows.closeMenu()` → `tagActions.createTag(); rows.closeMenu()`. (No spec exists for this component.)

- [ ] **Step 8: Organise tag group**

`frontend/src/app/settings/organise/organise-tag-group.component.ts`:
- After `import { ManageActions } from '../../reader/feeds/manage/manage-actions.service';` add
  `import { TagActions } from '../../reader/feeds/manage/tag-actions.service';`
- After `  protected readonly manage = inject(ManageActions);` add
  `  protected readonly tagActions = inject(TagActions);`

`organise-tag-group.component.html`: `(click)="manage.editTag(tag)"` → `(click)="tagActions.editTag(tag)"`; `(click)="manage.deleteTag(tag)"` → `(click)="tagActions.deleteTag(tag)"`.

`organise-tag-group.component.spec.ts`:
- After `import { ManageActions } from '../../reader/feeds/manage/manage-actions.service';` add
  `import { TagActions } from '../../reader/feeds/manage/tag-actions.service';`
- Replace
  ```ts
    const manage = {
      reorderTagFeeds: jest.fn(),
      reorderUntagged: jest.fn(),
      reorderTags: jest.fn(),
      moveFeedToTag: jest.fn(),
      editTag: jest.fn(),
      deleteTag: jest.fn(),
      editSubscription: jest.fn(),
  ```
  with
  ```ts
    const tagActions = {
      editTag: jest.fn(),
      deleteTag: jest.fn(),
    };

    const manage = {
      reorderTagFeeds: jest.fn(),
      reorderUntagged: jest.fn(),
      moveFeedToTag: jest.fn(),
      editSubscription: jest.fn(),
  ```
  (`reorderTags` goes because the component never called it.)
- `    for (const spy of Object.values(manage)) spy.mockReset();` →
  `    for (const spy of [...Object.values(manage), ...Object.values(tagActions)]) spy.mockReset();`
- After `          { provide: ManageActions, useValue: manage },` add
  `          { provide: TagActions, useValue: tagActions },`

- [ ] **Step 9: Reader shell**

`frontend/src/app/reader/shell/reader-shell.component.ts`:
- After `import { ManageActions } from '../feeds/manage/manage-actions.service';` add
  `import { TagActions } from '../feeds/manage/tag-actions.service';`
- After `  readonly manage = inject(ManageActions);` add
  `  readonly tagActions = inject(TagActions);`

`reader-shell.component.html`:
- `(click)="manage.editTag(tag)"` → `(click)="tagActions.editTag(tag)"`
- Comment line `     the drawer on phone). Same `ManageActions` as the sidebar calls; empty otherwise. -->` → `     the drawer on phone). The same actions the sidebar calls; empty otherwise. -->`

`reader-shell.component.spec.ts`:
- After `import { ManageActions } from '../feeds/manage/manage-actions.service';` add
  `import { TagActions } from '../feeds/manage/tag-actions.service';`
- In `it('offers the same edit action for the selected tag', …)`:
  `const edit = jest.spyOn(TestBed.inject(ManageActions), 'editTag');` → `const edit = jest.spyOn(TestBed.inject(TagActions), 'editTag');`

- [ ] **Step 10: Organise section (tag part)**

`frontend/src/app/settings/organise/organise-section.component.ts`:
- After `import { ManageActions } from '../../reader/feeds/manage/manage-actions.service';` add
  `import { TagActions } from '../../reader/feeds/manage/tag-actions.service';`
- After `  protected readonly manage = inject(ManageActions);` add
  `  protected readonly tagActions = inject(TagActions);`
- `this.manage.moveTag(ended.tag, ended.drop);` → `this.tagActions.moveTag(ended.tag, ended.drop);`
- `this.manage.reorderTags(ids);` → `this.tagActions.reorderTags(ids);`

`organise-section.component.html`: `<app-button size="sm" (click)="manage.createTag()">` → `<app-button size="sm" (click)="tagActions.createTag()">`

`organise-section.component.spec.ts` (the section renders `OrganiseTagGroupComponent`s, so the spec stubs every group either one injects):
- After `import { ManageActions } from '../../reader/feeds/manage/manage-actions.service';` add
  `import { TagActions } from '../../reader/feeds/manage/tag-actions.service';`
- In the `const manage = { … }` stub, delete the five lines `    createTag: jest.fn(),`, `    editTag: jest.fn(),`, `    deleteTag: jest.fn(),`, `    reorderTags: jest.fn(),`, `    moveTag: jest.fn(),`. Directly after the stub's closing `  };` insert:
  ```ts

    const tagActions = {
      createTag: jest.fn(),
      editTag: jest.fn(),
      deleteTag: jest.fn(),
      reorderTags: jest.fn(),
      moveTag: jest.fn(),
    };

    const resetActionSpies = (): void => {
      for (const spy of [...Object.values(manage), ...Object.values(tagActions)]) spy.mockReset();
    };
  ```
- Then run:
  ```bash
  perl -pi -e '
    s/^(\s*)for \(const spy of Object\.values\(manage\)\) spy\.mockReset\(\);/$1resetActionSpies();/;
    s/^(\s*)\{ provide: ManageActions, useValue: manage \},\n/$1\{ provide: ManageActions, useValue: manage },\n$1\{ provide: TagActions, useValue: tagActions },\n/;
    s/\bmanage\.(createTag|editTag|deleteTag|reorderTags|moveTag)\b/tagActions.$1/g;
    s/const \{ manage \} = await (renderReordered|renderTags|renderTree)\(\);/const { tagActions } = await $1();/;
    s/turns into a full reordered id list for ManageActions\.reorderTags\./turns into a full reordered id list for TagActions.reorderTags./;
  ' frontend/src/app/settings/organise/organise-section.component.spec.ts
  ```
  This covers 3 resets, 3 providers, the `tagActions.reorderTags`/`moveTag` expectations, 8 destructurings and 1 comment.
- In `renderWithMocks`, `    return { component, manage, store: component.store };` → `    return { component, manage, tagActions, store: component.store };`
- Check: `grep -c "provide: TagActions"` prints `3`; `grep -n "const { manage } = await render\(Reordered\|Tags\|Tree\)"` prints nothing.

- [ ] **Step 11: Format, static check, touched specs, build**

```bash
cd frontend
npx prettier --write src/app/reader/feeds/manage src/app/reader/shell/reader-shell.component.ts src/app/reader/shell/reader-shell.component.html src/app/reader/shell/reader-shell.component.spec.ts src/app/reader/shell/sidebar src/app/settings/organise
npm run check:static
cd .. && docker compose exec -T frontend npx jest src/app/reader/feeds/manage src/app/reader/shell/sidebar src/app/reader/shell/reader-shell.component.spec.ts src/app/settings/organise
cd frontend && npm run build
```
Expected: lint/format/stylelint/tsc clean, all listed specs PASS, build succeeds (the build catches template errors such as a missed `manage.editTag`).

- [ ] **Step 12: Commit**

```bash
git add frontend/src/app/reader/feeds/manage frontend/src/app/reader/shell frontend/src/app/settings/organise
git commit -m "refactor(#2111): tag actions move to TagActions"
```

---

### Task 2: Extract BulkSubscriptionActions and slim ManageActions

**Files:**
- Create: `frontend/src/app/reader/feeds/manage/bulk-subscription-actions.service.ts`
- Create: `frontend/src/app/reader/feeds/manage/bulk-subscription-actions.service.spec.ts`
- Modify: `frontend/src/app/reader/feeds/manage/reload-after.ts`
- Modify (full rewrite): `frontend/src/app/reader/feeds/manage/manage-actions.service.ts`
- Modify: `frontend/src/app/reader/feeds/manage/manage-actions.service.spec.ts`
- Modify: `frontend/src/app/settings/organise/organise-section.component.ts`, `.spec.ts`
- Modify: `frontend/src/app/settings/organise/bulk-tag-dialog.component.ts`
- Modify: `frontend/README.md`

**Interfaces:**
- Consumes: `TagActions` from Task 1 (the organise-section spec's `tagActions` stub and `resetActionSpies`).
- Produces: `BulkSubscriptionActions` (`@Injectable({ providedIn: 'root' })`) with
  `bulkAddTag(subscriptionIds: number[], tag: TagDto): Observable<void>`, `bulkRemoveTag(subscriptionIds: number[], tag: TagDto): Observable<void>`, `bulkSetFlags(subscriptionIds: number[], flags: SubscriptionFlags): Observable<void>`, `bulkUnsubscribe(subscriptions: SubscriptionDto[]): Observable<boolean>`.
- Produces: `reloadAfterFlagWrite(flags: SubscriptionFlags, subscriptions: SubscriptionsStore, savedSearches: SavedSearchesStore): void` in `reload-after.ts`.

- [ ] **Step 1: Shared helper in `reload-after.ts`** (replace the whole file)

```ts
import { Observable } from 'rxjs';
import { SubscriptionsStore } from '../../state/subscriptions.store';
import { SavedSearchesStore } from '../../state/saved-searches.store';
import { SubscriptionFlags } from '../../subscription-bulk';

/** Reloads whether the write landed or not, so the store shows what the server kept. */
export function reloadAfter(write$: Observable<unknown>, reload: () => void): void {
  write$.subscribe({ next: reload, error: reload });
}

/** The saved-search flag moves saved-search badges and counts, which only a reload re-learns. */
export function reloadAfterFlagWrite(
  flags: SubscriptionFlags,
  subscriptions: SubscriptionsStore,
  savedSearches: SavedSearchesStore,
): void {
  subscriptions.reloadQuietly();
  if (flags.includeInSavedSearches !== undefined) savedSearches.load();
}
```
(`BulkSubscriptionUpdate extends SubscriptionFlags`, so `bulkPatch` passes its body straight in, as `reloadFeedsAfter(body)` does today.)

- [ ] **Step 2: Create `bulk-subscription-actions.service.ts`**

```ts
import { Injectable, inject } from '@angular/core';
import { Observable, map, of, switchMap, tap } from 'rxjs';
import { TranslocoService } from '@jsverse/transloco';
import { ReaderApi } from '../../reader-api';
import { SubscriptionsStore } from '../../state/subscriptions.store';
import { SavedSearchesStore } from '../../state/saved-searches.store';
import { SubscriptionDto, TagDto } from '../../models';
import { reloadAfterFlagWrite } from './reload-after';
import { BulkSubscriptionUpdate, SubscriptionFlags } from '../../subscription-bulk';
import { ConfirmService } from '../../../shared/confirm-dialog/confirm.service';
import { ToastService, CONFIRMATION_DURATION_MS } from '../../../shared/toast/toast.service';
import { pluralKey } from '../../../core/i18n/plural-key';
import { bulkUnsubscribeConfirm } from './unsubscribe-messages';

/** The Organise page's bulk bar: one write across the selected feeds, confirmed by a toast. */
@Injectable({ providedIn: 'root' })
export class BulkSubscriptionActions {
  private readonly confirm = inject(ConfirmService);
  private readonly api = inject(ReaderApi);
  private readonly subs = inject(SubscriptionsStore);
  private readonly savedSearches = inject(SavedSearchesStore);
  private readonly i18n = inject(TranslocoService);
  private readonly toast = inject(ToastService);

  /** Add one tag to many feeds. Not optimistic: a bulk tag change moves feeds
   *  between lists under the server's position rules, and re-deriving those
   *  here would be the second copy SubscriptionTagSync exists to prevent. */
  bulkAddTag(subscriptionIds: number[], tag: TagDto): Observable<void> {
    return this.bulkPatch(
      { subscriptionIds, addTagIds: [tag.id] },
      this.i18n.translate(pluralKey('manage.bulk.tagAdded', subscriptionIds.length), {
        count: subscriptionIds.length,
        name: tag.name,
      }),
    );
  }

  /** Remove one tag from many feeds. */
  bulkRemoveTag(subscriptionIds: number[], tag: TagDto): Observable<void> {
    return this.bulkPatch(
      { subscriptionIds, removeTagIds: [tag.id] },
      this.i18n.translate(pluralKey('manage.bulk.tagRemoved', subscriptionIds.length), {
        count: subscriptionIds.length,
        name: tag.name,
      }),
    );
  }

  /** Set one or both inclusion flags across many feeds. */
  bulkSetFlags(subscriptionIds: number[], flags: SubscriptionFlags): Observable<void> {
    return this.bulkPatch(
      { subscriptionIds, ...flags },
      this.i18n.translate(pluralKey('manage.bulk.flagsSet', subscriptionIds.length), {
        count: subscriptionIds.length,
      }),
    );
  }

  /** Confirm, then unsubscribe from many feeds. Emits false when the user
   *  dismissed the confirmation, so the caller can leave the selection alone. */
  bulkUnsubscribe(subscriptions: SubscriptionDto[]): Observable<boolean> {
    return this.confirm.ask(bulkUnsubscribeConfirm(this.i18n, subscriptions)).pipe(
      switchMap((confirmed) => {
        if (!confirmed) return of(false);
        return this.api.bulkUnsubscribe(subscriptions.map((subscription) => subscription.id)).pipe(
          tap((result) => {
            this.subs.reloadQuietly();
            this.toast.show({
              message: this.i18n.translate(pluralKey('manage.bulk.unsubscribed', result.removed), {
                count: result.removed,
              }),
              durationMs: CONFIRMATION_DURATION_MS,
            });
          }),
          map(() => true),
        );
      }),
    );
  }

  private bulkPatch(body: BulkSubscriptionUpdate, confirmation: string): Observable<void> {
    return this.api.bulkUpdateSubscriptions(body).pipe(
      tap(() => {
        reloadAfterFlagWrite(body, this.subs, this.savedSearches);
        this.toast.show({ message: confirmation, durationMs: CONFIRMATION_DURATION_MS });
      }),
      map(() => undefined),
    );
  }
}
```

- [ ] **Step 3: Create `bulk-subscription-actions.service.spec.ts`** (the `describe('bulk actions')` tests, moved verbatim, without the add-feed test, which stays with `ManageActions`)

```ts
import { TestBed } from '@angular/core/testing';
import { provideTranslocoTesting } from '../../../../testing/transloco-testing';
import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { Dialog } from '@angular/cdk/dialog';
import { ReplaySubject } from 'rxjs';
import { API_BASE_URL } from '../../../core/api';
import { BulkSubscriptionActions } from './bulk-subscription-actions.service';
import { SubscriptionsStore } from '../../state/subscriptions.store';
import { SavedSearchesStore } from '../../state/saved-searches.store';
import { SubscriptionDto, TagDto } from '../../models';
import { makeSubscription } from '../../testing/subscription.factory';
import { ToastService } from '../../../shared/toast/toast.service';

const BASE = 'https://api.test';

const SUBSCRIPTION: SubscriptionDto = makeSubscription({
  id: 5,
  feedId: 50,
  title: 'Heise',
  feedUrl: 'u',
  createdAt: 'x',
});
const TAG: TagDto = { id: 3, name: 'Tech', color: null, icon: null, position: 0, parentId: null };

describe('BulkSubscriptionActions', () => {
  const subscriptions = (count: number): SubscriptionDto[] =>
    Array.from({ length: count }, (_, index) => ({
      ...SUBSCRIPTION,
      id: index + 1,
      title: `Feed ${index + 1}`,
    }));

  let http: HttpTestingController;
  afterEach(() => http.verify());

  // The dialog mock returns a replayable `closed`, so a test can answer the
  // confirmation before the code under test subscribes.
  function setup(options: { confirmAnswer?: boolean } = {}) {
    const closed = new ReplaySubject<unknown>(1);
    const dialogOpen = jest.fn<
      { closed: ReplaySubject<unknown> },
      [unknown, { data: { title: string; message: string; requireText?: string } }]
    >(() => ({ closed }));
    TestBed.configureTestingModule({
      imports: [provideTranslocoTesting()],
      providers: [
        provideHttpClient(),
        provideHttpClientTesting(),
        { provide: API_BASE_URL, useValue: BASE },
        { provide: Dialog, useValue: { open: dialogOpen } },
        // ConfirmService and ToastService share the `Dialog` token; the stub above's
        // bare { closed } object lacks the `overlayRef` ToastService.show() reads, so
        // a real call inside bulkPatch()/bulkUnsubscribe() would throw against it.
        { provide: ToastService, useValue: { show: jest.fn() } },
      ],
    });
    const actions = TestBed.inject(BulkSubscriptionActions);
    http = TestBed.inject(HttpTestingController);
    const subLoad = jest
      .spyOn(TestBed.inject(SubscriptionsStore), 'reloadQuietly')
      .mockImplementation(() => undefined);
    const savedSearchesLoad = jest
      .spyOn(TestBed.inject(SavedSearchesStore), 'load')
      .mockImplementation(() => undefined);

    if (options.confirmAnswer !== undefined) closed.next(options.confirmAnswer);

    return { actions, http, dialogOpen, subLoad, savedSearchesLoad };
  }

  it('posts one bulk patch when a tag is added to several feeds', () => {
    const { actions, http } = setup();
    let emitted = false;

    actions.bulkAddTag([1, 2, 3], TAG).subscribe(() => (emitted = true));

    const testRequest = http.expectOne(`${BASE}/api/subscriptions/bulk`);
    expect(testRequest.request.method).toBe('PATCH');
    expect(testRequest.request.body).toEqual({ subscriptionIds: [1, 2, 3], addTagIds: [TAG.id] });
    testRequest.flush({ subscriptions: [] });

    expect(emitted).toBe(true);
  });

  it('posts removeTagIds when a tag is removed', () => {
    const { actions, http } = setup();
    let emitted = false;

    actions.bulkRemoveTag([4], TAG).subscribe(() => (emitted = true));

    const testRequest = http.expectOne(`${BASE}/api/subscriptions/bulk`);
    expect(testRequest.request.body).toEqual({
      subscriptionIds: [4],
      removeTagIds: [TAG.id],
    });
    testRequest.flush({ subscriptions: [] });

    expect(emitted).toBe(true);
  });

  it('sends only the flag that was named', () => {
    const { actions, http } = setup();
    let emitted = false;

    actions.bulkSetFlags([7], { includeInAllItems: false }).subscribe(() => (emitted = true));

    const testRequest = http.expectOne(`${BASE}/api/subscriptions/bulk`);
    expect(testRequest.request.body).toEqual({
      subscriptionIds: [7],
      includeInAllItems: false,
    });
    testRequest.flush({ subscriptions: [] });

    expect(emitted).toBe(true);
  });

  it('reloads the subscriptions store after a successful bulk patch', () => {
    const { actions, http, subLoad, savedSearchesLoad } = setup();

    actions.bulkAddTag([1], TAG).subscribe();
    http.expectOne(`${BASE}/api/subscriptions/bulk`).flush({ subscriptions: [] });

    expect(subLoad).toHaveBeenCalled();
    expect(savedSearchesLoad).not.toHaveBeenCalled();
  });

  it('reloads saved searches after a bulk patch of the saved-search flag only', () => {
    const { actions, http, savedSearchesLoad } = setup();

    actions.bulkSetFlags([1], { includeInForYou: false }).subscribe();
    http.expectOne(`${BASE}/api/subscriptions/bulk`).flush({ subscriptions: [] });
    expect(savedSearchesLoad).not.toHaveBeenCalled();

    actions.bulkSetFlags([1], { includeInSavedSearches: false }).subscribe();
    http.expectOne(`${BASE}/api/subscriptions/bulk`).flush({ subscriptions: [] });
    expect(savedSearchesLoad).toHaveBeenCalledTimes(1);
  });

  it('asks the user to type the count from five feeds up', () => {
    const { actions, dialogOpen } = setup();

    actions.bulkUnsubscribe(subscriptions(5)).subscribe();

    expect(dialogOpen.mock.calls[0][1].data.requireText).toBe('5');
  });

  describe('where saved posts go', () => {
    const bluesky = makeSubscription({
      id: 21,
      title: 'Bob',
      social: { network: 'bluesky', accountId: null, kind: 'profile' },
    });
    const confirmMessage = (leaving: SubscriptionDto[]): string => {
      const { actions, dialogOpen } = setup();
      actions.bulkUnsubscribe(leaving).subscribe();
      return dialogOpen.mock.calls[0][1].data.message;
    };

    it('names the one Saved feed that all leaving feeds share', () => {
      expect(confirmMessage(subscriptions(2))).toContain('move to Saved from feeds');
    });

    it('says "their Saved feeds" when the destinations differ', () => {
      expect(confirmMessage([subscriptions(1)[0], bluesky])).toContain(
        'move to their Saved feeds',
      );
    });

    it('warns about a saved feed leaving with the others', () => {
      const savedFeed = makeSubscription({
        id: 22,
        title: 'Saved from feeds',
        sourceFormat: 'saved',
      });

      expect(confirmMessage([bluesky, savedFeed])).toContain(
        'The saved posts in Saved from feeds are deleted for good.',
      );
    });

    it('says the posts move to a new Saved feed when their destination leaves too', () => {
      const savedFeed = makeSubscription({
        id: 22,
        title: 'Saved from feeds',
        sourceFormat: 'saved',
      });

      expect(confirmMessage([subscriptions(1)[0], savedFeed])).toContain(
        'move to a new Saved from feeds.',
      );
    });
  });

  it('does not ask for typed text at four feeds', () => {
    const { actions, dialogOpen } = setup();

    actions.bulkUnsubscribe(subscriptions(4)).subscribe();

    expect(dialogOpen.mock.calls[0][1].data.requireText).toBeUndefined();
  });

  it('singularises the confirmation title at a selection of one', () => {
    const { actions, dialogOpen } = setup();

    actions.bulkUnsubscribe(subscriptions(1)).subscribe();

    expect(dialogOpen.mock.calls[0][1].data.title).toBe('Unsubscribe from 1 feed?');
  });

  it('names at most five titles and counts the rest', () => {
    const { actions, dialogOpen } = setup();

    actions.bulkUnsubscribe(subscriptions(7)).subscribe();

    const message: string = dialogOpen.mock.calls[0][1].data.message;
    expect(message).toContain('Feed 1');
    expect(message).toContain('Feed 5');
    expect(message).not.toContain('Feed 6');
  });

  it('writes nothing when the confirmation is dismissed', () => {
    const { actions, http } = setup({ confirmAnswer: false });
    let outcome: boolean | undefined;

    actions.bulkUnsubscribe(subscriptions(2)).subscribe((ok) => (outcome = ok));

    expect(outcome).toBe(false);
    http.expectNone(`${BASE}/api/subscriptions/bulk-unsubscribe`);
  });

  it('unsubscribes and reloads after a confirmed bulk unsubscribe', () => {
    const { actions, http, subLoad } = setup({ confirmAnswer: true });

    actions.bulkUnsubscribe(subscriptions(2)).subscribe();
    const testRequest = http.expectOne(`${BASE}/api/subscriptions/bulk-unsubscribe`);
    expect(testRequest.request.body).toEqual({ subscriptionIds: [1, 2] });
    testRequest.flush({ removed: 2 });

    expect(subLoad).toHaveBeenCalled();
  });
});
```

- [ ] **Step 4: Rewrite `manage-actions.service.ts`** (whole file). It keeps the single-feed actions, `patchFlags` uses the shared helper, and two stray docblocks are fixed: the orphan "Fire a drag-and-drop write…" block is deleted, since `reloadAfter` documents itself, and the `afterMove` docblock moves from `reloadSubsAfter` to `afterMove`.

```ts
import { Injectable, inject } from '@angular/core';
import { Observable, catchError, map, of, tap } from 'rxjs';
import { Dialog } from '@angular/cdk/dialog';
import { TranslocoService } from '@jsverse/transloco';
import { ReaderApi } from '../../reader-api';
import { isFeedRecovered } from '../feed-health';
import { SubscriptionsStore } from '../../state/subscriptions.store';
import { SavedSearchesStore } from '../../state/saved-searches.store';
import { TagsStore } from '../../state/tags.store';
import { RefreshService } from '../../state/refresh.service';
import { EntriesStore } from '../../state/entries.store';
import { MoveFeedToTag, RefreshReport, SubscriptionDto, SubscriptionTagDto } from '../../models';
import { reloadAfter, reloadAfterFlagWrite } from './reload-after';
import { SubscriptionFlags } from '../../subscription-bulk';
import { ConfirmService } from '../../../shared/confirm-dialog/confirm.service';
import { ToastService, CONFIRMATION_DURATION_MS } from '../../../shared/toast/toast.service';
import {
  EditSubscriptionDialogComponent,
  EditSubscriptionResult,
  UNSUBSCRIBE_REQUESTED,
} from './edit-subscription-dialog.component';
import { AddFeedDialogComponent } from '../add-feed/add-feed-dialog.component';
import { isSearchFeed } from '../../state/social-sections';
import { unsubscribeConfirm } from './unsubscribe-messages';

/** The single place one feed's management dialog is opened and its side effects applied.
 *  Both the settings sections and the sidebar (via the shell) call these, so an
 *  action behaves identically wherever it is triggered. Dialogs perform their own
 *  API write and close with the result; this service refreshes the affected
 *  stores on a truthy close. Tags have TagActions, many feeds at once BulkSubscriptionActions. */
@Injectable({ providedIn: 'root' })
export class ManageActions {
  private readonly dialog = inject(Dialog);
  private readonly confirm = inject(ConfirmService);
  private readonly api = inject(ReaderApi);
  private readonly subs = inject(SubscriptionsStore);
  private readonly savedSearches = inject(SavedSearchesStore);
  private readonly tags = inject(TagsStore);
  private readonly i18n = inject(TranslocoService);
  private readonly toast = inject(ToastService);
  private readonly refresh = inject(RefreshService);
  private readonly entries = inject(EntriesStore);

  editSubscription(sub: SubscriptionDto): void {
    const ref = this.dialog.open<EditSubscriptionResult>(EditSubscriptionDialogComponent, {
      data: sub,
      panelClass: 'app-dialog',
    });
    ref.closed.subscribe((updated) => {
      if (updated === UNSUBSCRIBE_REQUESTED) {
        this.unsubscribe(sub);
        return;
      }
      if (!updated) return;
      this.subs.reloadQuietly();
      if (updated.includeInSavedSearches !== sub.includeInSavedSearches) this.savedSearches.load();
      if (searchQueryOf(updated) !== searchQueryOf(sub)) this.requeried(sub);
    });
  }

  /** Move a feed between the sidebar's lists at the dropped position, the way a
   *  drag reads: out of `fromTagId`, into `toTagId` at `position` (a null tag
   *  id is the untagged "Feeds" list; a null position appends). A move within
   *  one list is a reorder, not this. Optimistic: reflect the new membership
   *  at once, then let the reload reconcile the exact order the server chose. */
  moveFeedToTag(sub: SubscriptionDto, move: MoveFeedToTag): void {
    if (move.fromTagId === move.toTagId) return;
    this.subs.storedSubscriptions.update((current) =>
      current.map((subscription) =>
        subscription.id === sub.id ? this.afterMove(subscription, move) : subscription,
      ),
    );
    this.reloadSubsAfter(this.api.moveFeedToTag(sub.id, move));
  }

  private reloadSubsAfter(write$: Observable<unknown>): void {
    reloadAfter(write$, () => this.subs.reloadQuietly());
  }

  /** The moved feed's optimistic shape: the source tag dropped, the target tag
   *  added at the drop position; dropped on Feeds, its untagged position is set
   *  only once it has no tags left. Sibling positions are left to the reload. */
  private afterMove(
    sub: SubscriptionDto,
    { fromTagId, toTagId, position }: MoveFeedToTag,
  ): SubscriptionDto {
    const kept = sub.tags.filter((tag) => tag.id !== fromTagId && tag.id !== toTagId);
    if (toTagId === null) {
      const untaggedPosition = kept.length === 0 ? (position ?? sub.position) : sub.position;
      return { ...sub, tags: kept, position: untaggedPosition };
    }
    const target = sub.tags.find((tag) => tag.id === toTagId) ?? this.tagShape(toTagId);
    return { ...sub, tags: [...kept, { ...target, position: position ?? kept.length }] };
  }

  private tagShape(tagId: number): SubscriptionTagDto {
    const tag = this.tags.tags().find((candidate) => candidate.id === tagId);
    return {
      id: tagId,
      name: tag?.name ?? '',
      color: tag?.color ?? null,
      icon: tag?.icon ?? null,
      position: 0,
      parentId: tag?.parentId ?? null,
    };
  }

  /** Flip whether this feed contributes to the "All items" list. Optimistic:
   *  update the sidebar immediately, reconcile with the server. */
  setIncludeInAllItems(sub: SubscriptionDto, value: boolean): void {
    this.patchFlags(sub, { includeInAllItems: value });
  }

  /** Flip whether this feed contributes to "For you". Optimistic: update the
   *  sidebar immediately, reconcile with the server. */
  setIncludeInForYou(sub: SubscriptionDto, value: boolean): void {
    this.patchFlags(sub, { includeInForYou: value });
  }

  /** Flip whether this feed's entries count in saved searches. Optimistic like the others. */
  setIncludeInSavedSearches(sub: SubscriptionDto, value: boolean): void {
    this.patchFlags(sub, { includeInSavedSearches: value });
  }

  /** The backend PATCH clears customTitle/tagIds when they are omitted, so
   *  every flag toggle resends them; an omitted flag stays as it is. */
  private patchFlags(sub: SubscriptionDto, flags: SubscriptionFlags): void {
    this.subs.patchLocal(sub.id, flags);
    reloadAfter(
      this.api.updateSubscription(sub.id, {
        customTitle: sub.customTitle,
        tagIds: sub.tags.map((tag) => tag.id),
        ...flags,
      }),
      () => reloadAfterFlagWrite(flags, this.subs, this.savedSearches),
    );
  }

  /** Persist a new order for the untagged "Feeds" list. Optimistic: update
   *  positions immediately, reconcile on response. */
  reorderUntagged(subscriptionIds: number[]): void {
    this.subs.storedSubscriptions.update((current) =>
      current.map((subscription) => {
        const index = subscriptionIds.indexOf(subscription.id);
        if (index >= 0) return { ...subscription, position: index };
        return subscription;
      }),
    );
    this.reloadSubsAfter(this.api.reorderSubscriptions(subscriptionIds));
  }

  /** Persist a new order for the feeds within one tag. Optimistic: update
   *  per-tag positions immediately, reconcile on response. */
  reorderTagFeeds(tagId: number, subscriptionIds: number[]): void {
    this.subs.storedSubscriptions.update((current) =>
      current.map((subscription) => {
        const tagEntry = subscription.tags.find((tag) => tag.id === tagId);
        if (!tagEntry) return subscription;
        const index = subscriptionIds.indexOf(subscription.id);
        if (index < 0) return subscription;
        return {
          ...subscription,
          tags: subscription.tags.map((tag) =>
            tag.id === tagId ? { ...tag, position: index } : tag,
          ),
        };
      }),
    );
    this.reloadSubsAfter(this.api.setTagFeedOrder(tagId, subscriptionIds));
  }

  unsubscribe(sub: SubscriptionDto): void {
    this.confirm.confirmThen(unsubscribeConfirm(this.i18n, sub), () => {
      this.api.deleteSubscription(sub.id).subscribe({ next: () => this.subs.reloadQuietly() });
    });
  }

  /** Open the add-feed dialog. The reader shell opens the same dialog and then
   *  navigates to the new feed; the Organise page only wants the feed to appear
   *  in its list, so the navigation stays with the shell and the dialog opening
   *  lives here, where every other management dialog already does. */
  addFeed(): Observable<SubscriptionDto | undefined> {
    const ref = this.dialog.open<SubscriptionDto>(AddFeedDialogComponent, {
      panelClass: 'app-dialog',
    });

    return ref.closed.pipe(
      tap((subscription) => {
        if (subscription) this.subs.reloadQuietly();
      }),
    );
  }

  /** Manually re-fetch one feed (the health list's Retry). Reaches gone feeds
   *  too; a success resurrects the feed server-side. Awaits the report, then
   *  quietly reloads the list (no loading skeleton) so a recovered feed drops
   *  out in place. Emits `true` while the feed is STILL unhealthy — the caller
   *  shows the error — and `false` on recovery, which a toast confirms here. */
  retryFeed(sub: SubscriptionDto): Observable<boolean> {
    return this.api.refresh({ kind: 'feed', feedId: sub.feedId }).pipe(
      tap(() => this.subs.reloadQuietly()),
      map((report: RefreshReport) => {
        if (!isFeedRecovered(report)) return true;
        this.toast.show({
          message: this.i18n.translate('settings.health.retry.recovered', {
            title: sub.title,
          }),
          durationMs: CONFIRMATION_DURATION_MS,
        });
        return false;
      }),
      // A request that never landed is as unresolved as a still-failing feed:
      // show the error either way.
      catchError(() => of(true)),
    );
  }

  /** The list drops the removed posts now; the refresh's finish reloads it with what the new query finds. */
  private requeried(sub: SubscriptionDto): void {
    this.entries.reload();
    this.refresh.run(undefined, { kind: 'feed', feedId: sub.feedId });
  }
}

function searchQueryOf(subscription: SubscriptionDto): string | null {
  return isSearchFeed(subscription) ? subscription.social.query : null;
}
```

- [ ] **Step 5: Trim `manage-actions.service.spec.ts`**

- `import { ReplaySubject, of } from 'rxjs';` → `import { of } from 'rxjs';`
- Delete the two lines `const TAG = tag;` and `const SUBSCRIPTION = sub;`.
- Delete the block `describe('bulk actions', () => { … });` whole (from its opening line through its matching `});`, just before `describe('retryFeed', …)`). Its add-feed test is re-homed in the next bullet. Every other test in it now lives in `bulk-subscription-actions.service.spec.ts`.
- Directly after the `it('reorderTagFeeds: PATCHes /api/tags/{id}/feed-order then reloads subs', …)` block, insert:
  ```ts

    it('addFeed: opens the add-feed dialog and reloads on a successful subscribe', () => {
      closed = sub;
      const spy = jest
        .spyOn(TestBed.inject(SubscriptionsStore), 'reloadQuietly')
        .mockImplementation(() => undefined);

      service.addFeed().subscribe();

      expect(open).toHaveBeenCalled();
      expect(spy).toHaveBeenCalled();
    });
  ```
- In `describe('retryFeed')`, replace the three comment lines above `function setup()`:
  ```ts
      // Own TestBed, same reason as the bulk-action setup(): ManageActions and
      // ToastService share the Dialog token, so a real ToastService.show() call
      // here needs the stub replaced rather than left to the outer `open` mock.
  ```
  with
  ```ts
      // Own TestBed: ManageActions and ToastService share the Dialog token, so a real
      // ToastService.show() call here needs the stub replaced rather than left to the outer `open` mock.
  ```
- Check that `TagDto`, `TagsStore`, `ToastService`, `CONFIRMATION_DURATION_MS`, `TranslocoService`, `BASE` and `heldUnrenamedHome` are all still referenced (`moveFeedToTag` optimistic test, `retryFeed`, the unsubscribe tests). If ESLint reports an unused import, delete that import.

- [ ] **Step 6: Organise section (bulk part)**

`frontend/src/app/settings/organise/organise-section.component.ts`:
- After `import { TagActions } from '../../reader/feeds/manage/tag-actions.service';` add
  `import { BulkSubscriptionActions } from '../../reader/feeds/manage/bulk-subscription-actions.service';`
- After `  protected readonly tagActions = inject(TagActions);` add
  `  private readonly bulkActions = inject(BulkSubscriptionActions);`
- `perl -pi -e 's/this\.manage\.(bulkAddTag|bulkRemoveTag|bulkSetFlags|bulkUnsubscribe)\b/this.bulkActions.$1/g' frontend/src/app/settings/organise/organise-section.component.ts` (4 sites: `applyTag` ×2, `setFlags`, `unsubscribeSelected`)
- Docblock: ` * the page. Every write goes through ManageActions — this component injects no` / ` * ReaderApi.` →
  ```ts
   * the page. Every write goes through ManageActions, TagActions or
   * BulkSubscriptionActions — this component injects no ReaderApi.
  ```

`organise-section.component.spec.ts`:
- After `import { TagActions } from '../../reader/feeds/manage/tag-actions.service';` add
  `import { BulkSubscriptionActions } from '../../reader/feeds/manage/bulk-subscription-actions.service';`
- In the `const manage = { … }` stub, delete the four lines `    bulkAddTag: jest.fn(() => of(undefined)),`, `    bulkRemoveTag: jest.fn(() => of(undefined)),`, `    bulkSetFlags: jest.fn(() => of(undefined)),`, `    bulkUnsubscribe: jest.fn(() => of(true)),`.
- Directly after the `tagActions` stub's closing `  };` insert:
  ```ts

    const bulkActions = {
      bulkAddTag: jest.fn(() => of(undefined)),
      bulkRemoveTag: jest.fn(() => of(undefined)),
      bulkSetFlags: jest.fn(() => of(undefined)),
      bulkUnsubscribe: jest.fn(() => of(true)),
    };
  ```
- Replace `resetActionSpies` (from Task 1) with:
  ```ts
    const resetActionSpies = (): void => {
      const spies = [
        ...Object.values(manage),
        ...Object.values(tagActions),
        ...Object.values(bulkActions),
      ];
      for (const spy of spies) spy.mockReset();
    };
  ```
- Then run:
  ```bash
  perl -pi -e '
    s/\bmanage\.(bulkAddTag|bulkRemoveTag|bulkSetFlags|bulkUnsubscribe)\b/bulkActions.$1/g;
    s/^(\s*)\{ provide: TagActions, useValue: tagActions \},\n/$1\{ provide: TagActions, useValue: tagActions },\n$1\{ provide: BulkSubscriptionActions, useValue: bulkActions },\n/;
    s/const \{ component, manage \} = await renderWithMocks\(\);/const { component, bulkActions } = await renderWithMocks();/;
  ' frontend/src/app/settings/organise/organise-section.component.spec.ts
  ```
  This covers the four `mockReturnValue` lines in `renderWithMocks`, every `bulkActions.*` expectation, 3 providers, and 5 destructurings (selection-after-unsubscribe, bulk-write-fails, bulk tag with filter, flags with filter, six visibility commands). Every one of those tests uses only bulk methods.
- In `renderWithMocks`: `    return { component, manage, tagActions, store: component.store };` → `    return { component, manage, tagActions, bulkActions, store: component.store };`
- Check: `grep -c "provide: BulkSubscriptionActions"` prints `3`; `grep -nE "manage\.bulk"` prints nothing; the remaining `{ manage }` destructurings are the add-feed test and the unhealthy-feeds retry test only.

- [ ] **Step 7: Docblocks and README**

`frontend/src/app/settings/organise/bulk-tag-dialog.component.ts`: ` *  caller performs the bulk write through ManageActions -- a wrong click is` → ` *  caller performs the bulk write through BulkSubscriptionActions -- a wrong click is`

`frontend/README.md`:
- `manage menus, which open the same dialogs through \`ManageActions\`.` → `manage menus, which open the same dialogs through \`ManageActions\` and \`TagActions\`.`
- Replace the bullet
  ```md
  - **`ManageActions`** (`src/app/reader/feeds/manage/manage-actions.service.ts`) is
    the one place a management dialog is opened and its result applied — both
    the settings sections and the sidebar call it, so a dialog's own API write
    and the store refresh afterward happen exactly once, in exactly one place.
  ```
  with
  ```md
  - **`ManageActions`**, **`TagActions`** and **`BulkSubscriptionActions`**
    (`src/app/reader/feeds/manage/`) are the one place a management dialog is
    opened and its result applied, for one feed, for tags, and for a selection
    of feeds. Both the settings sections and the sidebar call them, so a
    dialog's own API write and the store refresh afterward happen exactly once,
    in exactly one place.
  ```

- [ ] **Step 8: Format, static check, touched specs, build**

```bash
cd frontend
npx prettier --write src/app/reader/feeds/manage src/app/settings/organise
npm run check:static
cd .. && docker compose exec -T frontend npx jest src/app/reader/feeds/manage src/app/settings/organise
cd frontend && npm run build
```
Expected: all clean, all PASS, build succeeds. Also confirm no consumer is left on a moved method:
`grep -rnE "manage(Actions)?\.(bulk[A-Z]\w*|createTag|editTag|deleteTag|reorderTags|moveTag)\b" src/app` prints nothing.

- [ ] **Step 9: Commit**

```bash
git add frontend/src/app/reader/feeds/manage frontend/src/app/settings/organise frontend/README.md
git commit -m "refactor(#2111): bulk actions move to BulkSubscriptionActions; ManageActions keeps the single-feed actions"
```

---

### Task 3: Gates

**Files:** none (verification only; fix and amend into the owning task's commit only if a gate fails).

- [ ] **Step 1: Full frontend check natively** (from `frontend/`; this runs Jest natively, so make sure no container Jest is running)

```bash
cd frontend && npm run check
```
Expected: lint, format, stylelint, three tsc projects and the full Jest suite all pass.

- [ ] **Step 2: Full Jest suite in the Docker frontend container**, only after Step 1 has finished

```bash
docker compose exec -T frontend npm test
```
Expected: PASS. If a spec fails with a missing `Dialog`/`HttpClient` provider, look for a consumer TestBed that now instantiates the real `TagActions` or `BulkSubscriptionActions` and stub the group there.

- [ ] **Step 3: Production build** (template type-check, which `npm run check` does not cover)

```bash
cd frontend && npm run build
```

- [ ] **Step 4: Line budget** — confirm nothing crept towards `max-lines`:

```bash
cd frontend/src/app && for f in reader/feeds/manage/*.service.ts reader/shell/reader-shell.component.ts reader/shell/sidebar/sidebar.component.ts settings/organise/organise-section.component.ts; do echo "$f $(grep -vE '^\s*$|^\s*(//|/\*|\*)' "$f" | wc -l)"; done
```
Expected: `tag-actions.service.ts` about 65, `bulk-subscription-actions.service.ts` about 80, `manage-actions.service.ts` about 185. `reader-shell.component.ts` is about 296; it passes, but report it (it was already about 294).

- [ ] **Step 5: Report** the commits (`git log --oneline develop..HEAD`) and the line counts. Do not push or open a PR unless asked.
