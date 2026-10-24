import { Injectable, computed, inject, signal } from '@angular/core';
import { TagDto } from './models';
import { TagsStore } from './state/tags.store';
import {
  TagDragSubject,
  TagDrop,
  TagDropLayout,
  TagDropMark,
  TagHeaderBox,
  resolveTagDrop,
} from './tag-drop';

export interface TagHeaderElement {
  readonly tagId: number;
  readonly element: Element;
}

/** What a drag measures: every tag header, the foot of the last top-level block, and the element offsets count from. */
export interface TagDragLayoutSource {
  readonly headers: readonly TagHeaderElement[];
  readonly listEnd: Element | undefined;
  readonly origin: Element;
}

/** Measured once per drag: a re-measure mid-drag would read the cue's own layout shift. */
export function measureTagLayout({ headers, listEnd, origin }: TagDragLayoutSource): TagDropLayout {
  const originTop = origin.getBoundingClientRect().top;
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
  private origin: Element | null = null;

  readonly drop = computed<TagDrop | null>(() => {
    const drag = this.drag();
    const offsetY = this.offsetY();
    if (drag === null || offsetY === null) return null;
    return resolveTagDrop(drag, this.tags.tags(), offsetY);
  });

  cueFor(tagId: number): TagDropMark['kind'] | null {
    const mark = this.drop()?.mark;
    return mark?.tagId === tagId ? mark.kind : null;
  }

  start(tag: TagDto, source: TagDragLayoutSource): void {
    this.origin = source.origin;
    this.drag.set({ tag, layout: measureTagLayout(source) });
    this.offsetY.set(null);
  }

  /** Offsets count from the origin's current top, so a scroll mid-drag keeps the measured layout valid. */
  move(clientY: number): void {
    if (this.origin === null) return;
    this.offsetY.set(clientY - this.origin.getBoundingClientRect().top);
  }

  end(): { readonly tag: TagDto; readonly drop: TagDrop } | null {
    const tag = this.drag()?.tag;
    const drop = this.drop();
    this.drag.set(null);
    this.offsetY.set(null);
    return tag === undefined || drop === null ? null : { tag, drop };
  }
}
