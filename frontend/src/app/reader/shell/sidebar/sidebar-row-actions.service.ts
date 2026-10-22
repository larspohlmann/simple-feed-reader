import { DestroyRef, Injectable, inject, signal } from '@angular/core';
import { takeUntilDestroyed } from '@angular/core/rxjs-interop';
import { TranslocoService } from '@jsverse/transloco';
import { ActionSheetAction } from '../../../shared/action-sheet/action-sheet.component';
import { ActionSheet } from '../../../shared/action-sheet/action-sheet.service';
import { ManageActions } from '../../feeds/manage/manage-actions.service';
import { SocialFeedsPicker } from '../../feeds/social-picker/social-feeds-picker.service';
import { SubscriptionDto, TagDto } from '../../models';
import {
  FEED_FLAG_TOGGLES,
  FeedFlagToggle,
  feedFlagLabelKey,
  flipFeedFlag,
} from '../../feed-exclusions';
import { SocialAccountRefDto } from '../../social-feed';
import { SavedSearchesStore } from '../../state/saved-searches.store';
import { SocialSection } from '../../state/social-sections';

const SAVED_SEARCHES_CHOICE = 'savedSearches';

/** The row menus and action sheets of the sidebar's tag and feed rows. Provided
 *  on the sidebar, so all rows share one open menu and a late sheet choice dies
 *  with the sidebar. */
@Injectable()
export class SidebarRowActions {
  private readonly manage = inject(ManageActions);
  private readonly sheet = inject(ActionSheet);
  private readonly transloco = inject(TranslocoService);
  private readonly picker = inject(SocialFeedsPicker);
  private readonly savedSearches = inject(SavedSearchesStore);
  private readonly destroyRef = inject(DestroyRef);

  readonly menuFor = signal<string | null>(null);

  toggleMenu(key: string, event: Event): void {
    event.preventDefault();
    event.stopPropagation();
    this.menuFor.update((openKey) => (openKey === key ? null : key));
  }

  closeMenu(): void {
    this.menuFor.set(null);
  }

  /** ⋯ on a tag row (coarse): sheet with the tag's actions. */
  openTagSheet(tag: TagDto): void {
    this.sheet
      .open({
        title: tag.name,
        actions: [
          { id: 'edit', label: this.transloco.translate('reader.editTag') },
          { id: 'delete', label: this.transloco.translate('reader.deleteTag'), danger: true },
        ],
      })
      // A sheet can outlive the sidebar (e.g. the shell unmounts); a late
      // choice must not emit into destroyed outputs.
      .pipe(takeUntilDestroyed(this.destroyRef))
      .subscribe((choice) => {
        if (choice === 'edit') this.manage.editTag(tag);
        if (choice === 'delete') this.manage.deleteTag(tag);
      });
  }

  /** ⋯ on a feed row (coarse): sheet with the subscription's actions. */
  openFeedSheet(subscription: SubscriptionDto): void {
    this.sheet
      .open({
        title: subscription.title,
        actions: [
          { id: 'edit', label: this.transloco.translate('reader.editFeed') },
          ...FEED_FLAG_TOGGLES.map((toggle) => ({
            id: toggle.surface,
            label: this.transloco.translate(feedFlagLabelKey(toggle, subscription)),
          })),
          {
            id: 'unsubscribe',
            label: this.transloco.translate('reader.unsubscribe'),
            danger: true,
          },
        ],
      })
      .pipe(takeUntilDestroyed(this.destroyRef))
      .subscribe((choice) => {
        if (choice === 'edit') this.manage.editSubscription(subscription);
        const toggle = FEED_FLAG_TOGGLES.find(({ surface }) => surface === choice);
        if (toggle) flipFeedFlag(this.manage, toggle, subscription);
        if (choice === 'unsubscribe') this.manage.unsubscribe(subscription);
      });
  }

  /** ⋯ on a network head (coarse): one item per account of the network. */
  openSocialSheet(section: SocialSection): void {
    this.sheet
      .open({
        title: this.transloco.translate(`settings.social.${section.network}`),
        actions: [...this.socialAccountActions(section), this.networkSavedSearchesAction(section)],
      })
      .pipe(takeUntilDestroyed(this.destroyRef))
      .subscribe((choice) => {
        if (choice === SAVED_SEARCHES_CHOICE) {
          this.toggleNetworkInSavedSearches(section);
          return;
        }
        const account = section.networkAccounts.find(({ id }) => String(id) === choice);
        if (account) this.manageSocialFeeds(account);
      });
  }

  /** The network head's saved-searches item, for its sheet and its menu alike. */
  networkSavedSearchesAction(section: SocialSection): ActionSheetAction {
    const included = this.savedSearches.includedNetworks().has(section.network);
    return {
      id: SAVED_SEARCHES_CHOICE,
      label: this.transloco.translate(
        included
          ? 'reader.excludeNetworkFromSavedSearches'
          : 'reader.includeNetworkInSavedSearches',
      ),
    };
  }

  toggleNetworkInSavedSearches(section: SocialSection): void {
    this.savedSearches.toggleNetwork(section.network);
    this.closeMenu();
  }

  toggleFeedFlag(toggle: FeedFlagToggle, subscription: SubscriptionDto): void {
    flipFeedFlag(this.manage, toggle, subscription);
    this.closeMenu();
  }

  socialAccountActions(section: SocialSection): ActionSheetAction[] {
    return section.networkAccounts.map((account) => ({
      id: String(account.id),
      label: this.socialAccountLabel(section, account),
      disabled: account.status !== 'connected',
    }));
  }

  manageSocialFeeds(account: SocialAccountRefDto): void {
    this.closeMenu();
    this.picker.open(account).subscribe();
  }

  private socialAccountLabel(section: SocialSection, account: SocialAccountRefDto): string {
    if (account.status !== 'connected') {
      return this.transloco.translate('reader.reconnectSocialAccount', { handle: account.handle });
    }
    return section.networkAccounts.length === 1
      ? this.transloco.translate('reader.manageSocialFeeds')
      : this.transloco.translate('reader.manageSocialFeedsOf', { handle: account.handle });
  }
}
