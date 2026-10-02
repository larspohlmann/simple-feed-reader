import { Injectable, signal } from '@angular/core';
import { CurrentUser } from './auth/auth.service';
import { onIdentityChange } from './auth/session-identity';

/** A recommendation setting only some engines read; each value is that setting's field name on the wire. */
export const RECOMMENDATION_TUNING_FIELDS = [
  'contextWindow',
  'batchSize',
  'suppressReasoning',
  'slowModel',
  'maxBatchSize',
  'batchConcurrency',
] as const;

export type RecommendationTuningField = (typeof RECOMMENDATION_TUNING_FIELDS)[number];

/** What a connection's recommendation engine can do; the client renders from it and never learns the engine. */
export interface RecommendationCapabilities {
  readonly reasons: boolean;
  /** Whether the engine sends a prompt of its own: a fixed prompt, a guidance default and a distilled profile. */
  readonly prompt: boolean;
  readonly tuningFields: readonly RecommendationTuningField[];
}

/** Whether the engine reads the setting; one it ignores is not offered. */
export function offersTuning(
  capabilities: RecommendationCapabilities,
  field: RecommendationTuningField,
): boolean {
  return capabilities.tuningFields.includes(field);
}

export const NO_RECOMMENDATION_CAPABILITIES: RecommendationCapabilities = {
  reasons: false,
  prompt: false,
  tuningFields: [],
};

/**
 * The whole of what this service tracks — and so the whole of what any caller
 * has to hand it. `/api/me` reports exactly these fields under `ai`;
 * `capabilities` is null while no connection is active.
 */
export interface AiAvailability {
  readonly model: string | null;
  readonly ready: boolean;
  readonly capabilities: RecommendationCapabilities | null;
}

/**
 * Whether AI features may run for the signed-in account.
 *
 * One signal for the whole app, seeded from `/api/me` and updated by the
 * settings section, so a later feature reads it without a request of its own.
 * `false` is the safe default while the profile is in flight: an AI feature
 * that stays hidden a moment longer is right, one that appears and then fails
 * is not.
 */
@Injectable({ providedIn: 'root' })
export class AiAvailabilityService {
  private readonly readySignal = signal(false);
  private readonly modelSignal = signal<string | null>(null);
  private readonly capabilitiesSignal = signal<RecommendationCapabilities>(
    NO_RECOMMENDATION_CAPABILITIES,
  );

  readonly ready = this.readySignal.asReadonly();
  readonly model = this.modelSignal.asReadonly();
  readonly capabilities = this.capabilitiesSignal.asReadonly();

  constructor() {
    // The token is the trigger, not `logout()`. The interceptor's 401 path
    // clears the token and navigates without ever calling `logout()`, so a
    // reset wired only there would let an expired session hand `ready: true`
    // and the previous account's model to the next one (#263).
    onIdentityChange(() => this.reset());
  }

  /** Take the account's values, right after `AuthService.loadMe()`. */
  adopt(user: CurrentUser): void {
    this.set(user.ai);
  }

  /** Take a settings write's own answer, so the section needs no profile refetch. */
  apply(state: AiAvailability): void {
    this.set(state);
  }

  /**
   * Per-account, like PreferencesService: leaving it set would let the next
   * signed-in account see AI offered until its own profile arrives, or forever
   * if that request fails. `AuthService.logout()` calls this too, which is now
   * belt-and-braces — the identity binding above covers that path as well.
   */
  reset(): void {
    this.set({ ready: false, model: null, capabilities: null });
  }

  private set(availability: AiAvailability): void {
    this.readySignal.set(availability.ready);
    this.modelSignal.set(availability.model);
    this.capabilitiesSignal.set(availability.capabilities ?? NO_RECOMMENDATION_CAPABILITIES);
  }
}
