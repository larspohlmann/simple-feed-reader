# Mark-Read Server Recount Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** After any mark-read, the sidebar badges come from the server's quiet recount. The frontend no longer works out which feeds a scope covers.

**Architecture:** Today `MarkReadActions` takes two routes after a mark-read:
- For the watermark scopes (All items, tag, feed, network) it zeroes the badges on the client through `SubscriptionsStore.zeroUnread`, which uses `includeInAllItems`, tag membership and `inNetworkTarget`, then reloads the counts.
- For the other scopes, and for mark-above-read, it runs a full `subscriptions.load()`.

After this change, every mark-read calls `subscriptions.reloadCountsQuietly()`, and nothing else touches the counts. That leaves `zeroUnread` and everything only it uses dead, including the totals edit counter that #1683 added for it, so a quiet reload adopts its totals unconditionally.

**Tech Stack:** Angular 20 (standalone, signals), Jest in the Docker `frontend` container, Prettier at 100 columns.

**Spec:** GitHub issue #1687 (`gh issue view 1687`).

## Global Constraints

- No frontend code derives scope membership (`includeInAllItems`, tag membership, network or profile feed) to edit a count.
- After every mark-read (All items, tag, feed, network, network profiles, search, For you, saved searches, one saved search, mark-above-read), the store reloads the counts quietly and makes no full `subscriptions.load()`.
- The wire shape and the backend are unchanged.
- Run the frontend tests inside Docker from the repo root: `docker compose exec -T frontend npx jest <path>`. The gate is `docker compose exec -T frontend npm run check`.
- Format with `docker compose exec -T frontend npx prettier --write <path>` before committing; Prettier is part of the gate.
- Commit format `refactor(#1687): lower-case summary`, with no Claude attribution or Co-Authored-By lines.
- Comments only where a reader would otherwise get the code wrong (CLAUDE.md).

---

### Task 1: Every mark-read reloads the counts quietly

**Files:**
- Modify: `frontend/src/app/reader/shell/mark-read-actions.service.ts` (`markAboveReadNow`, `reloadAfterMarkRead`, `watermarkOf`, the `ZeroTarget` import)
- Test: `frontend/src/app/reader/shell/mark-read-actions.service.spec.ts` (rewrite its only `describe`)
- Test: `frontend/src/app/reader/shell/reader-shell.component.spec.ts` (seven mark-read cases that expect the old requests)

**Interfaces:**
- Consumes: `SubscriptionsStore.reloadCountsQuietly(): void` (exists).
- Produces: after this task, nothing calls `SubscriptionsStore.zeroUnread`. Task 2 deletes it.

- [ ] **Step 1: Rewrite the mark-read spec**

In `mark-read-actions.service.spec.ts`, replace the whole `describe('MarkReadActions marking a network read', …)` block (keep the imports and the `subscription` helper above it) with:

