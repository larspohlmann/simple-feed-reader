import { EntryDto } from '../models';

export type TrendingWindow = 'day' | 'week';

export type TrendingCounts = Readonly<Record<TrendingWindow, number>>;

/** One story of `GET /api/trending` (#2017): the lead and the other article outlets are list rows. */
export interface TrendingStoryDto {
  lead: EntryDto;
  others: EntryDto[];
  /** Distinct subscribed feeds in the story, social ones included. */
  outletCount: number;
  viewed: boolean;
}

export interface TrendingResponseDto {
  window: TrendingWindow;
  builtAt: string | null;
  stories: TrendingStoryDto[];
}

export interface TrendingCoverage {
  outletCount: number;
  others: EntryDto[];
  /** The server's verdict: some entry of the story, a social one included, was opened. */
  viewed: boolean;
}
