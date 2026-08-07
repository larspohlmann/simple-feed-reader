// src/app/settings/recommendation-settings.service.ts
import { HttpClient, HttpErrorResponse } from '@angular/common/http';
import { Injectable, inject, signal } from '@angular/core';
import { Observable } from 'rxjs';
import { API_BASE_URL } from '../core/api';
import { Problem, parseProblem } from '../core/problem';

export type ContextWindowSource = 'user' | 'provider' | 'fallback';

/** Mirrors the GET payload 1:1 — see Task 14's `RecommendationSettingsJson`. */
export interface RecommendationSettingsState {
  readonly guidancePrompt: string | null;
  readonly defaultGuidancePrompt: string;
  readonly fixedPrompt: {
    readonly role: string;
    readonly outputContract: string;
  };
  readonly favoritesCap: number;
  readonly keptCap: number;
  readonly viewedCap: number;
  readonly candidatePoolSize: number;
  readonly picksLimit: number;
  readonly contextWindow: number;
  readonly contextWindowOverride: number | null;
  readonly contextWindowSource: ContextWindowSource;
  readonly debugEnabled: boolean;
}

/** The eight writable fields of the PUT body. */
export interface SaveRecommendationSettings {
  readonly guidancePrompt: string | null;
  readonly favoritesCap: number;
  readonly keptCap: number;
  readonly viewedCap: number;
  readonly candidatePoolSize: number;
  readonly picksLimit: number;
  readonly contextWindow: number | null;
  readonly debugEnabled: boolean;
}

/**
 * The recommendation settings card's own state and writes, mirroring
 * `AiSettingsService`'s shape: a `load`/`save` pair, one private `run<T>()`,
 * and `saved` as a one-shot success flag the card resets on the next edit.
 */
@Injectable()
export class RecommendationSettingsService {
  private readonly http = inject(HttpClient);
  private readonly base = inject(API_BASE_URL);

  readonly state = signal<RecommendationSettingsState | null>(null);
  readonly busy = signal(false);
  readonly failure = signal<Problem | null>(null);
  readonly saved = signal(false);

  load(): void {
    this.run(
      this.http.get<RecommendationSettingsState>(`${this.base}/api/me/ai/recommendations`),
      (state) => this.state.set(state),
    );
  }

  save(body: SaveRecommendationSettings): void {
    this.run(
      this.http.put<RecommendationSettingsState>(`${this.base}/api/me/ai/recommendations`, body),
      (state) => {
        this.state.set(state);
        this.saved.set(true);
      },
    );
  }

  private run<T>(request: Observable<T>, onSuccess: (value: T) => void): void {
    this.busy.set(true);
    this.failure.set(null);
    this.saved.set(false);

    request.subscribe({
      next: (value) => {
        this.busy.set(false);
        onSuccess(value);
      },
      error: (error: HttpErrorResponse) => {
        this.busy.set(false);
        this.failure.set(parseProblem(error));
      },
    });
  }
}
