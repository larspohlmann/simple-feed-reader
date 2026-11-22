import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { WritableSignal, signal } from '@angular/core';
import { ComponentFixture, TestBed } from '@angular/core/testing';
import { By } from '@angular/platform-browser';
import { provideRouter } from '@angular/router';
import { provideTranslocoTesting } from '../../../../testing/transloco-testing';
import {
  MockIntersectionObserver,
  installMockIntersectionObserver,
  restoreIntersectionObserver,
  stubIntersectionObserver,
} from '../../../../testing/intersection-observer-testing';
import { API_BASE_URL } from '../../../core/api';
import { restorePageScroll, trackPageScrollTop } from '../../../../testing/scroll-testing';
import { SocialNetworkName } from '../../../core/social-network';
import { ToastService } from '../../../shared/toast/toast.service';
import { SocialSection } from '../../state/social-sections';
import { SubscriptionsStore } from '../../state/subscriptions.store';
import { NotificationRowComponent } from '../notification-row/notification-row.component';
import { NotificationGroupDto, NotificationsPageDto } from '../notification.models';
import { NotificationListComponent } from './notification-list.component';

describe('NotificationListComponent (#2240)', () => {
  const listUrl = 'https://api.test/api/me/notifications';
  const seenUrl = 'https://api.test/api/me/notifications/seen';

  const group = (id: number, over: Partial<NotificationGroupDto> = {}): NotificationGroupDto => ({
    id,
    kind: 'like',
    account: '@a',
    actors: [],
    actorCount: 0,
    excerpt: null,
    answeredExcerpt: null,
    threadTitle: null,
    threadPlace: null,
    threadBy: null,
    onComment: false,
    linkUrl: 'https://example.test/post',
    entryId: null,
    latestAt: '2026-11-16T10:00:00+00:00',
    seen: true,
    ...over,
  });
  const page = (
    groups: NotificationGroupDto[],
    over: Partial<NotificationsPageDto> = {},
  ): NotificationsPageDto => ({
    groups,
    nextCursor: null,
    scopeMissing: [],
    needsReconnect: [],
    ...over,
  });

  let fixture: ComponentFixture<NotificationListComponent>;
  let http: HttpTestingController;
  let socialSections: WritableSignal<SocialSection[]>;
  const reloadCountsQuietly = jest.fn();

  beforeEach(() => {
    reloadCountsQuietly.mockClear();
    socialSections = signal([]);
    TestBed.configureTestingModule({
      imports: [NotificationListComponent, provideTranslocoTesting()],
      providers: [
        provideHttpClient(),
        provideHttpClientTesting(),
        provideRouter([]),
        { provide: API_BASE_URL, useValue: 'https://api.test' },
        { provide: ToastService, useValue: { showProblem: jest.fn() } },
        { provide: SubscriptionsStore, useValue: { socialSections, reloadCountsQuietly } },
      ],
    });
    http = TestBed.inject(HttpTestingController);
  });

  afterEach(() => {
    http.verify();
    restoreIntersectionObserver();
    restorePageScroll();
  });

  function render(network: SocialNetworkName = 'bluesky'): HTMLElement {
    fixture = TestBed.createComponent(NotificationListComponent);
    fixture.componentRef.setInput('selection', { kind: 'notifications', network });
    fixture.componentRef.setInput('title', 'Notifications');
    fixture.detectChanges();
    return fixture.nativeElement;
  }

  function flushList(body: NotificationsPageDto, network: SocialNetworkName = 'bluesky'): void {
    http
      .expectOne((request) => request.url === listUrl && request.params.get('network') === network)
      .flush(body);
    fixture.detectChanges();
  }

  function showing(body: NotificationsPageDto): HTMLElement {
    const element = render();
    flushList(body);
    return element;
  }

  const text = (element: Element | null) => element?.textContent?.replace(/\s+/g, ' ').trim();

  it("opens the network's stored notifications", () => {
    const element = render();
    http.expectOne(`${listUrl}?network=bluesky`).flush(page([group(9), group(7)]));
    fixture.detectChanges();

    expect(element.querySelectorAll('app-notification-row')).toHaveLength(2);
  });

  it('reopens when the network changes', () => {
    showing(page([group(9)]));

    fixture.componentRef.setInput('selection', { kind: 'notifications', network: 'mastodon' });
    fixture.detectChanges();

    http.expectOne(`${listUrl}?network=mastodon`).flush(page([]));
  });

  it("lands a newly opened network's list at the top of the page", () => {
    const scrolled = trackPageScrollTop();
    showing(page([group(9)]));
    scrolled.top = 500;

    fixture.componentRef.setInput('selection', { kind: 'notifications', network: 'mastodon' });
    fixture.detectChanges();
    http.expectOne(`${listUrl}?network=mastodon`).flush(page([]));

    expect(scrolled.top).toBe(0);
  });

  it('disables mark all seen while the list loads', () => {
    const element = render();
    expect(element.querySelector<HTMLButtonElement>('.mark-seen')?.disabled).toBe(true);
    flushList(page([group(9, { seen: false })]));
    expect(element.querySelector<HTMLButtonElement>('.mark-seen')?.disabled).toBe(false);

    element.querySelector<HTMLButtonElement>('.refresh')?.click();
    fixture.detectChanges();
    expect(element.querySelector<HTMLButtonElement>('.mark-seen')?.disabled).toBe(true);
    flushList(page([group(9, { seen: false })]));
  });

  it('offers refresh and mark all seen, nothing else', () => {
    const element = showing(page([group(9)]));

    expect(element.querySelector('.list-header .refresh')).not.toBeNull();
    const markSeen = element.querySelector<HTMLButtonElement>('.list-header .mark-seen');
    expect(markSeen?.getAttribute('aria-label')).toBe('Mark all seen');
    expect(markSeen?.querySelector('app-icon')?.getAttribute('name')).toBe('done_all');
    expect(markSeen?.disabled).toBe(true);
    expect(element.querySelector('.list-order')).toBeNull();
    expect(element.querySelector('.unread-switch')).toBeNull();
  });

  it('refresh reads the stored list again', () => {
    const element = showing(page([group(9)]));

    element.querySelector<HTMLButtonElement>('.refresh')?.click();
    fixture.detectChanges();

    flushList(page([group(9)]));
    http.expectNone((request) => request.url.includes('/refresh'));
    expect(reloadCountsQuietly).toHaveBeenCalled();
  });

  it('marks all seen from the header', () => {
    const element = showing(page([group(9, { seen: false }), group(7, { seen: false })]));
    const markSeen = element.querySelector<HTMLButtonElement>('.mark-seen');
    expect(markSeen?.disabled).toBe(false);
    expect(element.querySelector('.dot')).not.toBeNull();

    markSeen?.click();
    fixture.detectChanges();
    expect(markSeen?.querySelector('app-pending-dots')).not.toBeNull();
    expect(markSeen?.querySelector('app-icon')).toBeNull();
    expect(markSeen?.getAttribute('aria-busy')).toBe('true');
    const request = http.expectOne(seenUrl);
    expect(request.request.body).toEqual({ network: 'bluesky', upTo: 9 });
    request.flush(null, { status: 204, statusText: 'No Content' });
    fixture.detectChanges();

    expect(element.querySelector('.dot')).toBeNull();
    expect(markSeen?.querySelector('app-pending-dots')).toBeNull();
    expect(markSeen?.querySelector('app-icon')?.getAttribute('name')).toBe('done_all');
    expect(markSeen?.hasAttribute('aria-busy')).toBe(false);
  });

  it('shows a reconnect hint per problem', () => {
    const element = showing(page([group(9)], { scopeMissing: ['@a'], needsReconnect: ['@a'] }));

    const hints = Array.from(element.querySelectorAll('app-warning-box'));
    expect(hints).toHaveLength(2);
    expect(text(hints[0])).toContain('Bluesky needs permission to read your notifications.');
    expect(text(hints[1])).toContain('Bluesky needs reconnecting before new notifications arrive.');
    for (const hint of hints) {
      const link = hint.querySelector('a');
      expect(link?.getAttribute('href')).toBe('/settings/social');
      expect(text(link)).toBe('Reconnect Bluesky');
    }
  });

  it('ends with the 30-day line once nothing more is out there', () => {
    const element = showing(page([group(9)]));

    expect(text(element.querySelector('.end'))).toBe("That's everything from the last 30 days.");
    expect(element.querySelector('.foot')).toBeNull();
  });

  it('waits at a sentinel while more is out there', () => {
    const element = showing(page([group(9)], { nextCursor: 5 }));

    expect(element.querySelector('.end')).toBeNull();
    expect(element.querySelector('.foot')).not.toBeNull();
  });

  it('loads the next page when the sentinel nears', () => {
    const observer = stubIntersectionObserver();
    const element = showing(page([group(9)], { nextCursor: 5 }));
    const sentinel = element.querySelector('.foot') as Element;
    expect(observer.observed).toContain(sentinel);

    observer.report(sentinel, true);

    const request = http.expectOne((candidate) => candidate.url === listUrl);
    expect(request.request.params.get('cursor')).toBe('5');
    request.flush(page([group(5)]));
  });

  it('keeps paging while the sentinel stays in reach', () => {
    installMockIntersectionObserver();
    const element = showing(page([group(9)], { nextCursor: 5 }));
    const sentinel = () => element.querySelector('.foot') as Element;

    MockIntersectionObserver.report(sentinel(), 1);
    const observersBefore = MockIntersectionObserver.instances.length;
    http
      .expectOne((candidate) => candidate.params.get('cursor') === '5')
      .flush(page([group(5)], { nextCursor: 3 }));
    fixture.detectChanges();

    // An observer only calls back on a change, or once when it starts observing.
    const fresh = MockIntersectionObserver.instances
      .slice(observersBefore)
      .find((observer) => observer.targets.has(sentinel()));
    expect(fresh).toBeDefined();
    fresh?.callback(
      [{ target: sentinel(), isIntersecting: true } as unknown as IntersectionObserverEntry],
      fresh as unknown as IntersectionObserver,
    );
    http.expectOne((candidate) => candidate.params.get('cursor') === '3').flush(page([group(3)]));
  });

  it('says so when there is nothing', () => {
    const element = showing(page([]));

    expect(text(element.querySelector('.empty'))).toBe('No notifications from the last 30 days.');
    expect(element.querySelector('.end')).toBeNull();
  });

  it('names the receiving account when the network has several', () => {
    socialSections.set([
      {
        network: 'bluesky',
        networkAccounts: [
          { id: 1, handle: '@a' },
          { id: 2, handle: '@b' },
        ],
      } as unknown as SocialSection,
    ]);
    showing(page([group(9)]));

    const row = fixture.debugElement.query(By.directive(NotificationRowComponent));
    expect((row.componentInstance as NotificationRowComponent).showsAccount()).toBe(true);
  });

  it('names no account when the network has one', () => {
    socialSections.set([
      {
        network: 'bluesky',
        networkAccounts: [{ id: 1, handle: '@a' }],
      } as unknown as SocialSection,
    ]);
    showing(page([group(9)]));

    const row = fixture.debugElement.query(By.directive(NotificationRowComponent));
    expect((row.componentInstance as NotificationRowComponent).showsAccount()).toBe(false);
  });
});
