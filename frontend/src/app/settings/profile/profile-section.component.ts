import {
  ChangeDetectionStrategy,
  Component,
  WritableSignal,
  computed,
  inject,
  linkedSignal,
} from '@angular/core';
import { TranslocoPipe } from '@jsverse/transloco';
import { LanguageService } from '../../core/i18n/language.service';
import { formatLongDateTime, formatRange } from '../../reader/format';
import { ButtonComponent } from '../../shared/button/button.component';
import { ErrorBannerComponent } from '../../shared/error-banner/error-banner.component';
import { FieldComponent } from '../../shared/field/field.component';
import { SettingsGroupComponent } from '../../shared/settings/settings-group/settings-group.component';
import { SettingsRowComponent } from '../../shared/settings/settings-row/settings-row.component';
import { SettingsSaveBarComponent } from '../../shared/settings/save-bar/save-bar.component';
import { toastOnSaved } from '../../shared/toast/saved-toast';
import { ProfileDebugLogComponent } from './profile-debug-log.component';
import {
  ProfileCapField,
  ProfileConnection,
  ProfileSettingsService,
} from './profile-settings.service';

/** The interest profile the For You runs score against: what it says, how it is built, and a manual start. */
@Component({
  selector: 'app-profile-section',
  imports: [
    ButtonComponent,
    ErrorBannerComponent,
    FieldComponent,
    ProfileDebugLogComponent,
    SettingsGroupComponent,
    SettingsRowComponent,
    SettingsSaveBarComponent,
    TranslocoPipe,
  ],
  providers: [ProfileSettingsService],
  templateUrl: './profile-section.component.html',
  styleUrl: './profile-section.component.scss',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class ProfileSectionComponent {
  readonly svc = inject(ProfileSettingsService);
  private readonly language = inject(LanguageService);

  readonly keptCap = linkedSignal<number>(
    () => this.svc.pending('keptCap') ?? this.svc.state()?.keptCap ?? 0,
  );
  readonly viewedCap = linkedSignal<number>(
    () => this.svc.pending('viewedCap') ?? this.svc.state()?.viewedCap ?? 0,
  );

  readonly generatedAt = computed(() => {
    const generatedAt = this.svc.state()?.generatedAt;
    return generatedAt ? formatLongDateTime(generatedAt, this.language.lang()) : null;
  });

  /** The server keeps a pick that can no longer build profiles, but it is no candidate: shown, never offered. */
  readonly unusableChoice = computed(() => {
    const state = this.svc.state();
    if (!state || state.connectionId === null) return null;
    const isCandidate = state.candidates.some((candidate) => candidate.id === state.connectionId);
    return isCandidate ? null : state.connectionId;
  });

  readonly canGenerate = computed(() => !this.svc.polling() && !this.svc.starting());

  /** The one line under the profile about the newest run; a failure shows as a banner instead. */
  readonly runStatusKey = computed(() => {
    const run = this.svc.profileRun();
    if (this.svc.runActive()) return 'settings.profile.statusRunning';
    if (run?.status !== 'completed') return null;
    if (run.outcome === 'unchanged') return 'settings.profile.statusUnchanged';
    if (run.outcome === 'no_history') return 'settings.profile.statusNoHistory';
    return null;
  });

  readonly runError = computed(() => {
    const run = this.svc.profileRun();
    return run?.status === 'failed' ? (run.error ?? '') : null;
  });

  readonly startFailureMessage = computed(() => {
    const failure = this.svc.startFailure();
    return failure ? (failure.detail ?? failure.title) : null;
  });

  constructor() {
    this.svc.load();
    this.svc.loadRun();
    toastOnSaved(this.svc, 'settings.profile.saved');
  }

  label(connection: ProfileConnection): string {
    const name = connection.name ?? new URL(connection.baseUrl).host;
    return connection.model ? `${name} · ${connection.model}` : name;
  }

  intervalKey(hours: number | null): string {
    return hours === null ? 'settings.profile.scheduleManual' : `settings.profile.schedule${hours}`;
  }

  rangeLabel(field: ProfileCapField): string {
    return formatRange(this.svc.state()!.bounds[field], this.language.lang());
  }

  onSchedule(event: Event): void {
    const raw = (event.target as HTMLSelectElement).value;
    this.svc.saveInstant({ intervalHours: raw === '' ? null : +raw });
  }

  onConnection(event: Event): void {
    const raw = (event.target as HTMLSelectElement).value;
    this.svc.saveInstant({ connectionId: raw === '' ? null : +raw });
  }

  /** Blank input is not zero: a cleared field keeps its last valid value. */
  onCapInput(field: ProfileCapField, target: WritableSignal<number>, event: Event): void {
    const raw = (event.target as HTMLInputElement).value;
    if (raw === '') return;
    target.set(+raw);
    this.svc.setTypedField(field, +raw);
  }

  onReset(): void {
    this.svc.discardDraft();
    this.keptCap.set(this.svc.state()?.keptCap ?? 0);
    this.viewedCap.set(this.svc.state()?.viewedCap ?? 0);
  }

  generate(): void {
    this.svc.startRun();
  }
}
