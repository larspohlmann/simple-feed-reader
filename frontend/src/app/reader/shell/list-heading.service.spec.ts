import { inject, signal } from '@angular/core';
import { TestBed } from '@angular/core/testing';
import { provideTranslocoTesting } from '../../../testing/transloco-testing';
import { LanguageService } from '../../core/i18n/language.service';
import { SubscriptionDto, TagDto } from '../models';
import { Selection, SelectionOf } from '../query/query';
import { ReadingLayoutService } from '../reading-layout.service';
import { EntriesStore } from '../state/entries.store';
import { RecommendationsService } from '../state/recommendations.service';
import { SavedSearchesStore } from '../state/saved-searches.store';
import { VisibilitySwitchesStore } from '../state/visibility-switches.store';
import { tagsById } from '../state/tag-ancestry';
import { VisibilitySwitches } from '../state/visibility';
import { makeTag } from '../testing/tag.factory';
import { INCLUDED_EVERYWHERE } from '../tag-inclusion';
import { SocialSection, buildSocialSections, socialFeedGroup } from '../state/social-sections';
import { NO_NOTIFICATION_COUNTS } from '../state/notification-counts';
import { SubscriptionsStore } from '../state/subscriptions.store';
import { SubscriptionRows } from '../state/subscription-rows';
import { TagNode, countDescendants, tagNodesById } from '../state/subscription-derivations';
import { NO_UNREAD_TOTALS, UnreadTotals } from '../state/unread-totals';
import { aStory, aStoryArticle, aStoryCard } from '../../../testing/entry-testing';
import { makeSubscription } from '../testing/subscription.factory';
import { TrendingBuiltAt } from '../trending/trending-built-at';
import { TrendingWindowService } from '../trending/trending-window.service';
import { ListHeading } from './list-heading.service';
import { ListContext } from '../state/list-context';
import { OpenStory } from '../state/open-story';
import { StoryCard } from '../trending/trending.models';
import { FollowedStoriesStore } from '../state/followed-stories.store';
import { TestListContext } from '../../../testing/list-context-testing';
import { LabelsStore } from '../labels/labels.store';
import { LabelTally } from '../labels/labels.models';

const home = (accountId: number, over: Partial<SubscriptionDto> = {}): SubscriptionDto =>
  makeSubscription({
    id: accountId,
    title: 'Home',
    social: { network: 'bluesky', accountId, kind: 'home' },
    ...over,
  });

function headingFor(subscription: SubscriptionDto, sections: SocialSection[]): string {
  return listHeadingFor(subscription, sections).title();
}

function listHeadingFor(subscription: SubscriptionDto, sections: SocialSection[]): ListHeading {
  const selection: Selection = { kind: 'subscription', id: subscription.id, unread: true };
  TestBed.configureTestingModule({
    imports: [provideTranslocoTesting()],
    providers: [
      ListHeading,
      {
        provide: SubscriptionsStore,
        useFactory: () => {
          const rows = inject(SubscriptionRows);
          rows.stored.set([subscription]);
          return { subscriptions: rows.all, socialSections: signal(sections) };
        },
      },
      { provide: LanguageService, useValue: { lang: signal('en') } },
      {
        provide: ListContext,
        useValue: new TestListContext({} as EntriesStore, signal(selection)),
      },
      { provide: OpenStory, useValue: {} },
      { provide: FollowedStoriesStore, useValue: {} },
      { provide: RecommendationsService, useValue: {} },
      { provide: SavedSearchesStore, useValue: {} },
      { provide: VisibilitySwitchesStore, useValue: {} },
      { provide: TrendingWindowService, useValue: { window: signal('day') } },
      { provide: ReadingLayoutService, useValue: { mode: signal('magazine') } },
      { provide: LabelsStore, useValue: {} },
    ],
  });
  return TestBed.inject(ListHeading);
}

