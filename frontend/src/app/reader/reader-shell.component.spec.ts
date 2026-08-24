import { TestBed } from '@angular/core/testing';
import { Dialog } from '@angular/cdk/dialog';
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
import { By, Title } from '@angular/platform-browser';
import { BehaviorSubject, Subject, of } from 'rxjs';
import { WritableSignal, signal } from '@angular/core';
import { API_BASE_URL } from '../core/api';
import { AuthService } from '../core/auth.service';
import { LanguageService } from '../core/language.service';
import { OnboardingSkip } from '../discover/onboarding-skip';
import { ReaderShellComponent } from './reader-shell.component';
import { EntryListComponent } from './entry-list/entry-list.component';
import { ListScrollMemory } from './list-scroll-memory';
import { EntryDto, SavedSearchDto } from './models';
import { SubscriptionsStore } from './subscriptions.store';
import { Selection } from './query';
import { ReaderHeaderComponent } from './header/reader-header.component';
import { headerHiddenAtRest } from './header-scroll';
import { RefreshService } from './refresh.service';
import { LayoutService } from './layout.service';
import { ReadingLayout } from './reading-layout.service';
import { ManageActions } from './manage/manage-actions.service';
import { TagsStore } from './tags.store';
import { DrawerSwipeDirective } from './drawer-swipe.directive';
import { RecommendationsService } from './recommendations.service';
import { AiAvailabilityService } from '../core/ai-availability.service';
import { ToastService } from '../shared/toast/toast.service';

