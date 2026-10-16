import { DestroyRef, Injectable, inject, signal } from '@angular/core';
import { Subscription } from 'rxjs';
import { onIdentityChange } from '../../core/auth/session-identity';
import { ReaderApi } from '../reader-api';
import { EntriesStore } from '../state/entries.store';
import { CompletedEntriesMerge } from './completed-entries-merge';
import { ReaderRouteState } from './reader-route-state.service';

/** Each call can tie up a PHP worker for a page fetch, and Strato has few of them. */
export const SIGHTED_PREVIEWS_IN_FLIGHT = 2;

/** Completes a pending entry the moment its link card comes into view (#1646). Each entry goes in
 *  a call of its own: the backend spends one page fetch per call on whichever row it claims. */
@Injectable()
export class SightedPreviews {
  private readonly api = inject(ReaderApi);
  private readonly store = inject(EntriesStore);
  private readonly routeState = inject(ReaderRouteState);
  private readonly completed = inject(CompletedEntriesMerge);

  private readonly inFlight = signal<ReadonlySet<number>>(new Set());
  private readonly asked = new Set<number>();
  private readonly waiting: number[] = [];
  private readonly requests = new Map<number, Subscription>();

  constructor() {
    onIdentityChange(() => this.reset());
    inject(DestroyRef).onDestroy(() => this.reset());
  }

  sighted(entryId: number): void {
    if (this.asked.has(entryId)) return;
    this.asked.add(entryId);
    this.waiting.push(entryId);
    this.sendNext();
  }

  isCompleting(entryId: number): boolean {
    return this.inFlight().has(entryId);
  }

  private sendNext(): void {
    while (this.inFlight().size < SIGHTED_PREVIEWS_IN_FLIGHT && this.waiting.length > 0) {
      const entryId = this.waiting.pop()!;
      if (this.isPending(entryId)) this.send(entryId);
    }
  }

  private send(entryId: number): void {
    this.inFlight.update((ids) => new Set(ids).add(entryId));
    const request = this.api.complete([entryId]).subscribe({
      next: (rows) => this.completed.merge(rows, [entryId]),
      error: () => this.settle(entryId),
      complete: () => this.settle(entryId),
    });
    if (!request.closed) this.requests.set(entryId, request);
  }

  private settle(entryId: number): void {
    this.requests.delete(entryId);
    this.inFlight.update((ids) => new Set([...ids].filter((id) => id !== entryId)));
    this.sendNext();
  }

  private isPending(entryId: number): boolean {
    const open = this.routeState.openEntry();
    if (open?.id === entryId) return open.pending;
    return this.store.entries().some((entry) => entry.id === entryId && entry.pending);
  }

  private reset(): void {
    for (const request of this.requests.values()) request.unsubscribe();
    this.requests.clear();
    this.asked.clear();
    this.waiting.length = 0;
    this.inFlight.set(new Set());
  }
}
