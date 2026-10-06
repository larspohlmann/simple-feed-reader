import {
  ChangeDetectionStrategy,
  Component,
  Signal,
  computed,
  inject,
  linkedSignal,
  signal,
  untracked,
} from '@angular/core';
import { TranslocoPipe, TranslocoService } from '@jsverse/transloco';
import {
  offersTuning,
  RecommendationCapabilities,
  RecommendationTuningField,
} from '../../core/ai-availability.service';
import { ButtonComponent } from '../../shared/button/button.component';
import { ConfirmData } from '../../shared/confirm-dialog/confirm-dialog.component';
import { ConfirmService } from '../../shared/confirm-dialog/confirm.service';
import { DisclosureComponent } from '../../shared/disclosure/disclosure.component';
import { ErrorBannerComponent } from '../../shared/error-banner/error-banner.component';
import { FieldComponent } from '../../shared/field/field.component';
import { PasswordInputComponent } from '../../shared/password-input/password-input.component';
import { IconComponent } from '../../shared/icon/icon.component';
import { InfoTipComponent } from '../../shared/info-tip/info-tip.component';
import {
  SearchableSelectComponent,
  SelectOption,
} from '../../shared/searchable-select/searchable-select.component';
import { SegmentedChoiceComponent } from '../../shared/segmented-choice/segmented-choice.component';
import { SettingsGroupComponent } from '../../shared/settings/settings-group/settings-group.component';
import { SettingsStackComponent } from '../../shared/settings/stack/settings-stack.component';
import { ProfileSectionComponent } from '../profile/profile-section.component';
import { AiFailure, SERVER_TEXT_KINDS } from './ai-failure';
import {
  AiConfig,
  AiModel,
  AiSettingsService,
  MODEL_KINDS,
  ModelKind,
  SCORING_FAMILIES,
} from './ai-settings.service';
import { RecommendationDebugLogComponent } from '../recommendations/recommendation-debug-log.component';
import { RecommendationRunHistoryComponent } from '../recommendations/recommendation-run-history.component';
import { RecommendationSettingsCardComponent } from '../recommendations/recommendation-settings-card.component';

function offeredKinds(models: readonly AiModel[]): readonly ModelKind[] {
  return MODEL_KINDS.filter((kind) => models.some((model) => model.kind === kind));
}

/** The AI provider list: every saved configuration, one row each, plus the
 *  add form below. Each row carries its own model and readiness, at most
 *  one active; this component only reflects that (activation is decided
 *  server-side).
 *
 *  The model list is fetched on demand per row, not up front: each fetch is
 *  an outbound call to that row's provider against a shared rate budget. */
