# Manage social feeds Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Turn the social "Select feeds" picker into "Manage feeds": a tick always means *subscribed*, unticking any subscribed row unsubscribes it, and the picker opens from the sidebar's network head too.

**Architecture:** The backend already unsubscribes any owned subscription id sent in `unsubscribe`; it changes only so a truncated follow list keeps leftover subscriptions as `subscribed`, and so the sidebar's account refs carry `status`. The frontend moves the picker out of `settings/` into `reader/feeds/social-picker/` (reader may not import settings; settings may import reader), adds a root `SocialFeedsPicker` opener used by both settings and the sidebar, makes every subscribed row removable, and gives the sidebar's network head a "⋯" menu (inline popover on fine pointers, action sheet on coarse).

**Tech Stack:** Symfony 7.4 / PHP 8.4 / PHPUnit 12; Angular 20 standalone + signals, CDK Dialog, Transloco, Jest.

**Spec:** GitHub issue #1677 (`gh issue view 1677`).

## Global Constraints

- Branch `feature/1677-manage-social-feeds`; commits `type(#1677): lower-case summary`; no Claude attribution anywhere.
- A tick means subscribed, for follows, lists, custom feeds **and Home**; nothing is locked.
- "No longer followed" keeps its badge and chip; such a row shows **ticked**; unticking unsubscribes. No automatic cleanup.
- Truncated follow list (over 5000): leftover profile subscriptions show as `subscribed` (ticked, no badge), not dropped.
- Leftover rows keep showing the DID as the handle (unchanged).
- Copy (en / de):
  - Apply button: "Save" / "Speichern"; with count: "Save {{count}} changes" / "{{count}} Änderungen speichern"
  - Dialog title: "Manage feeds of {{handle}}" / "Feeds von {{handle}} verwalten"
  - Hint: "Untick a feed to unsubscribe." / "Entferne den Haken, um einen Feed abzubestellen."
  - Settings section title: "Manage feeds" / "Feeds verwalten"
  - Settings button: "Manage feeds…" / "Feeds verwalten…"
- Removal confirm on save names how many feeds go (one feed: by name).
- Sidebar: network head "⋯" → "Manage feeds…" (one account on the network) or one "Manage feeds of {{handle}}" per account; an account with `status === 'needs_reconnect'` shows its item disabled, labelled "Reconnect {{handle}} in settings" / "{{handle}} in den Einstellungen neu verbinden".
- Frontend tests run in the container: `docker compose exec -T frontend npx jest <path>`; the gate is `docker compose exec -T frontend npm run check`.
- Backend: `php bin/phpunit <path>` natively; `composer check` and `composer md` must be clean for touched `src` files.
- Clean Code rules from CLAUDE.md apply (no abbreviations, no comment unless a future reader would get it wrong, guard clauses).

---

### Task 1: Backend — a truncated follow list keeps leftovers as subscribed

**Files:**
- Modify: `backend/src/Service/Social/Model/ImportCandidateModel.php`
- Modify: `backend/src/Service/Social/ImportCandidates.php:52,92-108`
- Test: `backend/tests/Service/Social/ImportCandidatesTest.php:187-205`

**Interfaces:**
- Produces: `ImportCandidateModel::asSubscribed(): self` (same account and subscription id, state `Subscribed`).

- [ ] **Step 1: Rewrite the truncation test**

Replace `testACutOffFollowListOffersNothingAsNoLongerFollowed` with:

```php
    public function testACutOffFollowListShowsAnUnmatchedSubscriptionAsSubscribed(): void
    {
        $account = $this->account();
        $bob = $this->subscribe('https://bsky.app/profile/did:plc:bob/rss');
        $beyondTheCut = $this->subscribe('https://bsky.app/profile/did:plc:beyond-the-cut/rss');
        $network = new StubSocialCandidates(new NetworkCandidatesModel(
            [self::follow('did:plc:bob', '@bob.bsky.social'), self::follow('did:plc:amy', '@amy.bsky.social')],
            [],
            [],
            true,
        ));

        $follows = $this->importCandidates($network)->forAccount($account)->follows;

        self::assertSame(
            [
                ['@amy.bsky.social', 'new', null],
                ['@bob.bsky.social', 'subscribed', $bob->requireId()],
                ['did:plc:beyond-the-cut', 'subscribed', $beyondTheCut->requireId()],
            ],
            self::summary($follows),
        );
    }
```

- [ ] **Step 2: Run it, expect FAIL** (the leftover is dropped today)

Run: `cd backend && php bin/phpunit --filter testACutOffFollowListShowsAnUnmatchedSubscriptionAsSubscribed tests/Service/Social/ImportCandidatesTest.php`

- [ ] **Step 3: Implement**

In `ImportCandidateModel` add:

```php
    public function asSubscribed(): self
    {
        return new self($this->account, ImportCandidateState::Subscribed, $this->subscriptionId);
    }
```

In `ImportCandidates`, replace `trustedFollows` with:

```php
    /**
     * @param list<ImportCandidateModel> $follows
     *
     * @return list<ImportCandidateModel> a cut-off follow list cannot tell an unfollow, so a leftover stays subscribed
     */
    private static function trustedFollows(NetworkCandidatesModel $network, array $follows): array
    {
        if (!$network->truncated) {
            return $follows;
        }

        return array_map(
            static fn (ImportCandidateModel $follow): ImportCandidateModel => ImportCandidateState::NoLongerFollowed === $follow->state
                ? $follow->asSubscribed()
                : $follow,
            $follows,
        );
    }
```

