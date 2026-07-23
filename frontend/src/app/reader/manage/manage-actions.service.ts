// src/app/reader/manage/manage-actions.service.ts
import { Injectable, inject } from '@angular/core';
import { Dialog } from '@angular/cdk/dialog';
import { ReaderApi } from '../reader-api';
import { SubscriptionsStore } from '../subscriptions.store';
import { TagsStore } from '../tags.store';
import { SubscriptionDto, TagDto } from '../models';
import { ConfirmDialogComponent, ConfirmData } from './confirm-dialog.component';
import { EditSubscriptionDialogComponent } from './edit-subscription-dialog.component';
import { TagFormDialogComponent } from './tag-form-dialog.component';

/** The single place a management dialog is opened and its side effects applied.
 *  Both the settings sections and the sidebar (via the shell) call these, so an
 *  action behaves identically wherever it is triggered. Dialogs perform their own
 *  API write and close with the result; this service refreshes the affected
 *  stores on a truthy close. */
@Injectable({ providedIn: 'root' })
export class ManageActions {
  private readonly dialog = inject(Dialog);
  private readonly api = inject(ReaderApi);
  private readonly subs = inject(SubscriptionsStore);
  private readonly tags = inject(TagsStore);

  editSubscription(sub: SubscriptionDto): void {
    const ref = this.dialog.open<SubscriptionDto>(EditSubscriptionDialogComponent, { data: sub });
    ref.closed.subscribe((updated) => {
      if (updated) this.subs.load();
    });
  }

  /** Replace a subscription's whole tag set (used by sidebar drag-and-drop).
   *  The PATCH endpoint replaces tags, so callers pass the final id list. */
  retag(sub: SubscriptionDto, tagIds: number[]): void {
    this.api
      .updateSubscription(sub.id, { customTitle: sub.customTitle, tagIds })
      .subscribe({ next: () => this.subs.load() });
  }

  /** Persist a new sidebar tag order (drag-and-drop); tag order lives in TagsStore. */
  reorderTags(tagIds: number[]): void {
    this.api.reorderTags(tagIds).subscribe({ next: () => this.tags.load() });
  }

  /** Persist a new order for the untagged "Feeds" list. */
  reorderUntagged(subscriptionIds: number[]): void {
    this.api.reorderSubscriptions(subscriptionIds).subscribe({ next: () => this.subs.load() });
  }

  /** Persist a new order for the feeds within one tag. */
  reorderTagFeeds(tagId: number, subscriptionIds: number[]): void {
    this.api.setTagFeedOrder(tagId, subscriptionIds).subscribe({ next: () => this.subs.load() });
  }

  unsubscribe(sub: SubscriptionDto): void {
    const data: ConfirmData = {
      title: 'Unsubscribe',
      message: `Remove “${sub.title}” and its entries from your feeds?`,
      confirmLabel: 'Unsubscribe',
      danger: true,
    };
    const ref = this.dialog.open<boolean>(ConfirmDialogComponent, { data });
    ref.closed.subscribe((ok) => {
      if (!ok) return;
      this.api.deleteSubscription(sub.id).subscribe({ next: () => this.subs.load() });
    });
  }

  createTag(): void {
    const ref = this.dialog.open<TagDto>(TagFormDialogComponent, { data: null });
    ref.closed.subscribe((tag) => {
      if (tag) this.tags.load();
    });
  }

  editTag(tag: TagDto): void {
    const ref = this.dialog.open<TagDto>(TagFormDialogComponent, { data: tag });
    ref.closed.subscribe((updated) => {
      if (!updated) return;
      this.tags.load();
      this.subs.load(); // embedded tag colour/name on feeds changed too
    });
  }

  deleteTag(tag: TagDto): void {
    const data: ConfirmData = {
      title: 'Delete tag',
      message: `Delete “${tag.name}”? It will be removed from every feed that uses it.`,
      confirmLabel: 'Delete',
      danger: true,
    };
    const ref = this.dialog.open<boolean>(ConfirmDialogComponent, { data });
    ref.closed.subscribe((ok) => {
      if (!ok) return;
      this.api.deleteTag(tag.id).subscribe({
        next: () => {
          this.tags.load();
          this.subs.load();
        },
      });
    });
  }
}
