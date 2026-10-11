import { EntryDto } from '../models';

/** What names an entry where some text is required: its title, or its source when it has none. */
export function entryDisplayTitle(entry: Pick<EntryDto, 'title' | 'source'>): string {
  return entry.title || entry.source;
}
