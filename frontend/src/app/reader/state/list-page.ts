import { EntriesPage } from '../models';
import { StoryPageDto } from '../trending/trending.models';
import { ListItem } from './list-item';

export interface ListPage extends Partial<
  Pick<
    StoryPageDto,
    'title' | 'synopsis' | 'memberCount' | 'unreadCount' | 'lastOpenedAt' | 'newEntryIds'
  >
> {
  items: ListItem[];
  nextCursor: string | null;
  matchedWords?: EntriesPage['matchedWords'];
}

export function listPageOf(page: EntriesPage | StoryPageDto): ListPage {
  const { entries, ...rest } = page;
  return { ...rest, items: entries };
}
