import { EntriesPage, EntryDto, TrendingResponseDto, TrendingStoryDto } from '../models';

/** The Trending list as the reader's list store holds any list: one row per story, its lead (#2017). */
export function trendingPage(response: TrendingResponseDto): EntriesPage {
  return { entries: response.stories.map(leadOf), nextCursor: null };
}

function leadOf(story: TrendingStoryDto): EntryDto {
  return {
    ...story.lead,
    coverage: { outletCount: story.outletCount, others: story.others, viewed: story.viewed },
  };
}
