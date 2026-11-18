import { SINCE_VISIT_BLOCK } from './hidden-overlay';
import { ListSegment, SegmentMarks, segmentList, segmentsWithout } from './list-segments';
import { EntryDto } from '../../models';
import { leadOf } from '../../state/list-item';
import { aStory, aStoryArticle } from '../../../../testing/entry-testing';

const NEWEST = '2026-08-09T10:00:00+00:00';
const OLDER = '2026-08-07T09:05:00+00:00';

const entry = (id: number, over: Partial<EntryDto> = {}): EntryDto => ({ id, ...over }) as EntryDto;
const ids = (segments: ListSegment[]) =>
  segments.map((segment) => segment.items.map((item) => leadOf(item).id));
const dividers = (segments: ListSegment[]) => segments.map((segment) => segment.divider);

const marks = (over: Partial<SegmentMarks> = {}): SegmentMarks => ({
  showsRunHeader: () => false,
  newSinceVisitIds: new Set(),
  newestFirst: true,
  ...over,
});

describe('segmentList', () => {
  it('gives each shown run its header', () => {
    const entries = [
      entry(1, { runId: 9, runGeneratedAt: NEWEST }),
      entry(2, { runId: 9, runGeneratedAt: NEWEST }),
      entry(3, { runId: 7, runGeneratedAt: OLDER }),
    ];

    const segments = segmentList(entries, marks({ showsRunHeader: (group) => group.runId !== 9 }));

    expect(ids(segments)).toEqual([[1, 2], [3]]);
    expect(dividers(segments)).toEqual([null, { kind: 'run-header', generatedAt: OLDER }]);
  });

  it('splits a followed story at its first row that is not new', () => {
    const segments = segmentList(
      [entry(1), entry(2), entry(3)],
      marks({ newSinceVisitIds: new Set([1, 2]) }),
    );

    expect(ids(segments)).toEqual([[1, 2], [3]]);
    expect(dividers(segments)).toEqual([null, SINCE_VISIT_BLOCK]);
  });

  it('draws no visit divider oldest first or when the list does not open with a new row', () => {
    const entries = [entry(1), entry(2), entry(3)];

    expect(
      segmentList(entries, marks({ newSinceVisitIds: new Set([1]), newestFirst: false })),
    ).toHaveLength(1);
    expect(segmentList(entries, marks({ newSinceVisitIds: new Set([2, 3]) }))).toHaveLength(1);
    expect(segmentList(entries, marks({ newSinceVisitIds: new Set([1, 2, 3]) }))).toHaveLength(1);
  });

  it("segments story items by their lead's run (#2328)", () => {
    const storyLedBy = (id: number, runId: number, generatedAt: string) =>
      aStory({ id, articles: [aStoryArticle({ id, runId, runGeneratedAt: generatedAt })] });

    const segments = segmentList(
      [storyLedBy(1, 9, NEWEST), storyLedBy(2, 9, NEWEST), storyLedBy(3, 7, OLDER)],
      marks({ showsRunHeader: (group) => group.runId !== 9 }),
    );

    expect(ids(segments)).toEqual([[1, 2], [3]]);
    expect(dividers(segments)).toEqual([null, { kind: 'run-header', generatedAt: OLDER }]);
  });

  it('splits at the first story whose lead is not new (#2328)', () => {
    const storyLedBy = (id: number) => aStory({ id: 100 + id, articles: [aStoryArticle({ id })] });

    const segments = segmentList(
      [storyLedBy(1), storyLedBy(2), storyLedBy(3)],
      marks({ newSinceVisitIds: new Set([1, 2]) }),
    );

    expect(ids(segments)).toEqual([[1, 2], [3]]);
  });

  it('keeps one divider-less segment for a plain list', () => {
    const entries = [entry(1), entry(2)];

    expect(segmentList(entries, marks())).toEqual([
      { key: 'head:0', divider: null, items: entries },
    ]);
  });
});

const split = segmentList(
  [entry(1), entry(2), entry(3), entry(4)],
  marks({ newSinceVisitIds: new Set([1, 2]) }),
);

describe('segmentsWithout', () => {
  it('returns the input itself when nothing is hidden', () => {
    expect(segmentsWithout(split, new Set())).toBe(split);
  });

  it('keeps an untouched segment by identity and drops an emptied one', () => {
    const out = segmentsWithout(split, new Set([3, 4]));

    expect(out).toHaveLength(1);
    expect(out[0]).toBe(split[0]);
  });

  it('keeps the visit divider while some new rows are still shown', () => {
    const out = segmentsWithout(split, new Set([1]));

    expect(ids(out)).toEqual([[2], [3, 4]]);
    expect(dividers(out)).toEqual([null, SINCE_VISIT_BLOCK]);
  });

  it('drops the visit divider once hiding leaves nothing new above it', () => {
    const out = segmentsWithout(split, new Set([1, 2, 3]));

    expect(ids(out)).toEqual([[4]]);
    expect(dividers(out)).toEqual([null]);
  });
});

describe('segment keys', () => {
  it('keys a segment by its divider, else by its place', () => {
    const [head, sinceVisit] = segmentList(
      [entry(1), entry(2)],
      marks({ newSinceVisitIds: new Set([1]) }),
    );

    expect(head.key).toBe('head:0');
    expect(sinceVisit.key).toBe('since-last-visit');
  });

  it('keeps the key of a segment whose divider hiding takes', () => {
    const [sinceVisit] = segmentsWithout(split, new Set([1, 2]));

    expect(sinceVisit.divider).toBeNull();
    expect(sinceVisit.key).toBe('since-last-visit');
  });
});
