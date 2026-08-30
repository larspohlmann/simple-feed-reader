// src/testing/refresh-report.ts
import { RefreshReport } from '../app/reader/models';

/**
 * A `RefreshReport` for specs that flush `POST /api/refresh`.
 *
 * Five spec files used to hand-build this shape, and #721 is what made the cost
 * visible: reshaping the wire contract meant five separate edits, in three of
 * which a newly required field had to be remembered by hand. One builder means
 * the next field lands in one place.
 *
 * The defaults describe a finished run that swept nothing. Override whatever the
 * test is actually about — and override `progress` together with `remaining`,
 * since a run with feeds still due does not have a settled denominator.
 */
export function refreshReport(over: Partial<RefreshReport> = {}): RefreshReport {
  return {
    status: 'completed',
    progress: { done: 0, total: 0 },
    fetched: 0,
    notModified: 0,
    failed: 0,
    throttled: 0,
    skippedForBudget: 0,
    remaining: 0,
    pruned: 0,
    ...over,
  };
}
