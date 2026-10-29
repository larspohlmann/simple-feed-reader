import { TestBed } from '@angular/core/testing';
import { provideAccountIdentity } from '../../../../testing/account-identity-testing';
import { articleHost } from '../../../../testing/article-host-testing';
import { injectIslands, stubScrollIntoView } from '../../../../testing/article-islands-testing';
import { provideTranslocoTesting } from '../../../../testing/transloco-testing';
import { WIDGET_URL, fakeListenActions } from '../../../../testing/embed-media-testing';
import { ListenActions } from '../player-bar/embed-listen';
import { ArticlePlayerBars } from './article-player-bars';

const YOUTUBE_FRAME =
  '<iframe src="https://www.youtube-nocookie.com/embed/GhUuOxrCato?enablejsapi=1&origin=x"></iframe>';
const CINEMA_BOX = `<div class="reader-cinema"><div class="reader-embed reader-embed--landscape">${YOUTUBE_FRAME}</div></div>`;
const SOUNDCLOUD_BOX = `<div class="reader-embed"><iframe src="${WIDGET_URL}"></iframe></div><p>Notes</p>`;
const VIMEO_BOX =
  '<div class="reader-embed"><iframe src="https://player.vimeo.com/video/1"></iframe></div>';

const NATIVE_VIDEO_BOX =
  '<div class="reader-cinema"><video controls src="https://x.test/clip.mp4"></video></div>';
const VIMEO_CINEMA_BOX =
  '<div class="reader-cinema"><div class="reader-embed reader-embed--landscape"><iframe src="https://player.vimeo.com/video/1"></iframe></div></div>';
const SHORT_BOX =
  '<div class="reader-embed reader-embed--portrait"><iframe src="https://www.youtube-nocookie.com/embed/GhUuOxrCato?enablejsapi=1#shorts"></iframe></div>';

function barsOver(html: string, actions: ListenActions | undefined) {
  const host = articleHost(html);
  const islands = injectIslands();
  const bars = new ArticlePlayerBars(islands);
  bars.mount(host, actions);
  return { host, bars, islands };
}

function twoCinemaBars() {
  return barsOver(CINEMA_BOX + CINEMA_BOX, undefined);
}

function key(init: KeyboardEventInit = {}, target?: HTMLElement): KeyboardEvent {
  const event = new KeyboardEvent('keydown', { key: 't', cancelable: true, ...init });
  if (target) Object.defineProperty(event, 'target', { value: target });
  return event;
}

const widened = (host: HTMLElement): boolean[] =>
  Array.from(host.querySelectorAll('.reader-cinema'), (box) =>
    box.classList.contains('reader-cinema--on'),
  );

stubScrollIntoView();

