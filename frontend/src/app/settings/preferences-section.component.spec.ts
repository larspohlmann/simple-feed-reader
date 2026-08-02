import { TestBed } from '@angular/core/testing';
import { signal } from '@angular/core';
import { LanguageService } from '../core/language.service';
import { PreferencesService } from '../core/preferences.service';
import { provideTranslocoTesting } from '../../testing/transloco-testing';
import { PreferencesSectionComponent } from './preferences-section.component';

describe('PreferencesSectionComponent', () => {
  let saveFailed: ReturnType<typeof signal<boolean>>;
  let preferences: PreferencesService;

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

  it('renders inside a settings card', () => {
    const el = mount().nativeElement as HTMLElement;
    expect(el.querySelector('app-settings-card')).not.toBeNull();
  });

  it('offers the language switcher', () => {
    const el = mount().nativeElement as HTMLElement;
    expect(el.querySelector('app-language-switcher')).not.toBeNull();
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

  it('writes the preference when toggled', () => {
    const fixture = mount();
    const input = fixture.nativeElement.querySelector(
      'app-toggle input[type="checkbox"]',
    ) as HTMLInputElement;

    input.click();
    fixture.detectChanges();

    expect(preferences.scrapeFallbackEnabled()).toBe(true);
  });
});
