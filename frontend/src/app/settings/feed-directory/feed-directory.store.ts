import { Injectable, OnDestroy, computed, inject, linkedSignal, signal } from '@angular/core';
import { Subscription, forkJoin, of } from 'rxjs';
import { toggled } from '../../core/toggled-set';
import { FeedDirectoryApi } from './feed-directory-api';
import {
  FeedDirectoryFilter,
  FeedDirectoryLength,
  FeedDirectoryMode,
  FeedDirectoryRow,
  FeedDirectorySort,
  countByCountry,
  feedsIn,
  filterRows,
  prepareRows,
  withSubscribed,
} from './feed-directory-rows';
import { FeedDirectoryScopeService } from './feed-directory-scope.service';
import { FeedDirectoryIndex, FeedDirectoryScope } from './feed-directory.models';

@Injectable()
export class FeedDirectoryStore implements OnDestroy {
  private readonly api = inject(FeedDirectoryApi);
  private readonly scopes = inject(FeedDirectoryScopeService);

  readonly status = signal<'loading' | 'ready' | 'failed'>('loading');
  readonly index = signal<FeedDirectoryIndex | null>(null);
  private readonly loaded = signal<FeedDirectoryRow[]>([]);
  private loading = Subscription.EMPTY;

  readonly mode = signal<FeedDirectoryMode>('channels');
  readonly country = signal<string | null>(null);
  readonly lengths = signal<ReadonlySet<FeedDirectoryLength>>(new Set());
  readonly languages = signal<ReadonlySet<string>>(new Set());
  readonly query = signal('');
  readonly sort = signal<FeedDirectorySort>('top');

  private readonly filter = computed<FeedDirectoryFilter>(() => ({
    mode: this.mode(),
    country: this.country(),
    categories: this.categories(),
    lengths: this.lengths(),
    languages: this.languages(),
    query: this.query(),
    sort: this.sort(),
  }));

  readonly rows = computed(() => filterRows(this.loaded(), this.filter()));
  readonly countryCounts = computed(() =>
    countByCountry(this.loaded(), { ...this.filter(), country: null }),
  );
  readonly total = computed(
    () => this.loaded().filter((row) => feedsIn(row, this.mode()).length > 0).length,
  );
  readonly categoryOptions = computed(() =>
    [
      ...new Set(
        this.loaded().flatMap((row) => feedsIn(row, this.mode()).map((feed) => feed.category)),
      ),
    ].sort(),
  );
  readonly categories = linkedSignal<readonly string[], ReadonlySet<string>>({
    source: this.categoryOptions,
    computation: (options, previous) =>
      new Set([...(previous?.value ?? [])].filter((name) => options.includes(name))),
  });

  load(): void {
    const scope = this.scopes.scope();
    const scopeLanguages = new Set(scope.languages);
    const countryFiles = scope.countries.length
      ? forkJoin(scope.countries.map((code) => this.api.country(code)))
      : of([]);
    this.loading.unsubscribe();
    this.keepFiltersWithin(scope);
    this.status.set('loading');
    this.loading = forkJoin({
      index: this.api.index(),
      subscribed: this.api.subscribed(),
      files: countryFiles,
    }).subscribe({
      next: ({ index, subscribed, files }) => {
        this.index.set(index);
        this.loaded.set(files.flatMap((file) => prepareRows(file, scopeLanguages, subscribed)));
        this.status.set('ready');
      },
      error: () => this.status.set('failed'),
    });
  }

  retry(): void {
    this.load();
  }

  ngOnDestroy(): void {
    this.loading.unsubscribe();
  }

  markSubscribed(feedUrl: string): void {
    this.loaded.update((rows) => withSubscribed(rows, feedUrl));
  }

  toggleCategory(name: string): void {
    this.categories.update((set) => toggled(set, name));
  }

  toggleLength(length: FeedDirectoryLength): void {
    this.lengths.update((set) => toggled(set, length));
  }

  toggleLanguage(code: string): void {
    this.languages.update((set) => toggled(set, code));
  }

  private keepFiltersWithin(scope: FeedDirectoryScope): void {
    this.country.update((code) => (code !== null && scope.countries.includes(code) ? code : null));
    this.languages.update(
      (codes) => new Set([...codes].filter((code) => scope.languages.includes(code))),
    );
  }
}
