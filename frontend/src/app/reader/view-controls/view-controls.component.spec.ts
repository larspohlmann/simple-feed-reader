import { TestBed } from '@angular/core/testing';
import { of } from 'rxjs';
import { MAGAZINE_STYLE_WRITER } from '../../core/preferences/magazine-style-writer';
import { MagazineStyleService } from '../../core/preferences/magazine-style.service';
import { ReadingLayoutService } from '../reading-layout.service';
import { ThemeService } from '../../theme/theme.service';
import { ViewControlsComponent } from './view-controls.component';
import { provideTranslocoTesting } from '../../../testing/transloco-testing';

const BOXED = '[title="Magazine layout, boxed"]';
const AIRY = '[title="Magazine layout, airy"]';

describe('ViewControlsComponent', () => {
  beforeEach(() => {
    localStorage.clear();
    TestBed.configureTestingModule({
      imports: [ViewControlsComponent, provideTranslocoTesting()],
      providers: [{ provide: MAGAZINE_STYLE_WRITER, useValue: { write: () => of(true) } }],
    });
  });

  function create() {
    const fixture = TestBed.createComponent(ViewControlsComponent);
    fixture.detectChanges();
    return fixture;
  }

  function layoutGroup(fixture: ReturnType<typeof create>): HTMLElement {
    return fixture.nativeElement.querySelector('[aria-label="Reading layout"]') as HTMLElement;
  }

  it('offers the two magazine designs, then list and pane, in one group', () => {
    const group = layoutGroup(create());
    const titles = Array.from(group.querySelectorAll('button')).map((button) =>
      button.getAttribute('title'),
    );

    expect(titles).toEqual([
      'Magazine layout, boxed',
      'Magazine layout, airy',
      'List layout',
      'Pane layout',
    ]);
  });

  it('picks the layout and the design together', () => {
    const fixture = create();
    const layout = TestBed.inject(ReadingLayoutService);
    const magazineStyle = TestBed.inject(MagazineStyleService);
    layout.set('list');
    fixture.detectChanges();

    (layoutGroup(fixture).querySelector(AIRY) as HTMLButtonElement).click();

    expect(layout.mode()).toBe('magazine');
    expect(magazineStyle.style()).toBe('airy');
  });

  it('marks only the design the reader is actually looking at', () => {
    const fixture = create();
    const group = layoutGroup(fixture);
    expect(group.querySelector(BOXED)!.getAttribute('aria-pressed')).toBe('true');

    (group.querySelector(AIRY) as HTMLButtonElement).click();
    fixture.detectChanges();
    expect(group.querySelector(AIRY)!.getAttribute('aria-pressed')).toBe('true');
    expect(group.querySelector(BOXED)!.getAttribute('aria-pressed')).toBe('false');
  });

  it('leaves both magazine buttons unpressed outside the magazine layout', () => {
    const fixture = create();
    TestBed.inject(ReadingLayoutService).set('list');
    fixture.detectChanges();

    const group = layoutGroup(fixture);
    expect(group.querySelector(BOXED)!.getAttribute('aria-pressed')).toBe('false');
    expect(group.querySelector(AIRY)!.getAttribute('aria-pressed')).toBe('false');
  });

  it('keeps the chosen design when the reader leaves and returns to the magazine', () => {
    const fixture = create();
    const layout = TestBed.inject(ReadingLayoutService);
    const group = layoutGroup(fixture);
    (group.querySelector(AIRY) as HTMLButtonElement).click();

    layout.set('list');
    fixture.detectChanges();
    layout.set('magazine');
    fixture.detectChanges();

    expect(TestBed.inject(MagazineStyleService).style()).toBe('airy');
    expect(group.querySelector(AIRY)!.getAttribute('aria-pressed')).toBe('true');
    expect(group.querySelector(BOXED)!.getAttribute('aria-pressed')).toBe('false');
  });

  it('toggles the reading layout to pane', () => {
    const fixture = create();
    const layout = TestBed.inject(ReadingLayoutService);
    (fixture.nativeElement.querySelector('[title="Pane layout"]') as HTMLButtonElement).click();
    expect(layout.mode()).toBe('pane');
  });

  it('switches the theme mode', () => {
    const fixture = create();
    const theme = TestBed.inject(ThemeService);
    const group = fixture.nativeElement.querySelector('[aria-label="Theme"]') as HTMLElement;
    const dark = group.querySelector('[title="Dark"]') as HTMLButtonElement;
    dark.click();
    expect(theme.mode()).toBe('dark');
    fixture.detectChanges();
    expect(dark.getAttribute('aria-pressed')).toBe('true');
  });
});
