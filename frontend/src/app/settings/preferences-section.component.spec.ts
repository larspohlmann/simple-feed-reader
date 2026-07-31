import { TestBed } from '@angular/core/testing';
import { provideTranslocoTesting } from '../../testing/transloco-testing';
import { PreferencesSectionComponent } from './preferences-section.component';

describe('PreferencesSectionComponent', () => {
  function mount() {
    TestBed.resetTestingModule();
    TestBed.configureTestingModule({
      imports: [provideTranslocoTesting()],
    });
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
});
