# 5b Core Reader Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Turn the 5a placeholder shell into a working feed reader — sidebar navigation tree, entry list with infinite scroll and content-derived preview images, a shared article reader, read/favorite/keep, mark-all-read, subscribe-by-URL, and a live refresh progress loop — desktop and mobile, over the frozen 4a/4b API.

**Architecture:** Angular 20 standalone components + signals. A thin typed `ReaderApi` over the 5a `HttpClient`/`API_BASE_URL` setup; three signal stores (`SubscriptionsStore`, `EntriesStore`, `RefreshService`); presentational components (sidebar, list, row, reader, header, dialog) composed by a `ReaderShellComponent` that replaces the placeholder. Selection lives in the URL (`?view/tag/subscription/unread/entry`). A `ReadingLayoutService` (List default / Pane) mirrors `ThemeService`; `@angular/cdk` provides the add-feed dialog and the wide-screen breakpoint.

**Tech Stack:** Angular 20.3, TypeScript strict, RxJS, `@angular/cdk` (new), Jest + jest-preset-angular (jsdom), ESLint + Prettier + Stylelint, Playwright (integration smoke). Design tokens from 5a `theme/`; Material Symbols icons.

**Design reference:** `docs/superpowers/specs/2026-07-22-05b-reader-core-design.md`. It carries the verified contract shapes; this plan reproduces the exact ones each task needs.

**Conventions (match 5a):**
- Services `@Injectable({ providedIn: 'root' })`, `inject()` for DI, `signal()` for state.
- Components standalone; inline `template` + `styles`; **no hex outside `theme/`** — tokens only (`var(--…)`).
- Specs: `TestBed.configureTestingModule({ imports:[X], providers:[provideHttpClient(), provideHttpClientTesting(), provideRouter([]), { provide: API_BASE_URL, useValue: 'https://api.test' }] })`; drive HTTP with `HttpTestingController.expectOne(url).flush(...)`; `localStorage.clear()` in `beforeEach`.
- Icons: `<app-icon [name]="'star'" [size]="18" />` (Material Symbols names, e.g. `inbox`, `star`, `bookmark`, `refresh`, `add`, `check`, `chevron_right`, `chevron_left`, `expand_more`, `done_all`, `open_in_new`).
- Commit after every task (Conventional Commits, `feat(frontend): …`).
- After each code change run `npm run check` (from `frontend/`) — must stay green.

**New file layout (`frontend/src/app/reader/`):**
```
reader/
  models.ts                      # DTO interfaces + EntryQuery/EntryView
  reader-api.ts                  # typed HttpClient wrapper (+ .spec)
  preview-image.ts               # first-https-image extractor (+ .spec)
  layout.service.ts              # wide-screen signal via CDK BreakpointObserver (+ .spec)
  reading-layout.service.ts      # List/Pane preference, localStorage (+ .spec)
  subscriptions.store.ts         # subscriptions + tag tree + counts (+ .spec)
  entries.store.ts               # entry list, pagination, optimistic state (+ .spec)
  refresh.service.ts             # refresh poll loop + progress signal (+ .spec)
  query.ts                       # URL params <-> EntryQuery/selection helpers (+ .spec)
  sidebar/sidebar.component.ts           (+ .spec)
  entry-row/entry-row.component.ts       (+ .spec)
  entry-list/entry-list.component.ts     (+ .spec)
  reader-view/reader-view.component.ts   (+ .spec)
  add-feed/add-feed-dialog.component.ts  (+ .spec)
  header/reader-header.component.ts      (+ .spec)
  reader-shell.component.ts              (+ .spec)   # replaces shell/shell.component.ts
```

---

## Task 1: Reader models + typed `ReaderApi`

**Files:**
- Create: `frontend/src/app/reader/models.ts`
- Create: `frontend/src/app/reader/reader-api.ts`
- Test: `frontend/src/app/reader/reader-api.spec.ts`

- [ ] **Step 1: Write `models.ts`** (no test — pure types)

```ts
// src/app/reader/models.ts
export interface TagDto {
  id: number;
  name: string;
  color: string | null;
  icon: string | null;
}

export interface SubscriptionDto {
  id: number;
  title: string;
  customTitle: string | null;
  feedUrl: string;
  siteUrl: string | null;
  status: 'active' | 'erroring' | 'gone';
  createdAt: string;
  tags: TagDto[];
  unreadCount: number;
}

export interface EntryDto {
  id: number;
  title: string;
  url: string | null;
  author: string | null;
  summary: string | null;
  contentHtml: string | null;
  publishedAt: string | null;
  createdAt: string;
  subscriptionId: number;
  source: string;
  isRead: boolean;
  isFavorite: boolean;
  isKept: boolean;
}

export interface EntriesPage {
  entries: EntryDto[];
  nextCursor: string | null;
}

export interface EntryStateDto {
  entryId: number;
  isRead: boolean;
  isFavorite: boolean;
  isKept: boolean;
  readAt: string | null;
}

export interface RefreshReport {
  status: 'busy' | 'partial' | 'completed' | 'aborted';
  total: number;
  fetched: number;
  notModified: number;
  failed: number;
  skippedForBudget: number;
  remaining: number;
  pruned: number;
}

/** A candidate feed returned by POST /subscriptions when the URL was an HTML page. */
export interface FeedCandidate {
  url: string;
  title: string;
}

/** POST /subscriptions returns either the created subscription or a candidate list. */
export type SubscribeResult = { subscription: SubscriptionDto } | { candidates: FeedCandidate[] };

export type EntryView = 'all' | 'unread' | 'favorites' | 'kept';

/** A resolved selection the entry list turns into query params. */
export interface EntryQuery {
  view: EntryView;
  subscription?: number;
  tag?: number;
}

export type MarkReadScope = 'all' | 'feed' | 'tag';

export interface EntryStatePatch {
  isRead?: boolean;
  isFavorite?: boolean;
  isKept?: boolean;
}
```

- [ ] **Step 2: Write the failing test** `reader-api.spec.ts`

```ts
import { TestBed } from '@angular/core/testing';
import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { API_BASE_URL } from '../core/api';
import { ReaderApi } from './reader-api';

describe('ReaderApi', () => {
  let api: ReaderApi;
  let ctrl: HttpTestingController;

  beforeEach(() => {
    TestBed.configureTestingModule({
      providers: [
        provideHttpClient(),
        provideHttpClientTesting(),
        { provide: API_BASE_URL, useValue: 'https://api.test' },
      ],
    });
    api = TestBed.inject(ReaderApi);
    ctrl = TestBed.inject(HttpTestingController);
  });

  afterEach(() => ctrl.verify());

  it('GETs subscriptions', () => {
    api.subscriptions().subscribe();
    const req = ctrl.expectOne('https://api.test/api/subscriptions');
    expect(req.request.method).toBe('GET');
    req.flush({ subscriptions: [] });
  });

  it('POSTs a subscribe URL', () => {
    api.subscribe('https://example.com/feed').subscribe();
    const req = ctrl.expectOne('https://api.test/api/subscriptions');
    expect(req.request.method).toBe('POST');
    expect(req.request.body).toEqual({ url: 'https://example.com/feed' });
    req.flush({ subscription: {} });
  });

  it('GETs entries with only the set filters, cursor last', () => {
    api.entries({ view: 'unread', subscription: 7 }, 'CUR').subscribe();
    const req = ctrl.expectOne(
      (r) => r.url === 'https://api.test/api/entries',
    );
    expect(req.request.params.get('view')).toBe('unread');
    expect(req.request.params.get('subscription')).toBe('7');
    expect(req.request.params.get('tag')).toBeNull();
    expect(req.request.params.get('cursor')).toBe('CUR');
    req.flush({ entries: [], nextCursor: null });
  });

  it('PATCHes entry state', () => {
    api.updateState(3, { isFavorite: true }).subscribe();
    const req = ctrl.expectOne('https://api.test/api/entries/3/state');
    expect(req.request.method).toBe('PATCH');
    expect(req.request.body).toEqual({ isFavorite: true });
    req.flush({ state: { entryId: 3, isRead: false, isFavorite: true, isKept: false, readAt: null } });
  });

  it('POSTs mark-read with scope/until/id', () => {
    api.markRead('feed', '2026-01-01T00:00:00Z', 9).subscribe();
    const req = ctrl.expectOne('https://api.test/api/entries/mark-read');
    expect(req.request.method).toBe('POST');
    expect(req.request.body).toEqual({ scope: 'feed', until: '2026-01-01T00:00:00Z', id: 9 });
    req.flush(null);
  });

  it('POSTs refresh', () => {
    api.refresh().subscribe();
    const req = ctrl.expectOne('https://api.test/api/refresh');
    expect(req.request.method).toBe('POST');
    req.flush({ status: 'completed', total: 0, fetched: 0, notModified: 0, failed: 0, skippedForBudget: 0, remaining: 0, pruned: 0 });
  });
});
```

- [ ] **Step 3: Run — expect FAIL** (`ReaderApi` undefined): `npx jest reader-api`

- [ ] **Step 4: Implement `reader-api.ts`**

```ts
// src/app/reader/reader-api.ts
import { HttpClient, HttpParams } from '@angular/common/http';
import { Injectable, inject } from '@angular/core';
import { Observable } from 'rxjs';
import { API_BASE_URL } from '../core/api';
import {
  EntriesPage,
  EntryQuery,
  EntryStatePatch,
  MarkReadScope,
  RefreshReport,
  SubscribeResult,
  SubscriptionDto,
  EntryStateDto,
} from './models';

@Injectable({ providedIn: 'root' })
export class ReaderApi {
  private readonly http = inject(HttpClient);
  private readonly base = inject(API_BASE_URL);

  subscriptions(): Observable<{ subscriptions: SubscriptionDto[] }> {
    return this.http.get<{ subscriptions: SubscriptionDto[] }>(`${this.base}/api/subscriptions`);
  }

  subscribe(url: string): Observable<SubscribeResult> {
    return this.http.post<SubscribeResult>(`${this.base}/api/subscriptions`, { url });
  }

  entries(query: EntryQuery, cursor?: string | null): Observable<EntriesPage> {
    let params = new HttpParams().set('view', query.view);
    if (query.subscription != null) params = params.set('subscription', query.subscription);
    if (query.tag != null) params = params.set('tag', query.tag);
    if (cursor) params = params.set('cursor', cursor);
    return this.http.get<EntriesPage>(`${this.base}/api/entries`, { params });
  }

  updateState(id: number, patch: EntryStatePatch): Observable<{ state: EntryStateDto }> {
    return this.http.patch<{ state: EntryStateDto }>(`${this.base}/api/entries/${id}/state`, patch);
  }

  markRead(scope: MarkReadScope, until: string, id?: number): Observable<void> {
    const body: Record<string, unknown> = { scope, until };
    if (id != null) body['id'] = id;
    return this.http.post<void>(`${this.base}/api/entries/mark-read`, body);
  }

  refresh(): Observable<RefreshReport> {
    return this.http.post<RefreshReport>(`${this.base}/api/refresh`, {});
  }
}
```

- [ ] **Step 5: Run — expect PASS**: `npx jest reader-api`
- [ ] **Step 6: `npm run check`** (from `frontend/`) — green.
- [ ] **Step 7: Commit**

```bash
git add frontend/src/app/reader/models.ts frontend/src/app/reader/reader-api.ts frontend/src/app/reader/reader-api.spec.ts
git commit -m "feat(frontend): reader models and typed ReaderApi wrapper"
```

---

## Task 2: Add `@angular/cdk` + `LayoutService` (wide-screen signal)

**Files:**
- Modify: `frontend/package.json`, `frontend/package-lock.json` (add dependency)
- Create: `frontend/src/app/reader/layout.service.ts`
- Test: `frontend/src/app/reader/layout.service.spec.ts`

- [ ] **Step 1: Add the dependency** (from `frontend/`, matching the installed Angular major, pinning npm 11 as the repo requires):

```bash
npm i -g npm@11 >/dev/null 2>&1 || true
npm i @angular/cdk@^20.3.0
```

Confirm `@angular/cdk` appears in `package.json` dependencies and `package-lock.json` updated. (Global gitignore does not hide `package-lock.json` here — it is force-tracked; verify `git status` shows it modified.)

- [ ] **Step 2: Write the failing test** `layout.service.spec.ts`

`LayoutService` exposes `isWide` (a signal, true ≥ 900px). Drive it by faking `BreakpointObserver`.

```ts
import { TestBed } from '@angular/core/testing';
import { BreakpointObserver, BreakpointState } from '@angular/cdk/layout';
import { Subject } from 'rxjs';
import { LayoutService } from './layout.service';

describe('LayoutService', () => {
  const changes = new Subject<BreakpointState>();
  const observer = { observe: () => changes.asObservable() } as unknown as BreakpointObserver;

  beforeEach(() => {
    TestBed.configureTestingModule({
      providers: [{ provide: BreakpointObserver, useValue: observer }],
    });
  });

  it('tracks the wide breakpoint', () => {
    const svc = TestBed.inject(LayoutService);
    changes.next({ matches: true, breakpoints: {} });
    expect(svc.isWide()).toBe(true);
    changes.next({ matches: false, breakpoints: {} });
    expect(svc.isWide()).toBe(false);
  });
});
```

- [ ] **Step 3: Run — expect FAIL**: `npx jest layout.service`

- [ ] **Step 4: Implement `layout.service.ts`**

```ts
// src/app/reader/layout.service.ts
import { Injectable, inject, signal } from '@angular/core';
import { BreakpointObserver } from '@angular/cdk/layout';
import { toSignal } from '@angular/core/rxjs-interop';
import { map } from 'rxjs';

/** True when the viewport is wide enough to place the reader in a side pane. */
export const WIDE_QUERY = '(min-width: 900px)';

@Injectable({ providedIn: 'root' })
export class LayoutService {
  private readonly bp = inject(BreakpointObserver);
  readonly isWide = toSignal(this.bp.observe(WIDE_QUERY).pipe(map((s) => s.matches)), {
    initialValue: typeof window !== 'undefined' ? window.matchMedia(WIDE_QUERY).matches : true,
  });
}
```

