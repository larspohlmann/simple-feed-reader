import { ChapterPlayer, PlaybackPosition } from '../../chapters/chapter-player';
import { addChapterList } from './reader-chapters';

const CHAPTERS = [
  { startSeconds: 0, title: 'Intro' },
  { startSeconds: 256, title: 'How it works' },
  { startSeconds: 3723, title: 'Late' },
];

type FakePlayer = ChapterPlayer & {
  positionTo(seconds: number | null): void;
  progressTo(position: PlaybackPosition): void;
};

function fakePlayer(): FakePlayer {
  let listener: (position: PlaybackPosition | null) => void = () => undefined;
  return {
    playFrom: jest.fn(),
    watch: (onPosition) => (listener = onPosition),
    release: jest.fn(),
    positionTo: (seconds) => listener(seconds === null ? null : { seconds, durationSeconds: null }),
    progressTo: (position) => listener(position),
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

  it('starts collapsed and opens from its toggle', () => {
    const host = document.createElement('div');
    addChapterList(
      { host, after: null },
      { chapters: CHAPTERS, heading: 'Chapters' },
      fakePlayer(),
    );
    const toggle = host.querySelector<HTMLButtonElement>('.reader-chapters__toggle');
    const list = host.querySelector<HTMLElement>('.reader-chapters__list');

    expect(toggle?.textContent).toContain('Chapters');
    expect(toggle?.getAttribute('aria-expanded')).toBe('false');
    expect(list?.hidden).toBe(true);

    toggle?.click();
    expect(toggle?.getAttribute('aria-expanded')).toBe('true');
    expect(list?.hidden).toBe(false);

    toggle?.click();
    expect(list?.hidden).toBe(true);
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

  function progressOf(row: HTMLElement): string {
    return row.style.getPropertyValue('--chapter-progress');
  }

  function timeLeftOf(row: HTMLElement): string | null | undefined {
    return row.querySelector('.reader-chapters__left')?.textContent;
  }

  it('fills the playing chapter and shows its time left', () => {
    const host = document.createElement('div');
    const player = fakePlayer();
    addChapterList({ host, after: null }, { chapters: CHAPTERS, heading: 'Chapters' }, player);

    player.progressTo({ seconds: 128, durationSeconds: null });

    expect(rows(host).map(progressOf)).toEqual(['0.5000', '', '']);
    expect(rows(host).map(timeLeftOf)).toEqual(['2:08', '', '']);
  });

  it('moves fill and time left to the chapter playback moves into', () => {
    const host = document.createElement('div');
    const player = fakePlayer();
    addChapterList({ host, after: null }, { chapters: CHAPTERS, heading: 'Chapters' }, player);

    player.progressTo({ seconds: 128, durationSeconds: 4000 });
    player.progressTo({ seconds: 3800, durationSeconds: 4000 });

    expect(rows(host).map(progressOf)).toEqual(['', '', '0.2780']);
    expect(rows(host).map(timeLeftOf)).toEqual(['', '', '3:20']);
  });

  it('leaves the last chapter unfilled while the duration is unknown', () => {
    const host = document.createElement('div');
    const player = fakePlayer();
    addChapterList({ host, after: null }, { chapters: CHAPTERS, heading: 'Chapters' }, player);

    player.progressTo({ seconds: 3800, durationSeconds: null });

    expect(rows(host)[2].getAttribute('aria-current')).toBe('true');
    expect(progressOf(rows(host)[2])).toBe('');
    expect(timeLeftOf(rows(host)[2])).toBe('');
  });

  it('clears fill and time left of a chapter left by a tap', () => {
    const host = document.createElement('div');
    const player = fakePlayer();
    addChapterList({ host, after: null }, { chapters: CHAPTERS, heading: 'Chapters' }, player);

    player.progressTo({ seconds: 128, durationSeconds: null });
    rows(host)[1].click();

    expect(rows(host).map(progressOf)).toEqual(['', '', '']);
    expect(rows(host).map(timeLeftOf)).toEqual(['', '', '']);
  });

  it('clears fill and time left when playback stops', () => {
    const host = document.createElement('div');
    const player = fakePlayer();
    addChapterList({ host, after: null }, { chapters: CHAPTERS, heading: 'Chapters' }, player);

    player.progressTo({ seconds: 128, durationSeconds: null });
    player.positionTo(null);

    expect(rows(host).map(progressOf)).toEqual(['', '', '']);
    expect(rows(host).map(timeLeftOf)).toEqual(['', '', '']);
  });

  it('updates the playing chapter as playback advances within it', () => {
    const host = document.createElement('div');
    const player = fakePlayer();
    addChapterList({ host, after: null }, { chapters: CHAPTERS, heading: 'Chapters' }, player);

    player.progressTo({ seconds: 128, durationSeconds: null });
    player.progressTo({ seconds: 192, durationSeconds: null });

    expect(progressOf(rows(host)[0])).toBe('0.7500');
    expect(timeLeftOf(rows(host)[0])).toBe('1:04');
  });

  it('clears the last chapter when its duration becomes unknown', () => {
    const host = document.createElement('div');
    const player = fakePlayer();
    addChapterList({ host, after: null }, { chapters: CHAPTERS, heading: 'Chapters' }, player);

    player.progressTo({ seconds: 3800, durationSeconds: 4000 });
    player.progressTo({ seconds: 3800, durationSeconds: null });

    expect(rows(host)[2].getAttribute('aria-current')).toBe('true');
    expect(progressOf(rows(host)[2])).toBe('');
    expect(timeLeftOf(rows(host)[2])).toBe('');
  });

  it('keeps the time left out of the row name a screen reader reads', () => {
    const host = document.createElement('div');
    addChapterList(
      { host, after: null },
      { chapters: CHAPTERS, heading: 'Chapters' },
      fakePlayer(),
    );

    const timeLeft = rows(host)[0].querySelector('.reader-chapters__left');
    expect(timeLeft?.getAttribute('aria-hidden')).toBe('true');
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
