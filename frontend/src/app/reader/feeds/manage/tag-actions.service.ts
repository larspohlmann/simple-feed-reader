import { Injectable, inject } from '@angular/core';
import { Dialog } from '@angular/cdk/dialog';
import { TranslocoService } from '@jsverse/transloco';
import { ReaderApi } from '../../reader-api';
import { SubscriptionsStore } from '../../state/subscriptions.store';
import { TagsStore } from '../../state/tags.store';
import { TagDto } from '../../models';
import { TagDrop } from '../../tag-drop';
import { TagPlacement } from './tag-placement.service';
import { ConfirmData } from '../../../shared/confirm-dialog/confirm-dialog.component';
import { ConfirmService } from '../../../shared/confirm-dialog/confirm.service';
import { TagFormData, TagFormDialogComponent } from './tag-form-dialog.component';

/** Creates, edits, deletes and places tags for the sidebar and the Organise page alike. The tag
 *  dialog performs its own API write; a truthy close reloads the affected stores. */
@Injectable({ providedIn: 'root' })
export class TagActions {
  private readonly dialog = inject(Dialog);
  private readonly confirm = inject(ConfirmService);
  private readonly api = inject(ReaderApi);
  private readonly subs = inject(SubscriptionsStore);
  private readonly tags = inject(TagsStore);
  private readonly tagPlacement = inject(TagPlacement);
  private readonly i18n = inject(TranslocoService);

  createTag(parentId: number | null = null): void {
    const data: TagFormData = { kind: 'create', parentId };
    const ref = this.dialog.open<TagDto>(TagFormDialogComponent, {
      data,
      panelClass: 'app-dialog',
    });
    ref.closed.subscribe((tag) => {
      if (tag) this.tags.load();
    });
  }

  editTag(tag: TagDto): void {
    const data: TagFormData = { kind: 'edit', tag };
    const ref = this.dialog.open<TagDto>(TagFormDialogComponent, {
      data,
      panelClass: 'app-dialog',
    });
    ref.closed.subscribe((updated) => {
      if (!updated) return;
      this.tags.load();
      this.subs.reloadQuietly(); // embedded tag colour/name on feeds changed too
    });
  }

  deleteTag(tag: TagDto): void {
    const data: ConfirmData = {
      title: this.i18n.translate('manage.deleteTagTitle'),
      message: this.i18n.translate('manage.deleteTagMessage', { name: tag.name }),
      confirmLabel: this.i18n.translate('manage.deleteConfirm'),
      danger: true,
    };
    this.confirm.confirmThen(data, () => {
      this.api.deleteTag(tag.id).subscribe({
        next: () => {
          this.tags.load();
          this.subs.reloadQuietly();
        },
      });
    });
  }

  reorderTags(tagIds: readonly number[]): void {
    this.tagPlacement.reorder(tagIds);
  }

  moveTag(tag: TagDto, drop: TagDrop): void {
    this.tagPlacement.move(tag, drop);
  }
}