describe('ReaderShellComponent', () => {
  let screen: {
    isNarrow: WritableSignal<boolean>;
    isWide: WritableSignal<boolean>;
    isCoarse: WritableSignal<boolean>;
  };
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
    localStorage.clear(); // LanguageService caches the chosen lang here — a de test must not leak into the next
    auth.isAdmin.mockReturnValue(false); // default non-admin; a test opting in overrides it
    qp.next(convertToParamMap({}));
    // Provided rather than left to the real service: jsdom's matchMedia answers
    // "no" to every query, so the real one is stuck on the wide layout and a
    // test about a phone-only surface has no way to say so. The defaults below
    // reproduce exactly what jsdom used to give.
    screen = { isNarrow: signal(false), isWide: signal(false), isCoarse: signal(false) };
    TestBed.configureTestingModule({
      imports: [ReaderShellComponent, provideTranslocoTesting()],
      providers: [
        provideHttpClient(),
        provideHttpClientTesting(),
        provideRouter([]),
        { provide: API_BASE_URL, useValue: 'https://api.test' },
        { provide: ActivatedRoute, useValue: { queryParamMap: qp.asObservable() } },
        { provide: AuthService, useValue: auth },
        { provide: LayoutService, useValue: screen },
      ],
    });
    ctrl = TestBed.inject(HttpTestingController);
  });

  function boot(entryOverride: Partial<typeof entry> = {}) {
    const f = TestBed.createComponent(ReaderShellComponent);
    f.detectChanges(); // ngOnInit + initial effects
    ctrl.expectOne('https://api.test/api/subscriptions').flush(subsBody);
    ctrl.expectOne('https://api.test/api/tags').flush({ tags: [] });
    ctrl.expectOne('https://api.test/api/saved-searches').flush({ savedSearches: [] });
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
    // The sidebar's update badge: the shell checks once on load. No release to
    // report here, so no badge — the request just has to be drained.
    ctrl.expectOne('https://api.test/api/version').flush({
      version: 'dev',
      commit: 'local',
      builtAt: '',
      latest: null,
      updateAvailable: false,
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
    ctrl.expectOne('https://api.test/api/saved-searches').flush({ savedSearches: [] });
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
    // The sidebar's update badge: the shell checks once on load. No release to
    // report here, so no badge — the request just has to be drained.
    ctrl.expectOne('https://api.test/api/version').flush({
      version: 'dev',
      commit: 'local',
      builtAt: '',
      latest: null,
      updateAvailable: false,
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
    // as some magazine block. Assert the list mounted and rendered a block rather
    // than pinning the exact tier, which is planner-tuning-dependent.
    expect(el.querySelector('app-entry-list')).not.toBeNull();
    expect(
      el.querySelector(
        'app-entry-hero, app-entry-wide, app-entry-quote, app-entry-split, ' +
          'app-entry-kicker, app-entry-thumb, app-entry-compact',
      ),
    ).not.toBeNull();
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
      // The trigger that sets searchOpen true only ever renders on a narrow
      // layout (#408): patch isNarrow alongside isWide so the header's own
      // "close on layout growth" effect doesn't immediately undo the line below.
      (f.componentInstance.screen as unknown as { isNarrow: () => boolean }).isNarrow = () => true;
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
      (f.componentInstance.screen as unknown as { isNarrow: () => boolean }).isNarrow = () => true;
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
      (f.componentInstance.screen as unknown as { isNarrow: () => boolean }).isNarrow = () => true;
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
      (f.componentInstance.screen as unknown as { isNarrow: () => boolean }).isNarrow = () => true;
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
    expect(f.nativeElement.querySelector('app-reader-view')).not.toBeNull();
  });

  it('marks the opened entry read and viewed only once even when the PATCH fails', () => {
    const f = boot();
    qp.next(convertToParamMap({ entry: '1' }));
    f.detectChanges();
    const req = ctrl.expectOne('https://api.test/api/entries/1/state');
    expect(req.request.body).toEqual({ isViewed: true });
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

  describe('one scoped refresh reloads the list once (#502)', () => {
    it('fires exactly one entries reload and one tags reload after the run finishes', () => {
      const f = boot();

      f.componentInstance.onScopedRefresh();

      // The refresh sweep itself.
      ctrl.expectOne((r) => r.url === 'https://api.test/api/refresh').flush(refreshDone);
      f.detectChanges();

      // Exactly one reload of each list-backing resource — the double reload
      // (slice effect + onDone) would make entries match twice here.
      // ctrl.match() removes what it finds from the open-request queue, so the
      // counts are captured once and reused below to drain them — matching
      // again would find nothing and throw.
      const entriesReloads = ctrl.match((r) => r.url === 'https://api.test/api/entries');
      const tagsReloads = ctrl.match((r) => r.url === 'https://api.test/api/tags');
      const subsReloads = ctrl.match((r) => r.url === 'https://api.test/api/subscriptions');
      const savedSearchesReloads = ctrl.match(
        (r) => r.url === 'https://api.test/api/saved-searches',
      );
      expect(entriesReloads.length).toBe(1);
      expect(tagsReloads.length).toBe(1);
      expect(subsReloads.length).toBe(1);
      expect(savedSearchesReloads.length).toBe(1);

      // Drain the reload requests so verify() is clean.
      entriesReloads[0].flush({ entries: [], nextCursor: null });
      tagsReloads[0].flush({ tags: [] });
      subsReloads[0].flush(subsBody);
      savedSearchesReloads[0].flush({ savedSearches: [] });
      ctrl.verify();
    });
  });

  describe('onboarding sweep still fills progressively (#502)', () => {
    it('reloads the list on each landing slice, not only at the end', () => {
      // All subscriptions never fetched → awaitingFirstFetch() is true → the shell
      // fires the post-onboarding sweep itself (sweeping() is true for its span).
      const f = bootWith([{ ...SUBSCRIPTION_FIXTURE, lastFetchedAt: null }]);

      // The sweep's own refresh request.
      const refresh = ctrl.expectOne((r) => r.url === 'https://api.test/api/refresh');

      // First slice: partial, more feeds still due → the list must reload now.
      // RefreshService.step() re-fires the next /api/refresh synchronously from
      // inside this flush, so it is already queued below alongside the reload.
      refresh.flush({ ...refreshDone, status: 'partial', total: 2, fetched: 1, remaining: 1 });
      f.detectChanges();
      const firstSliceEntries = ctrl.match((r) => r.url === 'https://api.test/api/entries');
      expect(firstSliceEntries.length).toBe(1);
      firstSliceEntries[0].flush({ entries: [], nextCursor: null });
      // subs reload per slice; tags do not (they reload once at finish), so only
      // the subscriptions request is drained here.
      ctrl
        .match((r) => r.url === 'https://api.test/api/subscriptions')
        .forEach((req) =>
          req.flush({
            subscriptions: [{ ...SUBSCRIPTION_FIXTURE, lastFetchedAt: null }],
            favoritesCount: 0,
            keptCount: 0,
          }),
        );
      // Tags must NOT reload on a partial slice — a refresh never touches them,
      // so they reload once at the finish, not once per sweep slice (#502).
      expect(ctrl.match((r) => r.url === 'https://api.test/api/tags').length).toBe(0);

      // Second slice: the sweep's poll loop re-fires /api/refresh on its own;
      // finishing it reloads again — proof the first reload was not the only one.
      const next = ctrl.expectOne((r) => r.url === 'https://api.test/api/refresh');
      next.flush({ ...refreshDone, total: 2, fetched: 2 });
      f.detectChanges();

      // The finishing slice reloads once more (entries + subs + tags). match()
      // consumes the open queue, so it is called once and the same array is
      // flushed; an entries request being among them proves the first slice's
      // reload was not the only one.
      const finishReloads = ctrl.match(() => true);
      expect(finishReloads.some((req) => req.request.url.endsWith('/api/entries'))).toBe(true);
      // Tags reload exactly once, here at the finish — never on the partial slice above.
      expect(finishReloads.filter((req) => req.request.url.endsWith('/api/tags')).length).toBe(1);
      finishReloads.forEach((req) =>
        req.flush({
          entries: [],
          nextCursor: null,
          subscriptions: [{ ...SUBSCRIPTION_FIXTURE, lastFetchedAt: '2026-08-21T00:00:00Z' }],
          favoritesCount: 0,
          keptCount: 0,
          tags: [],
        }),
      );
      ctrl.verify();
    });
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

  it('offers an edit action in the list header for the selected feed', () => {
    const f = boot();
    const edit = jest.spyOn(TestBed.inject(ManageActions), 'editSubscription');
    qp.next(convertToParamMap({ subscription: '5' }));
    f.detectChanges();
    ctrl
      .expectOne((r) => r.url === 'https://api.test/api/entries')
      .flush({ entries: [], nextCursor: null });
    f.detectChanges();

    const button = f.nativeElement.querySelector('.list-header .list-edit') as HTMLButtonElement;
    expect(button).not.toBeNull();
    button.click();

    // The whole subscription, not just its id: the dialog edits the feed the
    // sidebar's own menu edits, through the same service.
    expect(edit).toHaveBeenCalledWith(expect.objectContaining({ id: 5 }));
  });

  it('offers the same edit action for the selected tag', () => {
    const f = boot();
    const edit = jest.spyOn(TestBed.inject(ManageActions), 'editTag');
    // The header's glyph and its edit action both read the tag out of the
    // tree, so the tag has to exist there for either to appear.
    TestBed.inject(TagsStore).tags.set([
      { id: 3, name: 'Tech', color: null, icon: null, position: 0 },
    ]);
    qp.next(convertToParamMap({ tag: '3' }));
    f.detectChanges();
    ctrl
      .expectOne((r) => r.url === 'https://api.test/api/entries')
      .flush({ entries: [], nextCursor: null });
    f.detectChanges();

    (f.nativeElement.querySelector('.list-header .list-edit') as HTMLButtonElement).click();
    expect(edit).toHaveBeenCalledWith(expect.objectContaining({ id: 3 }));
  });

  it('leaves the slot empty for a selection that edits nothing', () => {
    const f = boot();
    qp.next(convertToParamMap({ view: 'favorites' }));
    f.detectChanges();
    ctrl
      .expectOne((r) => r.url === 'https://api.test/api/entries')
      .flush({ entries: [], nextCursor: null });
    f.detectChanges();

    expect(f.nativeElement.querySelector('.list-header .list-edit')).toBeNull();
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

  // The reader route declares DYNAMIC_TITLE, which tells the title strategy to
  // stand back — so if the reader ever stopped naming the tab, nothing else
  // would, and #549 would be back through the door built for the reader.
  it('names the browser tab after the list on screen', () => {
    const f = boot();
    f.detectChanges();

    expect(TestBed.inject(Title).getTitle()).toBe('All items | simple feed reader');
  });

  it('names the browser tab after the open article, cut to what a tab shows', () => {
    const headline = 'A headline far longer than any browser tab has ever been able to show';
    const f = boot();
    qp.next(convertToParamMap({ entry: '514' }));
    f.detectChanges();
    ctrl
      .expectOne((r) => r.url === 'https://api.test/api/entries/514')
      .flush({ entry: { ...entry, id: 514, title: headline } });
    f.detectChanges();

    expect(TestBed.inject(Title).getTitle()).toBe(`${headline.slice(0, 60)}… | simple feed reader`);
  });

  // The heading used to hold hardcoded English literals, so a German user saw
  // "All items"/"Favorites" while the sidebar row beside it was translated. It
  // now reuses the sidebar's own keys and reacts to a language switch (#411).
  describe('translated heading (#411)', () => {
    it('titles the default list with the translated all-items label', () => {
      const f = boot();
      expect(f.componentInstance.title()).toBe('All items');

      TestBed.inject(LanguageService).set('de');
      f.detectChanges();

      // The crux of #411: TranslocoService.translate() is one-shot, so without a
      // language signal in the computed's dependency graph the heading would
      // freeze on the English string a switch never revisits.
      expect(f.componentInstance.title()).toBe('Alle Einträge');
    });

    it('titles the favorites list with the translated label', () => {
      const f = boot();
      TestBed.inject(LanguageService).set('de');
      qp.next(convertToParamMap({ view: 'favorites' }));
      f.detectChanges();
      ctrl
        .expectOne((r) => r.url === 'https://api.test/api/entries')
        .flush({ entries: [], nextCursor: null });
      f.detectChanges();

      expect(f.componentInstance.title()).toBe('Favoriten');
    });
  });

  describe('searching (#408 follow-up)', () => {
    // The spinner must appear ONLY for a search selection whose list is
    // actually in flight — entries.loading() alone is true for every list
    // load, so all four combinations are pinned to guard against a spinner
    // that lights up for an unrelated feed load.
    it('is false for a non-search selection while its list is not loading', () => {
      const f = boot();
      expect(f.componentInstance.searching()).toBe(false);
    });

    it('is false for a non-search selection while its list IS loading', () => {
      const f = boot();
      qp.next(convertToParamMap({ tag: '9' }));
      f.detectChanges();

      expect(f.componentInstance.selection().kind).toBe('tag');
      expect(f.componentInstance.entries.loading()).toBe(true);
      expect(f.componentInstance.searching()).toBe(false);

      ctrl
        .expectOne((r) => r.url === 'https://api.test/api/entries')
        .flush({
          entries: [],
          nextCursor: null,
        });
    });

    it('is false for a search selection once its list has finished loading', () => {
      const f = boot();
      qp.next(convertToParamMap({ q: 'angular' }));
      f.detectChanges();
      ctrl
        .expectOne((r) => r.url === 'https://api.test/api/entries/search')
        .flush({ entries: [], nextCursor: null });
      f.detectChanges();

      expect(f.componentInstance.searching()).toBe(false);
    });

    it('is true for a search selection while its list IS loading', () => {
      const f = boot();
      qp.next(convertToParamMap({ q: 'angular' }));
      f.detectChanges();

      expect(f.componentInstance.selection().kind).toBe('search');
      expect(f.componentInstance.entries.loading()).toBe(true);
      expect(f.componentInstance.searching()).toBe(true);

      ctrl
        .expectOne((r) => r.url === 'https://api.test/api/entries/search')
        .flush({ entries: [], nextCursor: null });
    });

    // The regression this section exists to close: typing past the first
    // search term must reload the list. `selection`'s equality check once
    // reimplemented `sameSelection` inline and forgot `term`, so two search
    // selections compared equal, the computed never produced a new
    // reference, and the reload effect (which depends on `selection()`)
    // never re-ran for a second search in the same session.
    it('reloads the list when the search term changes (#408 follow-up)', () => {
      const f = boot();
      qp.next(convertToParamMap({ q: 'daft' }));
      f.detectChanges();
      ctrl
        .expectOne(
          (r) => r.url === 'https://api.test/api/entries/search' && r.params.get('q') === 'daft',
        )
        .flush({ entries: [{ ...entry, id: 1, title: 'daft' }], nextCursor: null });
      f.detectChanges();

      expect(f.componentInstance.entries.entries().map((e) => e.id)).toEqual([1]);

      qp.next(convertToParamMap({ q: 'daft punk' }));
      f.detectChanges();

      // A second request for the new term must actually go out — this is
      // the assertion that catches the bug: with the stale comparator, no
      // request fires here at all, and the entries array (and the title
      // built from it) stay frozen on the first search's result.
      const secondRequest = ctrl.expectOne(
        (r) => r.url === 'https://api.test/api/entries/search' && r.params.get('q') === 'daft punk',
      );
      secondRequest.flush({ entries: [{ ...entry, id: 2, title: 'daft punk' }], nextCursor: null });
      f.detectChanges();

      expect(f.componentInstance.entries.entries().map((e) => e.id)).toEqual([2]);
      expect(f.componentInstance.title()).toContain('daft punk');
    });

    it('does not reload the list for an entry-only URL change (original comparator intent)', () => {
      const f = boot();
      qp.next(convertToParamMap({ q: 'daft punk' }));
      f.detectChanges();
      ctrl
        .expectOne((r) => r.url === 'https://api.test/api/entries/search')
        .flush({ entries: [{ ...entry, id: 2 }], nextCursor: null });
      f.detectChanges();

      // Opening an entry changes only the `entry` param, not the selection —
      // no second list request must fire.
      qp.next(convertToParamMap({ q: 'daft punk', entry: '2-daft-punk' }));
      f.detectChanges();

      ctrl.expectNone((r) => r.url === 'https://api.test/api/entries/search');
    });

    it('treats two search selections with the same term as the same selection, and a different term as different', () => {
      const f = boot();
      qp.next(convertToParamMap({ q: 'daft' }));
      f.detectChanges();
      const first = f.componentInstance.selection();
      ctrl
        .expectOne((r) => r.url === 'https://api.test/api/entries/search')
        .flush({ entries: [], nextCursor: null });
      f.detectChanges();

      // Same params again (e.g. a re-emit with no real change) must not be a
      // new selection reference's worth of behaviour.
      qp.next(convertToParamMap({ q: 'daft' }));
      f.detectChanges();
      expect(f.componentInstance.selection()).toBe(first);
      ctrl.expectNone((r) => r.url === 'https://api.test/api/entries/search');

      qp.next(convertToParamMap({ q: 'daft punk' }));
      f.detectChanges();
      expect(f.componentInstance.selection()).not.toBe(first);
      ctrl
        .expectOne((r) => r.url === 'https://api.test/api/entries/search')
        .flush({ entries: [], nextCursor: null });
    });

    it('distinguishes a search selection from a non-search selection sharing kind/id/unread', () => {
      // A search selection always has kind 'search', id null, unread false —
      // the same triple every non-search "all items" selection has too. The
      // comparator must still tell them apart via `term`.
      const f = boot();
      qp.next(convertToParamMap({}));
      f.detectChanges();
      expect(f.componentInstance.selection().kind).toBe('all');

      qp.next(convertToParamMap({ q: 'daft punk' }));
      f.detectChanges();

      expect(f.componentInstance.selection().kind).toBe('search');
      ctrl
        .expectOne((r) => r.url === 'https://api.test/api/entries/search')
        .flush({ entries: [], nextCursor: null });
    });

    it('strips the trailing whole-word-mode space from the title shown to the user', () => {
      const f = boot();
      qp.next(convertToParamMap({ q: 'daft ' }));
      f.detectChanges();
      ctrl
        .expectOne(
          (r) => r.url === 'https://api.test/api/entries/search' && r.params.get('q') === 'daft ',
        )
        .flush({ entries: [{ ...entry, id: 1 }], nextCursor: null });
      f.detectChanges();

      expect(f.componentInstance.title()).not.toContain('daft "');
      expect(f.componentInstance.title()).toContain('"daft"');
    });
  });

  describe('selection query params (#408 follow-up)', () => {
    // Opening/closing an article must NOT go through selectionQueryParams: it
    // does not change which list is shown, so `q` (and any other selection
    // param) must survive both the open and the close, letting a Back from an
    // article opened out of search results land back on those results.
    it('keeps q when opening an article', () => {
      const nav = jest.spyOn(TestBed.inject(Router), 'navigate').mockResolvedValue(true);
      const f = boot();

      f.componentInstance.onOpen(entry);

      const queryParams = nav.mock.calls[0][1]?.queryParams as Record<string, unknown>;
      expect(queryParams).not.toHaveProperty('q');
    });

    it('keeps q when closing an article', () => {
      const nav = jest.spyOn(TestBed.inject(Router), 'navigate').mockResolvedValue(true);
      const f = boot();

      f.componentInstance.onCloseReader();

      const queryParams = nav.mock.calls[0][1]?.queryParams as Record<string, unknown>;
      expect(queryParams).not.toHaveProperty('q');
    });

    it('clears q along with the rest when adding a feed selects its subscription (#408)', () => {
      const nav = jest.spyOn(TestBed.inject(Router), 'navigate').mockResolvedValue(true);
      const f = boot();
      qp.next(convertToParamMap({ q: 'angular' }));
      f.detectChanges();
      ctrl
        .expectOne((r) => r.url === 'https://api.test/api/entries/search')
        .flush({ entries: [], nextCursor: null });

      const ref = { closed: of({ id: 9, lastFetchedAt: 'x' }) };
      jest.spyOn(TestBed.inject(Dialog), 'open').mockReturnValue(ref as never);
      f.componentInstance.onAddFeed();
      ctrl.expectOne('https://api.test/api/subscriptions').flush(subsBody);

      expect(nav).toHaveBeenCalledWith(
        [],
        expect.objectContaining({
          queryParams: { view: null, tag: null, subscription: 9, entry: null, q: null },
        }),
      );
      ctrl.match(() => true).forEach((r) => r.flush({ entries: [], nextCursor: null }));
    });

    it('layers a search over the current list, keeping view/tag/subscription in the URL to return to (#542)', () => {
      const nav = jest.spyOn(TestBed.inject(Router), 'navigate').mockResolvedValue(true);
      const f = boot();

      f.componentInstance.onSearch('angular');

      expect(nav).toHaveBeenCalledWith(
        [],
        expect.objectContaining({
          queryParams: { q: 'angular', entry: null },
          queryParamsHandling: 'merge',
        }),
      );
    });

    it("onSearch('') drops only the search, so closing it returns to the list it was started from rather than All items (#542)", () => {
      const nav = jest.spyOn(TestBed.inject(Router), 'navigate').mockResolvedValue(true);
      const f = boot();

      f.componentInstance.onSearch('');

      expect(nav).toHaveBeenCalledWith(
        [],
        expect.objectContaining({
          queryParams: { q: null, entry: null },
          queryParamsHandling: 'merge',
        }),
      );
    });
  });

  it(
    'shows no count for a search that is loading, even though entries() still ' +
      'holds the PREVIOUS list rows (#254 stale-list regression, fix round 2)',
    () => {
      const f = boot();
      // Establish the fixture deliberately: boot() has already landed one row
      // from the 'all' selection's list, and that row is still mounted — this
      // is exactly the #254 behaviour (load() clears nextCursor synchronously
      // but leaves the outgoing list rendered until the response lands).
      expect(f.componentInstance.entries.entries().length).toBe(1);

      qp.next(convertToParamMap({ q: 'angular' }));
      f.detectChanges();

      // The search request is now in flight. Prove the trap is live before
      // asserting the title: the stale row from 'all' is still all
      // entries() has, and hasMore() reads false because nextCursor was
      // already cleared — a naive count/hasMore read here would show
      // "— 1" for a term that has not answered yet.
      expect(f.componentInstance.entries.entries().length).toBe(1);
      expect(f.componentInstance.hasMore()).toBe(false);
      expect(f.componentInstance.searching()).toBe(true);

      expect(f.componentInstance.title()).toBe('Results for "angular"');

      ctrl
        .expectOne((r) => r.url === 'https://api.test/api/entries/search')
        .flush({ entries: [], nextCursor: null });
    },
  );

  it('titles a search selection with the translated term and the exact loaded count when there is no further page', () => {
    const f = boot();
    qp.next(convertToParamMap({ q: 'angular' }));
    f.detectChanges();
    ctrl
      .expectOne((r) => r.url === 'https://api.test/api/entries/search')
      .flush({ entries: [entry, { ...entry, id: 2 }], nextCursor: null });
    f.detectChanges();

    expect(f.componentInstance.title()).toBe('Results for "angular" — 2');
  });

  it('titles a settled search with zero results as the exact count, not the loading form', () => {
    const f = boot();
    qp.next(convertToParamMap({ q: 'angular' }));
    f.detectChanges();
    ctrl
      .expectOne((r) => r.url === 'https://api.test/api/entries/search')
      .flush({ entries: [], nextCursor: null });
    f.detectChanges();

    expect(f.componentInstance.searching()).toBe(false);
    expect(f.componentInstance.title()).toBe('Results for "angular" — 0');
  });

  it('titles a search selection with a trailing + when another page exists', () => {
    const f = boot();
    qp.next(convertToParamMap({ q: 'angular' }));
    f.detectChanges();
    ctrl
      .expectOne((r) => r.url === 'https://api.test/api/entries/search')
      .flush({ entries: [entry], nextCursor: 'cursor-2' });
    f.detectChanges();

    expect(f.componentInstance.title()).toBe('Results for "angular" — 1+');
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
    // The header never carries the progress caption: it is the pill's, on
    // every route, running or not (#398).
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
    // The count, the ETA and the bar left the LIST header in #398 and never
    // came back; a live run leaves nothing but the Stop button there. On this
    // (wide) layout they read out from the app bar instead of the pill (#435).
    expect(f.nativeElement.querySelector('.list-header .for-you-progress')).toBeNull();
    expect(f.nativeElement.querySelector('app-reader-header .for-you-progress')).not.toBeNull();
    expect(f.nativeElement.querySelector('.list-header [role="alert"]')).toBeNull();
  });

  it('offers a way back to the pill only once the pill has been closed', () => {
    // A phone-layout concern: above the drawer breakpoint the app bar carries
    // the run and there is no ✕, so there is nothing to offer back (#435).
    screen.isNarrow.set(true);
    const f = bootForYou();
    const recs = TestBed.inject(RecommendationsService);
    const toast = TestBed.inject(ToastService);
    recs.running.set(true);
    recs.report.set(runningReport);
    toast.show({ message: 'stand-in for the run pill' });
    f.detectChanges();

    // Nothing to restore while it is on screen.
    expect(f.nativeElement.querySelector('.for-you-show')).toBeNull();

    toast.dismiss();
    f.detectChanges();

    const restore = f.nativeElement.querySelector('.for-you-show button') as HTMLButtonElement;
    expect(restore).not.toBeNull();

    const raise = jest.spyOn(recs, 'showRunPill');
    restore.click();
    expect(raise).toHaveBeenCalledTimes(1);
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
          savedSearches: [],
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
          savedSearches: [],
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
          savedSearches: [],
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

  describe('collapsing a row out of a saved view (#478)', () => {
    // Boot the shell straight into a given view with one entry in a chosen state,
    // draining the four requests every boot fires.
    function bootInto(view: string, entryOverride: Partial<EntryDto>) {
      qp.next(convertToParamMap({ view }));
      const f = TestBed.createComponent(ReaderShellComponent);
      f.detectChanges();
      ctrl
        .expectOne('https://api.test/api/subscriptions')
        .flush({ ...subsBody, favoritesCount: 3, keptCount: 3, viewedCount: 3 });
      ctrl.expectOne('https://api.test/api/tags').flush({ tags: [] });
      ctrl
        .expectOne((r) => r.url === 'https://api.test/api/entries')
        .flush({ entries: [{ ...entry, ...entryOverride }], nextCursor: null });
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

    function flushStatePatch() {
      ctrl.expectOne((r) => r.url === 'https://api.test/api/entries/1/state').flush({ state: {} });
    }

    it('collapses an un-favourited row but keeps it in the data so the plan holds', () => {
      const f = bootInto('favorites', { isFavorite: true });
      f.componentInstance.onFavorite(f.componentInstance.entries.entries()[0]);
      flushStatePatch();

      expect(f.componentInstance.leavingIds().has(1)).toBe(true);
      // Kept in entries() on purpose: dropping it would re-flow the magazine plan.
      expect(f.componentInstance.entries.entries().some((e) => e.id === 1)).toBe(true);
    });

    it('does NOT collapse the row when the flag is toggled outside its saved view', () => {
      const f = bootInto('all', { isFavorite: true });
      f.componentInstance.onFavorite(f.componentInstance.entries.entries()[0]);
      flushStatePatch();

      expect(f.componentInstance.leavingIds().has(1)).toBe(false);
    });

    it('collapses a Recently-read row on un-tick and drops the viewed badge', () => {
      const f = bootInto('viewed', { isRead: true, isViewed: true });
      const subs = TestBed.inject(SubscriptionsStore);
      expect(subs.viewedCount()).toBe(3);

      f.componentInstance.onToggleViewed(f.componentInstance.entries.entries()[0]);
      flushStatePatch();

      expect(f.componentInstance.leavingIds().has(1)).toBe(true);
      expect(subs.viewedCount()).toBe(2);
    });

    it('un-collapses the row and restores the badge when the PATCH fails', () => {
      const f = bootInto('viewed', { isRead: true, isViewed: true });
      const subs = TestBed.inject(SubscriptionsStore);

      f.componentInstance.onToggleViewed(f.componentInstance.entries.entries()[0]);
      ctrl
        .expectOne((r) => r.url === 'https://api.test/api/entries/1/state')
        .error(new ProgressEvent('fail'));

      expect(f.componentInstance.leavingIds().has(1)).toBe(false);
      expect(subs.viewedCount()).toBe(3);
    });

    it('clears the collapsed set when the selection changes', () => {
      const f = bootInto('favorites', { isFavorite: true });
      f.componentInstance.onFavorite(f.componentInstance.entries.entries()[0]);
      flushStatePatch();
      expect(f.componentInstance.leavingIds().has(1)).toBe(true);

      qp.next(convertToParamMap({ view: 'kept' }));
      f.detectChanges();
      ctrl
        .expectOne((r) => r.url === 'https://api.test/api/entries')
        .flush({ entries: [], nextCursor: null });

      expect(f.componentInstance.leavingIds().size).toBe(0);
    });
  });

  describe('feed intro (#568)', () => {
    // Boots the shell with one subscription carrying the given intro fields
    // and selects it, so `selectedSubscription()`/`feedIntroSubscription()`
    // see it rather than the empty list `boot()` starts with.
    function mountWithSubscriptionSelected(
      overrides: {
        description: string | null;
        imageUrl: string | null;
        siteUrl: string | null;
      },
      layout: ReadingLayout = 'magazine',
    ): HTMLElement {
      const f = bootWith([{ ...SUBSCRIPTION_FIXTURE, ...overrides }]);
      f.componentInstance.layout.set(layout);
      const id = String(SUBSCRIPTION_FIXTURE.id);
      qp.next(convertToParamMap({ subscription: id }));
      f.detectChanges();
      ctrl
        .expectOne((r) => r.params.get('subscription') === id)
        .flush({ entries: [], nextCursor: null });
      f.detectChanges();
      return f.nativeElement as HTMLElement;
    }

    // Drives the shell to the given selection kind through the same query
    // params the URL would carry, draining the entries request the change
    // triggers. 'all' is boot()'s own starting selection, so it needs none.
    // Resets `qp` first: this runs inside a loop over several kinds, and
    // `qp` is the shared BehaviorSubject the whole file mounts against — left
    // at a previous iteration's params, the NEXT boot() would read a stale
    // selection (e.g. still 'search') on init and fire the wrong request.
    function mountWithSelectionKind(kind: string): HTMLElement {
      qp.next(convertToParamMap({}));
      const f = boot();
      if (kind === 'all') return f.nativeElement as HTMLElement;

      const params =
        kind === 'tag' ? { tag: '7' } : kind === 'search' ? { q: 'angular' } : { view: kind };
      qp.next(convertToParamMap(params));
      f.detectChanges();
      const url =
        kind === 'search' ? 'https://api.test/api/entries/search' : 'https://api.test/api/entries';
      ctrl.expectOne((r) => r.url === url).flush({ entries: [], nextCursor: null });
      f.detectChanges();
      return f.nativeElement as HTMLElement;
    }

    it('shows the feed intro at the top of the list for a single-feed selection', () => {
      const host = mountWithSubscriptionSelected({
        description: 'A feed about things.',
        imageUrl: 'https://example.com/logo.png',
        siteUrl: 'https://example.com/',
      });

      expect(host.querySelector('app-feed-intro')).not.toBeNull();
    });

    it('shows no feed intro in the list layout', () => {
      // The block is a member of the magazine column — it takes that column's
      // measure and left edge. The list layout has no such measure, so the same
      // block would be a wide slab sitting on top of dense rows.
      const host = mountWithSubscriptionSelected(
        {
          description: 'A feed about things.',
          imageUrl: 'https://example.com/logo.png',
          siteUrl: 'https://example.com/',
        },
        'list',
      );

      expect(host.querySelector('app-feed-intro')).toBeNull();
    });

    it('shows no feed intro for the aggregated and saved views', () => {
      for (const view of ['all', 'tag', 'search', 'favorites', 'kept', 'viewed', 'for-you']) {
        const host = mountWithSelectionKind(view);
        expect(host.querySelector('app-feed-intro')).toBeNull();
      }
    });

    it('shows no feed intro for a feed that has none of the three values', () => {
      const host = mountWithSubscriptionSelected({
        description: null,
        imageUrl: null,
        siteUrl: null,
      });

      expect(host.querySelector('app-feed-intro')).toBeNull();
    });
  });

  describe('mark all read for a search (#581)', () => {
    // A search selects a `SelectionKind` that markReadTarget() maps to a
    // 'search' scope, so canMarkAllRead() (and thus the header button) turns
    // on without any change to the entry-list component.
    function bootWithSearchSelected() {
      const f = boot();
      qp.next(convertToParamMap({ q: 'climate ' }));
      f.detectChanges();
      ctrl
        .expectOne((r) => r.url === 'https://api.test/api/entries/search')
        .flush({ entries: [], nextCursor: null });
      f.detectChanges();
      return f;
    }

    it('calls the search mark-read endpoint with the term verbatim, then reloads entries, subscriptions and saved searches', () => {
      const f = bootWithSearchSelected();
      const ref = { closed: of(true) };
      jest.spyOn(TestBed.inject(Dialog), 'open').mockReturnValue(ref as never);

      f.componentInstance.onMarkAllRead();

      const req = ctrl.expectOne('https://api.test/api/entries/search/mark-read');
      expect(req.request.method).toBe('POST');
      // The trailing space is the whole-word-match signal the backend reads
      // via SearchTerms::fromInput; it must reach the request body unchanged.
      expect(req.request.body).toEqual({ q: 'climate ', until: expect.any(String) });
      req.flush(null);

      ctrl
        .expectOne((r) => r.url === 'https://api.test/api/entries/search')
        .flush({ entries: [], nextCursor: null });
      ctrl.expectOne('https://api.test/api/subscriptions').flush(subsBody);
      ctrl.expectOne('https://api.test/api/saved-searches').flush({ savedSearches: [] });
    });

    it('does nothing when the dialog is cancelled', () => {
      const f = bootWithSearchSelected();
      const ref = { closed: of(false) };
      jest.spyOn(TestBed.inject(Dialog), 'open').mockReturnValue(ref as never);

      f.componentInstance.onMarkAllRead();

      ctrl.expectNone('https://api.test/api/entries/search/mark-read');
    });
  });

  // The Save/Remove control is a shell command rendered through the list's
  // `headerActions` outlet, so the list emits nothing and the shell owns both
  // the decision and the button. One toggle, not two one-way actions.
  describe('saving the current search (#581)', () => {
    // boot() has already drained the shell's own saved-searches load, so seed
    // the store directly — what the button reads is the store, not the request.
    function bootWithSearchSelected(saved: SavedSearchDto[], q = 'climate ') {
      const f = boot();
      f.componentInstance.savedSearchesStore.savedSearches.set(saved);
      qp.next(convertToParamMap({ q }));
      f.detectChanges();
      ctrl
        .expectOne((r) => r.url === 'https://api.test/api/entries/search')
        .flush({ entries: [], nextCursor: null });
      f.detectChanges();
      return f;
    }

    const savedClimate: SavedSearchDto = {
      id: 4,
      term: 'climate',
      wholeWord: true,
      position: 0,
      unreadCount: 2,
    };

    it('saves the decoded term and whole-word flag, and adopts the response without reloading the list', () => {
      const f = bootWithSearchSelected([]);

      f.componentInstance.onToggleSavedSearch();

      const req = ctrl.expectOne('https://api.test/api/saved-searches');
      expect(req.request.method).toBe('POST');
      // The trailing space is the whole-word signal; it is decoded to the pair
      // the backend stores, never sent verbatim as the term.
      expect(req.request.body).toEqual({ term: 'climate', wholeWord: true });
      req.flush({ savedSearch: savedClimate });

      // The POST already answered with the row and its count — no re-fetch.
      ctrl.expectNone('https://api.test/api/saved-searches');
      expect(f.componentInstance.savedSearchesStore.savedSearches()).toEqual([savedClimate]);
    });

    it('removes the saved search when the current one is already saved, and drops it locally', () => {
      const f = bootWithSearchSelected([savedClimate]);
      expect(f.componentInstance.currentSavedSearch()).toEqual(savedClimate);

      f.componentInstance.onToggleSavedSearch();

      const req = ctrl.expectOne('https://api.test/api/saved-searches/4');
      expect(req.request.method).toBe('DELETE');
      req.flush(null);

      ctrl.expectNone('https://api.test/api/saved-searches');
      expect(f.componentInstance.savedSearchesStore.savedSearches()).toEqual([]);
    });

    it('matches a saved search by its decoded pair, not by the raw term string', () => {
      // A no-break space is a whole-word signal to the decoder but never equals
      // a plain trailing space, which is what a string comparison would need.
      const f = bootWithSearchSelected([savedClimate], 'climate\u00a0');

      expect(f.componentInstance.currentSavedSearch()).toEqual(savedClimate);
    });
  });
});
