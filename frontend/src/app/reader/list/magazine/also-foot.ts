import { EntryDto } from '../../models';
import { otherArticlesOf } from '../../state/list-item';
import { TrendingStoryDto } from '../../trending/trending.models';

/** What a card's footer lists, and the translation key it is headed with. */
export interface AlsoFoot {
  readonly copies: readonly EntryDto[];
  readonly label: string;
}

const NO_COPIES: readonly EntryDto[] = [];

export function alsoFootOf(entry: EntryDto, story: TrendingStoryDto | null): AlsoFoot {
  if (story) return { copies: otherArticlesOf(story), label: 'reader.alsoCoveredBy' };
  return { copies: entry.duplicates ?? NO_COPIES, label: 'reader.alsoIn' };
}

export function isCrossPost(lead: EntryDto, copy: EntryDto): boolean {
  return (
    lead.canonicalItemId !== null &&
    copy.canonicalItemId !== null &&
    copy.canonicalItemId !== lead.canonicalItemId &&
    copy.community !== null
  );
}
