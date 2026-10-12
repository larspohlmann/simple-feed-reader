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
import { DisclosureComponent } from '../../shared/disclosure/disclosure.component';
import { ErrorBannerComponent } from '../../shared/error-banner/error-banner.component';
import { inputValue } from '../../shared/input-value';
import { OverlayPanelComponent } from '../../shared/overlay-panel/overlay-panel.component';
import { SpinnerComponent } from '../../shared/spinner/spinner.component';
import { CONFIRMATION_DURATION_MS, ToastService } from '../../shared/toast/toast.service';
import { WarningBoxComponent } from '../../shared/warning-box/warning-box.component';
import { SocialNetworkName } from '../../core/social-network';
import {
  CollectionCandidate,
  CollectionState,
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

const STATE_KEYS: Readonly<Record<ImportCandidateState, string>> = {
  new: 'settings.social.importStateNew',
  subscribed: 'settings.social.importStateSubscribed',
  no_longer_followed: 'settings.social.importStateNoLongerFollowed',
};

const CHECKBOX_KEYS: Readonly<Record<ImportCandidateState, string>> = {
  new: 'settings.social.importSubscribe',
  subscribed: 'settings.social.importAlreadySubscribed',
  no_longer_followed: 'settings.social.importUnsubscribe',
};

const COLLECTION_CHECKBOX_KEYS: Readonly<Record<CollectionState, string>> = {
  new: 'settings.social.importAddCollection',
  subscribed: 'settings.social.importCollectionAdded',
};

/** Home has no id, and a list and a custom feed could share one, so the kind is part of the key. */
export function collectionKey(collection: CollectionCandidate): string {
  return `${collection.kind}:${collection.id ?? ''}`;
}

interface CollectionSection {
  readonly labelKey: string;
  readonly testId: string;
  readonly items: readonly CollectionCandidate[];
}

@Component({
  selector: 'app-social-import-dialog',
  imports: [
    A11yModule,
    ButtonComponent,
    DisclosureComponent,
    ErrorBannerComponent,
    OverlayPanelComponent,
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
  protected readonly checkboxKeys = CHECKBOX_KEYS;
  protected readonly collectionCheckboxKeys = COLLECTION_CHECKBOX_KEYS;
  protected readonly collectionKey = collectionKey;
  protected readonly inputValue = inputValue;
  private readonly showsCustomFeeds = this.data.network === 'bluesky';
  protected readonly filter = signal('');
  protected readonly toSubscribe = signal<ReadonlySet<string>>(new Set());
  protected readonly toUnsubscribe = signal<ReadonlySet<number>>(new Set());
  protected readonly toCollect = signal<ReadonlySet<string>>(new Set());

  private readonly needle = computed(() => this.filter().trim().toLowerCase());

  protected readonly followView = computed(() => {
    const follows: FollowCandidate[] = [];
    const visibleNew: FollowCandidate[] = [];
    let hasLeftovers = false;
    for (const follow of this.importer.candidates()?.follows ?? []) {
      if (!this.matches(follow.handle, follow.displayName)) continue;
      follows.push(follow);
      if (follow.state === 'new') visibleNew.push(follow);
      if (follow.state === 'no_longer_followed') hasLeftovers = true;
    }
    return { follows, visibleNew, hasLeftovers };
  });
  protected readonly selectedCount = computed(
    () => this.toSubscribe().size + this.toUnsubscribe().size + this.toCollect().size,
  );

  protected readonly followsKey = computed(() =>
    pluralKey('settings.social.importFollows', this.followView().follows.length),
  );
  protected readonly collections = computed<readonly CollectionSection[]>(() => {
    const candidates = this.importer.candidates();
    const sections = [
      this.collection('settings.social.importLists', 'social-import-lists', candidates?.lists),
    ];
    if (this.showsCustomFeeds) {
      sections.push(
        this.collection(
          'settings.social.importCustomFeeds',
          'social-import-feeds',
          candidates?.customFeeds,
        ),
      );
    }
    return sections;
  });

  constructor() {
    this.load();
  }

  protected load(): void {
    this.importer.load(this.data.accountId);
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
    const feedUrls = this.followView().visibleNew.map((follow) => follow.feedUrl);
    this.toSubscribe.update((current) => new Set([...current, ...feedUrls]));
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

  private collection(
    label: string,
    testId: string,
    items: readonly CollectionCandidate[] = [],
  ): CollectionSection {
    const shown = items.filter((item) => this.matches(this.collectionName(item)));
    return { labelKey: pluralKey(label, shown.length), testId, items: shown };
  }

  private matches(...texts: readonly (string | null)[]): boolean {
    const needle = this.needle();
    return needle === '' || texts.some((text) => (text ?? '').toLowerCase().includes(needle));
  }
}
