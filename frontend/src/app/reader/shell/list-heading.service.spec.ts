import { inject, signal } from '@angular/core';
import { TestBed } from '@angular/core/testing';
import { provideTranslocoTesting } from '../../../testing/transloco-testing';
import { LanguageService } from '../../core/i18n/language.service';
import { SubscriptionDto, TagDto } from '../models';
import { Selection } from '../query/query';
import { ReadingLayoutService } from '../reading-layout.service';
import { EntriesStore } from '../state/entries.store';
import { RecommendationsService } from '../state/recommendations.service';
import { SavedSearchesStore } from '../state/saved-searches.store';
import { SocialSection, buildSocialSections, socialProfiles } from '../state/social-sections';
import { SubscriptionsStore } from '../state/subscriptions.store';
import { SubscriptionRows } from '../state/subscription-rows';
import { TagNode, tagNodesById } from '../state/subscription-derivations';
import { NO_UNREAD_TOTALS, UnreadTotals } from '../state/unread-totals';
import { makeSubscription } from '../testing/subscription.factory';
import { ListHeading } from './list-heading.service';
import { ReaderRouteState } from './reader-route-state.service';

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
      { provide: ReaderRouteState, useValue: { selection: signal(selection) } },
      {
        provide: SubscriptionsStore,
        useFactory: () => {
          const rows = inject(SubscriptionRows);
          rows.stored.set([subscription]);
          return { subscriptions: rows.all, socialSections: signal(sections) };
        },
      },
      { provide: LanguageService, useValue: { lang: signal('en') } },
      { provide: EntriesStore, useValue: {} },
      { provide: RecommendationsService, useValue: {} },
      { provide: SavedSearchesStore, useValue: {} },
      { provide: ReadingLayoutService, useValue: { mode: signal('magazine') } },
    ],
  });
  return TestBed.inject(ListHeading);
}

const accountsOf = (...handles: string[]): SocialSection => ({
  network: 'bluesky',
  networkAccounts: [],
  profiles: socialProfiles([], 0),
  unreadCount: 0,
  entryCount: 0,
  saved: null,
  showsHandles: handles.length > 1,
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
  ];

  const NETWORK_TOTALS: UnreadTotals = {
    ...NO_UNREAD_TOTALS,
    networks: new Map([
      ['bluesky', 7],
      ['mastodon', 14],
    ]),
    networkProfiles: new Map([
      ['bluesky', 4],
      ['mastodon', 9],
    ]),
  };

  function networkHeading(unread: boolean, selected: Partial<Selection> = {}): ListHeading {
    const selection: Selection = {
      kind: 'network',
      id: null,
      unread,
      network: 'bluesky',
      ...selected,
    };
    return headingWith(selection, {
      subscriptions: signal(subscriptions),
      socialSections: signal(buildSocialSections(subscriptions, [], NETWORK_TOTALS)),
    });
  }

  it('is titled by the network', () => {
    expect(networkHeading(true).title()).toBe('Bluesky');
  });

  it("counts the unread of the network's feeds, excluded ones included", () => {
    expect(networkHeading(true).titleCount()).toEqual({ value: 7, counts: 'unread' });
  });

  it("counts every post of the network's feeds under All posts", () => {
    expect(networkHeading(false).titleCount()).toEqual({ value: 70, counts: 'items' });
  });

  it("titles a network's profile feeds as Feeds", () => {
    const heading = networkHeading(true, { network: 'mastodon', feedKind: 'profile' });

    expect(heading.title()).toBe('Mastodon · Feeds');
  });

  it("counts only the network's profile feeds when narrowed to them", () => {
    const selected = { network: 'mastodon', feedKind: 'profile' } as const;

    expect(networkHeading(true, selected).titleCount()).toEqual({ value: 9, counts: 'unread' });
  });
});

describe("ListHeading for a feed group's combined view", () => {
  const subscriptions: SubscriptionDto[] = [
    makeSubscription({
      id: 1,
      entryCount: 10,
      tags: [{ id: 5, name: 'News', color: null, icon: null, position: 0, parentId: null }],
    }),
    makeSubscription({
      id: 2,
      entryCount: 20,
      tags: [{ id: 6, name: 'Tech', color: null, icon: null, position: 1, parentId: null }],
    }),
    makeSubscription({ id: 3, entryCount: 300 }),
  ];

  const GROUP_TOTALS: UnreadTotals = { ...NO_UNREAD_TOTALS, groups: { tags: 4, feeds: 9 } };

  function groupHeading(unread: boolean, group: 'tags' | 'feeds'): ListHeading {
    return headingWith(
      { kind: 'group', id: null, unread, group },
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
function headingWith(selection: Selection, subscriptionsStore: object): ListHeading {
  TestBed.configureTestingModule({
    imports: [provideTranslocoTesting()],
    providers: [
      ListHeading,
      { provide: ReaderRouteState, useValue: { selection: signal(selection) } },
      { provide: SubscriptionsStore, useValue: subscriptionsStore },
      { provide: LanguageService, useValue: { lang: signal('en') } },
      { provide: EntriesStore, useValue: {} },
      { provide: RecommendationsService, useValue: {} },
      { provide: SavedSearchesStore, useValue: {} },
      { provide: ReadingLayoutService, useValue: { mode: signal('magazine') } },
    ],
  });
  return TestBed.inject(ListHeading);
}

describe('ListHeading for a child tag (#1736)', () => {
  const tagOf = (id: number, name: string, parentId: number | null): TagDto => ({
    id,
    name,
    color: null,
    icon: null,
    position: 0,
    parentId,
  });
  const nodeOf = (tag: TagDto, children: TagNode[] = []): TagNode => ({
    tag,
    subscriptions: [],
    children,
    unreadCount: 0,
    entryCount: 0,
  });

  it('finds a child tag that only appears inside its parent node', () => {
    const tree = [nodeOf(tagOf(1, 'Reading', null), [nodeOf(tagOf(2, 'Long reads', 1))])];
    const selection: Selection = { kind: 'tag', id: 2, unread: true };

    const heading = headingWith(selection, { tagNodeById: signal(tagNodesById(tree)) });

    expect(heading.selectedTag()?.name).toBe('Long reads');
  });
});
