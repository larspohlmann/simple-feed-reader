import { Injectable, OnDestroy, computed, inject, linkedSignal, signal } from '@angular/core';
import { Observable, Subscription, forkJoin, map, of, switchMap } from 'rxjs';
import { SubscriptionDto } from '../../reader/models';
import { SubscriptionsStore } from '../../reader/state/subscriptions.store';
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
  sameLookup,
  subscribedLookup,
  withSubscriptions,
} from './feed-directory-rows';
import { feedDirectoryUrl } from './feed-directory-url';
import { FeedDirectoryScopeService } from './feed-directory-scope.service';
import { FeedDirectoryCountryFile, FeedDirectoryIndex } from './feed-directory.models';

@Injectable()
export class FeedDirectoryStore implements OnDestroy {
  private readonly api = inject(FeedDirectoryApi);
  private readonly scopes = inject(FeedDirectoryScopeService);
  private readonly names = inject(FeedDirectoryNames);

  private readonly subscriptionStore = inject(SubscriptionsStore);

  private readonly fileStatus = signal<'loading' | 'ready' | 'failed'>('loading');
  readonly status = computed(() => {
    if (this.fileStatus() !== 'ready') return this.fileStatus();
    if (this.subscriptionStore.error() && !this.subscriptionStore.subscriptions().length) {
      return 'failed';
    }
    return this.subscriptionStore.resolved() ? 'ready' : 'loading';
  });
  private readonly loaded = signal<FeedDirectoryRow[]>([]);
  private readonly subscriptionsByUrl = computed(
    () =>
      new Map(
        this.subscriptionStore
          .subscriptions()
          .map((subscription) => [feedDirectoryUrl(subscription.feedUrl), subscription] as const),
      ),
  );
  private readonly lookup = computed(() => subscribedLookup(this.subscriptionsByUrl()), {
    equal: sameLookup,
  });
  private readonly marked = computed(() => withSubscriptions(this.loaded(), this.lookup()));
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
    filterRows(this.marked(), {
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
      [
        ...new Set(
          this.loaded().flatMap((row) => [...row.byMode[this.mode()].categoryCounts.keys()]),
        ),
      ].sort(),
    { equal: sameIds },
  );
  readonly categories = linkedSignal<readonly string[], ReadonlySet<string>>({
    source: this.categoryOptions,
    computation: (options, previous) => keptWithin(previous?.value ?? new Set(), options),
  });

  readonly onlyCategory = computed(() => {
    const picked = this.categories();
    return picked.size === 1 ? [...picked][0] : null;
  });

  load(): void {
    const scope = this.scopes.scope();
    const scopeLanguages = new Set(scope.languages);
    this.loading.unsubscribe();
    this.keepFiltersWithin(scope);
    this.fileStatus.set('loading');
    this.subscriptionStore.loadIfStale();
    this.loading = this.api
      .index()
      .pipe(
        switchMap((index) =>
          this.countryFiles(knownCountries(scope, index)).pipe(map((files) => ({ index, files }))),
        ),
      )
      .subscribe({
        next: ({ index, files }) => {
          this.names.learnCountryNames(index);
          this.loaded.set(files.flatMap((file) => prepareRows(file, scopeLanguages)));
          this.fileStatus.set('ready');
        },
        error: () => this.fileStatus.set('failed'),
      });
  }

  ngOnDestroy(): void {
    this.loading.unsubscribe();
  }

  subscriptionFor(feedUrl: string): SubscriptionDto | null {
    return this.subscriptionsByUrl().get(feedDirectoryUrl(feedUrl)) ?? null;
  }

  toggleCategory(name: string): void {
    this.categories.update((set) => toggled(set, name));
  }

  focusCategory(name: string): void {
    this.categories.set(this.onlyCategory() === name ? new Set() : new Set([name]));
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
