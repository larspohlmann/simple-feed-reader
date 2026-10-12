import { EntryDto } from '../models';

/** A post: an entry whose title the server derived from its text. */
export function isPost(entry: Pick<EntryDto, 'titleDerived'>): boolean {
  return entry.titleDerived;
}

/** What a card shows in its headline slot: a post's own text, since its derived title only
 *  repeats it — the title when the text came out empty. */
export function entryHeadline(entry: EntryDto): string {
  return isPost(entry) ? entry.excerpt || entry.title : entry.title;
}

/** The dek a card renders: none for a post, whose text is already its headline. */
export function entryDek(entry: EntryDto): string {
  return isPost(entry) ? '' : entry.excerpt;
}

/** What names an entry where some text is required: its title, or who posted it when it has none. */
export function entryDisplayTitle(entry: Pick<EntryDto, 'title' | 'source' | 'poster'>): string {
  return entry.title || (entry.poster?.name ?? entry.source);
}
