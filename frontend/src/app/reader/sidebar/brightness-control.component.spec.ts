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
  const fill = (f: Fixture): number =>
    parseFloat(element(f, '.fill').style.getPropertyValue('inline-size'));

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

  it('shows a moon for darker and a sun for brighter', () => {
    const f = create();
    expect(element(f, '.darker').textContent).toContain('dark_mode');
    expect(element(f, '.brighter').textContent).toContain('light_mode');
  });

  it('half-fills the bar at the dark default', () => {
    expect(fill(create())).toBeCloseTo(50);
  });

  it('announces the default in words', () => {
    expect(element(create(), 'output').textContent?.trim()).toBe('Brightness default');
  });

  it('steps up on the sun and announces the signed value', () => {
    const f = create();
    element(f, '.brighter').click();
    f.detectChanges();
    expect(fill(f)).toBeCloseTo(200 / 3);
    expect(element(f, 'output').textContent?.trim()).toBe('Brightness +1');
  });

  it('steps down on the moon and announces the negative value', () => {
    const f = create();
    element(f, '.darker').click();
    f.detectChanges();
    expect(fill(f)).toBeCloseTo(100 / 3);
    expect(element(f, 'output').textContent?.trim()).toBe('Brightness -1');
  });

  it('empties the bar and disables the moon at the bottom of the range', () => {
    const f = create();
    setStep(f, -3);
    expect(element<HTMLButtonElement>(f, '.darker').disabled).toBe(true);
    expect(element<HTMLButtonElement>(f, '.brighter').disabled).toBe(false);
    expect(fill(f)).toBeCloseTo(0);
  });

  it('fills the bar and disables the sun at the top of the dark range', () => {
    const f = create();
    setStep(f, 3);
    expect(element<HTMLButtonElement>(f, '.brighter').disabled).toBe(true);
    expect(fill(f)).toBeCloseTo(100);
  });

  it('resets to the default when the bar is clicked', () => {
    const f = create();
    setStep(f, 2);
    element(f, '.bar').click();
    f.detectChanges();
    expect(fill(f)).toBeCloseTo(50);
    expect(element(f, '.bar').getAttribute('title')).toBe('Reset to default');
  });

  it('fills the whole bar at the light default and only dims from there', () => {
    localStorage.setItem('sfr.theme', 'light');
    const f = create();
    expect(fill(f)).toBeCloseTo(100);
    expect(element<HTMLButtonElement>(f, '.brighter').disabled).toBe(true);
    setStep(f, -6);
    expect(fill(f)).toBeCloseTo(0);
    expect(element<HTMLButtonElement>(f, '.darker').disabled).toBe(true);
  });
});
