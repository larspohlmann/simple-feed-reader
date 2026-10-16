import { ChangeDetectionStrategy, Component, computed, inject } from '@angular/core';
import { TranslocoPipe } from '@jsverse/transloco';
import { ErrorBannerComponent } from '../../../shared/error-banner/error-banner.component';
import { SettingsGroupComponent } from '../../../shared/settings/settings-group/settings-group.component';
import { SettingsRowComponent } from '../../../shared/settings/settings-row/settings-row.component';
import { SettingsSaveBarComponent } from '../../../shared/settings/save-bar/save-bar.component';
import { SettingsStackComponent } from '../../../shared/settings/stack/settings-stack.component';
import { toastOnSaved } from '../../../shared/toast/saved-toast';
import { RetentionSettings, RetentionSettingsService } from './retention-settings.service';

/** The admin "Retention" section (#1645): how many days refresh keeps feed entries and social posts. */
@Component({
  selector: 'app-retention-section',
  imports: [
    ErrorBannerComponent,
    SettingsGroupComponent,
    SettingsRowComponent,
    SettingsSaveBarComponent,
    SettingsStackComponent,
    TranslocoPipe,
  ],
  providers: [RetentionSettingsService],
  templateUrl: './retention-section.component.html',
  styleUrl: './retention-section.component.scss',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class RetentionSectionComponent {
  readonly svc = inject(RetentionSettingsService);

  readonly entryRetentionDays = computed(() => this.days('entryRetentionDays'));
  readonly socialRetentionDays = computed(() => this.days('socialRetentionDays'));

  readonly failureMessage = this.svc.failureMessage;

  constructor() {
    this.svc.load();
    toastOnSaved(this.svc, 'settings.retention.saved');
  }

  onDays(field: keyof RetentionSettings, event: Event): void {
    this.svc.setTypedField(field, +(event.target as HTMLInputElement).value);
  }

  onSave(): void {
    this.svc.save();
  }

  onReset(): void {
    this.svc.discardDraft();
  }

  private days(field: keyof RetentionSettings): number {
    return this.svc.pending(field) ?? this.svc.state()?.[field] ?? 0;
  }
}
