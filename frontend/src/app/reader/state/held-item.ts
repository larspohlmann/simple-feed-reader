import { Signal, computed } from '@angular/core';
import { EntryDto } from '../models';
import { StoryArticleDto, TrendingStoryDto } from '../trending/trending.models';
import { ListItem, isStory } from './list-item';

/** An item one list shows, built from the cached entries it names (#2329). */
export interface HeldItem<T extends ListItem = ListItem> {
  readonly item: Signal<T>;
  /** Every entry the item shows: its row, the copies in its `duplicates`, or a story's articles and theirs. */
  readonly ids: readonly number[];
}

/** Writes an entry into the cache and hands back its record. */
export type EntryRecord = (entry: EntryDto) => Signal<EntryDto>;

export function heldItem(item: ListItem, record: EntryRecord): HeldItem {
  return isStory(item) ? heldStory(item, record) : heldRow(item, record);
}

/** A plain row is its record itself. What a list sends with a row that belongs to the list, not to the entry, stays
 *  with the list: another list's copy of the row lacks it or differs in it, so a shared record must not carry it. */
function heldRow(row: EntryDto, record: EntryRecord): HeldItem<EntryDto> {
  const {
    duplicates,
    joinedAt,
    recommendationReason,
    recommendationScore,
    runId,
    runGeneratedAt,
    ...own
  } = row;
  const listFields = definedOnly({
    joinedAt,
    recommendationReason,
    recommendationScore,
    runId,
    runGeneratedAt,
  });
  const entry = record(own);
  const copies = duplicates?.map((copy) => heldRow(copy, record));
  const ids = [row.id, ...(copies ?? []).flatMap((copy) => copy.ids)];
  if (copies === undefined && Object.keys(listFields).length === 0) return { item: entry, ids };
  return {
    item: computed(() => {
      const shown: EntryDto = { ...entry(), ...listFields };
      return copies === undefined
        ? shown
        : { ...shown, duplicates: copies.map((copy) => copy.item()) };
    }),
    ids,
  };
}

/** A story's own fields and its articles' shares stay with the list; its articles are entries like any row's. */
function heldStory(story: TrendingStoryDto, record: EntryRecord): HeldItem<TrendingStoryDto> {
  const { articles, ...fields } = story;
  const held = articles.map(({ shares, ...article }) => {
    const row = heldRow(article, record);
    const item = computed((): StoryArticleDto => ({ ...row.item(), shares }));
    return { ids: row.ids, item };
  });
  return {
    item: computed(() => ({ ...fields, articles: held.map((article) => article.item()) })),
    ids: held.flatMap((article) => article.ids),
  };
}

function definedOnly<T extends object>(fields: T): Partial<T> {
  return Object.fromEntries(
    Object.entries(fields).filter(([, value]) => value !== undefined),
  ) as Partial<T>;
}
