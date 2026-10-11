import { Injectable, Signal, WritableSignal, inject, signal } from '@angular/core';
import { Subject, Subscription, asapScheduler, finalize, subscribeOn } from 'rxjs';
import { onIdentityChange } from '../../../core/auth/session-identity';
import { CompletedEntryDto } from '../../state/completed-entry';
import { ReaderApi } from '../../reader-api';

/** How many article bodies stay cached at once — one browsing session's worth
 *  of feed reading, not the whole account's history. */
const CACHE_CAP = 50;

export type EntryBodyState =
  { status: 'loading' } | { status: 'ok'; html: string | null } | { status: 'error' };

/**
 * The reader's in-memory body cache — a `Map`, not `reader-cache.service`'s
 * IndexedDB store: bodies are cheap to refetch. Insertion order doubles as
 * recency: `touch` moves a hit to the end, evicting the oldest past `CACHE_CAP`.
 */
@Injectable({ providedIn: 'root' })
export class EntryBodyService {
  private readonly api = inject(ReaderApi);
  private readonly cache = new Map<number, WritableSignal<EntryBodyState>>();
  private readonly inFlight = new Map<WritableSignal<EntryBodyState>, Subscription>();
  private readonly arrivedCompletions = new Subject<CompletedEntryDto>();
  /** What completing each entry a body fetch brings back has filled in (#1502). */
  readonly completedEntries = this.arrivedCompletions.asObservable();

  constructor() {
    onIdentityChange(() => this.clear());
  }

  /** The body for `id`, fetching it on first request. Safe to call from a
   *  `computed()`: every fetch subscribes past the current synchronous read,
   *  so a mocked observable that emits immediately can never write to the
   *  signal mid-computation. */
  body(id: number): Signal<EntryBodyState> {
    return this.stateFor(id).asReadonly();
  }

  /** Warm the cache for an id the view isn't showing yet (prev/next on open).
   *  A no-op once the id is cached or already in flight. */
  prefetch(id: number): void {
    this.stateFor(id);
  }

  /** Seed the cache from a detail response already in hand (a deep link's own
   *  fetch), skipping a redundant request. Writes the existing signal in
   *  place so a view already reading it for this id sees the update. */
  seed(id: number, html: string | null): void {
    const state = this.cache.get(id) ?? signal<EntryBodyState>({ status: 'loading' });
    state.set({ status: 'ok', html });
    this.touch(id, state);
  }

  /** Refetch a body that failed to load — the inline error's retry action.
   *  A no-op past an id nothing has ever requested: there is nothing to retry. */
  retry(id: number): void {
    const state = this.cache.get(id);
    if (!state) return;
    state.set({ status: 'loading' });
    this.fetch(id, state);
  }

  /** Refetch a body the server has since changed, keeping a shown one on screen until
   *  the new one lands, and for good if the refetch fails. A fetch still out is cancelled. */
  refresh(id: number): void {
    const state = this.cache.get(id);
    if (!state) return;
    this.fetch(id, state);
  }

  /** Drop a cached body the server has since changed, so it is fetched afresh when next asked for. */
  forget(id: number): void {
    const state = this.cache.get(id);
    if (state) this.cancel(state);
    this.cache.delete(id);
  }

  private stateFor(id: number): WritableSignal<EntryBodyState> {
    const existing = this.cache.get(id);
    if (existing) {
      this.touch(id, existing);
      return existing;
    }
    const state = signal<EntryBodyState>({ status: 'loading' });
    this.touch(id, state);
    this.fetch(id, state);
    return state;
  }

  /** Records `id => state` as the most recently used, evicting the oldest
   *  entry once the cache grows past its cap. Map insertion order is the LRU
   *  order: re-setting an existing key moves it to the end. Eviction leaves a
   *  fetch running: a view may still hold the evicted signal. */
  private touch(id: number, state: WritableSignal<EntryBodyState>): void {
    this.cache.delete(id);
    this.cache.set(id, state);
    const oldest = this.cache.size > CACHE_CAP ? this.cache.keys().next().value : undefined;
    if (oldest !== undefined) this.cache.delete(oldest);
  }

  private fetch(id: number, state: WritableSignal<EntryBodyState>): void {
    this.cancel(state);
    const request = this.api
      .entry(id)
      .pipe(
        subscribeOn(asapScheduler),
        finalize(() => this.inFlight.delete(state)),
      )
      .subscribe({
        next: (response) => {
          state.set({ status: 'ok', html: response.entry.contentHtml });
          this.arrivedCompletions.next(response.completed);
        },
        error: () => {
          if (state().status !== 'ok') state.set({ status: 'error' });
        },
      });
    this.inFlight.set(state, request);
  }

  private cancel(state: WritableSignal<EntryBodyState>): void {
    this.inFlight.get(state)?.unsubscribe();
  }

  private clear(): void {
    this.inFlight.forEach((request) => request.unsubscribe());
    this.cache.clear();
  }
}