const accountsOf = (...handles: string[]): SocialSection => ({
  network: 'bluesky',
  networkAccounts: [],
  groups: { search: socialFeedGroup([], 0), profile: socialFeedGroup([], 0) },
  unreadCount: 0,
  entryCount: 0,
  saved: null,
  showsHandles: handles.length > 1,
  notificationsUnseen: 0,
  accounts: handles.map((handle, index) => ({
    accountId: index + 1,
    handle,
    subscriptions: [home(index + 1)],
  })),
});

describe('ListHeading for a Home timeline', () => {
  it('reads Home through i18n, not the backend title', () => {
    expect(headingFor(home(1), [accountsOf('@alice.bsky.social')])).toBe('Home@bsky');
  });

  it('says whose Home it is when the network has several accounts', () => {
    expect(headingFor(home(2), [accountsOf('@alice.bsky.social', '@work.bsky.social')])).toBe(
      'Home@bsky · @work.bsky.social',
    );
  });

  it('keeps a renamed Home under its own name', () => {
    const renamed = home(1, { title: 'Main', customTitle: 'Main' });

    expect(headingFor(renamed, [accountsOf('@a', '@b')])).toBe('Main');
  });
});

describe('ListHeading feed intro for a Home timeline', () => {
  const site = { siteUrl: 'https://bsky.app' };

  it('names an unrenamed Home through i18n, as the heading does', () => {
    const heading = listHeadingFor(home(1, site), [accountsOf('@alice.bsky.social')]);

    expect(heading.feedIntroTitle()).toBe('Home@bsky');
  });

  it('keeps a renamed Home under its own name', () => {
    const renamed = home(1, { ...site, title: 'Main', customTitle: 'Main' });

    expect(listHeadingFor(renamed, [accountsOf('@a')]).feedIntroTitle()).toBe('Main');
  });
});

describe("ListHeading for a network's combined view", () => {
  const subscriptions: SubscriptionDto[] = [
    home(1, { unreadCount: 3, entryCount: 30, includeInAllItems: false }),
    makeSubscription({
      id: 2,
      unreadCount: 4,
      entryCount: 40,
      social: { network: 'bluesky', accountId: null, kind: 'profile' },
    }),
    makeSubscription({
      id: 3,
      unreadCount: 9,
      entryCount: 90,
      social: { network: 'mastodon', accountId: null, kind: 'profile' },
    }),
    makeSubscription({ id: 4, unreadCount: 20, entryCount: 200 }),
    makeSubscription({
      id: 5,
      unreadCount: 5,
      entryCount: 50,
      social: { network: 'mastodon', accountId: 7, kind: 'home' },
    }),
    makeSubscription({
      id: 6,
      unreadCount: 6,
      entryCount: 60,
      social: { network: 'bluesky', accountId: 1, kind: 'search', query: 'rss' },
    }),
  ];

  const NETWORK_TOTALS: UnreadTotals = {
    ...NO_UNREAD_TOTALS,
    networks: new Map([
      ['bluesky', 13],
      ['mastodon', 14],
    ]),
    networkProfiles: new Map([
      ['bluesky', 4],
      ['mastodon', 9],
    ]),
  };

  function networkHeading(
    unread: boolean,
    selected: Partial<Pick<SelectionOf<'network'>, 'network' | 'feedKind'>> = {},
  ): ListHeading {
    const selection: Selection = {
      kind: 'network',
      unread,
      network: 'bluesky',
      ...selected,
    };
    return headingWith(selection, {
      subscriptions: signal(subscriptions),
      socialSections: signal(
        buildSocialSections(subscriptions, [], {
          unread: NETWORK_TOTALS,
          notifications: NO_NOTIFICATION_COUNTS,
        }),
      ),
    });
  }

  it('names the tab after the list title', () => {
    expect(networkHeading(true).tabTitle()).toBe('Bluesky');
  });

  it('heads the notifications list Notifications, beside the network logo', () => {
    const heading = headingWith({ kind: 'notifications', network: 'bluesky' }, {});

    expect(heading.title()).toBe('Notifications');
  });

  it("names the notifications' network in the tab, which shows no logo", () => {
    const heading = headingWith({ kind: 'notifications', network: 'mastodon' }, {});

    expect(heading.tabTitle()).toBe('Mastodon · Notifications');
  });

  it('is titled by the network', () => {
    expect(networkHeading(true).title()).toBe('Bluesky');
  });

  it("counts the unread of the network's feeds, excluded ones included", () => {
    expect(networkHeading(true).titleCount()).toEqual({ value: 13, counts: 'unread' });
  });

  it("counts every post of the network's feeds under All posts", () => {
    expect(networkHeading(false).titleCount()).toEqual({ value: 130, counts: 'items' });
  });

  it("titles a network's profile feeds as Feeds", () => {
    const heading = networkHeading(true, { network: 'mastodon', feedKind: 'profile' });

    expect(heading.title()).toBe('Mastodon · Feeds');
  });

  it('titles a Lemmy profile group as Communities', () => {
    const heading = networkHeading(true, { network: 'lemmy', feedKind: 'profile' });

    expect(heading.title()).toBe('Lemmy · Communities');
  });

  it("counts only the network's profile feeds when narrowed to them", () => {
    const selected = { network: 'mastodon', feedKind: 'profile' } as const;

    expect(networkHeading(true, selected).titleCount()).toEqual({ value: 9, counts: 'unread' });
  });

  it("titles a network's search feeds as Searches", () => {
    const heading = networkHeading(true, { feedKind: 'search' });

    expect(heading.title()).toBe('Bluesky · Searches');
  });

  it("counts only the unread of the network's search feeds when narrowed to them", () => {
    expect(networkHeading(true, { feedKind: 'search' }).titleCount()).toEqual({
      value: 6,
      counts: 'unread',
    });
  });

  it("counts only the posts of the network's search feeds under All posts", () => {
    expect(networkHeading(false, { feedKind: 'search' }).titleCount()).toEqual({
      value: 60,
      counts: 'items',
    });
  });
});

