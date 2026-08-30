// src/app/settings/preferences-section.component.ts
import { ChangeDetectionStrategy, Component, inject } from '@angular/core';
import { TranslocoPipe } from '@jsverse/transloco';
import { LANGS } from '../core/language';
import { LanguageService } from '../core/language.service';
import { MAGAZINE_STYLES } from '../core/magazine-style';
import { MagazineStyleService } from '../core/magazine-style.service';
import { PreferencesService } from '../core/preferences.service';
import { ReadingFocusService } from '../core/reading-focus.service';
import { ErrorBannerComponent } from '../shared/error-banner/error-banner.component';
import { SegmentedChoiceComponent } from '../shared/segmented-choice/segmented-choice.component';
import { SettingsGroupComponent } from '../shared/settings/settings-group/settings-group.component';
import { SettingsRowComponent } from '../shared/settings/settings-row/settings-row.component';
import { SettingsStackComponent } from '../shared/settings/stack/settings-stack.component';
import { ToggleComponent } from '../shared/toggle/toggle.component';

@Component({
  selector: 'app-preferences-section',
  imports: [
    ErrorBannerComponent,
    SegmentedChoiceComponent,
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
  readonly magazineStyle = inject(MagazineStyleService);
  readonly preferences = inject(PreferencesService);
  readonly readingFocus = inject(ReadingFocusService);
  readonly languages = LANGS;
  readonly magazineStyles = MAGAZINE_STYLES;
}
