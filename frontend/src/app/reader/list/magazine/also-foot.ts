import { EntryDto } from '../../models';
import { TrendingStoryDto } from '../../trending/trending.models';

/** What a card's footer lists, and the translation key it is headed with. */
export interface AlsoFoot {
  readonly copies: readonly EntryDto[];
  readonly label: string;
}

const NO_COPIES: readonly EntryDto[] = [];

/** A row's other copies, or the other articles of the story it leads. */
export function alsoFootOf(entry: EntryDto, story: TrendingStoryDto | null): AlsoFoot {
  if (story) return { copies: story.articles.slice(1), label: 'reader.alsoCoveredBy' };
  return { copies: entry.duplicates ?? NO_COPIES, label: 'reader.alsoIn' };
}