describe("ListHeading for a feed group's combined view", () => {
  const subscriptions: SubscriptionDto[] = [
    makeSubscription({
      id: 1,
      entryCount: 10,
      tags: [
        {
          id: 5,
          name: 'News',
          color: null,
          icon: null,
          position: 0,
          parentId: null,
          ...INCLUDED_EVERYWHERE,
        },
      ],
    }),
    makeSubscription({
      id: 2,
      entryCount: 20,
      tags: [
        {
          id: 6,
          name: 'Tech',
          color: null,
          icon: null,
          position: 1,
          parentId: null,
          ...INCLUDED_EVERYWHERE,
        },
      ],
    }),
    makeSubscription({ id: 3, entryCount: 300 }),
  ];

  const GROUP_TOTALS: UnreadTotals = { ...NO_UNREAD_TOTALS, groups: { tags: 4, feeds: 9 } };

  function groupHeading(unread: boolean, group: 'tags' | 'feeds'): ListHeading {
    return headingWith(
      { kind: 'group', unread, group },
      {
        subscriptions: signal(subscriptions),
        feedGroupCounts: signal({
          tags: { unreadCount: GROUP_TOTALS.groups.tags, entryCount: 30 },
          feeds: { unreadCount: GROUP_TOTALS.groups.feeds, entryCount: 300 },
        }),
      },
    );
  }

  it('is titled by the section', () => {
    expect(groupHeading(true, 'feeds').title()).toBe('Feeds');
  });

  it("counts the section's unread under Unread", () => {
    expect(groupHeading(true, 'tags').titleCount()).toEqual({ value: 4, counts: 'unread' });
  });

  it("counts the section's posts under All posts", () => {
    expect(groupHeading(false, 'tags').titleCount()).toEqual({ value: 30, counts: 'items' });
  });
});

