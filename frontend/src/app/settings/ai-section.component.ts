// src/app/settings/ai-section.component.ts
import {
  ChangeDetectionStrategy,
  Component,
  Signal,
  computed,
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
import { PasswordInputComponent } from '../shared/password-input/password-input.component';
import { IconComponent } from '../shared/icon/icon.component';
import { InfoTipComponent } from '../shared/info-tip/info-tip.component';
import {
  SearchableSelectComponent,
  SelectOption,
} from '../shared/searchable-select/searchable-select.component';
import { SettingsGroupComponent } from '../shared/settings/settings-group/settings-group.component';
import { SettingsStackComponent } from '../shared/settings/stack/settings-stack.component';
import { AiFailure, SERVER_TEXT_KINDS } from './ai-failure';
import { AiConfig, AiSettingsService } from './ai-settings.service';
import { RecommendationDebugLogComponent } from './recommendation-debug-log.component';
import { RecommendationRunHistoryComponent } from './recommendation-run-history.component';
import { RecommendationSettingsCardComponent } from './recommendation-settings-card.component';

/**
 * The AI provider list: every configuration the account has saved, one row
 * each, plus the add form below. Unlike the earlier single-connection
 * section there is no longer "the" provider — each row carries its own
 * model and readiness, at most one is active, and this component's job is
 * only to reflect that (activation itself is decided server-side).
 *
 * The model list, like the earlier section, is fetched on demand per row
 * rather than for every row up front: each fetch is an outbound call to
 * that row's provider against a shared rate budget.
 */
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
    RecommendationDebugLogComponent,
    RecommendationRunHistoryComponent,
    RecommendationSettingsCardComponent,
    SearchableSelectComponent,
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
  private readonly dialog = inject(Dialog);
  private readonly i18n = inject(TranslocoService);

  readonly newName = signal('');
  readonly newBaseUrl = signal('');
  readonly newApiKey = signal('');
  readonly renamingId = signal<number | null>(null);
  readonly renameText = signal('');

  /** Whichever row is fetching or showing a model list; unset once a
   *  different row starts, so a stale pick from one row can never be sent
   *  for another. */
  readonly chosenModel = linkedSignal<number | null, string | null>({
    source: () => this.ai.choosingModelFor(),
    computation: () => null,
  });

  /** The dropdown's fixed choices — the server's own `Range(1..8)`, so there
   *  is no invalid value the handler needs to guard against. */
  readonly concurrencyOptions: readonly number[] = [1, 2, 3, 4, 5, 6, 7, 8];

  /** Shown as the batch-cap field's placeholder when a connection makes no
   *  claim — the ceiling the backend applies on its behalf, read from the list
   *  response so `RecommendationPackingSettings::DEFAULT_MAXIMUM_BATCH_SIZE`
   *  stays its one definition. A string, since `placeholder` is a string
   *  attribute and strict template checking rejects a number there; empty
   *  until the list has loaded. */
  readonly defaultMaxBatchSize = computed(() => {
    const value = this.ai.defaultMaxBatchSize();
    return value === null ? '' : String(value);
  });

  readonly modelOptions = computed<SelectOption[]>(() =>
    this.ai.models().map((model) => ({ value: model, label: model })),
  );

  /** The key is optional — a local model server needs none — so only the
   *  address gates the button. */
  readonly canAdd = computed(() => this.newBaseUrl().trim().length > 0 && !this.ai.busy());

  readonly activeConfig = computed(() => this.ai.configs().find((config) => config.active));

  readonly activeReady = computed(() => this.activeConfig()?.ready ?? false);
  readonly activeModel = computed(() => this.activeConfig()?.model ?? null);

  /**
   * Folds the provider group down to a one-line summary once a ready active
   * connection exists; the full connection manager is shown otherwise. Starts
   * `false`, so a ready account opens folded and a first-time account (no ready
   * active connection) sees the manager expanded for setup. "Manage" sets it
   * `true`; "Done" sets it back to `false`. A plain signal keeps it OnPush-safe:
   * the folded summary shows only while `activeReady()` is also true, so the
   * flag never strands the account with neither view.
   */
  readonly managing = signal(false);

  /** The list card answers for the initial load; a row's own write answers
   *  in the row, and the add form answers under itself. One shared banner
   *  could only ever be right for one of the three (#415). */
  readonly listFailure: Signal<string | null> = computed(() => this.messageFor('load'));
  readonly addFailure: Signal<string | null> = computed(() => this.messageFor('add'));

  rowFailure(configId: number): string | null {
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

  /**
   * The server's own sentence, for the kinds whose next move really is
   * "correct the form and retry". The rest keep a translated message, because
   * the backend's prose does not say "enter the key again" or "wait a few
   * minutes" — and in German it would not say it in German.
   *
   * A production 500 and a dead connection carry no sentence at all, which is
   * the one case the generic fallback is for.
   */
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
    const ref = this.dialog.open<boolean>(ConfirmDialogComponent, {
      data,
      role: 'alertdialog',
      panelClass: 'app-dialog',
    });
    ref.closed.subscribe((confirmed) => {
      if (confirmed) this.ai.remove(config.id);
    });
  }
}
