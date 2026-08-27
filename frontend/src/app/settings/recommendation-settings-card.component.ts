// src/app/settings/recommendation-settings-card.component.ts
import {
  ChangeDetectionStrategy,
  Component,
  WritableSignal,
  computed,
  effect,
  inject,
  linkedSignal,
  signal,
} from '@angular/core';
import { Dialog } from '@angular/cdk/dialog';
import { TranslocoPipe, TranslocoService } from '@jsverse/transloco';
import { ButtonComponent } from '../shared/button/button.component';
import {
  ConfirmData,
  ConfirmDialogComponent,
} from '../shared/confirm-dialog/confirm-dialog.component';
import { DisclosureComponent } from '../shared/disclosure/disclosure.component';
import { ErrorBannerComponent } from '../shared/error-banner/error-banner.component';
import { FieldComponent } from '../shared/field/field.component';
import { InfoTipComponent } from '../shared/info-tip/info-tip.component';
import { SettingsGroupComponent } from '../shared/settings/settings-group/settings-group.component';
import { SettingsRowComponent } from '../shared/settings/settings-row/settings-row.component';
import { SettingsSaveBarComponent } from '../shared/settings/save-bar/save-bar.component';
import { SettingsStackComponent } from '../shared/settings/stack/settings-stack.component';
import { ToggleComponent } from '../shared/toggle/toggle.component';
import { CONFIRMATION_DURATION_MS, ToastService } from '../shared/toast/toast.service';
import { LanguageService } from '../core/language.service';
import { formatInteger } from '../reader/format';
import {
  RecommendationSettingsService,
  RecommendationExpertField,
  RecommendationSettingBounds,
  TypedRecommendationEdits,
} from './recommendation-settings.service';

/**
 * The "For You" tuning card, rebuilt on the settings primitives (#541). The
 * group's first row is the "show reasons" switch; the auto-generate cadence and
 * the look-back window (#386) are the two ordinary choices below it. All three
 * — plus the debug switch — persist the instant they change through
 * `saveInstant`. Everything the user types (the guidance prompt, the six caps,
 * the context window, the batch count) folds into one "Expert settings"
 * drill-in and is held as a pending draft until the save bar's Save; Reset drops
 * that draft and reseeds the inputs from the last-saved state. The fixed prompt
 * layers ship with the app, not the account, so they stay read-only in a nested
 * disclosure. The purge below is its own always-visible danger zone, copying
 * the confirm-then-act pattern from `account-section.component.ts`.
 *
 * Success is the global toast, fired uniformly off the service's `saved` flag:
 * every persist — instant or explicit — sets `saved`, an `effect` here toasts
 * once and resets the flag. Keying the toast on the actual HTTP success (not on
 * the click) is what keeps a rejected save silent.
 */