- [ ] **Step 5: Run — expect PASS**; then **`npm run check`** green (also proves the CDK install builds).
- [ ] **Step 6: Commit**

```bash
git add frontend/package.json frontend/package-lock.json frontend/src/app/reader/layout.service.ts frontend/src/app/reader/layout.service.spec.ts
git commit -m "feat(frontend): add @angular/cdk and a wide-screen LayoutService"
```

---

## Task 3: `ReadingLayoutService` (List/Pane preference)

**Files:**
- Create: `frontend/src/app/reader/reading-layout.service.ts`
- Test: `frontend/src/app/reader/reading-layout.service.spec.ts`

Mirrors `ThemeService`: a `signal` seeded from `localStorage`, `set()` persists. Default `'list'`.

- [ ] **Step 1: Write the failing test**

```ts
import { TestBed } from '@angular/core/testing';
import { ReadingLayoutService } from './reading-layout.service';

describe('ReadingLayoutService', () => {
  beforeEach(() => {
    localStorage.clear();
    TestBed.configureTestingModule({});
  });

  it('defaults to list', () => {
    expect(TestBed.inject(ReadingLayoutService).mode()).toBe('list');
  });

  it('persists and restores the choice', () => {
    TestBed.inject(ReadingLayoutService).set('pane');
    expect(localStorage.getItem('sfr.layout')).toBe('pane');
    // A fresh injector reads the saved value.
    TestBed.resetTestingModule();
    TestBed.configureTestingModule({});
    expect(TestBed.inject(ReadingLayoutService).mode()).toBe('pane');
  });

  it('ignores a garbage saved value', () => {
    localStorage.setItem('sfr.layout', 'nonsense');
    expect(TestBed.inject(ReadingLayoutService).mode()).toBe('list');
  });
});
```

- [ ] **Step 2: Run — expect FAIL**: `npx jest reading-layout`

- [ ] **Step 3: Implement**

```ts
// src/app/reader/reading-layout.service.ts
import { Injectable, signal } from '@angular/core';

export type ReadingLayout = 'list' | 'pane';
const KEY = 'sfr.layout';

@Injectable({ providedIn: 'root' })
export class ReadingLayoutService {
  readonly mode = signal<ReadingLayout>(this.readSaved());

  set(mode: ReadingLayout): void {
    localStorage.setItem(KEY, mode);
    this.mode.set(mode);
  }

  private readSaved(): ReadingLayout {
    return localStorage.getItem(KEY) === 'pane' ? 'pane' : 'list';
  }
}
```

- [ ] **Step 4: Run — expect PASS**; **`npm run check`** green.
- [ ] **Step 5: Commit**

```bash
git add frontend/src/app/reader/reading-layout.service.ts frontend/src/app/reader/reading-layout.service.spec.ts
git commit -m "feat(frontend): List/Pane reading-layout preference"
```

---

## Task 4: `preview-image.ts` (first-https-image extractor)

**Files:**
- Create: `frontend/src/app/reader/preview-image.ts`
- Test: `frontend/src/app/reader/preview-image.spec.ts`

A pure function: given `contentHtml` (fallback `summary`), return the first `<img>` `src` that is an absolute `https://` URL, else `null`. Never executes markup (uses `DOMParser`, an inert parse). Also exports `textSnippet(html)` → plain-text for the row snippet fallback.

- [ ] **Step 1: Write the failing test**

```ts
import { firstPreviewImage, textSnippet } from './preview-image';

describe('firstPreviewImage', () => {
  it('returns the first https image src', () => {
    expect(firstPreviewImage('<p>hi</p><img src="https://cdn.test/a.jpg"><img src="https://cdn.test/b.jpg">'))
      .toBe('https://cdn.test/a.jpg');
  });
  it('skips http and relative/data images', () => {
    expect(firstPreviewImage('<img src="http://x/a.png"><img src="/rel.png"><img src="data:image/png;base64,AAAA">'))
      .toBeNull();
    expect(firstPreviewImage('<img src="https://ok.test/z.png">')).toBe('https://ok.test/z.png');
  });
  it('falls back to summary when content has none', () => {
    expect(firstPreviewImage(null, '<img src="https://s.test/s.jpg">')).toBe('https://s.test/s.jpg');
  });
  it('returns null for empty or image-less html', () => {
    expect(firstPreviewImage('', '')).toBeNull();
    expect(firstPreviewImage('<p>text only</p>')).toBeNull();
  });
  it('is safe on malformed html', () => {
    expect(() => firstPreviewImage('<img src=https://x <<< broken')).not.toThrow();
  });
});

describe('textSnippet', () => {
  it('strips tags to plain text', () => {
    expect(textSnippet('<p>Hello <b>world</b></p>')).toBe('Hello world');
  });
  it('collapses whitespace and handles null', () => {
    expect(textSnippet('  a\n\n  b  ')).toBe('a b');
    expect(textSnippet(null)).toBe('');
  });
});
```

- [ ] **Step 2: Run — expect FAIL**: `npx jest preview-image`

- [ ] **Step 3: Implement**

```ts
// src/app/reader/preview-image.ts
/** Parse HTML inertly and return the first absolute https image src, or null.
 *  http/relative/data srcs are rejected: the app is https, so http images are
 *  mixed-content-blocked, and relative srcs can't be resolved without a base. */
export function firstPreviewImage(contentHtml: string | null, summary: string | null = null): string | null {
  return pickImage(contentHtml) ?? pickImage(summary);
}

function pickImage(html: string | null): string | null {
  if (!html) return null;
  const doc = new DOMParser().parseFromString(html, 'text/html');
  for (const img of Array.from(doc.querySelectorAll('img'))) {
    const src = img.getAttribute('src') ?? '';
    if (src.startsWith('https://')) return src;
  }
  return null;
}

/** Plain-text snippet from HTML, whitespace-collapsed. */
export function textSnippet(html: string | null): string {
  if (!html) return '';
  const doc = new DOMParser().parseFromString(html, 'text/html');
  return (doc.body.textContent ?? '').replace(/\s+/g, ' ').trim();
}
```

- [ ] **Step 4: Run — expect PASS**; **`npm run check`** green.
- [ ] **Step 5: Commit**

```bash
git add frontend/src/app/reader/preview-image.ts frontend/src/app/reader/preview-image.spec.ts
git commit -m "feat(frontend): content-derived preview image + text snippet helpers"
```

---

## Task 5: `query.ts` (URL params ⇄ selection ⇄ EntryQuery)

**Files:**
- Create: `frontend/src/app/reader/query.ts`
- Test: `frontend/src/app/reader/query.spec.ts`

Pure functions translating the URL (`view`/`tag`/`subscription`/`unread`/`entry`) into a `Selection`, then into an API `EntryQuery`, and into a mark-read target. Recall the contract quirk: **`scope=feed` takes a subscription id**.

- [ ] **Step 1: Write the failing test**

```ts
import { convertToParamMap } from '@angular/router';
import { markReadTarget, queryFromSelection, selectionFromParams } from './query';

const pm = (o: Record<string, string>) => convertToParamMap(o);

describe('selectionFromParams', () => {
  it('defaults to all-items, unread-only', () => {
    const { selection, entryId } = selectionFromParams(pm({}));
    expect(selection).toEqual({ kind: 'all', id: null, unread: true });
    expect(entryId).toBeNull();
  });
  it('reads unread=0 as show-all', () => {
    expect(selectionFromParams(pm({ unread: '0' })).selection.unread).toBe(false);
  });
  it('reads a subscription selection and open entry', () => {
    const { selection, entryId } = selectionFromParams(pm({ subscription: '7', entry: '42' }));
    expect(selection).toEqual({ kind: 'subscription', id: 7, unread: true });
    expect(entryId).toBe(42);
  });
  it('reads a tag selection', () => {
    expect(selectionFromParams(pm({ tag: '3' })).selection).toEqual({ kind: 'tag', id: 3, unread: true });
  });
  it('reads favorites/kept and ignores the unread toggle there', () => {
    expect(selectionFromParams(pm({ view: 'favorites', unread: '0' })).selection)
      .toEqual({ kind: 'favorites', id: null, unread: false });
    expect(selectionFromParams(pm({ view: 'kept' })).selection.kind).toBe('kept');
  });
  it('rejects non-positive/garbage ids', () => {
    expect(selectionFromParams(pm({ subscription: '0' })).selection.kind).toBe('all');
    expect(selectionFromParams(pm({ tag: 'x' })).selection.kind).toBe('all');
  });
});

describe('queryFromSelection', () => {
  it('maps all/tag/subscription through the unread toggle', () => {
    expect(queryFromSelection({ kind: 'all', id: null, unread: true })).toEqual({ view: 'unread' });
    expect(queryFromSelection({ kind: 'all', id: null, unread: false })).toEqual({ view: 'all' });
    expect(queryFromSelection({ kind: 'tag', id: 3, unread: true })).toEqual({ view: 'unread', tag: 3 });
    expect(queryFromSelection({ kind: 'subscription', id: 7, unread: false })).toEqual({ view: 'all', subscription: 7 });
  });
  it('maps curated views directly', () => {
    expect(queryFromSelection({ kind: 'favorites', id: null, unread: false })).toEqual({ view: 'favorites' });
    expect(queryFromSelection({ kind: 'kept', id: null, unread: false })).toEqual({ view: 'kept' });
  });
});

describe('markReadTarget', () => {
  it('maps selection to a mark-read scope (feed=subscription id)', () => {
    expect(markReadTarget({ kind: 'all', id: null, unread: true })).toEqual({ scope: 'all' });
    expect(markReadTarget({ kind: 'tag', id: 3, unread: true })).toEqual({ scope: 'tag', id: 3 });
    expect(markReadTarget({ kind: 'subscription', id: 7, unread: true })).toEqual({ scope: 'feed', id: 7 });
    expect(markReadTarget({ kind: 'favorites', id: null, unread: false })).toBeNull();
  });
});
```

- [ ] **Step 2: Run — expect FAIL**: `npx jest reader/query`

- [ ] **Step 3: Implement**

```ts
// src/app/reader/query.ts
import { ParamMap } from '@angular/router';
import { EntryQuery, MarkReadScope } from './models';

export interface Selection {
  kind: 'all' | 'tag' | 'subscription' | 'favorites' | 'kept';
  id: number | null;
  unread: boolean;
}

export function selectionFromParams(p: ParamMap): { selection: Selection; entryId: number | null } {
  const view = p.get('view');
  const tag = posInt(p.get('tag'));
  const subscription = posInt(p.get('subscription'));
  const unread = p.get('unread') !== '0';
  const entryId = posInt(p.get('entry'));

  let selection: Selection;
  if (view === 'favorites' || view === 'kept') {
    selection = { kind: view, id: null, unread: false };
  } else if (subscription != null) {
    selection = { kind: 'subscription', id: subscription, unread };
  } else if (tag != null) {
    selection = { kind: 'tag', id: tag, unread };
  } else {
    selection = { kind: 'all', id: null, unread };
  }
  return { selection, entryId };
}

export function queryFromSelection(s: Selection): EntryQuery {
  switch (s.kind) {
    case 'favorites':
      return { view: 'favorites' };
    case 'kept':
      return { view: 'kept' };
    case 'tag':
      return { view: s.unread ? 'unread' : 'all', tag: s.id ?? undefined };
    case 'subscription':
      return { view: s.unread ? 'unread' : 'all', subscription: s.id ?? undefined };
    case 'all':
      return { view: s.unread ? 'unread' : 'all' };
  }
}

export function markReadTarget(s: Selection): { scope: MarkReadScope; id?: number } | null {
  switch (s.kind) {
    case 'all':
      return { scope: 'all' };
    case 'tag':
      return s.id != null ? { scope: 'tag', id: s.id } : null;
    case 'subscription':
      return s.id != null ? { scope: 'feed', id: s.id } : null;
    default:
      return null;
  }
}

function posInt(v: string | null): number | null {
  if (v == null) return null;
  const n = Number(v);
  return Number.isInteger(n) && n > 0 ? n : null;
}
```

- [ ] **Step 4: Run — expect PASS**; **`npm run check`** green.
- [ ] **Step 5: Commit**

```bash
git add frontend/src/app/reader/query.ts frontend/src/app/reader/query.spec.ts
git commit -m "feat(frontend): URL selection <-> EntryQuery <-> mark-read helpers"
```

---

## Task 6: `SubscriptionsStore` (subscriptions, tag tree, unread counts)

**Files:**
- Create: `frontend/src/app/reader/subscriptions.store.ts`
- Test: `frontend/src/app/reader/subscriptions.store.spec.ts`

Exports pure derivation helpers (unit-tested directly) plus the store. Per-tag count is the **sum** over its subscriptions (overlap intended); all-items total counts each subscription once.

- [ ] **Step 1: Write the failing test**

```ts
import { TestBed } from '@angular/core/testing';
import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { API_BASE_URL } from '../core/api';
import { SubscriptionsStore, buildTagTree, sumUnread, untaggedSubs } from './subscriptions.store';
import { SubscriptionDto } from './models';

const tag = (id: number, name: string) => ({ id, name, color: null, icon: null });
const sub = (id: number, unread: number, tags = [] as ReturnType<typeof tag>[]): SubscriptionDto => ({
  id, title: `s${id}`, customTitle: null, feedUrl: `https://f/${id}`, siteUrl: null,
  status: 'active', createdAt: 'x', tags, unreadCount: unread,
});

describe('subscription derivations', () => {
  const subs = [sub(1, 3, [tag(10, 'News'), tag(20, 'Tech')]), sub(2, 6, [tag(20, 'Tech')]), sub(3, 0, [])];
  it('sums per-tag unread with overlap', () => {
    const tree = buildTagTree(subs);
    expect(tree.map((n) => [n.tag.name, n.unreadCount])).toEqual([['News', 3], ['Tech', 9]]);
    expect(tree.find((n) => n.tag.name === 'Tech')!.subscriptions.map((s) => s.id)).toEqual([1, 2]);
  });
  it('lists untagged subs and totals each sub once', () => {
    expect(untaggedSubs(subs).map((s) => s.id)).toEqual([3]);
    expect(sumUnread(subs)).toBe(9);
  });
});

