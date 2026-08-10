import { EntryDto } from './models';
import { groupByRun } from './for-you-runs';

const e = (id: number, runId?: number, runGeneratedAt?: string): EntryDto =>
  ({ id, runId, runGeneratedAt }) as EntryDto;

describe('groupByRun', () => {
  it('splits contiguous entries into one group per run, in order', () => {
    const groups = groupByRun([e(1, 9, 'B'), e(2, 9, 'B'), e(3, 7, 'A')]);

    expect(groups.map((g) => g.runId)).toEqual([9, 7]);
    expect(groups[0].entries.map((x) => x.id)).toEqual([1, 2]);
    expect(groups[0].generatedAt).toBe('B');
    expect(groups[1].entries.map((x) => x.id)).toEqual([3]);
  });

  it('returns a single run-less group when entries carry no runId', () => {
    const groups = groupByRun([e(1), e(2)]);

    expect(groups).toHaveLength(1);
    expect(groups[0].runId).toBeUndefined();
    expect(groups[0].generatedAt).toBeUndefined();
    expect(groups[0].entries).toHaveLength(2);
  });

  it('is empty for no entries', () => {
    expect(groupByRun([])).toEqual([]);
  });
});
