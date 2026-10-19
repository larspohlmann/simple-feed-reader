import { Injectable, computed, signal } from '@angular/core';
import { TagMove, gapIndexAt, isNoOpGap } from './tag-drop-gap';

interface TagDrag {
  readonly from: number;
  readonly middles: readonly number[];
}

@Injectable()
export class TagDragGap {
  private readonly drag = signal<TagDrag | null>(null);
  private readonly offsetY = signal<number | null>(null);

  readonly gap = computed(() => {
    const drag = this.drag();
    const offsetY = this.offsetY();
    if (drag === null || offsetY === null) return null;
    const gap = gapIndexAt(offsetY, drag.middles);
    return isNoOpGap(drag.from, gap) ? null : gap;
  });

  /** `middles` are the tag panels' midpoints, measured once, relative to the tree's top. */
  start(from: number, middles: readonly number[]): void {
    this.drag.set({ from, middles });
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
