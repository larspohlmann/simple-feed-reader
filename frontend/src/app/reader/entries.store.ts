// src/app/reader/entries.store.ts
import { HttpErrorResponse } from '@angular/common/http';
import { Injectable, inject, signal } from '@angular/core';
import { Problem, parseProblem } from '../core/problem';
import { ReaderApi } from './reader-api';
import { EntryDto, EntryQuery, EntryStatePatch } from './models';

@Injectable({ providedIn: 'root' })
export class EntriesStore {
  private readonly api = inject(ReaderApi);

  readonly entries = signal<EntryDto[]>([]);
  readonly nextCursor = signal<string | null>(null);
  readonly loading = signal(false);
  readonly loadingMore = signal(false);
  readonly error = signal<Problem | null>(null);
  readonly loadedAt = signal<string>('');

  private query: EntryQuery | null = null;

  load(query: EntryQuery): void {
    this.query = query;
    this.entries.set([]);
    this.nextCursor.set(null);
    this.loading.set(true);
    this.error.set(null);
    this.loadedAt.set(new Date().toISOString());
    this.api.entries(query).subscribe({
      next: (page) => {
        this.entries.set(page.entries);
        this.nextCursor.set(page.nextCursor);
        this.loading.set(false);
      },
      error: (e: HttpErrorResponse) => {
        this.error.set(parseProblem(e));
        this.loading.set(false);
      },
    });
  }

  loadMore(): void {
    const cursor = this.nextCursor();
    if (!cursor || !this.query || this.loading() || this.loadingMore()) return;
    this.loadingMore.set(true);
    this.api.entries(this.query, cursor).subscribe({
      next: (page) => {
        this.entries.update((cur) => [...cur, ...page.entries]);
        this.nextCursor.set(page.nextCursor);
        this.loadingMore.set(false);
      },
      error: (e: HttpErrorResponse) => {
        this.error.set(parseProblem(e));
        this.loadingMore.set(false);
      },
    });
  }

  /** Optimistic patch of one entry's flags; rolls the list back if the PATCH fails. */
  setState(entryId: number, patch: EntryStatePatch): void {
    const before = this.entries();
    if (!before.some((e) => e.id === entryId)) return;
    this.entries.update((cur) => cur.map((e) => (e.id === entryId ? { ...e, ...patch } : e)));
    this.api.updateState(entryId, patch).subscribe({
      error: () => this.entries.set(before),
    });
  }
}
