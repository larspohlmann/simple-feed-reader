import { signal } from '@angular/core';
import { TestBed } from '@angular/core/testing';
import { By } from '@angular/platform-browser';
import { CdkDragDrop, CdkDropList } from '@angular/cdk/drag-drop';
import { provideTranslocoTesting } from '../../../../../testing/transloco-testing';
import { AudioPlayerService, AudioTrack } from '../../../audio-player.service';
import type { ChapterPlayhead } from '../../../audio/track-chapters';
import type { ChapterDto } from '../../../models';
import { AudioPlaylistComponent } from './audio-playlist.component';

const first: AudioTrack = {
  url: 'https://x.test/ep.mp3',
  title: 'Episode 1',
  faviconUrl: null,
  imageUrl: null,
  durationInSeconds: 120,
};
const chapters: ChapterDto[] = [
  { startSeconds: 0, title: 'Intro' },
  { startSeconds: 60, title: 'Main part' },
];
const second: AudioTrack = {
  ...first,
  url: 'https://x.test/two.mp3',
  title: 'Episode 2',
  chapters,
};
const third: AudioTrack = {
  ...first,
  url: 'https://x.test/three.mp3',
  title: 'Episode 3',
  durationInSeconds: null,
  chapters: [...chapters, { startSeconds: 100, title: 'Outro' }],
};

function stub() {
  return {
    tracks: signal<AudioTrack[]>([first, second, third]),
    current: signal<AudioTrack | null>(second),
    index: signal(1),
    duration: signal(0),
    chapterControls: { playhead: signal<ChapterPlayhead | null>(null) },
    playAt: jest.fn(),
    move: jest.fn(),
    dequeue: jest.fn(),
    playTrackChapter: jest.fn(),
  };
}

let player: ReturnType<typeof stub>;

function render() {
  player = stub();
  TestBed.configureTestingModule({
    imports: [AudioPlaylistComponent, provideTranslocoTesting()],
    providers: [{ provide: AudioPlayerService, useValue: player }],
  });
  const fixture = TestBed.createComponent(AudioPlaylistComponent);
  fixture.detectChanges();
  return fixture;
}

function rows(fixture: ReturnType<typeof render>): HTMLElement[] {
  return fixture.debugElement.queryAll(By.css('.row')).map((row) => row.nativeElement);
}

function click(row: HTMLElement, selector: string): void {
  (row.querySelector(selector) as HTMLButtonElement).click();
}

function nestedChapters(row: HTMLElement): HTMLElement | null {
  return row.querySelector<HTMLElement>('app-audio-chapters');
}

function nestedRows(row: HTMLElement): HTMLButtonElement[] {
  return Array.from(row.querySelectorAll<HTMLButtonElement>('.reader-chapters__row'));
}

function expandButton(row: HTMLElement): HTMLButtonElement {
  return row.querySelector<HTMLButtonElement>('.expand')!;
}

