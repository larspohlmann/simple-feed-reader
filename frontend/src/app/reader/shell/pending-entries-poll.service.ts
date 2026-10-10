import { DestroyRef, Injectable, NgZone, computed, effect, inject, untracked } from '@angular/core';
import { Subscription } from 'rxjs';
import { onIdentityChange } from '../../core/auth/session-identity';
import { EntryBodyService } from '../article/content/entry-body.service';
import { EntryDto } from '../models';
import { ReaderApi } from '../reader-api';
import { EntriesStore } from '../state/entries.store';
import { ReaderRouteState } from './reader-route-state.service';

export const PENDING_POLL_INTERVAL_MS = 3000;
export const PENDING_POLL_MAX_ROUNDS = 20;
const PENDING_POLL_BATCH = 25;

/** Completes the listed entries still pending (#1502) and merges the finished rows back.
 *  A failed call spends a round too; a new list load starts the count afresh. */
@Injectable()
export class PendingEntriesPoll {
  private readonly store = inject(EntriesStore);
  private readonly api = inject(ReaderApi);
  private readonly bodies = inject(EntryBodyService);
  private readonly zone = inject(NgZone);
  private readonly routeState = inject(ReaderRouteState);

  readonly previewsLoading = computed(() => this.store.entries().some((entry) => entry.pending));

  private timerId: ReturnType<typeof setTimeout> | null = null;
  private request: Subscription | null = null;
  private rounds = 0;
  private listLoad: string | null = null;

  private readonly onVisibilityChange = (): void => {
    if (document.visibilityState === 'hidden') {
      this.cancelTimer();
      return;
    }
    this.schedule();
  };

  constructor() {
    effect(() => {
      const listLoad = this.store.loadedAt();
      this.store.entries();
      this.store.loading();
      untracked(() => this.follow(listLoad));
    });
    this.zone.runOutsideAngular(() =>
      document.addEventListener('visibilitychange', this.onVisibilityChange),
    );
    onIdentityChange(() => this.reset());
    inject(DestroyRef).onDestroy(() => {
      this.reset();
      document.removeEventListener('visibilitychange', this.onVisibilityChange);
    });
  }

  private follow(listLoad: string): void {
    if (listLoad !== this.listLoad) {
      this.reset();
      this.listLoad = listLoad;
    }
    this.schedule();
  }

  private reset(): void {
    this.cancelTimer();
    this.request?.unsubscribe();
    this.request = null;
    this.rounds = 0;
  }

  private schedule(): void {
    if (this.timerId !== null || this.request !== null || !this.mayPoll()) return;
    this.zone.runOutsideAngular(() => {
      this.timerId = setTimeout(() => this.poll(), PENDING_POLL_INTERVAL_MS);
    });
  }

  private mayPoll(): boolean {
    return (
      this.rounds < PENDING_POLL_MAX_ROUNDS &&
      document.visibilityState !== 'hidden' &&
      !this.store.loading() &&
      this.pendingIds().length > 0
    );
  }

  private poll(): void {
    this.timerId = null;
    if (!this.mayPoll()) return;
    this.rounds++;
    const request = this.api.complete(this.pendingIds()).subscribe({
      next: (rows) => this.zone.run(() => this.merge(rows)),
      error: () => this.settle(),
      complete: () => this.settle(),
    });
    this.request = request.closed ? null : request;
  }

  private settle(): void {
    this.request = null;
    this.schedule();
  }

  private merge(rows: EntryDto[]): void {
    const pendingBefore = new Set(this.pendingIds());
    this.store.mergeCompleted(rows);
    const openId = this.routeState.openEntryId();
    for (const row of rows) {
      if (row.pending || !pendingBefore.has(row.id)) continue;
      if (row.id === openId) this.bodies.refresh(row.id);
      else this.bodies.forget(row.id);
    }
  }

  private pendingIds(): number[] {
    return this.store
      .entries()
      .filter((entry) => entry.pending)
      .sort((left, right) => Date.parse(left.createdAt) - Date.parse(right.createdAt))
      .slice(0, PENDING_POLL_BATCH)
      .map((entry) => entry.id);
  }

  private cancelTimer(): void {
    if (this.timerId === null) return;
    clearTimeout(this.timerId);
    this.timerId = null;
  }
}