(`CandidateOrder::follows` runs after this, so the re-stated rows sort among the subscribed ones.)

- [ ] **Step 4: Run the whole test class, expect PASS**

Run: `cd backend && php bin/phpunit tests/Service/Social/ImportCandidatesTest.php`

- [ ] **Step 5: Gates on touched files and commit**

Run: `cd backend && composer cs && composer stan && composer md`

```bash
git add backend/src/Service/Social/Model/ImportCandidateModel.php backend/src/Service/Social/ImportCandidates.php backend/tests/Service/Social/ImportCandidatesTest.php
git commit -m "feat(#1677): a cut-off follow list keeps leftover feeds subscribed"
```

---

### Task 2: Backend — the sidebar's account refs carry their status

**Files:**
- Modify: `backend/src/Http/SubscriptionJson.php:135-143`
- Test: `backend/tests/Http/SubscriptionJsonListTest.php:61-67`, `backend/tests/Controller/Api/SubscriptionSocialListTest.php:45-48`

**Interfaces:**
- Produces: `/api/subscriptions` → `socialAccounts[]` items `{id, network, handle, status: 'connected'|'needs_reconnect'}`.

- [ ] **Step 1: Extend both expectations** — add `'status' => 'connected'` to every expected account array in the two tests (three arrays in total).

- [ ] **Step 2: Run, expect FAIL**

Run: `cd backend && php bin/phpunit tests/Http/SubscriptionJsonListTest.php tests/Controller/Api/SubscriptionSocialListTest.php`

- [ ] **Step 3: Implement**

```php
    /** @return array{id: int, network: string, handle: string, status: string} */
    private static function socialAccount(SocialAccount $account): array
    {
        return [
            'id' => $account->requireId(),
            'network' => $account->getNetwork()->value,
            'handle' => $account->getHandle(),
            'status' => $account->getStatus()->value,
        ];
    }
```

Fix the `@param` line above `list()` if it names only handles: `the handles and statuses the sidebar labels a network's accounts with`.

- [ ] **Step 4: Run, expect PASS**; also `cd backend && composer stan`.

- [ ] **Step 5: Commit**

```bash
git add backend/src/Http/SubscriptionJson.php backend/tests/Http/SubscriptionJsonListTest.php backend/tests/Controller/Api/SubscriptionSocialListTest.php
git commit -m "feat(#1677): name each social account's status in the subscriptions list"
```

---

### Task 3: Frontend — move the picker into the reader and add one opener

Pure move plus an opener service; behaviour unchanged, all existing specs keep passing.

**Files:**
- Move (`git mv`) from `frontend/src/app/settings/social/` to `frontend/src/app/reader/feeds/social-picker/`:
  `social-import-dialog.component.{ts,html,scss,spec.ts}`, `social-import-rows.{ts,spec.ts}`, `social-import.service.{ts,spec.ts}`
- Create: `frontend/src/app/reader/feeds/social-picker/social-feeds-picker.service.ts`, `…/social-feeds-picker.service.spec.ts`
- Modify: `frontend/src/app/settings/social/social-section.component.ts:20-23,150-162`, `…/social-section.component.spec.ts`

**Interfaces:**
- Produces:
  ```ts
  export interface PickerAccount { readonly id: number; readonly network: SocialNetworkName; readonly handle: string; }
  @Injectable({ providedIn: 'root' }) export class SocialFeedsPicker {
    open(account: PickerAccount): Observable<SocialImportResult | undefined>;
  }
  ```

- [ ] **Step 1: Move the files**

```bash
cd frontend/src/app
mkdir -p reader/feeds/social-picker
for f in social-import-dialog.component.ts social-import-dialog.component.html social-import-dialog.component.scss social-import-dialog.component.spec.ts social-import-rows.ts social-import-rows.spec.ts social-import.service.ts social-import.service.spec.ts; do git mv settings/social/$f reader/feeds/social-picker/$f; done
```

- [ ] **Step 2: Rewrite relative imports in the moved files** (one level deeper, and inside `reader`):
  - `'../../core/…'` → `'../../../core/…'`
  - `'../../shared/…'` → `'../../../shared/…'`
  - `'../../reader/state/…'` → `'../../state/…'`
  - `'../../reader/feeds/display-title'` → `'../display-title'`
  - `'../../reader/social-feed'` → `'../../social-feed'`
  - `'../../../testing/…'` → `'../../../../testing/…'`
  - scss `@use '../../theme/glass-rim'` → `@use '../../../theme/glass-rim'`

  Verify: `grep -rn "from '\.\./\.\./reader\|settings/" src/app/reader/feeds/social-picker` prints nothing.

- [ ] **Step 3: Write the opener's failing spec** `social-feeds-picker.service.spec.ts`:

```ts
import { TestBed } from '@angular/core/testing';
import { Dialog } from '@angular/cdk/dialog';
import { of } from 'rxjs';
import { SocialFeedsPicker } from './social-feeds-picker.service';
import { SocialImportDialogComponent } from './social-import-dialog.component';

describe('SocialFeedsPicker', () => {
  it('opens the picker for the account and hands back how it closed', () => {
    const result = { imported: 1, alreadySubscribed: 0, invalid: 0, skippedOverLimit: 0, removed: 0 };
    const open = jest.fn(() => ({ closed: of(result) }));
    TestBed.configureTestingModule({ providers: [{ provide: Dialog, useValue: { open } }] });
    const closed = jest.fn();

    TestBed.inject(SocialFeedsPicker)
      .open({ id: 4, network: 'bluesky', handle: '@alice.bsky.social' })
      .subscribe(closed);

    expect(open).toHaveBeenCalledWith(SocialImportDialogComponent, {
      data: { accountId: 4, network: 'bluesky', handle: '@alice.bsky.social' },
      panelClass: 'app-dialog',
    });
    expect(closed).toHaveBeenCalledWith(result);
  });
});
```