describe('SubscriptionsStore', () => {
  let store: SubscriptionsStore;
  let ctrl: HttpTestingController;
  beforeEach(() => {
    TestBed.configureTestingModule({
      providers: [provideHttpClient(), provideHttpClientTesting(), { provide: API_BASE_URL, useValue: 'https://api.test' }],
    });
    store = TestBed.inject(SubscriptionsStore);
    ctrl = TestBed.inject(HttpTestingController);
  });

  it('loads and exposes derived signals', () => {
    store.load();
    ctrl.expectOne('https://api.test/api/subscriptions').flush({ subscriptions: [sub(1, 3, [tag(20, 'Tech')]), sub(2, 6, [tag(20, 'Tech')])] });
    expect(store.totalUnread()).toBe(9);
    expect(store.tagTree()[0].unreadCount).toBe(9);
    expect(store.loading()).toBe(false);
  });

  it('optimistically decrements and zeroes unread', () => {
    store.load();
    ctrl.expectOne('https://api.test/api/subscriptions').flush({ subscriptions: [sub(1, 3, [tag(20, 'Tech')]), sub(2, 6)] });
    store.decrementUnread(1);
    expect(store.subscriptions().find((s) => s.id === 1)!.unreadCount).toBe(2);
    store.decrementUnread(1, 99);
    expect(store.subscriptions().find((s) => s.id === 1)!.unreadCount).toBe(0);
    store.zeroUnread({ subscription: 2 });
    expect(store.subscriptions().find((s) => s.id === 2)!.unreadCount).toBe(0);
  });

  it('captures a problem on error', () => {
    store.load();
    ctrl.expectOne('https://api.test/api/subscriptions').flush(
      { type: 'x', title: 't', status: 500 }, { status: 500, statusText: 'err' });
    expect(store.error()?.status).toBe(500);
    expect(store.loading()).toBe(false);
  });
});
```

- [ ] **Step 2: Run — expect FAIL**: `npx jest subscriptions.store`

- [ ] **Step 3: Implement**

```ts
// src/app/reader/subscriptions.store.ts
import { HttpErrorResponse } from '@angular/common/http';
import { Injectable, computed, inject, signal } from '@angular/core';
import { Problem, parseProblem } from '../core/problem';
import { ReaderApi } from './reader-api';
import { SubscriptionDto, TagDto } from './models';

export interface TagNode {
  tag: TagDto;
  subscriptions: SubscriptionDto[];
  unreadCount: number;
}

export function buildTagTree(subs: SubscriptionDto[]): TagNode[] {
  const byId = new Map<number, TagNode>();
  for (const s of subs) {
    for (const t of s.tags) {
      let node = byId.get(t.id);
      if (!node) {
        node = { tag: t, subscriptions: [], unreadCount: 0 };
        byId.set(t.id, node);
      }
      node.subscriptions.push(s);
      node.unreadCount += s.unreadCount;
    }
  }
  return [...byId.values()].sort((a, b) => a.tag.name.localeCompare(b.tag.name));
}

export function untaggedSubs(subs: SubscriptionDto[]): SubscriptionDto[] {
  return subs.filter((s) => s.tags.length === 0);
}

export function sumUnread(subs: SubscriptionDto[]): number {
  return subs.reduce((n, s) => n + s.unreadCount, 0);
}

type ZeroTarget = 'all' | { tag: number } | { subscription: number };

@Injectable({ providedIn: 'root' })
export class SubscriptionsStore {
  private readonly api = inject(ReaderApi);

  readonly subscriptions = signal<SubscriptionDto[]>([]);
  readonly loading = signal(false);
  readonly error = signal<Problem | null>(null);

  readonly tagTree = computed(() => buildTagTree(this.subscriptions()));
  readonly untagged = computed(() => untaggedSubs(this.subscriptions()));
  readonly totalUnread = computed(() => sumUnread(this.subscriptions()));

  load(): void {
    this.loading.set(true);
    this.error.set(null);
    this.api.subscriptions().subscribe({
      next: (r) => {
        this.subscriptions.set(r.subscriptions);
        this.loading.set(false);
      },
      error: (e: HttpErrorResponse) => {
        this.error.set(parseProblem(e));
        this.loading.set(false);
      },
    });
  }

  decrementUnread(subscriptionId: number, by = 1): void {
    this.subscriptions.update((subs) =>
      subs.map((s) =>
        s.id === subscriptionId ? { ...s, unreadCount: Math.max(0, s.unreadCount - by) } : s,
      ),
    );
  }

  incrementUnread(subscriptionId: number, by = 1): void {
    this.subscriptions.update((subs) =>
      subs.map((s) => (s.id === subscriptionId ? { ...s, unreadCount: s.unreadCount + by } : s)),
    );
  }

  zeroUnread(target: ZeroTarget): void {
    this.subscriptions.update((subs) =>
      subs.map((s) => {
        if (target === 'all') return { ...s, unreadCount: 0 };
        if ('tag' in target) return s.tags.some((t) => t.id === target.tag) ? { ...s, unreadCount: 0 } : s;
        return s.id === target.subscription ? { ...s, unreadCount: 0 } : s;
      }),
    );
  }
}
```

- [ ] **Step 4: Run — expect PASS**; **`npm run check`** green.
- [ ] **Step 5: Commit**

```bash
git add frontend/src/app/reader/subscriptions.store.ts frontend/src/app/reader/subscriptions.store.spec.ts
git commit -m "feat(frontend): SubscriptionsStore with tag tree and unread counts"
```

---

## Task 7: `EntriesStore` (list, pagination, optimistic state)

**Files:**
- Create: `frontend/src/app/reader/entries.store.ts`
- Test: `frontend/src/app/reader/entries.store.spec.ts`

Holds the current page(s), the cursor, and the `loadedAt` timestamp (sent later as mark-read `until`). Optimistic `setState` with rollback. It does **not** touch `SubscriptionsStore` — callers coordinate unread counts.

- [ ] **Step 1: Write the failing test**

```ts
import { TestBed } from '@angular/core/testing';
import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { API_BASE_URL } from '../core/api';
import { EntriesStore } from './entries.store';
import { EntryDto } from './models';

const entry = (id: number, over: Partial<EntryDto> = {}): EntryDto => ({
  id, title: `e${id}`, url: null, author: null, summary: null, contentHtml: null,
  publishedAt: null, createdAt: 'x', subscriptionId: 1, source: 's',
  isRead: false, isFavorite: false, isKept: false, ...over,
});

describe('EntriesStore', () => {
  let store: EntriesStore;
  let ctrl: HttpTestingController;
  beforeEach(() => {
    TestBed.configureTestingModule({
      providers: [provideHttpClient(), provideHttpClientTesting(), { provide: API_BASE_URL, useValue: 'https://api.test' }],
    });
    store = TestBed.inject(EntriesStore);
    ctrl = TestBed.inject(HttpTestingController);
  });

  it('loads a first page and records a next cursor', () => {
    store.load({ view: 'unread' });
    ctrl.expectOne((r) => r.url === 'https://api.test/api/entries').flush({ entries: [entry(1)], nextCursor: 'C1' });
    expect(store.entries().map((e) => e.id)).toEqual([1]);
    expect(store.nextCursor()).toBe('C1');
    expect(store.loadedAt()).not.toBe('');
  });

  it('appends on loadMore and terminates on a null cursor', () => {
    store.load({ view: 'unread' });
    ctrl.expectOne((r) => r.url === 'https://api.test/api/entries').flush({ entries: [entry(1)], nextCursor: 'C1' });
    store.loadMore();
    ctrl.expectOne((r) => r.params.get('cursor') === 'C1').flush({ entries: [entry(2)], nextCursor: null });
    expect(store.entries().map((e) => e.id)).toEqual([1, 2]);
    store.loadMore();
    ctrl.expectNone((r) => r.url === 'https://api.test/api/entries'); // no cursor -> no request
  });

  it('resets when the query changes', () => {
    store.load({ view: 'unread' });
    ctrl.expectOne((r) => r.url === 'https://api.test/api/entries').flush({ entries: [entry(1)], nextCursor: 'C1' });
    store.load({ view: 'all' });
    expect(store.entries()).toEqual([]);
    ctrl.expectOne((r) => r.params.get('view') === 'all').flush({ entries: [entry(9)], nextCursor: null });
    expect(store.entries().map((e) => e.id)).toEqual([9]);
  });

  it('optimistically sets state and rolls back on error', () => {
    store.load({ view: 'all' });
    ctrl.expectOne((r) => r.url === 'https://api.test/api/entries').flush({ entries: [entry(1)], nextCursor: null });

    store.setState(1, { isFavorite: true });
    expect(store.entries()[0].isFavorite).toBe(true);
    ctrl.expectOne('https://api.test/api/entries/1/state').flush(
      { type: 'x', title: 't', status: 500 }, { status: 500, statusText: 'err' });
    expect(store.entries()[0].isFavorite).toBe(false); // rolled back
  });
});
```

- [ ] **Step 2: Run — expect FAIL**: `npx jest entries.store`

- [ ] **Step 3: Implement**

```ts
// src/app/reader/entries.store.ts
import { HttpErrorResponse } from '@angular/common/http';
import { Injectable, inject, signal } from '@angular/core';
import { Problem, parseProblem } from '../core/problem';
import { ReaderApi } from './reader-api';
import { EntryDto, EntryQuery, EntryStatePatch } from './models';

@Injectable({ providedIn: 'root' })
export class EntriesStore {
  private readonly api = inject(ReaderApi);

  readonly entries = signal<EntryDto[]>([]);
  readonly nextCursor = signal<string | null>(null);
  readonly loading = signal(false);
  readonly loadingMore = signal(false);
  readonly error = signal<Problem | null>(null);
  readonly loadedAt = signal<string>('');

  private query: EntryQuery | null = null;

  load(query: EntryQuery): void {
    this.query = query;
    this.entries.set([]);
    this.nextCursor.set(null);
    this.loading.set(true);
    this.error.set(null);
    this.loadedAt.set(new Date().toISOString());
    this.api.entries(query).subscribe({
      next: (page) => {
        this.entries.set(page.entries);
        this.nextCursor.set(page.nextCursor);
        this.loading.set(false);
      },
      error: (e: HttpErrorResponse) => {
        this.error.set(parseProblem(e));
        this.loading.set(false);
      },
    });
  }

  loadMore(): void {
    const cursor = this.nextCursor();
    if (!cursor || !this.query || this.loading() || this.loadingMore()) return;
    this.loadingMore.set(true);
    this.api.entries(this.query, cursor).subscribe({
      next: (page) => {
        this.entries.update((cur) => [...cur, ...page.entries]);
        this.nextCursor.set(page.nextCursor);
        this.loadingMore.set(false);
      },
      error: (e: HttpErrorResponse) => {
        this.error.set(parseProblem(e));
        this.loadingMore.set(false);
      },
    });
  }

  /** Optimistic patch of one entry's flags; rolls the list back if the PATCH fails. */
  setState(entryId: number, patch: EntryStatePatch): void {
    const before = this.entries();
    if (!before.some((e) => e.id === entryId)) return;
    this.entries.update((cur) => cur.map((e) => (e.id === entryId ? { ...e, ...patch } : e)));
    this.api.updateState(entryId, patch).subscribe({
      error: () => this.entries.set(before),
    });
  }
}
```

- [ ] **Step 4: Run — expect PASS**; **`npm run check`** green.
- [ ] **Step 5: Commit**

```bash
git add frontend/src/app/reader/entries.store.ts frontend/src/app/reader/entries.store.spec.ts
git commit -m "feat(frontend): EntriesStore with cursor pagination and optimistic state"
```

---

## Task 8: `RefreshService` (poll loop + progress)

**Files:**
- Create: `frontend/src/app/reader/refresh.service.ts`
- Test: `frontend/src/app/reader/refresh.service.spec.ts`

Loops `POST /api/refresh`: `partial` → immediately again; `busy` → back off then retry (bounded); `completed`/`aborted`/error → stop. Exposes `running`, `report`, `error`, and a `progress` (0..1) computed from `total`/`remaining`. `onDone` lets the caller refetch.

- [ ] **Step 1: Write the failing test** (uses Angular `fakeAsync`/`tick` for the busy backoff)

```ts
import { TestBed, fakeAsync, tick } from '@angular/core/testing';
import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { API_BASE_URL } from '../core/api';
import { RefreshService } from './refresh.service';

const report = (over: Partial<Record<string, unknown>>) => ({
  status: 'partial', total: 10, fetched: 0, notModified: 0, failed: 0, skippedForBudget: 0, remaining: 5, pruned: 0, ...over,
});

describe('RefreshService', () => {
  let svc: RefreshService;
  let ctrl: HttpTestingController;
  beforeEach(() => {
    TestBed.configureTestingModule({
      providers: [provideHttpClient(), provideHttpClientTesting(), { provide: API_BASE_URL, useValue: 'https://api.test' }],
    });
    svc = TestBed.inject(RefreshService);
    ctrl = TestBed.inject(HttpTestingController);
  });

  it('loops partial then completes and calls onDone', () => {
    const done = jest.fn();
    svc.run(done);
    ctrl.expectOne('https://api.test/api/refresh').flush(report({ status: 'partial', remaining: 5 }));
    expect(svc.running()).toBe(true);
    ctrl.expectOne('https://api.test/api/refresh').flush(report({ status: 'completed', remaining: 0, fetched: 10 }));
    expect(svc.running()).toBe(false);
    expect(svc.progress()).toBe(1);
    expect(done).toHaveBeenCalledTimes(1);
  });

  it('backs off on busy then retries', fakeAsync(() => {
    svc.run();
    ctrl.expectOne('https://api.test/api/refresh').flush(report({ status: 'busy', total: 0, remaining: 0 }));
    expect(svc.running()).toBe(true);
    tick(1500);
    ctrl.expectOne('https://api.test/api/refresh').flush(report({ status: 'completed', remaining: 0 }));
    expect(svc.running()).toBe(false);
  }));

  it('stops and records a problem on error (e.g. 429)', () => {
    svc.run();
    ctrl.expectOne('https://api.test/api/refresh').flush(
      { type: 'rate_limited', title: 't', status: 429 }, { status: 429, statusText: 'Too Many Requests' });
    expect(svc.running()).toBe(false);
    expect(svc.error()?.status).toBe(429);
  });
});
```

- [ ] **Step 2: Run — expect FAIL**: `npx jest refresh.service`

- [ ] **Step 3: Implement**

```ts
// src/app/reader/refresh.service.ts
import { HttpErrorResponse } from '@angular/common/http';
import { Injectable, computed, inject, signal } from '@angular/core';
import { Problem, parseProblem } from '../core/problem';
import { ReaderApi } from './reader-api';
import { RefreshReport } from './models';

