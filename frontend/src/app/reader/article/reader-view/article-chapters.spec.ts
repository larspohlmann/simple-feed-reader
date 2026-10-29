import { Injector } from '@angular/core';
import { TestBed } from '@angular/core/testing';
import { injectIslands } from '../../../../testing/article-islands-testing';
import { provideAccountIdentity } from '../../../../testing/account-identity-testing';
import { WIDGET_URL } from '../../../../testing/embed-media-testing';
import {
  deliverMessage,
  messagesOf,
  recordPosts,
} from '../../../../testing/frame-playback-testing';
import { openReaderPanels } from '../../../../testing/reader-panel-testing';
import { provideTranslocoTesting } from '../../../../testing/transloco-testing';
import { AudioPlayerService } from '../../audio-player.service';
import { SOUNDCLOUD_WIDGET_ORIGIN } from '../../media/soundcloud-widget-protocol';
import { ArticleChapters, chapterFrames } from './article-chapters';
import { ArticleIslands } from './article-islands';

const CHAPTERS = [
  { startSeconds: 0, title: 'A' },
  { startSeconds: 60, title: 'B' },
];
const TRACK = {
  url: 'https://x.test/ep.mp3',
  title: 'Ep',
  faviconUrl: null,
  imageUrl: null,
  durationInSeconds: 600,
};
const NEXT_TRACK = { ...TRACK, url: 'https://x.test/next.mp3', title: 'Next' };
const NEXT_CHAPTERS = [
  { startSeconds: 0, title: 'X' },
  { startSeconds: 30, title: 'Y' },
  { startSeconds: 90, title: 'Z' },
];

function titles(host: HTMLElement): string[] {
  openReaderPanels(host);
  return Array.from(host.querySelectorAll('.reader-chapters__title')).map(
    (title) => title.textContent ?? '',
  );
}

function mountedChapters(): { articleChapters: ArticleChapters; islands: ArticleIslands } {
  const islands = injectIslands();
  const articleChapters = new ArticleChapters(
    TestBed.inject(AudioPlayerService),
    islands,
    TestBed.inject(Injector),
  );
  return { articleChapters, islands };
}

function chapters(): ArticleChapters {
  return mountedChapters().articleChapters;
}

