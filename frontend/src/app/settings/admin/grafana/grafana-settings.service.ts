import { Injectable } from '@angular/core';
import { DraftSettingsService } from '../../../shared/settings/draft-settings.service';

export interface GrafanaSettingsState {
  readonly lokiPushUrl: string | null;
  readonly lokiPushUrlDefault: string;
  readonly lokiPushUrlEffective: string | null;
  readonly lokiUsername: string | null;
  readonly grafanaUrl: string | null;
  readonly grafanaUrlDefault: string;
  readonly grafanaUrlEffective: string | null;
  readonly hasToken: boolean;
  readonly tokenHint: string;
  readonly containerPresent: boolean;
  readonly pyroscopePushUrl: string | null;
  readonly pyroscopePushUrlDefault: string;
  readonly pyroscopePushUrlEffective: string | null;
  readonly profilingEnabled: boolean;
  readonly profilingContainerPresent: boolean;
  readonly profilerAvailable: boolean;
}

export interface SaveGrafanaSettings {
  readonly lokiPushUrl: string | null;
  readonly lokiUsername: string | null;
  readonly grafanaUrl: string | null;
  /** null keeps the stored token; a string replaces it. */
  readonly token: string | null;
  readonly removeToken: boolean;
  readonly pyroscopePushUrl: string | null;
  readonly profilingEnabled: boolean;
}

export type TypedGrafanaEdits = Partial<
  Omit<SaveGrafanaSettings, 'removeToken' | 'profilingEnabled'>
>;

@Injectable()
export class GrafanaSettingsService extends DraftSettingsService<
  GrafanaSettingsState,
  SaveGrafanaSettings,
  TypedGrafanaEdits
> {
  protected readonly endpoint = `${this.base}/api/admin/grafana`;

  removeToken(): void {
    const current = this.state();
    if (!current) return;
    this.put({ ...this.bodyFromState(current), removeToken: true }, (state) => {
      this.commit(state);
      this.saved.set(true);
    });
  }

  protected bodyFromState(state: GrafanaSettingsState): SaveGrafanaSettings {
    return {
      lokiPushUrl: state.lokiPushUrl,
      lokiUsername: state.lokiUsername,
      grafanaUrl: state.grafanaUrl,
      token: null,
      removeToken: false,
      pyroscopePushUrl: state.pyroscopePushUrl,
      profilingEnabled: state.profilingEnabled,
    };
  }
}
