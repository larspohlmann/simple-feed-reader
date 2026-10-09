import { ComponentFixture, TestBed } from '@angular/core/testing';
import { Dialog } from '@angular/cdk/dialog';
import { OverlayContainer } from '@angular/cdk/overlay';
import { provideTranslocoTesting } from '../../../testing/transloco-testing';
import { HttpErrorResponse, provideHttpClient } from '@angular/common/http';
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
import { BehaviorSubject, Subject, of, throwError } from 'rxjs';
import { Provider, WritableSignal, signal } from '@angular/core';
import { API_BASE_URL } from '../../core/api';
import { AuthService } from '../../core/auth/auth.service';
import { LanguageService } from '../../core/i18n/language.service';
import { TokenStore } from '../../core/auth/token.store';
import { OnboardingSkip } from '../feeds/catalog/onboarding-skip';
import { ReaderShellComponent } from './reader-shell.component';
import { ListHeaderComponent } from '../list/list-header/list-header.component';
import { EntryListComponent } from '../list/entry-list/entry-list.component';
import { ListScrollMemory } from '../scroll/list-scroll-memory';
import { EntryDto, SavedSearchDto, SavedSearchWire } from '../models';
import { SIDEBAR_RELOAD_INTERVAL_MS } from '../state/sidebar-freshness';
import { SubscriptionsStore } from '../state/subscriptions.store';
import { EntriesStore } from '../state/entries.store';
import { ReaderApi } from '../reader-api';
import { EntryBodyService } from '../article/content/entry-body.service';
import { Selection } from '../query/query';
import { UnreadFilterService } from '../list/unread-filter.service';
import { ReaderHeaderComponent } from './header/reader-header.component';
import { RefreshService } from '../state/refresh.service';
import { LayoutService } from '../layout.service';
import { SidebarVisibilityService } from './sidebar-visibility.service';
import { ReadingLayout } from '../reading-layout.service';
import { ManageActions } from '../feeds/manage/manage-actions.service';
import { TagsStore } from '../state/tags.store';
import { DrawerSwipeDirective } from './drawer-swipe.directive';
import { RecommendationsService } from '../state/recommendations.service';
import { AiAvailabilityService } from '../../core/ai-availability.service';
import { SetupService } from '../../core/setup/setup.service';
import { CONFIRMATION_DURATION_MS, ToastService } from '../../shared/toast/toast.service';
import { PasskeyOfferDialogComponent } from './passkey-offer-dialog.component';
import { refreshReport } from '../../../testing/refresh-report';
import { provideAccountIdentity } from '../../../testing/account-identity-testing';

