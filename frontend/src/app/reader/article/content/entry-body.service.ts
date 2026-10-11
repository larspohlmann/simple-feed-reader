import { Injectable, Signal, WritableSignal, inject, signal } from '@angular/core';
import { Subject } from 'rxjs';
import { onIdentityChange } from '../../../core/auth/session-identity';
import { EntryDto } from '../../models';
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
  /** Bumped on logout so a response already on the wire for the previous
   *  account can't write itself into the next one's cache. */
  private generation = 0;
  private readonly latestRequest = new WeakMap<WritableSignal<EntryBodyState>, object>();
  private readonly arrivedDetails = new Subject<EntryDto>();
  /** Each entry a body fetch brings back: opening a post can queue it, so the row may now be pending (#1502). */
  readonly details = this.arrivedDetails.asObservable();

  constructor() {
    onIdentityChange(() => this.clear());
  }

  /** The body for `id`, fetching it on first request. Safe to call from a
   *  `computed()`: the request itself is deferred past the current
   *  synchronous read, so a mocked observable that emits immediately can
   *  never write to the signal mid-computation. */
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
   *  the new one lands, and for good if the refetch fails. A fetch still out is superseded. */
  refresh(id: number): void {
    const state = this.cache.get(id);
    if (!state) return;
    this.fetch(id, state);
  }

  /** Drop a cached body the server has since changed, so it is fetched afresh when next asked for. */
  forget(id: number): void {
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
    queueMicrotask(() => this.fetch(id, state));
    return state;
  }

  /** Records `id => state` as the most recently used, evicting the oldest
   *  entry once the cache grows past its cap. Map insertion order is the LRU
   *  order: re-setting an existing key moves it to the end. */
  private touch(id: number, state: WritableSignal<EntryBodyState>): void {
    this.cache.delete(id);
    this.cache.set(id, state);
    const oldest = this.cache.size > CACHE_CAP ? this.cache.keys().next().value : undefined;
    if (oldest !== undefined) this.cache.delete(oldest);
  }

  private fetch(id: number, state: WritableSignal<EntryBodyState>): void {
    const generation = this.generation;
    const ticket = {};
    this.latestRequest.set(state, ticket);
    const superseded = () =>
      generation !== this.generation || this.latestRequest.get(state) !== ticket;
    this.api.entry(id).subscribe({
      next: (response) => {
        if (superseded()) return;
        state.set({ status: 'ok', html: response.entry.contentHtml });
        if (this.cache.get(id) === state) this.arrivedDetails.next(response.entry);
      },
      error: () => {
        if (superseded() || state().status === 'ok') return;
        state.set({ status: 'error' });
      },
    });
  }

  private clear(): void {
    this.generation++;
    this.cache.clear();
  }
}
