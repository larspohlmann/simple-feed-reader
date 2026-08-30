import { TestBed } from '@angular/core/testing';
import { signal } from '@angular/core';
import { LanguageService } from '../core/language.service';
import { PreferencesService } from '../core/preferences.service';
import { provideTranslocoTesting } from '../../testing/transloco-testing';
import { PreferencesSectionComponent } from './preferences-section.component';
import en from '../../../public/i18n/en.json';

describe('PreferencesSectionComponent', () => {
  let saveFailed: ReturnType<typeof signal<boolean>>;
  let preferences: PreferencesService;

  beforeEach(() => localStorage.clear());

  function mount() {
    TestBed.resetTestingModule();
    saveFailed = signal(false);
    TestBed.configureTestingModule({
      imports: [provideTranslocoTesting()],
      providers: [
        { provide: LanguageService, useValue: { lang: signal('en'), set: jest.fn(), saveFailed } },
      ],
    });
    preferences = TestBed.inject(PreferencesService);
    const f = TestBed.createComponent(PreferencesSectionComponent);
    f.detectChanges();
    return f;
  }

  it('renders the section as a settings group', () => {
    const el = mount().nativeElement as HTMLElement;
    expect(el.querySelector('app-settings-group')).not.toBeNull();
  });

  it('shows the experimental badge beside the scraping row title', () => {
    const el = mount().nativeElement as HTMLElement;
    expect(el.querySelector('.row-title .badge')?.textContent?.trim()).toBe(
      en.settings.experimental,
    );
  });

  it('offers a segmented choice for the language and for the magazine style', () => {
    const el = mount().nativeElement as HTMLElement;
    const labels = Array.from(el.querySelectorAll('app-segmented-choice [role="group"]')).map((g) =>
      g.getAttribute('aria-label'),
    );

    expect(labels).toEqual(['Language', 'Magazine style']);
  });

  it('shows no banner while the language write has not failed', () => {
    const el = mount().nativeElement as HTMLElement;
    expect(el.querySelector('app-error-banner')).toBeNull();
  });

  it('surfaces a banner when the language write failed', () => {
    const f = mount();
    saveFailed.set(true);
    f.detectChanges();
    const el = f.nativeElement as HTMLElement;
    const banner = el.querySelector('app-error-banner');
    expect(banner).not.toBeNull();
    expect(banner?.textContent).toContain('could not be saved to your account');
  });

  it('renders the scraping toggle marked experimental', () => {
    const fixture = mount();
    const text = fixture.nativeElement.textContent as string;

    expect(text).toContain('Experimental');
    expect(fixture.nativeElement.querySelector('app-toggle')).not.toBeNull();
  });

  it('offers reading focus enabled by default', () => {
    const fixture = mount();
    const toggle = fixture.nativeElement.querySelector('#reading-focus-toggle') as HTMLInputElement;

    expect(fixture.nativeElement.textContent).toContain('Reading focus');
    expect(toggle.checked).toBe(true);
  });

  it('stores the reading focus setting when toggled', () => {
    const fixture = mount();
    const toggle = fixture.nativeElement.querySelector('#reading-focus-toggle') as HTMLInputElement;

    toggle.click();
    fixture.detectChanges();

    expect(toggle.checked).toBe(false);
    expect(localStorage.getItem('sfr.readingFocus')).toBe('false');
  });

  it('writes the preference when toggled', () => {
    const fixture = mount();
    const input = fixture.nativeElement.querySelector(
      'app-toggle input[type="checkbox"]',
    ) as HTMLInputElement;

    input.click();
    fixture.detectChanges();

    expect(preferences.scrapeFallbackEnabled()).toBe(true);
  });

  it('toggles the control when the visible label text is clicked, not only the switch', () => {
    const fixture = mount();
    const el = fixture.nativeElement as HTMLElement;
    const label = el.querySelector('.row-title label') as HTMLLabelElement;
    const input = el.querySelector('app-toggle input[type="checkbox"]') as HTMLInputElement;

    expect(label.htmlFor).toBe(input.id);
    label.click();
    fixture.detectChanges();

    expect(preferences.scrapeFallbackEnabled()).toBe(true);
  });
});