```ts
describe('MarkReadActions recounting after a mark-read', () => {
  const profileFeed = {
    ...subscription(1, 4, { network: 'bluesky', accountId: null, kind: 'profile' }),
    tags: [{ id: 5, name: 'News', color: null, icon: null, position: 0 }],
  };
  const homeFeed = subscription(2, 6, { network: 'bluesky', accountId: 3, kind: 'home' });
  const otherNetworkProfile = subscription(3, 8, {
    network: 'mastodon',
    accountId: null,
    kind: 'profile',
  });

  let actions: MarkReadActions;
  let subscriptions: SubscriptionsStore;
  let reloadCountsQuietly: jest.SpyInstance;
  let fullLoad: jest.SpyInstance;
  const currentSelection = signal<Selection | null>(null);

  const unreadCounts = () => subscriptions.subscriptions().map((feed) => feed.unreadCount);

  const markSelectionRead = (selection: Selection) => {
    currentSelection.set(selection);
    actions.confirmMarkAllRead();
  };

  beforeEach(() => {
    localStorage.clear();
    TestBed.configureTestingModule({
      imports: [provideTranslocoTesting()],
      providers: [
        MarkReadActions,
        provideHttpClient(),
        provideHttpClientTesting(),
        { provide: API_BASE_URL, useValue: 'https://api.test' },
        {
          provide: ConfirmService,
          useValue: { confirmThen: (_: unknown, run: () => void) => run() },
        },
        {
          provide: EntriesStore,
          useValue: {
            loadedAt: () => '2026-10-16T10:00:00Z',
            load: jest.fn(),
            markHiddenLocally: jest.fn(),
            runThenReload: (
              mutation: { subscribe: (next: () => void) => void },
              reload: () => void,
            ) => mutation.subscribe(reload),
          },
        },
        { provide: SavedSearchesStore, useValue: { load: jest.fn() } },
        { provide: RecommendationsService, useValue: { refreshStatus: jest.fn() } },
        { provide: ReaderRouteState, useValue: { selection: currentSelection } },
      ],
    });
    TestBed.inject(TokenStore).set('user-a.jwt');
    const api = TestBed.inject(ReaderApi);
    for (const request of [
      'markRead',
      'markSearchRead',
      'markForYouRead',
      'markSavedSearchesRead',
      'markSingleSavedSearchRead',
      'markEntriesRead',
    ] as const) {
      jest.spyOn(api, request).mockReturnValue(of(undefined));
    }
    subscriptions = TestBed.inject(SubscriptionsStore);
    subscriptions.load();
    TestBed.inject(HttpTestingController)
      .expectOne('https://api.test/api/subscriptions')
      .flush({
        subscriptions: [profileFeed, homeFeed, otherNetworkProfile],
        favoritesCount: 0,
        keptCount: 0,
        viewedCount: 0,
        unreadTotals: { allItems: 0, tags: [{ id: 5, unreadCount: 4 }], networks: [] },
      });
    reloadCountsQuietly = jest.spyOn(subscriptions, 'reloadCountsQuietly').mockImplementation();
    fullLoad = jest.spyOn(subscriptions, 'load');
    actions = TestBed.inject(MarkReadActions);
  });

  it.each<Selection>([
    { kind: 'all', id: null, unread: true },
    { kind: 'tag', id: 5, unread: true },
    { kind: 'subscription', id: 1, unread: true },
    { kind: 'network', id: null, unread: true, network: 'bluesky' },
    { kind: 'network', id: null, unread: true, network: 'bluesky', feedKind: 'profile' },
    { kind: 'search', id: null, unread: true, term: 'news' },
    { kind: 'for-you', id: null, unread: true },
    { kind: 'saved-searches', id: null, unread: true },
    { kind: 'saved-search', id: 7, unread: true },
  ])('leaves the badges to the server recount after marking $kind read', (selection) => {
    markSelectionRead(selection);

    expect(reloadCountsQuietly).toHaveBeenCalledTimes(1);
    expect(fullLoad).not.toHaveBeenCalled();
    expect(unreadCounts()).toEqual([4, 6, 8]);
  });

  it('recounts from the server after marking the entries above read', () => {
    currentSelection.set({ kind: 'all', id: null, unread: false });

    actions.confirmMarkAboveRead([1, 2], jest.fn());

    expect(reloadCountsQuietly).toHaveBeenCalledTimes(1);
    expect(fullLoad).not.toHaveBeenCalled();
  });
});
```

The `ReaderApi` import already exists. Every fixture feed is in scope for at least one case. The profile feed carries tag 5, is feed 1 and is a Bluesky profile feed; Bluesky Home is in the Bluesky network; all three are in All items. So any client-side zeroing turns some count to 0 and fails `[4, 6, 8]`.

- [ ] **Step 2: Run it to verify it fails**

Run: `docker compose exec -T frontend npx jest src/app/reader/shell/mark-read-actions.service.spec.ts`
Expected: `Tests: 10 failed, 10 total`.
- The watermark scopes fail on `unreadCounts()`, because `zeroUnread` zeroes a feed.
- Search, For you and the saved-search scopes fail on `fullLoad` (`load()` is called) and on `reloadCountsQuietly` (0 calls).
- Mark-above-read fails on `fullLoad`.

- [ ] **Step 3: Change `mark-read-actions.service.ts`**

Delete the import line:

```ts
import { ZeroTarget } from '../state/unread-totals';
```

In `markAboveReadNow`, replace `this.subscriptions.load();` with `this.subscriptions.reloadCountsQuietly();`, so the `next` handler reads:

```ts
      next: () => {
        hideLocally();
        this.subscriptions.reloadCountsQuietly();
        this.savedSearches.load();
        this.recommendations.refreshStatus();
      },
```

Replace `reloadAfterMarkRead` with:

```ts
  private reloadAfterMarkRead(target: MarkReadTarget): void {
    this.entries.load(queryFromSelection(this.routeState.selection()));
    this.subscriptions.reloadCountsQuietly();
    this.savedSearches.load();
    // Marked picks move no watermark the reload sees, so re-read the for-you summary.
    if (target.scope === 'for-you') this.recommendations.refreshStatus();
  }
```

Delete `watermarkOf` and its docblock (`/** The scopes the backend marks by watermark, whose unread counts can be zeroed locally. */`) from the end of the file. The file then ends with the class's closing `}`.

- [ ] **Step 4: Run the mark-read spec to verify it passes**

Run: `docker compose exec -T frontend npx jest src/app/reader/shell/mark-read-actions.service.spec.ts`
Expected: `Tests: 10 passed, 10 total`.

