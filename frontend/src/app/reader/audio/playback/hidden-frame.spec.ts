import { EMBED_FRAME_ATTRIBUTES } from '../../media/media-embeds';
import { hiddenPlaybackFrame, hiddenPlaybackHost } from './hidden-frame';

describe('hiddenPlaybackFrame', () => {
  it('builds a detached, eagerly loading provider frame that may autoplay', () => {
    const frame = hiddenPlaybackFrame('https://www.youtube-nocookie.com/embed/abc', 'A video');

    expect(frame.isConnected).toBe(false);
    expect(frame.getAttribute('src')).toBe('https://www.youtube-nocookie.com/embed/abc');
    expect(frame.getAttribute('title')).toBe('A video');
    expect(frame.getAttribute('allow')).toBe('autoplay');
    expect(frame.getAttribute('loading')).toBe('eager');
    expect(frame.getAttribute('tabindex')).toBe('-1');
  });

  it('carries every embed security attribute', () => {
    const frame = hiddenPlaybackFrame('https://w.soundcloud.com/player/?url=x', 'A track');

    for (const [name, value] of Object.entries(EMBED_FRAME_ATTRIBUTES)) {
      expect(frame.getAttribute(name)).toBe(value);
    }
  });
});

describe('hiddenPlaybackHost', () => {
  afterEach(() => {
    document.body.innerHTML = '';
  });

  it('appends one hidden container to the body on first use', () => {
    const host = hiddenPlaybackHost();

    expect(host.parentElement).toBe(document.body);
    expect(host.classList.contains('hidden-playback')).toBe(true);
    expect(host.getAttribute('aria-hidden')).toBe('true');
  });

  it('answers the same container on every later use', () => {
    const host = hiddenPlaybackHost();

    expect(hiddenPlaybackHost()).toBe(host);
    expect(document.body.querySelectorAll('.hidden-playback')).toHaveLength(1);
  });

  it('builds a fresh container once the body lost the old one', () => {
    const host = hiddenPlaybackHost();
    document.body.innerHTML = '';

    const fresh = hiddenPlaybackHost();

    expect(fresh).not.toBe(host);
    expect(fresh.parentElement).toBe(document.body);
  });
});
