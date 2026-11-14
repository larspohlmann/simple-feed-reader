import { signal } from '@angular/core';
import { TestBed } from '@angular/core/testing';
import { SocialAccountRefDto } from '../../social-feed';
import { SubscriptionsStore } from '../../state/subscriptions.store';
import { PostNetworks } from './post-networks.service';

function postNetworksOf(accounts: SocialAccountRefDto[]): PostNetworks {
  TestBed.configureTestingModule({
    providers: [
      { provide: SubscriptionsStore, useValue: { socialAccounts: signal(accounts).asReadonly() } },
    ],
  });
  return TestBed.inject(PostNetworks);
}

describe('PostNetworks', () => {
  it('offers each network with a connected account, Bluesky first, with its handles', () => {
    const networks = postNetworksOf([
      { id: 2, network: 'mastodon', handle: '@bob@mastodon.example', status: 'connected' },
      { id: 1, network: 'bluesky', handle: '@alice.bsky.social', status: 'connected' },
      { id: 3, network: 'bluesky', handle: '@carol.bsky.social', status: 'needs_reconnect' },
    ]);

    expect(networks.connected()).toEqual([
      { network: 'bluesky', handles: ['@alice.bsky.social'] },
      { network: 'mastodon', handles: ['@bob@mastodon.example'] },
    ]);
  });

  it('adds no post network for a connected Lemmy account', () => {
    const networks = postNetworksOf([
      { id: 7, network: 'lemmy', handle: 'lars@lemmy.example', status: 'connected' },
    ]);

    expect(networks.connected()).toEqual([]);
  });
});
