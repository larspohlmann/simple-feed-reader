import { TestBed } from '@angular/core/testing';
import { provideTranslocoTesting } from '../../../../testing/transloco-testing';
import { ChapterPlayer, PlaybackPosition } from '../../chapters/chapter-player';
import { EmbedChaptersComponent } from './embed-chapters.component';

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

function render(player: ChapterPlayer = fakePlayer()) {
  TestBed.configureTestingModule({ imports: [EmbedChaptersComponent, provideTranslocoTesting()] });
  const fixture = TestBed.createComponent(EmbedChaptersComponent);
  fixture.componentRef.setInput('chapters', CHAPTERS);
  fixture.componentRef.setInput('player', player);
  fixture.detectChanges();
  const element = fixture.nativeElement as HTMLElement;
  const open = (): void => {
    element.querySelector<HTMLButtonElement>('.reader-panel__toggle')!.click();
    fixture.detectChanges();
  };
  return { fixture, element, open };
}

const rows = (element: HTMLElement) =>
  Array.from(element.querySelectorAll<HTMLButtonElement>('.reader-chapters__row'));
const progressOf = (row: HTMLElement) => row.style.getPropertyValue('--chapter-progress');
const timeLeftOf = (row: HTMLElement) =>
  row.querySelector('.reader-chapters__remaining')?.textContent ?? '';

describe('EmbedChaptersComponent', () => {
  it('is a chapter list labelled Chapters, collapsed until its toggle opens it', () => {
    const { element, open } = render();
    const toggle = element.querySelector<HTMLButtonElement>('.reader-panel__toggle')!;

    expect(element.classList).toContain('reader-chapters');
    expect(element.querySelector('nav')!.getAttribute('aria-label')).toBe('Chapters');
    expect(toggle.textContent?.trim()).toContain('Chapters');
    expect(toggle.getAttribute('aria-expanded')).toBe('false');
    expect(rows(element)).toHaveLength(0);

    open();

    expect(toggle.getAttribute('aria-expanded')).toBe('true');
    expect(rows(element).map((row) => row.textContent?.replace(/\s+/g, ''))).toEqual([
      '0:00Intro',
      '4:16Howitworks',
      '1:02:03Late',
    ]);
  });

  it('plays from a tapped chapter and marks it at once', () => {
    const player = fakePlayer();
    const { fixture, element, open } = render(player);
    open();

    rows(element)[1].click();
    fixture.detectChanges();

    expect(player.playFrom).toHaveBeenCalledWith(256);
    expect(rows(element).map((row) => row.getAttribute('aria-current'))).toEqual([
      null,
      'true',
      null,
    ]);
  });

  it('follows the position the player reports', () => {
    const player = fakePlayer();
    const { fixture, element, open } = render(player);
    open();

    player.positionTo(4000);
    fixture.detectChanges();
    expect(rows(element)[2].getAttribute('aria-current')).toBe('true');

    player.positionTo(null);
    fixture.detectChanges();
    expect(rows(element).some((row) => row.hasAttribute('aria-current'))).toBe(false);
  });

  it('fills the playing chapter and shows its time left behind a clock', () => {
    const player = fakePlayer();
    const { fixture, element, open } = render(player);
    open();

    player.progressTo({ seconds: 128, durationSeconds: null });
    fixture.detectChanges();

    expect(rows(element).map(progressOf)).toEqual(['0.5', '', '']);
    expect(rows(element).map(timeLeftOf)).toEqual(['2:08', '', '']);
    expect(rows(element)[0].querySelector('.reader-chapters__left app-icon')).not.toBeNull();
  });

  it('moves fill and time left to the chapter playback moves into', () => {
    const player = fakePlayer();
    const { fixture, element, open } = render(player);
    open();

    player.progressTo({ seconds: 128, durationSeconds: 4000 });
    player.progressTo({ seconds: 3800, durationSeconds: 4000 });
    fixture.detectChanges();

    const [first, second, last] = rows(element).map(progressOf);
    expect([first, second]).toEqual(['', '']);
    expect(Number(last)).toBeCloseTo(0.278, 3);
    expect(rows(element).map(timeLeftOf)).toEqual(['', '', '3:20']);
  });

  it('leaves the last chapter unfilled while the duration is unknown', () => {
    const player = fakePlayer();
    const { fixture, element, open } = render(player);
    open();

    player.progressTo({ seconds: 3800, durationSeconds: null });
    fixture.detectChanges();

    expect(rows(element)[2].getAttribute('aria-current')).toBe('true');
    expect(progressOf(rows(element)[2])).toBe('');
    expect(timeLeftOf(rows(element)[2])).toBe('');
  });

  it('clears fill and time left of a chapter left by a tap', () => {
    const player = fakePlayer();
    const { fixture, element, open } = render(player);
    open();

    player.progressTo({ seconds: 128, durationSeconds: null });
    rows(element)[1].click();
    fixture.detectChanges();

    expect(rows(element).map(progressOf)).toEqual(['', '', '']);
    expect(rows(element).map(timeLeftOf)).toEqual(['', '', '']);
  });

  it('follows a new player once it is handed one, starting unmarked', () => {
    const first = fakePlayer();
    const next = fakePlayer();
    const { fixture, element, open } = render(first);
    open();
    first.positionTo(300);
    fixture.detectChanges();

    fixture.componentRef.setInput('player', next);
    fixture.detectChanges();
    expect(rows(element).some((row) => row.hasAttribute('aria-current'))).toBe(false);
    expect(element.querySelector('.reader-panel__toggle')!.getAttribute('aria-expanded')).toBe(
      'true',
    );

    next.positionTo(4000);
    rows(element)[0].click();
    fixture.detectChanges();
    expect(next.playFrom).toHaveBeenCalledWith(0);
    expect(first.playFrom).not.toHaveBeenCalled();
  });

  it('releases the player it follows when handed another', () => {
    const first = fakePlayer();
    const next = fakePlayer();
    const { fixture } = render(first);

    fixture.componentRef.setInput('player', next);
    fixture.detectChanges();

    expect(first.release).toHaveBeenCalledTimes(1);
    expect(next.release).not.toHaveBeenCalled();
  });

  it('releases its player when destroyed', () => {
    const player = fakePlayer();
    const { fixture } = render(player);

    fixture.destroy();

    expect(player.release).toHaveBeenCalled();
  });
});