const BUSY_BACKOFF_MS = 1500;
const MAX_BUSY_RETRIES = 5;

@Injectable({ providedIn: 'root' })
export class RefreshService {
  private readonly api = inject(ReaderApi);

  readonly running = signal(false);
  readonly report = signal<RefreshReport | null>(null);
  readonly error = signal<Problem | null>(null);

  readonly progress = computed(() => {
    const r = this.report();
    if (!r || r.total <= 0) return 0;
    return Math.min(1, Math.max(0, (r.total - r.remaining) / r.total));
  });

  run(onDone?: () => void): void {
    if (this.running()) return;
    this.running.set(true);
    this.report.set(null);
    this.error.set(null);
    this.step(0, onDone);
  }

  private step(busyRetries: number, onDone?: () => void): void {
    this.api.refresh().subscribe({
      next: (r) => {
        this.report.set(r);
        if (r.status === 'partial' && r.remaining > 0) {
          this.step(0, onDone);
        } else if (r.status === 'busy') {
          if (busyRetries >= MAX_BUSY_RETRIES) {
            this.finish(onDone);
          } else {
            setTimeout(() => this.step(busyRetries + 1, onDone), BUSY_BACKOFF_MS);
          }
        } else {
          this.finish(onDone); // completed | aborted
        }
      },
      error: (e: HttpErrorResponse) => {
        this.error.set(parseProblem(e));
        this.finish(onDone);
      },
    });
  }

  private finish(onDone?: () => void): void {
    this.running.set(false);
    onDone?.();
  }
}
```

- [ ] **Step 4: Run — expect PASS**; **`npm run check`** green.
- [ ] **Step 5: Commit**

```bash
git add frontend/src/app/reader/refresh.service.ts frontend/src/app/reader/refresh.service.spec.ts
git commit -m "feat(frontend): refresh poll loop with progress signal"
```

---

> **Presentational-component note (Tasks 9–14):** Components are standalone, use signal `input()`/`output()`, and are **token-only** (no hex — Stylelint enforces it). Templates below are complete and authoritative; **styles are structural** — the implementer completes the finish to match the design mockups in the spec (spacing, hairlines, muted palette), staying token-only. Specs are authoritative and must pass as written. Set signal inputs in specs via `fixture.componentRef.setInput('x', v)`.

## Task 9: `format.ts` + `EntryRowComponent`

**Files:**
- Create: `frontend/src/app/reader/format.ts`, `frontend/src/app/reader/format.spec.ts`
- Create: `frontend/src/app/reader/entry-row/entry-row.component.ts`
- Test: `frontend/src/app/reader/entry-row/entry-row.component.spec.ts`

- [ ] **Step 1: Failing tests**

```ts
// format.spec.ts
import { relativeTime } from './format';
describe('relativeTime', () => {
  const now = new Date('2026-07-22T12:00:00Z');
  it('formats buckets', () => {
    expect(relativeTime('2026-07-22T11:59:30Z', now)).toBe('just now');
    expect(relativeTime('2026-07-22T11:30:00Z', now)).toBe('30 min ago');
    expect(relativeTime('2026-07-22T09:00:00Z', now)).toBe('3 h ago');
    expect(relativeTime('2026-07-20T12:00:00Z', now)).toBe('2 d ago');
  });
  it('handles bad input', () => expect(relativeTime('nope', now)).toBe(''));
});
```

```ts
// entry-row.component.spec.ts
import { TestBed } from '@angular/core/testing';
import { EntryRowComponent } from './entry-row.component';
import { EntryDto } from '../models';

const entry = (over: Partial<EntryDto> = {}): EntryDto => ({
  id: 1, title: 'Hello', url: 'https://x/1', author: null, summary: '<p>Summary text</p>',
  contentHtml: '<img src="https://cdn.test/a.jpg"><p>Body</p>', publishedAt: '2026-07-22T11:00:00Z',
  createdAt: 'x', subscriptionId: 5, source: 'heise', isRead: false, isFavorite: false, isKept: false, ...over,
});

function mount(e: EntryDto) {
  const f = TestBed.createComponent(EntryRowComponent);
  f.componentRef.setInput('entry', e);
  f.detectChanges();
  return f;
}

describe('EntryRowComponent', () => {
  beforeEach(() => TestBed.configureTestingModule({ imports: [EntryRowComponent] }));

  it('renders title, source, snippet and the https thumbnail', () => {
    const el = mount(entry()).nativeElement as HTMLElement;
    expect(el.querySelector('.title')!.textContent).toContain('Hello');
    expect(el.querySelector('.meta')!.textContent).toContain('heise');
    expect(el.querySelector('.snippet')!.textContent).toContain('Summary text');
    expect(el.querySelector('img.thumb')!.getAttribute('src')).toBe('https://cdn.test/a.jpg');
  });

  it('omits the thumbnail when no https image exists', () => {
    const el = mount(entry({ contentHtml: '<p>no image</p>', summary: '<p>x</p>' })).nativeElement as HTMLElement;
    expect(el.querySelector('img.thumb')).toBeNull();
  });

  it('emits actions and open', () => {
    const f = mount(entry());
    const out = { favorite: 0, keep: 0, read: 0, open: 0 };
    f.componentInstance.favorite.subscribe(() => out.favorite++);
    f.componentInstance.keep.subscribe(() => out.keep++);
    f.componentInstance.read.subscribe(() => out.read++);
    f.componentInstance.open.subscribe(() => out.open++);
    const el = f.nativeElement as HTMLElement;
    (el.querySelector('[aria-label="Favorite"]') as HTMLButtonElement).click();
    (el.querySelector('[aria-label="Keep"]') as HTMLButtonElement).click();
    (el.querySelector('[aria-label="Toggle read"]') as HTMLButtonElement).click();
    (el.querySelector('.row') as HTMLElement).click();
    expect(out).toEqual({ favorite: 1, keep: 1, read: 1, open: 1 });
  });
});
```

- [ ] **Step 2: Run — expect FAIL**: `npx jest reader/format reader/entry-row`

- [ ] **Step 3: Implement `format.ts`**

```ts
// src/app/reader/format.ts
export function relativeTime(iso: string, now: Date = new Date()): string {
  const then = new Date(iso).getTime();
  if (Number.isNaN(then)) return '';
  const s = Math.max(0, Math.floor((now.getTime() - then) / 1000));
  if (s < 60) return 'just now';
  const m = Math.floor(s / 60);
  if (m < 60) return `${m} min ago`;
  const h = Math.floor(m / 60);
  if (h < 24) return `${h} h ago`;
  return `${Math.floor(h / 24)} d ago`;
}
```

- [ ] **Step 4: Implement `entry-row.component.ts`**

```ts
// src/app/reader/entry-row/entry-row.component.ts
import { Component, computed, effect, input, output, signal } from '@angular/core';
import { IconComponent } from '../../shared/icon/icon.component';
import { EntryDto } from '../models';
import { firstPreviewImage, textSnippet } from '../preview-image';
import { relativeTime } from '../format';

@Component({
  selector: 'app-entry-row',
  imports: [IconComponent],
  template: `
    <article class="row" [class.read]="entry().isRead" (click)="open.emit(entry())">
      <span class="dot" [class.on]="!entry().isRead" aria-hidden="true"></span>
      <div class="body">
        <h3 class="title">{{ entry().title }}</h3>
        <p class="meta">{{ entry().source }} · {{ when() }}</p>
        <p class="snippet">{{ snippet() }}</p>
        <div class="actions" (click)="$event.stopPropagation()">
          <button type="button" aria-label="Favorite" [class.on]="entry().isFavorite"
            [attr.aria-pressed]="entry().isFavorite" (click)="favorite.emit(entry())">
            <app-icon name="star" [size]="18" />
          </button>
          <button type="button" aria-label="Keep" [class.on]="entry().isKept"
            [attr.aria-pressed]="entry().isKept" (click)="keep.emit(entry())">
            <app-icon name="bookmark" [size]="18" />
          </button>
          <button type="button" aria-label="Toggle read"
            [attr.aria-pressed]="entry().isRead" (click)="read.emit(entry())">
            <app-icon [name]="entry().isRead ? 'mark_email_unread' : 'check'" [size]="18" />
          </button>
        </div>
      </div>
      @if (image() && !imgError()) {
        <img class="thumb" [src]="image()!" alt="" loading="lazy" decoding="async"
          referrerpolicy="no-referrer" (error)="imgError.set(true)" />
      }
    </article>
  `,
  styles: [
    `
      .row { display: flex; gap: var(--space-3); padding: var(--space-3) var(--space-4);
        border-bottom: 1px solid var(--border); cursor: pointer; }
      .row:hover { background: var(--surface-0); }
      .dot { width: 8px; height: 8px; border-radius: 50%; margin-top: 6px; flex: 0 0 auto;
        border: 1px solid var(--border-strong); }
      .dot.on { background: var(--accent); border-color: var(--accent); }
      .body { flex: 1; min-width: 0; display: flex; flex-direction: column; gap: var(--space-1); }
      .title { margin: 0; font-size: var(--fs-base); font-weight: 500; color: var(--text-primary); }
      .row.read .title { font-weight: 400; color: var(--text-secondary); }
      .meta { margin: 0; font-size: var(--fs-sm); color: var(--text-muted); }
      .snippet { margin: 0; font-size: var(--fs-sm); color: var(--text-secondary);
        display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden; }
      .actions { display: flex; gap: var(--space-3); }
      .actions button { background: none; border: none; color: var(--text-muted); cursor: pointer; padding: 2px; }
      .actions button.on { color: var(--accent); }
      .thumb { width: 88px; height: 66px; object-fit: cover; border-radius: var(--radius); flex: 0 0 auto; }
    `,
  ],
})
export class EntryRowComponent {
  readonly entry = input.required<EntryDto>();
  readonly favorite = output<EntryDto>();
  readonly keep = output<EntryDto>();
  readonly read = output<EntryDto>();
  readonly open = output<EntryDto>();

  readonly imgError = signal(false);
  readonly image = computed(() => firstPreviewImage(this.entry().contentHtml, this.entry().summary));
  readonly snippet = computed(() =>
    this.entry().summary ? textSnippet(this.entry().summary) : textSnippet(this.entry().contentHtml),
  );
  readonly when = computed(() => relativeTime(this.entry().publishedAt ?? this.entry().createdAt));

  // Reset the failed-image flag whenever the row is reused for a different entry.
  private readonly _resetOnEntryChange = effect(() => {
    this.entry();
    this.imgError.set(false);
  });
}
```

- [ ] **Step 5: Run — expect PASS**; **`npm run check`** green.
- [ ] **Step 6: Commit** `feat(frontend): entry row with preview image and actions`

---

## Task 10: `SidebarComponent`

**Files:**
- Create: `frontend/src/app/reader/sidebar/sidebar.component.ts`
- Test: `frontend/src/app/reader/sidebar/sidebar.component.spec.ts`

Presentational: navigation via `routerLink` + `queryParams` (the shell reacts to the URL). Highlights from the `selection` input; local `expanded` set reveals a tag's subscriptions.

- [ ] **Step 1: Failing test**

```ts
import { TestBed } from '@angular/core/testing';
import { provideRouter } from '@angular/router';
import { SidebarComponent } from './sidebar.component';
import { TagNode } from '../subscriptions.store';
import { Selection } from '../query';
import { SubscriptionDto } from '../models';

const sub = (id: number, unread = 0): SubscriptionDto => ({
  id, title: `s${id}`, customTitle: null, feedUrl: `https://f/${id}`, siteUrl: null,
  status: 'active', createdAt: 'x', tags: [], unreadCount: unread,
});

function mount(over: Partial<{ tagTree: TagNode[]; untagged: SubscriptionDto[]; totalUnread: number; selection: Selection }> = {}) {
  TestBed.configureTestingModule({ imports: [SidebarComponent], providers: [provideRouter([])] });
  const f = TestBed.createComponent(SidebarComponent);
  f.componentRef.setInput('tagTree', over.tagTree ?? []);
  f.componentRef.setInput('untagged', over.untagged ?? []);
  f.componentRef.setInput('totalUnread', over.totalUnread ?? 0);
  f.componentRef.setInput('selection', over.selection ?? { kind: 'all', id: null, unread: true });
  f.componentRef.setInput('loading', false);
  f.detectChanges();
  return f;
}

describe('SidebarComponent', () => {
  it('shows the all-items total and marks it active', () => {
    const el = mount({ totalUnread: 24 }).nativeElement as HTMLElement;
    const all = el.querySelector('.nav.all')!;
    expect(all.textContent).toContain('24');
    expect(all.classList).toContain('active');
  });

  it('renders tags with summed counts and reveals subs when expanded', () => {
    const node: TagNode = { tag: { id: 20, name: 'Tech', color: null, icon: null }, subscriptions: [sub(1, 3), sub(2, 6)], unreadCount: 9 };
    const f = mount({ tagTree: [node] });
    const el = f.nativeElement as HTMLElement;
    expect(el.querySelector('.tag')!.textContent).toContain('Tech');
    expect(el.querySelector('.tag')!.textContent).toContain('9');
    expect(el.querySelectorAll('.tag-sub').length).toBe(0);
    (el.querySelector('.tag .expand') as HTMLButtonElement).click();
    f.detectChanges();
    expect(el.querySelectorAll('.tag-sub').length).toBe(2);
  });
});
```

- [ ] **Step 2: Run — expect FAIL**: `npx jest sidebar`

- [ ] **Step 3: Implement**

```ts
// src/app/reader/sidebar/sidebar.component.ts
import { Component, input, signal } from '@angular/core';
import { RouterLink } from '@angular/router';
import { IconComponent } from '../../shared/icon/icon.component';
import { TagNode } from '../subscriptions.store';
import { Selection } from '../query';
import { SubscriptionDto } from '../models';

