import { instanceOrigin, LinkFacts, OwnInstances, ownInstanceUrl } from './own-instance-url';

const HOME_M = 'https://m.home';
const HOME_L = 'https://l.home';

const BOTH: OwnInstances = {
  mastodon: HOME_M,
  lemmy: HOME_L,
  lemmyHosts: new Set(['lemmy.world']),
};
const NONE: OwnInstances = { mastodon: null, lemmy: null, lemmyHosts: new Set(['lemmy.world']) };

function authorize(address: string): string {
  return `${HOME_M}/authorize_interaction?uri=${encodeURIComponent(address)}`;
}

function lemmySearch(address: string): string {
  return `${HOME_L}/search?q=${encodeURIComponent(address)}&type=All`;
}

describe('instanceOrigin', () => {
  it('reduces a service url to its origin', () => {
    expect(instanceOrigin('https://home.example/')).toBe('https://home.example');
  });

  it.each(['not a url', '', 'ftp://home.example/'])('rejects %p', (serviceUrl) => {
    expect(instanceOrigin(serviceUrl)).toBeNull();
  });
});

const CASES: [string, LinkFacts, string][] = [
  ['https://lemmy.world/post/9', { homeUrl: 'https://l.home/post/42' }, 'https://l.home/post/42'],
  [
    'https://lemmy.world/post/9',
    { homeUrl: 'https://other.home/post/42' },
    lemmySearch('https://lemmy.world/post/9'),
  ],
  ['https://feddit.org/post/9', { network: 'lemmy' }, lemmySearch('https://feddit.org/post/9')],
  ['https://feddit.org/post/9', {}, 'https://feddit.org/post/9'],
  ['https://lemmy.world/comment/5', {}, lemmySearch('https://lemmy.world/comment/5')],
  ['https://lemmy.world/c/rust', {}, 'https://l.home/c/rust@lemmy.world'],
  ['https://lemmy.world/c/rust@programming.dev', {}, 'https://l.home/c/rust@programming.dev'],
  ['https://lemmy.world/u/alice', {}, 'https://l.home/u/alice@lemmy.world'],
  ['https://l.home/post/3', { network: 'lemmy' }, 'https://l.home/post/3'],
  ['https://lemmy.world/some/article', { network: 'lemmy' }, 'https://lemmy.world/some/article'],
  ['https://mastodon.social/@alice/1234', {}, authorize('https://mastodon.social/@alice/1234')],
  [
    'https://pixel.example/users/bob/statuses/77',
    {},
    authorize('https://pixel.example/users/bob/statuses/77'),
  ],
  ['https://medium.com/@author/some-slug-1a2b', {}, 'https://medium.com/@author/some-slug-1a2b'],
  ['https://medium.com/@author', {}, 'https://medium.com/@author'],
  [
    'https://mastodon.social/@alice',
    { mastodonLinkText: '@alice' },
    authorize('https://mastodon.social/@alice'),
  ],
  ['https://mastodon.social/tags/rust', { mastodonLinkText: '#rust' }, 'https://m.home/tags/rust'],
  [
    'https://mastodon.social/@Gargron',
    { network: 'mastodon' },
    authorize('https://mastodon.social/@Gargron'),
  ],
];

describe('ownInstanceUrl', () => {
  it.each(CASES)('moves %p with %p to %p', (address, facts, expected) => {
    expect(ownInstanceUrl(address, facts, BOTH)).toBe(expected);
  });

  it.each(CASES)('leaves %p with %p alone without instances', (address, facts) => {
    expect(ownInstanceUrl(address, facts, NONE)).toBe(address);
  });

  it('leaves a Mastodon status alone when only a Lemmy instance is known', () => {
    const lemmyOnly: OwnInstances = { ...BOTH, mastodon: null };

    expect(ownInstanceUrl('https://mastodon.social/@alice/1234', {}, lemmyOnly)).toBe(
      'https://mastodon.social/@alice/1234',
    );
  });

  it('leaves an address on the own Mastodon instance alone', () => {
    expect(ownInstanceUrl('https://M.HOME/@me/1', { network: 'mastodon' }, BOTH)).toBe(
      'https://M.HOME/@me/1',
    );
  });

  it('encodes the whole mention href', () => {
    const href = 'https://mastodon.social/@alice?x=1#y';

    expect(ownInstanceUrl(href, { mastodonLinkText: '@alice' }, BOTH)).toBe(authorize(href));
  });

  it('routes a users-path mention through authorize_interaction', () => {
    expect(
      ownInstanceUrl(
        'https://pixel.example/users/bob',
        { mastodonLinkText: '@bob@pixel.example' },
        BOTH,
      ),
    ).toBe(authorize('https://pixel.example/users/bob'));
  });

  it.each([
    ['https://medium.com/@author', 'medium.com/@author'],
    ['https://mastodon.social/@alice/123', '@alice'],
    ['https://mastodon.social/@alice', '#alice'],
    ['https://mastodon.social/tags/rust', '@rust'],
    ['https://m.home/@alice', '@alice'],
    ['https://m.home/tags/rust', '#rust'],
  ])('keeps the body rule for %p with text %p', (href, text) => {
    const expected = href === 'https://mastodon.social/@alice/123' ? authorize(href) : href;

    expect(ownInstanceUrl(href, { mastodonLinkText: text }, BOTH)).toBe(expected);
  });

  it.each(['nonsense', 'mailto:someone@example.com', '#comments'])('leaves %p alone', (address) => {
    expect(ownInstanceUrl(address, { network: 'mastodon', mastodonLinkText: '@alice' }, BOTH)).toBe(
      address,
    );
  });
});
