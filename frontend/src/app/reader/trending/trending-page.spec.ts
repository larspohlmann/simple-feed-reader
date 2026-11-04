import { anEntry } from '../../../testing/entry-testing';
import { TrendingResponseDto, trendingPage } from './trending-page';

const response = (): TrendingResponseDto => ({
  window: 'day',
  builtAt: '2026-11-04T08:00:00Z',
  stories: [
    anEntry({
      id: 1,
      title: 'Lead one',
      duplicates: [anEntry({ id: 2 }), anEntry({ id: 3 })],
      story: { outletCount: 7, viewed: false },
    }),
    anEntry({ id: 4, title: 'Lead two', story: { outletCount: 3, viewed: true } }),
  ],
});

describe('trendingPage', () => {
  it('lists each story as its lead row, in the order the server ranked them', () => {
    expect(trendingPage(response()).entries).toEqual(response().stories);
  });

  it('has no further page', () => {
    expect(trendingPage(response()).nextCursor).toBeNull();
  });
});
