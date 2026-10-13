import {
  ChangeDetectionStrategy,
  Component,
  computed,
  inject,
  linkedSignal,
  signal,
} from '@angular/core';
import { toSignal } from '@angular/core/rxjs-interop';
import { A11yModule } from '@angular/cdk/a11y';
import { DIALOG_DATA, DialogRef } from '@angular/cdk/dialog';
import { TranslocoPipe, TranslocoService } from '@jsverse/transloco';
import { toggled } from '../../core/toggled-set';
import { RefreshService } from '../../reader/state/refresh.service';
import { SubscriptionsStore } from '../../reader/state/subscriptions.store';
import { TagsStore } from '../../reader/state/tags.store';
import { ButtonComponent } from '../../shared/button/button.component';
import { ErrorBannerComponent } from '../../shared/error-banner/error-banner.component';
import { IconComponent } from '../../shared/icon/icon.component';
import { inputValue } from '../../shared/input-value';
import { OverlayPanelComponent } from '../../shared/overlay-panel/overlay-panel.component';
import { SegmentedChoiceComponent } from '../../shared/segmented-choice/segmented-choice.component';
import { SkeletonComponent } from '../../shared/skeleton/skeleton.component';
import { CONFIRMATION_DURATION_MS, ToastService } from '../../shared/toast/toast.service';
import { WarningBoxComponent } from '../../shared/warning-box/warning-box.component';
import { SocialNetworkName } from '../../core/social-network';
import {
  FILTER_KEYS,
  ImportRow,
  ImportTab,
  StateFilter,
  TAB_TEST_IDS,
  buildRows,
  collectionKey,
  followKey,
  tally,
} from './social-import-rows';
import {
  ImportCandidateState,
  SocialImportCollection,
  SocialImportFeed,
  SocialImportResult,
  SocialImportService,
} from './social-import.service';

export interface SocialImportDialogData {
  readonly accountId: number;
  readonly network: SocialNetworkName;
  readonly handle: string;
}

const ROWS_PER_PAGE = 100;

const BADGE_CLASSES: Readonly<Record<ImportCandidateState, string>> = {
  new: 'accent-pill',
  subscribed: 'state',
  no_longer_followed: 'state is-gone',
};