@Component({
  selector: 'app-sidebar',
  imports: [RouterLink, IconComponent],
  template: `
    <nav class="sidebar" aria-label="Feeds">
      <a class="nav all" [class.active]="selection().kind === 'all'"
        [routerLink]="[]" [queryParams]="{ view: null, tag: null, subscription: null, entry: null }"
        queryParamsHandling="merge">
        <app-icon name="inbox" [size]="18" /><span>All items</span>
        @if (totalUnread() > 0) { <span class="count">{{ totalUnread() }}</span> }
      </a>
      <a class="nav" [class.active]="selection().kind === 'favorites'"
        [routerLink]="[]" [queryParams]="{ view: 'favorites', tag: null, subscription: null, entry: null }"
        queryParamsHandling="merge">
        <app-icon name="star" [size]="18" /><span>Favorites</span>
      </a>
      <a class="nav" [class.active]="selection().kind === 'kept'"
        [routerLink]="[]" [queryParams]="{ view: 'kept', tag: null, subscription: null, entry: null }"
        queryParamsHandling="merge">
        <app-icon name="bookmark" [size]="18" /><span>Kept</span>
      </a>

      @if (tagTree().length) {
        <p class="label">Tags</p>
        @for (node of tagTree(); track node.tag.id) {
          <div class="tag">
            <button class="expand" type="button" [attr.aria-expanded]="expanded().has(node.tag.id)"
              [attr.aria-label]="'Toggle ' + node.tag.name" (click)="toggle(node.tag.id)">
              <app-icon [name]="expanded().has(node.tag.id) ? 'expand_more' : 'chevron_right'" [size]="18" />
            </button>
            <a class="nav grow" [class.active]="selection().kind === 'tag' && selection().id === node.tag.id"
              [routerLink]="[]" [queryParams]="{ tag: node.tag.id, view: null, subscription: null, entry: null }"
              queryParamsHandling="merge">
              <span class="dot" [style.background]="node.tag.color || 'var(--text-muted)'"></span>
              <span>{{ node.tag.name }}</span>
              @if (node.unreadCount > 0) { <span class="count">{{ node.unreadCount }}</span> }
            </a>
          </div>
          @if (expanded().has(node.tag.id)) {
            @for (s of node.subscriptions; track s.id) {
              <a class="nav tag-sub" [class.active]="selection().kind === 'subscription' && selection().id === s.id"
                [routerLink]="[]" [queryParams]="{ subscription: s.id, view: null, tag: null, entry: null }"
                queryParamsHandling="merge">
                <span>{{ s.title }}</span>
                @if (s.unreadCount > 0) { <span class="count">{{ s.unreadCount }}</span> }
              </a>
            }
          }
        }
      }

      @if (untagged().length) {
        <p class="label">Feeds</p>
        @for (s of untagged(); track s.id) {
          <a class="nav" [class.active]="selection().kind === 'subscription' && selection().id === s.id"
            [routerLink]="[]" [queryParams]="{ subscription: s.id, view: null, tag: null, entry: null }"
            queryParamsHandling="merge">
            <app-icon name="rss_feed" [size]="16" /><span>{{ s.title }}</span>
            @if (s.unreadCount > 0) { <span class="count">{{ s.unreadCount }}</span> }
          </a>
        }
      }
    </nav>
  `,
  styles: [
    `
      .sidebar { padding: var(--space-3) var(--space-2); display: flex; flex-direction: column; gap: 2px;
        overflow: auto; height: 100%; }
      .nav { display: flex; align-items: center; gap: var(--space-2); padding: var(--space-2);
        border-radius: var(--radius); color: var(--text-primary); text-decoration: none; }
      .nav:hover { background: var(--surface-0); }
      .nav.active { background: var(--accent-soft); color: var(--accent); }
      .nav .count { margin-left: auto; font-size: var(--fs-sm); color: var(--text-muted); }
      .nav.active .count { color: var(--accent); }
      .label { font-size: var(--fs-sm); text-transform: uppercase; letter-spacing: 0.04em;
        color: var(--text-muted); margin: var(--space-3) var(--space-2) var(--space-1); }
      .tag { display: flex; align-items: center; }
      .tag .expand { background: none; border: none; color: var(--text-secondary); cursor: pointer;
        padding: var(--space-2) 0 var(--space-2) var(--space-1); }
      .tag .grow { flex: 1; }
      .tag-sub { padding-left: var(--space-6); }
      .dot { width: 9px; height: 9px; border-radius: 50%; flex: 0 0 auto; }
    `,
  ],
})
export class SidebarComponent {
  readonly tagTree = input.required<TagNode[]>();
  readonly untagged = input.required<SubscriptionDto[]>();
  readonly totalUnread = input.required<number>();
  readonly selection = input.required<Selection>();
  readonly loading = input(false);

  readonly expanded = signal<Set<number>>(new Set());

  toggle(tagId: number): void {
    this.expanded.update((set) => {
      const next = new Set(set);
      next.has(tagId) ? next.delete(tagId) : next.add(tagId);
      return next;
    });
  }
}
```

- [ ] **Step 4: Run — expect PASS**; **`npm run check`** green.
- [ ] **Step 5: Commit** `feat(frontend): sidebar navigation tree with unread counts`

---

## Task 11: `EntryListComponent` (+ IntersectionObserver mock)

**Files:**
- Create: `frontend/src/app/reader/entry-list/entry-list.component.ts`
- Test: `frontend/src/app/reader/entry-list/entry-list.component.spec.ts`
- Modify: `frontend/jest-global-mocks.ts` (stub `IntersectionObserver` for jsdom)

- [ ] **Step 1: Add the IntersectionObserver stub** to `jest-global-mocks.ts` (append):

```ts
// Minimal IntersectionObserver stub — jsdom has none. Components only need it
// to construct without throwing; tests exercise the Load-more button directly.
class IntersectionObserverStub {
  observe(): void {}
  unobserve(): void {}
  disconnect(): void {}
  takeRecords(): [] { return []; }
}
(globalThis as unknown as { IntersectionObserver: unknown }).IntersectionObserver = IntersectionObserverStub;
```

- [ ] **Step 2: Failing test** `entry-list.component.spec.ts`

```ts
import { TestBed } from '@angular/core/testing';
import { provideRouter } from '@angular/router';
import { EntryListComponent } from './entry-list.component';
import { EntryDto } from '../models';

const entry = (id: number): EntryDto => ({
  id, title: `e${id}`, url: null, author: null, summary: 's', contentHtml: null,
  publishedAt: '2026-07-22T11:00:00Z', createdAt: 'x', subscriptionId: 1, source: 'src',
  isRead: false, isFavorite: false, isKept: false,
});

function mount(over: Record<string, unknown> = {}) {
  TestBed.configureTestingModule({ imports: [EntryListComponent], providers: [provideRouter([])] });
  const f = TestBed.createComponent(EntryListComponent);
  const inputs = { title: 'All items', entries: [entry(1), entry(2)], loading: false, loadingMore: false,
    error: null, hasMore: false, canMarkAllRead: true,
    selection: { kind: 'all', id: null, unread: true }, openEntryId: null, ...over };
  for (const [k, v] of Object.entries(inputs)) f.componentRef.setInput(k, v);
  f.detectChanges();
  return f;
}

describe('EntryListComponent', () => {
  it('renders a row per entry and the header title', () => {
    const el = mount().nativeElement as HTMLElement;
    expect(el.querySelector('.list-header')!.textContent).toContain('All items');
    expect(el.querySelectorAll('app-entry-row').length).toBe(2);
  });

  it('shows skeletons while loading and an empty state when empty', () => {
    expect((mount({ loading: true, entries: [] }).nativeElement as HTMLElement).querySelector('.skeleton')).not.toBeNull();
    expect((mount({ loading: false, entries: [] }).nativeElement as HTMLElement).querySelector('.empty')).not.toBeNull();
  });

  it('emits loadMore from the fallback button and markAllRead', () => {
    const f = mount({ hasMore: true });
    let more = 0, mar = 0;
    f.componentInstance.loadMore.subscribe(() => more++);
    f.componentInstance.markAllRead.subscribe(() => mar++);
    const el = f.nativeElement as HTMLElement;
    (el.querySelector('.load-more') as HTMLButtonElement).click();
    (el.querySelector('.mark-all') as HTMLButtonElement).click();
    expect([more, mar]).toEqual([1, 1]);
  });

  it('hides mark-all-read when not applicable', () => {
    const el = mount({ canMarkAllRead: false }).nativeElement as HTMLElement;
    expect(el.querySelector('.mark-all')).toBeNull();
  });
});
```

- [ ] **Step 3: Run — expect FAIL**: `npx jest entry-list`

- [ ] **Step 4: Implement** `entry-list.component.ts`

```ts
// src/app/reader/entry-list/entry-list.component.ts
import {
  AfterViewInit, Component, ElementRef, OnDestroy, computed, effect, input, output, viewChild,
} from '@angular/core';
import { RouterLink } from '@angular/router';
import { IconComponent } from '../../shared/icon/icon.component';
import { EntryRowComponent } from '../entry-row/entry-row.component';
import { EntryDto } from '../models';
import { Selection } from '../query';
import { Problem } from '../../core/problem';

@Component({
  selector: 'app-entry-list',
  imports: [RouterLink, IconComponent, EntryRowComponent],
  template: `
    <header class="list-header">
      <h2>{{ title() }}</h2>
      <div class="tools">
        @if (selection().kind === 'all' || selection().kind === 'tag' || selection().kind === 'subscription') {
          <div class="toggle" role="group" aria-label="Filter">
            <a [class.on]="selection().unread" [routerLink]="[]" [queryParams]="{ unread: null }" queryParamsHandling="merge">Unread</a>
            <a [class.on]="!selection().unread" [routerLink]="[]" [queryParams]="{ unread: '0' }" queryParamsHandling="merge">All</a>
          </div>
        }
        @if (canMarkAllRead()) {
          <button class="mark-all" type="button" (click)="markAllRead.emit()">
            <app-icon name="done_all" [size]="18" /> Mark all read
          </button>
        }
      </div>
    </header>

    @if (error()) {
      <div class="banner" role="alert">{{ error()!.detail ?? error()!.title }}</div>
    }

    @if (loading()) {
      <div class="rows">
        @for (i of [1, 2, 3, 4, 5]; track i) { <div class="skeleton"></div> }
      </div>
    } @else if (entries().length === 0) {
      <p class="empty">{{ selection().unread ? "You're all caught up." : 'Nothing here yet.' }}</p>
    } @else {
      <div class="rows">
        @for (e of entries(); track e.id) {
          <app-entry-row [entry]="e" [class.open]="openEntryId() === e.id"
            (favorite)="favorite.emit($event)" (keep)="keep.emit($event)"
            (read)="read.emit($event)" (open)="open.emit($event)" />
        }
      </div>
      @if (hasMore()) {
        <div class="foot" #sentinel>
          <button class="load-more" type="button" [disabled]="loadingMore()" (click)="loadMore.emit()">
            {{ loadingMore() ? 'Loading…' : 'Load more' }}
          </button>
        </div>
      }
    }
  `,
  styles: [
    `
      :host { display: flex; flex-direction: column; min-height: 0; height: 100%; }
      .list-header { display: flex; align-items: center; justify-content: space-between; gap: var(--space-3);
        padding: var(--space-3) var(--space-4); border-bottom: 1px solid var(--border); }
      .list-header h2 { margin: 0; font-size: var(--fs-lg); }
      .tools { display: flex; align-items: center; gap: var(--space-3); }
      .toggle { display: inline-flex; border: 1px solid var(--border-strong); border-radius: var(--radius); overflow: hidden; }
      .toggle a { padding: var(--space-1) var(--space-3); font-size: var(--fs-sm); color: var(--text-secondary);
        text-decoration: none; cursor: pointer; }
      .toggle a.on { background: var(--surface-0); color: var(--text-primary); }
      .mark-all { display: inline-flex; align-items: center; gap: var(--space-1); background: none; border: none;
        color: var(--accent); cursor: pointer; font-size: var(--fs-sm); }
      .rows { overflow: auto; }
      .empty { color: var(--text-muted); padding: var(--space-6); text-align: center; }
      .banner { margin: var(--space-3) var(--space-4); padding: var(--space-3); border-radius: var(--radius);
        background: var(--bg-danger); color: var(--danger); }
      .skeleton { height: 72px; margin: var(--space-3) var(--space-4); border-radius: var(--radius);
        background: var(--surface-0); }
      .foot { display: flex; justify-content: center; padding: var(--space-4); }
      .load-more { padding: var(--space-2) var(--space-4); border: 1px solid var(--border-strong);
        border-radius: var(--radius); background: var(--surface-1); color: var(--text-primary); cursor: pointer; }
      @media (prefers-reduced-motion: reduce) { .skeleton { animation: none; } }
    `,
  ],
})
export class EntryListComponent implements AfterViewInit, OnDestroy {
  readonly title = input.required<string>();
  readonly entries = input.required<EntryDto[]>();
  readonly loading = input.required<boolean>();
  readonly loadingMore = input.required<boolean>();
  readonly error = input.required<Problem | null>();
  readonly hasMore = input.required<boolean>();
  readonly canMarkAllRead = input.required<boolean>();
  readonly selection = input.required<Selection>();
  readonly openEntryId = input.required<number | null>();

  readonly loadMore = output<void>();
  readonly markAllRead = output<void>();
  readonly favorite = output<EntryDto>();
  readonly keep = output<EntryDto>();
  readonly read = output<EntryDto>();
  readonly open = output<EntryDto>();

  private readonly sentinel = viewChild<ElementRef<HTMLElement>>('sentinel');
  private observer?: IntersectionObserver;

