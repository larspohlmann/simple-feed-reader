import { ComponentFixture, TestBed } from '@angular/core/testing';
import { provideTranslocoTesting } from '../../../../testing/transloco-testing';
import { TextSizeService } from '../../../theme/text-size.service';
import { TextSizeControlComponent } from './text-size-control.component';

type Fixture = ComponentFixture<TextSizeControlComponent>;

describe('TextSizeControlComponent', () => {
  beforeEach(() => {
    localStorage.clear();
    TestBed.configureTestingModule({
      imports: [TextSizeControlComponent, provideTranslocoTesting()],
    });
  });

  function create(): Fixture {
    const fixture = TestBed.createComponent(TextSizeControlComponent);
    fixture.detectChanges();
    return fixture;
  }

  const element = <T extends HTMLElement>(fixture: Fixture, selector: string): T =>
    (fixture.nativeElement as HTMLElement).querySelector<T>(selector)!;
  const fill = (fixture: Fixture): number =>
    parseFloat(element(fixture, '.fill').style.getPropertyValue('inline-size'));

  function click(fixture: Fixture, selector: string): void {
    element(fixture, selector).click();
    fixture.detectChanges();
  }

  it('labels the group and both buttons', () => {
    const fixture = create();
    expect(element(fixture, '[role=group]').getAttribute('aria-label')).toBe('Text size');
    expect(element(fixture, '.smaller').getAttribute('title')).toBe('Smaller text');
    expect(element(fixture, '.larger').getAttribute('title')).toBe('Larger text');
    expect(element(fixture, '.bar').getAttribute('title')).toBe('Reset text size');
  });

  it('shows the decrease and increase glyphs', () => {
    const fixture = create();
    expect(element(fixture, '.smaller').textContent).toContain('text_decrease');
    expect(element(fixture, '.larger').textContent).toContain('text_increase');
  });

  it('shows and announces the current percentage', () => {
    const fixture = create();
    expect(element(fixture, '.value').textContent?.trim()).toBe('100%');
    expect(element(fixture, 'output').textContent?.trim()).toBe('Text size 100%');
  });

  it('steps up on the larger button', () => {
    const fixture = create();
    click(fixture, '.larger');
    expect(element(fixture, '.value').textContent?.trim()).toBe('110%');
    expect(fill(fixture)).toBeCloseTo(100 / 3);
  });

  it('steps down on the smaller button and disables it at 90 %', () => {
    const fixture = create();
    click(fixture, '.smaller');
    expect(element(fixture, '.value').textContent?.trim()).toBe('90%');
    expect(element<HTMLButtonElement>(fixture, '.smaller').disabled).toBe(true);
    expect(fill(fixture)).toBeCloseTo(0);
  });

  it('disables the larger button at 150 %', () => {
    const fixture = create();
    TestBed.inject(TextSizeService).set(150);
    fixture.detectChanges();
    expect(element<HTMLButtonElement>(fixture, '.larger').disabled).toBe(true);
    expect(fill(fixture)).toBeCloseTo(100);
  });

  it('resets to 100 % when the bar is clicked', () => {
    const fixture = create();
    TestBed.inject(TextSizeService).set(140);
    fixture.detectChanges();
    click(fixture, '.bar');
    expect(element(fixture, '.value').textContent?.trim()).toBe('100%');
  });
});
