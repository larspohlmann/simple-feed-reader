import { groupByRun, RunGroup } from '../for-you-runs';
import { ListItem, leadOf } from '../../state/list-item';
import {
  BEFORE_VISIT_BLOCK,
  isVisitBlock,
  listBlockKey,
  marksVisit,
  RunHeaderBlock,
  SINCE_VISIT_BLOCK,
  VisitBlock,
} from './hidden-overlay';

/** A stretch of the list both layouts render under one divider: a run header, a visit marker, or none. */
export interface ListSegment {
  /** Fixed when segmented, so a segment whose divider hiding takes keeps its rows mounted. */
  readonly key: string;
  readonly divider: RunHeaderBlock | VisitBlock | null;
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

/** `segments` minus the hidden ids: an emptied segment goes, and the visit's markers once either side
 *  of them is empty. The input itself comes back when nothing is hidden. */
export function segmentsWithout(
  segments: ListSegment[],
  hidden: ReadonlySet<number>,
): ListSegment[] {
  if (hidden.size === 0) return segments;
  const kept = segments
    .map((segment) => segmentWithout(segment, hidden))
    .filter((segment) => segment.items.length > 0);
  if (marksVisit(kept.map((segment) => segment.divider))) return kept;
  return kept.map((segment) =>
    isVisitBlock(segment.divider) ? { ...segment, divider: null } : segment,
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
    { divider: segment.divider ?? SINCE_VISIT_BLOCK, items: items.slice(0, end) },
    { divider: BEFORE_VISIT_BLOCK, items: items.slice(end) },
  ];
}

function segmentWithout(segment: ListSegment, hidden: ReadonlySet<number>): ListSegment {
  const items = segment.items.filter((item) => !hidden.has(leadOf(item).id));
  return items.length === segment.items.length ? segment : { ...segment, items };
}
