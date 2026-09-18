import { HttpErrorResponse } from '@angular/common/http';
import { Injectable, InjectionToken, Signal, inject, signal } from '@angular/core';
import { firstValueFrom } from 'rxjs';
import { Problem, outcomeIsUnproven, parseProblem } from '../core/problem';
import { RestoreCounts, RestoreResult } from '../reader/models';
import { ReaderApi } from '../reader/reader-api';
import { BackupArchive } from './backup-archive';

export type RestoreRunOutcome =
  | { kind: 'completed'; loaded: RestoreCounts }
  | { kind: 'stopped'; problem: Problem; wiped: boolean };

/** The one problem type the backend raises from an already-wiped account
 *  (BackupLoadFailedException) -- see backup-section.component.ts. */
const BACKUP_LOAD_FAILED = 'backup_load_failed';

export const RESTORE_RETRY_DELAYS_MS = [1000, 2000, 4000] as const;

export const RESTORE_WAIT = new InjectionToken<(ms: number) => Promise<void>>('RESTORE_WAIT', {
  factory: () => (ms: number) => new Promise((resolve) => setTimeout(resolve, ms)),
});

function zeroCounts(): RestoreCounts {
  return { tags: 0, savedSearches: 0, feeds: 0, subscriptions: 0, entries: 0, entryStates: 0 };
}

function addCounts(first: RestoreCounts, second: RestoreCounts): RestoreCounts {
  return {
    tags: first.tags + second.tags,
    savedSearches: first.savedSearches + second.savedSearches,
    feeds: first.feeds + second.feeds,
    subscriptions: first.subscriptions + second.subscriptions,
    entries: first.entries + second.entries,
    entryStates: first.entryStates + second.entryStates,
  };
}

function isRetryableProblem(problem: Problem): boolean {
  return problem.status === 408 || problem.status === 429 || outcomeIsUnproven(problem);
}

type RetryOutcome<T> = { ok: true; value: T } | { ok: false; problem: Problem };

/** Retries `action` after each of `RESTORE_RETRY_DELAYS_MS` in order, on a
 *  retryable failure only. A 4xx other than 408/429 stops on the first try:
 *  the same bytes would be refused again. */
async function withRetry<T>(
  action: () => Promise<T>,
  wait: (ms: number) => Promise<void>,
): Promise<RetryOutcome<T>> {
  for (let attempt = 0; ; attempt++) {
    try {
      return { ok: true, value: await action() };
    } catch (error) {
      const problem = parseProblem(error as HttpErrorResponse);
      if (!isRetryableProblem(problem) || attempt >= RESTORE_RETRY_DELAYS_MS.length) {
        return { ok: false, problem };
      }
      await wait(RESTORE_RETRY_DELAYS_MS[attempt]);
    }
  }
}

/** Drives one sequential restore run: `start` (never retried, pre-wipe),
 *  then every entry part with retry. Root singleton -- `reset()` must
 *  clear every field, or state survives a logout into the next run. */
@Injectable({ providedIn: 'root' })
export class BackupRestoreRun {
  private readonly api = inject(ReaderApi);
  private readonly wait = inject(RESTORE_WAIT);

  private readonly progressSignal = signal<{ done: number; total: number } | null>(null);
  private readonly canContinueSignal = signal(false);
  readonly progress: Signal<{ done: number; total: number } | null> = this.progressSignal;
  readonly canContinue: Signal<boolean> = this.canContinueSignal;

  private archive: BackupArchive | null = null;
  private nextIndex = 1;
  private counts: RestoreCounts = zeroCounts();

  async run(archive: BackupArchive): Promise<RestoreRunOutcome> {
    this.archive = archive;
    this.counts = zeroCounts();
    this.canContinueSignal.set(false);
    this.progressSignal.set({ done: 0, total: this.total });

    const startFailure = await this.runStart(archive);
    if (startFailure) return startFailure;

    return this.runEntryParts(1);
  }

  async continue(): Promise<RestoreRunOutcome> {
    this.requireArchive();
    this.canContinueSignal.set(false);
    return this.runEntryParts(this.nextIndex);
  }

  reset(): void {
    this.archive = null;
    this.nextIndex = 1;
    this.counts = zeroCounts();
    this.progressSignal.set(null);
    this.canContinueSignal.set(false);
  }

  private async runStart(archive: BackupArchive): Promise<RestoreRunOutcome | null> {
    try {
      const foundation = await archive.foundation();
      const result = await firstValueFrom(this.api.startAccountRestore(foundation));
      this.counts = addCounts(this.counts, result.loaded);
      this.progressSignal.set({ done: 1, total: this.total });
      return null;
    } catch (error) {
      const problem = parseProblem(error as HttpErrorResponse);
      const wiped = outcomeIsUnproven(problem) || problem.type === BACKUP_LOAD_FAILED;
      return { kind: 'stopped', problem, wiped };
    }
  }

  private async runEntryParts(from: number): Promise<RestoreRunOutcome> {
    for (let index = from; index < this.total; index++) {
      const failure = await this.runOnePart(index);
      if (failure) return failure;
    }
    return { kind: 'completed', loaded: this.counts };
  }

  private async runOnePart(index: number): Promise<RestoreRunOutcome | null> {
    const attempt = await withRetry(() => this.postEntryPart(index), this.wait);
    if (!attempt.ok) {
      this.nextIndex = index;
      this.canContinueSignal.set(true);
      return { kind: 'stopped', problem: attempt.problem, wiped: true };
    }
    this.counts = addCounts(this.counts, attempt.value.loaded);
    this.progressSignal.set({ done: index + 1, total: this.total });
    return null;
  }

  private async postEntryPart(index: number): Promise<RestoreResult> {
    const archive = this.requireArchive();
    const part = await archive.entryPart(index);
    return firstValueFrom(this.api.restoreEntryPart(part));
  }

  private get total(): number {
    return this.requireArchive().entryPartCount + 1;
  }

  private requireArchive(): BackupArchive {
    if (!this.archive) {
      throw new Error('BackupRestoreRun.continue() called with no run in progress.');
    }
    return this.archive;
  }
}