describe('ArticleChapters', () => {
  beforeEach(() => {
    localStorage.clear();
    TestBed.configureTestingModule({
      imports: [provideTranslocoTesting()],
      providers: [provideAccountIdentity()],
    });
  });

  afterEach(() => jest.restoreAllMocks());

  it('puts a video list after the cinema box of the API player', () => {
    const host = document.createElement('div');
    host.innerHTML =
      '<div class="reader-cinema"><div class="reader-embed"><iframe src="https://www.youtube-nocookie.com/embed/JsBZOcqZerk?enablejsapi=1&origin=x"></iframe></div></div><p>Notes</p>';

    chapters().attachToFrame(host.querySelector('iframe')!, CHAPTERS);

    expect(host.children[1].localName).toBe('app-embed-chapters');
  });

  it("puts a SoundCloud list after the widget's player bar", () => {
    const host = document.createElement('div');
    host.innerHTML = `<div class="reader-embed reader-embed--strip"><iframe src="${WIDGET_URL}"></iframe></div><app-player-bar class="reader-island"></app-player-bar><p>Notes</p>`;

    chapters().attachToFrame(host.querySelector('iframe')!, CHAPTERS);

    expect(host.children[2].localName).toBe('app-embed-chapters');
  });

  it('adds nothing under a frame that plays no embed', () => {
    const host = document.createElement('div');
    host.innerHTML = '<iframe src="https://player.vimeo.com/video/1"></iframe><p>Notes</p>';

    chapters().attachToFrame(host.querySelector('iframe')!, CHAPTERS);

    expect(host.querySelector('app-embed-chapters')).toBeNull();
  });

  it("stops following an embed's player once its list is released", () => {
    const host = document.createElement('div');
    host.innerHTML =
      '<div class="reader-embed"><iframe src="https://www.youtube-nocookie.com/embed/JsBZOcqZerk?enablejsapi=1&origin=x"></iframe></div><p>Notes</p>';
    const frame = host.querySelector('iframe')!;
    const { articleChapters, islands } = mountedChapters();
    articleChapters.attachToFrame(frame, CHAPTERS);
    openReaderPanels(host);
    const list = host.querySelector('app-embed-chapters')!;
    const removeListener = jest.spyOn(window, 'removeEventListener');

    islands.releaseAll();
    deliverMessage(
      frame,
      JSON.stringify({ event: 'infoDelivery', info: { currentTime: 70 } }),
      'https://www.youtube-nocookie.com',
    );
    TestBed.tick();

    expect(removeListener).toHaveBeenCalledWith('message', expect.any(Function));
    expect(list.querySelector('.reader-chapters__row[aria-current="true"]')).toBeNull();
  });

  it('gives a frame a list again once it forgets the shown ones', () => {
    const host = document.createElement('div');
    host.innerHTML =
      '<div class="reader-embed"><iframe src="https://www.youtube-nocookie.com/embed/JsBZOcqZerk?enablejsapi=1&origin=x"></iframe></div><p>Notes</p>';
    const frame = host.querySelector('iframe')!;
    const { articleChapters, islands } = mountedChapters();
    articleChapters.attachToFrame(frame, CHAPTERS);
    islands.releaseAll();

    articleChapters.forget();
    articleChapters.attachToFrame(frame, NEXT_CHAPTERS);

    expect(host.querySelectorAll('app-embed-chapters')).toHaveLength(1);
    expect(titles(host)).toEqual(['X', 'Y', 'Z']);
    islands.releaseAll();
  });

  describe('followTrack', () => {
    const VIDEO_TRACK = { ...TRACK, url: 'https://www.youtube.com/watch?v=JsBZOcqZerk' };
    const VIDEO_BODY =
      '<div class="reader-cinema"><div class="reader-embed"><iframe src="https://www.youtube-nocookie.com/embed/JsBZOcqZerk?enablejsapi=1&origin=x"></iframe></div></div><p>Notes</p>';

    it('hands a video list to the audio player, leaving it under the video', () => {
      const audio = TestBed.inject(AudioPlayerService);
      const playFrom = jest.spyOn(audio, 'playFrom').mockImplementation(() => undefined);
      const host = document.createElement('div');
      host.innerHTML = VIDEO_BODY;
      const posted = recordPosts(host.querySelector('iframe')!);
      const articleChapters = chapters();
      articleChapters.attachToFrame(host.querySelector('iframe')!, CHAPTERS);

      articleChapters.followTrack(VIDEO_TRACK);
      openReaderPanels(host);
      host.querySelectorAll<HTMLButtonElement>('.reader-chapters__row')[1].click();
      TestBed.tick();

      expect(host.querySelectorAll('app-embed-chapters')).toHaveLength(1);
      expect(host.children[1].localName).toBe('app-embed-chapters');
      expect(titles(host)).toEqual(['A', 'B']);
      expect(playFrom).toHaveBeenCalledWith(VIDEO_TRACK, 60);
      expect(posted).toEqual([]);
    });

    it('keeps an open list open when Listen follows the track', () => {
      const host = document.createElement('div');
      host.innerHTML = VIDEO_BODY;
      const articleChapters = chapters();
      articleChapters.attachToFrame(host.querySelector('iframe')!, CHAPTERS);
      openReaderPanels(host);
      const list = host.querySelector('app-embed-chapters');

      articleChapters.followTrack(VIDEO_TRACK);
      TestBed.tick();

      expect(host.querySelector('app-embed-chapters')).toBe(list);
      expect(host.querySelector('.reader-panel__toggle')?.getAttribute('aria-expanded')).toBe(
        'true',
      );
      expect(host.querySelectorAll('.reader-chapters__row')).toHaveLength(CHAPTERS.length);
    });

    it('follows the audio player once the video is its track', () => {
      const host = document.createElement('div');
      host.innerHTML = VIDEO_BODY;
      const articleChapters = chapters();
      articleChapters.attachToFrame(host.querySelector('iframe')!, CHAPTERS);

      articleChapters.followTrack(VIDEO_TRACK);
      const audio = TestBed.inject(AudioPlayerService);
      audio.enqueue(VIDEO_TRACK);
      audio.seek(70);
      TestBed.tick();
      openReaderPanels(host);

      const current = host.querySelector('.reader-chapters__row[aria-current="true"]');
      expect(current?.querySelector('.reader-chapters__title')?.textContent).toBe('B');
    });

    it('leaves a list on its frame when another track is followed', () => {
      const audio = TestBed.inject(AudioPlayerService);
      const playFrom = jest.spyOn(audio, 'playFrom').mockImplementation(() => undefined);
      const host = document.createElement('div');
      host.innerHTML = VIDEO_BODY;
      const frame = host.querySelector('iframe')!;
      const posted = recordPosts(frame);
      const articleChapters = chapters();
      articleChapters.attachToFrame(frame, CHAPTERS);
      deliverMessage(
        frame,
        JSON.stringify({ event: 'onReady' }),
        'https://www.youtube-nocookie.com',
      );

      articleChapters.followTrack(NEXT_TRACK);
      openReaderPanels(host);
      host.querySelectorAll<HTMLButtonElement>('.reader-chapters__row')[1].click();
      TestBed.tick();

      expect(playFrom).not.toHaveBeenCalled();
      expect(messagesOf(posted)).toContainEqual(
        expect.objectContaining({ func: 'seekTo', args: [60, true] }),
      );
    });
  });

  describe('per embed', () => {
    const VIDEO_SRC = 'https://www.youtube-nocookie.com/embed/JsBZOcqZerk?enablejsapi=1&origin=x';
    const WIDGET_SRC =
      'https://w.soundcloud.com/player/?url=https%3A%2F%2Fapi.soundcloud.com%2Ftracks%2F123';
    const EMBEDS_BODY = `<div class="reader-embed"><iframe src="${VIDEO_SRC}"></iframe></div><p>Between</p><div class="reader-embed"><iframe src="${WIDGET_SRC}"></iframe></div><p>End</p>`;
    const WIDGET_TRACK = { ...TRACK, url: WIDGET_SRC };
    const LIST = CHAPTERS;

    function embedsHost(): {
      host: HTMLElement;
      video: HTMLIFrameElement;
      widget: HTMLIFrameElement;
    } {
      const host = document.createElement('div');
      host.innerHTML = EMBEDS_BODY;
      const [video, widget] = Array.from(host.querySelectorAll('iframe'));
      return { host, video, widget };
    }

    function listAfter(frame: HTMLIFrameElement): Element | null {
      return frame.closest('.reader-embed')?.nextElementSibling ?? null;
    }

    function tapSecondRow(frame: HTMLIFrameElement): void {
      const list = listAfter(frame) as HTMLElement;
      openReaderPanels(list);
      list.querySelectorAll<HTMLButtonElement>('.reader-chapters__row')[1].click();
      TestBed.tick();
    }

    it('finds every API video frame and SoundCloud widget in order', () => {
      const host = document.createElement('div');
      host.innerHTML = `<iframe src="https://www.youtube-nocookie.com/embed/JsBZOcqZerk"></iframe>${EMBEDS_BODY}`;

      expect(chapterFrames(host).map((frame) => frame.getAttribute('src'))).toEqual([
        VIDEO_SRC,
        WIDGET_SRC,
      ]);
    });

    it('puts a list right after each embed', () => {
      const { host, video, widget } = embedsHost();
      const { articleChapters, islands } = mountedChapters();

      articleChapters.attachToFrame(video, LIST);
      articleChapters.attachToFrame(widget, LIST);

      expect(host.querySelectorAll('app-embed-chapters')).toHaveLength(2);
      expect(listAfter(video)?.localName).toBe('app-embed-chapters');
      expect(listAfter(widget)?.localName).toBe('app-embed-chapters');
      islands.releaseAll();
    });

    it('seeks only the video from the video list', () => {
      const { video, widget } = embedsHost();
      const videoPosts = recordPosts(video);
      const widgetPosts = recordPosts(widget);
      const { articleChapters, islands } = mountedChapters();
      articleChapters.attachToFrame(video, LIST);
      articleChapters.attachToFrame(widget, LIST);
      widgetPosts.length = 0;
      deliverMessage(
        video,
        JSON.stringify({ event: 'onReady' }),
        'https://www.youtube-nocookie.com',
      );

      tapSecondRow(video);

      expect(messagesOf(videoPosts)).toContainEqual(
        expect.objectContaining({ func: 'seekTo', args: [60, true] }),
      );
      expect(widgetPosts).toEqual([]);
      islands.releaseAll();
    });

    it('plays only the widget from the widget list, seeking once it plays', () => {
      const { video, widget } = embedsHost();
      const videoPosts = recordPosts(video);
      const widgetPosts = recordPosts(widget);
      const { articleChapters, islands } = mountedChapters();
      articleChapters.attachToFrame(video, LIST);
      articleChapters.attachToFrame(widget, LIST);
      widgetPosts.length = 0;

      tapSecondRow(widget);
      expect(messagesOf(widgetPosts)).toEqual([{ method: 'play' }]);

      deliverMessage(widget, JSON.stringify({ method: 'play' }), SOUNDCLOUD_WIDGET_ORIGIN);
      expect(messagesOf(widgetPosts)).toEqual([
        { method: 'play' },
        { method: 'seekTo', value: 60000 },
      ]);
      expect(videoPosts).toEqual([]);
      islands.releaseAll();
    });

    it("hands only the listened embed's list to the audio player", () => {
      const audio = TestBed.inject(AudioPlayerService);
      const playFrom = jest.spyOn(audio, 'playFrom').mockImplementation(() => undefined);
      const { host, video, widget } = embedsHost();
      const videoPosts = recordPosts(video);
      const widgetPosts = recordPosts(widget);
      const { articleChapters, islands } = mountedChapters();
      articleChapters.attachToFrame(video, LIST);
      articleChapters.attachToFrame(widget, LIST);
      widgetPosts.length = 0;

      articleChapters.followTrack(WIDGET_TRACK);
      tapSecondRow(widget);
      tapSecondRow(video);

      expect(playFrom).toHaveBeenCalledTimes(1);
      expect(playFrom).toHaveBeenCalledWith(WIDGET_TRACK, 60);
      expect(widgetPosts).toEqual([]);
      expect(messagesOf(videoPosts)).toContainEqual({
        event: 'listening',
        id: 1,
        channel: 'widget',
      });
      expect(listAfter(widget)?.localName).toBe('app-embed-chapters');
      expect(host.querySelectorAll('app-embed-chapters')).toHaveLength(2);
      islands.releaseAll();
    });

    it('hands the list of every frame of the listened embed to the audio player', () => {
      const audio = TestBed.inject(AudioPlayerService);
      const playFrom = jest.spyOn(audio, 'playFrom').mockImplementation(() => undefined);
      const host = document.createElement('div');
      const widgetBox = `<div class="reader-embed"><iframe src="${WIDGET_SRC}"></iframe></div>`;
      host.innerHTML = `${widgetBox}<p>Between</p>${widgetBox}`;
      const [first, second] = Array.from(host.querySelectorAll('iframe'));
      const firstPosts = recordPosts(first);
      const secondPosts = recordPosts(second);
      const { articleChapters, islands } = mountedChapters();
      articleChapters.attachToFrame(first, LIST);
      articleChapters.attachToFrame(second, LIST);
      firstPosts.length = 0;
      secondPosts.length = 0;

      articleChapters.followTrack(WIDGET_TRACK);
      tapSecondRow(first);
      tapSecondRow(second);

      expect(playFrom).toHaveBeenCalledTimes(2);
      expect(firstPosts).toEqual([]);
      expect(secondPosts).toEqual([]);
      expect(host.querySelectorAll('app-embed-chapters')).toHaveLength(2);
      islands.releaseAll();
    });

    it('leaves every list on its own player when the listened embed has no list', () => {
      const audio = TestBed.inject(AudioPlayerService);
      const playFrom = jest.spyOn(audio, 'playFrom').mockImplementation(() => undefined);
      const { video, widget } = embedsHost();
      const videoPosts = recordPosts(video);
      const widgetPosts = recordPosts(widget);
      const { articleChapters, islands } = mountedChapters();
      articleChapters.attachToFrame(video, LIST);

      articleChapters.followTrack(WIDGET_TRACK);
      deliverMessage(
        video,
        JSON.stringify({ event: 'onReady' }),
        'https://www.youtube-nocookie.com',
      );
      tapSecondRow(video);

      expect(playFrom).not.toHaveBeenCalled();
      expect(messagesOf(videoPosts)).toContainEqual(
        expect.objectContaining({ func: 'seekTo', args: [60, true] }),
      );
      expect(widgetPosts).toEqual([]);
      islands.releaseAll();
    });

    it('gives an embed one list however often it is attached', () => {
      const { host, video, widget } = embedsHost();
      const { articleChapters, islands } = mountedChapters();
      articleChapters.attachToFrame(video, LIST);

      articleChapters.attachToFrame(video, LIST);
      articleChapters.attachToFrame(widget, LIST);
      articleChapters.attachToFrame(widget, LIST);

      expect(host.querySelectorAll('app-embed-chapters')).toHaveLength(2);
      expect(listAfter(video)?.localName).toBe('app-embed-chapters');
      expect(listAfter(widget)?.localName).toBe('app-embed-chapters');
      islands.releaseAll();
    });

    it('keeps the first list attached to an embed, not a later one', () => {
      const { host, video } = embedsHost();
      const { articleChapters, islands } = mountedChapters();
      articleChapters.attachToFrame(video, LIST);

      articleChapters.attachToFrame(video, NEXT_CHAPTERS);

      expect(host.querySelectorAll('app-embed-chapters')).toHaveLength(1);
      expect(titles(host)).toEqual(['A', 'B']);
      islands.releaseAll();
    });

    it('drives the audio player when the embed is already its track', () => {
      const audio = TestBed.inject(AudioPlayerService);
      audio.enqueue(WIDGET_TRACK);
      const playFrom = jest.spyOn(audio, 'playFrom').mockImplementation(() => undefined);
      const { widget } = embedsHost();
      const widgetPosts = recordPosts(widget);
      const { articleChapters, islands } = mountedChapters();

      articleChapters.attachToFrame(widget, LIST);
      tapSecondRow(widget);

      expect(playFrom).toHaveBeenCalledWith(expect.objectContaining({ url: WIDGET_SRC }), 60);
      expect(widgetPosts).toEqual([]);
      islands.releaseAll();
    });
  });
});
