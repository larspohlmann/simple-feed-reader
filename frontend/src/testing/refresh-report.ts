import { RefreshReport } from '../app/reader/models';

/**
 * A `RefreshReport` for specs that flush `POST /api/refresh`.
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
