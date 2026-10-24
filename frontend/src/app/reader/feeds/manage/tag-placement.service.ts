import { Injectable, inject } from '@angular/core';
import { Observable, switchMap } from 'rxjs';
import { ReaderApi } from '../../reader-api';
import { SubscriptionsStore } from '../../state/subscriptions.store';
import { TagsStore, compareTagPositions } from '../../state/tags.store';
import { TagDto, TagInput } from '../../models';
import { TagDrop } from '../../tag-drop';

/** Writes where a tag sits among its siblings and under which parent. Optimistic: the store
 *  shows the new place at once, the reload reconciles with what the server kept. */
@Injectable({ providedIn: 'root' })
export class TagPlacement {
  private readonly api = inject(ReaderApi);
  private readonly subs = inject(SubscriptionsStore);
  private readonly tags = inject(TagsStore);

  reorder(tagIds: number[]): void {
    this.place(tagIds);
    this.persist(this.api.reorderTags(tagIds), () => this.tags.load());
  }

  /** A new parent is a PATCH (the server puts the tag last), then the order the drop chose. */
  move(tag: TagDto, drop: TagDrop): void {
    const order = [...drop.order];
    if (drop.parentId === tag.parentId) {
      this.reorder(order);
      return;
    }
    this.place(order, drop.parentId);
    const body: TagInput = {
      name: tag.name,
      color: tag.color,
      icon: tag.icon,
      parentId: drop.parentId,
    };
    this.persist(
      this.api.updateTag(tag.id, body).pipe(switchMap(() => this.api.reorderTags(order))),
      () => {
        this.tags.load();
        this.subs.load();
      },
    );
  }

  private persist(write$: Observable<unknown>, reload: () => void): void {
    write$.subscribe({ next: reload, error: reload });
  }

  private place(tagIds: number[], parentId?: number | null): void {
    this.tags.tags.update((current) => {
      const listed = new Set(tagIds);
      const byId = new Map(current.map((tag) => [tag.id, tag]));
      const repositioned = tagIds.map((id, index): TagDto => {
        const placed = {
          ...(byId.get(id) ?? { id, name: '', color: null, icon: null, parentId: null }),
          position: index,
        };
        return parentId === undefined ? placed : { ...placed, parentId };
      });
      return [...current.filter((tag) => !listed.has(tag.id)), ...repositioned].sort(
        compareTagPositions,
      );
    });
  }
}
