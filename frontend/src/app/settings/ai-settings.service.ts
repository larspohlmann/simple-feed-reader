// src/app/settings/ai-settings.service.ts
import { HttpClient, HttpErrorResponse } from '@angular/common/http';
import { Injectable, inject, signal } from '@angular/core';
import { Observable } from 'rxjs';
import { AiAvailabilityService, AiState } from '../core/ai-availability.service';
import { API_BASE_URL } from '../core/api';
import { AiFailure, aiFailure } from './ai-failure';

const EMPTY: AiState = {
  configured: false,
  baseUrl: null,
  apiKeyHint: null,
  model: null,
  ready: false,
};

/**
 * The AI section's own state and writes.
 *
 * Every write answers with the new state, so nothing here re-reads the profile
 * to find out what happened, and `AiAvailabilityService` is fed from the same
 * answer.
 *
 * The typed key is a parameter and never a field: it goes into one request body
 * and is gone. Nothing here writes it to storage, a URL or a log.
 */
@Injectable()
export class AiSettingsService {
  private readonly http = inject(HttpClient);
  private readonly base = inject(API_BASE_URL);
  private readonly availability = inject(AiAvailabilityService);

  readonly state = signal<AiState>(EMPTY);
  readonly models = signal<readonly string[]>([]);
  readonly busy = signal(false);
  readonly failure = signal<AiFailure | null>(null);

  load(): void {
    this.run(this.http.get<AiState>(`${this.base}/api/me/ai`), (state) => this.take(state));
  }

  saveConnection(baseUrl: string, apiKey: string): void {
    this.run(
      this.http.put<AiState & { models: string[] }>(`${this.base}/api/me/ai/connection`, {
        baseUrl,
        apiKey,
      }),
      (answer) => {
        this.take(answer);
        this.models.set(answer.models);
      },
    );
  }

  refreshModels(): void {
    this.run(this.http.get<{ models: string[] }>(`${this.base}/api/me/ai/models`), (answer) =>
      this.models.set(answer.models),
    );
  }

  saveModel(model: string): void {
    this.run(this.http.put<AiState>(`${this.base}/api/me/ai/model`, { model }), (state) =>
      this.take(state),
    );
  }

  forget(): void {
    this.run(this.http.delete<void>(`${this.base}/api/me/ai`), () => {
      this.take(EMPTY);
      this.models.set([]);
    });
  }

  private take(state: AiState): void {
    this.state.set(state);
    this.availability.apply(state);
  }

  private run<T>(request: Observable<T>, onSuccess: (value: T) => void): void {
    this.busy.set(true);
    this.failure.set(null);

    request.subscribe({
      next: (value) => {
        this.busy.set(false);
        onSuccess(value);
      },
      error: (error: HttpErrorResponse) => {
        this.busy.set(false);
        this.failure.set(aiFailure(error));
      },
    });
  }
}
