import { HttpErrorResponse } from '@angular/common/http';
import { Injectable, Signal, computed, inject } from '@angular/core';
import { Observable, Subject, Subscription } from 'rxjs';
import { accountSignal, onIdentityChange } from '../../core/auth/session-identity';
import { EntryDto, EntryQuery } from '../models';
import { ReaderApi } from '../reader-api';
import { EntryPatch, withPatchedItem } from './entry-rows';
import { LandedList } from './landed-list';
import { ListItem, articlesOf } from './list-item';
import { RetryableFailure } from './retryable-failure';

/** The rows of the Trending story open over the list (#2329): a second list beside `EntriesStore`'s, so the
 *  list underneath stays as it was. `EntriesStore` patches these rows too, so a row's state reads the same in both. */
@Injectable({ providedIn: 'root' })
export class StoryListStore {
  private readonly api = inject(ReaderApi);
  private readonly failure = new RetryableFailure();
  private readonly rawItems = accountSignal<ListItem[]>([]);
  private readonly query = accountSignal<EntryQuery | null>(null);
  private readonly cursor = accountSignal<string | null>(null);
  private readonly landedLists = new Subject<LandedList>();
  private request: Subscription | null = null;

  readonly items = this.rawItems.asReadonly();
  readonly entries: Signal<EntryDto[]> = computed(() => this.items().flatMap(articlesOf));
  readonly loading = accountSignal(false);
  readonly loadingMore = accountSignal(false);
  readonly hasMore = computed(() => this.cursor() !== null);
  readonly error = this.failure.problem;
  readonly listLanded: Observable<LandedList> = this.landedLists.asObservable();

  constructor() {
    onIdentityChange(() => this.request?.unsubscribe());
  }

  load(query: EntryQuery): void {
    this.query.set(query);
    this.cursor.set(null);
    this.loading.set(true);
    this.loadingMore.set(false);
    this.failure.clear();
    this.request?.unsubscribe();
    this.request = this.api.entries(query).subscribe({
      next: (page) => {
        this.rawItems.set(page.items);
        this.cursor.set(page.nextCursor);
        this.loading.set(false);
        this.landedLists.next({ query, page });
      },
      error: (error: HttpErrorResponse) => {
        this.rawItems.set([]);
        this.failure.report(error, () => this.load(query));
        this.loading.set(false);
        this.landedLists.next({ query, page: null });
      },
    });
  }

  loadMore(): void {
    const cursor = this.cursor();
    const query = this.query();
    if (cursor === null || query === null || this.loading() || this.loadingMore()) return;
    this.loadingMore.set(true);
    this.failure.abandonRetry();
    this.request = this.api.entries(query, cursor).subscribe({
      next: (page) => {
        this.rawItems.update((current) => [...current, ...page.items]);
        this.cursor.set(page.nextCursor);
        this.loadingMore.set(false);
      },
      error: (error: HttpErrorResponse) => {
        this.failure.report(error, () => this.loadMore());
        this.loadingMore.set(false);
      },
    });
  }

  close(): void {
    this.request?.unsubscribe();
    this.query.set(null);
    this.cursor.set(null);
    this.rawItems.set([]);
    this.loading.set(false);
    this.loadingMore.set(false);
    this.failure.clear();
  }

  entry(id: number): EntryDto | null {
    return this.entries().find((entry) => entry.id === id) ?? null;
  }

  patch(targets: ReadonlySet<number>, patch: EntryPatch): void {
    this.rawItems.update((current) => {
      const patched = current.map((item) => withPatchedItem(item, targets, patch));
      return patched.some((item, index) => item !== current[index]) ? patched : current;
    });
  }

  retry(): void {
    this.failure.retry();
  }

  dismissError(): void {
    this.failure.clear();
  }
}
