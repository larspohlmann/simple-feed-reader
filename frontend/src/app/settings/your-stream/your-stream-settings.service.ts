import { HttpErrorResponse } from '@angular/common/http';
import { Injectable, computed, inject, signal } from '@angular/core';
import { parseProblem } from '../../core/problem';
import { SubscriptionsStore } from '../../reader/state/subscriptions.store';
import { DraftSettingsService } from '../../shared/settings/draft-settings.service';

export const YOUR_STREAM_PRECISIONS = [
  'narrowest',
  'narrow',
  'balanced',
  'wide',
  'widest',
] as const;

export type YourStreamPrecision = (typeof YOUR_STREAM_PRECISIONS)[number];

export interface YourStreamSettings {
  readonly enabled: boolean;
  readonly precision: YourStreamPrecision;
}

export interface YourStreamWord {
  readonly word: string;
  readonly weight: number;
}

export interface YourStreamLanguageWords {
  readonly language: string | null;
  readonly words: readonly YourStreamWord[];
}

export interface YourStreamState extends YourStreamSettings {
  readonly defaultPrecision: YourStreamPrecision;
  readonly words: readonly YourStreamLanguageWords[];
}

/**
 * `state` is the last answer; `shown` lays the newest unanswered choice over it,
 * and an answer or failure that a newer choice overtook is dropped.
 */
@Injectable({ providedIn: 'root' })
export class YourStreamSettingsService extends DraftSettingsService<
  YourStreamState,
  YourStreamSettings,
  object
> {
  private readonly subscriptions = inject(SubscriptionsStore);
  private readonly pendingChoice = signal<YourStreamSettings | null>(null);

  protected readonly endpoint = `${this.base}/api/me/your-stream`;

  readonly shown = computed<YourStreamState | null>(() => {
    const state = this.state();
    return state ? { ...state, ...this.pendingChoice() } : null;
  });

  override saveInstant(partial: Partial<YourStreamSettings>): void {
    const shown = this.shown();
    if (!shown) return;
    const choice = { ...this.bodyFromState(shown), ...partial };
    this.pendingChoice.set(choice);
    this.failure.set(null);
    this.saved.set(false);
    this.http.put<YourStreamState>(this.endpoint, choice).subscribe({
      next: (state) => this.adopt(choice, state),
      error: (error: HttpErrorResponse) => this.reject(choice, error),
    });
  }

  protected bodyFromState(state: YourStreamState): YourStreamSettings {
    return { enabled: state.enabled, precision: state.precision };
  }

  private adopt(choice: YourStreamSettings, state: YourStreamState): void {
    if (this.pendingChoice() !== choice) return;
    const enabledChanged = this.state()?.enabled !== state.enabled;
    this.state.set(state);
    this.pendingChoice.set(null);
    this.saved.set(true);
    if (enabledChanged) this.subscriptions.reloadCountsQuietly();
  }

  private reject(choice: YourStreamSettings, error: HttpErrorResponse): void {
    if (this.pendingChoice() !== choice) return;
    this.pendingChoice.set(null);
    this.failure.set(parseProblem(error));
  }
}
