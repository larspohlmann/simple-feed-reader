import { DestroyRef, Injectable, computed, effect, inject, untracked } from '@angular/core';
import { ActivatedRoute, convertToParamMap } from '@angular/router';
import { toSignal } from '@angular/core/rxjs-interop';
import { ReaderApi } from '../reader-api';
import { EntryBodyService } from '../article/content/entry-body.service';
import { EntriesStore } from '../state/entries.store';
import { ListPreferences } from '../list/list-preferences.service';
import { selectionFromRoute } from '../query/reader-matcher';
import { sameSelection } from '../query/query';

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

  readonly openEntry = computed(() => {
    const id = this.entryId();
    return id == null ? null : this.entries.entry(id);
  });
  /** Identity only: effects keyed on it fire once per opened entry, never on its flag changes. */
  readonly openEntryId = computed(() => this.openEntry()?.id ?? null);

  constructor() {
    inject(DestroyRef).onDestroy(() => this.entries.releaseUnlisted());
    effect(() => {
      const id = this.entryId();
      untracked(() => this.fetchUnlistedEntry(id));
    });
    // Warm the body store for the entries beside the one just opened. Keyed on
    // the id alone, so an unrelated list reload doesn't re-issue the prefetch.
    effect(() => {
      const id = this.openEntryId();
      if (id === null) return;
      untracked(() => this.prefetchNeighbours(id));
    });
  }

  private prefetchNeighbours(id: number): void {
    const list = this.entries.entries();
    const index = list.findIndex((entry) => entry.id === id);
    if (index === -1) return;
    const previous = list[index - 1];
    const next = list[index + 1];
    if (previous) this.bodyService.prefetch(previous.id);
    if (next) this.bodyService.prefetch(next.id);
  }

  private fetchUnlistedEntry(id: number | null): void {
    if (id == null) {
      this.entries.releaseUnlisted();
      return;
    }
    if (this.entries.entry(id)) return;
    this.api.entry(id).subscribe({
      // Id-guarded: a slow answer for an abandoned deep link must not replace the entry now open.
      next: (response) => {
        if (this.entryId() !== id) return;
        this.entries.holdUnlisted(response.entry);
        this.bodyService.seed(response.entry.id, response.entry.contentHtml);
      },
      error: () => {
        if (this.entryId() === id) this.entries.releaseUnlisted();
      },
    });
  }
}
