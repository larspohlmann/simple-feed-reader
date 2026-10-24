import { Injectable, inject } from '@angular/core';
import { switchMap } from 'rxjs';
import { ReaderApi } from '../../reader-api';
import { SubscriptionsStore } from '../../state/subscriptions.store';
import { TagsStore, compareTagPositions } from '../../state/tags.store';
import { TagDto, TagInput } from '../../models';
import { TagDrop } from '../../tag-drop';
import { reloadAfter } from './reload-after';

/** Writes where a tag sits among its siblings and under which parent. Optimistic: the store
 *  shows the new place at once, the reload reconciles with what the server kept. */
@Injectable({ providedIn: 'root' })
export class TagPlacement {
  private readonly api = inject(ReaderApi);
  private readonly subs = inject(SubscriptionsStore);
  private readonly tags = inject(TagsStore);

  reorder(tagIds: readonly number[]): void {
    this.tags.tags.update((current) => placed(current, positioned(current, tagIds)));
    reloadAfter(this.api.reorderTags(tagIds), () => this.tags.load());
  }

  /** A new parent is a PATCH (the server puts the tag last), then the order the drop chose. */
  move(tag: TagDto, drop: TagDrop): void {
    if (drop.parentId === tag.parentId) {
      this.reorder(drop.order);
      return;
    }
    this.tags.tags.update((current) =>
      placed(
        current,
        positioned(current, drop.order).map((sibling) => ({ ...sibling, parentId: drop.parentId })),
      ),
    );
    const body: TagInput = {
      name: tag.name,
      color: tag.color,
      icon: tag.icon,
      parentId: drop.parentId,
    };
    reloadAfter(
      this.api.updateTag(tag.id, body).pipe(switchMap(() => this.api.reorderTags(drop.order))),
      () => {
        this.tags.load();
        this.subs.reloadQuietly();
      },
    );
  }
}

function positioned(current: readonly TagDto[], tagIds: readonly number[]): TagDto[] {
  const byId = new Map(current.map((tag) => [tag.id, tag]));
  return tagIds.map((id, index) => ({
    ...(byId.get(id) ?? { id, name: '', color: null, icon: null, parentId: null }),
    position: index,
  }));
}

function placed(current: readonly TagDto[], repositioned: readonly TagDto[]): TagDto[] {
  const listed = new Set(repositioned.map((tag) => tag.id));
  return [...current.filter((tag) => !listed.has(tag.id)), ...repositioned].sort(
    compareTagPositions,
  );
}
