import { Injectable, computed, inject, linkedSignal, signal } from '@angular/core';
import { toSignal } from '@angular/core/rxjs-interop';
import { DIALOG_DATA } from '@angular/cdk/dialog';
import { TranslocoService } from '@jsverse/transloco';
import { pluralKey } from '../../../core/i18n/plural-key';
import { SocialNetworkName } from '../../../core/social-network';
import { toggled } from '../../../core/toggled-set';
import { ConfirmService } from '../../../shared/confirm-dialog/confirm.service';
import { savedFeedLabel } from '../display-title';
import {
  DialogTab,
  ImportRow,
  ImportTab,
  Removal,
  StateFilter,
  buildRows,
  collectionKey,
  collectionsOf,
  followKey,
  removals,
  tally,
} from './social-import-rows';
import {
  ImportCandidateState,
  SocialImportCollection,
  SocialImportFeed,
  SocialImportSelection,
  SocialImportService,
} from './social-import.service';

export interface SocialImportDialogData {
  readonly accountId: number;
  readonly network: SocialNetworkName;
  readonly handle: string;
}

const ROWS_PER_PAGE = 100;

/**
 * The import dialog's follows list: the open tab, the filter, the ticks and what Apply sends. Provided by the
 * dialog, so all of it outlives a trip to the Search tab.
 */
@Injectable()
export class SocialImportList {
  private readonly data = inject<SocialImportDialogData>(DIALOG_DATA);
  private readonly importer = inject(SocialImportService);
  private readonly confirm = inject(ConfirmService);
  private readonly i18n = inject(TranslocoService);
  private readonly activeLang = toSignal(this.i18n.langChanges$, {
    initialValue: this.i18n.getActiveLang(),
  });

  private readonly listTabs: readonly ImportTab[] =
    this.data.network === 'bluesky'
      ? ['accounts', 'lists', 'customFeeds']
      : ['accounts', 'lists', 'tags'];
  readonly tabs: readonly DialogTab[] = [...this.listTabs, 'search'];
  readonly tab = signal<DialogTab>('accounts');
  /** The list tab on show; null on Search. */
  readonly listTab = computed(() => {
    const tab = this.tab();
    return tab === 'search' ? null : tab;
  });
  /** The list's controls, rows and Apply show on a list tab once the candidates are in. */
  readonly visible = computed(() => this.listTab() !== null && this.importer.candidates() !== null);
  readonly stateFilter = signal<StateFilter>('all');
  readonly filter = signal('');
  readonly chosen = signal<ReadonlySet<string>>(new Set());

  private readonly rows = computed(() => {
    this.activeLang();
    return buildRows(this.importer.candidates(), (key, params) => this.i18n.translate(key, params));
  });
  private readonly needle = computed(() => this.filter().trim().toLowerCase());
  private readonly tally = computed(() =>
    tally(this.rows(), { tabs: this.listTabs, current: this.tab(), needle: this.needle() }),
  );

  readonly tabCounts = computed(() =>
    this.importer.candidates() === null ? {} : this.tally().tabCounts,
  );
  readonly stateOptions = computed<readonly StateFilter[]>(() =>
    this.tally().stateCounts.no_longer_followed > 0
      ? ['all', 'new', 'subscribed', 'no_longer_followed']
      : ['all', 'new', 'subscribed'],
  );
  readonly stateCounts = computed(() => this.tally().stateCounts);
  readonly activeFilter = computed<StateFilter>(() =>
    this.stateOptions().includes(this.stateFilter()) ? this.stateFilter() : 'all',
  );
  readonly visibleRows = computed(() => {
    const filter = this.activeFilter();
    const matched = this.tally().matched;
    return filter === 'all' ? matched : matched.filter((row) => row.state === filter);
  });
  readonly applyCountKey = computed(() =>
    pluralKey('settings.social.importApplyCount', this.chosen().size),
  );
  private readonly rowLimit = linkedSignal({
    source: () => [this.tab(), this.activeFilter(), this.needle()],
    computation: () => ROWS_PER_PAGE,
  });
  readonly renderedRows = computed(() => this.visibleRows().slice(0, this.rowLimit()));
  readonly nextPageSize = computed(() =>
    Math.min(ROWS_PER_PAGE, this.visibleRows().length - this.rowLimit()),
  );
  readonly visibleNewCount = computed(() => this.visibleCount('new'));
  readonly hasSubscribedRows = computed(() => this.visibleRows().some((row) => row.removable));

  toggle(row: ImportRow): void {
    this.chosen.update((current) => toggled(current, row.key));
  }

  selectAllNew(): void {
    const newKeys = this.visibleRows()
      .filter((row) => row.state === 'new')
      .map((row) => row.key);
    this.chosen.update((current) => new Set([...current, ...newKeys]));
  }

  showMore(): void {
    this.rowLimit.update((limit) => limit + ROWS_PER_PAGE);
  }

  /** Hands the ticked changes to `send`, after asking first when any of them unsubscribes. */
  apply(send: (selection: SocialImportSelection) => void): void {
    const unticked = removals(this.importer.candidates(), this.chosen(), (key, params) =>
      this.i18n.translate(key, params),
    );
    const sendSelection = () => send(this.selection(unticked));
    if (unticked.length === 0) {
      sendSelection();
      return;
    }
    this.confirm.confirmThen(
      {
        title: this.i18n.translate('settings.social.importRemoveTitle'),
        message: this.removalMessage(unticked),
        confirmLabel: this.i18n.translate('settings.social.importRemoveConfirm'),
        danger: true,
      },
      sendSelection,
    );
  }

  private selection(unticked: readonly Removal[]): SocialImportSelection {
    return {
      subscribe: this.chosenFollows(),
      unsubscribe: unticked.map((removal) => removal.subscriptionId),
      collections: this.chosenCollections(),
    };
  }

  private visibleCount(state: ImportCandidateState): number {
    const filter = this.activeFilter();
    return filter === 'all' || filter === state ? this.stateCounts()[state] : 0;
  }

  private chosenFollows(): SocialImportFeed[] {
    return (this.importer.candidates()?.follows ?? [])
      .filter((follow) => follow.state === 'new' && this.chosen().has(followKey(follow)))
      .map((follow) => ({ identity: follow.identity, title: follow.displayName }));
  }

  private chosenCollections(): SocialImportCollection[] {
    return collectionsOf(this.importer.candidates())
      .filter(
        (collection) => collection.state === 'new' && this.chosen().has(collectionKey(collection)),
      )
      .map(({ kind, id, name }) => ({ kind, id, name }));
  }

  private removalMessage(unticked: readonly Removal[]): string {
    const removal =
      unticked.length === 1
        ? this.i18n.translate('settings.social.importRemoveMessage', { name: unticked[0].name })
        : this.i18n.translate('settings.social.importRemoveCountMessage', {
            count: unticked.length,
          });
    const moves = this.i18n.translate('manage.savedPostsMove', {
      saved: savedFeedLabel(this.i18n, this.data.network),
    });
    return `${removal} ${moves}`;
  }
}
