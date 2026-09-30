# Entry actions reach the shell without forwarding — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: superpowers:executing-plans (inline; one commit per task). Steps use checkbox (`- [ ]`) syntax.

**Goal:** Favourite/keep/read/open stop being re-emitted through every layer. The shell template renders the entry list and the reader view once each. The sidebar calls `ManageActions` itself (#1302).

**Architecture:**
- An abstract class `EntryActionHandler` serves as the DI token. Its root default is a no-op, which matches today's behaviour where an unbound output does nothing, and it lets specs that don't care skip a provider.
- `ReaderShellComponent` provides itself as the handler. The four leaf components (`entry-actions`, the magazine block base, `entry-row`, `entry-duplicates`) inject the handler and call it directly.
- Every intermediate `favorite`/`keep`/`read`/`open` output, and every binding of one, is deleted.
- `withOpen` is no longer needed for these three actions: the reader view's `app-entry-actions` receives the open entry as its `[entry]`.

**Tech Stack:** Angular 20 signals and standalone components, Jest in the frontend container.

## Global Constraints

- Starts after #1301 has merged. Branch `refactor/1302-entry-actions-context` off `develop`. Commit format `type(#1302): summary`. First commit copies this plan to `docs/superpowers/plans/`.
- Gate: `docker compose exec -T frontend npm run check`.
- After the gate, one manual check in the running app at https://localhost:8443: in list and magazine layouts, favourite, keep, tick and open an entry. Do the same once inside a duplicates popover and once in the open article.

---

### Task 1: The handler, provided by the shell

**Files:**
- Create: `frontend/src/app/reader/entry-actions/entry-action-handler.ts`
- Modify: `frontend/src/app/reader/reader-shell.component.ts` (decorator `providers`, ~113; handlers `onFavorite`/`onKeep`/`onToggleViewed` ~840–854 and `onOpen` ~976), `reader-shell.component.spec.ts` (7 references)

- [ ] **Step 1: Create the token**

```ts
import { Injectable } from '@angular/core';
import { EntryDto } from '../models';

@Injectable({ providedIn: 'root', useFactory: () => IGNORED_ENTRY_ACTIONS })
export abstract class EntryActionHandler {
  abstract favorite(entry: EntryDto): void;
  abstract keep(entry: EntryDto): void;
  abstract toggleRead(entry: EntryDto): void;
  abstract open(entry: EntryDto): void;
}

const IGNORED_ENTRY_ACTIONS: EntryActionHandler = {
  favorite: () => undefined,
  keep: () => undefined,
  toggleRead: () => undefined,
  open: () => undefined,
};
```

- [ ] **Step 2: The shell implements it.**
  - Rename `onFavorite` → `favorite`, `onKeep` → `keep`, `onToggleViewed` → `toggleRead` and `onOpen` → `open`, in the component, its template and its spec.
  - Add `implements EntryActionHandler` to the class.
  - Set `providers: [SidebarCountsPoll, { provide: EntryActionHandler, useExisting: forwardRef(() => ReaderShellComponent) }]`.
  - Arrow-function properties satisfy the abstract methods under `implements`.

- [ ] **Step 3:** Run `docker compose exec -T frontend npx jest src/app/reader/reader-shell` and expect PASS. Commit `refactor(#1302): shell is the entry action handler`.

### Task 2: Leaves call the handler; forwarding is deleted

**Files:**
- Leaves:
  - `reader/entry-actions/entry-actions.component.ts` + `.html`
  - `reader/magazine/entry-block-base.ts` + every magazine block's `.html` (hero, wide, quote, split, kicker, thumb, compact)
  - `reader/entry-row/entry-row.component.ts` + `.html`
  - `reader/magazine/entry-duplicates.component.ts` + `.html`
- Forwarders (delete outputs and bindings):
  - `reader/entry-meta/entry-meta.component.ts` + `.html`
  - `reader/magazine/source-group.component.ts` + `.html`
  - `reader/entry-list/entry-list.component.ts` + `.html` (lines ~291–371, 422–431)
  - `reader/reader-view/reader-view.component.ts` + `.html` (outputs `favorite`/`keep`/`read` ~165–167, and the bindings ~140–142)
  - `reader/reader-shell.component.html`
- Specs of all of the above

- [ ] **Step 1: Leaves.** Each leaf gets `private readonly actions = inject(EntryActionHandler);`.
  - **entry-actions:** delete its three outputs. The template's `favorite.emit(entry())` becomes `actions.favorite(entry())`, and likewise `keep`, and `read` → `actions.toggleRead(entry())`. Because the template reads it, make the field `protected` instead of private.
  - **entry-block-base:** delete `open`/`favorite`/`keep`/`read` and their doc comment. Add:

    ```ts
    protected readonly actions = inject(EntryActionHandler);
    ```

    Each block template's `open.emit(entry())` (click, enter and space handlers) becomes `actions.open(entry())`. Keep the rest of the base's doc comment only if it still says something true.
  - **entry-row:** same as the block base; its `open.emit(entry())` handlers call `actions.open(entry())`.
  - **entry-duplicates** decorates the handler for the rows inside its popover, so opening a copy still closes the popover and each toggle still flips the clone. Delete its four outputs, and delete the four event bindings on its inner `<app-entry-row>`. Then turn the component into the handler for its children:

```ts
@Component({
  // …existing metadata…
  providers: [{ provide: EntryActionHandler, useExisting: forwardRef(() => EntryDuplicatesComponent) }],
})
export class EntryDuplicatesComponent implements EntryActionHandler {
  private readonly shellActions = inject(EntryActionHandler, { skipSelf: true });

  open(copy: EntryDto): void {
    this.shellActions.open(copy);
    this.close();
  }

  // Call before flipping the clone: the shell reads the flag's pre-toggle value.
  favorite(copy: EntryDto): void {
    this.shellActions.favorite(copy);
    this.displayed.update((current) => (current ? { ...current, isFavorite: !current.isFavorite } : current));
  }

  keep(copy: EntryDto): void {
    this.shellActions.keep(copy);
    this.displayed.update((current) => (current ? { ...current, isKept: !current.isKept } : current));
  }

  toggleRead(copy: EntryDto): void {
    this.shellActions.toggleRead(copy);
    this.displayed.update((current) => (current ? { ...current, isViewed: !current.isViewed } : current));
  }
}
```

    These four replace `openCopy`/`favoriteCopy`/`keepCopy`/`readCopy`. Check the template for other callers of the old names. If the popover's trigger button calls `open` for another purpose, rename that one; `open` now belongs to the handler.
- [ ] **Step 2: Forwarders.** In entry-meta, source-group, entry-list, reader-view and every magazine block, delete every `(favorite)=`, `(keep)=`, `(read)=` and `(open)=` binding that only re-emits. Delete the matching `output<…>()` declarations. In the shell template, delete the `(favorite)`/`(keep)`/`(read)`/`(open)` bindings on `<app-entry-list>` and on both `<app-reader-view>`. Then delete `withOpen` if only `(openOriginal)` still uses it, rewriting that binding as `(openOriginal)="openEntry() && onOpenOriginal(openEntry()!)"`. If lint rejects the non-null assertion, keep `withOpen` for it instead.
- [ ] **Step 3: Specs.** Specs that asserted a forwarded output was emitted now provide a spy handler and assert on it:

```ts
const actions = { favorite: jest.fn(), keep: jest.fn(), toggleRead: jest.fn(), open: jest.fn() };
// providers: [{ provide: EntryActionHandler, useValue: actions }]
expect(actions.favorite).toHaveBeenCalledWith(entry);
```

Delete a spec that only checked forwarding between two layers that no longer exist. Keep the leaf-level assertions.

- [ ] **Step 4: Verify.** `grep -rn "favorite.emit\|keep.emit\|read.emit\|open.emit" frontend/src/app/reader` prints nothing. Run the gate, then do the manual check from the Global Constraints. Commit `refactor(#1302): entry actions go straight to the handler`.

### Task 3: One list, one reader view in the shell template; the sidebar calls ManageActions

**Files:** `reader/reader-shell.component.html` + `.ts`, `reader/sidebar/sidebar.component.ts` + `.html` + spec

- [ ] **Step 1: Shell template.**
  - Wrap the `<section class="list"><app-entry-list …/></section>` block once in `<ng-template #entryListPane>…</ng-template>`, placed after `</main>`.
  - Replace both copies with `<ng-container *ngTemplateOutlet="entryListPane" />`.
  - Do the same for the reader view with a context:

    ```html
    <ng-template #readerPane let-fullscreen="fullscreen">
      <app-reader-view
        [entry]="openEntry()"
        [tags]="openEntryTags()"
        [fullscreen]="fullscreen"
        (openOriginal)="…as after Task 2…"
        (close)="onCloseReader()"
      />
    </ng-template>
    ```

    Use it in the split pane as `<ng-container *ngTemplateOutlet="readerPane; context: { fullscreen: false }" />` and in the overlay with `context: { fullscreen: !screen.isWide() }`. Check `reader-view`'s `fullscreen` input default: if it isn't `false`, pass whatever the split pane gets today, which is nothing, i.e. its default.
  - Import `NgTemplateOutlet` in the shell.
  - Keep the existing HTML comments (#810, #128) next to the markup they describe.
- [ ] **Step 2: Sidebar.** Inject `ManageActions` in the sidebar (`private readonly manage = inject(ManageActions);`). Replace each `X.emit(arg)` for these outputs with the call the shell makes today, then delete the output and its shell binding.

| Sidebar output | Direct call |
|---|---|
| `editTag` | `manage.editTag(tag)` |
| `deleteTag` | `manage.deleteTag(tag)` |
| `editFeed` | `manage.editSubscription(sub)` |
| `unsubscribe` | `manage.unsubscribe(sub)` |
| `toggleAllItems` | `manage.setIncludeInAllItems(sub, !sub.includeInAllItems)` |
| `toggleForYou` | `manage.setIncludeInForYou(sub, !sub.includeInForYou)` |
| `moveFeed` | `manage.moveFeedToTag(sub, fromTagId, toTagId, position)` |
| `reorderTags` | `manage.reorderTags(ids)` |
| `reorderUntagged` | `manage.reorderUntagged(ids)` |
| `reorderTagFeeds` | `manage.reorderTagFeeds(tagId, subscriptionIds)` |

  Where an emit happens in the template, call a `protected` field `manage` from the template. `refresh`, `addFeed`, `search` and `toggleDigest` stay as outputs, because the shell adds its own logic to them.
- [ ] **Step 3: Sidebar spec.** Assertions on these outputs become assertions on a `ManageActions` spy (`{ provide: ManageActions, useValue: { editTag: jest.fn(), … } }`), with the same arguments as before.
- [ ] **Step 4:** Run the gate and the manual check. Also, in the running app, drag a feed between tags and edit a tag from the sidebar menu. Commit `refactor(#1302): shell renders list and reader once; sidebar manages feeds directly`, open the PR (`Closes #1302`) and merge when green.