- [ ] **Step 4: Run, expect FAIL** (module missing)

Run: `docker compose exec -T frontend npx jest src/app/reader/feeds/social-picker`

- [ ] **Step 5: Implement** `social-feeds-picker.service.ts`:

```ts
import { Injectable, inject } from '@angular/core';
import { Dialog } from '@angular/cdk/dialog';
import { Observable } from 'rxjs';
import { SocialNetworkName } from '../../../core/social-network';
import { SocialImportDialogComponent, SocialImportDialogData } from './social-import-dialog.component';
import { SocialImportResult } from './social-import.service';

export interface PickerAccount {
  readonly id: number;
  readonly network: SocialNetworkName;
  readonly handle: string;
}

/** Opens one account's Manage feeds picker, from settings or the sidebar. */
@Injectable({ providedIn: 'root' })
export class SocialFeedsPicker {
  private readonly dialog = inject(Dialog);

  open(account: PickerAccount): Observable<SocialImportResult | undefined> {
    const data: SocialImportDialogData = {
      accountId: account.id,
      network: account.network,
      handle: account.handle,
    };
    return this.dialog.open<SocialImportResult | undefined>(SocialImportDialogComponent, {
      data,
      panelClass: 'app-dialog',
    }).closed;
  }
}
```

- [ ] **Step 6: Settings uses the opener.** In `social-section.component.ts` drop the dialog imports and the `Dialog` injection if `openImport` was its only use (check `grep -n "this.dialog" social-section.component.ts`), inject `private readonly picker = inject(SocialFeedsPicker);` (import from `'../../reader/feeds/social-picker/social-feeds-picker.service'`) and:

```ts
  protected openImport(account: SocialAccount): void {
    this.picker.open(account).subscribe(() => this.svc.load());
  }
```

In `social-section.component.spec.ts` replace the `Dialog` stub used for the import button with a `SocialFeedsPicker` stub `{ open: jest.fn(() => of(undefined)) }` and assert `expect(pickerStub.open).toHaveBeenCalledWith(expect.objectContaining({ id: 1 }))` where the test previously asserted `dialogStub.open` with `SocialImportDialogComponent`; keep the "reloads the accounts when the picker closes" assertion against `pickerStub.open`'s observable. Remove the now unused `SocialImportDialogComponent` import.

- [ ] **Step 7: Run all three areas, expect PASS**

Run: `docker compose exec -T frontend npx jest src/app/reader/feeds/social-picker src/app/settings/social`

- [ ] **Step 8: Commit**

```bash
git add -A frontend/src/app/settings/social frontend/src/app/reader/feeds/social-picker
git commit -m "refactor(#1677): move the social feed picker into the reader behind one opener"
```

---

### Task 4: Frontend — a tick means subscribed; unticking unsubscribes any subscribed row

**Files:**
- Modify: `frontend/src/app/reader/feeds/social-picker/social-import-rows.ts`
- Modify: `frontend/src/app/reader/feeds/social-picker/social-import-dialog.component.{ts,html}`
- Modify: `frontend/public/i18n/en.json`, `frontend/public/i18n/de.json` (`settings.social.*`)
- Test: `…/social-import-rows.spec.ts`, `…/social-import-dialog.component.spec.ts`

**Interfaces:**
- Produces (rows file):
  ```ts
  export interface Removal { readonly name: string; readonly subscriptionId: number; }
  export function removals(candidates: ImportCandidates | null, chosen: ReadonlySet<string>): Removal[];
  export function isRemovable(collection: CollectionCandidate): boolean; // now: state === 'subscribed'
  ```
  `ImportRow` loses `locked`.

- [ ] **Step 1: Rewrite the dialog specs that encode the old rule** (en strings come from `en.json` through `provideTranslocoTesting`):

Replace `'marks each follow with its state and shows subscribed ones checked and fixed'` with:

```ts
  it('ticks every subscribed follow, a no longer followed one too, and fixes none', () => {
    const fixture = loaded(candidates({ follows: [follow(), CAROL, DAVE] }));

    expect(checkboxOf(fixture, 'did:plc:bob').checked).toBe(false);
    expect(checkboxOf(fixture, 'did:plc:carol').checked).toBe(true);
    expect(checkboxOf(fixture, 'did:plc:dave').checked).toBe(true);
    for (const identity of ['did:plc:bob', 'did:plc:carol', 'did:plc:dave']) {
      expect(checkboxOf(fixture, identity).disabled).toBe(false);
    }
    const text = byTestId(fixture, 'social-import-follows')?.textContent ?? '';
    expect(text).toContain('New');
    expect(text).toContain('Subscribed');
    expect(text).toContain('No longer followed');
  });
```

In `'offers lists and custom feeds as checkboxes; …'` rename to `'offers lists and custom feeds as checkboxes; every added one, Home too, stays ticked and can be unticked'` and change the Home expectations to:

```ts
    expect(collectionBox(fixture, 'home:').checked).toBe(true);
    expect(collectionBox(fixture, 'home:').disabled).toBe(false);
    expect(collectionBox(fixture, 'home:').getAttribute('aria-label')).toBe('Remove Home timeline');
```

Replace `'never unticks a subscribed Home'` with:

