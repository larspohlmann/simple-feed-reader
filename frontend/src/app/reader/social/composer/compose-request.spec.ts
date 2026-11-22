import { ComposeRequest, crossposts } from './compose-request';

describe('crossposts', () => {
  const link: ComposeRequest = {
    kind: 'link',
    network: 'mastodon',
    url: 'https://example.com/a',
    title: 'A',
    quote: null,
  };

  it('is true for a link request', () => {
    expect(crossposts(link)).toBe(true);
  });

  it.each(['reply', 'quote'] as const)('is false for a %s request', (kind) => {
    expect(crossposts({ kind, entryId: 1, network: 'mastodon', address: null })).toBe(false);
  });
});
