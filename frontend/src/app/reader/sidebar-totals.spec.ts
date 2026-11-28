import { NO_YOUR_STREAM_TALLY, sameYourStreamTally } from './sidebar-totals';

describe('sameYourStreamTally', () => {
  const tally = { available: true, unreadCount: 3, entryCount: 8 };

  it('is true for an equal tally', () => {
    expect(sameYourStreamTally(tally, { ...tally })).toBe(true);
    expect(sameYourStreamTally(NO_YOUR_STREAM_TALLY, { ...NO_YOUR_STREAM_TALLY })).toBe(true);
  });

  it('is false when any field differs', () => {
    expect(sameYourStreamTally(tally, { ...tally, available: false })).toBe(false);
    expect(sameYourStreamTally(tally, { ...tally, unreadCount: 4 })).toBe(false);
    expect(sameYourStreamTally(tally, { ...tally, entryCount: 9 })).toBe(false);
  });
});
