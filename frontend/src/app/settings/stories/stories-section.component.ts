import {
  ChangeDetectionStrategy,
  Component,
  computed,
  effect,
  inject,
  input,
  untracked,
} from '@angular/core';
import { TranslocoPipe } from '@jsverse/transloco';
import { ErrorBannerComponent } from '../../shared/error-banner/error-banner.component';
import { SettingsGroupComponent } from '../../shared/settings/settings-group/settings-group.component';
import { SettingsRowComponent } from '../../shared/settings/settings-row/settings-row.component';
import { ToggleComponent } from '../../shared/toggle/toggle.component';
import { toastOnSaved } from '../../shared/toast/saved-toast';
import { connectionLabel, unusableConnectionId } from '../connection-choice';
import { StoriesSettingsService } from './stories-settings.service';

/** What the LLM does for Trending stories: whether it writes their titles, and which connection does. */
@Component({
  selector: 'app-stories-section',
  imports: [
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
  readonly connections = input<readonly unknown[]>([]);
  readonly svc = inject(StoriesSettingsService);

  readonly unusableChoice = computed(() => {
    const state = this.svc.state();
    return state ? unusableConnectionId(state.writingConnectionId, state.writingCandidates) : null;
  });
  readonly label = connectionLabel;

  constructor() {
    this.svc.load();
    let initial = true;
    effect(() => {
      this.connections();
      if (initial) {
        initial = false;
        return;
      }
      untracked(() => this.svc.load());
    });
    toastOnSaved(this.svc, 'settings.stories.saved');
  }

  onTitles(enabled: boolean): void {
    this.svc.saveInstant({ titlesEnabled: enabled });
  }

  onConnection(event: Event): void {
    const raw = (event.target as HTMLSelectElement).value;
    this.svc.saveInstant({ writingConnectionId: raw === '' ? null : +raw });
  }
}
