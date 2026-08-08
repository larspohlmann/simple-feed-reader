// src/app/reader/for-you-message.spec.ts
import { forYouFailureKey } from './for-you-message';

describe('forYouFailureKey', () => {
  it('names a run the backend gave up on', () => {
    expect(forYouFailureKey({ kind: 'failed', error: 'boom' })).toBe('reader.forYouFailed');
  });

  it('falls back to the general unreachable message for an HTTP problem', () => {
    const problem = { type: 'rate_limited', title: 'Too many requests', status: 429 };
    expect(forYouFailureKey({ kind: 'http', problem })).toBe('reader.forYouUnreachable');
  });
});
