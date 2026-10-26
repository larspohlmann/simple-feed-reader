import { TestBed } from '@angular/core/testing';
import { TranslocoService } from '@jsverse/transloco';
import { articleHost, attachedArticleHost } from '../../../../testing/article-host-testing';
import { fakeHls, firstPlay, resetFakeHls } from '../../../../testing/hls-testing';
import { provideTranslocoTesting } from '../../../../testing/transloco-testing';
import { decorateArticle } from './decorate-article';
import type { ListenActions } from './reader-listen';

jest.mock('hls.js', () => jest.requireActual('../../../../testing/hls-testing').fakeHlsModule);

const decorated = (html: string): HTMLElement =>
  articleHost(html, (host) =>
    decorateArticle(host, TestBed.inject(TranslocoService), { enclosureUrl: null }),
  );

const YOUTUBE_LINK = '<a href="https://www.youtube-nocookie.com/embed/GhUuOxrCato">x</a>';

const listenActions = (): ListenActions => ({ listen: jest.fn(), toggleQueued: jest.fn() });

describe('decorateArticle', () => {
  beforeEach(() => {
    TestBed.configureTestingModule({ imports: [provideTranslocoTesting()] });
  });

  afterEach(() => {
    document.body.innerHTML = '';
  });

  it('puts the landscape embed the media step builds from a link inside a cinema box', () => {
    const host = decorated(YOUTUBE_LINK);

    expect(host.querySelector('.reader-cinema > .reader-embed iframe')).not.toBeNull();
    expect(host.querySelector('.reader-cinema > .reader-player-bar')).not.toBeNull();
  });

  it('offers Listen and the queue toggle under a video when listen actions are given', () => {
    const actions = listenActions();
    const host = articleHost(YOUTUBE_LINK);

    const controls = decorateArticle(host, TestBed.inject(TranslocoService), {
      enclosureUrl: null,
      listen: actions,
    });

    const buttons = Array.from(
      host.querySelectorAll('.reader-player-bar > .reader-listen__button'),
      (button) => button.textContent,
    );
    expect(buttons).toEqual(['play_circleListen', 'playlist_addAdd to playlist']);
    expect(host.querySelector<HTMLElement>('.reader-listen__button')?.title).toBe(
      "Play the sound in the reader's audio player",
    );
    expect(controls.map((control) => control.media.url)).toEqual([
      'https://www.youtube.com/watch?v=GhUuOxrCato',
    ]);

    controls[0].showQueued(true);
    expect(host.querySelector('[aria-pressed="true"]')?.textContent).toBe(
      'playlist_add_checkIn playlist',
    );
  });

  it('offers no listen controls without listen actions', () => {
    const host = articleHost(YOUTUBE_LINK);

    const controls = decorateArticle(host, TestBed.inject(TranslocoService), {
      enclosureUrl: null,
    });

    expect(controls).toEqual([]);
    expect(host.querySelector('.reader-listen__button')).toBeNull();
  });

  it('leaves the portrait embed the media step builds from a Short outside a cinema box', () => {
    const host = decorated(
      '<a href="https://www.youtube-nocookie.com/embed/GhUuOxrCato#shorts">x</a>',
    );

    expect(host.querySelector('.reader-embed iframe')).not.toBeNull();
    expect(host.querySelector('.reader-cinema')).toBeNull();
  });

  it('keeps the narration box a collapsed player while a publisher disclosure becomes a FAQ', () => {
    const host = decorated(
      '<audio class="reader-narration" src="https://x.test/full.mp3"></audio>' +
        '<details><summary>Question?</summary><p>Answer.</p></details>',
    );
    const [narration, disclosure] = Array.from(host.querySelectorAll('details'));

    expect(narration.querySelector('audio')).not.toBeNull();
    expect(narration.classList.contains('reader-faq')).toBe(false);
    expect(narration.open).toBe(false);
    expect(disclosure.classList.contains('reader-faq')).toBe(true);
    expect(disclosure.open).toBe(true);
  });

  it('streams a playlist video through hls.js once the cinema box has wrapped it', async () => {
    resetFakeHls();
    const host = attachedArticleHost('<video src="https://x.test/master.m3u8"></video>');
    decorateArticle(host, TestBed.inject(TranslocoService), { enclosureUrl: null });
    const video = host.querySelector<HTMLVideoElement>('.reader-cinema video')!;

    await firstPlay(video);

    expect(fakeHls.loadSource).toHaveBeenCalledWith('https://x.test/master.m3u8');
    expect(fakeHls.attachMedia).toHaveBeenCalledWith(video);
  });
});
