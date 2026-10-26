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
import { ChapterDto, EntryDto } from '../../models';
import { AudioPlayerService, AudioTrack } from '../../audio-player.service';
import { anEntry } from '../../../../testing/entry-testing';
import { Observable, Subject, of, throwError } from 'rxjs';
import { TrackDetails, chaptersOnly } from '../../audio/audio-track';
import { EmbedDetailsApi } from '../../embed-details-api';
import { EmbedMedia } from '../../audio/embed-media';

jest.mock('hls.js', () => jest.requireActual('../../../../testing/hls-testing').fakeHlsModule);

const VIDEO = '<video controls src="https://x.test/clip.mp4"></video>';
const STREAM = '<video controls src="https://x.test/master.m3u8"></video>';
const YOUTUBE =
  '<a href="https://www.youtube-nocookie.com/embed/JsBZOcqZerk">Video</a><p>Notes</p>';
const VIDEO_URL = 'https://www.youtube.com/watch?v=JsBZOcqZerk';
const API_FRAME_URL = `https://www.youtube-nocookie.com/embed/JsBZOcqZerk?enablejsapi=1&origin=${encodeURIComponent(window.location.origin)}`;
const CHAPTERS: ChapterDto[] = [
  { startSeconds: 0, title: 'Intro' },
  { startSeconds: 256, title: 'How it works' },
];
const WIDGET_URL =
  'https://w.soundcloud.com/player/?url=https%3A%2F%2Fapi.soundcloud.com%2Ftracks%2F123456789';