describe('ReaderShellComponent', () => {
  let screen: {
    isNarrow: WritableSignal<boolean>;
    isWide: WritableSignal<boolean>;
    isCoarse: WritableSignal<boolean>;
  };
  let ctrl: HttpTestingController;
  const qp = new BehaviorSubject(convertToParamMap({}));
  // The reader shell owns the saved-search paths too, so it reads paramMap
  // alongside queryParamMap; a test drives a single saved search through `pp`.
  const pp = new BehaviorSubject(convertToParamMap({}));
  // passkeyOfferAnswered defaults to true so the #624 passkey-offer suite is
  // the only place a boot sees the flag unanswered; isPasskeySupported() is
  // false by default in jsdom regardless (stubbed in only in that describe block).
  const auth = {
    user: signal({ id: 1, email: 'a@b.c', preferences: { passkeyOfferAnswered: true } }),
    accountLoadFailed: signal(false),
    loadMe: () => of({}),
    logout: jest.fn(),
    isAdmin: jest.fn().mockReturnValue(false),
    answerPasskeyOffer: jest.fn(() => of(undefined)),
    markPasskeyOfferAnswered: jest.fn(),
  };

  const subscriptionsBody = {
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
        entryCount: 9,
        includeInAllItems: true,
        includeInForYou: true,
      },
    ],
  };
  const entry: EntryDto = {
    id: 1,
    title: 'e1',
    url: null,
    author: null,
    summary: 's',
    excerpt: 's',
    imageUrl: null,
    imageWidth: null,
    imageHeight: null,
    imageRenditions: [],
    media: [],
    attachments: [],
    categories: [],
    publishedAt: '2026-07-22T11:00:00Z',
    createdAt: 'x',
    subscriptionId: 5,
    source: 'heise',
    faviconUrl: null,
    isHidden: false,
    isFavorite: false,
    isKept: false,
    isViewed: false,
    isShort: false,
    imageAspectRatio: null,
    discussionUrl: null,
    comments: null,
  };

  // A jest.fn() double, not the real HTTP-backed service: this suite asserts
  // the shell CALLS prefetch/seed correctly, not the store's own caching —
  // that belongs to entry-body.service.spec.ts.
  let bodyStore: { prefetch: jest.Mock; seed: jest.Mock; body: jest.Mock; retry: jest.Mock };

  beforeEach(() => {
    bodyStore = {
      prefetch: jest.fn(),
      seed: jest.fn(),
      body: jest.fn(() => signal({ status: 'ok', html: null })),
      retry: jest.fn(),
    };
    sessionStorage.clear(); // OnboardingSkip persists here; don't leak across tests
    localStorage.clear(); // LanguageService caches the lang; a de test must not leak into the next
    auth.isAdmin.mockReturnValue(false); // default non-admin; a test opting in overrides it
    // Reset the #624 offer state too: a test below sets passkeyOfferAnswered
    // to false and calls the two marking methods, and neither must leak into
    // an unrelated test later in this file.
    auth.user.set({ id: 1, email: 'a@b.c', preferences: { passkeyOfferAnswered: true } });
    auth.accountLoadFailed.set(false);
    auth.answerPasskeyOffer.mockClear();
    auth.markPasskeyOfferAnswered.mockClear();
    qp.next(convertToParamMap({}));
    pp.next(convertToParamMap({}));
    // Provided rather than left to the real service: jsdom's matchMedia answers
    // "no" to every query, so the real one is stuck on wide and a phone-only test
    // has no way to say so. The defaults below match jsdom's all-false answers.
    screen = { isNarrow: signal(false), isWide: signal(false), isCoarse: signal(false) };
    configureShell([{ provide: AuthService, useValue: auth }]);
    ctrl = TestBed.inject(HttpTestingController);
  });

  function configureShell(authProviders: Provider[]) {
    TestBed.configureTestingModule({
      imports: [ReaderShellComponent, provideTranslocoTesting()],
      providers: [
        provideHttpClient(),
        provideHttpClientTesting(),
        provideRouter([]),
        { provide: API_BASE_URL, useValue: 'https://api.test' },
        {
          provide: ActivatedRoute,
          useValue: { queryParamMap: qp.asObservable(), paramMap: pp.asObservable() },
        },
        ...authProviders,
        { provide: LayoutService, useValue: screen },
        { provide: EntryBodyService, useValue: bodyStore },
        // Defaults to "available", matching every test in this file written before
        // #624 follow-up's instance-wide toggle existed. The "first-login passkey
        // offer" describe block below overrides this per test to cover false/null.
        {
          provide: SetupService,
          useValue: { ensureLoaded: () => of(true), passkeySignInAvailable: signal(true) },
        },
      ],
    });
  }

  /** A fresh module: the outer `beforeEach` already injected `HttpTestingController`,
   *  which Angular refuses to override past. */
  function reconfigureShell(authProviders: Provider[]): void {
    TestBed.resetTestingModule();
    configureShell(authProviders);
    ctrl = TestBed.inject(HttpTestingController);
  }

  function boot(entryOverride: Partial<typeof entry> = {}) {
    const fixture = TestBed.createComponent(ReaderShellComponent);
    fixture.detectChanges(); // ngOnInit + initial effects
    const subscriptions = ctrl.match('https://api.test/api/subscriptions');
    expect(subscriptions.length).toBeLessThanOrEqual(1);
    subscriptions.forEach((request) => request.flush(subscriptionsBody));
    ctrl.expectOne('https://api.test/api/tags').flush({ tags: [] });
    ctrl.expectOne('https://api.test/api/saved-searches').flush({ savedSearches: [] });
    ctrl
      .expectOne((request) => request.url === 'https://api.test/api/entries')
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
    fixture.detectChanges();
    return fixture;
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
    const fixture = TestBed.createComponent(ReaderShellComponent);
    fixture.detectChanges();
    ctrl
      .expectOne('https://api.test/api/subscriptions')
      .flush({ subscriptions, favoritesCount: 0, keptCount: 0 });
    ctrl.expectOne('https://api.test/api/tags').flush({ tags: [] });
    ctrl.expectOne('https://api.test/api/saved-searches').flush({ savedSearches: [] });
    ctrl
      .expectOne((request) => request.url === 'https://api.test/api/entries')
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
    fixture.detectChanges();
    return fixture;
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
    const element = boot().nativeElement as HTMLElement;
    expect(element.querySelector('app-reader-header')).not.toBeNull();
    expect(element.querySelector('app-sidebar')!.textContent).toContain('heise');
    // The shell's default layout is 'magazine'; the single loaded entry renders
    // as some magazine block. Assert the list mounted and rendered a block rather
    // than pinning the exact tier, which is planner-tuning-dependent.
    expect(element.querySelector('app-entry-list')).not.toBeNull();
    expect(
      element.querySelector(
        'app-entry-hero, app-entry-wide, app-entry-quote, app-entry-split, ' +
          'app-entry-kicker, app-entry-thumb, app-entry-compact',
      ),
    ).not.toBeNull();
  });

  describe('manual sidebar visibility (wide layout)', () => {
    it('marks the body hidden and offers the show button when the sidebar is hidden', () => {
      const fixture = boot();
      const element = fixture.nativeElement as HTMLElement;
      expect(element.querySelector('.body')!.classList).not.toContain('sidebar-hidden');
      expect(element.querySelector('[aria-label="Show sidebar"]')).toBeNull();

      TestBed.inject(SidebarVisibilityService).hide();
      fixture.detectChanges();

      expect(element.querySelector('.body')!.classList).toContain('sidebar-hidden');
      expect(element.querySelector('.title-row [aria-label="Show sidebar"]')).not.toBeNull();
    });

    it('shows the sidebar again when the show button is clicked', () => {
      const fixture = boot();
      const element = fixture.nativeElement as HTMLElement;
      TestBed.inject(SidebarVisibilityService).hide();
      fixture.detectChanges();

      (element.querySelector('[aria-label="Show sidebar"]') as HTMLButtonElement).click();
      fixture.detectChanges();

      expect(TestBed.inject(SidebarVisibilityService).hidden()).toBe(false);
      expect(element.querySelector('.body')!.classList).not.toContain('sidebar-hidden');
    });

    it('never hides the body column on a narrow layout, where the drawer rules', () => {
      screen.isNarrow.set(true);
      const fixture = boot();
      const element = fixture.nativeElement as HTMLElement;
      TestBed.inject(SidebarVisibilityService).hide();
      fixture.detectChanges();

      expect(element.querySelector('.body')!.classList).not.toContain('sidebar-hidden');
      expect(element.querySelector('[aria-label="Show sidebar"]')).toBeNull();
    });
  });

  // #87: the header is an overlay, so hiding it must change the header and
  // nothing else — no layout pass that resizes the scroller under the finger.
  describe('hide-on-scroll header', () => {
    it('publishes a bar height that does not move when the bar does', () => {
      const fixture = boot();
      const element = fixture.nativeElement as HTMLElement;
      // jsdom has no ResizeObserver, so nothing measures the header; stand in
      // for the measurement to exercise everything that depends on it.
      fixture.componentInstance.headerHeight.set(90);
      fixture.detectChanges();
      expect(element.style.getPropertyValue('--app-bar-h')).toBe('90px');
      expect(element.style.getPropertyValue('--app-bar-shift')).toBe('0px');

      // Retract the bar the only way there is now: scroll the list down on the
      // narrow layout, which collapses the list and the app bar mirrors it.
      const rows = listScroller(fixture);
      rows.scrollTo(100);
      rows.scrollTo(500);
      fixture.detectChanges();
      // The reservation is unchanged — only the shift moves. That is the fix:
      // the panes' geometry cannot depend on whether the bar is showing.
      expect(element.style.getPropertyValue('--app-bar-h')).toBe('90px');
      expect(element.style.getPropertyValue('--app-bar-shift')).toBe('-90px');
      expect(element.querySelector('app-reader-header')!.classList).toContain('hidden');
      // The old mechanism, and the whole bug: no margin may be involved.
      expect(element.querySelector<HTMLElement>('app-reader-header')!.style.marginTop).toBe('');
    });

    it('shows the header again when the mobile drawer opens', () => {
      // The drawer hangs below the bar, so opening it under a retracted header
      // would leave a strip of backdrop where the bar belongs.
      const fixture = boot();
      const rows = listScroller(fixture);
      rows.scrollTo(100);
      rows.scrollTo(500);
      fixture.detectChanges();
      expect(fixture.componentInstance.headerHidden()).toBe(true);

      fixture.componentInstance.setSidebarOpen(true);
      fixture.detectChanges();
      expect(fixture.componentInstance.headerHidden()).toBe(false);
      expect(fixture.componentInstance.sidebarOpen()).toBe(true);
    });

    it('keeps the header shown when momentum scroll fires after the drawer opens', () => {
      // #121: the open-swipe's touchend shows the header, but inertial scrolling
      // keeps firing scroll events afterwards. One arriving under the open drawer
      // must not re-hide the header — the drawer would then hang below a gap.
      const fixture = boot();
      // Only a narrow layout hides the header at all; force it so the residual
      // scroll would otherwise register as a hide.
      (fixture.componentInstance.screen as unknown as { isWide: () => boolean }).isWide = () =>
        false;
      fixture.componentInstance.headerHeight.set(90);
      fixture.componentInstance.setSidebarOpen(true);
      fixture.detectChanges();
      expect(fixture.componentInstance.headerHidden()).toBe(false);

      // A residual downward scroll (100 → 500) on the list while the drawer is
      // open. It collapses the list, but the open overlay force-shows the bar.
      const rows = listScroller(fixture);
      rows.scrollTo(100);
      rows.scrollTo(500);
      fixture.detectChanges();

      expect(fixture.componentInstance.headerHidden()).toBe(false);
    });

    it('re-minimizes the header when the drawer closes over a scrolled-down list', () => {
      // #121 follow-up: opening forces the header back (so the drawer never hangs
      // below a retracted bar), but closing must not leave it expanded over
      // scrolled-down content — that strip overlays the list but isn't its scroller.
      const fixture = boot();
      (fixture.componentInstance.screen as unknown as { isWide: () => boolean }).isWide = () =>
        false;

      // Swipe up / scroll down: the header minimizes.
      const rows = listScroller(fixture);
      rows.scrollTo(100);
      rows.scrollTo(500);
      fixture.detectChanges();
      expect(fixture.componentInstance.headerHidden()).toBe(true);

      fixture.componentInstance.setSidebarOpen(true);
      fixture.detectChanges();
      expect(fixture.componentInstance.headerHidden()).toBe(false); // shown while open

      fixture.componentInstance.setSidebarOpen(false);
      fixture.detectChanges();
      // Back to the resting state the scroll offset implies — minimized.
      expect(fixture.componentInstance.headerHidden()).toBe(true);
    });

    it('stays shown while the header reports its own search bar open, even across a scroll', () => {
      // The bar holds the live term and, on a phone, the keyboard — sliding it
      // away under a scroll would hide the text the results depend on.
      const fixture = boot();
      (fixture.componentInstance.screen as unknown as { isWide: () => boolean }).isWide = () =>
        false;
      // The trigger that sets searchOpen true only ever renders on a narrow
      // layout (#408): patch isNarrow alongside isWide so the header's own
      // "close on layout growth" effect doesn't immediately undo the line below.
      (fixture.componentInstance.screen as unknown as { isNarrow: () => boolean }).isNarrow = () =>
        true;
      const header = fixture.debugElement.query(By.directive(ReaderHeaderComponent))
        .componentInstance as ReaderHeaderComponent;

      header.searchOpen.set(true);
      fixture.detectChanges();
      expect(fixture.componentInstance.headerHidden()).toBe(false);

      const rows = listScroller(fixture);
      rows.scrollTo(100);
      rows.scrollTo(500);
      fixture.detectChanges();

      expect(fixture.componentInstance.headerHidden()).toBe(false);
    });

    it('returns to the resting state for the current offset once the search bar closes', () => {
      const fixture = boot();
      (fixture.componentInstance.screen as unknown as { isWide: () => boolean }).isWide = () =>
        false;
      (fixture.componentInstance.screen as unknown as { isNarrow: () => boolean }).isNarrow = () =>
        true;
      const header = fixture.debugElement.query(By.directive(ReaderHeaderComponent))
        .componentInstance as ReaderHeaderComponent;

      // Scroll down first so the resting state the bar returns to is minimized,
      // not just whatever the header happened to hold before opening.
      const rows = listScroller(fixture);
      rows.scrollTo(100);
      rows.scrollTo(500);
      fixture.detectChanges();
      expect(fixture.componentInstance.headerHidden()).toBe(true);

      header.searchOpen.set(true);
      fixture.detectChanges();
      expect(fixture.componentInstance.headerHidden()).toBe(false);

      header.searchOpen.set(false);
      fixture.detectChanges();
      expect(fixture.componentInstance.headerHidden()).toBe(true);
    });

    it('stays shown when the search bar closes while the drawer is still open', () => {
      // Both overlays open, then only search closes: the drawer alone is still
      // reason enough to keep the header shown. Two independent force-show/resolve
      // writers (one per overlay) would overwrite each other's resting-state resolution.
      const fixture = boot();
      (fixture.componentInstance.screen as unknown as { isWide: () => boolean }).isWide = () =>
        false;
      (fixture.componentInstance.screen as unknown as { isNarrow: () => boolean }).isNarrow = () =>
        true;
      const header = fixture.debugElement.query(By.directive(ReaderHeaderComponent))
        .componentInstance as ReaderHeaderComponent;

      const rows = listScroller(fixture);
      rows.scrollTo(100);
      rows.scrollTo(500);
      fixture.detectChanges();
      expect(fixture.componentInstance.headerHidden()).toBe(true);

      fixture.componentInstance.setSidebarOpen(true);
      header.searchOpen.set(true);
      fixture.detectChanges();
      expect(fixture.componentInstance.headerHidden()).toBe(false);

      header.searchOpen.set(false);
      fixture.detectChanges();
      // The drawer is still open — the header must not retract under it.
      expect(fixture.componentInstance.headerHidden()).toBe(false);
    });

    it('stays shown when the drawer closes while the search bar is still open', () => {
      // The reverse order: search opens first, then the drawer opens and
      // closes (e.g. the edge-swipe gesture). The search bar alone is still
      // reason enough to keep the header shown.
      const fixture = boot();
      (fixture.componentInstance.screen as unknown as { isWide: () => boolean }).isWide = () =>
        false;
      (fixture.componentInstance.screen as unknown as { isNarrow: () => boolean }).isNarrow = () =>
        true;
      const header = fixture.debugElement.query(By.directive(ReaderHeaderComponent))
        .componentInstance as ReaderHeaderComponent;

      const rows = listScroller(fixture);
      rows.scrollTo(100);
      rows.scrollTo(500);
      fixture.detectChanges();
      expect(fixture.componentInstance.headerHidden()).toBe(true);

      header.searchOpen.set(true);
      fixture.componentInstance.setSidebarOpen(true);
      fixture.detectChanges();
      expect(fixture.componentInstance.headerHidden()).toBe(false);

      fixture.componentInstance.setSidebarOpen(false);
      fixture.detectChanges();
      // The search bar is still open — the header must not retract under it.
      expect(fixture.componentInstance.headerHidden()).toBe(false);
    });

    it('shows the bar again when a new list is chosen while scrolled down (#630)', () => {
      // The drawer auto-closes while the OUTGOING list is still rendered and
      // scrolled down (#254); the bar must not take its state from that offset.
      const fixture = boot();
      const rows = listScroller(fixture);
      rows.scrollTo(100);
      rows.scrollTo(500);
      fixture.detectChanges();
      expect(fixture.componentInstance.headerHidden()).toBe(true);

      // Open the drawer (bar shows), then pick a saved search from it. The
      // selection change auto-closes the drawer; the outgoing list is still at
      // 500 at that instant.
      fixture.componentInstance.setSidebarOpen(true);
      fixture.detectChanges();
      qp.next(convertToParamMap({ q: 'news' }));
      fixture.detectChanges();
      ctrl
        .expectOne((request) => request.url === 'https://api.test/api/entries/search')
        .flush({ entries: [entry], nextCursor: null });
      fixture.detectChanges();

      expect(fixture.componentInstance.headerHidden()).toBe(false);
    });
  });

  /** Drive the entry list's real scroll container: assign an offset and fire the
   *  scroll event `onRowsScroll` handles, updating the `collapsed` signal the app
   *  bar mirrors — tests must scroll the element the component actually consults. */
  function listScroller(fixture: ReturnType<typeof boot>) {
    const element = (fixture.nativeElement as HTMLElement).querySelector<HTMLElement>('.rows')!;
    return {
      el: element,
      scrollTo(top: number): void {
        element.scrollTop = top;
        element.dispatchEvent(new Event('scroll'));
      },
    };
  }

  describe('returning from a full-screen article (#128)', () => {
    /** An extra scroller under the shell (the article overlay's, say), with its
     *  own coordinate space, heard by the same capture-phase listener. */
    function scroller(fixture: ReturnType<typeof boot>) {
      const element = document.createElement('div');
      let top = 0;
      Object.defineProperty(element, 'scrollTop', { get: () => top, configurable: true });
      (fixture.nativeElement as HTMLElement).appendChild(element);
      return {
        el: element,
        scrollTo(next: number): void {
          top = next;
          element.dispatchEvent(new Event('scroll'));
        },
      };
    }

    function bootNarrowScrolledDown() {
      const fixture = boot();
      (fixture.componentInstance.screen as unknown as { isWide: () => boolean }).isWide = () =>
        false;
      const rows = listScroller(fixture);
      rows.scrollTo(100);
      rows.scrollTo(800);
      fixture.detectChanges();
      expect(fixture.componentInstance.headerHidden()).toBe(true);
      return { fixture, rows };
    }

    function openArticle(fixture: ReturnType<typeof boot>): void {
      qp.next(convertToParamMap({ entry: '1' }));
      fixture.detectChanges();
      ctrl.expectOne('https://api.test/api/entries/1/state').flush({
        state: {
          entryId: 1,
          isHidden: true,
          isFavorite: false,
          isKept: false,
          hiddenAt: 'x',
          isViewed: true,
          viewedAt: 'x',
        },
      });
      fixture.detectChanges();
      expect(fixture.componentInstance.articleFullscreen()).toBe(true);
    }

    function closeArticle(fixture: ReturnType<typeof boot>): void {
      qp.next(convertToParamMap({ entry: null }));
      fixture.detectChanges();
      expect(fixture.componentInstance.articleFullscreen()).toBe(false);
    }

    it('leaves the list bar alone across article open and close', () => {
      // The full-screen article is a layer above the whole list, bar included,
      // with its own toolbar. Opening and closing it must not touch the bar's
      // hide-on-scroll state — the list is revealed exactly as it was left (#128).
      const { fixture } = bootNarrowScrolledDown();
      openArticle(fixture);
      expect(fixture.componentInstance.headerHidden()).toBe(true);

      closeArticle(fixture);
      expect(fixture.componentInstance.headerHidden()).toBe(true);
    });

    it('hears no scroller but the list', () => {
      // #128: only the entry list's typed scrolled output drives the bar, never
      // the article overlay's scroller or the tag row's re-snap.
      const { fixture, rows } = bootNarrowScrolledDown();
      openArticle(fixture);

      const article = scroller(fixture);
      article.scrollTo(100);
      article.scrollTo(2000);
      const tagRowLike = scroller(fixture);
      tagRowLike.scrollTo(0); // horizontal snap: scrollTop stays 0
      fixture.detectChanges();
      expect(fixture.componentInstance.headerHidden()).toBe(true);

      closeArticle(fixture);
      rows.scrollTo(810); // a small further scroll DOWN on the list
      fixture.detectChanges();
      expect(fixture.componentInstance.headerHidden()).toBe(true);

      rows.scrollTo(300); // a real scroll UP still expands the header
      fixture.detectChanges();
      expect(fixture.componentInstance.headerHidden()).toBe(false);
    });

    it('restores the drawer-close header state from the list, not the article', () => {
      // setSidebarOpen(false) re-derives the header from "the" scroll offset.
      // After deep-scrolling an article, that offset must be the list's own —
      // here near the top, so the header must stay expanded.
      const fixture = boot();
      (fixture.componentInstance.screen as unknown as { isWide: () => boolean }).isWide = () =>
        false;
      const rows = listScroller(fixture);
      rows.scrollTo(30);
      rows.scrollTo(10);
      fixture.detectChanges();
      expect(fixture.componentInstance.headerHidden()).toBe(false);

      openArticle(fixture);
      const article = scroller(fixture);
      article.scrollTo(100);
      article.scrollTo(2000);
      fixture.detectChanges();
      closeArticle(fixture);

      fixture.componentInstance.setSidebarOpen(true);
      fixture.detectChanges();
      fixture.componentInstance.setSidebarOpen(false);
      fixture.detectChanges();
      expect(fixture.componentInstance.headerHidden()).toBe(false);
    });
  });

  it('marks the opened entry read and viewed', () => {
    const fixture = boot();
    qp.next(convertToParamMap({ entry: '1' }));
    fixture.detectChanges();
    const testRequest = ctrl.expectOne('https://api.test/api/entries/1/state');
    expect(testRequest.request.body).toEqual({ isViewed: true });
    testRequest.flush({
      state: {
        entryId: 1,
        isHidden: true,
        isFavorite: false,
        isKept: false,
        hiddenAt: 'x',
        isViewed: true,
        viewedAt: 'x',
      },
    });
    expect(fixture.nativeElement.querySelector('app-reader-view')).not.toBeNull();
  });

  it('drops the saved-search unread count when an unread matching entry is opened (#645)', () => {
    const fixture = boot();
    const store = fixture.componentInstance.savedSearchesStore;
    store.load();
    ctrl.expectOne('https://api.test/api/saved-searches').flush({
      savedSearches: [
        {
          id: 7,
          term: 'news',
          wholeWord: false,
          phrase: false,
          position: 0,
          unreadEntryIds: [1, 2],
        },
      ],
    });
    expect(store.savedSearches()[0].unreadCount).toBe(2);

    qp.next(convertToParamMap({ entry: '1' }));
    fixture.detectChanges();

    // Opening reads entry 1 optimistically, so the badge drops at once — before
    // the state PATCH even resolves, no reload of the saved searches.
    expect(store.savedSearches()[0].unreadCount).toBe(1);
    ctrl.expectOne('https://api.test/api/entries/1/state').flush({
      state: {
        entryId: 1,
        isHidden: true,
        isFavorite: false,
        isKept: false,
        hiddenAt: 'x',
        isViewed: true,
        viewedAt: 'x',
      },
    });
    ctrl.expectNone('https://api.test/api/saved-searches');
  });

  it('restores the saved-search unread count when the open PATCH fails (#645)', () => {
    const fixture = boot();
    const store = fixture.componentInstance.savedSearchesStore;
    store.load();
    ctrl.expectOne('https://api.test/api/saved-searches').flush({
      savedSearches: [
        {
          id: 7,
          term: 'news',
          wholeWord: false,
          phrase: false,
          position: 0,
          unreadEntryIds: [1, 2],
        },
      ],
    });

    qp.next(convertToParamMap({ entry: '1' }));
    fixture.detectChanges();
    expect(store.savedSearches()[0].unreadCount).toBe(1);

    ctrl
      .expectOne('https://api.test/api/entries/1/state')
      .flush({ type: 'x', title: 't', status: 500 }, { status: 500, statusText: 'err' });
    fixture.detectChanges();

    // The read rolled back, so the entry is unread again and the badge returns.
    expect(store.savedSearches()[0].unreadCount).toBe(2);
  });

  it('marks the opened entry read and viewed only once even when the PATCH fails', () => {
    const fixture = boot();
    qp.next(convertToParamMap({ entry: '1' }));
    fixture.detectChanges();
    const testRequest = ctrl.expectOne('https://api.test/api/entries/1/state');
    expect(testRequest.request.body).toEqual({ isViewed: true });
    testRequest.flush({ type: 'x', title: 't', status: 500 }, { status: 500, statusText: 'err' });
    fixture.detectChanges();
    // The entry is still unread/unviewed (rollback), but the effect must NOT
    // re-fire a PATCH.
    ctrl.expectNone((request) => request.url.endsWith('/entries/1/state'));
    ctrl.verify();
  });

  it('marks an already-read entry viewed on open', () => {
    const fixture = boot({ isHidden: true });
    qp.next(convertToParamMap({ entry: '1' }));
    fixture.detectChanges();
    const testRequest = ctrl.expectOne('https://api.test/api/entries/1/state');
    expect(testRequest.request.body).toEqual({ isViewed: true });
    testRequest.flush({
      state: {
        entryId: 1,
        isHidden: true,
        isFavorite: false,
        isKept: false,
        hiddenAt: 'x',
        isViewed: true,
        viewedAt: 'x',
      },
    });
  });

  it('does not re-mark an already-viewed entry on open', () => {
    const fixture = boot({ isHidden: true, isViewed: true });
    qp.next(convertToParamMap({ entry: '1' }));
    fixture.detectChanges();
    ctrl.expectNone((request) => request.url.endsWith('/entries/1/state'));
    ctrl.verify();
  });

  it('does not re-mark the open entry viewed after the user un-ticks it', () => {
    // The tick must turn off and stay off. The auto-open effect (which marks the
    // open entry viewed once) must not re-fire when un-ticking flips isViewed
    // back to false — otherwise it re-marks the entry and the tick never flips.
    const fixture = boot();
    qp.next(convertToParamMap({ entry: '1' }));
    fixture.detectChanges();
    ctrl.expectOne('https://api.test/api/entries/1/state').flush({
      state: {
        entryId: 1,
        isHidden: true,
        isFavorite: false,
        isKept: false,
        hiddenAt: 'x',
        isViewed: true,
        viewedAt: 'x',
      },
    });
    fixture.detectChanges();

    fixture.componentInstance.entryActions.toggleRead(fixture.componentInstance.openEntry()!);
    fixture.detectChanges();

    const patches = ctrl.match((request) => request.url.endsWith('/entries/1/state'));
    expect(patches.map((patch) => patch.request.body)).toEqual([{ isViewed: false }]);
    patches.forEach((patch) =>
      patch.flush({
        state: {
          entryId: 1,
          isHidden: true,
          isFavorite: false,
          isKept: false,
          hiddenAt: 'x',
          isViewed: false,
          viewedAt: null,
        },
      }),
    );
    fixture.detectChanges();
    ctrl.expectNone((request) => request.url.endsWith('/entries/1/state'));
    ctrl.verify();
  });

  it('marks the entry viewed when the original-article link is followed', () => {
    // Open fires only the viewed patch, which fails and rolls back below, so
    // the link click is the real retry path this test exercises.
    const fixture = boot({ isHidden: true });
    qp.next(convertToParamMap({ entry: '1' }));
    fixture.detectChanges();
    ctrl
      .expectOne('https://api.test/api/entries/1/state')
      .flush({ type: 'x', title: 't', status: 500 }, { status: 500, statusText: 'err' });
    fixture.detectChanges();

    fixture.componentInstance.entryActions.openOriginal({
      ...entry,
      isHidden: true,
      isViewed: false,
    });
    const testRequest = ctrl.expectOne('https://api.test/api/entries/1/state');
    expect(testRequest.request.body).toEqual({ isViewed: true });
  });

  // Direct invocation above proves openOriginal's own logic, but not that the
  // template wires the click to it — there are TWO <app-reader-view> sites (wide
  // pane, narrow overlay), and either could silently drop the `(openOriginal)` binding.
  describe('the original-article link, through the real template wiring', () => {
    function clickOriginalLink(fixture: ReturnType<typeof boot>): void {
      const link = fixture.debugElement.query(By.css('app-reader-view a[target="_blank"]'));
      expect(link).not.toBeNull();
      link.triggerEventHandler('click', null);
      fixture.detectChanges();
    }

    it('marks viewed via the narrow full-screen overlay reader-view', () => {
      // Default test layout is narrow (isWide() is false), so the shell renders
      // the @else branch's overlay <app-reader-view> (reader-shell.component.html:157).
      const fixture = boot({ isHidden: true, url: 'https://example.com/story' });
      qp.next(convertToParamMap({ entry: '1' }));
      fixture.detectChanges();
      // The on-open effect's own PATCH fails and rolls isViewed back to false,
      // so the link click below is the one exercising the wiring under test.
      ctrl
        .expectOne('https://api.test/api/entries/1/state')
        .flush({ type: 'x', title: 't', status: 500 }, { status: 500, statusText: 'err' });
      fixture.detectChanges();

      clickOriginalLink(fixture);

      const testRequest = ctrl.expectOne('https://api.test/api/entries/1/state');
      expect(testRequest.request.body).toEqual({ isViewed: true });
    });

    it('marks viewed via the wide split-pane reader-view', () => {
      // Force the wide split-pane layout so the shell renders the @if branch's
      // <app-reader-view> (reader-shell.component.html:112) instead of the overlay.
      const fixture = boot({ isHidden: true, url: 'https://example.com/story' });
      (fixture.componentInstance.screen as unknown as { isWide: () => boolean }).isWide = () =>
        true;
      fixture.componentInstance.layout.set('pane');
      qp.next(convertToParamMap({ entry: '1' }));
      fixture.detectChanges();
      expect(fixture.componentInstance.paneMode()).toBe(true);
      ctrl
        .expectOne('https://api.test/api/entries/1/state')
        .flush({ type: 'x', title: 't', status: 500 }, { status: 500, statusText: 'err' });
      fixture.detectChanges();

      clickOriginalLink(fixture);

      const testRequest = ctrl.expectOne('https://api.test/api/entries/1/state');
      expect(testRequest.request.body).toEqual({ isViewed: true });
    });
  });

  describe('wide desktop searches (#607)', () => {
    const savedAngular: SavedSearchWire = {
      id: 9,
      slug: '9-angular',
      term: 'angular',
      wholeWord: false,
      phrase: false,
      position: 0,
      unreadEntryIds: [],
      memberCount: 0,
      includeInDigest: false,
    };

    function selectSavedAngular(fixture: ReturnType<typeof boot>) {
      fixture.componentInstance.savedSearchesStore.load();
      ctrl
        .expectOne('https://api.test/api/saved-searches')
        .flush({ savedSearches: [savedAngular] });
      pp.next(convertToParamMap({ savedSearch: '9-angular' }));
      fixture.detectChanges();
    }

    it.each(['magazine', 'list', 'pane'] as const)(
      'uses the selected %s layout for a single saved search',
      (layout) => {
        const fixture = boot();
        screen.isWide.set(true);
        fixture.componentInstance.layout.set(layout);

        selectSavedAngular(fixture);
        ctrl
          .expectOne((request) => request.url === 'https://api.test/api/entries/saved-searches/9')
          .flush({ entries: [{ ...entry, isHidden: true, isViewed: true }], nextCursor: null });
        fixture.detectChanges();

        // A saved search is not a direct search, so it never opens the search
        // pane: it uses the selected layout and only splits under a pane layout.
        expect(fixture.componentInstance.splitView()).toBe(layout === 'pane');
        expect(fixture.nativeElement.querySelector('app-sidebar input').value).toBe('');
        expect(fixture.nativeElement.querySelector('.rows.magazine') !== null).toBe(
          layout === 'magazine',
        );

        qp.next(convertToParamMap({ entry: '1' }));
        fixture.detectChanges();
        expect(fixture.componentInstance.articleFullscreen()).toBe(layout !== 'pane');
        ctrl.verify();
      },
    );

    it('switches between a saved search and a direct search of the same term', () => {
      const fixture = boot();
      screen.isWide.set(true);
      fixture.componentInstance.layout.set('magazine');

      selectSavedAngular(fixture);
      ctrl
        .match((request) => request.url === 'https://api.test/api/entries/saved-searches/9')
        .forEach((testRequest) => testRequest.flush({ entries: [entry], nextCursor: null }));
      fixture.detectChanges();
      expect(fixture.componentInstance.splitView()).toBe(false);
      expect(fixture.nativeElement.querySelector('app-sidebar input').value).toBe('');
      expect(fixture.nativeElement.querySelector('.rows.magazine') !== null).toBe(true);
      expect(fixture.componentInstance.layout.mode()).toBe('magazine');

      pp.next(convertToParamMap({}));
      qp.next(convertToParamMap({ q: 'angular' }));
      fixture.detectChanges();
      ctrl
        .match((request) => request.url === 'https://api.test/api/entries/search')
        .forEach((testRequest) => testRequest.flush({ entries: [entry], nextCursor: null }));
      fixture.detectChanges();
      expect(fixture.componentInstance.splitView()).toBe(true);
      expect(fixture.nativeElement.querySelector('app-sidebar input').value).toBe('angular');
      expect(fixture.nativeElement.querySelector('.rows.magazine') !== null).toBe(false);
      expect(fixture.componentInstance.layout.mode()).toBe('magazine');
      ctrl.verify();
    });

    it('uses the split reader and restores the selected magazine layout when search clears', () => {
      const fixture = boot();
      screen.isWide.set(true);
      fixture.componentInstance.layout.set('magazine');

      qp.next(convertToParamMap({ q: 'angular' }));
      fixture.detectChanges();
      ctrl
        .expectOne((request) => request.url === 'https://api.test/api/entries/search')
        .flush({ entries: [{ ...entry, isHidden: true, isViewed: true }], nextCursor: null });
      fixture.detectChanges();

      expect(fixture.componentInstance.paneMode()).toBe(false);
      expect(fixture.componentInstance.splitView()).toBe(true);
      expect(fixture.componentInstance.layout.mode()).toBe('magazine');
      expect(
        fixture.nativeElement.querySelector('.main.split .reader .placeholder'),
      ).not.toBeNull();

      qp.next(convertToParamMap({ q: 'angular', entry: '1' }));
      fixture.detectChanges();

      expect(fixture.componentInstance.articleFullscreen()).toBe(false);
      expect(
        fixture.nativeElement.querySelector('.main.split .reader h1.title')?.textContent,
      ).toContain('e1');
      expect(fixture.nativeElement.querySelector('.article-overlay')).toBeNull();

      qp.next(convertToParamMap({}));
      fixture.detectChanges();
      ctrl
        .expectOne((request) => request.url === 'https://api.test/api/entries')
        .flush({ entries: [entry], nextCursor: null });
      fixture.detectChanges();

      expect(fixture.componentInstance.splitView()).toBe(fixture.componentInstance.paneMode());
      expect(fixture.componentInstance.splitView()).toBe(false);
      expect(fixture.componentInstance.layout.mode()).toBe('magazine');
      expect(fixture.nativeElement.querySelector('.main.split')).toBeNull();
      expect(fixture.nativeElement.querySelector('.rows.magazine')).not.toBeNull();
    });
  });

  describe('the split-pane resize handle (#810)', () => {
    it('mounts a keyboard-operable separator between the panes in split view', () => {
      const fixture = boot();
      screen.isWide.set(true);
      fixture.componentInstance.layout.set('pane');
      fixture.detectChanges();

      const handle = fixture.nativeElement.querySelector('.main.split .pane-divider');
      expect(handle).not.toBeNull();
      expect(handle.getAttribute('role')).toBe('separator');
      expect(handle.getAttribute('aria-orientation')).toBe('vertical');
      ctrl.verify();
    });

    it('carries no separator when the main area is not split', () => {
      const fixture = boot();
      expect(fixture.componentInstance.splitView()).toBe(false);
      expect(fixture.nativeElement.querySelector('.pane-divider')).toBeNull();
      ctrl.verify();
    });
  });

  it('fetches a deep-linked entry that is not in the loaded list', () => {
    const fixture = boot(); // initial list holds only entry id 1
    qp.next(convertToParamMap({ entry: '514-deep-linked-story' }));
    fixture.detectChanges();

    // Not in the list → the shell fetches it by the id parsed from the slug.
    const testRequest = ctrl.expectOne('https://api.test/api/entries/514');
    expect(testRequest.request.method).toBe('GET');
    // isHidden:true, isViewed:true so the on-open effect fires no state PATCH.
    testRequest.flush({
      entry: {
        ...entry,
        id: 514,
        title: 'Deep linked story',
        contentHtml: '<p>Deep linked body</p>',
        isHidden: true,
        isViewed: true,
      },
    });
    fixture.detectChanges();

    expect(fixture.nativeElement.querySelector('app-reader-view')).not.toBeNull();
    // The detail response already carries the body — seed the store with it
    // rather than let the reader view issue a redundant fetch of its own.
    expect(bodyStore.seed).toHaveBeenCalledWith(514, '<p>Deep linked body</p>');
    ctrl.verify();
  });

  describe('neighbour prefetch on open (#1100)', () => {
    it('warms the body store for the previous and next entries in list order', () => {
      const fixture = TestBed.createComponent(ReaderShellComponent);
      fixture.detectChanges();
      ctrl
        .match('https://api.test/api/subscriptions')
        .forEach((testRequest) => testRequest.flush(subscriptionsBody));
      ctrl.expectOne('https://api.test/api/tags').flush({ tags: [] });
      ctrl.expectOne('https://api.test/api/saved-searches').flush({ savedSearches: [] });
      ctrl
        .expectOne((request) => request.url === 'https://api.test/api/entries')
        .flush({
          entries: [
            { ...entry, id: 1 },
            // Already viewed/hidden, so opening it fires no on-open state PATCH.
            { ...entry, id: 2, isHidden: true, isViewed: true },
            { ...entry, id: 3 },
          ],
          nextCursor: null,
        });
      ctrl.expectOne('https://api.test/api/recommendations/runs/current').flush({
        status: 'none',
        batchesTotal: null,
        batchesDone: 0,
        error: null,
        background: false,
        streamedChars: 0,
        forYou: { itemCount: 0, generatedAt: null, newestRunId: null },
      });
      ctrl.expectOne('https://api.test/api/version').flush({
        version: 'dev',
        commit: 'local',
        builtAt: '',
        latest: null,
      });

      qp.next(convertToParamMap({ entry: '2' }));
      fixture.detectChanges();

      expect(bodyStore.prefetch).toHaveBeenCalledWith(1);
      expect(bodyStore.prefetch).toHaveBeenCalledWith(3);
      ctrl.verify();
    });

    it('prefetches only the side with a neighbour, for an edge entry', () => {
      const fixture = boot(); // a single-entry list — no neighbour on either side
      qp.next(convertToParamMap({ entry: '1' }));
      fixture.detectChanges();

      expect(bodyStore.prefetch).not.toHaveBeenCalled();
    });
  });

  it('ignores a stale cold-entry fetch that resolves after navigating to another', () => {
    const fixture = boot();
    // Open cold entry A (not in the list), then jump to cold entry B before A resolves.
    qp.next(convertToParamMap({ entry: '514-a' }));
    fixture.detectChanges();
    const testRequestA = ctrl.expectOne('https://api.test/api/entries/514');
    qp.next(convertToParamMap({ entry: '600-b' }));
    fixture.detectChanges();
    const testRequestB = ctrl.expectOne('https://api.test/api/entries/600');

    // B resolves first (now open), then A resolves LATE — A must not clobber B.
    testRequestB.flush({
      entry: { ...entry, id: 600, title: 'Entry B', contentHtml: '<p>B</p>', isHidden: true },
    });
    fixture.detectChanges();
    testRequestA.flush({
      entry: { ...entry, id: 514, title: 'Entry A', contentHtml: '<p>A</p>', isHidden: true },
    });
    fixture.detectChanges();

    // The list stays mounted beneath the article overlay, so scope to the reader.
    expect(fixture.nativeElement.querySelector('app-reader-view .title')?.textContent).toContain(
      'Entry B',
    );
  });

  const refreshDone = refreshReport();

  it('scopes an all-items refresh to nothing (sweeps every due feed)', () => {
    const fixture = boot();
    fixture.componentInstance.refresh.refreshScoped();
    const testRequest = ctrl.expectOne((request) => request.url === 'https://api.test/api/refresh');
    expect(testRequest.request.params.has('feedId')).toBe(false);
    expect(testRequest.request.params.has('tag')).toBe(false);
    testRequest.flush(refreshDone);
  });

  describe('one scoped refresh reloads the list once (#502)', () => {
    it('fires exactly one entries reload and one tags reload after the run finishes', () => {
      const fixture = boot();

      fixture.componentInstance.refresh.refreshScoped();

      // The refresh sweep itself.
      ctrl
        .expectOne((request) => request.url === 'https://api.test/api/refresh')
        .flush(refreshDone);
      fixture.detectChanges();

      // Exactly one reload of each list-backing resource — the double reload
      // (slice effect + onDone) would make entries match twice here.
      // ctrl.match() removes what it finds from the open-request queue, so the
      // counts are captured once and reused below to drain them — matching
      // again would find nothing and throw.
      const entriesReloads = ctrl.match(
        (request) => request.url === 'https://api.test/api/entries',
      );
      const tagsReloads = ctrl.match((request) => request.url === 'https://api.test/api/tags');
      const subscriptionsReloads = ctrl.match(
        (request) => request.url === 'https://api.test/api/subscriptions',
      );
      const savedSearchesReloads = ctrl.match(
        (request) => request.url === 'https://api.test/api/saved-searches',
      );
      expect(entriesReloads.length).toBe(1);
      expect(tagsReloads.length).toBe(1);
      expect(subscriptionsReloads.length).toBe(1);
      expect(savedSearchesReloads.length).toBe(1);

      // Drain the reload requests so verify() is clean.
      entriesReloads[0].flush({ entries: [], nextCursor: null });
      tagsReloads[0].flush({ tags: [] });
      subscriptionsReloads[0].flush(subscriptionsBody);
      savedSearchesReloads[0].flush({ savedSearches: [] });
      ctrl.verify();
    });
  });

  describe('onboarding sweep still fills progressively (#502)', () => {
    it('reloads the list on each landing slice, not only at the end', () => {
      // All subscriptions never fetched → awaitingFirstFetch() is true → the shell
      // fires the post-onboarding sweep itself (sweeping() is true for its span).
      const fixture = bootWith([{ ...SUBSCRIPTION_FIXTURE, lastFetchedAt: null }]);

      // The sweep's own refresh request.
      const refresh = ctrl.expectOne((request) => request.url === 'https://api.test/api/refresh');

      // First slice: partial, more feeds still due → the list must reload now.
      // RefreshService.step() re-fires the next /api/refresh synchronously from
      // inside this flush, so it is already queued below alongside the reload.
      refresh.flush({
        ...refreshDone,
        status: 'partial',
        progress: { done: 1, total: 2 },
        fetched: 1,
        remaining: 1,
      });
      fixture.detectChanges();
      const firstSliceEntries = ctrl.match(
        (request) => request.url === 'https://api.test/api/entries',
      );
      expect(firstSliceEntries.length).toBe(1);
      firstSliceEntries[0].flush({ entries: [], nextCursor: null });
      // subs reload per slice; tags do not (they reload once at finish), so only
      // the subscriptions request is drained here.
      ctrl
        .match((request) => request.url === 'https://api.test/api/subscriptions')
        .forEach((testRequest) =>
          testRequest.flush({
            subscriptions: [{ ...SUBSCRIPTION_FIXTURE, lastFetchedAt: null }],
            favoritesCount: 0,
            keptCount: 0,
          }),
        );
      // Tags must NOT reload on a partial slice — a refresh never touches them,
      // so they reload once at the finish, not once per sweep slice (#502).
      expect(ctrl.match((request) => request.url === 'https://api.test/api/tags').length).toBe(0);

      // Second slice: the sweep's poll loop re-fires /api/refresh on its own;
      // finishing it reloads again — proof the first reload was not the only one.
      const next = ctrl.expectOne((request) => request.url === 'https://api.test/api/refresh');
      next.flush({ ...refreshDone, progress: { done: 2, total: 2 }, fetched: 2 });
      fixture.detectChanges();

      // The finishing slice reloads once more (entries + subs + tags). match()
      // consumes the open queue, so calling it once and flushing that array proves
      // the first slice's reload was not the only one.
      const finishReloads = ctrl.match(() => true);
      expect(
        finishReloads.some((testRequest) => testRequest.request.url.endsWith('/api/entries')),
      ).toBe(true);
      // Tags reload exactly once, here at the finish — never on the partial slice above.
      expect(
        finishReloads.filter((testRequest) => testRequest.request.url.endsWith('/api/tags')).length,
      ).toBe(1);
      // Skip the cancelled ones: each store abandons a request the next slice's
      // reload supersedes, so the queue holds some that can no longer answer.
      finishReloads
        .filter((testRequest) => !testRequest.cancelled)
        .forEach((testRequest) =>
          testRequest.flush({
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
    const fixture = boot();
    qp.next(convertToParamMap({ tag: '3' }));
    fixture.detectChanges();
    ctrl
      .expectOne((request) => request.url === 'https://api.test/api/entries')
      .flush({ entries: [], nextCursor: null });
    fixture.componentInstance.refresh.refreshScoped();
    const testRequest = ctrl.expectOne((request) => request.url === 'https://api.test/api/refresh');
    expect(testRequest.request.params.get('tag')).toBe('3');
    testRequest.flush(refreshDone);
  });

  it('scopes a subscription refresh to the underlying feed id', () => {
    const fixture = boot();
    qp.next(convertToParamMap({ subscription: '5' }));
    fixture.detectChanges();
    ctrl
      .expectOne((request) => request.url === 'https://api.test/api/entries')
      .flush({ entries: [], nextCursor: null });
    fixture.componentInstance.refresh.refreshScoped();
    const testRequest = ctrl.expectOne((request) => request.url === 'https://api.test/api/refresh');
    // The subscription's real feed id (55), not the subscription id (5).
    expect(testRequest.request.params.get('feedId')).toBe('55');
    testRequest.flush(refreshDone);
  });

  it('offers an edit action in the list header for the selected feed', () => {
    const fixture = boot();
    const edit = jest.spyOn(TestBed.inject(ManageActions), 'editSubscription');
    qp.next(convertToParamMap({ subscription: '5' }));
    fixture.detectChanges();
    ctrl
      .expectOne((request) => request.url === 'https://api.test/api/entries')
      .flush({ entries: [], nextCursor: null });
    fixture.detectChanges();

    const button = fixture.nativeElement.querySelector(
      '.list-header .list-edit',
    ) as HTMLButtonElement;
    expect(button).not.toBeNull();
    button.click();

    // The whole subscription, not just its id: the dialog edits the feed the
    // sidebar's own menu edits, through the same service.
    expect(edit).toHaveBeenCalledWith(expect.objectContaining({ id: 5 }));
  });

  it('offers the same edit action for the selected tag', () => {
    const fixture = boot();
    const edit = jest.spyOn(TestBed.inject(ManageActions), 'editTag');
    // The header's glyph and its edit action both read the tag out of the
    // tree, so the tag has to exist there for either to appear.
    TestBed.inject(TagsStore).tags.set([
      { id: 3, name: 'Tech', color: null, icon: null, position: 0 },
    ]);
    qp.next(convertToParamMap({ tag: '3' }));
    fixture.detectChanges();
    ctrl
      .expectOne((request) => request.url === 'https://api.test/api/entries')
      .flush({ entries: [], nextCursor: null });
    fixture.detectChanges();

    (fixture.nativeElement.querySelector('.list-header .list-edit') as HTMLButtonElement).click();
    expect(edit).toHaveBeenCalledWith(expect.objectContaining({ id: 3 }));
  });

  it('leaves the slot empty for a selection that edits nothing', () => {
    const fixture = boot();
    qp.next(convertToParamMap({ view: 'favorites' }));
    fixture.detectChanges();
    ctrl
      .expectOne((request) => request.url === 'https://api.test/api/entries')
      .flush({ entries: [], nextCursor: null });
    fixture.detectChanges();

    expect(fixture.nativeElement.querySelector('.list-header .list-edit')).toBeNull();
  });

  it('does not refresh from the cross-feed saved views', () => {
    const fixture = boot();
    qp.next(convertToParamMap({ view: 'favorites' }));
    fixture.detectChanges();
    ctrl
      .expectOne((request) => request.url === 'https://api.test/api/entries')
      .flush({ entries: [], nextCursor: null });
    fixture.componentInstance.refresh.refreshScoped();
    ctrl.expectNone((request) => request.url === 'https://api.test/api/refresh');
  });

  it('reloads entries and sidebar counts when the selection changes (#664)', () => {
    jest.useFakeTimers({ now: new Date('2026-08-27T16:00:00Z') });
    const fixture = boot();
    jest.advanceTimersByTime(SIDEBAR_RELOAD_INTERVAL_MS);
    qp.next(convertToParamMap({ subscription: '5' }));
    fixture.detectChanges();
    ctrl
      .expectOne((request) => request.params.get('subscription') === '5')
      .flush({ entries: [], nextCursor: null });
    ctrl.expectOne('https://api.test/api/subscriptions').flush({
      ...subscriptionsBody,
      subscriptions: [{ ...subscriptionsBody.subscriptions[0], unreadCount: 3 }],
    });
    fixture.detectChanges();

    expect(TestBed.inject(SubscriptionsStore).totalUnread()).toBe(3);
    expect(fixture.nativeElement.querySelector('.empty')).not.toBeNull();
    jest.useRealTimers();
  });

  it('loads the for-you view and titles the list for it', () => {
    const fixture = boot();
    qp.next(convertToParamMap({ view: 'for-you' }));
    fixture.detectChanges();
    const testRequest = ctrl.expectOne((request) => request.url === 'https://api.test/api/entries');
    expect(testRequest.request.params.get('view')).toBe('for-you');
    testRequest.flush({ entries: [], nextCursor: null });
    fixture.detectChanges();

    expect(fixture.componentInstance.heading.title()).toBe('For you');
  });

  // The For-You badge counts unread picks (#724), so the heading and the tab
  // must label it "unread" too — not "items" — the way All items, a tag and a
  // feed already do. Otherwise the same number is described two ways.
  it('titles the for-you count as unread, matching the sidebar badge', () => {
    localStorage.setItem('sfr.user.1.unread-only', '1');
    const fixture = boot();
    TestBed.inject(RecommendationsService).report.set({
      status: 'completed',
      batchesTotal: 1,
      batchesDone: 1,
      error: null,
      background: false,
      streamedChars: 0,
      elapsedSeconds: null,
      forYou: { itemCount: 7, totalCount: 7, generatedAt: null, newestRunId: null },
    });
    qp.next(convertToParamMap({ view: 'for-you' }));
    fixture.detectChanges();
    ctrl
      .expectOne((request) => request.url === 'https://api.test/api/entries')
      .flush({ entries: [], nextCursor: null });
    fixture.detectChanges();

    const list = fixture.debugElement.query(By.directive(EntryListComponent));
    expect(list.componentInstance.titleCount()).toEqual({ value: 7, counts: 'unread' });
  });

  it('titles the for-you count as its total when All posts is on', () => {
    const fixture = boot();
    TestBed.inject(RecommendationsService).report.set({
      status: 'completed',
      batchesTotal: 1,
      batchesDone: 1,
      error: null,
      background: false,
      streamedChars: 0,
      elapsedSeconds: null,
      forYou: { itemCount: 7, totalCount: 20, generatedAt: null, newestRunId: null },
    });
    qp.next(convertToParamMap({ view: 'for-you' }));
    fixture.detectChanges();
    ctrl
      .expectOne((request) => request.url === 'https://api.test/api/entries')
      .flush({ entries: [], nextCursor: null });
    fixture.detectChanges();

    const list = fixture.debugElement.query(By.directive(EntryListComponent));
    expect(list.componentInstance.titleCount()).toEqual({ value: 20, counts: 'items' });
  });

  // The reader route declares DYNAMIC_TITLE, which tells the title strategy to
  // stand back — so if the reader ever stopped naming the tab, nothing else
  // would, and #549 would be back through the door built for the reader.
  it('names the browser tab after the list on screen, and what it holds', () => {
    localStorage.setItem('sfr.user.1.unread-only', '1');
    const fixture = boot();
    fixture.detectChanges();

    expect(TestBed.inject(Title).getTitle()).toBe('All items (2) | simple feed reader');
  });

  it('names the browser tab after the selected feed and its unread count', () => {
    localStorage.setItem('sfr.user.1.unread-only', '1');
    const fixture = boot();
    qp.next(convertToParamMap({ subscription: '5' }));
    fixture.detectChanges();
    ctrl
      .expectOne((request) => request.url === 'https://api.test/api/entries')
      .flush({ entries: [], nextCursor: null });
    fixture.detectChanges();

    expect(TestBed.inject(Title).getTitle()).toBe('heise (2) | simple feed reader');
  });

  // The heading shows the same number as the tab, from the same computed — two
  // resolutions of "how much is in this list" would drift apart.
  it('hands the list heading the same count the tab shows', () => {
    localStorage.setItem('sfr.user.1.unread-only', '1');
    const fixture = boot();
    fixture.detectChanges();

    const list = fixture.debugElement.query(By.directive(EntryListComponent));
    expect(list.componentInstance.titleCount()).toEqual({ value: 2, counts: 'unread' });
  });

  // Task 5 (#1154): the heading and tab total flip with the switch — the
  // sidebar's unread number under "Only unread", the list's total otherwise.
  it('counts every post in the heading and tab when All posts is on', () => {
    const fixture = boot();
    fixture.detectChanges();

    expect(fixture.componentInstance.heading.titleCount()).toEqual({ value: 9, counts: 'items' });
    expect(TestBed.inject(Title).getTitle()).toBe('All items (9) | simple feed reader');
  });

  it('counts every post of a feed when All posts is on', () => {
    const fixture = boot();
    qp.next(convertToParamMap({ subscription: '5' }));
    fixture.detectChanges();
    ctrl
      .expectOne((request) => request.url === 'https://api.test/api/entries')
      .flush({ entries: [], nextCursor: null });
    fixture.detectChanges();

    expect(fixture.componentInstance.heading.titleCount()).toEqual({ value: 9, counts: 'items' });
  });

  it('counts every post of a tag when All posts is on', () => {
    const fixture = bootWith([
      {
        ...subscriptionsBody.subscriptions[0],
        tags: [{ id: 3, name: 'Tech', color: null, icon: null, position: 0 }],
      },
    ]);
    qp.next(convertToParamMap({ tag: '3' }));
    fixture.detectChanges();
    ctrl
      .expectOne((request) => request.url === 'https://api.test/api/entries')
      .flush({ entries: [], nextCursor: null });
    fixture.detectChanges();

    expect(fixture.componentInstance.heading.titleCount()).toEqual({ value: 9, counts: 'items' });
  });

  it('switches the count when the unread switch flips', () => {
    const fixture = boot();
    TestBed.inject(UnreadFilterService).set(true);
    fixture.detectChanges();
    ctrl
      .expectOne((request) => request.url === 'https://api.test/api/entries')
      .flush({ entries: [], nextCursor: null });

    expect(fixture.componentInstance.heading.titleCount()).toEqual({ value: 2, counts: 'unread' });
  });

  // A search names itself with its own result count, in the heading and in the
  // tab; a second count would say the same thing twice and disagree while the
  // search is in flight.
  it('leaves a search without a count of its own', () => {
    const fixture = boot();
    qp.next(convertToParamMap({ q: 'angular' }));
    fixture.detectChanges();
    ctrl
      .expectOne((request) => request.url.startsWith('https://api.test/api/entries/search'))
      .flush({ entries: [], nextCursor: null });
    fixture.detectChanges();

    expect(fixture.componentInstance.heading.titleCount().value).toBe(0);
  });

  it('names the browser tab after the open article, cut to what a tab shows', () => {
    const headline = 'A headline far longer than any browser tab has ever been able to show';
    const fixture = boot();
    qp.next(convertToParamMap({ entry: '514' }));
    fixture.detectChanges();
    ctrl
      .expectOne((request) => request.url === 'https://api.test/api/entries/514')
      .flush({ entry: { ...entry, id: 514, title: headline, contentHtml: '<p>b</p>' } });
    fixture.detectChanges();
    // A second pass: PageTitleService's toObservable/toSignal pipeline settles
    // one tick after the entry effect writes the page.
    fixture.detectChanges();

    expect(TestBed.inject(Title).getTitle()).toBe(`${headline.slice(0, 60)}… | simple feed reader`);
  });

  // #411: the heading reuses the sidebar's translation keys and follows a
  // language switch.
  describe('translated heading (#411)', () => {
    it('titles the default list with the translated all-items label', () => {
      const fixture = boot();
      expect(fixture.componentInstance.heading.title()).toBe('All items');

      TestBed.inject(LanguageService).set('de');
      fixture.detectChanges();

      // The crux of #411: TranslocoService.translate() is one-shot, so without a
      // language signal in the computed's dependency graph the heading would
      // freeze on the English string a switch never revisits.
      expect(fixture.componentInstance.heading.title()).toBe('Alle Einträge');
    });

    it('titles the favorites list with the translated label', () => {
      const fixture = boot();
      TestBed.inject(LanguageService).set('de');
      qp.next(convertToParamMap({ view: 'favorites' }));
      fixture.detectChanges();
      ctrl
        .expectOne((request) => request.url === 'https://api.test/api/entries')
        .flush({ entries: [], nextCursor: null });
      fixture.detectChanges();

      expect(fixture.componentInstance.heading.title()).toBe('Favoriten');
    });
  });

  describe('searching (#408 follow-up)', () => {
    // The spinner must appear ONLY for a search selection whose list is
    // actually in flight — entries.loading() alone is true for every list
    // load, so all four combinations are pinned to guard against a spinner
    // that lights up for an unrelated feed load.
    it('is false for a non-search selection while its list is not loading', () => {
      const fixture = boot();
      expect(fixture.componentInstance.heading.searching()).toBe(false);
    });

    it('is false for a non-search selection while its list IS loading', () => {
      const fixture = boot();
      qp.next(convertToParamMap({ tag: '9' }));
      fixture.detectChanges();

      expect(fixture.componentInstance.selection().kind).toBe('tag');
      expect(fixture.componentInstance.entries.loading()).toBe(true);
      expect(fixture.componentInstance.heading.searching()).toBe(false);

      ctrl
        .expectOne((request) => request.url === 'https://api.test/api/entries')
        .flush({
          entries: [],
          nextCursor: null,
        });
    });

    it('is false for a search selection once its list has finished loading', () => {
      const fixture = boot();
      qp.next(convertToParamMap({ q: 'angular' }));
      fixture.detectChanges();
      ctrl
        .expectOne((request) => request.url === 'https://api.test/api/entries/search')
        .flush({ entries: [], nextCursor: null });
      fixture.detectChanges();

      expect(fixture.componentInstance.heading.searching()).toBe(false);
    });

    it('is true for a search selection while its list IS loading', () => {
      const fixture = boot();
      qp.next(convertToParamMap({ q: 'angular' }));
      fixture.detectChanges();

      expect(fixture.componentInstance.selection().kind).toBe('search');
      expect(fixture.componentInstance.entries.loading()).toBe(true);
      expect(fixture.componentInstance.heading.searching()).toBe(true);

      ctrl
        .expectOne((request) => request.url === 'https://api.test/api/entries/search')
        .flush({ entries: [], nextCursor: null });
    });

    // The regression this section closes: `selection`'s equality check once
    // reimplemented `sameSelection` inline and forgot `term`, so two search
    // selections compared equal and the reload effect never re-ran for a new term.
    it('reloads the list when the search term changes (#408 follow-up)', () => {
      const fixture = boot();
      qp.next(convertToParamMap({ q: 'daft' }));
      fixture.detectChanges();
      ctrl
        .expectOne(
          (request) =>
            request.url === 'https://api.test/api/entries/search' &&
            request.params.get('q') === 'daft',
        )
        .flush({ entries: [{ ...entry, id: 1, title: 'daft' }], nextCursor: null });
      fixture.detectChanges();

      expect(
        fixture.componentInstance.entries.entries().map((listedEntry) => listedEntry.id),
      ).toEqual([1]);

      qp.next(convertToParamMap({ q: 'daft punk' }));
      fixture.detectChanges();

      // A second request for the new term must actually go out — this is the
      // assertion that catches the bug: with the stale comparator, no request
      // fires and the entries array (and title built from it) stay frozen.
      const secondRequest = ctrl.expectOne(
        (request) =>
          request.url === 'https://api.test/api/entries/search' &&
          request.params.get('q') === 'daft punk',
      );
      secondRequest.flush({ entries: [{ ...entry, id: 2, title: 'daft punk' }], nextCursor: null });
      fixture.detectChanges();

      expect(
        fixture.componentInstance.entries.entries().map((listedEntry) => listedEntry.id),
      ).toEqual([2]);
      expect(fixture.componentInstance.heading.title()).toContain('daft punk');
    });

    it('does not reload the list for an entry-only URL change (original comparator intent)', () => {
      const fixture = boot();
      qp.next(convertToParamMap({ q: 'daft punk' }));
      fixture.detectChanges();
      ctrl
        .expectOne((request) => request.url === 'https://api.test/api/entries/search')
        .flush({ entries: [{ ...entry, id: 2 }], nextCursor: null });
      fixture.detectChanges();

      // Opening an entry changes only the `entry` param, not the selection —
      // no second list request must fire.
      qp.next(convertToParamMap({ q: 'daft punk', entry: '2-daft-punk' }));
      fixture.detectChanges();

      ctrl.expectNone((request) => request.url === 'https://api.test/api/entries/search');
    });

    it('treats two search selections with the same term as the same selection, and a different term as different', () => {
      const fixture = boot();
      qp.next(convertToParamMap({ q: 'daft' }));
      fixture.detectChanges();
      const first = fixture.componentInstance.selection();
      ctrl
        .expectOne((request) => request.url === 'https://api.test/api/entries/search')
        .flush({ entries: [], nextCursor: null });
      fixture.detectChanges();

      // Same params again (e.g. a re-emit with no real change) must not be a
      // new selection reference's worth of behaviour.
      qp.next(convertToParamMap({ q: 'daft' }));
      fixture.detectChanges();
      expect(fixture.componentInstance.selection()).toBe(first);
      ctrl.expectNone((request) => request.url === 'https://api.test/api/entries/search');

      qp.next(convertToParamMap({ q: 'daft punk' }));
      fixture.detectChanges();
      expect(fixture.componentInstance.selection()).not.toBe(first);
      ctrl
        .expectOne((request) => request.url === 'https://api.test/api/entries/search')
        .flush({ entries: [], nextCursor: null });
    });

    it('distinguishes a search selection from a non-search selection sharing kind/id/unread', () => {
      // A search selection always has kind 'search', id null, unread false —
      // the same triple every non-search "all items" selection has too. The
      // comparator must still tell them apart via `term`.
      const fixture = boot();
      qp.next(convertToParamMap({}));
      fixture.detectChanges();
      expect(fixture.componentInstance.selection().kind).toBe('all');

      qp.next(convertToParamMap({ q: 'daft punk' }));
      fixture.detectChanges();

      expect(fixture.componentInstance.selection().kind).toBe('search');
      ctrl
        .expectOne((request) => request.url === 'https://api.test/api/entries/search')
        .flush({ entries: [], nextCursor: null });
    });

    it('strips the trailing whole-word-mode space from the title shown to the user', () => {
      const fixture = boot();
      qp.next(convertToParamMap({ q: 'daft ' }));
      fixture.detectChanges();
      ctrl
        .expectOne(
          (request) =>
            request.url === 'https://api.test/api/entries/search' &&
            request.params.get('q') === 'daft ',
        )
        .flush({ entries: [{ ...entry, id: 1 }], nextCursor: null });
      fixture.detectChanges();

      expect(fixture.componentInstance.heading.title()).not.toContain('daft "');
      expect(fixture.componentInstance.heading.title()).toContain('"daft"');
    });
  });

  describe('selection query params (#408 follow-up)', () => {
    // Opening/closing an article must NOT go through selectionQueryParams: it
    // does not change which list is shown, so `q` (and any other selection
    // param) must survive both the open and the close, letting a Back from an
    // article opened out of search results land back on those results.
    it('keeps q when opening an article', () => {
      const nav = jest.spyOn(TestBed.inject(Router), 'navigate').mockResolvedValue(true);
      const fixture = boot();

      fixture.componentInstance.entryActions.open(entry);

      const queryParams = nav.mock.calls[0][1]?.queryParams as Record<string, unknown>;
      expect(queryParams).not.toHaveProperty('q');
    });

    it('keeps q when closing an article', () => {
      const nav = jest.spyOn(TestBed.inject(Router), 'navigate').mockResolvedValue(true);
      const fixture = boot();

      fixture.componentInstance.onCloseReader();

      const queryParams = nav.mock.calls[0][1]?.queryParams as Record<string, unknown>;
      expect(queryParams).not.toHaveProperty('q');
    });

    it('clears q along with the rest when adding a feed selects its subscription (#408)', () => {
      const nav = jest.spyOn(TestBed.inject(Router), 'navigate').mockResolvedValue(true);
      const fixture = boot();
      qp.next(convertToParamMap({ q: 'angular' }));
      fixture.detectChanges();
      ctrl
        .expectOne((request) => request.url === 'https://api.test/api/entries/search')
        .flush({ entries: [], nextCursor: null });

      const ref = { closed: of({ id: 9, lastFetchedAt: 'x' }) };
      jest.spyOn(TestBed.inject(Dialog), 'open').mockReturnValue(ref as never);
      fixture.componentInstance.refresh.addFeed();
      ctrl.expectOne('https://api.test/api/subscriptions').flush(subscriptionsBody);

      expect(nav).toHaveBeenCalledWith(
        ['/'],
        expect.objectContaining({
          queryParams: {
            view: null,
            tag: null,
            subscription: 9,
            entry: null,
            q: null,
          },
        }),
      );
      ctrl
        .match(() => true)
        .forEach((testRequest) => testRequest.flush({ entries: [], nextCursor: null }));
    });

    it('layers a search over the current list, keeping view/tag/subscription in the URL to return to (#542)', () => {
      const nav = jest.spyOn(TestBed.inject(Router), 'navigate').mockResolvedValue(true);
      const fixture = boot();

      fixture.componentInstance.onSearch('angular');

      expect(nav).toHaveBeenCalledWith(
        ['/'],
        expect.objectContaining({
          queryParams: { q: 'angular', entry: null },
          queryParamsHandling: 'merge',
        }),
      );
    });

    it("onSearch('') drops only the search, so closing it returns to the list it was started from rather than All items (#542)", () => {
      const nav = jest.spyOn(TestBed.inject(Router), 'navigate').mockResolvedValue(true);
      const fixture = boot();

      fixture.componentInstance.onSearch('');

      expect(nav).toHaveBeenCalledWith(
        ['/'],
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
      const fixture = boot();
      // Establish the fixture deliberately: boot() landed one row from the 'all'
      // list, still mounted — this is #254's behaviour (load() clears nextCursor
      // synchronously but leaves the outgoing list rendered until it lands).
      expect(fixture.componentInstance.entries.entries().length).toBe(1);

      qp.next(convertToParamMap({ q: 'angular' }));
      fixture.detectChanges();

      // The search request is now in flight. Prove the trap is live before asserting
      // the title: entries() still holds the stale 'all' row and hasMore() reads
      // false (nextCursor already cleared) — a naive read would show "— 1" here.
      expect(fixture.componentInstance.entries.entries().length).toBe(1);
      expect(fixture.componentInstance.heading.hasMore()).toBe(false);
      expect(fixture.componentInstance.heading.searching()).toBe(true);

      expect(fixture.componentInstance.heading.title()).toBe('Results for "angular"');
      expect(fixture.componentInstance.heading.searchTitlePrefix()).toBe('Results for');
      expect(fixture.componentInstance.heading.searchTitleBody()).toBe('"angular"');
      expect(fixture.componentInstance.heading.searchTitleTerm()).toBe('"angular"');
      // No pill while in flight — the same trap as the dash-form count above:
      // a naive read here would show a stale/false count for a term that has
      // not answered yet.
      expect(fixture.componentInstance.heading.searchCountLabel()).toBeNull();

      ctrl
        .expectOne((request) => request.url === 'https://api.test/api/entries/search')
        .flush({ entries: [], nextCursor: null });
    },
  );

  it('titles a search selection with the translated term and the exact loaded count when there is no further page', () => {
    const fixture = boot();
    qp.next(convertToParamMap({ q: 'angular' }));
    fixture.detectChanges();
    ctrl
      .expectOne((request) => request.url === 'https://api.test/api/entries/search')
      .flush({ entries: [entry, { ...entry, id: 2 }], nextCursor: null });
    fixture.detectChanges();

    expect(fixture.componentInstance.heading.title()).toBe('Results for "angular" — 2');
    expect(fixture.componentInstance.heading.searchTitlePrefix()).toBe('Results for');
    expect(fixture.componentInstance.heading.searchTitleBody()).toBe('"angular" — 2');
    expect(fixture.componentInstance.heading.searchTitleTerm()).toBe('"angular"');
    expect(fixture.componentInstance.heading.searchCountLabel()).toBe('2');
  });

  it('titles a settled search with zero results as the exact count, not the loading form', () => {
    const fixture = boot();
    qp.next(convertToParamMap({ q: 'angular' }));
    fixture.detectChanges();
    ctrl
      .expectOne((request) => request.url === 'https://api.test/api/entries/search')
      .flush({ entries: [], nextCursor: null });
    fixture.detectChanges();

    expect(fixture.componentInstance.heading.searching()).toBe(false);
    expect(fixture.componentInstance.heading.title()).toBe('Results for "angular" — 0');
    expect(fixture.componentInstance.heading.searchTitleBody()).toBe('"angular" — 0');
    expect(fixture.componentInstance.heading.searchCountLabel()).toBe('0');
  });

  it('titles a search selection with a trailing + when another page exists', () => {
    const fixture = boot();
    qp.next(convertToParamMap({ q: 'angular' }));
    fixture.detectChanges();
    ctrl
      .expectOne((request) => request.url === 'https://api.test/api/entries/search')
      .flush({ entries: [entry], nextCursor: 'cursor-2' });
    fixture.detectChanges();

    expect(fixture.componentInstance.heading.title()).toBe('Results for "angular" — 1+');
    expect(fixture.componentInstance.heading.searchTitleBody()).toBe('"angular" — 1+');
    expect(fixture.componentInstance.heading.searchCountLabel()).toBe('1+');
  });

  it('reloads the for-you list when a run completes while it is open', () => {
    const fixture = boot();
    qp.next(convertToParamMap({ view: 'for-you' }));
    fixture.detectChanges();
    ctrl
      .expectOne((request) => request.url === 'https://api.test/api/entries')
      .flush({ entries: [], nextCursor: null });
    fixture.detectChanges();

    TestBed.inject(RecommendationsService).completedStamp.update((stamp) => stamp + 1);
    fixture.detectChanges();

    const testRequest = ctrl.expectOne((request) => request.url === 'https://api.test/api/entries');
    expect(testRequest.request.params.get('view')).toBe('for-you');
    testRequest.flush({ entries: [], nextCursor: null });
  });

  it('keeps the unread filter when a run completes while the for-you list is open', () => {
    localStorage.setItem('sfr.user.1.unread-only', '1');
    const fixture = boot();
    qp.next(convertToParamMap({ view: 'for-you' }));
    fixture.detectChanges();
    ctrl
      .expectOne((request) => request.url === 'https://api.test/api/entries')
      .flush({ entries: [], nextCursor: null });
    fixture.detectChanges();

    TestBed.inject(RecommendationsService).completedStamp.update((stamp) => stamp + 1);
    fixture.detectChanges();

    const testRequest = ctrl.expectOne((request) => request.url === 'https://api.test/api/entries');
    expect(testRequest.request.params.get('unread')).toBe('1');
    testRequest.flush({ entries: [], nextCursor: null });
  });

  it('does not reload another list when a for-you run completes off-screen', () => {
    const fixture = boot();
    TestBed.inject(RecommendationsService).completedStamp.update((stamp) => stamp + 1);
    fixture.detectChanges();
    ctrl.expectNone((request) => request.url === 'https://api.test/api/entries');
  });

  // The run trigger lives in the list header now (#325), gated on AI being
  // ready — the same gate the sidebar's For You link uses — so a booted for-you
  // view marks readiness before it expects the button.
  function bootForYou() {
    const fixture = boot();
    TestBed.inject(AiAvailabilityService).apply({ ready: true, model: 'gpt', capabilities: null });
    qp.next(convertToParamMap({ view: 'for-you' }));
    fixture.detectChanges();
    ctrl
      .expectOne((request) => request.url === 'https://api.test/api/entries')
      .flush({ entries: [], nextCursor: null });
    fixture.detectChanges();
    return fixture;
  }

  const runningReport = {
    status: 'running' as const,
    batchesTotal: 3,
    batchesDone: 1,
    error: null,
    background: false,
    streamedChars: 0,
    elapsedSeconds: null,
    forYou: { itemCount: 0, totalCount: 0, generatedAt: null, newestRunId: null },
  };

  const failedReport = {
    status: 'failed' as const,
    batchesTotal: 3,
    batchesDone: 2,
    error: 'The AI provider at http://x/v1 failed: That provider answered with status 400.',
    background: false,
    resumable: true,
    streamedChars: 0,
    elapsedSeconds: null,
    forYou: { itemCount: 0, totalCount: 0, generatedAt: null, newestRunId: null },
  };

  function menuItem(text: string): HTMLElement {
    const item = [...document.querySelectorAll('[role="menuitem"]')].find((menuEntry) =>
      menuEntry.textContent?.includes(text),
    ) as HTMLElement | undefined;
    expect(item).not.toBeUndefined();
    return item!;
  }

  it('withholds the run button until AI is ready', () => {
    const fixture = boot();
    qp.next(convertToParamMap({ view: 'for-you' }));
    fixture.detectChanges();
    ctrl
      .expectOne((request) => request.url === 'https://api.test/api/entries')
      .flush({ entries: [], nextCursor: null });
    fixture.detectChanges();

    expect(fixture.nativeElement.querySelector('.for-you-run')).toBeNull();
  });

  it('shows the run button in the list header and starts a run only after the user confirms', () => {
    const fixture = bootForYou();
    const recommendations = TestBed.inject(RecommendationsService);

    const button = fixture.nativeElement.querySelector(
      '.list-header .for-you-run',
    ) as HTMLButtonElement;
    expect(button).not.toBeNull();
    // "Refresh", not "Get recommendations" (#710): named after what it produces,
    // like every other header action. The sparkle glyph sets it apart from the
    // ordinary feed refresh, and the accessible name says which refresh this is.
    expect(button.textContent).toContain('Refresh');
    expect(button.textContent).not.toContain('Get recommendations');
    expect(button.getAttribute('aria-label')).toBe('Refresh recommendations');
    expect(button.querySelector('app-icon')?.textContent?.trim()).toBe('auto_awesome');
    // The same control the unread switch and Mark all read are, down to the
    // border: three actions in one header must not wear three chromes. It used
    // to be a filled primary button, the loudest thing in a quiet bar.
    expect(button.classList.contains('list-action')).toBe(true);
    expect(button.classList.contains('accent-outline')).toBe(false);
    expect(button.classList.contains('primary')).toBe(false);
    // The header never carries the progress caption: it is the pill's, on
    // every route, running or not (#398).
    expect(fixture.nativeElement.querySelector('.for-you-progress')).toBeNull();

    // The click only opens the confirmation: nothing is requested until it is
    // accepted, because a run is long and spends provider budget.
    button.click();
    fixture.detectChanges();
    ctrl.expectNone('https://api.test/api/recommendations/runs');

    const confirm = document.querySelector('[data-testid="confirm"]') as HTMLButtonElement;
    expect(confirm).not.toBeNull();
    confirm.click();
    fixture.detectChanges();

    ctrl.expectOne('https://api.test/api/recommendations/runs').flush(runningReport);
    fixture.detectChanges();
    expect(recommendations.running()).toBe(true);
    ctrl.expectOne('https://api.test/api/recommendations/runs/tick').flush(runningReport);
  });

  it('starts no run when the confirmation is dismissed', () => {
    const fixture = bootForYou();

    (fixture.nativeElement.querySelector('.for-you-run') as HTMLButtonElement).click();
    fixture.detectChanges();

    const cancel = [...document.querySelectorAll('app-button button')].find(
      (button) => button.textContent?.trim() === 'Cancel',
    ) as HTMLButtonElement;
    expect(cancel).not.toBeUndefined();
    cancel.click();
    fixture.detectChanges();

    ctrl.expectNone('https://api.test/api/recommendations/runs');
    expect(TestBed.inject(RecommendationsService).running()).toBe(false);
  });

  it('offers resume or start-over for a failed run, and resumes on choice', () => {
    const fixture = bootForYou();
    const recommendations = TestBed.inject(RecommendationsService);
    recommendations.report.set(failedReport);
    fixture.detectChanges();

    (fixture.nativeElement.querySelector('.for-you-run') as HTMLButtonElement).click();
    fixture.detectChanges();

    // The plain confirm never opens; the choice sheet stands in for it, and
    // nothing is requested until the user picks.
    ctrl.expectNone('https://api.test/api/recommendations/runs');
    menuItem('Resume unfinished run').click();
    fixture.detectChanges();

    ctrl.expectOne('https://api.test/api/recommendations/runs/resume').flush(runningReport);
    fixture.detectChanges();
    expect(recommendations.running()).toBe(true);
    ctrl.expectOne('https://api.test/api/recommendations/runs/tick').flush(runningReport);
  });

  it('starts a fresh run when start-over is chosen over a failed run', () => {
    const fixture = bootForYou();
    TestBed.inject(RecommendationsService).report.set(failedReport);
    fixture.detectChanges();

    (fixture.nativeElement.querySelector('.for-you-run') as HTMLButtonElement).click();
    fixture.detectChanges();

    menuItem('Start a new run').click();
    fixture.detectChanges();

    ctrl.expectOne('https://api.test/api/recommendations/runs').flush(runningReport);
    fixture.detectChanges();
    ctrl.expectOne('https://api.test/api/recommendations/runs/tick').flush(runningReport);
  });

  it('goes straight to the fresh-run confirm for a failed run the server cannot resume', () => {
    const fixture = bootForYou();
    TestBed.inject(RecommendationsService).report.set({
      ...failedReport,
      error: 'Profile generation failed: No connection can build your profile.',
      resumable: false,
    });
    fixture.detectChanges();

    (fixture.nativeElement.querySelector('.for-you-run') as HTMLButtonElement).click();
    fixture.detectChanges();

    expect(document.querySelector('[role="menuitem"]')).toBeNull();
    (document.querySelector('[data-testid="confirm"]') as HTMLButtonElement).click();
    fixture.detectChanges();

    ctrl.expectOne('https://api.test/api/recommendations/runs').flush(runningReport);
    fixture.detectChanges();
    ctrl.expectOne('https://api.test/api/recommendations/runs/tick').flush(runningReport);
  });

  it('replaces the run button with a stop button while a run is in flight', () => {
    const fixture = bootForYou();
    const recommendations = TestBed.inject(RecommendationsService);
    recommendations.running.set(true);
    recommendations.report.set(runningReport);
    fixture.detectChanges();

    // Only the Stop button remains — starting a second run over a live one is
    // exactly what the single toggling slot prevents — with the batch count
    // beneath it and no failure alert clutter in the header (#325).
    const buttons = [...fixture.nativeElement.querySelectorAll('.for-you-run')];
    expect(buttons.length).toBe(1);
    expect(buttons[0].querySelector('.txt')!.textContent!.trim()).toBe('Stop');
    // The count, the ETA and the bar left the LIST header in #398 and never
    // came back; a live run leaves nothing but the Stop button there. On this
    // (wide) layout they read out from the app bar instead of the pill (#435).
    expect(fixture.nativeElement.querySelector('.list-header .for-you-progress')).toBeNull();
    expect(
      fixture.nativeElement.querySelector('app-reader-header .for-you-progress'),
    ).not.toBeNull();
    expect(fixture.nativeElement.querySelector('.list-header [role="alert"]')).toBeNull();
  });

  it('draws the refresh bar inside the app bar, and nowhere else', () => {
    const fixture = boot();
    TestBed.inject(RefreshService).running.set(true);
    fixture.detectChanges();
    const element = fixture.nativeElement as HTMLElement;

    // Exactly one. Two bars for one refresh is #721's first symptom.
    expect(element.querySelectorAll('app-progress-hairline').length).toBe(1);
    // Inside the bar, so it travels with it — parked below, it retracted on scroll
    // and left a 2px band. The hairline gates `.bar` on `active()`, so checking the
    // rendered bar (not just the always-present host) exercises the binding.
    expect(element.querySelector('app-reader-header app-progress-hairline .bar')).not.toBeNull();
    expect(element.querySelector('.under-header app-progress-hairline')).toBeNull();
  });

  it('offers a way back to the pill only once the pill has been closed', () => {
    // A phone-layout concern: above the drawer breakpoint the app bar carries
    // the run and there is no ✕, so there is nothing to offer back (#435).
    screen.isNarrow.set(true);
    const fixture = bootForYou();
    const recommendations = TestBed.inject(RecommendationsService);
    const toast = TestBed.inject(ToastService);
    recommendations.running.set(true);
    recommendations.report.set(runningReport);
    toast.show({ message: 'stand-in for the run pill' });
    fixture.detectChanges();

    // Nothing to restore while it is on screen.
    expect(fixture.nativeElement.querySelector('.for-you-show')).toBeNull();

    toast.dismiss();
    fixture.detectChanges();

    const restore = fixture.nativeElement.querySelector('button.for-you-show') as HTMLButtonElement;
    expect(restore.classList).toContain('list-action');
    expect(restore.querySelector('.txt')!.textContent!.trim()).toBe('Show progress');

    const raise = jest.spyOn(recommendations, 'showRunPill');
    restore.click();
    expect(raise).toHaveBeenCalledTimes(1);
  });

  it('stops the run when the stop button is clicked', () => {
    const fixture = bootForYou();
    const recommendations = TestBed.inject(RecommendationsService);
    const stop = jest.spyOn(recommendations, 'stop');
    recommendations.running.set(true);
    recommendations.report.set(runningReport);
    fixture.detectChanges();

    const stopButton = fixture.nativeElement.querySelector(
      'button.for-you-run',
    ) as HTMLButtonElement;
    expect(stopButton.classList).toContain('list-action');
    stopButton.click();

    expect(stop).toHaveBeenCalledTimes(1);
  });

  // The heading names the tag, so it also carries the tag's glyph and colour —
  // the same pair the sidebar row shows. Both come from one lookup, so the two
  // can never describe different tags.
  it('hands the list the selected tag, and nothing for any other selection', () => {
    const science = { id: 7, name: 'Wissenschaft', color: '#c2410c', icon: 'science', position: 0 };
    const fixture = TestBed.createComponent(ReaderShellComponent);
    fixture.detectChanges();
    ctrl.expectOne('https://api.test/api/subscriptions').flush(subscriptionsBody);
    ctrl.expectOne('https://api.test/api/tags').flush({ tags: [science] });
    ctrl
      .expectOne((request) => request.url === 'https://api.test/api/entries')
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
    fixture.detectChanges();

    const list = () =>
      fixture.debugElement.query(By.directive(EntryListComponent))
        .componentInstance as EntryListComponent;
    expect(list().titleTag()).toBeNull();

    qp.next(convertToParamMap({ tag: '7' }));
    fixture.detectChanges();
    ctrl
      .expectOne((request) => request.params.get('tag') === '7')
      .flush({ entries: [], nextCursor: null });
    fixture.detectChanges();

    expect(list().titleTag()).toEqual(science);
    expect(fixture.componentInstance.heading.title()).toBe('Wissenschaft');
  });

  it('forwards the header tap to the entry list', () => {
    const fixture = boot();
    const list = fixture.debugElement.query(By.directive(EntryListComponent))
      .componentInstance as EntryListComponent;
    const jump = jest.spyOn(list, 'scrollToTop').mockImplementation(() => undefined);

    const header = fixture.debugElement.query(By.directive(ReaderHeaderComponent))
      .componentInstance as ReaderHeaderComponent;
    header.scrollTop.emit();

    expect(jump).toHaveBeenCalledTimes(1);
  });

  describe('onboarding redirect and first sweep', () => {
    it('waits for the current user subscriptions before offering the catalog', async () => {
      const tokens = TestBed.inject(TokenStore);
      tokens.set('user-a.jwt');
      const subscriptions = TestBed.inject(SubscriptionsStore);
      subscriptions.load();
      ctrl.expectOne('https://api.test/api/subscriptions').flush({
        subscriptions: [],
        favoritesCount: 0,
        keptCount: 0,
        viewedCount: 0,
      });

      tokens.set('user-b.jwt');
      TestBed.tick();
      const nav = jest.spyOn(TestBed.inject(Router), 'navigate').mockResolvedValue(true);
      const fixture = TestBed.createComponent(ReaderShellComponent);
      fixture.detectChanges();

      const currentSubscriptions = ctrl.expectOne('https://api.test/api/subscriptions');
      ctrl.expectOne('https://api.test/api/tags').flush({ tags: [] });
      ctrl.expectOne('https://api.test/api/saved-searches').flush({ savedSearches: [] });
      ctrl
        .expectOne((request) => request.url === 'https://api.test/api/entries')
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
      ctrl.expectOne('https://api.test/api/version').flush({
        version: 'dev',
        commit: 'local',
        builtAt: '',
        latest: null,
        updateAvailable: false,
      });
      await fixture.whenStable();
      fixture.detectChanges();

      expect(nav).not.toHaveBeenCalledWith(['/discover'], { replaceUrl: true });
      ctrl.expectNone('https://api.test/api/catalog');

      currentSubscriptions.flush({
        subscriptions: [SUBSCRIPTION_FIXTURE],
        favoritesCount: 0,
        keptCount: 0,
        viewedCount: 0,
      });
      await fixture.whenStable();
      fixture.detectChanges();

      expect(nav).not.toHaveBeenCalledWith(['/discover'], { replaceUrl: true });
      ctrl.expectNone('https://api.test/api/catalog');
    });

    it('redirects a user with no subscriptions to the picker, replacing the URL', async () => {
      const nav = jest.spyOn(TestBed.inject(Router), 'navigate').mockResolvedValue(true);
      const fixture = bootWith([]);
      await fixture.whenStable();
      ctrl.expectOne('https://api.test/api/catalog').flush(CATALOG_WITH_FEEDS);
      await fixture.whenStable();
      fixture.detectChanges();
      expect(nav).toHaveBeenCalledWith(['/discover'], { replaceUrl: true });
    });

    it('does not redirect when nobody has imported a catalog yet', async () => {
      const nav = jest.spyOn(TestBed.inject(Router), 'navigate').mockResolvedValue(true);
      const fixture = bootWith([]);
      await fixture.whenStable();
      ctrl.expectOne('https://api.test/api/catalog').flush({ categories: [] });
      await fixture.whenStable();
      fixture.detectChanges();
      expect(nav).not.toHaveBeenCalledWith(['/discover'], { replaceUrl: true });
    });

    it('does not redirect when the catalog cannot be loaded', async () => {
      const nav = jest.spyOn(TestBed.inject(Router), 'navigate').mockResolvedValue(true);
      const fixture = bootWith([]);
      await fixture.whenStable();
      ctrl
        .expectOne('https://api.test/api/catalog')
        .flush({ type: 'x', title: 't', status: 500 }, { status: 500, statusText: 'err' });
      await fixture.whenStable();
      fixture.detectChanges();
      expect(nav).not.toHaveBeenCalledWith(['/discover'], { replaceUrl: true });
    });

    it('does not redirect when the subscriptions request fails (#691)', async () => {
      // A 500 leaves the store resolved with an empty list and an error set. That
      // must not read as "this user has zero subscriptions": no catalog fetch, no
      // redirect to the picker — the user stays on the reader with the error.
      const nav = jest.spyOn(TestBed.inject(Router), 'navigate').mockResolvedValue(true);
      const fixture = TestBed.createComponent(ReaderShellComponent);
      fixture.detectChanges();
      ctrl
        .expectOne('https://api.test/api/subscriptions')
        .flush({ type: 'x', title: 't', status: 500 }, { status: 500, statusText: 'err' });
      ctrl.expectOne('https://api.test/api/tags').flush({ tags: [] });
      ctrl.expectOne('https://api.test/api/saved-searches').flush({ savedSearches: [] });
      ctrl
        .expectOne((request) => request.url === 'https://api.test/api/entries')
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
      ctrl.expectOne('https://api.test/api/version').flush({
        version: 'dev',
        commit: 'local',
        builtAt: '',
        latest: null,
        updateAvailable: false,
      });
      fixture.detectChanges();
      await fixture.whenStable();
      fixture.detectChanges();

      ctrl.expectNone('https://api.test/api/catalog');
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
      const fixture = bootWith([]);
      await fixture.whenStable();
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
      const fixture = bootWith([
        { ...SUBSCRIPTION_FIXTURE, id: 1, lastFetchedAt: null },
        { ...SUBSCRIPTION_FIXTURE, id: 2, feedId: 12, lastFetchedAt: null },
      ]);
      // The sweep fired a real refresh; a partial slice keeps it running, so the
      // counted banner shows this-much-done.
      ctrl
        .expectOne((request) => request.url === 'https://api.test/api/refresh')
        .flush({
          ...refreshDone,
          status: 'partial',
          progress: { done: 1, total: 2 },
          remaining: 1,
          fetched: 1,
        });
      fixture.detectChanges();
      const banner = (fixture.nativeElement as HTMLElement).querySelector('.fetch-banner');
      expect(banner).not.toBeNull();
      expect(banner!.textContent).toContain('1 of 2');
      // The partial re-armed the poll; finish it so the sweep completes.
      ctrl
        .expectOne((request) => request.url === 'https://api.test/api/refresh')
        .flush(refreshDone);
    });

    it('does not reshow the fetch banner on a later refresh once the sweep has landed', () => {
      const fixture = bootWith([{ ...SUBSCRIPTION_FIXTURE, id: 1, lastFetchedAt: null }]);
      // Complete the onboarding sweep successfully → the banner window closes.
      ctrl
        .expectOne((request) => request.url === 'https://api.test/api/refresh')
        .flush(refreshDone);
      fixture.detectChanges();
      expect((fixture.nativeElement as HTMLElement).querySelector('.fetch-banner')).toBeNull();

      // A later manual refresh (the sidebar button) must NOT bring the counted
      // banner back over the now-populated reader — it belongs to the sweep only.
      fixture.componentInstance.refresh.refreshAll();
      fixture.detectChanges();
      ctrl
        .expectOne((request) => request.url === 'https://api.test/api/refresh')
        .flush(refreshDone);
      fixture.detectChanges();
      expect((fixture.nativeElement as HTMLElement).querySelector('.fetch-banner')).toBeNull();
    });

    // The guard for the clause below: mid-run, with motion allowed, the counted
    // banner must still stay away. The test above only checks after the run ends
    // (`running()` false) — it would pass even if the banner showed the whole run.
    it('leaves an ordinary refresh uncounted while it is still going', () => {
      // jsdom answers "no" to every media query, so this is the motion-allowed path.
      const fixture = bootWith([
        { ...SUBSCRIPTION_FIXTURE, id: 1, lastFetchedAt: '2026-07-26T10:00:00+00:00' },
      ]);
      fixture.componentInstance.refresh.refreshAll();
      fixture.detectChanges();
      ctrl
        .expectOne((request) => request.url === 'https://api.test/api/refresh')
        .flush({
          ...refreshDone,
          status: 'partial',
          progress: { done: 20, total: 200 },
          remaining: 180,
          fetched: 20,
        });
      fixture.detectChanges();

      expect((fixture.nativeElement as HTMLElement).querySelector('.fetch-banner')).toBeNull();

      ctrl
        .expectOne((request) => request.url === 'https://api.test/api/refresh')
        .flush(refreshDone);
    });
  });

  describe('refresh failures', () => {
    /** Boot a reader whose feeds are already fetched (no onboarding sweep), then
     *  refresh and answer with `respond`. Covers the ORDINARY refresh path — the
     *  sidebar button, a scoped refresh, add-feed — which told the user nothing before #119. */
    const refreshAnsweredWith = (respond: (request: TestRequest) => void) => {
      const fixture = bootWith([
        { ...SUBSCRIPTION_FIXTURE, id: 1, lastFetchedAt: '2026-07-26T10:00:00+00:00' },
      ]);
      fixture.componentInstance.refresh.refreshAll();
      fixture.detectChanges();
      respond(ctrl.expectOne((request) => request.url === 'https://api.test/api/refresh'));
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
      ctrl
        .expectOne((request) => request.url === 'https://api.test/api/refresh')
        .flush(refreshDone);
      fixture.detectChanges();

      expect(banner()).toBeNull(); // a clean retry clears it
    });

    // An aborted sweep left feeds unfetched and still due. It shared the
    // `completed` branch, so it presented exactly like a clean run.
    it('says a sweep stopped early rather than showing it as finished', () => {
      const { banner } = refreshAnsweredWith((request) =>
        request.flush({
          ...refreshDone,
          status: 'aborted',
          progress: { done: 3, total: 10 },
          remaining: 7,
          fetched: 3,
        }),
      );

      expect(banner()?.textContent).toContain('The refresh stopped early.');
    });

    it('marks the failure as an alert, not a status update', () => {
      const { banner } = refreshAnsweredWith((request) =>
        request.flush({
          ...refreshDone,
          status: 'aborted',
          progress: { done: 0, total: 4 },
          remaining: 4,
        }),
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
      const fixture = bootWith([
        { ...SUBSCRIPTION_FIXTURE, id: 1, lastFetchedAt: '2026-07-26T10:00:00+00:00' },
      ]);
      await fixture.whenStable();
      // An admin gets the catalog fetched EVEN WITH their own subscriptions — the
      // loadCatalogForAdmin effect, not the redirect (which returns on non-empty subs).
      ctrl.expectOne('https://api.test/api/catalog').flush({ categories: [] });
      fixture.detectChanges();
      const warning = (fixture.nativeElement as HTMLElement).querySelector(
        '[data-testid="catalog-empty-warning"]',
      );
      expect(warning).not.toBeNull();
      expect(warning!.querySelector('a')!.getAttribute('href')).toBe('/settings/admin/catalog');
    });

    it('shows an admin no warning once a catalog exists', async () => {
      auth.isAdmin.mockReturnValue(true);
      const fixture = bootWith([
        { ...SUBSCRIPTION_FIXTURE, id: 1, lastFetchedAt: '2026-07-26T10:00:00+00:00' },
      ]);
      await fixture.whenStable();
      ctrl.expectOne('https://api.test/api/catalog').flush(CATALOG_WITH_FEEDS);
      fixture.detectChanges();
      expect(
        (fixture.nativeElement as HTMLElement).querySelector(
          '[data-testid="catalog-empty-warning"]',
        ),
      ).toBeNull();
    });

    it('never shows the warning to a non-admin', async () => {
      const nav = jest.spyOn(TestBed.inject(Router), 'navigate').mockResolvedValue(true);
      const fixture = bootWith([]);
      await fixture.whenStable();
      // The redirect effect (empty subs, non-admin) is what fetches the catalog here.
      ctrl.expectOne('https://api.test/api/catalog').flush({ categories: [] });
      await fixture.whenStable();
      fixture.detectChanges();
      expect(
        (fixture.nativeElement as HTMLElement).querySelector(
          '[data-testid="catalog-empty-warning"]',
        ),
      ).toBeNull();
      expect(nav).not.toHaveBeenCalledWith(['/discover'], { replaceUrl: true });
    });
  });

  describe('list scroll reset', () => {
    // The rule itself is proved in list-scroll-reset.spec.ts. This proves the
    // shell starts it, which is the one thing that file cannot show: without the
    // constructor call nothing listens and the offset survives the click (#286).
    it('drops the offset of a list the user clicks', () => {
      // A bare `/` parses to the all-items list with the default (all) filter.
      const allItems: Selection = { kind: 'all', id: null, unread: false };
      bootWith([SUBSCRIPTION_FIXTURE]);
      const memory = TestBed.inject(ListScrollMemory);
      const events = TestBed.inject(Router).events as Subject<RouterEvent>;

      events.next(new NavigationStart(1, '/?tag=5', 'imperative'));
      memory.save(allItems, 300);
      events.next(new NavigationStart(2, '/', 'imperative'));

      expect(memory.read(allItems)).toBe(0);
    });
  });

  describe('loading state while the account gate is closed', () => {
    it('renders the skeleton, not the empty state, with a claimless token and no account yet', () => {
      reconfigureShell([
        { provide: AuthService, useValue: { ...auth, user: signal(undefined) } },
        provideAccountIdentity(signal(null)),
      ]);
      const fixture = TestBed.createComponent(ReaderShellComponent);
      fixture.detectChanges();
      ctrl.expectOne('https://api.test/api/tags').flush({ tags: [] });
      ctrl.expectOne('https://api.test/api/saved-searches').flush({ savedSearches: [] });
      ctrl.expectOne('https://api.test/api/recommendations/runs/current').flush({
        status: 'none',
        batchesTotal: null,
        batchesDone: 0,
        error: null,
        background: false,
        streamedChars: 0,
        forYou: { itemCount: 0, generatedAt: null, newestRunId: null },
      });
      ctrl.expectOne('https://api.test/api/version').flush({
        version: 'dev',
        commit: 'local',
        builtAt: '',
        latest: null,
        updateAvailable: false,
      });
      ctrl.expectNone((request) => request.url === 'https://api.test/api/subscriptions');
      ctrl.expectNone((request) => request.url === 'https://api.test/api/entries');
      fixture.detectChanges();

      const root = fixture.nativeElement as HTMLElement;
      expect(root.querySelector('.skeleton')).toBeTruthy();
      expect(root.querySelector('.empty')).toBeFalsy();
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
          {
            provide: ActivatedRoute,
            useValue: { queryParamMap: qp.asObservable(), paramMap: pp.asObservable() },
          },
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
      const controller = TestBed.inject(HttpTestingController);
      const fixture = TestBed.createComponent(ReaderShellComponent);
      fixture.detectChanges();
      controller
        .match(() => true)
        .forEach((testRequest) =>
          testRequest.flush({
            subscriptions: [],
            tags: [],
            entries: [],
            savedSearches: [],
            favoritesCount: 0,
            keptCount: 0,
            nextCursor: null,
          }),
        );
      fixture.detectChanges();

      const body = (fixture.nativeElement as HTMLElement).querySelector('.body')!;
      expect(body.classList).toContain('is-narrow');
      (narrow as import('@angular/core').WritableSignal<boolean>).set(false);
      fixture.detectChanges();
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
          {
            provide: ActivatedRoute,
            useValue: { queryParamMap: qp.asObservable(), paramMap: pp.asObservable() },
          },
          { provide: AuthService, useValue: auth },
        ],
      });
      TestBed.overrideProvider(LayoutService, {
        useValue: { isNarrow: signal(true), isWide: signal(false), isCoarse: signal(true) },
      });
    });

    it('pauses the drawer swipe while organising', () => {
      const controller = TestBed.inject(HttpTestingController);
      const fixture = TestBed.createComponent(ReaderShellComponent);
      fixture.detectChanges();
      controller
        .match(() => true)
        .forEach((testRequest) =>
          testRequest.flush({
            subscriptions: [],
            tags: [],
            entries: [],
            savedSearches: [],
            favoritesCount: 0,
            keptCount: 0,
            nextCursor: null,
          }),
        );
      fixture.detectChanges();

      const swipe = fixture.debugElement
        .query(By.directive(DrawerSwipeDirective))
        .injector.get(DrawerSwipeDirective);
      expect(swipe.disabled()).toBe(false);

      fixture.componentInstance.sidebarOrganising.set(true);
      fixture.detectChanges();
      expect(swipe.disabled()).toBe(true);
    });

    it('resets organising when the drawer closes', () => {
      const controller = TestBed.inject(HttpTestingController);
      const fixture = TestBed.createComponent(ReaderShellComponent);
      fixture.detectChanges();
      controller
        .match(() => true)
        .forEach((testRequest) =>
          testRequest.flush({
            subscriptions: [],
            tags: [],
            entries: [],
            savedSearches: [],
            favoritesCount: 0,
            keptCount: 0,
            nextCursor: null,
          }),
        );
      fixture.componentInstance.setSidebarOpen(true);
      fixture.componentInstance.sidebarOrganising.set(true);
      fixture.componentInstance.setSidebarOpen(false);
      expect(fixture.componentInstance.sidebarOrganising()).toBe(false);
    });
  });

  describe('the mobile drawer and the settings menu never overlap', () => {
    beforeEach(() => {
      TestBed.resetTestingModule();
      TestBed.configureTestingModule({
        imports: [ReaderShellComponent, provideTranslocoTesting()],
        providers: [
          provideHttpClient(),
          provideHttpClientTesting(),
          provideRouter([]),
          { provide: API_BASE_URL, useValue: 'https://api.test' },
          {
            provide: ActivatedRoute,
            useValue: { queryParamMap: qp.asObservable(), paramMap: pp.asObservable() },
          },
          { provide: AuthService, useValue: auth },
        ],
      });
      TestBed.overrideProvider(LayoutService, {
        useValue: { isNarrow: signal(true), isWide: signal(false), isCoarse: signal(true) },
      });
    });

    function bootNarrow() {
      const controller = TestBed.inject(HttpTestingController);
      const fixture = TestBed.createComponent(ReaderShellComponent);
      fixture.detectChanges();
      controller
        .match(() => true)
        .forEach((testRequest) =>
          testRequest.flush({
            subscriptions: [],
            tags: [],
            entries: [],
            savedSearches: [],
            favoritesCount: 0,
            keptCount: 0,
            nextCursor: null,
          }),
        );
      fixture.detectChanges();
      return fixture;
    }

    function avatarButton(fixture: ComponentFixture<ReaderShellComponent>) {
      return (fixture.nativeElement as HTMLElement).querySelector(
        '[aria-haspopup="menu"]',
      ) as HTMLButtonElement;
    }

    it('closes the drawer when the settings menu opens', () => {
      const fixture = bootNarrow();
      fixture.componentInstance.setSidebarOpen(true);
      fixture.detectChanges();

      avatarButton(fixture).click();
      fixture.detectChanges();

      expect((fixture.nativeElement as HTMLElement).querySelector('.menu')).not.toBeNull();
      expect(fixture.componentInstance.sidebarOpen()).toBe(false);
    });

    it('closes the settings menu when the drawer opens', () => {
      const fixture = bootNarrow();
      avatarButton(fixture).click();
      fixture.detectChanges();
      expect((fixture.nativeElement as HTMLElement).querySelector('.menu')).not.toBeNull();

      fixture.componentInstance.setSidebarOpen(true);
      fixture.detectChanges();

      expect((fixture.nativeElement as HTMLElement).querySelector('.menu')).toBeNull();
    });
  });

  describe('collapsing a row out of a saved view (#478)', () => {
    // Boot the shell straight into a given view with one entry in a chosen state,
    // draining the four requests every boot fires.
    function bootInto(view: string, entryOverride: Partial<EntryDto>) {
      qp.next(convertToParamMap({ view }));
      const fixture = TestBed.createComponent(ReaderShellComponent);
      fixture.detectChanges();
      ctrl
        .expectOne('https://api.test/api/subscriptions')
        .flush({ ...subscriptionsBody, favoritesCount: 3, keptCount: 3, viewedCount: 3 });
      ctrl.expectOne('https://api.test/api/tags').flush({ tags: [] });
      ctrl
        .expectOne((request) => request.url === 'https://api.test/api/entries')
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
      fixture.detectChanges();
      return fixture;
    }

    function flushStatePatch() {
      ctrl
        .expectOne((request) => request.url === 'https://api.test/api/entries/1/state')
        .flush({ state: {} });
    }

    it('collapses an un-favourited row but keeps it in the data so the plan holds', () => {
      const fixture = bootInto('favorites', { isFavorite: true });
      fixture.componentInstance.entryActions.favorite(
        fixture.componentInstance.entries.entries()[0],
      );
      flushStatePatch();

      expect(fixture.componentInstance.entryActions.leavingIds().has(1)).toBe(true);
      // Kept in entries() on purpose: dropping it would re-flow the magazine plan.
      expect(
        fixture.componentInstance.entries.entries().some((listedEntry) => listedEntry.id === 1),
      ).toBe(true);
    });

    it('does NOT collapse the row when the flag is toggled outside its saved view', () => {
      const fixture = bootInto('all', { isFavorite: true });
      fixture.componentInstance.entryActions.favorite(
        fixture.componentInstance.entries.entries()[0],
      );
      flushStatePatch();

      expect(fixture.componentInstance.entryActions.leavingIds().has(1)).toBe(false);
    });

    it('collapses a Recently-read row on un-tick and drops the viewed badge', () => {
      const fixture = bootInto('viewed', { isHidden: true, isViewed: true });
      const subscriptions = TestBed.inject(SubscriptionsStore);
      expect(subscriptions.viewedCount()).toBe(3);

      fixture.componentInstance.entryActions.toggleRead(
        fixture.componentInstance.entries.entries()[0],
      );
      flushStatePatch();

      expect(fixture.componentInstance.entryActions.leavingIds().has(1)).toBe(true);
      expect(subscriptions.viewedCount()).toBe(2);
    });

    it('un-collapses the row and restores the badge when the PATCH fails', () => {
      const fixture = bootInto('viewed', { isHidden: true, isViewed: true });
      const subscriptions = TestBed.inject(SubscriptionsStore);

      fixture.componentInstance.entryActions.toggleRead(
        fixture.componentInstance.entries.entries()[0],
      );
      ctrl
        .expectOne((request) => request.url === 'https://api.test/api/entries/1/state')
        .error(new ProgressEvent('fail'));

      expect(fixture.componentInstance.entryActions.leavingIds().has(1)).toBe(false);
      expect(subscriptions.viewedCount()).toBe(3);
    });

    it('clears the collapsed set when the selection changes', () => {
      const fixture = bootInto('favorites', { isFavorite: true });
      fixture.componentInstance.entryActions.favorite(
        fixture.componentInstance.entries.entries()[0],
      );
      flushStatePatch();
      expect(fixture.componentInstance.entryActions.leavingIds().has(1)).toBe(true);

      qp.next(convertToParamMap({ view: 'kept' }));
      fixture.detectChanges();
      ctrl
        .expectOne((request) => request.url === 'https://api.test/api/entries')
        .flush({ entries: [], nextCursor: null });

      expect(fixture.componentInstance.entryActions.leavingIds().size).toBe(0);
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
      const fixture = bootWith([{ ...SUBSCRIPTION_FIXTURE, ...overrides }]);
      fixture.componentInstance.layout.set(layout);
      const id = String(SUBSCRIPTION_FIXTURE.id);
      qp.next(convertToParamMap({ subscription: id }));
      fixture.detectChanges();
      ctrl
        .expectOne((request) => request.params.get('subscription') === id)
        .flush({ entries: [], nextCursor: null });
      fixture.detectChanges();
      return fixture.nativeElement as HTMLElement;
    }

    // Drives the shell to the given selection kind via the URL's query params,
    // draining the entries request it triggers. Resets the shared `qp` first: left
    // at a previous iteration's params, the next boot() would read a stale selection.
    function mountWithSelectionKind(kind: string): HTMLElement {
      qp.next(convertToParamMap({}));
      const fixture = boot();
      if (kind === 'all') return fixture.nativeElement as HTMLElement;

      const params =
        kind === 'tag' ? { tag: '7' } : kind === 'search' ? { q: 'angular' } : { view: kind };
      qp.next(convertToParamMap(params));
      fixture.detectChanges();
      const url =
        kind === 'search' ? 'https://api.test/api/entries/search' : 'https://api.test/api/entries';
      ctrl.expectOne((request) => request.url === url).flush({ entries: [], nextCursor: null });
      fixture.detectChanges();
      return fixture.nativeElement as HTMLElement;
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
      const fixture = boot();
      qp.next(convertToParamMap({ q: 'climate ' }));
      fixture.detectChanges();
      ctrl
        .expectOne((request) => request.url === 'https://api.test/api/entries/search')
        .flush({ entries: [], nextCursor: null });
      fixture.detectChanges();
      return fixture;
    }

    it('calls the search mark-read endpoint with the term verbatim, then reloads entries, subscriptions and saved searches', () => {
      const fixture = bootWithSearchSelected();
      const ref = { closed: of(true) };
      jest.spyOn(TestBed.inject(Dialog), 'open').mockReturnValue(ref as never);

      fixture.componentInstance.markRead.confirmMarkAllRead();

      const testRequest = ctrl.expectOne('https://api.test/api/entries/search/mark-read');
      expect(testRequest.request.method).toBe('POST');
      // The trailing space is the whole-word-match signal the backend reads
      // via SearchTermsModel::fromInput; it must reach the request body unchanged.
      expect(testRequest.request.body).toEqual({ q: 'climate ', until: expect.any(String) });
      testRequest.flush(null);

      ctrl
        .expectOne((request) => request.url === 'https://api.test/api/entries/search')
        .flush({ entries: [], nextCursor: null });
      ctrl.expectOne('https://api.test/api/subscriptions').flush(subscriptionsBody);
      ctrl.expectOne('https://api.test/api/saved-searches').flush({ savedSearches: [] });
    });

    it('does nothing when the dialog is cancelled', () => {
      const fixture = bootWithSearchSelected();
      const ref = { closed: of(false) };
      jest.spyOn(TestBed.inject(Dialog), 'open').mockReturnValue(ref as never);

      fixture.componentInstance.markRead.confirmMarkAllRead();

      ctrl.expectNone('https://api.test/api/entries/search/mark-read');
    });
  });

  describe('mark all read for the ranked feed (#710)', () => {
    function bootWithForYouSelected() {
      const fixture = boot();
      qp.next(convertToParamMap({ view: 'for-you' }));
      fixture.detectChanges();
      ctrl
        .expectOne((request) => request.url === 'https://api.test/api/entries')
        .flush({ entries: [], nextCursor: null });
      fixture.detectChanges();
      return fixture;
    }

    it('offers the action on the ranked feed at all', () => {
      const fixture = bootWithForYouSelected();

      expect(fixture.componentInstance.markRead.canMarkAllRead()).toBe(true);
    });

    it('calls the for-you endpoint, then reloads the list and the counts beside it', () => {
      const fixture = bootWithForYouSelected();
      const ref = { closed: of(true) };
      jest.spyOn(TestBed.inject(Dialog), 'open').mockReturnValue(ref as never);

      fixture.componentInstance.markRead.confirmMarkAllRead();

      const testRequest = ctrl.expectOne('https://api.test/api/entries/for-you/mark-read');
      expect(testRequest.request.method).toBe('POST');
      // No scope and no id: the ranked feed identifies itself.
      expect(testRequest.request.body).toEqual({ until: expect.any(String) });
      testRequest.flush(null);

      ctrl
        .expectOne((request) => request.url === 'https://api.test/api/entries')
        .flush({ entries: [], nextCursor: null });
      ctrl.expectOne('https://api.test/api/subscriptions').flush(subscriptionsBody);
      ctrl.expectOne('https://api.test/api/saved-searches').flush({ savedSearches: [] });
      ctrl.expectOne('https://api.test/api/recommendations/runs/current').flush({
        status: 'none',
        batchesTotal: null,
        batchesDone: 0,
        error: null,
        background: false,
        streamedChars: 0,
        forYou: { itemCount: 0, generatedAt: null, newestRunId: null },
      });
    });

    // The badge counts unread picks now (#724), so marking them all read must
    // drop it to zero — the for-you summary is re-read after the mark-read,
    // since the marked picks do not move a watermark the list reload would see.
    it('refreshes the for-you count to zero after marking all read', () => {
      const fixture = bootWithForYouSelected();
      const recommendations = TestBed.inject(RecommendationsService);
      recommendations.report.set({
        status: 'completed',
        batchesTotal: 1,
        batchesDone: 1,
        error: null,
        background: false,
        streamedChars: 0,
        elapsedSeconds: null,
        forYou: { itemCount: 5, totalCount: 5, generatedAt: null, newestRunId: null },
      });
      expect(recommendations.forYouCount()).toBe(5);
      const ref = { closed: of(true) };
      jest.spyOn(TestBed.inject(Dialog), 'open').mockReturnValue(ref as never);

      fixture.componentInstance.markRead.confirmMarkAllRead();

      ctrl.expectOne('https://api.test/api/entries/for-you/mark-read').flush(null);
      ctrl
        .expectOne((request) => request.url === 'https://api.test/api/entries')
        .flush({ entries: [], nextCursor: null });
      ctrl.expectOne('https://api.test/api/subscriptions').flush(subscriptionsBody);
      ctrl.expectOne('https://api.test/api/saved-searches').flush({ savedSearches: [] });
      ctrl.expectOne('https://api.test/api/recommendations/runs/current').flush({
        status: 'completed',
        batchesTotal: 1,
        batchesDone: 1,
        error: null,
        background: false,
        streamedChars: 0,
        forYou: { itemCount: 0, generatedAt: null, newestRunId: null },
      });

      expect(recommendations.forYouCount()).toBe(0);
    });

    // A watermark is what the feed and tag scopes move; this list must never
    // reach that endpoint (#665, and the reason the backend split them).
    it('never falls back to the watermark endpoint', () => {
      const fixture = bootWithForYouSelected();
      const ref = { closed: of(true) };
      jest.spyOn(TestBed.inject(Dialog), 'open').mockReturnValue(ref as never);

      fixture.componentInstance.markRead.confirmMarkAllRead();

      ctrl.expectNone('https://api.test/api/entries/mark-read');
      ctrl.expectOne('https://api.test/api/entries/for-you/mark-read').flush(null);
      ctrl
        .expectOne((request) => request.url === 'https://api.test/api/entries')
        .flush({ entries: [], nextCursor: null });
      ctrl.expectOne('https://api.test/api/subscriptions').flush(subscriptionsBody);
      ctrl.expectOne('https://api.test/api/saved-searches').flush({ savedSearches: [] });
      ctrl.expectOne('https://api.test/api/recommendations/runs/current').flush({
        status: 'none',
        batchesTotal: null,
        batchesDone: 0,
        error: null,
        background: false,
        streamedChars: 0,
        forYou: { itemCount: 0, generatedAt: null, newestRunId: null },
      });
    });

    // The lag the user saw was the mark-read round trip: nothing showed the
    // wait until its response reloaded the list. The cue must rise on confirm.
    it('raises the list loading cue on confirm, before the mark-read resolves', () => {
      const fixture = bootWithForYouSelected();
      const ref = { closed: of(true) };
      jest.spyOn(TestBed.inject(Dialog), 'open').mockReturnValue(ref as never);
      expect(fixture.componentInstance.entries.loading()).toBe(false);

      fixture.componentInstance.markRead.confirmMarkAllRead();

      ctrl.expectOne('https://api.test/api/entries/for-you/mark-read');
      expect(fixture.componentInstance.entries.loading()).toBe(true);
    });
  });

  describe('titling the combined saved-search list (#769)', () => {
    function bootWithSavedSearches(saved: SavedSearchWire[]) {
      const fixture = boot();
      fixture.componentInstance.savedSearchesStore.load();
      ctrl.expectOne('https://api.test/api/saved-searches').flush({ savedSearches: saved });
      qp.next(convertToParamMap({ view: 'saved-searches' }));
      fixture.detectChanges();
      ctrl
        .expectOne((request) => request.url === 'https://api.test/api/entries/saved-searches')
        .flush({ entries: [], nextCursor: null });
      fixture.detectChanges();
      return fixture;
    }

    it('titles the combined saved-search list with the sidebar label', () => {
      const fixture = bootWithSavedSearches([]);

      expect(fixture.componentInstance.heading.title()).toBe('Saved searches');
    });

    it('counts the same unread total the sidebar row shows', () => {
      localStorage.setItem('sfr.user.1.unread-only', '1');
      const fixture = bootWithSavedSearches([
        {
          id: 1,
          slug: '1-a',
          term: 'a',
          wholeWord: false,
          phrase: false,
          position: 0,
          unreadEntryIds: [1, 2],
          memberCount: 2,
          includeInDigest: false,
        },
        {
          id: 2,
          slug: '2-b',
          term: 'b',
          wholeWord: false,
          phrase: false,
          position: 1,
          unreadEntryIds: [3, 4, 5],
          memberCount: 5,
          includeInDigest: false,
        },
      ]);

      expect(fixture.componentInstance.heading.titleCount()).toEqual({
        value: 5,
        counts: 'unread',
      });
    });

    it('counts the combined member total when All posts is on', () => {
      const fixture = bootWithSavedSearches([
        {
          id: 1,
          slug: '1-a',
          term: 'a',
          wholeWord: false,
          phrase: false,
          position: 0,
          unreadEntryIds: [1, 2],
          memberCount: 4,
          includeInDigest: false,
        },
        {
          id: 2,
          slug: '2-b',
          term: 'b',
          wholeWord: false,
          phrase: false,
          position: 1,
          unreadEntryIds: [3, 4, 5],
          memberCount: 5,
          includeInDigest: false,
        },
      ]);

      expect(fixture.componentInstance.heading.titleCount()).toEqual({ value: 9, counts: 'items' });
    });
  });

  describe('a single saved search addressed by its path slug (#1118)', () => {
    const savedClimate: SavedSearchWire = {
      id: 4,
      slug: '4-climate',
      term: 'climate',
      wholeWord: true,
      phrase: false,
      position: 0,
      unreadEntryIds: [100, 101, 102],
      memberCount: 3,
      includeInDigest: false,
    };

    function bootSingleSavedSearch() {
      const fixture = boot();
      fixture.componentInstance.savedSearchesStore.load();
      ctrl
        .expectOne('https://api.test/api/saved-searches')
        .flush({ savedSearches: [savedClimate] });
      pp.next(convertToParamMap({ savedSearch: '4-climate' }));
      fixture.detectChanges();
      ctrl
        .expectOne((request) => request.url === 'https://api.test/api/entries/saved-searches/4')
        .flush({ entries: [], nextCursor: null });
      fixture.detectChanges();
      return fixture;
    }

    it('keeps the unread filter on when a tag list moves to a saved search (#1126)', () => {
      localStorage.setItem('sfr.user.1.unread-only', '1');
      const fixture = boot();
      fixture.componentInstance.savedSearchesStore.load();
      ctrl
        .expectOne('https://api.test/api/saved-searches')
        .flush({ savedSearches: [savedClimate] });
      qp.next(convertToParamMap({ tag: '3' }));
      fixture.detectChanges();
      const tagList = ctrl.expectOne((request) => request.url === 'https://api.test/api/entries');
      expect(tagList.request.params.get('view')).toBe('unread');
      tagList.flush({ entries: [], nextCursor: null });

      qp.next(convertToParamMap({}));
      pp.next(convertToParamMap({ savedSearch: '4-climate' }));
      fixture.detectChanges();

      const saved = ctrl.expectOne(
        (request) => request.url === 'https://api.test/api/entries/saved-searches/4',
      );
      expect(saved.request.params.get('unread')).toBe('1');
      expect(fixture.componentInstance.selection().unread).toBe(true);
    });

    it('selects the single saved search by the id in its slug', () => {
      const fixture = bootSingleSavedSearch();

      expect(fixture.componentInstance.selection()).toEqual(
        expect.objectContaining({ kind: 'saved-search', id: 4, unread: false }),
      );
      expect(fixture.componentInstance.heading.activeSavedSearchId()).toBe(4);
    });

    it('titles the list with the saved search term and counts its unread total', () => {
      localStorage.setItem('sfr.user.1.unread-only', '1');
      const fixture = bootSingleSavedSearch();

      expect(fixture.componentInstance.heading.title()).toBe('climate');
      expect(fixture.componentInstance.heading.titleCount()).toEqual({
        value: 3,
        counts: 'unread',
      });
    });

    it('counts the saved search member total when All posts is on', () => {
      const fixture = boot();
      fixture.componentInstance.savedSearchesStore.load();
      ctrl
        .expectOne('https://api.test/api/saved-searches')
        .flush({ savedSearches: [{ ...savedClimate, memberCount: 6 }] });
      pp.next(convertToParamMap({ savedSearch: '4-climate' }));
      fixture.detectChanges();
      ctrl
        .expectOne((request) => request.url === 'https://api.test/api/entries/saved-searches/4')
        .flush({ entries: [], nextCursor: null });
      fixture.detectChanges();

      expect(fixture.componentInstance.heading.titleCount()).toEqual({ value: 6, counts: 'items' });
    });

    it('turns on Mark all read and the unread filter', () => {
      const fixture = bootSingleSavedSearch();

      expect(fixture.componentInstance.markRead.canMarkAllRead()).toBe(true);
      const header = fixture.debugElement.query(By.directive(ListHeaderComponent))
        .componentInstance as ListHeaderComponent;
      expect(header.hasUnreadFilter()).toBe(true);
    });

    it('marks it read via its by-id endpoint, then reloads entries, subscriptions and saved searches', () => {
      const fixture = bootSingleSavedSearch();
      jest.spyOn(TestBed.inject(Dialog), 'open').mockReturnValue({ closed: of(true) } as never);

      fixture.componentInstance.markRead.confirmMarkAllRead();

      const testRequest = ctrl.expectOne('https://api.test/api/entries/saved-searches/4/mark-read');
      expect(testRequest.request.method).toBe('POST');
      expect(testRequest.request.body).toEqual({ until: expect.any(String) });
      testRequest.flush(null);

      ctrl.expectOne((request) => request.url === 'https://api.test/api/entries/saved-searches/4');
      ctrl.expectOne('https://api.test/api/subscriptions').flush(subscriptionsBody);
      ctrl.expectOne('https://api.test/api/saved-searches').flush({ savedSearches: [] });
    });

    it('offers a Remove that deletes the search and returns to the combined list', () => {
      const fixture = bootSingleSavedSearch();
      jest.spyOn(TestBed.inject(Dialog), 'open').mockReturnValue({ closed: of(true) } as never);
      const nav = jest.spyOn(TestBed.inject(Router), 'navigate').mockResolvedValue(true);

      expect(fixture.componentInstance.savedSearch.current()?.id).toBe(4);
      fixture.componentInstance.savedSearch.toggle();

      ctrl.expectOne('https://api.test/api/saved-searches/4').flush(null);
      expect(nav).toHaveBeenCalledWith(['/searches/saved/all']);
    });
  });

  describe('the unread filter lives in localStorage (#1126)', () => {
    it('reloads the list when the switch flips, without navigating', () => {
      const fixture = boot();
      const nav = jest.spyOn(TestBed.inject(Router), 'navigate');

      (fixture.nativeElement.querySelector('.unread-switch') as HTMLButtonElement).click();
      fixture.detectChanges();

      const testRequest = ctrl.expectOne(
        (request) => request.url === 'https://api.test/api/entries',
      );
      expect(testRequest.request.params.get('view')).toBe('unread');
      expect(localStorage.getItem('sfr.user.1.unread-only')).toBe('1');
      testRequest.flush({ entries: [], nextCursor: null });
      expect(nav).not.toHaveBeenCalled();
    });

    it('filters a direct search to unread', () => {
      localStorage.setItem('sfr.user.1.unread-only', '1');
      const fixture = boot();
      qp.next(convertToParamMap({ q: 'angular' }));
      fixture.detectChanges();

      const testRequest = ctrl.expectOne(
        (request) => request.url === 'https://api.test/api/entries/search',
      );
      expect(testRequest.request.params.get('unread')).toBe('1');
      testRequest.flush({ entries: [], nextCursor: null, matchedWords: [] });
    });

    it('ignores an unread parameter in the URL', () => {
      const fixture = boot();
      qp.next(convertToParamMap({ unread: '1' }));
      fixture.detectChanges();

      expect(fixture.componentInstance.selection().unread).toBe(false);
      ctrl.expectNone((request) => request.url === 'https://api.test/api/entries');
    });
  });

  describe('list order (#1143)', () => {
    it('reloads a flipped list oldest first and remembers it for that list only', () => {
      const fixture = boot();

      (fixture.nativeElement.querySelector('.list-order') as HTMLButtonElement).click();
      fixture.detectChanges();
      const flipped = ctrl.expectOne((request) => request.url === 'https://api.test/api/entries');
      expect(flipped.request.params.get('order')).toBe('asc');
      expect(flipped.request.params.get('cursor')).toBeNull();
      flipped.flush({ entries: [], nextCursor: null });
      expect(JSON.parse(localStorage.getItem('sfr.user.1.oldest-first-views')!)).toEqual(['all']);

      qp.next(convertToParamMap({ tag: '9' }));
      fixture.detectChanges();
      const other = ctrl.expectOne((request) => request.url === 'https://api.test/api/entries');
      expect(other.request.params.get('order')).toBeNull();
      other.flush({ entries: [], nextCursor: null });
    });

    it('holds the first list load until the account is known', () => {
      auth.user.set({ email: 'a@b.c', preferences: { passkeyOfferAnswered: true } } as never);
      const fixture = TestBed.createComponent(ReaderShellComponent);
      fixture.detectChanges();
      expect(ctrl.match((request) => request.url === 'https://api.test/api/entries')).toHaveLength(
        0,
      );

      auth.user.set({ id: 1, email: 'a@b.c', preferences: { passkeyOfferAnswered: true } });
      fixture.detectChanges();
      ctrl
        .expectOne((request) => request.url === 'https://api.test/api/entries')
        .flush({ entries: [], nextCursor: null });
    });

    it('loads the list with the defaults when the account never loads', () => {
      reconfigureShell([]);
      const fixture = TestBed.createComponent(ReaderShellComponent);
      fixture.detectChanges();
      expect(ctrl.match((request) => request.url === 'https://api.test/api/entries')).toHaveLength(
        0,
      );

      ctrl
        .expectOne('https://api.test/api/me')
        .flush('boom', { status: 500, statusText: 'Server Error' });
      fixture.detectChanges();

      const list = ctrl.expectOne((request) => request.url === 'https://api.test/api/entries');
      expect(list.request.params.get('order')).toBeNull();
      expect(list.request.params.get('view')).toBe('all');
      list.flush({ entries: [], nextCursor: null });
    });
  });

  describe('mark all read for the combined saved-searches view (#769)', () => {
    function bootWithSavedSearchesSelected() {
      const fixture = boot();
      qp.next(convertToParamMap({ view: 'saved-searches' }));
      fixture.detectChanges();
      ctrl
        .expectOne((request) => request.url === 'https://api.test/api/entries/saved-searches')
        .flush({ entries: [], nextCursor: null });
      fixture.detectChanges();
      return fixture;
    }

    it('calls the saved-searches endpoint with only a watermark, then reloads entries, subscriptions and saved searches', () => {
      const fixture = bootWithSavedSearchesSelected();
      const ref = { closed: of(true) };
      jest.spyOn(TestBed.inject(Dialog), 'open').mockReturnValue(ref as never);

      fixture.componentInstance.markRead.confirmMarkAllRead();

      const testRequest = ctrl.expectOne('https://api.test/api/entries/saved-searches/mark-read');
      expect(testRequest.request.method).toBe('POST');
      expect(testRequest.request.body).toEqual({ until: expect.any(String) });
      testRequest.flush(null);

      ctrl
        .expectOne((request) => request.url === 'https://api.test/api/entries/saved-searches')
        .flush({ entries: [], nextCursor: null });
      ctrl.expectOne('https://api.test/api/subscriptions').flush(subscriptionsBody);
      ctrl.expectOne('https://api.test/api/saved-searches').flush({ savedSearches: [] });
    });

    it('does nothing when the dialog is cancelled', () => {
      const fixture = bootWithSavedSearchesSelected();
      const ref = { closed: of(false) };
      jest.spyOn(TestBed.inject(Dialog), 'open').mockReturnValue(ref as never);

      fixture.componentInstance.markRead.confirmMarkAllRead();

      ctrl.expectNone('https://api.test/api/entries/saved-searches/mark-read');
    });
  });

  describe('marking everything above the fold as read (#1080)', () => {
    function bootUnreadView() {
      const fixture = boot();
      TestBed.inject(UnreadFilterService).set(true);
      fixture.detectChanges();
      ctrl
        .expectOne((request) => request.url === 'https://api.test/api/entries')
        .flush({ entries: [], nextCursor: null });
      fixture.detectChanges();
      return fixture;
    }

    it('hides the marked posts in place and lands the boundary at the top, on an unread view', () => {
      const fixture = bootUnreadView();
      const api = TestBed.inject(ReaderApi);
      jest.spyOn(api, 'markEntriesRead').mockReturnValue(of(undefined));
      jest.spyOn(TestBed.inject(Dialog), 'open').mockReturnValue({ closed: of(true) } as never);
      const load = jest.spyOn(fixture.componentInstance.entries, 'load');
      const list = fixture.debugElement.query(By.directive(EntryListComponent))
        .componentInstance as EntryListComponent;
      const hideAboveMarked = jest
        .spyOn(list, 'hideAboveMarked')
        .mockImplementation(() => undefined);

      fixture.componentInstance.onMarkAboveRead([1, 2]);

      expect(api.markEntriesRead).toHaveBeenCalledWith([1, 2]);
      expect(hideAboveMarked).toHaveBeenCalledWith([1, 2]);
      expect(load).not.toHaveBeenCalled();
    });

    it('surfaces a failed request on the entries error banner, on an unread view', () => {
      const fixture = bootUnreadView();
      const api = TestBed.inject(ReaderApi);
      const problem = { type: 'x', title: 'Failed', status: 500 };
      jest
        .spyOn(api, 'markEntriesRead')
        .mockReturnValue(throwError(() => new HttpErrorResponse({ error: problem, status: 500 })));
      jest.spyOn(TestBed.inject(Dialog), 'open').mockReturnValue({ closed: of(true) } as never);
      const list = fixture.debugElement.query(By.directive(EntryListComponent))
        .componentInstance as EntryListComponent;
      const hideAboveMarked = jest.spyOn(list, 'hideAboveMarked');

      fixture.componentInstance.onMarkAboveRead([1, 2]);

      expect(fixture.componentInstance.entries.error()).toEqual(expect.objectContaining(problem));
      expect(hideAboveMarked).not.toHaveBeenCalled();
    });

    it('restyles in place without a re-fetch, on an all-items view', () => {
      const fixture = boot();
      const api = TestBed.inject(ReaderApi);
      jest.spyOn(api, 'markEntriesRead').mockReturnValue(of(undefined));
      jest.spyOn(TestBed.inject(Dialog), 'open').mockReturnValue({ closed: of(true) } as never);
      const markHiddenLocally = jest.spyOn(fixture.componentInstance.entries, 'markHiddenLocally');
      const load = jest.spyOn(fixture.componentInstance.entries, 'load');

      fixture.componentInstance.onMarkAboveRead([5, 6]);

      expect(api.markEntriesRead).toHaveBeenCalledWith([5, 6]);
      expect(markHiddenLocally).toHaveBeenCalledWith([5, 6]);
      expect(load).not.toHaveBeenCalled();
    });

    it('surfaces a failed request on the entries error banner, on an all-items view', () => {
      const fixture = boot();
      const api = TestBed.inject(ReaderApi);
      const problem = { type: 'x', title: 'Failed', status: 500 };
      jest
        .spyOn(api, 'markEntriesRead')
        .mockReturnValue(throwError(() => new HttpErrorResponse({ error: problem, status: 500 })));
      jest.spyOn(TestBed.inject(Dialog), 'open').mockReturnValue({ closed: of(true) } as never);
      const markHiddenLocally = jest.spyOn(fixture.componentInstance.entries, 'markHiddenLocally');
      const load = jest.spyOn(fixture.componentInstance.entries, 'load');

      fixture.componentInstance.onMarkAboveRead([5, 6]);

      expect(fixture.componentInstance.entries.error()).toEqual(expect.objectContaining(problem));
      expect(markHiddenLocally).not.toHaveBeenCalled();
      expect(load).not.toHaveBeenCalled();
    });

    it('does nothing when the dialog is cancelled', () => {
      const fixture = boot();
      const api = TestBed.inject(ReaderApi);
      jest.spyOn(api, 'markEntriesRead').mockReturnValue(of(undefined));
      jest.spyOn(TestBed.inject(Dialog), 'open').mockReturnValue({ closed: of(false) } as never);

      fixture.componentInstance.onMarkAboveRead([1, 2]);

      expect(api.markEntriesRead).not.toHaveBeenCalled();
    });

    it('does nothing for an empty selection, without opening the dialog', () => {
      const fixture = boot();
      const dialogOpen = jest.spyOn(TestBed.inject(Dialog), 'open');

      fixture.componentInstance.onMarkAboveRead([]);

      expect(dialogOpen).not.toHaveBeenCalled();
    });
  });

  // The Save/Remove control is a shell command rendered through the list's
  // `headerActions` outlet, so the list emits nothing and the shell owns both
  // the decision and the button. One toggle, not two one-way actions.
  describe('saving the current search (#581)', () => {
    // boot() drained the shell's own initial saved-searches load with an empty
    // set, so seed through a second real load() — the store maps the wire (ids)
    // to the view the button reads, exactly as production does.
    function bootWithSearchSelected(saved: SavedSearchWire[], searchTerm = 'climate ') {
      const fixture = boot();
      fixture.componentInstance.savedSearchesStore.load();
      ctrl.expectOne('https://api.test/api/saved-searches').flush({ savedSearches: saved });
      qp.next(convertToParamMap({ q: searchTerm }));
      fixture.detectChanges();
      ctrl
        .expectOne((request) => request.url === 'https://api.test/api/entries/search')
        .flush({ entries: [], nextCursor: null });
      fixture.detectChanges();
      return fixture;
    }

    const savedClimate: SavedSearchWire = {
      id: 4,
      slug: '4-climate',
      term: 'climate',
      wholeWord: true,
      phrase: false,
      position: 0,
      unreadEntryIds: [100, 101],
      memberCount: 2,
      includeInDigest: false,
    };
    // The sidebar view the store derives from that wire row.
    const savedClimateView: SavedSearchDto = {
      id: 4,
      slug: '4-climate',
      term: 'climate',
      wholeWord: true,
      phrase: false,
      position: 0,
      unreadCount: 2,
      memberCount: 2,
      includeInDigest: false,
    };

    it('saves the decoded term and whole-word flag, adopts the response without reloading the list, and toasts a confirmation', () => {
      const fixture = bootWithSearchSelected([]);
      const show = jest.spyOn(TestBed.inject(ToastService), 'show');

      fixture.componentInstance.savedSearch.toggle();

      const testRequest = ctrl.expectOne('https://api.test/api/saved-searches');
      expect(testRequest.request.method).toBe('POST');
      // The trailing space is the whole-word signal; it is decoded to the mode
      // the backend stores, never sent verbatim as the term.
      expect(testRequest.request.body).toEqual({ term: 'climate', wholeWord: true, phrase: false });
      testRequest.flush({ savedSearch: savedClimate });

      // The POST already answered with the row and its matches — no re-fetch.
      ctrl.expectNone('https://api.test/api/saved-searches');
      expect(fixture.componentInstance.savedSearchesStore.savedSearches()).toEqual([
        savedClimateView,
      ]);
      expect(show).toHaveBeenCalledWith(
        expect.objectContaining({ message: 'Search saved', durationMs: CONFIRMATION_DURATION_MS }),
      );
    });

    it('saves a quoted query as a phrase, with the bare term and the phrase flag', () => {
      const fixture = bootWithSearchSelected([], '"climate change"');

      fixture.componentInstance.savedSearch.toggle();

      const testRequest = ctrl.expectOne('https://api.test/api/saved-searches');
      expect(testRequest.request.method).toBe('POST');
      // The wrapping quotes are the phrase signal; decoded to the mode the
      // backend stores, the term is saved bare and phrase is true.
      expect(testRequest.request.body).toEqual({
        term: 'climate change',
        wholeWord: false,
        phrase: true,
      });
    });

    it('removes the saved search when the current one is already saved and the removal is confirmed', () => {
      const fixture = bootWithSearchSelected([savedClimate]);
      expect(fixture.componentInstance.savedSearch.current()).toEqual(savedClimateView);
      const ref = { closed: of(true) };
      jest.spyOn(TestBed.inject(Dialog), 'open').mockReturnValue(ref as never);

      fixture.componentInstance.savedSearch.toggle();

      const testRequest = ctrl.expectOne('https://api.test/api/saved-searches/4');
      expect(testRequest.request.method).toBe('DELETE');
      testRequest.flush(null);

      ctrl.expectNone('https://api.test/api/saved-searches');
      expect(fixture.componentInstance.savedSearchesStore.savedSearches()).toEqual([]);
    });

    it('does not remove the saved search when the removal is cancelled', () => {
      const fixture = bootWithSearchSelected([savedClimate]);
      const ref = { closed: of(false) };
      jest.spyOn(TestBed.inject(Dialog), 'open').mockReturnValue(ref as never);

      fixture.componentInstance.savedSearch.toggle();

      ctrl.expectNone('https://api.test/api/saved-searches/4');
      expect(fixture.componentInstance.savedSearchesStore.savedSearches()).toEqual([
        savedClimateView,
      ]);
    });

    it('enables the digest for a row when confirmed, keyed by the row id and its flipped flag', () => {
      const fixture = bootWithSearchSelected([savedClimate]);
      const ref = { closed: of(true) };
      jest.spyOn(TestBed.inject(Dialog), 'open').mockReturnValue(ref as never);
      const setIncludeInDigest = jest
        .spyOn(fixture.componentInstance.savedSearchesStore, 'setIncludeInDigest')
        .mockImplementation(() => undefined);

      fixture.componentInstance.savedSearch.confirmToggleDigest(savedClimateView);

      expect(setIncludeInDigest).toHaveBeenCalledWith(4, true);
    });

    it('disables the digest for a row already included, when confirmed', () => {
      const fixture = bootWithSearchSelected([savedClimate]);
      const ref = { closed: of(true) };
      jest.spyOn(TestBed.inject(Dialog), 'open').mockReturnValue(ref as never);
      const setIncludeInDigest = jest
        .spyOn(fixture.componentInstance.savedSearchesStore, 'setIncludeInDigest')
        .mockImplementation(() => undefined);

      fixture.componentInstance.savedSearch.confirmToggleDigest({
        ...savedClimateView,
        includeInDigest: true,
      });

      expect(setIncludeInDigest).toHaveBeenCalledWith(4, false);
    });

    it('does nothing when the digest toggle confirmation is cancelled', () => {
      const fixture = bootWithSearchSelected([savedClimate]);
      const ref = { closed: of(false) };
      jest.spyOn(TestBed.inject(Dialog), 'open').mockReturnValue(ref as never);
      const setIncludeInDigest = jest
        .spyOn(fixture.componentInstance.savedSearchesStore, 'setIncludeInDigest')
        .mockImplementation(() => undefined);

      fixture.componentInstance.savedSearch.confirmToggleDigest(savedClimateView);

      expect(setIncludeInDigest).not.toHaveBeenCalled();
    });

    it('matches a saved search by its decoded pair, not by the raw term string', () => {
      // A no-break space is a whole-word signal to the decoder but never equals
      // a plain trailing space, which is what a string comparison would need.
      const fixture = bootWithSearchSelected([savedClimate], 'climate\u00a0');

      expect(fixture.componentInstance.savedSearch.current()).toEqual(savedClimateView);
    });
  });

  describe('the counts poll (#708)', () => {
    const realFetch = globalThis.fetch;

    // The steady tick reads the static change marker (#720) before it fetches.
    // A missing marker falls back to fetching, which is the behaviour these
    // tests assert; a stubbed non-ok response reaches that path deterministically.
    const countsBody = {
      subscriptions: [{ id: 5, unreadCount: 2 }],
      favoritesCount: 0,
      keptCount: 0,
      viewedCount: 0,
    };

    afterEach(() => {
      jest.useRealTimers();
      globalThis.fetch = realFetch;
    });

    // The poll's interval is created while the reader is built, so the fake
    // clock has to be in place before that — installed afterwards, it would
    // never own the timer it is supposed to drive.
    beforeEach(() => {
      jest.useFakeTimers();
      globalThis.fetch = jest.fn(async () => ({ ok: false, text: async () => '' }) as Response);
    });

    it('refreshes the sidebar counts on its own while the reader is open', async () => {
      const fixture = boot();

      await jest.advanceTimersByTimeAsync(SIDEBAR_RELOAD_INTERVAL_MS);

      // The cheap counts endpoint and saved searches, with no user action between.
      ctrl.expectOne('https://api.test/api/subscriptions/counts').flush(countsBody);
      ctrl.expectOne('https://api.test/api/saved-searches').flush({ savedSearches: [] });
      fixture.detectChanges();
    });

    // The property that matters across #708 and #709: there is ONE number per
    // surface, held in the stores, and the sidebar badge, the list heading and
    // the tab title all read it. A tick moves the store, so it moves all three
    // at once — none of them can be refreshed and leave another behind.
    it('moves the sidebar badge, the list heading and the tab title on one tick', async () => {
      localStorage.setItem('sfr.user.1.unread-only', '1');
      const fixture = boot();
      expect(TestBed.inject(Title).getTitle()).toBe('All items (2) | simple feed reader');

      await jest.advanceTimersByTimeAsync(SIDEBAR_RELOAD_INTERVAL_MS);
      ctrl.expectOne('https://api.test/api/subscriptions/counts').flush({
        ...countsBody,
        subscriptions: [{ id: 5, unreadCount: 9 }],
      });
      ctrl.expectOne('https://api.test/api/saved-searches').flush({ savedSearches: [] });
      fixture.detectChanges();

      expect(TestBed.inject(SubscriptionsStore).totalUnread()).toBe(9);
      const list = fixture.debugElement.query(By.directive(EntryListComponent));
      expect(list.componentInstance.titleCount()).toEqual({ value: 9, counts: 'unread' });
      expect(TestBed.inject(Title).getTitle()).toBe('All items (9) | simple feed reader');
    });

    it('ends with the reader, so a closed reader polls nothing', async () => {
      const fixture = boot();

      fixture.destroy();
      await jest.advanceTimersByTimeAsync(SIDEBAR_RELOAD_INTERVAL_MS * 5);

      ctrl.expectNone('https://api.test/api/subscriptions/counts');
      ctrl.expectNone('https://api.test/api/saved-searches');
    });
  });

  // Its own describe: this one needs the real clock, because a fake one hides
  // exactly the defect it is here to catch.
  describe('the counts poll and Angular zone stability (#708)', () => {
    it('leaves the zone stable, so anything awaiting the app still resolves', async () => {
      const fixture = boot();

      // A repeating timer scheduled INSIDE the Angular zone is a macrotask that
      // never finishes, so the zone never settles and every `whenStable()` in
      // the app hangs until it times out. The poll schedules outside the zone.
      await expect(fixture.whenStable()).resolves.toBeDefined();
    });
  });

  describe('the first-login passkey offer (#624)', () => {
    // jsdom has neither `PublicKeyCredential` nor `navigator.credentials` --
    // "unsupported" is what every other test in this file gets by default, so
    // only this block stubs it in, and only for the span of a test.
    function supportPasskeys(): void {
      (window as unknown as { PublicKeyCredential: unknown }).PublicKeyCredential = {};
    }

    beforeEach(() => {
      auth.user.set({ id: 1, email: 'a@b.c', preferences: { passkeyOfferAnswered: false } });
    });

    afterEach(() => {
      delete (window as unknown as { PublicKeyCredential?: unknown }).PublicKeyCredential;
    });

    it('shows the offer once the shell has settled, WebAuthn is available, and onboarding is not running', () => {
      supportPasskeys();
      const open = jest
        .spyOn(TestBed.inject(Dialog), 'open')
        .mockReturnValue({ closed: new Subject() } as never);

      boot();

      expect(open).toHaveBeenCalledWith(
        PasskeyOfferDialogComponent,
        expect.objectContaining({ panelClass: 'app-dialog' }),
      );
    });

    it('does not show the offer once the account has already answered it', () => {
      supportPasskeys();
      auth.user.set({ id: 1, email: 'a@b.c', preferences: { passkeyOfferAnswered: true } });
      const open = jest
        .spyOn(TestBed.inject(Dialog), 'open')
        .mockReturnValue({ closed: new Subject() } as never);

      boot();

      expect(open).not.toHaveBeenCalledWith(PasskeyOfferDialogComponent, expect.anything());
    });

    it('does not show the offer when the browser has no WebAuthn support', () => {
      // No supportPasskeys() call -- jsdom's own default.
      const open = jest
        .spyOn(TestBed.inject(Dialog), 'open')
        .mockReturnValue({ closed: new Subject() } as never);

      boot();

      expect(open).not.toHaveBeenCalledWith(PasskeyOfferDialogComponent, expect.anything());
    });

    /**
     * A fresh testing module, not `overrideProvider` — the outer `beforeEach`
     * already injected `HttpTestingController`, which Angular refuses to override
     * past. Mirrors "drawer breakpoint driven by class" further down.
     */
    function configureAvailability(passkeySignInAvailable: boolean | null): void {
      TestBed.resetTestingModule();
      TestBed.configureTestingModule({
        imports: [ReaderShellComponent, provideTranslocoTesting()],
        providers: [
          provideHttpClient(),
          provideHttpClientTesting(),
          provideRouter([]),
          { provide: API_BASE_URL, useValue: 'https://api.test' },
          {
            provide: ActivatedRoute,
            useValue: { queryParamMap: qp.asObservable(), paramMap: pp.asObservable() },
          },
          { provide: AuthService, useValue: auth },
          { provide: LayoutService, useValue: screen },
          {
            provide: SetupService,
            useValue: {
              ensureLoaded: () => of(true),
              passkeySignInAvailable: signal(passkeySignInAvailable),
            },
          },
        ],
      });
      ctrl = TestBed.inject(HttpTestingController);
    }

    /**
     * #624 follow-up: the instance-wide toggle gates the offer the same way
     * it gates enrolment everywhere else -- offering it while sign-in cannot
     * complete would hand the account a credential it can never use.
     */
    it('does not show the offer once the instance reports passkey sign-in unavailable', () => {
      supportPasskeys();
      configureAvailability(false);
      const open = jest
        .spyOn(TestBed.inject(Dialog), 'open')
        .mockReturnValue({ closed: new Subject() } as never);

      boot();

      expect(open).not.toHaveBeenCalledWith(PasskeyOfferDialogComponent, expect.anything());
    });

    it('does not show the offer while availability is still unknown', () => {
      supportPasskeys();
      configureAvailability(null);
      const open = jest
        .spyOn(TestBed.inject(Dialog), 'open')
        .mockReturnValue({ closed: new Subject() } as never);

      boot();

      expect(open).not.toHaveBeenCalledWith(PasskeyOfferDialogComponent, expect.anything());
    });

    it('does not show the offer while the post-onboarding first-fetch sweep is running', () => {
      supportPasskeys();
      const open = jest
        .spyOn(TestBed.inject(Dialog), 'open')
        .mockReturnValue({ closed: new Subject() } as never);

      // Every subscription unfetched -> awaitingFirstFetch()/sweeping() are true ->
      // the shell fires the post-onboarding sweep itself. A modal on top of it is
      // exactly what design spec §5.3 rules out.
      bootWith([{ ...SUBSCRIPTION_FIXTURE, id: 1, lastFetchedAt: null }]);

      expect(open).not.toHaveBeenCalledWith(PasskeyOfferDialogComponent, expect.anything());
    });

    it('does not show the offer while a zero-subscription account is waiting on the catalog to decide the /discover redirect', () => {
      // Regression: the catalog request only STARTS once subscriptions resolve
      // empty, so there is a real window on every new account where the list is
      // empty but the catalog hasn't answered — deliberately left unflushed here.
      supportPasskeys();
      const open = jest
        .spyOn(TestBed.inject(Dialog), 'open')
        .mockReturnValue({ closed: new Subject() } as never);

      bootWith([]);

      expect(open).not.toHaveBeenCalledWith(PasskeyOfferDialogComponent, expect.anything());
    });

    it('shows the offer at most once per boot, even if the shell re-renders', () => {
      supportPasskeys();
      const open = jest
        .spyOn(TestBed.inject(Dialog), 'open')
        .mockReturnValue({ closed: new Subject() } as never);

      const fixture = boot();
      // Re-render without anything about eligibility changing.
      fixture.detectChanges();
      fixture.detectChanges();

      expect(open).toHaveBeenCalledTimes(1);
    });

    describe('any close marks the offer answered', () => {
      // The real Dialog (not spied) so the real PasskeyOfferDialogComponent renders
      // into the real overlay — these three tests prove the actual button/Escape/
      // backdrop paths reach AuthService, not just isolated component behavior.
      function container(): HTMLElement {
        return TestBed.inject(OverlayContainer).getContainerElement();
      }

      it('via the button path -- Not now, then OK', () => {
        supportPasskeys();
        boot();

        const notNow = Array.from(container().querySelectorAll('button')).find((button) =>
          button.textContent?.includes('Not now'),
        ) as HTMLButtonElement;
        notNow.click();

        // Declining marks the offer the moment state two opens.
        expect(auth.answerPasskeyOffer).toHaveBeenCalledTimes(1);

        const ok = container().querySelector<HTMLButtonElement>('[data-test="passkey-offer-ok"]')!;
        ok.click();

        expect(container().querySelector('.cdk-overlay-pane')).toBeNull();
      });

      it('via Escape, before choosing anything', () => {
        supportPasskeys();
        boot();
        expect(container().querySelector('.cdk-overlay-pane')).not.toBeNull();

        // CDK's overlay keyboard dispatcher listens on `body`, not `document`
        // (`OverlayKeyboardDispatcher`, `@angular/cdk/overlay`), and reads the
        // legacy `keyCode` field to recognise Escape (`ESCAPE = 27`,
        // `@angular/cdk/keycodes`), not `key`.
        document.body.dispatchEvent(
          new KeyboardEvent('keydown', { key: 'Escape', keyCode: 27, bubbles: true }),
        );

        expect(auth.answerPasskeyOffer).toHaveBeenCalledTimes(1);
        expect(container().querySelector('.cdk-overlay-pane')).toBeNull();
      });

      it('via the backdrop, before choosing anything', () => {
        supportPasskeys();
        boot();
        const backdrop = container().querySelector<HTMLElement>('.cdk-overlay-backdrop')!;

        backdrop.click();

        expect(auth.answerPasskeyOffer).toHaveBeenCalledTimes(1);
        expect(container().querySelector('.cdk-overlay-pane')).toBeNull();
      });
    });
  });

  // #996: the list's error banner offers retry and dismiss; the shell must route
  // them to the store, or a wired-looking banner does nothing on click. Proven
  // through the real template binding rather than a direct store call.
  describe('error banner wiring (#996)', () => {
    it('routes the list retry and dismiss outputs to the store', () => {
      const fixture = boot();
      const store = TestBed.inject(EntriesStore);
      const retry = jest.spyOn(store, 'retry');
      const dismiss = jest.spyOn(store, 'dismissError');

      const list = fixture.debugElement.query(By.directive(EntryListComponent));
      list.triggerEventHandler('retry');
      list.triggerEventHandler('dismiss');

      expect(retry).toHaveBeenCalledTimes(1);
      expect(dismiss).toHaveBeenCalledTimes(1);
    });
  });
});
