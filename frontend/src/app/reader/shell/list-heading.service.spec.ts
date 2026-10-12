import { signal } from '@angular/core';
import { TestBed } from '@angular/core/testing';
import { provideTranslocoTesting } from '../../../testing/transloco-testing';
import { LanguageService } from '../../core/i18n/language.service';
import { SubscriptionDto } from '../models';
import { Selection } from '../query/query';
import { ReadingLayoutService } from '../reading-layout.service';
import { EntriesStore } from '../state/entries.store';
import { RecommendationsService } from '../state/recommendations.service';
import { SavedSearchesStore } from '../state/saved-searches.store';
import { SocialSection } from '../state/social-sections';
import { SubscriptionsStore } from '../state/subscriptions.store';
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
        useValue: { subscriptions: signal([subscription]), socialSections: signal(sections) },
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
  people: [],
  unreadCount: 0,
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
  ];

  function networkHeading(unread: boolean): ListHeading {
    const selection: Selection = { kind: 'network', id: null, unread, network: 'bluesky' };
    TestBed.configureTestingModule({
      imports: [provideTranslocoTesting()],
      providers: [
        ListHeading,
        { provide: ReaderRouteState, useValue: { selection: signal(selection) } },
        {
          provide: SubscriptionsStore,
          useValue: { subscriptions: signal(subscriptions), socialSections: signal([]) },
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

  it('is titled by the network', () => {
    expect(networkHeading(true).title()).toBe('Bluesky');
  });

  it("counts the unread of the network's feeds, excluded ones included", () => {
    expect(networkHeading(true).titleCount()).toEqual({ value: 7, counts: 'unread' });
  });

  it("counts every post of the network's feeds under All posts", () => {
    expect(networkHeading(false).titleCount()).toEqual({ value: 70, counts: 'items' });
  });
});
