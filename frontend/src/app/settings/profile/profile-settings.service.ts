import { HttpErrorResponse } from '@angular/common/http';
import { DestroyRef, Injectable, NgZone, OnDestroy, computed, inject, signal } from '@angular/core';
import { takeUntilDestroyed } from '@angular/core/rxjs-interop';
import { Problem, parseProblem } from '../../core/problem';
import { DraftSettingsService } from '../../shared/settings/draft-settings.service';

const POLL_MS = 2000;
const MAX_POLL_FAILURES = 3;

export interface ProfileConnection {
  readonly id: number;
  readonly name: string | null;
  readonly baseUrl: string;
  readonly model: string | null;
}

export interface ProfileCapBounds {
  readonly min: number;
  readonly max: number;
}

export type ProfileCapField = 'keptCap' | 'viewedCap';

/** Mirrors `ProfileSettingsJson`; `connection` is the one that builds the profile, null when none can. */
export interface ProfileSettingsState {
  readonly profileText: string | null;
  readonly generatedAt: string | null;
  readonly generatedBy: { readonly providerHost: string | null; readonly model: string } | null;
  readonly intervalHours: number | null;
  readonly intervalChoices: readonly (number | null)[];
  readonly connectionId: number | null;
  readonly connection: ProfileConnection | null;
  readonly candidates: readonly ProfileConnection[];
  readonly keptCap: number;
  readonly viewedCap: number;
  readonly defaults: Readonly<Record<ProfileCapField, number>>;
  readonly bounds: Readonly<Record<ProfileCapField, ProfileCapBounds>>;
  readonly debugEnabled: boolean;
}

export interface SaveProfileSettings {
  readonly intervalHours: number | null;
  readonly connectionId: number | null;
  readonly keptCap: number;
  readonly viewedCap: number;
}

export type TypedProfileEdits = Pick<SaveProfileSettings, ProfileCapField>;

/** Mirrors `ProfileRunJson`; `none` until the account has run one. */
export interface ProfileRun {
  readonly status: 'none' | 'pending' | 'running' | 'completed' | 'failed';
  readonly id: number | null;
  readonly trigger: 'manual' | 'scheduled' | 'recommendation' | null;
  readonly outcome: 'generated' | 'unchanged' | 'no_history' | null;
  readonly error: string | null;
  readonly createdAt: string | null;
  readonly completedAt: string | null;
  readonly providerHost: string | null;
  readonly model: string | null;
  readonly attempts: number;
  readonly maxAttempts: number;
  readonly transportFailures: number;
  readonly maxTransportFailures: number;
  readonly streamedChars: number;
}

/**
 * The profile section's state and writes on the shared draft base (schedule and connection save at once, the two
 * caps behind the save bar), plus the newest profile run, polled while it is active.
 */
@Injectable()
export class ProfileSettingsService
  extends DraftSettingsService<ProfileSettingsState, SaveProfileSettings, TypedProfileEdits>
  implements OnDestroy
{
  private readonly zone = inject(NgZone);
  private readonly destroyRef = inject(DestroyRef);

  protected readonly endpoint = `${this.base}/api/me/ai/profile`;

  readonly profileRun = signal<ProfileRun | null>(null);
  readonly runActive = computed(() => {
    const status = this.profileRun()?.status;
    return status === 'pending' || status === 'running';
  });
  readonly starting = signal(false);
  readonly startFailure = signal<Problem | null>(null);
  /** Set once the status could not be read three times in a row; polling has stopped. */
  readonly pollFailure = signal<Problem | null>(null);
  readonly polling = computed(() => this.runActive() && this.pollFailure() === null);

  private pollTimer: ReturnType<typeof setTimeout> | null = null;
  private consecutivePollFailures = 0;

  protected bodyFromState(state: ProfileSettingsState): SaveProfileSettings {
    return {
      intervalHours: state.intervalHours,
      connectionId: state.connectionId,
      keptCap: state.keptCap,
      viewedCap: state.viewedCap,
    };
  }

  loadRun(): void {
    this.http
      .get<ProfileRun>(`${this.endpoint}/runs/current`)
      .pipe(takeUntilDestroyed(this.destroyRef))
      .subscribe({
        next: (run) => {
          this.consecutivePollFailures = 0;
          this.pollFailure.set(null);
          this.adoptRun(run);
        },
        error: (error: HttpErrorResponse) => this.retryOrGiveUp(error),
      });
  }

  startRun(): void {
    this.starting.set(true);
    this.startFailure.set(null);
    this.consecutivePollFailures = 0;
    this.pollFailure.set(null);
    this.http
      .post<ProfileRun>(`${this.endpoint}/runs`, {})
      .pipe(takeUntilDestroyed(this.destroyRef))
      .subscribe({
        next: (run) => {
          this.starting.set(false);
          this.adoptRun(run);
        },
        error: (error: HttpErrorResponse) => {
          this.starting.set(false);
          this.startFailure.set(parseProblem(error));
        },
      });
  }

  ngOnDestroy(): void {
    this.stopPolling();
  }

  /** A run that just ended may have replaced the stored profile, so the state is read again, keeping the draft. */
  private adoptRun(run: ProfileRun): void {
    const wasActive = this.runActive();
    this.profileRun.set(run);
    if (wasActive && !this.runActive()) {
      this.http
        .get<ProfileSettingsState>(this.endpoint)
        .pipe(takeUntilDestroyed(this.destroyRef))
        .subscribe({
          next: (state) => this.state.set(state),
          error: (error: HttpErrorResponse) => this.failure.set(parseProblem(error)),
        });
    }
    this.schedulePoll();
  }

  private retryOrGiveUp(error: HttpErrorResponse): void {
    this.consecutivePollFailures += 1;
    if (this.consecutivePollFailures >= MAX_POLL_FAILURES) {
      this.pollFailure.set(parseProblem(error));
      return;
    }
    this.schedulePoll();
  }

  /** Outside the zone: a pending poll timer would keep the app from ever becoming stable. */
  private schedulePoll(): void {
    this.stopPolling();
    if (!this.runActive()) return;
    this.zone.runOutsideAngular(() => {
      this.pollTimer = setTimeout(() => this.zone.run(() => this.loadRun()), POLL_MS);
    });
  }

  private stopPolling(): void {
    if (this.pollTimer === null) return;
    clearTimeout(this.pollTimer);
    this.pollTimer = null;
  }
}