```ts
  it('removes a subscribed Home when it is unticked', () => {
    const home = collection({ kind: 'home', id: null, name: 'Home', state: 'subscribed', subscriptionId: 11 });
    const fixture = loaded(candidates({ lists: [home] }));
    showTab(fixture, 'Lists');

    collectionBox(fixture, 'home:').click();
    fixture.detectChanges();
    press(fixture, 'social-import-apply');

    const post = http.expectOne(IMPORT);
    expect(post.request.body).toEqual({ subscribe: [], unsubscribe: [11], collections: [] });
    post.flush({ imported: 0, alreadySubscribed: 0, invalid: 0, skippedOverLimit: 0, removed: 1 });
  });
```

Add:

```ts
  it('unsubscribes a subscribed follow when it is unticked, after asking by name', () => {
    const fixture = loaded(candidates({ follows: [CAROL] }));

    tick(fixture, 'did:plc:carol');
    expect(checkboxOf(fixture, 'did:plc:carol').checked).toBe(false);
    press(fixture, 'social-import-apply');

    const [data] = confirmStub.confirmThen.mock.calls[0];
    expect(data.message).toContain('Carol');
    const post = http.expectOne(IMPORT);
    expect(post.request.body).toEqual({ subscribe: [], unsubscribe: [9], collections: [] });
    post.flush({ imported: 0, alreadySubscribed: 0, invalid: 0, skippedOverLimit: 0, removed: 1 });
  });

  it('asks by count when several feeds go', () => {
    const fixture = loaded(
      candidates({ follows: [CAROL, DAVE], lists: [collection({ state: 'subscribed', subscriptionId: 12 })] }),
    );
    tick(fixture, 'did:plc:carol');
    tick(fixture, 'did:plc:dave');
    showTab(fixture, 'Lists');
    collectionBox(fixture, 'list:at://list/a').click();
    fixture.detectChanges();

    press(fixture, 'social-import-apply');

    const [data] = confirmStub.confirmThen.mock.calls[0];
    expect(data.message).toContain('Remove these 3 feeds and their entries from your feeds?');
    const post = http.expectOne(IMPORT);
    expect(post.request.body).toEqual({ subscribe: [], unsubscribe: [9, 10, 12], collections: [] });
    post.flush({ imported: 0, alreadySubscribed: 0, invalid: 0, skippedOverLimit: 0, removed: 3 });
  });
```

Change `'hints how to unsubscribe only while a leftover is listed'` to:

```ts
  it('hints how to unsubscribe only while a subscribed row is listed', () => {
    const hint = 'Untick a feed to unsubscribe.';
    const fixture = loaded(candidates({ follows: [follow(), CAROL] }));
    expect((fixture.nativeElement as HTMLElement).textContent).toContain(hint);

    showState(fixture, 'new');
    expect((fixture.nativeElement as HTMLElement).textContent).not.toContain(hint);

    showState(fixture, 'all');
    showTab(fixture, 'Lists');
    expect((fixture.nativeElement as HTMLElement).textContent).not.toContain(hint);
  });
```

In `'counts the selection in the footer and on the Import button'` rename to `'… on the Save button'`, expect `'Save'` and `'Save 2 changes'`.

In `'imports the chosen follows, unsubscribes the chosen leftovers and reloads the reader'` and `'skips the refresh when nothing new was added'` the DAVE `tick` now *unticks* a ticked row — the request bodies stay the same (`unsubscribe: [10]`); keep them, they prove the leftover path still works.

In `'names the handle on the checkbox of every row state'` expect CAROL's label `'Unsubscribe from @carol.bsky.social'`.

Wherever a spec relied on `confirmThen` not being called for a follow-only removal, it now is called: the stub runs the action, so bodies are unchanged.

- [ ] **Step 2: Run, expect FAIL**

Run: `docker compose exec -T frontend npx jest src/app/reader/feeds/social-picker`

- [ ] **Step 3: Rows** — in `social-import-rows.ts`:
  - Delete `locked` from `ImportRow`, from both row builders, and `COLLECTION_CHECKBOX_KEYS`.
  - `CHECKBOX_KEYS`: `subscribed: 'settings.social.importUnsubscribe'` (`new` and `no_longer_followed` unchanged).
  - `isRemovable`: `return collection.state === 'subscribed';` and its doc line: `/** Any added list, custom feed or Home can be unticked to remove it. */`
  - Follow row: `removable: follow.state !== 'new',`
  - `collectionCheckboxKey(removable)`: `return removable ? 'settings.social.importRemoveCollection' : 'settings.social.importAddCollection';` (drop the `collection` parameter).
  - Add:

```ts
export interface Removal {
  readonly name: string;
  readonly subscriptionId: number;
}

/** The subscribed rows the user unticked, follows first. */
export function removals(
  candidates: ImportCandidates | null,
  chosen: ReadonlySet<string>,
): Removal[] {
  const follows = (candidates?.follows ?? [])
    .filter((follow) => follow.state !== 'new' && chosen.has(followKey(follow)))
    .map((follow) => ({ name: follow.displayName ?? follow.handle, subscriptionId: follow.subscriptionId }));
  const collections = [...(candidates?.lists ?? []), ...(candidates?.customFeeds ?? [])]
    .filter((collection) => isRemovable(collection) && chosen.has(collectionKey(collection)))
    .map((collection) => ({ name: collection.name, subscriptionId: collection.subscriptionId }));
  return [...follows, ...collections].flatMap(({ name, subscriptionId }) =>
    subscriptionId === null ? [] : [{ name, subscriptionId }],
  );
}
```

  Add to `social-import-rows.spec.ts`:

