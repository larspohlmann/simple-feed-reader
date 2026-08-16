import {
  RUN_HISTORY_STATUSES,
  runHistoryStatusIcon,
  RunHistoryStatus,
} from './run-history-status-icon';

const EXPECTED: Record<RunHistoryStatus, string> = {
  completed: 'check_circle',
  failed: 'error',
  cancelled: 'cancel',
  running: 'autorenew',
  pending: 'schedule',
};

describe('runHistoryStatusIcon', () => {
  it.each(RUN_HISTORY_STATUSES)('maps %s to its expected icon name', (status) => {
    expect(runHistoryStatusIcon(status)).toBe(EXPECTED[status]);
  });

  it('lists every status exactly once', () => {
    expect(new Set(RUN_HISTORY_STATUSES).size).toBe(RUN_HISTORY_STATUSES.length);
    expect(RUN_HISTORY_STATUSES).toEqual(
      expect.arrayContaining(['completed', 'failed', 'cancelled', 'running', 'pending']),
    );
  });
});
