import { ComponentFixture, TestBed } from '@angular/core/testing';
import { BrightnessService } from '../../../theme/brightness.service';
import { provideTranslocoTesting } from '../../../../testing/transloco-testing';
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
    const fixture = TestBed.createComponent(BrightnessControlComponent);
    fixture.detectChanges();
    return fixture;
  }

  const element = <T extends HTMLElement>(fixture: Fixture, selector: string): T =>
    (fixture.nativeElement as HTMLElement).querySelector<T>(selector)!;
  const fill = (fixture: Fixture): number =>
    parseFloat(element(fixture, '.fill').style.getPropertyValue('inline-size'));

  function setStep(fixture: Fixture, step: number): void {
    TestBed.inject(BrightnessService).set(step);
    fixture.detectChanges();
  }

  it('labels the group and both buttons', () => {
    const fixture = create();
    expect(element(fixture, '[role=group]').getAttribute('aria-label')).toBe('Brightness');
    expect(element(fixture, '.darker').getAttribute('title')).toBe('Darker');
    expect(element(fixture, '.brighter').getAttribute('title')).toBe('Brighter');
  });

  it('shows a moon for darker and a sun for brighter', () => {
    const fixture = create();
    expect(element(fixture, '.darker').textContent).toContain('dark_mode');
    expect(element(fixture, '.brighter').textContent).toContain('light_mode');
  });

  it('half-fills the bar at the dark default', () => {
    expect(fill(create())).toBeCloseTo(50);
  });

  it('announces the default in words', () => {
    expect(element(create(), 'output').textContent?.trim()).toBe('Brightness default');
  });

  it('steps up on the sun and announces the signed value', () => {
    const fixture = create();
    element(fixture, '.brighter').click();
    fixture.detectChanges();
    expect(fill(fixture)).toBeCloseTo(200 / 3);
    expect(element(fixture, 'output').textContent?.trim()).toBe('Brightness +1');
  });

  it('steps down on the moon and announces the negative value', () => {
    const fixture = create();
    element(fixture, '.darker').click();
    fixture.detectChanges();
    expect(fill(fixture)).toBeCloseTo(100 / 3);
    expect(element(fixture, 'output').textContent?.trim()).toBe('Brightness -1');
  });

  it('empties the bar and disables the moon at the bottom of the range', () => {
    const fixture = create();
    setStep(fixture, -3);
    expect(element<HTMLButtonElement>(fixture, '.darker').disabled).toBe(true);
    expect(element<HTMLButtonElement>(fixture, '.brighter').disabled).toBe(false);
    expect(fill(fixture)).toBeCloseTo(0);
  });

  it('fills the bar and disables the sun at the top of the dark range', () => {
    const fixture = create();
    setStep(fixture, 3);
    expect(element<HTMLButtonElement>(fixture, '.brighter').disabled).toBe(true);
    expect(fill(fixture)).toBeCloseTo(100);
  });

  it('resets to the default when the bar is clicked', () => {
    const fixture = create();
    setStep(fixture, 2);
    element(fixture, '.bar').click();
    fixture.detectChanges();
    expect(fill(fixture)).toBeCloseTo(50);
    expect(element(fixture, '.bar').getAttribute('title')).toBe('Reset to default');
  });

  it('fills the whole bar at the light default and only dims from there', () => {
    localStorage.setItem('sfr.theme', 'light');
    const fixture = create();
    expect(fill(fixture)).toBeCloseTo(100);
    expect(element<HTMLButtonElement>(fixture, '.brighter').disabled).toBe(true);
    setStep(fixture, -6);
    expect(fill(fixture)).toBeCloseTo(0);
    expect(element<HTMLButtonElement>(fixture, '.darker').disabled).toBe(true);
  });
});
