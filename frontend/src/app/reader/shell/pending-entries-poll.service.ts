import {
  DestroyRef,
  Injectable,
  NgZone,
  computed,
  effect,
  inject,
  signal,
  untracked,
} from '@angular/core';
import { Subscription } from 'rxjs';
import { onIdentityChange } from '../../core/auth/session-identity';
import { isTabHidden, onTabVisibilityChange } from '../../core/tab-visibility';
import { EntryBodyService } from '../article/content/entry-body.service';
import { PreviewProgress } from '../link-card/preview-progress';
import { ReaderApi } from '../reader-api';
import { CompletedEntryDto } from '../state/completed-entry';
import { EntriesStore } from '../state/entries.store';
import { ReaderRouteState } from './reader-route-state.service';

export const PENDING_POLL_INTERVAL_MS = 3000;
export const PENDING_POLL_MAX_ROUNDS = 20;
const PENDING_POLL_BATCH = 25;

/** Completes the listed entries still pending (#1502), and the open one, and merges the finished
 *  rows back. The list and the open entry each spend their own rounds, a failed call too; a new
 *  list load starts both counts afresh, and a newly opened entry its own. */
@Injectable()
export class PendingEntriesPoll implements PreviewProgress {
  private readonly store = inject(EntriesStore);
  private readonly api = inject(ReaderApi);
  private readonly bodies = inject(EntryBodyService);
  private readonly zone = inject(NgZone);
  private readonly routeState = inject(ReaderRouteState);

  private readonly listedPending = computed(() =>
    this.store
      .entries()
      .filter((entry) => entry.pending)
      .sort((left, right) => Date.parse(left.createdAt) - Date.parse(right.createdAt)),
  );
  private readonly listedBatch = computed(
    () =>
      this.listedPending()
        .slice(0, PENDING_POLL_BATCH)
        .map((entry) => entry.id),
    { equal: sameIds },
  );
  private readonly openPendingId = computed(() => {
    const open = this.routeState.openEntry();
    return open?.pending ? open.id : null;
  });
  private readonly request = signal<Subscription | null>(null);
  private readonly listRounds = signal(0);
  private readonly openRounds = signal(0);
  private readonly listSpent = computed(() => this.listRounds() >= PENDING_POLL_MAX_ROUNDS);
  private readonly openSpent = computed(() => this.openRounds() >= PENDING_POLL_MAX_ROUNDS);
  private readonly listLoading = computed(() =>
    this.listSpent() && this.request() === null ? [] : this.listedPending(),
  );
  private readonly openLoadingId = computed(() =>
    this.openSpent() && this.request() === null ? null : this.openPendingId(),
  );
  private readonly loadingIds = computed(() => {
    const ids = new Set(this.listLoading().map((entry) => entry.id));
    const open = this.openLoadingId();
    if (open !== null) ids.add(open);
    return ids;
  });
  readonly previewsLoading = computed(() => this.listLoading().length > 0);

  private timerId: ReturnType<typeof setTimeout> | null = null;
  private roundsOpenId: number | null = null;
  private listLoad: string | null = null;

  constructor() {
    effect(() => {
      const listLoad = this.store.loadedAt();
      const openId = this.openPendingId();
      this.listedBatch();
      this.store.loading();
      untracked(() => this.follow(listLoad, openId));
    });
    onTabVisibilityChange((hidden) => (hidden ? this.cancelTimer() : this.schedule()));
    onIdentityChange(() => this.reset());
    inject(DestroyRef).onDestroy(() => this.reset());
  }

  private follow(listLoad: string, openId: number | null): void {
    if (listLoad !== this.listLoad) {
      this.reset();
      this.listLoad = listLoad;
    }
    if (openId !== this.roundsOpenId) {
      this.roundsOpenId = openId;
      this.openRounds.set(0);
    }
    this.schedule();
  }

  private reset(): void {
    this.cancelTimer();
    this.request()?.unsubscribe();
    this.request.set(null);
    this.listRounds.set(0);
    this.openRounds.set(0);
  }

  isLoading(entryId: number): boolean {
    return this.loadingIds().has(entryId);
  }

  private schedule(): void {
    if (this.timerId !== null || this.request() !== null || !this.mayPoll()) return;
    this.zone.runOutsideAngular(() => {
      this.timerId = setTimeout(() => this.poll(), PENDING_POLL_INTERVAL_MS);
    });
  }

  private mayPoll(): boolean {
    return !isTabHidden() && !this.store.loading() && this.due().length > 0;
  }

  private poll(): void {
    this.timerId = null;
    if (!this.mayPoll()) return;
    const ids = this.due();
    this.spendRound(ids);
    const request = this.api.complete(ids).subscribe({
      next: (rows) => this.zone.run(() => this.merge(rows, ids)),
      error: () => this.settle(),
      complete: () => this.settle(),
    });
    this.request.set(request.closed ? null : request);
  }

  private settle(): void {
    this.request.set(null);
    this.schedule();
  }

  private merge(rows: CompletedEntryDto[], sent: number[]): void {
    this.store.mergeCompleted(rows);
    const openId = this.routeState.openEntry()?.id;
    for (const row of rows) {
      if (row.pending || !sent.includes(row.id)) continue;
      if (row.id === openId) this.bodies.refresh(row.id);
      else this.bodies.forget(row.id);
    }
  }

  /** The ids whose rounds are not yet spent: the listed batch, and the open entry put last. */
  private due(): number[] {
    const listed = this.listSpent() ? [] : this.listedBatch();
    const open = this.openPendingId();
    if (open === null || this.openSpent() || listed.includes(open)) {
      return listed;
    }
    return [...listed.slice(0, PENDING_POLL_BATCH - 1), open];
  }

  private spendRound(ids: number[]): void {
    if (!this.listSpent() && this.listedBatch().length > 0)
      this.listRounds.update((count) => count + 1);
    const open = this.openPendingId();
    if (open !== null && ids.includes(open)) this.openRounds.update((count) => count + 1);
  }

  private cancelTimer(): void {
    if (this.timerId === null) return;
    clearTimeout(this.timerId);
    this.timerId = null;
  }
}

function sameIds(left: number[], right: number[]): boolean {
  return left.length === right.length && left.every((id, index) => id === right[index]);
}
