import { moveItemInArray } from '@angular/cdk/drag-drop';

/** A tag drag's outcome: the tag's index in the full order, and the gap it was dropped in. */
export interface TagMove {
  readonly from: number;
  readonly gap: number;
}

export function gapIndexAt(offsetY: number, middles: readonly number[]): number {
  return middles.filter((middle) => middle < offsetY).length;
}

/** The gaps on either side of the dragged tag leave the order as it is. */
export function isNoOpGap(from: number, gap: number): boolean {
  return gap === from || gap === from + 1;
}

export function moveToGap(ids: readonly number[], move: TagMove): number[] {
  const order = [...ids];
  moveItemInArray(order, move.from, move.gap > move.from ? move.gap - 1 : move.gap);
  return order;
}

export function verticalMiddle(element: Element): number {
  const rect = element.getBoundingClientRect();
  return rect.top + rect.height / 2;
}