- [ ] **Step 5: Update the reader-shell spec to the new requests**

Running `docker compose exec -T frontend npx jest src/app/reader/shell/reader-shell.component.spec.ts` now fails seven mark-read cases: six that expect a full `GET /api/subscriptions` after a mark-read, and one that spies on `zeroUnread`. Edit `reader-shell.component.spec.ts` as follows.

(a) Directly above `  const subscriptionsBody = {` (near line 86, below `NO_UNREAD_TOTALS_DTO`), add:

```ts
  const markedReadCountsBody = {
    subscriptions: [{ id: 5, unreadCount: 0, entryCount: 0 }],
    favoritesCount: 0,
    keptCount: 0,
    viewedCount: 0,
    unreadTotals: NO_UNREAD_TOTALS_DTO,
  };

```

(b) Six times, in the search, For you (three cases), single saved search and saved searches mark-read cases, this pair of lines appears:

```ts
      ctrl.expectOne('https://api.test/api/subscriptions').flush(subscriptionsBody);
      ctrl.expectOne('https://api.test/api/saved-searches')
```

In each of the six, replace the first line with:

```ts
      ctrl.expectOne('https://api.test/api/subscriptions/counts').flush(markedReadCountsBody);
```

Leave every other `expectOne('https://api.test/api/subscriptions')` in the file alone; they belong to boots and loads, not to mark-reads.

(c) In the three test titles that end `then reloads entries, subscriptions and saved searches`, change `subscriptions` to `counts`.

(d) In the case `'marks the network read by name, reloads the list and zeroes its feeds locally'`:
- Rename it to `'marks the network read by name, then reloads the list and the counts'`.
- Delete the line `const zeroUnread = jest.spyOn(TestBed.inject(SubscriptionsStore), 'zeroUnread');`.
- Replace its last line, `expect(zeroUnread).toHaveBeenCalledWith({ network: 'bluesky' });`, with:

```ts
      ctrl.expectOne('https://api.test/api/subscriptions/counts').flush(markedReadCountsBody);
```

If `SubscriptionsStore` is now unused in that file, remove its import. Check with `grep -n SubscriptionsStore frontend/src/app/reader/shell/reader-shell.component.spec.ts`.

- [ ] **Step 6: Run both shell specs**

Run: `docker compose exec -T frontend npx jest src/app/reader/shell`
Expected: all pass.

- [ ] **Step 7: Format and commit**

```bash
docker compose exec -T frontend npx prettier --write src/app/reader/shell
git add frontend/src/app/reader/shell/mark-read-actions.service.ts frontend/src/app/reader/shell/mark-read-actions.service.spec.ts frontend/src/app/reader/shell/reader-shell.component.spec.ts
git commit -m "refactor(#1687): every mark-read leaves the badges to the server recount"
```

---

### Task 2: Delete the client zeroing and the totals edit counter

**Files:**
- Modify: `frontend/src/app/reader/state/subscriptions.store.ts` (`totalsEdits`, `quietFetch`, `zeroUnread`, `patchUnreadTotals`, `inNetworkTarget`, imports)
- Modify: `frontend/src/app/reader/state/unread-totals.ts` (`ZeroTarget`, `zeroUnreadTotals`, `zeroNetwork`, `withZero`, imports)
- Test: `frontend/src/app/reader/state/subscriptions.store.spec.ts`
- Test: `frontend/src/app/reader/state/unread-totals.spec.ts`

**Interfaces:**
- Consumes: Task 1 left no caller of `zeroUnread`. Confirm before deleting: `grep -rn "zeroUnread\|ZeroTarget" frontend/src --include='*.ts'` should list only `subscriptions.store.ts`, `unread-totals.ts` and their two specs.
- Produces: `SubscriptionsStore` without `zeroUnread`; `unread-totals.ts` exporting only `UnreadTotals`, `NO_UNREAD_TOTALS`, `unreadTotalsFrom` and `sameUnreadTotals`.

- [ ] **Step 1: Delete the specs of the code that goes**

In `subscriptions.store.spec.ts`:
- Delete these whole `it(...)` cases:
  - `'zeroes the targeted tag total'` (in `describe('reading an article with copies (#1679)')`)
  - `'zeroes exactly the feeds of a network marked read'`
  - `"zeroes only a network's profile feeds when the Feeds row is marked read"`
  - `'marks All items read only in the feeds All items holds, as the backend does'`
  - `"zeroes a tag's private feeds along with the rest when the tag is marked read"` (these four are in `describe('with social feeds')`)
  - `'drops the scope totals when a mark-scope-read zeroed them while it was on the wire'` (in `describe('SubscriptionsStore counts-only reload')`)
- In `'optimistically decrements and zeroes unread'`, rename it to `'optimistically decrements unread'` and delete its last statement:

