import { provideHttpClient } from '@angular/common/http';
import { provideHttpClientTesting } from '@angular/common/http/testing';
import { TestBed } from '@angular/core/testing';
import { API_BASE_URL } from '../../core/api';
import { anEntry } from '../../../testing/entry-testing';
import { provideTranslocoTesting } from '../../../testing/transloco-testing';
import { SubscriptionsStore } from '../state/subscriptions.store';
import { entryLinkNetwork, entryOpenUrl } from './entry-link-facts';
import { OwnInstanceLinks } from './own-instance-links.service';

const LEMMY_POST = 'https://lemmy.world/post/9';

describe('entry link facts', () => {
  let links: OwnInstanceLinks;

  beforeEach(() => {
    TestBed.configureTestingModule({
      imports: [provideTranslocoTesting()],
      providers: [
        provideHttpClient(),
        provideHttpClientTesting(),
        { provide: API_BASE_URL, useValue: 'https://api.test' },
      ],
    });
    const store = TestBed.inject(SubscriptionsStore);
    store.socialAccounts.set([
      { id: 1, network: 'lemmy', handle: 'me', status: 'connected', serviceUrl: 'https://l.home' },
      {
        id: 2,
        network: 'mastodon',
        handle: 'me',
        status: 'connected',
        serviceUrl: 'https://m.home',
      },
    ]);
    store.lemmyHosts.set(['lemmy.world']);
    links = TestBed.inject(OwnInstanceLinks);
  });

  it('names Lemmy for an entry with a community or a home address', () => {
    expect(
      entryLinkNetwork(anEntry({ community: { name: 'rust', url: 'https://lemmy.world/c/rust' } })),
    ).toBe('lemmy');
    expect(entryLinkNetwork(anEntry({ discussionHomeUrl: 'https://l.home/post/42' }))).toBe(
      'lemmy',
    );
  });

  it('names the social network of a post and nothing for an article', () => {
    const social = { network: 'mastodon' as const, isLiked: false, canLike: true, isOwn: false };

    expect(entryLinkNetwork(anEntry({ social }))).toBe('mastodon');
    expect(entryLinkNetwork(anEntry())).toBeNull();
  });

  it('opens a Lemmy account post at its home address', () => {
    const entry = anEntry({
      discussionUrl: LEMMY_POST,
      discussionHomeUrl: 'https://l.home/post/42',
    });

    expect(entryOpenUrl(entry, links)).toBe('https://l.home/post/42');
  });

  it('opens a Lemmy RSS post through the home search page', () => {
    const entry = anEntry({ discussionUrl: LEMMY_POST });

    expect(entryOpenUrl(entry, links)).toBe(
      `https://l.home/search?q=${encodeURIComponent(LEMMY_POST)}&type=All`,
    );
  });

  it('opens a Mastodon post through authorize_interaction as before', () => {
    const social = { network: 'mastodon' as const, isLiked: false, canLike: true, isOwn: false };
    const entry = anEntry({ url: 'https://mastodon.social/@alice/1', social });

    expect(entryOpenUrl(entry, links)).toBe(
      `https://m.home/authorize_interaction?uri=${encodeURIComponent('https://mastodon.social/@alice/1')}`,
    );
  });

  it('opens an article as it is and has nothing to open without an address', () => {
    expect(entryOpenUrl(anEntry({ url: 'https://example.com/a' }), links)).toBe(
      'https://example.com/a',
    );
    expect(entryOpenUrl(anEntry(), links)).toBeNull();
  });
});
