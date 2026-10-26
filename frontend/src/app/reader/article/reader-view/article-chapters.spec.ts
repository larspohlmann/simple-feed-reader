import { Injector } from '@angular/core';
import { TestBed } from '@angular/core/testing';
import { recordPosts } from '../../../../testing/frame-playback-testing';
import { AudioPlayerService } from '../../audio-player.service';
import { ArticleChapters } from './article-chapters';

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
});
