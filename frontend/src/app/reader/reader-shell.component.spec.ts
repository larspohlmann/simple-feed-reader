import { TestBed } from '@angular/core/testing';
import { provideTranslocoTesting } from '../../testing/transloco-testing';
import { provideHttpClient } from '@angular/common/http';
import {
  HttpTestingController,
  TestRequest,
  provideHttpClientTesting,
} from '@angular/common/http/testing';
import {
  ActivatedRoute,
  NavigationStart,
  Router,
  Event as RouterEvent,
  convertToParamMap,
  provideRouter,
} from '@angular/router';
import { By } from '@angular/platform-browser';
import { BehaviorSubject, Subject, of } from 'rxjs';
import { signal } from '@angular/core';
import { API_BASE_URL } from '../core/api';
import { AuthService } from '../core/auth.service';
import { OnboardingSkip } from '../discover/onboarding-skip';
import { ReaderShellComponent } from './reader-shell.component';
import { EntryListComponent } from './entry-list/entry-list.component';
import { ListScrollMemory } from './list-scroll-memory';
import { EntryDto } from './models';
import { Selection } from './query';
import { ReaderHeaderComponent } from './header/reader-header.component';
import { headerHiddenAtRest } from './header-scroll';
import { RefreshService } from './refresh.service';
import { LayoutService } from './layout.service';
import { DrawerSwipeDirective } from './drawer-swipe.directive';
import { RecommendationsService } from './recommendations.service';
import { AiAvailabilityService } from '../core/ai-availability.service';

