import { groupByRun, RunGroup } from '../for-you-runs';
import { EntryDto } from '../../models';
import { listBlockKey, RunHeaderBlock, SINCE_VISIT_BLOCK, SinceVisitBlock } from './hidden-overlay';

/** A stretch of the list both layouts render under one divider: a run header, the visit's, or none. */
export interface ListSegment {
  readonly divider: RunHeaderBlock | SinceVisitBlock | null;
  readonly entries: EntryDto[];
}

export interface SegmentMarks {
  readonly showsRunHeader: (group: RunGroup) => boolean;
  readonly newSinceVisitIds: ReadonlySet<number>;
  readonly newestFirst: boolean;
}

/** One segment per recommendation run (#348), the first split where the rows that joined since the
 *  last visit end (#2128). A plain list is one divider-less segment. */
export function segmentList(entries: EntryDto[], marks: SegmentMarks): ListSegment[] {
  const [first, ...rest] = groupByRun(entries).map((group) => runSegment(group, marks));
  if (first === undefined) return [];
  return [...splitAtVisitEnd(first, marks), ...rest];
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
    .filter((segment) => segment.entries.length > 0)
    .map((segment, index) =>
      index === 0 && segment.divider?.kind === 'since-last-visit'
        ? { ...segment, divider: null }
        : segment,
    );
}

export function segmentKey(segment: ListSegment, index: number): string {
  return segment.divider === null ? `head:${index}` : listBlockKey(segment.divider);
}

function runSegment(group: RunGroup, marks: SegmentMarks): ListSegment {
  const divider: RunHeaderBlock | null = marks.showsRunHeader(group)
    ? { kind: 'run-header', generatedAt: group.generatedAt! }
    : null;
  return { divider, entries: group.entries };
}

/** Oldest first, the new rows trail the list, so it marks nothing. */
function splitAtVisitEnd(segment: ListSegment, marks: SegmentMarks): ListSegment[] {
  const newIds = marks.newSinceVisitIds;
  const { entries } = segment;
  if (!marks.newestFirst || !newIds.has(entries[0].id)) return [segment];
  const end = entries.findIndex((entry) => !newIds.has(entry.id));
  if (end === -1) return [segment];
  return [
    { ...segment, entries: entries.slice(0, end) },
    { divider: SINCE_VISIT_BLOCK, entries: entries.slice(end) },
  ];
}

function segmentWithout(segment: ListSegment, hidden: ReadonlySet<number>): ListSegment {
  const entries = segment.entries.filter((entry) => !hidden.has(entry.id));
  return entries.length === segment.entries.length ? segment : { ...segment, entries };
}
