# Reader header refresh + kept/favorite on mobile — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Show the favorite and keep buttons in the article-view toolbar on mobile as well as desktop, and add an icon-only refresh button that drops the open article from the browser cache and refetches it.

**Architecture:** Frontend-only, all in the `reader-view` area. A new `ReaderCacheService.delete()` removes one IndexedDB record; a new `ReaderContentService.reload()` deletes then refetches (cache-busting mirror of `load()`); `ReaderViewComponent` gains a `refreshArticle()` that reuses the existing load lifecycle through an extracted `runLoad()` helper, showing the existing loading state and preserving the reader/original mode. The template unifies the toolbar so favorite/keep/refresh/mode read the same in both layouts.

**Tech Stack:** Angular 20 (standalone, signals), RxJS, IndexedDB, Jest (jsdom), fake-indexeddb, Transloco.

## Global Constraints

- Spec: `docs/superpowers/specs/2026-08-18-reader-header-refresh-design.md` (issue #470).
- Frontend-only. No backend change. **No cache `VERSION` bump.**
- Component styles live in the sibling `.scss` — no inline styles. No hex colours and no raw `px` outside `src/app/theme/` (Stylelint fails otherwise).
- `refresh` uses the shared `<app-icon name="refresh" size="md" />` — the glyph is already in the loaded font.
- Every aria-label comes from Transloco; add keys to both `en.json` and `de.json`.
- The refresh action must **not** reset the reader/original mode.
- CI gate is `npm run check` (ESLint + Prettier + Stylelint + Jest), run from `frontend/`. Prettier is 100-column.
- Tests are production code: same naming and standards; assertions must be falsifiable.

---

### Task 1: `ReaderCacheService.delete(entryId)`

**Files:**
- Modify: `frontend/src/app/reader/reader-cache.service.ts`
- Test: `frontend/src/app/reader/reader-cache.service.spec.ts`

**Interfaces:**
- Produces: `delete(entryId: number): Promise<void>` — removes the one record; deleting an absent id resolves without error.

- [ ] **Step 1: Write the failing test**

Add to `reader-cache.service.spec.ts` inside the `describe('ReaderCacheService', …)` block:

```typescript
it('deletes a cached entry, leaving a later get a miss', async () => {
  await cache.put(1, article('https://x/1'));
  expect(await cache.get(1)).not.toBeNull();
  await cache.delete(1);
  expect(await cache.get(1)).toBeNull();
});

it('treats deleting an absent entry as a no-op', async () => {
  await expect(cache.delete(999)).resolves.toBeUndefined();
});
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `cd frontend && npx jest reader-cache.service --silent`
Expected: FAIL — `cache.delete is not a function`.

- [ ] **Step 3: Add the `delete` method**

In `reader-cache.service.ts`, add this method after `put()` (before `private evict`):

```typescript
  async delete(entryId: number): Promise<void> {
    const db = await this.open();
    if (!db) return;
    await new Promise<void>((resolve) => {
      const tx = db.transaction(ReaderCacheService.STORE, 'readwrite');
      tx.objectStore(ReaderCacheService.STORE).delete(entryId);
      tx.oncomplete = () => resolve();
      tx.onerror = () => resolve();
    });
  }
```

- [ ] **Step 4: Run the tests to verify they pass**

Run: `cd frontend && npx jest reader-cache.service --silent`
Expected: PASS (all cases, old and new).

- [ ] **Step 5: Commit**

```bash
git add frontend/src/app/reader/reader-cache.service.ts frontend/src/app/reader/reader-cache.service.spec.ts
git commit -m "feat(#470): drop a single article from the reader cache"
```

---

### Task 2: `ReaderContentService.reload(entryId)`

**Files:**
- Modify: `frontend/src/app/reader/reader-content.service.ts`
- Test: `frontend/src/app/reader/reader-content.service.spec.ts`

**Interfaces:**
- Consumes: `ReaderCacheService.delete(entryId): Promise<void>` (Task 1).
- Produces: `reload(entryId: number): Observable<ReaderContent>` — deletes the cache record, then fetches from the API and re-caches a successful (`status: 'ok'`) result; a failure is not cached.

- [ ] **Step 1: Write the failing test**

The existing suite provides `ReaderCacheService` as `{ get: cacheGet, put: cachePut }`. Add a `cacheDelete` mock and tests. Replace the `beforeEach` provider wiring and add the new cases:

In the `let` block near the top, add:

```typescript
  let cacheDelete: jest.Mock;
```

In `beforeEach`, set it up and add it to the provider:

```typescript
    cacheDelete = jest.fn().mockResolvedValue(undefined);
```
```typescript
        { provide: ReaderCacheService, useValue: { get: cacheGet, put: cachePut, delete: cacheDelete } },
```

Then add these tests inside the `describe`:

```typescript
  it('reload deletes the cache then fetches from the API even on a prior hit', async () => {
    cacheGet.mockResolvedValue(ARTICLE);
    apiGet.mockReturnValue(of(ARTICLE));
    const svc = TestBed.inject(ReaderContentService);
    const result = await firstValueFrom(svc.reload(1));
    expect(cacheDelete).toHaveBeenCalledWith(1);
    expect(apiGet).toHaveBeenCalledWith(1);
    expect(result).toEqual(ARTICLE);
    expect(cachePut).toHaveBeenCalledWith(1, ARTICLE);
  });

  it('reload does not cache a failed refetch', async () => {
    const failure: ReaderContent = { status: 'failed', url: null, reason: 'fetch' };
    apiGet.mockReturnValue(of(failure));
    const svc = TestBed.inject(ReaderContentService);
    await firstValueFrom(svc.reload(1));
    expect(cacheDelete).toHaveBeenCalledWith(1);
    expect(cachePut).not.toHaveBeenCalled();
  });
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `cd frontend && npx jest reader-content.service --silent`
Expected: FAIL — `svc.reload is not a function`.

- [ ] **Step 3: Add the `reload` method**

In `reader-content.service.ts`, factor the shared fetch-and-cache tail out of `load()` and add `reload()`. Replace the class body's `load` with:

```typescript
  load(entryId: number): Observable<ReaderContent> {
    return from(this.cache.get(entryId)).pipe(
      switchMap((cached) => (cached ? of<ReaderContent>(cached) : this.fetchAndCache(entryId))),
    );
  }

  /** Drop this entry's cached copy, then fetch and re-cache a fresh one. */
  reload(entryId: number): Observable<ReaderContent> {
    return from(this.cache.delete(entryId)).pipe(switchMap(() => this.fetchAndCache(entryId)));
  }

  private fetchAndCache(entryId: number): Observable<ReaderContent> {
    return this.api.readerContent(entryId).pipe(
      tap((c) => {
        if (c.status === 'ok') void this.cache.put(entryId, c);
      }),
    );
  }
```

- [ ] **Step 4: Run the tests to verify they pass**

Run: `cd frontend && npx jest reader-content.service --silent`
Expected: PASS (existing `load` cases and the two new `reload` cases).

- [ ] **Step 5: Commit**

```bash
git add frontend/src/app/reader/reader-content.service.ts frontend/src/app/reader/reader-content.service.spec.ts
git commit -m "feat(#470): reload refetches a single article past the cache"
```

---

### Task 3: Reader-view toolbar — unify buttons, add refresh, wire `refreshArticle()`

**Files:**
- Modify: `frontend/src/app/reader/reader-view/reader-view.component.ts`
- Modify: `frontend/src/app/reader/reader-view/reader-view.component.html`
- Modify: `frontend/src/app/reader/reader-view/reader-view.component.spec.ts`
- Modify: `frontend/public/i18n/en.json`
- Modify: `frontend/public/i18n/de.json`

**Interfaces:**
- Consumes: `ReaderContentService.reload(entryId): Observable<ReaderContent>` (Task 2); existing `ReaderContentService.load`, `ReaderModeService`.
- Produces: `ReaderViewComponent.refreshArticle(): void` — refetches the open entry past the cache, entering the loading state and preserving the reader/original mode.

- [ ] **Step 1: Add the i18n key to both locales**

In `frontend/public/i18n/en.json`, immediately after the `"openOriginal": "Open original",` line inside `reader`:

```json
    "refreshArticle": "Reload article",
```

In `frontend/public/i18n/de.json`, immediately after the `"openOriginal": "Original öffnen",` line inside `reader`:

```json
    "refreshArticle": "Artikel neu laden",
```

- [ ] **Step 2: Write the failing component tests**

First make the shared mock expose `reload` so the component can call it. In `reader-view.component.spec.ts`, at the top-level `let loadMock: jest.Mock;` add:

```typescript
let reloadMock: jest.Mock;
```

In the outer `beforeEach`, initialise it and widen the provider:

```typescript
    reloadMock = jest.fn(() => of<ReaderContent>(okContent()));
```
```typescript
        { provide: ReaderContentService, useValue: { load: loadMock, reload: reloadMock } },
```

Replace the existing test `'leaves the full-screen toolbar to the back button and the mode toggle'` (it asserts favorite/keep are absent in fullscreen — the change reverses that) with:

```typescript
    it('offers favourite and keep in the full-screen toolbar too', () => {
      const f = TestBed.createComponent(ReaderViewComponent);
      f.componentRef.setInput('entry', entry());
      f.componentRef.setInput('fullscreen', true);
      f.detectChanges();

      const el = f.nativeElement as HTMLElement;
      // The nameplate still rides the .mini strip in full screen, not the bar.
      expect(el.querySelector('.bar .bar-title')).toBeNull();
      expect(el.querySelector('.bar [aria-label="Favorite"]')).not.toBeNull();
      expect(el.querySelector('.bar [aria-label="Keep"]')).not.toBeNull();
      f.destroy();
    });
```

Then add a new `describe` for the refresh button (place it after the toolbar `describe` block):

```typescript
  describe('article refresh', () => {
    it('shows the refresh button in both layouts and while extraction failed', () => {
      // Default loadMock resolves failed, so this also covers the failed case.
      const pane = mount(entry()).nativeElement as HTMLElement;
      expect(pane.querySelector('.bar [aria-label="Reload article"]')).not.toBeNull();

      const f = TestBed.createComponent(ReaderViewComponent);
      f.componentRef.setInput('entry', entry());
      f.componentRef.setInput('fullscreen', true);
      f.detectChanges();
      expect(
        (f.nativeElement as HTMLElement).querySelector('.bar [aria-label="Reload article"]'),
      ).not.toBeNull();
      f.destroy();
    });

    it('refetches past the cache and shows the loading state', () => {
      loadMock.mockReturnValue(of<ReaderContent>(okContent()));
      const subject = new Subject<ReaderContent>();
      reloadMock.mockReturnValue(subject.asObservable());
      const f = mount(entry());
      const el = f.nativeElement as HTMLElement;

      (el.querySelector('.bar [aria-label="Reload article"]') as HTMLButtonElement).click();
      f.detectChanges();
      expect(reloadMock).toHaveBeenCalledWith(1);
      expect(el.querySelector('.loading')).not.toBeNull();

      subject.next(okContent({ contentHtml: '<p>FRESH</p>' }));
      subject.complete();
      f.detectChanges();
      expect(el.querySelector('.loading')).toBeNull();
      expect(el.querySelector('.content')!.innerHTML).toContain('FRESH');
    });

    it('preserves the reader/original mode across a refresh', () => {
      loadMock.mockReturnValue(of<ReaderContent>(okContent()));
      reloadMock.mockReturnValue(of<ReaderContent>(okContent()));
      const f = mount(entry());
      f.componentInstance.toggleMode(); // reader -> original
      expect(f.componentInstance.mode()).toBe('original');

      (f.nativeElement as HTMLElement)
        .querySelector<HTMLButtonElement>('.bar [aria-label="Reload article"]')!
        .click();
      f.detectChanges();
      expect(f.componentInstance.mode()).toBe('original');
    });
  });
```

Add `Subject` to the rxjs import at the top of the spec:

```typescript
import { of, Subject } from 'rxjs';
```

- [ ] **Step 3: Run the tests to verify they fail**

Run: `cd frontend && npx jest reader-view.component --silent`
Expected: FAIL — no element with `aria-label="Reload article"`, and `refreshArticle`/`reload` wiring absent.

- [ ] **Step 4: Extract `runLoad()` and add `refreshArticle()` in the component**

In `reader-view.component.ts`:

Add `Observable` to the rxjs import and `ReaderContent` to the models import:

```typescript
import { Observable, Subscription, timeout } from 'rxjs';
```
```typescript
import { EntryDto, ReaderArticle, ReaderContent, SubscriptionTagDto } from '../models';
```

In the constructor's entry-change `effect`, replace the block from `this.loadSub?.unsubscribe();` (currently the line right after `this.loadedId = id;`) so the unsubscribe moves into the `!e` branch and the load path calls `runLoad`. The effect's tail becomes:

```typescript
      this.loadedId = id;
      this.readerMode.reset();
      this.cancelRestore();
      // A new article starts at the top, with a fresh, collapsed contents list
      // and its toolbar presented — opening another entry reuses this instance,
      // and a bar the previous article's reading retracted must not open the
      // next one headless.
      this.toc.set([]);
      this.tocOpen.set(false);
      this.heroError.set(false);
      this.showToTop.set(false);
      this.toolbarHidden.set(false);
      this.lastToolbarScrollTop = 0;
      this.scrollTop.set(0);
      if (!e) {
        this.loadSub?.unsubscribe();
        this.pendingRestore = null;
        this.state.set({ status: 'idle' });
        return;
      }
      // Arm a scroll restore for this entry if we remember a position for it.
      const savedTop = this.scroll.readEntry(e.id);
      this.pendingRestore = savedTop > 0 ? { id: e.id, top: savedTop } : null;
      this.runLoad(this.reader.load(e.id));
```

Add these two methods (place `runLoad` next to the load lifecycle, and `refreshArticle` near `toggleMode`):

```typescript
  /** Subscribe to a content source (initial load or a cache-busting reload),
   *  driving the shared loading → ok/failed lifecycle. The reader/original mode
   *  is not touched here, so a reload keeps the mode the reader chose; only a
   *  genuine entry change resets it (see the load effect above). */
  private runLoad(source: Observable<ReaderContent>): void {
    this.loadSub?.unsubscribe();
    this.state.set({ status: 'loading' });
    this.loadSub = source.pipe(timeout({ first: READER_LOAD_TIMEOUT_MS })).subscribe({
      next: (c) => {
        if (c.status === 'ok') {
          this.state.set({ status: 'ok', article: c });
          this.readerMode.enableToggle();
        } else {
          this.state.set({ status: 'failed' });
          this.readerMode.setOriginalOnly();
        }
      },
      error: () => {
        this.state.set({ status: 'failed' });
        this.readerMode.setOriginalOnly();
      },
    });
  }

  /** Drop the open article from the browser cache and refetch it. */
  refreshArticle(): void {
    const e = this.entry();
    if (!e) return;
    this.runLoad(this.reader.reload(e.id));
  }
```

- [ ] **Step 5: Update the toolbar template**

In `reader-view.component.html`, replace the `.nav` block (currently lines 51–86) with the following — the favorite/keep buttons lose the `@if (!fullscreen())` guard, and a refresh button is added between keep and the mode toggle:

```html
      <div class="nav">
        <button
          type="button"
          [attr.aria-label]="'reader.favorite' | transloco"
          [class.on]="e.isFavorite"
          [attr.aria-pressed]="e.isFavorite"
          (click)="favorite.emit()"
        >
          <app-icon name="star" size="md" />
        </button>
        <button
          type="button"
          [attr.aria-label]="'reader.keep' | transloco"
          [class.on]="e.isKept"
          [attr.aria-pressed]="e.isKept"
          (click)="keep.emit()"
        >
          <app-icon name="bookmark" size="md" />
        </button>
        <button
          type="button"
          [attr.aria-label]="'reader.refreshArticle' | transloco"
          (click)="refreshArticle()"
        >
          <app-icon name="refresh" size="md" />
        </button>
        @if (readerMode.canToggle()) {
          <button
            class="mode"
            type="button"
            [attr.aria-pressed]="mode() === 'reader'"
            [attr.aria-label]="
              (mode() === 'reader' ? 'reader.showOriginal' : 'reader.showReader') | transloco
            "
            (click)="toggleMode()"
          >
            <app-icon [name]="mode() === 'reader' ? 'article' : 'feed'" size="md" />
            {{ (mode() === 'reader' ? 'reader.readerView' : 'reader.original') | transloco }}
          </button>
        }
      </div>
```

- [ ] **Step 6: Run the tests to verify they pass**

Run: `cd frontend && npx jest reader-view.component --silent`
Expected: PASS — the reversed fullscreen test, the three refresh tests, and every pre-existing test.

- [ ] **Step 7: Run the full frontend gate**

Run: `cd frontend && npm run check`
Expected: PASS (ESLint + Prettier + Stylelint + Jest all green). Fix any Prettier 100-column or lint findings before continuing.

- [ ] **Step 8: Commit**

```bash
git add frontend/src/app/reader/reader-view/reader-view.component.ts \
        frontend/src/app/reader/reader-view/reader-view.component.html \
        frontend/src/app/reader/reader-view/reader-view.component.spec.ts \
        frontend/public/i18n/en.json frontend/public/i18n/de.json
git commit -m "feat(#470): kept/favorite on mobile and a per-article refresh button"
```

---

## Self-Review

**Spec coverage:**
- Unify toolbar (favorite/keep on mobile) → Task 3 Step 5 (guard removed) + reversed fullscreen test in Step 2. ✓
- Icon-only refresh button between keep and mode, shown whenever an entry is open, with `reader.refreshArticle` label → Task 3 Steps 1, 5, and the visibility test in Step 2. ✓
- `ReaderCacheService.delete` → Task 1. ✓
- `ReaderContentService.reload` (delete then refetch, re-cache ok, don't cache failure) → Task 2. ✓
- `refreshArticle()` cancels in-flight load, shows loading, reuses handlers via `runLoad`, preserves mode → Task 3 Step 4 + the loading-state and mode-preservation tests in Step 2. ✓
- No VERSION bump, frontend-only → nothing in any task touches the cache VERSION or backend. ✓

**Placeholder scan:** No TBD/TODO/"handle edge cases"; every code step carries the actual code. ✓

**Type consistency:** `delete(entryId: number): Promise<void>`, `reload(entryId: number): Observable<ReaderContent>`, `runLoad(source: Observable<ReaderContent>): void`, and `refreshArticle(): void` are used identically wherever referenced. The spec mock keys (`get`, `put`, `delete`) match the service methods; `load`/`reload` on the content-service mock match the component's calls. ✓
