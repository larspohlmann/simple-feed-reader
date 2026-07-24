import { TestBed } from '@angular/core/testing';
import { ReadingLayoutService } from '../reading-layout.service';
import { ThemeService } from '../../theme/theme.service';
import { ViewControlsComponent } from './view-controls.component';
import { provideTranslocoTesting } from '../../../testing/transloco-testing';

describe('ViewControlsComponent', () => {
  beforeEach(() => {
    localStorage.clear();
    TestBed.configureTestingModule({
      imports: [ViewControlsComponent, provideTranslocoTesting()],
    });
  });

  function create() {
    const f = TestBed.createComponent(ViewControlsComponent);
    f.detectChanges();
    return f;
  }

  it('shows the Magazine layout button first and switches to it', () => {
    const f = create();
    const layout = TestBed.inject(ReadingLayoutService);
    const group = f.nativeElement.querySelector('[aria-label="Reading layout"]') as HTMLElement;
    const buttons = group.querySelectorAll('button');
    expect(buttons[0].getAttribute('aria-label')).toBe('Magazine layout');

    layout.set('list');
    f.detectChanges();
    expect(group.querySelector('[aria-label="Magazine layout"]')!.classList).not.toContain(
      'active',
    );

    (group.querySelector('[aria-label="Magazine layout"]') as HTMLButtonElement).click();
    expect(layout.mode()).toBe('magazine');
  });

  it('toggles the reading layout to pane', () => {
    const f = create();
    const layout = TestBed.inject(ReadingLayoutService);
    (f.nativeElement.querySelector('[aria-label="Pane layout"]') as HTMLButtonElement).click();
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
