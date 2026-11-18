import { EntryDto } from '../models';
import { ListItem, isStory } from './list-item';

export type EntryPatch = (entry: EntryDto) => EntryDto;

/** `item` itself when nothing in it is targeted; a story's `shares` are never patched. */
export function withPatchedItem(
  item: ListItem,
  targets: ReadonlySet<number>,
  patch: EntryPatch,
): ListItem {
  if (!isStory(item)) return withPatchedRow(item, targets, patch);
  if (!item.articles.some((article) => targets.has(article.id))) return item;
  return {
    ...item,
    articles: item.articles.map((article) =>
      targets.has(article.id) ? { ...patch(article), shares: article.shares } : article,
    ),
  };
}

/** `row` itself when neither it nor a copy in its `duplicates` is targeted. */
export function withPatchedRow(
  row: EntryDto,
  targets: ReadonlySet<number>,
  patch: EntryPatch,
): EntryDto {
  const patched = targets.has(row.id) ? patch(row) : row;
  if (!patched.duplicates?.some((copy) => targets.has(copy.id))) return patched;
  return {
    ...patched,
    duplicates: patched.duplicates.map((copy) => (targets.has(copy.id) ? patch(copy) : copy)),
  };
}
