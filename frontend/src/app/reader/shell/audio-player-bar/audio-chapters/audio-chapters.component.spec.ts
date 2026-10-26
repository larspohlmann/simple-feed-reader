import { TestBed } from '@angular/core/testing';
import { By } from '@angular/platform-browser';
import type { ChapterProgress } from '../../../chapters/current-chapter';
import type { ChapterDto } from '../../../models';
import { AudioChaptersComponent } from './audio-chapters.component';

const chapters: ChapterDto[] = [
  { startSeconds: 0, title: 'Intro' },
  { startSeconds: 256, title: 'How it works' },
  { startSeconds: 3723, title: 'Late' },
];

interface Inputs {
  currentIndex?: number;
  progress?: ChapterProgress | null;
}

function render({ currentIndex, progress }: Inputs = {}) {
  TestBed.configureTestingModule({ imports: [AudioChaptersComponent] });
  const fixture = TestBed.createComponent(AudioChaptersComponent);
  fixture.componentRef.setInput('chapters', chapters);
  if (currentIndex !== undefined) fixture.componentRef.setInput('currentIndex', currentIndex);
  if (progress !== undefined) fixture.componentRef.setInput('progress', progress);
  fixture.detectChanges();
  return fixture;
}

function rows(fixture: ReturnType<typeof render>): HTMLButtonElement[] {
  return fixture.debugElement
    .queryAll(By.css('.reader-chapters__row'))
    .map((row) => row.nativeElement);
}

function timeLeft(row: HTMLElement): HTMLElement {
  return row.querySelector<HTMLElement>('.reader-chapters__left')!;
}

describe('AudioChaptersComponent', () => {
  it('lists every chapter with its start time, none marked', () => {
    const fixture = render();

    expect(rows(fixture).map((row) => row.textContent!.replace(/\s+/g, ''))).toEqual([
      '0:00Intro',
      '4:16Howitworks',
      '1:02:03Late',
    ]);
    expect(rows(fixture).some((row) => row.hasAttribute('aria-current'))).toBe(false);
    expect(rows(fixture).every((row) => timeLeft(row).getAttribute('aria-hidden') === 'true')).toBe(
      true,
    );
  });

  it('marks the current row, fills it from the progress and shows its time left', () => {
    const fixture = render({ currentIndex: 1, progress: { fraction: 0.25, secondsLeft: 2600 } });
    const [intro, current, late] = rows(fixture);

    expect(current.getAttribute('aria-current')).toBe('true');
    expect(current.style.getPropertyValue('--chapter-progress')).toBe('0.25');
    expect(timeLeft(current).textContent!.replace(/\s+/g, '')).toBe('schedule43:20');
    expect(timeLeft(current).querySelector('app-icon')).not.toBeNull();
    for (const other of [intro, late]) {
      expect(other.hasAttribute('aria-current')).toBe(false);
      expect(other.style.getPropertyValue('--chapter-progress')).toBe('');
      expect(timeLeft(other).textContent).toBe('');
    }
  });

  it('marks the current row without a fill while the chapter end is unknown', () => {
    const fixture = render({ currentIndex: 2, progress: null });
    const current = rows(fixture)[2];

    expect(current.getAttribute('aria-current')).toBe('true');
    expect(current.style.getPropertyValue('--chapter-progress')).toBe('');
    expect(timeLeft(current).textContent).toBe('');
  });

  it('follows the current row as the inputs move on', () => {
    const fixture = render({ currentIndex: 0, progress: { fraction: 0.5, secondsLeft: 128 } });

    fixture.componentRef.setInput('currentIndex', 1);
    fixture.componentRef.setInput('progress', { fraction: 0.1, secondsLeft: 3120 });
    fixture.detectChanges();

    const [intro, current] = rows(fixture);
    expect(intro.hasAttribute('aria-current')).toBe(false);
    expect(intro.style.getPropertyValue('--chapter-progress')).toBe('');
    expect(current.getAttribute('aria-current')).toBe('true');
    expect(current.style.getPropertyValue('--chapter-progress')).toBe('0.1');
    expect(timeLeft(current).textContent).toContain('52:00');
  });

  it('emits the index of a tapped row', () => {
    const fixture = render();
    const picked: number[] = [];
    fixture.componentInstance.pick.subscribe((index) => picked.push(index));

    rows(fixture)[2].click();
    rows(fixture)[0].click();

    expect(picked).toEqual([2, 0]);
  });
});
