import { autoplayingShortPlayer, mutedShortPlayer } from './short-embed';

describe('autoplayingShortPlayer', () => {
  it('starts the allow-listed player of a Short on load', () => {
    expect(
      autoplayingShortPlayer('https://www.youtube-nocookie.com/embed/87Ov3cS-xMs#shorts'),
    ).toBe('https://www.youtube-nocookie.com/embed/87Ov3cS-xMs?autoplay=1#shorts');
  });

  it('is null for a player the allow-list refuses', () => {
    expect(autoplayingShortPlayer('https://evil.test/embed/87Ov3cS-xMs#shorts')).toBeNull();
    expect(
      autoplayingShortPlayer('https://www.youtube-nocookie.com/embed/87Ov3cS-xMs?mute=1#shorts'),
    ).toBeNull();
  });

  it('is null for an entry that is no Short', () => {
    expect(autoplayingShortPlayer(null)).toBeNull();
  });
});

describe('mutedShortPlayer', () => {
  it('mutes a Short player and keeps it inline, for starting without a tap', () => {
    expect(
      mutedShortPlayer('https://www.youtube-nocookie.com/embed/87Ov3cS-xMs?autoplay=1#shorts'),
    ).toBe(
      'https://www.youtube-nocookie.com/embed/87Ov3cS-xMs?autoplay=1&mute=1&playsinline=1#shorts',
    );
  });
});
