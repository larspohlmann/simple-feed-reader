// src/app/settings/recommendation-settings-card.component.ts
import { ChangeDetectionStrategy, Component, computed, inject, linkedSignal } from '@angular/core';
import { TranslocoPipe } from '@jsverse/transloco';
import { ButtonComponent } from '../shared/button/button.component';
import { ErrorBannerComponent } from '../shared/error-banner/error-banner.component';
import { FieldComponent } from '../shared/field/field.component';
import { ToggleComponent } from '../shared/toggle/toggle.component';
import { RecommendationSettingsService } from './recommendation-settings.service';

/**
 * The "For you" tuning card: the account's own guidance prompt, the history
 * caps and context window that shape a run, and the debug-persistence
 * switch. The fixed prompt layers are read-only here — they ship with the
 * app, not with the account — so they sit in a `<details>` rather than a
 * form field.
 */
@Component({
  selector: 'app-recommendation-settings-card',
  imports: [ButtonComponent, ErrorBannerComponent, FieldComponent, ToggleComponent, TranslocoPipe],
  providers: [RecommendationSettingsService],
  templateUrl: './recommendation-settings-card.component.html',
  styleUrl: './recommendation-settings-card.component.scss',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class RecommendationSettingsCardComponent {
  readonly svc = inject(RecommendationSettingsService);

  readonly guidance = linkedSignal<string>(() => this.svc.state()?.guidancePrompt ?? '');
  readonly favoritesCap = linkedSignal<number>(() => this.svc.state()?.favoritesCap ?? 0);
  readonly keptCap = linkedSignal<number>(() => this.svc.state()?.keptCap ?? 0);
  readonly viewedCap = linkedSignal<number>(() => this.svc.state()?.viewedCap ?? 0);
  readonly candidatePoolSize = linkedSignal<number>(() => this.svc.state()?.candidatePoolSize ?? 0);
  readonly picksLimit = linkedSignal<number>(() => this.svc.state()?.picksLimit ?? 0);
  /** The override the account may set; blank stays `null` ("use provider or default"). */
  readonly contextWindow = linkedSignal<number | null>(
    () => this.svc.state()?.contextWindowOverride ?? null,
  );
  readonly debugEnabled = linkedSignal<boolean>(() => this.svc.state()?.debugEnabled ?? false);

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

  constructor() {
    this.svc.load();
  }

  numberValue(event: Event): number {
    return +(event.target as HTMLInputElement).value;
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
      picksLimit: this.picksLimit(),
      contextWindow: this.contextWindow(),
      debugEnabled: this.debugEnabled(),
    });
  }
}
