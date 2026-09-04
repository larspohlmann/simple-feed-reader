import { HttpErrorResponse } from '@angular/common/http';
import { Injectable, inject, signal } from '@angular/core';
import { Problem, parseProblem } from '../core/problem';
import { DraftSettingsService } from '../shared/settings/draft-settings.service';
import { ReaderApi } from '../reader/reader-api';
import { RecommendationsService } from '../reader/recommendations.service';

export type ContextWindowSource = 'user' | 'provider' | 'fallback';

export interface RecommendationExpertDefaults {
  readonly guidancePrompt: string | null;
  readonly favoritesCap: number;
  readonly keptCap: number;
  readonly viewedCap: number;
  readonly candidatePoolSize: number;
  readonly picksLimit: number;
  readonly batchCount: number | null;
  readonly contextWindow: number | null;
}

export interface RecommendationSettingBounds {
  readonly min: number;
  readonly max: number;
}

export type RecommendationExpertField =
  | 'favoritesCap'
  | 'keptCap'
  | 'viewedCap'
  | 'candidatePoolSize'
  | 'picksLimit'
  | 'batchCount'
  | 'contextWindow';

/** Mirrors the GET payload 1:1 — see Task 14's `RecommendationSettingsJson`. */
export interface RecommendationSettingsState {
  readonly guidancePrompt: string | null;
  readonly defaultGuidancePrompt: string;
  readonly fixedPrompt: {
    readonly role: string;
    readonly outputContract: string;
  };
  readonly expertDefaults: RecommendationExpertDefaults;
  readonly expertBounds: Readonly<Record<RecommendationExpertField, RecommendationSettingBounds>>;
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
  /** Shows each pick's reason and the score beside it (#541) — one switch for
   *  the whole explanation; debug mode reaches neither (#576). */
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
 * `AiSettingsService`'s shape on the shared draft base: a `load`/`save` pair
 * and `saved` as a one-shot success flag the card resets on the next edit.
 */
@Injectable()
export class RecommendationSettingsService extends DraftSettingsService<
  RecommendationSettingsState,
  SaveRecommendationSettings,
  TypedRecommendationEdits
> {
  private readonly api = inject(ReaderApi);
  private readonly recommendations = inject(RecommendationsService);

  protected readonly endpoint = `${this.base}/api/me/ai/recommendations`;

  /** Kept apart from `busy`/`failure`/`saved`: the purge is a danger-zone
   *  action with its own confirmation line, not another outcome of the save
   *  form above it. */
  readonly purging = signal(false);
  readonly purgeFailure = signal<Problem | null>(null);
  readonly purged = signal(false);

  /** Replaces the entire expert draft with the factory values from the API.
   *  This stays local until the card's explicit Save writes it. */
  resetExpertDraft(defaults: RecommendationExpertDefaults): void {
    this.draft.set(defaults);
  }

  /** The single mapping from server truth to the writable body. `contextWindow`
   *  is the account's nullable override (`contextWindowOverride`), not the
   *  resolved window — matching the card's own save body. */
  protected bodyFromState(state: RecommendationSettingsState): SaveRecommendationSettings {
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

  /** Clears every persisted recommendation. On success, refreshes the reader's
   *  own status so the sidebar count (Task 9) drops immediately. A 409 while a
   *  run is active comes back as an ordinary `Problem` -- the caller renders
   *  its `detail` verbatim, same as any other rejected write. */
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
}
