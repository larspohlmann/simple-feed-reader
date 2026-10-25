import {
  ElementRef,
  EnvironmentInjector,
  NgZone,
  createEnvironmentInjector,
  signal,
} from '@angular/core';
import { TestBed } from '@angular/core/testing';
import { articleHost } from '../../../../testing/article-host-testing';
import { provideTranslocoTesting } from '../../../../testing/transloco-testing';
import { ImageProxyService } from '../../../shared/proxied-image/image-proxy.service';
import { neverRecoveringImageProxy } from '../../../../testing/image-proxy-testing';
import { fakeHls, firstPlay, resetFakeHls } from '../../../../testing/hls-testing';
import { ArticleBody } from './article-body.service';
import { ListAutoplayCoordinator } from '../../media/autoplay/list-autoplay-coordinator';
import { PlayingMedium } from '../../media/autoplay/autoplay-player';
import { ChapterDto } from '../../models';
import { AudioTrack } from '../../audio-player.service';

jest.mock('hls.js', () => jest.requireActual('../../../../testing/hls-testing').fakeHlsModule);

const VIDEO = '<video controls src="https://x.test/clip.mp4"></video>';
const STREAM = '<video controls src="https://x.test/master.m3u8"></video>';
const YOUTUBE =
  '<a href="https://www.youtube-nocookie.com/embed/JsBZOcqZerk">Video</a><p>Notes</p>';
const CHAPTERS: ChapterDto[] = [
  { startSeconds: 0, title: 'Intro' },
  { startSeconds: 256, title: 'How it works' },
];
const EPISODE: AudioTrack = {
  url: 'https://x.test/ep.mp3',
  title: 'Ep',
  faviconUrl: null,
  imageUrl: null,
  durationInSeconds: 600,
};

