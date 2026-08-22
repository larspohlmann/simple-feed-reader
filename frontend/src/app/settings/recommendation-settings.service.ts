// src/app/settings/recommendation-settings.service.ts
import { HttpClient, HttpErrorResponse } from '@angular/common/http';
import { Injectable, computed, inject, signal } from '@angular/core';
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
  /** Whether "For you" shows each pick's one-line reason (#541). */
  readonly showReasons: boolean;
}

/** The writable fields of the PUT body. `showReasons` is the twelfth field;
 *  every write built through this service (`bodyFromState`, `saveInstant`,
 *  `save`) always sends it. */
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
  readonly showReasons: boolean;
}

/** The typed text/number fields the explicit Save persists; the toggles and
 *  selects go through `saveInstant` instead. */
export type TypedRecommendationEdits = Partial<SaveRecommendationSettings>;

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

  /** Pending text/number edits waiting behind an explicit Save; empty means
   *  the form matches the last-saved `state`. */
  readonly draft = signal<TypedRecommendationEdits>({});
  /** True while `draft` holds unsaved typed edits; the Save affordance reads it. */
  readonly dirty = computed(() => Object.keys(this.draft()).length > 0);

  /** Kept apart from `busy`/`failure`/`saved`: the purge is a danger-zone
   *  action with its own confirmation line, not another outcome of the save
   *  form above it. */
  readonly purging = signal(false);
  readonly purgeFailure = signal<Problem | null>(null);
  readonly purged = signal(false);

  load(): void {
    this.run(
      this.http.get<RecommendationSettingsState>(`${this.base}/api/me/ai/recommendations`),
      (state) => this.commit(state),
    );
  }

  /** The explicit Save: last-saved baseline plus the pending typed edits. On
   *  success the pending edits are now server truth, so the draft clears. */
  save(): void {
    const current = this.state();
    if (!current) return;
    const payload = { ...this.bodyFromState(current), ...this.draft() };
    this.put(payload, (state) => {
      this.commit(state);
      this.saved.set(true);
    });
  }

  /** The instant path for toggles and selects: it composes the override over
   *  the last-saved state, so it never carries pending typed edits — and it
   *  leaves any pending typed edits in the draft untouched. `saved` flips true
   *  on success here too, so the card's one success signal is uniform across
   *  the instant and the explicit path (#541): the card toasts off `saved`
   *  rather than guessing which write finished. It does not clear the draft:
   *  an instant toggle must never discard a pending typed edit. */
  saveInstant(partial: Partial<SaveRecommendationSettings>): void {
    const current = this.state();
    if (!current) return;
    this.put({ ...this.bodyFromState(current), ...partial }, (state) => {
      this.state.set(state);
      this.saved.set(true);
    });
  }

  /** Records one pending typed edit without a write; the explicit Save flushes
   *  the accumulated `draft`. */
  setTypedField<Field extends keyof TypedRecommendationEdits>(
    field: Field,
    value: TypedRecommendationEdits[Field],
  ): void {
    this.draft.update((draft) => ({ ...draft, [field]: value }));
  }

  /** Drops every pending typed edit and restores the clean baseline, without a
   *  write. The card's Reset calls this, then reseeds its typed inputs from
   *  `state`. Mirrors the draft half of `commit`. */
  discardDraft(): void {
    this.draft.set({});
  }

  /** The single mapping from server truth to the writable body. `contextWindow`
   *  is the account's nullable override (`contextWindowOverride`), not the
   *  resolved window — matching the card's own save body. */
  private bodyFromState(state: RecommendationSettingsState): SaveRecommendationSettings {
    return {
      guidancePrompt: state.guidancePrompt,
      favoritesCap: state.favoritesCap,
      keptCap: state.keptCap,
      viewedCap: state.viewedCap,
      candidatePoolSize: state.candidatePoolSize,
      lookbackDays: state.lookbackDays,
      picksLimit: state.picksLimit,
      batchCount: state.batchCount,
      contextWindow: state.contextWindowOverride,
      debugEnabled: state.debugEnabled,
      autoGenerateIntervalHours: state.autoGenerateIntervalHours,
      showReasons: state.showReasons,
    };
  }

  private put(
    body: SaveRecommendationSettings,
    onSuccess: (state: RecommendationSettingsState) => void,
  ): void {
    this.run(
      this.http.put<RecommendationSettingsState>(`${this.base}/api/me/ai/recommendations`, body),
      (state) => onSuccess(state),
    );
  }

  /** Adopts a server state as the new clean baseline: the pending typed edits
   *  are now saved, so the draft clears. */
  private commit(state: RecommendationSettingsState): void {
    this.state.set(state);
    this.draft.set({});
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