```ts
describe('removals', () => {
  it('lists the unticked subscribed follows and collections, skipping new rows', () => {
    const candidates = {
      follows: [
        { identity: 'a', handle: '@a', displayName: 'Ann', avatarUrl: null, feedUrl: 'u:a', state: 'subscribed' as const, subscriptionId: 1 },
        { identity: 'b', handle: 'did:plc:b', displayName: null, avatarUrl: null, feedUrl: 'u:b', state: 'no_longer_followed' as const, subscriptionId: 2 },
        { identity: 'c', handle: '@c', displayName: null, avatarUrl: null, feedUrl: 'u:c', state: 'new' as const, subscriptionId: null },
      ],
      lists: [{ kind: 'home' as const, id: null, name: 'Home', state: 'subscribed' as const, subscriptionId: 3 }],
      customFeeds: [],
      truncated: false,
    };

    expect(removals(candidates, new Set(['follow:u:a', 'follow:u:b', 'follow:u:c', 'home:']))).toEqual([
      { name: 'Ann', subscriptionId: 1 },
      { name: 'did:plc:b', subscriptionId: 2 },
      { name: 'Home', subscriptionId: 3 },
    ]);
  });
});
```

- [ ] **Step 4: Dialog** — in `social-import-dialog.component.ts`:
  - `toggle(row)`: drop the `if (row.locked) return;` guard.
  - Replace `hasLeftovers` with `protected readonly hasSubscribedRows = computed(() => this.visibleCount('subscribed') + this.visibleCount('no_longer_followed') > 0);`
  - Replace `apply`, `chosenRemovals`, `untickedCollections`, `removalMessage` with:

```ts
  protected apply(): void {
    const unticked = removals(this.importer.candidates(), this.chosen());
    if (unticked.length === 0) {
      this.send();
      return;
    }
    this.confirm.confirmThen(
      {
        title: this.i18n.translate('settings.social.importRemoveTitle'),
        message: this.removalMessage(unticked),
        confirmLabel: this.i18n.translate('settings.social.importRemoveConfirm'),
        danger: true,
      },
      () => this.send(),
    );
  }

  private chosenRemovals(): number[] {
    return removals(this.importer.candidates(), this.chosen()).map((removal) => removal.subscriptionId);
  }

  private removalMessage(unticked: readonly Removal[]): string {
    const removal =
      unticked.length === 1
        ? this.i18n.translate('settings.social.importRemoveMessage', { names: unticked[0].name })
        : this.i18n.translate('settings.social.importRemoveCountMessage', { count: unticked.length });
    const moves = this.i18n.translate('manage.savedPostsMove', {
      saved: savedFeedLabel(this.i18n, this.data.network),
    });
    return `${removal} ${moves}`;
  }
```

  Fix imports (`removals`, `Removal`; drop `isRemovable`, `CollectionCandidate` if unused).
  - Template: checkbox becomes `[checked]="row.removable ? !picked : picked"` with no `[disabled]`; hint `@if (hasSubscribedRows())`.

- [ ] **Step 5: i18n** — `settings.social` in both files:

| key | en | de |
|---|---|---|
| `importTitle` | `Manage feeds of {{handle}}` | `Feeds von {{handle}} verwalten` |
| `importUnsubscribeHint` | `Untick a feed to unsubscribe.` | `Entferne den Haken, um einen Feed abzubestellen.` |
| `importApply` | `Save` | `Speichern` |
| `importApplyCount` | `Save {{count}} changes` | `{{count}} Änderungen speichern` |
| `importRemoveMessage` | `Remove {{names}} and its entries from your feeds?` (unchanged) | unchanged |
| new `importRemoveCountMessage` | `Remove these {{count}} feeds and their entries from your feeds?` | `Diese {{count}} Feeds und ihre Einträge aus deinen Feeds entfernen?` |
| delete | `importAlreadySubscribed`, `importCollectionAdded` | same |

  Confirm deletions are unused: `grep -rn "importAlreadySubscribed\|importCollectionAdded" frontend/src frontend/e2e` prints nothing.

- [ ] **Step 6: Run, expect PASS**

Run: `docker compose exec -T frontend npx jest src/app/reader/feeds/social-picker src/app/settings/social`

- [ ] **Step 7: Commit**

```bash
git add frontend/src/app/reader/feeds/social-picker frontend/public/i18n/en.json frontend/public/i18n/de.json
git commit -m "feat(#1677): a tick means subscribed and unticking any subscribed feed removes it"
```

---

### Task 5: Frontend — settings copy says Manage feeds

**Files:**
- Modify: `frontend/public/i18n/en.json`, `frontend/public/i18n/de.json`
- Test: `frontend/src/app/settings/social/social-section.component.spec.ts`

- [ ] **Step 1: Failing spec** — add to `social-section.component.spec.ts` (reuse its existing loaded-accounts helper and `byTestId`):

```ts
  it('offers each account’s feeds under Manage feeds', () => {
    // mount with one connected account exactly like the existing "opens the picker" test
    const section = byTestId(fixture, 'social-import-section') as HTMLElement;
    expect(section.textContent).toContain('Manage feeds');
    expect(byTestId(fixture, 'social-import-1')?.textContent?.trim()).toBe('Manage feeds…');
  });
```

- [ ] **Step 2: Run, expect FAIL**: `docker compose exec -T frontend npx jest src/app/settings/social/social-section`

- [ ] **Step 3: i18n**

