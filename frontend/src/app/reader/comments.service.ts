import { Injectable, Signal, WritableSignal, inject, signal } from '@angular/core';
import { onIdentityChange } from '../core/session-identity';
import { CommentsResponse, EntryCommentDto } from './models';
import { ReaderApi } from './reader-api';

const CACHE_CAP = 50;
const FRESH_MS = 60 * 60 * 1000;

export type CommentsState =
  | { status: 'idle' }
  | { status: 'loading' }
  | { status: 'ok'; comments: EntryCommentDto[] }
  | { status: 'throttled'; retryAt: number }
  | { status: 'failed' };

interface Slot {
  state: WritableSignal<CommentsState>;
  loadedAt: number | null;
}

@Injectable({ providedIn: 'root' })
export class CommentsService {
  private readonly api = inject(ReaderApi);
  private readonly slots = new Map<number, Slot>();
  private generation = 0;

  constructor() {
    onIdentityChange(() => this.clear());
  }

  state(id: number): Signal<CommentsState> {
    return this.slotFor(id).state.asReadonly();
  }

  load(id: number): void {
    const slot = this.slotFor(id);
    if (this.isStale(slot)) this.fetch(id, slot);
  }

  reload(id: number): void {
    const slot = this.slotFor(id);
    if (slot.state().status === 'loading') return;
    this.fetch(id, slot);
  }

  private isStale(slot: Slot): boolean {
    const status = slot.state().status;
    if (status === 'idle') return true;
    return status === 'ok' && slot.loadedAt !== null && Date.now() - slot.loadedAt >= FRESH_MS;
  }

  private slotFor(id: number): Slot {
    const slot = this.slots.get(id) ?? {
      state: signal<CommentsState>({ status: 'idle' }),
      loadedAt: null,
    };
    this.slots.delete(id);
    this.slots.set(id, slot);
    const oldest = this.slots.size > CACHE_CAP ? this.slots.keys().next().value : undefined;
    if (oldest !== undefined) this.slots.delete(oldest);
    return slot;
  }

  private fetch(id: number, slot: Slot): void {
    const generation = this.generation;
    slot.state.set({ status: 'loading' });
    slot.loadedAt = null;
    this.api.comments(id).subscribe({
      next: (response) => {
        if (generation !== this.generation) return;
        this.settle(slot, response);
      },
      error: () => {
        if (generation !== this.generation) return;
        slot.state.set({ status: 'failed' });
      },
    });
  }

  private settle(slot: Slot, response: CommentsResponse): void {
    if (response.status === 'ok') {
      slot.state.set({ status: 'ok', comments: response.comments });
      slot.loadedAt = Date.now();
      return;
    }
    if (response.status === 'throttled') {
      slot.state.set({ status: 'throttled', retryAt: Date.now() + response.retryAfter * 1000 });
      return;
    }
    slot.state.set({ status: 'failed' });
  }

  private clear(): void {
    this.generation++;
    this.slots.clear();
  }
}
