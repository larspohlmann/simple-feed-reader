import { EntrySocialDto, likeableSocial } from './entry-social';

describe('likeableSocial', () => {
  const social = (canLike: boolean): EntrySocialDto => ({
    network: 'bluesky',
    isLiked: false,
    canLike,
    isOwn: false,
  });

  it('returns the social data when the reader can like', () => {
    const likeable = social(true);
    expect(likeableSocial({ social: likeable })).toBe(likeable);
  });

  it('returns null when the reader cannot like', () => {
    expect(likeableSocial({ social: social(false) })).toBeNull();
  });

  it('returns null for an entry without social data', () => {
    expect(likeableSocial({ social: null })).toBeNull();
  });
});