| key | en | de |
|---|---|---|
| `importSectionTitle` | `Manage feeds` | `Feeds verwalten` |
| `importChoose` | `Manage feeds…` | `Feeds verwalten…` |
| `importSectionCaption` | `Choose which of your Bluesky and Mastodon follows, lists and feeds arrive here as feeds; follows land under the Feeds row of the Bluesky or Mastodon section. A tick means you are subscribed: untick a feed to unsubscribe, and come back any time for new follows. Lists and feeds you save or create on the network are added within the hour, and ones you remove there go too; anything you remove here is not added back.` | `Wähle, welche deiner Bluesky- und Mastodon-Konten, -Listen und -Feeds hier als Feeds ankommen; gefolgte Konten landen unter „Feeds“ im Bluesky- oder Mastodon-Bereich. Ein Haken heißt abonniert: Entferne ihn, um einen Feed abzubestellen, und schau jederzeit wieder für neu Gefolgte vorbei. Listen und Feeds, die du im Netzwerk speicherst oder anlegst, kommen innerhalb einer Stunde dazu, und was du dort entfernst, verschwindet auch hier; was du hier entfernst, wird nicht wieder hinzugefügt.` |

- [ ] **Step 4: Run, expect PASS**, then commit:

```bash
git add frontend/public/i18n/en.json frontend/public/i18n/de.json frontend/src/app/settings/social/social-section.component.spec.ts
git commit -m "feat(#1677): settings names the picker Manage feeds"
```

---

### Task 6: Frontend — an action sheet item can be disabled

**Files:**
- Modify: `frontend/src/app/shared/action-sheet/action-sheet.component.{ts,html}`
- Test: `frontend/src/app/shared/action-sheet/action-sheet.service.spec.ts`

**Interfaces:**
- Produces: `ActionSheetAction.disabled?: boolean`.

- [ ] **Step 1: Failing spec** — add next to `'renders the title and one menu item per action, flagging danger'`, following that test's setup:

```ts
  it('renders a disabled action as a disabled item', () => {
    // open the sheet as the neighbouring test does, with
    // actions: [{ id: 'manage', label: 'Reconnect @a in settings', disabled: true }]
    expect(items()[0].disabled).toBe(true);
  });
```

- [ ] **Step 2: Run, expect FAIL**: `docker compose exec -T frontend npx jest src/app/shared/action-sheet`

- [ ] **Step 3: Implement** — interface gets `disabled?: boolean;`; template button gets `[disabled]="action.disabled"`. If `action-sheet.component.scss` has no disabled style, add `button:disabled { opacity: 0.5; cursor: default; }` (tokens only, no hex).

- [ ] **Step 4: Run, expect PASS**, commit:

```bash
git add frontend/src/app/shared/action-sheet
git commit -m "feat(#1677): an action sheet item can be disabled"
```

---

### Task 7: Frontend — the sidebar's network head opens Manage feeds

**Files:**
- Modify: `frontend/src/app/reader/social-feed.ts` (`SocialAccountRefDto` gains `status`)
- Modify: `frontend/src/app/reader/state/social-sections.ts` (`SocialSection.networkAccounts`)
- Modify: `frontend/src/app/reader/shell/sidebar/sidebar-social-section.component.{ts,html,scss}`
- Modify: `frontend/src/app/reader/shell/sidebar/sidebar-row-actions.service.ts` (`openSocialSheet`)
- Modify: `frontend/public/i18n/en.json`, `de.json` (`reader.*`)
- Modify fixtures that build `SocialSection` or `SocialAccountRefDto` literals: `reader/shell/list-heading.service.spec.ts`, `reader/shell/sidebar/sidebar.component.spec.ts`, `reader/shell/sidebar/sidebar-social-section.component.spec.ts`, `reader/state/subscriptions.store.spec.ts`, `reader/state/social-sections.spec.ts` (find them with `grep -rln "showsHandles\|handle: '@" src/app/reader`)
- Test: `social-sections.spec.ts`, `sidebar-social-section.component.spec.ts`, `sidebar-row-actions.service.spec.ts`

**Interfaces:**
- Consumes: `SocialFeedsPicker.open(account)` (Task 3), `ActionSheetAction.disabled` (Task 6).
- Produces:
  ```ts
  export type SocialAccountStatus = 'connected' | 'needs_reconnect';
  export interface SocialAccountRefDto { id: number; network: SocialNetworkName; handle: string; status: SocialAccountStatus; }
  // SocialSection
  readonly networkAccounts: readonly SocialAccountRefDto[]; // every connected account of the network, by id
  // SidebarRowActions
  openSocialSheet(section: SocialSection): void;
  ```
- i18n (`reader.*`):

| key | en | de |
|---|---|---|
| `manageSocialFeeds` | `Manage feeds…` | `Feeds verwalten…` |
| `manageSocialFeedsOf` | `Manage feeds of {{handle}}` | `Feeds von {{handle}} verwalten` |
| `reconnectSocialAccount` | `Reconnect {{handle}} in settings` | `{{handle}} in den Einstellungen neu verbinden` |
| `manageSocialSection` | `{{network}} options` | `{{network}}-Optionen` |

- [ ] **Step 1: Failing builder spec** in `social-sections.spec.ts`:

