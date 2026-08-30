import { TestBed } from '@angular/core/testing';
import { of } from 'rxjs';
import { MAGAZINE_STYLE_WRITER } from '../../core/magazine-style-writer';
import { MagazineStyleService } from '../../core/magazine-style.service';
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
    const f = TestBed.createComponent(ViewControlsComponent);
    f.detectChanges();
    return f;
  }

  function layoutGroup(f: ReturnType<typeof create>): HTMLElement {
    return f.nativeElement.querySelector('[aria-label="Reading layout"]') as HTMLElement;
  }

  it('offers the two magazine designs, then list and pane, in one group', () => {
    const group = layoutGroup(create());
    const titles = Array.from(group.querySelectorAll('button')).map((b) => b.getAttribute('title'));

    expect(titles).toEqual([
      'Magazine layout, boxed',
      'Magazine layout, airy',
      'List layout',
      'Pane layout',
    ]);
  });

  it('picks the layout and the design together', () => {
    const f = create();
    const layout = TestBed.inject(ReadingLayoutService);
    const magazineStyle = TestBed.inject(MagazineStyleService);
    layout.set('list');
    f.detectChanges();

    (layoutGroup(f).querySelector(AIRY) as HTMLButtonElement).click();

    expect(layout.mode()).toBe('magazine');
    expect(magazineStyle.style()).toBe('airy');
  });

  it('marks only the design the reader is actually looking at', () => {
    const f = create();
    const group = layoutGroup(f);
    expect(group.querySelector(BOXED)!.getAttribute('aria-pressed')).toBe('true');

    (group.querySelector(AIRY) as HTMLButtonElement).click();
    f.detectChanges();
    expect(group.querySelector(AIRY)!.getAttribute('aria-pressed')).toBe('true');
    expect(group.querySelector(BOXED)!.getAttribute('aria-pressed')).toBe('false');
  });

  it('leaves both magazine buttons unpressed outside the magazine layout', () => {
    const f = create();
    TestBed.inject(ReadingLayoutService).set('list');
    f.detectChanges();

    const group = layoutGroup(f);
    expect(group.querySelector(BOXED)!.getAttribute('aria-pressed')).toBe('false');
    expect(group.querySelector(AIRY)!.getAttribute('aria-pressed')).toBe('false');
  });

  it('keeps the chosen design when the reader leaves and returns to the magazine', () => {
    const f = create();
    const layout = TestBed.inject(ReadingLayoutService);
    const group = layoutGroup(f);
    (group.querySelector(AIRY) as HTMLButtonElement).click();

    layout.set('list');
    f.detectChanges();
    layout.set('magazine');
    f.detectChanges();

    expect(TestBed.inject(MagazineStyleService).style()).toBe('airy');
    expect(group.querySelector(AIRY)!.getAttribute('aria-pressed')).toBe('true');
    expect(group.querySelector(BOXED)!.getAttribute('aria-pressed')).toBe('false');
  });

  it('toggles the reading layout to pane', () => {
    const f = create();
    const layout = TestBed.inject(ReadingLayoutService);
    (f.nativeElement.querySelector('[title="Pane layout"]') as HTMLButtonElement).click();
    expect(layout.mode()).toBe('pane');
  });

  it('switches the theme mode', () => {
    const f = create();
    const theme = TestBed.inject(ThemeService);
    const group = f.nativeElement.querySelector('[aria-label="Theme"]') as HTMLElement;
    const dark = group.querySelector('[title="Dark"]') as HTMLButtonElement;
    dark.click();
    expect(theme.mode()).toBe('dark');
    f.detectChanges();
    expect(dark.getAttribute('aria-pressed')).toBe('true');
  });
});
