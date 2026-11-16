import { EntriesPage, EntryQuery } from '../models';

/** A first page that landed, or with no page, a load that failed. */
export interface LandedList {
  readonly query: EntryQuery;
  readonly page: EntriesPage | null;
}
