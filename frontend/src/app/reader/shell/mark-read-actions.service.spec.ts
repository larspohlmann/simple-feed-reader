import { TestBed } from '@angular/core/testing';
import { DestroyRef, Signal, signal } from '@angular/core';
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
import { ListContext } from '../state/list-context';
import { SavedSearchesStore } from '../state/saved-searches.store';
import { SubscriptionsStore } from '../state/subscriptions.store';
import { makeSubscription } from '../testing/subscription.factory';
import { MarkReadActions } from './mark-read-actions.service';
import { ListCover } from './list-cover';
import type { ListHeading } from './list-heading.service';
import { TestListContext, provideRouteListForTest } from '../../../testing/list-context-testing';

const subscription = (
  id: number,
  unreadCount: number,
  social: SubscriptionDto['social'],
): SubscriptionDto => makeSubscription({ id, unreadCount, social });

describe('MarkReadActions recounting after a mark-read', () => {
  const profileFeed = {
    ...subscription(1, 4, { network: 'bluesky', accountId: null, kind: 'profile' }),
    tags: [{ id: 5, name: 'News', color: null, icon: null, position: 0 }],
  };
  const homeFeed = subscription(2, 6, { network: 'bluesky', accountId: 3, kind: 'home' });
  const otherNetworkProfile = subscription(3, 8, {
    network: 'mastodon',
    accountId: null,
    kind: 'profile',
  });

  let actions: MarkReadActions;
  let subscriptions: SubscriptionsStore;
  let reloadCountsQuietly: jest.SpyInstance;
  let fullLoad: jest.SpyInstance;
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
        ...provideRouteListForTest(
          {
            loadedAt: () => '2026-10-16T10:00:00Z',
            entries: () => [{ id: 3 }],
            load: jest.fn(),
            markHiddenLocally: jest.fn(),
            runThenReload: (
              mutation: { subscribe: (next: () => void) => void },
              reload: () => void,
            ) => mutation.subscribe(reload),
            withMutatingCue: <T>(mutation: T) => mutation,
          },
          currentSelection as Signal<Selection>,
        ),
        { provide: SavedSearchesStore, useValue: { load: jest.fn() } },
      ],
    });
    TestBed.inject(TokenStore).set('user-a.jwt');
    const api = TestBed.inject(ReaderApi);
    for (const request of [
      'markRead',
      'markSearchRead',
      'markForYouRead',
      'markSavedSearchesRead',
      'markSingleSavedSearchRead',
      'markTrendingStoryRead',
      'markEntriesRead',
    ] as const) {
      jest.spyOn(api, request).mockReturnValue(of(undefined));
    }
    subscriptions = TestBed.inject(SubscriptionsStore);
    subscriptions.load();
    TestBed.inject(HttpTestingController)
      .expectOne('https://api.test/api/subscriptions')
      .flush({
        subscriptions: [profileFeed, homeFeed, otherNetworkProfile],
        favoritesCount: 0,
        keptCount: 0,
        viewedCount: 0,
        trending: { window: 'day', unopened: 0 },
        unreadTotals: {
          allItems: 0,
          forYou: 0,
          tags: [{ id: 5, unreadCount: 4 }],
          networks: [],
          groups: { tags: 0, feeds: 0 },
        },
      });
    reloadCountsQuietly = jest.spyOn(subscriptions, 'reloadCountsQuietly').mockImplementation();
    fullLoad = jest.spyOn(subscriptions, 'load');
    actions = TestBed.inject(MarkReadActions);
  });

  it.each<Selection>([
    { kind: 'all', unread: true },
    { kind: 'tag', id: 5, unread: true },
    { kind: 'subscription', id: 1, unread: true },
    { kind: 'network', unread: true, network: 'bluesky' },
    { kind: 'network', unread: true, network: 'bluesky', feedKind: 'profile' },
    { kind: 'group', unread: true, group: 'tags' },
    { kind: 'search', unread: true, term: 'news' },
    { kind: 'for-you', unread: true },
    { kind: 'saved-searches', unread: true },
    { kind: 'saved-search', id: 7, unread: true },
  ])('leaves the badges to the server recount after marking $kind read', (selection) => {
    markSelectionRead(selection);

    expect(reloadCountsQuietly).toHaveBeenCalledTimes(1);
    expect(fullLoad).not.toHaveBeenCalled();
    expect(unreadCounts()).toEqual([4, 6, 8]);
  });

  it('marks a feed group read through the mark-read endpoint', () => {
    const markRead = jest.spyOn(TestBed.inject(ReaderApi), 'markRead');
    currentSelection.set({ kind: 'group', unread: true, group: 'tags' });

    actions.confirmMarkAllRead();

    expect(markRead).toHaveBeenCalledWith(
      { scope: 'group', group: 'tags' },
      '2026-10-16T10:00:00Z',
    );
  });

  it('recounts from the server after marking the entries above read', () => {
    currentSelection.set({ kind: 'all', unread: false });

    actions.confirmMarkAboveRead([1, 2], jest.fn());

    expect(reloadCountsQuietly).toHaveBeenCalledTimes(1);
    expect(fullLoad).not.toHaveBeenCalled();
  });

  it("reloads the reader's list after marking it read, leaving its rows as they were", () => {
    const routeRows = TestBed.inject(ListContext).entries;
    currentSelection.set({ kind: 'tag', id: 5, unread: false });

    actions.confirmMarkAllRead();

    expect(routeRows.load).toHaveBeenCalledWith({ view: 'all', tag: 5 });
    expect(routeRows.markHiddenLocally).not.toHaveBeenCalled();
  });

  describe("a Trending story's list over the reader's (#2329)", () => {
    const story: Selection = { kind: 'trending-story', id: 42, unread: false };
    let storyRows: {
      loadedAt: () => string;
      entries: () => { id: number }[];
      load: jest.Mock;
      markHiddenLocally: jest.Mock;
      runThenReload: (
        mutation: { subscribe: (next: () => void) => void },
        reload: () => void,
      ) => void;
      withMutatingCue: <T>(mutation: T) => T;
    };
    const heading = {} as ListHeading;

    beforeEach(() => {
      storyRows = {
        loadedAt: () => '2026-10-16T11:00:00Z',
        entries: () => [{ id: 7 }, { id: 8 }],
        load: jest.fn(),
        markHiddenLocally: jest.fn(),
        runThenReload: (mutation, reload) => mutation.subscribe(reload),
        withMutatingCue: (mutation) => mutation,
      };
      const context = new TestListContext(storyRows as unknown as EntriesStore, signal(story));
      TestBed.inject(ListCover).coverWith({ context, heading }, TestBed.inject(DestroyRef));
      currentSelection.set({ kind: 'trending', window: 'day' });
    });

    it('marks the story read and reloads only the story', () => {
      const markStoryRead = jest.spyOn(TestBed.inject(ReaderApi), 'markTrendingStoryRead');

      actions.confirmMarkAllRead();

      expect(markStoryRead).toHaveBeenCalledWith(42, '2026-10-16T11:00:00Z');
      expect(storyRows.load).toHaveBeenCalledWith({ view: 'all', storyId: 42 });
      expect(TestBed.inject(ListContext).entries.load).not.toHaveBeenCalled();
      expect(reloadCountsQuietly).toHaveBeenCalledTimes(1);
    });

    it("marks the rows above read in the story's list, not the reader's", () => {
      actions.confirmMarkAboveRead([7], jest.fn());

      expect(storyRows.markHiddenLocally).toHaveBeenCalledWith([7]);
      expect(TestBed.inject(ListContext).entries.markHiddenLocally).not.toHaveBeenCalled();
    });

    it('offers mark all read for the story', () => {
      expect(actions.canMarkAllRead()).toBe(true);
    });
  });
});
