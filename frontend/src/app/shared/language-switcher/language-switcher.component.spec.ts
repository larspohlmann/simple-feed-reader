import { TestBed } from '@angular/core/testing';
import { TranslocoService } from '@jsverse/transloco';
import { LanguageSwitcherComponent } from './language-switcher.component';
import { LanguageService } from '../../core/language.service';
import { provideTranslocoTesting } from '../../../testing/transloco-testing';

function mount() {
  TestBed.resetTestingModule();
  TestBed.configureTestingModule({
    imports: [LanguageSwitcherComponent, provideTranslocoTesting()],
    providers: [LanguageService],
  });
  const f = TestBed.createComponent(LanguageSwitcherComponent);
  f.detectChanges();
  return f;
}

describe('LanguageSwitcherComponent', () => {
  beforeEach(() => localStorage.clear());

  it('renders a button per language, marking the active one', () => {
    const el = mount().nativeElement as HTMLElement;
    const buttons = Array.from(el.querySelectorAll('button'));
    expect(buttons.map((b) => b.textContent!.trim())).toEqual(['English', 'German']);
    expect(buttons.find((b) => b.classList.contains('on'))!.textContent).toContain('English');
  });

  it('switches the language and re-renders labels when a button is clicked', () => {
    const f = mount();
    const el = f.nativeElement as HTMLElement;
    const german = Array.from(el.querySelectorAll('button')).find((b) =>
      b.textContent!.includes('German'),
    )!;
    german.click();
    f.detectChanges();

    expect(TestBed.inject(LanguageService).lang()).toBe('de');
    expect(TestBed.inject(TranslocoService).getActiveLang()).toBe('de');
    // Labels now render in German.
    const buttons = Array.from(el.querySelectorAll('button')).map((b) => b.textContent!.trim());
    expect(buttons).toEqual(['Englisch', 'Deutsch']);
  });
});
