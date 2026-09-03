import { RunHistoryRow } from '../reader/models';

/** The wire vocabulary for a run's status, narrowed off `RunHistoryRow` rather
 *  than redeclared -- a status the API adds shows up here as a type error
 *  against `STATUS_ICON` below instead of silently falling through it. */
export type RunHistoryStatus = RunHistoryRow['status'];

/** Every status, in the order the mobile legend lists them (#409). The one
 *  place that enumerates all five, so the legend cannot fall out of sync with
 *  the type above by forgetting one. */
export const RUN_HISTORY_STATUSES: readonly RunHistoryStatus[] = [
  'completed',
  'failed',
  'cancelled',
  'running',
  'pending',
];

/** Material Symbol name per status -- the icon a phone reader sees in place
 *  of the spelled-out word, and the same glyph the legend explains it with.
 *  Read by both `RecommendationRunHistoryMonthComponent`'s row and
 *  `RecommendationRunHistoryComponent`'s legend so the two mappings cannot
 *  drift apart into disagreement. */
const STATUS_ICON: Record<RunHistoryStatus, string> = {
  completed: 'check_circle',
  failed: 'error',
  cancelled: 'cancel',
  running: 'autorenew',
  pending: 'schedule',
};

export function runHistoryStatusIcon(status: RunHistoryStatus): string {
  return STATUS_ICON[status];
}
