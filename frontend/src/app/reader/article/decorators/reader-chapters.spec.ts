import { ChapterPlayer } from '../../chapters/chapter-player';
import { addChapterList } from './reader-chapters';

const CHAPTERS = [
  { startSeconds: 0, title: 'Intro' },
  { startSeconds: 256, title: 'How it works' },
  { startSeconds: 3723, title: 'Late' },
];

function fakePlayer(): ChapterPlayer & { positionTo(seconds: number | null): void } {
  let listener: (seconds: number | null) => void = () => undefined;
  return {
    playFrom: jest.fn(),
    watch: (onPosition) => (listener = onPosition),
    release: jest.fn(),
    positionTo: (seconds) => listener(seconds),
  };
}

function rows(host: HTMLElement): HTMLButtonElement[] {
  return Array.from(host.querySelectorAll<HTMLButtonElement>('.reader-chapters__row'));
}

describe('addChapterList', () => {
  it('lists every chapter with its start time after the given element', () => {
    const host = document.createElement('div');
    host.innerHTML = '<div class="player"></div><p>Notes</p>';

    addChapterList(
      { host, after: host.querySelector('.player') },
      { chapters: CHAPTERS, heading: 'Chapters' },
      fakePlayer(),
    );

    expect(host.children[1].matches('nav.reader-chapters[aria-label="Chapters"]')).toBe(true);
    expect(rows(host).map((row) => row.textContent)).toEqual([
      '0:00Intro',
      '4:16How it works',
      '1:02:03Late',
    ]);
  });

  it('goes first when there is nothing to follow', () => {
    const host = document.createElement('div');
    host.innerHTML = '<p>Notes</p>';

    addChapterList(
      { host, after: null },
      { chapters: CHAPTERS, heading: 'Chapters' },
      fakePlayer(),
    );

    expect(host.firstElementChild?.classList.contains('reader-chapters')).toBe(true);
  });

  it('plays from a tapped chapter and marks it at once', () => {
    const host = document.createElement('div');
    const player = fakePlayer();
    addChapterList({ host, after: null }, { chapters: CHAPTERS, heading: 'Chapters' }, player);

    rows(host)[1].click();

    expect(player.playFrom).toHaveBeenCalledWith(256);
    expect(rows(host).map((row) => row.getAttribute('aria-current'))).toEqual([null, 'true', null]);
  });

  it('follows the position the player reports', () => {
    const host = document.createElement('div');
    const player = fakePlayer();
    addChapterList({ host, after: null }, { chapters: CHAPTERS, heading: 'Chapters' }, player);

    player.positionTo(4000);
    expect(rows(host)[2].getAttribute('aria-current')).toBe('true');
    player.positionTo(null);
    expect(rows(host).some((row) => row.hasAttribute('aria-current'))).toBe(false);
  });

  it('adds nothing for no chapters', () => {
    const host = document.createElement('div');

    expect(
      addChapterList({ host, after: null }, { chapters: [], heading: 'Chapters' }, fakePlayer()),
    ).toBeNull();
    expect(host.querySelector('.reader-chapters')).toBeNull();
  });

  it('replaces an earlier list with one that drives its own player', () => {
    const host = document.createElement('div');
    const earlier = fakePlayer();
    const later = fakePlayer();
    addChapterList({ host, after: null }, { chapters: CHAPTERS, heading: 'Chapters' }, earlier);

    const list = addChapterList(
      { host, after: null },
      { chapters: CHAPTERS.slice(0, 2), heading: 'Chapters' },
      later,
    );

    expect(Array.from(host.querySelectorAll('.reader-chapters'))).toEqual([list]);
    rows(host)[1].click();
    expect(later.playFrom).toHaveBeenCalledWith(256);
    expect(earlier.playFrom).not.toHaveBeenCalled();
  });
});
