import {
  ChangeDetectionStrategy,
  Component,
  computed,
  inject,
  linkedSignal,
  signal,
} from '@angular/core';
import { TranslocoPipe } from '@jsverse/transloco';
import { ButtonComponent } from '../../../shared/button/button.component';
import { ErrorBannerComponent } from '../../../shared/error-banner/error-banner.component';
import { IconComponent } from '../../../shared/icon/icon.component';
import { PasswordInputComponent } from '../../../shared/password-input/password-input.component';
import { SettingsGroupComponent } from '../../../shared/settings/settings-group/settings-group.component';
import { SettingsRowComponent } from '../../../shared/settings/settings-row/settings-row.component';
import { SettingsSaveBarComponent } from '../../../shared/settings/save-bar/save-bar.component';
import { SettingsStackComponent } from '../../../shared/settings/stack/settings-stack.component';
import { toastOnSaved } from '../../../shared/toast/saved-toast';
import { ToggleComponent } from '../../../shared/toggle/toggle.component';
import { GrafanaSettingsService } from './grafana-settings.service';

/** The admin "Grafana" settings section (#983), on the grouped design language
 *  from #541. The profiling toggle saves instantly; every other field is typed
 *  and waits behind the shared save bar. */
@Component({
  selector: 'app-grafana-section',
  imports: [
    ButtonComponent,
    ErrorBannerComponent,
    IconComponent,
    PasswordInputComponent,
    SettingsGroupComponent,
    SettingsRowComponent,
    SettingsSaveBarComponent,
    SettingsStackComponent,
    ToggleComponent,
    TranslocoPipe,
  ],
  providers: [GrafanaSettingsService],
  templateUrl: './grafana-section.component.html',
  styleUrl: './grafana-section.component.scss',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class GrafanaSectionComponent {
  readonly svc = inject(GrafanaSettingsService);

  // Typed fields: held as a pending draft until the explicit Save. Each reads
  // the pending edit first, server truth underneath.
  readonly lokiPushUrl = linkedSignal<string>(
    () => this.svc.pending('lokiPushUrl') ?? this.svc.state()?.lokiPushUrl ?? '',
  );
  readonly lokiUsername = linkedSignal<string>(
    () => this.svc.pending('lokiUsername') ?? this.svc.state()?.lokiUsername ?? '',
  );
  readonly grafanaUrl = linkedSignal<string>(
    () => this.svc.pending('grafanaUrl') ?? this.svc.state()?.grafanaUrl ?? '',
  );
  readonly pyroscopePushUrl = linkedSignal<string>(
    () => this.svc.pending('pyroscopePushUrl') ?? this.svc.state()?.pyroscopePushUrl ?? '',
  );
  /** Never seeded from server truth -- the API never returns the secret. */
  readonly token = signal('');

  readonly lokiPushUrlDefault = computed(() => this.svc.state()?.lokiPushUrlDefault ?? '');
  readonly lokiPushUrlEffective = computed(() => this.svc.state()?.lokiPushUrlEffective ?? null);
  readonly containerPresent = computed(() => this.svc.state()?.containerPresent ?? false);
  readonly grafanaUrlDefault = computed(() => this.svc.state()?.grafanaUrlDefault ?? '');
  readonly grafanaUrlEffective = computed(() => this.svc.state()?.grafanaUrlEffective ?? null);
  readonly hasToken = computed(() => this.svc.state()?.hasToken ?? false);
  readonly tokenHint = computed(() => this.svc.state()?.tokenHint ?? '');
  readonly profilingEnabled = computed(() => this.svc.state()?.profilingEnabled ?? false);
  readonly profilerAvailable = computed(() => this.svc.state()?.profilerAvailable ?? false);
  readonly profilingContainerPresent = computed(
    () => this.svc.state()?.profilingContainerPresent ?? false,
  );
  readonly pyroscopePushUrlDefault = computed(
    () => this.svc.state()?.pyroscopePushUrlDefault ?? '',
  );
  readonly pyroscopePushUrlEffective = computed(
    () => this.svc.state()?.pyroscopePushUrlEffective ?? null,
  );

  readonly failureMessage = computed(() => {
    const failure = this.svc.failure();
    return failure ? (failure.detail ?? failure.title) : null;
  });

  constructor() {
    this.svc.load();
    toastOnSaved(this.svc, 'settings.grafana.saved');
  }

  onLokiPushUrl(event: Event): void {
    const value = (event.target as HTMLInputElement).value;
    this.lokiPushUrl.set(value);
    this.svc.setTypedField('lokiPushUrl', emptyToNull(value));
  }

  onLokiUsername(event: Event): void {
    const value = (event.target as HTMLInputElement).value;
    this.lokiUsername.set(value);
    this.svc.setTypedField('lokiUsername', value);
  }

  onGrafanaUrl(event: Event): void {
    const value = (event.target as HTMLInputElement).value;
    this.grafanaUrl.set(value);
    this.svc.setTypedField('grafanaUrl', emptyToNull(value));
  }

  /** An empty field means "keep the stored token", not "clear it" -- the
   *  service never sees a blank token as a real edit. */
  onToken(event: Event): void {
    const value = (event.target as HTMLInputElement).value;
    this.token.set(value);
    this.svc.setTypedField('token', value === '' ? null : value);
  }

  onPyroscopePushUrl(event: Event): void {
    const value = (event.target as HTMLInputElement).value;
    this.pyroscopePushUrl.set(value);
    this.svc.setTypedField('pyroscopePushUrl', emptyToNull(value));
  }

  onProfilingToggled(value: boolean): void {
    this.svc.saveInstant({ profilingEnabled: value });
  }

  onSave(): void {
    this.svc.save();
  }

  /** Dropping the draft is enough for the typed inputs: they read it as their
   *  source, so clearing it reseeds them from the last-saved state. The token
   *  is a plain signal with no server source, so it is cleared here. */
  onReset(): void {
    this.svc.discardDraft();
    this.token.set('');
  }

  removeToken(): void {
    this.svc.removeToken();
  }
}

/** The client sends `null`, not `''`, to restore whatever fallback the server
 *  applies when a nullable settings field is left empty. */
function emptyToNull(value: string): string | null {
  const trimmed = value.trim();
  return trimmed === '' ? null : trimmed;
}
