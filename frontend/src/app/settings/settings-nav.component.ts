import { ChangeDetectionStrategy, Component, computed, inject, input } from '@angular/core';
import { RouterLink, RouterLinkActive } from '@angular/router';
import { TranslocoPipe } from '@jsverse/transloco';
import { AuthService } from '../core/auth.service';
import { IconComponent } from '../shared/icon/icon.component';
import { SETTINGS_SECTIONS, SettingsSection } from './settings-sections';

interface NavGroup {
  /** null for the unlabelled general group. */
  readonly labelKey: string | null;
  readonly sections: readonly SettingsSection[];
}

/** The settings navigation, rendered from SETTINGS_SECTIONS in one of two
 *  framings: `rail` (persistent desktop column) or `hub` (the full-page list
 *  that IS the mobile /settings route). Same data, same markup — the variant
 *  class picks the styling. */
@Component({
  selector: 'app-settings-nav',
  imports: [RouterLink, RouterLinkActive, TranslocoPipe, IconComponent],
  templateUrl: './settings-nav.component.html',
  styleUrl: './settings-nav.component.scss',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class SettingsNavComponent {
  readonly variant = input.required<'rail' | 'hub'>();
  private readonly auth = inject(AuthService);

  readonly groups = computed<readonly NavGroup[]>(() => {
    const groups: NavGroup[] = [
      { labelKey: null, sections: SETTINGS_SECTIONS.filter((s) => s.group === 'general') },
    ];
    if (this.auth.isAdmin()) {
      groups.push({
        labelKey: 'settings.nav.admin',
        sections: SETTINGS_SECTIONS.filter((s) => s.group === 'admin'),
      });
    }
    return groups;
  });
}
