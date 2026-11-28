import { HttpClient, HttpErrorResponse } from '@angular/common/http';
import { Injectable, computed, inject, signal } from '@angular/core';
import { API_BASE_URL } from '../../core/api';
import { Problem, parseProblem } from '../../core/problem';
import { SubscriptionsStore } from '../../reader/state/subscriptions.store';

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

@Injectable({ providedIn: 'root' })
export class YourStreamSettingsService {
  private readonly http = inject(HttpClient);
  private readonly subscriptions = inject(SubscriptionsStore);
  private readonly endpoint = `${inject(API_BASE_URL)}/api/me/your-stream`;

  private readonly stored = signal<YourStreamState | null>(null);
  private readonly pending = signal<YourStreamSettings | null>(null);

  readonly failure = signal<Problem | null>(null);
  readonly saved = signal(false);

  readonly state = computed<YourStreamState | null>(() => {
    const stored = this.stored();
    return stored ? { ...stored, ...this.pending() } : null;
  });

  load(): void {
    this.failure.set(null);
    this.http.get<YourStreamState>(this.endpoint).subscribe({
      next: (state) => this.stored.set(state),
      error: (error: HttpErrorResponse) => this.failure.set(parseProblem(error)),
    });
  }

  save(settings: YourStreamSettings): void {
    this.pending.set(settings);
    this.failure.set(null);
    this.saved.set(false);
    this.http.put<YourStreamState>(this.endpoint, settings).subscribe({
      next: (state) => this.adopt(settings, state),
      error: (error: HttpErrorResponse) => this.reject(settings, error),
    });
  }

  private adopt(settings: YourStreamSettings, state: YourStreamState): void {
    if (this.pending() !== settings) return;
    const enabledChanged = this.stored()?.enabled !== state.enabled;
    this.stored.set(state);
    this.pending.set(null);
    this.saved.set(true);
    if (enabledChanged) this.subscriptions.reloadCountsQuietly();
  }

  private reject(settings: YourStreamSettings, error: HttpErrorResponse): void {
    if (this.pending() !== settings) return;
    this.pending.set(null);
    this.failure.set(parseProblem(error));
  }
}
