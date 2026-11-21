import { CompactCount } from './compact-count';
import { pluralKey } from './plural-key';

export interface DiscussionCount {
  text: string;
  count: number;
  labelKey: string;
}

export type DiscussionCountLabel =
  | 'reader.discussionCounts.points'
  | 'reader.discussionCounts.comments'
  | 'reader.socialCounts.likes';

export function buildDiscussionCount(
  compactCount: CompactCount,
  count: number | null,
  label: DiscussionCountLabel,
): DiscussionCount | null {
  if (count === null) return null;
  return {
    text: compactCount.format(count),
    count,
    labelKey: pluralKey(label, count),
  };
}
