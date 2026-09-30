// The reading pane splitter's list-column width, as a percent of the split container.
// The band is the collapse guard for the draggable split: it keeps neither pane from
// shrinking to nothing, so the drag can never strand the list or the article.
export const DEFAULT_LIST_PERCENT = 42;
export const MIN_LIST_PERCENT = 25;
export const MAX_LIST_PERCENT = 70;

export function clampListPercent(percent: number): number {
  if (!Number.isFinite(percent)) {
    return DEFAULT_LIST_PERCENT;
  }
  return Math.min(MAX_LIST_PERCENT, Math.max(MIN_LIST_PERCENT, percent));
}
