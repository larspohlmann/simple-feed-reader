import { provideHttpClient } from '@angular/common/http';
import { provideHttpClientTesting } from '@angular/common/http/testing';
import { TestBed } from '@angular/core/testing';
import { API_BASE_URL } from '../../core/api';
import { provideTranslocoTesting } from '../../../testing/transloco-testing';
import { SocialAccountRefDto } from '../social-feed';
import { SubscriptionsStore } from '../state/subscriptions.store';
import { OwnInstanceLinks } from './own-instance-links.service';

const ADDRESS = 'https://mastodon.social/@Gargron';

function account(
  id: number,
  network: SocialAccountRefDto['network'],
  serviceUrl: string,
): SocialAccountRefDto {
  return { id, network, handle: `handle${id}`, status: 'connected', serviceUrl };
}

describe('OwnInstanceLinks', () => {
  let links: OwnInstanceLinks;
  let store: SubscriptionsStore;

  beforeEach(() => {
    TestBed.configureTestingModule({
      imports: [provideTranslocoTesting()],
      providers: [
        provideHttpClient(),
        provideHttpClientTesting(),
        { provide: API_BASE_URL, useValue: 'https://api.test' },
      ],
    });
    store = TestBed.inject(SubscriptionsStore);
    links = TestBed.inject(OwnInstanceLinks);
  });

  it('uses the first Mastodon account instance', () => {
    store.socialAccounts.set([
      account(1, 'bluesky', 'https://bsky.social'),
      account(2, 'mastodon', 'https://home.example/'),
      account(3, 'mastodon', 'https://other.example'),
    ]);

    expect(links.instances().mastodon).toBe('https://home.example');
    expect(links.open(ADDRESS, { network: 'mastodon' })).toBe(
      'https://home.example/authorize_interaction?uri=https%3A%2F%2Fmastodon.social%2F%40Gargron',
    );
  });

  it('uses the first Lemmy account instance and the known Lemmy hosts', () => {
    store.socialAccounts.set([
      account(4, 'lemmy', 'https://l.home/'),
      account(5, 'lemmy', 'https://second.home'),
    ]);
    store.lemmyHosts.set(['lemmy.world']);

    expect(links.instances().lemmy).toBe('https://l.home');
    expect(links.instances().lemmyHosts.has('lemmy.world')).toBe(true);
    expect(links.open('https://lemmy.world/c/rust')).toBe('https://l.home/c/rust@lemmy.world');
  });

  it('forwards the stored home address', () => {
    store.socialAccounts.set([account(4, 'lemmy', 'https://l.home/')]);

    expect(links.open('https://lemmy.world/post/9', { homeUrl: 'https://l.home/post/42' })).toBe(
      'https://l.home/post/42',
    );
  });

  it('leaves other networks and unknown networks alone', () => {
    store.socialAccounts.set([account(2, 'mastodon', 'https://home.example/')]);

    expect(links.open(ADDRESS, { network: 'bluesky' })).toBe(ADDRESS);
    expect(links.open(ADDRESS)).toBe(ADDRESS);
  });

  it('leaves the address alone without a Mastodon account', () => {
    store.socialAccounts.set([account(1, 'bluesky', 'https://bsky.social')]);

    expect(links.instances().mastodon).toBeNull();
    expect(links.open(ADDRESS, { network: 'mastodon' })).toBe(ADDRESS);
  });
});
