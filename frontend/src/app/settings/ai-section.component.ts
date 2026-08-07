// src/app/settings/ai-section.component.ts
import {
  ChangeDetectionStrategy,
  Component,
  computed,
  inject,
  linkedSignal,
  signal,
} from '@angular/core';
import { TranslocoPipe } from '@jsverse/transloco';
import { ButtonComponent } from '../shared/button/button.component';
import { ErrorBannerComponent } from '../shared/error-banner/error-banner.component';
import { FieldComponent } from '../shared/field/field.component';
import {
  SearchableSelectComponent,
  SelectOption,
} from '../shared/searchable-select/searchable-select.component';
import { SettingsCardComponent } from '../shared/settings-card/settings-card.component';
import { AiSettingsService } from './ai-settings.service';
import { RecommendationSettingsCardComponent } from './recommendation-settings-card.component';

/**
 * The AI provider form. Two writes, in the order the flow needs them: the
 * connection first, because the model list cannot be fetched without a key,
 * then the model.
 *
 * The model list is fetched on demand rather than on every mount: each fetch
 * is an outbound call to the provider against a 30-per-15-minutes budget, and
 * an account that only opened the settings page needs no such call.
 */
@Component({
  selector: 'app-ai-section',
  imports: [
    ButtonComponent,
    ErrorBannerComponent,
    FieldComponent,
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

  /**
   * Both follow the stored state: a write answers with the new one, so saving a
   * connection re-fills the endpoint from what the server kept, and clearing
   * the provider empties the form without a second code path.
   */
  readonly baseUrl = linkedSignal<string>(() => this.ai.state().baseUrl ?? '');
  readonly chosenModel = linkedSignal<string | null>(() => this.ai.state().model);

  /** Never a stored field, never persisted: typed, sent, and dropped. */
  readonly apiKey = signal('');

  readonly options = computed<SelectOption[]>(() =>
    this.ai.models().map((model) => ({ value: model, label: model })),
  );

  readonly canSaveConnection = computed(
    () => this.baseUrl().trim().length > 0 && this.apiKey().trim().length > 0 && !this.ai.busy(),
  );

  /** The server's own sentence, shown for the ordinary provider refusals — it
   *  already says whether the endpoint or the key was the problem. */
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

  saveConnection(): void {
    this.ai.saveConnection(this.baseUrl().trim(), this.apiKey().trim());
    this.apiKey.set('');
  }

  saveModel(): void {
    const model = this.chosenModel();
    if (model) this.ai.saveModel(model);
  }
}
