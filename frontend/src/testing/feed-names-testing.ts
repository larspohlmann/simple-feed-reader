import { TestBed } from '@angular/core/testing';
import { EntryDto } from '../app/reader/models';
import { SubscriptionRows } from '../app/reader/state/subscription-rows';
import { makeSubscription } from '../app/reader/testing/subscription.factory';
import { anEntry } from './entry-testing';

/** Holds one unrenamed Bluesky Home, which every surface must name "Home@bsky". Call after configuring TestBed. */
export function holdUnrenamedHome(subscriptionId: number): void {
  TestBed.inject(SubscriptionRows).all.set([
    makeSubscription({
      id: subscriptionId,
      title: 'Home',
      social: { network: 'bluesky', accountId: 1, kind: 'home' },
    }),
  ]);
}

/** A post from that Home with no author, so the backend fell back to the feed's own title. */
export function authorlessHomePost(subscriptionId: number, over: Partial<EntryDto> = {}): EntryDto {
  return anEntry({ subscriptionId, subscriptionTitle: 'Home', source: 'Home', ...over });
}
