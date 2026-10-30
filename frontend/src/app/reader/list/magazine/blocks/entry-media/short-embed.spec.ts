import { autoplayingShortPlayer, mutedShortPlayer } from './short-embed';

describe('autoplayingShortPlayer', () => {
  it('starts the allow-listed player of a Short on load, scriptable from the reader', () => {
    expect(
      autoplayingShortPlayer(
        'https://www.youtube-nocookie.com/embed/87Ov3cS-xMs#shorts',
        'https://reader.test',
      ),
    ).toBe(
      'https://www.youtube-nocookie.com/embed/87Ov3cS-xMs?autoplay=1&enablejsapi=1&origin=https%3A%2F%2Freader.test#shorts',
    );
  });

  it('is null for a player the allow-list refuses', () => {
    expect(
      autoplayingShortPlayer('https://evil.test/embed/87Ov3cS-xMs#shorts', 'https://reader.test'),
    ).toBeNull();
    expect(
      autoplayingShortPlayer(
        'https://www.youtube-nocookie.com/embed/87Ov3cS-xMs?mute=1#shorts',
        'https://reader.test',
      ),
    ).toBeNull();
  });

  it('is null for an entry that is no Short', () => {
    expect(autoplayingShortPlayer(null, 'https://reader.test')).toBeNull();
  });
});

describe('mutedShortPlayer', () => {
  it('mutes a Short player and keeps it inline, for starting without a tap', () => {
    expect(
      mutedShortPlayer(
        'https://www.youtube-nocookie.com/embed/87Ov3cS-xMs?autoplay=1&enablejsapi=1&origin=https%3A%2F%2Freader.test#shorts',
      ),
    ).toBe(
      'https://www.youtube-nocookie.com/embed/87Ov3cS-xMs?autoplay=1&enablejsapi=1&origin=https%3A%2F%2Freader.test&mute=1&playsinline=1#shorts',
    );
  });
});
