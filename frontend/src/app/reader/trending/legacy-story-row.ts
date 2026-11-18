import { EntryDto } from '../models';
import { TrendingStoryDto } from './trending.models';

const rows = new WeakMap<TrendingStoryDto, EntryDto>();

/** Until the story widgets take the story (#2328 Task 11): a story's lead as the old wire shaped its
 *  row. One row per story object, so a template binding it sees the same value on every check. */
export function legacyRow(lead: EntryDto, story: TrendingStoryDto | null): EntryDto {
  if (!story) return lead;
  let row = rows.get(story);
  if (!row) {
    row = { ...lead, duplicates: story.articles.slice(1), story };
    rows.set(story, row);
  }
  return row;
}