/** A ListHeading over one combined view: only the selection and the store stub differ between views. */
function headingWith(
  selection: Selection,
  subscriptionsStore: object,
  switches: VisibilitySwitches = NO_SWITCHES,
): ListHeading {
  TestBed.configureTestingModule({
    imports: [provideTranslocoTesting()],
    providers: [
      ListHeading,
      { provide: SubscriptionsStore, useValue: subscriptionsStore },
      { provide: LanguageService, useValue: { lang: signal('en') } },
      {
        provide: ListContext,
        useValue: new TestListContext({} as EntriesStore, signal(selection)),
      },
      { provide: OpenStory, useValue: {} },
      { provide: FollowedStoriesStore, useValue: {} },
      { provide: RecommendationsService, useValue: {} },
      { provide: SavedSearchesStore, useValue: {} },
      { provide: VisibilitySwitchesStore, useValue: { switches: signal(switches) } },
      { provide: TrendingWindowService, useValue: { window: signal('day') } },
      { provide: ReadingLayoutService, useValue: { mode: signal('magazine') } },
      { provide: LabelsStore, useValue: {} },
    ],
  });
  return TestBed.inject(ListHeading);
}

const NO_SWITCHES: VisibilitySwitches = {
  tagsById: new Map(),
  excludedNetworks: {
    allItems: new Set(),
    forYou: new Set(),
    savedSearches: new Set(),
    trending: new Set(),
  },
};

describe('ListHeading for All items', () => {
  it('counts only the posts of feeds the switches leave in', () => {
    const tech = makeTag(6, 'Tech', { includeInAllItems: false });
    const heading = headingWith(
      { kind: 'all', unread: false },
      {
        totalUnread: signal(0),
        subscriptions: signal([
          makeSubscription({ id: 1, entryCount: 10 }),
          makeSubscription({ id: 2, entryCount: 20, tags: [tech] }),
        ]),
      },
      { ...NO_SWITCHES, tagsById: tagsById([tech]) },
    );

    expect(heading.titleCount()).toEqual({ value: 10, counts: 'items' });
  });
});

describe('ListHeading for a child tag (#1736)', () => {
  const tagOf = (id: number, name: string, parentId: number | null): TagDto => ({
    id,
    name,
    color: null,
    icon: null,
    position: 0,
    parentId,
    ...INCLUDED_EVERYWHERE,
  });
  const nodeOf = (tag: TagDto, children: TagNode[] = []): TagNode => ({
    tag,
    subscriptions: [],
    children,
    unreadCount: 0,
    entryCount: 0,
    descendantCount: countDescendants(children),
  });

  it('finds a child tag that only appears inside its parent node', () => {
    const tree = [nodeOf(tagOf(1, 'Reading', null), [nodeOf(tagOf(2, 'Long reads', 1))])];
    const selection: Selection = { kind: 'tag', id: 2, unread: true };

    const heading = headingWith(selection, { tagNodeById: signal(tagNodesById(tree)) });

    expect(heading.selectedTag()?.name).toBe('Long reads');
  });
});

describe('ListHeading for the For you stream (#2582)', () => {
  const streamHeading = (unread: boolean) =>
    headingWith(
      { kind: 'for-you-stream', unread },
      { forYouStream: signal({ available: true, unreadCount: 2, entryCount: 9 }) },
    );

  it('is titled For you stream', () => {
    expect(streamHeading(false).title()).toBe('For you stream');
  });

  it('counts the unread entries behind the unread switch', () => {
    expect(streamHeading(true).titleCount()).toEqual({ value: 2, counts: 'unread' });
  });

  it('counts every entry with the switch off', () => {
    expect(streamHeading(false).titleCount()).toEqual({ value: 9, counts: 'items' });
  });
});

describe('ListHeading for the Shared list (#2007)', () => {
  const sharedHeading = () => headingWith({ kind: 'shared' }, { sharedCount: signal(3) });

  it('is titled Shared', () => {
    expect(sharedHeading().title()).toBe('Shared');
  });

  it('counts the shared entries', () => {
    expect(sharedHeading().titleCount()).toEqual({ value: 3, counts: 'items' });
  });
});

