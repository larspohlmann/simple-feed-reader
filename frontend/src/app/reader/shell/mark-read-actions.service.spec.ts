import { TestBed } from '@angular/core/testing';
import { signal } from '@angular/core';
import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { of } from 'rxjs';
import { provideTranslocoTesting } from '../../../testing/transloco-testing';
import { API_BASE_URL } from '../../core/api';
import { TokenStore } from '../../core/auth/token.store';
import { ConfirmService } from '../../shared/confirm-dialog/confirm.service';
import { ReaderApi } from '../reader-api';
import { SubscriptionDto } from '../models';
import { Selection } from '../query/query';
import { EntriesStore } from '../state/entries.store';
import { RecommendationsService } from '../state/recommendations.service';
import { SavedSearchesStore } from '../state/saved-searches.store';
import { SubscriptionsStore } from '../state/subscriptions.store';
import { MarkReadActions } from './mark-read-actions.service';
import { ReaderRouteState } from './reader-route-state.service';

const subscription = (
  id: number,
  unreadCount: number,
  social: SubscriptionDto['social'],
): SubscriptionDto => ({
  id,
  feedId: id * 10,
  title: `s${id}`,
  faviconUrl: null,
  customTitle: null,
  feedUrl: `https://f/${id}`,
  siteUrl: null,
  description: null,
  imageUrl: null,
  status: 'active',
  sourceFormat: 'xml',
  createdAt: 'x',
  lastFetchedAt: null,
  lastSuccessfulFetchAt: null,
  lastNewContentAt: null,
  nextFetchAt: null,
  consecutiveFailures: 0,
  lastErrorMessage: null,
  position: 0,
  tags: [],
  unreadCount,
  entryCount: 0,
  includeInAllItems: true,
  includeInForYou: true,
  social,
});

describe('MarkReadActions marking a network read', () => {
  const profileFeed = subscription(1, 4, { network: 'bluesky', accountId: null, kind: 'profile' });
  const homeFeed = subscription(2, 6, { network: 'bluesky', accountId: 3, kind: 'home' });
  const otherNetworkProfile = subscription(3, 8, {
    network: 'mastodon',
    accountId: null,
    kind: 'profile',
  });

  let actions: MarkReadActions;
  let subscriptions: SubscriptionsStore;
  let markRead: jest.SpyInstance;
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
    markRead = jest.spyOn(TestBed.inject(ReaderApi), 'markRead').mockReturnValue(of(undefined));
    subscriptions = TestBed.inject(SubscriptionsStore);
    subscriptions.load();
    TestBed.inject(HttpTestingController)
      .expectOne('https://api.test/api/subscriptions')
      .flush({
        subscriptions: [profileFeed, homeFeed, otherNetworkProfile],
        favoritesCount: 0,
        keptCount: 0,
        viewedCount: 0,
      });
    actions = TestBed.inject(MarkReadActions);
  });

  it('zeroes only the profile feeds of the network when its Feeds row is marked read', () => {
    markSelectionRead({
      kind: 'network',
      id: null,
      unread: true,
      network: 'bluesky',
      feedKind: 'profile',
    });

    expect(markRead).toHaveBeenCalledTimes(1);
    expect(unreadCounts()).toEqual([0, 6, 8]);
  });

  it('zeroes every feed of the network when the whole network is marked read', () => {
    markSelectionRead({ kind: 'network', id: null, unread: true, network: 'bluesky' });

    expect(unreadCounts()).toEqual([0, 0, 8]);
  });
});
