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
});
