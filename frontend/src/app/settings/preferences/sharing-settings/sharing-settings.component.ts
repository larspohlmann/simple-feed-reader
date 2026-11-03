import { ChangeDetectionStrategy, Component, inject } from '@angular/core';
import { TranslocoPipe } from '@jsverse/transloco';
import { SharingService } from '../../../core/preferences/sharing.service';
import { ShareTargetLogoComponent } from '../../../reader/article/share/share-target-logo/share-target-logo.component';
import { ConfiguredTarget, SHARE_TARGETS } from '../../../reader/article/share/share-targets';
import { ErrorBannerComponent } from '../../../shared/error-banner/error-banner.component';
import { SettingsGroupComponent } from '../../../shared/settings/settings-group/settings-group.component';
import { SettingsRowComponent } from '../../../shared/settings/settings-row/settings-row.component';
import { ToggleComponent } from '../../../shared/toggle/toggle.component';

@Component({
  selector: 'app-sharing-settings',
  imports: [
    ErrorBannerComponent,
    SettingsGroupComponent,
    SettingsRowComponent,
    ShareTargetLogoComponent,
    ToggleComponent,
    TranslocoPipe,
  ],
  templateUrl: './sharing-settings.component.html',
  styleUrl: './sharing-settings.component.scss',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class SharingSettingsComponent {
  protected readonly sharing = inject(SharingService);
  protected readonly targets = SHARE_TARGETS.filter(
    (target): target is ConfiguredTarget => target.kind !== 'divider',
  );
}
