import { Injectable, computed, inject, signal } from '@angular/core';
import { TagDto } from './models';
import { TagsStore } from './state/tags.store';
import { TagDragSubject, TagDrop, TagDropLayout, TagHeaderBox, resolveTagDrop } from './tag-drop';

export interface TagHeaderElement {
  readonly tagId: number;
  readonly element: Element;
}

/** Measured once per drag: a re-measure mid-drag would read the cue's own layout shift. */
export function measureTagLayout(
  headers: readonly TagHeaderElement[],
  listEnd: Element | undefined,
  originTop: number,
): TagDropLayout {
  const boxes: TagHeaderBox[] = headers
    .map(({ tagId, element }) => {
      const rect = element.getBoundingClientRect();
      return { tagId, top: rect.top - originTop, bottom: rect.bottom - originTop };
    })
    .sort((left, right) => left.top - right.top);
  const listBottom =
    listEnd === undefined
      ? (boxes[boxes.length - 1]?.bottom ?? 0)
      : listEnd.getBoundingClientRect().bottom - originTop;
  return { headers: boxes, listBottom };
}

@Injectable()
export class TagDragSession {
  private readonly tags = inject(TagsStore);
  private readonly drag = signal<TagDragSubject | null>(null);
  private readonly offsetY = signal<number | null>(null);

  readonly drop = computed<TagDrop | null>(() => {
    const drag = this.drag();
    const offsetY = this.offsetY();
    if (drag === null || offsetY === null) return null;
    return resolveTagDrop(drag, this.tags.tags(), offsetY);
  });

  start(tag: TagDto, layout: TagDropLayout): void {
    this.drag.set({ tag, layout });
    this.offsetY.set(null);
  }

  move(offsetY: number): void {
    this.offsetY.set(offsetY);
  }

  end(): { readonly tag: TagDto; readonly drop: TagDrop } | null {
    const tag = this.drag()?.tag;
    const drop = this.drop();
    this.drag.set(null);
    this.offsetY.set(null);
    return tag === undefined || drop === null ? null : { tag, drop };
  }
}
