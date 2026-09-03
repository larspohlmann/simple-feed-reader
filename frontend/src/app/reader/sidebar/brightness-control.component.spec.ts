import { ComponentFixture, TestBed } from '@angular/core/testing';
import { BrightnessService } from '../../theme/brightness.service';
import { provideTranslocoTesting } from '../../../testing/transloco-testing';
import { BrightnessControlComponent } from './brightness-control.component';

type Fixture = ComponentFixture<BrightnessControlComponent>;

describe('BrightnessControlComponent', () => {
  beforeEach(() => {
    localStorage.clear();
    localStorage.setItem('sfr.theme', 'dark');
    TestBed.configureTestingModule({
      imports: [BrightnessControlComponent, provideTranslocoTesting()],
    });
  });

  function create(): Fixture {
    const f = TestBed.createComponent(BrightnessControlComponent);
    f.detectChanges();
    return f;
  }

  const element = <T extends HTMLElement>(f: Fixture, selector: string): T =>
    (f.nativeElement as HTMLElement).querySelector<T>(selector)!;
  const filledCells = (f: Fixture): number =>
    (f.nativeElement as HTMLElement).querySelectorAll('.cell.filled').length;

  function setStep(f: Fixture, step: number): void {
    TestBed.inject(BrightnessService).set(step);
    f.detectChanges();
  }

  it('labels the group and both buttons', () => {
    const f = create();
    expect(element(f, '[role=group]').getAttribute('aria-label')).toBe('Brightness');
    expect(element(f, '.darker').getAttribute('title')).toBe('Darker');
    expect(element(f, '.brighter').getAttribute('title')).toBe('Brighter');
  });

  it('shows a small sun for darker and a big sun for brighter', () => {
    const f = create();
    expect(element(f, '.darker').textContent).toContain('brightness_low');
    expect(element(f, '.brighter').textContent).toContain('brightness_high');
  });

  it('fills four of seven cells at the default and marks the default cell', () => {
    const f = create();
    expect((f.nativeElement as HTMLElement).querySelectorAll('.cell').length).toBe(7);
    expect(filledCells(f)).toBe(4);
    expect(element(f, '.cell.default')).not.toBeNull();
  });

  it('announces the default in words', () => {
    expect(element(create(), 'output').textContent?.trim()).toBe('Brightness default');
  });

  it('steps up on the big sun and announces the signed value', () => {
    const f = create();
    element(f, '.brighter').click();
    f.detectChanges();
    expect(filledCells(f)).toBe(5);
    expect(element(f, 'output').textContent?.trim()).toBe('Brightness +1');
  });

  it('steps down on the small sun and announces the negative value', () => {
    const f = create();
    element(f, '.darker').click();
    f.detectChanges();
    expect(filledCells(f)).toBe(3);
    expect(element(f, 'output').textContent?.trim()).toBe('Brightness -1');
  });

  it('disables the small sun at the bottom of the range', () => {
    const f = create();
    setStep(f, -3);
    expect(element<HTMLButtonElement>(f, '.darker').disabled).toBe(true);
    expect(element<HTMLButtonElement>(f, '.brighter').disabled).toBe(false);
    expect(filledCells(f)).toBe(1);
  });

  it('disables the big sun at the top of the dark range', () => {
    const f = create();
    setStep(f, 3);
    expect(element<HTMLButtonElement>(f, '.brighter').disabled).toBe(true);
    expect(filledCells(f)).toBe(7);
  });

  it('resets to the default when the bar is clicked', () => {
    const f = create();
    setStep(f, 2);
    element(f, '.bar').click();
    f.detectChanges();
    expect(filledCells(f)).toBe(4);
    expect(element(f, '.bar').getAttribute('title')).toBe('Reset to default');
  });

  it('marks the unreachable cells and stops at +1 in light mode', () => {
    localStorage.setItem('sfr.theme', 'light');
    const f = create();
    expect((f.nativeElement as HTMLElement).querySelectorAll('.cell.unavailable').length).toBe(2);
    setStep(f, 1);
    expect(element<HTMLButtonElement>(f, '.brighter').disabled).toBe(true);
    expect(filledCells(f)).toBe(5);
  });
});
