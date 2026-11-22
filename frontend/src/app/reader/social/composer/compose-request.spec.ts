import { ComposeRequest, crosspostNetworks } from './compose-request';

describe('crosspostNetworks', () => {
  const link: ComposeRequest = {
    kind: 'link',
    network: 'mastodon',
    url: 'https://example.com/a',
    title: 'A',
    quote: null,
  };

  it('lets a link go to every network', () => {
    expect(crosspostNetworks(link)).toEqual(['bluesky', 'mastodon', 'lemmy']);
  });

  it('lets a text post go to Bluesky and Mastodon, never Lemmy', () => {
    expect(crosspostNetworks({ kind: 'post', network: 'bluesky' })).toEqual([
      'bluesky',
      'mastodon',
    ]);
  });

  it.each(['reply', 'quote'] as const)('lets a %s go nowhere else', (kind) => {
    expect(crosspostNetworks({ kind, entryId: 1, network: 'mastodon', address: null })).toEqual([]);
  });
});
