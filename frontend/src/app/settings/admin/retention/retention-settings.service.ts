import { Injectable } from '@angular/core';
import { DraftSettingsService } from '../../../shared/settings/draft-settings.service';

export interface RetentionSettings {
  readonly entryRetentionDays: number;
  readonly socialRetentionDays: number;
}

@Injectable()
export class RetentionSettingsService extends DraftSettingsService<
  RetentionSettings,
  RetentionSettings,
  RetentionSettings
> {
  protected readonly endpoint = `${this.base}/api/admin/retention`;

  protected bodyFromState(state: RetentionSettings): RetentionSettings {
    return state;
  }
}
