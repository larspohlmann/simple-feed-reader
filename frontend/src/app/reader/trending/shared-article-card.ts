import { EntryDto } from '../models';
import { LinkCardDto } from '../link-card/link-card';
import { isPost } from '../entry/entry-text';

/** The card a Trending row stands for when it is a post sharing an article no followed outlet carries (#2229). */
export function sharedArticleCard(entry: EntryDto): LinkCardDto | null {
  return isPost(entry) && (entry.shares?.length ?? 0) > 0 ? (entry.linkCards[0] ?? null) : null;
}
