import { instanceOrigin, ownInstanceBodyUrl, ownInstancePostUrl } from './own-instance-url';

const HOME = 'https://home.example';

describe('instanceOrigin', () => {
  it('reduces a service url to its origin', () => {
    expect(instanceOrigin('https://home.example/')).toBe(HOME);
  });

  it.each(['not a url', '', 'ftp://home.example/'])('rejects %p', (serviceUrl) => {
    expect(instanceOrigin(serviceUrl)).toBeNull();
  });
});

describe('ownInstancePostUrl', () => {
  it('routes a remote address through authorize_interaction', () => {
    expect(ownInstancePostUrl('https://mastodon.social/@Gargron', HOME)).toBe(
      'https://home.example/authorize_interaction?uri=https%3A%2F%2Fmastodon.social%2F%40Gargron',
    );
  });

  it('leaves the address alone without an instance', () => {
    expect(ownInstancePostUrl('https://mastodon.social/@Gargron', null)).toBe(
      'https://mastodon.social/@Gargron',
    );
  });

  it('leaves an address on the own instance alone', () => {
    expect(ownInstancePostUrl('https://HOME.example/@me/1', HOME)).toBe(
      'https://HOME.example/@me/1',
    );
  });

  it('leaves an unparsable address alone', () => {
    expect(ownInstancePostUrl('nonsense', HOME)).toBe('nonsense');
  });
});

describe('ownInstanceBodyUrl', () => {
  it('moves a hashtag to the own instance', () => {
    expect(ownInstanceBodyUrl('https://mastodon.social/tags/rust', '#rust', HOME)).toBe(
      'https://home.example/tags/rust',
    );
  });

  it('routes a mention through authorize_interaction', () => {
    expect(ownInstanceBodyUrl('https://mastodon.social/@alice', '@alice', HOME)).toBe(
      'https://home.example/authorize_interaction?uri=https%3A%2F%2Fmastodon.social%2F%40alice',
    );
  });

  it('routes a users-path mention through authorize_interaction', () => {
    expect(ownInstanceBodyUrl('https://pixel.example/users/bob', '@bob@pixel.example', HOME)).toBe(
      'https://home.example/authorize_interaction?uri=https%3A%2F%2Fpixel.example%2Fusers%2Fbob',
    );
  });

  it('encodes the whole href', () => {
    const href = 'https://mastodon.social/@alice?x=1#y';

    expect(ownInstanceBodyUrl(href, '@alice', HOME)).toBe(
      `https://home.example/authorize_interaction?uri=${encodeURIComponent(href)}`,
    );
  });

  it.each([
    ['https://medium.com/@author', 'medium.com/@author'],
    ['https://mastodon.social/@alice/123', 'https://mastodon.social/@alice/123'],
    ['https://mastodon.social/@alice/123', '@alice'],
    ['https://mastodon.social/@alice', '#alice'],
    ['https://mastodon.social/tags/rust', '@rust'],
    ['https://home.example/@alice', '@alice'],
    ['https://home.example/tags/rust', '#rust'],
    ['nonsense', '@alice'],
  ])('leaves %p with text %p unchanged', (href, text) => {
    expect(ownInstanceBodyUrl(href, text, HOME)).toBe(href);
  });

  it('leaves everything alone without an instance', () => {
    expect(ownInstanceBodyUrl('https://mastodon.social/tags/rust', '#rust', null)).toBe(
      'https://mastodon.social/tags/rust',
    );
  });
});
