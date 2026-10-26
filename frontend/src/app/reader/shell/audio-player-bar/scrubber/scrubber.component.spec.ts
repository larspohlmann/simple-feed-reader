import { TestBed } from '@angular/core/testing';
import { By } from '@angular/platform-browser';
import type { ChapterDto } from '../../../models';
import { ScrubberComponent } from './scrubber.component';

const chapters: ChapterDto[] = [
  { startSeconds: 0, title: 'Intro' },
  { startSeconds: 90, title: 'Main' },
  { startSeconds: 300, title: 'Outro' },
];

function render(inputs: Partial<Record<'position' | 'duration' | 'buffered', number>> = {}) {
  TestBed.configureTestingModule({ imports: [ScrubberComponent] });
  const fixture = TestBed.createComponent(ScrubberComponent);
  fixture.componentRef.setInput('position', inputs.position ?? 0);
  fixture.componentRef.setInput('duration', inputs.duration ?? 0);
  fixture.componentRef.setInput('buffered', inputs.buffered ?? 0);
  fixture.componentRef.setInput('label', 'Seek');
  fixture.detectChanges();
  return fixture;
}

function range(fixture: ReturnType<typeof render>): HTMLInputElement {
  return fixture.debugElement.query(By.css('input[type="range"]')).nativeElement;
}

function marks(fixture: ReturnType<typeof render>): HTMLElement[] {
  return fixture.debugElement.queryAll(By.css('.mark')).map((mark) => mark.nativeElement);
}

describe('ScrubberComponent', () => {
  it('drives the range from position and duration, labelled for assistive tech', () => {
    const fixture = render({ position: 30, duration: 120 });

    expect(range(fixture).max).toBe('120');
    expect(range(fixture).value).toBe('30');
    expect(range(fixture).getAttribute('aria-label')).toBe('Seek');
  });

  it('draws the played and the cached share of the track', () => {
    const fixture = render({ position: 50, duration: 200, buffered: 120 });

    expect(range(fixture).style.getPropertyValue('--played')).toBe('25%');
    expect(range(fixture).style.getPropertyValue('--cached')).toBe('60%');
  });

  it('draws one mark per chapter start, decorative only', () => {
    const fixture = render({ duration: 600 });
    fixture.componentRef.setInput('chapters', chapters);
    fixture.detectChanges();

    expect(marks(fixture).map((mark) => mark.style.left)).toEqual(['15%', '50%']);
    expect(
      fixture.debugElement.query(By.css('.marks')).nativeElement.getAttribute('aria-hidden'),
    ).toBe('true');
  });

  it('draws no marks for a track without chapters', () => {
    const fixture = render({ duration: 600 });

    expect(marks(fixture)).toHaveLength(0);
  });

  it('emits a seek when the range is moved', () => {
    const fixture = render({ duration: 120 });
    const seeks: number[] = [];
    fixture.componentInstance.seek.subscribe((seconds) => seeks.push(seconds));

    range(fixture).value = '75';
    range(fixture).dispatchEvent(new Event('input'));

    expect(seeks).toEqual([75]);
  });

  it('reports while it is held', () => {
    const fixture = render({ duration: 120 });
    const held: boolean[] = [];
    fixture.componentInstance.dragging.subscribe((dragging) => held.push(dragging));

    range(fixture).dispatchEvent(new Event('pointerdown'));
    range(fixture).dispatchEvent(new Event('pointerup'));
    range(fixture).dispatchEvent(new Event('pointerdown'));
    range(fixture).dispatchEvent(new Event('pointercancel'));

    expect(held).toEqual([true, false, true, false]);
  });
});
