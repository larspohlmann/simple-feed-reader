import { Injectable, Signal, WritableSignal, inject, signal } from '@angular/core';
import { onIdentityChange } from '../core/session-identity';
import { ReaderApi } from './reader-api';

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
    this.api.entry(id).subscribe({
      next: (r) => {
        if (generation !== this.generation) return;
        state.set({ status: 'ok', html: r.entry.contentHtml });
      },
      error: () => {
        if (generation !== this.generation) return;
        state.set({ status: 'error' });
      },
    });
  }

  private clear(): void {
    this.generation++;
    this.cache.clear();
  }
}
