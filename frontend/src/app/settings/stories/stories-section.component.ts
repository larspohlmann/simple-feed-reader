import { ChangeDetectionStrategy, Component, inject, input } from '@angular/core';
import { TranslocoPipe } from '@jsverse/transloco';
import { ErrorBannerComponent } from '../../shared/error-banner/error-banner.component';
import { SettingsGroupComponent } from '../../shared/settings/settings-group/settings-group.component';
import { SettingsRowComponent } from '../../shared/settings/settings-row/settings-row.component';
import { ToggleComponent } from '../../shared/toggle/toggle.component';
import { toastOnSaved } from '../../shared/toast/saved-toast';
import { AiConfig } from '../ai/ai-settings.service';
import { reloadOnConnectionChange } from '../connection-choice';
import { ConnectionSelectComponent } from '../connection-select/connection-select.component';
import { StoriesSettingsService } from './stories-settings.service';

/** What the LLM does for Trending stories: whether it writes their titles, and which connection does. */
@Component({
  selector: 'app-stories-section',
  imports: [
    ConnectionSelectComponent,
    ErrorBannerComponent,
    SettingsGroupComponent,
    SettingsRowComponent,
    ToggleComponent,
    TranslocoPipe,
  ],
  providers: [StoriesSettingsService],
  templateUrl: './stories-section.component.html',
  styleUrl: './stories-section.component.scss',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class StoriesSectionComponent {
  readonly connections = input<readonly AiConfig[]>([]);
  readonly svc = inject(StoriesSettingsService);

  constructor() {
    this.svc.load();
    reloadOnConnectionChange(this.connections, () => this.svc.load());
    toastOnSaved(this.svc, 'settings.stories.saved');
  }

  onTitles(enabled: boolean): void {
    this.svc.saveInstant({ titlesEnabled: enabled });
  }

  onConnection(writingConnectionId: number | null): void {
    this.svc.saveInstant({ writingConnectionId });
  }
}
