import { moveItemInArray } from '@angular/cdk/drag-drop';
import { DestroyRef, Injectable, inject, signal } from '@angular/core';
import { takeUntilDestroyed } from '@angular/core/rxjs-interop';
import { TranslocoService } from '@jsverse/transloco';
import {
  ACTION_SHEET_DIVIDER,
  ActionSheetAction,
  ActionSheetCheck,
  ActionSheetItem,
} from '../../../shared/action-sheet/action-sheet.component';
import { ActionSheet } from '../../../shared/action-sheet/action-sheet.service';
import { ManageActions } from '../../feeds/manage/manage-actions.service';
import { TagActions } from '../../feeds/manage/tag-actions.service';
import { SocialFeedsPicker } from '../../feeds/social-picker/social-feeds-picker.service';
import { SubscriptionDto, TagDto } from '../../models';
import {
  ExclusionSurface,
  flipFeedFlag,
  isIncludedIn,
  surfaceChecks,
} from '../../feed-exclusions';
import { SocialAccountRefDto } from '../../social-feed';
import { NetworkSurfaceSwitches } from '../../state/network-surface-switches.service';
import { SocialSection } from '../../state/social-sections';
import { siblingIdsOf } from '../../state/subscription-derivations';
import { SubscriptionsStore } from '../../state/subscriptions.store';
import { TagsStore } from '../../state/tags.store';

/** The row menus and action sheets of the sidebar's tag and feed rows. Provided
 *  on the sidebar, so all rows share one open menu and a late sheet choice dies
 *  with the sidebar. */
@Injectable()
export class SidebarRowActions {
  private readonly manage = inject(ManageActions);
  private readonly tagActions = inject(TagActions);
  private readonly sheet = inject(ActionSheet);
  private readonly transloco = inject(TranslocoService);
  private readonly picker = inject(SocialFeedsPicker);
  private readonly tags = inject(TagsStore);
  private readonly subscriptions = inject(SubscriptionsStore);
  private readonly destroyRef = inject(DestroyRef);

  private readonly networkSwitches = inject(NetworkSurfaceSwitches);

  readonly menuFor = signal<string | null>(null);

  canCreateTagUnder(tag: TagDto): boolean {
    return this.tags.hierarchy().mayPlaceUnder(null, tag.id);
  }

  toggleMenu(key: string, event: Event): void {
    event.preventDefault();
    event.stopPropagation();
    this.menuFor.update((openKey) => (openKey === key ? null : key));
  }

  closeMenu(): void {
    this.menuFor.set(null);
  }

  /** ⋯ on a tag row (coarse): the tag's actions; the moves stay within its sibling set, like a drag. */
  openTagSheet(tag: TagDto): void {
    const siblingIds = siblingIdsOf(this.tags.tags(), tag);
    const index = siblingIds.indexOf(tag.id);
    this.sheet
      .open({
        title: tag.name,
        actions: [
          { id: 'edit', label: this.transloco.translate('reader.editTag'), symbol: 'edit' },
          ...this.createHereActions(tag),
          {
            id: 'moveUp',
            label: this.transloco.translate('reader.moveTagUp'),
            symbol: 'arrow_upward',
            disabled: index <= 0,
          },
          {
            id: 'moveDown',
            label: this.transloco.translate('reader.moveTagDown'),
            symbol: 'arrow_downward',
            disabled: index < 0 || index === siblingIds.length - 1,
          },
          ACTION_SHEET_DIVIDER,
          ...this.tagSurfaceChecks(tag),
          ACTION_SHEET_DIVIDER,
          {
            id: 'delete',
            label: this.transloco.translate('reader.deleteTag'),
            symbol: 'delete',
            danger: true,
          },
        ],
      })
      // A sheet can outlive the sidebar (e.g. the shell unmounts); a late
      // choice must not emit into destroyed outputs.
      .pipe(takeUntilDestroyed(this.destroyRef))
      .subscribe((choice) => {
        if (choice === 'edit') this.tagActions.editTag(tag);
        if (choice === 'createHere') this.tagActions.createTag(tag.id);
        if (choice === 'moveUp') this.moveTag(tag, -1);
        if (choice === 'moveDown') this.moveTag(tag, 1);
        if (choice === 'delete') this.tagActions.deleteTag(tag);
      });
  }