  // Re-observe whenever the sentinel appears/disappears (hasMore toggles it).
  private readonly _wire = effect(() => {
    const node = this.sentinel()?.nativeElement;
    this.observer?.disconnect();
    if (node && typeof IntersectionObserver !== 'undefined') {
      this.observer = new IntersectionObserver((es) => {
        if (es.some((e) => e.isIntersecting) && this.hasMore() && !this.loadingMore()) this.loadMore.emit();
      });
      this.observer.observe(node);
    }
  });

  ngAfterViewInit(): void {
    /* observer is wired reactively via the effect above */
  }
  ngOnDestroy(): void {
    this.observer?.disconnect();
  }
}
```

- [ ] **Step 5: Run — expect PASS**; **`npm run check`** green.
- [ ] **Step 6: Commit** `feat(frontend): entry list with infinite scroll, filter toggle, mark-all-read`

---

## Task 12: `ReaderViewComponent`

**Files:**
- Create: `frontend/src/app/reader/reader-view/reader-view.component.ts`
- Test: `frontend/src/app/reader/reader-view/reader-view.component.spec.ts`

Renders one entry. Content via `[innerHTML]` (Angular re-sanitizes; **no** `bypassSecurityTrust*`). Anchors inside the rendered body get `target=_blank rel="noopener noreferrer"` in `ngAfterViewChecked` (idempotent).

- [ ] **Step 1: Failing test**

```ts
import { TestBed } from '@angular/core/testing';
import { ReaderViewComponent } from './reader-view.component';
import { EntryDto } from '../models';

const entry = (over: Partial<EntryDto> = {}): EntryDto => ({
  id: 1, title: 'Deep dive', url: 'https://x/1', author: 'Ada', summary: null,
  contentHtml: '<p>Body</p><a href="https://ext.test/z">link</a>', publishedAt: '2026-07-22T11:00:00Z',
  createdAt: 'x', subscriptionId: 5, source: 'Ars', isRead: false, isFavorite: false, isKept: false, ...over,
});

function mount(e: EntryDto | null, hasPrev = true, hasNext = true) {
  const f = TestBed.createComponent(ReaderViewComponent);
  f.componentRef.setInput('entry', e);
  f.componentRef.setInput('hasPrev', hasPrev);
  f.componentRef.setInput('hasNext', hasNext);
  f.detectChanges();
  return f;
}

describe('ReaderViewComponent', () => {
  beforeEach(() => TestBed.configureTestingModule({ imports: [ReaderViewComponent] }));

  it('renders title, meta, content and decorates external links', () => {
    const el = mount(entry()).nativeElement as HTMLElement;
    expect(el.querySelector('.title')!.textContent).toContain('Deep dive');
    expect(el.querySelector('.meta')!.textContent).toContain('Ars');
    expect(el.querySelector('.content')!.textContent).toContain('Body');
    const a = el.querySelector('.content a') as HTMLAnchorElement;
    expect(a.target).toBe('_blank');
    expect(a.rel).toContain('noopener');
  });

  it('emits favorite/keep/read/prev/next/close', () => {
    const f = mount(entry());
    const c = { favorite: 0, keep: 0, read: 0, prev: 0, next: 0, close: 0 };
    (Object.keys(c) as (keyof typeof c)[]).forEach((k) => f.componentInstance[k].subscribe(() => c[k]++));
    const el = f.nativeElement as HTMLElement;
    (el.querySelector('[aria-label="Favorite"]') as HTMLButtonElement).click();
    (el.querySelector('[aria-label="Keep"]') as HTMLButtonElement).click();
    (el.querySelector('[aria-label="Toggle read"]') as HTMLButtonElement).click();
    (el.querySelector('.prev') as HTMLButtonElement).click();
    (el.querySelector('.next') as HTMLButtonElement).click();
    (el.querySelector('.close') as HTMLButtonElement).click();
    expect(c).toEqual({ favorite: 1, keep: 1, read: 1, prev: 1, next: 1, close: 1 });
  });

  it('disables prev/next at the ends', () => {
    const el = mount(entry(), false, false).nativeElement as HTMLElement;
    expect((el.querySelector('.prev') as HTMLButtonElement).disabled).toBe(true);
    expect((el.querySelector('.next') as HTMLButtonElement).disabled).toBe(true);
  });
});
```

- [ ] **Step 2: Run — expect FAIL**: `npx jest reader-view`

- [ ] **Step 3: Implement**

```ts
// src/app/reader/reader-view/reader-view.component.ts
import { AfterViewChecked, Component, ElementRef, input, output, viewChild } from '@angular/core';
import { IconComponent } from '../../shared/icon/icon.component';
import { EntryDto } from '../models';
import { relativeTime } from '../format';

@Component({
  selector: 'app-reader-view',
  imports: [IconComponent],
  template: `
    @if (entry(); as e) {
      <div class="reader">
        <div class="bar">
          <button class="close" type="button" aria-label="Back to list" (click)="close.emit()">
            <app-icon name="arrow_back" [size]="20" />
          </button>
          <div class="nav">
            <button class="prev" type="button" aria-label="Previous" [disabled]="!hasPrev()" (click)="prev.emit()">
              <app-icon name="chevron_left" [size]="20" />
            </button>
            <button class="next" type="button" aria-label="Next" [disabled]="!hasNext()" (click)="next.emit()">
              <app-icon name="chevron_right" [size]="20" />
            </button>
          </div>
        </div>
        <article>
          <h1 class="title">{{ e.title }}</h1>
          <p class="meta">
            {{ e.source }}@if (e.author) { · {{ e.author }} } · {{ when(e) }}
            @if (e.url) {
              · <a [href]="e.url" target="_blank" rel="noopener noreferrer">Open original <app-icon name="open_in_new" [size]="14" /></a>
            }
          </p>
          <div class="actions">
            <button type="button" aria-label="Favorite" [class.on]="e.isFavorite" (click)="favorite.emit()"><app-icon name="star" [size]="20" /></button>
            <button type="button" aria-label="Keep" [class.on]="e.isKept" (click)="keep.emit()"><app-icon name="bookmark" [size]="20" /></button>
            <button type="button" aria-label="Toggle read" (click)="read.emit()"><app-icon [name]="e.isRead ? 'mark_email_unread' : 'check'" [size]="20" /></button>
          </div>
          <div #content class="content" [innerHTML]="e.contentHtml"></div>
        </article>
      </div>
    } @else {
      <div class="placeholder"><p>Select an article to read.</p></div>
    }
  `,
  styles: [
    `
      :host { display: block; height: 100%; overflow: auto; }
      .bar { position: sticky; top: 0; display: flex; align-items: center; justify-content: space-between;
        padding: var(--space-2) var(--space-4); border-bottom: 1px solid var(--border); background: var(--surface-1); }
      .bar button, .actions button { background: none; border: none; color: var(--text-secondary); cursor: pointer; padding: var(--space-1); }
      .bar button:disabled { color: var(--text-muted); cursor: default; }
      article { max-width: 720px; margin: 0 auto; padding: var(--space-5) var(--space-4); }
      .title { font-size: var(--fs-xl); margin: 0 0 var(--space-2); color: var(--text-primary); }
      .meta { font-size: var(--fs-sm); color: var(--text-muted); margin: 0 0 var(--space-3); }
      .meta a { color: var(--accent); text-decoration: none; }
      .actions { display: flex; gap: var(--space-4); padding: var(--space-2) 0 var(--space-4);
        border-bottom: 1px solid var(--border); margin-bottom: var(--space-4); }
      .actions button.on { color: var(--accent); }
      .content { color: var(--text-primary); line-height: 1.7; }
      .content :is(img, video, iframe) { max-width: 100%; height: auto; border-radius: var(--radius); }
      .content a { color: var(--accent); }
      .placeholder { display: grid; place-items: center; height: 100%; color: var(--text-muted); }
    `,
  ],
})
export class ReaderViewComponent implements AfterViewChecked {
  readonly entry = input.required<EntryDto | null>();
  readonly hasPrev = input(false);
  readonly hasNext = input(false);

  readonly favorite = output<void>();
  readonly keep = output<void>();
  readonly read = output<void>();
  readonly prev = output<void>();
  readonly next = output<void>();
  readonly close = output<void>();

  private readonly content = viewChild<ElementRef<HTMLElement>>('content');

  when(e: EntryDto): string {
    return relativeTime(e.publishedAt ?? e.createdAt);
  }

  ngAfterViewChecked(): void {
    const host = this.content()?.nativeElement;
    if (!host) return;
    for (const a of Array.from(host.querySelectorAll('a'))) {
      if (a.target !== '_blank') {
        a.target = '_blank';
        a.rel = 'noopener noreferrer';
      }
    }
  }
}
```

- [ ] **Step 4: Run — expect PASS**; **`npm run check`** green.
- [ ] **Step 5: Commit** `feat(frontend): article reader view with safe content and prev/next`

---

## Task 13: `AddFeedDialogComponent` (CDK Dialog)

**Files:**
- Create: `frontend/src/app/reader/add-feed/add-feed-dialog.component.ts`
- Test: `frontend/src/app/reader/add-feed/add-feed-dialog.component.spec.ts`

Injects `DialogRef` (`@angular/cdk/dialog`) + `ReaderApi`. Submits a URL; on `{subscription}` closes with it, on `{candidates}` lists them (pick → re-subscribe), on 422 shows the field error.

- [ ] **Step 1: Failing test**

```ts
import { TestBed } from '@angular/core/testing';
import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { DialogRef } from '@angular/cdk/dialog';
import { API_BASE_URL } from '../../core/api';
import { AddFeedDialogComponent } from './add-feed-dialog.component';

describe('AddFeedDialogComponent', () => {
  let ctrl: HttpTestingController;
  const close = jest.fn();
  beforeEach(() => {
    close.mockReset();
    TestBed.configureTestingModule({
      imports: [AddFeedDialogComponent],
      providers: [
        provideHttpClient(), provideHttpClientTesting(),
        { provide: API_BASE_URL, useValue: 'https://api.test' },
        { provide: DialogRef, useValue: { close } },
      ],
    });
    ctrl = TestBed.inject(HttpTestingController);
  });

  function create() {
    const f = TestBed.createComponent(AddFeedDialogComponent);
    f.detectChanges();
    return f;
  }

  it('closes with the created subscription', () => {
    const f = create();
    f.componentInstance.form.setValue({ url: 'https://example.com/feed' });
    f.componentInstance.submit();
    ctrl.expectOne('https://api.test/api/subscriptions').flush({ subscription: { id: 9 } }, { status: 201, statusText: 'Created' });
    expect(close).toHaveBeenCalledWith({ id: 9 });
  });

  it('lists candidates and subscribes to a pick', () => {
    const f = create();
    f.componentInstance.form.setValue({ url: 'https://example.com' });
    f.componentInstance.submit();
    ctrl.expectOne('https://api.test/api/subscriptions').flush({ candidates: [{ url: 'https://example.com/rss', title: 'RSS' }] });
    f.detectChanges();
    expect(f.componentInstance.candidates().length).toBe(1);
    f.componentInstance.pick('https://example.com/rss');
    ctrl.expectOne('https://api.test/api/subscriptions').flush({ subscription: { id: 3 } }, { status: 201, statusText: 'Created' });
    expect(close).toHaveBeenCalledWith({ id: 3 });
  });

  it('shows a field error on 422', () => {
    const f = create();
    f.componentInstance.form.setValue({ url: 'not-a-url' });
    f.componentInstance.submit();
    ctrl.expectOne('https://api.test/api/subscriptions').flush(
      { type: 'validation_error', title: 'x', status: 422, errors: { url: ['This value is not a valid URL.'] } },
      { status: 422, statusText: 'Unprocessable' });
    expect(f.componentInstance.error()).toContain('valid URL');
    expect(close).not.toHaveBeenCalled();
  });
});
```

- [ ] **Step 2: Run — expect FAIL**: `npx jest add-feed`

- [ ] **Step 3: Implement**

```ts
// src/app/reader/add-feed/add-feed-dialog.component.ts
import { HttpErrorResponse } from '@angular/common/http';
import { Component, inject, signal } from '@angular/core';
import { NonNullableFormBuilder, ReactiveFormsModule, Validators } from '@angular/forms';
import { DialogRef } from '@angular/cdk/dialog';
import { parseProblem } from '../../core/problem';
import { ReaderApi } from '../reader-api';
import { FeedCandidate, SubscriptionDto } from '../models';

@Component({
  selector: 'app-add-feed-dialog',
  imports: [ReactiveFormsModule],
  template: `
    <div class="dialog" role="dialog" aria-modal="true" aria-label="Add a feed" cdkTrapFocus>
      <h2>Add a feed</h2>
      <form [formGroup]="form" (ngSubmit)="submit()">
        <input class="field" formControlName="url" type="url" placeholder="https://example.com" aria-label="Feed or site URL" cdkFocusInitial />
        @if (error()) { <p class="error" role="alert">{{ error() }}</p> }
        @if (candidates().length) {
          <p class="hint">We found these feeds — pick one:</p>
          <ul class="candidates">
            @for (c of candidates(); track c.url) {
              <li><button type="button" (click)="pick(c.url)">{{ c.title || c.url }}</button></li>
            }
          </ul>
        }
        <div class="row">
          <button type="button" (click)="ref.close()">Cancel</button>
          <button type="submit" class="primary" [disabled]="loading()">{{ loading() ? 'Adding…' : 'Add' }}</button>
        </div>
      </form>
    </div>
  `,
  styles: [
    `
      .dialog { background: var(--surface-2); border: 1px solid var(--border); border-radius: var(--radius);
        padding: var(--space-5); width: min(440px, 92vw); display: flex; flex-direction: column; gap: var(--space-3); }
      h2 { margin: 0; font-size: var(--fs-lg); }
      .error { color: var(--danger); font-size: var(--fs-sm); margin: 0; }
      .hint { color: var(--text-secondary); font-size: var(--fs-sm); margin: 0; }
      .candidates { list-style: none; margin: 0; padding: 0; display: flex; flex-direction: column; gap: var(--space-1); }
      .candidates button { width: 100%; text-align: left; padding: var(--space-2); background: var(--surface-1);
        border: 1px solid var(--border); border-radius: var(--radius); color: var(--text-primary); cursor: pointer; }
      .row { display: flex; justify-content: flex-end; gap: var(--space-2); }
      .row button { padding: var(--space-2) var(--space-4); border: 1px solid var(--border-strong);
        border-radius: var(--radius); background: var(--surface-1); color: var(--text-primary); cursor: pointer; }
      .row button.primary { background: var(--accent); color: var(--on-accent); border-color: var(--accent); }
    `,
  ],
})
export class AddFeedDialogComponent {
  readonly ref = inject<DialogRef<SubscriptionDto>>(DialogRef);
  private readonly api = inject(ReaderApi);
  private readonly fb = inject(NonNullableFormBuilder);

