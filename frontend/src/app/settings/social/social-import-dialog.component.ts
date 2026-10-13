import { ChangeDetectionStrategy, Component, computed, inject, signal } from '@angular/core';
import { A11yModule } from '@angular/cdk/a11y';
import { DIALOG_DATA, DialogRef } from '@angular/cdk/dialog';
import { TranslocoPipe, TranslocoService } from '@jsverse/transloco';
import { pluralKey } from '../../core/i18n/plural-key';
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
import { SpinnerComponent } from '../../shared/spinner/spinner.component';
import { CONFIRMATION_DURATION_MS, ToastService } from '../../shared/toast/toast.service';
import { WarningBoxComponent } from '../../shared/warning-box/warning-box.component';
import { SocialNetworkName } from '../../core/social-network';
import {
  CHECKBOX_KEYS,
  COLLECTION_CHECKBOX_KEYS,
  COLLECTION_ICONS,
  FILTER_KEYS,
  ImportTab,
  STATE_KEYS,
  StateFilter,
  StateOption,
  TAB_TEST_IDS,
  collectionKey,
  initialsOf,
} from './social-import-rows';
import {
  CollectionCandidate,
  FollowCandidate,
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

@Component({
  selector: 'app-social-import-dialog',
  imports: [
    A11yModule,
    ButtonComponent,
    ErrorBannerComponent,
    IconComponent,
    OverlayPanelComponent,
    SegmentedChoiceComponent,
    SpinnerComponent,
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

  protected readonly stateKeys = STATE_KEYS;
  protected readonly filterKeys = FILTER_KEYS;
  protected readonly checkboxKeys = CHECKBOX_KEYS;
  protected readonly collectionCheckboxKeys = COLLECTION_CHECKBOX_KEYS;
  protected readonly collectionIcons = COLLECTION_ICONS;
  protected readonly tabTestIds = TAB_TEST_IDS;
  protected readonly collectionKey = collectionKey;
  protected readonly inputValue = inputValue;
  protected readonly tabs: readonly ImportTab[] =
    this.data.network === 'bluesky' ? ['accounts', 'lists', 'customFeeds'] : ['accounts', 'lists'];
  protected readonly tab = signal<ImportTab>('accounts');
  protected readonly stateFilter = signal<StateFilter>('all');
  protected readonly filter = signal('');
  protected readonly toSubscribe = signal<ReadonlySet<string>>(new Set());
  protected readonly toUnsubscribe = signal<ReadonlySet<number>>(new Set());
  protected readonly toCollect = signal<ReadonlySet<string>>(new Set());

  private readonly needle = computed(() => this.filter().trim().toLowerCase());

  private readonly matchedFollows = computed(() =>
    (this.importer.candidates()?.follows ?? []).filter((follow) =>
      this.matches(follow.handle, follow.displayName),
    ),
  );
  private readonly matchedCollections = computed(() => {
    const candidates = this.importer.candidates();
    return {
      lists: this.matchedOf(candidates?.lists),
      customFeeds: this.matchedOf(candidates?.customFeeds),
    };
  });
  private readonly tabItems = computed<readonly { readonly state: ImportCandidateState }[]>(() => {
    const tab = this.tab();
    return tab === 'accounts' ? this.matchedFollows() : this.matchedCollections()[tab];
  });

  protected readonly tabCounts = computed<Partial<Record<ImportTab, number>>>(() => ({
    accounts: this.matchedFollows().length,
    lists: this.matchedCollections().lists.length,
    customFeeds: this.matchedCollections().customFeeds.length,
  }));
  protected readonly stateOptions = computed<readonly StateOption[]>(() => {
    const items = this.tabItems();
    const countOf = (state: ImportCandidateState) =>
      items.filter((item) => item.state === state).length;
    const options: StateOption[] = [
      { state: 'all', count: items.length },
      { state: 'new', count: countOf('new') },
      { state: 'subscribed', count: countOf('subscribed') },
    ];
    const leftovers = countOf('no_longer_followed');
    if (leftovers > 0) options.push({ state: 'no_longer_followed', count: leftovers });
    return options;
  });
  protected readonly activeFilter = computed<StateFilter>(() =>
    this.stateOptions().some((option) => option.state === this.stateFilter())
      ? this.stateFilter()
      : 'all',
  );
  protected readonly visibleFollows = computed(() =>
    this.matchedFollows().filter((follow) => this.passesFilter(follow.state)),
  );
  protected readonly visibleCollections = computed(() => {
    const tab = this.tab();
    if (tab === 'accounts') return [];
    return this.matchedCollections()[tab].filter((item) => this.passesFilter(item.state));
  });
  protected readonly visibleNewCount = computed(() =>
    this.tab() === 'accounts'
      ? this.visibleFollows().filter((follow) => follow.state === 'new').length
      : this.visibleCollections().filter((item) => item.state === 'new').length,
  );
  protected readonly hasLeftovers = computed(
    () =>
      this.tab() === 'accounts' &&
      this.visibleFollows().some((follow) => follow.state === 'no_longer_followed'),
  );
  protected readonly selectedCount = computed(
    () => this.toSubscribe().size + this.toUnsubscribe().size + this.toCollect().size,
  );
  protected readonly selectedKey = computed(() =>
    pluralKey('settings.social.importSelected', this.selectedCount()),
  );

  constructor() {
    this.load();
  }

  protected load(): void {
    this.importer.load(this.data.accountId);
  }

  protected initials(follow: FollowCandidate): string {
    return initialsOf(follow.displayName ?? follow.handle);
  }

  protected isChosen(follow: FollowCandidate): boolean {
    switch (follow.state) {
      case 'subscribed':
        return true;
      case 'new':
        return this.toSubscribe().has(follow.feedUrl);
      case 'no_longer_followed':
        return follow.subscriptionId !== null && this.toUnsubscribe().has(follow.subscriptionId);
    }
  }

  protected toggle(follow: FollowCandidate): void {
    switch (follow.state) {
      case 'subscribed':
        return;
      case 'new':
        this.toSubscribe.update((current) => toggled(current, follow.feedUrl));
        return;
      case 'no_longer_followed':
        this.toggleRemoval(follow.subscriptionId);
    }
  }

  protected selectAllNew(): void {
    if (this.tab() === 'accounts') {
      this.selectNewFollows();
      return;
    }
    this.selectNewCollections();
  }

  protected apply(): void {
    const selection = {
      subscribe: this.chosenFollows(),
      unsubscribe: [...this.toUnsubscribe()],
      collections: this.chosenCollections(),
    };

    this.importer.importSelection(this.data.accountId, selection).subscribe({
      next: (result) => this.finish(result),
      error: () => undefined,
    });
  }

  protected collectionName(collection: CollectionCandidate): string {
    return collection.kind === 'home'
      ? this.i18n.translate('settings.social.importHome')
      : collection.name;
  }

  protected isCollected(collection: CollectionCandidate): boolean {
    return collection.state === 'subscribed' || this.toCollect().has(collectionKey(collection));
  }

  protected toggleCollection(collection: CollectionCandidate): void {
    if (collection.state === 'subscribed') return;
    this.toCollect.update((current) => toggled(current, collectionKey(collection)));
  }

  protected cancel(): void {
    this.dialogRef.close(undefined);
  }

  private selectNewFollows(): void {
    const feedUrls = this.visibleFollows()
      .filter((follow) => follow.state === 'new')
      .map((follow) => follow.feedUrl);
    this.toSubscribe.update((current) => new Set([...current, ...feedUrls]));
  }

  private selectNewCollections(): void {
    const keys = this.visibleCollections()
      .filter((item) => item.state === 'new')
      .map(collectionKey);
    this.toCollect.update((current) => new Set([...current, ...keys]));
  }

  private toggleRemoval(subscriptionId: number | null): void {
    if (subscriptionId === null) return;
    this.toUnsubscribe.update((current) => toggled(current, subscriptionId));
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
      .filter((follow) => follow.state === 'new' && this.toSubscribe().has(follow.feedUrl))
      .map((follow) => ({ feedUrl: follow.feedUrl, title: follow.displayName }));
  }

  private chosenCollections(): SocialImportCollection[] {
    const candidates = this.importer.candidates();
    return [...(candidates?.lists ?? []), ...(candidates?.customFeeds ?? [])]
      .filter(
        (collection) =>
          collection.state === 'new' && this.toCollect().has(collectionKey(collection)),
      )
      .map(({ kind, id, name }) => ({ kind, id, name }));
  }

  private matchedOf(items: readonly CollectionCandidate[] = []): readonly CollectionCandidate[] {
    return items.filter((item) => this.matches(this.collectionName(item)));
  }

  private passesFilter(state: ImportCandidateState): boolean {
    const filter = this.activeFilter();
    return filter === 'all' || filter === state;
  }

  private matches(...texts: readonly (string | null)[]): boolean {
    const needle = this.needle();
    return needle === '' || texts.some((text) => (text ?? '').toLowerCase().includes(needle));
  }
}
