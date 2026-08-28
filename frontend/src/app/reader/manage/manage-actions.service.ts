// src/app/reader/manage/manage-actions.service.ts
import { Injectable, inject } from '@angular/core';
import { Observable } from 'rxjs';
import { Dialog } from '@angular/cdk/dialog';
import { TranslocoService } from '@jsverse/transloco';
import { ReaderApi } from '../reader-api';
import { SubscriptionsStore } from '../subscriptions.store';
import { TagsStore } from '../tags.store';
import { SubscriptionDto, TagDto } from '../models';
import {
  ConfirmDialogComponent,
  ConfirmData,
} from '../../shared/confirm-dialog/confirm-dialog.component';
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
  private readonly i18n = inject(TranslocoService);

  editSubscription(sub: SubscriptionDto): void {
    const ref = this.dialog.open<SubscriptionDto>(EditSubscriptionDialogComponent, {
      data: sub,
      panelClass: 'app-dialog',
    });
    ref.closed.subscribe((updated) => {
      if (updated) this.subs.load();
    });
  }

  /** Fire a drag-and-drop write, then re-sync the affected store whether it
   *  succeeds OR fails: a rejected write (e.g. a concurrent change) is
   *  corrected from the server. */
  private reloadAfter(write$: Observable<unknown>, reload: () => void): void {
    write$.subscribe({ next: reload, error: reload });
  }

  /** Apply an optimistic update to the subscription's tags so the sidebar
   *  reflects the new tag set immediately, then reconcile with the server. */
  retag(sub: SubscriptionDto, tagIds: number[]): void {
    this.subs.subscriptions.update((current) =>
      current.map((s) => {
        if (s.id !== sub.id) return s;
        return {
          ...s,
          tags: tagIds.map((id, i) => {
            const existing = s.tags.find((t) => t.id === id);
            if (existing) return { ...existing, position: i };
            const tag = this.tags.tags().find((t) => t.id === id);
            return {
              id,
              name: tag?.name ?? '',
              color: tag?.color ?? null,
              icon: tag?.icon ?? null,
              position: i,
            };
          }),
        };
      }),
    );
    this.reloadAfter(
      this.api.updateSubscription(sub.id, { customTitle: sub.customTitle, tagIds }),
      () => this.subs.load(),
    );
  }

  /** Flip whether this feed contributes to the "All items" list. Optimistic:
   *  update the sidebar immediately, reconcile with the server. */
  setIncludeInAllItems(sub: SubscriptionDto, value: boolean): void {
    this.patchFlags(sub, { includeInAllItems: value, includeInForYou: sub.includeInForYou });
  }

  /** Flip whether this feed contributes to "For you". Optimistic: update the
   *  sidebar immediately, reconcile with the server. */
  setIncludeInForYou(sub: SubscriptionDto, value: boolean): void {
    this.patchFlags(sub, { includeInAllItems: sub.includeInAllItems, includeInForYou: value });
  }

  /** The backend PATCH clears customTitle/tagIds when they are omitted, so
   *  every flag toggle sends the full mutable body — not just the flag that
   *  changed — mirroring retag(). */
  private patchFlags(
    sub: SubscriptionDto,
    flags: { includeInAllItems: boolean; includeInForYou: boolean },
  ): void {
    this.subs.patchLocal(sub.id, flags);
    this.reloadAfter(
      this.api.updateSubscription(sub.id, {
        customTitle: sub.customTitle,
        tagIds: sub.tags.map((t) => t.id),
        ...flags,
      }),
      () => this.subs.load(),
    );
  }

  /** Persist a new sidebar tag order (drag-and-drop); tag order lives in
   *  TagsStore. Optimistic: reorder immediately, reconcile on response. */
  reorderTags(tagIds: number[]): void {
    this.tags.tags.update((current) => {
      const byId = new Map(current.map((t) => [t.id, t]));
      return tagIds.map((id, i) => {
        const tag = byId.get(id);
        return tag
          ? { ...tag, position: i }
          : { id, name: '', color: null, icon: null, position: i };
      });
    });
    this.reloadAfter(this.api.reorderTags(tagIds), () => this.tags.load());
  }

  /** Persist a new order for the untagged "Feeds" list. Optimistic: update
   *  positions immediately, reconcile on response. */
  reorderUntagged(subscriptionIds: number[]): void {
    this.subs.subscriptions.update((current) =>
      current.map((s) => {
        const idx = subscriptionIds.indexOf(s.id);
        if (idx >= 0) return { ...s, position: idx };
        return s;
      }),
    );
    this.reloadAfter(this.api.reorderSubscriptions(subscriptionIds), () => this.subs.load());
  }

  /** Persist a new order for the feeds within one tag. Optimistic: update
   *  per-tag positions immediately, reconcile on response. */
  reorderTagFeeds(tagId: number, subscriptionIds: number[]): void {
    this.subs.subscriptions.update((current) =>
      current.map((s) => {
        const tagEntry = s.tags.find((t) => t.id === tagId);
        if (!tagEntry) return s;
        const idx = subscriptionIds.indexOf(s.id);
        if (idx < 0) return s;
        return {
          ...s,
          tags: s.tags.map((t) => (t.id === tagId ? { ...t, position: idx } : t)),
        };
      }),
    );
    this.reloadAfter(this.api.setTagFeedOrder(tagId, subscriptionIds), () => this.subs.load());
  }

  unsubscribe(sub: SubscriptionDto): void {
    const data: ConfirmData = {
      title: this.i18n.translate('manage.unsubscribeTitle'),
      message: this.i18n.translate('manage.unsubscribeMessage', { title: sub.title }),
      confirmLabel: this.i18n.translate('manage.unsubscribeConfirm'),
      danger: true,
    };
    const ref = this.dialog.open<boolean>(ConfirmDialogComponent, {
      data,
      // A destructive confirmation is an alert, not a plain dialog; the role
      // belongs on the CDK's modal container, which is the outermost element
      // assistive tech sees.
      role: 'alertdialog',
      panelClass: 'app-dialog',
    });
    ref.closed.subscribe((ok) => {
      if (!ok) return;
      this.api.deleteSubscription(sub.id).subscribe({ next: () => this.subs.load() });
    });
  }

  createTag(): void {
    const ref = this.dialog.open<TagDto>(TagFormDialogComponent, {
      data: null,
      panelClass: 'app-dialog',
    });
    ref.closed.subscribe((tag) => {
      if (tag) this.tags.load();
    });
  }

  editTag(tag: TagDto): void {
    const ref = this.dialog.open<TagDto>(TagFormDialogComponent, {
      data: tag,
      panelClass: 'app-dialog',
    });
    ref.closed.subscribe((updated) => {
      if (!updated) return;
      this.tags.load();
      this.subs.load(); // embedded tag colour/name on feeds changed too
    });
  }

  deleteTag(tag: TagDto): void {
    const data: ConfirmData = {
      title: this.i18n.translate('manage.deleteTagTitle'),
      message: this.i18n.translate('manage.deleteTagMessage', { name: tag.name }),
      confirmLabel: this.i18n.translate('manage.deleteConfirm'),
      danger: true,
    };
    const ref = this.dialog.open<boolean>(ConfirmDialogComponent, {
      data,
      // A destructive confirmation is an alert, not a plain dialog; the role
      // belongs on the CDK's modal container, which is the outermost element
      // assistive tech sees.
      role: 'alertdialog',
      panelClass: 'app-dialog',
    });
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