@Component({
  selector: 'app-ai-section',
  imports: [
    ButtonComponent,
    DisclosureComponent,
    ErrorBannerComponent,
    FieldComponent,
    PasswordInputComponent,
    IconComponent,
    InfoTipComponent,
    ProfileSectionComponent,
    RecommendationDebugLogComponent,
    RecommendationRunHistoryComponent,
    RecommendationSettingsCardComponent,
    SearchableSelectComponent,
    SegmentedChoiceComponent,
    SettingsGroupComponent,
    SettingsStackComponent,
    TranslocoPipe,
  ],
  providers: [AiSettingsService],
  templateUrl: './ai-section.component.html',
  styleUrl: './ai-section.component.scss',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class AiSectionComponent {
  readonly ai = inject(AiSettingsService);
  private readonly confirm = inject(ConfirmService);
  private readonly i18n = inject(TranslocoService);

  readonly newName = signal('');
  readonly newBaseUrl = signal('');
  readonly newApiKey = signal('');
  readonly renamingId = signal<number | null>(null);
  readonly renameText = signal('');

  readonly modelKinds = MODEL_KINDS;

  readonly unofferedKinds = computed(() => {
    const offered = offeredKinds(this.ai.models());
    return MODEL_KINDS.filter((kind) => !offered.includes(kind));
  });

  /** Opens on the kind of the row's saved model, or on the only kind offered. The rows
   *  are read untracked, so a write to any row keeps the reader's pick. */
  readonly modelKind = linkedSignal<readonly AiModel[], ModelKind>({
    source: () => this.ai.models(),
    computation: (models) => untracked(() => this.openingKind(models)),
  });

  /** The pick in whichever row and kind is showing a model list; unset once
   *  another row starts or the kind changes, so a stale pick can never be
   *  sent for another row or from the other kind's list. */
  readonly chosenModel = linkedSignal<{ row: number | null; kind: ModelKind }, string | null>({
    source: () => ({ row: this.ai.choosingModelFor(), kind: this.modelKind() }),
    computation: () => null,
  });

  /** The dropdown's fixed choices — the server's own `Range(1..8)`, so there
   *  is no invalid value the handler needs to guard against. */
  readonly concurrencyOptions: readonly number[] = [1, 2, 3, 4, 5, 6, 7, 8];

  /** The batch-cap field's placeholder when a connection makes no claim -- the
   *  backend's own ceiling, read from the list response so
   *  `RecommendationPackingSettingsModel::DEFAULT_MAXIMUM_BATCH_SIZE` stays its one
   *  definition. A string because `placeholder` rejects a number under strict
   *  template checking; empty until the list has loaded. */
  readonly defaultMaxBatchSize = computed(() => {
    const value = this.ai.defaultMaxBatchSize();
    return value === null ? '' : String(value);
  });

  readonly modelOptions = computed<SelectOption[]>(() =>
    this.ai
      .models()
      .filter((model) => model.kind === this.modelKind())
      .map((model) => ({
        value: model.id,
        label: [model.id, this.familyTag(model)].filter(Boolean).join(' · '),
        hint: this.modelHint(model.capabilities),
      })),
  );

  readonly chosenModelHint = computed(
    () => this.modelOptions().find((option) => option.value === this.chosenModel())?.hint ?? null,
  );

  /** A provider without rerankers never explains one. */
  readonly offeredScoringFamilies = computed(() => {
    const models = this.ai.models();
    return SCORING_FAMILIES.filter((family) => models.some((model) => model.family === family));
  });

  /** The key is optional — a local model server needs none — so only the
   *  address gates the button. */
  readonly canAdd = computed(() => this.newBaseUrl().trim().length > 0 && !this.ai.busy());

  readonly activeConfig = computed(() => this.ai.configs().find((config) => config.active));

  readonly activeReady = computed(() => this.activeConfig()?.ready ?? false);
  readonly activeModel = computed(() => this.activeConfig()?.model ?? null);

  /** Folds the provider group to a one-line summary once a ready active
   *  connection exists; shows the full manager otherwise. Starts `false`, so a
   *  first-time account (no ready connection) opens expanded for setup.
   *  "Manage"/"Done" toggle it; the folded view only shows while
   *  `activeReady()` is also true, so the flag can never strand neither view. */
  readonly managing = signal(false);

  /** The list card answers for the initial load; a row's own write answers
   *  in the row, and the add form answers under itself. One shared banner
   *  could only ever be right for one of the three (#415). */
  readonly listFailure: Signal<string | null> = computed(() => this.messageFor('load'));
  readonly addFailure: Signal<string | null> = computed(() => this.messageFor('add'));

  private modelHint(capabilities: RecommendationCapabilities): string | undefined {
    const differences = [
      capabilities.reasons ? null : 'settings.ai.modelHint.noReasons',
      capabilities.profile === 'borrowed' ? 'settings.ai.modelHint.borrowedProfile' : null,
    ].filter((key) => key !== null);

    return differences.map((key) => this.i18n.translate(key)).join(' · ') || undefined;
  }

  noneOfferedId(configId: number, kind: ModelKind): string {
    return `model-kind-none-${configId}-${kind}`;
  }

  noneOfferedIds(configId: number): string | null {
    const ids = this.unofferedKinds().map((kind) => this.noneOfferedId(configId, kind));
    return ids.length ? ids.join(' ') : null;
  }

  private familyTag(model: AiModel): string | null {
    return model.family ? this.i18n.translate(`settings.ai.modelFamily.${model.family}`) : null;
  }

  private openingKind(models: readonly AiModel[]): ModelKind {
    const row = this.ai.configs().find((config) => config.id === this.ai.choosingModelFor());
    const current = row?.kind ?? 'llm';
    const offered = offeredKinds(models);

    return offered.includes(current) ? current : (offered[0] ?? current);
  }

  rowFailure(configId: number): string | null {
    return this.failureFor(configId);
  }

  private failureFor(configId: number): string | null {
    const scoped = this.ai.failure();
    if (!scoped || scoped.scope.action !== 'row') return null;
    if (scoped.scope.configId !== configId) return null;

    return this.message(scoped.failure);
  }

  private messageFor(action: 'load' | 'add'): string | null {
    const scoped = this.ai.failure();
    if (!scoped || scoped.scope.action !== action) return null;

    return this.message(scoped.failure);
  }

  /** The server's own sentence for kinds whose next move is "correct the form
   *  and retry"; the rest keep a translated message, since the backend's prose
   *  doesn't say "enter the key again" -- and in German it wouldn't say it in
   *  German. A production 500 or dead connection carries no sentence, which is
   *  what the generic fallback is for. */
  private message(failure: AiFailure): string {
    if (!SERVER_TEXT_KINDS.has(failure.kind)) return this.errorText(failure.kind);
    if (failure.fieldErrors.length) return this.fieldText(failure);

    return failure.detail ?? this.errorText(failure.kind);
  }

  /** `apiKey` becomes "API key"; a path this build does not know keeps its
   *  raw name, which is still more use than dropping the message. */
  private fieldText(failure: AiFailure): string {
    return failure.fieldErrors
      .map((fieldError) => {
        const key = `settings.ai.fields.${fieldError.field}`;
        const label = this.i18n.translate(key);
        const name = label === key ? fieldError.field : label;

        return `${name}: ${fieldError.messages.join(' ')}`;
      })
      .join(' ');
  }

  private errorText(kind: AiFailure['kind']): string {
    return this.i18n.translate(`settings.ai.errors.${kind}`);
  }

  constructor() {
    this.ai.load();
  }

  value(event: Event): string {
    return (event.target as HTMLInputElement).value;
  }

  /** The host a config displays by when it has no name of its own — more
   *  useful here than the raw URL, and pairing it with the model tells two
   *  identically-hosted rows apart. */
  label(config: AiConfig): string {
    if (config.name) return config.name;
    const host = new URL(config.baseUrl).host;
    return config.model ? `${host} · ${config.model}` : host;
  }

  add(): void {
    this.ai.add(
      {
        name: this.newName().trim() || null,
        baseUrl: this.newBaseUrl().trim(),
        apiKey: this.newApiKey().trim(),
      },
      () => this.clearDraft(),
    );
  }

  /** Runs on success only. A rejected add leaves the endpoint and the key
   *  exactly as the account typed them. */
  private clearDraft(): void {
    this.newName.set('');
    this.newBaseUrl.set('');
    this.newApiKey.set('');
  }

  saveModel(id: number): void {
    const model = this.chosenModel();
    if (model) this.ai.chooseModel(id, model);
  }

  toggleReasoning(config: AiConfig, event: Event): void {
    this.ai.setReasoning(config.id, (event.target as HTMLInputElement).checked);
  }

  toggleSlowModel(config: AiConfig, event: Event): void {
    this.ai.setSlowModel(config.id, (event.target as HTMLInputElement).checked);
  }

  offersTuning(config: AiConfig, field: RecommendationTuningField): boolean {
    return offersTuning(config.capabilities, field);
  }

  offersAnyToggle(config: AiConfig): boolean {
    return (['suppressReasoning', 'slowModel', 'maxBatchSize'] as const).some((field) =>
      this.offersTuning(config, field),
    );
  }

  /** An empty field means "no claim, the default stands" — never `NaN`. */
  setMaxBatchSize(config: AiConfig, event: Event): void {
    const raw = (event.target as HTMLInputElement).value;
    this.ai.setMaxBatchSize(config.id, raw === '' ? null : Number(raw));
  }

  setBatchConcurrency(config: AiConfig, event: Event): void {
    const value = Number((event.target as HTMLSelectElement).value);
    this.ai.setBatchConcurrency(config.id, value);
  }

  startRename(config: AiConfig): void {
    this.renamingId.set(config.id);
    this.renameText.set(config.name ?? '');
  }

  cancelRename(): void {
    this.renamingId.set(null);
  }

  confirmRename(id: number): void {
    this.ai.rename(id, this.renameText().trim() || null);
    this.renamingId.set(null);
  }

  /** Same confirm-then-act shape as `RecommendationSettingsCardComponent.confirmPurge()`
   *  and `AccountSectionComponent.confirmThenDelete()`: open the shared dialog, act
   *  only on a truthy close. No `requireText` — a saved provider takes nothing
   *  else down with it. */
  confirmDelete(config: AiConfig): void {
    const data: ConfirmData = {
      title: this.i18n.translate('settings.ai.configs.deleteConfirmTitle'),
      message: this.i18n.translate('settings.ai.configs.deleteConfirmMessage'),
      confirmLabel: this.i18n.translate('settings.ai.configs.delete'),
      danger: true,
    };
    this.confirm.confirmThen(data, () => this.ai.remove(config.id));
  }
}
