# Selection as a Discriminated Union Implementation Plan (#2107)

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Turn `Selection` (`frontend/src/app/reader/query/query.ts`) from one interface with a `kind` and optional fields into a discriminated union over `kind`, where each member carries exactly its own fields, and make every table and reader over it exhaustive and free of null guards.

**Architecture:** The union is built from one `SelectionFields` map (kind → fields), so `SelectionOf<K>` names one member and `BySelectionKind<R>` types a lookup table whose every arm receives its own narrowed member. One dispatcher, `bySelectionKind(table, selection)`, is the only place the kind-to-arm correlation is taken on trust (TypeScript cannot correlate a union with a table indexed by it, TS #30581). `sameSelection`, `listOrderKey` and `scrollKey` all read one per-kind `identityKey()` (the id, term, network, group or window a kind carries), so "which fields make a list's identity" is written once. `queryFromSelection` and `markReadTarget` move out of `query.ts` into `entry-query.ts` and `mark-read-target.ts`: `query.ts` already counts 291 of its 300 allowed lines and the union adds lines; both are wire mappings with their own importers (3 and 2 files), so the split follows responsibility, not just the count. The work is ordered so the tree compiles after every task: pin the URLs, move the two mappings, route every generic reader through three new accessors on the old type, then swap the type.

**Tech Stack:** Angular 20 (standalone components, signals, strict templates), TypeScript strict, Jest (jsdom), ESLint (`complexity: 10`, `max-lines: 300`), Prettier (printWidth 100).

**Spec:** GitHub issue #2107 (`gh issue view 2107`). Frontend precedent: #2106 made `RefreshScope` a discriminated union (`f58d6ff41`), and it is merged, so `query.ts` is in its post-#2106 state.

## Global Constraints

- Branch `refactor/2107-selection-union` is already checked out. Concurrent sessions may share the checkout: run `git status` before any `checkout`, `reset` or `stash`.
- Commit format: `refactor(#2107): <summary>` (a test-only commit is `test(#2107): …`). No Claude attribution anywhere (no `Co-Authored-By`, no "Generated with").
- No behaviour change, with one deliberate exception named in Task 4: the scroll memory key now also tells Trending's day and week windows apart (it is `sessionStorage`, tab-scoped; `sameSelection` already treats them as different lists).
- `selectionFromRoute` must accept exactly the URLs it accepts today. Task 1 pins them; that spec must not change after Task 2.
- `EntryQuery` (`frontend/src/app/reader/models.ts`) stays the wire shape. It is not touched.
- `listOrderKey()` output is persisted (the account's oldest-first list keys). Its strings must not change: `all`, `viewed`, `saved-searches`, `tag:3`, `subscription:12`, `saved-search:7`, `network:bluesky`, `network:mastodon:profile`, `group:tags`, `search`, and `null` for for-you and Trending.
- ESLint `max-lines` is 300 per file with blank and comment-only lines skipped (specs are exempt). Current counts: `query.ts` 291, `reader-shell.component.ts` 296, `sidebar.component.ts` 286, `entry-list.component.ts` 294. This plan adds 2 lines to `reader-shell.component.ts` and 8 to `sidebar.component.ts`, and brings `query.ts` down. Add nothing else to those files.
- ESLint `complexity` is 10 per function and `sonarjs/cognitive-complexity` 15. A `switch` over all 14 kinds would score 15, so every exhaustive site in this plan is a typed `BySelectionKind<R>` table (`VIEW_QUERY`, `MARK_READ_TARGET`, `IDENTITY_KEY`, the heading's `titleByKind` and `countByKind`) or a kind-only `Record<SelectionKind, …>` (`FIXED_VIEW_ICON`). The one `switch` that stays (`RefreshActions.refreshScope`, five cases and a `default`) is partial by design and scores 8.
- `unicorn/prevent-abbreviations` is on: spell names out.
- Comments: default to none; one line where one is earned. Delete the "Only X carries one" comments with the fields they described.
- `npm run check` does not type-check Angular templates; `npm run build` does. This plan edits templates, so every task ends with both.
- After editing, run `npx prettier --write` on every touched `.ts`/`.html` file before `npm run check`. The sed passes in Task 4 shorten lines, and Prettier will want to re-join some of them.
- Jest: targeted runs happen **inside the Docker frontend container** (`docker compose exec -T frontend npx jest <paths>`, workdir `/app` = `frontend/`). **Never start two Jest runs at once**, because they run out of memory. That includes the native `npm test` that `npm run check` runs: do not start a Docker Jest run while `npm run check` is running, or the reverse.

## Kinds and the fields each one really uses

Derived from where each kind is built (`selectionFromParams` and its helpers in `query.ts`, `selectionFromRoute` in `reader-matcher.ts`, `withUnreadPreference`, `withListOrder`) and read (`VIEW_QUERY`, `MARK_READ_TARGET`, `listOrderKey`, `scrollKey`, `sameSelection`, the heading, the header, the sidebar, refresh):

| Kind | Built by | `unread` | `order` | Own fields |
|---|---|---|---|---|
| `all` | `feedScopedSelection` | yes (`hasUnreadFilter` via `canScopedRefresh`) | yes | none |
| `tag` | `feedScopedSelection` | yes | yes | `id: number` |
| `subscription` | `feedScopedSelection` | yes | yes | `id: number` |
| `network` | `networkSelection` | yes | yes | `network: SocialNetworkName`, `feedKind?: NetworkFeedKind` |
| `group` | `feedScopedSelection` | yes | yes | `group: FeedGroupName` |
| `favorites`, `kept`, `shared`, `viewed` | `viewFromParams` (named view) | no: `hasUnreadFilter` is false, so `unread` is always `false` | yes | none |
| `for-you` | `viewFromParams` (named view) | yes | no: `hasListOrder` is false | none |
| `trending` | `trendingFromParams` | no | no | `window: TrendingWindow` |
| `saved-searches` | `viewFromParams` and `selectionFromRoute` | yes | yes | none |
| `saved-search` | `selectionFromRoute` only | yes | yes | `id: number` |
| `search` | `selectionFromParams` (searchable `q`) | yes | yes | `term: string` |

`id` is never `null` on a member that carries one: the parse turns every missing or invalid id into another kind (`all` from `feedScopedSelection`, `saved-searches` from `selectionFromRoute`). That is why `idScoped()`, the `?? undefined` guards and the network/group/term null arms in `MARK_READ_TARGET` can go.

## Files that read or construct a `Selection`

| File | Task | Change |
|---|---|---|
| `frontend/src/app/reader/query/reader-url.spec.ts` | 1 | **Create**: every URL `selectionFromRoute` accepts, pinned by kind, wire query, entry id and URL correction |
| `frontend/src/app/reader/query/entry-query.ts` | 2, 4 | **Create**: `queryFromSelection` + `VIEW_QUERY` moved out of `query.ts`; typed per member in Task 4 |
| `frontend/src/app/reader/query/entry-query.spec.ts` | 2, 4 | **Create**: the `queryFromSelection` describe block moved out of `query.spec.ts` |
| `frontend/src/app/reader/query/mark-read-target.ts` | 2, 4 | **Create**: `MarkReadTarget`, `ScopedMarkReadTarget`, `markReadTarget` + `MARK_READ_TARGET`; exhaustive in Task 4 |
| `frontend/src/app/reader/query/mark-read-target.spec.ts` | 2, 4 | **Create**: the `markReadTarget` describe block moved out; impossible-state assertions deleted in Task 4 |
| `frontend/src/app/reader/query/query.ts` | 2, 3, 4 | Exports `SelectionKind`; gains `isUnreadOnly`, `searchTermOf`, `trendingWindowOf`; becomes the union in Task 4 |
| `frontend/src/app/reader/query/query.spec.ts` | 1–4 | Imports from the new files; `.unread` reads go through `isUnreadOnly`; literals narrowed |
| `frontend/src/app/reader/query/reader-matcher.ts` | 4 | Two literals lose `id: null` |
| `frontend/src/app/reader/models.ts` | 2 | Doc comment points at `mark-read-target.ts` |
| `frontend/src/app/reader/reader-api.ts` | 2 | `ScopedMarkReadTarget` import path |
| `frontend/src/app/reader/shell/mark-read-actions.service.ts` | 2, 3 | Import paths; `isUnreadOnly` |
| `frontend/src/app/reader/shell/list-reload.service.ts` | 2 | Import path |
| `frontend/src/app/reader/shell/refresh-actions.service.ts` | 2, 4 | Import path; `refreshScope` narrows, `sectionScope` deleted |
| `frontend/src/app/reader/shell/reader-route-state.service.ts` | 3 | Trending effect reads `trendingWindowOf` |
| `frontend/src/app/reader/shell/reader-shell.component.{ts,html}` | 3 | `searchTerm`, `trendingWindow` computeds; `activeTagId` drops `?? null` |
| `frontend/src/app/reader/shell/list-heading.service.ts` | 3, 4 | `searchTermOf`; typed tables, `networkCounts`, `bySwitch` on `FilteredSelection` |
| `frontend/src/app/reader/shell/saved-search-toggle.service.ts` | 4 | `selection.term` without `?? ''` |
| `frontend/src/app/reader/shell/sidebar/sidebar.component.{ts,html}` | 3 | `searchTerm`, `activeTagId`, narrowed `activeGroup`; `activeTagPath` reads `activeTagId` |
| `frontend/src/app/reader/shell/sidebar/sidebar-social-section.component.ts` | 3 | `selectionOnThisNetwork` replaces the two `.feedKind` reads |
| `frontend/src/app/reader/list/list-header/list-header.component.{ts,html}` | 3, 4 | `unreadOnly`, `titleNetwork` computeds; exhaustive `FIXED_VIEW_ICON`; badges read `selection.term` |
| `frontend/src/app/reader/list/list-empty-state/list-empty-state.component.{ts,html}` | 3 | `unreadOnly` computed; `searchTermOf`, `trendingWindowOf` |
| `frontend/src/app/reader/list/entry-list/entry-list.component.ts` | 3 | `searchTermOf` |
| `frontend/src/app/reader/scroll/list-scroll-memory.ts` | 4 | `scrollKey` reads `identityKey`, `isUnreadOnly`; `networkKey` import gone |
| `frontend/src/app/reader/scroll/list-scroll-memory.spec.ts` | 4 | The `Partial<Selection>` factory `sel()` is replaced by typed literals |
| Spec fixtures (`entry-list.component.spec.ts`, `list-scroll-reset.spec.ts`, `mark-read-actions.service.spec.ts`, `reader-shell.component.spec.ts`, `list-heading.service.spec.ts`, `sidebar-social-section.component.spec.ts`, `sidebar.component.spec.ts`, `list-order.service.spec.ts`, `list-preferences.service.spec.ts`) | 3, 4 | `.unread` reads via `isUnreadOnly` (Task 3); literals narrowed by sed plus four hand edits (Task 4) |

Read but unchanged (they only use `kind`, `sameSelection`, `isDirectSearch`, `canScopedRefresh`, `isSingleStreamView`, `listOrderKey` or pass the value through): `list-scroll-reset.ts`, `list-content.ts`, `list-reading-focus.ts`, `list-scroll-state.ts`, `entry-state-actions.service.ts`, `list-order.service.ts`, `list-preferences.service.ts`, `sidebar-views.component.{ts,html}`, `sidebar-feed-row.component.ts` (its `selection.kind === 'subscription' && selection.id === …` narrows by itself). `discover.component.ts` and `organise-section.component.*` match the grep but use other "selection" types.

No test factory is added. After the change every literal is the member itself (`{ kind: 'tag', id: 3, unread: true }`) and the compiler checks it; a `tagSelection(3, { unread: true })` helper would not be shorter. The one factory that exists, `sel(over: Partial<Selection>)` in `list-scroll-memory.spec.ts`, cannot survive a union (a `Partial` of a union spread over `kind: 'all'` is not a `Selection`) and is replaced in Task 4.

---

### Task 1: Pin every URL the route parse accepts

**Files:**
- Create: `frontend/src/app/reader/query/reader-url.spec.ts`

**Interfaces:**
- Consumes: `readerMatcher`, `selectionFromRoute` (`./reader-matcher`); `queryFromSelection`, `ViewContext` (`./query`, moved in Task 2).
- Produces: a characterisation spec that asserts, per URL, the kind, the `EntryQuery` the list asks for, the open entry id and the URL correction. Those four outlive the `Selection` type, so the expectations never change in this plan.

The existing `selectionFromRoute` and `selectionFromParams` tests assert whole `Selection` objects, which Task 4 must edit. This spec goes through the real URL serializer and the real matcher and asserts only refactor-invariant values, so it is the regression guard.

- [ ] **Step 1: Write the spec**

```ts
import { DefaultUrlSerializer, PRIMARY_OUTLET, convertToParamMap } from '@angular/router';
import { EntryQuery } from '../models';
import { readerMatcher, selectionFromRoute } from './reader-matcher';
import { ViewContext, queryFromSelection } from './query';

const ON: ViewContext = { trending: true, trendingWindow: 'day' };
const OFF: ViewContext = { trending: false, trendingWindow: 'day' };

interface Opened {
  kind: string;
  query: EntryQuery;
  entryId: number | null;
  urlCorrection: Record<string, string | null> | null;
}

/** What the reader opens for a URL, in the terms the request and the address bar see. */
function opened(url: string, context: ViewContext): Opened | null {
  const tree = new DefaultUrlSerializer().parse(url);
  const match = readerMatcher(tree.root.children[PRIMARY_OUTLET]?.segments ?? []);
  if (match === null) return null;
  const savedSearch = match.posParams?.['savedSearch']?.path;
  const pathParams = convertToParamMap(savedSearch === undefined ? {} : { savedSearch });
  const parsed = selectionFromRoute(pathParams, convertToParamMap(tree.queryParams), context);
  return {
    kind: parsed.selection.kind,
    query: queryFromSelection(parsed.selection),
    entryId: parsed.entryId,
    urlCorrection: parsed.urlCorrection,
  };
}

const list = (kind: string, query: EntryQuery, entryId: number | null = null): Opened => ({
  kind,
  query,
  entryId,
  urlCorrection: null,
});

describe('the reader URLs (#2107 pin)', () => {
  it.each<[string, Opened]>([
    ['/', list('all', { view: 'all' })],
    ['/?unread=1', list('all', { view: 'all' })],
    ['/?entry=42', list('all', { view: 'all' }, 42)],
    ['/?entry=514-some-title', list('all', { view: 'all' }, 514)],
    ['/?subscription=7&entry=42', list('subscription', { view: 'all', subscription: 7 }, 42)],
    ['/?subscription=0', list('all', { view: 'all' })],
    ['/?subscription=abc', list('all', { view: 'all' })],
    ['/?tag=3', list('tag', { view: 'all', tag: 3 })],
    ['/?tag=x', list('all', { view: 'all' })],
    ['/?tag=-2', list('all', { view: 'all' })],
    ['/?subscription=7&tag=3', list('subscription', { view: 'all', subscription: 7 })],
    ['/?tag=3&network=mastodon', list('tag', { view: 'all', tag: 3 })],
    ['/?network=mastodon', list('network', { view: 'all', network: 'mastodon' })],
    ['/?network=bluesky', list('network', { view: 'all', network: 'bluesky' })],
    [
      '/?network=bluesky&feedKind=profile',
      list('network', { view: 'all', network: 'bluesky', feedKind: 'profile' }),
    ],
    [
      '/?network=mastodon&feedKind=search',
      list('network', { view: 'all', network: 'mastodon', feedKind: 'search' }),
    ],
    ['/?network=mastodon&feedKind=home', list('network', { view: 'all', network: 'mastodon' })],
    ['/?network=myspace', list('all', { view: 'all' })],
    ['/?network=mastodon&group=tags', list('network', { view: 'all', network: 'mastodon' })],
    ['/?group=tags', list('group', { view: 'all', group: 'tags' })],
    ['/?group=feeds', list('group', { view: 'all', group: 'feeds' })],
    ['/?group=folders', list('all', { view: 'all' })],
    ['/?view=favorites', list('favorites', { view: 'favorites' })],
    ['/?view=kept', list('kept', { view: 'kept' })],
    ['/?view=shared', list('shared', { view: 'shared' })],
    ['/?view=viewed', list('viewed', { view: 'viewed' })],
    ['/?view=for-you', list('for-you', { view: 'for-you' })],
    ['/?view=for-you&unread=1', list('for-you', { view: 'for-you' })],
    ['/?view=saved-searches', list('saved-searches', { view: 'saved-searches' })],
    ['/?view=favorites&tag=3', list('favorites', { view: 'favorites' })],
    ['/?view=unknown&tag=3', list('tag', { view: 'all', tag: 3 })],
    ['/?view=kept&window=week', list('kept', { view: 'kept' })],
    ['/?view=trending&window=week', list('trending', { view: 'trending', window: 'week' })],
    ['/?view=trending&window=day', list('trending', { view: 'trending', window: 'day' })],
    [
      '/?view=trending',
      { ...list('trending', { view: 'trending', window: 'day' }), urlCorrection: { window: 'day' } },
    ],
    [
      '/?view=trending&window=month',
      { ...list('trending', { view: 'trending', window: 'day' }), urlCorrection: { window: 'day' } },
    ],
    ['/?q=angular', list('search', { view: 'all', q: 'angular' })],
    ['/?q=punk%20', list('search', { view: 'all', q: 'punk ' })],
    ['/?q=%20%20angular%20%20%20js%20%20', list('search', { view: 'all', q: 'angular js ' })],
    ['/?q=angular&tag=3', list('search', { view: 'all', q: 'angular' })],
    ['/?view=saved-searches&q=climate', list('search', { view: 'all', q: 'climate' })],
    ['/?view=trending&q=climate', list('search', { view: 'all', q: 'climate' })],
    ['/?q=an', list('all', { view: 'all' })],
    ['/?q=ab%20', list('all', { view: 'all' })],
    ['/?q=', list('all', { view: 'all' })],
    ['/?q=%20%20%20', list('all', { view: 'all' })],
    ['/searches/saved/all', list('saved-searches', { view: 'saved-searches' })],
    ['/searches/saved/42', list('saved-search', { view: 'all', savedSearchId: 42 })],
    ['/searches/saved/42-climate', list('saved-search', { view: 'all', savedSearchId: 42 })],
    ['/searches/saved/climate', list('saved-searches', { view: 'saved-searches' })],
    ['/searches/saved/42x', list('saved-searches', { view: 'saved-searches' })],
    [
      '/searches/saved/42-climate?entry=99',
      list('saved-search', { view: 'all', savedSearchId: 42 }, 99),
    ],
    ['/searches/saved/all?entry=99', list('saved-searches', { view: 'saved-searches' }, 99)],
    [
      '/searches/saved/42-climate?q=angular&tag=3',
      list('saved-search', { view: 'all', savedSearchId: 42 }),
    ],
    ['/searches/saved/42-climate?unread=1', list('saved-search', { view: 'all', savedSearchId: 42 })],
  ])('opens %s', (url, expected) => {
    expect(opened(url, ON)).toEqual(expected);
  });

  it.each<[string, Opened]>([
    [
      '/?view=trending&window=week',
      { ...list('all', { view: 'all' }), urlCorrection: { view: null, window: null } },
    ],
    [
      '/?view=trending&tag=3',
      { ...list('tag', { view: 'all', tag: 3 }), urlCorrection: { view: null, window: null } },
    ],
  ])('opens %s on an account that switched Trending off', (url, expected) => {
    expect(opened(url, OFF)).toEqual(expected);
  });

  it.each(['/settings', '/searches/saved', '/searches/saved/1/extra', '/searches/42'])(
    'leaves %s to another route',
    (url) => {
      expect(opened(url, ON)).toBeNull();
    },
  );
});
```

Notes for the executor:
- `toEqual` treats a property whose value is `undefined` as absent, so a network query built with `feedKind: undefined` equals `{ view: 'all', network: 'mastodon' }`. That is the current behaviour and stays.
- The entry ids rely on `entryIdFromParam` reading an id or an id-prefixed slug (`514-some-title` → 514), as `selectionFromParams` documents.

- [ ] **Step 2: Run it**

Run: `docker compose exec -T frontend npx jest src/app/reader/query/reader-url.spec.ts`
Expected: PASS (it characterises today's behaviour). If any row fails, the row is wrong, not the code: read the parse in `query.ts`/`reader-matcher.ts`, correct the expectation to what the code does today, and note it in the commit message. Do not change application code in this task.

- [ ] **Step 3: Gates**

Run from `frontend/`: `npx prettier --write src/app/reader/query/reader-url.spec.ts`, then `npm run check`, then `npm run build`.
Expected: all green.

- [ ] **Step 4: Commit**

```bash
git add frontend/src/app/reader/query/reader-url.spec.ts
git commit -m "test(#2107): pin every URL the reader route parse accepts"
```

---

### Task 2: Move the two wire mappings out of query.ts

**Files:**
- Create: `frontend/src/app/reader/query/entry-query.ts`, `frontend/src/app/reader/query/entry-query.spec.ts`
- Create: `frontend/src/app/reader/query/mark-read-target.ts`, `frontend/src/app/reader/query/mark-read-target.spec.ts`
- Modify: `frontend/src/app/reader/query/query.ts`, `frontend/src/app/reader/query/query.spec.ts`, `frontend/src/app/reader/query/reader-url.spec.ts`
- Modify: `frontend/src/app/reader/models.ts:414`, `frontend/src/app/reader/reader-api.ts:12`, `frontend/src/app/reader/shell/mark-read-actions.service.ts:10`, `frontend/src/app/reader/shell/list-reload.service.ts:13`, `frontend/src/app/reader/shell/refresh-actions.service.ts:13`

**Interfaces:**
- Consumes: `Selection`, `listOrderOf` from `./query`.
- Produces: `export type SelectionKind = Selection['kind']` in `query.ts`; `queryFromSelection(selection: Selection): EntryQuery` in `entry-query.ts`; `MarkReadTarget`, `ScopedMarkReadTarget`, `markReadTarget(selection: Selection): MarkReadTarget | null` in `mark-read-target.ts`. Bodies move verbatim; Task 4 retypes them.

- [ ] **Step 1: Export `SelectionKind` from `query.ts`**

In `query.ts`, delete the line `type SelectionKind = Selection['kind'];` (it sits between `viewQuery` and `VIEW_QUERY`) and add, directly after the closing `}` of `export interface Selection { … }`:

```ts
export type SelectionKind = Selection['kind'];
```

- [ ] **Step 2: Create `entry-query.ts`**

```ts
import { EntryQuery } from '../models';
import { Selection, SelectionKind, listOrderOf } from './query';

export function queryFromSelection(selection: Selection): EntryQuery {
  const query = viewQuery(selection);
  return listOrderOf(selection) === 'oldest' ? { ...query, order: 'oldest' } : query;
}

function viewQuery(selection: Selection): EntryQuery {
  return VIEW_QUERY[selection.kind](selection);
}

const VIEW_QUERY: Record<SelectionKind, (selection: Selection) => EntryQuery> = {
  favorites: () => ({ view: 'favorites' }),
  kept: () => ({ view: 'kept' }),
  shared: () => ({ view: 'shared' }),
  viewed: () => ({ view: 'viewed' }),
  // The one list whose unread filter is not a view of its own: the ranked
  // feed IS the view, so the filter travels beside it (#710).
  'for-you': (selection) => withUnread({ view: 'for-you' }, selection.unread),
  trending: (selection) => ({ view: 'trending', window: selection.window }),
  tag: (selection) => ({ view: listView(selection), tag: selection.id ?? undefined }),
  network: (selection) => ({
    view: listView(selection),
    network: selection.network,
    feedKind: selection.feedKind,
  }),
  group: (selection) => ({ view: listView(selection), group: selection.group }),
  subscription: (selection) => ({
    view: listView(selection),
    subscription: selection.id ?? undefined,
  }),
  all: (selection) => ({ view: listView(selection) }),
  'saved-searches': (selection) => withUnread({ view: 'saved-searches' }, selection.unread),
  // A single saved search reads the membership table by id, not a live
  // query, so it carries the id and takes the unread refinement like any list.
  'saved-search': (selection) =>
    withUnread({ view: 'all', savedSearchId: selection.id ?? undefined }, selection.unread),
  search: (selection) => withUnread({ view: 'all', q: selection.term }, selection.unread),
};

function withUnread(query: EntryQuery, unread: boolean): EntryQuery {
  return unread ? { ...query, unread: true } : query;
}

function listView(selection: Selection): EntryQuery['view'] {
  return selection.unread ? 'unread' : 'all';
}
```

- [ ] **Step 3: Create `mark-read-target.ts`**

```ts
import { MarkReadScope } from '../models';
import { FeedGroupName } from '../../core/feed-group';
import { NetworkFeedKind, SocialNetworkName } from '../../core/social-network';
import { Selection, SelectionKind } from './query';

/** What "Mark all read" applies to for a selection, or null where the action
 *  doesn't apply. A discriminated union, not a bag with optional fields: id and
 *  term never coexist, and a bag would make every consumer re-assert the case. */
export type MarkReadTarget =
  | { scope: Extract<MarkReadScope, 'all'> }
  | { scope: Extract<MarkReadScope, 'feed' | 'tag'>; id: number }
  | {
      scope: Extract<MarkReadScope, 'network'>;
      network: SocialNetworkName;
      feedKind?: NetworkFeedKind;
    }
  | { scope: Extract<MarkReadScope, 'group'>; group: FeedGroupName }
  | { scope: 'search'; term: string }
  | { scope: 'for-you' }
  | { scope: 'saved-searches' }
  | { scope: 'saved-search'; id: number };

/** The targets the mark-read endpoint itself takes, sent as its body. */
export type ScopedMarkReadTarget = Extract<MarkReadTarget, { scope: MarkReadScope }>;

export function markReadTarget(selection: Selection): MarkReadTarget | null {
  return MARK_READ_TARGET[selection.kind]?.(selection) ?? null;
}

const MARK_READ_TARGET: Partial<
  Record<SelectionKind, (selection: Selection) => MarkReadTarget | null>
> = {
  all: () => ({ scope: 'all' }),
  tag: idScoped('tag'),
  network: (selection) =>
    selection.network
      ? { scope: 'network', network: selection.network, feedKind: selection.feedKind }
      : null,
  group: (selection) => (selection.group ? { scope: 'group', group: selection.group } : null),
  subscription: idScoped('feed'),
  search: (selection) => (selection.term ? { scope: 'search', term: selection.term } : null),
  // No id and no term: the list is the reader's own ranked feed, so the
  // endpoint needs nothing beyond who is asking (#710).
  'for-you': () => ({ scope: 'for-you' }),
  'saved-searches': () => ({ scope: 'saved-searches' }),
  'saved-search': idScoped('saved-search'),
};

function idScoped(
  scope: Extract<MarkReadTarget, { id: number }>['scope'],
): (selection: Selection) => MarkReadTarget | null {
  return (selection) => (selection.id != null ? { scope, id: selection.id } : null);
}
```

- [ ] **Step 4: Trim `query.ts`**

Delete from `query.ts`, verbatim: `export function queryFromSelection`, `function viewQuery`, `const VIEW_QUERY`, `function withUnread`, `function listView`, the `MarkReadTarget` docblock and type, the `ScopedMarkReadTarget` docblock and type, `export function markReadTarget`, `const MARK_READ_TARGET`, `function idScoped`. Keep `posInt` (the parse uses it). Change the first two import lines from:

```ts
import { ParamMap, Params, convertToParamMap } from '@angular/router';
import { EntryQuery, ListOrder, MarkReadScope } from '../models';
```

to:

```ts
import { ParamMap, Params, convertToParamMap } from '@angular/router';
import { ListOrder } from '../models';
```

- [ ] **Step 5: Point the importers at the new files**

`frontend/src/app/reader/shell/mark-read-actions.service.ts` line 10, replace
`import { MarkReadTarget, markReadTarget, queryFromSelection } from '../query/query';` with:

```ts
import { MarkReadTarget, markReadTarget } from '../query/mark-read-target';
import { queryFromSelection } from '../query/entry-query';
```

`frontend/src/app/reader/shell/list-reload.service.ts` line 13, replace
`import { queryFromSelection } from '../query/query';` with:

```ts
import { queryFromSelection } from '../query/entry-query';
```

`frontend/src/app/reader/shell/refresh-actions.service.ts` line 13, replace
`import { RefreshScope, Selection, queryFromSelection, selectionQueryParams } from '../query/query';` with:

```ts
import { RefreshScope, Selection, selectionQueryParams } from '../query/query';
import { queryFromSelection } from '../query/entry-query';
```

`frontend/src/app/reader/reader-api.ts` line 12, replace
`import { RefreshScope, ScopedMarkReadTarget } from './query/query';` with:

```ts
import { RefreshScope } from './query/query';
import { ScopedMarkReadTarget } from './query/mark-read-target';
```

`frontend/src/app/reader/models.ts` line 414, replace `rejects. See \`MarkReadTarget\` in query.ts. */` with:

```ts
 *  rejects. See `MarkReadTarget` in query/mark-read-target.ts. */
```

`frontend/src/app/reader/query/reader-url.spec.ts` (Task 1), replace
`import { ViewContext, queryFromSelection } from './query';` with:

```ts
import { ViewContext } from './query';
import { queryFromSelection } from './entry-query';
```

- [ ] **Step 6: Move the two spec blocks**

Cut the whole `describe('queryFromSelection', …)` block (`query.spec.ts` lines 343–405 today) and the whole `describe('markReadTarget', …)` block (lines 407–454) out of `query.spec.ts`. Create `entry-query.spec.ts`:

```ts
import { queryFromSelection } from './entry-query';

describe('queryFromSelection', () => {
  it('maps all/tag/subscription through the unread toggle', () => {
    expect(queryFromSelection({ kind: 'all', id: null, unread: true })).toEqual({ view: 'unread' });
    expect(queryFromSelection({ kind: 'all', id: null, unread: false })).toEqual({ view: 'all' });
    expect(queryFromSelection({ kind: 'tag', id: 3, unread: true })).toEqual({
      view: 'unread',
      tag: 3,
    });
    expect(queryFromSelection({ kind: 'subscription', id: 7, unread: false })).toEqual({
      view: 'all',
      subscription: 7,
    });
  });
  it("maps a network's view through the unread toggle", () => {
    expect(
      queryFromSelection({ kind: 'network', id: null, unread: true, network: 'bluesky' }),
    ).toEqual({ view: 'unread', network: 'bluesky' });
  });
  it('maps curated views directly', () => {
    expect(queryFromSelection({ kind: 'favorites', id: null, unread: false })).toEqual({
      view: 'favorites',
    });
    expect(queryFromSelection({ kind: 'kept', id: null, unread: false })).toEqual({ view: 'kept' });
    expect(queryFromSelection({ kind: 'shared', id: null, unread: false })).toEqual({
      view: 'shared',
    });
    expect(queryFromSelection({ kind: 'for-you', id: null, unread: false })).toEqual({
      view: 'for-you',
    });
  });
  // For you is the one list whose unread filter cannot be a view: the ranked
  // feed IS the view, so the filter rides beside it (#710).
  it('asks the ranked feed for unread picks with a flag, not a view', () => {
    expect(queryFromSelection({ kind: 'for-you', id: null, unread: true })).toEqual({
      view: 'for-you',
      unread: true,
    });
  });
  it('maps a search selection to view=all with q', () => {
    expect(
      queryFromSelection({ kind: 'search', id: null, unread: false, term: 'angular' }),
    ).toEqual({ view: 'all', q: 'angular' });
  });
  it('passes a trailing space through unchanged — the server reads it as whole-word match', () => {
    expect(queryFromSelection({ kind: 'search', id: null, unread: false, term: 'punk ' })).toEqual({
      view: 'all',
      q: 'punk ',
    });
  });
  it('routes a single saved-search selection to its id', () => {
    expect(queryFromSelection({ kind: 'saved-search', id: 42, unread: false })).toEqual({
      view: 'all',
      savedSearchId: 42,
    });
  });
  it('carries unread on a single saved-search selection', () => {
    expect(queryFromSelection({ kind: 'saved-search', id: 42, unread: true })).toEqual({
      view: 'all',
      savedSearchId: 42,
      unread: true,
    });
  });
});
```

Create `mark-read-target.spec.ts`:

```ts
import { markReadTarget } from './mark-read-target';

describe('markReadTarget', () => {
  it('maps selection to a mark-read scope (feed=subscription id)', () => {
    expect(markReadTarget({ kind: 'all', id: null, unread: true })).toEqual({ scope: 'all' });
    expect(markReadTarget({ kind: 'tag', id: 3, unread: true })).toEqual({ scope: 'tag', id: 3 });
    expect(markReadTarget({ kind: 'subscription', id: 7, unread: true })).toEqual({
      scope: 'feed',
      id: 7,
    });
    expect(markReadTarget({ kind: 'favorites', id: null, unread: false })).toBeNull();
  });

  // For you marks its picks read through its own endpoint — no scope, no id:
  // the list is the reader's own ranked feed (#710).
  it('maps the for-you selection to its own scope', () => {
    expect(markReadTarget({ kind: 'for-you', id: null, unread: false })).toEqual({
      scope: 'for-you',
    });
  });

  it('maps a search selection to a search scope, term verbatim', () => {
    expect(markReadTarget({ kind: 'search', id: null, unread: false, term: 'climate' })).toEqual({
      scope: 'search',
      term: 'climate',
    });
    // The trailing space is the whole-word-match signal and must reach the backend.
    expect(markReadTarget({ kind: 'search', id: null, unread: false, term: 'climate ' })).toEqual({
      scope: 'search',
      term: 'climate ',
    });
    expect(markReadTarget({ kind: 'search', id: null, unread: false, term: '' })).toBeNull();
  });
  it('marks a network read by its name', () => {
    expect(
      markReadTarget({ kind: 'network', id: null, unread: false, network: 'mastodon' }),
    ).toEqual({
      scope: 'network',
      network: 'mastodon',
    });
    expect(markReadTarget({ kind: 'network', id: null, unread: false })).toBeNull();
  });
  it('marks a single saved search read by its id', () => {
    expect(markReadTarget({ kind: 'saved-search', id: 42, unread: false })).toEqual({
      scope: 'saved-search',
      id: 42,
    });
    expect(markReadTarget({ kind: 'saved-search', id: null, unread: false })).toBeNull();
  });
});
```

In `query.spec.ts`, remove `markReadTarget,` and `queryFromSelection,` from the `from './query'` import list and add below it (the other `describe` blocks still use both):

```ts
import { queryFromSelection } from './entry-query';
import { markReadTarget } from './mark-read-target';
```

- [ ] **Step 7: Run the moved specs and the pin**

Run: `docker compose exec -T frontend npx jest src/app/reader/query`
Expected: PASS, same test count as before plus none lost (`query.spec.ts` + `entry-query.spec.ts` + `mark-read-target.spec.ts` together hold what `query.spec.ts` held).

- [ ] **Step 8: Gates**

From `frontend/`: `npx prettier --write` on every file touched in this task, then `npm run check`, then `npm run build`. Expected: green.

- [ ] **Step 9: Commit**

```bash
git add frontend/src/app/reader
git commit -m "refactor(#2107): move queryFromSelection and markReadTarget out of query.ts"
```

---

### Task 3: Route every generic reader through three accessors

**Files:**
- Modify: `frontend/src/app/reader/query/query.ts`, `frontend/src/app/reader/query/query.spec.ts`
- Modify: `frontend/src/app/reader/shell/reader-route-state.service.ts:9,89-93`
- Modify: `frontend/src/app/reader/shell/reader-shell.component.ts:46,208-211`, `reader-shell.component.html:7,319`, `reader-shell.component.spec.ts:46,4075,4228`
- Modify: `frontend/src/app/reader/shell/list-heading.service.ts:9,237-239`
- Modify: `frontend/src/app/reader/shell/mark-read-actions.service.ts:48`
- Modify: `frontend/src/app/reader/shell/sidebar/sidebar.component.ts:46,157,235-242`, `sidebar.component.html:19,252`
- Modify: `frontend/src/app/reader/shell/sidebar/sidebar-social-section.component.ts:66-73`
- Modify: `frontend/src/app/reader/list/list-header/list-header.component.ts`, `list-header.component.html:26,153,156,161,166`
- Modify: `frontend/src/app/reader/list/list-empty-state/list-empty-state.component.ts`, `list-empty-state.component.html:9,12`
- Modify: `frontend/src/app/reader/list/entry-list/entry-list.component.ts:31,189`
- Modify: `frontend/src/app/reader/list/list-preferences.service.spec.ts`

**Interfaces:**
- Consumes: `SelectionKind` (Task 2).
- Produces, in `query.ts`: `isUnreadOnly(selection: Selection): boolean`, `searchTermOf(selection: Selection): string`, `trendingWindowOf(selection: Selection): TrendingWindow | null`. Task 4 keeps the names and signatures and drops their transitional `??` guards.

Every edit in this task compiles against the old interface **and** the union of Task 4, so Task 4 does not touch these sites again (except where Task 4 says so).

- [ ] **Step 1: Write the failing tests**

In `query.spec.ts`, add `isUnreadOnly,`, `searchTermOf,` and `trendingWindowOf,` to the `from './query'` import list, and append:

```ts
describe('the selection readers (#2107)', () => {
  it('reads the unread filter only where the list offers it', () => {
    expect(isUnreadOnly({ kind: 'tag', id: 3, unread: true })).toBe(true);
    expect(isUnreadOnly({ kind: 'tag', id: 3, unread: false })).toBe(false);
    expect(isUnreadOnly({ kind: 'favorites', id: null, unread: false })).toBe(false);
  });

  it('reads the search term with its whole-word space, empty outside a search', () => {
    expect(searchTermOf({ kind: 'search', id: null, unread: false, term: 'punk ' })).toBe('punk ');
    expect(searchTermOf({ kind: 'all', id: null, unread: false })).toBe('');
  });

  it("reads Trending's window, null on any other list", () => {
    expect(
      trendingWindowOf({ kind: 'trending', id: null, unread: false, window: 'week' }),
    ).toBe('week');
    expect(trendingWindowOf({ kind: 'all', id: null, unread: false })).toBeNull();
  });
});
```

In the same file replace the four direct `.unread` reads:

```ts
    expect(selectionFromParams(pm({ unread: '1' }), ON).selection.unread).toBe(false);
    expect(selectionFromParams(pm({ view: 'for-you', unread: '1' }), ON).selection.unread).toBe(
      false,
    );
```
→
```ts
    expect(isUnreadOnly(selectionFromParams(pm({ unread: '1' }), ON).selection)).toBe(false);
    expect(
      isUnreadOnly(selectionFromParams(pm({ view: 'for-you', unread: '1' }), ON).selection),
    ).toBe(false);
```

and, in `the combined saved-searches view` and `selectionFromRoute`, each `expect(selection.unread).toBe(false);` → `expect(isUnreadOnly(selection)).toBe(false);` (two sites).

- [ ] **Step 2: Run to verify it fails**

Run: `docker compose exec -T frontend npx jest src/app/reader/query/query.spec.ts`
Expected: FAIL, TypeScript reports `'"./query"' has no exported member 'isUnreadOnly'` (and the other two).

- [ ] **Step 3: Add the readers to `query.ts`**

`TrendingWindow` is already imported. Insert after `withUnreadPreference`:

```ts
/** Whether the list shows only unread posts; never true for a list without the switch. */
export function isUnreadOnly(selection: Selection): boolean {
  return hasUnreadFilter(selection) && selection.unread;
}

/** The search on screen, trailing whole-word space included; empty outside a search. */
export function searchTermOf(selection: Selection): string {
  return selection.kind === 'search' ? (selection.term ?? '') : '';
}

/** The window Trending shows, or null on every other list. */
export function trendingWindowOf(selection: Selection): TrendingWindow | null {
  return selection.kind === 'trending' ? (selection.window ?? null) : null;
}
```

(The `?? ''` and `?? null` are transitional; Task 4 deletes them.)

- [ ] **Step 4: Run to verify it passes**

Run: `docker compose exec -T frontend npx jest src/app/reader/query/query.spec.ts`
Expected: PASS.

- [ ] **Step 5: Migrate the shell**

`reader-route-state.service.ts` line 9: `import { isDirectSearch, sameSelection } from '../query/query';` →

```ts
import { isDirectSearch, sameSelection, trendingWindowOf } from '../query/query';
```

and the last effect in the constructor:

```ts
    effect(() => {
      const window = this.selection().window;
      if (window === undefined) return;
      untracked(() => this.trendingWindow.set(window));
    });
```
→
```ts
    effect(() => {
      const window = trendingWindowOf(this.selection());
      if (window === null) return;
      untracked(() => this.trendingWindow.set(window));
    });
```

`reader-shell.component.ts` line 46: `import { isDirectSearch } from '../query/query';` →

```ts
import { isDirectSearch, searchTermOf, trendingWindowOf } from '../query/query';
```

and lines 208–211:

```ts
  readonly activeTagId = computed(() => {
    const selection = this.selection();
    return selection.kind === 'tag' ? (selection.id ?? null) : null;
  });
```
→
```ts
  readonly activeTagId = computed(() => {
    const selection = this.selection();
    return selection.kind === 'tag' ? selection.id : null;
  });
  readonly searchTerm = computed(() => searchTermOf(this.selection()));
  readonly trendingWindow = computed(() => trendingWindowOf(this.selection()));
```

`reader-shell.component.html` line 7: `[searchTerm]="selection().term ?? ''"` → `[searchTerm]="searchTerm()"`; line 319: `@if (selection().window; as window) {` → `@if (trendingWindow(); as window) {`.

`reader-shell.component.spec.ts` line 46: `import { Selection } from '../query/query';` → `import { Selection, isUnreadOnly } from '../query/query';`; lines 4075 and 4228: `expect(fixture.componentInstance.selection().unread).toBe(true);` → `expect(isUnreadOnly(fixture.componentInstance.selection())).toBe(true);` (and the same with `false` at 4228).

`list-heading.service.ts` line 9: `import { Selection, visibleSearchTerm } from '../query/query';` → `import { Selection, searchTermOf, visibleSearchTerm } from '../query/query';`, and lines 237–239:

```ts
  private readonly searchTerm = computed(() =>
    visibleSearchTerm(this.routeState.selection().term ?? ''),
  );
```
→
```ts
  private readonly searchTerm = computed(() =>
    visibleSearchTerm(searchTermOf(this.routeState.selection())),
  );
```

`mark-read-actions.service.ts`: add `import { isUnreadOnly } from '../query/query';` after the two imports from Task 2, and in `markAboveReadNow`:

```ts
    const hideLocally = this.routeState.selection().unread
```
→
```ts
    const hideLocally = isUnreadOnly(this.routeState.selection())
```

- [ ] **Step 6: Migrate the sidebar**

`sidebar.component.ts` line 46: `import { Selection, selectionQueryParams } from '../../query/query';` → `import { Selection, searchTermOf, selectionQueryParams } from '../../query/query';`

Line 157:

```ts
  protected readonly activeGroup = computed(() => this.selection().group ?? null);
```
→
```ts
  protected readonly activeGroup = computed(() => {
    const selection = this.selection();
    return selection.kind === 'group' ? selection.group : null;
  });
  protected readonly searchTerm = computed(() => searchTermOf(this.selection()));
  protected readonly activeTagId = computed(() => {
    const selection = this.selection();
    return selection.kind === 'tag' ? selection.id : null;
  });
```

`activeTagPath` (lines 235–242):

```ts
  private readonly activeTagPath = computed(
    () => {
      const selection = this.selection();
      if (selection.kind !== 'tag' || selection.id === null) return [];
      return [selection.id, ...this.tagsStore.hierarchy().ancestorIdsOf(selection.id)];
    },
    { equal: sameIds },
  );
```
→
```ts
  private readonly activeTagPath = computed(
    () => {
      const tagId = this.activeTagId();
      if (tagId === null) return [];
      return [tagId, ...this.tagsStore.hierarchy().ancestorIdsOf(tagId)];
    },
    { equal: sameIds },
  );
```

`sidebar.component.html` line 19: `[term]="selection().term ?? ''"` → `[term]="searchTerm()"`; line 252: `[active]="selection().kind === 'tag' && selection().id === node.tag.id"` → `[active]="activeTagId() === node.tag.id"`.

`sidebar-social-section.component.ts` lines 66–73:

```ts
  private readonly onThisNetwork = computed(() => {
    const selection = this.selection();
    return selection.kind === 'network' && selection.network === this.section().network;
  });
  protected readonly active = computed(() => this.onThisNetwork() && !this.selection().feedKind);
  protected readonly activeFeedKind = computed(() =>
    this.onThisNetwork() ? this.selection().feedKind : undefined,
  );
```
→
```ts
  private readonly selectionOnThisNetwork = computed(() => {
    const selection = this.selection();
    return selection.kind === 'network' && selection.network === this.section().network
      ? selection
      : null;
  });
  protected readonly active = computed(() => {
    const onThisNetwork = this.selectionOnThisNetwork();
    return onThisNetwork !== null && !onThisNetwork.feedKind;
  });
  protected readonly activeFeedKind = computed(() => this.selectionOnThisNetwork()?.feedKind);
```

- [ ] **Step 7: Migrate the list**

`list-header.component.ts` import block (lines 19–26) →

```ts
import {
  Selection,
  SelectionKind,
  canScopedRefresh,
  hasListOrder,
  hasUnreadFilter,
  isSingleStreamView,
  isUnreadOnly,
  listOrderOf,
} from '../../query/query';
```

`FIXED_VIEW_ICON` (lines 31–44) →

```ts
/** The heading icon for each list, matching its sidebar row's glyph so the list a
 *  reader lands in reads as the row they clicked (#411); null where the heading
 *  already leads with a tag glyph, favicon or network logo. */
const FIXED_VIEW_ICON: Record<SelectionKind, string | null> = {
  all: 'inbox',
  tag: null,
  subscription: null,
  network: null,
  group: null,
  favorites: 'star',
  kept: 'bookmark',
  shared: 'share',
  viewed: 'history',
  'for-you': 'auto_awesome',
  trending: 'trending_up',
  'saved-searches': 'saved_search',
  'saved-search': null,
  search: 'search',
};
```

After `readonly hasUnreadFilter = computed(...)` add:

```ts
  protected readonly unreadOnly = computed(() => isUnreadOnly(this.selection()));

  protected readonly titleNetwork = computed(() => {
    const selection = this.selection();
    return selection.kind === 'network' ? selection.network : null;
  });
```

and `titleIcon`:

```ts
  protected readonly titleIcon = computed(() => FIXED_VIEW_ICON[this.selection().kind] ?? null);
```
→
```ts
  protected readonly titleIcon = computed(() => FIXED_VIEW_ICON[this.selection().kind]);
```

`list-header.component.html`: line 26 `@if (selection().network; as network) {` → `@if (titleNetwork(); as network) {`; lines 153, 156, 161, 166: every `selection().unread` → `unreadOnly()` (four occurrences: `[attr.aria-checked]="unreadOnly()"`, `(click)="unreadOnlyChange.emit(!unreadOnly())"`, `[fill]="unreadOnly()"`, `(unreadOnly() ? 'reader.unread' : 'reader.all') | transloco`).

`list-empty-state.component.ts` line 7: `import { Selection, visibleSearchTerm } from '../../query/query';` →

```ts
import {
  Selection,
  isUnreadOnly,
  searchTermOf,
  trendingWindowOf,
  visibleSearchTerm,
} from '../../query/query';
```

and the two computeds:

```ts
  protected readonly displayedSearchTerm = computed(() =>
    visibleSearchTerm(this.selection().term ?? ''),
  );

  protected readonly trendingRule = computed(() =>
    this.selection().window === 'week' ? 'reader.trendingEmptyWeek' : 'reader.trendingEmptyDay',
  );
```
→
```ts
  protected readonly displayedSearchTerm = computed(() =>
    visibleSearchTerm(searchTermOf(this.selection())),
  );

  protected readonly trendingRule = computed(() =>
    trendingWindowOf(this.selection()) === 'week'
      ? 'reader.trendingEmptyWeek'
      : 'reader.trendingEmptyDay',
  );

  protected readonly unreadOnly = computed(() => isUnreadOnly(this.selection()));
```

`list-empty-state.component.html` lines 9 and 12: `selection().unread` → `unreadOnly()` (two occurrences).

`entry-list.component.ts` line 31: `import { Selection, canScopedRefresh, offersMarkAboveRead } from '../../query/query';` →

```ts
import { Selection, canScopedRefresh, offersMarkAboveRead, searchTermOf } from '../../query/query';
```

and line 189:

```ts
    return matched.length > 0 ? matched : searchWords(this.selection().term ?? '');
```
→
```ts
    return matched.length > 0 ? matched : searchWords(searchTermOf(this.selection()));
```

`list-preferences.service.spec.ts`: add `import { isUnreadOnly } from '../query/query';` and rewrite the four `.unread` reads:

```ts
      expect(TestBed.inject(ListPreferences).appliedTo(search).unread).toBe(false);
```
→ `expect(isUnreadOnly(TestBed.inject(ListPreferences).appliedTo(search))).toBe(false);`

```ts
      expect(preferences.appliedTo(search).unread).toBe(true);
```
→ `expect(isUnreadOnly(preferences.appliedTo(search))).toBe(true);`

```ts
      expect(preferences.appliedTo({ kind: 'all', id: null, unread: false }).unread).toBe(false);
```
→ `expect(isUnreadOnly(preferences.appliedTo({ kind: 'all', id: null, unread: false }))).toBe(false);`

```ts
      expect(preferences.appliedTo(search).unread).toBe(false);
```
→ `expect(isUnreadOnly(preferences.appliedTo(search))).toBe(false);`

- [ ] **Step 8: Run the touched specs**

Run: `docker compose exec -T frontend npx jest src/app/reader/query src/app/reader/shell src/app/reader/list`
Expected: PASS.

- [ ] **Step 9: Gates**

From `frontend/`: `npx prettier --write` on every touched file, `npm run check`, `npm run build`. Expected: green. If `max-lines` fires on `reader-shell.component.ts` or `sidebar.component.ts`, stop and report the count; do not compress code to dodge it.

- [ ] **Step 10: Commit**

```bash
git add frontend/src/app/reader
git commit -m "refactor(#2107): read the unread filter, search term and Trending window through query accessors"
```

---

### Task 4: Make Selection a discriminated union

**Files:**
- Modify: `frontend/src/app/reader/query/query.ts` (full replacement below)
- Modify: `frontend/src/app/reader/query/entry-query.ts`, `mark-read-target.ts` (full replacements below)
- Modify: `frontend/src/app/reader/query/reader-matcher.ts:32-42`
- Modify: `frontend/src/app/reader/shell/list-heading.service.ts`, `refresh-actions.service.ts`, `saved-search-toggle.service.ts:38`
- Modify: `frontend/src/app/reader/list/list-header/list-header.component.ts:119-136`
- Modify: `frontend/src/app/reader/scroll/list-scroll-memory.ts`, `list-scroll-memory.spec.ts`
- Modify specs: `query.spec.ts`, `entry-query.spec.ts`, `mark-read-target.spec.ts`, and the fixture files in Step 9

**Interfaces:**
- Consumes: `isUnreadOnly`, `searchTermOf`, `trendingWindowOf` (Task 3).
- Produces, in `query.ts`: `type Selection` (union), `SelectionKind`, `SelectionOf<K>`, `FilteredSelection`, `BySelectionKind<R>`, `bySelectionKind(table, selection)`, `identityKey(selection): string`. `networkKey` is deleted (folded into `identityKey`).

- [ ] **Step 1: Write the failing tests**

Append to `query.spec.ts` (and add `identityKey,` to the `from './query'` import list):

```ts
describe('identityKey (#2107)', () => {
  it('names what tells two lists of one kind apart, and nothing else', () => {
    expect(identityKey({ kind: 'all', unread: true })).toBe('');
    expect(identityKey({ kind: 'favorites' })).toBe('');
    expect(identityKey({ kind: 'tag', id: 3, unread: true })).toBe('3');
    expect(identityKey({ kind: 'saved-search', id: 7, unread: false })).toBe('7');
    expect(identityKey({ kind: 'network', unread: true, network: 'bluesky' })).toBe('bluesky');
    expect(
      identityKey({ kind: 'network', unread: true, network: 'bluesky', feedKind: 'profile' }),
    ).toBe('bluesky:profile');
    expect(identityKey({ kind: 'group', unread: true, group: 'tags' })).toBe('tags');
    expect(identityKey({ kind: 'trending', window: 'week' })).toBe('week');
    expect(identityKey({ kind: 'search', unread: false, term: 'punk ' })).toBe('punk ');
  });

  it('lets a list without an order read as newest first in a comparison', () => {
    expect(
      sameSelection({ kind: 'favorites' }, withListOrder({ kind: 'favorites' }, 'newest')),
    ).toBe(true);
    expect(sameSelection({ kind: 'favorites' }, { kind: 'kept' })).toBe(false);
  });
});
```

- [ ] **Step 2: Run to verify it fails**

Run: `docker compose exec -T frontend npx jest src/app/reader/query/query.spec.ts`
Expected: FAIL to compile, `has no exported member 'identityKey'` and `Property 'id' is missing` for the narrowed literals.

- [ ] **Step 3: Replace `query.ts`**

Full new content of `frontend/src/app/reader/query/query.ts`:

```ts
import { ParamMap, Params, convertToParamMap } from '@angular/router';
import { ListOrder } from '../models';
import { TrendingWindow } from '../trending/trending.models';
import { FeedGroupName, isFeedGroupName } from '../../core/feed-group';
import { entryIdFromParam } from './slug';
import { isSearchableTerm, normalizeSearchInput, phraseWithin } from './search-term';
import { isTrendingWindow } from '../trending/trending-window';
import {
  NetworkFeedKind,
  SocialNetworkName,
  isNetworkFeedKind,
  isSocialNetworkName,
} from '../../core/social-network';

/** A list that takes the unread switch and the newest/oldest order. */
interface FilteredList {
  unread: boolean;
  /** Absent means newest first. */
  order?: ListOrder;
}

/** A list of entry states: already a filter, so it takes only the order. */
interface StateList {
  order?: ListOrder;
}

/** What each kind of list carries beside its kind. */
interface SelectionFields {
  all: FilteredList;
  tag: FilteredList & { id: number };
  subscription: FilteredList & { id: number };
  network: FilteredList & { network: SocialNetworkName; feedKind?: NetworkFeedKind };
  group: FilteredList & { group: FeedGroupName };
  favorites: StateList;
  kept: StateList;
  shared: StateList;
  viewed: StateList;
  'for-you': { unread: boolean };
  trending: { window: TrendingWindow };
  'saved-searches': FilteredList;
  'saved-search': FilteredList & { id: number };
  search: FilteredList & { term: string };
}

export type SelectionKind = keyof SelectionFields;
export type SelectionOf<K extends SelectionKind> = { kind: K } & SelectionFields[K];
export type Selection = { [K in SelectionKind]: SelectionOf<K> }[SelectionKind];
export type FilteredSelection = Extract<Selection, { unread: boolean }>;
type OrderedSelection = Exclude<Selection, { kind: 'for-you' | 'trending' }>;

/** One arm per kind, each handed the selection already narrowed to that kind. */
export type BySelectionKind<R> = { [K in SelectionKind]: (selection: SelectionOf<K>) => R };

export function bySelectionKind<R>(table: BySelectionKind<R>, selection: Selection): R {
  return armFor(table, selection.kind)(selection);
}

// The selection's own kind picked the arm, so it fits; TypeScript cannot correlate the two (TS #30581).
function armFor<K extends SelectionKind, R>(
  table: BySelectionKind<R>,
  kind: K,
): (selection: SelectionOf<K>) => R {
  return table[kind];
}

/** Which feeds a refresh covers: all the user's due feeds, one feed, a tag's
 *  feeds, a network's feeds, a sidebar section's feeds, or the never-fetched ones. */
export type RefreshScope =
  | { kind: 'all' }
  | { kind: 'feed'; feedId: number }
  | { kind: 'tag'; tagId: number }
  | { kind: 'network'; network: SocialNetworkName; feedKind?: NetworkFeedKind }
  | { kind: 'group'; group: FeedGroupName }
  | { kind: 'neverFetched' };

/** Whether two selections name the same list. Selections are rebuilt from the
 *  route on every navigation, so they are never reference-equal. */
export function sameSelection(left: Selection, right: Selection): boolean {
  return (
    left.kind === right.kind &&
    identityKey(left) === identityKey(right) &&
    isUnreadOnly(left) === isUnreadOnly(right) &&
    listOrderOf(left) === listOrderOf(right)
  );
}

/** What tells two lists of one kind apart: the id, term, network, group or window the kind carries. */
export function identityKey(selection: Selection): string {
  return bySelectionKind(IDENTITY_KEY, selection);
}

const IDENTITY_KEY: BySelectionKind<string> = {
  all: () => '',
  tag: (selection) => String(selection.id),
  subscription: (selection) => String(selection.id),
  network: (selection) =>
    selection.feedKind ? `${selection.network}:${selection.feedKind}` : selection.network,
  group: (selection) => selection.group,
  favorites: () => '',
  kept: () => '',
  shared: () => '',
  viewed: () => '',
  'for-you': () => '',
  trending: (selection) => selection.window,
  'saved-searches': () => '',
  'saved-search': (selection) => String(selection.id),
  search: (selection) => selection.term,
};

export function isDirectSearch(selection: Selection): boolean {
  return selection.kind === 'search';
}

/** `Selection.term` with the trailing whole-word-match space stripped for
 *  display. The space must reach the server (`EntryQuery.q`) and stay in
 *  `Selection.term` (part of identity, see `sameSelection`) — this is only
 *  for human-readable strings like the list title. */
export function visibleSearchTerm(term: string): string {
  // A phrase shows as its bare inner text — the wrapping quotes are the mode
  // signal, surfaced by the "Phrase" pill instead, exactly as the trailing
  // whole-word space is dropped here and shown as its own pill (#702).
  return phraseWithin(term) ?? term.trimEnd();
}

/** Whether the list offers the "All posts / only unread" switch. Favorites,
 *  kept, shared, viewed and Trending carry no unread flag: they do not. */
export function hasUnreadFilter(selection: Selection): selection is FilteredSelection {
  return 'unread' in selection;
}

export function withUnreadPreference(selection: Selection, unreadOnly: boolean): Selection {
  return hasUnreadFilter(selection) ? { ...selection, unread: unreadOnly } : selection;
}

/** Whether the list shows only unread posts; never true for a list without the switch. */
export function isUnreadOnly(selection: Selection): boolean {
  return hasUnreadFilter(selection) && selection.unread;
}

/** The search on screen, trailing whole-word space included; empty outside a search. */
export function searchTermOf(selection: Selection): string {
  return selection.kind === 'search' ? selection.term : '';
}

/** The window Trending shows, or null on every other list. */
export function trendingWindowOf(selection: Selection): TrendingWindow | null {
  return selection.kind === 'trending' ? selection.window : null;
}

/** Whether the list offers the newest/oldest-first toggle; the ranked for-you
 *  and Trending lists have no date order to reverse. */
export function hasListOrder(selection: Selection): selection is OrderedSelection {
  return selection.kind !== 'for-you' && selection.kind !== 'trending';
}

export function listOrderOf(selection: Selection): ListOrder {
  return hasListOrder(selection) ? (selection.order ?? 'newest') : 'newest';
}

/** Applies an order to a selection parsed from the URL, which never carries one. */
export function withListOrder(selection: Selection, order: ListOrder): Selection {
  return hasListOrder(selection) && order === 'oldest' ? { ...selection, order } : selection;
}

/** Trending is a picture of the news, not an inbox: nothing in it is marked read in bulk (#2017). */
export function offersMarkAboveRead(selection: Selection): boolean {
  return selection.kind !== 'trending';
}

/** Where a list's order is remembered: per feed, tag, saved search and view, and
 *  once for every direct search, whose terms are throwaway. */
export function listOrderKey(selection: Selection): string | null {
  if (!hasListOrder(selection)) return null;
  if (selection.kind === 'search') return 'search';
  const identity = identityKey(selection);
  return identity === '' ? selection.kind : `${selection.kind}:${identity}`;
}

/** Whether the current selection supports a scoped refresh — the cross-feed
 *  saved views (favorites/kept) don't map to any feed scope, so they can't. */
export function canScopedRefresh(selection: Selection): boolean {
  return (
    selection.kind === 'all' ||
    selection.kind === 'tag' ||
    selection.kind === 'network' ||
    selection.kind === 'group' ||
    selection.kind === 'subscription'
  );
}

/** A view that shows one logical stream, not an aggregation of feeds: a single
 *  subscription, or a ranked list (for you, Trending). Carries a "last refreshed" label and
 *  never collapses same-source runs into a group widget (would hide/reorder entries). */
export function isSingleStreamView(selection: Selection): boolean {
  return (
    selection.kind === 'subscription' ||
    selection.kind === 'for-you' ||
    selection.kind === 'trending'
  );
}

/** Every query parameter that names which list is on screen. A navigation that
 *  changes the list must clear all of them — `selectionFromParams` gives `q`
 *  priority, so a leftover `q` the caller forgot to null strands the user (#408). */
const SELECTION_PARAM_NAMES = [
  'view',
  'tag',
  'network',
  'feedKind',
  'group',
  'subscription',
  'entry',
  'link',
  'q',
  'window',
] as const;

type SelectionParamName = (typeof SELECTION_PARAM_NAMES)[number];

/** The only way `selectionFromParams` may pull a selection-identity value
 *  out of the URL. Its parameter type is `SelectionParamName`, so this is
 *  where the compile error in the comment above actually fires. */
function selectionParam(paramMap: ParamMap, name: SelectionParamName): string | null {
  return paramMap.get(name);
}

type SelectionParamValue = string | number | null;
export type SelectionParams = Record<SelectionParamName, SelectionParamValue>;

/** Results handed out by `selectionQueryParams`, keyed by the argument that
 *  produced them. Most callers are `[queryParams]` template bindings (one per
 *  sidebar feed/tag/pill); Angular caches a literal object in a binding but not
 *  a function call's result, so without this each change-detection pass
 *  allocated a fresh object and `RouterLink` rebuilt its href every scroll
 *  frame. Arguments are drawn from a bounded set, so caching on the argument
 *  returns a stable reference and the link work happens once. */
const selectionParamsCache = new Map<string, SelectionParams>();

/** Build a `[queryParams]` object that selects exactly one list: the given
 *  params are set, every other vocabulary name is nulled — a new vocabulary
 *  entry only needs adding here, not at every call site. The returned object is
 *  shared between equal-argument callers and must be treated read-only. */
export function selectionQueryParams(set: Partial<SelectionParams>): SelectionParams {
  const key = JSON.stringify(SELECTION_PARAM_NAMES.map((name) => set[name] ?? null));
  const cached = selectionParamsCache.get(key);
  if (cached !== undefined) return cached;

  const cleared = Object.fromEntries(
    SELECTION_PARAM_NAMES.map((name) => [name, null]),
  ) as SelectionParams;
  const params = { ...cleared, ...set };
  // A search term is unbounded — one distinct key per term the user tries —
  // and that call site is a single navigation rather than a template binding,
  // so it gains nothing from the cache and must not be allowed to grow it.
  if (set.q == null) selectionParamsCache.set(key, params);

  return params;
}

/** What the account brings to reading a URL: whether it offers Trending (D39), and the window a Trending link that
 *  names none opens (D40). */
export interface ViewContext {
  trending: boolean;
  trendingWindow: TrendingWindow;
}

/** What a URL names, and how the URL must change to say the same; null when it already does. */
export interface ParsedRoute {
  selection: Selection;
  entryId: number | null;
  urlCorrection: Params | null;
}

type ParsedView = Omit<ParsedRoute, 'entryId'>;

export function selectionFromParams(paramMap: ParamMap, context: ViewContext): ParsedRoute {
  // The entry param is an id or an id-prefixed slug ("514-some-title").
  const entryId = entryIdFromParam(selectionParam(paramMap, 'entry'));

  // Not a plain trim(): a trailing space is the whole-word-match signal (#408
  // follow-up) and must survive into `Selection.term`, so only MEANINGLESS
  // whitespace — leading, and collapsed runs between terms — is removed here.
  const term = normalizeSearchInput(selectionParam(paramMap, 'q') ?? '');
  if (isSearchableTerm(term)) {
    // A `?q=` search is its own view over every subscription, so a tag or feed
    // parameter left in the URL by hand is ignored rather than combined.
    const selection: Selection = { kind: 'search', unread: false, term };
    return { selection, entryId, urlCorrection: null };
  }

  return { ...viewFromParams(paramMap, context), entryId };
}

/** The views of entry states: no feed, no unread switch. */
const STATE_VIEWS = ['favorites', 'kept', 'shared', 'viewed'] as const;

/** The views that name no feed but take the unread switch. */
const FILTERED_VIEWS = ['for-you', 'saved-searches'] as const;

function isViewIn<View extends string>(views: readonly View[], view: string | null): view is View {
  return (views as readonly (string | null)[]).includes(view);
}

function viewFromParams(paramMap: ParamMap, context: ViewContext): ParsedView {
  const view = selectionParam(paramMap, 'view');
  if (view === 'trending') return trendingFromParams(paramMap, context);
  if (isViewIn(STATE_VIEWS, view)) return { selection: { kind: view }, urlCorrection: null };
  if (isViewIn(FILTERED_VIEWS, view)) {
    return { selection: { kind: view, unread: false }, urlCorrection: null };
  }
  return { selection: feedScopedSelection(paramMap), urlCorrection: null };
}

/** Switched off, Trending opens All items, as an unknown view does, and leaves the URL. A URL naming no window
 *  (or an unknown one) opens the account's window and gains it. */
function trendingFromParams(paramMap: ParamMap, context: ViewContext): ParsedView {
  if (!context.trending) {
    return {
      selection: feedScopedSelection(paramMap),
      urlCorrection: { view: null, window: null },
    };
  }
  const named = selectionParam(paramMap, 'window');
  const window = isTrendingWindow(named) ? named : context.trendingWindow;
  return {
    selection: { kind: 'trending', window },
    urlCorrection: named === window ? null : { window },
  };
}

/** The lists drawn from feeds: one subscription, a tag, a network's feeds, a feed group, or all of them. */
function feedScopedSelection(paramMap: ParamMap): Selection {
  const subscription = posInt(selectionParam(paramMap, 'subscription'));
  if (subscription != null) return { kind: 'subscription', id: subscription, unread: false };
  const tag = posInt(selectionParam(paramMap, 'tag'));
  if (tag != null) return { kind: 'tag', id: tag, unread: false };
  const network = selectionParam(paramMap, 'network');
  if (isSocialNetworkName(network))
    return networkSelection(network, selectionParam(paramMap, 'feedKind'));
  const group = selectionParam(paramMap, 'group');
  if (isFeedGroupName(group)) return { kind: 'group', unread: false, group };
  return { kind: 'all', unread: false };
}

function networkSelection(network: SocialNetworkName, feedKind: string | null): Selection {
  const selection: SelectionOf<'network'> = { kind: 'network', unread: false, network };
  return isNetworkFeedKind(feedKind) ? { ...selection, feedKind } : selection;
}

/** The list a URL names with any search set aside: the list a search is layered
 *  over, and the one that clearing the search returns to. `selectionFromParams`
 *  gives a searchable `q` priority, so the covered list is invisible in the
 *  resulting selection even though the URL still carries it (#542). */
export function listSelectionFrom(params: Params, context: ViewContext): Selection {
  return selectionFromParams(convertToParamMap({ ...params, q: null }), context).selection;
}

function posInt(value: string | null): number | null {
  if (value == null) return null;
  const parsed = Number(value);
  return Number.isInteger(parsed) && parsed > 0 ? parsed : null;
}
```

What this deletes, against the issue: the `Selection` interface and its five "Only X carries one" comments, the field-by-field `sameSelection`, `networkKey`, `NAMED_VIEWS`/`isNamedView` (split into `STATE_VIEWS`/`FILTERED_VIEWS`, since the two groups no longer share a shape), and every `id: null`.

- [ ] **Step 4: Replace `entry-query.ts`**

```ts
import { EntryQuery } from '../models';
import { BySelectionKind, FilteredSelection, Selection, bySelectionKind, listOrderOf } from './query';

export function queryFromSelection(selection: Selection): EntryQuery {
  const query = bySelectionKind(VIEW_QUERY, selection);
  return listOrderOf(selection) === 'oldest' ? { ...query, order: 'oldest' } : query;
}

const VIEW_QUERY: BySelectionKind<EntryQuery> = {
  favorites: () => ({ view: 'favorites' }),
  kept: () => ({ view: 'kept' }),
  shared: () => ({ view: 'shared' }),
  viewed: () => ({ view: 'viewed' }),
  // The one list whose unread filter is not a view of its own: the ranked
  // feed IS the view, so the filter travels beside it (#710).
  'for-you': (selection) => unreadAsFlag({ view: 'for-you' }, selection),
  trending: (selection) => ({ view: 'trending', window: selection.window }),
  tag: (selection) => ({ view: unreadAsView(selection), tag: selection.id }),
  network: (selection) => ({
    view: unreadAsView(selection),
    network: selection.network,
    feedKind: selection.feedKind,
  }),
  group: (selection) => ({ view: unreadAsView(selection), group: selection.group }),
  subscription: (selection) => ({ view: unreadAsView(selection), subscription: selection.id }),
  all: (selection) => ({ view: unreadAsView(selection) }),
  'saved-searches': (selection) => unreadAsFlag({ view: 'saved-searches' }, selection),
  // A single saved search reads the membership table by id, not a live
  // query, so it carries the id and takes the unread refinement like any list.
  'saved-search': (selection) =>
    unreadAsFlag({ view: 'all', savedSearchId: selection.id }, selection),
  search: (selection) => unreadAsFlag({ view: 'all', q: selection.term }, selection),
};

function unreadAsView(selection: FilteredSelection): EntryQuery['view'] {
  return selection.unread ? 'unread' : 'all';
}

function unreadAsFlag(query: EntryQuery, selection: FilteredSelection): EntryQuery {
  return selection.unread ? { ...query, unread: true } : query;
}
```

`withUnread()`/`listView()` become `unreadAsFlag()`/`unreadAsView()`, typed on `FilteredSelection`: the null part they carried (`?? undefined` beside them) is gone, but the wire still has two ways of saying "only unread" (a view, or a flag beside a view), used by five and four arms, so each keeps one named home rather than nine inline ternaries.

- [ ] **Step 5: Replace `mark-read-target.ts`**

```ts
import { MarkReadScope } from '../models';
import { FeedGroupName } from '../../core/feed-group';
import { NetworkFeedKind, SocialNetworkName } from '../../core/social-network';
import { BySelectionKind, Selection, bySelectionKind } from './query';

/** What "Mark all read" applies to for a selection, or null where the action
 *  doesn't apply. A discriminated union, not a bag with optional fields: id and
 *  term never coexist, and a bag would make every consumer re-assert the case. */
export type MarkReadTarget =
  | { scope: Extract<MarkReadScope, 'all'> }
  | { scope: Extract<MarkReadScope, 'feed' | 'tag'>; id: number }
  | {
      scope: Extract<MarkReadScope, 'network'>;
      network: SocialNetworkName;
      feedKind?: NetworkFeedKind;
    }
  | { scope: Extract<MarkReadScope, 'group'>; group: FeedGroupName }
  | { scope: 'search'; term: string }
  | { scope: 'for-you' }
  | { scope: 'saved-searches' }
  | { scope: 'saved-search'; id: number };

/** The targets the mark-read endpoint itself takes, sent as its body. */
export type ScopedMarkReadTarget = Extract<MarkReadTarget, { scope: MarkReadScope }>;

export function markReadTarget(selection: Selection): MarkReadTarget | null {
  return bySelectionKind(MARK_READ_TARGET, selection);
}

const MARK_READ_TARGET: BySelectionKind<MarkReadTarget | null> = {
  all: () => ({ scope: 'all' }),
  tag: (selection) => ({ scope: 'tag', id: selection.id }),
  subscription: (selection) => ({ scope: 'feed', id: selection.id }),
  network: (selection) => ({
    scope: 'network',
    network: selection.network,
    feedKind: selection.feedKind,
  }),
  group: (selection) => ({ scope: 'group', group: selection.group }),
  search: (selection) => ({ scope: 'search', term: selection.term }),
  // No id and no term: the list is the reader's own ranked feed, so the
  // endpoint needs nothing beyond who is asking (#710).
  'for-you': () => ({ scope: 'for-you' }),
  'saved-searches': () => ({ scope: 'saved-searches' }),
  'saved-search': (selection) => ({ scope: 'saved-search', id: selection.id }),
  favorites: () => null,
  kept: () => null,
  shared: () => null,
  viewed: () => null,
  trending: () => null,
};
```

- [ ] **Step 6: Narrow the route parse in `reader-matcher.ts`**

```ts
  if (savedSearch === 'all' || id === null) {
    return {
      selection: { kind: 'saved-searches', id: null, unread: false },
      entryId,
      urlCorrection: null,
    };
  }
  return { selection: { kind: 'saved-search', id, unread: false }, entryId, urlCorrection: null };
```
→
```ts
  if (savedSearch === 'all' || id === null) {
    return { selection: { kind: 'saved-searches', unread: false }, entryId, urlCorrection: null };
  }
  return { selection: { kind: 'saved-search', id, unread: false }, entryId, urlCorrection: null };
```

This is the one place a missing saved-search id becomes "no single saved search"; `feedScopedSelection` is the same for feed ids.

- [ ] **Step 7: Narrow the consumers**

`list-heading.service.ts`:

Import line `import { Selection, searchTermOf, visibleSearchTerm } from '../query/query';` →

```ts
import {
  BySelectionKind,
  FilteredSelection,
  SelectionOf,
  bySelectionKind,
  searchTermOf,
  visibleSearchTerm,
} from '../query/query';
```

`selectedTagNode`:

```ts
    if (selection.kind !== 'tag' || selection.id === null) return null;
```
→
```ts
    if (selection.kind !== 'tag') return null;
```

Delete `selectedNetworkSection` and `selectedNetworkCounts` (lines 80–90) entirely.

`titleByKind` (lines 110–135) →

```ts
  private readonly titleByKind: BySelectionKind<string> = {
    favorites: () => this.i18n.translate('reader.favorites'),
    kept: () => this.i18n.translate('reader.kept'),
    shared: () => this.i18n.translate('reader.shared'),
    viewed: () => this.i18n.translate('reader.viewed'),
    'for-you': () => this.i18n.translate('reader.forYou'),
    trending: () => this.i18n.translate('reader.trending'),
    'saved-searches': () => this.i18n.translate('reader.savedSearches'),
    'saved-search': () =>
      this.activeSavedSearch()?.term ?? this.i18n.translate('reader.savedSearches'),
    all: () => this.i18n.translate('reader.allItems'),
    tag: () => this.selectedTag()?.name ?? this.i18n.translate('reader.tagFallback'),
    network: (selection) => this.networkTitle(selection),
    group: (selection) => this.i18n.translate(GROUP_TITLE_KEY[selection.group]),
    search: () => `${this.searchTitlePrefix()} ${this.searchTitleBody()}`,
    subscription: () => {
      const subscription = this.selectedSubscription();
      return subscription
        ? this.feedTitle(subscription)
        : this.i18n.translate('reader.feedFallback');
    },
  };
```

`networkTitle` signature: `private networkTitle(selection: Selection): string {` → `private networkTitle(selection: SelectionOf<'network'>): string {` (body unchanged).

Add, directly after `sectionOf`:

```ts
  private networkCounts(
    selection: SelectionOf<'network'>,
  ): Pick<SocialSection, 'unreadCount' | 'entryCount'> {
    const section = this.sectionOf(selection.network);
    if (section === null) return { unreadCount: 0, entryCount: 0 };
    return selection.feedKind ? section.groups[selection.feedKind] : section;
  }
```

`countByKind` declaration and its `network` and `group` arms:

```ts
  private readonly countByKind: Record<Selection['kind'], (selection: Selection) => TitleCount> = {
```
→
```ts
  private readonly countByKind: BySelectionKind<TitleCount> = {
```

```ts
    network: (selection) => {
      const counts = this.selectedNetworkCounts();
      return bySwitch(selection, counts.unreadCount, counts.entryCount);
    },
    group: (selection) => {
      const counts = selection.group ? this.subscriptions.feedGroupCounts()[selection.group] : null;
      return bySwitch(selection, counts?.unreadCount ?? 0, counts?.entryCount ?? 0);
    },
```
→
```ts
    network: (selection) => {
      const counts = this.networkCounts(selection);
      return bySwitch(selection, counts.unreadCount, counts.entryCount);
    },
    group: (selection) => {
      const counts = this.subscriptions.feedGroupCounts()[selection.group];
      return bySwitch(selection, counts.unreadCount, counts.entryCount);
    },
```

`title` and `titleCount`:

```ts
    return this.titleByKind[this.routeState.selection().kind]();
```
→
```ts
    return bySelectionKind(this.titleByKind, this.routeState.selection());
```

```ts
  readonly titleCount = computed<TitleCount>(() => {
    const selection = this.routeState.selection();
    return this.countByKind[selection.kind](selection);
  });
```
→
```ts
  readonly titleCount = computed<TitleCount>(() =>
    bySelectionKind(this.countByKind, this.routeState.selection()),
  );
```

`bySwitch` at the bottom:

```ts
function bySwitch(selection: Selection, unreadCount: number, allCount: number): TitleCount {
  return selection.unread ? unread(unreadCount) : items(allCount);
}
```
→
```ts
function bySwitch(selection: FilteredSelection, unreadCount: number, allCount: number): TitleCount {
  return selection.unread ? unread(unreadCount) : items(allCount);
}
```

`refresh-actions.service.ts`: the `refreshScope` switch:

```ts
      case 'tag':
        return selection.id != null ? { kind: 'tag', tagId: selection.id } : null;
      case 'network':
      case 'group':
        return this.sectionScope(selection);
```
→
```ts
      case 'tag':
        return { kind: 'tag', tagId: selection.id };
      case 'network':
        return { kind: 'network', network: selection.network, feedKind: selection.feedKind };
      case 'group':
        return { kind: 'group', group: selection.group };
```

Delete `private sectionScope(selection: Selection): RefreshScope | null { … }` (lines 74–78) and drop `Selection,` from the `../query/query` import (no other use in the file).

`saved-search-toggle.service.ts` line 38: `const raw = selection.term ?? '';` → `const raw = selection.term;`

`list-header.component.ts` `showWholeWordBadge` and `showPhraseBadge`:

```ts
  protected readonly showWholeWordBadge = computed(() => {
    const selection = this.selection();
    const term = selection.term ?? '';
    // A phrase overrides whole-word when both signals are present (#702), so a
    // phrase query shows only the phrase pill, never both.
    return selection.kind === 'search' && isWholeWordTerm(term) && !isPhraseTerm(term);
  });
```
→
```ts
  protected readonly showWholeWordBadge = computed(() => {
    const selection = this.selection();
    // A phrase overrides whole-word when both signals are present (#702), so a
    // phrase query shows only the phrase pill, never both.
    return (
      selection.kind === 'search' &&
      isWholeWordTerm(selection.term) &&
      !isPhraseTerm(selection.term)
    );
  });
```

```ts
    return selection.kind === 'search' && isPhraseTerm(selection.term ?? '');
```
→
```ts
    return selection.kind === 'search' && isPhraseTerm(selection.term);
```

`list-scroll-memory.ts` import and `scrollKey`:

```ts
import { Selection, listOrderOf, networkKey } from '../query/query';

/**
 * Storage key for a selection's scroll offset. Distinguishes feed / tag / view,
 * unread-vs-all, and the list order so every list remembers its own place independently.
 */
export function scrollKey(selection: Selection): string {
  return `feed-reader:list-scroll:${selection.kind}:${selection.id ?? ''}:${selection.unread ? 'u' : 'a'}:${selection.term ?? ''}:${networkKey(selection)}:${selection.group ?? ''}:${listOrderOf(selection)}`;
}
```
→
```ts
import { Selection, identityKey, isUnreadOnly, listOrderOf } from '../query/query';

/**
 * Storage key for a selection's scroll offset. Distinguishes the kind and its identity,
 * unread-vs-all, and the list order so every list remembers its own place independently.
 */
export function scrollKey(selection: Selection): string {
  return `feed-reader:list-scroll:${selection.kind}:${identityKey(selection)}:${isUnreadOnly(selection) ? 'u' : 'a'}:${listOrderOf(selection)}`;
}
```

The key now also separates Trending's day and week windows (the old key had no window); that is the one deliberate behaviour change, tab-scoped, and the spec in Step 8 pins it.

- [ ] **Step 8: Replace `list-scroll-memory.spec.ts`**

Full new content:

```ts
import { convertToParamMap } from '@angular/router';
import { ListScrollMemory, scrollKey } from './list-scroll-memory';
import { Selection, selectionFromParams, withListOrder } from '../query/query';

const ALL_UNREAD: Selection = { kind: 'all', unread: true };
const tag = (id: number): Selection => ({ kind: 'tag', id, unread: true });

describe('scrollKey', () => {
  it('is distinct per kind, id and unread flag', () => {
    const keys = new Set([
      scrollKey(ALL_UNREAD),
      scrollKey({ kind: 'all', unread: false }),
      scrollKey(tag(3)),
      scrollKey(tag(4)),
      scrollKey({ kind: 'subscription', id: 3, unread: true }),
    ]);
    expect(keys.size).toBe(5);
  });

  it('is stable for the same selection', () => {
    expect(scrollKey(tag(3))).toBe(scrollKey(tag(3)));
  });

  it('is distinct per search term', () => {
    const angular = scrollKey({ kind: 'search', unread: true, term: 'angular' });
    const react = scrollKey({ kind: 'search', unread: true, term: 'react' });
    expect(angular).not.toBe(react);
  });

  it('is distinct per list order', () => {
    expect(scrollKey(tag(3))).not.toBe(scrollKey(withListOrder(tag(3), 'oldest')));
  });

  it('is distinct per network and per network feed kind', () => {
    const keys = new Set([
      scrollKey({ kind: 'network', unread: true, network: 'bluesky' }),
      scrollKey({ kind: 'network', unread: true, network: 'bluesky', feedKind: 'profile' }),
      scrollKey({ kind: 'network', unread: true, network: 'mastodon' }),
    ]);
    expect(keys.size).toBe(3);
  });

  it('keeps the two feed groups apart', () => {
    expect(scrollKey({ kind: 'group', unread: true, group: 'tags' })).not.toBe(
      scrollKey({ kind: 'group', unread: true, group: 'feeds' }),
    );
  });

  it("keeps Trending's two windows apart", () => {
    expect(scrollKey({ kind: 'trending', window: 'day' })).not.toBe(
      scrollKey({ kind: 'trending', window: 'week' }),
    );
  });
});

describe('ListScrollMemory', () => {
  let mem: ListScrollMemory;

  beforeEach(() => {
    sessionStorage.clear();
    mem = new ListScrollMemory();
  });

  it('round-trips an offset per selection and survives a fresh instance (a page reload)', () => {
    mem.save(ALL_UNREAD, 640);
    // A brand-new instance stands in for the app rebooting after an iOS/Brave
    // resume-reload — sessionStorage persists across it, an in-memory Map would not.
    expect(new ListScrollMemory().read(ALL_UNREAD)).toBe(640);
  });

  it('reads 0 for a selection it has never seen', () => {
    expect(mem.read(tag(9))).toBe(0);
  });

  it('keeps saved-search and direct-search positions separate through a reload', () => {
    const saved: Selection = { kind: 'saved-search', id: 7, unread: false };
    const direct = selectionFromParams(convertToParamMap({ q: 'climate' }), {
      trending: true,
      trendingWindow: 'day',
    }).selection;
    mem.save(saved, 640);
    mem.save(direct, 120);

    const restored = new ListScrollMemory();
    expect(restored.read(saved)).toBe(640);
    expect(restored.read(direct)).toBe(120);
  });

  it("does not leak one selection's offset to another", () => {
    mem.save(tag(3), 500);
    expect(mem.read(tag(4))).toBe(0);
  });

  it('rounds and clamps on save; treats garbage and non-positive values as 0', () => {
    mem.save(ALL_UNREAD, 12.7);
    expect(mem.read(ALL_UNREAD)).toBe(13);

    mem.save(tag(1), -5);
    expect(mem.read(tag(1))).toBe(0);

    sessionStorage.setItem(scrollKey(tag(2)), 'nope');
    expect(mem.read(tag(2))).toBe(0);
  });

  it('forgets one list without touching another', () => {
    mem.save(tag(3), 500);
    mem.save(tag(4), 700);

    mem.forget(tag(3));

    expect(mem.read(tag(3))).toBe(0);
    expect(mem.read(tag(4))).toBe(700);
  });

  it('round-trips an article scroll offset per page key, surviving a fresh instance', () => {
    mem.saveArticle('entry:514', 1200);
    expect(new ListScrollMemory().readArticle('entry:514')).toBe(1200);
    expect(mem.readArticle('entry:999')).toBe(0);
  });

  it('keeps article and list offsets in separate keys', () => {
    const subscription: Selection = { kind: 'subscription', id: 5, unread: true };
    mem.save(subscription, 300);
    mem.saveArticle('entry:5', 900);
    expect(mem.read(subscription)).toBe(300);
    expect(mem.readArticle('entry:5')).toBe(900);
  });

  it('never throws when storage access is blocked (private mode / quota)', () => {
    const setItem = jest.spyOn(Storage.prototype, 'setItem').mockImplementation(() => {
      throw new Error('blocked');
    });
    const getItem = jest.spyOn(Storage.prototype, 'getItem').mockImplementation(() => {
      throw new Error('blocked');
    });
    const removeItem = jest.spyOn(Storage.prototype, 'removeItem').mockImplementation(() => {
      throw new Error('blocked');
    });
    expect(() => mem.save(ALL_UNREAD, 100)).not.toThrow();
    expect(mem.read(ALL_UNREAD)).toBe(0);
    expect(() => mem.forget(ALL_UNREAD)).not.toThrow();
    setItem.mockRestore();
    getItem.mockRestore();
    removeItem.mockRestore();
  });
});
```

- [ ] **Step 9: Narrow the spec fixtures**

Every `Selection` literal in the specs below loses `id: null`, and the five kinds without an unread switch lose `unread`. Run from `frontend/` (BSD `sed`, as on macOS):

```bash
SPECS="src/app/reader/query/query.spec.ts
src/app/reader/query/entry-query.spec.ts
src/app/reader/query/mark-read-target.spec.ts
src/app/reader/list/entry-list/entry-list.component.spec.ts
src/app/reader/list/list-order.service.spec.ts
src/app/reader/list/list-preferences.service.spec.ts
src/app/reader/scroll/list-scroll-reset.spec.ts
src/app/reader/shell/mark-read-actions.service.spec.ts
src/app/reader/shell/reader-shell.component.spec.ts
src/app/reader/shell/list-heading.service.spec.ts
src/app/reader/shell/sidebar/sidebar-social-section.component.spec.ts
src/app/reader/shell/sidebar/sidebar.component.spec.ts"
sed -i '' -E \
  -e "s/(kind: '[a-z-]+'), id: null, /\1, /g" \
  -e '/^[[:space:]]*id: null,$/d' \
  -e "s/(kind: '(favorites|kept|shared|viewed|trending)'), unread: (true|false)/\1/g" \
  $SPECS
```

Every standalone `id: null,` line in those files belongs to a `Selection` literal (checked: `query.spec.ts` ×11, `entry-list.component.spec.ts` ×1, `list-heading.service.spec.ts` ×1, `sidebar-social-section.component.spec.ts` ×2). `social-import-dialog.component.spec.ts`, which has `id: null` on social collections, is deliberately not in the list.

Then make these hand edits, which the sed cannot see (a multi-line state or Trending literal, or a missing required field):

`query.spec.ts`, `reads favorites/kept`, `reads view=shared into the shared selection`, and `listSelectionFrom … reads a plain list unchanged` — each now reads `toEqual({ kind: '<view>', unread: false, })` across lines; make them:

```ts
    expect(selectionFromParams(pm({ view: 'favorites' }), ON).selection).toEqual({
      kind: 'favorites',
    });
```
```ts
    expect(selectionFromParams(pm({ view: 'shared' }), ON).selection).toEqual({
      kind: 'shared',
    });
```
```ts
    expect(listSelectionFrom({ view: 'favorites' }, ON)).toEqual({
      kind: 'favorites',
    });
```

`query.spec.ts`, `ignores an unknown feed kind`:

```ts
    expect(
      selectionFromParams(params({ network: 'mastodon', feedKind: 'home' }), ON).selection.feedKind,
    ).toBeUndefined();
```
→
```ts
    expect(
      selectionFromParams(params({ network: 'mastodon', feedKind: 'home' }), ON).selection,
    ).toEqual({ kind: 'network', unread: false, network: 'mastodon' });
```

`entry-list.component.spec.ts` around line 2849, the `trending` helper:

```ts
    const trending = (window: 'day' | 'week' = 'day') => ({
      kind: 'trending',
      unread: false,
      window,
    });
```
→ delete the `unread: false,` line.

`list-heading.service.spec.ts` line 318:

```ts
          useValue: { selection: signal<Selection>({ kind: 'trending' }) },
```
→
```ts
          useValue: { selection: signal<Selection>({ kind: 'trending', window: 'day' }) },
```

`mark-read-target.spec.ts`: delete the three assertions on states the type no longer allows (a search with an empty term, a network without a network, a saved search without an id):

```ts
    expect(markReadTarget({ kind: 'search', unread: false, term: '' })).toBeNull();
```
```ts
    expect(markReadTarget({ kind: 'network', unread: false })).toBeNull();
```
```ts
    expect(markReadTarget({ kind: 'saved-search', unread: false })).toBeNull();
```

and add, to the first `it`, the views that have no mark-all-read:

```ts
    for (const kind of ['favorites', 'kept', 'shared', 'viewed'] as const) {
      expect(markReadTarget({ kind })).toBeNull();
    }
```

(replacing the existing single `expect(markReadTarget({ kind: 'favorites' })).toBeNull();`).

Now run `npm run typecheck:spec` from `frontend/` and fix any remaining error the same way: a literal whose kind carries no `unread` loses it; a literal missing a field its kind requires gains a realistic value. `git diff --stat` should show only the files above.

- [ ] **Step 10: Run the reader suite**

Run: `docker compose exec -T frontend npx jest src/app/reader`
Expected: PASS, including `reader-url.spec.ts` **unchanged since Task 2** (`git diff HEAD -- frontend/src/app/reader/query/reader-url.spec.ts` is empty).

- [ ] **Step 11: Confirm the deletions**

Run from `frontend/`:

```bash
grep -rn "idScoped\|withUnread\b\|listView\|networkKey\|Only .* carries one\|sectionScope\|selectedNetworkCounts" src/app/reader
grep -rnE "selection(\(\))?\.(id|term|network|feedKind|group|window)\b ?\?\?" src/app/reader
```

Expected: no output from either.

- [ ] **Step 12: Gates**

From `frontend/`: `npx prettier --write` on every touched file, `npm run check`, `npm run build`. Expected: green.

- [ ] **Step 13: Commit**

```bash
git add frontend/src/app/reader
git commit -m "refactor(#2107): make Selection a discriminated union with per-member lookup tables"
```

---

### Task 5: Gates

**Files:** none changed unless a gate fails.

- [ ] **Step 1: Static checks and the native suite**

From `frontend/`: `npm run check`
Expected: ESLint, Prettier, Stylelint, the three `tsc` projects and Jest all green.

- [ ] **Step 2: The suite in the Docker frontend container**

Only after Step 1 has finished (never two Jest runs at once), from the repo root:
`docker compose exec -T frontend npm test`
Expected: green.

- [ ] **Step 3: Production build**

From `frontend/`: `npm run build`
Expected: success, no template type errors.

- [ ] **Step 4: Confirm the pins held**

```bash
git log --oneline -- frontend/src/app/reader/query/reader-url.spec.ts
```

Expected: exactly two commits, Task 1 (created it) and Task 2 (moved the `queryFromSelection` import). No later commit touched its expectations.

- [ ] **Step 5: Fix and commit only if a gate failed**

Fix the cause, re-run the failing gate, and commit as `refactor(#2107): <what was fixed>`.

---

## Self-review

- Issue coverage: union with exact per-member fields (Task 4 Step 3); `viewQuery`/`VIEW_QUERY`, `markReadTarget`/`MARK_READ_TARGET`, `titleByKind`, `countByKind` typed per member through `BySelectionKind` (Steps 4, 5, 7); `FIXED_VIEW_ICON` exhaustive (Task 3 Step 7); `sameSelection` compares kind first, then the member's identity, unread and order (Step 3); the route parse is the one place a missing id becomes another kind (Step 6 + `feedScopedSelection`); `EntryQuery` untouched; `idScoped()`, the `?? undefined` guards and the "only X carries one" comments deleted (Step 11 checks); `withUnread()`/`listView()` replaced by narrowly typed helpers (see the decision under Step 4).
- Route URLs: pinned in Task 1 through the real serializer and matcher, checked unchanged in Task 4 Step 10 and Task 5 Step 4.
- Type names used across tasks: `SelectionKind` (Task 2, redefined in Task 4 with the same name and members), `isUnreadOnly`/`searchTermOf`/`trendingWindowOf` (Task 3, same signatures in Task 4), `queryFromSelection` (`entry-query.ts`), `markReadTarget`/`MarkReadTarget`/`ScopedMarkReadTarget` (`mark-read-target.ts`), `FilteredSelection`/`SelectionOf`/`BySelectionKind`/`bySelectionKind`/`identityKey` (Task 4).
