import { Injector } from '@angular/core';
import { TestBed } from '@angular/core/testing';
import {
  deliverMessage,
  messagesOf,
  recordPosts,
} from '../../../../testing/frame-playback-testing';
import { AudioPlayerService } from '../../audio-player.service';
import { SOUNDCLOUD_WIDGET_ORIGIN } from '../../media/soundcloud-widget-protocol';
import { ArticleChapters, chapterFrames } from './article-chapters';

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
  return Array.from(host.querySelectorAll('.reader-chapters__title')).map(
    (title) => title.textContent ?? '',
  );
}

function chapters(): ArticleChapters {
  return new ArticleChapters(TestBed.inject(AudioPlayerService), TestBed.inject(Injector));
}

describe('ArticleChapters', () => {
  beforeEach(() => localStorage.clear());

  it('puts a video list after the cinema box of the API player', () => {
    const host = document.createElement('div');
    host.innerHTML =
      '<div class="reader-cinema"><div class="reader-embed"><iframe src="https://www.youtube-nocookie.com/embed/JsBZOcqZerk?enablejsapi=1&origin=x"></iframe></div></div><p>Notes</p>';

    chapters().attach(host, { chapters: CHAPTERS, heading: 'Chapters' }, null);

    expect(host.children[1].classList.contains('reader-chapters')).toBe(true);
  });

  it('puts an episode list first', () => {
    const host = document.createElement('div');
    host.innerHTML = '<p>Notes</p>';

    chapters().attach(host, { chapters: CHAPTERS, heading: 'Chapters' }, TRACK);

    expect(host.firstElementChild?.classList.contains('reader-chapters')).toBe(true);
  });

  it('drives the episode, not a video its notes embed', () => {
    const audio = TestBed.inject(AudioPlayerService);
    const playFrom = jest.spyOn(audio, 'playFrom').mockImplementation(() => undefined);
    const host = document.createElement('div');
    host.innerHTML =
      '<div class="reader-cinema"><div class="reader-embed"><iframe src="https://www.youtube-nocookie.com/embed/JsBZOcqZerk?enablejsapi=1&origin=x"></iframe></div></div><p>Notes</p>';

    chapters().attach(host, { chapters: CHAPTERS, heading: 'Chapters' }, TRACK);
    host.querySelectorAll<HTMLButtonElement>('.reader-chapters__row')[1].click();

    expect(host.firstElementChild?.classList.contains('reader-chapters')).toBe(true);
    expect(playFrom).toHaveBeenCalledWith(TRACK, 60);
  });

  it('adds nothing without a player to drive', () => {
    const host = document.createElement('div');
    host.innerHTML = '<p>Notes</p>';

    chapters().attach(host, { chapters: CHAPTERS, heading: 'Chapters' }, null);

    expect(host.querySelector('.reader-chapters')).toBeNull();
  });

  it('takes its list away on release', () => {
    const host = document.createElement('div');
    host.innerHTML = '<p>Notes</p>';
    const articleChapters = chapters();
    articleChapters.attach(host, { chapters: CHAPTERS, heading: 'Chapters' }, TRACK);

    articleChapters.release();

    expect(host.querySelector('.reader-chapters')).toBeNull();
    expect(host.innerHTML).toBe('<p>Notes</p>');
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
      articleChapters.attach(host, { chapters: CHAPTERS, heading: 'Chapters' }, null);

      articleChapters.followTrack(VIDEO_TRACK);
      host.querySelectorAll<HTMLButtonElement>('.reader-chapters__row')[1].click();

      expect(host.querySelectorAll('.reader-chapters')).toHaveLength(1);
      expect(host.children[1].classList.contains('reader-chapters')).toBe(true);
      expect(titles(host)).toEqual(['A', 'B']);
      expect(playFrom).toHaveBeenCalledWith(VIDEO_TRACK, 60);
      expect(posted).toEqual([]);
    });

    it('follows the audio player once the video is its track', () => {
      const host = document.createElement('div');
      host.innerHTML = VIDEO_BODY;
      const articleChapters = chapters();
      articleChapters.attach(host, { chapters: CHAPTERS, heading: 'Chapters' }, null);

      articleChapters.followTrack(VIDEO_TRACK);
      const audio = TestBed.inject(AudioPlayerService);
      audio.enqueue(VIDEO_TRACK);
      audio.seek(70);
      TestBed.tick();

      const current = host.querySelector('.reader-chapters__row[aria-current="true"]');
      expect(current?.querySelector('.reader-chapters__title')?.textContent).toBe('B');
    });

    it('does nothing without a list to hand over', () => {
      const host = document.createElement('div');
      host.innerHTML = '<p>Notes</p>';
      const articleChapters = chapters();
      articleChapters.attach(host, { chapters: CHAPTERS, heading: 'Chapters' }, null);

      articleChapters.followTrack(VIDEO_TRACK);

      expect(host.innerHTML).toBe('<p>Notes</p>');
    });
  });

  it('draws the next body its own list, following the new player', () => {
    const host = document.createElement('div');
    host.innerHTML = '<p>Notes</p>';
    const articleChapters = chapters();
    articleChapters.attach(host, { chapters: CHAPTERS, heading: 'Chapters' }, TRACK);

    articleChapters.attach(host, { chapters: NEXT_CHAPTERS, heading: 'Chapters' }, NEXT_TRACK);
    const audio = TestBed.inject(AudioPlayerService);
    audio.enqueue(NEXT_TRACK);
    audio.seek(45);
    TestBed.tick();

    expect(host.querySelectorAll('.reader-chapters')).toHaveLength(1);
    expect(titles(host)).toEqual(['X', 'Y', 'Z']);
    const current = host.querySelector('.reader-chapters__row[aria-current="true"]');
    expect(current?.querySelector('.reader-chapters__time')?.textContent).toBe('0:30');
    expect(current?.querySelector('.reader-chapters__title')?.textContent).toBe('Y');
  });

  describe('per embed', () => {
    const VIDEO_SRC = 'https://www.youtube-nocookie.com/embed/JsBZOcqZerk?enablejsapi=1&origin=x';
    const WIDGET_SRC =
      'https://w.soundcloud.com/player/?url=https%3A%2F%2Fapi.soundcloud.com%2Ftracks%2F123';
    const EMBEDS_BODY = `<div class="reader-embed"><iframe src="${VIDEO_SRC}"></iframe></div><p>Between</p><div class="reader-embed"><iframe src="${WIDGET_SRC}"></iframe></div><p>End</p>`;
    const WIDGET_TRACK = { ...TRACK, url: WIDGET_SRC };
    const LIST = { chapters: CHAPTERS, heading: 'Chapters' };

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
      listAfter(frame)?.querySelectorAll<HTMLButtonElement>('.reader-chapters__row')[1].click();
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
      const articleChapters = chapters();

      articleChapters.attachToFrame(video, LIST);
      articleChapters.attachToFrame(widget, LIST);

      expect(host.querySelectorAll('.reader-chapters')).toHaveLength(2);
      expect(listAfter(video)?.classList.contains('reader-chapters')).toBe(true);
      expect(listAfter(widget)?.classList.contains('reader-chapters')).toBe(true);
      articleChapters.release();
    });

    it('seeks only the video from the video list', () => {
      const { video, widget } = embedsHost();
      const videoPosts = recordPosts(video);
      const widgetPosts = recordPosts(widget);
      const articleChapters = chapters();
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
      articleChapters.release();
    });

    it('plays only the widget from the widget list, seeking once it plays', () => {
      const { video, widget } = embedsHost();
      const videoPosts = recordPosts(video);
      const widgetPosts = recordPosts(widget);
      const articleChapters = chapters();
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
      articleChapters.release();
    });

    it("keeps the body's own list beside an embed's list", () => {
      const { host, widget } = embedsHost();
      const articleChapters = chapters();

      articleChapters.attach(host, LIST, null);
      articleChapters.attachToFrame(widget, LIST);

      expect(host.querySelectorAll('.reader-chapters')).toHaveLength(2);
      articleChapters.release();
    });

    it("hands only the listened embed's list to the audio player", () => {
      const audio = TestBed.inject(AudioPlayerService);
      const playFrom = jest.spyOn(audio, 'playFrom').mockImplementation(() => undefined);
      const { host, video, widget } = embedsHost();
      const videoPosts = recordPosts(video);
      const widgetPosts = recordPosts(widget);
      const articleChapters = chapters();
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
      expect(listAfter(widget)?.classList.contains('reader-chapters')).toBe(true);
      expect(host.querySelectorAll('.reader-chapters')).toHaveLength(2);
      articleChapters.release();
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
      const articleChapters = chapters();
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
      expect(host.querySelectorAll('.reader-chapters')).toHaveLength(2);
      articleChapters.release();
    });

    it('leaves every list on its own player when the listened embed has no list', () => {
      const audio = TestBed.inject(AudioPlayerService);
      const playFrom = jest.spyOn(audio, 'playFrom').mockImplementation(() => undefined);
      const { host, video, widget } = embedsHost();
      const videoPosts = recordPosts(video);
      const widgetPosts = recordPosts(widget);
      const articleChapters = chapters();
      articleChapters.attach(host, LIST, null);

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
      articleChapters.release();
    });

    it('gives an embed one list however often it is attached', () => {
      const { host, video, widget } = embedsHost();
      const articleChapters = chapters();
      articleChapters.attach(host, LIST, null);

      articleChapters.attachToFrame(video, LIST);
      articleChapters.attachToFrame(widget, LIST);
      articleChapters.attachToFrame(widget, LIST);

      expect(host.querySelectorAll('.reader-chapters')).toHaveLength(2);
      expect(listAfter(video)?.classList.contains('reader-chapters')).toBe(true);
      expect(listAfter(widget)?.classList.contains('reader-chapters')).toBe(true);
      articleChapters.release();
    });

    it('drives the audio player when the embed is already its track', () => {
      const audio = TestBed.inject(AudioPlayerService);
      audio.enqueue(WIDGET_TRACK);
      const playFrom = jest.spyOn(audio, 'playFrom').mockImplementation(() => undefined);
      const { widget } = embedsHost();
      const widgetPosts = recordPosts(widget);
      const articleChapters = chapters();

      articleChapters.attachToFrame(widget, LIST);
      tapSecondRow(widget);

      expect(playFrom).toHaveBeenCalledWith(expect.objectContaining({ url: WIDGET_SRC }), 60);
      expect(widgetPosts).toEqual([]);
      articleChapters.release();
    });

    it('takes every list away on release', () => {
      const { host, widget } = embedsHost();
      const articleChapters = chapters();
      articleChapters.attach(host, LIST, null);
      articleChapters.attachToFrame(widget, LIST);

      articleChapters.release();

      expect(host.querySelector('.reader-chapters')).toBeNull();
      expect(host.innerHTML).toBe(EMBEDS_BODY.replace('&origin', '&amp;origin'));
    });
  });
});
