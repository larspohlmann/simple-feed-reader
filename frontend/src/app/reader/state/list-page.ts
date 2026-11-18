import { EntriesPage } from '../models';
import { FollowedStoryPageDto } from '../trending/trending.models';
import { ListItem } from './list-item';

export interface ListPage {
  items: ListItem[];
  nextCursor: string | null;
  matchedWords?: EntriesPage['matchedWords'];
}

export function listPageOf(
  page: EntriesPage,
): ListPage & Omit<FollowedStoryPageDto, keyof EntriesPage> {
  const { entries, ...rest } = page;
  return { ...rest, items: entries } as ListPage & Omit<FollowedStoryPageDto, keyof EntriesPage>;
}