```ts
  it('names every account of the network for its menu, by id, even one without private feeds', () => {
    const sections = buildSocialSections(
      [feed(1, ref('bluesky', 3, 'home'))],
      [
        { id: 9, network: 'bluesky', handle: '@bob.bsky.social', status: 'needs_reconnect' },
        { id: 3, network: 'bluesky', handle: '@alice.bsky.social', status: 'connected' },
        { id: 5, network: 'mastodon', handle: '@carol@mastodon.example', status: 'connected' },
      ],
    );

    expect(sections[0].networkAccounts.map((account) => account.id)).toEqual([3, 9]);
  });
```

- [ ] **Step 2: Failing component specs** in `sidebar-social-section.component.spec.ts` (add `networkAccounts` to `ONE_ACCOUNT`: `[{ id: 3, network: 'bluesky', handle: '@alice.bsky.social', status: 'connected' }]`; provide `{ provide: SocialFeedsPicker, useValue: pickerStub }` with `const pickerStub = { open: jest.fn(() => of(undefined)) };` and add `openSocialSheet: jest.fn()` to the `SidebarRowActions` stub; the stub's `menuFor` signal lets a test open the menu with `menuFor.set('social-bluesky')`):

```ts
  it('opens Manage feeds for the network’s only account from the head’s menu', () => {
    const fixture = mount(ONE_ACCOUNT);
    rowActions.menuFor.set('social-bluesky');
    fixture.detectChanges();

    const item = fixture.nativeElement.querySelector('[data-testid="sidebar-social-bluesky"] .pop button');
    expect(item.textContent.trim()).toBe('Manage feeds…');
    item.click();

    expect(pickerStub.open).toHaveBeenCalledWith(ONE_ACCOUNT.networkAccounts[0]);
  });

  it('offers one item per account, naming each, and disables one that needs reconnecting', () => {
    const fixture = mount({
      ...ONE_ACCOUNT,
      networkAccounts: [
        { id: 3, network: 'bluesky', handle: '@alice.bsky.social', status: 'connected' },
        { id: 9, network: 'bluesky', handle: '@bob.bsky.social', status: 'needs_reconnect' },
      ],
    });
    rowActions.menuFor.set('social-bluesky');
    fixture.detectChanges();

    const items = [...fixture.nativeElement.querySelectorAll('[data-testid="sidebar-social-bluesky"] .pop button')];
    expect(items.map((item) => [item.textContent.trim(), item.disabled])).toEqual([
      ['Manage feeds of @alice.bsky.social', false],
      ['Reconnect @bob.bsky.social in settings', true],
    ]);
  });

  it('shows no menu for a network without accounts', () => {
    const fixture = mount({ ...ONE_ACCOUNT, networkAccounts: [] });
    expect(fixture.nativeElement.querySelector('[data-testid="sidebar-social-bluesky"] .dots')).toBeNull();
  });

  it('opens the sheet on a coarse pointer', () => {
    // mount with LayoutService isCoarse: signal(true) — extend mount() with an optional coarse flag
    fixture.nativeElement.querySelector('[data-testid="sidebar-social-bluesky"] .dots').click();
    expect(rowActions.openSocialSheet).toHaveBeenCalledWith(ONE_ACCOUNT);
  });
```

(`rowActions` is the stub object currently inlined in `mount()`; hoist it to a `const` so tests can reach it.)

- [ ] **Step 3: Failing sheet spec** in `sidebar-row-actions.service.spec.ts` (provide `{ provide: SocialFeedsPicker, useValue: { open: pickerOpen } }`):

```ts
  it('offers each account on the network sheet and opens the picked one', () => {
    const open = jest.fn(() => of('9'));
    const pickerOpen = jest.fn(() => of(undefined));
    // configure as the test above, plus the picker stub
    const bob = { id: 9, network: 'bluesky' as const, handle: '@bob.bsky.social', status: 'connected' as const };
    const reconnect = { id: 4, network: 'bluesky' as const, handle: '@old.bsky.social', status: 'needs_reconnect' as const };
    TestBed.inject(SidebarRowActions).openSocialSheet({ ...sectionOf('bluesky'), networkAccounts: [bob, reconnect] });

    expect(open).toHaveBeenCalledWith({
      title: 'Bluesky',
      actions: [
        { id: '9', label: 'Manage feeds of @bob.bsky.social', disabled: false },
        { id: '4', label: 'Reconnect @old.bsky.social in settings', disabled: true },
      ],
    });
    expect(pickerOpen).toHaveBeenCalledWith(bob);
  });
```

(`sectionOf(network)` builds a minimal `SocialSection` with `socialProfiles([])`; define it in the spec.)

- [ ] **Step 4: Run, expect FAIL**: `docker compose exec -T frontend npx jest src/app/reader/state/social-sections src/app/reader/shell/sidebar`

- [ ] **Step 5: Implement the DTO and builder**

`social-feed.ts`:

```ts
export type SocialAccountStatus = 'connected' | 'needs_reconnect';

/** A connected account, named once per subscriptions answer for the sidebar's account labels and menus. */
export interface SocialAccountRefDto {
  id: number;
  network: SocialNetworkName;
  handle: string;
  status: SocialAccountStatus;
}
```

If `settings/social/social-accounts.service.ts` declares its own status union, make it reuse `SocialAccountStatus` from `reader/social-feed` (settings may import reader).

`social-sections.ts` — `SocialSection` gains:

```ts
  /** Every connected account of the network, by id: the head's menu opens each one's picker. */
  readonly networkAccounts: readonly SocialAccountRefDto[];
```

and `buildSocialSections` sets `networkAccounts: accounts.filter((account) => account.network === network).sort((left, right) => left.id - right.id)` — rename the outer `accounts` parameter to `connectedAccounts` first, since the map body already shadows `accounts`.

- [ ] **Step 6: Implement the menu**

`sidebar-row-actions.service.ts` — inject `private readonly picker = inject(SocialFeedsPicker);` and:

```ts
  /** ⋯ on a network head (coarse): one item per account of the network. */
  openSocialSheet(section: SocialSection): void {
    this.sheet
      .open({
        title: this.transloco.translate(`settings.social.${section.network}`),
        actions: section.networkAccounts.map((account) => ({
          id: String(account.id),
          label: this.socialAccountLabel(section, account),
          disabled: account.status !== 'connected',
        })),
      })
      .pipe(takeUntilDestroyed(this.destroyRef))
      .subscribe((choice) => {
        const account = section.networkAccounts.find(({ id }) => String(id) === choice);
        if (account) this.picker.open(account).subscribe();
      });
  }

  socialAccountLabel(section: SocialSection, account: SocialAccountRefDto): string {
    if (account.status !== 'connected') {
      return this.transloco.translate('reader.reconnectSocialAccount', { handle: account.handle });
    }
    return section.networkAccounts.length === 1
      ? this.transloco.translate('reader.manageSocialFeeds')
      : this.transloco.translate('reader.manageSocialFeedsOf', { handle: account.handle });
  }
```

`sidebar-social-section.component.ts` — inject `protected readonly rows = inject(SidebarRowActions);`, `protected readonly screen = inject(LayoutService);`, `private readonly picker = inject(SocialFeedsPicker);`; import `DismissOnOutsideDirective`; add:

```ts
  protected readonly menuKey = computed(() => `social-${this.section().network}`);

  protected manageFeeds(account: SocialAccountRefDto): void {
    this.rows.closeMenu();
    this.picker.open(account).subscribe();
  }
```

`sidebar-social-section.component.html` — in `.section-head`, between the link and the chevron:

```html
  @if (current.networkAccounts.length > 0) {
    @if (screen.isCoarse()) {
      <button
        class="dots"
        type="button"
        [attr.aria-label]="'reader.manageSocialSection' | transloco: { network: ('settings.social.' + current.network | transloco) }"
        (click)="rows.openSocialSheet(current)"
      >
        <app-icon name="more_horiz" size="sm" />
      </button>
    } @else {
      <div class="rowmenu" [appDismissOnOutside]="rows.menuFor() === menuKey()" (dismiss)="rows.closeMenu()">
        <button
          class="dots"
          type="button"
          [attr.aria-label]="'reader.manageSocialSection' | transloco: { network: ('settings.social.' + current.network | transloco) }"
          (click)="rows.toggleMenu(menuKey(), $event)"
        >
          <app-icon name="more_horiz" size="sm" />
        </button>
        @if (rows.menuFor() === menuKey()) {
          <div class="pop" role="menu">
            @for (account of current.networkAccounts; track account.id) {
              <button
                role="menuitem"
                [disabled]="account.status !== 'connected'"
                (click)="manageFeeds(account)"
              >
                {{ rows.socialAccountLabel(current, account) }}
              </button>
            }
          </div>
        }
      </div>
    }
  }
```

`sidebar-social-section.component.scss` — add `@include row.row-menu;` and, if the disabled item does not read as disabled, `.pop button:disabled { color: var(--text-muted); cursor: default; }`. Check the head still fits: the dots sit before the chevron and must not push the count off (verify in Step 9).

- [ ] **Step 7: Fix fixtures** — add `networkAccounts: []` (or the matching accounts) to every `SocialSection` literal and `status: 'connected'` to every `SocialAccountRefDto` literal the typecheck flags: `docker compose exec -T frontend npx tsc -p tsconfig.spec.json --noEmit`.

- [ ] **Step 8: Run, expect PASS**: `docker compose exec -T frontend npx jest src/app/reader src/app/settings/social src/app/shared/action-sheet`

- [ ] **Step 9: Live check** on http://localhost:4200 in the built-in browser (dev stack running): the Bluesky head's "⋯" opens "Manage feeds…", which opens the picker titled "Manage feeds of @lars-pohlmann.de"; the "No longer followed" rows show ticked; unticking one and pressing "Save 1 changes" asks by name. **Press Cancel on the confirm** — this is the user's real dev data. Repeat the head menu at the mobile viewport (action sheet). Screenshot both.

- [ ] **Step 10: Commit**

```bash
git add frontend/src/app frontend/public/i18n
git commit -m "feat(#1677): open Manage feeds from the sidebar's network head"
```

---

### Task 8: Gates and PR

- [ ] Backend: `cd backend && composer check && composer md && composer test:parallel`, then `docker compose exec php composer test` (MySQL leg).
- [ ] Frontend: `docker compose exec -T frontend npm run check`.
- [ ] Dev log scan: `ls -t backend/var/log/dev-*.log | head -1 | xargs tail -n 50 | jq .` — no new errors or deprecations.
- [ ] `composer infection:diff` for the backend files touched in Tasks 1–2.
- [ ] Push and open the PR into `develop` with body `Closes #1677`, a summary of the decided behaviour and the two screenshots.

## Self-review notes

- Spec coverage: unsubscribe subscribed follows (T4), Home removable (T4), no-longer-followed ticked + badge kept (T4), truncated → subscribed (T1), copy (T4, T5), confirm by count (T4), sidebar menu, per-account items, reconnect disabled (T2, T6, T7), opener shared by settings and sidebar (T3).
- The section only exists while a network holds a social feed (`buildSocialSections` filter); an account with no feeds yet is reached from settings. Unchanged on purpose.
