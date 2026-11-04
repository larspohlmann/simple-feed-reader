export type TrendingWindow = 'day' | 'week';

export type TrendingCounts = Readonly<Record<TrendingWindow, number>>;

/** What a Trending row stands for beyond its lead (#2017). */
export interface TrendingStory {
  /** Distinct subscribed feeds in the story, social ones included. */
  outletCount: number;
  /** The server's verdict: some entry of the story, a social one included, was opened. */
  viewed: boolean;
}
