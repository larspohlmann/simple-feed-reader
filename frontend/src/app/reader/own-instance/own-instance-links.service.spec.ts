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

    expect(links.instance()).toBe('https://home.example');
    expect(links.open(ADDRESS, 'mastodon')).toBe(
      'https://home.example/authorize_interaction?uri=https%3A%2F%2Fmastodon.social%2F%40Gargron',
    );
  });

  it('leaves other networks and unknown networks alone', () => {
    store.socialAccounts.set([account(2, 'mastodon', 'https://home.example/')]);

    expect(links.open(ADDRESS, 'bluesky')).toBe(ADDRESS);
    expect(links.open(ADDRESS, null)).toBe(ADDRESS);
  });

  it('leaves the address alone without a Mastodon account', () => {
    store.socialAccounts.set([account(1, 'bluesky', 'https://bsky.social')]);

    expect(links.instance()).toBeNull();
    expect(links.open(ADDRESS, 'mastodon')).toBe(ADDRESS);
  });
});
