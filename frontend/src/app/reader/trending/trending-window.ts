import { TrendingCounts, TrendingWindow } from '../models';

export const DEFAULT_TRENDING_WINDOW: TrendingWindow = 'day';

export const NO_TRENDING_COUNTS: TrendingCounts = { day: 0, week: 0 };

export function isTrendingWindow(value: string | null): value is TrendingWindow {
  return value === 'day' || value === 'week';
}

export function sameTrendingCounts(left: TrendingCounts, right: TrendingCounts): boolean {
  return left.day === right.day && left.week === right.week;
}
