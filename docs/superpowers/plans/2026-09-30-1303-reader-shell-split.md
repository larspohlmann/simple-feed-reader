# Split the reader-shell god component — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: superpowers:executing-plans (inline; one commit per task). Steps use checkbox (`- [ ]`) syntax.

**Goal:** Take `reader-shell.component.ts` from 1355 lines to about 650. Five cohesive jobs move into shell-scoped services. (#1303)

**Architecture:**
- New services live in `frontend/src/app/reader/shell/`. Every one is `@Injectable()` with no `providedIn`, and all are listed in the shell's `providers`, so they live and die with the reader.
- **Shared state has one owner: `ReaderRouteState`.** It owns the route-derived `selection`, `entryId`, the open entry, and the cold-fetched deep-link entry, which is private and patched only through `patchFetchedEntry`. Every other service reads the selection and the open entry from it.
- **`EntryStateActions` owns `leavingIds` and the viewed-on-open set.** The shell only clears `leavingIds` on a selection change, through `clearLeaving()`.
- The shell keeps `readonly selection/entryId/openEntry` as aliases of `ReaderRouteState`. Its own effects and template read them constantly, so the aliases save a rename across both files.
- Line numbers below are for `origin/develop` at `54f6436fc`.

**Tech Stack:** Angular 20 signals and standalone components, Jest in the frontend container.

## Global Constraints

- Branch `refactor/1303-reader-shell-split` off `develop`. Commit format `type(#1303): summary`. The first commit copies this plan to `docs/superpowers/plans/2026-09-30-1303-reader-shell-split.md`.
- Comments in new code: none by default, 3 lines at most, no issue numbers, no narration of history. The comments in the code below are the whole set.
- In moved code, rename single-letter and abbreviated names (`e`, `s`, `x`, `cur`, `subs`, `recs`) as shown. Drop a boolean flag parameter where the code below drops it.
- Per-task test cycle:
  1. `docker compose exec -T frontend npx prettier --write <touched files>`
  2. `docker compose exec -T frontend npx jest src/app/reader/reader-shell` → PASS
  3. `docker compose exec -T frontend npm run check` → green
- Before the PR:
  1. `docker compose exec -T frontend npm run build` → no errors.
  2. Click through http://localhost:4200:
     - Mark all read on a feed, a tag, a search and For you; mark above read on an unread list.
     - Favourite, keep, tick and open an entry in the list and in the open article. In Favorites, un-favourite a row: it must fade out.
     - Open an entry by deep link (paste a `?entry=` URL of an entry that is not on the first page).
     - Onboarding: with a fresh test account from the project's seed data, confirm that the redirect to `/discover`, the counted fetch banner and the passkey offer still behave. Do this only if such an account exists; otherwise rely on the spec.
- **Tests stay in `reader-shell.component.spec.ts`.**
  - Every case covering moved behaviour boots the whole shell: 6 request drains, route doubles, and the real entry list for `hideAboveMarked`. A per-service spec would have to copy that harness.
  - These cases keep proving the real DI wiring through the shell's `providers`. Only their call sites change, via the `sed` lines given per task. Assertions stay byte-identical.

## Out of scope

- **Saved-search save/remove and the digest confirm (~90 lines):** a clean next extraction, but independent of the five here.
- **For-you run start/resume (~40 lines):** small, and only one template button uses it.
- **Header geometry** (ResizeObserver, bar CSS vars, hide-on-scroll, sidebar focus): tied to view children and the host element, so it needs a directive, not a service.
- **Refresh scoping and the post-refresh reload effect:** they coordinate selection, the sweep and four stores, and the shell is their natural owner.
- **The sidebar's duplicated feed row:** an `ng-template` would put the `cdkDragHandle` outside its `cdkDrag` injector, and a component would cut across the shared menu state and styles. Drag is fragile here, so leave it (planner ruling).
- **Cutting the shell's `inject()` count:** it stays around 30. What leaves the shell is logic, not collaborators.

---

### Task 0: Plan

- [ ] `git switch -c refactor/1303-reader-shell-split develop`. Copy this file to `docs/superpowers/plans/`. Commit `docs(#1303): plan the reader-shell split`.

### Task 1: `ReaderRouteState` owns the selection and the open entry

**Files:** Create `frontend/src/app/reader/shell/reader-route-state.service.ts`. Modify `reader-shell.component.ts`.

- [ ] **Step 1: Create the service**

```ts
import { Injectable, computed, effect, inject, signal, untracked } from '@angular/core';
import { ActivatedRoute, convertToParamMap } from '@angular/router';
import { toSignal } from '@angular/core/rxjs-interop';
import { ReaderApi } from '../reader-api';
import { EntryBodyService } from '../entry-body.service';
import { EntriesStore } from '../entries.store';
import { ListPreferences } from '../list-preferences.service';
import { selectionFromRoute } from '../reader-matcher';
import { sameSelection } from '../query';
import { EntryDto, EntryStatePatch } from '../models';

@Injectable()
export class ReaderRouteState {
  private readonly route = inject(ActivatedRoute);
  private readonly api = inject(ReaderApi);
  private readonly bodyService = inject(EntryBodyService);
  private readonly entries = inject(EntriesStore);
  private readonly listPreferences = inject(ListPreferences);

  private readonly queryParameters = toSignal(this.route.queryParamMap, {
    initialValue: convertToParamMap({}),
  });
  private readonly pathParameters = toSignal(this.route.paramMap, {
    initialValue: convertToParamMap({}),
  });
  private readonly parsed = computed(() =>
    selectionFromRoute(this.pathParameters(), this.queryParameters()),
  );

  /** Structural equality: an entry-only URL change keeps the reference, so the list does not reload. */
  readonly selection = computed(() => this.listPreferences.appliedTo(this.parsed().selection), {
    equal: sameSelection,
  });
  readonly entryId = computed(() => this.parsed().entryId);

  private readonly fetchedEntry = signal<EntryDto | null>(null);
  readonly openEntry = computed(() => {
    const id = this.entryId();
    if (id == null) return null;
    const listed = this.entries.entries().find((entry) => entry.id === id);
    if (listed) return listed;
    const fetched = this.fetchedEntry();
    return fetched && fetched.id === id ? fetched : null;
  });
  /** Identity only: effects keyed on it fire once per opened entry, never on its flag changes. */
  readonly openEntryId = computed(() => this.openEntry()?.id ?? null);

  constructor() {
    effect(() => {
      const id = this.entryId();
      untracked(() => this.fetchUnlistedEntry(id));
    });
  }

  patchFetchedEntry(id: number, patch: EntryStatePatch, onError?: () => void): void {
    const before = this.fetchedEntry();
    this.fetchedEntry.update((current) =>
      current && current.id === id ? { ...current, ...patch } : current,
    );
    this.api.updateState(id, patch).subscribe({
      error: () => {
        // Revert only while the same cold entry is open; Back/Forward may have moved on.
        this.fetchedEntry.update((current) => (current && current.id === id ? before : current));
        onError?.();
      },
    });
  }

  private fetchUnlistedEntry(id: number | null): void {
    if (id == null) {
      this.fetchedEntry.set(null);
      return;
    }
    if (this.entries.entries().some((entry) => entry.id === id)) return;
    if (this.fetchedEntry()?.id === id) return;
    this.api.entry(id).subscribe({
      // Id-guarded: a slow answer for an abandoned deep link must not replace the entry now open.
      next: (response) => {
        if (this.entryId() !== id) return;
        this.fetchedEntry.set(response.entry);
        this.bodyService.seed(response.entry.id, response.entry.contentHtml);
      },
      error: () => {
        if (this.entryId() === id) this.fetchedEntry.set(null);
      },
    });
  }
}
```

- [ ] **Step 2: Shell**
  - `providers`: add `ReaderRouteState` after `SidebarCountsPoll`. Replace the two-line provider comment with `// Per-reader state: provided here so none of it outlives the reader.`
  - Delete these fields:
    - `params`, `pathParams`, `parsed`, and `selection` with its comment (286–299)
    - `entryId` (307)
    - `fetchedEntry`, `openEntry`, `openEntryId` and their comments (325–338)
  - Put these fields in their place, after `listLoading`:
    ```ts
    private readonly routeState = inject(ReaderRouteState);
    readonly selection = this.routeState.selection;
    readonly entryId = this.routeState.entryId;
    readonly openEntry = this.routeState.openEntry;
    ```
  - Delete the constructor's deep-link effect (651–678).
  - In the viewed-on-open effect (642–650) and the prefetch effect (682–694), replace `this.openEntryId()` with `this.routeState.openEntryId()`.
  - In `patchOpen`, replace everything after the `setState` early return (971–980) with `this.routeState.patchFetchedEntry(e.id, patch, onError);`.
  - Drop the imports that are now unused: `toSignal`, `convertToParamMap`, `selectionFromRoute`, `sameSelection`.
- [ ] **Step 3:** Run the test cycle; the spec is unchanged. Commit `refactor(#1303): route state owns the selection and the open entry`.

### Task 2: `ListHeading` — every pure heading computed, one subscription lookup

**Files:**
- Create `frontend/src/app/reader/shell/list-heading.service.ts`.
- Modify `reader-shell.component.ts` + `.html` + `.spec.ts`.
- Modify `entry-list/entry-list.component.ts`, doc comment at line 159.

- [ ] **Step 1: Create the service**

`selectedSubscription` replaces the six `subscriptions().find((x) => x.id === s.id)` copies (362, 369, 466, 514, 533, 1240). `selectedTagNode` replaces the two tag-tree lookups.

```ts
import { Injectable, computed, inject } from '@angular/core';
import { TranslocoService } from '@jsverse/transloco';
import { LanguageService } from '../../core/language.service';
import { SubscriptionsStore } from '../subscriptions.store';
import { EntriesStore } from '../entries.store';
import { RecommendationsService } from '../recommendations.service';
import { SavedSearchesStore } from '../saved-searches.store';
import { ReadingLayoutService } from '../reading-layout.service';
import { Selection, visibleSearchTerm } from '../query';
import { TitleCount } from '../entry-list/entry-list.component';
import { ReaderRouteState } from './reader-route-state.service';

@Injectable()
export class ListHeading {
  private readonly routeState = inject(ReaderRouteState);
  private readonly subscriptions = inject(SubscriptionsStore);
  private readonly entries = inject(EntriesStore);
  private readonly recommendations = inject(RecommendationsService);
  private readonly savedSearches = inject(SavedSearchesStore);
  private readonly layout = inject(ReadingLayoutService);
  private readonly language = inject(LanguageService);
  private readonly i18n = inject(TranslocoService);

  readonly activeSavedSearchId = computed(() => {
    const selection = this.routeState.selection();
    return selection.kind === 'saved-search' ? selection.id : null;
  });

  readonly activeSavedSearch = computed(() => {
    const id = this.activeSavedSearchId();
    if (id === null) return null;
    return this.savedSearches.savedSearches().find((saved) => saved.id === id) ?? null;
  });

  private readonly selectedTagNode = computed(() => {
    const selection = this.routeState.selection();
    if (selection.kind !== 'tag') return null;
    return this.subscriptions.tagTree().find((node) => node.tag.id === selection.id) ?? null;
  });

  readonly selectedTag = computed(() => this.selectedTagNode()?.tag ?? null);

  readonly selectedSubscription = computed(() => {
    const selection = this.routeState.selection();
    if (selection.kind !== 'subscription') return null;
    return (
      this.subscriptions
        .subscriptions()
        .find((subscription) => subscription.id === selection.id) ?? null
    );
  });

  /** Magazine only: in the dense list layout the intro would be a slab above the rows. */
  readonly feedIntroSubscription = computed(() => {
    if (this.layout.mode() !== 'magazine') return null;
    const subscription = this.selectedSubscription();
    if (subscription === null) return null;
    const hasIntro =
      subscription.description !== null ||
      subscription.imageUrl !== null ||
      subscription.siteUrl !== null;
    return hasIntro ? subscription : null;
  });

  readonly hasMore = computed(() => this.entries.nextCursor() !== null);

  /** A search request in flight, not merely any list loading. */
  readonly searching = computed(
    () => this.routeState.selection().kind === 'search' && this.entries.loading(),
  );

  readonly lastRefreshed = computed(() => {
    if (this.routeState.selection().kind === 'for-you') return this.recommendations.generatedAt();
    return this.selectedSubscription()?.lastFetchedAt ?? null;
  });

  readonly nextRefresh = computed(() => this.selectedSubscription()?.nextFetchAt ?? null);

  readonly newestRunId = computed(() =>
    this.routeState.selection().kind === 'for-you' ? this.recommendations.newestRunId() : null,
  );

  readonly title = computed(() => {
    // translate() is one-shot: reading the language re-runs this on a switch.
    this.language.lang();
    const selection = this.routeState.selection();
    // No default: a new selection kind must fail to compile here.
    switch (selection.kind) {
      case 'favorites':
        return this.i18n.translate('reader.favorites');
      case 'kept':
        return this.i18n.translate('reader.kept');
      case 'viewed':
        return this.i18n.translate('reader.viewed');
      case 'for-you':
        return this.i18n.translate('reader.forYou');
      case 'saved-searches':
        return this.i18n.translate('reader.savedSearches');
      case 'saved-search':
        return this.activeSavedSearch()?.term ?? this.i18n.translate('reader.savedSearches');
      case 'all':
        return this.i18n.translate('reader.allItems');
      case 'tag':
        return this.selectedTag()?.name ?? this.i18n.translate('reader.tagFallback');
      case 'search':
        return `${this.searchTitlePrefix()} ${this.searchTitleBody()}`;
      case 'subscription':
        return this.selectedSubscription()?.title ?? this.i18n.translate('reader.feedFallback');
    }
  });

  readonly titleCount = computed<TitleCount>(() => {
    const selection = this.routeState.selection();
    switch (selection.kind) {
      case 'all':
        return bySwitch(
          selection,
          this.subscriptions.totalUnread(),
          this.subscriptions.totalEntries(),
        );
      case 'tag': {
        const node = this.selectedTagNode();
        return bySwitch(selection, node?.unreadCount ?? 0, node?.entryCount ?? 0);
      }
      case 'subscription': {
        const subscription = this.selectedSubscription();
        return bySwitch(selection, subscription?.unreadCount ?? 0, subscription?.entryCount ?? 0);
      }
      case 'favorites':
        return items(this.subscriptions.favoritesCount());
      case 'kept':
        return items(this.subscriptions.keptCount());
      case 'viewed':
        return items(this.subscriptions.viewedCount());
      case 'for-you':
        return bySwitch(
          selection,
          this.recommendations.forYouCount(),
          this.recommendations.forYouTotal(),
        );
      case 'saved-searches':
        return bySwitch(selection, this.savedSearchesUnread(), this.savedSearchesTotal());
      case 'saved-search': {
        const saved = this.activeSavedSearch();
        return bySwitch(selection, saved?.unreadCount ?? 0, saved?.memberCount ?? 0);
      }
      case 'search':
        return items(0);
    }
  });

  readonly searchTitlePrefix = computed(() => {
    this.language.lang();
    return this.i18n.translate('reader.searchResultsPrefix');
  });

  readonly searchTitleBody = computed(() => {
    this.language.lang();
    const term = this.searchTerm();
    // In flight, the previous term's rows are still on screen, so no count yet.
    if (this.searching()) return this.i18n.translate('reader.searchResults', { term });
    const count = this.entries.entries().length;
    const key = this.hasMore() ? 'reader.searchResultsCountMore' : 'reader.searchResultsCount';
    return this.i18n.translate(key, { term, count });
  });

  readonly searchTitleTerm = computed(() => {
    this.language.lang();
    return this.i18n.translate('reader.searchResults', { term: this.searchTerm() });
  });

  /** The loaded count, not a total: a trailing '+' while another page is out there. */
  readonly searchCountLabel = computed<string | null>(() => {
    if (this.searching()) return null;
    const count = this.entries.entries().length;
    return this.hasMore() ? `${count}+` : `${count}`;
  });

  private readonly searchTerm = computed(() =>
    visibleSearchTerm(this.routeState.selection().term ?? ''),
  );

  /** A post matching two searches counts twice, so the heading matches the sidebar row. */
  private readonly savedSearchesUnread = computed(() =>
    this.savedSearches.savedSearches().reduce((sum, saved) => sum + saved.unreadCount, 0),
  );

  private readonly savedSearchesTotal = computed(() =>
    this.savedSearches.savedSearches().reduce((sum, saved) => sum + saved.memberCount, 0),
  );
}

function unread(value: number): TitleCount {
  return { value, counts: 'unread' };
}

function items(value: number): TitleCount {
  return { value, counts: 'items' };
}

/** The unread count under "Only unread", every post under "All posts". */
function bySwitch(selection: Selection, unreadCount: number, allCount: number): TitleCount {
  return selection.unread ? unread(unreadCount) : items(allCount);
}
```

- [ ] **Step 2: Shell `.ts`**
  - `providers`: add `ListHeading`.
  - Add the field `readonly heading = inject(ListHeading);`.
  - Delete these members:
    - `activeSavedSearchId`, `activeSavedSearch` (309–323)
    - `hasMore`, `searching` (350–354)
    - `listLastRefreshed`, `listNextRefresh`, `listNewestRunId` (356–376)
    - `selectedTag`, `selectedSubscription`, `feedIntroSubscription` (451–482)
    - `title` … `searchCountLabel` (484–599)
    - `savedSearchesUnread`, `savedSearchesTotal` (1144–1155)
    - the module functions `unread`, `items`, `bySwitch` (1323–1339)
  - Keep `canMarkAllRead` until Task 4.
  - Tab-title effect: use `this.heading.title()` and `this.heading.titleCount().value`.
  - `currentSavedSearch`: use `this.heading.activeSavedSearch()`.
  - `refreshScope`, subscription branch: use `const feedId = this.heading.selectedSubscription()?.feedId;`.
  - Remove the `language` field and the now-unused imports: `LanguageService`, `TitleCount`.
- [ ] **Step 3: Shell `.html`.** Prefix with `heading.` every call of `searching()`, `activeSavedSearchId()`, `title()`, `selectedTag()`, `selectedSubscription()`, `searchTitlePrefix()`, `searchTitleTerm()`, `searchCountLabel()`, `titleCount()`, `hasMore()` and `feedIntroSubscription()`. That covers header, sidebar, entry list, `#listEditAction` and `#feedIntro`. Rename these three:
  - `listLastRefreshed()` → `heading.lastRefreshed()`
  - `listNextRefresh()` → `heading.nextRefresh()`
  - `listNewestRunId()` → `heading.newestRunId()`
- [ ] **Step 4: Spec call sites and doc reference**

```bash
sed -E -i '' 's/componentInstance\.(titleCount|title|searching|searchTitleBody|searchCountLabel|searchTitleTerm|searchTitlePrefix|hasMore|activeSavedSearchId)\(/componentInstance.heading.\1(/g' frontend/src/app/reader/reader-shell.component.spec.ts
sed -i '' 's/`ReaderShellComponent.searchCountLabel`/`ListHeading.searchCountLabel`/' frontend/src/app/reader/entry-list/entry-list.component.ts
```

  Expect 47 spec rewrites (`git diff --stat`). These cases now exercise `ListHeading` through the shell: every "titles …", "counts …", "hands the list heading …", "searching (#408 follow-up)", "translated heading (#411)", "titling the combined saved-search list (#769)" and "a single saved search addressed by its path slug (#1118)" case.
- [ ] **Step 5:** Run the test cycle. Confirm with `git grep -n "subscriptions().find((x) => x.id === s.id)" frontend/src` that it prints nothing. Commit `refactor(#1303): list heading leaves the shell`.

### Task 3: `EntryStateActions` is the entry action handler

**Files:** Create `frontend/src/app/reader/shell/entry-state-actions.service.ts`. Modify `reader-shell.component.ts` + `.html` + `.spec.ts`.

- [ ] **Step 1: Create the service**

`toggleRead` absorbs `setViewed`, which drops its flag parameter. `markOpenedViewed` is `applyOpenedPatch` specialised to its only patch, `{ isViewed: true }`. `markLeaving(id, bool)` becomes an add and a remove.

```ts
import { Injectable, effect, inject, signal, untracked } from '@angular/core';
import { ActivatedRoute, Router } from '@angular/router';
import { EntryActionHandler } from '../entry-actions/entry-action-handler';
import { EntriesStore, localStatePatch } from '../entries.store';
import { SubscriptionsStore } from '../subscriptions.store';
import { SavedSearchesStore } from '../saved-searches.store';
import { Selection } from '../query';
import { entryParam } from '../slug';
import { EntryDto, EntryStatePatch } from '../models';
import { ReaderRouteState } from './reader-route-state.service';

@Injectable()
export class EntryStateActions implements EntryActionHandler {
  private readonly router = inject(Router);
  private readonly route = inject(ActivatedRoute);
  private readonly entries = inject(EntriesStore);
  private readonly subscriptions = inject(SubscriptionsStore);
  private readonly savedSearches = inject(SavedSearchesStore);
  private readonly routeState = inject(ReaderRouteState);

  private readonly viewedOnOpen = new Set<number>();
  private readonly leaving = signal<ReadonlySet<number>>(new Set());
  /** Rows fading out of a saved view; they stay in the list data so the magazine plan holds. */
  readonly leavingIds = this.leaving.asReadonly();

  constructor() {
    // Once per session, even when the PATCH fails and rolls back.
    effect(() => {
      if (this.routeState.openEntryId() === null) return;
      untracked(() => {
        const entry = this.routeState.openEntry();
        if (!entry || entry.isViewed || this.viewedOnOpen.has(entry.id)) return;
        this.viewedOnOpen.add(entry.id);
        this.markOpenedViewed(entry);
      });
    });
  }

  favorite(entry: EntryDto): void {
    const delta = entry.isFavorite ? -1 : 1;
    this.subscriptions.bumpFavorites(delta);
    this.patchInList(entry, { isFavorite: !entry.isFavorite }, () =>
      this.subscriptions.bumpFavorites(-delta),
    );
  }

  keep(entry: EntryDto): void {
    const delta = entry.isKept ? -1 : 1;
    this.subscriptions.bumpKept(delta);
    this.patchInList(entry, { isKept: !entry.isKept }, () => this.subscriptions.bumpKept(-delta));
  }

  /** Ticking also reads the entry; un-ticking lets a later reopen mark it viewed again. */
  toggleRead(entry: EntryDto): void {
    const viewed = !entry.isViewed;
    const alsoReads = viewed && !entry.isHidden;
    const viewedDelta = viewed ? 1 : -1;
    this.subscriptions.bumpViewed(viewedDelta);
    if (alsoReads) {
      this.subscriptions.decrementUnread(entry.subscriptionId);
      this.savedSearches.markEntryRead(entry.id);
    }
    if (!viewed) this.viewedOnOpen.delete(entry.id);
    this.patchInList(entry, { isViewed: viewed }, () => {
      this.subscriptions.bumpViewed(-viewedDelta);
      if (alsoReads) {
        this.subscriptions.incrementUnread(entry.subscriptionId);
        this.savedSearches.markEntryUnread(entry.id);
      }
    });
  }

  open(entry: EntryDto): void {
    void this.router.navigate([], {
      relativeTo: this.route,
      queryParams: { entry: entryParam(entry.id, entry.title) },
      queryParamsHandling: 'merge',
    });
  }

  readonly openOriginal = (entry: EntryDto): void => {
    if (entry.isViewed) return;
    this.subscriptions.bumpViewed(1);
    this.patchOpen(entry, { isViewed: true }, () => this.subscriptions.bumpViewed(-1));
  };

  clearLeaving(): void {
    this.leaving.set(new Set());
  }

  private markOpenedViewed(entry: EntryDto): void {
    const alsoReads = !entry.isHidden;
    if (alsoReads) {
      this.subscriptions.decrementUnread(entry.subscriptionId);
      this.savedSearches.markEntryRead(entry.id);
    }
    this.subscriptions.bumpViewed(1);
    this.patchOpen(entry, { isViewed: true }, () => {
      if (alsoReads) {
        this.subscriptions.incrementUnread(entry.subscriptionId);
        this.savedSearches.markEntryUnread(entry.id);
      }
      this.subscriptions.bumpViewed(-1);
    });
  }

  /** The leaving row stays in the list data, so patchOpen still finds it. */
  private patchInList(entry: EntryDto, patch: EntryStatePatch, onError: () => void): void {
    const revertLeave = this.leaveExcludedRow(entry, patch);
    this.patchOpen(entry, patch, () => {
      onError();
      revertLeave();
    });
  }

  private leaveExcludedRow(entry: EntryDto, patch: EntryStatePatch): () => void {
    const flag = savedViewMembership(this.routeState.selection().kind);
    if (flag === null) return () => undefined;
    const stillMember = (localStatePatch(patch)[flag] ?? entry[flag]) === true;
    if (stillMember) return () => undefined;
    this.leaving.update((ids) => new Set(ids).add(entry.id));
    return () => this.leaving.update((ids) => withoutId(ids, entry.id));
  }

  private patchOpen(entry: EntryDto, patch: EntryStatePatch, onError?: () => void): void {
    if (this.entries.entries().some((listed) => listed.id === entry.id)) {
      this.entries.setState(entry.id, patch, onError);
      return;
    }
    this.routeState.patchFetchedEntry(entry.id, patch, onError);
  }
}

/** The flag a saved view filters on; an entry whose patch clears it leaves the view. */
function savedViewMembership(kind: Selection['kind']): 'isFavorite' | 'isKept' | 'isViewed' | null {
  switch (kind) {
    case 'favorites':
      return 'isFavorite';
    case 'kept':
      return 'isKept';
    case 'viewed':
      return 'isViewed';
    default:
      return null;
  }
}

function withoutId(ids: ReadonlySet<number>, id: number): ReadonlySet<number> {
  const remaining = new Set(ids);
  remaining.delete(id);
  return remaining;
}
```

- [ ] **Step 2: Shell `.ts`**
  - Replace the `EntryActionHandler` provider with `EntryStateActions, { provide: EntryActionHandler, useExisting: EntryStateActions }`.
  - Drop `implements EntryActionHandler` from the class and `forwardRef` from the imports.
  - Add the field `readonly entryActions = inject(EntryStateActions);`.
  - Delete these members:
    - `viewedOnOpen`, `leavingIds` (601–606)
    - the viewed-on-open effect (639–650)
    - `favorite`, `keep`, `toggleRead` (848–861)
    - everything from `setViewed` through `open` (869–989), **except `withOpen`** (863–867)
    - the module function `savedViewMembership` (1341–1355)
  - Selection effect: replace `this.leavingIds.set(new Set());` with `this.entryActions.clearLeaving();`.
  - Remove the now-unused imports `localStatePatch`, `entryParam`, `EntryStatePatch` and `Selection`, plus `EntryDto` only if lint reports it unused.
- [ ] **Step 3: Shell `.html`**
  - `[leavingIds]="entryActions.leavingIds()"`
  - `(openOriginal)="withOpen(entryActions.openOriginal)"`. `openOriginal` stays an arrow property because it is passed unbound.
- [ ] **Step 4: Spec call sites**

```bash
sed -E -i '' \
  -e 's/componentInstance\.onOpenOriginal\(/componentInstance.entryActions.openOriginal(/g' \
  -e 's/componentInstance\.(favorite|toggleRead|leavingIds|open)\(/componentInstance.entryActions.\1(/g' \
  -e "s/proves onOpenOriginal's/proves openOriginal's/" \
  frontend/src/app/reader/reader-shell.component.spec.ts
```

  Expect 14 rewrites. These cases now exercise `EntryStateActions`:
  - "marks the opened entry read and viewed" and its six siblings (707–893)
  - "the original-article link, through the real template wiring"
  - "keeps q when opening an article"
  - all five cases of "collapsing a row out of a saved view (#478)"
- [ ] **Step 5:** Run the test cycle. Commit `refactor(#1303): entry state actions are the entry action handler`.

### Task 4: `MarkReadActions`

**Files:**
- Create `frontend/src/app/reader/shell/mark-read-actions.service.ts`.
- Modify `reader-shell.component.ts` + `.html` + `.spec.ts`.
- Modify `subscriptions.store.ts`, line 91: `type ZeroTarget` → `export type ZeroTarget`.

- [ ] **Step 1: Create the service**

The 5-way `if` chain becomes an exhaustive `switch` for the request, plus `watermarkOf` for the follow-up. A new `MarkReadTarget` scope now fails to compile instead of falling through to the watermark endpoint.

```ts
import { Injectable, computed, inject } from '@angular/core';
import { HttpErrorResponse } from '@angular/common/http';
import { Observable } from 'rxjs';
import { TranslocoService } from '@jsverse/transloco';
import { ConfirmService } from '../../shared/confirm-dialog/confirm.service';
import { ReaderApi } from '../reader-api';
import { EntriesStore } from '../entries.store';
import { SubscriptionsStore, ZeroTarget } from '../subscriptions.store';
import { SavedSearchesStore } from '../saved-searches.store';
import { RecommendationsService } from '../recommendations.service';
import { MarkReadTarget, markReadTarget, queryFromSelection } from '../query';
import { ReaderRouteState } from './reader-route-state.service';

@Injectable()
export class MarkReadActions {
  private readonly api = inject(ReaderApi);
  private readonly confirm = inject(ConfirmService);
  private readonly i18n = inject(TranslocoService);
  private readonly entries = inject(EntriesStore);
  private readonly subscriptions = inject(SubscriptionsStore);
  private readonly savedSearches = inject(SavedSearchesStore);
  private readonly recommendations = inject(RecommendationsService);
  private readonly routeState = inject(ReaderRouteState);

  readonly canMarkAllRead = computed(() => markReadTarget(this.routeState.selection()) !== null);

  confirmMarkAllRead(): void {
    const target = markReadTarget(this.routeState.selection());
    if (!target) return;
    const question = {
      title: this.i18n.translate('reader.markAllReadConfirm'),
      message: this.i18n.translate('reader.markAllReadConfirmMessage'),
      confirmLabel: this.i18n.translate('reader.markAllRead'),
    };
    this.confirm.confirmThen(question, () => this.markAllReadNow(target));
  }

  confirmMarkAboveRead(ids: number[], hideInUnreadList: () => void): void {
    if (ids.length === 0) return;
    const question = {
      title: this.i18n.translate('reader.markAboveReadConfirm'),
      message: this.i18n.translate('reader.markAboveReadConfirmMessage', { count: ids.length }),
      confirmLabel: this.i18n.translate('reader.markAboveRead'),
    };
    this.confirm.confirmThen(question, () => this.markAboveReadNow(ids, hideInUnreadList));
  }

  /** Never a re-fetch: a reload lets the magazine planner lift newer posts above the boundary. */
  private markAboveReadNow(ids: number[], hideInUnreadList: () => void): void {
    const hideLocally = this.routeState.selection().unread
      ? hideInUnreadList
      : () => this.entries.markHiddenLocally(ids);
    this.api.markEntriesRead(ids).subscribe({
      next: () => {
        hideLocally();
        this.subscriptions.load();
        this.savedSearches.load();
        this.recommendations.refreshStatus();
      },
      error: (error: HttpErrorResponse) =>
        this.entries.reportMutationFailure(error, () =>
          this.markAboveReadNow(ids, hideInUnreadList),
        ),
    });
  }

  private markAllReadNow(target: MarkReadTarget): void {
    const until = this.entries.loadedAt() || new Date().toISOString();
    this.entries.runThenReload(this.markReadRequest(target, until), () =>
      this.reloadAfterMarkRead(target),
    );
  }

  private markReadRequest(target: MarkReadTarget, until: string): Observable<void> {
    switch (target.scope) {
      case 'search':
        return this.api.markSearchRead(target.term, until);
      case 'for-you':
        return this.api.markForYouRead(until);
      case 'saved-searches':
        return this.api.markSavedSearchesRead(until);
      case 'saved-search':
        return this.api.markSingleSavedSearchRead(target.id, until);
      case 'all':
        return this.api.markRead(target.scope, until);
      case 'tag':
      case 'feed':
        return this.api.markRead(target.scope, until, target.id);
    }
  }

  private reloadAfterMarkRead(target: MarkReadTarget): void {
    const watermark = watermarkOf(target);
    this.entries.load(queryFromSelection(this.routeState.selection()));
    if (watermark === null) this.subscriptions.load();
    else this.subscriptions.zeroUnread(watermark);
    this.savedSearches.load();
    // Marked picks move no watermark the reload sees, so re-read the for-you summary.
    if (target.scope === 'for-you') this.recommendations.refreshStatus();
  }
}

/** The scopes the backend marks by watermark, whose unread counts can be zeroed locally. */
function watermarkOf(target: MarkReadTarget): ZeroTarget | null {
  switch (target.scope) {
    case 'all':
      return 'all';
    case 'tag':
      return { tag: target.id };
    case 'feed':
      return { subscription: target.id };
    default:
      return null;
  }
}
```

- [ ] **Step 2: Shell `.ts`**
  - `providers`: add `MarkReadActions`.
  - Add the field `readonly markRead = inject(MarkReadActions);`.
  - Delete these members:
    - `canMarkAllRead` (355)
    - `onMarkAllRead` (997–1008)
    - `markAboveReadNow`, `refreshCountsAfterMarkRead`, `markReadNow`, `reloadListAndCounts` (1020–1098)
  - Replace `onMarkAboveRead` (1010–1018) with:
    ```ts
    onMarkAboveRead(ids: number[]): void {
      this.markRead.confirmMarkAboveRead(ids, () => this.list()?.hideAboveMarked(ids));
    }
    ```
  - Remove the `api` field and the now-unused imports: `ReaderApi`, `HttpErrorResponse`, `MarkReadTarget`, `markReadTarget`.
- [ ] **Step 3: Shell `.html`.** Change these two bindings: `[canMarkAllRead]="markRead.canMarkAllRead()"` and `(markAllRead)="markRead.confirmMarkAllRead()"`.
- [ ] **Step 4: Spec call sites**

```bash
sed -E -i '' \
  -e 's/componentInstance\.onMarkAllRead\(/componentInstance.markRead.confirmMarkAllRead(/g' \
  -e 's/componentInstance\.canMarkAllRead\(/componentInstance.markRead.canMarkAllRead(/g' \
  frontend/src/app/reader/reader-shell.component.spec.ts
```

  Expect 11 rewrites. These describes now exercise `MarkReadActions` unchanged:
  - "mark all read for a search (#581)"
  - "mark all read for the ranked feed (#710)"
  - "mark all read for the combined saved-searches view (#769)"
  - "marks it read via its by-id endpoint…" (#1118)
  - "marking everything above the fold as read (#1080)", whose `onMarkAboveRead` call sites are unchanged
- [ ] **Step 5:** Run the test cycle. Commit `refactor(#1303): mark-read actions leave the shell`.

### Task 5: Onboarding sweep and the first-boot passkey offer

**Files:**
- Create `frontend/src/app/reader/shell/reader-onboarding.service.ts` and `frontend/src/app/reader/shell/passkey-first-boot-offer.service.ts`.
- Modify `reader-shell.component.ts` + `.html`.
- Modify `passkey-offer-dialog.component.ts`, doc comment at line 19.

- [ ] **Step 1: `ReaderOnboarding`**

```ts
import { Injectable, computed, effect, inject, signal, untracked } from '@angular/core';
import { Router } from '@angular/router';
import { AuthService } from '../../core/auth.service';
import { SubscriptionsStore } from '../subscriptions.store';
import { CatalogStore } from '../catalog/catalog.store';
import { OnboardingSkip } from '../catalog/onboarding-skip';
import { RefreshService } from '../refresh.service';

@Injectable()
export class ReaderOnboarding {
  private readonly router = inject(Router);
  private readonly auth = inject(AuthService);
  private readonly subscriptions = inject(SubscriptionsStore);
  private readonly catalog = inject(CatalogStore);
  private readonly skip = inject(OnboardingSkip);
  private readonly refresh = inject(RefreshService);

  private readonly catalogOffered = computed(
    () => this.catalog.resolved() && this.catalog.hasEntries(),
  );

  readonly showCatalogEmptyWarning = computed(
    () => this.auth.isAdmin() && this.catalog.resolved() && !this.catalog.hasEntries(),
  );

  private readonly awaitingFirstFetch = computed(
    () =>
      this.subscriptions.resolved() &&
      this.subscriptions.subscriptions().length > 0 &&
      this.subscriptions.subscriptions().every((subscription) => subscription.lastFetchedAt === null),
  );

  private readonly sweptOnce = signal(false);
  /** Unlike the permanent `sweptOnce` latch, clears once the sweep lands without error. */
  private readonly sweepInFlight = signal(false);
  readonly sweeping = this.sweepInFlight.asReadonly();

  readonly showFetchProgress = computed(() => this.sweeping() && this.refresh.failure() === null);

  /** A failed load also resolves empty; `error()` keeps that from reading as onboarding. */
  private readonly emptySubscriptionsNeedingOnboarding = computed(
    () =>
      this.subscriptions.resolved() &&
      !this.subscriptions.error() &&
      this.subscriptions.subscriptions().length === 0 &&
      !this.skip.wasSkipped(),
  );

  /** An unanswered catalog counts as running: the redirect is still undecided. */
  readonly running = computed(() => {
    if (this.awaitingFirstFetch() || this.sweeping()) return true;
    if (!this.emptySubscriptionsNeedingOnboarding()) return false;
    if (!this.catalog.resolved()) return true;
    return this.catalogOffered();
  });

  constructor() {
    effect(() => {
      if (this.auth.isAdmin()) untracked(() => this.catalog.load());
    });

    effect(() => {
      if (!this.emptySubscriptionsNeedingOnboarding()) return;
      untracked(() => this.catalog.load());
      if (!this.catalogOffered()) return;
      void this.router.navigate(['/discover'], { replaceUrl: true });
    });

    // Driven by state, not a call: run() early-returns while running, so a call could be lost.
    effect(() => {
      if (!this.awaitingFirstFetch() || this.sweptOnce()) return;
      this.sweptOnce.set(true);
      this.sweepInFlight.set(true);
      this.refresh.run();
    });

    effect(() => {
      if (this.sweeping() && !this.refresh.running() && this.refresh.failure() === null) {
        untracked(() => this.sweepInFlight.set(false));
      }
    });
  }
}
```

- [ ] **Step 2: `PasskeyFirstBootOffer`**

```ts
import { Injectable, computed, effect, inject, signal, untracked } from '@angular/core';
import { Dialog } from '@angular/cdk/dialog';
import { catchError, of } from 'rxjs';
import { AuthService } from '../../core/auth.service';
import { SetupService } from '../../core/setup.service';
import { isPasskeySupported } from '../../core/webauthn';
import { SubscriptionsStore } from '../subscriptions.store';
import { PasskeyOfferDialogComponent } from '../passkey-offer-dialog.component';
import { ReaderOnboarding } from './reader-onboarding.service';

@Injectable()
export class PasskeyFirstBootOffer {
  private readonly dialog = inject(Dialog);
  private readonly auth = inject(AuthService);
  private readonly setup = inject(SetupService);
  private readonly subscriptions = inject(SubscriptionsStore);
  private readonly onboarding = inject(ReaderOnboarding);

  private readonly eligible = computed(() => {
    if (!isPasskeySupported()) return false;
    // Exactly true: an instance that cannot sign in by passkey must not enrol one.
    if (this.setup.passkeySignInAvailable() !== true) return false;
    const user = this.auth.user();
    if (!user || user.preferences.passkeyOfferAnswered) return false;
    return this.subscriptions.resolved() && !this.onboarding.running();
  });

  private readonly shown = signal(false);

  constructor() {
    // The reader route is never behind setupRedirectGuard, so nothing else loads the flag.
    if (isPasskeySupported()) {
      this.setup
        .ensureLoaded()
        .pipe(catchError(() => of(false)))
        .subscribe();
    }
    effect(() => {
      if (!this.eligible() || this.shown()) return;
      untracked(() => {
        this.shown.set(true);
        this.dialog.open<void>(PasskeyOfferDialogComponent, { panelClass: 'app-dialog' });
      });
    });
  }
}
```

- [ ] **Step 3: Shell `.ts`**
  - `providers`: add `ReaderOnboarding, PasskeyFirstBootOffer`.
  - Delete lines 163–272 (from `onboardingAvailable` through `offerPasskeyOnFirstBoot`), keeping `fetchFailureKey` (274–280).
  - In their place add:
    ```ts
    protected readonly onboarding = inject(ReaderOnboarding);
    /** Injected for its effect: holding it opens the passkey offer when due. */
    private readonly passkeyOffer = inject(PasskeyFirstBootOffer);
    ```
  - Delete the constructor's passkey `ensureLoaded` block (609–621), the redirect effect (708–721) and both sweep effects (723–740).
  - In the post-refresh reload effect, replace `this.sweeping()` with `this.onboarding.sweeping()`.
  - Remove the `skip`, `catalog` and `setup` fields and the now-unused imports: `isPasskeySupported`, `catchError`, `of`, `OnboardingSkip`, `CatalogStore`, `SetupService`, `PasskeyOfferDialogComponent`.
- [ ] **Step 4: Shell `.html`:** `onboarding.showFetchProgress()` and `onboarding.showCatalogEmptyWarning()`.
- [ ] **Step 5:** In `passkey-offer-dialog.component.ts:19`, change "see `ReaderShellComponent`'s gating effect" to "see `PasskeyFirstBootOffer`". The spec needs no edits: "onboarding redirect and first sweep", "onboarding sweep still fills progressively", "admin empty-catalog warning" and "the first-login passkey offer (#624)" now exercise the two services through the shell.
- [ ] **Step 6:** Run the test cycle. Commit `refactor(#1303): onboarding sweep and passkey offer leave the shell`.

### Task 6: Verify and ship

- [ ] Run `wc -l frontend/src/app/reader/reader-shell.component.ts` and expect about 650.
- [ ] `docker compose exec -T frontend npm run check`, then `docker compose exec -T frontend npm run build`, then the click-through from the Global Constraints.
- [ ] Push. Open the PR against `develop` with the body `Closes #1303`, the out-of-scope list and the line counts.
- [ ] `gh pr checks --watch`; when green, `gh pr merge --merge --delete-branch`. Verify #1303 closed.
