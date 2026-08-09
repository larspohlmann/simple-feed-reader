// src/app/settings/ai-section.component.ts
import {
  ChangeDetectionStrategy,
  Component,
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
import { ErrorBannerComponent } from '../shared/error-banner/error-banner.component';
import { FieldComponent } from '../shared/field/field.component';
import {
  SearchableSelectComponent,
  SelectOption,
} from '../shared/searchable-select/searchable-select.component';
import { SettingsCardComponent } from '../shared/settings-card/settings-card.component';
import { AiConfig, AiSettingsService } from './ai-settings.service';
import { RecommendationDebugLogComponent } from './recommendation-debug-log.component';
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
    ErrorBannerComponent,
    FieldComponent,
    RecommendationDebugLogComponent,
    RecommendationSettingsCardComponent,
    SearchableSelectComponent,
    SettingsCardComponent,
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

  readonly modelOptions = computed<SelectOption[]>(() =>
    this.ai.models().map((model) => ({ value: model, label: model })),
  );

  readonly canAdd = computed(
    () =>
      this.newBaseUrl().trim().length > 0 && this.newApiKey().trim().length > 0 && !this.ai.busy(),
  );

  private readonly activeConfig = computed(() =>
    this.ai.configs().find((config) => config.id === this.ai.activeId()),
  );

  readonly activeReady = computed(() => this.activeConfig()?.ready ?? false);
  readonly activeModel = computed(() => this.activeConfig()?.model ?? null);

  /** The server's own sentence, shown for the ordinary provider refusals —
   *  it already says whether the endpoint or the key was the problem. */
  readonly failureDetail = computed(() => {
    const failure = this.ai.failure();
    return failure?.kind === 'provider' ? failure.detail : null;
  });

  /** Every other failure gets a translated message of its own, because the
   *  account's next move differs from "correct the form and retry". */
  readonly failureKey = computed(() => {
    const failure = this.ai.failure();
    return failure ? `settings.ai.errors.${failure.kind}` : null;
  });

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
    this.ai.add(this.newName().trim() || null, this.newBaseUrl().trim(), this.newApiKey().trim());
    this.newName.set('');
    this.newBaseUrl.set('');
    this.newApiKey.set('');
  }

  saveModel(id: number): void {
    const model = this.chosenModel();
    if (model) this.ai.chooseModel(id, model);
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
