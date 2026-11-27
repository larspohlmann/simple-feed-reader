import { HttpErrorResponse } from '@angular/common/http';
import { Signal } from '@angular/core';
import { accountSignal } from '../../core/auth/session-identity';
import { parseProblem, Problem } from '../../core/problem';

/** The list's error banner and the one operation its retry replays (#996). A fresh operation clears it,
 *  so a stale failure never lingers behind a later success. */
export class RetryableFailure {
  private readonly shown = accountSignal<Problem | null>(null);
  private readonly failedOperation = accountSignal<(() => void) | null>(null);
  readonly problem: Signal<Problem | null> = this.shown.asReadonly();

  clear(): void {
    this.shown.set(null);
    this.failedOperation.set(null);
  }

  /** Keeps the banner on screen but gives its retry nothing to replay. */
  abandonRetry(): void {
    this.failedOperation.set(null);
  }

  report(error: HttpErrorResponse, retry: () => void): void {
    this.reportProblem(parseProblem(error), retry);
  }

  reportProblem(problem: Problem, retry: () => void): void {
    this.shown.set(problem);
    this.failedOperation.set(retry);
  }

  /** Clears the banner first so a fresh attempt reads as progress. A no-op when nothing has failed. */
  retry(): void {
    const operation = this.failedOperation();
    if (!operation) return;
    this.shown.set(null);
    operation();
  }
}
