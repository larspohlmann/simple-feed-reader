import { EntryDto } from '../../models';
import { ListItem, isStory, leadOf } from '../../state/list-item';
import { TrendingStoryDto } from '../../trending/trending.models';

/** What a single-entry block carries: the entry it lays out, and the story that entry leads (#2328). */
export interface BlockCard {
  entry: EntryDto;
  story: TrendingStoryDto | null;
}

/** The planner lays out each item's lead; this hands each lead's block its card. */
export function cardsByLead(items: ListItem[]): (lead: EntryDto) => BlockCard {
  const stories = new Map(items.filter(isStory).map((story) => [leadOf(story), story]));
  return (entry) => ({ entry, story: stories.get(entry) ?? null });
}
