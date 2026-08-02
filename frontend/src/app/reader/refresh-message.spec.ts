import { refreshFailureKey } from './refresh-message';

describe('refreshFailureKey', () => {
  // Three causes the user must be able to tell apart: someone else holds the
  // lock, the sweep stopped early with feeds still due, or the request failed
  // (#119). One message for all three tells the user nothing actionable.
  it('names the contended lock', () => {
    expect(refreshFailureKey({ kind: 'busy' })).toBe('reader.refreshBusy');
  });

  it('names a sweep that stopped early', () => {
    expect(refreshFailureKey({ kind: 'aborted' })).toBe('reader.refreshAborted');
  });

  it('falls back to the general fetch failure for an HTTP problem', () => {
    const problem = { type: 'rate_limited', title: 'Too many requests', status: 429 };
    expect(refreshFailureKey({ kind: 'http', problem })).toBe('reader.fetchFailed');
  });
});
