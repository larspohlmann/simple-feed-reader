// src/app/settings/ai-settings.service.ts
import { HttpClient, HttpErrorResponse } from '@angular/common/http';
import { Injectable, computed, inject, signal } from '@angular/core';
import { Observable } from 'rxjs';
import { AiAvailabilityService } from '../core/ai-availability.service';
import { API_BASE_URL } from '../core/api';
import { AiFailureScope, ScopedAiFailure, aiFailure } from './ai-failure';

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
  readonly suppressReasoning: boolean;
  readonly batchConcurrency: number;
}

export interface AiConfigList {
  readonly configs: AiConfig[];
  readonly activeId: number | null;
}

/**
 * What the add form holds. A parameter object rather than three arguments,
 * and deliberately not a field on this service: the plaintext key goes into
 * one request body and is gone.
 */
export interface AiDraft {
  readonly name: string | null;
  readonly baseUrl: string;
  readonly apiKey: string;
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
  readonly activeId = computed<number | null>(
    () => this.configs().find((each) => each.active)?.id ?? null,
  );
  readonly models = signal<readonly string[]>([]);
  readonly choosingModelFor = signal<number | null>(null);
  readonly busy = signal(false);
  readonly failure = signal<ScopedAiFailure | null>(null);

  /** Which row's parallel-requests dropdown most recently saved — drives a
   *  transient "Saved" confirmation on that one row. Scoped to this single
   *  write (not the shared `run()` helper) because every other field here
   *  saves silently; only the concurrency dropdown needed feedback, since it
   *  auto-saves on `change` with no submit button of its own. */
  readonly savedConcurrencyId = signal<number | null>(null);
  private savedConcurrencyTimer: ReturnType<typeof setTimeout> | null = null;

  load(): void {
    this.run({ action: 'load' }, this.http.get<AiConfigList>(`${this.base}/api/me/ai`), (list) => {
      this.configs.set(list.configs);
      this.applyAvailability();
    });
  }

  /**
   * `onAdded` runs on success only. The caller owns the draft — see AiDraft —
   * so this is how it learns the values are safe to clear. A rejected add
   * leaves the form exactly as the account typed it.
   */
  add(draft: AiDraft, onAdded: () => void): void {
    this.run(
      { action: 'add' },
      this.http.post<AiConfig & { models: string[] }>(`${this.base}/api/me/ai/configs`, {
        name: draft.name,
        baseUrl: draft.baseUrl,
        apiKey: draft.apiKey,
      }),
      (added) => {
        const { models, ...configuration } = added;
        this.upsert(configuration);
        this.models.set(models);
        this.choosingModelFor.set(configuration.id);
        onAdded();
      },
    );
  }

  loadModels(id: number): void {
    this.run(
      { action: 'row', configId: id },
      this.http.get<{ models: string[] }>(`${this.base}/api/me/ai/configs/${id}/models`),
      (answer) => {
        this.models.set(answer.models);
        this.choosingModelFor.set(id);
      },
    );
  }

  chooseModel(id: number, model: string): void {
    this.run(
      { action: 'row', configId: id },
      this.http.put<AiConfig>(`${this.base}/api/me/ai/configs/${id}/model`, { model }),
      (config) => this.upsert(config),
    );
  }

  rename(id: number, name: string | null): void {
    this.run(
      { action: 'row', configId: id },
      this.http.put<AiConfig>(`${this.base}/api/me/ai/configs/${id}/name`, { name }),
      (config) => this.upsert(config),
    );
  }

  setReasoning(id: number, suppressReasoning: boolean): void {
    this.run(
      { action: 'row', configId: id },
      this.http.put<AiConfig>(`${this.base}/api/me/ai/configs/${id}/reasoning`, {
        suppressReasoning,
      }),
      (config) => this.upsert(config),
    );
  }

  setBatchConcurrency(id: number, batchConcurrency: number): void {
    if (this.savedConcurrencyTimer !== null) clearTimeout(this.savedConcurrencyTimer);
    this.savedConcurrencyId.set(null);

    this.run(
      { action: 'row', configId: id },
      this.http.put<AiConfig>(`${this.base}/api/me/ai/configs/${id}/batch-concurrency`, {
        batchConcurrency,
      }),
      (config) => {
        this.upsert(config);
        this.savedConcurrencyId.set(config.id);
        this.savedConcurrencyTimer = setTimeout(() => this.savedConcurrencyId.set(null), 2500);
      },
    );
  }

  activate(id: number): void {
    this.run(
      { action: 'row', configId: id },
      this.http.put<AiConfig>(`${this.base}/api/me/ai/configs/${id}/active`, {}),
      (config) => this.upsert(config),
    );
  }

  duplicate(id: number): void {
    this.run(
      { action: 'row', configId: id },
      this.http.post<AiConfig>(`${this.base}/api/me/ai/configs/${id}/duplicate`, {}),
      (config) => this.upsert(config),
    );
  }

  remove(id: number): void {
    this.run(
      { action: 'row', configId: id },
      this.http.delete<void>(`${this.base}/api/me/ai/configs/${id}`),
      () => this.drop(id),
    );
  }

  /**
   * Replaces the row by id when it already exists, so a sibling row's write
   * never reorders the list; otherwise appends it, which is what `add`
   * needs. A row reported `active` clears the flag on whichever row held it
   * before — the rest are already inactive, so there is nothing else to
   * touch — the same guarantee the server holds server-side (at most one
   * active configuration per account).
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
        ? replaced.map((each) =>
            each.id !== config.id && each.active ? { ...each, active: false } : each,
          )
        : replaced,
    );
    this.applyAvailability();
  }

  private drop(id: number): void {
    this.configs.set(this.configs().filter((each) => each.id !== id));
    this.applyAvailability();
  }

  private applyAvailability(): void {
    const active = this.configs().find((each) => each.active);
    this.availability.apply({ ready: active?.ready ?? false, model: active?.model ?? null });
  }

  private run<T>(
    scope: AiFailureScope,
    request: Observable<T>,
    onSuccess: (value: T) => void,
  ): void {
    this.busy.set(true);
    this.failure.set(null);

    request.subscribe({
      next: (value) => {
        this.busy.set(false);
        onSuccess(value);
      },
      error: (error: HttpErrorResponse) => {
        this.busy.set(false);
        this.failure.set({ failure: aiFailure(error), scope });
      },
    });
  }
}