@Component({
  selector: 'app-recommendation-settings-card',
  imports: [
    ButtonComponent,
    DisclosureComponent,
    ErrorBannerComponent,
    FieldComponent,
    InfoTipComponent,
    SettingsGroupComponent,
    SettingsRowComponent,
    SettingsSaveBarComponent,
    SettingsStackComponent,
    ToggleComponent,
    TranslocoPipe,
  ],
  providers: [RecommendationSettingsService],
  templateUrl: './recommendation-settings-card.component.html',
  styleUrl: './recommendation-settings-card.component.scss',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class RecommendationSettingsCardComponent {
  readonly svc = inject(RecommendationSettingsService);
  private readonly dialog = inject(Dialog);
  private readonly i18n = inject(TranslocoService);
  private readonly language = inject(LanguageService);
  private readonly toast = inject(ToastService);

  // Typed fields: displayed here, held as a pending draft in the service until
  // the explicit Save. Each seeds from server truth and recomputes when the
  // state does (after a save); a keystroke overrides the seed via `.set`.
  readonly guidance = linkedSignal<string>(() => this.svc.state()?.guidancePrompt ?? '');
  readonly favoritesCap = linkedSignal<number>(() => this.svc.state()?.favoritesCap ?? 0);
  readonly keptCap = linkedSignal<number>(() => this.svc.state()?.keptCap ?? 0);
  readonly viewedCap = linkedSignal<number>(() => this.svc.state()?.viewedCap ?? 0);
  readonly candidatePoolSize = linkedSignal<number>(() => this.svc.state()?.candidatePoolSize ?? 0);
  readonly picksLimit = linkedSignal<number>(() => this.svc.state()?.picksLimit ?? 0);
  /** Blank stays `null` ("automatic packing"), same treatment as `contextWindow`. */
  readonly batchCount = linkedSignal<number | null>(() => this.svc.state()?.batchCount ?? null);
  /** The override the account may set; blank stays `null` ("use provider or default"). */
  readonly contextWindow = linkedSignal<number | null>(
    () => this.svc.state()?.contextWindowOverride ?? null,
  );
  private readonly clientValidationErrors = signal<
    Partial<Record<RecommendationExpertField, string>>
  >({});
  private readonly dismissedServerErrors = signal<Partial<Record<RecommendationExpertField, true>>>(
    {},
  );

  // Instant fields: persisted the moment they change, never held in the draft.
  readonly showReasons = linkedSignal<boolean>(() => this.svc.state()?.showReasons ?? false);
  readonly debugEnabled = linkedSignal<boolean>(() => this.svc.state()?.debugEnabled ?? false);
  readonly autoGenerateIntervalHours = linkedSignal<number | null>(
    () => this.svc.state()?.autoGenerateIntervalHours ?? null,
  );
  readonly lookbackDays = linkedSignal<number>(() => this.svc.state()?.lookbackDays ?? 0);
  readonly workerAlive = computed<boolean>(() => this.svc.state()?.workerAlive ?? false);

  /** The six cadence choices; null is "only manually". */
  readonly intervalOptions: readonly { readonly value: number | null; readonly key: string }[] = [
    { value: null, key: 'settings.ai.recommendations.autoGenerateManual' },
    { value: 1, key: 'settings.ai.recommendations.autoGenerate1' },
    { value: 3, key: 'settings.ai.recommendations.autoGenerate3' },
    { value: 6, key: 'settings.ai.recommendations.autoGenerate6' },
    { value: 12, key: 'settings.ai.recommendations.autoGenerate12' },
    { value: 24, key: 'settings.ai.recommendations.autoGenerate24' },
  ];

  /** The seven look-back choices, one per day (#386). */
  readonly lookbackOptions: readonly { readonly value: number; readonly key: string }[] = [
    { value: 1, key: 'settings.ai.recommendations.lookback1' },
    { value: 2, key: 'settings.ai.recommendations.lookback2' },
    { value: 3, key: 'settings.ai.recommendations.lookback3' },
    { value: 4, key: 'settings.ai.recommendations.lookback4' },
    { value: 5, key: 'settings.ai.recommendations.lookback5' },
    { value: 6, key: 'settings.ai.recommendations.lookback6' },
    { value: 7, key: 'settings.ai.recommendations.lookback7' },
  ];

  /** The effective context window, grouped for the hint line ("Reported by your
   *  provider: 8,192 tokens"). The `contextWindowFromProvider` string carries the
   *  `{{value}}` slot; the other two source strings ignore the param. */
  readonly reportedContextWindow = computed(() =>
    formatInteger(this.svc.state()?.contextWindow ?? 0, this.language.lang()),
  );

  /** The key for the hint line, decided by where the effective value came from. */
  readonly contextWindowSourceKey = computed(() => {
    const source = this.svc.state()?.contextWindowSource;
    if (source === 'user') return 'settings.ai.recommendations.contextWindowOverride';
    if (source === 'provider') return 'settings.ai.recommendations.contextWindowFromProvider';
    return 'settings.ai.recommendations.contextWindowFallback';
  });

  readonly contextWindowHint = computed(
    () =>
      `${this.i18n.translate(this.contextWindowSourceKey(), {
        value: this.reportedContextWindow(),
      })} · ${this.rangeLabel('contextWindow')}`,
  );

  /** Falls back to the problem's title so a failure with no `detail` (a
   *  network error, a gateway response) still shows something. */
  readonly failureMessage = computed(() => {
    const failure = this.svc.failure();
    if (!failure) return null;

    const messages = Object.entries(failure.errors ?? {})
      .filter(([field]) => !this.dismissedServerErrors()[field as RecommendationExpertField])
      .flatMap(([, errors]) => errors);
    return messages.length > 0 ? messages.join(' ') : (failure.detail ?? failure.title);
  });

  /** Same fallback as `failureMessage`; the 409 while a run is active
   *  arrives with a `detail` already written for the account to read. */
  readonly purgeFailureMessage = computed(() => {
    const failure = this.svc.purgeFailure();
    return failure ? (failure.detail ?? failure.title) : null;
  });

  constructor() {
    this.svc.load();
    // One success signal, fired on the actual HTTP success rather than the
    // click: every persist sets `saved`, so this toasts once and resets the
    // flag. A rejected save never sets `saved`, so it stays silent — the
    // `failure()` guard only hardens that.
    effect(() => {
      if (this.svc.saved() && !this.svc.failure()) {
        this.toast.show({
          message: this.i18n.translate('settings.ai.recommendations.saved'),
          durationMs: CONFIRMATION_DURATION_MS,
        });
        this.svc.saved.set(false);
      }
    });
  }

  /** Blank input is not zero: `+'' === 0` would silently coerce a cleared
   *  field to a value below every cap's minimum and ship a raw 422 on save.
   *  Leaving the signal untouched keeps its last valid value instead. */
  onCapInput(
    field: Extract<RecommendationExpertField, keyof TypedRecommendationEdits>,
    target: WritableSignal<number>,
    event: Event,
  ): void {
    const raw = (event.target as HTMLInputElement).value;
    if (raw === '') return;

    this.clearFieldError(field);
    const value = +raw;
    target.set(value);
    this.svc.setTypedField(field, value);
  }

  onGuidanceInput(event: Event): void {
    const value = (event.target as HTMLTextAreaElement).value;
    this.guidance.set(value);
    const trimmed = value.trim();
    this.svc.setTypedField('guidancePrompt', trimmed === '' ? null : trimmed);
  }

  onBatchCountInput(event: Event): void {
    this.clearFieldError('batchCount');
    const value = this.nullableNumberValue(event);
    this.batchCount.set(value);
    this.svc.setTypedField('batchCount', value);
  }

  onContextWindowInput(event: Event): void {
    this.clearFieldError('contextWindow');
    const value = this.nullableNumberValue(event);
    this.contextWindow.set(value);
    this.svc.setTypedField('contextWindow', value);
  }

  onShowReasons(value: boolean): void {
    this.showReasons.set(value);
    this.svc.saveInstant({ showReasons: value });
  }

  onDebug(value: boolean): void {
    this.debugEnabled.set(value);
    this.svc.saveInstant({ debugEnabled: value });
  }

  onAutoGenerate(event: Event): void {
    const raw = (event.target as HTMLSelectElement).value;
    const value = raw === '' ? null : +raw;
    this.autoGenerateIntervalHours.set(value);
    this.svc.saveInstant({ autoGenerateIntervalHours: value });
  }

  onLookbackDays(event: Event): void {
    const value = +(event.target as HTMLSelectElement).value;
    this.lookbackDays.set(value);
    this.svc.saveInstant({ lookbackDays: value });
  }

  private nullableNumberValue(event: Event): number | null {
    const raw = (event.target as HTMLInputElement).value;
    return raw === '' ? null : +raw;
  }

  /** Empties the guidance prompt back to the app default; recorded as a pending
   *  edit (`guidancePrompt: null`) that the save bar persists. */
  resetGuidance(): void {
    this.guidance.set('');
    this.svc.setTypedField('guidancePrompt', null);
  }

  /** Seeds every expert input from the factory values the API supplied, without
   *  writing them until the explicit Save. */
  resetToFactoryDefaults(): void {
    const defaults = this.svc.state()?.expertDefaults;
    if (!defaults) return;

    this.svc.resetExpertDraft(defaults);
    this.guidance.set(defaults.guidancePrompt ?? '');
    this.favoritesCap.set(defaults.favoritesCap);
    this.keptCap.set(defaults.keptCap);
    this.viewedCap.set(defaults.viewedCap);
    this.candidatePoolSize.set(defaults.candidatePoolSize);
    this.picksLimit.set(defaults.picksLimit);
    this.batchCount.set(defaults.batchCount);
    this.contextWindow.set(defaults.contextWindow);
    this.clientValidationErrors.set({});
    this.dismissedServerErrors.set({});
  }

  /** The explicit Save flushes the accumulated typed draft over the last-saved
   *  baseline; the service builds the body. */
  onSave(): void {
    if (this.validateExpertFields()) return;

    this.dismissedServerErrors.set({});
    this.svc.save();
  }

  range(field: RecommendationExpertField): RecommendationSettingBounds {
    return this.svc.state()!.expertBounds[field];
  }

  rangeLabel(field: RecommendationExpertField): string {
    const bounds = this.range(field);
    return `${formatInteger(bounds.min, this.language.lang())}–${formatInteger(bounds.max, this.language.lang())}`;
  }

  fieldError(field: RecommendationExpertField): string | null {
    const clientError = this.clientValidationErrors()[field];
    if (clientError) return clientError;
    if (this.dismissedServerErrors()[field]) return null;
    return this.svc.failure()?.errors?.[field]?.join(' ') ?? null;
  }

  isFieldInvalid(field: RecommendationExpertField): boolean {
    return this.fieldError(field) !== null;
  }

  /** Drops the pending typed edits and reseeds every typed input from the last
   *  saved state, so a Reset with no intervening save still visibly restores
   *  the inputs (a `linkedSignal` only recomputes when `state` changes). */
  onReset(): void {
    this.svc.discardDraft();
    const state = this.svc.state();
    this.guidance.set(state?.guidancePrompt ?? '');
    this.favoritesCap.set(state?.favoritesCap ?? 0);
    this.keptCap.set(state?.keptCap ?? 0);
    this.viewedCap.set(state?.viewedCap ?? 0);
    this.candidatePoolSize.set(state?.candidatePoolSize ?? 0);
    this.picksLimit.set(state?.picksLimit ?? 0);
    this.batchCount.set(state?.batchCount ?? null);
    this.contextWindow.set(state?.contextWindowOverride ?? null);
    this.clientValidationErrors.set({});
    this.dismissedServerErrors.set({});
  }

  private validateExpertFields(): boolean {
    const errors = (
      Object.entries(this.expertFieldValues()) as [RecommendationExpertField, number | null][]
    ).reduce<Partial<Record<RecommendationExpertField, string>>>(
      (validationErrors, [field, value]) => {
        if (value === null || this.isInRange(field, value)) return validationErrors;

        return { ...validationErrors, [field]: this.rangeLabel(field) };
      },
      {},
    );
    this.clientValidationErrors.set(errors);
    return Object.keys(errors).length > 0;
  }

  private expertFieldValues(): Record<RecommendationExpertField, number | null> {
    return {
      favoritesCap: this.favoritesCap(),
      keptCap: this.keptCap(),
      viewedCap: this.viewedCap(),
      candidatePoolSize: this.candidatePoolSize(),
      picksLimit: this.picksLimit(),
      batchCount: this.batchCount(),
      contextWindow: this.contextWindow(),
    };
  }

  private isInRange(field: RecommendationExpertField, value: number): boolean {
    const bounds = this.range(field);
    return Number.isInteger(value) && value >= bounds.min && value <= bounds.max;
  }

  private clearFieldError(field: RecommendationExpertField): void {
    this.clientValidationErrors.update((errors) => ({ ...errors, [field]: undefined }));
    this.dismissedServerErrors.update((errors) => ({ ...errors, [field]: true }));
  }

  /** Same confirm-then-act shape as `AccountSectionComponent.confirmThenDelete()`:
   *  open the shared dialog, act only on a truthy close. No `requireText` here --
   *  unlike the account, a purge does not take the account itself with it, and a
   *  new run rebuilds the list. */
  confirmPurge(): void {
    const data: ConfirmData = {
      title: this.i18n.translate('settings.ai.recommendations.purgeConfirm'),
      message: this.i18n.translate('settings.ai.recommendations.purgeExplain'),
      confirmLabel: this.i18n.translate('settings.ai.recommendations.purge'),
      danger: true,
    };
    const ref = this.dialog.open<boolean>(ConfirmDialogComponent, {
      data,
      role: 'alertdialog',
      panelClass: 'app-dialog',
    });
    ref.closed.subscribe((confirmed) => {
      if (confirmed) this.svc.purge();
    });
  }
}