describe('ArticleBody', () => {
  let imageProxy: ReturnType<typeof neverRecoveringImageProxy>;
  let injector: EnvironmentInjector;
  let decorated: jest.Mock<void, [HTMLElement]>;
  let content: HTMLElement;
  let autoplay: { nowPlaying: jest.Mock; stopped: jest.Mock };
  const html = signal('');
  const chapters = signal<ChapterDto[]>([]);
  const audioTrack = signal<AudioTrack | null>(null);

  function connectBody(body: string): void {
    content = articleHost(body);
    html.set(body);
    decorated = jest.fn();
    injector.get(ArticleBody).connect({
      content: signal(new ElementRef(content)),
      html,
      pageUrl: () => 'https://news.test/story',
      enclosureUrl: () => null,
      chapters,
      audioTrack,
      decorated,
    });
    TestBed.tick();
  }

  async function playBodyStream(): Promise<void> {
    connectBody(STREAM);
    document.body.append(content);
    await Promise.resolve();
    await firstPlay(content.querySelector('video')!);
  }

  function playBodyVideo(): HTMLVideoElement {
    const video = content.querySelector('video')!;
    Object.defineProperty(video, 'paused', { configurable: true, value: false });
    video.dispatchEvent(new Event('play'));
    return video;
  }

  const pressT = (): void => {
    document.dispatchEvent(new KeyboardEvent('keydown', { key: 't', cancelable: true }));
  };
  const cinemaToggleClicks = (): jest.SpyInstance =>
    jest
      .spyOn(content.querySelector<HTMLButtonElement>('.reader-cinema__toggle')!, 'click')
      .mockImplementation(() => undefined);

  beforeEach(() => {
    chapters.set([]);
    audioTrack.set(null);
    imageProxy = neverRecoveringImageProxy();
    TestBed.configureTestingModule({
      imports: [provideTranslocoTesting()],
      providers: [{ provide: ImageProxyService, useValue: imageProxy }],
    });
    autoplay = { nowPlaying: jest.fn(), stopped: jest.fn() };
    injector = createEnvironmentInjector(
      [ArticleBody, { provide: ListAutoplayCoordinator, useValue: autoplay }],
      TestBed.inject(EnvironmentInjector),
    );
  });

  afterEach(() => {
    if (!injector.destroyed) injector.destroy();
    document.body.innerHTML = '';
  });

  it('hands nothing on when destroyed before the decoration runs', async () => {
    connectBody('<a href="https://ext.test/z">ext</a>');

    injector.destroy();
    await Promise.resolve();

    expect(decorated).not.toHaveBeenCalled();
  });

  it('decorates the body once it renders, then hands it on', async () => {
    connectBody('<a href="https://ext.test/z">ext</a>');
    expect(decorated).not.toHaveBeenCalled();

    await Promise.resolve();

    expect(content.querySelector('a')!.target).toBe('_blank');
    expect(decorated).toHaveBeenCalledWith(content);
  });

  it('decorates again when the rendered HTML changes', async () => {
    connectBody('<p>Feed.</p>');
    await Promise.resolve();

    html.set('<p>Reader.</p>');
    TestBed.tick();
    await Promise.resolve();

    expect(decorated).toHaveBeenCalledTimes(2);
  });

  it('hands a failed body image to the proxy', () => {
    connectBody('<img src="https://www.oxmoxhh.de/cover.png" alt="">');
    const image = content.querySelector('img')!;

    image.dispatchEvent(new Event('error'));

    expect(imageProxy.attempts).toEqual([image]);
  });

  it('replaces a body video the browser cannot play with a link to the original page', () => {
    connectBody(VIDEO);

    content.querySelector('video')!.dispatchEvent(new Event('error'));

    expect(content.querySelector('video')).toBeNull();
    const link = content.querySelector<HTMLAnchorElement>('figure.link-card a')!;
    expect(link.href).toBe('https://news.test/story');
    expect(link.querySelector('strong')!.textContent).toBe("This video can't play in your browser");
  });

  it('widens the video on the t key', async () => {
    connectBody(VIDEO);
    await Promise.resolve();
    const clicks = cinemaToggleClicks();

    pressT();

    expect(clicks).toHaveBeenCalled();
  });

  it('lets go of the body and the document once destroyed', async () => {
    connectBody(VIDEO);
    await Promise.resolve();
    const clicks = cinemaToggleClicks();
    const image = document.createElement('img');
    content.append(image);

    injector.destroy();
    pressT();
    image.dispatchEvent(new Event('error'));

    expect(clicks).not.toHaveBeenCalled();
    expect(imageProxy.attempts).toEqual([]);
  });

  it('keeps a playing stream while the body stays, and releases it when the HTML changes', async () => {
    resetFakeHls();
    await playBodyStream();

    TestBed.tick();
    expect(fakeHls.destroy).not.toHaveBeenCalled();

    html.set('<p>Reader.</p>');
    TestBed.tick();
    expect(fakeHls.destroy).toHaveBeenCalledTimes(1);
  });

  it('releases the body stream once destroyed', async () => {
    resetFakeHls();
    await playBodyStream();

    injector.destroy();

    expect(fakeHls.destroy).toHaveBeenCalledTimes(1);
  });
  describe('its media', () => {
    it('tells the autoplay coordinator when the user plays one', async () => {
      connectBody(VIDEO);
      await Promise.resolve();

      const video = playBodyVideo();

      expect(autoplay.nowPlaying).toHaveBeenCalledTimes(1);
      expect((autoplay.nowPlaying.mock.calls[0][0] as PlayingMedium).element).toBe(video);
    });

    it('hears them outside the Angular zone, so a play runs no change detection', async () => {
      const inAngularZone: boolean[] = [];
      autoplay.nowPlaying.mockImplementation(() => inAngularZone.push(NgZone.isInAngularZone()));
      TestBed.inject(NgZone).run(() => connectBody(VIDEO));
      await Promise.resolve();

      playBodyVideo();

      expect(inAngularZone).toEqual([false]);
    });

    it('lets go of the swapped-out media when the HTML changes', async () => {
      connectBody(VIDEO);
      await Promise.resolve();
      const swappedOut = content.querySelector('video')!;

      content.innerHTML = '<p>Reader.</p>';
      html.set('<p>Reader.</p>');
      TestBed.tick();
      await Promise.resolve();
      content.append(swappedOut);
      playBodyVideo();

      expect(autoplay.stopped).toHaveBeenCalledTimes(1);
      expect(autoplay.nowPlaying).not.toHaveBeenCalled();
    });

    it('lets go of them once destroyed', async () => {
      connectBody(VIDEO);
      await Promise.resolve();

      injector.destroy();
      playBodyVideo();

      expect(autoplay.stopped).toHaveBeenCalledTimes(1);
      expect(autoplay.nowPlaying).not.toHaveBeenCalled();
    });
  });

  describe('its chapters', () => {
    const chapterRows = (): string[] =>
      Array.from(content.querySelectorAll('.reader-chapters__row')).map(
        (row) => row.textContent ?? '',
      );

    it("lists an episode's chapters atop the body", async () => {
      chapters.set(CHAPTERS);
      audioTrack.set(EPISODE);
      connectBody('<p>Notes</p>');
      await Promise.resolve();

      expect(content.firstElementChild!.getAttribute('aria-label')).toBe('Chapters');
      expect(chapterRows()).toEqual(['0:00Intro', '4:16How it works']);
    });

    it('drives a video with chapters through its API player, listing them beneath it', async () => {
      chapters.set(CHAPTERS);
      connectBody(YOUTUBE);
      await Promise.resolve();

      const frame = content.querySelector('iframe')!;
      expect(frame.getAttribute('src')).toBe(
        `https://www.youtube-nocookie.com/embed/JsBZOcqZerk?enablejsapi=1&origin=${encodeURIComponent(window.location.origin)}`,
      );
      expect(frame.closest('.reader-cinema')!.nextElementSibling!.classList).toContain(
        'reader-chapters',
      );
    });

    it('leaves a video without chapters on its plain player', async () => {
      connectBody(YOUTUBE);
      await Promise.resolve();

      expect(content.querySelector('iframe')!.getAttribute('src')).toBe(
        'https://www.youtube-nocookie.com/embed/JsBZOcqZerk',
      );
      expect(content.querySelector('.reader-chapters')).toBeNull();
    });

    it('decorates again when the chapters arrive after the body', async () => {
      audioTrack.set(EPISODE);
      connectBody('<p>Notes</p>');
      await Promise.resolve();

      chapters.set(CHAPTERS);
      TestBed.tick();
      await Promise.resolve();

      expect(decorated).toHaveBeenCalledTimes(2);
      expect(chapterRows()).toHaveLength(2);
    });

    it('does not decorate again for an equal chapter list or a changed episode object', async () => {
      chapters.set(CHAPTERS);
      audioTrack.set(EPISODE);
      connectBody('<p>Notes</p>');
      await Promise.resolve();

      chapters.set(CHAPTERS.map((chapter) => ({ ...chapter })));
      audioTrack.set({ ...EPISODE });
      TestBed.tick();
      await Promise.resolve();

      expect(decorated).toHaveBeenCalledTimes(1);
    });
  });
});
