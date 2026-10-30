import { avatarThumbnailUrl } from './avatar-thumbnail';

describe('avatarThumbnailUrl', () => {
  it("swaps Bluesky's full avatar for its 128 px thumbnail", () => {
    expect(
      avatarThumbnailUrl('https://cdn.bsky.app/img/avatar/plain/did:plc:abc/bafkrei@jpeg'),
    ).toBe('https://cdn.bsky.app/img/avatar_thumbnail/plain/did:plc:abc/bafkrei@jpeg');
  });

  it('leaves other avatars alone', () => {
    for (const url of [
      'https://cdn.bsky.app/img/avatar_thumbnail/plain/did:plc:abc/bafkrei@jpeg',
      'https://cdn.bsky.app/img/feed_fullsize/plain/did:plc:abc/bafkrei@jpeg',
      'https://files.mastodon.social/accounts/avatars/1/original/a.png',
      'https://example.com/img/avatar/plain/x.jpg',
    ]) {
      expect(avatarThumbnailUrl(url)).toBe(url);
    }
  });

  it('passes a missing avatar through', () => {
    expect(avatarThumbnailUrl(null)).toBeNull();
  });
});