@Component({
  selector: 'app-social-import-dialog',
  imports: [
    A11yModule,
    ButtonComponent,
    ErrorBannerComponent,
    IconComponent,
    OverlayPanelComponent,
    SegmentedChoiceComponent,
    SkeletonComponent,
    TranslocoPipe,
    WarningBoxComponent,
  ],
  providers: [SocialImportService],
  templateUrl: './social-import-dialog.component.html',
  styleUrl: './social-import-dialog.component.scss',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class SocialImportDialogComponent {
  private readonly dialogRef = inject<DialogRef<SocialImportResult | undefined>>(DialogRef);
  protected readonly data = inject<SocialImportDialogData>(DIALOG_DATA);
  protected readonly importer = inject(SocialImportService);
  private readonly subscriptions = inject(SubscriptionsStore);
  private readonly tags = inject(TagsStore);
  private readonly refresh = inject(RefreshService);
  private readonly toast = inject(ToastService);
  private readonly i18n = inject(TranslocoService);
  private readonly activeLang = toSignal(this.i18n.langChanges$, {
    initialValue: this.i18n.getActiveLang(),
  });

  protected readonly filterKeys = FILTER_KEYS;
  protected readonly badgeClasses = BADGE_CLASSES;
  protected readonly tabTestIds = TAB_TEST_IDS;
  protected readonly inputValue = inputValue;
  protected readonly tabs: readonly ImportTab[] =
    this.data.network === 'bluesky' ? ['accounts', 'lists', 'customFeeds'] : ['accounts', 'lists'];
  protected readonly tab = signal<ImportTab>('accounts');
  protected readonly stateFilter = signal<StateFilter>('all');
  protected readonly filter = signal('');
  protected readonly chosen = signal<ReadonlySet<string>>(new Set());

  private readonly rows = computed(() => {
    this.activeLang();
    return buildRows(this.importer.candidates(), (key, params) => this.i18n.translate(key, params));
  });
  private readonly needle = computed(() => this.filter().trim().toLowerCase());
  private readonly tally = computed(() =>
    tally(this.rows(), { tabs: this.tabs, current: this.tab(), needle: this.needle() }),
  );

  protected readonly tabCounts = computed(() => this.tally().tabCounts);
  protected readonly stateOptions = computed<readonly StateFilter[]>(() =>
    this.tally().stateCounts.no_longer_followed > 0
      ? ['all', 'new', 'subscribed', 'no_longer_followed']
      : ['all', 'new', 'subscribed'],
  );
  protected readonly stateCounts = computed(() => this.tally().stateCounts);
  protected readonly activeFilter = computed<StateFilter>(() =>
    this.stateOptions().includes(this.stateFilter()) ? this.stateFilter() : 'all',
  );
  protected readonly visibleRows = computed(() => {
    const filter = this.activeFilter();
    const matched = this.tally().matched;
    return filter === 'all' ? matched : matched.filter((row) => row.state === filter);
  });
  private readonly rowLimit = linkedSignal({
    source: () => [this.tab(), this.activeFilter(), this.needle()],
    computation: () => ROWS_PER_PAGE,
  });
  protected readonly renderedRows = computed(() => this.visibleRows().slice(0, this.rowLimit()));
  protected readonly nextPageSize = computed(() =>
    Math.min(ROWS_PER_PAGE, this.visibleRows().length - this.rowLimit()),
  );
  protected readonly visibleNewCount = computed(() => this.visibleCount('new'));
  protected readonly hasLeftovers = computed(() => this.visibleCount('no_longer_followed') > 0);

  constructor() {
    this.load();
  }

  protected load(): void {
    this.importer.load(this.data.accountId);
  }

  protected toggle(row: ImportRow): void {
    if (row.state === 'subscribed') return;
    this.chosen.update((current) => toggled(current, row.key));
  }

  protected selectAllNew(): void {
    const newKeys = this.visibleRows()
      .filter((row) => row.state === 'new')
      .map((row) => row.key);
    this.chosen.update((current) => new Set([...current, ...newKeys]));
  }

  protected showMore(): void {
    this.rowLimit.update((limit) => limit + ROWS_PER_PAGE);
  }

  protected apply(): void {
    const selection = {
      subscribe: this.chosenFollows(),
      unsubscribe: this.chosenRemovals(),
      collections: this.chosenCollections(),
    };

    this.importer.importSelection(this.data.accountId, selection).subscribe({
      next: (result) => this.finish(result),
      error: () => undefined,
    });
  }

  protected cancel(): void {
    this.dialogRef.close(undefined);
  }

  private visibleCount(state: ImportCandidateState): number {
    const filter = this.activeFilter();
    return filter === 'all' || filter === state ? this.stateCounts()[state] : 0;
  }

  private finish(result: SocialImportResult): void {
    this.announce(result);
    this.subscriptions.load();
    this.tags.load();
    if (result.imported > 0) {
      this.refresh.run(() => this.subscriptions.load());
    }
    this.dialogRef.close(result);
  }

  private announce(result: SocialImportResult): void {
    const done = this.i18n.translate('settings.social.importDone', {
      imported: result.imported,
      removed: result.removed,
      alreadySubscribed: result.alreadySubscribed,
    });
    const overLimit =
      result.skippedOverLimit > 0
        ? ' ' +
          this.i18n.translate('settings.social.importOverLimit', {
            count: result.skippedOverLimit,
          })
        : '';
    this.toast.show({ message: done + overLimit, durationMs: CONFIRMATION_DURATION_MS });
  }

  private chosenFollows(): SocialImportFeed[] {
    return (this.importer.candidates()?.follows ?? [])
      .filter((follow) => follow.state === 'new' && this.chosen().has(followKey(follow)))
      .map((follow) => ({ feedUrl: follow.feedUrl, title: follow.displayName }));
  }

  private chosenRemovals(): number[] {
    return (this.importer.candidates()?.follows ?? [])
      .filter((follow) => follow.state === 'no_longer_followed')
      .filter((follow) => this.chosen().has(followKey(follow)))
      .flatMap((follow) => (follow.subscriptionId === null ? [] : [follow.subscriptionId]));
  }

  private chosenCollections(): SocialImportCollection[] {
    const candidates = this.importer.candidates();
    return [...(candidates?.lists ?? []), ...(candidates?.customFeeds ?? [])]
      .filter(
        (collection) => collection.state === 'new' && this.chosen().has(collectionKey(collection)),
      )
      .map(({ kind, id, name }) => ({ kind, id, name }));
  }
}
