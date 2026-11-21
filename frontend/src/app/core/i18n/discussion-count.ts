import { CompactCount } from './compact-count';
import { pluralKey } from './plural-key';

export interface DiscussionCount {
  text: string;
  count: number;
  labelKey: string;
}

export function buildDiscussionCount(
  compactCount: CompactCount,
  count: number | null,
  kind: 'points' | 'comments' | 'likes',
): DiscussionCount | null {
  if (count === null) return null;
  return {
    text: compactCount.format(count),
    count,
    labelKey: pluralKey(`reader.discussionCounts.${kind}`, count),
  };
}