describe('ListHeading for a Favorites or Kept label (#2581)', () => {
  const work: LabelTally = { id: 3, name: 'Work', favoriteCount: 3, keptCount: 1, articleCount: 4 };
  const collectionCounts = { favoritesCount: signal(42), keptCount: signal(7) };

  function labelledHeading(selection: Selection, loaded = true): ListHeading {
    TestBed.overrideProvider(LabelsStore, {
      useValue: {
        loaded: signal(loaded),
        labels: signal([work]),
        unlabeled: signal({ favoriteCount: 5, keptCount: 2 }),
      },
    });
    return headingWith(selection, collectionCounts);
  }

  it('names and counts the label inside Favorites', () => {
    const heading = labelledHeading({ kind: 'favorites', label: 3 });

    expect(heading.title()).toBe('Work');
    expect(heading.tabTitle()).toBe('Work');
    expect(heading.titleCount()).toEqual({ value: 3, counts: 'items' });
  });

  it('counts the same label by its kept tally inside Kept', () => {
    expect(labelledHeading({ kind: 'kept', label: 3 }).titleCount()).toEqual({
      value: 1,
      counts: 'items',
    });
  });

  it('names and counts the posts without a label', () => {
    const heading = labelledHeading({ kind: 'favorites', label: 'unlabeled' });

    expect(heading.title()).toBe('Unlabeled');
    expect(heading.titleCount()).toEqual({ value: 5, counts: 'items' });
  });

  it('shows the collection count for Unlabeled until the tallies have loaded', () => {
    const heading = labelledHeading({ kind: 'favorites', label: 'unlabeled' }, false);

    expect(heading.title()).toBe('Favorites');
    expect(heading.titleCount()).toEqual({ value: 42, counts: 'items' });
  });

  it('falls back to the whole collection for a label that no longer exists', () => {
    const heading = labelledHeading({ kind: 'kept', label: 99 });

    expect(heading.title()).toBe('Kept');
    expect(heading.titleCount()).toEqual({ value: 7, counts: 'items' });
  });

  it('heads the whole collection when no label is chosen', () => {
    const heading = labelledHeading({ kind: 'favorites' });

    expect(heading.title()).toBe('Favorites');
    expect(heading.titleCount()).toEqual({ value: 42, counts: 'items' });
  });
});

describe('ListHeading for the Trending list (#2017)', () => {
  const storyOfThree = (id: number) =>
    aStory({
      id,
      articles: [1, 2, 3].map((position) => aStoryArticle({ id: id * 10 + position })),
    });
  const trendingHeading = (window: 'day' | 'week' = 'day') => {
    TestBed.configureTestingModule({
      imports: [provideTranslocoTesting()],
      providers: [
        ListHeading,
        { provide: SubscriptionsStore, useValue: {} },
        { provide: LanguageService, useValue: { lang: signal('en') } },
        {
          provide: ListContext,
          useValue: new TestListContext(
            { items: signal([storyOfThree(1), storyOfThree(2)]) } as unknown as EntriesStore,
            signal<Selection>({ kind: 'trending', window }),
          ),
        },
        { provide: OpenStory, useValue: {} },
        { provide: FollowedStoriesStore, useValue: {} },
        { provide: RecommendationsService, useValue: {} },
        { provide: SavedSearchesStore, useValue: {} },
        { provide: VisibilitySwitchesStore, useValue: {} },
        { provide: TrendingWindowService, useValue: { window: signal('day') } },
        { provide: ReadingLayoutService, useValue: { mode: signal('magazine') } },
        { provide: LabelsStore, useValue: {} },
      ],
    });
    return TestBed.inject(ListHeading);
  };

  it('names the 24 h window in its title (#2326)', () => {
    expect(trendingHeading('day').title()).toBe('Trending · 24 h');
  });

  it('names the 7 day window in its title (#2326)', () => {
    expect(trendingHeading('week').title()).toBe('Trending · 7 days');
  });

  it('counts each story once, however many articles it holds (#2328)', () => {
    expect(trendingHeading().titleCount()).toEqual({ value: 2, counts: 'items' });
  });
});

