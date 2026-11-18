import { EntriesPage } from '../models';
import { FollowedStoryPageDto } from '../trending/trending.models';
import { ListItem } from './list-item';

export interface ListPage extends Partial<
  Pick<FollowedStoryPageDto, 'lastOpenedAt' | 'newEntryIds'>
> {
  items: ListItem[];
  nextCursor: string | null;
  matchedWords?: EntriesPage['matchedWords'];
}

export function listPageOf(page: EntriesPage | FollowedStoryPageDto): ListPage {
  const { entries, ...rest } = page;
  return { ...rest, items: entries };
}
