// src/app/settings/ai-settings.service.ts
import { HttpClient, HttpErrorResponse } from '@angular/common/http';
import { Injectable, inject, signal } from '@angular/core';
import { Observable } from 'rxjs';
import { AiAvailabilityService } from '../core/ai-availability.service';
import { API_BASE_URL } from '../core/api';
import { AiFailure, aiFailure } from './ai-failure';

/**
 * One saved provider connection, as the multi-config endpoints report it.
 * The API key is never part of this shape — `apiKeyHint` is the last four
 * characters, which is all the settings page ever needs to show.
 */
export interface AiConfig {
  readonly id: number;
  readonly name: string | null;
  readonly baseUrl: string;
  readonly apiKeyHint: string | null;
  readonly model: string | null;
  readonly ready: boolean;
  readonly active: boolean;
}

export interface AiConfigList {
  readonly configs: AiConfig[];
  readonly activeId: number | null;
}

/**
 * The AI section's own state and writes, over an account that may hold
 * several provider configurations with at most one active.
 *
 * Every write answers with the configuration it touched (or, for `add`, the
 * new one plus its model list), so nothing here re-reads the list to find out
 * what happened. `configs` is kept in upsert form rather than replaced
 * wholesale on every write, so a row the account is mid-edit on does not lose
 * its place in the list when a sibling row changes.
 *
 * `AiAvailabilityService` is recomputed after every mutation from whichever
 * configuration is now active, exactly the same way regardless of which
 * write caused it — the alternative would be one bespoke availability update
 * per method, with every new method a fresh chance to forget it.
 *
 * The typed key is a parameter and never a field: it goes into one request
 * body and is gone. Nothing here writes it to storage, a URL or a log.
 */
@Injectable()
export class AiSettingsService {
  private readonly http = inject(HttpClient);
  private readonly base = inject(API_BASE_URL);
  private readonly availability = inject(AiAvailabilityService);

  readonly configs = signal<readonly AiConfig[]>([]);
  readonly activeId = signal<number | null>(null);
  readonly models = signal<readonly string[]>([]);
  readonly choosingModelFor = signal<number | null>(null);
  readonly busy = signal(false);
  readonly failure = signal<AiFailure | null>(null);

  load(): void {
    this.run(this.http.get<AiConfigList>(`${this.base}/api/me/ai`), (list) => {
      this.configs.set(list.configs);
      this.activeId.set(list.activeId);
      this.applyAvailability();
    });
  }

  add(name: string | null, baseUrl: string, apiKey: string): void {
    this.run(
      this.http.post<AiConfig & { models: string[] }>(`${this.base}/api/me/ai/configs`, {
        name,
        baseUrl,
        apiKey,
      }),
      (added) => {
        const { models, ...configuration } = added;
        this.upsert(configuration);
        this.models.set(models);
        this.choosingModelFor.set(configuration.id);
      },
    );
  }

  loadModels(id: number): void {
    this.run(
      this.http.get<{ models: string[] }>(`${this.base}/api/me/ai/configs/${id}/models`),
      (answer) => {
        this.models.set(answer.models);
        this.choosingModelFor.set(id);
      },
    );
  }

  chooseModel(id: number, model: string): void {
    this.run(
      this.http.put<AiConfig>(`${this.base}/api/me/ai/configs/${id}/model`, { model }),
      (config) => this.upsert(config),
    );
  }

  rename(id: number, name: string | null): void {
    this.run(
      this.http.put<AiConfig>(`${this.base}/api/me/ai/configs/${id}/name`, { name }),
      (config) => this.upsert(config),
    );
  }

  activate(id: number): void {
    this.run(
      this.http.put<AiConfig>(`${this.base}/api/me/ai/configs/${id}/active`, null),
      (config) => this.upsert(config),
    );
  }

  remove(id: number): void {
    this.run(this.http.delete<void>(`${this.base}/api/me/ai/configs/${id}`), () => this.drop(id));
  }

  /**
   * Replaces the row by id when it already exists, so a sibling row's write
   * never reorders the list; otherwise appends it, which is what `add`
   * needs. A row reported `active` clears the flag on every other row, the
   * same guarantee the server holds server-side (at most one active
   * configuration per account).
   */
  private upsert(config: AiConfig): void {
    const current = this.configs();
    const index = current.findIndex((each) => each.id === config.id);
    const replaced =
      index === -1
        ? [...current, config]
        : current.map((each, position) => (position === index ? config : each));

    this.configs.set(
      config.active
        ? replaced.map((each) => (each.id === config.id ? each : { ...each, active: false }))
        : replaced,
    );
    if (config.active) this.activeId.set(config.id);
    this.applyAvailability();
  }

  private drop(id: number): void {
    this.configs.set(this.configs().filter((each) => each.id !== id));
    if (this.activeId() === id) this.activeId.set(null);
    this.applyAvailability();
  }

  private applyAvailability(): void {
    const active = this.configs().find((each) => each.id === this.activeId());
    this.availability.apply({ ready: active?.ready ?? false, model: active?.model ?? null });
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
