import { refreshFailureKey } from './refresh-message';

describe('refreshFailureKey', () => {
  // Four causes the user must be able to tell apart: a contended lock, an
  // early-stopped sweep, a stalled sweep, or a request failure (#119, #302).
  // One message for all of them tells the user nothing actionable.
  it('names the contended lock', () => {
    expect(refreshFailureKey({ kind: 'busy' })).toBe('reader.refreshBusy');
  });

  it('names a sweep that stopped early', () => {
    expect(refreshFailureKey({ kind: 'aborted' })).toBe('reader.refreshAborted');
  });

  // A stall is not the backend aborting: nothing failed, the sweep simply
  // stopped shrinking the due set, and retrying at once would repeat it.
  it('names a sweep that stopped making progress', () => {
    expect(refreshFailureKey({ kind: 'stalled' })).toBe('reader.refreshStalled');
  });

  it('falls back to the general fetch failure for an HTTP problem', () => {
    const problem = { type: 'rate_limited', title: 'Too many requests', status: 429 };
    expect(refreshFailureKey({ kind: 'http', problem })).toBe('reader.fetchFailed');
  });
});
