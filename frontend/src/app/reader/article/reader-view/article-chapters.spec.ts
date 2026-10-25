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

  it('adds nothing without a player to drive', () => {
    const host = document.createElement('div');
    host.innerHTML = '<p>Notes</p>';

    chapters().attach(host, { chapters: CHAPTERS, heading: 'Chapters' }, null);

    expect(host.querySelector('.reader-chapters')).toBeNull();
  });
});
