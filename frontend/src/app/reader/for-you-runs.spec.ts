import { EntryDto } from './models';
import { groupByRun } from './for-you-runs';

const entry = (id: number, runId?: number, runGeneratedAt?: string): EntryDto =>
  ({ id, runId, runGeneratedAt }) as EntryDto;

describe('groupByRun', () => {
  it('splits contiguous entries into one group per run, in order', () => {
    const groups = groupByRun([entry(1, 9, 'B'), entry(2, 9, 'B'), entry(3, 7, 'A')]);

    expect(groups.map((group) => group.runId)).toEqual([9, 7]);
    expect(groups[0].entries.map((x) => x.id)).toEqual([1, 2]);
    expect(groups[0].generatedAt).toBe('B');
    expect(groups[1].entries.map((x) => x.id)).toEqual([3]);
  });

  it('returns a single run-less group when entries carry no runId', () => {
    const groups = groupByRun([entry(1), entry(2)]);

    expect(groups).toHaveLength(1);
    expect(groups[0].runId).toBeUndefined();
    expect(groups[0].generatedAt).toBeUndefined();
    expect(groups[0].entries).toHaveLength(2);
  });

  it('is empty for no entries', () => {
    expect(groupByRun([])).toEqual([]);
  });
});
