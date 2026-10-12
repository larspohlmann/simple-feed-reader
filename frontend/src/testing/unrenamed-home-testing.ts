import { TestBed } from '@angular/core/testing';
import { EntryDto, SubscriptionDto } from '../app/reader/models';
import { SubscriptionRows } from '../app/reader/state/subscription-rows';
import { makeSubscription } from '../app/reader/testing/subscription.factory';
import { anEntry } from './entry-testing';

/** Holds one unrenamed Bluesky Home, which every surface must name "Home@bsky". Call after configuring TestBed. */
export function holdUnrenamedHome(subscriptionId: number): void {
  TestBed.inject(SubscriptionRows).stored.set([
    makeSubscription({
      id: subscriptionId,
      title: 'Home',
      social: { network: 'bluesky', accountId: 1, kind: 'home' },
    }),
  ]);
}

/** That Home as every surface receives it: titled for display. */
export function heldUnrenamedHome(subscriptionId: number): SubscriptionDto {
  holdUnrenamedHome(subscriptionId);
  const row = TestBed.inject(SubscriptionRows).find(subscriptionId);
  if (row === null) throw new Error(`No subscription ${subscriptionId} is held.`);
  return row;
}

/** A post from that Home with no author, so no one but the feed is named. */
export function authorlessHomePost(subscriptionId: number, over: Partial<EntryDto> = {}): EntryDto {
  return anEntry({ subscriptionId, source: 'Home', ...over });
}
