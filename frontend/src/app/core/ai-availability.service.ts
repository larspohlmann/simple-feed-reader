// src/app/core/ai-availability.service.ts
import { Injectable, signal } from '@angular/core';
import { CurrentUser } from './auth.service';
import { onIdentityChange } from './session-identity';

/**
 * The whole of what this service tracks — and so the whole of what any caller
 * has to hand it. `/api/me` reports exactly these two fields under `ai`.
 */
export interface AiAvailability {
  readonly model: string | null;
  readonly ready: boolean;
}

/** The account's AI provider, as the settings endpoints report it. */
export interface AiState extends AiAvailability {
  readonly configured: boolean;
  readonly baseUrl: string | null;
  readonly apiKeyHint: string | null;
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
    this.set({ ready: false, model: null });
  }

  private set(availability: AiAvailability): void {
    this.readySignal.set(availability.ready);
    this.modelSignal.set(availability.model);
  }
}
