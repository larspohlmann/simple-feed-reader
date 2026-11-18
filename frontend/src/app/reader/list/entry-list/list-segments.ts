import { groupByRun, RunGroup } from '../for-you-runs';
import { ListItem, leadOf } from '../../state/list-item';
import { listBlockKey, RunHeaderBlock, SINCE_VISIT_BLOCK, SinceVisitBlock } from './hidden-overlay';

/** A stretch of the list both layouts render under one divider: a run header, the visit's, or none. */
export interface ListSegment {
  /** Fixed when segmented, so a segment whose divider hiding takes keeps its rows mounted. */
  readonly key: string;
  readonly divider: RunHeaderBlock | SinceVisitBlock | null;
  readonly items: ListItem[];
}

export interface SegmentMarks {
  readonly showsRunHeader: (group: RunGroup) => boolean;
  readonly newSinceVisitIds: ReadonlySet<number>;
  readonly newestFirst: boolean;
}

/** One segment per recommendation run (#348), the first split where the rows that joined since the
 *  last visit end (#2128). A plain list is one divider-less segment. */
export function segmentList(items: ListItem[], marks: SegmentMarks): ListSegment[] {
  const [first, ...rest] = groupByRun(items).map((group) => runSegment(group, marks));
  if (first === undefined) return [];
  return [...splitAtVisitEnd(first, marks), ...rest].map((segment, index) => ({
    ...segment,
    key: segment.divider === null ? `head:${index}` : listBlockKey(segment.divider),
  }));
}

/** `segments` minus the hidden ids: an emptied segment goes, and the visit's divider once nothing
 *  new is left above it. The input itself comes back when nothing is hidden. */
export function segmentsWithout(
  segments: ListSegment[],
  hidden: ReadonlySet<number>,
): ListSegment[] {
  if (hidden.size === 0) return segments;
  return segments
    .map((segment) => segmentWithout(segment, hidden))
    .filter((segment) => segment.items.length > 0)
    .map((segment, index) =>
      index === 0 && segment.divider?.kind === 'since-last-visit'
        ? { ...segment, divider: null }
        : segment,
    );
}

type UnkeyedSegment = Omit<ListSegment, 'key'>;

function runSegment(group: RunGroup, marks: SegmentMarks): UnkeyedSegment {
  const divider: RunHeaderBlock | null = marks.showsRunHeader(group)
    ? { kind: 'run-header', generatedAt: group.generatedAt! }
    : null;
  return { divider, items: group.items };
}

/** Oldest first, the new rows trail the list, so it marks nothing. */
function splitAtVisitEnd(segment: UnkeyedSegment, marks: SegmentMarks): UnkeyedSegment[] {
  const newIds = marks.newSinceVisitIds;
  const { items } = segment;
  if (!marks.newestFirst || !newIds.has(leadOf(items[0]).id)) return [segment];
  const end = items.findIndex((item) => !newIds.has(leadOf(item).id));
  if (end === -1) return [segment];
  return [
    { ...segment, items: items.slice(0, end) },
    { divider: SINCE_VISIT_BLOCK, items: items.slice(end) },
  ];
}

function segmentWithout(segment: ListSegment, hidden: ReadonlySet<number>): ListSegment {
  const items = segment.items.filter((item) => !hidden.has(leadOf(item).id));
  return items.length === segment.items.length ? segment : { ...segment, items };
}