describe('AudioPlaylistComponent', () => {
  it('lists every track with its length, marking the current and the played ones', () => {
    const [earlier, current, later] = rows(render());

    expect(current.getAttribute('aria-current')).toBe('true');
    expect(earlier.classList).toContain('played');
    expect(later.classList).not.toContain('played');
    expect(earlier.textContent).toContain('2:00');
    expect(later.querySelector('.row-time')).toBeNull();
  });

  it('shows the current row with the duration the element reports', () => {
    const fixture = render();
    player.duration.set(150);
    fixture.detectChanges();

    expect(rows(fixture)[1].querySelector('.row-time')?.textContent).toContain('2:30');
  });

  it('plays, moves and removes from the row controls', () => {
    const [earlier, current, later] = rows(render());

    click(later, '.pick');
    click(current, '.up');
    click(current, '.down');
    click(earlier, '.remove');

    expect(player.playAt).toHaveBeenCalledWith(2);
    expect(player.move).toHaveBeenNthCalledWith(1, 1, 0);
    expect(player.move).toHaveBeenNthCalledWith(2, 1, 2);
    expect(player.dequeue).toHaveBeenCalledWith(first.url);
  });

  it('disables moving past either end', () => {
    const [earlier, , later] = rows(render());

    expect((earlier.querySelector('.up') as HTMLButtonElement).disabled).toBe(true);
    expect((later.querySelector('.down') as HTMLButtonElement).disabled).toBe(true);
  });

  it('moves a dragged row to where it was dropped', () => {
    const fixture = render();

    const list = fixture.debugElement.query(By.directive(CdkDropList)).injector.get(CdkDropList);
    list.dropped.emit({ previousIndex: 2, currentIndex: 0 } as CdkDragDrop<AudioTrack[]>);

    expect(player.move).toHaveBeenCalledWith(2, 0);
  });

  it('shows neither a chapter count nor a chevron on a row without chapters', () => {
    const [earlier] = rows(render());

    expect(earlier.querySelector('.row-chapters')).toBeNull();
    expect(earlier.querySelector('.expand')).toBeNull();
    expect(nestedChapters(earlier)).toBeNull();
  });

  it("counts a chaptered row's chapters after its length", () => {
    const [, current, later] = rows(render());

    expect(current.querySelector('.row-chapters')?.textContent).toContain('2');
    expect(current.querySelector('.row-chapters')?.getAttribute('aria-label')).toBe('Chapters (2)');
    expect(later.querySelector('.row-chapters')?.textContent).toContain('3');
    expect(expandButton(later).getAttribute('aria-label')).toBe('Chapters of Episode 3');
  });

  it('keeps every chapter list closed until its chevron is tapped, the playing one too', () => {
    const [, current, later] = rows(render());

    expect(nestedChapters(current)).toBeNull();
    expect(expandButton(current).getAttribute('aria-expanded')).toBe('false');
    expect(nestedChapters(later)).toBeNull();
    expect(expandButton(later).getAttribute('aria-expanded')).toBe('false');
  });

  it("opens and closes a track's chapters from its chevron", () => {
    const fixture = render();
    const later = rows(fixture)[2];

    click(later, '.expand');
    fixture.detectChanges();
    expect(nestedRows(later)).toHaveLength(3);
    expect(expandButton(later).getAttribute('aria-expanded')).toBe('true');
    expect(nestedChapters(later)?.classList).toContain('nested');

    click(later, '.expand');
    fixture.detectChanges();
    expect(nestedChapters(later)).toBeNull();
    expect(expandButton(later).getAttribute('aria-expanded')).toBe('false');
  });

  it('keeps the drag target of the nested chapters inside the row', () => {
    const fixture = render();
    const current = rows(fixture)[1];
    click(current, '.expand');
    fixture.detectChanges();

    expect(nestedChapters(current)?.closest('.row')).toBe(current);
    expect(nestedChapters(current)?.hasAttribute('cdkdroplist')).toBe(false);
  });

  it('plays another track from a tapped chapter of its list', () => {
    const fixture = render();
    const later = rows(fixture)[2];
    click(later, '.expand');
    fixture.detectChanges();

    nestedRows(later)[1].click();

    expect(player.playTrackChapter).toHaveBeenCalledWith(2, 1);
  });

  it("hands only the current row's list the service's chapter and progress", () => {
    const fixture = render();
    player.chapterControls.playhead.set({ index: 1, progress: { fraction: 0.5, secondsLeft: 30 } });
    const [, current, later] = rows(fixture);
    click(current, '.expand');
    click(later, '.expand');
    fixture.detectChanges();

    const [intro, main] = nestedRows(current);
    expect(intro.hasAttribute('aria-current')).toBe(false);
    expect(main.getAttribute('aria-current')).toBe('true');
    expect(main.style.getPropertyValue('--chapter-progress')).toBe('0.5');
    expect(nestedRows(later).some((row) => row.hasAttribute('aria-current'))).toBe(false);
  });
});
