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
      { provide: ReadingLayoutService, useValue: {} },
    ],
  });
  return TestBed.inject(ListHeading).title();
}

const accountsOf = (...handles: string[]): SocialSection => ({
  network: 'bluesky',
  people: [],
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
