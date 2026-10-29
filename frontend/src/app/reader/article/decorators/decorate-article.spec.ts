import { TestBed } from '@angular/core/testing';
import { TranslocoService } from '@jsverse/transloco';
import { articleHost, attachedArticleHost } from '../../../../testing/article-host-testing';
import { fakeHls, firstPlay, resetFakeHls } from '../../../../testing/hls-testing';
import { provideTranslocoTesting } from '../../../../testing/transloco-testing';
import { decorateArticle } from './decorate-article';

jest.mock('hls.js', () => jest.requireActual('../../../../testing/hls-testing').fakeHlsModule);

const decorated = (html: string): HTMLElement =>
  articleHost(html, (host) =>
    decorateArticle(host, TestBed.inject(TranslocoService), { enclosureUrl: null }),
  );

const YOUTUBE_LINK = '<a href="https://www.youtube-nocookie.com/embed/GhUuOxrCato">x</a>';

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
  });

  it('leaves the portrait embed the media step builds from a Short outside a cinema box', () => {
    const host = decorated(
      '<a href="https://www.youtube-nocookie.com/embed/GhUuOxrCato#shorts">x</a>',
    );

    expect(host.querySelector('.reader-embed iframe')).not.toBeNull();
    expect(host.querySelector('.reader-cinema')).toBeNull();
  });

  it('turns a publisher disclosure into a FAQ and leaves the narration player in place', () => {
    const host = decorated(
      '<audio class="reader-narration" src="https://x.test/full.mp3"></audio>' +
        '<details><summary>Question?</summary><p>Answer.</p></details>',
    );

    expect(host.querySelectorAll('details.reader-faq[open]')).toHaveLength(1);
    expect(host.querySelectorAll('details')).toHaveLength(1);
    expect(host.querySelector('audio.reader-narration')!.closest('details')).toBeNull();
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
