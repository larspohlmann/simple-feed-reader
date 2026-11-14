import { LINK_POST_NETWORKS, SOCIAL_NETWORKS, isSocialNetworkName } from './social-network';

describe('social networks', () => {
  it('knows Lemmy as a social network', () => {
    expect(SOCIAL_NETWORKS).toContain('lemmy');
    expect(isSocialNetworkName('lemmy')).toBe(true);
    expect(isSocialNetworkName('threads')).toBe(false);
  });

  it('takes link posts on Bluesky and Mastodon only', () => {
    expect(LINK_POST_NETWORKS).toEqual(['bluesky', 'mastodon']);
  });
});
