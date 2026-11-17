import { networkList } from './network-list';

describe('networkList', () => {
  it('joins network names the way the language lists them', () => {
    expect(networkList(['bluesky', 'mastodon'], 'en')).toBe('Bluesky and Mastodon');
    expect(networkList(['bluesky', 'mastodon'], 'de')).toBe('Bluesky und Mastodon');
    expect(networkList(['bluesky', 'mastodon', 'lemmy'], 'en')).toBe(
      'Bluesky, Mastodon, and Lemmy',
    );
    expect(networkList(['mastodon'], 'fr')).toBe('Mastodon');
  });
});
