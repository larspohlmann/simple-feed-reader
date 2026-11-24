import { ChangeDetectionStrategy, Component, inject } from '@angular/core';
import { TranslocoPipe } from '@jsverse/transloco';
import { LANGS } from '../../core/i18n/language';
import { HANDEDNESS } from '../../core/preferences/handedness';
import { HandednessService } from '../../core/preferences/handedness.service';
import { LanguageService } from '../../core/i18n/language.service';
import { MAGAZINE_STYLES } from '../../core/preferences/magazine-style';
import { MagazineStyleService } from '../../core/preferences/magazine-style.service';
import { SIDEBAR_STYLES } from '../../core/preferences/sidebar-style';
import { SidebarStyleService } from '../../core/preferences/sidebar-style.service';
import { THEME_NAMES } from '../../core/preferences/theme-name';
import { ThemeNameService } from '../../core/preferences/theme-name.service';
import { PreferencesService } from '../../core/preferences/preferences.service';
import { ListAutoplayService } from '../../core/preferences/list-autoplay.service';
import { ReadingFocusService } from '../../core/preferences/reading-focus.service';
import { ErrorBannerComponent } from '../../shared/error-banner/error-banner.component';
import { SegmentedChoiceComponent } from '../../shared/segmented-choice/segmented-choice.component';
import { SettingsGroupComponent } from '../../shared/settings/settings-group/settings-group.component';
import { SettingsRowComponent } from '../../shared/settings/settings-row/settings-row.component';
import { SettingsStackComponent } from '../../shared/settings/stack/settings-stack.component';
import { ToggleComponent } from '../../shared/toggle/toggle.component';
import { SharingSettingsComponent } from './sharing-settings/sharing-settings.component';

@Component({
  selector: 'app-preferences-section',
  imports: [
    ErrorBannerComponent,
    SegmentedChoiceComponent,
    SettingsGroupComponent,
    SettingsRowComponent,
    SettingsStackComponent,
    SharingSettingsComponent,
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
  readonly sidebarStyle = inject(SidebarStyleService);
  readonly themeName = inject(ThemeNameService);
  readonly preferences = inject(PreferencesService);
  readonly readingFocus = inject(ReadingFocusService);
  readonly listAutoplay = inject(ListAutoplayService);
  readonly handedness = inject(HandednessService);
  readonly languages = LANGS;
  readonly magazineStyles = MAGAZINE_STYLES;
  readonly sidebarStyles = SIDEBAR_STYLES;
  readonly themeNames = THEME_NAMES;
  readonly handednessOptions = HANDEDNESS;
}
