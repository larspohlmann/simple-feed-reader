import { TestBed } from '@angular/core/testing';
import { CommentTotals } from './comment-totals';

describe('CommentTotals', () => {
  let totals: CommentTotals;
  const counted = (
    id: number,
    commentCount: number | null,
    canonicalItemId: number | null = null,
  ) => ({
    id,
    canonicalItemId,
    commentCount,
  });

  beforeEach(() => {
    totals = TestBed.inject(CommentTotals);
  });

  it('is the stored count until a thread is in', () => {
    expect(totals.latestCount(counted(1, 12))).toBe(12);
  });

  it('is the recorded total once a thread is in', () => {
    totals.record(counted(1, 12), 4);

    expect(totals.latestCount(counted(1, 12))).toBe(4);
  });

  it('stays null where the source counts no comments', () => {
    totals.record(counted(1, null), 4);

    expect(totals.latestCount(counted(1, null))).toBeNull();
  });

  it('is shared by the copies of one item', () => {
    totals.record(counted(1, 12, 7), 4);

    expect(totals.latestCount(counted(2, 12, 7))).toBe(4);
  });

  it('forgets the oldest total past fifty threads', () => {
    for (let id = 1; id <= 51; id++) totals.record(counted(id, 0), 100);

    expect(totals.latestCount(counted(1, 0))).toBe(0);
    expect(totals.latestCount(counted(2, 0))).toBe(100);
  });
});
