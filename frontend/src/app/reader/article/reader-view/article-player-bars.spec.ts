import { Injector } from '@angular/core';
import { TestBed } from '@angular/core/testing';
import { provideAccountIdentity } from '../../../../testing/account-identity-testing';
import { articleHost } from '../../../../testing/article-host-testing';
import { provideTranslocoTesting } from '../../../../testing/transloco-testing';
import { WIDGET_URL, fakeListenActions } from '../../../../testing/embed-media-testing';
import { ListenActions } from '../player-bar/embed-listen';
import { ArticleIslands } from './article-islands';
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

function mountBars(html: string, actions: ListenActions | undefined): HTMLElement {
  const host = articleHost(html);
  new ArticlePlayerBars(new ArticleIslands(TestBed.inject(Injector))).mount(host, actions);
  return host;
}

describe('ArticlePlayerBars', () => {
  beforeEach(() => {
    TestBed.configureTestingModule({
      imports: [provideTranslocoTesting()],
      providers: [provideAccountIdentity()],
    });
  });

  it('puts a bar in a cinema box after its player, with Listen before the cinema toggle', () => {
    const host = mountBars(CINEMA_BOX, fakeListenActions());

    const bar = host.querySelector('.reader-cinema')!.children[1];
    expect(bar.matches('app-player-bar.reader-player-bar')).toBe(true);
    expect(Array.from(bar.querySelectorAll('button'), (button) => button.className)).toEqual([
      'reader-listen__button',
      'reader-listen__button',
      'reader-cinema__toggle',
    ]);
  });

  it('gives a SoundCloud box a bar of its own, right after the box', () => {
    const host = mountBars(SOUNDCLOUD_BOX, fakeListenActions());

    expect(host.children[1].matches('app-player-bar')).toBe(true);
    expect(host.querySelector('.reader-cinema__toggle')).toBeNull();
  });

  it('offers only the cinema toggle without listen actions', () => {
    const host = mountBars(CINEMA_BOX, undefined);

    expect(host.querySelector('app-player-bar .reader-cinema__toggle')).not.toBeNull();
    expect(host.querySelector('.reader-listen__button')).toBeNull();
  });

  it('adds no bar after an embed Listen cannot play', () => {
    const host = mountBars(VIMEO_BOX, fakeListenActions());

    expect(host.querySelector('app-player-bar')).toBeNull();
  });

  it('hands Listen the media and the frame', () => {
    const actions = fakeListenActions();
    const host = mountBars(CINEMA_BOX, actions);

    host.querySelector<HTMLButtonElement>('.reader-listen__button')!.click();

    expect(actions.listen).toHaveBeenCalledWith(
      expect.objectContaining({ url: 'https://www.youtube.com/watch?v=GhUuOxrCato' }),
      host.querySelector('iframe'),
    );
  });

  it('gives a native video in a cinema box a cinema-only bar', () => {
    const host = mountBars(NATIVE_VIDEO_BOX, fakeListenActions());

    expect(host.querySelector('app-player-bar .reader-cinema__toggle')).not.toBeNull();
    expect(host.querySelector('.reader-listen__button')).toBeNull();
  });

  it('gives a landscape Vimeo frame in a cinema box a cinema-only bar', () => {
    const host = mountBars(VIMEO_CINEMA_BOX, fakeListenActions());

    expect(host.querySelector('app-player-bar .reader-cinema__toggle')).not.toBeNull();
    expect(host.querySelector('.reader-listen__button')).toBeNull();
  });

  it('gives a portrait Short outside any cinema box no bar', () => {
    const host = mountBars(SHORT_BOX, fakeListenActions());

    expect(host.querySelector('app-player-bar')).toBeNull();
  });
});
