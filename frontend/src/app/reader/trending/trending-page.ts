import { ListPage } from '../state/list-page';
import { TrendingStoryDto, TrendingWindow } from './trending.models';

/** `GET /api/trending` (#2328): one entry per story, its articles lead first. */
export interface TrendingResponseDto {
  window: TrendingWindow;
  builtAt: string | null;
  stories: TrendingStoryDto[];
}

/** The Trending list as the reader's list store holds any list: one item per story. */
export function trendingPage(response: TrendingResponseDto): ListPage {
  return { items: response.stories, nextCursor: null };
}
