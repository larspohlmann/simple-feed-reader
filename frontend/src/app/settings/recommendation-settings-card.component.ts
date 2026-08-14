// src/app/settings/recommendation-settings-card.component.ts
import {
  ChangeDetectionStrategy,
  Component,
  WritableSignal,
  computed,
  inject,
  linkedSignal,
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
import { ToggleComponent } from '../shared/toggle/toggle.component';
import { RecommendationSettingsService } from './recommendation-settings.service';

/**
 * The "For you" tuning card: the account's own guidance prompt, the history
 * caps and context window that shape a run, and the debug-persistence
 * switch. The fixed prompt layers are read-only here — they ship with the
 * app, not with the account — so they sit in a `<details>` rather than a
 * form field. The six numeric tuning fields, the context window and the
 * fixed prompt fold into one "Expert settings" disclosure (#321 decision
 * 6A); the auto-generate cadence and the look-back window (#386) stay
 * outside it, because they are the two choices an ordinary account does
 * make. The purge below is its own
 * danger zone, always visible, copying the confirm-then-act pattern from
 * `account-section.component.ts`.
 */
@Component({
  selector: 'app-recommendation-settings-card',
  imports: [
    ButtonComponent,
    DisclosureComponent,
    ErrorBannerComponent,
    FieldComponent,
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
  readonly debugEnabled = linkedSignal<boolean>(() => this.svc.state()?.debugEnabled ?? false);
  readonly autoGenerateIntervalHours = linkedSignal<number | null>(
    () => this.svc.state()?.autoGenerateIntervalHours ?? null,
  );
  readonly lookbackDays = linkedSignal<number>(() => this.svc.state()?.lookbackDays ?? 2);
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

  /** The key for the hint line, decided by where the effective value came from. */
  readonly contextWindowSourceKey = computed(() => {
    const source = this.svc.state()?.contextWindowSource;
    if (source === 'user') return 'settings.ai.recommendations.contextWindowOverride';
    if (source === 'provider') return 'settings.ai.recommendations.contextWindowFromProvider';
    return 'settings.ai.recommendations.contextWindowFallback';
  });

  /** Falls back to the problem's title so a failure with no `detail` (a
   *  network error, a gateway response) still shows something. */
  readonly failureMessage = computed(() => {
    const failure = this.svc.failure();
    return failure ? (failure.detail ?? failure.title) : null;
  });

  /** Same fallback as `failureMessage`; the 409 while a run is active
   *  arrives with a `detail` already written for the account to read. */
  readonly purgeFailureMessage = computed(() => {
    const failure = this.svc.purgeFailure();
    return failure ? (failure.detail ?? failure.title) : null;
  });

  constructor() {
    this.svc.load();
  }

  /** Blank input is not zero: `+'' === 0` would silently coerce a cleared
   *  field to a value below every cap's minimum and ship a raw 422 on save.
   *  Leaving the signal untouched keeps its last valid value instead. */
  setNumber(target: WritableSignal<number>, event: Event): void {
    const raw = (event.target as HTMLInputElement).value;
    if (raw === '') return;
    target.set(+raw);
  }

  setAutoGenerate(event: Event): void {
    const raw = (event.target as HTMLSelectElement).value;
    this.autoGenerateIntervalHours.set(raw === '' ? null : +raw);
  }

  setLookbackDays(event: Event): void {
    this.lookbackDays.set(+(event.target as HTMLSelectElement).value);
  }

  nullableNumberValue(event: Event): number | null {
    const raw = (event.target as HTMLInputElement).value;
    return raw === '' ? null : +raw;
  }

  textValue(event: Event): string {
    return (event.target as HTMLTextAreaElement).value;
  }

  resetGuidance(): void {
    this.guidance.set('');
  }

  save(): void {
    const trimmed = this.guidance().trim();
    this.svc.save({
      guidancePrompt: trimmed === '' ? null : trimmed,
      favoritesCap: this.favoritesCap(),
      keptCap: this.keptCap(),
      viewedCap: this.viewedCap(),
      candidatePoolSize: this.candidatePoolSize(),
      lookbackDays: this.lookbackDays(),
      picksLimit: this.picksLimit(),
      batchCount: this.batchCount(),
      contextWindow: this.contextWindow(),
      debugEnabled: this.debugEnabled(),
      autoGenerateIntervalHours: this.autoGenerateIntervalHours(),
    });
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
