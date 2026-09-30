import { Injectable, computed, effect, inject, signal, untracked } from '@angular/core';
import { ActivatedRoute, convertToParamMap } from '@angular/router';
import { toSignal } from '@angular/core/rxjs-interop';
import { ReaderApi } from '../reader-api';
import { EntryBodyService } from '../entry-body.service';
import { EntriesStore } from '../entries.store';
import { ListPreferences } from '../list-preferences.service';
import { selectionFromRoute } from '../reader-matcher';
import { sameSelection } from '../query';
import { EntryDto, EntryStatePatch } from '../models';

@Injectable()
export class ReaderRouteState {
  private readonly route = inject(ActivatedRoute);
  private readonly api = inject(ReaderApi);
  private readonly bodyService = inject(EntryBodyService);
  private readonly entries = inject(EntriesStore);
  private readonly listPreferences = inject(ListPreferences);

  private readonly queryParameters = toSignal(this.route.queryParamMap, {
    initialValue: convertToParamMap({}),
  });
  private readonly pathParameters = toSignal(this.route.paramMap, {
    initialValue: convertToParamMap({}),
  });
  private readonly parsed = computed(() =>
    selectionFromRoute(this.pathParameters(), this.queryParameters()),
  );

  /** Structural equality: an entry-only URL change keeps the reference, so the list does not reload. */
  readonly selection = computed(() => this.listPreferences.appliedTo(this.parsed().selection), {
    equal: sameSelection,
  });
  readonly entryId = computed(() => this.parsed().entryId);

  private readonly fetchedEntry = signal<EntryDto | null>(null);
  readonly openEntry = computed(() => {
    const id = this.entryId();
    if (id == null) return null;
    const listed = this.entries.entries().find((entry) => entry.id === id);
    if (listed) return listed;
    const fetched = this.fetchedEntry();
    return fetched && fetched.id === id ? fetched : null;
  });
  /** Identity only: effects keyed on it fire once per opened entry, never on its flag changes. */
  readonly openEntryId = computed(() => this.openEntry()?.id ?? null);

  constructor() {
    effect(() => {
      const id = this.entryId();
      untracked(() => this.fetchUnlistedEntry(id));
    });
  }

  patchFetchedEntry(id: number, patch: EntryStatePatch, onError?: () => void): void {
    const before = this.fetchedEntry();
    this.fetchedEntry.update((current) =>
      current && current.id === id ? { ...current, ...patch } : current,
    );
    this.api.updateState(id, patch).subscribe({
      error: () => {
        // Revert only while the same cold entry is open; Back/Forward may have moved on.
        this.fetchedEntry.update((current) => (current && current.id === id ? before : current));
        onError?.();
      },
    });
  }

  private fetchUnlistedEntry(id: number | null): void {
    if (id == null) {
      this.fetchedEntry.set(null);
      return;
    }
    if (this.entries.entries().some((entry) => entry.id === id)) return;
    if (this.fetchedEntry()?.id === id) return;
    this.api.entry(id).subscribe({
      // Id-guarded: a slow answer for an abandoned deep link must not replace the entry now open.
      next: (response) => {
        if (this.entryId() !== id) return;
        this.fetchedEntry.set(response.entry);
        this.bodyService.seed(response.entry.id, response.entry.contentHtml);
      },
      error: () => {
        if (this.entryId() === id) this.fetchedEntry.set(null);
      },
    });
  }
}