  readonly form = this.fb.group({ url: ['', [Validators.required]] });
  readonly loading = signal(false);
  readonly error = signal<string | null>(null);
  readonly candidates = signal<FeedCandidate[]>([]);

  submit(): void {
    if (this.form.invalid) return;
    this.subscribe(this.form.getRawValue().url);
  }

  pick(url: string): void {
    this.subscribe(url);
  }

  private subscribe(url: string): void {
    this.loading.set(true);
    this.error.set(null);
    this.api.subscribe(url).subscribe({
      next: (res) => {
        this.loading.set(false);
        if ('subscription' in res) this.ref.close(res.subscription);
        else this.candidates.set(res.candidates);
      },
      error: (e: HttpErrorResponse) => {
        this.loading.set(false);
        const p = parseProblem(e);
        this.error.set(p.errors?.['url']?.[0] ?? p.detail ?? p.title);
      },
    });
  }
}
```

Note: `cdkTrapFocus`/`cdkFocusInitial` come from `A11yModule` (`@angular/cdk/a11y`) — add it to `imports`. If a spec without an overlay reports them as unknown elements, keep them (they are attributes on rendered elements) and ensure `A11yModule` is imported so they resolve.

- [ ] **Step 4: Run — expect PASS** (add `A11yModule` to imports if the template attributes need it); **`npm run check`** green.
- [ ] **Step 5: Commit** `feat(frontend): add-feed dialog with discovery candidates`

---

## Task 14: `ReaderHeaderComponent`

**Files:**
- Create: `frontend/src/app/reader/header/reader-header.component.ts`
- Test: `frontend/src/app/reader/header/reader-header.component.spec.ts`

Injects `ThemeService`, `ReadingLayoutService`, `RefreshService`, `AuthService`. Emits `refresh` and `addFeed`; toggles layout + theme locally; shows account menu. Moves the 5a theme + account controls here.

- [ ] **Step 1: Failing test**

```ts
import { TestBed } from '@angular/core/testing';
import { RefreshService } from '../refresh.service';
import { ReadingLayoutService } from '../reading-layout.service';
import { ThemeService } from '../../theme/theme.service';
import { AuthService } from '../../core/auth.service';
import { ReaderHeaderComponent } from './reader-header.component';
import { signal } from '@angular/core';

describe('ReaderHeaderComponent', () => {
  const auth = { user: signal({ email: 'a@b.c' }), logout: jest.fn() };
  beforeEach(() => {
    localStorage.clear();
    TestBed.configureTestingModule({
      imports: [ReaderHeaderComponent],
      providers: [{ provide: AuthService, useValue: auth }],
    });
  });

  function create() {
    const f = TestBed.createComponent(ReaderHeaderComponent);
    f.componentRef.setInput('title', 'All items');
    f.detectChanges();
    return f;
  }

  it('emits refresh and addFeed', () => {
    const f = create();
    let refresh = 0, add = 0;
    f.componentInstance.refresh.subscribe(() => refresh++);
    f.componentInstance.addFeed.subscribe(() => add++);
    const el = f.nativeElement as HTMLElement;
    (el.querySelector('[aria-label="Refresh"]') as HTMLButtonElement).click();
    (el.querySelector('[aria-label="Add feed"]') as HTMLButtonElement).click();
    expect([refresh, add]).toEqual([1, 1]);
  });

  it('toggles the reading layout', () => {
    const f = create();
    const layout = TestBed.inject(ReadingLayoutService);
    (f.nativeElement.querySelector('[aria-label="Pane layout"]') as HTMLButtonElement).click();
    expect(layout.mode()).toBe('pane');
  });

  it('shows the busy state while refreshing', () => {
    const f = create();
    TestBed.inject(RefreshService).running.set(true);
    f.detectChanges();
    expect((f.nativeElement.querySelector('[aria-label="Refresh"]') as HTMLButtonElement).disabled).toBe(true);
  });
});
```

- [ ] **Step 2: Run — expect FAIL**: `npx jest reader-header`

- [ ] **Step 3: Implement** (reuse the 5a theme-control + account-menu markup; add refresh, add-feed, layout toggle)

```ts
// src/app/reader/header/reader-header.component.ts
import { Component, inject, input, output, signal } from '@angular/core';
import { IconComponent } from '../../shared/icon/icon.component';
import { AuthService } from '../../core/auth.service';
import { ThemeService } from '../../theme/theme.service';
import { ThemeMode } from '../../theme/themes/registry';
import { ReadingLayoutService } from '../reading-layout.service';
import { RefreshService } from '../refresh.service';

@Component({
  selector: 'app-reader-header',
  imports: [IconComponent],
  template: `
    <header>
      <span class="brand">{{ title() }}</span>
      <div class="right">
        <div class="progress" [class.on]="refreshSvc.running()">
          <button aria-label="Refresh" [disabled]="refreshSvc.running()" (click)="refresh.emit()">
            <app-icon name="refresh" [size]="18" />
          </button>
          @if (refreshSvc.running()) { <span class="bar"><i [style.width.%]="refreshSvc.progress() * 100"></i></span> }
        </div>
        <button aria-label="Add feed" (click)="addFeed.emit()"><app-icon name="add" [size]="18" /></button>

        <div class="seg" role="group" aria-label="Reading layout">
          <button aria-label="List layout" [class.active]="layout.mode() === 'list'" (click)="layout.set('list')"><app-icon name="view_agenda" [size]="18" /></button>
          <button aria-label="Pane layout" [class.active]="layout.mode() === 'pane'" (click)="layout.set('pane')"><app-icon name="view_column_2" [size]="18" /></button>
        </div>

        <div class="seg" role="group" aria-label="Theme">
          @for (m of modes; track m.id) {
            <button [class.active]="theme.mode() === m.id" [attr.aria-pressed]="theme.mode() === m.id" [title]="m.label" (click)="theme.setMode(m.id)">
              <app-icon [name]="m.icon" [size]="18" />
            </button>
          }
        </div>

        <div class="account">
          <button aria-haspopup="menu" [attr.aria-expanded]="menuOpen()" (click)="menuOpen.set(!menuOpen())">
            {{ auth.user()?.email ?? '…' }} <app-icon name="expand_more" [size]="18" />
          </button>
          @if (menuOpen()) {
            <div class="menu" role="menu"><button role="menuitem" (click)="auth.logout()">Sign out</button></div>
          }
        </div>
      </div>
    </header>
  `,
  styles: [
    `
      header { height: 56px; display: flex; align-items: center; justify-content: space-between;
        padding: 0 var(--space-4); border-bottom: 1px solid var(--border); background: var(--surface-1); }
      .brand { font-weight: 500; }
      .right { display: flex; align-items: center; gap: var(--space-3); }
      .right > button, .account > button { display: inline-flex; align-items: center; gap: var(--space-1);
        background: none; border: none; color: var(--text-primary); cursor: pointer; }
      .seg { display: inline-flex; border: 1px solid var(--border); border-radius: var(--radius); overflow: hidden; }
      .seg button { padding: var(--space-2); background: var(--surface-1); border: none; color: var(--text-secondary); cursor: pointer; }
      .seg button.active { background: var(--accent-soft); color: var(--accent); }
      .progress { display: inline-flex; align-items: center; gap: var(--space-1); }
      .progress .bar { width: 48px; height: 4px; border-radius: 2px; background: var(--border); overflow: hidden; }
      .progress .bar i { display: block; height: 100%; background: var(--accent); transition: width 0.2s; }
      .account { position: relative; }
      .menu { position: absolute; right: 0; top: 40px; background: var(--surface-2); border: 1px solid var(--border);
        border-radius: var(--radius); min-width: 140px; }
      .menu button { width: 100%; text-align: left; padding: var(--space-3); background: none; border: none;
        color: var(--text-primary); cursor: pointer; }
    `,
  ],
})
export class ReaderHeaderComponent {
  readonly title = input.required<string>();
  readonly refresh = output<void>();
  readonly addFeed = output<void>();

  readonly auth = inject(AuthService);
  readonly theme = inject(ThemeService);
  readonly layout = inject(ReadingLayoutService);
  readonly refreshSvc = inject(RefreshService);
  readonly menuOpen = signal(false);

  readonly modes: { id: ThemeMode; label: string; icon: string }[] = [
    { id: 'light', label: 'Light', icon: 'light_mode' },
    { id: 'dark', label: 'Dark', icon: 'dark_mode' },
    { id: 'system', label: 'System', icon: 'contrast' },
  ];
}
```

- [ ] **Step 4: Run — expect PASS**; **`npm run check`** green.
- [ ] **Step 5: Commit** `feat(frontend): reader header (refresh, add-feed, layout + theme, account)`

---

## Task 15: `ReaderShellComponent` + routing (replaces the placeholder shell)

**Files:**
- Create: `frontend/src/app/reader/reader-shell.component.ts`
- Test: `frontend/src/app/reader/reader-shell.component.spec.ts`
- Modify: `frontend/src/app/app.routes.ts` (point `''` at the reader shell)
- Delete: `frontend/src/app/shell/shell.component.ts`, `frontend/src/app/shell/shell.component.spec.ts`
- Modify (if they reference `ShellComponent`): `frontend/src/app/app.routes.spec.ts`

The shell owns the URL→selection mapping, the stores, mark-on-open, mark-all-read, refresh, and add-feed. Selection and open-entry live in query params; two `effect`s react.

- [ ] **Step 1: Failing test** (drives selection through a controllable `ActivatedRoute`)

```ts
import { TestBed } from '@angular/core/testing';
import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { ActivatedRoute, Router, convertToParamMap, provideRouter } from '@angular/router';
import { BehaviorSubject, of } from 'rxjs';
import { signal } from '@angular/core';
import { API_BASE_URL } from '../core/api';
import { AuthService } from '../core/auth.service';
import { ReaderShellComponent } from './reader-shell.component';

describe('ReaderShellComponent', () => {
  let ctrl: HttpTestingController;
  const qp = new BehaviorSubject(convertToParamMap({}));
  const auth = { user: signal({ email: 'a@b.c' }), loadMe: () => of({}), logout: jest.fn() };

  const subsBody = { subscriptions: [
    { id: 5, title: 'heise', customTitle: null, feedUrl: 'https://f/5', siteUrl: null, status: 'active', createdAt: 'x', tags: [], unreadCount: 2 },
  ] };
  const entry = { id: 1, title: 'e1', url: null, author: null, summary: 's', contentHtml: '<p>b</p>',
    publishedAt: '2026-07-22T11:00:00Z', createdAt: 'x', subscriptionId: 5, source: 'heise', isRead: false, isFavorite: false, isKept: false };

  beforeEach(() => {
    qp.next(convertToParamMap({}));
    TestBed.configureTestingModule({
      imports: [ReaderShellComponent],
      providers: [
        provideHttpClient(), provideHttpClientTesting(), provideRouter([]),
        { provide: API_BASE_URL, useValue: 'https://api.test' },
        { provide: ActivatedRoute, useValue: { queryParamMap: qp.asObservable() } },
        { provide: AuthService, useValue: auth },
      ],
    });
    ctrl = TestBed.inject(HttpTestingController);
  });

  function boot() {
    const f = TestBed.createComponent(ReaderShellComponent);
    f.detectChanges(); // ngOnInit + initial effects
    ctrl.expectOne('https://api.test/api/subscriptions').flush(subsBody);
    ctrl.expectOne((r) => r.url === 'https://api.test/api/entries').flush({ entries: [entry], nextCursor: null });
    f.detectChanges();
    return f;
  }

  it('renders header + sidebar and loads the initial list', () => {
    const el = boot().nativeElement as HTMLElement;
    expect(el.querySelector('app-reader-header')).not.toBeNull();
    expect(el.querySelector('app-sidebar')!.textContent).toContain('heise');
    expect(el.querySelectorAll('app-entry-row').length).toBe(1);
  });

  it('marks the opened entry read', () => {
    const f = boot();
    qp.next(convertToParamMap({ entry: '1' }));
    f.detectChanges();
    const req = ctrl.expectOne('https://api.test/api/entries/1/state');
    expect(req.request.body).toEqual({ isRead: true });
    req.flush({ state: { entryId: 1, isRead: true, isFavorite: false, isKept: false, readAt: 'x' } });
    expect(f.nativeElement.querySelector('app-reader-view')).not.toBeNull();
  });

  it('reloads entries when the selection changes', () => {
    const f = boot();
    qp.next(convertToParamMap({ subscription: '5' }));
    f.detectChanges();
    ctrl.expectOne((r) => r.params.get('subscription') === '5').flush({ entries: [], nextCursor: null });
    expect(f.nativeElement.querySelector('.empty')).not.toBeNull();
  });
});
```

- [ ] **Step 2: Run — expect FAIL**: `npx jest reader-shell`

- [ ] **Step 3: Implement `reader-shell.component.ts`**

```ts
// src/app/reader/reader-shell.component.ts
import { Component, OnInit, computed, effect, inject, untracked } from '@angular/core';
import { ActivatedRoute, Router, convertToParamMap } from '@angular/router';
import { toSignal } from '@angular/core/rxjs-interop';
import { Dialog } from '@angular/cdk/dialog';
import { AuthService } from '../core/auth.service';
import { ReaderApi } from './reader-api';
import { SubscriptionsStore } from './subscriptions.store';
import { EntriesStore } from './entries.store';
import { RefreshService } from './refresh.service';
import { ReadingLayoutService } from './reading-layout.service';
import { LayoutService } from './layout.service';
import { markReadTarget, queryFromSelection, selectionFromParams } from './query';
import { EntryDto } from './models';
import { ReaderHeaderComponent } from './header/reader-header.component';
import { SidebarComponent } from './sidebar/sidebar.component';
import { EntryListComponent } from './entry-list/entry-list.component';
import { ReaderViewComponent } from './reader-view/reader-view.component';
import { AddFeedDialogComponent } from './add-feed/add-feed-dialog.component';
import { SubscriptionDto } from './models';

