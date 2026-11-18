import { EntryQuery } from '../models';
import { ListPage } from './list-page';

/** A first page that landed, or with no page, a load that failed. */
export interface LandedList {
  readonly query: EntryQuery;
  readonly page: ListPage | null;
}
