import { Dialog } from '@angular/cdk/dialog';
import {
  CdkFixedSizeVirtualScroll,
  CdkVirtualForOf,
  CdkVirtualScrollViewport,
  CdkVirtualScrollableWindow,
} from '@angular/cdk/scrolling';
import {
  ChangeDetectionStrategy,
  Component,
  computed,
  effect,
  inject,
  untracked,
} from '@angular/core';
import { takeUntilDestroyed } from '@angular/core/rxjs-interop';
import { TranslocoPipe } from '@jsverse/transloco';
import { Subject, debounceTime } from 'rxjs';
import { FeedDirectoryScope } from '../../core/preferences/feed-directory-scope';
import { sameIds } from '../../core/same-ids';
import { ButtonComponent } from '../../shared/button/button.component';
import { ErrorBannerComponent } from '../../shared/error-banner/error-banner.component';
import { IconComponent } from '../../shared/icon/icon.component';
import { inputValue } from '../../shared/input-value';
import { SegmentedChoiceComponent } from '../../shared/segmented-choice/segmented-choice.component';
import { SettingsGroupComponent } from '../../shared/settings/settings-group/settings-group.component';
import { SettingsStackComponent } from '../../shared/settings/stack/settings-stack.component';
import { SkeletonComponent } from '../../shared/skeleton/skeleton.component';
import { LayoutService } from '../../reader/layout.service';
import { FeedDirectoryNames } from './feed-directory-names';
import {
  FeedDirectoryLength,
  FeedDirectoryMode,
  FeedDirectoryRow,
  FeedDirectorySort,
} from './feed-directory-rows';
import { FeedDirectoryScopeService } from './feed-directory-scope.service';
import { FeedDirectoryStore } from './feed-directory.store';
import { FeedDirectorySubscriber } from './feed-directory-subscriber';
import {
  FeedDirectoryFilterPillComponent,
  FeedDirectoryFilterPillOption,
} from './filter-pill/feed-directory-filter-pill.component';
import {
  FEED_DIRECTORY_ROW_HEIGHT,
  FeedDirectoryRowComponent,
} from './row/feed-directory-row.component';
import { FeedDirectoryScopeDialogComponent } from './scope-dialog/feed-directory-scope-dialog.component';
import { FeedDirectoryScopePickerComponent } from './scope-picker/feed-directory-scope-picker.component';

const ALL_COUNTRIES = '';
const MODES: readonly FeedDirectoryMode[] = ['channels', 'podcasts'];
const LENGTHS: readonly FeedDirectoryLength[] = ['headlines', 'summaries', 'long'];
const SORTS: readonly FeedDirectorySort[] = ['top', 'az', 'feeds'];
const SEARCH_DEBOUNCE_MS = 150;

@Component({
  selector: 'app-feed-directory-section',
  imports: [
    ButtonComponent,
    CdkFixedSizeVirtualScroll,
    CdkVirtualForOf,
    CdkVirtualScrollViewport,
    CdkVirtualScrollableWindow,
    ErrorBannerComponent,
    FeedDirectoryFilterPillComponent,
    FeedDirectoryRowComponent,
    FeedDirectoryScopePickerComponent,
    IconComponent,
    SegmentedChoiceComponent,
    SettingsGroupComponent,
    SettingsStackComponent,
    SkeletonComponent,
    TranslocoPipe,
  ],
  providers: [FeedDirectoryStore, FeedDirectorySubscriber],
  templateUrl: './feed-directory-section.component.html',
  styleUrl: './feed-directory-section.component.scss',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class FeedDirectorySectionComponent {
  protected readonly store = inject(FeedDirectoryStore);
  protected readonly scopes = inject(FeedDirectoryScopeService);
  protected readonly names = inject(FeedDirectoryNames);
  protected readonly subscriber = inject(FeedDirectorySubscriber);
  private readonly dialog = inject(Dialog);
  private readonly layout = inject(LayoutService);
  private readonly scopeToLoad = computed(
    () => (this.scopes.hasScope() ? this.scopes.scope() : null),
    { equal: sameScope },
  );

  protected readonly rowHeight = computed(() =>
    this.layout.isPhone() ? FEED_DIRECTORY_ROW_HEIGHT.phone : FEED_DIRECTORY_ROW_HEIGHT.wide,
  );
  protected readonly modes = MODES;
  protected readonly byHost = (_index: number, row: FeedDirectoryRow): string => row.host;
  private readonly typedQuery = new Subject<string>();

  protected readonly countryNames = computed(() =>
    this.scopes.scope().countries.map((code) => this.names.country(code)),
  );
  protected readonly languageNames = computed(() =>
    this.scopes.scope().languages.map((code) => this.names.language(code)),
  );
  protected readonly chosenCountry = computed(() => {
    const code = this.store.country();
    return code === null ? null : this.names.country(code);
  });

  protected readonly countryOptions = computed<FeedDirectoryFilterPillOption[]>(() => {
    const counts = this.store.countryCounts();
    const all = [...counts.values()].reduce((sum, count) => sum + count, 0);
    return [
      {
        value: ALL_COUNTRIES,
        labelKey: 'settings.feedDirectory.filters.allCountries',
        count: this.names.count(all),
        selected: this.store.country() === null,
      },
      ...this.scopes.scope().countries.map((code) => ({
        value: code,
        label: this.names.country(code),
        count: this.names.count(counts.get(code) ?? 0),
        selected: this.store.country() === code,
      })),
    ];
  });
  protected readonly categoryOptions = computed(() =>
    optionsOf(
      this.store.categoryOptions(),
      (name) => ({ label: name }),
      (name) => this.store.categories().has(name),
    ),
  );
  protected readonly lengthOptions = computed(() =>
    optionsOf(
      LENGTHS,
      (length) => ({ labelKey: `settings.feedDirectory.lengths.${length}` }),
      (length) => this.store.lengths().has(length),
    ),
  );
  protected readonly languageOptions = computed(() =>
    optionsOf(
      this.scopes.scope().languages,
      (code) => ({ label: this.names.language(code) }),
      (code) => this.store.languages().has(code),
    ),
  );
  protected readonly sortOptions = computed(() =>
    optionsOf(
      SORTS,
      (sort) => ({ labelKey: `settings.feedDirectory.sort.${sort}` }),
      (sort) => this.store.sort() === sort,
    ),
  );

  constructor() {
    effect(() => {
      if (this.scopeToLoad() !== null) untracked(() => this.store.load());
    });
    this.typedQuery
      .pipe(debounceTime(SEARCH_DEBOUNCE_MS), takeUntilDestroyed())
      .subscribe((query) => this.store.query.set(query));
  }

  protected search(event: Event): void {
    this.typedQuery.next(inputValue(event));
  }

  protected chooseCountry(code: string): void {
    this.store.country.set(code === ALL_COUNTRIES ? null : code);
  }

  protected changeScope(): void {
    this.dialog.open(FeedDirectoryScopeDialogComponent, { panelClass: 'app-dialog' });
  }
}

function sameScope(left: FeedDirectoryScope | null, right: FeedDirectoryScope | null): boolean {
  if (left === null || right === null) return left === right;
  return sameIds(left.countries, right.countries) && sameIds(left.languages, right.languages);
}

function optionsOf<T extends string>(
  values: readonly T[],
  labelOf: (value: T) => Pick<FeedDirectoryFilterPillOption<T>, 'label' | 'labelKey'>,
  isSelected: (value: T) => boolean,
): FeedDirectoryFilterPillOption<T>[] {
  return values.map((value) => ({ value, ...labelOf(value), selected: isSelected(value) }));
}
