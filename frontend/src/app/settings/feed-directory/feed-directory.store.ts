import { Injectable, OnDestroy, computed, inject, linkedSignal, signal } from '@angular/core';
import { Observable, Subscription, forkJoin, map, of, switchMap } from 'rxjs';
import { FeedDirectoryScope } from '../../core/preferences/feed-directory-scope';
import { sameIds } from '../../core/same-ids';
import { toggled } from '../../core/toggled-set';
import { FeedDirectoryApi } from './feed-directory-api';
import { FeedDirectoryNames } from './feed-directory-names';
import {
  FeedDirectoryCriteria,
  FeedDirectoryLength,
  FeedDirectoryMode,
  FeedDirectoryRow,
  FeedDirectorySort,
  countByCountry,
  filterRows,
  prepareRows,
  withSubscribed,
} from './feed-directory-rows';
import { FeedDirectoryScopeService } from './feed-directory-scope.service';
import { FeedDirectoryCountryFile, FeedDirectoryIndex } from './feed-directory.models';

@Injectable()
export class FeedDirectoryStore implements OnDestroy {
  private readonly api = inject(FeedDirectoryApi);
  private readonly scopes = inject(FeedDirectoryScopeService);
  private readonly names = inject(FeedDirectoryNames);

  readonly status = signal<'loading' | 'ready' | 'failed'>('loading');
  private readonly loaded = signal<FeedDirectoryRow[]>([]);
  private loading = Subscription.EMPTY;

  readonly mode = signal<FeedDirectoryMode>('channels');
  readonly country = signal<string | null>(null);
  readonly lengths = signal<ReadonlySet<FeedDirectoryLength>>(new Set());
  readonly languages = signal<ReadonlySet<string>>(new Set());
  readonly query = signal('');
  readonly sort = signal<FeedDirectorySort>('top');

  private readonly criteria = computed<FeedDirectoryCriteria>(() => ({
    mode: this.mode(),
    categories: this.categories(),
    lengths: this.lengths(),
    languages: this.languages(),
    query: this.query(),
  }));

  readonly rows = computed(() =>
    filterRows(this.loaded(), {
      ...this.criteria(),
      country: this.country(),
      sort: this.sort(),
    }),
  );
  readonly countryCounts = computed(() => countByCountry(this.loaded(), this.criteria()));
  readonly total = computed(
    () => this.loaded().filter((row) => row.byMode[this.mode()].feedCount > 0).length,
  );
  readonly categoryOptions = computed(
    () =>
      [...new Set(this.loaded().flatMap((row) => [...row.byMode[this.mode()].categories]))].sort(),
    { equal: sameIds },
  );
  readonly categories = linkedSignal<readonly string[], ReadonlySet<string>>({
    source: this.categoryOptions,
    computation: (options, previous) => keptWithin(previous?.value ?? new Set(), options),
  });

  load(): void {
    const scope = this.scopes.scope();
    const scopeLanguages = new Set(scope.languages);
    this.loading.unsubscribe();
    this.keepFiltersWithin(scope);
    this.status.set('loading');
    this.loading = forkJoin({ index: this.api.index(), subscribed: this.api.subscribed() })
      .pipe(
        switchMap(({ index, subscribed }) =>
          this.countryFiles(knownCountries(scope, index)).pipe(
            map((files) => ({ index, subscribed, files })),
          ),
        ),
      )
      .subscribe({
        next: ({ index, subscribed, files }) => {
          this.names.learnCountryNames(index);
          this.loaded.set(files.flatMap((file) => prepareRows(file, scopeLanguages, subscribed)));
          this.status.set('ready');
        },
        error: () => this.status.set('failed'),
      });
  }

  ngOnDestroy(): void {
    this.loading.unsubscribe();
  }

  markSubscribed(host: string, feedUrl: string): void {
    this.loaded.update((rows) => withSubscribed(rows, host, feedUrl));
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

  private countryFiles(codes: readonly string[]): Observable<FeedDirectoryCountryFile[]> {
    return codes.length ? forkJoin(codes.map((code) => this.api.country(code))) : of([]);
  }

  private keepFiltersWithin(scope: FeedDirectoryScope): void {
    this.country.update((code) => (code !== null && scope.countries.includes(code) ? code : null));
    this.languages.update(
      (codes) => new Set([...codes].filter((code) => scope.languages.includes(code))),
    );
  }
}

function keptWithin(picked: ReadonlySet<string>, options: readonly string[]): ReadonlySet<string> {
  const kept = [...picked].filter((name) => options.includes(name));
  return kept.length === picked.size ? picked : new Set(kept);
}

function knownCountries(scope: FeedDirectoryScope, index: FeedDirectoryIndex): string[] {
  const listed = new Set(index.countries.map((country) => country.code));
  return scope.countries.filter((code) => listed.has(code));
}
