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
import { TranslocoPipe } from '@jsverse/transloco';
import { ButtonComponent } from '../../shared/button/button.component';
import { ErrorBannerComponent } from '../../shared/error-banner/error-banner.component';
import { IconComponent } from '../../shared/icon/icon.component';
import { inputValue } from '../../shared/input-value';
import { SegmentedChoiceComponent } from '../../shared/segmented-choice/segmented-choice.component';
import { SettingsGroupComponent } from '../../shared/settings/settings-group/settings-group.component';
import { SettingsStackComponent } from '../../shared/settings/stack/settings-stack.component';
import { SkeletonComponent } from '../../shared/skeleton/skeleton.component';
import { FeedDirectoryNames } from './feed-directory-names';
import {
  FeedDirectoryLength,
  FeedDirectoryMode,
  FeedDirectoryRow,
  FeedDirectorySort,
} from './feed-directory-rows';
import { FeedDirectoryScopeService } from './feed-directory-scope.service';
import { FeedDirectoryStore } from './feed-directory.store';
import { FeedDirectoryScope } from './feed-directory.models';
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
  providers: [FeedDirectoryStore],
  templateUrl: './feed-directory-section.component.html',
  styleUrl: './feed-directory-section.component.scss',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class FeedDirectorySectionComponent {
  protected readonly store = inject(FeedDirectoryStore);
  protected readonly scopes = inject(FeedDirectoryScopeService);
  protected readonly names = inject(FeedDirectoryNames);
  private readonly dialog = inject(Dialog);
  private readonly scopeToLoad = computed(
    () => (this.scopes.hasScope() ? this.scopes.scope() : null),
    { equal: sameScope },
  );

  protected readonly rowHeight = FEED_DIRECTORY_ROW_HEIGHT;
  protected readonly modes = MODES;
  protected readonly inputValue = inputValue;
  protected readonly byHost = (_index: number, row: FeedDirectoryRow): string => row.host;

  protected readonly countryChoice = computed(() => this.scopes.scope().countries.length > 1);
  protected readonly languageChoice = computed(() => this.scopes.scope().languages.length > 1);
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
  protected readonly categoryOptions = computed<FeedDirectoryFilterPillOption[]>(() =>
    this.store.categoryOptions().map((name) => ({
      value: name,
      label: name,
      selected: this.store.categories().has(name),
    })),
  );
  protected readonly lengthOptions = computed<FeedDirectoryFilterPillOption<FeedDirectoryLength>[]>(
    () =>
      LENGTHS.map((length) => ({
        value: length,
        labelKey: `settings.feedDirectory.lengths.${length}`,
        selected: this.store.lengths().has(length),
      })),
  );
  protected readonly languageOptions = computed<FeedDirectoryFilterPillOption[]>(() =>
    this.scopes.scope().languages.map((code) => ({
      value: code,
      label: this.names.language(code),
      selected: this.store.languages().has(code),
    })),
  );
  protected readonly sortOptions = computed<FeedDirectoryFilterPillOption<FeedDirectorySort>[]>(
    () =>
      SORTS.map((sort) => ({
        value: sort,
        labelKey: `settings.feedDirectory.sort.${sort}`,
        selected: this.store.sort() === sort,
      })),
  );

  constructor() {
    effect(() => {
      if (this.scopeToLoad() !== null) untracked(() => this.store.load());
    });
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
  return (
    left.countries.join() === right.countries.join() &&
    left.languages.join() === right.languages.join()
  );
}
