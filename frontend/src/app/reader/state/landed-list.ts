import { EntriesPage, EntryQuery } from '../models';
import { FollowedStoryPageDto } from '../trending/trending.models';

/** A first page that landed, or with no page, a load that failed. */
export interface LandedList {
  readonly query: EntryQuery;
  readonly page: EntriesPage | FollowedStoryPageDto | null;
}
