import { AVATAR_TONE_COUNT, avatarToneOf } from './avatar-tone';

describe('avatarToneOf', () => {
  it('gives the same seed the same tone every time', () => {
    expect(avatarToneOf('atrupar.com')).toBe(avatarToneOf('atrupar.com'));
  });

  it('keeps the tone inside the palette', () => {
    for (const seed of ['', 'a', 'atrupar.com', 'kai@scholar.social', '日本語']) {
      expect(avatarToneOf(seed)).toBeGreaterThanOrEqual(0);
      expect(avatarToneOf(seed)).toBeLessThan(AVATAR_TONE_COUNT);
    }
  });

  it('tells different seeds apart', () => {
    expect(avatarToneOf('a')).not.toBe(avatarToneOf('b'));
    expect(new Set(['a', 'b', 'c', 'd'].map(avatarToneOf)).size).toBe(AVATAR_TONE_COUNT);
  });
});
