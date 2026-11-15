import { EntryDto } from '../models';
import { LinkCardDto } from '../link-card/link-card';
import { entryDek, entryDisplayTitle, entryHeadline, isPost } from '../entry/entry-text';

/** The card a Trending row stands for when it is a post sharing an article no followed outlet carries (#2229). */
export function sharedArticleCard(entry: EntryDto): LinkCardDto | null {
  return isPost(entry) && (entry.shares?.length ?? 0) > 0 ? (entry.linkCards[0] ?? null) : null;
}

/** What a mini card shows of a row: its shared card when it stands for one, else the row's own text and picture. */
export function articleFaceOf(entry: EntryDto): {
  title: string;
  dek: string;
  picture: string | null;
} {
  const card = sharedArticleCard(entry);
  if (card) return { title: card.title, dek: card.description ?? '', picture: card.imageUrl };
  return {
    title: entryHeadline(entry) || entryDisplayTitle(entry),
    dek: entryDek(entry),
    picture: entry.imageUrl,
  };
}
