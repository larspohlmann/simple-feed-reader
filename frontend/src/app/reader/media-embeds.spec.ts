import { upgradeMediaEmbeds } from './media-embeds';

function host(html: string): HTMLElement {
  const element = document.createElement('div');
  element.innerHTML = html;
  upgradeMediaEmbeds(element);
  return element;
}

describe('upgradeMediaEmbeds', () => {
  it('replaces a YouTube link with a nocookie iframe', () => {
    const element = host(
      '<a href="https://www.youtube-nocookie.com/embed/aaaaaaaaaaa"><img src="p.jpg"></a>',
    );
    const frame = element.querySelector('iframe');

    expect(frame).not.toBeNull();
    expect(frame!.getAttribute('src')).toBe('https://www.youtube-nocookie.com/embed/aaaaaaaaaaa');
    expect(element.querySelector('a')).toBeNull();
  });

  it('applies the sandbox and referrer policy', () => {
    const element = host('<a href="https://www.youtube-nocookie.com/embed/aaaaaaaaaaa">x</a>');
    const frame = element.querySelector('iframe')!;

    expect(frame.getAttribute('sandbox')).toContain('allow-scripts');
    expect(frame.getAttribute('referrerpolicy')).toBe('strict-origin-when-cross-origin');
    expect(frame.getAttribute('loading')).toBe('lazy');
    expect(frame.getAttribute('allow') ?? '').not.toContain('autoplay');
  });

  it('replaces a SoundCloud player link', () => {
    const element = host(
      '<a href="https://w.soundcloud.com/player/?url=https%3A%2F%2Fapi.soundcloud.com%2Ftracks%2F2370150908">x</a>',
    );

    expect(element.querySelector('iframe')).not.toBeNull();
  });

  it('leaves an ordinary article link alone', () => {
    const element = host('<a href="https://example.test/story">Read this</a>');

    expect(element.querySelector('iframe')).toBeNull();
    expect(element.querySelector('a')).not.toBeNull();
  });

  it('leaves a link to a host that is not allow-listed', () => {
    const element = host('<a href="https://evil.test/embed/aaaaaaaaaaa">x</a>');

    expect(element.querySelector('iframe')).toBeNull();
  });

  it('rejects a look-alike host', () => {
    const element = host(
      '<a href="https://www.youtube-nocookie.com.evil.test/embed/aaaaaaaaaaa">x</a>',
    );

    expect(element.querySelector('iframe')).toBeNull();
  });

  it('is idempotent across repeated passes', () => {
    const element = document.createElement('div');
    element.innerHTML = '<a href="https://www.youtube-nocookie.com/embed/aaaaaaaaaaa">x</a>';
    upgradeMediaEmbeds(element);
    upgradeMediaEmbeds(element);

    expect(element.querySelectorAll('iframe').length).toBe(1);
  });

  it('replaces a Brightcove player link with a sandboxed iframe', () => {
    const element = host(
      '<a href="https://players.brightcove.net/665003303001/6tKQRAx7lu_default/index.html?videoId=6403736850112"><img src="p.jpg"></a>',
    );
    const frame = element.querySelector('iframe')!;

    expect(frame).not.toBeNull();
    expect(frame.getAttribute('src')).toBe(
      'https://players.brightcove.net/665003303001/6tKQRAx7lu_default/index.html?videoId=6403736850112',
    );
    expect(frame.getAttribute('sandbox')).toContain('allow-scripts');
  });

  it('leaves a Brightcove link that carries more than the video id', () => {
    const element = host(
      '<a href="https://players.brightcove.net/665003303001/6tKQRAx7lu_default/index.html?videoId=6403736850112&autoplay=1">x</a>',
    );

    expect(element.querySelector('iframe')).toBeNull();
  });

  it('replaces a Vimeo player link with a sandboxed iframe', () => {
    const element = host('<a href="https://player.vimeo.com/video/1226652197">Watch on Vimeo</a>');
    const frame = element.querySelector('iframe')!;

    expect(frame).not.toBeNull();
    expect(frame.getAttribute('src')).toBe('https://player.vimeo.com/video/1226652197');
    expect(frame.getAttribute('sandbox')).toContain('allow-scripts');
    expect(element.querySelector('a')).toBeNull();
  });

  it('replaces an unlisted Vimeo player link that carries a privacy hash', () => {
    const element = host('<a href="https://player.vimeo.com/video/76979871?h=8272103f6e">x</a>');

    expect(element.querySelector('iframe')!.getAttribute('src')).toBe(
      'https://player.vimeo.com/video/76979871?h=8272103f6e',
    );
  });

  it('leaves a bare vimeo.com page link (not the player URL) alone', () => {
    const element = host('<a href="https://vimeo.com/1226652197">x</a>');

    expect(element.querySelector('iframe')).toBeNull();
    expect(element.querySelector('a')).not.toBeNull();
  });

  it('rejects a Vimeo look-alike host', () => {
    const element = host('<a href="https://player.vimeo.com.evil.test/video/1226652197">x</a>');

    expect(element.querySelector('iframe')).toBeNull();
  });

  it('replaces a Spotify playlist link with a sandboxed iframe', () => {
    const element = host(
      '<a href="https://open.spotify.com/embed/playlist/27uRYdAHvcKADidfnR8BN4">Listen on Spotify</a>',
    );
    const frame = element.querySelector('iframe')!;

    expect(frame).not.toBeNull();
    expect(frame.getAttribute('src')).toBe(
      'https://open.spotify.com/embed/playlist/27uRYdAHvcKADidfnR8BN4',
    );
    expect(frame.getAttribute('sandbox')).toContain('allow-scripts');
    expect(element.querySelector('a')).toBeNull();
  });

  it('rejects a Spotify look-alike host', () => {
    const element = host(
      '<a href="https://open.spotify.com.evil.test/embed/playlist/27uRYdAHvcKADidfnR8BN4">x</a>',
    );

    expect(element.querySelector('iframe')).toBeNull();
  });

  it('replaces a Dailymotion link with a sandboxed iframe', () => {
    const element = host(
      '<a href="https://www.dailymotion.com/embed/video/x7tgad0">Watch on Dailymotion</a>',
    );
    const frame = element.querySelector('iframe')!;

    expect(frame).not.toBeNull();
    expect(frame.getAttribute('src')).toBe('https://www.dailymotion.com/embed/video/x7tgad0');
    expect(frame.getAttribute('sandbox')).toContain('allow-scripts');
    expect(element.querySelector('a')).toBeNull();
  });

  it('rejects a Dailymotion look-alike host', () => {
    const element = host(
      '<a href="https://www.dailymotion.com.evil.test/embed/video/x7tgad0">x</a>',
    );

    expect(element.querySelector('iframe')).toBeNull();
  });

  it('gives a Spotify playlist a tall box, not the 16:9 video frame', () => {
    const element = host(
      '<a href="https://open.spotify.com/embed/playlist/27uRYdAHvcKADidfnR8BN4">x</a>',
    );

    expect(element.querySelector('.reader-embed--tall')).not.toBeNull();
  });

  it('keeps the default frame for a single Spotify track', () => {
    const element = host(
      '<a href="https://open.spotify.com/embed/track/4cOdK2wGLETKBW3PvgPWqT">x</a>',
    );

    expect(element.querySelector('.reader-embed')).not.toBeNull();
    expect(element.querySelector('.reader-embed--tall')).toBeNull();
  });

  it('gives a YouTube Short a portrait box and keeps the fragment on the iframe', () => {
    const element = host(
      '<a href="https://www.youtube-nocookie.com/embed/GhUuOxrCato#shorts">x</a>',
    );

    expect(element.querySelector('div.reader-embed.reader-embed--portrait')).not.toBeNull();
    expect(element.querySelector('iframe')?.getAttribute('src')).toBe(
      'https://www.youtube-nocookie.com/embed/GhUuOxrCato#shorts',
    );
  });

  it('keeps the landscape box for an ordinary YouTube embed', () => {
    const element = host('<a href="https://www.youtube-nocookie.com/embed/GhUuOxrCato">x</a>');

    expect(element.querySelector('.reader-embed')).not.toBeNull();
    expect(element.querySelector('.reader-embed--portrait')).toBeNull();
  });

  it.each([
    'https://w.soundcloud.com/player/?url=https%3A%2F%2Fapi.soundcloud.com%2Ftracks%2F2370150908',
    'https://open.spotify.com/embed/track/4cOdK2wGLETKBW3PvgPWqT',
  ])('marks the audio player %s as audio', (url) => {
    const element = host(`<a href="${url}">x</a>`);

    expect(element.querySelector('div.reader-embed.reader-embed--audio')).not.toBeNull();
  });

  it('marks a Spotify collection as audio and keeps its tall box', () => {
    const element = host(
      '<a href="https://open.spotify.com/embed/playlist/27uRYdAHvcKADidfnR8BN4">x</a>',
    );

    expect(element.querySelector('div')!.className).toBe(
      'reader-embed reader-embed--tall reader-embed--audio',
    );
  });

  it('does not mark a YouTube video as audio', () => {
    const element = host('<a href="https://www.youtube-nocookie.com/embed/GhUuOxrCato">x</a>');

    expect(element.querySelector('.reader-embed')).not.toBeNull();
    expect(element.querySelector('.reader-embed--audio')).toBeNull();
  });
});
