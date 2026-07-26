import { TestBed } from '@angular/core/testing';
import { provideTranslocoTesting } from '../../testing/transloco-testing';
import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { ActivatedRoute, convertToParamMap, provideRouter } from '@angular/router';
import { By } from '@angular/platform-browser';
import { BehaviorSubject, of } from 'rxjs';
import { signal } from '@angular/core';
import { API_BASE_URL } from '../core/api';
import { AuthService } from '../core/auth.service';
import { ReaderShellComponent } from './reader-shell.component';
import { EntryListComponent } from './entry-list/entry-list.component';
import { ReaderHeaderComponent } from './header/reader-header.component';

describe('ReaderShellComponent', () => {
  let ctrl: HttpTestingController;
  const qp = new BehaviorSubject(convertToParamMap({}));
  const auth = { user: signal({ email: 'a@b.c' }), loadMe: () => of({}), logout: jest.fn() };

  const subsBody = {
    subscriptions: [
      {
        id: 5,
        feedId: 55,
        title: 'heise',
        customTitle: null,
        lastFetchedAt: '2026-07-22T10:00:00Z',
        feedUrl: 'https://f/5',
        siteUrl: null,
        status: 'active',
        sourceFormat: 'xml',
        createdAt: 'x',
        tags: [],
        unreadCount: 2,
      },
    ],
  };
  const entry = {
    id: 1,
    title: 'e1',
    url: null,
    author: null,
    summary: 's',
    contentHtml: '<p>b</p>',
    publishedAt: '2026-07-22T11:00:00Z',
    createdAt: 'x',
    subscriptionId: 5,
    source: 'heise',
    isRead: false,
    isFavorite: false,
    isKept: false,
  };

  beforeEach(() => {
    qp.next(convertToParamMap({}));
    TestBed.configureTestingModule({
      imports: [ReaderShellComponent, provideTranslocoTesting()],
      providers: [
        provideHttpClient(),
        provideHttpClientTesting(),
        provideRouter([]),
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
    ctrl.expectOne('https://api.test/api/tags').flush({ tags: [] });
    ctrl
      .expectOne((r) => r.url === 'https://api.test/api/entries')
      .flush({
        entries: [entry],
        nextCursor: null,
      });
    f.detectChanges();
    return f;
  }

  it('renders header + sidebar and loads the initial list', () => {
    const el = boot().nativeElement as HTMLElement;
    expect(el.querySelector('app-reader-header')).not.toBeNull();
    expect(el.querySelector('app-sidebar')!.textContent).toContain('heise');
    // The shell's default layout is 'magazine'; the single loaded entry renders
    // as some magazine block (the first entry leads as a hero). Assert the list
    // mounted and rendered a block rather than pinning the exact tier, which is
    // planner-tuning-dependent.
    expect(el.querySelector('app-entry-list')).not.toBeNull();
    expect(el.querySelector('app-entry-hero, app-entry-compact, app-entry-row')).not.toBeNull();
  });

  // #87: the header used to be pulled out of view with a negative margin-top,
  // which re-ran layout and resized the scroller under the user's finger. It is
  // an overlay now, so hiding it must change the header and nothing else.
  describe('hide-on-scroll header', () => {
    it('publishes a bar height that does not move when the bar does', () => {
      const f = boot();
      const el = f.nativeElement as HTMLElement;
      // jsdom has no ResizeObserver, so nothing measures the header; stand in
      // for the measurement to exercise everything that depends on it.
      f.componentInstance.headerHeight.set(90);
      f.detectChanges();
      expect(el.style.getPropertyValue('--app-bar-h')).toBe('90px');
      expect(el.style.getPropertyValue('--app-bar-shift')).toBe('0px');

      f.componentInstance.headerHidden.set(true);
      f.detectChanges();
      // The reservation is unchanged — only the shift moves. That is the fix:
      // the panes' geometry cannot depend on whether the bar is showing.
      expect(el.style.getPropertyValue('--app-bar-h')).toBe('90px');
      expect(el.style.getPropertyValue('--app-bar-shift')).toBe('-90px');
      expect(el.querySelector('app-reader-header')!.classList).toContain('hidden');
      // The old mechanism, and the whole bug: no margin may be involved.
      expect(el.querySelector<HTMLElement>('app-reader-header')!.style.marginTop).toBe('');
    });

    it('shows the header again when the mobile drawer opens', () => {
      // The drawer hangs below the bar, so opening it under a retracted header
      // would leave a strip of backdrop where the bar belongs.
      const f = boot();
      f.componentInstance.headerHidden.set(true);
      f.componentInstance.setSidebarOpen(true);
      f.detectChanges();
      expect(f.componentInstance.headerHidden()).toBe(false);
      expect(f.componentInstance.sidebarOpen()).toBe(true);
    });

    it('keeps the header shown when momentum scroll fires after the drawer opens', () => {
      // #121: the open-swipe's touchend shows the header, but inertial scrolling
      // keeps firing scroll events afterwards. One arriving under the open drawer
      // must not re-hide the header — the drawer would then hang below a gap.
      const f = boot();
      // Only a narrow layout hides the header at all; force it so the residual
      // scroll would otherwise register as a hide.
      (f.componentInstance.screen as unknown as { isWide: () => boolean }).isWide = () => false;
      f.componentInstance.headerHeight.set(90);
      f.componentInstance.setSidebarOpen(true);
      f.detectChanges();
      expect(f.componentInstance.headerHidden()).toBe(false);

      // A residual downward scroll (0 → 500) delivered to the shell's
      // capture-phase scroll listener while the drawer is open.
      const host = f.nativeElement as HTMLElement;
      const scroller = document.createElement('div');
      Object.defineProperty(scroller, 'scrollTop', { value: 500, configurable: true });
      host.appendChild(scroller);
      scroller.dispatchEvent(new Event('scroll'));
      f.detectChanges();

      expect(f.componentInstance.headerHidden()).toBe(false);
    });
  });

  it('marks the opened entry read', () => {
    const f = boot();
    qp.next(convertToParamMap({ entry: '1' }));
    f.detectChanges();
    const req = ctrl.expectOne('https://api.test/api/entries/1/state');
    expect(req.request.body).toEqual({ isRead: true });
    req.flush({
      state: { entryId: 1, isRead: true, isFavorite: false, isKept: false, readAt: 'x' },
    });
    expect(f.nativeElement.querySelector('app-reader-view')).not.toBeNull();
  });

  it('marks the opened entry read only once even when the PATCH fails', () => {
    const f = boot();
    qp.next(convertToParamMap({ entry: '1' }));
    f.detectChanges();
    const req = ctrl.expectOne('https://api.test/api/entries/1/state');
    expect(req.request.body).toEqual({ isRead: true });
    req.flush({ type: 'x', title: 't', status: 500 }, { status: 500, statusText: 'err' });
    f.detectChanges();
    // The entry is still unread (rollback), but the effect must NOT re-fire a PATCH.
    ctrl.expectNone((r) => r.url.endsWith('/entries/1/state'));
    ctrl.verify();
  });

  it('fetches a deep-linked entry that is not in the loaded list', () => {
    const f = boot(); // initial list holds only entry id 1
    qp.next(convertToParamMap({ entry: '514-deep-linked-story' }));
    f.detectChanges();

    // Not in the list → the shell fetches it by the id parsed from the slug.
    const req = ctrl.expectOne('https://api.test/api/entries/514');
    expect(req.request.method).toBe('GET');
    // isRead:true so the mark-on-open effect fires no extra state PATCH.
    req.flush({ entry: { ...entry, id: 514, title: 'Deep linked story', isRead: true } });
    f.detectChanges();

    expect(f.nativeElement.querySelector('app-reader-view')).not.toBeNull();
  });

  it('ignores a stale cold-entry fetch that resolves after navigating to another', () => {
    const f = boot();
    // Open cold entry A (not in the list), then jump to cold entry B before A resolves.
    qp.next(convertToParamMap({ entry: '514-a' }));
    f.detectChanges();
    const reqA = ctrl.expectOne('https://api.test/api/entries/514');
    qp.next(convertToParamMap({ entry: '600-b' }));
    f.detectChanges();
    const reqB = ctrl.expectOne('https://api.test/api/entries/600');

    // B resolves first (now open), then A resolves LATE — A must not clobber B.
    reqB.flush({ entry: { ...entry, id: 600, title: 'Entry B', isRead: true } });
    f.detectChanges();
    reqA.flush({ entry: { ...entry, id: 514, title: 'Entry A', isRead: true } });
    f.detectChanges();

    // The list stays mounted beneath the article overlay, so scope to the reader.
    expect(f.nativeElement.querySelector('app-reader-view .title')?.textContent).toContain(
      'Entry B',
    );
  });

  const refreshDone = {
    status: 'completed',
    total: 0,
    fetched: 0,
    notModified: 0,
    failed: 0,
    skippedForBudget: 0,
    remaining: 0,
    pruned: 0,
  };

  it('scopes an all-items refresh to nothing (sweeps every due feed)', () => {
    const f = boot();
    f.componentInstance.onScopedRefresh();
    const req = ctrl.expectOne((r) => r.url === 'https://api.test/api/refresh');
    expect(req.request.params.has('feedId')).toBe(false);
    expect(req.request.params.has('tag')).toBe(false);
    req.flush(refreshDone);
  });

  it('scopes a tag refresh to the tag id', () => {
    const f = boot();
    qp.next(convertToParamMap({ tag: '3' }));
    f.detectChanges();
    ctrl
      .expectOne((r) => r.url === 'https://api.test/api/entries')
      .flush({ entries: [], nextCursor: null });
    f.componentInstance.onScopedRefresh();
    const req = ctrl.expectOne((r) => r.url === 'https://api.test/api/refresh');
    expect(req.request.params.get('tag')).toBe('3');
    req.flush(refreshDone);
  });

  it('scopes a subscription refresh to the underlying feed id', () => {
    const f = boot();
    qp.next(convertToParamMap({ subscription: '5' }));
    f.detectChanges();
    ctrl
      .expectOne((r) => r.url === 'https://api.test/api/entries')
      .flush({ entries: [], nextCursor: null });
    f.componentInstance.onScopedRefresh();
    const req = ctrl.expectOne((r) => r.url === 'https://api.test/api/refresh');
    // The subscription's real feed id (55), not the subscription id (5).
    expect(req.request.params.get('feedId')).toBe('55');
    req.flush(refreshDone);
  });

  it('does not refresh from the cross-feed saved views', () => {
    const f = boot();
    qp.next(convertToParamMap({ view: 'favorites' }));
    f.detectChanges();
    ctrl
      .expectOne((r) => r.url === 'https://api.test/api/entries')
      .flush({ entries: [], nextCursor: null });
    f.componentInstance.onScopedRefresh();
    ctrl.expectNone((r) => r.url === 'https://api.test/api/refresh');
  });

  it('reloads entries when the selection changes', () => {
    const f = boot();
    qp.next(convertToParamMap({ subscription: '5' }));
    f.detectChanges();
    ctrl
      .expectOne((r) => r.params.get('subscription') === '5')
      .flush({ entries: [], nextCursor: null });
    f.detectChanges();
    expect(f.nativeElement.querySelector('.empty')).not.toBeNull();
  });

  it('forwards the header tap to the entry list', () => {
    const f = boot();
    const list = f.debugElement.query(By.directive(EntryListComponent))
      .componentInstance as EntryListComponent;
    const jump = jest.spyOn(list, 'scrollToTop').mockImplementation(() => undefined);

    const header = f.debugElement.query(By.directive(ReaderHeaderComponent))
      .componentInstance as ReaderHeaderComponent;
    header.scrollTop.emit();

    expect(jump).toHaveBeenCalledTimes(1);
  });
});
