import { EntrySocialDto, isLikedAnyWay, likeableSocial } from './entry-social';

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

describe('isLikedAnyWay (#2587)', () => {
  const social = (isLiked: boolean, canLike: boolean): EntrySocialDto => ({
    network: 'mastodon',
    isLiked,
    canLike,
    isOwn: false,
  });
  const unliked = { social: null, isLiked: false, myVote: null };

  it('holds a local like, a network like the reader can take back, and an up-vote', () => {
    expect(isLikedAnyWay({ ...unliked, isLiked: true })).toBe(true);
    expect(isLikedAnyWay({ ...unliked, social: social(true, true) })).toBe(true);
    expect(isLikedAnyWay({ ...unliked, myVote: 1 })).toBe(true);
  });

  it('holds neither a network like the reader cannot reach, nor a vote short of up', () => {
    expect(isLikedAnyWay(unliked)).toBe(false);
    expect(isLikedAnyWay({ ...unliked, social: social(true, false) })).toBe(false);
    expect(isLikedAnyWay({ ...unliked, myVote: 0 })).toBe(false);
    expect(isLikedAnyWay({ ...unliked, myVote: -1 })).toBe(false);
  });
});
