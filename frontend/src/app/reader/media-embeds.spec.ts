import { upgradeMediaEmbeds } from './media-embeds';

function host(html: string): HTMLElement {
  const el = document.createElement('div');
  el.innerHTML = html;
  upgradeMediaEmbeds(el);
  return el;
}

describe('upgradeMediaEmbeds', () => {
  it('replaces a YouTube link with a nocookie iframe', () => {
    const el = host(
      '<a href="https://www.youtube-nocookie.com/embed/aaaaaaaaaaa"><img src="p.jpg"></a>',
    );
    const frame = el.querySelector('iframe');

    expect(frame).not.toBeNull();
    expect(frame!.getAttribute('src')).toBe('https://www.youtube-nocookie.com/embed/aaaaaaaaaaa');
    expect(el.querySelector('a')).toBeNull();
  });

  it('applies the sandbox and referrer policy', () => {
    const el = host('<a href="https://www.youtube-nocookie.com/embed/aaaaaaaaaaa">x</a>');
    const frame = el.querySelector('iframe')!;

    expect(frame.getAttribute('sandbox')).toContain('allow-scripts');
    expect(frame.getAttribute('referrerpolicy')).toBe('strict-origin-when-cross-origin');
    expect(frame.getAttribute('loading')).toBe('lazy');
    expect(frame.getAttribute('allow') ?? '').not.toContain('autoplay');
  });

  it('replaces a SoundCloud player link', () => {
    const el = host(
      '<a href="https://w.soundcloud.com/player/?url=https%3A%2F%2Fapi.soundcloud.com%2Ftracks%2F2370150908">x</a>',
    );

    expect(el.querySelector('iframe')).not.toBeNull();
  });

  it('leaves an ordinary article link alone', () => {
    const el = host('<a href="https://example.test/story">Read this</a>');

    expect(el.querySelector('iframe')).toBeNull();
    expect(el.querySelector('a')).not.toBeNull();
  });

  it('leaves a link to a host that is not allow-listed', () => {
    const el = host('<a href="https://evil.test/embed/aaaaaaaaaaa">x</a>');

    expect(el.querySelector('iframe')).toBeNull();
  });

  it('rejects a look-alike host', () => {
    const el = host('<a href="https://www.youtube-nocookie.com.evil.test/embed/aaaaaaaaaaa">x</a>');

    expect(el.querySelector('iframe')).toBeNull();
  });

  it('is idempotent across repeated passes', () => {
    const el = document.createElement('div');
    el.innerHTML = '<a href="https://www.youtube-nocookie.com/embed/aaaaaaaaaaa">x</a>';
    upgradeMediaEmbeds(el);
    upgradeMediaEmbeds(el);

    expect(el.querySelectorAll('iframe').length).toBe(1);
  });

  it('replaces a Brightcove player link with a sandboxed iframe', () => {
    const el = host(
      '<a href="https://players.brightcove.net/665003303001/6tKQRAx7lu_default/index.html?videoId=6403736850112"><img src="p.jpg"></a>',
    );
    const frame = el.querySelector('iframe')!;

    expect(frame).not.toBeNull();
    expect(frame.getAttribute('src')).toBe(
      'https://players.brightcove.net/665003303001/6tKQRAx7lu_default/index.html?videoId=6403736850112',
    );
    expect(frame.getAttribute('sandbox')).toContain('allow-scripts');
  });

  it('leaves a Brightcove link that carries more than the video id', () => {
    const el = host(
      '<a href="https://players.brightcove.net/665003303001/6tKQRAx7lu_default/index.html?videoId=6403736850112&autoplay=1">x</a>',
    );

    expect(el.querySelector('iframe')).toBeNull();
  });

  it('replaces a Vimeo player link with a sandboxed iframe', () => {
    const el = host('<a href="https://player.vimeo.com/video/1226652197">Watch on Vimeo</a>');
    const frame = el.querySelector('iframe')!;

    expect(frame).not.toBeNull();
    expect(frame.getAttribute('src')).toBe('https://player.vimeo.com/video/1226652197');
    expect(frame.getAttribute('sandbox')).toContain('allow-scripts');
    expect(el.querySelector('a')).toBeNull();
  });

  it('replaces an unlisted Vimeo player link that carries a privacy hash', () => {
    const el = host('<a href="https://player.vimeo.com/video/76979871?h=8272103f6e">x</a>');

    expect(el.querySelector('iframe')!.getAttribute('src')).toBe(
      'https://player.vimeo.com/video/76979871?h=8272103f6e',
    );
  });

  it('leaves a bare vimeo.com page link (not the player URL) alone', () => {
    const el = host('<a href="https://vimeo.com/1226652197">x</a>');

    expect(el.querySelector('iframe')).toBeNull();
    expect(el.querySelector('a')).not.toBeNull();
  });

  it('rejects a Vimeo look-alike host', () => {
    const el = host('<a href="https://player.vimeo.com.evil.test/video/1226652197">x</a>');

    expect(el.querySelector('iframe')).toBeNull();
  });

  it('replaces a Spotify playlist link with a sandboxed iframe', () => {
    const el = host(
      '<a href="https://open.spotify.com/embed/playlist/27uRYdAHvcKADidfnR8BN4">Listen on Spotify</a>',
    );
    const frame = el.querySelector('iframe')!;

    expect(frame).not.toBeNull();
    expect(frame.getAttribute('src')).toBe(
      'https://open.spotify.com/embed/playlist/27uRYdAHvcKADidfnR8BN4',
    );
    expect(frame.getAttribute('sandbox')).toContain('allow-scripts');
    expect(el.querySelector('a')).toBeNull();
  });

  it('rejects a Spotify look-alike host', () => {
    const el = host(
      '<a href="https://open.spotify.com.evil.test/embed/playlist/27uRYdAHvcKADidfnR8BN4">x</a>',
    );

    expect(el.querySelector('iframe')).toBeNull();
  });

  it('replaces a Dailymotion link with a sandboxed iframe', () => {
    const el = host(
      '<a href="https://www.dailymotion.com/embed/video/x7tgad0">Watch on Dailymotion</a>',
    );
    const frame = el.querySelector('iframe')!;

    expect(frame).not.toBeNull();
    expect(frame.getAttribute('src')).toBe('https://www.dailymotion.com/embed/video/x7tgad0');
    expect(frame.getAttribute('sandbox')).toContain('allow-scripts');
    expect(el.querySelector('a')).toBeNull();
  });

  it('rejects a Dailymotion look-alike host', () => {
    const el = host('<a href="https://www.dailymotion.com.evil.test/embed/video/x7tgad0">x</a>');

    expect(el.querySelector('iframe')).toBeNull();
  });

  it('gives a Spotify playlist a tall box, not the 16:9 video frame', () => {
    const el = host(
      '<a href="https://open.spotify.com/embed/playlist/27uRYdAHvcKADidfnR8BN4">x</a>',
    );

    expect(el.querySelector('.reader-embed--tall')).not.toBeNull();
  });

  it('keeps the default frame for a single Spotify track', () => {
    const el = host('<a href="https://open.spotify.com/embed/track/4cOdK2wGLETKBW3PvgPWqT">x</a>');

    expect(el.querySelector('.reader-embed')).not.toBeNull();
    expect(el.querySelector('.reader-embed--tall')).toBeNull();
  });
});
