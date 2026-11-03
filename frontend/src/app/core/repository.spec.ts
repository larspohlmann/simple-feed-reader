import { REPOSITORY_URL, releasePageUrl } from './repository';

describe('releasePageUrl', () => {
  it.each(['v1.7.0', 'v1.7.1-dev.1'])('links the tag %s to its page', (version) => {
    expect(releasePageUrl(version)).toBe(`${REPOSITORY_URL}/releases/tag/${version}`);
  });

  it.each(['dev', 'v1.7.0-3-gabc1234', 'abc1234'])(
    'links %s, which names no tag, to the release list',
    (version) => {
      expect(releasePageUrl(version)).toBe(`${REPOSITORY_URL}/releases`);
    },
  );
});