const SOUNDCLOUD = `<a href="${WIDGET_URL}">Track</a>`;
const OTHER_VIDEO_URL = 'https://www.youtube.com/watch?v=aaaaaaaaaaa';
const OTHER_YOUTUBE = '<a href="https://www.youtube-nocookie.com/embed/aaaaaaaaaaa">Other</a>';
const VIDEO_CHAPTERS: ChapterDto[] = [
  { startSeconds: 0, title: 'Opening' },
  { startSeconds: 61, title: 'Q&A' },
];
const TRACK_CHAPTERS: ChapterDto[] = [
  { startSeconds: 0, title: 'Side A' },
  { startSeconds: 120, title: 'Side B' },
];
const NO_DETAILS = chaptersOnly([]);
const TRACK_DETAILS: TrackDetails = {
  title: 'Catching Flies',
  durationSeconds: null,
  chapters: TRACK_CHAPTERS,
};
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
  let embedDetails: jest.Mock<Observable<TrackDetails>, [EmbedMedia]>;
  const html = signal('');
  const chapters = signal<ChapterDto[]>([]);
  const audioTrack = signal<AudioTrack | null>(null);
  const entry = signal<EntryDto | null>(null);

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
      entry,
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
    localStorage.clear();
    chapters.set([]);
    audioTrack.set(null);
    entry.set(null);
    imageProxy = neverRecoveringImageProxy();
    embedDetails = jest.fn<Observable<TrackDetails>, [EmbedMedia]>(() => of(NO_DETAILS));
    TestBed.configureTestingModule({
      imports: [provideTranslocoTesting()],
      providers: [
        { provide: ImageProxyService, useValue: imageProxy },
        { provide: EmbedDetailsApi, useValue: { detailsOf: embedDetails } },
      ],
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
      expect(frame.getAttribute('src')).toBe(API_FRAME_URL);
      expect(frame.closest('.reader-cinema')!.nextElementSibling!.classList).toContain(
        'reader-chapters',
      );
    });

    it('loads a video without chapters with the player API too, so Listen can pause it', async () => {
      connectBody(YOUTUBE);
      await Promise.resolve();

      expect(content.querySelector('iframe')!.getAttribute('src')).toBe(API_FRAME_URL);
      expect(content.querySelector('.reader-chapters')).toBeNull();
    });

    it("keeps an episode's chapters on the episode when its notes embed a video", async () => {
      chapters.set(CHAPTERS);
      audioTrack.set(EPISODE);
      connectBody(YOUTUBE);
      await Promise.resolve();

      expect(content.querySelector('iframe')!.getAttribute('src')).toBe(API_FRAME_URL);
      expect(content.firstElementChild!.classList).toContain('reader-chapters');
    });

    it('hands the player the chapters of the video it lists, for a copy queued earlier, even when its own details fail', async () => {
      const describeTrack = jest
        .spyOn(TestBed.inject(AudioPlayerService), 'describe')
        .mockImplementation(() => undefined);
      embedDetails.mockImplementation(() => throwError(() => new Error('503')));
      chapters.set(CHAPTERS);
      connectBody(YOUTUBE);
      await Promise.resolve();

      expect(describeTrack.mock.calls).toEqual([[VIDEO_URL, chaptersOnly(CHAPTERS)]]);
    });

    it("gives no video the chapters while they are the episode's", async () => {
      const describeTrack = jest
        .spyOn(TestBed.inject(AudioPlayerService), 'describe')
        .mockImplementation(() => undefined);
      chapters.set(CHAPTERS);
      audioTrack.set(EPISODE);
      connectBody(YOUTUBE);
      await Promise.resolve();

      expect(describeTrack).not.toHaveBeenCalledWith(
        VIDEO_URL,
        expect.objectContaining({ chapters: CHAPTERS }),
      );
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

  describe('its embeds', () => {
    const listenButton = (): HTMLButtonElement =>
      content.querySelector<HTMLButtonElement>('.reader-listen__button')!;
    const queueToggle = (): HTMLButtonElement =>
      content.querySelector<HTMLButtonElement>('.reader-listen__button[aria-pressed]')!;

    it('offers Listen under a video of the open entry', async () => {
      entry.set(anEntry());
      connectBody(YOUTUBE);
      await Promise.resolve();

      expect(listenButton()).not.toBeNull();
    });

    it('offers no Listen before the entry is known', async () => {
      connectBody(YOUTUBE);
      await Promise.resolve();

      expect(listenButton()).toBeNull();
    });

    it('sends the video to the player on Listen, and its chapter list follows', async () => {
      const audio = TestBed.inject(AudioPlayerService);
      const play = jest.spyOn(audio, 'play').mockImplementation(() => undefined);
      const playFrom = jest.spyOn(audio, 'playFrom').mockImplementation(() => undefined);
      entry.set(anEntry({ title: 'The video' }));
      chapters.set(CHAPTERS);
      connectBody(YOUTUBE);
      await Promise.resolve();

      listenButton().click();
      content.querySelectorAll<HTMLButtonElement>('.reader-chapters__row')[1].click();

      const track = expect.objectContaining({
        url: VIDEO_URL,
        title: 'The video',
        chapters: CHAPTERS,
      });
      expect(play).toHaveBeenCalledWith(track);
      expect(playFrom).toHaveBeenCalledWith(track, 256);
    });

    it('shows the queue toggle pressed while the video is in the playlist', async () => {
      const audio = TestBed.inject(AudioPlayerService);
      entry.set(anEntry());
      connectBody(YOUTUBE);
      await Promise.resolve();
      TestBed.tick();
      expect(queueToggle().getAttribute('aria-pressed')).toBe('false');

      queueToggle().click();
      TestBed.tick();

      expect(audio.isQueued(VIDEO_URL)).toBe(true);
      expect(queueToggle().getAttribute('aria-pressed')).toBe('true');
    });

    it('stops following the playlist once destroyed', async () => {
      const audio = TestBed.inject(AudioPlayerService);
      entry.set(anEntry());
      connectBody(YOUTUBE);
      await Promise.resolve();
      TestBed.tick();
      const toggle = queueToggle();

      injector.destroy();
      audio.enqueue({
        url: VIDEO_URL,
        title: 'V',
        faviconUrl: null,
        imageUrl: null,
        durationInSeconds: null,
      });
      TestBed.tick();

      expect(toggle.getAttribute('aria-pressed')).toBe('false');
    });
  });
  describe("its embeds' own details", () => {
    const answers = (byUrl: Record<string, Observable<TrackDetails>>): void => {
      embedDetails.mockImplementation((media) => byUrl[media.url] ?? of(NO_DETAILS));
    };
    const askedUrls = (): string[] => embedDetails.mock.calls.map(([media]) => media.url);
    const listRows = (list: Element): string[] =>
      Array.from(list.querySelectorAll('.reader-chapters__row')).map(
        (row) => row.textContent ?? '',
      );

    it('asks for every embed and lists the chapters under each', async () => {
      answers({
        [VIDEO_URL]: of({ ...NO_DETAILS, chapters: VIDEO_CHAPTERS }),
        [WIDGET_URL]: of(TRACK_DETAILS),
      });
      connectBody(YOUTUBE + SOUNDCLOUD);
      await Promise.resolve();

      expect(askedUrls()).toEqual([VIDEO_URL, WIDGET_URL]);
      const lists = content.querySelectorAll('.reader-chapters');
      expect(lists).toHaveLength(2);
      expect(listRows(lists[0])).toEqual(['0:00Opening', '1:01Q&A']);
      expect(listRows(lists[1])).toEqual(['0:00Side A', '2:00Side B']);
      expect(content.querySelector('.reader-embed--audio')!.nextElementSibling).toBe(lists[1]);
    });

    describe("for the video the body's chapters belong to", () => {
      const fetched: TrackDetails = {
        title: 'The video itself',
        durationSeconds: 4964,
        chapters: VIDEO_CHAPTERS,
      };

      beforeEach(async () => {
        answers({ [VIDEO_URL]: of(fetched) });
        entry.set(anEntry({ title: 'The post' }));
        chapters.set(CHAPTERS);
        connectBody(YOUTUBE + OTHER_YOUTUBE + SOUNDCLOUD);
        await Promise.resolve();
      });

      it('asks for its details too', () => {
        expect(askedUrls()).toEqual([VIDEO_URL, OTHER_VIDEO_URL, WIDGET_URL]);
      });

      it("lists only the body's chapters under it, even when its answer has chapters", () => {
        const lists = content.querySelectorAll('.reader-chapters');

        expect(lists).toHaveLength(1);
        expect(listRows(lists[0])).toEqual(['0:00Intro', '4:16How it works']);
      });

      it("hands Listen a track with its fetched title and length and the body's chapters", () => {
        const play = jest
          .spyOn(TestBed.inject(AudioPlayerService), 'play')
          .mockImplementation(() => undefined);

        content.querySelector<HTMLButtonElement>('.reader-listen__button')!.click();

        expect(play).toHaveBeenCalledWith(
          expect.objectContaining({
            url: VIDEO_URL,
            title: 'The video itself',
            durationInSeconds: 4964,
            chapters: CHAPTERS,
          }),
        );
      });
    });

    it("describes the body's video by its fetched title and length, keeping the body's chapters", async () => {
      const describeTrack = jest
        .spyOn(TestBed.inject(AudioPlayerService), 'describe')
        .mockImplementation(() => undefined);
      answers({
        [VIDEO_URL]: of({ title: 'Own', durationSeconds: 300, chapters: VIDEO_CHAPTERS }),
      });
      chapters.set(CHAPTERS);
      connectBody(YOUTUBE);
      await Promise.resolve();

      expect(describeTrack).toHaveBeenLastCalledWith(VIDEO_URL, {
        title: 'Own',
        durationSeconds: 300,
        chapters: CHAPTERS,
      });
    });

    it("gives a YouTube entry's own queued video its length, keeping the body's one chapter list", async () => {
      const audio = TestBed.inject(AudioPlayerService);
      audio.enqueue({
        url: VIDEO_URL,
        title: 'The post',
        faviconUrl: null,
        imageUrl: null,
        durationInSeconds: null,
      });
      answers({
        [VIDEO_URL]: of({ title: null, durationSeconds: 600, chapters: VIDEO_CHAPTERS }),
      });
      entry.set(anEntry({ url: VIDEO_URL, title: 'The post' }));
      chapters.set(CHAPTERS);
      connectBody(YOUTUBE);
      await Promise.resolve();

      expect(askedUrls()).toEqual([VIDEO_URL]);
      expect(audio.tracks()[0]).toEqual(
        expect.objectContaining({ title: 'The post', durationInSeconds: 600, chapters: CHAPTERS }),
      );
      const lists = content.querySelectorAll('.reader-chapters');
      expect(lists).toHaveLength(1);
      expect(listRows(lists[0])).toEqual(['0:00Intro', '4:16How it works']);
    });

    it("hands Listen on the widget a track carrying the widget's title and chapters", async () => {
      const play = jest
        .spyOn(TestBed.inject(AudioPlayerService), 'play')
        .mockImplementation(() => undefined);
      answers({ [WIDGET_URL]: of(TRACK_DETAILS) });
      entry.set(anEntry());
      connectBody(YOUTUBE + SOUNDCLOUD);
      await Promise.resolve();

      content
        .querySelectorAll('.reader-listen')[1]
        .querySelector<HTMLButtonElement>('.reader-listen__button')!
        .click();

      expect(play).toHaveBeenCalledWith(
        expect.objectContaining({
          url: WIDGET_URL,
          title: 'Catching Flies',
          chapters: TRACK_CHAPTERS,
        }),
      );
    });

    it("names Listen's track for the video the body lists by the post while nothing was fetched", async () => {
      const play = jest
        .spyOn(TestBed.inject(AudioPlayerService), 'play')
        .mockImplementation(() => undefined);
      entry.set(anEntry({ title: 'The post' }));
      chapters.set(CHAPTERS);
      connectBody(YOUTUBE);
      await Promise.resolve();

      content.querySelector<HTMLButtonElement>('.reader-listen__button')!.click();

      expect(play).toHaveBeenCalledWith(
        expect.objectContaining({
          url: VIDEO_URL,
          title: 'The post',
          durationInSeconds: null,
          chapters: CHAPTERS,
        }),
      );
    });

    it('hands the player the details of an embed queued before they arrived', async () => {
      const describeTrack = jest
        .spyOn(TestBed.inject(AudioPlayerService), 'describe')
        .mockImplementation(() => undefined);
      const answer = new Subject<TrackDetails>();
      answers({ [WIDGET_URL]: answer });
      connectBody(SOUNDCLOUD);
      await Promise.resolve();

      answer.next(TRACK_DETAILS);

      expect(describeTrack).toHaveBeenCalledWith(WIDGET_URL, TRACK_DETAILS);
    });

    it('describes an embed without chapters by its title, and attaches no list', async () => {
      const describeTrack = jest
        .spyOn(TestBed.inject(AudioPlayerService), 'describe')
        .mockImplementation(() => undefined);
      const unchaptered: TrackDetails = { title: 'A mix', durationSeconds: 4964, chapters: [] };
      answers({ [WIDGET_URL]: of(unchaptered) });
      connectBody(SOUNDCLOUD);
      await Promise.resolve();

      expect(content.querySelector('.reader-chapters')).toBeNull();
      expect(describeTrack).toHaveBeenCalledWith(WIDGET_URL, unchaptered);
    });

    it("drops a late answer for the swapped-out body's embed", async () => {
      const describeTrack = jest
        .spyOn(TestBed.inject(AudioPlayerService), 'describe')
        .mockImplementation(() => undefined);
      const late = new Subject<TrackDetails>();
      answers({ [WIDGET_URL]: late });
      connectBody(SOUNDCLOUD);
      await Promise.resolve();
      const swappedOut = content.querySelector('.reader-embed--audio')!;

      html.set('<p>Reader.</p>');
      TestBed.tick();
      late.next(TRACK_DETAILS);

      expect(swappedOut.parentElement!.querySelector('.reader-chapters')).toBeNull();
      expect(describeTrack).not.toHaveBeenCalled();
    });

    it('drops a late answer once destroyed', async () => {
      const late = new Subject<TrackDetails>();
      answers({ [WIDGET_URL]: late });
      connectBody(SOUNDCLOUD);
      await Promise.resolve();

      injector.destroy();
      late.next(TRACK_DETAILS);

      expect(content.querySelector('.reader-chapters')).toBeNull();
    });
  });
});
