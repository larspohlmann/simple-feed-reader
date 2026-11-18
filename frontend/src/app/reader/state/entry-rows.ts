import { EntryDto } from '../models';
import { ListItem, isStory } from './list-item';

export type EntryPatch = (entry: EntryDto) => EntryDto;

/** `item` itself when the patch changes nothing in it; a story's `shares` are never patched. */
export function withPatchedItem(
  item: EntryDto,
  targets: ReadonlySet<number>,
  patch: EntryPatch,
): EntryDto;
export function withPatchedItem(
  item: ListItem,
  targets: ReadonlySet<number>,
  patch: EntryPatch,
): ListItem;
export function withPatchedItem(
  item: ListItem,
  targets: ReadonlySet<number>,
  patch: EntryPatch,
): ListItem {
  if (!isStory(item)) return withPatchedRow(item, targets, patch);
  const articles = item.articles.map((article) => {
    if (!targets.has(article.id)) return article;
    const next = patch(article);
    return next === article ? article : { ...next, shares: article.shares };
  });
  return articles.every((article, index) => article === item.articles[index])
    ? item
    : { ...item, articles };
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
