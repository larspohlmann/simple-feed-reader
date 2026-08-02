// src/app/settings/preferences-section.component.ts
import { ChangeDetectionStrategy, Component, inject } from '@angular/core';
import { TranslocoPipe } from '@jsverse/transloco';
import { LanguageService } from '../core/language.service';
import { PreferencesService } from '../core/preferences.service';
import { ErrorBannerComponent } from '../shared/error-banner/error-banner.component';
import { LanguageSwitcherComponent } from '../shared/language-switcher/language-switcher.component';
import { SettingsCardComponent } from '../shared/settings-card/settings-card.component';
import { ToggleComponent } from '../shared/toggle/toggle.component';

@Component({
  selector: 'app-preferences-section',
  imports: [
    LanguageSwitcherComponent,
    SettingsCardComponent,
    ToggleComponent,
    TranslocoPipe,
    ErrorBannerComponent,
  ],
  templateUrl: './preferences-section.component.html',
  styleUrl: './preferences-section.component.scss',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class PreferencesSectionComponent {
  readonly language = inject(LanguageService);
  readonly preferences = inject(PreferencesService);
}
