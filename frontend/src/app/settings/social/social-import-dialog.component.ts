import { ChangeDetectionStrategy, Component, computed, inject, signal } from '@angular/core';
import { A11yModule } from '@angular/cdk/a11y';
import { DIALOG_DATA, DialogRef } from '@angular/cdk/dialog';
import { TranslocoPipe, TranslocoService } from '@jsverse/transloco';
import { pluralKey } from '../../core/i18n/plural-key';
import { RefreshService } from '../../reader/state/refresh.service';
import { SubscriptionsStore } from '../../reader/state/subscriptions.store';
import { TagsStore } from '../../reader/state/tags.store';
import { ButtonComponent } from '../../shared/button/button.component';
import { DisclosureComponent } from '../../shared/disclosure/disclosure.component';
import { ErrorBannerComponent } from '../../shared/error-banner/error-banner.component';
import { OverlayPanelComponent } from '../../shared/overlay-panel/overlay-panel.component';
import { SpinnerComponent } from '../../shared/spinner/spinner.component';
import { CONFIRMATION_DURATION_MS, ToastService } from '../../shared/toast/toast.service';
import { WarningBoxComponent } from '../../shared/warning-box/warning-box.component';
import { SocialNetwork } from './social-accounts.service';
import {
  FollowCandidate,
  ImportCandidateState,
  SocialImportResult,
  SocialImportService,
} from './social-import.service';

export interface SocialImportDialogData {
  readonly accountId: number;
  readonly network: SocialNetwork;
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

  protected readonly showsCustomFeeds = this.data.network === 'bluesky';
  protected readonly filter = signal('');
  protected readonly toSubscribe = signal<ReadonlySet<string>>(new Set());
  protected readonly toUnsubscribe = signal<ReadonlySet<number>>(new Set());

  private readonly needle = computed(() => this.filter().trim().toLowerCase());

  protected readonly follows = computed(() =>
    (this.importer.candidates()?.follows ?? []).filter((follow) =>
      this.matches(follow.handle, follow.displayName),
    ),
  );
  protected readonly lists = computed(() =>
    (this.importer.candidates()?.lists ?? []).filter((list) => this.matches(list.name)),
  );
  protected readonly customFeeds = computed(() =>
    (this.importer.candidates()?.customFeeds ?? []).filter((feed) => this.matches(feed.name)),
  );
  protected readonly visibleNew = computed(() =>
    this.follows().filter((follow) => follow.state === 'new'),
  );
  protected readonly hasLeftovers = computed(() =>
    this.follows().some((follow) => follow.state === 'no_longer_followed'),
  );
  protected readonly selectedCount = computed(
    () => this.toSubscribe().size + this.toUnsubscribe().size,
  );

  protected readonly followsKey = computed(() =>
    pluralKey('settings.social.importFollows', this.follows().length),
  );
  protected readonly listsKey = computed(() =>
    pluralKey('settings.social.importLists', this.lists().length),
  );
  protected readonly customFeedsKey = computed(() =>
    pluralKey('settings.social.importCustomFeeds', this.customFeeds().length),
  );

  constructor() {
    this.importer.load(this.data.accountId);
  }

  protected typed(event: Event): string {
    return (event.target as HTMLInputElement).value;
  }

  protected stateKey(follow: FollowCandidate): string {
    return STATE_KEYS[follow.state];
  }

  protected checkboxKey(follow: FollowCandidate): string {
    return CHECKBOX_KEYS[follow.state];
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
    const feedUrls = this.visibleNew().map((follow) => follow.feedUrl);
    this.toSubscribe.update((current) => new Set([...current, ...feedUrls]));
  }

  protected retry(): void {
    this.importer.load(this.data.accountId);
  }

  protected apply(): void {
    const subscribe = (this.importer.candidates()?.follows ?? [])
      .filter((follow) => follow.state === 'new' && this.toSubscribe().has(follow.feedUrl))
      .map((follow) => ({ feedUrl: follow.feedUrl, title: follow.displayName }));
    const unsubscribe = [...this.toUnsubscribe()];

    this.importer.importSelection(this.data.accountId, { subscribe, unsubscribe }).subscribe({
      next: (result) => this.finish(result),
      error: () => undefined,
    });
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

  private matches(...texts: readonly (string | null)[]): boolean {
    const needle = this.needle();
    return needle === '' || texts.some((text) => (text ?? '').toLowerCase().includes(needle));
  }
}

function toggled<T>(current: ReadonlySet<T>, value: T): ReadonlySet<T> {
  const next = new Set(current);
  if (next.has(value)) {
    next.delete(value);
  } else {
    next.add(value);
  }
  return next;
}