@Component({
  selector: 'app-reader-shell',
  imports: [ReaderHeaderComponent, SidebarComponent, EntryListComponent, ReaderViewComponent],
  template: `
    <app-reader-header [title]="title()" (refresh)="onRefresh()" (addFeed)="onAddFeed()" />
    <div class="body">
      <aside class="sidebar">
        <app-sidebar [tagTree]="subs.tagTree()" [untagged]="subs.untagged()"
          [totalUnread]="subs.totalUnread()" [selection]="selection()" [loading]="subs.loading()" />
      </aside>
      <main class="main" [class.split]="paneMode()">
        @if (paneMode()) {
          <section class="list">
            <app-entry-list [title]="title()" [entries]="entries.entries()" [loading]="entries.loading()"
              [loadingMore]="entries.loadingMore()" [error]="entries.error()" [hasMore]="hasMore()"
              [canMarkAllRead]="canMarkAllRead()" [selection]="selection()" [openEntryId]="entryId()"
              (loadMore)="entries.loadMore()" (markAllRead)="onMarkAllRead()"
              (favorite)="onFavorite($event)" (keep)="onKeep($event)" (read)="onToggleRead($event)" (open)="onOpen($event)" />
          </section>
          <section class="reader">
            <app-reader-view [entry]="openEntry()" [hasPrev]="hasPrev()" [hasNext]="hasNext()"
              (favorite)="withOpen(onFavorite)" (keep)="withOpen(onKeep)" (read)="withOpen(onToggleRead)"
              (prev)="onPrev()" (next)="onNext()" (close)="onCloseReader()" />
          </section>
        } @else if (openEntry()) {
          <app-reader-view [entry]="openEntry()" [hasPrev]="hasPrev()" [hasNext]="hasNext()"
            (favorite)="withOpen(onFavorite)" (keep)="withOpen(onKeep)" (read)="withOpen(onToggleRead)"
            (prev)="onPrev()" (next)="onNext()" (close)="onCloseReader()" />
        } @else {
          <app-entry-list [title]="title()" [entries]="entries.entries()" [loading]="entries.loading()"
            [loadingMore]="entries.loadingMore()" [error]="entries.error()" [hasMore]="hasMore()"
            [canMarkAllRead]="canMarkAllRead()" [selection]="selection()" [openEntryId]="entryId()"
            (loadMore)="entries.loadMore()" (markAllRead)="onMarkAllRead()"
            (favorite)="onFavorite($event)" (keep)="onKeep($event)" (read)="onToggleRead($event)" (open)="onOpen($event)" />
        }
      </main>
    </div>
  `,
  styles: [
    `
      :host { display: flex; flex-direction: column; height: 100vh; }
      .body { flex: 1; display: flex; min-height: 0; }
      .sidebar { width: 260px; flex: 0 0 auto; border-right: 1px solid var(--border); background: var(--surface-1); }
      .main { flex: 1; min-width: 0; display: flex; }
      .main.split .list { flex: 0 0 42%; max-width: 480px; border-right: 1px solid var(--border); display: flex; flex-direction: column; }
      .main.split .reader { flex: 1; min-width: 0; }
      .main:not(.split) > * { flex: 1; min-width: 0; }
      @media (max-width: 720px) { .sidebar { display: none; } }
    `,
  ],
})
export class ReaderShellComponent implements OnInit {
  private readonly route = inject(ActivatedRoute);
  private readonly router = inject(Router);
  private readonly dialog = inject(Dialog);
  private readonly api = inject(ReaderApi);
  private readonly auth = inject(AuthService);

  readonly subs = inject(SubscriptionsStore);
  readonly entries = inject(EntriesStore);
  readonly refreshSvc = inject(RefreshService);
  readonly layout = inject(ReadingLayoutService);
  readonly screen = inject(LayoutService);

  private readonly params = toSignal(this.route.queryParamMap, { initialValue: convertToParamMap({}) });
  private readonly parsed = computed(() => selectionFromParams(this.params()));
  readonly selection = computed(() => this.parsed().selection);
  readonly entryId = computed(() => this.parsed().entryId);

  readonly openEntry = computed(() => this.entries.entries().find((e) => e.id === this.entryId()) ?? null);
  readonly hasMore = computed(() => this.entries.nextCursor() !== null);
  readonly canMarkAllRead = computed(() => markReadTarget(this.selection()) !== null);
  readonly paneMode = computed(() => this.layout.mode() === 'pane' && this.screen.isWide());

  readonly title = computed(() => {
    const s = this.selection();
    if (s.kind === 'favorites') return 'Favorites';
    if (s.kind === 'kept') return 'Kept';
    if (s.kind === 'all') return 'All items';
    if (s.kind === 'tag') return this.subs.tagTree().find((n) => n.tag.id === s.id)?.tag.name ?? 'Tag';
    return this.subs.subscriptions().find((x) => x.id === s.id)?.title ?? 'Feed';
  });

  private readonly index = computed(() => this.entries.entries().findIndex((e) => e.id === this.entryId()));
  readonly hasPrev = computed(() => this.index() > 0);
  readonly hasNext = computed(() => {
    const i = this.index();
    return i >= 0 && i < this.entries.entries().length - 1;
  });

  constructor() {
    // Reload the list whenever the selection (not the open entry) changes.
    effect(() => {
      const q = queryFromSelection(this.selection());
      untracked(() => this.entries.load(q));
    });
    // Mark the opened entry read exactly once when it becomes available unread.
    effect(() => {
      const e = this.openEntry();
      if (e && !e.isRead) untracked(() => this.setRead(e, true));
    });
  }

  ngOnInit(): void {
    this.subs.load();
    if (!this.auth.user()) this.auth.loadMe().subscribe({ error: () => undefined });
  }

  onFavorite = (e: EntryDto): void => this.entries.setState(e.id, { isFavorite: !e.isFavorite });
  onKeep = (e: EntryDto): void => this.entries.setState(e.id, { isKept: !e.isKept });
  onToggleRead = (e: EntryDto): void => this.setRead(e, !e.isRead);

  /** Reader-view outputs are payload-less; apply them to the currently open entry. */
  withOpen(fn: (e: EntryDto) => void): void {
    const e = this.openEntry();
    if (e) fn(e);
  }

  private setRead(e: EntryDto, read: boolean): void {
    this.entries.setState(e.id, { isRead: read });
    if (read) this.subs.decrementUnread(e.subscriptionId);
    else this.subs.incrementUnread(e.subscriptionId);
  }

  onOpen(e: EntryDto): void {
    void this.router.navigate([], { relativeTo: this.route, queryParams: { entry: e.id }, queryParamsHandling: 'merge' });
  }
  onCloseReader(): void {
    void this.router.navigate([], { relativeTo: this.route, queryParams: { entry: null }, queryParamsHandling: 'merge' });
  }
  onPrev(): void {
    const i = this.index();
    if (i > 0) this.onOpen(this.entries.entries()[i - 1]);
  }
  onNext(): void {
    const i = this.index();
    if (i >= 0 && i < this.entries.entries().length - 1) this.onOpen(this.entries.entries()[i + 1]);
  }

  onMarkAllRead(): void {
    const t = markReadTarget(this.selection());
    if (!t) return;
    const until = this.entries.loadedAt() || new Date().toISOString();
    this.api.markRead(t.scope, until, t.id).subscribe({
      next: () => {
        this.subs.zeroUnread(t.scope === 'all' ? 'all' : t.scope === 'tag' ? { tag: t.id! } : { subscription: t.id! });
        this.entries.load(queryFromSelection(this.selection()));
      },
    });
  }

  onRefresh(): void {
    this.refreshSvc.run(() => {
      this.subs.load();
      this.entries.load(queryFromSelection(this.selection()));
    });
  }

  onAddFeed(): void {
    const ref = this.dialog.open<SubscriptionDto>(AddFeedDialogComponent);
    ref.closed.subscribe((sub) => {
      if (!sub) return;
      this.subs.load();
      void this.router.navigate([], { relativeTo: this.route, queryParams: { subscription: sub.id, view: null, tag: null, entry: null }, queryParamsHandling: 'merge' });
    });
  }
}
```

Note on the two `@else`-shared `app-entry-list` blocks: they are identical; the implementer may hoist to an `<ng-template>` to avoid duplication, but keep behavior identical.

- [ ] **Step 4: Point routing at the reader shell** — edit `app.routes.ts`, replacing the `''` route's `loadComponent`:

```ts
  {
    path: '',
    canActivate: [authGuard],
    loadComponent: () => import('./reader/reader-shell.component').then((m) => m.ReaderShellComponent),
  },
```

- [ ] **Step 5: Delete the placeholder shell and fix references**

```bash
git rm frontend/src/app/shell/shell.component.ts frontend/src/app/shell/shell.component.spec.ts
```

If `app.routes.spec.ts` imports/asserts `ShellComponent`, update it to reference `ReaderShellComponent` (or assert the lazy import resolves). Run `npx jest app.routes` to confirm.

- [ ] **Step 6: Run the full suite** — `npx jest reader-shell` PASS, then **`npm run check`** green, then **`npm run build`** (proves the lazy chunk + CDK compile).
- [ ] **Step 7: Commit**

```bash
git add frontend/src/app/reader/reader-shell.component.ts frontend/src/app/reader/reader-shell.component.spec.ts frontend/src/app/app.routes.ts frontend/src/app/app.routes.spec.ts
git commit -m "feat(frontend): reader shell wiring selection, stores, mark-read, refresh, add-feed"
```

---

## Task 16: Manual verification against Docker + Playwright reader smoke

**Files:**
- Create: `frontend/e2e/reader-smoke.spec.ts`
- Reference: `frontend/e2e/auth-smoke.spec.ts`, `frontend/playwright.config.ts` (existing patterns)

Not part of `npm run check`/CI unit gate (needs the Docker stack). Mirrors the 5a smoke's login helper.

- [ ] **Step 1: Bring the stack up** (from repo root) and rebuild the frontend deps if needed:

```bash
docker compose up -d
```

Open http://localhost:4200, sign in (or register→verify via Mailpit→approve via console as the 5a smoke does), and manually confirm: sidebar lists subscriptions with counts; the list paginates; opening an entry marks it read and decrements the count; favorite/keep toggle; mark-all-read clears; the layout toggle switches List/Pane on a wide window; refresh shows progress; Add feed accepts a URL and shows candidates for an HTML page. Fix anything broken before writing the automated smoke.

- [ ] **Step 2: Write `reader-smoke.spec.ts`** — a resilient journey that reuses the existing login helper, then:
  - asserts the reader shell renders (header, sidebar) after login;
  - opens the Add-feed dialog and submits a known feed URL (e.g. the e2e feeds used by `ReaderJourneyE2eTest`), tolerating either a direct subscribe or a candidate list (**skip-if-unreachable**, matching the backend real-feed e2e convention);
  - if entries appear, opens the first, asserts the reader view shows, toggles favorite.

Keep network-dependent steps guarded so the smoke passes when feeds are unreachable (assert the UI reached the expected state, skip the rest). Follow `auth-smoke.spec.ts` for base URL, storage, and login.

- [ ] **Step 3: Run** `npm run e2e` against the running stack; iterate until green (or cleanly skipped on unreachable feeds).
- [ ] **Step 4: Commit** `test(frontend): reader journey Playwright smoke against Docker`

---

## Task 17: Documentation

**Files:**
- Modify: `frontend/README.md`

- [ ] **Step 1:** Add a short **Reader** section to `frontend/README.md` describing: the reader shell (sidebar tree, entry list, article reader); the **List/Pane** reading-layout preference (on-device, beside the theme toggle); that preview images are extracted client-side from content (no backend change, https-only, no-referrer); and that tag/subscription **management**, OPML, admin, and the full settings page are **5c**. Note the new `@angular/cdk` dependency. Keep it consistent with the existing README voice.
- [ ] **Step 2:** `npm run format` if needed; confirm `npm run check` still green.
- [ ] **Step 3: Commit** `docs(frontend): document the 5b reader in the frontend README`

---

## Self-review (author checklist — completed)

- **Spec coverage:** shell/sidebar/list/row/reader (Tasks 9–15), List/Pane preference (Tasks 3, 14, 15), preview images (Tasks 4, 9), unread toggle + infinite scroll + mark-all (Tasks 5, 11, 15), mark-on-open + read/favorite/keep (Tasks 7, 12, 15), subscribe-by-URL (Task 13, 15), refresh progress (Tasks 8, 14, 15), loading/empty/error (Tasks 11, 15), CDK add (Task 2), tests (all + Task 16), docs (Task 17). 5c items are explicitly out.
- **Contract fidelity:** endpoints/params/bodies match the source verified 2026-07-22 (`EntryJson`, `SubscriptionJson`, `EntryController`, `MarkReadService` → `scope=feed` is a subscription id, `subscribe` returns `{subscription}`|`{candidates}`, `refresh` statuses `busy|partial|completed|aborted`).
- **Type consistency:** `EntryDto`/`SubscriptionDto`/`TagDto`/`EntryQuery`/`Selection`/`RefreshReport` are defined once (Task 1 + Task 5) and reused; `firstPreviewImage`/`textSnippet`/`relativeTime`/`buildTagTree`/`queryFromSelection`/`markReadTarget` signatures are stable across their consumers.
- **No placeholders:** every code step carries complete code; presentational styles are structural-complete and token-only (Stylelint-enforced), with the design mockups as the finish reference.

## Execution

Execute with **superpowers:subagent-driven-development** — fresh implementer per task, spec-compliance then code-quality review between tasks, continuous (no check-ins). After Task 17, dispatch a final whole-branch review, then **superpowers:finishing-a-development-branch**.
