import { Injectable, computed, signal } from '@angular/core';
import { TagMove, gapIndexAt, isNoOpGap } from './tag-drop-gap';

/** The pointer range, relative to the tree's top, inside which a gap may show. */
export interface TagDragBounds {
  readonly top: number;
  readonly bottom: number;
}

interface TagDrag {
  readonly from: number;
  readonly middles: readonly number[];
  readonly bounds: TagDragBounds | null;
}

function isOutside(offsetY: number, bounds: TagDragBounds | null): boolean {
  return bounds !== null && (offsetY < bounds.top || offsetY > bounds.bottom);
}

@Injectable()
export class TagDragGap {
  private readonly drag = signal<TagDrag | null>(null);
  private readonly offsetY = signal<number | null>(null);

  readonly gap = computed(() => {
    const drag = this.drag();
    const offsetY = this.offsetY();
    if (drag === null || offsetY === null || isOutside(offsetY, drag.bounds)) return null;
    const gap = gapIndexAt(offsetY, drag.middles);
    return isNoOpGap(drag.from, gap) ? null : gap;
  });

  /** `middles` are the tag panels' midpoints, measured once, relative to the tree's top. */
  start(from: number, middles: readonly number[], bounds: TagDragBounds | null = null): void {
    this.drag.set({ from, middles, bounds });
    this.offsetY.set(null);
  }

  move(offsetY: number): void {
    this.offsetY.set(offsetY);
  }

  end(): TagMove | null {
    const drag = this.drag();
    const gap = this.gap();
    this.drag.set(null);
    this.offsetY.set(null);
    return drag === null || gap === null ? null : { from: drag.from, gap };
  }
}