describe('ReaderShellComponent', () => {
  let ctrl: HttpTestingController;
  const qp = new BehaviorSubject(convertToParamMap({}));
  const auth = {
    user: signal({ email: 'a@b.c' }),
    loadMe: () => of({}),
    logout: jest.fn(),
    isAdmin: jest.fn().mockReturnValue(false),
  };

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
  const entry: EntryDto = {
    id: 1,
    title: 'e1',
    url: null,
    author: null,
    summary: 's',
    contentHtml: '<p>b</p>',
    imageUrl: null,
    imageWidth: null,
    imageHeight: null,
    publishedAt: '2026-07-22T11:00:00Z',
    createdAt: 'x',
    subscriptionId: 5,
    source: 'heise',
    faviconUrl: null,
    isRead: false,
    isFavorite: false,
    isKept: false,
    isViewed: false,
  };

  beforeEach(() => {
    sessionStorage.clear(); // OnboardingSkip persists here; don't leak across tests
    auth.isAdmin.mockReturnValue(false); // default non-admin; a test opting in overrides it
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

  function boot(entryOverride: Partial<typeof entry> = {}) {
    const f = TestBed.createComponent(ReaderShellComponent);
    f.detectChanges(); // ngOnInit + initial effects
    ctrl.expectOne('https://api.test/api/subscriptions').flush(subsBody);
    ctrl.expectOne('https://api.test/api/tags').flush({ tags: [] });
    ctrl
      .expectOne((r) => r.url === 'https://api.test/api/entries')
      .flush({ entries: [{ ...entry, ...entryOverride }], nextCursor: null });
    // resume() fires on init to pick up a run left in flight by an earlier
    // session; 'none' means there is nothing to resume.
    ctrl.expectOne('https://api.test/api/recommendations/runs/current').flush({
      status: 'none',
      batchesTotal: null,
      batchesDone: 0,
      error: null,
      background: false,
      streamedChars: 0,
      forYou: { itemCount: 0, generatedAt: null, newestRunId: null },
    });
    f.detectChanges();
    return f;
  }

  // One subscription row in the shape the shell reads. Overlay `id`/`lastFetchedAt`
  // per test to describe "fetched" vs "never fetched" feeds.
  const SUBSCRIPTION_FIXTURE = {
    id: 1,
    feedId: 11,
    title: 'The Verge',
    customTitle: null,
    lastFetchedAt: null as string | null,
    feedUrl: 'https://f/1',
    siteUrl: null,
    status: 'active',
    sourceFormat: 'xml',
    createdAt: 'x',
    tags: [],
    unreadCount: 0,
  };

  // Boot the shell against a CUSTOM subscriptions list, draining the three
  // requests it always fires (subscriptions, tags, entries) so a later
  // expectOne/expectNone on '/api/catalog' or '/api/refresh' is unambiguous.
  function bootWith(subscriptions: unknown[]) {
    const f = TestBed.createComponent(ReaderShellComponent);
    f.detectChanges();
    ctrl
      .expectOne('https://api.test/api/subscriptions')
      .flush({ subscriptions, favoritesCount: 0, keptCount: 0 });
    ctrl.expectOne('https://api.test/api/tags').flush({ tags: [] });
    ctrl
      .expectOne((r) => r.url === 'https://api.test/api/entries')
      .flush({ entries: [], nextCursor: null });
    ctrl.expectOne('https://api.test/api/recommendations/runs/current').flush({
      status: 'none',
      batchesTotal: null,
      batchesDone: 0,
      error: null,
      background: false,
      streamedChars: 0,
      forYou: { itemCount: 0, generatedAt: null, newestRunId: null },
    });
    f.detectChanges();
    return f;
  }

  const CATALOG_WITH_FEEDS = {
    categories: [
      {
        id: 1,
        key: 'technology',
        name: 'Technology',
        icon: 'memory',
        color: '#3b82f6',
        feeds: [
          {
            id: 10,
            title: 'The Verge',
            description: null,
            siteUrl: null,
            faviconUrl: '/f/10',
            subscribed: false,
          },
        ],
      },
    ],
  };

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

      // A residual downward scroll (100 → 500) delivered to the shell's
      // capture-phase scroll listener while the drawer is open.
      const rows = listScroller(f);
      rows.scrollTo(100);
      rows.scrollTo(500);
      f.detectChanges();

      expect(f.componentInstance.headerHidden()).toBe(false);
    });

    it('re-minimizes the header when the drawer closes over a scrolled-down list', () => {
      // #121 follow-up: opening forces the header back (so the drawer never hangs
      // below a retracted bar), but closing must not leave it expanded over
      // scrolled-down content. That top strip overlays the list yet isn't its
      // scroller, so a swipe starting there scrolls nothing — a dead zone.
      const f = boot();
      (f.componentInstance.screen as unknown as { isWide: () => boolean }).isWide = () => false;

      // Swipe up / scroll down: the header minimizes.
      const rows = listScroller(f);
      rows.scrollTo(100);
      rows.scrollTo(500);
      f.detectChanges();
      expect(f.componentInstance.headerHidden()).toBe(true);

      f.componentInstance.setSidebarOpen(true);
      f.detectChanges();
      expect(f.componentInstance.headerHidden()).toBe(false); // shown while open

      f.componentInstance.setSidebarOpen(false);
      f.detectChanges();
      // Back to the resting state the scroll offset implies — minimized.
      expect(f.componentInstance.headerHidden()).toBe(true);
    });

    it('stays shown while the header reports its own search bar open, even across a scroll', () => {
      // The bar holds the live term and, on a phone, the keyboard — sliding it
      // away under a scroll would hide the text the results depend on.
      const f = boot();
      (f.componentInstance.screen as unknown as { isWide: () => boolean }).isWide = () => false;
      const header = f.debugElement.query(By.directive(ReaderHeaderComponent))
        .componentInstance as ReaderHeaderComponent;

      header.searchOpen.set(true);
      f.detectChanges();
      expect(f.componentInstance.headerHidden()).toBe(false);

      const rows = listScroller(f);
      rows.scrollTo(100);
      rows.scrollTo(500);
      f.detectChanges();

      expect(f.componentInstance.headerHidden()).toBe(false);
    });

    it('returns to the resting state for the current offset once the search bar closes', () => {
      const f = boot();
      (f.componentInstance.screen as unknown as { isWide: () => boolean }).isWide = () => false;
      const header = f.debugElement.query(By.directive(ReaderHeaderComponent))
        .componentInstance as ReaderHeaderComponent;

      // Scroll down first so the resting state the bar returns to is minimized,
      // not just whatever the header happened to hold before opening.
      const rows = listScroller(f);
      rows.scrollTo(100);
      rows.scrollTo(500);
      f.detectChanges();
      expect(f.componentInstance.headerHidden()).toBe(true);

      header.searchOpen.set(true);
      f.detectChanges();
      expect(f.componentInstance.headerHidden()).toBe(false);

      header.searchOpen.set(false);
      f.detectChanges();
      expect(f.componentInstance.headerHidden()).toBe(true);
    });

    it('stays shown when the search bar closes while the drawer is still open', () => {
      // Both overlays open, then only search closes: the drawer alone is
      // still reason enough to keep the header shown. Two independent
      // force-show/resolve writers (one per overlay) would have each other's
      // state in the same "resting state" resolution, wrongly overwriting it.
      const f = boot();
      (f.componentInstance.screen as unknown as { isWide: () => boolean }).isWide = () => false;
      const header = f.debugElement.query(By.directive(ReaderHeaderComponent))
        .componentInstance as ReaderHeaderComponent;

      const rows = listScroller(f);
      rows.scrollTo(100);
      rows.scrollTo(500);
      f.detectChanges();
      expect(f.componentInstance.headerHidden()).toBe(true);

      f.componentInstance.setSidebarOpen(true);
      header.searchOpen.set(true);
      f.detectChanges();
      expect(f.componentInstance.headerHidden()).toBe(false);

      header.searchOpen.set(false);
      f.detectChanges();
      // The drawer is still open — the header must not retract under it.
      expect(f.componentInstance.headerHidden()).toBe(false);
    });

    it('stays shown when the drawer closes while the search bar is still open', () => {
      // The reverse order: search opens first, then the drawer opens and
      // closes (e.g. the edge-swipe gesture). The search bar alone is still
      // reason enough to keep the header shown.
      const f = boot();
      (f.componentInstance.screen as unknown as { isWide: () => boolean }).isWide = () => false;
      const header = f.debugElement.query(By.directive(ReaderHeaderComponent))
        .componentInstance as ReaderHeaderComponent;

      const rows = listScroller(f);
      rows.scrollTo(100);
      rows.scrollTo(500);
      f.detectChanges();
      expect(f.componentInstance.headerHidden()).toBe(true);

      header.searchOpen.set(true);
      f.componentInstance.setSidebarOpen(true);
      f.detectChanges();
      expect(f.componentInstance.headerHidden()).toBe(false);

      f.componentInstance.setSidebarOpen(false);
      f.detectChanges();
      // The search bar is still open — the header must not retract under it.
      expect(f.componentInstance.headerHidden()).toBe(false);
    });

    it('matches headerHiddenAtRest for the offset once both overlays are closed', () => {
      const f = boot();
      (f.componentInstance.screen as unknown as { isWide: () => boolean }).isWide = () => false;

      const rows = listScroller(f);
      rows.scrollTo(100);
      rows.scrollTo(500);
      f.detectChanges();

      expect(f.componentInstance.headerHidden()).toBe(headerHiddenAtRest(500, false));
    });
  });

  /** Drive the entry list's real scroll container: assign an offset and fire the
   *  scroll event the shell's capture-phase listener hears. The drawer-close
   *  restore reads this element's offset back, so tests must scroll the element
   *  the component actually consults, not a stand-in. */
  function listScroller(f: ReturnType<typeof boot>) {
    const el = (f.nativeElement as HTMLElement).querySelector<HTMLElement>('.rows')!;
    return {
      el,
      scrollTo(top: number): void {
        el.scrollTop = top;
        el.dispatchEvent(new Event('scroll'));
      },
    };
  }

  describe('returning from a full-screen article (#128)', () => {
    /** An extra scroller under the shell (the article overlay's, say), with its
     *  own coordinate space, heard by the same capture-phase listener. */
    function scroller(f: ReturnType<typeof boot>) {
      const el = document.createElement('div');
      let top = 0;
      Object.defineProperty(el, 'scrollTop', { get: () => top, configurable: true });
      (f.nativeElement as HTMLElement).appendChild(el);
      return {
        el,
        scrollTo(next: number): void {
          top = next;
          el.dispatchEvent(new Event('scroll'));
        },
      };
    }

    function bootNarrowScrolledDown() {
      const f = boot();
      (f.componentInstance.screen as unknown as { isWide: () => boolean }).isWide = () => false;
      const rows = listScroller(f);
      rows.scrollTo(100);
      rows.scrollTo(800);
      f.detectChanges();
      expect(f.componentInstance.headerHidden()).toBe(true);
      return { f, rows };
    }

    function openArticle(f: ReturnType<typeof boot>): void {
      qp.next(convertToParamMap({ entry: '1' }));
      f.detectChanges();
      ctrl.expectOne('https://api.test/api/entries/1/state').flush({
        state: {
          entryId: 1,
          isRead: true,
          isFavorite: false,
          isKept: false,
          readAt: 'x',
          isViewed: true,
          viewedAt: 'x',
        },
      });
      f.detectChanges();
      expect(f.componentInstance.articleFullscreen()).toBe(true);
    }

    function closeArticle(f: ReturnType<typeof boot>): void {
      qp.next(convertToParamMap({ entry: null }));
      f.detectChanges();
      expect(f.componentInstance.articleFullscreen()).toBe(false);
    }

    it('leaves the list bar alone across article open and close', () => {
      // The full-screen article is a layer above the whole list — bar
      // included — with its own toolbar. Opening and closing it must not
      // touch the bar's hide-on-scroll state: the list is revealed exactly
      // as it was left (#128).
      const { f } = bootNarrowScrolledDown();
      openArticle(f);
      expect(f.componentInstance.headerHidden()).toBe(true);

      closeArticle(f);
      expect(f.componentInstance.headerHidden()).toBe(true);
    });

    it('hears no scroller but the list', () => {
      // The bar used to be driven by a capture-phase listener on the shell,
      // which heard EVERY scroller underneath: the article overlay's (own
      // coordinate space — a cross-scroller delta read as a hard scroll-up)
      // and the header's horizontal tag row (whose re-snap after remount
      // reports scrollTop 0, satisfying the near-top show rule). It is driven
      // by the entry list's typed scrolled output now, so foreign scroll
      // events must change nothing (#128).
      const { f, rows } = bootNarrowScrolledDown();
      openArticle(f);

      const article = scroller(f);
      article.scrollTo(100);
      article.scrollTo(2000);
      const tagRowLike = scroller(f);
      tagRowLike.scrollTo(0); // horizontal snap: scrollTop stays 0
      f.detectChanges();
      expect(f.componentInstance.headerHidden()).toBe(true);

      closeArticle(f);
      rows.scrollTo(810); // a small further scroll DOWN on the list
      f.detectChanges();
      expect(f.componentInstance.headerHidden()).toBe(true);

      rows.scrollTo(300); // a real scroll UP still expands the header
      f.detectChanges();
      expect(f.componentInstance.headerHidden()).toBe(false);
    });

    it('restores the drawer-close header state from the list, not the article', () => {
      // setSidebarOpen(false) re-derives the header from "the" scroll offset.
      // After deep-scrolling an article, that offset must be the list's own —
      // here near the top, so the header must stay expanded.
      const f = boot();
      (f.componentInstance.screen as unknown as { isWide: () => boolean }).isWide = () => false;
      const rows = listScroller(f);
      rows.scrollTo(30);
      rows.scrollTo(10);
      f.detectChanges();
      expect(f.componentInstance.headerHidden()).toBe(false);

      openArticle(f);
      const article = scroller(f);
      article.scrollTo(100);
      article.scrollTo(2000);
      f.detectChanges();
      closeArticle(f);

      f.componentInstance.setSidebarOpen(true);
      f.detectChanges();
      f.componentInstance.setSidebarOpen(false);
      f.detectChanges();
      expect(f.componentInstance.headerHidden()).toBe(false);
    });
  });

  it('marks the opened entry read and viewed', () => {
    const f = boot();
    qp.next(convertToParamMap({ entry: '1' }));
    f.detectChanges();
    const req = ctrl.expectOne('https://api.test/api/entries/1/state');
    expect(req.request.body).toEqual({ isRead: true, isViewed: true });
    req.flush({
      state: {
        entryId: 1,
        isRead: true,
        isFavorite: false,
        isKept: false,
        readAt: 'x',
        isViewed: true,
        viewedAt: 'x',
      },
    });
    expect(f.nativeElement.querySelector('app-reader-view')).not.toBeNull();
  });

  it('marks the opened entry read and viewed only once even when the PATCH fails', () => {
    const f = boot();
    qp.next(convertToParamMap({ entry: '1' }));
    f.detectChanges();
    const req = ctrl.expectOne('https://api.test/api/entries/1/state');
    expect(req.request.body).toEqual({ isRead: true, isViewed: true });
    req.flush({ type: 'x', title: 't', status: 500 }, { status: 500, statusText: 'err' });
    f.detectChanges();
    // The entry is still unread/unviewed (rollback), but the effect must NOT
    // re-fire a PATCH.
    ctrl.expectNone((r) => r.url.endsWith('/entries/1/state'));
    ctrl.verify();
  });

  it('marks an already-read entry viewed on open', () => {
    const f = boot({ isRead: true });
    qp.next(convertToParamMap({ entry: '1' }));
    f.detectChanges();
    const req = ctrl.expectOne('https://api.test/api/entries/1/state');
    expect(req.request.body).toEqual({ isViewed: true });
    req.flush({
      state: {
        entryId: 1,
        isRead: true,
        isFavorite: false,
        isKept: false,
        readAt: 'x',
        isViewed: true,
        viewedAt: 'x',
      },
    });
  });

  it('does not re-mark an already-viewed entry on open', () => {
    const f = boot({ isRead: true, isViewed: true });
    qp.next(convertToParamMap({ entry: '1' }));
    f.detectChanges();
    ctrl.expectNone((r) => r.url.endsWith('/entries/1/state'));
    ctrl.verify();
  });

  it('marks the entry viewed when the original-article link is followed', () => {
    const f = boot({ isRead: true }); // open fires only the viewed patch…
    qp.next(convertToParamMap({ entry: '1' }));
    f.detectChanges();
    ctrl
      .expectOne('https://api.test/api/entries/1/state')
      .flush({ type: 'x', title: 't', status: 500 }, { status: 500, statusText: 'err' }); // …which fails and rolls back, so the link click is the real retry path.
    f.detectChanges();

    f.componentInstance.onOpenOriginal({ ...entry, isRead: true, isViewed: false });
    const req = ctrl.expectOne('https://api.test/api/entries/1/state');
    expect(req.request.body).toEqual({ isViewed: true });
  });

  // The two tests above/below drive onOpenOriginal directly and prove its own
  // logic (no-op once viewed, retry after rollback). Direct invocation cannot
  // prove the template actually wires the click to it — reader-shell.component.html
  // has TWO <app-reader-view> sites (the wide split-pane and the narrow
  // full-screen overlay), and nothing stops a future edit dropping the
  // `(openOriginal)` binding from either. These click through the real DOM on
  // each site so such a regression turns the test red.
  describe('the original-article link, through the real template wiring', () => {
    function clickOriginalLink(f: ReturnType<typeof boot>): void {
      const link = f.debugElement.query(By.css('app-reader-view a[target="_blank"]'));
      expect(link).not.toBeNull();
      link.triggerEventHandler('click', null);
      f.detectChanges();
    }

    it('marks viewed via the narrow full-screen overlay reader-view', () => {
      // Default test layout is narrow (isWide() is false), so the shell renders
      // the @else branch's overlay <app-reader-view> (reader-shell.component.html:157).
      const f = boot({ isRead: true, url: 'https://example.com/story' });
      qp.next(convertToParamMap({ entry: '1' }));
      f.detectChanges();
      // The on-open effect's own PATCH fails and rolls isViewed back to false,
      // so the link click below is the one exercising the wiring under test.
      ctrl
        .expectOne('https://api.test/api/entries/1/state')
        .flush({ type: 'x', title: 't', status: 500 }, { status: 500, statusText: 'err' });
      f.detectChanges();

      clickOriginalLink(f);

      const req = ctrl.expectOne('https://api.test/api/entries/1/state');
      expect(req.request.body).toEqual({ isViewed: true });
    });

    it('marks viewed via the wide split-pane reader-view', () => {
      // Force the wide split-pane layout so the shell renders the @if branch's
      // <app-reader-view> (reader-shell.component.html:112) instead of the overlay.
      const f = boot({ isRead: true, url: 'https://example.com/story' });
      (f.componentInstance.screen as unknown as { isWide: () => boolean }).isWide = () => true;
      f.componentInstance.layout.set('pane');
      qp.next(convertToParamMap({ entry: '1' }));
      f.detectChanges();
      expect(f.componentInstance.paneMode()).toBe(true);
      ctrl
        .expectOne('https://api.test/api/entries/1/state')
        .flush({ type: 'x', title: 't', status: 500 }, { status: 500, statusText: 'err' });
      f.detectChanges();

      clickOriginalLink(f);

      const req = ctrl.expectOne('https://api.test/api/entries/1/state');
      expect(req.request.body).toEqual({ isViewed: true });
    });
  });

  it('fetches a deep-linked entry that is not in the loaded list', () => {
    const f = boot(); // initial list holds only entry id 1
    qp.next(convertToParamMap({ entry: '514-deep-linked-story' }));
    f.detectChanges();

    // Not in the list → the shell fetches it by the id parsed from the slug.
    const req = ctrl.expectOne('https://api.test/api/entries/514');
    expect(req.request.method).toBe('GET');
    // isRead:true, isViewed:true so the on-open effect fires no state PATCH.
    req.flush({
      entry: { ...entry, id: 514, title: 'Deep linked story', isRead: true, isViewed: true },
    });
    f.detectChanges();

    expect(f.nativeElement.querySelector('app-reader-view')).not.toBeNull();
    ctrl.verify();
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

  it('loads the for-you view and titles the list for it', () => {
    const f = boot();
    qp.next(convertToParamMap({ view: 'for-you' }));
    f.detectChanges();
    const req = ctrl.expectOne((r) => r.url === 'https://api.test/api/entries');
    expect(req.request.params.get('view')).toBe('for-you');
    req.flush({ entries: [], nextCursor: null });
    f.detectChanges();

    expect(f.componentInstance.title()).toBe('For you');
  });

  it('titles a search selection with the translated term', () => {
    const f = boot();
    qp.next(convertToParamMap({ q: 'angular' }));
    f.detectChanges();
    ctrl
      .expectOne((r) => r.url === 'https://api.test/api/entries/search')
      .flush({ entries: [], nextCursor: null });
    f.detectChanges();

    expect(f.componentInstance.title()).toBe('Results for "angular"');
  });

  it('reloads the for-you list when a run completes while it is open', () => {
    const f = boot();
    qp.next(convertToParamMap({ view: 'for-you' }));
    f.detectChanges();
    ctrl
      .expectOne((r) => r.url === 'https://api.test/api/entries')
      .flush({ entries: [], nextCursor: null });
    f.detectChanges();

    TestBed.inject(RecommendationsService).completedStamp.update((n) => n + 1);
    f.detectChanges();

    const req = ctrl.expectOne((r) => r.url === 'https://api.test/api/entries');
    expect(req.request.params.get('view')).toBe('for-you');
    req.flush({ entries: [], nextCursor: null });
  });

  it('does not reload another list when a for-you run completes off-screen', () => {
    const f = boot();
    TestBed.inject(RecommendationsService).completedStamp.update((n) => n + 1);
    f.detectChanges();
    ctrl.expectNone((r) => r.url === 'https://api.test/api/entries');
  });

  // The run trigger lives in the list header now (#325), gated on AI being
  // ready — the same gate the sidebar's For You link uses — so a booted for-you
  // view marks readiness before it expects the button.
  function bootForYou() {
    const f = boot();
    TestBed.inject(AiAvailabilityService).apply({ ready: true, model: 'gpt' });
    qp.next(convertToParamMap({ view: 'for-you' }));
    f.detectChanges();
    ctrl
      .expectOne((r) => r.url === 'https://api.test/api/entries')
      .flush({ entries: [], nextCursor: null });
    f.detectChanges();
    return f;
  }

  const runningReport = {
    status: 'running' as const,
    batchesTotal: 3,
    batchesDone: 1,
    error: null,
    background: false,
    streamedChars: 0,
    elapsedSeconds: null,
    forYou: { itemCount: 0, generatedAt: null, newestRunId: null },
  };

  const failedReport = {
    status: 'failed' as const,
    batchesTotal: 3,
    batchesDone: 2,
    error: 'The AI provider at http://x/v1 failed: That provider answered with status 400.',
    background: false,
    streamedChars: 0,
    elapsedSeconds: null,
    forYou: { itemCount: 0, generatedAt: null, newestRunId: null },
  };

  function menuItem(text: string): HTMLElement {
    const item = [...document.querySelectorAll('[role="menuitem"]')].find((b) =>
      b.textContent?.includes(text),
    ) as HTMLElement | undefined;
    expect(item).not.toBeUndefined();
    return item!;
  }

  it('withholds the run button until AI is ready', () => {
    const f = boot();
    qp.next(convertToParamMap({ view: 'for-you' }));
    f.detectChanges();
    ctrl
      .expectOne((r) => r.url === 'https://api.test/api/entries')
      .flush({ entries: [], nextCursor: null });
    f.detectChanges();

    expect(f.nativeElement.querySelector('.for-you-run')).toBeNull();
  });

  it('shows the run button in the list header and starts a run only after the user confirms', () => {
    const f = bootForYou();
    const recs = TestBed.inject(RecommendationsService);

    const button = f.nativeElement.querySelector(
      '.list-header .for-you-run button',
    ) as HTMLButtonElement;
    expect(button).not.toBeNull();
    expect(button.textContent).toContain('Get recommendations');
    // No progress caption while idle — it belongs to a live run only.
    expect(f.nativeElement.querySelector('.for-you-progress')).toBeNull();

    // The click only opens the confirmation: nothing is requested until it is
    // accepted, because a run is long and spends provider budget.
    button.click();
    f.detectChanges();
    ctrl.expectNone('https://api.test/api/recommendations/runs');

    const confirm = document.querySelector('[data-testid="confirm"]') as HTMLButtonElement;
    expect(confirm).not.toBeNull();
    confirm.click();
    f.detectChanges();

    ctrl.expectOne('https://api.test/api/recommendations/runs').flush(runningReport);
    f.detectChanges();
    expect(recs.running()).toBe(true);
    ctrl.expectOne('https://api.test/api/recommendations/runs/tick').flush(runningReport);
  });

  it('starts no run when the confirmation is dismissed', () => {
    const f = bootForYou();

    (f.nativeElement.querySelector('.for-you-run button') as HTMLButtonElement).click();
    f.detectChanges();

    const cancel = [...document.querySelectorAll('app-button button')].find(
      (b) => b.textContent?.trim() === 'Cancel',
    ) as HTMLButtonElement;
    expect(cancel).not.toBeUndefined();
    cancel.click();
    f.detectChanges();

    ctrl.expectNone('https://api.test/api/recommendations/runs');
    expect(TestBed.inject(RecommendationsService).running()).toBe(false);
  });

  it('offers resume or start-over for a failed run, and resumes on choice', () => {
    const f = bootForYou();
    const recs = TestBed.inject(RecommendationsService);
    recs.report.set(failedReport);
    f.detectChanges();

    (f.nativeElement.querySelector('.for-you-run button') as HTMLButtonElement).click();
    f.detectChanges();

    // The plain confirm never opens; the choice sheet stands in for it, and
    // nothing is requested until the user picks.
    ctrl.expectNone('https://api.test/api/recommendations/runs');
    menuItem('Resume unfinished run').click();
    f.detectChanges();

    ctrl.expectOne('https://api.test/api/recommendations/runs/resume').flush(runningReport);
    f.detectChanges();
    expect(recs.running()).toBe(true);
    ctrl.expectOne('https://api.test/api/recommendations/runs/tick').flush(runningReport);
  });

  it('starts a fresh run when start-over is chosen over a failed run', () => {
    const f = bootForYou();
    TestBed.inject(RecommendationsService).report.set(failedReport);
    f.detectChanges();

    (f.nativeElement.querySelector('.for-you-run button') as HTMLButtonElement).click();
    f.detectChanges();

    menuItem('Start a new run').click();
    f.detectChanges();

    ctrl.expectOne('https://api.test/api/recommendations/runs').flush(runningReport);
    f.detectChanges();
    ctrl.expectOne('https://api.test/api/recommendations/runs/tick').flush(runningReport);
  });

  it('replaces the run button with a stop button while a run is in flight', () => {
    const f = bootForYou();
    const recs = TestBed.inject(RecommendationsService);
    recs.running.set(true);
    recs.report.set(runningReport);
    f.detectChanges();

    // Only the Stop button remains — starting a second run over a live one is
    // exactly what the single toggling slot prevents — with the batch count
    // beneath it and no failure alert clutter in the header (#325).
    const buttons = [...f.nativeElement.querySelectorAll('.for-you-run')];
    expect(buttons.length).toBe(1);
    expect(buttons[0].querySelector('.label')!.textContent!.trim()).toBe('Stop');
    const progress = f.nativeElement.querySelector('.for-you-progress') as HTMLElement;
    expect(progress.textContent).toContain('1 of 3');
    expect(f.nativeElement.querySelector('.list-header [role="alert"]')).toBeNull();
  });

  it('stops the run when the stop button is clicked', () => {
    const f = bootForYou();
    const recs = TestBed.inject(RecommendationsService);
    const stop = jest.spyOn(recs, 'stop');
    recs.running.set(true);
    recs.report.set(runningReport);
    f.detectChanges();

    (f.nativeElement.querySelector('.for-you-run button') as HTMLElement).click();

    expect(stop).toHaveBeenCalledTimes(1);
  });

  // The heading names the tag, so it also carries the tag's glyph and colour —
  // the same pair the sidebar row shows. Both come from one lookup, so the two
  // can never describe different tags.
  it('hands the list the selected tag, and nothing for any other selection', () => {
    const science = { id: 7, name: 'Wissenschaft', color: '#c2410c', icon: 'science', position: 0 };
    const f = TestBed.createComponent(ReaderShellComponent);
    f.detectChanges();
    ctrl.expectOne('https://api.test/api/subscriptions').flush(subsBody);
    ctrl.expectOne('https://api.test/api/tags').flush({ tags: [science] });
    ctrl
      .expectOne((r) => r.url === 'https://api.test/api/entries')
      .flush({ entries: [entry], nextCursor: null });
    ctrl.expectOne('https://api.test/api/recommendations/runs/current').flush({
      status: 'none',
      batchesTotal: null,
      batchesDone: 0,
      error: null,
      background: false,
      streamedChars: 0,
      forYou: { itemCount: 0, generatedAt: null, newestRunId: null },
    });
    f.detectChanges();

    const list = () =>
      f.debugElement.query(By.directive(EntryListComponent))
        .componentInstance as EntryListComponent;
    expect(list().titleTag()).toBeNull();

    qp.next(convertToParamMap({ tag: '7' }));
    f.detectChanges();
    ctrl.expectOne((r) => r.params.get('tag') === '7').flush({ entries: [], nextCursor: null });
    f.detectChanges();

    expect(list().titleTag()).toEqual(science);
    expect(f.componentInstance.title()).toBe('Wissenschaft');
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

  describe('onboarding redirect and first sweep', () => {
    it('redirects a user with no subscriptions to the picker, replacing the URL', async () => {
      const nav = jest.spyOn(TestBed.inject(Router), 'navigate').mockResolvedValue(true);
      const f = bootWith([]);
      await f.whenStable();
      ctrl.expectOne('https://api.test/api/catalog').flush(CATALOG_WITH_FEEDS);
      await f.whenStable();
      f.detectChanges();
      expect(nav).toHaveBeenCalledWith(['/discover'], { replaceUrl: true });
    });

    it('does not redirect when nobody has imported a catalog yet', async () => {
      const nav = jest.spyOn(TestBed.inject(Router), 'navigate').mockResolvedValue(true);
      const f = bootWith([]);
      await f.whenStable();
      ctrl.expectOne('https://api.test/api/catalog').flush({ categories: [] });
      await f.whenStable();
      f.detectChanges();
      expect(nav).not.toHaveBeenCalledWith(['/discover'], { replaceUrl: true });
    });

    it('does not redirect when the catalog cannot be loaded', async () => {
      const nav = jest.spyOn(TestBed.inject(Router), 'navigate').mockResolvedValue(true);
      const f = bootWith([]);
      await f.whenStable();
      ctrl
        .expectOne('https://api.test/api/catalog')
        .flush({ type: 'x', title: 't', status: 500 }, { status: 500, statusText: 'err' });
      await f.whenStable();
      f.detectChanges();
      expect(nav).not.toHaveBeenCalledWith(['/discover'], { replaceUrl: true });
    });

    it('does not even ask for the catalog when a non-admin user has subscriptions', () => {
      // Non-admin (the default mock): a populated reader has no reason to touch
      // the catalog. Admins DO fetch it unconditionally — covered separately below.
      bootWith([{ ...SUBSCRIPTION_FIXTURE, id: 1, lastFetchedAt: '2026-07-26T10:00:00+00:00' }]);
      ctrl.expectNone('https://api.test/api/catalog');
    });

    it('does not redirect when the user skipped this session, and does not fetch the catalog', async () => {
      TestBed.inject(OnboardingSkip).remember();
      const nav = jest.spyOn(TestBed.inject(Router), 'navigate').mockResolvedValue(true);
      const f = bootWith([]);
      await f.whenStable();
      ctrl.expectNone('https://api.test/api/catalog');
      expect(nav).not.toHaveBeenCalledWith(['/discover'], { replaceUrl: true });
    });

    it('sweeps once when subscriptions exist that have never been fetched', () => {
      const run = jest
        .spyOn(TestBed.inject(RefreshService), 'run')
        .mockImplementation(() => undefined);
      bootWith([
        { ...SUBSCRIPTION_FIXTURE, id: 1, lastFetchedAt: null },
        { ...SUBSCRIPTION_FIXTURE, id: 2, feedId: 12, lastFetchedAt: null },
      ]);
      expect(run).toHaveBeenCalledTimes(1);
    });

    it('does not sweep when every subscription has been fetched before', () => {
      const run = jest
        .spyOn(TestBed.inject(RefreshService), 'run')
        .mockImplementation(() => undefined);
      bootWith([{ ...SUBSCRIPTION_FIXTURE, id: 1, lastFetchedAt: '2026-07-26T10:00:00+00:00' }]);
      expect(run).not.toHaveBeenCalled();
    });

    it('shows the counted fetch banner while the onboarding sweep runs', () => {
      const f = bootWith([
        { ...SUBSCRIPTION_FIXTURE, id: 1, lastFetchedAt: null },
        { ...SUBSCRIPTION_FIXTURE, id: 2, feedId: 12, lastFetchedAt: null },
      ]);
      // The sweep fired a real refresh; a partial slice keeps it running, so the
      // counted banner shows this-much-done.
      ctrl
        .expectOne((r) => r.url === 'https://api.test/api/refresh')
        .flush({ ...refreshDone, status: 'partial', total: 2, remaining: 1, fetched: 1 });
      f.detectChanges();
      const banner = (f.nativeElement as HTMLElement).querySelector('.fetch-banner');
      expect(banner).not.toBeNull();
      expect(banner!.textContent).toContain('1 of 2');
      // The partial re-armed the poll; finish it so the sweep completes.
      ctrl.expectOne((r) => r.url === 'https://api.test/api/refresh').flush(refreshDone);
    });

    it('does not reshow the fetch banner on a later refresh once the sweep has landed', () => {
      const f = bootWith([{ ...SUBSCRIPTION_FIXTURE, id: 1, lastFetchedAt: null }]);
      // Complete the onboarding sweep successfully → the banner window closes.
      ctrl.expectOne((r) => r.url === 'https://api.test/api/refresh').flush(refreshDone);
      f.detectChanges();
      expect((f.nativeElement as HTMLElement).querySelector('.fetch-banner')).toBeNull();

      // A later manual refresh (the sidebar button) must NOT bring the counted
      // banner back over the now-populated reader — it belongs to the sweep only.
      f.componentInstance.onRefresh();
      f.detectChanges();
      ctrl.expectOne((r) => r.url === 'https://api.test/api/refresh').flush(refreshDone);
      f.detectChanges();
      expect((f.nativeElement as HTMLElement).querySelector('.fetch-banner')).toBeNull();
    });
  });

  describe('refresh failures', () => {
    /** Boot a reader whose feeds have all been fetched before -- so no
     *  onboarding sweep fires -- then refresh and answer that refresh with
     *  `respond`. Every test here therefore covers the ORDINARY refresh path:
     *  the sidebar button, a scoped refresh, add-feed. That path told the user
     *  nothing at all before #119, whatever went wrong. */
    const refreshAnsweredWith = (respond: (request: TestRequest) => void) => {
      const fixture = bootWith([
        { ...SUBSCRIPTION_FIXTURE, id: 1, lastFetchedAt: '2026-07-26T10:00:00+00:00' },
      ]);
      fixture.componentInstance.onRefresh();
      fixture.detectChanges();
      respond(ctrl.expectOne((r) => r.url === 'https://api.test/api/refresh'));
      fixture.detectChanges();
      return {
        fixture,
        banner: () => (fixture.nativeElement as HTMLElement).querySelector('.fetch-banner'),
      };
    };

    const serverError = (request: TestRequest) =>
      request.flush({ type: 'x', title: 't', status: 500 }, { status: 500, statusText: 'err' });

    it('tells the user a refresh failed, outside the onboarding sweep', () => {
      const { banner } = refreshAnsweredWith(serverError);

      expect(banner()?.textContent).toContain('Some feeds could not be fetched.');
    });

    // The whole point of the banner: the user must be able to act on it.
    it('refreshes again when the failure banner is retried', () => {
      const { fixture, banner } = refreshAnsweredWith(serverError);

      (banner()!.querySelector('button') as HTMLButtonElement).click();
      fixture.detectChanges();
      ctrl.expectOne((r) => r.url === 'https://api.test/api/refresh').flush(refreshDone);
      fixture.detectChanges();

      expect(banner()).toBeNull(); // a clean retry clears it
    });

    // An aborted sweep left feeds unfetched and still due. It shared the
    // `completed` branch, so it presented exactly like a clean run.
    it('says a sweep stopped early rather than showing it as finished', () => {
      const { banner } = refreshAnsweredWith((request) =>
        request.flush({ ...refreshDone, status: 'aborted', total: 10, remaining: 7, fetched: 3 }),
      );

      expect(banner()?.textContent).toContain('The refresh stopped early.');
    });

    it('marks the failure as an alert, not a status update', () => {
      const { banner } = refreshAnsweredWith((request) =>
        request.flush({ ...refreshDone, status: 'aborted', remaining: 4 }),
      );

      expect(banner()?.getAttribute('role')).toBe('alert');
    });

    it('stays silent when the refresh completes', () => {
      const { banner } = refreshAnsweredWith((request) => request.flush(refreshDone));

      expect(banner()).toBeNull();
    });
  });

  describe('admin empty-catalog warning', () => {
    it('warns an admin that no catalog has been imported', async () => {
      auth.isAdmin.mockReturnValue(true);
      const f = bootWith([
        { ...SUBSCRIPTION_FIXTURE, id: 1, lastFetchedAt: '2026-07-26T10:00:00+00:00' },
      ]);
      await f.whenStable();
      // An admin gets the catalog fetched EVEN WITH their own subscriptions — the
      // loadCatalogForAdmin effect, not the redirect (which returns on non-empty subs).
      ctrl.expectOne('https://api.test/api/catalog').flush({ categories: [] });
      f.detectChanges();
      const warning = (f.nativeElement as HTMLElement).querySelector(
        '[data-testid="catalog-empty-warning"]',
      );
      expect(warning).not.toBeNull();
      expect(warning!.querySelector('a')!.getAttribute('href')).toBe('/settings/admin/catalog');
    });

    it('shows an admin no warning once a catalog exists', async () => {
      auth.isAdmin.mockReturnValue(true);
      const f = bootWith([
        { ...SUBSCRIPTION_FIXTURE, id: 1, lastFetchedAt: '2026-07-26T10:00:00+00:00' },
      ]);
      await f.whenStable();
      ctrl.expectOne('https://api.test/api/catalog').flush(CATALOG_WITH_FEEDS);
      f.detectChanges();
      expect(
        (f.nativeElement as HTMLElement).querySelector('[data-testid="catalog-empty-warning"]'),
      ).toBeNull();
    });

    it('never shows the warning to a non-admin', async () => {
      const nav = jest.spyOn(TestBed.inject(Router), 'navigate').mockResolvedValue(true);
      const f = bootWith([]);
      await f.whenStable();
      // The redirect effect (empty subs, non-admin) is what fetches the catalog here.
      ctrl.expectOne('https://api.test/api/catalog').flush({ categories: [] });
      await f.whenStable();
      f.detectChanges();
      expect(
        (f.nativeElement as HTMLElement).querySelector('[data-testid="catalog-empty-warning"]'),
      ).toBeNull();
      expect(nav).not.toHaveBeenCalledWith(['/discover'], { replaceUrl: true });
    });
  });

  describe('list scroll reset', () => {
    // The rule itself is proved in list-scroll-reset.spec.ts. This proves the
    // shell starts it, which is the one thing that file cannot show: without the
    // constructor call nothing listens and the offset survives the click (#286).
    it('drops the offset of a list the user clicks', () => {
      const allUnread: Selection = { kind: 'all', id: null, unread: true };
      bootWith([SUBSCRIPTION_FIXTURE]);
      const memory = TestBed.inject(ListScrollMemory);
      const events = TestBed.inject(Router).events as Subject<RouterEvent>;

      events.next(new NavigationStart(1, '/?tag=5', 'imperative'));
      memory.save(allUnread, 300);
      events.next(new NavigationStart(2, '/', 'imperative'));

      expect(memory.read(allUnread)).toBe(0);
    });
  });

  describe('drawer breakpoint driven by class, not media query', () => {
    beforeEach(() => {
      TestBed.resetTestingModule();
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
      TestBed.overrideProvider(LayoutService, {
        useValue: { isNarrow: signal(true), isWide: signal(false), isCoarse: signal(true) },
      });
    });

    it('adds is-narrow to .body when isNarrow is true and removes it when false', () => {
      const narrow = TestBed.inject(LayoutService)
        .isNarrow as import('@angular/core').Signal<boolean>;
      sessionStorage.clear();
      auth.isAdmin.mockReturnValue(false);
      qp.next(convertToParamMap({}));
      const c = TestBed.inject(HttpTestingController);
      const f = TestBed.createComponent(ReaderShellComponent);
      f.detectChanges();
      c.match(() => true).forEach((req) =>
        req.flush({
          subscriptions: [],
          tags: [],
          entries: [],
          favoritesCount: 0,
          keptCount: 0,
          nextCursor: null,
        }),
      );
      f.detectChanges();

      const body = (f.nativeElement as HTMLElement).querySelector('.body')!;
      expect(body.classList).toContain('is-narrow');
      (narrow as import('@angular/core').WritableSignal<boolean>).set(false);
      f.detectChanges();
      expect(body.classList).not.toContain('is-narrow');
    });
  });

  describe('sidebar organising', () => {
    beforeEach(() => {
      TestBed.resetTestingModule();
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
      TestBed.overrideProvider(LayoutService, {
        useValue: { isNarrow: signal(true), isWide: signal(false), isCoarse: signal(true) },
      });
    });

    it('pauses the drawer swipe while organising', () => {
      const c = TestBed.inject(HttpTestingController);
      const f = TestBed.createComponent(ReaderShellComponent);
      f.detectChanges();
      c.match(() => true).forEach((req) =>
        req.flush({
          subscriptions: [],
          tags: [],
          entries: [],
          favoritesCount: 0,
          keptCount: 0,
          nextCursor: null,
        }),
      );
      f.detectChanges();

      const swipe = f.debugElement
        .query(By.directive(DrawerSwipeDirective))
        .injector.get(DrawerSwipeDirective);
      expect(swipe.disabled()).toBe(false);

      f.componentInstance.sidebarOrganising.set(true);
      f.detectChanges();
      expect(swipe.disabled()).toBe(true);
    });

    it('resets organising when the drawer closes', () => {
      const c = TestBed.inject(HttpTestingController);
      const f = TestBed.createComponent(ReaderShellComponent);
      f.detectChanges();
      c.match(() => true).forEach((req) =>
        req.flush({
          subscriptions: [],
          tags: [],
          entries: [],
          favoritesCount: 0,
          keptCount: 0,
          nextCursor: null,
        }),
      );
      f.componentInstance.setSidebarOpen(true);
      f.componentInstance.sidebarOrganising.set(true);
      f.componentInstance.setSidebarOpen(false);
      expect(f.componentInstance.sidebarOrganising()).toBe(false);
    });
  });
});
