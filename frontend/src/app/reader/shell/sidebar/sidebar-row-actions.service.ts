import { DestroyRef, Injectable, inject, signal } from '@angular/core';
import { takeUntilDestroyed } from '@angular/core/rxjs-interop';
import { TranslocoService } from '@jsverse/transloco';
import { ActionSheet } from '../../../shared/action-sheet/action-sheet.service';
import { ManageActions } from '../../feeds/manage/manage-actions.service';
import { SubscriptionDto, TagDto } from '../../models';

/** The row menus and action sheets of the sidebar's tag and feed rows. Provided
 *  on the sidebar, so all rows share one open menu and a late sheet choice dies
 *  with the sidebar. */
@Injectable()
export class SidebarRowActions {
  private readonly manage = inject(ManageActions);
  private readonly sheet = inject(ActionSheet);
  private readonly transloco = inject(TranslocoService);
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
          {
            id: 'toggleAllItems',
            label: this.toggleLabel(
              subscription.includeInAllItems,
              'reader.excludeFromAllItems',
              'reader.includeInAllItems',
            ),
          },
          {
            id: 'toggleForYou',
            label: this.toggleLabel(
              subscription.includeInForYou,
              'reader.excludeFromForYou',
              'reader.includeInForYou',
            ),
          },
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
        if (choice === 'toggleAllItems')
          this.manage.setIncludeInAllItems(subscription, !subscription.includeInAllItems);
        if (choice === 'toggleForYou')
          this.manage.setIncludeInForYou(subscription, !subscription.includeInForYou);
        if (choice === 'unsubscribe') this.manage.unsubscribe(subscription);
      });
  }

  /** Label for a feed exclusion toggle, state-dependent: when the feed is
   *  currently included it offers to exclude, and vice versa. */
  private toggleLabel(included: boolean, excludeKey: string, includeKey: string): string {
    return this.transloco.translate(included ? excludeKey : includeKey);
  }

  /** Tooltip for the row's exclusion marker: names exactly which surface(s)
   *  the feed is hidden from. */
  exclusionTitle(subscription: SubscriptionDto): string {
    if (!subscription.includeInAllItems && !subscription.includeInForYou) {
      return this.transloco.translate('reader.excludedFromBoth');
    }
    if (!subscription.includeInAllItems) {
      return this.transloco.translate('reader.excludedFromAllItems');
    }
    return this.transloco.translate('reader.excludedFromForYou');
  }
}