describe('ListHeading last refreshed on Trending (#2264)', () => {
  function trendingHeading(window: 'day' | 'week'): ListHeading {
    TestBed.configureTestingModule({
      imports: [provideTranslocoTesting()],
      providers: [
        ListHeading,
        { provide: TrendingWindowService, useValue: { window: signal(window) } },
        { provide: SubscriptionsStore, useValue: {} },
        { provide: LanguageService, useValue: { lang: signal('en') } },
        {
          provide: ListContext,
          useValue: new TestListContext(
            {} as EntriesStore,
            signal<Selection>({ kind: 'trending', window: 'day' }),
          ),
        },
        { provide: OpenStory, useValue: {} },
        { provide: FollowedStoriesStore, useValue: {} },
        { provide: RecommendationsService, useValue: {} },
        { provide: SavedSearchesStore, useValue: {} },
        { provide: VisibilitySwitchesStore, useValue: {} },
        { provide: ReadingLayoutService, useValue: { mode: signal('magazine') } },
        { provide: LabelsStore, useValue: {} },
      ],
    });
    return TestBed.inject(ListHeading);
  }

  it('reads the build time of the window on screen', () => {
    const heading = trendingHeading('week');
    const builtAt = TestBed.inject(TrendingBuiltAt);
    builtAt.record('day', '2026-11-16T07:30:00+00:00');
    builtAt.record('week', '2026-11-16T07:00:00+00:00');

    expect(heading.lastRefreshed()).toBe('2026-11-16T07:00:00+00:00');
  });

  it('has nothing to say before the window was loaded', () => {
    expect(trendingHeading('day').lastRefreshed()).toBeNull();
  });
});

describe("ListHeading over a Trending story's list (#2329)", () => {
  const story = (unread: boolean): Selection => ({ kind: 'trending-story', id: 42, unread });

  function storyHeading(
    selection: Selection,
    openStory: Partial<OpenStory>,
    followed: { headline: string } | null = null,
  ): ListHeading {
    TestBed.configureTestingModule({
      imports: [provideTranslocoTesting()],
      providers: [
        ListHeading,
        {
          provide: ListContext,
          useValue: new TestListContext({} as EntriesStore, signal(selection)),
        },
        { provide: OpenStory, useValue: openStory },
        { provide: FollowedStoriesStore, useValue: { byId: () => followed } },
        { provide: SubscriptionsStore, useValue: {} },
        { provide: LanguageService, useValue: { lang: signal('en') } },
        { provide: RecommendationsService, useValue: {} },
        { provide: SavedSearchesStore, useValue: {} },
        { provide: VisibilitySwitchesStore, useValue: {} },
        { provide: TrendingWindowService, useValue: { window: signal('day') } },
        { provide: ReadingLayoutService, useValue: { mode: signal('magazine') } },
        { provide: LabelsStore, useValue: {} },
      ],
    });
    return TestBed.inject(ListHeading);
  }

  const tally = { memberCount: 31, unreadCount: 12 };
  const aCard = (title: string): StoryCard => aStoryCard({ id: 42, title, outletCount: 1 });

  it("titles the list with the story's page title, else its followed headline, else Story", () => {
    expect(
      storyHeading(story(false), {
        cardOf: () => aCard('Harbour strike'),
        tallyOf: () => tally,
      }).title(),
    ).toBe('Harbour strike');
    TestBed.resetTestingModule();
    expect(
      storyHeading(
        story(false),
        { cardOf: () => null, tallyOf: () => tally },
        { headline: 'Port closed' },
      ).title(),
    ).toBe('Port closed');
    TestBed.resetTestingModule();
    expect(storyHeading(story(false), { cardOf: () => null, tallyOf: () => null }).title()).toBe(
      'Story',
    );
  });

  it("counts every row of the story from the server's tally, not the page loaded", () => {
    const heading = storyHeading(story(false), { cardOf: () => null, tallyOf: () => tally });

    expect(heading.titleCount()).toEqual({ value: 31, counts: 'items' });
  });

  it('counts the unread rows under Only unread', () => {
    const heading = storyHeading(story(true), { cardOf: () => null, tallyOf: () => tally });

    expect(heading.titleCount()).toEqual({ value: 12, counts: 'unread' });
  });
});
