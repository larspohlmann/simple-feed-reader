// src/app/settings/preferences-section.component.ts
import { ChangeDetectionStrategy, Component, inject } from '@angular/core';
import { TranslocoPipe } from '@jsverse/transloco';
import { LanguageService } from '../core/language.service';
import { PreferencesService } from '../core/preferences.service';
import { ReadingFocusService } from '../core/reading-focus.service';
import { ErrorBannerComponent } from '../shared/error-banner/error-banner.component';
import { LanguageSwitcherComponent } from '../shared/language-switcher/language-switcher.component';
import { SettingsGroupComponent } from '../shared/settings/settings-group/settings-group.component';
import { SettingsRowComponent } from '../shared/settings/settings-row/settings-row.component';
import { SettingsStackComponent } from '../shared/settings/stack/settings-stack.component';
import { ToggleComponent } from '../shared/toggle/toggle.component';

@Component({
  selector: 'app-preferences-section',
  imports: [
    ErrorBannerComponent,
    LanguageSwitcherComponent,
    SettingsGroupComponent,
    SettingsRowComponent,
    SettingsStackComponent,
    ToggleComponent,
    TranslocoPipe,
  ],
  templateUrl: './preferences-section.component.html',
  styleUrl: './preferences-section.component.scss',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class PreferencesSectionComponent {
  readonly language = inject(LanguageService);
  readonly preferences = inject(PreferencesService);
  readonly readingFocus = inject(ReadingFocusService);
}
