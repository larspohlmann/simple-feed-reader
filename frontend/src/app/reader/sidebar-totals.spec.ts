import { NO_FOR_YOU_STREAM_TALLY, sameForYouStreamTally } from './sidebar-totals';

describe('sameForYouStreamTally', () => {
  const tally = { available: true, unreadCount: 3, entryCount: 8 };

  it('is true for an equal tally', () => {
    expect(sameForYouStreamTally(tally, { ...tally })).toBe(true);
    expect(sameForYouStreamTally(NO_FOR_YOU_STREAM_TALLY, { ...NO_FOR_YOU_STREAM_TALLY })).toBe(
      true,
    );
  });

  it('is false when any field differs', () => {
    expect(sameForYouStreamTally(tally, { ...tally, available: false })).toBe(false);
    expect(sameForYouStreamTally(tally, { ...tally, unreadCount: 4 })).toBe(false);
    expect(sameForYouStreamTally(tally, { ...tally, entryCount: 9 })).toBe(false);
  });
});
