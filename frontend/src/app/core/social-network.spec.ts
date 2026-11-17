import { SOCIAL_NETWORKS, isSocialNetworkName } from './social-network';

describe('social networks', () => {
  it('knows Lemmy as a social network', () => {
    expect(SOCIAL_NETWORKS).toContain('lemmy');
    expect(isSocialNetworkName('lemmy')).toBe(true);
    expect(isSocialNetworkName('threads')).toBe(false);
  });
});
