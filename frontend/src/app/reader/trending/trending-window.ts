import { TrendingUnopened, TrendingWindow } from './trending.models';

export const DEFAULT_TRENDING_WINDOW: TrendingWindow = 'day';

export const NO_TRENDING_UNOPENED: TrendingUnopened = {
  window: DEFAULT_TRENDING_WINDOW,
  unopened: 0,
};

export function isTrendingWindow(value: string | null): value is TrendingWindow {
  return value === 'day' || value === 'week';
}

export function sameTrendingUnopened(left: TrendingUnopened, right: TrendingUnopened): boolean {
  return left.window === right.window && left.unopened === right.unopened;
}