describe('ArticlePlayerBars', () => {
  beforeEach(() => {
    TestBed.configureTestingModule({
      imports: [provideTranslocoTesting()],
      providers: [provideAccountIdentity()],
    });
  });

  it('puts a bar in a cinema box after its player, with Listen before the cinema toggle', () => {
    const { host } = barsOver(CINEMA_BOX, fakeListenActions());

    const bar = host.querySelector('.reader-cinema')!.children[1];
    expect(bar.matches('app-player-bar')).toBe(true);
    expect(Array.from(bar.querySelectorAll('button'), (button) => button.className)).toEqual([
      'reader-listen__button',
      'reader-listen__button',
      'reader-cinema__toggle',
    ]);
  });

  it('gives a SoundCloud box a bar of its own, right after the box', () => {
    const { host } = barsOver(SOUNDCLOUD_BOX, fakeListenActions());

    expect(host.children[1].matches('app-player-bar')).toBe(true);
    expect(host.querySelector('.reader-cinema__toggle')).toBeNull();
  });

  it('offers only the cinema toggle without listen actions', () => {
    const { host } = barsOver(CINEMA_BOX, undefined);

    expect(host.querySelector('app-player-bar .reader-cinema__toggle')).not.toBeNull();
    expect(host.querySelector('.reader-listen__button')).toBeNull();
  });

  it('adds no bar after an embed Listen cannot play', () => {
    const { host } = barsOver(VIMEO_BOX, fakeListenActions());

    expect(host.querySelector('app-player-bar')).toBeNull();
  });

  it('hands Listen the media and the frame', () => {
    const actions = fakeListenActions();
    const { host } = barsOver(CINEMA_BOX, actions);

    host.querySelector<HTMLButtonElement>('.reader-listen__button')!.click();

    expect(actions.listen).toHaveBeenCalledWith(
      expect.objectContaining({ url: 'https://www.youtube.com/watch?v=GhUuOxrCato' }),
      host.querySelector('iframe'),
    );
  });

  it('gives a native video in a cinema box a cinema-only bar', () => {
    const { host } = barsOver(NATIVE_VIDEO_BOX, fakeListenActions());

    expect(host.querySelector('app-player-bar .reader-cinema__toggle')).not.toBeNull();
    expect(host.querySelector('.reader-listen__button')).toBeNull();
  });

  it('gives a landscape Vimeo frame in a cinema box a cinema-only bar', () => {
    const { host } = barsOver(VIMEO_CINEMA_BOX, fakeListenActions());

    expect(host.querySelector('app-player-bar .reader-cinema__toggle')).not.toBeNull();
    expect(host.querySelector('.reader-listen__button')).toBeNull();
  });

  it('gives a portrait Short outside any cinema box no bar', () => {
    const { host } = barsOver(SHORT_BOX, fakeListenActions());

    expect(host.querySelector('app-player-bar')).toBeNull();
  });

  describe('toggleCinemaByKey', () => {
    it('toggles the first video when none is in view', () => {
      const { host, bars } = twoCinemaBars();
      const event = key();

      bars.toggleCinemaByKey(event);

      expect(widened(host)).toEqual([true, false]);
      expect(event.defaultPrevented).toBe(true);
    });

    it('toggles the video in view', () => {
      const { host, bars } = twoCinemaBars();
      host.querySelectorAll<HTMLElement>('app-player-bar')[1].getBoundingClientRect = () =>
        ({ top: 100, bottom: 400 }) as DOMRect;

      bars.toggleCinemaByKey(key());

      expect(widened(host)).toEqual([false, true]);
    });

    it.each([{ metaKey: true }, { ctrlKey: true }, { altKey: true }, { key: 'T' }, { key: 'x' }])(
      'ignores %o',
      (init) => {
        const { host, bars } = twoCinemaBars();

        bars.toggleCinemaByKey(key(init));

        expect(widened(host)).toEqual([false, false]);
      },
    );

    it.each(['input', 'select'])('ignores a key typed into a %s', (tagName) => {
      const { host, bars } = twoCinemaBars();

      bars.toggleCinemaByKey(key({}, document.createElement(tagName)));

      expect(widened(host)).toEqual([false, false]);
    });

    it('does nothing where cinema is not offered', () => {
      const { host, bars } = twoCinemaBars();
      host.querySelectorAll<HTMLElement>('.reader-cinema__toggle').forEach((toggle) => {
        toggle.style.display = 'none';
      });
      const event = key();

      bars.toggleCinemaByKey(event);

      expect(widened(host)).toEqual([false, false]);
      expect(event.defaultPrevented).toBe(false);
    });

    it('does nothing with no bars mounted', () => {
      const { bars } = barsOver('<p>Text</p>', undefined);
      const event = key();

      bars.toggleCinemaByKey(event);

      expect(event.defaultPrevented).toBe(false);
    });

    it('does nothing once the islands are released', () => {
      const { host, bars, islands } = twoCinemaBars();

      islands.releaseAll();
      bars.toggleCinemaByKey(key());

      expect(widened(host)).toEqual([false, false]);
    });

    it('widens the cinema box next to a bar that offers no cinema', () => {
      const { host, bars } = barsOver(SOUNDCLOUD_BOX + CINEMA_BOX, fakeListenActions());

      bars.toggleCinemaByKey(key());

      expect(widened(host)).toEqual([true]);
    });
  });
});
