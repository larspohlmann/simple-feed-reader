import { blocksWithout, groupMembersWithout, ListBlock, runGroupsWithout } from './hidden-overlay';
import { RunGroup } from '../for-you-runs';
import { EntryDto } from '../../models';

const entry = (id: number): EntryDto => ({ id }) as EntryDto;
const compact = (id: number): ListBlock => ({ kind: 'compact', entry: entry(id) });
const header = (generatedAt: string): ListBlock => ({ kind: 'run-header', generatedAt });

describe('blocksWithout', () => {
  it('returns the input itself when nothing is hidden', () => {
    const blocks = [compact(1)];
    expect(blocksWithout(blocks, new Set())).toBe(blocks);
  });

  it('drops the hidden single-entry blocks and keeps the order of the rest', () => {
    const blocks = [compact(1), compact(2), compact(3)];
    expect(blocksWithout(blocks, new Set([2]))).toEqual([compact(1), compact(3)]);
  });

  it('shrinks a group to its surviving members and drops an emptied one', () => {
    const group: ListBlock = {
      kind: 'group',
      subscriptionId: 1,
      source: 'a',
      entries: [entry(1), entry(2)],
      previewCount: 1,
    };
    expect(blocksWithout([group], new Set([1]))).toEqual([{ ...group, entries: [entry(2)] }]);
    expect(blocksWithout([group], new Set([1, 2]))).toEqual([]);
  });

  it('drops a run divider once every block of its run is hidden', () => {
    const blocks = [header('t1'), compact(1), header('t2'), compact(2)];
    expect(blocksWithout(blocks, new Set([1]))).toEqual([header('t2'), compact(2)]);
    expect(blocksWithout(blocks, new Set([2]))).toEqual([header('t1'), compact(1)]);
  });
});

describe('groupMembersWithout (#1972)', () => {
  const group = (ids: number[]): ListBlock => ({
    kind: 'group',
    subscriptionId: 1,
    source: 'a',
    entries: ids.map(entry),
    previewCount: 1,
  });

  it('returns the input itself when nothing is leaving', () => {
    const blocks = [group([1, 2])];
    expect(groupMembersWithout(blocks, new Set())).toBe(blocks);
  });

  it('takes a leaving member out of its group and keeps a leaving single block for its animation', () => {
    expect(groupMembersWithout([compact(1), group([2, 3])], new Set([1, 2]))).toEqual([
      compact(1),
      group([3]),
    ]);
  });

  it('drops a group, and then its run divider, once every member is leaving', () => {
    const blocks = [header('t1'), group([1, 2]), header('t2'), compact(3)];
    expect(groupMembersWithout(blocks, new Set([1, 2]))).toEqual([header('t2'), compact(3)]);
  });
});

describe('runGroupsWithout', () => {
  const group = (runId: number, ids: number[]): RunGroup => ({
    runId,
    generatedAt: undefined,
    entries: ids.map(entry),
  });

  it('returns the input itself when nothing is hidden', () => {
    const groups = [group(1, [1])];
    expect(runGroupsWithout(groups, new Set())).toBe(groups);
  });

  it('keeps an untouched group by identity and drops an emptied one', () => {
    const untouched = group(1, [1]);
    const out = runGroupsWithout([untouched, group(2, [2, 3])], new Set([2, 3]));
    expect(out).toHaveLength(1);
    expect(out[0]).toBe(untouched);
  });
});