  /** ⋯ on the Tags head (Organise): create a top-level tag. */
  openTagsHeadSheet(): void {
    this.sheet
      .open({
        title: this.transloco.translate('reader.tags'),
        actions: [{ id: 'new', label: this.transloco.translate('reader.newTag'), symbol: 'add' }],
      })
      .pipe(takeUntilDestroyed(this.destroyRef))
      .subscribe((choice) => {
        if (choice === 'new') this.tagActions.createTag();
      });
  }

  /** The tag's surface switches, for its sheet and its menu alike; each reads the live tag. */
  tagSurfaceChecks(tag: TagDto): ActionSheetCheck[] {
    return surfaceChecks(this.transloco, {
      isIncluded: (surface) => isIncludedIn(this.liveTag(tag), surface),
      flip: (surface) =>
        this.tagActions.setTagInclusion(tag, surface, !isIncludedIn(this.liveTag(tag), surface)),
    });
  }

  private liveTag(tag: TagDto): TagDto {
    return this.tags.byId(tag.id) ?? tag;
  }

  private createHereActions(tag: TagDto): ActionSheetAction[] {
    if (!this.canCreateTagUnder(tag)) return [];
    return [
      { id: 'createHere', label: this.transloco.translate('reader.createTagHere'), symbol: 'add' },
    ];
  }

  /** Reads the siblings afresh: the order the sheet opened on may have changed while it was up. */
  private moveTag(tag: TagDto, step: -1 | 1): void {
    const siblingIds = siblingIdsOf(this.tags.tags(), tag);
    const from = siblingIds.indexOf(tag.id);
    const to = from + step;
    if (from < 0 || to < 0 || to >= siblingIds.length) return;
    moveItemInArray(siblingIds, from, to);
    this.tagActions.reorderTags(siblingIds);
  }

  /** ⋯ on a feed row (coarse): sheet with the subscription's actions. */
  openFeedSheet(subscription: SubscriptionDto): void {
    this.sheet
      .open({
        title: subscription.title,
        actions: [
          { id: 'edit', label: this.transloco.translate('reader.editFeed') },
          ACTION_SHEET_DIVIDER,
          ...this.feedFlagChecks(subscription),
          ACTION_SHEET_DIVIDER,
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
        if (choice === 'unsubscribe') this.manage.unsubscribe(subscription);
      });
  }

  private feedFlagChecks(subscription: SubscriptionDto): ActionSheetCheck[] {
    return surfaceChecks(this.transloco, {
      isIncluded: (surface) => isIncludedIn(this.liveSubscription(subscription), surface),
      flip: (surface) => this.toggleFeedFlag(surface, subscription),
    });
  }

  /** The row the menu opened on may predate a tick that has since patched the store. */
  private liveSubscription(subscription: SubscriptionDto): SubscriptionDto {
    return (
      this.subscriptions.subscriptions().find(({ id }) => id === subscription.id) ?? subscription
    );
  }

  /** ⋯ on a network head (coarse): one item per account of the network, then its switches. */
  openSocialSheet(section: SocialSection): void {
    this.sheet
      .open({
        title: this.transloco.translate(`settings.social.${section.network}`),
        actions: this.socialSheetItems(section),
      })
      .pipe(takeUntilDestroyed(this.destroyRef))
      .subscribe((choice) => {
        const account = section.networkAccounts.find(({ id }) => String(id) === choice);
        if (account) this.manageSocialFeeds(account);
      });
  }

  private socialSheetItems(section: SocialSection): ActionSheetItem[] {
    const accounts = this.socialAccountActions(section);
    const divider = accounts.length > 0 ? [ACTION_SHEET_DIVIDER] : [];
    return [...accounts, ...divider, ...this.networkSurfaceChecks(section)];
  }

  /** The network head's switches, for its sheet and its menu alike. */
  networkSurfaceChecks(section: SocialSection): ActionSheetCheck[] {
    const network = section.network;
    return surfaceChecks(this.transloco, {
      isIncluded: (surface) => !this.networkSwitches.isExcluded(network, surface),
      flip: (surface) => this.networkSwitches.toggle(network, surface),
    });
  }

  networkExcludedSurfaces(network: SocialSection['network']): ExclusionSurface[] {
    return this.networkSwitches.excludedSurfaces(network);
  }

  toggleFeedFlag(surface: ExclusionSurface, subscription: SubscriptionDto): void {
    flipFeedFlag(this.manage, surface, this.liveSubscription(subscription));
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
