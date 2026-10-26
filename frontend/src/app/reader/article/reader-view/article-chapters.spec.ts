import { Injector } from '@angular/core';
import { TestBed } from '@angular/core/testing';
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