```ts
    store.zeroUnread({ subscription: 2 });
    expect(store.subscriptions().find((subscription) => subscription.id === 2)!.unreadCount).toBe(
      0,
    );
```

- In `'takes over a tick on the wire when a deliberate reload follows'`, delete the line `store.zeroUnread('all');`. The test still proves the takeover through `staleTick.cancelled` and the adopted `allItems: 2`.
- Keep `'adopts the scope totals even when a feed count was edited while it was on the wire'` unchanged; it pins the behaviour Step 4 keeps.

In `unread-totals.spec.ts`, delete the whole `describe('zeroUnreadTotals', …)` block and `zeroUnreadTotals` from the import list.

Afterwards, `grep -n "zeroUnread" frontend/src/app/reader/state/*.spec.ts` prints nothing.

- [ ] **Step 2: Delete `zeroUnread` and its helpers from the store**

In `subscriptions.store.ts`, delete the public method `zeroUnread(target: ZeroTarget): void { … }` (whole body), the private method:

```ts
  private patchUnreadTotals(patch: (totals: UnreadTotals) => UnreadTotals): void {
    ++this.totalsEdits;
    this.storedUnreadTotals.update(patch);
  }
```

and the module function after the class:

```ts
function inNetworkTarget(
  subscription: SubscriptionDto,
  target: { network: SocialNetworkName; feedKind?: NetworkFeedKind },
): boolean {
  if (subscription.social?.network !== target.network) return false;
  return target.feedKind === undefined || isProfileFeed(subscription);
}
```

The class's closing `}` must remain the last line of the file.

- [ ] **Step 3: Delete the totals edit counter**

Delete the field and its docblock:

```ts
  /** Kept apart from `localEdits` so a favourite or a read on the wire never drops a recount's totals. */
  private totalsEdits = 0;
```

In `quietFetch`, delete `const totalsEditsWhenSent = this.totalsEdits;` and replace

```ts
          if (this.totalsEdits === totalsEditsWhenSent) {
            this.storedUnreadTotals.set(unreadTotalsFrom(response.unreadTotals));
          }
```

with

```ts
          // Only the server edits the totals, so even an overtaken answer carries the latest ones.
          this.storedUnreadTotals.set(unreadTotalsFrom(response.unreadTotals));
```

That one comment line stays: without it, a reader would expect the totals to sit behind the `localEdits` gate like everything else.

- [ ] **Step 4: Fix the store's imports**

Replace

```ts
import {
  buildSocialSections,
  isEmptySavedFeed,
  isProfileFeed,
  isSocialFeed,
} from './social-sections';
import { NetworkFeedKind, SocialNetworkName } from '../../core/social-network';
```

with

```ts
import { buildSocialSections, isEmptySavedFeed, isSocialFeed } from './social-sections';
```

and remove `ZeroTarget,` and `zeroUnreadTotals,` from the `from './unread-totals'` import. Keep `UnreadTotals`, which still types `storedUnreadTotals`. If `tsc` reports another symbol as unused after this, remove only that one.

- [ ] **Step 5: Delete the zeroing from `unread-totals.ts`**

Delete `export type ZeroTarget = …;`, `export function zeroUnreadTotals(…)`, `function zeroNetwork(…)` and `function withZero(…)`. Change the first import to:

```ts
import { SocialNetworkName } from '../../core/social-network';
```

- [ ] **Step 6: Run the state specs and the whole gate**

Run: `docker compose exec -T frontend npx jest src/app/reader`
Expected: all pass.

Run: `docker compose exec -T frontend npx prettier --write src/app/reader && docker compose exec -T frontend npm run check`
Expected: exit 0. This covers ESLint, Prettier, Stylelint, tsc for app, spec and e2e, and Jest.

Run: `grep -rn "zeroUnread\|ZeroTarget\|inNetworkTarget\|totalsEdits\|patchUnreadTotals" frontend/src`
Expected: no output.

- [ ] **Step 7: Commit**

```bash
git add frontend/src/app/reader/state
git commit -m "refactor(#1687): the client no longer zeroes badges by its own scope rules"
```

---

### After the tasks (controller)

- Live check on the dev stack (http://localhost:4200, logged in):
  1. Mark one small feed read through its list header.
  2. In the browser's network log, confirm that `POST /api/entries/mark-read` is followed by `GET /api/subscriptions/counts` and not by `GET /api/subscriptions`.
  3. Confirm the feed's badge shows 0 once that answer lands.
  4. Mention to the user that the feed's entries are now read in their dev data.
- Then run `/simplify`, open the PR into `develop` with `Closes #1687`, merge when green, and deploy `v1.4.2-dev.14`, which ships #1683 and #1687 together.
