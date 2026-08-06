// src/app/core/ai-availability.service.ts
import { Injectable, signal } from '@angular/core';
import { CurrentUser } from './auth.service';

/** The account's AI provider, as the API reports it. */
export interface AiState {
  readonly configured: boolean;
  readonly baseUrl: string | null;
  readonly apiKeyHint: string | null;
  readonly model: string | null;
  readonly ready: boolean;
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

  readonly ready = this.readySignal.asReadonly();
  readonly model = this.modelSignal.asReadonly();

  /** Take the account's values, right after `AuthService.loadMe()`. */
  adopt(user: CurrentUser): void {
    this.readySignal.set(user.ai.ready);
    this.modelSignal.set(user.ai.model);
  }

  /** Take a settings write's own answer, so the section needs no profile refetch. */
  apply(state: AiState): void {
    this.readySignal.set(state.ready);
    this.modelSignal.set(state.model);
  }

  /**
   * Per-account, like PreferencesService: leaving it set would let the next
   * signed-in account see AI offered until its own profile arrives, or forever
   * if that request fails.
   */
  reset(): void {
    this.readySignal.set(false);
    this.modelSignal.set(null);
  }
}
