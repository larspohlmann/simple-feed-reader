// src/app/settings/recommendation-settings.service.ts
import { HttpClient, HttpErrorResponse } from '@angular/common/http';
import { Injectable, inject, signal } from '@angular/core';
import { Observable } from 'rxjs';
import { API_BASE_URL } from '../core/api';
import { Problem, parseProblem } from '../core/problem';
import { ReaderApi } from '../reader/reader-api';
import { RecommendationsService } from '../reader/recommendations.service';

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
  /** How many days back the candidate pool reaches; 1-7, default 2 (#386). */
  readonly lookbackDays: number;
  readonly picksLimit: number;
  /** How many entries the provider scores per call. `null` packs batches
   *  automatically; see Task 5's `RecommendationPackingSettings`. */
  readonly batchCount: number | null;
  readonly contextWindow: number;
  readonly contextWindowOverride: number | null;
  readonly contextWindowSource: ContextWindowSource;
  readonly debugEnabled: boolean;
  /** Chosen auto-generate cadence in hours; null = only manually (#333). */
  readonly autoGenerateIntervalHours: number | null;
  /** Whether a background worker heartbeat is fresh; false hides the schedule's
   *  external-cron help note. */
  readonly workerAlive: boolean;
  /** The persisted, distilled preference profile the pipeline writes; read-only
   *  here, null until a run has generated one. */
  readonly profileText: string | null;
}

/** The eleven writable fields of the PUT body. */
export interface SaveRecommendationSettings {
  readonly guidancePrompt: string | null;
  readonly favoritesCap: number;
  readonly keptCap: number;
  readonly viewedCap: number;
  readonly candidatePoolSize: number;
  readonly lookbackDays: number;
  readonly picksLimit: number;
  readonly batchCount: number | null;
  readonly contextWindow: number | null;
  readonly debugEnabled: boolean;
  readonly autoGenerateIntervalHours: number | null;
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
  private readonly api = inject(ReaderApi);
  private readonly recommendations = inject(RecommendationsService);

  readonly state = signal<RecommendationSettingsState | null>(null);
  readonly busy = signal(false);
  readonly failure = signal<Problem | null>(null);
  readonly saved = signal(false);

  /** Kept apart from `busy`/`failure`/`saved`: the purge is a danger-zone
   *  action with its own confirmation line, not another outcome of the save
   *  form above it. */
  readonly purging = signal(false);
  readonly purgeFailure = signal<Problem | null>(null);
  readonly purged = signal(false);

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

  /** Clears every persisted recommendation. On success, refreshes the
   *  reader's own status so the sidebar count (Task 9) drops immediately
   *  rather than waiting for the next run. A 409 while a run is active comes
   *  back as an ordinary `Problem` -- the caller renders its `detail`
   *  verbatim, the same real-outcome treatment as any other rejected write,
   *  rather than a generic failure message. */
  purge(): void {
    this.purging.set(true);
    this.purgeFailure.set(null);
    this.purged.set(false);

    this.api.purgeRecommendations().subscribe({
      next: () => {
        this.purging.set(false);
        this.purged.set(true);
        this.recommendations.refreshStatus();
      },
      error: (error: HttpErrorResponse) => {
        this.purging.set(false);
        this.purgeFailure.set(parseProblem(error));
      },
    });
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
