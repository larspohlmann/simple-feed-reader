// src/app/reader/refresh.service.ts
import { HttpErrorResponse } from '@angular/common/http';
import { Injectable, computed, inject, signal } from '@angular/core';
import { Problem, parseProblem } from '../core/problem';
import { ReaderApi } from './reader-api';
import { RefreshScope } from './query';
import { RefreshReport } from './models';

const BUSY_BACKOFF_MS = 1500;
const MAX_BUSY_RETRIES = 5;

/** Why a refresh ended without fetching what it was asked to fetch.
 *
 *  A union rather than a `Problem` because two of the three are not HTTP
 *  failures at all: the request succeeded and reported that it did nothing.
 *  Forcing them into problem+json would mean inventing a status, a title and a
 *  type for a case where nothing failed over the wire. */
export type RefreshFailure =
  | { kind: 'busy' } // another refresh holds the lock, and outlasted our retries
  | { kind: 'aborted' } // the sweep stopped mid-way; feeds are still due
  | { kind: 'http'; problem: Problem };

@Injectable({ providedIn: 'root' })
export class RefreshService {
  private readonly api = inject(ReaderApi);

  readonly running = signal(false);
  readonly report = signal<RefreshReport | null>(null);
  /** Null while a refresh is doing its job. Set exactly once per run, on the
   *  three paths that end it without the feeds being fetched. */
  readonly failure = signal<RefreshFailure | null>(null);

  /** Increments every time a report lands, including partial slices. Consumers watch
   *  this to refetch progressively — waiting for onDone would leave a new user
   *  staring at an empty list for the whole sweep. */
  readonly slice = signal(0);

  readonly progress = computed(() => {
    const r = this.report();
    if (!r || r.total <= 0) return 0;
    return Math.min(1, Math.max(0, (r.total - r.remaining) / r.total));
  });

  /** Pass a scope to sweep just one feed (feedId) or one tag's feeds (tagId);
   *  omit it to sweep all the caller's due feeds. The scope holds across the
   *  whole poll loop. */
  run(onDone?: () => void, scope?: RefreshScope): void {
    if (this.running()) return;
    this.running.set(true);
    this.report.set(null);
    this.failure.set(null);
    this.slice.set(0);
    this.step(0, onDone, scope);
  }

  private step(busyRetries: number, onDone?: () => void, scope?: RefreshScope): void {
    this.api.refresh(scope).subscribe({
      next: (r) => {
        this.report.set(r);
        this.slice.update((n) => n + 1);
        if (r.status === 'partial' && r.remaining > 0) {
          this.step(0, onDone, scope);
        } else if (r.status === 'busy') {
          this.backOffWhileBusy(busyRetries, onDone, scope);
        } else if (r.status === 'aborted') {
          // The backend closed the EntityManager and stopped mid-sweep. Feeds
          // are unfetched and still due, so this is NOT the `completed` case it
          // used to share a branch with (#119).
          this.stopWith({ kind: 'aborted' }, onDone);
        } else {
          this.finish(onDone);
        }
      },
      error: (e: HttpErrorResponse) => {
        this.stopWith({ kind: 'http', problem: parseProblem(e) }, onDone);
      },
    });
  }

  /** Another refresh holds the lock. Wait and ask again -- but only so long: a
   *  CLI sweep can hold it for its entire budget, far past the patience anyone
   *  has for a spinner. Retrying longer is not the fix; telling the user is. */
  private backOffWhileBusy(busyRetries: number, onDone?: () => void, scope?: RefreshScope): void {
    if (busyRetries >= MAX_BUSY_RETRIES) {
      this.stopWith({ kind: 'busy' }, onDone);
      return;
    }
    setTimeout(() => this.step(busyRetries + 1, onDone, scope), BUSY_BACKOFF_MS);
  }

  private stopWith(failure: RefreshFailure, onDone?: () => void): void {
    this.failure.set(failure);
    this.finish(onDone);
  }

  private finish(onDone?: () => void): void {
    this.running.set(false);
    onDone?.();
  }
}
